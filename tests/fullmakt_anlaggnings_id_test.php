<?php

declare(strict_types=1);

require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/store.php';
require dirname(__DIR__) . '/fullmakt/lib/parties.php';
require dirname(__DIR__) . '/src/ellevio_fullmakt.php';

function aid_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

function aid_cleanup(string $token): void
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

$empty = fullmakt_anlaggnings_id_error('');
aid_assert(str_contains($empty, 'Fyll i anläggnings-id'), 'tomt id blockeras, fick: ' . $empty);
aid_assert(fullmakt_anlaggnings_id_error('abc') === $empty, 'bara bokstäver räknas som tomt');

$short = fullmakt_anlaggnings_id_error('735999');
aid_assert(str_contains($short, 'exakt 18 siffror'), 'för kort id blockeras, fick: ' . $short);
aid_assert(fullmakt_anlaggnings_id_error('7359 9911 4010 13581') === $short, '17 siffror med mellanslag blockeras');

$okId = '735999114010135815';
aid_assert(fullmakt_anlaggnings_id_error($okId) === '', '18 siffror går igenom');
aid_assert(fullmakt_anlaggnings_id_error('7359 9911 4010 135815') === '', 'mellanslag skalas av innan längdkoll');
aid_assert(fullmakt_anlaggnings_id_digits('SE 735999114010135815') === $okId, 'prefix som inte är siffror tas bort');

aid_assert(fullmakt_anlaggnings_id_required(['type' => 'ellevio']) === true, 'ellevio kräver id');
aid_assert(fullmakt_anlaggnings_id_required([]) === true, 'saknad typ räknas som ellevio');
aid_assert(fullmakt_anlaggnings_id_required(['type' => 'overlay']) === false, 'overlay kräver inte id');

$doc = [
    'token' => 'prefill',
    'type' => 'ellevio',
    'anlaggnings_id' => '7359 9911 4010 135815',
];
aid_assert(fullmakt_prefill_anlaggnings_id($doc, []) === $okId, 'orderns id förifylls från dokumentet');
aid_assert(fullmakt_prefill_anlaggnings_id($doc, ['anlaggnings_id' => '']) === '', 'tömt fält i post vinner över förifyllt');
aid_assert(fullmakt_prefill_anlaggnings_id($doc, ['anlaggnings_id' => '111122223333444455']) === '111122223333444455', 'postat id vinner');

aid_assert(jeab_ellevio_fullmakt_anlaggnings_digits(['anlaggnings_id' => '']) === '', 'tom orderrad ger tomt id');
aid_assert(jeab_ellevio_fullmakt_anlaggnings_digits(['anlaggnings_id' => '7359-99114010135815']) === $okId, 'orderradens id normaliseras');
aid_assert(jeab_ellevio_fullmakt_anlaggnings_id_for_token('') === '', 'tom token slår inte upp order');

$partyDoc = [
    'token' => 'party',
    'status' => 'pending',
    'typ' => 'privatperson',
    'part' => 'huvudsokande',
    'namn' => 'Sven Huvudsson',
    'personnummer' => '19640206-3314',
    'anlaggnings_id' => $okId,
    'parter' => [
        'huvudsokande' => ['namn' => 'Sven Huvudsson', 'personnummer' => '19640206-3314'],
        'medsokande' => [[
            'namn' => 'Eva Medsökande',
            'personnummer' => '19680512-1234',
            'adress' => 'Björkvägen 4',
            'postnummer' => '181 30',
            'ort' => 'Lidingö',
        ]],
    ],
];
$switched = fullmakt_apply_party($partyDoc, 'medsokande', 0);
aid_assert(($switched['namn'] ?? '') === 'Eva Medsökande', 'medsökande byter namn');
aid_assert(($switched['anlaggnings_id'] ?? '') === $okId, 'anläggnings-id ligger kvar vid partbyte');
aid_assert(fullmakt_anlaggnings_id_required($switched) === true, 'medsökande kräver också anläggnings-id');

$keptEmpty = fullmakt_backfill_anlaggnings_id(['type' => 'ellevio', 'anlaggnings_id' => '']);
aid_assert(($keptEmpty['anlaggnings_id'] ?? 'x') === '', 'tomt id på dokumentet slår inte upp ordern');
$normalized = fullmakt_backfill_anlaggnings_id(['type' => 'ellevio', 'anlaggnings_id' => '7359 9911 4010 135815']);
aid_assert(($normalized['anlaggnings_id'] ?? '') === $okId, 'backfill normaliserar siffrorna');
$overlayDoc = fullmakt_backfill_anlaggnings_id(['type' => 'overlay', 'namn' => 'Ann']);
aid_assert(!array_key_exists('anlaggnings_id', $overlayDoc), 'overlay backfill lägger inte på id');

