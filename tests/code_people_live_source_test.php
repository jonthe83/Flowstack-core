<?php

declare(strict_types=1);

/**
 * Speglar live: code_people ligger bara i json_docs (biz_code_people), ingen code-people.json,
 * och biz_doc_store.php är inte laddad (kärnsidor och hook.php). read(false) och
 * auth.code_people_shape ska ändå se raderna. Koder och fingeravtryck här är fejk.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-cp-live-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
// Live MySQL räknas som sql-läge utan flagga; samma väg här.
$GLOBALS['_JEAB_BIZ_DOC_LEGACY_SQL'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
// Påhittade koder. Deras rader sparades när Sam L hette så och Roberto var ensam.
$codes = [
    'SamL' => 'SamL' . 'fake' . bin2hex(random_bytes(4)),
    'Roberto' => 'Roberto-' . 'fake' . bin2hex(random_bytes(4)),
    'Kim' => 'Kim ' . 'fake' . bin2hex(random_bytes(4)),
];
$hashes = [];
foreach ($codes as $cid => $code) {
    $hashes[$cid] = password_hash($code, PASSWORD_DEFAULT);
}
file_put_contents($tmp . '/passwords.json', json_encode(['secret' => bin2hex(random_bytes(16)), 'hashes' => array_map(static fn ($h) => ['hash' => $h], array_values($hashes))]));
// Dagens people-config: Sam L heter Jonny L, och Roberto B delar prefix med Roberto.
$people = [
    ['id' => 'Roberto', 'email' => 'saljare3@jarfalla.example.test', 'name' => 'Roberto', 'company' => 'jarfalla', 'job' => 'batteri'],
    ['id' => 'RobertoB', 'email' => 'robertob@jarfalla.example.test', 'name' => 'Roberto B', 'company' => 'jarfalla', 'job' => null],
    ['id' => 'SamL', 'email' => 'jonny@jarfalla.example.test', 'name' => 'Jonny L', 'company' => 'jarfalla', 'job' => null, 'username' => 'jonny'],
    ['id' => 'SamW', 'email' => 'demo@malmo.example.test', 'name' => 'Sam W', 'company' => 'malmo', 'job' => null],
    ['id' => 'Elin', 'email' => 'saljare4@jarfalla.example.test', 'name' => 'Elin', 'company' => 'jarfalla', 'job' => 'laddbox'],
    ['id' => 'Kim', 'email' => 'saljare.syd@malmo.example.test', 'name' => 'Kim', 'company' => 'malmo', 'job' => 'laddbox'],
];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'people' => $people,
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
function cl(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

cl(!function_exists('jeab_biz_doc_store_pdo'), 'förutsättning: biz_doc_store.php är inte laddad, som på live');
$pdo = jeab_store()->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS json_docs (doc_key TEXT PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL)');
$rows = [
    'fp:00000000000000a1' => ['person' => 'Elin', 'via' => 'prefix', 'at' => '2026-10-31 12:00:00', 'head' => 'Elinxxxxx'],
    'fp:00000000000000a2' => ['person' => 'SamL', 'via' => 'manual', 'at' => '2026-10-31 12:01:00', 'head' => 'SamL'],
];
$st = $pdo->prepare('INSERT INTO json_docs (doc_key, payload, updated_at) VALUES (?, ?, ?)');
$st->execute(['biz_code_people', json_encode($rows), '2026-10-31 12:01:00']);
cl(!is_file(jeab_code_people_path()), 'förutsättning: ingen code-people.json');

$read = jeab_code_people_read(false);
cl(count($read) === 2 && ($read['fp:00000000000000a2']['person'] ?? '') === 'SamL', 'read(false) läser SQL-raden');

@mkdir($tmp . '/secrets', 0700, true);
$token = 'fake-live-token-' . bin2hex(random_bytes(4));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $token]));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$shape = jeab_auth_code_people_shape_dispatch(['action' => 'auth.code_people_shape']);
cl(($shape['ok'] ?? false) === true && ($shape['count'] ?? 0) === 2, 'shape: count 2 från SQL');
$json = (string) json_encode($shape);
cl(!str_contains($json, '00000000000000a') && !str_contains($json, 'Elinxxxxx'), 'shape: inget fingeravtryck eller huvud');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fel';
$deny = jeab_auth_code_people_shape_dispatch([]);
cl(($deny['status'] ?? 0) === 401 && !isset($deny['rows']), 'shape: fel token nekas');

// Sparning från en kärnsida landar i SQL och syns i nästa läsning.
jeab_code_people_save('00000000000000a3', 'Kim', 'prefix', 'Kimxxxxxx');
unset($GLOBALS['_JEAB_SQL_DOC']);
$after = jeab_code_people_read(false);
cl(($after['fp:00000000000000a3']['person'] ?? '') === 'Kim' && !is_file(jeab_code_people_path()), 'sparning går till SQL, inte JSON, och läses tillbaka');

// Inaktuella prefixrader i SQL-läge: namnbyte och ny person med samma prefix.
$fps = [];
foreach ($hashes as $cid => $h) {
    $fps[$cid] = jeab_auth_hash_fingerprint($h);
}
$stale = [
    'fp:' . $fps['SamL'] => ['person' => 'SamL', 'via' => 'prefix', 'at' => '2026-10-30 09:00:00', 'head' => jeab_code_head($codes['SamL'])],
    'fp:' . $fps['Roberto'] => ['person' => 'Roberto', 'via' => 'prefix', 'at' => '2026-10-30 09:00:00', 'head' => jeab_code_head($codes['Roberto'])],
    'fp:' . $fps['Kim'] => ['person' => 'Kim', 'via' => 'prefix', 'at' => '2026-10-30 09:00:00', 'head' => jeab_code_head($codes['Kim'])],
    'fp:00000000000000b1' => ['person' => 'Roberto', 'via' => 'code', 'at' => '2026-10-30 09:00:00', 'head' => jeab_code_head($codes['Roberto'])],
    'fp:00000000000000b2' => ['person' => 'Elin', 'via' => 'manual', 'at' => '2026-10-30 09:00:00', 'head' => 'Xyzxyzxyz'],
];
$pdo->prepare('UPDATE json_docs SET payload = ? WHERE doc_key = ?')->execute([json_encode($stale), 'biz_code_people']);
unset($GLOBALS['_JEAB_SQL_DOC']);
cl(count(jeab_code_people_read(false)) === 5 && !is_file(jeab_code_people_path()), 'inaktuella rader ligger bara i SQL');
cl(jeab_person_by_code_fp($fps['SamL']) === null, 'namnbyte: person_by_code_fp litar inte på prefixraden');
cl(jeab_person_by_code_fp($fps['Roberto']) === null, 'ny person med samma prefix: person_by_code_fp ger ingen');
cl(jeab_person_by_code_fp('00000000000000b1') === null, 'via=code med tvetydigt huvud: ingen');
cl((jeab_person_by_code_fp($fps['Kim'])['id'] ?? '') === 'Kim', 'oförändrad prefixrad gäller');
cl((jeab_person_by_code_fp('00000000000000b2')['id'] ?? '') === 'Elin', 'manuellt val gäller');
cl(!jeab_auth_code_people_conflict($fps['SamL'], jeab_auth_person_by_id('SamW')), 'inaktuell prefixrad är ingen konflikt');
cl(jeab_auth_code_people_conflict($fps['Kim'], jeab_auth_person_by_id('Elin')), 'giltig prefixrad mot annan e-postperson är konflikt');

$login = static function (string $cid) use ($hashes): array {
    $_SESSION = [];
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value(null, $hashes[$cid]);
    unset($GLOBALS['_JEAB_LOGIN_IDENTITY'], $GLOBALS['_JEAB_WHO_REASON']);

    return jeab_login_identity();
};
jeab_auth_data(true);
$r = $login('SamL');
cl(empty($r['certain']) && ($r['person'] ?? null) === null, 'inloggning: namnbyte loggar inte in någon (inte Sam W)');
$r = $login('Roberto');
cl(empty($r['certain']) && ($r['person'] ?? null) === null, 'inloggning: Roberto/Roberto B tvetydigt, väljaren');
$r = $login('Kim');
cl(!empty($r['certain']) && ($r['person']['id'] ?? '') === 'Kim', 'inloggning: Kim väljs');

exit($fail > 0 ? 1 : 0);
