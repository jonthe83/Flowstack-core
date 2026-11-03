<?php

declare(strict_types=1);

/** inbox.asset_hash: sha256 och storlek, aldrig byte. Samma token som inbox.gp_probe. */

$tmp = sys_get_temp_dir() . '/jeab-asset-hash-' . bin2hex(random_bytes(4));
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

function ah(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
ah(str_contains($hookSrc, "case 'inbox.asset_hash':"), 'åtgärden finns bredvid gp_probe');
$rf = new ReflectionFunction('jeab_auto_asset_hash');
$lines = file($rf->getFileName());
$fnSrc = implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
ah(!str_contains($fnSrc, 'file_put_contents') && !str_contains($fnSrc, 'base64_encode'), 'hooken skriver inte och kodar inte innehållet');

$marker = 'ASSET-SECRET-BYTES-' . bin2hex(random_bytes(8));
$pdf = "%PDF-1.4 {$marker}";
$jpeg = "\xFF\xD8" . $marker;
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode($pdf)]);
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode($jpeg)]);
$pdfPath = jeab_auto_pdf_path('batteri_jarfalla');
$imgPath = jeab_auto_team_image_path('jarfalla');
$pdfHash = hash('sha256', (string) file_get_contents($pdfPath));
$imgHash = hash('sha256', (string) file_get_contents($imgPath));
$beforePdf = file_get_contents($pdfPath);
$beforeImg = file_get_contents($imgPath);

$one = jeab_inkorg_hook([
    'action' => 'inbox.asset_hash',
    'key' => 'batteri_jarfalla',
    'data' => base64_encode('OVERWRITE-BYTES'),
    'name' => 'hack.pdf',
]);
ah($one === ['ok' => true, 'assets' => ['batteri_jarfalla' => ['sha256' => $pdfHash, 'bytes' => strlen($pdf)]]], 'en nyckel ger sha256 och byte');
ah(file_get_contents($pdfPath) === $beforePdf && file_get_contents($imgPath) === $beforeImg, 'data i anropet skriver inte om filen');

$both = jeab_auto_asset_hash(['keys' => ['team_jarfalla', 'batteri_jarfalla']]);
ah($both['ok'] === true
    && $both['assets']['team_jarfalla'] === ['sha256' => $imgHash, 'bytes' => strlen($jpeg)]
    && $both['assets']['batteri_jarfalla']['sha256'] === $pdfHash, 'team_jarfalla och batteri_jarfalla');
$encoded = json_encode($both, JSON_UNESCAPED_UNICODE);
ah(is_string($encoded) && !str_contains($encoded, $marker) && !str_contains($encoded, 'OVERWRITE') && !str_contains($encoded, 'content') && !str_contains((string) json_encode($one), $marker), 'svaret innehåller inte filens byte');

$missing = jeab_auto_asset_hash(['key' => 'sol_jarfalla']);
ah($missing === ['ok' => true, 'assets' => ['sol_jarfalla' => ['sha256' => null, 'bytes' => 0]]], 'saknad fil är noll byte och ingen hash');

foreach (['../secrets/x', 'team_jarfalla/../../etc/passwd', 'batteri_jarfalla.pdf', ['batteri_jarfalla']] as $badKey) {
    $r = jeab_auto_asset_hash(['key' => $badKey]);
    ah($r === ['ok' => false, 'error' => 'okänd tillgång'], 'okänd nyckel nekas: ' . json_encode($badKey));
}
ah(jeab_auto_asset_hash(['keys' => 'batteri_jarfalla']) === ['ok' => false, 'error' => 'okänd tillgång'], 'keys måste vara en lista');
ah(jeab_auto_asset_hash(['keys' => ['batteri_jarfalla', 'nope']])['ok'] === false, 'en okänd i listan nekar hela anropet');

$all = jeab_auto_asset_hash([]);
ah(($all['ok'] ?? false) === true && isset($all['assets']['batteri_jarfalla'], $all['assets']['team_jarfalla'], $all['assets']['laddbox_jarfalla'], $all['assets']['team_malmo']), 'utan nyckel listas kända tillgångar');
ah(!str_contains((string) json_encode($all), $marker), 'hela listan läcker inte byte');

