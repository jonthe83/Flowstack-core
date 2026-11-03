<?php

declare(strict_types=1);

require_once __DIR__ . '/jeab_test_proc_env.php';

/**
 * ordrar.install_note via php -S.
 * sendBeacon-liknande multipart (FormData, csrf i kroppen, ingen X-Requested-With)
 * och fetch med JSON-headers. Sedan läses anteckningen som Ordrar/v3 ritar den.
 */

$tmp = sys_get_temp_dir() . '/jeab-note-http-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_NOTE_HTTP_TMP=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");
$hash = password_hash('note-http-kod', PASSWORD_DEFAULT);
file_put_contents($tmp . '/pw.json', json_encode([
    'secret' => 'note-http-secret',
    'hashes' => [['hash' => $hash, 'email' => 'note@jarfalla.example.test']],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/pw.json');

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function note_http_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function note_http_ok(string $message): void
{
    echo "OK  {$message}\n";
}

$store = new JeabJsonStore($tmp . '/store.json');
$reminder = jeab_create_reminder($store, 'QA Anteckning', null, 'Order', null, [
    'customer' => 'QA Anteckning',
    'company' => 'jarfalla',
    'address' => 'Testgatan 2, 175 00 Järfälla',
    'notes' => 'Order',
]);
$id = (int) $reminder['id'];
jeab_purchase_put([
    'reminder_id' => $id,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'QA Anteckning',
    'address' => 'Testgatan 2, 175 00 Järfälla',
    'pris' => 12000,
    'install_note' => 'QA test b',
    'fastighetsbeteckning' => '',
    'steps' => [],
]);

$stale = jeab_purchase_get($id);
if (!is_array($stale)) {
    note_http_fail('ordern saknas innan sidvisningen');
}
jeab_ordrar_save_install_note(['id' => $id, 'install_note' => 'QA test b QA6']);
$stale['install_note'] = 'QA test b';
$stale['fastighetsbeteckning'] = '';
jeab_ordrar_bekrafta_apply_carry($stale, [
    'fastighetsbeteckning' => 'Testen 1:1',
    'owners' => [],
], null);
jeab_purchase_cache_forget();
$afterCarry = jeab_purchase_get($id);
if (!is_array($afterCarry) || (string) ($afterCarry['install_note'] ?? '') !== 'QA test b QA6') {
    note_http_fail('sidvisningens ifyllnad skrev tillbaka den gamla anteckningen: ' . (string) ($afterCarry['install_note'] ?? ''));
}
if ((string) ($afterCarry['fastighetsbeteckning'] ?? '') !== 'Testen 1:1') {
    note_http_fail('sidvisningen sparade inte fastigheten');
}
note_http_ok('ifyllnad på sidvisning lämnar anteckningen');

jeab_ordrar_save_install_note(['id' => $id, 'install_note' => 'QA test b']);
jeab_purchase_cache_forget();

jeab_auth_data(true);
$cookie = jeab_auth_issue_value('note@jarfalla.example.test', $hash);
if (!is_string($cookie) || $cookie === '') {
    note_http_fail('testkaka');
}

$root = dirname(__DIR__);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n" . jeab_test_sqlite_boot() . <<<PHP
\$tmp = getenv('JEAB_NOTE_HTTP_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_FELLOGG_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/pw.json');
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$root}/index.php';
    return true;
}
return false;
PHP);

$port = 8944;
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $root,
    jeab_test_proc_env(['JEAB_TEST_TEMP_SQLITE' => '1', 'JEAB_NOTE_HTTP_TMP' => $tmp, 'JEAB_DISABLE_SQLITE' => '1', 'JEAB_PASSWORDS_FILE' => $tmp . '/pw.json'])
);
if (!is_resource($server)) {
    note_http_fail('php -S');
}
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});

$jar = $tmp . '/cookies.txt';
file_put_contents($jar, "# Netscape HTTP Cookie File\n127.0.0.1\tFALSE\t/\tFALSE\t0\tjeab_auth\t{$cookie}\n");

