<?php

declare(strict_types=1);

/**
 * booking.move_mail_test dry=0 levererar bara till demo.person@example.invalid.
 * Testlead-spärren loggas och stoppar inte den vägen. Övriga testlead-stopp
 * säger «Testmottagare – skickas inte». booking.create_test / delete_test
 * och flags.set BOOKING_MOVE_MAIL. Inget mejl lämnar maskinen.
 */

$home = sys_get_temp_dir() . '/jeab-move-deliver-' . getmypid();
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
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE'],
    $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_DELIVER_TO'],
    $GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY']
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
file_put_contents($data . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-move-deliver']));

require dirname(__DIR__) . '/src/bootstrap.php';

function dl_assert(bool $ok, string $message): void
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
$captured = [];
$sawLogOnly = false;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured, &$sawLogOnly): array {
    $captured[] = $opts;
    if (!empty($GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY'])) {
        $sawLogOnly = true;
    }
    $n = count($captured);

    $from = trim((string) ($opts['from_email'] ?? ''));

    return [
        'ok' => true,
        'path' => 'inkorg',
        'error' => null,
        'id' => 'thr-' . $n,
        'thread_id' => 'thr-' . $n,
        'graph_id' => 'g-' . $n,
        'from' => $from,
        'to' => (string) ($opts['to'] ?? ''),
        'provider' => [
            'name' => 'graph',
            'code' => 202,
            'from' => $from,
            'to' => (string) ($opts['to'] ?? ''),
            'id' => 'g-' . $n,
            'ok' => true,
        ],
    ];
};

$weekday = $now->modify('+4 days')->setTime(10, 0, 0);
while ((int) $weekday->format('N') >= 6) {
    $weekday = $weekday->modify('+1 day');
}
$startA = $weekday->format('Y-m-d H:i:s');
$dayB = $weekday->modify('+1 day');
while ((int) $dayB->format('N') >= 6) {
    $dayB = $dayB->modify('+1 day');
}
$startB = $dayB->setTime(13, 0, 0)->format('Y-m-d H:i:s');

$store = jeab_store(true);
dl_assert($store instanceof JeabPdoStore, 'butiken är SQL');

$GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY'] = true;
$passed = send_guard_check('email', 'demo.person@example.invalid', 'booking_notice', 'auto', 'auto', 'system', '', [
    'body' => 'Hej',
    'deliver_customer_email' => 'flytttest@example.com',
]);
unset($GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY']);
dl_assert(($passed['reason'] ?? '') !== 'testlead' && !empty($passed['send']), 'hookvägen loggar testlead och släpper igenom, fick ' . (string) ($passed['reason'] ?? ''));
$still = send_guard_check('email', 'annan@example.com', 'booking_notice', 'auto', 'auto', 'system', '', ['body' => 'Hej']);
dl_assert(($still['reason'] ?? '') === 'testlead' && empty($still['send']), 'utan hookflaggan stoppas testmottagare igen');

$blocked = send_guard_check('email', 'kund@example.com', 'booking_notice', 'auto', 'auto', 'system', '', ['body' => 'Hej']);
dl_assert(($blocked['send'] ?? true) === false && ($blocked['reason'] ?? '') === 'testlead', 'send_guard stoppar testmottagare');
$gate = jeab_send_guard_gate('email', 'kund@example.com', 'Hej', ['mode' => 'auto', 'template' => 'booking_notice']);
dl_assert(
    ($gate['action'] ?? '') === 'stop' && ($gate['result']['error'] ?? '') === 'Testmottagare – skickas inte',
    'testlead har ett eget meddelande'
);
$logged = array_keys(is_array($GLOBALS['_JEAB_TEST_LEAD_LOGGED'] ?? null) ? $GLOBALS['_JEAB_TEST_LEAD_LOGGED'] : []);
dl_assert($logged !== [] && str_starts_with((string) $logged[0], 'send_guard|'), 'testlead-spärren loggas');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
dl_assert(str_contains($hook, "=== 'booking.create_test'") && str_contains($hook, 'jeab_booking_create_test_dispatch'), 'hook tar booking.create_test');
dl_assert(str_contains($hook, "=== 'booking.delete_test'") && str_contains($hook, 'jeab_booking_delete_test_dispatch'), 'hook tar booking.delete_test');
$mailSrc = (string) file_get_contents(dirname(__DIR__) . '/src/system_mail.php');
dl_assert(str_contains($mailSrc, "deliver_customer_email"), 'systemmejlet ger testkundens adress till send_guard');

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN']);
$denied = jeab_booking_create_test_dispatch($store, ['company' => 'jarfalla', 'start' => $startA, 'dry' => 0]);
dl_assert(($denied['status'] ?? 0) === 401 && empty($denied['created']), 'create_test utan token skapar inget');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-move-deliver';

$before = count($captured);
$badCompany = jeab_booking_create_test_dispatch($store, ['company' => 'stockholm', 'start' => $startA, 'dry' => 0]);
dl_assert(($badCompany['status'] ?? 0) === 400 && empty($badCompany['created']) && count($captured) === $before, 'okänt bolag skapar ingen bokning');
$dryCreate = jeab_booking_create_test_dispatch($store, ['company' => 'malmo', 'start' => $startA]);
dl_assert(!empty($dryCreate['ok']) && ($dryCreate['dry'] ?? 0) === 1 && empty($dryCreate['created']) && ($dryCreate['would_starts_at'] ?? '') === $startA, 'create_test är dry som standard');
dl_assert($store->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn() === 0, 'dry skapar ingen rad');

$jarfalla = jeab_booking_create_test_dispatch($store, ['company' => 'jarfalla', 'start' => $startA, 'dry' => 0]);
dl_assert(
    !empty($jarfalla['ok']) && !empty($jarfalla['created']) && !empty($jarfalla['confirmation_sent'])
    && ($jarfalla['company'] ?? '') === 'jarfalla' && ($jarfalla['email'] ?? '') === 'flytttest@example.com'
    && jeab_booking_customer_mail_is_test($store->getBooking((int) $jarfalla['id']) ?: []),
    'Järfälla-testbokningen är skapad och bekräftelsen är markerad, fick ' . json_encode($jarfalla, JSON_UNESCAPED_UNICODE)
);
$malmo = jeab_booking_create_test_dispatch($store, ['company' => 'malmo', 'start' => $startA, 'dry' => 0]);
dl_assert(
    !empty($malmo['ok']) && ($malmo['company'] ?? '') === 'malmo' && !empty($malmo['confirmation_sent'])
    && jeab_booking_customer_mail_is_test($store->getBooking((int) $malmo['id']) ?: []),
    'Malmö-testbokningen är skapad, fick ' . json_encode($malmo, JSON_UNESCAPED_UNICODE)
);
dl_assert(count($captured) === $before, 'skapandet skickar inget mejl');

$realCustomer = $store->insertCustomer('Ada Riktig', jeab_now());
$real = $store->insertBooking([
    'customer_id' => $realCustomer['id'],
    'customer_name' => 'Ada Riktig',
    'email' => 'ada.riktig@kund.se',
    'phone' => '',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $startA,
    'ends_at' => $weekday->modify('+2 hours')->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$refuseDelete = jeab_booking_delete_test_dispatch($store, ['id' => (int) $real['id'], 'dry' => 0]);
dl_assert(empty($refuseDelete['ok']) && ($refuseDelete['status'] ?? 0) === 409 && $store->getBooking((int) $real['id']) !== null, 'delete_test rör inte en riktig kund');
$dryDelete = jeab_booking_delete_test_dispatch($store, ['id' => (int) $jarfalla['id']]);
dl_assert(!empty($dryDelete['ok']) && ($dryDelete['dry'] ?? 0) === 1 && empty($dryDelete['deleted']) && $store->getBooking((int) $jarfalla['id']) !== null, 'delete_test är dry som standard');

$GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'] = [(int) $jarfalla['id'], (int) $malmo['id']];
jeab_update_booking($store, (int) $jarfalla['id'], [
    'starts_at' => $startB,
    'ends_at' => $dayB->setTime(15, 0, 0)->format('Y-m-d H:i:s'),
]);
jeab_update_booking($store, (int) $malmo['id'], [
    'starts_at' => $startB,
    'ends_at' => $dayB->setTime(15, 0, 0)->format('Y-m-d H:i:s'),
]);
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS']);
dl_assert(jeab_booking_move_mail_enabled() === false, 'flaggan är av inför utskicket');
dl_assert(count(jeab_booking_move_mail_rows_for((int) $jarfalla['id'])['booking_move_queue']) === 1, 'Järfälla ligger i kön');

$missingDeliver = jeab_booking_move_mail_test_dispatch($store, ['id' => (int) $jarfalla['id'], 'dry' => 0]);
dl_assert(($missingDeliver['status'] ?? 0) === 400 && empty($missingDeliver['mailed']) && count($captured) === $before, 'dry=0 utan deliver_to skickar inte');
$wrong = jeab_booking_move_mail_test_dispatch($store, [
    'id' => (int) $jarfalla['id'],
    'dry' => 0,
    'deliver_to' => 'nagon.annan@gmail.com',
]);
dl_assert(($wrong['status'] ?? 0) === 400 && empty($wrong['mailed']) && count(jeab_booking_move_mail_rows_for((int) $jarfalla['id'])['booking_move_queue']) === 1, 'annan adress nekas och kön ligger kvar');

$sawLogOnly = false;
$sentJ = jeab_booking_move_mail_test_dispatch($store, [
    'id' => (int) $jarfalla['id'],
    'dry' => 0,
    'deliver_to' => ' Sam.Exempel@gmail.com ',
]);
$jarfallaMail = $captured[count($captured) - 1] ?? [];
dl_assert(
    !empty($sentJ['mailed']) && $sawLogOnly
    && ($jarfallaMail['to'] ?? '') === 'demo.person@example.invalid'
    && ($jarfallaMail['company'] ?? '') === 'jarfalla'
    && !isset($jarfallaMail['from_email'])
    && str_contains((string) ($jarfallaMail['text'] ?? ''), 'Hej Flytttest')
    && ($jarfallaMail['deliver_customer_email'] ?? '') === 'flytttest@example.com'
    && !isset($jarfallaMail['thread_id'])
    && !isset($jarfallaMail['deliver_direct']),
    'Järfälla-mejlet ritas för testbokningen och går till sam'
);
dl_assert(($sentJ['result']['provider']['code'] ?? 0) === 202 && ($sentJ['result']['to'] ?? '') === 'demo.person@example.invalid', 'Järfälla lämnar tillbaka leverantörens svar');
dl_assert(!array_key_exists('_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY', $GLOBALS), 'loggflaggan lämnas inte kvar');

$sentM = jeab_booking_move_mail_test_dispatch($store, [
    'id' => (int) $malmo['id'],
    'dry' => 0,
    'deliver_to' => 'demo.person@example.invalid',
]);
$malmoMail = $captured[count($captured) - 1] ?? [];
dl_assert(
    !empty($sentM['mailed'])
    && ($malmoMail['to'] ?? '') === 'demo.person@example.invalid'
    && ($malmoMail['company'] ?? '') === 'malmo'
    && ($malmoMail['from_email'] ?? '') === 'kontakt@malmo.example.test'
    && ($malmoMail['from_name'] ?? '') === 'Demo Energi Malmö'
    && ($malmoMail['deliver_direct'] ?? 0) === 1,
    'Malmö-mejlet går från Malmös avsändare, fick ' . json_encode($malmoMail, JSON_UNESCAPED_UNICODE)
);
dl_assert(
    ($sentM['result']['path'] ?? '') === 'inkorg'
    && ($sentM['result']['provider']['code'] ?? 0) === 202
    && ($sentM['result']['provider']['to'] ?? '') === 'demo.person@example.invalid'
    && ($sentM['result']['from'] ?? '') === 'kontakt@malmo.example.test',
    'Malmö lämnar tillbaka leverantörens svar, fick ' . json_encode($sentM['result'] ?? null, JSON_UNESCAPED_UNICODE)
);

$gone = jeab_booking_delete_test_dispatch($store, ['id' => (int) $jarfalla['id'], 'dry' => 0]);
dl_assert(!empty($gone['deleted']) && $store->getBooking((int) $jarfalla['id']) === null, 'delete_test tar bort testbokningen');
$stillReal = $store->getBooking((int) $real['id']);
dl_assert(is_array($stillReal), 'den riktiga bokningen finns kvar');

$_SERVER['REQUEST_METHOD'] = 'POST';
$dryFlag = jeab_flags_set_dispatch(['name' => 'BOOKING_MOVE_MAIL', 'value' => 1]);
dl_assert(!empty($dryFlag['ok']) && !empty($dryFlag['dry']) && empty($dryFlag['written']) && !is_file($privat . '/flags.json'), 'flags.set BOOKING_MOVE_MAIL är dry som standard');
$badFlag = jeab_flags_set_dispatch(['name' => 'BOOKING_MOVE_MAIL', 'value' => 2, 'dry' => 0]);
dl_assert(($badFlag['status'] ?? 0) === 400 && !is_file($privat . '/flags.json'), 'annat än 0/1 skrivs inte');
$on = jeab_flags_set_dispatch(['name' => 'BOOKING_MOVE_MAIL', 'value' => 1, 'dry' => 0]);
dl_assert(!empty($on['written']) && jeab_booking_move_mail_file_value() === 1 && jeab_booking_move_mail_enabled() === true, 'filen slår på när env saknas');
putenv('BOOKING_MOVE_MAIL=0');
$_ENV['BOOKING_MOVE_MAIL'] = '0';
dl_assert(jeab_booking_move_mail_enabled() === false && jeab_booking_move_mail_file_value() === 1, 'env 0 vinner över filen');
putenv('BOOKING_MOVE_MAIL');
unset($_ENV['BOOKING_MOVE_MAIL']);
dl_assert(jeab_booking_move_mail_enabled() === true, 'utan env gäller filen igen');
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] = false;
dl_assert(jeab_booking_move_mail_enabled() === false, 'GLOBALS vinner över filen');
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL']);
$keep = jeab_flags_set_dispatch(['name' => 'STEG4_CUTOVER', 'value' => 0, 'dry' => 0]);
$file = json_decode((string) file_get_contents($privat . '/flags.json'), true);
dl_assert(!empty($keep['written']) && is_array($file) && ($file['BOOKING_MOVE_MAIL'] ?? null) === 1 && ($file['STEG4_CUTOVER'] ?? null) === 0, 'en annan flagga lämnar BOOKING_MOVE_MAIL');
$got = jeab_flags_get_dispatch([]);
dl_assert(($got['BOOKING_MOVE_MAIL'] ?? null) === 1, 'flags.get visar filens BOOKING_MOVE_MAIL');
$off = jeab_flags_set_dispatch(['name' => 'BOOKING_MOVE_MAIL', 'value' => 0, 'dry' => 0]);
dl_assert(!empty($off['written']) && jeab_booking_move_mail_file_value() === 0 && jeab_booking_move_mail_enabled() === false, '0 i filen slår av');
putenv('BOOKING_MOVE_MAIL=1');
$_ENV['BOOKING_MOVE_MAIL'] = '1';
dl_assert(jeab_booking_move_mail_enabled() === true && jeab_booking_move_mail_file_value() === 0, 'env 1 vinner över filens 0');

echo "booking move mail deliver: ok\n";
