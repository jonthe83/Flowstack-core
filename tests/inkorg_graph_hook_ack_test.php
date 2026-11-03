<?php

declare(strict_types=1);

/** Graph-kroken: oläsbar kropp är 400 med last_hook, och kön skrivs före 202. */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-gha-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0700, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/m365.json', json_encode([
    'tenant_id' => 't',
    'client_id' => 'c',
    'client_secret' => 's',
    'client_state' => 'cs-ack-0123456789abcdef',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/inkorg/graph-state.json', json_encode([
    'subs' => ['jarfalla' => [
        'id' => 'sub-j',
        'expires' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * 86400),
        'notificationUrl' => 'https://flowstack.example.test/index.php?graph_hook=1',
    ]],
]));

mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';

function gha(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents($root . '/graph-hook.php');
$remember = strpos($hook, 'jeab_graph_hook_remember');
$enqueue = strpos($hook, 'jeab_graph_hook_enqueue');
$fail = strpos($hook, 'http_response_code(503)');
$finish = strpos($hook, 'fastcgi_finish_request');
gha($remember !== false && $enqueue !== false && $fail !== false && $finish !== false, 'kroken har remember, kö, 503 och kvittens');
gha($remember < $enqueue && $enqueue < $fail && $fail < $finish, 'kön och 503 ligger före 202');

$statusSrc = (string) file_get_contents($root . '/src/inkorg_graph.php');
$statusAt = strpos($statusSrc, 'function jeab_graph_hook_http_status');
$unreadableAt = strpos($statusSrc, "if (!empty(\$res['unreadable']))", $statusAt === false ? 0 : $statusAt);
$authAt = strpos($statusSrc, "if ((int) (\$res['auth_failed']", $statusAt === false ? 0 : $statusAt);
gha($statusAt !== false && $unreadableAt !== false && $authAt !== false && $unreadableAt < $authAt, 'oläsbar kropp prövas före 401');

$both = ['unreadable' => true, 'auth_failed' => 3, 'accepted' => 0, 'rejected' => 1];
gha(jeab_graph_hook_http_status($both) === 400, 'läsfel med auth_failed är 400');
gha(jeab_graph_hook_http_status(['auth_failed' => 1, 'accepted' => 0]) === 401, 'fel clientState är fortfarande 401');
gha(jeab_graph_hook_http_status(['accepted' => 1, 'jobs' => [['jarfalla', 'A1']]]) === 202, 'accepterad notis är 202');

jeab_graph_hook_remember(['unreadable' => true, 'accepted' => 0, 'rejected' => 0, 'jobs' => []]);
$stamped = jeab_graph_state()['last_hook'] ?? [];
gha(!empty($stamped['unreadable']) && (string) ($stamped['at'] ?? '') !== '' && (int) ($stamped['accepted'] ?? 1) === 0, 'remember stämplar last_hook utan notis');

$note = static function (string $id): string {
    return (string) json_encode([
        'value' => [[
            'clientState' => 'cs-ack-0123456789abcdef',
            'subscriptionId' => 'sub-j',
            'resourceData' => ['id' => $id],
        ]],
    ]);
};
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($tmp . '/public', true) . ";\nif (getenv('JEAB_GHA_NO_SQL') === '1') {\n    putenv('JEAB_DISABLE_SQLITE=1');\n    \$_ENV['JEAB_DISABLE_SQLITE'] = '1';\n    putenv('JEAB_ASSERT_SQL_DOWN=1');\n    \$_ENV['JEAB_ASSERT_SQL_DOWN'] = '1';\n}\nrequire " . var_export($root . '/index.php', true) . ";\n");