function note_http_curl(string $url, string $jar, array $headers = [], ?array $post = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $post;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

function note_http_textarea(string $html): ?string
{
    if (!preg_match('/<textarea id="v3-note"[^>]*>(.*?)<\/textarea>/s', $html, $match)) {
        return null;
    }

    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

$base = 'http://127.0.0.1:' . $port . '/index.php';
$pageUrl = $base . '?p=ordrar&co=jarfalla&id=' . $id;

$deadline = microtime(true) + 8;
$up = false;
do {
    $probe = note_http_curl($base . '?p=links', $jar);
    if ($probe[0] === 200 && str_contains($probe[2], 'JEAB')) {
        $up = true;
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);
if (!$up) {
    $err = is_file($tmp . '/server.err') ? (string) file_get_contents($tmp . '/server.err') : '';
    note_http_fail('servern svarade inte: ' . substr($err, 0, 400));
}

$first = note_http_curl($pageUrl, $jar);
$note = note_http_textarea($first[2]);
if ($first[0] !== 200 || $note !== 'QA test b') {
    note_http_fail('sidan läste inte QA test b, status ' . $first[0] . ' text=' . var_export($note, true) . ' ' . substr(preg_replace('/\s+/', ' ', $first[2]) ?? '', 0, 300));
}
if (!preg_match('/<form id="v3-f-bekrafta"[^>]*>(.*?)<\/form>/s', $first[2], $formMatch)) {
    note_http_fail('bekräfta-formuläret saknas');
}
$form = $formMatch[1];
if (!preg_match('/name="_csrf" value="([^"]+)"/', $form, $csrfMatch) || !preg_match('/name="id" value="(\d+)"/', $form, $idMatch)) {
    note_http_fail('csrf eller id saknas i formuläret');
}
$csrf = $csrfMatch[1];
$formId = $idMatch[1];
if ($formId !== (string) $id) {
    note_http_fail('formulärets id är ' . $formId . ' inte ordern');
}
note_http_ok('sidan läser install_note från ordern');

$fields = [
    '_csrf' => $csrf,
    'id' => (string) $id,
    'action' => 'ordrar.install_note',
    'install_note' => 'QA test b QA6',
    'v3' => '1',
];

$anonJar = $tmp . '/anon.txt';
file_put_contents($anonJar, "# Netscape HTTP Cookie File\n");
$anon = note_http_curl($pageUrl, $anonJar, [], $fields);
jeab_purchase_cache_forget();
$still = jeab_purchase_get($id);
if (!is_array($still) || (string) ($still['install_note'] ?? '') !== 'QA test b') {
    note_http_fail('post utan kaka skrev anteckningen');
}
if ($anon[0] === 200 && str_contains($anon[2], '"ok":true')) {
    note_http_fail('post utan kaka svarade ok');
}
note_http_ok('grinden tar inte emot keepalive utan kaka, status ' . $anon[0]);

$bad = note_http_curl($pageUrl, $jar, [], [
    '_csrf' => 'fel-csrf',
    'id' => (string) $id,
    'action' => 'ordrar.install_note',
    'install_note' => 'QA test b QA6',
    'v3' => '1',
]);
jeab_purchase_cache_forget();
$still = jeab_purchase_get($id);
if ($bad[0] === 200 && str_contains($bad[2], '"ok":true')) {
    note_http_fail('fel csrf svarade ok');
}
if (!is_array($still) || (string) ($still['install_note'] ?? '') !== 'QA test b') {
    note_http_fail('fel csrf skrev anteckningen, status ' . $bad[0]);
}
note_http_ok('csrf i kroppen krävs, status ' . $bad[0]);

$beacon = note_http_curl($pageUrl, $jar, [], $fields);
jeab_purchase_cache_forget();
$stored = jeab_purchase_get($id);
$again = note_http_curl($pageUrl, $jar);
$shown = note_http_textarea($again[2]);
if (!is_array($stored) || (string) ($stored['install_note'] ?? '') !== 'QA test b QA6' || $shown !== 'QA test b QA6') {
    note_http_fail('beacon-posten fastnade inte. status ' . $beacon[0] . ' lagrad=' . (string) ($stored['install_note'] ?? '') . ' sida=' . var_export($shown, true) . ' kropp ' . substr($beacon[2], 0, 240));
}
if ($beacon[0] === 200 && str_contains($beacon[2], '"ok":true')) {
    note_http_fail('beacon utan JSON-headers fick ok-json');
}
note_http_ok('FormData utan X-Requested-With sparas och sidan läser samma anteckning, status ' . $beacon[0]);

$fetch = note_http_curl($pageUrl, $jar, [
    'X-Requested-With: XMLHttpRequest',
    'Accept: application/json',
], [
    '_csrf' => $csrf,
    'id' => (string) $id,
    'action' => 'ordrar.install_note',
    'install_note' => '  QA test b QA6 json  ',
    'v3' => '1',
]);
$payload = json_decode($fetch[2], true);
jeab_purchase_cache_forget();
$stored = jeab_purchase_get($id);
$after = note_http_curl($pageUrl, $jar);
$shown = note_http_textarea($after[2]);
if ($fetch[0] !== 200 || !is_array($payload) || ($payload['ok'] ?? false) !== true) {
    note_http_fail('fetch-svaret är inte 200 ok:true: ' . $fetch[0] . ' ' . substr($fetch[2], 0, 300));
}
if ((string) ($payload['row']['install_note'] ?? '') !== 'QA test b QA6 json') {
    note_http_fail('svarets rad har inte den trimmade anteckningen');
}
if (!is_array($stored) || (string) ($stored['install_note'] ?? '') !== 'QA test b QA6 json' || $shown !== 'QA test b QA6 json') {
    note_http_fail('sidan läser inte det som svaret sa: sida=' . var_export($shown, true));
}
note_http_ok('200 ok:true och sidans fält är samma install_note');

$newer = note_http_curl($pageUrl, $jar, [], [
    '_csrf' => $csrf,
    'id' => (string) $id,
    'action' => 'ordrar.install_note',
    'install_note' => 'QA11',
    'note_rev' => '2',
    'v3' => '1',
]);
$stalePost = note_http_curl($pageUrl, $jar, [], [
    '_csrf' => $csrf,
    'id' => (string) $id,
    'action' => 'ordrar.install_note',
    'install_note' => 'QA10',
    'note_rev' => '1',
    'v3' => '1',
]);
jeab_purchase_cache_forget();
$stored = jeab_purchase_get($id);
$afterRev = note_http_curl($pageUrl, $jar);
$shown = note_http_textarea($afterRev[2]);
if (!is_array($stored) || (string) ($stored['install_note'] ?? '') !== 'QA11' || (int) ($stored['install_note_rev'] ?? 0) !== 2 || $shown !== 'QA11') {
    note_http_fail('senare lägre note_rev skrev över. ny status ' . $newer[0] . ' sen status ' . $stalePost[0] . ' lagrad=' . (string) ($stored['install_note'] ?? '') . ' rev=' . (string) ($stored['install_note_rev'] ?? '') . ' sida=' . var_export($shown, true) . ' kropp ' . substr($stalePost[2], 0, 180));
}
note_http_ok('beacon med högre note_rev står kvar när den äldre posten kommer efter, status ' . $newer[0] . '/' . $stalePost[0]);

$rolled = $stored;
$rolled['install_note'] = 'QA10';
$rolled['install_note_rev'] = 1;
$rolled['customer'] = 'QA Anteckning kvar';
jeab_ordrar_put_leaving_note($rolled);
jeab_purchase_cache_forget();
$kept = jeab_purchase_get($id);
if (!is_array($kept) || (string) ($kept['install_note'] ?? '') !== 'QA11' || (int) ($kept['install_note_rev'] ?? 0) !== 2) {
    note_http_fail('sidvisning rullade tillbaka anteckning eller revision: ' . (string) ($kept['install_note'] ?? '') . ' rev=' . (string) ($kept['install_note_rev'] ?? ''));
}
if ((string) ($kept['customer'] ?? '') !== 'QA Anteckning kvar') {
    note_http_fail('sidvisningen sparade inte övriga fält');
}
note_http_ok('sidvisning lämnar anteckning och revision');
