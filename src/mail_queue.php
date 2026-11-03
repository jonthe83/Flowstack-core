<?php

declare(strict_types=1);

/**
 * mail_queue: en SQL-kö för allt utgående mejl (H1).
 *
 * - Offert, mötesbekräftelse och Inkorg-svar läggs i kön av jeab_send_guard_gate()
 *   när MAIL_QUEUE_ENABLED är på (standard av: inget ändras vid deploy).
 * - jeab_mail_queue_drain() släpper högst 5 per körning, offerter först.
 *   Körs från tick (cron var 5:e minut, GET) och som reserv när sidan används
 *   och inget skickats på 10 minuter.
 * - Över 10 väntande stannar kön (banner i Inkorg). Äldsta > 10 min ger varning.
 * - Offert till samma kundmejl inom 30 dagar stoppas som 'dubblett_stoppad'.
 * - Offertens avsändare: jeab_user_responsibility_for(produkt, region, 'sender').
 * - Aldrig Help Scout: system_mail-jobb får skip_helpscout.
 * - Kill switch: MAIL_QUEUE_PAUSED (global, env eller mail_queue_state).
 * Tabellerna skapas lat (CREATE TABLE IF NOT EXISTS), inte i schema/*.php.
 */

const JEAB_MAIL_QUEUE_TYPES = ['offert', 'motesbekraftelse', 'inkorg_svar', 'ovrigt'];
const JEAB_MAIL_QUEUE_BATCH = 5;
const JEAB_MAIL_QUEUE_MAX_WAITING = 10;
const JEAB_MAIL_QUEUE_STALE_SECONDS = 600;
const JEAB_MAIL_QUEUE_FALLBACK_SECONDS = 600;
const JEAB_MAIL_QUEUE_DUP_DAYS = 30;

function jeab_mail_queue_now(): int
{
    $t = $GLOBALS['_JEAB_MAIL_QUEUE_NOW'] ?? null;
    return is_int($t) ? $t : time();
}

function jeab_mail_queue_stamp(?int $ts = null): string
{
    return date('Y-m-d H:i:s', $ts ?? jeab_mail_queue_now());
}

function jeab_mail_queue_pdo(): ?PDO
{
    $pdo = $GLOBALS['_JEAB_MAIL_QUEUE_PDO'] ?? null;
    if (!$pdo instanceof PDO) {
        $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    }
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        jeab_mail_queue_ensure($pdo);
    } catch (Throwable) {
        return null;
    }
    return $pdo;
}

