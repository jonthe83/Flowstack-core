<?php

declare(strict_types=1);

/** Svep på ärendekort: läst/oläst i SQL, stäng och ångra, tröskel. Inget utskick. */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixswipe-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'swipe-test-secret',
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
], true) . ";\n");
file_put_contents($tmp . '/inkorg/settings.json', json_encode([
    'helpscout_companies' => [],
    'send_enabled' => false,
], JSON_UNESCAPED_UNICODE));
require $root . '/src/bootstrap.php';

function sw(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SWIPE_SENDS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $mail): array {
    $GLOBALS['_SWIPE_SENDS'][] = $mail;
    return ['ok' => false, 'error' => 'testet skickar inte'];
};

sw(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'inkorgen kör sql');
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
sw(jeab_auth_ok() && jeab_inkorg_me_id() === 'SamL', 'Sam L är inloggad');

$mail = function (string $id, string $company, string $to): array {
    return [
        'message_id' => '<' . $id . '@swipe.test>',
        'from' => $id . '@example.test',
        'from_name' => 'Kund ' . $id,
        'to' => $to,
        'subject' => 'Ärende ' . $id,
        'body_text' => 'Hej, syntetiskt ärende.',
        'source' => 'graph-' . $company,
        'company' => $company,
        'test' => true,
    ];
};
$jar = jeab_inkorg_ingest($mail('jarfalla', 'jarfalla', 'kontakt@jarfalla.example.test'));
$mal = jeab_inkorg_ingest($mail('malmo', 'malmo', 'kontakt@malmo.example.test'));
sw(!empty($jar['ok']) && !empty($mal['ok']), 'Järfälla och Malmö tas in');
$jarId = (string) $jar['thread_id'];
$malId = (string) $mal['thread_id'];
$threads = array_column(jeab_inkorg_threads(), null, 'id');
sw(($threads[$jarId]['company'] ?? '') === 'jarfalla' && ($threads[$malId]['company'] ?? '') === 'malmo', 'bolaget sitter på tråden');
sw(jeab_inkorg_is_unread($threads[$jarId], 'SamL') && jeab_inkorg_is_unread($threads[$malId], 'SamL'), 'nya ärenden är olästa');

$beforeMessages = count(jeab_inkorg_messages());
$beforeSends = count($GLOBALS['_SWIPE_SENDS']);

