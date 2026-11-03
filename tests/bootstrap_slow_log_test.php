<?php

declare(strict_types=1);

/**
 * Långsam request och klick-till-färdig skrivs i felloggen, utan söktext.
 * Påhittad data. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-slow-log-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_TIMING_LOG'] = true;
$GLOBALS['_JEAB_SLOW_TOTAL_MS'] = 100;
$GLOBALS['_JEAB_SLOW_QUERY_MS'] = 10;
$GLOBALS['_JEAB_SLOW_CLICK_MS'] = 1500;
$GLOBALS['_JEAB_SLOW_WINDOW'] = 1800;
$GLOBALS['_JEAB_NOW'] = 1_700_000_000;
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

function sl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sl_rows(): array
{
    $raw = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
    $out = [];
    foreach (preg_split("/\n/", $raw) ?: [] as $line) {
        $row = json_decode(trim((string) $line), true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

$root = dirname(__DIR__);
$boot = (string) file_get_contents($root . '/src/bootstrap.php');
sl(str_contains($boot, 'jeab_timing_log_slow();'), 'shutdown anropar felloggen');
sl(str_contains($boot, "JEAB_SLOW_TOTAL_MS', 800"), 'request över 800 ms loggas');
$index = (string) file_get_contents($root . '/index.php');
$clickAt = strpos($index, 'jeab_timing_click_request');
$storeAt = strpos($index, 'jeab_boot_store');
sl($clickAt !== false && $storeAt !== false && $clickAt < $storeAt, 'klickloggen svarar innan butiken öppnas');
$shell = (string) file_get_contents($root . '/assets/js/shell.js');
sl(str_contains($shell, "body.set('action', 'timing.click')"), 'skalet skickar timing.click');
sl(str_contains($shell, 'ms >= 1500'), 'skalet skickar bara långsamma klick');
sl(!str_contains($shell, 'location.search') || !str_contains(substr($shell, (int) strpos($shell, 'function reportClickDone')), 'location.search'), 'klickrapporten tar inte med querysträngen');
sl(str_contains($shell, "body.set('page', pageOf(location.href))"), 'klickrapporten skickar sidslug');

$_GET = ['p' => 'kontakter', 'q' => 'Ada Hemliggatan', 'view' => 'week', 'intent' => 'card'];
jeab_timing_span('calendar', 0.42);
$_SERVER['REQUEST_URI'] = '/index.php?p=kontakter&q=Ada+Hemliggatan';
$GLOBALS['_JEAB_REQ_T0'] = microtime(true) - 0.25;
$GLOBALS['_JEAB_SLOW_SQL'] = [[
    'ms' => 55.0,
    'sql' => "SELECT id FROM contacts WHERE owner_email = 'ada@example.test'",
]];
unset($GLOBALS['_JEAB_TIMING_LOG_DONE']);
jeab_timing_log_slow();
$rows = sl_rows();
$blob = (string) file_get_contents(jeab_app_errors_path());
sl(count($rows) === 2, 'request och fråga blir två rader, fick ' . count($rows));
sl(str_contains($blob, 'långsam request') && str_contains($blob, 'långsam fråga'), 'båda etiketterna finns');
sl(str_contains($blob, 'SELECT contacts'), 'frågan loggas som form');
sl(!str_contains($blob, 'ada@example.test'), 'mejlet i SQL följer inte med');
sl(str_contains($blob, 'view=week') && str_contains($blob, 'intent=card') && str_contains($blob, 'spans=') && str_contains($blob, 'calendar:'), 'request-raden har vy, intent och spår');
sl(str_contains($blob, 'SELECT id FROM contacts WHERE owner_email = ?'), 'de långsamma frågorna är normaliserad SQL');
sl(!str_contains($blob, 'Hemliggatan'), 'söktexten följer inte med');
sl(!str_contains($blob, 'q=Ada'), 'querysträngen följer inte med');
sl((string) ($rows[0]['level'] ?? '') === 'info', 'raden är info och aviserar inte');

unset($GLOBALS['_JEAB_TIMING_LOG_DONE']);
$GLOBALS['_JEAB_NOW'] = 1_700_000_100;
jeab_timing_log_slow();
$again = sl_rows();
sl(count($again) === 2, 'samma sida inom fönstret skapar ingen ny rad');
$counts = array_map(static fn (array $row): int => (int) ($row['count'] ?? 0), $again);
sl($counts === [2, 2] || max($counts) === 2, 'räknaren höjs');

$_POST = ['action' => 'timing.click', 'ms' => '100', 'page' => 'kontakter', 'q' => 'Ada Hemliggatan'];
$_POST['_csrf'] = jeab_csrf_token();
$fast = jeab_timing_click_handle();
sl($fast['ok'] === true && $fast['status'] === 200, 'snabbt klick svarar ok');
sl(count(sl_rows()) === 2, 'snabbt klick skriver ingen rad');

$_POST['ms'] = '1800';
$_POST['page'] = 'kontakter';
$slowClick = jeab_timing_click_handle();
sl($slowClick['ok'] === true, 'långsamt klick svarar ok');
$blob = (string) file_get_contents(jeab_app_errors_path());
sl(str_contains($blob, 'långsamt klick') && str_contains($blob, 'p=kontakter'), 'klicket loggas med sidslug');
sl(!str_contains($blob, 'Hemliggatan'), 'klickloggen har ingen söktext');
$_POST['page'] = 'Kontakter<script>';
jeab_timing_click_handle();
$blob = (string) file_get_contents(jeab_app_errors_path());
sl(str_contains($blob, 'p=okand') && !str_contains($blob, 'script') && !str_contains($blob, '<'), 'ogiltig sidslug blir okand');

$_POST['_csrf'] = 'fel-token';
$denied = jeab_timing_click_handle();
sl($denied['ok'] === false && $denied['status'] === 419, 'klick utan csrf avvisas');

$alarm = (string) file_get_contents($root . '/src/bootstrap.php');
$fnAt = strpos($alarm, 'function jeab_tick_page_alarm');
$fnEnd = strpos($alarm, 'function jeab_mark_html_page_request');
$fn = $fnAt !== false ? substr($alarm, $fnAt, ($fnEnd === false ? 0 : $fnEnd) - $fnAt) : '';
sl(str_contains($fn, 'tick-larm ') && str_contains($fn, '$e::class'), 'tick-larmet loggar undantagsklassen');
sl(!str_contains($fn, 'catch (Throwable) {'), 'tick-larmets catch är inte tomt');

echo "slow log ok\n";
