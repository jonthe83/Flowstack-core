<?php

declare(strict_types=1);

/**
 * Veckofrågan ska träffa samma bokningar som COALESCE(NULLIF(ends_at, ''), starts_at)
 * och bara det valda kontoret när bolaget är ifyllt. Outlook-vågen läser listan en gång.
 */

$tmp = sys_get_temp_dir() . '/jeab-bookings-between-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function bbq_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bbq_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $ids[] = (int) $row['id'];
        }
    }
    sort($ids);

    return $ids;
}

/** Gamla SQL-fönstret, plus PHP-kontoret. */
function bbq_legacy_ids(PDO $pdo, string $from, string $to, ?string $company): array
{
    $ids = [];
    foreach ($pdo->query('SELECT id, starts_at, ends_at, status, company FROM bookings') as $row) {
        if (!is_array($row) || (string) ($row['status'] ?? '') !== 'booked') {
            continue;
        }
        $starts = (string) ($row['starts_at'] ?? '');
        $ends = (string) ($row['ends_at'] ?? '');
        if ($ends === '') {
            $ends = $starts;
        }
        if (!($starts < $to && $ends > $from)) {
            continue;
        }
        if ($company !== null && jeab_booking_company($row) !== $company) {
            continue;
        }
        $ids[] = (int) $row['id'];
    }
    sort($ids);

    return $ids;
}

function bbq_html_ids(array $payload): array
{
    $flat = '';
    foreach (['timed', 'early', 'late', 'month', 'sols'] as $bucket) {
        foreach ($payload[$bucket] ?? [] as $items) {
            if (is_array($items)) {
                $flat .= implode('', $items);
            } elseif (is_string($items)) {
                $flat .= $items;
            }
        }
    }
    $flat .= implode('', $payload['allday'] ?? []);
    preg_match_all('/data-id="(\d+)"/', $flat, $m);
    $ids = array_map('intval', $m[1] ?? []);
    sort($ids);

    return array_values(array_unique($ids));
}

