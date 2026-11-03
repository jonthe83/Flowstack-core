<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * data.cutover_all, data.rollback_type, data.restore_doc, data.inkorg_counts, data.inkorg_probe, diag.mina och data.set_storage.
 * dry=1 är standard. write=1 gör backup, backfill, snabbkoll, SQL-flagga och raderar JSON.
 * keep_json=1 eller delete=0 behåller JSON, loggar stickprovet och sätter ändå SQL-flaggan.
 * DDL sätts bara när omslaget körs, inte vid en vanlig sidvisning.
 * Kontakter, inkorg, external_queue och login_throttle körs ett anrop i taget via type=.
 * Övriga typer i jeab_cutover_all_types() kan också köras en i taget via type=.
 * Zip av data/privat görs högst en gång per körning. En zip yngre än 30 minuter i backup-roten återanvänds.
 * step=backup zippar bara trädet, utan DDL.
 */

function jeab_cutover_all_skip(): array
{
    return [
        'contacts',
        'inkorg_contacts',
        'inbox',
        'inbox_threads',
        'inkorg_rules',
        'inkorg_settings',
        'auto_send_cap',
        'malmo_offer_sends',
        'quote_preview',
        'hs_uncertain_open',
        'rebook_log',
        'corrections',
        'external_queue',
        'login_throttle',
    ];
}

/**
 * Egna anrop. Ordning: kontakter, inkorg, external_queue, login_throttle.
 *
 * @return list<string>
 */
function jeab_cutover_staged_order(): array
{
    return ['kontakter', 'inkorg', 'external_queue', 'login_throttle'];
}

/** @return list<string> */
function jeab_cutover_staged_ids(string $type): array
{
    return match ($type) {
        'kontakter' => ['contacts', 'inkorg_contacts'],
        'inkorg' => ['inbox', 'inbox_threads'],
        'external_queue' => ['external_queue'],
        'login_throttle' => ['login_throttle'],
        default => [],
    };
}

/**
 * Huvudlagrets typer. Flaggan '' betyder db-drivern; explicit sql stänger store.json-reserven.
 *
 * @return list<string>
 */
function jeab_cutover_store_types(): array
{
    return ['customers', 'bookings', 'reminders', 'reminder_lists'];
}

/** check=1 i dry kör snabbkollen (bara läsning). */
function jeab_cutover_wants_dry_spot(array $input): bool
{
    $flag = $input['check'] ?? ($input['spot'] ?? null);

    return $flag === 1 || $flag === '1' || $flag === true;
}

function jeab_cutover_plain_type(string $id): bool
{
    if ($id === '' || !in_array($id, jeab_cutover_all_types(), true) || in_array($id, jeab_cutover_all_skip(), true)) {
        return false;
    }
    foreach (jeab_cutover_staged_order() as $group) {
        if (in_array($id, jeab_cutover_staged_ids($group), true)) {
            return false;
        }
    }

    return true;
}

function jeab_cutover_send_pause_path(): string
{
    return jeab_cutover_privat_dir() . '/cutover-send.pause';
}

function jeab_cutover_sends_paused(): bool
{
    if (!empty($GLOBALS['_JEAB_CUTOVER_SENDS_PAUSED'])) {
        return true;
    }
    $path = jeab_cutover_send_pause_path();
    if (!is_file($path)) {
        return false;
    }
    if (time() - (int) filemtime($path) > 900) {
        @unlink($path);

        return false;
    }

    return true;
}

function jeab_cutover_sends_pause(bool $on): void
{
    $path = jeab_cutover_send_pause_path();
    if (!$on) {
        unset($GLOBALS['_JEAB_CUTOVER_SENDS_PAUSED']);
        if (is_file($path)) {
            @unlink($path);
        }

        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir)) {
        file_put_contents($path, date('c') . "\n", LOCK_EX);
    }
    $GLOBALS['_JEAB_CUTOVER_SENDS_PAUSED'] = true;
}

function jeab_cutover_storage_file(string $key): string
{
    if (!preg_match('/\A[a-z0-9_]+\z/', $key)) {
        return '';
    }
    $path = jeab_cutover_privat_dir() . '/' . $key . '.txt';
    if (!is_file($path)) {
        return '';
    }
    $raw = strtolower(trim((string) file_get_contents($path)));

    return $raw === 'sql' || $raw === 'json' ? $raw : '';
}

function jeab_cutover_storage_flag(string $key, string $mode): bool
{
    if (($mode !== 'sql' && $mode !== 'json') || !preg_match('/\A[a-z0-9_]+\z/', $key)) {
        return false;
    }
    $saved = false;
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_settings_put')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $saved = jeab_settings_put($pdo, $driver, $key, $mode);
    }
    $dir = jeab_cutover_privat_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $fileOk = is_dir($dir) && file_put_contents($dir . '/' . $key . '.txt', $mode . "\n", LOCK_EX) !== false;

    return $saved || $fileOk;
}

/** @return list<string> */
function jeab_cutover_all_types(): array
{
    return [
        'purchases',
        'ordrar_deleted',
        'gi_loans',
        'biz_docs',
        'customers',
        'bookings',
        'reminders',
        'reminder_lists',
        'who_picks',
        'who_diag',
        'reminder_follow',
        'reminder_hook',
        'calendar_log',
        'webshop_orders',
        'graph_events',
        'fellogg_open_asks',
        'app_errors',
        'notes',
        'notes_state',
        'sms_sent',
        'omtago_leads',
        'inbox_sent',
        'notiser',
        'inkop_mallar',
        'inkorg_views',
        'inbox_sent_new',
        'sent_scan',
        'graph_state',
        'solkoll',
        'app_errors_webhook',
        'graph_week',
        'graph_daily',
        'graph_poll',
        'fullmakt',
        'sent_check',
        'quote_queue',
        'classify_pending',
        'helpscout_import',
        'malmo_hs_cursor',
    ];
}

function jeab_cutover_privat_dir(): string
{
    $override = $GLOBALS['_JEAB_PRIVAT_DIR'] ?? null;
    if (is_string($override) && $override !== '') {
        return rtrim(str_replace('\\', '/', $override), '/');
    }
    if (function_exists('jeab_flags_dir')) {
        $dir = jeab_flags_dir();
        if (is_string($dir) && $dir !== '') {
            return rtrim(str_replace('\\', '/', $dir), '/');
        }
    }
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);

    return rtrim(str_replace('\\', '/', dirname($root) . '/privat'), '/');
}

function jeab_cutover_wants_keep_json(array $input): bool
{
    if (array_key_exists('keep_json', $input)) {
        $flag = $input['keep_json'];
        if ($flag === 1 || $flag === '1' || $flag === true) {
            return true;
        }
    }
    if (array_key_exists('delete', $input)) {
        $flag = $input['delete'];
        if ($flag === 0 || $flag === '0' || $flag === false) {
            return true;
        }
    }

    return false;
}

function jeab_cutover_wants_write(array $input): bool
{
    if (array_key_exists('write', $input)) {
        $write = $input['write'];
        if ($write === 1 || $write === '1' || $write === true) {
            return true;
        }
    }
    if (!array_key_exists('dry', $input)) {
        return false;
    }
    $dry = $input['dry'];

    return $dry === 0 || $dry === '0' || $dry === false;
}

function jeab_cutover_hook_token(): string
{
    if (function_exists('jeab_inkorg_token')) {
        return jeab_inkorg_token();
    }
    if (!function_exists('jeab_data_dir')) {
        return '';
    }
    $path = jeab_data_dir() . '/secrets/inkorg-ingest.json';
    $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($decoded) ? trim((string) ($decoded['key'] ?? '')) : '';
}

