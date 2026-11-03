<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/flags.php';
require_once __DIR__ . '/migrate/registry.php';

/**
 * En flagga för affärsdata: DATA_BACKEND=mysql|json, eller inställningen data_backend.
 * Är flaggan inte satt gäller de gamla per-typ-flaggorna.
 * Återställning är data_backend=json, utom typer med authoritative_sql.
 */

const JEAB_DATA_BACKEND_KEY = 'data_backend';
/** Inställningen läses inte. Bara miljövariabeln LEGACY_JSON_WRITES styr. */
const JEAB_LEGACY_JSON_WRITES_KEY = 'legacy_json_writes';
const JEAB_DATA_MAINTENANCE_KEY = 'data_maintenance';
const JEAB_DATA_MAINTENANCE_UNTIL_KEY = 'data_maintenance_until';
const JEAB_DATA_MAINTENANCE_TTL = 1800;
const JEAB_DATA_MIGRATE_BUDGET_SEC = 900;

function jeab_data_pdo(): ?PDO
{
    if (!empty($GLOBALS['_JEAB_STORE_OPENING']) || !empty($GLOBALS['_JEAB_SCHEMA_MIGRATING']) || !function_exists('jeab_store')) {
        return null;
    }
    // json-läget har ingen inställningstabell. Öppna inte butiken bara för att
    // läsa flaggan: JeabJsonStore skapar store.json i konstruktorn.
    if (function_exists('jeab_driver')) {
        $driver = jeab_driver();
        if ($driver !== 'sqlite' && $driver !== 'mysql') {
            return null;
        }
    }
    try {
        $store = jeab_store();
        return $store instanceof JeabPdoStore ? $store->pdo() : null;
    } catch (Throwable) {
        return null;
    }
}

function jeab_data_backend_stored(): string
{
    if (array_key_exists('_JEAB_DATA_BACKEND_STORED', $GLOBALS)) {
        return (string) $GLOBALS['_JEAB_DATA_BACKEND_STORED'];
    }
    if (!empty($GLOBALS['_JEAB_STORE_OPENING']) || !empty($GLOBALS['_JEAB_SCHEMA_MIGRATING'])) {
        return '';
    }
    $value = '';
    $pdo = jeab_data_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_settings_read')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $read = jeab_settings_read($pdo, $driver, JEAB_DATA_BACKEND_KEY);
        if (!empty($read['busy'])) {
            return 'mysql';
        }
        $got = $read['value'] ?? null;
        if ($got === 'mysql' || $got === 'json') {
            $value = $got;
        }
    } elseif ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $got = jeab_settings_value($pdo, $driver, JEAB_DATA_BACKEND_KEY);
        if ($got === 'mysql' || $got === 'json') {
            $value = $got;
        }
    }
    if ($value === '') {
        $file = jeab_data_backend_file_value();
        if ($file === 'mysql' || $file === 'json') {
            $value = $file;
        }
    }
    $GLOBALS['_JEAB_DATA_BACKEND_STORED'] = $value;
    return $value;
}

function jeab_data_backend_file_path(): string
{
    $dir = '';
    $override = $GLOBALS['_JEAB_PRIVAT_DIR'] ?? null;
    if (is_string($override) && $override !== '') {
        $dir = rtrim(str_replace('\\', '/', $override), '/');
    } elseif (function_exists('jeab_flags_dir')) {
        $flags = jeab_flags_dir();
        if (is_string($flags) && $flags !== '') {
            $dir = rtrim(str_replace('\\', '/', $flags), '/');
        }
    }
    if ($dir === '') {
        $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
        $dir = rtrim(str_replace('\\', '/', dirname($root) . '/privat'), '/');
    }

    return $dir . '/data-backend.txt';
}

function jeab_data_backend_file_value(): string
{
    $path = jeab_data_backend_file_path();
    if (!is_file($path)) {
        return '';
    }
    $raw = strtolower(trim((string) file_get_contents($path)));

    return $raw === 'mysql' || $raw === 'json' ? $raw : '';
}

function jeab_data_backend_explicit(): bool
{
    $forced = $GLOBALS['_JEAB_DATA_BACKEND'] ?? null;
    if ($forced === 'mysql' || $forced === 'json') {
        return true;
    }
    $env = getenv('DATA_BACKEND');
    if ($env === 'mysql' || $env === 'json') {
        return true;
    }
    return jeab_data_backend_stored() !== '';
}

function jeab_data_backend(): string
{
    $forced = $GLOBALS['_JEAB_DATA_BACKEND'] ?? null;
    if ($forced === 'mysql' || $forced === 'json') {
        return $forced;
    }
    $env = getenv('DATA_BACKEND');
    if ($env === 'mysql' || $env === 'json') {
        return $env;
    }
    $stored = jeab_data_backend_stored();
    return $stored === 'mysql' ? 'mysql' : 'json';
}

function jeab_data_backend_set(string $mode): bool
{
    if ($mode !== 'mysql' && $mode !== 'json') {
        return false;
    }
    $pdo = jeab_data_pdo();
    $ok = false;
    if ($pdo instanceof PDO && function_exists('jeab_settings_put')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $ok = jeab_settings_put($pdo, $driver, JEAB_DATA_BACKEND_KEY, $mode);
    }
    if (!$ok) {
        $path = jeab_data_backend_file_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $ok = is_dir($dir) && file_put_contents($path, $mode . "\n", LOCK_EX) !== false;
    }
    unset($GLOBALS['_JEAB_DATA_BACKEND_STORED']);
    return $ok;
}