function jeab_mail_queue_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mail_queue (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            type VARCHAR(24) NOT NULL DEFAULT \'ovrigt\',
            status VARCHAR(24) NOT NULL DEFAULT \'waiting\',
            recipient VARCHAR(191) NOT NULL DEFAULT \'\',
            sender VARCHAR(191) NOT NULL DEFAULT \'\',
            subject VARCHAR(255) NOT NULL DEFAULT \'\',
            thread_id VARCHAR(191) NOT NULL DEFAULT \'\',
            company VARCHAR(32) NOT NULL DEFAULT \'\',
            product VARCHAR(32) NOT NULL DEFAULT \'\',
            handler VARCHAR(32) NOT NULL DEFAULT \'\',
            job LONGTEXT NULL,
            attempts INT NOT NULL DEFAULT 0,
            log TEXT NULL,
            PRIMARY KEY (id),
            KEY mail_queue_status (status, type, created_at),
            KEY mail_queue_rcpt (recipient, type, status),
            KEY mail_queue_thread (thread_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mail_queue_state (
            k VARCHAR(64) NOT NULL, v VARCHAR(255) NOT NULL DEFAULT \'\', PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mail_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            sent_at TEXT NULL,
            type TEXT NOT NULL DEFAULT \'ovrigt\',
            status TEXT NOT NULL DEFAULT \'waiting\',
            recipient TEXT NOT NULL DEFAULT \'\',
            sender TEXT NOT NULL DEFAULT \'\',
            subject TEXT NOT NULL DEFAULT \'\',
            thread_id TEXT NOT NULL DEFAULT \'\',
            company TEXT NOT NULL DEFAULT \'\',
            product TEXT NOT NULL DEFAULT \'\',
            handler TEXT NOT NULL DEFAULT \'\',
            job TEXT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            log TEXT NULL
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS mail_queue_status ON mail_queue (status, type, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS mail_queue_rcpt ON mail_queue (recipient, type, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS mail_queue_thread ON mail_queue (thread_id)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mail_queue_state (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT \'\')');
    }
    $done[$key] = true;
}

function jeab_mail_queue_state_get(PDO $pdo, string $k): string
{
    $st = $pdo->prepare('SELECT v FROM mail_queue_state WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return is_string($v) ? $v : '';
}

function jeab_mail_queue_state_set(PDO $pdo, string $k, string $v): void
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $sql = $mysql
        ? 'INSERT INTO mail_queue_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        : 'INSERT INTO mail_queue_state (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v';
    $pdo->prepare($sql)->execute([$k, $v]);
}

function jeab_mail_queue_truthy(mixed $v): bool
{
    if (is_bool($v)) {
        return $v;
    }
    return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes', 'ja'], true);
}

/** Global > env > mail_queue_state. Standard av. */
function jeab_mail_queue_flag(string $name): bool
{
    if (array_key_exists($name, $GLOBALS)) {
        return jeab_mail_queue_truthy($GLOBALS[$name]);
    }
    $env = getenv($name);
    if (is_string($env) && $env !== '') {
        return jeab_mail_queue_truthy($env);
    }
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        return jeab_mail_queue_truthy(jeab_mail_queue_state_get($pdo, $name));
    } catch (Throwable) {
        return false;
    }
}

function jeab_mail_queue_enabled(): bool
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return false;
    }
    return jeab_mail_queue_flag('MAIL_QUEUE_ENABLED');
}

/** Kill switch. Pausad kö tar fortfarande emot mejl men släpper inget. */
function jeab_mail_queue_paused(): bool
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return true;
    }
    return jeab_mail_queue_flag('MAIL_QUEUE_PAUSED');
}

function jeab_mail_queue_set_paused(bool $paused): array
{
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas'];
    }
    jeab_mail_queue_state_set($pdo, 'MAIL_QUEUE_PAUSED', $paused ? '1' : '0');
    return ['ok' => true, 'paused' => $paused];
}

/**
 * Typ från gate-kontexten.
 * @param array<string,mixed> $context
 */
function jeab_mail_queue_classify(array $context): string
{
    $handler = (string) ($context['handler'] ?? '');
    $template = (string) ($context['template'] ?? '');
    $args = is_array($context['job'] ?? null) ? $context['job'] : [];
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    $qg = is_array($opts['quote_guard'] ?? null) ? $opts['quote_guard'] : [];
    if ($qg !== [] && (string) ($qg['kind'] ?? 'offert') === 'offert') {
        return 'offert';
    }
    if ($template === 'booking_notice') {
        return 'motesbekraftelse';
    }
    if (in_array($template, ['svar', 'nytt'], true) && in_array($handler, ['graph_reply', 'graph_send', 'outbound', 'gp_send'], true)) {
        return 'inkorg_svar';
    }
    return 'ovrigt';
}

/**
 * Inre anrop (t.ex. outbound -> gp_send) ska inte köas igen: det yttre anropet
 * har redan passerat. Räknar transportfunktioner på stacken.
 */
function jeab_mail_queue_nested_call(): bool
{
    $names = ['jeab_system_mail_send', 'jeab_outbound_graph_send', 'jeab_gp_send', 'jeab_graph_send', 'jeab_graph_reply', 'jeab_email_send'];
    $n = 0;
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $f) {
        if (in_array((string) ($f['function'] ?? ''), $names, true)) {
            $n++;
        }
    }
    return $n > 1;
}

