<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Spärr för automatiska kundutskick från flowstack.example.test.
 * Ett automatiskt mejl och ett automatiskt sms per fem minuter.
 * Överskott köas, FIFO, not_before = senaste utskick + 5 minuter.
 * Fler än 20 väntande i en kanal stoppar alla automatiska utskick.
 *
 * booking_notice som väntar på sms-cron har status planned och räknas
 * inte mot nödstoppets 20. sms-cron flyttar dem till takt-kön (waiting).
 * Där gäller 1 per 5 minuter, och fler än 20 i takt-kön är nödstopp.
 * Sidans avstängning anropar send_guard_drain() utan argument och rör
 * inte planned. En sidvisning som blockerar Graph lämnar kön till tick.
 * Tick anropar med exclude_booking true och högst fem jobb, och bara när
 * SEND_GUARD_TICK_DRAIN är på (standard av).
 * send_guard.queue_diag läser kön. Den skriver inte, skickar inte,
 * betar inte av och ändrar ingen flagga.
 * Ett Graph-anrop som sidan blockerar (http 0, blocked=1) läggs i
 * send_queue som waiting. Den vägen skickar inte och anropar inte drain.
 * Avbetning är bara tick plus SEND_GUARD_TICK_DRAIN, som är av som standard.
 */

function jeab_send_guard_now(): int
{
    if (isset($GLOBALS['_JEAB_SEND_GUARD_NOW'])) {
        return (int) $GLOBALS['_JEAB_SEND_GUARD_NOW'];
    }
    return time();
}

function jeab_send_guard_sql_stamp(?int $stamp = null): string
{
    return jeab_send_guard_format_stamp($stamp ?? jeab_send_guard_now());
}

function jeab_send_guard_pdo(): ?PDO
{
    if (array_key_exists('_JEAB_SEND_GUARD_PDO', $GLOBALS)) {
        $pdo = $GLOBALS['_JEAB_SEND_GUARD_PDO'];
        return $pdo instanceof PDO ? $pdo : null;
    }
    try {
        if (!function_exists('jeab_store')) {
            return null;
        }
        $store = jeab_store();
        if ($store instanceof JeabPdoStore) {
            return $store->pdo();
        }
    } catch (Throwable) {
        return null;
    }
    return null;
}

/** JSON-läge i tester utan SQL-liggare räknas inte som ett databasavbrott. */
function jeab_send_guard_sql_required(): bool
{
    if (array_key_exists('_JEAB_SEND_GUARD_PDO', $GLOBALS)) {
        return true;
    }
    if (getenv('JEAB_DISABLE_SQLITE') === '1' && function_exists('jeab_driver') && jeab_driver() === 'json') {
        return false;
    }
    return true;
}

function jeab_send_guard_normalize_channel(string $channel): string
{
    $channel = strtolower(trim($channel));
    return $channel === 'sms' ? 'sms' : ($channel === 'email' ? 'email' : '');
}

function jeab_send_guard_normalize_recipient(string $channel, string $recipient): string
{
    if ($channel === 'sms') {
        return preg_replace('/\D+/', '', $recipient) ?? '';
    }
    return strtolower(trim($recipient));
}

function jeab_send_guard_rcpt_hash(string $channel, string $recipient): string
{
    return hash('sha256', $channel . "\n" . jeab_send_guard_normalize_recipient($channel, $recipient));
}

function jeab_send_guard_ensure(PDO $pdo): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS send_guard_state (
                id INT NOT NULL,
                killed TINYINT NOT NULL DEFAULT 0,
                killed_at DATETIME NULL,
                last_run DATETIME NULL,
                alarm_at DATETIME NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS send_queue (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                channel VARCHAR(16) NOT NULL,
                template VARCHAR(64) NOT NULL,
                rcpt_hash CHAR(64) NOT NULL,
                body_hash CHAR(64) NOT NULL,
                not_before DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                job MEDIUMTEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'waiting\',
                attempts INT NOT NULL DEFAULT 0,
                last_error VARCHAR(180) NULL,
                claim_token VARCHAR(64) NULL,
                claimed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY send_queue_wait (channel, status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec('INSERT IGNORE INTO send_guard_state (id, killed) VALUES (1, 0)');
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS send_guard_state (
                id INTEGER PRIMARY KEY,
                killed INTEGER NOT NULL DEFAULT 0,
                killed_at TEXT,
                last_run TEXT,
                alarm_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS send_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel TEXT NOT NULL,
                template TEXT NOT NULL,
                rcpt_hash TEXT NOT NULL,
                body_hash TEXT NOT NULL,
                not_before TEXT NOT NULL,
                created_at TEXT NOT NULL,
                job TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'waiting\',
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                claim_token TEXT,
                claimed_at TEXT
            )'
        );
        $pdo->exec('INSERT OR IGNORE INTO send_guard_state (id, killed) VALUES (1, 0)');
    }
    jeab_send_guard_ensure_queue_columns($pdo);
    if (function_exists('jeab_ensure_inbox_sent')) {
        jeab_ensure_inbox_sent($pdo, $driver === 'mysql' ? 'mysql' : 'sqlite');
    }
}

/** @return list<string> */
function jeab_send_guard_queue_column_names(PDO $pdo): array
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return ['id', 'channel', 'template', 'rcpt_hash', 'body_hash', 'not_before', 'created_at', 'job', 'status', 'attempts', 'last_error', 'claim_token', 'claimed_at'];
    }
    $names = [];
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM send_queue');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $names[] = (string) ($row['Field'] ?? '');
            }
        }
        return $names;
    }
    $stmt = $pdo->query('PRAGMA table_info(send_queue)');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $names[] = (string) ($row['name'] ?? '');
        }
    }
    return $names;
}

function jeab_send_guard_ensure_queue_columns(PDO $pdo): void
{
    $have = jeab_send_guard_queue_column_names($pdo);
    if ($have === []) {
        return;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $add = $driver === 'mysql'
        ? [
            'attempts' => 'INT NOT NULL DEFAULT 0',
            'last_error' => 'VARCHAR(180) NULL',
            'claim_token' => 'VARCHAR(64) NULL',
            'claimed_at' => 'DATETIME NULL',
        ]
        : [
            'attempts' => 'INTEGER NOT NULL DEFAULT 0',
            'last_error' => 'TEXT',
            'claim_token' => 'TEXT',
            'claimed_at' => 'TEXT',
        ];
    foreach ($add as $name => $ddl) {
        if (in_array($name, $have, true)) {
            continue;
        }
        $pdo->exec('ALTER TABLE send_queue ADD COLUMN ' . $name . ' ' . $ddl);
    }
}

/**
 * Sending-rader som fanns innan claimed_at ska inte tas om direkt.
 * NULL räknas inte som gammal. Stämpeln sätts här, bara från migrate och tick.
 */
function jeab_send_guard_legacy_claims_allowed(): bool
{
    if (!empty($GLOBALS['_JEAB_TICK']) || !empty($GLOBALS['_JEAB_DATA_MIGRATE']) || !empty($GLOBALS['_JEAB_SCHEMA_DDL'])) {
        return true;
    }
    if (function_exists('jeab_booking_schema_work_allowed')) {
        return jeab_booking_schema_work_allowed();
    }

    return in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && empty($GLOBALS['_JEAB_PAGE_REQUEST']);
}

function jeab_send_guard_legacy_claims_backfill(PDO $pdo): void
{
    if (!jeab_send_guard_legacy_claims_allowed()) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $key = 'send_queue_claimed_at_v1';
        if (function_exists('jeab_schema_flag_ok') && function_exists('jeab_settings_value')
            && jeab_schema_flag_ok(jeab_settings_value($pdo, $driver, $key))) {
            return;
        }
        jeab_send_guard_ensure($pdo);
        if (!in_array('claimed_at', jeab_send_guard_queue_column_names($pdo), true)) {
            return;
        }
        $pdo->prepare(
            'UPDATE send_queue SET claimed_at = ? WHERE status = ? AND (claimed_at IS NULL OR claimed_at = \'\')'
        )->execute([jeab_send_guard_sql_stamp(), 'sending']);
        if (function_exists('jeab_settings_put')) {
            jeab_settings_put($pdo, $driver, $key, 'ok');
        }
    } catch (Throwable) {
    }
}

/**
 * @return array{killed:int,killed_at:string,last_run:string,alarm_at:string}
 */
function jeab_send_guard_state(PDO $pdo): array
{
    jeab_send_guard_ensure($pdo);
    $stmt = $pdo->query('SELECT killed, killed_at, last_run, alarm_at FROM send_guard_state WHERE id = 1');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($row)) {
        return ['killed' => 0, 'killed_at' => '', 'last_run' => '', 'alarm_at' => ''];
    }
    return [
        'killed' => (int) ($row['killed'] ?? 0),
        'killed_at' => (string) ($row['killed_at'] ?? ''),
        'last_run' => (string) ($row['last_run'] ?? ''),
        'alarm_at' => (string) ($row['alarm_at'] ?? ''),
    ];
}

function jeab_send_guard_mirror_kill(bool $killed): void
{
    try {
        if (!function_exists('jeab_store')) {
            return;
        }
        $store = jeab_store();
        if (!method_exists($store, 'setSetting')) {
            return;
        }
        $store->setSetting('send_killswitch', $killed ? '1' : '0');
    } catch (Throwable) {
    }
}

function jeab_send_guard_log(PDO $pdo, string $channel, string $via, string $actor, string $kind, string $outcome, string $bodyHash, string $rcptHash): void
{
    if ($via !== 'auto' && $via !== 'manual') {
        $via = 'manual';
    }
    $actor = trim($actor);
    if ($via === 'auto') {
        $actor = 'system';
    } elseif ($actor === '') {
        $actor = 'hook';
    }
    $pdo->prepare(
        'INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, rcpt_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        jeab_send_guard_sql_stamp(),
        '',
        $via,
        mb_substr($actor, 0, 191),
        mb_substr($kind, 0, 32),
        mb_substr($outcome, 0, 32),
        $channel,
        $bodyHash,
        $rcptHash,
    ]);
    $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;
}

function jeab_send_guard_latest_auto(PDO $pdo, string $channel): ?int
{
    $stmt = $pdo->prepare(
        'SELECT created_at FROM inbox_sent WHERE via = ? AND channel = ? AND outcome = ? AND kind = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['auto', $channel, 'sent', 'guard']);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) && !is_int($raw)) {
        return null;
    }
    return jeab_send_guard_stamp($raw);
}

/** Takt-kön. Planerad bokningsväntan (status planned) ingår inte. */
function jeab_send_guard_waiting(PDO $pdo, string $channel): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM send_queue WHERE channel = ? AND status = ?');
    $stmt->execute([$channel, 'waiting']);
    return (int) $stmt->fetchColumn();
}

function jeab_send_guard_planned(PDO $pdo, string $channel): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM send_queue WHERE channel = ? AND status = ? AND template = ?');
    $stmt->execute([$channel, 'planned', 'booking_notice']);
    return (int) $stmt->fetchColumn();
}

function jeab_send_guard_promote_booking(PDO $pdo): void
{
    $pdo->exec("UPDATE send_queue SET status = 'waiting' WHERE status = 'planned' AND template = 'booking_notice'");
}

/**
 * Samma utskick ligger redan i kön. Direktsändning skulle skicka det en gång till
 * när sms-cron senare betar av raden.
 */
