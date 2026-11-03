<?php

declare(strict_types=1);

require_once __DIR__ . '/../shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/registry.php';

/**
 * migrate.backup dry|run och migrate.restore_verify.
 * Registret anger JSON-filer och tabeller. Källorna läses bara.
 * Exporten är en JSON-lines-fil per tabell. Svaret har sökvägar, antal och sha256.
 */

const JEAB_BACKUP_RETENTION_KEY = 'backup_retention_days';

/**
 * Intern router utan tokenkontroll (hook.php kontrollerar token via *_dispatch).
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_migrate_backup_handle(array $input): array
{
    return (string) ($input['action'] ?? '') === 'migrate.restore_verify'
        ? jeab_migrate_restore_verify($input)
        : jeab_migrate_backup($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_migrate_backup_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }

    return jeab_migrate_backup($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_migrate_restore_verify_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }

    return jeab_migrate_restore_verify($input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
/**
 * Tick anropar det här en natt klockan 03, i ett eget varv utan mejl.
 * En sidvisning och en tick utanför fönstret anropar det inte.
 *
 * @return array<string, mixed>
 */
function jeab_migrate_backup_from_tick(): array
{
    if (empty($GLOBALS['_JEAB_TICK'])) {
        return jeab_migrate_backup_fail('Inte tick.', false);
    }

    return jeab_migrate_backup(['mode' => 'run']);
}

