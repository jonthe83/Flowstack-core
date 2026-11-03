<?php

declare(strict_types=1);

/**
 * Underhållsflaggan svarar 503 i högst tre minuter. Tom fil är av.
 * log_errors lämnas på, så varningar stannar i error_log.
 * Maskerad fatal skrivs bara när PHP inte redan loggat den.
 * private/.htaccess nekar webbåtkomst.
 */

$root = dirname(__DIR__);
require_once $root . '/src/deploy_guard.php';

$fail = 0;

function dg(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

$now = 1_800_000_000;
$dir = sys_get_temp_dir() . '/jeab-deploy-guard-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$flag = $dir . '/deploying.flag';

dg(jeab_deploy_flag_is_fresh($flag, $now) === false, 'saknad flagga är av');
file_put_contents($flag, '');
dg(jeab_deploy_flag_is_fresh($flag, $now) === false, 'tom flagga är av');
file_put_contents($flag, " \n");
dg(jeab_deploy_flag_is_fresh($flag, $now) === false, 'blank flagga är av');
file_put_contents($flag, (string) $now);
dg(jeab_deploy_flag_is_fresh($flag, $now) === true, 'färsk tidsstämpel är på');
dg(jeab_deploy_flag_is_fresh($flag, $now + 179) === true, 'två minuter och 59 sekunder är på');
dg(jeab_deploy_flag_is_fresh($flag, $now + 180) === false, 'tre minuter är av');
file_put_contents($flag, (string) ($now + 31));
dg(jeab_deploy_flag_is_fresh($flag, $now) === false, 'långt framtid är av');
file_put_contents($flag, '17');
dg(jeab_deploy_flag_is_fresh($flag, $now) === false, 'skräp utan unix-tid är av');

$webRoot = $root;
$live = $webRoot . '/private';
if (!is_dir($live)) {
    mkdir($live, 0700, true);
}
$liveFlag = $live . '/deploying.flag';
$previous = is_file($liveFlag) ? (string) file_get_contents($liveFlag) : null;
$existed = is_file($liveFlag);

$secret = 'SuperSecretTokenValue1234567890';
$log = $dir . '/php-error.log';
$iniOut = $dir . '/log-errors.txt';
$worker = $dir . '/fatal.php';
file_put_contents($worker, <<<'PHP'
<?php
declare(strict_types=1);
ini_set('log_errors', getenv('JEAB_LOG_ERRORS') ?: '0');
ini_set('display_errors', '0');
ini_set('error_log', getenv('JEAB_FATAL_LOG') ?: '');
require getenv('JEAB_GUARD');
jeab_deploy_guard();
file_put_contents(getenv('JEAB_INI_OUT') ?: '', (string) ini_get('log_errors'));
trigger_error('guard-warning-marker', E_USER_WARNING);
trigger_error('password=' . getenv('JEAB_FATAL_SECRET') . ' Bearer abcdefghijklmnopqrstuvwxyz012345', E_USER_ERROR);
PHP);
$baseEnv = [
    'JEAB_FATAL_LOG' => $log,
    'JEAB_GUARD' => $root . '/src/deploy_guard.php',
    'JEAB_FATAL_SECRET' => $secret,
    'JEAB_INI_OUT' => $iniOut,
];
foreach ($_SERVER as $key => $value) {
    if (is_string($key) && is_string($value) && !array_key_exists($key, $baseEnv) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
        $baseEnv[$key] = $value;
    }
}
$ranOn = dg_fatal($worker, $dir, $baseEnv + ['JEAB_LOG_ERRORS' => '1'], $log, $iniOut);
dg($ranOn['code'] !== 0, 'fatal avslutar processen');
dg($ranOn['ini'] === '1', 'guarden lämnar log_errors på, fick ' . json_encode($ranOn['ini']));
dg(str_contains($ranOn['log'], 'guard-warning-marker'), 'varning skrivs till error_log, fick ' . json_encode($ranOn['log']));
dg(substr_count($ranOn['log'], 'Fatal error') === 1, 'PHP loggar fatalen en gång');
dg(!str_contains($ranOn['log'], 'PHP fatal:'), 'ingen andra fatalrad när log_errors redan fångat den');
$ranOff = dg_fatal($worker, $dir, $baseEnv + ['JEAB_LOG_ERRORS' => '0'], $log, $iniOut);
dg($ranOff['ini'] === '0', 'guarden sätter inte på log_errors, fick ' . json_encode($ranOff['ini']));
dg(str_contains($ranOff['log'], 'PHP fatal:'), 'maskerad fatal skrivs när log_errors är av, fick ' . json_encode($ranOff['log']));
dg(substr_count($ranOff['log'], 'PHP fatal:') === 1, 'en maskerad fatalrad');
dg(!str_contains($ranOff['log'], $secret) && !str_contains($ranOff['out'], $secret), 'hemligheten kommer inte med');
dg(str_contains($ranOff['log'], '[secret]'), 'lösenord och bearer maskeras');
$htaccess = (string) file_get_contents($root . '/private/.htaccess');
dg(str_contains($htaccess, 'Require all denied') && str_contains($htaccess, 'Deny from all'), 'private/.htaccess nekar med båda syntaxerna');
dg(str_contains($htaccess, 'Order deny,allow'), 'private/.htaccess sätter Order deny,allow');
dg(str_contains($htaccess, 'Options -Indexes'), 'private/.htaccess stänger av index');
dg(preg_match('/RewriteRule\s+\^\s+-\s+\[F,L\]/', $htaccess) === 1, 'private/.htaccess förbjuder via rewrite');
$backupHt = (string) file_get_contents($root . '/private/backups/.htaccess');
dg($backupHt === $htaccess, 'private/backups/.htaccess är samma spärr');
$guardSrc = (string) file_get_contents($root . '/src/deploy_guard.php');
dg(!str_contains($guardSrc, "ini_set('log_errors'"), 'guarden rör inte log_errors');

file_put_contents($liveFlag, (string) time());
$probe = stream_socket_server('tcp://127.0.0.1:0');
$probeName = is_resource($probe) ? (string) stream_socket_get_name($probe, false) : '';
if (is_resource($probe)) {
    fclose($probe);
}
$port = 0;
if (preg_match('/:(\d+)$/', $probeName, $match) === 1) {
    $port = (int) $match[1];
}
$serverErr = $dir . '/server.err';
$server = $port > 0 ? proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $webRoot],
    [2 => ['file', $serverErr, 'w']],
    $serverPipes,
    $webRoot
) : false;
$ready = false;
$deadline = microtime(true) + 3;
while (is_resource($server) && microtime(true) < $deadline) {
    $try = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($try)) {
        fclose($try);
        $ready = true;
        break;
    }
    usleep(50000);
}
dg($ready, 'inbyggd server startade');
if ($ready) {
    $headers = dg_http($port, '/hook.php', 'POST', '{}');
    dg(($headers['code'] ?? 0) === 503, 'hook svarar 503 under flaggan, fick ' . json_encode($headers));
    dg(str_contains($headers['raw'] ?? '', 'Retry-After: 30'), 'Retry-After: 30');
    dg(str_contains($headers['body'] ?? '', '"ok":false'), 'hook-svaret är kort JSON');
    $index = dg_http($port, '/index.php', 'GET', '');
    dg(($index['code'] ?? 0) === 503, 'index svarar 503');
    dg(str_contains($index['body'] ?? '', 'Underhåll'), 'index svarar kort text');
    dg(!str_contains($index['body'] ?? '', 'Ange kampanjkod'), 'inloggningen visas inte under flaggan');
    $omtago = dg_http($port, '/api/omtago-lead.php', 'POST', '{}');
    dg(($omtago['code'] ?? 0) === 503 && str_contains($omtago['body'] ?? '', '"ok":false'), 'omtago svarar 503 JSON');
    $kalkyl = dg_http($port, '/index.php?p=offert&kalkyl=1', 'GET', '');
    dg(($kalkyl['code'] ?? 0) === 503 && str_contains($kalkyl['raw'] ?? '', 'application/json') && str_contains($kalkyl['body'] ?? '', '"ok":false'), 'kalkyl-anrop svarar 503 JSON');
}
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
if ($existed) {
    file_put_contents($liveFlag, (string) $previous);
} elseif (is_file($liveFlag)) {
    unlink($liveFlag);
}
if (is_dir($live) && count(array_diff(scandir($live) ?: [], ['.', '..'])) === 0) {
    rmdir($live);
}