function jeab_send_guard_open_id(PDO $pdo, string $channel, string $rcptHash, string $bodyHash, string $template): int
{
    if ($channel === '' || $rcptHash === '' || $bodyHash === '' || $template === '') {
        return 0;
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM send_queue WHERE channel = ? AND rcpt_hash = ? AND body_hash = ? AND template = ? AND status IN (\'waiting\', \'planned\', \'sending\') ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$channel, $rcptHash, $bodyHash, $template]);

    return (int) $stmt->fetchColumn();
}

function jeab_send_guard_same_open(PDO $pdo, string $channel, string $rcptHash, string $bodyHash, string $template): bool
{
    return jeab_send_guard_open_id($pdo, $channel, $rcptHash, $bodyHash, $template) > 0;
}

function jeab_send_guard_duplicate(PDO $pdo, string $channel, string $rcptHash, string $bodyHash, int $now): bool
{
    if ($rcptHash === '' || $bodyHash === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT created_at FROM inbox_sent WHERE channel = ? AND rcpt_hash = ? AND body_hash = ? AND outcome = ? ORDER BY id DESC LIMIT 5'
    );
    $stmt->execute([$channel, $rcptHash, $bodyHash, 'sent']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $stamp = jeab_send_guard_stamp($raw);
        if ($stamp !== null && $stamp >= $now - 600) {
            return true;
        }
    }
    return false;
}

function jeab_send_guard_alarm(string $channel): void
{
    $GLOBALS['_JEAB_SEND_GUARD_ALARMS'] = (int) ($GLOBALS['_JEAB_SEND_GUARD_ALARMS'] ?? 0) + 1;
    $word = $channel === 'sms' ? 'sms' : 'mejl';
    $payload = [
        'source' => 'jeab',
        'type' => 'send_guard',
        'description' => 'Automatiska ' . $word . ' är stoppade. Kön har fler än 20 väntande.',
        'reporter' => 'Einstein',
    ];
    try {
        if (!function_exists('jeab_einstein_webhook_secret') || !function_exists('jeab_einstein_webhook_post')) {
            return;
        }
        $secret = jeab_einstein_webhook_secret();
        if (!is_array($secret)) {
            return;
        }
        jeab_einstein_webhook_post($secret, $payload);
    } catch (Throwable) {
    }
}

function jeab_send_guard_trip(PDO $pdo, string $channel): void
{
    $stamp = jeab_send_guard_sql_stamp();
    $pdo->prepare('UPDATE send_guard_state SET killed = 1, killed_at = ? WHERE id = 1 AND killed = 0')->execute([$stamp]);
    $alarm = $pdo->prepare('UPDATE send_guard_state SET alarm_at = ? WHERE id = 1 AND alarm_at IS NULL');
    $alarm->execute([$stamp]);
    jeab_send_guard_mirror_kill(true);
    if ($alarm->rowCount() > 0) {
        jeab_send_guard_alarm($channel);
    }
}

/**
 * @param array<string, mixed> $base
 * @param array<string, mixed> $over
 * @return array<string, mixed>
 */
function jeab_send_guard_decision(array $base, array $over): array
{
    return array_merge($base, $over);
}

/**
 * @param array<string, mixed> $opts
 * @return array<string, mixed>
 */
function send_guard_check(
    string $channel,
    string $recipient,
    string $template,
    string $mode,
    string $via,
    string $actor,
    string $body_hash = '',
    array $opts = []
): array {
    jeab_send_guard_use_app_timezone();
    $channel = jeab_send_guard_normalize_channel($channel);
    $mode = $mode === 'manual' ? 'manual' : 'auto';
    $via = $via === 'manual' || $via === 'auto' ? $via : $mode;
    $template = trim($template);
    if ($template === '') {
        $template = $channel === 'sms' ? 'sms' : 'email';
    }
    $template = mb_substr($template, 0, 64);
    if ($mode === 'auto') {
        $actor = 'system';
        $via = 'auto';
    } elseif (trim($actor) === '') {
        $actor = function_exists('jeab_inbox_sent_actor') ? jeab_inbox_sent_actor('manual') : 'hook';
    }
    $body = (string) ($opts['body'] ?? '');
    if ($body_hash === '') {
        $body_hash = hash('sha256', $body);
    }
    $rcptHash = jeab_send_guard_rcpt_hash($channel, $recipient);
    $base = [
        'ok' => false,
        'send' => false,
        'queued' => false,
        'blocked' => true,
        'reason' => 'bad',
        'path' => '',
        'ticket' => '',
        'manual_pending' => false,
        'channel' => $channel,
        'via' => $via,
        'actor' => $actor,
        'template' => $template,
        'body_hash' => $body_hash,
        'rcpt_hash' => $rcptHash,
    ];
    if ($channel === '') {
        return $base;
    }
    $allow = static function (array $extra) use ($base): array {
        return jeab_send_guard_decision($base, $extra);
    };
    if ($channel === 'sms' && function_exists('jeab_test_lead_send_probe')
        && !(function_exists('jeab_test_lead_selftest_active') && jeab_test_lead_selftest_active())) {
        $probe = jeab_test_lead_send_probe('sms', $recipient, $opts);
        if ($probe['test']) {
            jeab_test_lead_log('send_guard', (string) $probe['reason'], (string) $probe['email'], (string) $probe['phone']);
            return $allow(['reason' => 'testlead']);
        }
    }
    if ($mode === 'auto' && $channel !== 'sms' && function_exists('jeab_test_lead_match')) {
        $logOnly = !empty($GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY']);
        $probeTarget = $recipient;
        if ($logOnly) {
            $customer = trim((string) ($opts['deliver_customer_email'] ?? ''));
            if ($customer !== '') {
                $probeTarget = $customer;
            }
        }
        $probe = jeab_test_lead_match(['email' => $probeTarget]);
        if ($probe['test']) {
            jeab_test_lead_log('send_guard', (string) $probe['reason'], (string) $probe['email'], (string) $probe['phone']);
            if (!$logOnly) {
                return $allow(['reason' => 'testlead']);
            }
        }
    }
    if (!jeab_send_guard_sql_required()) {
        $ticket = bin2hex(random_bytes(8));
        $GLOBALS['_JEAB_SEND_GUARD_TICKETS'][$ticket] = true;
        return $allow(['ok' => true, 'send' => true, 'blocked' => false, 'reason' => 'ok', 'path' => 'send', 'ticket' => $ticket]);
    }
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        if ($mode === 'manual') {
            $ticket = bin2hex(random_bytes(8));
            $GLOBALS['_JEAB_SEND_GUARD_TICKETS'][$ticket] = true;
            return $allow([
                'ok' => true,
                'send' => true,
                'blocked' => false,
                'reason' => 'ok',
                'path' => 'send',
                'ticket' => $ticket,
                'manual_pending' => true,
            ]);
        }
        return $allow(['reason' => 'sql']);
    }
    try {
        jeab_send_guard_ensure($pdo);
        $now = jeab_send_guard_now();
        if ($mode === 'manual' && jeab_send_guard_duplicate($pdo, $channel, $rcptHash, $body_hash, $now)) {
            jeab_send_guard_log($pdo, $channel, $via, $actor, 'guard:dup', 'duplicate', $body_hash, $rcptHash);
            return $allow(['reason' => 'duplicate']);
        }
        if ($mode === 'manual') {
            $ticket = bin2hex(random_bytes(8));
            $GLOBALS['_JEAB_SEND_GUARD_TICKETS'][$ticket] = true;
            return $allow([
                'ok' => true,
                'send' => true,
                'blocked' => false,
                'reason' => 'ok',
                'path' => 'send',
                'ticket' => $ticket,
                'manual_pending' => true,
            ]);
        }
        $state = jeab_send_guard_state($pdo);
        if ((int) $state['killed'] === 1) {
            jeab_send_guard_log($pdo, $channel, 'auto', 'system', 'guard:kill', 'blocked', $body_hash, $rcptHash);
            return $allow(['reason' => 'kill']);
        }
        $latest = jeab_send_guard_latest_auto($pdo, $channel);
        if ($latest !== null && $latest > $now - 300) {
            $handler = trim((string) ($opts['handler'] ?? ''));
            $job = [
                'handler' => $handler,
                'args' => is_array($opts['job'] ?? null) ? $opts['job'] : [],
            ];
            $encoded = base64_encode(serialize($job));
            $queueId = jeab_send_guard_open_id($pdo, $channel, $rcptHash, $body_hash, $template);
            if ($queueId < 1) {
                $notBefore = jeab_send_guard_sql_stamp($latest + 300);
                $lane = $template === 'booking_notice' ? 'planned' : 'waiting';
                $pdo->prepare(
                    'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $channel,
                    $template,
                    $rcptHash,
                    $body_hash,
                    $notBefore,
                    jeab_send_guard_sql_stamp($now),
                    $encoded,
                    $lane,
                ]);
                $queueId = (int) $pdo->lastInsertId();
                jeab_send_guard_log($pdo, $channel, 'auto', 'system', 'guard', 'queued', $body_hash, $rcptHash);
                if ($lane === 'waiting' && jeab_send_guard_waiting($pdo, $channel) > 20) {
                    jeab_send_guard_trip($pdo, $channel);
                }
            }
            return $allow(['ok' => true, 'queued' => true, 'blocked' => false, 'reason' => 'queued', 'path' => 'queued', 'queue_id' => $queueId]);
        }
        $queueId = jeab_send_guard_open_id($pdo, $channel, $rcptHash, $body_hash, $template);
        if ($queueId > 0) {
            return $allow(['ok' => true, 'queued' => true, 'blocked' => false, 'reason' => 'queued', 'path' => 'queued', 'queue_id' => $queueId]);
        }
        jeab_send_guard_log($pdo, $channel, 'auto', 'system', 'guard', 'sent', $body_hash, $rcptHash);
        $ticket = bin2hex(random_bytes(8));
        $GLOBALS['_JEAB_SEND_GUARD_TICKETS'][$ticket] = true;
        return $allow(['ok' => true, 'send' => true, 'blocked' => false, 'reason' => 'ok', 'path' => 'send', 'ticket' => $ticket]);
    } catch (Throwable) {
        if ($mode === 'manual') {
            $ticket = bin2hex(random_bytes(8));
            $GLOBALS['_JEAB_SEND_GUARD_TICKETS'][$ticket] = true;
            return $allow([
                'ok' => true,
                'send' => true,
                'blocked' => false,
                'reason' => 'ok',
                'path' => 'send',
                'ticket' => $ticket,
                'manual_pending' => true,
            ]);
        }
        return $allow(['reason' => 'sql']);
    }
}

function jeab_send_guard_commit_manual(array $gate): void
{
    if (empty($gate['manual_pending'])) {
        return;
    }
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        jeab_send_guard_ensure($pdo);
        jeab_send_guard_log(
            $pdo,
            (string) ($gate['channel'] ?? 'email'),
            'manual',
            (string) ($gate['actor'] ?? 'hook'),
            'guard',
            'sent',
            (string) ($gate['body_hash'] ?? ''),
            (string) ($gate['rcpt_hash'] ?? '')
        );
    } catch (Throwable) {
    }
}

/**
 * @param array<string, mixed> $context
 * @return array{action:string,result:?array,manual_pending:bool,ticket:string,inner:bool,channel:string,via:string,actor:string,template:string,body_hash:string,rcpt_hash:string}
 */
