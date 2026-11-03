<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Help Scout-arkiv i SQL. Tabellerna skapas här, inte i src/db.php.
 * index.jsonl, konversationsfilerna, helpscout-import.json och
 * malmo-hs-sync.json läses som reserv tills importen är gjord och skrivs inte.
 */

function jeab_hs_import_store_pdo(bool $create = true): ?PDO
{
    $driver = function_exists('jeab_driver') ? jeab_driver() : 'json';
    if ($driver !== 'json' && function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if ($store instanceof JeabPdoStore) {
                return $store->pdo();
            }
        } catch (Throwable) {
        }
    }
    return jeab_hs_import_store_sqlite_fallback($create);
}

/** När hela appen ligger på JSON öppnas samma sqlite-fil som PDO-hjälparen skulle använt. */
function jeab_hs_import_store_sqlite_fallback(bool $create = true): ?PDO
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
    if (!$create && !is_file($path)) {
        return null;
    }
    try {
        if (function_exists('jeab_data_dir_ready')) {
            jeab_data_dir_ready($path);
        }
        // Appens PDO-hjälpare vägrar sqlite när JEAB_DISABLE_SQLITE är satt.
        // Arkivet ska ändå öppna samma fil, utan appens schema.
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $GLOBALS['_JEAB_SIDE_SQLITE'] = $pdo;
        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

function jeab_hs_import_store_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

/**
 * Sant när Help Scout-tabellerna redan är flaggade eller det här är ett klick.
 * Då körs varken CREATE TABLE eller CREATE INDEX.
 */
function jeab_hs_import_ddl_blocked(PDO $pdo): bool
{
    $driver = jeab_hs_import_store_driver($pdo);
    $flag = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, 'hs_import_ddl_v1') : null;
    if ($flag === 'ok') {
        return true;
    }

    return function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl();
}

