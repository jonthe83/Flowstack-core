<?php

declare(strict_types=1);

/**
 * Felloggens långsamma rad dedupas och tar inte med söktext.
 */

$tmp = sys_get_temp_dir() . '/jeab-sec-slow-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_SLOW_WINDOW'] = 600;
$GLOBALS['_JEAB_NOW'] = 1_700_000_000;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function secslow(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$_SERVER['REQUEST_URI'] = '/index.php?p=ordrar&q=Kund+Hemlig&token=sekret';
$_POST = ['action' => 'ordrar.save', 'q' => 'Kund Hemlig'];
jeab_log_slow_request('total', 'ordrar', 2200, '');
jeab_log_slow_request('nope', 'ordrar', 9999, '');
jeab_log_slow_request('query', 'ordrar', 800, "SELECT purchases ada@example.test");
$raw = (string) file_get_contents(jeab_app_errors_path());
secslow(substr_count($raw, "\n") === 2 || count(array_filter(explode("\n", trim($raw)))) === 2, 'okänd sort skrivs inte');
secslow(str_contains($raw, 'långsam request 2200 ms p=ordrar'), 'request-raden har sidslug');
secslow(str_contains($raw, 'SELECT purchases'), 'frågeformen är kvar');
secslow(!str_contains($raw, 'ada@example.test') && !str_contains($raw, 'ada'), 'mejlet skalas bort ur formen');
secslow(!str_contains($raw, 'Hemlig') && !str_contains($raw, 'token='), 'söktext och token följer inte med');
secslow(str_contains($raw, '"level":"info"'), 'nivån är info');
secslow(str_contains($raw, '"path":"/index.php?p=ordrar"'), 'sökvägen är bara sidan');

$GLOBALS['_JEAB_NOW'] = 1_700_000_050;
jeab_log_slow_request('total', 'ordrar', 2400, '');
$lines = array_values(array_filter(explode("\n", trim((string) file_get_contents(jeab_app_errors_path())))));
secslow(count($lines) === 2, 'samma request inom fönstret höjer räknaren');
$first = json_decode($lines[0], true);
secslow(is_array($first) && (int) ($first['count'] ?? 0) === 2, 'räknaren är 2');
secslow(str_contains((string) ($first['message'] ?? ''), '2400 ms'), 'meddelandet visar senaste tiden');

echo "security slow log ok\n";
