<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Återgång per del. 1 i settings (eller $GLOBALS['_JEAB_JSON_SQL_ROLLBACK'])
 * läser JSON igen. Av som standard. Filerna på disk rörs inte.
 */

function jeab_json_sql_rollback(string $key): bool
{
    $global = $GLOBALS['_JEAB_JSON_SQL_ROLLBACK'] ?? null;
    if (is_array($global) && array_key_exists($key, $global)) {
        return !empty($global[$key]);
    }
    if (!function_exists('jeab_data_pdo') || !function_exists('jeab_settings_value')) {
        return false;
    }
    try {
        $pdo = jeab_data_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';

        return jeab_settings_value($pdo, $driver, 'json_sql_' . $key) === '1';
    } catch (Throwable) {
        return false;
    }
}

function jeab_json_sql_rollback_set(string $key, bool $on): bool
{
    if (!isset($GLOBALS['_JEAB_JSON_SQL_ROLLBACK']) || !is_array($GLOBALS['_JEAB_JSON_SQL_ROLLBACK'])) {
        $GLOBALS['_JEAB_JSON_SQL_ROLLBACK'] = [];
    }
    $GLOBALS['_JEAB_JSON_SQL_ROLLBACK'][$key] = $on;
    if (!function_exists('jeab_data_pdo') || !function_exists('jeab_settings_put')) {
        return false;
    }
    try {
        $pdo = jeab_data_pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';

        return jeab_settings_put($pdo, $driver, 'json_sql_' . $key, $on ? '1' : '0');
    } catch (Throwable) {
        return false;
    }
}

/** Sant när inkorgen är SQL och reservläget är av. Flaggan inkorg_json_fallback slår på JSON igen. */
function jeab_inkorg_json_reserve_closed(): bool
{
    return function_exists('jeab_inkorg_use_sql')
        && jeab_inkorg_use_sql()
        && !jeab_json_sql_rollback('inkorg_json_fallback');
}

/**
 * Filen saknas, är oläsbar, tom, eller JSON utan rader.
 * $rowKeys: någon av de nycklarna måste vara en icke-tom array. En icke-tom lista i roten räknas också.
 * Utan $rowKeys räknas varje icke-tom array.
 */
function jeab_sql_json_source_missing(string $path, ?array $rowKeys = null): bool
{
    if ($path === '' || !is_file($path)) {
        return true;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return true;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || $data === []) {
        return true;
    }
    if ($rowKeys === null) {
        return false;
    }
    if (array_is_list($data)) {
        return false;
    }
    foreach ($rowKeys as $key) {
        $value = $data[$key] ?? null;
        if (is_array($value) && $value !== []) {
            return false;
        }
    }

    return true;
}

/**
 * sql_resync / sql_migrate när JSON-källan saknas eller är tom.
 * Skriver inte SQL.
 *
 * @return array<string, mixed>
 */
function jeab_sql_json_source_skip(bool $dry, string $action): array
{
    return [
        'ok' => true,
        'dry' => $dry,
        'action' => $action,
        'skipped' => 'json_source_missing',
        'written' => false,
        'would' => 0,
        'updated' => 0,
        'file_unchanged' => true,
    ];
}