/** @param array<string,mixed> $context */
function jeab_mail_queue_should_intercept(string $channel, array $context): bool
{
    if ($channel !== 'email' || !empty($GLOBALS['_JEAB_MAIL_QUEUE_DRAIN'])) {
        return false;
    }
    $handler = (string) ($context['handler'] ?? '');
    // Help Scout (email_hs) köas aldrig här; H2 tar bort vägen.
    if ($handler === '' || $handler === 'email_hs' || $handler === 'sms') {
        return false;
    }
    if (!jeab_mail_queue_enabled()) {
        return false;
    }
    if (jeab_mail_queue_classify($context) === 'ovrigt') {
        return false;
    }
    return !jeab_mail_queue_nested_call();
}

/** @param array<string,mixed> $args */
function jeab_mail_queue_meta(string $handler, array $args): array
{
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    $qg = is_array($opts['quote_guard'] ?? null) ? $opts['quote_guard'] : [];
    $subject = (string) ($args['subject'] ?? $opts['subject'] ?? '');
    $company = (string) ($args['company'] ?? $opts['company'] ?? '');
    $sender = (string) ($opts['person_email'] ?? $opts['from_email'] ?? $args['email'] ?? '');
    $thread = (string) ($qg['thread_id'] ?? $opts['thread_id'] ?? $args['thread_id'] ?? '');
    if ($handler === 'system_mail') {
        $subject = (string) ($opts['subject'] ?? '');
    }
    return [
        'subject' => mb_substr($subject, 0, 250),
        'company' => $company,
        'sender' => $sender,
        'thread_id' => $thread,
        'product' => (string) ($qg['offer'] ?? $qg['product'] ?? ''),
    ];
}

function jeab_mail_queue_norm_email(string $email): string
{
    return strtolower(trim($email));
}

function jeab_mail_queue_append_log(PDO $pdo, int $id, string $line): void
{
    $st = $pdo->prepare('SELECT log FROM mail_queue WHERE id = ?');
    $st->execute([$id]);
    $old = (string) ($st->fetchColumn() ?: '');
    $new = trim($old . "\n" . jeab_mail_queue_stamp() . ' ' . $line);
    $pdo->prepare('UPDATE mail_queue SET log = ? WHERE id = ?')->execute([mb_substr($new, -4000), $id]);
}

/** Har kundmejlet fått offert de senaste 30 dagarna? */
function jeab_mail_queue_offer_recent(PDO $pdo, string $email, int $exceptId = 0): bool
{
    $since = jeab_mail_queue_stamp(jeab_mail_queue_now() - JEAB_MAIL_QUEUE_DUP_DAYS * 86400);
    $st = $pdo->prepare("SELECT COUNT(*) FROM mail_queue WHERE type = 'offert' AND status = 'sent' AND recipient = ? AND sent_at >= ? AND id <> ?");
    $st->execute([jeab_mail_queue_norm_email($email), $since, $exceptId]);
    return (int) $st->fetchColumn() > 0;
}

/** Noterar stoppet i ärendet (Inkorg) när ett tråd-id finns. */
function jeab_mail_queue_note_thread(string $threadId, string $text): void
{
    if ($threadId === '') {
        return;
    }
    $hook = $GLOBALS['_JEAB_MAIL_QUEUE_NOTE'] ?? null;
    if (is_callable($hook)) {
        $hook($threadId, $text);
        return;
    }
    // Annars syns raden (status dubblett_stoppad) i ärendets utskickslogg
    // via jeab_mail_queue_thread_rows().
}

/**
 * Lägger ett mejl i kön. $job har samma form som send_queue: handler + args.
 * @param array<string,mixed> $job
 * @return array{ok:bool,id:int,status:string,error:?string}
 */
