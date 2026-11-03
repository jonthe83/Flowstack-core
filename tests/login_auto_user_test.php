<?php

declare(strict_types=1);

/**
 * Hashar med bcrypt cost 4: samma password_verify, men testet håller sig under CI:s 15 s.
 * Person från inloggningen: längsta kodprefixet (saml, samw) först.
 * Ett ensamt Sam… är tvetydigt och blir ingen person. E-postdomänen ger stad
 * och väljer inte mellan Sam L och Sam W. Koden sparas aldrig, bara nio tecken.
 */

$tmp = sys_get_temp_dir() . '/jeab-login-auto-user-' . getmypid();
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
        'hash' => password_hash($codes[$id], PASSWORD_BCRYPT, ['cost' => 4]),
    ];
}
$codes['shared'] = 'deladTEST-' . bin2hex(random_bytes(4));
$codes['ambiguous'] = 'SamTEST-' . bin2hex(random_bytes(4));
$codes['none'] = 'xyzTEST-' . bin2hex(random_bytes(4));
$codes['jl_bare'] = 'SamLbare-' . bin2hex(random_bytes(3));
$codes['jw_bare'] = 'samwbare-' . bin2hex(random_bytes(3));
$codes['jl_krock'] = 'SamLkrock-' . bin2hex(random_bytes(3));
$codes['jw_krock'] = 'SamWkrock-' . bin2hex(random_bytes(3));
$entries[] = ['email' => 'agenter@flowstack.example.test', 'hash' => password_hash($codes['shared'], PASSWORD_BCRYPT, ['cost' => 4])];
$entries[] = ['hash' => password_hash($codes['ambiguous'], PASSWORD_BCRYPT, ['cost' => 4])];
$entries[] = ['hash' => password_hash($codes['none'], PASSWORD_BCRYPT, ['cost' => 4])];
$entries[] = ['hash' => password_hash($codes['jl_bare'], PASSWORD_BCRYPT, ['cost' => 4])];
$entries[] = ['hash' => password_hash($codes['jw_bare'], PASSWORD_BCRYPT, ['cost' => 4])];
$entries[] = ['email' => 'demo@malmo.example.test', 'hash' => password_hash($codes['jl_krock'], PASSWORD_BCRYPT, ['cost' => 4])];
$entries[] = ['email' => 'demo@example.test', 'hash' => password_hash($codes['jw_krock'], PASSWORD_BCRYPT, ['cost' => 4])];
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


/** Ny session (som efter sessions-GC), samma 90-dagarskaka. */
function la_fresh(): array
{
    $_SESSION = [];
    $_GET = [];
    unset($GLOBALS['_JEAB_LOGIN_IDENTITY'], $GLOBALS['_JEAB_WHO_REASON']);

    return jeab_login_identity();
}

function la_login(string $code): array
{
    $m = jeab_auth_match($code);
    cp_assert(is_array($m), 'påhittad kod matchar (längd ' . strlen($code) . ')');
    $email = (string) ($m['email'] ?? '');
    $_SESSION = [];
    unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
    jeab_code_people_remember($code, (string) $m['hash'], $email !== '' ? $email : null);
    $_COOKIE[JEAB_AUTH_COOKIE] = jeab_auth_issue_value($email !== '' ? $email : null, (string) $m['hash']);

    return $m;
}

// Koder med skiljetecken i prefixet. Alla påhittade.
$variants = [
    'Roberto' => 'Roberto ', 'SamL' => 'Sam L', 'Elin' => 'elin.', 'Niman' => 'Niman-',
    'Isaq' => 'ISAQ_', 'SamW' => 'Sam-W', 'Abol' => 'Abol ', 'Kim' => 'kim.',
];
$vcodes = [];
$ventries = [];
foreach ($variants as $id => $pre) {
    $vcodes[$id] = $pre . 'fake' . bin2hex(random_bytes(4));
    $ventries[] = ['hash' => password_hash($vcodes[$id], PASSWORD_BCRYPT, ['cost' => 4])];
}
$vcodes['amb'] = 'Sam-' . 'fake' . bin2hex(random_bytes(4));
$ventries[] = ['hash' => password_hash($vcodes['amb'], PASSWORD_BCRYPT, ['cost' => 4])];
$vcodes['none'] = 'Qwerty ' . 'fake' . bin2hex(random_bytes(4));
$ventries[] = ['hash' => password_hash($vcodes['none'], PASSWORD_BCRYPT, ['cost' => 4])];
$pw = json_decode((string) file_get_contents($tmp . '/passwords.json'), true);
$pw['hashes'] = array_merge($pw['hashes'], $ventries);
file_put_contents($tmp . '/passwords.json', json_encode($pw));
jeab_auth_data(true);