if (function_exists('jeab_stores_disconnect')) {
    jeab_stores_disconnect();
}
$withSql = gha_serve($root, $router, ['JEAB_GHA_NO_SQL' => '0', 'JEAB_DISABLE_SQLITE' => '0']);
gha($withSql['proc'] !== null, 'notis-servern lyssnar');
$stateFile = $tmp . '/inkorg/graph-state.json';
$stateBefore = (string) file_get_contents($stateFile);
$blank = gha_post($withSql['port'], '');
gha($blank['code'] === 400 && str_contains($blank['body'], '{"ok":false}'), 'tom kropp via kroken är 400, fick ' . $blank['code'] . ' ' . substr($blank['body'], 0, 80));
gha((string) file_get_contents($stateFile) === $stateBefore, 'kroken lämnar graph-state.json');
$blankPayload = '';
for ($try = 0; $try < 20; $try++) {
    try {
        $look = new PDO('sqlite:' . $tmp . '/app.sqlite');
        $blankPayload = (string) $look->query("SELECT payload FROM graph_state WHERE doc_key = 'graph_state'")->fetchColumn();
        $look = null;
        break;
    } catch (Throwable) {
        usleep(50000);
    }
}
$blankState = json_decode($blankPayload, true);
gha(is_array($blankState) && !empty($blankState['last_hook']['unreadable']), 'kroken stämplar last_hook i SQL på tom kropp');
$bad = gha_post($withSql['port'], '{');
gha($bad['code'] === 400 && $bad['code'] !== 401, 'trasig json är 400 och inte 401, fick ' . $bad['code']);
$up = gha_post($withSql['port'], $note('ACK-1'));
gha($up['code'] === 202 && str_contains($up['body'], '{"ok":true}'), 'sparad kö är 202, fick ' . $up['code'] . ' ' . substr($up['body'], 0, 80));
gha_stop($withSql);
$pdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
$queued = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'ACK-1'")->fetchColumn();
gha($queued === 1, '202 betyder att raden finns, fick ' . $queued);
$pdo = null;

$noSql = gha_serve($root, $router, ['JEAB_GHA_NO_SQL' => '1', 'JEAB_DISABLE_SQLITE' => '1', 'JEAB_ASSERT_SQL_DOWN' => '1']);
gha($noSql['proc'] !== null, 'servern utan sqlite lyssnar');
$down = gha_post($noSql['port'], $note('NOPE-1'));
gha($down['code'] === 503 && str_contains($down['body'], '{"ok":false}'), 'kö som inte kan skrivas är 503, fick ' . $down['code'] . ' ' . substr($down['body'], 0, 120));
gha_stop($noSql);
$pdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
$missed = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'NOPE-1'")->fetchColumn();
gha($missed === 0, '503 lämnade den notisen utanför kön, fick ' . $missed);
$kept = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'ACK-1'")->fetchColumn();
gha($kept === 1, 'en tidigare rad ligger kvar');

echo "graph hook ack: ok\n";

/**
 * @param array<string,string> $extra
 * @return array{proc:resource|null,port:int,pipes:array<int,resource>}
 */
function gha_serve(string $root, string $router, array $extra): array
{
    $port = 18080 + random_int(0, 800);
    $env = $extra;
    foreach (['PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR'] as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            $env[$key] = $value;
        }
    }
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env
    );
    $up = false;
    $deadline = microtime(true) + 5;
    while (is_resource($proc) && microtime(true) < $deadline) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($probe)) {
            fclose($probe);
            $up = true;
            break;
        }
        usleep(50000);
    }
    if (!$up) {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }

        return ['proc' => null, 'port' => $port, 'pipes' => []];
    }

    return ['proc' => $proc, 'port' => $port, 'pipes' => $pipes];
}

/** @param array{proc:resource|null,port:int,pipes:array<int,resource>} $server */
function gha_stop(array $server): void
{
    if (is_resource($server['proc'])) {
        $status = proc_get_status($server['proc']);
        if (!empty($status['pid'])) {
            proc_terminate($server['proc']);
        }
        foreach ($server['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($server['proc']);
    }
}

/** @return array{code:int,body:string} */
function gha_post(int $port, string $body): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . '/index.php?graph_hook=1');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($raw) ? $raw : ''];
}
