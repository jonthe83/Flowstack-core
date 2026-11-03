<?php

declare(strict_types=1);

/**
 * QA: regler sidan och Grok-instruktionen är admin, valt bolag följer med flikarna.
 * Ingen kunddata. Ingen webbläsare.
 */

$tmp = sys_get_temp_dir() . '/jeab-qa-regler-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('JEAB_QA_TMP=' . $tmp);
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [
        ['hash' => 'elin-hash-fixture', 'email' => 'saljare4@jarfalla.example.test'],
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
        ['hash' => 'agenter-hash-fixture', 'email' => 'agenter@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$secret = 'HEMLIG-INSTRUKTION-QA';
$settingsFile = $tmp . '/inkorg/settings.json';
file_put_contents($settingsFile, json_encode(['intent' => $secret, 'examples' => [['id' => 'ex1', 'message' => 'exempel']]], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function qa(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function qa_login(string $email, string $hash): void
{
    $_GET = [];
    $_POST = [];
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [];
    $_SESSION['jeab_email'] = $email;
    $_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value($email, $hash);
}

function qa_throws(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        qa(str_contains($e->getMessage(), $needle), $label . ' (' . $e->getMessage() . ')');
        return;
    }
    fwrite(STDERR, "FAIL {$label} kastade inte\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

qa_login('saljare4@jarfalla.example.test', 'elin-hash-fixture');
qa(jeab_is_admin() === false, 'Elin är inte admin');
$before = (string) file_get_contents($settingsFile);
$_POST = ['intent' => 'STULEN-INSTRUKTION', 'product_tags' => []];
qa_throws(static function (): void {
    jeab_inkorg_action('inkorg.settings_save');
}, 'Inte behörig', 'Elin får inte spara Grok-instruktionen');
qa((string) file_get_contents($settingsFile) === $before, 'Elin ändrade inte instruktionen');
$_POST = ['id' => 'ex1'];
qa_throws(static function (): void {
    jeab_inkorg_action('inkorg.example_delete');
}, 'Inte behörig', 'Elin får inte ta bort ett exempel');
qa(str_contains((string) file_get_contents($settingsFile), 'ex1'), 'exemplet ligger kvar');
$_POST = ['match' => 'all', 'c_field' => ['from'], 'c_header' => [''], 'c_op' => ['contains'], 'c_value' => ['qa-test']];
qa(jeab_inkorg_action('inkorg.rule_test') === 'Testad. Inget ändrat.', 'rule_test skriver inget');
qa((string) file_get_contents($settingsFile) === $before, 'rule_test rör inte inställningarna');
$_POST = ['name' => 'Elinregel', 'match' => 'all', 'c_field' => ['from'], 'c_op' => ['contains'], 'c_value' => ['x']];
qa_throws(static function (): void {
    jeab_inkorg_action('inkorg.rule_save');
}, 'Inte behörig', 'Elin får inte spara en regel');

qa_login('demo@example.test', 'sam-hash-fixture');
qa(jeab_is_admin() === true, 'Sam L är admin');
$_POST = ['intent' => 'NY-INSTRUKTION-QA', 'product_tags' => []];
qa(jeab_inkorg_action('inkorg.settings_save') === 'Instruktionen sparad.', 'admin sparar instruktionen');
qa((string) (jeab_inkorg_settings()['intent'] ?? '') === 'NY-INSTRUKTION-QA', 'sparad instruktion läses tillbaka');
file_put_contents($settingsFile, json_encode(['intent' => $secret, 'examples' => [['id' => 'ex1', 'message' => 'exempel']]], JSON_UNESCAPED_UNICODE));
unset($GLOBALS['_JEAB_INKORG_PTAGS']);

qa_login('demo@example.test', 'sam-hash-fixture');
$_SESSION['jeab_company'] = 'malmo';
$_GET = [];
jeab_auth_bind_user('demo@example.test');
qa(($_SESSION['jeab_company'] ?? '') === 'malmo', 'Sams Malmö överlever grinden');
qa(jeab_selected_company() === 'malmo', 'valt bolag är Malmö utan ?co=');
$nav = jeab_module_nav('reminders');
qa(str_contains($nav, 'co=malmo') && str_contains($nav, 'p=bookings'), 'flikarna bär co=malmo');
qa(!str_contains($nav, 'who='), 'flikarna bär inte who');
qa(str_contains(jeab_tab_url('reminders'), 'co=malmo'), 'märket bär co=malmo');

qa_login('saljare4@jarfalla.example.test', 'elin-hash-fixture');
unset($_SESSION['jeab_company']);
$_GET = [];
jeab_auth_bind_user('saljare4@jarfalla.example.test');
qa(jeab_selected_company() === 'jarfalla', 'Elin utan val stannar i Järfälla');
$_SESSION['jeab_company'] = 'malmo';
$_GET = ['co' => 'malmo'];
jeab_auth_bind_user('saljare4@jarfalla.example.test');
qa(jeab_selected_company() === 'malmo', 'Elin behåller valt Malmö');
qa(($_SESSION['jeab_company'] ?? '') === 'malmo', 'Elins valda Malmö ligger kvar i sessionen');
$elinNav = jeab_module_nav('bookings');
qa(str_contains($elinNav, 'co=malmo'), 'Elins flikar följer det valda kontoret');

qa_login('agenter@flowstack.example.test', 'agenter-hash-fixture');
qa(jeab_is_admin() === true, 'agenter@ är admin');
$_SESSION['jeab_company'] = 'malmo';
$_GET = [];
jeab_auth_bind_user('agenter@flowstack.example.test');
qa(jeab_selected_company() === 'malmo', 'agenter@ behåller Malmö');
qa(str_contains(jeab_module_nav('ordrar'), 'co=malmo'), 'agenter@ flikar bär Malmö');

$fast = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_fast.php');
$at = strpos($fast, 'function jeab_inkorg_page_counts');
$head = $at === false ? '' : substr($fast, $at, 280);
qa(str_contains($head, 'jeab_inkorg_fast_counts_ready($menu, $box, $me)'), 'sidräknaren läser cachen först');
qa(!str_contains($head, 'jeab_inkorg_fast_counts_refresh'), 'sidräknaren anropar inte omräkningen');

$root = dirname(__DIR__);
$router = $tmp . '/router.php';
file_put_contents($router, <<<PHP
<?php
\$tmp = getenv('JEAB_QA_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/passwords.json');
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$root}/index.php';
    return true;
}
return false;
PHP);

$port = 8913;
$server = null;
for ($try = 0; $try < 8; $try++) {
    $candidate = 8913 + $try;
    $probe = @fsockopen('127.0.0.1', $candidate, $errno, $errstr, 0.15);
    if (is_resource($probe)) {
        fclose($probe);
        continue;
    }
    $port = $candidate;
    $server = proc_open(
        ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
        [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
        $pipes,
        $root
    );
    break;
}
qa(is_resource($server), 'php -S startade');
register_shutdown_function(static function () use ($server): void {
    if (!is_resource($server)) {
        return;
    }
    $status = proc_get_status($server);
    $pid = (int) ($status['pid'] ?? 0);
    if ($pid > 0) {
        proc_terminate($server);
    }
    proc_close($server);
});
$deadline = microtime(true) + 5;
$up = false;
do {
    $ping = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=reminders');
    if (is_string($ping) && $ping !== '') {
        $up = true;
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);
qa($up, 'servern svarar på ' . $port);

function qa_http(string $url, string $jar, string $auth, string $method = 'GET', ?string $data = null, array $headers = []): array
{
    $ch = curl_init($url);
    $http = array_merge(['Cookie: jeab_auth=' . rawurlencode($auth)], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $http,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 25,
    ];
    if ($data !== null) {
        $opts[CURLOPT_POSTFIELDS] = $data;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$status, substr($raw, $headerSize)];
}

$elin = (string) jeab_auth_issue_value('saljare4@jarfalla.example.test', 'elin-hash-fixture');
$sam = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
$base = 'http://127.0.0.1:' . $port . '/index.php';
$elinJar = $tmp . '/elin.jar';
$jonJar = $tmp . '/jon.jar';

[$code, $elinRules] = qa_http($base . '?p=inkorg-regler', $elinJar, $elin);
qa($code === 200 && str_contains($elinRules, 'Inte behörig.'), 'Elin ser inte regelsidan, fick ' . $code);
qa(!str_contains($elinRules, 'name="intent"') && !str_contains($elinRules, $secret), 'Elin ser inte Grok-instruktionen');

[$code, $jonRules] = qa_http($base . '?p=inkorg-regler', $jonJar, $sam);
qa($code === 200 && str_contains($jonRules, 'name="intent"') && str_contains($jonRules, $secret), 'admin ser instruktionen');

[$code, $elinHome] = qa_http($base . '?p=reminders', $elinJar, $elin);
qa($code === 200, 'Elin når påminnelser');
qa(preg_match('/<meta name="csrf" content="([^"]+)"/', $elinHome, $csrf) === 1, 'Elin får en csrf-token');
$saved = (string) file_get_contents($settingsFile);
[$postCode, $postBody] = qa_http(
    $base . '?p=inkorg-regler&format=json',
    $elinJar,
    $elin,
    'POST',
    http_build_query([
        'action' => 'inkorg.settings_save',
        'intent' => 'STULEN-VIA-HTTP',
        '_csrf' => $csrf[1] ?? '',
    ]),
    ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']
);
qa($postCode === 422 && str_contains($postBody, 'Inte behörig'), 'HTTP-sparning nekas Elin, fick ' . $postCode . ' ' . substr($postBody, 0, 180));
qa((string) file_get_contents($settingsFile) === $saved, 'HTTP-sparning skrev inte instruktionen');

[$code, $picked] = qa_http($base . '?p=reminders&co=malmo', $jonJar, $sam);
qa($code === 200 && preg_match('/class="chip is-on"[^>]*>Malmö</', $picked) === 1, 'Sam väljer Malmö');
qa(str_contains($picked, 'p=bookings') && str_contains($picked, 'co=malmo'), 'menyn efter valet bär co=malmo');
[$code, $next] = qa_http($base . '?p=bookings', $jonJar, $sam);
qa($code === 200 && preg_match('/class="chip is-on"[^>]*>Malmö</', $next) === 1, 'Malmö sitter kvar på nästa flik utan ?co=');
qa(str_contains($next, 'co=malmo'), 'nästa flik länkar vidare med co=malmo');

[$code, $elinCo] = qa_http($base . '?p=reminders&co=malmo', $elinJar, $elin);
qa($code === 200 && preg_match('/class="chip is-on"[^>]*>Malmö</', $elinCo) === 1, 'Elin väljer Malmö via ?co=malmo');
qa(preg_match('/class="chip"[^>]*>Järfälla</', $elinCo) === 1 && !preg_match('/class="chip is-on"[^>]*>Järfälla</', $elinCo), 'Elin ser Järfälla men har Malmö valt');

echo "qa regler company: ok\n";
