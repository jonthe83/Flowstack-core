<?php

declare(strict_types=1);

/** store.json → SQL-hookar. Påhittad data, inga riktiga kunder. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-store-sql-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$storePath = $tmp . '/store.json';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $storePath],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/store_sql_migrate.php';

function ss(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

ss(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'store.sql_migrate'), 'hook tar store.sql_migrate');
ss(jeab_store_storage_mode() === 'json', 'flagga av');
ss(jeab_driver() === 'sqlite', 'sqlite-driver');

$fixture = [
    'next_id' => ['reminder_lists' => 2, 'customers' => 2, 'bookings' => 2, 'reminders' => 2],
    'reminder_lists' => [['id' => 1, 'name' => 'Påminnelser', 'created_at' => '2026-01-01 00:00:00']],
    'customers' => [['id' => 1, 'name' => 'Demo AB', 'created_at' => '2026-01-01 00:00:00']],
    'bookings' => [[
        'id' => 1,
        'customer_id' => 1,
        'customer_name' => 'Demo AB',
        'assignee' => 'Kim',
        'assignees' => ['Kim'],
        'company' => 'jarfalla',
        'starts_at' => '2026-02-01 09:00:00',
        'ends_at' => '2026-02-01 11:00:00',
        'status' => 'booked',
        'contact_id' => 'c-demo',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'booking_id' => null,
    ]],
    'reminders' => [[
        'id' => 1,
        'list_id' => 1,
        'title' => 'Demo-påminnelse',
        'company' => 'jarfalla',
        'assignee' => 'Kim',
        'assignees' => ['Kim'],
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'booking_id' => null,
        'contact_id' => 'c-demo',
    ]],
    'settings' => [],
];
file_put_contents($storePath, json_encode($fixture, JSON_PRETTY_PRINT));
$before = (string) file_get_contents($storePath);

$dry = jeab_store_sql_migrate([]);
ss(($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'migrate dry default');

$migrate = jeab_store_sql_migrate(['dry' => 0]);
ss(!empty($migrate['ok']) && ($migrate['count_match'] ?? false) === true, 'migrate count');
ss(($migrate['checksum_match'] ?? false) === true, 'migrate checksum');

$pdo = jeab_store_sql_pdo();
ss($pdo instanceof PDO && (string) $pdo->query('SELECT contact_id FROM bookings WHERE id = 1')->fetchColumn() === 'c-demo', 'bokning behåller contact_id');
ss((string) $pdo->query('SELECT contact_id FROM reminders WHERE id = 1')->fetchColumn() === 'c-demo', 'påminnelse behåller contact_id');
$pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = 1')->execute(['c-keep']);
jeab_store_sql_import_json($pdo, true);
ss((string) $pdo->query('SELECT contact_id FROM bookings WHERE id = 1')->fetchColumn() === 'c-keep', 'befintlig contact_id skrivs inte över');
$pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = 1')->execute(['c-demo']);

$verify = jeab_store_sql_verify([]);
ss(!empty($verify['ok']) && ($verify['match'] ?? false) === true, 'verify match');
ss((string) file_get_contents($storePath) === $before, 'migrate skriver inte store.json');

$cut = jeab_store_sql_cutover(['enable' => 1, 'dry' => 0]);
ss(!empty($cut['ok']), 'cutover enable på sqlite (no-op för driver)');

echo "store_sql_migrate_test ok\n";
