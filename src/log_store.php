<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Loggar och cache som tidigare låg i JSON.
 * Tabellerna skapas här. src/db.php ändras inte.
 * Saknas SQL läses JSON som reserv. Skrivning till JSON sker bara när SQL saknas.
 * Hook logs.import är idempotent. dry saknas betyder dry=1 och skriver inget.
 * who_diag äldre än sparfönstret räknas inte: importen beskär dem annars direkt,
 * och nästa dry=1 ser samma filrader som nya. logs.dedupe tar bort extra rader
 * med samma kind och line_hash. dry saknas betyder dry=1 där också.
 */

function jeab_log_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_log_is_mysql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function jeab_log_pdo_raw(): ?PDO
{
    $injected = $GLOBALS['_JEAB_LOG_PDO'] ?? null;
    if ($injected instanceof PDO) {
        return $injected;
    }
    if (!function_exists('jeab_driver') || jeab_driver() !== 'mysql') {
        return null;
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    if (!$store instanceof JeabPdoStore) {
        return null;
    }
    return $store->pdo();
}

function jeab_log_pdo(): ?PDO
{
    $pdo = jeab_log_pdo_raw();
    if ($pdo instanceof PDO) {
        jeab_log_ensure($pdo);
    }
    return $pdo;
}

function jeab_log_ensure(PDO $pdo): void
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
    if (jeab_log_is_mysql($pdo)) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log_docs (
                doc_key VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (doc_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log_lines (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(32) NOT NULL,
                line_hash CHAR(64) NOT NULL,
                at VARCHAR(40) NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY log_lines_kind_hash (kind, line_hash),
                KEY log_lines_kind_at (kind, at),
                KEY log_lines_kind_id (kind, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        try {
            $idx = $pdo->query("SHOW INDEX FROM log_lines WHERE Key_name = 'log_lines_kind_id'");
            $has = $idx instanceof PDOStatement && $idx->fetch() !== false;
            if (!$has) {
                $pdo->exec('ALTER TABLE log_lines ADD INDEX log_lines_kind_id (kind, id)');
            }
        } catch (Throwable) {
        }
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log_who_picks (
                pick_key VARCHAR(191) NOT NULL,
                person VARCHAR(64) NOT NULL,
                picked_at BIGINT NOT NULL,
                PRIMARY KEY (pick_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log_webshop_orders (
                order_key VARCHAR(191) NOT NULL,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (order_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log_graph_week (
                cache_key VARCHAR(255) NOT NULL,
                cached_at INT NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (cache_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS log_graph_events (
                email VARCHAR(191) NOT NULL,
                booking_id INT UNSIGNED NOT NULL,
                event_id TEXT NOT NULL,
                PRIMARY KEY (email, booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS log_docs (
            doc_key TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS log_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            line_hash TEXT NOT NULL,
            at TEXT NOT NULL,
            payload TEXT NOT NULL,
            UNIQUE (kind, line_hash)
        )'
    );
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_lines_kind_at ON log_lines (kind, at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS log_lines_kind_id ON log_lines (kind, id)');
    } catch (Throwable) {
        // Migrate-schemat har line_key och saknar id. Indexet får inte fälla läsningen.
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS log_who_picks (
            pick_key TEXT PRIMARY KEY,
            person TEXT NOT NULL,
            picked_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS log_webshop_orders (
            order_key TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS log_graph_week (
            cache_key TEXT PRIMARY KEY,
            cached_at INTEGER NOT NULL,
            payload TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS log_graph_events (
            email TEXT NOT NULL,
            booking_id INTEGER NOT NULL,
            event_id TEXT NOT NULL,
            PRIMARY KEY (email, booking_id)
        )'
    );
}

function jeab_log_table_exists(PDO $pdo, string $table): bool
{
    if (function_exists('jeab_schema_probe_allowed') && !jeab_schema_probe_allowed()) {
        return true;
    }
    try {
        if (jeab_log_is_mysql($pdo)) {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return (bool) $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** @return list<string> */
function jeab_log_kinds(): array
{
    return [
        'calendar_log',
        'graph_events',
        'graph_week',
        'who_picks',
        'who_diag',
        'reminder_follow',
        'reminder_hook',
        'webshop_orders',
        'app_error',
        'fellogg_open_asks',
    ];
}

function jeab_log_now(): string
{
    return function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
}

function jeab_log_doc_get_pdo(PDO $pdo, string $key): ?array
{
    $stmt = $pdo->prepare('SELECT payload FROM log_docs WHERE doc_key = ?');
    $stmt->execute([$key]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function jeab_log_doc_put_pdo(PDO $pdo, string $key, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }
    $at = jeab_log_now();
    if (jeab_log_is_mysql($pdo)) {
        $sql = 'INSERT INTO log_docs (doc_key, payload, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)';
    } else {
        $sql = 'INSERT INTO log_docs (doc_key, payload, updated_at) VALUES (?, ?, ?) ON CONFLICT(doc_key) DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at';
    }
    $pdo->prepare($sql)->execute([$key, $json, $at]);
}

function jeab_log_doc_get(string $key): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_docs')) {
            return null;
        }
        return jeab_log_doc_get_pdo($pdo, $key);
    } catch (Throwable) {
        return null;
    }
}

function jeab_log_flag(PDO $pdo, string $kind): bool
{
    return jeab_log_doc_get_pdo($pdo, 'flag:' . $kind) !== null;
}

function jeab_log_flag_set(PDO $pdo, string $kind): void
{
    jeab_log_doc_put_pdo($pdo, 'flag:' . $kind, ['ok' => true]);
}

function jeab_log_json_file(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** @return list<string> */
function jeab_log_text_lines(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

function jeab_log_line_hash(string $line): string
{
    return hash('sha256', $line);
}

function jeab_log_line_exists(PDO $pdo, string $kind, string $hash): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM log_lines WHERE kind = ? AND line_hash = ?');
    $stmt->execute([$kind, $hash]);
    return (bool) $stmt->fetchColumn();
}

function jeab_log_line_insert(PDO $pdo, string $kind, string $hash, string $at, string $payload): void
{
    if (jeab_log_is_mysql($pdo)) {
        $sql = 'INSERT IGNORE INTO log_lines (kind, line_hash, at, payload) VALUES (?, ?, ?, ?)';
    } else {
        $sql = 'INSERT OR IGNORE INTO log_lines (kind, line_hash, at, payload) VALUES (?, ?, ?, ?)';
    }
    $pdo->prepare($sql)->execute([$kind, $hash, $at, $payload]);
}

function jeab_log_who_diag_cutoff(): string
{
    $keep = defined('JEAB_WHO_DIAG_KEEP') ? (int) JEAB_WHO_DIAG_KEEP : 3 * 24 * 60 * 60;
    return date('Y-m-d H:i:s', time() - $keep);
}

/**
 * Samma rad två gånger i filen, eller en rad som redan ligger i SQL, är en dubblett.
 * Ogiltig JSON skrivs inte. who_diag med at äldre än sparfönstret skrivs inte:
 * beskärningen tar bort den direkt och nästa dry=1 skulle räkna den igen.
 *
 * @param list<string> $lines
 * @return array{lines:list<string>,skipped_dupe:int,skipped_invalid:int,skipped_stale:int}
 */
function jeab_log_classify_lines(PDO $pdo, string $kind, array $lines): array
{
    $keep = [];
    $skippedDupe = 0;
    $skippedInvalid = 0;
    $skippedStale = 0;
    $seen = [];
    $cutoff = $kind === 'who_diag' ? jeab_log_who_diag_cutoff() : '';
    foreach ($lines as $line) {
        if (!is_string($line)) {
            $skippedInvalid++;
            continue;
        }
        $line = trim($line);
        if ($line === '') {
            $skippedInvalid++;
            continue;
        }
        $hash = jeab_log_line_hash($line);
        if (isset($seen[$hash]) || jeab_log_line_exists($pdo, $kind, $hash)) {
            $skippedDupe++;
            $seen[$hash] = true;
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            $skippedInvalid++;
            continue;
        }
        if ($cutoff !== '') {
            $at = trim((string) ($decoded['at'] ?? ''));
            if ($at !== '' && $at < $cutoff) {
                $skippedStale++;
                continue;
            }
        }
        $seen[$hash] = true;
        $keep[] = $line;
    }
    return [
        'lines' => $keep,
        'skipped_dupe' => $skippedDupe,
        'skipped_invalid' => $skippedInvalid,
        'skipped_stale' => $skippedStale,
    ];
}

/** @return array{would:int,imported:int,skipped_dupe:int,skipped_invalid:int,skipped_stale:int} */
function jeab_log_import_blank(): array
{
    return [
        'would' => 0,
        'imported' => 0,
        'skipped_dupe' => 0,
        'skipped_invalid' => 0,
        'skipped_stale' => 0,
    ];
}

function jeab_log_line_count(PDO $pdo, string $kind): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM log_lines WHERE kind = ?');
    $stmt->execute([$kind]);
    return (int) $stmt->fetchColumn();
}

/**
 * Payload-rader. Migrate-tabellen saknar id, log_store har id.
 *
 * @return list<array<string, mixed>>|null
 */
function jeab_log_lines_payloads(PDO $pdo, string $kind): ?array
{
    $queries = [
        'SELECT payload FROM log_lines WHERE kind = ?',
        'SELECT payload FROM log_lines WHERE kind = ? ORDER BY id ASC',
    ];
    foreach ($queries as $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$kind]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $payload) {
                if (!is_string($payload) || $payload === '') {
                    continue;
                }
                $row = json_decode($payload, true);
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        } catch (Throwable) {
            continue;
        }
    }

    return null;
}

/** @return list<array<string, mixed>>|null */
function jeab_log_lines_decoded(string $kind): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_lines')) {
            return null;
        }
        $count = jeab_log_line_count($pdo, $kind);
        if ($count === 0 && !jeab_log_flag($pdo, $kind)) {
            return null;
        }
        $out = jeab_log_lines_payloads($pdo, $kind);

        return $out;
    } catch (Throwable) {
        return null;
    }
}

function jeab_log_kind_raw(string $kind): ?string
{
    if ($kind === 'app_error' && function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('app_errors') === 'json') {
        return null;
    }
    $rows = jeab_log_lines_decoded($kind);
    if ($rows === null) {
        return null;
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $lines = [];
    $seen = [];
    foreach ($rows as $row) {
        $line = json_encode($row, $flags);
        if (!is_string($line)) {
            continue;
        }
        $id = trim((string) ($row['fingerprint'] ?? '')) . '|' . trim((string) ($row['time'] ?? '')) . '|' . trim((string) ($row['message'] ?? ''));
        $seen[$id] = true;
        $lines[] = $line;
    }
    $mergeFile = $kind === 'app_error';
    if ($mergeFile && function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('app_errors') === 'sql') {
        $flagPdo = function_exists('jeab_log_pdo') ? jeab_log_pdo() : null;
        if ($flagPdo instanceof PDO && jeab_log_flag($flagPdo, 'app_error')) {
            $mergeFile = false;
        }
    }
    if ($mergeFile && function_exists('jeab_app_errors_path')) {
        $path = jeab_app_errors_path();
        $file = is_file($path) ? (string) @file_get_contents($path) : '';
        foreach (preg_split("/\n/", $file) ?: [] as $extra) {
            $extra = trim((string) $extra);
            if ($extra === '') {
                continue;
            }
            $row = json_decode($extra, true);
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['fingerprint'] ?? '')) . '|' . trim((string) ($row['time'] ?? '')) . '|' . trim((string) ($row['message'] ?? ''));
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $lines[] = $extra;
        }
    }
    if ($lines === []) {
        return '';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Varför SQL-append föll. En rad per typ och anrop i PHP:s fellogg (ingen fil under data/).
 * Utan orsak syntes bara att JSONL-reserven växte (who_diag, app_errors).
 */
function jeab_log_append_failed(string $kind, string $step, ?Throwable $e = null): void
{
    $reason = $step . ($e instanceof Throwable ? ': ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 200) : '');
    $GLOBALS['_JEAB_LOG_APPEND_FAIL'][$kind] = $reason;
    static $said = [];
    if (isset($said[$kind])) {
        return;
    }
    $said[$kind] = true;
    error_log('jeab log_lines append ' . $kind . ' föll: ' . $reason);
}

function jeab_log_line_append_raw(string $kind, string $line): bool
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            jeab_log_append_failed($kind, 'ingen_pdo');
            return false;
        }
        try {
            jeab_log_import_kind($kind, $pdo, false, true);
        } catch (Throwable $e) {
            // Importen av gamla filrader får inte stoppa den nya raden.
            jeab_log_append_failed($kind, 'import', $e);
        }
        $line = trim($line);
        if ($line === '') {
            return true;
        }
        $decoded = json_decode($line, true);
        $at = is_array($decoded) ? trim((string) ($decoded['at'] ?? $decoded['time'] ?? '')) : '';
        jeab_log_line_insert($pdo, $kind, jeab_log_line_hash($line), $at, $line);
        if ($kind === 'who_diag') {
            jeab_log_who_diag_prune($pdo);
        }
        if ($kind === 'app_error') {
            jeab_log_app_error_trim($pdo);
        }
        return true;
    } catch (Throwable $e) {
        jeab_log_append_failed($kind, 'insert', $e);
        return false;
    }
}

function jeab_log_who_diag_prune(PDO $pdo): void
{
    $cut = jeab_log_who_diag_cutoff();
    $pdo->prepare("DELETE FROM log_lines WHERE kind = 'who_diag' AND at <> '' AND at < ?")->execute([$cut]);
    $ids = $pdo->query("SELECT id FROM log_lines WHERE kind = 'who_diag' ORDER BY id DESC LIMIT 3000")->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) < 3000) {
        return;
    }
    $min = (int) min(array_map('intval', $ids));
    $pdo->prepare("DELETE FROM log_lines WHERE kind = 'who_diag' AND id < ?")->execute([$min]);
}

function jeab_log_app_error_trim(PDO $pdo): void
{
    $max = 10 * 1024 * 1024;
    $sum = (int) $pdo->query("SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM log_lines WHERE kind = 'app_error'")->fetchColumn();
    while ($sum > $max) {
        $row = $pdo->query("SELECT id, LENGTH(payload) AS n FROM log_lines WHERE kind = 'app_error' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $pdo->prepare('DELETE FROM log_lines WHERE id = ?')->execute([(int) $row['id']]);
        $sum -= (int) ($row['n'] ?? 0);
    }
}

function jeab_log_calendar_source(PDO $pdo): ?array
{
    if (function_exists('jeab_sql_doc_get') && jeab_log_table_exists($pdo, 'json_docs')) {
        try {
            $doc = jeab_sql_doc_get($pdo, 'calendar_log');
            if (is_array($doc)) {
                return $doc;
            }
        } catch (Throwable) {
        }
    }
    if (!function_exists('jeab_calendar_log_path')) {
        return null;
    }
    return jeab_log_json_file(jeab_calendar_log_path());
}

function jeab_log_calendar_save(array $payload): bool
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        jeab_log_doc_put_pdo($pdo, 'calendar_log', $payload);
        jeab_log_flag_set($pdo, 'calendar_log');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string, array{person:string,at:int}>|null */
function jeab_log_who_picks(): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_who_picks')) {
            return null;
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM log_who_picks')->fetchColumn();
        if ($count === 0 && !jeab_log_flag($pdo, 'who_picks')) {
            return null;
        }
        $out = [];
        foreach ($pdo->query('SELECT pick_key, person, picked_at FROM log_who_picks') as $row) {
            $key = (string) ($row['pick_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = ['person' => (string) ($row['person'] ?? ''), 'at' => (int) ($row['picked_at'] ?? 0)];
        }
        return $out;
    } catch (Throwable) {
        return null;
    }
}

function jeab_log_who_pick_put(string $key, string $personId): bool
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || $key === '' || $personId === '') {
            return false;
        }
        jeab_log_import_kind('who_picks', $pdo, false, true);
        $at = time();
        if (jeab_log_is_mysql($pdo)) {
            $sql = 'INSERT INTO log_who_picks (pick_key, person, picked_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE person = VALUES(person), picked_at = VALUES(picked_at)';
        } else {
            $sql = 'INSERT INTO log_who_picks (pick_key, person, picked_at) VALUES (?, ?, ?) ON CONFLICT(pick_key) DO UPDATE SET person = excluded.person, picked_at = excluded.picked_at';
        }
        $pdo->prepare($sql)->execute([$key, $personId, $at]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_log_who_diag_append(array $row): bool
{
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return false;
    }
    return jeab_log_line_append_raw('who_diag', $line);
}

/** @return list<array<string, mixed>>|null */
function jeab_log_who_diag_tail(int $limit): ?array
{
    $rows = jeab_log_lines_decoded('who_diag');
    if ($rows === null) {
        return null;
    }
    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, -$limit);
    }
    return $rows;
}

/** @return list<array<string, mixed>>|null */
function jeab_log_reminder_read(string $kind, int $id, int $sinceTs): ?array
{
    $rows = jeab_log_lines_decoded($kind);
    if ($rows === null || $id < 1) {
        return $rows === null ? null : [];
    }
    $out = [];
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) !== $id) {
            continue;
        }
        $at = strtotime((string) ($row['at'] ?? '')) ?: 0;
        if ($sinceTs > 0 && $at < $sinceTs) {
            continue;
        }
        $out[] = $row;
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
    });
    return $out;
}

/** @return array<string, array<string, mixed>>|null */
function jeab_log_webshop_read(): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_webshop_orders')) {
            return null;
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM log_webshop_orders')->fetchColumn();
        if ($count === 0 && !jeab_log_flag($pdo, 'webshop_orders')) {
            return null;
        }
        $out = [];
        foreach ($pdo->query('SELECT order_key, payload FROM log_webshop_orders') as $row) {
            $key = (string) ($row['order_key'] ?? '');
            $data = json_decode((string) ($row['payload'] ?? ''), true);
            if ($key !== '' && is_array($data)) {
                $out[$key] = $data;
            }
        }
        return $out;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, array<string, mixed>> $index */
function jeab_log_webshop_replace(array $index): bool
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($index): void {
        $pdo->exec('DELETE FROM log_webshop_orders');
        $at = jeab_log_now();
        $stmt = $pdo->prepare('INSERT INTO log_webshop_orders (order_key, payload, updated_at) VALUES (?, ?, ?)');
        foreach ($index as $key => $row) {
            if (!is_string($key) || $key === '' || !is_array($row)) {
                continue;
            }
            $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                continue;
            }
            $stmt->execute([$key, $json, $at]);
        }
        jeab_log_flag_set($pdo, 'webshop_orders');
        });
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return list<array<string, mixed>>|null */
function jeab_log_graph_week_get(string $key): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || $key === '' || !jeab_log_table_exists($pdo, 'log_graph_week')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT cached_at, payload FROM log_graph_week WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ((time() - (int) ($row['cached_at'] ?? 0)) >= 90) {
            return null;
        }
        $data = json_decode((string) ($row['payload'] ?? ''), true);
        return is_array($data) ? $data : null;
    } catch (Throwable) {
        return null;
    }
}