function jeab_cutover_all_authorized(): bool
{
    $expected = jeab_cutover_hook_token();
    if ($expected === '') {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
    if ($method === 'GET' && function_exists('jeab_cron_token_matches')) {
        return jeab_cron_token_matches($expected);
    }

    return function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized();
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_cutover_all_dispatch(array $input): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }
    $result = jeab_cutover_all($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_cutover_rollback_dispatch(array $input): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }
    $result = jeab_cutover_rollback_type($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_cutover_all(array $input): array
{
    $write = jeab_cutover_wants_write($input);
    $keep = jeab_cutover_wants_keep_json($input);
    $asked = trim((string) ($input['type'] ?? ''));
    $step = trim((string) ($input['step'] ?? ''));
    if ($step === 'backup') {
        return jeab_cutover_backup_step($write);
    }
    if ($step !== '') {
        return ['ok' => false, 'error' => 'Steget körs inte här.', 'dry' => !$write, 'written' => false, 'type' => $asked];
    }
    $ids = $asked === '' ? [] : jeab_cutover_staged_ids($asked);
    if ($asked !== '' && $ids === []) {
        if (!jeab_cutover_plain_type($asked)) {
            return ['ok' => false, 'error' => 'Typen körs inte här.', 'dry' => !$write, 'written' => false, 'type' => $asked];
        }
        $ids = [$asked];
    }
    $types = [];
    $errors = [];
    $ran = static function () use ($write, $keep, $asked, $ids, &$types, &$errors): array {
        $list = $ids !== [] ? $ids : jeab_cutover_all_types();
        foreach ($list as $id) {
            if ($ids === [] && in_array($id, jeab_cutover_all_skip(), true)) {
                continue;
            }
            $row = jeab_cutover_one($id, $write, $keep);
            $types[] = $row;
            if (empty($row['ok'])) {
                $errors[] = $id . ': ' . (string) ($row['error'] ?? 'stoppades');
                break;
            }
        }
        if ($write && $errors === [] && $asked === 'kontakter' && !jeab_cutover_storage_flag('kontakter_storage', 'sql')) {
            $errors[] = 'kontakter_storage gick inte att sätta.';
        }
        if ($write && $errors === [] && $asked === 'inkorg' && !jeab_cutover_storage_flag('inkorg_storage', 'sql')) {
            $errors[] = 'inkorg_storage gick inte att sätta.';
        }
        $differ = 0;
        foreach ($types as $typeRow) {
            $differ += (int) ($typeRow['differ'] ?? ($typeRow['check']['differ'] ?? 0));
        }
        $out = [
            'ok' => $errors === [],
            'dry' => !$write,
            'written' => $write && $errors === [],
            'type' => $asked,
            'types' => $types,
            'errors' => $errors,
        ];
        if ($keep) {
            $out['json_kept'] = $write && $errors === [];
            $out['differ'] = $differ;
        }

        return $out;
    };
    $pause = $write && in_array($asked, ['external_queue', 'login_throttle'], true);
    if ($pause) {
        jeab_cutover_sends_pause(true);
    }
    if (!$write) {
        $prevSpot = $GLOBALS['_JEAB_CUTOVER_DRY_SPOT'] ?? null;
        $GLOBALS['_JEAB_CUTOVER_DRY_SPOT'] = jeab_cutover_wants_dry_spot($input);
        try {
            return $ran();
        } finally {
            if ($prevSpot === null) {
                unset($GLOBALS['_JEAB_CUTOVER_DRY_SPOT']);
            } else {
                $GLOBALS['_JEAB_CUTOVER_DRY_SPOT'] = $prevSpot;
            }
        }
    }
    $prevDdl = $GLOBALS['_JEAB_SCHEMA_DDL'] ?? null;
    $prevMigrate = $GLOBALS['_JEAB_DATA_MIGRATE'] ?? null;
    $GLOBALS['_JEAB_SCHEMA_DDL'] = true;
    $GLOBALS['_JEAB_DATA_MIGRATE'] = true;
    $GLOBALS['_JEAB_MIGRATE_RUNS'] = true;
    try {
        if (function_exists('jeab_kontakt_quiet_call')) {
            $out = jeab_kontakt_quiet_call($ran);
        } else {
            $out = $ran();
        }
    } finally {
        if ($prevDdl === null) {
            unset($GLOBALS['_JEAB_SCHEMA_DDL']);
        } else {
            $GLOBALS['_JEAB_SCHEMA_DDL'] = $prevDdl;
        }
        if ($prevMigrate === null) {
            unset($GLOBALS['_JEAB_DATA_MIGRATE']);
        } else {
            $GLOBALS['_JEAB_DATA_MIGRATE'] = $prevMigrate;
        }
        unset($GLOBALS['_JEAB_MIGRATE_RUNS']);
        if ($pause) {
            jeab_cutover_sends_pause(false);
        }
    }

    return is_array($out) ? $out : ['ok' => false, 'error' => 'Omslaget svarade fel.', 'dry' => false, 'written' => false];
}

/**
 * @return array<string, mixed>
 */
function jeab_cutover_one(string $id, bool $write, bool $keepJson = false): array
{
    try {
        return jeab_cutover_one_run($id, $write, $keepJson);
    } catch (Throwable $e) {
        return ['ok' => false, 'type' => $id, 'dry' => !$write, 'error' => 'Typen stoppades.', 'aborted' => $write];
    }
}

/**
 * @return array<string, mixed>
 */
function jeab_cutover_one_run(string $id, bool $write, bool $keepJson = false): array
{
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find($id) : null;
    if (!is_array($module) || !empty($module['missing'])) {
        return ['ok' => false, 'type' => $id, 'dry' => !$write, 'error' => 'Modulen saknas.'];
    }
    $jsonCount = jeab_cutover_json_count($module);
    if (!$write) {
        $dry = [
            'ok' => true,
            'type' => $id,
            'dry' => true,
            'written' => false,
            'json_count' => $jsonCount,
            'json_kept' => false,
            'flag' => jeab_cutover_type_flag($id),
            'would' => ['backup', 'backfill', 'check', 'sql', $keepJson ? 'keep_json' : 'delete'],
        ];
        if (in_array($id, jeab_cutover_store_types(), true)) {
            $dry['driver'] = function_exists('jeab_driver') ? jeab_driver() : '';
            $dry['ready'] = $dry['driver'] === 'mysql';
        }
        if (!empty($GLOBALS['_JEAB_CUTOVER_DRY_SPOT'])) {
            // Bara läsning: snabbkollen mot nuvarande SQL. Ingen backfill, ingen logg.
            $dry['check'] = jeab_cutover_spot($module);
        }

        return $dry;
    }
    $backup = jeab_cutover_backup_type($module);
    if (empty($backup['ok'])) {
        return ['ok' => false, 'type' => $id, 'dry' => false, 'error' => (string) ($backup['error'] ?? 'Backupen misslyckades.'), 'backup' => $backup];
    }
    $copied = jeab_migrate_copy($module, false);
    $after = $GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL'] ?? null;
    if (is_callable($after)) {
        $after($id);
    }
    if (empty($copied['ok'])) {
        return [
            'ok' => false,
            'type' => $id,
            'dry' => false,
            'error' => (string) ($copied['error'] ?? 'Backfill misslyckades.'),
            'backup' => $backup['dir'] ?? '',
            'aborted' => true,
        ];
    }
    $spot = jeab_cutover_spot($module);
    $figures = jeab_cutover_page_figures_check($id);
    if (empty($figures['ok'])) {
        return [
            'ok' => false,
            'type' => $id,
            'dry' => false,
            'error' => 'Sidans siffror skulle minska.',
            'check' => $spot,
            'figures' => $figures,
            'backup' => $backup['dir'] ?? '',
            'aborted' => true,
            'json_kept' => $keepJson,
        ];
    }
    if (empty($spot['ok']) && !$keepJson) {
        return [
            'ok' => false,
            'type' => $id,
            'dry' => false,
            'error' => (string) ($spot['error'] ?? 'Snabbkollen avvek.'),
            'check' => $spot,
            'differ' => (int) ($spot['differ'] ?? 0),
            'diffs' => is_array($spot['diffs'] ?? null) ? $spot['diffs'] : [],
            'backup' => $backup['dir'] ?? '',
            'aborted' => true,
        ];
    }
    if (!jeab_data_type_mark($id, 'sql')) {
        return ['ok' => false, 'type' => $id, 'dry' => false, 'error' => 'Flaggan gick inte att sätta.', 'check' => $spot, 'aborted' => true, 'json_kept' => false];
    }
    jeab_cutover_mark_imported($id);
    if ($keepJson) {
        return [
            'ok' => true,
            'type' => $id,
            'dry' => false,
            'written' => true,
            'sql' => true,
            'json_kept' => true,
            'json_count' => (int) ($spot['json_count'] ?? $jsonCount),
            'sql_count' => (int) ($spot['sql_count'] ?? 0),
            'sampled' => (int) ($spot['sampled'] ?? 0),
            'checked' => (int) ($spot['checked'] ?? 0),
            'inactive' => (int) ($spot['inactive'] ?? 0),
            'differ' => (int) ($spot['differ'] ?? 0),
            'diffs' => is_array($spot['diffs'] ?? null) ? $spot['diffs'] : [],
            'figures' => $figures,
            'deleted' => [],
            'backup' => $backup['dir'] ?? '',
            'check' => $spot,
            'error' => '',
        ];
    }
    $deleted = jeab_cutover_delete_json($module);

    return [
        'ok' => !empty($deleted['ok']),
        'type' => $id,
        'dry' => false,
        'written' => true,
        'sql' => true,
        'json_count' => (int) ($spot['json_count'] ?? $jsonCount),
        'sql_count' => (int) ($spot['sql_count'] ?? 0),
        'sampled' => (int) ($spot['sampled'] ?? 0),
        'deleted' => $deleted['deleted'] ?? [],
        'backup' => $backup['dir'] ?? '',
        'error' => !empty($deleted['ok']) ? '' : (string) ($deleted['error'] ?? 'Filerna gick inte att radera.'),
    ];
}

/**
 * Typer där appen bara läser och skriver SQL och JSON-filen inte skrivs längre
 * (sms_sent sedan #528). Snabbkollen kräver då bara att varje JSON-rad finns i SQL.
 *
 * @return list<string>
 */
function jeab_cutover_sql_live_types(): array
{
    return ['sms_sent'];
}

/** @param array<string, mixed> $module */
function jeab_cutover_json_count(array $module): int
{
    $rowsFn = (string) ($module['rows'] ?? '');
    if (!function_exists($rowsFn)) {
        return 0;
    }
    $mapped = $rowsFn(jeab_migrate_read_source($module));

    return is_array($mapped) ? count($mapped) : 0;
}

/**
 * Varje JSON-rad ska finnas i SQL med samma normaliserade innehåll.
 * Extra SQL-rader är tillåtna. En nyare SQL-tid döljer inte en riktig fältförlust.
 * Kontakter jämförs bara om de synts i bokning, påminnelse, order, inkorg eller kalender senaste 3 månaderna.
 * Högst fem rader beskrivs, med fältnamn och utan värden.
 *
 * @param array<string, mixed> $module
 * @return array<string, mixed>
 */
function jeab_cutover_spot(array $module): array
{
    $id = (string) ($module['id'] ?? '');
    $rowsFn = (string) ($module['rows'] ?? '');
    if (!function_exists($rowsFn)) {
        return ['ok' => false, 'error' => 'rows saknas.', 'diffs' => []];
    }
    $mapped = $rowsFn(jeab_migrate_read_source($module));
    if (!is_array($mapped)) {
        return ['ok' => false, 'error' => 'rows svarade fel.', 'diffs' => []];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'json_count' => count($mapped), 'sql_count' => 0, 'diffs' => []];
    }
    try {
        $sqlRows = jeab_migrate_select_owned($pdo, $module, $mapped);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Tabellen gick inte att läsa.', 'json_count' => count($mapped), 'sql_count' => 0, 'diffs' => []];
    }
    $pk = (string) ($module['primary_key'] ?? '');
    $columns = is_array($module['columns'] ?? null) ? $module['columns'] : [];
    $jsonMap = jeab_migrate_key_rows($mapped, $pk);
    $sqlMap = jeab_migrate_key_rows(is_array($sqlRows) ? $sqlRows : [], $pk);
    $activeOnly = $id === 'contacts' || $id === 'inkorg_contacts';
    $active = $activeOnly ? jeab_cutover_active_contact_ids($pdo, $id) : null;
    $scope = $jsonMap;
    $inactive = 0;
    if (is_array($active)) {
        $scope = array_intersect_key($jsonMap, $active);
        $inactive = count($jsonMap) - count($scope);
    }
    $missing = 0;
    $diffs = [];
    foreach ($scope as $key => $_row) {
        if (isset($sqlMap[$key])) {
            continue;
        }
        $missing++;
        if (count($diffs) < 5) {
            $diffs[] = ['id' => (string) $key, 'fields' => [['name' => $pk !== '' ? $pk : 'id', 'kind' => 'saknas_i_sql']]];
        }
    }
    $exact = in_array($id, ['external_queue', 'login_throttle'], true);
    if ($missing > 0 || ($exact && count($jsonMap) !== count($sqlMap))) {
        return [
            'ok' => false,
            'error' => 'Antalet stämmer inte.',
            'json_count' => count($jsonMap),
            'sql_count' => count($sqlMap),
            'checked' => count($scope),
            'inactive' => $inactive,
            'missing' => $missing,
            'differ' => $missing,
            'sampled' => min(5, $missing),
            'diffs' => $diffs,
            'active_only' => $activeOnly,
        ];
    }
    $differ = 0;
    $sqlLive = in_array($id, jeab_cutover_sql_live_types(), true);
    $newer = 0;
    $newerIds = [];
    foreach ($scope as $key => $jsonRow) {
        $jsonRow = is_array($jsonRow) ? $jsonRow : [];
        $sqlRow = is_array($sqlMap[$key] ?? null) ? $sqlMap[$key] : [];
        $fields = jeab_cutover_real_fields(jeab_cutover_row_fields($jsonRow, $sqlRow, $columns));
        if ($fields === []) {
            continue;
        }
        if ($sqlLive) {
            // Appen skriver bara SQL. JSON-filen är en fryst importkopia, så SQL-raden är nyare.
            $newer++;
            if (count($newerIds) < 5) {
                $newerIds[] = (string) $key;
            }
            continue;
        }
        $differ++;
        if (count($diffs) < 5) {
            $diffs[] = ['id' => (string) $key, 'fields' => $fields];
        }
    }
    $checked = count($scope);
    $extra = $sqlLive ? ['compared' => 'presence', 'sql_newer' => $newer, 'sql_newer_ids' => $newerIds] : [];

    return $extra + [
        'ok' => $differ === 0,
        'type' => $id,
        'error' => $differ > 0 ? 'En rad skiljer sig.' : '',
        'json_count' => count($jsonMap),
        'sql_count' => count($sqlMap),
        'checked' => $checked,
        'inactive' => $inactive,
        'sampled' => min(5, $checked),
        'differ' => $differ,
        'diffs' => $diffs,
        'active_only' => $activeOnly,
    ];
}

/**
 * Kontakt-id som synts senaste tre månaderna.
 * Bokning, påminnelse, order, inkorg eller kalender. Saknad tabell hoppas över.
 *
 * @return array<string, true>
 */
function jeab_cutover_active_contact_ids(PDO $pdo, string $moduleId): array
{
    $since = date('Y-m-d H:i:s', strtotime('-3 months'));
    $ids = [];
    $add = static function (mixed $value) use (&$ids): void {
        $id = trim((string) $value);
        if ($id !== '') {
            $ids[$id] = true;
        }
    };
    $column = static function (string $sql, array $params) use ($pdo, $add): void {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $add($value);
            }
        } catch (Throwable) {
        }
    };
    $column(
        'SELECT contact_id FROM bookings WHERE contact_id IS NOT NULL AND contact_id <> \'\' AND starts_at >= ?',
        [$since]
    );
    $column(
        'SELECT contact_id FROM reminders WHERE contact_id IS NOT NULL AND contact_id <> \'\' AND (updated_at >= ? OR due_at >= ? OR created_at >= ?)',
        [$since, $since, $since]
    );
    $column(
        'SELECT contact_id FROM purchases WHERE contact_id IS NOT NULL AND contact_id <> \'\' AND updated_at >= ?',
        [$since]
    );
    $column(
        'SELECT contact_id FROM inkorg_list WHERE contact_id IS NOT NULL AND contact_id <> \'\' AND list_at >= ?',
        [$since]
    );
    try {
        $stmt = $pdo->prepare('SELECT payload FROM log_lines WHERE kind = ? AND at >= ? LIMIT 2000');
        $stmt->execute(['calendar_event', $since]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $decoded = json_decode((string) $payload, true);
            if (is_array($decoded)) {
                $add($decoded['contact_id'] ?? '');
            }
        }
    } catch (Throwable) {
    }
    $emails = [];
    try {
        $stmt = $pdo->prepare('SELECT from_addr FROM inkorg_messages WHERE received_at >= ? AND from_addr <> \'\'');
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
    } catch (Throwable) {
    }
    if ($emails !== []) {
        foreach (array_chunk(array_keys($emails), 200) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $column('SELECT id FROM contacts WHERE owner_email IN (' . $marks . ')', $chunk);
            if ($moduleId === 'inkorg_contacts') {
                $column('SELECT id FROM inkorg_contacts WHERE email IN (' . $marks . ')', $chunk);
            }
        }
    }
    if ($moduleId === 'inkorg_contacts') {
        $column('SELECT id FROM inkorg_contacts WHERE updated_at >= ?', [$since]);
    }

    return $ids;
}

