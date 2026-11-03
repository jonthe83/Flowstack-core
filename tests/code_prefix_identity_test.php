<?php

declare(strict_types=1);

/**
 * Person från inloggningen: längsta kodprefixet (saml, samw) först.
 * Ett ensamt Sam… är tvetydigt och blir ingen person. E-postdomänen ger stad
 * och väljer inte mellan Sam L och Sam W. Koden sparas aldrig, bara nio tecken.
 */

$tmp = sys_get_temp_dir() . '/jeab-code-prefix-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$people = [
    'Roberto' => ['prefix' => 'Roberto', 'email' => 'saljare3@jarfalla.example.test', 'company' => 'jarfalla', 'name' => 'Roberto'],
    'SamL' => ['prefix' => 'SamL', 'email' => 'demo@example.test', 'company' => 'jarfalla', 'name' => 'Sam L'],
    'Elin' => ['prefix' => 'Elin', 'email' => 'saljare4@jarfalla.example.test', 'company' => 'jarfalla', 'name' => 'Elin'],
    'Niman' => ['prefix' => 'Niman', 'email' => 'saljare5@jarfalla.example.test', 'company' => 'jarfalla', 'name' => 'Niman'],
    'Isaq' => ['prefix' => 'Isaq', 'email' => 'saljare6@malmo.example.test', 'company' => 'malmo', 'name' => 'Isaq'],
    'SamW' => ['prefix' => 'SamW', 'email' => 'demo@malmo.example.test', 'company' => 'malmo', 'name' => 'Sam W'],
    'Abol' => ['prefix' => 'Abol', 'email' => 'saljare7@malmo.example.test', 'company' => 'malmo', 'name' => 'Abol'],
    'Kim' => ['prefix' => 'Kim', 'email' => 'saljare.syd@malmo.example.test', 'company' => 'malmo', 'name' => 'Kim'],
];
$codes = [];
$entries = [];
foreach ($people as $id => $row) {
    $codes[$id] = $row['prefix'] . 'TEST-' . bin2hex(random_bytes(4));
    $entries[] = [
        'email' => $row['email'],
        'hash' => password_hash($codes[$id], PASSWORD_DEFAULT),
    ];
}
$codes['shared'] = 'deladTEST-' . bin2hex(random_bytes(4));
$codes['ambiguous'] = 'SamTEST-' . bin2hex(random_bytes(4));
$codes['none'] = 'xyzTEST-' . bin2hex(random_bytes(4));
$codes['jl_bare'] = 'SamLbare-' . bin2hex(random_bytes(3));
$codes['jw_bare'] = 'samwbare-' . bin2hex(random_bytes(3));
$codes['jl_krock'] = 'SamLkrock-' . bin2hex(random_bytes(3));
$codes['jw_krock'] = 'SamWkrock-' . bin2hex(random_bytes(3));
$entries[] = ['email' => 'agenter@flowstack.example.test', 'hash' => password_hash($codes['shared'], PASSWORD_DEFAULT)];
$entries[] = ['hash' => password_hash($codes['ambiguous'], PASSWORD_DEFAULT)];
$entries[] = ['hash' => password_hash($codes['none'], PASSWORD_DEFAULT)];
$entries[] = ['hash' => password_hash($codes['jl_bare'], PASSWORD_DEFAULT)];
$entries[] = ['hash' => password_hash($codes['jw_bare'], PASSWORD_DEFAULT)];
$entries[] = ['email' => 'demo@malmo.example.test', 'hash' => password_hash($codes['jl_krock'], PASSWORD_DEFAULT)];
$entries[] = ['email' => 'demo@example.test', 'hash' => password_hash($codes['jw_krock'], PASSWORD_DEFAULT)];
file_put_contents($tmp . '/passwords.json', json_encode(['secret' => bin2hex(random_bytes(16)), 'hashes' => $entries]));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
jeab_auth_data(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function cp_assert(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function cp_login(string $code): void
{
    $_SESSION = [];
    $_GET = [];
    $match = jeab_auth_match($code);
    cp_assert(is_array($match), 'koden matchar en rad');
    $email = (string) ($match['email'] ?? '');
    jeab_code_people_remember($code, (string) $match['hash'], $email !== '' ? $email : null);
    $_COOKIE[JEAB_AUTH_COOKIE] = jeab_auth_issue_value($email !== '' ? $email : null, (string) $match['hash']);
}

cp_assert((jeab_person_by_code_prefix($codes['SamL'])['id'] ?? '') === 'SamL', 'SamL utan e-post är Sam L');
cp_assert((jeab_person_by_code_prefix($codes['SamW'])['id'] ?? '') === 'SamW', 'SamW utan e-post är Sam W');
cp_assert((jeab_person_by_code_prefix('saml-hemlig')['id'] ?? '') === 'SamL', 'saml i gemener är Sam L');
cp_assert(jeab_person_by_code_prefix($codes['ambiguous']) === null, 'Sam utan L/W är ingen person');
cp_assert(jeab_person_from_login($codes['ambiguous'], 'demo@example.test') === null && jeab_who_identity_reason() === 'ambiguous-no-email', 'Sam utan initial blir ingen även med jarfalla.example.test');
cp_assert(jeab_person_from_login($codes['ambiguous'], 'demo@malmo.example.test') === null && jeab_who_identity_reason() === 'ambiguous-no-email', 'Sam utan initial blir ingen även med malmo.example.test');
cp_assert(jeab_person_from_login($codes['ambiguous'], '') === null, 'Sam utan initial och utan e-post är ingen');
cp_assert((jeab_person_from_login($codes['SamL'], 'demo@malmo.example.test')['id'] ?? '') === 'SamL' && jeab_who_identity_reason() === 'code', 'SamL slår malmö-adressen');
cp_assert((jeab_person_from_login($codes['SamW'], 'demo@example.test')['id'] ?? '') === 'SamW' && jeab_who_identity_reason() === 'code', 'SamW slår järfälla-adressen');
cp_assert((jeab_person_from_login($codes['SamL'], 'demo@example.test')['id'] ?? '') === 'SamL', 'SamL och jarfalla.example.test är Sam L');
cp_assert((jeab_person_from_login($codes['SamW'], 'demo@malmo.example.test')['id'] ?? '') === 'SamW', 'SamW och malmo.example.test är Sam W');
foreach (['Niman', 'Isaq', 'Abol', 'Kim', 'Roberto', 'Elin'] as $id) {
    cp_assert((jeab_person_by_code_prefix($codes[$id])['id'] ?? '') === $id, $id . ' utan e-post är ' . $id);
}
cp_assert((jeab_person_by_code_prefix($codes['Roberto'])['id'] ?? '') === 'Roberto', 'versaler spelar ingen roll');
cp_assert(jeab_person_by_code_prefix($codes['none']) === null, 'okänt prefix: ingen person');
cp_assert(jeab_person_from_login($codes['Elin'], 'agenter@flowstack.example.test') === null, 'delad adress blir inte personen i kodens förnamn');
cp_assert(jeab_person_from_login($codes['Elin'], 'demo@malmo.example.test') === null, 'e-post och förnamn som pekar åt olika håll blir ingen person');

foreach ($people as $id => $row) {
    cp_login($codes[$id]);
    $me = jeab_auth_user_person();
    cp_assert(is_array($me) && ($me['id'] ?? '') === $id && ($me['company'] ?? '') === $row['company'], $id . ' kommer från inloggningen');
    cp_assert((jeab_who_me_person()['id'] ?? '') === $id, $id . ' är samma person i listan');
    $_GET = ['co' => $row['company'], 'p' => 'reminders'];
    cp_assert(jeab_reminder_who_filter() === $row['name'], $id . ': Mina i ' . $row['company'] . ' är ' . $row['name']);
    cp_assert(jeab_filter_login_preset($row['company'], true)['who'] === $row['name'], $id . ': Ordrar Mina i egen stad');
    $other = $row['company'] === 'jarfalla' ? 'malmo' : 'jarfalla';
    $_GET = ['co' => $other, 'p' => 'reminders'];
    unset($_SESSION['jeab_reminder_who']);
    cp_assert(jeab_reminder_who_filter() === null, $id . ': andra staden visar Alla');
    cp_assert(jeab_filter_login_preset($other, true)['who'] === null, $id . ': Ordrar i andra staden är Alla');
    cp_assert(str_contains(jeab_who_scope_note_html($other), 'Mina visar ' . $row['name'] . '.'), $id . ': notisen nämner personen även i andra staden');
    cp_assert((jeab_who_me_person()['id'] ?? '') === $id, $id . ': staden byter inte person');
    cp_assert(str_contains(jeab_who_scope_note_html($row['company']), 'Mina visar ' . $row['name'] . '.'), $id . ': notisen nämner personen');
}

cp_login($codes['shared']);
cp_assert(jeab_auth_user_person() === null && jeab_who_me_person() === null && jeab_is_admin(), 'delad kod: Alla och admin');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
cp_assert(jeab_reminder_who_filter() === null && jeab_who_scope_note_html('jarfalla') === '', 'delad kod frågar inte och visar Alla');
$_GET = ['p' => 'reminders', 'co' => 'malmo', 'who' => 'Sam W', 'ws' => (string) time()];
cp_assert(jeab_reminder_who_filter() === 'Sam W', 'delad kod får fortfarande välja Sam W');

$amb = jeab_auth_match($codes['ambiguous']);
cp_assert(is_array($amb), 'tvetydig kod matchar');
$ambFp = jeab_auth_hash_fingerprint((string) $amb['hash']);
jeab_code_people_save($ambFp, 'SamL', 'code');
jeab_code_people_remember($codes['ambiguous'], (string) $amb['hash'], null);
$ambRow = jeab_code_people_read()['fp:' . $ambFp] ?? null;
cp_assert(is_array($ambRow) && ($ambRow['person'] ?? 'x') === '' && ($ambRow['via'] ?? '') === 'ambiguous', 'tvetydig Sam-kod släpper personen');
cp_assert(($ambRow['head'] ?? '') === jeab_code_head($codes['ambiguous']), 'huvudet är de nio första tecknen');
cp_assert(!str_contains(json_encode($ambRow, JSON_UNESCAPED_UNICODE), substr($codes['ambiguous'], 9)), 'resten av koden sparas inte');
$_SESSION = [];
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
$_COOKIE[JEAB_AUTH_COOKIE] = jeab_auth_issue_value(null, (string) $amb['hash']);
cp_assert(jeab_auth_user_person() === null && jeab_who_me_source() === 'none', 'Sam utan e-post: ingen person');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
cp_assert(jeab_reminder_who_filter() === null, 'Sam utan e-post: Alla');

cp_login($codes['none']);
cp_assert(jeab_auth_user_person() === null && jeab_reminder_who_filter() === null, 'okänd kod: Alla');

$jlBare = jeab_auth_match($codes['jl_bare']);
cp_assert(is_array($jlBare), 'SamL utan e-post matchar');
$jlFp = jeab_auth_hash_fingerprint((string) $jlBare['hash']);
jeab_code_people_save($jlFp, 'SamW', 'code');
cp_login($codes['jl_bare']);
cp_assert((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_who_me_name() === 'Sam L', 'SamL utan e-post är Sam L i Mina');
cp_assert((jeab_code_people_read()['fp:' . $jlFp]['via'] ?? '') === 'prefix' && (jeab_code_people_read()['fp:' . $jlFp]['person'] ?? '') === 'SamL', 'prefixet skriver över den gamla gissningen');
cp_assert(jeab_is_admin(), 'Sam L är admin med sin personliga kod');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
unset($_SESSION['jeab_reminder_who']);
cp_assert(jeab_reminder_who_filter() === 'Sam L' && jeab_filter_login_preset('jarfalla', true)['who'] === 'Sam L', 'Påminnelser och Ordrar visar Sam L');
$chips = jeab_ordrar_mina_chips_html('jarfalla', jeab_reminder_who_filter(), null);
cp_assert(str_contains($chips, 'who=Sam+L') && !str_contains($chips, 'Sam+W') && preg_match('#data-scope="mina"[^>]*>Mina</a>#', $chips) === 1, 'Ordrar-pillen Mina pekar på Sam L utan namn i pillen');
$_GET = ['p' => 'reminders', 'co' => 'malmo'];
unset($_SESSION['jeab_reminder_who']);
cp_assert(jeab_reminder_who_filter() === null && jeab_who_me_name() === 'Sam L', 'Sam L i Malmö är fortfarande Sam L, listan är Alla');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
$_SESSION['jeab_reminder_who'] = ['jarfalla' => ['who' => 'Sam W', 'at' => time()]];
cp_assert(jeab_reminder_who_filter() === 'Sam L', 'sparat Sam W-val i Järfälla blir Sam L');
$_GET = ['p' => 'reminders', 'co' => 'malmo', 'who' => 'Sam W', 'ws' => (string) time()];
cp_assert(jeab_reminder_who_filter() === null, 'en färsk Sam W-adress i Malmö blir Alla för Sam L');
$_GET = ['p' => 'ordrar', 'co' => 'malmo', 'who' => 'Sam W', 'ws' => (string) time()];
cp_assert((jeab_filter_from_request('malmo', true)['who'] ?? '') !== 'Sam W', 'Ordrar ignorerar Sam W för Sam L');

$jwBare = jeab_auth_match($codes['jw_bare']);
cp_assert(is_array($jwBare), 'SamW utan e-post matchar');
$jwFp = jeab_auth_hash_fingerprint((string) $jwBare['hash']);
jeab_code_people_save($jwFp, 'SamL', 'code');
cp_login($codes['jw_bare']);
cp_assert((jeab_auth_user_person()['id'] ?? '') === 'SamW' && jeab_who_me_name() === 'Sam W' && !jeab_is_admin(), 'SamW utan e-post är Sam W och inte admin');
cp_assert((jeab_code_people_read()['fp:' . $jwFp]['person'] ?? '') === 'SamW', 'gammal Sam L-gissning viker för SamW');
$_GET = ['p' => 'reminders', 'co' => 'malmo'];
unset($_SESSION['jeab_reminder_who']);
cp_assert(jeab_reminder_who_filter() === 'Sam W' && jeab_filter_login_preset('malmo', true)['who'] === 'Sam W', 'Påminnelser och Ordrar visar Sam W');
$chips = jeab_ordrar_mina_chips_html('malmo', 'Sam W', null);
cp_assert(str_contains($chips, 'who=Sam+W') && !str_contains($chips, 'Sam+L') && preg_match('#data-scope="mina"[^>]*>Mina</a>#', $chips) === 1, 'Ordrar-pillen Mina pekar på Sam W utan namn i pillen');

cp_login($codes['jl_krock']);
cp_assert((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_who_me_name() === 'Sam L' && jeab_is_admin(), 'SamL med malmö-e-post är Sam L och admin');
$krock = jeab_code_people_read()['fp:' . jeab_auth_hash_fingerprint((string) jeab_auth_match($codes['jl_krock'])['hash'])] ?? [];
cp_assert(($krock['via'] ?? '') === 'prefix' && ($krock['person'] ?? '') === 'SamL' && ($krock['head'] ?? '') === jeab_code_head($codes['jl_krock']), 'kodhuvudet sparas som Sam L');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
unset($_SESSION['jeab_reminder_who']);
cp_assert(jeab_reminder_who_filter() === 'Sam L' && jeab_filter_login_preset('jarfalla', true)['who'] === 'Sam L', 'Mina i Järfälla är Sam L');
$_GET = ['p' => 'reminders', 'co' => 'malmo'];
unset($_SESSION['jeab_reminder_who']);
cp_assert(jeab_reminder_who_filter() === null, 'Malmö utan val är Alla, inte Sam W');
$chips = jeab_ordrar_mina_chips_html('jarfalla', 'Sam L', null);
cp_assert(str_contains($chips, 'who=Sam+L') && !str_contains($chips, 'Sam+W'), 'Ordrar-pillen är Sam L');
$labels = array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
cp_assert(jeab_login_diag_allowed() && in_array('Användare', $labels, true), 'Sam L ser Inloggningar');

cp_login($codes['jw_krock']);
cp_assert((jeab_auth_user_person()['id'] ?? '') === 'SamW' && jeab_who_me_name() === 'Sam W' && !jeab_is_admin(), 'SamW med järfälla-e-post är Sam W och inte admin');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
unset($_SESSION['jeab_reminder_who']);
cp_assert(jeab_reminder_who_filter() === null, 'Sam W i Järfälla visar inte Sam L');
cp_assert(!jeab_login_diag_allowed(), 'Sam W ser inte Inloggningar');

$pages = [
    dirname(__DIR__) . '/src/pages/reminders.php',
    dirname(__DIR__) . '/src/pages/ordrar.php',
    dirname(__DIR__) . '/src/pages/bookings.php',
    dirname(__DIR__) . '/src/who_identity.php',
    dirname(__DIR__) . '/src/pages/inloggningar.php',
];
foreach ($pages as $page) {
    $src = (string) file_get_contents($page);
    cp_assert(!str_contains($src, 'Välj vem du är') && !str_contains($src, 'jeab_who_picker_html') && !str_contains($src, 'who.pick'), basename($page) . ' frågar inte');
}

$store = (string) file_get_contents(jeab_code_people_path());
foreach ($codes as $c) {
    cp_assert(!str_contains(strtolower($store), strtolower($c)), 'koden sparas inte');
}
cp_assert(!str_contains($store, '$2y$'), 'ingen hash sparas');
$pass = (string) file_get_contents($tmp . '/passwords.json');
foreach ($codes as $c) {
    cp_assert(!str_contains($pass, $c), 'lösenordsfilen innehåller inte koden');
}
echo "ALLA OK\n";