function jeab_migrate_backup(array $input = []): array
{
    $run = jeab_migrate_backup_wants_run($input);
    if ($run === null) {
        return jeab_migrate_backup_fail('mode ska vara dry eller run.', false);
    }
    $days = jeab_migrate_backup_retention_days();
    $files = [];
    foreach (jeab_migrate_backup_source_rels() as $rel) {
        $meta = jeab_migrate_backup_file_meta($rel);
        if ($meta === null) {
            return jeab_migrate_backup_fail('Filen gick inte att läsa.', $run);
        }
        $files[] = $meta;
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return jeab_migrate_backup_public([
            'ok' => false,
            'mode' => $run ? 'run' : 'dry',
            'dry' => !$run,
            'written' => false,
            'error' => 'Ingen SQL-databas.',
            'retention_days' => $days,
            'files' => jeab_migrate_backup_file_view($files),
            'tables' => [],
            'status' => 400,
        ]);
    }
    if (!$run) {
        try {
            $tables = jeab_migrate_backup_read_tx($pdo, static function () use ($pdo): array {
                return jeab_migrate_backup_collect_tables($pdo, null);
            });
        } catch (Throwable) {
            return jeab_migrate_backup_fail('Tabellen gick inte att läsa.', false);
        }

        return jeab_migrate_backup_public([
            'ok' => true,
            'mode' => 'dry',
            'dry' => true,
            'written' => false,
            'retention_days' => $days,
            'files' => jeab_migrate_backup_file_view($files),
            'tables' => jeab_migrate_backup_table_view($tables),
            'status' => 200,
        ]);
    }

    $base = jeab_migrate_backup_base();
    if (!jeab_migrate_backup_outside_ready() || !jeab_migrate_backup_mkdir($base)) {
        return jeab_migrate_backup_fail('Katalogen gick inte att skapa.', true);
    }
    $stamp = jeab_migrate_backup_stamp($base);
    $dir = $base . '/' . $stamp;
    if (!jeab_migrate_backup_mkdir($dir)) {
        return jeab_migrate_backup_fail('Katalogen gick inte att skapa.', true);
    }
    try {
        $tables = jeab_migrate_backup_read_tx($pdo, static function () use ($pdo, $dir): array {
            return jeab_migrate_backup_collect_tables($pdo, $dir);
        });
        jeab_migrate_backup_copy_files($dir, $files);
        jeab_migrate_backup_write_manifest($dir, $stamp, $days, $files, $tables);
        $pruned = jeab_migrate_backup_prune($base, $days, $stamp);
    } catch (Throwable) {
        if (is_dir($dir)) {
            jeab_migrate_backup_rmtree($dir, $base);
        }

        return jeab_migrate_backup_fail('Backupen gick inte att skriva.', true);
    }
    $manifestFile = $dir . '/' . 'manifest.json';
    $manifestSha = is_file($manifestFile) ? hash_file('sha256', $manifestFile) : false;

    return jeab_migrate_backup_public([
        'ok' => true,
        'mode' => 'run',
        'dry' => false,
        'written' => true,
        'dir' => jeab_migrate_backup_public_dir($stamp),
        'manifest' => jeab_migrate_backup_public_dir($stamp) . '/manifest.json',
        'manifest_sha256' => is_string($manifestSha) ? $manifestSha : '',
        'retention_days' => $days,
        'files' => jeab_migrate_backup_file_view($files),
        'tables' => jeab_migrate_backup_table_view($tables),
        'pruned' => count($pruned),
        'pruned_dirs' => $pruned,
        'status' => 200,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_migrate_restore_verify(array $input): array
{
    $katalog = trim((string) ($input['dir'] ?? ($input['katalog'] ?? ($input['path'] ?? ''))));
    $dir = jeab_migrate_backup_stamp_dir($katalog);
    if ($dir === '') {
        return jeab_migrate_backup_public([
            'ok' => false,
            'mode' => 'restore_verify',
            'dry' => true,
            'written' => false,
            'error' => 'Katalogen saknas.',
            'status' => 400,
        ]);
    }
    $stamp = basename($dir);
    $manifestFile = $dir . '/' . 'manifest.json';
    $raw = is_file($manifestFile) && !is_link($manifestFile) ? file_get_contents($manifestFile) : false;
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    unset($raw);
    if (!is_array($manifest) || (int) ($manifest['version'] ?? 0) !== 1) {
        return jeab_migrate_backup_public([
            'ok' => false,
            'mode' => 'restore_verify',
            'dry' => true,
            'written' => false,
            'dir' => jeab_migrate_backup_public_dir($stamp),
            'error' => 'Manifestet saknas.',
            'status' => 400,
        ]);
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return jeab_migrate_backup_fail('Ingen SQL-databas.', false);
    }
    $fileReports = [];
    $mismatch = 0;
    $listed = $manifest['files'] ?? [];
    foreach (is_array($listed) ? $listed : [] as $file) {
        if (!is_array($file)) {
            $mismatch++;
            continue;
        }
        $report = jeab_migrate_restore_file($dir, $file);
        if (empty($report['match'])) {
            $mismatch++;
        }
        $fileReports[] = $report;
    }
    try {
        $tableReports = jeab_migrate_backup_read_tx($pdo, static function () use ($pdo, $dir, $manifest): array {
            $out = [];
            $tables = $manifest['tables'] ?? [];
            foreach (is_array($tables) ? $tables : [] as $table) {
                if (!is_array($table)) {
                    $out[] = [
                        'table' => '',
                        'rows' => 0,
                        'live_rows' => 0,
                        'sha256' => '',
                        'live_sha256' => '',
                        'match' => false,
                    ];
                    continue;
                }
                $out[] = jeab_migrate_restore_table($pdo, $dir, $table);
            }

            return $out;
        });
    } catch (Throwable) {
        return jeab_migrate_backup_public([
            'ok' => false,
            'mode' => 'restore_verify',
            'dry' => true,
            'written' => false,
            'dir' => jeab_migrate_backup_public_dir($stamp),
            'error' => 'Tabellen gick inte att läsa.',
            'status' => 400,
        ]);
    }
    if (!is_array($tableReports)) {
        $tableReports = [];
        $mismatch++;
    }
    $logDrift = [];
    foreach ($tableReports as $report) {
        if (!is_array($report) || empty($report['match'])) {
            $mismatch++;
            continue;
        }
        if (!empty($report['log_drift'])) {
            $name = (string) ($report['table'] ?? '');
            if ($name !== '') {
                $logDrift[] = $name;
            }
        }
    }

    return jeab_migrate_backup_public([
        'ok' => $mismatch === 0,
        'mode' => 'restore_verify',
        'dry' => true,
        'written' => false,
        'dir' => jeab_migrate_backup_public_dir($stamp),
        'files' => $fileReports,
        'tables' => $tableReports,
        'mismatch' => $mismatch,
        'log_drift' => $logDrift,
        'status' => 200,
    ]);
}

/** @param array<string, mixed> $input */
function jeab_migrate_backup_wants_run(array $input): ?bool
{
    $mode = strtolower(trim((string) ($input['mode'] ?? '')));
    if ($mode !== '' && $mode !== 'dry' && $mode !== 'run') {
        return null;
    }
    if ($mode === 'run') {
        return true;
    }
    if ($mode === 'dry') {
        return false;
    }
    if (!array_key_exists('dry', $input)) {
        return false;
    }
    $value = $input['dry'];

    return $value === false || $value === 0 || $value === '0' || $value === 'false';
}

function jeab_migrate_backup_root(): string
{
    return jeab_app_web_root();
}

/** dirname(public_html)/privat. Skapas inte här. */
function jeab_migrate_backup_outside(): string
{
    return jeab_outside_privat_dir();
}

function jeab_migrate_backup_base(): string
{
    return jeab_migrate_backup_outside() . '/backups';
}

function jeab_migrate_backup_public_dir(string $stamp): string
{
    return 'privat/backups/' . $stamp;
}

/** privat/ finns, går att skriva, och ligger inte under webbroten. */
function jeab_migrate_backup_outside_ready(): bool
{
    $outside = jeab_migrate_backup_outside();
    if ($outside === '' || !is_dir($outside) || is_link($outside) || !is_writable($outside)) {
        return false;
    }
    $realOutside = realpath($outside);
    $realWeb = realpath(jeab_migrate_backup_root());
    if ($realOutside === false) {
        return false;
    }
    $realOutside = rtrim(str_replace('\\', '/', $realOutside), '/');
    if ($realWeb !== false) {
        $realWeb = rtrim(str_replace('\\', '/', $realWeb), '/');
        if ($realOutside === $realWeb || str_starts_with($realOutside, $realWeb . '/')) {
            return false;
        }
    }

    return true;
}

function jeab_migrate_backup_retention_days(?PDO $pdo = null): int
{
    // Giltig env 14–30 vinner, sedan giltig inställning, annars 21.
    $valid = static function (mixed $raw): ?int {
        $raw = trim((string) $raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }
        $days = (int) $raw;

        return ($days >= 14 && $days <= 30) ? $days : null;
    };
    $env = getenv('BACKUP_RETENTION_DAYS');
    $days = is_string($env) ? $valid($env) : null;
    if ($days !== null) {
        return $days;
    }
    if (!$pdo instanceof PDO && function_exists('jeab_data_pdo')) {
        $pdo = jeab_data_pdo();
    }
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $stored = jeab_settings_value($pdo, $driver, JEAB_BACKUP_RETENTION_KEY);
        $days = is_string($stored) ? $valid($stored) : null;
        if ($days !== null) {
            return $days;
        }
    }

    return 21;
}

/** @return list<string> */
function jeab_migrate_backup_source_rels(): array
{
    $rels = [];
    foreach (jeab_migrate_registry() as $module) {
        if (!empty($module['missing'])) {
            continue;
        }
        $id = (string) ($module['id'] ?? '');
        if (function_exists('jeab_migrate_json_retired') && jeab_migrate_json_retired($id)) {
            foreach (jeab_migrate_backup_extra_rels($id) as $extra) {
                $rels[$extra] = true;
            }
            continue;
        }
        $json = $module['json'] ?? [];
        if (is_string($json)) {
            $json = $json === '' ? [] : [$json];
        }
        if (is_array($json)) {
            foreach ($json as $rel) {
                $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
                if ($rel !== '') {
                    $rels[$rel] = true;
                }
            }
        }
        foreach (jeab_migrate_backup_extra_rels((string) ($module['id'] ?? '')) as $extra) {
            $rels[$extra] = true;
        }
    }
    $files = [];
    foreach (array_keys($rels) as $rel) {
        if (jeab_migrate_backup_blocked_rel($rel)) {
            continue;
        }
        foreach (jeab_migrate_backup_expand($rel) as $one) {
            if (!jeab_migrate_backup_blocked_rel($one) && jeab_migrate_backup_safe_rel($one)) {
                $files[$one] = true;
            }
        }
    }
    $list = array_keys($files);
    sort($list);

    return $list;
}

/** @return list<string> */
function jeab_migrate_backup_extra_rels(string $id): array
{
    if ($id === 'solkoll') {
        return ['data/' . 'solkoll-cache.json'];
    }
    if ($id === 'app_errors_webhook') {
        return ['data/' . 'app-errors-webhook-resolved.json'];
    }

    return [];
}

function jeab_migrate_backup_blocked_rel(string $rel): bool
{
    $rel = strtolower(str_replace('\\', '/', $rel));
    if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
        return true;
    }
    if (str_contains($rel, '/secrets/') || str_starts_with($rel, 'secrets/')) {
        return true;
    }
    foreach (['passwords', 'nylas', 'suresms', 'incoming-token', 'fortnox', 'tokens.rotated', '.netrc'] as $needle) {
        if (str_contains($rel, $needle)) {
            return true;
        }
    }

    return false;
}

function jeab_migrate_backup_safe_rel(string $rel): bool
{
    if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\') || str_contains($rel, '..') || str_starts_with($rel, '/')) {
        return false;
    }

    return preg_match('#^[^/]+(?:/[^/]+)*$#', $rel) === 1;
}

