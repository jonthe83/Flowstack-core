<?php

declare(strict_types=1);

/**
 * SQL är facit för alla typer när tabellen redan har rader.
 * Saknade id läggs till. Befintliga rader skrivs inte över och raderas inte.
 * En tom SQL-tabell kräver fortfarande samma antal och checksumma.
 */

$tmp = sys_get_temp_dir() . '/jeab-sql-facit-all-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['storage' => 'sql', 'sql_writes' => true, 'lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function facit_all(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function facit_all_count(PDO $pdo, string $sql): int
{
    $stmt = $pdo->query($sql);
    return $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : -1;
}

$pdo = jeab_data_pdo();
facit_all($pdo instanceof PDO, 'sqlite finns');

$customers = jeab_migrate_find('customers');
$reminders = jeab_migrate_find('reminders');
$bookings = jeab_migrate_find('bookings');
$webshop = jeab_migrate_find('webshop_orders');
$deleted = jeab_migrate_find('ordrar_deleted');
$who = jeab_migrate_find('who_diag');
facit_all(is_array($customers) && is_array($reminders) && is_array($bookings) && is_array($webshop) && is_array($deleted) && is_array($who), 'modulerna finns');

$store = [
    'customers' => [
        ['id' => 1, 'name' => 'Ada', 'created_at' => '2026-01-01 00:00:00'],
        ['id' => 2, 'name' => 'Bea', 'created_at' => '2026-01-02 00:00:00'],
    ],
    'reminders' => [],
    'bookings' => [],
];
file_put_contents($tmp . '/store.json', json_encode($store, JSON_UNESCAPED_UNICODE));

$emptyDry = jeab_migrate_copy($customers, true);
facit_all(!empty($emptyDry['ok']) && empty($emptyDry['match']) && empty($emptyDry['preserved']) && empty($emptyDry['written']), 'tom SQL kräver fortfarande exakt likhet');

$filled = jeab_migrate_copy($customers, false);
facit_all(!empty($filled['ok']) && !empty($filled['match']) && ($filled['checksum'] ?? '') === 'match' && empty($filled['preserved']), 'tom SQL fylls och checksumman ska vara lika');
facit_all((int) ($filled['json_count'] ?? 0) === 2 && (int) ($filled['sql_count'] ?? 0) === 2, 'tom SQL räknar båda mängderna');

$pdo->exec("UPDATE customers SET name = 'Nyare Ada' WHERE id = 1");
$pdo->exec("INSERT INTO customers (id, name, created_at) VALUES (9, 'Bara Sql', '2026-03-01 00:00:00')");
$beforeCustomers = facit_all_count($pdo, 'SELECT COUNT(*) FROM customers');
$store['customers'] = [
    ['id' => 1, 'name' => 'Gammal Ada', 'created_at' => '2020-01-01 00:00:00'],
    ['id' => 3, 'name' => 'Cilla', 'created_at' => '2026-04-01 00:00:00'],
];
file_put_contents($tmp . '/store.json', json_encode($store, JSON_UNESCAPED_UNICODE));

$dryCustomers = jeab_migrate_copy($customers, true);
$nameAfterDry = (string) $pdo->query('SELECT name FROM customers WHERE id = 1')->fetchColumn();
facit_all(!empty($dryCustomers['preserved']) && empty($dryCustomers['match']) && empty($dryCustomers['written']) && $nameAfterDry === 'Nyare Ada', 'torrkörning med befintlig SQL skriver inte och saknat id är inte match');
facit_all((int) ($dryCustomers['sql_count'] ?? 0) === $beforeCustomers, 'torrkörningen räknar de ägda SQL-raderna');

$wetCustomers = jeab_migrate_copy($customers, false);
$afterCustomers = facit_all_count($pdo, 'SELECT COUNT(*) FROM customers');
$nameAda = (string) $pdo->query('SELECT name FROM customers WHERE id = 1')->fetchColumn();
$nameSql = (string) $pdo->query('SELECT name FROM customers WHERE id = 9')->fetchColumn();
$nameBea = (string) $pdo->query('SELECT name FROM customers WHERE id = 2')->fetchColumn();
$nameCilla = (string) $pdo->query('SELECT name FROM customers WHERE id = 3')->fetchColumn();
facit_all(!empty($wetCustomers['ok']) && !empty($wetCustomers['match']) && !empty($wetCustomers['preserved']) && ($wetCustomers['checksum'] ?? '') === 'preserved', 'befintlig SQL matchar på id och antal, inte checksumma');
facit_all($nameAda === 'Nyare Ada' && $nameSql === 'Bara Sql' && $nameBea === 'Bea' && $nameCilla === 'Cilla', 'äldre JSON skriver inte över, SQL-only och borttagen JSON-rad finns kvar, saknat id läggs till');
facit_all($afterCustomers >= $beforeCustomers && (int) ($wetCustomers['sql_count'] ?? 0) === $afterCustomers, 'antalet SQL-rader minskar inte');

$stamp = '2026-05-01 08:00:00';
jeab_migrate_ensure($pdo, $reminders);
$pdo->exec("INSERT INTO reminders (id, list_id, title, flagged, created_at, updated_at, ringt, pratat, mailat, smsat) VALUES (184, 1, 'Ring kunden', 0, '{$stamp}', '{$stamp}', 0, 0, 0, 0)");
$beforeReminders = facit_all_count($pdo, 'SELECT COUNT(*) FROM reminders');
$remindersCopy = jeab_migrate_copy($reminders, false);
$title = (string) $pdo->query('SELECT title FROM reminders WHERE id = 184')->fetchColumn();
facit_all(!empty($remindersCopy['ok']) && !empty($remindersCopy['match']) && !empty($remindersCopy['preserved']) && empty($remindersCopy['written']), 'tom JSON mot befintliga påminnelser är match utan skrivning');
facit_all($title === 'Ring kunden' && facit_all_count($pdo, 'SELECT COUNT(*) FROM reminders') === $beforeReminders && (int) ($remindersCopy['sql_count'] ?? 0) === $beforeReminders, 'påminnelsen finns kvar och räknas');

jeab_migrate_ensure($pdo, $bookings);
$pdo->exec("INSERT INTO bookings (id, customer_id, customer_name, assignee, starts_at, ends_at, status, created_at, updated_at) VALUES (119, 1, 'Ada', 'Elin', '{$stamp}', '{$stamp}', 'booked', '{$stamp}', '{$stamp}')");
$beforeBookings = facit_all_count($pdo, 'SELECT COUNT(*) FROM bookings');
$bookingsCopy = jeab_migrate_copy($bookings, false);
facit_all(!empty($bookingsCopy['match']) && empty($bookingsCopy['written']) && facit_all_count($pdo, 'SELECT COUNT(*) FROM bookings') === $beforeBookings, 'tom JSON raderar inte bokningen');

file_put_contents($tmp . '/webshop-orders.json', json_encode([
    'order-a' => ['total' => 1, 'note' => 'gammal'],
    'order-c' => ['total' => 3, 'note' => 'ny'],
], JSON_UNESCAPED_UNICODE));
jeab_migrate_ensure($pdo, $webshop);
$pdo->exec("INSERT INTO log_webshop_orders (order_key, payload, updated_at) VALUES ('order-a', '{\"note\":\"nyare\",\"total\":9}', '2026-10-01 00:00:00')");
$pdo->exec("INSERT INTO log_webshop_orders (order_key, payload, updated_at) VALUES ('order-b', '{\"note\":\"bara-sql\",\"total\":2}', '2026-10-01 00:00:00')");
$beforeWebshop = facit_all_count($pdo, 'SELECT COUNT(*) FROM log_webshop_orders');
$webshopDry = jeab_migrate_copy($webshop, true);
$payloadA = (string) $pdo->query("SELECT payload FROM log_webshop_orders WHERE order_key = 'order-a'")->fetchColumn();
facit_all(!empty($webshopDry['preserved']) && empty($webshopDry['match']) && empty($webshopDry['written']) && str_contains($payloadA, 'nyare'), 'webbshopens torrkörning rör inte den nyare SQL-raden');
$webshopWet = jeab_migrate_copy($webshop, false);
$payloadA = (string) $pdo->query("SELECT payload FROM log_webshop_orders WHERE order_key = 'order-a'")->fetchColumn();
$payloadB = (string) $pdo->query("SELECT payload FROM log_webshop_orders WHERE order_key = 'order-b'")->fetchColumn();
$payloadC = (string) $pdo->query("SELECT payload FROM log_webshop_orders WHERE order_key = 'order-c'")->fetchColumn();
facit_all(!empty($webshopWet['match']) && ($webshopWet['checksum'] ?? '') === 'preserved' && str_contains($payloadA, 'nyare') && str_contains($payloadB, 'bara-sql') && str_contains($payloadC, '"total":3'), 'webbshopens SQL skrivs inte över och saknat id läggs till');
facit_all(facit_all_count($pdo, 'SELECT COUNT(*) FROM log_webshop_orders') >= $beforeWebshop, 'webbshopens antal minskar inte');

file_put_contents($tmp . '/ordrar-deleted.json', json_encode([
    'ids' => ['1' => ['at' => '2020-01-01', 'hs' => [], 'booking_id' => 0]],
    'hs' => [],
], JSON_UNESCAPED_UNICODE));
jeab_migrate_ensure($pdo, $deleted);
$newerDeleted = '{"hs":{},"ids":{"1":{"at":"2026-10-01","booking_id":0,"hs":[]}}}';
$pdo->exec('INSERT INTO json_docs (doc_key, payload, updated_at) VALUES (' . $pdo->quote('ordrar_deleted') . ', ' . $pdo->quote($newerDeleted) . ", '2026-10-01 00:00:00')");
$pdo->exec("INSERT INTO json_docs (doc_key, payload, updated_at) VALUES ('gi_loans', '{\"syskon\":1}', '2026-10-01 00:00:00')");
$deletedWet = jeab_migrate_copy($deleted, false);
$deletedPayload = (string) $pdo->query("SELECT payload FROM json_docs WHERE doc_key = 'ordrar_deleted'")->fetchColumn();
$sibling = (string) $pdo->query("SELECT payload FROM json_docs WHERE doc_key = 'gi_loans'")->fetchColumn();
facit_all(!empty($deletedWet['match']) && !empty($deletedWet['preserved']) && $deletedPayload === $newerDeleted && str_contains($sibling, 'syskon'), 'delad json_docs skriver inte över doc_key och lämnar syskonraden');

file_put_contents($tmp . '/who-diag.jsonl', "{\"at\":\"2026-10-01T10:00:00Z\",\"msg\":\"rad\"}\n");
jeab_migrate_ensure($pdo, $who);
$pdo->exec("INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES ('app_error:abc', 'app_error', 'abc', '2026-10-01T09:00:00Z', 'annat')");
$whoFilled = jeab_migrate_copy($who, false);
$otherKind = facit_all_count($pdo, "SELECT COUNT(*) FROM log_lines WHERE kind = 'app_error'");
$whoCount = facit_all_count($pdo, "SELECT COUNT(*) FROM log_lines WHERE kind = 'who_diag'");
facit_all(!empty($whoFilled['match']) && ($whoFilled['checksum'] ?? '') === 'match' && $otherKind === 1 && $whoCount === 1, 'tom who_diag-skiva fylls utan att röra andra slag');
$pdo->exec("INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES ('who_diag:sqlonly', 'who_diag', 'sqlonly', '2026-10-02T10:00:00Z', 'bara-sql')");
$beforeWho = facit_all_count($pdo, "SELECT COUNT(*) FROM log_lines WHERE kind = 'who_diag'");
$whoKept = jeab_migrate_copy($who, false);
facit_all(!empty($whoKept['preserved']) && !empty($whoKept['match']) && empty($whoKept['written']) && facit_all_count($pdo, "SELECT COUNT(*) FROM log_lines WHERE kind = 'who_diag'") === $beforeWho && facit_all_count($pdo, "SELECT COUNT(*) FROM log_lines WHERE kind = 'app_error'") === 1, 'who_diag med rader behåller SQL-only och andra slag');

echo "sql-facit-all ok\n";
