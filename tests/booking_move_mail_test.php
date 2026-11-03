<?php

declare(strict_types=1);

/**
 * Flyttmejl: när personal flyttar tiden efter att bekräftelsen gått
 * skrivs en väntande post. Ny flytt ersätter posten. Sparningen skickar
 * inget. Samma sms-cron som bekräftelserna tar sluttiden. Flaggan
 * BOOKING_MOVE_MAIL är av som standard. Inget riktigt mejl lämnar maskinen.
 */

$tmp = sys_get_temp_dir() . '/jeab-move-mail-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
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

require dirname(__DIR__) . '/src/bootstrap.php';

function mm_assert(bool $ok, string $message): void
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

$src = (string) file_get_contents(dirname(__DIR__) . '/src/bookings.php');
mm_assert(str_contains($src, 'BOOKING_MOVE_MAIL'), 'kommentaren nämner BOOKING_MOVE_MAIL');
mm_assert(str_contains($src, 'jeab_booking_mark_move_mail'), 'ändring märker för flyttmejl');
mm_assert(str_contains($src, 'Ingen kundbekräftelse vid ändring'), 'kommentaren om kundbekräftelse finns kvar');
mm_assert(str_contains($src, 'Sparningen skickar inget mejl'), 'sparningen skickar inget flyttmejl');
mm_assert(str_contains($src, 'Samma sms-cron som skickar bekräftelserna'), 'flyttmejlet går med bekräftelse-cronen');
mm_assert(!str_contains($src, '15 minuter'), 'bokningskommentaren har ingen 15-minutersväntan');

mm_assert(jeab_booking_move_mail_enabled() === false, 'flaggan är av utan env och utan GLOBALS');
putenv('BOOKING_MOVE_MAIL=1');
$_ENV['BOOKING_MOVE_MAIL'] = '1';
mm_assert(jeab_booking_move_mail_enabled() === true, 'env 1 slår på');
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] = false;
mm_assert(jeab_booking_move_mail_enabled() === false, 'GLOBALS av vinner över env');
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL']);
putenv('BOOKING_MOVE_MAIL');
unset($_ENV['BOOKING_MOVE_MAIL']);
mm_assert(jeab_booking_move_mail_enabled() === false, 'utan env är flaggan av igen');

$letter = jeab_email_move_letter();
mm_assert(str_contains($letter, '{hello}') && str_contains($letter, '{nar}'), 'flyttmallen använder samma token som bekräftelsen');
mm_assert(str_contains($letter, 'Vi har tyvärr behövt flytta din tid'), 'flyttmallen säger att tiden tyvärr flyttats');
mm_assert(str_contains($letter, 'Den nya tiden är {nar}'), 'flyttmallen har den nya tiden');
mm_assert(str_contains($letter, 'svara bara på det här mejlet med förslag på senare tider'), 'flyttmallen ber kunden svara med senare tider');
mm_assert(in_array('flytt-jarfalla', jeab_notiser_editable_ids(), true) && in_array('flytt-malmo', jeab_notiser_editable_ids(), true), 'mallen kan redigeras för Järfälla och Malmö');

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
mm_assert($store instanceof JeabPdoStore, 'butiken är SQL');

$insert = static function (
    JeabStore $store,
    string $name,
    string $email,
    string $start,
    string $end,
    string $createdAt
): array {
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
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
};

$age = static function (JeabStore $store, array $row, DateTimeImmutable $now): array {
    return $store->saveBooking((int) $row['id'], [
        'updated_at' => $now->modify('-3 hours')->format('Y-m-d H:i:s'),
    ]);
};

$pending = static function (int $id): bool {
    $done = jeab_sms_sent_store()[(string) $id] ?? [];
    return is_array($done) && !empty($done['move_pending']);
};

$sentMove = static function (int $id): array {
    $done = jeab_sms_sent_store()[(string) $id] ?? [];
    return is_array($done) ? $done : [];
};

