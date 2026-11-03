<?php

declare(strict_types=1);

/**
 * P1: HSTS ett år, nya skrivningar i data/.private, webhook-token bara via POST.
 * tick.php och sms-cron.php tar fortfarande GET med sin egen cron-token.
 */

$tmp = sys_get_temp_dir() . '/jeab-p1-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function p1_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function p1_reset_request(): void
{
    $_GET = [];
    $_POST = [];
    unset(
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
        $_SERVER['HTTP_X_WEBHOOK_TOKEN'],
        $_SERVER['CONTENT_TYPE'],
        $_SERVER['HTTP_CONTENT_TYPE'],
        $GLOBALS['_JEAB_RAW_BODY']
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

$saved = [
    'HTTPS' => $_SERVER['HTTPS'] ?? null,
    'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
    'HTTP_X_FORWARDED_SSL' => $_SERVER['HTTP_X_FORWARDED_SSL'] ?? null,
    'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? null,
];
$clearHttps = static function (): void {
    $_SERVER['HTTPS'] = 'off';
    unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_SSL'], $_SERVER['SERVER_PORT']);
};

$clearHttps();
p1_assert(jeab_hsts_value() === null, 'vanlig HTTP sätter inte HSTS');

$_SERVER['HTTPS'] = 'on';
p1_assert(jeab_hsts_value() === 'max-age=31536000', 'HTTPS ger max-age=31536000');
p1_assert(!str_contains((string) jeab_hsts_value(), 'includeSubDomains'), 'includeSubDomains läggs inte till');
p1_assert(!str_contains((string) jeab_hsts_value(), 'preload'), 'preload läggs inte till');

$clearHttps();
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
p1_assert(jeab_hsts_value() === null, 'X-Forwarded-Proto ensamt sätter inte HSTS');
$clearHttps();
$_SERVER['HTTP_X_FORWARDED_SSL'] = 'on';
p1_assert(jeab_hsts_value() === null, 'X-Forwarded-SSL ensamt sätter inte HSTS');
p1_assert(jeab_is_https() === false, 'X-Forwarded-Proto ensamt sätter inte Secure');

$clearHttps();
$_SERVER['SERVER_PORT'] = '443';
p1_assert(jeab_hsts_value() === 'max-age=31536000', 'port 443 ger samma HSTS');

foreach ($saved as $key => $value) {
    if ($value === null) {
        unset($_SERVER[$key]);
    } else {
        $_SERVER[$key] = $value;
    }
}

p1_assert(jeab_http_path_is_sensitive('/data/store.json') === true, 'P0 /data/*.json förblir spärrad');
p1_assert(jeab_http_path_is_sensitive('/data/secrets/einstein-bug-webhook.json') === true, 'P0 /data/secrets/*.json förblir spärrad');
p1_assert(jeab_http_path_is_sensitive('/index.php') === false, 'inloggningssidan är inte en data-spärr');

$public = sys_get_temp_dir() . '/jeab-p1-public-' . getmypid();
$private = $public . '/.private';
mkdir($public, 0775, true);
mkdir($private, 0775, true);

$fresh = jeab_data_land_private($private . '/new.json', $public . '/new.json', false);
p1_assert($fresh === $private . '/new.json', 'ny fil pekar på .private');
p1_assert(!is_file($public . '/new.json'), 'ny fil skapas inte under publik data/');

file_put_contents($public . '/store.json', '{"from":"public","n":1}');
$moved = jeab_data_land_private($private . '/store.json', $public . '/store.json', false);
p1_assert($moved === $private . '/store.json', 'befintlig publik fil flyttas till .private');
p1_assert(!is_file($public . '/store.json'), 'publik store.json är borta efter flytt');
p1_assert((string) file_get_contents($private . '/store.json') === '{"from":"public","n":1}', 'flytten behåller innehållet');

file_put_contents($private . '/notes.json', '{}');
file_put_contents($public . '/notes.json', '{"notes":[1,2,3]}');
$adopted = jeab_data_land_private($private . '/notes.json', $public . '/notes.json', false);
p1_assert($adopted === $private . '/notes.json', 'större publik kopia landar i .private');
p1_assert(!is_file($public . '/notes.json'), 'publik notes.json tas bort när privat kopia tagit över');
p1_assert(str_contains((string) file_get_contents($private . '/notes.json'), '"notes"'), 'innehållet från den större filen följde med');

$viaFile = jeab_data_file('p1-probe.json');
$viaNorm = str_replace('\\', '/', $viaFile);
$privateNorm = str_replace('\\', '/', jeab_data_dir());
$publicData = str_replace('\\', '/', jeab_public_data_dir());
p1_assert(str_starts_with($viaNorm, $privateNorm . '/'), 'jeab_data_file skriver i privat katalog');
p1_assert(!is_file($publicData . '/p1-probe.json'), 'jeab_data_file skapar ingen publik p1-probe.json');

$firstSqlite = str_replace('\\', '/', (string) jeab_config()['db']['sqlite_path']);
$secondSqlite = str_replace('\\', '/', (string) jeab_config()['db']['sqlite_path']);
p1_assert($firstSqlite === $secondSqlite, 'config-cachen behåller samma sqlite-sökväg');
p1_assert(str_starts_with($secondSqlite, $privateNorm . '/'), 'upprepad config-läsning stannar i privat katalog');

$sqlitePublic = jeab_public_data_dir() . '/p1-probe-not-created.sqlite';
$sqliteResolved = str_replace('\\', '/', jeab_resolve_data_path($sqlitePublic));
p1_assert(str_starts_with($sqliteResolved, $privateNorm . '/'), 'ny sqlite-sökväg landar i .private');
p1_assert(!is_file($sqlitePublic), 'sqlite skapas inte under publik data/');

$bugDir = str_replace('\\', '/', jeab_bug_reports_dir());
p1_assert(str_starts_with($bugDir, $privateNorm . '/bug-reports'), 'buggrapporter skrivs i .private');

$expected = 'p1-hook-token-value';
p1_reset_request();
$_GET['token'] = $expected;
p1_assert(jeab_incoming_token_ok($expected) === false, 'GET ?token= accepteras inte');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['token'] = $expected;
p1_assert(jeab_incoming_token_ok($expected) === false, 'query-token på POST räcker inte');

p1_reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expected;
p1_assert(jeab_incoming_token_ok($expected) === true, 'POST Authorization Bearer accepteras');

p1_reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $expected;
p1_assert(jeab_incoming_token_ok($expected) === true, 'POST X-Webhook-Token accepteras');

p1_reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['token'] = $expected;
p1_assert(jeab_incoming_token_ok($expected) === true, 'POST form-body token accepteras');

p1_reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$GLOBALS['_JEAB_RAW_BODY'] = json_encode(['token' => $expected, 'type' => 'reminder'], JSON_UNESCAPED_UNICODE);
p1_assert(jeab_incoming_token_ok($expected) === true, 'POST JSON-body token accepteras');

p1_reset_request();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expected;
p1_assert(jeab_incoming_token_ok($expected) === false, 'GET med Bearer accepteras inte');

p1_reset_request();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'fel-token';
p1_assert(jeab_incoming_token_ok($expected) === false, 'fel POST-token avvisas');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$tick = (string) file_get_contents(dirname(__DIR__) . '/sms-tick.php');
$cron = (string) file_get_contents(dirname(__DIR__) . '/sms-cron.php');
$tickEntry = (string) file_get_contents(dirname(__DIR__) . '/tick.php');
p1_assert(str_contains($hook, 'jeab_reject_get_webhook_token()'), 'hook.php avvisar GET-token');
p1_assert(str_contains($tick, 'jeab_reject_get_webhook_token()'), 'sms-tick.php avvisar GET-token');
p1_assert(!str_contains($cron, 'jeab_reject_get_webhook_token()'), 'sms-cron.php tvingar inte GET till 405');
p1_assert(str_contains($cron, 'jeab_cron_token_matches'), 'sms-cron.php jämför sin egen cron-token');
p1_assert(!str_contains($tickEntry, 'jeab_reject_get_webhook_token()'), 'tick.php tvingar inte GET till 405');
$security = (string) file_get_contents(dirname(__DIR__) . '/src/security.php');
$start = strpos($security, 'function jeab_incoming_token_ok');
$end = $start === false ? false : strpos($security, "\nfunction ", $start + 10);
if ($start === false) {
    $fn = '';
} elseif ($end === false) {
    $fn = substr($security, $start);
} else {
    $fn = substr($security, $start, $end - $start);
}
p1_assert($fn !== '' && !str_contains($fn, '$_GET'), 'jeab_incoming_token_ok läser inte query-token');
p1_assert(str_contains($security, 'function jeab_cron_capture_query_token') && str_contains($security, '$_GET[\'token\']'), 'bara cron-fångsten läser query-token');

p1_assert(jeab_http_path_is_sensitive('/fullmakt/data/pending/not-a-real-token.json') === true, 'saknad fullmakt-json är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/data/signed/not-a-real-token.pdf') === true, 'saknad fullmakt-pdf är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/data/secret.php') === true, 'fullmakt secret.php är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/lib/store.php') === true, 'fullmakt/lib är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/lib/bootstrap.php') === true, 'fullmakt bootstrap är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/lib/mailer.php') === true, 'fullmakt mailer är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/lib/pdf.php') === true, 'fullmakt pdf-lib är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/vendor/fpdf/fpdf.php') === true, 'fullmakt/vendor är känslig');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/s/abc') === false, 'sign-länken är inte en data-spärr');
p1_assert(jeab_http_path_is_sensitive('/fullmakt/pdf.php') === false, 'pdf-sidan är inte en data-spärr');
p1_assert(jeab_http_path_is_sensitive('/') === false, 'roten är inte en data-spärr');
p1_assert(jeab_http_path_is_sensitive('/index.php') === false, 'index.php är inte en data-spärr');

