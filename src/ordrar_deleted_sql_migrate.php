<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';
require_once __DIR__ . '/ordrar_deleted_store.php';

/**
 * Hookar: ordrar_deleted.sql_migrate, ordrar_deleted.sql_verify, ordrar_deleted.sql_cutover.
 * migrate skriver bara id/hs som finns i JSON men saknas i SQL.
 */

function jeab_ordrar_deleted_sql_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @param array<string, mixed> $json
 *  @param array<string, mixed> $sql
 *  @return list<string>
 */
function jeab_ordrar_deleted_json_only_ids(array $json, array $sql): array
{
    $only = [];
    foreach (is_array($json['ids'] ?? null) ? $json['ids'] : [] as $id => $_row) {
        if (!isset($sql['ids'][$id])) {
            $only[] = (string) $id;
        }
    }
    sort($only, SORT_STRING);
    return $only;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_deleted_sql_migrate(array $input): array
{
    $dry = jeab_ordrar_deleted_sql_dry($input);
    $path = function_exists('jeab_ordrar_deleted_path') ? jeab_ordrar_deleted_path() : '';
    if (jeab_sql_json_source_missing($path, ['ids', 'hs'])) {
        $sqlIds = [];
        $pdo = function_exists('jeab_ordrar_deleted_sql_pdo') ? jeab_ordrar_deleted_sql_pdo() : null;
        if ($pdo instanceof PDO && function_exists('jeab_sql_doc_get')) {
            $loaded = jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY);
            if (is_array($loaded) && is_array($loaded['ids'] ?? null)) {
                $sqlIds = $loaded['ids'];
            }
        }

        return jeab_sql_json_source_skip($dry, 'ordrar_deleted.sql_migrate') + [
            'json_ids' => 0,
            'sql_ids' => count($sqlIds),
            'sql_only' => count($sqlIds),
            'inserted_ids' => 0,
            'inserted_hs' => 0,
            'json_only' => 0,
            'mysql_live' => function_exists('jeab_ordrar_deleted_mysql_live') && jeab_ordrar_deleted_mysql_live(),
            'storage' => function_exists('jeab_ordrar_deleted_storage_mode') ? jeab_ordrar_deleted_storage_mode() : '',
        ];
    }
    $pdo = jeab_ordrar_deleted_sql_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_put')) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.'];
    }
    $json = ['ids' => [], 'hs' => []];
    if ($path !== '' && is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        if (is_array($raw) && function_exists('jeab_ordrar_deleted_normalize')) {
            $json = jeab_ordrar_deleted_normalize($raw);
        }
    }
    $sqlBefore = jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY) ?? ['ids' => [], 'hs' => []];
    $jsonOnly = jeab_ordrar_deleted_json_only_ids($json, $sqlBefore);
    $hsOnly = jeab_ordrar_deleted_json_only_hs($json, $sqlBefore);
    $sqlOnly = jeab_ordrar_deleted_sql_only_ids($json, $sqlBefore);
    $merged = jeab_ordrar_deleted_merge_json_only($json, $sqlBefore);
    $changed = jeab_ordrar_deleted_sql_checksum($merged) !== jeab_ordrar_deleted_sql_checksum($sqlBefore);
    if (!$dry && $changed) {
        jeab_sql_doc_put($pdo, JEAB_ORDRAR_DELETED_DOC_KEY, $merged);
    }
    $sql = jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY) ?? ['ids' => [], 'hs' => []];
    return [
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry && $changed,
        'would' => $changed ? 1 : 0,
        'inserted_ids' => count($jsonOnly),
        'inserted_hs' => count($hsOnly),
        'sql_only' => count($sqlOnly),
        'json_only' => count(jeab_ordrar_deleted_json_only_ids($json, $sql)),
        'json_ids' => count($json['ids'] ?? []),
        'sql_ids' => count($sql['ids'] ?? []),
        'mysql_live' => jeab_ordrar_deleted_mysql_live(),
        'storage' => jeab_ordrar_deleted_storage_mode(),
        'file_unchanged' => true,
    ];
}

/**
 * Id som redan ligger i SQL och saknas i JSON. De ska inte importeras igen.
 *
 * @param array<string, mixed> $json
 * @param array<string, mixed> $sql
 * @return list<string>
 */
function jeab_ordrar_deleted_sql_only_ids(array $json, array $sql): array
{
    $only = [];
    $have = is_array($json['ids'] ?? null) ? $json['ids'] : [];
    foreach (is_array($sql['ids'] ?? null) ? $sql['ids'] : [] as $id => $_row) {
        if (!isset($have[$id])) {
            $only[] = (string) $id;
        }
    }
    sort($only, SORT_STRING);

    return $only;
}

