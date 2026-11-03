<?php

declare(strict_types=1);

/**
 * Nattlig migrate.backup från tick, klockan 03, inte i samma varv som mejl.
 * CLI gör det bara när testet ber om det. Påhittad katalog.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-tick-backup-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/store.json', '{"customers":[],"bookings":[],"reminders":[],"reminder_lists":[]}');

require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';

function tb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$zone = new DateTimeZone('Europe/Stockholm');
$night = (new DateTimeImmutable('2026-01-15 03:10:00', $zone))->getTimestamp();
$day = (new DateTimeImmutable('2026-01-15 12:10:00', $zone))->getTimestamp();

$guard = 0;
$GLOBALS['_JEAB_TICK_GUARD'] = static function () use (&$guard): array {
    $guard++;

    return ['ok' => true, 'count' => 0];
};
$GLOBALS['_JEAB_TICK_INKORG'] = static fn (): array => ['ok' => true, 'count' => 0];
$GLOBALS['_JEAB_TICK_MALMO'] = static fn (): array => ['ok' => true, 'count' => 0];

$index = (string) file_get_contents($root . '/index.php');
tb(!str_contains($index, 'jeab_migrate_backup'), 'index.php startar inte backupen');
$tickSrc = (string) file_get_contents($root . '/src/tick.php');
tb(str_contains($tickSrc, 'jeab_tick_nightly_backup') && str_contains($tickSrc, 'if ($night === null)'), 'backup-varvet hoppar över mejlplanen');
tb(str_contains((string) file_get_contents($root . '/src/migrate/backup.php'), 'function jeab_migrate_backup_from_tick'), 'backup.php har tick-ingången');

unset($GLOBALS['_JEAB_TICK']);
tb(jeab_migrate_backup_from_tick()['ok'] === false, 'utan tick skrivs ingen backup');

$GLOBALS['_JEAB_BACKUP_NOW'] = $night;
unset($GLOBALS['_JEAB_TICK_BACKUP_ALLOW'], $GLOBALS['_JEAB_TICK_BACKUP_FORCE']);
$silent = jeab_tick_run();
tb(!isset($silent['jobs']['backup']), 'CLI utan tillstånd hoppar över backupen mitt i natten');
tb($guard === 1, 'CLI kör mejlplanen, fick guard ' . $guard);

$GLOBALS['_JEAB_TICK_BACKUP_ALLOW'] = true;
$first = jeab_tick_run();
tb(isset($first['jobs']['backup']), 'klockan 03 finns backup-jobbet');
tb($guard === 1, 'backup-varvet anropar inte mejlplanen, fick guard ' . $guard);
tb(!isset($first['jobs']['guard']) && !isset($first['jobs']['inkorg']) && !isset($first['jobs']['wix']), 'backup-varvet har inga andra jobb');
$pdo = jeab_store()->pdo();
tb(jeab_settings_value($pdo, 'sqlite', 'migrate_backup_night') === '2026-01-15', 'natten stämplas');
$log = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
if (empty($first['jobs']['backup']['ok'])) {
    tb(str_contains($log, 'nattbackup misslyckades'), 'ett misslyckande syns i felloggen');
}
tb(!str_contains($log, 'personnummer') && !str_contains($log, '@'), 'felloggen har ingen kundtext');

$second = jeab_tick_run();
tb(!isset($second['jobs']['backup']), 'samma natt körs backupen inte igen');
tb($guard === 2, 'nästa tick kör mejlplanen');

$GLOBALS['_JEAB_BACKUP_NOW'] = $day;
$pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute(['migrate_backup_night']);
jeab_settings_cache_forget($pdo, 'sqlite', 'migrate_backup_night');
$noon = jeab_tick_run();
tb(!isset($noon['jobs']['backup']), 'mitt på dagen blir det ingen backup');
tb($guard === 3, 'dagen kör mejlplanen');

echo "tick nightly backup ok\n";
