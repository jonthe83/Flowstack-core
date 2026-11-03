<?php

declare(strict_types=1);

require_once __DIR__ . '/../shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/canon.php';

/**
 * Samma yta som spår B: jeab_migrate_<id>_module(), _rows() och _checksum().
 * jeab_migrate_module() i canon.php bygger beskrivningen. Den här filen slår upp id.
 * Saknad fil stoppar data.migrate_all innan flaggan slås på.
 *
 * @return array<string, string>
 */
function jeab_migrate_order(): array
{
    return [
        'contacts' => 'A',
        'inkorg_contacts' => 'A',
        'customers' => 'B',
        'reminder_lists' => 'B',
        'bookings' => 'B',
        'reminders' => 'B',
        'purchases' => 'B',
        'ordrar_deleted' => 'B',
        'webshop_orders' => 'B',
        'calendar_log' => 'B',
        'inbox' => 'C',
        'inbox_threads' => 'C',
        'gi_loans' => 'C',
        'biz_docs' => 'C',
        'notes' => 'C',
        'notes_state' => 'C',
        'sms_sent' => 'C',
        'who_diag' => 'C',
        'reminder_follow' => 'C',
        'reminder_hook' => 'C',
        'app_errors' => 'C',
        'who_picks' => 'C',
        'graph_week' => 'C',
        'graph_events' => 'C',
        'graph_state' => 'C',
        'graph_daily' => 'C',
        'graph_poll' => 'C',
        'sent_scan' => 'C',
        'sent_check' => 'C',
        'quote_queue' => 'C',
        'classify_pending' => 'C',
        'fellogg_open_asks' => 'C',
        'external_queue' => 'C',
        'notiser' => 'C',
        'auto_send_cap' => 'C',
        'malmo_offer_sends' => 'C',
        'quote_preview' => 'C',
        'hs_uncertain_open' => 'C',
        'rebook_log' => 'C',
        'corrections' => 'C',
        'fullmakt' => 'C',
        'omtago_leads' => 'C',
        'inbox_sent' => 'C',
        'inbox_sent_new' => 'C',
        'helpscout_import' => 'C',
        'malmo_hs_cursor' => 'C',
        'login_throttle' => 'C',
        'solkoll' => 'C',
        'app_errors_webhook' => 'C',
        'inkop_mallar' => 'C',
        'inkorg_views' => 'C',
        'inkorg_settings' => 'C',
        'inkorg_rules' => 'C',
    ];
}

/** @return list<array<string, mixed>> */
function jeab_migrate_registry(): array
{
    if (isset($GLOBALS['_JEAB_MIGRATE_REGISTRY']) && is_array($GLOBALS['_JEAB_MIGRATE_REGISTRY'])) {
        return $GLOBALS['_JEAB_MIGRATE_REGISTRY'];
    }
    $out = [];
    foreach (jeab_migrate_order() as $id => $group) {
        $out[] = jeab_migrate_load($id, $group);
    }
    $GLOBALS['_JEAB_MIGRATE_REGISTRY'] = $out;
    return $out;
}

/** @return array<string, mixed>|null */
function jeab_migrate_find(string $id): ?array
{
    if ($id === 'orders') {
        $id = 'purchases';
    }
    foreach (jeab_migrate_registry() as $module) {
        if ((string) ($module['id'] ?? '') === $id) {
            return $module;
        }
    }
    return null;
}

