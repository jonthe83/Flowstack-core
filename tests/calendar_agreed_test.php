<?php

declare(strict_types=1);

/**
 * P-5, P-10, P-17 och B-004. Påhittade bokningar. Graph är mockad.
 * P-6 ligger redan i basen och rörs inte här.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-calendar-agreed-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [[
        'id' => 'testperson',
        'name' => 'Test Person',
        'email' => 'test.person@jarfalla.example.test',
        'company' => 'jarfalla',
    ]],
], true) . ";\n");

require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';
date_default_timezone_set('Europe/Stockholm');

function ca_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;

$http = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$http): array {
    $http++;
    return ['code' => 200, 'body' => ['value' => [[
        'id' => 'evt-nytt',
        'subject' => 'Nytt möte',
        'isAllDay' => false,
        'showAs' => 'busy',
        'start' => ['dateTime' => '2026-10-20T08:00:00'],
        'end' => ['dateTime' => '2026-10-20T09:00:00'],
    ]]]];
};

$monday = new DateTimeImmutable('2026-10-19 00:00:00');
$key = jeab_gp_week_events_cache_key($monday, null, null);
jeab_log_graph_week_put($key, [[
    'who' => 'Test Person',
    'title' => 'Gammalt möte',
    'event_id' => 'evt-gammal',
    'all_day' => false,
    'starts_at' => '2026-10-20 10:00:00',
    'ends_at' => '2026-10-20 11:00:00',
]]);

$fresh = jeab_gp_week_events_cache_read($monday, null, null);
ca_ok($http === 0 && empty($fresh['stale']) && ($fresh['events'][0]['title'] ?? '') === 'Gammalt möte', 'färsk vecka läses utan Graph');

$pdo->prepare('UPDATE log_graph_week SET cached_at = ? WHERE cache_key = ?')->execute([time() - 200, $key]);
$stale = jeab_gp_week_events_cache_read($monday, null, null);
ca_ok($http === 0 && !empty($stale['stale']) && ($stale['events'][0]['title'] ?? '') === 'Gammalt möte', 'gammal vecka visas och märks stale utan Graph');

$due = jeab_log_graph_week_due(2);
ca_ok(count($due) === 1 && ($due[0]['key'] ?? '') === $key, 'klicket ber tick uppdatera samma nyckel');

$refreshed = jeab_gp_week_refresh_due(2, microtime(true) + 5);
ca_ok($http === 1 && (int) ($refreshed['count'] ?? 0) === 1, 'tick hämtar Graph en gång för den begärda veckan');
$after = jeab_gp_week_events_cache_read($monday, null, null);
ca_ok(empty($after['stale']) && ($after['events'][0]['title'] ?? '') === 'Nytt möte', 'efter tick är veckan färsk');

$missingMonday = new DateTimeImmutable('2026-11-02 00:00:00');
$missing = jeab_gp_week_events_cache_read($missingMonday, 'Test Person', null);
ca_ok($http === 1 && !empty($missing['stale']) && $missing['events'] === [], 'saknad vecka är tom och stale utan Graph');

$srcBookings = (string) file_get_contents($root . '/src/bookings.php');
$waveAt = strpos($srcBookings, 'function jeab_calendar_respond_wave');
$wave = $waveAt === false ? '' : substr($srcBookings, $waveAt, 2200);
ca_ok(str_contains($wave, 'jeab_gp_week_events_cache_read') && !str_contains($wave, 'jeab_gp_week_events_cached('), 'veckoklicket läser bara cachen');
ca_ok(str_contains($wave, "'stale_label' => \$stale ? 'uppdateras…' : ''"), 'vågen skickar uppdateras… när veckan är gammal');

$page = (string) file_get_contents($root . '/src/pages/bookings.php');
ca_ok(!str_contains($page, 'jeab_calendar_log_backfill('), 'veckovyn skriver inte kalenderloggen');
ca_ok(str_contains($page, 'data-js="cal-stale"') && str_contains($page, 'uppdateras…'), 'etiketten finns i kalendern');
ca_ok(str_contains($page, 'data-js="cal-toast-undo"') && str_contains($page, '>Ångra<'), 'avboka har en Ångra-toast');
ca_ok(str_contains($page, 'class="week-now"') && str_contains($page, 'Denna vecka'), 'Denna vecka ligger kvar');

$tick = (string) file_get_contents($root . '/src/tick.php');
ca_ok(str_contains($tick, 'jeab_calendar_log_backfill('), 'B-004 körs från tick');
ca_ok(str_contains($tick, 'jeab_gp_week_refresh_due('), 'tick uppdaterar Outlook-cachen');
$maintAt = strpos($tick, 'function jeab_tick_maintenance');
$maint = $maintAt === false ? '' : substr($tick, $maintAt, 12000);
$calAt = strpos($maint, 'jeab_calendar_log_backfill(');
$notisAt = strpos($maint, 'jeab_notiser_history_refresh(');
ca_ok($calAt !== false && $notisAt !== false && $calAt < $notisAt, 'kalenderloggen och utskickshistoriken ligger båda i underhållet');

$js = (string) file_get_contents($root . '/assets/js/app.js');
$avAt = strpos($js, 'function armAvboka');
$avEnd = $avAt === false ? false : strpos($js, "\n  function ", $avAt + 10);
$avFn = ($avAt === false || $avEnd === false) ? '' : substr($js, $avAt, $avEnd - $avAt);
ca_ok($avFn !== '' && str_contains($avFn, 'commitAvboka(state, false);') && !str_contains($avFn, 'setTimeout(') && !str_contains($avFn, 'om 6 sekunder'), 'avboka verkställs direkt efter bekräftelsen');
ca_ok(str_contains($js, "setAttribute('data-busy', '1')") && str_contains($js, "setAttribute('aria-busy', 'true')"), 'avboka-knappen får en busy-markör');
ca_ok(preg_match('/confirm\(\'Avboka den här bokningen\?\'\)[\s\S]{0,220}bookingForm\.submit\(\)/', $js) !== 1, 'bekräftelsen postar inte direkt');
ca_ok(!str_contains($js, 'vh * 0.88'), 'bokningsarket är inte längre kapat till 88 procent');
ca_ok(str_contains($js, 'showOutlookStale(stale)') && str_contains($js, '}, 15000);'), 'en gammal vecka pollas utan Graph-väg');

$kal = (string) file_get_contents($root . '/assets/css/kalender.css');
$app = (string) file_get_contents($root . '/assets/css/app.css');
ca_ok(str_contains($kal, ".week-nav-row .week-view-toggle.cal-views .chip {\n    min-height: 44px;"), 'vyflikarna är 44 px');
ca_ok(str_contains($kal, "body.page-bookings .cal-filter .chip {\n    min-height: 44px;"), 'kalenderns filterchips är 44 px');
ca_ok(!str_contains($kal, '88dvh'), 'arkets tak är inte 88dvh');
ca_ok(str_contains($kal, 'max-height: 100dvh;'), 'arket får använda hela höjden');
ca_ok(str_contains($app, "--slot-h: 44px;") && !str_contains($app, '--slot-h: 28px;'), 'tidsluckan är 44 px');
ca_ok(preg_match('/\.week-now \{[^}]*min-height: 44px;/s', $app) === 1, 'Denna vecka är 44 px hög');
ca_ok(str_contains($app, ".chrome-company .chip,\n.chrome-company .chip.is-on {\n    min-height: 32px;"), 'den delade bolagschipen är kvar på 32 px');

$reminders = (string) file_get_contents($root . '/assets/css/paminnelser.css');
ca_ok(str_contains($reminders, 'html body.page-reminders .cal-filter .chip'), 'påminnelsernas chips har kvar sin egen regel');

echo "ALLA OK\n";
