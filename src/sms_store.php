<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Skickade SMS och mejlflaggor i SQL. Tabellerna skapas här, inte i src/db.php.
 * PDO kommer från jeab_store(), som öppnar via jeab_pdo_connect_sqlite/mysql.
 * sms-sent.json läses som reserv tills importen är gjord och skrivs inte längre.
 */

function jeab_sms_store_pdo(): ?PDO
{
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if ($store instanceof JeabPdoStore) {
                return $store->pdo();
            }
        } catch (Throwable) {
        }
    }
    return jeab_sms_store_sqlite_fallback();
}

/** När hela appen ligger på JSON öppnas samma sqlite-fil som PDO-hjälparen skulle använt. */
function jeab_sms_store_sqlite_fallback(): ?PDO
{
    $cached = $GLOBALS['_JEAB_SIDE_SQLITE'] ?? null;
    if ($cached instanceof PDO) {
        return $cached;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return null;
    }
    $path = (string) (function_exists('jeab_config') ? (jeab_config()['db']['sqlite_path'] ?? '') : '');
    if ($path === '' || (function_exists('jeab_store_path_is_public_data') && jeab_store_path_is_public_data($path))) {
        return null;
    }
    try {
        if (function_exists('jeab_data_dir_ready')) {
            jeab_data_dir_ready($path);
        }
        $pdo = function_exists('jeab_pdo_connect_sqlite') ? jeab_pdo_connect_sqlite() : null;
        if (!$pdo instanceof PDO) {
            return null;
        }
        $GLOBALS['_JEAB_SIDE_SQLITE'] = $pdo;
        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

function jeab_sms_store_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_sms_store_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $done[$key] = true;
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi !== 'cli' && $sapi !== 'phpdbg' && ($method === 'GET' || $method === 'HEAD') && empty($GLOBALS['_JEAB_TICK']) && empty($GLOBALS['_JEAB_SCHEMA_DDL'])) {
        return;
    }
    if (jeab_sms_store_driver($pdo) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sms_sent (
                booking_id INT UNSIGNED NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sms_sent_state (
                id TINYINT UNSIGNED NOT NULL,
                imported TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sms_sent (
            booking_id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sms_sent_state (
            id INTEGER PRIMARY KEY,
            imported INTEGER NOT NULL DEFAULT 0
        )'
    );
}

function jeab_sms_store_is_imported(PDO $pdo): bool
{
    $stmt = $pdo->query('SELECT imported FROM sms_sent_state WHERE id = 1');
    $value = $stmt ? $stmt->fetchColumn() : false;
    return $value !== false && (int) $value === 1;
}

function jeab_sms_store_mark_imported(PDO $pdo): void
{
    if (jeab_sms_store_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO sms_sent_state (id, imported) VALUES (1, 1)
             ON DUPLICATE KEY UPDATE imported = VALUES(imported)'
        )->execute();
        return;
    }
    $pdo->prepare(
        'INSERT INTO sms_sent_state (id, imported) VALUES (1, 1)
         ON CONFLICT(id) DO UPDATE SET imported = excluded.imported'
    )->execute();
}

/** @return array<string, array<string, mixed>> */
function jeab_sms_json_read(): array
{
    if (!function_exists('jeab_sms_sent_path')) {
        return [];
    }
    $path = jeab_sms_sent_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $data = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $key => $row) {
        if (is_array($row)) {
            $out[(string) $key] = $row;
        }
    }
    return $out;
}

/** @return array<string, array<string, mixed>> */
function jeab_sms_store_read_sql(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT booking_id, payload FROM sms_sent') ?: [] as $dbRow) {
        if (!is_array($dbRow)) {
            continue;
        }
        $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
        if (!is_array($row)) {
            continue;
        }
        $out[(string) (int) ($dbRow['booking_id'] ?? 0)] = $row;
    }
    return $out;
}

function jeab_sms_store_payload(array $row): ?string
{
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? null : $json;
}

function jeab_sms_store_insert_ignore(PDO $pdo, int $bookingId, string $payload): void
{
    if (jeab_sms_store_driver($pdo) === 'mysql') {
        $pdo->prepare('INSERT IGNORE INTO sms_sent (booking_id, payload) VALUES (?, ?)')->execute([$bookingId, $payload]);
        return;
    }
    $pdo->prepare('INSERT OR IGNORE INTO sms_sent (booking_id, payload) VALUES (?, ?)')->execute([$bookingId, $payload]);
}

