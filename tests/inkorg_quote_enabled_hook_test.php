<?php

declare(strict_types=1);

/** inbox.quote_enabled: bara auto_quote_enabled, läsläge, samma token som inbox.gp_flags. */

$tmp = sys_get_temp_dir() . '/jeab-quote-enabled-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qe(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function qe_settings(): array
{
    $path = jeab_inkorg_dir() . '/settings.json';
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($d) ? $d : [];
}

function qe_put(array $d): void
{
    $path = jeab_inkorg_dir() . '/settings.json';
    file_put_contents($path, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

$hookSrc = (new ReflectionFunction('jeab_auto_quote_enabled_hook'))->getFileName();
$fnSrc = '';
$rf = new ReflectionFunction('jeab_auto_quote_enabled_hook');
$lines = file($rf->getFileName());
$fnSrc = implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
qe(!str_contains($fnSrc, 'jeab_auto_settings_save'), 'hooken anropar inte jeab_auto_settings_save');
qe($hookSrc === $rf->getFileName() && str_contains((string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php'), "case 'inbox.quote_enabled'"), 'åtgärden finns i inkorg-hooken');

$seed = [
    'auto_quote_enabled' => false,
    'auto_trash_spam' => true,
    'auto_quote_only_from' => 'demo@example.test',
    'auto_min_confidence' => 80,
    'intent' => 'rör inte denna text',
    'quote_templates' => [
        'laddbox_jarfalla' => "UNIK-MALL-SKA-KVAR\nrad 2",
        'batteri_jarfalla' => 'BATTERI-UNIK',
    ],
    'custom_keep' => ['n' => 1, 's' => 'åäö'],
];
qe_put($seed);
$beforeHash = hash('sha256', (string) file_get_contents(jeab_inkorg_dir() . '/settings.json'));

$others = static function (array $d): array {
    unset($d['auto_quote_enabled']);
    return $d;
};

$read = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'mode' => 'state', 'tpl' => ['laddbox_jarfalla' => 'OVERWRITE'], 'quote_templates' => ['x' => 'y'], 'auto_trash_spam' => false]);
qe($read === ['ok' => true, 'auto_quote_enabled' => false], 'mode=state läser false');
qe(hash('sha256', (string) file_get_contents(jeab_inkorg_dir() . '/settings.json')) === $beforeHash, 'läsläge skriver inte om filen');

$readGet = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'mode' => 'get', 'auto_quote_enabled' => true]);
qe($readGet['auto_quote_enabled'] === false, 'mode=get skriver inte ens om värdet följer med');
qe(hash('sha256', (string) file_get_contents(jeab_inkorg_dir() . '/settings.json')) === $beforeHash, 'mode=get lämnar filen');

$bare = jeab_inkorg_hook(['action' => 'inbox.quote_enabled']);
qe($bare['ok'] === true && $bare['auto_quote_enabled'] === false, 'utan värde är det läsning');

$set = jeab_inkorg_hook([
    'action' => 'inbox.quote_enabled',
    'auto_quote_enabled' => true,
    'tpl' => ['laddbox_jarfalla' => 'OVERWRITE'],
    'quote_templates' => ['x' => 'y'],
    'auto_trash_spam' => false,
    'auto_quote_only_from' => '',
    'intent' => 'hacked',
    'token' => 'should-not-echo',
]);
qe($set === ['ok' => true, 'auto_quote_enabled' => true], 'påslag läses tillbaka');
qe(!array_key_exists('token', $set) && !array_key_exists('quote_templates', $set), 'svaret innehåller bara läget');
$after = qe_settings();
qe($after['auto_quote_enabled'] === true, 'filen har boolean true');
qe($others($after) === $others($seed), 'mallar och övriga nycklar är oförändrade');
qe(jeab_auto_settings()['quote_enabled'] === true, 'samma flagga som reglersidan läser');
qe(str_contains((string) file_get_contents(jeab_inkorg_dir() . '/settings.json'), 'UNIK-MALL-SKA-KVAR'), 'mallen ligger kvar i settings.json');

$sameHash = hash('sha256', (string) file_get_contents(jeab_inkorg_dir() . '/settings.json'));
$again = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => true]);
qe($again['auto_quote_enabled'] === true && hash('sha256', (string) file_get_contents(jeab_inkorg_dir() . '/settings.json')) === $sameHash, 'samma värde skrivs inte om');

$off = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => 'false']);
qe($off['auto_quote_enabled'] === false && qe_settings()['auto_quote_enabled'] === false, 'strängen false slår av');
qe($others(qe_settings()) === $others($seed), 'avslag rör inte övriga nycklar');

qe_put($seed);
try {
    jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => 'maybe', 'quote_templates' => ['x' => 'y']]);
    qe(false, 'ogiltigt värde ska avvisas');
} catch (InvalidArgumentException $e) {
    qe($e->getMessage() === 'auto_quote_enabled ska vara sant eller falskt.', 'ogiltigt värde avvisas');
}
qe(qe_settings() === $seed, 'ogiltigt värde ändrar inget');

unlink(jeab_inkorg_dir() . '/settings.json');
$missing = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'mode' => 'state']);
qe($missing === ['ok' => true, 'auto_quote_enabled' => false] && !is_file(jeab_inkorg_dir() . '/settings.json'), 'läsning skapar inte settings.json');
$created = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => 1]);
qe($created['auto_quote_enabled'] === true && array_keys(qe_settings()) === ['auto_quote_enabled'], 'ny fil får bara auto_quote_enabled');

