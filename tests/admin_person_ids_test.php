<?php

declare(strict_types=1);

/**
 * Admin är en lista av person-id plus den delade koden.
 * Sam L kommer från lösenordsradens e-post eller från koden SamL….
 * Ett ensamt Sam… och Sam W är inte admin.
 */

$tmp = sys_get_temp_dir() . '/jeab-admin-ids-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$hashL = password_hash('sam-jarfalla-admin', PASSWORD_DEFAULT);
$hashW = password_hash('sam-malmo-admin', PASSWORD_DEFAULT);
$hashBare = password_hash('sam-utan-admin', PASSWORD_DEFAULT);
$hashElin = password_hash('elin-admin-check', PASSWORD_DEFAULT);
$hashShared = password_hash('kod-agenter-admin', PASSWORD_DEFAULT);
$hashJlCode = password_hash('SamL-personlig-admin', PASSWORD_DEFAULT);
$hashJwCode = password_hash('SamW-personlig-admin', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $hashL],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ['hash' => $hashBare],
        ['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin],
        ['email' => 'agenter@flowstack.example.test', 'hash' => $hashShared],
        ['hash' => $hashJlCode],
        ['hash' => $hashJwCode],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ai(bool $ok, string $msg): void
{
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$msg}\n");
    exit(1);
}

function ai_cookie(?string $email, string $hash): void
{
    $_COOKIE = [];
    $_SESSION = [];
    $_GET = [];
    $GLOBALS['_JEAB_WHO_REASON'] = '';
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

function ai_labels(): array
{
    return array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
}

ai(jeab_admin_person_ids() === ['SamL'], 'adminlistan är Sam L och kan utökas på ett ställe');
ai(jeab_person_by_code_prefix('sam-jarfalla-admin') === null, 'förnamnet Sam räcker inte');
ai((jeab_person_by_code_prefix('SamL-personlig-admin')['id'] ?? '') === 'SamL', 'SamL utan e-post är Sam L');
ai((jeab_person_by_code_prefix('SamW-personlig-admin')['id'] ?? '') === 'SamW', 'SamW utan e-post är Sam W');
foreach (['Niman' => 'niman-kod', 'Isaq' => 'isaq-kod', 'Abol' => 'abol-kod', 'Kim' => 'kim-kod', 'Roberto' => 'Roberto-kod', 'Elin' => 'elin-kod'] as $id => $code) {
    ai((jeab_person_by_code_prefix($code)['id'] ?? '') === $id, $code . ' är ' . $id);
}

ai_cookie(null, $hashL);
$_GET['co'] = 'malmo';
$_SESSION['jeab_company'] = 'malmo';
ai(jeab_auth_user_person() === null && jeab_who_identity_reason() === 'ambiguous-no-head' && !jeab_is_admin(), 'Järfälla-rad utan huvud är inte admin');
ai(!in_array('Användare', ai_labels(), true) && !jeab_offert_utskick_allowed(), 'Järfälla-adress utan huvud ser inte Inloggningar');

ai_cookie(null, $hashW);
$_GET['co'] = 'jarfalla';
$_SESSION['jeab_company'] = 'jarfalla';
$_SESSION['jeab_email'] = 'demo@example.test';
ai(jeab_auth_user_person() === null && jeab_who_me_name() === '' && !jeab_is_admin(), 'Malmö-rad utan huvud blir inte Sam W');
ai(in_array('Utskick', ai_labels(), true) && !in_array('Offertutskick', ai_labels(), true) && !in_array('Användare', ai_labels(), true) && !jeab_offert_utskick_allowed(), 'osäker Sam ser Utskick men varken Inloggningar eller offertutskick');

jeab_code_people_save(jeab_auth_hash_fingerprint($hashBare), 'SamL', 'code');
ai_cookie(null, $hashBare);
$_SESSION['jeab_email'] = 'demo@example.test';
$_SESSION['jeab_company'] = 'jarfalla';
ai(jeab_auth_user_person() === null && jeab_personal_login_person() === null && !jeab_is_admin(), 'kod utan e-post är inte admin även med sparad gissning');
ai(in_array('Utskick', ai_labels(), true) && !jeab_offert_utskick_allowed(), 'tvetydig Sam får inte skicka offerter');

ai_cookie(null, $hashElin);
ai((jeab_auth_user_person()['id'] ?? '') === 'Elin' && !jeab_is_admin(), 'övrig personal är inte admin');

jeab_code_people_save(jeab_auth_hash_fingerprint($hashJlCode), 'SamW', 'code');
jeab_code_people_remember('SamL-personlig-admin', $hashJlCode, null);
ai_cookie(null, $hashJlCode);
ai((jeab_auth_user_person()['id'] ?? '') === 'SamL' && (jeab_personal_login_person()['id'] ?? '') === 'SamL' && jeab_is_admin(), 'Sam L:s kod utan e-post är admin');
ai(jeab_who_me_name() === 'Sam L', 'Mina är Sam L');
ai(in_array('Användare', ai_labels(), true) && jeab_offert_utskick_allowed(), 'SamL-huvud ser Inloggningar och får skicka offerter');
ai((jeab_code_people_read()['fp:' . jeab_auth_hash_fingerprint($hashJlCode)]['person'] ?? '') === 'SamL', 'gammal Sam W-gissning är borta');

jeab_code_people_remember('SamW-personlig-admin', $hashJwCode, null);
ai_cookie(null, $hashJwCode);
ai((jeab_auth_user_person()['id'] ?? '') === 'SamW' && !jeab_is_admin() && jeab_who_me_name() === 'Sam W', 'Sam W:s kod är inte admin');

ai((jeab_person_from_login('SamL-personlig-admin', 'demo@malmo.example.test')['id'] ?? '') === 'SamL' && jeab_who_identity_reason() === 'code', 'SamL slår malmö-e-post');

ai_cookie(null, $hashShared);
ai(jeab_auth_user_person() === null && jeab_is_admin(), 'delad kod är admin och ingen person');
ai(in_array('Utskick', ai_labels(), true) && !in_array('Offertutskick', ai_labels(), true) && jeab_offert_utskick_allowed(), 'delad kod ser Utskick och får skicka offerter');

echo "admin person ids: ok\n";
