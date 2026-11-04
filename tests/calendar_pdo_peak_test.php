<?php

declare(strict_types=1);

/**
 * Kalender och synk håller högst en PDO.
 * Under Graph-läsning utan efterföljande skrivning är den släppt. 1203 försöks bara vid anslutning.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-cal-pdo-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';

$fail = 0;
function peak_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'intent' => 'calendar-outlook'];
$_SERVER['REQUEST_METHOD'] = 'GET';

peak_ok(jeab_pdo_host_limit() === 10, 'värdtak är 10');
peak_ok(jeab_calendar_db_peak() === 1, 'kalendern räknar en anslutning per sidladdning');
peak_ok(jeab_calendar_db_peak() < jeab_pdo_host_limit(), 'toppen ligger under taket');

$newPdo = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    if (preg_match('/\bnew\s+PDO\s*\(/', $text) === 1 || preg_match('/\bnew\s+mysqli\b/', $text) === 1 || str_contains($text, 'mysqli_connect')) {
        $newPdo[] = str_replace($root . '/', '', $file->getPathname());
    }
}
peak_ok($newPdo === [], 'src öppnar ingen egen PDO eller mysqli' . ($newPdo === [] ? '' : ': ' . implode(', ', $newPdo)));

$curlMiss = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        continue;
    }
    foreach ($lines as $i => $line) {
        if (!str_contains($line, 'curl_exec(') && !str_contains($line, "file_get_contents('https://")) {
            continue;
        }
        $window = implode("\n", array_slice($lines, max(0, $i - 12), 12));
        if (!str_contains($window, 'jeab_pdo_release_if_idle') && !str_contains($window, 'Anslutningen ligger kvar')) {
            $curlMiss[] = str_replace($root . '/', '', $file->getPathname()) . ':' . ($i + 1);
        }
    }
}
peak_ok($curlMiss === [], 'läsning släpper anslutningen och skrivning behåller den' . ($curlMiss === [] ? '' : ': ' . implode(', ', $curlMiss)));

$js = (string) file_get_contents($root . '/assets/js/app.js');
$start = strpos($js, 'function fillCalendarWaves');
$end = strpos($js, 'function slotAtInCol');
$wave = $start !== false && $end !== false && $end > $start ? substr($js, $start, $end - $start) : '';
$bookThen = strpos($wave, 'paintBookings.then');
$outlookFetch = strpos($wave, 'fetchWave(outlookUrl)');
peak_ok(str_contains($wave, 'new AbortController'), 'kalendern avbryter en pågående våg');
peak_ok($bookThen !== false && $outlookFetch !== false && $outlookFetch > $bookThen, 'outlook-vågen väntar på bokningsvågen');
peak_ok(substr_count($wave, 'fetchWave(outlookUrl)') === 2, 'outlook hämtas efter bokningarna och igen om veckan är gammal');

$during = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$during): array {
    unset($method, $body);
    $during[] = jeab_pdo_held_count();
    if (str_contains($url, '/events')) {
        return ['code' => 201, 'body' => ['id' => 'evt-peak']];
    }
    if (str_contains($url, 'calendarView')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }

    return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=peak']];
};

$store = jeab_store();
peak_ok($store instanceof JeabPdoStore, 'butiken är den delade PDO:n');
$GLOBALS['_JEAB_QUIET_BOOKING'] = true;
$row = jeab_create_booking($store, 'Nils Peak', ['Niman'], '2026-10-21 08:00:00', '2026-10-21 09:00:00', 'sol', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
]);
unset($GLOBALS['_JEAB_QUIET_BOOKING']);
$GLOBALS['_JEAB_PDO_HELD_PEAK'] = 0;
jeab_pdo_held_count();
$during = [];
$monday = new DateTimeImmutable('2026-10-19 00:00:00');
$events = jeab_gp_week_events($monday, null);
peak_ok($events === [], 'veckohämtningen hoppar över egna bokningar och tomma träffar');
peak_ok($during !== [] && max($during) === 0, 'bookings/outlook håller ingen anslutning under Graph, max ' . ($during === [] ? 'saknas' : (string) max($during)));
peak_ok(jeab_pdo_held_peak() === 1, 'bookings öppnar högst en anslutning, topp ' . jeab_pdo_held_peak());

$GLOBALS['_JEAB_PDO_HELD_PEAK'] = 0;
jeab_pdo_held_count();
$during = [];
jeab_gp_sync_booking($row);
peak_ok($during !== [] && max($during) === 1, 'synken behåller anslutningen under Graph-skrivningen, max ' . ($during === [] ? 'saknas' : (string) max($during)));
peak_ok(jeab_pdo_held_peak() === 1, 'synken öppnar högst en anslutning, topp ' . jeab_pdo_held_peak());

$GLOBALS['_JEAB_PDO_HELD_PEAK'] = 0;
jeab_pdo_held_count();
$during = [];
$pull = jeab_outlook_pull($store);
peak_ok(($pull['ok'] ?? false) === true, 'synkdragningen svarar');
peak_ok($during !== [] && max($during) === 0, 'dragningen håller ingen anslutning under Graph, max ' . ($during === [] ? 'saknas' : (string) max($during)));
peak_ok(jeab_pdo_held_peak() === 1, 'dragningen öppnar högst en anslutning, topp ' . jeab_pdo_held_peak());

$GLOBALS['_JEAB_PDO_HELD_PEAK'] = 0;
jeab_pdo_held_count();
$during = [];
$tick = jeab_tick_run();
peak_ok(($tick['ok'] ?? false) === true || ($tick['busy'] ?? false) === true, 'tick kör eller är upptagen');
$graphDuring = $during === [] ? 0 : max($during);
peak_ok($graphDuring <= 1, 'tick håller högst en anslutning under Graph, max ' . $graphDuring);
$tickSrc = (string) file_get_contents($root . '/src/tick.php');
$lockedAt = strpos($tickSrc, 'function jeab_tick_run_work_locked');
$locked = $lockedAt !== false ? substr($tickSrc, $lockedAt) : '';
$lockAt = strpos($locked, 'jeab_job_overlap_release');
$jobsAt = strpos($locked, "'guard' =>");
peak_ok($lockAt !== false && $jobsAt !== false && $lockAt < $jobsAt, 'tick släpper fillåset före de externa jobben');
peak_ok(jeab_pdo_held_peak() <= 1, 'tick öppnar högst en anslutning, topp ' . jeab_pdo_held_peak());

$db = (string) file_get_contents($root . '/src/db.php');
peak_ok(str_contains($db, 'function jeab_pdo_call') && str_contains($db, 'function jeab_pdo_open_wait'), '1203-retry sitter i anslutningen, inte i frågan');
peak_ok(str_contains($db, 'function jeab_pdo_open_once'), 'anslutningen öppnas med backoff');

exit($fail > 0 ? 1 : 0);
