<?php

declare(strict_types=1);

/**
 * SQL-läge utan tabell. Läsare tar JSON i stället för tomt, och boot skapar tabellen när schemat går att köra.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-json-fallback-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
putenv('STEG4_CUTOVER');
$passwords = $tmp . '/passwords-admin.json';
file_put_contents($passwords, json_encode([
    'secret' => 'fallback-secret',
    'hashes' => [['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test']],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":[]}');
file_put_contents($tmp . '/inkop-mallar.json', json_encode([
    'ink_1' => ['id' => 'ink_1', 'name' => 'Testmall', 'kind' => 'batteri', 'lines' => []],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/solkoll.json', json_encode(['api_url' => 'https://solkoll.example.test'], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/rules.json', json_encode([
    'views' => [['id' => 'v-test', 'name' => 'Testvy', 'filter' => []]],
    'rules' => [],
    'lists' => [],
    'tags' => [],
    'views_v3' => '1',
    'views_v4' => '1',
    'views_v5' => '1',
    'views_v6' => '1',
    'views_v7' => '1',
    'views_v8' => '1',
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/sent-new.json', json_encode(['sent' => [[
    'at' => '2026-05-01T12:00:00+02:00',
    'by' => 'Test',
    'company' => 'jarfalla',
    'from' => 'kontakt@example.test',
    'to' => 'kund@example.test',
    'subject' => 'Redan skickat',
    'send_key' => 'aabbccdd',
]]], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

function fb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fb_table(PDO $pdo, string $table): bool
{
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table));

    return $stmt instanceof PDOStatement && (bool) $stmt->fetchColumn();
}

$pdo = jeab_data_pdo();
fb($pdo instanceof PDO, 'sqlite är öppen');
if (!$pdo instanceof PDO) {
    exit(1);
}
fb(jeab_data_backend_set('mysql') && jeab_cutover_sql('inkop_mallar') && jeab_cutover_sql('inkorg_views') && jeab_cutover_sql('solkoll') && jeab_inbox_sent_new_sql(), 'lagringen är sql');

$mallar = jeab_inkop_load();
fb(isset($mallar['ink_1']) && $mallar['ink_1']['name'] === 'Testmall', 'boot läser inköpsmallen');
fb(fb_table($pdo, 'inkop_mallar') && (int) $pdo->query('SELECT COUNT(*) FROM inkop_mallar')->fetchColumn() === 1, 'boot skapade inkop_mallar');

$views = jeab_inkorg_views_read();
$ids = array_map(static fn(array $view): string => (string) ($view['id'] ?? ''), $views);
fb(in_array('v-test', $ids, true), 'boot läser vyn');
fb(fb_table($pdo, 'inkorg_views'), 'boot skapade inkorg_views');

$sol = jeab_solkoll_read_json_file(jeab_solkoll_config_path());
fb(($sol['api_url'] ?? '') === 'https://solkoll.example.test', 'boot läser solkoll');
fb(fb_table($pdo, 'solkoll_docs'), 'boot skapade solkoll_docs');

fb(jeab_inbox_sent_new_usable(), 'sent-new-tabellen går att läsa');
fb(fb_table($pdo, 'inbox_sent_new') && (int) $pdo->query('SELECT COUNT(*) FROM inbox_sent_new')->fetchColumn() === 1, 'boot kopierade sent-new');

$pdo->exec('DROP TABLE inkop_mallar');
$pdo->exec('DROP TABLE inkorg_views');
$pdo->exec('DROP TABLE solkoll_docs');
$pdo->exec('DROP TABLE inbox_sent_new');
$mallar = jeab_inkop_load();
fb(isset($mallar['ink_1']) && $mallar['ink_1']['name'] === 'Testmall', 'saknad inkop-tabell faller tillbaka till json');
fb(!fb_table($pdo, 'inkop_mallar'), 'fallback skapar inte inkop-tabellen igen');
$views = jeab_inkorg_views_read();
$ids = array_map(static fn(array $view): string => (string) ($view['id'] ?? ''), $views);
fb(!in_array('v-test', $ids, true), 'saknad vy-tabell läser inte json');
fb(jeab_cutover_views_list() === null, 'oläsbar vy-lista är null');
$GLOBALS['_JEAB_JSON_SQL_ROLLBACK']['inkorg_views_json'] = true;
$views = jeab_inkorg_views_read();
$ids = array_map(static fn(array $view): string => (string) ($view['id'] ?? ''), $views);
fb(in_array('v-test', $ids, true), 'återgångsflaggan läser json-vyn');
unset($GLOBALS['_JEAB_JSON_SQL_ROLLBACK']['inkorg_views_json']);
$sol = jeab_solkoll_read_json_file(jeab_solkoll_config_path());
fb(($sol['api_url'] ?? '') === 'https://solkoll.example.test', 'saknad solkoll-tabell faller tillbaka till json');
fb(!jeab_inbox_sent_new_usable(), 'saknad sent-new-tabell är inte sql-läsbar');

jeab_auth_data(true);
jeab_code_people_save(jeab_auth_hash_fingerprint('sam-hash-fixture'), 'SamL', 'prefix', 'SamL');
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
$_SESSION['jeab_email'] = 'demo@example.test';
unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
$sent = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$sent): array {
    $sent[] = 'graph';

    return ['code' => 202, 'body' => []];
};
$dup = jeab_inkorg_send_new([
    'company' => 'jarfalla',
    'to' => 'kund@example.test',
    'subject' => 'Redan skickat',
    'body' => 'Påhittad text.',
    'send_key' => 'aabbccdd',
], 'Test');
fb(($dup['subject'] ?? '') === 'Redan skickat' && $sent === [], 'sent-new läser json när tabellen saknas');
fb(!fb_table($pdo, 'inbox_sent_new'), 'json-träffen skapar ingen sent-new-tabell');

unset($GLOBALS['_JEAB_CUTOVER_BOOT_DONE']);
$reg = jeab_migrate_registry();
foreach ($reg as $i => $module) {
    if (($module['id'] ?? '') === 'inkop_mallar') {
        $reg[$i]['schema']['sqlite'] = 'NOT SQL';
        $reg[$i]['schema']['mysql'] = 'NOT SQL';
    }
}
$GLOBALS['_JEAB_MIGRATE_REGISTRY'] = $reg;
$read = jeab_data_read('inkop_mallar');
fb(isset($read['ink_1']) && is_array($read['ink_1']), 'jeab_data_read tar json när tabellen inte går att skapa');
fb(!fb_table($pdo, 'inkop_mallar'), 'trasigt schema lämnar tabellen borta');

echo "cutover_json_fallback_test ok\n";