$ninetyDays = 90 * 24 * 60 * 60;
p1_assert(JEAB_AUTH_TTL === $ninetyDays, 'JEAB_AUTH_TTL är 90 dagar');
p1_assert(JEAB_AUTH_TTL > 30 * 24 * 60 * 60, 'TTL är längre än 30 dagar');
p1_assert(jeab_auth_cookie_params()['max_age'] === $ninetyDays, 'kakans max_age är 90 dagar');
p1_assert(str_contains(jeab_auth_cookie_header('probe'), 'Max-Age=' . $ninetyDays), 'Set-Cookie bär Max-Age 90 dagar');

$pwFile = $tmp . '/pw-ttl.json';
$keepHash = 'p1-keep-hash';
$dropHash = 'p1-drop-hash';
file_put_contents($pwFile, json_encode([
    'secret' => 'p1-ttl-probe-secret',
    'hashes' => [$keepHash, $dropHash],
], JSON_UNESCAPED_UNICODE));
$prevPw = getenv('JEAB_PASSWORDS_FILE');
putenv('JEAB_PASSWORDS_FILE=' . $pwFile);
jeab_auth_data(true);
$now = time();
$GLOBALS['_JEAB_AUTH_NOW'] = $now;
$fp = jeab_auth_hash_fingerprint($keepHash);
$youngPayload = '3.' . $fp . '.-.' . ($now - 31 * 24 * 60 * 60) . '.abcd';
$young = $youngPayload . '.' . hash_hmac('sha256', $youngPayload, 'p1-ttl-probe-secret');
p1_assert(jeab_auth_value_ok($young) === true, 'kaka äldre än 30 dagar gäller');
$oldPayload = '3.' . $fp . '.-.' . ($now - $ninetyDays - 2 * 24 * 60 * 60) . '.abcd';
$old = $oldPayload . '.' . hash_hmac('sha256', $oldPayload, 'p1-ttl-probe-secret');
p1_assert(jeab_auth_value_ok($old) === false, 'kaka äldre än 90 dagar gäller inte');
$v1Payload = '1.' . ($now - 60) . '.abcd';
$v1 = $v1Payload . '.' . hash_hmac('sha256', $v1Payload, 'p1-ttl-probe-secret');
p1_assert(jeab_auth_value_ok($v1) === true, 'obunden v1-kaka gäller medan någon hash finns');
$_COOKIE[JEAB_AUTH_COOKIE] = $v1;
jeab_auth_renew_cookie();
p1_assert(($_COOKIE[JEAB_AUTH_COOKIE] ?? '') === $v1, 'giltig v1-kaka rensas inte vid förnyelse');
$dropFp = jeab_auth_hash_fingerprint($dropHash);
$dropPayload = '3.' . $dropFp . '.-.' . (string) $now . '.abcd';
$dropCookie = $dropPayload . '.' . hash_hmac('sha256', $dropPayload, 'p1-ttl-probe-secret');
p1_assert(jeab_auth_value_ok($dropCookie) === true, 'kaka bunden till hash i listan gäller');
file_put_contents($pwFile, json_encode([
    'secret' => 'p1-ttl-probe-secret',
    'hashes' => [$keepHash],
], JSON_UNESCAPED_UNICODE));
jeab_auth_data(true);
p1_assert(jeab_auth_value_ok($dropCookie) === false, 'borttagen hash loggar ut befintlig kaka');
p1_assert(jeab_auth_value_ok($young) === true, 'kvarvarande hash fortsätter gälla');
p1_assert(jeab_auth_value_ok($v1) === true, 'v1-kaka gäller så länge någon hash finns kvar');
file_put_contents($pwFile, json_encode([
    'secret' => 'p1-ttl-probe-secret',
    'hashes' => [],
], JSON_UNESCAPED_UNICODE));
jeab_auth_data(true);
p1_assert(jeab_auth_value_ok($v1) === false, 'v1-kaka gäller inte när listan är tom');
unset($_COOKIE[JEAB_AUTH_COOKIE], $GLOBALS['_JEAB_AUTH_NOW']);
if ($prevPw === false) {
    putenv('JEAB_PASSWORDS_FILE');
} else {
    putenv('JEAB_PASSWORDS_FILE=' . $prevPw);
}