function jeab_send_guard_gate(string $channel, string $recipient, string $body, array $context): array
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        if (function_exists('jeab_demo_log')) {
            jeab_demo_log('gate:' . $channel, $recipient);
        }
        return [
            'action' => 'stop',
            'result' => $channel === 'sms'
                ? ['ok' => true, 'error' => null, 'demo' => true, 'id' => 'demo']
                : ['ok' => true, 'path' => 'demo', 'error' => null, 'id' => 'demo', 'demo' => true],
            'manual_pending' => false,
            'ticket' => '',
            'inner' => true,
            'channel' => $channel,
            'via' => '',
            'actor' => '',
            'template' => '',
            'body_hash' => '',
            'rcpt_hash' => '',
            'demo' => true,
        ];
    }
    $empty = [
        'action' => 'send',
        'result' => null,
        'manual_pending' => false,
        'ticket' => '',
        'inner' => true,
        'channel' => $channel,
        'via' => '',
        'actor' => '',
        'template' => '',
        'body_hash' => '',
        'rcpt_hash' => '',
    ];
    if (function_exists('jeab_cutover_sends_paused') && jeab_cutover_sends_paused()) {
        $empty['action'] = 'stop';
        $empty['result'] = $channel === 'sms'
            ? ['ok' => false, 'error' => 'Utskicket är pausat.']
            : ['ok' => false, 'path' => '', 'error' => 'Utskicket är pausat.', 'id' => ''];

        return $empty;
    }
    if (!empty($GLOBALS['_JEAB_SEND_GUARD_REPLAY']) || !function_exists('send_guard_check')) {
        return $empty;
    }
    $held = (string) ($GLOBALS['_JEAB_SEND_GUARD_HELD'] ?? '');
    if ($held !== '' && !empty($GLOBALS['_JEAB_SEND_GUARD_TICKETS'][$held])) {
        return array_merge($empty, ['ticket' => $held]);
    }
    // mail_queue (H1): offert, mötesbekräftelse och Inkorg-svar går via kön
    // när MAIL_QUEUE_ENABLED är på. Drain skickar via replay och kommer inte hit.
    if (function_exists('jeab_mail_queue_should_intercept') && jeab_mail_queue_should_intercept($channel, $context)) {
        $enq = jeab_mail_queue_enqueue(
            jeab_mail_queue_classify($context),
            $recipient,
            ['handler' => (string) ($context['handler'] ?? ''), 'args' => is_array($context['job'] ?? null) ? $context['job'] : []]
        );
        $empty['action'] = 'stop';
        $empty['result'] = jeab_mail_queue_gate_result($enq);

        return $empty;
    }
    $mode = (string) ($context['mode'] ?? 'auto');
    $template = (string) ($context['template'] ?? '');
    $via = (string) ($context['via'] ?? $mode);
    $actor = (string) ($context['actor'] ?? '');
    $leadOpts = [
        'body' => $body,
        'handler' => (string) ($context['handler'] ?? ''),
        'job' => is_array($context['job'] ?? null) ? $context['job'] : [],
    ];
    foreach (['email', 'name', 'phone', 'lead', 'order', 'reminder', 'booking', 'deliver_customer_email'] as $key) {
        if (array_key_exists($key, $context)) {
            $leadOpts[$key] = $context[$key];
        }
    }
    $decision = send_guard_check($channel, $recipient, $template, $mode, $via, $actor, (string) ($context['body_hash'] ?? ''), $leadOpts);
    $gate = [
        'action' => 'send',
        'result' => null,
        'manual_pending' => !empty($decision['manual_pending']),
        'ticket' => (string) ($decision['ticket'] ?? ''),
        'inner' => false,
        'channel' => (string) ($decision['channel'] ?? $channel),
        'via' => (string) ($decision['via'] ?? $via),
        'actor' => (string) ($decision['actor'] ?? $actor),
        'template' => (string) ($decision['template'] ?? $template),
        'body_hash' => (string) ($decision['body_hash'] ?? ''),
        'rcpt_hash' => (string) ($decision['rcpt_hash'] ?? ''),
    ];
    if (!empty($decision['send'])) {
        if ($gate['ticket'] !== '') {
            $GLOBALS['_JEAB_SEND_GUARD_HELD'] = $gate['ticket'];
        }
        return $gate;
    }
    $gate['action'] = 'stop';
    $message = match ((string) ($decision['reason'] ?? '')) {
        'duplicate' => 'Samma meddelande skickades nyligen.',
        'testlead' => 'Testmottagare – skickas inte',
        'sql' => 'Automatiska utskick är stoppade tills databasen svarar.',
        'kill' => 'Automatiska utskick är stoppade.',
        default => 'Automatiska utskick är stoppade.',
    };
    if (!empty($decision['queued'])) {
        $queueId = (int) ($decision['queue_id'] ?? 0);
        $id = $queueId > 0 ? (string) $queueId : '';
        $gate['result'] = $channel === 'sms'
            ? ['ok' => true, 'error' => null, 'queued' => true, 'id' => $id]
            : ['ok' => true, 'path' => 'queued', 'error' => null, 'id' => $id, 'queued' => true];
        return $gate;
    }
    $gate['result'] = $channel === 'sms'
        ? ['ok' => false, 'error' => $message]
        : ['ok' => false, 'path' => '', 'error' => $message, 'id' => ''];
    return $gate;
}

function jeab_send_guard_mark(array $gate, array $result): array
{
    if (!empty($result['ok'])) {
        jeab_send_guard_commit_manual($gate);
    }
    return $result;
}

function jeab_send_guard_release(array $gate): void
{
    $ticket = (string) ($gate['ticket'] ?? '');
    if ($ticket !== '' && empty($gate['inner']) && (string) ($GLOBALS['_JEAB_SEND_GUARD_HELD'] ?? '') === $ticket) {
        unset($GLOBALS['_JEAB_SEND_GUARD_HELD']);
    }
}

/**
 * Lägger ett blockerat mejl i kön. Samma rad vid samma kanal, mall,
 * mottagarhash och bodyhash. Skickar inte och anropar inte drain.
 *
 * @param array<string, mixed> $opts
 * @return array{ok:bool,path:string,error:null,id:string,queued:bool}|null
 */
function jeab_send_guard_queue_blocked(array $opts, string $recipient, string $body): ?array
{
    jeab_send_guard_use_app_timezone();
    $template = trim((string) ($opts['guard_template'] ?? 'email'));
    if ($template === '') {
        $template = 'email';
    }
    $template = mb_substr($template, 0, 64);
    $template = preg_replace('/[^A-Za-z0-9_\/.-]+/', '', $template) ?? '';
    if ($template === '') {
        $template = 'email';
    }
    $bodyHash = trim((string) ($opts['guard_body_hash'] ?? ''));
    if ($bodyHash === '') {
        $bodyHash = hash('sha256', $body);
    }
    $channel = 'email';
    $rcptHash = jeab_send_guard_rcpt_hash($channel, $recipient);
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        jeab_send_guard_ensure($pdo);
        $stamp = jeab_send_guard_sql_stamp(jeab_send_guard_now());
        $existing = $pdo->prepare(
            'SELECT id FROM send_queue WHERE channel = ? AND rcpt_hash = ? AND body_hash = ? AND template = ? AND status = ? ORDER BY id ASC LIMIT 1'
        );
        $existing->execute([$channel, $rcptHash, $bodyHash, $template, 'waiting']);
        $id = (int) $existing->fetchColumn();
        if ($id < 1) {
            $encoded = base64_encode(serialize([
                'handler' => 'system_mail',
                'args' => ['opts' => $opts],
            ]));
            $pdo->prepare(
                'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $channel,
                $template,
                $rcptHash,
                $bodyHash,
                $stamp,
                $stamp,
                $encoded,
                'waiting',
            ]);
            $id = (int) $pdo->lastInsertId();
        }
        if ($id < 1) {
            return null;
        }
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('email', 'queued_after_block id=' . $id . ' template=' . $template, ['level' => 'info']);
        }

        return [
            'ok' => false,
            'path' => 'queued',
            'error' => null,
            'id' => (string) $id,
            'queued' => true,
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * @param array<string, mixed> $opts
 */
function jeab_send_guard_exclude_booking(array $opts): bool
{
    if (!array_key_exists('exclude_booking', $opts)) {
        return true;
    }
    $value = $opts['exclude_booking'];
    return !($value === false || $value === 0 || $value === '0');
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function jeab_send_guard_replay_booking_skip(array $job): ?array
{
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    $id = (int) ($args['booking_id'] ?? $opts['booking_id'] ?? 0);
    $starts = (string) ($args['starts_at'] ?? $opts['starts_at'] ?? '');
    if ($id < 1) {
        return null;
    }
    if (!function_exists('jeab_store')) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'booking-gone'];
    }
    try {
        $row = jeab_store()->getBooking($id);
    } catch (Throwable) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'booking-gone'];
    }
    if (!is_array($row) || ($row['status'] ?? '') === 'cancelled') {
        return ['ok' => true, 'skipped' => true, 'reason' => 'booking-gone'];
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $handler = (string) ($job['handler'] ?? '');
    $kind = (string) ($opts['booking_notice_kind'] ?? '');
    $smsNotice = $handler === 'sms' || $kind === 'sms';
    if ($smsNotice) {
        $past = function_exists('jeab_sms_starts_at_passed') && jeab_sms_starts_at_passed($row, $now);
    } elseif (function_exists('jeab_booking_mail_start_clearly_past')) {
        $past = jeab_booking_mail_start_clearly_past($row, $now);
    } else {
        $past = function_exists('jeab_sms_starts_at_passed') && jeab_sms_starts_at_passed($row, $now);
    }
    if ($past) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'booking-past'];
    }
    if ($starts !== '' && (string) ($row['starts_at'] ?? '') !== $starts) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'booking-moved'];
    }

    return null;
}