function jeab_mail_queue_enqueue(string $type, string $recipient, array $job): array
{
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'id' => 0, 'status' => '', 'error' => 'Mejlkön saknar SQL.'];
    }
    if (!in_array($type, JEAB_MAIL_QUEUE_TYPES, true)) {
        $type = 'ovrigt';
    }
    $handler = (string) ($job['handler'] ?? '');
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $meta = jeab_mail_queue_meta($handler, $args);
    $rcpt = jeab_mail_queue_norm_email($recipient);
    $status = 'waiting';
    $log = 'köad';
    if ($type === 'offert' && jeab_mail_queue_offer_recent($pdo, $rcpt)) {
        $status = 'dubblett_stoppad';
        $log = 'dubblett stoppad (offert inom ' . JEAB_MAIL_QUEUE_DUP_DAYS . ' dagar)';
    }
    $pdo->prepare('INSERT INTO mail_queue (created_at, type, status, recipient, sender, subject, thread_id, company, product, handler, job, attempts, log) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)')
        ->execute([
            jeab_mail_queue_stamp(), $type, $status, $rcpt, $meta['sender'], $meta['subject'],
            $meta['thread_id'], $meta['company'], $meta['product'], $handler,
            base64_encode(serialize(['handler' => $handler, 'args' => $args])),
            jeab_mail_queue_stamp() . ' ' . $log,
        ]);
    $id = (int) $pdo->lastInsertId();
    if ($status === 'dubblett_stoppad') {
        jeab_mail_queue_note_thread($meta['thread_id'], 'Offert stoppad: dubblett stoppad (kunden fick offert inom 30 dagar).');
        return ['ok' => false, 'id' => $id, 'status' => $status, 'error' => 'dubblett stoppad'];
    }
    return ['ok' => true, 'id' => $id, 'status' => $status, 'error' => null];
}

/** Gate-svar för köat/stoppat mejl. Samma form som send_queue. */
function jeab_mail_queue_gate_result(array $enq): array
{
    if (!empty($enq['ok'])) {
        $id = (string) $enq['id'];
        return ['ok' => true, 'path' => 'queued', 'error' => null, 'id' => $id, 'queued' => true, 'mail_queue' => true];
    }
    return ['ok' => false, 'path' => '', 'error' => (string) ($enq['error'] ?? 'Mejlkön tog inte emot mejlet.'), 'id' => '', 'mail_queue' => true];
}

function jeab_mail_queue_resp_product(string $offer): string
{
    $o = strtolower($offer);
    if (str_contains($o, 'batteri')) {
        return 'batteri';
    }
    if (str_contains($o, 'ladd')) {
        return 'laddbox';
    }
    if (str_contains($o, 'sol')) {
        return 'sol';
    }
    return '';
}

function jeab_mail_queue_resp_region(string $company): string
{
    return in_array(strtolower($company), ['malmo', 'meab', 'skane'], true) ? 'skane' : 'stockholm';
}

