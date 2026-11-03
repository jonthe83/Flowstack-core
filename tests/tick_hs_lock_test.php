<?php

declare(strict_types=1);

/**
 * P2-32: HS-batchen tar MySQL GET_LOCK/RELEASE_LOCK. SQLite faller tillbaka
 * på settings-hyra så två tick inte behandlar samma batch.
 */

$root = dirname(__DIR__);
$tickSrc = (string) file_get_contents($root . '/src/tick.php');

function ths(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

ths(str_contains($tickSrc, "GET_LOCK('jeab_tick_hs'"), 'HS-batchen tar GET_LOCK jeab_tick_hs på mysql');
ths(str_contains($tickSrc, "RELEASE_LOCK('jeab_tick_hs')"), 'HS-batchen släpper GET_LOCK');
ths(str_contains($tickSrc, '_JEAB_TICK_HS_LOCK'), 'mysql-låset märks så samma anslutning hålls');
ths(str_contains($tickSrc, 'function jeab_tick_malmo_live') && str_contains($tickSrc, 'jeab_tick_hs_lock'), 'GET_LOCK ligger runt HS-batchen');
ths(str_contains($tickSrc, 'jeab_tick_lease') && str_contains($tickSrc, 'ON CONFLICT'), 'sqlite hyr fortfarande settings-raden');
ths(!str_contains($tickSrc, 'jeab_db_get_lock'), 'tick använder inte jeab_db_get_lock');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-tick-hs-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($tmp . '/public', 0700, true);
$GLOBALS['_JEAB_TICK_TOKEN_FILE'] = $tmp . '/jeab_tick_token';
file_put_contents($GLOBALS['_JEAB_TICK_TOKEN_FILE'], bin2hex(random_bytes(8)));
$GLOBALS['_JEAB_SHIELD_DONE'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';

$pdo = jeab_tick_pdo();
ths($pdo instanceof PDO, 'sqlite-hyra har en anslutning');
ths(jeab_tick_lease_driver($pdo) !== 'mysql', 'testet kör sqlite-fallback');
ths(jeab_tick_hs_lock($pdo) === true, 'sqlite HS-lås släpper igenom (hyra räcker)');
ths(jeab_tick_lock_acquire($pdo), 'första hyran tas');
ths(!jeab_tick_lock_acquire($pdo), 'andra tick tar inte samma batch');
jeab_tick_lock_release($pdo);
ths(jeab_tick_lock_acquire($pdo), 'släppt hyra kan tas igen');
jeab_tick_lock_release($pdo);

echo "OK  tick_hs_lock\n";
