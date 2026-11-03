<?php

declare(strict_types=1);

/**
 * Saknad eller tom JSON-källa får inte driva sql_resync / sql_migrate.
 * SQL-rader som redan finns ska ligga kvar.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-json-missing-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
putenv('STEG4_CUTOVER');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/calendar_log_sql.php';
require_once dirname(__DIR__) . '/src/inkorg_views_sql.php';
require_once dirname(__DIR__) . '/src/ordrar_deleted_sql_migrate.php';
require_once dirname(__DIR__) . '/src/kontakter_sql_migrate.php';
require_once dirname(__DIR__) . '/src/gi_loans_sql_migrate.php';
require_once dirname(__DIR__) . '/src/store_sql_migrate.php';
require_once dirname(__DIR__) . '/src/biz_doc_sql_migrate.php';
require_once dirname(__DIR__) . '/src/purchase_sql_migrate.php';
require_once dirname(__DIR__) . '/src/inkorg_sql_migrate.php';

function miss(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function miss_skip(array $row, string $action): bool
{
    return ($row['ok'] ?? false) === true
        && ($row['skipped'] ?? '') === 'json_source_missing'
        && ($row['action'] ?? '') === $action
        && ($row['written'] ?? true) === false
        && (int) ($row['would'] ?? 1) === 0;
}

$logPdo = new PDO('sqlite::memory:');
$logPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $logPdo;
jeab_log_pdo();
$calSql = [
    'entries' => [
        ['id' => 1, 'type' => 'booked', 'at' => '2026-10-01 09:00:00'],
        ['id' => 2, 'type' => 'cancelled', 'at' => '2026-10-02 09:00:00'],
    ],
    'backfilled' => false,
];
jeab_log_doc_put_pdo($logPdo, 'calendar_log', $calSql);
$calPath = jeab_calendar_log_path();
if (is_file($calPath)) {
    unlink($calPath);
}
$calDry = jeab_calendar_log_sql_resync([]);
miss(miss_skip($calDry, 'calendar_log.sql_resync') && (int) ($calDry['json_entries'] ?? -1) === 0 && (int) ($calDry['sql_entries'] ?? 0) === 2, 'kalender dry utan fil är no-op');
miss(count(jeab_calendar_log_read()['entries']) === 2, 'kalender dry raderar inte sql');
$calWet = jeab_calendar_log_sql_resync(['dry' => 0]);
miss(miss_skip($calWet, 'calendar_log.sql_resync') && count(jeab_calendar_log_read()['entries']) === 2, 'kalender wet utan fil skriver inte över sql');
file_put_contents($calPath, " \n");
$calBlank = jeab_calendar_log_sql_resync(['dry' => 0]);
miss(miss_skip($calBlank, 'calendar_log.sql_resync') && count(jeab_calendar_log_read()['entries']) === 2, 'kalender tom fil är no-op');
file_put_contents($calPath, json_encode(['entries' => [], 'backfilled' => false]));
$calEmpty = jeab_calendar_log_sql_resync(['dry' => 0]);
miss(miss_skip($calEmpty, 'calendar_log.sql_resync') && count(jeab_calendar_log_read()['entries']) === 2, 'kalender entries:[] skriver inte över sql');
$calRoll = jeab_calendar_log_sql_resync(['rollback' => 1, 'dry' => 0]);
miss(miss_skip($calRoll, 'calendar_log.sql_resync') && !jeab_json_sql_rollback('calendar_log_json'), 'kalender rollback utan rader sätter inte flaggan');
file_put_contents($calPath, json_encode([
    'entries' => [['id' => 9, 'type' => 'sms', 'at' => '2026-10-03 09:00:00']],
    'backfilled' => false,
], JSON_UNESCAPED_UNICODE));
$calReal = jeab_calendar_log_sql_resync([]);
miss(($calReal['skipped'] ?? '') === '' && (int) ($calReal['would'] ?? 0) === 1 && (int) ($calReal['json_entries'] ?? 0) === 1 && count(jeab_calendar_log_read()['entries']) === 2, 'kalender med rader räknar would och skriver inte i dry');
unlink($calPath);

$pdo = jeab_store()->pdo();
miss($pdo instanceof PDO, 'sqlite är öppen');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_views (view_id TEXT PRIMARY KEY, name TEXT NOT NULL, position INTEGER NOT NULL, payload TEXT NOT NULL)');
$pdo->exec("INSERT INTO inkorg_views (view_id, name, position, payload) VALUES ('v-keep', 'Kvar', 1, '{\"id\":\"v-keep\"}')");
$rulesPath = $tmp . '/inkorg/rules.json';
if (is_file($rulesPath)) {
    unlink($rulesPath);
}
$viewWet = jeab_inkorg_views_sql_resync(['dry' => 0]);
$cols = $pdo->query('PRAGMA table_info(inkorg_views)')->fetchAll(PDO::FETCH_COLUMN, 1);
miss(miss_skip($viewWet, 'inkorg_views.sql_resync') && (string) $pdo->query("SELECT name FROM inkorg_views WHERE view_id = 'v-keep'")->fetchColumn() === 'Kvar', 'vyer utan rules.json lämnar sql-raden');
miss(!in_array('filter', $cols, true) && !in_array('priority', $cols, true), 'vyer utan fil gör ingen ALTER');
file_put_contents($rulesPath, json_encode(['views' => [], 'rules' => []]));
$viewEmpty = jeab_inkorg_views_sql_resync(['dry' => 0]);
miss(miss_skip($viewEmpty, 'inkorg_views.sql_resync') && (string) $pdo->query("SELECT view_id FROM inkorg_views WHERE view_id = 'v-keep'")->fetchColumn() === 'v-keep', 'vyer med tom views-lista är no-op');
unlink($rulesPath);

jeab_sql_doc_put($pdo, JEAB_ORDRAR_DELETED_DOC_KEY, [
    'ids' => ['900002' => ['at' => '2026-02-01', 'hs' => [], 'booking_id' => 0]],
    'hs' => [],
]);
$deletedPath = jeab_ordrar_deleted_path();
if (is_file($deletedPath)) {
    unlink($deletedPath);
}
$delWet = jeab_ordrar_deleted_sql_migrate(['dry' => 0]);
$delAfter = jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY);
miss(miss_skip($delWet, 'ordrar_deleted.sql_migrate') && isset($delAfter['ids']['900002']), 'ordrar utan fil lämnar sql-id');
file_put_contents($deletedPath, json_encode(['ids' => [], 'hs' => []]));
$delEmpty = jeab_ordrar_deleted_sql_migrate(['dry' => 0]);
miss(miss_skip($delEmpty, 'ordrar_deleted.sql_migrate') && isset(jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY)['ids']['900002']), 'ordrar tomt dokument lämnar sql-id');
unlink($deletedPath);

jeab_gi_loans_sql_write(['loans' => [['id' => 'loan-keep', 'item' => 'Kvar']]]);
$loanPath = jeab_gi_loans_json_path();
if (is_file($loanPath)) {
    unlink($loanPath);
}
$loanWet = jeab_gi_loans_sql_migrate(['dry' => 0]);
miss(miss_skip($loanWet, 'gi_loans.sql_migrate') && (jeab_gi_loans_sql_read()['loans'][0]['id'] ?? '') === 'loan-keep', 'gi-lån utan fil skriver inte över sql');
if (!is_dir(dirname($loanPath))) {
    mkdir(dirname($loanPath), 0775, true);
}
file_put_contents($loanPath, json_encode(['loans' => []]));
$loanEmpty = jeab_gi_loans_sql_migrate(['dry' => 0]);
miss(miss_skip($loanEmpty, 'gi_loans.sql_migrate') && (jeab_gi_loans_sql_read()['loans'][0]['id'] ?? '') === 'loan-keep', 'gi-lån loans:[] skriver inte över sql');
unlink($loanPath);

$pdo->prepare('INSERT INTO customers (id, name, created_at) VALUES (?, ?, ?)')->execute([7, 'Kvar AB', '2026-01-01 00:00:00']);
$storePath = jeab_json_path();
if (is_file($storePath)) {
    unlink($storePath);
}
$storeWet = jeab_store_sql_migrate(['dry' => 0]);
miss(miss_skip($storeWet, 'store.sql_migrate') && (string) $pdo->query('SELECT name FROM customers WHERE id = 7')->fetchColumn() === 'Kvar AB', 'store utan fil lämnar kunden');
file_put_contents($storePath, json_encode(['customers' => [], 'bookings' => [], 'reminders' => []]));
$storeEmpty = jeab_store_sql_migrate(['dry' => 0]);
miss(miss_skip($storeEmpty, 'store.sql_migrate') && (string) $pdo->query('SELECT name FROM customers WHERE id = 7')->fetchColumn() === 'Kvar AB', 'store med tomma listor lämnar kunden');
unlink($storePath);

jeab_biz_doc_sql_write('webshop_catchup', ['date' => '2026-10-20']);
$bizPath = jeab_biz_doc_json_path('webshop_catchup');
if (is_file($bizPath)) {
    unlink($bizPath);
}
$bizWet = jeab_biz_doc_sql_migrate(['entity' => 'webshop_catchup', 'dry' => 0]);
miss(miss_skip($bizWet, 'biz_doc.sql_migrate') && (jeab_biz_doc_sql_read('webshop_catchup')['date'] ?? '') === '2026-10-20', 'biz_doc utan fil lämnar sql');
file_put_contents($bizPath, '{}');
$bizEmpty = jeab_biz_doc_sql_migrate(['entity' => 'webshop_catchup', 'dry' => 0]);
miss(miss_skip($bizEmpty, 'biz_doc.sql_migrate') && (jeab_biz_doc_sql_read('webshop_catchup')['date'] ?? '') === '2026-10-20', 'biz_doc {} lämnar sql');
unlink($bizPath);

$kept = jeab_kontakt_blank([
    'name' => 'Kvar Kontakt',
    'email' => 'kvar@example.test',
    'phone' => '0700000000',
    'street' => 'Kvarvagen 1',
    'street_key' => 'kvarvagen 1',
    'postal_code' => '11122',
    'city' => 'Teststad',
]);
$kept['id'] = 'kt_keep000000000001';
$registry = jeab_kontakter_sql_store();
$inboxStore = jeab_kontakter_sql_inbox_store();
miss($registry instanceof JeabPdoContactStore && $inboxStore instanceof JeabPdoInboxContactStore, 'kontaktlager finns');
$registry->replaceAll([$kept['id'] => $kept]);
$inboxStore->replaceAll([
    'c-keep01' => [
        'id' => 'c-keep01',
        'name' => 'Inkorg Kvar',
        'emails' => ['kvar.inkorg@example.test'],
        'mobile' => '+46700000001',
        'address' => 'Kvarvagen 1',
        'town' => 'Teststad',
        'notes' => '',
        'created_at' => '2026-01-01T12:00:00+01:00',
    ],
]);
$regPath = jeab_kontakter_path();
$inboxPath = $tmp . '/inkorg/contacts.json';
foreach ([$regPath, $inboxPath] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
$contactWet = jeab_kontakter_sql_migrate(['dry' => 0]);
miss(miss_skip($contactWet, 'kontakter.sql_migrate') && (int) ($contactWet['registry_sql'] ?? 0) === 1 && (int) ($contactWet['inbox_sql'] ?? 0) === 1, 'kontakter utan filer är no-op');
miss(isset($registry->sqlAll()[$kept['id']]) && isset($inboxStore->sqlAll()['c-keep01']), 'kontakter wet raderar inte sql');
file_put_contents($regPath, json_encode(['contacts' => []]));
file_put_contents($inboxPath, json_encode(['contacts' => new stdClass()]));
$contactEmpty = jeab_kontakter_sql_migrate(['dry' => 0]);
miss(miss_skip($contactEmpty, 'kontakter.sql_migrate') && isset($registry->sqlAll()[$kept['id']]) && isset($inboxStore->sqlAll()['c-keep01']), 'kontakter tomma filer raderar inte sql');
unlink($inboxPath);
$added = jeab_kontakt_blank([
    'name' => 'Ny Fil',
    'email' => 'ny.fil@example.test',
    'phone' => '0700000002',
    'street' => 'Nyvagen 2',
    'street_key' => 'nyvagen 2',
    'postal_code' => '22233',
    'city' => 'Teststad',
]);
$added['id'] = 'kt_add0000000000001';
file_put_contents($regPath, json_encode(['contacts' => [$kept['id'] => $kept, $added['id'] => $added]], JSON_UNESCAPED_UNICODE));
$contactPartial = jeab_kontakter_sql_migrate(['dry' => 0]);
miss(($contactPartial['skipped'] ?? '') === '' && isset($registry->sqlAll()[$added['id']]) && isset($inboxStore->sqlAll()['c-keep01']), 'saknad inkorgsfil tar inte bort inkorgskontakten');
unlink($regPath);

$purchasePath = jeab_purchase_path();
if (is_file($purchasePath)) {
    unlink($purchasePath);
}
$purchaseWet = jeab_purchases_sql_migrate(['dry' => 0]);
miss(miss_skip($purchaseWet, 'purchases.sql_migrate'), 'purchases utan fil är no-op');
file_put_contents($purchasePath, json_encode(['880001' => ['reminder_id' => 880001, 'kind' => 'sol', 'customer_name' => 'Fil']]));
$purchaseKept = jeab_purchases_sql_migrate(['dry' => 0]);
miss(($purchaseKept['error'] ?? '') === 'retired: SQL is sole source' && ($purchaseKept['skipped'] ?? '') === '', 'purchases med fil importerar inte');
unlink($purchasePath);

$inboxWet = jeab_inkorg_sql_migrate_batch(['dry' => 0]);
miss(miss_skip($inboxWet, 'inbox.sql_migrate'), 'inbox utan filer är no-op');
$inboxAll = jeab_inkorg_sql_migrate_all(['dry' => 0]);
miss(miss_skip($inboxAll, 'inbox.sql_migrate'), 'inbox all utan filer är no-op');
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => [['id' => 'm-1', 'body' => 'Hej']]]));
$inboxFile = jeab_inkorg_sql_migrate_hook(['dry' => 0]);
miss(($inboxFile['error'] ?? '') === 'retired: SQL is sole source' && ($inboxFile['skipped'] ?? '') === '', 'inbox med fil skriver inte från json');
unlink($tmp . '/inkorg/messages.json');

echo "sql_json_source_missing_test ok\n";
