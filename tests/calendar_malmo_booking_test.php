<?php

declare(strict_types=1);

/**
 * Streckade rutor är Outlook. Personfiltret tar bort kollegans JEAB-bokning
 * innan dubblettfiltret körs, så kopian blir kvar utan knapp.
 * Malmö (Kim) och Järfälla (Niman/Elin/Roberto) ska få edit-booking när
 * ämnet delar gata, ort eller kund, även om tittaren är någon annan.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-malmo-' . bin2hex(random_bytes(4));
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

function cal_malmo_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cal_malmo_html(array $payload): string
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

function cal_malmo_insert(JeabStore $store, string $name, array $data): array
{
    $stamp = jeab_now();
    $customer = $store->insertCustomer($name . ' ' . bin2hex(random_bytes(2)), $stamp);
    $data['customer_id'] = $customer['id'];
    $data['created_at'] = $stamp;
    $data['updated_at'] = $stamp;
    $data['status'] = 'booked';

    return $store->insertBooking($data);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$monday = jeab_week_monday(0);
$friday = $monday->modify('+4 days');
$store = jeab_store(true);

$malmoService = cal_malmo_insert($store, 'Erik Lundebo', [
    'customer_name' => 'Erik Lundebo',
    'address' => 'Vallmovägen 50, 212 34 Malmö',
    'notes' => 'Felsökning Growatt',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'service',
    'starts_at' => $friday->setTime(13, 30)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(15, 30)->format('Y-m-d H:i:s'),
]);
$malmoBox = cal_malmo_insert($store, 'Easee kund', [
    'customer_name' => 'Easee kund',
    'address' => 'Geijersgatan 88A, 211 45 Malmö',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => $friday->setTime(8, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(12, 0)->format('Y-m-d H:i:s'),
]);
$jarfallaSameStreet = cal_malmo_insert($store, 'Annan ort', [
    'customer_name' => 'Annan ort',
    'address' => 'Vallmovägen 50, 177 40 Järfälla',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => $friday->setTime(13, 30)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(15, 30)->format('Y-m-d H:i:s'),
]);
$sol = cal_malmo_insert($store, 'Solkund', [
    'customer_name' => 'Solkund',
    'address' => 'Gymnastikvägen 29, 177 40 Järfälla',
    'assignees' => ['Niman'],
    'company' => 'jarfalla',
    'job_type' => 'sol',
    'starts_at' => $friday->setTime(0, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s'),
]);
$tyreso = cal_malmo_insert($store, 'Tyresökund', [
    'customer_name' => 'Tyresökund',
    'address' => 'Strandvägen 2, 135 40 Tyresö',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'sol',
    'starts_at' => $friday->setTime(10, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(12, 0)->format('Y-m-d H:i:s'),
]);
$felsok = cal_malmo_insert($store, 'Felsök solaredge', [
    'customer_name' => 'Felsök solaredge',
    'address' => 'Parkvägen 4, 177 40 Järfälla',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => $friday->setTime(15, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(16, 30)->format('Y-m-d H:i:s'),
]);
$elmira = cal_malmo_insert($store, 'Elmira alla', [
    'customer_name' => 'Elmira alla',
    'address' => 'Åkervägen 3, 177 40 Järfälla',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $friday->setTime(9, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(10, 0)->format('Y-m-d H:i:s'),
]);

$_GET = ['p' => 'bookings', 'co' => 'malmo', 'who' => 'Sam W', 'job' => 'all'];
$_SESSION['jeab_company'] = 'malmo';
$win = jeab_calendar_visible_window();
$filtered = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$office = jeab_calendar_office_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$filteredIds = array_map(static fn(array $row): int => (int) $row['id'], $filtered);
$officeIds = array_map(static fn(array $row): int => (int) $row['id'], $office);
cal_malmo_ok(!in_array((int) $malmoService['id'], $filteredIds, true), 'Sam W ser inte Kims bokning i personfiltret');
cal_malmo_ok(in_array((int) $malmoService['id'], $officeIds, true) && in_array((int) $malmoBox['id'], $officeIds, true), 'Malmös bokningar ligger i kontorets vecka');
cal_malmo_ok(!in_array((int) $jarfallaSameStreet['id'], $officeIds, true), 'Järfälla med samma gata följer inte med till Malmö');

$serviceEvent = [
    'who' => 'Sam W',
    'title' => 'ServiceTjänst – Felsökning Growatt Erik Lundebo, Vallmovägen 50',
    'starts_at' => $friday->setTime(13, 30)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(15, 30)->format('Y-m-d H:i:s'),
    'all_day' => false,
];
$boxEvent = [
    'who' => 'Sam W',
    'title' => 'Laddbox installation – Easee, Geijersgatan 88A',
    'starts_at' => $friday->setTime(8, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(12, 0)->format('Y-m-d H:i:s'),
    'all_day' => false,
];
$private = [
    'who' => 'Sam W',
    'title' => 'Privat',
    'starts_at' => $friday->setTime(13, 30)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(14, 30)->format('Y-m-d H:i:s'),
    'all_day' => false,
];
$jobOnly = [
    'who' => 'Sam W',
    'title' => 'Service internt',
    'starts_at' => $friday->setTime(13, 30)->format('Y-m-d H:i:s'),
    'ends_at' => $friday->setTime(14, 30)->format('Y-m-d H:i:s'),
    'all_day' => false,
];
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($serviceEvent, $malmoService), 'Malmö-ämnet med gatan är Kims bokning');
cal_malmo_ok(!jeab_calendar_outlook_is_duplicate($jobOnly, $malmoService), 'bara jobbnamnet kopplar inte någon annans kalender');
cal_malmo_ok(!jeab_calendar_outlook_is_duplicate($serviceEvent, $jarfallaSameStreet) || jeab_booking_company($jarfallaSameStreet) !== 'malmo', 'samma gata i Järfälla är ett annat kontor');

$payload = jeab_calendar_wave_payload($filtered, [$serviceEvent, $boxEvent, $private, $jobOnly], $win['monday'], false, $office);
$html = cal_malmo_html($payload);
cal_malmo_ok(str_contains($html, 'data-js="edit-booking"') && str_contains($html, 'data-id="' . (int) $malmoService['id'] . '"'), 'Malmö service får en bokningsknapp');
cal_malmo_ok(str_contains($html, 'data-id="' . (int) $malmoBox['id'] . '"'), 'Malmö laddbox får en bokningsknapp');
cal_malmo_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="[^"]*ServiceTjänst/', $html) !== 1, 'serviceämnet ritas inte som streckad Outlook');
cal_malmo_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="[^"]*Laddbox installation/', $html) !== 1, 'laddboxämnet ritas inte som Outlook');
cal_malmo_ok(str_contains($html, 'Privat') && str_contains($html, 'data-js="outlook-busy"'), 'privat Outlook blir kvar');
cal_malmo_ok(str_contains($html, 'Service internt'), 'jobbnamn utan samma person blir kvar som Outlook');
cal_malmo_ok(!str_contains($html, 'data-id="' . (int) $jarfallaSameStreet['id'] . '"'), 'Järfällas bokning får ingen knapp i Malmö');

$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'Sam L', 'job' => 'all'];
$_SESSION['jeab_company'] = 'jarfalla';
$win = jeab_calendar_visible_window();
$filtered = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$office = jeab_calendar_office_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$dayEnd = $friday->modify('+1 day')->setTime(0, 0)->format('Y-m-d H:i:s');
$events = [
    [
        'who' => 'Sam L',
        'title' => 'Sol, Gymnastikvägen 29',
        'all_day' => true,
        'starts_at' => $friday->setTime(0, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $dayEnd,
    ],
    [
        'who' => 'Sam L',
        'title' => 'Tyresö',
        'all_day' => false,
        'starts_at' => $friday->setTime(10, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $friday->setTime(12, 0)->format('Y-m-d H:i:s'),
    ],
    [
        'who' => 'Sam L',
        'title' => 'Felsök solaredge',
        'all_day' => false,
        'starts_at' => $friday->setTime(15, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $friday->setTime(16, 30)->format('Y-m-d H:i:s'),
    ],
    [
        'who' => 'Sam L',
        'title' => 'Elmira alla',
        'all_day' => false,
        'starts_at' => $friday->setTime(9, 0)->format('Y-m-d H:i:s'),
        'ends_at' => $friday->setTime(10, 0)->format('Y-m-d H:i:s'),
    ],
];
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($events[0], $sol), 'heldag Sol, Gymnastikvägen 29 är Nimans bokning');
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($events[1], $tyreso), 'Tyresö är orten på Elins bokning');
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($events[2], $felsok), 'Felsök solaredge är kunden');
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($events[3], $elmira), 'Elmira alla är kunden');
$payload = jeab_calendar_wave_payload($filtered, $events, $win['monday'], false, $office);
$html = cal_malmo_html($payload);
foreach ([[$sol, 'Gymnastikvägen'], [$felsok, 'Felsök solaredge'], [$elmira, 'Elmira alla']] as [$row, $label]) {
    cal_malmo_ok(str_contains($html, 'data-js="edit-booking"') && str_contains($html, 'data-id="' . (int) $row['id'] . '"'), $label . ' får en bokningsknapp');
}
cal_malmo_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Sol, Gymnastikvägen 29"/', $html) !== 1, 'heldagsämnet ritas inte som Outlook');
cal_malmo_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Tyresö"/', $html) === 1, 'bara orten Tyresö förblir streckad');

/**
 * Kopior vars ämne saknar kundnamnet. Live: Roberto «Batteri, Ljusterö»,
 * Elin «Ps 10», Malmö «Ivan Maxborn». Ägaren av kalendern är inte tilldelad.
 */
