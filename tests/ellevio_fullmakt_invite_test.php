<?php

declare(strict_types=1);

/**
 * Ellevio-inbjudan från Ordrar får gå utan anläggnings-id.
 * 18 siffror krävs först när kunden signerar. Fyllt id följer med.
 */

if (!defined('JEAB_ROOT')) {
    define('JEAB_ROOT', dirname(__DIR__));
}

require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/store.php';
require dirname(__DIR__) . '/fullmakt/lib/parties.php';
require dirname(__DIR__) . '/fullmakt/lib/mailer.php';
require dirname(__DIR__) . '/src/ellevio_fullmakt.php';

function inv_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

function inv_cleanup(string $token): void
{
    foreach ([
        fullmakt_pending_path($token),
        fullmakt_signed_json_path($token),
        fullmakt_signed_pdf_path($token),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['INVITE_MAILS'][] = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-invite'];
};

function inv_row(string $aid): array
{
    return [
        'natbolag' => 'Ellevio',
        'huvudsokande' => 'Ada Adolfsson',
        'personnummer' => '19700101-0101',
        'phone' => '0701234567',
        'email' => 'ada@example.com',
        'huvudsokande_address' => 'Storgatan 1, 176 74 Järfälla',
        'anlaggnings_id' => $aid,
    ];
}

fullmakt_boot();
$okId = '735999114010135815';
$tokens = [];

try {
    $missing = jeab_ellevio_fullmakt_missing(jeab_ellevio_fullmakt_fields(inv_row('')));
    inv_assert($missing === [], 'komplett kund utan anläggnings-id saknar inget för utskick, fick: ' . implode(', ', $missing));
    inv_assert(!in_array('anläggnings-id', $missing, true), 'saknas-listan nämner inte anläggnings-id');

    $GLOBALS['INVITE_MAILS'] = [];
    $emptySent = jeab_ellevio_fullmakt_ensure(inv_row(''));
    $emptyToken = (string) ($emptySent['fullmakt_token'] ?? '');
    $tokens[] = $emptyToken;
    inv_assert($emptyToken !== '', 'tomt id skapar en fullmakt');
    inv_assert(!empty($emptySent['fullmakt_mailed']), 'tomt id skickar inbjudan');
    inv_assert(trim((string) ($emptySent['fullmakt_error'] ?? '')) === '', 'tomt id ger inget fullmaktsfel, fick: ' . ($emptySent['fullmakt_error'] ?? ''));
    inv_assert(!str_contains((string) ($emptySent['fullmakt_error'] ?? ''), 'anläggnings-id'), 'feltexten nämner inte anläggnings-id');
    $emptyDoc = fullmakt_load_doc($emptyToken);
    inv_assert(is_array($emptyDoc) && ($emptyDoc['anlaggnings_id'] ?? 'x') === '', 'dokumentet sparas med tomt id');
    inv_assert(count($GLOBALS['INVITE_MAILS']) === 1, 'ett mejl gick ut utan id');
    inv_assert(str_contains((string) ($GLOBALS['INVITE_MAILS'][0]['text'] ?? ''), 'anläggnings-id'), 'mejlet ber kunden fylla i id');

    $shortSent = jeab_ellevio_fullmakt_ensure(inv_row('735999'));
    $shortToken = (string) ($shortSent['fullmakt_token'] ?? '');
    $tokens[] = $shortToken;
    inv_assert(!empty($shortSent['fullmakt_mailed']), 'ofullständigt id stoppar inte utskicket');
    inv_assert((fullmakt_load_doc($shortToken)['anlaggnings_id'] ?? '') === '735999', 'korta siffror följer med som förifyllning');

    $filledSent = jeab_ellevio_fullmakt_ensure(inv_row('7359 9911 4010 135815'));
    $filledToken = (string) ($filledSent['fullmakt_token'] ?? '');
    $tokens[] = $filledToken;
    inv_assert(!empty($filledSent['fullmakt_mailed']), 'ifyllt id skickar inbjudan');
    inv_assert((fullmakt_load_doc($filledToken)['anlaggnings_id'] ?? '') === $okId, '18 siffror från ordern ligger på fullmakten');

    $retry = $emptySent;
    $retry['fullmakt_mailed'] = false;
    $retry['anlaggnings_id'] = '';
    $retried = jeab_ellevio_fullmakt_resend($retry);
    inv_assert(!empty($retried['fullmakt_mailed']), 'Skicka igen går utan anläggnings-id');
    inv_assert((string) ($retried['fullmakt_token'] ?? '') === $emptyToken, 'omsändning återanvänder samma länk');
    inv_assert((fullmakt_load_doc($emptyToken)['anlaggnings_id'] ?? 'x') === '', 'omsändning utan id lämnar fältet tomt');

    $later = $emptySent;
    $later['fullmakt_mailed'] = false;
    $later['anlaggnings_id'] = $okId;
    $laterSent = jeab_ellevio_fullmakt_resend($later);
    inv_assert(!empty($laterSent['fullmakt_mailed']), 'Skicka igen går när id fyllts i efteråt');
    inv_assert((fullmakt_load_doc($emptyToken)['anlaggnings_id'] ?? '') === $okId, 'id från ordern skrivs på väntande fullmakt vid omskick');

    $pageEmpty = fullmakt_backfill_anlaggnings_id([
        'type' => 'ellevio',
        'token' => $shortToken,
        'anlaggnings_id' => '',
    ]);
    inv_assert(($pageEmpty['anlaggnings_id'] ?? 'x') === '', 'signeringssidan slår inte upp ordern när fältet är tomt');
    $pageFilled = fullmakt_backfill_anlaggnings_id(fullmakt_load_doc($emptyToken) ?: []);
    inv_assert(($pageFilled['anlaggnings_id'] ?? '') === $okId, 'id som skrevs vid omskick visas förifyllt');

    $blocked = false;
    try {
        fullmakt_mark_signed($filledToken, fullmakt_load_doc($filledToken) ?: [], '%PDF-1.4', [
            'stamp_method' => 'ellevio',
            'anlaggnings_id' => '',
        ]);
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'Fyll i anläggnings-id');
    }
    inv_assert($blocked, 'signering utan id stoppas fortfarande');
    inv_assert((fullmakt_load_doc($filledToken)['status'] ?? '') === 'pending', 'avvisad signering lämnar dokumentet osignerat');

    $noZip = inv_row('');
    $noZip['huvudsokande_address'] = 'Testgatan 1, Teststad';
    $noZipSent = jeab_ellevio_fullmakt_ensure($noZip);
    inv_assert(empty($noZipSent['fullmakt_mailed']), 'utan postnummer skickas ingen inbjudan');
    inv_assert(str_contains((string) ($noZipSent['fullmakt_error'] ?? ''), 'postnummer'), 'feltexten nämner postnummer, fick: ' . ($noZipSent['fullmakt_error'] ?? ''));
    inv_assert(str_contains((string) ($noZipSent['fullmakt_error'] ?? ''), 'innan du skickar'), 'feltexten säger att postnummer krävs innan skick');
    inv_assert(!str_contains((string) ($noZipSent['fullmakt_error'] ?? ''), 'anläggnings'), 'spärren gäller inte anläggnings-id');
    inv_assert(trim((string) ($noZipSent['fullmakt_token'] ?? '')) === '', 'utan postnummer skapas ingen fullmakt');

    $fromField = inv_row('');
    $fromField['huvudsokande_address'] = 'Testgatan 2, Teststad';
    $fromField['postnummer'] = '17674';
    $fromField['ort'] = 'Teststad';
    $fromField['gata'] = 'Testgatan 2';
    $fieldSent = jeab_ellevio_fullmakt_ensure($fromField);
    $fieldToken = (string) ($fieldSent['fullmakt_token'] ?? '');
    $tokens[] = $fieldToken;
    inv_assert(!empty($fieldSent['fullmakt_mailed']), 'sparat postnummer räcker när adressraden saknar det, fel: ' . ($fieldSent['fullmakt_error'] ?? ''));
    inv_assert((fullmakt_load_doc($fieldToken)['postnummer'] ?? '') === '176 74', 'fullmakten får postnumret från orderfältet');
    inv_assert((fullmakt_load_doc($fieldToken)['anlaggnings_id'] ?? 'x') === '', 'tomt anläggnings-id följer fortfarande med som tomt');
} finally {
    foreach ($tokens as $token) {
        if ($token !== '') {
            inv_cleanup($token);
        }
    }
}

echo "ok\n";
