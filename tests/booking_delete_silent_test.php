<?php

declare(strict_types=1);

/**
 * booking.delete_silent: en enda testbokning tas bort tyst. Kopia först (bokning +
 * veckologgrader), ingen webhook/mejl/sms, inga andra bokningar rörs, namnet måste
 * stämma, bokning med order/påminnelse vägras, Outlook bara om det är JEAB:s egen
 * händelse utan andra deltagare.
 */

$tmp = sys_get_temp_dir() . '/jeab-delete-silent-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'] = $tmp . '/backups';
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');

function ds_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$http = [];
$GLOBALS['JEAB_NYLAS_HTTP'] = static function (string $method, string $url) use (&$http): array {
    $http[] = $method . ' ' . $url;
    return [];
};

$store = jeab_store(true);
$custTest = $store->insertCustomer('testmöte', '2026-10-01 10:00:00');
$custReal = $store->insertCustomer('Riktig Kund', '2026-10-01 10:00:00');
$base = ['phone' => null, 'email' => null, 'address' => null, 'notes' => null, 'company' => 'malmo', 'job_type' => 'laddbox', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'];
$test = $store->insertBooking($base + ['customer_id' => $custTest['id'], 'customer_name' => 'testmöte', 'assignees' => ['Kim'], 'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00']);
$real = $store->insertBooking(array_merge($base, ['customer_id' => $custReal['id'], 'customer_name' => 'Riktig Kund', 'email' => 'kund@example.invalid', 'phone' => '0701234567', 'address' => 'Exempelgatan 1', 'assignees' => ['Kim'], 'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 11:00:00']));
ds_assert(!is_file($tmp . '/store.json'), 'store.json skrivs inte');
jeab_calendar_log_write(['entries' => [
    ['id' => 1, 'type' => 'booked', 'at' => '2026-10-01 10:00:00', 'booking_id' => (int) $test['id'], 'title' => 'testmöte · Kim', 'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00'],
    ['id' => 2, 'type' => 'booked', 'at' => '2026-10-09 17:57:00', 'booking_id' => (int) $real['id'], 'title' => 'Riktig Kund · Kim', 'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 11:00:00'],
], 'backfilled' => true]);

// —— dry ändrar inget och lämnar inga personuppgifter ————————————————————————
$dry = jeab_booking_delete_silent($store, (int) $test['id'], '', true);
ds_assert($dry['dry'] === true && (int) $dry['id'] === (int) $test['id'] && (int) ($dry['counts']['log_entries'] ?? -1) === 1, 'dry visar id och antal loggrader');
ds_assert(array_keys($dry) === ['dry', 'id', 'counts'], 'dry har bara id och räkningar');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
ds_assert(is_string($dryJson) && !str_contains($dryJson, 'testmöte') && !isset($dry['booking']), 'dry lämnar inte kundens namn');
$dryReal = jeab_booking_delete_silent($store, (int) $real['id'], '', true);
$realJson = json_encode($dryReal, JSON_UNESCAPED_UNICODE);
ds_assert(
    is_string($realJson)
    && !str_contains($realJson, 'Riktig')
    && !str_contains($realJson, 'kund@example.invalid')
    && !str_contains($realJson, '0701234567')
    && !str_contains($realJson, 'Exempelgatan'),
    'dry av bokning med kontaktuppgifter har bara id och räkningar'
);
ds_assert($store->getBooking((int) $test['id']) !== null && $store->getBooking((int) $real['id']) !== null, 'dry tar inte bort');

// —— fel namn: inget ändras ——————————————————————————————————————————————————
try {
    jeab_booking_delete_silent($store, (int) $real['id'], 'testmöte', false);
    ds_assert(false, 'fel namn vägras');
} catch (InvalidArgumentException) {
    ds_assert($store->getBooking((int) $real['id']) !== null, 'fel namn vägras, riktiga bokningen kvar');
}

// —— skarpt ——————————————————————————————————————————————————————————————————
$res = jeab_booking_delete_silent($store, (int) $test['id'], 'Testmöte', false);
ds_assert($res['deleted'] === true && $res['log_removed'] === 1, 'testbokningen och dess loggrad borta');
ds_assert($store->getBooking((int) $test['id']) === null, 'raden finns inte kvar');
$kept = $store->getBooking((int) $real['id']);
ds_assert(is_array($kept) && $kept['starts_at'] === '2026-11-02 07:00:00' && $kept['status'] === 'booked', 'riktiga bokningen orörd (tid och status)');
$log = jeab_calendar_log_read();
ds_assert(count($log['entries']) === 1 && (int) $log['entries'][0]['booking_id'] === (int) $real['id'], 'bara testbokningens loggrad borttagen');
ds_assert(!array_filter($log['entries'], static fn(array $e): bool => ($e['type'] ?? '') === 'cancelled'), 'ingen «avbokad»-rad skrivs');
$path = $res['backup'];
$abs = str_starts_with($path, '/') ? $path : dirname(JEAB_ROOT) . '/' . $path;
ds_assert(str_contains($path, '/booking-' . (int) $test['id'] . '-') && is_file($abs), 'kopia sparad som booking-<id>-…json');
$copy = json_decode((string) file_get_contents($abs), true);
ds_assert(($copy['booking']['customer_name'] ?? '') === 'testmöte' && count($copy['calendar_log_entries'] ?? []) === 1, 'kopian har bokningen och loggraden');
ds_assert($http === [], 'ingen Outlook-händelse fanns, inga anrop');

// —— Outlook-regeln ——————————————————————————————————————————————————————————
ds_assert(jeab_booking_delete_outlook_block(['fetched' => true, 'title_matches' => true, 'other_participants' => 0]) === '', 'egen händelse utan deltagare får tas bort');
ds_assert(jeab_booking_delete_outlook_block(['fetched' => true, 'title_matches' => false, 'other_participants' => 0]) !== '', 'annan titel rörs inte');
ds_assert(jeab_booking_delete_outlook_block(['fetched' => true, 'title_matches' => true, 'other_participants' => 2]) !== '', 'deltagare → rörs inte');
ds_assert(jeab_booking_delete_outlook_block(['fetched' => false]) !== '', 'oläst händelse rörs inte');

// —— order/påminnelse vägras —————————————————————————————————————————————————
$custReminder = $store->insertCustomer('Med påminnelse', '2026-10-01 10:00:00');
$withReminder = $store->insertBooking($base + ['customer_id' => $custReminder['id'], 'customer_name' => 'Med påminnelse', 'assignees' => ['Kim'], 'starts_at' => '2026-10-21 09:00:00', 'ends_at' => '2026-10-21 10:00:00']);
$store->insertReminder(['title' => 'Följ upp', 'booking_id' => (int) $withReminder['id'], 'due_at' => '2026-10-22 09:00:00', 'created_at' => jeab_now(), 'updated_at' => jeab_now()]);
try {
    jeab_booking_delete_silent($store, (int) $withReminder['id'], 'Med påminnelse', false);
    ds_assert(false, 'bokning med påminnelse vägras');
} catch (InvalidArgumentException) {
    ds_assert($store->getBooking((int) $withReminder['id']) !== null, 'bokning med påminnelse vägras');
}

echo "ALLA OK\n";