$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all'];
$_SESSION['jeab_company'] = 'jarfalla';
$GLOBALS['_JEAB_BOOKING_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
unset($GLOBALS['_JEAB_TICK']);
$store = jeab_store(true);
bbq_ok($store instanceof JeabPdoStore, 'sqlite-butik');
$pdo = $store->pdo();
$columnNames = static function (PDO $pdo): array {
    $names = [];
    foreach ($pdo->query('PRAGMA table_info(bookings)') as $col) {
        if (is_array($col)) {
            $names[] = (string) ($col['name'] ?? '');
        }
    }

    return $names;
};
$indexNames = static function (PDO $pdo): array {
    return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
};
bbq_ok(!jeab_booking_schema_work_allowed(), 'webbsida får inte migrera');
bbq_ok(!in_array('ends_sort', $columnNames($pdo), true), 'sidan skapar inte ends_sort');
bbq_ok(!in_array('bookings_company_status_starts', $indexNames($pdo), true), 'sidan skapar inte veckoindexet');
bbq_ok(jeab_settings_value($pdo, 'sqlite', 'bookings_week_query_v1') === null, 'sidan sätter inte veckoflaggan');
unset($GLOBALS['_JEAB_PAGE_REQUEST']);
bbq_ok(!jeab_booking_schema_work_allowed(), 'fpm utan tick får inte migrera');
jeab_booking_week_query_migrate($pdo, 'sqlite');
jeab_booking_company_canon($pdo, 'sqlite');
bbq_ok(!in_array('ends_sort', $columnNames($pdo), true), 'avvisad migrering skapar inte kolumnen');
$GLOBALS['_JEAB_TICK'] = true;
bbq_ok(jeab_booking_schema_work_allowed(), 'tick får migrera');
jeab_booking_week_query_migrate($pdo, 'sqlite');
jeab_booking_company_canon($pdo, 'sqlite');
jeab_booking_query_mode_read($pdo, 'sqlite');
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_BOOKING_SAPI']);
$mode = jeab_booking_query_mode($pdo);
bbq_ok(!empty($mode['ends_sort']) && !empty($mode['company']), 'veckofrågan är armerad');
$flag = jeab_settings_value($pdo, 'sqlite', 'bookings_week_query_v1');
bbq_ok($flag === 'ok', 'ends_sort-migreringen är ok: ' . (string) $flag);
$canonFlag = jeab_settings_value($pdo, 'sqlite', 'bookings_company_canon_v1');
bbq_ok(is_string($canonFlag) && str_starts_with($canonFlag, 'ok:blank='), 'bolagssteget räknade tomma: ' . (string) $canonFlag);
bbq_ok(in_array('bookings_company_status_starts', $indexNames($pdo), true), 'index company, status, starts_at');

$stamp = jeab_now();
$customer = $store->insertCustomer('Vecka ' . bin2hex(random_bytes(2)), $stamp);
$make = static function (JeabPdoStore $store, array $customer, string $stamp, array $data): array {
    $data['customer_id'] = $customer['id'];
    $data['customer_name'] = $data['customer_name'] ?? 'Kund';
    $data['created_at'] = $stamp;
    $data['updated_at'] = $stamp;
    $data['status'] = $data['status'] ?? 'booked';

    return $store->insertBooking($data);
};

$inWeek = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-28 09:00:00',
    'ends_at' => '2026-10-28 11:00:00',
]);
$span = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-30 15:00:00',
    'ends_at' => '2026-11-03 10:00:00',
]);
$emptyEnd = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-28 13:00:00',
    'ends_at' => '',
]);
$emptyBefore = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-19 09:00:00',
    'ends_at' => '',
]);
$boundary = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-26 00:00:00',
    'ends_at' => '',
]);
$fromBefore = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-25 18:00:00',
    'ends_at' => '2026-10-26 10:00:00',
]);
$earlyEnd = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-28 12:00:00',
    'ends_at' => '2026-10-28 08:00:00',
]);
$malmo = $make($store, $customer, $stamp, [
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => '2026-10-30 10:00:00',
    'ends_at' => '2026-10-30 12:00:00',
]);
$cancelled = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-28 15:00:00',
    'ends_at' => '2026-10-28 16:00:00',
    'status' => 'cancelled',
]);
$blank = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-27 09:00:00',
    'ends_at' => '2026-10-27 10:00:00',
]);
$jeabCo = $make($store, $customer, $stamp, [
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-10-27 11:00:00',
    'ends_at' => '2026-10-27 12:00:00',
]);
$malmoAlias = $make($store, $customer, $stamp, [
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'service',
    'starts_at' => '2026-10-27 13:00:00',
    'ends_at' => '2026-10-27 14:00:00',
]);

$sortOf = static function (PDO $pdo, int $id): string {
    $stmt = $pdo->prepare('SELECT ends_sort FROM bookings WHERE id = ?');
    $stmt->execute([$id]);

    return (string) $stmt->fetchColumn();
};
bbq_ok($sortOf($pdo, (int) $emptyEnd['id']) === '2026-10-28 13:00:00', 'tomt slut sparas som starten');
bbq_ok($sortOf($pdo, (int) $span['id']) === '2026-11-03 10:00:00', 'satt slut sparas oförändrat');
bbq_ok($sortOf($pdo, (int) $earlyEnd['id']) === '2026-10-28 08:00:00', 'slut före start behåller slutet');
jeab_booking_query_mode_set($pdo, false, true);
$store->saveBooking((int) $inWeek['id'], ['ends_at' => '2026-10-28 18:30:00', 'updated_at' => $stamp]);
bbq_ok($sortOf($pdo, (int) $inWeek['id']) === '2026-10-28 18:30:00', 'ends_sort skrivs när kolumnen finns');
$store->saveBooking((int) $inWeek['id'], ['ends_at' => '2026-10-28 11:00:00', 'updated_at' => $stamp]);
bbq_ok($sortOf($pdo, (int) $inWeek['id']) === '2026-10-28 11:00:00', 'nytt slut skriver om ends_sort');
jeab_booking_query_mode_read($pdo, 'sqlite');

