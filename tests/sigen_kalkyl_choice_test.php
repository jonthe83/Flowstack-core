<?php

declare(strict_types=1);

/**
 * Kalkylens systemval: NEO, TP2 och EC i samma kontrollrad.
 * Priserna kommer från config/sigen_priser.php via jeab_sigen_apply_prices,
 * och JS-räknaren ger samma totaler.
 */

$tmp = sys_get_temp_dir() . '/jeab-sigen-choice-' . bin2hex(random_bytes(4));
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

function sc_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sc_calc(string $type, string $kw, int $qty, float $panels = 12.0): array
{
    return jeab_offert_calc([
        'sigen_typ' => $type,
        'sigen_kw' => $kw,
        'b3' => $qty,
        'b14' => $panels,
        'sokande' => 2,
        'laddare' => 0,
        'g27' => 70000,
    ]);
}

$neo = sc_calc('neo', '10', 2, 12);
sc_assert((int) round($neo['g27_cost']) === 17424 + (19034 * 2) + 1300, 'NEO 10 kW med 2 batterier: växelriktare + Neo BAT 8.0 × 2 + bottenplatta');
sc_assert((int) round($neo['b15']) === 0 && (int) round($neo['c27']) === 19034 && (int) round($neo['c4']) === 1300, 'NEO använder Neo BAT 8.0 och bottenplatta 1 300');
sc_assert((int) round($neo['not_tp2_unit']) === 17424 && (int) round($neo['sigen_surcharge']) === 0, 'NEO 10 kW sätter växelriktarpriset utan TP2-tillägg');
sc_assert((int) round($neo['b14']) === 12 && (int) round($neo['b3']) === 2, 'panelerna och antalet batterier står kvar');
sc_assert($neo['sigen_typ'] === 'neo' && $neo['sigen_kw'] === '10.0', 'valet normaliseras till neo 10.0');

$ec = sc_calc('ec', '15', 2, 16);
sc_assert((int) round($ec['g27_cost']) === 25271 + (27321 * 2) + 1300, 'EC 15 kW med 2 batterier: växelriktare + BAT 10.0 × 2 + bottenplatta');
sc_assert((int) round($ec['c27']) === 27321 && (int) round($ec['not_tp2_unit']) === 25271 && (int) round($ec['b15']) === 0, 'EC använder BAT 10.0 och sitt växelriktarpris');
sc_assert((int) round($ec['b14']) === 16, 'EC-kalkylen nollar inte panelerna');

$tp2 = sc_calc('tp2', '12', 2, 12);
sc_assert((int) round($tp2['g27_cost']) === 12403 + (27321 * 2) + 3000 + 1500, 'TP2 ger samma inköp som tidigare: växelriktare + batteri × 2 + bottenplatta + tillägg');
sc_assert((int) round($tp2['b15']) === 1 && (int) round($tp2['tp2_unit']) === 12403 && (int) round($tp2['c4']) === 3000, 'TP2 12 kW är fortfarande förvalt läge');

$plain = jeab_offert_calc(['b14' => 12, 'b3' => 2, 'b15' => 1, 'sokande' => 2, 'g27' => 70000]);
sc_assert((int) round($plain['g27_cost']) !== (int) round($tp2['g27_cost']), 'utan systemval används inte Sigen-prislistan');

$fromOrder = jeab_offert_kalkyl_input([
    'kind' => 'batteri',
    'kundpris' => 70000,
    'kalkyl' => ['sigen_typ' => 'neo', 'sigen_kw' => '10.0', 'b3' => 2, 'b14' => 0, 'sokande' => 2, 'b15' => 0, 'laddare' => 0],
], 'batteri');
$fromCalc = jeab_offert_calc($fromOrder);
sc_assert($fromCalc['sigen_typ'] === 'neo' && $fromCalc['sigen_kw'] === '10.0', 'sparat val från påminnelse/order öppnas som NEO 10 kW');
sc_assert((int) round($fromCalc['g27_cost']) === (int) round($neo['g27_cost']), 'orderns NEO-val ger samma inköp som kalkylen');

$default = jeab_offert_calc(jeab_offert_kalkyl_input(['kind' => 'sol', 'kalkyl' => ['b14' => 16, 'b3' => 2, 'sokande' => 2, 'b15' => 1, 'laddare' => 0]], 'sol'));
sc_assert($default['sigen_typ'] === 'tp2' && $default['sigen_kw'] === '12.0' && (int) round($default['b14']) === 16, 'kalkyl utan systemval blir TP2 12 kW och behåller panelerna');

