<?php

declare(strict_types=1);

/**
 * Tillbaka till bekräftad tid, eller avbokning, tar bort booking_move_queue
 * även när BOOKING_MOVE_MAIL är av. booking.move_queue_clear tar en kvarliggande
 * rad. dry=1 är standard. Inget mejl lämnar maskinen.
 */

$tmp = sys_get_temp_dir() . '/jeab-move-queue-clear-' . getmypid();
if (!is_dir($tmp . '/secrets') && !mkdir($tmp . '/secrets', 0700, true) && !is_dir($tmp . '/secrets')) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('BOOKING_MOVE_MAIL');
putenv('BOOKING_MOVE_MAIL_TEST_IDS');
unset(
    $_ENV['BOOKING_MOVE_MAIL'],
    $_ENV['BOOKING_MOVE_MAIL_TEST_IDS'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE']
);
file_put_contents($tmp . '/config.php', <<<'PHP'
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-queue-clear']));

require dirname(__DIR__) . '/src/bootstrap.php';

function qc_assert(bool $ok, string $message): void
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
$startB = $dayB->setTime(10, 0, 0)->format('Y-m-d H:i:s');
$endB = $dayB->setTime(12, 0, 0)->format('Y-m-d H:i:s');
$dayC = $dayB->modify('+1 day');
while ((int) $dayC->format('N') >= 6) {
    $dayC = $dayC->modify('+1 day');
}
$startC = $dayC->setTime(13, 0, 0)->format('Y-m-d H:i:s');
$endC = $dayC->setTime(15, 0, 0)->format('Y-m-d H:i:s');

qc_assert(jeab_booking_move_mail_enabled() === false, 'flaggan är av');
qc_assert(jeab_booking_move_mail_test_ids() === [], 'vitlistan är tom');

$store = jeab_store(true);
qc_assert($store instanceof JeabPdoStore, 'butiken är SQL');

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
$confirm = static function (int $id) use ($aged, $startA): void {
    jeab_sms_save_sent($id, [
        'starts_at' => $startA,
        'email' => true,
        'email_at' => $aged,
        'notified_starts_at' => $startA,
    ]);
};
$queued = static function (int $id): array {
    return jeab_booking_move_mail_rows_for($id)['booking_move_queue'];
};

$back = $insert('Ada Tillbaka', 'ada.tillbaka@kund.se');
$other = $insert('Bertil Kvar', 'bertil.kvar@kund.se');
$cancel = $insert('Cilla Avbok', 'cilla.avbok@kund.se');
$backId = (int) $back['id'];
$otherId = (int) $other['id'];
$cancelId = (int) $cancel['id'];
$confirm($backId);
$confirm($otherId);
$confirm($cancelId);
jeab_booking_move_mail_queue_put($backId, $startB);
jeab_booking_move_mail_queue_put($otherId, $startB);
jeab_booking_move_mail_queue_put($cancelId, $startB);
qc_assert(count($queued($backId)) === 1 && count($queued($otherId)) === 1, 'två köer finns innan flytten');

$before = count($captured);
jeab_update_booking($store, $backId, ['starts_at' => $startC, 'ends_at' => $endC]);
qc_assert(count($queued($backId)) === 1 && ($queued($backId)[0]['starts_at'] ?? '') === $startB, 'annan tid med flaggan av lämnar den gamla kön');
qc_assert(count($captured) === $before, 'flytten skickar inget');

jeab_update_booking($store, $backId, ['starts_at' => $startA, 'ends_at' => $endA]);
qc_assert($queued($backId) === [], 'tillbaka till bekräftad tid tar bort kön när flaggan är av');
qc_assert(count($queued($otherId)) === 1, 'en annan boknings kö ligger kvar');
$done = jeab_sms_sent_store()[(string) $backId] ?? [];
qc_assert(is_array($done) && empty($done['move_pending']), 'väntande flytt rensas vid återställd tid');

jeab_cancel_booking($store, $cancelId);
qc_assert($queued($cancelId) === [], 'avbokning tar bort kön när flaggan är av');
qc_assert(count($queued($otherId)) === 1 && count($captured) === $before, 'avbokningen rör inte övriga köer och skickar inget');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
qc_assert(str_contains($hook, "=== 'booking.move_queue_clear'") && str_contains($hook, 'jeab_booking_move_queue_clear_dispatch'), 'hook.php tar booking.move_queue_clear');

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION']);
$denied = jeab_booking_move_queue_clear_dispatch($store, ['id' => $otherId, 'dry' => 0]);
qc_assert(($denied['status'] ?? 0) === 401 && empty($denied['cleared']) && count($queued($otherId)) === 1, 'hook utan token tar inte bort raden');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-queue-clear';
$dry = jeab_booking_move_queue_clear_dispatch($store, ['id' => $otherId]);
qc_assert(
    !empty($dry['ok']) && ($dry['dry'] ?? 0) === 1 && empty($dry['cleared'])
    && count($dry['booking_move_queue'] ?? []) === 1
    && ($dry['booking_move_queue'][0]['booking_id'] ?? 0) === $otherId
    && ($dry['booking_move_queue'][0]['starts_at'] ?? '') === $startB
    && count($queued($otherId)) === 1,
    'dry visar raden och lämnar den'
);
$several = jeab_booking_move_queue_clear_dispatch($store, ['ids' => [$otherId], 'dry' => 0]);
qc_assert(($several['status'] ?? 0) === 400 && count($queued($otherId)) === 1, 'flera id nekas');

$cleared = jeab_booking_move_queue_clear_dispatch($store, ['id' => $otherId, 'dry' => 0]);
qc_assert(
    !empty($cleared['ok']) && ($cleared['dry'] ?? 1) === 0 && !empty($cleared['cleared'])
    && ($cleared['booking_move_queue'] ?? null) === []
    && count($cleared['booking_move_sent'] ?? []) === 0
    && $queued($otherId) === [],
    'dry=0 tar bort booking_move_queue för det id:t'
);
qc_assert(count($captured) === $before, 'rensningen skickar inget mejl');

$again = jeab_booking_move_queue_clear_dispatch($store, ['id' => $otherId, 'dry' => 0]);
qc_assert(!empty($again['ok']) && empty($again['cleared']) && ($again['booking_move_queue'] ?? null) === [], 'tom kö ger cleared false');

echo "booking move queue clear: ok\n";
