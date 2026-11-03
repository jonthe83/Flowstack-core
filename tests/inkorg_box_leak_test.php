<?php

declare(strict_types=1);

/**
 * Bolagsbyte Järfälla → Malmö → Järfälla. Adressens box ritar lista, rubrik,
 * Help Scout-rad och räknare. Sessionen skrivs innan den släpps. Samma ärende
 * finns inte i båda bolagen. Ingen kunddata.
 */

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);
$tmp = $argv[2] ?? '';
if ($mode === '' || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-ixbox-' . bin2hex(random_bytes(4));
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

function bx(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bx_session(string $saved): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once dirname(__DIR__) . '/tests/sam_l_login.php';
    jeab_test_login_sam_l();
    $_SESSION['jeab_inkorg_box'] = $saved;
}

function bx_get(string $box): void
{
    $_GET = [
        'p' => 'inkorg',
        'box' => $box,
        'co' => $box,
        'view' => 'v-inkorg',
        'scope' => 'allas',
        'ix_part' => 'app',
    ];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

if ($mode === 'app') {
    require $root . '/src/layout.php';
    $saved = (string) ($argv[3] ?? 'malmo');
    $box = (string) ($argv[4] ?? 'jarfalla');
    bx_session($saved);
    bx_get($box);
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
    jeab_inkorg_box();
    file_put_contents($tmp . '/committed-box', (string) ($_SESSION['jeab_inkorg_box'] ?? ''));
    session_write_close();
    $GLOBALS['_JEAB_INKORG_PART'] = 'counts';
    jeab_inkorg_fast_counts_refresh();
    unset($GLOBALS['_JEAB_INKORG_PART']);
    echo include $root . '/src/pages/inkorg.php';
    exit(0);
}

require $root . '/src/layout.php';
bx_session('malmo');

$boot = (string) file_get_contents($root . '/src/bootstrap.php');
$enterAt = strpos($boot, 'function jeab_inkorg_part_enter');
$nextAt = strpos($boot, 'function jeab_is_https');
$enter = $enterAt !== false && $nextAt !== false ? substr($boot, $enterAt, $nextAt - $enterAt) : '';
$boxAt = strpos($enter, 'jeab_inkorg_box()');
$closeAt = strpos($enter, 'session_write_close()');
bx($boxAt !== false && $closeAt !== false && $boxAt < $closeAt, 'delanropet skriver bolaget innan sessionen släpps');

bx_get('jarfalla');
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
bx(jeab_inkorg_box() === 'jarfalla', 'adressen vinner över sessionens Malmö');
bx(($_SESSION['jeab_inkorg_box'] ?? '') === 'jarfalla', 'sessionen får jarfalla medan den är öppen');
session_write_close();
unset($_GET['box'], $_GET['co'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
bx(jeab_inkorg_box() === 'jarfalla', 'efter släppet ligger den senast valda boxen i sessionen');

$_SESSION['jeab_email'] = 'demo@example.test';
$_SESSION['jeab_inkorg_box'] = 'malmo';
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
$_GET = ['p' => 'inkorg'];
bx(jeab_inkorg_box() === 'malmo', 'utan box i adressen används den sparade mejlboxen');

$pdo = jeab_pdo_connect_sqlite();
bx(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
bx($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

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
$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'seed');
bx($store->listStamp() !== '', 'liststämpeln är satt');

$names = static function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = (string) ($row['first']['subject'] ?? $row['subject'] ?? '');
    }

    return $out;
};
$jarfallaNames = $names($store->listForCompany('jarfalla'));
$malmoNames = $names($store->listForCompany('malmo'));
bx(in_array('Familjen Roos', $jarfallaNames, true) && in_array('Annan Järfälla', $jarfallaNames, true), 'Järfällas lista har sina rader');
bx($malmoNames === ['Sam Wendel'], 'Malmös lista har bara Sam Wendel, fick ' . implode(', ', $malmoNames));
bx(!in_array('Familjen Roos', $malmoNames, true) && !in_array('Sam Wendel', $jarfallaNames, true), 'ingen rad ligger i båda bolagen');

$me = jeab_inkorg_me_id();
$menu = jeab_inkorg_fast_scope_menu('egna');
$jarfallaKey = jeab_inkorg_fast_count_key($menu, 'jarfalla', $me);
$malmoKey = jeab_inkorg_fast_count_key($menu, 'malmo', $me);
bx($jarfallaKey !== $malmoKey, 'räknarcachen har en nyckel per bolag');

function bx_child(string $saved, string $box): array
{
    global $tmp;
    $pipes = [];
    $proc = proc_open(
        [PHP_BINARY, __FILE__, 'app', $tmp, $saved, $box],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    bx(is_resource($proc), $box . ' startade');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    bx($code === 0, $box . ' avslutade rent ' . trim((string) $err));
    $jsonAt = strpos((string) $out, '{');
    $payload = json_decode($jsonAt === false ? '' : substr((string) $out, $jsonAt), true);
    bx(is_array($payload), $box . ' svarade med JSON');

    return $payload;
}

function bx_html(array $payload, string $box, string $eyebrow, bool $banner, array $want, array $forbid, string $count): void
{
    $html = (string) ($payload['html'] ?? '');
    bx((string) ($payload['box'] ?? '') === $box, $box . ' JSON-box är adressen, fick ' . (string) ($payload['box'] ?? ''));
    bx(str_contains($html, 'data-ix-box="' . $box . '"'), $box . ' data-ix-box följer adressen');
    bx(str_contains($html, 'apple-eyebrow">' . $eyebrow . '<'), $box . ' rubriken är ' . $eyebrow);
    $hasBanner = preg_match('/<p class="ink-muted" role="status">Sol hanteras i Help Scout\.<\/p>/', $html) === 1;
    bx($hasBanner === $banner, $box . ' Help Scout-raden ' . ($banner ? 'visas' : 'visas inte'));
    foreach ($want as $name) {
        bx(str_contains($html, $name), $box . ' listar ' . $name);
    }
    foreach ($forbid as $name) {
        bx(!str_contains($html, $name), $box . ' listar inte ' . $name);
    }
    bx(str_contains($html, 'data-ix-count="v-inkorg">' . $count . '<'), $box . ' räknaren är ' . $count . ' inte ett streck');
    bx((string) (($payload['counts']['v-inkorg'] ?? '')) === $count, $box . ' counts-påsen är ' . $count);
}

$round = [
    ['malmo', 'jarfalla', 'Järfälla', false, ['Familjen Roos', 'Annan Järfälla'], ['Sam Wendel'], '2'],
    ['jarfalla', 'malmo', 'Malmö', true, ['Sam Wendel'], ['Familjen Roos', 'Annan Järfälla'], '1'],
    ['malmo', 'jarfalla', 'Järfälla', false, ['Familjen Roos', 'Annan Järfälla'], ['Sam Wendel'], '2'],
];
foreach ($round as $step) {
    [$saved, $box, $eyebrow, $banner, $want, $forbid, $count] = $step;
    $payload = bx_child($saved, $box);
    bx(is_file($tmp . '/committed-box') && trim((string) file_get_contents($tmp . '/committed-box')) === $box, $box . ' skrevs i sessionen innan svaret');
    bx_html($payload, $box, $eyebrow, $banner, $want, $forbid, $count);
}

$node = getenv('NODE') ?: 'node';
$nodeFile = $tmp . '/box-switch.js';
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
assert.strictEqual(plan.kind, 'page');
assert.strictEqual(plan.navigate, true);
assert.strictEqual(plan.fetch, false);
assert.strictEqual(nav.jeabInkorgCompanyCounts(plan), false);
const href = nav.jeabInkorgCompanyHref('/index.php?p=inkorg&box=jarfalla&view=v-historik&id=abc');
const countsUrl = nav.jeabInkorgCountsUrl(href);
assert.ok(countsUrl.indexOf('ix_part=counts') !== -1, countsUrl);
assert.ok(countsUrl.indexOf('box=jarfalla') !== -1, countsUrl);
assert.strictEqual(nav.jeabInkorgCompanyShell('jarfalla').eyebrow, 'Järfälla');
assert.strictEqual(nav.jeabInkorgCompanyShell('jarfalla').banner, false);
assert.strictEqual(nav.jeabInkorgCompanyShell('malmo').eyebrow, 'Malmö');
assert.strictEqual(nav.jeabInkorgCompanyShell('malmo').banner, true);
assert.strictEqual(nav.jeabInkorgResponseLive('', 'malmo', 'jarfalla'), false);
assert.strictEqual(nav.jeabInkorgResponseLive('jarfalla', 'jarfalla', 'jarfalla'), true);
assert.strictEqual(nav.jeabInkorgResponseLive('jarfalla', 'malmo', 'jarfalla'), false);
const painted = nav.jeabInkorgCountsDecision(
  { gen: 1, box: 'jarfalla', left: 4 },
  { gen: 2, locationBox: 'jarfalla', domBox: 'malmo' },
  { fresh: 1, counts: { 'v-inkorg': 2 }, box: 'jarfalla' }
);
assert.strictEqual(painted.paint, true);
assert.strictEqual(painted.reason, 'ok');
const other = nav.jeabInkorgCountsDecision(
  { gen: 1, box: 'malmo', left: 4 },
  { gen: 2, locationBox: 'jarfalla', domBox: 'jarfalla' },
  { fresh: 1, counts: { 'v-inkorg': 1 }, box: 'malmo' }
);
assert.strictEqual(other.paint, false);
assert.strictEqual(other.reason, 'old');
JS);
exec(escapeshellarg($node) . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/inkorg-nav.js') . ' 2>&1', $nodeOut, $nodeCode);
bx($nodeCode === 0, 'counts efter byte målas och förra bolaget slängs ' . implode(' ', $nodeOut));
$pageJs = (string) file_get_contents($root . '/src/pages/inkorg.php');
bx(str_contains($pageJs, 'location.href = window.jeabInkorgCompanyHref') && !str_contains($pageJs, 'ixDropOtherCompany('), 'bolagsbyte laddar sidan i stället för att tömma listan');

echo "OK  bolagsbyte\n";
