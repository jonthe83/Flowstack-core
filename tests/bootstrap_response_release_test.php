<?php

declare(strict_types=1);

/**
 * #974 hängde inloggade sidor på PHP-FPM: Accept-Encoding gzip slog på
 * zlib.output_compression, och ob_end_flush mot den bufferten återvände
 * inte. Workern dog efter max_execution_time med 0 byte till klienten.
 * Frigivningen är av. Testet kör samma väg (fastcgi finns, gzip begärs)
 * och kräver att finish återvänder direkt, plus en riktig sidladdning
 * mot två workers.
 */

if (!function_exists('fastcgi_finish_request')) {
    function fastcgi_finish_request(): bool
    {
        return true;
    }
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$tmp = sys_get_temp_dir() . '/jeab-release-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}

function rel_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rel_ok(string $message): void
{
    echo "OK  {$message}\n";
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$passwords = $tmp . '/pw.json';
file_put_contents($passwords, json_encode([
    'secret' => 'release-secret',
    'hashes' => [
        ['hash' => 'agenter-hash-fixture', 'email' => 'agenter@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'warm_token' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_RELEASE_RESPONSE');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_DEFER_TEST'] = true;
$_GET = ['p' => 'reminders'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['HTTP_HOST'] = '127.0.0.1';

$bootSrc = (string) file_get_contents($root . '/src/bootstrap.php');
if (str_contains($bootSrc, "ini_set('zlib.output_compression', '1')") || str_contains($bootSrc, 'ini_set("zlib.output_compression", "1")')) {
    rel_fail('bootstrap slår fortfarande på zlib.output_compression');
}
if (!str_contains($bootSrc, 'function jeab_response_release_enabled')) {
    rel_fail('flaggan för response-release saknas');
}

require $root . '/src/bootstrap.php';

if (jeab_response_release_enabled()) {
    rel_fail('JEAB_RELEASE_RESPONSE är på som standard');
}
if (jeab_compress_output() !== 'skip') {
    rel_fail('gzip slogs på fast frigivningen är av: ' . jeab_compress_output());
}
$zlib = strtolower((string) ini_get('zlib.output_compression'));
if ($zlib !== '' && $zlib !== '0' && $zlib !== 'off') {
    rel_fail('zlib.output_compression blev ' . $zlib);
}
rel_ok('frigivning och egen gzip är av, zlib rörs inte');

@ini_set('zlib.output_compression', '1');
ob_start();
echo str_repeat('Sidan ', 4000);
set_time_limit(3);
$started = microtime(true);
jeab_finish_http_response();
$finishMs = (int) round((microtime(true) - $started) * 1000);
set_time_limit(0);
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    if (!@ob_end_clean()) {
        break;
    }
}
if ($finishMs > 1000) {
    rel_fail('jeab_finish_http_response tog ' . $finishMs . ' ms');
}
rel_ok('finish med gzip-header och zlib-buffer återvände på ' . $finishMs . ' ms');

$store = jeab_store();
if (!$store instanceof JeabPdoStore) {
    rel_fail('sqlite');
}
$cookie = (string) jeab_auth_issue_value('agenter@flowstack.example.test', 'agenter-hash-fixture');
if ($cookie === '') {
    rel_fail('kaka');
}
if (function_exists('jeab_pdo_release')) {
    jeab_pdo_release();
}

$router = $tmp . '/router.php';
file_put_contents($router, <<<PHP
<?php
\$tmp = getenv('JEAB_REL_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/pw.json');
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_RELEASE_RESPONSE');
\$_SERVER['SCRIPT_FILENAME'] = '{$root}/index.php';
\$_SERVER['SCRIPT_NAME'] = '/index.php';
require '{$root}/index.php';
PHP);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    rel_fail('port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$log = $tmp . '/server.log';
$cmd = 'PHP_CLI_SERVER_WORKERS=2 ' . escapeshellarg($php)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($tmp)
    . ' ' . escapeshellarg($router);
$server = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, [
    'JEAB_REL_TMP' => $tmp,
    'JEAB_PASSWORDS_FILE' => $passwords,
    'JEAB_DISABLE_SQLITE' => '0',
]);
if (!is_resource($server)) {
    rel_fail('php -S');
}
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$up = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(50000);
}
if (!$up) {
    rel_fail('php -S lyssnar inte');
}

function rel_request(int $port, string $path, string $cookie): array
{
    $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if (!is_resource($fp)) {
        return ['status' => 0, 'headers' => '', 'body' => '', 'ms' => 2000, 'error' => $errstr];
    }
    $header = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\nAccept-Encoding: gzip\r\nCookie: jeab_auth=" . rawurlencode($cookie) . "\r\n\r\n";
    $t0 = microtime(true);
    stream_set_timeout($fp, 2);
    fwrite($fp, $header);
    $raw = '';
    while (!feof($fp) && (microtime(true) - $t0) < 2) {
        $chunk = fread($fp, 65536);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($fp);
            if (!empty($meta['timed_out']) || feof($fp)) {
                break;
            }
            usleep(5000);
            continue;
        }
        $raw .= $chunk;
    }
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $split = strpos($raw, "\r\n\r\n");
    $headers = $split === false ? $raw : substr($raw, 0, $split);
    $body = $split === false ? '' : substr($raw, $split + 4);
    $status = 0;
    if (preg_match('/\AHTTP\/\d+(?:\.\d+)?\s+(\d+)/', $headers, $m) === 1) {
        $status = (int) $m[1];
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body,
        'ms' => $ms,
        'timed_out' => !empty($meta['timed_out']),
        'error' => '',
    ];
}

$paths = [
    '/index.php?p=reminders',
    '/index.php?p=links',
    '/index.php?p=kontakter&co=jarfalla',
    '/index.php?p=ordrar&co=jarfalla',
];
$procs = [];
foreach ($paths as $i => $path) {
    $out = $tmp . '/out-' . $i . '.json';
    $code = '<?php
$port = (int) $argv[1];
$path = $argv[2];
$cookie = $argv[3];
$out = $argv[4];
$fp = fsockopen("127.0.0.1", $port, $errno, $errstr, 2);
$t0 = microtime(true);
if (!is_resource($fp)) {
    file_put_contents($out, json_encode(["status" => 0, "ms" => 2000, "headers" => "", "body" => "", "error" => $errstr]));
    exit(0);
}
$req = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\nAccept-Encoding: gzip\r\nCookie: jeab_auth=" . rawurlencode($cookie) . "\r\n\r\n";
stream_set_timeout($fp, 2);
fwrite($fp, $req);
$raw = "";
while (!feof($fp) && (microtime(true) - $t0) < 2) {
    $chunk = fread($fp, 65536);
    if ($chunk === false || $chunk === "") {
        $meta = stream_get_meta_data($fp);
        if (!empty($meta["timed_out"]) || feof($fp)) break;
        usleep(5000);
        continue;
    }
    $raw .= $chunk;
}
$meta = stream_get_meta_data($fp);
fclose($fp);
$ms = (int) round((microtime(true) - $t0) * 1000);
$split = strpos($raw, "\r\n\r\n");
$headers = $split === false ? $raw : substr($raw, 0, $split);
$body = $split === false ? "" : substr($raw, $split + 4);
$status = 0;
if (preg_match("/\\AHTTP\\/\\d+(?:\\.\\d+)?\\s+(\\d+)/", $headers, $m) === 1) {
    $status = (int) $m[1];
}
file_put_contents($out, json_encode([
    "status" => $status,
    "ms" => $ms,
    "headers" => $headers,
    "body" => $body,
    "timed_out" => !empty($meta["timed_out"]),
], JSON_UNESCAPED_UNICODE));
';
    $client = $tmp . '/client-' . $i . '.php';
    file_put_contents($client, $code);
    $proc = proc_open([$php, $client, (string) $port, $path, $cookie, $out], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        rel_fail('klient ' . $path);
    }
    $procs[] = ['proc' => $proc, 'pipes' => $pipes, 'out' => $out, 'path' => $path];
}
foreach ($procs as $job) {
    fclose($job['pipes'][1]);
    fclose($job['pipes'][2]);
    $code = proc_close($job['proc']);
    $row = json_decode((string) @file_get_contents($job['out']), true);
    if (!is_array($row)) {
        rel_fail($job['path'] . ' saknar svar, exit ' . $code);
    }
    $headers = strtolower((string) ($row['headers'] ?? ''));
    $body = (string) ($row['body'] ?? '');
    if (!empty($row['timed_out']) || (int) $row['ms'] > 2000) {
        rel_fail($job['path'] . ' tog ' . (int) $row['ms'] . ' ms');
    }
    if ((int) $row['status'] !== 200) {
        rel_fail($job['path'] . ' status ' . (int) $row['status'] . ' ' . substr($body, 0, 180));
    }
    if (str_contains($headers, 'content-encoding:')) {
        rel_fail($job['path'] . ' komprimerade själv');
    }
    if (!str_contains($body, '</html>') || !str_contains($body, 'Påminnelser') || str_contains($body, 'kampanjkod')) {
        rel_fail($job['path'] . ' är inte en inloggad sida, ' . strlen($body) . ' byte');
    }
    if (preg_match('/content-length:\s*(\d+)/i', (string) $row['headers'], $len) === 1 && (int) $len[1] !== strlen($body)) {
        rel_fail($job['path'] . ' Content-Length ' . $len[1] . ' mot kropp ' . strlen($body));
    }
    rel_ok($job['path'] . ' ' . (int) $row['ms'] . ' ms, ' . strlen($body) . ' byte');
}

$anon = rel_request($port, '/index.php?p=reminders', '');
if ($anon['status'] !== 200 || $anon['ms'] > 2000 || !str_contains($anon['body'], 'kampanjkod')) {
    rel_fail('utloggad sida blev ' . $anon['status'] . ' på ' . $anon['ms'] . ' ms');
}
rel_ok('utloggad sida ' . $anon['ms'] . ' ms');

proc_terminate($server);
proc_close($server);
rel_ok('två workers, fyra inloggade sidor med gzip, ingen över 2 s');
