<?php

declare(strict_types=1);

/**
 * Veckoväljaren i kalendern är stängd tills man klickar.
 * Panelen är ett överlägg under rubrikraden, inte ett fält som ligger öppet
 * över «v44 · 26–30 oktober» och flikarna Månad/Vecka/Idag.
 */

$tmp = sys_get_temp_dir() . '/jeab-week-pick-' . bin2hex(random_bytes(4));
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

function wp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

$octMonday = jeab_monday_of(new DateTimeImmutable('2026-10-26'));
$_GET = [
    'p' => 'bookings',
    'w' => (string) jeab_week_offset_for_monday($octMonday),
    'co' => 'jarfalla',
    'who' => 'all',
    'job' => 'all',
];
$win = jeab_calendar_visible_window();
$monday = $win['monday'];
$weekOffset = (int) $win['weekOffset'];
$bookings = [];
$selectedInstallers = [];
$flash = null;
$error = null;
$fromReminder = null;
$html = require dirname(__DIR__) . '/src/pages/bookings.php';
$label = jeab_week_range_label($monday);

wp_assert($label === 'v44 · 26–30 oktober', 'vecka 44 är «v44 · 26–30 oktober», fick ' . $label);
wp_assert(str_contains($html, '<summary class="week-label">' . $label . '</summary>'), 'rubriken i veckovyn är hel');
wp_assert(str_contains($html, '26–30 oktober') && !str_contains($html, '>6–30 oktober<'), 'datumet klipps inte i källan');
wp_assert(preg_match('/<details class="week-pick">/', $html) === 1, 'veckoväljaren ritas utan open');
wp_assert(preg_match('/<nav class="cal-filter week-view-toggle cal-views"[^>]*>.*?Månad<\/a>.*?Vecka<\/a>.*?Idag<\/a>/s', $html) === 1, 'Månad, Vecka och Idag ligger i en nav');
wp_assert(str_contains($html, 'class="week-date"') && str_contains($html, 'aria-label="Gå till datum"') && !str_contains($html, 't.ex. 5 okt'), 'datumfältet finns utan exempelplaceholder');
$firstChip = 'v' . jeab_week_monday(-1)->format('W');
$lastChip = 'v' . jeab_week_monday(8)->format('W');
wp_assert(str_contains($html, '>' . $firstChip . '<') && str_contains($html, '>' . $lastChip . '<'), 'veckochips ' . $firstChip . '–' . $lastChip . ' finns i panelen');

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/app.css');
$kal = (string) file_get_contents($root . '/assets/css/kalender.css');
$js = (string) file_get_contents($root . '/assets/js/app.js');
wp_assert(preg_match('/\.week-pick-box\s*\{[^}]*display:\s*none;[^}]*position:\s*absolute;[^}]*top:\s*calc\(100% \+ 4px\)/', $css) === 1, 'stängd panel är dold och öppen panel är ett överlägg under raden');
wp_assert(preg_match('/\.week-pick\[open\]\s*>\s*\.week-pick-box\s*\{\s*display:\s*block;/', $css) === 1, 'panelen visas bara när details är öppen');
wp_assert(preg_match('/\.week-pick\s*\{[^}]*display:\s*block;[^}]*overflow:\s*visible;/', $css) === 1, 'details är block så panelen inte läcker');
wp_assert(preg_match('/\.week-label\s*\{[^}]*overflow:\s*visible;/', $css) === 1, 'rubriken klipps inte med overflow');
wp_assert(preg_match('/\.week-label::marker\s*\{[^}]*content:\s*none;/', $css) === 1, 'pilmarkören på summary är borta');
wp_assert(!preg_match('/\.week-nav-shift\s*\{[^}]*100% - 5\.5em/', $css), 'flikarna reserveras inte längre 5.5em');
wp_assert(preg_match('/\.week-nav-row\s*\{[^}]*flex-wrap:\s*wrap;/', $css) === 1, 'raden får radbryta i stället för att lägga flikarna ovanpå');
wp_assert(preg_match('/\.week-nav-shift\s*\{[^}]*grid-template-columns:\s*var\(--tap\) minmax\(min-content, max-content\) var\(--tap\)/', $css) === 1, 'rubrikkolumnen är minst så bred som texten');
wp_assert(preg_match('/\.week-nav-shift\s*\{[^}]*width:\s*100%;[^}]*minmax\(min-content, 1fr\)/', $kal) === 1, 'mobil: rubriken får mittenkolumnen');
wp_assert(preg_match('/\.week-nav-row \.week-view-toggle\.cal-views\s*\{\s*width:\s*100%;/', $kal) === 1, 'mobil: flikarna är en egen rad');
wp_assert(str_contains($js, "document.querySelectorAll('details.week-pick[open]')") && str_contains($js, "event.key !== 'Escape'") && str_contains($js, '!pick.contains(event.target)'), 'klick utanför och Esc stänger väljaren');

echo "ALLA OK\n";