$pdo->prepare('UPDATE bookings SET company = ?, ends_sort = NULL WHERE id = ?')->execute(['', (int) $blank['id']]);
$pdo->prepare('UPDATE bookings SET company = ? WHERE id = ?')->execute(['JEAB', (int) $jeabCo['id']]);
$pdo->prepare('UPDATE bookings SET company = ? WHERE id = ?')->execute(['Malmö', (int) $malmoAlias['id']]);
$hidden = bbq_ids($store->bookingsBetween('2026-10-26 00:00:00', '2026-10-31 00:00:00', 'jarfalla'));
bbq_ok(!in_array((int) $blank['id'], $hidden, true), 'tomt bolag syns inte förrän kanonisering');
$companyOf = static function (PDO $pdo, int $id): string {
    $stmt = $pdo->prepare('SELECT company FROM bookings WHERE id = ?');
    $stmt->execute([$id]);

    return (string) $stmt->fetchColumn();
};
$auditCount = static function (PDO $pdo): int {
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM booking_company_audit')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
};
jeab_settings_cache_forget($pdo, 'sqlite', 'bookings_week_query_v1');
jeab_settings_cache_forget($pdo, 'sqlite', 'bookings_company_canon_v1');
$pdo->exec("DELETE FROM settings WHERE \"key\" IN ('bookings_week_query_v1', 'bookings_company_canon_v1')");
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_BOOKING_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_TICK']);
jeab_booking_week_query_migrate($pdo, 'sqlite');
jeab_booking_company_canon($pdo, 'sqlite');
bbq_ok($companyOf($pdo, (int) $blank['id']) === '', 'sidan skriver inte om tomt bolag');
bbq_ok($companyOf($pdo, (int) $jeabCo['id']) === 'JEAB', 'sidan skriver inte om JEAB');
bbq_ok($sortOf($pdo, (int) $blank['id']) === '', 'sidan fyller inte ends_sort');
bbq_ok($auditCount($pdo) === 0, 'sidan skriver ingen bolagslogg');
unset($GLOBALS['_JEAB_PAGE_REQUEST']);
$GLOBALS['_JEAB_TICK'] = true;
jeab_booking_week_query_migrate($pdo, 'sqlite');
bbq_ok($sortOf($pdo, (int) $blank['id']) === '2026-10-27 10:00:00', 'backfill fyller ends_sort');
bbq_ok($companyOf($pdo, (int) $blank['id']) === '', 'ends_sort-steget lämnar bolaget');
bbq_ok($companyOf($pdo, (int) $malmoAlias['id']) === 'Malmö', 'ends_sort-steget lämnar Malmö');
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS booking_company_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        booking_id INTEGER NOT NULL,
        old_company TEXT NOT NULL DEFAULT \'\',
        new_company TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TRIGGER booking_company_audit_fail BEFORE INSERT ON booking_company_audit
     BEGIN SELECT RAISE(ABORT, \'audit fail\'); END'
);
jeab_booking_company_canon($pdo, 'sqlite');
bbq_ok($companyOf($pdo, (int) $blank['id']) === '', 'avbruten logg rullar tillbaka tomt bolag');
bbq_ok($companyOf($pdo, (int) $jeabCo['id']) === 'JEAB', 'avbruten logg rullar tillbaka JEAB');
bbq_ok($companyOf($pdo, (int) $malmoAlias['id']) === 'Malmö', 'avbruten logg rullar tillbaka Malmö');
bbq_ok($auditCount($pdo) === 0, 'avbruten logg lämnar ingen rad');
$failedFlag = jeab_settings_value($pdo, 'sqlite', 'bookings_company_canon_v1');
bbq_ok(!is_string($failedFlag) || !str_starts_with($failedFlag, 'ok'), 'avbruten kanon sätter inte ok');
$pdo->exec('DROP TRIGGER booking_company_audit_fail');
jeab_booking_company_canon($pdo, 'sqlite');
bbq_ok($companyOf($pdo, (int) $blank['id']) === 'jarfalla', 'tomt bolag blir Järfälla');
bbq_ok($companyOf($pdo, (int) $jeabCo['id']) === 'jarfalla', 'JEAB blir Järfälla');
bbq_ok($companyOf($pdo, (int) $malmoAlias['id']) === 'malmo', 'Malmö blir malmo');
$logged = $pdo->query('SELECT booking_id, old_company, new_company FROM booking_company_audit ORDER BY id')->fetchAll();
$byId = [];
foreach ($logged as $row) {
    if (is_array($row)) {
        $byId[(int) $row['booking_id']] = [(string) $row['old_company'], (string) $row['new_company']];
    }
}
bbq_ok(($byId[(int) $blank['id']] ?? null) === ['', 'jarfalla'], 'loggen har tomt bolag');
bbq_ok(($byId[(int) $jeabCo['id']] ?? null) === ['JEAB', 'jarfalla'], 'loggen har JEAB');
bbq_ok(($byId[(int) $malmoAlias['id']] ?? null) === ['Malmö', 'malmo'], 'loggen har Malmö');
$again = jeab_settings_value($pdo, 'sqlite', 'bookings_company_canon_v1');
bbq_ok(is_string($again) && str_contains((string) $again, 'blank='), 'bolagssteget räknade tomma: ' . (string) $again);
$beforeSecond = $auditCount($pdo);
jeab_booking_company_canon($pdo, 'sqlite');
bbq_ok($auditCount($pdo) === $beforeSecond, 'andra körningen lägger inte nya loggrader');
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_BOOKING_SAPI']);
jeab_booking_query_mode_read($pdo, 'sqlite');

