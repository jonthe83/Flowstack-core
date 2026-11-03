<?php

declare(strict_types=1);

/** H-GRAPH-1: Graph kvitteras före kön, och en rad tas bort först när intaget är ok. */

$tmp = sys_get_temp_dir() . '/jeab-gh-retry-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ghr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents(dirname(__DIR__) . '/graph-hook.php');
$remember = strpos($hook, 'jeab_graph_hook_remember');
$finish = strpos($hook, 'fastcgi_finish_request');
$enqueue = strpos($hook, 'jeab_graph_hook_enqueue');
ghr($remember !== false && $enqueue !== false && $remember < $enqueue, 'last_hook skrivs före kön');
ghr($finish !== false && $enqueue !== false && $enqueue < $finish, 'kön skrivs före 202');
$fail = strpos($hook, 'http_response_code(503)');
ghr($fail !== false && $enqueue < $fail && $fail < $finish, 'misslyckad kö är 503 och avslutar före kvittensen');
ghr(strpos($hook, 'DELETE FROM graph_hook_queue') === false, 'kroken tar inte bort könraden');
$drain = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_graph.php');
$okAt = strpos($drain, "if (!empty(\$r['ok']))");
$execAt = strpos($drain, '$del->execute');
ghr($okAt !== false && $execAt !== false && $okAt < $execAt && $execAt - $okAt < 80, 'raden tas bort först när intaget är ok');

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$stored = jeab_graph_hook_enqueue([['jarfalla', 'FAIL-1'], ['jarfalla', 'GONE-1']]);
ghr($stored === true, 'kön tar emot jobben');
$pdo = jeab_store()->pdo();
ghr($pdo instanceof PDO, 'sqlite är öppen');
$cols = array_column($pdo->query('PRAGMA table_info(graph_hook_queue)')->fetchAll(PDO::FETCH_ASSOC), 'name');
ghr(in_array('attempts', $cols, true), 'kön har attempts');

$calls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$calls): array {
    unset($method, $body, $headers);
    $calls++;
    if (str_contains($url, 'FAIL-1')) {
        return ['code' => 500, 'body' => ['error' => ['message' => 'tillfälligt']]];
    }
    if (str_contains($url, 'GONE-1')) {
        return ['code' => 404, 'body' => []];
    }
    if (str_contains($url, 'OK-1')) {
        return ['code' => 200, 'body' => [
            'id' => 'OK-1',
            'internetMessageId' => '<ok-1@kund.test>',
            'conversationId' => 'C-OK-1',
            'from' => ['emailAddress' => ['address' => 'ok1@exempel.test', 'name' => 'Ok']],
            'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
            'subject' => 'Hej',
            'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z'),
            'body' => ['content' => 'Hej'],
            'isDraft' => false,
        ]];
    }

    return ['code' => 500, 'body' => ['error' => ['message' => 'oväntat']]];
};

$done = jeab_graph_hook_drain();
$leftFail = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'FAIL-1'")->fetchColumn();
$attempts = (int) $pdo->query("SELECT attempts FROM graph_hook_queue WHERE message_id = 'FAIL-1'")->fetchColumn();
$leftGone = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'GONE-1'")->fetchColumn();
ghr($leftFail === 1 && $attempts === 1, 'ett Graph-fel lämnar raden och räknar försöket, fick ' . $leftFail . '/' . $attempts);
ghr($leftGone === 0 && $done === 1, 'borta-mejlet (404) lämnar kön');

$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []): array {
    unset($method, $body, $headers);
    if (str_contains($url, 'FAIL-1')) {
        return ['code' => 200, 'body' => [
            'id' => 'FAIL-1',
            'internetMessageId' => '<fail-1@kund.test>',
            'conversationId' => 'C-FAIL-1',
            'from' => ['emailAddress' => ['address' => 'fail1@exempel.test', 'name' => 'Fail']],
            'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
            'subject' => 'Hej igen',
            'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z'),
            'body' => ['content' => 'Hej igen'],
            'isDraft' => false,
        ]];
    }

    return ['code' => 500, 'body' => []];
};
$again = jeab_graph_hook_drain();
$leftAfter = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'FAIL-1'")->fetchColumn();
$saved = false;
foreach (jeab_inkorg_messages() as $m) {
    if ((string) ($m['graph_id'] ?? '') === 'FAIL-1') {
        $saved = true;
    }
}
ghr($again === 1 && $leftAfter === 0 && $saved, 'lyckat intag tar bort raden');

jeab_graph_hook_enqueue([['jarfalla', 'CAP-1']]);
$capKey = jeab_graph_hook_queue_key('jarfalla', 'CAP-1');
$pdo->prepare('UPDATE graph_hook_queue SET attempts = ? WHERE idem_key = ?')->execute([JEAB_GRAPH_HOOK_ATTEMPTS, $capKey]);
$capCalls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$capCalls): array {
    unset($method, $body, $headers);
    $capCalls++;

    return ['code' => 500, 'body' => []];
};
$capped = jeab_graph_hook_drain();
$capLeft = (int) $pdo->query("SELECT COUNT(*) FROM graph_hook_queue WHERE message_id = 'CAP-1'")->fetchColumn();
ghr($capped === 0 && $capLeft === 1 && $capCalls === 0, 'försökstaket hämtar inte mejlet igen och raden ligger kvar');

echo "graph hook retry: ok\n";
