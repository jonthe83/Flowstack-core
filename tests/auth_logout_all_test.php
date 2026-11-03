<?php

declare(strict_types=1);

/**
 * auth_epoch i SQL-settings. Kakor utfärdade före epoch nekas.
 * auth.logout_all är torr som standard och skriver bara med confirm.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-auth-epoch-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$fellogg = $tmp . '/fellogg';
mkdir($fellogg, 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $fellogg;

$secret = 'ala-epoch-secret';
$hash = 'ala-staff-hash';
$email = 'staff@example.test';
$pwFile = $tmp . '/passwords.json';
file_put_contents($pwFile, json_encode([
    'secret' => $secret,
    'hashes' => [['hash' => $hash, 'email' => $email]],
], JSON_UNESCAPED_UNICODE));
$pwBefore = (string) file_get_contents($pwFile);
putenv('JEAB_PASSWORDS_FILE=' . $pwFile);
$inkToken = 'logout-all-bearer-token';
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $inkToken]));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/flags.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ala_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ala_cookie(string $secret, string $hash, int $issued, string $email): string
{
    $fp = jeab_auth_hash_fingerprint($hash);
    $enc = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
    $payload = '3.' . $fp . '.' . $enc . '.' . $issued . '.abcd';

    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

function ala_bearer(string $token): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    unset($_SERVER['HTTP_X_WEBHOOK_TOKEN'], $_POST['token'], $GLOBALS['_JEAB_RAW_BODY']);
}

function ala_clear_bearer(): void
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN'], $_POST['token'], $GLOBALS['_JEAB_RAW_BODY']);
}

$root = dirname(__DIR__);
$hookSrc = (string) file_get_contents($root . '/hook.php');
$authSrc = (string) file_get_contents($root . '/src/auth.php');
$dispatchStart = strpos($authSrc, 'function jeab_auth_logout_all_dispatch');
$dispatchSrc = $dispatchStart === false ? '' : substr($authSrc, $dispatchStart, 1800);
ala_assert(str_contains($hookSrc, "=== 'auth.logout_all'") && str_contains($hookSrc, 'jeab_auth_logout_all_dispatch'), 'hook.php tar auth.logout_all');
ala_assert(str_contains($dispatchSrc, 'jeab_inkorg_hook_authorized'), 'logout_all använder samma Bearer-koll');
ala_assert(!str_contains($dispatchSrc, 'passwords.json') && !str_contains($dispatchSrc, 'shield.php'), 'logout_all rör inte passwords.json eller shield.php');
$shieldBefore = hash('sha256', (string) file_get_contents($root . '/src/shield.php'));

jeab_auth_data(true);
unset($GLOBALS['_JEAB_AUTH_EPOCH_CACHE']);
$now = time();
$GLOBALS['_JEAB_AUTH_NOW'] = $now;
ala_assert(jeab_auth_epoch() === 0, 'saknad auth_epoch är 0');

$alive = ala_cookie($secret, $hash, $now - 100, $email);
ala_assert(jeab_auth_value_ok($alive) === true, 'epoch 0 lämnar en giltig kaka');

$_SESSION['jeab_email'] = $email;
$_COOKIE[JEAB_AUTH_COOKIE] = $alive;
ala_assert(jeab_auth_value_ok($alive) === true && ($_SESSION['jeab_email'] ?? '') === $email, 'giltig kaka rensar inte sessionen');

ala_clear_bearer();
$denied = jeab_auth_logout_all_dispatch(['action' => 'auth.logout_all', 'dry' => 0, 'confirm' => 'SAM_YES']);
ala_assert(($denied['status'] ?? 0) === 401 && empty($denied['ok']) && jeab_auth_epoch() === 0, 'utan Bearer skrivs ingen epoch');

ala_bearer('fel-token');
$wrong = jeab_auth_logout_all_dispatch(['action' => 'auth.logout_all']);
ala_assert(($wrong['status'] ?? 0) === 401 && jeab_auth_epoch() === 0, 'fel Bearer nekas');

ala_bearer($inkToken);
$flagsDenied = jeab_flags_get_dispatch([]);
ala_clear_bearer();
$flagsNoToken = jeab_flags_get_dispatch([]);
ala_bearer($inkToken);
ala_assert(!empty($flagsDenied['ok']) && ($flagsDenied['status'] ?? 0) === 200, 'flags.get med Bearer fungerar');
ala_assert(($flagsNoToken['status'] ?? 0) === 401, 'flags.get utan Bearer nekas');

$dry = jeab_auth_logout_all_dispatch(['action' => 'auth.logout_all']);
ala_assert(!empty($dry['ok']) && !empty($dry['dry']) && (int) ($dry['auth_epoch'] ?? -1) === 0 && jeab_auth_epoch() === 0, 'torr körning rapporterar epoch 0');
$dryZero = jeab_auth_logout_all_dispatch(['action' => 'auth.logout_all', 'dry' => '0']);
ala_assert(empty($dryZero['ok']) && ($dryZero['status'] ?? 0) === 400 && jeab_auth_epoch() === 0, 'dry 0 utan confirm skriver inte');
$dryWrong = jeab_auth_logout_all_dispatch(['action' => 'auth.logout_all', 'dry' => 0, 'confirm' => 'SAM_NO']);
ala_assert(empty($dryWrong['ok']) && jeab_auth_epoch() === 0, 'fel confirm skriver inte');

$live = jeab_auth_logout_all_dispatch(['action' => 'auth.logout_all', 'dry' => 0, 'confirm' => 'SAM_YES']);
$epoch = (int) ($live['auth_epoch'] ?? 0);
ala_assert(!empty($live['ok']) && empty($live['dry']) && $epoch === jeab_auth_epoch() && abs($epoch - time()) < 5, 'confirm sätter auth_epoch till time()');
$log = (string) @file_get_contents(jeab_app_errors_path());
ala_assert(str_contains($log, '"surface":"auth"') && str_contains($log, 'logout_all'), 'fellogg får logout_all');
ala_assert(!str_contains($log, $secret) && !str_contains($log, $inkToken), 'fellogg tar inte med hemligheter');
ala_assert((string) file_get_contents($pwFile) === $pwBefore, 'passwords.json är orörd');
ala_assert(hash('sha256', (string) file_get_contents($root . '/src/shield.php')) === $shieldBefore, 'shield.php är orörd');

$stale = ala_cookie($secret, $hash, $epoch - 30, $email);
$sameSecond = ala_cookie($secret, $hash, $epoch, $email);
$_COOKIE[JEAB_AUTH_COOKIE] = $stale;
$_SESSION['jeab_email'] = $email;
ala_assert(jeab_auth_value_ok($stale) === false, 'kaka utfärdad före epoch nekas');
ala_assert(!isset($_SESSION['jeab_email']), 'nekad kaka rensar jeab_email');
ala_assert(jeab_auth_user_email() === null, 'sessionens e-post kommer inte tillbaka');
ala_assert(jeab_auth_value_ok($sameSecond) === true, 'kaka utfärdad vid epoch gäller');
$fresh = jeab_auth_issue_value($email, $hash);
ala_assert(is_string($fresh) && jeab_auth_value_ok($fresh) === true, 'ny inloggning efter epoch fungerar');
$_COOKIE[JEAB_AUTH_COOKIE] = $fresh;
$_SESSION['jeab_email'] = $email;
ala_assert(jeab_auth_user_email() === $email && ($_SESSION['jeab_email'] ?? '') === $email, 'ny kaka behåller e-posten');

ala_bearer($inkToken);
$flagsAfter = jeab_flags_get_dispatch([]);
ala_assert(!empty($flagsAfter['ok']) && ($flagsAfter['status'] ?? 0) === 200, 'flags.get fungerar efter logout_all');
ala_assert(jeab_incoming_token_ok($inkToken) === true, 'Bearer mot rätt token gäller');
ala_assert(jeab_incoming_token_ok('annan-hook-token') === false, 'Bearer mot fel token nekas fortfarande');
ala_clear_bearer();
$flagsAfterDenied = jeab_flags_get_dispatch([]);
ala_assert(($flagsAfterDenied['status'] ?? 0) === 401 && jeab_auth_epoch() === $epoch, 'saknad Bearer ändrar inte epoch');

require_once dirname(__DIR__) . '/src/layout.php';
$login = jeab_login_html(null, 0);
ala_assert(str_contains($login, 'Ange kampanjkod'), 'inloggningsrutan ber om kampanjkod');
$gateSrc = substr($authSrc, (int) strpos($authSrc, 'function jeab_gate'));
ala_assert(str_contains($gateSrc, 'jeab_auth_forget_session_email') && str_contains($gateSrc, 'jeab_render_login'), 'grinden rensar sessionen och ritar inloggningen');

if (function_exists('jeab_pdo_release')) {
    jeab_pdo_release();
}
$outFile = $tmp . '/gate-session.json';
$runner = $tmp . '/gate_runner.php';
$boot = $root . '/src/bootstrap.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_TEST_TMP') ?: '';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';
$_GET = [];
$_POST = [];
$_COOKIE['jeab_auth'] = (string) getenv('JEAB_TEST_COOKIE');
require getenv('JEAB_BOOT') ?: '';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'stale@example.test';
register_shutdown_function(static function () use ($tmp): void {
    file_put_contents($tmp . '/gate-session.json', json_encode([
        'email' => $_SESSION['jeab_email'] ?? null,
    ], JSON_UNESCAPED_UNICODE));
});
jeab_gate();
PHP);
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    [
        'JEAB_TEST_TMP' => $tmp,
        'JEAB_TEST_COOKIE' => $stale,
        'JEAB_BOOT' => $boot,
        'JEAB_PASSWORDS_FILE' => $pwFile,
    ]
);
ala_assert(is_resource($proc), 'grinden startar');
$stdout = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$stderr = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
$exit = is_resource($proc) ? proc_close($proc) : 1;
$saved = is_file($outFile) ? json_decode((string) file_get_contents($outFile), true) : null;
ala_assert($exit === 0 && str_contains($stdout, 'Ange kampanjkod'), 'grinden visar Ange kampanjkod' . ($stderr !== '' ? ' stderr=' . trim($stderr) : ''));
ala_assert(is_array($saved) && array_key_exists('email', $saved) && $saved['email'] === null, 'grinden rensar jeab_email');

echo "OK  auth logout all\n";
