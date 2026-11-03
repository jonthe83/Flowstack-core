<?php

declare(strict_types=1);

/**
 * G-01: agenter@flowstack.example.test är ingen person och är admin.
 * Admin är Sam L:s egen e-post eller en kod som börjar med hans användarnamn,
 * och den delade agentkoden. Övrig personal behåller sin vanliga inloggning.
 * Okänd inloggning utan person är inte admin och har inga bolag.
 */

$tmp = sys_get_temp_dir() . '/jeab-admin-login-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$hashShared = password_hash('kod-agenter', PASSWORD_DEFAULT);
$hashJl = password_hash('kod-sam-epost', PASSWORD_DEFAULT);
$hashCode = password_hash('sam-personlig-kod', PASSWORD_DEFAULT);
$hashElinCode = password_hash('elin-personlig-kod', PASSWORD_DEFAULT);
$hashW = password_hash('kod-samw', PASSWORD_DEFAULT);
$hashElin = password_hash('kod-elin', PASSWORD_DEFAULT);
$hashR = password_hash('kod-roberto', PASSWORD_DEFAULT);
$hashJlBare = password_hash('SamL-utan-epost-xyz', PASSWORD_DEFAULT);
$hashJwBare = password_hash('SamW-utan-epost-xyz', PASSWORD_DEFAULT);
$hashKrockW = password_hash('SamW-mot-jarfalla-xyz', PASSWORD_DEFAULT);
$hashKrockL = password_hash('SamL-mot-malmo-xyz', PASSWORD_DEFAULT);

