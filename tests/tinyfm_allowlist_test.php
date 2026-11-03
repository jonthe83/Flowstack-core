<?php

declare(strict_types=1);

/**
 * TinyFM IP-lås: tillåt, neka, kommentarer, /32, och HTTP 403 före kampanjrutan.
 */

require dirname(__DIR__) . '/src/tinyfm_allow.php';

function tinyfm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$example = $root . '/data/.private/tinyfm_allowlist.txt.example';
$tracked = $root . '/data/.private/tinyfm_allowlist.txt';
$tmp = sys_get_temp_dir() . '/jeab-tinyfm-allow-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}

tinyfm_assert(is_file($example), 'exempelfilen finns i repot');
tinyfm_assert(!is_file($tracked), 'skarpa listan ligger inte i repot');
$seed = (string) file_get_contents($example);
tinyfm_assert(str_contains($seed, '203.0.113.10/32'), 'exemplet har en dokumentations-/32');
tinyfm_assert(str_contains($seed, '203.0.113.0/24'), 'exemplet har en dokumentations-/24');
tinyfm_assert(str_contains($seed, '198.51.100.20/32'), 'exemplet har en andra dokumentationsadress');
tinyfm_assert(!preg_match('/\b(?:81\.237\.|140\.248\.|44\.207\.|100\.63\.|104\.30\.)/', $seed), 'exemplet har inga skarpa adresser');
foreach ([
    '203.0.113.10',
    '203.0.113.50',
    '203.0.113.0',
    '203.0.113.255',
    '198.51.100.20',
] as $ip) {
    tinyfm_assert(jeab_tinyfm_ip_allowed($ip, $example), "exemplet släpper in {$ip}");
}
tinyfm_assert(!jeab_tinyfm_ip_allowed('203.0.114.10', $example), '/24 släpper inte grannnätet');
tinyfm_assert(!jeab_tinyfm_ip_allowed('198.51.100.21', $example), '/32 släpper inte grannen');
tinyfm_assert(!jeab_tinyfm_ip_allowed('192.0.2.10', $example), 'okänd dokumentationsadress nekas');
tinyfm_assert(!jeab_tinyfm_ip_allowed('203.0.113.9', $tmp . '/saknas.txt'), 'saknad lista fail-closed');
tinyfm_assert(!jeab_tinyfm_ip_allowed('', $example), 'tom klientadress nekas');
tinyfm_assert(!jeab_tinyfm_ip_allowed('inte-en-ip', $example), 'ogiltig klientadress nekas');

$list = $tmp . '/list.txt';
file_put_contents($list, "# kommentar\n\n203.0.113.10/32 # dokumentation\n\n10.1.2.0/24\n");
tinyfm_assert(jeab_tinyfm_ip_allowed('203.0.113.10', $list), 'kommentarer och tomrader hindrar inte /32');
tinyfm_assert(!jeab_tinyfm_ip_allowed('203.0.113.11', $list), '/32 matchar inte grannen');
tinyfm_assert(jeab_tinyfm_ip_allowed('10.1.2.9', $list), '/24 matchar inne i nätet');
tinyfm_assert(!jeab_tinyfm_ip_allowed('10.1.3.9', $list), '/24 matchar inte grannnätet');

file_put_contents($list, "\r\n# bara kommentar\r\n\r\n203.0.113.10\r\n");
tinyfm_assert(jeab_tinyfm_ip_allowed('203.0.113.10', $list), 'naket IP tolkas som /32 och CRLF är ok');
tinyfm_assert(!jeab_tinyfm_ip_allowed('203.0.113.9', $list), 'naket /32 släpper inte grannen');

file_put_contents($list, "#\nnot a cidr\n203.0.113.10/33\n203.0.113.10/32\n");
tinyfm_assert(jeab_tinyfm_ip_allowed('203.0.113.10', $list), 'trasiga rader ger ingen träff men giltig /32 gör det');
tinyfm_assert(!jeab_tinyfm_ip_in_cidr('203.0.113.10', '203.0.113.10/33'), '/33 är ogiltig');
tinyfm_assert(jeab_tinyfm_ip_in_cidr('2001:db8::1', '2001:db8::/32'), 'IPv6-prefix matchar');
tinyfm_assert(!jeab_tinyfm_ip_in_cidr('2001:db9::1', '2001:db8::/32'), 'IPv6 utanför prefix nekas');
tinyfm_assert(jeab_tinyfm_ip_in_cidr('::ffff:203.0.113.10', '203.0.113.10/32'), 'IPv4-mappad adress matchar /32');