/** @return array{at: int, events: list<array<string, mixed>>}|null */
function jeab_log_graph_week_peek(string $key): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || $key === '' || !jeab_log_table_exists($pdo, 'log_graph_week')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT cached_at, payload FROM log_graph_week WHERE cache_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $data = json_decode((string) ($row['payload'] ?? ''), true);

        return [
            'at' => (int) ($row['cached_at'] ?? 0),
            'events' => is_array($data) ? array_values(array_filter($data, 'is_array')) : [],
        ];
    } catch (Throwable) {
        return null;
    }
}

function jeab_log_graph_week_note(string $key, string $company): void
{
    if ($key === '') {
        return;
    }
    $company = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($company) ?? 'jarfalla')
        : ($company !== '' ? $company : 'jarfalla');
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_docs')) {
            return;
        }
        $doc = jeab_log_doc_get_pdo($pdo, 'graph_week_want');
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $next = [];
        $seen = false;
        foreach ($items as $item) {
            if (!is_array($item) || (string) ($item['key'] ?? '') === '') {
                continue;
            }
            if ((string) $item['key'] === $key) {
                $item['company'] = $company;
                $item['at'] = time();
                $seen = true;
            }
            $next[] = $item;
        }
        if (!$seen) {
            $next[] = ['key' => $key, 'company' => $company, 'at' => time()];
        }
        if (count($next) > 12) {
            $next = array_slice($next, -12);
        }
        jeab_log_doc_put_pdo($pdo, 'graph_week_want', ['items' => $next]);
    } catch (Throwable) {
    }
}

