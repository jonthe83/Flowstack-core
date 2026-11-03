<?php

declare(strict_types=1);

/**
 * Arbetsvyn läser en sida, inte hela mejlboxen.
 * Backfill fyller NULL och tom list_status en gång. ix_part=all bygger inte om listan.
 * En HTML-träff markerar tråden som läst på servern. Tråd-JSON gör det inte.
 */

$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
$tmp = $argv[2] ?? '';
if ($mode === 'all') {
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('XAI_API_KEY');
    require $root . '/src/bootstrap.php';
    require $root . '/src/layout.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once $root . '/tests/sam_l_login.php';
    jeab_test_login_sam_l();
    $_SESSION['jeab_inkorg_box'] = 'jarfalla';
    $_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'co' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'ix_part' => 'all'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
    unset($GLOBALS['_JEAB_INKORG_ALLOW_REBUILD']);
    register_shutdown_function(static function () use ($tmp): void {
        file_put_contents($tmp . '/all-globals.json', json_encode([
            'allow' => !empty($GLOBALS['_JEAB_INKORG_ALLOW_REBUILD']) ? 1 : 0,
            'rebuild' => (int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0),
        ]));
    });
    echo include $root . '/src/pages/inkorg.php';
    exit(0);
}

if ($tmp === '') {
    $tmp = sys_get_temp_dir() . '/jeab-ixpage3-' . bin2hex(random_bytes(4));
    mkdir($tmp . '/inkorg', 0775, true);
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
require $root . '/src/layout.php';

function pg3(bool $ok, string $message): void
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
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$pdo = jeab_pdo_connect_sqlite();
pg3(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan');
$store = jeab_inkorg_pdo_store();
pg3($store instanceof JeabPdoInkorgStore, 'SQL-lager');

$probe = static function (string $id, string $status) use ($store): void {
    $store->saveListRow([
        'id' => $id,
        'company' => 'jarfalla',
        'status' => $status,
        'deleted' => false,
        'assignee' => '',
        'updated_at' => '2024-01-01T00:00:00+00:00',
        'customer_email' => $id . '@exempel.test',
        'customer_name' => $id,
        'labels' => ['type' => 'Support'],
        'tags' => [],
        'search_hay' => $id,
        'list_snippet' => $id,
    ]);
};
$probe('empty-open', 'open');
$probe('empty-shut', 'closed');
$probe('laterrow', 'open');
$clear = $pdo->prepare('UPDATE inkorg_list SET list_status = \'\' WHERE id = ?');
$clear->execute(['empty-open']);
$clear->execute(['empty-shut']);
$filled = $store->backfillListStatus();
pg3($filled >= 2, 'backfill fyller tom list_status, fick ' . $filled);
$openStatus = $pdo->query('SELECT list_status FROM inkorg_list WHERE id = \'empty-open\'')->fetchColumn();
$shutStatus = $pdo->query('SELECT list_status FROM inkorg_list WHERE id = \'empty-shut\'')->fetchColumn();
pg3($openStatus === 'open' && $shutStatus === 'closed', 'tom sträng blir open respektive closed');
pg3($store->listStatusBackfillDone(), 'backfill sätter flaggan');
$clear->execute(['laterrow']);
pg3($store->backfillListStatus() === 0, 'en senare tom list_status fylls inte om');
$later = $pdo->query('SELECT list_status FROM inkorg_list WHERE id = \'laterrow\'')->fetchColumn();
pg3((string) $later === '', 'flaggan lämnar den senare tomma raden');
$pdo->exec('DELETE FROM inkorg_list WHERE id IN (\'empty-open\', \'empty-shut\', \'laterrow\')');

$store->saveListRow([
    'id' => 'drift',
    'company' => 'jarfalla',
    'status' => 'open',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-07-01T00:00:00+00:00',
    'customer_email' => 'drift@exempel.test',
    'customer_name' => 'Kolumnen stängd',
    'labels' => ['type' => 'Support'],
    'tags' => [],
    'search_hay' => 'kolumnen stängd',
    'list_snippet' => 'Kolumnen stängd',
]);
$pdo->prepare('UPDATE inkorg_list SET list_status = ? WHERE id = ?')->execute(['closed', 'drift']);
$workingIds = [];
foreach ($store->listWorking('jarfalla') as $row) {
    $workingIds[] = (string) ($row['id'] ?? '');
}
pg3(in_array('drift', $workingIds, true), 'JSON-öppen rad är en arbetsrad trots stängd list_status');

for ($i = 1; $i <= 80; $i++) {
    $store->saveListRow([
        'id' => sprintf('t%03d', $i),
        'company' => 'jarfalla',
        'status' => 'open',
        'deleted' => false,
        'assignee' => '',
        'updated_at' => gmdate('c', strtotime('2024-08-01 00:00:00 UTC') + $i),
        'customer_email' => 'kund' . $i . '@exempel.test',
        'customer_name' => 'Kund ' . $i,
        'labels' => ['type' => 'Support'],
        'tags' => [],
        'search_hay' => 'kund ' . $i,
        'list_snippet' => 'Kund ' . $i,
        'first' => ['from' => 'kund' . $i . '@exempel.test', 'from_name' => 'Kund ' . $i, 'subject' => 'Fråga ' . $i],
        'last' => ['from' => 'kund' . $i . '@exempel.test', 'from_name' => 'Kund ' . $i, 'subject' => 'Fråga ' . $i],
    ]);
}
for ($i = 1; $i <= 3; $i++) {
    $store->saveListRow([
        'id' => 'hit' . $i,
        'company' => 'jarfalla',
        'status' => 'closed',
        'deleted' => false,
        'assignee' => '',
        'updated_at' => gmdate('c', strtotime('2024-06-01 00:00:00 UTC') + $i),
        'customer_email' => 'nal' . $i . '@exempel.test',
        'customer_name' => 'Nål Historik',
        'labels' => ['type' => 'Information'],
        'tags' => [],
        'search_hay' => 'nål historik',
        'list_snippet' => 'Nål Historik',
    ]);
}
$store->upsertMessage('t080m', [
    'id' => 't080m',
    'thread_id' => 't080',
    'from' => 'kund80@exempel.test',
    'from_name' => 'Kund 80',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fråga 80',
    'body' => 'Fråga 80',
    'received_at' => gmdate('c', strtotime('2024-08-01 00:00:00 UTC') + 80),
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Support', 'intent' => 'support', 'confidence' => 90],
]);
$store->upsertThread('t080', [
    'status' => 'open',
    'tags' => [],
    'manual_tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'updated_at' => gmdate('c', strtotime('2024-08-01 00:00:00 UTC') + 80),
]);
jeab_inkorg_views();
$store->setListStamp('page-seed');
pg3($store->listStamp() === 'page-seed', 'liststämpeln är satt, fick ' . var_export($store->listStamp(), true));

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas'];
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$GLOBALS['_JEAB_LIST_WORKING_FETCHED'] = 0;
$GLOBALS['_JEAB_LIST_WORKING_CALLS'] = 0;
$page1 = jeab_inkorg_fast_prepare(true, 'list');
preg_match_all('/data-ix-id="([^"]+)"/', (string) ($page1['html'] ?? ''), $idMatch);
$ids1 = $idMatch[1];
$expect1 = [];
for ($i = 80; $i >= 31; $i--) {
    $expect1[] = sprintf('t%03d', $i);
}
pg3($ids1 === $expect1, 'första sidan är de 50 nyaste, fick ' . implode(',', array_slice($ids1, 0, 5)) . '…');
pg3((int) ($GLOBALS['_JEAB_LIST_WORKING_FETCHED'] ?? 0) < 80, 'första sidan läser inte alla row_json, fick ' . (int) ($GLOBALS['_JEAB_LIST_WORKING_FETCHED'] ?? 0));
pg3((int) ($GLOBALS['_JEAB_LIST_WORKING_LIMIT'] ?? 0) > 0, 'listWorking fick en gräns');
pg3(str_contains((string) ($page1['html'] ?? ''), 'data-ix-more="1"'), 'första sidan har Visa fler');

$_GET['sida'] = '2';
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$page2 = jeab_inkorg_fast_prepare(true, 'list');
preg_match_all('/data-ix-id="([^"]+)"/', (string) ($page2['html'] ?? ''), $idMatch2);
$ids2 = $idMatch2[1];
$expect2 = [];
for ($i = 30; $i >= 1; $i--) {
    $expect2[] = sprintf('t%03d', $i);
}
$expect2[] = 'drift';
pg3($ids2 === $expect2, 'andra sidan är resten');
pg3((int) ($page2['total'] ?? 0) === 81 && !str_contains((string) ($page2['html'] ?? ''), 'data-ix-more="1"'), 'sista sidan visar hela arbetsvyn, fick ' . (int) ($page2['total'] ?? 0));
pg3(!in_array('drift', $ids1, true) && in_array('drift', $ids2, true), 'JSON-öppen rad syns i Inkorgen trots stängd list_status');
$allaListed = jeab_inkorg_fast_working_listed($store, 'jarfalla', ['folder' => 'alla', 'company' => 'jarfalla'], 1, 50);
pg3((int) ($allaListed['total'] ?? 0) === 81 && !empty($allaListed['exact']) && count($allaListed['rows']) === 50, 'Alla räknar hela mängden, fick ' . (int) ($allaListed['total'] ?? 0));

$GLOBALS['_JEAB_LIST_HISTORIK_FULL'] = 0;
$hook = jeab_inkorg_counts_hook(['box' => 'jarfalla']);
pg3(($hook['ok'] ?? false) === true, 'räknehooken svarar');
pg3((int) ($GLOBALS['_JEAB_LIST_HISTORIK_FULL'] ?? 0) === 0, 'räknehooken läser inte alla stängda row_json');

$hits = $store->listHistorikMatching('jarfalla', 'Nål', 1);
pg3(count($hits) === 1 && (int) ($GLOBALS['_JEAB_LIST_HISTORIK_MATCH_LIMIT'] ?? 0) === 1, 'historiksökningen stannar vid gränsen');

$store->saveListRow([
    'id' => 'ny-closed',
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2025-01-01T00:00:00+00:00',
    'customer_email' => 'nyast@exempel.test',
    'customer_name' => 'Kund Nyast',
    'labels' => ['type' => 'Support'],
    'tags' => [],
    'search_hay' => 'kund nyast',
    'list_snippet' => 'Kund Nyast',
    'first' => ['from' => 'nyast@exempel.test', 'from_name' => 'Kund Nyast', 'subject' => 'Nyast'],
    'last' => ['from' => 'nyast@exempel.test', 'from_name' => 'Kund Nyast', 'subject' => 'Nyast'],
]);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'kund'];
unset($_GET['sida'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$seek = jeab_inkorg_fast_prepare(true, 'list');
preg_match_all('/data-ix-id="([^"]+)"/', (string) ($seek['html'] ?? ''), $seekIds);
pg3(($seekIds[1][0] ?? '') === 'ny-closed', 'sök tar med nyare stängd träff före öppna, fick ' . ($seekIds[1][0] ?? ''));

$storeSrc = (string) file_get_contents($root . '/src/inkorg_store.php');
pg3(substr_count($storeSrc, 'list_status IS NULL OR list_status =') >= 2, 'MySQL-backfill tar NULL och tom sträng');
$pageSrc = (string) file_get_contents($root . '/src/pages/inkorg.php');
pg3(!str_contains($pageSrc, 'jeab_inkorg_mark_read('), 'HTML-träffen skriver inte läst på servern');
pg3(str_contains($pageSrc, 'data-ix-read-on-open'), 'tråd-JSON kan be klienten markera läst');
pg3(str_contains($pageSrc, "if (\$part === 'all' && function_exists('jeab_inkorg_fast_counts_ready'))"), 'ix_part=all läser räknarcachen');
pg3(!str_contains($pageSrc, "\$part === 'all' && function_exists('jeab_inkorg_fast_counts_refresh')"), 'ix_part=all räknar inte om');

unset($_GET['sida'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
$_GET['id'] = 't080';
$html = (string) include $root . '/src/pages/inkorg.php';
pg3(str_contains($html, 'data-ix-read-on-open="1"') && str_contains($html, 'data-ix-open="t080"'), 'HTML-träffen är oläst och ber klienten markera läst');
$meta = $store->threadsByIds(['t080']);
$read = is_array($meta['t080'] ?? null) ? (array) ($meta['t080']['read'] ?? []) : [];
pg3(($read['SamL'] ?? '') === '', 'HTML-GET skriver inte read-kartan, fick ' . json_encode($read));

$pipes = [];
$proc = proc_open([PHP_BINARY, __FILE__, 'all', $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
pg3(is_resource($proc), 'ix_part=all startade');
$out = (string) stream_get_contents($pipes[1]);
$err = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
pg3($code === 0, 'ix_part=all avslutade rent ' . trim($err));
$jsonAt = strpos($out, '{');
$payload = json_decode($jsonAt === false ? '' : substr($out, $jsonAt), true);
pg3(is_array($payload) && ($payload['ok'] ?? false) === true, 'ix_part=all är JSON');
$globals = json_decode((string) @file_get_contents($tmp . '/all-globals.json'), true);
pg3(is_array($globals) && (int) ($globals['allow'] ?? 1) === 0 && (int) ($globals['rebuild'] ?? 1) === 0, 'ix_part=all sätter inte ombyggnad');

echo "ALLA OK\n";
