<?php
declare(strict_types=1);

/**
 * Listsidorna ska svara under 200 ms mot fixturer, utan externa anrop.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-list-ms-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'ordrar'];
require $root . '/src/bootstrap.php';

$fail = 0;
function ms_check(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$store = jeab_store();
$now = jeab_now();
$customer = $store->insertCustomer('Mätkund', $now);
$cid = (int) $customer['id'];
for ($i = 1; $i <= 240; $i++) {
    $store->insertReminder([
        'title' => 'Kundgatan ' . $i . ', Järfälla',
        'customer_name' => 'Kund ' . $i,
        'company' => 'jarfalla',
        'person' => $i % 2 === 0 ? 'Roberto' : 'Elin',
        'assignee' => $i % 2 === 0 ? 'Roberto' : 'Elin',
        'due_at' => sprintf('2026-11-%02d 09:00:00', ($i % 27) + 1),
        'completed_at' => $i % 4 === 0 ? $now : null,
        'address' => 'Kundgatan ' . $i . ', Järfälla',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($i <= 120) {
        $store->insertBooking([
            'customer_id' => $cid,
            'customer_name' => 'Bokning ' . $i,
            'address' => 'Kalendergatan ' . $i . ', Järfälla',
            'assignee' => 'Roberto',
            'assignees' => ['Roberto'],
            'job_type' => $i % 2 === 0 ? 'sol' : 'batteri',
            'company' => 'jarfalla',
            'starts_at' => sprintf('2026-10-%02d 09:00:00', ($i % 20) + 1),
            'ends_at' => sprintf('2026-10-%02d 11:00:00', ($i % 20) + 1),
            'status' => 'booked',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
$orders = [];
for ($i = 1; $i <= 180; $i++) {
    $orders[(string) $i] = [
        'reminder_id' => $i,
        'company' => 'jarfalla',
        'kind' => $i % 2 === 0 ? 'sol' : 'batteri',
        'customer_name' => 'Order ' . $i,
        'address' => 'Ordergatan ' . $i . ', Järfälla',
        'booking_id' => $i <= 120 ? $i : 0,
        'created_at' => sprintf('2026-09-%02d 08:00:00', ($i % 27) + 1),
        'updated_at' => $now,
        'steps' => [],
        'person' => 'Roberto',
    ];
}
jeab_purchase_save_all($orders);
$contacts = ['contacts' => []];
for ($i = 1; $i <= 400; $i++) {
    $contacts['contacts']['kt_' . $i] = [
        'id' => 'kt_' . $i,
        'street' => 'Kontaktgatan ' . $i,
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'owners' => [['name' => 'Namn ' . $i, 'contact' => true, 'email' => '', 'phone' => '']],
    ];
}
file_put_contents($tmp . '/kontakter.json', json_encode($contacts));

$times = [];
$measure = static function (string $name, callable $fn) use (&$times): void {
    $t = hrtime(true);
    $fn();
    $times[$name] = (hrtime(true) - $t) / 1e6;
};

$measure('ordrar', static function () use ($store): void {
    $rows = jeab_ordrar_lista_sort(jeab_filter_orders(jeab_ordrar_visible('jarfalla', false), null, null), $store);
    $rows = array_slice($rows, 0, 50);
    $html = '';
    foreach ($rows as $row) {
        $html .= jeab_ordrar_board_row_html($row, 'jarfalla', $store);
    }
    if (!str_contains($html, 'ordrar-row')) {
        throw new RuntimeException('tom orderlista');
    }
});
$measure('ordrar v3-celler', static function () use ($store): void {
    $rows = array_slice(jeab_ordrar_visible('jarfalla', false), 0, 50);
    foreach ($rows as $row) {
        jeab_ordrar_lista_cells($row, $store);
    }
});
$measure('påminnelser', static function () use ($store): void {
    $rows = jeab_list_reminders($store, true, 'jarfalla', null);
    $visible = jeab_filter_reminders($rows, 'all', ['mode' => 'none', 'tag' => null], '');
    $page = jeab_reminder_page($visible, 'all', '', 0, 0);
    if (count($page['rows']) > 50) {
        throw new RuntimeException('påminnelser över 50');
    }
});
$measure('kalender', static function () use ($store): void {
    $rows = jeab_calendar_filtered_bookings(
        $store,
        new DateTimeImmutable('2026-10-01 00:00:00'),
        new DateTimeImmutable('2026-10-31 23:59:59')
    );
    if ($rows === []) {
        throw new RuntimeException('tom kalender');
    }
});
$measure('kontakter', static function () use ($store): void {
    $view = jeab_kontakter_view($store, '', 'alla', 'jarfalla', JEAB_KONTAKT_PAGE);
    if (count($view['rows']) !== 50) {
        throw new RuntimeException('kontakter sida ' . count($view['rows']));
    }
});
$measure('bokningar', static function () use ($store): void {
    $rows = jeab_bookings_between($store, '2026-10-01 00:00:00', '2026-10-31 23:59:59');
    if ($rows === []) {
        throw new RuntimeException('tomma bokningar');
    }
});

echo "\n| Sida | ms |\n|---|---:|\n";
foreach ($times as $name => $ms) {
    echo '| ' . $name . ' | ' . number_format($ms, 1, '.', '') . " |\n";
    ms_check($ms < 200, $name . ' under 200 ms (' . number_format($ms, 1, '.', '') . ')');
}

$header = jeab_server_timing_value();
ms_check(str_contains($header, 'db;dur=') && str_contains($header, 'render;dur=') && str_contains($header, 'total;dur='), 'Server-Timing db render total');
$GLOBALS['_JEAB_TIMING_PANEL'] = true;
$_GET['_t'] = '1';
ob_start();
jeab_timing_panel();
$panel = (string) ob_get_clean();
ms_check(str_contains($panel, 'ms'), 'läget ?_t=1 visar frågor');
ms_check(jeab_page_blocks_external(), 'sidvisning blockerar externa anrop');
$idx = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['reminders_person', 'reminders_assignee', 'bookings_status_starts', 'bookings_job_type'] as $name) {
    ms_check(in_array($name, $idx, true), 'index ' . $name);
}

echo $fail === 0 ? "\nall passed\n" : "\n{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
