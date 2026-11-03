<?php

declare(strict_types=1);

/**
 * Inloggningssidan ska renderas när bootstrap och index.php laddas på riktigt.
 *
 * Deployen 2026-10-21 bytte bootstrap.php innan src/test_lead.php fanns.
 * php -l och enhetstester som redan hade filen på disk såg inte HTTP 500.
 * Det här testet kräver kedjan, både utan databas och med en tillfällig sqlite.
 */

$root = dirname(__DIR__);
$mode = (string) getenv('JEAB_BOOT_SMOKE');
if ($mode === 'chain' || $mode === 'page') {
    jeab_boot_smoke_worker($root, $mode);
}

$fail = 0;

function jeab_boot_smoke_check(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$files = [
    'index.php',
    'src/bootstrap.php',
    'src/inkorg.php',
    'src/inkorg_auto.php',
    'src/inkorg_follow.php',
    'src/inkorg_graph.php',
    'src/inkorg_no_email.php',
    'src/omtago.php',
    'src/send_guard_brake.php',
    'src/sms.php',
    'src/test_lead.php',
];
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    $proc = proc_open(
        [PHP_BINARY, '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    $out = is_resource($proc) ? stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]) : '';
    if (is_resource($proc)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    } else {
        $code = 1;
    }
    jeab_boot_smoke_check($code === 0 && str_contains($out, 'No syntax errors'), 'php -l ' . $rel);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'short_open_tag=1', '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    $out = is_resource($proc) ? stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]) : '';
    if (is_resource($proc)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
    } else {
        $code = 1;
    }
    jeab_boot_smoke_check($code === 0 && str_contains($out, 'No syntax errors'), 'php -l short_open_tag ' . $rel);
}

$bootstrap = (string) file_get_contents($root . '/src/bootstrap.php');
$clearAt = strpos($bootstrap, 'clearstatcache(true, $jeabTestLead)');
$requireAt = strpos($bootstrap, "require_once __DIR__ . '/test_lead.php';");
jeab_boot_smoke_check(
    $clearAt !== false && $requireAt !== false && $clearAt < $requireAt,
    'clearstatcache ligger före require av test_lead.php'
);
$dbAt = strpos($bootstrap, "require_once __DIR__ . '/db.php';");
jeab_boot_smoke_check(
    $dbAt !== false && $requireAt !== false && $dbAt < $requireAt,
    'test_lead.php laddas efter db.php'
);

$leadNames = jeab_boot_smoke_functions($root . '/src/test_lead.php');
jeab_boot_smoke_check(in_array('is_test_lead', $leadNames, true), 'is_test_lead finns i test_lead.php');
$dupes = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
        continue;
    }
    $path = $file->getPathname();
    if (realpath($path) === realpath($root . '/src/test_lead.php')) {
        continue;
    }
    foreach (jeab_boot_smoke_functions($path) as $name) {
        if (in_array($name, $leadNames, true)) {
            $dupes[] = $name . ' i ' . substr($path, strlen($root) + 1);
        }
    }
}
jeab_boot_smoke_check($dupes === [], 'inga dubbeldefinierade test_lead-funktioner' . ($dupes === [] ? '' : ': ' . implode(', ', $dupes)));

$phpCgi = jeab_boot_smoke_cgi();
$runs = [
    ['cli', 'chain', PHP_BINARY],
    ['cli', 'page', PHP_BINARY],
];
if ($phpCgi !== null) {
    $runs[] = ['cgi', 'chain', $phpCgi];
    $runs[] = ['cgi', 'page', $phpCgi];
} else {
    jeab_boot_smoke_check(false, 'php-cgi saknas');
}

foreach ($runs as [$sapi, $workerMode, $binary]) {
    [$code, $out, $err] = jeab_boot_smoke_spawn($root, $binary, $sapi, $workerMode);
    $text = $out . $err;
    jeab_boot_smoke_check($code === 0, "{$sapi} {$workerMode} exit {$code}");
    if ($workerMode === 'chain') {
        jeab_boot_smoke_check(str_contains($text, 'chain-ok'), "{$sapi} kedja utan databas");
        jeab_boot_smoke_check(str_contains($text, 'pdo=0'), "{$sapi} öppnar ingen PDO");
    } else {
        jeab_boot_smoke_check(str_contains($text, 'Ange kampanjkod'), "{$sapi} inloggningssida");
        jeab_boot_smoke_check(str_contains($text, 'page-ok'), "{$sapi} sida exit 0");
        jeab_boot_smoke_check(!str_contains($text, 'Failed opening required'), "{$sapi} ingen saknad require");
    }
}