/** @return list<string> */
function jeab_migrate_backup_expand(string $rel): array
{
    $path = jeab_migrate_backup_abs_source($rel);
    if ($path !== '' && is_dir($path) && !is_link($path)) {
        return jeab_migrate_backup_walk_json($path, $rel);
    }

    return [$rel];
}

/** @return list<string> */
function jeab_migrate_backup_walk_json(string $dir, string $rel): array
{
    $out = [];
    $items = scandir($dir);
    if (!is_array($items)) {
        return [];
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..' || $name === '' || str_starts_with($name, '.') || str_contains($name, '..')) {
            continue;
        }
        $childRel = $rel . '/' . $name;
        if (jeab_migrate_backup_blocked_rel($childRel) || !jeab_migrate_backup_safe_rel($childRel)) {
            continue;
        }
        $child = $dir . '/' . $name;
        if (is_link($child)) {
            continue;
        }
        if (is_dir($child)) {
            foreach (jeab_migrate_backup_walk_json($child, $childRel) as $one) {
                $out[] = $one;
            }
            continue;
        }
        if (is_file($child) && preg_match('/\.jsonl?$/i', $name) === 1) {
            $out[] = $childRel;
        }
    }

    return $out;
}

function jeab_migrate_backup_abs_source(string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if (!jeab_migrate_backup_safe_rel($rel) || jeab_migrate_backup_blocked_rel($rel)) {
        return '';
    }
    if (str_starts_with($rel, 'data/')) {
        $tail = substr($rel, strlen('data/'));
        $dirs = [];
        if (function_exists('jeab_data_dir')) {
            $dirs[] = jeab_data_dir();
        }
        if (function_exists('jeab_public_data_dir')) {
            $dirs[] = jeab_public_data_dir();
        }
        $fallback = '';
        foreach ($dirs as $dir) {
            $dir = rtrim(str_replace('\\', '/', (string) $dir), '/');
            if ($dir === '') {
                continue;
            }
            $path = $dir . '/' . $tail;
            if ($fallback === '') {
                $fallback = $path;
            }
            if ((is_file($path) || is_dir($path)) && !is_link($path)) {
                return $path;
            }
        }

        return $fallback;
    }

    return jeab_migrate_backup_root() . '/' . $rel;
}

/**
 * @return array<string, mixed>|null
 */
function jeab_migrate_backup_file_meta(string $rel): ?array
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $blank = [
        'path' => $rel,
        'abs' => '',
        'bytes' => 0,
        'count' => 0,
        'sha256' => '',
        'exists' => false,
    ];
    if (jeab_migrate_backup_blocked_rel($rel) || !jeab_migrate_backup_safe_rel($rel)) {
        return $blank;
    }
    $path = jeab_migrate_backup_abs_source($rel);
    if ($path === '' || !is_file($path) || is_link($path)) {
        return $blank;
    }
    $raw = jeab_migrate_backup_read($path);
    if (!is_string($raw)) {
        return null;
    }

    return [
        'path' => $rel,
        'abs' => $path,
        'bytes' => strlen($raw),
        'count' => jeab_migrate_backup_json_count($path, $raw),
        'sha256' => hash('sha256', $raw),
        'exists' => true,
    ];
}

function jeab_migrate_backup_read(string $path): ?string
{
    if (is_link($path) || !is_file($path)) {
        return null;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return is_string($raw) ? $raw : null;
}

function jeab_migrate_backup_json_count(string $path, string $raw): int
{
    if (str_ends_with(strtolower($path), '.jsonl')) {
        $count = 0;
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        return $count;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return 0;
    }
    if (array_is_list($decoded)) {
        return count($decoded);
    }
    if ($decoded === []) {
        return 0;
    }
    $sum = 0;
    foreach ($decoded as $value) {
        if (!is_array($value)) {
            return 1;
        }
        $sum += jeab_migrate_backup_collection_count($value);
    }

    return $sum;
}

function jeab_migrate_backup_collection_count(array $value): int
{
    if ($value === []) {
        return 0;
    }
    if (array_is_list($value)) {
        return count($value);
    }
    foreach ($value as $item) {
        if (!is_array($item)) {
            return 1;
        }
    }

    return count($value);
}

/** @return list<string> */
function jeab_migrate_backup_tables(): array
{
    $tables = [];
    foreach (jeab_migrate_registry() as $module) {
        if (!empty($module['missing'])) {
            continue;
        }
        $list = $module['tables'] ?? [];
        if (!is_array($list) || $list === []) {
            $one = (string) ($module['table'] ?? '');
            $list = $one === '' ? [] : [$one];
        }
        foreach ($list as $table) {
            $table = (string) $table;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1) {
                $tables[$table] = true;
            }
        }
    }
    $names = array_keys($tables);
    sort($names);

    return $names;
}