/** @param array<string, mixed> $module @return array<string, string> */
function jeab_cutover_updated_map(PDO $pdo, array $module): array
{
    $table = (string) ($module['table'] ?? '');
    $pk = (string) ($module['primary_key'] ?? '');
    $schema = $module['schema'] ?? [];
    $ddl = is_array($schema) ? (string) ($schema['sqlite'] ?? '') . (string) ($schema['mysql'] ?? '') : '';
    if ($table === '' || $pk === '' || stripos($ddl, 'updated_at') === false) {
        return [];
    }
    try {
        $stmt = $pdo->query(
            'SELECT ' . jeab_migrate_ident($pdo, $pk) . ', ' . jeab_migrate_ident($pdo, 'updated_at')
            . ' FROM ' . jeab_migrate_ident($pdo, $table)
        );
    } catch (Throwable) {
        return [];
    }
    if (!$stmt instanceof PDOStatement) {
        return [];
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = trim((string) ($row[$pk] ?? ''));
        $at = trim((string) ($row['updated_at'] ?? ''));
        if ($key !== '' && $at !== '') {
            $out[$key] = $at;
        }
    }

    return $out;
}

function jeab_cutover_stamp_newer(?string $sqlAt, ?string $jsonAt): bool
{
    return $sqlAt !== null && $jsonAt !== null && $sqlAt > $jsonAt;
}

/** @param array<string, mixed> $row */
function jeab_cutover_row_stamp(array $row, ?string $columnStamp): ?string
{
    $candidates = [$columnStamp, $row['updated_at'] ?? null];
    foreach (['payload', 'row_json'] as $name) {
        if (!is_string($row[$name] ?? null)) {
            continue;
        }
        $decoded = json_decode($row[$name], true);
        if (is_array($decoded)) {
            $candidates[] = $decoded['updated_at'] ?? null;
        }
    }
    foreach ($candidates as $raw) {
        $norm = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($raw) : null;
        if (is_string($norm) && $norm !== '1970-01-01 00:00:00') {
            return $norm;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $json
 * @param array<string, mixed> $sql
 * @param list<mixed> $columns
 * @return list<array{name:string,kind:string}>
 */
function jeab_cutover_row_fields(array $json, array $sql, array $columns): array
{
    $out = [];
    foreach ($columns as $column) {
        $column = (string) $column;
        if ($column === '' || $column === 'imported') {
            continue;
        }
        $left = $json[$column] ?? null;
        $right = $sql[$column] ?? null;
        if (jeab_cutover_covers(jeab_cutover_norm($left), jeab_cutover_norm($right))) {
            continue;
        }
        $before = count($out);
        jeab_cutover_diff_into($left, $right, $column, $out);
        if (count($out) === $before && count($out) < 8) {
            $out[] = ['name' => $column, 'kind' => jeab_cutover_diff_kind($left, $right, $column)];
        }
        if (count($out) >= 8) {
            break;
        }
        $jsonNames = jeab_cutover_real_names(jeab_cutover_norm($left));
        $sqlNames = jeab_cutover_real_names(jeab_cutover_norm($right));
        if (count($jsonNames) !== count($sqlNames) && count($out) < 8) {
            $out[] = ['name' => $column . '.antal', 'kind' => 'antal'];
        }
    }

    return $out;
}

/**
 * Tidsstämplar (format, watermark, updated_at, at) är inte en fältförlust.
 *
 * @param list<array{name:string,kind:string}> $fields
 * @return list<array{name:string,kind:string}>
 */
function jeab_cutover_real_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $field) {
        if (!is_array($field) || (string) ($field['kind'] ?? '') === 'tidsstampel') {
            continue;
        }
        $out[] = ['name' => (string) ($field['name'] ?? ''), 'kind' => (string) ($field['kind'] ?? '')];
    }

    return $out;
}

function jeab_cutover_value_is_time(mixed $value): bool
{
    if (jeab_cutover_looks_time($value)) {
        return true;
    }
    if (is_int($value)) {
        return $value > 1000000000 && $value < 20000000000;
    }
    if (is_string($value) && preg_match('/^\d{10,13}$/', trim($value)) === 1) {
        $n = (int) $value;

        return $n > 1000000000 && $n < 20000000000;
    }

    return false;
}

/**
 * Riktiga fältnamn, inte tidsstämplar. Används så stickprovet ser borttappade namn och antal.
 *
 * @return list<string>
 */
function jeab_cutover_real_names(mixed $value, string $prefix = ''): array
{
    if (!is_array($value) || array_is_list($value)) {
        return [];
    }
    $names = [];
    foreach ($value as $key => $item) {
        if ($item === null || jeab_cutover_value_is_time($item)) {
            continue;
        }
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($item) && !array_is_list($item)) {
            $nested = jeab_cutover_real_names($item, $path);
            foreach ($nested === [] ? [$path] : $nested as $one) {
                $names[] = $one;
            }
            continue;
        }
        $names[] = $path;
    }

    return $names;
}

/** @param list<array{name:string,kind:string}> $out */
function jeab_cutover_diff_into(mixed $json, mixed $sql, string $path, array &$out): void
{
    if (count($out) >= 8) {
        return;
    }
    $left = jeab_cutover_norm($json);
    $right = jeab_cutover_norm($sql);
    if (jeab_cutover_covers($left, $right)) {
        return;
    }
    if (is_array($left) && is_array($right) && !array_is_list($left) && !array_is_list($right)) {
        foreach ($left as $key => $value) {
            if ($value === null || count($out) >= 8) {
                continue;
            }
            $name = $path === '' ? (string) $key : $path . '.' . $key;
            if (!array_key_exists((string) $key, $right) || $right[$key] === null) {
                $out[] = [
                    'name' => $name,
                    'kind' => jeab_cutover_value_is_time($value) ? 'tidsstampel' : 'saknas_i_sql',
                ];
                continue;
            }
            if (!jeab_cutover_covers($value, $right[$key])) {
                if (is_array($value) && is_array($right[$key])) {
                    jeab_cutover_diff_into($value, $right[$key], $name, $out);
                } else {
                    $leftTime = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($value) : null;
                    $rightTime = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($right[$key]) : null;
                    if (is_string($leftTime) && $leftTime === $rightTime && $leftTime !== '1970-01-01 00:00:00'
                        && (jeab_cutover_key_is_stamp($name) || jeab_cutover_looks_time($value) || jeab_cutover_looks_time($right[$key]) || (jeab_cutover_value_is_time($value) && jeab_cutover_value_is_time($right[$key])))) {
                        continue;
                    }
                    $out[] = ['name' => $name, 'kind' => jeab_cutover_diff_kind($value, $right[$key], $name)];
                }
            }
        }

        return;
    }
    $out[] = ['name' => $path === '' ? 'rad' : $path, 'kind' => jeab_cutover_diff_kind($json, $sql, $path)];
}

function jeab_cutover_key_is_stamp(string $path): bool
{
    $leaf = $path;
    $dot = strrpos($path, '.');
    if ($dot !== false) {
        $leaf = substr($path, $dot + 1);
    }
    if (in_array($leaf, ['at', 'updated_at', 'created_at', 'watermark', 'cached_at', 'imported_at', 'synced_at', 'list_at', 'received_at'], true)) {
        return true;
    }

    return str_ends_with($leaf, '_at');
}

function jeab_cutover_diff_kind(mixed $json, mixed $sql, string $path = ''): string
{
    if (jeab_cutover_key_is_stamp($path) || jeab_cutover_looks_time($json) || jeab_cutover_looks_time($sql)) {
        return 'tidsstampel';
    }
    $left = gettype($json);
    $right = gettype($sql);
    $scalar = ['integer', 'double', 'string', 'NULL', 'boolean'];
    if ($left !== $right && in_array($left, $scalar, true) && in_array($right, $scalar, true)) {
        return 'typskillnad';
    }

    return 'faltnamn';
}

