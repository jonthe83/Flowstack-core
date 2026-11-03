<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * GI-lån.
 * Live (MySQL): läser och skriver SQL utan flagga. JSON läses bara som fallback tills migrering.
 * SQLite/lab: flaggan gi_loans_storage=sql efter cutover.
 */

const JEAB_GI_LOANS_STORAGE_KEY = 'gi_loans_storage';
const JEAB_GI_LOANS_DOC_KEY = 'gi_loans';

function jeab_gi_loans_store_pdo(): ?PDO
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

function jeab_gi_loans_store_ensure(PDO $pdo): void
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
function jeab_gi_loans_mysql_live(): bool
{
    if (!empty($GLOBALS['_JEAB_GI_LOANS_LEGACY_SQL'])) {
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

function jeab_gi_loans_storage_mode(): string
{
    $legacy = 'json';
    if (jeab_gi_loans_mysql_live()) {
        $legacy = 'sql';
    } else {
        $pdo = jeab_gi_loans_store_pdo();
        if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
            $legacy = jeab_settings_value($pdo, $driver, JEAB_GI_LOANS_STORAGE_KEY) === 'sql' ? 'sql' : 'json';
        }
    }
    return function_exists('jeab_data_storage') ? jeab_data_storage('gi_loans', $legacy) : $legacy;
}

function jeab_gi_loans_use_sql(): bool
{
    if (jeab_gi_loans_storage_mode() !== 'sql') {
        return false;
    }
    $pdo = jeab_gi_loans_store_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    jeab_gi_loans_store_ensure($pdo);
    return true;
}

function jeab_gi_loans_set_storage(string $mode): bool
{
    if (jeab_gi_loans_mysql_live()) {
        return $mode === 'sql';
    }
    $pdo = jeab_gi_loans_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    return jeab_settings_put($pdo, $driver, JEAB_GI_LOANS_STORAGE_KEY, $mode === 'sql' ? 'sql' : 'json');
}

function jeab_gi_loans_json_path(): string
{
    $legacy = defined('JEAB_ROOT') ? JEAB_ROOT . '/gi/data/loans.json' : '';
    if (function_exists('jeab_gi_loans_file') && $legacy !== '') {
        return jeab_gi_loans_file($legacy);
    }
    if (function_exists('jeab_data_dir')) {
        return jeab_data_dir() . '/gi/loans.json';
    }
    return $legacy !== '' ? $legacy : '';
}

/** @return array{loans:list<array<string,mixed>>} */
function jeab_gi_loans_json_read(): array
{
    $path = jeab_gi_loans_json_path();
    if ($path === '' || !is_file($path)) {
        return ['loans' => []];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw) || !isset($raw['loans']) || !is_array($raw['loans'])) {
        return ['loans' => []];
    }
    return ['loans' => array_values(array_filter($raw['loans'], 'is_array'))];
}

/** @return array{loans:list<array<string,mixed>>} */
function jeab_gi_loans_sql_read(): array
{
    $pdo = jeab_gi_loans_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_get')) {
        return ['loans' => []];
    }
    jeab_gi_loans_store_ensure($pdo);
    $doc = jeab_sql_doc_get($pdo, JEAB_GI_LOANS_DOC_KEY);
    if (!is_array($doc) || !isset($doc['loans']) || !is_array($doc['loans'])) {
        return ['loans' => []];
    }
    return ['loans' => array_values(array_filter($doc['loans'], 'is_array'))];
}

/** @param array{loans:list<array<string,mixed>>} $data */
function jeab_gi_loans_sql_write(array $data): void
{
    $pdo = jeab_gi_loans_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_put')) {
        throw new RuntimeException('SQL saknas.');
    }
    jeab_gi_loans_store_ensure($pdo);
    jeab_sql_doc_put($pdo, JEAB_GI_LOANS_DOC_KEY, $data);
}

/** @param mixed $value */
function jeab_gi_loans_canon(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/** @return array{loans:list<array<string,mixed>>} */
function jeab_gi_loans_load(): array
{
    if (jeab_gi_loans_use_sql()) {
        $sql = jeab_gi_loans_sql_read();
        if ($sql['loans'] !== []) {
            return $sql;
        }
    }
    return jeab_gi_loans_json_read();
}

/** @param callable(array{loans:list<array<string,mixed>>}):mixed $mutator */
function jeab_gi_loans_mutate(callable $mutator): mixed
{
    if (!jeab_gi_loans_use_sql()) {
        throw new RuntimeException('SQL saknas.');
    }
    $data = jeab_gi_loans_load();
    $result = $mutator($data);
    jeab_gi_loans_sql_write($data);
    return $result;
}