$windows = [
    'vecka' => ['2026-10-26 00:00:00', '2026-10-31 00:00:00'],
    'nästa vecka' => ['2026-11-02 00:00:00', '2026-11-07 00:00:00'],
    'oktober' => ['2026-09-28 00:00:00', '2026-11-02 00:00:00'],
];
foreach ($windows as $label => [$from, $to]) {
    foreach (['jarfalla', 'malmo', null] as $company) {
        $got = bbq_ids($store->bookingsBetween($from, $to, $company));
        $expect = bbq_legacy_ids($pdo, $from, $to, $company);
        $name = $label . ' ' . ($company ?? 'alla');
        bbq_ok($got === $expect, $name . ' samma id ' . json_encode($got) . ' mot ' . json_encode($expect));
    }
}
$weekIds = bbq_ids($store->bookingsBetween('2026-10-26 00:00:00', '2026-10-31 00:00:00', 'jarfalla'));
foreach ([(int) $inWeek['id'], (int) $span['id'], (int) $emptyEnd['id'], (int) $fromBefore['id'], (int) $earlyEnd['id'], (int) $blank['id'], (int) $jeabCo['id']] as $id) {
    bbq_ok(in_array($id, $weekIds, true), 'veckan innehåller ' . $id);
}
foreach ([(int) $emptyBefore['id'], (int) $boundary['id'], (int) $malmo['id'], (int) $cancelled['id'], (int) $malmoAlias['id']] as $id) {
    bbq_ok(!in_array($id, $weekIds, true), 'veckan utelämnar ' . $id);
}
$nextIds = bbq_ids($store->bookingsBetween('2026-11-02 00:00:00', '2026-11-07 00:00:00', 'jarfalla'));
bbq_ok(in_array((int) $span['id'], $nextIds, true), 'bokning som slutar nästa vecka ligger kvar i nästa vecka');
bbq_ok(!in_array((int) $emptyEnd['id'], $nextIds, true), 'tomt slut byter inte vecka');

$monday = jeab_monday_of(new DateTimeImmutable('2026-10-28'));
$offset = jeab_week_offset_for_monday($monday);
$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'w' => $offset];
$win = jeab_calendar_visible_window();
$loadedCalls = 0;
$GLOBALS['_JEAB_BOOKINGS_BETWEEN_CALLS'] = 0;
$loaded = jeab_calendar_loaded_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$loadedCalls = (int) $GLOBALS['_JEAB_BOOKINGS_BETWEEN_CALLS'];
bbq_ok($loadedCalls === 1, 'outlook-underlaget läser bokningar en gång, fick ' . $loadedCalls);
bbq_ok(bbq_ids($loaded['office']) === $weekIds, 'kontorslistan är veckans Järfälla');
bbq_ok(bbq_ids($loaded['rows']) === bbq_ids($loaded['office']), 'who=all behåller samma rader');

$weekHtml = bbq_html_ids(jeab_calendar_wave_payload($loaded['rows'], [], $win['monday'], false));
bbq_ok(in_array((int) $inWeek['id'], $weekHtml, true), 'veckovyn ritar bokningen');
bbq_ok(in_array((int) $span['id'], $weekHtml, true), 'veckovyn ritar bokningen som slutar nästa vecka');
bbq_ok(in_array((int) $emptyEnd['id'], $weekHtml, true), 'veckovyn ritar tomt slut');
bbq_ok(!in_array((int) $malmo['id'], $weekHtml, true), 'veckovyn ritar inte Malmö');

