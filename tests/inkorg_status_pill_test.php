<?php

declare(strict_types=1);

/**
 * Malmö box=malmo (Sol, Help Scout) använder samma statusmeny som Järfälla.
 * status_ui sparar Öppen, Parkerad och Stängd. Inget mejl och ingen offert.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixpill-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'pill-test-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'people' => [
        ['id' => 'SamW', 'email' => 'sam.w@example.test', 'name' => 'Sam W', 'company' => 'malmo'],
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/settings.json', json_encode([
    'helpscout_companies' => [],
    'send_enabled' => false,
], JSON_UNESCAPED_UNICODE));
require $root . '/src/bootstrap.php';
require_once $root . '/src/layout.php';

function pill(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_PILL_SENDS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $mail): array {
    $GLOBALS['_PILL_SENDS'][] = $mail;
    return ['ok' => false, 'error' => 'testet skickar inte'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 404, 'body' => []];

$sqlOn = jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql';
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
pill($sqlOn, 'inkorgen kör sql');
pill(jeab_auth_ok(), 'Sam L är inloggad');
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

$take = static function (string $id, string $company, string $to, string $subject): string {
    $row = jeab_inkorg_ingest([
        'message_id' => '<' . $id . '@pill.test>',
        'from' => $id . '@example.test',
        'from_name' => 'Kund ' . $id,
        'to' => $to,
        'subject' => $subject,
        'body_text' => 'Hej, vi vill ha solceller.',
        'source' => 'graph-' . $company,
        'company' => $company,
        'test' => true,
    ]);
    pill(!empty($row['ok']), $company . ' tas in');

    return (string) $row['thread_id'];
};
$jarId = $take('jarfalla', 'jarfalla', 'kontakt@jarfalla.example.test', 'Solceller på garaget');
$malId = $take('malmo', 'malmo', 'kontakt@malmo.example.test', 'Soltaket i Malmö');
jeab_inkorg_thread_set($malId, 'open', ['sol'], false);
jeab_inkorg_assign($malId, 'SamW', false);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => ['malmo']]);
$meta = jeab_inkorg_thread_meta($malId);
pill((string) ($meta['assignee'] ?? '') === 'SamW' && in_array('sol', (array) ($meta['tags'] ?? []), true), 'Malmö är tilldelad Sam W och märkt sol');

$beforeMessages = count(jeab_inkorg_messages());
$beforeSends = count($GLOBALS['_PILL_SENDS']);

$menuOf = static function (string $html): string {
    if (preg_match('/<div class="ix-menu" id="ix-st" hidden>(.*?)<\/div>/s', $html, $match) !== 1) {
        return '';
    }

    return preg_replace('/ checked/', '', $match[1]) ?? '';
};

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $jarId];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$jarHtml = (string) include $root . '/src/pages/inkorg.php';
$_GET = ['p' => 'inkorg', 'box' => 'malmo', 'id' => $malId];
$_SESSION['jeab_inkorg_box'] = 'malmo';
$moHtml = (string) include $root . '/src/pages/inkorg.php';
$jarMenu = $menuOf($jarHtml);
$moMenu = $menuOf($moHtml);
pill(jeab_inkorg_box() === 'malmo' && jeab_inkorg_box_readonly(), 'Malmö-vyn är skrivskyddad');
pill(str_contains($moHtml, 'Sol hanteras i Help Scout.') && str_contains($moHtml, 'Sam W'), 'bannern och tilldelningen syns');
pill(preg_match('/<button[^>]*data-status-btn/', $moHtml) === 1 && !str_contains($moHtml, '<span class="ix-chip ix-pill'), 'statusbrickan är menyns knapp');
pill($moMenu !== '' && $moMenu === $jarMenu && str_contains($moMenu, 'value="open"') && str_contains($moMenu, 'value="parked"') && str_contains($moMenu, 'value="closed"'), 'Malmö har samma Öppen, Parkerad och Stängd');
pill(str_contains($moHtml, 'name="status_ui"') && !str_contains($moHtml, 'name="assignee"') && !str_contains($moHtml, 'id="ix-composer"') && !str_contains($moHtml, 'inkorg.trash'), 'svaret och tilldelningen är fortfarande av');

$locked = null;
try {
    jeab_inkorg_thread_set($malId, 'closed', null);
} catch (Throwable $e) {
    $locked = $e;
}
pill($locked instanceof JeabInkorgWriteException && (string) (jeab_inkorg_thread_meta($malId)['status'] ?? '') === 'open', 'direkt skrivning är fortfarande 403');

$_POST = ['thread' => $malId, 'status' => 'closed'];
$listErr = null;
try {
    jeab_inkorg_action('inkorg.thread');
} catch (Throwable $e) {
    $listErr = $e;
}
pill($listErr instanceof JeabInkorgWriteException, 'listans stäng utan statusmeny skriver inte');

$_POST = ['thread' => $malId, 'status' => 'closed', 'status_ui' => '1', 'tags_present' => '1', 'tags' => ['batteri']];
$tagErr = null;
try {
    jeab_inkorg_action('inkorg.thread');
} catch (Throwable $e) {
    $tagErr = $e;
}
pill($tagErr instanceof JeabInkorgWriteException && (array) (jeab_inkorg_thread_meta($malId)['tags'] ?? []) === ['sol'], 'etiketter följer inte med statusen');

$_POST = ['thread' => $malId, 'status' => 'closed', 'status_ui' => '1'];
pill(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'menyn stänger');
pill((string) (jeab_inkorg_thread_meta($malId)['status'] ?? '') === 'closed', 'Malmö är stängd');
pill((string) (jeab_inkorg_thread_meta($malId)['assignee'] ?? '') === 'SamW', 'Sam W ligger kvar');

$_POST = ['thread' => $malId, 'status' => 'parked', 'status_ui' => '1', 'park_until' => '2099-05-01'];
pill(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'menyn parkerar');
$parked = jeab_inkorg_thread_meta($malId);
pill((string) ($parked['status'] ?? '') === 'waiting_supplier' && (string) ($parked['park_until'] ?? '') === '2099-05-01', 'Parkerad lagras med datum');

$_POST = ['thread' => $malId, 'status' => 'open', 'status_ui' => '1'];
pill(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'menyn öppnar igen');
$open = jeab_inkorg_thread_meta($malId);
pill((string) ($open['status'] ?? '') === 'open' && (string) ($open['park_until'] ?? '') === '', 'Malmö är öppen utan parkering');
pill((array) ($open['tags'] ?? []) === ['sol'] && !isset($open['quote_sent']), 'sol-etiketten ligger kvar och ingen offert markeras');

$trashErr = null;
try {
    $_POST = ['thread' => $malId];
    jeab_inkorg_action('inkorg.trash');
} catch (Throwable $e) {
    $trashErr = $e;
}
pill($trashErr instanceof JeabInkorgWriteException, 'Malmö slängs inte');
pill(count(jeab_inkorg_messages()) === $beforeMessages && count($GLOBALS['_PILL_SENDS']) === $beforeSends, 'statusen skickar inget');

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_PILL_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_PILL_DATA');
require getenv('JEAB_ROOT') . '/index.php';
PHP);
$port = 0;
for ($try = 8791; $try < 8810; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
pill($port > 0, 'en lokal port är ledig');
$log = $tmp . '/server.log';
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $serverPipes,
    null,
    [
        'JEAB_PILL_DATA' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '0',
        'XAI_API_KEY' => '',
    ]
);
pill(is_resource($server), 'php-servern startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if (is_string($probe) && $probe !== '') {
        $ready = true;
        break;
    }
    usleep(100000);
}
pill($ready, 'servern svarar');
$cookie = (string) $_COOKIE['jeab_auth'];
$csrf = (string) jeab_csrf_token();
$base = 'http://127.0.0.1:' . $port . '/index.php?p=inkorg';
$curlJson = static function (array $post) use ($base, $cookie): array {
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Cookie: jeab_auth=' . $cookie],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);

    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
};
$sqlStatus = static function () use ($tmp, $malId): string {
    $pdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
    $stmt = $pdo->prepare('SELECT status FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$malId]);

    return (string) $stmt->fetchColumn();
};
$noCsrf = $curlJson(['action' => 'inkorg.thread', 'thread' => $malId, 'status' => 'closed', 'status_ui' => '1']);
pill($noCsrf['code'] === 419 && $sqlStatus() === 'open', 'utan CSRF stängs Malmö inte, fick ' . $noCsrf['code']);
$close = $curlJson(['action' => 'inkorg.thread', 'thread' => $malId, 'status' => 'closed', 'status_ui' => '1', '_csrf' => $csrf]);
pill($close['code'] === 200 && ($close['json']['ok'] ?? false) === true && $sqlStatus() === 'closed', 'curl stänger via statusmenyn, fick ' . $close['code'] . ' ' . substr($close['body'], 0, 180));
$reopen = $curlJson(['action' => 'inkorg.thread', 'thread' => $malId, 'status' => 'open', 'status_ui' => '1', '_csrf' => $csrf]);
pill($reopen['code'] === 200 && $sqlStatus() === 'open', 'curl öppnar via statusmenyn, fick ' . $reopen['code'] . ' ' . substr($reopen['body'], 0, 180));
pill(count($GLOBALS['_PILL_SENDS']) === $beforeSends, 'curl skickar inget');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "OK  statusmeny\n";