function jeab_ordrar_deleted_json_only_hs(array $json, array $sql): array
{
    $only = [];
    $have = is_array($sql['hs'] ?? null) ? $sql['hs'] : [];
    foreach (is_array($json['hs'] ?? null) ? $json['hs'] : [] as $hs => $_id) {
        if (!isset($have[$hs])) {
            $only[] = (string) $hs;
        }
    }
    sort($only, SORT_STRING);
    return $only;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_deleted_sql_verify(array $input): array
{
    $pdo = jeab_ordrar_deleted_sql_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'dry' => true];
    }
    $path = function_exists('jeab_ordrar_deleted_path') ? jeab_ordrar_deleted_path() : '';
    $json = ['ids' => [], 'hs' => []];
    if ($path !== '' && is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        if (is_array($raw) && function_exists('jeab_ordrar_deleted_normalize')) {
            $json = jeab_ordrar_deleted_normalize($raw);
        }
    }
    $sql = jeab_sql_doc_get($pdo, JEAB_ORDRAR_DELETED_DOC_KEY) ?? ['ids' => [], 'hs' => []];
    $jsonOnly = jeab_ordrar_deleted_json_only_ids($json, $sql);
    $jsonChecksum = jeab_ordrar_deleted_sql_checksum($json);
    $sqlChecksum = jeab_ordrar_deleted_sql_checksum($sql);
    return [
        'ok' => true,
        'dry' => true,
        'match' => $jsonOnly === [],
        'json_only' => count($jsonOnly),
        'json_ids' => count($json['ids'] ?? []),
        'sql_ids' => count($sql['ids'] ?? []),
        'json_checksum' => $jsonChecksum,
        'sql_checksum' => $sqlChecksum,
        'checksum_match' => $jsonChecksum !== '' && $jsonChecksum === $sqlChecksum,
        'mysql_live' => jeab_ordrar_deleted_mysql_live(),
        'storage' => jeab_ordrar_deleted_storage_mode(),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_deleted_sql_cutover(array $input): array
{
    $dry = jeab_ordrar_deleted_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $before = jeab_ordrar_deleted_storage_mode();
    if ($enable === null) {
        return ['ok' => true, 'dry' => $dry, 'storage' => $before, 'changed' => false];
    }
    if ($enable) {
        $rollbackOn = function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('ordrar_deleted_json');
        if (jeab_ordrar_deleted_mysql_live() && !$rollbackOn) {
            return [
                'ok' => true,
                'dry' => $dry,
                'enable' => 1,
                'noop' => 'mysql_already_sql',
                'storage' => 'sql',
                'changed' => false,
                'file_unchanged' => true,
            ];
        }
        if (jeab_ordrar_deleted_mysql_live() && $rollbackOn) {
            if (!$dry && function_exists('jeab_json_sql_rollback_set')) {
                jeab_json_sql_rollback_set('ordrar_deleted_json', false);
            }
            return [
                'ok' => true,
                'dry' => $dry,
                'enable' => 1,
                'cleared_rollback' => true,
                'storage' => $dry ? $before : 'sql',
                'changed' => !$dry,
                'file_unchanged' => true,
            ];
        }
        $verify = jeab_ordrar_deleted_sql_verify($input);
        if (empty($verify['ok'])) {
            return $verify + ['dry' => $dry, 'storage' => $before];
        }
        if (empty($verify['match']) && empty($input['force'])) {
            return ['ok' => false, 'dry' => $dry, 'error' => 'Verifieringen matchar inte.', 'storage' => $before, 'verify' => $verify];
        }
        if (!$dry && !jeab_ordrar_deleted_sql_set_storage('sql')) {
            return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas.', 'storage' => jeab_ordrar_deleted_storage_mode()];
        }
        if (!$dry && function_exists('jeab_json_sql_rollback_set')) {
            jeab_json_sql_rollback_set('ordrar_deleted_json', false);
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'enable' => 1,
            'storage' => $dry ? $before : 'sql',
            'changed' => !$dry && $before !== 'sql',
        ];
    }
    if ($before !== 'sql' && !(function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('ordrar_deleted_json'))) {
        return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => 'json', 'changed' => false, 'file_unchanged' => true];
    }
    if (!$dry) {
        if (function_exists('jeab_json_sql_rollback_set')) {
            jeab_json_sql_rollback_set('ordrar_deleted_json', true);
        }
        if (!jeab_ordrar_deleted_mysql_live()) {
            jeab_ordrar_deleted_sql_set_storage('json');
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'enable' => 0,
        'storage' => $dry ? $before : 'json',
        'changed' => !$dry && $before !== 'json',
        'rollback' => true,
        'file_unchanged' => true,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_deleted_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'ordrar_deleted.sql_verify') {
        $result = jeab_ordrar_deleted_sql_verify($input);
    } elseif ($action === 'ordrar_deleted.sql_cutover') {
        $result = jeab_ordrar_deleted_sql_cutover($input);
    } else {
        $result = jeab_ordrar_deleted_sql_migrate($input);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}
