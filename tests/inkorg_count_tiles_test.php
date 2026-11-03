<?php

declare(strict_types=1);

/**
 * Järfälla: en färsk noll-cache, eller counts medan listlåset är taget, ska
 * räkna de rader som redan finns. Sidan ritar senaste cachen. Saknas den visas …,
 * aldrig 0 eller streck som platshållare. En cachad nolla är 0. Ingen kunddata.
 */

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);
$tmp = $argv[2] ?? '';
if ($mode === '' || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-ixcount-' . bin2hex(random_bytes(4));
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

function ct(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ct_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
}

function ct_get(string $box = 'jarfalla'): void
{
    $_GET = ['p' => 'inkorg', 'box' => $box, 'view' => 'v-inkorg', 'scope' => 'egna'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

if ($mode === 'hold') {
    $pdo = jeab_pdo_connect_sqlite();
    if (!jeab_db_get_lock($pdo, 'jeab_inkorg_list')) {
        fwrite(STDERR, "lock-failed\n");
        exit(1);
    }
    file_put_contents($tmp . '/ready-lock', '1');
    sleep(20);
    exit(0);
}

if ($mode === 'counts' || $mode === 'page') {
    require $root . '/src/layout.php';
    ct_session();
    ct_get();
    if ($mode === 'counts') {
        $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
        $GLOBALS['_JEAB_PARTNER_INFO_CALLS'] = 0;
        session_write_close();
        $bag = jeab_inkorg_fast_counts_refresh();
        $again = jeab_inkorg_fast_counts_refresh();
        echo json_encode([
            'inkorg' => (int) (($bag['counts'] ?? [])['v-inkorg'] ?? -1),
            'again' => (int) (($again['counts'] ?? [])['v-inkorg'] ?? -1),
            'fresh' => (int) ($bag['fresh'] ?? 0),
            'rebuild' => (int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0),
            'partner' => (int) ($GLOBALS['_JEAB_PARTNER_INFO_CALLS'] ?? 0),
            'me' => jeab_inkorg_me_id(),
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    echo include $root . '/src/pages/inkorg.php';
    exit(0);
}

require $root . '/src/layout.php';
ct_session();

$pdo = jeab_pdo_connect_sqlite();
ct(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
ct($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

for ($i = 1; $i <= 25; $i++) {
    $id = sprintf('j%02d', $i);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Järfälla rad ' . $i,
        'body' => 'Kort ' . $i,
        'received_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support', 'product' => 'batteri'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i),
    ]);
}
$store->upsertMessage('h1m', [
    'id' => 'h1m',
    'thread_id' => 'h1',
    'from' => 'arkiv@exempel.test',
    'from_name' => 'Arkiv',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Stängd information',
    'body' => 'Redan arkiverad.',
    'received_at' => '2024-05-01T10:00:00+00:00',
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Information'],
]);
$store->upsertThread('h1', [
    'status' => 'closed',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'updated_at' => '2024-05-02T10:00:00+00:00',
]);

$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
$GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
jeab_inkorg_sql_list_sync($store, 'seed');
ct((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === 1, 'tom stämpel bygger listan en gång');
ct($store->listStamp() === $store->listFingerprint(), 'stämpeln är satt');
ct(count($store->listForCompany('jarfalla')) >= 25, 'listan har Järfällas rader');

$closed = ['id' => 'h1', 'status' => 'closed', 'labels' => ['type' => 'Information'], 'tags' => [], 'assignee' => '', 'company' => 'jarfalla'];
$GLOBALS['_JEAB_PARTNER_INFO_CALLS'] = 0;
foreach (['historik', 'inkorg', 'information', 'service', 'alla'] as $folder) {
    jeab_inkorg_folder_thread($closed, $folder);
}
ct((int) $GLOBALS['_JEAB_PARTNER_INFO_CALLS'] === 0, 'stängd information klassas utan partneruppslag');

ct_get();
$me = jeab_inkorg_me_id();
ct($me !== '', 'inloggad person har ett id');
$menu = jeab_inkorg_fast_scope_menu('egna');
$key = jeab_inkorg_fast_count_key($menu, 'jarfalla', $me);
$malmoKey = jeab_inkorg_fast_count_key($menu, 'malmo', $me);

function ct_wipe(PDO $pdo): void
{
    $rows = $pdo->query('SELECT cache_key FROM inkorg_count_cache')->fetchAll(PDO::FETCH_COLUMN);
    $del = $pdo->prepare('DELETE FROM inkorg_count_cache WHERE cache_key = ?');
    foreach ($rows as $cacheKey) {
        $cacheKey = (string) $cacheKey;
        if ($cacheKey === '__stamp' || $cacheKey === '__sources') {
            continue;
        }
        $del->execute([$cacheKey]);
        if (function_exists('apcu_delete')) {
            apcu_delete('jeab_ixc_' . $cacheKey);
        }
    }
}

function ct_child(string $mode): array
{
    global $tmp;
    $pipes = [];
    $proc = proc_open(
        [PHP_BINARY, __FILE__, $mode, $tmp],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    ct(is_resource($proc), $mode . ' startade');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    ct($code === 0, $mode . ' avslutade rent ' . trim((string) $err));

    return ['out' => (string) $out, 'err' => (string) $err];
}

ct_wipe($pdo);
$twice = json_decode(ct_child('counts')['out'], true);
ct(is_array($twice) && (int) ($twice['inkorg'] ?? -1) === 25, 'första räknaranropet är 25, fick ' . (string) (is_array($twice) ? ($twice['inkorg'] ?? '') : ''));
ct(is_array($twice) && (int) ($twice['again'] ?? -1) === (int) ($twice['inkorg'] ?? -2), 'andra anropet av räknarna ger samma tal, inte dubbelt');
ct(is_array($twice) && (int) ($twice['rebuild'] ?? -1) === 0, 'det andra räknaranropet bygger inte om listan');
ct_wipe($pdo);
$page = ct_child('page')['out'];
ct(str_contains($page, 'Järfälla rad 1'), 'listan ritas utan räknarcache');
ct(str_contains($page, 'data-ix-count="v-inkorg">25<'), 'saknad cache räknas in i sidans HTML');

jeab_inkorg_fast_count_write($key, [
    'counts' => ['v-inkorg' => 12, 'v-information' => 0, 'v-historik' => 316],
    'unread' => 2,
    'sources' => [],
]);
jeab_inkorg_fast_count_write($malmoKey, [
    'counts' => ['v-inkorg' => 4, 'v-service' => 0, 'v-information' => 0, 'v-alla' => 4, 'v-historik' => 4633],
    'unread' => 0,
    'sources' => [],
]);
$cachedPage = ct_child('page')['out'];
ct(str_contains($cachedPage, 'data-ix-count="v-inkorg">12<'), 'Järfällas cache ritas i HTML');
ct(str_contains($cachedPage, 'data-ix-count="v-information">0<'), 'en cachad nolla ritas som 0');
ct(str_contains($cachedPage, 'data-ix-count="v-historik">316<'), 'Historik ritas från cachen');
ct(str_contains($cachedPage, 'data-ix-count="v-service">…<') || preg_match('/data-ix-count="v-service">\d+</', $cachedPage) === 1, 'en mapp utan cache räknas eller visas som laddar, inte streck');
ct(!str_contains($cachedPage, '4633'), 'Malmös cache ritas inte på Järfälla');
ct(str_contains($cachedPage, 'Järfälla rad 25'), 'listan visar raderna tillsammans med korten');

$staleAt = time() - JEAB_INKORG_COUNT_TTL - 30;
$staleBag = [
    'counts' => ['v-inkorg' => 12, 'v-information' => 0, 'v-historik' => 316],
    'unread' => 2,
    'sources' => [],
    'fresh' => 0,
    'at' => $staleAt,
    'mode' => jeab_inkorg_count_mode(),
    'ver' => JEAB_INKORG_COUNT_VER,
];
$staleJson = json_encode($staleBag, JSON_UNESCAPED_UNICODE);
ct(is_string($staleJson), 'gammal cache kan skrivas');
$pdo->prepare('UPDATE inkorg_count_cache SET payload = ? WHERE cache_key = ?')->execute([$staleJson, $key]);
if (function_exists('apcu_store')) {
    apcu_store('jeab_ixc_' . $key, $staleBag, 6 * 3600);
}
$stalePage = ct_child('page')['out'];
ct(str_contains($stalePage, 'data-ix-count="v-inkorg">12<'), 'gammal cache ritas direkt');
ct(!str_contains($stalePage, 'data-ix-count="v-inkorg">25<'), 'gammal cache räknas inte om i sidträffen');

$zeros = ['v-inkorg' => 0, 'v-service' => 0, 'v-information' => 0, 'v-alla' => 0, 'v-historik' => 0];
jeab_inkorg_fast_count_write($key, ['counts' => $zeros, 'unread' => 0, 'sources' => []]);
$planted = jeab_inkorg_fast_count_read($key);
ct(is_array($planted) && (int) (($planted['counts']['v-inkorg'] ?? -1)) === 0 && !empty($planted['fresh']), 'noll-cachen är färsk innan counts');
$zero = json_decode(ct_child('counts')['out'], true);
ct(is_array($zero), 'counts-svaret är JSON');
ct((int) ($zero['inkorg'] ?? -1) === 25, 'färsk noll-cache räknas om till listans 25, fick ' . (string) ($zero['inkorg'] ?? ''));
ct((int) ($zero['fresh'] ?? 0) === 1, 'omräkningen är färsk');
ct((int) ($zero['rebuild'] ?? -1) === 0, 'counts bygger inte om när stämpeln finns');
ct((int) ($zero['partner'] ?? -1) === 0, 'counts gör inget partneruppslag på historiken');
ct((string) ($zero['me'] ?? '') === $me, 'counts använder samma person efter att sessionen stängts i grinden');

ct_wipe($pdo);
@unlink($tmp . '/ready-lock');
$holdPipes = [];
$hold = proc_open(
    [PHP_BINARY, __FILE__, 'hold', $tmp],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', $tmp . '/hold.err', 'w']],
    $holdPipes
);
ct(is_resource($hold), 'låsprocessen startade');
$start = microtime(true);
while (!is_file($tmp . '/ready-lock') && microtime(true) - $start < 10) {
    usleep(50000);
}
ct(is_file($tmp . '/ready-lock'), 'listlåset är taget');
$t0 = microtime(true);
$locked = json_decode(ct_child('counts')['out'], true);
$waited = microtime(true) - $t0;
if (is_resource($hold)) {
    proc_terminate($hold);
    proc_close($hold);
}
ct(is_array($locked) && (int) ($locked['inkorg'] ?? -1) === 25, 'counts med hållet lås svarar 25, fick ' . (string) ($locked['inkorg'] ?? ''));
ct((int) ($locked['rebuild'] ?? -1) === 0, 'hållet lås bygger inte om');
ct($waited < 2, 'counts med hållet lås återvänder direkt, ' . round($waited, 3) . ' s');

$keyBefore = jeab_inkorg_fast_count_key(jeab_inkorg_fast_scope_menu('egna'), 'jarfalla', jeab_inkorg_me_id());
$_SESSION['jeab_inkorg_box'] = 'malmo';
unset($_GET['box'], $_GET['co']);
session_write_close();
ct(jeab_auth_user_email() === 'saljare3@jarfalla.example.test', 'e-post läses efter session_write_close');
ct(jeab_inkorg_me_id() === $me, 'person-id består efter session_write_close');
ct(jeab_inkorg_box() === 'malmo', 'sparad Malmö-box för Järfälla-personal öppnas');
$savedEmail = (string) ($_SESSION['jeab_email'] ?? '');
require_once dirname(__DIR__) . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$_SESSION['jeab_inkorg_box'] = 'malmo';
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
ct(jeab_inkorg_box() === 'malmo', 'admin får använda sparad Malmö-box');
unset($_COOKIE['jeab_auth']);
$_SESSION['jeab_email'] = $savedEmail;
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
ct(jeab_inkorg_fast_count_key(jeab_inkorg_fast_scope_menu('egna'), 'jarfalla', jeab_inkorg_me_id()) === $keyBefore, 'räknarnyckeln följer samma person');

$node = getenv('NODE') ?: 'node';
$nodeFile = $tmp . '/counts-switch.js';
file_put_contents($nodeFile, <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);
const plan = nav.jeabInkorgClickPlan({
  company: true,
  boxChanged: true,
  shownView: 'v-historik',
  nextView: 'v-inkorg',
  nextId: '',
  curId: 't1'
});
assert.strictEqual(plan.kind, 'page');
assert.strictEqual(plan.navigate, true);
assert.strictEqual(plan.fetch, false);
assert.strictEqual(nav.jeabInkorgCompanyCounts(plan), false);
const list = nav.jeabInkorgClickPlan({ shownView: 'v-inkorg', nextView: 'v-alla' });
assert.strictEqual(nav.jeabInkorgCompanyCounts(list), false);
const href = nav.jeabInkorgCompanyHref('/index.php?p=inkorg&box=malmo&view=v-historik&id=abc');
const countsUrl = nav.jeabInkorgCountsUrl(href);
assert.ok(countsUrl.indexOf('ix_part=counts') !== -1, countsUrl);
assert.ok(countsUrl.indexOf('box=malmo') !== -1, countsUrl);
assert.ok(countsUrl.indexOf('id=') === -1, countsUrl);
const late = nav.jeabInkorgCountsDecision(
  { gen: 1, box: 'jarfalla', left: 4 },
  { gen: 2, locationBox: 'malmo', domBox: 'malmo' },
  { fresh: 1, counts: { 'v-inkorg': 25 }, box: 'jarfalla' }
);
assert.strictEqual(late.paint, false);
const moved = nav.jeabInkorgCountsDecision(
  { gen: 2, box: 'jarfalla', left: 4 },
  { gen: 2, locationBox: 'malmo', domBox: 'malmo' },
  { fresh: 1, counts: { 'v-inkorg': 25 }, box: 'jarfalla' }
);
assert.strictEqual(moved.paint, false);
assert.strictEqual(moved.reason, 'location');
const held = nav.jeabInkorgCountsDecision(
  { gen: 2, box: 'malmo', left: 4 },
  { gen: 2, locationBox: 'malmo', domBox: 'jarfalla' },
  { fresh: 1, counts: { 'v-inkorg': 4 }, box: 'malmo' }
);
assert.strictEqual(held.paint, true);
assert.strictEqual(held.reason, 'ok');
const sameGen = nav.jeabInkorgCountsDecision(
  { gen: 1, box: 'jarfalla', left: 4 },
  { gen: 2, locationBox: 'jarfalla', domBox: 'malmo' },
  { fresh: 1, counts: { 'v-inkorg': 25 }, box: 'jarfalla' }
);
assert.strictEqual(sameGen.paint, true);
assert.strictEqual(sameGen.reason, 'ok');
const ready = nav.jeabInkorgCountsDecision(
  { gen: 2, box: 'malmo', left: 4 },
  { gen: 2, locationBox: 'malmo', domBox: 'malmo' },
  { fresh: 1, counts: { 'v-inkorg': 4 }, box: 'malmo' }
);
assert.strictEqual(ready.paint, true);
const busy = nav.jeabInkorgCountsDecision(
  { gen: 2, box: 'malmo', left: 2 },
  { gen: 2, locationBox: 'malmo', domBox: 'malmo' },
  { busy: 1, box: 'malmo' }
);
assert.strictEqual(busy.retry, true);
assert.strictEqual(busy.paint, false);
JS);
exec(escapeshellarg($node) . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/inkorg-nav.js') . ' 2>&1', $nodeOut, $nodeCode);
ct($nodeCode === 0, 'bolagsbyte hämtar counts och slänger ett sent svar ' . implode(' ', $nodeOut));
$pageJs = (string) file_get_contents($root . '/src/pages/inkorg.php');
ct(str_contains($pageJs, 'location.href = window.jeabInkorgCompanyHref') && str_contains($pageJs, 'function ixCountsSoon()') && str_contains($pageJs, 'ixCountsFor(url)'), 'bolagsbyte laddar om sidan och räknarna hämtas direkt');

echo "OK  räknarkorten\n";
