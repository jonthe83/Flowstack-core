<?php

declare(strict_types=1);

/**
 * Veckologgen ur SQL (datumvillkor) ska visa samma rader som ett fullt svep,
 * och getBooking ska inte längre köras en gång per bokning i hela loggen.
 * Titlar som saknas fylls från en IN-fråga.
 */

$tmp = sys_get_temp_dir() . '/jeab-week-log-lookup-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
unset($GLOBALS['_JEAB_CALENDAR_LOG_PATH']);
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

function wk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function wk_norm(array $rows): string
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        ksort($row);
        $out[] = $row;
    }
    return (string) json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * Facit: hela loggen, getBooking en gång per boknings-id, sedan veckofilter.
 * Titeln fylls efteråt, som kalendersidan gjorde.
 *
 * @return list<array<string, mixed>>
 */
function wk_before(DateTimeImmutable $monday, JeabStore $store, ?string $company): array
{
    $from = $monday->setTime(0, 0)->format('Y-m-d H:i:s');
    $to = $monday->modify('+7 days')->setTime(0, 0)->format('Y-m-d H:i:s');
    $entries = jeab_calendar_log_read()['entries'];
    $history = jeab_calendar_log_history($entries);
    $bookings = [];
    $matched = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = (int) ($entry['booking_id'] ?? 0);
        $booking = null;
        if ($id > 0) {
            if (!array_key_exists($id, $bookings)) {
                $bookings[$id] = $store->getBooking($id);
            }
            $booking = is_array($bookings[$id]) ? $bookings[$id] : null;
        }
        if (!jeab_calendar_log_company_ok($booking, $company)) {
            continue;
        }
        $entry = jeab_calendar_log_resolve_dates($entry, $booking, $history);
        if (!jeab_calendar_log_in_week($entry, $from, $to)) {
            continue;
        }
        $matched[] = $entry;
    }
    $snapshot = jeab_sms_sent_store();
    if (!is_array($snapshot)) {
        $snapshot = [];
    }
    if ($snapshot !== []) {
        $haveSms = [];
        $haveMail = [];
        $haveMove = [];
        foreach ($matched as $entry) {
            $id = (int) ($entry['booking_id'] ?? 0);
            $type = (string) ($entry['type'] ?? '');
            if ($type === 'sms') {
                $haveSms[$id] = true;
            }
            if ($type === 'mail') {
                if ((string) ($entry['mail_role'] ?? '') === 'move') {
                    $haveMove[$id] = true;
                } else {
                    $haveMail[$id] = true;
                }
            }
        }
        foreach ($snapshot as $idKey => $done) {
            if (!is_array($done)) {
                continue;
            }
            $id = (int) $idKey;
            if ($id <= 0) {
                continue;
            }
            $row = $store->getBooking($id);
            if (!$row || !jeab_calendar_log_company_ok($row, $company)) {
                continue;
            }
            $interval = jeab_calendar_log_interval(
                isset($row['starts_at']) ? (string) $row['starts_at'] : null,
                isset($row['ends_at']) ? (string) $row['ends_at'] : null
            );
            if ($interval === null || $interval[0] >= $to || $interval[1] <= $from) {
                continue;
            }
            $ends = jeab_parse_datetime(isset($row['ends_at']) ? (string) $row['ends_at'] : null);
            $base = [
                'booking_id' => $id,
                'starts_at' => $interval[0],
                'ends_at' => $ends ?? $interval[1],
                'title' => jeab_booking_title($row),
                'id' => 0,
            ];
            if (!empty($done['sms']) && empty($haveSms[$id])) {
                $at = trim((string) ($done['sms_at'] ?? ''));
                $matched[] = $base + ['type' => 'sms', 'at' => $at !== '' ? $at : $interval[0]];
            }
            if (!empty($done['email']) && empty($haveMail[$id])) {
                $at = trim((string) ($done['email_at'] ?? ''));
                $matched[] = $base + ['type' => 'mail', 'at' => $at !== '' ? $at : $interval[0], 'mail_role' => 'confirm'];
            }
            if (jeab_calendar_log_move_mail_sent($done) && empty($haveMove[$id])) {
                $at = trim((string) ($done['move_email_at'] ?? ''));
                $matched[] = $base + ['type' => 'mail', 'at' => $at !== '' ? $at : $interval[0], 'mail_role' => 'move'];
            }
        }
        $matched = jeab_calendar_log_channel_status($matched, $snapshot);
    }
    foreach ($matched as $i => $entry) {
        if (trim((string) ($entry['title'] ?? '')) !== '') {
            continue;
        }
        $logged = $bookings[(int) ($entry['booking_id'] ?? 0)] ?? null;
        if (!is_array($logged)) {
            $logged = $store->getBooking((int) ($entry['booking_id'] ?? 0));
        }
        if (is_array($logged)) {
            $matched[$i]['title'] = jeab_booking_title($logged);
        }
    }
    $limit = 100;
    $sent = [];
    $other = [];
    foreach ($matched as $entry) {
        $type = (string) ($entry['type'] ?? '');
        if ($type === 'sms' || $type === 'mail') {
            $sent[] = $entry;
        } else {
            $other[] = $entry;
        }
    }
    $sort = static function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    };
    usort($sent, $sort);
    usort($other, $sort);
    $sent = array_slice($sent, 0, $limit);
    $week = array_merge($sent, array_slice($other, 0, max(0, $limit - count($sent))));
    usort($week, $sort);
    return $week;
}

