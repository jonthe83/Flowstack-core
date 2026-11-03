<?php

declare(strict_types=1);

/**
 * Graph-kroken via index.php?graph_hook=1.
 * Validering utan bootstrap. Notiser som graph-hook.php.
 * En trasig graph-hook.php får inte fälla inloggningssidan.
 * Tick skapar om prenumerationen när notificationUrl skiljer sig.
 */

$root = dirname(__DIR__);
$mode = (string) getenv('JEAB_GRAPH_HOOK_INDEX');
if ($mode !== '') {
    jeab_ghi_worker($root, $mode);
}

$fail = 0;

function ghi(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

$index = (string) file_get_contents($root . '/index.php');
$gate = strpos($index, "(string) (\$_GET['graph_hook'] ?? '') === '1'");
$shield = strpos($index, "require_once __DIR__ . '/src/shield.php'");
$boot = strpos($index, "require_once __DIR__ . '/src/bootstrap.php'");
$hookRequire = strpos($index, "require \$jeabGraphHook");
ghi($gate !== false && $shield !== false && $boot !== false && $hookRequire !== false, 'index.php har graph_hook-grinden');
ghi($gate < $shield && $gate < $boot && $hookRequire < $shield, 'grinden ligger före shield och bootstrap');
$hookPath = strpos($index, "\$jeabGraphHook = __DIR__ . '/graph-hook.php'");
ghi($hookPath !== false && $hookPath > $gate && $hookPath < $shield, 'graph-hook.php inkluderas bara i grinden');
$shieldSrc = (string) file_get_contents($root . '/src/shield.php');
ghi(!str_contains($shieldSrc, 'graph_hook') && !str_contains($shieldSrc, 'graph-hook.php'), 'shield.php nämner inte graph-kroken');
$hookSrc = (string) file_get_contents($root . '/src/inkorg_graph.php');
ghi(str_contains($hookSrc, "const JEAB_GRAPH_HOOK_URL = 'https://flowstack.example.test/index.php?graph_hook=1';"), 'notificationUrl pekar på index.php');
ghi(str_contains($hookSrc, "jeab_graph_api('DELETE'") && str_contains($hookSrc, 'notificationUrl'), 'annan URL raderas och skapas om');

$hookFile = $root . '/graph-hook.php';
$hookBytes = (string) file_get_contents($hookFile);
register_shutdown_function(static function () use ($hookFile, $hookBytes): void {
    if (is_file($hookFile) && (string) file_get_contents($hookFile) === $hookBytes) {
        return;
    }
    file_put_contents($hookFile, $hookBytes);
});

[$code, $out, $err] = ghi_spawn($root, 'validate', '');
ghi($code === 0 && $out === 'abc tok', 'validering ekar token, fick ' . json_encode($out));
ghi(str_contains($err, 'code=200') && str_contains($err, 'boot=0'), 'validering är 200 utan bootstrap');
ghi(!str_contains($out . $err, 'Ange kampanjkod'), 'validering är inte inloggningssidan');

$plain = ghi_spawn($root, 'login', '');
ghi($plain[0] === 0 && str_contains($plain[1], 'Ange kampanjkod') && str_contains($plain[1], 'page-ok'), 'inloggning med hel graph-hook.php');

file_put_contents($hookFile, "<?php\nthis is not valid php (\n");
try {
    $broken = ghi_spawn($root, 'login', '');
    ghi($broken[0] === 0 && str_contains($broken[1], 'Ange kampanjkod') && str_contains($broken[1], 'page-ok'), 'syntaxfel i graph-hook.php fäller inte inloggningen');
    ghi(!str_contains($broken[1] . $broken[2], 'Failed opening required') && !str_contains($broken[1] . $broken[2], 'syntax error'), 'inloggningen inkluderar inte den trasiga filen');
    $hookReq = ghi_spawn($root, 'validate', '');
    ghi(!str_contains($hookReq[1], 'Ange kampanjkod'), 'graph_hook=1 med trasig fil blir inte inloggningssidan');
} finally {
    file_put_contents($hookFile, $hookBytes);
}
ghi((string) file_get_contents($hookFile) === $hookBytes, 'graph-hook.php är återställd');

$tmp = sys_get_temp_dir() . '/jeab-ghi-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$secret = json_encode([
    'tenant_id' => 't',
    'client_id' => 'c',
    'client_secret' => 's',
    'client_state' => 'cs-test-value-0123456789abcdef',
], JSON_UNESCAPED_SLASHES);
mkdir($tmp . '/secrets', 0700, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/m365.json', $secret);
file_put_contents($tmp . '/inkorg/graph-state.json', json_encode([
    'subs' => ['jarfalla' => ['id' => 'sub-j', 'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400), 'notificationUrl' => 'https://flowstack.example.test/index.php?graph_hook=1']],
]));
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => []]));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => new stdClass()]));