$savedDataDir = $GLOBALS['_JEAB_DATA_DIR'] ?? null;
$savedPublicDir = $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] ?? null;

$blocked = $tmp . '/not-a-directory';
file_put_contents($blocked, 'x');
$pubBlocked = $tmp . '/public-blocked';
mkdir($pubBlocked, 0775, true);
file_put_contents($pubBlocked . '/notes.json', '{"next_id":2,"notes":[{"id":1,"title":"Kvar","body":"","section":"faltnoteringar"}]}');
$GLOBALS['_JEAB_DATA_DIR'] = $blocked . '/private';
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $pubBlocked;
$kept = jeab_notes_load();
p1_assert(($kept['notes'][0]['title'] ?? '') === 'Kvar', 'saknad privat katalog tappar inte befintliga anteckningar');
p1_assert(is_file($pubBlocked . '/notes.json'), 'publik notes.json ligger kvar när flytten inte går');
p1_assert(!is_file($blocked . '/private/notes.json'), 'ingen tom privat fil skapas när katalogen saknas');

$privateNotes = $tmp . '/private-notes';
$publicNotes = $tmp . '/public-notes';
mkdir($privateNotes, 0775, true);
mkdir($publicNotes . '/notes-photos/7', 0775, true);
$noteBody = '{"next_id":4,"notes":[{"id":7,"title":"Falt","body":"text","section":"faltnoteringar","photos":[{"id":"abcdef0123456789","name":"bild.jpg","mime":"image/jpeg","size":4}]}]}';
file_put_contents($publicNotes . '/notes.json', $noteBody);
file_put_contents($publicNotes . '/notes-photos/7/abcdef0123456789.jpg', 'jpeg');
$GLOBALS['_JEAB_DATA_DIR'] = $privateNotes;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $publicNotes;
$notesPath = jeab_notes_path();
p1_assert(str_starts_with(str_replace('\\', '/', $notesPath), str_replace('\\', '/', $privateNotes) . '/'), 'notes.json landar i privat katalog');
p1_assert(jeab_notes_is_public_store($notesPath) === false, 'notes-sökvägen är inte publik data/');
p1_assert(!is_file($publicNotes . '/notes.json'), 'publik notes.json tas bort efter flytt');
p1_assert(str_contains((string) file_get_contents($notesPath), 'Falt'), 'anteckningen följde med');
$photoDir = jeab_notes_photos_dir();
p1_assert(is_file($photoDir . '/7/abcdef0123456789.jpg'), 'bilden följde med till privat katalog');
p1_assert(!is_file($publicNotes . '/notes-photos/7/abcdef0123456789.jpg'), 'publik bild är borta');
p1_assert(jeab_notes_is_public_store($photoDir) === false, 'bildkatalogen är inte publik data/');
$loadedNotes = jeab_notes_load();
p1_assert(($loadedNotes['notes'][0]['title'] ?? '') === 'Falt', 'inläsning ser den flyttade anteckningen');

$freshPrivate = $tmp . '/fresh-private';
$freshPublic = $tmp . '/fresh-public';
mkdir($freshPrivate, 0775, true);
mkdir($freshPublic, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $freshPrivate;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $freshPublic;
$fresh = jeab_notes_load();
p1_assert($fresh['notes'] === [], 'första anropet utan anteckningar ger tom lista');
p1_assert(!is_file($freshPrivate . '/notes.json'), 'första anropet skriver ingen notes.json');
p1_assert(!is_file($freshPublic . '/notes.json'), 'första anropet skapar ingen publik notes.json');

if (is_string($savedDataDir)) {
    $GLOBALS['_JEAB_DATA_DIR'] = $savedDataDir;
} else {
    unset($GLOBALS['_JEAB_DATA_DIR']);
}
if (is_string($savedPublicDir)) {
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $savedPublicDir;
} else {
    unset($GLOBALS['_JEAB_PUBLIC_DATA_DIR']);
}

echo "p1 security: ok\n";