$off = $insert($store, 'Anna Offson', 'anna.off@kund.se', $startA, $endA, $aged);
$tickOffConfirm = jeab_sms_tick($store);
mm_assert(($tickOffConfirm['sent_email'] ?? 0) === 1, 'bekräftelsen går när flaggan är av');
mm_assert(jeab_sms_email_already_sent((int) $off['id']), 'bekräftelsen är markerad som skickad');
$beforeOffMove = count($captured);
$offMoved = jeab_update_booking($store, (int) $off['id'], [
    'starts_at' => $startB,
    'ends_at' => $endB,
]);
mm_assert((string) $offMoved['starts_at'] === $startB, 'tiden flyttades med flaggan av');
mm_assert($pending((int) $off['id']) === false, 'flaggan av märker inte för flyttmejl');
$age($store, $offMoved, $now);
$tickOffMove = jeab_sms_tick($store);
mm_assert(($tickOffMove['sent_email'] ?? 0) === 0, 'tickern skickar inget flyttmejl när flaggan är av');
mm_assert(count($captured) === $beforeOffMove, 'inget extra mejl med flaggan av');

$GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] = true;
$on = $insert($store, 'Bertil Onsson', 'bertil.on@kund.se', $startA, $endA, $aged);
$tickOnConfirm = jeab_sms_tick($store);
mm_assert(($tickOnConfirm['sent_email'] ?? 0) === 1, 'bekräftelsen går med flaggan på');
$beforeOnMove = count($captured);
jeab_update_booking($store, (int) $on['id'], [
    'starts_at' => $startB,
    'ends_at' => $endB,
]);
mm_assert($pending((int) $on['id']) === true, 'ändrad tid efter bekräftelse märks för flyttmejl');
$queued = $store->pdo()->query('SELECT starts_at FROM booking_move_queue')->fetchAll(PDO::FETCH_ASSOC);
mm_assert(count($queued) === 1 && (string) ($queued[0]['starts_at'] ?? '') === $startB, 'en väntande post med den nya tiden');
mm_assert(count($captured) === $beforeOnMove, 'första flytten skickar inget');

jeab_update_booking($store, (int) $on['id'], [
    'starts_at' => $startC,
    'ends_at' => $endC,
]);
$queuedMid = $store->pdo()->query('SELECT booking_id, starts_at FROM booking_move_queue')->fetchAll(PDO::FETCH_ASSOC);
mm_assert(count($queuedMid) === 1 && (string) ($queuedMid[0]['starts_at'] ?? '') === $startC, 'ny flytt ersätter den väntande posten');
mm_assert(count($captured) === $beforeOnMove, 'andra flytten skickar inget');
$tickMove = jeab_sms_tick($store);
mm_assert(($tickMove['sent_email'] ?? 0) === 1, 'sms-cron skickar ett flyttmejl med sluttiden');
mm_assert(count($captured) === $beforeOnMove + 1, 'ett flyttmejl lämnade mocken');
$moveMail = $captured[$beforeOnMove];
mm_assert(($moveMail['to'] ?? '') === 'bertil.on@kund.se', 'flyttmejlet går till kunden');
mm_assert(str_contains((string) ($moveMail['text'] ?? ''), 'Vi har tyvärr behövt flytta din tid'), 'flyttmejlet är kort');
mm_assert(str_contains((string) ($moveMail['text'] ?? ''), 'Den nya tiden är'), 'flyttmejlet har den nya tiden');
mm_assert(str_contains((string) ($moveMail['text'] ?? ''), 'svara bara på det här mejlet'), 'flyttmejlet ber om svar med senare tider');
$whenB = jeab_booking_when(['starts_at' => $startB, 'ends_at' => $endB]);
$whenC = jeab_booking_when(['starts_at' => $startC, 'ends_at' => $endC]);
$narC = $whenC['clock'] !== '' ? $whenC['day'] . ' ' . $whenC['clock'] : $whenC['day'];
mm_assert(!str_contains((string) ($moveMail['text'] ?? ''), ($whenB['clock'] !== '' ? $whenB['day'] . ' ' . $whenB['clock'] : $whenB['day'])), 'mellantiden är inte med');
mm_assert(str_contains((string) ($moveMail['text'] ?? ''), $narC), 'flyttmejlet innehåller sluttiden');
$confirmMail = $captured[$beforeOnMove - 1];
mm_assert((string) ($moveMail['subject'] ?? '') === (string) ($confirmMail['subject'] ?? ''), 'ämnet är samma som bekräftelsen');
mm_assert((string) ($moveMail['thread_id'] ?? '') === 'thr-' . $beforeOnMove, 'svaret går i bekräftelsens tråd');
mm_assert((string) ($moveMail['reply_graph_id'] ?? '') === 'g-' . $beforeOnMove, 'Graph-svaret pekar på bekräftelsen');
mm_assert(($moveMail['company'] ?? '') === 'jarfalla', 'Järfälla använder Järfällas avsändare');
$sentLog = $store->pdo()->prepare('SELECT starts_at FROM booking_move_sent WHERE booking_id = ?');
$sentLog->execute([(int) $on['id']]);
$sentSlots = $sentLog->fetchAll(PDO::FETCH_COLUMN);
mm_assert($sentSlots === [$startC], 'SQL-loggen har bokning och sluttid');
$doneOn = $sentMove((int) $on['id']);
mm_assert(!empty($doneOn['move_email']), 'move_email sätts efter utskick');
mm_assert(trim((string) ($doneOn['move_email_at'] ?? '')) !== '', 'move_email_at sätts efter utskick');
mm_assert(empty($doneOn['move_pending']), 'pending rensas efter utskick');

