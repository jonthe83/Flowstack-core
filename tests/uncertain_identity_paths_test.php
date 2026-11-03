<?php

declare(strict_types=1);

/**
 * Delat förnamn utan sparat huvud, och en kod som inte träffar prefix,
 * är osäkra i varje väg som tidigare läste personen från e-posten.
 * Unika förnamn följer e-posten. SamL… med huvud är Sam L.
 */

$tmp = sys_get_temp_dir() . '/jeab-uncertain-paths-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$hashW = password_hash('malmo-utan-huvud', PASSWORD_DEFAULT);
$hashL = password_hash('jarfalla-utan-huvud', PASSWORD_DEFAULT);
$hashElin = password_hash('elin-unik-vag', PASSWORD_DEFAULT);
$hashHead = password_hash('SamL-saker-vag', PASSWORD_DEFAULT);
$hashOther = password_hash('helt-annan-kod-xyz', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ['email' => 'demo@example.test', 'hash' => $hashL],
        ['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashHead],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashOther],
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

function ui(bool $ok, string $msg): void
{
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$msg}\n");
    exit(1);
}

function ui_cookie(?string $email, string $hash): void
{
    $_COOKIE = [];
    $_SESSION = [];
    $_GET = [];
    $GLOBALS['_JEAB_WHO_REASON'] = '';
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

ui(jeab_person_from_login('helt-annan-kod', 'demo@malmo.example.test') === null && jeab_who_identity_reason() === 'ambiguous-no-head', 'kod utan prefixträff blir inte Sam W');
ui((jeab_person_from_login('helt-annan-kod', 'saljare4@jarfalla.example.test')['id'] ?? '') === 'Elin', 'kod utan prefixträff följer unikt förnamn');
ui((jeab_certain_identity('demo@malmo.example.test', '', '')['person'] ?? null) === null, 'delat förnamn utan huvud är osäkert');
ui((jeab_certain_identity('saljare4@jarfalla.example.test', '', '')['person']['id'] ?? '') === 'Elin', 'unikt förnamn är säkert via e-post');
ui((jeab_certain_identity('demo@malmo.example.test', '', 'SamL')['person']['id'] ?? '') === 'SamL', 'SamL-huvud är säkert även mot Malmö-adress');

ui_cookie('demo@malmo.example.test', $hashW);
$_SESSION['jeab_code_person'] = 'samw';
ui(jeab_auth_user_person() === null && !jeab_is_admin(), 'Malmö-kaka utan huvud är ingen person och inte admin');
ui(!isset($_SESSION['jeab_code_person']), 'osäker inloggning tömmer jeab_code_person');
ui(jeab_egna_me_id() === '', 'Egna läser inte kvar en osäker kodperson');
ui(jeab_inkorg_me() === 'personal', 'avsandaren blir inte Sam W');
ui(jeab_default_sheet_assignees() === [], 'Vem åker förväljer inte Sam W');
ui(jeab_bug_reporter() === 'demo@malmo.example.test', 'felrapporten signeras inte med Sam W');
ui(jeab_actor_companies() === ['malmo', 'jarfalla'] && jeab_selected_company() === 'malmo', 'Malmö-kaka utan huvud är Malmö först och har båda kontoren');
ui(!jeab_inkorg_login_entry_linked(['email' => 'demo@malmo.example.test', 'hash' => $hashW]), 'Malmö-rad utan huvud räknas inte som kopplad');
$codes = jeab_who_codes();
$malmo = null;
foreach ($codes as $row) {
    if (($row['fp'] ?? '') === jeab_auth_hash_fingerprint($hashW)) {
        $malmo = $row;
    }
}
ui(is_array($malmo) && ($malmo['person'] ?? null) === null, 'whodiag sätter inte personen från Malmö-adressen');
ui((jeab_pdo_conn_label()['user'] ?? 'x') === '', 'anslutningsetiketten tar inte Sam från e-posten');

ui_cookie('demo@example.test', $hashL);
$_SESSION['jeab_code_person'] = 'saml';
ui(jeab_auth_user_person() === null && !jeab_is_admin() && !isset($_SESSION['jeab_code_person']), 'Järfälla-adress utan huvud är inte Sam L');
ui(jeab_inkorg_me() === 'personal' && jeab_actor_companies() === ['jarfalla', 'malmo'] && jeab_selected_company() === 'jarfalla', 'Järfälla-adress utan huvud ger ingen avsändare, Järfälla först och båda kontoren');

ui_cookie('saljare4@jarfalla.example.test', $hashElin);
ui((jeab_auth_user_person()['id'] ?? '') === 'Elin' && !jeab_is_admin(), 'Elin följer e-posten och är inte admin');
ui(jeab_inkorg_me() === 'Elin', 'Elin är avsändare');
ui(jeab_default_sheet_assignees() === ['Elin'], 'Vem åker förväljer Elin');
ui(jeab_bug_reporter() === 'Elin <saljare4@jarfalla.example.test>', 'felrapporten signeras Elin');
ui(jeab_actor_companies() === ['jarfalla', 'malmo'], 'Elin har hemma-kontoret först och båda kontoren');
ui(jeab_inkorg_login_entry_linked(['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin]), 'Elin-raden är kopplad');
ui((jeab_pdo_conn_label()['user'] ?? '') === 'Elin', 'anslutningsetiketten är Elin');

jeab_code_people_save(jeab_auth_hash_fingerprint($hashHead), 'SamL', 'prefix', 'SamL');
ui_cookie('demo@malmo.example.test', $hashHead);
ui((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_is_admin(), 'SamL-huvud är admin trots Malmö-adress');
ui(jeab_inkorg_me() === 'Sam L', 'SamL-huvud är avsändare');
ui(jeab_actor_companies() === ['jarfalla', 'malmo'], 'SamL-huvud har båda kontoren');
ui(jeab_inkorg_login_entry_linked(['email' => 'demo@malmo.example.test', 'hash' => $hashHead]), 'rad med SamL-huvud är kopplad');
$headRow = null;
foreach (jeab_who_codes() as $row) {
    if (($row['fp'] ?? '') === jeab_auth_hash_fingerprint($hashHead)) {
        $headRow = $row;
    }
}
ui(is_array($headRow) && ($headRow['person'] ?? '') === 'Sam L', 'whodiag visar Sam L när huvudet finns');
ui((jeab_pdo_conn_label()['user'] ?? '') === 'Sam', 'anslutningsetiketten följer SamL-huvudet');

echo "uncertain identity paths: ok\n";
