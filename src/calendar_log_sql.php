<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';

/**
 * Hook calendar_log.sql_resync.
 * JSON-dokumentet ersätter SQL-dokumentet när de skiljer sig. log_lines rörs inte.
 * Filen på disk skrivs inte. dry saknas betyder dry=1.
 */

function jeab_calendar_log_sql_dry(array $input): bool
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

/** @return array<string, mixed> */
function jeab_calendar_log_sql_json_doc(): array
{
    $path = function_exists('jeab_calendar_log_path') ? jeab_calendar_log_path() : '';
    if ($path === '' || !is_file($path)) {
        return function_exists('jeab_calendar_log_empty') ? jeab_calendar_log_empty() : ['entries' => [], 'backfilled' => false];
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return function_exists('jeab_calendar_log_empty') ? jeab_calendar_log_empty() : ['entries' => [], 'backfilled' => false];
    }

    return function_exists('jeab_calendar_log_normalize') ? jeab_calendar_log_normalize($data) : $data;
}

/** Dokumentet, utan händelserader. null när SQL saknar dokumentet. */
function jeab_calendar_log_sql_current(): ?array
{
    if (function_exists('jeab_log_doc_get')) {
        $data = jeab_log_doc_get('calendar_log');
        if (is_array($data)) {
            return function_exists('jeab_calendar_log_normalize') ? jeab_calendar_log_normalize($data) : $data;
        }
    }
    $pdo = function_exists('jeab_sql_store_pdo') ? jeab_sql_store_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_sql_doc_get')) {
        $data = jeab_sql_doc_get($pdo, 'calendar_log');
        if (is_array($data)) {
            return function_exists('jeab_calendar_log_normalize') ? jeab_calendar_log_normalize($data) : $data;
        }
    }

    return null;
}

function jeab_calendar_log_sql_checksum(array $doc): string
{
    $entries = array_values(is_array($doc['entries'] ?? null) ? $doc['entries'] : []);
    $json = json_encode(['entries' => $entries, 'backfilled' => !empty($doc['backfilled'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? hash('sha256', $json) : '';
}

function jeab_calendar_log_sql_put(array $payload): bool
{
    if (function_exists('jeab_log_calendar_save') && jeab_log_calendar_save($payload)) {
        return true;
    }
    $pdo = function_exists('jeab_sql_store_pdo') ? jeab_sql_store_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_sql_doc_put')) {
        jeab_sql_doc_put($pdo, 'calendar_log', $payload);

        return true;
    }
    $log = function_exists('jeab_log_pdo') ? jeab_log_pdo() : null;
    if ($log instanceof PDO && function_exists('jeab_log_doc_put_pdo')) {
        jeab_log_doc_put_pdo($log, 'calendar_log', $payload);

        return true;
    }

    return false;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_calendar_log_sql_resync(array $input): array
{
    $dry = jeab_calendar_log_sql_dry($input);
    $path = function_exists('jeab_calendar_log_path') ? jeab_calendar_log_path() : '';
    if (jeab_sql_json_source_missing($path, ['entries'])) {
        $sql = jeab_calendar_log_sql_current();

        return jeab_sql_json_source_skip($dry, 'calendar_log.sql_resync') + [
            'json_entries' => 0,
            'sql_entries' => is_array($sql) ? count($sql['entries'] ?? []) : 0,
        ];
    }
    if (array_key_exists('rollback', $input)) {
        $on = !($input['rollback'] === false || $input['rollback'] === 0 || $input['rollback'] === '0' || $input['rollback'] === 'false');
        if (!$dry && function_exists('jeab_json_sql_rollback_set')) {
            jeab_json_sql_rollback_set('calendar_log_json', $on);
        }

        return [
            'ok' => true,
            'dry' => $dry,
            'action' => 'calendar_log.sql_resync',
            'rollback' => $on,
            'would_rollback' => 1,
            'written' => !$dry,
            'file_unchanged' => true,
            'updated' => 0,
            'would' => 0,
        ];
    }
    $json = jeab_calendar_log_sql_json_doc();
    $sql = jeab_calendar_log_sql_current();
    $jsonEntries = count($json['entries'] ?? []);
    $sqlEntries = is_array($sql) ? count($sql['entries'] ?? []) : 0;
    $differ = !is_array($sql) || jeab_calendar_log_sql_checksum($json) !== jeab_calendar_log_sql_checksum($sql);
    $updated = 0;
    if (!$dry && $differ) {
        if (!jeab_calendar_log_sql_put($json)) {
            return ['ok' => false, 'dry' => false, 'error' => 'Ingen SQL-databas.', 'file_unchanged' => true];
        }
        $updated = 1;
    }

    return [
        'ok' => true,
        'dry' => $dry,
        'action' => 'calendar_log.sql_resync',
        'json_entries' => $jsonEntries,
        'sql_entries' => $sqlEntries,
        'would' => $differ ? 1 : 0,
        'updated' => $updated,
        'written' => $updated === 1,
        'file_unchanged' => true,
        'rollback' => function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('calendar_log_json'),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_calendar_log_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_calendar_log_sql_resync($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}
