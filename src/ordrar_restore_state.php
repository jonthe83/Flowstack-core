<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
require_once __DIR__ . '/ordrar_state_sources.php';
jeab_deny_if_direct(__FILE__);

/**
 * ordrar.restore_state_from_snapshot.
 *
 * jeab_ordrar_restore_build sparar bara reminder_id, contact_id, kind, company,
 * address, step (1 eller 2), status och tid. Det räcker inte för v3.
 * En laddbox utan fortnox_order och utan bokning blir «Bekräfta 0 av 2»
 * och «väntar på följdfrågor», och listan har inget installationsdatum.
 *
 * Fält som källan kan ha och som build släpper:
 * company när det bara står i co, kind/product, step över 2, status,
 * steps (bokad_tid och övriga v3-stämplar), booking_id, install_date,
 * fortnox_order, pris, kundpris, inkop_lines, fortnox_order_rows, kalkyl.
 *
 * Kroken skriver bara de fälten, och bara på ordrar som order_restore_log
 * visar att restore_from_snapshot skapade. En källa har läge först när
 * den har step och minst ett till av steps, install_date, fortnox_order,
 * rows eller kalkyl. Bara co är svagt och räknas inte. Nyaste lagrade
 * källan med läge vinner: ögonblicksbild, data/*.json, data/.private
 * (säkerhetskopior, trash, cutover), order_audit, purchases_history,
 * purchase_store, påminnelse och inkorg. Kalender och Fortnox-mappning
 * är fakta till ordrar.rebuild_state när ingen källa har läget.
 * Skickar inget.
 */

function jeab_ordrar_restore_state_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
    }
    $action = trim((string) ($input['action'] ?? 'ordrar.restore_state_from_snapshot'));
    if ($action === 'ordrar.restore_state_undo') {
        return jeab_ordrar_restore_state_undo($store, $input);
    }

    return jeab_ordrar_restore_state($store, $input);
}

/** @return list<string> */
function jeab_ordrar_restore_state_keys(): array
{
    return [
        'company', 'kind', 'step', 'status', 'booking_id', 'install_date',
        'fortnox_order', 'pris', 'kundpris', 'steps', 'inkop_lines',
        'fortnox_order_rows', 'kalkyl',
    ];
}

