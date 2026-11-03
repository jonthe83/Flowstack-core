<?php

declare(strict_types=1);

/** JSON→SQL för de lager som kan bytas utan omsändning. Filen lämnas kvar. */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-remain-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/privat', 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';

function rm_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rm_hash(string $path): string
{
    return is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
}

$scanPath = jeab_remain_json_path('sent_scan');
$checkPath = jeab_remain_json_path('sent_check');
$statePath = jeab_remain_json_path('graph_state');
$hsPath = jeab_remain_json_path('helpscout_import');
$malmoPath = jeab_remain_json_path('malmo_hs_cursor');
$errPath = jeab_remain_json_path('app_errors');
$errLine = json_encode([
    'time' => '2026-10-01 10:00:00',
    'surface' => 'test',
    'message' => 'påhittat fel',
    'fingerprint' => str_repeat('ab', 32),
], JSON_UNESCAPED_UNICODE);
file_put_contents($scanPath, json_encode([
    'roberto' => ['at' => time(), 'rows' => [['to' => ['a@example.test'], 'cc' => [], 'sent' => '2026-10-01']]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($checkPath, json_encode([
    'a@example.test' => ['at' => time(), 'hit' => false],
], JSON_UNESCAPED_UNICODE));
file_put_contents($statePath, json_encode(['subs' => ['jarfalla' => ['id' => 'sub-1']]], JSON_UNESCAPED_UNICODE));
file_put_contents($hsPath, json_encode([
    'page' => 4,
    'mailboxes' => ['jarfalla' => ['done' => false]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($malmoPath, json_encode([
    'watermark' => '',
    'page' => 1,
    'offset' => 0,
    'query' => '',
], JSON_UNESCAPED_UNICODE));
file_put_contents($errPath, $errLine . "\n{trasig\n");

$paths = [$scanPath, $checkPath, $statePath, $hsPath, $malmoPath, $errPath];
$before = [];
foreach ($paths as $path) {
    $before[$path] = rm_hash($path);
}

$store = jeab_store();
rm_ok($store instanceof JeabPdoStore, 'sqlite är öppen');
$pdo = $store->pdo();
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;

$deny = jeab_remain_import_dispatch(['stores' => 'sent_scan']);
rm_ok(($deny['status'] ?? 0) === 401 && ($deny['dry'] ?? 0) === 1, 'remain.import utan token är 401 och dry');

$dry = jeab_remain_import([]);
rm_ok(($dry['dry'] ?? 0) === 1 && ($dry['ok'] ?? false) === true, 'dry saknas betyder dry=1');
rm_ok((int) ($dry['stores']['sent_scan']['inserted'] ?? 0) === 1, 'dry räknar utskicksskann');
rm_ok((int) ($dry['stores']['sent_scan']['sql'] ?? 1) === 0, 'dry skriver inte utskicksskann');
rm_ok((int) ($dry['stores']['graph_state']['inserted'] ?? 0) === 1, 'dry räknar graph-läge');
rm_ok((int) ($dry['stores']['helpscout_import']['inserted'] ?? 0) === 1, 'dry räknar HS-checkpoint');
rm_ok((int) ($dry['stores']['malmo_hs_cursor']['json'] ?? 1) === 0, 'tom Malmö-markör räknas inte');
rm_ok((int) ($dry['stores']['app_errors']['inserted'] ?? 0) === 1, 'dry räknar en felloggrad');
rm_ok((int) ($dry['stores']['app_errors']['skipped'] ?? 0) >= 1, 'dry räknar trasig rad');
foreach ($paths as $path) {
    rm_ok(rm_hash($path) === $before[$path] && is_file($path), 'dry lämnar ' . basename($path));
}
rm_ok(jeab_remain_doc_get('graph_state') === null, 'dry skapar ingen graph-rad');
rm_ok(jeab_log_line_count($pdo, 'app_error') === 0, 'dry skriver ingen felloggrad');
rm_ok(!function_exists('jeab_hs_import_store_is_imported') || jeab_hs_import_store_is_imported() === false, 'dry sätter inte imported');

$wet = jeab_remain_import(['dry' => 0]);
rm_ok((int) ($wet['stores']['sent_scan']['inserted'] ?? 0) === 1, 'import skriver utskicksskann');
rm_ok((int) ($wet['stores']['sent_check']['inserted'] ?? 0) === 1, 'import skriver sent-koll');
rm_ok((int) ($wet['stores']['graph_state']['inserted'] ?? 0) === 1, 'import skriver graph-läge');
rm_ok((int) ($wet['stores']['helpscout_import']['inserted'] ?? 0) === 1, 'import skriver checkpoint');
rm_ok((int) ($wet['stores']['malmo_hs_cursor']['inserted'] ?? 0) === 0, 'tom Malmö-markör skrivs inte');
rm_ok((int) ($wet['stores']['app_errors']['inserted'] ?? 0) === 1, 'import skriver en felloggrad');
foreach ($paths as $path) {
    rm_ok(rm_hash($path) === $before[$path] && is_file($path), 'import lämnar ' . basename($path));
}
rm_ok(jeab_hs_import_store_is_imported() === false, 'checkpoint-import sätter inte imported');
$malmoEmpty = jeab_hs_import_store_malmo_get();
rm_ok($malmoEmpty === null || trim((string) ($malmoEmpty['watermark'] ?? '')) === '', 'ingen tom Malmö-rad');

$again = jeab_remain_import(['dry' => 0]);
rm_ok((int) ($again['stores']['graph_state']['inserted'] ?? 1) === 0 && (int) ($again['stores']['graph_state']['skipped'] ?? 0) === 1, 'andra importen hoppar över graph-läge');
rm_ok((int) ($again['stores']['app_errors']['inserted'] ?? 1) === 0, 'andra importen hoppar över felloggen');

file_put_contents($statePath, json_encode(['subs' => ['jarfalla' => ['id' => 'fil']]], JSON_UNESCAPED_UNICODE));
file_put_contents($scanPath, json_encode(['roberto' => ['at' => time(), 'rows' => []]], JSON_UNESCAPED_UNICODE));
file_put_contents($checkPath, json_encode(['b@example.test' => ['at' => time()]], JSON_UNESCAPED_UNICODE));
file_put_contents($hsPath, json_encode(['page' => 9, 'mailboxes' => []], JSON_UNESCAPED_UNICODE));
unset($GLOBALS['_JEAB_SENT_CHECK_MEM'], $GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
$state = jeab_graph_state();
rm_ok((string) ($state['subs']['jarfalla']['id'] ?? '') === 'sub-1', 'graph-läget läses från SQL');
$scan = jeab_auto_graph_scan_cache_get('roberto');
rm_ok(is_array($scan) && ($scan[0]['to'][0] ?? '') === 'a@example.test', 'utskicksskann läses från SQL');
unset($GLOBALS['_JEAB_SENT_CHECK_MEM']);
$check = jeab_auto_sent_check_cache_load();
rm_ok(isset($check['a@example.test']) && !isset($check['b@example.test']), 'sent-koll läses från SQL');
$cp = jeab_hs_import_checkpoint();
rm_ok((int) ($cp['page'] ?? 0) === 4, 'checkpoint läses från SQL');
rm_ok(!isset($GLOBALS['_JEAB_GRAPH_HTTP']), 'importen anropar inte Graph');

$extra = json_encode([
    'time' => '2026-10-02 11:00:00',
    'surface' => 'test',
    'message' => 'bara i filen',
    'fingerprint' => str_repeat('cd', 32),
], JSON_UNESCAPED_UNICODE);
file_put_contents($errPath, $extra . "\n", FILE_APPEND);
$seen = jeab_app_errors_read(20);
$messages = array_map(static fn(array $row): string => (string) ($row['message'] ?? ''), $seen);
rm_ok(in_array('påhittat fel', $messages, true) && !in_array('bara i filen', $messages, true), 'importerad fellogg slår inte ihop nya filrader');

$preview = jeab_remain_set_storage(['type' => 'sent_check', 'mode' => 'json']);
rm_ok(($preview['dry'] ?? 0) === 1 && ($preview['written'] ?? true) === false && jeab_remain_sql_mode('sent_check') === 'sql', 'dry storage skriver inte');

$sqlCheck = jeab_remain_doc_get('sent_check');
$back = jeab_remain_set_storage(['type' => 'sent_check', 'mode' => 'json', 'dry' => 0]);
rm_ok(($back['ok'] ?? false) === true && jeab_remain_sql_mode('sent_check') === 'json' && is_file($checkPath), 'json-läge exporterar och behåller filen');
unset($GLOBALS['_JEAB_SENT_CHECK_MEM']);
jeab_auto_sent_check_cache_save(['c@example.test' => ['at' => 1, 'hit' => false]]);
rm_ok(jeab_remain_doc_get('sent_check') === $sqlCheck, 'json-läge skriver inte över SQL');
$rolled = json_decode((string) file_get_contents($checkPath), true);
rm_ok(is_array($rolled) && isset($rolled['c@example.test']), 'json-läge skriver filen');
$forth = jeab_remain_set_storage(['type' => 'sent_check', 'mode' => 'sql', 'dry' => 0]);
unset($GLOBALS['_JEAB_SENT_CHECK_MEM']);
$restored = jeab_auto_sent_check_cache_load();
rm_ok(($forth['ok'] ?? false) === true && isset($restored['c@example.test']) && jeab_remain_sql_mode('sent_check') === 'sql', 'sql-läge tar tillbaka filen');
rm_ok(is_file($checkPath), 'filen finns kvar efter sql-läge');

file_put_contents($malmoPath, json_encode([
    'watermark' => '2026-01-01T00:00:00Z',
    'page' => 2,
    'offset' => 5,
    'query' => 'malmo',
], JSON_UNESCAPED_UNICODE));
$malmoHash = rm_hash($malmoPath);
$malmoIn = jeab_remain_import(['dry' => 0, 'stores' => 'malmo_hs_cursor']);
rm_ok((int) ($malmoIn['stores']['malmo_hs_cursor']['inserted'] ?? 0) === 1, 'Malmö-markör importeras');
rm_ok(rm_hash($malmoPath) === $malmoHash && is_file($malmoPath), 'Malmö-filen lämnas');
rm_ok((string) (jeab_malmo_hs_state()['watermark'] ?? '') === '2026-01-01T00:00:00Z', 'Malmö läses från SQL');
rm_ok(jeab_hs_import_store_is_imported() === false, 'Malmö-import sätter inte imported');
file_put_contents($malmoPath, json_encode(['watermark' => 'fil', 'page' => 1, 'offset' => 0, 'query' => ''], JSON_UNESCAPED_UNICODE));
rm_ok((string) (jeab_malmo_hs_state()['watermark'] ?? '') === '2026-01-01T00:00:00Z', 'ändrad Malmö-fil ändrar inte SQL');

$errBefore = jeab_log_line_count($pdo, 'app_error');
$errBack = jeab_remain_set_storage(['type' => 'app_errors', 'mode' => 'json', 'dry' => 0]);
rm_ok(($errBack['ok'] ?? false) === true && is_file($errPath) && jeab_remain_sql_mode('app_errors') === 'json', 'fellogg kan rullas tillbaka till filen');
jeab_log_app_error('test', 'ny filrad', ['no_webhook' => true, 'skip_request' => true, 'fingerprint' => str_repeat('ef', 32)]);
rm_ok(jeab_log_line_count($pdo, 'app_error') === $errBefore, 'json-läge lägger inte felloggen i SQL');
rm_ok(str_contains((string) file_get_contents($errPath), 'ny filrad'), 'json-läge lägger felloggen i filen');

$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PRIVAT_DIR'] = " . var_export($tmp . '/privat', true) . ";\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$port = 18080 + random_int(0, 800);
$proc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root
);
$up = false;
$deadline = microtime(true) + 5;
while (is_resource($proc) && microtime(true) < $deadline) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $up = true;
        break;
    }
    usleep(50000);
}
rm_ok(is_resource($proc) && $up, 'hook-servern lyssnar');
if ($up) {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => 'remain.import']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = is_string($body) ? json_decode($body, true) : null;
    rm_ok($code === 401 && is_array($decoded) && ($decoded['ok'] ?? true) === false, 'curl remain.import utan token är 401, fick ' . $code . ' ' . substr((string) $body, 0, 120));
}
if (is_resource($proc)) {
    proc_terminate($proc);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($proc);
}

echo "remain sql: ok\n";