/** @return array<string, mixed> */
function jeab_migrate_load(string $id, string $group): array
{
    $file = __DIR__ . '/' . $id . '.php';
    $fn = 'jeab_migrate_' . $id . '_module';
    $blank = [
        'id' => $id,
        'type' => $id,
        'group' => $group,
        'title' => $id,
        'table' => '',
        'tables' => [],
        'json' => [],
        'primary_key' => '',
        'columns' => [],
        'depends' => [],
        'schema' => [],
        'rows' => '',
        'checksum' => '',
        'authoritative_sql' => '',
        'migrate' => '',
        'verify' => '',
        'read' => '',
        'write' => '',
        'missing' => true,
        'error' => 'Modulen saknas.',
    ];
    if (!is_file($file)) {
        return $blank;
    }
    require_once $file;
    if (!function_exists($fn)) {
        $blank['error'] = 'Modulen saknar ' . $fn . '.';
        return $blank;
    }
    $module = $fn();
    if (!is_array($module)) {
        $blank['error'] = $fn . ' svarade fel.';
        return $blank;
    }
    $rowsFn = 'jeab_migrate_' . $id . '_rows';
    $sumFn = 'jeab_migrate_' . $id . '_checksum';
    $authFn = 'jeab_migrate_' . $id . '_authoritative_sql';
    $readFn = 'jeab_migrate_' . $id . '_read';
    $writeFn = 'jeab_migrate_' . $id . '_write';
    $table = (string) ($module['table'] ?? '');
    $tables = $module['tables'] ?? ($table !== '' ? [$table] : []);
    if (!is_array($tables)) {
        $tables = [];
    }
    $json = $module['json'] ?? [];
    if (is_string($json)) {
        $json = $json === '' ? [] : [$json];
    }
    if (!is_array($json)) {
        $json = [];
    }
    $declared = is_string($module['authoritative_sql'] ?? null) ? (string) $module['authoritative_sql'] : '';
    if ($declared === '' && function_exists($authFn)) {
        $declared = $authFn;
    }
    return [
        'id' => $id,
        'type' => $id,
        'group' => (string) ($module['group'] ?? $group),
        'title' => (string) ($module['title'] ?? $id),
        'table' => $table !== '' ? $table : (string) ($tables[0] ?? ''),
        'tables' => array_values(array_map('strval', $tables)),
        'json' => array_values(array_map('strval', $json)),
        'primary_key' => (string) ($module['primary_key'] ?? ''),
        'columns' => array_values(is_array($module['columns'] ?? null) ? $module['columns'] : []),
        'depends' => array_values(is_array($module['depends'] ?? null) ? $module['depends'] : []),
        'schema' => is_array($module['schema'] ?? null) ? $module['schema'] : [],
        'rows' => function_exists($rowsFn) ? $rowsFn : '',
        'checksum' => function_exists($sumFn) ? $sumFn : '',
        'authoritative_sql' => $declared,
        'migrate' => '',
        'verify' => '',
        'read' => function_exists($readFn) ? $readFn : (is_string($module['read'] ?? null) ? (string) $module['read'] : ''),
        'write' => function_exists($writeFn) ? $writeFn : (is_string($module['write'] ?? null) ? (string) $module['write'] : ''),
        'missing' => false,
        'error' => '',
    ];
}

/**
 * Kopierar en modul som bara har rows() och checksum().
 *
 * @param array<string, mixed> $module
 * @return array<string, mixed>
 */
function jeab_migrate_json_retired(string $id): bool
{
    return in_array($id, ['inbox', 'inbox_threads', 'purchases', 'orders'], true);
}

/** data.migrate_all importerar saknade nycklar även när körvägen redan är SQL. */
function jeab_migrate_json_importable(string $id): bool
{
    return in_array($id, ['inbox', 'inbox_threads', 'purchases'], true);
}

/**
 * @return array<string, mixed>
 */
function jeab_migrate_retired_shape(string $id): array
{
    return jeab_migrate_shape($id, [
        'ok' => true,
        'dry' => true,
        'match' => true,
        'skipped' => true,
        'checksum' => 'retired: SQL is sole source',
        'error' => 'retired: SQL is sole source',
    ]);
}

function jeab_migrate_copy(array $module, bool $dry): array
{
    $id = (string) ($module['id'] ?? '');
    if (jeab_migrate_json_retired($id) && !jeab_migrate_json_importable($id)) {
        unset($dry);

        return jeab_migrate_retired_shape($id);
    }
    $rowsFn = (string) ($module['rows'] ?? '');
    $sumFn = (string) ($module['checksum'] ?? '');
    if (!function_exists($rowsFn) || !function_exists($sumFn)) {
        return jeab_migrate_shape($id, ['ok' => false, 'dry' => $dry, 'error' => 'rows eller checksum saknas.']);
    }
    $auth = (string) ($module['authoritative_sql'] ?? '');
    if ($auth !== '' && function_exists($auth) && $auth() && !jeab_migrate_json_importable($id)) {
        return jeab_migrate_shape($id, ['ok' => true, 'dry' => true, 'match' => true, 'skipped' => true]);
    }
    $source = jeab_migrate_read_source($module);
    $mapped = $rowsFn($source);
    if (!is_array($mapped)) {
        return jeab_migrate_shape($id, ['ok' => false, 'dry' => $dry, 'error' => 'rows svarade fel.']);
    }
    $checksum = (string) $sumFn($source);
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return jeab_migrate_shape($id, ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.', 'json_count' => count($mapped), 'checksum' => $checksum]);
    }
    unset($GLOBALS['_JEAB_MIGRATE_KEYS_FAILED']);
    $existing = jeab_migrate_owned_keys($pdo, $module);
    if (!empty($GLOBALS['_JEAB_MIGRATE_KEYS_FAILED'])) {
        unset($GLOBALS['_JEAB_MIGRATE_KEYS_FAILED']);

        return jeab_migrate_shape($id, [
            'ok' => false,
            'dry' => $dry,
            'written' => false,
            'error' => 'SQL gick inte att läsa. Inget ersatt.',
            'json_count' => count($mapped),
            'checksum' => $checksum,
        ]);
    }
    if ($existing !== []) {
        return jeab_migrate_copy_preserving($pdo, $module, $mapped, $existing, $dry);
    }
    try {
        if (!$dry) {
            jeab_migrate_ensure($pdo, $module);
            // Nyckelläsningen kan ha missat raden (tabellen fanns inte ännu).
            // keep_json och vanlig backfill får inte skriva äldre JSON över en SQL-rad som redan finns.
            unset($GLOBALS['_JEAB_MIGRATE_KEYS_FAILED']);
            $existing = jeab_migrate_owned_keys($pdo, $module);
            if (!empty($GLOBALS['_JEAB_MIGRATE_KEYS_FAILED'])) {
                unset($GLOBALS['_JEAB_MIGRATE_KEYS_FAILED']);

                return jeab_migrate_shape($id, [
                    'ok' => false,
                    'dry' => false,
                    'written' => false,
                    'error' => 'SQL gick inte att läsa. Inget ersatt.',
                    'json_count' => count($mapped),
                    'checksum' => $checksum,
                ]);
            }
            if ($existing !== []) {
                return jeab_migrate_copy_preserving($pdo, $module, $mapped, $existing, $dry);
            }
            jeab_migrate_upsert($pdo, $module, $mapped, false);
        }
        $sqlRows = jeab_migrate_select_owned($pdo, $module, $mapped);
    } catch (Throwable $e) {
        if ($dry) {
            return jeab_migrate_shape($id, [
                'ok' => true,
                'dry' => true,
                'match' => false,
                'json_count' => count($mapped),
                'sql_count' => 0,
                'checksum' => $checksum,
            ]);
        }
        return jeab_migrate_shape($id, ['ok' => false, 'dry' => false, 'error' => 'Tabellen gick inte att skriva.', 'json_count' => count($mapped), 'checksum' => $checksum]);
    }
    $aligned = jeab_migrate_align($sqlRows, $mapped, (string) ($module['primary_key'] ?? ''));
    $sqlChecksum = jeab_migrate_sql_checksum($sumFn, $mapped, $aligned, $checksum);
    $match = $checksum === $sqlChecksum && count($mapped) === count($aligned);
    return jeab_migrate_shape($id, [
        'ok' => true,
        'dry' => $dry,
        'match' => $match,
        'written' => !$dry,
        'json_count' => count($mapped),
        'sql_count' => count($aligned),
        'checksum' => $match ? 'match' : $checksum,
    ]);
}