$_GET['view'] = 'day';
$_GET['d'] = '2026-10-28';
$day = jeab_calendar_visible_window();
$dayIds = bbq_ids($store->bookingsBetween($day['rangeFrom']->format('Y-m-d H:i:s'), $day['rangeTo']->format('Y-m-d H:i:s'), 'jarfalla'));
bbq_ok($dayIds === bbq_legacy_ids($pdo, $day['rangeFrom']->format('Y-m-d H:i:s'), $day['rangeTo']->format('Y-m-d H:i:s'), 'jarfalla'), 'dagvyn hämtar samma id');
$dayHtml = bbq_html_ids(jeab_calendar_wave_payload(
    jeab_filter_bookings_for_company($store->bookingsBetween($day['rangeFrom']->format('Y-m-d H:i:s'), $day['rangeTo']->format('Y-m-d H:i:s'), 'jarfalla'), 'jarfalla'),
    [],
    $day['monday'],
    false
));
bbq_ok($dayHtml === $weekHtml, 'dagvyn har samma kort som veckan');

$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'view' => 'month', 'w' => $offset];
$month = jeab_calendar_visible_window();
$monthRows = $store->bookingsBetween($month['rangeFrom']->format('Y-m-d H:i:s'), $month['rangeTo']->format('Y-m-d H:i:s'), 'jarfalla');
bbq_ok(
    bbq_ids($monthRows) === bbq_legacy_ids($pdo, $month['rangeFrom']->format('Y-m-d H:i:s'), $month['rangeTo']->format('Y-m-d H:i:s'), 'jarfalla'),
    'månaden hämtar samma id'
);
$monthHtml = bbq_html_ids(jeab_calendar_wave_payload($monthRows, [], $month['monday'], true));
bbq_ok(in_array((int) $inWeek['id'], $monthHtml, true) && in_array((int) $span['id'], $monthHtml, true), 'månaden ritar samma bokningar');

$_GET['co'] = 'malmo';
$_SESSION['jeab_company'] = 'malmo';
$malmoIds = bbq_ids($store->bookingsBetween('2026-10-26 00:00:00', '2026-10-31 00:00:00', 'malmo'));
bbq_ok($malmoIds === [(int) $malmo['id'], (int) $malmoAlias['id']] || $malmoIds === [(int) $malmoAlias['id'], (int) $malmo['id']], 'Malmö-veckan är bara Malmö ' . json_encode($malmoIds));

$plan = $pdo->query(
    "EXPLAIN QUERY PLAN SELECT id FROM bookings WHERE company = 'jarfalla' AND status = 'booked' AND starts_at < '2026-10-31 00:00:00' AND COALESCE(NULLIF(ends_sort, ''), COALESCE(NULLIF(ends_at, ''), starts_at)) > '2026-10-26 00:00:00'"
)->fetchAll(PDO::FETCH_ASSOC);
$planText = json_encode($plan, JSON_UNESCAPED_UNICODE);
bbq_ok(is_string($planText) && str_contains($planText, 'bookings_company_status_starts'), 'planen använder indexet: ' . $planText);