$ljustero = cal_malmo_insert($store, 'Svea Svensson', [
    'customer_name' => 'Svea Svensson',
    'address' => 'Ängsvägen 2, 184 90 Ljusterö',
    'notes' => 'Byt batteri',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $monday->setTime(9, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->setTime(11, 0)->format('Y-m-d H:i:s'),
]);
$ps = cal_malmo_insert($store, 'Kund Elinsson', [
    'customer_name' => 'Kund Elinsson',
    'address' => 'Parkvägen 8, 177 40 Järfälla',
    'notes' => 'Ps 10',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => $monday->modify('+1 day')->setTime(13, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->modify('+1 day')->setTime(14, 0)->format('Y-m-d H:i:s'),
]);
$ivan = cal_malmo_insert($store, 'Solceller Syd', [
    'customer_name' => 'Solceller Syd',
    'address' => 'Geijersgatan 3, 211 45 Malmö',
    'notes' => 'Ivan Maxborn',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'sol',
    'starts_at' => $monday->modify('+3 days')->setTime(8, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->modify('+3 days')->setTime(9, 30)->format('Y-m-d H:i:s'),
]);
$batteriCopy = [
    'who' => 'Sam L',
    'title' => 'Batteri, Ljusterö',
    'starts_at' => $monday->setTime(9, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->setTime(11, 0)->format('Y-m-d H:i:s'),
    'all_day' => false,
    'event_id' => 'graph-copy-ljustero',
];
$psCopy = [
    'who' => 'Sam L',
    'title' => 'Ps 10',
    'starts_at' => $monday->modify('+1 day')->setTime(13, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->modify('+1 day')->setTime(14, 0)->format('Y-m-d H:i:s'),
    'all_day' => false,
    'event_id' => 'graph-copy-ps',
];
$ivanCopy = [
    'who' => 'Sam W',
    'title' => 'Ivan Maxborn',
    'starts_at' => $monday->modify('+3 days')->setTime(8, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->modify('+3 days')->setTime(9, 30)->format('Y-m-d H:i:s'),
    'all_day' => false,
    'event_id' => 'graph-copy-ivan',
];
$jobOnlyCopy = [
    'who' => 'Sam L',
    'title' => 'Batteri',
    'starts_at' => $monday->setTime(9, 0)->format('Y-m-d H:i:s'),
    'ends_at' => $monday->setTime(11, 0)->format('Y-m-d H:i:s'),
    'all_day' => false,
];
cal_malmo_ok(jeab_calendar_outlook_text_kind($batteriCopy, $ljustero) === 'strong', 'Batteri, Ljusterö är stark träff utan kundnamn');
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($batteriCopy, $ljustero), 'kollegans kopia Batteri, Ljusterö är Robertos bokning');
cal_malmo_ok(!jeab_calendar_outlook_who_on_booking($batteriCopy, $ljustero), 'kalenderns ägare är inte tilldelad');
cal_malmo_ok(!jeab_calendar_outlook_is_duplicate($jobOnlyCopy, $ljustero), 'bara Batteri kopplar inte någon annans kalender');
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($psCopy, $ps), 'Ps 10 i anteckningen är Elins bokning');
cal_malmo_ok(!str_contains('Kund Elinsson', 'Ps 10'), 'Ps 10 är inte kundnamnet');
cal_malmo_ok(jeab_calendar_outlook_is_duplicate($ivanCopy, $ivan), 'Ivan Maxborn i anteckningen är Kims bokning');
cal_malmo_ok(!str_contains('Solceller Syd', 'Ivan Maxborn'), 'Ivan Maxborn är inte kundnamnet');

$copyDead = static function (string $html, string $title): bool {
    return preg_match('/data-js="outlook-busy"[^>]*data-title="' . preg_quote($title, '/') . '"/', $html) === 1;
};
$copyButton = static function (string $html, int $id): bool {
    return str_contains($html, 'data-js="edit-booking"')
        && str_contains($html, 'data-id="' . $id . '"')
        && str_contains($html, 'data-editable="1"');
};

$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'Sam L', 'job' => 'all'];
$_SESSION['jeab_company'] = 'jarfalla';
$win = jeab_calendar_visible_window();
$filtered = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$office = jeab_calendar_office_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$filteredIds = array_map(static fn(array $row): int => (int) $row['id'], $filtered);
cal_malmo_ok(!in_array((int) $ljustero['id'], $filteredIds, true) && !in_array((int) $ps['id'], $filteredIds, true), 'Sam L ser inte Roberto eller Elin i personfiltret');
$weekHtml = cal_malmo_html(jeab_calendar_wave_payload($filtered, [$batteriCopy, $psCopy, $jobOnlyCopy], $win['monday'], false, $office));
$monthHtml = cal_malmo_html(jeab_calendar_wave_payload($filtered, [$batteriCopy, $psCopy], $win['monday'], true, $office));
foreach (['Batteri, Ljusterö', 'Ps 10'] as $title) {
    cal_malmo_ok($copyDead($weekHtml, $title), $title . ' utan gata eller kundnamn förblir streckad i veckan');
    cal_malmo_ok($copyDead($monthHtml, $title), $title . ' utan gata eller kundnamn förblir streckad i månaden');
}
cal_malmo_ok($copyDead($weekHtml, 'Batteri'), 'bara jobbnamnet blir kvar som streckad ruta');
cal_malmo_ok(!str_contains($weekHtml, 'data-id="' . (int) $ivan['id'] . '"'), 'Malmös bokning följer inte med till Järfälla');

$_GET = ['p' => 'bookings', 'co' => 'malmo', 'who' => 'Sam W', 'job' => 'all'];
$_SESSION['jeab_company'] = 'malmo';
$win = jeab_calendar_visible_window();
$filtered = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$office = jeab_calendar_office_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$weekHtml = cal_malmo_html(jeab_calendar_wave_payload($filtered, [$ivanCopy], $win['monday'], false, $office));
$monthHtml = cal_malmo_html(jeab_calendar_wave_payload($filtered, [$ivanCopy], $win['monday'], true, $office));
cal_malmo_ok($copyDead($weekHtml, 'Ivan Maxborn') && $copyDead($monthHtml, 'Ivan Maxborn'), 'Ivan Maxborn i anteckningen utan gata förblir streckad');
cal_malmo_ok(!str_contains($weekHtml, 'data-id="' . (int) $ljustero['id'] . '"'), 'Järfällas bokning följer inte med till Malmö');

$appJs = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
$busyStart = strpos($appJs, 'function bindOutlookBusy');
$busyEnd = strpos($appJs, 'function bindEditBooking');
$busyFn = $busyStart !== false && $busyEnd !== false ? substr($appJs, $busyStart, $busyEnd - $busyStart) : '';
cal_malmo_ok(str_contains($busyFn, 'nodeOpensBooking') && str_contains($busyFn, 'openEdit'), 'klick på en Outlook-kopia med boknings-id öppnar dialogen');
cal_malmo_ok(str_contains($busyFn, 'if (!nodeOpensBooking(el))') && str_contains($busyFn, 'return;'), 'en Outlook-ruta utan boknings-id öppnar ingen dialog');

echo "calendar malmo booking ok\n";