function jeab_cutover_covers(mixed $json, mixed $sql): bool
{
    if ($json === null) {
        return true;
    }
    if (is_array($json) && is_array($sql)) {
        $jsonList = array_is_list($json);
        $sqlList = array_is_list($sql);
        if ($jsonList || $sqlList) {
            if (!$jsonList || !$sqlList) {
                return false;
            }
            $used = [];
            foreach ($json as $item) {
                $found = false;
                foreach ($sql as $index => $other) {
                    if (isset($used[$index])) {
                        continue;
                    }
                    if (jeab_cutover_covers($item, $other)) {
                        $used[$index] = true;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }

            return true;
        }
        foreach ($json as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!array_key_exists((string) $key, $sql) || !jeab_cutover_covers($value, $sql[$key])) {
                return false;
            }
        }

        return true;
    }

    return $json === $sql;
}

function jeab_cutover_norm(mixed $value): mixed
{
    if (is_array($value)) {
        if (function_exists('jeab_migrate_redact')) {
            $value = jeab_migrate_redact($value);
        }
        if (!is_array($value)) {
            return jeab_cutover_norm($value);
        }
        $list = array_is_list($value);
        if (!$list) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = jeab_cutover_norm($item);
        }

        return $value;
    }
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return jeab_cutover_norm_number($value);
    }
    if (!is_string($value)) {
        return null;
    }
    $trim = trim($value);
    if ($trim === '') {
        return null;
    }
    $first = $trim[0];
    if (($first === '{' || $first === '[') && strlen($trim) > 1) {
        $decoded = json_decode($trim, true);
        if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
            return jeab_cutover_norm($decoded);
        }
    }
    if (jeab_cutover_looks_time($trim)) {
        $norm = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($trim) : null;
        if (is_string($norm)) {
            return $norm;
        }
    }
    if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $trim) === 1) {
        return jeab_cutover_norm_number($trim + 0);
    }

    return $trim;
}

function jeab_cutover_norm_number(int|float $value): int|float
{
    if (!is_finite($value)) {
        return 0;
    }
    if (abs($value) < 1e15 && floor($value) == $value) {
        return (int) $value;
    }

    return $value;
}

function jeab_cutover_looks_time(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $trim = trim($value);

    return preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $trim) === 1;
}

/** @param list<string> $columns */
function jeab_cutover_row_canon(array $row, array $columns): string
{
    $pick = [];
    foreach ($columns as $column) {
        $column = (string) $column;
        $pick[$column] = jeab_cutover_norm($row[$column] ?? null);
    }
    $json = json_encode($pick, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return hash('sha256', is_string($json) ? $json : '');
}

/**
 * Sidans egna tal, före flipp. -1 betyder att talet inte gick att läsa (ingen DDL).
 * Historik är stängda rader i inkorg_list plus arkivet i jeab_hs_import_index.
 * Mina är fingeravtryck i biz_code_people som kan bli en person.
 *
 * @return array{inkorg:int,historik:int,ordrar_deleted:int,mina:int}
 */
function jeab_cutover_page_figures(): array
{
    return [
        'inkorg' => jeab_cutover_figure_inkorg(),
        'historik' => jeab_cutover_figure_historik(),
        'ordrar_deleted' => jeab_cutover_figure_deleted(),
        'mina' => jeab_cutover_figure_mina(),
    ];
}

/**
 * @return array{ok:bool,before:array<string,int>,after:array<string,int>,drop:list<string>}
 */
function jeab_cutover_page_figures_check(string $id): array
{
    $before = jeab_cutover_page_figures();
    $GLOBALS['_JEAB_CUTOVER_FIGURE_SQL'] = $id;
    try {
        $after = jeab_cutover_page_figures();
    } finally {
        unset($GLOBALS['_JEAB_CUTOVER_FIGURE_SQL']);
    }
    $drop = [];
    foreach (['inkorg', 'historik', 'ordrar_deleted', 'mina'] as $key) {
        $from = (int) ($before[$key] ?? -1);
        $to = (int) ($after[$key] ?? -1);
        if ($from >= 0 && $to >= 0 && $to < $from) {
            $drop[] = $key;
        }
    }
    $report = ['ok' => $drop === [], 'before' => $before, 'after' => $after, 'drop' => $drop, 'type' => $id];
    jeab_cutover_page_figures_log($report);

    return $report;
}

/** @param array<string, mixed> $report */
function jeab_cutover_page_figures_log(array $report): void
{
    $dir = jeab_cutover_privat_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }
    $line = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    @file_put_contents($dir . '/cutover-page-figures.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

function jeab_cutover_figure_force(): string
{
    return (string) ($GLOBALS['_JEAB_CUTOVER_FIGURE_SQL'] ?? '');
}

function jeab_cutover_figure_scalar(PDO $pdo, string $sql, array $args = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return -1;
    }
}

function jeab_cutover_figure_inkorg(): int
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return -1;
    }
    $force = jeab_cutover_figure_force();
    if ($force !== '' && $force !== 'inbox' && $force !== 'inbox_threads') {
        $live = function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql();
        if (!$live) {
            return jeab_cutover_figure_scalar(
                $pdo,
                "SELECT COUNT(*) FROM inkorg_list WHERE list_status NOT IN ('closed', 'deleted')"
            );
        }
    }

    return jeab_cutover_figure_scalar(
        $pdo,
        "SELECT COUNT(*) FROM inkorg_list WHERE list_status NOT IN ('closed', 'deleted')"
    );
}

function jeab_cutover_figure_historik(): int
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return -1;
    }
    $live = jeab_cutover_figure_scalar(
        $pdo,
        "SELECT COUNT(*) FROM inkorg_list WHERE list_status IN ('closed', 'deleted')"
    );
    $archive = function_exists('jeab_hs_import_historik_count_noddl')
        ? jeab_hs_import_historik_count_noddl($pdo, ['jarfalla', 'malmo', 'meab'])
        : jeab_cutover_figure_scalar(
            $pdo,
            "SELECT COUNT(*) FROM jeab_hs_import_index WHERE list_status IN ('closed', 'deleted')"
        );
    if ($live < 0 && $archive < 0) {
        return -1;
    }

    return max(0, $live) + max(0, $archive);
}

function jeab_cutover_figure_deleted(): int
{
    $force = jeab_cutover_figure_force();
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $sqlDoc = null;
    if ($pdo instanceof PDO && ($force === 'ordrar_deleted' || (function_exists('jeab_ordrar_deleted_storage_mode') && jeab_ordrar_deleted_storage_mode() === 'sql'))) {
        try {
            $stmt = $pdo->prepare('SELECT payload FROM json_docs WHERE doc_key = ?');
            $stmt->execute(['ordrar_deleted']);
            $raw = $stmt->fetchColumn();
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $sqlDoc = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            $sqlDoc = null;
        }
    }
    if ($force === 'ordrar_deleted') {
        $doc = is_array($sqlDoc) ? $sqlDoc : [];
    } elseif (is_array($sqlDoc) && $sqlDoc !== []) {
        $doc = $sqlDoc;
    } else {
        $path = function_exists('jeab_ordrar_deleted_path') ? jeab_ordrar_deleted_path() : '';
        $raw = $path !== '' && is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $doc = is_array($raw) ? $raw : [];
    }
    $ids = is_array($doc['ids'] ?? null) ? $doc['ids'] : [];

    return count($ids);
}

function jeab_cutover_figure_mina(): int
{
    $force = jeab_cutover_figure_force();
    $sql = [];
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_sql_doc_get') && function_exists('jeab_biz_doc_sql_key')) {
        try {
            $got = jeab_sql_doc_get($pdo, jeab_biz_doc_sql_key('code_people'));
            $sql = is_array($got) ? $got : [];
        } catch (Throwable) {
            $sql = [];
        }
    }
    if ($force === 'biz_docs') {
        return jeab_cutover_mina_count($sql);
    }
    if ($sql !== [] && function_exists('jeab_code_people_json_read') && function_exists('jeab_code_people_prefer_newer')) {
        return jeab_cutover_mina_count(jeab_code_people_prefer_newer($sql, jeab_code_people_json_read()));
    }
    if (function_exists('jeab_code_people_read')) {
        $doc = jeab_code_people_read(false);

        return jeab_cutover_mina_count(is_array($doc) ? $doc : []);
    }

    return jeab_cutover_mina_count($sql);
}

/** @param array<string, mixed> $doc */
function jeab_cutover_mina_count(array $doc): int
{
    $n = 0;
    foreach ($doc as $row) {
        if (!is_array($row)) {
            continue;
        }
        $via = (string) ($row['via'] ?? '');
        $person = trim((string) ($row['person'] ?? ''));
        if ($person !== '' && in_array($via, ['prefix', 'code', 'manual', 'email'], true)) {
            $n++;
        }
    }

    return $n;
}

function jeab_cutover_backup_root(): string
{
    return jeab_cutover_privat_dir() . '/backup';
}

/**
 * @param array<string, mixed> $module
 * @return array<string, mixed>
 */
function jeab_cutover_backup_type(array $module): array
{
    $id = (string) ($module['id'] ?? '');
    $zip = jeab_cutover_ensure_tree_zip(true);
    if (empty($zip['ok']) || (string) ($zip['path'] ?? '') === '') {
        return ['ok' => false, 'error' => (string) ($zip['error'] ?? 'Zipen gick inte att skriva.')];
    }
    $stamp = date('Ymd-His');
    $dir = jeab_cutover_backup_root() . '/' . $stamp . '-' . preg_replace('/[^a-z0-9_]/', '', $id);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Backupmappen gick inte att skapa.'];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $tables = is_array($module['tables'] ?? null) ? $module['tables'] : [];
    $dumpPath = $dir . '/tables.dump';
    $dumped = $pdo instanceof PDO && jeab_cutover_dump_rows($pdo, $module, $dumpPath);
    $mysqlDump = $dir . '/tables.sql';
    $mysqlOk = $pdo instanceof PDO && jeab_cutover_mysqldump($pdo, $tables, $mysqlDump);
    $manifest = [
        'type' => $id,
        'at' => date('c'),
        'zip' => jeab_cutover_zip_manifest_ref($dir, (string) $zip['path']),
        'dump' => $dumped ? 'tables.dump' : '',
        'mysqldump' => $mysqlOk ? 'tables.sql' : '',
        'tables' => array_values(array_map('strval', $tables)),
        'json' => jeab_cutover_json_rels($module),
    ];
    if (file_put_contents($dir . '/manifest.txt', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        return ['ok' => false, 'error' => 'Manifestet gick inte att skriva.', 'dir' => $dir];
    }

    return [
        'ok' => true,
        'dir' => $dir,
        'zip' => true,
        'reused' => !empty($zip['reused']),
        'dump' => $dumped,
        'mysqldump' => $mysqlOk,
    ];
}

function jeab_cutover_zip_is_fresh(string $path): bool
{
    if (!is_file($path) || !str_ends_with(strtolower($path), '.zip')) {
        return false;
    }
    clearstatcache(true, $path);
    $size = filesize($path);

    return $size !== false && $size > 0 && (time() - (int) filemtime($path)) < 1800;
}

function jeab_cutover_fresh_tree_zip(): string
{
    $cached = $GLOBALS['_JEAB_CUTOVER_TREE_ZIP'] ?? '';
    if (is_string($cached) && jeab_cutover_zip_is_fresh($cached)) {
        return $cached;
    }
    $root = jeab_cutover_backup_root();
    if (!is_dir($root)) {
        return '';
    }
    $best = '';
    $bestMtime = 0;
    foreach (glob($root . '/data-privat*.zip') ?: [] as $path) {
        if (!is_string($path) || !jeab_cutover_zip_is_fresh($path)) {
            continue;
        }
        $mtime = (int) filemtime($path);
        if ($mtime >= $bestMtime) {
            $best = $path;
            $bestMtime = $mtime;
        }
    }
    if ($best !== '') {
        $GLOBALS['_JEAB_CUTOVER_TREE_ZIP'] = $best;
    }

    return $best;
}

/**
 * Zippar data/privat högst en gång. En färsk zip i backup-roten återanvänds.
 * En ny zip får ett eget filnamn så att äldre manifest fortfarande pekar rätt.
 *
 * @return array{ok:bool,path:string,reused:bool,error:string}
 */
function jeab_cutover_ensure_tree_zip(bool $write): array
{
    $fresh = jeab_cutover_fresh_tree_zip();
    if ($fresh !== '') {
        return ['ok' => true, 'path' => $fresh, 'reused' => true, 'error' => ''];
    }
    if (!$write) {
        return ['ok' => true, 'path' => '', 'reused' => false, 'error' => ''];
    }
    $dir = jeab_cutover_backup_root();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'path' => '', 'reused' => false, 'error' => 'Backupmappen gick inte att skapa.'];
    }
    $path = $dir . '/data-privat-' . date('Ymd-His') . '.zip';
    if (is_file($path)) {
        $path = $dir . '/data-privat-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.zip';
    }
    $tmp = $path . '.part';
    $GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] = (int) ($GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] ?? 0) + 1;
    if (!jeab_cutover_zip_trees($tmp) || !@rename($tmp, $path)) {
        @unlink($tmp);

        return ['ok' => false, 'path' => '', 'reused' => false, 'error' => 'Zipen gick inte att skriva.'];
    }
    $GLOBALS['_JEAB_CUTOVER_TREE_ZIP'] = $path;

    return ['ok' => true, 'path' => $path, 'reused' => false, 'error' => ''];
}

