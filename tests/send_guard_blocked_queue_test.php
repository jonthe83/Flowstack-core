<?php

declare(strict_types=1);

/**
 * En Graph-spärr (http 0, blocked=1) lägger mejlet i send_queue som waiting.
 * Samma mejl en gång till ger ingen ny rad. Ingenting skickas och kön betalas inte av.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-guard-block-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/fellogg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
putenv('JEAB_DISABLE_SQLITE');
putenv('SEND_GUARD_TICK_DRAIN');
unset($GLOBALS['SEND_GUARD_TICK_DRAIN']);
file_put_contents($tmp . '/secrets/m365.json', json_encode([
    'tenant_id' => 'tenant',
    'client_id' => 'client',
    'client_secret' => 'secret',
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require $root . '/src/bootstrap.php';

function bq_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bq_ok(bool $ok, string $message): void
{
    if (!$ok) {
        bq_fail($message);
    }
    echo "OK  {$message}\n";
}

$brake = (string) file_get_contents($root . '/src/send_guard_brake.php');
$queueFn = '';
$pos = strpos($brake, 'function jeab_send_guard_queue_blocked(');
if ($pos !== false) {
    $next = strpos($brake, "\nfunction ", $pos + 10);
    $queueFn = $next === false ? substr($brake, $pos) : substr($brake, $pos, $next - $pos);
}
bq_ok($queueFn !== '' && !str_contains($queueFn, 'send_guard_drain') && !str_contains($queueFn, 'jeab_send_guard_finish') && !str_contains($queueFn, 'SEND_GUARD_TICK_DRAIN'), 'köfunktionen anropar inte avbetning och rör inte flaggan');

$graphCalls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;

    return ['code' => 202, 'body' => ['id' => 'should-not-send']];
};
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
unset($GLOBALS['_JEAB_EXTERNAL_ALLOW'], $GLOBALS['_JEAB_TICK']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php?p=solkampanj';
$_GET = ['p' => 'solkampanj'];
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT'], $_POST);
bq_ok(jeab_page_blocks_external(), 'sidvisningen blockerar Graph');
bq_ok(!jeab_send_guard_tick_drain_enabled(), 'SEND_GUARD_TICK_DRAIN är av');

$to = 'kund.block@example.test';
$opts = [
    'to' => $to,
    'subject' => 'Offert',
    'text' => 'Hej igen',
    'company' => 'jarfalla',
    'guard_template' => 'offert',
    'guard_mode' => 'manual',
    'guard_via' => 'manual',
    'guard_actor' => 'Sam L',
    'guard_body_hash' => hash('sha256', 'samma-offert'),
];
$first = jeab_system_mail_send($opts);
bq_ok($graphCalls === 0, 'Graph anropas inte');
bq_ok(($first['ok'] ?? true) === false && !empty($first['queued']) && ($first['path'] ?? '') === 'queued', 'svaret är köat och inte skickat');

$store = jeab_store();
bq_ok($store instanceof JeabPdoStore, 'sqlite är öppen');
$pdo = $store->pdo();
$rows = $pdo->query("SELECT id, channel, template, status, attempts, job FROM send_queue WHERE status = 'waiting'")->fetchAll(PDO::FETCH_ASSOC);
bq_ok(count($rows) === 1, 'exakt en väntande rad');
$row = $rows[0];
$id = (int) ($row['id'] ?? 0);
bq_ok(
    $id > 0
    && ($row['channel'] ?? '') === 'email'
    && ($row['template'] ?? '') === 'offert'
    && ($row['status'] ?? '') === 'waiting'
    && (int) ($row['attempts'] ?? 1) === 0,
    'raden är email, offert och waiting'
);
$raw = base64_decode((string) ($row['job'] ?? ''), true);
$job = is_string($raw) ? unserialize($raw, ['allowed_classes' => false]) : false;
bq_ok(is_array($job) && ($job['handler'] ?? '') === 'system_mail' && is_array($job['args']['opts'] ?? null), 'jobbet är system_mail med payload');
bq_ok((string) ($first['id'] ?? '') === (string) $id, 'svaret pekar på samma rad');

$second = jeab_system_mail_send($opts);
$again = (int) $pdo->query("SELECT COUNT(*) FROM send_queue WHERE status = 'waiting' AND template = 'offert'")->fetchColumn();
bq_ok($graphCalls === 0 && $again === 1 && !empty($second['queued']) && (string) ($second['id'] ?? '') === (string) $id, 'samma mejl ger ingen dubblett och skickas inte');

$sentCount = 0;
try {
    $sentCount = (int) $pdo->query("SELECT COUNT(*) FROM inbox_sent WHERE outcome = 'sent'")->fetchColumn();
} catch (Throwable) {
    $sentCount = 0;
}
bq_ok($sentCount === 0, 'ingen sent-rad skrivs');
$state = $pdo->query('SELECT last_run, killed FROM send_guard_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
bq_ok(is_array($state) && trim((string) ($state['last_run'] ?? '')) === '' && (int) ($state['killed'] ?? 1) === 0, 'avbetningen har inte kört');
bq_ok(!jeab_send_guard_tick_drain_enabled(), 'flaggan är fortfarande av');

$log = (string) @file_get_contents(jeab_app_errors_path());
$line = 'queued_after_block id=' . $id . ' template=offert';
bq_ok(substr_count($log, $line) === 2, 'båda försöken loggar samma kö-rad');
bq_ok(!str_contains($log, 'Inkorgen: graph 0'), 'det gamla dropplocket skrivs inte');
bq_ok(!str_contains($log, $to) && !str_contains($log, '@'), 'loggen har ingen adress');

echo "OK  send_guard blocked queue\n";
