<?php

declare(strict_types=1);

/**
 * sms_sent: appen skriver bara SQL och sms-sent.json är en fryst importkopia.
 * Snabbkollen kräver bara att varje JSON-rad finns i SQL. Nyare SQL-payload räknas som sql_newer, inte differ.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-smsspot-' . bin2hex(random_bytes(4));
mkdir($tmp . '/privat', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'people' => [['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla']],
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require_once $root . '/src/data_cutover_all.php';

function smsspot(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$module = jeab_migrate_find('sms_sent');
smsspot(is_array($module) && empty($module['missing']), 'sms_sent-modulen finns');
$jsonPath = jeab_sms_sent_path();
@mkdir(dirname($jsonPath), 0775, true);
file_put_contents($jsonPath, json_encode([
    '26' => ['sms' => true, 'starts_at' => '2026-10-10 08:00:00', 'email' => true],
    '95' => ['sms' => true, 'starts_at' => '2026-10-11 08:00:00'],
]));
smsspot(jeab_cutover_json_count($module) === 2, 'JSON-kopian har två rader');

$pdo = jeab_data_pdo();
smsspot($pdo instanceof PDO, 'SQL finns');
jeab_sms_store_ensure($pdo);
// Bokning 26 flyttades efter importen: appen nollställde sms och lade till flyttfält, bara i SQL.
jeab_sms_store_upsert($pdo, 26, (string) json_encode(['sms' => false, 'starts_at' => '2026-10-12 08:00:00', 'email' => true, 'move_pending' => false, 'move_starts_at' => '2026-10-12 08:00:00']));
jeab_sms_store_upsert($pdo, 95, (string) json_encode(['sms' => true, 'starts_at' => '2026-10-11 08:00:00']));
jeab_sms_store_upsert($pdo, 140, (string) json_encode(['sms' => true, 'starts_at' => '2026-10-20 08:00:00']));

$spot = jeab_cutover_spot($module);
smsspot(!empty($spot['ok']) && (int) ($spot['differ'] ?? -1) === 0, 'nyare SQL-payload ger differ 0');
smsspot(($spot['compared'] ?? '') === 'presence', 'sms_sent jämförs på närvaro');
smsspot((int) ($spot['sql_newer'] ?? 0) === 1 && ($spot['sql_newer_ids'] ?? []) === ['26'], 'raden som ändrats i SQL redovisas som sql_newer');
smsspot((int) ($spot['sql_count'] ?? 0) === 3, 'extra SQL-rader är tillåtna');

$pdo->exec('DELETE FROM sms_sent WHERE booking_id = 95');
$spot = jeab_cutover_spot($module);
smsspot(empty($spot['ok']) && (int) ($spot['differ'] ?? 0) === 1, 'saknad rad i SQL ger fortfarande differ');

smsspot(!in_array('notes', jeab_cutover_sql_live_types(), true), 'andra typer jämförs som förut');

// Inkorg-arkivet: index.jsonl får en rad per uppdatering. Räkna unika id och jämför mot SQL.
@mkdir($tmp . '/inkorg/hs-import', 0775, true);
$a = 'hsi-' . str_repeat('a', 16);
$b = 'hsi-' . str_repeat('b', 16);
$c = 'hsi-' . str_repeat('c', 16);
file_put_contents($tmp . '/inkorg/hs-import/index.jsonl', implode("\n", [
    json_encode(['id' => $a, 'list_status' => 'open']),
    json_encode(['id' => $a, 'list_status' => 'closed']),
    json_encode(['id' => $b, 'list_status' => 'closed']),
    json_encode(['id' => $b, 'list_status' => 'closed']),
    json_encode(['id' => $c, 'list_status' => 'closed']),
    '',
]) . "\n");
$scan = jeab_cutover_hs_jsonl_scan($tmp . '/inkorg/hs-import/index.jsonl');
smsspot($scan['lines'] === 5 && count($scan['ids']) === 3, 'index.jsonl: 5 rader, 3 unika id');
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => []]));
$fig = jeab_cutover_inkorg_json_figures($tmp . '/inkorg');
smsspot($fig['archive'] === 3 && $fig['historik'] === 3, 'JSON-Historik räknar unika arkiv-id');
$pdo->exec('CREATE TABLE IF NOT EXISTS jeab_hs_import_index (id TEXT PRIMARY KEY, payload TEXT NOT NULL)');
$pdo->exec("INSERT INTO jeab_hs_import_index (id, payload) VALUES ('$a', '{}'), ('$b', '{}')");
$cmp = jeab_cutover_hs_archive_compare($pdo);
smsspot($cmp['jsonl_distinct'] === 3 && $cmp['sql'] === 2 && $cmp['json_not_in_sql'] === 1 && $cmp['sample'] === [$c], 'arkiv-id som saknas i SQL räknas');

echo "ALL OK\n";
