<?php

declare(strict_types=1);

/**
 * Inloggad personal har båda kontoren i samma lista som väljaren.
 * Förvalet är det egna kontoret. Inkorgens åtgärder följer ärendets bolag,
 * och svaret skickas från ärendets brevlåda.
 */

$tmp = sys_get_temp_dir() . '/jeab-office-switch-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [
        ['hash' => 'elin-hash-fixture', 'email' => 'saljare4@jarfalla.example.test'],
        ['hash' => 'isaq-hash-fixture', 'email' => 'saljare6@malmo.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function ox(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function ox_login(string $email, string $hash): void
{
    $_GET = [];
    $_POST = [];
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [];
    $_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value($email, $hash);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
@mkdir(jeab_inkorg_dir(), 0775, true);
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['helpscout_companies' => []]));
$GLOBALS['_OX_SENDS'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null): array {
    if ($method === 'POST' && (str_contains($url, '/sendMail') || str_contains($url, '/reply'))) {
        $GLOBALS['_OX_SENDS'][] = [$url, $body];
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};

$msg = jeab_inkorg_ingest([
    'message_id' => 'getaccept-jf@example.test',
    'from' => 'reply_to_sender@getaccept.com',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Dokument signerat',
    'body_text' => 'GetAccept från Järfälla.',
    'source' => 'graph-jarfalla',
    'company' => 'jarfalla',
]);
$tid = (string) $msg['thread_id'];
ox(jeab_inkorg_thread_company_of($tid) === 'jarfalla', 'GetAccept-ärendet hör till Järfälla');

ox_login('saljare6@malmo.example.test', 'isaq-hash-fixture');
ox(jeab_actor_companies() === ['malmo', 'jarfalla'] && jeab_company_allowed('jarfalla') && jeab_company_allowed('malmo'), 'Isaq har Malmö först och båda kontoren i listan');
$_GET = [];
unset($_SESSION['jeab_company'], $_SESSION['jeab_inkorg_box'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
ox(jeab_selected_company() === 'malmo' && jeab_inkorg_box() === 'malmo', 'utan val stannar Isaq i Malmö');
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => ['malmo']]);
unset($_SESSION['jeab_company'], $_SESSION['jeab_inkorg_box'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
$_GET = [];
ox(jeab_inkorg_box() === 'malmo' && jeab_inkorg_box_readonly(), 'Malmö i Help Scout är fortfarande Isaqs förval');
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
unset($_SESSION['jeab_company'], $_SESSION['jeab_inkorg_box'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
$chrome = jeab_chrome_company_html('reminders');
ox(str_contains($chrome, 'Järfälla') && str_contains($chrome, 'Malmö') && str_contains($chrome, 'co=jarfalla') && str_contains($chrome, 'co=malmo'), 'väljaren visar båda kontoren');
$_GET = ['co' => 'jarfalla', 'box' => 'jarfalla'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
ox(jeab_selected_company() === 'jarfalla' && jeab_inkorg_box() === 'jarfalla', 'Isaq kan välja Järfälla');
ox(jeab_get_reminder(jeab_store(), 1) === null, 'ett påminnelse-id som inte finns är tomt');

$assign = array_keys(jeab_inkorg_assign_staff(jeab_inkorg_thread_company_of($tid)));
ox(in_array('Elin', $assign, true) && in_array('Kim', $assign, true) && array_search('Elin', $assign, true) < array_search('Kim', $assign, true), 'tilldelning för Järfälla-ärendet har båda kontoren, Järfälla först');

$_POST = ['thread' => $tid, 'status' => 'closed'];
ox(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'Isaq stänger GetAccept-ärendet');
ox((string) (jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'closed', 'ärendet är stängt');
$_POST = ['thread' => $tid, 'status' => 'open'];
ox(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'Isaq öppnar GetAccept-ärendet');
$_POST = ['thread' => $tid, 'assignee' => 'Elin'];
ox(jeab_inkorg_action('inkorg.assign') === 'Tilldelning sparad.', 'Isaq tilldelar Elin på Järfälla-ärendet');
ox((string) (jeab_inkorg_thread_meta($tid)['assignee'] ?? '') === 'Elin', 'tilldelningen sparades');

$before = count($GLOBALS['_OX_SENDS']);
$sent = jeab_inkorg_send_in_thread($tid, ['body' => 'Tack, vi har dokumentet.'], 'Isaq');
ox((string) ($sent['state'] ?? '') === 'sent' && (string) ($sent['from'] ?? '') === 'kontakt@jarfalla.example.test', 'svaret skickas från Järfällas brevlåda');
$url = (string) ($GLOBALS['_OX_SENDS'][$before][0] ?? '');
ox(count($GLOBALS['_OX_SENDS']) === $before + 1 && str_contains($url, 'jarfalla-demo') && !str_contains($url, 'malmo-demo'), 'Graph anropas med Järfällas mailbox');
ox((string) ($sent['to'] ?? '') === 'reply_to_sender@getaccept.com', 'mottagaren är avsändaren i tråden');

$_POST = ['thread' => $tid];
ox(str_starts_with(jeab_inkorg_action('inkorg.trash'), 'Ärendet flyttat till papperskorgen'), 'Isaq slänger GetAccept-ärendet');
ox((string) (jeab_inkorg_thread_meta($tid)['deleted_at'] ?? '') !== '', 'ärendet ligger i papperskorgen');
$_POST = ['thread' => $tid];
ox(jeab_inkorg_action('inkorg.restore') === 'Ärendet återställt.', 'Isaq återställer GetAccept-ärendet');

ox_login('saljare4@jarfalla.example.test', 'elin-hash-fixture');
$_GET = [];
unset($_SESSION['jeab_company'], $_SESSION['jeab_inkorg_box'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
ox(jeab_selected_company() === 'jarfalla', 'Elin utan val stannar i Järfälla');
$_GET = ['co' => 'malmo'];
ox(jeab_selected_company() === 'malmo', 'Elin kan välja Malmö');
$_GET = ['co' => 'jarfalla'];
ox(jeab_selected_company() === 'jarfalla', 'Elin kan välja tillbaka Järfälla');

$port = 0;
$srv = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:0', dirname(__DIR__) . '/tests/office_switch_router.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp,
    [
        'JEAB_DISABLE_SQLITE' => '',
        'JEAB_DATA_DIR' => $tmp,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'XAI_API_KEY' => '',
    ]
);
ox(is_resource($srv), 'curl-servern startade');
stream_set_blocking($pipes[2], false);
$heard = '';
$deadline = microtime(true) + 8;
while (microtime(true) < $deadline) {
    $heard .= (string) fread($pipes[2], 256);
    if (preg_match('/127\.0\.0\.1:(\d+)/', $heard, $m) === 1) {
        $port = (int) $m[1];
        break;
    }
    usleep(50000);
}
ox($port > 0, 'curl-servern lyssnar');
$cookie = rawurlencode((string) $_COOKIE['jeab_auth']);
$ch = curl_init('http://127.0.0.1:' . $port . '/?co=malmo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Cookie: jeab_auth=' . $cookie],
    CURLOPT_TIMEOUT => 15,
]);
$html = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
ox($code === 200 && preg_match('/class="chip is-on"[^>]*>Malmö</', $html) === 1 && preg_match('/class="chip"[^>]*>Järfälla</', $html) === 1, 'curl: Elin ser båda kontoren och Malmö är valt');
$ch = curl_init('http://127.0.0.1:' . $port . '/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Cookie: jeab_auth=' . $cookie],
    CURLOPT_TIMEOUT => 15,
]);
$home = (string) curl_exec($ch);
curl_close($ch);
ox(preg_match('/class="chip is-on"[^>]*>Järfälla</', $home) === 1 && preg_match('/class="chip is-on"[^>]*>Malmö</', $home) !== 1, 'curl: utan co= är Järfälla förvalet');
proc_terminate($srv);
proc_close($srv);

echo "ALL OK\n";
