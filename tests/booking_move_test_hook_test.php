<?php

declare(strict_types=1);

/**
 * flags.set skriver BOOKING_MOVE_MAIL_TEST_IDS bara för testbokningar.
 * booking.move_test flyttar en testbokning via kalenderns uppdatering.
 * dry=1 är standard. En riktig kund flyttas inte. Inget mejl lämnar maskinen.
 */

$home = sys_get_temp_dir() . '/jeab-move-test-hook-' . getmypid();
$root = $home . '/public_html';
$data = $root . '/data';
$privat = $home . '/privat';
if (!is_dir($data . '/secrets') && !mkdir($data . '/secrets', 0700, true) && !is_dir($data . '/secrets')) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
if (!is_dir($privat) && !mkdir($privat, 0700, true) && !is_dir($privat)) {
    fwrite(STDERR, "FAIL privat dir\n");
    exit(1);
}
$GLOBALS['_JEAB_APP_ROOT'] = $root;
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $data . '/calendar-log.json';
putenv('JEAB_APP_ROOT=' . $root);
putenv('BOOKING_MOVE_MAIL');
putenv('BOOKING_MOVE_MAIL_TEST_IDS');
unset(
    $_ENV['BOOKING_MOVE_MAIL'],
    $_ENV['BOOKING_MOVE_MAIL_TEST_IDS'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE']
);
file_put_contents($data . '/config.php', <<<'PHP'
<?php
return [
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/app.sqlite',
        'json_path' => __DIR__ . '/store.json',
    ],
];
PHP);
file_put_contents($data . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-move-test']));

require dirname(__DIR__) . '/src/bootstrap.php';

function mv_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');
$tz = new DateTimeZone('Europe/Stockholm');
$now = new DateTimeImmutable('now', $tz);
$aged = $now->modify('-5 hours')->format('Y-m-d H:i:s');
$captured = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured): array {
    $captured[] = $opts;

    return ['ok' => true, 'path' => 'inkorg', 'error' => null, 'id' => 'thr', 'thread_id' => 'thr', 'graph_id' => 'g'];
};

$weekday = $now->modify('+3 days')->setTime(9, 0, 0);
while ((int) $weekday->format('N') >= 6) {
    $weekday = $weekday->modify('+1 day');
}
$startA = $weekday->format('Y-m-d H:i:s');
$endA = $weekday->modify('+2 hours')->format('Y-m-d H:i:s');
$dayB = $weekday->modify('+1 day');
while ((int) $dayB->format('N') >= 6) {
    $dayB = $dayB->modify('+1 day');
}
$startB = $dayB->setTime(13, 0, 0)->format('Y-m-d H:i:s');
$endB = $dayB->setTime(15, 0, 0)->format('Y-m-d H:i:s');

$store = jeab_store(true);
mv_assert($store instanceof JeabPdoStore, 'butiken är SQL');
$insert = static function (string $name, string $email) use ($store, $aged, $startA, $endA): array {
    $customer = $store->insertCustomer($name, jeab_now());

    return $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => $name,
        'email' => $email,
        'phone' => '',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'laddbox',
        'starts_at' => $startA,
        'ends_at' => $endA,
        'status' => 'booked',
        'created_at' => $aged,
        'updated_at' => $aged,
    ]);
};
$real = $insert('Ada Riktig', 'ada.riktig@kund.se');
$test = $insert('Bodil Exempel', 'bodil.move@example.com');
$realId = (int) $real['id'];
$testId = (int) $test['id'];

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION']);
$noToken = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => [$testId],
    'dry' => 0,
]);
mv_assert(($noToken['status'] ?? 0) === 401 && empty($noToken['written']) && !is_file($privat . '/flags.json'), 'flags.set utan token skriver inte');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-move-test';
$dryIds = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => [$testId],
]);
mv_assert(!empty($dryIds['ok']) && ($dryIds['dry'] ?? false) === true && empty($dryIds['written']) && ($dryIds['new'] ?? []) === [$testId] && !is_file($privat . '/flags.json'), 'utelämnad dry skriver inte listan');

$realSet = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => [$realId],
    'dry' => 0,
]);
mv_assert(empty($realSet['ok']) && ($realSet['written'] ?? true) === false && ($realSet['rejected'] ?? []) === [$realId] && !is_file($privat . '/flags.json'), 'riktig kund avvisas');

$mix = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => [$testId, $realId],
    'dry' => '0',
]);
mv_assert(empty($mix['ok']) && ($mix['rejected'] ?? []) === [$realId] && !is_file($privat . '/flags.json'), 'en riktig kund i listan stoppar hela skrivningen');

$junk = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => $testId . ',abc',
    'dry' => 0,
]);
mv_assert(empty($junk['ok']) && empty($junk['written']) && !is_file($privat . '/flags.json'), 'ogiltigt id skriver inte');

$missing = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => [$testId, 99999],
    'dry' => 0,
]);
mv_assert(empty($missing['ok']) && ($missing['rejected'] ?? []) === [99999] && !is_file($privat . '/flags.json'), 'saknad bokning avvisas');

