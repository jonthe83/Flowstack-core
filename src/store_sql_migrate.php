<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';
require_once __DIR__ . '/store_sql_storage.php';

/**
 * Hookar: store.sql_migrate, store.sql_verify, store.sql_cutover.
 */

function jeab_store_sql_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_store_sql_migrate(array $input): array
{
    $dry = jeab_store_sql_dry($input);
    $storePath = function_exists('jeab_json_path') ? jeab_json_path() : '';
    if (jeab_sql_json_source_missing($storePath, ['customers', 'bookings', 'reminders'])) {
        return jeab_sql_json_source_skip($dry, 'store.sql_migrate');
    }
    $pdo = jeab_store_sql_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.'];
    }
    $json = jeab_store_json_snapshot_for_verify();
    $before = jeab_store_sql_counts($pdo);
    if (!$dry) {
        jeab_store_sql_import_json($pdo, true);
    }
    $sqlSnap = jeab_store_sql_snapshot($pdo);
    $jsonCounts = jeab_store_json_counts($json);
    $sqlCounts = jeab_store_sql_counts($pdo);
    $countMatch = $jsonCounts === $sqlCounts;
    $checksumMatch = $dry ? false : jeab_store_snapshot_checksum($json) === jeab_store_snapshot_checksum($sqlSnap);
    $sample = jeab_store_sql_sample($json, $sqlSnap, max(1, min(10, (int) ($input['sample'] ?? 3))));
    return [
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry,
        'json_counts' => $jsonCounts,
        'sql_counts' => $sqlCounts,
        'sql_before' => $before,
        'count_match' => $dry ? false : $countMatch,
        'checksum_match' => $checksumMatch,
        'samples_match' => $dry ? false : $sample['match'],
        'samples' => $sample['rows'],
        'storage' => jeab_store_storage_mode(),
        'driver' => jeab_driver(),
    ];
}

/**
 * @param array<string, mixed> $json
 * @param array<string, mixed> $sql
 * @return array{match:bool,rows:list<array{entity:string,id:int,match:bool}>}
 */
function jeab_store_sql_sample(array $json, array $sql, int $sampleN): array
{
    $ok = true;
    $rows = [];
    foreach (['reminders', 'bookings', 'customers'] as $entity) {
        $leftList = is_array($json[$entity] ?? null) ? $json[$entity] : [];
        $rightById = [];
        foreach (is_array($sql[$entity] ?? null) ? $sql[$entity] : [] as $row) {
            if (is_array($row)) {
                $rightById[(int) ($row['id'] ?? 0)] = $row;
            }
        }
        $picked = array_slice($leftList, 0, $sampleN);
        foreach ($picked as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $right = $rightById[$id] ?? null;
            $match = is_array($right)
                && jeab_store_snapshot_checksum([$entity => [$row]]) === jeab_store_snapshot_checksum([$entity => [$right]]);
            if (!$match) {
                $ok = false;
            }
            $rows[] = ['entity' => $entity, 'id' => $id, 'match' => $match];
        }
    }
    return ['match' => $ok, 'rows' => $rows];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_store_sql_verify(array $input): array
{
    $pdo = jeab_store_sql_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'dry' => true];
    }
    $json = jeab_store_json_snapshot_for_verify();
    $sqlSnap = jeab_store_sql_snapshot($pdo);
    $jsonCounts = jeab_store_json_counts($json);
    $sqlCounts = jeab_store_sql_counts($pdo);
    $countMatch = $jsonCounts === $sqlCounts;
    $checksumMatch = jeab_store_snapshot_checksum($json) === jeab_store_snapshot_checksum($sqlSnap);
    $sample = jeab_store_sql_sample($json, $sqlSnap, max(1, min(10, (int) ($input['sample'] ?? 5))));
    return [
        'ok' => true,
        'dry' => true,
        'match' => $countMatch && $checksumMatch && $sample['match'],
        'json_counts' => $jsonCounts,
        'sql_counts' => $sqlCounts,
        'count_match' => $countMatch,
        'checksum_match' => $checksumMatch,
        'samples_match' => $sample['match'],
        'samples' => $sample['rows'],
        'storage' => jeab_store_storage_mode(),
        'driver' => jeab_driver(),
    ];
}