/** Offertens avsändare från Användare-tabellen. '' om ingen är satt. */
function jeab_mail_queue_offer_sender(string $offer, string $company): string
{
    $product = jeab_mail_queue_resp_product($offer);
    if ($product === '' || !function_exists('jeab_user_responsibility_for')) {
        return '';
    }
    $who = jeab_user_responsibility_for($product, jeab_mail_queue_resp_region($company), 'sender');
    if ($who === '') {
        return '';
    }
    if (filter_var($who, FILTER_VALIDATE_EMAIL)) {
        return strtolower($who);
    }
    $email = '';
    if (function_exists('jeab_people')) {
        foreach (jeab_people() as $p) {
            if (is_array($p) && strcasecmp((string) ($p['id'] ?? ''), $who) === 0) {
                $email = (string) ($p['email'] ?? '');
                break;
            }
        }
    }
    if ($email === '' && function_exists('jeab_gp_person_email')) {
        $email = jeab_gp_person_email($who, $company !== '' ? $company : null);
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : '';
}

/**
 * Sätter offertens avsändare och stänger Help Scout i jobbet.
 * @param array<string,mixed> $job
 * @return array<string,mixed>
 */
function jeab_mail_queue_prepare_job(array $job, string $type, string $sender): array
{
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $handler = (string) ($job['handler'] ?? '');
    if ($handler === 'system_mail') {
        $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
        $opts['skip_helpscout'] = true;
        if ($type === 'offert' && $sender !== '') {
            $opts['from_email'] = $sender;
            if (is_array($opts['quote_guard'] ?? null)) {
                $opts['quote_guard']['from'] = $sender;
            }
        }
        $args['opts'] = $opts;
    } elseif ($type === 'offert' && $sender !== '') {
        if ($handler === 'gp_send') {
            $args['email'] = $sender;
        } else {
            $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
            $opts['person_email'] = $sender;
            if (is_array($opts['quote_guard'] ?? null)) {
                $opts['quote_guard']['from'] = $sender;
            }
            $args['opts'] = $opts;
        }
    }
    $job['args'] = $args;
    return $job;
}

function jeab_mail_queue_waiting(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'waiting'")->fetchColumn();
}

function jeab_mail_queue_oldest_age(PDO $pdo): int
{
    $v = $pdo->query("SELECT MIN(created_at) FROM mail_queue WHERE status = 'waiting'")->fetchColumn();
    if (!is_string($v) || $v === '') {
        return 0;
    }
    $t = strtotime($v);
    return $t === false ? 0 : max(0, jeab_mail_queue_now() - $t);
}

/** Nödbromsen i send_guard gäller även kön. */
function jeab_mail_queue_brake_on(PDO $pdo): bool
{
    if (!function_exists('jeab_send_guard_state')) {
        return false;
    }
    try {
        return (int) (jeab_send_guard_state($pdo)['killed'] ?? 0) === 1;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed> */
function jeab_mail_queue_status(): array
{
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas'];
    }
    $waiting = jeab_mail_queue_waiting($pdo);
    $age = jeab_mail_queue_oldest_age($pdo);
    return [
        'ok' => true,
        'enabled' => jeab_mail_queue_enabled(),
        'paused' => jeab_mail_queue_paused(),
        'waiting' => $waiting,
        'oldest_age_s' => $age,
        'stopped' => $waiting > JEAB_MAIL_QUEUE_MAX_WAITING,
        'stale' => $waiting > 0 && $age > JEAB_MAIL_QUEUE_STALE_SECONDS,
        'last_sent_at' => jeab_mail_queue_state_get($pdo, 'last_sent_at'),
        'last_fallback_at' => jeab_mail_queue_state_get($pdo, 'last_fallback_at'),
    ];
}

/**
 * Släpper högst $limit väntande mejl, offerter först.
 * @return array<string,mixed>
 */
function jeab_mail_queue_drain(int $limit = JEAB_MAIL_QUEUE_BATCH, string $trigger = 'tick'): array
{
    $out = ['ok' => true, 'trigger' => $trigger, 'sent' => 0, 'failed' => 0, 'dubbletter' => 0, 'skipped' => ''];
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas'] + $out;
    }
    if (jeab_mail_queue_paused()) {
        $out['skipped'] = 'paused';
        return $out;
    }
    if (jeab_mail_queue_brake_on($pdo)) {
        $out['skipped'] = 'nödbroms';
        return $out;
    }
    $waiting = jeab_mail_queue_waiting($pdo);
    if ($waiting > JEAB_MAIL_QUEUE_MAX_WAITING) {
        jeab_mail_queue_state_set($pdo, 'stopped_at', jeab_mail_queue_stamp());
        $out['skipped'] = 'över ' . JEAB_MAIL_QUEUE_MAX_WAITING . ' väntande';
        $out['waiting'] = $waiting;
        return $out;
    }
    $limit = max(1, min(JEAB_MAIL_QUEUE_BATCH, $limit));
    // Lätta sidor laddar bara kärnan: transporterna (system_mail, outbound, graph) behövs.
    if (!function_exists('jeab_system_mail_send') && function_exists('jeab_bootstrap_load_files') && function_exists('jeab_bootstrap_legacy_files')) {
        try {
            jeab_bootstrap_load_files(jeab_bootstrap_legacy_files());
        } catch (Throwable) {
        }
    }
    $st = $pdo->prepare("SELECT * FROM mail_queue WHERE status = 'waiting' ORDER BY CASE WHEN type = 'offert' THEN 0 ELSE 1 END, created_at, id LIMIT " . $limit);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        // Hämta raden (skydd mot två samtidiga drain).
        $claim = $pdo->prepare("UPDATE mail_queue SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'waiting'");
        $claim->execute([$id]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $type = (string) $row['type'];
        $rcpt = (string) $row['recipient'];
        $thread = (string) $row['thread_id'];
        if ($type === 'offert' && jeab_mail_queue_offer_recent($pdo, $rcpt, $id)) {
            $pdo->prepare("UPDATE mail_queue SET status = 'dubblett_stoppad' WHERE id = ?")->execute([$id]);
            jeab_mail_queue_append_log($pdo, $id, 'dubblett stoppad');
            jeab_mail_queue_note_thread($thread, 'Offert stoppad: dubblett stoppad (kunden fick offert inom 30 dagar).');
            $out['dubbletter']++;
            continue;
        }
        $job = @unserialize(base64_decode((string) $row['job']), ['allowed_classes' => false]);
        if (!is_array($job)) {
            $pdo->prepare("UPDATE mail_queue SET status = 'failed' WHERE id = ?")->execute([$id]);
            jeab_mail_queue_append_log($pdo, $id, 'fel: jobbet går inte att läsa');
            $out['failed']++;
            continue;
        }
        $sender = (string) $row['sender'];
        if ($type === 'offert') {
            $sender = jeab_mail_queue_offer_sender((string) $row['product'], (string) $row['company']);
            if ($sender === '') {
                $pdo->prepare("UPDATE mail_queue SET status = 'failed' WHERE id = ?")->execute([$id]);
                jeab_mail_queue_append_log($pdo, $id, 'fel: ingen offert-avsändare i Användare för ' . $row['product'] . '/' . jeab_mail_queue_resp_region((string) $row['company']));
                $out['failed']++;
                continue;
            }
        }
        $job = jeab_mail_queue_prepare_job($job, $type, $sender);
        $GLOBALS['_JEAB_MAIL_QUEUE_DRAIN'] = true;
        try {
            $send = $GLOBALS['_JEAB_MAIL_QUEUE_SEND'] ?? null;
            $result = is_callable($send) ? $send($job) : (function_exists('jeab_send_guard_replay') ? jeab_send_guard_replay($job) : ['ok' => false, 'error' => 'replay saknas']);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            unset($GLOBALS['_JEAB_MAIL_QUEUE_DRAIN']);
        }
        if (!empty($result['ok'])) {
            $pdo->prepare("UPDATE mail_queue SET status = 'sent', sent_at = ?, sender = ? WHERE id = ?")->execute([jeab_mail_queue_stamp(), $sender, $id]);
            jeab_mail_queue_append_log($pdo, $id, 'skickad (' . $trigger . ')');
            jeab_mail_queue_state_set($pdo, 'last_sent_at', jeab_mail_queue_stamp());
            $out['sent']++;
        } else {
            $pdo->prepare("UPDATE mail_queue SET status = 'failed' WHERE id = ?")->execute([$id]);
            jeab_mail_queue_append_log($pdo, $id, 'fel: ' . mb_substr((string) ($result['error'] ?? 'okänt'), 0, 300));
            $out['failed']++;
        }
    }
    return $out;
}

/**
 * Reserv: när sidan används och inget skickats på 10 minuter släpps upp till 5.
 * Sedan väntar reserven 10 minuter igen.
 */
function jeab_mail_queue_page_fallback(): array
{
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO || !jeab_mail_queue_enabled()) {
        return ['ok' => true, 'skipped' => 'av'];
    }
    $now = jeab_mail_queue_now();
    foreach (['last_sent_at', 'last_fallback_at'] as $k) {
        $t = strtotime(jeab_mail_queue_state_get($pdo, $k) ?: '1970-01-01');
        if ($t !== false && $t > $now - JEAB_MAIL_QUEUE_FALLBACK_SECONDS) {
            return ['ok' => true, 'skipped' => 'nyligen'];
        }
    }
    if (jeab_mail_queue_waiting($pdo) === 0) {
        return ['ok' => true, 'skipped' => 'tom'];
    }
    jeab_mail_queue_state_set($pdo, 'last_fallback_at', jeab_mail_queue_stamp($now));
    return jeab_mail_queue_drain(JEAB_MAIL_QUEUE_BATCH, 'sida');
}

