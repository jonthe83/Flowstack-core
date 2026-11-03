<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * ordrar.restore_from_snapshot, ordrar.restore_undo, ordrar.missing_report, ordrar.delete_audit.
 * Återställer en saknad orderrad från contacts_sole-ögonblicksbilden.
 * Skriver inte över en rad som finns och rör inte ordrar_deleted.
 * Skickar inget: inget mail, inget sms, ingen fortnox och ingen kalenderinbjudan.
 */

function jeab_ordrar_restore_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/**
 * @return array{ids?:list<int>,error?:string}
 */
function jeab_ordrar_restore_parse_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $split = preg_split('/\s*,\s*/', trim($raw));
        $raw = is_array($split) ? $split : [];
    }
    if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
        return ['error' => 'ids ska vara en lista.'];
    }
    if (count($raw) > 200) {
        return ['error' => 'Högst 200 id.'];
    }
    $ids = [];
    foreach ($raw as $value) {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9]\d*\z/', $value) === 1) {
            $id = (int) $value;
        } else {
            return ['error' => 'Ogiltigt id.'];
        }
        if ($id <= 0 || isset($ids[$id])) {
            return ['error' => 'Ogiltigt id.'];
        }
        $ids[$id] = $id;
    }

    return ['ids' => array_values($ids)];
}

/** @return list<array{id:int,at:string,data:array<string,mixed>}> */
function jeab_ordrar_restore_snapshots(): array
{
    if (!function_exists('jeab_contacts_sole_undo_all')) {
        return [];
    }
    $out = [];
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if (!is_array($row) || (string) ($row['kind'] ?? '') !== 'snapshot' || (int) ($row['undone'] ?? 0) === 1) {
            continue;
        }
        $data = json_decode((string) ($row['before_json'] ?? ''), true);
        if (!is_array($data)) {
            continue;
        }
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'at' => trim((string) ($row['created_at'] ?? '')),
            'data' => $data,
        ];
    }

    return $out;
}

/** @param array<string, mixed> $data */
function jeab_ordrar_restore_snapshot_has(array $data, int $id): bool
{
    foreach (is_array($data['ids']['orders'] ?? null) ? $data['ids']['orders'] : [] as $raw) {
        if ((int) $raw === $id) {
            return true;
        }
    }
    $links = is_array($data['links']['orders'] ?? null) ? $data['links']['orders'] : [];
    if (isset($links[(string) $id]) || isset($links[$id])) {
        return true;
    }
    foreach (['orders_rows', 'rows'] as $key) {
        $bag = $data[$key] ?? null;
        if (is_array($bag) && (isset($bag[(string) $id]) || isset($bag[$id]))) {
            return true;
        }
    }

    return false;
}

/**
 * Senaste ögonblicksbilden som fortfarande innehåller id:t.
 *
 * @param list<array{id:int,at:string,data:array<string,mixed>}> $snaps
 * @return array{id:int,at:string,data:array<string,mixed>}|null
 */
function jeab_ordrar_restore_snapshot_for(array $snaps, int $id): ?array
{
    $best = null;
    foreach ($snaps as $snap) {
        if (!jeab_ordrar_restore_snapshot_has($snap['data'], $id)) {
            continue;
        }
        if ($best === null || $snap['id'] >= $best['id']) {
            $best = $snap;
        }
    }

    return $best;
}

/** @param array<string, mixed> $data @return array<string, mixed>|null */
function jeab_ordrar_restore_detail(array $data, int $id): ?array
{
    foreach (['orders_rows', 'rows'] as $key) {
        $bag = $data[$key] ?? null;
        if (!is_array($bag)) {
            continue;
        }
        $row = $bag[(string) $id] ?? $bag[$id] ?? null;
        if (is_array($row)) {
            return $row;
        }
    }

    return null;
}

