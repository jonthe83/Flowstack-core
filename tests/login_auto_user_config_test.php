<?php

declare(strict_types=1);

/**
 * via=prefix vinner bara när huvudet fortfarande pekar ut exakt den sparade personen
 * i people-config. Fas 1 loggar in med påhittade koder och standardregistret.
 * Fas 2 (egen process, samma data) byter namn på Sam L och lägger till en
 * Roberto till. Då ska väljaren visas i stället för en gissning. Inga riktiga koder.
 */

$phase = getenv('LA_PHASE') ?: '1';
$tmp = getenv('LA_DIR') ?: sys_get_temp_dir() . '/jeab-login-auto-config-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

function lc_assert(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

if ($phase === '1') {
    $codes = [
        'SamL' => 'SamL' . 'fake' . bin2hex(random_bytes(4)),
        'Roberto' => 'Roberto-' . 'fake' . bin2hex(random_bytes(4)),
        'Kim' => 'Kim ' . 'fake' . bin2hex(random_bytes(4)),
    ];
    $entries = [];
    foreach ($codes as $code) {
        $entries[] = ['hash' => password_hash($code, PASSWORD_DEFAULT)];
    }
    file_put_contents($tmp . '/passwords.json', json_encode(['secret' => bin2hex(random_bytes(16)), 'hashes' => $entries]));
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
        'passwords_file' => $tmp . '/passwords.json',
    ], true) . ";\n");
}
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_auth_data(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function lc_fresh(string $cookie): array
{
    $_SESSION = [];
    $_GET = [];
    $_COOKIE[JEAB_AUTH_COOKIE] = $cookie;
    unset($GLOBALS['_JEAB_LOGIN_IDENTITY'], $GLOBALS['_JEAB_WHO_REASON']);

    return jeab_login_identity();
}

if ($phase === '1') {
    $cookies = [];
    foreach ($codes as $id => $code) {
        $m = jeab_auth_match($code);
        lc_assert(is_array($m), $id . ': påhittad kod matchar');
        jeab_code_people_remember($code, (string) $m['hash'], null);
        $cookies[$id] = (string) jeab_auth_issue_value(null, (string) $m['hash']);
        $r = lc_fresh($cookies[$id]);
        lc_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === $id, $id . ': ny session väljer personen');
    }
    file_put_contents($tmp . '/cookies.json', json_encode($cookies));
    // Fas 2: Sam L heter nu Jonny L, och Roberto B delar prefixet med Roberto.
    $people = jeab_default_people();
    foreach ($people as &$row) {
        if ($row['id'] === 'SamL') {
            $row['name'] = 'Jonny L';
            $row['username'] = 'jonny';
        }
    }
    unset($row);
    $people[] = ['id' => 'RobertoB', 'email' => 'robertob@jarfalla.example.test', 'name' => 'Roberto B', 'company' => 'jarfalla', 'job' => null];
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
        'passwords_file' => $tmp . '/passwords.json',
        'people' => $people,
    ], true) . ";\n");
    $cmd = 'LA_PHASE=2 LA_DIR=' . escapeshellarg($tmp) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
    exec($cmd, $out, $code);
    echo implode("\n", $out) . "\n";
    lc_assert($code === 0, 'fas 2 med ändrad people-config');
    echo "ALL OK\n";
    exit(0);
}

$cookies = json_decode((string) file_get_contents($tmp . '/cookies.json'), true);
$ids = array_column(jeab_people(), 'id');
lc_assert(in_array('RobertoB', $ids, true) && (jeab_auth_person_by_id('SamL')['name'] ?? '') === 'Jonny L', 'fas 2: nytt register laddat');
$r = lc_fresh((string) $cookies['SamL']);
lc_assert(empty($r['certain']) && $r['person'] === null, 'namnbyte: sparat prefix gäller inte, ingen gissning (inte Sam W)');
$r = lc_fresh((string) $cookies['Roberto']);
lc_assert(empty($r['certain']) && $r['person'] === null, 'ny person med samma prefix: tvetydigt, väljaren visas');
$r = lc_fresh((string) $cookies['Kim']);
lc_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === 'Kim', 'oförändrad person väljs fortfarande');
exit(0);
