<?php

declare(strict_types=1);

/**
 * Session utan användare repareras från kodens 9 första tecken.
 * Versaler och blanksteg spelar ingen roll. Utan träff visas Välj användare.
 * Hook auth.session_user_repair är torr som standard och skriver bara antal.
 * Inga riktiga koder. Inget skrivs utanför tmp.
 */

require_once __DIR__ . '/jeab_test_proc_env.php';

$tmp = sys_get_temp_dir() . '/jeab-sess-user-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$sessDir = $tmp . '/sess';
mkdir($sessDir, 0775, true);
mkdir($tmp . '/secrets', 0775, true);
session_save_path($sessDir);

$suffix = bin2hex(random_bytes(4));
$codeJl = 'SamL' . $suffix;
$codeSpace = 'Sam L' . $suffix;
$codeCase = '  jOnAtHaNl' . $suffix;
$codeNone = 'zzzzzzzzz' . $suffix;
$hashJl = password_hash($codeJl, PASSWORD_DEFAULT);
$hashNone = password_hash($codeNone, PASSWORD_DEFAULT);
$secret = bin2hex(random_bytes(16));
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => $secret,
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $hashJl],
        ['email' => 'demo@example.test', 'hash' => $hashNone],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
], true) . ";\n");
$hookToken = 'repair-test-token';
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $hookToken]));

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_auth_data(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function su(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function su_reset(): void
{
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    unset(
        $GLOBALS['_JEAB_LOGIN_IDENTITY'],
        $GLOBALS['_JEAB_AUTH_EMAIL'],
        $GLOBALS['_JEAB_AUTH_OK'],
        $GLOBALS['_JEAB_WHO_REASON'],
        $GLOBALS['_JEAB_AUTH_PAYLOAD']
    );
}

su((jeab_person_by_code_prefix($codeCase)['id'] ?? '') === 'SamL', 'versaler och blanksteg runt prefixet är Sam L');
su((jeab_person_by_code_prefix($codeSpace)['id'] ?? '') === 'SamL', 'mellanslag i Sam L är Sam L');
su(jeab_person_by_code_prefix($codeNone) === null, 'okänt prefix träffar ingen');

$fpJl = jeab_auth_hash_fingerprint($hashJl);
file_put_contents($tmp . '/code-people.json', json_encode([
    'fp:' . $fpJl => ['person' => '', 'via' => 'prefix', 'head' => ' JoNaThAnL', 'at' => '2026-01-01 00:00:00'],
], JSON_UNESCAPED_UNICODE));
su_reset();
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $hashJl);
su((jeab_auth_user_person()['id'] ?? '') === 'SamL', 'befintlig session utan användare får Sam L från huvudet');
su(($_SESSION['jeab_user'] ?? '') === 'SamL' && ($_SESSION['jeab_user_via'] ?? '') === 'prefix', 'träffen sparas i sessionen');
$stored = (string) file_get_contents($tmp . '/code-people.json');
su(!str_contains($stored, $suffix) && !str_contains($stored, $codeJl), 'hela koden skrivs inte till code-people');

su_reset();
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $hashNone);
unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
su(jeab_auth_user_person() === null, 'kod utan prefixträff har ingen användare');
$picker = jeab_auth_user_picker_html(null);
su(str_contains($picker, 'Välj användare') && str_contains($picker, 'data-user-picker="1"'), 'väljaren har rubriken');
su(str_contains($picker, 'Sam L') && str_contains($picker, 'Elin') && str_contains($picker, 'name="person"'), 'väljaren listar användare');
su(!str_contains($picker, $suffix) && !str_contains($picker, $codeNone), 'väljaren visar inte koden');

su_reset();
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $hashNone);
jeab_auth_session_user_store('Elin', 'choice', null);
unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
su((jeab_auth_user_person()['id'] ?? '') === 'Elin', 'valet sparas och används');
su(!jeab_is_admin(), 'ett val i listan ger inte admin');
su((jeab_who_me_name() ?? '') === 'Elin', 'Mina följer valet');

$liveId = session_id();
session_write_close();
if ($liveId !== '') {
    @unlink($sessDir . '/sess_' . $liveId);
}
$fpRepair = 'abcabcabcabcabca';
file_put_contents($tmp . '/code-people.json', json_encode([
    'fp:' . $fpJl => ['person' => 'SamL', 'via' => 'prefix', 'head' => 'SamL', 'at' => '2026-01-01 00:00:00'],
    'fp:' . $fpRepair => ['person' => '', 'via' => 'prefix', 'head' => ' JoNaThAnW', 'at' => '2026-01-02 00:00:00'],
], JSON_UNESCAPED_UNICODE));
$blobMatch = 'jeab_code_head|s:9:"SamL";jeab_auth_fast|a:2:{s:2:"fp";s:16:"0000000000000000";s:3:"mac";s:3:"mac";}';
$blobCase = 'jeab_auth_fast|a:2:{s:2:"fp";s:16:"' . $fpRepair . '";s:3:"mac";s:3:"mac";}';
$blobMiss = 'jeab_code_head|s:9:"zzzzzzzzz";jeab_email|s:3:"x@y";';
$blobHave = 'jeab_email|s:3:"a@b";jeab_user|s:9:"SamL";jeab_user_via|s:6:"prefix";';
file_put_contents($sessDir . '/sess_match', $blobMatch);
file_put_contents($sessDir . '/sess_case', $blobCase);
file_put_contents($sessDir . '/sess_miss', $blobMiss);
file_put_contents($sessDir . '/sess_have', $blobHave);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $hookToken;
$dry = jeab_auth_session_user_repair_dispatch(['action' => 'auth.session_user_repair']);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
su(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true, 'hooken är torr utan dry');
su(($dry['sessions_without_user'] ?? -1) === 3 && ($dry['matched'] ?? -1) === 2 && ($dry['unmatched'] ?? -1) === 1 && ($dry['linked'] ?? -1) === 0, 'torr körning räknar utan användare, träff och miss');
su(is_string($dryJson) && !str_contains($dryJson, $suffix) && !str_contains($dryJson, $codeJl), 'svaret har inga koder');
su(!str_contains((string) file_get_contents($sessDir . '/sess_match'), 'jeab_user|'), 'torr körning skriver inte sessionen');