function jeab_ordrar_restore_date(string $value): string
{
    $value = trim($value);
    if (preg_match('/\A(\d{4}-\d{2}-\d{2})/', $value, $m) === 1) {
        return $m[1];
    }

    return '';
}

/**
 * @param array<string, mixed> $snap
 * @param array<string, mixed>|null $reminder
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_build(int $id, array $snap, ?array $reminder): array
{
    $detail = jeab_ordrar_restore_detail($snap, $id);
    $links = is_array($snap['links']['orders'] ?? null) ? $snap['links']['orders'] : [];
    $contact = is_array($detail) ? trim((string) ($detail['contact_id'] ?? '')) : '';
    if ($contact === '') {
        $contact = trim((string) ($links[(string) $id] ?? $links[$id] ?? ''));
    }
    $kind = is_array($detail) ? trim((string) ($detail['product'] ?? $detail['kind'] ?? '')) : '';
    if ($kind === '' && is_array($reminder) && function_exists('jeab_ordrar_vunnen_products')) {
        $kind = (string) (jeab_ordrar_vunnen_products($reminder)[0] ?? '');
    }
    if ($kind === '' && is_array($reminder)) {
        $kind = trim((string) ($reminder['job_type'] ?? $reminder['kind'] ?? ''));
    }
    if (function_exists('jeab_purchase_normalize_kind')) {
        $kind = jeab_purchase_normalize_kind($kind) ?? $kind;
    }
    $created = is_array($detail) ? trim((string) ($detail['created_at'] ?? '')) : '';
    if ($created === '' && is_array($reminder)) {
        $created = trim((string) ($reminder['created_at'] ?? ''));
    }
    $updated = is_array($detail) ? trim((string) ($detail['updated_at'] ?? '')) : '';
    if ($updated === '' && is_array($reminder)) {
        $updated = trim((string) ($reminder['updated_at'] ?? $reminder['completed_at'] ?? ''));
    }
    if ($updated === '') {
        $updated = $created;
    }
    $step = 1;
    if (is_array($detail) && isset($detail['step'])) {
        $step = (int) $detail['step'] >= 2 ? 2 : 1;
    }
    $status = is_array($detail) ? trim((string) ($detail['status'] ?? '')) : '';
    if ($status === '' && is_array($reminder)) {
        $status = trim((string) ($reminder['completed_at'] ?? '')) !== '' ? 'done' : 'open';
    }
    $company = '';
    if (is_array($detail)) {
        $company = trim((string) ($detail['company'] ?? ''));
    }
    if ($company === '' && is_array($reminder)) {
        $company = trim((string) ($reminder['company'] ?? ''));
    }
    if (function_exists('jeab_normalize_company')) {
        $company = jeab_normalize_company($company) ?? $company;
    }
    $address = '';
    if (is_array($reminder)) {
        $address = trim((string) ($reminder['address'] ?? ''));
    }
    if ($address === '' && is_array($detail)) {
        $address = trim((string) ($detail['address'] ?? ''));
    }
    if (function_exists('jeab_contacts_sole_snapshot_address_text')) {
        $address = jeab_contacts_sole_snapshot_address_text($address);
    }

    return [
        'reminder_id' => $id,
        'contact_id' => $contact,
        'kind' => $kind,
        'company' => $company,
        'address' => $address,
        'step' => $step,
        'status' => $status,
        'done' => $status === 'done',
        'created_at' => $created,
        'updated_at' => $updated,
        'reminder_created_at' => $created,
    ];
}

/** @return array{result:string,booking_ids:list<int>} */
function jeab_ordrar_restore_booking_match(PDO $pdo, array $row): array
{
    $contact = trim((string) ($row['contact_id'] ?? ''));
    $date = jeab_ordrar_restore_date((string) ($row['created_at'] ?? ''));
    if ($contact === '' || $date === '') {
        return ['result' => 'none', 'booking_ids' => []];
    }
    $hasOrderColumn = false;
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'order_id'");
            $hasOrderColumn = $col instanceof PDOStatement && is_array($col->fetch());
        } else {
            foreach ($pdo->query('PRAGMA table_info(bookings)') ?: [] as $info) {
                if (is_array($info) && (string) ($info['name'] ?? '') === 'order_id') {
                    $hasOrderColumn = true;
                }
            }
        }
    } catch (Throwable) {
        $hasOrderColumn = false;
    }
    $sql = $hasOrderColumn
        ? 'SELECT id, starts_at, order_id FROM bookings WHERE contact_id = ?'
        : 'SELECT id, starts_at FROM bookings WHERE contact_id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$contact]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        return ['result' => 'none', 'booking_ids' => []];
    }
    $taken = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $order) {
            if (!is_array($order)) {
                continue;
            }
            $bookingId = (int) ($order['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $taken[$bookingId] = true;
            }
        }
    }
    $hits = [];
    foreach (is_array($rows) ? $rows : [] as $booking) {
        if (!is_array($booking)) {
            continue;
        }
        $bookingId = (int) ($booking['id'] ?? 0);
        if ($bookingId <= 0 || isset($taken[$bookingId])) {
            continue;
        }
        if ($hasOrderColumn && (int) ($booking['order_id'] ?? 0) > 0) {
            continue;
        }
        if (jeab_ordrar_restore_date((string) ($booking['starts_at'] ?? '')) !== $date) {
            continue;
        }
        $hits[$bookingId] = $bookingId;
    }
    $ids = array_values($hits);
    sort($ids);
    if ($ids === []) {
        return ['result' => 'none', 'booking_ids' => []];
    }
    if (count($ids) > 1) {
        return ['result' => 'ambiguous', 'booking_ids' => $ids];
    }

    return ['result' => 'linked', 'booking_ids' => $ids];
}

