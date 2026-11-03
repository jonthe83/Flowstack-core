<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * bookings.delete_one. dry är standard.
 *
 * Tar bort en bokning bara när dry=0 och confirm är exakt delete.
 * Vägrar om bokningen har en påminnelse (även avslutad), en order
 * (purchases.payload booking_id, eller purchases.reminder_id mot en
 * påminnelse på bokningen), en faktura på den ordern, eller en
 * Outlook-händelse (jeab_booking_delete_outlook_block). Ett databasfel
 * i de uppslagen ger 409 och links unknown. Inget tas bort.
 * Koll, ångerrad och radering sker i en transaktion. Ångerraden har
 * hela bokningen och kalenderloggens rader (fil eller SQL, samma
 * läs- och skrivväg som kalendern redan har). Sedan
 * jeab_booking_delete_silent. Svaret har inga kunduppgifter.
 *
 * bookings.delete_one_undo lägger tillbaka samma id, tider och loggrader.
 * dry är standard. Skrivning kräver dry=0 och confirm exakt restore.
 * 409 conflict om en annan bokad bokning med samma person överlappar.
 */

final class JeabBookingsDeleteOneHalt extends RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(public array $body)
    {
        parent::__construct('halt');
    }
}

/**
 * @param array<string, mixed> $input
 */
function jeab_bookings_delete_one_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    if ((string) ($input['action'] ?? '') === 'bookings.delete_one_undo') {
        return jeab_bookings_delete_one_undo($store, $input);
    }

    return jeab_bookings_delete_one($store, $input);
}

/** Torr om dry saknas. Bara false, 0, "0" eller "false" skriver. */
function jeab_bookings_delete_one_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/**
 * @param array<string, mixed> $input
 */
function jeab_bookings_delete_one_id(array $input): int
{
    $raw = $input['id'] ?? null;
    if (is_int($raw) && $raw > 0) {
        return $raw;
    }
    if (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1) {
        return (int) $raw;
    }

    return 0;
}

/**
 * @return array<string, mixed>
 */
