<?php

declare(strict_types=1);

require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/mailer.php';
require dirname(__DIR__) . '/fullmakt/lib/parties.php';
require dirname(__DIR__) . '/src/ellevio_fullmakt.php';

function part_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

$huvudMail = 'huvud@example.com';
$huvudNamn = 'Sven Huvudsson';
$huvudPnr = '19640206-3314';
$huvudPhone = '+46700000001';
$medNamn = 'Eva Medsökande';
$medPnr = '19680512-1234';
$medAddress = 'Björkvägen 4, 181 30 Lidingö';

$row = [
    'huvudsokande' => $huvudNamn,
    'personnummer' => $huvudPnr,
    'phone' => $huvudPhone,
    'email' => $huvudMail,
    'huvudsokande_address' => 'Mosstorpsvägen 35, 181 56 Lidingö',
    'medsokande' => [[
        'name' => $medNamn,
        'personnummer' => $medPnr,
        'address' => $medAddress,
    ]],
];

$fields = jeab_ellevio_fullmakt_fields($row);
$bundle = jeab_ellevio_fullmakt_party_bundle($row, $fields);
part_assert($bundle['invite_epost'] === $huvudMail, 'inbjudan stannar på huvudsökandes e-post');
part_assert($bundle['invite_namn'] === $huvudNamn, 'hälsningen stannar på huvudsökande');
part_assert(($bundle['parter']['huvudsokande']['namn'] ?? '') === $huvudNamn, 'huvudpartens namn');
$med = $bundle['parter']['medsokande'][0] ?? [];
part_assert(($med['namn'] ?? '') === $medNamn, 'medsökandes namn');
part_assert(($med['personnummer'] ?? '') === $medPnr, 'medsökandes personnummer');
part_assert(($med['adress'] ?? '') === 'Björkvägen 4', 'medsökandes gata');
part_assert(($med['postnummer'] ?? '') === '181 30', 'medsökandes postnummer');
part_assert(($med['ort'] ?? '') === 'Lidingö', 'medsökandes ort');
part_assert(($med['telefon'] ?? '') === '', 'medsökande får inte huvudsökandes telefon');
part_assert(($med['epost'] ?? '') === '', 'medsökande får inte huvudsökandes e-post');
part_assert(!str_contains((string) ($med['anlaggningsadress'] ?? ''), 'Mosstorpsvägen'), 'anläggningsadress byter till medsökandes adress');

$doc = array_merge($fields, [
    'token' => 'test-token',
    'status' => 'pending',
    'typ' => 'privatperson',
    'part' => 'huvudsokande',
    'invite_epost' => $bundle['invite_epost'],
    'invite_namn' => $bundle['invite_namn'],
    'parter' => $bundle['parter'],
]);

$html = fullmakt_party_switch_html($doc);
part_assert(str_contains($html, 'id="byt-medsokande"'), 'byt-knappen syns innan signering');
part_assert(str_contains($html, $medNamn), 'knappen namnger medsökande');
part_assert(str_contains($html, 'Signeringslänken går fortfarande till huvudsökandes e-post'), 'copy om mailet');

$empty = $doc;
$empty['parter']['medsokande'] = [];
part_assert(fullmakt_party_switch_html($empty) === '', 'utan medsökande visas inget byte');

