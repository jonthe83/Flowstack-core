<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Gemensam cron-tick var 5:e minut. Kör tre deljobb som redan finns.
 * Bokningsnotiser och bokningspåminnelser ligger kvar på sms-cron.php
 * (jeab_sms_tick), varannan timme, så kalendern hinner flyttas.
 *
 * Deljobb, i ordning:
 * 1. Nödbromsens kö. jeab_tick_guard_live() anropar send_guard_drain()
 *    med exclude_booking och högst fem jobb. Bara när SEND_GUARD_TICK_DRAIN
 *    är på. Standard är av, så kön kan läsas med send_guard.peek först.
 * 2. Graph-delta för nya mejl, före ombokning och stängning.
 *    Ombokning körs inuti jeab_graph_ingest_message när ett nytt mejl tas in.
 * 3. Malmö från Help Scout, samma argument som jeab_malmo_hs_background().
 * Därefter, högst var 30:e minut: Wix Forms spamrapporter. Hyran förnyas
 * före det jobbet. Wix-anropen tar högst 10 sekunder och fångar alla fel,
 * så de tre deljobben inte kan vänta på dem. En intern inkorgsanteckning
 * när domänen inte är en testdomän. Inget kundmejl.
 * Därefter, högst en gång per Stockholm-dygn: ikapp av betalda Wix-ordrar.
 * Samma körning som tidigare låg efter första sidklicket. Den får samma
 * deadline som resten av ticken, och högst 10 sekunder. Inget kundmejl.
 * En sen eller saknad tick skrivs i felloggen från sidvisningen.
 *
 * Nödbroms. Saknas send_guard_drain hoppas jobbet över.
 * Tick skickar exclude_booking true (bokningsnotiser stannar på sms-cron)
 * och limit 5. deadline är microtime(true) när jobbet ska sluta.
 * Returen till tick har bara ok och count. Namn och adresser följer inte med.
 * jeab_tick_booking_templates() och jeab_tick_booking_categories() är
 * samma bokningsmängd som sms-cron redan äger.
 */

const JEAB_TICK_BUDGET_SEC = 25.0;
const JEAB_TICK_HS_BATCH = 25;
const JEAB_TICK_LEASE_TTL = 300;

/** @return array<string, float> */
function jeab_tick_job_budgets(): array
{
    return [
        'guard' => 8.0,
        'inkorg' => 12.0,
        'malmo' => 5.0,
    ];
}

/**
 * Bekräftelsemejl (bekraft-*) och SMS «kommer imorgon» (sms-*).
 * Samma id som jeab_notiser_confirm_mail_row / jeab_notiser_confirm_sms_row.
 *
 * @return list<string>
 */
function jeab_tick_booking_templates(): array
{
    $ids = [];
    foreach (['bekraft', 'sms'] as $prefix) {
        foreach (['laddbox', 'batteri', 'sol', 'service'] as $job) {
            foreach (['jarfalla', 'malmo'] as $company) {
                $ids[] = $prefix . '-' . $job . '-' . $company;
            }
        }
    }
    $ids[] = 'flytt-jarfalla';
    $ids[] = 'flytt-malmo';
    return $ids;
}

/**
 * bokningsnotis = bekräftelsemejlet. bokningspaminnelse = SMS «kommer imorgon».
 *
 * @return list<string>
 */
function jeab_tick_booking_categories(): array
{
    return ['bokningsnotis', 'bokningspaminnelse'];
}

function jeab_tick_token_file(): string
{
    $override = $GLOBALS['_JEAB_TICK_TOKEN_FILE'] ?? null;
    if (is_string($override) && $override !== '') {
        return $override;
    }
    $root = realpath(JEAB_ROOT);
    if ($root === false) {
        $root = JEAB_ROOT;
    }
    return dirname($root) . '/privat/jeab_tick_token';
}

function jeab_tick_expected_token(): string
{
    $file = jeab_tick_token_file();
    if (function_exists('jeab_read_token_file')) {
        return jeab_read_token_file($file);
    }
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }
    $raw = file_get_contents($file);
    return is_string($raw) ? trim($raw) : '';
}

/**
 * Bara tick-filens token. GET ?token= och POST. Inte andra nycklar.
 * Värdet loggas inte.
 */
function jeab_tick_token_ok(): bool
{
    if (!function_exists('jeab_cron_token_matches')) {
        return false;
    }

    return jeab_cron_token_matches(jeab_tick_expected_token());
}