function jeab_ordrar_restore_ensure_log(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_restore_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(64) NOT NULL,
                reminder_id INT NOT NULL,
                contact_id VARCHAR(64) NOT NULL DEFAULT \'\',
                kind VARCHAR(64) NOT NULL DEFAULT \'\',
                order_created_at VARCHAR(32) NOT NULL DEFAULT \'\',
                step INT NOT NULL DEFAULT 1,
                status VARCHAR(16) NOT NULL DEFAULT \'\',
                booking_id INT NOT NULL DEFAULT 0,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                entity VARCHAR(16) NOT NULL DEFAULT \'order\',
                entity_key VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                KEY order_restore_undo (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_restore_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            undo_id TEXT NOT NULL,
            reminder_id INTEGER NOT NULL,
            contact_id TEXT NOT NULL DEFAULT \'\',
            kind TEXT NOT NULL DEFAULT \'\',
            order_created_at TEXT NOT NULL DEFAULT \'\',
            step INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT \'\',
            booking_id INTEGER NOT NULL DEFAULT 0,
            undone INTEGER NOT NULL DEFAULT 0,
            entity TEXT NOT NULL DEFAULT \'order\',
            entity_key TEXT NOT NULL DEFAULT \'\'
        )'
    );
    }
    jeab_restore_log_entity_columns($pdo);
}

function jeab_restore_log_entity_columns(PDO $pdo): void
{
    try {
        $pdo->query('SELECT entity, entity_key FROM order_restore_log LIMIT 0');
    } catch (Throwable) {
        try {
            $pdo->exec("ALTER TABLE order_restore_log ADD COLUMN entity TEXT NOT NULL DEFAULT 'order'");
        } catch (Throwable) {
        }
        try {
            $pdo->exec("ALTER TABLE order_restore_log ADD COLUMN entity_key TEXT NOT NULL DEFAULT ''");
        } catch (Throwable) {
        }
    }
}