/** @return list<array{key: string, company: string}> */
function jeab_log_graph_week_due(int $limit): array
{
    $limit = max(1, min(4, $limit));
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_docs')) {
            return [];
        }
        $doc = jeab_log_doc_get_pdo($pdo, 'graph_week_want');
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];
        $now = time();
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            $asked = (int) ($item['at'] ?? 0);
            if ($key === '' || $asked < $now - 86400) {
                continue;
            }
            $peek = jeab_log_graph_week_peek($key);
            $cachedAt = is_array($peek) ? (int) ($peek['at'] ?? 0) : 0;
            if ($cachedAt > 0 && ($now - $cachedAt) < 90) {
                continue;
            }
            $company = (string) ($item['company'] ?? 'jarfalla');
            $out[] = ['key' => $key, 'company' => $company !== '' ? $company : 'jarfalla'];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/** @param list<array<string, mixed>> $events */
function jeab_log_graph_week_put(string $key, array $events): bool
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || $key === '') {
            return false;
        }
        $json = json_encode(array_values($events), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $at = time();
        if (jeab_log_is_mysql($pdo)) {
            $sql = 'INSERT INTO log_graph_week (cache_key, cached_at, payload) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cached_at = VALUES(cached_at), payload = VALUES(payload)';
        } else {
            $sql = 'INSERT INTO log_graph_week (cache_key, cached_at, payload) VALUES (?, ?, ?) ON CONFLICT(cache_key) DO UPDATE SET cached_at = excluded.cached_at, payload = excluded.payload';
        }
        $pdo->prepare($sql)->execute([$key, $at, $json]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_log_graph_week_forget(): void
{
    try {
        $pdo = jeab_log_pdo();
        if ($pdo instanceof PDO && jeab_log_table_exists($pdo, 'log_graph_week')) {
            $pdo->exec('DELETE FROM log_graph_week');
        }
    } catch (Throwable) {
    }
}

function jeab_log_app_error_append(string $line): bool
{
    return jeab_log_line_append_raw('app_error', $line);
}

/** @return list<array<string, mixed>>|null */
function jeab_log_open_asks_read(): ?array
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO || !jeab_log_table_exists($pdo, 'log_docs')) {
            return null;
        }
        if (jeab_log_doc_get_pdo($pdo, 'fellogg_open_asks') === null && !jeab_log_flag($pdo, 'fellogg_open_asks')) {
            return null;
        }
        $doc = jeab_log_doc_get_pdo($pdo, 'fellogg_open_asks');
        return is_array($doc) ? $doc : [];
    } catch (Throwable) {
        return null;
    }
}