function jeab_hs_import_store_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $driver = jeab_hs_import_store_driver($pdo);
    $ddl = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, 'hs_import_ddl_v1') : null;
    $tables = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, 'hs_import_tables_v1') : null;
    if ($ddl === 'ok') {
        $done[$key] = true;

        return;
    }
    $page = function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl();
    if ($page && $tables === 'ok') {
        $done[$key] = true;

        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jeab_hs_import_index (
                id VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jeab_hs_import_conv (
                id VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS jeab_hs_import_state (
                id TINYINT UNSIGNED NOT NULL,
                imported TINYINT(1) NOT NULL DEFAULT 0,
                checkpoint LONGTEXT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS malmo_hs_cursor (
                id TINYINT UNSIGNED NOT NULL,
                watermark VARCHAR(40) NOT NULL DEFAULT \'\',
                page INT UNSIGNED NOT NULL DEFAULT 1,
                cursor_offset INT UNSIGNED NOT NULL DEFAULT 0,
                query VARCHAR(80) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        jeab_hs_import_ensure_columns($pdo, $driver);
        if (function_exists('jeab_settings_put')) {
            jeab_settings_put($pdo, $driver, 'hs_import_tables_v1', 'ok');
        }
        if ($page) {
            $done[$key] = true;

            return;
        }
        if (jeab_hs_import_commit_ddl($pdo, $driver)) {
            $done[$key] = true;
        }

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS jeab_hs_import_index (
            id TEXT PRIMARY KEY,
            payload TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS jeab_hs_import_conv (
            id TEXT PRIMARY KEY,
            payload TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS jeab_hs_import_state (
            id INTEGER PRIMARY KEY,
            imported INTEGER NOT NULL DEFAULT 0,
            checkpoint TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS malmo_hs_cursor (
            id INTEGER PRIMARY KEY,
            watermark TEXT NOT NULL DEFAULT \'\',
            page INTEGER NOT NULL DEFAULT 1,
            cursor_offset INTEGER NOT NULL DEFAULT 0,
            query TEXT NOT NULL DEFAULT \'\'
        )'
    );
    jeab_hs_import_ensure_columns($pdo, $driver);
    if (function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, $driver, 'hs_import_tables_v1', 'ok');
    }
    if ($page) {
        $done[$key] = true;

        return;
    }
    if (jeab_hs_import_commit_ddl($pdo, $driver)) {
        $done[$key] = true;
    }
}

/**
 * Indexen är klara bara när båda finns. Flaggan sätts inte om uppslaget kastar.
 */
function jeab_hs_import_commit_ddl(PDO $pdo, string $driver): bool
{
    $fromOk = jeab_hs_import_ensure_from_index($pdo);
    $listOk = jeab_hs_import_ensure_list_page($pdo);
    if (!$fromOk || !$listOk) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('db', 'hs import index misslyckades');
        }

        return false;
    }
    if (function_exists('jeab_settings_put') && !jeab_settings_put($pdo, $driver, 'hs_import_ddl_v1', 'ok')) {
        return false;
    }

    return true;
}

/** Kolumner som sparningen skriver. Index och påfyllnad väntar på tick. */
function jeab_hs_import_ensure_columns(PDO $pdo, string $driver): void
{
    if (!function_exists('jeab_ensure_table_columns')) {
        return;
    }
    jeab_ensure_table_columns($pdo, 'jeab_hs_import_index', [
        'from_addr' => $driver === 'mysql' ? "VARCHAR(191) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        'list_company' => $driver === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        'list_status' => $driver === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        'list_at' => $driver === 'mysql' ? "VARCHAR(40) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
    ], $driver);
}

function jeab_hs_import_store_is_imported(?PDO $pdo = null): bool
{
    $pdo ??= jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $stmt = $pdo->query('SELECT imported FROM jeab_hs_import_state WHERE id = 1');
        $value = $stmt ? $stmt->fetchColumn() : false;
        return $value !== false && (int) $value === 1;
    } catch (Throwable) {
        return false;
    }
}

function jeab_hs_import_store_mark_imported(PDO $pdo): void
{
    $checkpoint = jeab_hs_import_store_checkpoint_raw($pdo);
    if (jeab_hs_import_store_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO jeab_hs_import_state (id, imported, checkpoint) VALUES (1, 1, ?)
             ON DUPLICATE KEY UPDATE imported = 1'
        )->execute([$checkpoint]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO jeab_hs_import_state (id, imported, checkpoint) VALUES (1, 1, ?)
         ON CONFLICT(id) DO UPDATE SET imported = 1'
    )->execute([$checkpoint]);
}

function jeab_hs_import_store_payload(array $row): ?string
{
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json) && $json !== '' ? $json : null;
}

function jeab_hs_import_store_upsert(PDO $pdo, string $table, string $id, string $payload): void
{
    if (jeab_hs_import_store_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO ' . $table . ' (id, payload) VALUES (?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload)'
        )->execute([$id, $payload]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO ' . $table . ' (id, payload) VALUES (?, ?) ON CONFLICT(id) DO UPDATE SET payload = excluded.payload'
    )->execute([$id, $payload]);
}

function jeab_hs_import_store_insert_ignore(PDO $pdo, string $table, string $id, string $payload): void
{
    if (jeab_hs_import_store_driver($pdo) === 'mysql') {
        $pdo->prepare('INSERT IGNORE INTO ' . $table . ' (id, payload) VALUES (?, ?)')->execute([$id, $payload]);
        return;
    }
    $pdo->prepare('INSERT OR IGNORE INTO ' . $table . ' (id, payload) VALUES (?, ?)')->execute([$id, $payload]);
}

/** @return array<string, true> */
function jeab_hs_import_store_ids(PDO $pdo, string $table): array
{
    $have = [];
    foreach ($pdo->query('SELECT id FROM ' . $table) ?: [] as $dbRow) {
        if (!is_array($dbRow)) {
            continue;
        }
        $id = (string) ($dbRow['id'] ?? '');
        if ($id !== '') {
            $have[$id] = true;
        }
    }
    return $have;
}

function jeab_hs_import_store_index_put(string $id, array $row): bool
{
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
        return false;
    }
    $pdo = jeab_hs_import_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $payload = jeab_hs_import_store_payload($row);
    if ($payload === null) {
        return false;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        jeab_hs_import_ensure_from_index($pdo);
        jeab_hs_import_ensure_list_page($pdo);
        $from = mb_substr(mb_strtolower(trim((string) ($row['from'] ?? ''))), 0, 191);
        [$listCompany, $listStatus, $listAt] = jeab_hs_import_list_columns($row);
        if (jeab_hs_import_store_driver($pdo) === 'mysql') {
            $pdo->prepare(
                'INSERT INTO jeab_hs_import_index (id, payload, from_addr, list_company, list_status, list_at) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), from_addr = VALUES(from_addr), list_company = VALUES(list_company), list_status = VALUES(list_status), list_at = VALUES(list_at)'
            )->execute([$id, $payload, $from, $listCompany, $listStatus, $listAt]);
        } else {
            $pdo->prepare(
                'INSERT INTO jeab_hs_import_index (id, payload, from_addr, list_company, list_status, list_at) VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT(id) DO UPDATE SET payload = excluded.payload, from_addr = excluded.from_addr, list_company = excluded.list_company, list_status = excluded.list_status, list_at = excluded.list_at'
            )->execute([$id, $payload, $from, $listCompany, $listStatus, $listAt]);
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_hs_import_ensure_from_index(PDO $pdo): bool
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return true;
    }
    if (jeab_hs_import_ddl_blocked($pdo)) {
        return jeab_hs_import_ddl_flag_ok($pdo);
    }
    $driver = jeab_hs_import_store_driver($pdo);
    if (function_exists('jeab_ensure_table_columns')) {
        jeab_ensure_table_columns($pdo, 'jeab_hs_import_index', [
            'from_addr' => $driver === 'mysql' ? "VARCHAR(191) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        ], $driver);
    }
    if (!jeab_hs_import_create_index($pdo, $driver, 'jeab_hs_import_index_from', 'jeab_hs_import_index', 'from_addr')) {
        return false;
    }
    $done[$key] = true;

    return true;
}

function jeab_hs_import_ddl_flag_ok(PDO $pdo): bool
{
    $driver = jeab_hs_import_store_driver($pdo);
    $flag = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, 'hs_import_ddl_v1') : null;

    return $flag === 'ok';
}

/**
 * Bolag, status och datum som egna kolumner så Historik kan LIMIT/OFFSET.
 * Befintliga payload rörs inte. Kolumnerna fylls en gång från JSON när de saknas.
 */
function jeab_hs_import_create_index(PDO $pdo, string $driver, string $name, string $table, string $columns): bool
{
    if (!function_exists('jeab_list_index_create') || !function_exists('jeab_create_index_sql') || !function_exists('jeab_existing_index_names')) {
        return false;
    }
    try {
        if (in_array($name, jeab_existing_index_names($pdo, $table, $driver), true)) {
            return true;
        }
    } catch (Throwable) {
        return false;
    }
    try {
        jeab_list_index_create($pdo, jeab_create_index_sql($driver, $name, $table . ' (' . $columns . ')'));
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, 'Duplicate') === false && stripos($message, 'already exists') === false) {
            return false;
        }
    }
    try {
        return in_array($name, jeab_existing_index_names($pdo, $table, $driver), true);
    } catch (Throwable) {
        return false;
    }
}

