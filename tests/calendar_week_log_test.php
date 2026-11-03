<?php

declare(strict_types=1);

/**
 * Veckologgen visar bara den vecka man tittar på, efter bokningens egna tider.
 * En bokning tor–tis syns i båda veckornas logg. Ombokning syns i både gamla
 * och nya veckan. När raden skrevs spelar ingen roll.
 * Sms och mejl hör till besökets vecka. Bekräftelse och flyttmejl syns när datan finns.
 */

$tmp = sys_get_temp_dir() . '/jeab-week-log-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
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

function wl_assert(bool $ok, string $message): void
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
$week40 = new DateTimeImmutable('2026-09-28 00:00:00', $tz);
$week42 = new DateTimeImmutable('2026-10-12 00:00:00', $tz);
$week43 = new DateTimeImmutable('2026-10-19 00:00:00', $tz);

/** @return list<string> */
function wl_keys(DateTimeImmutable $monday, ?JeabStore $store = null): array
{
    $keys = [];
    foreach (jeab_calendar_log_for_week($monday, 100, $store) as $entry) {
        $keys[] = (int) ($entry['booking_id'] ?? 0) . ':' . (string) ($entry['type'] ?? '') . ':' . (string) ($entry['mail_role'] ?? '');
    }
    return $keys;
}

jeab_calendar_log_write([
    'entries' => [
        [
            'id' => 1,
            'type' => 'cancelled',
            'at' => '2026-10-01 10:15:00',
            'booking_id' => 42,
            'starts_at' => '2026-10-14 07:00:00',
            'ends_at' => '2026-10-14 16:00:00',
            'title' => 'Ove Karlsson · Batteri · Roberto',
        ],
        [
            'id' => 2,
            'type' => 'booked',
            'at' => '2026-09-30 11:53:00',
            'booking_id' => 77,
            'starts_at' => '2026-10-15 00:00:00',
            'ends_at' => '2026-10-20 00:00:00',
            'title' => 'Anna Höglund · Sol · Sam L',
        ],
        [
            'id' => 3,
            'type' => 'mail',
            'at' => '2026-09-30 12:00:00',
            'booking_id' => 77,
            'starts_at' => '2026-10-15 00:00:00',
            'ends_at' => '2026-10-20 00:00:00',
            'title' => 'Anna Höglund · Sol · Sam L',
        ],
        [
            'id' => 4,
            'type' => 'sms',
            'at' => '2026-10-14 08:00:00',
            'booking_id' => 77,
            'starts_at' => '2026-10-15 00:00:00',
            'ends_at' => '2026-10-20 00:00:00',
            'title' => 'Anna Höglund · Sol · Sam L',
        ],
        [
            'id' => 5,
            'type' => 'mail',
            'at' => '2026-10-01 09:00:00',
            'booking_id' => 42,
            'starts_at' => '2026-10-14 07:00:00',
            'ends_at' => '2026-10-14 16:00:00',
            'title' => 'Ove Karlsson · Batteri · Roberto',
        ],
        [
            'id' => 6,
            'type' => 'rescheduled',
            'at' => '2026-10-01 10:15:00',
            'booking_id' => 42,
            'starts_at' => '2026-10-14 07:00:00',
            'ends_at' => '2026-10-14 16:00:00',
            'prev_starts_at' => '2026-10-08 07:00:00',
            'prev_ends_at' => '2026-10-08 16:00:00',
            'title' => 'Ove Karlsson · Batteri · Roberto',
        ],
    ],
    'backfilled' => true,
]);

$v42 = wl_keys($week42);
$v43 = wl_keys($week43);
$v40 = wl_keys($week40);
$v41 = wl_keys(new DateTimeImmutable('2026-10-05 00:00:00', $tz));

wl_assert(in_array('42:cancelled:', $v42, true), 'avbokning av besök 14 okt syns i v42 där besöket låg');
wl_assert(!in_array('42:cancelled:', $v40, true), 'avbokning syns inte i veckan den skrevs');
wl_assert(in_array('42:rescheduled:', $v42, true), 'ombokning till 14 okt syns i nya veckan');
wl_assert(in_array('42:rescheduled:', $v41, true), 'ombokning från 8 okt syns i gamla veckan');
wl_assert(!in_array('42:rescheduled:', $v40, true), 'ombokning syns inte i veckan den skrevs');
wl_assert(!in_array('77:booked:', $v40, true), 'bokad 30 sep syns inte i v40 när besöket ligger i v42–43');

wl_assert(in_array('77:booked:', $v42, true), 'tor–tis bokad syns i första veckan');
wl_assert(in_array('77:booked:', $v43, true), 'tor–tis bokad syns i andra veckan');
wl_assert(in_array('77:mail:', $v42, true) && in_array('77:sms:', $v42, true), 'mejl och sms syns i första veckan');
wl_assert(in_array('77:mail:', $v43, true) && in_array('77:sms:', $v43, true), 'mejl och sms syns i andra veckan');
wl_assert(in_array('42:mail:', $v42, true), 'mejl för endagsbesök syns i besökets vecka');
wl_assert(!in_array('42:mail:', $v40, true), 'mejl följer inte med till veckan det skickades');