function jeab_tick_pdo(): ?PDO
{
    $injected = $GLOBALS['_JEAB_TICK_PDO'] ?? null;
    if ($injected instanceof PDO) {
        return $injected;
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    if ($store instanceof JeabPdoStore) {
        return $store->pdo();
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !function_exists('jeab_data_dir')) {
        return null;
    }
    $dir = jeab_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    $path = $dir . '/tick-lock.sqlite';
    $key = 'tick-lock:' . $path;
    if (function_exists('jeab_pdo_pool_get')) {
        $cached = jeab_pdo_pool_get($key);
        if ($cached instanceof PDO) {
            return $cached;
        }
    }
    try {
        $opts = function_exists('jeab_pdo_base_options') ? jeab_pdo_base_options() : [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
        ];
        $pdo = function_exists('jeab_pdo_open_once')
            ? jeab_pdo_open_once('sqlite:' . $path, null, null, $opts)
            : null;
        if (!$pdo instanceof PDO) {
            return null;
        }
        if (function_exists('jeab_pdo_pool_put')) {
            return jeab_pdo_pool_put($key, $pdo);
        }

        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

function jeab_tick_lease_now(): int
{
    $frozen = $GLOBALS['_JEAB_TICK_LEASE_NOW'] ?? null;
    if (is_int($frozen)) {
        return $frozen;
    }

    return time();
}

function jeab_tick_lease_token(string $payload): string
{
    $pos = strpos($payload, '|');
    if ($pos === false) {
        return '';
    }

    return substr($payload, $pos + 1);
}

function jeab_tick_lease_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

/**
 * Hyra i settings. Anslutningen släpps innan Graph, mejl och inkorg så sms-cron
 * får platsen. Värdet är utgångstid|token. Första tagningen jämför-och-sätter
 * när tiden gått ut. Förnyelse jämför-och-sätter med samma token.
 * HS-batchen tar dessutom GET_LOCK på mysql (jeab_tick_hs_lock).
 */
function jeab_tick_lock_acquire(PDO $pdo, int $staleSeconds = JEAB_TICK_LEASE_TTL): bool
{
    $staleSeconds = max(1, $staleSeconds);
    $now = jeab_tick_lease_now();
    $payload = ($now + $staleSeconds) . '|' . bin2hex(random_bytes(8));
    $key = 'jeab_tick_lease';
    try {
        $driver = jeab_tick_lease_driver($pdo);
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO settings (`key`, value) VALUES (?, ?)'
                . ' ON DUPLICATE KEY UPDATE value = IF(CAST(value AS SIGNED) < ?, ?, value)';
            $pdo->prepare($sql)->execute([$key, $payload, $now, $payload]);
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $sql = 'INSERT INTO settings ("key", value) VALUES (?, ?)'
                . ' ON CONFLICT("key") DO UPDATE SET value = excluded.value'
                . ' WHERE CAST(settings.value AS INTEGER) < ?';
            $pdo->prepare($sql)->execute([$key, $payload, $now]);
        }
        if (function_exists('jeab_settings_cache_forget')) {
            jeab_settings_cache_forget($pdo, $driver, $key);
        }
        $have = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, $key) : null;
        if ($have !== $payload) {
            return false;
        }
        $GLOBALS['_JEAB_TICK_LEASE_VALUE'] = $payload;

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Förläng den egna hyran. En annan token rörs inte. */
function jeab_tick_lock_renew(PDO $pdo, int $staleSeconds = JEAB_TICK_LEASE_TTL): bool
{
    $current = $GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '';
    if (!is_string($current)) {
        return false;
    }
    $token = jeab_tick_lease_token($current);
    if ($token === '') {
        return false;
    }
    $staleSeconds = max(1, $staleSeconds);
    $payload = (jeab_tick_lease_now() + $staleSeconds) . '|' . $token;
    $key = 'jeab_tick_lease';
    try {
        $driver = jeab_tick_lease_driver($pdo);
        if ($driver === 'mysql') {
            $sql = 'UPDATE settings SET value = ? WHERE `key` = ?'
                . ' AND SUBSTRING(value, LOCATE(\'|\', value) + 1) = ?';
        } else {
            $sql = 'UPDATE settings SET value = ? WHERE "key" = ?'
                . ' AND substr(value, instr(value, \'|\') + 1) = ?';
        }
        $pdo->prepare($sql)->execute([$payload, $key, $token]);
        if (function_exists('jeab_settings_cache_forget')) {
            jeab_settings_cache_forget($pdo, $driver, $key);
        }
        $have = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, $key) : null;
        if ($have !== $payload) {
            return false;
        }
        $GLOBALS['_JEAB_TICK_LEASE_VALUE'] = $payload;
        if (is_array($GLOBALS['_JEAB_TICK_LEASE_TRACE'] ?? null)) {
            $GLOBALS['_JEAB_TICK_LEASE_TRACE'][] = 'renew';
        }

        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_tick_lock_renew_outside(): bool
{
    $pdo = jeab_tick_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        return jeab_tick_lock_renew($pdo);
    } catch (Throwable) {
        return false;
    } finally {
        $pdo = null;
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
    }
}

function jeab_tick_lock_release(PDO $pdo): void
{
    $payload = $GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '';
    unset($GLOBALS['_JEAB_TICK_LEASE_VALUE']);
    if (!is_string($payload) || $payload === '') {
        return;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $col = $driver === 'mysql' ? '`key`' : '"key"';
        $pdo->prepare('UPDATE settings SET value = ? WHERE ' . $col . ' = ? AND value = ?')
            ->execute(['0', 'jeab_tick_lease', $payload]);
        if (function_exists('jeab_settings_cache_forget')) {
            jeab_settings_cache_forget($pdo, $driver, 'jeab_tick_lease');
        }
    } catch (Throwable) {
    }
}

/**
 * Påfyllnad och listindex. Sidan läser bara flaggan.
 * CREATE INDEX körs här, inte på första kontorsklicket. Tidsgränsen
 * höjs bara för den här passningen, aldrig i index.php.
 */
function jeab_tick_maintenance(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $prevLimit = (int) ini_get('max_execution_time');
    if ($prevLimit !== 0 && $prevLimit < 120) {
        set_time_limit(120);
    }
    try {
        if (function_exists('jeab_ensure_inkorg_tables')) {
            jeab_ensure_inkorg_tables($pdo, $driver);
        }
        $purchaseFile = __DIR__ . '/purchase_store.php';
        if (is_file($purchaseFile)) {
            require_once $purchaseFile;
        }
        if (function_exists('jeab_purchases_store_ensure')) {
            jeab_purchases_store_ensure($pdo);
        }
        $hsFile = __DIR__ . '/hs_import_store.php';
        if (is_file($hsFile)) {
            require_once $hsFile;
        }
        if (function_exists('jeab_hs_import_store_ensure')) {
            jeab_hs_import_store_ensure($pdo);
        }
        if (function_exists('jeab_hs_import_ensure_contact_column')) {
            jeab_hs_import_ensure_contact_column($pdo);
        }
    } catch (Throwable) {
    } finally {
        if ($prevLimit !== 0 && $prevLimit < 120) {
            set_time_limit($prevLimit);
        }
    }
    if (function_exists('jeab_ensure_reminder_list_index')) {
        try {
            jeab_ensure_reminder_list_index($pdo, $driver);
        } catch (Throwable) {
        }
    }
    $contacts = __DIR__ . '/kontakt_store.php';
    if (is_file($contacts)) {
        require_once $contacts;
    }
    if (function_exists('jeab_contacts_list_backfill')) {
        try {
            jeab_contacts_list_backfill($pdo);
        } catch (Throwable $e) {
            error_log('tick contacts_list_backfill ' . $e::class);
        }
    }
    if (function_exists('jeab_purchase_sql_pdo') && function_exists('jeab_purchases_contact_index_backfill')) {
        $orders = jeab_purchase_sql_pdo();
        if ($orders instanceof PDO) {
            try {
                jeab_purchases_contact_index_backfill($orders);
            } catch (Throwable) {
            }
            if (function_exists('jeab_purchases_list_index_backfill')) {
                try {
                    jeab_purchases_list_index_backfill($orders);
                } catch (Throwable) {
                }
            }
            if (function_exists('jeab_purchases_list_json_backfill')) {
                try {
                    jeab_purchases_list_json_backfill($orders);
                } catch (Throwable) {
                }
            }
            $purchaseApi = __DIR__ . '/purchase.php';
            if (is_file($purchaseApi)) {
                require_once $purchaseApi;
            }
            if (function_exists('jeab_purchase_booking_ids')) {
                try {
                    $bookingCache = jeab_purchase_booking_ids_cache_path();
                    if (is_file($bookingCache)) {
                        @unlink($bookingCache);
                    }
                    jeab_purchase_booking_ids();
                } catch (Throwable) {
                }
            }
        }
    }
    if (function_exists('jeab_booking_week_query_migrate')) {
        try {
            jeab_booking_week_query_migrate($pdo, $driver);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_booking_company_canon')) {
        try {
            jeab_booking_company_canon($pdo, $driver);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_ensure_contact_emails')) {
        try {
            jeab_ensure_contact_emails($pdo, $driver);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_ensure_contact_secondary_columns')) {
        try {
            jeab_ensure_contact_secondary_columns($pdo, $driver);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_ensure_contact_search_prefix')) {
        try {
            jeab_ensure_contact_search_prefix($pdo, $driver);
        } catch (Throwable $e) {
            error_log('tick contacts_search_prefix ' . $e::class);
        }
    }
    if (function_exists('jeab_store') && function_exists('jeab_calendar_log_backfill')) {
        try {
            jeab_calendar_log_backfill(jeab_store());
        } catch (Throwable $e) {
            error_log('tick calendar_log_backfill ' . $e::class);
        }
    }
    if (function_exists('jeab_send_guard_legacy_claims_backfill')) {
        try {
            jeab_send_guard_legacy_claims_backfill($pdo);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_inkorg_ensure_list_contact')) {
        try {
            jeab_inkorg_ensure_list_contact($pdo, $driver);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_notiser_history_refresh')) {
        try {
            jeab_notiser_history_refresh();
        } catch (Throwable) {
        }
    }
}

/** Stockholm-klocka för nattbackupen. Tester sätter _JEAB_BACKUP_NOW som unixtid. */
function jeab_tick_backup_now(): DateTimeImmutable
{
    $zone = new DateTimeZone('Europe/Stockholm');
    $raw = $GLOBALS['_JEAB_BACKUP_NOW'] ?? null;
    if (is_int($raw) || (is_string($raw) && preg_match('/\A\d{1,12}\z/', $raw) === 1)) {
        return (new DateTimeImmutable('@' . (int) $raw))->setTimezone($zone);
    }

    return new DateTimeImmutable('now', $zone);
}

/**
 * Ett försök per Stockholm-natt, klockan 03. CLI gör det bara när testet ber om det,
 * så en testkörning mitt i natten inte hoppar över mejlen.
 */
function jeab_tick_backup_due(PDO $pdo): bool
{
    if (empty($GLOBALS['_JEAB_TICK'])) {
        return false;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if (($sapi === 'cli' || $sapi === 'phpdbg') && empty($GLOBALS['_JEAB_TICK_BACKUP_ALLOW'])) {
        return false;
    }
    $now = jeab_tick_backup_now();
    if ((int) $now->format('G') !== 3 && empty($GLOBALS['_JEAB_TICK_BACKUP_FORCE'])) {
        return false;
    }
    if (!function_exists('jeab_settings_value')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $stamp = (string) (jeab_settings_value($pdo, $driver, 'migrate_backup_night') ?? '');

    return $stamp !== $now->format('Y-m-d');
}

/**
 * mode=run med den gallring backupen redan har. Stämplar natten även vid fel,
 * så nästa tick samma natt kan skicka mejl. null betyder att det inte var dags.
 *
 * @return array{ok:bool,ms:int,count:int,error?:string}|null
 */
function jeab_tick_nightly_backup(PDO $pdo): ?array
{
    if (!jeab_tick_backup_due($pdo)) {
        return null;
    }
    $now = jeab_tick_backup_now();
    $day = $now->format('Y-m-d');
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $started = microtime(true);
    $ok = false;
    $file = __DIR__ . '/migrate/backup.php';
    if (is_file($file)) {
        require_once $file;
    }
    $prevLimit = (int) ini_get('max_execution_time');
    if ($prevLimit !== 0 && $prevLimit < 180 && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        set_time_limit(180);
    }
    try {
        if (function_exists('jeab_migrate_backup_from_tick')) {
            $result = jeab_migrate_backup_from_tick();
            $ok = !empty($result['ok']) && !empty($result['written']);
        }
    } catch (Throwable $e) {
        error_log('tick migrate.backup ' . $e::class);
        $ok = false;
    } finally {
        if ($prevLimit !== 0 && $prevLimit < 180 && PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            set_time_limit($prevLimit);
        }
    }
    if (function_exists('jeab_settings_put')) {
        try {
            jeab_settings_put($pdo, $driver, 'migrate_backup_night', $day);
        } catch (Throwable $e) {
            error_log('tick migrate.backup ' . $e::class);
        }
    }
    if (!$ok && function_exists('jeab_log_app_error')) {
        jeab_log_app_error('tick', 'nattbackup misslyckades', [
            'level' => 'info',
            'dedupe_seconds' => 86400,
            'skip_request' => true,
            'path' => '/tick.php',
        ]);
    }
    $job = [
        'ok' => $ok,
        'ms' => (int) round((microtime(true) - $started) * 1000),
        'count' => $ok ? 1 : 0,
    ];
    if (!$ok) {
        $job['error'] = 'fel';
    }

    return $job;
}

/** @return array{ok: bool, count: int, skipped?: string, error?: string} */
function jeab_tick_guard_live(float $deadline): array
{
    $file = __DIR__ . '/send_guard.php';
    if (is_file($file)) {
        require_once $file;
    }
    if (!function_exists('send_guard_drain')) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'saknas'];
    }
    if (!function_exists('jeab_send_guard_tick_drain_enabled') || !jeab_send_guard_tick_drain_enabled()) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'av'];
    }
    $result = send_guard_drain([
        'exclude_booking' => true,
        'limit' => 5,
        'deadline' => $deadline,
    ]);
    if (!is_array($result)) {
        return ['ok' => false, 'count' => 0, 'error' => 'fel'];
    }
    $reason = (string) ($result['reason'] ?? '');
    $count = max(0, (int) ($result['sent'] ?? 0));
    if ($reason === 'error' || $reason === 'sql' || $reason === 'killed') {
        return ['ok' => false, 'count' => $count, 'error' => 'fel'];
    }
    if (function_exists('jeab_send_once_purge')) {
        try {
            jeab_send_once_purge();
        } catch (Throwable) {
        }
    }
    return ['ok' => true, 'count' => $count];
}

/** @return array{ok: bool, count: int, skipped?: string, error?: string} */
function jeab_tick_inkorg_live(float $deadline): array
{
    $count = 0;
    $deltaOk = true;
    if (function_exists('jeab_graph_mail_tick') || function_exists('jeab_graph_delta')) {
        try {
            $result = function_exists('jeab_graph_mail_tick') ? jeab_graph_mail_tick($deadline) : ['delta' => jeab_graph_delta(0, 3)];
            $delta = (array) ($result['delta'] ?? $result);
            $deltaOk = !array_key_exists('ok', $delta) || !empty($delta['ok']);
            foreach ((array) ($delta['boxes'] ?? []) as $box) {
                if (is_array($box)) {
                    $count += (int) ($box['new'] ?? 0);
                }
            }
        } catch (Throwable) {
            $deltaOk = false;
        }
    } else {
        return ['ok' => true, 'count' => 0, 'skipped' => 'saknas'];
    }
    if ($deadline - microtime(true) < 0.2) {
        return ['ok' => $deltaOk, 'count' => $count];
    }
    if (function_exists('jeab_inkorg_tick_warm')) {
        try {
            jeab_inkorg_tick_warm();
        } catch (Throwable) {
        }
    }
    $learned = 0;
    $retried = 0;
    if (function_exists('jeab_rebook_pending_drain')) {
        try {
            $retried = jeab_rebook_pending_drain(20);
        } catch (Throwable) {
            $retried = 0;
        }
    }
    $closes = 0;
    if (function_exists('jeab_inkorg_close_retry_drain')) {
        try {
            $closes = jeab_inkorg_close_retry_drain(20);
        } catch (Throwable) {
            $closes = 0;
        }
    }
    if (function_exists('jeab_inkorg_learn_drain')) {
        try {
            $learned = jeab_inkorg_learn_drain(20);
        } catch (Throwable) {
            $learned = 0;
        }
    }
    $classified = 0;
    $GLOBALS['_JEAB_CLASSIFY_RETRY_TICK_QUOTES'] = 0;
    if (function_exists('jeab_auto_classify_requeue_failed')) {
        try {
            jeab_auto_classify_requeue_failed(20);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_auto_classify_pending_drain')) {
        try {
            $classified = jeab_auto_classify_pending_drain(20);
        } catch (Throwable) {
            $classified = 0;
        }
    }
    $filed = 0;
    if (function_exists('jeab_inkorg_file_missed')) {
        try {
            $filedRow = jeab_inkorg_file_missed(['dry' => 0, 'limit' => 25]);
            $filed = (int) ($filedRow['changed'] ?? 0);
        } catch (Throwable) {
            $filed = 0;
        }
    }

    return [
        'ok' => $deltaOk,
        'count' => $count + $learned + $retried + $closes + $classified + $filed,
    ];
}

/** MySQL GET_LOCK runt HS-batchen. SQLite faller tillbaka på tick-hyran. */
function jeab_tick_hs_lock(PDO $pdo): bool
{
    if (jeab_tick_lease_driver($pdo) !== 'mysql') {
        return true;
    }
    if (!empty($GLOBALS['_JEAB_TICK_HS_LOCK'])) {
        return false;
    }
    try {
        $stmt = $pdo->query("SELECT GET_LOCK('jeab_tick_hs', 0)");
        $got = $stmt ? (int) $stmt->fetchColumn() : 0;
    } catch (Throwable) {
        return false;
    }
    if ($got !== 1) {
        return false;
    }
    $GLOBALS['_JEAB_TICK_HS_LOCK'] = true;
    $GLOBALS['_JEAB_TICK_HS_LOCK_PDO'] = $pdo;

    return true;
}

function jeab_tick_hs_unlock(?PDO $pdo): void
{
    if (empty($GLOBALS['_JEAB_TICK_HS_LOCK'])) {
        return;
    }
    $lock = $GLOBALS['_JEAB_TICK_HS_LOCK_PDO'] ?? $pdo;
    unset($GLOBALS['_JEAB_TICK_HS_LOCK'], $GLOBALS['_JEAB_TICK_HS_LOCK_PDO']);
    if ($lock instanceof PDO) {
        try {
            $lock->query("SELECT RELEASE_LOCK('jeab_tick_hs')");
        } catch (Throwable) {
        }
    }
}

/** @return array{ok: bool, count: int, skipped?: string, error?: string} */
function jeab_tick_malmo_live(float $deadline): array
{
    unset($deadline);
    if (!function_exists('jeab_malmo_hs_settings') || !function_exists('jeab_malmo_hs_sync')) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'saknas'];
    }
    if (!jeab_malmo_hs_settings()['sync']) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'av'];
    }
    $pdo = jeab_tick_pdo();
    $hsPdo = null;
    if ($pdo instanceof PDO && jeab_tick_lease_driver($pdo) === 'mysql') {
        if (!jeab_tick_hs_lock($pdo)) {
            $pdo = null;
            if (function_exists('jeab_pdo_release_if_idle')) {
                jeab_pdo_release_if_idle();
            }

            return ['ok' => true, 'count' => 0, 'skipped' => 'upptagen'];
        }
        $hsPdo = $pdo;
    } else {
        $pdo = null;
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
    }
    try {
        return jeab_tick_malmo_live_locked();
    } finally {
        jeab_tick_hs_unlock($hsPdo);
        $hsPdo = null;
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
    }
}

/** @return array{ok: bool, count: int, skipped?: string, error?: string} */
function jeab_tick_malmo_live_locked(): array
{
    $watermark = '';
    if (function_exists('jeab_malmo_hs_state')) {
        $watermark = trim((string) (jeab_malmo_hs_state()['watermark'] ?? ''));
    }
    $in = ['dry' => 0, 'limit' => JEAB_TICK_HS_BATCH];
    if ($watermark === '') {
        $in['backfill'] = 1;
    } else {
        $in['since'] = 1;
    }
    $result = jeab_malmo_hs_sync($in);
    if (!is_array($result)) {
        return ['ok' => false, 'count' => 0, 'error' => 'fel'];
    }
    $rows = is_array($result['conversations'] ?? null) ? $result['conversations'] : [];
    $skipped = trim((string) ($result['skipped'] ?? ''));
    $out = [
        'ok' => !empty($result['ok']) || $skipped !== '',
        'count' => count($rows),
    ];
    if ($skipped === 'av') {
        $out['skipped'] = 'av';
        $out['ok'] = true;
    } elseif (empty($out['ok'])) {
        $out['error'] = 'fel';
    }
    return $out;
}

/**
 * @param callable(float): array $fn
 * @return array{ok: bool, ms: int, count: int, skipped?: string, error?: string}
 */
function jeab_tick_one(string $name, float $deadline, callable $fn): array
{
    $start = microtime(true);
    $left = $deadline - $start;
    $budget = jeab_tick_job_budgets()[$name] ?? 5.0;
    if ($left < 0.05) {
        return ['ok' => false, 'ms' => 0, 'count' => 0, 'error' => 'tidsgrans'];
    }
    $cap = min($left, $budget);
    $jobDeadline = $start + $cap;
    if (PHP_SAPI !== 'cli') {
        @set_time_limit(max(1, (int) ceil($cap)));
    }
    try {
        $raw = $fn($jobDeadline);
        if (!is_array($raw)) {
            $raw = ['ok' => false, 'count' => 0, 'error' => 'fel'];
        }
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('tick', $name . ' ' . $e::class);
        }
        $raw = ['ok' => false, 'count' => 0, 'error' => 'fel'];
    }
    return jeab_tick_public_job($raw, (int) round((microtime(true) - $start) * 1000));
}

/**
 * @param array<string, mixed> $raw
 * @return array{ok: bool, ms: int, count: int, skipped?: string, error?: string}
 */
function jeab_tick_public_job(array $raw, int $ms): array
{
    $out = [
        'ok' => !empty($raw['ok']),
        'ms' => max(0, $ms),
        'count' => max(0, (int) ($raw['count'] ?? 0)),
    ];
    $skipped = trim((string) ($raw['skipped'] ?? ''));
    if ($skipped !== '' && preg_match('/^[a-z0-9_-]{1,32}$/', $skipped) === 1) {
        $out['skipped'] = $skipped;
        $out['ok'] = true;
    }
    if (empty($out['ok'])) {
        $err = trim((string) ($raw['error'] ?? 'fel'));
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $err) !== 1) {
            $err = 'fel';
        }
        $out['error'] = $err;
    }
    return $out;
}

/** @return callable(float): array */
function jeab_tick_job(string $name, callable $live): callable
{
    $key = match ($name) {
        'guard' => '_JEAB_TICK_GUARD',
        'inkorg' => '_JEAB_TICK_INKORG',
        'malmo' => '_JEAB_TICK_MALMO',
        default => '',
    };
    $stub = $key !== '' ? ($GLOBALS[$key] ?? null) : null;
    if ($stub instanceof Closure) {
        return $stub;
    }
    return $live;
}

/**
 * @return array{ok: bool, ms: int, busy?: bool, error?: string, jobs: array<string, array<string, mixed>>}
 */
function jeab_tick_run(): array
{
    $prevTick = array_key_exists('_JEAB_TICK', $GLOBALS) ? $GLOBALS['_JEAB_TICK'] : null;
    $prevAllow = array_key_exists('_JEAB_EXTERNAL_ALLOW', $GLOBALS) ? $GLOBALS['_JEAB_EXTERNAL_ALLOW'] : null;
    $GLOBALS['_JEAB_TICK'] = true;
    $GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
    try {
        return jeab_tick_run_work();
    } finally {
        if ($prevTick === null) {
            unset($GLOBALS['_JEAB_TICK']);
        } else {
            $GLOBALS['_JEAB_TICK'] = $prevTick;
        }
        if ($prevAllow === null) {
            unset($GLOBALS['_JEAB_EXTERNAL_ALLOW']);
        } else {
            $GLOBALS['_JEAB_EXTERNAL_ALLOW'] = $prevAllow;
        }
    }
}

/**
 * @return array{ok: bool, ms: int, busy?: bool, error?: string, jobs: array<string, array<string, mixed>>}
 */
function jeab_tick_run_work(): array
{
    $started = microtime(true);
    if (function_exists('jeab_cutover_sends_paused') && jeab_cutover_sends_paused()) {
        return [
            'ok' => true,
            'paused' => 1,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'jobs' => [],
        ];
    }
    if (function_exists('jeab_data_maintenance') && jeab_data_maintenance() && empty($GLOBALS['_JEAB_DATA_MIGRATE'])) {
        return [
            'ok' => true,
            'paused' => 1,
            'maintenance' => 1,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'jobs' => [],
        ];
    }
    if (function_exists('jeab_job_overlap_acquire') && !jeab_job_overlap_acquire()) {
        return [
            'ok' => true,
            'busy' => true,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'jobs' => [],
        ];
    }
    try {
        return jeab_tick_run_work_locked($started);
    } finally {
        if (function_exists('jeab_job_overlap_release')) {
            jeab_job_overlap_release();
        }
    }
}

/**
 * @return array{ok: bool, ms: int, busy?: bool, error?: string, jobs: array<string, array<string, mixed>>}
 */
function jeab_tick_run_work_locked(float $started): array
{
    $deadline = $started + JEAB_TICK_BUDGET_SEC;
    $pdo = jeab_tick_pdo();
    if (!$pdo instanceof PDO) {
        return [
            'ok' => false,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'error' => 'las',
            'jobs' => [],
        ];
    }
    if (!jeab_tick_lock_acquire($pdo)) {
        return [
            'ok' => true,
            'busy' => true,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'jobs' => [],
        ];
    }
    $jobs = [];
    try {
        if (!jeab_tick_lock_renew($pdo)) {
            return [
                'ok' => true,
                'busy' => true,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'jobs' => [],
            ];
        }
        if (function_exists('jeab_tick_seen_read') && function_exists('jeab_log_app_error')) {
            try {
                $seen = jeab_tick_seen_read($pdo);
                $now = jeab_tick_lease_now();
                if ($seen !== null && ($now - $seen) > (defined('JEAB_TICK_STALE_SEC') ? JEAB_TICK_STALE_SEC : 1200)) {
                    jeab_log_app_error('tick', 'tick sen', [
                        'dedupe_seconds' => 3600,
                        'skip_request' => true,
                    ]);
                }
            } catch (Throwable) {
            }
        }
        // CLI-tick (tester) rör inte datakatalogen. Webbens tick.php städar läckorna.
        if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            try {
                if (function_exists('jeab_shield_relocate_leaks_from_tick')) {
                    jeab_shield_relocate_leaks_from_tick();
                }
            } catch (Throwable) {
            }
        }
        try {
            jeab_tick_maintenance($pdo);
        } catch (Throwable) {
        }
        $night = null;
        try {
            $night = jeab_tick_nightly_backup($pdo);
        } catch (Throwable $e) {
            error_log('tick migrate.backup ' . $e::class);
        }
        if ($night !== null) {
            $jobs['backup'] = $night;
        }
        // Hyresraden ligger kvar. Anslutningen och fil-låset släpps före Graph, mejl och inkorg.
        // Nattens backup är ett eget varv: mejl, inkorg och Wix väntar till nästa tick.
        if ($night === null) {
        $pdo = null;
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
        if (function_exists('jeab_job_overlap_release')) {
            jeab_job_overlap_release();
        }
        $plan = [
            'guard' => jeab_tick_job('guard', static function (float $deadline): array {
                return jeab_tick_guard_live($deadline);
            }),
            'inkorg' => jeab_tick_job('inkorg', static function (float $deadline): array {
                return jeab_tick_inkorg_live($deadline);
            }),
            'malmo' => jeab_tick_job('malmo', static function (float $deadline): array {
                return jeab_tick_malmo_live($deadline);
            }),
        ];
        $pending = array_keys($plan);
        $index = 0;
        $spamOk = true;
        foreach ($plan as $name => $fn) {
            if (!jeab_tick_lock_renew_outside()) {
                $spamOk = false;
                for ($left = $index; $left < count($pending); $left++) {
                    $jobs[$pending[$left]] = ['ok' => false, 'ms' => 0, 'count' => 0, 'error' => 'upptagen'];
                }
                break;
            }
            $jobs[$name] = jeab_tick_one($name, $deadline, $fn);
            $index++;
        }
        if (function_exists('jeab_inkorg_resend_drain_queued')) {
            try {
                jeab_inkorg_resend_drain_queued();
            } catch (Throwable) {
            }
        }
        if (function_exists('jeab_inbox_queue_quote_drain')) {
            try {
                jeab_inbox_queue_quote_drain();
            } catch (Throwable) {
            }
        }
        // mail_queue: cron var 5:e minut släpper högst 5, offerter först.
        if (function_exists('jeab_mail_queue_enabled') && jeab_mail_queue_enabled()) {
            try {
                jeab_mail_queue_drain(JEAB_MAIL_QUEUE_BATCH, 'tick');
            } catch (Throwable) {
            }
        }
        // Ordrar-reparationer är inte ett fjärde deljobb. Shopnummer, aktivitet och
        // Ellevio-id som inte längre skrivs när ordern öppnas fylls i här, några åt gången.
        if (function_exists('jeab_ordrar_repair_tick')) {
            try {
                jeab_ordrar_repair_tick(25);
            } catch (Throwable) {
            }
        }
        if ($spamOk && function_exists('jeab_wix_spam_tick') && jeab_tick_lock_renew_outside()) {
            if (function_exists('jeab_gp_week_refresh_due')) {
                try {
                    jeab_gp_week_refresh_due(2, min($deadline, microtime(true) + 8));
                } catch (Throwable $e) {
                    error_log('tick calendar_graph ' . $e::class);
                }
            }
            $spamDeadline = min($deadline, microtime(true) + 10);
            $prevSpamDeadline = $GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] ?? null;
            $GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] = $spamDeadline;
            try {
                jeab_wix_spam_tick();
            } catch (Throwable) {
            } finally {
                if ($prevSpamDeadline === null) {
                    unset($GLOBALS['_JEAB_WIX_SPAM_DEADLINE']);
                } else {
                    $GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] = $prevSpamDeadline;
                }
            }
        }
        if ($spamOk && function_exists('jeab_webshop_catchup_daily_schedule') && function_exists('jeab_store') && jeab_tick_lock_renew_outside()) {
            $catchDeadline = min($deadline, microtime(true) + 10);
            if ($catchDeadline - microtime(true) < 0.2) {
                $jobs['wix'] = ['ok' => true, 'ms' => 0, 'count' => 0, 'skipped' => 'tidsgrans'];
            } else {
                $prevCatch = $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] ?? null;
                $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = $catchDeadline;
                $catchStart = microtime(true);
                try {
                    jeab_webshop_catchup_daily_schedule(jeab_store());
                    $jobs['wix'] = ['ok' => true, 'ms' => (int) round((microtime(true) - $catchStart) * 1000), 'count' => 0];
                } catch (Throwable) {
                    $jobs['wix'] = ['ok' => false, 'ms' => 0, 'count' => 0, 'error' => 'fel'];
                } finally {
                    if ($prevCatch === null) {
                        unset($GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE']);
                    } else {
                        $GLOBALS['_JEAB_WIX_CATCHUP_DEADLINE'] = $prevCatch;
                    }
                }
            }
        }
        }
        if (function_exists('jeab_tick_seen_write')) {
            try {
                $stampPdo = jeab_tick_pdo();
                if ($stampPdo instanceof PDO) {
                    jeab_tick_seen_write($stampPdo, jeab_tick_lease_now());
                }
            } catch (Throwable) {
            }
        }
    } finally {
        try {
            $release = jeab_tick_pdo();
            if ($release instanceof PDO) {
                jeab_tick_lock_release($release);
            }
        } catch (Throwable) {
        }
        $release = null;
        $pdo = null;
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
    }
    $ok = true;
    foreach ($jobs as $job) {
        if (empty($job['ok'])) {
            $ok = false;
        }
    }
    return [
        'ok' => $ok,
        'ms' => (int) round((microtime(true) - $started) * 1000),
        'jobs' => $jobs,
    ];
}
