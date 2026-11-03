<?php

declare(strict_types=1);

/**
 * Järfälla vecka, filter Alla. Håkansson med gata i ämnet blir knapp
 * även när bokningen ligger en annan dag. «Ps 10» förblir streckad.
 * Tooltipen (.wg-peek) tar inte klick. app.js bär ?v=innehållets hash.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-alla-' . bin2hex(random_bytes(4));
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
require dirname(__DIR__) . '/src/layout.php';
date_default_timezone_set('Europe/Stockholm');

function alla_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function alla_insert(JeabStore $store, string $name, array $data): array
{
    $stamp = jeab_now();
    $customer = $store->insertCustomer($name . ' ' . bin2hex(random_bytes(2)), $stamp);
    $data['customer_id'] = $customer['id'];
    $data['created_at'] = $stamp;
    $data['updated_at'] = $stamp;
    $data['status'] = 'booked';

    return $store->insertBooking($data);
}

function alla_flat(array $payload): string
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

function alla_opens(string $html, string $title, int $id): bool
{
    if (preg_match('/data-js="outlook-busy"[^>]*data-title="' . preg_quote($title, '/') . '"/', $html) === 1) {
        return false;
    }
    $pattern = '/data-js="edit-booking"[^>]*data-id="' . $id . '"[^>]*data-editable="1"[^>]*>[\s\S]{0,2500}'
        . preg_quote($title, '/') . '/';

    return preg_match($pattern, $html) === 1;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_SESSION['jeab_company'] = 'jarfalla';
$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all'];
$appPath = dirname(__DIR__) . '/assets/js/app.js';
$appV = jeab_asset_version_token($appPath);
ob_start();
jeab_layout('Kalender', 'bookings', '<div class="cal-page"></div>');
$layout = (string) ob_get_clean();

alla_ok(jeab_booking_who_filter() === null && jeab_booking_job_filter() === null, 'Alla är who=null och job=null');
alla_ok(jeab_calendar_include_outlook(null), 'Alla tar med Outlook');

$store = jeab_store(true);
$monday = jeab_week_monday(0);
$tuesday = $monday->modify('+1 day');
$friday = $monday->modify('+4 days');
$ljustero = alla_insert($store, 'Sten Håkansson', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'notes' => '',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $friday->setTime(7, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(16, 0)->format('Y-m-d H:i:s'),
]);
$ps = alla_insert($store, 'Kund Elinsson', [
    'customer_name' => 'Kund Elinsson',
    'address' => 'Parkvägen 8, 177 40 Järfälla',
    'notes' => 'Ps 10',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $tuesday->setTime(13, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $tuesday->setTime(14, 0)->format('Y-m-d H:i:s'),
]);
$win = jeab_calendar_visible_window();
$office = jeab_calendar_office_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$visible = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
alla_ok(count($visible) === count($office), 'Alla visar hela kontorets lista');

$events = [
    [
        'who' => 'Roberto',
        'title' => 'Batteri, Ljusterö huvuds väg 23, Sten Håkansson',
        'starts_at' => $monday->setTime(9, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $monday->setTime(11, 0)->format('Y-m-d H:i:s'),
        'all_day' => false,
        'event_id' => 'graph-alla-ljustero',
    ],
    [
        'who' => 'Elin',
        'title' => 'Ps 10',
        'starts_at' => $tuesday->setTime(13, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $tuesday->setTime(14, 0)->format('Y-m-d H:i:s'),
        'all_day' => false,
        'event_id' => 'graph-alla-ps',
    ],
    [
        'who' => 'Roberto',
        'title' => 'Privat möte',
        'starts_at' => $monday->setTime(15, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $monday->setTime(16, 0)->format('Y-m-d H:i:s'),
        'all_day' => false,
        'event_id' => 'graph-alla-private',
    ],
];

$weekOffset = (int) $win['weekOffset'];
$bookings = $office;
$officeBookings = $office;
$outlookEvents = $events;
$selectedInstallers = [];
$flash = null;
$error = null;
$fromReminder = null;
$html = require dirname(__DIR__) . '/src/pages/bookings.php';
alla_ok(is_string($html) && str_contains($html, 'class="week-grid'), 'veckovyn ritades');

alla_ok(alla_opens($html, 'Sten Håkansson', (int) $ljustero['id']), 'Håkansson en annan dag är en edit-booking med data-id i veckans HTML');
alla_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Ps 10"/', $html) === 1, 'Ps 10 förblir streckad');
alla_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Privat möte"/', $html) === 1, 'privat möte är kvar som streckad ruta');

$mondayKey = $monday->format('Y-m-d');
alla_ok(preg_match('/data-day="' . $mondayKey . '"[\s\S]*?data-js="outlook-busy"[\s\S]*?Sten Håkansson/', $html) === 1, 'bokningsknappen ligger efter den döda Outlook-rutan i måndagens kolumn');

$wave = alla_flat(jeab_calendar_wave_payload($visible, $events, $win['monday'], false, $office));
alla_ok(alla_opens($wave, 'Sten Håkansson', (int) $ljustero['id']), 'Håkansson har samma knapp i vågen som insertNodes tar emot');
alla_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Ps 10"/', $wave) === 1, 'vågen lämnar Ps 10 streckad');
alla_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Privat möte"/', $wave) === 1, 'vågen lämnar det privata mötet');

$appJs = (string) file_get_contents($appPath);
alla_ok(str_contains($appJs, 'jeab.cal.v3.'), 'kalendercachen byter namn när skriptet gör det');
alla_ok(str_contains($html, $appV), 'boot-nyckeln innehåller app.js innehållshash');
alla_ok(jeab_asset_url('assets/js/app.js') === jeab_url('assets/js/app.js') . '?v=' . $appV, 'app.js?v= är filens innehållshash');
alla_ok(str_contains($layout, 'assets/js/app.js?v=' . $appV), 'script-taggen på kalendersidan bär samma v');

$bindAt = strpos($appJs, 'function bindPaintedCalendarItems');
$bindFn = $bindAt !== false ? substr($appJs, $bindAt, 500) : '';
alla_ok(str_contains($bindFn, 'edit-booking') && str_contains($bindFn, 'outlook-busy') && strpos($bindFn, 'edit-booking') < strpos($bindFn, 'outlook-busy'), 'edit-bindningen registreras före Outlook');
alla_ok(str_contains($bindFn, 'nodeOpensBooking'), 'en Outlook-nod med boknings-id får edit-hanteraren');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
$peekAt = strpos($css, '.wg-peek {');
$peekCss = $peekAt !== false ? substr($css, $peekAt, 420) : '';
alla_ok(str_contains($peekCss, 'pointer-events: none'), 'tooltipen tar inte emot klick');
alla_ok(str_contains($css, '.wg-item[data-js="edit-booking"]') && str_contains($css, 'z-index: 3'), 'bokningsknappen ligger över en streckad ruta');

$shell = (string) file_get_contents(dirname(__DIR__) . '/assets/js/shell.js');
$assetAt = strpos($shell, 'function assetVersion');
$assetFn = $assetAt !== false ? substr($shell, $assetAt, 700) : '';
alla_ok(preg_match("/var el = document\\.getElementById\\('jeab-asset-v'\\);\\s*if \\(el\\)/", $assetFn) === 1, 'skalet läser om versionen i stället för att frysa den');

echo "calendar alla click ok\n";
