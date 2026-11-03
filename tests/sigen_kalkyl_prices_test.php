<?php

declare(strict_types=1);

/**
 * Detaljpanelens inköpspriser styr kalkylen och Sigen-raderna.
 * Gamla sparade värden utan de nya nycklarna räknas som idag.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-prices-' . bin2hex(random_bytes(4));
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

function sp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$labels = [];
foreach (jeab_sigen_price_fields() as $spec) {
    $labels[] = $spec['label'];
}
sp_assert($labels === [
    'Växelriktare NEO 8 kW',
    'Växelriktare NEO 10 kW',
    'Växelriktare NEO 12 kW',
    'Växelriktare NEO 15 kW',
    'Växelriktare EC 12 kW',
    'Växelriktare EC 15 kW',
    'Växelriktare EC 17 kW',
    'Växelriktare EC 20 kW',
    'Växelriktare TP2 12 kW',
    'Batteri Neo BAT 8.0 / st',
    'Batteri BAT 10.0 / st',
    'Bottenplatta NEO/EC',
    'Bottenplatta TP2',
    'TP2 tillägg',
], 'ett fält per växelriktare, batteri och TP2-tillägg');

$html = jeab_offert_sigen_price_fields_html(jeab_offert_load_standard()['offert']);
sp_assert(str_contains($html, 'Växelriktare TP2 12 kW') && str_contains($html, 'data-in="sigen_inv_tp2_12_0"'), 'TP2-växelriktaren är ett eget fält');
sp_assert(!str_contains($html, '12 kW ej TP2') && !str_contains($html, 'Batteripris / modul'), 'de gamla samlingsfälten är borta');
sp_assert(substr_count($html, 'data-in="sigen_bat_10"') === 1 && substr_count($html, 'data-in="sigen_inv_neo_10_0"') === 1, 'varje pris finns på ett ställe');
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/offert.php');
sp_assert(!str_contains($page, '12 kW ej TP2') && !str_contains($page, 'Batteripris / modul'), 'detaljpanelen använder inte de gamla fälten');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
sp_assert(preg_match('/@media \(max-width:\s*719px\)\s*\{\s*\.offert-month-box\s*\{\s*grid-template-columns:\s*1fr/', $css) === 1, 'fälten staplas på mobil');

$catalog = jeab_sigen_client_catalog();
$base = jeab_offert_calc([
    'sigen_typ' => 'neo',
    'sigen_kw' => '10',
    'b3' => 2,
    'b14' => 12,
    'sokande' => 2,
    'g27' => 70000,
]);
sp_assert((int) round($base['g27_cost']) === 17424 + (19034 * 2) + 1300, 'utan nya nycklar är NEO 10 kW samma som prislistan');

$changed = jeab_offert_calc([
    'sigen_typ' => 'neo',
    'sigen_kw' => '10',
    'b3' => 2,
    'b14' => 12,
    'sokande' => 2,
    'g27' => 70000,
    'sigen_inv_neo_10_0' => 20000,
    'sigen_bat_10' => 30000,
]);
sp_assert((int) round($changed['g27_cost']) === 20000 + (19034 * 2) + 1300, 'ändrat NEO 10 kW-pris styr kalkylen, inte BAT 10.0');
sp_assert((int) round($changed['not_tp2_unit']) === 20000 && (int) round($changed['c27']) === 19034, 'NEO använder sitt växelriktarfält och Neo BAT 8.0');

$tp2 = jeab_offert_calc([
    'sigen_typ' => 'tp2',
    'sigen_kw' => '12',
    'b3' => 2,
    'b14' => 12,
    'sokande' => 2,
    'g27' => 70000,
    'sigen_inv_neo_10_0' => 20000,
    'sigen_bat_10' => 30000,
]);
sp_assert((int) round($tp2['g27_cost']) === 12403 + (30000 * 2) + 3000 + 1500, 'ändrat BAT 10.0 styr TP2');
$ec = jeab_offert_calc([
    'sigen_typ' => 'ec',
    'sigen_kw' => '15',
    'b3' => 2,
    'b14' => 12,
    'sokande' => 2,
    'g27' => 70000,
    'sigen_bat_10' => 30000,
]);
sp_assert((int) round($ec['c27']) === 30000 && (int) round($ec['not_tp2_unit']) === 25271, 'EC delar BAT 10.0 och behåller sin växelriktare');

$oldFile = $tmp . '/offert-defaults.json';
file_put_contents($oldFile, json_encode(['offert' => ['hourly' => 750]], JSON_UNESCAPED_UNICODE));
$old = jeab_offert_calc(jeab_offert_input_from_purchase([
    'kind' => 'batteri',
    'kundpris' => 70000,
    'kalkyl' => ['sigen_typ' => 'neo', 'sigen_kw' => '10.0', 'b3' => 2, 'b14' => 0, 'sokande' => 2, 'b15' => 0, 'laddare' => 0],
], 'batteri'));
sp_assert((int) round($old['g27_cost']) === (int) round($base['g27_cost']), 'gammal standard utan nya nycklar ger samma NEO-total');
unlink($oldFile);

$store = new JeabJsonStore($tmp . '/store.json');
jeab_offert_save_standard($store, [
    'sigen_inv_neo_10_0' => '20 000',
    'sigen_bat_10' => '30 000',
]);
$saved = jeab_offert_load_standard($store);
sp_assert((int) $saved['offert']['sigen_inv_neo_10_0'] === 20000 && (int) $saved['offert']['sigen_bat_10'] === 30000, 'spara nya värden som standard tar med priserna');
sp_assert((int) jeab_sigen_resolve('neo', '10', 2)['inverter_unit'] === 20000, 'orderradens NEO-växelriktare läser det sparade priset');
sp_assert((int) jeab_sigen_resolve('tp2', '12', 2)['battery_unit'] === 30000, 'orderradens BAT 10.0 läser det sparade priset');
sp_assert((int) jeab_sigen_resolve('ec', '15', 1)['battery_unit'] === 30000, 'EC-raden använder samma BAT 10.0');
sp_assert((int) jeab_sigen_resolve('neo', '8', 1)['inverter_unit'] === 15972, 'orörd storlek ligger kvar på listpris');
$lines = jeab_sigen_lines(jeab_sigen_resolve('neo', '10', 2));
$byRole = [];
foreach ($lines as $line) {
    $byRole[$line['role']] = $line;
}
sp_assert((int) $byRole['inverter']['purchase'] === 20000 && (int) $byRole['battery']['purchase'] === 19034, 'inköpsraderna får samma sparade priser');

$cases = [
    'neo-changed' => $changed,
    'tp2-bat' => $tp2,
    'ec-bat' => $ec,
    'neo-base' => $base,
];
$payload = ['catalog' => $catalog, 'cases' => []];
foreach ($cases as $name => $calc) {
    $input = [
        'sigen_typ' => $calc['sigen_typ'],
        'sigen_kw' => $calc['sigen_kw'],
        'b3' => $calc['b3'],
        'b14' => $calc['b14'],
        'b15' => $calc['b15'],
        'laddare' => 0,
        'g27' => 70000,
        'c23' => null,
        'sokande' => 2,
        'b5' => 24,
        'c13' => 2000,
        'b17' => 16,
        'c28' => 1558,
        'laddare_unit' => 6793,
        'laddare_hours' => 8,
        'hourly' => 750,
    ];
    if ($name !== 'neo-base') {
        $input['sigen_inv_neo_10_0'] = 20000;
        $input['sigen_bat_10'] = 30000;
    }
    $payload['cases'][$name] = [
        'input' => $input,
        'expect' => [
            'g27_cost' => round((float) $calc['g27_cost']),
            'i14' => round((float) $calc['i14']),
        ],
    ];
}
file_put_contents($tmp . '/cases.json', json_encode($payload));
file_put_contents($tmp . '/match.js', <<<'JS'
const fs = require('fs');
const vm = require('vm');
const payload = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const src = fs.readFileSync(process.argv[3], 'utf8');
const start = src.indexOf('function sigenKwKey');
const end = src.indexOf('function jeabBootOffertDom', start);
if (start < 0 || end < 0) {
  console.error('FAIL kunde inte hitta kalkylens JS');
  process.exit(1);
}
const sandbox = { globalThis: {}, Intl, Number, Object, Math, String };
vm.createContext(sandbox);
vm.runInContext(src.slice(start, end), sandbox);
const live = sandbox.globalThis.jeabOffertLive;
let failed = false;
for (const [name, row] of Object.entries(payload.cases)) {
  const out = live.calc(Object.assign({}, row.input, { sigen_catalog: payload.catalog }));
  for (const key of Object.keys(row.expect)) {
    const got = Math.round(out[key]);
    const want = row.expect[key];
    if (got !== want) {
      console.error('FAIL ' + name + ' ' + key + ' js=' + got + ' php=' + want);
      failed = true;
    } else {
      console.log('OK  js ' + name + ' ' + key + ' ' + got);
    }
  }
}
process.exit(failed ? 1 : 0);
JS);
$code = 1;
$nodeLines = [];
exec('node ' . escapeshellarg($tmp . '/match.js') . ' ' . escapeshellarg($tmp . '/cases.json') . ' ' . escapeshellarg(dirname(__DIR__) . '/assets/js/app.js') . ' 2>&1', $nodeLines, $code);
echo implode("\n", $nodeLines) . "\n";
sp_assert($code === 0, 'JS och PHP ger samma totaler efter ändrade priser');

echo "sigen kalkyl prices: ok\n";
