<?php

declare(strict_types=1);

/**
 * Sidfart: bootstrap-spår, en MySQL-anslutning, Ordrar läser inte payload på GET,
 * Regler/Vyer räknar i inkorg_list.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fart-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_BOOT_T0'] = microtime(true) - 0.05;
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
function fart_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$boot = (string) file_get_contents($root . '/src/bootstrap.php');
fart_ok(str_contains($boot, "jeab_timing_span('boot'") && str_contains($boot, "jeab_timing_span('opcache'"), 'bootstrap och opcache är egna spår');
fart_ok(!str_contains($boot, 'phpinfo('), 'opcache läses inte via phpinfo');
$timing = $GLOBALS['_JEAB_TIMING'] ?? [];
fart_ok(is_array($timing) && isset($timing['boot']) && (float) $timing['boot'] >= 1, 'boot-spåret fångar tiden före timing_begin, fick ' . var_export($timing['boot'] ?? null, true));
fart_ok(is_array($timing) && isset($timing['opcache']) && in_array((float) $timing['opcache'], [0.0, 1.0], true), 'opcache-spåret är 0 eller 1');

fart_ok(jeab_pdo_is_connect_refused(new PDOException('SQLSTATE[HY000] [2002] Connection refused')), '2002 är en nekad anslutning');
$GLOBALS['_JEAB_PDO_CONNECT_REFUSED'] = 1;
$busy = false;
try {
    jeab_pdo_connect_mysql();
} catch (JeabDbBusyException) {
    $busy = true;
} catch (Throwable $e) {
    $busy = false;
    echo 'FAIL 2002 kastade ' . $e::class . "\n";
    $fail++;
}
fart_ok($busy, 'en nekad anslutning öppnar inte en till');
unset($GLOBALS['_JEAB_PDO_CONNECT_REFUSED']);

$ordrarPage = (string) file_get_contents($root . '/src/pages/ordrar.php');
fart_ok(str_contains($ordrarPage, "jeab_timing_span('ordrar_php'") && str_contains($ordrarPage, "jeab_timing_span('ordrar_html'"), 'ordrar-spåret har php och html inuti');
$db = (string) file_get_contents($root . '/src/db.php');
fart_ok(str_contains($db, 'jeab_pdo_is_connect_refused') && str_contains($db, "ATTR_DRIVER_NAME) === 'mysql'"), 'en MySQL-anslutning delas och 2002 öppnar inte en till');

$shell = (string) file_get_contents($root . '/assets/js/shell.js');
fart_ok(str_contains($shell, 'QUIET_MS = 1500') && str_contains($shell, 'saveData') && str_contains($shell, 'PREFETCH_MAX = 4') && str_contains($shell, "IDLE_PAGES = ['bookings', 'ordrar', 'offert', 'utskick']"), 'förhämtning väntar 1,5 s, tar kalender och ordrar först och högst fyra flikar');
$ht = (string) file_get_contents($root . '/.htaccess');
fart_ok(str_contains($ht, 'FileETag MTime Size') && str_contains($ht, 'immutable'), 'assets får ETag och lång cache');
$nginx = (string) file_get_contents($root . '/.github/loopia-nginx.conf');
fart_ok(str_contains($nginx, 'etag on;') && str_contains($nginx, 'immutable'), 'nginx-snippet sätter etag och cache');

exit($fail > 0 ? 1 : 0);
