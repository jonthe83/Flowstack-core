<?php

declare(strict_types=1);

/**
 * tick.php anropas med GET. Token-POST mot login.microsoftonline.com
 * ska nå mocken. En inloggad HTML-sidvisning ska fortfarande blockeras.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-tick-graph-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/fellogg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
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

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/tick.php';
$_GET = [];
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE']);
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';

require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';

$fail = 0;
function tg_ok(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

tg_ok(!jeab_page_blocks_external(), 'tick-GET utan sidflagga blockerar inte');

jeab_graph_secret_save(['tenant_id' => 'tenant', 'client_id' => 'client', 'client_secret' => 'secret']);
$calls = [];
$GLOBALS['_JEAB_GRAPH_TOKEN_VIA_HTTP'] = true;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$calls): array {
    $calls[] = strtoupper($method) . ' ' . $url;
    if (str_contains($url, 'login.microsoftonline.com')) {
        return ['code' => 200, 'body' => ['access_token' => 'tick-token', 'expires_in' => 3600]];
    }

    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['_JEAB_TICK_GUARD'] = static fn (): array => ['ok' => true, 'count' => 0];
$GLOBALS['_JEAB_TICK_MALMO'] = static fn (): array => ['ok' => true, 'count' => 0];

$ran = jeab_tick_run();
$tokenHits = 0;
foreach ($calls as $line) {
    if (str_starts_with($line, 'POST ') && str_contains($line, 'login.microsoftonline.com') && str_contains($line, '/oauth2/v2.0/token')) {
        $tokenHits++;
    }
}
tg_ok($tokenHits >= 1, 'tick-GET gör Graph-tokenanrop, fick ' . $tokenHits);
tg_ok(empty($ran['busy']), 'tick-körningen tog låset');
$log = (string) @file_get_contents(jeab_app_errors_path());
tg_ok(!str_contains($log, 'blocked=1'), 'tick-GET loggar inte blocked=1');
tg_ok(empty($GLOBALS['_JEAB_TICK']) && !empty($GLOBALS['_JEAB_EXTERNAL_ALLOW']), 'tick-körningen återställer sin flagga och behåller ingångens');

unset($GLOBALS['_JEAB_EXTERNAL_ALLOW'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_GRAPH_HTTP'], $GLOBALS['_JEAB_GRAPH_TOKEN_VIA_HTTP']);
unset($GLOBALS['_JEAB_TICK_GUARD'], $GLOBALS['_JEAB_TICK_MALMO']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'inkorg'];
$before = count($calls);
tg_ok(jeab_page_blocks_external(), 'inloggad HTML-sidvisning blockerar');
$blocked = jeab_graph_http('POST', 'https://login.microsoftonline.com/tenant/oauth2/v2.0/token', null);
tg_ok(count($calls) === $before && (int) ($blocked['code'] ?? -1) === 0 && !empty($blocked['blocked']), 'sidvisningens tokenanrop når inte mocken');

$GLOBALS['_JEAB_SAPI'] = 'cli';
tg_ok(!jeab_page_blocks_external(), 'CLI blockerar inte');
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
tg_ok(!jeab_page_blocks_external(), 'AJAX blockerar inte');
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread'];
tg_ok(!jeab_page_blocks_external(), 'inkorgens delanrop blockerar inte');

$tickSrc = (string) file_get_contents($root . '/tick.php');
tg_ok(str_contains($tickSrc, "\$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;"), 'tick.php sätter undantag före bootstrap');
$indexSrc = (string) file_get_contents($root . '/index.php');
tg_ok(str_contains($indexSrc, 'jeab_mark_html_page_request();'), 'index.php märker bara HTML-sidan');
tg_ok(!preg_match('/_JEAB_PAGE_REQUEST\'\] = true;/', $indexSrc), 'index.php sätter inte sidflaggan ovillkorligt');

foreach (['rot/index.php', 'bokning/rot/index.php'] as $rotRel) {
    $rotSrc = (string) file_get_contents($root . '/' . $rotRel);
    tg_ok(!str_contains($rotSrc, '_JEAB_PAGE_REQUEST') && !str_contains($rotSrc, '_JEAB_EXTERNAL_ALLOW') && !str_contains($rotSrc, 'jeab_mark_html_page_request'), $rotRel . ' sätter ingen sidflagga');
}
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_EXTERNAL_ALLOW'], $GLOBALS['_JEAB_TICK']);
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
$rotCalls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$rotCalls): array {
    $rotCalls[] = strtoupper($method) . ' ' . $url;

    return ['code' => 200, 'body' => ['access_token' => 'rot-token']];
};
foreach (['/rot/index.php', '/bokning/rot/index.php'] as $rotScript) {
    $_SERVER['SCRIPT_NAME'] = $rotScript;
    $rotCalls = [];
    tg_ok(!jeab_page_blocks_external(), $rotScript . ' blockerar inte utan sidflagga');
    $rotHttp = jeab_graph_http('POST', 'https://login.microsoftonline.com/tenant/oauth2/v2.0/token', null);
    tg_ok(count($rotCalls) === 1 && (int) ($rotHttp['code'] ?? 0) === 200 && empty($rotHttp['blocked']), $rotScript . ' når Graph-token');
}

echo $fail === 0 ? "all passed\n" : "{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
