<?php

declare(strict_types=1);

/**
 * Bolagsbyte är en sidladdning. Sidan ritar listan för boxen i adressen,
 * och den listan är samma urval som inkorg.counts. Ingen kunddata i hooksvaret.
 */

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);
$tmp = $argv[2] ?? '';
if ($mode === '' || $tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-ixpage-' . bin2hex(random_bytes(4));
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

function pg(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function pg_session(?string $email = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $email = $email ?: 'saljare3@jarfalla.example.test';
    if ($email === 'demo@example.test') {
        require_once dirname(__DIR__) . '/tests/sam_l_login.php';
        jeab_test_login_sam_l();
    } else {
        unset($_COOKIE['jeab_auth']);
        $_SESSION['jeab_email'] = $email;
    }
    $_SESSION['jeab_inkorg_box'] = 'jarfalla';
}

if ($mode === 'part') {
    require $root . '/src/layout.php';
    $actor = (string) ($argv[6] ?? '');
    pg_session($actor !== '' ? $actor : null);
    $box = (string) ($argv[3] ?? 'jarfalla');
    $part = (string) ($argv[4] ?? '');
    $_GET = ['p' => 'inkorg', 'box' => $box, 'co' => $box, 'view' => 'v-inkorg'];
    $scope = (string) ($argv[5] ?? '');
    if ($scope !== '') {
        $_GET['scope'] = $scope;
    }
    if ($part !== '') {
        $_GET['ix_part'] = $part;
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_HS_IMPORT_INDEX']);
    echo include $root . '/src/pages/inkorg.php';
    exit(0);
}

require $root . '/src/layout.php';
pg_session();
$pageJs = (string) file_get_contents($root . '/src/pages/inkorg.php');
pg(str_contains($pageJs, "jeab_asset_url('assets/js/inkorg-nav.js')"), 'jeab_asset_url är kvar');
pg(str_contains($pageJs, 'jeab_inkorg_test_chip_html') && str_contains((string) file_get_contents($root . '/src/inkorg_fast.php'), 'jeab_inkorg_test_chip_html'), 'Omtago-testchippen är kvar');
pg(str_contains($pageJs, 'location.href = window.jeabInkorgCompanyHref'), 'bolagsbyte sätter location.href');
pg(!str_contains($pageJs, 'ixDropOtherCompany('), 'sidan tömmer inte listan vid byte');
$soon = strpos($pageJs, 'function ixCountsSoon()');
$soonBody = $soon === false ? '' : substr($pageJs, $soon, 520);
pg($soon !== false && str_contains($soonBody, "visibilityState === 'hidden'") && str_contains($soonBody, 'ixCountsTimer') && str_contains($soonBody, 'setTimeout') && str_contains($soonBody, 'ixCountsFor(url)'), 'bakgrundsräkningen väntar och hoppar över en dold flik');
$warm = strpos($pageJs, 'function ixWarm()');
$warmBody = $warm === false ? '' : substr($pageJs, $warm, 700);
pg($warm !== false && str_contains($warmBody, 'ixLoadThread(url, false)') && substr_count($warmBody, "searchParams.set('ix_part', 'all')") === 0 && substr_count($warmBody, 'ixCountsSoon()') === 0, 'första laddningen hämtar den öppna tråden utan att bygga om listan');
$fastSrc = (string) file_get_contents($root . '/src/inkorg_fast.php');
$pageCounts = '';
$at = strpos($fastSrc, 'function jeab_inkorg_page_counts');
if ($at !== false) {
    $pageCounts = substr($fastSrc, $at, 280);
}
pg($pageCounts !== '' && str_contains($pageCounts, 'jeab_inkorg_fast_counts_ready($menu, $box, $me)') && !str_contains($pageCounts, 'jeab_inkorg_fast_counts_refresh'), 'sidan läser räknarcachen och räknar inte om i sidträffen');

$node = getenv('NODE') ?: 'node';
$nodeFile = $tmp . '/page-load.js';
file_put_contents($nodeFile, <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);
const href = nav.jeabInkorgCompanyHref('/index.php?p=inkorg&co=jarfalla&view=v-alla&id=abc');
const url = new URL(href, 'http://local');
assert.strictEqual(url.searchParams.get('p'), 'inkorg');
assert.strictEqual(url.searchParams.get('box'), 'jarfalla');
assert.strictEqual(url.searchParams.get('co'), 'jarfalla');
assert.strictEqual(url.searchParams.get('id'), null);
const plan = nav.jeabInkorgClickPlan({ company: true, boxChanged: true, shownView: 'v-alla', nextView: 'v-alla' });
assert.strictEqual(plan.navigate, true);
assert.strictEqual(plan.fetch, false);
JS);
exec(escapeshellarg($node) . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/inkorg-nav.js') . ' 2>&1', $nodeOut, $nodeCode);
pg($nodeCode === 0, 'adressen för bolagsbyte är box och co ' . implode(' ', $nodeOut));

$pdo = jeab_pdo_connect_sqlite();
pg(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
pg($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
$importDdl = (int) ($GLOBALS['_JEAB_INKORG_IMPORT_CACHE_DDL'] ?? 0);
pg($importDdl === 1, 'importcachen skapas en gång');
jeab_ensure_inkorg_tables($pdo, 'sqlite');
jeab_inkorg_ensure_import_cache($pdo, 'sqlite');
pg((int) ($GLOBALS['_JEAB_INKORG_IMPORT_CACHE_DDL'] ?? 0) === $importDdl, 'flaggan hoppar över importcachens DDL');

$seed = [
    ['id' => 'mine', 'assignee' => '', 'name' => 'Eget ärende', 'product' => 'batteri'],
    ['id' => 'other', 'assignee' => 'Elin', 'name' => 'Elins ärende'],
    ['id' => 'shut', 'assignee' => '', 'name' => 'Stängt ärende', 'status' => 'closed'],
];
foreach ($seed as $i => $row) {
    $store->upsertMessage($row['id'] . 'm', [
        'id' => $row['id'] . 'm',
        'thread_id' => $row['id'],
        'from' => $row['id'] . '@exempel.test',
        'from_name' => $row['name'],
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $row['name'],
        'body' => $row['name'],
        'received_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i + 1),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support', 'intent' => 'support', 'confidence' => 90] + (isset($row['product']) ? ['product' => $row['product']] : []),
    ]);
    $store->upsertThread($row['id'], [
        'status' => $row['status'] ?? 'open',
        'tags' => [],
        'manual_tags' => [],
        'assignee' => $row['assignee'],
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => sprintf('2024-06-%02dT10:00:00+00:00', $i + 1),
    ]);
}
$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'seed-page');
unset($GLOBALS['_JEAB_INKORG_PART']);

$hid = static fn (string $seed): string => 'hsi-' . substr(hash('sha256', $seed), 0, 16);
$append = static function (string $id, string $status, string $subject, array $tags): void {
    pg(jeab_hs_import_index_append([
        'id' => $id,
        'company' => 'malmo',
        'status' => $status,
        'subject' => $subject,
        'from' => 'kund.' . $id . '@exempel.test',
        'from_name' => 'Malmö Kund',
        'snippet' => $subject,
        'labels' => ['type' => 'Ny lead'],
        'assignee' => '',
        'deleted' => false,
        'import_source' => 'helpscout_import',
        'updated_at' => '2024-06-08T10:00:00+00:00',
        'tags' => $tags,
        'message_ids' => [],
    ]), 'kopia ' . $subject);
};
$append($hid('kim-batteri'), 'open', 'Kim batteri', ['batteri']);
$append($hid('sol-oppen'), 'open', 'Sol öppen', ['sol']);
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
$indexBefore = (int) ($GLOBALS['_JEAB_HS_INDEX_FULL'] ?? 0);
$GLOBALS['_JEAB_INKORG_PART'] = '';
unset($GLOBALS['_JEAB_INKORG_ALLOW_REBUILD']);
jeab_inkorg_fast_import_threads('malmo');
pg((int) ($GLOBALS['_JEAB_HS_INDEX_FULL'] ?? 0) === $indexBefore, 'Malmö-sidan läser inte Help Scout-indexet utan cache');
pg((int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] ?? 0) === 0, 'Malmö-sidan skannar inte importen');
$warm = jeab_inkorg_tick_warm();
pg(!empty($warm['ok']), 'tick fyller listan och räknarna');
pg(jeab_inkorg_import_cached('malmo'), 'tick skriver Malmö-importens cache i MySQL');

function pg_part(string $box, string $part, string $scope = '', string $email = ''): string
{
    global $tmp;
    $cmd = [PHP_BINARY, __FILE__, 'part', $tmp, $box, $part, $scope];
    if ($email !== '') {
        $cmd[] = $email;
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    pg(is_resource($proc), $box . ' ' . $part . ' startade');
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    pg($code === 0, $box . ' ' . ($part === '' ? 'sida' : $part) . ' avslutade rent ' . trim($err));

    return $out;
}

function pg_same(string $box, array $want, array $hide, string $scope = ''): void
{
    $email = $box === 'malmo' ? 'demo@example.test' : '';
    if ($email !== '') {
        $prev = (string) ($_SESSION['jeab_email'] ?? '');
        $savedGet = $_GET;
        require_once dirname(__DIR__) . '/tests/sam_l_login.php';
        jeab_test_login_sam_l();
        $_GET = ['p' => 'inkorg', 'box' => $box, 'co' => $box, 'view' => 'v-inkorg'];
        if ($scope !== '') {
            $_GET['scope'] = $scope;
        }
        unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
        jeab_inkorg_fast_counts_refresh();
        $_GET = $savedGet;
        unset($_COOKIE['jeab_auth']);
        $_SESSION['jeab_email'] = $prev;
        unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
    }
    $html = pg_part($box, '', $scope, $email);
    $rows = substr_count($html, 'class="item ix-it');
    pg(preg_match('/data-ix-count="v-inkorg">(\d+)</', $html, $tileHtml) === 1, $box . ' vykortet står i HTML');
    $htmlTile = (int) ($tileHtml[1] ?? -1);
    $countsOut = pg_part($box, 'counts', $scope, $email);
    $jsonAt = strpos($countsOut, '{');
    $counts = json_decode($jsonAt === false ? '' : substr($countsOut, $jsonAt), true);
    pg(is_array($counts), $box . ' counts är JSON');
    $tile = (int) (($counts['counts'] ?? [])['v-inkorg'] ?? -1);
    $prevEmail = (string) ($_SESSION['jeab_email'] ?? '');
    if ($email !== '') {
        require_once dirname(__DIR__) . '/tests/sam_l_login.php';
        jeab_test_login_sam_l();
        unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
    }
    $hook = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => $box]);
    if ($email !== '') {
        unset($_COOKIE['jeab_auth']);
        $_SESSION['jeab_email'] = $prevEmail;
        unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
    }
    $hookTotal = (int) (($hook['tabs']['v-inkorg']['total'] ?? -1));
    pg($rows === $htmlTile && $htmlTile === $tile && $tile === count($want), $box . ' rader ' . $rows . ' = HTML ' . $htmlTile . ' = counts ' . $tile);
    if ($scope !== 'egna') {
        pg($tile === $hookTotal, $box . ' Allas-vykortet är kroken ' . $hookTotal . ', fick ' . $tile);
    }
    if (preg_match('/id="ix-range"[^>]*>([^<]*)</', $html, $m) === 1) {
        pg(trim($m[1]) === (string) $htmlTile, $box . ' rubriken är ' . $htmlTile . ', fick ' . trim($m[1]));
    } else {
        pg(false, $box . ' saknar rubrik');
    }
    foreach ($want as $subject) {
        pg(str_contains($html, $subject), $box . ' visar ' . $subject);
    }
    foreach ($hide as $subject) {
        pg(!str_contains($html, $subject), $box . ' döljer ' . $subject);
    }
    $encoded = json_encode($hook, JSON_UNESCAPED_UNICODE);
    pg(is_string($encoded) && !str_contains($encoded, '@') && !str_contains($encoded, 'Kim') && !str_contains($encoded, 'Elin'), 'hooken har ingen persondata');
}

pg_same('jarfalla', ['Eget ärende', 'Elins ärende'], ['Stängt ärende', 'Kim batteri'], 'allas');
pg_same('jarfalla', ['Eget ärende'], ['Elins ärende', 'Stängt ärende', 'Kim batteri'], 'egna');
pg_same('malmo', ['Kim batteri'], ['Sol öppen', 'Eget ärende', 'Elins ärende'], 'allas');

echo "inkorg page load: ok\n";