function jeab_hs_import_ensure_list_page(PDO $pdo): bool
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return true;
    }
    if (jeab_hs_import_ddl_blocked($pdo)) {
        return jeab_hs_import_ddl_flag_ok($pdo);
    }
    $driver = jeab_hs_import_store_driver($pdo);
    if (function_exists('jeab_ensure_table_columns')) {
        jeab_ensure_table_columns($pdo, 'jeab_hs_import_index', [
            'list_company' => $driver === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
            'list_status' => $driver === 'mysql' ? "VARCHAR(32) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
            'list_at' => $driver === 'mysql' ? "VARCHAR(40) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        ], $driver);
    }
    if (!jeab_hs_import_create_index($pdo, $driver, 'jeab_hs_import_list_page', 'jeab_hs_import_index', 'list_company, list_status, list_at')) {
        return false;
    }
    $flag = 'hs_import_list_page_v1';
    $ready = function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, $flag) === 'ok';
    if (!$ready) {
        if ($driver === 'mysql') {
            $pdo->exec(
                "UPDATE jeab_hs_import_index SET
                    list_company = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.company')), ''),
                    list_status = CASE
                        WHEN JSON_EXTRACT(payload, '$.deleted') = true THEN 'deleted'
                        WHEN JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) IN ('closed', 'spam', 'stängd', 'stangd') THEN 'closed'
                        WHEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')), '') = '' THEN 'open'
                        ELSE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status'))
                    END,
                    list_at = COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.updated_at')), '')
                 WHERE list_company = ''"
            );
        } else {
            $pdo->exec(
                "UPDATE jeab_hs_import_index SET
                    list_company = COALESCE(json_extract(payload, '$.company'), ''),
                    list_status = CASE
                        WHEN json_extract(payload, '$.deleted') IN (1, 'true', '1') THEN 'deleted'
                        WHEN json_extract(payload, '$.status') IN ('closed', 'spam', 'stängd', 'stangd') THEN 'closed'
                        WHEN json_extract(payload, '$.status') IS NULL OR json_extract(payload, '$.status') = '' THEN 'open'
                        ELSE json_extract(payload, '$.status')
                    END,
                    list_at = COALESCE(json_extract(payload, '$.updated_at'), '')
                 WHERE list_company IS NULL OR list_company = ''"
            );
        }
        if (function_exists('jeab_settings_put') && !jeab_settings_put($pdo, $driver, $flag, 'ok')) {
            return false;
        }
    }
    $done[$key] = true;

    return true;
}

/** @param array<string, mixed> $row @return array{0: string, 1: string, 2: string} */
function jeab_hs_import_list_columns(array $row): array
{
    $company = mb_substr(trim((string) ($row['company'] ?? '')), 0, 32);
    $status = mb_strtolower(trim((string) ($row['status'] ?? '')));
    if (!empty($row['deleted'])) {
        $listStatus = 'deleted';
    } elseif (in_array($status, ['closed', 'spam', 'stängd', 'stangd'], true)) {
        $listStatus = 'closed';
    } else {
        $listStatus = mb_substr($status === '' ? 'open' : $status, 0, 32);
    }
    $at = mb_substr((string) ($row['updated_at'] ?? ''), 0, 40);

    return [$company, $listStatus, $at];
}

/**
 * @param list<string> $companies
 * @return array{0: string, 1: list<string>}
 */
