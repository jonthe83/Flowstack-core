<?php

declare(strict_types=1);

/**
 * Sam W utan sparat kodhuvud är ingen person och inte admin,
 * men Malmö-adressen ska ändå ge Malmö först och båda kontoren i väljaren.
 * Ett huvud som sparats i JSON ska synas även när en äldre SQL-kopia finns och läget är json.
 * Koderna här är fejk.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-samw-office-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');

$codeW = 'SamWXXXXXXXX';
$codeLower = 'samwYYYYYYYY';
$codeTail = 'SamWZZZZZZZZ';
$hashOld = password_hash('gammal-kaka-utan-huvud', PASSWORD_DEFAULT);
$hashL = password_hash('jarfalla-kaka-utan-huvud', PASSWORD_DEFAULT);
$hashUnknown = password_hash('okand-doman-utan-huvud', PASSWORD_DEFAULT);
$hashW = password_hash($codeW, PASSWORD_DEFAULT);
$hashLower = password_hash($codeLower, PASSWORD_DEFAULT);
$hashTail = password_hash($codeTail, PASSWORD_DEFAULT);

file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@malmo.example.test', 'hash' => $hashOld],
        ['email' => 'demo@example.test', 'hash' => $hashL],
        ['email' => 'okand@example.test', 'hash' => $hashUnknown],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashLower],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashTail],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
require dirname(__DIR__) . '/src/biz_doc_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function jw(bool $ok, string $msg): void
{
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$msg}\n");
    exit(1);
}

function jw_cookie(?string $email, string $hash): void
{
    $_COOKIE = [];
    $_GET = [];
    $_POST = [];
    $_SESSION = [];
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_WHO_REASON']);
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

function jw_chips(string $page): string
{
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
    return jeab_chrome_company_html($page);
}

$pdo = jeab_biz_doc_store_pdo();
jw($pdo instanceof PDO, 'sqlite-pdo finns');
jeab_biz_doc_store_ensure($pdo);
jeab_sql_doc_put($pdo, jeab_biz_doc_sql_key('code_people'), [
    'fp:aaaaaaaaaaaaaaaa' => ['person' => 'Elin', 'via' => 'prefix', 'at' => '2020-01-01 00:00:00', 'head' => 'Elin'],
]);
jw(jeab_biz_doc_storage_mode('code_people') === 'json', 'code_people är i JSON-läge');

jw_cookie('demo@malmo.example.test', $hashOld);
jw(jeab_auth_user_person() === null && !jeab_is_admin(), 'gammal Malmö-kaka är ingen person och inte admin');
jw(jeab_actor_companies() === ['malmo', 'jarfalla'], 'gammal Malmö-kaka har Malmö först och båda kontoren');
jw(jeab_selected_company() === 'malmo' && jeab_inkorg_box() === 'malmo', 'utan val är Malmö förvalet i kalender och inkorg');
$chips = jw_chips('inkorg');
jw(str_contains($chips, 'Malmö') && str_contains($chips, 'Järfälla'), 'inkorgen visar båda bolagen');
jw(str_contains($chips, 'aria-current="true"') && preg_match('/chip is-on[^>]*>Malmö</', $chips) === 1, 'Malmö är valt i väljaren');
$_SESSION['jeab_company'] = 'jarfalla';
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
jw(jeab_selected_company() === 'jarfalla' && jeab_inkorg_box() === 'jarfalla', 'ett tillåtet Järfälla-val ligger kvar');
jw(str_contains(jw_chips('reminders'), 'Malmö') && str_contains(jw_chips('reminders'), 'Järfälla'), 'väljaren finns kvar när Järfälla är valt');

jw_cookie('demo@example.test', $hashL);
jw(jeab_auth_user_person() === null && !jeab_is_admin(), 'Järfälla-kaka utan huvud är inte Sam L');
jw(jeab_actor_companies() === ['jarfalla', 'malmo'] && jeab_selected_company() === 'jarfalla', 'Järfälla-adressen har Järfälla först');
jw(str_contains(jw_chips('inkorg'), 'Malmö') && str_contains(jw_chips('inkorg'), 'Järfälla'), 'Järfälla-adressen har också väljaren');

jw_cookie('okand@example.test', $hashUnknown);
jw(jeab_auth_user_person() === null && jeab_actor_companies() === [], 'okänd domän får inga kontor');
jw(!str_contains(jw_chips('inkorg'), 'Malmö') && !str_contains(jw_chips('inkorg'), 'Järfälla'), 'okänd domän får ingen väljare');

$lower = jeab_auth_attempt('samwXXXXXXXX', '203.0.113.10');
jw(empty($lower['ok']), 'annan versal än den sparade koden loggar inte in');
$extra = jeab_auth_attempt($codeW . 'Z', '203.0.113.11');
jw(empty($extra['ok']), 'extra tecken loggar inte in');
$short = jeab_auth_attempt(substr($codeW, 0, -1), '203.0.113.12');
jw(empty($short['ok']), 'avhuggen kod loggar inte in');

$exact = jeab_auth_attempt($codeW, '203.0.113.13');
jw(!empty($exact['ok']) && ($exact['email'] ?? '') === 'demo@malmo.example.test', 'SamW-koden loggar in');
$person = jeab_code_people_remember($codeW, (string) $exact['hash'], (string) $exact['email']);
jw(is_array($person) && ($person['id'] ?? '') === 'SamW', 'inloggningen sparar Sam W');
jw_cookie('demo@malmo.example.test', (string) $exact['hash']);
jw((jeab_auth_user_person()['id'] ?? '') === 'SamW' && !jeab_is_admin(), 'nästa sida är Sam W och inte admin');
jw(jeab_actor_companies() === ['malmo', 'jarfalla'] && jeab_selected_company() === 'malmo', 'Sam W ser Malmö först');
jw((jeab_who_me_name() ?? '') === 'Sam W', 'Påminnelser kan visa Sam W');
$fp = jeab_auth_hash_fingerprint((string) $exact['hash']);
$noddl = jeab_code_people_read_noddl();
jw(($noddl['fp:' . $fp]['person'] ?? '') === 'SamW' && ($noddl['fp:' . $fp]['via'] ?? '') === 'prefix', 'JSON-läget läser huvudet trots gammal SQL-kopia');
$sql = jeab_sql_doc_get($pdo, jeab_biz_doc_sql_key('code_people'));
jw(is_array($sql) && !isset($sql['fp:' . $fp]) && isset($sql['fp:aaaaaaaaaaaaaaaa']), 'SQL-kopian skrevs inte över');

$lowerOk = jeab_auth_attempt($codeLower, '203.0.113.14');
jw(!empty($lowerOk['ok']), 'en kod som är sparad med gemener loggar in');
$lowerPerson = jeab_code_people_remember($codeLower, (string) $lowerOk['hash'], (string) $lowerOk['email']);
jw(is_array($lowerPerson) && ($lowerPerson['id'] ?? '') === 'SamW', 'gemener i SamW träffar Sam W');

$tailOk = jeab_auth_attempt($codeTail, '203.0.113.15');
jw(!empty($tailOk['ok']), 'kod med svans efter SamW loggar in');
$tailPerson = jeab_code_people_remember($codeTail, (string) $tailOk['hash'], (string) $tailOk['email']);
jw(is_array($tailPerson) && ($tailPerson['id'] ?? '') === 'SamW' && !jeab_is_admin(), 'svansen ändrar inte personen och ger inte admin');

echo "samw office: ok\n";
