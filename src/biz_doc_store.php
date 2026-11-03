<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Mindre affärs-/cache-dokument i SQL (json_docs).
 * Live (MySQL): läser och skriver SQL utan flagga. JSON läses bara som fallback tills migrering.
 * SQLite/lab: flaggan biz_storage_* = sql efter cutover.
 */

/** @return list<string> */
function jeab_biz_doc_names(): array
{
    return ['code_people', 'reminder_ort_cache', 'reminders_prune', 'webshop_catchup'];
}

function jeab_biz_doc_storage_setting(string $name): string
{
    return 'biz_storage_' . $name;
}

function jeab_biz_doc_sql_key(string $name): string
{
    return 'biz_' . $name;
}

function jeab_biz_doc_store_pdo(): ?PDO
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

function jeab_biz_doc_store_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS json_docs (
                doc_key VARCHAR(64) NOT NULL,
                payload LONGTEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (doc_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS json_docs (
            doc_key TEXT PRIMARY KEY,
            payload TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}

/** Live flowstack.example.test: driver mysql och json_docs via jeab_sql_store_pdo. */
function jeab_biz_doc_mysql_live(): bool
{
    if (!empty($GLOBALS['_JEAB_BIZ_DOC_LEGACY_SQL'])) {
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

function jeab_biz_doc_storage_mode(string $name): string
{
    if (!in_array($name, jeab_biz_doc_names(), true)) {
        return 'json';
    }
    $legacy = 'json';
    if (jeab_biz_doc_mysql_live()) {
        $legacy = 'sql';
    } else {
        $pdo = jeab_biz_doc_store_pdo();
        if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
            $legacy = jeab_settings_value($pdo, $driver, jeab_biz_doc_storage_setting($name)) === 'sql' ? 'sql' : 'json';
        }
    }
    return function_exists('jeab_data_storage') ? jeab_data_storage('biz_docs', $legacy) : $legacy;
}

function jeab_biz_doc_use_sql(string $name): bool
{
    if (jeab_biz_doc_storage_mode($name) !== 'sql') {
        return false;
    }
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    jeab_biz_doc_store_ensure($pdo);
    return true;
}

function jeab_biz_doc_set_storage(string $name, string $mode): bool
{
    if (!in_array($name, jeab_biz_doc_names(), true)) {
        return false;
    }
    if (jeab_biz_doc_mysql_live()) {
        return $mode === 'sql';
    }
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    return jeab_settings_put($pdo, $driver, jeab_biz_doc_storage_setting($name), $mode === 'sql' ? 'sql' : 'json');
}

function jeab_biz_doc_json_path(string $name): string
{
    return match ($name) {
        'code_people' => function_exists('jeab_code_people_path') ? jeab_code_people_path() : '',
        'reminder_ort_cache' => function_exists('jeab_reminder_ort_cache_path') ? jeab_reminder_ort_cache_path() : '',
        'reminders_prune' => function_exists('jeab_prune_stamp_path') ? jeab_prune_stamp_path() : '',
        'webshop_catchup' => function_exists('jeab_data_dir') ? jeab_data_dir() . '/webshop-catchup.json' : '',
        default => '',
    };
}

/** @return array<string, mixed> */
function jeab_biz_doc_json_read(string $name): array
{
    $path = jeab_biz_doc_json_path($name);
    if ($path === '' || !is_file($path)) {
        return [];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

/** @return array<string, mixed> */
function jeab_biz_doc_sql_read(string $name): array
{
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_get')) {
        return [];
    }
    jeab_biz_doc_store_ensure($pdo);
    $doc = jeab_sql_doc_get($pdo, jeab_biz_doc_sql_key($name));
    return is_array($doc) ? $doc : [];
}

/** @param array<string, mixed> $data */
function jeab_biz_doc_sql_write(string $name, array $data): void
{
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_put')) {
        throw new RuntimeException('SQL saknas.');
    }
    jeab_biz_doc_store_ensure($pdo);
    jeab_sql_doc_put($pdo, jeab_biz_doc_sql_key($name), $data);
}

/** @param mixed $value */
function jeab_biz_doc_canon(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/**
 * Slår ihop JSON in i SQL utan att skriva över befintliga nycklar.
 *
 * @param array<string, mixed> $json
 * @param array<string, mixed> $sql
 * @return array<string, mixed>
 */
function jeab_biz_doc_merge_json_only(array $json, array $sql): array
{
    foreach ($json as $key => $value) {
        if (!array_key_exists($key, $sql)) {
            $sql[$key] = $value;
        }
    }
    return $sql;
}

/** @param array<string, mixed> $json
 *  @param array<string, mixed> $sql
 *  @return list<string|int>
 */
function jeab_biz_doc_json_only_keys(array $json, array $sql): array
{
    $only = [];
    foreach ($json as $key => $_value) {
        if (!array_key_exists($key, $sql)) {
            $only[] = $key;
        }
    }
    return $only;
}

/** En fellogg-rad per dokument när SQL-läget är på men kopian är tom. */
function jeab_biz_doc_log_empty_sql_once(string $name): void
{
    static $logged = [];
    if (isset($logged[$name]) || !function_exists('jeab_log_app_error')) {
        return;
    }
    $logged[$name] = true;
    jeab_log_app_error('biz_doc', $name . ': SQL är tom, läser JSON', [
        'level' => 'info',
        'no_webhook' => true,
        'skip_request' => true,
        'dedupe_seconds' => 86400 * 365,
        'fingerprint' => hash('sha256', 'biz_doc_empty_sql|' . $name),
    ]);
}

/**
 * SQL-läge med tom kopia läser JSON-filen. Filen skrivs inte.
 *
 * @return array<string, mixed>
 */
function jeab_biz_doc_load(string $name): array
{
    if (!jeab_biz_doc_use_sql($name)) {
        return jeab_biz_doc_json_read($name);
    }
    $sql = jeab_biz_doc_sql_read($name);
    if ($sql !== []) {
        return $sql;
    }
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', 'biz_docs')) {
        return [];
    }
    $json = jeab_biz_doc_json_read($name);
    if ($json !== []) {
        jeab_biz_doc_log_empty_sql_once($name);
    }
    return $json;
}

/** @param array<string, mixed> $data */
function jeab_biz_doc_save(string $name, array $data): void
{
    if (!jeab_biz_doc_use_sql($name)) {
        throw new RuntimeException('SQL saknas.');
    }
    jeab_biz_doc_sql_write($name, $data);
}

/** @return array<string, mixed> */
function jeab_webshop_catchup_read(): array
{
    return jeab_biz_doc_load('webshop_catchup');
}

/** @param array<string, mixed> $data */
function jeab_webshop_catchup_write(array $data): void
{
    jeab_biz_doc_save('webshop_catchup', $data);
}