function jeab_cutover_zip_manifest_ref(string $dir, string $zipPath): string
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    $zipPath = str_replace('\\', '/', $zipPath);
    $root = rtrim(str_replace('\\', '/', jeab_cutover_backup_root()), '/');
    if (!str_starts_with($dir, $root . '/') || !str_starts_with($zipPath, $root . '/')) {
        return 'data-privat.zip';
    }
    $up = substr_count(substr($dir, strlen($root) + 1), '/') + 1;

    return str_repeat('../', $up) . substr($zipPath, strlen($root) + 1);
}

/** @return array<string, mixed> */
function jeab_cutover_backup_step(bool $write): array
{
    $made = jeab_cutover_ensure_tree_zip($write);
    $ok = !empty($made['ok']);

    return [
        'ok' => $ok,
        'step' => 'backup',
        'dry' => !$write,
        'written' => $write && $ok,
        'reused' => !empty($made['reused']),
        'zip' => $ok && (string) ($made['path'] ?? '') !== '' ? basename((string) $made['path']) : '',
        'error' => $ok ? '' : (string) ($made['error'] ?? 'Zipen gick inte att skriva.'),
    ];
}

/** @param array{dir:string,manifest:array<string,mixed>} $found */
function jeab_cutover_backup_zip_file(array $found): string
{
    $named = str_replace('\\', '/', (string) ($found['manifest']['zip'] ?? ''));
    if ($named === '') {
        $named = 'data-privat.zip';
    }
    if (str_contains($named, "\0") || str_starts_with($named, '/') || preg_match('#^[A-Za-z]:/#', $named) === 1) {
        return '';
    }
    $dir = rtrim(str_replace('\\', '/', (string) ($found['dir'] ?? '')), '/');
    if ($dir === '') {
        return '';
    }
    $real = realpath($dir . '/' . $named);
    if ($real === false || !is_file($real)) {
        return '';
    }
    $real = str_replace('\\', '/', $real);
    if (!str_ends_with(strtolower($real), '.zip')) {
        return '';
    }
    $root = realpath(jeab_cutover_backup_root());
    if (!is_string($root)) {
        return '';
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if ($real !== $root && !str_starts_with($real, $root . '/')) {
        return '';
    }

    return $real;
}

function jeab_cutover_zip_trees(string $zipPath): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $data = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    $privat = jeab_cutover_privat_dir();
    $backup = jeab_cutover_backup_root();
    if ($data !== '' && is_dir($data)) {
        jeab_cutover_zip_dir($zip, $data, 'data', $backup);
    }
    if (is_dir($privat)) {
        jeab_cutover_zip_dir($zip, $privat, 'privat', $backup);
    }
    $zip->close();

    return is_file($zipPath);
}

function jeab_cutover_zip_dir(ZipArchive $zip, string $root, string $prefix, string $skip): void
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $skip = rtrim(str_replace('\\', '/', $skip), '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if ($skip !== '' && ($path === $skip || str_starts_with($path, $skip . '/'))) {
            continue;
        }
        if (str_ends_with($path, '.sqlite') || str_ends_with($path, '.sqlite-journal')) {
            continue;
        }
        $rel = $prefix . substr($path, strlen($root));
        $zip->addFile($path, $rel);
    }
}

/**
 * @param array<string, mixed> $module
 * @param list<string>|array<int, mixed> $tables
 */
function jeab_cutover_dump_rows(PDO $pdo, array $module, string $dest): bool
{
    try {
        $rows = jeab_migrate_select_owned($pdo, $module, []);
    } catch (Throwable) {
        $rows = [];
    }
    $body = json_encode([
        'table' => (string) ($module['table'] ?? ''),
        'rows' => is_array($rows) ? $rows : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($body) && file_put_contents($dest, $body, LOCK_EX) !== false;
}

/** @param list<mixed> $tables */
function jeab_cutover_mysqldump(PDO $pdo, array $tables, string $dest): bool
{
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }
    } catch (Throwable) {
        return false;
    }
    $which = @shell_exec('command -v mysqldump 2>/dev/null');
    $bin = is_string($which) ? trim($which) : '';
    if ($bin === '' || !function_exists('jeab_config')) {
        return false;
    }
    $mysql = jeab_config()['db']['mysql'] ?? null;
    if (!is_array($mysql) || (string) ($mysql['name'] ?? '') === '') {
        return false;
    }
    $names = [];
    foreach ($tables as $table) {
        $table = (string) $table;
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) === 1) {
            $names[] = $table;
        }
    }
    if ($names === []) {
        return false;
    }
    $cnf = $dest . '.cnf';
    $pass = str_replace(["\r", "\n"], '', (string) ($mysql['pass'] ?? ''));
    $user = str_replace(["\r", "\n"], '', (string) ($mysql['user'] ?? ''));
    $host = str_replace(["\r", "\n"], '', (string) ($mysql['host'] ?? 'localhost'));
    $written = file_put_contents($cnf, "[client]\nuser=" . $user . "\npassword=" . $pass . "\nhost=" . $host . "\n");
    @chmod($cnf, 0600);
    if ($written === false) {
        return false;
    }
    $cmd = escapeshellarg($bin)
        . ' --defaults-extra-file=' . escapeshellarg($cnf)
        . ' --single-transaction --skip-lock-tables '
        . escapeshellarg((string) $mysql['name']) . ' '
        . implode(' ', array_map('escapeshellarg', $names))
        . ' > ' . escapeshellarg($dest) . ' 2>/dev/null';
    $code = 1;
    @exec($cmd, $unused, $code);
    @unlink($cnf);

    return $code === 0 && is_file($dest) && (int) filesize($dest) > 0;
}

/** @param array<string, mixed> $module @return list<string> */
function jeab_cutover_json_rels(array $module): array
{
    $json = $module['json'] ?? [];
    if (is_string($json)) {
        $json = $json === '' ? [] : [$json];
    }

    return is_array($json) ? array_values(array_map('strval', $json)) : [];
}

/**
 * @param array<string, mixed> $module
 * @return array<string, mixed>
 */
function jeab_cutover_delete_json(array $module): array
{
    $id = (string) ($module['id'] ?? '');
    $paths = $id === 'fullmakt' ? jeab_cutover_fullmakt_json_paths() : jeab_cutover_module_paths($module);
    $deleted = [];
    foreach ($paths as $path) {
        if (!jeab_cutover_path_allowed($path) || !is_file($path)) {
            continue;
        }
        if (!str_ends_with(strtolower($path), '.json') && !str_ends_with(strtolower($path), '.jsonl')) {
            continue;
        }
        if (@unlink($path)) {
            $deleted[] = $path;
        }
    }

    return ['ok' => true, 'deleted' => $deleted];
}

/** @param array<string, mixed> $module @return list<string> */
function jeab_cutover_module_paths(array $module): array
{
    $out = [];
    foreach (jeab_cutover_json_rels($module) as $rel) {
        $moduleOne = $module;
        $moduleOne['json'] = [$rel];
        $path = jeab_migrate_source_path($moduleOne);
        if ($path !== '') {
            $out[] = $path;
        }
    }
    if ((string) ($module['id'] ?? '') === 'biz_docs' && function_exists('jeab_biz_doc_names')) {
        foreach (jeab_biz_doc_names() as $name) {
            if (function_exists('jeab_biz_doc_json_path')) {
                $path = jeab_biz_doc_json_path($name);
                if ($path !== '') {
                    $out[] = $path;
                }
            }
        }
    }

    return array_values(array_unique($out));
}

/** @return list<string> */
function jeab_cutover_fullmakt_json_paths(): array
{
    $out = [];
    foreach (jeab_migrate_fullmakt_file_list() as $path) {
        $out[] = $path;
    }

    return $out;
}

function jeab_cutover_path_allowed(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }
    $real = realpath($path);
    if ($real === false) {
        return false;
    }
    $real = str_replace('\\', '/', $real);
    if (str_contains($real, '/secrets/')) {
        return false;
    }
    $roots = [];
    if (function_exists('jeab_data_dir')) {
        $data = realpath(jeab_data_dir());
        if (is_string($data)) {
            $roots[] = str_replace('\\', '/', $data);
        }
    }
    $privat = realpath(jeab_cutover_privat_dir());
    if (is_string($privat)) {
        $roots[] = str_replace('\\', '/', $privat);
    }
    foreach ($roots as $root) {
        if ($real === $root || str_starts_with($real, $root . '/')) {
            return true;
        }
    }

    return false;
}

function jeab_cutover_mark_imported(string $id): void
{
    if ($id === 'notes' || $id === 'notes_state') {
        if (!function_exists('jeab_notes_store_pdo')) {
            return;
        }
        $pdo = jeab_notes_store_pdo();
        if (!$pdo instanceof PDO) {
            return;
        }
        jeab_notes_store_ensure($pdo);
        $state = jeab_notes_store_state($pdo);
        jeab_notes_store_put_state($pdo, (int) $state['next_id'], 1);
    }
    if ($id === 'sms_sent' && function_exists('jeab_sms_store_pdo')) {
        $pdo = jeab_sms_store_pdo();
        if ($pdo instanceof PDO) {
            jeab_sms_store_ensure($pdo);
            jeab_sms_store_mark_imported($pdo);
        }
    }
}

/**
 * Skriver typens JSON från SQL. Används av rollback när zip-filen saknas.
 *
 * @return array<string, mixed>
 */
function jeab_cutover_export_type(string $id): array
{
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find($id) : null;
    if (!is_array($module) || !empty($module['missing'])) {
        return ['ok' => false, 'error' => 'Modulen saknas.'];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.'];
    }
    try {
        $rows = jeab_migrate_select_owned($pdo, $module, []);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Tabellen gick inte att läsa.'];
    }
    if (!is_array($rows)) {
        $rows = [];
    }
    if ($id === 'fullmakt') {
        return jeab_cutover_export_fullmakt($rows);
    }
    $columns = is_array($module['columns'] ?? null) ? $module['columns'] : [];
    if (in_array('payload', $columns, true) && count($rows) === 1 && isset($rows[0]['payload'])) {
        $path = jeab_migrate_source_path($module);
        if ($path === '') {
            return ['ok' => false, 'error' => 'Filen saknar sökväg.'];
        }
        jeab_cutover_write_text($path, (string) $rows[0]['payload']);

        return ['ok' => true, 'files' => [$path]];
    }
    if ($id === 'biz_docs') {
        return jeab_cutover_export_biz($rows);
    }
    try {
        jeab_migrate_write_json($module, $rows);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Filen gick inte att skriva.'];
    }

    return ['ok' => true, 'files' => jeab_cutover_module_paths($module)];
}

