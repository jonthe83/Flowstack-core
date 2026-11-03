<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Ordrar/köp i SQL.
 * Live (MySQL): läser och skriver SQL som tidigare, utan flagga (jeab_sql_store_pdo).
 * SQLite/lab: flaggan purchases_storage=sql efter cutover.
 */

const JEAB_PURCHASES_STORAGE_KEY = 'purchases_storage';

function jeab_purchases_store_pdo(): ?PDO
{
    if (!function_exists('jeab_store') || !function_exists('jeab_driver')) {
        return null;
    }
    $driver = jeab_driver();
    if ($driver !== 'sqlite' && $driver !== 'mysql') {
        return null;
    }
    try {
        $store = jeab_store();
        return $store instanceof JeabPdoStore ? $store->pdo() : null;
    } catch (Throwable) {
        return null;
    }
}

/** Live flowstack.example.test: driver mysql och purchases-tabellen via jeab_sql_store_pdo. */
function jeab_purchases_mysql_live(): bool
{
    if (!empty($GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'])) {
        return true;
    }
    if (!function_exists('jeab_driver') || jeab_driver() !== 'mysql') {
        return false;
    }
    if (!function_exists('jeab_sql_store_pdo')) {
        return false;
    }
    return jeab_sql_store_pdo() instanceof PDO;
}

function jeab_purchases_storage_mode(): string
{
    $legacy = 'json';
    if (jeab_purchases_mysql_live()) {
        $legacy = 'sql';
    } else {
        $pdo = jeab_purchases_store_pdo();
        if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
            $legacy = jeab_settings_value($pdo, $driver, JEAB_PURCHASES_STORAGE_KEY) === 'sql' ? 'sql' : 'json';
        }
    }
    return function_exists('jeab_data_storage') ? jeab_data_storage('orders', $legacy) : $legacy;
}

function jeab_purchases_store_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    // purchases_tables_v2 ok: tabell och list_json. base: bara grundtabellen från ett klick.
    // purchases_ddl_v1: indexen. Kolumner och index väntar på tick. fail: försöks igen där.
    $ddlFlag = 'purchases_ddl_v1';
    $tablesFlag = 'purchases_tables_v2';
    $ddl = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, $ddlFlag) : null;
    $tables = function_exists('jeab_settings_value') ? jeab_settings_value($pdo, $driver, $tablesFlag) : null;
    if ($ddl === 'ok' && $tables === 'ok') {
        $done[$key] = true;

        return;
    }
    $page = function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl();
    if ($page && ($tables === 'ok' || $tables === 'base')) {
        $done[$key] = true;

        return;
    }
    if ($tables !== 'ok') {
        if ($page) {
            jeab_purchases_create_base($pdo, $driver);
            $marked = function_exists('jeab_settings_put') && jeab_settings_put($pdo, $driver, $tablesFlag, 'base');
            if (!$marked) {
                jeab_purchases_page_list_json($pdo, $driver);
            }
            $done[$key] = true;

            return;
        }
        jeab_purchases_ensure_table($pdo, $driver);
        if (function_exists('jeab_settings_put')) {
            jeab_settings_put($pdo, $driver, $tablesFlag, 'ok');
        }
    }
    if ($ddl === 'ok' || $page) {
        $done[$key] = true;

        return;
    }
    $done[$key] = true;
    if (jeab_purchases_list_indexes($pdo, $driver) && function_exists('jeab_settings_put')) {
        jeab_settings_put($pdo, $driver, $ddlFlag, 'ok');
    }
}

/**
 * list_json när flaggan inte kan sparas. Annars faller nästa sparning.
 * Ett klick med settings-tabell lämnar kolumnen till tick.
 */
function jeab_purchases_page_list_json(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        return;
    }
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(purchases)')->fetchAll() as $col) {
        if (is_array($col)) {
            $cols[] = (string) ($col['name'] ?? '');
        }
    }
    if (!in_array('list_json', $cols, true)) {
        $pdo->exec('ALTER TABLE purchases ADD COLUMN list_json TEXT');
    }
}