function jeab_sms_store_upsert(PDO $pdo, int $bookingId, string $payload): void
{
    if (jeab_sms_store_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO sms_sent (booking_id, payload) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload)'
        )->execute([$bookingId, $payload]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO sms_sent (booking_id, payload) VALUES (?, ?)
         ON CONFLICT(booking_id) DO UPDATE SET payload = excluded.payload'
    )->execute([$bookingId, $payload]);
}

/**
 * @return array{inserted:int,skipped:int,json_rows:int}
 */
function jeab_sms_store_copy_json(PDO $pdo): array
{
    $json = jeab_sms_json_read();
    $have = [];
    foreach ($pdo->query('SELECT booking_id FROM sms_sent') ?: [] as $dbRow) {
        if (is_array($dbRow)) {
            $have[(string) (int) ($dbRow['booking_id'] ?? 0)] = true;
        }
    }
    $inserted = 0;
    $skipped = 0;
    foreach ($json as $key => $row) {
        $id = (int) $key;
        if ($id < 0) {
            $skipped++;
            continue;
        }
        if (isset($have[(string) $id])) {
            $skipped++;
            continue;
        }
        $payload = jeab_sms_store_payload($row);
        if ($payload === null) {
            $skipped++;
            continue;
        }
        jeab_sms_store_insert_ignore($pdo, $id, $payload);
        $have[(string) $id] = true;
        $inserted++;
    }
    return ['inserted' => $inserted, 'skipped' => $skipped, 'json_rows' => count($json)];
}

/** @return array<string, array<string, mixed>> */
function jeab_sms_store_load(): array
{
    $pdo = jeab_sms_store_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_sms_json_read();
    }
    try {
        jeab_sms_store_ensure($pdo);
        $sql = jeab_sms_store_read_sql($pdo);
    } catch (Throwable) {
        error_log('SMS: SQL-läsning misslyckades.');

        return [];
    }
    if (jeab_sms_store_is_imported($pdo) || $sql !== []) {
        return $sql;
    }

    return jeab_sms_json_read();
}