foreach (['jarfalla' => $jarId, 'malmo' => $malId] as $label => $tid) {
    $_POST = ['thread' => $tid, 'read' => '1'];
    $flash = jeab_inkorg_action('inkorg.read');
    sw($flash === 'Markerat som läst.', $label . ' läst via endpoint');
    $threads = array_column(jeab_inkorg_threads(), null, 'id');
    sw(!jeab_inkorg_is_unread($threads[$tid], 'SamL'), $label . ' är läst');
    $pdo = jeab_inkorg_store_pdo();
    sw($pdo instanceof PDO, 'sql-handtag finns');
    $stmt = $pdo->prepare('SELECT status, row_json FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$tid]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    $json = json_decode((string) ($db['row_json'] ?? ''), true);
    sw(is_array($db) && is_array($json) && ($json['read']['SamL'] ?? '') !== '', $label . ' läststatus ligger i SQL-raden');
    sw(!is_file($tmp . '/inkorg/threads.json'), $label . ' skrev ingen threads.json');
    $_POST = ['thread' => $tid, 'read' => '0'];
    sw(jeab_inkorg_action('inkorg.read') === 'Markerat som oläst.', $label . ' oläst via endpoint');
    $threads = array_column(jeab_inkorg_threads(), null, 'id');
    sw(jeab_inkorg_is_unread($threads[$tid], 'SamL'), $label . ' är oläst igen');
    $stmt->execute([$tid]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    $json = json_decode((string) ($db['row_json'] ?? ''), true);
    sw(is_array($json) && !isset($json['read']['SamL']), $label . ' oläst tog bort fältet i SQL');
}

foreach (['jarfalla' => $jarId, 'malmo' => $malId] as $label => $tid) {
    $threads = array_column(jeab_inkorg_threads(), null, 'id');
    $statusBefore = (string) ($threads[$tid]['status'] ?? '');
    $tagsBefore = (array) ($threads[$tid]['tags'] ?? []);
    $_POST = ['thread' => $tid, 'status' => 'closed'];
    sw(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', $label . ' stängs med samma endpoint som trådvyn');
    $pdo = jeab_inkorg_store_pdo();
    $stmt = $pdo->prepare('SELECT status, row_json FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$tid]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    $json = json_decode((string) ($db['row_json'] ?? ''), true);
    sw((string) ($db['status'] ?? '') === 'closed' && (string) ($json['status'] ?? '') === 'closed', $label . ' är stängd i SQL');
    $_POST = ['thread' => $tid, 'status' => $statusBefore !== '' ? $statusBefore : 'open'];
    sw(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', $label . ' ångra öppnar igen');
    $stmt->execute([$tid]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    $json = json_decode((string) ($db['row_json'] ?? ''), true);
    $threads = array_column(jeab_inkorg_threads(), null, 'id');
    sw((string) ($db['status'] ?? '') === 'open' && (string) ($threads[$tid]['status'] ?? '') === 'open', $label . ' är öppen igen');
    sw((array) ($json['tags'] ?? []) === $tagsBefore, $label . ' behåller etiketterna');
}

sw(count(jeab_inkorg_messages()) === $beforeMessages, 'stäng och ångra lägger inga mejl');
sw(count($GLOBALS['_SWIPE_SENDS']) === $beforeSends, 'inget utskick');

file_put_contents($tmp . '/inkorg/settings.json', json_encode([
    'helpscout_companies' => ['malmo'],
    'send_enabled' => false,
], JSON_UNESCAPED_UNICODE));
$locked = null;
try {
    $_POST = ['thread' => $malId, 'status' => 'closed'];
    jeab_inkorg_action('inkorg.thread');
} catch (Throwable $e) {
    $locked = $e;
}
sw($locked instanceof JeabInkorgWriteException, 'låst Malmö stängs inte');
$threads = array_column(jeab_inkorg_threads(), null, 'id');
sw((string) ($threads[$malId]['status'] ?? '') === 'open', 'låst Malmö är kvar öppen');
$_POST = ['thread' => $malId, 'read' => '1'];
sw(jeab_inkorg_action('inkorg.read') === 'Markerat som läst.', 'läst fungerar även när Malmö är låst');
file_put_contents($tmp . '/inkorg/settings.json', json_encode([
    'helpscout_companies' => [],
    'send_enabled' => false,
], JSON_UNESCAPED_UNICODE));

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-oppna'];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$jarList = jeab_inkorg_fast_prepare(true);
$_GET = ['p' => 'inkorg', 'box' => 'malmo', 'view' => 'v-oppna'];
$_SESSION['jeab_inkorg_box'] = 'malmo';
$malList = jeab_inkorg_fast_prepare(true);
foreach (['jarfalla' => [$jarList, $jarId], 'malmo' => [$malList, $malId]] as $label => $pair) {
    $html = (string) $pair[0]['html'];
    sw(str_contains($html, 'data-ix-id="' . $pair[1] . '"'), $label . ' kortet har tråd-id');
    sw(str_contains($html, 'ix-under-close">Stäng<') && str_contains($html, 'data-ix-act="close"'), $label . ' visar Stäng');
    sw(str_contains($html, 'data-ix-act="read"') && str_contains($html, 'class="ix-dot"'), $label . ' har läsknapp och prick');
    sw(str_contains($html, 'class="item-main'), $label . ' tryck öppnar fortfarande tråden');
}

$js = (string) file_get_contents($root . '/assets/js/inkorg-swipe.js');
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$layout = (string) file_get_contents($root . '/src/layout.php');
sw(str_contains($js, 'pointerdown') && str_contains($js, 'pointermove') && str_contains($js, 'pointerup'), 'pointer events');
sw(str_contains($js, 'inkorg.thread') && str_contains($js, "status: 'closed'") && str_contains($js, 'inkorg.read'), 'stäng och läst använder post');
sw(str_contains($js, 'Stängt · ') && str_contains($js, 'Ångra') && str_contains($js, '5000'), 'toast Stängt · Ångra i 5 sekunder');
sw(str_contains($js, "key === 'e'") && str_contains($js, "key === 'u'") && str_contains($js, 'toLowerCase()'), 'e stänger och u växlar läst');
sw(str_contains($css, 'touch-action: pan-y') && str_contains($css, '#c23b30') && str_contains($css, '#2563eb'), 'pan-y, röd stäng och blå läst');
sw(str_contains($page, 'data-ix-post') && str_contains($layout, 'inkorg-swipe.js'), 'listan pekar på scriptet');
sw(str_contains($js, 'jeabInkorgSwipeBind') && str_contains($page, 'jeabInkorgSwipeBind'), 'svepet binds om efter att listan bytts');
$tailAt = strpos($css, '/* Vykort under 640px.');
$tail = $tailAt === false ? '' : substr($css, $tailAt);
sw($tailAt !== false && substr_count($tail, '@media (max-width: 640px)') === 1 && str_contains($tail, 'min-width: max-content') && str_contains($tail, 'flex-wrap: nowrap') && str_ends_with(rtrim($tail), '}'), 'vykorten under 640px är en rad med hela namnet');

$node = proc_open(
    ['node', '-e', <<<'JS'
const { jeabInkorgSwipeDecide: d } = require(process.env.JEAB_SWIPE_JS);
function check(dx, dy, w, locked, expLocked, expCommit) {
  const r = d(dx, dy, w, locked);
  if (r.locked !== expLocked || r.commit !== expCommit) {
    console.error(JSON.stringify({ dx, dy, w, locked, r, expLocked, expCommit }));
    process.exit(1);
  }
}
check(4, 3, 200, '', '', '');
check(9, 9, 200, '', '', '');
check(3, 11, 200, '', 'scroll', '');
check(80, 11, 200, 'scroll', 'scroll', '');
check(12, 4, 200, '', 'x', '');
check(69, 0, 200, 'x', 'x', '');
check(70, 0, 200, 'x', 'x', 'read');
check(-69, 0, 200, 'x', 'x', '');
check(-70, 40, 200, 'x', 'x', 'close');
check(11, 10, 320, '', 'x', '');
const rel = require(process.env.JEAB_SWIPE_JS).jeabInkorgSwipeRelease;
function tap(dx, dy, w, open, commit) {
  const r = rel(dx, dy, w);
  if (r.open !== open || r.commit !== commit) {
    console.error(JSON.stringify({ dx, dy, w, r, open, commit }));
    process.exit(1);
  }
}
tap(0, 0, 320, true, '');
tap(4, 3, 320, true, '');
tap(9, 2, 320, true, '');
tap(12, 4, 320, true, '');
tap(70, 0, 200, false, 'read');
tap(-70, 10, 200, false, 'close');
tap(3, 20, 320, true, '');
JS],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $nodePipes,
    null,
    ['JEAB_SWIPE_JS' => $root . '/assets/js/inkorg-swipe.js']
);
$nodeOut = stream_get_contents($nodePipes[1]);
$nodeErr = stream_get_contents($nodePipes[2]);
fclose($nodePipes[1]);
fclose($nodePipes[2]);
sw(proc_close($node) === 0, 'tröskel 35% och riktning på 10 px' . ($nodeErr !== '' ? ' ' . trim($nodeErr) : '') . ($nodeOut !== '' ? ' ' . trim($nodeOut) : ''));

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_SWIPE_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_SWIPE_DATA');
require getenv('JEAB_ROOT') . '/index.php';
PHP);
$port = 0;
for ($try = 8761; $try < 8780; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
sw($port > 0, 'en lokal port är ledig');
$log = $tmp . '/server.log';
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $serverPipes,
    null,
    [
        'JEAB_SWIPE_DATA' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '0',
        'XAI_API_KEY' => '',
    ]
);
sw(is_resource($server), 'php-servern startade');
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
sw($ready, 'servern svarar');
$cookie = (string) $_COOKIE['jeab_auth'];
$csrf = (string) jeab_csrf_token();
$base = 'http://127.0.0.1:' . $port . '/index.php?p=inkorg';

$curlJson = function (array $post, array $headers) use ($base): array {
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
};

$sqlNow = static function (string $id) use ($tmp): array {
    $pdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
    $stmt = $pdo->prepare('SELECT status, row_json FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$id]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    $json = json_decode((string) ($db['row_json'] ?? ''), true);
    return ['status' => (string) ($db['status'] ?? ''), 'row' => is_array($json) ? $json : []];
};

$anon = $curlJson(['action' => 'inkorg.read', 'thread' => $jarId, 'read' => '1'], ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$anonRow = $sqlNow($jarId);
sw($anon['code'] === 401 && ($anon['json']['ok'] ?? null) === false && !isset($anonRow['row']['read']['SamL']), 'utan kaka ändras inte läst, fick ' . $anon['code'] . ' ' . substr($anon['body'], 0, 120));

$noCsrf = $curlJson(
    ['action' => 'inkorg.thread', 'thread' => $jarId, 'status' => 'closed'],
    ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Cookie: jeab_auth=' . $cookie]
);
sw($noCsrf['code'] === 419 && $sqlNow($jarId)['status'] === 'open', 'utan CSRF stängs inget, fick ' . $noCsrf['code']);

$read = $curlJson(
    ['action' => 'inkorg.read', 'thread' => $jarId, 'read' => '1', '_csrf' => $csrf],
    ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Cookie: jeab_auth=' . $cookie]
);
$readRow = $sqlNow($jarId);
sw($read['code'] === 200 && ($read['json']['ok'] ?? false) === true && ($readRow['row']['read']['SamL'] ?? '') !== '' && !jeab_inkorg_is_unread($readRow['row'], 'SamL'), 'curl markerar läst, fick ' . $read['code'] . ' ' . substr($read['body'], 0, 180));

$close = $curlJson(
    ['action' => 'inkorg.thread', 'thread' => $jarId, 'status' => 'closed', '_csrf' => $csrf],
    ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Cookie: jeab_auth=' . $cookie]
);
sw($close['code'] === 200 && ($close['json']['ok'] ?? false) === true && $sqlNow($jarId)['status'] === 'closed', 'curl stänger, fick ' . $close['code'] . ' ' . substr($close['body'], 0, 180));
$msgPdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
$msgCount = (int) $msgPdo->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn();
sw($msgCount === $beforeMessages && count($GLOBALS['_SWIPE_SENDS']) === $beforeSends, 'curl-stäng skickar inget');

$undo = $curlJson(
    ['action' => 'inkorg.thread', 'thread' => $jarId, 'status' => 'open', '_csrf' => $csrf],
    ['X-Requested-With: XMLHttpRequest', 'Accept: application/json', 'Cookie: jeab_auth=' . $cookie]
);
sw($undo['code'] === 200 && $sqlNow($jarId)['status'] === 'open', 'curl ångra återställer tråden');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "OK  kortsvep\n";