/** @param list<array<string, mixed>> $rows @return array<string, mixed> */
function jeab_cutover_export_biz(array $rows): array
{
    if (!function_exists('jeab_biz_doc_names')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    $files = [];
    foreach ($rows as $row) {
        $key = (string) ($row['doc_key'] ?? '');
        $name = str_starts_with($key, 'biz_') ? substr($key, 4) : '';
        if ($name === '' || !function_exists('jeab_biz_doc_json_path')) {
            continue;
        }
        $path = jeab_biz_doc_json_path($name);
        if ($path === '') {
            continue;
        }
        jeab_cutover_write_text($path, (string) ($row['payload'] ?? '{}'));
        $files[] = $path;
    }

    return ['ok' => true, 'files' => $files];
}

/** @param list<array<string, mixed>> $rows @return array<string, mixed> */
function jeab_cutover_export_fullmakt(array $rows): array
{
    $files = [];
    foreach ($rows as $row) {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($row['token'] ?? $payload['token'] ?? '')) ?? '';
        if ($token === '') {
            continue;
        }
        $status = (string) ($row['status'] ?? $payload['status'] ?? 'pending');
        $slot = $status === 'signed' ? 'signed' : 'pending';
        $dir = jeab_migrate_fullmakt_primary_dir();
        if ($dir === '') {
            return ['ok' => false, 'error' => 'Fullmaktsmappen saknas.'];
        }
        $path = $dir . '/' . $slot . '/' . $token . '.json';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            continue;
        }
        jeab_cutover_write_text($path, $body);
        $files[] = $path;
    }

    return ['ok' => true, 'files' => $files];
}

function jeab_cutover_write_text(string $path, string $body): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Mappen gick inte att skapa.');
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $body, LOCK_EX) === false) {
        throw new RuntimeException('Filen gick inte att skriva.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Filen gick inte att skriva.');
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_cutover_rollback_type(array $input): array
{
    $id = trim((string) ($input['type'] ?? ''));
    $ids = jeab_cutover_staged_ids($id);
    $group = $ids !== [] ? $id : '';
    if ($ids === []) {
        if ($id === '' || in_array($id, jeab_cutover_all_skip(), true)) {
            return ['ok' => false, 'error' => 'Typen kan inte återställas här.', 'written' => false];
        }
        $ids = [$id];
    }
    $dry = !jeab_cutover_wants_write($input) && !array_key_exists('write', $input);
    if (array_key_exists('dry', $input)) {
        $flag = $input['dry'];
        $dry = !($flag === 0 || $flag === '0' || $flag === false);
    }
    if (array_key_exists('write', $input)) {
        $write = $input['write'];
        $dry = !($write === 1 || $write === '1' || $write === true);
    }
    if ($dry) {
        $missing = [];
        $backup = '';
        foreach ($ids as $one) {
            $found = jeab_cutover_latest_backup($one);
            if ($found === null) {
                $missing[] = $one;
            } elseif ($backup === '') {
                $backup = (string) $found['dir'];
            }
        }

        return [
            'ok' => $missing === [],
            'dry' => true,
            'written' => false,
            'type' => $id,
            'backup' => $backup,
            'error' => $missing === [] ? '' : 'Ingen backup.',
        ];
    }
    $pause = in_array($group !== '' ? $group : $id, ['external_queue', 'login_throttle'], true);
    if ($pause) {
        jeab_cutover_sends_pause(true);
    }
    $ok = true;
    $backup = '';
    $files = [];
    try {
        foreach ($ids as $one) {
            $oneResult = jeab_cutover_rollback_one($one);
            $ok = $ok && !empty($oneResult['ok']);
            if ($backup === '' && !empty($oneResult['backup'])) {
                $backup = (string) $oneResult['backup'];
            }
            if (is_array($oneResult['files'] ?? null)) {
                $files = array_merge($files, $oneResult['files']);
            }
        }
        if ($ok && $group === 'kontakter') {
            $ok = jeab_cutover_storage_flag('kontakter_storage', 'json');
        }
        if ($ok && $group === 'inkorg') {
            $ok = jeab_cutover_storage_flag('inkorg_storage', 'json');
        }
    } finally {
        if ($pause) {
            jeab_cutover_sends_pause(false);
        }
    }

    return [
        'ok' => $ok,
        'dry' => false,
        'written' => $ok,
        'type' => $id,
        'backup' => $backup,
        'files' => $files,
        'error' => $ok ? '' : 'Återställningen stoppades.',
    ];
}

/**
 * @return array<string, mixed>
 */
function jeab_cutover_json_present(string $id): bool
{
    $module = function_exists('jeab_migrate_find') ? jeab_migrate_find($id) : null;
    if (!is_array($module)) {
        return false;
    }
    $paths = $id === 'fullmakt' ? jeab_cutover_fullmakt_json_paths() : jeab_cutover_module_paths($module);
    if ($paths === []) {
        return false;
    }
    foreach ($paths as $path) {
        if (!is_file($path)) {
            return false;
        }
    }

    return true;
}

function jeab_cutover_rollback_one(string $id): array
{
    if (jeab_cutover_json_present($id)) {
        $marked = jeab_data_type_mark($id, 'json');

        return [
            'ok' => $marked,
            'dry' => false,
            'written' => $marked,
            'type' => $id,
            'backup' => '',
            'files' => [],
            'kept_json' => true,
            'error' => $marked ? '' : 'Flaggan gick inte att sätta.',
        ];
    }
    $found = jeab_cutover_latest_backup($id);
    $prevDdl = $GLOBALS['_JEAB_SCHEMA_DDL'] ?? null;
    $GLOBALS['_JEAB_SCHEMA_DDL'] = true;
    $GLOBALS['_JEAB_DATA_MIGRATE'] = true;
    try {
        $exported = ['ok' => false, 'files' => []];
        $fromZip = 0;
        if ($found !== null) {
            $fromZip = jeab_cutover_restore_zip_files($found);
            if ($fromZip < 1) {
                $exported = jeab_cutover_export_type($id);
            } else {
                $exported = ['ok' => true, 'files' => []];
            }
            jeab_cutover_restore_dump($id, $found);
        } else {
            $exported = jeab_cutover_export_type($id);
        }
        $marked = jeab_data_type_mark($id, 'json');
    } finally {
        if ($prevDdl === null) {
            unset($GLOBALS['_JEAB_SCHEMA_DDL']);
        } else {
            $GLOBALS['_JEAB_SCHEMA_DDL'] = $prevDdl;
        }
        unset($GLOBALS['_JEAB_DATA_MIGRATE']);
    }

    return [
        'ok' => $marked && (!empty($exported['ok']) || $fromZip > 0),
        'dry' => false,
        'written' => true,
        'type' => $id,
        'backup' => $found['dir'] ?? '',
        'files' => $exported['files'] ?? [],
        'error' => $marked ? (string) ($exported['error'] ?? '') : 'Flaggan gick inte att sätta.',
    ];
}

/** @return array{dir:string,manifest:array<string,mixed>}|null */
function jeab_cutover_latest_backup(string $id): ?array
{
    $root = jeab_cutover_backup_root();
    if (!is_dir($root)) {
        return null;
    }
    $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
    rsort($dirs);
    foreach ($dirs as $dir) {
        $raw = is_file($dir . '/manifest.txt') ? (string) file_get_contents($dir . '/manifest.txt') : '';
        $manifest = json_decode($raw, true);
        if (is_array($manifest) && (string) ($manifest['type'] ?? '') === $id) {
            return ['dir' => $dir, 'manifest' => $manifest];
        }
    }

    return null;
}

/** @param array{dir:string,manifest:array<string,mixed>} $found */
function jeab_cutover_restore_dump(string $id, array $found): void
{
    if ($id === 'purchases' || $id === 'orders') {
        throw new RuntimeException('Ordern kan bara raderas via Radera.');
    }
    if ($id === 'reminders') {
        throw new RuntimeException('Påminnelsen kan bara raderas via Radera.');
    }
    if ($id === 'inbox_threads') {
        throw new RuntimeException('Ärendet kan bara raderas via Radera.');
    }
    $file = $found['dir'] . '/tables.dump';
    if (!is_file($file)) {
        return;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    $rows = is_array($decoded) ? ($decoded['rows'] ?? []) : [];
    if (!is_array($rows)) {
        return;
    }
    $module = jeab_migrate_find($id);
    $pdo = jeab_data_pdo();
    if (!is_array($module) || !$pdo instanceof PDO) {
        return;
    }
    jeab_migrate_ensure($pdo, $module);
    $pk = (string) ($module['primary_key'] ?? '');
    $current = [];
    try {
        $current = array_keys(jeab_migrate_owned_keys($pdo, $module));
    } catch (Throwable) {
        $current = [];
    }
    jeab_cutover_delete_keys($pdo, $module, $current);
    $list = [];
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row[$pk] ?? '') !== '') {
            $list[] = $row;
        }
    }
    if ($list !== []) {
        jeab_migrate_upsert($pdo, $module, $list);
    }
}

/** @param array{dir:string,manifest:array<string,mixed>} $found */
function jeab_cutover_restore_zip_files(array $found): int
{
    $zipPath = jeab_cutover_backup_zip_file($found);
    if ($zipPath === '' || !class_exists('ZipArchive')) {
        return 0;
    }
    $rels = $found['manifest']['json'] ?? [];
    if (!is_array($rels) || $rels === []) {
        return 0;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return 0;
    }
    $data = function_exists('jeab_data_dir') ? rtrim(jeab_data_dir(), '/') : '';
    $written = 0;
    foreach ($rels as $rel) {
        $rel = ltrim(str_replace('\\', '/', (string) $rel), '/');
        if ($rel === '' || !str_starts_with($rel, 'data/')) {
            continue;
        }
        $entry = $zip->getFromName($rel);
        if (!is_string($entry) || $data === '') {
            continue;
        }
        $dest = $data . '/' . substr($rel, strlen('data/'));
        try {
            jeab_cutover_write_text($dest, $entry);
            $written++;
        } catch (Throwable) {
        }
    }
    $zip->close();

    return $written;
}

function jeab_data_type_lists(): array
{
    $sql = jeab_data_type_read_list('sql');
    $json = jeab_data_type_read_list('json');

    return ['sql' => $sql, 'json' => $json];
}

/** @return list<string> */
function jeab_data_type_read_list(string $mode): array
{
    $key = $mode === 'json' ? 'data_json_types' : 'data_sql_types';
    $fileName = $mode === 'json' ? 'data-json-types.txt' : 'data-sql-types.txt';
    $items = [];
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $raw = (string) jeab_settings_value($pdo, $driver, $key);
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $items[$part] = true;
            }
        }
    }
    $file = jeab_cutover_privat_dir() . '/' . $fileName;
    if (is_file($file)) {
        foreach (preg_split("/\r\n|\n|\r/", (string) file_get_contents($file)) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $items[$part] = true;
            }
        }
    }

    return array_keys($items);
}

