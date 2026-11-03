<?php

declare(strict_types=1);

/**
 * Ny fullmakt: samma startflöde, gammal länk kvar, bekräftelse och
 * spärr mot dubbelklick. ordrar.fullmakt_preview skickar inget.
 */

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-ny-' . bin2hex(random_bytes(4));
if (!mkdir($tmp . '/fullmakt', 0775, true) && !is_dir($tmp . '/fullmakt')) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['NY_MAILS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['NY_MAILS'][] = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-ny'];
};

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ellevio_fullmakt_load();

function ny_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return list<array<string,mixed>> */
function ny_of_type(string $type): array
{
    $out = [];
    foreach ((array) ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? []) as $row) {
        if (is_array($row) && ($row['type'] ?? '') === $type) {
            $out[] = $row;
        }
    }
    return $out;
}

function ny_secret(): void
{
    $path = jeab_bokmalen_ellevio_fullmakt_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode([
        'url' => 'https://bokmalen.example.invalid/hooks/fullmakt',
        'key' => 'ny-test-key',
    ], JSON_UNESCAPED_SLASHES));
}

function ny_row(int $id, array $extra = []): array
{
    return jeab_purchase_put(array_merge([
        'reminder_id' => $id,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'natbolag' => 'Ellevio',
        'huvudsokande' => 'Ada Adolfsson',
        'name' => 'Ada Adolfsson',
        'personnummer' => '19700101-0101',
        'phone' => '0701234567',
        'email' => 'ada@example.com',
        'huvudsokande_address' => 'Storgatan 1, 176 74 Järfälla',
        'anlaggnings_id' => '',
        'kwh' => '10',
        'medsokande' => [[
            'name' => 'Bo Adolfsson',
            'personnummer' => '19680512-1234',
        ]],
    ], $extra));
}

function ny_notes(string $text): void
{
    ny_assert(str_contains($text, 'måste fylla i anläggnings-id'), 'mejlet säger att anläggnings-id måste fyllas i');
    ny_assert(str_contains($text, 'samma person som står på elfakturan'), 'mejlet säger att den som signerar ska stå på elfakturan');
    ny_assert(str_contains($text, 'välja vem som signerar'), 'mejlet säger att man kan välja vem som signerar');
    ny_assert(substr_count($text, 'måste fylla i anläggnings-id') === 1, 'anläggnings-meningen står en gång');
}

ny_secret();
$tokens = [];