/** @param list<array<string, mixed>> $asks */
function jeab_log_open_asks_write(array $asks): bool
{
    try {
        $pdo = jeab_log_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        jeab_log_doc_put_pdo($pdo, 'fellogg_open_asks', array_values($asks));
        jeab_log_flag_set($pdo, 'fellogg_open_asks');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array{would:int,imported:int}
 */
function jeab_log_import_kind(string $kind, ?PDO $pdo, bool $dry, bool $ready): array
{
    if (!$pdo instanceof PDO) {
        return ['would' => jeab_log_import_file_count($kind), 'imported' => 0];
    }
    if (!$ready || !jeab_log_table_exists($pdo, 'log_docs')) {
        return ['would' => jeab_log_import_file_count($kind), 'imported' => 0];
    }
    return match ($kind) {
        'calendar_log' => jeab_log_import_calendar($pdo, $dry),
        'graph_events' => jeab_log_import_graph_events($pdo, $dry),
        'graph_week' => jeab_log_import_graph_week($pdo, $dry),
        'who_picks' => jeab_log_import_who_picks($pdo, $dry),
        'who_diag' => jeab_log_import_lines_kind($pdo, 'who_diag', function_exists('jeab_who_diag_path') ? jeab_who_diag_path() : '', $dry),
        'reminder_follow' => jeab_log_import_lines_kind($pdo, 'reminder_follow', function_exists('jeab_follow_reminder_audit_path') ? jeab_follow_reminder_audit_path() : '', $dry),
        'reminder_hook' => jeab_log_import_lines_kind($pdo, 'reminder_hook', function_exists('jeab_inbox_reminder_hook_audit_path') ? jeab_inbox_reminder_hook_audit_path() : '', $dry),
        'webshop_orders' => jeab_log_import_webshop($pdo, $dry),
        'app_error' => jeab_log_import_lines_kind($pdo, 'app_error', function_exists('jeab_app_errors_path') ? jeab_app_errors_path() : '', $dry),
        'fellogg_open_asks' => jeab_log_import_open_asks($pdo, $dry),
        default => ['would' => 0, 'imported' => 0],
    };
}

function jeab_log_graph_event_file_count(): int
{
    if (!function_exists('jeab_gp_events_path')) {
        return 0;
    }
    $map = jeab_log_json_file(jeab_gp_events_path());
    if ($map === null) {
        return 0;
    }
    $n = 0;
    foreach ($map as $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $bookingId => $eventId) {
            if ((int) $bookingId > 0 && trim((string) $eventId) !== '') {
                $n++;
            }
        }
    }
    return $n;
}

function jeab_log_import_file_count(string $kind): int
{
    return match ($kind) {
        'calendar_log' => function_exists('jeab_calendar_log_path') && is_file(jeab_calendar_log_path()) ? 1 : 0,
        'graph_events' => jeab_log_graph_event_file_count(),
        'graph_week' => function_exists('jeab_gp_week_events_cache_path') && is_array($data = jeab_log_json_file(jeab_gp_week_events_cache_path())) ? count($data) : 0,
        'who_picks' => function_exists('jeab_who_picks_path') && is_array($data = jeab_log_json_file(jeab_who_picks_path())) ? count($data) : 0,
        'who_diag' => function_exists('jeab_who_diag_path') ? count(jeab_log_text_lines(jeab_who_diag_path())) : 0,
        'reminder_follow' => function_exists('jeab_follow_reminder_audit_path') ? count(jeab_log_text_lines(jeab_follow_reminder_audit_path())) : 0,
        'reminder_hook' => function_exists('jeab_inbox_reminder_hook_audit_path') ? count(jeab_log_text_lines(jeab_inbox_reminder_hook_audit_path())) : 0,
        'webshop_orders' => function_exists('jeab_webshop_index_path') && is_array($data = jeab_log_json_file(jeab_webshop_index_path())) ? count($data) : 0,
        'app_error' => function_exists('jeab_app_errors_path') ? count(jeab_log_text_lines(jeab_app_errors_path())) : 0,
        'fellogg_open_asks' => function_exists('jeab_fellogg_open_asks_path') && is_file(jeab_fellogg_open_asks_path()) ? 1 : 0,
        default => 0,
    };
}

/** @return array{would:int,imported:int} */
function jeab_log_import_calendar(PDO $pdo, bool $dry): array
{
    if (jeab_log_doc_get_pdo($pdo, 'calendar_log') !== null) {
        return ['would' => 0, 'imported' => 0];
    }
    $source = jeab_log_calendar_source($pdo);
    if ($source === null) {
        return ['would' => 0, 'imported' => 0];
    }
    if ($dry) {
        return ['would' => 1, 'imported' => 0];
    }
    jeab_log_doc_put_pdo($pdo, 'calendar_log', $source);
    jeab_log_flag_set($pdo, 'calendar_log');
    return ['would' => 1, 'imported' => 1];
}

/** @return array{would:int,imported:int} */
function jeab_log_import_graph_events(PDO $pdo, bool $dry): array
{
    if (!function_exists('jeab_gp_events_path')) {
        return ['would' => 0, 'imported' => 0];
    }
    $map = jeab_log_json_file(jeab_gp_events_path());
    if ($map === null) {
        return ['would' => 0, 'imported' => 0];
    }
    $missing = [];
    $stmt = $pdo->prepare('SELECT 1 FROM log_graph_events WHERE email = ? AND booking_id = ?');
    foreach ($map as $email => $rows) {
        if (!is_string($email) || !is_array($rows)) {
            continue;
        }
        foreach ($rows as $bookingId => $eventId) {
            $bookingId = (int) $bookingId;
            $eventId = trim((string) $eventId);
            if ($bookingId <= 0 || $eventId === '') {
                continue;
            }
            $stmt->execute([$email, $bookingId]);
            if (!$stmt->fetchColumn()) {
                $missing[] = [$email, $bookingId, $eventId];
            }
        }
    }
    if ($dry) {
        return ['would' => count($missing), 'imported' => 0];
    }
    $ins = $pdo->prepare('INSERT INTO log_graph_events (email, booking_id, event_id) VALUES (?, ?, ?)');
    foreach ($missing as [$email, $bookingId, $eventId]) {
        $ins->execute([$email, $bookingId, $eventId]);
    }
    jeab_log_flag_set($pdo, 'graph_events');
    return ['would' => count($missing), 'imported' => count($missing)];
}

function jeab_log_import_graph_events_file(bool $dry): array
{
    $pdo = $dry ? jeab_log_pdo_raw() : jeab_log_pdo();
    if (!$pdo instanceof PDO) {
        return ['would' => 0, 'imported' => 0];
    }
    if (!$dry) {
        jeab_log_ensure($pdo);
    }
    if (!jeab_log_table_exists($pdo, 'log_graph_events')) {
        return ['would' => jeab_log_import_file_count('graph_events'), 'imported' => 0];
    }
    return jeab_log_import_graph_events($pdo, $dry);
}

/** @return array{would:int,imported:int} */
function jeab_log_import_graph_week(PDO $pdo, bool $dry): array
{
    if (!function_exists('jeab_gp_week_events_cache_path')) {
        return ['would' => 0, 'imported' => 0];
    }
    $map = jeab_log_json_file(jeab_gp_week_events_cache_path());
    if ($map === null) {
        return ['would' => 0, 'imported' => 0];
    }
    $missing = 0;
    $stmt = $pdo->prepare('SELECT 1 FROM log_graph_week WHERE cache_key = ?');
    foreach ($map as $key => $row) {
        if (!is_string($key) || !is_array($row)) {
            continue;
        }
        $stmt->execute([$key]);
        if (!$stmt->fetchColumn()) {
            $missing++;
        }
    }
    if ($dry) {
        return ['would' => $missing, 'imported' => 0];
    }
    $imported = 0;
    foreach ($map as $key => $row) {
        if (!is_string($key) || !is_array($row) || !is_array($row['events'] ?? null)) {
            continue;
        }
        $stmt->execute([$key]);
        if ($stmt->fetchColumn()) {
            continue;
        }
        $events = $row['events'];
        $json = json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            continue;
        }
        $pdo->prepare('INSERT INTO log_graph_week (cache_key, cached_at, payload) VALUES (?, ?, ?)')->execute([
            $key,
            (int) ($row['at'] ?? 0),
            $json,
        ]);
        $imported++;
    }
    jeab_log_flag_set($pdo, 'graph_week');
    return ['would' => $missing, 'imported' => $imported];
}