file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'agenter@flowstack.example.test', 'hash' => $hashShared],
        ['email' => 'demo@example.test', 'hash' => $hashJl],
        ['hash' => $hashCode],
        ['hash' => $hashElinCode],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin],
        ['email' => 'saljare3@jarfalla.example.test', 'hash' => $hashR],
        ['hash' => $hashJlBare],
        ['hash' => $hashJwBare],
        ['email' => 'demo@example.test', 'hash' => $hashKrockW],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashKrockL],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'login_people' => [
        'agenter@flowstack.example.test' => 'SamL',
        'vikarie@example.test' => 'Elin',
    ],
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ad(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ad_cookie(?string $email, string $hash): void
{
    $_SESSION = [];
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

function ad_email(string $email): void
{
    $_SESSION = ['jeab_email' => $email];
    unset($_COOKIE[JEAB_AUTH_COOKIE]);
}

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
ad(substr_count($index, 'jeab_is_admin()') >= 2, 'städning och tyst borttagning använder admin-flaggan');
ad(!str_contains($index, "['saml', 'samw']"), 'index.php jämför inte längre person-id mot Sam');

ad(jeab_login_person_map() === [] || !isset(jeab_login_person_map()['agenter@flowstack.example.test']), 'kopplingstabellen har inte agenter@');
ad(!array_key_exists('agenter@flowstack.example.test', jeab_login_person_map()), 'config kan inte göra agentkoden till en person');
ad((jeab_person_by_login('vikarie@example.test')['id'] ?? '') === 'Elin', 'annan config-koppling finns kvar');
ad(jeab_person_by_login('agenter@flowstack.example.test') === null, 'agenter@ är ingen person');
ad((jeab_person_by_login('demo@example.test')['id'] ?? '') === 'SamL', 'Sam L:s e-post är kvar');

foreach (['Roberto', 'Elin', 'Niman', 'Isaq', 'SamW', 'Abol', 'Kim', 'SamL'] as $id) {
    $row = null;
    foreach (jeab_people() as $person) {
        if ($person['id'] === $id) {
            $row = $person;
        }
    }
    ad($row !== null, $id . ' finns i registret');
    ad_email((string) $row['email']);
    $me = jeab_auth_user_person();
    if ($id === 'SamL' || $id === 'SamW') {
        ad($me === null && !jeab_is_admin() && !jeab_inkorg_is_admin() && !jeab_inkorg_can_approve(), $id . ' utan huvud är ingen person och inte admin');
        continue;
    }
    ad(is_array($me) && ($me['id'] ?? '') === $id, $id . ' behåller sin inloggning');
    ad(!jeab_is_admin(), $id . ' är inte admin');
    ad(!jeab_inkorg_is_admin(), $id . ' är inte inkorgsadmin');
    ad(!jeab_inkorg_can_approve(), $id . ' godkänner inte');
    if ($id === 'Elin') {
        ad(jeab_inkorg_person_companies() === ['jarfalla'], 'Elin hör till Järfälla');
        ad(jeab_visible_company_names('jarfalla') === ['Elin'], 'Elin ser sitt namn');
        ad(jeab_filter_login_preset('jarfalla') === ['who' => 'Elin', 'job' => 'laddbox'], 'Elin förväljer Elin och Elbilsladdare');
    }
}

ad_email('agenter@flowstack.example.test');
ad(jeab_auth_user_person() === null && jeab_is_admin(), 'session som agenter@ är ingen person och är admin');
ad(jeab_inkorg_is_admin() && jeab_inkorg_can_approve(), 'agenter@ är inkorgsadmin');
ad(jeab_actor_companies() === ['jarfalla', 'malmo'] && jeab_inkorg_person_companies() === ['jarfalla', 'malmo'], 'agenter@ har båda bolagen');
ad(jeab_may_edit_webhook(), 'agenter@ får spara Zapier');
jeab_inkorg_require_admin();
ad(true, 'agenter@ kan spara inkorgsadmin');

$sharedFp = jeab_auth_hash_fingerprint($hashShared);
jeab_code_people_save($sharedFp, 'SamL', 'email');
ad_cookie('agenter@flowstack.example.test', $hashShared);
ad((jeab_code_people_read()['fp:' . $sharedFp]['person'] ?? '') === 'SamL', 'gammalt via=email ligger kvar i filen');
ad(jeab_auth_user_person() === null, 'gammalt via=email gör inte agentkoden till Sam L');
ad(jeab_is_admin() && jeab_inkorg_is_admin() && jeab_inkorg_can_approve(), 'gammalt via=email tar inte bort admin på agentkoden');
ad(!jeab_inkorg_login_entry_linked(['email' => 'agenter@flowstack.example.test', 'hash' => $hashShared]), 'agentkoden räknas inte som kopplad');

jeab_who_pick_save((string) jeab_who_cookie_info()['key'], 'SamL');
$_SESSION['jeab_who_pick'][(string) jeab_who_cookie_info()['key']] = 'SamL';
ad(jeab_who_me_person() === null && jeab_auth_user_person() === null, 'sparat val gör inte den delade koden till en person');
ad(jeab_is_admin() && jeab_inkorg_can_approve(), 'admin följer den delade adressen');

jeab_code_people_remember('sam-hemlig-kod', $hashCode);
ad_cookie(null, $hashCode);
ad(jeab_auth_user_person() === null && !jeab_is_admin(), 'sam utan e-post är inte en person och inte admin');
$seen = jeab_code_people_remember('sam-hemlig-kod', $hashCode, 'demo@example.test');
ad($seen === null && jeab_who_identity_reason() === 'ambiguous-no-email', 'Sam utan L/W blir ingen även med Järfälla-adress');
ad_cookie(null, $hashCode);
ad(jeab_auth_user_person() === null, 'utan e-post på raden blir Sam inte kvar');
ad(jeab_who_identity_reason() === 'ambiguous-stored', 'sparad tvetydig kod ger ingen gissad Sam');
ad(!jeab_is_admin(), 'gissad Sam-kod är inte admin');
jeab_code_people_remember('sam-annan-kod', $hashW, 'demo@malmo.example.test');
ad_cookie('demo@malmo.example.test', $hashW);
ad(jeab_auth_user_person() === null && !jeab_is_admin(), 'samma förnamn och Malmö-adressen blir inte Sam W');

jeab_code_people_remember('elin-hemlig-kod', $hashElinCode);
ad_cookie(null, $hashElinCode);
ad((jeab_auth_user_person()['id'] ?? '') === 'Elin', 'kod som börjar med elin är Elin');
ad(!jeab_is_admin() && jeab_inkorg_person_companies() === ['jarfalla'], 'Elins kod är inte admin och hör till Järfälla');
ad(jeab_filter_login_preset('jarfalla') === ['who' => 'Elin', 'job' => 'laddbox'], 'Elins kod förväljer som förut');

ad_cookie('demo@example.test', $hashJl);
ad(jeab_auth_user_person() === null && jeab_personal_login_person() === null && !jeab_is_admin(), 'demo@example.test utan huvud är inte admin');
ad(!jeab_inkorg_admin_allowed(null), 'ingen person är inte admin');
$jw = null;
foreach (jeab_people() as $person) {
    if ($person['id'] === 'SamW') {
        $jw = $person;
    }
}
ad(is_array($jw) && !jeab_inkorg_admin_allowed($jw), 'Sam W blir inte admin av Sam L:s session');

jeab_code_people_save(jeab_auth_hash_fingerprint($hashJlBare), 'SamW', 'code');
jeab_code_people_remember('SamL-utan-epost-xyz', $hashJlBare, null);
ad_cookie(null, $hashJlBare);
ad((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_who_me_name() === 'Sam L' && jeab_is_admin(), 'SamL utan e-post är Sam L och admin');
ad((jeab_personal_login_person()['id'] ?? '') === 'SamL', 'admin-spåret är samma Sam L');
ad((jeab_code_people_read()['fp:' . jeab_auth_hash_fingerprint($hashJlBare)]['via'] ?? '') === 'prefix', 'gammalt Sam W-prefix är ersatt');

jeab_code_people_remember('SamW-utan-epost-xyz', $hashJwBare, null);
ad_cookie(null, $hashJwBare);
ad((jeab_auth_user_person()['id'] ?? '') === 'SamW' && jeab_who_me_name() === 'Sam W' && !jeab_is_admin(), 'SamW utan e-post är inte admin');

jeab_code_people_remember('SamW-mot-jarfalla-xyz', $hashKrockW, 'demo@example.test');
ad_cookie(null, $hashKrockW);
ad((jeab_auth_user_person()['id'] ?? '') === 'SamW' && (jeab_personal_login_person()['id'] ?? '') === 'SamW' && !jeab_is_admin() && jeab_who_me_name() === 'Sam W', 'SamW mot Järfälla-e-post är Sam W och inte admin');

jeab_code_people_remember('SamL-mot-malmo-xyz', $hashKrockL, 'demo@malmo.example.test');
ad_cookie(null, $hashKrockL);
ad((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_is_admin() && jeab_who_me_name() === 'Sam L', 'SamL mot Malmö-e-post är Sam L och admin');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
unset($_SESSION['jeab_reminder_who']);
ad(jeab_reminder_who_filter() === 'Sam L', 'Mina är Sam L');
$_SESSION['jeab_reminder_who'] = ['jarfalla' => ['who' => 'Sam W', 'at' => time()]];
ad(jeab_reminder_who_filter() === 'Sam L', 'sparat Sam W-val släpps för Sam L');

ad_email('okand@flowstack.example.test');
ad(jeab_auth_user_person() === null && !jeab_is_admin(), 'okand@flowstack.example.test är inte admin');
ad(jeab_actor_companies() === [] && jeab_inkorg_person_companies() === [], 'okand@flowstack.example.test har inga bolag');

$_SESSION = [];
unset($_COOKIE[JEAB_AUTH_COOKIE]);
ad(!jeab_is_admin() && !jeab_inkorg_can_approve(), 'utan inloggning är ingen admin');

echo "admin personal login: ok\n";
