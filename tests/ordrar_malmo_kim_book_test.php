<?php

declare(strict_types=1);

/**
 * Malmö-laddbox och Malmö-batteri bokas alltid på Kim när ordern bokas
 * (Skapa order och boka), oavsett vem påminnelsen ligger på.
 * Förr blev det Elin (Järfälla) om påminnelsen inte låg på Kim, Abol eller Sam W.
 * Autobokning vid vunnen affär styrs fortfarande av ansvarig (meab_won_book_test).
 */

$tmp = sys_get_temp_dir() . '/jeab-malmo-kim-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function mk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');
jeab_ordrar_external_live(false);
$store = new JeabJsonStore($tmp . '/store.json');

function mk_order(JeabStore $store, string $customer, string $company, string $who, string $kind): array
{
    $reminder = jeab_create_reminder($store, $customer, null, 'Order', null, [
        'customer' => $customer,
        'company' => $company,
        'assignee' => $who,
        'email' => 'kund@example.com',
        'phone' => '0701112233',
        'address' => 'Testgatan 1',
        'notes' => 'Order',
    ]);
    $row = jeab_purchase_put([
        'reminder_id' => (int) $reminder['id'],
        'company' => $company,
        'kind' => $kind,
        'customer' => $customer,
        'address' => 'Testgatan 1',
    ]);
    return $row;
}

function mk_names(JeabStore $store, array $row): array
{
    $id = (int) ($row['booking_id'] ?? 0);
    $booking = $id > 0 ? $store->getBooking($id) : null;
    if (!is_array($booking)) {
        return [];
    }
    $names = jeab_assignees_from_row($booking);
    sort($names);
    return $names;
}

foreach (['Isaq', 'Elin', 'Niman', ''] as $who) {
    $order = mk_order($store, 'Malmö Laddbox ' . $who, 'malmo', $who, 'laddbox');
    $booked = jeab_ordrar_create_booking($store, ['id' => (int) $order['reminder_id']]);
    mk_assert(mk_names($store, $booked) === ['Kim'], 'Malmö-laddbox med påminnelse på "' . $who . '" bokas på Kim, fick ' . json_encode(mk_names($store, $booked)));
}

foreach (['Isaq', 'Elin'] as $who) {
    $order = mk_order($store, 'Malmö Batteri ' . $who, 'malmo', $who, 'batteri');
    $booked = jeab_ordrar_create_booking($store, ['id' => (int) $order['reminder_id']]);
    mk_assert(mk_names($store, $booked) === ['Kim'], 'Malmö-batteri med påminnelse på "' . $who . '" bokas på Kim, fick ' . json_encode(mk_names($store, $booked)));
}

$order = mk_order($store, 'Malmö Sol', 'malmo', 'Elin', 'sol');
$booked = jeab_ordrar_create_booking($store, ['id' => (int) $order['reminder_id']]);
mk_assert(mk_names($store, $booked) === ['Abol', 'Isaq'], 'Malmö-sol bokas på Isaq och Abol, fick ' . json_encode(mk_names($store, $booked)));

$order = mk_order($store, 'Järfälla Laddbox', 'jarfalla', 'Kim', 'laddbox');
$booked = jeab_ordrar_create_booking($store, ['id' => (int) $order['reminder_id']]);
mk_assert(mk_names($store, $booked) === ['Elin'], 'Järfälla-laddbox bokas fortfarande på Elin, fick ' . json_encode(mk_names($store, $booked)));

$order = mk_order($store, 'Järfälla Batteri', 'jarfalla', 'Kim', 'batteri');
$booked = jeab_ordrar_create_booking($store, ['id' => (int) $order['reminder_id']]);
mk_assert(mk_names($store, $booked) === ['Roberto'], 'Järfälla-batteri bokas fortfarande på Roberto, fick ' . json_encode(mk_names($store, $booked)));

echo "ALL OK\n";
