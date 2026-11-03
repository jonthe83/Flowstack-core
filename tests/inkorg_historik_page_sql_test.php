<?php

declare(strict_types=1);

/**
 * Historik och inkorg.threads ska läsa en sida plus COUNT.
 * Inte alla trådar, inte alla mejl, inte hela listans row_json.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-histpage-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
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

function pageok(bool $ok, string $message): void
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
$_SESSION['jeab_email'] = 'demo@example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$pdo = jeab_pdo_connect_sqlite();
pageok(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan');
$store = jeab_inkorg_pdo_store();
pageok($store instanceof JeabPdoInkorgStore, 'SQL-lager');

$fat = str_repeat('Lång historikrad med text som inte ska läsas för hela arkivet. ', 40);
$n = 2000;
$pdo->beginTransaction();
for ($i = 1; $i <= $n; $i++) {
    $id = sprintf('h%04d', $i);
    $name = $i === 7 ? 'Uniknål Kund' : 'Arkiv ' . $i;
    $store->saveListRow([
        'id' => $id,
        'company' => 'jarfalla',
        'status' => 'closed',
        'deleted' => false,
        'assignee' => $i === 3 ? 'other-id' : ($i === 4 ? 'elin-id' : ''),
        'updated_at' => gmdate('c', strtotime('2020-01-01 00:00:00 UTC') + $i * 60),
        'customer_email' => 'arkiv' . $i . '@exempel.test',
        'customer_name' => $name,
        'labels' => ['type' => 'Information'],
        'tags' => [],
        'search_hay' => mb_strtolower($name . "\n" . $fat),
        'list_snippet' => $name,
        'padding' => $fat,
    ]);
}
for ($i = 1; $i <= 30; $i++) {
    $store->saveListRow([
        'id' => sprintf('o%03d', $i),
        'company' => 'jarfalla',
        'status' => 'open',
        'deleted' => false,
        'assignee' => '',
        'updated_at' => gmdate('c', strtotime('2024-06-01 00:00:00 UTC') + $i),
        'customer_email' => 'oppen' . $i . '@exempel.test',
        'customer_name' => 'Öppen ' . $i,
        'labels' => ['type' => 'Support'],
        'search_hay' => 'öppen',
        'list_snippet' => 'öppen',
    ]);
}
$pdo->commit();

$importId = 'hsi-' . substr(hash('sha256', 'hist-page-new'), 0, 16);
$dupId = 'hsi-' . substr(hash('sha256', 'hist-page-dup'), 0, 16);
$store->saveListRow([
    'id' => $dupId,
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2021-05-01T00:00:00+00:00',
    'customer_email' => 'dup@exempel.test',
    'customer_name' => 'Dubblett Lista',
    'labels' => ['type' => 'Information'],
    'search_hay' => 'dubblett lista',
    'list_snippet' => 'dubblett',
]);
pageok(jeab_hs_import_index_append([
    'id' => $importId,
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'closed_at' => '2099-01-02T00:00:00+00:00',
    'updated_at' => '2099-01-02T00:00:00+00:00',
    'from' => 'nyast@exempel.test',
    'from_name' => 'Nyast Import',
    'subject' => 'Nyast importärende',
    'import_source' => 'helpscout_import',
    'customer_name' => 'Nyast Import',
    'search_hay' => 'nyast importärende',
]), 'ny import rad');
pageok(jeab_hs_import_index_append([
    'id' => $dupId,
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'closed_at' => '2099-01-03T00:00:00+00:00',
    'updated_at' => '2099-01-03T00:00:00+00:00',
    'from' => 'dup@exempel.test',
    'from_name' => 'Dubblett',
    'subject' => 'Dubblett',
    'import_source' => 'helpscout_import',
]), 'import med samma id som listan');
$store->setListStamp($store->listFingerprint());

$GLOBALS['_JEAB_LIST_HISTORIK_FULL'] = 0;
$GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] = 0;
$GLOBALS['_JEAB_INKORG_THREADS_ALL'] = 0;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
$GLOBALS['_JEAB_HISTORIK_KEY_LIMIT'] = 0;
$t0 = microtime(true);
$listed = jeab_inkorg_fast_historik_listed($store, [
    'folder' => 'historik',
    'company' => 'jarfalla',
    'q' => '',
], 1, 20);
$elapsed = microtime(true) - $t0;
pageok($elapsed < 2, 'första Historik-sidan tar ' . round($elapsed, 3) . ' s');
pageok((int) $listed['total'] === $n + 2, 'antalet är listan plus importen, inte dubbletten: ' . (int) $listed['total']);
$ids = array_map(static fn($row): string => is_array($row) ? (string) ($row['id'] ?? '') : '', $listed['rows']);
pageok(count($listed['rows']) === 20, 'sidan har 20 rader, fick ' . count($listed['rows']));
pageok(in_array($importId, $ids, true), 'nyaste importraden ligger på första sidan');
pageok(!in_array('h0001', $ids, true), 'äldsta raden ligger inte på första sidan');
pageok(!in_array('o001', $ids, true), 'öppna rader ligger inte i Historik');
pageok((int) $GLOBALS['_JEAB_LIST_HISTORIK_FULL'] === 0, 'listHistorik läser inte alla row_json');
pageok((int) $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] === 0, 'sidan läser inte alla mejl');
pageok((int) $GLOBALS['_JEAB_INKORG_THREADS_ALL'] === 0, 'sidan läser inte alla trådar');
pageok((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'sidan läser inte hela importarkivet');
pageok((int) $GLOBALS['_JEAB_HISTORIK_KEY_LIMIT'] > 0 && (int) $GLOBALS['_JEAB_HISTORIK_KEY_LIMIT'] <= 40, 'nycklarna hämtas med LIMIT');

$needle = $store->countHistorik('jarfalla', '', '', 'uniknål');
pageok($needle === 1, 'sök uniknål ger en träff, fick ' . $needle);
$needleRows = $store->listHistorikPage('jarfalla', 20, 0, '', '', 'uniknål');
pageok(count($needleRows) === 1 && (string) ($needleRows[0]['id'] ?? '') === 'h0007', 'söksidan är h0007');
$others = $store->countHistorik('jarfalla', 'others', 'elin-id');
pageok($others === 1, 'ägare others utelämnar mig och otilldelade, fick ' . $others);
$otherRows = $store->listHistorikPage('jarfalla', 20, 0, 'others', 'elin-id');
pageok(count($otherRows) === 1 && (string) ($otherRows[0]['id'] ?? '') === 'h0003', 'others-sidan är h0003');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik', 'scope' => 'allas'];
$GLOBALS['_JEAB_LIST_HISTORIK_FULL'] = 0;
$t0 = microtime(true);
$hist = jeab_inkorg_fast_prepare(true, 'list');
pageok(microtime(true) - $t0 < 2, 'vyn Historik tar ' . round(microtime(true) - $t0, 3) . ' s');
pageok((int) $hist['total'] === $n + 2, 'vyn räknar ' . (int) $hist['total']);
pageok(substr_count((string) $hist['html'], 'class="item ix-it') === 20, 'vyn ritar 20 rader');
pageok(str_contains((string) $hist['html'], 'data-ix-id="' . $importId . '"'), 'vyn visar importraden');
pageok(!str_contains((string) $hist['html'], 'data-ix-id="h0001"'), 'vyn visar inte den äldsta');
pageok((int) $GLOBALS['_JEAB_LIST_HISTORIK_FULL'] === 0, 'vyn anropar inte listHistorik');

$_GET['q'] = 'uniknål';
$seek = jeab_inkorg_fast_prepare(true, 'list');
pageok((int) $seek['total'] === 1, 'vyns sök har en träff, fick ' . (int) $seek['total']);
pageok(str_contains((string) $seek['html'], 'Uniknål Kund'), 'vyns sök visar Uniknål');
pageok(!str_contains((string) $seek['html'], 'Arkiv 1'), 'vyns sök tar inte med Arkiv 1');

for ($i = 1; $i <= 120; $i++) {
    $id = sprintf('t%03d', $i);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga ' . $i,
        'body' => $fat,
        'received_at' => gmdate('c', strtotime('2024-01-01 00:00:00 UTC') + $i * 60),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => $i === 2 ? 'Support' : 'Ny lead'],
    ]);
    $store->upsertThread($id, [
        'status' => $i === 5 ? 'closed' : 'open',
        'tags' => [],
        'assignee' => $i === 9 ? 'Elin' : '',
        'company' => 'jarfalla',
        'customer_email' => 'kund' . $i . '@exempel.test',
        'customer_name' => 'Kund ' . $i,
        'updated_at' => gmdate('c', strtotime('2024-01-01 00:00:00 UTC') + $i * 60),
    ]);
}
$GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] = 0;
$GLOBALS['_JEAB_INKORG_THREADS_ALL'] = 0;
$GLOBALS['_JEAB_INKORG_THREAD_PAGE'] = 0;
$t0 = microtime(true);
$list = jeab_inkorg_threads_list_hook([
    'mailbox' => 'jarfalla',
    'status' => 'open',
    'limit' => 100,
    'offset' => 0,
]);
pageok(microtime(true) - $t0 < 2, 'inkorg.threads 100 tar ' . round(microtime(true) - $t0, 3) . ' s');
pageok(($list['ok'] ?? false) === true, 'inkorg.threads svarar');
pageok((int) ($list['total'] ?? 0) === 119, 'öppna trådar är 119, fick ' . (int) ($list['total'] ?? 0));
pageok(count((array) ($list['threads'] ?? [])) === 100, 'sidan är 100 trådar');
pageok((string) (($list['threads'][0]['thread_id'] ?? '')) === 't120', 'nyaste öppna tråden är först');
pageok((int) $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] === 0, 'inkorg.threads läser inte alla mejl');
pageok((int) $GLOBALS['_JEAB_INKORG_THREADS_ALL'] === 0, 'inkorg.threads bygger inte alla trådar');
pageok((int) $GLOBALS['_JEAB_INKORG_THREAD_PAGE'] === 1, 'sidan kommer från SQL');
$owned = jeab_inkorg_threads_list_hook([
    'mailbox' => 'jarfalla',
    'status' => 'open',
    'owner' => 'Elin',
    'limit' => 20,
]);
pageok((int) ($owned['total'] ?? 0) === 1 && (string) (($owned['threads'][0]['thread_id'] ?? '')) === 't009', 'ägare Elin är t009');
$closed = jeab_inkorg_threads_list_hook([
    'mailbox' => 'jarfalla',
    'status' => 'closed',
    'limit' => 20,
]);
pageok((int) ($closed['total'] ?? 0) === 1 && (string) (($closed['threads'][0]['thread_id'] ?? '')) === 't005', 'stängd är t005');

$pdo->exec('DROP INDEX IF EXISTS inkorg_threads_status_at');
$pdo->exec('DROP INDEX IF EXISTS jeab_hs_import_list_page');
$pdo->exec('DROP INDEX IF EXISTS inkorg_list_company_status_at');
pageok(!in_array('inkorg_threads_status_at', jeab_existing_index_names($pdo, 'inkorg_threads', 'sqlite'), true), 'trådindexet är borta');
pageok(jeab_settings_value($pdo, 'sqlite', 'inkorg_schema_v2') === 'ok', 'schemaflaggan är redan ok');
pageok(jeab_settings_value($pdo, 'sqlite', 'inkorg_ddl_v3') === 'ok', 'ddl-flaggan är redan ok');
$idxRunner = $tmp . '/idx.php';
file_put_contents($idxRunner, <<<'PHP'
<?php
$tmp = getenv('JEAB_PAGE_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
require $root . '/src/bootstrap.php';
$pdo = jeab_pdo_connect_sqlite();
if (getenv('JEAB_PAGE_RESET_DDL') === '1') {
    foreach (['inkorg_ddl_v3', 'inkorg_open_indexes_v1', 'inkorg_list_status_v1', 'hs_import_ddl_v1'] as $key) {
        $pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute([$key]);
    }
}
jeab_sql_stats_reset();
jeab_ensure_inkorg_tables($pdo, 'sqlite');
$schema = false;
foreach (jeab_sql_stats()['log'] as $sql) {
    if (preg_match('/\b(SHOW\s+INDEX|SHOW\s+COLUMNS|CREATE\s+|ALTER\s+TABLE|PRAGMA\s+table_info|sqlite_master)\b/i', (string) $sql) === 1) {
        $schema = true;
    }
}
$names = array_merge(
    jeab_existing_index_names($pdo, 'inkorg_threads', 'sqlite'),
    jeab_existing_index_names($pdo, 'inkorg_list', 'sqlite'),
    jeab_existing_index_names($pdo, 'jeab_hs_import_index', 'sqlite')
);
echo json_encode(['names' => array_values(array_unique($names)), 'schema' => $schema]);
PHP);
$proc = proc_open(
    [PHP_BINARY, $idxRunner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['JEAB_PAGE_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
$idx = json_decode((string) $out, true);
pageok($code === 0 && is_array($idx), 'index-barn svarar ' . trim((string) $err));
$idxNames = is_array($idx['names'] ?? null) ? $idx['names'] : [];
pageok(empty($idx['schema']), 'flaggan ok ger ingen CREATE eller SHOW INDEX');
pageok(!in_array('inkorg_threads_status_at', $idxNames, true), 'status+datum skapas inte om när ddl-flaggan är ok');
pageok(!in_array('inkorg_list_company_status_at', $idxNames, true), 'listindexet skapas inte om');
pageok(!in_array('jeab_hs_import_list_page', $idxNames, true), 'importens sidindex skapas inte om');
$redo = proc_open(
    [PHP_BINARY, $idxRunner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $redoPipes,
    null,
    ['JEAB_PAGE_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0', 'JEAB_PAGE_RESET_DDL' => '1']
);
$redoOut = stream_get_contents($redoPipes[1]);
$redoErr = stream_get_contents($redoPipes[2]);
fclose($redoPipes[1]);
fclose($redoPipes[2]);
$redoCode = proc_close($redo);
$redoIdx = json_decode((string) $redoOut, true);
pageok($redoCode === 0 && is_array($redoIdx), 'index kan skapas igen ' . trim((string) $redoErr));
$redoNames = is_array($redoIdx['names'] ?? null) ? $redoIdx['names'] : [];
pageok(in_array('inkorg_threads_status_at', $redoNames, true), 'status+datum skapas när ddl-flaggan tas bort');
pageok(in_array('inkorg_list_company_status_at', $redoNames, true), 'listindexet skapas när ddl-flaggan tas bort');
pageok(in_array('jeab_hs_import_list_page', $redoNames, true), 'importens sidindex skapas när ddl-flaggan tas bort');

echo "inkorg historik page sql: ok\n";