if ($fail > 0) {
    fwrite(STDERR, "boot-login-smoke: {$fail} fel\n");
    exit(1);
}
echo "boot-login-smoke: ok\n";
exit(0);

/**
 * @return list<string>
 */
function jeab_boot_smoke_functions(string $path): array
{
    $code = file_get_contents($path);
    if (!is_string($code)) {
        return [];
    }
    $tokens = token_get_all($code);
    $names = [];
    $depth = 0;
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_FUNCTION && $depth === 0) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                $j++;
            }
            if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $names[] = $tokens[$j][1];
            }
        }
        if ($token === '{') {
            $depth++;
        } elseif ($token === '}') {
            $depth = max(0, $depth - 1);
        }
    }

    return $names;
}

function jeab_boot_smoke_cgi(): ?string
{
    foreach ([PHP_BINDIR . '/php-cgi', '/usr/bin/php-cgi'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }
    $which = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));

    return $which !== '' && is_executable($which) ? $which : null;
}

/**
 * @return array{0:int,1:string,2:string}
 */
function jeab_boot_smoke_spawn(string $root, string $binary, string $sapi, string $mode): array
{
    $tmp = sys_get_temp_dir() . '/jeab-boot-smoke-' . $sapi . '-' . $mode . '-' . getmypid();
    if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
        return [1, '', 'tempkatalog saknas'];
    }
    $public = $tmp . '/public';
    if (!is_dir($public)) {
        mkdir($public, 0700, true);
    }
    $env = [
        'JEAB_BOOT_SMOKE' => $mode,
        'JEAB_BOOT_SMOKE_TMP' => $tmp,
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/index.php',
        'SCRIPT_NAME' => '/index.php',
        'SCRIPT_FILENAME' => $root . '/tests/boot_login_smoke_test.php',
        'SERVER_NAME' => 'jeab.test',
        'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'jeab.test',
        'REDIRECT_STATUS' => '200',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
    ];
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && is_string($value) && !array_key_exists($key, $env) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            $env[$key] = $value;
        }
    }
    $cmd = [$binary];
    if ($sapi === 'cgi') {
        $cmd[] = '-d';
        $cmd[] = 'cgi.force_redirect=0';
        $cmd[] = '-d';
        $cmd[] = 'session.save_path=' . $tmp;
    }
    $cmd[] = $root . '/tests/boot_login_smoke_test.php';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    if (!is_resource($proc)) {
        return [1, '', 'proc_open misslyckades'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), (string) $out, (string) $err];
}

function jeab_boot_smoke_worker(string $root, string $mode): never
{
    $tmp = (string) getenv('JEAB_BOOT_SMOKE_TMP');
    if ($tmp === '' || !is_dir($tmp)) {
        fwrite(STDERR, "FAIL tempkatalog\n");
        exit(1);
    }
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_HOST'] = 'jeab.test';
    $_SERVER['SERVER_NAME'] = 'jeab.test';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_GET = [];
    $_POST = [];

    if ($mode === 'chain') {
        require $root . '/src/bootstrap.php';
        $pdo = function_exists('jeab_pdo_open_count') ? jeab_pdo_open_count() : -1;
        $ok = function_exists('is_test_lead')
            && function_exists('jeab_inkorg_service_labels')
            && function_exists('jeab_inkorg_sales_block_reason')
            && $pdo === 0;
        if (!$ok) {
            fwrite(STDERR, 'FAIL chain sapi=' . PHP_SAPI . ' pdo=' . $pdo . "\n");
            exit(1);
        }
        echo 'chain-ok sapi=' . PHP_SAPI . ' pdo=0' . "\n";
        exit(0);
    }

    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
        ],
    ], true) . ";\n");
    register_shutdown_function(static function (): void {
        $body = '';
        while (ob_get_level() > 0) {
            $chunk = ob_get_clean();
            $body = (is_string($chunk) ? $chunk : '') . $body;
        }
        $code = http_response_code();
        if ($code !== 200 || !str_contains($body, 'Ange kampanjkod')) {
            fwrite(STDERR, 'FAIL page sapi=' . PHP_SAPI . ' http=' . $code . ' body=' . substr($body, 0, 400) . "\n");
            exit(1);
        }
        echo $body . "\npage-ok sapi=" . PHP_SAPI . "\n";
    });
    ob_start();
    require $root . '/index.php';
    fwrite(STDERR, "FAIL index.php återvände utan inloggningssida\n");
    exit(1);
}
