<?php

declare(strict_types=1);

/**
 * Testvägen för flyttmejl. Flaggan BOOKING_MOVE_MAIL är av.
 * Vitlistan släpper bara listade testbokningar. Hooken booking.move_mail_test
 * mejlar en testbokning och aldrig en riktig kund. dry=1 är standard.
 * Inget riktigt mejl lämnar maskinen.
 */

$home = sys_get_temp_dir() . '/jeab-move-mail-test-ids-' . getmypid();
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
file_put_contents($data . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-move-mail-test']));

require dirname(__DIR__) . '/src/bootstrap.php';

function mt_assert(bool $ok, string $message): void
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
    $n = count($captured);

    return [
        'ok' => true,
        'path' => 'inkorg',
        'error' => null,
        'id' => 'thr-' . $n,
        'thread_id' => 'thr-' . $n,
        'graph_id' => 'g-' . $n,
    ];
};

mt_assert(jeab_booking_move_mail_parse_ids('10, 0, -3, x, 2') === [2, 10], 'listan tar positiva id och slänger skräp');
mt_assert(jeab_booking_move_mail_parse_ids([4, '4', '05', 0]) === [4], 'dubbletter och nollor faller bort');
mt_assert(jeab_booking_move_mail_enabled() === false, 'flaggan är av');
mt_assert(jeab_booking_move_mail_test_ids() === [], 'utan källa är vitlistan tom');

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

$store = jeab_store(true);
mt_assert($store instanceof JeabPdoStore, 'butiken är SQL');

$insert = static function (JeabStore $store, string $name, string $email, string $start, string $end) use ($aged): array {
    $customer = $store->insertCustomer($name, jeab_now());

    return $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => $name,
        'email' => $email,
        'phone' => '',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'laddbox',
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => 'booked',
        'created_at' => $aged,
        'updated_at' => $aged,
    ]);
};
$confirm = static function (int $id, string $starts) use ($aged): void {
    jeab_sms_save_sent($id, [
        'starts_at' => $starts,
        'email' => true,
        'email_at' => $aged,
        'email_subject' => 'Bekräftelse',
        'email_thread_id' => 'thr-confirm-' . $id,
    ]);
};
$pending = static function (int $id): bool {
    $done = jeab_sms_sent_store()[(string) $id] ?? [];

    return is_array($done) && !empty($done['move_pending']);
};
$queueCount = static function (JeabStore $store, int $id): int {
    unset($store);

    return count(jeab_booking_move_mail_rows_for($id)['booking_move_queue']);
};
$mailsTo = static function (string $email) use (&$captured): int {
    $n = 0;
    foreach ($captured as $mail) {
        if (($mail['to'] ?? '') === $email) {
            $n++;
        }
    }

    return $n;
};

$real = $insert($store, 'Ada Riktig', 'ada.riktig@kund.se', $startA, $endA);
$listedTest = $insert($store, 'Bodil Exempel', 'bodil.move@example.com', $startA, $endA);
$otherTest = $insert($store, 'Cilla Exempel', 'cilla.move@example.net', $startA, $endA);
$confirm((int) $real['id'], $startA);
$confirm((int) $listedTest['id'], $startA);
$confirm((int) $otherTest['id'], $startA);

