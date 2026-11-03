<?php

declare(strict_types=1);

/**
 * Kalender: förval = inloggad person, kort med tid/adress/kund (utan «Typ ·»),
 * interna Outlook-poster sammanslagna och grå, rader utanför 07–17, röda dagar,
 * svenska datum, «Elbilsladdare», läsvy med länk till ordern och Avboka i «…».
 */

$tmp = sys_get_temp_dir() . '/jeab-kalender-ux-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
date_default_timezone_set('Europe/Stockholm');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ku_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$row = [
    'id' => 501,
    'company' => 'malmo',
    'customer_name' => 'Kund Testsson',
    'address' => 'Exempelgatan 3, 211 11 Malmö',
    'phone' => '0701234567',
    'email' => 'kund@example.invalid',
    'job_type' => 'laddbox',
    'assignee' => 'Kim',
    'starts_at' => '2026-11-02 07:00:00',
    'ends_at' => '2026-11-02 11:00:00',
    'notes' => '',
];

// —— Kort: tid överst, adress fet, kund grå, ingen «Typ ·» ————————————————
$card = jeab_booking_summary_html($row);
ku_assert(str_contains($card, '<span class="wg-time">07–11</span>'), 'kortet har tiden överst');
ku_assert(str_contains($card, '<strong class="wg-lead">Exempelgatan 3, 211 11 Malmö</strong>'), 'adressen är fet');
ku_assert(str_contains($card, '<span class="wg-meta">Kund Testsson</span>'), 'kunden står under');
ku_assert(!str_contains($card, 'Elbilsladdare ·') && !str_contains($card, 'Laddbox'), 'inget «Typ ·»-prefix');
ku_assert(strpos($card, 'wg-time') < strpos($card, 'wg-lead'), 'tid före adress');

// —— Svenska datum ————————————————————————————————————————————————————————
$year = (new DateTimeImmutable('now'))->format('Y');
ku_assert(jeab_calendar_sv_date($year . '-10-05') === '5 okt', '«5 okt»');
ku_assert(jeab_calendar_sv_date($year . '-10-05', true) === 'mån 5 okt' || str_ends_with(jeab_calendar_sv_date($year . '-10-05', true), ' 5 okt'), 'veckodag + «5 okt»');
ku_assert(str_ends_with(jeab_calendar_sv_date('2031-01-02'), '2 jan 2031'), 'annat år får årtal');
ku_assert(str_contains(jeab_calendar_when_label('2026-11-02 07:00:00', '2026-11-02 11:00:00'), '2 nov') && str_contains(jeab_calendar_when_label('2026-11-02 07:00:00', '2026-11-02 11:00:00'), '07:00–11:00'), 'läsvyns tid «mån 2 nov · 07:00–11:00»');
$attrs = jeab_booking_data_attrs($row);
ku_assert(str_contains($attrs, 'data-when="mån 2 nov'), 'data-when är svensk text');
ku_assert(preg_match('/data-when="[^"]*\d{4}-\d{2}/', $attrs) !== 1, 'data-when utan ISO');
$log = jeab_calendar_log_line(['at' => $year . '-10-08 18:00:00', 'type' => 'sms', 'title' => 'X']);
ku_assert(str_starts_with($log, '8 okt 18:00'), 'veckologgen visar «8 okt 18:00»: ' . $log);

// —— Elbilsladdare, aldrig Laddbox ——————————————————————————————————————————
ku_assert(str_contains(jeab_month_legend_html(), 'Elbilsladdare') && !str_contains(jeab_month_legend_html(), 'Laddbox'), 'teckenförklaringen säger Elbilsladdare');

// —— Interna poster slås ihop ————————————————————————————————————————————————
$kick = ['title' => 'Kick Off', 'starts_at' => '2026-11-03 07:00:00', 'ends_at' => '2026-11-03 16:00:00', 'all_day' => false];
$merged = jeab_calendar_merge_outlook([
    $kick + ['who' => 'Sam W'],
    $kick + ['who' => 'Abol'],
    $kick + ['who' => 'Kim'],
    $kick + ['who' => 'Kim'],
    ['title' => 'ADMIN', 'who' => 'Kim', 'starts_at' => '2026-11-03 07:00:00', 'ends_at' => '2026-11-03 16:00:00'],
]);
ku_assert(count($merged) === 2, 'samma kickoff samma dag blir en rad');
ku_assert($merged[0]['who'] === 'Sam W, Abol, Kim', 'alla namn står kvar en gång');
$monday = new DateTimeImmutable('2026-11-02 00:00:00');
$payload = jeab_calendar_wave_payload([$row], [
    $kick + ['who' => 'Sam W'],
    $kick + ['who' => 'Abol'],
    ['title' => 'Privat', 'who' => 'Kim', 'starts_at' => '2026-11-02 19:00:00', 'ends_at' => '2026-11-02 20:00:00'],
    ['title' => 'Gym', 'who' => 'Kim', 'starts_at' => '2026-11-04 06:00:00', 'ends_at' => '2026-11-04 06:45:00'],
], $monday, false);
$tue = implode('', $payload['timed']['2026-11-03']);
ku_assert(substr_count($tue, 'data-title="Kick Off"') === 1, 'kickoff ritas en gång');
ku_assert(str_contains($tue, 'is-internal') && str_contains($tue, 'Sam W, Abol'), 'intern post är grå och har båda namnen');