/** @param callable(PDO): mixed $fn */
function jeab_sms_store_write_tx(PDO $pdo, callable $fn): mixed
{
    if (function_exists('jeab_pdo_in_tx') && jeab_pdo_in_tx($pdo)) {
        return $fn($pdo);
    }
    if (jeab_sms_store_driver($pdo) === 'sqlite' && function_exists('jeab_pdo_begin_immediate') && function_exists('jeab_pdo_end')) {
        jeab_pdo_begin_immediate($pdo);
        try {
            $result = $fn($pdo);
            jeab_pdo_end($pdo, true);

            return $result;
        } catch (Throwable $e) {
            try {
                jeab_pdo_end($pdo, false);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    return jeab_pdo_transaction($pdo, $fn);
}

function jeab_sms_claim_ttl(): int
{
    return 600;
}

/**
 * @param array<string, mixed> $prev
 */
function jeab_sms_channel_claim_decision(array $prev, string $startsAt, string $channel): string
{
    if ($channel === 'email') {
        if (!empty($prev['email'])) {
            return 'sent';
        }
    } elseif (!empty($prev['sms']) && (string) ($prev['starts_at'] ?? '') === $startsAt) {
        return 'sent';
    }
    $at = (int) ($prev[$channel . '_claim_at'] ?? 0);
    $claimStarts = (string) ($prev[$channel . '_claim_starts'] ?? '');
    $sameSlot = $channel !== 'sms' || $claimStarts === $startsAt;
    if ($at > 0 && $sameSlot && (time() - $at) < jeab_sms_claim_ttl()) {
        return 'held';
    }

    return 'send';
}

function jeab_sms_claim_file_path(int $bookingId, string $channel, string $startsAt): ?string
{
    if (!function_exists('jeab_db_slot_dir')) {
        return null;
    }
    $dir = jeab_db_slot_dir() . '/sms-claims';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $slot = substr(hash('sha256', $startsAt), 0, 12);

    return $dir . '/' . $bookingId . '-' . $channel . '-' . $slot;
}

/** @return 'send'|'held'|'sent'|'unstored' */
function jeab_sms_channel_claim_file(int $bookingId, string $startsAt, string $channel): string
{
    $path = jeab_sms_claim_file_path($bookingId, $channel, $startsAt);
    if ($path === null) {
        return 'unstored';
    }
    $fh = fopen($path, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }

        return 'unstored';
    }
    try {
        rewind($fh);
        $raw = stream_get_contents($fh);
        $state = is_string($raw) ? trim($raw) : '';
        if ($state === 'sent') {
            return 'sent';
        }
        if (str_starts_with($state, 'c:') && (time() - (int) substr($state, 2)) < jeab_sms_claim_ttl()) {
            return 'held';
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, 'c:' . time());
        fflush($fh);

        return 'send';
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function jeab_sms_channel_release_file(int $bookingId, string $channel, string $startsAt): void
{
    $path = jeab_sms_claim_file_path($bookingId, $channel, $startsAt);
    if ($path === null || !is_file($path)) {
        return;
    }
    $fh = fopen($path, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }

        return;
    }
    $raw = stream_get_contents($fh);
    $state = is_string($raw) ? trim($raw) : '';
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($state !== 'sent') {
        @unlink($path);
    }
}

/** Filflagga som överlever en misslyckad SQL-skrivning efter ett lyckat utskick. */
function jeab_sms_channel_file_is_sent(int $bookingId, string $channel, string $startsAt): bool
{
    if (!function_exists('jeab_db_slot_dir')) {
        return false;
    }
    $dir = jeab_db_slot_dir() . '/sms-claims';
    if (!is_dir($dir)) {
        return false;
    }
    $path = jeab_sms_claim_file_path($bookingId, $channel, $startsAt);
    if ($path === null || !is_file($path)) {
        return false;
    }
    $raw = @file_get_contents($path);

    return is_string($raw) && trim($raw) === 'sent';
}

function jeab_sms_channel_file_mark_sent(int $bookingId, string $channel, string $startsAt): void
{
    $path = jeab_sms_claim_file_path($bookingId, $channel, $startsAt);
    if ($path === null) {
        return;
    }
    $fh = fopen($path, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }

        return;
    }
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, 'sent');
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/**
 * Tar bokningen innan leverantören anropas.
 * sent = flaggan är redan satt. held = ett annat anrop äger anspråket.
 * send = den här körningen får skicka. unstored = anspråket gick inte att spara.
 *
 * @return 'send'|'held'|'sent'|'unstored'
 */
function jeab_sms_channel_claim(int $bookingId, string $startsAt, string $channel): string
{
    if ($bookingId <= 0 || !in_array($channel, ['sms', 'email'], true)) {
        return 'unstored';
    }
    $pdo = jeab_sms_store_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_sms_channel_claim_file($bookingId, $startsAt, $channel);
    }
    try {
        if (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo)) {
            jeab_sms_store_ensure($pdo);
        }
        $claimed = jeab_sms_store_write_tx($pdo, static function (PDO $pdo) use ($bookingId, $startsAt, $channel): string {
            if (!jeab_sms_store_is_imported($pdo)) {
                jeab_sms_store_copy_json($pdo);
            }
            jeab_sms_store_insert_ignore($pdo, $bookingId, '{}');
            $sql = 'SELECT payload FROM sms_sent WHERE booking_id = ?';
            if (jeab_sms_store_driver($pdo) === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$bookingId]);
            $raw = $stmt->fetchColumn();
            $prev = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($prev)) {
                $prev = [];
            }
            $decision = jeab_sms_channel_claim_decision($prev, $startsAt, $channel);
            if ($decision === 'send' && jeab_sms_channel_file_is_sent($bookingId, $channel, $startsAt)) {
                return 'sent';
            }
            if ($decision !== 'send') {
                return $decision;
            }
            $prev[$channel . '_claim_at'] = time();
            $prev[$channel . '_claim_starts'] = $startsAt;
            if (!isset($prev['starts_at']) || trim((string) $prev['starts_at']) === '') {
                $prev['starts_at'] = $startsAt;
            }
            $payload = jeab_sms_store_payload($prev);
            if ($payload === null) {
                return 'unstored';
            }
            jeab_sms_store_upsert($pdo, $bookingId, $payload);
            jeab_sms_store_mark_imported($pdo);

            return 'send';
        });

        return is_string($claimed) ? $claimed : 'unstored';
    } catch (Throwable) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('sms', 'sms_sent-anspråk misslyckades');
        }

        return 'unstored';
    }
}

