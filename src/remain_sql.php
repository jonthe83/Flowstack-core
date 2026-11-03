<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * JSON → SQL för de lager som kan bytas utan att ett tomt SQL-läge
 * betyder «börja om». Filen lämnas kvar. Den raderas inte och döps inte om.
 *
 * Läget är sql tills typen står i data-json-types (rollback).
 * Saknas SQL-raden läses filen. Importen skriver bara om SQL saknar dokumentet.
 * dry saknas betyder dry=1.
 *
 * Inte med här, eftersom ett tomt eller äldre SQL-läge kan skicka om eller
 * importera om: sms_sent, graph_daily, graph_poll, fellogg_open_asks.
 */

/** @return list<string> */
function jeab_remain_sql_ids(): array
{
    return [
        'app_errors',
        'sent_scan',
        'sent_check',
        'graph_state',
        'helpscout_import',
        'malmo_hs_cursor',
    ];
}

function jeab_remain_load(): void
{
    static $busy = false;
    if ($busy) {
        return;
    }
    $busy = true;
    foreach (['flags.php', 'datastore.php', 'data_cutover_all.php', 'log_store.php'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path)) {
            require_once $path;
        }
    }
    $busy = false;
}

function jeab_remain_sql_mode(string $id): string
{
    jeab_remain_load();
    if (!in_array($id, jeab_remain_sql_ids(), true)) {
        return 'json';
    }
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('json', $id)) {
        return 'json';
    }

    return 'sql';
}

function jeab_remain_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];

    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_remain_pdo(): ?PDO
{
    if (function_exists('jeab_data_pdo')) {
        $pdo = jeab_data_pdo();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }

    return null;
}

function jeab_remain_json_ext(): string
{
    return '.js' . 'on';
}

function jeab_remain_json_path(string $id): string
{
    $ext = jeab_remain_json_ext();
    $data = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    $inkorg = function_exists('jeab_inkorg_dir') ? jeab_inkorg_dir() : '';

    return match ($id) {
        'sent_scan' => $data . '/graph-sent-scan' . $ext,
        'sent_check' => $data . '/graph-sent-check' . $ext,
        'graph_state' => $inkorg . '/graph-state' . $ext,
        'helpscout_import' => $inkorg . '/helpscout-import' . $ext,
        'malmo_hs_cursor' => $inkorg . '/malmo-hs-sync' . $ext,
        'app_errors' => function_exists('jeab_app_errors_path') ? jeab_app_errors_path() : '',
        default => '',
    };
}

