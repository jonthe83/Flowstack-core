<?php

declare(strict_types=1);

/**
 * ?open= på påminnelser och kalendern.
 * Påminnelse som kontor, person eller flik gömmer hämtas och visas (rätt flik, kontor, who=all).
 * Bokning hoppar till sin vecka eller dag och läsvyn kan öppnas (data-js="read-open").
 */

$tmp = sys_get_temp_dir() . '/jeab-open-deep-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function od(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function od_reset_request(array $get): void
{
    $_GET = $get;
    $_GET['p'] = $get['p'] ?? 'reminders';
    unset($GLOBALS['_JEAB_WHO_SRC'], $GLOBALS['_JEAB_BOOKING_CACHE']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['jeab_reminder_who'], $_SESSION['jeab_company'], $_SESSION['jeab_ordrar_filter']);
    }
}

/** Samma urval som sidans lista efter jeab_reminders_apply_open. */
function od_visible_ids(JeabStore $store): array
{
    $company = jeab_selected_company();
    $who = jeab_reminder_who_filter();
    $search = trim((string) ($_GET['q'] ?? ''));
    if ($search !== '' && $who !== null) {
        $rows = jeab_list_reminders($store, true, $company, null);
        $rows = jeab_filter_reminders_scope($rows, $company, $who);
    } else {
        $rows = jeab_list_reminders($store, true, $company, $who);
    }
    $view = jeab_reminder_view();
    $tag = jeab_reminder_tag_filter();
    $scope = jeab_reminder_list_scope($view, $tag, $search);
    $visible = jeab_filter_reminders($rows, $scope['view'], $scope['tag'], $search);
    $ids = [];
    foreach ($visible as $row) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
    return $ids;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Europe/Stockholm');
$store = jeab_store(true);
$tz = new DateTimeZone('Europe/Stockholm');
$now = new DateTimeImmutable('now', $tz);
$yesterday = $now->modify('-1 day')->setTime(9, 0)->format('Y-m-d H:i:s');
$tomorrow = $now->modify('+1 day')->setTime(10, 0)->format('Y-m-d H:i:s');
$stamp = jeab_now();

$elinToday = $store->insertReminder([
    'title' => 'Elin idag',
    'customer_name' => 'Elin Kund',
    'company' => 'jarfalla',
    'person' => 'Elin',
    'assignees' => ['Elin'],
    'due_at' => $yesterday,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$samToday = $store->insertReminder([
    'title' => 'Sam idag',
    'customer_name' => 'Sam Kund',
    'company' => 'jarfalla',
    'person' => 'Sam L',
    'assignees' => ['Sam L'],
    'due_at' => $yesterday,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$waitingTagged = $store->insertReminder([
    'title' => 'Taggad väntar',
    'customer_name' => 'Tagg Kund',
    'company' => 'jarfalla',
    'person' => 'Elin',
    'assignees' => ['Elin'],
    'tags' => ['vunnen'],
    'due_at' => $tomorrow,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$doneRow = $store->insertReminder([
    'title' => 'Klar rad',
    'customer_name' => 'Klar Kund',
    'company' => 'jarfalla',
    'person' => 'Elin',
    'assignees' => ['Elin'],
    'due_at' => $yesterday,
    'completed_at' => $stamp,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$malmoRow = $store->insertReminder([
    'title' => 'Malmö öppen',
    'customer_name' => 'Malmö Kund',
    'company' => 'malmo',
    'person' => 'Isaq',
    'assignees' => ['Isaq'],
    'due_at' => $yesterday,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);

$elinId = (int) $elinToday['id'];
$samId = (int) $samToday['id'];
$waitingId = (int) $waitingTagged['id'];
$doneId = (int) $doneRow['id'];
$malmoId = (int) $malmoRow['id'];
$ws = (string) time();

od_reset_request([
    'p' => 'reminders',
    'co' => 'jarfalla',
    'v' => 'today',
    'who' => 'Sam L',
    'ws' => $ws,
    'open' => (string) $elinId,
]);
od(!in_array($elinId, od_visible_ids($store), true), 'Elin syns inte när filtret är Sam L');
jeab_reminders_apply_open($store);
od(jeab_selected_company() === 'jarfalla', 'samma kontor behålls');
od((string) ($_GET['who'] ?? '') === 'all', 'personfiltret blir alla');
od(jeab_reminder_view() === 'today', 'försenad utan tagg hamnar på Idag');
od(jeab_reminder_who_filter() === null, 'who=all släpper igenom');
od(in_array($elinId, od_visible_ids($store), true), 'öppen rad finns i listan efter bytet');
$card = jeab_reminder_row(jeab_get_reminder($store, $elinId));
od(str_contains($card, 'is-open') && str_contains($card, 'id="r-' . $elinId . '"'), 'kortet ritas öppet');

od_reset_request([
    'p' => 'reminders',
    'co' => 'jarfalla',
    'v' => 'today',
    'who' => 'Sam L',
    'ws' => $ws,
    'open' => (string) $samId,
]);
jeab_reminders_apply_open($store);
od((string) ($_GET['who'] ?? '') === 'Sam L', 'en rad som redan syns byter inte till alla');
od(jeab_reminder_view() === 'today', 'fliken lämnas när raden redan syns');

od_reset_request([
    'p' => 'reminders',
    'co' => 'jarfalla',
    'v' => 'today',
    'open' => (string) $waitingId,
]);
jeab_reminders_apply_open($store);
od(jeab_reminder_view() === 'waiting', 'taggad framtida rad byter till Väntar');
od(jeab_reminder_tag_filter()['mode'] === 'all', 'taggfiltret släpper igenom raden');
od((string) ($_GET['who'] ?? '') === 'all', 'who=all sätts när fliken gömde raden');
od(in_array($waitingId, od_visible_ids($store), true), 'väntande rad finns i listan');

od_reset_request([
    'p' => 'reminders',
    'co' => 'jarfalla',
    'v' => 'today',
    'who' => 'all',
    'ws' => $ws,
    'open' => (string) $doneId,
]);
jeab_reminders_apply_open($store);
od(jeab_reminder_view() === 'done', 'klar rad byter till Klara');
od(in_array($doneId, od_visible_ids($store), true), 'klar rad finns i listan');

od_reset_request([
    'p' => 'reminders',
    'co' => 'jarfalla',
    'v' => 'today',
    'who' => 'Sam L',
    'ws' => $ws,
    'q' => 'finns-inte-open',
    'open' => (string) $malmoId,
]);
jeab_reminders_apply_open($store);
od(jeab_selected_company() === 'jarfalla', 'co i adressen vinner över påminnelsens kontor');
od((string) ($_GET['co'] ?? '') === 'jarfalla', 'co=jarfalla skrivs inte om');
od(!in_array($malmoId, od_visible_ids($store), true), 'Malmö-raden öppnas inte i Järfälla-vyn');

od_reset_request([
    'p' => 'reminders',
    'co' => 'malmo',
    'v' => 'today',
    'who' => 'all',
    'ws' => $ws,
    'open' => (string) $elinId,
]);
jeab_reminders_apply_open($store);
od(jeab_selected_company() === 'malmo', 'co=malmo&open stannar i Malmö');
od(jeab_reminder_view() === 'today', 'öppet id från Järfälla byter inte flik när co vinner');
od(!in_array($elinId, od_visible_ids($store), true), 'Järfälla-raden syns inte i Malmö');

od_reset_request([
    'p' => 'reminders',
    'v' => 'today',
    'open' => (string) $malmoId,
]);
unset($_GET['co']);
$_SESSION['jeab_company'] = 'jarfalla';
jeab_reminders_apply_open($store);
od(jeab_selected_company() === 'malmo', 'utan co i adressen följer kontoret påminnelsen');
od((string) ($_GET['who'] ?? '') === 'all', 'who=all vid annat kontor');
od(jeab_reminder_view() === 'today', 'Malmö-raden utan tagg ligger på Idag');
od(in_array($malmoId, od_visible_ids($store), true), 'raden från andra kontoret visas när adressen saknar co');

od_reset_request([
    'p' => 'reminders',
    'co' => 'jarfalla',
    'v' => 'today',
    'open' => '999999',
]);
jeab_reminders_apply_open($store);
od(jeab_selected_company() === 'jarfalla' && jeab_reminder_view() === 'today', 'okänt id lämnar filtret');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$applyAt = strpos($index, 'jeab_reminders_apply_open($store)');
$visibleAt = strpos($index, '$visible = jeab_filter_reminders(');
od($applyAt !== false && $visibleAt !== false && $applyAt < $visibleAt, 'sidan växlar filter innan listan byggs');

$far = $now->modify('+21 days')->setTime(10, 0);
while ((int) $far->format('N') >= 6) {
    $far = $far->modify('+1 day');
}
$farEnd = $far->modify('+1 hour');
$booking = jeab_create_booking(
    $store,
    'Öppen bokning',
    ['Sam L'],
    $far->format('Y-m-d H:i:s'),
    $farEnd->format('Y-m-d H:i:s'),
    'laddbox',
    ['company' => 'jarfalla', 'address' => 'Testgatan 1, Järfälla'],
    false
);
$bookingId = (int) $booking['id'];
$expectWeek = jeab_week_offset_for_monday(jeab_monday_of(new DateTimeImmutable((string) $booking['starts_at'])));

od_reset_request([
    'p' => 'bookings',
    'co' => 'malmo',
    'who' => 'Elin',
    'job' => 'batteri',
    'open' => (string) $bookingId,
]);
$opened = jeab_bookings_apply_open($store);
od(is_array($opened) && (int) ($opened['id'] ?? 0) === $bookingId, 'bokningen laddas');
od(jeab_selected_company() === 'jarfalla', 'kalenderns kontor följer bokningen');
od((string) ($_GET['who'] ?? '') === 'all' && (string) ($_GET['job'] ?? '') === 'all', 'person- och jobbfilter som gömmer bokningen blir alla');
od((int) ($_GET['w'] ?? 0) === $expectWeek, 'veckan hoppar till bokningens måndag');
$win = jeab_calendar_visible_window();
$inWindow = jeab_calendar_filtered_bookings($store, $win['rangeFrom'], $win['rangeTo']);
$seen = false;
foreach ($inWindow as $row) {
    if ((int) ($row['id'] ?? 0) === $bookingId) {
        $seen = true;
    }
}
od($seen, 'vågen har bokningen i fönstret');
$payload = jeab_calendar_wave_payload($inWindow, [], $win['monday'], false);
$blob = '';
foreach (['timed', 'early', 'late', 'month'] as $bucket) {
    foreach ($payload[$bucket] ?? [] as $items) {
        if (is_array($items)) {
            $blob .= implode('', $items);
        }
    }
}
$blob .= implode('', $payload['allday'] ?? []);
od(str_contains($blob, 'data-id="' . $bookingId . '"') && str_contains($blob, 'data-js="edit-booking"'), 'vågens HTML har data-id');

od_reset_request([
    'p' => 'bookings',
    'co' => 'jarfalla',
    'who' => 'Sam L',
    'view' => 'day',
    'open' => (string) $bookingId,
]);
jeab_bookings_apply_open($store);
od(($_GET['who'] ?? null) === 'Sam L', 'träffande personfilter lämnas');
od((string) ($_GET['d'] ?? '') === substr((string) $booking['starts_at'], 0, 10), 'dagvyn hoppar till bokningens dag');
od(jeab_booking_view() === 'day', 'dagvyn behålls på en vardag');

$saturday = $now->modify('next saturday')->setTime(11, 0);
$weekend = jeab_create_booking(
    $store,
    'Lördag bokning',
    ['Sam L'],
    $saturday->format('Y-m-d H:i:s'),
    $saturday->modify('+1 hour')->format('Y-m-d H:i:s'),
    'laddbox',
    ['company' => 'jarfalla'],
    false
);
od_reset_request([
    'p' => 'bookings',
    'view' => 'day',
    'open' => (string) $weekend['id'],
]);
jeab_bookings_apply_open($store);
od(!isset($_GET['view']) && jeab_booking_view() === 'week', 'lördag visas som vecka');
od((int) ($_GET['w'] ?? -1) === jeab_week_offset_for_monday(jeab_monday_of(new DateTimeImmutable((string) $weekend['starts_at']))), 'lördagens vecka är satt');

od_reset_request([
    'p' => 'bookings',
    'open' => (string) $bookingId,
    'co' => 'jarfalla',
    'who' => 'all',
    'job' => 'all',
    'w' => (string) $expectWeek,
]);
$openBooking = jeab_bookings_apply_open($store);
$win = jeab_calendar_visible_window();
$monday = $win['monday'];
$weekOffset = (int) $win['weekOffset'];
$bookings = [];
$selectedInstallers = [];
$fromReminder = null;
$flash = null;
$error = null;
$body = require dirname(__DIR__) . '/src/pages/bookings.php';
od(is_string($body), 'kalendersidan ritas');
od(str_contains($body, 'data-open-seed="1"') && str_contains($body, 'data-id="' . $bookingId . '"'), 'sidan har en bokningsknapp för läsvyn');
od(str_contains($body, 'data-js="read-open"'), 'läspanelen har read-open');
od(str_contains($body, '"monday":"' . $monday->format('Y-m-d') . '"'), 'boot-JSON pekar på bokningens vecka');

$app = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
od(str_contains($app, "openBookingParams.get('open')") && str_contains($app, 'openRead(openEl)'), 'kalendern öppnar läsvyn från open=');
$bookAt = strpos($index, 'jeab_bookings_apply_open($store)');
$waveAt = strpos($index, "jeab_calendar_respond_wave(\$store, \$intent)");
od($bookAt !== false && $waveAt !== false && $bookAt < $waveAt, 'veckofönstret hoppar innan vågen svarar');

echo "open deep link ok\n";
