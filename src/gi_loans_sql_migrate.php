<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';
require_once __DIR__ . '/gi_loans_store.php';

/**
 * Hookar: gi_loans.sql_migrate, gi_loans.sql_verify, gi_loans.sql_cutover.
 */

/** SQLite-rollback. Live MySQL skriver inte JSON. */
function jeab_gi_loans_json_write(array $data): void
{
    $path = jeab_gi_loans_json_path();
    if ($path === '') {
        throw new RuntimeException('Kunde inte spara GI-lån.');
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara GI-lån.');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara GI-lån.');
    }
    @chmod($path, 0600);
    @chmod($dir, 0700);
}

function jeab_gi_loans_sql_dry(array $input): bool
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
function jeab_gi_loans_sql_migrate(array $input): array
{
    $dry = jeab_gi_loans_sql_dry($input);
    if (jeab_sql_json_source_missing(jeab_gi_loans_json_path(), ['loans'])) {
        return jeab_sql_json_source_skip($dry, 'gi_loans.sql_migrate') + [
            'json_rows' => 0,
            'sql_rows' => count(jeab_gi_loans_sql_read()['loans']),
        ];
    }
    $pdo = jeab_gi_loans_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.'];
    }
    jeab_gi_loans_store_ensure($pdo);
    $json = jeab_gi_loans_json_read();
    $before = count(jeab_gi_loans_sql_read()['loans']);
    if (!$dry) {
        jeab_gi_loans_sql_write($json);
    }
    $sql = jeab_gi_loans_sql_read();
    $countMatch = count($json['loans']) === count($sql['loans']);
    $checksumMatch = $dry ? false : jeab_gi_loans_canon($json) === jeab_gi_loans_canon($sql);
    $sample = jeab_gi_loans_sql_sample($json, $sql, max(1, min(20, (int) ($input['sample'] ?? 3))));
    return [
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry,
        'json_rows' => count($json['loans']),
        'sql_rows' => count($sql['loans']),
        'sql_before' => $before,
        'count_match' => $dry ? false : $countMatch,
        'checksum_match' => $checksumMatch,
        'samples_match' => $dry ? false : $sample['match'],
        'samples' => $sample['rows'],
        'storage' => jeab_gi_loans_storage_mode(),
    ];
}

/**
 * @param array{loans:list<array<string,mixed>>} $json
 * @param array{loans:list<array<string,mixed>>} $sql
 * @return array{match:bool,rows:list<array{id:string,match:bool}>}
 */
function jeab_gi_loans_sql_sample(array $json, array $sql, int $sampleN): array
{
    $byId = [];
    foreach ($sql['loans'] as $row) {
        if (is_array($row) && isset($row['id'])) {
            $byId[(string) $row['id']] = $row;
        }
    }
    $picked = array_slice($json['loans'], 0, $sampleN);
    $ok = true;
    $rows = [];
    foreach ($picked as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $right = $byId[$id] ?? null;
        $match = is_array($right) && jeab_gi_loans_canon(['loans' => [$row]]) === jeab_gi_loans_canon(['loans' => [$right]]);
        if (!$match) {
            $ok = false;
        }
        $rows[] = ['id' => $id, 'match' => $match];
    }
    return ['match' => $ok, 'rows' => $rows];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_gi_loans_sql_verify(array $input): array
{
    $sampleN = max(1, min(20, (int) ($input['sample'] ?? 5)));
    $pdo = jeab_gi_loans_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'dry' => true];
    }
    $json = jeab_gi_loans_json_read();
    $sql = jeab_gi_loans_sql_read();
    $countMatch = count($json['loans']) === count($sql['loans']);
    $checksumMatch = jeab_gi_loans_canon($json) === jeab_gi_loans_canon($sql);
    $sample = jeab_gi_loans_sql_sample($json, $sql, $sampleN);
    return [
        'ok' => true,
        'dry' => true,
        'match' => $countMatch && $checksumMatch && $sample['match'],
        'json_rows' => count($json['loans']),
        'sql_rows' => count($sql['loans']),
        'count_match' => $countMatch,
        'checksum_match' => $checksumMatch,
        'samples_match' => $sample['match'],
        'samples' => $sample['rows'],
        'storage' => jeab_gi_loans_storage_mode(),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_gi_loans_sql_cutover(array $input): array
{
    $dry = jeab_gi_loans_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $before = jeab_gi_loans_storage_mode();
    if ($enable === null) {
        return ['ok' => true, 'dry' => $dry, 'storage' => $before, 'changed' => false];
    }
    if ($enable) {
        if (jeab_gi_loans_mysql_live()) {
            return [
                'ok' => true,
                'dry' => $dry,
                'enable' => 1,
                'noop' => 'mysql_already_sql',
                'storage' => 'sql',
                'changed' => false,
            ];
        }
        $verify = jeab_gi_loans_sql_verify($input);
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
        if (!$dry && !jeab_gi_loans_set_storage('sql')) {
            return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas.', 'storage' => jeab_gi_loans_storage_mode()];
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'enable' => 1,
            'storage' => $dry ? $before : 'sql',
            'changed' => !$dry && $before !== 'sql',
        ];
    }
    if (jeab_gi_loans_mysql_live()) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Live MySQL använder SQL utan flagga; rollback stöds inte.', 'storage' => 'sql'];
    }
    if ($before !== 'sql') {
        return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => 'json', 'changed' => false, 'rollback' => 'already_json'];
    }
    $sqlRows = jeab_gi_loans_sql_read();
    if (count($sqlRows['loans']) === 0 && count(jeab_gi_loans_json_read()['loans']) > 0 && empty($input['force'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'SQL är tom. JSON skrevs inte över.', 'storage' => $before];
    }
    if (!$dry) {
        jeab_gi_loans_json_write($sqlRows);
        if (!jeab_gi_loans_set_storage('json')) {
            return ['ok' => false, 'dry' => false, 'error' => 'JSON skrevs men flaggan kunde inte slås av.', 'storage' => jeab_gi_loans_storage_mode()];
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'enable' => 0,
        'storage' => $dry ? 'sql' : 'json',
        'changed' => !$dry,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_gi_loans_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'gi_loans.sql_verify') {
        $result = jeab_gi_loans_sql_verify($input);
    } elseif ($action === 'gi_loans.sql_cutover') {
        $result = jeab_gi_loans_sql_cutover($input);
    } else {
        $result = jeab_gi_loans_sql_migrate($input);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}