$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($tmp . '/public', true) . ";\n\$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {\n    return ['code' => 404, 'body' => []];\n};\nrequire " . var_export($root . '/index.php', true) . ";\n");
$notePort = 19000 + random_int(0, 800);
$noteProc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $notePort, '-t', $root, $router],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $notePipes,
    $root
);
$noteUp = false;
$noteDeadline = microtime(true) + 5;
while (microtime(true) < $noteDeadline) {
    $probe = @fsockopen('127.0.0.1', $notePort, $errno, $errstr, 0.2);
    if (is_resource($probe)) {
        fclose($probe);
        $noteUp = true;
        break;
    }
    usleep(50000);
}
ghi(is_resource($noteProc) && $noteUp, 'notis-servern lyssnar');
if ($noteUp) {
    $noteCurl = static function (string $url, string $body) use ($notePort): array {
        $ch = curl_init('http://127.0.0.1:' . $notePort . $url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $raw];
    };
    $bad = $noteCurl('/index.php?graph_hook=1', (string) json_encode(['value' => [[
        'clientState' => 'fel',
        'subscriptionId' => 'sub-j',
        'resourceData' => ['id' => 'A1'],
    ]]]));
    ghi($bad[0] === 401 && str_contains($bad[1], '{"ok":false}') && !str_contains($bad[1], 'Ange kampanjkod'), 'fel clientState är 401, fick ' . $bad[0] . ' ' . substr($bad[1], 0, 120));
    $unknown = $noteCurl('/index.php?graph_hook=1', (string) json_encode(['value' => [[
        'clientState' => 'cs-test-value-0123456789abcdef',
        'subscriptionId' => 'okand-prenumeration',
        'resourceData' => ['id' => 'A9'],
    ]]]));
    ghi($unknown[0] === 202 && str_contains($unknown[1], '{"ok":true}') && !str_contains($unknown[1], 'Ange kampanjkod'), 'okänt prenumerations-id är 202, fick ' . $unknown[0] . ' ' . substr($unknown[1], 0, 120));
    $empty = $noteCurl('/index.php?graph_hook=1', '{"value":[]}');
    ghi($empty[0] === 202 && str_contains($empty[1], '{"ok":true}'), 'tom notis är 202, fick ' . $empty[0]);
    $blank = $noteCurl('/index.php?graph_hook=1', '');
    ghi($blank[0] === 400 && str_contains($blank[1], '{"ok":false}') && $blank[0] !== 401, 'tom kropp är 400 och inte 401, fick ' . $blank[0] . ' ' . substr($blank[1], 0, 80));
    $junk = $noteCurl('/index.php?graph_hook=1', '{');
    ghi($junk[0] === 400 && str_contains($junk[1], '{"ok":false}'), 'oläsbar json är 400, fick ' . $junk[0]);
    $hookState = null;
    if (is_file($tmp . '/app.sqlite')) {
        try {
            $hookPdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
            $hookRaw = $hookPdo->query("SELECT payload FROM graph_state WHERE doc_key = 'graph_state'")->fetchColumn();
            $hookPdo = null;
            $hookState = is_string($hookRaw) ? json_decode($hookRaw, true) : null;
        } catch (Throwable) {
            $hookState = null;
        }
    }
    if (!is_array($hookState)) {
        $hookState = json_decode((string) @file_get_contents($tmp . '/inkorg/graph-state.json'), true);
    }
    ghi(is_array($hookState) && !empty($hookState['last_hook']['unreadable']) && (string) ($hookState['last_hook']['at'] ?? '') !== '', 'oläsbar kropp stämplar last_hook');
    $good = $noteCurl('/index.php?graph_hook=1', (string) json_encode(['value' => [[
        'clientState' => 'cs-test-value-0123456789abcdef',
        'subscriptionId' => 'sub-j',
        'resourceData' => ['id' => 'A1'],
    ]]]));
    ghi($good[0] === 202 && str_contains($good[1], '{"ok":true}') && !str_contains($good[1], 'Ange kampanjkod'), 'rätt clientState är 202, fick ' . $good[0] . ' ' . substr($good[1], 0, 160));
}
if (is_resource($noteProc)) {
    $status = proc_get_status($noteProc);
    if (!empty($status['pid'])) {
        proc_terminate($noteProc);
    }
    fclose($notePipes[1]);
    fclose($notePipes[2]);
    proc_close($noteProc);
}