echo $fail === 0 ? "deploy guard: ok\n" : "{$fail} fel\n";
exit($fail > 0 ? 1 : 0);

/** @param array<string, string> $env
 *  @return array{code:int,out:string,log:string,ini:string}
 */
function dg_fatal(string $worker, string $dir, array $env, string $log, string $iniOut): array
{
    if (is_file($log)) {
        unlink($log);
    }
    if (is_file($iniOut)) {
        unlink($iniOut);
    }
    $proc = proc_open([PHP_BINARY, $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
    $out = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
    $err = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
    if (is_resource($proc)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }
    $code = is_resource($proc) ? proc_close($proc) : 1;

    return [
        'code' => $code,
        'out' => $out . $err,
        'log' => is_file($log) ? (string) file_get_contents($log) : '',
        'ini' => is_file($iniOut) ? (string) file_get_contents($iniOut) : '',
    ];
}

/** @return array{code:int,raw:string,body:string} */
function dg_http(int $port, string $path, string $method, string $body): array
{
    $errno = 0;
    $errstr = '';
    $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 3);
    if (!is_resource($fp)) {
        return ['code' => 0, 'raw' => $errstr, 'body' => ''];
    }
    $req = $method . ' ' . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    fwrite($fp, $req);
    $raw = (string) stream_get_contents($fp);
    fclose($fp);
    $code = 0;
    if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $raw, $match) === 1) {
        $code = (int) $match[1];
    }
    $parts = explode("\r\n\r\n", $raw, 2);

    return ['code' => $code, 'raw' => $raw, 'body' => $parts[1] ?? ''];
}