// —— Utanför 07–17 ————————————————————————————————————————————————————————
ku_assert(isset($payload['late']['2026-11-02'][0]) && str_contains($payload['late']['2026-11-02'][0], '19:00') && str_contains($payload['late']['2026-11-02'][0], 'Privat'), '«19:00 Privat» under dagen');
ku_assert(isset($payload['early']['2026-11-04'][0]) && str_contains($payload['early']['2026-11-04'][0], '06:00'), 'tidig post ovanför dagen');
ku_assert(!str_contains(implode('', $payload['timed']['2026-11-02']), 'Privat'), 'kvällsposten ligger inte i rutnätet');
ku_assert(str_contains(implode('', $payload['timed']['2026-11-02']), '--ord: 420'), 'kortet bär ordning för mobillistan');

// —— Röda dagar ———————————————————————————————————————————————————————————
$red = jeab_calendar_red_days(new DateTimeImmutable('2026-12-21'), new DateTimeImmutable('2026-12-25'));
ku_assert(($red['2026-12-24'] ?? '') === 'Julafton' && ($red['2026-12-25'] ?? '') === 'Juldagen', '24–25 dec är röda');
ku_assert(!isset($red['2026-12-23']), '23 dec är vanlig dag');

// —— Länk till ordern ————————————————————————————————————————————————————
$store = jeab_store();
$saved = $store->insertBooking([
    'customer_id' => null, 'customer_name' => 'Länk Kund', 'company' => 'jarfalla', 'address' => 'Gatan 1', 'job_type' => 'batteri',
    'assignee' => 'Roberto', 'starts_at' => '2026-11-03 07:00:00', 'ends_at' => '2026-11-03 16:00:00',
    'status' => 'booked', 'created_at' => jeab_now(), 'updated_at' => jeab_now(),
]);
jeab_purchase_put(['reminder_id' => 77, 'booking_id' => (int) $saved['id'], 'company' => 'jarfalla']);
$GLOBALS['_JEAB_CAL_LINKS_RESET'] = true;
$links = jeab_calendar_booking_links($saved);
ku_assert(str_contains($links['order'], 'p=ordrar') && str_contains($links['order'], 'id=77'), 'bokning med order länkar till ordern (v3)');
ku_assert(str_contains(jeab_booking_data_attrs($saved), 'data-order="'), 'länken följer med till läsvyn');

// —— Sidan ————————————————————————————————————————————————————————————————
$_SESSION['jeab_email'] = 'demo@example.test';
$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'w' => (string) jeab_week_offset_for_monday(new DateTimeImmutable('2026-12-21'))];
$monday = new DateTimeImmutable('2026-12-21 00:00:00');
$weekOffset = (int) $_GET['w'];
$bookings = [];
$selectedInstallers = [];
$flash = null;
$error = null;
$fromReminder = null;
$html = require dirname(__DIR__) . '/src/pages/bookings.php';
ku_assert(str_contains($html, 'data-js="cal-page"'), 'sidan har en yta som byts utan omladdning');
ku_assert(str_contains($html, '>Idag</a>'), '«Idag» finns');
ku_assert(preg_match('/class="cal-filter week-view-toggle cal-views".*?>Månad<\/a>.*?class="chip is-on"[^>]*data-view="week"[^>]*>Vecka<\/a>.*?data-view="day"[^>]*>Idag<\/a>/s', $html) === 1, 'vyväljare Månad · Vecka (vald) · Idag');
ku_assert(!str_contains($html, '>Laddbox<') && !str_contains($html, 'placeholder="2027-01-01"'), 'ingen Laddbox och ingen ISO-platshållare');
ku_assert(str_contains($html, 'id="booking-read"') && str_contains($html, 'data-js="read-call"') && str_contains($html, 'data-js="read-nav"'), 'läsvy med Ring och Navigera');
ku_assert(preg_match('/<details class="sheet-more" id="booking-more"[^>]*>.*?id="booking-delete".*?<\/details>/s', $html) === 1, 'Avboka ligger i «…»-menyn');
ku_assert(preg_match('/id="booking-delete"[^<]*<\/button>\s*<button class="btn-save"/', $html) !== 1, 'Avboka ligger inte bredvid Spara');
ku_assert(str_contains($html, 'id="booking-start-day-sv"'), 'datumfältet visar svensk text');
ku_assert(str_contains($html, 'data-when="early"') && str_contains($html, 'data-when="late"'), 'rader för före 07 / efter 17');
ku_assert(preg_match('/"who":"Sam L"/', $html) === 1, 'förval: inloggad person (Sam L)');
ku_assert(preg_match('/class="wg-day-col[^"]*" data-day="[^"]+" data-label="(mån|tis|ons|tor|fre) \d+ [a-zå]{3}/u', $html) === 1, 'dagarna har svensk etikett för mobillistan');
ku_assert(substr_count($html, 'class="wg-cell') === 100, 'rutnätet är mån–fre 07–17 (5×20 halvtimmar)');
ku_assert(preg_match('/class="wg-day is-red"[^>]*title="Julafton"/', $html) === 1, 'julafton markerad i veckohuvudet');

echo "ALLA OK\n";
