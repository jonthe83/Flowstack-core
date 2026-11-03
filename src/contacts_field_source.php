<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * contacts.field_source är skrivskyddad. Den säger om namn, mejl, telefon
 * och adress som visas kommer från kontakten, kopian, ett fryst snapshot eller
 * annat. copy används bara när kopians värde är ifyllt. Tom kopia som inte är
 * kontakt eller snapshot blir other. Svaret har hash och match, inte klartext
 * och inte personnummer.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_field_source_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return jeab_contacts_field_source_public([
            'ok' => false,
            'error' => 'Fel token.',
            'status' => 401,
        ]);
    }
    try {
        $result = jeab_contacts_field_source($input);
    } catch (InvalidArgumentException $e) {
        return jeab_contacts_field_source_public([
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 422,
        ]);
    }
    if (!isset($result['status'])) {
        $result['status'] = !empty($result['ok']) ? 200 : 400;
    }

    return jeab_contacts_field_source_public($result);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_field_source(array $input): array
{
    [$kind, $id] = jeab_contacts_field_source_target($input);
    $loaded = jeab_contacts_field_source_load($kind, $id);
    if ($loaded === null) {
        return [
            'ok' => false,
            'error' => 'Posten finns inte.',
            'status' => 404,
            'kind' => $kind,
            'id' => $id,
        ];
    }
    $report = jeab_contacts_field_source_report($kind, $id, $loaded);

    return $report;
}

/**
 * @param array<string, mixed> $input
 * @return array{0:string,1:string}
 */
