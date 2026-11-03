<?php

declare(strict_types=1);

/**
 * sms-cron.php tar sin egen tick_token via GET (Loopia) och via POST.
 * Tick är utbytt i testservern så inget mejl eller SMS skickas.
 * Tokenvärdet skapas i temp och skrivs inte ut.
 */

function cron_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cron_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    cron_fail($message);
}

function cron_redact(string $text, string $secret): string
{
    if ($secret === '') {
        return $text;
    }
    return str_replace($secret, '[redacted]', $text);
}

/** @param array<string, string> $headers
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function cron_http(string $url, string $method, ?string $body, string $secret, array $headers = []): array
{
    $header = "Connection: close\r\n";
    foreach ($headers as $name => $value) {
        $header .= $name . ': ' . $value . "\r\n";
    }
    if ($method === 'POST' && !isset($headers['Content-Type'])) {
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $header,
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
            'follow_location' => 0,
        ],
    ]);
    $err = '';
    set_error_handler(static function (int $severity, string $message) use (&$err, $secret): bool {
        unset($severity);
        $err = cron_redact($message, $secret);
        return true;
    });
    $raw = file_get_contents($url, false, $ctx);
    restore_error_handler();
    if ($raw === false) {
        cron_fail('HTTP-anrop misslyckades: ' . ($err !== '' ? $err : 'okänt fel'));
    }
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})\b#', $line, $m) === 1) {
            $status = (int) $m[1];
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
    }
    return ['status' => $status, 'body' => (string) $raw, 'headers' => $responseHeaders];
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-sms-cron-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    cron_fail('temp dir');
}
$mark = $tmp . '/tick-called';
$log = $tmp . '/server.log';
$token = bin2hex(random_bytes(16));
file_put_contents($tmp . '/suresms.json', json_encode(['tick_token' => $token], JSON_UNESCAPED_UNICODE));

$router = $tmp . '/router.php';
$routerSrc = <<<'PHP'
<?php

declare(strict_types=1);

$data = getenv('JEAB_SMS_CRON_DATA');
if (!is_string($data) || $data === '') {
    http_response_code(500);
    echo 'missing data dir';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data;
$GLOBALS['_JEAB_SHIELD_DONE'] = true;
$mark = getenv('JEAB_SMS_CRON_MARK');
$GLOBALS['_JEAB_SMS_CRON_TICK'] = static function ($store) use ($mark): array {
    unset($store);
    if (is_string($mark) && $mark !== '') {
        file_put_contents($mark, '1');
    }
    return [
        'ok' => true,
        'sent' => 0,
        'sent_email' => 0,
        'skipped' => 0,
        'checked' => 0,
        'errors' => [],
    ];
};

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$root = getenv('JEAB_SMS_CRON_ROOT');
if (!is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing root';
    return true;
}
$allowed = [
    '/sms-cron.php' => $root . '/sms-cron.php',
    '/sms-tick.php' => $root . '/sms-tick.php',
    '/hook.php' => $root . '/hook.php',
];
if (!isset($allowed[$path]) || !is_file($allowed[$path])) {
    http_response_code(404);
    echo 'no';
    return true;
}
require $allowed[$path];
return true;
PHP;
file_put_contents($router, $routerSrc);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    cron_fail('kunde inte välja port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
if ($port <= 0) {
    cron_fail('ogiltig port');
}

$cmd = [
    PHP_BINARY,
    '-d', 'display_errors=0',
    '-d', 'log_errors=1',
    '-d', 'error_log=' . $log,
    '-S', '127.0.0.1:' . $port,
    '-t', $root,
    $router,
];
$proc = proc_open($cmd, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
], $pipes, $root, [
    'JEAB_SMS_CRON_DATA' => $tmp,
    'JEAB_SMS_CRON_MARK' => $mark,
    'JEAB_SMS_CRON_ROOT' => $root,
]);
if (!is_resource($proc)) {
    cron_fail('servern startade inte');
}

$stop = static function () use ($proc, $tmp): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    if (is_dir($tmp)) {
        foreach (scandir($tmp) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $tmp . '/' . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($tmp);
    }
};
register_shutdown_function($stop);

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
cron_assert($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port;
$clearMark = static function () use ($mark): void {
    if (is_file($mark)) {
        unlink($mark);
    }
};
$tickCalled = static function () use ($mark): bool {
    return is_file($mark);
};

$srcCron = (string) file_get_contents($root . '/sms-cron.php');
$srcHook = (string) file_get_contents($root . '/hook.php');
$srcTick = (string) file_get_contents($root . '/sms-tick.php');
cron_assert(!str_contains($srcCron, 'jeab_reject_get_webhook_token()'), 'sms-cron.php tvingar inte GET till 405');
cron_assert(str_contains($srcCron, 'jeab_cron_http_gate'), 'sms-cron.php använder cron-grinden');
cron_assert(str_contains($srcCron, "jeab_suresms_config()['tick_token']"), 'sms-cron.php läser sin egen tick_token');
cron_assert(!str_contains($srcCron, 'jeab_tick_expected_token'), 'sms-cron.php använder inte tick.php:s token');
cron_assert(str_contains($srcCron, 'return jeab_sms_tick($store);'), 'sms-cron.php anropar tick när stubben saknas');
cron_assert(str_contains($srcHook, 'jeab_reject_get_webhook_token()'), 'hook.php avvisar fortfarande GET-token');
cron_assert(str_contains($srcTick, 'jeab_reject_get_webhook_token()'), 'sms-tick.php avvisar fortfarande GET-token');

$clearMark();
$probe = cron_http($base . '/hook.php?token=probe', 'GET', null, $token);
cron_assert($probe['status'] === 405, 'hook.php GET med query-token är 405, fick ' . $probe['status']);
cron_assert(str_contains($probe['body'], 'Token tas inte emot via GET.'), 'hook.php svarar att GET-token inte tas emot');
cron_assert(!$tickCalled(), 'hook.php anropar inte tick');

$clearMark();
$probe = cron_http($base . '/sms-tick.php?token=probe', 'GET', null, $token);
cron_assert($probe['status'] === 405, 'sms-tick.php GET med query-token är 405, fick ' . $probe['status']);
cron_assert(str_contains($probe['body'], 'Token tas inte emot via GET.'), 'sms-tick.php svarar att GET-token inte tas emot');
cron_assert(!$tickCalled(), 'sms-tick.php anropar inte tick');

$clearMark();
$missingGet = cron_http($base . '/sms-cron.php', 'GET', null, $token);
cron_assert($missingGet['status'] === 401, 'GET utan token är 401, fick ' . $missingGet['status'] . ' ' . cron_redact($missingGet['body'], $token));
cron_assert(!$tickCalled(), 'GET utan token anropar inte tick');

$clearMark();
$missingPost = cron_http($base . '/sms-cron.php', 'POST', '', $token);
cron_assert($missingPost['status'] === 401, 'POST utan token är 401, fick ' . $missingPost['status'] . ' ' . cron_redact($missingPost['body'], $token));
cron_assert(!$tickCalled(), 'POST utan token anropar inte tick');

$clearMark();
$wrongSecret = 'fel-sms-cron-token';
$wrong = cron_http($base . '/sms-cron.php?token=' . rawurlencode($wrongSecret), 'GET', null, $token);
cron_assert($wrong['status'] === 401, 'GET med fel token är 401, fick ' . $wrong['status']);
cron_assert(!$tickCalled(), 'GET med fel token anropar inte tick');
cron_assert(!str_contains($wrong['body'], $token) && !str_contains($wrong['body'], $wrongSecret), '401-svaret innehåller inte token');
cron_assert(strtolower((string) ($wrong['headers']['referrer-policy'] ?? '')) === 'no-referrer', 'fel token sätter Referrer-Policy no-referrer');

$clearMark();
$goodGet = cron_http($base . '/sms-cron.php?token=' . rawurlencode($token), 'GET', null, $token);
cron_assert($goodGet['status'] === 200, 'GET med egen tick_token är 200, fick ' . $goodGet['status'] . ' ' . cron_redact($goodGet['body'], $token));
$goodJson = json_decode($goodGet['body'], true);
cron_assert(is_array($goodJson) && !empty($goodJson['ok']) && ($goodJson['sent'] ?? null) === 0, 'GET med rätt token når tick-stubben');
cron_assert($tickCalled(), 'GET med rätt token anropar tick');
cron_assert(!str_contains($goodGet['body'], $token), 'GET-svaret innehåller inte token');
cron_assert(strtolower((string) ($goodGet['headers']['referrer-policy'] ?? '')) === 'no-referrer', 'GET sätter Referrer-Policy no-referrer');

$tickOnly = bin2hex(random_bytes(16));
$clearMark();
$otherKey = cron_http($base . '/sms-cron.php?token=' . rawurlencode($tickOnly), 'GET', null, $token);
cron_assert($otherKey['status'] === 401, 'en annan nyckel är inte sms-cron-token, fick ' . $otherKey['status']);
cron_assert(!$tickCalled(), 'annan nyckel anropar inte tick');
cron_assert(!str_contains($otherKey['body'], $tickOnly), 'svaret ekar inte den andra nyckeln');

$clearMark();
$goodHeader = cron_http($base . '/sms-cron.php', 'POST', '', $token, ['X-Webhook-Token' => $token]);
cron_assert($goodHeader['status'] === 200, 'POST med header-token är 200, fick ' . $goodHeader['status'] . ' ' . cron_redact($goodHeader['body'], $token));
$headerJson = json_decode($goodHeader['body'], true);
cron_assert(is_array($headerJson) && !empty($headerJson['ok']) && ($headerJson['sent'] ?? null) === 0, 'POST med header-token når tick-stubben');
cron_assert($tickCalled(), 'POST med header-token anropar tick');
cron_assert(!str_contains($goodHeader['body'], $token), 'header-svaret innehåller inte token');

$clearMark();
$goodPost = cron_http($base . '/sms-cron.php', 'POST', http_build_query(['token' => $token]), $token);
cron_assert($goodPost['status'] === 200, 'POST med token är 200, fick ' . $goodPost['status'] . ' ' . cron_redact($goodPost['body'], $token));
$postJson = json_decode($goodPost['body'], true);
cron_assert(is_array($postJson) && !empty($postJson['ok']), 'POST med token når tick-stubben');
cron_assert($tickCalled(), 'POST med token anropar tick');
cron_assert(!str_contains($goodPost['body'], $token), 'POST-svaret innehåller inte token');

echo "OK  sms-cron GET\n";