$wrote = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => (string) $testId,
    'dry' => 0,
]);
$disk = json_decode((string) file_get_contents($privat . '/flags.json'), true);
mv_assert(!empty($wrote['ok']) && ($wrote['written'] ?? false) === true && ($wrote['new'] ?? []) === [$testId] && is_array($disk) && ($disk['BOOKING_MOVE_MAIL_TEST_IDS'] ?? []) === [$testId], 'flags.set skriver testbokningens id');
mv_assert(jeab_booking_move_mail_test_ids() === [$testId], 'filen är vitlistan när env saknas');
$got = jeab_flags_get_dispatch([]);
mv_assert(!empty($got['ok']) && ($got['BOOKING_MOVE_MAIL_TEST_IDS'] ?? []) === [$testId], 'flags.get visar listan från filen');

$kept = jeab_flags_set_dispatch(['name' => 'STEG4_CUTOVER', 'value' => 1, 'dry' => 0]);
$diskKept = json_decode((string) file_get_contents($privat . '/flags.json'), true);
mv_assert(!empty($kept['ok']) && is_array($diskKept) && ($diskKept['BOOKING_MOVE_MAIL_TEST_IDS'] ?? []) === [$testId] && ($diskKept['STEG4_CUTOVER'] ?? 0) === 1, 'boolesk flags.set behåller test-id-listan');

jeab_sms_save_sent($testId, [
    'starts_at' => $startA,
    'email' => true,
    'email_at' => $aged,
    'email_subject' => 'Bekräftelse',
    'email_thread_id' => 'thr-confirm',
]);
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
mv_assert(str_contains($hookSrc, "=== 'booking.move_test'") && str_contains($hookSrc, 'jeab_booking_move_test_dispatch'), 'hook.php tar booking.move_test');
mv_assert(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/sms.php'), 'jeab_update_booking($store, $id'), 'flytthooken använder kalenderns uppdatering');

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION']);
$denied = jeab_booking_move_test_dispatch($store, ['id' => $testId, 'start' => $startB, 'dry' => 0]);
mv_assert(($denied['status'] ?? 0) === 401 && empty($denied['moved']) && (string) $store->getBooking($testId)['starts_at'] === $startA, 'hook utan token flyttar inte');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-move-test';
$preview = jeab_booking_move_test_dispatch($store, ['id' => $testId, 'start' => $startB]);
mv_assert(
    !empty($preview['ok']) && ($preview['dry'] ?? 0) === 1 && empty($preview['moved'])
    && ($preview['would_starts_at'] ?? '') === $startB && ($preview['would_ends_at'] ?? '') === $endB
    && ($preview['booking_move_queue'] ?? null) === []
    && (string) $store->getBooking($testId)['starts_at'] === $startA,
    'dry visar den nya tiden och lämnar bokningen'
);

$beforeMail = count($captured);
$moved = jeab_booking_move_test_dispatch($store, ['id' => $testId, 'start' => $startB, 'dry' => 0]);
$fresh = $store->getBooking($testId);
mv_assert(
    !empty($moved['ok']) && ($moved['dry'] ?? 1) === 0 && !empty($moved['moved'])
    && (string) ($fresh['starts_at'] ?? '') === $startB && (string) ($fresh['ends_at'] ?? '') === $endB
    && count($moved['booking_move_queue'] ?? []) === 1
    && ($moved['booking_move_queue'][0]['booking_id'] ?? 0) === $testId
    && ($moved['booking_move_queue'][0]['starts_at'] ?? '') === $startB,
    'dry=0 flyttar testbokningen och köar flyttmejl'
);
mv_assert(count($captured) === $beforeMail, 'flytten skickar inget mejl');

$realBefore = (string) $store->getBooking($realId)['starts_at'];
$refused = jeab_booking_move_test_dispatch($store, ['id' => $realId, 'start' => $startB, 'dry' => 0]);
mv_assert(
    empty($refused['ok']) && ($refused['status'] ?? 0) === 409 && empty($refused['moved'])
    && ($refused['error'] ?? '') === 'inte en testbokning'
    && (string) $store->getBooking($realId)['starts_at'] === $realBefore,
    'hooken vägrar att flytta en riktig kund'
);

$cleared = jeab_flags_set_dispatch([
    'name' => 'BOOKING_MOVE_MAIL_TEST_IDS',
    'value' => [],
    'dry' => 0,
]);
$diskClear = json_decode((string) file_get_contents($privat . '/flags.json'), true);
mv_assert(!empty($cleared['ok']) && ($cleared['new'] ?? [1]) === [] && is_array($diskClear) && !array_key_exists('BOOKING_MOVE_MAIL_TEST_IDS', $diskClear) && ($diskClear['STEG4_CUTOVER'] ?? 0) === 1, 'tom lista tar bort test-id och lämnar övriga flaggor');

echo "booking move test hook: ok\n";