// HTTP: samma tokenkanaler som inbox.gp_probe.
$httpRoot = sys_get_temp_dir() . '/jeab-asset-hash-http-' . bin2hex(random_bytes(4));
$httpData = $httpRoot . '/data';
$httpEmpty = $httpRoot . '/empty';
mkdir($httpData . '/inkorg/offert', 0775, true);
mkdir($httpData . '/secrets', 0775, true);
mkdir($httpData . '/public', 0775, true);
mkdir($httpEmpty, 0775, true);
$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents($httpData . '/secrets/inkorg-ingest.json', json_encode(['key' => $inboxToken]));
file_put_contents($httpData . '/inkorg/offert/batteri_jarfalla.pdf', $pdf);
file_put_contents($httpData . '/inkorg/offert/team-jarfalla.jpg', $jpeg);
file_put_contents($httpData . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $httpData . '/app.sqlite', 'json_path' => $httpData . '/store.json'],
], true) . ";\n");
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_ASSET_DATA');
$root = getenv('JEAB_ASSET_ROOT');
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
    ah(false, 'kunde inte välja port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
ah($port > 0, 'port vald');
$php = PHP_BINARY;
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_ASSET_DATA=' . escapeshellarg($httpData)
    . ' JEAB_ASSET_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg($php)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
ah(is_resource($proc), 'lokal PHP-server startade');
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
ah($up, 'testservern lyssnar');

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

$deny = $http($base, 'POST', json_encode(['action' => 'inbox.asset_hash', 'key' => 'batteri_jarfalla']), ['Content-Type: application/json']);
ah($deny['status'] === 401 && ($deny['json']['error'] ?? '') === 'Fel token.', 'utan token blir det 401');

$payload = json_encode(['action' => 'inbox.asset_hash', 'key' => 'batteri_jarfalla', 'data' => base64_encode($marker), 'token' => 'should-not-echo']);
$on = $http($base, 'POST', $payload, ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
ah($on['status'] === 200 && $on['json'] === [
    'ok' => true,
    'assets' => ['batteri_jarfalla' => ['sha256' => $pdfHash, 'bytes' => strlen($pdf)]],
], 'Bearer ger hash utan innehåll');
ah(!str_contains($on['body'], $marker) && !str_contains($on['body'], 'should-not-echo'), 'HTTP-svaret har varken byte eller token');

$head = $http($base, 'POST', json_encode(['action' => 'inbox.asset_hash', 'key' => 'team_jarfalla']), ['Content-Type: application/json', 'X-Webhook-Token: ' . $inboxToken]);
ah($head['status'] === 200 && ($head['json']['assets']['team_jarfalla']['sha256'] ?? '') === $imgHash && ($head['json']['assets']['team_jarfalla']['bytes'] ?? -1) === strlen($jpeg), 'X-Webhook-Token fungerar som för gp_probe');
ah(!str_contains($head['body'], $marker), 'teambildens byte kommer inte med');

$bad = $http($base, 'POST', json_encode(['action' => 'inbox.asset_hash', 'key' => '../../etc/passwd']), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
ah($bad['status'] === 400 && ($bad['json']['error'] ?? '') === 'okänd tillgång', 'sökväg utanför tillgångarna nekas');

$getTok = $http($base . '?token=probe', 'GET', null, []);
ah($getTok['status'] === 405, 'query-token på GET är 405');
$getPlain = $http($base, 'GET', null, []);
ah($getPlain['status'] === 200 && $getPlain['json'] === ['ok' => true], 'GET utan token avslöjar inte hash');

$logText = is_file($log) ? (string) file_get_contents($log) : '';
ah(!str_contains($logText, $inboxToken) && !str_contains($logText, $marker), 'PHP-loggen innehåller varken token eller filbyte');

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
echo "inkorg asset_hash: ok\n";