/**
 * SQL som redan har rader är facit för alla typer. Saknade id läggs till.
 * Befintliga rader skrivs inte över och inget raderas.
 * Match kräver att varje JSON-id finns i SQL och att antalet inte minskat.
 *
 * @param array<string, mixed> $module
 * @param list<array<string, mixed>> $mapped
 * @param array<string, true> $existing
 * @return array<string, mixed>
 */
function jeab_migrate_copy_preserving(PDO $pdo, array $module, array $mapped, array $existing, bool $dry): array
{
    $id = (string) ($module['id'] ?? '');
    $pk = (string) ($module['primary_key'] ?? '');
    $before = count($existing);
    $fresh = [];
    foreach ($mapped as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row[$pk] ?? ''));
        if ($key === '' || isset($existing[$key])) {
            continue;
        }
        $fresh[] = $row;
    }
    try {
        if (!$dry && $fresh !== []) {
            jeab_migrate_ensure($pdo, $module);
            jeab_migrate_upsert($pdo, $module, $fresh);
        }
        $afterKeys = jeab_migrate_owned_keys($pdo, $module);
    } catch (Throwable) {
        return jeab_migrate_shape($id, [
            'ok' => false,
            'dry' => $dry,
            'error' => 'Tabellen gick inte att skriva.',
            'json_count' => count($mapped),
            'sql_count' => $before,
            'preserved' => true,
        ]);
    }
    $missing = false;
    foreach ($mapped as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row[$pk] ?? ''));
        if ($key === '' || isset($afterKeys[$key])) {
            continue;
        }
        $missing = true;
        break;
    }
    $after = count($afterKeys);
    $match = !$missing && $after >= $before;

    return jeab_migrate_shape($id, [
        'ok' => true,
        'dry' => $dry,
        'match' => $match,
        'skipped' => $match,
        'preserved' => true,
        'written' => !$dry && $fresh !== [],
        'json_count' => count($mapped),
        'sql_count' => $after,
        'checksum' => 'preserved',
    ]);
}

/**
 * Nycklar som hör till modulen. Delade tabeller räknas per kind, prefix eller doc_key.
 *
 * @param array<string, mixed> $module
 * @return array<string, true>
 */
function jeab_migrate_owned_keys(PDO $pdo, array $module): array
{
    $table = (string) ($module['table'] ?? '');
    $pk = (string) ($module['primary_key'] ?? '');
    if ($table === '' || $pk === '') {
        return [];
    }
    $columns = is_array($module['columns'] ?? null) ? $module['columns'] : [];
    $id = (string) ($module['id'] ?? '');
    if (in_array('kind', $columns, true)) {
        $kind = jeab_migrate_owned_kind($id);
        if ($kind !== '') {
            return jeab_migrate_sql_keys_where($pdo, $table, $pk, 'kind', $kind);
        }
    }
    if ($id === 'biz_docs') {
        return jeab_migrate_sql_keys_like($pdo, $table, $pk, 'biz_');
    }
    if ($pk === 'doc_key' && jeab_migrate_table_shared($table)) {
        return jeab_migrate_sql_keys_where($pdo, $table, $pk, $pk, $id);
    }

    return jeab_migrate_sql_keys($pdo, $table, $pk);
}

