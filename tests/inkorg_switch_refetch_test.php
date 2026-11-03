<?php

declare(strict_types=1);

/**
 * Bolagsbyte hämtar lista och ix_part=counts för boxen i adressen.
 * Listans rubrik följer samma box och vy som raderna. Visa fler tar nästa 50.
 * Ingen kunddata.
 */

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);
$tmp = $argv[2] ?? '';
if ($mode === '' || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-ixrefetch-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/inkorg', 0775, true);
    mkdir($tmp . '/secrets', 0775, true);
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
        ],
    ], true) . ";\n");
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';

function rf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rf_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once dirname(__DIR__) . '/tests/sam_l_login.php';
    jeab_test_login_sam_l();
    $_SESSION['jeab_inkorg_box'] = 'jarfalla';
}

if ($mode === 'part') {
    require $root . '/src/layout.php';
    rf_session();
    $_GET = [
        'p' => 'inkorg',
        'box' => (string) ($argv[3] ?? 'jarfalla'),
        'co' => (string) ($argv[3] ?? 'jarfalla'),
        'view' => (string) ($argv[4] ?? 'v-inkorg'),
        'scope' => (string) ($argv[5] ?? 'egna'),
        'ix_part' => (string) ($argv[6] ?? 'list'),
    ];
    if ((string) ($argv[7] ?? '') !== '') {
        $_GET['sida'] = (string) $argv[7];
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
    echo include $root . '/src/pages/inkorg.php';
    exit(0);
}

require $root . '/src/layout.php';
rf_session();
$pageJs = (string) file_get_contents($root . '/src/pages/inkorg.php');
rf(str_contains($pageJs, "jeab_asset_url('assets/js/inkorg-nav.js')"), 'jeab_asset_url är kvar');
$switchAt = strpos($pageJs, "location.href = window.jeabInkorgCompanyHref ? window.jeabInkorgCompanyHref(a.href) : a.href;");
rf($switchAt !== false && !str_contains($pageJs, 'ixDropOtherCompany('), 'bolagsbyte är en sidladdning utan DOM-drop');
rf(str_contains($pageJs, 'a[data-ix-more]'), 'Visa fler fångas i klienten');

$node = getenv('NODE') ?: 'node';
$nodeFile = $tmp . '/switch-refetch.js';
file_put_contents($nodeFile, <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);
const plan = nav.jeabInkorgClickPlan({
  company: true,
  boxChanged: true,
  shownView: 'v-inkorg',
  nextView: 'v-inkorg',
  nextId: '',
  curId: ''
});
const loads = nav.jeabInkorgSwitchFetch(plan);
assert.strictEqual(plan.navigate, true);
assert.strictEqual(plan.fetch, false);
assert.strictEqual(loads.list, false);
assert.strictEqual(loads.counts, false);
assert.strictEqual(nav.jeabInkorgResponseLive('jarfalla', 'malmo', 'jarfalla'), false);
assert.strictEqual(nav.jeabInkorgResponseLive('malmo', 'malmo', 'malmo'), true);
const cache = {};
cache[nav.jeabInkorgCountCacheKey('jarfalla', 'egna')] = { box: 'jarfalla', counts: { 'v-inkorg': 16 }, unread: 1 };
cache[nav.jeabInkorgCountCacheKey('malmo', 'egna')] = { box: 'malmo', counts: { 'v-inkorg': 4 }, unread: 0 };
assert.strictEqual(nav.jeabInkorgCachedCounts(cache, 'malmo', 'egna').counts['v-inkorg'], 4);
assert.strictEqual(nav.jeabInkorgCachedCounts(cache, 'jarfalla', 'egna').counts['v-inkorg'], 16);
assert.strictEqual(nav.jeabInkorgCachedCounts(cache, 'malmo', 'allas'), null);
const stale = nav.jeabInkorgCountsDecision(
  { gen: 2, box: 'malmo', left: 3 },
  { gen: 2, locationBox: 'malmo', domBox: 'malmo' },
  { fresh: 0, counts: { 'v-inkorg': 4 }, box: 'malmo' }
);
assert.strictEqual(stale.paint, true);
assert.strictEqual(stale.reason, 'stale');
const other = nav.jeabInkorgCountsDecision(
  { gen: 1, box: 'jarfalla', left: 3 },
  { gen: 2, locationBox: 'malmo', domBox: 'malmo' },
  { fresh: 0, counts: { 'v-inkorg': 16 }, box: 'jarfalla' }
);
assert.strictEqual(other.paint, false);
assert.strictEqual(nav.jeabInkorgShownRange(50, 71), 'visar 50 av 71');
assert.strictEqual(nav.jeabInkorgShownRange(71, 71), '71');
const more = nav.jeabInkorgMorePlan(1, 2);
assert.strictEqual(more.more, true);
assert.strictEqual(more.sida, 2);
assert.strictEqual(nav.jeabInkorgMorePlan(2, 2).more, false);
const page = nav.jeabInkorgClickPlan({
  shownView: 'v-alla',
  nextView: 'v-alla',
  pageChanged: true,
  nextId: '',
  curId: ''
});
assert.strictEqual(page.fetch, true);
assert.strictEqual(page.reveal, false);
JS);
exec(escapeshellarg($node) . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/inkorg-nav.js') . ' 2>&1', $nodeOut, $nodeCode);
rf($nodeCode === 0, 'nav-planen hämtar lista och counts utan att blanda box ' . implode(' ', $nodeOut));

$pdo = jeab_pdo_connect_sqlite();
rf(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
rf($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$seed = [
    ['id' => 'roos', 'company' => 'jarfalla', 'source' => 'graph-jarfalla', 'to' => 'kontakt@jarfalla.example.test', 'name' => 'Familjen Roos', 'at' => '2024-06-02T10:00:00+00:00', 'assignee' => '', 'product' => 'batteri'],
    ['id' => 'annan', 'company' => 'jarfalla', 'source' => 'graph-jarfalla', 'to' => 'kontakt@jarfalla.example.test', 'name' => 'Annan Järfälla', 'at' => '2024-06-03T10:00:00+00:00', 'assignee' => '', 'product' => 'batteri'],
    ['id' => 'wendel', 'company' => 'malmo', 'source' => 'graph-malmo', 'to' => 'kontakt@malmo.example.test', 'name' => 'Sam Wendel', 'at' => '2024-06-04T10:00:00+00:00', 'assignee' => 'Roberto', 'product' => ''],
];
foreach ($seed as $row) {
    $store->upsertMessage($row['id'] . 'm', [
        'id' => $row['id'] . 'm',
        'thread_id' => $row['id'],
        'from' => $row['id'] . '@exempel.test',
        'from_name' => $row['name'],
        'to' => $row['to'],
        'subject' => $row['name'],
        'body' => $row['name'],
        'received_at' => $row['at'],
        'source' => $row['source'],
        'labels' => ['type' => 'Support'] + (($row['product'] ?? '') !== '' ? ['product' => $row['product']] : []),
    ]);
    $store->upsertThread($row['id'], [
        'status' => 'open',
        'tags' => [],
        'assignee' => $row['assignee'],
        'read' => [],
        'company' => $row['company'],
        'updated_at' => $row['at'],
    ]);
}

function rf_part(string $box, string $view, string $scope, string $part, string $sida = ''): array
{
    global $tmp;
    $cmd = [PHP_BINARY, __FILE__, 'part', $tmp, $box, $view, $scope, $part];
    if ($sida !== '') {
        $cmd[] = $sida;
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    rf(is_resource($proc), $part . ' ' . $box . ' startade');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    rf($code === 0, $part . ' ' . $box . ' avslutade rent ' . trim((string) $err));
    $jsonAt = strpos((string) $out, '{');
    $payload = json_decode($jsonAt === false ? '' : substr((string) $out, $jsonAt), true);
    rf(is_array($payload), $part . ' ' . $box . ' är JSON');

    return $payload;
}

$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'seed');
unset($GLOBALS['_JEAB_INKORG_PART']);

$jfList = rf_part('jarfalla', 'v-inkorg', 'allas', 'list');
$moList = rf_part('malmo', 'v-inkorg', 'allas', 'list');
$jfCounts = rf_part('jarfalla', 'v-inkorg', 'allas', 'counts');
$moCounts = rf_part('malmo', 'v-inkorg', 'allas', 'counts');
rf((string) ($jfList['box'] ?? '') === 'jarfalla' && str_contains((string) $jfList['html'], 'Familjen Roos') && str_contains((string) $jfList['html'], 'Annan Järfälla') && !str_contains((string) $jfList['html'], 'Sam Wendel'), 'Järfällas lista är bara Järfälla');
rf((string) ($moList['box'] ?? '') === 'malmo' && str_contains((string) $moList['html'], 'Sam Wendel') && !str_contains((string) $moList['html'], 'Familjen Roos'), 'Malmös lista är bara Malmö');
rf((string) ($jfList['range'] ?? '') === '2' && substr_count((string) $jfList['html'], 'class="item ix-it') === 2, 'Järfällas rubrik är samma antal som raderna');
rf((string) ($moList['range'] ?? '') === '1' && substr_count((string) $moList['html'], 'class="item ix-it') === 1, 'Malmös rubrik är samma antal som raderna');
rf((int) ($jfCounts['counts']['v-inkorg'] ?? -1) === 2 && (string) ($jfCounts['box'] ?? '') === 'jarfalla', 'ix_part=counts för Järfälla är 2');
rf((int) ($moCounts['counts']['v-inkorg'] ?? -1) === 1 && (string) ($moCounts['box'] ?? '') === 'malmo', 'ix_part=counts för Malmö är 1');
rf((int) ($jfCounts['fresh'] ?? 0) === 1 && (int) ($moCounts['fresh'] ?? 0) === 1, 'counts är färska per box');

for ($i = 1; $i <= 55; $i++) {
    $id = sprintf('alla%03d', $i);
    $at = sprintf('2024-07-%02dT10:00:00+00:00', (($i - 1) % 28) + 1);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => $id . '@exempel.test',
        'from_name' => 'Alla ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Alla ' . $i,
        'body' => 'Alla ' . $i,
        'received_at' => $at,
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => $at,
    ]);
}
$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'page');
unset($GLOBALS['_JEAB_INKORG_PART']);
$alla1 = rf_part('jarfalla', 'v-alla', 'allas', 'list', '1');
$alla2 = rf_part('jarfalla', 'v-alla', 'allas', 'list', '2');
$allaCounts = rf_part('jarfalla', 'v-alla', 'allas', 'counts');
$total = (int) ($alla1['total'] ?? 0);
rf($total >= 55, 'Alla har fler än en sida, fick ' . $total);
rf((int) ($allaCounts['counts']['v-alla'] ?? -1) === $total, 'räknaren för Alla är samma total som listan, fick ' . (string) ($allaCounts['counts']['v-alla'] ?? ''));
rf(substr_count((string) $alla1['html'], 'class="item ix-it') === 50, 'första sidan är 50 rader');
rf(str_contains((string) $alla1['html'], 'data-ix-more="1"') && str_contains((string) $alla1['html'], 'Visa fler'), 'första sidan har Visa fler');
rf((string) ($alla1['range'] ?? '') === 'visar 50 av ' . $total, 'rubriken säger 50 av ' . $total . ', fick ' . (string) ($alla1['range'] ?? ''));
$rest = substr_count((string) $alla2['html'], 'class="item ix-it');
rf($rest === $total - 50, 'Visa fler hämtar resten, fick ' . $rest);
rf(!str_contains((string) $alla2['html'], 'data-ix-id="roos"') || $rest > 0, 'sida 2 är en egen sida');

echo "OK  lista och counts per box\n";