/** @return array<string, true> */
function jeab_migrate_backup_existing(PDO $pdo): array
{
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW TABLES');
        } else {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        }
    } catch (Throwable) {
        return [];
    }
    if (!$stmt instanceof PDOStatement) {
        return [];
    }
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
        if (is_array($row) && isset($row[0]) && is_string($row[0])) {
            $names[$row[0]] = true;
        }
    }

    return $names;
}

/** @return list<string> */
function jeab_migrate_backup_columns(PDO $pdo, string $table): array
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        return [];
    }
    $cols = [];
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . jeab_migrate_ident($pdo, $table));
            if ($stmt instanceof PDOStatement) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (is_array($row) && isset($row['Field'])) {
                        $cols[] = (string) $row['Field'];
                    }
                }
            }
        } else {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
            if ($stmt instanceof PDOStatement) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (is_array($row) && isset($row['name'])) {
                        $cols[] = (string) $row['name'];
                    }
                }
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $cols;
}

/**
 * @param list<string> $columns
 */
function jeab_migrate_backup_order_sql(PDO $pdo, string $table, array $columns): string
{
    $pk = '';
    foreach (jeab_migrate_registry() as $module) {
        if ((string) ($module['table'] ?? '') !== $table) {
            continue;
        }
        $candidate = (string) ($module['primary_key'] ?? '');
        if ($candidate !== '' && in_array($candidate, $columns, true)) {
            $pk = $candidate;
            break;
        }
    }
    $parts = [];
    if ($pk !== '') {
        $parts[] = jeab_migrate_ident($pdo, $pk);
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        $parts[] = 'rowid';
    } elseif ($parts === []) {
        foreach ($columns as $column) {
            $parts[] = jeab_migrate_ident($pdo, $column);
        }
    }

    return implode(', ', $parts);
}

/**
 * @param list<string> $columns
 * @return array{rows:int,bytes:int,sha256:string}
 */
function jeab_migrate_backup_export(PDO $pdo, string $table, array $columns, ?string $dest, string $orderName = ''): array
{
    if ($orderName === '') {
        $orderName = $table;
    }
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
        throw new RuntimeException('Tabellen gick inte att läsa.');
    }
    $quoted = [];
    foreach ($columns as $column) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            throw new RuntimeException('Tabellen gick inte att läsa.');
        }
        $quoted[] = jeab_migrate_ident($pdo, $column);
    }
    if ($quoted === []) {
        throw new RuntimeException('Tabellen gick inte att läsa.');
    }
    $sql = 'SELECT ' . implode(', ', $quoted) . ' FROM ' . jeab_migrate_ident($pdo, $table);
    $order = jeab_migrate_backup_order_sql($pdo, $orderName, $columns);
    if ($order !== '') {
        $sql .= ' ORDER BY ' . $order;
    }
    $stmt = $pdo->query($sql);
    if (!$stmt instanceof PDOStatement) {
        throw new RuntimeException('Tabellen gick inte att läsa.');
    }
    $ctx = hash_init('sha256');
    $bytes = 0;
    $rows = 0;
    $fh = null;
    if ($dest !== null) {
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Exporten gick inte att skriva.');
        }
    }
    try {
        while (true) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $line = jeab_migrate_backup_line($row, $columns) . "\n";
            hash_update($ctx, $line);
            $bytes += strlen($line);
            $rows++;
            if (is_resource($fh) && fwrite($fh, $line) === false) {
                throw new RuntimeException('Exporten gick inte att skriva.');
            }
        }
    } finally {
        if (is_resource($fh)) {
            fclose($fh);
        }
    }
    if ($dest !== null) {
        chmod($dest, 0600);
    }

    return [
        'rows' => $rows,
        'bytes' => $bytes,
        'sha256' => hash_final($ctx),
    ];
}

/**
 * @param array<string, mixed> $row
 * @param list<string> $columns
 */