foreach ($people as $id => $row) {
    la_login($codes[$id]);
    $r = la_fresh();
    cp_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === $id, $id . ': ny session väljer personen utan väljare');
}
foreach ($variants as $id => $pre) {
    cp_assert((jeab_person_by_code_prefix($vcodes[$id])['id'] ?? '') === $id, $id . ': prefix med skiljetecken träffar');
    $m = la_login($vcodes[$id]);
    $r = la_fresh();
    cp_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === $id, $id . ': ny session efter kod med skiljetecken utan väljare');
    $r = la_fresh();
    cp_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === $id, $id . ': även andra gången');
    $row = jeab_code_people_read()['fp:' . jeab_auth_hash_fingerprint((string) $m['hash'])] ?? [];
    cp_assert(strlen((string) ($row['head'] ?? '')) <= 9, $id . ': högst nio tecken sparas');
    cp_assert(!str_contains((string) json_encode(jeab_code_people_read()), substr($vcodes[$id], 9)), $id . ': resten av koden sparas inte');
}
la_login($vcodes['amb']);
$r = la_fresh();
cp_assert(empty($r['certain']) && $r['person'] === null && str_starts_with((string) $r['reason'], 'ambiguous'), 'Sam- utan initial: väljaren som nödfall');
la_login($vcodes['none']);
$r = la_fresh();
cp_assert(empty($r['certain']) && $r['person'] === null, 'okänt prefix: ingen person');

// Manuellt val mot en sparad prefixrad.
$seed = jeab_code_people_read();
$seed['fp:00000000000000b1'] = ['person' => 'SamL', 'via' => 'prefix', 'head' => 'Sam ', 'at' => '2026-01-01 00:00:00'];
$seed['fp:00000000000000b2'] = ['person' => 'Kim', 'via' => 'prefix', 'head' => 'Ab-ol x', 'at' => '2026-01-01 00:00:00'];
$seed['fp:00000000000000b3'] = ['person' => 'Kim', 'via' => 'prefix', 'head' => 'Kimfake', 'at' => '2026-01-01 00:00:00'];
$seed['fp:00000000000000b4'] = ['person' => 'Roberto', 'via' => 'prefix', 'head' => 'Xyzzy', 'at' => '2026-01-01 00:00:00'];
file_put_contents(jeab_code_people_path(), json_encode($seed));
jeab_code_people_save('00000000000000b1', 'SamL', 'manual', null);
jeab_code_people_save('00000000000000b2', 'Abol', 'manual', null);
jeab_code_people_save('00000000000000b3', 'Elin', 'manual', null);
jeab_code_people_save('00000000000000b4', 'Roberto', 'manual', null);
$after = jeab_code_people_read();
cp_assert(($after['fp:00000000000000b1']['via'] ?? '') === 'manual' && ($after['fp:00000000000000b1']['person'] ?? '') === 'SamL', 'manuellt: tvetydig prefixrad (Sam) skrivs över');
cp_assert(($after['fp:00000000000000b2']['via'] ?? '') === 'manual' && ($after['fp:00000000000000b2']['person'] ?? '') === 'Abol' && ($after['fp:00000000000000b2']['head'] ?? '') === 'Abolx', 'manuellt: inaktuell prefixrad skrivs över, huvudet i nytt format');
cp_assert(($after['fp:00000000000000b3']['via'] ?? '') === 'prefix' && ($after['fp:00000000000000b3']['person'] ?? '') === 'Kim', 'manuellt: giltigt entydigt prefix vinner');
cp_assert(($after['fp:00000000000000b4']['via'] ?? '') === 'manual', 'manuellt: prefixrad vars huvud inte träffar någon skrivs över');
// Kaka med sparat prefix «Sam » (gammalt format): manuellt val gäller i nästa session.
$mOld = la_login($vcodes['SamL']);
$fpOld = jeab_auth_hash_fingerprint((string) $mOld['hash']);
$seed = jeab_code_people_read();
$seed['fp:' . $fpOld] = ['person' => 'SamL', 'via' => 'prefix', 'head' => 'Sam ', 'at' => '2026-01-01 00:00:00'];
file_put_contents(jeab_code_people_path(), json_encode($seed));
$r = la_fresh();
cp_assert(empty($r['certain']), 'gammalt tvetydigt prefixhuvud: väljaren som nödfall');
jeab_code_people_save($fpOld, 'SamL', 'manual', null);
$r = la_fresh();
cp_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === 'SamL', 'efter manuellt val: ny session utan väljare');
$r = la_fresh();
cp_assert(!empty($r['certain']) && ($r['person']['id'] ?? '') === 'SamL', 'efter manuellt val: även nästa session');