/**
 * Lägesfält som build inte tar med, för en rå orderrad.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_ordrar_restore_state_dropped(array $row): array
{
    $id = (int) ($row['reminder_id'] ?? $row['id'] ?? 1);
    if ($id <= 0) {
        $id = 1;
    }
    $built = function_exists('jeab_ordrar_restore_build')
        ? jeab_ordrar_restore_build($id, ['orders_rows' => [(string) $id => $row]], null)
        : [];
    $want = jeab_ordrar_restore_state_extract($row);
    $have = jeab_ordrar_restore_state_extract(is_array($built) ? $built : []);
    $dropped = [];
    foreach (jeab_ordrar_restore_state_keys() as $key) {
        if (!array_key_exists($key, $want)) {
            continue;
        }
        $left = $want[$key];
        $right = $have[$key] ?? null;
        if ($left !== $right) {
            $dropped[] = $key;
        }
    }

    return $dropped;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_state_extract(array $row): array
{
    $out = [];
    $company = trim((string) ($row['company'] ?? $row['co'] ?? ''));
    if ($company !== '' && function_exists('jeab_normalize_company')) {
        $company = jeab_normalize_company($company) ?? '';
    }
    if ($company === 'jarfalla' || $company === 'malmo') {
        $out['company'] = $company;
    }
    $kind = trim((string) ($row['kind'] ?? $row['product'] ?? $row['produkt'] ?? $row['vad'] ?? ''));
    if ($kind !== '' && function_exists('jeab_purchase_normalize_kind')) {
        $kind = jeab_purchase_normalize_kind($kind) ?? $kind;
    }
    if ($kind !== '' && preg_match('/\A[a-z0-9_-]{1,32}\z/', $kind) === 1) {
        $out['kind'] = $kind;
    }
    if (array_key_exists('step', $row)) {
        $step = (int) $row['step'];
        if ($step >= 1 && $step <= 20) {
            $out['step'] = $step;
        }
    }
    $status = trim((string) ($row['status'] ?? ''));
    if ($status !== '' && preg_match('/\A[A-Za-z0-9_-]{1,16}\z/', $status) === 1) {
        $out['status'] = $status;
    }
    $booking = (int) ($row['booking_id'] ?? 0);
    if ($booking > 0) {
        $out['booking_id'] = $booking;
    }
    $install = jeab_ordrar_restore_state_date($row['install_date'] ?? $row['install_ymd'] ?? '');
    $steps = [];
    if (is_array($row['steps'] ?? null)) {
        foreach ($row['steps'] as $key => $value) {
            $name = trim((string) $key);
            if ($name === '' || strlen($name) > 32 || preg_match('/\A[A-Za-z0-9_-]+\z/', $name) !== 1) {
                continue;
            }
            if (is_int($value) || (is_string($value) && preg_match('/\A[1-9]\d*\z/', $value) === 1)) {
                $steps[$name] = (int) $value;
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $text = trim($value);
            if ($text === '' || strlen($text) > 32 || str_contains($text, '@')) {
                continue;
            }
            $steps[$name] = $text;
        }
    }
    $booked = jeab_ordrar_restore_state_date($steps['bokad_tid'] ?? '');
    if ($booked === '') {
        $booked = jeab_ordrar_restore_state_date($row['bokad_tid'] ?? '');
        if ($booked !== '') {
            $rawBooked = trim((string) ($row['bokad_tid'] ?? ''));
            if ($rawBooked !== '' && strlen($rawBooked) <= 32 && !str_contains($rawBooked, '@')) {
                $steps['bokad_tid'] = $rawBooked;
            }
        }
    }
    if (!is_array($row['inkop_lines'] ?? null) && is_array($row['rows'] ?? null)) {
        $row['inkop_lines'] = $row['rows'];
    }
    if (!is_array($row['inkop_lines'] ?? null) && is_array($row['orderrader'] ?? null)) {
        $row['inkop_lines'] = $row['orderrader'];
    }
    if ($booked === '' && $install !== '') {
        $steps['bokad_tid'] = $install;
    }
    if ($install === '' && $booked !== '') {
        $install = $booked;
    }
    if ($install !== '') {
        $out['install_date'] = $install;
    }
    if ($steps !== []) {
        $out['steps'] = $steps;
    }
    $fortnox = trim((string) ($row['fortnox_order'] ?? ''));
    if ($fortnox !== '' && preg_match('/\A[A-Za-z0-9-]{1,32}\z/', $fortnox) === 1) {
        $out['fortnox_order'] = $fortnox;
    }
    foreach (['pris', 'kundpris'] as $money) {
        if (!array_key_exists($money, $row)) {
            continue;
        }
        $amount = (int) $row[$money];
        if ($amount > 0) {
            $out[$money] = $amount;
        }
    }
    foreach (['inkop_lines', 'fortnox_order_rows', 'kalkyl'] as $bag) {
        if (!is_array($row[$bag] ?? null) || $row[$bag] === []) {
            continue;
        }
        $clean = jeab_ordrar_restore_state_lines($row[$bag]);
        if ($clean !== []) {
            $out[$bag] = $clean;
        }
    }

    return $out;
}

function jeab_ordrar_restore_state_date(mixed $value): string
{
    $value = trim((string) $value);
    if (preg_match('/\A(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * @param array<mixed> $rows
 * @return list<array<string, int|string>>
 */
function jeab_ordrar_restore_state_lines(array $rows): array
{
    $out = [];
    foreach ($rows as $line) {
        if (!is_array($line)) {
            continue;
        }
        $clean = [];
        foreach ($line as $key => $value) {
            $name = (string) $key;
            if (in_array($name, ['name', 'email', 'phone', 'personnummer', 'pnr', 'note', 'notes', 'customer'], true)) {
                continue;
            }
            if (is_int($value)) {
                $clean[$name] = $value;
                continue;
            }
            if (is_float($value)) {
                $clean[$name] = (int) round($value);
                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text === '' || strlen($text) > 80 || str_contains($text, '@')) {
                continue;
            }
            $clean[$name] = $text;
        }
        if ($clean !== []) {
            $out[] = $clean;
        }
        if (count($out) >= 40) {
            break;
        }
    }

    return $out;
}

