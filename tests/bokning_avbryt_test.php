<?php

declare(strict_types=1);

/**
 * QA: Avbryt på en oförändrad bokning ska inte fråga «Kasta ändringarna?».
 * Datumfältet ska inte visa placeholdern «t.ex. 5 okt».
 */

$tmp = sys_get_temp_dir() . '/jeab-bokning-avbryt-' . bin2hex(random_bytes(4));
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

function ba_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_GET = ['p' => 'bookings', 'co' => 'jarfalla'];
$win = jeab_calendar_visible_window();
$monday = $win['monday'];
$weekOffset = (int) $win['weekOffset'];
$bookings = [];
$selectedInstallers = [];
$flash = null;
$error = null;
$fromReminder = null;
$html = require dirname(__DIR__) . '/src/pages/bookings.php';
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
$sheetCss = (string) file_get_contents(dirname(__DIR__) . '/assets/css/bokning-sheet.css');

ba_assert(str_contains($html, 'class="week-date"') && str_contains($html, 'aria-label="Gå till datum"'), 'datumfältet finns kvar');
ba_assert(!str_contains($html, 't.ex. 5 okt') && !str_contains($html, 'placeholder="Gå till datum'), 'placeholdern «t.ex. 5 okt» är borta');

ba_assert(preg_match('/function isBookingSheetDirty\(\) \{\s*return bookingSheetSnapshot\(\) !== sheetOpenedSnapshot;\s*\}/', $js) === 1, 'dirty-kollen jämför bara mot ursprungsvärden');
ba_assert(!str_contains($js, 'bookingSheetHasTypedContent'), 'ifyllt kundnamn räknas inte som en ändring');
ba_assert(preg_match('/function bookingSheetSnapshot\(\) \{\s*syncSheetContacts\(\);/', $js) === 1, 'jämförelsen normaliserar telefon och e-post först');
ba_assert(str_contains($js, 'function absorbSheetAutofill()') && str_contains($js, 'jeabAutofillStart'), 'autofyll efter öppning ingår i ursprungsvärdet');
ba_assert(str_contains($sheetCss, '@keyframes jeabAutofillStart'), 'autofyll-animationen finns i arkets css');

$edit = strpos($js, 'function openEdit(btn)');
$phone = strpos($js, 'setSheetPhone(btn.dataset.phone', $edit);
$show = strpos($js, 'showSheet(btn, \'none\')', $edit);
ba_assert($edit !== false && $phone !== false && $show !== false && $phone < $show, 'redigering fyller och normaliserar innan bilden tas');

$node = <<<'JS'
const opened = ['booking.update', '42', '2026-10-23T09:00', '2026-10-23T10:00', 'Anna', 'a@example.com', '+46701234567', 'Storgatan 1', 'Byt filter', 'Elin', 'sol'].join('\n');
const same = opened;
const renamed = opened.replace('Anna', 'Bertil');
if (same !== opened) {
  throw new Error('oförändrad bokning ska vara ren');
}
if (renamed === opened) {
  throw new Error('ändrat namn ska vara smutsigt');
}
console.log('snapshot-ok');
JS;
$nodeFile = $tmp . '/snapshot-check.js';
file_put_contents($nodeFile, $node);
exec('node ' . escapeshellarg($nodeFile), $out, $code);
ba_assert($code === 0 && in_array('snapshot-ok', $out, true), 'snapshot-jämförelsen slår bara till vid riktig skillnad');

echo "bokning avbryt ok\n";