/** @return array{would:int,imported:int} */
function jeab_log_import_who_picks(PDO $pdo, bool $dry): array
{
    if (!function_exists('jeab_who_picks_path')) {
        return ['would' => 0, 'imported' => 0];
    }
    $map = jeab_log_json_file(jeab_who_picks_path());
    if ($map === null && jeab_log_flag($pdo, 'who_picks')) {
        return ['would' => 0, 'imported' => 0];
    }
    $missing = [];
    $stmt = $pdo->prepare('SELECT 1 FROM log_who_picks WHERE pick_key = ?');
    foreach ($map ?? [] as $key => $row) {
        if (!is_string($key) || $key === '' || !is_array($row)) {
            continue;
        }
        $stmt->execute([$key]);
        if (!$stmt->fetchColumn()) {
            $missing[$key] = $row;
        }
    }
    if ($dry) {
        return ['would' => count($missing), 'imported' => 0];
    }
    if (jeab_log_is_mysql($pdo)) {
        $sql = 'INSERT INTO log_who_picks (pick_key, person, picked_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pick_key = pick_key';
    } else {
        $sql = 'INSERT OR IGNORE INTO log_who_picks (pick_key, person, picked_at) VALUES (?, ?, ?)';
    }
    $ins = $pdo->prepare($sql);
    foreach ($missing as $key => $row) {
        $ins->execute([$key, (string) ($row['person'] ?? ''), (int) ($row['at'] ?? 0)]);
    }
    jeab_log_flag_set($pdo, 'who_picks');
    return ['would' => count($missing), 'imported' => count($missing)];
}