function jeab_mail_queue_schedule_fallback(): void
{
    if (!empty($GLOBALS['_JEAB_MAIL_QUEUE_SCHEDULED'])) {
        return;
    }
    $GLOBALS['_JEAB_MAIL_QUEUE_SCHEDULED'] = true;
    register_shutdown_function(static function (): void {
        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            jeab_mail_queue_page_fallback();
        } catch (Throwable) {
        }
    });
}

/** Banner i Inkorg: kön stoppad (>10) eller äldsta väntat > 10 min. */
function jeab_mail_queue_banner_html(): string
{
    try {
        if (!jeab_mail_queue_enabled()) {
            return '';
        }
        $s = jeab_mail_queue_status();
    } catch (Throwable) {
        return '';
    }
    if (empty($s['ok'])) {
        return '';
    }
    $msgs = [];
    if (!empty($s['stopped'])) {
        $msgs[] = 'Mejlkön är stoppad: ' . (int) $s['waiting'] . ' mejl väntar (max ' . JEAB_MAIL_QUEUE_MAX_WAITING . '). Inget skickas förrän kön är under gränsen.';
    }
    if (!empty($s['stale'])) {
        $msgs[] = 'Äldsta mejlet i kön har väntat ' . (int) floor(((int) $s['oldest_age_s']) / 60) . ' minuter.';
    }
    if (!empty($s['paused'])) {
        $msgs[] = 'Mejlkön är pausad (MAIL_QUEUE_PAUSED).';
    }
    if ($msgs === []) {
        return '';
    }
    $h = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    return '<div class="ix-banner is-warn" role="alert" data-mail-queue-banner>' . implode('<br>', array_map($h, $msgs)) . '</div>';
}

