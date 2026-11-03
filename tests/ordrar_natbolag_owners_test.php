<?php

declare(strict_types=1);

/**
 * SEOM (Sollentuna Energi & Miljö), Kraftringen och Öresundskraft är valbara
 * nätägare i samma lista som Ellevio, E.ON och Vattenfall. Befintliga värden
 * sparas, föreslås och skickas som förut. Bara Ellevio går via fullmakten.
 */

$tmp = sys_get_temp_dir() . '/jeab-natagare-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function own_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function own_row(array $extra): array
{
    return array_replace([
        'reminder_id' => 8801,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'name' => 'Ada Ägare',
        'huvudsokande' => 'Ada Ägare',
        'personnummer' => '197001011234',
        'fastighetsbeteckning' => 'TEST 1:1',
        'address' => 'Storgatan 1',
        'huvudsokande_address' => 'Storgatan 1',
        'natbolag' => '',
        'anlaggnings_id' => '735999123456789012',
        'kwh' => '10',
        'steps' => [],
    ], $extra);
}

jeab_ordrar_external_live(false);

$options = jeab_ordrar_natbolag_options();
own_assert($options === ['Ellevio', 'E.ON', 'Vattenfall', 'SEOM', 'Kraftringen', 'Öresundskraft'], 'listan behåller de tre och lägger till SEOM, Kraftringen och Öresundskraft');

$names = [
    'E.ON Energidistribution AB' => 'E.ON',
    'Ellevio AB' => 'Ellevio',
    'Vattenfall Eldistribution AB' => 'Vattenfall',
    'Sollentuna Energi & Miljö AB' => 'SEOM',
    'Sollentuna Elnät AB' => 'SEOM',
    'SEOM' => 'SEOM',
    'Kraftringen Nät AB' => 'Kraftringen',
    'Öresundskraft AB' => 'Öresundskraft',
    'Oresundskraft Elnat AB' => 'Öresundskraft',
    'Öresunds Kraft' => 'Öresundskraft',
    'Fortum' => '',
    '' => '',
];
foreach ($names as $raw => $expected) {
    own_assert(jeab_ordrar_natbolag_from_foretag($raw) === $expected, 'företagsnamn «' . $raw . '» blir ' . ($expected === '' ? 'tomt' : $expected));
}

$places = [
    ['postnr' => '191 41', 'ort' => 'Sollentuna', 'address' => 'Flottarestigen 6', 'expect' => 'SEOM'],
    ['postnr' => '19251', 'ort' => 'Sollentuna', 'address' => 'Flottarestigen 6', 'expect' => 'SEOM'],
    ['postnr' => '', 'ort' => 'Häggvik', 'address' => 'Kanalvägen 1, Häggvik', 'expect' => 'SEOM'],
    ['postnr' => '', 'ort' => 'Rotebro', 'address' => 'Rotebrovägen 2', 'expect' => 'SEOM'],
    ['postnr' => '', 'ort' => 'Eslöv', 'address' => 'Storgatan 1, Eslöv', 'expect' => 'Kraftringen'],
    ['postnr' => '', 'ort' => 'Horby', 'address' => 'Storgatan 1, Horby', 'expect' => 'Kraftringen'],
    ['postnr' => '', 'ort' => 'Lomma', 'address' => 'Strandvägen 4, Lomma', 'expect' => 'Kraftringen'],
    ['postnr' => '', 'ort' => 'Helsingborg', 'address' => 'Drottninggatan 1, Helsingborg', 'expect' => 'Öresundskraft'],
    ['postnr' => '', 'ort' => 'Angelholm', 'address' => 'Storgatan 3, Angelholm', 'expect' => 'Öresundskraft'],
    ['postnr' => '176 74', 'ort' => 'Järfälla', 'address' => 'Krokusvägen 10', 'expect' => 'E.ON'],
    ['postnr' => '165 65', 'ort' => 'Hässelby', 'address' => 'Hässelby Strandväg 47', 'expect' => 'Ellevio'],
    ['postnr' => '147 92', 'ort' => 'Grödinge', 'address' => 'Sibblevägen 8', 'expect' => 'Vattenfall'],
    ['postnr' => '169 00', 'ort' => 'Okändort', 'address' => 'Storgatan 1', 'expect' => ''],
    ['postnr' => '', 'ort' => 'Lund', 'address' => 'Lundavägen 1, Lund', 'expect' => ''],
];
foreach ($places as $place) {
    $row = own_row([
        'company' => '',
        'natbolag' => '',
        'postnr' => $place['postnr'],
        'ort' => $place['ort'],
        'address' => $place['address'],
        'huvudsokande_address' => $place['address'],
    ]);
    $got = jeab_ordrar_natbolag_address_fallback($row);
    own_assert($got === $place['expect'], $place['address'] . ' ' . $place['postnr'] . ' faller tillbaka till ' . ($place['expect'] === '' ? 'inget' : $place['expect']) . ', fick ' . ($got === '' ? 'inget' : $got));
}