fullmakt_boot();

$base = [
    'namn' => 'Test Testsson',
    'personnummer' => '19700101-0101',
    'epost' => 'test@example.com',
    'typ' => 'privatperson',
];

$blocked = fullmakt_create_doc($base);
$blockedToken = (string) $blocked['token'];
try {
    $threw = false;
    try {
        fullmakt_mark_signed($blockedToken, $blocked, '%PDF-1.4', ['stamp_method' => 'ellevio']);
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'Fyll i anläggnings-id');
        aid_assert($threw, 'signering utan id kastade fel text, fick: ' . $e->getMessage());
    }
    aid_assert($threw, 'signering utan id ska kastas');
    $still = fullmakt_load_doc($blockedToken);
    aid_assert(is_array($still) && ($still['status'] ?? '') === 'pending', 'avvisad signering lämnar dokumentet osignerat');
    aid_assert(!is_file(fullmakt_signed_json_path($blockedToken)), 'ingen signerad json utan id');

    $shortDoc = fullmakt_create_doc($base);
    $shortToken = (string) $shortDoc['token'];
    $shortThrew = false;
    try {
        fullmakt_mark_signed($shortToken, $shortDoc, '%PDF-1.4', [
            'stamp_method' => 'ellevio',
            'anlaggnings_id' => '735999',
        ]);
    } catch (RuntimeException $e) {
        $shortThrew = str_contains($e->getMessage(), 'exakt 18 siffror');
    }
    aid_assert($shortThrew, 'för kort id stoppas i mark_signed');
    aid_assert((fullmakt_load_doc($shortToken)['status'] ?? '') === 'pending', 'för kort id signerar inte');

    $fromOrder = fullmakt_create_doc($base + ['anlaggnings_id' => $okId]);
    $fromOrderToken = (string) $fromOrder['token'];
    $signedFromOrder = fullmakt_mark_signed($fromOrderToken, $fromOrder, '%PDF-1.4', ['stamp_method' => 'ellevio']);
    aid_assert(($signedFromOrder['anlaggnings_id'] ?? '') === $okId, 'id från ordern räcker när fältet inte postas över');
    aid_assert(($signedFromOrder['status'] ?? '') === 'signed', 'förifyllt id kan signeras');

    $manual = fullmakt_create_doc($base);
    $manualToken = (string) $manual['token'];
    $signedManual = fullmakt_mark_signed($manualToken, $manual, '%PDF-1.4', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => '7359 9911 4010 135815',
    ]);
    aid_assert(($signedManual['anlaggnings_id'] ?? '') === $okId, 'manuellt id normaliseras vid signering');

    $cleared = fullmakt_create_doc($base + ['anlaggnings_id' => $okId]);
    $clearedToken = (string) $cleared['token'];
    $clearedThrew = false;
    try {
        fullmakt_mark_signed($clearedToken, $cleared, '%PDF-1.4', [
            'stamp_method' => 'ellevio',
            'anlaggnings_id' => '',
        ]);
    } catch (RuntimeException $e) {
        $clearedThrew = str_contains($e->getMessage(), 'Fyll i anläggnings-id');
    }
    aid_assert($clearedThrew, 'tömt fält stoppar även om ordern hade id');

    $overlay = fullmakt_create_doc($base + ['type' => 'overlay']);
    $overlayToken = (string) $overlay['token'];
    $signedOverlay = fullmakt_mark_signed($overlayToken, $overlay, '%PDF-1.4', ['stamp_method' => 'fpdi']);
    aid_assert(($signedOverlay['status'] ?? '') === 'signed', 'overlay kan signeras utan anläggnings-id');
    aid_assert(!array_key_exists('anlaggnings_id', $signedOverlay) || ($signedOverlay['anlaggnings_id'] ?? '') === '', 'overlay får inget påtvingat id');
} finally {
    foreach ([$blockedToken, $shortToken ?? '', $fromOrderToken ?? '', $manualToken ?? '', $clearedToken ?? '', $overlayToken ?? ''] as $token) {
        if ($token !== '') {
            aid_cleanup($token);
        }
    }
}

echo "ok\n";