function jeab_data_backend_wants_dry(array $input): bool
{
    if (function_exists('jeab_kontakt_dry')) {
        return jeab_kontakt_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/**
 * data.backend_set. dry=1 visar current och would utan att skriva.
 * backend=json är vägen tillbaka från mysql.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_data_backend_set_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $dry = jeab_data_backend_wants_dry($input);
    $backend = strtolower(trim((string) ($input['backend'] ?? '')));
    $current = jeab_data_backend_stored();
    if ($backend !== 'json' && $backend !== 'mysql') {
        return [
            'ok' => false,
            'error' => 'backend ska vara json eller mysql.',
            'status' => 400,
            'dry' => $dry,
            'written' => false,
            'current' => $current,
            'would' => null,
        ];
    }
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'written' => false,
            'current' => $current,
            'would' => $backend,
            'status' => 200,
        ];
    }
    if (!jeab_data_backend_set($backend)) {
        return [
            'ok' => false,
            'error' => 'Inställningen kunde inte sparas.',
            'status' => 400,
            'dry' => false,
            'written' => false,
            'current' => $current,
            'would' => $backend,
        ];
    }

    return [
        'ok' => true,
        'dry' => false,
        'written' => true,
        'current' => $current,
        'would' => $backend,
        'value' => jeab_data_backend_stored(),
        'status' => 200,
    ];
}

function jeab_data_backend_clear(): bool
{
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $col = $driver === 'mysql' ? '`key`' : '"key"';
    try {
        $pdo->prepare('DELETE FROM settings WHERE ' . $col . ' = ?')->execute([JEAB_DATA_BACKEND_KEY]);
    } catch (Throwable) {
        return false;
    }
    if (function_exists('jeab_settings_cache_forget')) {
        jeab_settings_cache_forget($pdo, $driver, JEAB_DATA_BACKEND_KEY);
    }
    unset($GLOBALS['_JEAB_DATA_BACKEND_STORED']);
    return true;
}

function jeab_data_authoritative_sql(string $type): bool
{
    $module = jeab_migrate_find($type);
    $fn = is_array($module) ? ($module['authoritative_sql'] ?? '') : '';
    return is_string($fn) && function_exists($fn) && $fn();
}

function jeab_data_storage(string $type, string $legacy): string
{
    if (jeab_data_authoritative_sql($type)) {
        return 'sql';
    }
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('json', $type)) {
        return 'json';
    }
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', $type)) {
        return 'sql';
    }
    if (!jeab_data_backend_explicit()) {
        return $legacy === 'sql' ? 'sql' : 'json';
    }
    return jeab_data_backend() === 'mysql' ? 'sql' : 'json';
}

/**
 * LEGACY_JSON_WRITES är av som standard. $GLOBALS, miljövariabeln och
 * privat/flags.json styr, i den ordningen. En rad i settings ändrar inget.
 * Av och typen explicit i SQL med grön verify skriver ingen fil.
 * Av utan det skriver filen. 1 i filen slår på skrivningen igen. Ingen rename och ingen radering.
 */
function jeab_legacy_json_writes_env_raw(): ?string
{
    return jeab_flag_env_raw('LEGACY_JSON_WRITES');
}

function jeab_legacy_json_writes_env(): ?bool
{
    return jeab_flag_env_bool('LEGACY_JSON_WRITES');
}

/** @return array{env:?string,effective:bool} */
function jeab_legacy_json_writes_state(): array
{
    return [
        'env' => jeab_legacy_json_writes_env_raw(),
        'effective' => jeab_legacy_json_writes(),
    ];
}

function jeab_legacy_json_writes(): bool
{
    return jeab_flag('LEGACY_JSON_WRITES');
}

/**
 * Typen är SQL bara när jeab_data_storage säger sql och senaste verify är grön.
 * Verify är svepets lista (samma request) eller senaste klara data.migrate_all.
 * Saknas den är typen inte i SQL-läge och JSON skrivs.
 */
function jeab_legacy_json_marked_sql(string $type): bool
{
    switch ($type) {
        case 'store':
        case 'customers':
        case 'bookings':
        case 'reminders':
        case 'reminder_lists':
            return function_exists('jeab_store_storage_mode') && jeab_store_storage_mode() === 'sql';
        case 'purchases':
        case 'orders':
            return function_exists('jeab_purchases_storage_mode') && jeab_purchases_storage_mode() === 'sql';
        case 'ordrar_deleted':
            return function_exists('jeab_ordrar_deleted_storage_mode') && jeab_ordrar_deleted_storage_mode() === 'sql';
        case 'contacts':
        case 'kontakter':
        case 'inkorg_contacts':
            if (function_exists('jeab_kontakter_storage_mode') && jeab_kontakter_storage_mode() === 'sql') {
                return true;
            }
            $id = $type === 'inkorg_contacts' ? 'inkorg_contacts' : 'contacts';

            return function_exists('jeab_data_storage') && jeab_data_storage($id, 'json') === 'sql';
        case 'calendar_log':
            if (!empty($GLOBALS['_JEAB_CALENDAR_LOG_PATH'])) {
                return false;
            }

            return function_exists('jeab_data_storage') && jeab_data_storage('calendar_log', 'json') === 'sql';
        default:
            return function_exists('jeab_data_storage') && jeab_data_storage($type, 'json') === 'sql';
    }
}

/** @return list<string> */
function jeab_legacy_json_verify_ids(string $type): array
{
    switch ($type) {
        case 'store':
            return ['customers', 'bookings', 'reminders', 'reminder_lists'];
        case 'orders':
            return ['purchases'];
        case 'kontakter':
            return ['contacts'];
        default:
            return [$type];
    }
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, bool>
 */
function jeab_legacy_json_verify_index_from(array $rows): array
{
    $index = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['type'] ?? '');
        if ($id === 'orders') {
            $id = 'purchases';
        } elseif ($id === 'kontakter') {
            $id = 'contacts';
        }
        if ($id === '') {
            continue;
        }
        $index[$id] = !empty($row['ok']) && !empty($row['match']);
    }

    return $index;
}

/** @return list<array<string, mixed>> */
function jeab_legacy_json_verify_rows_from_job(): array
{
    if (!empty($GLOBALS['_JEAB_STORE_OPENING']) || !empty($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_READING'])) {
        return [];
    }
    $GLOBALS['_JEAB_LEGACY_JSON_VERIFY_READING'] = true;
    try {
        $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
        if (!$pdo instanceof PDO) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT result_json FROM data_jobs WHERE job_type = ? AND status = ? ORDER BY updated_at DESC, job_id DESC LIMIT 1'
        );
        $stmt->execute(['data.migrate_all', 'done']);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        $verify = is_array($decoded) ? ($decoded['verify'] ?? null) : null;

        return is_array($verify) ? array_values(array_filter($verify, 'is_array')) : [];
    } catch (Throwable) {
        return [];
    } finally {
        unset($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_READING']);
    }
}

/** @return array<string, bool> */
function jeab_legacy_json_verify_index(): array
{
    if (isset($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX']) && is_array($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX'])) {
        return $GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX'];
    }
    if (!empty($GLOBALS['_JEAB_STORE_OPENING']) || !empty($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_READING'])) {
        return [];
    }
    if (isset($GLOBALS['_JEAB_DATA_MIGRATE_VERIFY']) && is_array($GLOBALS['_JEAB_DATA_MIGRATE_VERIFY'])) {
        $rows = $GLOBALS['_JEAB_DATA_MIGRATE_VERIFY'];
    } else {
        $rows = jeab_legacy_json_verify_rows_from_job();
    }
    $index = jeab_legacy_json_verify_index_from(is_array($rows) ? $rows : []);
    $GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX'] = $index;

    return $index;
}

function jeab_legacy_json_verify_green(string $type): bool
{
    $index = jeab_legacy_json_verify_index();
    if ($index === []) {
        return false;
    }
    foreach (jeab_legacy_json_verify_ids($type) as $id) {
        if ($id === 'orders') {
            $id = 'purchases';
        } elseif ($id === 'kontakter') {
            $id = 'contacts';
        }
        if (empty($index[$id])) {
            return false;
        }
    }

    return true;
}

function jeab_legacy_json_type_sql(string $type): bool
{
    if (!jeab_legacy_json_marked_sql($type)) {
        return false;
    }

    return jeab_legacy_json_verify_green($type);
}

/** Flaggan av och typen i SQL-läge: hoppa över JSON-filen. */
function jeab_legacy_json_skip(string $type): bool
{
    if (jeab_legacy_json_writes()) {
        return false;
    }

    return jeab_legacy_json_type_sql($type);
}

function jeab_data_checksum(array $rows): string
{
    if (!array_is_list($rows)) {
        ksort($rows);
    }
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/** @return array<string, mixed> */
function jeab_data_sql_missing(string $id): never
{
    if (($id === 'purchases' || $id === 'orders') && function_exists('jeab_purchase_sql_fail')) {
        jeab_purchase_sql_fail();
    }
    if (function_exists('jeab_inkorg_sql_fail')) {
        jeab_inkorg_sql_fail($id !== '' ? $id : 'data');
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error($id !== '' ? $id : 'data', 'SQL saknas');
    }
    throw new RuntimeException('SQL saknas');
}

/** @param array<string, mixed> $module @return array<string, mixed> */
function jeab_data_read_sql_only(array $module): array
{
    $id = (string) ($module['id'] ?? '');
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        jeab_data_sql_missing($id);
    }
    try {
        $selected = jeab_migrate_select($pdo, $module);
    } catch (Throwable) {
        jeab_data_sql_missing($id);
    }
    if (!is_array($selected)) {
        jeab_data_sql_missing($id);
    }

    return jeab_migrate_key_rows($selected, (string) ($module['primary_key'] ?? ''));
}

function jeab_data_read(string $type): array
{
    $module = jeab_migrate_find($type);
    if (!is_array($module) || !empty($module['missing'])) {
        return [];
    }
    if (jeab_migrate_json_retired((string) ($module['id'] ?? $type))) {
        return jeab_data_read_sql_only($module);
    }
    $fn = $module['read'] ?? '';
    if (is_string($fn) && function_exists($fn)) {
        $rows = $fn();
        return is_array($rows) ? $rows : [];
    }
    $rowsFn = $module['rows'] ?? '';
    $mapped = [];
    if (is_string($rowsFn) && function_exists($rowsFn)) {
        $fromJson = $rowsFn(jeab_migrate_read_source($module));
        $mapped = is_array($fromJson) ? $fromJson : [];
    }
    if (jeab_data_storage($type, 'json') === 'sql') {
        $pk = (string) ($module['primary_key'] ?? '');
        $fallback = jeab_migrate_key_rows($mapped, $pk);
        $jsonFallback = function_exists('jeab_cutover_json_fallback_type') && jeab_cutover_json_fallback_type($type);
        if ($type === 'inkorg_views' && !(function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('inkorg_views_json'))) {
            $jsonFallback = false;
        }
        $pdo = jeab_data_pdo();
        if (!$pdo instanceof PDO) {
            return $jsonFallback ? $fallback : [];
        }
        try {
            if ($jsonFallback && function_exists('jeab_cutover_type_boot') && !jeab_cutover_type_boot($type)) {
                return $fallback;
            }
            $selected = jeab_migrate_select_owned($pdo, $module, $mapped);
        } catch (Throwable) {
            return $jsonFallback ? $fallback : [];
        }

        return jeab_migrate_key_rows($selected, $pk);
    }
    return jeab_migrate_key_rows($mapped, (string) ($module['primary_key'] ?? ''));
}

/** @param array<string, mixed> $rows */
function jeab_data_write(string $type, array $rows): void
{
    jeab_data_write_guard();
    $module = jeab_migrate_find($type);
    if (!is_array($module) || !empty($module['missing'])) {
        throw new RuntimeException('Typen kan inte skrivas.');
    }
    $fn = $module['write'] ?? '';
    if (is_string($fn) && function_exists($fn)) {
        $fn($rows);
        return;
    }
    if (jeab_data_storage($type, 'json') === 'sql') {
        $pdo = jeab_data_pdo();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Ingen SQL-databas.');
        }
        $list = array_is_list($rows) ? $rows : array_values($rows);
        jeab_migrate_ensure($pdo, $module);
        jeab_migrate_upsert($pdo, $module, $list);
        return;
    }
    if (jeab_migrate_json_retired((string) ($module['id'] ?? $type))) {
        throw new RuntimeException('retired: SQL is sole source');
    }
    jeab_migrate_write_json($module, $rows);
}

/**
 * Underhållsflaggan läses en gång per request.
 * $remember uppdaterar cachen när flaggan skrivs, så ett senare unset av
 * _JEAB_DATA_MAINTENANCE fortfarande ser värdet som just sparades.
 * Flaggan har en TTL (data_maintenance_until). En död process släpper
 * spärren när tiden gått ut, även om finally inte hann köras.
 */
function jeab_data_maintenance(?bool $remember = null): bool
{
    static $cached = null;
    static $until = 0;
    static $reading = false;
    if (!empty($GLOBALS['_JEAB_DATA_MAINTENANCE_REREAD'])) {
        unset($GLOBALS['_JEAB_DATA_MAINTENANCE_REREAD']);
        $cached = null;
        $until = 0;
    }
    if ($remember !== null) {
        $cached = $remember;
        $until = $remember ? time() + JEAB_DATA_MAINTENANCE_TTL : 0;
        return $cached;
    }
    if (array_key_exists('_JEAB_DATA_MAINTENANCE', $GLOBALS)) {
        $cached = (bool) $GLOBALS['_JEAB_DATA_MAINTENANCE'];
        return $cached;
    }
    if ($cached === true && $until > 0 && time() > $until) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }
    if ($reading || !empty($GLOBALS['_JEAB_STORE_OPENING'])) {
        return false;
    }
    $reading = true;
    try {
        $pdo = jeab_data_pdo();
        if (!$pdo instanceof PDO || !function_exists('jeab_settings_value')) {
            return false;
        }
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $flag = jeab_settings_value($pdo, $driver, JEAB_DATA_MAINTENANCE_KEY) === '1';
        $rawUntil = jeab_settings_value($pdo, $driver, JEAB_DATA_MAINTENANCE_UNTIL_KEY);
        $until = is_string($rawUntil) && $rawUntil !== '' ? (int) $rawUntil : 0;
        $cached = $flag && $until >= time();
        if (!$cached) {
            $until = 0;
        }
        return $cached;
    } finally {
        $reading = false;
    }
}

