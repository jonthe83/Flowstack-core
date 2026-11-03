<?php

declare(strict_types=1);

/**
 * migrate.backup från tick kräver tick-flaggan och skriver mode=run.
 */

$tmp = sys_get_temp_dir() . '/jeab-backup-tick-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/migrate/backup.php';

function bkt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

unset($GLOBALS['_JEAB_TICK']);
$blocked = jeab_migrate_backup_from_tick();
bkt(empty($blocked['ok']) && empty($blocked['written']), 'sidvisning och CLI utan tick skriver inte');
bkt(($blocked['error'] ?? '') === 'Inte tick.', 'avvisningen säger att det inte är tick');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/migrate/backup.php');
$at = strpos($src, 'function jeab_migrate_backup_from_tick');
$end = strpos($src, "\nfunction ", $at + 10);
$body = substr($src, (int) $at, ($end === false ? 0 : $end) - (int) $at);
bkt(str_contains($body, "['mode' => 'run']"), 'tick ber om mode=run');
bkt(str_contains($body, "empty(\$GLOBALS['_JEAB_TICK'])"), 'funktionen kräver tick');
$tick = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
bkt(str_contains($tick, 'jeab_migrate_backup_from_tick'), 'tick anropar backup-ingången');
bkt(str_contains($tick, "format('G') !== 3"), 'fönstret är klockan 03');

echo "backup tick ok\n";
