<?php

declare(strict_types=1);

/**
 * Inloggningar under Övrigt: nio tecken, ingen hemlighet, peka om bara när
 * personen saknas. Sam L ser inte Sam W:s Mina-filter.
 */

$case = getenv('JEAB_CASE') ?: '';
if ($case !== '') {
    lda_child($case, (string) getenv('JEAB_TEST_DIR'));
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-login-diag-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_TEST_DIR=' . $tmp);

$codeJl = 'SamLsecretREST123';
$codeBare = 'SamTESTsecretREST';
$codeOpen = 'xyzTESTsecretREST99';
$hashJl = password_hash($codeJl, PASSWORD_DEFAULT);
$hashBare = password_hash($codeBare, PASSWORD_DEFAULT);
$hashOpen = password_hash($codeOpen, PASSWORD_DEFAULT);
$hashW = password_hash('kod-sam-w', PASSWORD_DEFAULT);
$hashElin = password_hash('kod-elin', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@malmo.example.test', 'hash' => $hashJl],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashBare],
        ['hash' => $hashOpen],
        ['email' => 'demo@malmo.example.test', 'hash' => $hashW],
        ['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin],
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

function lda(bool $ok, string $msg): void
{
    if ($ok) {
        echo "OK  {$msg}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$msg}\n");
    exit(1);
}

function lda_cookie(?string $email, string $hash): void
{
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
}

function lda_run(string $case, array $extra): array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['JEAB_CASE'] = $case;
    $env['JEAB_TEST_DIR'] = (string) getenv('JEAB_TEST_DIR');
    foreach ($extra as $key => $value) {
        $env[$key] = $value;
    }
    $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([PHP_BINARY, __FILE__], $spec, $pipes, null, $env);
    $out = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
    $err = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
    if (is_resource($proc)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }
    $code = is_resource($proc) ? proc_close($proc) : 1;

    return [$code, $out, $err];
}

function lda_child(string $case, string $tmp): void
{
    if ($case === 'noddl') {
        $dir = $tmp . '/noddl';
        mkdir($dir, 0775, true);
        $hash = password_hash('SamLnoddlXYZ', PASSWORD_DEFAULT);
        file_put_contents($dir . '/passwords.json', json_encode([
            'secret' => bin2hex(random_bytes(8)),
            'hashes' => [['email' => 'demo@example.test', 'hash' => $hash]],
        ]));
        file_put_contents($dir . '/config.php', "<?php\nreturn " . var_export([
            'timezone' => 'Europe/Stockholm',
            'passwords_file' => $dir . '/passwords.json',
            'db' => ['driver' => 'sqlite', 'sqlite_path' => $dir . '/app.sqlite', 'json_path' => $dir . '/store.json'],
        ], true) . ";\n");
        $GLOBALS['_JEAB_DATA_DIR'] = $dir;
        putenv('JEAB_DISABLE_SQLITE=0');
        require dirname(__DIR__) . '/src/bootstrap.php';
        require_once dirname(__DIR__) . '/src/biz_doc_store.php';
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $hash);
        $_GET = ['p' => 'inloggdiag'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $pdo = jeab_biz_doc_store_pdo();
        if (!$pdo instanceof PDO) {
            fwrite(STDERR, "FAIL sqlite-pdo saknas\n");
            exit(1);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec('INSERT INTO settings ("key", value) VALUES (\'biz_storage_code_people\', \'sql\') ON CONFLICT("key") DO UPDATE SET value = excluded.value');
        $pdo->exec('DROP TABLE IF EXISTS json_docs');
        jeab_is_admin();
        jeab_auth_user_person();
        jeab_personal_login_person();
        jeab_login_diag_allowed();
        jeab_login_rows_diag();
        jeab_login_diag_html();
        jeab_login_diag_page();
        $name = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'json_docs'")->fetchColumn();
        if ($name) {
            fwrite(STDERR, "FAIL visning skapade json_docs\n");
            exit(1);
        }
        echo "OK  visning skapar inte json_docs\n";
        return;
    }

    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    putenv('JEAB_DISABLE_SQLITE=1');
    require dirname(__DIR__) . '/src/bootstrap.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $hash = (string) getenv('JEAB_HASH');
    $email = getenv('JEAB_EMAIL');
    $email = $email === false || $email === '' ? null : $email;
    $_SESSION = [];
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value($email, $hash);
    $_GET = ['p' => 'inloggdiag'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'action' => 'login.assign',
        'fp' => (string) getenv('JEAB_FP'),
        'person' => (string) getenv('JEAB_PERSON'),
        '_csrf' => getenv('JEAB_CSRF') === 'ok' ? jeab_csrf_token() : 'fel-token',
    ];
    jeab_login_diag_page();
    echo "NOEXIT\n";
}

jeab_code_people_remember($codeJl, $hashJl, 'demo@malmo.example.test');
lda_cookie(null, $hashJl);
lda((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_is_admin(), 'SamL-kod med Malmö-e-post är Sam L och admin');
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
$_SESSION['jeab_reminder_who'] = ['jarfalla' => ['who' => 'Sam W', 'at' => time()]];
lda(jeab_reminder_who_filter() === 'Sam L', 'Påminnelser i Järfälla är Sam L');
$_GET = ['p' => 'reminders', 'co' => 'malmo', 'who' => 'Sam', 'ws' => (string) time()];
$_SESSION['jeab_reminder_who'] = ['malmo' => ['who' => 'Sam', 'at' => time()]];
lda(jeab_reminder_who_filter() !== 'Sam W', 'förnamnet Sam i Malmö blir inte Sam W:s filter');
lda(jeab_login_diag_allowed(), 'Sam L får öppna Inloggningar');
$labels = array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
lda(in_array('Användare', $labels, true), 'länken heter Inloggningar');
$_GET['p'] = 'links';
$links = require dirname(__DIR__) . '/src/pages/links.php';
lda(is_string($links) && str_contains($links, '>Användare<') && str_contains($links, 'p=inloggdiag'), 'Övrigt visar Inloggningar');
$page = require dirname(__DIR__) . '/src/pages/inloggningar.php';
lda(is_string($page) && str_contains($page, '<h1>Användare</h1>'), 'sidan heter Inloggningar');

jeab_code_people_remember($codeBare, $hashBare, 'demo@malmo.example.test');
lda_cookie('demo@malmo.example.test', $hashBare);
lda(jeab_auth_user_person() === null && !jeab_is_admin() && !jeab_login_diag_allowed(), 'Sam utan L/W blir ingen och ser inte Inloggningar');
$_GET = ['p' => 'reminders', 'co' => 'malmo'];
lda(jeab_reminder_who_filter() === null, 'tvetydig kod visar inte Sam W');

lda_cookie('demo@malmo.example.test', $hashW);
$_GET = ['p' => 'reminders', 'co' => 'malmo'];
lda(jeab_auth_user_person() === null && jeab_who_me_name() === '' && jeab_reminder_who_filter() === null && !jeab_is_admin(), 'gammal Malmö-kaka utan huvud blir inte Sam W');
$wFp = jeab_auth_hash_fingerprint($hashW);
$wRow = null;
foreach (jeab_login_rows_diag() as $row) {
    if (($row['fp'] ?? '') === $wFp) {
        $wRow = $row;
    }
}
lda(is_array($wRow) && ($wRow['person'] ?? 'x') === '' && !empty($wRow['assign']) && str_contains((string) ($wRow['flags'] ?? ''), 'osäker'), 'rad utan huvud visas som osäker och kan pekas om');

jeab_code_people_remember($codeOpen, $hashOpen, null);
lda_cookie(null, $hashJl);
$html = jeab_login_diag_html();
$head = jeab_code_head($codeJl);
lda(str_contains($html, $head), 'listan visar kodens nio första tecken');
lda(!str_contains($html, substr($codeJl, 9)) && !str_contains($html, substr($codeBare, 9)) && !str_contains($html, substr($codeOpen, 9)), 'resten av koden visas inte');
lda(!str_contains($html, '$2y$') && !str_contains($html, $hashJl), 'hashen visas inte');
lda(str_contains($html, 'malmo.example.test') && str_contains($html, 'Malmö') && str_contains($html, 'Sam L'), 'domän, ort och person visas');
lda(str_contains($html, 'name="action" value="login.assign"') && str_contains($html, '>Peka</button>'), 'en inloggning utan person kan pekas om');
lda(!str_contains($html, '199001011234'), 'inget personnummer');

$openFp = jeab_auth_hash_fingerprint($hashOpen);
[$code, $out, $err] = lda_run('post', [
    'JEAB_HASH' => $hashElin,
    'JEAB_EMAIL' => 'saljare4@jarfalla.example.test',
    'JEAB_FP' => $openFp,
    'JEAB_PERSON' => 'Niman',
    'JEAB_CSRF' => 'ok',
]);
lda($code === 0 && str_contains($out, 'Bara admin.'), 'Elin får inte peka om, fick ' . trim($out . $err));
lda((jeab_code_people_read()['fp:' . $openFp]['via'] ?? '') !== 'manual', 'nekad pekning sparas inte');

[$code, $out, $err] = lda_run('post', [
    'JEAB_HASH' => $hashJl,
    'JEAB_EMAIL' => '',
    'JEAB_FP' => $openFp,
    'JEAB_PERSON' => 'Niman',
    'JEAB_CSRF' => 'bad',
]);
lda($code === 0 && str_contains($out, 'Ogiltig session'), 'fel CSRF sparas inte, fick ' . trim($out . $err));
lda((jeab_code_people_read()['fp:' . $openFp]['via'] ?? '') !== 'manual', 'CSRF-fel lämnar personen tom');

[$code, $out, $err] = lda_run('post', [
    'JEAB_HASH' => $hashJl,
    'JEAB_EMAIL' => '',
    'JEAB_FP' => $openFp,
    'JEAB_PERSON' => 'Niman',
    'JEAB_CSRF' => 'ok',
]);
$saved = jeab_code_people_read()['fp:' . $openFp] ?? [];
lda($code === 0 && ($saved['person'] ?? '') === 'Niman' && ($saved['via'] ?? '') === 'manual', 'admin pekar en tom inloggning, fick ' . trim($out . $err));
lda(!str_contains(json_encode($saved), substr($codeOpen, 9)), 'pekningen sparar inte resten av koden');

[$code, $out, $err] = lda_run('post', [
    'JEAB_HASH' => $hashJl,
    'JEAB_EMAIL' => '',
    'JEAB_FP' => $openFp,
    'JEAB_PERSON' => 'Elin',
    'JEAB_CSRF' => 'ok',
]);
lda($code === 0 && str_contains($out, 'pekar redan'), 'en andra pekning stoppas, fick ' . trim($out . $err));
lda((jeab_code_people_read()['fp:' . $openFp]['person'] ?? '') === 'Niman', 'den första personen ligger kvar');

[$code, $out, $err] = lda_run('post', [
    'JEAB_HASH' => $hashJl,
    'JEAB_EMAIL' => '',
    'JEAB_FP' => $wFp,
    'JEAB_PERSON' => 'SamL',
    'JEAB_CSRF' => 'ok',
]);
$wSaved = jeab_code_people_read()['fp:' . $wFp] ?? [];
lda($code === 0 && ($wSaved['via'] ?? '') === 'manual' && ($wSaved['person'] ?? '') === 'SamL', 'Inloggningar pekar om en Malmö-rad utan huvud, fick ' . trim($out . $err));
lda_cookie(null, $hashW);
lda((jeab_auth_user_person()['id'] ?? '') === 'SamL' && jeab_is_admin(), 'ompekad kaka är Sam L utan ny inloggning');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    [$code, $out, $err] = lda_run('noddl', []);
    lda($code === 0 && str_contains($out, 'OK'), 'sidvisning utan DDL, fick ' . trim($out . $err));
} else {
    echo "SKIP sqlite saknas\n";
}

echo "login diag assign: ok\n";
