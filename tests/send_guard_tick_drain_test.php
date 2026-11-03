<?php

declare(strict_types=1);

/**
 * GET-sidan rör inte Graph och lämnar kön. Tick med SEND_GUARD_TICK_DRAIN
 * skickar jobbet en gång. Nästa tick skickar inget.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-guard-tick-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/fellogg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
putenv('JEAB_DISABLE_SQLITE');
putenv('SEND_GUARD_TICK_DRAIN');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';

function td_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function td_ok(bool $ok, string $message): void
{
    if (!$ok) {
        td_fail($message);
    }
    echo "OK  {$message}\n";
}

$tickSrc = (string) file_get_contents($root . '/src/tick.php');
$brakeSrc = (string) file_get_contents($root . '/src/send_guard_brake.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$cronSrc = (string) file_get_contents($root . '/sms-cron.php');
td_ok(str_contains($tickSrc, "send_guard_drain([") && str_contains($tickSrc, "'exclude_booking' => true") && str_contains($tickSrc, "'limit' => 5"), 'tick anropar avbetningen med tak');
td_ok(!str_contains($tickSrc, 'jeab_send_guard_drain('), 'tick anropar inte saknad funktion');
td_ok(str_contains($brakeSrc, 'jeab_page_blocks_external()'), 'sidans avstängning ser Graph-spärren');
td_ok(str_contains($hookSrc, "send_guard.peek"), 'peek är en hook');
td_ok(str_contains($cronSrc, "send_guard_drain(['exclude_booking' => false])"), 'sms-cron betar fortfarande av bokningsnotiser');

unset($GLOBALS['SEND_GUARD_TICK_DRAIN']);
td_ok(!jeab_send_guard_tick_drain_enabled(), 'flaggan är av när inget är satt');

jeab_send_guard_use_app_timezone();
$t0 = strtotime('2026-06-01 12:00:00');
td_ok($t0 !== false, 'klockan går att tolka');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0;
$mailCalls = 0;
$graphCalls = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mailCalls): array {
    unset($opts);
    $mailCalls++;
    return ['ok' => true, 'path' => 'stub', 'id' => 'stub'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;
    return ['code' => 200, 'body' => []];
};

$store = jeab_store();
td_ok($store instanceof JeabPdoStore, 'sqlite är öppen');
$pdo = $store->pdo();
jeab_settings_put($pdo, 'sqlite', 'SEND_GUARD_TICK_DRAIN', '1');
td_ok(jeab_send_guard_tick_drain_enabled(), 'settings kan slå på flaggan');
$GLOBALS['SEND_GUARD_TICK_DRAIN'] = false;
td_ok(!jeab_send_guard_tick_drain_enabled(), 'global av vinner över settings');
jeab_settings_put($pdo, 'sqlite', 'SEND_GUARD_TICK_DRAIN', '0');

$email = static function (string $template, string $body): array {
    return send_guard_check('email', 'kund@exempel.se', $template, 'auto', 'auto', 'system', hash('sha256', $body), [
        'body' => $body,
        'handler' => 'system_mail',
        'job' => ['opts' => [
            'to' => 'kund@exempel.se',
            'subject' => 'Test',
            'text' => $body,
            'guard_template' => $template,
            'tags' => ['kalender'],
        ]],
    ]);
};

$first = $email('offert', 'offert-a');
td_ok(!empty($first['send']), 'första mejlet går direkt');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 1;
$queued = $email('offert', 'offert-b');
td_ok(!empty($queued['queued']), 'nästa mejl köas');
$booking = $email('booking_notice', 'bokning-b');
td_ok(!empty($booking['queued']), 'bokningsnotisen köas för sms-cron');
$waitingId = (int) $pdo->query("SELECT id FROM send_queue WHERE status = 'waiting' AND template = 'offert'")->fetchColumn();
td_ok($waitingId > 0, 'det väntande jobbet har ett id');

$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 301;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'manuell-order'];
unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
td_ok(jeab_page_blocks_external(), 'Manuell order som GET blockerar Graph');
jeab_send_guard_finish();
$log = (string) @file_get_contents(jeab_app_errors_path());
td_ok($mailCalls === 0 && $graphCalls === 0 && !str_contains($log, 'blocked=1'), 'GET-avstängningen rör inte Graph');
$still = (string) $pdo->query('SELECT status FROM send_queue WHERE id = ' . $waitingId)->fetchColumn();
td_ok($still === 'waiting', 'jobbet ligger kvar och väntar på tick');
td_ok((int) $pdo->query("SELECT COUNT(*) FROM send_queue WHERE status = 'planned' AND template = 'booking_notice'")->fetchColumn() === 1, 'bokningsnotisen är orörd');

$peek = jeab_send_guard_peek_hook(['to' => 'kund@exempel.se', 'text' => 'hemlig brödtext']);
$peekJson = json_encode($peek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
td_ok(is_string($peekJson) && !empty($peek['ok']) && !empty($peek['read_only']) && !str_contains($peekJson, '@'), 'peek är läsning utan adress');
$peekJob = null;
foreach ((array) ($peek['jobs'] ?? []) as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === $waitingId) {
        $peekJob = $row;
    }
}
td_ok(is_array($peekJob) && ($peekJob['template'] ?? '') === 'offert/kalender' && ($peekJob['audience'] ?? '') === 'customer' && (int) ($peekJob['attempts'] ?? -1) === 0 && ($peekJob['error'] ?? 'x') === '' && ($peekJob['status'] ?? '') === 'waiting', 'peek visar mallen, att det är kundmejl, och noll försök');

unset($GLOBALS['_JEAB_PAGE_REQUEST']);
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
$GLOBALS['SEND_GUARD_TICK_DRAIN'] = false;
$off = jeab_tick_guard_live(microtime(true) + 8);
td_ok(($off['skipped'] ?? '') === 'av' && $mailCalls === 0, 'tick med flaggan av skickar inget');
td_ok((string) $pdo->query('SELECT status FROM send_queue WHERE id = ' . $waitingId)->fetchColumn() === 'waiting', 'avstängd tick lämnar jobbet');

$claim = jeab_send_guard_claim($pdo, $waitingId);
$again = jeab_send_guard_claim($pdo, $waitingId);
td_ok($claim['token'] !== '' && $again['token'] === '' && $mailCalls === 0, 'jobbet claimas en gång');
$released = $pdo->prepare('UPDATE send_queue SET status = ?, claim_token = NULL, attempts = 0 WHERE id = ? AND claim_token = ?');
$released->execute(['waiting', $waitingId, $claim['token']]);
td_ok($released->rowCount() === 1, 'claimen släpps tillbaka till kön inför tick');

$GLOBALS['SEND_GUARD_TICK_DRAIN'] = true;
$sent = jeab_tick_guard_live(microtime(true) + 8);
td_ok(!empty($sent['ok']) && (int) ($sent['count'] ?? 0) === 1 && $mailCalls === 1 && $graphCalls === 0, 'tick med flaggan på skickar jobbet en gång');
td_ok((string) $pdo->query('SELECT status FROM send_queue WHERE id = ' . $waitingId)->fetchColumn() === 'sent', 'jobbet är skickat');
td_ok((int) $pdo->query("SELECT COUNT(*) FROM send_queue WHERE status = 'planned' AND template = 'booking_notice'")->fetchColumn() === 1, 'tick skickar inte bokningsnotisen');

$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 500;
$second = jeab_tick_guard_live(microtime(true) + 8);
td_ok(!empty($second['ok']) && (int) ($second['count'] ?? 0) === 0 && $mailCalls === 1, 'nästa tick skickar inget');

$secret = 'demo@malmo.example.test';
$failedJob = base64_encode(serialize([
    'handler' => 'system_mail',
    'args' => ['opts' => [
        'to' => $secret,
        'subject' => 'Hemlig rubrik',
        'text' => 'hemlig brödtext som inte får med',
        'tags' => ['intern'],
        'guard_template' => 'anteckning',
    ]],
]));
$pdo->prepare(
    'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts, last_error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'email',
    'anteckning',
    hash('sha256', 'email' . "\n" . $secret),
    hash('sha256', 'hemlig brödtext som inte får med'),
    '2026-06-01 12:10:00',
    '2026-06-01 12:06:00',
    $failedJob,
    'failed',
    2,
    'graph http status 0 blocked=1 ' . $secret,
]);
$failedPeek = jeab_send_guard_peek_hook(['body' => $secret]);
$failedJson = json_encode($failedPeek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
td_ok(is_string($failedJson) && !empty($failedPeek['ok']) && !str_contains($failedJson, $secret) && !str_contains($failedJson, '@') && !str_contains($failedJson, 'hemlig'), 'peek av misslyckat jobb saknar adress och text');
$failedRow = null;
foreach ((array) ($failedPeek['jobs'] ?? []) as $row) {
    if (is_array($row) && ($row['status'] ?? '') === 'failed') {
        $failedRow = $row;
    }
}
td_ok(
    is_array($failedRow)
    && ($failedRow['template'] ?? '') === 'anteckning/intern'
    && ($failedRow['audience'] ?? '') === 'internal'
    && (int) ($failedRow['attempts'] ?? 0) === 2
    && str_contains((string) ($failedRow['error'] ?? ''), 'blocked=1')
    && ($failedRow['created'] ?? '') === '2026-06-01 12:06:00',
    'peek visar id-fältens mall, intern mottagare, försök och fel'
);

$beforeBatch = $mailCalls;
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 700;
$insert = $pdo->prepare(
    'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
for ($i = 1; $i <= 6; $i++) {
    $body = 'batch-' . $i;
    $insert->execute([
        'email',
        'offert',
        hash('sha256', 'email' . "\n" . 'kund@exempel.se'),
        hash('sha256', $body),
        '2026-06-01 12:00:00',
        '2026-06-01 12:07:0' . $i,
        base64_encode(serialize([
            'handler' => 'system_mail',
            'args' => ['opts' => [
                'to' => 'kund@exempel.se',
                'subject' => 'Test',
                'text' => $body,
                'guard_template' => 'offert',
            ]],
        ])),
        'waiting',
        0,
    ]);
}
$batch = send_guard_drain(['exclude_booking' => true, 'limit' => 5, 'deadline' => microtime(true) + 8]);
td_ok(!empty($batch['ran']) && (int) ($batch['sent'] ?? 0) === 5 && $mailCalls === $beforeBatch + 5, 'ett tick tar högst fem jobb');
td_ok((int) $pdo->query("SELECT COUNT(*) FROM send_queue WHERE status = 'waiting' AND template = 'offert'")->fetchColumn() === 1, 'det sjätte jobbet ligger kvar');
$sameMinute = send_guard_drain(['exclude_booking' => true, 'limit' => 5, 'deadline' => microtime(true) + 8]);
td_ok(empty($sameMinute['ran']) && $mailCalls === $beforeBatch + 5, 'samma minut skickar inte om');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 1100;
$rest = send_guard_drain(['exclude_booking' => true, 'limit' => 5, 'deadline' => microtime(true) + 8]);
td_ok(!empty($rest['ran']) && (int) ($rest['sent'] ?? 0) === 1 && $mailCalls === $beforeBatch + 6, 'nästa tick tar bara det som inte redan skickats');
td_ok((int) $pdo->query("SELECT COUNT(*) FROM send_queue WHERE status = 'waiting' AND channel = 'email' AND template = 'offert'")->fetchColumn() === 0, 'inget väntande offertjobb blir kvar');

$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$mailCalls): array {
    $mailCalls++;
    throw new RuntimeException('kund@exempel.se boom');
};
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 1600;
$insert->execute([
    'email',
    'offert',
    hash('sha256', 'throw'),
    hash('sha256', 'throw-body'),
    '2026-06-01 12:00:00',
    '2026-06-01 12:20:00',
    base64_encode(serialize([
        'handler' => 'system_mail',
        'args' => ['opts' => [
            'to' => 'kund@exempel.se',
            'subject' => 'Test',
            'text' => 'throw-body',
            'guard_template' => 'offert',
        ]],
    ])),
    'waiting',
    0,
]);
$threw = send_guard_drain(['exclude_booking' => true, 'limit' => 5, 'deadline' => microtime(true) + 8]);
$threwStatus = (string) $pdo->query("SELECT status FROM send_queue WHERE body_hash = '" . hash('sha256', 'throw-body') . "'")->fetchColumn();
$threwError = (string) $pdo->query("SELECT last_error FROM send_queue WHERE body_hash = '" . hash('sha256', 'throw-body') . "'")->fetchColumn();
td_ok(!empty($threw['ran']) && (int) ($threw['sent'] ?? 0) === 0 && $threwStatus === 'failed' && $threwError === 'misslyckades' && !str_contains($threwError, '@'), 'ett kast claimas som misslyckat utan adress');
$callsAfterThrow = $mailCalls;
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 2000;
$afterThrow = send_guard_drain(['exclude_booking' => true, 'limit' => 5, 'deadline' => microtime(true) + 8]);
td_ok((int) ($afterThrow['sent'] ?? 0) === 0 && $mailCalls === $callsAfterThrow, 'ett misslyckat jobb skickas inte igen');

echo "OK  tick-avbetning\n";
