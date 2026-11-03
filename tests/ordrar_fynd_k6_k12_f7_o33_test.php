<?php

declare(strict_types=1);

/**
 * K6/K2/K12/F7/F9/O33: handredigerade fakturarader, stale-spar, låst faktura,
 * kWh-validering, ingen tyst växelriktare, Klar·Ångra. JSON-lager, ingen MariaDB.
 * Påhittade testdata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-fynd-k6-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
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
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function fy_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fy_states(array $row): array
{
    $out = [];
    foreach (jeab_ordrar_v3_steps($row) as $step) {
        $out[$step['id']] = $step;
    }
    return $out;
}

function fy_line(string $desc, string $price, string $rid = '', string $art = 'BAT', string $qty = '1'): array
{
    return [
        'row_id' => $rid,
        'article' => $art,
        'description' => $desc,
        'housework' => '',
        'qty' => $qty,
        'price' => $price,
    ];
}

function fy_empty(): array
{
    return ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''];
}

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/ordrar-v3.js');
$css = (string) file_get_contents($root . '/assets/css/ordrar-v3.css');
$v3src = (string) file_get_contents($root . '/src/ordrar_v3.php');
$fxsrc = (string) file_get_contents($root . '/src/ordrar_fortnox.php');
$index = (string) file_get_contents($root . '/index.php');
$ordrar = (string) file_get_contents($root . '/src/ordrar.php');

// —— K6: räkna bara om rader som inte är handredigerade ——
$posted = [
    fy_line('Batteri', '100000', '1'),
    fy_line('Schablon', '1', '2', 'SCH'),
    fy_empty(),
    fy_empty(),
];
$formula = [
    fy_line('Batteri', '90000', '', 'BAT'),
    fy_line('Schablon', '5000', '', 'SCH'),
    fy_empty(),
    fy_empty(),
];
$keptAll = jeab_ordrar_fortnox_keep_manual_editor_lines($posted, $formula, [true, true, false, false], []);
fy_ok(($keptAll['lines'][0]['price'] ?? '') === '100000' && ($keptAll['lines'][1]['price'] ?? '') === '1', 'K6: båda handredigerade rader lämnas');
fy_ok($keptAll['manual'] === [0, 1], 'K6: båda indexen sparas som manuella');

$keptSchablon = jeab_ordrar_fortnox_keep_manual_editor_lines($posted, $formula, [false, true, false, false], []);
fy_ok(($keptSchablon['lines'][0]['price'] ?? '') === '90000', 'K6: orörd batterirad räknas om');
fy_ok(($keptSchablon['lines'][0]['row_id'] ?? '') === '1', 'K6: omräknad rad behåller Fortnox-rad-id');
fy_ok(($keptSchablon['lines'][1]['price'] ?? '') === '1', 'K6: handredigerad schablon lämnas');
fy_ok($keptSchablon['manual'] === [1], 'K6: bara schablon räknas som manuell');

$fromSaved = jeab_ordrar_fortnox_keep_manual_editor_lines($posted, $formula, [false, false, false, false], [1]);
fy_ok(($fromSaved['lines'][1]['price'] ?? '') === '1' && ($fromSaved['lines'][0]['price'] ?? '') === '90000', 'K6: tidigare manuell schablon lämnas utan ny touch');

$blankKeep = jeab_ordrar_fortnox_keep_manual_editor_lines(
    [fy_empty(), fy_empty(), fy_empty(), fy_empty()],
    $formula,
    [true, false, false, false],
    []
);
fy_ok(($blankKeep['lines'][0]['price'] ?? '') === '90000' && $blankKeep['manual'] === [], 'K6: tom handredigerad rad räknas om');

$flags = jeab_ordrar_fortnox_touched_flags(['fx_touched' => ['1', '0', 'false', 'yes']]);
fy_ok($flags === [true, false, false, true], 'K6: fx_touched tolkar 1/0/false/yes');
fy_ok(jeab_ordrar_fortnox_touched_flags([]) === [false, false, false, false], 'K6: saknad fx_touched är orörd');
fy_ok(jeab_ordrar_fortnox_manual_indexes(['fortnox_manual_rows' => [0, 2, 9, -1, '1']]) === [0, 2, 1], 'K6: manuella index 0–3');

$change = jeab_ordrar_fortnox_editor_change_text($posted, $formula);
fy_ok(str_contains($change, 'Batteri 100000 → 90000') && str_contains($change, 'Schablon 1 → 5000'), 'K6: ändringstext visar gammalt → nytt pris');

fy_ok(str_contains($fxsrc, 'jeab_ordrar_fortnox_keep_manual_editor_lines')
    && str_contains($v3src, 'name="fx_touched[]"'), 'K6: Spara använder keep-manual och skickar fx_touched');
fy_ok(str_contains((string) file_get_contents($root . '/src/purchase.php'), "'fortnox_manual_rows'"), 'K6: manuella rader ligger kvar på ordern');

$store = new JeabJsonStore($tmp . '/store.json');
$reminder = jeab_create_reminder($store, 'Testkund Fynd K6', null, 'Order', null, [
    'customer' => 'Testkund Fynd K6',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'address' => 'Testgatan 9',
    'notes' => 'batteri',
]);
$rid = (int) $reminder['id'];
$marked = jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'customer' => 'Testkund Fynd K6',
    'address' => 'Testgatan 9',
    'huvudsokande' => 'Testkund Fynd K6',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 9:9',
    'pris' => 89000,
    'fortnox_order' => '9101',
    'fortnox_manual_rows' => [1],
    'kwh' => '10',
    'vaxelriktare' => 'Testväxel 8 kW',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999123456789012',
    'steps' => [],
]);
$readBack = jeab_purchase_get($rid);
fy_ok(is_array($readBack) && ($readBack['fortnox_manual_rows'] ?? null) === [1], 'K6: fortnox_manual_rows sparas på ordern');

// —— K2/U14: spara rader och visa vad som ändrats, skriv inte över osparat ——
fy_ok(str_contains($v3src, 'Ändrat. Orörda rader räknas om. Handredigerade rader lämnas.')
    && str_contains($v3src, 'Orörda rader räknas om och sparas. Handredigerade rader lämnas.'), 'K2: stale/noten tvingar inte Spara');
fy_ok(!str_contains($v3src, 'Spara krävs') && !str_contains($js, 'Spara krävs'), 'K2: ingen text om att Spara krävs');
fy_ok(str_contains($js, "t.id === 'v3-faktura-att-betala'")
    && str_contains($js, 'payStale.hidden = false')
    && !str_contains($js, "querySelector('[data-v3-sec=\"faktura\"]')"), 'K2: Att betala visar osparat och sparar inte av sig själv');
fy_ok(str_contains($js, "flag.value = '1'") && str_contains($js, 'data-v3-fx-touched'), 'K2: radredigering märks som handredigerad');
fy_ok(str_contains($index, '_JEAB_FAKTURA_CHANGES') && str_contains($index, "'Sparad. '"), 'K2: flash visar vad som ändrats');

$fakturaHtml = jeab_ordrar_v3_faktura_html($marked, 'jarfalla', $rid, [
    'id' => 'faktura',
    'state' => 'todo',
    'n' => 4,
    'title' => 'Fakturering',
    'short' => 'Faktura',
    'reason' => '',
]);
fy_ok(str_contains($fakturaHtml, 'data-v3-stale') && str_contains($fakturaHtml, 'name="fx_touched[]"'), 'K2: fakturaformuläret har stale och fx_touched');

// —— K12: fakturasteget låst tills föregående steg är klara ——
$bekraftad = [
    'kind' => 'batteri',
    'fortnox_order' => '9101',
    'booking_id' => 0,
    'steps' => [],
];
$st = fy_states($bekraftad);
fy_ok(($st['faktura']['state'] ?? '') === 'locked' && ($st['faktura']['reason'] ?? '') === 'Kräver att Föranmälan är klar', 'K12: efter Bekräfta är Fakturering låst tills Föranmälan');
fy_ok(($st['bestall']['state'] ?? '') === 'locked', 'K12: Beställ är låst tills Föranmälan');

$foranKlar = $bekraftad;
$foranKlar['foranmalan_status'] = 'A';
$foranKlar['foranmalan_case_status'] = 'skickad';
$foranKlar['steps'] = ['foranmald' => '2026-10-05', 'spec' => '2026-10-05'];
$st = fy_states($foranKlar);
fy_ok(($st['faktura']['state'] ?? '') === 'locked' && ($st['faktura']['reason'] ?? '') === 'Kräver att Beställ är klar', 'K12: efter Föranmälan är Fakturering låst tills Beställ');
fy_ok(($st['bestall']['state'] ?? '') === 'todo', 'K12: Beställ är att göra när Föranmälan är klar');

$bestallKlar = $foranKlar;
$bestallKlar['steps']['bestalld'] = '2026-10-09';
$st = fy_states($bestallKlar);
fy_ok(($st['faktura']['state'] ?? '') === 'todo' && ($st['faktura']['reason'] ?? '') === '', 'K12: Fakturering öppnas när Beställ är klar');

$ladd = ['kind' => 'laddbox', 'fortnox_order' => '9102', 'steps' => []];
$st = fy_states($ladd);
fy_ok(($st['faktura']['state'] ?? '') === 'todo' && !isset($st['foranmalan']), 'K12: laddbox öppnar Fakturering efter Bekräfta');

$lockedHtml = jeab_ordrar_v3_html($bekraftad + ['reminder_id' => $rid, 'company' => 'jarfalla'], 'jarfalla', $rid, $store);
fy_ok(str_contains($lockedHtml, 'id="steg-faktura"') && str_contains($lockedHtml, 'is-locked') && str_contains($lockedHtml, 'Kräver att Föranmälan är klar'), 'K12: vyn visar låst Fakturering med skäl');
fy_ok(!str_contains($lockedHtml, 'data-v3-primary="faktura"'), 'K12: låst Fakturering har ingen grön knapp');

// —— F7: kWh decimaltal > 0, komma eller punkt ——
fy_ok(jeab_ordrar_kwh_amount('10') === 10.0, 'F7: heltal 10');
fy_ok(jeab_ordrar_kwh_amount('10,5') === 10.5, 'F7: komma 10,5');
fy_ok(jeab_ordrar_kwh_amount('10.5') === 10.5, 'F7: punkt 10.5');
fy_ok(jeab_ordrar_kwh_amount(' 10,50 ') === 10.5, 'F7: mellanslag trimmas');
fy_ok(jeab_ordrar_kwh_amount('') === null, 'F7: tom är ogiltig');
fy_ok(jeab_ordrar_kwh_amount('0') === null, 'F7: 0 är ogiltig');
fy_ok(jeab_ordrar_kwh_amount('abc') === null, 'F7: text är ogiltig');
fy_ok(jeab_ordrar_kwh_amount('-1') === null, 'F7: negativ är ogiltig');

$fields = jeab_ordrar_v3_foranmalan_fields(['kwh' => '', 'vaxelriktare' => '', 'natbolag' => 'E.ON', 'anlaggnings_id' => '']);
fy_ok(str_contains($fields, 'name="kwh"') && str_contains($fields, 'inputmode="decimal"')
    && str_contains($fields, 'pattern="[0-9]+([.,][0-9]+)?"'), 'F7: kWh-fältet har decimal-tangentbord och mönster');
fy_ok(str_contains($js, "parseFloat(kwhVal) > 0") && str_contains($js, '/^\\d+(\\.\\d+)?$/'), 'F7: klienten kräver decimaltal > 0');

$badKwh = '';
try {
    jeab_ordrar_save_batterisol([
        'id' => $rid,
        'kwh' => 'abc',
        'vaxelriktare' => 'Testväxel 8 kW',
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999123456789012',
        'foranmalan_status' => 'B',
    ]);
} catch (InvalidArgumentException $e) {
    $badKwh = $e->getMessage();
}
fy_ok(str_contains($badKwh, 'kWh ska vara ett tal större än 0'), 'F7: serverside stoppar ogiltigt kWh, fick ' . $badKwh);

$comma = jeab_ordrar_save_batterisol([
    'id' => $rid,
    'kwh' => '9,5',
    'vaxelriktare' => 'Testväxel 8 kW',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999123456789012',
    'foranmalan_status' => 'B',
]);
fy_ok((string) ($comma['kwh'] ?? '') === '9,5', 'F7: komma-kWh sparas');

$missing = function_exists('jeab_ordrar_bokmalen_missing')
    ? jeab_ordrar_bokmalen_missing(['kind' => 'batteri', 'kwh' => '0', 'natbolag' => 'E.ON', 'anlaggnings_id' => '735999123456789012'], 'ordrar.foranmalan')
    : [];
fy_ok(in_array('kWh', $missing, true), 'F7: Bokmalen räknar 0 kWh som saknad');

// —— F9: ingen tyst default, kräv aktivt val ——
fy_ok(!str_contains($v3src, "\$vaxel = 'Sigenergy") && str_contains($v3src, 'placeholder="Välj växelriktare"'), 'F9: v3 defaultar inte växelriktare');
fy_ok(str_contains($fields, 'placeholder="Välj växelriktare"') && str_contains($fields, 'name="vaxelriktare" value=""'), 'F9: tom växelriktare lämnas tom');
fy_ok(str_contains($js, "m.push('växelriktare')"), 'F9: klienten kräver växelriktare');

$keptVaxel = jeab_ordrar_save_batterisol([
    'id' => $rid,
    'kwh' => '9,5',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999123456789012',
    'foranmalan_status' => 'B',
]);
fy_ok((string) ($keptVaxel['vaxelriktare'] ?? '') === 'Testväxel 8 kW', 'F9: spar utan växelriktare-fält lämnar valt värde');

$cleared = jeab_ordrar_save_batterisol([
    'id' => $rid,
    'kwh' => '9,5',
    'vaxelriktare' => '',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999123456789012',
    'foranmalan_status' => 'B',
]);
fy_ok((string) ($cleared['vaxelriktare'] ?? '') === '', 'F9: tomt val sparas tomt, ingen tyst default');

$needChoice = '';
try {
    jeab_ordrar_save_batterisol([
        'id' => $rid,
        'intent' => 'next',
        'kwh' => '9,5',
        'vaxelriktare' => '',
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999123456789012',
        'foranmalan_status' => 'A',
    ]);
} catch (InvalidArgumentException $e) {
    $needChoice = $e->getMessage();
}
fy_ok(str_contains($needChoice, 'Välj växelriktare'), 'F9: skick kräver aktivt val, fick ' . $needChoice);

$needKwh = '';
try {
    jeab_ordrar_save_batterisol([
        'id' => $rid,
        'intent' => 'next',
        'kwh' => '',
        'vaxelriktare' => 'Testväxel 8 kW',
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999123456789012',
        'foranmalan_status' => 'A',
    ]);
} catch (InvalidArgumentException $e) {
    $needKwh = $e->getMessage();
}
fy_ok(str_contains($needKwh, 'kWh ska vara ett tal större än 0'), 'F9/F7: skick utan kWh stoppas, fick ' . $needKwh);

// —— O33: toast med Ångra innan omladdning ——
fy_ok(str_contains($js, 'function toastUndo') && str_contains($js, "btn.textContent = 'Ångra'"), 'O33: toastUndo med Ångra finns');
fy_ok(str_contains($js, 'ms = ms || 5000') && str_contains($css, '.v3-toast-undo') && str_contains($css, 'pointer-events: auto'), 'O33: några sekunder, klickbar Ångra');
fy_ok(str_contains($js, 'toastUndo(flash.replace') && str_contains($js, "post(form, { done: '0', v3: '1' })"), 'O33: Ångra återställer via done=0');
fy_ok(str_contains($js, 'reloadTo') && str_contains($ordrar, "(string) \$input['done'] === '0'"), 'O33: timeout laddar om, servern tar done=0');
fy_ok(str_contains($index, "'Öppen igen.'"), 'O33: ångra-flash är Öppen igen');

$withInvoice = jeab_purchase_put((jeab_purchase_get($rid) ?: []) + [
    'fortnox_invoice' => 'INV-9101',
    'allt_klart' => false,
    'closed_at' => '',
]);
$closed = jeab_ordrar_stang(['id' => $rid, 'v3' => '1']);
fy_ok(!empty($closed['allt_klart']), 'O33: Klar stänger ärendet');
$reopened = jeab_ordrar_stang(['id' => $rid, 'done' => '0', 'v3' => '1']);
fy_ok(empty($reopened['allt_klart']) && trim((string) ($reopened['closed_at'] ?? '')) === '', 'O33: done=0 öppnar ärendet igen');

echo "ALL OK\n";