$port = 18080 + random_int(0, 800);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $serverPipes,
    $root
);
ghi(is_resource($server), 'php -S startar');
$curlOk = false;
if (is_resource($server)) {
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($probe)) {
            fclose($probe);
            $curlOk = true;
            break;
        }
        usleep(50000);
    }
    ghi($curlOk, 'php -S lyssnar');
    $cases = [
        ['POST', '/index.php?graph_hook=1&validationToken=abc', ''],
        ['GET', '/index.php?graph_hook=1&validationToken=abc', ''],
        ['POST', '/?graph_hook=1&validationToken=abc', 'Mozilla/5.0'],
        ['POST', '/graph-hook.php?validationToken=abc', ''],
    ];
    foreach ($cases as [$method, $path, $ua]) {
        $t0 = microtime(true);
        $ch = curl_init('http://127.0.0.1:' . $port . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = '';
        }
        curl_setopt_array($ch, $opts);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $elapsed = microtime(true) - $t0;
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        ghi($status === 200 && $body === 'abc' && stripos($headers, 'text/plain') !== false && $elapsed < 10, "{$method} {$path} UA=" . ($ua === '' ? 'tom' : $ua) . " är 200 text/plain på " . round($elapsed, 3) . 's');
    }
    $status = proc_get_status($server);
    if (!empty($status['pid'])) {
        proc_terminate($server);
    }
    fclose($serverPipes[1]);
    fclose($serverPipes[2]);
    proc_close($server);
}

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
require $root . '/src/bootstrap.php';