function jeab_bookings_delete_one_fail(string $error, int $status, bool $dry, int $id): array
{
    $out = [
        'ok' => false,
        'error' => $error,
        'status' => $status,
        'dry' => $dry,
        'deleted' => false,
        'written' => false,
    ];
    if ($id > 0) {
        $out['id'] = $id;
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_bookings_delete_one(JeabStore $store, array $input): array
{
    $dry = jeab_bookings_delete_one_dry($input);
    $id = jeab_bookings_delete_one_id($input);
    if ($id <= 0) {
        return jeab_bookings_delete_one_fail('Ett boknings-id.', 400, $dry, 0);
    }
    $pdo = jeab_bookings_delete_one_pdo($store);
    if (!$pdo instanceof PDO) {
        return jeab_bookings_delete_one_fail('Ingen SQL-databas.', 400, $dry, $id);
    }
    $raw = jeab_bookings_delete_one_raw($pdo, $id);
    if ($raw === null) {
        return jeab_bookings_delete_one_fail('Bokningen finns inte.', 404, $dry, $id);
    }
    if ($dry) {
        $linked = jeab_bookings_delete_one_link_result($pdo, $id, true);
        if ($linked !== null) {
            return $linked;
        }

        return [
            'ok' => true,
            'dry' => true,
            'id' => $id,
            'deleted' => false,
            'written' => false,
            'status' => 200,
        ];
    }
    if (($input['confirm'] ?? null) !== 'delete') {
        return jeab_bookings_delete_one_fail('Kräver confirm:delete.', 400, false, $id);
    }
    $name = function_exists('jeab_booking_delete_confirm_name')
        ? jeab_booking_delete_confirm_name($raw)
        : trim((string) ($raw['customer_name'] ?? ''));
    if ($name === '') {
        return jeab_bookings_delete_one_fail('Namnet stämmer inte. Inget ändrat.', 400, false, $id);
    }
    if (!function_exists('jeab_booking_delete_silent') || !function_exists('jeab_pdo_transaction')) {
        return jeab_bookings_delete_one_fail('Bokningen kunde inte tas bort.', 400, false, $id);
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (function_exists('jeab_ensure_booking_delete_undo')) {
            jeab_ensure_booking_delete_undo($pdo, $driver);
        }
    } catch (Throwable) {
        return jeab_bookings_delete_one_fail('Bokningen kunde inte tas bort.', 400, false, $id);
    }
    try {
        $undoId = jeab_pdo_transaction($pdo, static function (PDO $tx) use ($store, $id, $name): string {
            $tx->prepare('UPDATE bookings SET updated_at = updated_at WHERE id = ?')->execute([$id]);
            $locked = jeab_bookings_delete_one_raw($tx, $id);
            if ($locked === null) {
                throw new JeabBookingsDeleteOneHalt(jeab_bookings_delete_one_fail('Bokningen finns inte.', 404, false, $id));
            }
            $linked = jeab_bookings_delete_one_link_result($tx, $id, false);
            if ($linked !== null) {
                throw new JeabBookingsDeleteOneHalt($linked);
            }
            $entries = jeab_bookings_delete_one_calendar_entries($id);
            $undoId = jeab_bookings_delete_one_write_undo($tx, $id, $locked, $entries);
            jeab_booking_delete_silent($store, $id, $name, false);
            if (jeab_bookings_delete_one_raw($tx, $id) !== null) {
                throw new RuntimeException('Bokningen kunde inte tas bort.');
            }

            return $undoId;
        });
    } catch (JeabBookingsDeleteOneHalt $e) {
        return $e->body;
    } catch (Throwable $e) {
        return jeab_bookings_delete_one_fail(jeab_bookings_delete_one_safe_error($e), 400, false, $id);
    }

    return [
        'ok' => true,
        'dry' => false,
        'id' => $id,
        'deleted' => true,
        'undo_id' => $undoId,
        'written' => true,
        'status' => 200,
    ];
}

function jeab_bookings_delete_one_safe_error(Throwable $e): string
{
    $allow = [
        'Bokningen finns inte.',
        'Namnet stämmer inte. Inget ändrat.',
        'Bokningen hör till en order eller påminnelse. Inget ändrat.',
        'Ingen kopia kunde sparas. Inget ändrat.',
        'Loggen kunde inte kopieras.',
    ];
    $message = $e->getMessage();

    return in_array($message, $allow, true) ? $message : 'Bokningen kunde inte tas bort.';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_bookings_delete_one_undo(JeabStore $store, array $input): array
{
    $dry = jeab_bookings_delete_one_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return [
            'ok' => false,
            'error' => 'Ogiltigt undo_id.',
            'status' => 400,
            'dry' => $dry,
            'written' => false,
            'restored' => false,
        ];
    }
    $pdo = jeab_bookings_delete_one_pdo($store);
    if (!$pdo instanceof PDO) {
        return jeab_bookings_delete_one_undo_fail('Ingen SQL-databas.', 400, $dry, $undoId);
    }
    $logged = jeab_bookings_delete_one_read_undo($pdo, $undoId);
    if ($logged === null) {
        return jeab_bookings_delete_one_undo_fail('Okänt undo_id.', 404, $dry, $undoId);
    }
    $undone = $logged['undone_at'] ?? null;
    if ($undone !== null && trim((string) $undone) !== '') {
        return jeab_bookings_delete_one_undo_fail('Redan återställd.', 409, $dry, $undoId);
    }
    $snap = json_decode((string) ($logged['booking_json'] ?? ''), true);
    if (!is_array($snap)) {
        return jeab_bookings_delete_one_undo_fail('Bokningen kunde inte återställas.', 400, $dry, $undoId);
    }
    $bookingId = (int) ($snap['id'] ?? 0);
    $starts = $snap['starts_at'] ?? null;
    $ends = $snap['ends_at'] ?? null;
    if (
        $bookingId <= 0
        || (int) ($logged['booking_id'] ?? 0) !== $bookingId
        || !is_scalar($starts)
        || !is_scalar($ends)
        || (string) $starts === ''
        || (string) $ends === ''
    ) {
        return jeab_bookings_delete_one_undo_fail('Bokningen kunde inte återställas.', 400, $dry, $undoId);
    }
    $starts = (string) $starts;
    $ends = (string) $ends;
    $entries = jeab_bookings_delete_one_calendar_saved($logged);
    if (jeab_bookings_delete_one_overlaps($store, $snap, $starts, $ends, $bookingId)) {
        return jeab_bookings_delete_one_undo_fail('conflict', 409, $dry, $undoId);
    }
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'undo_id' => $undoId,
            'booking_id' => $bookingId,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'restored' => false,
            'written' => false,
            'status' => 200,
        ];
    }
    if (($input['confirm'] ?? null) !== 'restore') {
        return jeab_bookings_delete_one_undo_fail('Kräver confirm:restore.', 400, false, $undoId);
    }
    if (jeab_bookings_delete_one_raw($pdo, $bookingId) !== null) {
        return jeab_bookings_delete_one_undo_fail('Bokningen finns redan.', 409, false, $undoId);
    }
    try {
        jeab_bookings_delete_one_restore($pdo, $store, $undoId, $snap, $bookingId, $starts, $ends, $entries);
    } catch (JeabBookingsDeleteOneHalt $e) {
        return $e->body;
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === 'Bokningen finns redan.') {
            return jeab_bookings_delete_one_undo_fail('Bokningen finns redan.', 409, false, $undoId);
        }

        return jeab_bookings_delete_one_undo_fail('Bokningen kunde inte återställas.', 400, false, $undoId);
    } catch (Throwable) {
        if (jeab_bookings_delete_one_raw($pdo, $bookingId) !== null) {
            return jeab_bookings_delete_one_undo_fail('Bokningen finns redan.', 409, false, $undoId);
        }

        return jeab_bookings_delete_one_undo_fail('Bokningen kunde inte återställas.', 400, false, $undoId);
    }
    if (function_exists('jeab_booking_cache_forget')) {
        jeab_booking_cache_forget($bookingId);
    }

    return [
        'ok' => true,
        'dry' => false,
        'undo_id' => $undoId,
        'booking_id' => $bookingId,
        'starts_at' => $starts,
        'ends_at' => $ends,
        'restored' => true,
        'written' => true,
        'status' => 200,
    ];
}