function jeab_sms_channel_release(int $bookingId, string $channel, string $startsAt): void
{
    if ($bookingId <= 0 || !in_array($channel, ['sms', 'email'], true)) {
        return;
    }
    $pdo = jeab_sms_store_pdo();
    if (!$pdo instanceof PDO) {
        jeab_sms_channel_release_file($bookingId, $channel, $startsAt);

        return;
    }
    jeab_sms_store_update($bookingId, static function (?array $prev) use ($channel): array {
        if (!is_array($prev)) {
            throw new RuntimeException('jeab-sms-skip');
        }
        unset($prev[$channel . '_claim_at'], $prev[$channel . '_claim_starts']);

        return $prev;
    });
}

/** @param callable(?array<string, mixed>): array<string, mixed> $fn */
function jeab_sms_store_update(int $bookingId, callable $fn): bool
{
    if ($bookingId < 0) {
        return false;
    }
    $pdo = jeab_sms_store_pdo();
    if (!$pdo instanceof PDO) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('sms', 'sms_sent saknar databas');
        }

        return false;
    }
    try {
        if (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo)) {
            jeab_sms_store_ensure($pdo);
        }
        jeab_sms_store_write_tx($pdo, static function (PDO $pdo) use ($bookingId, $fn): void {
            if (!jeab_sms_store_is_imported($pdo)) {
                jeab_sms_store_copy_json($pdo);
            }
            $sql = 'SELECT payload FROM sms_sent WHERE booking_id = ?';
            if (jeab_sms_store_driver($pdo) === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$bookingId]);
            $raw = $stmt->fetchColumn();
            $prev = is_string($raw) ? json_decode($raw, true) : null;
            $row = $fn(is_array($prev) ? $prev : null);
            if (!is_array($row)) {
                throw new RuntimeException('jeab-sms-skip');
            }
            $payload = jeab_sms_store_payload($row);
            if ($payload === null) {
                throw new RuntimeException('jeab-sms-skip');
            }
            jeab_sms_store_upsert($pdo, $bookingId, $payload);
            jeab_sms_store_mark_imported($pdo);
        });

        return true;
    } catch (Throwable $e) {
        if ($e->getMessage() === 'jeab-sms-skip') {
            return true;
        }
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('sms', 'sms_sent kunde inte skrivas');
        }

        return false;
    }
}

function jeab_sms_import_is_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === 0 || $dry === '0' || $dry === false);
}

/** @param array<string, mixed> $input
 *  @return array<string, mixed>
 */
function jeab_sms_import_dispatch(array $input): array
{
    $dry = jeab_sms_import_is_dry($input);
    $pdo = jeab_sms_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'dry' => $dry ? 1 : 0, 'status' => 400];
    }
    jeab_sms_store_ensure($pdo);
    $json = jeab_sms_json_read();
    if (jeab_sms_store_is_imported($pdo)) {
        return [
            'ok' => true,
            'dry' => $dry ? 1 : 0,
            'inserted' => 0,
            'skipped' => count($json),
            'json_rows' => count($json),
        ];
    }
    if ($dry) {
        $have = jeab_sms_store_read_sql($pdo);
        $inserted = 0;
        $skipped = 0;
        foreach ($json as $key => $row) {
            unset($row);
            $id = (int) $key;
            if ($id < 0 || isset($have[(string) $id])) {
                $skipped++;
                continue;
            }
            $inserted++;
        }
        return [
            'ok' => true,
            'dry' => 1,
            'inserted' => $inserted,
            'skipped' => $skipped,
            'json_rows' => count($json),
        ];
    }
    try {
        $copied = jeab_pdo_transaction($pdo, static function (PDO $pdo): array {
            $copied = jeab_sms_store_copy_json($pdo);
            jeab_sms_store_mark_imported($pdo);

            return $copied;
        });
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Importen stoppades.', 'dry' => 0, 'status' => 400];
    }
    return [
        'ok' => true,
        'dry' => 0,
        'inserted' => $copied['inserted'],
        'skipped' => $copied['skipped'],
        'json_rows' => $copied['json_rows'],
    ];
}