function jeab_migrate_owned_kind(string $id): string
{
    return match ($id) {
        'app_errors' => 'app_error',
        'who_diag' => 'who_diag',
        'reminder_follow' => 'reminder_follow',
        'reminder_hook' => 'reminder_hook',
        default => '',
    };
}

function jeab_migrate_table_shared(string $table): bool
{
    if ($table === '' || !function_exists('jeab_migrate_registry')) {
        return false;
    }
    $owners = 0;
    foreach (jeab_migrate_registry() as $entry) {
        if ((string) ($entry['table'] ?? '') === $table) {
            $owners++;
            if ($owners > 1) {
                return true;
            }
        }
    }

    return false;
}

/** @return array<string, true> */
function jeab_migrate_keys_unreadable(Throwable $e): array
{
    $msg = $e->getMessage();
    $missing = stripos($msg, 'no such table') !== false
        || stripos($msg, 'doesn\'t exist') !== false
        || str_contains($msg, '1146');
    if (!$missing) {
        $GLOBALS['_JEAB_MIGRATE_KEYS_FAILED'] = true;
    }

    return [];
}

/** @return array<string, true> */
function jeab_migrate_sql_keys(PDO $pdo, string $table, string $pk): array
{
    try {
        $stmt = $pdo->query('SELECT ' . jeab_migrate_ident($pdo, $pk) . ' FROM ' . jeab_migrate_ident($pdo, $table));
    } catch (Throwable $e) {
        return jeab_migrate_keys_unreadable($e);
    }

    return $stmt instanceof PDOStatement ? jeab_migrate_sql_key_map($stmt) : [];
}

/** @return array<string, true> */
function jeab_migrate_sql_keys_where(PDO $pdo, string $table, string $pk, string $column, string $value): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT ' . jeab_migrate_ident($pdo, $pk) . ' FROM ' . jeab_migrate_ident($pdo, $table)
            . ' WHERE ' . jeab_migrate_ident($pdo, $column) . ' = ?'
        );
        $stmt->execute([$value]);
    } catch (Throwable $e) {
        return jeab_migrate_keys_unreadable($e);
    }

    return jeab_migrate_sql_key_map($stmt);
}

/** @return array<string, true> */
function jeab_migrate_sql_keys_like(PDO $pdo, string $table, string $pk, string $prefix): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT ' . jeab_migrate_ident($pdo, $pk) . ' FROM ' . jeab_migrate_ident($pdo, $table)
            . ' WHERE ' . jeab_migrate_ident($pdo, $pk) . ' LIKE ?'
        );
        $stmt->execute([$prefix . '%']);
    } catch (Throwable $e) {
        return jeab_migrate_keys_unreadable($e);
    }

    return jeab_migrate_sql_key_map($stmt);
}

/** @return array<string, true> */
function jeab_migrate_sql_key_map(PDOStatement $stmt): array
{
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
        $key = trim((string) $value);
        if ($key !== '') {
            $out[$key] = true;
        }
    }

    return $out;
}

function jeab_migrate_sql_count(PDO $pdo, string $table): int
{
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM ' . jeab_migrate_ident($pdo, $table));
    } catch (Throwable) {
        return 0;
    }

    return $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : 0;
}

/**
 * @param list<array<string, mixed>> $mapped
 * @param list<array<string, mixed>> $aligned
 */
function jeab_migrate_sql_checksum(string $sumFn, array $mapped, array $aligned, string $jsonChecksum): string
{
    $rowChecksum = jeab_migrate_checksum($mapped);
    $sqlRows = jeab_migrate_checksum($aligned);
    if ($jsonChecksum === $rowChecksum) {
        return $sqlRows;
    }
    if (count($aligned) === 1 && is_string($aligned[0]['payload'] ?? null)) {
        $decoded = json_decode($aligned[0]['payload'], true);
        if (is_array($decoded) && function_exists($sumFn)) {
            $fromPayload = (string) $sumFn($decoded);
            if ($fromPayload === $jsonChecksum) {
                return $fromPayload;
            }
        }
    }
    if ($rowChecksum === jeab_migrate_checksum($aligned) && $mapped !== []) {
        return $jsonChecksum;
    }
    return $sqlRows;
}