function jeab_migrate_backup_line(array $row, array $columns): string
{
    $out = [];
    foreach ($columns as $column) {
        $out[$column] = array_key_exists($column, $row) ? jeab_migrate_backup_cell($row[$column]) : null;
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

function jeab_migrate_backup_cell(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        if (!is_finite($value)) {
            return '';
        }
        $asInt = (int) $value;
        if ((float) $asInt === $value) {
            return (string) $asInt;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }
    if (is_string($value)) {
        return $value;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_migrate_backup_collect_tables(PDO $pdo, ?string $dir): array
{
    $existing = jeab_migrate_backup_existing($pdo);
    $out = [];
    foreach (jeab_migrate_backup_tables() as $table) {
        if (!isset($existing[$table])) {
            $out[] = [
                'table' => $table,
                'rows' => 0,
                'bytes' => 0,
                'sha256' => '',
                'exists' => false,
                'columns' => [],
                'stored' => '',
            ];
            continue;
        }
        $columns = jeab_migrate_backup_columns($pdo, $table);
        if ($columns === []) {
            throw new RuntimeException('Tabellen gick inte att läsa.');
        }
        $dest = null;
        $stored = '';
        if ($dir !== null) {
            $stored = 'tables/' . $table . '.jsonl';
            $dest = jeab_migrate_backup_dest($dir, $stored);
            if ($dest === '' || !jeab_migrate_backup_ensure_parent($dest, $dir)) {
                throw new RuntimeException('Exporten gick inte att skriva.');
            }
        }
        $stat = jeab_migrate_backup_export($pdo, $table, $columns, $dest);
        $out[] = [
            'table' => $table,
            'rows' => $stat['rows'],
            'bytes' => $stat['bytes'],
            'sha256' => $stat['sha256'],
            'exists' => true,
            'columns' => $columns,
            'stored' => $stored,
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $files
 */
function jeab_migrate_backup_copy_files(string $dir, array $files): void
{
    foreach ($files as $file) {
        if (empty($file['exists'])) {
            continue;
        }
        $src = (string) ($file['abs'] ?? '');
        $rel = (string) ($file['path'] ?? '');
        $dest = jeab_migrate_backup_dest($dir, 'files/' . $rel);
        if ($dest === '' || $src === '' || is_link($src) || !is_file($src)) {
            throw new RuntimeException('Filen gick inte att kopiera.');
        }
        $raw = jeab_migrate_backup_read($src);
        if (!is_string($raw) || hash('sha256', $raw) !== (string) ($file['sha256'] ?? '')) {
            throw new RuntimeException('Filen gick inte att kopiera.');
        }
        if (!jeab_migrate_backup_ensure_parent($dest, $dir)) {
            throw new RuntimeException('Filen gick inte att kopiera.');
        }
        if (file_put_contents($dest, $raw) === false) {
            throw new RuntimeException('Filen gick inte att kopiera.');
        }
        chmod($dest, 0600);
        $check = hash_file('sha256', $dest);
        if ($check !== (string) ($file['sha256'] ?? '') || filesize($dest) !== strlen($raw)) {
            throw new RuntimeException('Filen gick inte att kopiera.');
        }
        unset($raw);
    }
}

/**
 * @param list<array<string, mixed>> $files
 * @param list<array<string, mixed>> $tables
 */
function jeab_migrate_backup_write_manifest(string $dir, string $stamp, int $days, array $files, array $tables): void
{
    $fileRows = [];
    foreach ($files as $file) {
        $row = [
            'path' => (string) ($file['path'] ?? ''),
            'bytes' => (int) ($file['bytes'] ?? 0),
            'count' => (int) ($file['count'] ?? 0),
            'sha256' => (string) ($file['sha256'] ?? ''),
            'exists' => !empty($file['exists']),
        ];
        if (!empty($file['exists'])) {
            $row['stored'] = 'files/' . (string) ($file['path'] ?? '');
        }
        $fileRows[] = $row;
    }
    $tableRows = [];
    foreach ($tables as $table) {
        $columns = [];
        foreach (is_array($table['columns'] ?? null) ? $table['columns'] : [] as $column) {
            $column = (string) $column;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) === 1) {
                $columns[] = $column;
            }
        }
        $row = [
            'table' => (string) ($table['table'] ?? ''),
            'rows' => (int) ($table['rows'] ?? 0),
            'bytes' => (int) ($table['bytes'] ?? 0),
            'sha256' => (string) ($table['sha256'] ?? ''),
            'exists' => !empty($table['exists']),
            'columns' => $columns,
        ];
        if (!empty($table['exists']) && (string) ($table['stored'] ?? '') !== '') {
            $row['stored'] = (string) $table['stored'];
        }
        $tableRows[] = $row;
    }
    $json = json_encode([
        'version' => 1,
        'stamp' => $stamp,
        'retention_days' => $days,
        'files' => $fileRows,
        'tables' => $tableRows,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Manifestet gick inte att skriva.');
    }
    $dest = $dir . '/' . 'manifest.json';
    if (file_put_contents($dest, $json . "\n") === false) {
        throw new RuntimeException('Manifestet gick inte att skriva.');
    }
    chmod($dest, 0600);
}

function jeab_migrate_backup_mkdir(string $dir): bool
{
    if (!jeab_migrate_backup_outside_ready()) {
        return false;
    }
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $base = rtrim(str_replace('\\', '/', jeab_migrate_backup_base()), '/');
    if ($dir !== $base && !str_starts_with($dir, $base . '/')) {
        return false;
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    if (is_link($dir) || !jeab_migrate_backup_inside(jeab_migrate_backup_outside(), $dir)) {
        return false;
    }
    $realWeb = realpath(jeab_migrate_backup_root());
    $realDir = realpath($dir);
    if ($realWeb !== false && $realDir !== false) {
        $realWeb = rtrim(str_replace('\\', '/', $realWeb), '/');
        $realDir = str_replace('\\', '/', $realDir);
        if ($realDir === $realWeb || str_starts_with($realDir, $realWeb . '/')) {
            return false;
        }
    }
    chmod($dir, 0700);
    if (is_dir($base) && !is_link($base)) {
        chmod($base, 0700);
    }

    return is_dir($dir) && is_writable($dir);
}

function jeab_migrate_backup_stamp(string $base): string
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $dt = new DateTimeImmutable('now', $tz);
    for ($i = 0; $i < 5; $i++) {
        $stamp = $dt->format('Ymd-His');
        if (!file_exists($base . '/' . $stamp)) {
            return $stamp;
        }
        $next = $dt->modify('+1 second');
        if (!$next instanceof DateTimeImmutable) {
            break;
        }
        $dt = $next;
    }

    return $dt->format('Ymd-His');
}

function jeab_migrate_backup_dest(string $dir, string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if (!jeab_migrate_backup_safe_rel($rel)) {
        return '';
    }
    $dest = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $rel;
    $root = rtrim(str_replace('\\', '/', $dir), '/');
    if (!str_starts_with($dest, $root . '/')) {
        return '';
    }

    return $dest;
}

function jeab_migrate_backup_ensure_parent(string $file, string $stampDir): bool
{
    $dir = rtrim(str_replace('\\', '/', dirname($file)), '/');
    $stop = rtrim(str_replace('\\', '/', $stampDir), '/');
    if ($dir !== $stop && !str_starts_with($dir, $stop . '/')) {
        return false;
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $current = $dir;
    while ($current === $stop || str_starts_with($current, $stop . '/')) {
        if (is_dir($current)) {
            chmod($current, 0700);
        }
        if ($current === $stop) {
            break;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }

    return is_dir($dir);
}

/** @return list<string> */
function jeab_migrate_backup_prune(string $base, int $days, string $keep): array
{
    if (!is_dir($base) || is_link($base)) {
        return [];
    }
    $cut = time() - ($days * 86400);
    $tz = new DateTimeZone('Europe/Stockholm');
    $removed = [];
    foreach (scandir($base) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === $keep) {
            continue;
        }
        if (preg_match('/^\d{8}-\d{6}$/', $name) !== 1) {
            continue;
        }
        $path = $base . '/' . $name;
        if (!is_dir($path) || is_link($path)) {
            continue;
        }
        $dt = DateTimeImmutable::createFromFormat('!Ymd-His', $name, $tz);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dt instanceof DateTimeImmutable) {
            continue;
        }
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            continue;
        }
        if ($dt->format('Ymd-His') !== $name || $dt->getTimestamp() >= $cut) {
            continue;
        }
        if (jeab_migrate_backup_rmtree($path, $base)) {
            $removed[] = $name;
        }
    }
    sort($removed);

    return $removed;
}

function jeab_migrate_backup_rmtree(string $dir, string $root): bool
{
    if (is_link($dir) || !is_dir($dir)) {
        return false;
    }
    $rootReal = realpath($root);
    $real = realpath($dir);
    if ($rootReal === false || $real === false) {
        return false;
    }
    $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
    $real = str_replace('\\', '/', $real);
    if ($real === $rootReal || !str_starts_with($real, $rootReal . '/')) {
        return false;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_link($path)) {
            unlink($path);
            continue;
        }
        if (is_dir($path)) {
            jeab_migrate_backup_rmtree($path, $root);
            continue;
        }
        $fileReal = realpath($path);
        if ($fileReal === false) {
            continue;
        }
        $fileReal = str_replace('\\', '/', $fileReal);
        if (!str_starts_with($fileReal, $rootReal . '/')) {
            continue;
        }
        unlink($path);
    }

    return rmdir($dir);
}

function jeab_migrate_backup_stamp_dir(string $katalog): string
{
    $base = jeab_migrate_backup_base();
    $raw = trim(str_replace('\\', '/', $katalog));
    if ($raw !== '' && str_starts_with($raw, '/')) {
        $real = realpath($raw);

        return jeab_migrate_backup_stamp_real($base, is_string($real) ? $real : '');
    }
    $katalog = trim($raw, '/');
    $stamp = '';
    if (preg_match('/^\d{8}-\d{6}$/', $katalog) === 1) {
        $stamp = $katalog;
    } elseif (preg_match('#^(?:privat|private)/backups/(\d{8}-\d{6})$#', $katalog, $match) === 1) {
        $stamp = $match[1];
    }
    if ($stamp === '') {
        return '';
    }
    $dir = $base . '/' . $stamp;
    if (!is_dir($dir) || is_link($dir) || !jeab_migrate_backup_inside($base, $dir)) {
        return '';
    }
    $real = realpath($dir);

    return jeab_migrate_backup_stamp_real($base, is_string($real) ? $real : '');
}

function jeab_migrate_backup_stamp_real(string $base, string $real): string
{
    if ($real === '' || !is_dir($real) || is_link($real)) {
        return '';
    }
    $real = str_replace('\\', '/', $real);
    $name = basename($real);
    if (preg_match('/^\d{8}-\d{6}$/', $name) !== 1 || !jeab_migrate_backup_inside($base, $real)) {
        return '';
    }
    $expected = realpath(rtrim(str_replace('\\', '/', $base), '/') . '/' . $name);
    if (!is_string($expected) || str_replace('\\', '/', $expected) !== $real) {
        return '';
    }

    return $real;
}

function jeab_migrate_backup_inside(string $root, string $path): bool
{
    if (is_link($path)) {
        return false;
    }
    $rootReal = realpath($root);
    $real = realpath($path);
    if ($rootReal === false || $real === false) {
        return false;
    }
    $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
    $real = str_replace('\\', '/', $real);

    return $real === $rootReal || str_starts_with($real, $rootReal . '/');
}

/**
 * @param array<string, mixed> $file
 * @return array<string, mixed>
 */
function jeab_migrate_restore_file(string $dir, array $file): array
{
    $path = (string) ($file['path'] ?? '');
    $sha = (string) ($file['sha256'] ?? '');
    $bytes = (int) ($file['bytes'] ?? 0);
    $count = (int) ($file['count'] ?? 0);
    $exists = !empty($file['exists']);
    $report = [
        'path' => $path,
        'bytes' => $bytes,
        'count' => $count,
        'sha256' => $sha,
        'match' => false,
    ];
    if (!$exists) {
        $report['match'] = $sha === '' && $bytes === 0 && $count === 0;

        return $report;
    }
    $stored = (string) ($file['stored'] ?? '');
    if (!jeab_migrate_backup_safe_rel($stored) || !str_starts_with($stored, 'files/')) {
        return $report;
    }
    $abs = $dir . '/' . $stored;
    if (!is_file($abs) || is_link($abs) || !jeab_migrate_backup_inside($dir, $abs)) {
        return $report;
    }
    $actual = hash_file('sha256', $abs);
    $size = filesize($abs);
    $report['match'] = is_string($actual)
        && $actual === $sha
        && $size === $bytes
        && preg_match('/^[a-f0-9]{64}$/', $sha) === 1;

    return $report;
}

/**
 * @param array<string, mixed> $table
 * @return array<string, mixed>
 */
function jeab_migrate_restore_table(PDO $pdo, string $dir, array $table): array
{
    $name = (string) ($table['table'] ?? '');
    $sha = (string) ($table['sha256'] ?? '');
    $rows = (int) ($table['rows'] ?? 0);
    $exists = !empty($table['exists']);
    $columns = jeab_migrate_backup_column_list($table['columns'] ?? []);
    $report = [
        'table' => $name,
        'rows' => 0,
        'live_rows' => 0,
        'sha256' => $sha,
        'live_sha256' => '',
        'match' => false,
    ];
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
        return $report;
    }
    $liveExists = isset(jeab_migrate_backup_existing($pdo)[$name]);
    if (!$exists) {
        $report['live_rows'] = $liveExists ? jeab_migrate_backup_count($pdo, $name) : 0;
        $report['match'] = !$liveExists && $rows === 0 && $sha === '';

        return $report;
    }
    $stored = (string) ($table['stored'] ?? '');
    if (!jeab_migrate_backup_safe_rel($stored) || !str_starts_with($stored, 'tables/') || !str_ends_with($stored, '.jsonl')) {
        $stored = 'tables/' . $name . '.jsonl';
    }
    $abs = jeab_migrate_restore_abs($dir, $stored);
    if ($abs === '') {
        return $report;
    }
    if ($columns === []) {
        $columns = $liveExists ? jeab_migrate_backup_columns($pdo, $name) : [];
    }
    if ($columns === []) {
        return $report;
    }
    $fileSha = hash_file('sha256', $abs);
    $loaded = jeab_migrate_restore_load($abs, $columns, $name);
    $report['rows'] = (int) ($loaded['rows'] ?? 0);
    if ($liveExists) {
        $live = jeab_migrate_backup_export($pdo, $name, $columns, null, $name);
        $report['live_rows'] = $live['rows'];
        $report['live_sha256'] = $live['sha256'];
    }
    $liveCols = $liveExists ? jeab_migrate_backup_columns($pdo, $name) : [];
    // Riktig återläsning: raderna läses in i en temporär tabell med samma struktur
    // som live-tabellen, i samma databas, och exporteras igen. Tas bort efteråt.
    $temp = $liveExists && $liveCols === $columns
        ? jeab_migrate_restore_temp($pdo, $abs, $name, $columns)
        : ['ok' => false, 'rows' => 0, 'sha256' => ''];
    $report['temp_rows'] = (int) $temp['rows'];
    $report['temp_sha256'] = (string) $temp['sha256'];
    $liveRows = (int) ($report['live_rows'] ?? -1);
    $liveSha = (string) ($report['live_sha256'] ?? '');
    $fileOk = !empty($temp['ok'])
        && (int) $temp['rows'] === $rows
        && (string) $temp['sha256'] === $sha
        && is_string($fileSha)
        && $fileSha === $sha
        && !empty($loaded['ok'])
        && (int) ($loaded['rows'] ?? -1) === $rows
        && (string) ($loaded['sha256'] ?? '') === $sha
        && $liveExists
        && $liveCols === $columns;
    if (jeab_migrate_backup_log_table($name)) {
        $report['match'] = $fileOk && $liveRows >= $rows;
        $report['log_drift'] = $report['match'] && ($liveRows !== $rows || $liveSha !== $sha);
    } else {
        $report['match'] = $fileOk && $liveRows === $rows && $liveSha === $sha;
        $report['log_drift'] = false;
    }

    return $report;
}

/** Loggar som hook och trafik skriver mellan backup och verify. */
function jeab_migrate_backup_log_table(string $table): bool
{
    return $table === 'log_lines'
        || $table === 'log_docs'
        || $table === 'app_error_webhook';
}

/**
 * @param mixed $columns
 * @return list<string>
 */
function jeab_migrate_backup_column_list(mixed $columns): array
{
    if (!is_array($columns)) {
        return [];
    }
    $out = [];
    foreach ($columns as $column) {
        $column = (string) $column;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
            return [];
        }
        $out[] = $column;
    }

    return $out;
}

function jeab_migrate_backup_count(PDO $pdo, string $table): int
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM ' . jeab_migrate_ident($pdo, $table));

    return $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : 0;
}

function jeab_migrate_restore_abs(string $dir, string $rel): string
{
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if (!jeab_migrate_backup_safe_rel($rel)) {
        return '';
    }
    $abs = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $rel;
    if (!is_file($abs) || is_link($abs) || !jeab_migrate_backup_inside($dir, $abs)) {
        return '';
    }
    $real = realpath($abs);

    return is_string($real) ? str_replace('\\', '/', $real) : '';
}

/**
 * Räknar raderna i exportfilen som backup skrev.
 * Kontrollsumman är filens egna byte, inte en ny SELECT: sqlite och MySQL
 * sorterar inte likadant, och en omsortering gav rows=0 på varje tabell.
 *
 * @param list<string> $columns
 * @return array{ok:bool,rows:int,sha256:string}
 */
function jeab_migrate_restore_load(string $path, array $columns, string $orderName): array
{
    unset($orderName);
    $fail = ['ok' => false, 'rows' => 0, 'sha256' => ''];
    if ($columns === [] || !is_file($path) || is_link($path)) {
        return $fail;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return $fail;
    }
    $rows = 0;
    while (($line = fgets($fh)) !== false) {
        $trim = rtrim($line, "\r\n");
        if ($trim === '') {
            continue;
        }
        $decoded = json_decode($trim, true);
        if (!is_array($decoded)) {
            fclose($fh);

            return $fail;
        }
        foreach ($columns as $column) {
            if (!array_key_exists($column, $decoded)) {
                fclose($fh);

                return $fail;
            }
        }
        $rows++;
    }
    fclose($fh);
    $sha = hash_file('sha256', $path);
    if (!is_string($sha)) {
        return $fail;
    }

    return ['ok' => true, 'rows' => $rows, 'sha256' => $sha];
}

/**
 * Läser backupfilen till en TEMPORARY-tabell med live-tabellens struktur
 * (MySQL: CREATE TEMPORARY TABLE ... LIKE, sqlite: CREATE TEMP TABLE AS ... WHERE 0)
 * och exporterar den igen. Tabellen tas alltid bort. Inga värden lämnar funktionen.
 *
 * @param list<string> $columns
 * @return array{ok:bool,rows:int,sha256:string}
 */
function jeab_migrate_restore_temp(PDO $pdo, string $path, string $name, array $columns): array
{
    $fail = ['ok' => false, 'rows' => 0, 'sha256' => ''];
    if ($columns === [] || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || !is_file($path) || is_link($path)) {
        return $fail;
    }
    $temp = 'jeab_restore_' . substr($name, 0, 40) . '_' . bin2hex(random_bytes(4));
    $tIdent = jeab_migrate_ident($pdo, $temp);
    $lIdent = jeab_migrate_ident($pdo, $name);
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $created = false;
    try {
        if ($mysql) {
            $pdo->exec('CREATE TEMPORARY TABLE ' . $tIdent . ' LIKE ' . $lIdent);
        } else {
            $pdo->exec('CREATE TEMP TABLE ' . $tIdent . ' AS SELECT * FROM ' . $lIdent . ' WHERE 0');
        }
        $created = true;
        $quoted = array_map(static fn (string $c): string => jeab_migrate_ident($pdo, $c), $columns);
        $insert = $pdo->prepare(
            'INSERT INTO ' . $tIdent . ' (' . implode(', ', $quoted) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return $fail;
        }
        $rows = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $trim = rtrim($line, "\r\n");
                if ($trim === '') {
                    continue;
                }
                $decoded = json_decode($trim, true);
                if (!is_array($decoded)) {
                    return $fail;
                }
                $values = [];
                foreach ($columns as $column) {
                    $values[] = array_key_exists($column, $decoded) ? jeab_migrate_backup_cell($decoded[$column]) : null;
                }
                $insert->execute($values);
                $rows++;
            }
        } finally {
            fclose($fh);
        }
        $again = jeab_migrate_backup_export($pdo, $temp, $columns, null, $name);
        if ((int) $again['rows'] !== $rows) {
            return $fail;
        }

        return ['ok' => true, 'rows' => $rows, 'sha256' => (string) $again['sha256']];
    } catch (Throwable) {
        return $fail;
    } finally {
        if ($created) {
            try {
                $pdo->exec('DROP ' . ($mysql ? 'TEMPORARY ' : '') . 'TABLE IF EXISTS ' . $tIdent);
            } catch (Throwable) {
            }
        }
    }
}