$confirm = jeab_calendar_log_line([
    'type' => 'booked',
    'at' => '2026-09-30 11:53:00',
    'title' => 'Anna',
    'confirm_mail' => 'sent',
]);
$confirmMissing = jeab_calendar_log_line([
    'type' => 'booked',
    'at' => '2026-09-30 11:53:00',
    'title' => 'Anna',
    'confirm_mail' => 'unsent',
]);
$moveNo = jeab_calendar_log_line([
    'type' => 'rescheduled',
    'at' => '2026-10-01 10:15:00',
    'title' => 'Ove',
    'move_mail' => 'unsent',
]);
$moveYes = jeab_calendar_log_line([
    'type' => 'rescheduled',
    'at' => '2026-10-01 10:15:00',
    'title' => 'Ove',
    'move_mail' => 'sent',
]);
$mailLine = jeab_calendar_log_line([
    'type' => 'mail',
    'at' => '2026-09-30 12:00:00',
    'title' => 'Anna',
    'mail_role' => 'confirm',
]);
$smsLine = jeab_calendar_log_line([
    'type' => 'sms',
    'at' => '2026-10-14 08:00:00',
    'title' => 'Anna',
]);
wl_assert(!str_contains($confirm, 'Bekräftelsemejl skickat'), 'bokad rad säger inte att mejlet gick vid bokningstiden');
wl_assert(str_contains($confirmMissing, 'Bekräftelsemejl inte skickat'), 'bokad rad visar att bekräftelsemejl saknas');
wl_assert(str_contains($moveNo, 'Flyttmejl inte skickat'), 'ombokad rad visar att flyttmejl saknas');
wl_assert(str_contains($moveYes, 'Flyttmejl skickat'), 'ombokad rad visar att flyttmejl gått');
wl_assert(str_contains($mailLine, 'Bekräftelsemejl') && !str_contains($mailLine, 'Skickade sms'), 'bekräftelsemejl är en mejrad');
wl_assert(str_contains($smsLine, 'Skickade sms'), 'sms-raden finns kvar');

jeab_calendar_log_append('rescheduled', [
    'id' => 9,
    'customer_name' => 'Span',
    'job_type' => 'sol',
    'assignee' => 'Sam L',
    'starts_at' => '2026-10-15 07:00:00',
    'ends_at' => '2026-10-20 16:00:00',
], '2026-10-13 09:00:00', 'web', [
    'prev_starts_at' => '2026-10-08 07:00:00',
    'prev_ends_at' => '2026-10-08 11:00:00',
]);
$stored = null;
foreach (jeab_calendar_log_read()['entries'] as $entry) {
    if ((int) ($entry['booking_id'] ?? 0) === 9) {
        $stored = $entry;
    }
}
wl_assert(is_array($stored) && ($stored['ends_at'] ?? '') === '2026-10-20 16:00:00', 'loggen sparar sluttid');
wl_assert(is_array($stored) && ($stored['prev_starts_at'] ?? '') === '2026-10-08 07:00:00', 'loggen sparar förra starten');

$store = jeab_store(true);
$span = jeab_create_booking(
    $store,
    'Span Korsar',
    ['Sam W'],
    '2026-10-15 07:00:00',
    '2026-10-20 16:00:00',
    'laddbox',
    [
        'company' => 'malmo',
        'assignees' => ['Sam W'],
        'email' => 'span@example.com',
        'phone' => '+46701234567',
    ]
);
$spanId = (int) $span['id'];
jeab_calendar_log_write([
    'entries' => [[
        'id' => 1,
        'type' => 'booked',
        'at' => '2026-09-30 11:00:00',
        'booking_id' => $spanId,
        'starts_at' => (string) $span['starts_at'],
        'title' => 'Span Korsar',
    ]],
    'backfilled' => true,
]);
$withStore42 = wl_keys($week42, $store);
$withStore43 = wl_keys($week43, $store);
wl_assert(in_array($spanId . ':booked:', $withStore42, true), 'saknad sluttid fylls från bokningen i första veckan');
wl_assert(in_array($spanId . ':booked:', $withStore43, true), 'saknad sluttid fylls från bokningen i andra veckan');

file_put_contents(jeab_sms_sent_path(), json_encode([
    (string) $spanId => [
        'starts_at' => (string) $span['starts_at'],
        'sms' => true,
        'sms_at' => '2026-10-14 18:00:00',
        'email' => true,
        'email_at' => '2026-10-01 08:00:00',
        'move_email' => true,
        'move_email_at' => '2026-10-02 09:00:00',
    ],
], JSON_UNESCAPED_UNICODE));
$channel42 = jeab_calendar_log_for_week($week42, 100, $store);
$sawSms = false;
$sawConfirm = false;
$sawMove = false;
$bookedLine = '';
foreach ($channel42 as $entry) {
    if ((int) ($entry['booking_id'] ?? 0) !== $spanId) {
        continue;
    }
    $type = (string) ($entry['type'] ?? '');
    if ($type === 'sms') {
        $sawSms = true;
    }
    if ($type === 'mail' && (string) ($entry['mail_role'] ?? '') === 'confirm') {
        $sawConfirm = true;
    }
    if ($type === 'mail' && (string) ($entry['mail_role'] ?? '') === 'move') {
        $sawMove = true;
    }
    if ($type === 'booked') {
        $bookedLine = jeab_calendar_log_line($entry);
    }
}
wl_assert($sawSms && $sawConfirm, 'sms och bekräftelsemejl hämtas till besökets vecka');
wl_assert($sawMove, 'flyttmejl hämtas när den datan finns');
wl_assert(!str_contains($bookedLine, 'Bekräftelsemejl skickat'), 'bokad rad i veckan säger inte att mejlet redan gått vid bokningen');

echo "calendar week log: ok\n";