function jeab_send_guard_replay_record_booking(array $job, array $result): void
{
    if (!function_exists('jeab_sms_provider_delivered') || !jeab_sms_provider_delivered($result) || !empty($result['skipped'])) {
        return;
    }
    $handler = (string) ($job['handler'] ?? '');
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    $id = (int) ($args['booking_id'] ?? $opts['booking_id'] ?? 0);
    $starts = (string) ($args['starts_at'] ?? $opts['starts_at'] ?? '');
    if ($id < 1 || $starts === '' || !function_exists('jeab_sms_record_sent')) {
        return;
    }
    $row = [];
    if (function_exists('jeab_store')) {
        try {
            $found = jeab_store()->getBooking($id);
            if (is_array($found)) {
                $row = $found;
            }
        } catch (Throwable) {
            $row = [];
        }
    }
    $kind = (string) ($opts['booking_notice_kind'] ?? ($handler === 'sms' ? 'sms' : 'mail'));
    $done = function_exists('jeab_sms_sent_row') ? jeab_sms_sent_row($id, $starts) : ['starts_at' => $starts];
    if ($kind === 'sms' || $handler === 'sms') {
        $done['sms'] = true;
        $done['sms_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $done['starts_at'] = $starts;
        jeab_sms_record_sent($id, $done, $row, 'sms');
        return;
    }
    if ($kind === 'move') {
        $done['move_email'] = true;
        $done['move_email_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $done['move_pending'] = false;
        $done['move_starts_at'] = $starts;
        $done['starts_at'] = $starts;
        $done['notified_starts_at'] = $starts;
        $done['email'] = true;
        jeab_sms_record_sent($id, $done, $row, 'move');
        if (function_exists('jeab_booking_move_mail_queue_delete')) {
            jeab_booking_move_mail_queue_delete($id);
        }
        return;
    }
    $done['email'] = true;
    $done['email_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $done['starts_at'] = $starts;
    $done['notified_starts_at'] = $starts;
    if ($row !== []) {
        $done['created_at'] = (string) ($row['created_at'] ?? '');
        if (function_exists('jeab_booking_mail_last_changed')) {
            $done['email_version'] = jeab_booking_mail_last_changed($row);
        }
    }
    jeab_sms_record_sent($id, $done, $row, 'mail');
}

/**
 * Offertboken förs när jobbet faktiskt lämnat kön. Ett kastat fel lämnas till drain,
 * som markerar jobbet failed och släpper väntande utbox den vägen.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $result
 */
function jeab_send_guard_quote_hook(array $job, array $result): void
{
    if (!function_exists('jeab_quote_outbox_guard_replay')) {
        return;
    }
    try {
        jeab_quote_outbox_guard_replay($job, $result);
    } catch (Throwable) {
    }
}

/**
 * @param array<string, mixed> $job
 * @return array<string, mixed>
 */
function jeab_send_guard_replay_send(array $job): array
{
    $handler = (string) ($job['handler'] ?? '');
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    if ($handler === 'system_mail' && function_exists('jeab_system_mail_send')) {
        $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
        $result = jeab_system_mail_send($opts);
        jeab_send_guard_replay_record_booking($job, is_array($result) ? $result : []);

        return is_array($result) ? $result : ['ok' => false, 'error' => 'okänd kö'];
    }
    if ($handler === 'outbound' && function_exists('jeab_outbound_graph_send')) {
        return jeab_outbound_graph_send(
            (string) ($args['company'] ?? ''),
            (string) ($args['to'] ?? ''),
            (string) ($args['subject'] ?? ''),
            (string) ($args['body'] ?? ''),
            !empty($args['html']),
            is_array($args['attachments'] ?? null) ? $args['attachments'] : [],
            is_array($args['cc'] ?? null) ? $args['cc'] : [],
            is_array($args['opts'] ?? null) ? $args['opts'] : []
        );
    }
    if ($handler === 'graph_send' && function_exists('jeab_graph_send')) {
        return jeab_graph_send(
            (string) ($args['company'] ?? ''),
            (string) ($args['to'] ?? ''),
            (string) ($args['subject'] ?? ''),
            (string) ($args['text'] ?? ''),
            is_array($args['cc'] ?? null) ? $args['cc'] : [],
            is_array($args['opts'] ?? null) ? $args['opts'] : []
        );
    }
    if ($handler === 'graph_reply' && function_exists('jeab_graph_reply')) {
        return jeab_graph_reply(
            (string) ($args['company'] ?? ''),
            (string) ($args['graph_id'] ?? ''),
            (string) ($args['to'] ?? ''),
            is_array($args['cc'] ?? null) ? $args['cc'] : [],
            (string) ($args['text'] ?? ''),
            is_array($args['opts'] ?? null) ? $args['opts'] : []
        );
    }
    if ($handler === 'gp_send' && function_exists('jeab_gp_send')) {
        return jeab_gp_send(
            (string) ($args['email'] ?? ''),
            (string) ($args['to'] ?? ''),
            (string) ($args['subject'] ?? ''),
            (string) ($args['html'] ?? ''),
            is_array($args['attachments'] ?? null) ? $args['attachments'] : [],
            is_array($args['cc'] ?? null) ? $args['cc'] : [],
            is_array($args['opts'] ?? null) ? $args['opts'] : []
        );
    }
    if ($handler === 'email_hs' && function_exists('jeab_email_send')) {
        return jeab_email_send(
            (string) ($args['to'] ?? ''),
            (string) ($args['text'] ?? ''),
            isset($args['subject']) ? (string) $args['subject'] : null,
            isset($args['job_type']) ? (string) $args['job_type'] : null,
            is_array($args['attachments'] ?? null) ? $args['attachments'] : [],
            is_array($args['tags'] ?? null) ? $args['tags'] : ['kalender'],
            !array_key_exists('log_failure', $args) || !empty($args['log_failure']),
            isset($args['company']) ? (string) $args['company'] : null
        );
    }
    if ($handler === 'sms' && function_exists('jeab_sms_send')) {
        $result = jeab_sms_send(
            (string) ($args['to'] ?? ''),
            (string) ($args['text'] ?? ''),
            isset($args['company']) ? (string) $args['company'] : null,
            isset($args['from']) ? (string) $args['from'] : null,
            [
                'guard_template' => 'booking_notice',
                'booking_id' => (int) ($args['booking_id'] ?? 0),
                'starts_at' => (string) ($args['starts_at'] ?? ''),
            ]
        );
        jeab_send_guard_replay_record_booking($job, is_array($result) ? $result : []);

        return is_array($result) ? $result : ['ok' => false, 'error' => 'okänd kö'];
    }

    return ['ok' => false, 'error' => 'okänd kö'];
}

function jeab_send_guard_replay(array $job): array
{
    $skip = jeab_send_guard_replay_booking_skip($job);
    if ($skip !== null) {
        return $skip;
    }
    $GLOBALS['_JEAB_SEND_GUARD_REPLAY'] = true;
    try {
        $result = jeab_send_guard_replay_send($job);
    } finally {
        unset($GLOBALS['_JEAB_SEND_GUARD_REPLAY']);
    }
    jeab_send_guard_quote_hook($job, $result);

    return $result;
}

function jeab_send_guard_flag_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    $text = strtolower(trim((string) $value));
    return in_array($text, ['1', 'on', 'true', 'ja'], true);
}

/**
 * SEND_GUARD_TICK_DRAIN. Saknat värde är av. Global och env går före settings.
 *
 * @return array{enabled:bool,source:'global'|'env'|'settings'|'default'}
 */
function jeab_send_guard_tick_drain_detail(): array
{
    if (array_key_exists('SEND_GUARD_TICK_DRAIN', $GLOBALS)) {
        return [
            'enabled' => jeab_send_guard_flag_truthy($GLOBALS['SEND_GUARD_TICK_DRAIN']),
            'source' => 'global',
        ];
    }
    $env = getenv('SEND_GUARD_TICK_DRAIN');
    if (is_string($env) && $env !== '') {
        return [
            'enabled' => jeab_send_guard_flag_truthy($env),
            'source' => 'env',
        ];
    }
    try {
        if (!function_exists('jeab_settings_value')) {
            return ['enabled' => false, 'source' => 'default'];
        }
        $pdo = jeab_send_guard_pdo();
        if (!$pdo instanceof PDO) {
            return ['enabled' => false, 'source' => 'default'];
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        if (function_exists('jeab_settings_read')) {
            $read = jeab_settings_read($pdo, $driver, 'SEND_GUARD_TICK_DRAIN');
            if (empty($read['ok'])) {
                return ['enabled' => false, 'source' => 'default'];
            }

            return [
                'enabled' => jeab_send_guard_flag_truthy($read['value'] ?? null),
                'source' => 'settings',
            ];
        }

        return [
            'enabled' => jeab_send_guard_flag_truthy(jeab_settings_value($pdo, $driver, 'SEND_GUARD_TICK_DRAIN')),
            'source' => 'settings',
        ];
    } catch (Throwable) {
        return ['enabled' => false, 'source' => 'default'];
    }
}

/** SEND_GUARD_TICK_DRAIN. Saknat värde är av. Global och env går före settings. */
function jeab_send_guard_tick_drain_enabled(): bool
{
    return jeab_send_guard_tick_drain_detail()['enabled'];
}

function jeab_send_guard_diag_parse(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) !== 1) {
        return null;
    }
    $raw = str_replace('T', ' ', $raw);
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new DateTimeZone('Europe/Stockholm'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }

    return $dt;
}

/** Sessionens tidszon när den går att läsa. SYSTEM jämförs med NOW(). */
function jeab_send_guard_diag_zone(?string $sessionTz, ?string $dbNow): ?DateTimeZone
{
    $sessionTz = trim((string) $sessionTz);
    if ($sessionTz !== '' && strcasecmp($sessionTz, 'SYSTEM') !== 0) {
        try {
            return new DateTimeZone($sessionTz);
        } catch (Exception) {
            return null;
        }
    }
    $clock = substr(str_replace('T', ' ', trim((string) $dbNow)), 0, 19);
    $asUtc = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $clock, new DateTimeZone('UTC'));
    if (!$asUtc instanceof DateTimeImmutable) {
        return null;
    }
    $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if (abs($asUtc->getTimestamp() - $utcNow->getTimestamp()) <= 120) {
        return new DateTimeZone('UTC');
    }
    $asStock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $clock, new DateTimeZone('Europe/Stockholm'));
    $stockNow = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
    if ($asStock instanceof DateTimeImmutable && abs($asStock->getTimestamp() - $stockNow->getTimestamp()) <= 120) {
        return new DateTimeZone('Europe/Stockholm');
    }

    return null;
}

/**
 * @return array{0:string,1:string}
 */
function jeab_send_guard_diag_bounds(DateTimeImmutable $from, DateTimeImmutable $to, ?DateTimeZone $zone): array
{
    $compare = $zone ?? new DateTimeZone('Europe/Stockholm');

    return [
        $from->setTimezone($compare)->format('Y-m-d H:i:s'),
        $to->setTimezone($compare)->format('Y-m-d H:i:s'),
    ];
}

function jeab_send_guard_diag_stockholm(string $raw, ?DateTimeZone $zone): ?string
{
    if (!$zone instanceof DateTimeZone) {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', trim(str_replace('T', ' ', $raw)), $match) !== 1) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1], $zone);
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }

    return $dt->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('Y-m-d H:i:s');
}

/** Tar bort mejl, telefon och personnummer. Högst 200 tecken. */
function jeab_send_guard_diag_error(string $error): string
{
    $error = str_replace(["\r", "\n", "\t"], ' ', $error);
    $error = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '', $error) ?? '';
    $error = preg_replace('/(?:\+|00)?\d[\d\s\-]{7,}\d/', '', $error) ?? '';
    $error = preg_replace('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', '', $error) ?? '';
    $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');
    if (mb_strlen($error) > 200) {
        $error = mb_substr($error, 0, 200);
    }

    return $error;
}

/**
 * @return array{session_time_zone:?string,now:?string}
 */
function jeab_send_guard_db_timezone(PDO $pdo): array
{
    $empty = ['session_time_zone' => null, 'now' => null];
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return $empty;
    }
    try {
        $stmt = $pdo->query('SELECT @@session.time_zone AS session_time_zone, NOW() AS now_at');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return $empty;
        }
        $tz = trim((string) ($row['session_time_zone'] ?? ''));
        $now = trim((string) ($row['now_at'] ?? ''));

        return [
            'session_time_zone' => $tz !== '' ? $tz : null,
            'now' => $now !== '' ? $now : null,
        ];
    } catch (Throwable) {
        return $empty;
    }
}

/**
 * @param array<string, mixed> $out
 * @return array<string, mixed>
 */
function jeab_send_guard_diag_guard_payload(array $out): array
{
    $blocked = static function (array $node) use (&$blocked): bool {
        $secretKeys = [
            'recipient', 'address', 'body', 'phone', 'pnr', 'to', 'job', 'rcpt', 'email', 'rcpt_hash', 'body_hash',
        ];
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $secretKeys, true)) {
                return true;
            }
            if (is_array($value) && $blocked($value)) {
                return true;
            }
        }

        return false;
    };
    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $leaks = !is_string($encoded)
        || str_contains($encoded, '@')
        || preg_match('/(?:\+|00)?\d{8,}/', $encoded) === 1
        || preg_match('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', $encoded) === 1
        || $blocked($out);
    if (!$leaks) {
        return $out;
    }

    return [
        'ok' => false,
        'read_only' => true,
        'error' => 'Svaret stoppades.',
        'rows' => [],
        'tick_drain' => is_array($out['tick_drain'] ?? null) ? $out['tick_drain'] : ['enabled' => false, 'source' => 'default'],
        'db_timezone' => ['session_time_zone' => null, 'now' => null],
    ];
}

/** Inkorgstoken eller en inloggad admin. */
function jeab_send_guard_diag_authorized(): bool
{
    if (function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }

    return function_exists('jeab_auth_ok')
        && jeab_auth_ok()
        && function_exists('jeab_is_admin')
        && jeab_is_admin();
}

function jeab_send_guard_claim_stale_seconds(): int
{
    return 300;
}

function jeab_send_guard_claim_max_attempts(): int
{
    return 5;
}

function jeab_send_guard_claim_stale_stamp(?int $now = null): string
{
    return jeab_send_guard_sql_stamp(($now ?? jeab_send_guard_now()) - jeab_send_guard_claim_stale_seconds());
}

/**
 * Claimar ett väntande jobb, eller en sending-rad äldre än leverantörens timeout.
 * Tom token betyder att raden redan tagits.
 *
 * @return array{token:string,attempts:int}
 */
function jeab_send_guard_claim(PDO $pdo, int $id): array
{
    $empty = ['token' => '', 'attempts' => 0];
    if ($id < 1) {
        return $empty;
    }
    jeab_send_guard_ensure($pdo);
    $token = bin2hex(random_bytes(16));
    $now = jeab_send_guard_sql_stamp();
    $stale = jeab_send_guard_claim_stale_stamp();
    $max = jeab_send_guard_claim_max_attempts();
    $upd = $pdo->prepare(
        'UPDATE send_queue SET status = ?, claim_token = ?, claimed_at = ?, attempts = attempts + 1
         WHERE id = ? AND (
            status = ?
            OR (status = ? AND attempts < ? AND claimed_at <= ?)
         )'
    );
    $upd->execute(['sending', $token, $now, $id, 'waiting', 'sending', $max, $stale]);
    $check = $pdo->prepare('SELECT status, claim_token, attempts FROM send_queue WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $empty;
    }
    if ((string) ($row['status'] ?? '') !== 'sending' || (string) ($row['claim_token'] ?? '') !== $token) {
        return $empty;
    }
    return ['token' => $token, 'attempts' => (int) ($row['attempts'] ?? 0)];
}