function jeab_hs_import_historik_where(PDO $pdo, array $companies, string $assignee, string $me, string $q, bool $ensure = true): array
{
    $companies = array_values(array_filter(array_map(static fn($c): string => mb_substr(trim((string) $c), 0, 32), $companies)));
    if ($companies === []) {
        return ['1 = 0', []];
    }
    if ($ensure) {
        jeab_hs_import_store_ensure($pdo);
        jeab_hs_import_ensure_list_page($pdo);
    }
    $marks = implode(',', array_fill(0, count($companies), '?'));
    $where = 'list_company IN (' . $marks . ') AND list_status IN (\'closed\', \'deleted\')';
    $args = $companies;
    if ($assignee === 'me' || $assignee === 'others') {
        $mysql = jeab_hs_import_store_driver($pdo) === 'mysql';
        $expr = $mysql
            ? "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.assignee')), '')"
            : "COALESCE(json_extract(payload, '$.assignee'), '')";
        if ($assignee === 'me') {
            if ($me === '') {
                return ['1 = 0', []];
            }
            // Egna Historik: bara personens egna, inte hela otilldelade arkivet.
            $where .= ' AND ' . $expr . " <> '' AND LOWER(" . $expr . ') = LOWER(?)';
            $args[] = $me;
        } else {
            $where .= ' AND ' . $expr . " <> '' AND LOWER(" . $expr . ') <> LOWER(?)';
            $args[] = $me;
        }
    }
    $q = mb_strtolower(trim($q));
    if ($q !== '') {
        $where .= ' AND instr(lower(payload), ?) > 0';
        $args[] = $q;
    }
    $where .= ' AND NOT EXISTS (SELECT 1 FROM inkorg_list l WHERE l.id = jeab_hs_import_index.id AND l.list_status IN (\'closed\', \'deleted\'))';

    return [$where, $args];
}

/**
 * @param list<string> $companies
 * @return list<array{id: string, at: string}>
 */
