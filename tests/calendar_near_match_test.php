<?php

declare(strict_types=1);

/**
 * Live: måndagens Outlook «Batteri, Ljusterö huvuds väg 23, Sten Håkansson»
 * hör till fredagens bokning. Graph-id går före. Flera träffar öppnar inget.
 * «Ps 10» och «ADMIN» förblir streckade.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-near-' . bin2hex(random_bytes(4));
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

function near_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function near_insert(JeabStore $store, string $name, array $data): array
{
    $stamp = jeab_now();
    $customer = $store->insertCustomer($name . ' ' . bin2hex(random_bytes(2)), $stamp);
    $data['customer_id'] = $customer['id'];
    $data['created_at'] = $stamp;
    $data['updated_at'] = $stamp;
    if (!array_key_exists('status', $data)) {
        $data['status'] = 'booked';
    }

    return $store->insertBooking($data);
}

function near_candidate(array $payload, string $title, int $id): ?array
{
    foreach ($payload['events'] as $event) {
        if (!is_array($event) || ($event['title'] ?? '') !== $title) {
            continue;
        }
        foreach ($event['candidates'] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
    }

    return null;
}

function near_event(array $payload, string $title): ?array
{
    foreach ($payload['events'] as $event) {
        if (is_array($event) && ($event['title'] ?? '') === $title) {
            return $event;
        }
    }

    return null;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_SESSION['jeab_company'] = 'jarfalla';
$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'GET';

$store = jeab_store(true);
$title = 'Batteri, Ljusterö huvuds väg 23, Sten Håkansson';
$mon = '2026-10-19';
$fri = '2026-10-23';

$friday = near_insert($store, 'Sten Håkansson', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $fri . ' 07:00:00',
    'ends_at' => $fri . ' 16:00:00',
]);
$cancelled = near_insert($store, 'Sten Avbokad', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
]);
$store->pdo()->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute(['cancelled', (int) $cancelled['id']]);
$other = near_insert($store, 'Annan Kund', [
    'customer_name' => 'Annan Kund',
    'address' => 'Storgatan 4, 177 40 Järfälla',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 10:00:00',
]);
$nameOnly = near_insert($store, 'Sten Bara namn', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Parkvägen 2, 177 40 Järfälla',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => '2026-10-21 08:00:00',
    'ends_at' => '2026-10-21 12:00:00',
]);
$far = near_insert($store, 'Sten För långt', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => '2026-10-27 07:00:00',
    'ends_at' => '2026-10-27 16:00:00',
]);
$linked = near_insert($store, 'Intern utbildning', [
    'customer_name' => 'Intern utbildning',
    'address' => 'Kontoret 1, 177 40 Järfälla',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => $mon . ' 15:00:00',
    'ends_at' => $mon . ' 16:00:00',
]);
$outlook = jeab_outlook_pdo();
near_ok($outlook instanceof PDO, 'Outlook-tabellen finns');
$outlook->prepare(
    'INSERT INTO booking_outlook_events (booking_id, resource, email, graph_event_id, show_as, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
)->execute([(int) $linked['id'], 'Roberto', 'saljare3@jarfalla.example.test', 'graph-hakansson-link', 'busy', jeab_now()]);

$event = [
    'who' => 'Roberto',
    'title' => $title,
    'event_id' => 'graph-copy-not-stored',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
    'all_day' => false,
];
$admin = [
    'who' => 'Roberto',
    'title' => 'ADMIN',
    'event_id' => 'graph-admin',
    'starts_at' => $mon . ' 12:00:00',
    'ends_at' => $mon . ' 13:00:00',
    'all_day' => false,
];
$ps = [
    'who' => 'Elin',
    'title' => 'Ps 10',
    'event_id' => 'graph-ps',
    'starts_at' => '2026-10-20 07:00:00',
    'ends_at' => '2026-10-20 08:00:00',
    'all_day' => false,
];
$linkedEvent = [
    'who' => 'Roberto',
    'title' => $title,
    'event_id' => 'graph-hakansson-link',
    'starts_at' => $mon . ' 09:00:00',
    'ends_at' => $mon . ' 11:00:00',
    'all_day' => false,
];

$before = (int) $store->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
$linksBefore = (int) $outlook->query('SELECT COUNT(*) FROM booking_outlook_events')->fetchColumn();
$debug = jeab_calendar_match_debug($store, $mon, 'Roberto', [$event, $admin, $linkedEvent]);
$after = (int) $store->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
$linksAfter = (int) $outlook->query('SELECT COUNT(*) FROM booking_outlook_events')->fetchColumn();
near_ok($before === $after && $linksBefore === $linksAfter, 'läsningen skriver varken bokning eller Outlook-länk');

$hit = near_event($debug, $title);
near_ok(is_array($hit) && (int) ($hit['matched_id'] ?? 0) === (int) $friday['id'] && ($hit['via'] ?? '') === 'near', 'fredagens Håkansson öppnas från måndagens kopia');
near_ok(($hit['span_from'] ?? '') === '2026-10-12' && ($hit['span_to'] ?? '') === '2026-10-27', 'kandidaterna spänner ±7 dagar');
$fridayRow = near_candidate($debug, $title, (int) $friday['id']);
near_ok(is_array($fridayRow) && ($fridayRow['name'] ?? false) === true && ($fridayRow['street'] ?? false) === true && ($fridayRow['match'] ?? false) === true, 'namn och Huvudsväg 23 träffar båda');
$nameRow = near_candidate($debug, $title, (int) $nameOnly['id']);
near_ok(is_array($nameRow) && ($nameRow['match'] ?? true) === false && in_array('rank', $nameRow['why'] ?? [], true) && ($nameRow['name'] ?? false) === true && ($nameRow['street'] ?? true) === false, 'bara namnet förlorar mot namn plus gata');
$cancelRow = near_candidate($debug, $title, (int) $cancelled['id']);
near_ok(is_array($cancelRow) && ($cancelRow['match'] ?? true) === false && in_array('status', $cancelRow['why'] ?? [], true), 'avbokad Håkansson öppnas inte');
$otherRow = near_candidate($debug, $title, (int) $other['id']);
near_ok(is_array($otherRow) && in_array('text', $otherRow['why'] ?? [], true), 'annan kund utan gatan faller på text');
near_ok(near_candidate($debug, $title, (int) $far['id']) === null, 'bokning åtta dagar bort är inte kandidat');

$adminEvent = near_event($debug, 'ADMIN');
near_ok(is_array($adminEvent) && ($adminEvent['matched_id'] ?? null) === null, 'ADMIN öppnas inte');
$psDebug = jeab_calendar_match_debug($store, '2026-10-20', 'Elin', [$ps]);
$psEvent = near_event($psDebug, 'Ps 10');
near_ok(is_array($psEvent) && ($psEvent['matched_id'] ?? null) === null && ($psDebug['ok'] ?? false) === true, 'Ps 10 öppnas inte och är inget fel');

$graphDebug = jeab_calendar_match_debug($store, $mon, 'Roberto', [$linkedEvent]);
$graphHit = near_event($graphDebug, $title);
near_ok(is_array($graphHit) && (int) ($graphHit['matched_id'] ?? 0) === (int) $linked['id'] && ($graphHit['via'] ?? '') === 'graph', 'sparat Graph-id vinner över namn och gata');

$monday = new DateTimeImmutable('2026-10-19');
$payload = jeab_calendar_wave_payload([], [$event, $admin, $ps], $monday, false, []);
$flat = '';
foreach (['timed', 'early', 'late', 'month', 'allday'] as $bucket) {
    foreach ($payload[$bucket] ?? [] as $items) {
        if (is_array($items)) {
            $flat .= implode('', $items);
        }
    }
}
near_ok(str_contains($flat, 'data-id="' . (int) $friday['id'] . '"') && str_contains($flat, 'data-editable="1"'), 'måndagens kopia blir knappen för fredagens bokning');
near_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="ADMIN"/', $flat) === 1, 'ADMIN ritas utan knapp');
near_ok(preg_match('/data-js="outlook-busy"[^>]*data-title="Ps 10"/', $flat) === 1, 'Ps 10 ritas utan knapp');

$twin = near_insert($store, 'Sten Tvilling', [
    'customer_name' => 'Sten Håkansson',
    'address' => 'Huvudsväg 23, 184 90 Ljusterö',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => '2026-10-22 07:00:00',
    'ends_at' => '2026-10-22 16:00:00',
]);
$amb = jeab_calendar_match_debug($store, $mon, 'Roberto', [$event]);
$ambEvent = near_event($amb, $title);
near_ok(is_array($ambEvent) && ($ambEvent['matched_id'] ?? null) === null, 'två likvärdiga Håkansson öppnar inget');
foreach ([(int) $friday['id'], (int) $twin['id']] as $id) {
    $row = near_candidate($amb, $title, $id);
    near_ok(is_array($row) && in_array('ambiguous', $row['why'] ?? [], true) && ($row['match'] ?? true) === false, 'id ' . $id . ' är tvetydig');
}

$end = (int) $store->pdo()->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
near_ok($end === $before + 1, 'vågen skriver ingen bokning');

echo "calendar near match ok\n";