/**
 * @return list<array{source:string,at:string,file:string,row:array<string,mixed>}>
 */
function jeab_ordrar_restore_state_candidates(PDO $pdo, int $id): array
{
    $found = [];
    if (function_exists('jeab_ordrar_restore_snapshots') && function_exists('jeab_ordrar_restore_detail')) {
        foreach (jeab_ordrar_restore_snapshots() as $snap) {
            $detail = jeab_ordrar_restore_detail($snap['data'], $id);
            if (!is_array($detail)) {
                continue;
            }
            $at = jeab_ordrar_restore_state_stamp($detail['updated_at'] ?? '', (string) ($snap['at'] ?? ''));
            $found[] = [
                'source' => 'snapshot',
                'at' => $at,
                'file' => '',
                'path' => 'contacts_sole_undo',
                'row' => $detail,
            ];
        }
    }
    foreach (jeab_ordrar_restore_state_files() as $file) {
        foreach (jeab_ordrar_restore_state_file_rows($file['path'], $id) as $row) {
            $at = jeab_ordrar_restore_state_stamp($row['updated_at'] ?? $row['created_at'] ?? '', $file['at']);
            $found[] = [
                'source' => $file['source'],
                'at' => $at,
                'file_at' => (string) ($file['at'] ?? ''),
                'file' => $file['file'],
                'path' => (string) ($file['rel'] ?? $file['file']),
                'row' => $row,
            ];
        }
    }
    foreach (['purchases_history' => 'purchases_history', 'order_audit' => 'audit', 'purchase_store' => 'purchase_store'] as $table => $source) {
        foreach (jeab_ordrar_restore_state_table_rows($pdo, $table, $source, $id) as $hit) {
            $found[] = $hit;
        }
    }
    if (function_exists('jeab_ordrar_state_sources_stored')) {
        foreach (jeab_ordrar_state_sources_stored($pdo, $id) as $hit) {
            $found[] = $hit;
        }
    }

    return $found;
}

function jeab_ordrar_restore_state_stamp(mixed $primary, string $fallback): string
{
    $primary = trim((string) $primary);
    if (preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $primary) === 1) {
        return substr(str_replace('T', ' ', $primary), 0, 19);
    }
    $fallback = trim($fallback);
    if (preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $fallback) === 1) {
        return substr(str_replace('T', ' ', $fallback), 0, 19);
    }

    return '';
}

/** @return list<array{path:string,file:string,rel:string,at:string,source:string}> */
function jeab_ordrar_restore_state_files(): array
{
    return jeab_ordrar_state_sources_files();
}

/** @return list<array<string, mixed>> */
function jeab_ordrar_restore_state_file_rows(string $path, int $id): array
{
    return jeab_ordrar_state_sources_file_rows($path, $id);
}

/**
 * @return list<array{source:string,at:string,file:string,row:array<string,mixed>}>
 */