function jeab_data_type_forced(string $mode, string $type): bool
{
    $cache = $GLOBALS['_JEAB_DATA_TYPE_LISTS'] ?? null;
    if (!is_array($cache)) {
        $cache = jeab_data_type_lists();
        $GLOBALS['_JEAB_DATA_TYPE_LISTS'] = $cache;
    }
    $list = $cache[$mode] ?? [];

    return is_array($list) && in_array($type, $list, true);
}

function jeab_data_type_mark(string $type, string $mode): bool
{
    if ($mode !== 'sql' && $mode !== 'json') {
        return false;
    }
    $sql = array_fill_keys(jeab_data_type_read_list('sql'), true);
    $json = array_fill_keys(jeab_data_type_read_list('json'), true);
    unset($sql[$type], $json[$type]);
    if ($mode === 'sql') {
        $sql[$type] = true;
    } else {
        $json[$type] = true;
    }
    $ok = jeab_data_type_store('data_sql_types', 'data-sql-types.txt', array_keys($sql));
    $ok = jeab_data_type_store('data_json_types', 'data-json-types.txt', array_keys($json)) && $ok;
    unset($GLOBALS['_JEAB_DATA_TYPE_LISTS']);

    return $ok;
}

/** @param list<string> $ids */
function jeab_data_type_store(string $key, string $fileName, array $ids): bool
{
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
    sort($ids);
    $text = implode(',', $ids);
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $saved = false;
    if ($pdo instanceof PDO && function_exists('jeab_settings_put')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $saved = jeab_settings_put($pdo, $driver, $key, $text);
    }
    $dir = jeab_cutover_privat_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $fileOk = false;
    if (is_dir($dir) && is_writable($dir)) {
        $body = $ids === [] ? '' : implode("\n", $ids) . "\n";
        $fileOk = file_put_contents($dir . '/' . $fileName, $body, LOCK_EX) !== false;
    }

    return $saved || $fileOk;
}

/** @param callable(?array): array $fn */
function jeab_classify_pending_locked(callable $fn): mixed
{
    $doc = function_exists('jeab_cutover_doc_fetch') ? jeab_cutover_doc_fetch('classify_pending') : null;
    if (!is_array($doc)) {
        $doc = [];
    }
    [$out, $new] = $fn($doc === [] ? null : $doc);
    if ($new !== null) {
        if (!is_array($new) || !function_exists('jeab_cutover_doc_put')) {
            throw new RuntimeException('Klassningskön kan inte sparas.');
        }
        jeab_cutover_doc_put('classify_pending', $new);
    }

    return $out;
}

/**
 * Backupens fp-poster skriver över. Poster som bara finns i SQL behålls.
 *
 * @param array<string, mixed> $backup
 * @param array<string, mixed> $current
 * @return array<string, mixed>
 */
function jeab_cutover_merge_fp_posts(array $backup, array $current): array
{
    foreach ($backup as $key => $row) {
        if (!is_string($key) || !str_starts_with($key, 'fp:') || !is_array($row)) {
            continue;
        }
        $current[$key] = $row;
    }

    return $current;
}

function jeab_cutover_fp_count(array $doc): int
{
    $n = 0;
    foreach ($doc as $key => $_row) {
        if (is_string($key) && str_starts_with($key, 'fp:')) {
            $n++;
        }
    }

    return $n;
}

/**
 * @param array<string, mixed> $backup
 * @param array<string, mixed> $current
 * @return list<array<string, mixed>>
 */
function jeab_cutover_fp_diff(array $backup, array $current): array
{
    $keys = [];
    foreach ([$backup, $current] as $doc) {
        foreach ($doc as $key => $_row) {
            if (is_string($key) && str_starts_with($key, 'fp:')) {
                $keys[$key] = true;
            }
        }
    }
    ksort($keys);
    $diffs = [];
    foreach (array_keys($keys) as $key) {
        $fromBackup = is_array($backup[$key] ?? null) ? $backup[$key] : null;
        $fromSql = is_array($current[$key] ?? null) ? $current[$key] : null;
        $fields = [];
        foreach (['via', 'at', 'person', 'head'] as $field) {
            $left = is_array($fromBackup) ? (string) ($fromBackup[$field] ?? '') : '';
            $right = is_array($fromSql) ? (string) ($fromSql[$field] ?? '') : '';
            if ($left !== $right) {
                $fields[] = ['name' => $field, 'backup' => $left, 'sql' => $right];
            }
        }
        if ($fromBackup === null || $fromSql === null || $fields !== []) {
            $diffs[] = [
                'id' => $key,
                'only' => $fromBackup === null ? 'sql' : ($fromSql === null ? 'backup' : ''),
                'fields' => $fields,
            ];
        }
    }

    return $diffs;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_cutover_restore_doc_dispatch(array $input): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }
    $result = jeab_cutover_restore_doc($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/**
 * Återställ en dokumentrad från senaste tables.dump. Ingen DDL.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_cutover_restore_doc(array $input): array
{
    $type = trim((string) ($input['type'] ?? ''));
    $key = trim((string) ($input['key'] ?? ''));
    if ($type !== 'biz_docs' || $key !== 'biz_code_people') {
        return ['ok' => false, 'error' => 'Bara biz_docs och biz_code_people.', 'dry' => true, 'written' => false];
    }
    $dry = !jeab_cutover_wants_write($input);
    $found = jeab_cutover_latest_backup($type);
    if ($found === null) {
        return ['ok' => false, 'error' => 'Ingen backup.', 'dry' => $dry, 'written' => false, 'type' => $type, 'key' => $key];
    }
    $loaded = jeab_cutover_backup_doc($found, $key);
    if (empty($loaded['ok']) || !is_array($loaded['doc'] ?? null)) {
        return [
            'ok' => false,
            'error' => (string) ($loaded['error'] ?? 'Dokumentet saknas i backupen.'),
            'dry' => $dry,
            'written' => false,
            'type' => $type,
            'key' => $key,
            'backup' => basename((string) $found['dir']),
        ];
    }
    $backupDoc = $loaded['doc'];
    $current = jeab_cutover_sql_doc($key);
    if (empty($current['ok']) || !is_array($current['doc'] ?? null)) {
        return [
            'ok' => false,
            'error' => (string) ($current['error'] ?? 'SQL gick inte att läsa.'),
            'dry' => $dry,
            'written' => false,
            'type' => $type,
            'key' => $key,
            'backup' => basename((string) $found['dir']),
        ];
    }
    $sqlDoc = $current['doc'];
    $before = jeab_cutover_fp_count($sqlDoc);
    $merged = jeab_cutover_merge_fp_posts($backupDoc, $sqlDoc);
    $after = jeab_cutover_fp_count($merged);
    $diffs = jeab_cutover_fp_diff($backupDoc, $sqlDoc);
    $base = [
        'ok' => true,
        'dry' => $dry,
        'written' => false,
        'type' => $type,
        'key' => $key,
        'backup' => basename((string) $found['dir']),
        'before' => $before,
        'after' => $after,
        'diffs' => $diffs,
    ];
    if ($dry) {
        return $base;
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_put')) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'dry' => false, 'written' => false, 'type' => $type, 'key' => $key] + $base;
    }
    try {
        jeab_sql_doc_put($pdo, $key, $merged);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Dokumentet gick inte att skriva.', 'dry' => false, 'written' => false] + $base;
    }
    if (!jeab_data_type_mark('biz_docs', 'sql')) {
        return ['ok' => false, 'error' => 'Flaggan gick inte att sätta.', 'dry' => false, 'written' => true] + $base;
    }
    unset($GLOBALS['_JEAB_BIZ_DOC_CACHE'], $GLOBALS['_JEAB_DATA_TYPE_LISTS']);
    $base['dry'] = false;
    $base['written'] = true;
    $base['flag'] = 'sql';

    return $base;
}

/**
 * @param array{dir:string,manifest:array<string,mixed>} $found
 * @return array{ok:bool,error?:string,doc?:array<string,mixed>}
 */
function jeab_cutover_backup_doc(array $found, string $key): array
{
    $file = $found['dir'] . '/tables.dump';
    if (!is_file($file)) {
        return ['ok' => false, 'error' => 'tables.dump saknas.'];
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    $rows = is_array($decoded) ? ($decoded['rows'] ?? null) : null;
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'tables.dump gick inte att läsa.'];
    }
    foreach ($rows as $row) {
        if (!is_array($row) || (string) ($row['doc_key'] ?? '') !== $key) {
            continue;
        }
        $payload = $row['payload'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return ['ok' => false, 'error' => 'Dokumentet i backupen är tomt.'];
        }

        return ['ok' => true, 'doc' => $payload];
    }

    return ['ok' => false, 'error' => 'Nyckeln saknas i backupen.'];
}

