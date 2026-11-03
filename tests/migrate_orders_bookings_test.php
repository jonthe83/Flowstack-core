<?php

declare(strict_types=1);

/** Tabell, mappning och checksumma för ordrar och bokningar. Påhittad data. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

require dirname(__DIR__) . '/src/migrate/customers.php';
require dirname(__DIR__) . '/src/migrate/bookings.php';
require dirname(__DIR__) . '/src/migrate/reminder_lists.php';
require dirname(__DIR__) . '/src/migrate/reminders.php';
require dirname(__DIR__) . '/src/migrate/purchases.php';
require dirname(__DIR__) . '/src/migrate/ordrar_deleted.php';
require dirname(__DIR__) . '/src/migrate/webshop_orders.php';
require dirname(__DIR__) . '/src/migrate/calendar_log.php';

function mob(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$store = [
    'customers' => [
        ['id' => 7, 'name' => 'Demo Kund', 'created_at' => '2026-01-02 08:00:00'],
    ],
    'reminder_lists' => [
        ['id' => 1, 'name' => 'Påminnelser', 'created_at' => '2026-01-01 00:00:00'],
    ],
    'bookings' => [
        [
            'id' => 42,
            'customer_id' => 7,
            'customer_name' => 'Demo Kund',
            'email' => 'demo@example.com',
            'phone' => '0700000000',
            'address' => 'Demovägen 1',
            'assignee' => 'Kim',
            'assignees' => ['Kim'],
            'company' => 'JEAB',
            'job_type' => 'sol',
            'starts_at' => '2026-03-01 09:00:00',
            'ends_at' => '2026-03-01 11:00:00',
            'status' => 'booked',
            'contact_id' => 'c-demo',
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ],
    ],
    'reminders' => [
        [
            'id' => 880001,
            'list_id' => 1,
            'title' => 'Demo order',
            'customer_name' => 'Demo Kund',
            'company' => 'malmö',
            'tags' => ['vunnen'],
            'booking_id' => 42,
            'contact_id' => 'c-demo',
            'ringt' => 1,
            'created_at' => '2026-02-02 10:00:00',
            'updated_at' => '2026-02-02 10:00:00',
        ],
    ],
];

$purchases = [
    '880001' => [
        'reminder_id' => 880001,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'seller' => 'Kim',
        'klart' => 0,
        'customer_name' => 'Demo Kund',
        'updated_at' => '2026-02-02 12:00:00',
        'created_at' => '2026-02-02 11:00:00',
    ],
];

$deleted = [
    'hs' => ['hs-2' => 880001],
    'ids' => [
        '900001' => ['booking_id' => 42, 'at' => '2026-02-03', 'hs' => ['hs-2']],
    ],
];

$webshop = [
    'jarfalla:1001' => ['number' => 1001, 'company' => 'jarfalla', 'reminder_id' => 880001],
];

$calendar = [
    'backfilled' => false,
    'entries' => [
        ['id' => 'e2', 'at' => '2026-02-02', 'kind' => 'book'],
        ['id' => 'e1', 'at' => '2026-02-01', 'kind' => 'move'],
    ],
];

$modules = [
    jeab_migrate_customers_module(),
    jeab_migrate_reminder_lists_module(),
    jeab_migrate_bookings_module(),
    jeab_migrate_reminders_module(),
    jeab_migrate_purchases_module(),
    jeab_migrate_ordrar_deleted_module(),
    jeab_migrate_webshop_orders_module(),
    jeab_migrate_calendar_log_module(),
];
foreach ($modules as $module) {
    mob(isset($module['id'], $module['table'], $module['schema']['mysql'], $module['schema']['sqlite'], $module['columns']), $module['id'] . ' har tabell och schema');
    mob(!isset($module['hook']) && !isset($module['flag']), $module['id'] . ' saknar egen flagga och hook');
    mob(str_contains($module['schema']['mysql'], 'CREATE TABLE IF NOT EXISTS ' . $module['table']), $module['id'] . ' skapar sin tabell');
}

$bookings = jeab_migrate_bookings_rows($store);
mob($bookings[0]['company'] === 'jarfalla', 'bokning JEAB blir jarfalla');
mob($bookings[0]['contact_id'] === 'c-demo', 'bokning behåller contact_id');
$bookingSum = jeab_migrate_bookings_checksum($store);
mob(strlen($bookingSum) === 64 && $bookingSum === jeab_migrate_bookings_checksum($bookings), 'bokningschecksumma är stabil');

$shuffled = $store;
$shuffled['bookings'] = array_reverse($store['bookings']);
mob(jeab_migrate_bookings_checksum($shuffled) === $bookingSum, 'bokningsordning påverkar inte checksumman');

$reminders = jeab_migrate_reminders_rows($store);
mob($reminders[0]['company'] === 'malmo' && $reminders[0]['booking_id'] === 42, 'påminnelse mappas');
mob(strlen(jeab_migrate_reminders_checksum($store)) === 64, 'påminnelsechecksumma');

$purchaseRows = jeab_migrate_purchases_rows($purchases);
mob($purchaseRows[0]['reminder_id'] === 880001 && $purchaseRows[0]['kind'] === 'sol', 'order mappas på reminder_id');
$purchaseSum = jeab_migrate_purchases_checksum($purchases);
$flipped = ['z' => $purchases['880001']];
mob(jeab_migrate_purchases_checksum($flipped) === $purchaseSum && strlen($purchaseSum) === 64, 'orderchecksumma är stabil');
mob(!str_contains($purchaseRows[0]['payload'], 'personnummer'), 'orderraden har inget personnummerfält');

$deletedSum = jeab_migrate_ordrar_deleted_checksum($deleted);
$deletedAgain = jeab_migrate_ordrar_deleted_checksum(['ids' => $deleted['ids'], 'hs' => ['hs-2' => 880001]]);
mob(strlen($deletedSum) === 64 && $deletedSum === $deletedAgain, 'raderade ordrar har stabil checksumma');
mob(jeab_migrate_ordrar_deleted_rows($deleted)[0]['doc_key'] === 'ordrar_deleted', 'raderade ordrar är ett json_docs-dokument');

mob(jeab_migrate_webshop_orders_rows($webshop)[0]['order_key'] === 'jarfalla:1001', 'webshopnyckel');
mob(strlen(jeab_migrate_webshop_orders_checksum($webshop)) === 64, 'webshopchecksumma');

$calendarSum = jeab_migrate_calendar_log_checksum($calendar);
$calendarFlip = $calendar;
$calendarFlip['entries'] = array_reverse($calendar['entries']);
mob(strlen($calendarSum) === 64 && jeab_migrate_calendar_log_checksum($calendarFlip) === $calendarSum, 'veckologgens checksumma sorterar id');
mob(jeab_migrate_calendar_log_rows($calendar)[0]['doc_key'] === 'calendar_log', 'veckologgen är log_docs');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    jeab_migrate_customers_module(),
    jeab_migrate_reminder_lists_module(),
    jeab_migrate_bookings_module(),
    jeab_migrate_reminders_module(),
    jeab_migrate_purchases_module(),
    jeab_migrate_ordrar_deleted_module(),
    jeab_migrate_webshop_orders_module(),
    jeab_migrate_calendar_log_module(),
] as $module) {
    $pdo->exec($module['schema']['sqlite']);
}

$batches = [
    [jeab_migrate_customers_module(), jeab_migrate_customers_rows($store), ['id']],
    [jeab_migrate_reminder_lists_module(), jeab_migrate_reminder_lists_rows($store), ['id']],
    [jeab_migrate_bookings_module(), $bookings, ['id', 'customer_id']],
    [jeab_migrate_reminders_module(), $reminders, ['id', 'list_id', 'flagged', 'booking_id', 'ringt', 'pratat', 'mailat', 'smsat']],
    [jeab_migrate_purchases_module(), $purchaseRows, ['reminder_id', 'klart']],
    [jeab_migrate_ordrar_deleted_module(), jeab_migrate_ordrar_deleted_rows($deleted), []],
    [jeab_migrate_webshop_orders_module(), jeab_migrate_webshop_orders_rows($webshop), []],
    [jeab_migrate_calendar_log_module(), jeab_migrate_calendar_log_rows($calendar), []],
];
foreach ($batches as [$module, $rows, $ints]) {
    $columns = $module['columns'];
    $table = $module['table'];
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    if (!in_array('updated_at', $columns, true) && str_contains($module['schema']['sqlite'], 'updated_at')) {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ', updated_at) VALUES (' . $placeholders . ", '1970-01-01 00:00:00')";
    }
    $stmt = $pdo->prepare($sql);
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column];
        }
        $stmt->execute($values);
    }
    $loaded = [];
    foreach ($pdo->query('SELECT ' . implode(', ', $columns) . ' FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $stored) {
        foreach ($ints as $intKey) {
            if (array_key_exists($intKey, $stored) && $stored[$intKey] !== null) {
                $stored[$intKey] = (int) $stored[$intKey];
            }
        }
        $loaded[] = $stored;
    }
    mob(jeab_migrate_checksum($loaded) === jeab_migrate_checksum($rows), $module['id'] . ' rundtur i sqlite');
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
mob(!str_contains($hook, 'webshop_orders.sql_') && !str_contains($hook, 'calendar_log.sql_'), 'hook saknar egna order- och bokningshookar');
mob(!is_file(dirname(__DIR__) . '/src/calendar_log_sql.php') && !is_file(dirname(__DIR__) . '/src/webshop_orders_sql.php'), 'flaggfilerna är borta');

echo "migrate_orders_bookings_test ok\n";