function jeab_ordrar_restore_state_table_rows(PDO $pdo, string $table, string $source, int $id): array
{
    if (!in_array($table, ['purchases_history', 'order_audit', 'purchase_store'], true) || !jeab_ordrar_restore_state_table($pdo, $table)) {
        return [];
    }
    $cols = jeab_ordrar_restore_state_columns($pdo, $table);
    $idCol = isset($cols['reminder_id']) ? 'reminder_id' : (isset($cols['order_id']) ? 'order_id' : '');
    $payloadCol = isset($cols['payload']) ? 'payload' : (isset($cols['data']) ? 'data' : '');
    if ($idCol === '' || $payloadCol === '') {
        return [];
    }
    $when = isset($cols['updated_at']) ? 'updated_at' : (isset($cols['created_at']) ? 'created_at' : '');
    $sql = 'SELECT ' . $payloadCol . ' AS payload' . ($when !== '' ? ', ' . $when . ' AS at_col' : '') . ' FROM ' . $table . ' WHERE ' . $idCol . ' = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $fetched = $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($fetched) ? $fetched : [] as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $row = json_decode((string) ($hit['payload'] ?? ''), true);
        if (!is_array($row)) {
            continue;
        }
        if (function_exists('jeab_ordrar_state_sources_unwrap')) {
            $row = jeab_ordrar_state_sources_unwrap($row);
        }
        $out[] = [
            'source' => $source,
            'at' => jeab_ordrar_restore_state_stamp($row['updated_at'] ?? '', (string) ($hit['at_col'] ?? '')),
            'file' => '',
            'path' => $table,
            'row' => $row,
        ];
    }

    return $out;
}

function jeab_ordrar_restore_state_table(PDO $pdo, string $table): bool
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);

            return (bool) $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string, true> */
function jeab_ordrar_restore_state_columns(PDO $pdo, string $table): array
{
    $have = [];
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = $driver === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll()
            : $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    } catch (Throwable) {
        return [];
    }
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = (string) ($row['Field'] ?? $row['name'] ?? '');
        if ($name !== '') {
            $have[$name] = true;
        }
    }

    return $have;
}

/**
 * Läge kräver step och minst ett till av steps, install_date,
 * fortnox_order, rows (inkop_lines/fortnox_order_rows) eller kalkyl.
 * Bara co, eller bara step, är svagt.
 *
 * @param array<string, mixed> $state
 */
function jeab_ordrar_restore_state_has_state(array $state): bool
{
    if (!isset($state['step'])) {
        return false;
    }
    foreach (['steps', 'install_date', 'fortnox_order', 'inkop_lines', 'fortnox_order_rows', 'kalkyl'] as $key) {
        if (!array_key_exists($key, $state)) {
            continue;
        }
        $value = $state[$key];
        if ($value === [] || $value === '' || $value === null) {
            continue;
        }

        return true;
    }

    return false;
}

/** @param array<string, mixed> $state */
function jeab_ordrar_restore_state_is_rich(array $state): bool
{
    foreach (['company', 'booking_id', 'install_date', 'fortnox_order', 'pris', 'kundpris', 'steps', 'inkop_lines', 'fortnox_order_rows', 'kalkyl'] as $key) {
        if (array_key_exists($key, $state)) {
            return true;
        }
    }

    return false;
}

/**
 * Nyast källa som har läge. Bara co, eller step utan steps/install_date/
 * fortnox_order/rows/kalkyl, faller igenom. En svag rad slår inte en rad
 * med faktura, installation eller rader.
 *
 * @param list<array{source:string,at:string,file:string,row:array<string,mixed>}> $candidates
 * @return array{source:string,at:string,file:string,row:array<string,mixed>}|null
 */
function jeab_ordrar_restore_state_pick(array $candidates): ?array
{
    $richWindow = [];
    $rich = [];
    $weakWindow = [];
    $weak = [];
    foreach ($candidates as $cand) {
        $state = jeab_ordrar_restore_state_extract($cand['row']);
        if (!jeab_ordrar_restore_state_has_state($state)) {
            continue;
        }
        $window = function_exists('jeab_ordrar_state_sources_window') && jeab_ordrar_state_sources_window($cand);
        if (jeab_ordrar_restore_state_is_rich($state)) {
            if ($window) {
                $richWindow[] = $cand;
            } else {
                $rich[] = $cand;
            }
            continue;
        }
        if ($window) {
            $weakWindow[] = $cand;
            continue;
        }
        $weak[] = $cand;
    }
    foreach ([$richWindow, $rich, $weakWindow, $weak] as $bucket) {
        if ($bucket !== []) {
            return jeab_ordrar_restore_state_newest($bucket);
        }
    }

    return null;
}

/**
 * @param list<array{source:string,at:string,file:string,row:array<string,mixed>}> $candidates
 * @return array{source:string,at:string,file:string,row:array<string,mixed>}|null
 */