$html = jeab_offert_daily_controls_html($default);
sc_assert(substr_count($html, 'name="sigen_typ"') === 3, 'ett systemval: NEO, TP2 och EC');
sc_assert(str_contains($html, 'value="neo"') && str_contains($html, 'value="tp2"') && str_contains($html, 'value="ec"'), 'systemknapparna är NEO, TP2 och EC');
sc_assert(str_contains($html, 'Batterimoduler') && substr_count($html, 'name="b3"') === 1, 'batterimoduler är kvar som antal');
sc_assert(!preg_match('/<input[^>]*type="checkbox"[^>]*name="b15"/', $html), 'kryssrutan 12 kW TP2 är borta');
sc_assert(preg_match('/name="sigen_typ" value="tp2"[^>]*checked/', $html) === 1, 'TP2 är förvalt');
sc_assert(str_contains($html, 'data-sigen-sizes') && preg_match('/data-sigen-sizes[^>]*hidden/', $html) === 1, 'TP2 visar ingen extra storleksrad');
sc_assert(substr_count($html, 'name="sigen_kw"') === 1 && str_contains($html, 'name="sigen_kw" value="12.0"'), 'TP2 har bara sin egen storlek i koden');
sc_assert(!str_contains($html, 'disabled') && !str_contains($html, 'value="8.0"') && !str_contains($html, 'value="20.0"'), 'TP2 har inga avstängda dubbletter från NEO eller EC');

$neoHtml = jeab_offert_daily_controls_html($neo);
sc_assert(preg_match('/data-sigen-sizes[^>]*hidden/', $neoHtml) !== 1, 'NEO visar storleken bredvid systemet');
sc_assert(preg_match('/name="sigen_kw" value="10.0"[^>]*checked/', $neoHtml) === 1, 'NEO 10 kW är valt');
sc_assert(substr_count($neoHtml, 'name="sigen_kw"') === 4, 'NEO visar bara sina fyra storlekar');
foreach (['8.0', '10.0', '12.0', '15.0'] as $kw) {
    sc_assert(str_contains($neoHtml, 'name="sigen_kw" value="' . $kw . '"'), 'NEO-storlek ' . $kw);
}
sc_assert(!str_contains($neoHtml, 'value="17.0"') && !str_contains($neoHtml, 'value="20.0"') && !str_contains($neoHtml, 'disabled'), 'NEO visar inte EC-storlekar, varken aktiva eller avstängda');

$ecHtml = jeab_offert_daily_controls_html($ec);
sc_assert(substr_count($ecHtml, 'name="sigen_kw"') === 4, 'EC visar bara sina fyra storlekar');
foreach (['12.0', '15.0', '17.0', '20.0'] as $kw) {
    sc_assert(str_contains($ecHtml, 'name="sigen_kw" value="' . $kw . '"'), 'EC-storlek ' . $kw);
}
sc_assert(!str_contains($ecHtml, 'value="8.0"') && !str_contains($ecHtml, 'value="10.0"'), 'EC visar inte NEO-storlekar');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
sc_assert(str_contains($css, '.offert-seg') && preg_match('/max-width:\s*719px[\s\S]*?\.offert-seg-opt span\s*\{[^}]*min-height:\s*44px/', $css) === 1, 'mobilen får hela bredden och 44 px höga knappar');
sc_assert(preg_match('/\.offert-sigen \[hidden\]\s*\{[^}]*display:\s*none\s*!important/', $css) === 1, 'dold storleksrad vinner över flex-layouten');