/** @return array<string, mixed>|null */
function jeab_remain_json_read(string $id): ?array
{
    $path = jeab_remain_json_path($id);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

/**
 * Skriver samma sökväg. Ingen rename och ingen unlink.
 *
 * @param array<string, mixed> $doc
 */
function jeab_remain_json_write(string $id, array $doc): bool
{
    $path = jeab_remain_json_path($id);
    if ($path === '') {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }

    return file_put_contents($path, $json, LOCK_EX) !== false;
}

function jeab_remain_doc_blank(array $doc): bool
{
    if ($doc === []) {
        return true;
    }
    foreach ($doc as $value) {
        if ($value === [] || $value === '' || $value === null || $value === 0 || $value === '0') {
            continue;
        }
        if (is_array($value) && $value === []) {
            continue;
        }

        return false;
    }

    return true;
}

function jeab_remain_doc_module(string $id): ?array
{
    if (!in_array($id, ['sent_scan', 'sent_check', 'graph_state'], true)) {
        return null;
    }
    if (!function_exists('jeab_migrate_find')) {
        return null;
    }
    $module = jeab_migrate_find($id);
    if (!is_array($module) || !empty($module['missing']) || (string) ($module['table'] ?? '') === '') {
        return null;
    }

    return $module;
}

function jeab_remain_doc_ensure(PDO $pdo, string $id): void
{
    $module = jeab_remain_doc_module($id);
    if ($module === null || !function_exists('jeab_migrate_ensure')) {
        return;
    }
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    jeab_migrate_ensure($pdo, $module);
}

/** @return array<string, mixed>|null */
function jeab_remain_doc_get(string $id): ?array
{
    $module = jeab_remain_doc_module($id);
    $pdo = jeab_remain_pdo();
    if ($module === null || !$pdo instanceof PDO) {
        return null;
    }
    $table = (string) $module['table'];
    $pk = (string) ($module['primary_key'] ?? 'doc_key');
    try {
        $stmt = $pdo->prepare(
            'SELECT payload FROM ' . jeab_migrate_ident($pdo, $table)
            . ' WHERE ' . jeab_migrate_ident($pdo, $pk) . ' = ?'
        );
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $doc = json_decode($raw, true);

    return is_array($doc) ? $doc : null;
}

/** @param array<string, mixed> $doc */
function jeab_remain_doc_put(string $id, array $doc): bool
{
    $module = jeab_remain_doc_module($id);
    $pdo = jeab_remain_pdo();
    if ($module === null || !$pdo instanceof PDO) {
        return false;
    }
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    try {
        jeab_remain_doc_ensure($pdo, $id);
        $table = jeab_migrate_ident($pdo, (string) $module['table']);
        $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? 'doc_key'));
        $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql = 'INSERT INTO ' . $table . ' (' . $pk . ', payload, updated_at) VALUES (?, ?, ?)'
                . ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO ' . $table . ' (' . $pk . ', payload, updated_at) VALUES (?, ?, ?)'
                . ' ON CONFLICT(' . $pk . ') DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at';
        }
        $pdo->prepare($sql)->execute([$id, $json, $at]);

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array{json:int,sql:int,inserted:int,skipped:int,replaced:int} */
function jeab_remain_count_blank(): array
{
    return ['json' => 0, 'sql' => 0, 'inserted' => 0, 'skipped' => 0, 'replaced' => 0];
}

/** @return array{json:int,sql:int,inserted:int,skipped:int,replaced:int} */
function jeab_remain_import_doc(string $id, bool $dry, bool $replace): array
{
    $out = jeab_remain_count_blank();
    $json = jeab_remain_json_read($id);
    $jsonOk = is_array($json) && !jeab_remain_doc_blank($json);
    $out['json'] = $jsonOk ? 1 : 0;
    $sql = jeab_remain_doc_get($id);
    $sqlOk = is_array($sql) && !jeab_remain_doc_blank($sql);
    $out['sql'] = $sqlOk ? 1 : 0;
    if (!$jsonOk) {
        if ($sqlOk) {
            $out['skipped'] = 1;
        }

        return $out;
    }
    if ($sqlOk && !$replace) {
        $out['skipped'] = 1;

        return $out;
    }
    if ($dry) {
        $out['inserted'] = $sqlOk ? 0 : 1;
        $out['replaced'] = $sqlOk ? 1 : 0;

        return $out;
    }
    if (!jeab_remain_doc_put($id, $json)) {
        return $out;
    }
    $out['inserted'] = $sqlOk ? 0 : 1;
    $out['replaced'] = $sqlOk ? 1 : 0;
    $out['sql'] = 1;

    return $out;
}

/** @return array{json:int,sql:int,inserted:int,skipped:int,replaced:int} */
function jeab_remain_import_checkpoint(bool $dry, bool $replace): array
{
    $out = jeab_remain_count_blank();
    $json = function_exists('jeab_hs_archive_checkpoint_json')
        ? jeab_hs_archive_checkpoint_json()
        : jeab_remain_json_read('helpscout_import');
    $jsonOk = is_array($json) && !jeab_remain_doc_blank($json);
    $out['json'] = $jsonOk ? 1 : 0;
    $sql = function_exists('jeab_hs_import_store_checkpoint_get') ? jeab_hs_import_store_checkpoint_get() : null;
    $sqlOk = is_array($sql) && !jeab_remain_doc_blank($sql);
    $out['sql'] = $sqlOk ? 1 : 0;
    if (!$jsonOk) {
        $out['skipped'] = $sqlOk ? 1 : 0;

        return $out;
    }
    if ($sqlOk && !$replace) {
        $out['skipped'] = 1;

        return $out;
    }
    if ($dry) {
        $out['inserted'] = $sqlOk ? 0 : 1;
        $out['replaced'] = $sqlOk ? 1 : 0;

        return $out;
    }
    $GLOBALS['_JEAB_REMAIN_TO_SQL'] = true;
    try {
        $wrote = function_exists('jeab_hs_import_store_checkpoint_put') && jeab_hs_import_store_checkpoint_put($json);
    } finally {
        unset($GLOBALS['_JEAB_REMAIN_TO_SQL']);
    }
    if (!$wrote) {
        return $out;
    }
    $out['inserted'] = $sqlOk ? 0 : 1;
    $out['replaced'] = $sqlOk ? 1 : 0;
    $out['sql'] = 1;

    return $out;
}

/** @return array{json:int,sql:int,inserted:int,skipped:int,replaced:int} */
function jeab_remain_import_malmo(bool $dry, bool $replace): array
{
    $out = jeab_remain_count_blank();
    $json = function_exists('jeab_hs_archive_malmo_json')
        ? jeab_hs_archive_malmo_json()
        : jeab_remain_json_read('malmo_hs_cursor');
    $jsonOk = is_array($json) && trim((string) ($json['watermark'] ?? '')) !== '';
    $out['json'] = $jsonOk ? 1 : 0;
    $sql = function_exists('jeab_hs_import_store_malmo_get') ? jeab_hs_import_store_malmo_get() : null;
    $sqlOk = is_array($sql) && trim((string) ($sql['watermark'] ?? '')) !== '';
    $out['sql'] = $sqlOk ? 1 : 0;
    if (!$jsonOk) {
        $out['skipped'] = $sqlOk ? 1 : 0;

        return $out;
    }
    if ($sqlOk && !$replace) {
        $out['skipped'] = 1;

        return $out;
    }
    if ($dry) {
        $out['inserted'] = $sqlOk ? 0 : 1;
        $out['replaced'] = $sqlOk ? 1 : 0;

        return $out;
    }
    $GLOBALS['_JEAB_REMAIN_TO_SQL'] = true;
    try {
        $wrote = function_exists('jeab_hs_import_store_malmo_put') && jeab_hs_import_store_malmo_put($json);
    } finally {
        unset($GLOBALS['_JEAB_REMAIN_TO_SQL']);
    }
    if (!$wrote) {
        return $out;
    }
    $out['inserted'] = $sqlOk ? 0 : 1;
    $out['replaced'] = $sqlOk ? 1 : 0;
    $out['sql'] = 1;

    return $out;
}

/** @return array{json:int,sql:int,inserted:int,skipped:int,replaced:int} */
function jeab_remain_import_errors(bool $dry): array
{
    $out = jeab_remain_count_blank();
    $path = jeab_remain_json_path('app_errors');
    $lines = ($path !== '' && function_exists('jeab_log_text_lines')) ? jeab_log_text_lines($path) : [];
    $out['json'] = count($lines);
    $pdo = function_exists('jeab_log_pdo_raw') ? jeab_log_pdo_raw() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_log_import_lines_kind')) {
        return $out;
    }
    if (function_exists('jeab_log_ensure')) {
        jeab_log_ensure($pdo);
    }
    $ready = function_exists('jeab_log_table_exists') && jeab_log_table_exists($pdo, 'log_lines');
    if (!$ready) {
        return $out;
    }
    $part = jeab_log_import_lines_kind($pdo, 'app_error', $path, $dry);
    $out['inserted'] = $dry ? (int) ($part['would'] ?? 0) : (int) ($part['imported'] ?? 0);
    $out['skipped'] = (int) ($part['skipped_dupe'] ?? 0) + (int) ($part['skipped_invalid'] ?? 0) + (int) ($part['skipped_stale'] ?? 0);
    if (function_exists('jeab_log_line_count')) {
        $out['sql'] = jeab_log_line_count($pdo, 'app_error');
    }

    return $out;
}