function jeab_ordrar_restore_state_newest(array $candidates): ?array
{
    $best = null;
    $rank = [
        'snapshot' => 1,
        'file' => 2,
        'backup' => 2,
        'trash' => 3,
        'inkorg' => 3,
        'reminder' => 3,
        'cutover' => 4,
        'audit' => 5,
        'purchase_store' => 6,
        'purchases_history' => 7,
        'bak' => 4,
        'sql' => 4,
    ];
    foreach ($candidates as $cand) {
        if ($best === null) {
            $best = $cand;
            continue;
        }
        $cmp = strcmp($cand['at'], $best['at']);
        $better = $cmp > 0 || ($cmp === 0 && ($rank[$cand['source']] ?? 0) > ($rank[$best['source']] ?? 0));
        if ($better) {
            $best = $cand;
        }
    }

    return $best;
}

/**
 * @param array<string, mixed> $live
 * @param array<string, mixed> $state
 * @return array<string, array{from:string,to:string}>
 */
function jeab_ordrar_restore_state_diff(array $live, array $state): array
{
    $diff = [];
    foreach (['company', 'kind', 'step', 'status', 'booking_id', 'install_date', 'fortnox_order', 'pris', 'kundpris'] as $key) {
        if (!array_key_exists($key, $state)) {
            continue;
        }
        $from = array_key_exists($key, $live) ? trim((string) $live[$key]) : '';
        $to = trim((string) $state[$key]);
        if ($from !== $to) {
            $diff[$key] = ['from' => $from, 'to' => $to];
        }
    }
    $fromBooked = '';
    if (is_array($live['steps'] ?? null)) {
        $fromBooked = jeab_ordrar_restore_state_date($live['steps']['bokad_tid'] ?? '');
    }
    $toBooked = is_array($state['steps'] ?? null) ? jeab_ordrar_restore_state_date($state['steps']['bokad_tid'] ?? '') : '';
    if ($toBooked !== '' && $fromBooked !== $toBooked) {
        $diff['bokad_tid'] = ['from' => $fromBooked, 'to' => $toBooked];
    }
    foreach (['inkop_lines' => 'inkop', 'fortnox_order_rows' => 'fortnox_rows', 'kalkyl' => 'kalkyl'] as $key => $label) {
        if (!is_array($state[$key] ?? null)) {
            continue;
        }
        $from = is_array($live[$key] ?? null) ? count($live[$key]) : 0;
        $to = count($state[$key]);
        if ($from !== $to) {
            $diff[$label] = ['from' => (string) $from, 'to' => (string) $to];
        }
    }

    return $diff;
}

/** @return array<int, true> */
function jeab_ordrar_restore_state_logged(PDO $pdo): array
{
    if (!function_exists('jeab_ordrar_restore_ensure_log')) {
        return [];
    }
    try {
        jeab_ordrar_restore_ensure_log($pdo);
        $rows = $pdo->query("SELECT reminder_id, entity FROM order_restore_log WHERE undone = 0")->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entity = trim((string) ($row['entity'] ?? 'order'));
        if ($entity !== '' && $entity !== 'order') {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id > 0) {
            $out[$id] = true;
        }
    }

    return $out;
}