$dbSrc = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$sqliteConnect = substr($dbSrc, (int) strpos($dbSrc, 'function jeab_pdo_connect_sqlite'));
$sqliteConnect = substr($sqliteConnect, 0, (int) strpos($sqliteConnect, 'function jeab_pdo_connect_mysql'));
$mysqlConnect = substr($dbSrc, (int) strpos($dbSrc, 'function jeab_pdo_connect_mysql'));
$mysqlConnect = substr($mysqlConnect, 0, (int) strpos($mysqlConnect, 'function jeab_migrate_sqlite'));
bbq_ok(str_contains($sqliteConnect, 'jeab_booking_week_query_boot'), 'sqlite-anslutningen bara startar läget');
bbq_ok(!str_contains($sqliteConnect, 'jeab_booking_week_query_migrate'), 'sqlite-anslutningen migrerar inte själv');
bbq_ok(str_contains($mysqlConnect, 'jeab_booking_week_query_boot'), 'mysql-anslutningen bara startar läget');
bbq_ok(!str_contains($mysqlConnect, 'jeab_booking_company_canon'), 'mysql-anslutningen kanoniserar inte själv');
$tickSrc = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
bbq_ok(str_contains($tickSrc, 'jeab_booking_week_query_migrate') && str_contains($tickSrc, 'jeab_booking_company_canon'), 'tick kör båda stegen');
$tickAt = strpos($tickSrc, 'function jeab_tick_maintenance');
$tickFn = $tickAt === false ? '' : substr($tickSrc, $tickAt, 4000);
$purchaseAt = strpos($tickFn, "function_exists('jeab_purchase_sql_pdo')");
$weekAt = strpos($tickFn, 'jeab_booking_week_query_migrate');
$canonCallAt = strpos($tickFn, 'jeab_booking_company_canon');
bbq_ok(
    $purchaseAt !== false && $weekAt !== false && $canonCallAt !== false && $weekAt > $purchaseAt && $canonCallAt > $purchaseAt,
    'kalenderstegen ligger efter inköpsblocket i tick'
);
$canonFnAt = strpos($dbSrc, 'function jeab_booking_company_canon');
$canonFn = $canonFnAt === false ? '' : substr($dbSrc, $canonFnAt, 2800);
$canonEnd = strpos($canonFn, "\nfunction ");
if ($canonEnd !== false) {
    $canonFn = substr($canonFn, 0, $canonEnd);
}
$updAt = strpos($canonFn, 'UPDATE bookings SET company');
$insAt = strpos($canonFn, 'INSERT INTO booking_company_audit');
bbq_ok(str_contains($canonFn, 'jeab_pdo_transaction'), 'bolagskanon körs i en transaktion');
bbq_ok($updAt !== false && $insAt !== false && $updAt < $insAt, 'UPDATE sker före audit-INSERT');
$migrateSrc = substr($dbSrc, (int) strpos($dbSrc, 'function jeab_migrate_sqlite'));
$migrateSrc = substr($migrateSrc, 0, (int) strpos($migrateSrc, 'function jeab_ensure_booking_contact_columns'));
bbq_ok(substr_count($migrateSrc, 'jeab_booking_week_query_migrate') === 2, 'migrate sqlite och mysql fyller ends_sort');
bbq_ok(substr_count($migrateSrc, 'jeab_booking_company_canon') === 2, 'migrate sqlite och mysql kanoniserar bolag');
bbq_ok(substr_count($migrateSrc, 'jeab_pdo_resolve_known_busy') === 0, 'migrate anropar inte känd-upptagen');
bbq_ok(
    !str_contains($migrateSrc, '_JEAB_MIGRATE_SQLITE_ACTIVE') && !str_contains($migrateSrc, '_JEAB_MIGRATE_MYSQL_ACTIVE'),
    'migrate sätter inga aktiva migreringsflaggor'
);
foreach (['sqlite', 'mysql'] as $bbqDriver) {
    $fnAt = strpos($dbSrc, 'function jeab_migrate_' . $bbqDriver);
    $fn = $fnAt === false ? '' : substr($dbSrc, $fnAt);
    $nextFn = strpos($fn, "\nfunction ");
    if ($nextFn !== false) {
        $fn = substr($fn, 0, $nextFn);
    }
    $weekCall = strrpos($fn, 'jeab_booking_week_query_migrate(');
    $canonCall = strrpos($fn, 'jeab_booking_company_canon(');
    $tail = $canonCall === false ? '' : substr($fn, $canonCall);
    bbq_ok(
        $weekCall !== false && $canonCall !== false && $weekCall < $canonCall
            && preg_match('/^jeab_booking_company_canon\(\$pdo, \'' . $bbqDriver . '\'\);\s*\}$/', $tail) === 1,
        $bbqDriver . ': kalenderstegen är sist i migrate'
    );
    bbq_ok(str_contains($fn, 'jeab_ensure_notis_sends(') && str_contains($fn, 'jeab_ensure_notis_history('), $bbqDriver . ': utskickstabellerna skapas i migrate');
}
$sqliteMigrate = substr($migrateSrc, 0, (int) strpos($migrateSrc, 'function jeab_migrate_mysql'));
$mysqlMigrate = substr($migrateSrc, (int) strpos($migrateSrc, 'function jeab_migrate_mysql'));
bbq_ok(
    str_ends_with(rtrim($sqliteMigrate), "jeab_booking_company_canon(\$pdo, 'sqlite');\n}")
        && str_ends_with(rtrim($mysqlMigrate), "jeab_booking_company_canon(\$pdo, 'mysql');\n}"),
    'kalenderstegen ligger sist i båda migrate'
);
$tickCalls = [
    'jeab_calendar_log_backfill(jeab_store())' => 'kalenderloggen',
    'jeab_inkorg_ensure_list_contact($pdo, $driver)' => 'listlänken',
    'jeab_notiser_history_refresh()' => 'utskickshistoriken',
    'jeab_gp_week_refresh_due(' => 'Outlook-veckan',
];
foreach ($tickCalls as $needle => $label) {
    bbq_ok(substr_count($tickSrc, $needle) === 1, $label . ' anropas en gång i tick');
}
$logAt = strpos($tickSrc, 'jeab_calendar_log_backfill(jeab_store())');
$listAt = strpos($tickSrc, 'jeab_inkorg_ensure_list_contact($pdo, $driver)');
$histAt = strpos($tickSrc, 'jeab_notiser_history_refresh()');
bbq_ok(
    $logAt !== false && $listAt !== false && $histAt !== false && $logAt < $listAt && $listAt < $histAt,
    'tick kör kalenderlogg, listlänk och utskickshistorik var för sig'
);
bbq_ok(
    preg_match_all('/try \{\s*jeab_calendar_log_backfill\(jeab_store\(\)\);/s', $tickSrc) === 1
        && preg_match_all('/try \{\s*jeab_inkorg_ensure_list_contact\(\$pdo, \$driver\);/s', $tickSrc) === 1
        && preg_match_all('/try \{\s*jeab_notiser_history_refresh\(\);/s', $tickSrc) === 1
        && preg_match_all('/try \{\s*jeab_gp_week_refresh_due\(/s', $tickSrc) === 1,
    'kalenderlogg, listlänk, utskick och Outlook har varsitt try'
);
$connectSrc = $sqliteConnect . $mysqlConnect;
$sqlitePut = strpos($sqliteConnect, 'jeab_pdo_pool_put');
$sqliteBusy = strpos($sqliteConnect, 'jeab_pdo_resolve_known_busy');
$mysqlPut = strpos($mysqlConnect, 'jeab_pdo_pool_put');
$mysqlBusy = strpos($mysqlConnect, 'jeab_pdo_resolve_known_busy');
bbq_ok(substr_count($connectSrc, 'jeab_pdo_resolve_known_busy') === 2, 'anslutningarna anropar känd-upptagen en gång per drivare');
bbq_ok(
    $sqlitePut !== false && $sqliteBusy !== false && $sqliteBusy > $sqlitePut
        && $mysqlPut !== false && $mysqlBusy !== false && $mysqlBusy > $mysqlPut
        && substr_count($connectSrc, 'if ($migrated)') === 2,
    'känd-upptagen ligger efter pool put och bara när migreringen just gick'
);

$src = (string) file_get_contents(dirname(__DIR__) . '/src/bookings.php');
$waveAt = strpos($src, 'function jeab_calendar_respond_wave');
$wave = $waveAt !== false ? substr($src, $waveAt) : '';
$waveEnd = strpos($wave, "\nfunction ");
if ($waveEnd !== false) {
    $wave = substr($wave, 0, $waveEnd);
}
bbq_ok(str_contains($wave, 'jeab_gp_week_events_cache_read'), 'outlook-vågen läser cachen utan att anropa Graph');
bbq_ok(!str_contains($wave, 'jeab_gp_week_events_cached'), 'outlook-vågen anropar inte Graph-vägen');
bbq_ok(str_contains($wave, "'stale_label' => \$stale ? 'uppdateras…' : ''"), 'vågen skickar uppdateras… när veckan är gammal');
bbq_ok(substr_count($wave, 'jeab_calendar_loaded_bookings') === 2, 'varje våg läser bokningarna en gång');

echo "ALLA OK\n";