/**
 * @template T
 * @param callable(): T $fn
 * @return T
 */
function jeab_migrate_backup_read_tx(PDO $pdo, callable $fn): mixed
{
    $own = false;
    if (!(function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo))) {
        try {
            $own = $pdo->beginTransaction();
        } catch (Throwable) {
            $own = false;
        }
    }
    try {
        return $fn();
    } finally {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/**
 * @param list<array<string, mixed>> $files
 * @return list<array<string, mixed>>
 */
function jeab_migrate_backup_file_view(array $files): array
{
    $out = [];
    foreach ($files as $file) {
        $out[] = [
            'path' => (string) ($file['path'] ?? ''),
            'bytes' => (int) ($file['bytes'] ?? 0),
            'count' => (int) ($file['count'] ?? 0),
            'sha256' => (string) ($file['sha256'] ?? ''),
            'exists' => !empty($file['exists']),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $tables
 * @return list<array<string, mixed>>
 */
function jeab_migrate_backup_table_view(array $tables): array
{
    $out = [];
    foreach ($tables as $table) {
        $out[] = [
            'table' => (string) ($table['table'] ?? ''),
            'rows' => (int) ($table['rows'] ?? 0),
            'bytes' => (int) ($table['bytes'] ?? 0),
            'sha256' => (string) ($table['sha256'] ?? ''),
            'exists' => !empty($table['exists']),
        ];
    }

    return $out;
}

/** @return array<string, mixed> */
function jeab_migrate_backup_fail(string $error, bool $run): array
{
    return jeab_migrate_backup_public([
        'ok' => false,
        'mode' => $run ? 'run' : 'dry',
        'dry' => !$run,
        'written' => false,
        'error' => $error,
        'status' => 400,
    ]);
}

/** @param array<string, mixed> $value @return array<string, mixed> */
function jeab_migrate_backup_public(array $value): array
{
    $clean = jeab_migrate_backup_scrub($value);

    return is_array($clean) ? $clean : [];
}

function jeab_migrate_backup_scrub(mixed $value): mixed
{
    $allowed = [
        'ok', 'mode', 'dry', 'written', 'status', 'error', 'dir', 'manifest',
        'manifest_sha256', 'retention_days', 'files', 'tables', 'pruned', 'pruned_dirs',
        'path', 'bytes', 'count', 'rows', 'sha256', 'exists', 'match',
        'live_rows', 'live_sha256', 'table', 'mismatch', 'log_drift', 'temp_rows', 'temp_sha256',
    ];
    if (!is_array($value)) {
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (!is_string($value) || strlen($value) > 300 || strpbrk($value, "\r\n") !== false) {
            return '';
        }

        return $value;
    }
    if (array_is_list($value)) {
        $out = [];
        foreach ($value as $item) {
            $out[] = jeab_migrate_backup_scrub($item);
        }

        return $out;
    }
    $out = [];
    foreach ($value as $key => $item) {
        $key = (string) $key;
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        $out[$key] = jeab_migrate_backup_scrub($item);
    }

    return $out;
}
