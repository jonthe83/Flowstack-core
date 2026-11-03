<?php

declare(strict_types=1);

/**
 * PR 29: H-CAL-1, H-CAL-2, H-CAL-4, H-SMS-1, H-SMS-3, H-SMS-4, H-SMS-5, H-SMS-6, H-TICK-2.
 * Påhittade rader. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-pr29-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_QUIET_BOOKING'] = true;
putenv('BOOKING_MOVE_MAIL=1');
$_ENV['BOOKING_MOVE_MAIL'] = '1';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

date_default_timezone_set('Europe/Stockholm');

$fail = 0;
function pr29(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    if (str_contains($url, 'sendMail') || str_contains($url, '/messages')) {
        return ['code' => 500, 'body' => ['error' => 'kundmejl är förbjudet']];
    }
    if ($method === 'GET' && str_contains($url, '/events/')) {
        if (str_contains($url, 'gone-del')) {
            return ['code' => 404, 'body' => []];
        }
        return ['code' => 200, 'body' => ['id' => 'still', 'isCancelled' => false, 'start' => ['dateTime' => '2026-10-26T08:00:00', 'timeZone' => 'Europe/Stockholm'], 'end' => ['dateTime' => '2026-10-26T16:00:00', 'timeZone' => 'Europe/Stockholm']]];
    }
    return ['code' => 200, 'body' => []];
};

$mailCalls = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mailCalls): array {
    $mailCalls++;
    return ['ok' => true, 'path' => 'stub', 'id' => 'stub'];
};
$smsCalls = [];
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to, string $text) use (&$smsCalls): array {
    $smsCalls[] = ['to' => $to, 'text' => $text];
    return ['ok' => true];
};

$store = jeab_store(true);
pr29($store instanceof JeabPdoStore, 'testet kör SQLite');

$tz = new DateTimeZone('Europe/Stockholm');

// H-CAL-4: UTC utan Z, och heldag från UTC-midnatt dagen före.
$timed = jeab_outlook_event_times([
    'isAllDay' => false,
    'start' => ['dateTime' => '2026-06-15T08:00:00', 'timeZone' => 'UTC'],
    'end' => ['dateTime' => '2026-06-15T12:00:00', 'timeZone' => 'UTC'],
]);
pr29(
    is_array($timed) && $timed['starts_at'] === '2026-06-15 10:00:00' && $timed['ends_at'] === '2026-06-15 14:00:00',
    'H-CAL-4 UTC utan Z konverteras till Stockholm'
);
$allDay = jeab_outlook_event_times([
    'isAllDay' => true,
    'start' => ['dateTime' => '2026-06-14T22:00:00', 'timeZone' => 'UTC'],
    'end' => ['dateTime' => '2026-06-15T22:00:00', 'timeZone' => 'UTC'],
]);
pr29(
    is_array($allDay) && $allDay['starts_at'] === '2026-06-15 00:00:00' && $allDay['ends_at'] === '2026-06-16 00:00:00',
    'H-CAL-4 heldag från UTC landar på Stockholmsdatum'
);
$local = jeab_outlook_event_times([
    'isAllDay' => false,
    'start' => ['dateTime' => '2026-10-26T08:00:00', 'timeZone' => 'Europe/Stockholm'],
    'end' => ['dateTime' => '2026-10-26T16:00:00', 'timeZone' => 'Europe/Stockholm'],
]);
pr29(
    is_array($local) && $local['starts_at'] === '2026-10-26 08:00:00' && $local['ends_at'] === '2026-10-26 16:00:00',
    'H-CAL-4 lokal Graph-tid är oförändrad'
);

$now = new DateTimeImmutable('now', $tz);
$visit = $now->modify('+1 day')->setTime(9, 0, 0);
while ((int) $visit->format('N') >= 6) {
    $visit = $visit->modify('+1 day');
}
$startA = $visit->format('Y-m-d H:i:s');
$endA = $visit->modify('+2 hours')->format('Y-m-d H:i:s');
$startB = $visit->modify('+1 day')->format('Y-m-d H:i:s');
$endB = $visit->modify('+1 day')->modify('+2 hours')->format('Y-m-d H:i:s');
$aged = $now->modify('-5 hours')->format('Y-m-d H:i:s');

$insert = static function (string $name, string $email, string $phone = '+46701111111') use ($store, $aged, $startA, $endA): array {
    $customer = $store->insertCustomer($name, jeab_now());

    return $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
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

$calRow = $insert('Cal Kund', 'cal.kund@exempel.se');
$calId = (int) $calRow['id'];
$link = [
    'booking_id' => $calId,
    'email' => 'saljare4@jarfalla.example.test',
    'resource' => 'Elin',
    'show_as' => 'busy',
    'graph_event_id' => 'evt-cal',
];

$changed = jeab_outlook_apply_link($store, $link, [
    'id' => 'evt-cal',
    '@removed' => ['reason' => 'changed'],
]);
$afterChanged = $store->getBooking($calId) ?: [];
pr29($changed === '' && ($afterChanged['status'] ?? '') === 'booked', 'H-CAL-1 reason=changed avbokar inte');

$goneUnconfirmed = jeab_outlook_apply_link($store, $link, [
    'id' => 'evt-still',
    '@removed' => ['reason' => 'deleted'],
]);
$afterUnconfirmed = $store->getBooking($calId) ?: [];
pr29($goneUnconfirmed === '' && ($afterUnconfirmed['status'] ?? '') === 'booked', 'H-CAL-1 deleted utan 404 avbokar inte');

$gone = jeab_outlook_apply_link($store, $link, [
    'id' => 'gone-del',
    '@removed' => ['reason' => 'deleted'],
]);
$afterGone = $store->getBooking($calId) ?: [];
pr29($gone === 'removed' && ($afterGone['status'] ?? '') === 'cancelled', 'H-CAL-1 deleted + GET 404 avbokar sista resursen');

$moveRow = $insert('Flytt Kund', 'flytt.kund@exempel.se');
$moveId = (int) $moveRow['id'];
jeab_sms_save_sent($moveId, [
    'starts_at' => $startA,
    'email' => true,
    'email_at' => $aged,
    'notified_starts_at' => $startA,
    'sms' => true,
    'sms_at' => $aged,
]);
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] = true;
$beforeMails = $mailCalls;
$movedKind = jeab_outlook_apply_link($store, [
    'booking_id' => $moveId,
    'email' => 'saljare4@jarfalla.example.test',
    'resource' => 'Elin',
    'show_as' => 'busy',
], [
    'id' => 'evt-move',
    'isAllDay' => false,
    'start' => ['dateTime' => str_replace(' ', 'T', $startB), 'timeZone' => 'Europe/Stockholm'],
    'end' => ['dateTime' => str_replace(' ', 'T', $endB), 'timeZone' => 'Europe/Stockholm'],
]);
$moved = $store->getBooking($moveId) ?: [];
$pending = jeab_sms_sent_store()[(string) $moveId] ?? [];
pr29($movedKind === 'updated' && ($moved['starts_at'] ?? '') === $startB, 'H-CAL-2 Outlook-tid skrivs till bokningen');
pr29(!empty($pending['move_pending']) && $mailCalls === $beforeMails, 'H-CAL-2 flytt märks utan att skicka mejl nu');
pr29((string) ($moved['updated_at'] ?? '') === $aged, 'H-CAL-2 mejlklockan rubbas inte');

$smsKeep = $insert('Sms Behall', 'sms.behall@exempel.se');
$smsKeepId = (int) $smsKeep['id'];
jeab_sms_save_sent($smsKeepId, [
    'starts_at' => $startA,
    'email' => true,
    'email_at' => $aged,
    'notified_starts_at' => $startA,
    'sms' => true,
    'sms_at' => $aged,
]);
unset($GLOBALS['_JEAB_BOOKING_MOVE_MAIL']);
putenv('BOOKING_MOVE_MAIL');
unset($_ENV['BOOKING_MOVE_MAIL']);
jeab_update_booking($store, $smsKeepId, ['starts_at' => $startB, 'ends_at' => $endB]);
$away = jeab_sms_sent_store()[(string) $smsKeepId] ?? [];
pr29(!empty($away['sms']), 'H-SMS-6 flytt med flaggan av nollar inte SMS');
jeab_update_booking($store, $smsKeepId, ['starts_at' => $startA, 'ends_at' => $endA]);
$reverted = jeab_sms_sent_store()[(string) $smsKeepId] ?? [];
pr29(!empty($reverted['sms']) && empty($reverted['move_pending']), 'H-SMS-6 SMS-flaggan överlever återställd tid');
$GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] = true;

$claimSrc = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
$claimAt = strpos($claimSrc, 'function jeab_booking_move_mail_sent_claim');
$claimEnd = strpos($claimSrc, 'function jeab_booking_move_mail_sent_release');
$claimFn = $claimAt !== false && $claimEnd !== false ? substr($claimSrc, $claimAt, $claimEnd - $claimAt) : '';
pr29(
    str_contains($claimFn, 'return false') && !preg_match('/if \(!\$pdo instanceof PDO\) \{\s*return true;/', $claimFn),
    'H-SMS-5 saknad PDO ger false, inte true'
);
pr29(jeab_booking_move_mail_sent_claim(4242, $startB) === true, 'H-SMS-5 första infogningen vinner');
pr29(jeab_booking_move_mail_sent_claim(4242, $startB) === false, 'H-SMS-5 samma nyckel skickar inte igen');

$todayStart = $now->setTime((int) $now->format('G') < 20 ? (int) $now->format('G') + 2 : 22, 0, 0);
if ($todayStart <= $now) {
    $todayStart = $now->modify('+1 hour');
}
$todayText = jeab_sms_text([
    'starts_at' => $todayStart->format('Y-m-d H:i:s'),
    'ends_at' => $todayStart->modify('+2 hours')->format('Y-m-d H:i:s'),
    'job_type' => 'laddbox',
    'company' => 'jarfalla',
]);
$tomorrowText = jeab_sms_text([
    'starts_at' => $now->modify('+1 day')->setTime(9, 0, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $now->modify('+1 day')->setTime(11, 0, 0)->format('Y-m-d H:i:s'),
    'job_type' => 'laddbox',
    'company' => 'jarfalla',
]);
pr29(str_contains($todayText, 'kommer idag') && !str_contains($todayText, 'imorgon'), 'H-SMS-3 dagens besök säger idag');
pr29(str_contains($tomorrowText, 'kommer imorgon'), 'H-SMS-3 morgondagens besök säger imorgon');

$smsWindow = $now->modify('+3 hours');
if ($smsWindow->format('Y-m-d') !== $now->format('Y-m-d')) {
    $smsWindow = $now->modify('+2 hours');
}
$smsStart = $smsWindow->format('Y-m-d H:i:s');
$smsEnd = $smsWindow->modify('+2 hours')->format('Y-m-d H:i:s');
$glue = $insert('Lim Telefon', 'lim.telefon@exempel.se', '070-111 22 33 / 070-444 55 66');
$store->saveBooking((int) $glue['id'], ['starts_at' => $smsStart, 'ends_at' => $smsEnd, 'updated_at' => $aged]);
$okPhone = $insert('Ok Telefon', 'ok.telefon@exempel.se', '070-111 22 33');
$store->saveBooking((int) $okPhone['id'], ['starts_at' => $smsStart, 'ends_at' => $smsEnd, 'updated_at' => $aged]);
$queuedRow = $insert('Koad Sms', 'koad.sms@exempel.se', '+46703333333');
$store->saveBooking((int) $queuedRow['id'], ['starts_at' => $smsStart, 'ends_at' => $smsEnd, 'updated_at' => $aged]);

$smsCalls = [];
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to, string $text) use (&$smsCalls, $queuedRow): array {
    $smsCalls[] = ['to' => $to, 'text' => $text];
    if ($to === '+46703333333') {
        return ['ok' => true, 'queued' => true];
    }
    return ['ok' => true];
};
$tick = jeab_sms_tick($store);
$tos = array_column($smsCalls, 'to');
pr29(!in_array('+467011122330704445566', $tos, true), 'H-SMS-4 limmade siffror skickas inte');
pr29(in_array('+46701112233', $tos, true), 'H-SMS-4 giltigt nummer skickas');
$glueSent = jeab_sms_sent_store()[(string) $glue['id']] ?? [];
pr29(empty($glueSent['sms']), 'H-SMS-4 limmat dubbelnummer markeras inte');
$queuedSent = jeab_sms_sent_store()[(string) $queuedRow['id']] ?? [];
pr29(empty($queuedSent['sms']), 'H-SMS-1 köat SMS markeras inte som skickat');
$okSent = jeab_sms_sent_store()[(string) $okPhone['id']] ?? [];
pr29(!empty($okSent['sms']), 'H-SMS-4 ett svenskt nummer går ut');

$queuedMail = $insert('Koad Mail', 'koad.mail@exempel.se');
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mailCalls): array {
    unset($opts);
    $mailCalls++;
    return ['ok' => true, 'path' => 'queued', 'queued' => true, 'id' => ''];
};
$mailResult = jeab_booking_send_customer_mail($store->getBooking((int) $queuedMail['id']) ?: $queuedMail);
$mailSent = jeab_sms_sent_store()[(string) $queuedMail['id']] ?? [];
pr29(($mailResult['reason'] ?? '') === 'queued' && empty($mailSent['email']), 'H-SMS-1 köat bekräftelsemejl markeras inte som skickat');
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mailCalls): array {
    unset($opts);
    $mailCalls++;
    return ['ok' => true, 'path' => 'stub', 'id' => 'stub'];
};

$past = $now->modify('-1 hour')->format('Y-m-d H:i:s');
$skip = jeab_send_guard_replay_booking_skip([
    'handler' => 'sms',
    'args' => ['booking_id' => $moveId, 'starts_at' => $past, 'to' => '+46701111111', 'text' => 'x'],
]);
$cancelledSkip = jeab_send_guard_replay_booking_skip([
    'handler' => 'sms',
    'args' => ['booking_id' => $calId, 'starts_at' => $startA, 'to' => '+46701111111', 'text' => 'x'],
]);
pr29(is_array($cancelledSkip) && ($cancelledSkip['reason'] ?? '') === 'booking-gone', 'H-SMS-1 replay hoppar över avbokad');
$futureSkip = jeab_send_guard_replay_booking_skip([
    'handler' => 'sms',
    'args' => ['booking_id' => $okPhone['id'], 'starts_at' => $smsStart, 'to' => '+46701112233', 'text' => 'x'],
]);
pr29($futureSkip === null || (($futureSkip['reason'] ?? '') === 'booking-past' && $smsStart <= jeab_now()), 'H-SMS-1 replay släpper framtida bokning');

$pdo = $store->pdo();
jeab_send_guard_ensure($pdo);
$t0 = strtotime('2026-06-01 12:00:00');
pr29($t0 !== false, 'H-TICK-2 klockan går att tolka');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0;
$job = base64_encode(serialize([
    'handler' => 'sms',
    'args' => [
        'to' => '+46705555555',
        'text' => 'test',
        'company' => 'jarfalla',
        'from' => null,
        'booking_id' => 0,
    ],
]));
$pdo->prepare(
    'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts, claimed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'sms',
    'sms',
    hash('sha256', 'sms-pr29'),
    hash('sha256', 'body-pr29'),
    jeab_send_guard_sql_stamp($t0 - 400),
    jeab_send_guard_sql_stamp($t0 - 60),
    $job,
    'sending',
    1,
    jeab_send_guard_sql_stamp($t0 - 60),
]);
$stuckId = (int) $pdo->lastInsertId();
$freshClaim = jeab_send_guard_claim($pdo, $stuckId);
pr29($freshClaim['token'] === '', 'H-TICK-2 färsk sending-rad tas inte');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 400;
$staleClaim = jeab_send_guard_claim($pdo, $stuckId);
pr29($staleClaim['token'] !== '' && $staleClaim['attempts'] === 2, 'H-TICK-2 sending äldre än timeout tas om');
jeab_send_guard_settle($pdo, $stuckId, $staleClaim['token'], 'failed', $staleClaim['attempts'], 'test');

$cols = jeab_send_guard_queue_column_names($pdo);
pr29(in_array('claimed_at', $cols, true), 'H-TICK-2 claimed_at finns på kön');

$guardNow = strtotime('2027-01-15 09:00:00');
pr29($guardNow !== false, 'H-SMS-2 klockan för dubbelsändning går att tolka');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $guardNow;
$openCount = static function (string $channel, string $rcptHash, string $bodyHash, string $template) use ($pdo): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM send_queue WHERE channel = ? AND rcpt_hash = ? AND body_hash = ? AND template = ? AND status IN (\'waiting\', \'planned\', \'sending\')'
    );
    $stmt->execute([$channel, $rcptHash, $bodyHash, $template]);

    return (int) $stmt->fetchColumn();
};
$queueTwin = static function (string $channel, string $recipient, string $template, string $body, string $status) use ($pdo, $guardNow, $openCount): array {
    $bodyHash = hash('sha256', $body);
    $rcptHash = jeab_send_guard_rcpt_hash($channel, $recipient);
    $pdo->prepare(
        'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts, claimed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $channel,
        $template,
        $rcptHash,
        $bodyHash,
        jeab_send_guard_sql_stamp($guardNow - 60),
        jeab_send_guard_sql_stamp($guardNow - 60),
        base64_encode(serialize(['handler' => $channel === 'sms' ? 'sms' : 'system_mail', 'args' => []])),
        $status,
        $status === 'sending' ? 1 : 0,
        $status === 'sending' ? jeab_send_guard_sql_stamp($guardNow - 30) : null,
    ]);
    $before = $openCount($channel, $rcptHash, $bodyHash, $template);
    $gate = send_guard_check($channel, $recipient, $template, 'auto', 'auto', 'system', $bodyHash, ['body' => $body]);

    return [$before, $gate, $openCount($channel, $rcptHash, $bodyHash, $template)];
};
[$mailBefore, $mailGate, $mailAfter] = $queueTwin('email', 'dubbel.bekraftelse@exempel.se', 'booking_notice', 'bekraftelse-redan-koad', 'planned');
pr29($mailBefore === 1 && !empty($mailGate['queued']) && empty($mailGate['send']) && $mailAfter === 1, 'H-SMS-2 köad bekräftelse skickas inte direkt igen');
[$smsBefore, $smsGate, $smsAfter] = $queueTwin('sms', '0701234567', 'booking_notice', 'sms-redan-pa-vag', 'sending');
pr29($smsBefore === 1 && !empty($smsGate['queued']) && empty($smsGate['send']) && $smsAfter === 1, 'H-SMS-2 SMS som redan skickas köas inte en gång till');
$freshBody = 'bekraftelse-utan-ko';
$freshGate = send_guard_check('email', 'ny.bekraftelse@exempel.se', 'booking_notice', 'auto', 'auto', 'system', hash('sha256', $freshBody), ['body' => $freshBody]);
pr29(!empty($freshGate['send']) && empty($freshGate['queued']), 'H-SMS-2 unikt utskick går fortfarande direkt');

$near = $insert('Nara Tid', 'nara.tid@exempel.se');
$nearId = (int) $near['id'];
$clock = new DateTimeImmutable(jeab_now(), $tz);
$oneMin = $clock->modify('-1 minute')->format('Y-m-d H:i:s');
$store->saveBooking($nearId, ['starts_at' => $oneMin, 'ends_at' => $clock->modify('+1 hour')->format('Y-m-d H:i:s')]);
$mailJob = [
    'handler' => 'system_mail',
    'args' => [
        'booking_id' => $nearId,
        'starts_at' => $oneMin,
        'opts' => ['booking_notice_kind' => 'mail', 'booking_id' => $nearId, 'starts_at' => $oneMin],
    ],
];
$smsJob = [
    'handler' => 'sms',
    'args' => [
        'booking_id' => $nearId,
        'starts_at' => $oneMin,
        'opts' => ['booking_notice_kind' => 'sms', 'booking_id' => $nearId, 'starts_at' => $oneMin],
    ],
];
$nearMail = jeab_send_guard_replay_booking_skip($mailJob);
$nearSms = jeab_send_guard_replay_booking_skip($smsJob);
pr29($nearMail === null, 'H-SMS-2 bekräftelsemejl en minut efter start kastas inte');
pr29(is_array($nearSms) && ($nearSms['reason'] ?? '') === 'booking-past', 'H-SMS-2 SMS efter start hoppas över');
$old = $insert('Gammal Tid', 'gammal.tid@exempel.se');
$oldId = (int) $old['id'];
$longAgo = $clock->modify('-20 minutes')->format('Y-m-d H:i:s');
$store->saveBooking($oldId, ['starts_at' => $longAgo, 'ends_at' => $clock->modify('-10 minutes')->format('Y-m-d H:i:s')]);
$oldMail = jeab_send_guard_replay_booking_skip([
    'handler' => 'system_mail',
    'args' => [
        'booking_id' => $oldId,
        'starts_at' => $longAgo,
        'opts' => ['booking_notice_kind' => 'mail', 'booking_id' => $oldId, 'starts_at' => $longAgo],
    ],
]);
pr29(is_array($oldMail) && ($oldMail['reason'] ?? '') === 'booking-past', 'H-SMS-2 bekräftelsemejl klart före femton minuter hoppas över');

$pdo->prepare(
    'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts, claimed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'email',
    'offert',
    hash('sha256', 'null-claim'),
    hash('sha256', 'null-body'),
    jeab_send_guard_sql_stamp($guardNow - 10),
    jeab_send_guard_sql_stamp($guardNow - 10),
    base64_encode(serialize(['handler' => 'system_mail', 'args' => []])),
    'sending',
    1,
    null,
]);
$nullId = (int) $pdo->lastInsertId();
jeab_settings_put($pdo, 'sqlite', 'send_queue_claimed_at_v1', 'again');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $guardNow + 5000;
$nullClaim = jeab_send_guard_claim($pdo, $nullId);
pr29($nullClaim['token'] === '', 'H-TICK-2 sending utan claimed_at tas inte om');
$savedTick = $GLOBALS['_JEAB_TICK'] ?? null;
$savedMigrate = $GLOBALS['_JEAB_DATA_MIGRATE'] ?? null;
$savedDdl = $GLOBALS['_JEAB_SCHEMA_DDL'] ?? null;
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_DATA_MIGRATE'], $GLOBALS['_JEAB_SCHEMA_DDL']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
jeab_send_guard_legacy_claims_backfill($pdo);
$nullRow = $pdo->query('SELECT claimed_at FROM send_queue WHERE id = ' . $nullId)->fetch(PDO::FETCH_ASSOC);
pr29(is_array($nullRow) && ($nullRow['claimed_at'] === null || $nullRow['claimed_at'] === ''), 'H-TICK-2 sidvisning stämplar inte gamla anspråk');
unset($GLOBALS['_JEAB_PAGE_REQUEST']);
if ($savedTick === null) {
    unset($GLOBALS['_JEAB_TICK']);
} else {
    $GLOBALS['_JEAB_TICK'] = $savedTick;
}
if ($savedMigrate === null) {
    unset($GLOBALS['_JEAB_DATA_MIGRATE']);
} else {
    $GLOBALS['_JEAB_DATA_MIGRATE'] = $savedMigrate;
}
if ($savedDdl === null) {
    unset($GLOBALS['_JEAB_SCHEMA_DDL']);
} else {
    $GLOBALS['_JEAB_SCHEMA_DDL'] = $savedDdl;
}
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $guardNow + 5000;
jeab_send_guard_legacy_claims_backfill($pdo);
$nullRow = $pdo->query('SELECT claimed_at FROM send_queue WHERE id = ' . $nullId)->fetch(PDO::FETCH_ASSOC);
$stamped = is_array($nullRow) ? jeab_send_guard_stamp($nullRow['claimed_at'] ?? null) : null;
pr29($stamped === $guardNow + 5000, 'H-TICK-2 migrate/tick sätter claimed_at till nu');
$held = jeab_send_guard_claim($pdo, $nullId);
pr29($held['token'] === '', 'H-TICK-2 nystämplat anspråk tas inte direkt');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $guardNow + 5000 + 301;
$retake = jeab_send_guard_claim($pdo, $nullId);
pr29($retake['token'] !== '' && $retake['attempts'] === 2, 'H-TICK-2 anspråk tas om efter timeout');
jeab_send_guard_settle($pdo, $nullId, $retake['token'], 'failed', $retake['attempts'], 'test');
$migrateSrc = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$tickSrc = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
pr29(substr_count($migrateSrc, 'jeab_send_guard_legacy_claims_backfill($pdo)') === 2, 'H-TICK-2 backfill ligger i båda migrate');
pr29(str_contains($tickSrc, 'jeab_send_guard_legacy_claims_backfill'), 'H-TICK-2 backfill ligger i tick');
$moveSrc = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
$moveAt = strpos($moveSrc, 'function jeab_booking_send_move_mail');
$moveDoc = $moveAt === false ? '' : substr($moveSrc, max(0, $moveAt - 400), 400);
pr29(str_contains($moveDoc, 'Aldrig mail()') && str_contains($moveDoc, 'jeab_system_mail_send'), 'H-SMS-2 flyttmejlet säger Aldrig mail()');

if ($fail > 0) {
    fwrite(STDERR, "pr29: {$fail} fel\n");
    exit(1);
}
echo "pr29 kalender/sms: ok\n";