function jeab_data_maintenance_set(bool $on): bool
{
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $stamp = $on ? (string) (time() + JEAB_DATA_MAINTENANCE_TTL) : '0';
    $okUntil = jeab_settings_put($pdo, $driver, JEAB_DATA_MAINTENANCE_UNTIL_KEY, $stamp);
    $ok = jeab_settings_put($pdo, $driver, JEAB_DATA_MAINTENANCE_KEY, $on ? '1' : '0');
    $GLOBALS['_JEAB_DATA_MAINTENANCE'] = $on;
    jeab_data_maintenance($on);
    return $ok && $okUntil;
}

/** Förlänger TTL medan ett svep lever. Andra requests ser nya tiden när deras cache gått ut. */
function jeab_data_maintenance_touch(): void
{
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_settings_put($pdo, $driver, JEAB_DATA_MAINTENANCE_UNTIL_KEY, (string) (time() + JEAB_DATA_MAINTENANCE_TTL));
}

function jeab_data_maintenance_arm_shutdown(): void
{
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;
    register_shutdown_function(static function (): void {
        if (function_exists('jeab_finish_http_response')) {
            jeab_finish_http_response();
        }
        if (empty($GLOBALS['_JEAB_DATA_JOB_HOLDING_MAINTENANCE'])) {
            return;
        }
        $previous = !empty($GLOBALS['_JEAB_DATA_JOB_MAINTENANCE_WAS']);
        $GLOBALS['_JEAB_DATA_JOB_HOLDING_MAINTENANCE'] = false;
        jeab_data_maintenance_set($previous);
        unset($GLOBALS['_JEAB_DATA_MAINTENANCE'], $GLOBALS['_JEAB_DATA_MIGRATE']);
    });
}

