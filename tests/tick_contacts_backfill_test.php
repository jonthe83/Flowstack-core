<?php

declare(strict_types=1);

/**
 * N-13: fel i contacts_list_backfill loggas utan kunddata, nästa tick försöker igen.
 * Påhittade namn. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-tick-backfill-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$log = $tmp . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $log);
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

function tcb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function tcb_log(): string
{
    $path = ini_get('error_log');
    return is_string($path) && is_file($path) ? (string) file_get_contents($path) : '';
}

$src = (string) file_get_contents($root . '/src/tick.php');
$at = strpos($src, 'function jeab_tick_maintenance');
// Fönstret måste räcka när kalender- och inköpssteg staplas framför markören (~1966).
$fn = $at === false ? '' : substr($src, $at, 4000);
$start = strpos($fn, "function_exists('jeab_contacts_list_backfill')");
$end = strpos($fn, "function_exists('jeab_purchase_sql_pdo')");
$block = ($start !== false && $end !== false && $end > $start) ? substr($fn, $start, $end - $start) : '';
tcb($block !== '', 'backfill-blocket finns i tick-underhållet');
tcb(str_contains($block, 'catch (Throwable $e)'), 'felet fångas med $e');
tcb(str_contains($block, 'error_log('), 'felet loggas med error_log');
tcb(str_contains($block, '$e::class'), 'loggen tar bara undantagsklassen');
tcb(!str_contains($block, 'getMessage'), 'loggen tar inte getMessage');
tcb(!str_contains($block, 'throw '), 'felet kastas inte vidare, så nästa tick kan försöka');

final class TickBackfillPdo extends PDO
{
    public int $execs = 0;

    public function exec(?string $statement): int|false
    {
        unset($statement);
        $this->execs++;
        throw new RuntimeException('Ada Testsson backfill-fel');
    }
}

$secret = 'Ada Testsson backfill-fel';
$pdo = new TickBackfillPdo('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$_SERVER['REQUEST_TIME_FLOAT'] = 1.0;
$threw = false;
try {
    jeab_tick_maintenance($pdo);
} catch (Throwable) {
    $threw = true;
}
tcb(!$threw, 'underhållet sväljer felet så resten av ticken körs');
tcb($pdo->execs > 0, 'backfill nådde SQL');
$first = tcb_log();
tcb(str_contains($first, 'tick contacts_list_backfill RuntimeException'), 'första felet loggas med klassen');
tcb(!str_contains($first, $secret), 'loggen har inte kundnamnet');
tcb(!str_contains($first, '@'), 'loggen har ingen e-post');

$before = $pdo->execs;
file_put_contents($log, '');
$_SERVER['REQUEST_TIME_FLOAT'] = 2.0;
jeab_tick_maintenance($pdo);
$again = tcb_log();
tcb($pdo->execs > $before, 'nästa tick försöker listbackfill igen');
tcb(str_contains($again, 'tick contacts_list_backfill RuntimeException'), 'andra felet loggas också');
tcb(!str_contains($again, $secret), 'andra loggen har inte kundnamnet');

echo "tick contacts backfill ok\n";