$again = jeab_sms_tick($store);
mm_assert(($again['sent_email'] ?? 0) === 0, 'andra tickern skickar inte flyttmejlet igen');
mm_assert(count($captured) === $beforeOnMove + 1, 'en gång per sluttid');

$dupDone = $sentMove((int) $on['id']);
$dupDone['notified_starts_at'] = $startA;
$dupDone['move_pending'] = true;
$dupDone['move_email'] = false;
jeab_sms_save_sent((int) $on['id'], $dupDone);
jeab_booking_move_mail_queue_put((int) $on['id'], $startC);
$dupTick = jeab_sms_tick($store);
mm_assert(($dupTick['sent_email'] ?? 0) === 0 && count($captured) === $beforeOnMove + 1, 'SQL-loggen stoppar samma bokning och sluttid');

$revert = $insert($store, 'Hanna Tillbaka', 'hanna.tillbaka@kund.se', $startA, $endA, $aged);
jeab_sms_tick($store);
jeab_update_booking($store, (int) $revert['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
mm_assert($pending((int) $revert['id']) === true, 'första flytten skrivs som väntande');
jeab_update_booking($store, (int) $revert['id'], ['starts_at' => $startA, 'ends_at' => $endA]);
mm_assert($pending((int) $revert['id']) === false, 'tillbaka till bekräftad tid tar bort posten');
$revertQueue = $store->pdo()->prepare('SELECT COUNT(*) FROM booking_move_queue WHERE booking_id = ?');
$revertQueue->execute([(int) $revert['id']]);
mm_assert((int) $revertQueue->fetchColumn() === 0, 'kön är tom när sluttiden redan är meddelad');
$beforeRevert = count($captured);
$tickBack = jeab_sms_tick($store);
mm_assert(($tickBack['sent_email'] ?? 0) === 0 && count($captured) === $beforeRevert, 'återställd tid skickar inget');

$second = jeab_update_booking($store, (int) $on['id'], [
    'starts_at' => $startB,
    'ends_at' => $endB,
]);
mm_assert($pending((int) $on['id']) === true, 'ny flytt märks på nytt');
mm_assert(empty($sentMove((int) $on['id'])['move_email']), 'tidigare flyttmejl nollställs vid ny ändring');
mm_assert(count($captured) === $beforeRevert, 'nya flytten skickar inget vid sparning');
$beforeSecond = count($captured);
$tickSecond = jeab_sms_tick($store);
mm_assert(($tickSecond['sent_email'] ?? 0) === 1, 'ny ändring ger ett nytt flyttmejl');
$secondMail = $captured[$beforeSecond];
$narB = $whenB['clock'] !== '' ? $whenB['day'] . ' ' . $whenB['clock'] : $whenB['day'];
mm_assert(str_contains((string) ($secondMail['text'] ?? ''), $narB), 'andra flyttmejlet har den senaste tiden');
mm_assert(!empty($sentMove((int) $on['id'])['move_email']), 'move_email sätts för den nya ändringen');

jeab_notiser_save_body('flytt-jarfalla', "{hello}\n\nEGEN-FLYTT {nar}. Svara bara på det här mejlet.", 'test@flowstack.example.test');
$edited = $insert($store, 'Frida Mall', 'frida.mall@kund.se', $startA, $endA, $aged);
jeab_sms_tick($store);
jeab_update_booking($store, (int) $edited['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
$beforeEdited = count($captured);
$tickEdited = jeab_sms_tick($store);
mm_assert(($tickEdited['sent_email'] ?? 0) === 1, 'redigerad mall skickas');
mm_assert(str_contains((string) ($captured[$beforeEdited]['text'] ?? ''), 'EGEN-FLYTT'), 'utskicket använder mallen under Notiser');

$malmoCustomer = $store->insertCustomer('Greta Malmö', jeab_now());
$malmo = $store->insertBooking([
    'customer_id' => $malmoCustomer['id'],
    'customer_name' => 'Greta Malmö',
    'email' => 'greta.malmo@kund.se',
    'phone' => '',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => $startA,
    'ends_at' => $endA,
    'status' => 'booked',
    'created_at' => $aged,
    'updated_at' => $aged,
]);
$tickMalmoConfirm = jeab_sms_tick($store);
mm_assert(($tickMalmoConfirm['sent_email'] ?? 0) === 1, 'Malmö-bekräftelsen går');
$malmoConfirmN = count($captured);
$malmoConfirm = $captured[$malmoConfirmN - 1];
jeab_update_booking($store, (int) $malmo['id'], ['starts_at' => $startB, 'ends_at' => $endB]);
$beforeMalmo = count($captured);
$tickMalmo = jeab_sms_tick($store);
mm_assert(($tickMalmo['sent_email'] ?? 0) === 1, 'Malmö får flyttmejl');
$malmoMail = $captured[$beforeMalmo];
mm_assert(($malmoMail['company'] ?? '') === 'malmo', 'Malmö-mejlet har bolaget malmo');
mm_assert(($malmoMail['from_email'] ?? '') === 'kontakt@malmo.example.test', 'Malmö använder samma avsändare som bekräftelsen');
mm_assert((string) ($malmoMail['subject'] ?? '') === (string) ($malmoConfirm['subject'] ?? ''), 'Malmö-ämnet är bekräftelsens ämne');
mm_assert((string) ($malmoMail['thread_id'] ?? '') === 'thr-' . $malmoConfirmN, 'Malmö-svaret ligger i bekräftelsens tråd');

$emailOnly = jeab_update_booking($store, (int) $on['id'], [
    'email' => 'bertil.ny@kund.se',
]);
mm_assert((string) ($emailOnly['email'] ?? '') === 'bertil.ny@kund.se', 'e-poständringen sparades');
mm_assert($pending((int) $on['id']) === false, 'ändrad e-post märker inte för flyttmejl');

$cancel = $insert($store, 'Cecilia Cancel', 'cecilia.cancel@kund.se', $startA, $endA, $aged);
jeab_sms_tick($store);
$cancelMoved = jeab_update_booking($store, (int) $cancel['id'], [
    'starts_at' => $startB,
    'ends_at' => $endB,
]);
mm_assert($pending((int) $cancel['id']) === true, 'avbokningsfallet märks före avbokning');
jeab_cancel_booking($store, (int) $cancel['id']);
$age($store, $cancelMoved, $now);
$beforeCancel = count($captured);
$tickCancel = jeab_sms_tick($store);
mm_assert(($tickCancel['sent_email'] ?? 0) === 0, 'avbokad bokning får inget flyttmejl');
mm_assert(count($captured) === $beforeCancel, 'inget mejl till avbokad kund');

$testRow = $insert($store, 'Doris Exempel', 'doris.move@example.com', $startA, $endA, $aged);
jeab_sms_save_sent((int) $testRow['id'], [
    'starts_at' => $startA,
    'email' => true,
    'email_at' => $aged,
]);
$testMoved = jeab_update_booking($store, (int) $testRow['id'], [
    'starts_at' => $startB,
    'ends_at' => $endB,
]);
mm_assert($pending((int) $testRow['id']) === false, 'testbokning märks inte för flyttmejl');
$age($store, $testMoved, $now);
$beforeTest = count($captured);
$tickTest = jeab_sms_tick($store);
mm_assert(($tickTest['sent_email'] ?? 0) === 0, 'testbokning får inget flyttmejl');
mm_assert(count($captured) === $beforeTest, 'inget mejl till testdomän');

$unsent = $insert($store, 'Erik Vantar', 'erik.vantar@kund.se', $startA, $endA, $aged);
$unsentMoved = jeab_update_booking($store, (int) $unsent['id'], [
    'starts_at' => $startB,
    'ends_at' => $endB,
]);
mm_assert($pending((int) $unsent['id']) === false, 'utan bekräftelse märks inget flyttmejl');
$age($store, $unsentMoved, $now);
$beforeUnsent = count($captured);
$tickUnsent = jeab_sms_tick($store);
mm_assert(($tickUnsent['sent_email'] ?? 0) === 1, 'utan tidigare bekräftelse går bekräftelsen som vanligt');
mm_assert(str_contains((string) ($captured[$beforeUnsent]['text'] ?? ''), 'sätter upp din laddbox'), 'det är bekräftelsen, inte flyttmejlet');
mm_assert(empty($sentMove((int) $unsent['id'])['move_email']), 'bekräftelsevägen sätter inte move_email');

unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL']);
$root = dirname(__DIR__);
$smsSrc = (string) file_get_contents($root . '/src/sms.php');
$tickSrc = (string) file_get_contents($root . '/src/tick.php');
$cronSrc = (string) file_get_contents($root . '/sms-cron.php');
$markAt = strpos($smsSrc, 'function jeab_booking_mark_move_mail');
$markEnd = strpos($smsSrc, 'function jeab_email_move_letter');
$mark = ($markAt === false || $markEnd === false) ? '' : substr($smsSrc, $markAt, $markEnd - $markAt);
mm_assert($mark !== '' && !str_contains($mark, 'jeab_booking_send_move_mail') && !str_contains($mark, 'jeab_system_mail_send'), 'sparningen anropar inte utskick');
mm_assert(!str_contains($tickSrc, 'jeab_booking_move_mail_drain'), '5-minuters-tickern skickar inte flyttmejl');
mm_assert(!str_contains((string) file_get_contents($root . '/tick.php'), 'jeab_booking_move_mail_drain'), 'tick.php skickar inte flyttmejl');
$runAt = strpos($smsSrc, 'function jeab_sms_tick_run');
$run = $runAt === false ? '' : substr($smsSrc, $runAt, 12000);
mm_assert(str_contains($run, 'jeab_booking_move_mail_drain($store)'), 'sms-cron-körningen tar flyttposterna');
mm_assert(str_contains($cronSrc, 'return jeab_sms_tick($store);'), 'sms-cron.php är samma körning som bekräftelserna');
mm_assert(!str_contains($smsSrc, 'not_before') && !str_contains($smsSrc, '15 * 60'), 'flyttkön har ingen egen väntetid');
$mvAt = strpos($smsSrc, 'function jeab_booking_send_move_mail');
$mv = $mvAt === false ? '' : substr($smsSrc, max(0, $mvAt - 400), 8400);
if (!str_contains($mv, "jeab_contacts_strict_block(\$row, 'email')")) { fwrite(STDERR, "FAIL flyttmejlet har strikt Kontakter-spärr\n"); exit(1); }
echo "OK  flyttmejlet har strikt Kontakter-spärr\n";
if (!str_contains($mv, 'jeab_system_mail_send') || !str_contains($mv, 'Aldrig mail()')) { fwrite(STDERR, "FAIL flyttmejlet ska gå via systemmejlet\n"); exit(1); }
echo "OK  flyttmejlet går via systemmejlet\n";
echo "booking move mail: ok\n";