/**
 * @return array<string, mixed>
 */
function jeab_bookings_delete_one_undo_fail(string $error, int $status, bool $dry, string $undoId): array
{
    return [
        'ok' => false,
        'error' => $error,
        'status' => $status,
        'dry' => $dry,
        'undo_id' => $undoId,
        'written' => false,
        'restored' => false,
    ];
}

function jeab_bookings_delete_one_pdo(JeabStore $store): ?PDO
{
    if (!method_exists($store, 'pdo')) {
        return null;
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return null;
    }

    return $pdo instanceof PDO ? $pdo : null;
}

/**
 * Rå rad, inte den hydrerade. null i customer_id ska förbli null.
 *
 * @return array<string, mixed>|null
 */
function jeab_bookings_delete_one_raw(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }

    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $raw
 */
/**
 * @param array<string, mixed> $raw
 * @param list<array<string, mixed>> $entries
 */
function jeab_bookings_delete_one_write_undo(PDO $pdo, int $id, array $raw, array $entries): string
{
    if (!function_exists('jeab_pdo_tx_busy') || !jeab_pdo_tx_busy($pdo)) {
        if (!function_exists('jeab_ensure_booking_delete_undo')) {
            throw new RuntimeException('Bokningen kunde inte tas bort.');
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        jeab_ensure_booking_delete_undo($pdo, $driver);
    }
    $json = json_encode($raw, JSON_UNESCAPED_UNICODE);
    $logJson = json_encode(array_values($entries), JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '' || $json === 'null' || !is_string($logJson)) {
        throw new RuntimeException('Bokningen kunde inte tas bort.');
    }
    $undoId = bin2hex(random_bytes(8));
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO booking_delete_undo (undo_id, created_at, booking_id, booking_json, calendar_log_json) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$undoId, $now, $id, $json, $logJson]);

    return $undoId;
}

function jeab_bookings_delete_one_drop_undo(PDO $pdo, string $undoId): void
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM booking_delete_undo WHERE undo_id = ? AND undone_at IS NULL')->execute([$undoId]);
    } catch (Throwable) {
    }
}

