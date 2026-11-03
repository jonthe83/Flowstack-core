<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';
require_once __DIR__ . '/biz_doc_store.php';

/**
 * Hookar: biz_doc.sql_migrate, biz_doc.sql_verify, biz_doc.sql_cutover (entity = namn eller all).
 */

/** SQLite-rollback. Live MySQL skriver inte JSON. */
function jeab_biz_doc_json_write(string $name, array $data): void
{
    $path = jeab_biz_doc_json_path($name);
    if ($path === '') {
        throw new RuntimeException('Okänt dokument.');
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara.');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($name === 'code_people' ? JSON_PRETTY_PRINT : 0));
    if (!is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara.');
    }
}

function jeab_biz_doc_sql_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @return list<string> */
function jeab_biz_doc_sql_entities(array $input): array
{
    $entity = strtolower(trim((string) ($input['entity'] ?? 'all')));
    if ($entity === 'all') {
        return jeab_biz_doc_names();
    }
    if (!in_array($entity, jeab_biz_doc_names(), true)) {
        return [];
    }
    return [$entity];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_biz_doc_sql_migrate(array $input): array
{
    $dry = jeab_biz_doc_sql_dry($input);
    $entities = jeab_biz_doc_sql_entities($input);
    if ($entities === []) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'entity ska vara all eller ett av: ' . implode(', ', jeab_biz_doc_names())];
    }
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.'];
    }
    jeab_biz_doc_store_ensure($pdo);
    $present = [];
    foreach ($entities as $name) {
        if (!jeab_sql_json_source_missing(jeab_biz_doc_json_path($name))) {
            $present[] = $name;
        }
    }
    if ($present === []) {
        return jeab_sql_json_source_skip($dry, 'biz_doc.sql_migrate') + [
            'entity' => (string) ($input['entity'] ?? 'all'),
        ];
    }
    $docs = [];
    foreach ($present as $name) {
        $json = jeab_biz_doc_json_read($name);
        $sqlBefore = jeab_biz_doc_sql_read($name);
        $onlyKeys = jeab_biz_doc_json_only_keys($json, $sqlBefore);
        if (!$dry && $onlyKeys !== []) {
            jeab_biz_doc_sql_write($name, jeab_biz_doc_merge_json_only($json, $sqlBefore));
        }
        $sql = jeab_biz_doc_sql_read($name);
        $docs[$name] = [
            'json_keys' => count($json),
            'sql_keys' => count($sql),
            'json_only' => count(jeab_biz_doc_json_only_keys($json, $sql)),
            'inserted_keys' => $dry ? count($onlyKeys) : count($onlyKeys),
            'storage' => jeab_biz_doc_storage_mode($name),
        ];
    }
    $allClear = true;
    foreach ($docs as $row) {
        if (($row['json_only'] ?? 1) > 0) {
            $allClear = false;
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry,
        'entity' => (string) ($input['entity'] ?? 'all'),
        'docs' => $docs,
        'json_only_cleared' => $dry ? false : $allClear,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_biz_doc_sql_verify(array $input): array
{
    $entities = jeab_biz_doc_sql_entities($input + ['entity' => $input['entity'] ?? 'all']);
    if ($entities === []) {
        return ['ok' => false, 'error' => 'Ogiltig entity.', 'dry' => true];
    }
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'dry' => true];
    }
    $docs = [];
    $match = true;
    foreach ($entities as $name) {
        $json = jeab_biz_doc_json_read($name);
        $sql = jeab_biz_doc_sql_read($name);
        $jsonOnly = jeab_biz_doc_json_only_keys($json, $sql);
        if ($jsonOnly !== []) {
            $match = false;
        }
        $docs[$name] = [
            'json_only' => count($jsonOnly),
            'json_keys' => count($json),
            'sql_keys' => count($sql),
            'storage' => jeab_biz_doc_storage_mode($name),
        ];
    }
    return [
        'ok' => true,
        'dry' => true,
        'match' => $match,
        'docs' => $docs,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_biz_doc_sql_cutover(array $input): array
{
    $dry = jeab_biz_doc_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $entities = jeab_biz_doc_sql_entities($input + ['entity' => $input['entity'] ?? 'all']);
    if ($entities === []) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ogiltig entity.'];
    }
    if ($enable === null) {
        $modes = [];
        foreach ($entities as $name) {
            $modes[$name] = jeab_biz_doc_storage_mode($name);
        }
        return ['ok' => true, 'dry' => $dry, 'storage' => $modes, 'changed' => false];
    }
    if ($enable) {
        $verify = jeab_biz_doc_sql_verify($input);
        if (empty($verify['ok'])) {
            return $verify + ['dry' => $dry];
        }
        foreach ($entities as $name) {
            $row = is_array($verify['docs'][$name] ?? null) ? $verify['docs'][$name] : [];
            $jsonOnly = (int) ($row['json_only'] ?? 0);
            $sqlKeys = (int) ($row['sql_keys'] ?? 0);
            $jsonKeys = (int) ($row['json_keys'] ?? 0);
            if ($jsonOnly > 0 || $sqlKeys < $jsonKeys) {
                return [
                    'ok' => false,
                    'dry' => $dry,
                    'error' => 'Cutover stoppad för ' . $name . ': json_only=' . $jsonOnly . ', sql_keys=' . $sqlKeys . ', json_keys=' . $jsonKeys . '.',
                    'verify' => $verify,
                ];
            }
        }
        if (jeab_biz_doc_mysql_live()) {
            $modes = [];
            foreach ($entities as $name) {
                $modes[$name] = 'sql';
            }
            return [
                'ok' => true,
                'dry' => $dry,
                'enable' => 1,
                'noop' => 'mysql_already_sql',
                'storage' => $modes,
                'changed' => false,
            ];
        }
        $modes = [];
        foreach ($entities as $name) {
            if (!$dry) {
                if (!jeab_biz_doc_set_storage($name, 'sql')) {
                    return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas för ' . $name];
                }
            }
            $modes[$name] = $dry ? jeab_biz_doc_storage_mode($name) : 'sql';
        }
        return ['ok' => true, 'dry' => $dry, 'enable' => 1, 'storage' => $modes, 'changed' => !$dry];
    }
    if (jeab_biz_doc_mysql_live()) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Live MySQL använder SQL utan flagga; rollback stöds inte.', 'storage' => 'sql'];
    }
    $modes = [];
    foreach ($entities as $name) {
        if (jeab_biz_doc_storage_mode($name) !== 'sql') {
            $modes[$name] = 'json';
            continue;
        }
        if (!$dry) {
            $sql = jeab_biz_doc_sql_read($name);
            jeab_biz_doc_json_write($name, $sql);
            if (!jeab_biz_doc_set_storage($name, 'json')) {
                return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte slås av för ' . $name];
            }
        }
        $modes[$name] = $dry ? 'sql' : 'json';
    }
    return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => $modes, 'changed' => !$dry];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_biz_doc_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'biz_doc.sql_verify') {
        $result = jeab_biz_doc_sql_verify($input);
    } elseif ($action === 'biz_doc.sql_cutover') {
        $result = jeab_biz_doc_sql_cutover($input);
    } else {
        $result = jeab_biz_doc_sql_migrate($input);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}