/** Grundtabell utan list_json. Ett klick får skapa den. Kolumner hör hemma i tick. */
function jeab_purchases_create_base(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS purchases (
                reminder_id INT UNSIGNED NOT NULL,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (reminder_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchases (
            reminder_id INTEGER PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}

/** Tabell och list_json. Inget index. */
function jeab_purchases_ensure_table(PDO $pdo, string $driver): void
{
    jeab_purchases_create_base($pdo, $driver);
    if (!function_exists('jeab_ensure_table_columns')) {
        return;
    }
    $short = $driver === 'mysql' ? 'VARCHAR(64) DEFAULT NULL' : 'TEXT';
    $flag = $driver === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    $when = $driver === 'mysql' ? 'DATETIME DEFAULT NULL' : 'TEXT';
    $contact = $driver === 'mysql' ? 'VARCHAR(64) NOT NULL DEFAULT \'\'' : 'TEXT NOT NULL DEFAULT \'\'';
    $listJson = $driver === 'mysql' ? 'LONGTEXT DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'purchases', [
        'company' => $short,
        'kind' => $short,
        'seller' => $short,
        'klart' => $flag,
        'created_at' => $when,
        'contact_id' => $contact,
        'contact_indexed' => $flag,
        // Tavlan för #921. Öppnad order läser fortfarande payload.
        'list_json' => $listJson,
    ], $driver);
}

/**
 * Index som matchar filter och påfyllnad. Ett sammansatt index med created_at
 * väntar tills listans OR company IS NULL är borta, annars används det inte.
 */
function jeab_purchases_list_indexes(PDO $pdo, string $driver): bool
{
    if (!function_exists('jeab_existing_index_names') || !function_exists('jeab_list_index_create') || !function_exists('jeab_index_column_ok')) {
        return false;
    }
    $wanted = [
        'purchases_company_klart' => ['company', 'klart'],
        'purchases_seller' => ['seller'],
        'purchases_kind' => ['kind'],
        'purchases_created' => ['created_at'],
        'purchases_contact_id' => ['contact_id'],
        'purchases_contact_indexed' => ['contact_indexed'],
    ];
    $have = jeab_existing_index_names($pdo, 'purchases', $driver);
    $failed = false;
    foreach ($wanted as $name => $columns) {
        if (in_array($name, $have, true)) {
            continue;
        }
        $ok = true;
        foreach ($columns as $column) {
            if (!jeab_index_column_ok($pdo, 'purchases', $column, $driver)) {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }
        $cols = implode(', ', $columns);
        $sql = function_exists('jeab_create_index_sql')
            ? jeab_create_index_sql($driver, $name, 'purchases (' . $cols . ')')
            : ($driver === 'mysql'
                ? 'CREATE INDEX ' . $name . ' ON purchases (' . $cols . ')'
                : 'CREATE INDEX IF NOT EXISTS ' . $name . ' ON purchases (' . $cols . ')');
        try {
            jeab_list_index_create($pdo, $sql);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'Duplicate') !== false || stripos($message, 'already exists') !== false) {
                continue;
            }
            $failed = true;
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('db', 'CREATE INDEX misslyckades: ' . $name);
            }
            if (function_exists('jeab_settings_put')) {
                jeab_settings_put($pdo, $driver, 'purchases_ddl_v1', 'fail:' . $name);
            }
        }
    }

    return !$failed;
}

