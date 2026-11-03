<?php

declare(strict_types=1);

/**
 * Skickat-läsningen: HTTP 200 utan träff är ingen tidigare offert.
 * 200 med träff håller. nextLink följs även när value saknas.
 * 403 håller och loggas med Graph-koden, utan svarets text.
 * Påhittade adresser. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-sent-graph-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sg(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$rcpt = 'kund.sent.graf@example.com';
$hit = [
    'id' => 'MSG1',
    'subject' => 'Offert solceller',
    'toRecipients' => [['emailAddress' => ['address' => 'Kund.Sent.Graf@example.com']]],
    'ccRecipients' => [],
    'sentDateTime' => '2026-10-20T10:00:00Z',
    'hasAttachments' => true,
];
$context = "https://graph.microsoft.com/v1.0/\$metadata#users('demo@example.test')/mailFolders('sentitems')/messages";
$next = 'https://graph.microsoft.com/v1.0/users/demo@example.test/mailFolders/sentitems/messages?page-2';
$logPath = jeab_app_errors_path();

$ask = static function (array $bodies) use ($rcpt): array {
    $GLOBALS['_SENT_GRAPH_BODIES'] = $bodies;
    $GLOBALS['_SENT_GRAPH_URLS'] = [];
    $GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
        $GLOBALS['_SENT_GRAPH_URLS'][] = $url;
        $queue = $GLOBALS['_SENT_GRAPH_BODIES'];
        $row = array_shift($queue);
        $GLOBALS['_SENT_GRAPH_BODIES'] = $queue;
        if (!is_array($row)) {
            return ['code' => 500, 'body' => ['error' => ['code' => 'TestEmpty', 'message' => 'SECRET-FALLBACK']]];
        }

        return $row;
    };
    $page = jeab_auto_quote_sent_graph_pages(
        'users/' . rawurlencode('demo@example.test') . '/mailFolders/sentitems/messages?$top=50',
        $rcpt,
        true,
        true
    );
    unset($GLOBALS['_JEAB_GRAPH_HTTP']);

    return $page;
};

@unlink($logPath);
$emptyList = $ask([['code' => 200, 'body' => ['value' => []]]]);
sg(($emptyList['hit'] ?? true) === false && ($emptyList['why'] ?? 'x') === '' && ($emptyList['status'] ?? '') === 'ok', '200 med tom value är ingen tidigare offert');
sg(!is_file($logPath), 'tom 200 loggas inte som läsfel');

$emptyOdata = $ask([['code' => 200, 'body' => ['@odata.context' => $context, '@odata.count' => 0]]]);
sg(($emptyOdata['hit'] ?? true) === false && ($emptyOdata['why'] ?? 'x') === '' && ($emptyOdata['status'] ?? '') === 'ok', '200 utan value och med count 0 är ingen tidigare offert');

$bare = $ask([['code' => 200, 'body' => ['ok' => true]]]);
sg(($bare['hit'] ?? true) === false && ($bare['why'] ?? 'x') === '' && ($bare['status'] ?? '') === 'ok' && !str_contains((string) ($bare['why'] ?? ''), 'graph 200'), '200 utan value och utan @odata är ingen tidigare offert');

$onlyNext = $ask([
    ['code' => 200, 'body' => ['@odata.nextLink' => $next]],
    ['code' => 200, 'body' => ['value' => []]],
]);
sg(($onlyNext['hit'] ?? true) === false && ($onlyNext['why'] ?? 'x') === '', '200 med bara nextLink är ingen tidigare offert');

$emptyNull = $ask([['code' => 200, 'body' => ['@odata.context' => $context, 'value' => null]]]);
sg(($emptyNull['hit'] ?? true) === false && ($emptyNull['why'] ?? 'x') === '' && !str_contains((string) ($emptyNull['why'] ?? ''), 'graph 200'), '200 med value null är ingen tidigare offert');

$scanBodies = [['code' => 200, 'body' => ['@odata.context' => $context, '@odata.count' => 0]]];
$GLOBALS['_SENT_GRAPH_BODIES'] = $scanBodies;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    $queue = $GLOBALS['_SENT_GRAPH_BODIES'];
    $row = array_shift($queue);
    $GLOBALS['_SENT_GRAPH_BODIES'] = $queue;

    return is_array($row) ? $row : ['code' => 500, 'body' => []];
};
$scan = jeab_auto_graph_scan_load('demo@example.test');
unset($GLOBALS['_JEAB_GRAPH_HTTP']);
sg(($scan['why'] ?? 'x') === '' && ($scan['status'] ?? '') === 'ok' && ($scan['rows'] ?? null) === [], 'datumskanningens tomma 200 är ingen tidigare offert');

$found = $ask([['code' => 200, 'body' => ['value' => [$hit]]]]);
sg(!empty($found['hit']) && str_contains((string) ($found['why'] ?? ''), 'Skickat') && str_contains((string) ($found['why'] ?? ''), '2026-10-20'), '200 med träff är en tidigare offert');

$paged = $ask([
    ['code' => 200, 'body' => ['@odata.context' => $context, '@odata.nextLink' => $next]],
    ['code' => 200, 'body' => ['value' => [$hit]]],
]);
$sawNext = false;
foreach ($GLOBALS['_SENT_GRAPH_URLS'] as $url) {
    if (str_contains($url, 'page-2')) {
        $sawNext = true;
    }
}
sg($sawNext && !empty($paged['hit']) && str_contains((string) ($paged['why'] ?? ''), 'Skickat') && !str_contains((string) ($paged['why'] ?? ''), 'graph 200'), '200 med nextLink och utan value följs till träffen');

$emptyThenEnd = $ask([
    ['code' => 200, 'body' => ['value' => [], '@odata.nextLink' => $next]],
    ['code' => 200, 'body' => ['@odata.context' => $context, 'value' => []]],
]);
sg(($emptyThenEnd['hit'] ?? true) === false && ($emptyThenEnd['why'] ?? 'x') === '', '200 med nextLink och noll träffar är ingen tidigare offert');

@unlink($logPath);
$denied = $ask([['code' => 403, 'body' => ['error' => ['code' => 'ErrorAccessDenied', 'message' => 'SECRET-MAILBOX']]]]);
$logged = is_file($logPath) ? (string) file_get_contents($logPath) : '';
sg(
    ($denied['hit'] ?? true) === false
    && str_contains((string) ($denied['why'] ?? ''), 'kunde inte läsa Skickat (graph 403)')
    && str_contains($logged, 'Skickat gick inte att läsa: graph 403 ErrorAccessDenied')
    && !str_contains((string) ($denied['why'] ?? ''), 'SECRET')
    && !str_contains($logged, 'SECRET'),
    '403 håller och loggar koden utan svarets text'
);

@unlink($logPath);
$badJson = $ask([['code' => 200, 'body' => [], 'json_error' => 'Syntax error']]);
$logged = is_file($logPath) ? (string) file_get_contents($logPath) : '';
sg(
    str_contains((string) ($badJson['why'] ?? ''), 'kunde inte läsa Skickat (graph 200)')
    && str_contains($logged, 'ogiltig JSON')
    && !str_contains($logged, 'Syntax error SECRET'),
    'ogiltig JSON på 200 håller och loggas'
);

$mailboxUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$mailboxUrls): array {
    $mailboxUrls[] = $url;
    if (str_contains($url, 'mailbox-page-2')) {
        return ['code' => 200, 'body' => ['error' => ['code' => 'ErrorInternalServerError', 'message' => 'SECRET-MAILBOX']]];
    }

    return ['code' => 200, 'body' => [
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/demo@example.test/messages?mailbox-page-2',
    ]];
};
$mailbox = jeab_auto_quote_sent_graph_pages(
    'users/' . rawurlencode('demo@example.test') . '/messages?$search=' . rawurlencode('"to:' . $rcpt . '"'),
    $rcpt,
    false,
    true,
    'sent-folder-id'
);
$sawMailboxNext = false;
foreach ($mailboxUrls as $url) {
    if (str_contains($url, 'mailbox-page-2')) {
        $sawMailboxNext = true;
    }
}
sg(
    $sawMailboxNext
    && !empty($mailbox['unsupported'])
    && ($mailbox['hit'] ?? true) === false
    && ($mailbox['why'] ?? 'x') === ''
    && !str_contains((string) ($mailbox['why'] ?? ''), 'graph 200')
    && !str_contains(json_encode($mailbox), 'SECRET'),
    'oväntad 200 på users/messages hoppas över'
);
$sentHold = $ask([
    ['code' => 200, 'body' => ['@odata.nextLink' => $next]],
    ['code' => 200, 'body' => ['error' => ['code' => 'ErrorInternalServerError', 'message' => 'SECRET-SENT']]],
]);
sg(
    empty($sentHold['unsupported'])
    && str_contains((string) ($sentHold['why'] ?? ''), 'kunde inte läsa Skickat (graph 200)')
    && !str_contains((string) ($sentHold['why'] ?? ''), 'SECRET'),
    'oväntad 200 i Skickat håller fortfarande'
);

echo "OK\n";
