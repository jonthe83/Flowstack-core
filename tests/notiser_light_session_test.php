<?php

declare(strict_types=1);

/**
 * Notis-sidan läser inte alla SMS-payload eller kalenderloggen.
 * Tick backfyller en gång. Kalender-HTML släpper sessionen innan sidan ritas.
 * Påhittade rader. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-notis-light-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    fwrite(STDERR, "FAIL graph skickades\n");
    exit(1);
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = $GLOBALS['JEAB_GRAPH_SEND'];
$GLOBALS['JEAB_SMS_SEND'] = $GLOBALS['JEAB_GRAPH_SEND'];

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_company'] = 'malmo';
unset($_GET['co']);
session_write_close();
$GLOBALS['_JEAB_SESSION_RELEASED'] = true;
$releasedCompany = jeab_selected_company();
unset($GLOBALS['_JEAB_SESSION_RELEASED']);

function nbl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function nbl_light(bool $on): void
{
    if ($on) {
        $GLOBALS['_JEAB_PAGE_REQUEST'] = 1;
        $GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
        unset($GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_NOTIS_SEND_DDL']);
        return;
    }
    unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
}

function nbl_sends(string $id): int
{
    foreach (jeab_notiser_catalog() as $row) {
        if (($row['id'] ?? '') === $id) {
            return (int) ($row['sends_30d'] ?? 0);
        }
    }

    return -1;
}

register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
});

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$bookAt = strpos($index, "if (\$page === 'bookings')");
$requireAt = $bookAt === false ? false : strpos($index, "require __DIR__ . '/src/pages/bookings.php'", $bookAt);
nbl($bookAt !== false && $requireAt !== false, 'kalendergrenen finns');
$branch = substr($index, (int) $bookAt, (int) $requireAt - (int) $bookAt);
$companyAt = strpos($branch, 'jeab_selected_company()');
$whoAt = strpos($branch, 'jeab_booking_who_filter()');
$csrfAt = strpos($branch, 'jeab_csrf_token()');
$releaseAt = $csrfAt === false ? false : strpos($branch, 'jeab_release_session()', $csrfAt);
nbl($companyAt !== false && $whoAt !== false && $csrfAt !== false && $releaseAt !== false, 'bolag, filter och CSRF skrivs');
nbl($companyAt < $releaseAt && $whoAt < $releaseAt && $csrfAt < $releaseAt, 'sessionen släpps efter bolag, filter och CSRF');
$waveAt = strpos($branch, 'jeab_calendar_respond_wave($store, $intent)');
$logAt = strpos($branch, 'jeab_calendar_log_for_week');
nbl($waveAt !== false && $logAt !== false && $releaseAt < $waveAt && $releaseAt < $logAt, 'våg och logg ligger efter att låset släppts');
$afterRelease = substr($branch, $releaseAt + strlen('jeab_release_session()'));
nbl(!str_contains($afterRelease, '$_SESSION['), 'inget $_SESSION-skriv efter släppet');
$people = (string) file_get_contents(dirname(__DIR__) . '/src/people.php');
nbl(str_contains($people, '_JEAB_SESSION_RELEASED'), 'bolaget läses efter att låset släppts');
nbl($releasedCompany === 'malmo', 'släppt session behåller Malmö, fick ' . $releasedCompany);

$tz = new DateTimeZone(date_default_timezone_get());
$recent = (new DateTimeImmutable('now', $tz))->modify('-2 days')->format('Y-m-d H:i:s');
$store = jeab_store();
$customer = $store->insertCustomer('Notis Light', $recent);
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Notis Light',
    'email' => 'notis-light@exempel.test',
    'phone' => '+46701110000',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $recent,
    'ends_at' => $recent,
    'status' => 'booked',
    'created_at' => $recent,
    'updated_at' => $recent,
]);
$bid = (int) $booking['id'];
$noise = 400;
$payloads = [
    $bid => [
        'starts_at' => $recent,
        'email' => true,
        'email_at' => $recent,
        'sms' => true,
        'sms_at' => $recent,
    ],
];
for ($i = 0; $i < $noise; $i++) {
    $payloads[800000 + $i] = [
        'starts_at' => $recent,
        'email' => true,
        'email_at' => $recent,
        'sms' => true,
        'sms_at' => $recent,
        'note' => str_repeat('x', 240),
    ];
}
$pdo = $store->pdo();
jeab_sms_store_ensure($pdo);
foreach ($payloads as $id => $row) {
    jeab_sms_store_upsert($pdo, (int) $id, (string) json_encode($row, JSON_UNESCAPED_UNICODE));
}
jeab_sms_store_mark_imported($pdo);
$entries = [[
    'id' => 1,
    'type' => 'mail',
    'at' => $recent,
    'booking_id' => $bid,
    'title' => 'Notis Light',
]];
for ($i = 0; $i < $noise; $i++) {
    $entries[] = [
        'id' => 2 + $i,
        'type' => 'booked',
        'at' => $recent,
        'booking_id' => $bid,
        'title' => 'brus',
    ];
}
file_put_contents($GLOBALS['_JEAB_CALENDAR_LOG_PATH'], json_encode(['entries' => $entries, 'backfilled' => true]));

nbl_light(true);
nbl(jeab_notiser_view_is_light(), 'sidvisning är lätt');
$GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] = 0;
$GLOBALS['_JEAB_NOTIS_HEAVY'] = 0;
$lightStarted = microtime(true);
jeab_notiser_events();
$lightMs = (microtime(true) - $lightStarted) * 1000;
$before = nbl_sends('bekraft-laddbox-jarfalla');
nbl($before === 0, 'sidan läser inte SMS-raden före backfill, fick ' . $before);
nbl((int) $GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] === 0, 'sidan skannar inte bokningsloggen');
nbl((int) $GLOBALS['_JEAB_NOTIS_HEAVY'] === 0, 'sidan skannar inte mejlboxen');

nbl_light(false);
$scanStarted = microtime(true);
$scanned = 0;
$seen = [];
$cut = jeab_notiser_cut();
jeab_notiser_booking_events(static function (string $id, ?DateTimeImmutable $at, bool $ok) use (&$scanned, &$seen, $cut): void {
    if ($id !== 'bekraft-laddbox-jarfalla' || !$ok || !$at instanceof DateTimeImmutable || $at < $cut) {
        return;
    }
    $key = $at->setTimezone(jeab_notiser_zone())->format('Y-m-d H:i');
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $scanned++;
});
$scanMs = (microtime(true) - $scanStarted) * 1000;
nbl($scanned === 1, 'skanningen räknar bekräftelsen en gång, fick ' . $scanned);
echo 'tid lätt sida ' . number_format($lightMs, 1, '.', '') . ' ms, skanning ' . number_format($scanMs, 1, '.', '') . " ms, brus {$noise}\n";
nbl($lightMs < $scanMs, 'sidan är snabbare än skanningen (' . number_format($lightMs, 1, '.', '') . ' mot ' . number_format($scanMs, 1, '.', '') . ' ms)');

$GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] = 0;
jeab_notiser_booking_backfill();
nbl((int) $GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] === 1, 'backfill läser loggen en gång');
jeab_notiser_booking_backfill();
nbl((int) $GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] === 1, 'andra backfill läser inte om');
nbl_light(true);
$GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] = 0;
$after = nbl_sends('bekraft-laddbox-jarfalla');
$smsAfter = nbl_sends('sms-laddbox-jarfalla');
nbl($after === $scanned, 'sidan visar samma 30-dagarssiffra, fick ' . $after);
nbl($smsAfter === 1, 'SMS-siffran kommer från den sparade raden, fick ' . $smsAfter);
nbl((int) $GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] === 0, 'sidan efter backfill skannar inte');

echo "notiser-light-session: ok\n";
