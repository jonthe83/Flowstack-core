<?php

declare(strict_types=1);

/** Begränsad roll solkampanj. Koden skapas i testet och får inte synas i svar, filer eller utdata. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-restricted-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/privat', 0775, true);
mkdir($tmp . '/public', 0775, true);
$passFile = $tmp . '/privat/passwords.json';
$usersFile = $tmp . '/privat/restricted-users.json';
$publicPass = $tmp . '/public/passwords.json';
$staffCode = 'sam' . bin2hex(random_bytes(8));
file_put_contents($passFile, json_encode([
    'secret' => 'test-secret',
    'hashes' => [[
        'hash' => password_hash($staffCode, PASSWORD_DEFAULT),
        'email' => 'demo@example.test',
    ]],
], JSON_PRETTY_PRINT));
file_put_contents($publicPass, json_encode(['secret' => 'test-secret', 'hashes' => []], JSON_PRETTY_PRINT));
chmod($passFile, 0600);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_APP_ROOT'] = $tmp . '/public';
$GLOBALS['_JEAB_SOLKAMPANJ_DIR'] = $tmp . '/privat/solkampanj';
$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = $usersFile;
putenv('JEAB_PASSWORDS_FILE=' . $passFile);
putenv('JEAB_DISABLE_SQLITE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'restricted-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function ru(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ru_hash_count(string $path): int
{
    $raw = json_decode((string) file_get_contents($path), true);

    return is_array($raw) && is_array($raw['hashes'] ?? null) ? count($raw['hashes']) : -1;
}

function ru_absent(string $haystack, string $secret, string $message): void
{
    ru($secret !== '' && !str_contains($haystack, $secret), $message);
}

$name = 'Kampanj ' . bin2hex(random_bytes(3));
$username = jeab_restricted_username($name);
$code = $username . bin2hex(random_bytes(8));
$short = $username . 'abc';
$other = 'zz' . bin2hex(random_bytes(8));

ru(jeab_restricted_username('Mårten Pilot') === 'martenpilot', 'användarnamn tas från namnet');
ru(strlen($username) >= 3 && str_starts_with($code, $username) && !str_contains($code, ' '), 'testkoden byggs i körningen');

$srcBlob = (string) file_get_contents(dirname(__DIR__) . '/src/restricted_users.php')
    . (string) file_get_contents(dirname(__DIR__) . '/src/people.php')
    . (string) file_get_contents(dirname(__DIR__) . '/hook.php')
    . (string) file_get_contents(dirname(__DIR__) . '/solkampanj.php');
ru(preg_match('/password_hash\(\s*[\'"]/', $srcBlob) !== 1, 'ingen kod är inbakad i källan');
ru_absent($srcBlob, $code, 'koden finns inte i källan');
ru_absent($srcBlob, $staffCode, 'personalens kod finns inte i källan');

$before = ru_hash_count($passFile);
$badRole = jeab_restricted_add(['name' => $name, 'role' => 'admin', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
ru(empty($badRole['ok']) && ru_hash_count($passFile) === $before, 'bara rollen solkampanj kan skapas');
ru_absent(json_encode($badRole, JSON_UNESCAPED_UNICODE), $code, 'fel roll ekar inte koden');

$clash = jeab_restricted_add([
    'name' => 'Sam',
    'role' => 'solkampanj',
    'code' => 'sam' . bin2hex(random_bytes(8)),
    'dry' => 0,
    'confirm' => 'SAM',
]);
ru(empty($clash['ok']) && str_contains((string) ($clash['error'] ?? ''), 'krockar') && ru_hash_count($passFile) === $before, 'användarnamn som krockar med personal skapas inte');

$used = jeab_restricted_add([
    'name' => 'Sam',
    'role' => 'solkampanj',
    'code' => $staffCode,
    'dry' => 0,
    'confirm' => 'SAM',
]);
ru(empty($used['ok']) && ($used['error'] ?? '') === 'Koden används redan.' && ru_hash_count($passFile) === $before, 'en befintlig personalkod tas inte över');
ru_absent(json_encode($used, JSON_UNESCAPED_UNICODE), $staffCode, 'svaret på krock ekar inte koden');

$shortRes = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $short, 'dry' => 1]);
ru(empty($shortRes['ok']) && !is_file($usersFile), 'för kort kod sparas inte');
ru_absent(json_encode($shortRes, JSON_UNESCAPED_UNICODE), $short, 'avvisad kod ekas inte');

$prefix = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $other, 'dry' => 1]);
ru(empty($prefix['ok']) && ($prefix['username'] ?? '') === $username, 'koden måste börja med användarnamnet');
ru_absent(json_encode($prefix, JSON_UNESCAPED_UNICODE), $other, 'fel prefix ekas inte');

$dry = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code]);
ru(!empty($dry['ok']) && ($dry['dry'] ?? 0) === 1 && ($dry['would_create'] ?? 0) === 1 && ru_hash_count($passFile) === $before && !is_file($usersFile), 'utan dry skrivs inget');
ru_absent(json_encode($dry, JSON_UNESCAPED_UNICODE), $code, 'torrkörning ekar inte koden');

$noConfirm = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'ja']);
ru(empty($noConfirm['ok']) && str_contains((string) ($noConfirm['error'] ?? ''), 'SAM') && ru_hash_count($passFile) === $before, 'dry=0 utan confirm skriver inget');

putenv('JEAB_PASSWORDS_FILE=' . $publicPass);
jeab_auth_data(true);
$publicTry = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
ru(empty($publicTry['ok']) && ru_hash_count($publicPass) === 0, 'lösenord inne i webbroten skrivs inte');
putenv('JEAB_PASSWORDS_FILE=' . $passFile);
jeab_auth_data(true);

$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = $tmp . '/public/restricted-users.json';
jeab_restricted_read(true);
$inside = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
ru(empty($inside['ok']) && ru_hash_count($passFile) === $before && !is_file($tmp . '/public/restricted-users.json'), 'rollfilen får inte ligga i webbroten');
$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = $usersFile;
jeab_restricted_read(true);

$created = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
$createdJson = json_encode($created, JSON_UNESCAPED_UNICODE);
ru(!empty($created['ok']) && ($created['created'] ?? 0) === 1 && ($created['dry'] ?? 1) === 0, 'confirm skapar användaren');
ru_absent((string) $createdJson, $code, 'skapandet ekar inte koden');
ru(!isset($created['hash']) && !isset($created['code']) && !isset($created['password']), 'svaret har varken hash eller kod');
ru(ru_hash_count($passFile) === $before + 1, 'hashen läggs till bland de andra');
$passRaw = (string) file_get_contents($passFile);
$usersRaw = (string) file_get_contents($usersFile);
ru_absent($passRaw, $code, 'lösenordsfilen innehåller hashen, inte koden');
ru_absent($usersRaw, $code, 'rollfilen innehåller inte koden');
ru(password_verify($staffCode, (string) (json_decode($passRaw, true)['hashes'][0]['hash'] ?? '')), 'personalens hash är kvar');
$saved = json_decode($usersRaw, true);
ru(is_array($saved) && count($saved) === 1, 'en rollpost');
$savedRow = is_array($saved) ? reset($saved) : [];
$savedKey = is_array($saved) ? (string) array_key_first($saved) : '';
ru(is_array($savedRow) && array_diff(array_keys($savedRow), ['name', 'role', 'username']) === [] && ($savedRow['role'] ?? '') === 'solkampanj' && ($savedRow['name'] ?? '') === $name, 'posten är namn, roll och användarnamn');
ru(preg_match('/^fp:[0-9a-f]{16}$/', $savedKey) === 1, 'nyckeln är fingeravtrycket');
ru((fileperms($usersFile) & 0777) === 0600 && (fileperms($passFile) & 0777) === 0600, 'filerna är inte läsbara för andra');

$match = jeab_auth_match($code);
ru(is_array($match) && ($match['email'] ?? '') === '', 'inloggningen träffar hashen utan e-post');
$hash = (string) ($match['hash'] ?? '');
ru(jeab_code_people_remember($code, $hash) === null, 'koden binds inte till en person i personalen');
ru(jeab_person_by_code_prefix($code) === null, 'prefixet träffar inte personalen');
ru(!isset(jeab_code_people_read()['fp:' . jeab_auth_hash_fingerprint($hash)]), 'ingen personalpost sparas för koden');

$again = jeab_restricted_add(['name' => $name, 'role' => 'solkampanj', 'code' => $code, 'dry' => 0, 'confirm' => 'SAM']);
ru(!empty($again['ok']) && ($again['already'] ?? 0) === 1 && ru_hash_count($passFile) === $before + 1, 'samma kod skapar inte en andra hash');
ru_absent(json_encode($again, JSON_UNESCAPED_UNICODE), $code, 'omkörning ekar inte koden');

$cookie = jeab_auth_issue_value(null, $hash);
ru(is_string($cookie) && $cookie !== '', 'kakan kan ställas ut');
$_COOKIE[JEAB_AUTH_COOKIE] = $cookie;
unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
$_GET = [];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
$_POST = [];
ru(jeab_auth_ok() && jeab_restricted_is_solkampanj() && !jeab_is_admin(), 'inloggad med rollen, inte admin');
ru(jeab_restricted_decision() === 'redirect', 'andra sidor styrs till solkampanj');
$_GET = ['p' => 'offert'];
ru(jeab_restricted_decision() === 'allow', 'kalkylsidan är öppen');
$_GET = ['p' => 'kalkyl'];
ru(jeab_restricted_decision() === 'allow', 'p=kalkyl är samma kalkylator');
$_GET = [];
$_SERVER['SCRIPT_NAME'] = '/kalkyl.php';
$_SERVER['REQUEST_URI'] = '/kalkyl.php';
ru(jeab_restricted_kalkyl_entry() && jeab_restricted_decision() === 'allow', 'kalkyl.php är öppen');
jeab_promote_kalkyl_request();
ru(($_GET['p'] ?? '') === 'offert', 'tom kalkyl.php blir kalkylatorn');
$_GET = ['p' => 'inkorg'];
ru(jeab_restricted_decision() === 'redirect', 'kalkyl.php öppnar inte andra sidor');
jeab_promote_kalkyl_request();
ru(($_GET['p'] ?? '') === 'inkorg', 'en annan sida skrivs inte om till kalkylatorn');
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'offert.defaults'];
ru(jeab_restricted_decision() === 'allow', 'kalkyl.php får spara sina standardvärden');
$_POST = ['action' => 'reminder.create'];
ru(jeab_restricted_decision() === 'deny', 'kalkyl.php tar inte andra sparningar');
$_GET = ['graph_hook' => '1'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ru(jeab_restricted_decision() === 'redirect' && !jeab_restricted_kalkyl_entry(), 'kalkyl.php är inte en hook');
$_GET = [];
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/kalkyl.php';
ru(jeab_restricted_decision() === 'allow', 'omskriven /kalkyl.php är samma kalkylator');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'offert.defaults'];
ru(jeab_restricted_decision() === 'allow', 'omskriven kalkyl får spara standardvärden');
$_POST = ['action' => 'reminder.create'];
ru(jeab_restricted_decision() === 'deny', 'omskriven kalkyl tar inte andra sparningar');
$_SERVER['SCRIPT_NAME'] = '/hook.php';
$_SERVER['REQUEST_URI'] = '/kalkyl.php';
ru(jeab_restricted_decision() === 'deny', 'en hook blir inte kalkyl för att adressen säger det');
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_GET = [];
$_GET = ['p' => 'csrf'];
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
ru(jeab_restricted_decision() === 'allow', 'kalkylatorn får förnya CSRF');
unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
$_GET = ['p' => 'offert'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'offert.defaults'];
ru(jeab_restricted_decision() === 'allow', 'bara kalkylens standardvärden får sparas');
$_POST = ['action' => 'reminder.create'];
ru(jeab_restricted_decision() === 'deny', 'andra sparningar är stängda');
$_POST = ['action' => 'offert.defaults'];
$_GET = ['p' => 'inkorg'];
ru(jeab_restricted_decision() === 'deny', 'kalkylsparning på en annan sida är stängd');
$_GET = ['p' => 'reminders'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_SERVER['HTTP_ACCEPT'] = 'application/json';
ru(jeab_restricted_decision() === 'deny', 'övriga JSON-anrop svarar 403');
unset($_SERVER['HTTP_ACCEPT']);
$_GET = [];
ru(!function_exists('jeab_who_needs_pick') && !function_exists('jeab_who_picker_html'), 'personalväljaren finns inte');
$_SERVER['SCRIPT_NAME'] = '/filer.php';
ru(jeab_restricted_decision() === 'redirect', 'TinyFM är inte nåbar');
$_SERVER['SCRIPT_NAME'] = '/hook.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
ru(jeab_restricted_decision() === 'deny', 'hook svarar 403');
$_SERVER['SCRIPT_NAME'] = '/graph-hook.php';
ru(jeab_restricted_decision() === 'deny', 'övriga hookar svarar 403');
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET['format'] = 'json';
$_SERVER['REQUEST_METHOD'] = 'GET';
ru(jeab_restricted_decision() === 'deny', 'JSON-anrop svarar 403');
$_GET = [];
$_SERVER['SCRIPT_NAME'] = '/solkampanj.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
ru(jeab_restricted_decision() === 'allow', 'solkampanj.php är öppen');
ru(jeab_solkampanj_actor() === $name, 'sent_by är hennes namn');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$hookAt = strpos($hookSrc, "=== 'users.add_restricted'");
$hookSlice = $hookAt === false ? '' : substr($hookSrc, $hookAt, 700);
ru($hookAt !== false && str_contains($hookSlice, 'jeab_inkorg_hook_authorized') && strpos($hookSlice, 'jeab_inkorg_hook_authorized') < strpos($hookSlice, 'jeab_restricted_add'), 'hooken kräver token');
$bootSrc = (string) file_get_contents(dirname(__DIR__) . '/src/bootstrap.php');
ru(str_contains($bootSrc, 'jeab_restricted_fence(true)'), 'grinden körs för webbanrop');
$authSrc = (string) file_get_contents(dirname(__DIR__) . '/src/auth.php');
ru(str_contains($authSrc, "jeab_redirect(jeab_url('solkampanj.php'), 303)"), 'inloggning landar på solkampanj');
$pageSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/solkampanj.php');
ru(str_contains($pageSrc, 'if ($solkampanjAdmin)') && str_contains($pageSrc, 'solkampanj.settings'), 'inställningar ritas bara för admin');

jeab_solkampanj_set_test_mode(true);
$blockedSettings = jeab_solkampanj_apply_settings(['test_mode' => '0'], []);
ru(empty($blockedSettings['ok']) && jeab_solkampanj_test_mode() === true, 'testläge kan inte stängas av');

$spy = "namn;mejl\nHemlig;hemlig@example.test\n";
$blockedImport = jeab_solkampanj_import_dispatch(['csv' => $spy]);
ru(empty($blockedImport['ok']) && (int) ($blockedImport['status'] ?? 0) === 403, 'rollen kan inte importera');
ru(!str_contains(json_encode($blockedImport, JSON_UNESCAPED_UNICODE), 'hemlig@example.test'), 'avvisad import ekar inte raden');
$csv = "namn;gata;postnr;ort;mejl\nKund Kundsson;Solgatan 1;177 31;Järfälla;kund@example.test\nAnnan Person;Bakgatan 9;177 32;Järfälla;annan@example.test\n";
$imported = jeab_solkampanj_import_csv($csv);
ru(($imported['imported'] ?? 0) === 2, 'två mottagare i testlistan');
$otherTown = jeab_solkampanj_import_csv("namn;gata;postnr;ort;mejl\nStad Person;Storgatan 1;191 00;Stockholm;stad@example.test\n");
ru(($otherTown['imported'] ?? 0) === 1 && jeab_solkampanj_active_ort() === 'Järfälla', 'rollen börjar i Järfälla');
$calls = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$calls): array {
    $calls[] = $opts;

    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'm-r', 'from' => (string) ($opts['person_from'] ?? '')];
};
$next = jeab_solkampanj_next();
$png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$sent = jeab_solkampanj_send([
    'id' => (int) ($next['id'] ?? 0),
    'antal' => '4',
    'kwh' => '300',
    'pris' => '80000',
    'image' => 'data:image/png;base64,' . $png,
], jeab_solkampanj_actor());
ru(!empty($sent['ok']) && count($calls) === 1, 'Skicka går via systemmejlet');
ru(($calls[0]['person_from'] ?? '') === 'demo@example.test' && ($calls[0]['from_email'] ?? '') === 'demo@example.test', 'mejlet går från Sams brevlåda');
ru(($calls[0]['guard_actor'] ?? '') === $name, 'utskicket loggar hennes namn');
$stored = jeab_solkampanj_fetch(jeab_solkampanj_pdo(), (int) ($next['id'] ?? 0));
ru(is_array($stored) && trim((string) ($stored['sent_by'] ?? '')) === '' && ($stored['status'] ?? '') === 'pending', 'testläge förbrukar inte hennes mottagare');
$public = $sent['next'] ?? null;
ru(is_array($public) && ($public['mejl'] ?? '') === 'kund@example.test' && !isset($public['customer_number']) && !array_key_exists('values_json', $public), 'kön stannar på samma mottagare');
$blockedTest = jeab_solkampanj_test_send([
    'id' => (int) ($next['id'] ?? 0),
    'antal' => '4',
    'kwh' => '300',
    'pris' => '80000',
    'image' => 'data:image/png;base64,' . $png,
], jeab_solkampanj_actor());
ru(empty($blockedTest['ok']) && (int) ($blockedTest['status'] ?? 0) === 403 && count($calls) === 1, 'test_send är bara för admin');
ru_absent(json_encode($sent, JSON_UNESCAPED_UNICODE), $code, 'sändsvaret innehåller inte koden');

$runner = $tmp . '/fence.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');
$GLOBALS['_JEAB_APP_ROOT'] = getenv('JEAB_TEST_APP');
$GLOBALS['_JEAB_RESTRICTED_USERS_FILE'] = getenv('JEAB_TEST_USERS');
$GLOBALS['_JEAB_SOLKAMPANJ_DIR'] = getenv('JEAB_TEST_SOL');
$GLOBALS['_JEAB_RESTRICTED_FENCE'] = true;
putenv('JEAB_PASSWORDS_FILE=' . getenv('JEAB_TEST_PW'));
putenv('JEAB_DISABLE_SQLITE');
$_COOKIE['jeab_auth'] = (string) getenv('JEAB_TEST_COOKIE');
$_SERVER['SCRIPT_NAME'] = (string) getenv('JEAB_TEST_SCRIPT');
$_SERVER['REQUEST_METHOD'] = (string) getenv('JEAB_TEST_METHOD');
$_SERVER['REQUEST_URI'] = (string) (getenv('JEAB_TEST_URI') ?: '/');
$_GET = [];
$jeabTestP = getenv('JEAB_TEST_P');
if (is_string($jeabTestP) && $jeabTestP !== '') {
    $_GET['p'] = $jeabTestP;
}
$jeabTestAccept = getenv('JEAB_TEST_ACCEPT');
if (is_string($jeabTestAccept) && $jeabTestAccept !== '') {
    $_SERVER['HTTP_ACCEPT'] = $jeabTestAccept;
}
$_POST['action'] = (string) getenv('JEAB_TEST_ACTION');
require getenv('JEAB_TEST_ROOT') . '/src/bootstrap.php';
if (getenv('JEAB_TEST_PAGE') === '1') {
    jeab_start_session();
    $solkampanjTowns = [['ort' => 'Järfälla', 'pending' => 1]];
    $solkampanjOrt = 'Järfälla';
    $solkampanjRow = [
        'id' => 1,
        'namn' => 'Kund Kundsson',
        'first_name' => 'Kund',
        'mejl' => 'kund@example.test',
        'gata' => 'Solgatan 1',
        'postnr' => '177 31',
        'ort' => 'Järfälla',
    ];
    $solkampanjProgress = ['sent' => 0, 'total' => 1, 'skipped' => 0, 'pending' => 1];
    $solkampanjTest = true;
    $solkampanjPdf = false;
    $solkampanjFlash = '';
    $solkampanjError = '';
    include getenv('JEAB_TEST_ROOT') . '/src/pages/solkampanj.php';
    exit;
}
jeab_restricted_fence(getenv('JEAB_TEST_LOGOUT') === '1');
echo 'STILL';
PHP);
$envBase = [
    'JEAB_TEST_DATA' => $tmp,
    'JEAB_TEST_APP' => $tmp . '/public',
    'JEAB_TEST_USERS' => $usersFile,
    'JEAB_TEST_SOL' => $tmp . '/privat/solkampanj',
    'JEAB_TEST_PW' => $passFile,
    'JEAB_TEST_COOKIE' => $cookie,
    'JEAB_TEST_ROOT' => dirname(__DIR__),
    'JEAB_TEST_LOGOUT' => '0',
];
$runFence = static function (array $extra) use ($runner, $envBase): string {
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    unset($env['JEAB_DISABLE_SQLITE']);
    foreach (array_merge($envBase, $extra) as $key => $value) {
        $env[$key] = (string) $value;
    }
    $proc = proc_open([PHP_BINARY, $runner], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
    $err = is_resource($proc) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($proc)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    return (string) $out . (string) $err;
};
$html = $runFence([
    'JEAB_TEST_PAGE' => '1',
    'JEAB_TEST_SCRIPT' => '/solkampanj.php',
    'JEAB_TEST_METHOD' => 'GET',
    'JEAB_TEST_ACTION' => '',
]);
ru(!str_contains($html, 'Fatal'), 'sidan ritas');
ru(str_contains($html, 'id="send"') && str_contains($html, 'id="skip"') && str_contains($html, "addEventListener('paste'"), 'klistra in, Skicka och Hoppa över finns');
ru(str_contains($html, 'id="ort"') && str_contains($html, 'Järfälla'), 'orten kan väljas');
ru(!str_contains($html, 'solkampanj.settings') && !str_contains($html, 'name="csv"') && !str_contains($html, 'name="test_mode"'), 'uppladdning och testläge syns inte');
ru(str_contains($html, 'kalkyl.php') && str_contains($html, '>Kalkyl<') && str_contains($html, 'aria-current="page"') && str_contains($html, 'name="action" value="logout"'), 'navigerar bara mellan Solkampanj och Kalkyl');
ru(!str_contains($html, 'index.php?p=offert'), 'kalkylfliken går till kalkyl.php');
ru(!str_contains($html, 'p=links') && !str_contains($html, 'p=inkorg') && !str_contains($html, 'p=ordrar') && !str_contains($html, 'p=reminders'), 'inga andra sidor i navigeringen');
ru(str_contains($html, 'kund@example.test') && !str_contains($html, 'customer_number'), 'bara den aktuella mottagaren');
ru_absent($html, $code, 'sidan visar inte koden');
$denied = $runFence(['JEAB_TEST_SCRIPT' => '/hook.php', 'JEAB_TEST_METHOD' => 'POST', 'JEAB_TEST_ACTION' => 'solkampanj.import']);
ru(str_contains($denied, 'Inte behörig') && !str_contains($denied, 'STILL'), 'hook.php avslutas med 403');
ru_absent($denied, $code, '403-svaret innehåller inte koden');
$logout = $runFence(['JEAB_TEST_SCRIPT' => '/index.php', 'JEAB_TEST_METHOD' => 'POST', 'JEAB_TEST_ACTION' => 'logout', 'JEAB_TEST_LOGOUT' => '1']);
ru(str_contains($logout, 'STILL'), 'utloggning stoppas inte före index');
$redirected = $runFence(['JEAB_TEST_SCRIPT' => '/index.php', 'JEAB_TEST_METHOD' => 'GET', 'JEAB_TEST_ACTION' => '']);
ru(!str_contains($redirected, 'STILL') && !str_contains($redirected, 'Inte behörig') && !str_contains($redirected, 'Fatal'), 'index styrs om och ritas inte');
$calc = $runFence(['JEAB_TEST_SCRIPT' => '/index.php', 'JEAB_TEST_METHOD' => 'GET', 'JEAB_TEST_ACTION' => '', 'JEAB_TEST_P' => 'kalkyl']);
ru(str_contains($calc, 'STILL') && !str_contains($calc, 'Fatal'), 'kalkylatorn stoppas inte');
$calcFile = $runFence(['JEAB_TEST_SCRIPT' => '/kalkyl.php', 'JEAB_TEST_METHOD' => 'GET', 'JEAB_TEST_ACTION' => '', 'JEAB_TEST_URI' => '/kalkyl.php']);
ru(str_contains($calcFile, 'STILL') && !str_contains($calcFile, 'Inte behörig') && !str_contains($calcFile, 'Fatal'), 'kalkyl.php stoppas inte');
$calcUri = $runFence(['JEAB_TEST_SCRIPT' => '/index.php', 'JEAB_TEST_METHOD' => 'GET', 'JEAB_TEST_ACTION' => '', 'JEAB_TEST_URI' => '/kalkyl.php']);
ru(str_contains($calcUri, 'STILL') && !str_contains($calcUri, 'Fatal'), 'omskriven /kalkyl.php stoppas inte');
$calcOther = $runFence(['JEAB_TEST_SCRIPT' => '/kalkyl.php', 'JEAB_TEST_METHOD' => 'POST', 'JEAB_TEST_ACTION' => 'reminder.create', 'JEAB_TEST_URI' => '/kalkyl.php', 'JEAB_TEST_ACCEPT' => 'application/json']);
ru(str_contains($calcOther, 'Inte behörig') && !str_contains($calcOther, 'STILL'), 'kalkyl.php tar inte andra anrop');
$save = $runFence(['JEAB_TEST_SCRIPT' => '/index.php', 'JEAB_TEST_METHOD' => 'POST', 'JEAB_TEST_ACTION' => 'offert.defaults', 'JEAB_TEST_P' => 'offert', 'JEAB_TEST_ACCEPT' => 'application/json']);
ru(str_contains($save, 'STILL') && !str_contains($save, 'Inte behörig'), 'sparningen av kalkylens standard stoppas inte');
$otherSave = $runFence(['JEAB_TEST_SCRIPT' => '/index.php', 'JEAB_TEST_METHOD' => 'POST', 'JEAB_TEST_ACTION' => 'reminder.create', 'JEAB_TEST_P' => 'offert', 'JEAB_TEST_ACCEPT' => 'application/json']);
ru(str_contains($otherSave, 'Inte behörig') && !str_contains($otherSave, 'STILL'), 'annan sparning på kalkylsidan är 403');
require dirname(__DIR__) . '/src/layout.php';
jeab_start_session();
$nav = jeab_module_nav('offert');
ru(substr_count($nav, '<a ') === 2 && str_contains($nav, 'Solkampanj') && str_contains($nav, 'kalkyl.php') && str_contains($nav, 'is-on'), 'menyn har två flikar');
ru(!str_contains($nav, 'index.php?p='), 'menyn pekar inte på andra sidor');
ru(!str_contains($nav, 'Inkorg') && !str_contains($nav, 'Påminnelser') && !str_contains($nav, 'Kalender') && !str_contains($nav, 'Ordrar') && !str_contains($nav, 'Övrigt'), 'menyn har inga andra delar');
$chrome = jeab_chrome_html('offert');
ru(!str_contains($chrome, 'Malmö') && !str_contains($chrome, 'p=reminders') && str_contains($chrome, 'value="logout"'), 'kalkylatorn visar inte bolag eller resten av appen');

echo "OK  restricted\n";