/** @param array<string, mixed> $module @return array<string, mixed> */
function jeab_migrate_source_path(array $module): string
{
    $paths = $module['json'] ?? [];
    $rel = is_array($paths) ? (string) ($paths[0] ?? '') : '';
    if ($rel === '') {
        return '';
    }
    $rel = ltrim($rel, '/');
    if (str_ends_with($rel, '/')) {
        return '';
    }
    if (str_starts_with($rel, 'data/') && function_exists('jeab_data_file')) {
        return jeab_data_file(substr($rel, 5));
    }
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__, 2);
    return $root . '/' . $rel;
}

/** @param array<string, mixed> $module @return array<string, mixed> */
function jeab_migrate_read_source(array $module): array
{
    $id = (string) ($module['id'] ?? '');
    if ($id === 'biz_docs' && function_exists('jeab_migrate_biz_docs_source')) {
        return jeab_migrate_biz_docs_source();
    }
    if (jeab_migrate_json_retired($id) && !jeab_migrate_json_importable($id)) {
        return [];
    }
    if ($id === 'solkoll' && function_exists('jeab_migrate_solkoll_source')) {
        $source = jeab_migrate_solkoll_source();

        return is_array($source) ? $source : [];
    }
    if ($id === 'app_errors_webhook' && function_exists('jeab_migrate_app_errors_webhook_source')) {
        $source = jeab_migrate_app_errors_webhook_source();

        return is_array($source) ? $source : [];
    }
    $paths = $module['json'] ?? [];
    $rel = is_array($paths) ? (string) ($paths[0] ?? '') : '';
    $path = jeab_migrate_source_path($module);
    if ($path === '' || !is_file($path)) {
        return [];
    }
    $raw = (string) file_get_contents($path);
    if (str_ends_with($rel, '.jsonl')) {
        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $module
 * @param array<string, mixed> $rows
 */
function jeab_migrate_write_json(array $module, array $rows): void
{
    if (jeab_migrate_json_retired((string) ($module['id'] ?? ''))) {
        unset($rows);
        throw new RuntimeException('retired: SQL is sole source');
    }
    $rowsFn = (string) ($module['rows'] ?? '');
    if (!function_exists($rowsFn)) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $path = jeab_migrate_source_path($module);
    if ($path === '') {
        throw new RuntimeException('Typen kan inte skrivas tillbaka till JSON.');
    }
    $existing = [];
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        $existing = is_array($decoded) ? $decoded : [];
    }
    $list = array_is_list($rows) ? $rows : array_values($rows);
    $id = (string) ($module['id'] ?? '');
    $attempts = [];
    if ($existing !== [] && !array_is_list($existing)) {
        $doc = $existing;
        if ($id !== '') {
            $doc[$id] = $list;
        }
        $attempts[] = $doc;
    } else {
        if ($id !== '') {
            $attempts[] = [$id => $list];
        }
        $attempts[] = $list;
    }
    $wanted = jeab_migrate_checksum($list);
    $chosen = null;
    foreach ($attempts as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $written = $rowsFn($candidate);
        if (is_array($written) && jeab_migrate_checksum($written) === $wanted) {
            $chosen = $candidate;
            break;
        }
    }
    if (!is_array($chosen)) {
        throw new RuntimeException('Typen kan inte skrivas tillbaka till JSON.');
    }
    $encoded = json_encode($chosen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Typen kan inte skrivas tillbaka till JSON.');
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
}

/** @param list<array<string, mixed>> $rows @return array<string, array<string, mixed>> */
function jeab_migrate_key_rows(array $rows, string $primaryKey): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = (string) ($row[$primaryKey] ?? '');
        if ($key === '') {
            continue;
        }
        $out[$key] = $row;
    }
    return $out;
}

/** @param array<string, mixed> $module */
function jeab_migrate_ensure(PDO $pdo, array $module): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $schema = $module['schema'] ?? [];
    $sql = is_array($schema) ? (string) ($schema[$driver] ?? '') : '';
    if ($sql === '') {
        throw new RuntimeException('Schemat saknas.');
    }
    $pdo->exec($sql);
    if ((string) ($module['table'] ?? '') === 'external_queue_jobs') {
        if (function_exists('jeab_migrate_external_queue_ensure_attempts')) {
            jeab_migrate_external_queue_ensure_attempts($pdo);
        }
        if (function_exists('jeab_migrate_external_queue_ensure_claimed_until')) {
            jeab_migrate_external_queue_ensure_claimed_until($pdo);
        }
        if (function_exists('jeab_migrate_external_queue_ensure_claim_token')) {
            jeab_migrate_external_queue_ensure_claim_token($pdo);
        }
    }
    jeab_migrate_ensure_columns($pdo, $module);
    jeab_migrate_ensure_key($pdo, $module);
    jeab_migrate_ensure_fullmakt_status($pdo, $module);
    $table = (string) ($module['table'] ?? '');
    if (in_array($table, ['reminders', 'bookings', 'customers', 'reminder_lists'], true) && function_exists('jeab_sql_ensure_autoincrement')) {
        jeab_sql_ensure_autoincrement($pdo, $table);
    }
}