/** PDO för läs/skriv: live MySQL först, annars sqlite med flagga sql. */
function jeab_purchases_sql_pdo(): ?PDO
{
    $injected = $GLOBALS['_JEAB_PURCHASES_PDO'] ?? null;
    if ($injected instanceof PDO) {
        jeab_purchases_store_ensure($injected);

        return $injected;
    }
    if (function_exists('jeab_sql_store_pdo')) {
        $legacy = jeab_sql_store_pdo();
        if ($legacy instanceof PDO) {
            jeab_purchases_store_ensure($legacy);
            return $legacy;
        }
    }
    if (!empty($GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'])) {
        $pdo = jeab_purchases_store_pdo();
        if ($pdo instanceof PDO) {
            jeab_purchases_store_ensure($pdo);
            return $pdo;
        }
    }
    if (jeab_purchases_storage_mode() !== 'sql') {
        return null;
    }
    $pdo = jeab_purchases_store_pdo();
    if ($pdo instanceof PDO) {
        jeab_purchases_store_ensure($pdo);
        return $pdo;
    }
    return null;
}

/**
 * Ordrar-listan: bolag och Allt klart i SQL. JSON-läget filtrerar i PHP.
 * $limit skär i SQL. Utan den kommer hela urvalet, fortfarande bara list_json
 * när den finns.
 *
 * @return list<array<string, mixed>>
 */
function jeab_purchase_list(string $company, bool $klart, ?int $limit = null, int $offset = 0, ?string $kind = null): array
{
    $company = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($company) ?? 'jarfalla')
        : (trim($company) !== '' ? $company : 'jarfalla');
    $pdo = jeab_purchases_sql_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_sql_purchases_list')) {
        try {
            return jeab_sql_purchases_list($pdo, $company, $klart, $limit, $offset, $kind);
        } catch (Throwable) {
            // Samma tavla som tidigare: PHP-filtret när SQL-listan kastar.
        }
    }
    $out = [];
    if (!function_exists('jeab_purchase_all')) {
        return $out;
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowCompany = function_exists('jeab_normalize_company')
            ? (jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla')
            : 'jarfalla';
        if ($rowCompany !== $company) {
            continue;
        }
        $done = function_exists('jeab_ordrar_allt_klart') && jeab_ordrar_allt_klart($row);
        if ($done !== $klart) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * Skriver contact_id från payload i bitar. Flaggan purchases_contact_id_v1 hoppar över
 * när varje rad redan är indexerad, så en kontaktvy inte läser hela ordertabellen.
 */
function jeab_purchases_contact_index_backfill(PDO $pdo): void
{
    static $seen = [];
    $oid = spl_object_id($pdo);
    $stamp = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '1');
    if (($seen[$oid] ?? '') === $stamp) {
        return;
    }
    $seen[$oid] = $stamp;
    jeab_purchases_store_ensure($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'purchases_contact_id_v1') === 'ok') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (!function_exists('jeab_db_get_lock') || !jeab_db_get_lock($pdo, 'jeab_purchases_contact_index')) {
        return;
    }
    try {
        jeab_pdo_transaction($pdo, static function () use ($pdo, $driver): void {
            $stmt = $pdo->query('SELECT reminder_id, payload FROM purchases WHERE contact_indexed = 0 LIMIT 400');
            $rows = $stmt ? $stmt->fetchAll() : [];
            if ($rows === []) {
                if (function_exists('jeab_settings_put')) {
                    jeab_settings_put($pdo, $driver, 'purchases_contact_id_v1', 'ok');
                }

                return;
            }
            $upd = $pdo->prepare('UPDATE purchases SET contact_id = ?, contact_indexed = 1 WHERE reminder_id = ?');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $data = json_decode((string) ($row['payload'] ?? ''), true);
                $cid = is_array($data) ? trim((string) ($data['contact_id'] ?? '')) : '';
                $upd->execute([mb_substr($cid, 0, 64), (int) ($row['reminder_id'] ?? 0)]);
            }
        });
    } catch (Throwable) {
    } finally {
        if (function_exists('jeab_db_release_lock')) {
            jeab_db_release_lock($pdo, 'jeab_purchases_contact_index');
        }
    }
}