/**
 * Läsning av kö/logg. Filtrerar på status, typ, mottagare, ärende.
 * @param array<string,mixed> $f
 */
function jeab_mail_queue_list(array $f = []): array
{
    $pdo = jeab_mail_queue_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas'];
    }
    $where = [];
    $args = [];
    foreach (['status', 'type', 'recipient', 'thread_id'] as $k) {
        $v = trim((string) ($f[$k] ?? ''));
        if ($v !== '') {
            $where[] = $k . ' = ?';
            $args[] = $k === 'recipient' ? jeab_mail_queue_norm_email($v) : $v;
        }
    }
    $limit = max(1, min(500, (int) ($f['limit'] ?? 100)));
    $sql = 'SELECT id, created_at, sent_at, type, status, recipient, sender, subject, thread_id, company, product, handler, attempts, log FROM mail_queue'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return ['ok' => true, 'read_only' => true, 'status' => jeab_mail_queue_status(), 'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

/**
 * Ärendets rader i kön (visas i Inkorgs utskickslogg för tråden).
 * @return list<array{time:string,type:string,status:string}>
 */
function jeab_mail_queue_thread_rows(string $threadId): array
{
    if ($threadId === '') {
        return [];
    }
    try {
        $pdo = jeab_mail_queue_pdo();
        if (!$pdo instanceof PDO) {
            return [];
        }
        $st = $pdo->prepare('SELECT created_at, sent_at, type, status FROM mail_queue WHERE thread_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$threadId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'time' => (string) (($r['sent_at'] ?? '') ?: $r['created_at']),
                'type' => (string) $r['type'],
                'status' => $r['status'] === 'dubblett_stoppad' ? 'dubblett stoppad' : (string) $r['status'],
            ];
        }
        return $out;
    } catch (Throwable) {
        return [];
    }
}