/**
 * Index på fullmakt_docs.status. Idempotent: befintlig tabell får indexet,
 * omkörning och dubblett (ny MySQL-tabell har det redan i CREATE) ignoreras.
 *
 * @param array<string, mixed> $module
 */
function jeab_migrate_ensure_fullmakt_status(PDO $pdo, array $module): void
{
    if ((string) ($module['table'] ?? '') !== 'fullmakt_docs') {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $sqlite = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql';
    $sql = 'CREATE INDEX ' . ($sqlite ? 'IF NOT EXISTS ' : '')
        . jeab_migrate_ident($pdo, 'fullmakt_docs_status')
        . ' ON ' . jeab_migrate_ident($pdo, 'fullmakt_docs')
        . ' (' . jeab_migrate_ident($pdo, 'status') . ')';
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'already exists') !== false) {
            return;
        }
        throw $e;
    }
}

/** @param array<string, mixed> $module */
function jeab_migrate_ensure_columns(PDO $pdo, array $module): void
{
    $table = (string) ($module['table'] ?? '');
    if ($table === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
        return;
    }
    $have = [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . jeab_migrate_ident($pdo, $table));
        if ($stmt instanceof PDOStatement) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && isset($row['Field'])) {
                    $have[] = (string) $row['Field'];
                }
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt instanceof PDOStatement) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && isset($row['name'])) {
                    $have[] = (string) $row['name'];
                }
            }
        }
    }
    if ($have === []) {
        return;
    }
    $wanted = is_array($module['columns'] ?? null) ? $module['columns'] : [];
    $schema = $module['schema'] ?? [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $ddl = is_array($schema) ? (string) ($schema[$driver] ?? '') : '';
    if (stripos($ddl, 'updated_at') !== false) {
        $wanted[] = 'updated_at';
    }
    foreach ($wanted as $column) {
        $column = (string) $column;
        if ($column === '' || in_array($column, $have, true)) {
            continue;
        }
        $type = $driver === 'mysql' ? 'TEXT NULL' : 'TEXT';
        $pdo->exec('ALTER TABLE ' . jeab_migrate_ident($pdo, $table) . ' ADD COLUMN ' . jeab_migrate_ident($pdo, $column) . ' ' . $type);
        $have[] = $column;
    }
}

/** @param array<string, mixed> $module */
function jeab_migrate_ensure_key(PDO $pdo, array $module): void
{
    $table = (string) ($module['table'] ?? '');
    $pk = (string) ($module['primary_key'] ?? '');
    if ($table === '' || $pk === '') {
        return;
    }
    $primary = [];
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt = $pdo->query('SHOW KEYS FROM ' . jeab_migrate_ident($pdo, $table) . " WHERE Key_name = 'PRIMARY'");
        if ($stmt instanceof PDOStatement) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && isset($row['Column_name'])) {
                    $primary[] = (string) $row['Column_name'];
                }
            }
        }
    } else {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt instanceof PDOStatement) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (is_array($row) && (int) ($row['pk'] ?? 0) > 0) {
                    $primary[(int) $row['pk']] = (string) ($row['name'] ?? '');
                }
            }
            ksort($primary);
            $primary = array_values($primary);
        }
    }
    if ($primary === [$pk]) {
        return;
    }
    $index = $table . '_' . $pk . '_uq';
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index)) {
        return;
    }
    $sql = 'CREATE UNIQUE INDEX ' . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '' : 'IF NOT EXISTS ')
        . jeab_migrate_ident($pdo, $index) . ' ON ' . jeab_migrate_ident($pdo, $table) . ' (' . jeab_migrate_ident($pdo, $pk) . ')';
    try {
        $pdo->exec($sql);
    } catch (Throwable) {
    }
}

/**
 * @param array<string, mixed> $module
 * @param list<array<string, mixed>> $rows
 */