// HTTP: samma tokenkanaler som inbox.gp_flags (POST Bearer / X-Webhook-Token). Ingen token i svaret.
$httpRoot = sys_get_temp_dir() . '/jeab-quote-http-' . bin2hex(random_bytes(4));
$httpData = $httpRoot . '/data';
$httpEmpty = $httpRoot . '/empty';
mkdir($httpData . '/inkorg', 0775, true);
mkdir($httpData . '/secrets', 0775, true);
mkdir($httpData . '/public', 0775, true);
mkdir($httpEmpty, 0775, true);
$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents($httpData . '/secrets/inkorg-ingest.json', json_encode(['key' => $inboxToken]));
file_put_contents($httpData . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $httpData . '/app.sqlite', 'json_path' => $httpData . '/store.json'],
], true) . ";\n");
$httpSeed = $seed;
$httpSeed['auto_quote_enabled'] = false;
file_put_contents($httpData . '/inkorg/settings.json', json_encode($httpSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_QUOTE_DATA');
$root = getenv('JEAB_QUOTE_ROOT');
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
if ($probe === false) {
    qe(false, 'kunde inte välja port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
qe($port > 0, 'port vald');
$php = PHP_BINARY;
$pidFile = $httpRoot . '/php-server.pid';
$script = $httpRoot . '/serve.sh';
file_put_contents($script, "#!/bin/bash\ntrap 'if [ -f " . escapeshellarg($pidFile) . " ]; then kill -TERM \"\$(cat " . escapeshellarg($pidFile) . ")\" 2>/dev/null || true; fi' EXIT TERM INT\n"
    . escapeshellarg($php)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router)
    . " &\necho \$! > " . escapeshellarg($pidFile) . "\nwait \$!\n");
chmod($script, 0700);
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_QUOTE_DATA=' . escapeshellarg($httpData)
    . ' JEAB_QUOTE_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' bash ' . escapeshellarg($script);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
qe(is_resource($proc), 'lokal PHP-server startade');
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
qe($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (string $url, string $method, ?string $body, array $headers) use ($inboxToken): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 5,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    if ($inboxToken !== '' && str_contains($text, $inboxToken)) {
        fwrite(STDERR, "FAIL svaret innehåller token\n");
        exit(1);
    }
    return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true)];
};

$deny = $http($base, 'POST', json_encode(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => true]), ['Content-Type: application/json']);
qe($deny['status'] === 401 && ($deny['json']['error'] ?? '') === 'Fel token.', 'utan token blir det 401');
$still = json_decode((string) file_get_contents($httpData . '/inkorg/settings.json'), true);
qe(is_array($still) && $still['auto_quote_enabled'] === false && $still['quote_templates'] === $httpSeed['quote_templates'], '401 ändrar inte settings');

$on = $http($base, 'POST', json_encode(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => true, 'quote_templates' => ['x' => 'y']]), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
qe($on['status'] === 200 && $on['json'] === ['ok' => true, 'auto_quote_enabled' => true], 'Bearer slår på och läser tillbaka');
$disk = json_decode((string) file_get_contents($httpData . '/inkorg/settings.json'), true);
qe(is_array($disk) && $disk['auto_quote_enabled'] === true && $disk['quote_templates'] === $httpSeed['quote_templates'] && $disk['intent'] === $httpSeed['intent'], 'HTTP-påslag rör bara flaggan');

$stateHash = hash('sha256', (string) file_get_contents($httpData . '/inkorg/settings.json'));
$state = $http($base, 'POST', json_encode(['action' => 'inbox.quote_enabled', 'mode' => 'state']), ['Content-Type: application/json', 'X-Webhook-Token: ' . $inboxToken]);
qe($state['status'] === 200 && $state['json'] === ['ok' => true, 'auto_quote_enabled' => true], 'X-Webhook-Token mode=state läser');
$state2 = $http($base, 'POST', json_encode(['action' => 'inbox.quote_enabled', 'mode' => 'get', 'auto_quote_enabled' => false]), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
qe($state2['json']['auto_quote_enabled'] === true && hash('sha256', (string) file_get_contents($httpData . '/inkorg/settings.json')) === $stateHash, 'mode=get via HTTP skriver inte');

$offHttp = $http($base, 'POST', json_encode(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => false, 'token' => $inboxToken]), ['Content-Type: application/json']);
qe($offHttp['status'] === 200 && $offHttp['json'] === ['ok' => true, 'auto_quote_enabled' => false], 'token i JSON-body slår av');
qe(!str_contains($offHttp['body'], $inboxToken), 'token ekas inte');
$diskOff = json_decode((string) file_get_contents($httpData . '/inkorg/settings.json'), true);
qe(is_array($diskOff) && $diskOff['auto_quote_enabled'] === false && $diskOff['quote_templates']['laddbox_jarfalla'] === $httpSeed['quote_templates']['laddbox_jarfalla'], 'avslag lämnar mallen');

$bad = $http($base, 'POST', json_encode(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => 'maybe']), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
qe($bad['status'] === 422, 'ogiltigt värde är 422');

$getTok = $http($base . '?token=probe', 'GET', null, []);
qe($getTok['status'] === 405, 'query-token på GET är 405');
$getPlain = $http($base, 'GET', null, []);
qe($getPlain['status'] === 200 && $getPlain['json'] === ['ok' => true], 'GET utan token avslöjar inte flaggan');

$logText = is_file($log) ? (string) file_get_contents($log) : '';
qe(!str_contains($logText, $inboxToken), 'PHP-loggen innehåller inte token');

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
echo "inkorg quote_enabled hook: ok\n";