/**
 * Fyller company/klart (och kind/seller/created_at) från payload i bitar.
 * Listan filtrerar på kolumnerna. Sidan fyller inte.
 */
function jeab_purchases_list_index_backfill(PDO $pdo): void
{
    // Samma sidspärr som list_json. Tick och migrate fyller. Ett kontorsklick gör det inte.
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    static $seen = [];
    $oid = spl_object_id($pdo);
    $stamp = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '1');
    if (($seen[$oid] ?? '') === $stamp) {
        return;
    }
    $seen[$oid] = $stamp;
    jeab_purchases_store_ensure($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'purchases_list_index_v1') === 'ok') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (!function_exists('jeab_db_get_lock') || !jeab_db_get_lock($pdo, 'jeab_purchases_list_index')) {
        return;
    }
    try {
        jeab_pdo_transaction($pdo, static function () use ($pdo, $driver): void {
            $stmt = $pdo->query("SELECT reminder_id, payload FROM purchases WHERE company IS NULL OR company = '' ORDER BY reminder_id LIMIT 400");
            $rows = $stmt ? $stmt->fetchAll() : [];
            if ($rows === []) {
                if (function_exists('jeab_settings_put')) {
                    jeab_settings_put($pdo, $driver, 'purchases_list_index_v1', 'ok');
                }

                return;
            }
            if (!function_exists('jeab_sql_purchases_index_values')) {
                return;
            }
            $upd = $pdo->prepare('UPDATE purchases SET company = ?, kind = ?, seller = ?, klart = ?, created_at = ? WHERE reminder_id = ? AND (company IS NULL OR company = \'\')');
            // «-» är inget bolag. Raden lämnar kön. Ordrar läser payload och visade den inte.
            $skip = $pdo->prepare('UPDATE purchases SET company = ? WHERE reminder_id = ? AND (company IS NULL OR company = \'\')');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['reminder_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $data = function_exists('jeab_sql_purchases_decode')
                    ? jeab_sql_purchases_decode($id, $row['payload'] ?? '')
                    : json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($data)) {
                    $skip->execute(['-', $id]);
                    continue;
                }
                $idx = jeab_sql_purchases_index_values($data);
                $upd->execute([
                    $idx['company'],
                    $idx['kind'],
                    $idx['seller'],
                    $idx['klart'],
                    $idx['created_at'],
                    $id,
                ]);
            }
        });
    } catch (Throwable) {
    } finally {
        if (function_exists('jeab_db_release_lock')) {
            jeab_db_release_lock($pdo, 'jeab_purchases_list_index');
        }
    }
}

/**
 * Fyller list_json från payload. Sidan anropar inte det här.
 * En payload som inte går att läsa märks, så samma 200 rader inte väljs igen.
 * purchases_list_json_v1 blir ok först när inga tomma rader finns kvar.
 */