/**
 * @return array<string, mixed>|null
 */
function jeab_bookings_delete_one_read_undo(PDO $pdo, string $undoId): ?array
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return null;
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (function_exists('jeab_ensure_booking_delete_undo')) {
            jeab_ensure_booking_delete_undo($pdo, $driver);
        }
        $stmt = $pdo->prepare(
            'SELECT undo_id, booking_id, booking_json, calendar_log_json, undone_at FROM booking_delete_undo WHERE undo_id = ? ORDER BY id LIMIT 1'
        );
        $stmt->execute([$undoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }

    return is_array($row) ? $row : null;
}

/**
 * 409-svar när något är kopplat eller ett uppslag föll. null när det är fritt.
 *
 * @return array<string, mixed>|null
 */
function jeab_bookings_delete_one_link_result(PDO $pdo, int $id, bool $dry): ?array
{
    $links = jeab_bookings_delete_one_links($pdo, $id);
    if ($links === []) {
        return null;
    }
    $error = 'Bokningen hör till en order, påminnelse eller faktura. Inget ändrat.';
    if ($links === ['unknown']) {
        $error = 'Kopplingarna kunde inte läsas. Inget ändrat.';
    } elseif ($links === ['outlook']) {
        $error = 'Bokningen har en Outlook-händelse. Inget ändrat.';
    }

    return [
        'ok' => false,
        'error' => $error,
        'status' => 409,
        'dry' => $dry,
        'id' => $id,
        'deleted' => false,
        'written' => false,
        'links' => $links,
    ];
}

/**
 * Ett databasfel i något uppslag blir unknown. Delträffar släpps då.
 *
 * @return list<string>
 */
function jeab_bookings_delete_one_links(PDO $pdo, int $id): array
{
    try {
        $links = [];
        if (jeab_bookings_delete_one_has_reminder($pdo, $id)) {
            $links[] = 'reminder';
        }
        $orders = jeab_bookings_delete_one_purchase_rows($pdo, $id);
        if ($orders !== []) {
            $links[] = 'order';
        }
        foreach ($orders as $order) {
            if (jeab_bookings_delete_one_has_invoice($order)) {
                $links[] = 'invoice';
                break;
            }
        }
        if (jeab_bookings_delete_one_has_outlook($pdo, $id)) {
            $links[] = 'outlook';
        }

        return $links;
    } catch (Throwable) {
        return ['unknown'];
    }
}

function jeab_bookings_delete_one_has_reminder(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM reminders WHERE booking_id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetchColumn() !== false;
}

/**
 * @param array<string, mixed> $row
 */
