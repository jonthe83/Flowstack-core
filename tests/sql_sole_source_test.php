<?php

declare(strict_types=1);

/**
 * Inkorg och ordrar läser bara SQL. JSON-filerna rörs inte.
 */

$root = dirname(__DIR__);
$down = sys_get_temp_dir() . '/jeab-sole-down-' . bin2hex(random_bytes(4));
mkdir($down . '/inkorg', 0775, true);
$keep = '{"messages":[{"id":"keep"}]}';
file_put_contents($down . '/inkorg/messages.json', $keep);
file_put_contents($down . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $down . '/app.sqlite', 'json_path' => $down . '/store.json'],
], true) . ";\n");
$runner = $down . '/down.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_FELLOGG_DIR'] = $argv[1];
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
require $argv[2] . '/src/bootstrap.php';
$fail = static function (string $fn): string {
    try {
        $fn();
        return 'open';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
echo $fail('jeab_inkorg_messages') . "\n";
echo $fail('jeab_inkorg_threads_meta') . "\n";
echo $fail('jeab_purchase_all') . "\n";
echo is_file($argv[1] . '/inkorg/threads.json') ? "threads-created\n" : "threads-absent\n";
echo is_file($argv[1] . '/purchases.json') ? "purchases-created\n" : "purchases-absent\n";
echo (string) file_get_contents($argv[1] . '/inkorg/messages.json') . "\n";
$log = is_file($argv[1] . '/app-errors.jsonl') ? (string) file_get_contents($argv[1] . '/app-errors.jsonl') : '';
echo str_contains($log, 'SQL saknas') ? "fellogg\n" : "no-fellogg\n";
echo substr_count($log, 'SQL saknas') >= 1 ? "fellogg-lines\n" : "fellogg-empty\n";
PHP);
$proc = proc_open(
    [PHP_BINARY, $runner, $down, $root],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$stdout = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$stderr = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
if (is_resource($proc)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
} else {
    $code = 1;
}

function sole(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

sole($code === 0, 'sql-nere-processen avslutas');
if ($code !== 0) {
    fwrite(STDERR, $stderr);
}
$lines = array_values(array_filter(explode("\n", trim($stdout)), static fn(string $line): bool => $line !== ''));
sole(($lines[0] ?? '') === 'SQL saknas' && ($lines[1] ?? '') === 'SQL saknas' && ($lines[2] ?? '') === 'SQL saknas', 'sql nere ger SQL saknas');
sole(($lines[3] ?? '') === 'threads-absent' && ($lines[4] ?? '') === 'purchases-absent', 'sql nere skapar inga json-filer');
sole(($lines[5] ?? '') === $keep, 'befintlig meddelandefil ändras inte');
sole(($lines[6] ?? '') === 'fellogg', 'fellogg får en rad');

$tmp = sys_get_temp_dir() . '/jeab-sole-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';

$decoyMessages = $tmp . '/inkorg/messages.json';
$decoyThreads = $tmp . '/inkorg/threads.json';
$decoyPurchases = $tmp . '/purchases.json';
file_put_contents($decoyMessages, '{"messages":[{"id":"decoy"}]}');
file_put_contents($decoyThreads, '{"threads":{"decoy":{"contact_id":"kt_decoy"}}}');
file_put_contents($decoyPurchases, '[{"reminder_id":9,"contact_id":"kt_decoy"}]');
$before = [
    $decoyMessages => hash('sha256', (string) file_get_contents($decoyMessages)),
    $decoyThreads => hash('sha256', (string) file_get_contents($decoyThreads)),
    $decoyPurchases => hash('sha256', (string) file_get_contents($decoyPurchases)),
];
jeab_inkorg_locked('messages', static function (?array $doc): array {
    unset($doc);

    return [null, ['messages' => [[
        'id' => 'm-sole',
        'thread_id' => 'th-sole',
        'from' => 'sole@example.test',
        'subject' => 'Syntet',
        'body' => 'Hej.',
        'received_at' => '2026-04-01T08:00:00+02:00',
        'source' => 'bridge',
    ]]]];
});
jeab_inkorg_thread_write('th-sole', static function (array $row): array {
    $row['contact_id'] = 'kt_sole';
    $row['status'] = 'open';

    return $row;
});
unset($GLOBALS['_JEAB_INKORG_MSGS'], $GLOBALS['_JEAB_INKORG_MSGS_FP'], $GLOBALS['_JEAB_INKORG_META'], $GLOBALS['_JEAB_INKORG_META_FP']);
$messages = jeab_inkorg_messages();
$meta = jeab_inkorg_threads_meta();
sole(count($messages) === 1 && ($messages[0]['id'] ?? '') === 'm-sole', 'meddelanden läses från sql');
sole(($meta['th-sole']['contact_id'] ?? '') === 'kt_sole', 'trådar läses från sql');
$saved = jeab_purchase_put([
    'reminder_id' => 41,
    'contact_id' => 'kt_link',
    'kind' => 'laddbox',
    'customer_name' => 'Syntet',
    'company' => 'jeab',
]);
sole((int) ($saved['reminder_id'] ?? 0) === 41, 'ordern skrivs i sql');
$loaded = jeab_purchase_all();
sole(count($loaded) === 1 && (int) ($loaded['41']['reminder_id'] ?? 0) === 41, 'ordern läses från sql');
foreach ($before as $path => $hash) {
    sole(is_file($path) && hash('sha256', (string) file_get_contents($path)) === $hash, 'sql-vägen lämnar ' . basename($path));
}

jeab_kontakter_mutate(static function (array &$contacts): void {
    $contacts['kt_sole'] = ['id' => 'kt_sole', 'owners' => [], 'source' => ''];
    $contacts['kt_link'] = ['id' => 'kt_link', 'owners' => [], 'source' => ''];
});
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => [
    'kt_json_only' => ['id' => 'kt_json_only', 'owners' => [], 'source' => ''],
]], JSON_UNESCAPED_UNICODE));
unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
$stats = jeab_kontakter_stats();
sole(($stats['ok'] ?? false) === true && (int) ($stats['by_source']['inbox'] ?? 0) === 1, 'kontakter.stats läser sql');
sole((int) ($stats['by_source']['link'] ?? 0) === 1, 'orderns kontakt räknas från sql');

