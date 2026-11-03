<?php

declare(strict_types=1);

/**
 * Kalenderns tre vyer: Månad, Vecka och Idag (dagvy, bläddras dag för dag mån–fre).
 * Dagvyn behåller samma regler: 07–17, rader utanför, röda dagar, svenska datum.
 */

$tmp = sys_get_temp_dir() . '/jeab-kalender-vyer-' . bin2hex(random_bytes(4));
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

function kv_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}


$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

// —— Dag att visa ————————————————————————————————————————————————————————————
$_GET = ['p' => 'bookings', 'view' => 'day', 'd' => '2026-10-11'];
kv_assert(jeab_booking_view() === 'day', 'view=day är dagvyn');
kv_assert(jeab_calendar_focus_day()->format('Y-m-d') === '2026-10-12', 'söndag → måndag (bara mån–fre)');
$_GET['d'] = '2026-13-40';
kv_assert(jeab_calendar_focus_day()->format('Y-m-d') === jeab_calendar_weekday_step(new DateTimeImmutable('today'), 0)->format('Y-m-d'), 'ogiltigt datum → idag');
kv_assert(jeab_calendar_weekday_step(new DateTimeImmutable('2026-10-16'), 1)->format('Y-m-d') === '2026-10-19', 'nästa dag efter fredag är måndag');
kv_assert(jeab_calendar_weekday_step(new DateTimeImmutable('2026-10-19'), -1)->format('Y-m-d') === '2026-10-16', 'föregående dag före måndag är fredag');
$_GET = ['p' => 'bookings', 'view' => 'day', 'd' => '2026-12-24', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all'];
$win = jeab_calendar_visible_window();
kv_assert($win['monday']->format('Y-m-d') === '2026-12-21', 'dagvyn hämtar veckan som dagen ligger i');
$u = jeab_bookings_url(['who' => 'Elin']);
kv_assert(str_contains($u, 'view=day') && str_contains($u, 'd=2026-12-24') && !str_contains($u, '&w='), 'filterbyte stannar på dagen');
$u = jeab_bookings_url(['view' => '', 'w' => 3]);
kv_assert(!str_contains($u, 'view=') && !str_contains($u, 'd=') && str_contains($u, '&w=3'), 'till veckan: inget d');
kv_assert(str_contains(jeab_chrome_company_url('bookings', 'malmo'), 'view=day') && str_contains(jeab_chrome_company_url('bookings', 'malmo'), 'd=2026-12-24'), 'kontorsbyte stannar på dagen');

// —— Sidan i dagvyn ——————————————————————————————————————————————————————————
$monday = $win['monday'];
$weekOffset = (int) $win['weekOffset'];
$bookings = [];
$selectedInstallers = [];
$flash = null;
$error = null;
$fromReminder = null;
$html = require dirname(__DIR__) . '/src/pages/bookings.php';
kv_assert(str_contains($html, 'class="week-grid is-day"'), 'dagvyn ritar en kolumn');
kv_assert(substr_count($html, 'class="wg-cell') === 20, 'en dag 07–17 (20 halvtimmar)');
kv_assert(str_contains($html, '<summary class="week-label">tor 24 dec</summary>'), 'rubrik på svenska «tor 24 dec»');
kv_assert(preg_match('/week-nav-prev" href="[^"]*d=2026-12-23[^"]*"[^>]*aria-label="Föregående dag"/', $html) === 1, 'bakåt = föregående dag');
kv_assert(preg_match('/week-nav-next" href="[^"]*d=2026-12-25[^"]*"[^>]*aria-label="Nästa dag"/', $html) === 1, 'framåt = nästa dag');
kv_assert(preg_match('/>Månad<\/a>.*?>Vecka<\/a>.*?class="chip week-today is-on"[^>]*>Idag<\/a>/s', $html) === 1, 'vyväljaren visar Idag vald');
kv_assert(preg_match('/data-view="week"[^>]*>Vecka/', $html) === 1 && preg_match('/href="[^"]*w=11[^"]*"[^>]*data-view="week"/', $html) === 1, 'Vecka går till dagens vecka');
kv_assert(str_contains($html, 'class="wg-day is-today') === false && str_contains($html, 'title="Julafton"'), 'röd dag i dagvyn');
kv_assert(str_contains($html, '"view":"day"') && str_contains($html, '"day":"2026-12-24"'), 'boot vet att det är dagvyn');
kv_assert(str_contains($html, 'data-when="early"') && str_contains($html, 'data-when="late"'), 'rader före 07 / efter 17 finns i dagvyn');

echo "ALLA OK\n";