date_default_timezone_set('Europe/Stockholm');
$tz = new DateTimeZone('Europe/Stockholm');
$mon = static fn(string $d): DateTimeImmutable => new DateTimeImmutable($d . ' 00:00:00', $tz);

$store = jeab_store(true);
wk_assert($store instanceof JeabPdoStore, 'sqlite-lager');
$pdo = $store->pdo();
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;
wk_assert(jeab_log_pdo() instanceof PDO, 'loggtabeller finns');

$now = '2026-01-02 08:00:00';
$customerId = (int) $store->insertCustomer('Kalenderkund', $now)['id'];
$insert = static function (string $name, string $starts, string $ends, string $company = 'jarfalla') use ($store, $now, $customerId): array {
    return $store->insertBooking([
        'customer_id' => $customerId,
        'customer_name' => $name,
        'assignees' => ['Roberto'],
        'assignee' => 'Roberto',
        'company' => $company,
        'job_type' => 'batteri',
        'starts_at' => $starts,
        'ends_at' => $ends,
        'status' => 'booked',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};

$sten = $insert('Sten Håkansson', '2026-10-20 07:00:00', '2026-10-20 16:00:00');
$span = $insert('Span Korsar', '2026-10-15 07:00:00', '2026-10-20 16:00:00');
$filip = $insert('Filip', '2026-10-28 00:00:00', '2026-10-30 00:00:00', 'malmo');
$moved = $insert('Omskriven', '2024-03-04 07:00:00', '2024-03-04 16:00:00');
$stenId = (int) $sten['id'];
$spanId = (int) $span['id'];
$filipId = (int) $filip['id'];
$movedId = (int) $moved['id'];

$entries = [
    [
        'id' => 4,
        'type' => 'booked',
        'at' => '2026-10-06 21:04:00',
        'booking_id' => $stenId,
        'starts_at' => '2026-10-20 07:00:00',
        'ends_at' => '2026-10-20 16:00:00',
        'title' => '',
    ],
    [
        'id' => 5,
        'type' => 'mail',
        'at' => '2026-10-07 00:00:00',
        'booking_id' => $stenId,
        'title' => 'Sten Håkansson · Batteri · Roberto',
    ],
    [
        'id' => 20,
        'type' => 'booked',
        'at' => '2026-09-30 11:00:00',
        'booking_id' => $spanId,
        'starts_at' => '2026-10-15 07:00:00',
        'title' => 'Span Korsar · Batteri · Roberto',
    ],
    [
        'id' => 11,
        'type' => 'booked',
        'at' => '2026-10-07 15:28:00',
        'booking_id' => $filipId,
        'starts_at' => '2026-10-28 00:00:00',
        'ends_at' => '2026-10-30 00:00:00',
        'title' => 'Filip · Batteri · Roberto',
    ],
    [
        'id' => 60,
        'type' => 'booked',
        'at' => '2026-09-01 10:00:00',
        'booking_id' => 9002,
        'starts_at' => '2026-11-03 07:00:00',
        'ends_at' => '2026-11-03 16:00:00',
        'title' => 'Gammal Kund · Batteri · Roberto',
    ],
    [
        'id' => 61,
        'type' => 'cancelled',
        'at' => '2026-09-02 10:00:00',
        'booking_id' => 9002,
        'title' => 'Gammal Kund · Batteri · Roberto',
    ],
    [
        'id' => 10,
        'type' => 'rescheduled',
        'at' => '2026-10-02 10:00:00',
        'booking_id' => 9004,
        'starts_at' => '2026-12-02 07:00:00',
        'ends_at' => '2026-12-02 16:00:00',
        'prev_starts_at' => '2026-11-12 07:00:00',
        'prev_ends_at' => '2026-11-12 16:00:00',
        'title' => 'Flyttad Kund · Batteri · Roberto',
    ],
    [
        'id' => 50,
        'type' => 'booked',
        'at' => '2026-10-01 09:00:00',
        'booking_id' => $movedId,
        'starts_at' => '2026-10-20 07:00:00',
        'ends_at' => '2026-10-20 16:00:00',
        'title' => 'Omskriven · Batteri · Roberto',
    ],
];
$noiseIds = [];
for ($i = 0; $i < 25; $i++) {
    $row = $insert('Brus ' . $i, '2024-03-04 07:00:00', '2024-03-04 11:00:00');
    $noiseIds[] = (int) $row['id'];
    $entries[] = [
        'id' => 1000 + $i,
        'type' => 'booked',
        'at' => '2024-03-01 10:00:00',
        'booking_id' => (int) $row['id'],
        'starts_at' => '2024-03-04 07:00:00',
        'ends_at' => '2024-03-04 11:00:00',
        'title' => 'Brus ' . $i . ' · Batteri · Roberto',
    ];
}
jeab_calendar_log_write(['entries' => $entries, 'backfilled' => true]);

$lineOnly = [
    'id' => 80,
    'type' => 'sms',
    'at' => '2026-10-14 08:00:00',
    'booking_id' => $spanId,
    'starts_at' => '2026-10-15 07:00:00',
    'ends_at' => '2026-10-20 16:00:00',
    'title' => 'Span Korsar · Batteri · Roberto',
];
$overwrite = [
    'id' => 50,
    'type' => 'booked',
    'at' => '2026-10-01 09:00:00',
    'booking_id' => $movedId,
    'starts_at' => '2024-03-04 07:00:00',
    'ends_at' => '2024-03-04 16:00:00',
    'title' => 'Omskriven · Batteri · Roberto',
];
foreach ([$lineOnly, $overwrite] as $entry) {
    $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    wk_assert(is_string($json) && $json !== '', 'rad kan kodas');
    jeab_log_line_insert($pdo, 'calendar_event', jeab_log_line_hash($json), (string) $entry['at'], $json);
}

file_put_contents(jeab_sms_sent_path(), json_encode([
    (string) $spanId => [
        'starts_at' => '2026-10-15 07:00:00',
        'sms' => true,
        'sms_at' => '2026-10-14 18:00:00',
        'email' => true,
        'email_at' => '2026-10-01 08:00:00',
    ],
], JSON_UNESCAPED_UNICODE));

$cases = [
    [$mon('2026-10-12'), 'jarfalla'],
    [$mon('2026-10-19'), 'jarfalla'],
    [$mon('2026-10-26'), 'jarfalla'],
    [$mon('2026-10-26'), 'malmo'],
    [$mon('2026-10-26'), null],
    [$mon('2026-11-02'), 'jarfalla'],
    [$mon('2026-11-09'), 'jarfalla'],
    [$mon('2026-11-16'), 'jarfalla'],
    [$mon('2024-03-04'), 'jarfalla'],
];

$before = 0;
$after = 0;
$GLOBALS['_JEAB_CALENDAR_LOG_SQL_WEEK'] = 0;
foreach ($cases as [$monday, $company]) {
    $label = $monday->format('Y-m-d') . '/' . ($company ?? 'alla');
    $GLOBALS['_JEAB_GET_BOOKING_CALLS'] = 0;
    $old = wk_before($monday, $store, $company);
    $before += (int) $GLOBALS['_JEAB_GET_BOOKING_CALLS'];
    $GLOBALS['_JEAB_GET_BOOKING_CALLS'] = 0;
    $fresh = jeab_calendar_log_for_week($monday, 100, $store, $company);
    $after += (int) $GLOBALS['_JEAB_GET_BOOKING_CALLS'];
    $same = wk_norm($old) === wk_norm($fresh);
    if (!$same) {
        fwrite(STDERR, "Före {$label}: " . wk_norm($old) . "\n");
        fwrite(STDERR, "Efter {$label}: " . wk_norm($fresh) . "\n");
    }
    wk_assert($same, 'samma veckologg ' . $label);
}
wk_assert((int) $GLOBALS['_JEAB_CALENDAR_LOG_SQL_WEEK'] === count($cases), 'varje vecka läses med datumvillkor i SQL');
wk_assert($before > count($noiseIds), 'facit slår upp fler bokningar än bruset (' . $before . ')');
wk_assert($after < $before, 'getBooking-uppslag minskade från ' . $before . ' till ' . $after);
wk_assert($after === 0, 'veckologgen slår inte längre upp en bokning i taget');

$week43 = jeab_calendar_log_for_week($mon('2026-10-19'), 100, $store, 'jarfalla');
$titles = [];
$ids = [];
foreach ($week43 as $entry) {
    $ids[] = (int) ($entry['id'] ?? 0);
    if ((int) ($entry['booking_id'] ?? 0) === $stenId && (string) ($entry['type'] ?? '') === 'booked') {
        $titles[] = (string) ($entry['title'] ?? '');
    }
}
wk_assert($titles === ['Sten Håkansson · Batteri · Roberto'], 'tom titel fylls från bokningen');
wk_assert(in_array(80, $ids, true), 'rad som bara ligger i log_lines syns');
wk_assert(!in_array(50, $ids, true), 'händelseraden vinner över dokumentet när tiderna skiljer');
wk_assert(!in_array(1000, $ids, true), 'mars-rader syns inte i oktober');

echo "calendar week log lookup: ok\n";
