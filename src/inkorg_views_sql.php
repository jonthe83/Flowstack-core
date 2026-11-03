<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';

/**
 * Hook inkorg_views.sql_resync.
 * Lägger filter och priority om de saknas, och uppdaterar rader som skiljer sig.
 * Extra SQL-rader lämnas. rules.json skrivs inte. dry saknas betyder dry=1.
 */

/** Samma PDO som kalenderlogg och raderade ordrar: MySQL-butiken först, sedan sqlite/lab. */
function jeab_inkorg_views_sql_pdo(): ?PDO
{
    if (function_exists('jeab_sql_store_pdo')) {
        $pdo = jeab_sql_store_pdo();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (function_exists('jeab_data_pdo')) {
        $pdo = jeab_data_pdo();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }

    return $store instanceof JeabPdoStore ? $store->pdo() : null;
}

function jeab_inkorg_views_sql_boot(): void
{
    if (!function_exists('jeab_migrate_inkorg_views_rows') || !function_exists('jeab_migrate_inkorg_views_module')) {
        require_once __DIR__ . '/migrate/inkorg_views.php';
    }
}

function jeab_inkorg_views_sql_dry(array $input): bool
{
    if (array_key_exists('write', $input) && ($input['write'] === 1 || $input['write'] === '1' || $input['write'] === true)) {
        return false;
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @return list<string> */
function jeab_inkorg_views_sql_columns(PDO $pdo): array
{
    $have = [];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM inkorg_views');
        } else {
            $stmt = $pdo->query('PRAGMA table_info(inkorg_views)');
        }
        if (!$stmt instanceof PDOStatement) {
            return [];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['Field'] ?? $row['name'] ?? '');
            if ($name !== '') {
                $have[] = $name;
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $have;
}

/**
 * Lägger filter och priority om de saknas. Torr körning anropar inte den här.
 *
 * @return list<string> kolumner som lades till
 */
function jeab_inkorg_views_sql_ensure_columns(PDO $pdo): array
{
    $have = jeab_inkorg_views_sql_columns($pdo);
    if ($have === []) {
        return [];
    }
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $defs = $mysql
        ? ['filter' => 'LONGTEXT NULL', 'priority' => 'INT NOT NULL DEFAULT 0']
        : ['filter' => "TEXT NOT NULL DEFAULT ''", 'priority' => 'INTEGER NOT NULL DEFAULT 0'];
    $added = [];
    foreach ($defs as $column => $type) {
        if (in_array($column, $have, true)) {
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE inkorg_views ADD COLUMN ' . $column . ' ' . $type);
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'duplicate') || str_contains($msg, 'already exists')) {
                $have[] = $column;
                continue;
            }
            throw $e;
        }
        $have[] = $column;
        $added[] = $column;
    }

    return $added;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_views_sql_resync(array $input): array
{
    $dry = jeab_inkorg_views_sql_dry($input);
    $rulesPath = (function_exists('jeab_data_dir') ? jeab_data_dir() : '') . '/inkorg/rules.json';
    if (jeab_sql_json_source_missing($rulesPath, ['views'])) {
        return jeab_sql_json_source_skip($dry, 'inkorg_views.sql_resync') + [
            'json_rows' => 0,
        ];
    }
    if (array_key_exists('rollback', $input)) {
        $on = !($input['rollback'] === false || $input['rollback'] === 0 || $input['rollback'] === '0' || $input['rollback'] === 'false');
        if (!$dry && function_exists('jeab_json_sql_rollback_set')) {
            jeab_json_sql_rollback_set('inkorg_views_json', $on);
        }

        return [
            'ok' => true,
            'dry' => $dry,
            'action' => 'inkorg_views.sql_resync',
            'rollback' => $on,
            'would_rollback' => 1,
            'written' => !$dry,
            'file_unchanged' => true,
            'updated' => 0,
            'inserted' => 0,
            'would_update' => 0,
            'would_insert' => 0,
            'differ' => 0,
        ];
    }
    jeab_inkorg_views_sql_boot();
    $pdo = jeab_inkorg_views_sql_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_migrate_inkorg_views_rows') || !function_exists('jeab_migrate_inkorg_views_module')) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.', 'file_unchanged' => true];
    }
    $disk = function_exists('jeab_inkorg_json_side_disk') ? jeab_inkorg_json_side_disk('rules.json') : null;
    $jsonRows = jeab_migrate_inkorg_views_rows(is_array($disk) ? $disk : []);
    $have = jeab_inkorg_views_sql_columns($pdo);
    $missing = [];
    foreach (['filter', 'priority'] as $column) {
        if (!in_array($column, $have, true)) {
            $missing[] = $column;
        }
    }
    $sqlById = [];
    if ($have !== []) {
        $wanted = array_values(array_intersect(['view_id', 'name', 'position', 'payload', 'filter', 'priority'], $have));
        if ($wanted !== []) {
            $sql = 'SELECT ' . implode(', ', $wanted) . ' FROM inkorg_views';
            try {
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (is_array($row) && (string) ($row['view_id'] ?? '') !== '') {
                        $sqlById[(string) $row['view_id']] = $row;
                    }
                }
            } catch (Throwable) {
                $sqlById = [];
            }
        }
    }
    $pending = [];
    $differ = 0;
    $wouldUpdate = 0;
    $wouldInsert = 0;
    foreach ($jsonRows as $row) {
        $id = (string) ($row['view_id'] ?? '');
        if ($id === '' || !isset($sqlById[$id])) {
            $wouldInsert++;
            $pending[] = $row;
            continue;
        }
        $sqlRow = $sqlById[$id];
        $valueDiffer = (string) ($sqlRow['name'] ?? '') !== (string) $row['name']
            || (int) ($sqlRow['position'] ?? -1) !== (int) $row['position']
            || (string) ($sqlRow['payload'] ?? '') !== (string) $row['payload'];
        if (in_array('filter', $have, true) && (string) ($sqlRow['filter'] ?? '') !== (string) $row['filter']) {
            $valueDiffer = true;
        }
        if (in_array('priority', $have, true) && (int) ($sqlRow['priority'] ?? 0) !== (int) $row['priority']) {
            $valueDiffer = true;
        }
        $needsColumns = $missing !== [] && ((string) $row['filter'] !== '' && (string) $row['filter'] !== '[]' && (string) $row['filter'] !== '{}' || (int) $row['priority'] !== 0);
        if ($valueDiffer) {
            $differ++;
        }
        if ($valueDiffer || $needsColumns) {
            $wouldUpdate++;
            $pending[] = $row;
        }
    }
    $sqlOnly = 0;
    $jsonIds = [];
    foreach ($jsonRows as $row) {
        $jsonIds[(string) ($row['view_id'] ?? '')] = true;
    }
    foreach ($sqlById as $id => $_row) {
        if (!isset($jsonIds[$id])) {
            $sqlOnly++;
        }
    }
    $updated = 0;
    $inserted = 0;
    if (!$dry && ($pending !== [] || $missing !== [])) {
        $module = jeab_migrate_inkorg_views_module();
        if ($have !== [] && $missing !== []) {
            jeab_inkorg_views_sql_ensure_columns($pdo);
        }
        jeab_migrate_ensure($pdo, $module);
        if ($missing !== []) {
            jeab_inkorg_views_sql_ensure_columns($pdo);
        }
        if ($pending !== [] && function_exists('jeab_migrate_upsert')) {
            jeab_migrate_upsert($pdo, $module, $pending, true);
        }
        $updated = $wouldUpdate;
        $inserted = $wouldInsert;
    }

    return [
        'ok' => true,
        'dry' => $dry,
        'action' => 'inkorg_views.sql_resync',
        'json_rows' => count($jsonRows),
        'sql_rows' => count($sqlById),
        'differ' => $differ,
        'would_update' => $wouldUpdate,
        'would_insert' => $wouldInsert,
        'would' => $wouldUpdate + $wouldInsert,
        'updated' => $updated,
        'inserted' => $inserted,
        'columns_missing' => $missing,
        'would_add_columns' => $missing,
        'sql_only' => $sqlOnly,
        'written' => !$dry && ($updated + $inserted) > 0,
        'file_unchanged' => true,
        'rollback' => function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('inkorg_views_json'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_views_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_inkorg_views_sql_resync($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}