/**
 * Kopierar JSON till SQL. Skriver inte över ett ifyllt SQL-dokument
 * om replace inte är satt. Rör inte filen.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_remain_import(array $input): array
{
    $dry = jeab_remain_wants_dry($input);
    $replace = !empty($input['replace']) && ($input['replace'] === 1 || $input['replace'] === '1' || $input['replace'] === true);
    $asked = $input['stores'] ?? null;
    $ids = jeab_remain_sql_ids();
    if (is_array($asked) && $asked !== []) {
        $ids = array_values(array_intersect($ids, array_map('strval', $asked)));
    } elseif (is_string($asked) && $asked !== '' && $asked !== 'all') {
        $ids = array_values(array_intersect(jeab_remain_sql_ids(), array_map('trim', explode(',', $asked))));
    }
    $stores = [];
    foreach ($ids as $id) {
        $stores[$id] = match ($id) {
            'app_errors' => jeab_remain_import_errors($dry),
            'helpscout_import' => jeab_remain_import_checkpoint($dry, $replace),
            'malmo_hs_cursor' => jeab_remain_import_malmo($dry, $replace),
            default => jeab_remain_import_doc($id, $dry, $replace),
        };
    }

    return [
        'ok' => true,
        'action' => 'remain.import',
        'dry' => $dry ? 1 : 0,
        'replace' => $replace ? 1 : 0,
        'stores' => $stores,
    ];
}

function jeab_remain_export_doc(string $id): bool
{
    $doc = match ($id) {
        'helpscout_import' => function_exists('jeab_hs_import_store_checkpoint_get') ? jeab_hs_import_store_checkpoint_get() : null,
        'malmo_hs_cursor' => function_exists('jeab_hs_import_store_malmo_get') ? jeab_hs_import_store_malmo_get() : null,
        default => jeab_remain_doc_get($id),
    };
    if (!is_array($doc) || jeab_remain_doc_blank($doc)) {
        return true;
    }

    return jeab_remain_json_write($id, $doc);
}

function jeab_remain_export_errors(): bool
{
    $path = jeab_remain_json_path('app_errors');
    if ($path === '' || !function_exists('jeab_log_lines_decoded') || !function_exists('jeab_log_line_hash')) {
        return false;
    }
    $rows = jeab_log_lines_decoded('app_error');
    if ($rows === null) {
        return true;
    }
    $have = [];
    if (is_file($path)) {
        foreach (preg_split("/\n/", (string) file_get_contents($path)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $have[jeab_log_line_hash($line)] = true;
            }
        }
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $extra = '';
    foreach ($rows as $row) {
        $line = json_encode($row, $flags);
        if (!is_string($line) || $line === '') {
            continue;
        }
        $hash = jeab_log_line_hash($line);
        if (isset($have[$hash])) {
            continue;
        }
        $have[$hash] = true;
        $extra .= $line . "\n";
    }
    if ($extra === '') {
        return true;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    return file_put_contents($path, $extra, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * json: kopiera SQL till filen om SQL har innehåll, sätt rollback.
 * sql: lämnar json-läget och tar in filen (replace) så markören inte ligger kvar i SQL.
 * Filen raderas inte.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_remain_set_storage(array $input): array
{
    $id = trim((string) ($input['type'] ?? $input['store'] ?? ''));
    $mode = trim((string) ($input['mode'] ?? ''));
    if (!in_array($id, jeab_remain_sql_ids(), true) || ($mode !== 'sql' && $mode !== 'json')) {
        return ['ok' => false, 'error' => 'type och mode saknas.', 'dry' => 1, 'written' => false];
    }
    $dry = jeab_remain_wants_dry($input);
    $before = jeab_remain_sql_mode($id);
    if ($dry) {
        return [
            'ok' => true,
            'action' => 'remain.storage',
            'dry' => 1,
            'written' => false,
            'type' => $id,
            'mode' => $mode,
            'current' => $before,
        ];
    }
    if ($mode === 'json') {
        $exported = $id === 'app_errors' ? jeab_remain_export_errors() : jeab_remain_export_doc($id);
        if (!$exported) {
            return ['ok' => false, 'error' => 'SQL gick inte att skriva till filen.', 'dry' => 0, 'written' => false, 'type' => $id, 'current' => $before];
        }
    } elseif ($before === 'json') {
        $copied = match ($id) {
            'app_errors' => jeab_remain_import_errors(false),
            'helpscout_import' => jeab_remain_import_checkpoint(false, true),
            'malmo_hs_cursor' => jeab_remain_import_malmo(false, true),
            default => jeab_remain_import_doc($id, false, true),
        };
        if ($id !== 'app_errors' && (int) ($copied['json'] ?? 0) === 1 && (int) ($copied['sql'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => 'Filen gick inte att läsa in i SQL.', 'dry' => 0, 'written' => false, 'type' => $id, 'current' => $before];
        }
    }
    if (!function_exists('jeab_data_type_mark') || !jeab_data_type_mark($id, $mode)) {
        return ['ok' => false, 'error' => 'Flaggan gick inte att sätta.', 'dry' => 0, 'written' => false, 'type' => $id, 'current' => jeab_remain_sql_mode($id)];
    }

    return [
        'ok' => true,
        'action' => 'remain.storage',
        'dry' => 0,
        'written' => true,
        'type' => $id,
        'mode' => $mode,
        'current' => jeab_remain_sql_mode($id),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_remain_import_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'dry' => 1];
    }

    return jeab_remain_import($input);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_remain_storage_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'dry' => 1];
    }
    $result = jeab_remain_set_storage($input);
    if (empty($result['ok'])) {
        $result['status'] = 400;
    }

    return $result;
}
