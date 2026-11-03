<?php

declare(strict_types=1);

/**
 * fp:-mappning utan e-post och utan prefix öppnar inte Inloggningar.
 * En Malmö-kaka utan huvud med kvarliggande kodperson ger ingen anslutningsetikett.
 */

$case = getenv('JEAB_CASE') ?: '';
$fromParent = getenv('JEAB_TEST_DIR');
if ($case === 'post' && is_string($fromParent) && $fromParent !== '') {
    $tmp = $fromParent;
} else {
    $tmp = sys_get_temp_dir() . '/jeab-fp-diag-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_TEST_DIR=' . $tmp);

if ($case !== 'post') {
    $hashFp = password_hash('kod-utan-prefix-och-post', PASSWORD_DEFAULT);
    $hashW = password_hash('malmo-kvar-samw', PASSWORD_DEFAULT);
    $fp = substr(hash('sha256', $hashFp), 0, 16);
    file_put_contents($tmp . '/passwords.json', json_encode([
        'secret' => bin2hex(random_bytes(16)),
        'hashes' => [
            ['hash' => $hashFp],
            ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ],
    ], JSON_UNESCAPED_UNICODE));
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'passwords_file' => $tmp . '/passwords.json',
        'login_people' => ['fp:' . $fp => 'SamL'],
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
        ],
    ], true) . ";\n");
    file_put_contents($tmp . '/fixture.json', json_encode(['hash' => $hashFp, 'hashW' => $hashW, 'fp' => $fp]));
}

if ($case === 'post') {
    $fixture = json_decode((string) file_get_contents($tmp . '/fixture.json'), true);
    $hashFp = (string) ($fixture['hash'] ?? '');
    $fp = (string) ($fixture['fp'] ?? '');
    require dirname(__DIR__) . '/src/bootstrap.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value(null, $hashFp);
    $_SESSION['jeab_code_person'] = 'saml';
    $_GET = ['p' => 'inloggdiag'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'action' => 'login.assign',
        'fp' => $fp,
        'person' => 'SamL',
        '_csrf' => jeab_csrf_token(),
    ];
    jeab_login_diag_page();
    echo "NOEXIT\n";
    exit(0);
}

require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function fd(bool $ok, string $msg): void
{
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$msg}\n");
    exit(1);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
session_id(bin2hex(random_bytes(8)));
session_start();
$_SESSION = ['jeab_code_person' => 'samw'];
$_COOKIE = [JEAB_AUTH_COOKIE => (string) jeab_auth_issue_value('demo@malmo.example.test', $hashW)];
$GLOBALS['_JEAB_WHO_REASON'] = '';
unset($GLOBALS['_JEAB_PDO_CONN_STATS']);
$pdo = jeab_pdo_connect_sqlite();
fd($pdo instanceof PDO, 'sqlite öppnar');
fd((string) ($GLOBALS['_JEAB_PDO_CONN_STATS']['user'] ?? 'x') === '', 'boot-etiketten är tom för Malmö-kaka utan huvud');
fd(!isset($_SESSION['jeab_code_person']), 'osäker kaka tömmer samw innan etiketten');
fd(jeab_auth_user_person() === null && jeab_egna_me_id() === '' && !jeab_is_admin(), 'kvarliggande samw blir ingen person');

$_COOKIE = [JEAB_AUTH_COOKIE => (string) jeab_auth_issue_value(null, $hashFp)];
$_SESSION = ['jeab_code_person' => 'saml'];
$GLOBALS['_JEAB_WHO_REASON'] = '';
fd((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_who_identity_reason() === 'fp-config', 'fp: ger personen i filtret');
fd(jeab_personal_login_person() === null && !jeab_is_admin() && !jeab_login_diag_allowed(), 'fp: öppnar inte Inloggningar och är inte admin');

$env = getenv();
$env = is_array($env) ? $env : [];
$env['JEAB_CASE'] = 'post';
$env['JEAB_TEST_DIR'] = $tmp;
$proc = proc_open([PHP_BINARY, __FILE__], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
$out = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$err = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
if (is_resource($proc)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
}
$code = is_resource($proc) ? proc_close($proc) : 1;
fd($code === 0 && str_contains($out, 'Bara admin.'), 'fp:-inloggning kan inte peka om, fick ' . trim($out . $err));
$saved = jeab_code_people_read(false)['fp:' . $fp] ?? null;
fd(!is_array($saved) || (string) ($saved['via'] ?? '') !== 'manual', 'nekad pekning gör inte inloggningen till admin');

echo "uncertain fp diag: ok\n";
