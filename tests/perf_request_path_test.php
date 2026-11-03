<?php
declare(strict_types=1);

/**
 * Request-path performance: deferred jobs skip AJAX/csrf/hooks, reminder
 * list filters in SQL, ort lookup stays off the page view, indexes exist.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-perf-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_DEFER_TEST'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
$ran = false;

function check(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'csrf'];
check(jeab_request_skips_deferred_jobs(), 'csrf skips deferred jobs');

$_GET = ['p' => 'reminders', 'intent' => 'sync'];
check(jeab_request_skips_deferred_jobs(), 'intent=sync skips deferred jobs');

$_GET = ['p' => 'reminders', 'intent' => 'more'];
check(jeab_request_skips_deferred_jobs(), 'intent=more skips deferred jobs');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = ['p' => 'reminders'];
check(jeab_request_skips_deferred_jobs(), 'POST skips deferred jobs');

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'reminders'];
check(jeab_request_skips_deferred_jobs(), 'AJAX header skips deferred jobs');
unset($_SERVER['HTTP_X_REQUESTED_WITH']);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'reminders'];
$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch';
check(jeab_request_skips_deferred_jobs(), 'prefetch skips deferred jobs');
unset($_SERVER['HTTP_SEC_PURPOSE']);

$_SERVER['REQUEST_URI'] = '/hook.php';
$_SERVER['SCRIPT_NAME'] = '/hook.php';
check(jeab_request_skips_deferred_jobs(), 'hook.php skips deferred jobs');
$_SERVER['REQUEST_URI'] = '/assets/app.css';
$_SERVER['SCRIPT_NAME'] = '/assets/app.css';
check(jeab_request_skips_deferred_jobs(), 'css asset skips deferred jobs');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php?p=reminders';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'reminders'];
check(!jeab_request_skips_deferred_jobs(), 'normal page view may defer jobs');

$_GET = ['p' => 'csrf'];
$before = count($GLOBALS['_JEAB_DEFER_JOBS'] ?? []);
jeab_graph_poll_schedule(300);
check(count($GLOBALS['_JEAB_DEFER_JOBS'] ?? []) === $before, 'graph poll is not queued for csrf');

$_GET = ['p' => 'reminders'];
jeab_defer_after_response(static function () use (&$ran): void {
    $ran = true;
});
check(!$ran, 'deferred job does not run before shutdown');

$index = (string) file_get_contents($root . '/index.php');
check(
    str_contains($index, '$allowLookup = $ordrarRefresh && jeab_ordrar_identity_missing($open) !== [];'),
    'order open lookup only when refresh=1'
);
check(!str_contains($index, '!$attempted'), 'first-open identity lookup flag removed');
check(str_contains($index, 'jeab_reminders_ensure_ort($store, jeab_reminders_fill_bodies($store, $page[\'rows\']), false)'), 'reminder page view does not look up ort');
check(str_contains($index, "jeab_timing_span(\$openId > 0 ? 'order' : 'ordrar'"), 'order Server-Timing span');
check(str_contains($index, "jeab_timing_span('reminders'"), 'reminders Server-Timing span');
check(str_contains($index, "jeab_timing_span('calendar'"), 'calendar Server-Timing span');

check(!jeab_solkoll_request_wants_catalog(['kind' => 'heat']), 'non-sol order does not want Solkoll catalog');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'ordrar'];
check(!jeab_solkoll_request_wants_catalog(['kind' => 'sol', 'id' => 5]), 'GET without refresh skips catalog');
$_GET = ['p' => 'ordrar', 'refresh' => '1'];
check(!jeab_solkoll_request_wants_catalog(['kind' => 'sol', 'id' => 5]), 'refresh without an open id skips catalog');
$_GET = ['p' => 'ordrar', 'refresh' => '1', 'id' => '5'];
check(jeab_solkoll_request_wants_catalog(['kind' => 'sol', 'id' => 5]), 'refresh on the open order may fetch catalog');
check(!jeab_solkoll_request_wants_catalog(['kind' => 'sol', 'id' => 9]), 'refresh does not fetch catalog for another order');

$store = jeab_store();
$now = jeab_now();
$store->insertReminder([
    'title' => 'Test AB',
    'customer_name' => 'Test AB',
    'company' => 'jarfalla',
    'person' => 'Roberto',
    'assignee' => 'Roberto',
    'due_at' => '2026-11-01 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->insertReminder([
    'title' => 'Annan',
    'customer_name' => 'Annan',
    'company' => 'meab',
    'person' => 'Elin',
    'assignee' => 'Elin',
    'due_at' => '2026-11-02 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
check($store instanceof JeabPdoStore, 'sqlite store');
$idx = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['reminders_contact_id', 'reminders_company', 'reminders_person', 'reminders_assignee', 'reminders_completed', 'bookings_contact_id', 'reminders_company_due', 'bookings_company_starts', 'bookings_status_starts', 'bookings_job_type'] as $name) {
    check(in_array($name, $idx, true), 'index ' . $name);
}
$flag = $store->pdo()->query("SELECT value FROM settings WHERE \"key\" = 'list_indexes_v2'")->fetchColumn();
check($flag === 'ok', 'list indexes flagged done');
$sqlitePath = $tmp . '/app.sqlite';
$freshPdo = static function () use ($sqlitePath): PDO {
    return new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
};
$store->pdo()->exec('DROP INDEX reminders_company');
jeab_ensure_list_indexes($freshPdo(), 'sqlite');
$idxAfter = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
check(!in_array('reminders_company', $idxAfter, true), 'done flag skips a second SHOW INDEX and CREATE');
$store->pdo()->exec("DELETE FROM settings WHERE \"key\" = 'list_indexes_v2'");
$GLOBALS['_JEAB_LIST_INDEX_FAIL'] = ['reminders_company'];
$logDir = $tmp . '/fellogg';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
$GLOBALS['_JEAB_FELLOGG_DIR'] = $logDir;
jeab_ensure_list_indexes($freshPdo(), 'sqlite');
$failFlag = $store->pdo()->query("SELECT value FROM settings WHERE \"key\" = 'list_indexes_v2'")->fetchColumn();
check(is_string($failFlag) && str_starts_with($failFlag, 'fail:') && str_contains($failFlag, 'reminders_company'), 'failed create stored once');
$logPath = $logDir . '/app-errors.jsonl';
$logText = is_file($logPath) ? (string) file_get_contents($logPath) : '';
check(substr_count($logText, 'CREATE INDEX misslyckades') === 1, 'failed create logged once');
jeab_ensure_list_indexes($freshPdo(), 'sqlite');
$logText = is_file($logPath) ? (string) file_get_contents($logPath) : '';
check(substr_count($logText, 'CREATE INDEX misslyckades') === 1, 'second request does not retry the failed create');
$stillMissing = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
check(!in_array('reminders_company', $stillMissing, true), 'failed index stays missing');
unset($GLOBALS['_JEAB_LIST_INDEX_FAIL']);
$jar = jeab_list_reminders($store, true, 'jarfalla', null);
check(count($jar) === 1 && ($jar[0]['person'] ?? '') === 'Roberto', 'company filter jarfalla');
$who = jeab_list_reminders($store, true, null, 'Roberto');
check(count($who) === 1 && ($who[0]['company'] ?? '') === 'jarfalla', 'person filter Roberto');

$lookups = 0;
$GLOBALS['_JEAB_REMINDER_CITY_LOOKUP'] = static function () use (&$lookups): string {
    $lookups++;

    return 'Testort';
};
$rows = [['customer_name' => 'Någon', 'address' => 'Storgatan 1']];
$out = jeab_reminders_ensure_ort($store, $rows, false);
check($lookups === 0 && ($out[0]['ort'] ?? '') === '', 'ensure_ort(false) does not call Lantmäteriet');
$out = jeab_reminders_ensure_ort($store, $rows, true);
check($lookups === 1 && ($out[0]['ort'] ?? '') === 'Testort', 'ensure_ort(true) still looks up once');

@unlink(jeab_prune_stamp_path());
check(jeab_prune_due(), 'prune is due when no stamp');
check(jeab_prune_completed_reminders($store, false) === 0, 'prune deletes nothing');
check(jeab_prune_completed_reminders_now($store) === 0, 'forced prune deletes nothing');
check(!is_file(jeab_prune_stamp_path()), 'prune writes no stamp');

register_shutdown_function(static function () use (&$ran, &$fail): void {
    if (!$ran) {
        echo "FAIL deferred job did not run after the response\n";
        $fail++;
    } else {
        echo "OK  deferred job runs on shutdown\n";
    }
    echo $fail === 0 ? "\nall passed\n" : "\n{$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
});