function jeab_send_guard_settle(PDO $pdo, int $id, string $token, string $status, int $attempts, string $error): void
{
    if ($id < 1 || $token === '') {
        return;
    }
    $status = $status === 'sent' ? 'sent' : 'failed';
    $err = $status === 'failed' ? jeab_send_guard_public_error($error) : '';
    if ($status === 'failed' && $err === '') {
        $err = 'misslyckades';
    }
    if ($status === 'failed' && function_exists('jeab_quote_outbox_guard_drop_job')) {
        try {
            jeab_quote_outbox_guard_drop_job($id);
        } catch (Throwable) {
        }
    }
    $pdo->prepare(
        'UPDATE send_queue SET status = ?, claim_token = NULL, attempts = ?, last_error = ? WHERE id = ? AND claim_token = ? AND status = ?'
    )->execute([
        $status,
        max(0, $attempts),
        $err !== '' ? $err : null,
        $id,
        $token,
        'sending',
    ]);
}

/**
 * Tar bort ett köat jobb och släpper offertens väntande utbox-rad.
 * En rad som håller på att skickas (sending) lämnas.
 */
function jeab_send_guard_drop_job(int $id): bool
{
    if ($id < 1) {
        return false;
    }
    if (function_exists('jeab_quote_outbox_guard_drop_job')) {
        try {
            jeab_quote_outbox_guard_drop_job($id);
        } catch (Throwable) {
        }
    }
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        jeab_send_guard_ensure($pdo);
        $stmt = $pdo->prepare("DELETE FROM send_queue WHERE id = ? AND status IN ('waiting', 'planned', 'failed')");
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

function jeab_send_guard_public_error(string $error): string
{
    $error = str_replace(["\r", "\n", "\t"], ' ', $error);
    $error = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '', $error) ?? '';
    $error = preg_replace('/(?:\+|00)?\d[\d\s\-]{7,}\d/', '', $error) ?? '';
    $error = trim(preg_replace('/\s+/', ' ', $error) ?? '');
    if (strlen($error) > 160) {
        $error = substr($error, 0, 160);
    }
    return $error;
}

/**
 * @param array<string, mixed> $job
 */
function jeab_send_guard_job_opts(array $job): array
{
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    return $opts;
}

/**
 * @param array<string, mixed> $job
 */
function jeab_send_guard_template_tag(string $template, array $job): string
{
    $template = strtolower(trim($template));
    $template = preg_replace('/[^a-z0-9_-]+/', '', $template) ?? '';
    $tag = '';
    $opts = jeab_send_guard_job_opts($job);
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $tags = $opts['tags'] ?? $args['tags'] ?? [];
    if (is_array($tags)) {
        foreach ($tags as $candidate) {
            $candidate = strtolower(trim((string) $candidate));
            $candidate = preg_replace('/[^a-z0-9_-]+/', '', $candidate) ?? '';
            if ($candidate !== '') {
                $tag = $candidate;
                break;
            }
        }
    }
    if ($template !== '' && $tag !== '' && $tag !== $template) {
        return $template . '/' . $tag;
    }
    if ($template !== '') {
        return $template;
    }
    return $tag;
}

/**
 * @param array<string, mixed> $job
 */
function jeab_send_guard_audience(array $job): string
{
    $opts = jeab_send_guard_job_opts($job);
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $to = '';
    foreach (['to', 'email'] as $key) {
        $candidate = trim((string) ($opts[$key] ?? $args[$key] ?? ''));
        if ($candidate !== '' && str_contains($candidate, '@')) {
            $to = $candidate;
            break;
        }
    }
    if (preg_match('/<([^>]+)>/', $to, $match) === 1) {
        $to = trim($match[1]);
    }
    $to = strtolower($to);
    $domain = '';
    $at = strrpos($to, '@');
    if ($at !== false) {
        $domain = substr($to, $at + 1);
    }
    $internal = ['jarfalla.example.test', 'malmo.example.test', 'flowstack.example.test'];
    if ($domain !== '' && in_array($domain, $internal, true)) {
        return 'internal';
    }
    if ($to !== '' && function_exists('jeab_follow_internal_address') && jeab_follow_internal_address($to)) {
        return 'internal';
    }
    if ($to !== '' && function_exists('jeab_kontakt_email_is_staff_or_platform') && jeab_kontakt_email_is_staff_or_platform($to)) {
        return 'internal';
    }
    return 'customer';
}

/**
 * Betar av kön. exclude_booking är sant när nyckeln saknas.
 * Utan limit tas ett väntande jobb per kanal. limit (högst 5) är taket
 * för hela anropet, mejl först. Varje jobb claimas en gång före sändning.
 * sms-cron och jeab_sms_tick skickar exclude_booking false.
 *
 * @param array<string, mixed> $opts
 * @return array{ran:bool,sent:int,skipped_booking:int,email:int,sms:int,reason:string}
 */
function send_guard_drain(array $opts = []): array
{
    $out = ['ran' => false, 'sent' => 0, 'skipped_booking' => 0, 'email' => 0, 'sms' => 0, 'reason' => ''];
    $exclude = jeab_send_guard_exclude_booking($opts);
    if (!jeab_send_guard_sql_required()) {
        $out['reason'] = 'skip';
        return $out;
    }
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        $out['reason'] = 'sql';
        return $out;
    }
    $locked = false;
    try {
        jeab_send_guard_use_app_timezone();
        jeab_send_guard_ensure($pdo);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $got = $pdo->query("SELECT GET_LOCK('jeab_send_guard', 0)");
            $locked = $got !== false && (int) $got->fetchColumn() === 1;
            if (!$locked) {
                $out['reason'] = 'lock';
                return $out;
            }
        }
        $state = jeab_send_guard_state($pdo);
        if ((int) $state['killed'] === 1) {
            $out['reason'] = 'killed';
            return $out;
        }
        $now = jeab_send_guard_now();
        $stamp = jeab_send_guard_sql_stamp($now);
        $cutoff = jeab_send_guard_sql_stamp($now - 60);
        $updated = $pdo->prepare('UPDATE send_guard_state SET last_run = ? WHERE id = 1 AND (last_run IS NULL OR last_run <= ?)');
        $updated->execute([$stamp, $cutoff]);
        if ($updated->rowCount() !== 1) {
            $out['reason'] = 'lock';
            return $out;
        }
        $out['ran'] = true;
        if (!$exclude) {
            jeab_send_guard_promote_booking($pdo);
            foreach (['email', 'sms'] as $channel) {
                if (jeab_send_guard_waiting($pdo, $channel) > 20) {
                    jeab_send_guard_trip($pdo, $channel);
                    $out['reason'] = 'killed';
                    return $out;
                }
            }
        } else {
            $count = $pdo->prepare('SELECT COUNT(*) FROM send_queue WHERE status = ? AND template = ?');
            $count->execute(['planned', 'booking_notice']);
            $out['skipped_booking'] = (int) $count->fetchColumn();
        }
        $sharedCap = null;
        if (array_key_exists('limit', $opts)) {
            $sharedCap = max(1, min(5, (int) $opts['limit']));
        }
        $taken = 0;
        $wall = isset($opts['deadline']) && is_numeric($opts['deadline']) ? (float) $opts['deadline'] : null;
        $hrDeadline = hrtime(true) + 2000000000;
        foreach (['email', 'sms'] as $channel) {
            $room = $sharedCap === null ? 1 : ($sharedCap - $taken);
            if ($room < 1) {
                break;
            }
            $burst = false;
            for ($n = 0; $n < $room; $n++) {
                if ($wall !== null) {
                    if (microtime(true) > $wall) {
                        break 2;
                    }
                } elseif (hrtime(true) > $hrDeadline) {
                    break 2;
                }
                $pick = $pdo->prepare(
                    'SELECT id, template, not_before, job, attempts FROM send_queue
                     WHERE channel = ? AND (
                        status = ?
                        OR (status = ? AND attempts < ? AND claimed_at <= ?)
                     ) ORDER BY id ASC LIMIT 1'
                );
                $pick->execute([
                    $channel,
                    'waiting',
                    'sending',
                    jeab_send_guard_claim_max_attempts(),
                    jeab_send_guard_claim_stale_stamp($now),
                ]);
                $row = $pick->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    break;
                }
                $due = jeab_send_guard_stamp((string) ($row['not_before'] ?? ''));
                if ($due !== null && $due > $now) {
                    break;
                }
                if (!$burst) {
                    $latest = jeab_send_guard_latest_auto($pdo, $channel);
                    if ($latest !== null && $latest > $now - 300) {
                        break;
                    }
                    if ($sharedCap !== null) {
                        $burst = true;
                    }
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) {
                    break;
                }
                $claimed = jeab_send_guard_claim($pdo, $id);
                if ($claimed['token'] === '') {
                    break;
                }
                $raw = base64_decode((string) ($row['job'] ?? ''), true);
                $job = is_string($raw) ? unserialize($raw, ['allowed_classes' => false]) : false;
                if (!is_array($job)) {
                    jeab_send_guard_settle($pdo, $id, $claimed['token'], 'failed', $claimed['attempts'], 'ogiltigt jobb');
                    $taken++;
                    continue;
                }
                try {
                    $sent = jeab_send_guard_replay($job);
                } catch (Throwable) {
                    jeab_send_guard_settle($pdo, $id, $claimed['token'], 'failed', $claimed['attempts'], 'misslyckades');
                    $taken++;
                    continue;
                }
                if (empty($sent['ok'])) {
                    $why = jeab_send_guard_public_error((string) ($sent['error'] ?? ''));
                    jeab_send_guard_settle($pdo, $id, $claimed['token'], 'failed', $claimed['attempts'], $why !== '' ? $why : 'misslyckades');
                    $taken++;
                    continue;
                }
                jeab_send_guard_settle($pdo, $id, $claimed['token'], 'sent', $claimed['attempts'], '');
                jeab_send_guard_log($pdo, $channel, 'auto', 'system', 'guard', 'sent', '', '');
                $out['sent']++;
                $out[$channel] = (int) ($out[$channel] ?? 0) + 1;
                $taken++;
            }
        }
        return $out;
    } catch (Throwable) {
        $out['ran'] = false;
        $out['reason'] = 'error';
        return $out;
    } finally {
        if ($locked) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('jeab_send_guard')");
            } catch (Throwable) {
            }
        }
    }
}

function jeab_send_guard_schedule(): void
{
    if (!empty($GLOBALS['_JEAB_SEND_GUARD_SCHEDULED'])) {
        return;
    }
    $GLOBALS['_JEAB_SEND_GUARD_SCHEDULED'] = true;
    register_shutdown_function('jeab_send_guard_finish');
}

