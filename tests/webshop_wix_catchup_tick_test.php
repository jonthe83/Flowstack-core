<?php

declare(strict_types=1);

/**
 * Wix-ikapp startar inte från en sidvisning. Tick kör den en gång per dygn.
 * JSON-GET registrerar inte avbetning av utskickskön.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-wix-catchup-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0700, true);
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['JEAB_NO_SESSION'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/secrets/wix-api.json', json_encode(['api_key' => 'test-nyckel']));
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => []]));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => new stdClass()]));

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_GET = ['p' => 'reminders'];
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);

require $root . '/src/bootstrap.php';
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:');
$GLOBALS['_JEAB_LOG_PDO']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_log_pdo();
jeab_log_flag_set($GLOBALS['_JEAB_LOG_PDO'], 'webshop_orders');
require_once $root . '/src/tick.php';

$fail = 0;
function wc_ok(bool $ok, string $message): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $message . "\n";
    if (!$ok) {
        $fail++;
    }
}

$searches = 0;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$searches): array {
    unset($site, $method, $body);
    if (str_contains($path, '/ecom/v1/orders/search')) {
        $searches++;
    }
    return ['orders' => []];
};
$GLOBALS['_JEAB_DEFER_JOBS'] = [];
unset($GLOBALS['_JEAB_DEFER_ARMED'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_SEND_GUARD_SCHEDULED']);

$store = jeab_store();
jeab_webshop_catchup_daily_schedule($store);
wc_ok($searches === 0, 'sidvisning utan tick startar inte Wix-ikapp, fick ' . $searches);
wc_ok(($GLOBALS['_JEAB_DEFER_JOBS'] ?? []) === [], 'sidvisning registrerar inget efterjobb');
$index = (string) file_get_contents($root . '/index.php');
wc_ok(!str_contains($index, 'jeab_webshop_catchup_daily_schedule'), 'index.php anropar inte ikappkörningen');
$wixSrc = (string) file_get_contents($root . '/src/webshop_wix.php');
$fnAt = strpos($wixSrc, 'function jeab_webshop_catchup_daily_schedule');
$fnEnd = strpos($wixSrc, 'function ', $fnAt + 10);
$fnSrc = $fnAt !== false ? substr($wixSrc, $fnAt, ($fnEnd === false ? 4000 : $fnEnd) - $fnAt) : '';
wc_ok($fnSrc !== '' && !str_contains($fnSrc, 'register_shutdown_function') && !str_contains($fnSrc, 'jeab_defer_after_response'), 'ikappkörningen registrerar ingen shutdown');

$drains = 0;
$GLOBALS['_JEAB_SEND_GUARD_DRAIN'] = static function () use (&$drains): void {
    $drains++;
};
$_GET = ['p' => 'ordrar'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
unset($_GET['ix_part'], $_GET['intent'], $_GET['format']);
wc_ok(jeab_request_skips_send_guard(), 'JSON-GET utan ix_part hoppar över utskickskön');
if (!jeab_request_skips_send_guard()) {
    jeab_send_guard_schedule();
}
if (!empty($GLOBALS['_JEAB_SEND_GUARD_SCHEDULED'])) {
    jeab_send_guard_finish();
}
wc_ok($drains === 0 && empty($GLOBALS['_JEAB_SEND_GUARD_SCHEDULED']), 'JSON-GET anropar inte send_guard_drain');

unset($GLOBALS['_JEAB_SEND_GUARD_SCHEDULED'], $GLOBALS['_JEAB_PAGE_REQUEST']);
$_SERVER['HTTP_ACCEPT'] = 'text/html';
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
wc_ok(!jeab_request_skips_send_guard() && jeab_page_blocks_external(), 'HTML-GET schemalägger men blockerar externa anrop');
jeab_send_guard_schedule();
jeab_send_guard_finish();
wc_ok($drains === 0, 'HTML-GET betar inte av utskickskön');
unset($GLOBALS['_JEAB_SEND_GUARD_SCHEDULED'], $GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SEND_GUARD_DRAIN']);

$GLOBALS['_JEAB_TICK_GUARD'] = static fn (): array => ['ok' => true, 'count' => 0];
$GLOBALS['_JEAB_TICK_INKORG'] = static fn (): array => ['ok' => true, 'count' => 0];
$GLOBALS['_JEAB_TICK_MALMO'] = static fn (): array => ['ok' => true, 'count' => 0];
$GLOBALS['_JEAB_WIX_SPAM_SITES'] = ['00000000-0000-4000-8000-000000000001' => 'jarfalla'];

$first = jeab_tick_run();
$afterFirst = $searches;
wc_ok(empty($first['busy']) && !empty($first['ok']), 'första tick tar låset');
wc_ok($afterFirst === 2, 'första tick söker betalda ordrar på båda sajterna, fick ' . $afterFirst);
$second = jeab_tick_run();
wc_ok(empty($second['busy']) && $searches === $afterFirst, 'samma dygn körs ikappkörningen inte igen, fick ' . $searches);
wc_ok(($first['jobs']['wix']['skipped'] ?? '') !== 'tidsgrans', 'första tick har tid kvar till ikappen');
wc_ok(str_contains($tickSrc = (string) file_get_contents($root . '/src/tick.php'), '_JEAB_WIX_CATCHUP_DEADLINE') && str_contains($tickSrc, 'min($deadline, microtime(true) + 10)'), 'ikappen får tickens deadline, högst 10 sekunder');
wc_ok(!str_contains($fnSrc, 'set_time_limit(60)'), 'ikappen höjer inte tidsgränsen till 60 sekunder');

$seenPdo = jeab_store()->pdo();
$seen = jeab_tick_seen_read($seenPdo);
wc_ok($seen !== null && $seen >= time() - 30, 'tick skriver en stämpel, fick ' . (string) $seen);

$late = 0;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$late): array {
    unset($site, $method, $body);
    if (str_contains($path, '/ecom/v1/orders/search')) {
        $late++;
    }
    return ['orders' => []];
};
$GLOBALS['_JEAB_TICK'] = true;
$GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = microtime(true) - 1;
jeab_webshop_catchup_daily_schedule($store);
wc_ok($late === 0, 'utgången deadline startar inte Wix-ikapp, fick ' . $late);
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE']);

$catchPath = $tmp . '/webshop-catchup.json';
if (is_file($catchPath)) {
    unlink($catchPath);
}
if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('webshop_catchup')) {
    jeab_webshop_catchup_write(['date' => '', 'at' => 0]);
}
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
$abortCalls = 0;
$GLOBALS['_JEAB_TICK'] = true;
$GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = microtime(true) + 30;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$abortCalls): array {
    unset($site, $method, $body, $path);
    $abortCalls++;
    $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = microtime(true) - 1;

    return ['orders' => []];
};
jeab_webshop_catchup_daily_schedule($store);
$stampedDate = (string) (jeab_webshop_catchup_read()['date'] ?? '');
wc_ok($abortCalls === 1 && $stampedDate !== $today, 'avbruten ikapp skriver inte dygnets stämpel, anrop ' . $abortCalls . ' datum ' . $stampedDate);
$retryCalls = 0;
$GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = microtime(true) + 30;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $method, string $path, ?array $body) use (&$retryCalls): array {
    unset($site, $method, $body);
    if (str_contains($path, '/ecom/v1/orders/search')) {
        $retryCalls++;
    }

    return ['orders' => []];
};
jeab_webshop_catchup_daily_schedule($store);
wc_ok($retryCalls === 2, 'nästa tick tar ikappen när stämpeln saknas, fick ' . $retryCalls);
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE']);

jeab_tick_seen_write($seenPdo, time() - 1300);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$beforeLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
jeab_tick_page_alarm(jeab_store());
$afterLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
wc_ok($afterLog !== '' && str_contains($afterLog, 'tick sen') && substr_count($afterLog, 'tick sen') === substr_count($beforeLog, 'tick sen') + 1, 'en sen tick larmas en gång');
jeab_tick_page_alarm(jeab_store());
$againLog = (string) @file_get_contents($tmp . '/app-errors.jsonl');
wc_ok(substr_count($againLog, 'tick sen') === substr_count($afterLog, 'tick sen'), 'samma timme larmas inte igen');
unset($GLOBALS['_JEAB_PAGE_REQUEST']);

echo $fail === 0 ? "all passed\n" : "{$fail} failed\n";
exit($fail === 0 ? 0 : 1);
