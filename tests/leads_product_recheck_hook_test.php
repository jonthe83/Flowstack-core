<?php

declare(strict_types=1);

/** leads.product_recheck ska nå inkorgsdispatchern via hook.php. dry=1 skriver inget. */

$tmp = sys_get_temp_dir() . '/jeab-lead-recheck-hook-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lpr(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

$httpRoot = sys_get_temp_dir() . '/jeab-lead-recheck-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_LPR_DATA');
$root = getenv('JEAB_LPR_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
lpr($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_LPR_DATA=' . escapeshellarg($tmp)
    . ' JEAB_LPR_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
lpr(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
lpr($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (array $payload, bool $auth) use ($base, $inboxToken): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    if (str_contains($text, $inboxToken)) {
        fwrite(STDERR, "FAIL svaret innehåller token\n");
        exit(1);
    }
    $json = json_decode($text, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text];
};

$denied = $http(['action' => 'leads.product_recheck', 'dry' => 1], false);
lpr($denied['status'] === 401 && ($denied['json']['error'] ?? '') === 'Fel token.', 'hook.php utan token är 401');

$dry = $http(['action' => 'leads.product_recheck', 'dry' => 1], true);
$json = $dry['json'];
lpr(
    $dry['status'] === 200
        && ($json['ok'] ?? false) === true
        && ($json['error'] ?? '') !== 'not_found'
        && ($json['dry'] ?? 0) === 1
        && ($json['wrote'] ?? 1) === 0
        && ($json['days'] ?? 0) === 30
        && ($json['checked'] ?? -1) === 0
        && ($json['diffs'] ?? null) === [],
    'dry=1 når jeab_lead_product_recheck via hook.php'
);
lpr(!str_contains($dry['body'], '@'), 'svaret innehåller ingen e-post');

$storePath = $tmp . '/store.json';
$storeRaw = is_file($storePath) ? (string) file_get_contents($storePath) : '';
lpr($storeRaw === '' || !str_contains($storeRaw, '"reminders"') || !preg_match('/"reminders"\s*:\s*\[\s*\{/', $storeRaw), 'dry=1 skrev ingen påminnelse');

echo "OK  leads.product_recheck via hook.php\n";