function jeab_migrate_upsert(PDO $pdo, array $module, array $rows, bool $replace = true): void
{
    $table = (string) ($module['table'] ?? '');
    $pk = (string) ($module['primary_key'] ?? '');
    $columns = $module['columns'] ?? [];
    if ($table === '' || $pk === '' || !is_array($columns) || $columns === []) {
        throw new RuntimeException('Tabellen saknar kolumner.');
    }
    $columns = array_values(array_map('strval', $columns));
    $schema = $module['schema'] ?? [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $ddl = is_array($schema) ? (string) ($schema[$driver] ?? '') : '';
    $extra = [];
    if (stripos($ddl, 'updated_at') !== false && !in_array('updated_at', $columns, true)) {
        $extra['updated_at'] = '1970-01-01 00:00:00';
    }
    $quoted = [];
    foreach (array_merge($columns, array_keys($extra)) as $column) {
        $quoted[] = jeab_migrate_ident($pdo, (string) $column);
    }
    $tableSql = jeab_migrate_ident($pdo, $table);
    $pkSql = jeab_migrate_ident($pdo, $pk);
    $placeholders = implode(', ', array_fill(0, count($quoted), '?'));
    $assignments = [];
    foreach (array_merge($columns, array_keys($extra)) as $column) {
        if ((string) $column === $pk) {
            continue;
        }
        $ident = jeab_migrate_ident($pdo, (string) $column);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $assignments[] = $ident . ' = VALUES(' . $ident . ')';
        } else {
            $assignments[] = $ident . ' = excluded.' . $ident;
        }
    }
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if (!$replace) {
        if ($mysql) {
            $sql = 'INSERT IGNORE INTO ' . $tableSql . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
        } else {
            $sql = 'INSERT INTO ' . $tableSql . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ') ON CONFLICT(' . $pkSql . ') DO NOTHING';
        }
    } elseif ($mysql) {
        $sql = 'INSERT INTO ' . $tableSql . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
        $sql .= $assignments === [] ? '' : ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    } else {
        $sql = 'INSERT INTO ' . $tableSql . ' (' . implode(', ', $quoted) . ') VALUES (' . $placeholders . ')';
        $sql .= ' ON CONFLICT(' . $pkSql . ') DO UPDATE SET ' . ($assignments === [] ? $pkSql . ' = excluded.' . $pkSql : implode(', ', $assignments));
    }
    $stmt = $pdo->prepare($sql);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[(string) $column] ?? null;
        }
        foreach ($extra as $value) {
            $values[] = $row['updated_at'] ?? $value;
        }
        $stmt->execute($values);
    }
}

/**
 * @param array<string, mixed> $module
 * @return list<array<string, mixed>>
 */
function jeab_migrate_select(PDO $pdo, array $module): array
{
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $columns = $module['columns'] ?? [];
    if (!is_array($columns) || $columns === []) {
        return [];
    }
    $quoted = [];
    foreach ($columns as $column) {
        $quoted[] = jeab_migrate_ident($pdo, (string) $column);
    }
    $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? $columns[0]));
    $stmt = $pdo->query('SELECT ' . implode(', ', $quoted) . ' FROM ' . $table . ' ORDER BY ' . $pk);
    if (!$stmt instanceof PDOStatement) {
        return [];
    }
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * @param array<string, mixed> $module
 * @param list<array<string, mixed>> $mapped
 * @return list<array<string, mixed>>
 */
function jeab_migrate_select_owned(PDO $pdo, array $module, array $mapped): array
{
    $table = (string) ($module['table'] ?? '');
    $owners = [];
    foreach (jeab_migrate_registry() as $entry) {
        $name = (string) ($entry['table'] ?? '');
        if ($name !== '') {
            $owners[$name][] = (string) ($entry['id'] ?? '');
        }
    }
    if (count($owners[$table] ?? []) < 2) {
        return jeab_migrate_select($pdo, $module);
    }
    $columns = $module['columns'] ?? [];
    $pk = (string) ($module['primary_key'] ?? '');
    if (is_array($columns) && in_array('kind', $columns, true)) {
        $kind = '';
        foreach ($mapped as $row) {
            if (isset($row['kind']) && (string) $row['kind'] !== '') {
                $kind = (string) $row['kind'];
                break;
            }
        }
        if ($kind === '') {
            $known = [
                'app_errors' => 'app_error',
                'who_diag' => 'who_diag',
                'reminder_follow' => 'reminder_follow',
                'reminder_hook' => 'reminder_hook',
            ];
            $kind = $known[(string) ($module['id'] ?? '')] ?? '';
        }
        if ($kind !== '') {
            return jeab_migrate_select_where($pdo, $module, 'kind', [$kind]);
        }
    }
    if ((string) ($module['id'] ?? '') === 'biz_docs') {
        return jeab_migrate_select_prefix($pdo, $module, $pk, 'biz_');
    }
    $keys = [];
    foreach ($mapped as $row) {
        if (!isset($row[$pk]) || $row[$pk] === '' || $row[$pk] === null) {
            continue;
        }
        $keys[] = $row[$pk];
    }
    if ($keys === []) {
        return [];
    }
    return jeab_migrate_select_where($pdo, $module, $pk, $keys);
}

/**
 * @param array<string, mixed> $module
 * @param list<int|string> $values
 * @return list<array<string, mixed>>
 */