$switched = fullmakt_apply_party($doc, 'medsokande', 0);
part_assert(($switched['part'] ?? '') === 'medsokande', 'part markeras medsökande');
part_assert(($switched['namn'] ?? '') === $medNamn, 'blankettens namn');
part_assert(($switched['personnummer'] ?? '') === $medPnr, 'blankettens personnummer');
part_assert(($switched['telefon'] ?? '') === '', 'blankettens telefon töms');
part_assert(($switched['epost'] ?? '') === '', 'blankettens e-post töms');
part_assert(($switched['adress'] ?? '') === 'Björkvägen 4', 'blankettens adress');
part_assert(($switched['ort'] ?? '') === 'Lidingö', 'blankettens ort används även som namnförtydligandets ort');
part_assert(($switched['invite_epost'] ?? '') === $huvudMail, 'invite_epost rörs inte');
part_assert(($switched['invite_namn'] ?? '') === $huvudNamn, 'invite_namn rörs inte');
foreach (['namn', 'personnummer', 'telefon', 'epost', 'adress', 'postnummer', 'ort', 'anlaggningsadress', 'foretagsnamn'] as $key) {
    part_assert(!str_contains((string) ($switched[$key] ?? ''), $huvudNamn), "huvudnamn borta från {$key}");
    part_assert(!str_contains((string) ($switched[$key] ?? ''), $huvudPnr), "huvudets personnummer borta från {$key}");
    part_assert(!str_contains((string) ($switched[$key] ?? ''), $huvudMail), "huvudets e-post borta från {$key}");
    part_assert(!str_contains((string) ($switched[$key] ?? ''), $huvudPhone), "huvudets telefon borta från {$key}");
    part_assert(!str_contains((string) ($switched[$key] ?? ''), 'Mosstorpsvägen'), "huvudets adress borta från {$key}");
}

$back = fullmakt_apply_party($switched, 'huvudsokande');
part_assert(($back['namn'] ?? '') === $huvudNamn, 'tillbaka till huvudsökande');
part_assert(($back['epost'] ?? '') === $huvudMail, 'huvudets e-post tillbaka på blanketten');

$after = fullmakt_party_switch_html($switched);
part_assert(str_contains($after, 'Blanketten står på medsökande'), 'bekräftelse efter bytet');
part_assert(str_contains($after, 'Byt tillbaka till huvudsökande'), 'går att ångra innan signering');

part_assert(fullmakt_invite_recipient($switched) === $huvudMail, 'utskick till huvudsökande');
part_assert(fullmakt_invite_greeting_name($switched) === $huvudNamn, 'hälsning till huvudsökande');
[$subject, $body] = fullmakt_invite_parts($switched, 'https://flowstack.example.test/fullmakt/s/test-token');
part_assert(str_contains($body, 'Hej ' . $huvudNamn), 'mejlet hälsar huvudsökande');
part_assert(!str_contains($body, $medNamn), 'mejlet nämner inte medsökande');
part_assert(str_contains($body, 'https://flowstack.example.test/fullmakt/s/test-token'), 'samma signeringslänk');

$captured = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured): array {
    $captured = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'id' => 'm1'];
};
$sent = fullmakt_send_invite($switched, 'https://flowstack.example.test/fullmakt/s/test-token');
part_assert($sent === true, 'inbjudan skickas');
part_assert(($captured['to'] ?? '') === $huvudMail, 'mottagare är huvudsökande, fick ' . (string) ($captured['to'] ?? ''));
part_assert(str_contains((string) ($captured['text'] ?? ''), $huvudNamn), 'brödtextens namn är huvudsökande');

$old = [
    'token' => 'gammal',
    'status' => 'pending',
    'namn' => $huvudNamn,
    'personnummer' => $huvudPnr,
    'telefon' => $huvudPhone,
    'epost' => $huvudMail,
    'adress' => 'Mosstorpsvägen 35',
    'postnummer' => '181 56',
    'ort' => 'Lidingö',
    'anlaggningsadress' => 'Mosstorpsvägen 35, 181 56 Lidingö',
    'foretagsnamn' => '',
];
$hydrated = fullmakt_ensure_parties($old, static function (string $token) use ($med): array {
    part_assert($token === 'gammal', 'uppslag använder token');
    return [$med];
});
part_assert(($hydrated['invite_epost'] ?? '') === $huvudMail, 'äldre länk låser invite-mejlet');
part_assert(fullmakt_med_parties($hydrated) !== [], 'äldre länk får medsökande från ordern');
$fromOld = fullmakt_apply_party($hydrated, 'medsokande', 0);
part_assert(($fromOld['namn'] ?? '') === $medNamn, 'äldre länk kan byta part');
part_assert(fullmakt_invite_recipient($fromOld) === $huvudMail, 'äldre länk mejlar fortfarande huvudsökande');

echo "fullmakt part: ok\n";
