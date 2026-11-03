<?php

declare(strict_types=1);

/**
 * Sigen-val i batteri-kalkylen: rätt batteri och bottenplatta per typ,
 * TP2-tillägg 1 500, storlekar per typ, antal som multiplicerar, och
 * orderrader. Sparad eller stängd order räknas inte om.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-kalkyl-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function sg_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sg_line(array $lines, string $role): array
{
    foreach ($lines as $line) {
        if (is_array($line) && ($line['role'] ?? '') === $role) {
            return $line;
        }
    }
    return [];
}

function sg_purchase(string $type, string $kw, int $qty, array $extra = []): array
{
    return array_merge([
        'kind' => 'batteri',
        'kundpris' => 70000,
        'pris' => 70000,
        'kalkyl' => [
            'sokande' => 2,
            'b14' => 0,
            'b3' => $qty,
            'b15' => $type === 'tp2' ? 1 : 0,
            'laddare' => 0,
            'sigen_typ' => $type,
            'sigen_kw' => $kw,
        ],
    ], $extra);
}

sg_assert(JEAB_SIGEN_NEO_BAT_8_0_EX_VAT === 19034, '7,5 kWh är SigenStor Neo BAT 8.0 för 19 034');

sg_assert(jeab_sigen_sizes('neo') === ['8.0', '10.0', '12.0', '15.0'], 'NEO har 8, 10, 12 och 15');
sg_assert(jeab_sigen_sizes('tp2') === ['12.0'], 'TP2 har bara 12.0');
sg_assert(jeab_sigen_sizes('ec') === ['12.0', '15.0', '17.0', '20.0'], 'EC har 12, 15, 17 och 20');
sg_assert(jeab_sigen_resolve('tp2', '15.0', 1) === null, 'TP2 tar inte 15 kW');
sg_assert(jeab_sigen_resolve('ec', '8.0', 1) === null, 'EC tar inte 8 kW');
sg_assert(jeab_sigen_resolve('neo', '20', 1) === null, 'NEO tar inte 20 kW');

$neo = jeab_sigen_lines(jeab_sigen_resolve('neo', '10', 2));
sg_assert(sg_line($neo, 'inverter')['title'] === 'SigenStor Neo EC 10.0 TP' && sg_line($neo, 'inverter')['purchase'] === 17424 && sg_line($neo, 'inverter')['qty'] === 1, 'NEO 10.0 är rätt växelriktare');
sg_assert(sg_line($neo, 'battery')['title'] === 'SigenStor Neo BAT 8.0' && sg_line($neo, 'battery')['purchase'] === 19034 && sg_line($neo, 'battery')['qty'] === 2, 'NEO-batteriet är BAT 8.0 för 19 034');
sg_assert(sg_line($neo, 'battery')['sku'] === '', 'NEO-batteriet har inget artikelnummer');
sg_assert(sg_line($neo, 'plate')['title'] === 'Bottenplatta' && sg_line($neo, 'plate')['purchase'] === 1300 && sg_line($neo, 'plate')['sku'] === '11170033', 'NEO-bottenplatta är 1 300 och 11170033');
sg_assert(sg_line($neo, 'surcharge') === [], 'NEO har inget TP2-tillägg');

$tp2 = jeab_sigen_lines(jeab_sigen_resolve('tp2', '12.0', 3));
sg_assert(sg_line($tp2, 'inverter')['sku'] === '11010186' && sg_line($tp2, 'inverter')['purchase'] === 12403, 'TP2-växelriktaren är 11010186 och 12 403');
sg_assert(sg_line($tp2, 'battery')['sku'] === '11130012' && sg_line($tp2, 'battery')['purchase'] === 27321 && sg_line($tp2, 'battery')['qty'] === 3, 'TP2-batteriet är BAT 10.0 × 3');
sg_assert(jeab_sigen_line_purchase_sum(sg_line($tp2, 'battery')) === 27321 * 3, 'batteriantalet multiplicerar inköpspriset');
sg_assert(sg_line($tp2, 'plate')['purchase'] === 3000 && sg_line($tp2, 'plate')['sku'] === '', 'TP2-bottenplatta är 3 000 utan känt artikelnummer');
sg_assert(sg_line($tp2, 'surcharge')['purchase'] === 1500 && sg_line($tp2, 'surcharge')['qty'] === 1, 'TP2 lägger till 1 500');

$ec = jeab_sigen_lines(jeab_sigen_resolve('ec', '20', 4));
sg_assert(sg_line($ec, 'inverter')['sku'] === '11010017' && sg_line($ec, 'inverter')['purchase'] === 28207, 'EC 20 är 11010017 och 28 207');
sg_assert(sg_line($ec, 'battery')['sku'] === '11130012' && sg_line($ec, 'battery')['qty'] === 4, 'EC använder BAT 10.0 × antal');
sg_assert(sg_line($ec, 'plate')['purchase'] === 1300 && sg_line($ec, 'plate')['sku'] === '11170033', 'EC-bottenplatta är 1 300 och 11170033');
sg_assert(sg_line($ec, 'surcharge') === [], 'EC har inget TP2-tillägg');

$tp2Buy = sg_purchase('tp2', '12.0', 2);
$tp2Input = jeab_offert_input_from_purchase($tp2Buy, 'batteri');
$with = jeab_offert_calc($tp2Input);
$bare = $tp2Input;
unset($bare['sigen_typ'], $bare['sigen_kw']);
$bare['sigen_surcharge'] = 0;
$without = jeab_offert_calc($bare);
sg_assert((int) round($with['g27_cost'] - $without['g27_cost']) === 1500, 'TP2-tillägget ökar inköpet med 1 500 i den befintliga kostnaden');
sg_assert((int) round($with['g27_cost']) === 12403 + (27321 * 2) + 3000 + 1500, 'TP2-inköpet är växelriktare + batteri × 2 + bottenplatta + tillägg');

$plain = [
    'kind' => 'batteri',
    'kundpris' => 70000,
    'pris' => 70000,
    'kalkyl' => ['sokande' => 2, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
];
$oldRows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $plain);
sg_assert($oldRows[0]['desc'] === 'Batteri' && $oldRows[0]['price'] === 131845, 'order utan Sigen-val behåller batteriraden 131 845');
sg_assert($oldRows[1]['desc'] === 'Schablon' && $oldRows[1]['price'] === 4077, 'order utan Sigen-val behåller schablonen 4 077');

$newRows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $tp2Buy);
$newSum = 0;
$oldSum = 0;
foreach ($newRows as $row) {
    $newSum += (int) $row['price'];
}
foreach ($oldRows as $row) {
    $oldSum += (int) $row['price'];
}
sg_assert($newSum === $oldSum, 'Sigen-raderna summerar till samma kundbelopp som Kalkylen');
sg_assert(jeab_offert_rows_att_betala($newRows, 2) === jeab_offert_rows_att_betala($oldRows, 2), 'att betala ändras inte av artikelraderna');
$byDesc = [];
foreach ($newRows as $row) {
    $byDesc[$row['desc']] = $row;
}
sg_assert(($byDesc['Sigen Hybrid Inverter 12.0 kW TP2']['article'] ?? '') === 'battery' && ($byDesc['Sigen Hybrid Inverter 12.0 kW TP2']['qty'] ?? 0) === 1, 'Fortnox-raden för TP2-växelriktaren använder batteriartikeln');
sg_assert(($byDesc['SigenStor BAT 10.0 (9 kWh)']['article'] ?? '') === 'battery' && ($byDesc['SigenStor BAT 10.0 (9 kWh)']['qty'] ?? 0) === 2, 'Fortnox-raden för batteriet är batteriartikeln × antal');
sg_assert(($byDesc['Schablon']['price'] ?? null) === 4077 && array_key_exists('hw', $byDesc['Schablon']) && $byDesc['Schablon']['hw'] === null, 'Sigen behåller schablonen 4 077');
sg_assert(!isset($byDesc['Sigen bottenplatta TP2']) && !isset($byDesc['TP2 tillägg material och arbete']), 'bottenplatta och tillägg ersätter inte schablonraden');
foreach ($newRows as $row) {
    sg_assert(!in_array((string) ($row['article'] ?? ''), ['11010186', '11130012', '11170033', '11010017'], true), 'leverantörsnummer skickas inte som Fortnox-artikel');
}

$closed = sg_purchase('tp2', '12.0', 2, ['closed_at' => '2026-01-01 00:00:00', 'allt_klart' => true]);
$closedRows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $closed);
sg_assert($closedRows[0]['desc'] === 'Batteri' && $closedRows[0]['price'] === 131845 && $closedRows[1]['price'] === 4077, 'stängd order räknas inte om till Sigen-rader');
$closedInput = jeab_offert_input_from_purchase($closed, 'batteri');
sg_assert((int) $closedInput['c27'] === 26362 && (int) $closedInput['tp2_unit'] === 9580, 'stängd order behåller de gamla inköpspriserna');

$kept = jeab_sigen_apply_inkop([
    'kalkyl' => ['sigen_typ' => 'ec', 'sigen_kw' => '12.0', 'b3' => 1],
    'inkop_lines' => [['sku' => 'KEEP', 'title' => 'Egen rad', 'qty' => 1]],
]);
sg_assert(($kept['inkop_lines'][0]['sku'] ?? '') === 'KEEP', 'sparad inköpslista skrivs inte över');

$filled = jeab_sigen_apply_inkop([
    'kalkyl' => ['sigen_typ' => 'ec', 'sigen_kw' => '20.0', 'b3' => 2],
    'inkop_lines' => [],
]);
sg_assert(($filled['inkop_source'] ?? '') === 'sigen', 'tom inköpslista märks som Sigen');
sg_assert(($filled['inkop_lines'][0]['sku'] ?? '') === '11010017' && (int) ($filled['inkop_lines'][0]['qty'] ?? 0) === 1, 'inköpet börjar med EC 20');
sg_assert(($filled['inkop_lines'][1]['sku'] ?? '') === '11130012' && (int) ($filled['inkop_lines'][1]['qty'] ?? 0) === 2, 'inköpet har batteri × 2');
sg_assert(($filled['inkop_lines'][2]['sku'] ?? '') === '11170033' && (int) ($filled['inkop_lines'][2]['qty'] ?? 0) === 1, 'inköpet har bottenplattan');
sg_assert(count($filled['inkop_lines']) === 3, 'EC-inköpet har ingen tilläggsrad');

$frozenLines = jeab_sigen_apply_inkop([
    'closed_at' => '2026-02-01 00:00:00',
    'kalkyl' => ['sigen_typ' => 'ec', 'sigen_kw' => '20.0', 'b3' => 2],
    'inkop_lines' => [],
]);
sg_assert($frozenLines['inkop_lines'] === [], 'stängd order får ingen ny inköpslista');

$missing = jeab_sigen_missing_articles();
sg_assert(in_array('NEO batteri (SigenStor Neo BAT 8.0)', $missing, true), 'saknat artikelnummer för Neo BAT 8.0 listas');
sg_assert(in_array('TP2 bottenplatta 3000 kr', $missing, true), 'saknat artikelnummer för TP2-bottenplattan listas');
sg_assert(in_array('TP2 tillägg material och arbete', $missing, true), 'saknat artikelnummer för TP2-tillägget listas');
$missingText = implode("\n", $missing);
sg_assert(!str_contains($missingText, '11010017') && !str_contains($missingText, '11010186') && !str_contains($missingText, '11130012') && !str_contains($missingText, '11170033'), 'kända artikelnummer listas inte som saknade');

$neoHtml = jeab_ordrar_v3_sigen_kalkyl_html([
    'kind' => 'batteri',
    'pris' => 70000,
    'kalkyl' => ['sigen_typ' => 'neo', 'sigen_kw' => '10.0', 'b3' => 2, 'sokande' => 2],
]);
sg_assert(substr_count($neoHtml, 'name="sigen_typ"') === 3, 'ett val för typ: NEO, TP2, EC');
sg_assert(substr_count($neoHtml, 'name="b3"') === 1, 'antalet batterier är en stegare');
sg_assert(!str_contains($neoHtml, 'name="b14"') && !str_contains($neoHtml, 'name="b15"'), 'inga extra paneler eller TP2-kryssruta');
sg_assert(str_contains($neoHtml, 'Batterimoduler') && str_contains($neoHtml, 'SigenStor Neo BAT 8.0 × 2') && str_contains($neoHtml, '19 034 kr'), 'NEO visar BAT 8.0 och 19 034 kr');
sg_assert(str_contains($neoHtml, 'Bottenplatta 1 300 kr') && str_contains($neoHtml, '17 424 kr'), 'NEO visar bottenplatta och 10.0-växelriktaren');
preg_match_all('/<input type="radio" name="sigen_kw" value="([^"]+)"([^>]*)>/', $neoHtml, $neoSizes, PREG_SET_ORDER);
$neoEnabled = [];
foreach ($neoSizes as $hit) {
    if (!str_contains($hit[2], 'disabled')) {
        $neoEnabled[] = $hit[1];
    }
}
sg_assert($neoEnabled === ['8.0', '10.0', '12.0', '15.0'], 'NEO visar bara sina storlekar');

$tp2Html = jeab_ordrar_v3_sigen_kalkyl_html([
    'kind' => 'batteri',
    'pris' => 70000,
    'kalkyl' => ['sigen_typ' => 'tp2', 'sigen_kw' => '12.0', 'b3' => 3, 'sokande' => 1],
]);
preg_match_all('/<input type="radio" name="sigen_kw" value="([^"]+)"([^>]*)>/', $tp2Html, $tp2Sizes, PREG_SET_ORDER);
$tp2Enabled = [];
foreach ($tp2Sizes as $hit) {
    if (!str_contains($hit[2], 'disabled')) {
        $tp2Enabled[] = $hit[1];
    }
}
sg_assert($tp2Enabled === ['12.0'], 'TP2 visar bara 12.0');
sg_assert(str_contains($tp2Html, 'TP2 tillägg material och arbete 1 500 kr') && str_contains($tp2Html, '× 3'), 'TP2 visar tillägget och antalet');

$store = new JeabJsonStore($tmp . '/store.json');
$reminder = jeab_create_reminder($store, 'Sigen Testsson', null, 'Order', null, [
    'customer' => 'Sigen Testsson',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1',
    'notes' => 'Order',
]);
$open = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'customer' => 'Sigen Testsson',
    'huvudsokande' => 'Sigen Testsson',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 1:1',
    'pris' => 70000,
    'kundpris' => 70000,
    'inkop_lines' => [['sku' => 'KEEP', 'title' => 'Redan sparad', 'qty' => 2]],
    'kalkyl' => ['b14' => 0, 'b3' => 1, 'sokande' => 2, 'b15' => 1, 'laddare' => 0],
]);
$saved = jeab_ordrar_save_questions([
    'id' => (int) $open['reminder_id'],
    'kind' => 'batteri',
    'pris' => '70 000',
    'kalkyl_present' => '1',
    'sigen_typ' => 'ec',
    'sigen_kw' => '15.0',
    'b3' => '2',
    'sokande' => '2',
]);
sg_assert(($saved['kalkyl']['sigen_typ'] ?? '') === 'ec' && ($saved['kalkyl']['sigen_kw'] ?? '') === '15.0' && (int) ($saved['kalkyl']['b3'] ?? 0) === 2, 'valet sparas på öppen order');
sg_assert(($saved['inkop_lines'][0]['sku'] ?? '') === 'KEEP', 'spara med befintliga rader rör inte inköpet');

$threw = '';
try {
    jeab_ordrar_kalkyl_from_input([
        'kalkyl_present' => '1',
        'sigen_typ' => 'tp2',
        'sigen_kw' => '15.0',
        'b3' => '1',
        'sokande' => '1',
    ]);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
sg_assert($threw !== '', 'fel storlek för typen stoppas');

$closedOrder = $saved;
$closedOrder['closed_at'] = '2026-03-01 12:00:00';
$closedOrder['allt_klart'] = true;
$closedOrder['kalkyl'] = ['b14' => 0, 'b3' => 1, 'sokande' => 2, 'b15' => 1, 'laddare' => 0];
$closedOrder = jeab_purchase_put($closedOrder);
$afterClose = jeab_ordrar_save_questions([
    'id' => (int) $closedOrder['reminder_id'],
    'kind' => 'batteri',
    'pris' => '70 000',
    'kalkyl_present' => '1',
    'sigen_typ' => 'neo',
    'sigen_kw' => '8.0',
    'b3' => '5',
    'sokande' => '2',
]);
sg_assert(!isset($afterClose['kalkyl']['sigen_typ']) && (int) ($afterClose['kalkyl']['b3'] ?? 0) === 1, 'stängd order behåller den sparade kalkylen');

echo "sigen kalkyl: ok\n";