/** @return array{would:int,imported:int,skipped_dupe:int,skipped_invalid:int,skipped_stale:int} */
function jeab_log_import_lines_kind(PDO $pdo, string $kind, string $path, bool $dry): array
{
    if ($path === '') {
        return jeab_log_import_blank();
    }
    $lines = jeab_log_text_lines($path);
    if ($lines === [] && jeab_log_flag($pdo, $kind)) {
        return jeab_log_import_blank();
    }
    $class = jeab_log_classify_lines($pdo, $kind, $lines);
    $result = [
        'would' => count($class['lines']),
        'imported' => 0,
        'skipped_dupe' => $class['skipped_dupe'],
        'skipped_invalid' => $class['skipped_invalid'],
        'skipped_stale' => $class['skipped_stale'],
    ];
    if ($dry) {
        return $result;
    }
    foreach ($class['lines'] as $line) {
        $hash = jeab_log_line_hash($line);
        if (jeab_log_line_exists($pdo, $kind, $hash)) {
            $result['skipped_dupe']++;
            $result['would']--;
            continue;
        }
        $decoded = json_decode($line, true);
        $at = is_array($decoded) ? trim((string) ($decoded['at'] ?? $decoded['time'] ?? '')) : '';
        jeab_log_line_insert($pdo, $kind, $hash, $at, $line);
        $result['imported']++;
    }
    jeab_log_flag_set($pdo, $kind);
    if ($kind === 'who_diag') {
        jeab_log_who_diag_prune($pdo);
    }
    return $result;
}