function jeab_data_write_guard(): void
{
    if (!empty($GLOBALS['_JEAB_DATA_MIGRATE'])) {
        return;
    }
    if (jeab_data_maintenance()) {
        throw new RuntimeException('Underhåll pågår. Försök igen om en stund.');
    }
}

function jeab_data_budget(float $started): void
{
    if ((microtime(true) - $started) > JEAB_DATA_MIGRATE_BUDGET_SEC) {
        throw new RuntimeException('Svepet passerade 15 minuter.');
    }
}

/**
 * @return array{counts:array<string,int>,links:list<array{entity:string,id:string,contact_id:string}>}
 */
function jeab_data_link_report(?JeabStore $store): array
{
    require_once __DIR__ . '/kontakter_conflicts.php';
    $links = jeab_kontakter_conflicts_live_links($store);
    $ids = jeab_kontakter_conflicts_id_set(jeab_kontakter_conflicts_rows());
    return [
        'counts' => jeab_kontakter_conflicts_census($links, $ids),
        'links' => $links,
    ];
}

/** @param array{counts:array<string,int>,links:list<array{entity:string,id:string,contact_id:string}>} $before @param array{counts:array<string,int>,links:list<array{entity:string,id:string,contact_id:string}>} $after */
function jeab_data_link_changed(array $before, array $after): string
{
    require_once __DIR__ . '/kontakter_conflicts.php';
    return jeab_kontakter_conflicts_guard_reason($before['links'], $after['links'], $before['counts'], $after['counts']);
}

