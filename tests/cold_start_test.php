<?php

declare(strict_types=1);

/**
 * Kallstart: inloggning utan kaka laddar inte den tunga listan, sidans
 * MySQL-anslutning har ett tak, schemat körs inte på klicket, och sessionslåset
 * släpps innan butiken öppnas.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-cold-start-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function cold_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$prevSapi = $GLOBALS['_JEAB_SAPI'] ?? null;
$prevLazy = $GLOBALS['_JEAB_BOOT_LAZY'] ?? null;
$prevBootAll = $GLOBALS['_JEAB_BOOT_ALL'] ?? null;
$prevTick = $GLOBALS['_JEAB_TICK'] ?? null;
$prevCookie = $_COOKIE['jeab_auth'] ?? null;
$prevInteractive = $GLOBALS['_JEAB_PDO_INTERACTIVE'] ?? null;
$prevScript = $_SERVER['SCRIPT_FILENAME'] ?? null;

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_BOOT_LAZY'] = true;
unset($GLOBALS['_JEAB_BOOT_ALL'], $GLOBALS['_JEAB_TICK'], $_COOKIE['jeab_auth']);
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
cold_ok(jeab_bootstrap_skip_heavy_until_login(), 'utan kaka laddas inte Ordrar och Inkorg');
$_COOKIE['jeab_auth'] = 'sparad-kaka';
cold_ok(!jeab_bootstrap_skip_heavy_until_login(), 'med kaka laddas sidan');
unset($_COOKIE['jeab_auth']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
cold_ok(!jeab_bootstrap_skip_heavy_until_login(), 'CLI laddar hela listan');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_PDO_INTERACTIVE']);
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
cold_ok(jeab_pdo_connect_timeout() === 1, 'sidans anslutning väntar högst 1 sekund, fick ' . var_export(jeab_pdo_connect_timeout(), true));
$GLOBALS['_JEAB_PDO_INTERACTIVE'] = true;
cold_ok(jeab_pdo_connect_timeout() === 1, 'interaktiv header väntar 1 sekund');
unset($GLOBALS['_JEAB_PDO_INTERACTIVE']);
$GLOBALS['_JEAB_TICK'] = true;
cold_ok(jeab_pdo_connect_timeout() === null, 'tick behåller drivrutinens tak');
unset($GLOBALS['_JEAB_TICK']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
cold_ok(jeab_pdo_connect_timeout() === null, 'CLI har inget tak');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_DATA_MIGRATE'], $GLOBALS['_JEAB_SCHEMA_DDL']);
cold_ok(!jeab_pdo_schema_on_request(), 'ett klick kör inte schemat');
$GLOBALS['_JEAB_TICK'] = true;
cold_ok(jeab_pdo_schema_on_request(), 'tick kör schemat');
unset($GLOBALS['_JEAB_TICK']);
$_SERVER['SCRIPT_FILENAME'] = $root . '/tick.php';
cold_ok(jeab_pdo_schema_on_request(), 'tick.php kör schemat även innan flaggan är satt');
$GLOBALS['_JEAB_SAPI'] = 'cli';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
cold_ok(jeab_pdo_schema_on_request(), 'CLI kör schemat');

$_GET = [];
$_POST = [];
cold_ok(jeab_timing_page_slug() === 'reminders', 'GET / loggas som reminders, inte okand');
$_POST['action'] = 'login';
cold_ok(jeab_timing_page_slug() === 'login', 'inloggnings-POST loggas som login');
$_POST = [];
cold_ok(jeab_timing_page_slug('Kontakter<script>') === 'okand', 'ogiltig slug är fortfarande okand');

$index = (string) file_get_contents($root . '/index.php');
$releaseAt = strpos($index, 'jeab_session_release_before_store()');
$storeAt = strpos($index, '$store = jeab_boot_store();');
cold_ok($releaseAt !== false && $storeAt !== false && $releaseAt < $storeAt, 'sessionen släpps innan butiken öppnas');

$db = (string) file_get_contents($root . '/src/db.php');
$noteAt = strpos($db, 'function jeab_pdo_note_connect');
$noteEnd = strpos($db, 'function jeab_pdo_sample_remembered');
$note = ($noteAt !== false && $noteEnd !== false) ? substr($db, $noteAt, $noteEnd - $noteAt) : '';
cold_ok($note !== '' && str_contains($note, 'jeab_pdo_sample_remembered') && !str_contains($note, 'PROCESSLIST'), 'anslutningen läser inte PROCESSLIST');
cold_ok(str_contains($db, 'jeab_pdo_connect_timeout()'), 'MySQL-anslutningen använder taket');
cold_ok(str_contains($db, 'jeab_pdo_schema_on_request()'), 'schemat styrs av jeab_pdo_schema_on_request');

$boot = (string) file_get_contents($root . '/src/bootstrap.php');
cold_ok(str_contains($boot, 'jeab_bootstrap_skip_heavy_until_login()'), 'boot hoppar över den tunga listan utan kaka');
cold_ok(str_contains($boot, '_JEAB_AUTH_EPOCH_DEFER'), 'första requesten efter session väntar med epoch tills butiken är öppen');
$auth = (string) file_get_contents($root . '/src/auth.php');
cold_ok(str_contains($auth, 'jeab_auth_recheck_after_store'), 'epoch kollas när butiken finns');
cold_ok(str_contains($index, 'jeab_auth_recheck_or_login'), 'första sidträffen kollar epoch efter butiken');
$GLOBALS['_JEAB_AUTH_EPOCH_DEFER'] = true;
unset($GLOBALS['_JEAB_AUTH_EPOCH_CACHE']);
cold_ok(jeab_auth_epoch() === 0 && !isset($GLOBALS['_JEAB_AUTH_EPOCH_CACHE']), 'uppskjuten epoch cachas inte som noll');
unset($GLOBALS['_JEAB_AUTH_EPOCH_DEFER']);

if ($prevSapi === null) {
    unset($GLOBALS['_JEAB_SAPI']);
} else {
    $GLOBALS['_JEAB_SAPI'] = $prevSapi;
}
if ($prevLazy === null) {
    unset($GLOBALS['_JEAB_BOOT_LAZY']);
} else {
    $GLOBALS['_JEAB_BOOT_LAZY'] = $prevLazy;
}
if ($prevBootAll === null) {
    unset($GLOBALS['_JEAB_BOOT_ALL']);
} else {
    $GLOBALS['_JEAB_BOOT_ALL'] = $prevBootAll;
}
if ($prevTick === null) {
    unset($GLOBALS['_JEAB_TICK']);
} else {
    $GLOBALS['_JEAB_TICK'] = $prevTick;
}
if ($prevCookie === null) {
    unset($_COOKIE['jeab_auth']);
} else {
    $_COOKIE['jeab_auth'] = $prevCookie;
}
if ($prevInteractive === null) {
    unset($GLOBALS['_JEAB_PDO_INTERACTIVE']);
} else {
    $GLOBALS['_JEAB_PDO_INTERACTIVE'] = $prevInteractive;
}
if ($prevScript === null) {
    unset($_SERVER['SCRIPT_FILENAME']);
} else {
    $_SERVER['SCRIPT_FILENAME'] = $prevScript;
}

exit($fail > 0 ? 1 : 0);