/** @return array{would:int,imported:int} */
function jeab_log_import_webshop(PDO $pdo, bool $dry): array
{
    if (!function_exists('jeab_webshop_index_path')) {
        return ['would' => 0, 'imported' => 0];
    }
    $map = jeab_log_json_file(jeab_webshop_index_path());
    if ($map === null && jeab_log_flag($pdo, 'webshop_orders')) {
        return ['would' => 0, 'imported' => 0];
    }
    $missing = [];
    $stmt = $pdo->prepare('SELECT 1 FROM log_webshop_orders WHERE order_key = ?');
    foreach ($map ?? [] as $key => $row) {
        if (!is_string($key) || $key === '' || !is_array($row)) {
            continue;
        }
        $stmt->execute([$key]);
        if (!$stmt->fetchColumn()) {
            $missing[$key] = $row;
        }
    }
    if ($dry) {
        return ['would' => count($missing), 'imported' => 0];
    }
    $at = jeab_log_now();
    $ins = $pdo->prepare('INSERT INTO log_webshop_orders (order_key, payload, updated_at) VALUES (?, ?, ?)');
    foreach ($missing as $key => $row) {
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            continue;
        }
        $ins->execute([$key, $json, $at]);
    }
    jeab_log_flag_set($pdo, 'webshop_orders');
    return ['would' => count($missing), 'imported' => count($missing)];
}