function jeab_send_guard_finish(): void
{
    $prevBg = array_key_exists('_JEAB_PDO_BACKGROUND', $GLOBALS) ? $GLOBALS['_JEAB_PDO_BACKGROUND'] : null;
    $GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
    try {
        if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $stub = $GLOBALS['_JEAB_SEND_GUARD_DRAIN'] ?? null;
        if ($stub instanceof Closure) {
            $stub();
            return;
        }
        send_guard_drain();
    } catch (Throwable) {
    } finally {
        if (function_exists('jeab_pdo_release')) {
            jeab_pdo_release();
        }
        if ($prevBg === null) {
            unset($GLOBALS['_JEAB_PDO_BACKGROUND']);
        } else {
            $GLOBALS['_JEAB_PDO_BACKGROUND'] = $prevBg;
        }
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_send_guard_status(): array
{
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return [
            'ok' => false,
            'read_only' => true,
            'error' => 'databas',
            'killed' => false,
            'killed_at' => '',
            'email_queue' => 0,
            'sms_queue' => 0,
            'last_run' => '',
        ];
    }
    try {
        $state = jeab_send_guard_state($pdo);
        return [
            'ok' => true,
            'read_only' => true,
            'killed' => (int) $state['killed'] === 1,
            'killed_at' => $state['killed_at'],
            'email_queue' => jeab_send_guard_waiting($pdo, 'email'),
            'sms_queue' => jeab_send_guard_waiting($pdo, 'sms'),
            'email_planned' => jeab_send_guard_planned($pdo, 'email'),
            'sms_planned' => jeab_send_guard_planned($pdo, 'sms'),
            'last_run' => $state['last_run'],
        ];
    } catch (Throwable) {
        return ['ok' => false, 'read_only' => true, 'error' => 'databas', 'killed' => false, 'email_queue' => 0, 'sms_queue' => 0];
    }
}

/**
 * Väntande och misslyckade mejl. Inga adresser och ingen brödtext.
 *
 * @return list<array{id:int,created:string,template:string,audience:string,attempts:int,error:string,status:string}>
 */
function jeab_send_guard_peek_rows(PDO $pdo): array
{
    jeab_send_guard_ensure($pdo);
    $stmt = $pdo->query(
        "SELECT id, template, created_at, attempts, last_error, job, status FROM send_queue WHERE channel = 'email' AND status IN ('waiting', 'failed') ORDER BY id ASC LIMIT 50"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = base64_decode((string) ($row['job'] ?? ''), true);
        $job = is_string($raw) ? unserialize($raw, ['allowed_classes' => false]) : false;
        $job = is_array($job) ? $job : [];
        $status = (string) ($row['status'] ?? '');
        if ($status !== 'waiting' && $status !== 'failed') {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'created' => (string) ($row['created_at'] ?? ''),
            'template' => jeab_send_guard_template_tag((string) ($row['template'] ?? ''), $job),
            'audience' => jeab_send_guard_audience($job),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'error' => jeab_send_guard_public_error((string) ($row['last_error'] ?? '')),
            'status' => $status,
        ];
    }
    return $out;
}

/**
 * Staff-hook send_guard.peek. Ignorerar indata. Skriver inget.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_send_guard_peek_hook(array $in): array
{
    unset($in);
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'read_only' => true, 'error' => 'databas', 'jobs' => []];
    }
    try {
        $jobs = jeab_send_guard_peek_rows($pdo);
    } catch (Throwable) {
        return ['ok' => false, 'read_only' => true, 'error' => 'databas', 'jobs' => []];
    }
    $out = ['ok' => true, 'read_only' => true, 'jobs' => $jobs];
    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || str_contains($encoded, '@') || preg_match('/(?:\+|00)?\d{8,}/', $encoded) === 1) {
        return ['ok' => false, 'read_only' => true, 'error' => 'Svaret stoppades.', 'jobs' => []];
    }
    return $out;
}

/**
 * @return array{id:string,name:string,username:string}|null
 */
function jeab_send_guard_diag_person(string $userId): ?array
{
    if (!function_exists('jeab_people')) {
        return null;
    }
    foreach (jeab_people() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (strcasecmp((string) ($row['id'] ?? ''), $userId) !== 0) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        $username = trim((string) ($row['username'] ?? ''));
        if (str_contains($name, '@')) {
            $name = '';
        }
        if (str_contains($username, '@')) {
            $username = '';
        }

        return [
            'id' => (string) ($row['id'] ?? $userId),
            'name' => $name,
            'username' => $username,
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
        ];
    }

    return null;
}

/**
 * @param array{id:string,name:string,username:string,email?:string}|null $person
 * @return array{id:string,name:string,username:string}
 */
function jeab_send_guard_diag_user_public(?array $person, string $userId): array
{
    if ($person === null) {
        return ['id' => $userId, 'name' => '', 'username' => ''];
    }

    return [
        'id' => (string) $person['id'],
        'name' => (string) $person['name'],
        'username' => (string) $person['username'],
    ];
}

function jeab_send_guard_diag_actor_label(string $actor): string
{
    $actor = trim($actor);
    if ($actor === '') {
        return '';
    }
    if (str_contains($actor, '@') && function_exists('jeab_people')) {
        foreach (jeab_people() as $row) {
            if (!is_array($row) || strcasecmp((string) ($row['email'] ?? ''), $actor) !== 0) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && !str_contains($name, '@')) {
                return $name;
            }
            $username = trim((string) ($row['username'] ?? ''));
            if ($username !== '' && !str_contains($username, '@')) {
                return $username;
            }

            return '';
        }

        return '';
    }
    $clean = jeab_send_guard_diag_error($actor);
    if ($clean === '' || str_contains($clean, '@')) {
        return '';
    }

    return $clean;
}

/**
 * @param array<string, mixed> $row
 */
function jeab_send_guard_diag_activity_hit(array $row, array $person): bool
{
    $id = (string) ($person['id'] ?? '');
    $name = (string) ($person['name'] ?? '');
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    $idKeys = ['me', 'user_id', 'person_id', 'actor_id'];
    $nameKeys = ['who', 'person', 'actor', 'assignee', 'name'];
    $walk = static function (array $node) use (&$walk, $id, $name, $email, $idKeys, $nameKeys): bool {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                if ($walk($value)) {
                    return true;
                }
                continue;
            }
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }
            $lk = strtolower($key);
            if (in_array($lk, $idKeys, true) && strcasecmp($text, $id) === 0) {
                return true;
            }
            if ($lk === 'email' && $email !== '' && strtolower($text) === $email) {
                return true;
            }
            if (in_array($lk, $nameKeys, true) && ($text === $name || strcasecmp($text, $id) === 0)) {
                return true;
            }
        }

        return false;
    };

    return $walk($row);
}

/**
 * @param array<string, mixed> $row
 */
function jeab_send_guard_diag_activity_text(array $row): string
{
    $bits = [];
    $take = static function (array $node) use (&$take, &$bits): void {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $take($value);
                continue;
            }
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (!in_array(strtolower($key), ['note', 'message', 'text', 'body'], true)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                $bits[] = $text;
            }
        }
    };
    $take($row);

    return jeab_send_guard_diag_error(implode(' ', $bits));
}

function jeab_send_guard_diag_in_window(string $raw, DateTimeImmutable $from, DateTimeImmutable $to): bool
{
    $stamp = jeab_send_guard_stamp($raw);
    if ($stamp === null) {
        return false;
    }

    return $stamp >= $from->getTimestamp() && $stamp <= $to->getTimestamp();
}

/**
 * who_diag, reminder_follow och reminder_hook. Bara SELECT.
 *
 * @param array{id:string,name:string,username:string,email?:string} $person
 * @return list<array<string, mixed>>
 */
function jeab_send_guard_diag_activity(PDO $pdo, array $person, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT kind, at, payload FROM log_lines WHERE kind IN (?, ?, ?) ORDER BY id ASC LIMIT 500'
        );
        $stmt->execute(['who_diag', 'reminder_follow', 'reminder_hook']);
        $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($fetched as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $kind = (string) ($raw['kind'] ?? '');
        $payload = json_decode((string) ($raw['payload'] ?? ''), true);
        if (!is_array($payload) || !jeab_send_guard_diag_activity_hit($payload, $person)) {
            continue;
        }
        $time = trim((string) ($payload['at'] ?? $payload['time'] ?? $raw['at'] ?? ''));
        if (!jeab_send_guard_diag_in_window($time, $from, $to)) {
            continue;
        }
        $page = jeab_send_guard_diag_error(trim((string) ($payload['page'] ?? '')));
        $action = trim((string) ($payload['action'] ?? ''));
        if ($action === '') {
            $action = trim((string) ($payload['m'] ?? $payload['intent'] ?? ''));
        }
        $action = jeab_send_guard_diag_error($action);
        $row = ['time' => $time];
        if ($page !== '') {
            $row['page'] = $page;
        }
        if ($action !== '') {
            $row['action'] = $action;
        }
        $thread = trim((string) ($payload['thread_id'] ?? $payload['case_id'] ?? ''));
        if ($thread !== '' && !str_contains($thread, '@')) {
            $row['thread_id'] = $thread;
        }
        if ($kind === 'reminder_follow' || $kind === 'reminder_hook') {
            $reminder = (int) ($payload['reminder_id'] ?? $payload['id'] ?? 0);
            if ($reminder > 0) {
                $row['reminder_id'] = $reminder;
            }
        }
        $message = jeab_send_guard_diag_activity_text($payload);
        if ($message !== '') {
            $row['message'] = $message;
        }
        $out[] = $row;
        if (count($out) >= 50) {
            break;
        }
    }

    return $out;
}

/**
 * En inbox_sent-rad. Tid, mall, aktör och kanal. Ingen mottagare.
 *
 * @return array{time:string,template:string,actor:string,channel:string}|null
 */
function jeab_send_guard_diag_sent(PDO $pdo, string $thread, DateTimeImmutable $from, DateTimeImmutable $to): ?array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT created_at, kind, actor, channel FROM inbox_sent WHERE thread_id = ? ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([$thread]);
        $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    foreach ($fetched as $row) {
        if (!is_array($row)) {
            continue;
        }
        $time = trim((string) ($row['created_at'] ?? ''));
        if (!jeab_send_guard_diag_in_window($time, $from, $to)) {
            continue;
        }
        $template = jeab_send_guard_diag_error(trim((string) ($row['kind'] ?? '')));
        $channel = jeab_send_guard_diag_error(trim((string) ($row['channel'] ?? '')));

        return [
            'time' => $time,
            'template' => $template,
            'actor' => jeab_send_guard_diag_actor_label((string) ($row['actor'] ?? '')),
            'channel' => $channel,
        ];
    }

    return null;
}