function jeab_purchases_list_json_backfill(PDO $pdo): void
{
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    static $seen = [];
    $oid = spl_object_id($pdo);
    $stamp = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '1');
    if (($seen[$oid] ?? '') === $stamp) {
        return;
    }
    $seen[$oid] = $stamp;
    jeab_purchases_store_ensure($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'purchases_list_json_v1') === 'ok') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (!function_exists('jeab_sql_purchases_list_json')) {
        return;
    }
    if (!function_exists('jeab_db_get_lock') || !jeab_db_get_lock($pdo, 'jeab_purchases_list_json')) {
        return;
    }
    try {
        jeab_pdo_transaction($pdo, static function () use ($pdo, $driver): void {
            $stmt = $pdo->query("SELECT reminder_id, payload FROM purchases WHERE list_json IS NULL OR list_json = '' ORDER BY reminder_id LIMIT 200");
            $rows = $stmt ? $stmt->fetchAll() : [];
            if ($rows === []) {
                if (function_exists('jeab_settings_put')) {
                    jeab_settings_put($pdo, $driver, 'purchases_list_json_v1', 'ok');
                }

                return;
            }
            $upd = $pdo->prepare('UPDATE purchases SET list_json = ? WHERE reminder_id = ? AND (list_json IS NULL OR list_json = \'\')');
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (int) ($row['reminder_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $data = function_exists('jeab_sql_purchases_decode')
                    ? jeab_sql_purchases_decode($id, $row['payload'] ?? '')
                    : json_decode((string) ($row['payload'] ?? ''), true);
                if (!is_array($data)) {
                    // Inte en order. Icke-tom så nästa tick tar nästa bit, inte samma rader.
                    $upd->execute(['{"_jeab_list_skip":1,"reminder_id":' . $id . '}', $id]);
                    continue;
                }
                $json = jeab_sql_purchases_list_json($data);
                if ($json === '') {
                    $json = '{"_jeab_list_skip":1,"reminder_id":' . $id . '}';
                }
                $upd->execute([$json, $id]);
            }
        });
    } catch (Throwable) {
    } finally {
        if (function_exists('jeab_db_release_lock')) {
            jeab_db_release_lock($pdo, 'jeab_purchases_list_json');
        }
    }
}

function jeab_purchases_sql_set_storage(string $mode): bool
{
    if (jeab_purchases_mysql_live()) {
        return $mode === 'sql';
    }
    $pdo = jeab_purchases_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    return jeab_settings_put($pdo, $driver, JEAB_PURCHASES_STORAGE_KEY, $mode === 'sql' ? 'sql' : 'json');
}

/** @param array<string, array<string, mixed>> $json
 *  @param array<string, array<string, mixed>> $sql
 *  @return list<string>
 */
function jeab_purchases_json_only_ids(array $json, array $sql): array
{
    $only = [];
    foreach ($json as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ((int) ($row['reminder_id'] ?? $key));
        if ($id === '0' || isset($sql[$id])) {
            continue;
        }
        $only[] = $id;
    }
    sort($only, SORT_STRING);
    return $only;
}

/** @param mixed $value */
function jeab_purchases_sql_canon(mixed $value): string
{
    $norm = jeab_purchases_sql_sort($value);
    $json = json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/** @param mixed $value */
function jeab_purchases_sql_sort(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value);
    }
    foreach ($value as $k => $item) {
        $value[$k] = jeab_purchases_sql_sort($item);
    }
    return $value;
}

/** @param array<string, array<string, mixed>> $rows */
function jeab_purchases_sql_checksum(array $rows): string
{
    $ids = array_keys($rows);
    sort($ids, SORT_STRING);
    $canonical = [];
    foreach ($ids as $id) {
        $row = $rows[$id] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $canonical[$id] = $row;
    }
    return jeab_purchases_sql_canon($canonical);
}

/**
 * @param array<string, array<string, mixed>> $json
 * @param array<string, array<string, mixed>> $sql
 * @return array{match:bool,rows:list<array{id:string,match:bool}>}
 */
function jeab_purchases_sql_sample(array $json, array $sql, int $sampleN): array
{
    $ids = array_keys($json);
    sort($ids, SORT_STRING);
    $picked = array_slice($ids, 0, $sampleN);
    $ok = true;
    $rows = [];
    foreach ($picked as $id) {
        $left = $json[$id] ?? null;
        $right = $sql[$id] ?? null;
        if (!is_array($left)) {
            continue;
        }
        if (!is_array($right)) {
            $ok = false;
            $rows[] = ['id' => (string) $id, 'match' => false];
            continue;
        }
        $match = jeab_purchases_sql_checksum([(string) $id => $left]) === jeab_purchases_sql_checksum([(string) $id => $right]);
        if (!$match) {
            $ok = false;
        }
        $rows[] = ['id' => (string) $id, 'match' => $match];
    }
    return ['match' => $ok, 'rows' => $rows];
}
