<?php

declare(strict_types=1);

/**
 * csrf utan databas, en PDO efter släpp, schema en gång,
 * kort 1203-backoff bara när anslutningen öppnas, tick och sms-cron delar inte låset.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-csrf-conn-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
$hash = password_hash('kod', PASSWORD_DEFAULT);
$email = 'ada@example.com';
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'csrf-conn-secret',
    'hashes' => [
        ['hash' => $hash, 'email' => $email],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function conn_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

conn_ok(jeab_pdo_base_options()[PDO::ATTR_PERSISTENT] === false, 'PDO är inte persistent');

$first = jeab_pdo_connect_sqlite();
conn_ok($first instanceof PDO, 'första anslutningen');
conn_ok((int) ($GLOBALS['_JEAB_MIGRATE_RUNS'] ?? 0) === 1, 'schemat körs en gång, fick ' . (int) ($GLOBALS['_JEAB_MIGRATE_RUNS'] ?? 0));
jeab_pdo_release();
$second = jeab_pdo_connect_sqlite();
conn_ok($second instanceof PDO, 'andra anslutningen');
conn_ok((int) ($GLOBALS['_JEAB_MIGRATE_RUNS'] ?? 0) === 1, 'sparad schema-stämpel hoppar över migrationen');
@unlink(jeab_pdo_schema_mark_path('sqlite'));
jeab_pdo_release();
jeab_pdo_connect_sqlite();
conn_ok((int) ($GLOBALS['_JEAB_MIGRATE_RUNS'] ?? 0) === 2, 'borttagen stämpel kör migrationen igen');

$store = jeab_store();
$pinned = $store->pdo();
$opens = jeab_pdo_open_count();
jeab_pdo_release_if_idle();
$again = jeab_store();
conn_ok($again === $store, 'släppet behåller samma butik');
$fresh = $again->pdo();
conn_ok(jeab_pdo_live_count() === 1, 'en levande anslutning efter släpp, fick ' . jeab_pdo_live_count());
conn_ok(jeab_pdo_open_count() === $opens + 1, 'en ny öppning, inte två, fick ' . jeab_pdo_open_count());
conn_ok(spl_object_id($fresh) !== spl_object_id($pinned), 'den gamla PDO:n återanvänds inte');
unset($pinned, $fresh);

$_GET = ['p' => 'csrf'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
conn_ok(jeab_csrf_refresh_request(), 'GET p=csrf är förnyelsen');
$before = jeab_pdo_open_count();
jeab_boot_session();
conn_ok(jeab_pdo_open_count() === $before, 'sessionen öppnar ingen databas');
conn_ok(jeab_request_skips_send_guard(), 'csrf startar inte utskickskön');
$_GET = ['p' => 'bookings', 'intent' => 'calendar-outlook'];
conn_ok(jeab_request_skips_send_guard(), 'kalendervågen startar inte utskickskön');
$_GET = ['p' => 'reminders', 'intent' => 'sync'];
conn_ok(jeab_request_skips_send_guard(), 'påminnelse-poll startar inte utskickskön');
$_GET = ['p' => 'inkorg', 'ix_part' => 'counts'];
$_SERVER['REQUEST_METHOD'] = 'GET';
conn_ok(jeab_request_skips_send_guard(), 'inkorgens räknare startar inte utskickskön');
$_GET = ['p' => 'ordrar'];
unset($_GET['ix_part'], $_GET['intent'], $_GET['format']);
$_SERVER['HTTP_ACCEPT'] = 'text/html';
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
conn_ok(!jeab_request_skips_send_guard(), 'en ordervy får fortfarande beta av kön');

$layout = (string) file_get_contents($root . '/src/layout.php');
conn_ok(str_contains($layout, 'jeabPollClaim') && str_contains($layout, '15 * 60 * 1000'), 'csrf pollas av en flik var 15:e minut');
$graph = (string) file_get_contents($root . '/src/inkorg_graph.php');
$grok = (string) file_get_contents($root . '/src/inkorg.php');
conn_ok(str_contains($graph, 'jeab_graph_read_may_release') && str_contains($graph, "['GET', 'HEAD']"), 'Graph släpper bara före en läsning');
$classifyAt = strpos($grok, 'function jeab_inkorg_llm_classify');
$classifyEnd = strpos($grok, 'function jeab_inkorg_classify');
$classify = $classifyAt !== false && $classifyEnd !== false ? substr($grok, $classifyAt, $classifyEnd - $classifyAt) : '';
conn_ok($classify !== '' && !str_contains($classify, 'jeab_pdo_release_if_idle'), 'Grok-klassning håller anslutningen, etiketten sparas efteråt');
$cal = (string) file_get_contents($root . '/src/bookings.php');
conn_ok(!str_contains($cal, 'jeab_pdo_release_if_idle'), 'kalenderns Outlook-våg släpper inte före Graph');
$guard = (string) file_get_contents($root . '/src/send_guard_brake.php');
conn_ok(str_contains($guard, 'function jeab_send_guard_claim') && str_contains($guard, 'claim_token') && str_contains($guard, "GET_LOCK('jeab_send_guard'"), 'utskickskön tar raden med claim_token och låser med GET_LOCK');
$dbSrc = (string) file_get_contents($root . '/src/db.php');
conn_ok(!str_contains($dbSrc, 'KILL ') && !str_contains($dbSrc, 'jeab_pdo_drop_connection'), 'släppet dödar inte anslutningen');
$app = (string) file_get_contents($root . '/assets/js/app.js');
conn_ok(str_contains($app, 'paintBookings.then'), 'kalendern hämtar bokningar och sedan Outlook, inte samtidigt');

jeab_pdo_release();
$GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 60000;
$GLOBALS['_JEAB_PDO_BG_BUDGET_US'] = 120000;
$GLOBALS['_JEAB_PDO_WAITED_US'] = 0;
$tries = 0;
$GLOBALS['_JEAB_PDO_FACTORY'] = static function () use (&$tries): void {
    $tries++;
    throw new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);
};
$caught = false;
try {
    jeab_pdo_open_once('sqlite::memory:', null, null, jeab_pdo_base_options());
} catch (JeabDbBusyException) {
    $caught = true;
}
$waited = (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0);
unset($GLOBALS['_JEAB_PDO_FACTORY'], $GLOBALS['_JEAB_PDO_BACKGROUND']);
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
conn_ok($caught && $tries === 3, 'bakgrund försöker 1203 tre gånger, fick ' . $tries);
conn_ok($waited > 0 && $waited <= 120000, 'bakgrundens backoff är högst 120 ms, sov ' . $waited);

conn_ok(jeab_job_overlap_acquire(), 'första processen tar cron-låset');
$childCode = <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[1];
require $argv[2] . '/src/bootstrap.php';
fwrite(STDOUT, jeab_job_overlap_acquire() ? "got\n" : "busy\n");
PHP;
$childFile = $tmp . '/overlap.php';
file_put_contents($childFile, $childCode);
$spawn = static function () use ($childFile, $tmp, $root): string {
    $proc = proc_open(
        [PHP_BINARY, $childFile, $tmp, $root],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $tmp
    );
    if (!is_resource($proc)) {
        return '';
    }
    $out = stream_get_contents($pipes[1]);
    proc_close($proc);

    return trim((string) $out);
};
conn_ok($spawn() === 'busy', 'tick och sms-cron tar inte låset samtidigt');
jeab_job_overlap_release();
conn_ok($spawn() === 'got', 'låset släpps när jobbet är klart');

$value = jeab_auth_issue_value($email, $hash);
conn_ok(is_string($value) && $value !== '', 'testkakan kan utfärdas');
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_TEST_DATA');\nrequire getenv('JEAB_ROOT') . '/index.php';\n");
$port = 18000 + (getmypid() % 2000);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $srvPipes,
    $root,
    [
        'JEAB_TEST_DATA' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '0',
    ]
);
conn_ok(is_resource($server), 'php-servern startade');
$curl = static function (string $url, ?string $cookie = null): array {
    $cmd = ['curl', '-sS', '-D', '-', '--max-time', '15', $url];
    if ($cookie !== null) {
        $cmd = ['curl', '-sS', '-D', '-', '--max-time', '15', '-b', 'jeab_auth=' . $cookie, $url];
    }
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => ''];
    }
    $raw = (string) stream_get_contents($pipes[1]);
    proc_close($proc);
    $status = 0;
    if (preg_match('/^HTTP\/\S+\s+(\d+)/m', $raw, $m) === 1) {
        $status = (int) $m[1];
    }
    $body = $raw;
    $split = strpos($raw, "\r\n\r\n");
    if ($split !== false) {
        $body = substr($raw, $split + 4);
    }

    return ['status' => $status, 'body' => $body];
};
jeab_pdo_release();
foreach (['app.sqlite', 'app.sqlite-wal', 'app.sqlite-shm'] as $dbFile) {
    if (is_file($tmp . '/' . $dbFile)) {
        unlink($tmp . '/' . $dbFile);
    }
}
$ready = ['status' => 0, 'body' => ''];
for ($i = 0; $i < 40; $i++) {
    $ready = $curl('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if ($ready['status'] > 0) {
        break;
    }
    usleep(100000);
}
conn_ok($ready['status'] === 200 && str_contains($ready['body'], 'kampanjkod'), 'utloggad csrf är inloggningssidan, fick ' . $ready['status']);
conn_ok(!is_file($tmp . '/app.sqlite'), 'utloggad csrf skapar ingen sqlite');
$auth = $curl('http://127.0.0.1:' . $port . '/index.php?p=csrf', (string) $value);
$json = json_decode($auth['body'], true);
conn_ok($auth['status'] === 200 && is_array($json) && ($json['ok'] ?? false) === true && is_string($json['csrf'] ?? null) && strlen((string) $json['csrf']) === 64, 'inloggad csrf är JSON utan databas, status ' . $auth['status']);
conn_ok(!is_file($tmp . '/app.sqlite'), 'inloggad csrf rör inte databasen');
$page = $curl('http://127.0.0.1:' . $port . '/index.php?p=reminders', (string) $value);
conn_ok($page['status'] === 200 && str_contains($page['body'], 'JEAB'), 'en vanlig sida svarar, fick ' . $page['status']);
conn_ok(is_file($tmp . '/app.sqlite'), 'en vanlig sida öppnar databasen');
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

exit($fail > 0 ? 1 : 0);