/**
 * Staff-hook send_guard.queue_diag. from och to är Europe/Stockholm.
 * Bara läsning: ingen skrivning, inget utskick, ingen avbetning, ingen flagga.
 * user_id och sent_thread är valfria och läser samma fönster.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_send_guard_queue_diag_hook(array $in): array
{
    $fail = static function (string $error, array $extra = []): array {
        return array_merge([
            'ok' => false,
            'read_only' => true,
            'error' => $error,
            'rows' => [],
        ], $extra);
    };
    $from = jeab_send_guard_diag_parse((string) ($in['from'] ?? ''));
    $to = jeab_send_guard_diag_parse((string) ($in['to'] ?? ''));
    if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable || $from > $to) {
        return $fail('from och to ska vara tider.');
    }
    $status = trim((string) ($in['status'] ?? ''));
    if ($status !== '' && preg_match('/^[a-z][a-z0-9_]{0,31}$/', $status) !== 1) {
        return $fail('Ogiltig status.');
    }
    $tick = jeab_send_guard_tick_drain_detail();
    $tzInfo = ['session_time_zone' => null, 'now' => null];
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return $fail('databas', ['tick_drain' => $tick, 'db_timezone' => $tzInfo]);
    }
    try {
        $tzInfo = jeab_send_guard_db_timezone($pdo);
        $zone = jeab_send_guard_diag_zone($tzInfo['session_time_zone'], $tzInfo['now']);
        // created_at är en naiv DATETIME som PHP skriver som Stockholm-klocka.
        // Fönstret jämförs mot den klockan. created_at_stockholm räknas om
        // bara när sessionens tidszon går att läsa.
        [$fromSql, $toSql] = jeab_send_guard_diag_bounds($from, $to, null);
        $sql = 'SELECT id, channel, template, status, created_at, attempts, last_error FROM send_queue WHERE created_at >= ? AND created_at <= ?';
        $params = [$fromSql, $toSql];
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $fail('databas', ['tick_drain' => $tick, 'db_timezone' => $tzInfo]);
    }
    $rows = [];
    foreach ($fetched as $row) {
        if (!is_array($row)) {
            continue;
        }
        $created = (string) ($row['created_at'] ?? '');
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'channel' => (string) ($row['channel'] ?? ''),
            'template' => (string) ($row['template'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'created_at' => $created,
            'created_at_stockholm' => jeab_send_guard_diag_stockholm($created, $zone),
            'attempts' => (int) ($row['attempts'] ?? 0),
            'last_error' => jeab_send_guard_diag_error((string) ($row['last_error'] ?? '')),
        ];
    }
    $out = [
        'ok' => true,
        'read_only' => true,
        'tick_drain' => [
            'enabled' => (bool) ($tick['enabled'] ?? false),
            'source' => (string) ($tick['source'] ?? 'default'),
        ],
        'db_timezone' => [
            'session_time_zone' => $tzInfo['session_time_zone'],
            'now' => $tzInfo['now'],
        ],
        'rows' => $rows,
    ];
    $userId = trim((string) ($in['user_id'] ?? ''));
    if ($userId !== '') {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $userId) !== 1) {
            return $fail('Ogiltigt user_id.');
        }
        $person = jeab_send_guard_diag_person($userId);
        $out['user'] = jeab_send_guard_diag_user_public($person, $userId);
        $out['activity'] = $person === null ? [] : jeab_send_guard_diag_activity($pdo, $person, $from, $to);
    }
    $sentThread = trim((string) ($in['sent_thread'] ?? ''));
    if ($sentThread !== '') {
        if (preg_match('/^[A-Za-z0-9_-]{1,191}$/', $sentThread) !== 1) {
            return $fail('Ogiltig sent_thread.');
        }
        $out['sent'] = jeab_send_guard_diag_sent($pdo, $sentThread, $from, $to);
    }

    return jeab_send_guard_diag_guard_payload($out);
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_send_guard_status_hook(array $in): array
{
    unset($in);
    $status = jeab_send_guard_status();
    $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || str_contains($encoded, '@')) {
        return ['ok' => false, 'read_only' => true, 'error' => 'Svaret stoppades.'];
    }
    return $status;
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_send_guard_reset_hook(array $in): array
{
    $confirm = trim((string) ($in['confirm'] ?? ''));
    if ($confirm !== 'SAM') {
        return ['ok' => false, 'error' => 'Bekräfta med SAM.'];
    }
    $choice = strtolower(trim((string) ($in['queue'] ?? $in['choice'] ?? '')));
    $release = in_array($choice, ['release', 'släpp', 'slapp'], true);
    $discard = in_array($choice, ['discard', 'kasta'], true);
    if (!$release && !$discard) {
        return ['ok' => false, 'error' => 'Välj släpp eller kasta.'];
    }
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'databas'];
    }
    try {
        jeab_send_guard_ensure($pdo);
        if ($discard) {
            $waiting = $pdo->query("SELECT id FROM send_queue WHERE status IN ('waiting', 'planned')");
            foreach ($waiting ? $waiting->fetchAll(PDO::FETCH_COLUMN) : [] as $rawId) {
                $jobId = (int) $rawId;
                if ($jobId < 1 || !function_exists('jeab_quote_outbox_guard_drop_job')) {
                    continue;
                }
                try {
                    jeab_quote_outbox_guard_drop_job($jobId);
                } catch (Throwable) {
                }
            }
            $pdo->exec("DELETE FROM send_queue WHERE status IN ('waiting', 'planned')");
        }
        $pdo->exec('UPDATE send_guard_state SET killed = 0, killed_at = NULL, alarm_at = NULL WHERE id = 1');
        jeab_send_guard_mirror_kill(false);
        $status = jeab_send_guard_status();
        $status['ok'] = true;
        $status['read_only'] = false;
        $status['queue'] = $discard ? 'discard' : 'release';
        return $status;
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'databas'];
    }
}

/**
 * @return list<string>
 */
function jeab_send_guard_scan_transports(string $root): array
{
    $allowed = [
        'src/sms.php' => true,
        'src/system_mail.php' => true,
        'src/inkorg_outbound.php' => true,
        'src/inkorg_graph.php' => true,
        'src/graph_person.php' => true,
    ];
    $hits = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($root, '/')))), '/');
        if (
            str_starts_with($rel, 'tests/')
            || str_starts_with($rel, 'vendor/')
            || str_contains($rel, '/vendor/')
            || $rel === 'src/send_guard.php'
            || $rel === 'src/send_guard_brake.php'
        ) {
            continue;
        }
        $code = (string) file_get_contents($path);
        $lines = preg_split('/\R/', $code) ?: [];
        $fileHits = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $trim = ltrim($lines[$i]);
            if ($trim === '' || str_starts_with($trim, '//') || str_starts_with($trim, '#') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
                continue;
            }
            if (str_contains($lines[$i], 'sendsms.aspx')) {
                $fileHits[] = 'sms';
            }
            if (str_contains($lines[$i], 'api.helpscout.net/v2/conversations')) {
                $fileHits[] = 'helpscout';
            }
            if (preg_match('/(?<![A-Za-z0-9_])mail\s*\(/', $lines[$i]) === 1) {
                $fileHits[] = 'mail';
            }
            if (str_contains($lines[$i], 'jeab_graph_api') && (str_contains($lines[$i], 'sendMail') || str_contains($lines[$i], '/reply'))) {
                $fileHits[] = 'graph';
            }
        }
        if ($fileHits === []) {
            continue;
        }
        if (!isset($allowed[$rel]) || !str_contains($code, 'send_guard_check')) {
            $hits[] = $rel . ':' . implode(',', array_values(array_unique($fileHits)));
        }
    }
    sort($hits);
    return $hits;
}

/** Misslyckat utskick ska inte sparas som redan skickat. */
class JeabSendOnceMiss extends RuntimeException
{
}

