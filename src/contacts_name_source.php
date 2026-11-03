<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * contacts.name_source. Läser bara.
 * booking_id är bokningens siffra. key är kt_-värdet i bookings.contact_id
 * när det pekar på exakt en bokning. Flera bokningar med samma key är 400.
 * Vägen kommer från jeab_contacts_sole_booking_name_choice, samma funktion
 * som jeab_contacts_sole_paint_booking använder. Möjliga path: contact,
 * linked_order, linked_reminder, candidate, none. candidate kräver
 * CONTACTS_NAME_CANDIDATE_DISPLAY. Svaret har inga namn.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_name_source(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_name_source_fail(400, 'Ingen lagring.');
    }
    $parsed = jeab_contacts_name_source_input($input);
    if (isset($parsed['error'])) {
        return jeab_contacts_name_source_fail(400, (string) $parsed['error']);
    }
    $bookingId = (int) ($parsed['booking_id'] ?? 0);
    $key = (string) ($parsed['key'] ?? '');
    if ($bookingId > 0) {
        $booking = $store->getBooking($bookingId);
        if (!is_array($booking)) {
            return jeab_contacts_name_source_fail(404, 'Bokningen finns inte.');
        }
        if ($key !== '' && trim((string) ($booking['contact_id'] ?? '')) !== $key) {
            return jeab_contacts_name_source_fail(400, 'booking_id och key är olika bokningar.');
        }
    } else {
        $found = jeab_contacts_name_source_by_key($store->pdo(), $key);
        if (isset($found['error'])) {
            return jeab_contacts_name_source_fail((int) $found['status'], (string) $found['error']);
        }
        $bookingId = (int) ($found['booking_id'] ?? 0);
        $booking = $store->getBooking($bookingId);
        if (!is_array($booking)) {
            return jeab_contacts_name_source_fail(404, 'Bokningen finns inte.');
        }
    }
    if (!function_exists('jeab_contacts_sole_booking_name_choice')) {
        return jeab_contacts_name_source_fail(400, 'Ingen lagring.');
    }
    $choice = jeab_contacts_sole_booking_name_choice($booking);
    $path = (string) ($choice['path'] ?? 'none');
    if (!in_array($path, ['contact', 'linked_order', 'linked_reminder', 'candidate', 'none'], true)) {
        $path = 'none';
    }
    $sourceId = $choice['source_id'] ?? null;
    $sourceId = is_int($sourceId) && $sourceId > 0 ? $sourceId : null;

    return [
        'ok' => true,
        'status' => 200,
        'booking_id' => $bookingId,
        'contact_id' => trim((string) ($choice['contact_id'] ?? '')),
        'path' => $path,
        'source_id' => $sourceId,
        'contact_name_real' => !empty($choice['contact_name_real']),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_name_source_input(array $input): array
{
    $hasBooking = array_key_exists('booking_id', $input) && $input['booking_id'] !== null && $input['booking_id'] !== '';
    $hasKey = array_key_exists('key', $input) && trim((string) $input['key']) !== '';
    if (!$hasBooking && !$hasKey) {
        return ['error' => 'Saknar booking_id eller key.'];
    }
    $bookingId = 0;
    if ($hasBooking) {
        $bookingId = jeab_contacts_name_source_positive_id($input['booking_id']);
        if ($bookingId <= 0) {
            return ['error' => 'Ogiltig booking_id.'];
        }
    }
    $key = '';
    if ($hasKey) {
        if (!is_string($input['key'])) {
            return ['error' => 'Ogiltig nyckel.'];
        }
        $key = trim($input['key']);
        if (preg_match('/^kt_[A-Za-z0-9]+$/', $key) !== 1) {
            return ['error' => 'Ogiltig nyckel.'];
        }
    }

    return ['booking_id' => $bookingId, 'key' => $key];
}

function jeab_contacts_name_source_positive_id(mixed $value): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value)) === 1) {
        return (int) trim($value);
    }

    return 0;
}

/**
 * @return array{booking_id?:int,error?:string,status?:int}
 */
function jeab_contacts_name_source_by_key(PDO $pdo, string $key): array
{
    try {
        $stmt = $pdo->prepare('SELECT id FROM bookings WHERE contact_id = ? ORDER BY id LIMIT 2');
        $stmt->execute([$key]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    } catch (Throwable) {
        return ['error' => 'Bokningen finns inte.', 'status' => 404];
    }
    if ($ids === []) {
        return ['error' => 'Bokningen finns inte.', 'status' => 404];
    }
    if (count($ids) > 1) {
        return ['error' => 'Nyckeln pekar på flera bokningar.', 'status' => 400];
    }

    return ['booking_id' => $ids[0]];
}

/**
 * @return array{ok:bool,error:string,status:int}
 */
function jeab_contacts_name_source_fail(int $status, string $error): array
{
    return [
        'ok' => false,
        'error' => $error,
        'status' => $status,
    ];
}
