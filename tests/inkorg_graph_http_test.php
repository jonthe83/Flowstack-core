<?php

declare(strict_types=1);

/**
 * Graph-sändning från en sidförfrågan.
 * GET blockeras. POST (svar) får anropa Graph.
 * Status 0 efter curl loggar errno, feltext och tid.
 * Ett svar som inte fick HTTP-svar skickas inte en gång till via sendMail,
 * och en misslyckad sändning förbrukar inte dedupe-nyckeln.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-graph-http-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/fellogg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => new stdClass()], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

$fail = 0;
function gh_ok(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_GET['format'], $_GET['ix_part'], $_GET['intent']);
gh_ok(jeab_page_blocks_external(), 'GET-sidvisning blockerar externa anrop');
$_SERVER['REQUEST_METHOD'] = 'HEAD';
gh_ok(jeab_page_blocks_external(), 'HEAD-sidvisning blockerar externa anrop');
$_SERVER['REQUEST_METHOD'] = 'POST';
gh_ok(!jeab_page_blocks_external(), 'POST från index.php får anropa Graph');
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
gh_ok(!jeab_page_blocks_external(), 'tillåten tick släpper igenom GET');
unset($GLOBALS['_JEAB_EXTERNAL_ALLOW']);
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
gh_ok(!jeab_page_blocks_external(), 'AJAX-GET blockerar inte');
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
gh_ok(!jeab_page_blocks_external(), 'CLI blockerar inte ens med sidflagga');
$GLOBALS['_JEAB_PAGE_REQUEST'] = false;
gh_ok(!jeab_page_blocks_external(), 'CLI utan sidflagga blockerar inte');

$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$stubHits = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$stubHits): array {
    $stubHits++;

    return ['code' => 202, 'body' => []];
};
$blocked = jeab_graph_http('POST', 'https://graph.microsoft.com/v1.0/users/x/sendMail', ['message' => []]);
gh_ok($stubHits === 0 && (int) ($blocked['code'] ?? -1) === 0 && !empty($blocked['blocked']) && empty($blocked['transport']), 'GET når inte curl eller stub');
$log = (string) @file_get_contents(jeab_app_errors_path());
gh_ok(str_contains($log, 'blocked=1') && str_contains($log, 'curl_errno=0') && str_contains($log, 'ms=0'), 'blockerat anrop loggas som status 0 utan curl');
gh_ok(!str_contains($log, 'client_secret') && !str_contains($log, 'Authorization'), 'loggen tar inte med hemligheter');

$_SERVER['REQUEST_METHOD'] = 'POST';
$sent = jeab_graph_http('POST', 'https://graph.microsoft.com/v1.0/users/x/sendMail', ['message' => []]);
gh_ok($stubHits === 1 && (int) ($sent['code'] ?? 0) === 202, 'POST når Graph-stubben');

unset($GLOBALS['_JEAB_GRAPH_HTTP'], $GLOBALS['_JEAB_PAGE_REQUEST']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$dead = jeab_graph_http('POST', 'http://127.0.0.1:1/', null);
$log = (string) @file_get_contents(jeab_app_errors_path());
gh_ok((int) ($dead['code'] ?? -1) === 0 && !empty($dead['transport']) && (int) ($dead['curl_errno'] ?? 0) > 0, 'nått värd ger status 0 med curl_errno, fick ' . (int) ($dead['curl_errno'] ?? 0));
gh_ok(str_contains($log, 'curl_errno=' . (int) $dead['curl_errno']) && str_contains($log, 'ms=') && str_contains($log, 'host=127.0.0.1'), 'riktig status 0 loggar errno, fel och tid');
gh_ok(!str_contains($log, 'Bearer') && !str_contains($log, 'client_secret'), 'transportloggen är utan token');

jeab_inkorg_sql_set_storage('sql');
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$calls): array {
    $calls[] = $url;
    if (str_ends_with($url, '/reply')) {
        return ['code' => 0, 'body' => [], 'transport' => true, 'curl_errno' => 28, 'curl_error' => 'Timeout was reached', 'ms' => 25001];
    }
    if (str_ends_with($url, '/sendMail')) {
        return ['code' => 202, 'body' => []];
    }

    return ['code' => 500, 'body' => []];
};
$ing = jeab_inkorg_ingest([
    'message_id' => '<graph-zero@example.test>',
    'from' => 'kund.zero@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Timeout',
    'body_text' => 'Hej.',
    'source' => 'graph-jarfalla',
    'graph_id' => 'GZ1',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'received_at' => '2020-01-04T10:00:00+01:00',
    'test' => true,
]);
gh_ok(!empty($ing['ok']), 'ärendet för timeout finns');
$tid = (string) ($ing['thread_id'] ?? '');
$threw = '';
try {
    jeab_inkorg_send_in_thread($tid, ['body' => 'Svar som inte fick svar från Graph.', 'send_key' => 'cc33'], 'Test');
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
$mailCalls = array_values(array_filter($calls, static fn(string $url): bool => str_ends_with($url, '/sendMail') || str_ends_with($url, '/reply')));
gh_ok(count($mailCalls) === 1 && str_ends_with($mailCalls[0], '/reply'), 'timeout på reply skickar inte sendMail, fick ' . count($mailCalls));
gh_ok(str_contains($threw, 'curl_errno=28') && str_contains($threw, 'ms=25001'), 'feltexten visar curl och tid');
gh_ok(!jeab_inkorg_send_seen($tid, jeab_inkorg_send_dedupe($tid, ['send_key' => 'cc33'], 'Svar som inte fick svar från Graph.', 'kund.zero@example.test')), 'misslyckad sändning förbrukar inte nyckeln');

$calls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$calls): array {
    $calls[] = $url;
    if (str_ends_with($url, '/reply')) {
        return ['code' => 404, 'body' => []];
    }
    if (str_ends_with($url, '/sendMail')) {
        return ['code' => 202, 'body' => []];
    }

    return ['code' => 500, 'body' => []];
};
$fallback = jeab_inkorg_send_in_thread($tid, ['body' => 'Svar via sendMail.', 'send_key' => 'dd44'], 'Test');
$mailCalls = array_values(array_filter($calls, static fn(string $url): bool => str_ends_with($url, '/sendMail') || str_ends_with($url, '/reply')));
gh_ok(count($mailCalls) === 2 && str_ends_with($mailCalls[1], '/sendMail') && ($fallback['state'] ?? '') === 'sent', 'avvisat reply faller tillbaka till ett sendMail');
$before = count($calls);
jeab_inkorg_send_in_thread($tid, ['body' => 'Svar via sendMail.', 'send_key' => 'dd44'], 'Test');
gh_ok(count($calls) === $before, 'samma nyckel efter lyckat skick anropar inte Graph igen');

echo $fail === 0 ? "klart\n" : "{$fail} fel\n";
exit($fail > 0 ? 1 : 0);