$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$calls): array {
    $calls[] = [$method, $url, $body];
    if ($method === 'POST' && str_contains($url, '/subscriptions')) {
        return ['code' => 201, 'body' => ['id' => 'sub-new', 'expirationDateTime' => (string) ($body['expirationDateTime'] ?? '')]];
    }
    if ($method === 'PATCH' && str_contains($url, '/subscriptions/')) {
        return ['code' => 200, 'body' => ['expirationDateTime' => (string) ($body['expirationDateTime'] ?? '')]];
    }
    if ($method === 'DELETE' && str_contains($url, '/subscriptions/')) {
        return ['code' => 204, 'body' => []];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
jeab_graph_state_update(static function (array &$d): bool {
    $d['subs'] = [];

    return true;
});
$created = jeab_graph_subscriptions_ensure();
$post = null;
foreach ($calls as $call) {
    if ($call[0] === 'POST' && str_contains($call[1], '/subscriptions')) {
        $post = $call[2];
    }
}
ghi(is_array($post) && ($post['notificationUrl'] ?? '') === 'https://flowstack.example.test/index.php?graph_hook=1', 'ny prenumeration får index.php-URL');
ghi(!empty($created['ok']) && (jeab_graph_state()['subs']['jarfalla']['notificationUrl'] ?? '') === JEAB_GRAPH_HOOK_URL, 'URL sparas på prenumerationen');
ghi(jeab_graph_subs_need_renew() === false, 'färsk prenumeration med rätt URL förnyas inte');

jeab_graph_state_update(static function (array &$d): bool {
    $d['subs']['jarfalla']['notificationUrl'] = 'https://flowstack.example.test/graph-hook.php';
    $d['subs']['jarfalla']['expires'] = gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400);
    unset($d['subs']['jarfalla']['error'], $d['subs']['jarfalla']['error_at']);

    return true;
});
ghi(jeab_graph_subs_need_renew() === true, 'tick ser att URL:en skiljer sig');
$calls = [];
$rebuilt = jeab_graph_subscriptions_ensure();
$methods = array_map(static fn (array $call): string => $call[0], $calls);
ghi(in_array('DELETE', $methods, true) && in_array('POST', $methods, true) && !in_array('PATCH', $methods, true), 'gammal URL raderas och skapas om, inte PATCH');
$postedUrl = '';
foreach ($calls as $call) {
    if ($call[0] === 'POST') {
        $postedUrl = (string) ($call[2]['notificationUrl'] ?? '');
    }
}
ghi($postedUrl === JEAB_GRAPH_HOOK_URL && !empty($rebuilt['ok']), 'omskapad prenumeration pekar på den nya URL:en');
ghi((jeab_graph_state()['subs']['jarfalla']['notificationUrl'] ?? '') === JEAB_GRAPH_HOOK_URL, 'ny URL ersätter den gamla i tillståndet');
ghi(jeab_graph_subs_need_renew() === false, 'efter omskapandet behövs ingen ny omgång');

$calls = [];
jeab_graph_subscriptions_ensure();
$methods = array_map(static fn (array $call): string => $call[0], $calls);
ghi($methods === ['PATCH'], 'samma URL förnyas med PATCH, fick ' . implode(',', $methods));

echo $fail === 0 ? "klart\n" : "{$fail} fel\n";
exit($fail > 0 ? 1 : 0);

/**
 * @return array{0:int,1:string,2:string}
 */
function ghi_spawn(string $root, string $mode, string $tmp, string $stdin = ''): array
{
    $cmd = [PHP_BINARY, $root . '/tests/graph_hook_index_test.php'];
    $env = [
        'JEAB_GRAPH_HOOK_INDEX' => $mode,
        'JEAB_GHI_TMP' => $tmp,
    ];
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && is_string($value) && !array_key_exists($key, $env) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            $env[$key] = $value;
        }
    }
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($proc)) {
        return [1, '', 'proc_open misslyckades'];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out, (string) $err];
}

function jeab_ghi_worker(string $root, string $mode): never
{
    $tmp = (string) getenv('JEAB_GHI_TMP');
    if ($tmp !== '') {
        $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
        $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
        putenv('JEAB_DISABLE_SQLITE=1');
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_HOST'] = 'jeab.test';
    $_SERVER['SERVER_NAME'] = 'jeab.test';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    if ($mode === 'validate') {
        $_GET = ['graph_hook' => '1', 'validationToken' => 'abc tok'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?graph_hook=1&validationToken=abc%20tok';
        register_shutdown_function(static function (): void {
            $code = http_response_code();
            $boot = function_exists('jeab_boot') ? '1' : '0';
            fwrite(STDERR, "MARK code={$code} boot={$boot}\n");
        });
        require $root . '/index.php';
        fwrite(STDERR, "MARK noexit\n");
        exit(2);
    }

    if ($mode === 'login') {
        if ($tmp === '' || !is_dir($tmp)) {
            $tmp = sys_get_temp_dir() . '/jeab-ghi-login-' . bin2hex(random_bytes(3));
            mkdir($tmp, 0700, true);
            $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
            $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
        }
        file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
            'db' => [
                'driver' => 'sqlite',
                'sqlite_path' => $tmp . '/app.sqlite',
                'json_path' => $tmp . '/store.json',
            ],
        ], true) . ";\n");
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php';
        register_shutdown_function(static function (): void {
            $body = '';
            while (ob_get_level() > 0) {
                $chunk = ob_get_clean();
                $body = (is_string($chunk) ? $chunk : '') . $body;
            }
            $code = http_response_code();
            if ($code !== 200 || !str_contains($body, 'Ange kampanjkod')) {
                fwrite(STDERR, 'FAIL page http=' . $code . ' body=' . substr($body, 0, 400) . "\n");
                exit(1);
            }
            echo $body . "\npage-ok\n";
        });
        ob_start();
        require $root . '/index.php';
        fwrite(STDERR, "FAIL index.php återvände utan inloggningssida\n");
        exit(1);
    }

    fwrite(STDERR, "okänt läge\n");
    exit(1);
}