/** @return array{ok:bool,error?:string,doc?:array<string,mixed>} */
function jeab_cutover_sql_doc(string $key): array
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_doc_get')) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.'];
    }
    try {
        $doc = jeab_sql_doc_get($pdo, $key);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'SQL gick inte att läsa.'];
    }

    return ['ok' => true, 'doc' => is_array($doc) ? $doc : []];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_cutover_inkorg_counts_dispatch(array $input): array
{
    unset($input);
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_cutover_inkorg_counts();
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_cutover_inkorg_probe_dispatch(array $input): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    require_once __DIR__ . '/inkorg_fast.php';
    $prev = $GLOBALS['_JEAB_PAGE_REQUEST'] ?? null;
    $GLOBALS['_JEAB_PAGE_REQUEST'] = true;
    try {
        $result = jeab_inkorg_probe($input);
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_PAGE_REQUEST']);
        } else {
            $GLOBALS['_JEAB_PAGE_REQUEST'] = $prev;
        }
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_diag_mina_dispatch(array $input): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    require_once __DIR__ . '/reminders.php';
    $prev = $GLOBALS['_JEAB_PAGE_REQUEST'] ?? null;
    $GLOBALS['_JEAB_PAGE_REQUEST'] = true;
    try {
        $result = jeab_diag_mina($input);
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_PAGE_REQUEST']);
        } else {
            $GLOBALS['_JEAB_PAGE_REQUEST'] = $prev;
        }
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/**
 * Radantal. Ingen DDL och ingen skrivning.
 *
 * @return array<string, mixed>
 */
function jeab_cutover_inkorg_counts(): array
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    $sql = [];
    if (!$pdo instanceof PDO) {
        $sql['error'] = 'Ingen SQL-databas.';
    } else {
        $sql['inkorg_messages'] = jeab_cutover_count_scalar($pdo, 'SELECT COUNT(*) FROM inkorg_messages');
        $sql['inkorg_threads'] = jeab_cutover_count_scalar($pdo, 'SELECT COUNT(*) FROM inkorg_threads');
        $sql['inkorg_list'] = jeab_cutover_count_grouped($pdo, 'SELECT company, list_status, COUNT(*) AS n FROM inkorg_list GROUP BY company, list_status');
        $sql['jeab_hs_import_index'] = jeab_cutover_count_scalar($pdo, 'SELECT COUNT(*) FROM jeab_hs_import_index');
        $sql['jeab_hs_import_index_by'] = jeab_cutover_count_grouped($pdo, 'SELECT list_company, list_status, COUNT(*) AS n FROM jeab_hs_import_index GROUP BY list_company, list_status');
        $sql['jeab_hs_import_conv'] = jeab_cutover_count_scalar($pdo, 'SELECT COUNT(*) FROM jeab_hs_import_conv');
        $sql['jeab_hs_import_state'] = jeab_cutover_count_scalar($pdo, 'SELECT COUNT(*) FROM jeab_hs_import_state');
    }
    $flags = [
        'inkorg_storage' => function_exists('jeab_inkorg_storage_mode') ? jeab_inkorg_storage_mode() : '',
        'inkorg_storage_file' => jeab_cutover_storage_file('inkorg_storage'),
        'inbox' => jeab_cutover_type_flag('inbox'),
        'inbox_threads' => jeab_cutover_type_flag('inbox_threads'),
        'helpscout_import' => jeab_cutover_type_flag('helpscout_import'),
    ];

    $json = jeab_cutover_inkorg_json_counts();
    $sqlFigures = $pdo instanceof PDO
        ? ['arenden' => jeab_cutover_figure_inkorg(), 'historik' => jeab_cutover_figure_historik()]
        : ['arenden' => -1, 'historik' => -1];
    $jsonFigures = jeab_cutover_inkorg_json_figures();
    $sql['figures'] = $sqlFigures;
    $archive = $pdo instanceof PDO ? jeab_cutover_hs_archive_compare($pdo) : null;
    $json['figures'] = $jsonFigures;
    // Snabbkoll före radering: SQL får inte visa färre ärenden eller mindre Historik än JSON.
    $compare = [
        'arenden_sql' => $sqlFigures['arenden'],
        'arenden_json' => $jsonFigures['arenden'],
        'historik_sql' => $sqlFigures['historik'],
        'historik_json' => $jsonFigures['historik'],
    ];
    $compare['ok'] = $sqlFigures['arenden'] >= 0 && $sqlFigures['historik'] >= 0
        && $jsonFigures['error'] === ''
        && $sqlFigures['arenden'] >= $jsonFigures['arenden']
        && $sqlFigures['historik'] >= $jsonFigures['historik'];

    return [
        'ok' => true,
        'dry' => true,
        'written' => false,
        'sql' => $sql,
        'json' => $json,
        'compare' => $compare,
        'archive' => $archive,
        'flags' => $flags,
        'note' => 'SQL-läge räknar Inkorg och Historik från inkorg_list (company och list_status) och tar inte med jeab_hs_import_index i sidotalet. Arkivet syns i Historik bara när list_company och list_status är fyllda. JSON-läge läser messages.json och threads.json, och sidotalet hoppar över importarkivet.',
    ];
}

function jeab_cutover_type_flag(string $type): string
{
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', $type)) {
        return 'sql';
    }
    if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('json', $type)) {
        return 'json';
    }

    return '';
}

/** @return array{ok:bool,n:int,error:string} */
function jeab_cutover_count_scalar(PDO $pdo, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        $n = $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : 0;

        return ['ok' => true, 'n' => $n, 'error' => ''];
    } catch (Throwable) {
        return ['ok' => false, 'n' => 0, 'error' => 'Tabellen gick inte att läsa.'];
    }
}

/** @return array{ok:bool,rows:list<array<string,mixed>>,error:string} */
function jeab_cutover_count_grouped(PDO $pdo, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        $rows = [];
        foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'company' => (string) ($row['company'] ?? $row['list_company'] ?? ''),
                'list_status' => (string) ($row['list_status'] ?? ''),
                'n' => (int) ($row['n'] ?? 0),
            ];
        }

        return ['ok' => true, 'rows' => $rows, 'error' => ''];
    } catch (Throwable) {
        return ['ok' => false, 'rows' => [], 'error' => 'Tabellen gick inte att läsa.'];
    }
}

/**
 * Ärenden och Historik räknat ur threads.json (samma status som inkorg_list) plus importarkivets rader.
 *
 * @return array{arenden:int,historik:int,threads:int,archive:int,error:string}
 */
function jeab_cutover_inkorg_json_figures(?string $dir = null): array
{
    $dir ??= function_exists('jeab_data_dir') ? rtrim(jeab_data_dir(), '/') . '/inkorg' : '';
    $out = ['arenden' => 0, 'historik' => 0, 'threads' => 0, 'archive' => 0, 'error' => ''];
    $path = $dir . '/threads.json';
    if ($dir === '' || !is_file($path)) {
        $out['error'] = 'saknas';

        return $out;
    }
    $size = filesize($path);
    if ($size !== false && $size > 32 * 1024 * 1024) {
        $out['error'] = 'for_stor';

        return $out;
    }
    $data = json_decode((string) file_get_contents($path), true);
    $threads = is_array($data) && is_array($data['threads'] ?? null) ? $data['threads'] : null;
    if ($threads === null) {
        $out['error'] = 'gick inte att läsa';

        return $out;
    }
    foreach ($threads as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out['threads']++;
        $status = function_exists('jeab_inkorg_list_row_status')
            ? jeab_inkorg_list_row_status($row)
            : (!empty($row['deleted']) ? 'deleted' : (trim((string) ($row['status'] ?? '')) ?: 'open'));
        if ($status === 'closed' || $status === 'deleted') {
            $out['historik']++;
        } else {
            $out['arenden']++;
        }
    }
    $index = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/index.jsonl' : $dir . '/hs-import/index.jsonl';
    // index.jsonl får en ny rad varje gång en konversation uppdateras. Läsaren tar sista raden per id,
    // så arkivet räknas som unika id, inte rader.
    $out['archive'] = count(jeab_cutover_hs_jsonl_scan($index)['ids']);
    $out['historik'] += $out['archive'];

    return $out;
}

/** @return array<string, mixed> */
function jeab_cutover_inkorg_json_counts(): array
{
    $dir = function_exists('jeab_data_dir') ? rtrim(jeab_data_dir(), '/') . '/inkorg' : '';
    $messages = jeab_cutover_json_key_count($dir . '/messages.json', 'messages');
    $notes = jeab_cutover_json_key_count($dir . '/messages.json', 'notes');
    $threads = jeab_cutover_json_key_count($dir . '/threads.json', 'threads');
    $index = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/index.jsonl' : $dir . '/hs-import/index.jsonl';
    $scan = jeab_cutover_hs_jsonl_scan($index);

    return [
        'messages' => $messages,
        'notes' => $notes,
        'threads' => $threads,
        'index_jsonl' => ['n' => $scan['lines'], 'distinct' => count($scan['ids']), 'error' => $scan['error']],
    ];
}

/**
 * Rader och unika hsi-id i importarkivets index.jsonl (samma id-regel som jeab_hs_archive_index_json_map).
 *
 * @return array{lines:int,ids:array<string,true>,error:string}
 */
function jeab_cutover_hs_jsonl_scan(string $path): array
{
    $out = ['lines' => 0, 'ids' => [], 'error' => ''];
    if ($path === '' || !is_file($path)) {
        $out['error'] = 'saknas';

        return $out;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        $out['error'] = 'gick inte att läsa';

        return $out;
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $out['lines']++;
        $row = json_decode($line, true);
        $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
        if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) === 1) {
            $out['ids'][$id] = true;
        }
    }
    fclose($fh);

    return $out;
}

/**
 * Unika arkiv-id i index.jsonl som saknas i jeab_hs_import_index. Bara läsning.
 *
 * @return array{jsonl_lines:int,jsonl_distinct:int,sql:int,json_not_in_sql:int,sample:list<string>,error:string}
 */
function jeab_cutover_hs_archive_compare(PDO $pdo): array
{
    $index = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/index.jsonl' : '';
    $scan = jeab_cutover_hs_jsonl_scan($index);
    $out = ['jsonl_lines' => $scan['lines'], 'jsonl_distinct' => count($scan['ids']), 'sql' => -1, 'json_not_in_sql' => -1, 'sample' => [], 'error' => $scan['error']];
    try {
        $have = [];
        $stmt = $pdo->query('SELECT id FROM jeab_hs_import_index');
        foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $hsId) {
            $have[(string) $hsId] = true;
        }
    } catch (Throwable) {
        $out['error'] = 'SQL gick inte att läsa';

        return $out;
    }
    $out['sql'] = count($have);
    $missing = array_diff_key($scan['ids'], $have);
    $out['json_not_in_sql'] = count($missing);
    $out['sample'] = array_slice(array_keys($missing), 0, 5);

    return $out;
}

/** @return array{n:int,error:string} */
function jeab_cutover_json_key_count(string $path, string $key): array
{
    if ($path === '' || !is_file($path)) {
        return ['n' => 0, 'error' => 'saknas'];
    }
    $size = filesize($path);
    if ($size !== false && $size > 32 * 1024 * 1024) {
        return ['n' => 0, 'error' => 'for_stor'];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) {
        return ['n' => 0, 'error' => 'gick inte att läsa'];
    }

    return ['n' => count($data[$key]), 'error' => ''];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_cutover_set_storage_dispatch(array $input): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }
    $result = jeab_cutover_set_storage($input);
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/**
 * Sätter bara flaggan. Ingen backfill och ingen radering.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_cutover_set_storage(array $input): array
{
    $type = trim((string) ($input['type'] ?? ''));
    $mode = trim((string) ($input['mode'] ?? ''));
    if (($mode !== 'sql' && $mode !== 'json') || !preg_match('/\A[a-z0-9_]+\z/', $type)) {
        return ['ok' => false, 'error' => 'type och mode saknas.', 'dry' => true, 'written' => false];
    }
    $group = $type === 'inkorg';
    $known = $group || in_array($type, jeab_cutover_all_types(), true);
    if (!$known) {
        foreach (jeab_cutover_staged_order() as $name) {
            if (in_array($type, jeab_cutover_staged_ids($name), true)) {
                $known = true;
                break;
            }
        }
    }
    if (!$known) {
        return ['ok' => false, 'error' => 'Typen kan inte flaggas här.', 'dry' => true, 'written' => false, 'type' => $type];
    }
    $write = jeab_cutover_wants_write($input);
    $target = $group ? 'inkorg_storage' : $type;
    if (!$write) {
        return [
            'ok' => true,
            'dry' => true,
            'written' => false,
            'type' => $type,
            'mode' => $mode,
            'target' => $target,
            'current' => $group ? (function_exists('jeab_inkorg_storage_mode') ? jeab_inkorg_storage_mode() : '') : jeab_cutover_type_flag($type),
        ];
    }
    $ok = $group ? jeab_cutover_storage_flag('inkorg_storage', $mode) : jeab_data_type_mark($type, $mode);
    unset($GLOBALS['_JEAB_DATA_TYPE_LISTS']);

    return [
        'ok' => $ok,
        'dry' => false,
        'written' => $ok,
        'type' => $type,
        'mode' => $mode,
        'target' => $target,
        'current' => $group ? (function_exists('jeab_inkorg_storage_mode') ? jeab_inkorg_storage_mode() : '') : jeab_cutover_type_flag($type),
        'error' => $ok ? '' : 'Flaggan gick inte att sätta.',
    ];
}

/** diag.opcache. Bara ini och status. Ingen sökväg, ingen DDL. */
function jeab_diag_opcache_dispatch(): array
{
    if (!jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $report = function_exists('jeab_opcache_report') ? jeab_opcache_report() : ['ok' => false, 'error' => 'Rapporten saknas.'];
    $report['status'] = 200;

    return $report;
}