/** @return array{would:int,imported:int} */
function jeab_log_import_open_asks(PDO $pdo, bool $dry): array
{
    if (jeab_log_doc_get_pdo($pdo, 'fellogg_open_asks') !== null) {
        return ['would' => 0, 'imported' => 0];
    }
    if (!function_exists('jeab_fellogg_open_asks_path')) {
        return ['would' => 0, 'imported' => 0];
    }
    $data = jeab_log_json_file(jeab_fellogg_open_asks_path());
    if ($data === null) {
        return ['would' => 0, 'imported' => 0];
    }
    if (isset($data['asks']) && is_array($data['asks'])) {
        $data = $data['asks'];
    }
    if ($dry) {
        return ['would' => 1, 'imported' => 0];
    }
    jeab_log_doc_put_pdo($pdo, 'fellogg_open_asks', array_values($data));
    jeab_log_flag_set($pdo, 'fellogg_open_asks');
    return ['would' => 1, 'imported' => 1];
}

/**
 * Hook logs.import. dry=1 är standard och skriver inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_log_import(array $input): array
{
    $dry = jeab_log_wants_dry($input);
    $pdo = jeab_log_pdo_raw();
    $ready = false;
    if ($pdo instanceof PDO && !$dry) {
        jeab_log_ensure($pdo);
        $ready = true;
    } elseif ($pdo instanceof PDO) {
        $ready = jeab_log_table_exists($pdo, 'log_docs');
    }
    $kinds = [];
    foreach (jeab_log_kinds() as $kind) {
        try {
            $kinds[$kind] = array_merge(jeab_log_import_blank(), jeab_log_import_kind($kind, $pdo, $dry, $ready));
        } catch (Throwable) {
            $kinds[$kind] = array_merge(jeab_log_import_blank(), ['error' => 'lästes inte']);
        }
    }
    return [
        'ok' => true,
        'action' => 'logs.import',
        'dry' => $dry ? 1 : 0,
        'sql' => $pdo instanceof PDO,
        'kinds' => $kinds,
    ];
}

/**
 * Hook logs.dedupe. Tar bort extra log_lines med samma kind och line_hash.
 * dry=1 är standard och skriver inget. En andra körning tar bort noll.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_log_dedupe(array $input): array
{
    $dry = jeab_log_wants_dry($input);
    $out = [
        'ok' => true,
        'action' => 'logs.dedupe',
        'dry' => $dry ? 1 : 0,
        'sql' => false,
        'duplicate_rows' => 0,
        'removed' => 0,
        'kinds' => [],
    ];
    $pdo = jeab_log_pdo_raw();
    if (!$pdo instanceof PDO) {
        return $out;
    }
    $out['sql'] = true;
    if (!$dry) {
        jeab_log_ensure($pdo);
    }
    if (!jeab_log_table_exists($pdo, 'log_lines')) {
        return $out;
    }
    $names = $pdo->query('SELECT DISTINCT kind FROM log_lines')->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($names)) {
        return $out;
    }
    $kinds = [];
    $duplicateRows = 0;
    $removed = 0;
    foreach ($names as $kind) {
        $kind = (string) $kind;
        if ($kind === '') {
            continue;
        }
        $part = jeab_log_dedupe_kind($pdo, $kind, $dry);
        $duplicateRows += $part['duplicate_rows'];
        $removed += $part['removed'];
        if ($part['duplicate_rows'] > 0 || $part['removed'] > 0) {
            $kinds[$kind] = $part;
        }
    }
    $out['duplicate_rows'] = $duplicateRows;
    $out['removed'] = $removed;
    $out['kinds'] = $kinds;
    return $out;
}

/** @return array{duplicate_rows:int,removed:int} */
function jeab_log_dedupe_kind(PDO $pdo, string $kind, bool $dry): array
{
    $stmt = $pdo->prepare(
        'SELECT line_hash, COUNT(*) AS n, MIN(id) AS keep_id FROM log_lines WHERE kind = ? GROUP BY line_hash HAVING COUNT(*) > 1'
    );
    $stmt->execute([$kind]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($groups)) {
        return ['duplicate_rows' => 0, 'removed' => 0];
    }
    $duplicateRows = 0;
    foreach ($groups as $group) {
        $duplicateRows += max(0, (int) ($group['n'] ?? 0) - 1);
    }
    if ($dry || $duplicateRows === 0) {
        return ['duplicate_rows' => $duplicateRows, 'removed' => 0];
    }
    $del = $pdo->prepare('DELETE FROM log_lines WHERE kind = ? AND line_hash = ? AND id <> ?');
    $removed = 0;
    foreach ($groups as $group) {
        $hash = (string) ($group['line_hash'] ?? '');
        $keep = (int) ($group['keep_id'] ?? 0);
        if ($hash === '' || $keep < 1) {
            continue;
        }
        $del->execute([$kind, $hash, $keep]);
        $removed += $del->rowCount();
    }
    return ['duplicate_rows' => $duplicateRows, 'removed' => $removed];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_log_import_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    return jeab_log_import($input);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_log_dedupe_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    return jeab_log_dedupe($input);
}