$live = ['b3' => 2, 'b14' => 16, 'sokande' => 2, 'laddare' => 1, 'g27' => 70000];
$liveOff = $live;
$liveOff['laddare'] = 0;
$withCharger = [
    'neo' => jeab_offert_calc($live + ['sigen_typ' => 'neo', 'sigen_kw' => '10']),
    'ec' => jeab_offert_calc($live + ['sigen_typ' => 'ec', 'sigen_kw' => '15']),
    'tp2' => jeab_offert_calc($live + ['sigen_typ' => 'tp2', 'sigen_kw' => '12']),
];
$withoutCharger = [
    'neo' => jeab_offert_calc($liveOff + ['sigen_typ' => 'neo', 'sigen_kw' => '10']),
    'ec' => jeab_offert_calc($liveOff + ['sigen_typ' => 'ec', 'sigen_kw' => '15']),
    'tp2' => jeab_offert_calc($liveOff + ['sigen_typ' => 'tp2', 'sigen_kw' => '12']),
];
foreach ($withCharger as $name => $calc) {
    $delta = (int) round($calc['i14']) - (int) round($withoutCharger[$name]['i14']);
    $vinstDelta = (int) round($calc['al20']) - (int) round($withoutCharger[$name]['al20']);
    sc_assert($delta > 0, $name . ' med elbilsladdare höjer att betala');
    sc_assert($vinstDelta === 3000, $name . ' får 3 000 kr mer i vinst av laddboxen, ' . $vinstDelta);
    sc_assert((int) round($calc['c14']) === 16 * 1558, $name . ' har samma panelmaterial för 16 paneler');
}
sc_assert((int) round($withCharger['neo']['i11']) !== (int) round($withCharger['ec']['i11']), 'solpaneler skiljer sig när växelriktarpriset ligger i soldelen');
sc_assert((int) round($withoutCharger['neo']['i14']) === 121800 && (int) round($withoutCharger['tp2']['i14']) === 128408 && (int) round($withoutCharger['ec']['i14']) === 140528, '16 paneler utan laddare följer vinsten');

$kept = jeab_ordrar_kalkyl_from_input([
    'kalkyl_present' => '1',
    'sigen_typ' => 'neo',
    'sigen_kw' => '10.0',
    'b14' => '16',
    'b3' => '2',
    'sokande' => '2',
    'b15' => '0',
]);
sc_assert(($kept['sigen_typ'] ?? '') === 'neo' && (int) ($kept['b14'] ?? -1) === 16 && (int) ($kept['b3'] ?? 0) === 2, 'solkalkylen sparar systemvalet utan att nollställa panelerna');

$cases = [
    'neo' => $neo,
    'ec' => $ec,
    'tp2' => $tp2,
    'neo-laddare' => $withCharger['neo'],
    'ec-laddare' => $withCharger['ec'],
    'tp2-laddare' => $withCharger['tp2'],
];
$payload = [
    'catalog' => jeab_sigen_client_catalog(),
    'cases' => [],
];
foreach ($cases as $name => $calc) {
    $payload['cases'][$name] = [
        'input' => [
            'sigen_typ' => $calc['sigen_typ'],
            'sigen_kw' => $calc['sigen_kw'],
            'b3' => $calc['b3'],
            'b14' => $calc['b14'],
            'b15' => $calc['b15'],
            'laddare' => (int) round((float) $calc['laddare']),
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
        ],
        'expect' => [
            'g27_cost' => round((float) $calc['g27_cost']),
            'i14' => round((float) $calc['i14']),
            'i15' => round((float) $calc['i15']),
            'h31' => round((float) $calc['h31']),
            'c24' => round((float) $calc['c24']),
        ],
    ];
}
$jsonPath = $tmp . '/cases.json';
file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE));
$jsPath = $tmp . '/match.js';
file_put_contents($jsPath, <<<'JS'
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
const chunk = src.slice(start, end);
const sandbox = { globalThis: {}, Intl, Number, Object, Math, String };
vm.createContext(sandbox);
vm.runInContext(chunk, sandbox);
const live = sandbox.globalThis.jeabOffertLive;
if (!live || typeof live.calc !== 'function' || typeof live.applySigenPrices !== 'function') {
  console.error('FAIL jeabOffertLive saknas');
  process.exit(1);
}
let failed = false;
for (const [name, row] of Object.entries(payload.cases)) {
  const input = Object.assign({}, row.input, { sigen_catalog: payload.catalog });
  const out = live.calc(input);
  const panels = live.applySigenPrices(input, payload.catalog);
  if (Number(panels.b14) !== Number(row.input.b14)) {
    console.error('FAIL ' + name + ' paneler ' + panels.b14);
    failed = true;
  }
  for (const key of Object.keys(row.expect)) {
    const got = Math.round(out[key]);
    const want = Math.round(row.expect[key]);
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
$node = '';
$code = 1;
exec(
    'node ' . escapeshellarg($jsPath) . ' ' . escapeshellarg($jsonPath) . ' ' . escapeshellarg(dirname(__DIR__) . '/assets/js/app.js') . ' 2>&1',
    $nodeLines,
    $code
);
$node = implode("\n", $nodeLines);
echo $node . "\n";
sc_assert($code === 0 && !str_contains($node, 'FAIL'), 'JS och PHP ger samma totaler');

echo "sigen kalkyl choice: ok\n";