$before = count($captured);
jeab_update_booking($store, (int) $real['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
mt_assert($pending((int) $real['id']) === false && $queueCount($store, (int) $real['id']) === 0, 'flaggan av köar inte en riktig kund');
jeab_update_booking($store, (int) $otherTest['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
mt_assert($pending((int) $otherTest['id']) === false, 'testbokning utanför listan köas inte');
mt_assert(count($captured) === $before, 'sparningen skickar inget');

putenv('BOOKING_MOVE_MAIL_TEST_IDS=' . (int) $listedTest['id'] . ', ' . (int) $real['id']);
$_ENV['BOOKING_MOVE_MAIL_TEST_IDS'] = (int) $listedTest['id'] . ', ' . (int) $real['id'];
mt_assert(jeab_booking_move_mail_test_ids() === [(int) $listedTest['id'], (int) $real['id']] || jeab_booking_move_mail_test_ids() === [(int) $real['id'], (int) $listedTest['id']], 'env läser båda id:na');
$ids = jeab_booking_move_mail_test_ids();
sort($ids, SORT_NUMERIC);
$expect = [(int) $listedTest['id'], (int) $real['id']];
sort($expect, SORT_NUMERIC);
mt_assert($ids === $expect, 'env-listan är de två id:na');

jeab_update_booking($store, (int) $real['id'], ['starts_at' => $startC, 'ends_at' => $endC]);
mt_assert($pending((int) $real['id']) === false && $queueCount($store, (int) $real['id']) === 0, 'listad riktig kund styrs av flaggan och köas inte');
jeab_update_booking($store, (int) $listedTest['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
mt_assert($pending((int) $listedTest['id']) === true, 'listad testbokning köas när flaggan är av');
mt_assert($queueCount($store, (int) $listedTest['id']) === 1, 'kön har den listade testbokningen');
mt_assert(count($captured) === $before, 'kön skickar inget vid sparning');

$tick = jeab_sms_tick($store);
mt_assert(($tick['sent_email'] ?? 0) === 1 && $mailsTo('bodil.move@example.com') === 1, 'cronen skickar flyttmejl bara till den listade testbokningen');
mt_assert($mailsTo('ada.riktig@kund.se') === 0 && $mailsTo('cilla.move@example.net') === 0, 'varken riktig kund eller olustad testbokning får mejl');
$rows = jeab_booking_move_mail_rows_for((int) $listedTest['id']);
mt_assert($rows['booking_move_queue'] === [] && count($rows['booking_move_sent']) === 1 && ($rows['booking_move_sent'][0]['starts_at'] ?? '') === $startB, 'cronen skriver booking_move_sent och tömmer kön');

putenv('BOOKING_MOVE_MAIL_TEST_IDS');
unset($_ENV['BOOKING_MOVE_MAIL_TEST_IDS']);
file_put_contents($privat . '/flags.json', json_encode([
    'BOOKING_MOVE_MAIL_TEST_IDS' => [(int) $otherTest['id']],
    'STEG4_CUTOVER' => 1,
], JSON_UNESCAPED_UNICODE) . "\n");
mt_assert(jeab_booking_move_mail_test_ids() === [(int) $otherTest['id']], 'flags.json ger vitlistan');
jeab_update_booking($store, (int) $otherTest['id'], ['starts_at' => $startC, 'ends_at' => $endC]);
mt_assert($pending((int) $otherTest['id']) === true && $queueCount($store, (int) $otherTest['id']) === 1, 'filens id köar testbokningen');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-move-mail-test';
$kept = jeab_flags_set_dispatch(['name' => 'STEG4_CUTOVER', 'value' => 1, 'dry' => 0]);
$disk = json_decode((string) file_get_contents($privat . '/flags.json'), true);
mt_assert(!empty($kept['ok']) && is_array($disk) && ($disk['BOOKING_MOVE_MAIL_TEST_IDS'] ?? []) === [(int) $otherTest['id']], 'flags.set behåller test-id-listan');
mt_assert(jeab_booking_move_mail_test_ids() === [(int) $otherTest['id']], 'listan gäller efter flags.set');

putenv('BOOKING_MOVE_MAIL_TEST_IDS=' . (int) $listedTest['id']);
$_ENV['BOOKING_MOVE_MAIL_TEST_IDS'] = (string) (int) $listedTest['id'];
$union = jeab_booking_move_mail_test_ids();
sort($union, SORT_NUMERIC);
$unionExpect = [(int) $listedTest['id'], (int) $otherTest['id']];
sort($unionExpect, SORT_NUMERIC);
mt_assert($union === $unionExpect, 'env och fil slås ihop');
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'] = '';
mt_assert(jeab_booking_move_mail_test_ids() === [], 'GLOBALS tom lista vinner över env och fil');
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS']);

$flagged = [
    'id' => 4242,
    'customer_name' => 'Doris Flagga',
    'email' => 'doris.flagga@kund.se',
    'phone' => '',
    'is_test' => true,
    'status' => 'booked',
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'assignees' => ['Elin'],
    'starts_at' => $startB,
    'ends_at' => $endB,
];
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'] = '4242';
$confirm(4242, $startA);
$beforeFlag = count($captured);
jeab_booking_mark_move_mail(4242, $startB, $flagged);
mt_assert($pending(4242) === true, 'is_test på raden räcker för vitlistan även med riktig adress');
$plain = $flagged;
$plain['is_test'] = false;
$plain['id'] = 4243;
$confirm(4243, $startA);
jeab_booking_mark_move_mail(4243, $startB, $plain);
mt_assert($pending(4243) === false && $queueCount($store, 4243) === 0, 'samma adress utan is_test köas inte');
$sentFlag = jeab_booking_send_move_mail($flagged);
mt_assert(!empty($sentFlag['sent']) && $mailsTo('doris.flagga@kund.se') === 1 && count($captured) === $beforeFlag + 1, 'vitlistad is_test-rad skickas när flaggan är av');
$sentPlain = jeab_booking_send_move_mail($plain + ['starts_at' => $startB]);
mt_assert(empty($sentPlain['sent']) && ($sentPlain['reason'] ?? '') === 'skip' && $mailsTo('doris.flagga@kund.se') === 1, 'olistad riktig rad skickas inte');
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS']);
putenv('BOOKING_MOVE_MAIL_TEST_IDS');
unset($_ENV['BOOKING_MOVE_MAIL_TEST_IDS']);

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
mt_assert(str_contains($hookSrc, "=== 'booking.move_mail_test'") && str_contains($hookSrc, 'jeab_booking_move_mail_test_dispatch'), 'hook.php tar booking.move_mail_test');

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN']);
$deniedGet = jeab_booking_move_mail_test_dispatch($store, ['booking_id' => (int) $otherTest['id'], 'dry' => 0]);
mt_assert(($deniedGet['status'] ?? 0) === 401 && empty($deniedGet['mailed']), 'hook utan POST nekas');
$_SERVER['REQUEST_METHOD'] = 'POST';
$deniedPost = jeab_booking_move_mail_test_dispatch($store, ['booking_id' => (int) $otherTest['id'], 'dry' => 0]);
mt_assert(($deniedPost['status'] ?? 0) === 401 && empty($deniedPost['mailed']) && $queueCount($store, (int) $otherTest['id']) === 1, 'hook utan token skickar inte');
mt_assert(count($captured) === $beforeFlag + 1, 'nekad hook lämnar inget mejl');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-move-mail-test';
$bad = jeab_booking_move_mail_test_dispatch($store, ['ids' => [(int) $otherTest['id']], 'dry' => 0]);
mt_assert(($bad['status'] ?? 0) === 400 && empty($bad['mailed']), 'flera id nekas');
$missing = jeab_booking_move_mail_test_dispatch($store, ['booking_id' => 4242]);
mt_assert(($missing['status'] ?? 0) === 404 && ($missing['dry'] ?? 0) === 1, 'okänt id är 404 och dry är standard');

$dry = jeab_booking_move_mail_test_dispatch($store, ['booking_id' => (int) $otherTest['id']]);
mt_assert(
    !empty($dry['ok']) && ($dry['dry'] ?? 0) === 1 && empty($dry['mailed']) && $dry['result'] === null
    && count($dry['booking_move_queue'] ?? []) === 1
    && ($dry['booking_move_queue'][0]['booking_id'] ?? 0) === (int) $otherTest['id']
    && ($dry['booking_move_queue'][0]['starts_at'] ?? '') === $startC
    && ($dry['booking_move_sent'] ?? null) === [],
    'dry visar kön och skickar inte'
);
mt_assert($mailsTo('cilla.move@example.net') === 0 && $queueCount($store, (int) $otherTest['id']) === 1, 'dry lämnar kön orörd');

$firstOfTwo = $insert($store, 'Ebba Exempel', 'ebba.move@example.com', $startA, $endA);
$secondOfTwo = $insert($store, 'Frida Exempel', 'frida.move@example.org', $startA, $endA);
$confirm((int) $firstOfTwo['id'], $startA);
$confirm((int) $secondOfTwo['id'], $startA);
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'] = [(int) $firstOfTwo['id'], (int) $secondOfTwo['id']];
jeab_update_booking($store, (int) $firstOfTwo['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
jeab_update_booking($store, (int) $secondOfTwo['id'], ['starts_at' => $startC, 'ends_at' => $endC]);
mt_assert($queueCount($store, (int) $firstOfTwo['id']) === 1 && $queueCount($store, (int) $secondOfTwo['id']) === 1, 'två testbokningar ligger i kön');
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'] = [];
$beforeHook = count($captured);
$one = jeab_booking_move_mail_test_dispatch($store, [
    'id' => (int) $firstOfTwo['id'],
    'dry' => 0,
    'deliver_to' => 'demo.person@example.invalid',
]);
mt_assert(
    !empty($one['ok']) && ($one['dry'] ?? 1) === 0 && !empty($one['mailed'])
    && ($one['result']['reason'] ?? '') === 'sent'
    && ($one['booking_move_queue'] ?? null) === []
    && count($one['booking_move_sent'] ?? []) === 1
    && ($one['booking_move_sent'][0]['starts_at'] ?? '') === $startB
    && ($one['booking_move_sent'][0]['booking_id'] ?? 0) === (int) $firstOfTwo['id'],
    'dry=0 skickar samma steg som cronen för ett id'
);
mt_assert($mailsTo('demo.person@example.invalid') === 1 && $mailsTo('ebba.move@example.com') === 0 && $mailsTo('frida.move@example.org') === 0, 'mejlet går till sam och inte till testkunderna');
$lastMail = $captured[count($captured) - 1] ?? [];
mt_assert(str_contains((string) ($lastMail['text'] ?? ''), 'Hej Ebba'), 'texten är ritad för den valda testbokningen');
mt_assert(!isset($lastMail['thread_id']) && !isset($lastMail['hs_conversation_id']), 'testutskicket svarar inte i kundens tråd');
mt_assert(count($captured) === $beforeHook + 1 && $queueCount($store, (int) $secondOfTwo['id']) === 1, 'den andra testbokningen ligger kvar');
mt_assert(!array_key_exists('_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE', $GLOBALS), 'tvingande testläge lämnas inte kvar');
$stillOff = jeab_sms_tick($store);
mt_assert(($stillOff['sent_email'] ?? 0) === 0 && $mailsTo('frida.move@example.org') === 0, 'cronen tar inte resten när flaggan och listan är av');

$GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] = true;
jeab_update_booking($store, (int) $real['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
mt_assert($pending((int) $real['id']) === true && $queueCount($store, (int) $real['id']) === 1, 'flaggan på köar den riktiga kunden');
$beforeRealHook = count($captured);
$refused = jeab_booking_move_mail_test_dispatch($store, [
    'booking_id' => (int) $real['id'],
    'dry' => 0,
]);
mt_assert(
    empty($refused['ok']) && ($refused['status'] ?? 0) === 409 && empty($refused['mailed'])
    && ($refused['error'] ?? '') === 'inte en testbokning'
    && count($refused['booking_move_queue'] ?? []) === 1,
    'hooken vägrar en riktig kund även med dry=0 och flaggan på'
);
mt_assert(count($captured) === $beforeRealHook && $queueCount($store, (int) $real['id']) === 1, 'den riktiga kön är orörd');
$cronReal = jeab_sms_tick($store);
mt_assert(($cronReal['sent_email'] ?? 0) === 1 && $mailsTo('ada.riktig@kund.se') === 1, 'cronen får skicka den riktiga kunden när flaggan är på');
mt_assert($mailsTo('frida.move@example.org') === 0, 'olustad testbokning mejlas inte bara för att flaggan är på');

jeab_update_booking($store, (int) $real['id'], ['starts_at' => $startC, 'ends_at' => $endC]);
mt_assert($pending((int) $real['id']) === true, 'ny flytt av riktig kund köas när flaggan är på');
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE'] = (int) $real['id'];
$beforeForce = count($captured);
$forcedReal = jeab_booking_send_move_mail($store->getBooking((int) $real['id']) ?? []);
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE']);
mt_assert(empty($forcedReal['sent']) && ($forcedReal['reason'] ?? '') === 'skip' && count($captured) === $beforeForce && $queueCount($store, (int) $real['id']) === 1, 'tvingat utskick mejlar inte en riktig kund');

unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL'], $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'], $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE']);
echo "booking move mail test ids: ok\n";