/**
 * Ett steg är en typ, en kontroll eller en avslutande fas.
 * Tillståndet kan sparas så att ett avbrutet svep fortsätter där det slutade.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_data_migrate_initial(array $input): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;

    return [
        'done' => false,
        'released' => false,
        'phase' => 'prepare',
        'index' => 0,
        'cursor_type' => '',
        'dry' => $dry,
        'started' => microtime(true),
        'errors' => [],
        'migrated' => [],
        'verified' => [],
        'conflicts' => ['ok' => true, 'dry' => $dry, 'written' => false, 'rolled_back' => false, 'counts' => [], 'error' => ''],
        'links' => ['before' => [], 'after' => []],
        'link_before' => null,
        'references' => ['checked' => 0, 'unknown' => 0, 'bookings' => 0, 'reminders' => 0, 'orders' => 0],
        'held' => false,
        'maintenance_was' => false,
        'backend_before' => jeab_data_backend_explicit() ? jeab_data_backend() : '',
        'flipped' => false,
    ];
}

/** @param array<string, mixed> $state */
function jeab_data_migrate_progress(array $state): array
{
    $run = is_array($state['conflicts_run'] ?? null) ? $state['conflicts_run'] : null;
    $progress = [
        'phase' => (string) ($state['phase'] ?? ''),
        'index' => $run !== null ? (int) ($run['cursor'] ?? 0) : (int) ($state['index'] ?? 0),
        'type' => $run !== null ? 'conflicts' : (string) ($state['cursor_type'] ?? ''),
        'done' => !empty($state['done']),
    ];
    if ($run !== null) {
        $progress['cursor'] = (int) ($run['cursor'] ?? 0);
        $progress['limit'] = (int) ($run['limit'] ?? 0);
        $progress['total'] = (int) ($run['total'] ?? 0);
    }

    return $progress;
}

