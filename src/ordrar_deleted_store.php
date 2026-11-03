<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Raderade ordrar (ordrar-deleted.json).
 * Live MySQL: json_docs via jeab_sql_store_pdo (som tidigare).
 * SQLite/lab: flaggan ordrar_deleted_storage efter cutover.
 */

const JEAB_ORDRAR_DELETED_STORAGE_KEY = 'ordrar_deleted_storage';
const JEAB_ORDRAR_DELETED_DOC_KEY = 'ordrar_deleted';

function jeab_ordrar_deleted_store_pdo(): ?PDO
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

function jeab_ordrar_deleted_mysql_live(): bool
{
    if (!empty($GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL'])) {
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

function jeab_ordrar_deleted_storage_mode(): string
{
    if (function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('ordrar_deleted_json')) {
        return 'json';
    }
    // Live MySQL har alltid läst json_docs, även när DATA_BACKEND eller
    // data-json-types säger json. Den filen är inte källan där.
    if (jeab_ordrar_deleted_mysql_live()) {
        return 'sql';
    }
    $legacy = 'json';
    $pdo = jeab_ordrar_deleted_store_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $legacy = jeab_settings_value($pdo, $driver, JEAB_ORDRAR_DELETED_STORAGE_KEY) === 'sql' ? 'sql' : 'json';
    }

    return function_exists('jeab_data_storage') ? jeab_data_storage('ordrar_deleted', $legacy) : $legacy;
}

function jeab_ordrar_deleted_store_ensure(PDO $pdo): void
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

/** PDO för läs/skriv, samma ordning som före flagg-lagret. */
function jeab_ordrar_deleted_sql_pdo(): ?PDO
{
    if (function_exists('jeab_sql_store_pdo')) {
        $legacy = jeab_sql_store_pdo();
        if ($legacy instanceof PDO) {
            jeab_ordrar_deleted_store_ensure($legacy);
            return $legacy;
        }
    }
    // Svepet kopierar innan data_backend slås på. Läget för vanliga läsningar är oförändrat.
    if (!empty($GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL']) || !empty($GLOBALS['_JEAB_ORDRAR_DELETED_MIGRATE_PDO'])) {
        $pdo = jeab_ordrar_deleted_store_pdo();
        if ($pdo instanceof PDO) {
            jeab_ordrar_deleted_store_ensure($pdo);
            return $pdo;
        }
    }
    if (jeab_ordrar_deleted_storage_mode() !== 'sql') {
        return null;
    }
    $pdo = jeab_ordrar_deleted_store_pdo();
    if ($pdo instanceof PDO) {
        jeab_ordrar_deleted_store_ensure($pdo);
        return $pdo;
    }
    return null;
}

function jeab_ordrar_deleted_sql_set_storage(string $mode): bool
{
    if (jeab_ordrar_deleted_mysql_live()) {
        return $mode === 'sql';
    }
    $pdo = jeab_ordrar_deleted_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    return jeab_settings_put($pdo, $driver, JEAB_ORDRAR_DELETED_STORAGE_KEY, $mode === 'sql' ? 'sql' : 'json');
}

/** @param mixed $value */
function jeab_ordrar_deleted_sql_canon(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/** @param array<string, mixed> $doc */
function jeab_ordrar_deleted_sql_checksum(array $doc): string
{
    if (function_exists('jeab_ordrar_deleted_normalize')) {
        $doc = jeab_ordrar_deleted_normalize($doc);
    }
    $ids = is_array($doc['ids'] ?? null) ? $doc['ids'] : [];
    $hs = is_array($doc['hs'] ?? null) ? $doc['hs'] : [];
    ksort($ids);
    ksort($hs);
    return jeab_ordrar_deleted_sql_canon(['ids' => $ids, 'hs' => $hs]);
}

/**
 * @param array<string, mixed> $json
 * @param array<string, mixed> $sql
 * @return array<string, mixed>
 */
function jeab_ordrar_deleted_merge_json_only(array $json, array $sql): array
{
    if (!function_exists('jeab_ordrar_deleted_normalize')) {
        return $sql;
    }
    $json = jeab_ordrar_deleted_normalize($json);
    $sql = jeab_ordrar_deleted_normalize($sql);
    foreach ($json['ids'] as $id => $row) {
        if (!isset($sql['ids'][$id])) {
            $sql['ids'][$id] = $row;
        }
    }
    foreach ($json['hs'] as $hs => $reminderId) {
        if (!isset($sql['hs'][$hs])) {
            $sql['hs'][$hs] = $reminderId;
        }
    }
    return jeab_ordrar_deleted_normalize($sql);
}