file_put_contents($list, str_repeat('a', JEAB_TINYFM_ALLOWLIST_MAX_BYTES + 1));
clearstatcache(true, $list);
tinyfm_assert(filesize($list) > JEAB_TINYFM_ALLOWLIST_MAX_BYTES, 'testfilen är över taket');
tinyfm_assert(!jeab_tinyfm_ip_allowed('203.0.113.10', $list), 'för stor lista fail-closed');

$public = [
    'REMOTE_ADDR' => '192.0.2.9',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
];
tinyfm_assert(jeab_tinyfm_client_ip($public) === '192.0.2.9', 'publikt REMOTE_ADDR vinner över X-Forwarded-For');
tinyfm_assert(!jeab_tinyfm_ip_allowed(jeab_tinyfm_client_ip($public), $example), 'spoofad X-Forwarded-For öppnar inte låset');

$proxy = [
    'REMOTE_ADDR' => '10.0.0.4',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 192.0.2.9',
];
tinyfm_assert(jeab_tinyfm_client_ip($proxy) === '192.0.2.9', 'bakom privat proxy används högra publika hoppet');
tinyfm_assert(!jeab_tinyfm_ip_allowed(jeab_tinyfm_client_ip($proxy), $example), 'vänster spoof bakom proxy nekas');

$proxyHome = [
    'REMOTE_ADDR' => '192.168.0.2',
    'HTTP_X_FORWARDED_FOR' => '10.1.1.1, 203.0.113.10',
];
tinyfm_assert(jeab_tinyfm_client_ip($proxyHome) === '203.0.113.10', 'högra hoppet bakom proxy kan vara en tillåten adress');
tinyfm_assert(jeab_tinyfm_ip_allowed(jeab_tinyfm_client_ip($proxyHome), $example), 'tillåten adress via proxy-hopp släpps in');

$mapped = ['REMOTE_ADDR' => '::ffff:203.0.113.10'];
tinyfm_assert(jeab_tinyfm_client_ip($mapped) === '203.0.113.10', 'IPv4-mappad REMOTE_ADDR normaliseras');

tinyfm_assert(jeab_tinyfm_client_ip(['REMOTE_ADDR' => '']) === '', 'saknat REMOTE_ADDR litar inte på X-Forwarded-For');
tinyfm_assert(
    jeab_tinyfm_client_ip(['REMOTE_ADDR' => '', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10']) === '',
    'X-Forwarded-For ensam öppnar inte låset'
);

$savedAllow = $GLOBALS['_JEAB_TINYFM_ALLOWLIST'] ?? null;
$savedData = $GLOBALS['_JEAB_DATA_DIR'] ?? null;
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
unset($GLOBALS['_JEAB_TINYFM_ALLOWLIST']);
tinyfm_assert(
    jeab_tinyfm_allowlist_path() === $tmp . '/tinyfm_allowlist.txt',
    'testsökväg följer data-katalogen'
);
unset($GLOBALS['_JEAB_DATA_DIR']);
tinyfm_assert(
    jeab_tinyfm_allowlist_path() === $root . '/data/.private/tinyfm_allowlist.txt',
    'skarpa sökvägen är data/.private/tinyfm_allowlist.txt'
);
$GLOBALS['_JEAB_TINYFM_ALLOWLIST'] = $list;
tinyfm_assert(jeab_tinyfm_allowlist_path() === $list, 'explicit listsökväg vinner');
if ($savedAllow === null) {
    unset($GLOBALS['_JEAB_TINYFM_ALLOWLIST']);
} else {
    $GLOBALS['_JEAB_TINYFM_ALLOWLIST'] = $savedAllow;
}
if (is_string($savedData)) {
    $GLOBALS['_JEAB_DATA_DIR'] = $savedData;
}

$boot = (string) file_get_contents($root . '/src/filer_boot.php');
$guardAt = strpos($boot, 'jeab_tinyfm_guard()');
$gateAt = strpos($boot, 'jeab_gate()');
tinyfm_assert($guardAt !== false && $gateAt !== false && $guardAt < $gateAt, 'låset körs före kampanjgrinden');
tinyfm_assert(str_contains($boot, "PHP_SAPI !== 'cli'"), 'CLI-tester startar inte grinden via låset');
$index = (string) file_get_contents($root . '/index.php');
tinyfm_assert(!str_contains($index, 'jeab_tinyfm'), 'index.php använder inte TinyFM-låset');
tinyfm_assert(!str_contains((string) file_get_contents($root . '/src/tinyfm_allow.php'), 'HTTP_X_JEAB_TEST'), 'produktionen läser inte testrubriken');

$port = 8097;
$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
declare(strict_types=1);
if (getenv('JEAB_TEST_ROUTER') !== '1') {
    http_response_code(500);
    echo "router off\n";
    return true;
}
$client = trim((string) ($_SERVER['HTTP_X_JEAB_TEST_CLIENT'] ?? ''));
if ($client !== '') {
    $_SERVER['REMOTE_ADDR'] = $client;
}
$allow = trim((string) ($_SERVER['HTTP_X_JEAB_TEST_ALLOWLIST'] ?? ''));
if ($allow === '') {
    $allow = (string) getenv('JEAB_TEST_ALLOWLIST');
}
if ($allow !== '') {
    $GLOBALS['_JEAB_TINYFM_ALLOWLIST'] = $allow;
}
$root = (string) getenv('JEAB_APP_ROOT');
$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if ($uri === '/index.php') {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    require $root . '/index.php';
    return true;
}
if ($uri === '/filer.php') {
    $_SERVER['SCRIPT_NAME'] = '/filer.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/filer.php';
    require $root . '/src/filer_boot.php';
    return true;
}
http_response_code(404);
echo "missing\n";
return true;
PHP);

$log = $tmp . '/server.log';
$cmd = 'env JEAB_TEST_ROUTER=1 '
    . 'JEAB_TEST_ALLOWLIST=' . escapeshellarg($example) . ' '
    . 'JEAB_APP_ROOT=' . escapeshellarg($root) . ' '
    . 'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router)
    . ' > ' . escapeshellarg($log) . ' 2>&1';
$proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
tinyfm_assert(is_resource($proc), 'lokal webbserver startar');
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
tinyfm_assert($up, 'lokal webbserver lyssnar');

$http = static function (string $url, array $headers) use ($port): array {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $match) === 1) {
            $status = (int) $match[1];
        }
    }
    return [$status, (string) $body];
};