function jeab_hs_import_historik_keys(PDO $pdo, array $companies, int $limit, string $assignee = '', string $me = '', string $q = '', bool $ensure = true): array
{
    $limit = max(0, $limit);
    if ($limit === 0) {
        return [];
    }
    try {
        [$where, $args] = jeab_hs_import_historik_where($pdo, $companies, $assignee, $me, $q, $ensure);
        $stmt = $pdo->prepare(
            'SELECT id, list_at FROM jeab_hs_import_index WHERE ' . $where . ' ORDER BY list_at DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute($args);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = ['id' => $id, 'at' => (string) ($row['list_at'] ?? '')];
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/** @param list<string> $companies */
function jeab_hs_import_historik_count(PDO $pdo, array $companies, string $assignee = '', string $me = '', string $q = ''): int
{
    return jeab_hs_import_historik_count_query($pdo, $companies, $assignee, $me, $q, true);
}

/** Samma tal som jeab_hs_import_historik_count, utan CREATE eller index-DDL. Webb-GET. */
function jeab_hs_import_historik_count_noddl(PDO $pdo, array $companies, string $assignee = '', string $me = '', string $q = ''): int
{
    return jeab_hs_import_historik_count_query($pdo, $companies, $assignee, $me, $q, false);
}

/** @param list<string> $companies */
function jeab_hs_import_historik_count_query(PDO $pdo, array $companies, string $assignee, string $me, string $q, bool $ensure): int
{
    try {
        [$where, $args] = jeab_hs_import_historik_where($pdo, $companies, $assignee, $me, $q, $ensure);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM jeab_hs_import_index WHERE ' . $where);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * $ensure false är webbläsningen: ingen CREATE, samma som _noddl-räknarna.
 *
 * @param list<string> $ids
 * @return list<array<string, mixed>>
 */
function jeab_hs_import_rows_by_ids(PDO $pdo, array $ids, bool $ensure = true): array
{
    $want = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $want[] = $id;
        }
    }
    if ($want === []) {
        return [];
    }
    try {
        if ($ensure) {
            jeab_hs_import_store_ensure($pdo);
        }
        $byId = [];
        foreach (array_chunk($want, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare('SELECT id, payload FROM jeab_hs_import_index WHERE id IN (' . $marks . ')');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = json_decode((string) ($row['payload'] ?? ''), true);
                $id = (string) ($row['id'] ?? '');
                if ($id !== '' && is_array($decoded)) {
                    $byId[$id] = $decoded;
                }
            }
        }
        $out = [];
        foreach ($want as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Indexrader för en kundadress. Läser inte hela importarkivet.
 *
 * @return array<string, array<string, mixed>>
 */
function jeab_hs_import_index_for_email(string $email): array
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return [];
    }
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        jeab_hs_import_ensure_from_index($pdo);
        $stmt = $pdo->prepare('SELECT id, payload FROM jeab_hs_import_index WHERE from_addr = ? LIMIT 30');
        $stmt->execute([mb_substr($email, 0, 191)]);
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
            $id = (string) ($dbRow['id'] ?? '');
            if ($id !== '' && is_array($row)) {
                $out[$id] = $row;
            }
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Indexrader för flera adresser. En fråga, inte hela arkivet.
 *
 * @param list<string> $emails
 * @return array<string, array<string, array<string, mixed>>>
 */
function jeab_hs_import_index_for_emails(array $emails): array
{
    $clean = [];
    foreach ($emails as $email) {
        $email = mb_substr(mb_strtolower(trim((string) $email)), 0, 191);
        if ($email !== '' && str_contains($email, '@')) {
            $clean[$email] = $email;
        }
    }
    $clean = array_values($clean);
    if ($clean === []) {
        return [];
    }
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return [];
    }
    $GLOBALS['_JEAB_QUOTE_HS_SQL'] = (int) ($GLOBALS['_JEAB_QUOTE_HS_SQL'] ?? 0) + 1;
    try {
        jeab_hs_import_store_ensure($pdo);
        jeab_hs_import_ensure_from_index($pdo);
        $marks = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $pdo->prepare('SELECT id, payload, from_addr FROM jeab_hs_import_index WHERE from_addr IN (' . $marks . ')');
        $stmt->execute($clean);
        $out = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
            $id = (string) ($dbRow['id'] ?? '');
            $from = mb_strtolower(trim((string) ($dbRow['from_addr'] ?? '')));
            if ($id === '' || $from === '' || !is_array($row)) {
                continue;
            }
            $out[$from][$id] = $row;
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Stängda och raderade importrader för ett bolag. Öppna arkivrader läses inte.
 *
 * @return list<array<string, mixed>>
 */
function jeab_hs_import_store_historik_rows(string $company): array
{
    $company = mb_substr(trim($company), 0, 32);
    if ($company === '') {
        return [];
    }
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $mysql = jeab_hs_import_store_driver($pdo) === 'mysql';
        if ($mysql) {
            $sql = 'SELECT payload FROM jeab_hs_import_index WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.company\')) = ? AND ('
                . 'JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.status\')) IN (\'closed\', \'spam\', \'stängd\', \'stangd\')'
                . ' OR JSON_EXTRACT(payload, \'$.deleted\') = true)';
        } else {
            $sql = 'SELECT payload FROM jeab_hs_import_index WHERE json_extract(payload, \'$.company\') = ? AND ('
                . 'json_extract(payload, \'$.status\') IN (\'closed\', \'spam\', \'stängd\', \'stangd\')'
                . ' OR json_extract(payload, \'$.deleted\') IN (1, \'true\', \'1\'))';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company]);
        $rows = [];
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Bara rader för en kontakt. Hela indexet läses inte.
 *
 * @return list<array<string, mixed>>
 */
function jeab_hs_import_rows_for_contact(string $contactId, int $limit = 40): array
{
    $contactId = mb_substr(trim($contactId), 0, 64);
    if ($contactId === '') {
        return [];
    }
    $limit = max(1, min(80, $limit));
    $page = function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl();
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return $page ? [] : array_slice(jeab_hs_import_rows_for_contact_json($contactId), 0, $limit);
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $driver = jeab_hs_import_store_driver($pdo);
        $flag = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, 'hs_import_contact_v1') : null;
        $rows = [];
        $read = false;
        $stmt = null;
        if ($flag === 'ok' || $page) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT payload FROM jeab_hs_import_index WHERE contact_id = ? ORDER BY list_at DESC, id DESC LIMIT ' . $limit
                );
                $stmt->execute([$contactId]);
                $read = true;
            } catch (Throwable) {
                try {
                    $stmt = $pdo->prepare('SELECT payload FROM jeab_hs_import_index WHERE contact_id = ? LIMIT ' . $limit);
                    $stmt->execute([$contactId]);
                    $read = true;
                } catch (Throwable) {
                    if ($page) {
                        return [];
                    }
                }
            }
        }
        if (!$read) {
            $expr = $driver === 'mysql'
                ? "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.contact_id'))"
                : "json_extract(payload, '$.contact_id')";
            $stmt = $pdo->prepare('SELECT payload FROM jeab_hs_import_index WHERE ' . $expr . ' = ? LIMIT ' . $limit);
            $stmt->execute([$contactId]);
        }
        if (!$stmt instanceof PDOStatement) {
            return $rows;
        }
        foreach ($stmt->fetchAll() as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        // Hela index.jsonl hör inte till ett kort. Tick och CLI får slå ihop.
        if (!$page && !jeab_hs_import_store_is_imported($pdo) && count($rows) < $limit) {
            $seen = [];
            foreach ($rows as $row) {
                $seen[(string) ($row['id'] ?? '')] = true;
            }
            foreach (jeab_hs_import_rows_for_contact_json($contactId) as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $rows[] = $row;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }

        return $rows;
    } catch (Throwable) {
        return $page ? [] : array_slice(jeab_hs_import_rows_for_contact_json($contactId), 0, $limit);
    }
}

/** @return list<array<string, mixed>> */
function jeab_hs_import_rows_for_contact_json(string $contactId): array
{
    $out = [];
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (is_array($row) && (string) ($row['contact_id'] ?? '') === $contactId) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * contact_id som egen kolumn. Bara tick och CLI. Sidan läser flaggan.
 */
function jeab_hs_import_ensure_contact_column(PDO $pdo): void
{
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    $driver = jeab_hs_import_store_driver($pdo);
    $flag = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, 'hs_import_contact_v1') : null;
    if ($flag === 'ok') {
        return;
    }
    if (function_exists('jeab_ensure_table_columns')) {
        jeab_ensure_table_columns($pdo, 'jeab_hs_import_index', [
            'contact_id' => $driver === 'mysql' ? "VARCHAR(64) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        ], $driver);
    }
    if (function_exists('jeab_list_index_create') && function_exists('jeab_create_index_sql') && function_exists('jeab_existing_index_names')) {
        try {
            $have = jeab_existing_index_names($pdo, 'jeab_hs_import_index', $driver);
            if (!in_array('jeab_hs_import_contact', $have, true)) {
                jeab_list_index_create($pdo, jeab_create_index_sql($driver, 'jeab_hs_import_contact', 'jeab_hs_import_index (contact_id)'));
            }
        } catch (Throwable) {
        }
    }
    try {
        if ($driver === 'mysql') {
            $pdo->exec(
                "UPDATE jeab_hs_import_index SET contact_id = LEFT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.contact_id')), ''), 64)
                 WHERE contact_id = '' AND JSON_EXTRACT(payload, '$.contact_id') IS NOT NULL"
            );
        } else {
            $pdo->exec(
                "UPDATE jeab_hs_import_index SET contact_id = substr(COALESCE(json_extract(payload, '$.contact_id'), ''), 1, 64)
                 WHERE contact_id = '' AND json_extract(payload, '$.contact_id') IS NOT NULL"
            );
        }
    } catch (Throwable) {
        return;
    }
    if (function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, $driver, 'hs_import_contact_v1', 'ok');
    }
}

/** @return list<array<string, mixed>> */
function jeab_hs_import_store_index_rows(): array
{
    if (!empty($GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS']) || !empty($GLOBALS['_JEAB_INKORG_SKIP_IMPORT'])) {
        return [];
    }
    if (function_exists('jeab_inkorg_settings_request') && jeab_inkorg_settings_request()) {
        return [];
    }
    $GLOBALS['_JEAB_HS_INDEX_FULL'] = (int) ($GLOBALS['_JEAB_HS_INDEX_FULL'] ?? 0) + 1;
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $rows = [];
        foreach ($pdo->query('SELECT payload FROM jeab_hs_import_index') ?: [] as $dbRow) {
            if (!is_array($dbRow)) {
                continue;
            }
            $row = json_decode((string) ($dbRow['payload'] ?? ''), true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    } catch (Throwable) {
        return [];
    }
}

function jeab_hs_import_store_conv_put(string $id, array $conv): bool
{
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
        return false;
    }
    $pdo = jeab_hs_import_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $payload = jeab_hs_import_store_payload($conv);
    if ($payload === null) {
        return false;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        jeab_hs_import_store_upsert($pdo, 'jeab_hs_import_conv', $id, $payload);
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string, mixed>|null */
function jeab_hs_import_store_conv_get(string $id): ?array
{
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
        return null;
    }
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $stmt = $pdo->prepare('SELECT payload FROM jeab_hs_import_conv WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $row = json_decode($raw, true);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

function jeab_hs_import_store_checkpoint_raw(PDO $pdo): ?string
{
    $stmt = $pdo->query('SELECT checkpoint FROM jeab_hs_import_state WHERE id = 1');
    $raw = $stmt ? $stmt->fetchColumn() : false;
    return is_string($raw) && $raw !== '' ? $raw : null;
}

/** @return array<string, mixed>|null */
function jeab_hs_import_store_checkpoint_get(): ?array
{
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $raw = jeab_hs_import_store_checkpoint_raw($pdo);
        if ($raw === null) {
            return null;
        }
        $row = json_decode($raw, true);
        return is_array($row) ? $row : null;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, mixed> $checkpoint */
function jeab_hs_import_store_checkpoint_put(array $checkpoint): bool
{
    if (empty($GLOBALS['_JEAB_REMAIN_TO_SQL']) && function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('helpscout_import') === 'json' && function_exists('jeab_remain_json_write')) {
        return jeab_remain_json_write('helpscout_import', $checkpoint);
    }
    $pdo = jeab_hs_import_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $payload = jeab_hs_import_store_payload($checkpoint);
    if ($payload === null) {
        return false;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $imported = jeab_hs_import_store_is_imported($pdo) ? 1 : 0;
        if (jeab_hs_import_store_driver($pdo) === 'mysql') {
            $pdo->prepare(
                'INSERT INTO jeab_hs_import_state (id, imported, checkpoint) VALUES (1, ?, ?)
                 ON DUPLICATE KEY UPDATE checkpoint = VALUES(checkpoint)'
            )->execute([$imported, $payload]);
            return true;
        }
        $pdo->prepare(
            'INSERT INTO jeab_hs_import_state (id, imported, checkpoint) VALUES (1, ?, ?)
             ON CONFLICT(id) DO UPDATE SET checkpoint = excluded.checkpoint'
        )->execute([$imported, $payload]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array{bytes: int, files: int} */
function jeab_hs_import_store_usage(): array
{
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return function_exists('jeab_dir_usage') ? jeab_dir_usage(function_exists('jeab_hs_import_root') ? jeab_hs_import_root() : '') : ['bytes' => 0, 'files' => 0];
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $bytes = (int) $pdo->query('SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM jeab_hs_import_conv')->fetchColumn()
            + (int) $pdo->query('SELECT COALESCE(SUM(LENGTH(payload)), 0) FROM jeab_hs_import_index')->fetchColumn();
        $files = (int) $pdo->query('SELECT COUNT(*) FROM jeab_hs_import_conv')->fetchColumn();
        return ['bytes' => $bytes, 'files' => $files];
    } catch (Throwable) {
        return ['bytes' => 0, 'files' => 0];
    }
}

/** @return array{watermark: string, page: int, offset: int, query: string}|null */
function jeab_hs_import_store_malmo_get(): ?array
{
    $pdo = jeab_hs_import_store_pdo(false);
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        jeab_hs_import_store_ensure($pdo);
        $row = $pdo->query('SELECT watermark, page, cursor_offset, query FROM malmo_hs_cursor WHERE id = 1')->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'watermark' => (string) ($row['watermark'] ?? ''),
            'page' => max(1, (int) ($row['page'] ?? 1)),
            'offset' => max(0, (int) ($row['cursor_offset'] ?? 0)),
            'query' => (string) ($row['query'] ?? ''),
        ];
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, mixed> $state */
function jeab_hs_import_store_malmo_put(array $state): bool
{
    if (empty($GLOBALS['_JEAB_REMAIN_TO_SQL']) && function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('malmo_hs_cursor') === 'json' && function_exists('jeab_remain_json_write')) {
        $row = function_exists('jeab_malmo_hs_state_row') ? jeab_malmo_hs_state_row($state) : $state;

        return jeab_remain_json_write('malmo_hs_cursor', $row);
    }
    $pdo = jeab_hs_import_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $row = function_exists('jeab_malmo_hs_state_row') ? jeab_malmo_hs_state_row($state) : [
        'watermark' => (string) ($state['watermark'] ?? ''),
        'page' => max(1, (int) ($state['page'] ?? 1)),
        'offset' => max(0, (int) ($state['offset'] ?? 0)),
        'query' => (string) ($state['query'] ?? ''),
    ];
    try {
        jeab_hs_import_store_ensure($pdo);
        $sql = jeab_hs_import_store_driver($pdo) === 'mysql'
            ? 'INSERT INTO malmo_hs_cursor (id, watermark, page, cursor_offset, query) VALUES (1, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE watermark = VALUES(watermark), page = VALUES(page), cursor_offset = VALUES(cursor_offset), query = VALUES(query)'
            : 'INSERT INTO malmo_hs_cursor (id, watermark, page, cursor_offset, query) VALUES (1, ?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET watermark = excluded.watermark, page = excluded.page, cursor_offset = excluded.cursor_offset, query = excluded.query';
        $pdo->prepare($sql)->execute([$row['watermark'], $row['page'], $row['offset'], $row['query']]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_hs_import_store_malmo_exists(): bool
{
    return jeab_hs_import_store_malmo_get() !== null;
}

function jeab_hs_archive_import_is_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === 0 || $dry === '0' || $dry === false);
}

/** @return array<string, array<string, mixed>> */
function jeab_hs_archive_index_json_map(): array
{
    $path = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/index.jsonl' : '';
    $out = [];
    if ($path === '' || !is_file($path)) {
        return $out;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return $out;
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
            continue;
        }
        $out[$id] = $row;
    }
    fclose($fh);
    return $out;
}

/** @return array<string, string> id => payload */
function jeab_hs_archive_conv_json_map(): array
{
    $root = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/c' : '';
    $out = [];
    if ($root === '' || !is_dir($root)) {
        return $out;
    }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    } catch (Throwable) {
        return $out;
    }
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
            continue;
        }
        $id = basename($file->getFilename(), '.json');
        if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
            continue;
        }
        $raw = file_get_contents($file->getPathname());
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $out[$id] = $raw;
    }
    return $out;
}

/** @return array<string, mixed>|null */
function jeab_hs_archive_checkpoint_json(): ?array
{
    if (!function_exists('jeab_inkorg_dir')) {
        return null;
    }
    $path = jeab_inkorg_dir() . '/helpscout-import.json';
    if (!is_file($path)) {
        return null;
    }
    $row = json_decode((string) file_get_contents($path), true);
    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function jeab_hs_archive_malmo_json(): ?array
{
    if (!function_exists('jeab_inkorg_dir')) {
        return null;
    }
    $path = jeab_inkorg_dir() . '/malmo-hs-sync.json';
    if (!is_file($path)) {
        return null;
    }
    $row = json_decode((string) file_get_contents($path), true);
    if (!is_array($row)) {
        return null;
    }
    $norm = function_exists('jeab_malmo_hs_state_row') ? jeab_malmo_hs_state_row($row) : $row;
    $empty = ($norm['watermark'] ?? '') === '' && (int) ($norm['page'] ?? 1) === 1 && (int) ($norm['offset'] ?? 0) === 0 && ($norm['query'] ?? '') === '';
    return $empty ? null : $norm;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hs_archive_import_dispatch(array $input): array
{
    $dry = jeab_hs_archive_import_is_dry($input);
    $pdo = jeab_hs_import_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'dry' => $dry ? 1 : 0, 'status' => 400];
    }
    try {
        jeab_hs_import_store_ensure($pdo);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'dry' => $dry ? 1 : 0, 'status' => 400];
    }
    $index = jeab_hs_archive_index_json_map();
    $convs = jeab_hs_archive_conv_json_map();
    $checkpoint = jeab_hs_archive_checkpoint_json();
    $malmo = jeab_hs_archive_malmo_json();
    $jsonRows = count($index) + count($convs) + ($checkpoint !== null ? 1 : 0) + ($malmo !== null ? 1 : 0);
    if (jeab_hs_import_store_is_imported($pdo)) {
        return [
            'ok' => true,
            'dry' => $dry ? 1 : 0,
            'inserted' => 0,
            'skipped' => $jsonRows,
            'json_rows' => $jsonRows,
        ];
    }
    $haveIndex = jeab_hs_import_store_ids($pdo, 'jeab_hs_import_index');
    $haveConv = jeab_hs_import_store_ids($pdo, 'jeab_hs_import_conv');
    $haveCheckpoint = jeab_hs_import_store_checkpoint_get() !== null;
    $haveMalmo = jeab_hs_import_store_malmo_exists();
    $plan = ['index' => 0, 'conv' => 0, 'checkpoint' => 0, 'malmo' => 0, 'skipped' => 0];
    foreach ($index as $id => $row) {
        unset($row);
        if (isset($haveIndex[$id])) {
            $plan['skipped']++;
            continue;
        }
        $plan['index']++;
    }
    foreach ($convs as $id => $raw) {
        unset($raw);
        if (isset($haveConv[$id])) {
            $plan['skipped']++;
            continue;
        }
        $decoded = json_decode($convs[$id], true);
        if (!is_array($decoded)) {
            $plan['skipped']++;
            continue;
        }
        $plan['conv']++;
    }
    if ($checkpoint !== null) {
        if ($haveCheckpoint) {
            $plan['skipped']++;
        } else {
            $plan['checkpoint']++;
        }
    }
    if ($malmo !== null) {
        if ($haveMalmo) {
            $plan['skipped']++;
        } else {
            $plan['malmo']++;
        }
    }
    $inserted = $plan['index'] + $plan['conv'] + $plan['checkpoint'] + $plan['malmo'];
    if ($dry) {
        return [
            'ok' => true,
            'dry' => 1,
            'inserted' => $inserted,
            'skipped' => $plan['skipped'],
            'json_rows' => $jsonRows,
        ];
    }
    $pdo->beginTransaction();
    try {
        $wrote = ['index' => 0, 'conv' => 0, 'checkpoint' => 0, 'malmo' => 0, 'skipped' => 0];
        foreach ($index as $id => $row) {
            if (isset($haveIndex[$id])) {
                $wrote['skipped']++;
                continue;
            }
            $payload = jeab_hs_import_store_payload($row);
            if ($payload === null) {
                $wrote['skipped']++;
                continue;
            }
            jeab_hs_import_store_insert_ignore($pdo, 'jeab_hs_import_index', $id, $payload);
            $haveIndex[$id] = true;
            $wrote['index']++;
        }
        foreach ($convs as $id => $raw) {
            if (isset($haveConv[$id])) {
                $wrote['skipped']++;
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $wrote['skipped']++;
                continue;
            }
            jeab_hs_import_store_insert_ignore($pdo, 'jeab_hs_import_conv', $id, $raw);
            $haveConv[$id] = true;
            $wrote['conv']++;
        }
        if ($checkpoint !== null) {
            if (jeab_hs_import_store_checkpoint_get() !== null) {
                $wrote['skipped']++;
            } elseif (!jeab_hs_import_store_checkpoint_put($checkpoint)) {
                throw new RuntimeException('checkpoint');
            } else {
                $wrote['checkpoint']++;
            }
        }
        if ($malmo !== null) {
            if (jeab_hs_import_store_malmo_exists()) {
                $wrote['skipped']++;
            } elseif (!jeab_hs_import_store_malmo_put($malmo)) {
                throw new RuntimeException('malmo');
            } else {
                $wrote['malmo']++;
            }
        }
        jeab_hs_import_store_mark_imported($pdo);
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Importen stoppades.', 'dry' => 0, 'status' => 400];
    }
    unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
    return [
        'ok' => true,
        'dry' => 0,
        'inserted' => $wrote['index'] + $wrote['conv'] + $wrote['checkpoint'] + $wrote['malmo'],
        'skipped' => $wrote['skipped'],
        'json_rows' => $jsonRows,
    ];
}
