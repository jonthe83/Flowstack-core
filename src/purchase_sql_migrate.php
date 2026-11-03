<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';
require_once __DIR__ . '/purchase_store.php';

/**
 * Hookar: purchases.sql_migrate, purchases.sql_verify, purchases.sql_cutover.
 * De är avstängda. SQL är enda källan och JSON-filen läses inte.
 */

/** @return array{ok:false,error:string,dry:bool} */
function jeab_purchases_sql_retired(): array
{
    return ['ok' => false, 'error' => 'retired: SQL is sole source', 'dry' => true];
}

function jeab_purchases_sql_dry(array $input): bool
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
function jeab_purchases_sql_migrate(array $input): array
{
    $path = function_exists('jeab_purchase_path') ? jeab_purchase_path() : '';
    if ($path !== '' && jeab_sql_json_source_missing($path)) {
        return jeab_sql_json_source_skip(jeab_purchases_sql_dry($input), 'purchases.sql_migrate');
    }
    unset($input);

    return jeab_purchases_sql_retired();
    $dry = jeab_purchases_sql_dry($input);
    $pdo = jeab_purchases_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.'];
    }
    jeab_purchases_store_ensure($pdo);
    if (!function_exists('jeab_purchase_json_read') || !function_exists('jeab_sql_purchases_load')) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'purchase.php saknas.'];
    }
    $json = jeab_purchase_json_read();
    $sqlBefore = jeab_sql_purchases_load($pdo);
    $before = count($sqlBefore);
    $jsonOnly = jeab_purchases_json_only_ids($json, $sqlBefore);
    $inserted = 0;
    if (!$dry) {
        foreach ($jsonOnly as $id) {
            $row = $json[$id] ?? null;
            if (!is_array($row)) {
                continue;
            }
            jeab_sql_purchases_put($pdo, $row);
            $inserted++;
        }
    }
    $sql = jeab_sql_purchases_load($pdo);
    $overlapOk = true;
    foreach ($json as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ((int) ($row['reminder_id'] ?? $key));
        if ($id === '0' || !isset($sql[$id])) {
            continue;
        }
        if (jeab_purchases_sql_checksum([$id => $row]) !== jeab_purchases_sql_checksum([$id => $sql[$id]])) {
            $overlapOk = false;
            break;
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry && $inserted > 0,
        'inserted' => $dry ? count($jsonOnly) : $inserted,
        'skipped_sql' => count($json) - count($jsonOnly),
        'json_only' => count($jsonOnly),
        'json_rows' => count($json),
        'sql_rows' => count($sql),
        'sql_before' => $before,
        'json_only_remaining' => $dry ? count($jsonOnly) : count(jeab_purchases_json_only_ids($json, $sql)),
        'overlap_match' => $overlapOk,
        'mysql_live' => jeab_purchases_mysql_live(),
        'storage' => jeab_purchases_storage_mode(),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_purchases_sql_verify(array $input): array
{
    unset($input);

    return jeab_purchases_sql_retired();
    $sampleN = max(1, min(50, (int) ($input['sample'] ?? 5)));
    $pdo = jeab_purchases_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'dry' => true];
    }
    jeab_purchases_store_ensure($pdo);
    $json = jeab_purchase_json_read();
    $sql = jeab_sql_purchases_load($pdo);
    $jsonOnly = jeab_purchases_json_only_ids($json, $sql);
    $sample = jeab_purchases_sql_sample($json, $sql, $sampleN);
    $jsonChecksum = jeab_purchases_sql_checksum($json);
    $sqlChecksum = jeab_purchases_sql_checksum($sql);
    $overlapOk = true;
    foreach ($json as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ((int) ($row['reminder_id'] ?? $key));
        if ($id === '0' || !isset($sql[$id])) {
            continue;
        }
        if (jeab_purchases_sql_checksum([$id => $row]) !== jeab_purchases_sql_checksum([$id => $sql[$id]])) {
            $overlapOk = false;
            break;
        }
    }
    return [
        'ok' => true,
        'dry' => true,
        'match' => $jsonOnly === [] && $overlapOk && $sample['match'],
        'json_rows' => count($json),
        'sql_rows' => count($sql),
        'json_only' => count($jsonOnly),
        'overlap_match' => $overlapOk,
        'json_checksum' => $jsonChecksum,
        'sql_checksum' => $sqlChecksum,
        'checksum_match' => $jsonChecksum !== '' && $jsonChecksum === $sqlChecksum,
        'samples_match' => $sample['match'],
        'samples' => $sample['rows'],
        'mysql_live' => jeab_purchases_mysql_live(),
        'storage' => jeab_purchases_storage_mode(),
    ];
}

/** @param array<string, array<string, mixed>> $rows */
function jeab_purchases_sql_write_json(array $rows): void
{
    unset($rows);
    throw new RuntimeException('retired: SQL is sole source');
    if (!function_exists('jeab_purchase_path')) {
        throw new RuntimeException('Kunde inte skriva purchases.json');
    }
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Kunde inte skriva purchases.json');
    }
    $path = jeab_purchase_path();
    if (is_file($path)) {
        @copy($path, $path . '.prev');
    }
    $tmp = $path . '.rollback.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte skriva purchases.json');
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_purchases_sql_cutover(array $input): array
{
    unset($input);

    return jeab_purchases_sql_retired();
    $dry = jeab_purchases_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $before = jeab_purchases_storage_mode();
    if ($enable === null) {
        return ['ok' => true, 'dry' => $dry, 'storage' => $before, 'changed' => false];
    }
    if ($enable) {
        if (jeab_purchases_mysql_live()) {
            return [
                'ok' => true,
                'dry' => $dry,
                'enable' => 1,
                'noop' => 'mysql_already_sql',
                'storage' => 'sql',
                'changed' => false,
            ];
        }
        $verify = jeab_purchases_sql_verify($input);
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
        if (!$dry && !jeab_purchases_sql_set_storage('sql')) {
            return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas.', 'storage' => jeab_purchases_storage_mode()];
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'enable' => 1,
            'storage' => $dry ? $before : 'sql',
            'would_storage' => 'sql',
            'changed' => !$dry && $before !== 'sql',
        ];
    }
    $pdo = jeab_purchases_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.', 'storage' => $before];
    }
    $sqlRows = jeab_sql_purchases_load($pdo);
    $jsonRows = jeab_purchase_json_read();
    if (jeab_purchases_mysql_live()) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Live MySQL använder SQL utan flagga; rollback stöds inte.', 'storage' => 'sql'];
    }
    if ($before !== 'sql') {
        return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => 'json', 'changed' => false, 'rollback' => 'already_json'];
    }
    if (count($sqlRows) === 0 && count($jsonRows) > 0 && empty($input['force'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'SQL är tom. JSON skrevs inte över.', 'storage' => $before];
    }
    if (!$dry) {
        jeab_purchases_sql_write_json($sqlRows);
        if (!jeab_purchases_sql_set_storage('json')) {
            return ['ok' => false, 'dry' => false, 'error' => 'JSON skrevs men flaggan kunde inte slås av.', 'storage' => jeab_purchases_storage_mode()];
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'enable' => 0,
        'rollback' => 'json',
        'storage' => $dry ? $before : 'json',
        'would_storage' => 'json',
        'sql_rows' => count($sqlRows),
        'changed' => !$dry,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_purchases_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'purchases.sql_verify') {
        $result = jeab_purchases_sql_verify($input);
    } elseif ($action === 'purchases.sql_cutover') {
        $result = jeab_purchases_sql_cutover($input);
    } else {
        $result = jeab_purchases_sql_migrate($input);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}