function jeab_migrate_select_where(PDO $pdo, array $module, string $column, array $values): array
{
    $columns = $module['columns'] ?? [];
    if (!is_array($columns) || $columns === [] || $values === []) {
        return [];
    }
    $quoted = [];
    foreach ($columns as $name) {
        $quoted[] = jeab_migrate_ident($pdo, (string) $name);
    }
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? $columns[0]));
    $columnSql = jeab_migrate_ident($pdo, $column);
    $marks = implode(', ', array_fill(0, count($values), '?'));
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $quoted) . ' FROM ' . $table . ' WHERE ' . $columnSql . ' IN (' . $marks . ') ORDER BY ' . $pk);
    $stmt->execute(array_values($values));
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/** @param array<string, mixed> $module @return list<array<string, mixed>> */
function jeab_migrate_select_prefix(PDO $pdo, array $module, string $column, string $prefix): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
        return [];
    }
    $columns = $module['columns'] ?? [];
    if (!is_array($columns) || $columns === []) {
        return [];
    }
    $quoted = [];
    foreach ($columns as $name) {
        $quoted[] = jeab_migrate_ident($pdo, (string) $name);
    }
    $table = jeab_migrate_ident($pdo, (string) ($module['table'] ?? ''));
    $pk = jeab_migrate_ident($pdo, (string) ($module['primary_key'] ?? $columns[0]));
    $columnSql = jeab_migrate_ident($pdo, $column);
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $quoted) . ' FROM ' . $table . ' WHERE ' . $columnSql . ' LIKE ? ORDER BY ' . $pk);
    $stmt->execute([$prefix . '%']);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * @param list<array<string, mixed>> $sqlRows
 * @param list<array<string, mixed>> $mapped
 * @return list<array<string, mixed>>
 */
function jeab_migrate_align(array $sqlRows, array $mapped, string $primaryKey): array
{
    $templates = [];
    foreach ($mapped as $row) {
        if (!is_array($row)) {
            continue;
        }
        $templates[(string) ($row[$primaryKey] ?? '')] = $row;
    }
    $sample = $mapped[0] ?? null;
    $columns = is_array($sample) ? array_keys($sample) : [];
    $out = [];
    foreach ($sqlRows as $sql) {
        $key = (string) ($sql[$primaryKey] ?? '');
        $template = $templates[$key] ?? (is_array($sample) ? $sample : []);
        $aligned = [];
        foreach ($template as $column => $value) {
            $raw = $sql[$column] ?? null;
            if (is_int($value) && is_numeric($raw)) {
                $aligned[$column] = (int) $raw;
            } elseif ($value === null && ($raw === null || $raw === '')) {
                $aligned[$column] = null;
            } elseif (is_string($value) && is_scalar($raw)) {
                $aligned[$column] = (string) $raw;
            } else {
                $aligned[$column] = $raw;
            }
        }
        if ($aligned !== []) {
            $out[] = $aligned;
        }
    }
    if ($columns === []) {
        return $out;
    }
    return jeab_migrate_project($out, $primaryKey, $columns);
}

function jeab_migrate_ident(PDO $pdo, string $ident): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident)) {
        throw new RuntimeException('Ogiltigt namn.');
    }
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`' . $ident . '`' : '"' . $ident . '"';
}

if (!function_exists('jeab_migrate_declare')) {
    /**
     * @param list<string> $columns
     * @param list<string> $depends
     * @return array{id:string,table:string,json:string,primary_key:string,columns:list<string>,depends:list<string>,schema:array{mysql:string,sqlite:string}}
     */
    function jeab_migrate_declare(
        string $id,
        string $table,
        string $jsonFile,
        string $primaryKey,
        array $columns,
        string $mysql,
        string $sqlite,
        array $depends = []
    ): array {
        return jeab_migrate_module($id, $table, $jsonFile, $primaryKey, $columns, $mysql, $sqlite, $depends);
    }
}

/**
 * @param array<string, mixed> $raw
 * @return array<string, mixed>
 */
function jeab_migrate_shape(string $type, array $raw): array
{
    $jsonCount = (int) ($raw['json_count'] ?? $raw['registry_json'] ?? $raw['json_rows'] ?? 0);
    if (isset($raw['inbox_json'])) {
        $jsonCount += (int) $raw['inbox_json'];
    }
    $sqlCount = (int) ($raw['sql_count'] ?? $raw['registry_sql'] ?? $raw['sql_rows'] ?? 0);
    if (isset($raw['inbox_sql']) && !isset($raw['sql_count'])) {
        $sqlCount += (int) $raw['inbox_sql'];
    }
    $match = (bool) ($raw['match'] ?? false);
    if (!$match && array_key_exists('count_match', $raw) && array_key_exists('checksum_match', $raw)) {
        $match = !empty($raw['count_match']) && !empty($raw['checksum_match']);
    }
    $out = [
        'type' => $type,
        'ok' => !isset($raw['ok']) || !empty($raw['ok']),
        'dry' => !empty($raw['dry']),
        'match' => $match,
        'json_count' => $jsonCount,
        'sql_count' => $sqlCount,
        'checksum' => (string) ($raw['checksum'] ?? ''),
        'skipped' => !empty($raw['skipped']),
        'preserved' => !empty($raw['preserved']),
        'written' => !empty($raw['written']),
        'error' => (string) ($raw['error'] ?? ''),
    ];
    if ($type === 'fullmakt' && function_exists('jeab_migrate_fullmakt_json_only')) {
        $out['json_only'] = jeab_migrate_fullmakt_json_only();
    }

    return $out;
}

require_once __DIR__ . '/backup.php';
