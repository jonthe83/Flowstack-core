<?php

declare(strict_types=1);

/**
 * Semafor för bakgrund (taket minus 3), egna anslutningar, släpp av PDO.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-db-guard-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$fellog = $tmp . '/fellog';
mkdir($fellog, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $fellog;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function guard_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$pdo = jeab_pdo_connect_sqlite();
guard_ok($pdo instanceof PDO, 'sqlite öppnar');
guard_ok(jeab_settings_value($pdo, 'sqlite', 'db_busy_resolved_v1') === '1', 'migreringen sätter flaggan');
guard_ok((int) ($GLOBALS['_JEAB_PDO_RESOLVE_RUNS'] ?? 0) === 1, 'markeringen körs en gång');
jeab_pdo_resolve_known_busy($pdo, 'sqlite');
guard_ok((int) ($GLOBALS['_JEAB_PDO_RESOLVE_RUNS'] ?? 0) === 1, 'andra anropet ser flaggan');
$full = '7c5afc64' . str_repeat('ab', 28);
foreach (['7c5afc64', '74ae69c5', '42bacec3', 'dbbb737a', $full] as $fp) {
    guard_ok(jeab_log_app_error_resolved_has($fp), 'löst ' . substr($fp, 0, 8));
}

$stats = jeab_pdo_conn_stats($pdo);
$encoded = json_encode($stats, JSON_UNESCAPED_UNICODE);
guard_ok(is_string($encoded), 'conn_stats går att skriva');
guard_ok(($stats['ok'] ?? false) === true && ($stats['read_only'] ?? false) === true, 'conn_stats är torr');
guard_ok(array_key_exists('max_user_connections', $stats), 'conn_stats har max_user_connections');
guard_ok(array_key_exists('own_connections', $stats) && !array_key_exists('threads_connected', $stats), 'conn_stats räknar egna anslutningar');
guard_ok(($stats['db_busy_resolved_v1'] ?? false) === true, 'conn_stats visar db_busy_resolved_v1');
$sem = is_array($stats['semaphore'] ?? null) ? $stats['semaphore'] : [];
guard_ok(array_key_exists('own', $sem) && array_key_exists('ceiling', $sem) && array_key_exists('denied_background_hour', $sem), 'semaforstatus har egna, tak och nekade');
guard_ok(($stats['processlist'] ?? null) === [], 'sqlite har ingen processlista');
guard_ok(is_string($encoded) && !str_contains($encoded, '@') && !str_contains($encoded, 'Info') && !str_contains($encoded, 'password') && !str_contains($encoded, 'Threads_connected'), 'conn_stats utan hemligheter och utan serverglobal status');

jeab_pdo_release();
guard_ok(jeab_pdo_live_count() === 0, 'release tömmer poolen');
$fresh = jeab_store();
guard_ok($fresh instanceof JeabPdoStore, 'ny butik efter release');
$fresh->pdo();
guard_ok(jeab_pdo_live_count() === 1, 'en anslutning efter återöppning');
jeab_pdo_release();
guard_ok(jeab_pdo_live_count() === 0, 'andra releasen släpper PDO');

$GLOBALS['_JEAB_PDO_HOST_LIMIT'] = 5;
$held = [];
$locked = 0;
for ($i = 0; $i < 3; $i++) {
    $fh = jeab_pdo_bg_lock_once();
    if (is_resource($fh)) {
        $locked++;
        $held[] = $fh;
    }
}
guard_ok(jeab_pdo_bg_budget() === 2, 'budget är taket minus 3, fick ' . jeab_pdo_bg_budget());
guard_ok($locked === 2, 'semaforen släpper in två bakgrund, fick ' . $locked);
unset($GLOBALS['_JEAB_PDO_BACKGROUND']);
$whileHeld = jeab_pdo_connect_sqlite();
guard_ok($whileHeld instanceof PDO && jeab_pdo_live_count() === 1, 'interaktiv sida tar inte semaforplats');
jeab_pdo_release();
foreach ($held as $fh) {
    flock($fh, LOCK_UN);
    fclose($fh);
}
$again = jeab_pdo_bg_lock_once();
guard_ok(is_resource($again), 'platsen kommer tillbaka efter släpp');
if (is_resource($again)) {
    flock($again, LOCK_UN);
    fclose($again);
}

file_put_contents(jeab_db_slot_dir() . '/conn-sample.json', json_encode([
    'max_user_connections' => 25,
    'threads_connected' => 47,
    'at' => time(),
]));
unset($GLOBALS['_JEAB_PDO_HOST_LIMIT'], $GLOBALS['_JEAB_PDO_HOST_LIMIT_LIVE']);
guard_ok(jeab_pdo_host_limit() === 25, 'cachat tak är 25, inte reserven 10');
guard_ok(jeab_pdo_own_sample_read() === null, 'gammal serverglobal siffra räknas inte som våra anslutningar');
$GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
$legacyOpens = 0;
$GLOBALS['_JEAB_PDO_FACTORY'] = static function () use (&$legacyOpens): PDO {
    $legacyOpens++;

    return new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
};
$legacy = null;
try {
    $legacy = jeab_pdo_connect_sqlite();
} catch (JeabDbBusyException) {
    $legacy = null;
}
guard_ok($legacy instanceof PDO && $legacyOpens === 1, 'Threads_connected 47 stoppar inte bakgrund');
jeab_pdo_release();
unset($GLOBALS['_JEAB_PDO_FACTORY'], $GLOBALS['_JEAB_PDO_BACKGROUND']);

file_put_contents(jeab_db_slot_dir() . '/conn-sample.json', json_encode([
    'max_user_connections' => 25,
    'own_connections' => 22,
    'at' => time(),
]));
$GLOBALS['_JEAB_PDO_HOST_LIMIT'] = 25;
$GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
$opens = 0;
$GLOBALS['_JEAB_PDO_FACTORY'] = static function () use (&$opens): PDO {
    $opens++;

    return new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
};
$denied = false;
try {
    jeab_pdo_connect_sqlite();
} catch (JeabDbBusyException) {
    $denied = true;
}
guard_ok($denied && $opens === 0, 'egna anslutningar vid taket minus 3 öppnar ingen ny, öppningar ' . $opens);
guard_ok(jeab_pdo_bg_denied_count() >= 1, 'nekad bakgrund räknas senaste timmen');
unset($GLOBALS['_JEAB_PDO_FACTORY'], $GLOBALS['_JEAB_PDO_BACKGROUND']);
@unlink(jeab_db_slot_dir() . '/conn-sample.json');

$_GET = ['p' => 'bookings', 'intent' => 'calendar-bookings', 'job' => 'laddbox'];
guard_ok(!jeab_pdo_is_background(), 'kalendervågen är interaktiv');
$_GET['intent'] = 'sync';
guard_ok(jeab_pdo_is_background(), 'påminnelse-poll är bakgrund');
$_GET = ['p' => 'inkorg', 'ix_part' => 'counts'];
guard_ok(jeab_pdo_is_background(), 'oläst-räknaren är bakgrund');
$GLOBALS['_JEAB_PDO_FORCE_INTERACTIVE'] = true;
$GLOBALS['_JEAB_TICK'] = true;
guard_ok(!jeab_pdo_is_background(), 'tvingad interaktiv vinner över tick');
unset($GLOBALS['_JEAB_PDO_FORCE_INTERACTIVE'], $GLOBALS['_JEAB_TICK']);
$_GET = [];

$GLOBALS['_JEAB_PDO_CONN_STATS'] = [
    'own_connections' => 9,
    'max_user_connections' => 25,
    'page' => 'bookings',
    'intent' => 'calendar-bookings',
    'job' => 'laddbox',
    'user' => 'Elin',
];
$log = jeab_app_errors_path();
$before = is_file($log) ? (string) file_get_contents($log) : '';
$GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
unset($GLOBALS['_JEAB_PDO_1203_LOGGED']);
jeab_pdo_log_1203();
$mid = is_file($log) ? (string) file_get_contents($log) : '';
guard_ok($mid === $before, 'bakgrund skriver inte 1203 i felloggen');
unset($GLOBALS['_JEAB_PDO_BACKGROUND'], $GLOBALS['_JEAB_PDO_1203_LOGGED']);
jeab_pdo_log_1203();
$after = is_file($log) ? (string) file_get_contents($log) : '';
guard_ok(str_contains($after, '1203 own=9 max=25 page=bookings intent=calendar-bookings job=laddbox user=Elin'), 'interaktiv 1203 loggar egna anslutningar, sida och användare');
guard_ok(!str_contains($after, '@'), 'felloggen har ingen mejladress');

$db = (string) file_get_contents($root . '/src/db.php');
$index = (string) file_get_contents($root . '/index.php');
$hook = (string) file_get_contents($root . '/hook.php');
$layout = (string) file_get_contents($root . '/src/layout.php');
guard_ok(str_contains($db, "header('Retry-After: 3')"), 'bakgrund svarar med Retry-After');
guard_ok(!str_contains($db, 'Threads_connected'), 'semaforen läser inte Threads_connected');
guard_ok(substr_count($db, 'SUBSTRING_INDEX(CURRENT_USER()') === 2, 'egna rader räknas i PROCESSLIST');
guard_ok(str_contains($index, 'jeab_pdo_busy_response'), 'sidan kastar inte 1203 till felloggen');
guard_ok(str_contains($hook, "'db.conn_stats'"), 'torr hook db.conn_stats');
guard_ok(str_contains($layout, 'assets/js/poll-lock.js'), 'fliklåset laddas');
guard_ok(str_contains($db, 'jeab_pdo_release'), 'PDO släpps');

exit($fail > 0 ? 1 : 0);