// auth.code_people_shape: bara form, aldrig huvud, hash, fingeravtryck eller kod.
@mkdir($tmp . '/secrets', 0700, true);
$hookToken = 'fake-shape-token-' . bin2hex(random_bytes(4));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $hookToken]));
jeab_code_people_save('00000000000000aa', 'Elin', 'manual', null);
$legacy = jeab_code_people_read();
$legacy['fp:00000000000000aa']['head'] = 'El-in';
file_put_contents(jeab_code_people_path(), json_encode($legacy));
$storeBefore = (string) json_encode(jeab_code_people_read());
$_SERVER['REQUEST_METHOD'] = 'POST';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN']);
$deny = jeab_auth_code_people_shape_dispatch(['action' => 'auth.code_people_shape']);
cp_assert(($deny['ok'] ?? true) === false && ($deny['status'] ?? 0) === 401 && !isset($deny['rows']), 'shape: utan token nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fel-' . bin2hex(random_bytes(4));
$deny = jeab_auth_code_people_shape_dispatch([]);
cp_assert(($deny['status'] ?? 0) === 401 && !isset($deny['rows']), 'shape: fel token nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $hookToken;
$shape = jeab_auth_code_people_shape_dispatch(['action' => 'auth.code_people_shape']);
$json = (string) json_encode($shape, JSON_UNESCAPED_UNICODE);
cp_assert(($shape['ok'] ?? false) === true && count($shape['rows'] ?? []) === count(jeab_code_people_read()) && count($shape['rows']) > 0, 'shape: en rad per code_people-rad');
$keysOk = true;
foreach ($shape['rows'] as $r) {
    $keysOk = $keysOk && array_keys($r) === ['person', 'via', 'head_len', 'has_sep'] && in_array($r['has_sep'], ['ja', 'nej'], true);
}
cp_assert($keysOk, 'shape: bara person, via, head_len och has_sep');
$leak = false;
foreach (jeab_code_people_read() as $fpKey => $r) {
    $h = (string) ($r['head'] ?? '');
    $leak = $leak || str_contains($json, substr((string) $fpKey, 3)) || (strlen(trim($h)) >= 3 && !isset($people[$h]) && str_contains($json, json_encode($h) ?: '\0'));
}
foreach ((jeab_auth_data(true)['hashes'] ?? []) as $e) {
    $leak = $leak || (is_array($e) && ($e['hash'] ?? '') !== '' && str_contains($json, (string) $e['hash']));
}
foreach (array_merge($codes, $vcodes) as $k => $c) {
    // Ett huvud som är exakt ett person-id (SamL) är personfältet, inte en läcka.
    $h9 = substr($c, 0, 9);
    $leak = $leak || (!isset($people[$h9]) && str_contains($json, $h9)) || str_contains($json, $c);
}
cp_assert(!$leak && !str_contains($json, '"head"') && !str_contains($json, '$2y$') && !str_contains($json, 'fp:'), 'shape: inget huvud, ingen hash, inget fingeravtryck, ingen kod');
// «Sam L…» sparar nu huvudet SamL (9 tecken, utan blanksteg).
$jl = array_values(array_filter($shape['rows'], static fn (array $r): bool => $r['person'] === 'SamL' && $r['via'] === 'prefix'));
cp_assert($jl !== [] && array_unique(array_column($jl, 'head_len')) === [9] && !in_array('ja', array_column($jl, 'has_sep'), true), 'shape: SamL-rader har head_len 9 utan skiljetecken');
cp_assert(in_array(['person' => 'Elin', 'via' => 'manual', 'head_len' => 5, 'has_sep' => 'ja'], $shape['rows'], true), 'shape: gammalt huvud med bindestreck syns som has_sep=ja');
cp_assert((string) json_encode(jeab_code_people_read()) === $storeBefore, 'shape: skriver inget');
echo "ALL OK\n";
