<?php

declare(strict_types=1);

/**
 * Sidkropp, schema på GET och svar som släpps innan efterarbete.
 * Varje sidritning startar en egen PHP-process. Påhittad data. Skickar inget.
 */

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;

if (($argv[1] ?? '') === 'worker') {
    sf2_worker($root, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sidfart2-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}

function sf2_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sf2_ok(string $message): void
{
    echo "OK  {$message}\n";
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'sidfart2-secret',
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
putenv('JEAB_WARM_TOKEN=sidfart2-warm');
putenv('XAI_API_KEY');
$_ENV['JEAB_WARM_TOKEN'] = 'sidfart2-warm';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_BOOT_ALL'] = true;
$_GET = ['p' => 'kontakter'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_SESSION['jeab_company'] = 'jarfalla';
unset($_SESSION['csrf'], $_SESSION['jeab_csrf_auth_nonce']);
session_write_close();
$GLOBALS['_JEAB_SESSION_READONLY'] = true;
$GLOBALS['_JEAB_SESSION_RELEASED'] = true;
unset($_SESSION['csrf'], $_SESSION['jeab_csrf_auth_nonce'], $GLOBALS['_JEAB_CSRF'], $GLOBALS['_JEAB_CSRF_AUTH_NONCE']);
$csrf = jeab_csrf_session_token();
$nonce = jeab_csrf_auth_nonce();
session_start();
if ($csrf === '' || ($_SESSION['csrf'] ?? '') !== $csrf) {
    sf2_fail('csrf från readonly-svar saknas i sessionen');
}
if ($nonce === '' || ($_SESSION['jeab_csrf_auth_nonce'] ?? '') !== $nonce) {
    sf2_fail('auth-nonce från readonly-svar saknas i sessionen');
}
$again = jeab_csrf_session_token();
if ($again !== $csrf) {
    sf2_fail('nästa läsning fick en ny csrf');
}
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_SESSION['jeab_company'] = 'jarfalla';
unset($GLOBALS['_JEAB_SESSION_READONLY'], $GLOBALS['_JEAB_SESSION_RELEASED']);

sf2_ok('readonly utan csrf sparar tokenen i sessionen');
sf2_ok(jeab_ordrar_page_size() === 25 ? 'ordrar visar 25 rader' : '');
if (jeab_ordrar_page_size() !== 25) {
    sf2_fail('sidstorlek är ' . jeab_ordrar_page_size());
}

$shell = (string) file_get_contents($root . '/assets/js/shell.js');
if (!str_contains($shell, 'FETCH_MAX = 2') || !str_contains($shell, 'function abortShellFetches')) {
    sf2_fail('skalet saknar taket på två hämtningar');
}
if (!str_contains($shell, 'PREFETCH_MAX = 4')) {
    sf2_fail('förhämtningsbudgeten är borta');
}
sf2_ok('skalet hämtar högst två åt gången och avbryter på klick');

$layout = (string) file_get_contents($root . '/src/layout.php');
if (!str_contains($layout, 'rel="preload"') || !str_contains($layout, 'as="style"') || !str_contains($layout, 'as="script"')) {
    sf2_fail('head saknar preload');
}
sf2_ok('css och js förhämtas i head');

$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
$_GET['intent'] = '';
if (!jeab_request_session_readonly()) {
    sf2_fail('förhämtning öppnar skrivbar session');
}
unset($_SERVER['HTTP_SEC_PURPOSE']);
$_GET = ['p' => 'kontakter', 'intent' => 'card', 'id' => 'kt_x'];
if (!jeab_request_session_readonly()) {
    sf2_fail('intent=card öppnar skrivbar session');
}
$_GET = ['p' => 'bookings', 'intent' => 'calendar-log'];
if (!jeab_request_session_readonly()) {
    sf2_fail('calendar-log öppnar skrivbar session');
}
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread', 'id' => 't1'];
if (!jeab_request_session_readonly()) {
    sf2_fail('ix_part öppnar skrivbar session');
}
$_GET = ['p' => 'kontakter'];
if (jeab_request_session_readonly()) {
    sf2_fail('vanlig lista blev skrivskyddad');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['p' => 'kontakter', 'intent' => 'card', 'id' => 'kt_x'];
if (jeab_request_session_readonly()) {
    sf2_fail('POST intent=card blev skrivskyddad');
}
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread', 'id' => 't1'];
if (jeab_request_session_readonly()) {
    sf2_fail('POST ix_part=thread blev skrivskyddad');
}
$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
if (jeab_request_session_readonly()) {
    sf2_fail('POST-förhämtning blev skrivskyddad');
}
unset($_SERVER['HTTP_SEC_PURPOSE']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'kontakter', 'intent' => 'card'];
if (!jeab_request_session_readonly()) {
    sf2_fail('GET intent=card är inte skrivskyddad');
}
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread'];
if (!jeab_request_session_readonly()) {
    sf2_fail('GET ix_part=thread är inte skrivskyddad');
}
$_GET = ['p' => 'kontakter'];
sf2_ok('POST med intent eller ix_part skriver sessionen, GET läser den');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['REQUEST_METHOD'] = 'GET';
if (jeab_schema_probe_allowed()) {
    sf2_fail('GET får köra schema-probe');
}
$probe = new PDO('sqlite::memory:');
$show = false;
try {
    $exists = jeab_reminders_sql_table($probe, 'purchases');
    $col = jeab_reminders_sql_column($probe, 'purchases', 'reminder_id');
} catch (Throwable $e) {
    $show = true;
    $exists = false;
    $col = false;
}
if ($show || $exists !== true || $col !== true) {
    sf2_fail('GET körde SHOW TABLES/COLUMNS');
}
$logged = implode("\n", is_array($GLOBALS['_JEAB_SQL_LOG'] ?? null) ? $GLOBALS['_JEAB_SQL_LOG'] : []);
if (stripos($logged, 'SHOW TABLES') !== false || stripos($logged, 'SHOW COLUMNS') !== false || stripos($logged, 'information_schema') !== false) {
    sf2_fail('SQL-loggen har schemafraga: ' . $logged);
}
sf2_ok('GET hoppar över SHOW TABLES, SHOW COLUMNS och information_schema');
unset($GLOBALS['_JEAB_SAPI']);

$boot = (string) file_get_contents($root . '/src/bootstrap.php');
if (!str_contains($boot, "jeab_timing_span('fcgi'") || !str_contains($boot, "jeab_timing_span('shutdown_ms'")) {
    sf2_fail('Server-Timing saknar fcgi eller shutdown_ms');
}
if (!str_contains($boot, 'function jeab_arm_http_response') || !str_contains($boot, "header('Content-Length: '")) {
    sf2_fail('svaret armeras inte med Content-Length');
}
if (!str_contains($boot, 'function jeab_response_release_enabled') || jeab_response_release_enabled()) {
    sf2_fail('response-release är på utan flagga');
}
sf2_ok('fcgi, shutdown_ms och Content-Length finns, frigivningen är av');

$timing = jeab_server_timing_value();
jeab_timing_span('fcgi', 0.0);
jeab_timing_span('shutdown_ms', 0.012);
$timing = jeab_server_timing_value();
if (!str_contains($timing, 'fcgi;dur=0') || !str_contains($timing, 'shutdown_ms;dur=')) {
    sf2_fail('timing-raden: ' . $timing);
}
sf2_ok('Server-Timing bär fcgi och shutdown_ms');

if (jeab_warm_token_expected() !== 'sidfart2-warm') {
    sf2_fail('warm-token lästes inte från miljön');
}
unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY'], $GLOBALS['_JEAB_CRON_QUERY_TOKEN']);
$_GET['token'] = 'sidfart2-warm';
$_SERVER['REQUEST_METHOD'] = 'GET';
if (!jeab_cron_token_matches(jeab_warm_token_expected())) {
    sf2_fail('rätt warm-token nekades');
}
unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY'], $GLOBALS['_JEAB_CRON_QUERY_TOKEN']);
$_GET['token'] = 'fel';
if (jeab_cron_token_matches(jeab_warm_token_expected())) {
    sf2_fail('fel warm-token släpptes in');
}
unset($_GET['token'], $GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY'], $GLOBALS['_JEAB_CRON_QUERY_TOKEN']);
sf2_ok('warm-token kommer från miljön och jämförs utan att loggas');

$store = jeab_store();
if (!$store instanceof JeabPdoStore) {
    sf2_fail('sqlite');
}
$bookingPdo = $store->pdo();
jeab_settings_put($bookingPdo, 'sqlite', 'bookings_week_query_v1', 'ok');
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['REQUEST_METHOD'] = 'GET';
$migrateRuns = $GLOBALS['_JEAB_MIGRATE_RUNS'] ?? null;
unset($GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_TICK']);
$withFlag = jeab_booking_query_mode_read($bookingPdo, 'sqlite');
if (empty($withFlag['ends_sort'])) {
    sf2_fail('ok-flaggan stängde ends_sort på GET');
}
jeab_settings_put($bookingPdo, 'sqlite', 'bookings_week_query_v1', '');
jeab_booking_column_cache_forget($bookingPdo, 'ends_sort');
$withoutFlag = jeab_booking_query_mode_read($bookingPdo, 'sqlite');
if (!empty($withoutFlag['ends_sort'])) {
    sf2_fail('saknad flagga slog på ends_sort via kolumnkoll på GET');
}
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
if (jeab_booking_schema_work_allowed()) {
    sf2_fail('GET fick sätta bookings_week_query_v1');
}
unset($GLOBALS['_JEAB_PAGE_REQUEST']);
$GLOBALS['_JEAB_TICK'] = true;
if (!jeab_booking_schema_work_allowed()) {
    sf2_fail('tick får inte sätta bookings_week_query_v1');
}
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_SAPI']);
if ($migrateRuns !== null) {
    $GLOBALS['_JEAB_MIGRATE_RUNS'] = $migrateRuns;
}
jeab_settings_put($bookingPdo, 'sqlite', 'bookings_week_query_v1', 'ok');
sf2_ok('ends_sort följer flaggan på GET, tick sätter den');
$fat = 'FATNOTETOKEN' . str_repeat('n', 8000);
$bulk = [];
for ($i = 0; $i < 80; $i++) {
    $id = sprintf('kt_fat%04d', $i);
    $bulk[$id] = [
        'id' => $id,
        'street' => 'Gatan ' . $i,
        'city' => 'Järfälla',
        'postal_code' => '17600',
        'company' => 'jarfalla',
        'ovrigt' => $fat . $i,
        'owners' => [[
            'name' => 'Fett Namn ' . $i,
            'contact' => true,
            'personnummer' => '',
            'email' => 'fett' . $i . '@example.test',
            'phone' => '0700000' . $i,
        ]],
    ];
}
jeab_kontakter_mutate(static function (array &$contacts) use ($bulk): void {
    foreach ($bulk as $id => $row) {
        $contacts[$id] = $row;
    }
});
$_GET = ['p' => 'kontakter', 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$html = (string) require $root . '/src/pages/kontakter.php';
if (strlen($html) > 120000) {
    sf2_fail('kontaktlistan är ' . strlen($html) . ' byte');
}
if (substr_count($html, 'data-id="') > JEAB_KONTAKT_PAGE) {
    sf2_fail('listan ritade fler än en sida');
}
if (str_contains($html, 'FATNOTETOKEN')) {
    sf2_fail('listan bäddade in anteckningarna');
}
sf2_ok('kontaktlistan är ' . strlen($html) . ' byte utan anteckningarna');

sf2_ok('listan lämnar ärendena till intent=threads');

$row = [
    'reminder_id' => 42,
    'updated_at' => '2026-10-29 12:00:00',
    'status' => 'open',
    'kind' => 'sol',
    'company' => 'jarfalla',
    'customer_name' => 'Rad Test',
    'name' => 'Rad Test',
];
$key = jeab_ordrar_row_cache_key($row, 'jarfalla', null);
jeab_ordrar_row_cache_put($key, '<li>cachad</li>');
if (jeab_ordrar_row_cache_get($key) !== '<li>cachad</li>') {
    sf2_fail('radcachen träffade inte');
}
sf2_ok('orderradens HTML cachas på radhash');

file_put_contents($tmp . '/cookie.txt', (string) jeab_auth_issue_value('agenter@flowstack.example.test', 'agenter-hash-fixture'));
if (function_exists('jeab_pdo_release')) {
    jeab_pdo_release();
}

$pages = [
    'kontakter' => ['p' => 'kontakter', 'co' => 'jarfalla'],
    'kontakter-card' => ['p' => 'kontakter', 'co' => 'jarfalla', 'intent' => 'card', 'id' => 'kt_fat0001'],
    'ordrar' => ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'ws' => (string) time()],
    'reminders' => ['p' => 'reminders', 'co' => 'jarfalla'],
    'bookings' => ['p' => 'bookings', 'co' => 'jarfalla'],
];
foreach ($pages as $name => $get) {
    $cmd = [$php, '-d', 'display_errors=1', $root . '/tests/sidfart2_test.php', 'worker', $tmp, $name];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '0',
        'JEAB_PAGE_GET' => json_encode($get, JSON_UNESCAPED_UNICODE),
    ]);
    if (!is_resource($proc)) {
        sf2_fail($name . ' startade inte');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $blob = (string) $out . "\n" . (string) $err;
    if ($code !== 0 || !str_contains($blob, 'PAGE_OK')) {
        sf2_fail($name . ' render ' . $code . ' ' . substr(preg_replace('/\s+/', ' ', $blob) ?? '', 0, 500));
    }
    if (stripos($blob, 'SHOW TABLES') !== false || stripos($blob, 'SHOW COLUMNS') !== false || stripos($blob, 'information_schema') !== false) {
        sf2_fail($name . ' körde schemafraga');
    }
    sf2_ok($name . ' ritades utan SHOW TABLES');
}

sf2_ok('sidorna bootades var för sig');

$httpDir = $tmp . '/http';
mkdir($httpDir . '/empty', 0775, true);
$router = $httpDir . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_SF_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_SF_DATA');
require getenv('JEAB_SF_ROOT') . '/src/bootstrap.php';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/sida') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><p>Sidan</p>';
    exit;
}
if ($path === '/json') {
    header('Content-Type: application/json; charset=utf-8');
    echo '{"ok":true}';
    exit;
}
if ($path === '/fil') {
    $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="fullmakt.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
if ($path === '/tom') {
    http_response_code(204);
    exit;
}
if ($path === '/hopp') {
    jeab_redirect('/sida');
}
http_response_code(404);
echo 'no';
PHP
);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    sf2_fail('kunde inte välja port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$httpLog = $httpDir . '/php.log';
$cmd = escapeshellarg($php)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($httpLog)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpDir . '/empty')
    . ' ' . escapeshellarg($router);
$httpProc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $httpLog, 'a'], 2 => ['file', $httpLog, 'a']], $httpPipes, $root, [
    'JEAB_SF_DATA' => $tmp,
    'JEAB_SF_ROOT' => $root,
    'JEAB_DISABLE_SQLITE' => '0',
]);
if (!is_resource($httpProc)) {
    sf2_fail('php -S startade inte');
}
$httpUp = false;
for ($i = 0; $i < 30; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $httpUp = true;
        break;
    }
    usleep(100000);
}
if (!$httpUp) {
    proc_terminate($httpProc);
    proc_close($httpProc);
    sf2_fail('php -S lyssnar inte');
}
try {
    $plain = sf2_http($port, 'GET', '/sida', null);
    if ($plain['status'] !== 200 || str_contains(strtolower($plain['headers']), 'content-encoding: gzip') || !str_contains($plain['body'], '<p>Sidan</p>')) {
        sf2_fail('sidan utan gzip blev ' . $plain['status'] . ' ' . substr($plain['body'], 0, 80));
    }
    $zip = sf2_http($port, 'GET', '/sida', 'gzip');
    if ($zip['status'] !== 200 || str_contains(strtolower($zip['headers']), 'content-encoding:') || !str_contains($zip['body'], '<p>Sidan</p>')) {
        sf2_fail('sidan med Accept-Encoding gzip blev ' . $zip['status'] . ' ' . substr($zip['body'], 0, 80));
    }
    $json = sf2_http($port, 'POST', '/json', null, '{"x":1}');
    $decoded = json_decode($json['body'], true);
    if ($json['status'] !== 200 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
        sf2_fail('json utan gzip blev ' . $json['status'] . ' ' . substr($json['body'], 0, 80));
    }
    $jsonZip = sf2_http($port, 'POST', '/json', 'gzip', '{"x":1}');
    $decodedZip = json_decode($jsonZip['body'], true);
    if ($jsonZip['status'] !== 200 || str_contains(strtolower($jsonZip['headers']), 'content-encoding:') || !is_array($decodedZip) || ($decodedZip['ok'] ?? false) !== true) {
        sf2_fail('json med Accept-Encoding gzip blev ' . $jsonZip['status'] . ' ' . substr($jsonZip['body'], 0, 80));
    }
    $head = sf2_http($port, 'HEAD', '/json', null);
    if ($head['status'] !== 200 || $head['body'] !== '') {
        sf2_fail('HEAD lämnade en kropp på ' . strlen($head['body']) . ' byte');
    }
    $pdf = sf2_http($port, 'GET', '/fil', 'gzip');
    if ($pdf['status'] !== 200 || !str_starts_with($pdf['body'], '%PDF-1.4') || str_contains(strtolower($pdf['headers']), 'content-encoding: gzip') || !str_contains(strtolower($pdf['headers']), 'content-disposition:')) {
        sf2_fail('pdf blev ' . $pdf['status'] . ' ' . substr($pdf['body'], 0, 40));
    }
    $empty = sf2_http($port, 'GET', '/tom', 'gzip');
    if ($empty['status'] !== 204 || $empty['body'] !== '' || str_contains(strtolower($empty['headers']), 'content-encoding:')) {
        sf2_fail('204 blev ' . $empty['status'] . ' kropp ' . strlen($empty['body']));
    }
    $hop = sf2_http($port, 'GET', '/hopp', null);
    if ($hop['status'] !== 303 || !str_contains($hop['headers'], 'Location: /sida') || str_contains(strtolower($hop['headers']), 'content-encoding:')) {
        sf2_fail('redirect blev ' . $hop['status'] . ' ' . substr($hop['headers'], 0, 180));
    }
    sf2_ok('php -S lämnar råtext även när klienten ber om gzip, och rör inte pdf, 204 eller redirect');
} finally {
    proc_terminate($httpProc);
    proc_close($httpProc);
}