/** @param array<string, mixed> $row */
function jeab_ordrar_restore_same(array $live, array $row): bool
{
    return (int) ($live['reminder_id'] ?? 0) === (int) ($row['reminder_id'] ?? 0)
        && trim((string) ($live['contact_id'] ?? '')) === trim((string) ($row['contact_id'] ?? ''))
        && trim((string) ($live['kind'] ?? '')) === trim((string) ($row['kind'] ?? ''))
        && trim((string) ($live['created_at'] ?? '')) === trim((string) ($row['created_at'] ?? ''))
        && (int) ($live['step'] ?? 0) === (int) ($row['step'] ?? 0)
        && trim((string) ($live['status'] ?? '')) === trim((string) ($row['status'] ?? ''));
}

function jeab_ordrar_restore_link_booking(PDO $pdo, int $bookingId, int $orderId): void
{
    if ($bookingId <= 0 || $orderId <= 0) {
        return;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $has = false;
    if ($driver === 'mysql') {
        $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'order_id'");
        $has = $col instanceof PDOStatement && is_array($col->fetch());
    } else {
        foreach ($pdo->query('PRAGMA table_info(bookings)') ?: [] as $info) {
            if (is_array($info) && (string) ($info['name'] ?? '') === 'order_id') {
                $has = true;
            }
        }
    }
    if (!$has) {
        return;
    }
    $pdo->prepare('UPDATE bookings SET order_id = ? WHERE id = ? AND (order_id IS NULL OR order_id = \'\' OR order_id = 0)')
        ->execute([$orderId, $bookingId]);
}

/**
 * @param array<string, mixed> $value
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_public(array $value): array
{
    $drop = [
        'name', 'email', 'phone', 'personnummer', 'pnr', 'customer_name', 'customer',
        'note', 'notes', 'body', 'message', 'seller', 'huvudsokande',
    ];
    $walk = static function (mixed $item) use (&$walk, $drop): mixed {
        if (!is_array($item)) {
            return $item;
        }
        $out = [];
        foreach ($item as $key => $child) {
            $name = (string) $key;
            if (in_array($name, $drop, true)) {
                continue;
            }
            if ($name === 'actor' && is_string($child) && str_contains($child, '@')) {
                continue;
            }
            $out[$key] = $walk($child);
        }

        return $out;
    };
    $clean = $walk($value);

    return is_array($clean) ? $clean : [];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_from_snapshot(JeabStore $store, array $input = []): array
{
    $kind = jeab_restore_kind($input);
    if ($kind === 'reminder' || $kind === 'thread') {
        return jeab_restore_entity($store, $input, $kind);
    }
    $dry = jeab_ordrar_restore_dry($input);
    $parsed = jeab_ordrar_restore_parse_ids($input['ids'] ?? null);
    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => $parsed['error'], 'status' => 422, 'sent' => 0, 'dry' => $dry ? 1 : 0];
    }
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'restore') {
        return ['ok' => false, 'error' => 'confirm:restore krävs.', 'status' => 400, 'sent' => 0, 'dry' => 0];
    }
    $company = '';
    if (array_key_exists('co', $input) && function_exists('jeab_normalize_company')) {
        $company = jeab_normalize_company($input['co']) ?? '';
        if (trim((string) $input['co']) !== '' && $company === '') {
            return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422, 'sent' => 0, 'dry' => $dry ? 1 : 0];
        }
    }
    $snaps = jeab_ordrar_restore_snapshots();
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    $ordersPdo = function_exists('jeab_purchase_sql_live') ? jeab_purchase_sql_live() : null;
    if (!$ordersPdo instanceof PDO) {
        $ordersPdo = $pdo;
    }
    $would = [];
    $skipped = [];
    $bookings = [];
    $plans = [];
    foreach ($parsed['ids'] ?? [] as $id) {
        $id = (int) $id;
        $snap = jeab_ordrar_restore_snapshot_for($snaps, $id);
        if ($snap === null) {
            $skipped[] = ['id' => $id, 'reason' => 'not_in_snapshot'];
            continue;
        }
        if (function_exists('jeab_purchase_get') && is_array(jeab_purchase_get($id))) {
            $skipped[] = ['id' => $id, 'reason' => 'exists'];
            continue;
        }
        if (function_exists('jeab_ordrar_deleted_blocks') && jeab_ordrar_deleted_blocks($id)) {
            $skipped[] = ['id' => $id, 'reason' => 'deleted'];
            continue;
        }
        $reminder = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : null;
        $row = jeab_ordrar_restore_build($id, $snap['data'], is_array($reminder) ? $reminder : null);
        if ($company !== '' && (string) ($row['company'] ?? '') !== $company) {
            $skipped[] = ['id' => $id, 'reason' => 'company'];
            continue;
        }
        $match = $ordersPdo instanceof PDO
            ? jeab_ordrar_restore_booking_match($ordersPdo, $row)
            : ['result' => 'none', 'booking_ids' => []];
        if ($match['result'] === 'linked') {
            $row['booking_id'] = (int) $match['booking_ids'][0];
        }
        $plans[] = ['row' => $row, 'match' => $match];
        $would[] = [
            'id' => $id,
            'address' => (string) ($row['address'] ?? ''),
            'product' => (string) ($row['kind'] ?? ''),
            'step' => (int) ($row['step'] ?? 1),
            'status' => (string) ($row['status'] ?? ''),
            'dates' => [
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ],
        ];
        $bookings[] = [
            'id' => $id,
            'result' => (string) $match['result'],
            'booking_ids' => $match['booking_ids'],
        ];
    }
    $base = [
        'ok' => true,
        'action' => 'ordrar.restore_from_snapshot',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'sent' => 0,
        'mail' => 0,
        'sms' => 0,
        'fortnox' => 0,
        'calendar' => 0,
        'would_restore' => $would,
        'skipped' => $skipped,
        'booking' => $bookings,
        'undo_id' => null,
        'status' => 200,
    ];
    if ($dry || $plans === []) {
        return jeab_ordrar_restore_public($base);
    }
    if (!$ordersPdo instanceof PDO || !function_exists('jeab_sql_purchases_put') || !function_exists('jeab_pdo_transaction')) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400, 'sent' => 0, 'dry' => 0];
    }
    $undoId = bin2hex(random_bytes(16));
    try {
        jeab_ordrar_restore_ensure_log($ordersPdo);
        jeab_pdo_transaction($ordersPdo, static function (PDO $pdo) use ($plans, $undoId): void {
            $seal = jeab_restore_seal_read($pdo);
            jeab_ordrar_restore_ensure_log($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO order_restore_log (undo_id, reminder_id, contact_id, kind, order_created_at, step, status, booking_id, undone, entity, entity_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
            );
            foreach ($plans as $plan) {
                $row = $plan['row'];
                $id = (int) ($row['reminder_id'] ?? 0);
                if (function_exists('jeab_purchase_get') && is_array(jeab_purchase_get($id))) {
                    throw new RuntimeException('exists');
                }
                if (function_exists('jeab_ordrar_deleted_blocks') && jeab_ordrar_deleted_blocks($id)) {
                    throw new RuntimeException('deleted');
                }
                jeab_sql_purchases_put($pdo, $row);
                $bookingId = (int) ($row['booking_id'] ?? 0);
                if ($bookingId > 0) {
                    jeab_ordrar_restore_link_booking($pdo, $bookingId, $id);
                }
                $insert->execute([
                    $undoId,
                    $id,
                    mb_substr((string) ($row['contact_id'] ?? ''), 0, 64),
                    mb_substr((string) ($row['kind'] ?? ''), 0, 64),
                    (string) ($row['created_at'] ?? ''),
                    (int) ($row['step'] ?? 1),
                    mb_substr((string) ($row['status'] ?? ''), 0, 16),
                    $bookingId,
                    'order',
                    '',
                ]);
            }
            if (jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Återställningen gick inte att spara.', 'status' => 500, 'sent' => 0, 'dry' => 0];
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['undo_id'] = $undoId;

    return jeab_ordrar_restore_public($base);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_from_snapshot_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
    }
    $result = jeab_ordrar_restore_from_snapshot($store, $input);
    $result['status'] = (int) ($result['status'] ?? 200);

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_undo(JeabStore $store, array $input = []): array
{
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    if (preg_match('/\A[a-f0-9]{32}\z/', $undoId) !== 1) {
        return ['ok' => false, 'error' => 'undo_id krävs.', 'status' => 422, 'sent' => 0];
    }
    $dry = jeab_ordrar_restore_dry($input);
    $pdo = function_exists('jeab_purchase_sql_live') ? jeab_purchase_sql_live() : null;
    if (!$pdo instanceof PDO && $store instanceof JeabPdoStore) {
        $pdo = $store->pdo();
    }
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400, 'sent' => 0];
    }
    jeab_ordrar_restore_ensure_log($pdo);
    $stmt = $pdo->prepare('SELECT reminder_id, contact_id, kind, order_created_at, step, status, booking_id, entity, entity_key FROM order_restore_log WHERE undo_id = ? AND undone = 0 ORDER BY id');
    $stmt->execute([$undoId]);
    $logs = [];
    foreach ($stmt->fetchAll() as $row) {
        if (is_array($row)) {
            $logs[] = $row;
        }
    }
    $would = [];
    $wouldExtra = [];
    $kept = [];
    $gone = [];
    foreach ($logs as $log) {
        $entity = (string) ($log['entity'] ?? 'order');
        if ($entity === 'reminder' || $entity === 'thread') {
            [$bucket, $token] = jeab_restore_undo_classify($store, $log);
            if ($bucket === 'would') {
                $wouldExtra[] = $token;
            } elseif ($bucket === 'kept') {
                $kept[] = $token;
            } else {
                $gone[] = $token;
            }
            continue;
        }
        $id = (int) ($log['reminder_id'] ?? 0);
        $live = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
        $expect = [
            'reminder_id' => $id,
            'contact_id' => (string) ($log['contact_id'] ?? ''),
            'kind' => (string) ($log['kind'] ?? ''),
            'created_at' => (string) ($log['order_created_at'] ?? ''),
            'step' => (int) ($log['step'] ?? 1),
            'status' => (string) ($log['status'] ?? ''),
        ];
        if (!is_array($live)) {
            $gone[] = $id;
            continue;
        }
        if (!jeab_ordrar_restore_same($live, $expect)) {
            $kept[] = $id;
            continue;
        }
        $would[] = $id;
    }
    $deleted = [];
    if (!$dry && ($would !== [] || $wouldExtra !== [])) {
        try {
            jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($would, $wouldExtra, $undoId, $store, &$deleted): void {
                $seal = jeab_restore_seal_read($pdo);
                foreach ($wouldExtra as $token) {
                    if (jeab_restore_undo_apply($store, $pdo, $undoId, (string) $token)) {
                        $deleted[] = $token;
                    }
                }
                foreach ($would as $id) {
                    $live = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
                    $fresh = $pdo->prepare('SELECT contact_id, kind, order_created_at, step, status, booking_id FROM order_restore_log WHERE undo_id = ? AND reminder_id = ? AND undone = 0');
                    $fresh->execute([$undoId, $id]);
                    $log = $fresh->fetch();
                    if (!is_array($live) || !is_array($log)) {
                        continue;
                    }
                    $expect = [
                        'reminder_id' => $id,
                        'contact_id' => (string) ($log['contact_id'] ?? ''),
                        'kind' => (string) ($log['kind'] ?? ''),
                        'created_at' => (string) ($log['order_created_at'] ?? ''),
                        'step' => (int) ($log['step'] ?? 1),
                        'status' => (string) ($log['status'] ?? ''),
                    ];
                    if (!jeab_ordrar_restore_same($live, $expect)) {
                        continue;
                    }
                    $GLOBALS['_JEAB_ORDRAR_DELETE_LOCK'] = 1;
                    try {
                        if (function_exists('jeab_ordrar_delete_audit')) {
                            jeab_ordrar_delete_audit($id);
                        }
                        jeab_purchase_delete($id);
                    } finally {
                        unset($GLOBALS['_JEAB_ORDRAR_DELETE_LOCK']);
                    }
                    $bookingId = (int) ($log['booking_id'] ?? 0);
                    if ($bookingId > 0) {
                        try {
                            $pdo->prepare('UPDATE bookings SET order_id = NULL WHERE id = ? AND order_id = ?')->execute([$bookingId, $id]);
                        } catch (Throwable) {
                        }
                    }
                    $pdo->prepare('UPDATE order_restore_log SET undone = 1 WHERE undo_id = ? AND reminder_id = ?')->execute([$undoId, $id]);
                    $deleted[] = $id;
                }
                if (jeab_restore_seal_read($pdo) !== $seal) {
                    throw new RuntimeException('frozen');
                }
            });
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Ångringen gick inte att spara.', 'status' => 500, 'sent' => 0];
        }
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
    }

    return jeab_ordrar_restore_public([
        'ok' => true,
        'action' => 'ordrar.restore_undo',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'sent' => 0,
        'mail' => 0,
        'sms' => 0,
        'fortnox' => 0,
        'calendar' => 0,
        'undo_id' => $undoId,
        'would_delete' => array_merge($would, $wouldExtra),
        'deleted' => $deleted,
        'kept' => $kept,
        'gone' => $gone,
        'status' => 200,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_restore_undo_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
    }
    $result = jeab_ordrar_restore_undo($store, $input);
    $result['status'] = (int) ($result['status'] ?? 200);

    return $result;
}

/** @return array<int, true> */
function jeab_ordrar_restore_backup_ids(string $since): array
{
    $cut = $since !== '' ? strtotime($since) : false;
    $dirs = function_exists('jeab_reminders_backup_dirs') ? jeab_reminders_backup_dirs() : [];
    $ids = [];
    foreach ($dirs as $dir) {
        foreach (['purchases.json', 'purchases.bak-*', 'purchases-*.json', 'purchases*.json'] as $pattern) {
            foreach (glob(rtrim($dir, '/') . '/' . $pattern) ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if ($cut !== false && (int) filemtime($file) < $cut) {
                    continue;
                }
                $decoded = json_decode((string) file_get_contents($file), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $list = $decoded['purchases'] ?? $decoded['orders'] ?? $decoded;
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $key => $row) {
                    $id = is_array($row) ? (int) ($row['reminder_id'] ?? $row['id'] ?? 0) : 0;
                    if ($id <= 0) {
                        $id = (int) $key;
                    }
                    if ($id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
        }
    }

    return $ids;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_missing_report(JeabStore $store, array $input = []): array
{
    $since = trim((string) ($input['since'] ?? ''));
    $coFilter = jeab_restore_company($input['co'] ?? '');
    $live = function_exists('jeab_purchase_live_ids') ? jeab_purchase_live_ids() : [];
    $meta = [];
    $noteOrder = static function (int $id, string $reason, string $company = '') use (&$meta): void {
        if ($id <= 0 || $reason === '') {
            return;
        }
        if (!isset($meta[$id])) {
            $meta[$id] = ['reasons' => [], 'company' => ''];
        }
        $meta[$id]['reasons'][$reason] = true;
        if ($meta[$id]['company'] === '' && $company !== '') {
            $meta[$id]['company'] = $company;
        }
    };
    foreach (jeab_ordrar_restore_snapshots() as $snap) {
        if ($since !== '' && $snap['at'] !== '' && $snap['at'] < $since) {
            continue;
        }
        $data = $snap['data'];
        foreach (is_array($data['ids']['orders'] ?? null) ? $data['ids']['orders'] : [] as $raw) {
            $noteOrder((int) $raw, 'snapshot');
        }
        foreach (['orders_rows', 'rows'] as $key) {
            $bag = $data[$key] ?? null;
            if (!is_array($bag)) {
                continue;
            }
            foreach ($bag as $rawKey => $row) {
                $company = is_array($row) ? jeab_restore_company($row['company'] ?? '') : '';
                $noteOrder((int) $rawKey, 'snapshot', $company);
            }
        }
    }
    foreach (jeab_ordrar_restore_backup_ids($since) as $id => $_own) {
        $noteOrder((int) $id, 'backup');
    }
    foreach (jeab_restore_fellogg_hits() as $hit) {
        if (($hit['kind'] ?? '') === 'order') {
            $noteOrder((int) ($hit['id'] ?? 0), 'fellogg', (string) ($hit['company'] ?? ''));
        }
    }
    $deleted = function_exists('jeab_ordrar_deleted_doc') ? jeab_ordrar_deleted_doc() : ['ids' => []];
    $tomb = is_array($deleted['ids'] ?? null) ? $deleted['ids'] : [];
    $missing = [];
    foreach ($meta as $id => $hit) {
        $id = (int) $id;
        if ($id <= 0 || isset($live[$id])) {
            continue;
        }
        $company = (string) ($hit['company'] ?? '');
        if ($company === '') {
            $company = jeab_restore_company_from_reminder($store, $id);
        }
        if ($coFilter !== '' && $company !== '' && $company !== $coFilter) {
            continue;
        }
        $entry = $tomb[(string) $id] ?? null;
        $state = null;
        if (is_array($entry)) {
            $state = [
                'at' => trim((string) ($entry['at'] ?? '')),
                'booking_id' => (int) ($entry['booking_id'] ?? 0),
            ];
        }
        $missing[] = [
            'id' => $id,
            'company' => $company,
            'reason' => jeab_restore_reason_text(is_array($hit['reasons'] ?? null) ? $hit['reasons'] : []),
            'ordrar_deleted' => $state,
        ];
    }
    usort($missing, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
    $companies = jeab_restore_company_report($store, $since, $coFilter);

    return jeab_ordrar_restore_public([
        'ok' => true,
        'action' => 'ordrar.missing_report',
        'read_only' => true,
        'dry' => 1,
        'sent' => 0,
        'since' => $since,
        'missing' => $missing,
        'count' => count($missing),
        'companies' => $companies,
        'status' => 200,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_missing_report_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
    }
    $result = jeab_ordrar_missing_report($store, $input);
    $result['status'] = 200;

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_delete_audit_hook(JeabStore $store, array $input = []): array
{
    unset($store);
    $since = trim((string) ($input['since'] ?? ''));
    $rows = [];
    foreach (function_exists('jeab_ordrar_delete_audit_list') ? jeab_ordrar_delete_audit_list() : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $at = trim((string) ($row['created_at'] ?? ''));
        if ($since !== '' && $at !== '' && $at < $since) {
            continue;
        }
        $item = [
            'id' => (int) ($row['reminder_id'] ?? 0),
            'created_at' => $at,
        ];
        $actor = trim((string) ($row['actor'] ?? ''));
        if ($actor !== '' && !str_contains($actor, '@')) {
            $item['actor'] = $actor;
        }
        $rows[] = $item;
    }

    return jeab_ordrar_restore_public([
        'ok' => true,
        'action' => 'ordrar.delete_audit',
        'read_only' => true,
        'dry' => 1,
        'sent' => 0,
        'since' => $since,
        'rows' => $rows,
        'count' => count($rows),
        'status' => 200,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_delete_audit_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
    }

    return jeab_ordrar_delete_audit_hook($store, $input);
}

require_once __DIR__ . '/restore_entities.php';
