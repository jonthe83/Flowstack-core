<?php

declare(strict_types=1);

/** Lösenordsbutiken för users.add_restricted ska vara samma som inloggningen. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-auth-store-' . bin2hex(random_bytes(4));
$public = $tmp . '/public';
$privat = $tmp . '/privat';
$site = $tmp . '/site';
mkdir($public . '/data/.private', 0775, true);
mkdir($privat, 0775, true);
mkdir($site . '/public_html', 0775, true);
mkdir($site . '/privat', 0775, true);
mkdir($tmp . '/outside', 0775, true);

$privatFile = $privat . '/passwords.json';
$webFile = $public . '/data/.private/passwords.json';
$publicFile = $public . '/passwords.json';
$siteFile = $site . '/privat/passwords.json';
$siteWebFile = $site . '/public_html/passwords.json';
$usersFile = $privat . '/restricted-users.json';
$siteUsers = $tmp . '/outside/restricted-users.json';

$staff = 'personal' . bin2hex(random_bytes(6));
$other = 'annan' . bin2hex(random_bytes(6));
$secret = 'store-secret-' . bin2hex(random_bytes(4));

$writeStore = static function (string $path, string $code) use ($secret): void {
    file_put_contents($path, json_encode([
        'secret' => $secret,
        'hashes' => [[
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'email' => 'demo@example.test',
        ]],
    ], JSON_PRETTY_PRINT));
    chmod($path, 0600);
};

$writeStore($privatFile, $staff);
$writeStore($webFile, $other);
$writeStore($publicFile, $other);
$writeStore($siteFile, $staff);
$writeStore($siteWebFile, $other);

$GLOBALS['_JEAB_DATA_DIR'] = $tmp . '/data';
$GLOBALS['_JEAB_APP_ROOT'] = $public;
$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = $usersFile;
mkdir($tmp . '/data', 0775, true);
file_put_contents($tmp . '/data/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'auth-store-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
putenv('JEAB_PASSWORDS_FILE');
unset($_ENV['JEAB_PASSWORDS_FILE']);
putenv('JEAB_DISABLE_SQLITE=1');

require dirname(__DIR__) . '/src/bootstrap.php';

function as_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function as_absent(string $haystack, string $secret, string $message): void
{
    as_ok($secret !== '' && !str_contains($haystack, $secret), $message);
}

$resolved = jeab_auth_path();
as_ok(str_starts_with($resolved, $tmp . '/'), 'resolver stannar i testkatalogen');
as_ok($resolved === $privatFile, 'syskonet privat/ väljs före kopian i webbroten');
as_ok(jeab_auth_path_is_private($privatFile), 'privat/ bredvid public_html är privat');
as_ok(!jeab_auth_path_is_private($webFile), 'data/.private skrivs inte när inloggningen använder privat/');
as_ok(!is_file($public . '/data/.private/.htaccess'), 'ingen spärr skapas för en fil inloggningen inte använder');
as_ok(!jeab_auth_path_is_private($publicFile), 'lösenord i webbroten skrivs inte');
as_ok(jeab_auth_path_is_private($public . '/../privat/passwords.json'), 'sökväg med .. ut ur public_html är privat');
as_ok(jeab_auth_normalize_path('/flowstack.example.test/public_html/../privat/passwords.json') === '/flowstack.example.test/privat/passwords.json', '.. normaliseras utan realpath');

$diag = jeab_auth_diag();
$diagJson = json_encode($diag, JSON_UNESCAPED_UNICODE);
as_ok(($diag['ok'] ?? false) === true && ($diag['source'] ?? '') === 'file' && ($diag['path_kind'] ?? '') === 'privat' && ($diag['via'] ?? '') === 'candidate' && ($diag['private'] ?? 0) === 1 && ($diag['exists'] ?? 0) === 1 && ($diag['ready'] ?? 0) === 1, 'diag säger fil i privat och att inloggningen kan den');
as_ok(jeab_auth_ready() && jeab_auth_password_ok($staff) && !jeab_auth_password_ok($other), 'inloggningen läser privat-filen, inte kopian i webbroten');
as_absent((string) $diagJson, $staff, 'diag ekar inte koden');
as_absent((string) $diagJson, $secret, 'diag ekar inte secret');
as_absent((string) $diagJson, $privatFile, 'diag ekar inte sökvägen');
as_ok(array_diff(array_keys($diag), ['ok', 'source', 'path_kind', 'via', 'private', 'exists', 'ready', 'status']) === [], 'diag har bara källan och sorten');

$name = 'Pilot ' . bin2hex(random_bytes(2));
$username = jeab_restricted_username($name);
$code = $username . bin2hex(random_bytes(8));
$created = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
$createdJson = json_encode($created, JSON_UNESCAPED_UNICODE);
as_ok(!empty($created['ok']) && ($created['created'] ?? 0) === 1, 'add_restricted skriver i privat-filen');
as_absent((string) $createdJson, $code, 'skapandet ekar inte koden');
as_ok(jeab_auth_password_ok($code), 'den nya koden går att logga in med');
$rawPrivat = (string) file_get_contents($privatFile);
$rawWeb = (string) file_get_contents($webFile);
as_absent($rawPrivat, $code, 'lösenordsfilen innehåller inte koden');
as_ok(substr_count($rawWeb, '"hash"') === 1, 'kopian i webbroten rörs inte');

$GLOBALS['_JEAB_APP_ROOT'] = $site;
$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = $siteUsers;
jeab_auth_data(true);
jeab_restricted_read(true);
$siteResolved = jeab_auth_path();
as_ok(str_starts_with($siteResolved, $tmp . '/'), 'site-resolver stannar i testkatalogen');
as_ok($siteResolved === $siteFile && jeab_auth_path_is_private($siteFile), 'privat/ accepteras när webbroten är siten som innehåller public_html');
as_ok(!jeab_auth_path_is_private($siteWebFile), 'public_html under siten är fortfarande stängd');
$siteDiag = jeab_auth_diag();
as_ok(($siteDiag['source'] ?? '') === 'file' && ($siteDiag['path_kind'] ?? '') === 'privat' && ($siteDiag['private'] ?? 0) === 1 && ($siteDiag['ready'] ?? 0) === 1, 'diag för privat-layouten är privat');
as_absent(json_encode($siteDiag, JSON_UNESCAPED_UNICODE), $siteFile, 'site-diag ekar inte sökvägen');
$siteName = 'Webbhotell ' . bin2hex(random_bytes(2));
$siteUser = jeab_restricted_username($siteName);
$siteCode = $siteUser . bin2hex(random_bytes(8));
$siteAdd = jeab_restricted_add(['name' => $siteName, 'role' => 'solkampanj', 'code' => $siteCode, 'dry' => 0, 'confirm' => 'SAM']);
as_ok(!empty($siteAdd['ok']) && ($siteAdd['created'] ?? 0) === 1, 'add_restricted går mot privat utanför public_html');
as_absent(json_encode($siteAdd, JSON_UNESCAPED_UNICODE), $siteCode, 'svaret ekar inte koden');
as_ok(jeab_auth_password_ok($siteCode), 'inloggningen ser koden som skrevs där');

$GLOBALS['_JEAB_APP_ROOT'] = $public;
$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = $usersFile;
putenv('JEAB_PASSWORDS_FILE=' . $publicFile);
jeab_auth_data(true);
jeab_restricted_read(true);
$blocked = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
as_ok(empty($blocked['ok']) && ($blocked['error'] ?? '') === 'Lösenordsfilen saknas.', 'env-fil i webbroten skrivs inte');
as_absent(json_encode($blocked, JSON_UNESCAPED_UNICODE), $code, 'avvisningen ekar inte koden');
$blockedDiag = jeab_auth_diag();
as_ok(($blockedDiag['via'] ?? '') === 'env' && ($blockedDiag['private'] ?? 1) === 0 && ($blockedDiag['path_kind'] ?? '') === 'other' && ($blockedDiag['ready'] ?? 0) === 1 && ($blockedDiag['source'] ?? '') === 'file', 'env inne i webbroten syns men är inte skrivbar');

unlink($privatFile);
unlink($siteFile);
putenv('JEAB_PASSWORDS_FILE');
unset($_ENV['JEAB_PASSWORDS_FILE']);
jeab_auth_data(true);
$privateDir = $public . '/data/.private';
$parentHt = $public . '/data/.htaccess';
file_put_contents($parentHt, "Require all denied\nDeny from all\n# parent\n");
$parentBefore = (string) file_get_contents($parentHt);
$onlyWeb = jeab_auth_diag();
$childHt = $privateDir . '/.htaccess';
$childText = (string) file_get_contents($childHt);
as_ok(jeab_auth_password_ok($other) && ($onlyWeb['ready'] ?? 0) === 1 && ($onlyWeb['private'] ?? 0) === 1 && ($onlyWeb['path_kind'] ?? '') === 'data_private' && ($onlyWeb['exists'] ?? 0) === 1 && ($onlyWeb['source'] ?? '') === 'file', 'spärrad data/.private är privat när inloggningen använder den');
as_ok(is_file($childHt) && str_contains($childText, 'Require all denied') && str_contains($childText, 'Deny from all'), 'saknad .htaccess skapas med båda nekanden');
as_ok((string) file_get_contents($parentHt) === $parentBefore, 'förälderns .htaccess rörs inte');
$againHt = (string) file_get_contents($childHt);
jeab_auth_diag();
as_ok((string) file_get_contents($childHt) === $againHt, 'en spärr som redan nekar skrivs inte om');
file_put_contents($childHt, "Options -Indexes\n# behåll\n");
as_ok(jeab_auth_path_is_private($webFile), 'filen blir privat när nekandet läggs till');
$mergedHt = (string) file_get_contents($childHt);
as_ok(str_starts_with($mergedHt, "Options -Indexes\n# behåll\n") && str_contains($mergedHt, 'Require all denied') && str_contains($mergedHt, 'Deny from all'), 'befintlig text behålls och nekandet läggs till');
jeab_auth_path_is_private($webFile);
as_ok((string) file_get_contents($childHt) === $mergedHt, 'nekandet läggs inte till en gång till');
$strong = "Options -Indexes\nRequire all denied\n# extra\n";
file_put_contents($childHt, $strong);
as_ok(jeab_auth_path_is_private($webFile) && (string) file_get_contents($childHt) === $strong, 'en fil som redan nekar försvagas inte');
$webBefore = substr_count((string) file_get_contents($webFile), '"hash"');
$publicBefore = (string) file_get_contents($publicFile);
$webName = 'Webb ' . bin2hex(random_bytes(2));
$webUser = jeab_restricted_username($webName);
$webCode = $webUser . bin2hex(random_bytes(8));
$webAdd = jeab_restricted_add(['name' => $webName, 'role' => 'solkampanj', 'code' => $webCode, 'dry' => 0, 'confirm' => 'SAM']);
as_ok(!empty($webAdd['ok']) && ($webAdd['created'] ?? 0) === 1 && substr_count((string) file_get_contents($webFile), '"hash"') === $webBefore + 1, 'add_restricted skriver den spärrade data/.private-filen');
as_absent(json_encode($webAdd, JSON_UNESCAPED_UNICODE), $webCode, 'data/.private-svaret ekar inte koden');
as_absent((string) file_get_contents($webFile), $webCode, 'data/.private-filen innehåller inte koden');
as_ok((string) file_get_contents($publicFile) === $publicBefore, 'en annan fil i public_html rörs inte');
file_put_contents($public . '/.htaccess', jeab_auth_deny_htaccess_block());
putenv('JEAB_PASSWORDS_FILE=' . $publicFile);
jeab_auth_data(true);
$flat = jeab_auth_diag();
$flatAdd = jeab_restricted_add(['name' => $webName, 'role' => 'solkampanj', 'code' => $webCode, 'dry' => 0, 'confirm' => 'SAM']);
as_ok(($flat['private'] ?? 1) === 0 && ($flat['path_kind'] ?? '') === 'other' && empty($flatAdd['ok']) && ($flatAdd['error'] ?? '') === 'Lösenordsfilen saknas.', 'övriga filer i public_html skrivs inte');
as_absent(json_encode($flat, JSON_UNESCAPED_UNICODE) . json_encode($flatAdd, JSON_UNESCAPED_UNICODE), $webCode, 'avvisad public_html ekar inte koden');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$at = strpos($hook, "=== 'users.auth_diag'");
$slice = $at === false ? '' : substr($hook, $at, 900);
as_ok($at !== false && str_contains($slice, 'jeab_inkorg_hook_authorized') && strpos($slice, 'jeab_inkorg_hook_authorized') < strpos($slice, 'jeab_auth_diag'), 'diag-hooken kräver token före läsningen');
as_ok(str_contains($slice, "'auth diag failed'") && !str_contains($slice, 'getMessage') && !str_contains($slice, '$input') && !str_contains($slice, '$raw'), 'diag-hooken loggar inte begäran');
$log = $tmp . '/php-error.log';
ini_set('error_log', $log);
$again = jeab_auth_diag();
as_absent(json_encode($again, JSON_UNESCAPED_UNICODE), $code, 'en andra diag ekar inte koden');
as_ok(!is_file($log) || !str_contains((string) file_get_contents($log), $code), 'felloggen innehåller inte koden');

echo "OK  auth store diag\n";
