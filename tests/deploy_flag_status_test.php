<?php

declare(strict_types=1);

/**
 * deploy.flag_status: tokenskyddad, svarar bara exists och mtime. Påhittad token i temp-katalog.
 */

$tmp = sys_get_temp_dir() . '/jeab-deploy-flag-status-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-flag-status-test']));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/deploy_guard.php';

function dfs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_deploy_flag_status_dispatch(['action' => 'deploy.flag_status']);
dfs(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401 && !array_key_exists('exists', $denied), 'utan token: 401 och inget svar om flaggan');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fel-token';
dfs((int) (jeab_deploy_flag_status_dispatch([])['status'] ?? 0) === 401, 'fel token: 401');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-flag-status-test';
dfs((int) (jeab_deploy_flag_status_dispatch([])['status'] ?? 0) === 401, 'GET nekas även med rätt token');
$_SERVER['REQUEST_METHOD'] = 'POST';

$ok = jeab_deploy_flag_status_dispatch([]);
dfs(!empty($ok['ok']) && (int) $ok['status'] === 200, 'rätt token: 200');
dfs(array_diff(array_keys($ok), ['ok', 'exists', 'mtime', 'mtime_iso', 'status']) === [], 'svaret har bara exists och mtime');

$flag = $tmp . '/deploying.flag';
$none = jeab_deploy_flag_status($flag);
dfs($none['exists'] === false && $none['mtime'] === null, 'saknad flagga: exists false, mtime null');
file_put_contents($flag, '1800000000 hemligt-innehall');
touch($flag, 1700000123);
$has = jeab_deploy_flag_status($flag);
dfs($has['exists'] === true && $has['mtime'] === 1700000123 && $has['mtime_iso'] === gmdate('c', 1700000123), 'flagga finns: exists och mtime');
dfs(!str_contains((string) json_encode($has), 'hemligt'), 'innehållet läcker inte');
dfs(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), "'deploy.flag_status'"), 'hook.php tar emot deploy.flag_status');

echo "deploy flag status: ok\n";
