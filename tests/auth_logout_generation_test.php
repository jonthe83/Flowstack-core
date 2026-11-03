<?php

declare(strict_types=1);

/**
 * H-AUTH-1. Utloggning höjer en gräns per kod i settings.
 * Äldre kakor för den koden nekas, även en kopia. En annan kod gäller kvar.
 * En ny inloggning efter utloggningen fungerar. Inga hemligheter i felloggen.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-auth-gen-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$fellogg = $tmp . '/fellogg';
mkdir($fellogg, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $fellogg;

$secret = 'alg-secret-' . bin2hex(random_bytes(4));
$hashA = 'alg-hash-a-' . bin2hex(random_bytes(4));
$hashB = 'alg-hash-b-' . bin2hex(random_bytes(4));
$emailA = 'a@example.test';
$emailB = 'b@example.test';
$pwFile = $tmp . '/passwords.json';
file_put_contents($pwFile, json_encode([
    'secret' => $secret,
    'hashes' => [
        ['hash' => $hashA, 'email' => $emailA],
        ['hash' => $hashB, 'email' => $emailB],
    ],
], JSON_UNESCAPED_UNICODE));
$pwBefore = (string) file_get_contents($pwFile);
putenv('JEAB_PASSWORDS_FILE=' . $pwFile);

require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function alg_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function alg_cookie(string $secret, string $ver, string $hash, int $issued, string $email): string
{
    if ($ver === '3') {
        $fp = jeab_auth_hash_fingerprint($hash);
        $enc = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
        $payload = '3.' . $fp . '.' . $enc . '.' . $issued . '.abcd';
    } elseif ($ver === '2') {
        $enc = rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
        $payload = '2.' . $enc . '.' . $issued . '.abcd';
    } else {
        $payload = '1.' . $issued . '.abcd';
    }

    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

jeab_auth_data(true);
unset($GLOBALS['_JEAB_AUTH_EPOCH_CACHE']);
$now = 1700000000;
$GLOBALS['_JEAB_AUTH_NOW'] = $now;

$oldA = alg_cookie($secret, '3', $hashA, $now - 100, $emailA);
$midA = alg_cookie($secret, '3', $hashA, $now - 10, $emailA);
$oldB = alg_cookie($secret, '3', $hashB, $now - 80, $emailB);
$v2A = alg_cookie($secret, '2', $hashA, $now - 40, $emailA);
$v1 = alg_cookie($secret, '1', $hashA, $now - 20, $emailA);

alg_assert(jeab_auth_value_ok($oldA) && jeab_auth_value_ok($midA) && jeab_auth_value_ok($oldB), 'kakor gäller före utloggning');
alg_assert(jeab_auth_value_ok($v2A) && jeab_auth_value_ok($v1), 'äldre format gäller före utloggning');
alg_assert(jeab_auth_generation_for(jeab_auth_hash_fingerprint($hashA)) === 0, 'saknad gräns är 0');

$_COOKIE[JEAB_AUTH_COOKIE] = $oldA;
$_SESSION['jeab_email'] = $emailA;
$_SESSION['jeab_company'] = 'jarfalla';
$_SESSION['other'] = 'kvar';
alg_assert(jeab_auth_user_email() === $emailA, 'e-post läses från kakan');

$renewed = jeab_auth_issue_value($emailA, $hashA);
alg_assert(is_string($renewed) && jeab_auth_value_ok($renewed) && jeab_auth_value_ok($oldA), 'förnyelse ogiltigförklarar inte den gamla kakan');

jeab_auth_logout();
alg_assert(true, 'utloggning går att anropa');
alg_assert(!isset($_COOKIE[JEAB_AUTH_COOKIE]), 'webbläsarens kaka tas bort');
alg_assert(!isset($_SESSION['jeab_email']) && !isset($_SESSION['jeab_company']) && ($_SESSION['other'] ?? '') === 'kvar', 'jeab-sessionen töms');

$floorA = jeab_auth_generation_for(jeab_auth_hash_fingerprint($hashA));
alg_assert($floorA === $now + 1, 'gränsen ligger strax efter kakan, fick ' . $floorA);
alg_assert(jeab_auth_generation_for(jeab_auth_hash_fingerprint($hashB)) === 0, 'den andra koden har ingen gräns');

$_COOKIE[JEAB_AUTH_COOKIE] = $oldA;
$_SESSION['jeab_email'] = $emailA;
alg_assert(jeab_auth_value_ok($oldA) === false, 'kopian av den utloggade kakan nekas');
alg_assert(!isset($_SESSION['jeab_email']), 'nekad kaka rensar jeab_email');
alg_assert(jeab_auth_user_email() === null, 'e-post kommer inte tillbaka från kopian');
alg_assert(jeab_auth_value_ok($midA) === false && jeab_auth_value_ok($v2A) === false, 'äldre kakor för samma kod nekas');
alg_assert(jeab_auth_value_ok($oldB) === true, 'den andra kodens kaka gäller');
$_COOKIE[JEAB_AUTH_COOKIE] = $oldB;
$_SESSION['jeab_email'] = 'fel@example.test';
alg_assert(jeab_auth_user_email() === $emailB, 'den andra koden behåller sin e-post');

$fresh = jeab_auth_issue_value($emailA, $hashA);
alg_assert(is_string($fresh) && jeab_auth_value_ok($fresh) === true, 'ny inloggning efter utloggning fungerar');
alg_assert(jeab_auth_value_ok($oldA) === false && jeab_auth_value_ok($oldB) === true, 'den nya kakan släpper inte in den gamla');
$_COOKIE[JEAB_AUTH_COOKIE] = $fresh;
alg_assert(jeab_auth_user_email() === $emailA, 'nya kakan ger e-post');

$_COOKIE[JEAB_AUTH_COOKIE] = $v1;
$_SESSION['jeab_email'] = $emailA;
jeab_auth_logout();
alg_assert(jeab_auth_value_ok($v1) === false, 'utloggad v1-kaka nekas');
alg_assert(jeab_auth_value_ok($fresh) === true && jeab_auth_value_ok($oldB) === true, 'v1-utloggning rör inte kodernas kakor');

$log = (string) @file_get_contents(jeab_app_errors_path());
alg_assert(!str_contains($log, $secret) && !str_contains($log, $hashA) && !str_contains($log, $hashB) && !str_contains($log, $oldA), 'fellogg tar inte med hemlighet, hash eller kaka');
alg_assert((string) file_get_contents($pwFile) === $pwBefore, 'passwords.json är orörd');

echo "ALLA OK\n";
