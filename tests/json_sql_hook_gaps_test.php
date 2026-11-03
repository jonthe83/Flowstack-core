<?php

declare(strict_types=1);

/**
 * De tre torra krokarna efter #1014. Aktivt lager är SQL:
 * vyer utan migrate-registret, inkorgsrader i inkorg_contacts,
 * fellogg i log_lines utan id-kolumn, raderade ordrar i json_docs.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-hook-gaps-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function gap(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

gap(!function_exists('jeab_migrate_inkorg_views_module'), 'vy-modulen är inte laddad före kroken');
require dirname(__DIR__) . '/src/inkorg_views_sql.php';
file_put_contents($tmp . '/inkorg/rules.json', json_encode([
    'views' => [
        ['id' => 'v-live', 'name' => 'Live', 'filter' => ['status' => ['open']], 'priority' => 4],
    ],
], JSON_UNESCAPED_UNICODE));
$rulesHash = hash('sha256', (string) file_get_contents($tmp . '/inkorg/rules.json'));
$view = jeab_inkorg_views_sql_resync([]);
gap(($view['ok'] ?? false) === true && ($view['error'] ?? '') === '' && ($view['dry'] ?? false) === true, 'vyer dry når SQL utan förladdad modul');
gap((int) ($view['json_rows'] ?? 0) === 1 && (int) ($view['would_insert'] ?? 0) === 1 && ($view['written'] ?? true) === false, 'vyer dry räknar raden och skriver inte');
gap(hash('sha256', (string) file_get_contents($tmp . '/inkorg/rules.json')) === $rulesHash, 'vyer dry lämnar rules.json');

require dirname(__DIR__) . '/src/inkorg_contact_create.php';
$log = new PDO('sqlite::memory:');
$log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$log->exec('CREATE TABLE log_lines (line_key TEXT PRIMARY KEY, kind TEXT NOT NULL, line_hash TEXT NOT NULL, at TEXT NOT NULL, payload TEXT NOT NULL)');
$payloads = [
    ['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => 'c-sqlline01'],
    ['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => 'c-owners001'],
    ['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => 'c-ab12'],
    ['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => 'c-fileonly'],
];
$ins = $log->prepare('INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES (?, ?, ?, ?, ?)');
foreach ($payloads as $i => $row) {
    $line = json_encode($row, JSON_UNESCAPED_UNICODE);
    $ins->execute(['k' . $i, 'app_error', hash('sha256', (string) $line), '2026-10-30 12:00:0' . $i, $line]);
}
$GLOBALS['_JEAB_LOG_PDO'] = $log;
$decoded = jeab_log_lines_decoded('app_error');
gap(is_array($decoded) && count($decoded) === 4, 'fellogg utan id-kolumn går att läsa');

$pdo = jeab_store()->pdo();
$putInbox = $pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, email, updated_at) VALUES (?, ?, ?, ?)');
$putInbox->execute([
    'c-sqlline01',
    json_encode(['id' => 'c-sqlline01', 'name' => 'Sql', 'emails' => ['sql@example.test']], JSON_UNESCAPED_UNICODE),
    'sql@example.test',
    '2026-10-01 10:00:00',
]);
$putInbox->execute([
    'c-owners001',
    json_encode(['id' => 'c-owners001', 'owners' => [['name' => 'Ägare', 'email' => 'agare@example.test', 'phone' => '']]], JSON_UNESCAPED_UNICODE),
    '',
    '2026-10-01 10:00:00',
]);
$putInbox->execute([
    'c-ab12',
    json_encode(['id' => 'c-ab12', 'name' => 'Kort', 'emails' => ['kort@example.test']], JSON_UNESCAPED_UNICODE),
    'kort@example.test',
    '2026-10-01 10:00:00',
]);
$contactsPath = jeab_inkorg_dir() . '/contacts.json';
file_put_contents($contactsPath, json_encode(['contacts' => [
    'c-fileonly' => [
        'id' => 'c-fileonly',
        'name' => 'Bara fil',
        'emails' => ['fil@example.test'],
    ],
]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$contactsHash = hash('sha256', (string) file_get_contents($contactsPath));
$dry = jeab_inkorg_contact_create_run($pdo, []);
$want = ['c-ab12', 'c-owners001', 'c-sqlline01'];
gap(($dry['dry'] ?? false) === true && (int) ($dry['found'] ?? 0) === 4 && (int) ($dry['would_create'] ?? 0) === 3 && (int) ($dry['skipped_missing'] ?? 0) === 1, 'kontakter dry läser SQL-rad, owners och kort ref');
gap(($dry['ids'] ?? null) === $want && (int) ($dry['created'] ?? 1) === 0, 'kontakter dry listar SQL-id och skapar inget');
gap(!in_array('c-fileonly', (array) ($dry['ids'] ?? []), true), 'kontakter dry läser inte contacts.json');
gap(hash('sha256', (string) file_get_contents($contactsPath)) === $contactsHash, 'kontakter dry lämnar contacts.json');
$seed = jeab_kontakter_mutate(static function (array &$contacts): int {
    return count($contacts);
});
gap((int) $seed === 0, 'kontakter dry slår inte ihop och skapar inte');

require dirname(__DIR__) . '/src/ordrar_deleted_sql_migrate.php';
$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
$GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL'] = true;
jeab_sql_doc_put($pdo, JEAB_ORDRAR_DELETED_DOC_KEY, [
    'ids' => ['42' => ['at' => '2026-03-01', 'hs' => [], 'booking_id' => 0]],
    'hs' => [],
]);
file_put_contents(jeab_ordrar_deleted_path(), json_encode(['ids' => [], 'hs' => []], JSON_UNESCAPED_UNICODE));
$deletedHash = hash('sha256', (string) file_get_contents(jeab_ordrar_deleted_path()));
$ord = jeab_ordrar_deleted_sql_migrate([]);
gap(($ord['mysql_live'] ?? false) === true && ($ord['storage'] ?? '') === 'sql' && (int) ($ord['would'] ?? 1) === 0, 'ordrar dry är sql när mysql lever och filen är tom');
gap((int) ($ord['sql_only'] ?? 0) === 1 && (int) ($ord['sql_ids'] ?? 0) === 1 && (int) ($ord['json_ids'] ?? 1) === 0, 'ordrar dry visar att id redan ligger i SQL');
gap(isset(jeab_ordrar_deleted_doc()['ids']['42']), 'sql-läget läser den raderade ordern');
gap(hash('sha256', (string) file_get_contents(jeab_ordrar_deleted_path())) === $deletedHash, 'ordrar dry lämnar json-filen');
gap(jeab_ordrar_deleted_storage_mode() === 'sql', 'DATA_BACKEND=json lämnar live-läget på sql');

$docs = new PDO('sqlite::memory:');
$docs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$docs->exec('CREATE TABLE log_lines (id INTEGER PRIMARY KEY, kind TEXT NOT NULL, at TEXT NOT NULL, payload TEXT NOT NULL)');
$docs->exec('CREATE TABLE json_docs (doc_key TEXT PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL)');
$docLines = [];
for ($i = 1; $i <= 8; $i++) {
    $docLines[] = json_encode([
        'surface' => 'inkorg_contact_create',
        'message' => 'skapades inte',
        'ref' => sprintf('c-doc%04d', $i),
    ], JSON_UNESCAPED_UNICODE);
}
$docs->prepare('INSERT INTO json_docs (doc_key, payload, updated_at) VALUES (?, ?, ?)')
    ->execute(['app_errors', implode("\n", $docLines), '2020-01-01 00:00:00']);
$GLOBALS['_JEAB_LOG_PDO'] = $docs;
$fromDocs = jeab_inkorg_contact_create_run($pdo, []);
$docDebug = is_array($fromDocs['debug'] ?? null) ? $fromDocs['debug'] : [];
gap(($fromDocs['dry'] ?? false) === true && (int) ($fromDocs['found'] ?? 0) === 8 && (int) ($fromDocs['created'] ?? 1) === 0, 'kontakter i json_docs hittas utan att skriva');
gap((int) ($docDebug['kind_app_error'] ?? -1) === 0 && (int) ($docDebug['json_docs'] ?? 0) === 8 && (int) ($docDebug['refs'] ?? 0) === 8, 'debug visar att kind är tom och json_docs har åtta');

unset($GLOBALS['_JEAB_LOG_PDO']);
$pdo->exec('DROP TABLE IF EXISTS log_lines');
$pdo->exec('CREATE TABLE log_lines (id INTEGER PRIMARY KEY, type TEXT NOT NULL, level TEXT NOT NULL, at TEXT NOT NULL, co TEXT NOT NULL, payload TEXT NOT NULL)');
$liveIns = $pdo->prepare('INSERT INTO log_lines (type, level, at, co, payload) VALUES (?, ?, ?, ?, ?)');
for ($i = 1; $i <= 8; $i++) {
    $id = sprintf('c-type%04d', $i);
    $liveIns->execute([
        'app_error',
        'error',
        '2020-01-15 08:00:00',
        'malmo',
        json_encode(['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => $id], JSON_UNESCAPED_UNICODE),
    ]);
    $putInbox->execute([
        $id,
        json_encode(['id' => $id, 'name' => 'Typ ' . $id, 'emails' => [$id . '@example.test']], JSON_UNESCAPED_UNICODE),
        $id . '@example.test',
        '2020-01-15 08:00:00',
    ]);
}
$liveIns->execute(['who_diag', 'info', '2026-10-01 00:00:00', 'jarfalla', '{"surface":"who_diag","message":"annat"}']);
$colsBefore = $pdo->query('PRAGMA table_info(log_lines)')->fetchAll(PDO::FETCH_COLUMN, 1);
$live = jeab_inkorg_contact_create_run($pdo, []);
$liveDebug = is_array($live['debug'] ?? null) ? $live['debug'] : [];
$liveIds = [];
for ($i = 1; $i <= 8; $i++) {
    $liveIds[] = sprintf('c-type%04d', $i);
}
gap(($live['dry'] ?? false) === true && (int) ($live['found'] ?? 0) === 8 && ($live['ids'] ?? null) === $liveIds && (int) ($live['would_create'] ?? 0) === 8 && (int) ($live['created'] ?? 1) === 0, 'type/level-rader hittas utan kind och utan att skriva');
gap((int) ($liveDebug['log_lines'] ?? 0) === 9 && (int) ($liveDebug['kind_app_error'] ?? -1) === 0 && (int) ($liveDebug['type_app_error'] ?? 0) === 8, 'debug visar att kind missar och type träffar');
gap((int) ($liveDebug['level_error'] ?? 0) === 8 && (int) ($liveDebug['date_window'] ?? 0) === 8 && (int) ($liveDebug['older_than_90d'] ?? 0) === 8 && (int) ($liveDebug['co'] ?? 0) === 8, 'level, datumfönster och co släpper de åtta');
gap($pdo->query('PRAGMA table_info(log_lines)')->fetchAll(PDO::FETCH_COLUMN, 1) === $colsBefore, 'kontakter dry ändrar inte log_lines-schemat');

$pdo->exec('DROP TABLE IF EXISTS inkorg_views');
$pdo->exec('CREATE TABLE inkorg_views (view_id TEXT PRIMARY KEY, name TEXT NOT NULL, position INTEGER NOT NULL, payload TEXT NOT NULL)');
if (!function_exists('jeab_inkorg_views_sql_resync')) {
    require dirname(__DIR__) . '/src/inkorg_views_sql.php';
}

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir) && !mkdir($tokenDir, 0775, true) && !is_dir($tokenDir)) {
    fwrite(STDERR, "FAIL token dir\n");
    exit(1);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-live-gaps']));
$root = dirname(__DIR__);
$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_TEST_DATA') . '/public';
if (trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')) === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'Authorization') === 0) {
            $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
        }
    }
}
require getenv('JEAB_ROOT') . '/hook.php';
PHP);
$port = 26000 + (getmypid() % 10000);
$serverLog = $tmp . '/server.log';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $srvPipes,
    $root,
    ['JEAB_TEST_DATA' => $tmp, 'JEAB_ROOT' => $root]
);
gap(is_resource($server), 'php -S startade');
$curl = static function (array $payload, string $bearer) use ($port): array {
    $cmd = [
        'curl', '-sS', '-D', '-', '--max-time', '60',
        '-X', 'POST',
        '-H', 'Content-Type: application/json',
        '-H', 'Authorization: Bearer ' . $bearer,
        '--data-binary', json_encode($payload, JSON_UNESCAPED_UNICODE),
        'http://127.0.0.1:' . $port . '/hook.php',
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 0, 'json' => []];
    }
    $raw = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $split = strpos($raw, "\r\n\r\n");
    $body = $split === false ? $raw : substr($raw, $split + 4);
    $json = json_decode($body, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : []];
};
$ready = false;
for ($i = 0; $i < 30; $i++) {
    $probe = $curl(['action' => 'data.inkorg_contact_create'], 'fel-token');
    if ($probe['status'] !== 0) {
        $ready = true;
        break;
    }
    usleep(150000);
}
gap($ready, 'php -S svarar');
$denied = $curl(['action' => 'data.inkorg_contact_create'], 'fel-token');
gap($denied['status'] === 401 && ($denied['json']['error'] ?? '') === 'Fel token.', 'curl med fel token ger 401');
$hit = $curl(['action' => 'data.inkorg_contact_create'], 'tok-live-gaps');
gap($hit['status'] === 200 && ($hit['json']['dry'] ?? false) === true && (int) ($hit['json']['found'] ?? 0) === 8 && (int) ($hit['json']['created'] ?? 1) === 0, 'curl dry hittar åtta och skapar noll');
gap((int) ($hit['json']['debug']['type_app_error'] ?? 0) === 8 && (int) ($hit['json']['debug']['kind_app_error'] ?? -1) === 0, 'curl-debug visar type mot kind');
$views = $curl(['action' => 'inkorg_views.sql_resync'], 'tok-live-gaps');
gap($views['status'] === 200 && ($views['json']['dry'] ?? false) === true && ($views['json']['would_add_columns'] ?? null) === ['filter', 'priority'], 'curl dry rapporterar would_add_columns');
$afterCols = $pdo->query('PRAGMA table_info(inkorg_views)')->fetchAll(PDO::FETCH_COLUMN, 1);
gap(!in_array('filter', $afterCols, true) && !in_array('priority', $afterCols, true), 'curl dry gör ingen ALTER');
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "json_sql_hook_gaps_test ok\n";