try {
    $mailer = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/mailer.php');
    ny_assert(!preg_match('/\bmail\s*\(/', $mailer), 'fullmaktsmejlet anropar inte mail()');
    ny_assert(!str_contains($mailer, 'sendmail'), 'fullmaktsmejlet anropar inte sendmail');
    ny_assert(str_contains($mailer, 'jeab_system_mail_send'), 'fullmaktsmejlet går via systemmejlet');
    $hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
    ny_assert(str_contains($hook, "'ordrar.fullmakt_preview'"), 'hooken tar ordrar.fullmakt_preview');
    ny_assert(str_contains($hook, '$input[\'dry\'] = 1'), 'förhandsvisningen tvingas torr');

    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $GLOBALS['NY_MAILS'] = [];
    $started = jeab_ellevio_fullmakt_start(ny_row(801));
    $oldToken = trim((string) ($started['fullmakt_token'] ?? ''));
    $tokens[] = $oldToken;
    ny_assert($oldToken !== '' && is_file(fullmakt_pending_path($oldToken)), 'första starten skapar en väntande fullmakt');
    $first = ny_of_type('fullmakt.start');
    ny_assert(count($first) === 1, 'första skicket postar ett fullmakt.start');
    ny_notes((string) ($first[0]['invite_text'] ?? ''));
    ny_assert(($first[0]['parter']['huvudsokande']['namn'] ?? '') === 'Ada Adolfsson', 'första skicket tar huvudsökande');
    ny_assert(($first[0]['parter']['medsokande'][0]['namn'] ?? '') === 'Bo Adolfsson', 'första skicket tar medsökande');
    ny_assert($GLOBALS['NY_MAILS'] === [], 'första starten mejlar inte själv');

    $idle = jeab_ordrar_v3_fullmakt_html(ny_row(802), 'jarfalla', 802, false);
    ny_assert(!str_contains($idle, 'Skicka ny fullmakt'), 'knappen visas inte innan en fullmakt finns');
    $box = jeab_ordrar_v3_fullmakt_html($started, 'jarfalla', 801, false);
    ny_assert(str_contains($box, '>Skicka ny fullmakt<'), 'ordervyn har knappen Skicka ny fullmakt');
    ny_assert(str_contains($box, 'ordrar.fullmakt_ny') && str_contains($box, 'name="confirm" value="1"'), 'knappen postar bekräftad ordrar.fullmakt_ny');
    ny_assert(str_contains($box, 'confirm('), 'knappen frågar innan den skickar');
    $oldBox = jeab_ordrar_fullmakt_status_html($started, 'jarfalla', 801, 'eon', false);
    ny_assert(str_contains($oldBox, '>Skicka ny fullmakt<'), 'äldre ordervyn har samma knapp');

    $blocked = false;
    try {
        jeab_ellevio_fullmakt_new_from_input(['id' => 801]);
    } catch (InvalidArgumentException $e) {
        $blocked = str_contains($e->getMessage(), 'Bekräfta');
    }
    ny_assert($blocked, 'utan bekräftelse startas ingen ny fullmakt');
    ny_assert((string) (jeab_purchase_get(801)['fullmakt_token'] ?? '') === $oldToken, 'avvisad bekräftelse lämnar den gamla länken');

    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $GLOBALS['NY_MAILS'] = [];
    $fresh = jeab_ellevio_fullmakt_new_from_input(['id' => 801, 'confirm' => '1']);
    $newToken = trim((string) ($fresh['fullmakt_token'] ?? ''));
    $tokens[] = $newToken;
    ny_assert($newToken !== '' && $newToken !== $oldToken, 'ny fullmakt får en ny länk');
    ny_assert(is_file(fullmakt_pending_path($oldToken)), 'den gamla fullmakten ligger kvar på disk');
    ny_assert((string) (($fresh['fullmakt_history'][0]['token'] ?? '')) === $oldToken, 'den gamla token finns i historiken');
    ny_assert(count(ny_of_type('fullmakt.start')) === 1, 'en ny fullmakt postar ett fullmakt.start');
    ny_assert((ny_of_type('fullmakt.start')[0]['token'] ?? '') === $newToken, 'webhooken bär den nya token');
    ny_assert((ny_of_type('fullmakt.start')[0]['parter']['medsokande'][0]['namn'] ?? '') === 'Bo Adolfsson', 'nya fullmakten tar samma ägare');
    ny_notes((string) (ny_of_type('fullmakt.start')[0]['invite_text'] ?? ''));
    ny_assert($GLOBALS['NY_MAILS'] === [], 'ny fullmakt mejlar inte vid sidan av starten');
    ny_assert(empty($fresh['fullmakt_new_kept']), 'första klicket räknas som skickat');

    $again = jeab_ellevio_fullmakt_new_from_input(['id' => 801, 'confirm' => '1']);
    ny_assert(!empty($again['fullmakt_new_kept']), 'andra klicket inom spärren skickar inte igen');
    ny_assert((string) ($again['fullmakt_token'] ?? '') === $newToken, 'dubbelklick behåller den nya länken');
    ny_assert(count(ny_of_type('fullmakt.start')) === 1, 'dubbelklick postar inte ett andra fullmakt.start');
    ny_assert(count(jeab_ellevio_fullmakt_history($again)) === 1, 'dubbelklick lägger inte en extra historikrad');

    $oldBody = "Hej {namn}, signera här. Länk: {url}\n";
    [$subject, $resent] = fullmakt_invite_parts([
        'type' => 'ellevio',
        'invite_body' => $oldBody,
        'invite_subject' => 'Fullmakt till Ellevio — signera',
        'invite_namn' => 'Ada Adolfsson',
        'invite_epost' => 'ada@example.com',
    ], 'https://flowstack.example.test/fullmakt/s/' . $oldToken);
    ny_assert($subject !== '', 'omsändning har ämne');
    ny_notes($resent);
    ny_assert(str_contains($resent, 'https://flowstack.example.test/fullmakt/s/' . $oldToken), 'omsändning behåller länken');
    $GLOBALS['NY_MAILS'] = [];
    $sent = fullmakt_send_invite([
        'type' => 'ellevio',
        'invite_body' => $oldBody,
        'invite_namn' => 'Ada Adolfsson',
        'invite_epost' => 'ada@example.com',
        'epost' => 'ada@example.com',
    ], 'https://flowstack.example.test/fullmakt/s/' . $oldToken);
    ny_assert($sent === true && count($GLOBALS['NY_MAILS']) === 1, 'omsändning går genom systemmejlet');
    ny_assert(($GLOBALS['NY_MAILS'][0]['to'] ?? '') === 'ada@example.com', 'omsändning till samma mottagare');
    ny_notes((string) ($GLOBALS['NY_MAILS'][0]['text'] ?? ''));
    ny_assert(($GLOBALS['NY_MAILS'][0]['tags'][0] ?? '') === 'fullmakt', 'systemmejlet märks fullmakt');

    $lill = ny_row(811, [
        'huvudsokande' => 'Kim Lilläng',
        'name' => 'Kim Lilläng',
        'email' => 'kim@example.com',
        'huvudsokande_address' => 'Lillängsgatan 15, 168 48 Bromma',
        'ort' => 'Bromma',
        'medsokande' => [[
            'name' => 'Noa Lilläng',
            'personnummer' => '19800101-1234',
        ]],
    ]);
    $moss = ny_row(812, [
        'huvudsokande' => 'Eva Mosstorp',
        'name' => 'Eva Mosstorp',
        'email' => 'eva@example.com',
        'huvudsokande_address' => 'Mosstorpsvägen 35, 181 56 Lidingö',
        'ort' => 'Lidingö',
        'medsokande' => [[
            'name' => 'Per Mosstorp',
            'personnummer' => '19751212-1212',
        ]],
    ]);
    unset($lill, $moss);
    $beforeHooks = count((array) $GLOBALS['_JEAB_BUG_WEBHOOKS']);
    $beforeMails = count($GLOBALS['NY_MAILS']);
    $store = new JeabUnavailableStore();
    $lillPreview = jeab_ellevio_fullmakt_preview_dispatch($store, [
        'action' => 'ordrar.fullmakt_preview',
        'address' => 'Lillängsgatan 15 Bromma',
        'dry' => 0,
    ]);
    ny_assert(($lillPreview['action'] ?? '') === 'ordrar.fullmakt_preview', 'anropet heter ordrar.fullmakt_preview');
    ny_assert(($lillPreview['dry'] ?? 0) === 1 && ($lillPreview['sent'] ?? true) === false, 'dry=0 på förhandsvisningen skickar ändå inget');
    ny_assert(($lillPreview['count'] ?? 0) === 1, 'Lillängsgatan ger en träff');
    $one = $lillPreview['previews'][0] ?? [];
    ny_assert(($one['recipient'] ?? '') === 'kim@example.com', 'förhandsvisningen visar mottagaren');
    ny_assert(($one['owners'][0]['namn'] ?? '') === 'Kim Lilläng' && ($one['owners'][1]['namn'] ?? '') === 'Noa Lilläng', 'förhandsvisningen visar valbara ägare');
    ny_notes((string) ($one['text'] ?? ''));
    ny_assert(!str_contains((string) ($one['text'] ?? ''), '19700101'), 'förhandsvisningen tar inte med personnummer');
    $mossPreview = jeab_ellevio_fullmakt_preview_dispatch($store, [
        'address' => 'Mosstorpsvägen 35 Lidingö',
    ]);
    ny_assert(($mossPreview['count'] ?? 0) === 1 && ($mossPreview['previews'][0]['recipient'] ?? '') === 'eva@example.com', 'Mosstorpsvägen förhandsvisas utan utskick');
    ny_assert(($mossPreview['previews'][0]['owners'][1]['namn'] ?? '') === 'Per Mosstorp', 'Mosstorpsvägen visar medsökande');
    ny_assert(count((array) $GLOBALS['_JEAB_BUG_WEBHOOKS']) === $beforeHooks, 'förhandsvisningen postar ingen webhook');
    ny_assert(count($GLOBALS['NY_MAILS']) === $beforeMails, 'förhandsvisningen skickar inget mejl');
} finally {
    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
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
}

echo "fullmakt ny: ok\n";
