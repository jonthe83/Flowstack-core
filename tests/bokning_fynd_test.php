<?php

declare(strict_types=1);

/**
 * Bokningsfynd U5, U6, U19, B21, B23–B28, B30–B36.
 * Inget kundmejl lämnar testet. B29 (tvåtimmarsklockan) rörs inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-bokning-fynd-' . bin2hex(random_bytes(4));
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

function bf_assert(bool $ok, string $message): void
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
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/app.css');
$kal = (string) file_get_contents(dirname(__DIR__) . '/assets/css/kalender.css');
$sheet = (string) file_get_contents(dirname(__DIR__) . '/assets/css/bokning-sheet.css');

bf_assert(str_contains($html, 'type="date"') && str_contains($html, 'aria-label="Gå till datum"'), 'B27 datumfältet är type=date');
bf_assert(str_contains($html, 'autocomplete="name"') && str_contains($html, 'autocomplete="email"') && str_contains($html, 'autocomplete="tel"') && str_contains($html, 'autocomplete="street-address"'), 'B26 kontaktfält har autocomplete');
bf_assert(str_contains($html, 'name="email"') && str_contains($html, 'name="phone"') && !str_contains($html, 'id="booking-email-edit"'), 'B26 e-post och telefon är synliga fält');
bf_assert(str_contains($html, 'data-js="sheet-discard-save"') && str_contains($html, '>Spara</button>'), 'B35 kasta-rutan har Spara');
bf_assert(str_contains($html, 'data-js="read-prev-day"') && str_contains($html, 'data-js="read-next-day"'), 'U19 dagen före/efter i läsvyn');
bf_assert(str_contains($html, 'data-js="sheet-read"'), 'B28 Visa finns kvar från redigeringen');
bf_assert(str_contains($js, 'save.disabled = false'), 'U5 Spara stängs inte av');
bf_assert(str_contains($js, 'opt.disabled = false') && !str_contains($js, 'opt.disabled = tooSoon'), 'U5 tidval stängs inte av');
bf_assert(str_contains($js, 'function queueMovedSave') && str_contains($js, 'Bekräftelsemejl skickas inte av flytten.'), 'U6 flytt kan ångras och skickar inget mejl');
bf_assert(str_contains($js, 'sheet.classList.add(\'is-sheet\')') && str_contains($kal, '#booking-sheet.outlook'), 'B21 bottenark på mobil');
bf_assert(str_contains($js, 'openCreate(`${day}T09:00`, `${day}T10:00`'), 'B23 ny tid är en timme, inte heldag');
bf_assert(str_contains($js, 'function applySelectedJobRange'), 'B24 produkt i arket sätter längden');
bf_assert(str_contains($css, '.outlook .chip-opt span') && preg_match('/\.outlook \.chip-opt span,\s*\.outlook \.chip \{\s*min-height: 44px;/', $css) === 1, 'B25 chips är 44 px');
bf_assert(str_contains($css, '.who-job-summary') && preg_match('/\.who-job-summary \{[^}]*min-height: 44px;/', $css) === 1, 'B25 Vem · Typ är 44 px');
bf_assert(str_contains($js, 'whoJob.open = true'), 'B25 Vem · Typ är utfälld');
bf_assert(str_contains($js, 'field.type === \'date\''), 'B27 datumvalet hoppar direkt');
bf_assert(str_contains($js, 'openEdit(el)') && str_contains($js, 'if (calIsPhone())'), 'B28 desktop öppnar redigering');
bf_assert(str_contains($js, 'T09:00') && str_contains($js, 'selectedJobKey()'), 'B31 tom dag använder timme eller produkt, inte 30 min');
bf_assert(preg_match('/\.mv-item \{[^}]*min-height: 44px;[^}]*white-space: normal;/', $css) === 1, 'B32 månadsposter är 44 px utan ellipsis');
bf_assert(preg_match('/wg-allday-item \{\s*width: 100%;[^}]*min-height: 44px;/', $kal) === 1, 'B33 heldag är 44 px på mobil');
bf_assert(preg_match('/\.cal-read-line \{[^}]*min-height: 44px;/', $kal) === 1, 'B34 läsvyns kontakttrad är 44 px');
bf_assert(str_contains($js, 'requestCloseBookingSheet()'), 'B35 Avbryt frågar innan kasta');
bf_assert(str_contains($js, 'är redan bokad då. Spara ändå?'), 'B36 dublett frågas innan sparning');
bf_assert(str_contains($sheet, 'min-height: 44px'), 'arkets tidrad är minst 44 px');
bf_assert(!str_contains($js, 'jeab_booking_notify_customer') && !str_contains($js, 'booking.notify'), 'ingen kundutskicksknapp i kalender-JS');

$store = jeab_store();
$first = jeab_create_booking($store, 'Ada', ['Roberto'], '2026-11-02 09:00:00', '2026-11-02 10:00:00', 'service', [
    'company' => 'jarfalla',
    'email' => 'ada@example.invalid',
    'phone' => '0700000000',
    'address' => 'Testgatan 1',
]);
bf_assert((int) ($first['id'] ?? 0) > 0, 'första bokningen sparas');
bf_assert(!isset($GLOBALS['_JEAB_BOOKING_NOTIFY']), 'B29/B30 sparande skickar inget kundmejl');
bf_assert(jeab_booking_saved_flash('Bokad.') === 'Bokad.', 'ensam bokning har ingen dublettvarning');

$second = jeab_create_booking($store, 'Beda', ['Roberto'], '2026-11-02 09:30:00', '2026-11-02 11:00:00', 'service', [
    'company' => 'jarfalla',
    'email' => 'beda@example.invalid',
]);
bf_assert((int) ($second['id'] ?? 0) > 0 && (int) $second['id'] !== (int) $first['id'], 'B36 dublett sparas ändå');
bf_assert(($store->getBooking((int) $first['id'])['status'] ?? '') === 'booked', 'första bokningen finns kvar');
bf_assert(jeab_booking_saved_flash('Bokad.') === 'Bokad. Samma person är redan bokad då.', 'B36 varningen följer med flash');
bf_assert(jeab_flash_text('Bokad. Samma person är redan bokad då.') === 'Bokad. Samma person är redan bokad då.', 'B36 flash släpps igenom');
bf_assert(jeab_flash_text('Bokad. godtycklig text') === null, 'andra flash-texter släpps inte in');

$GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE'] = 'Outlook-synken misslyckades.';
$GLOBALS['_JEAB_BOOKING_OVERLAP_NOTE'] = '';
bf_assert(jeab_booking_saved_flash('Bokad.') === 'Bokad. Outlook-synken misslyckades.', 'B30 Outlook-fel syns i flash');
bf_assert(jeab_flash_text('Bokad. Outlook-synken misslyckades.') === 'Bokad. Outlook-synken misslyckades.', 'B30 flash släpps igenom');
unset($GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE'], $GLOBALS['_JEAB_BOOKING_OVERLAP_NOTE']);
bf_assert(jeab_booking_saved_flash('Bokad.') === 'Bokad.', 'utan notiser lovar flash inget mejl');

echo "ALLA OK\n";