/** @param array<string, mixed> $state */
function jeab_data_migrate_release(array &$state): void
{
    if (!empty($state['released'])) {
        return;
    }
    $state['released'] = true;
    $GLOBALS['_JEAB_DATA_JOB_HOLDING_MAINTENANCE'] = false;
    unset($GLOBALS['_JEAB_DATA_MIGRATE']);
    if (!empty($state['held'])) {
        jeab_data_maintenance_set(!empty($state['maintenance_was']));
        unset($GLOBALS['_JEAB_DATA_MAINTENANCE']);
    }
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function jeab_data_migrate_response(array $state): array
{
    $dry = !empty($state['dry']);
    $errors = is_array($state['errors'] ?? null) ? array_values($state['errors']) : [];
    $migrated = is_array($state['migrated'] ?? null) ? $state['migrated'] : [];
    $verified = is_array($state['verified'] ?? null) ? $state['verified'] : [];
    if ($verified !== []) {
        $GLOBALS['_JEAB_DATA_MIGRATE_VERIFY'] = $verified;
        unset($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX']);
    }
    $conflicts = is_array($state['conflicts'] ?? null) ? $state['conflicts'] : [];
    $ready = $errors === [];
    foreach ($verified as $row) {
        if (is_array($row) && empty($row['match'])) {
            $ready = false;
        }
    }
    $started = (float) ($state['started'] ?? microtime(true));
    $response = [
        'ok' => $dry ? $errors === [] : ($errors === [] && !empty($state['flipped'])),
        'dry' => $dry,
        'ready' => $ready && $errors === [],
        'written' => !empty($state['flipped']),
        'backend' => jeab_data_backend(),
        'backend_explicit' => jeab_data_backend_explicit(),
        'legacy_json_writes' => jeab_legacy_json_writes_state(),
        'maintenance' => jeab_data_maintenance(),
        'budget_sec' => JEAB_DATA_MIGRATE_BUDGET_SEC,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'migrate' => $migrated,
        'verify' => $verified,
        'conflicts' => [
            'ok' => !empty($conflicts['ok']),
            'dry' => !empty($conflicts['dry']),
            'written' => !empty($conflicts['written']),
            'rolled_back' => !empty($conflicts['rolled_back']),
            'counts' => is_array($conflicts['counts'] ?? null) ? $conflicts['counts'] : [],
            'error' => (string) ($conflicts['error'] ?? ''),
        ],
        'links' => is_array($state['links'] ?? null) ? $state['links'] : ['before' => [], 'after' => []],
        'references' => is_array($state['references'] ?? null) ? $state['references'] : ['checked' => 0, 'unknown' => 0, 'bookings' => 0, 'reminders' => 0, 'orders' => 0],
        'totals' => jeab_data_migrate_totals($migrated, $verified),
        'errors' => $errors,
    ];

    return jeab_data_public($response);
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function jeab_data_migrate_finish(array $state): array
{
    if (!empty($state['flipped']) && is_array($state['errors'] ?? null) && $state['errors'] !== []) {
        jeab_data_restore_backend((string) ($state['backend_before'] ?? ''));
        $state['flipped'] = false;
    }
    jeab_data_migrate_release($state);
    $state['done'] = true;
    $state['phase'] = 'done';
    $state['result'] = jeab_data_migrate_response($state);

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function jeab_data_migrate_step(array $state, ?JeabStore $store = null): array
{
    if (!empty($state['done'])) {
        return $state;
    }
    $dry = !empty($state['dry']);
    try {
        $phase = (string) ($state['phase'] ?? 'prepare');
        if ($phase !== 'prepare') {
            jeab_data_budget((float) ($state['started'] ?? microtime(true)));
        }
        if ($phase === 'prepare') {
            $state['maintenance_was'] = jeab_data_maintenance();
            if (!$dry) {
                if (!jeab_data_maintenance_set(true)) {
                    throw new RuntimeException('Underhållsläget gick inte att slå på.');
                }
                $state['held'] = true;
                $GLOBALS['_JEAB_DATA_MIGRATE'] = true;
                $GLOBALS['_JEAB_DATA_JOB_HOLDING_MAINTENANCE'] = true;
                $GLOBALS['_JEAB_DATA_JOB_MAINTENANCE_WAS'] = !empty($state['maintenance_was']);
                jeab_data_maintenance_arm_shutdown();
                $importPdo = jeab_data_pdo();
                if ($importPdo instanceof PDO && function_exists('jeab_mysql_import_json_leftovers')) {
                    jeab_mysql_import_json_leftovers($importPdo, true);
                }
            }
            $state['phase'] = 'copy';
            $state['index'] = 0;
            return $state;
        }
        $registry = array_values(jeab_migrate_registry());
        $count = count($registry);
        if ($phase === 'copy' || $phase === 'verify') {
            $index = (int) ($state['index'] ?? 0);
            if ($index >= $count) {
                $state['phase'] = $phase === 'copy' ? 'verify' : 'references';
                $state['index'] = 0;
                $state['cursor_type'] = '';
                return $state;
            }
            $module = $registry[$index];
            $type = (string) ($module['type'] ?? '');
            $state['index'] = $index + 1;
            $state['cursor_type'] = $type;
            if ($phase === 'copy') {
                if (!empty($module['missing'])) {
                    $message = (string) ($module['error'] ?? 'Modulen saknas.');
                    $state['errors'][] = $type . ': ' . $message;
                    $state['migrated'][] = jeab_migrate_shape($type, ['ok' => false, 'dry' => $dry, 'error' => $message]);
                    return $state;
                }
                $migratedRow = jeab_migrate_copy($module, $dry);
                $state['migrated'][] = $migratedRow;
                if (empty($migratedRow['ok'])) {
                    $message = (string) ($migratedRow['error'] ?? '');
                    $state['errors'][] = $type . ': ' . ($message !== '' ? $message : 'migreringen misslyckades.');
                }
                return $state;
            }
            if (!empty($module['missing'])) {
                return $state;
            }
            $row = jeab_migrate_copy($module, true);
            if (($GLOBALS['_JEAB_DATA_MIGRATE_FAIL'] ?? '') === $type) {
                $row['ok'] = false;
                $row['match'] = false;
                $row['error'] = 'Kontrollen stoppades.';
            }
            $state['verified'][] = $row;
            if (!$dry && (empty($row['ok']) || empty($row['match']))) {
                $message = (string) ($row['error'] ?? '');
                $state['errors'][] = $type . ': ' . ($message !== '' ? $message : 'antal eller checksumma stämmer inte.');
            }
            return $state;
        }
        if ($phase === 'references') {
            $state['cursor_type'] = 'references';
            $state['references'] = jeab_data_reference_report();
            if (!$dry && (int) ($state['references']['unknown'] ?? 0) > 0) {
                $state['errors'][] = 'En order, bokning eller påminnelse pekar på en okänd kontakt.';
            }
            $state['phase'] = 'links_before';
            return $state;
        }
        if ($phase === 'links_before') {
            $state['cursor_type'] = 'links';
            $before = jeab_data_link_report($store instanceof JeabStore ? $store : null);
            $state['link_before'] = $before;
            $state['links']['before'] = $before['counts'];
            $state['phase'] = 'conflicts';
            return $state;
        }
        if ($phase === 'conflicts') {
            $state['cursor_type'] = 'conflicts';
            $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
            if (!($dry || $errors === [])) {
                unset($state['conflicts_run']);
                $state['phase'] = 'links_after';
                return $state;
            }
            require_once __DIR__ . '/kontakter_conflicts.php';
            if (!is_array($state['conflicts_run'] ?? null)) {
                $state['conflicts_run'] = jeab_kontakter_conflicts_initial(['dry' => $dry ? 1 : 0]);
            }
            $state['conflicts_run'] = jeab_kontakter_conflicts_chunk(
                $state['conflicts_run'],
                $store instanceof JeabStore ? $store : null
            );
            $run = is_array($state['conflicts_run']) ? $state['conflicts_run'] : [];
            $state['index'] = (int) ($run['cursor'] ?? 0);
            if (empty($run['done'])) {
                return $state;
            }
            $full = is_array($run['result'] ?? null) ? $run['result'] : [];
            $state['conflicts'] = [
                'ok' => !empty($full['ok']),
                'dry' => !empty($full['dry']),
                'written' => !empty($full['written']),
                'rolled_back' => !empty($full['rolled_back']),
                'counts' => is_array($full['counts'] ?? null) ? $full['counts'] : [],
                'error' => (string) ($full['error'] ?? ''),
            ];
            unset($state['conflicts_run']);
            if (function_exists('jeab_kontakter_conflicts_cache_clear')) {
                jeab_kontakter_conflicts_cache_clear();
            }
            if (!$dry && (!empty($full['rolled_back']) || empty($full['ok']))) {
                $state['errors'][] = 'Konflikter: ' . ((string) ($full['error'] ?? '') !== '' ? (string) $full['error'] : 'stoppades.');
            }
            $state['phase'] = 'links_after';
            return $state;
        }
        if ($phase === 'links_after') {
            $state['cursor_type'] = 'links';
            $after = jeab_data_link_report($store instanceof JeabStore ? $store : null);
            $state['links']['after'] = $after['counts'];
            $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
            if (!$dry && $errors === []) {
                $before = is_array($state['link_before'] ?? null) ? $state['link_before'] : ['counts' => [], 'links' => []];
                $linkError = jeab_data_link_changed($before, $after);
                if ($linkError !== '') {
                    $state['errors'][] = $linkError;
                }
            }
            $state['phase'] = 'flag';
            return $state;
        }
        if ($phase === 'flag') {
            $state['cursor_type'] = 'flag';
            $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
            if (!$dry && $errors === []) {
                if (!jeab_data_backend_set('mysql')) {
                    $state['errors'][] = 'Flaggan gick inte att slå på.';
                } else {
                    $state['flipped'] = true;
                }
            }
            return jeab_data_migrate_finish($state);
        }
    } catch (Throwable $e) {
        $state['errors'][] = $e->getMessage();
        if (!empty($state['flipped'])) {
            jeab_data_restore_backend((string) ($state['backend_before'] ?? ''));
            $state['flipped'] = false;
        }
        return jeab_data_migrate_finish($state);
    }

    return jeab_data_migrate_finish($state);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_data_migrate_all(array $input, ?JeabStore $store = null): array
{
    if (!$store instanceof JeabStore && function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
    }
    $state = jeab_data_migrate_initial($input);
    try {
        do {
            $state = jeab_data_migrate_step($state, $store);
        } while (empty($state['done']));
    } finally {
        if (empty($state['released'])) {
            jeab_data_migrate_release($state);
        }
    }

    return is_array($state['result'] ?? null) ? $state['result'] : jeab_data_migrate_response($state);
}

/**
 * @return array{checked:int,unknown:int,bookings:int,reminders:int,orders:int}
 */
function jeab_data_reference_report(): array
{
    $report = ['checked' => 0, 'unknown' => 0, 'bookings' => 0, 'reminders' => 0, 'orders' => 0];
    $ids = [];
    if (function_exists('jeab_kontakter_load')) {
        foreach (jeab_kontakter_load() as $id => $row) {
            if (is_array($row)) {
                $ids[(string) ($row['id'] ?? $id)] = true;
            }
        }
    }
    foreach (['bookings' => 'bookings', 'reminders' => 'reminders'] as $type => $bucket) {
        $module = jeab_migrate_find($type);
        $rowsFn = is_array($module) ? ($module['rows'] ?? '') : '';
        if (!is_string($rowsFn) || !function_exists($rowsFn) || !is_array($module)) {
            continue;
        }
        $mapped = $rowsFn(jeab_migrate_read_source($module));
        if (!is_array($mapped)) {
            continue;
        }
        foreach ($mapped as $row) {
            if (!is_array($row)) {
                continue;
            }
            $contactId = trim((string) ($row['contact_id'] ?? ''));
            if ($contactId === '' && isset($row['payload']) && is_string($row['payload'])) {
                $payload = json_decode($row['payload'], true);
                if (is_array($payload)) {
                    $contactId = trim((string) ($payload['contact_id'] ?? ''));
                }
            }
            if ($contactId === '') {
                continue;
            }
            $report['checked']++;
            $report[$bucket]++;
            if (!isset($ids[$contactId])) {
                $report['unknown']++;
            }
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $contactId = trim((string) ($row['contact_id'] ?? ''));
            if ($contactId === '') {
                continue;
            }
            $report['checked']++;
            $report['orders']++;
            if (!isset($ids[$contactId])) {
                $report['unknown']++;
            }
        }
    }
    return $report;
}

/**
 * @param list<array<string, mixed>> $migrated
 * @param list<array<string, mixed>> $verified
 * @return array{types:int,json_rows:int,sql_rows:int,matched:int,skipped:int,unmatched:int}
 */
function jeab_data_migrate_totals(array $migrated, array $verified): array
{
    $totals = ['types' => count($verified), 'json_rows' => 0, 'sql_rows' => 0, 'matched' => 0, 'skipped' => 0, 'unmatched' => 0];
    foreach ($verified as $row) {
        if (!is_array($row)) {
            continue;
        }
        $totals['json_rows'] += (int) ($row['json_count'] ?? 0);
        $totals['sql_rows'] += (int) ($row['sql_count'] ?? 0);
        if (!empty($row['skipped'])) {
            $totals['skipped']++;
        } elseif (!empty($row['match'])) {
            $totals['matched']++;
        } else {
            $totals['unmatched']++;
        }
    }
    if ($totals['types'] === 0) {
        $totals['types'] = count($migrated);
    }
    return $totals;
}

function jeab_data_restore_backend(string $previous): void
{
    if ($previous === 'mysql' || $previous === 'json') {
        jeab_data_backend_set($previous);
        return;
    }
    jeab_data_backend_clear();
}

/** @param array<string, mixed> $response @return array<string, mixed> */
function jeab_data_public(array $response): array
{
    $drop = ['samples', 'before_json', 'after_json', 'personnummer', 'pnr', 'payload'];
    $walk = static function (mixed $value) use (&$walk, $drop): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, $drop, true)) {
                continue;
            }
            $out[$key] = $walk($item);
        }
        return $out;
    };
    $clean = $walk($response);
    return is_array($clean) ? $clean : $response;
}

function jeab_data_type_storage_mode(string $id): string
{
    if ($id === 'inbox' || $id === 'inbox_threads') {
        return function_exists('jeab_inkorg_storage_mode') ? jeab_inkorg_storage_mode() : 'json';
    }
    if ($id === 'purchases' || $id === 'orders') {
        return function_exists('jeab_purchases_storage_mode') ? jeab_purchases_storage_mode() : 'json';
    }

    return jeab_data_storage($id, 'json');
}

function jeab_data_sql_count(?PDO $pdo, string $table): ?int
{
    if (!$pdo instanceof PDO || preg_match('/\A[a-z][a-z0-9_]*\z/', $table) !== 1) {
        return null;
    }
    try {
        $n = $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    } catch (Throwable) {
        return null;
    }

    return is_numeric($n) ? (int) $n : null;
}

/**
 * @return array{ok:bool,sql_reachable:bool,types:array<string,array<string,mixed>>}
 */
function jeab_data_storage_status(): array
{
    $pdo = null;
    try {
        $pdo = jeab_data_pdo();
    } catch (Throwable) {
        $pdo = null;
    }
    $reachable = $pdo instanceof PDO;
    $types = [];
    foreach (jeab_migrate_registry() as $module) {
        if (!is_array($module) || !empty($module['missing'])) {
            continue;
        }
        $id = (string) ($module['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $json = $module['json'] ?? [];
        $retired = jeab_migrate_json_retired($id);
        $table = (string) ($module['table'] ?? '');
        $rows = jeab_data_sql_count($reachable ? $pdo : null, $table);
        $types[$id] = [
            'storage' => jeab_data_type_storage_mode($id),
            'json_runtime' => $retired ? false : (is_array($json) && $json !== []),
            'sql_rows' => $rows,
            'sql_ok' => $rows !== null,
        ];
    }
    if (isset($types['purchases']) && !isset($types['orders'])) {
        $types['orders'] = $types['purchases'];
        $types['orders']['storage'] = jeab_data_type_storage_mode('orders');
        $types['orders']['json_runtime'] = false;
    }

    return [
        'ok' => true,
        'sql_reachable' => $reachable,
        'types' => $types,
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_data_storage_status_dispatch(array $input): array
{
    unset($input);
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_data_storage_status();
    $result['status'] = 200;

    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_data_migrate_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    require_once __DIR__ . '/data_jobs.php';

    return jeab_data_job_accept('data.migrate_all', $input);
}

/** @return list<string> */
function jeab_data_migrate_types_allowed(): array
{
    return ['inbox_sent_new', 'solkoll', 'inkop_mallar', 'inkorg_views'];
}

/**
 * data.migrate_types. dry=1 som standard.
 * Bara listade tillåtna typer. CREATE TABLE IF NOT EXISTS och insert på tom tabell
 * sker i jeab_migrate_copy. Ingen underhållsflagga, ingen konfliktkörning, inga kontakter.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_data_migrate_types(array $input): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $maintenanceBefore = jeab_data_maintenance();
    $backendBefore = jeab_data_backend_explicit() ? jeab_data_backend() : '';
    $raw = $input['types'] ?? [];
    if (is_string($raw)) {
        $raw = $raw === '' ? [] : explode(',', $raw);
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    $wanted = [];
    foreach ($raw as $type) {
        $type = trim((string) $type);
        if ($type !== '' && !in_array($type, $wanted, true)) {
            $wanted[] = $type;
        }
    }
    $allowed = jeab_data_migrate_types_allowed();
    $blocked = ['contacts', 'inkorg_contacts'];
    $errors = [];
    $migrated = [];
    $verified = [];
    if ($wanted === []) {
        $errors[] = 'types saknas.';
    }
    foreach ($wanted as $type) {
        if (in_array($type, $blocked, true) || !in_array($type, $allowed, true)) {
            $message = in_array($type, $blocked, true) ? 'Kontakter rörs inte.' : 'Typen är inte tillåten.';
            $errors[] = $type . ': ' . $message;
            $migrated[] = jeab_migrate_shape($type, ['ok' => false, 'dry' => $dry, 'error' => $message]);
            continue;
        }
        $module = jeab_migrate_find($type);
        if (!is_array($module) || !empty($module['missing'])) {
            $message = is_array($module) ? (string) ($module['error'] ?? 'Modulen saknas.') : 'Modulen saknas.';
            if ($message === '') {
                $message = 'Modulen saknas.';
            }
            $errors[] = $type . ': ' . $message;
            $migrated[] = jeab_migrate_shape($type, ['ok' => false, 'dry' => $dry, 'error' => $message]);
            continue;
        }
        $copied = jeab_migrate_copy($module, $dry);
        $migrated[] = $copied;
        if (empty($copied['ok'])) {
            $message = (string) ($copied['error'] ?? '');
            $errors[] = $type . ': ' . ($message !== '' ? $message : 'migreringen misslyckades.');
        }
        $check = jeab_migrate_copy($module, true);
        $verified[] = $check;
        if (!$dry && (empty($check['ok']) || empty($check['match']))) {
            $message = (string) ($check['error'] ?? '');
            $errors[] = $type . ': ' . ($message !== '' ? $message : 'antal stämmer inte.');
        }
    }
    $maintenanceAfter = jeab_data_maintenance();
    if ($maintenanceAfter !== $maintenanceBefore) {
        $errors[] = 'Underhållsflaggan ändrades.';
    }
    $backendAfter = jeab_data_backend_explicit() ? jeab_data_backend() : '';
    if ($backendAfter !== $backendBefore) {
        $errors[] = 'Flaggan ändrades.';
    }
    $ready = $errors === [];
    foreach ($verified as $row) {
        if (is_array($row) && empty($row['match'])) {
            $ready = false;
        }
    }

    return jeab_data_public([
        'ok' => $errors === [],
        'dry' => $dry,
        'ready' => $ready,
        'written' => !$dry && $errors === [],
        'maintenance' => $maintenanceAfter,
        'migrate' => $migrated,
        'verify' => $verified,
        'errors' => $errors,
        'types' => $wanted,
    ]);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_data_migrate_types_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_data_migrate_types($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

require_once __DIR__ . '/data_cutover.php';
require_once __DIR__ . '/data_cutover_all.php';
require_once __DIR__ . '/json_sql_roll.php';