[$denyStatus, $denyBody] = $http('/filer.php', ['X-Jeab-Test-Client: 192.0.2.9']);
tinyfm_assert($denyStatus === 403, "nekad IP får HTTP {$denyStatus}, väntat 403");
tinyfm_assert(trim($denyBody) === 'Forbidden', 'nekad IP får ingen inloggningsruta');
tinyfm_assert(!str_contains($denyBody, 'kampanjkod'), 'nekad kropp saknar kampanjrutan');

[$spoofStatus, $spoofBody] = $http('/filer.php', [
    'X-Jeab-Test-Client: 192.0.2.9',
    'X-Forwarded-For: 203.0.113.10',
]);
tinyfm_assert($spoofStatus === 403 && trim($spoofBody) === 'Forbidden', 'spoofad X-Forwarded-For mot publik REMOTE_ADDR är 403');

[$allowStatus, $allowBody] = $http('/filer.php', ['X-Jeab-Test-Client: 203.0.113.10']);
tinyfm_assert($allowStatus === 200, "tillåten IP får HTTP {$allowStatus}, väntat 200");
tinyfm_assert(str_contains($allowBody, 'Ange kampanjkod'), 'tillåten IP når kampanjrutan framför TinyFM');

foreach (['203.0.113.50', '198.51.100.20'] as $boxIp) {
    [$boxStatus, $boxBody] = $http('/filer.php', ['X-Jeab-Test-Client: ' . $boxIp]);
    tinyfm_assert($boxStatus === 200, "exempel-IP {$boxIp} får HTTP {$boxStatus}, väntat 200");
    tinyfm_assert(str_contains($boxBody, 'Ange kampanjkod'), "exempel-IP {$boxIp} når kampanjrutan");
}
[$outsideStatus, $outsideBody] = $http('/filer.php', ['X-Jeab-Test-Client: 203.0.114.10']);
tinyfm_assert($outsideStatus === 403 && trim($outsideBody) === 'Forbidden', '203.0.114.10 utanför /24 är 403');

[$missStatus, $missBody] = $http('/filer.php', [
    'X-Jeab-Test-Client: 203.0.113.10',
    'X-Jeab-Test-Allowlist: ' . $tmp . '/finns-inte.txt',
]);
tinyfm_assert($missStatus === 403 && trim($missBody) === 'Forbidden', 'saknad lista är 403 även för en adress i exemplet');

[$indexStatus, $indexBody] = $http('/index.php', ['X-Jeab-Test-Client: 192.0.2.9']);
tinyfm_assert($indexStatus === 200, "index.php med nekad TinyFM-IP är HTTP {$indexStatus}, väntat 200");
tinyfm_assert(str_contains($indexBody, 'Ange kampanjkod'), 'index.php visar kampanjrutan oberoende av TinyFM-listan');

proc_terminate($proc);
proc_close($proc);

echo "tinyfm allowlist: ok\n";