/** @param array<string, mixed> $sqlSnap */
function jeab_store_write_json_from_sql(array $sqlSnap): void
{
    $path = jeab_json_path();
    $existing = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $base = is_array($existing) ? $existing : [
        'next_id' => [
            'reminder_lists' => 2,
            'customers' => 1,
            'bookings' => 1,
            'reminders' => 1,
        ],
        'reminder_lists' => [
            ['id' => 1, 'name' => 'Påminnelser', 'created_at' => jeab_now()],
        ],
        'settings' => [],
    ];
    $base['customers'] = $sqlSnap['customers'] ?? [];
    $base['bookings'] = $sqlSnap['bookings'] ?? [];
    $base['reminders'] = $sqlSnap['reminders'] ?? [];
    $json = json_encode($base, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte skriva store.json');
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_store_sql_cutover(array $input): array
{
    $dry = jeab_store_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $before = jeab_store_storage_mode();
    $driver = jeab_driver();
    if ($enable === null) {
        return ['ok' => true, 'dry' => $dry, 'storage' => $before, 'driver' => $driver, 'changed' => false];
    }
    if ($enable) {
        $verify = jeab_store_sql_verify($input);
        if (empty($verify['ok'])) {
            return $verify + ['dry' => $dry, 'storage' => $before];
        }
        if (empty($verify['match']) && empty($input['force'])) {
            return [
                'ok' => false,
                'dry' => $dry,
                'error' => 'Verifieringen matchar inte. Cutover är inte gjord.',
                'storage' => $before,
                'verify' => $verify,
            ];
        }
        if ($driver !== 'json') {
            return [
                'ok' => true,
                'dry' => $dry,
                'enable' => 1,
                'storage' => $before,
                'driver' => $driver,
                'changed' => false,
                'note' => 'driver är redan SQL — flaggan behövs bara vid driver=json',
            ];
        }
        if (!$dry && !jeab_store_set_storage('sql')) {
            return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas.', 'storage' => jeab_store_storage_mode()];
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'enable' => 1,
            'storage' => $dry ? $before : 'sql',
            'driver' => $driver,
            'changed' => !$dry && $before !== 'sql',
        ];
    }
    if ($driver !== 'json' || $before !== 'sql') {
        return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => $before, 'driver' => $driver, 'changed' => false, 'rollback' => 'not_applicable'];
    }
    $pdo = jeab_store_sql_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.', 'storage' => $before];
    }
    $sqlSnap = jeab_store_sql_snapshot($pdo);
    $jsonCounts = jeab_store_json_counts(jeab_store_json_snapshot());
    $sqlCounts = jeab_store_sql_counts($pdo);
    if ($sqlCounts['reminders'] === 0 && $jsonCounts['reminders'] > 0 && empty($input['force'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'SQL saknar påminnelser. JSON skrevs inte över.', 'storage' => $before];
    }
    if (!$dry) {
        jeab_store_write_json_from_sql($sqlSnap);
        if (!jeab_store_set_storage('json')) {
            return ['ok' => false, 'dry' => false, 'error' => 'JSON skrevs men flaggan kunde inte slås av.', 'storage' => jeab_store_storage_mode()];
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'enable' => 0,
        'storage' => $dry ? 'sql' : 'json',
        'driver' => $driver,
        'changed' => !$dry,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_store_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'store.sql_verify') {
        $result = jeab_store_sql_verify($input);
    } elseif ($action === 'store.sql_cutover') {
        $result = jeab_store_sql_cutover($input);
    } else {
        $result = jeab_store_sql_migrate($input);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}
