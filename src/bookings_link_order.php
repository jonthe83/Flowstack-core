<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * bookings.unlinked_for_contacts läser bokningar utan order_id.
 * bookings.link_order sätter order_id när kontakten är samma.
 * Inget mail, sms eller kalenderinbjudan.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_bookings_link_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'sent' => 0];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'bookings.unlinked_for_contacts') {
        return jeab_bookings_unlinked_for_contacts($store, $input);
    }
    if ($action === 'bookings.link_order_undo') {
        return jeab_bookings_link_order_undo($store, $input);
    }

    return jeab_bookings_link_order($store, $input);
}

function jeab_bookings_link_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_bookings_link_int(mixed $raw): int
{
    if (is_int($raw) && $raw > 0) {
        return $raw;
    }
    if (is_string($raw) && preg_match('/\A[1-9]\d*\z/', $raw) === 1) {
        return (int) $raw;
    }

    return 0;
}

function jeab_bookings_link_pdo(JeabStore $store): ?PDO
{
    if ($store instanceof JeabPdoStore) {
        return $store->pdo();
    }

    return null;
}

function jeab_bookings_link_has_column(PDO $pdo, string $column): bool
{
    if (preg_match('/\A[a-z_]+\z/', $column) !== 1) {
        return false;
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM bookings LIKE ' . $pdo->quote($column));

            return $stmt instanceof PDOStatement && is_array($stmt->fetch());
        }
        foreach ($pdo->query('PRAGMA table_info(bookings)') ?: [] as $info) {
            if (is_array($info) && (string) ($info['name'] ?? '') === $column) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

function jeab_bookings_link_ensure_order(PDO $pdo): void
{
    if (jeab_bookings_link_has_column($pdo, 'order_id')) {
        return;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (!function_exists('jeab_ensure_table_columns')) {
        return;
    }
    jeab_ensure_table_columns($pdo, 'bookings', [
        'order_id' => $driver === 'mysql' ? 'INT NULL DEFAULT NULL' : 'INTEGER',
    ], $driver, true);
}

function jeab_bookings_link_ensure_log(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_order_link_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                undo_id VARCHAR(64) NOT NULL,
                booking_id INT NOT NULL,
                order_id INT NOT NULL,
                undone TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY booking_order_link_undo (undo_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS booking_order_link_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            undo_id TEXT NOT NULL,
            booking_id INTEGER NOT NULL,
            order_id INTEGER NOT NULL,
            undone INTEGER NOT NULL DEFAULT 0
        )'
    );
}

function jeab_bookings_link_order_empty(mixed $value): bool
{
    if ($value === null) {
        return true;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 0;
    }
    $text = trim((string) $value);

    return $text === '' || $text === '0';
}

function jeab_bookings_link_date(mixed $starts): string
{
    $text = trim((string) $starts);
    if (preg_match('/\A(\d{4}-\d{2}-\d{2})/', $text, $match) === 1) {
        return $match[1];
    }

    return '';
}

function jeab_bookings_link_company(mixed $raw): string
{
    if (!function_exists('jeab_normalize_company')) {
        return '';
    }
    $company = jeab_normalize_company($raw);

    return is_string($company) ? $company : '';
}

/**
 * @param array<string, mixed> $input
 * @return array{ids?:list<string>,error?:string}
 */
function jeab_bookings_link_contact_ids(mixed $raw): array
{
    if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
        return ['error' => 'contact_ids ska vara en lista.'];
    }
    if (count($raw) > 50) {
        return ['error' => 'Högst 50 kontakter.'];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = trim((string) $value);
        if ($id === '' || strlen($id) > 64 || str_contains($id, '@') || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $id) !== 1) {
            return ['error' => 'Ogiltigt kontakt-id.'];
        }
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return ['ids' => $ids];
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function jeab_bookings_link_public(array $body): array
{
    $body['sent'] = 0;
    $body['mail'] = 0;
    $body['sms'] = 0;
    $body['calendar'] = 0;
    if (function_exists('jeab_ordrar_restore_public')) {
        $clean = jeab_ordrar_restore_public($body);

        return is_array($clean) ? $clean : $body;
    }

    return $body;
}

/**
 * @return array<string, mixed>|null
 */
function jeab_bookings_link_booking(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $cols = ['id', 'contact_id', 'starts_at'];
    foreach (['company', 'order_id', 'reminder_id'] as $column) {
        if (jeab_bookings_link_has_column($pdo, $column)) {
            $cols[] = $column;
        }
    }
    try {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }

    return is_array($row) ? $row : null;
}

/**
 * @return array{found:bool,contact:string}
 */
function jeab_bookings_link_order_contact(PDO $pdo, int $orderId): array
{
    if ($orderId <= 0 || !function_exists('jeab_purchase_get')) {
        return ['found' => false, 'contact' => ''];
    }
    $row = jeab_purchase_get($orderId);
    if (!is_array($row)) {
        return ['found' => false, 'contact' => ''];
    }
    $contact = trim((string) ($row['contact_id'] ?? ''));
    if ($contact === '') {
        try {
            $stmt = $pdo->prepare('SELECT contact_id FROM purchases WHERE reminder_id = ?');
            $stmt->execute([$orderId]);
            $contact = trim((string) $stmt->fetchColumn());
        } catch (Throwable) {
            $contact = '';
        }
    }

    return ['found' => true, 'contact' => $contact];
}

function jeab_bookings_link_seal(): void
{
    if (!function_exists('jeab_restore_seal_read')) {
        require_once __DIR__ . '/restore_entities.php';
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_bookings_unlinked_for_contacts(JeabStore $store, array $input): array
{
    $parsed = jeab_bookings_link_contact_ids($input['contact_ids'] ?? null);
    if (isset($parsed['error'])) {
        return jeab_bookings_link_public([
            'ok' => false,
            'error' => $parsed['error'],
            'action' => 'bookings.unlinked_for_contacts',
            'status' => 422,
        ]);
    }
    $ids = $parsed['ids'] ?? [];
    $pdo = jeab_bookings_link_pdo($store);
    if (!$pdo instanceof PDO) {
        return jeab_bookings_link_public([
            'ok' => false,
            'error' => 'SQL saknas.',
            'action' => 'bookings.unlinked_for_contacts',
            'status' => 400,
        ]);
    }
    $by = [];
    foreach ($ids as $id) {
        $by[$id] = [];
    }
    $cols = ['id', 'contact_id', 'starts_at'];
    $hasOrder = jeab_bookings_link_has_column($pdo, 'order_id');
    foreach (['company', 'reminder_id'] as $column) {
        if (jeab_bookings_link_has_column($pdo, $column)) {
            $cols[] = $column;
        }
    }
    if ($hasOrder) {
        $cols[] = 'order_id';
    }
    $marks = implode(', ', array_fill(0, count($ids), '?'));
    try {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM bookings WHERE contact_id IN (' . $marks . ')');
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        $rows = [];
    }
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $contact = trim((string) ($row['contact_id'] ?? ''));
        if (!isset($by[$contact])) {
            continue;
        }
        if ($hasOrder && !jeab_bookings_link_order_empty($row['order_id'] ?? null)) {
            continue;
        }
        $by[$contact][] = [
            'id' => (int) ($row['id'] ?? 0),
            'date' => jeab_bookings_link_date($row['starts_at'] ?? ''),
            'company' => jeab_bookings_link_company($row['company'] ?? ''),
            'reminder_id' => (int) ($row['reminder_id'] ?? 0),
        ];
    }
    $contacts = [];
    foreach ($ids as $id) {
        $list = $by[$id];
        usort($list, static function (array $a, array $b): int {
            $date = strcmp((string) $a['date'], (string) $b['date']);
            if ($date !== 0) {
                return $date;
            }

            return ((int) $a['id']) <=> ((int) $b['id']);
        });
        $contacts[] = ['contact_id' => $id, 'bookings' => $list];
    }

    return jeab_bookings_link_public([
        'ok' => true,
        'action' => 'bookings.unlinked_for_contacts',
        'read_only' => true,
        'dry' => 1,
        'contacts' => $contacts,
        'status' => 200,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_bookings_link_order(JeabStore $store, array $input): array
{
    $dry = jeab_bookings_link_dry($input);
    $bookingId = jeab_bookings_link_int($input['booking_id'] ?? null);
    $orderId = jeab_bookings_link_int($input['order_id'] ?? null);
    $base = [
        'action' => 'bookings.link_order',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'booking_id' => $bookingId,
        'order_id' => $orderId,
        'undo_id' => null,
    ];
    if ($bookingId <= 0 || $orderId <= 0) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'booking_id och order_id krävs.', 'status' => 422]);
    }
    $pdo = jeab_bookings_link_pdo($store);
    if (!$pdo instanceof PDO) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400]);
    }
    $booking = jeab_bookings_link_booking($pdo, $bookingId);
    if ($booking === null) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Bokningen finns inte.', 'status' => 404]);
    }
    $order = jeab_bookings_link_order_contact($pdo, $orderId);
    if (!$order['found']) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Ordern finns inte.', 'status' => 404]);
    }
    $bookingContact = trim((string) ($booking['contact_id'] ?? ''));
    if ($bookingContact === '' || $order['contact'] === '' || $bookingContact !== $order['contact']) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Kontakterna stämmer inte.', 'status' => 409]);
    }
    $hasOrder = jeab_bookings_link_has_column($pdo, 'order_id');
    if ($hasOrder && !jeab_bookings_link_order_empty($booking['order_id'] ?? null)) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Bokningen har redan en order.', 'status' => 409]);
    }
    $base['contact_id'] = $bookingContact;
    if ($dry) {
        return jeab_bookings_link_public($base + ['ok' => true, 'would_link' => 1, 'status' => 200]);
    }
    if ((string) ($input['confirm'] ?? '') !== 'link') {
        $base['read_only'] = true;

        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'confirm:link krävs.', 'status' => 400]);
    }
    try {
        jeab_bookings_link_ensure_order($pdo);
        jeab_bookings_link_ensure_log($pdo);
        jeab_bookings_link_seal();
    } catch (Throwable) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Länken gick inte att spara.', 'status' => 500]);
    }
    if (!jeab_bookings_link_has_column($pdo, 'order_id') || !function_exists('jeab_pdo_transaction')) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Länken gick inte att spara.', 'status' => 500]);
    }
    $undoId = bin2hex(random_bytes(16));
    try {
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($bookingId, $orderId, $bookingContact, $undoId): void {
            $seal = function_exists('jeab_restore_seal_read') ? jeab_restore_seal_read($pdo) : [];
            $locked = jeab_bookings_link_booking($pdo, $bookingId);
            if ($locked === null) {
                throw new RuntimeException('missing');
            }
            if (trim((string) ($locked['contact_id'] ?? '')) !== $bookingContact) {
                throw new RuntimeException('contact');
            }
            if (!jeab_bookings_link_order_empty($locked['order_id'] ?? null)) {
                throw new RuntimeException('taken');
            }
            $updated = $pdo->prepare('UPDATE bookings SET order_id = ? WHERE id = ? AND (order_id IS NULL OR order_id = \'\' OR order_id = 0)');
            $updated->execute([$orderId, $bookingId]);
            if ($updated->rowCount() !== 1) {
                throw new RuntimeException('taken');
            }
            $pdo->prepare('INSERT INTO booking_order_link_log (undo_id, booking_id, order_id, undone) VALUES (?, ?, ?, 0)')
                ->execute([$undoId, $bookingId, $orderId]);
            if (function_exists('jeab_restore_seal_read') && jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Länken gick inte att spara.', 'status' => 500]);
    }
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['undo_id'] = $undoId;

    return jeab_bookings_link_public($base + ['ok' => true, 'linked' => 1, 'status' => 200]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_bookings_link_order_undo(JeabStore $store, array $input): array
{
    $dry = jeab_bookings_link_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $base = [
        'action' => 'bookings.link_order_undo',
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'undo_id' => $undoId,
        'would_clear' => [],
        'cleared' => [],
        'kept' => [],
        'gone' => [],
    ];
    if (preg_match('/\A[a-f0-9]{16,64}\z/', $undoId) !== 1) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'undo_id krävs.', 'status' => 422]);
    }
    $pdo = jeab_bookings_link_pdo($store);
    if (!$pdo instanceof PDO) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400]);
    }
    try {
        jeab_bookings_link_ensure_log($pdo);
    } catch (Throwable) {
        return jeab_bookings_link_public($base + ['ok' => true, 'status' => 200]);
    }
    $stmt = $pdo->prepare('SELECT booking_id, order_id FROM booking_order_link_log WHERE undo_id = ? AND undone = 0');
    $stmt->execute([$undoId]);
    $logs = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $bookingId = (int) ($row['booking_id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($bookingId > 0 && $orderId > 0) {
            $logs[] = ['booking_id' => $bookingId, 'order_id' => $orderId];
        }
    }
    $would = [];
    $kept = [];
    $gone = [];
    foreach ($logs as $log) {
        $booking = jeab_bookings_link_booking($pdo, $log['booking_id']);
        if ($booking === null) {
            $gone[] = $log['booking_id'];
            continue;
        }
        if ((int) ($booking['order_id'] ?? 0) === $log['order_id']) {
            $would[] = $log['booking_id'];
            continue;
        }
        $kept[] = $log['booking_id'];
    }
    $base['would_clear'] = $would;
    $base['kept'] = $kept;
    $base['gone'] = $gone;
    if ($dry || $would === []) {
        return jeab_bookings_link_public($base + ['ok' => true, 'status' => 200]);
    }
    if ((string) ($input['confirm'] ?? '') !== 'undo') {
        $base['read_only'] = true;

        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'confirm:undo krävs.', 'status' => 400]);
    }
    $cleared = [];
    try {
        jeab_bookings_link_seal();
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($logs, $undoId, &$cleared, &$kept, &$gone): void {
            $seal = function_exists('jeab_restore_seal_read') ? jeab_restore_seal_read($pdo) : [];
            foreach ($logs as $log) {
                $booking = jeab_bookings_link_booking($pdo, $log['booking_id']);
                if ($booking === null) {
                    $gone[] = $log['booking_id'];
                    continue;
                }
                if ((int) ($booking['order_id'] ?? 0) !== $log['order_id']) {
                    $kept[] = $log['booking_id'];
                    continue;
                }
                $updated = $pdo->prepare('UPDATE bookings SET order_id = NULL WHERE id = ? AND order_id = ?');
                $updated->execute([$log['booking_id'], $log['order_id']]);
                if ($updated->rowCount() !== 1) {
                    $kept[] = $log['booking_id'];
                    continue;
                }
                $pdo->prepare('UPDATE booking_order_link_log SET undone = 1 WHERE undo_id = ? AND booking_id = ? AND undone = 0')
                    ->execute([$undoId, $log['booking_id']]);
                $cleared[] = $log['booking_id'];
            }
            if (function_exists('jeab_restore_seal_read') && jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        return jeab_bookings_link_public($base + ['ok' => false, 'error' => 'Ångringen gick inte att spara.', 'status' => 500]);
    }
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['cleared'] = $cleared;
    $base['kept'] = array_values(array_unique($kept));
    $base['gone'] = array_values(array_unique($gone));

    return jeab_bookings_link_public($base + ['ok' => true, 'status' => 200]);
}