function jeab_bookings_delete_one_has_invoice(array $row): bool
{
    if (!empty($GLOBALS['_JEAB_BDO_INVOICE_THROW'])) {
        throw new RuntimeException('invoice');
    }
    if (function_exists('jeab_ordrar_has_invoice')) {
        return jeab_ordrar_has_invoice($row);
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    if (trim((string) ($steps['faktura_skapad'] ?? '')) !== '') {
        return true;
    }
    foreach (['fortnox_invoice', 'invoice_number', 'invoice', 'fortnox_invoice_number'] as $key) {
        if (trim((string) ($row[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Order = payload/list_json booking_id, eller ett köp vars reminder_id
 * pekar på en påminnelse för bokningen. Faktura läses på samma köp.
 *
 * @return list<array<string, mixed>>
 */
function jeab_bookings_delete_one_purchase_rows(PDO $pdo, int $bookingId): array
{
    $found = [];
    $push = static function (array $row) use (&$found): void {
        $decoded = jeab_bookings_delete_one_purchase_decode($row);
        $key = (int) ($decoded['reminder_id'] ?? 0);
        $found[$key > 0 ? 'r' . $key : 'x' . count($found)] = $decoded;
    };
    $reminderIds = [];
    $stmt = $pdo->prepare('SELECT id FROM reminders WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int) ($row['id'] ?? 0);
        if ($rid > 0) {
            $reminderIds[$rid] = true;
        }
    }
    $hasList = function_exists('jeab_sql_has_column') && jeab_sql_has_column($pdo, 'purchases', 'list_json');
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $expr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_id')) AS UNSIGNED)";
        if ($hasList) {
            $lexpr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(list_json, '$.booking_id')) AS UNSIGNED)";
            $stmt = $pdo->prepare(
                'SELECT reminder_id, payload, list_json FROM purchases WHERE ' . $expr . ' = ? OR ' . $lexpr . ' = ?'
            );
            $stmt->execute([$bookingId, $bookingId]);
        } else {
            $stmt = $pdo->prepare('SELECT reminder_id, payload FROM purchases WHERE ' . $expr . ' = ?');
            $stmt->execute([$bookingId]);
        }
    } else {
        $expr = "CAST(json_extract(payload, '$.booking_id') AS INTEGER)";
        if ($hasList) {
            $lexpr = "CAST(json_extract(list_json, '$.booking_id') AS INTEGER)";
            $stmt = $pdo->prepare(
                'SELECT reminder_id, payload, list_json FROM purchases WHERE ' . $expr . ' = ? OR ' . $lexpr . ' = ?'
            );
            $stmt->execute([$bookingId, $bookingId]);
        } else {
            $stmt = $pdo->prepare('SELECT reminder_id, payload FROM purchases WHERE ' . $expr . ' = ?');
            $stmt->execute([$bookingId]);
        }
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) {
            $push($row);
        }
    }
    if ($reminderIds !== []) {
        $ids = array_keys($reminderIds);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $sql = $hasList
            ? 'SELECT reminder_id, payload, list_json FROM purchases WHERE reminder_id IN (' . $marks . ')'
            : 'SELECT reminder_id, payload FROM purchases WHERE reminder_id IN (' . $marks . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $push($row);
            }
        }
    }

    return array_values($found);
}

/** Sparad Outlook-rad. Blocket är samma som tyst radering, utan Graph-anrop. */
function jeab_bookings_delete_one_has_outlook(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT resource, email, graph_event_id FROM booking_outlook_events WHERE booking_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $stored) {
        if (!is_array($stored)) {
            continue;
        }
        $email = trim((string) ($stored['email'] ?? ''));
        $eventId = trim((string) ($stored['graph_event_id'] ?? ''));
        if ($email === '' || $eventId === '') {
            continue;
        }
        $link = [
            'grant_email' => $email,
            'grant_name' => (string) ($stored['resource'] ?? $email),
            'grant_company' => '',
            'calendar_id' => 'primary',
            'calendar_name' => '',
            'event_id' => $eventId,
            'fetched' => false,
        ];
        if (!function_exists('jeab_booking_delete_outlook_block') || jeab_booking_delete_outlook_block($link) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_bookings_delete_one_purchase_decode(array $row): array
{
    $payload = [];
    if (is_string($row['payload'] ?? null) && $row['payload'] !== '') {
        $decoded = json_decode($row['payload'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if (is_string($row['list_json'] ?? null) && $row['list_json'] !== '') {
        $list = json_decode($row['list_json'], true);
        if (is_array($list)) {
            if (!array_key_exists('booking_id', $payload) && array_key_exists('booking_id', $list)) {
                $payload['booking_id'] = $list['booking_id'];
            }
            foreach (['fortnox_invoice', 'invoice_number', 'invoice', 'fortnox_invoice_number', 'steps', 'fortnox_order'] as $key) {
                if (!array_key_exists($key, $payload) && array_key_exists($key, $list)) {
                    $payload[$key] = $list[$key];
                }
            }
        }
    }
    $payload['reminder_id'] = (int) ($row['reminder_id'] ?? ($payload['reminder_id'] ?? 0));

    return $payload;
}

/**
 * @param array<string, mixed> $snap
 * @param list<array<string, mixed>> $entries
 */
function jeab_bookings_delete_one_restore(PDO $pdo, JeabStore $store, string $undoId, array $snap, int $bookingId, string $starts, string $ends, array $entries): void
{
    $wanted = [];
    foreach (jeab_bookings_delete_one_columns($pdo) as $col) {
        if (!array_key_exists($col, $snap)) {
            continue;
        }
        $value = $snap[$col];
        if (is_array($value) || is_object($value)) {
            continue;
        }
        $wanted[$col] = $value;
    }
    // jeab_sql_insert_id tar inte emot id. Samma id och de lagrade tiderna skrivs direkt.
    $wanted['id'] = $bookingId;
    $wanted['starts_at'] = $starts;
    $wanted['ends_at'] = $ends;
    $names = array_keys($wanted);
    $params = array_values($wanted);
    foreach ($names as $name) {
        if (!function_exists('jeab_sql_ident_ok') || !jeab_sql_ident_ok($name)) {
            throw new RuntimeException('Bokningen kunde inte återställas.');
        }
    }
    if (!function_exists('jeab_pdo_transaction')) {
        throw new RuntimeException('Bokningen kunde inte återställas.');
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    jeab_pdo_transaction($pdo, static function (PDO $tx) use ($store, $snap, $names, $params, $bookingId, $starts, $ends, $undoId, $now, $entries): void {
        $exists = $tx->prepare('SELECT 1 FROM bookings WHERE id = ?');
        $exists->execute([$bookingId]);
        if ($exists->fetchColumn() !== false) {
            throw new InvalidArgumentException('Bokningen finns redan.');
        }
        if (jeab_bookings_delete_one_overlaps($store, $snap, $starts, $ends, $bookingId)) {
            throw new JeabBookingsDeleteOneHalt(jeab_bookings_delete_one_undo_fail('conflict', 409, false, $undoId));
        }
        $marks = implode(', ', array_fill(0, count($names), '?'));
        $tx->prepare('INSERT INTO bookings (' . implode(', ', $names) . ') VALUES (' . $marks . ')')->execute($params);
        $got = $tx->prepare('SELECT id, starts_at, ends_at FROM bookings WHERE id = ?');
        $got->execute([$bookingId]);
        $back = $got->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($back)
            || (int) ($back['id'] ?? 0) !== $bookingId
            || (string) ($back['starts_at'] ?? '') !== $starts
            || (string) ($back['ends_at'] ?? '') !== $ends
        ) {
            throw new RuntimeException('Bokningen kunde inte återställas.');
        }
        jeab_bookings_delete_one_bump_seq($tx, $bookingId);
        jeab_bookings_delete_one_calendar_restore($entries);
        $mark = $tx->prepare('UPDATE booking_delete_undo SET undone_at = ? WHERE undo_id = ? AND undone_at IS NULL');
        $mark->execute([$now, $undoId]);
        if ($mark->rowCount() !== 1) {
            throw new RuntimeException('Bokningen kunde inte återställas.');
        }
    });
}

/**
 * @return list<string>
 */
function jeab_bookings_delete_one_columns(PDO $pdo): array
{
    $names = [];
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $rows = $pdo->query('SHOW COLUMNS FROM `bookings`');
            foreach ($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
                $names[] = (string) ($col['Field'] ?? '');
            }
        } else {
            $rows = $pdo->query('PRAGMA table_info(bookings)');
            foreach ($rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
                $names[] = (string) ($col['name'] ?? '');
            }
        }
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($names as $name) {
        if ($name !== '' && function_exists('jeab_sql_ident_ok') && jeab_sql_ident_ok($name)) {
            $out[] = $name;
        }
    }

    return $out;
}

function jeab_bookings_delete_one_bump_seq(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        return;
    }
    $driver = 'sqlite';
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
        return;
    }
    if ($driver === 'mysql') {
        $stmt = $pdo->query("SHOW TABLES LIKE 'jeab_id_seq'");
        $exists = $stmt !== false && $stmt->fetchColumn() !== false;
    } else {
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'jeab_id_seq'");
        $exists = $stmt !== false && (bool) $stmt->fetchColumn();
    }
    if (!$exists) {
        return;
    }
    $stmt = $pdo->prepare('SELECT next_id FROM jeab_id_seq WHERE name = ?');
    $stmt->execute(['bookings']);
    $next = $stmt->fetchColumn();
    if ($next === false || (int) $next > $id) {
        return;
    }
    $pdo->prepare('UPDATE jeab_id_seq SET next_id = ? WHERE name = ?')->execute([$id + 1, 'bookings']);
}

/**
 * Samma överlapp som bokningen redan varnar för. Bara bokade rader räknas.
 *
 * @param array<string, mixed> $snap
 */
function jeab_bookings_delete_one_overlaps(JeabStore $store, array $snap, string $starts, string $ends, int $bookingId): bool
{
    if (!function_exists('jeab_booking_assignee_overlap') || !function_exists('jeab_assignees_from_row')) {
        return false;
    }
    $names = jeab_assignees_from_row($snap);
    if ($names === [] || $starts === '' || $ends === '') {
        return false;
    }

    return jeab_booking_assignee_overlap($store, $names, $starts, $ends, $bookingId) !== [];
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_bookings_delete_one_calendar_entries(int $bookingId): array
{
    if ($bookingId <= 0 || !function_exists('jeab_calendar_log_read')) {
        return [];
    }
    $data = jeab_calendar_log_read();
    $out = [];
    foreach (is_array($data['entries'] ?? null) ? $data['entries'] : [] as $entry) {
        if (is_array($entry) && (int) ($entry['booking_id'] ?? 0) === $bookingId) {
            $out[] = $entry;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $logged
 * @return list<array<string, mixed>>
 */
function jeab_bookings_delete_one_calendar_saved(array $logged): array
{
    $decoded = json_decode((string) ($logged['calendar_log_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $entry) {
        if (is_array($entry)) {
            $out[] = $entry;
        }
    }

    return $out;
}

/**
 * Skriver tillbaka via kalenderns egen väg: dokumentet (fil, log_docs eller
 * json_docs) och, när SQL-raderna används, log_lines.
 *
 * @param list<array<string, mixed>> $entries
 */
function jeab_bookings_delete_one_calendar_restore(array $entries): void
{
    $entries = array_values(array_filter($entries, 'is_array'));
    if ($entries === []) {
        return;
    }
    if (!function_exists('jeab_calendar_log_read') || !function_exists('jeab_calendar_log_write')) {
        throw new RuntimeException('Bokningen kunde inte återställas.');
    }
    $data = jeab_calendar_log_read();
    if (!is_array($data['entries'] ?? null)) {
        $data['entries'] = [];
    }
    $seen = [];
    foreach ($data['entries'] as $entry) {
        if (is_array($entry) && (int) ($entry['id'] ?? 0) > 0) {
            $seen[(int) $entry['id']] = true;
        }
    }
    foreach ($entries as $entry) {
        $eid = (int) ($entry['id'] ?? 0);
        if ($eid > 0 && isset($seen[$eid])) {
            continue;
        }
        $data['entries'][] = $entry;
        if ($eid > 0) {
            $seen[$eid] = true;
        }
    }
    jeab_calendar_log_write($data);
    if (function_exists('jeab_calendar_log_store_row')) {
        foreach ($entries as $entry) {
            jeab_calendar_log_store_row($entry);
        }
    }
    $back = jeab_calendar_log_read();
    $have = is_array($back['entries'] ?? null) ? $back['entries'] : [];
    foreach ($entries as $entry) {
        if (!jeab_bookings_delete_one_calendar_has($have, $entry)) {
            throw new RuntimeException('Bokningen kunde inte återställas.');
        }
    }
}

/**
 * @param list<array<string, mixed>> $entries
 * @param array<string, mixed> $want
 */
function jeab_bookings_delete_one_calendar_has(array $entries, array $want): bool
{
    $id = (int) ($want['id'] ?? 0);
    $bookingId = (int) ($want['booking_id'] ?? 0);
    foreach ($entries as $entry) {
        if (!is_array($entry) || (int) ($entry['booking_id'] ?? 0) !== $bookingId) {
            continue;
        }
        if ($id > 0 && (int) ($entry['id'] ?? 0) === $id) {
            return true;
        }
        if (
            $id <= 0
            && (string) ($entry['type'] ?? '') === (string) ($want['type'] ?? '')
            && (string) ($entry['at'] ?? '') === (string) ($want['at'] ?? '')
        ) {
            return true;
        }
    }

    return false;
}
