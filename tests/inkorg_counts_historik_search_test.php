<?php

declare(strict_types=1);

/**
 * Tre inkorgsfel: vykort från räknarcachen (aldrig 0 som platshållare),
 * Historik från listtabellen under 1,5 s, och Enter-sök som filtrerar vyn.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ix3-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';

function ix3(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'scope' => 'allas'];

$pdo = jeab_pdo_connect_sqlite();
ix3(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan för inkorgen');
$store = jeab_inkorg_pdo_store();
ix3($store instanceof JeabPdoInkorgStore, 'listlagret är SQL');

$row = static function (string $id, string $status, string $at, string $name, string $subject, string $type) use ($store): void {
    $store->saveListRow([
        'id' => $id,
        'company' => 'jarfalla',
        'status' => $status,
        'deleted' => false,
        'assignee' => '',
        'updated_at' => $at,
        'customer_email' => 'kund@brevlada.se',
        'customer_name' => $name,
        'labels' => ['type' => $type, 'intent' => $type === 'Information' ? 'information' : 'support', 'confidence' => 90],
        'tags' => [],
        'search_hay' => mb_strtolower($name . "\n" . $subject),
        'list_snippet' => $subject,
        'last' => [
            'from' => 'kund@brevlada.se',
            'from_name' => $name,
            'subject' => $subject,
            'received_at' => $at,
        ],
        'first' => [
            'from' => 'kund@brevlada.se',
            'from_name' => $name,
            'subject' => $subject,
        ],
    ]);
};

for ($i = 0; $i < 400; $i++) {
    $row('open' . $i, 'open', '2024-01-01T00:00:00+00:00', 'Oppet ' . $i, 'Oppet arende ' . $i, 'Information');
}
for ($i = 1; $i <= 25; $i++) {
    $name = $i === 1 ? 'Anna Kund' : ($i === 2 ? 'Bertil Kund' : 'Support ' . $i);
    $row('s' . $i, 'open', sprintf('2024-05-01T%02d:00:00+00:00', $i), $name, $name . ' fraga', 'Support');
}
for ($i = 1; $i <= 316; $i++) {
    $name = $i === 316 ? 'Anna Historik' : 'Arkiv ' . $i;
    $row(sprintf('h%03d', $i), 'closed', gmdate('c', strtotime('2024-03-01 00:00:00 UTC') + $i * 60), $name, 'Historik ' . $i, 'Information');
}
$store->pdo()->prepare('UPDATE inkorg_list SET list_status = \'\' WHERE id = ?')->execute(['h001']);
$importOk = true;
for ($i = 0; $i < 80; $i++) {
    $id = 'hsi-' . substr(md5('open-import-' . $i), 0, 16);
    $importOk = $importOk && jeab_hs_import_index_append([
        'id' => $id,
        'company' => 'jarfalla',
        'status' => 'open',
        'subject' => 'Oppet import',
        'from' => 'oppet@brevlada.se',
        'from_name' => 'Oppet Import',
        'updated_at' => '2023-01-01T00:00:00+00:00',
        'labels' => ['type' => 'Information'],
        'import_source' => 'helpscout_import',
        'deleted' => false,
    ]);
}
ix3($importOk, '80 öppna importrader');
$store->setListStamp($store->listFingerprint());

$render = static function (bool $seedCounts) use ($root, $tmp): string {
    $script = $tmp . '/render-' . ($seedCounts ? 'cache' : 'empty') . '.php';
    file_put_contents($script, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_DATA');
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require getenv('JEAB_ROOT') . '/src/bootstrap.php';
require getenv('JEAB_ROOT') . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require getenv('JEAB_ROOT') . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
if (getenv('JEAB_SEED_COUNTS') === '1') {
    $box = jeab_inkorg_box();
    $me = jeab_inkorg_me_id();
    $menu = jeab_inkorg_fast_scope_menu('allas');
    $key = jeab_inkorg_fast_count_key($menu, $box, $me);
    jeab_inkorg_fast_count_write($key, [
        'counts' => [
            'v-inkorg' => 12,
            'v-historik' => 316,
            'v-information' => 0,
            'v-alla' => 25,
        ],
        'unread' => 1,
        'sources' => [],
    ]);
}
echo include getenv('JEAB_ROOT') . '/src/pages/inkorg.php';
PHP);
    $proc = proc_open(
        [PHP_BINARY, $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [
            'JEAB_DATA' => $tmp,
            'JEAB_ROOT' => $root,
            'JEAB_DISABLE_SQLITE' => '0',
            'JEAB_SEED_COUNTS' => $seedCounts ? '1' : '0',
        ]
    );
    ix3(is_resource($proc), 'renderskript startar');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    ix3($code === 0 && is_string($out) && $out !== '', 'sidan ritades ' . trim((string) $err));

    return $out;
};

$tile = static function (string $html, string $id): ?string {
    if (!preg_match('/<b data-ix-count="' . preg_quote($id, '/') . '">([^<]*)<\/b>/', $html, $m)) {
        return null;
    }

    return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$emptyHtml = $render(false);
ix3(substr_count($emptyHtml, 'class="item ix-it') >= 25, 'listan visar raderna tillsammans med korten');
$inkorgTile = $tile($emptyHtml, 'v-inkorg');
$histTile = $tile($emptyHtml, 'v-historik');
ix3(ctype_digit((string) $inkorgTile) && (int) $inkorgTile >= 25, 'sidan ritar Inkorg-talet utan cache, fick ' . var_export($inkorgTile, true));
ix3(ctype_digit((string) $histTile), 'sidan ritar Historik-talet utan cache, fick ' . var_export($histTile, true));

$cachedHtml = $render(true);
ix3($tile($cachedHtml, 'v-inkorg') === '12', 'cachad Inkorg-räknare är 12');
ix3($tile($cachedHtml, 'v-historik') === '316', 'cachad Historik-räknare är 316');
ix3($tile($cachedHtml, 'v-information') === '0', 'cachad nolla visas som 0');
$serviceTile = $tile($cachedHtml, 'v-service');
ix3($serviceTile === '…' || (is_string($serviceTile) && ctype_digit($serviceTile)), 'vy utan cache är laddar eller ett tal, inte streck');
ix3(substr_count($cachedHtml, 'class="item ix-it') >= 25, 'listan är kvar när räknarna kommer från cachen');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik', 'scope' => 'allas'];
unset(
    $GLOBALS['_JEAB_INKORG_LIGHT'],
    $GLOBALS['_JEAB_INKORG_LIGHT_FP'],
    $GLOBALS['_JEAB_HS_IMPORT_INDEX'],
    $GLOBALS['_JEAB_TIMING']
);
$GLOBALS['_JEAB_PARTNER_INFO_CALLS'] = 0;
$GLOBALS['_JEAB_LIST_COMPANY_CALLS'] = 0;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
$GLOBALS['_JEAB_LIST_HISTORIK_ROWS'] = 0;
$t0 = microtime(true);
$hist = jeab_inkorg_fast_prepare(true, 'list');
$elapsed = microtime(true) - $t0;
ix3($elapsed < 1.5, 'Historik med 316 ärenden tar ' . round($elapsed, 3) . ' s');
ix3((int) $hist['total'] === 316, 'Historik har 316 träffar, fick ' . (int) $hist['total']);
ix3(substr_count((string) $hist['html'], 'class="item ix-it') === 20, 'Historik visar 20 rader');
ix3(str_contains((string) $hist['html'], 'data-ix-id="h316"'), 'nyaste historikraden ligger på första sidan');
ix3(!str_contains((string) $hist['html'], 'data-ix-id="h001"'), 'äldsta historikraden är inte på första sidan');
ix3(!str_contains((string) $hist['html'], 'data-ix-id="open0"'), 'öppna ärenden ligger inte i Historik');
ix3((int) $GLOBALS['_JEAB_LIST_COMPANY_CALLS'] === 0, 'Historik läser inte hela bolagslistan');
ix3((int) $GLOBALS['_JEAB_LIST_HISTORIK_ROWS'] === 316, 'listtabellen lämnar bara de 316 historikraderna');
ix3((int) $GLOBALS['_JEAB_PARTNER_INFO_CALLS'] === 0, 'Historik kör inte partnerklassning');
ix3((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'Historik läser inte hela importarkivet');
$status = $store->pdo()->query('SELECT list_status FROM inkorg_list WHERE id = \'h001\'')->fetchColumn();
ix3((string) $status === 'closed', 'tom list_status fylls till closed, fick ' . var_export($status, true));
foreach (['folder_filter', 'import_match', 'view_list', 'historik_page', 'historik_backfill'] as $span) {
    ix3(isset($GLOBALS['_JEAB_TIMING'][$span]), 'Server-Timing har ' . $span);
}

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'Anna'];
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$seek = jeab_inkorg_fast_prepare(true, 'list');
ix3((string) $seek['vyId'] === 'v-inkorg' && (string) $seek['q'] === 'Anna', 'sökningen stannar i Inkorgen');
ix3(str_contains((string) $seek['html'], 'Anna Kund') && !str_contains((string) $seek['html'], 'Bertil Kund'), 'q=Anna filtrerar Inkorgen');
ix3(str_contains((string) $seek['html'], 'Anna Historik'), 'sök i Inkorgen tar med den stängda träffen i Historik');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik', 'scope' => 'allas', 'q' => 'Anna'];
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_HS_IMPORT_INDEX']);
$histSeek = jeab_inkorg_fast_prepare(true, 'list');
ix3((string) $histSeek['vyId'] === 'v-historik' && (string) $histSeek['q'] === 'Anna', 'sökningen stannar i Historik');
ix3(str_contains((string) $histSeek['html'], 'Anna Historik') && !str_contains((string) $histSeek['html'], 'Arkiv 1'), 'q=Anna filtrerar Historik');
ix3((int) $histSeek['total'] === 1, 'Historik-söket har en träff, fick ' . (int) $histSeek['total']);

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas'];
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_HS_IMPORT_INDEX'], $GLOBALS['_JEAB_TIMING']);
$GLOBALS['_JEAB_LIST_COMPANY_CALLS'] = 0;
$GLOBALS['_JEAB_LIST_WORKING_CALLS'] = 0;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
$t0 = microtime(true);
$inbox = jeab_inkorg_fast_prepare(true, '');
ix3(microtime(true) - $t0 < 1.5, 'första Inkorgen tar ' . round(microtime(true) - $t0, 3) . ' s');
ix3((int) $GLOBALS['_JEAB_LIST_COMPANY_CALLS'] === 0, 'första ritningen läser inte hela bolagslistan');
ix3((int) $GLOBALS['_JEAB_LIST_WORKING_CALLS'] >= 1, 'första ritningen läser öppna rader');
ix3((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'första ritningen läser inte hela importarkivet');
ix3(substr_count((string) $inbox['html'], 'class="item ix-it') >= 25, 'första ritningen har listan');

for ($i = 1; $i <= 4; $i++) {
    $store->saveListRow([
        'id' => 'malmo' . $i,
        'company' => 'malmo',
        'status' => 'open',
        'deleted' => false,
        'assignee' => '',
        'updated_at' => sprintf('2024-06-0%dT10:00:00+00:00', $i),
        'customer_email' => 'malmo@brevlada.se',
        'customer_name' => 'Malmo Kund',
        'labels' => ['type' => 'Support', 'intent' => 'support', 'confidence' => 90],
        'tags' => [],
        'search_hay' => 'malmo kund',
        'list_snippet' => 'Malmo fraga',
        'last' => ['from' => 'malmo@brevlada.se', 'from_name' => 'Malmo Kund', 'subject' => 'Hej', 'received_at' => '2024-06-01T10:00:00+00:00'],
        'first' => ['from' => 'malmo@brevlada.se', 'from_name' => 'Malmo Kund', 'subject' => 'Hej'],
    ]);
}
$malmoMenu = jeab_inkorg_fast_scope_menu('allas');
$malmoKey = jeab_inkorg_fast_count_key($malmoMenu, 'malmo', jeab_inkorg_me_id());
$store->countCachePut($malmoKey, [
    'counts' => ['v-inkorg' => 0, 'v-service' => 0, 'v-information' => 0, 'v-alla' => 0, 'v-historik' => 0],
    'unread' => 0,
    'sources' => [],
    'at' => time() - 180,
    'mode' => jeab_inkorg_count_mode(),
    'ver' => JEAB_INKORG_COUNT_VER,
]);
$_GET = ['p' => 'inkorg', 'box' => 'malmo', 'view' => 'v-inkorg', 'scope' => 'allas'];
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$malmo = jeab_inkorg_fast_prepare(true, 'list');
ix3((int) $malmo['total'] === 4, 'Malmö-listan har 4 ärenden, fick ' . (int) $malmo['total']);
ix3((string) $malmo['range'] === '4', 'rubrikraden säger 4, fick ' . (string) $malmo['range']);
ix3((array) $malmo['counts'] === [], 'gamla nollor för Malmö ritas inte');
$jarfallaKey = jeab_inkorg_fast_count_key($malmoMenu, 'jarfalla', jeab_inkorg_me_id());
ix3($jarfallaKey !== $malmoKey, 'räknarnyckeln skiljer bolag');

echo "inkorg counts historik search: ok\n";
