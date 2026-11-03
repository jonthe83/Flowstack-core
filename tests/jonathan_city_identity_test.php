<?php

declare(strict_types=1);

/**
 * Sam L och Sam W har samma förnamn. Utan sparat kodhuvud (9 tecken)
 * är identiteten osäker: domänen väljer inte, och kakan är inte admin.
 * Ett sparat SamL-huvud är Sam L. Unika förnamn binds fortfarande av e-post.
 */

$tmp = sys_get_temp_dir() . '/jeab-sam-city-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$hashL = password_hash('sam-jarfalla-hemlig', PASSWORD_DEFAULT);
$hashW = password_hash('sam-malmo-hemlig', PASSWORD_DEFAULT);
$hashBare = password_hash('sam-utan-hemlig', PASSWORD_DEFAULT);
$hashElin = password_hash('elin-hemlig-unik', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $hashL],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ['hash' => $hashBare],
        ['hash' => $hashElin],
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

function jci(bool $ok, string $msg): void
{
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    fwrite(STDERR, "FAIL $msg\n");
    exit(1);
}

function jci_cookie(?string $email, string $hash): void
{
    $_COOKIE = [];
    $_SESSION = [];
    $_GET = [];
    $GLOBALS['_JEAB_WHO_REASON'] = '';
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

jci(jeab_person_from_login('sam-jarfalla-hemlig', 'demo@example.test') === null && jeab_who_identity_reason() === 'ambiguous-no-email', 'Sam utan L/W blir ingen även med jarfalla-domän');
jci(jeab_person_from_login('sam-malmo-hemlig', 'demo@malmo.example.test') === null && jeab_who_identity_reason() === 'ambiguous-no-email', 'Sam utan L/W blir ingen även med malmö-domän');
jci(jeab_person_from_login('sam-utan-hemlig', '') === null, 'sam utan e-post gissas inte');
jci(jeab_person_by_code_prefix('sam-utan-hemlig') === null, 'förnamnsprefixet ensamt är inte en Sam');
jci((jeab_person_from_login('elin-hemlig-unik', '')['id'] ?? '') === 'Elin', 'unikt förnamn utan e-post binder');

// Gammal kaka: sparad gissning utan huvud, och radens e-post. Domänen får inte välja.
jeab_code_people_save(jeab_auth_hash_fingerprint($hashL), 'SamW', 'code');
jci_cookie(null, $hashL);
$_GET['co'] = 'malmo';
$_GET['p'] = 'reminders';
$_SESSION['jeab_company'] = 'malmo';
$_SESSION['jeab_email'] = 'demo@malmo.example.test';
$noHeadRows = jeab_login_rows_diag();
$noHeadJarfalla = null;
foreach ($noHeadRows as $row) {
    if (($row['domain'] ?? '') === 'jarfalla.example.test') {
        $noHeadJarfalla = $row;
    }
}
jci(is_array($noHeadJarfalla) && ($noHeadJarfalla['person'] ?? 'x') === '' && !empty($noHeadJarfalla['assign']) && str_contains((string) ($noHeadJarfalla['flags'] ?? ''), 'osäker'), 'rad utan huvud är osäker och kan pekas om');
jci(jeab_auth_user_person() === null && jeab_who_identity_reason() === 'ambiguous-no-head', 'Järfälla-rad utan huvud är ingen person');
jci(jeab_auth_user_email() === 'demo@example.test', 'sessionens Malmö-adress skrivs över av raden');
jci(jeab_who_me_name() === '', 'Mina har inget namn utan huvud');
jci(jeab_who_scope_note_html('malmo') === '', 'ingen notis som gissar Sam');
jci(jeab_reminder_who_filter() === null, 'listan är Alla när huvudet saknas');
jci(!jeab_is_admin() && !jeab_login_diag_allowed(), 'Järfälla-adress utan huvud är inte admin');

jeab_code_people_save(jeab_auth_hash_fingerprint($hashL), 'SamL', 'prefix', 'SamL');
jci_cookie(null, $hashL);
$_GET = ['co' => 'jarfalla', 'p' => 'reminders'];
unset($_SESSION['jeab_reminder_who']);
jci((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_is_admin(), 'SamL-huvud är Sam L och admin');
jci(jeab_who_me_name() === 'Sam L' && jeab_reminder_who_filter() === 'Sam L', 'Mina följer det sparade huvudet');
jci(jeab_login_diag_allowed(), 'Sam L med huvud får se inloggningslistan');

// Malmö-rad utan huvud blir inte Sam W, oavsett stadsväljare.
jci_cookie(null, $hashW);
$_GET = ['co' => 'jarfalla', 'p' => 'reminders'];
$_SESSION['jeab_company'] = 'jarfalla';
$_SESSION['jeab_email'] = 'demo@example.test';
jci(jeab_auth_user_person() === null && jeab_who_me_name() === '', 'Malmö-rad utan huvud blir inte Sam W');
jci(jeab_who_scope_note_html('jarfalla') === '', 'notisen gissar inte Sam W');
jci(jeab_reminder_who_filter() === null, 'Järfälla-listan är Alla utan huvud');
jci(!jeab_is_admin(), 'Malmö-rad utan huvud är inte admin');

// Ingen e-post: gammal gissning glöms på nästa sidträff, utan ny inloggning.
jeab_code_people_save(jeab_auth_hash_fingerprint($hashBare), 'SamW', 'code');
$rowsBefore = jeab_login_rows_diag();
$bareFlagged = false;
foreach ($rowsBefore as $row) {
    if (($row['domain'] ?? '') === '' && str_contains((string) ($row['flags'] ?? ''), 'tvetydig sparad gissning')) {
        $bareFlagged = true;
    }
}
jci($bareFlagged, 'diagnos flaggar sparad Sam-gissning utan e-post');
jci_cookie(null, $hashBare);
$_GET['co'] = 'malmo';
$_SESSION['jeab_company'] = 'malmo';
$_SESSION['jeab_email'] = 'demo@malmo.example.test';
jci(jeab_auth_user_person() === null, 'sparad Sam W utan e-post visas inte');
jci(jeab_who_identity_reason() === 'ambiguous-no-head', 'orsaken är en sparad gissning utan huvud');
jci(jeab_auth_user_email() === null, 'rad utan e-post ärver inte sessionens adress');
jci(jeab_who_me_name() === '', 'Mina har inget namn');
jci(jeab_who_scope_note_html('malmo') === '', 'ingen notis som gissar ett namn');
jci(!jeab_is_admin(), 'odelad Sam-rad är inte admin');
jci(jeab_reminder_who_filter() === null, 'utan person är listan Alla');

// Unikt förnamn utan e-post fungerar fortfarande.
jeab_code_people_save(jeab_auth_hash_fingerprint($hashElin), 'Elin', 'code');
jci_cookie(null, $hashElin);
jci((jeab_auth_user_person()['id'] ?? '') === 'Elin', 'Elin utan e-post stannar Elin');
jci(jeab_login_diag_allowed() === false, 'Elin är inte admin och ser inte inloggningslistan');

$rows = jeab_login_rows_diag();
$byDomain = [];
foreach ($rows as $row) {
    $domain = (string) ($row['domain'] ?? '');
    if ($domain !== '') {
        $byDomain[$domain] = $row;
    }
}
jci(($byDomain['jarfalla.example.test']['person'] ?? '') === 'Sam L' && ($byDomain['jarfalla.example.test']['head'] ?? '') === 'SamL', 'diagnos: sparat SamL-huvud är Sam L');
jci(($byDomain['jarfalla.example.test']['city'] ?? '') === 'jarfalla', 'diagnos: jarfalla-domän');
jci(($byDomain['jarfalla.example.test']['first'] ?? '') === 'sam', 'diagnos: förnamn utan kod');
jci(($byDomain['malmo.example.test']['person'] ?? 'x') === '' && str_contains((string) ($byDomain['malmo.example.test']['flags'] ?? ''), 'osäker'), 'diagnos: malmö utan huvud är inte Sam W');
jci(($byDomain['malmo.example.test']['city'] ?? '') === 'malmo', 'diagnos: malmö-domän');
$missing = 0;
foreach ($rows as $row) {
    if (str_contains((string) ($row['flags'] ?? ''), 'saknar e-post')) {
        $missing++;
    }
}
jci($missing === 2, 'diagnos: båda raderna utan e-post flaggas');
$html = jeab_login_diag_html();
jci(!str_contains($html, 'sam-jarfalla') && !str_contains($html, 'hemlig') && !str_contains($html, '$2y$'), 'diagnosen visar varken kod eller hash');
jci(str_contains($html, 'jarfalla.example.test') && str_contains($html, 'Sam L'), 'diagnosen visar domän och person');

echo "sam city identity ok\n";
