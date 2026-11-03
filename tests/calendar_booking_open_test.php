<?php

declare(strict_types=1);

/**
 * Kalenderns bokningsvåg (intent=calendar-bookings) är det endpoint som
 * dialogens data-attribut kommer från. Den ska svara med en klickbar
 * bokning utan att läsa hela ordertabellen, och en Outlook-kopia med
 * jobbets namn ska inte ligga kvar ovanpå.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'wave') {
    $dir = $argv[2] ?? '';
    if ($dir === '' || !is_dir($dir)) {
        fwrite(STDERR, "wave dir\n");
        exit(1);
    }
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $dir;
    $GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
    putenv('JEAB_DISABLE_SQLITE=0');
    require dirname(__DIR__) . '/src/bootstrap.php';
    $_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'intent' => 'calendar-bookings'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['jeab_company'] = 'jarfalla';
    jeab_calendar_respond_wave(jeab_store(true), 'calendar-bookings');
}

$tmp = sys_get_temp_dir() . '/jeab-cal-open-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;

function cal_open_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cal_open_html(array $payload): string
{
    $flat = '';
    foreach (['timed', 'early', 'late', 'month'] as $bucket) {
        foreach ($payload[$bucket] ?? [] as $items) {
            if (is_array($items)) {
                $flat .= implode('', $items);
            }
        }
    }
    $flat .= implode('', $payload['allday'] ?? []);

    return $flat;
}

$_GET = ['p' => 'bookings', 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_company'] = 'jarfalla';

$store = jeab_store(true);
$monday = jeab_week_monday(0);
$start = $monday->modify('+1 day')->setTime(9, 0);
$end = $start->modify('+2 hours');
$stamp = jeab_now();
$customer = $store->insertCustomer('Bo', $stamp);
$row = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Bo',
    'email' => 'bo@example.com',
    'phone' => '0701234567',
    'address' => 'Väg 1',
    'notes' => 'Byt säkring',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $start->format('Y-m-d H:i:s'),
    'ends_at' => $end->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$bookingId = (int) $row['id'];
cal_open_ok($bookingId > 0, 'bokningen finns');

$loadsBefore = (int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0);
$win = jeab_calendar_visible_window();
$rows = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$seen = false;
foreach ($rows as $item) {
    if ((int) ($item['id'] ?? 0) === $bookingId) {
        $seen = true;
    }
}
cal_open_ok($seen, 'bokningen ligger i veckofönstret');

$mirror = [
    'who' => 'Elin',
    'title' => 'Elbilsladdare, Gamla vägen 99, Annan Kund',
    'starts_at' => $start->format('Y-m-d H:i:s'),
    'ends_at' => $end->format('Y-m-d H:i:s'),
    'all_day' => false,
];
$private = [
    'who' => 'Elin',
    'title' => 'Privat',
    'starts_at' => $start->format('Y-m-d H:i:s'),
    'ends_at' => $end->format('Y-m-d H:i:s'),
    'all_day' => false,
];
$stuck = [
    'who' => 'Elin',
    'title' => 'Elbilsladdarextra Gamla vägen',
    'starts_at' => $start->format('Y-m-d H:i:s'),
    'ends_at' => $end->format('Y-m-d H:i:s'),
    'all_day' => false,
];
cal_open_ok(jeab_calendar_outlook_is_duplicate($mirror, $row), 'Outlook-kopian med jobbets namn är samma bokning');
cal_open_ok(!jeab_calendar_outlook_is_duplicate($private, $row), 'ett privat möte är inte bokningen');
cal_open_ok(!jeab_calendar_outlook_is_duplicate($stuck, $row), 'jobbnamnet måste vara ett eget ord');

$payload = jeab_calendar_wave_payload($rows, [$mirror, $private, $stuck], $win['monday'], false);
$html = cal_open_html($payload);
cal_open_ok(str_contains($html, 'data-js="edit-booking"') && str_contains($html, 'data-id="' . $bookingId . '"') && str_contains($html, 'data-editable="1"'), 'vågen har knappen som öppnar dialogen');
cal_open_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="[^"]*Gamla vägen 99/', $html) !== 1, 'Outlook-kopian är inte en död ruta');
cal_open_ok(str_contains($html, 'data-js="outlook-busy"') && str_contains($html, 'Privat'), 'privat Outlook blir kvar');
$busyAt = strpos($html, 'data-js="outlook-busy"');
$editAt = strpos($html, 'data-js="edit-booking"');
cal_open_ok($busyAt !== false && $editAt !== false && $editAt > $busyAt, 'bokningsknappen ligger över Outlook i samma dag');
cal_open_ok((int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0) === $loadsBefore, 'vågen läser inte hela ordertabellen');
cal_open_ok(!str_contains($html, 'SQL saknas'), 'vågen kastar inte SQL saknas');

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = jeab_purchase_sql_live();
cal_open_ok($pdo instanceof PDO, 'order-SQL finns i testet');
jeab_sql_purchases_put($pdo, [
    'reminder_id' => 910001,
    'booking_id' => $bookingId,
    'kind' => 'laddbox',
    'customer_name' => 'Bo',
]);
jeab_sql_purchases_put($pdo, [
    'reminder_id' => 910002,
    'kind' => 'sol',
    'customer_name' => 'Tung',
    'notes' => str_repeat('x', 8000),
]);
$GLOBALS['_JEAB_CAL_LINKS_RESET'] = true;
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$linked = jeab_calendar_wave_payload([$store->getBooking($bookingId)], [], $win['monday'], false);
$linkedHtml = cal_open_html($linked);
cal_open_ok(str_contains($linkedHtml, 'data-order=') && str_contains($linkedHtml, '910001'), 'orderlänken finns utan att avkoda alla ordrar');
cal_open_ok((int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0) === 0, 'orderlänken använder inte jeab_purchase_all');
cal_open_ok(str_contains($linkedHtml, 'data-js="edit-booking"'), 'orderlänken tar inte bort dialogknappen');

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' wave ' . escapeshellarg($tmp);
$json = shell_exec($cmd . ' 2>/dev/null');
cal_open_ok(is_string($json) && str_contains($json, '"ok":true'), 'endpointet svarar');
cal_open_ok(is_string($json) && str_contains($json, '"wave":"bookings"'), 'svaret är bokningsvågen');
cal_open_ok(is_string($json) && str_contains($json, 'data-js=\"edit-booking\"') && str_contains($json, 'data-id=\"' . $bookingId . '\"'), 'endpointet skickar dialogens knapp');
cal_open_ok(is_string($json) && !str_contains($json, 'SQL saknas'), 'endpointet nämner inte SQL saknas');

echo "calendar booking open ok\n";