function jeab_file_lock(string $lockPath, callable $fn): mixed
{
    if (!empty($GLOBALS['_JEAB_FILE_LOCKS'][$lockPath])) {
        return $fn();
    }
    $dir = dirname($lockPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte låsa.');
    }
    $fh = fopen($lockPath, 'c');
    if ($fh === false) {
        throw new RuntimeException('Kunde inte låsa.');
    }
    @chmod($lockPath, 0600);
    $GLOBALS['_JEAB_FILE_LOCKS'][$lockPath] = true;
    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Kunde inte låsa.');
        }
        return $fn();
    } finally {
        unset($GLOBALS['_JEAB_FILE_LOCKS'][$lockPath]);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Ett lås per påminnelse. Samma anrop får gå in igen utan att släppa låset. */
function jeab_reminder_lock(int $id, callable $fn): mixed
{
    if ($id <= 0) {
        return $fn();
    }
    if (!empty($GLOBALS['_JEAB_REMINDER_LOCKS'][$id])) {
        return $fn();
    }
    $GLOBALS['_JEAB_REMINDER_LOCKS'][$id] = true;
    try {
        return jeab_file_lock(jeab_data_dir() . '/locks/reminder-' . $id . '.lock', $fn);
    } finally {
        unset($GLOBALS['_JEAB_REMINDER_LOCKS'][$id]);
    }
}

function jeab_send_once_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

/** Appens databas. Utan PDO (json-läge i tester) en egen sqlite-fil, inte en json-fil. */
function jeab_send_once_pdo(): ?PDO
{
    if (array_key_exists('_JEAB_SEND_ONCE_PDO', $GLOBALS)) {
        $pdo = $GLOBALS['_JEAB_SEND_ONCE_PDO'];

        return $pdo instanceof PDO ? $pdo : null;
    }
    if (function_exists('jeab_send_guard_pdo')) {
        $pdo = jeab_send_guard_pdo();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('jeab_data_dir')) {
        return null;
    }
    $path = jeab_data_dir() . '/send-once.sqlite';
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Skapar tabellen en gång och sätter settings-flaggan send_once_table.
 * När flaggan är 1 körs ingen DDL.
 */
function jeab_send_once_install(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    $driver = jeab_send_once_driver($pdo);
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'send_once_table') === '1') {
        $done[$id] = true;

        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    try {
        $pdo->query('SELECT 1 FROM settings LIMIT 1');
    } catch (Throwable) {
        if ($driver === 'mysql') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                    `key` VARCHAR(64) NOT NULL,
                    value TEXT NOT NULL,
                    PRIMARY KEY (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
        }
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS send_once (
                idem_key CHAR(64) NOT NULL,
                bucket VARCHAR(32) NOT NULL,
                claim CHAR(16) NOT NULL,
                expires_at INT UNSIGNED NOT NULL,
                result MEDIUMTEXT NOT NULL,
                PRIMARY KEY (idem_key),
                KEY send_once_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS send_once (
                idem_key TEXT PRIMARY KEY,
                bucket TEXT NOT NULL,
                claim TEXT NOT NULL,
                expires_at INTEGER NOT NULL,
                result TEXT NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS send_once_expires ON send_once (expires_at)');
    }
    if (function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, $driver, 'send_once_table', '1');
    }
    $done[$id] = true;
}

function jeab_send_once_ready(PDO $pdo): void
{
    static $pragma = [];
    $id = spl_object_id($pdo);
    if (!isset($pragma[$id]) && jeab_send_once_driver($pdo) !== 'mysql') {
        $pragma[$id] = true;
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    jeab_send_once_install($pdo);
}

/** @param list<string> $keys */
function jeab_send_once_in(array $keys): string
{
    return implode(',', array_fill(0, count($keys), '?'));
}

/**
 * @param list<string> $keys
 * @return list<array<string, mixed>>
 */
function jeab_send_once_rows(PDO $pdo, array $keys): array
{
    if ($keys === []) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT idem_key, claim, expires_at, result FROM send_once WHERE idem_key IN (' . jeab_send_once_in($keys) . ')');
    $stmt->execute(array_values($keys));
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

/** @param array<string, mixed>|null $result */
function jeab_send_once_pack(?array $result): string
{
    if ($result === null) {
        return '';
    }
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

/** @return array<string, mixed>|null */
function jeab_send_once_unpack(string $json): ?array
{
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}

function jeab_send_once_upsert_claim(PDO $pdo, string $driver, string $bucket, string $key, string $claim, int $expires, int $now): void
{
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO send_once (idem_key, bucket, claim, expires_at, result) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                bucket = IF(expires_at < ?, VALUES(bucket), bucket),
                claim = IF(expires_at < ?, VALUES(claim), claim),
                result = IF(expires_at < ?, ?, result),
                expires_at = IF(expires_at < ?, VALUES(expires_at), expires_at)';
        $pdo->prepare($sql)->execute([$key, $bucket, $claim, $expires, '', $now, $now, $now, '', $now]);

        return;
    }
    $sql = 'INSERT INTO send_once (idem_key, bucket, claim, expires_at, result) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(idem_key) DO UPDATE SET
            bucket = CASE WHEN expires_at < ? THEN excluded.bucket ELSE bucket END,
            claim = CASE WHEN expires_at < ? THEN excluded.claim ELSE claim END,
            result = CASE WHEN expires_at < ? THEN ? ELSE result END,
            expires_at = CASE WHEN expires_at < ? THEN excluded.expires_at ELSE expires_at END';
    $pdo->prepare($sql)->execute([$key, $bucket, $claim, $expires, '', $now, $now, $now, '', $now]);
}

function jeab_send_once_upsert_result(PDO $pdo, string $driver, string $bucket, string $key, string $claim, int $expires, string $result): void
{
    if ($driver === 'mysql') {
        $sql = 'INSERT INTO send_once (idem_key, bucket, claim, expires_at, result) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE bucket = VALUES(bucket), claim = VALUES(claim), expires_at = VALUES(expires_at), result = VALUES(result)';
    } else {
        $sql = 'INSERT INTO send_once (idem_key, bucket, claim, expires_at, result) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(idem_key) DO UPDATE SET bucket = excluded.bucket, claim = excluded.claim, expires_at = excluded.expires_at, result = excluded.result';
    }
    $pdo->prepare($sql)->execute([$key, $bucket, $claim, $expires, $result]);
}

/** @param list<string> $keys */
function jeab_send_once_release(PDO $pdo, array $keys, string $claim): void
{
    if ($keys === []) {
        return;
    }
    $stmt = $pdo->prepare('DELETE FROM send_once WHERE claim = ? AND result = ? AND idem_key IN (' . jeab_send_once_in($keys) . ')');
    $stmt->execute(array_merge([$claim, ''], array_values($keys)));
}

/**
 * @param list<string> $keys
 * @return array{state: string, result: array<string, mixed>}
 */
function jeab_send_once_reserve(PDO $pdo, string $bucket, array $keys, int $seconds, string $claim): array
{
    $driver = jeab_send_once_driver($pdo);
    $now = time();
    $expires = $now + $seconds;
    $pdo->beginTransaction();
    try {
        foreach ($keys as $key) {
            jeab_send_once_upsert_claim($pdo, $driver, $bucket, $key, $claim, $expires, $now);
        }
        $own = true;
        $duplicate = null;
        foreach (jeab_send_once_rows($pdo, $keys) as $row) {
            if (!is_array($row)) {
                $own = false;
                continue;
            }
            $live = (int) ($row['expires_at'] ?? 0) >= $now;
            $mine = (string) ($row['claim'] ?? '') === $claim;
            if (!$mine) {
                $own = false;
            }
            if ($live && !$mine) {
                $decoded = jeab_send_once_unpack((string) ($row['result'] ?? ''));
                if ($decoded !== null) {
                    $duplicate = $decoded;
                }
            }
        }
        if (!$own) {
            jeab_send_once_release($pdo, $keys, $claim);
        }
        $pdo->commit();
        if (!$own) {
            return ['state' => $duplicate !== null ? 'duplicate' : 'busy', 'result' => $duplicate ?? []];
        }

        return ['state' => 'owned', 'result' => []];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param list<string> $keys
 * @return array<string, mixed>|null
 */
function jeab_send_once_wait(PDO $pdo, array $keys, int $seconds): ?array
{
    $until = microtime(true) + min(15, max(1, $seconds));
    while (microtime(true) < $until) {
        usleep(40000);
        $hit = jeab_send_once_live($pdo, $keys);
        if ($hit !== null) {
            return $hit;
        }
        if (!jeab_send_once_pending($pdo, $keys)) {
            return null;
        }
    }

    return null;
}

/**
 * @param list<string> $keys
 * @return array<string, mixed>|null
 */
function jeab_send_once_live(PDO $pdo, array $keys): ?array
{
    $now = time();
    foreach (jeab_send_once_rows($pdo, $keys) as $row) {
        if (!is_array($row) || (int) ($row['expires_at'] ?? 0) < $now) {
            continue;
        }
        $decoded = jeab_send_once_unpack((string) ($row['result'] ?? ''));
        if ($decoded !== null) {
            return $decoded;
        }
    }

    return null;
}

/** @param list<string> $keys */
function jeab_send_once_pending(PDO $pdo, array $keys): bool
{
    $now = time();
    foreach (jeab_send_once_rows($pdo, $keys) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int) ($row['expires_at'] ?? 0) >= $now && (string) ($row['result'] ?? '') === '') {
            return true;
        }
    }

    return false;
}

/** @param list<string> $keys */
function jeab_send_once_finish(PDO $pdo, string $bucket, array $keys, string $claim, int $seconds, ?array $result): void
{
    if ($result === null) {
        jeab_send_once_release($pdo, $keys, $claim);

        return;
    }
    $packed = jeab_send_once_pack($result);
    if ($packed === '') {
        jeab_send_once_release($pdo, $keys, $claim);

        return;
    }
    $driver = jeab_send_once_driver($pdo);
    $expires = time() + max(1, $seconds);
    foreach ($keys as $key) {
        jeab_send_once_upsert_result($pdo, $driver, $bucket, $key, $claim, $expires, $packed);
    }
}

function jeab_send_once_purge(): int
{
    $pdo = jeab_send_once_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_value')) {
        return 0;
    }
    $driver = jeab_send_once_driver($pdo);
    if (jeab_settings_value($pdo, $driver, 'send_once_table') !== '1') {
        return 0;
    }
    $stmt = $pdo->prepare('DELETE FROM send_once WHERE expires_at < ?');
    $stmt->execute([time()]);

    return $stmt->rowCount();
}

/**
 * @param list<string>|string $keys
 * @return array<string, mixed>|null
 */
function jeab_send_once_peek(string $bucket, array|string $keys, int $seconds): ?array
{
    unset($bucket, $seconds);
    $keys = jeab_send_once_keys($keys);
    if ($keys === []) {
        return null;
    }
    $pdo = jeab_send_once_pdo();
    if (!$pdo instanceof PDO) {
        return null;
    }
    jeab_send_once_ready($pdo);

    return jeab_send_once_live($pdo, $keys);
}

/**
 * Kör $work en gång per nyckel inom $seconds.
 * Unik nyckel reserveras med INSERT ... ON DUPLICATE. Pågår ett utskick väntar nästa.
 * JeabSendOnceMiss och andra fel sparar inget.
 *
 * @param list<string>|string $keys
 * @param callable(): array<string, mixed> $work
 * @return array{duplicate: bool, miss: bool, result: array<string, mixed>}
 */
function jeab_send_once(string $bucket, array|string $keys, int $seconds, callable $work): array
{
    $keys = jeab_send_once_keys($keys);
    $seconds = max(1, $seconds);
    if ($keys === []) {
        return jeab_send_once_run($work);
    }
    $pdo = jeab_send_once_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_send_once_run($work);
    }
    jeab_send_once_ready($pdo);
    for ($try = 0; $try < 3; $try++) {
        $claim = bin2hex(random_bytes(8));
        $reserved = jeab_send_once_reserve($pdo, $bucket, $keys, $seconds, $claim);
        if ($reserved['state'] === 'duplicate') {
            return ['duplicate' => true, 'miss' => false, 'result' => $reserved['result']];
        }
        if ($reserved['state'] === 'busy') {
            $hit = jeab_send_once_wait($pdo, $keys, $seconds);
            if ($hit !== null) {
                return ['duplicate' => true, 'miss' => false, 'result' => $hit];
            }
            continue;
        }
        try {
            $ran = jeab_send_once_run($work);
        } catch (Throwable $e) {
            jeab_send_once_finish($pdo, $bucket, $keys, $claim, $seconds, null);
            throw $e;
        }
        if (!empty($ran['miss'])) {
            jeab_send_once_finish($pdo, $bucket, $keys, $claim, $seconds, null);

            return $ran;
        }
        jeab_send_once_finish($pdo, $bucket, $keys, $claim, $seconds, $ran['result']);

        return $ran;
    }

    return ['duplicate' => false, 'miss' => true, 'result' => []];
}

/**
 * @param list<string> $keys
 * @param array<string, mixed> $result
 */
function jeab_send_once_remember(string $bucket, array $keys, int $seconds, array $result): void
{
    $keys = jeab_send_once_keys($keys);
    if ($keys === []) {
        return;
    }
    $pdo = jeab_send_once_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    jeab_send_once_ready($pdo);
    $packed = jeab_send_once_pack($result);
    if ($packed === '') {
        return;
    }
    $driver = jeab_send_once_driver($pdo);
    $expires = time() + max(1, $seconds);
    $claim = bin2hex(random_bytes(8));
    foreach ($keys as $key) {
        jeab_send_once_upsert_result($pdo, $driver, $bucket, $key, $claim, $expires, $packed);
    }
}

/**
 * @param list<string>|string $keys
 * @return list<string>
 */
function jeab_send_once_keys(array|string $keys): array
{
    if (is_string($keys)) {
        $keys = [$keys];
    }
    $out = [];
    foreach ($keys as $key) {
        if (is_string($key) && $key !== '' && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * @param callable(): array<string, mixed> $work
 * @return array{duplicate: bool, miss: bool, result: array<string, mixed>}
 */
function jeab_send_once_run(callable $work): array
{
    try {
        $result = $work();
    } catch (JeabSendOnceMiss) {
        return ['duplicate' => false, 'miss' => true, 'result' => []];
    }
    return ['duplicate' => false, 'miss' => false, 'result' => is_array($result) ? $result : []];
}

/**
 * Hook send_guard.replay_one: skickar exakt ett väntande jobb en gång.
 * Utan confirm=send eller med dry=1 visas bara jobbet. Claimen tar bara
 * status waiting (inte gamla sending), så drainen och ett andra anrop får 0.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_send_guard_replay_one_hook(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'id saknas', 'sent' => 0];
    }
    $dryRaw = $in['dry'] ?? 1;
    $dry = !($dryRaw === 0 || $dryRaw === '0' || $dryRaw === false || $dryRaw === 'false');
    $confirmed = (string) ($in['confirm'] ?? '') === 'send';
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'databas', 'sent' => 0];
    }
    try {
        jeab_send_guard_ensure($pdo);
        $sel = $pdo->prepare('SELECT id, channel, template, status, attempts, created_at, job FROM send_queue WHERE id = ?');
        $sel->execute([$id]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'databas', 'sent' => 0];
    }
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'sent' => 0];
    }
    $status = (string) ($row['status'] ?? '');
    $info = [
        'id' => $id,
        'status' => $status,
        'channel' => (string) ($row['channel'] ?? ''),
        'template' => (string) ($row['template'] ?? ''),
        'attempts' => (int) ($row['attempts'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
    if ($dry || !$confirmed) {
        return ['ok' => true, 'dry' => true, 'sent' => 0, 'job' => $info, 'hint' => 'dry=0 och confirm=send skickar'];
    }
    if ($status !== 'waiting') {
        return ['ok' => false, 'dry' => false, 'sent' => 0, 'error' => $status === 'sent' ? 'redan skickad' : 'inte waiting', 'job' => $info];
    }
    $token = bin2hex(random_bytes(16));
    try {
        $upd = $pdo->prepare(
            'UPDATE send_queue SET status = ?, claim_token = ?, claimed_at = ?, attempts = attempts + 1 WHERE id = ? AND status = ?'
        );
        $upd->execute(['sending', $token, jeab_send_guard_sql_stamp(), $id, 'waiting']);
        $claimedRows = $upd->rowCount();
    } catch (Throwable) {
        return ['ok' => false, 'dry' => false, 'sent' => 0, 'error' => 'databas', 'job' => $info];
    }
    if ($claimedRows !== 1) {
        return ['ok' => false, 'dry' => false, 'sent' => 0, 'error' => 'inte waiting', 'job' => $info];
    }
    $attempts = (int) ($row['attempts'] ?? 0) + 1;
    $raw = base64_decode((string) ($row['job'] ?? ''), true);
    $job = is_string($raw) ? unserialize($raw, ['allowed_classes' => false]) : false;
    if (!is_array($job)) {
        jeab_send_guard_settle($pdo, $id, $token, 'failed', $attempts, 'ogiltigt jobb');
        return ['ok' => false, 'dry' => false, 'sent' => 0, 'error' => 'ogiltigt jobb', 'job' => array_merge($info, ['status' => 'failed'])];
    }
    try {
        $sent = jeab_send_guard_replay($job);
    } catch (Throwable) {
        $sent = ['ok' => false, 'error' => 'misslyckades'];
    }
    if (empty($sent['ok'])) {
        $why = jeab_send_guard_public_error((string) ($sent['error'] ?? ''));
        jeab_send_guard_settle($pdo, $id, $token, 'failed', $attempts, $why !== '' ? $why : 'misslyckades');
        return ['ok' => false, 'dry' => false, 'sent' => 0, 'error' => $why !== '' ? $why : 'misslyckades', 'job' => array_merge($info, ['status' => 'failed'])];
    }
    jeab_send_guard_settle($pdo, $id, $token, 'sent', $attempts, '');
    try {
        jeab_send_guard_log($pdo, (string) ($row['channel'] ?? 'email'), 'auto', 'system', 'guard', 'sent', '', '');
    } catch (Throwable) {
    }
    $msgId = (string) ($sent['message_id'] ?? ($sent['id'] ?? ''));

    return ['ok' => true, 'dry' => false, 'sent' => 1, 'message_id' => $msgId, 'job' => array_merge($info, ['status' => 'sent', 'attempts' => $attempts])];
}