$inboxCopy = jeab_migrate_copy(jeab_migrate_find('inbox') ?? [], false);
$purchaseCopy = jeab_migrate_copy(jeab_migrate_find('purchases') ?? [], false);
sole(!empty($inboxCopy['ok']) && ($inboxCopy['error'] ?? '') === '', 'inkorgsmigreringen tar med json-rader som saknas');
sole(!empty($purchaseCopy['ok']) && ($purchaseCopy['error'] ?? '') === '', 'ordermigreringen tar med json-rader som saknas');
sole(jeab_migrate_read_source(jeab_migrate_find('inbox') ?? []) !== [], 'källäsningen ser json-filen');
$threw = false;
try {
    jeab_migrate_write_json(jeab_migrate_find('purchases') ?? [], []);
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'retired: SQL is sole source';
}
sole($threw, 'json-skrivning är avstängd');
sole(hash('sha256', (string) file_get_contents($decoyMessages)) === $before[$decoyMessages], 'avstängd migrering rör inte filen');
require_once $root . '/src/migrate/orders.php';
require_once $root . '/src/purchase_sql_migrate.php';
$orders = jeab_migrate_orders_migrate([]);
sole(($orders['error'] ?? '') === 'retired: SQL is sole source' && !empty($orders['skipped']), 'orders-modulen är avstängd');
sole((jeab_inkorg_sql_verify([])['error'] ?? '') === 'retired: SQL is sole source', 'inbox.sql_verify är avstängd');
sole((jeab_purchases_sql_cutover([])['error'] ?? '') === 'retired: SQL is sole source', 'purchases.sql_cutover är avstängd');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-sole']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_data_storage_status_dispatch([]);
sole(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'storage_status kräver token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-sole';
$status = jeab_data_storage_status_dispatch([]);
sole(!empty($status['ok']) && !empty($status['sql_reachable']), 'storage_status når sql');
$types = is_array($status['types'] ?? null) ? $status['types'] : [];
foreach (['inbox', 'inbox_threads', 'purchases', 'orders'] as $id) {
    $row = is_array($types[$id] ?? null) ? $types[$id] : [];
    sole(array_key_exists('json_runtime', $row) && $row['json_runtime'] === false && !empty($row['sql_ok']), $id . ' är sql utan json-körning');
}
sole((int) ($types['inbox']['sql_rows'] ?? 0) === 2 && (int) ($types['purchases']['sql_rows'] ?? 0) === 2, 'radantalet kommer från sql och saknade json-rader');
$contacts = is_array($types['contacts'] ?? null) ? $types['contacts'] : [];
sole(($contacts['json_runtime'] ?? null) === true && isset($contacts['storage']), 'övriga typer redovisar sitt läge');
$encoded = json_encode($status, JSON_UNESCAPED_UNICODE);
sole(is_string($encoded) && !str_contains($encoded, 'sole@example.test') && !str_contains($encoded, 'Syntet'), 'status innehåller ingen kunddata');

$allow = [
    'src/migrate/inbox.php',
    'src/migrate/inbox_threads.php',
    'src/migrate/purchases.php',
    'src/migrate/orders.php',
    'src/inkorg_sql_migrate.php',
    'src/purchase_sql_migrate.php',
];
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $rel = 'src/' . substr($file->getPathname(), strlen($root . '/src/'));
    $rel = str_replace('\\', '/', $rel);
    if (in_array($rel, $allow, true)) {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    foreach (['threads.json', 'messages.json', 'purchases.json'] as $needle) {
        if (str_contains($text, $needle)) {
            $hits[] = $rel . ' ' . $needle;
        }
    }
}
sole($hits === [], 'runtime nämner inte json-filerna');

echo "OK  sql är enda källan\n";