$live = jeab_auth_session_user_repair_dispatch(['action' => 'auth.session_user_repair', 'dry' => 0]);
su(($live['ok'] ?? false) === true && ($live['dry'] ?? true) === false && ($live['linked'] ?? 0) === 2, 'dry=0 länkar träffarna');
$linkedRaw = (string) file_get_contents($sessDir . '/sess_match') . (string) file_get_contents($sessDir . '/sess_case');
su(str_contains((string) file_get_contents($sessDir . '/sess_match'), 'jeab_user|s:9:"SamL";'), 'Sam L skrivs på sessionen');
su(str_contains((string) file_get_contents($sessDir . '/sess_case'), 'jeab_user|s:9:"SamW";'), 'Sam W från fingeravtryckets huvud, även med blanksteg och versaler');
su(!str_contains($linkedRaw, $suffix), 'länkade sessioner får inte koden');
su(!str_contains((string) file_get_contents($sessDir . '/sess_miss'), 'jeab_user|'), 'en miss länkas inte');

$runner = $tmp . '/gate.php';
$root = dirname(__DIR__);
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_TEST_TMP') ?: '';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php?p=reminders';
$_GET = ['p' => 'reminders'];
$_COOKIE['jeab_auth'] = (string) getenv('JEAB_TEST_COOKIE');
require getenv('JEAB_BOOT') ?: '';
jeab_gate();
PHP);
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    [
        'JEAB_TEST_TMP' => $tmp,
        'JEAB_TEST_COOKIE' => (string) jeab_auth_issue_value('demo@example.test', $hashNone),
        'JEAB_BOOT' => $root . '/src/bootstrap.php',
        'JEAB_PASSWORDS_FILE' => $tmp . '/passwords.json',
    ]
);
su(is_resource($proc), 'grinden startar');
$gateOut = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$gateErr = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
$gateCode = is_resource($proc) ? proc_close($proc) : 1;
su($gateCode === 0 && str_contains($gateOut, 'Välj användare') && str_contains($gateOut, 'data-user-picker="1"'), 'grinden visar väljaren');
su(!str_contains($gateOut, 'data-mina-fallback') && !str_contains($gateOut, $suffix), 'grinden ritar inte Påminnelser och inte koden');
su(!str_contains($gateErr, $suffix), 'felkanalen har inte koden');

$port = 0;
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (is_resource($probe)) {
    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr((string) strrchr((string) $name, ':'), 1);
}
if ($port < 1) {
    $port = 20000 + (getmypid() % 20000);
}
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n" . jeab_test_sqlite_boot() . <<<PHP
\$tmp = getenv('JEAB_TEST_TMP') ?: '';
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = \$tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/passwords.json');
ini_set('session.save_path', \$tmp . '/sess');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/hook.php') {
    require '{$root}/hook.php';
    return true;
}
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$root}/index.php';
    return true;
}
return false;
PHP);
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $serverPipes,
    $root,
    jeab_test_proc_env(['JEAB_TEST_TEMP_SQLITE' => '1', 'JEAB_TEST_TMP' => $tmp, 'JEAB_PASSWORDS_FILE' => $tmp . '/passwords.json'])
);
su(is_resource($server), 'php -S startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$deadline = microtime(true) + 5;
$up = false;
do {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/hook.php');
    if (is_string($probe) && $probe !== '') {
        $up = true;
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);
su($up, 'php -S svarar');

$cookie = rawurlencode((string) jeab_auth_issue_value('demo@example.test', $hashNone));
$ch = curl_init('http://127.0.0.1:' . $port . '/index.php?p=reminders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Cookie: jeab_auth=' . $cookie, 'Accept: text/html'],
    CURLOPT_TIMEOUT => 20,
]);
$page = (string) curl_exec($ch);
$pageStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
su($pageStatus === 200 && str_contains($page, 'Välj användare') && str_contains($page, 'data-user-picker="1"'), 'curl visar väljaren');
su(!str_contains($page, 'data-mina-fallback') && !str_contains($page, $suffix), 'curl ritar inte sidan och inte koden');

if (!is_dir($tmp . '/secrets')) {
    mkdir($tmp . '/secrets', 0775, true);
}
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $hookToken]));
$ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $hookToken,
        'X-Webhook-Token: ' . $hookToken,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => '{"action":"auth.session_user_repair"}',
    CURLOPT_TIMEOUT => 20,
]);
$hookBody = (string) curl_exec($ch);
$hookStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$hookJson = json_decode($hookBody, true);
$hookErr = is_array($hookJson) ? (string) ($hookJson['error'] ?? '') : '';
su($hookStatus === 200 && is_array($hookJson) && ($hookJson['dry'] ?? false) === true && ($hookJson['sessions_without_user'] ?? 0) >= 1, 'curl hook är torr och räknar, status ' . $hookStatus . ' ' . $hookErr);
su(!str_contains($hookBody, $suffix) && !str_contains($hookBody, $codeNone), 'curl-svaret har inga koder');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "OK  session-user-repair\n";