$kept = own_row([
    'natbolag' => 'SEOM',
    'address' => 'Hässelby Strandväg 47, Hässelby',
    'huvudsokande_address' => 'Hässelby Strandväg 47, Hässelby',
    'postnr' => '165 65',
    'ort' => 'Hässelby',
]);
own_assert(jeab_ordrar_natbolag($kept) === 'SEOM', 'sparat SEOM i Hässelby ligger kvar');
own_assert(jeab_ordrar_natbolag(own_row(['company' => 'malmo', 'natbolag' => '', 'address' => 'Malmö', 'huvudsokande_address' => 'Malmö', 'ort' => 'Malmö'])) === 'E.ON', 'Malmö utan träff är fortfarande E.ON');

$spec = jeab_ordrar_spec_html(own_row(['natbolag' => '']), 'jarfalla', 8801, null);
$status = jeab_ordrar_status_html(own_row(['natbolag' => 'Kraftringen']), 'jarfalla', 8801, null);
$fields = jeab_ordrar_v3_foranmalan_fields(own_row(['natbolag' => 'Öresundskraft']));
$overview = jeab_ordrar_v3_overview_html(own_row(['natbolag' => 'SEOM']), 'jarfalla', 8801, null);
foreach (['Ellevio', 'E.ON', 'Vattenfall', 'SEOM', 'Kraftringen', 'Öresundskraft'] as $owner) {
    own_assert(str_contains($spec, 'value="' . $owner . '"'), 'följdfrågor har ' . $owner);
    own_assert(str_contains($status, 'value="' . $owner . '"'), 'status har ' . $owner);
    own_assert(str_contains($fields, 'value="' . $owner . '"'), 'ordrar v3 föranmälan har ' . $owner);
    own_assert(str_contains($overview, 'value="' . $owner . '"'), 'ordrar v3 objekt har ' . $owner);
}
own_assert(str_contains($status, 'value="Kraftringen" selected'), 'sparad Kraftringen är vald på status');
own_assert(str_contains($fields, 'value="Öresundskraft" checked'), 'sparad Öresundskraft är vald i v3');
own_assert(str_contains($overview, 'value="SEOM" selected'), 'sparad SEOM är vald i v3-objektet');
own_assert(substr_count($spec, 'name="natbolag"') === 6, 'följdfrågor har sex nätägare');

jeab_purchase_put(own_row(['reminder_id' => 8801, 'natbolag' => 'Ellevio']));
foreach (['SEOM', 'Kraftringen', 'Öresundskraft'] as $owner) {
    $saved = jeab_ordrar_save_objekt(['id' => 8801, 'natbolag' => $owner]);
    own_assert(($saved['natbolag'] ?? '') === $owner, $owner . ' sparas på ordern');
    $again = jeab_purchase_get(8801);
    own_assert(is_array($again) && ($again['natbolag'] ?? '') === $owner, $owner . ' ligger kvar efter omläsning');
}
$untouched = jeab_ordrar_save_objekt(['id' => 8801, 'natbolag' => 'Inte ett bolag']);
own_assert(($untouched['natbolag'] ?? '') === 'Öresundskraft', 'ogiltig nätägare skrivs inte över');
own_assert(jeab_ordrar_save_objekt(['id' => 8801, 'natbolag' => 'E.ON'])['natbolag'] === 'E.ON', 'E.ON går fortfarande att spara');

foreach (['SEOM', 'Kraftringen', 'Öresundskraft', 'E.ON', 'Vattenfall'] as $owner) {
    $row = own_row(['natbolag' => $owner]);
    own_assert(jeab_ordrar_bokmalen_missing($row, 'ordrar.foranmalan') === [], $owner . ' med anläggnings-id och kWh saknar inget');
    own_assert(jeab_ordrar_bokmalen_skips_anlaggnings_id($row) === false, $owner . ' kräver anläggnings-id');
    own_assert(jeab_ellevio_fullmakt_applies($row) === false, $owner . ' startar inte Ellevio-fullmakt');
    $gap = own_row(['natbolag' => $owner, 'anlaggnings_id' => '']);
    own_assert(jeab_ordrar_bokmalen_missing($gap, 'ordrar.foranmalan') === ['anläggnings-id'], $owner . ' utan anläggnings-id stoppas');
}
$ellevio = own_row(['natbolag' => 'Ellevio', 'anlaggnings_id' => '']);
own_assert(jeab_ellevio_fullmakt_applies($ellevio) === true, 'Ellevio startar fortfarande fullmakt');
own_assert(jeab_ordrar_bokmalen_skips_anlaggnings_id($ellevio) === true, 'Ellevio hoppar fortfarande över anläggnings-id');
own_assert(jeab_ordrar_bokmalen_missing($ellevio, 'ordrar.foranmalan') === [], 'Ellevio utan anläggnings-id saknar inte nätfält');

$payload = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', own_row(['natbolag' => 'Öresundskraft']), false);
own_assert(is_array($payload) && ($payload['natbolag'] ?? '') === 'Öresundskraft', 'föranmälan-payloaden bär Öresundskraft');
own_assert(($payload['utkast'] ?? null) === false, 'automatisk föranmälan har utkast false');
$plomb = jeab_bokmalen_ordrar_plomb_payload(own_row(['natbolag' => 'Kraftringen', 'kind' => 'batteri']));
own_assert(is_array($plomb) && ($plomb['natbolag'] ?? '') === 'Kraftringen' && ($plomb['natagare'] ?? '') === 'Kraftringen', 'plombbrytning bär Kraftringen');

echo "OK  SEOM, Kraftringen och Öresundskraft\n";