function jeab_contacts_field_source_target(array $input): array
{
    $map = [
        'reminder' => 'reminder_id',
        'booking' => 'booking_id',
        'order' => 'order_id',
        'inkorg' => 'thread_id',
    ];
    $kind = trim((string) ($input['kind'] ?? ''));
    if ($kind === '' ) {
        foreach ($map as $name => $key) {
            if (array_key_exists($key, $input) && trim((string) $input[$key]) !== '') {
                $kind = $name;
                break;
            }
        }
    }
    if (!isset($map[$kind])) {
        throw new InvalidArgumentException('Skriv kind.');
    }
    $id = trim((string) ($input['id'] ?? $input[$map[$kind]] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('Skriv id.');
    }

    return [$kind, $id];
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_field_source_load(string $kind, string $id): ?array
{
    if ($kind === 'inkorg') {
        return jeab_contacts_field_source_load_inkorg($id);
    }
    $numeric = (int) $id;
    if ($numeric <= 0 || (string) $numeric !== $id) {
        return null;
    }
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if (!$store instanceof JeabStore) {
        return null;
    }
    if ($kind === 'reminder') {
        $row = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $numeric) : $store->getReminder($numeric);

        return is_array($row) ? $row : null;
    }
    if ($kind === 'booking') {
        $row = function_exists('jeab_get_booking') ? jeab_get_booking($store, $numeric) : $store->getBooking($numeric);

        return is_array($row) ? $row : null;
    }
    if (!function_exists('jeab_purchase_get')) {
        return null;
    }
    $row = jeab_purchase_get($numeric);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_field_source_load_inkorg(string $id): ?array
{
    $store = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return null;
    }
    $one = $store->threadsByIds([$id]);
    $row = $one[$id] ?? null;
    if (!is_array($row) || trim((string) ($row['created_at'] ?? '')) === '') {
        return null;
    }
    $copy = [
        'id' => $id,
        'contact_id' => (string) ($row['contact_id'] ?? ''),
        'customer_name' => (string) ($row['customer_name'] ?? $row['name'] ?? ''),
        'email' => (string) ($row['customer_email'] ?? $row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'address' => (string) ($row['address'] ?? ''),
    ];
    if (is_array($row['snapshots'] ?? null)) {
        $copy['snapshots'] = $row['snapshots'];
    }

    return $copy;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_field_source_report(string $kind, string $id, array $row): array
{
    $copy = jeab_contacts_field_source_party($row);
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = $contactId !== '' && function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($contactId) : null;
    $contactParty = [
        'name' => '',
        'email' => '',
        'phone' => '',
        'address' => '',
    ];
    if (is_array($contact) && function_exists('jeab_contacts_sole_view_from_contact')) {
        $view = jeab_contacts_sole_view_from_contact($contact);
        $contactParty = [
            'name' => (string) ($view['name'] ?? ''),
            'email' => (string) ($view['email'] ?? ''),
            'phone' => (string) ($view['phone'] ?? ''),
            'address' => (string) ($view['display'] ?? ''),
        ];
        $contactId = trim((string) ($view['id'] ?? $contactId));
    }
    $snap = jeab_contacts_field_source_snapshot($row, $kind);
    $shownRow = jeab_contacts_field_source_shown_row($kind, $row);
    $shown = jeab_contacts_field_source_party($shownRow);
    $fields = [];
    foreach (['name', 'email', 'phone', 'address'] as $field) {
        $fields[$field] = jeab_contacts_field_source_classify(
            $shown[$field],
            $contactParty[$field],
            $copy[$field],
            $snap['party'][$field] ?? '',
            $snap['use'],
            $field
        );
    }

    return [
        'ok' => true,
        'kind' => $kind,
        'id' => $id,
        'contact_id' => $contactId,
        'fields' => $fields,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_field_source_shown_row(string $kind, array $row): array
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return $row;
    }
    if ($kind === 'booking' && function_exists('jeab_contacts_sole_paint_booking')) {
        return jeab_contacts_sole_paint_booking($row);
    }
    if ($kind === 'order' && function_exists('jeab_contacts_sole_paint_order')) {
        return jeab_contacts_sole_paint_order($row);
    }
    if (function_exists('jeab_contacts_sole_paint_reminder')) {
        return jeab_contacts_sole_paint_reminder($row);
    }

    return $row;
}

/**
 * @param array<string, mixed> $row
 * @return array{name:string,email:string,phone:string,address:string}
 */
function jeab_contacts_field_source_party(array $row): array
{
    $name = '';
    foreach (['customer_name', 'name', 'huvudsokande'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            $name = $value;
            break;
        }
    }
    $address = trim((string) ($row['address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    }

    return [
        'name' => $name,
        'email' => trim((string) ($row['email'] ?? $row['customer_email'] ?? '')),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'address' => $address,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{use:bool,party:array{name:string,email:string,phone:string,address:string}}
 */
function jeab_contacts_field_source_snapshot(array $row, string $kind): array
{
    $empty = [
        'use' => false,
        'party' => ['name' => '', 'email' => '', 'phone' => '', 'address' => ''],
    ];
    $types = match ($kind) {
        'order' => ['invoice', 'offert', 'fullmakt'],
        'inkorg' => ['offert', 'mail'],
        'booking' => ['offert'],
        default => ['offert', 'fullmakt'],
    };
    if (!function_exists('jeab_snapshots_should_freeze') || !function_exists('jeab_snapshots_read')) {
        return $empty;
    }
    foreach ($types as $type) {
        if (!jeab_snapshots_should_freeze($row, $type)) {
            continue;
        }
        $snap = jeab_snapshots_read($row, $type);
        if (!is_array($snap)) {
            continue;
        }

        return [
            'use' => true,
            'party' => [
                'name' => trim((string) ($snap['name'] ?? '')),
                'email' => trim((string) ($snap['email'] ?? '')),
                'phone' => trim((string) ($snap['phone'] ?? '')),
                'address' => trim((string) ($snap['address'] ?? '')),
            ],
        ];
    }

    return $empty;
}

/**
 * @return array{source:string,hash:string,match:int}
 */
function jeab_contacts_field_source_classify(string $shown, string $contact, string $copy, string $snapshot, bool $useSnapshot, string $field): array
{
    $shownN = jeab_contacts_field_source_norm($shown, $field);
    $contactN = jeab_contacts_field_source_norm($contact, $field);
    $copyN = jeab_contacts_field_source_norm($copy, $field);
    $snapN = jeab_contacts_field_source_norm($snapshot, $field);
    // Tom kopia är inte källan copy, även när visningen också är tom.
    $source = 'copy';
    if ($contactN !== '' && $shownN === $contactN) {
        $source = 'contact';
    } elseif ($useSnapshot && $snapN !== '' && $shownN === $snapN && ($shownN !== $copyN || $contactN === '')) {
        $source = 'snapshot';
    } elseif ($copyN === '') {
        $source = 'other';
    }

    return [
        'source' => $source,
        'hash' => jeab_contacts_field_source_hash($shown, $field),
        'match' => $shownN === $contactN ? 1 : 0,
    ];
}

function jeab_contacts_field_source_norm(string $value, string $field): string
{
    $value = trim($value);
    if ($field === 'phone' && function_exists('jeab_norm_phone')) {
        return jeab_norm_phone($value);
    }
    if ($field === 'email') {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function jeab_contacts_field_source_hash(string $value, string $field): string
{
    $norm = jeab_contacts_field_source_norm($value, $field);
    if (jeab_contacts_field_source_is_pnr($value) || jeab_contacts_field_source_is_pnr($norm)) {
        return hash('sha256', 'redacted-pnr');
    }

    return hash('sha256', $norm);
}

function jeab_contacts_field_source_is_pnr(string $value): bool
{
    $compact = preg_replace('/\s+/', '', $value) ?? '';

    return preg_match('/^(19|20)?\d{6}[-+]?\d{4}$/', $compact) === 1;
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function jeab_contacts_field_source_public(array $result): array
{
    $result['sent'] = 0;
    $result['webhook'] = 0;
    $scrubbed = jeab_contacts_field_source_scrub($result);

    return is_array($scrubbed) ? $scrubbed : $result;
}

function jeab_contacts_field_source_scrub(mixed $value): mixed
{
    if (!is_array($value)) {
        if (is_string($value) && jeab_contacts_field_source_is_pnr($value)) {
            return '';
        }

        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match('/pnr|personnummer/i', $key) === 1) {
            continue;
        }
        $out[$key] = jeab_contacts_field_source_scrub($item);
    }

    return $out;
}