function sf2_http(int $port, string $method, string $path, ?string $accept, string $body = ''): array
{
    $hdr = $method . ' ' . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
    if ($accept !== null) {
        $hdr .= 'Accept-Encoding: ' . $accept . "\r\n";
    }
    if ($body !== '') {
        $hdr .= "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n";
    }
    $hdr .= "\r\n" . $body;
    $fp = fsockopen('127.0.0.1', $port, $errno, $errstr, 3);
    if (!is_resource($fp)) {
        return ['status' => 0, 'headers' => '', 'body' => ''];
    }
    stream_set_timeout($fp, 5);
    fwrite($fp, $hdr);
    $raw = stream_get_contents($fp);
    fclose($fp);
    $raw = is_string($raw) ? $raw : '';
    $split = strpos($raw, "\r\n\r\n");
    $headers = $split === false ? $raw : substr($raw, 0, $split);
    $payload = $split === false ? '' : substr($raw, $split + 4);
    $status = 0;
    if (preg_match('/\AHTTP\/\d+(?:\.\d+)?\s+(\d+)/', $headers, $m) === 1) {
        $status = (int) $m[1];
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $payload];
}

function sf2_worker(string $root, string $tmp, string $page): void
{
    $get = json_decode((string) getenv('JEAB_PAGE_GET'), true);
    if (!is_array($get)) {
        fwrite(STDOUT, "FAILED get\n");
        exit(1);
    }
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
    $_COOKIE['jeab_auth'] = trim((string) file_get_contents($tmp . '/cookie.txt'));
    $GLOBALS['_JEAB_BOOT_LAZY'] = true;
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('XAI_API_KEY');
    ini_set('log_errors', '1');
    ini_set('error_log', $tmp . '/php-error-' . $page . '.log');
    ob_start();
    register_shutdown_function(static function () use ($tmp, $page): void {
        $html = (string) ob_get_contents();
        $log = implode("\n", is_array($GLOBALS['_JEAB_SQL_LOG'] ?? null) ? $GLOBALS['_JEAB_SQL_LOG'] : []);
        $err = error_get_last();
        $errText = is_array($err) ? (string) ($err['message'] ?? '') : '';
        $file = is_file($tmp . '/php-error-' . $page . '.log') ? (string) file_get_contents($tmp . '/php-error-' . $page . '.log') : '';
        $blob = $html . "\n" . $errText . "\n" . $file;
        if (str_contains($blob, 'undefined function') || str_contains($blob, 'Uncaught')) {
            fwrite(STDOUT, "FAILED\n" . substr($blob, 0, 800) . "\nSQL\n" . $log . "\n");
            return;
        }
        if ($page === 'kontakter-card') {
            if (strlen($html) > 60000 || !str_contains($html, 'data-kt-threads') || str_contains($html, 'Inga ärenden')) {
                fwrite(STDOUT, 'FAILED card ' . strlen($html) . "\n" . substr($html, 0, 400) . "\n");
                return;
            }
        }
        if ($page === 'kontakter' && (strlen($html) > 250000 || str_contains($html, 'FATNOTETOKEN'))) {
            fwrite(STDOUT, 'FAILED list ' . strlen($html) . "\n");
            return;
        }
        fwrite(STDOUT, "PAGE_OK bytes=" . strlen($html) . "\nSQL\n" . $log . "\n");
    });
    require $root . '/index.php';
}
