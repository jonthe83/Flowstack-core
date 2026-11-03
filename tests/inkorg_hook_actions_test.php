<?php

declare(strict_types=1);

/** inbox.mail_links, inbox.mail_links_dedupe, inbox.trash_leads och inbox.waiting_recheck via hook.php. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-hook-actions-' . bin2hex(random_bytes(4));
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

function ha(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

$direct = jeab_inkorg_hook(['action' => 'inkorg.mail_links']);
ha(($direct['ok'] ?? false) === true && ($direct['dry'] ?? 0) === 1, 'inkorg.mail_links når kroken direkt');
$directInbox = jeab_inkorg_hook(['action' => 'inbox.mail_links']);
ha(($directInbox['ok'] ?? false) === true && ($directInbox['error'] ?? '') !== 'not_found', 'inbox.mail_links når kroken direkt');

$httpRoot = sys_get_temp_dir() . '/jeab-hook-actions-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_HA_DATA');
$root = getenv('JEAB_HA_ROOT');
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
ha($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$pidFile = $httpRoot . '/php-server.pid';
$script = $httpRoot . '/serve.sh';
file_put_contents($script, "#!/bin/bash\ntrap 'if [ -f " . escapeshellarg($pidFile) . " ]; then kill -TERM \"\$(cat " . escapeshellarg($pidFile) . ")\" 2>/dev/null || true; fi' EXIT TERM INT\n"
    . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router)
    . " &\necho \$! > " . escapeshellarg($pidFile) . "\nwait \$!\n");
chmod($script, 0700);
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_HA_DATA=' . escapeshellarg($tmp)
    . ' JEAB_HA_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' bash ' . escapeshellarg($script);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
ha(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc, $pidFile): void {
    $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;
    if ($pid > 1) {
        exec('kill -TERM ' . $pid . ' >/dev/null 2>&1');
    }
    if (is_resource($proc)) {
        proc_terminate($proc, 15);
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
ha($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (string $action, bool $auth) use ($base, $inboxToken): array {
    $body = json_encode(['action' => $action], JSON_UNESCAPED_UNICODE);
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
        CURLOPT_TIMEOUT => 8,
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

$denied = $http('inbox.mail_links', false);
ha($denied['status'] === 401, 'hook.php utan token är 401');

foreach ([
    'inbox.mail_links' => 'mail_links',
    'inkorg.mail_links' => 'mail_links via inkorg-alias',
    'inbox.mail_links_dedupe' => 'mail_links_dedupe',
    'inkorg.mail_links_dedupe' => 'mail_links_dedupe via inkorg-alias',
    'inbox.waiting_recheck' => 'waiting_recheck',
] as $action => $label) {
    $res = $http($action, true);
    $json = $res['json'];
    ha($res['status'] === 200 && ($json['ok'] ?? false) === true && ($json['error'] ?? '') !== 'not_found' && ($json['dry'] ?? 0) === 1, 'hook.php ' . $label);
    ha(!str_contains($res['body'], '@'), 'hook.php ' . $label . ' innehåller ingen e-post');
}
foreach ([
    'inbox.trash_leads' => 'trash_leads',
    'inkorg.trash_leads' => 'trash_leads via inkorg-alias',
] as $action => $label) {
    $res = $http($action, true);
    $json = $res['json'];
    ha($res['status'] === 200 && ($json['ok'] ?? false) === true && ($json['error'] ?? '') !== 'not_found' && array_key_exists('leads', $json), 'hook.php ' . $label);
    ha(!str_contains($res['body'], '@'), 'hook.php ' . $label . ' innehåller ingen e-post');
}

echo "OK  hook-actions\n";