function jeab_ordrar_restore_state_ensure_log(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_state_restore_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(64) NOT NULL,
                reminder_id INT NOT NULL,
                before_json LONGTEXT NOT NULL,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY order_state_restore_undo (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_state_restore_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            undo_id TEXT NOT NULL,
            reminder_id INTEGER NOT NULL,
            before_json TEXT NOT NULL,
            undone INTEGER NOT NULL DEFAULT 0
        )'
    );
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_state_public(array $body): array
{
    $body['sent'] = 0;
    $body['mail'] = 0;
    $body['sms'] = 0;
    $body['fortnox'] = 0;
    $body['calendar'] = 0;
    if (function_exists('jeab_ordrar_restore_public')) {
        $clean = jeab_ordrar_restore_public($body);

        return is_array($clean) ? $clean : $body;
    }

    return $body;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_state(JeabStore $store, array $input): array
{
    $dry = function_exists('jeab_ordrar_restore_dry') ? jeab_ordrar_restore_dry($input) : true;
    $parsed = function_exists('jeab_ordrar_restore_parse_ids')
        ? jeab_ordrar_restore_parse_ids($input['ids'] ?? null)
        : ['error' => 'ids ska vara en lista.'];
    if (isset($parsed['error'])) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => (string) $parsed['error'],
            'status' => 422,
            'dry' => $dry ? 1 : 0,
            'action' => 'ordrar.restore_state_from_snapshot',
        ]);
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_purchase_get') || !function_exists('jeab_sql_purchases_patch')) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => 'SQL saknas.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'action' => 'ordrar.restore_state_from_snapshot',
        ]);
    }
    $logged = jeab_ordrar_restore_state_logged($pdo);
    $orders = [];
    $plans = [];
    foreach ($parsed['ids'] ?? [] as $id) {
        $id = (int) $id;
        $item = [
            'id' => $id,
            'source' => '',
            'source_at' => '',
            'source_file' => '',
            'dropped' => [],
            'diff' => [],
        ];
        if (!isset($logged[$id])) {
            $item['reason'] = 'not_restored';
            $orders[] = $item;
            continue;
        }
        $live = jeab_purchase_get($id);
        if (!is_array($live)) {
            $item['reason'] = 'missing';
            $orders[] = $item;
            continue;
        }
        $pick = jeab_ordrar_restore_state_pick(jeab_ordrar_restore_state_candidates($pdo, $id));
        if ($pick === null) {
            $item['reason'] = 'no_state';
            $orders[] = $item;
            continue;
        }
        $state = jeab_ordrar_restore_state_extract($pick['row']);
        $item['source'] = $pick['source'];
        $item['source_at'] = $pick['at'];
        $item['source_file'] = $pick['file'];
        $item['dropped'] = jeab_ordrar_restore_state_dropped($pick['row']);
        $item['diff'] = jeab_ordrar_restore_state_diff($live, $state);
        if ($item['diff'] === []) {
            $item['reason'] = 'same';
            $orders[] = $item;
            continue;
        }
        $orders[] = $item;
        $plans[] = ['id' => $id, 'state' => $state, 'live' => $live];
    }
    $base = [
        'ok' => true,
        'action' => 'ordrar.restore_state_from_snapshot',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'orders' => $orders,
        'undo_id' => null,
        'status' => 200,
    ];
    if ($dry || $plans === []) {
        return jeab_ordrar_restore_state_public($base);
    }
    if ((string) ($input['confirm'] ?? '') !== 'restore') {
        $base['ok'] = false;
        $base['read_only'] = true;
        $base['error'] = 'confirm:restore krävs.';
        $base['status'] = 400;

        return jeab_ordrar_restore_state_public($base);
    }
    if (!function_exists('jeab_pdo_transaction') || !function_exists('jeab_restore_seal_read')) {
        $base['ok'] = false;
        $base['error'] = 'Läget gick inte att spara.';
        $base['status'] = 500;

        return jeab_ordrar_restore_state_public($base);
    }
    try {
        jeab_ordrar_restore_state_ensure_log($pdo);
    } catch (Throwable) {
        $base['ok'] = false;
        $base['error'] = 'Läget gick inte att spara.';
        $base['status'] = 500;

        return jeab_ordrar_restore_state_public($base);
    }
    $undoId = bin2hex(random_bytes(16));
    try {
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($plans, $undoId): void {
            $seal = jeab_restore_seal_read($pdo);
            $insert = $pdo->prepare('INSERT INTO order_state_restore_log (undo_id, reminder_id, before_json, undone) VALUES (?, ?, ?, 0)');
            foreach ($plans as $plan) {
                $id = (int) $plan['id'];
                $state = $plan['state'];
                $saved = jeab_sql_purchases_patch($pdo, $id, static function (array $current) use ($state): array {
                    $next = $current;
                    foreach ($state as $key => $value) {
                        $next[$key] = $value;
                    }
                    $next['updated_at'] = (string) ($current['updated_at'] ?? '');

                    return $next;
                });
                if (!is_array($saved)) {
                    throw new RuntimeException('missing');
                }
                $before = [];
                foreach (array_keys($state) as $key) {
                    $before[$key] = array_key_exists($key, $plan['live']) ? $plan['live'][$key] : null;
                }
                $json = json_encode($before, JSON_UNESCAPED_UNICODE);
                if (!is_string($json)) {
                    throw new RuntimeException('json');
                }
                $insert->execute([$undoId, $id, $json]);
            }
            if (jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        $base['ok'] = false;
        $base['error'] = 'Läget gick inte att spara.';
        $base['status'] = 500;

        return jeab_ordrar_restore_state_public($base);
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['undo_id'] = $undoId;

    return jeab_ordrar_restore_state_public($base);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_state_undo(JeabStore $store, array $input): array
{
    $dry = function_exists('jeab_ordrar_restore_dry') ? jeab_ordrar_restore_dry($input) : true;
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $base = [
        'action' => 'ordrar.restore_state_undo',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'undo_id' => $undoId,
        'would_restore' => [],
        'restored' => [],
        'status' => 200,
    ];
    if (preg_match('/\A[a-f0-9]{16,64}\z/', $undoId) !== 1) {
        return jeab_ordrar_restore_state_public($base + ['ok' => false, 'error' => 'undo_id krävs.', 'status' => 422]);
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_sql_purchases_patch')) {
        return jeab_ordrar_restore_state_public($base + ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400]);
    }
    try {
        jeab_ordrar_restore_state_ensure_log($pdo);
        $stmt = $pdo->prepare('SELECT reminder_id, before_json, undone FROM order_state_restore_log WHERE undo_id = ? ORDER BY id');
        $stmt->execute([$undoId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        return jeab_ordrar_restore_state_public($base + ['ok' => false, 'error' => 'Ångern gick inte att spara.', 'status' => 500]);
    }
    $plans = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row) || (int) ($row['undone'] ?? 0) === 1) {
            continue;
        }
        $before = json_decode((string) ($row['before_json'] ?? ''), true);
        if (!is_array($before)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id > 0) {
            $plans[] = ['id' => $id, 'before' => $before];
            $base['would_restore'][] = $id;
        }
    }
    if ($dry || $plans === []) {
        $base['ok'] = true;

        return jeab_ordrar_restore_state_public($base);
    }
    if ((string) ($input['confirm'] ?? '') !== 'undo') {
        $base['read_only'] = true;

        return jeab_ordrar_restore_state_public($base + ['ok' => false, 'error' => 'confirm:undo krävs.', 'status' => 400]);
    }
    if (!function_exists('jeab_pdo_transaction') || !function_exists('jeab_restore_seal_read')) {
        return jeab_ordrar_restore_state_public($base + ['ok' => false, 'error' => 'Ångern gick inte att spara.', 'status' => 500]);
    }
    try {
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($plans, $undoId): void {
            $seal = jeab_restore_seal_read($pdo);
            foreach ($plans as $plan) {
                $before = $plan['before'];
                $saved = jeab_sql_purchases_patch($pdo, (int) $plan['id'], static function (array $current) use ($before): array {
                    $next = $current;
                    foreach ($before as $key => $value) {
                        if ($value === null) {
                            unset($next[$key]);
                            continue;
                        }
                        $next[$key] = $value;
                    }
                    $next['updated_at'] = (string) ($current['updated_at'] ?? '');

                    return $next;
                });
                if (!is_array($saved)) {
                    throw new RuntimeException('missing');
                }
            }
            $pdo->prepare('UPDATE order_state_restore_log SET undone = 1 WHERE undo_id = ?')->execute([$undoId]);
            if (jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        return jeab_ordrar_restore_state_public($base + ['ok' => false, 'error' => 'Ångern gick inte att spara.', 'status' => 500]);
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $base['ok'] = true;
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['restored'] = $base['would_restore'];

    return jeab_ordrar_restore_state_public($base);
}
