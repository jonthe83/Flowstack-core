<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * store.json → SQL när driver=json och store_storage=sql (efter cutover).
 * Med driver mysql/sqlite är JeabPdoStore redan standard — flaggan ändrar inget.
 */

const JEAB_STORE_STORAGE_KEY = 'store_storage';

function jeab_store_json_default_path(): string
{
    return jeab_data_dir() . '/store.json';
}

function jeab_store_sql_pdo(): ?PDO
{
    if (!function_exists('jeab_driver')) {
        return null;
    }
    $driver = jeab_driver();
    if ($driver !== 'sqlite' && $driver !== 'mysql') {
        return null;
    }
    try {
        if ($driver === 'mysql') {
            return jeab_pdo_connect_mysql();
        }
        return jeab_pdo_connect_sqlite();
    } catch (Throwable) {
        return null;
    }
}

function jeab_store_storage_mode(): string
{
    $legacy = 'json';
    $pdo = jeab_store_sql_pdo();
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $legacy = jeab_settings_value($pdo, $driver, JEAB_STORE_STORAGE_KEY) === 'sql' ? 'sql' : 'json';
    }
    return function_exists('jeab_data_storage') ? jeab_data_storage('bookings', $legacy) : $legacy;
}

function jeab_store_use_sql(): bool
{
    if (jeab_driver() !== 'json' || jeab_store_storage_mode() !== 'sql') {
        return false;
    }
    return jeab_store_sql_pdo() instanceof PDO;
}

function jeab_store_set_storage(string $mode): bool
{
    $pdo = jeab_store_sql_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    return jeab_settings_put($pdo, $driver, JEAB_STORE_STORAGE_KEY, $mode === 'sql' ? 'sql' : 'json');
}

/** @return array<string, mixed> */
function jeab_store_json_snapshot(): array
{
    $path = function_exists('jeab_json_path') ? jeab_json_path() : '';
    if ($path === '' || !is_file($path)) {
        return [
            'customers' => [],
            'bookings' => [],
            'reminders' => [],
        ];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return ['customers' => [], 'bookings' => [], 'reminders' => []];
    }
    return [
        'customers' => is_array($data['customers'] ?? null) ? $data['customers'] : [],
        'bookings' => is_array($data['bookings'] ?? null) ? $data['bookings'] : [],
        'reminders' => is_array($data['reminders'] ?? null) ? $data['reminders'] : [],
    ];
}

/** @return array{customers:int,bookings:int,reminders:int} */
function jeab_store_sql_counts(PDO $pdo): array
{
    return [
        'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
        'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
        'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
    ];
}

/** @param array<string, mixed> $snapshot */
function jeab_store_json_counts(array $snapshot): array
{
    return [
        'customers' => count(is_array($snapshot['customers'] ?? null) ? $snapshot['customers'] : []),
        'bookings' => count(is_array($snapshot['bookings'] ?? null) ? $snapshot['bookings'] : []),
        'reminders' => count(is_array($snapshot['reminders'] ?? null) ? $snapshot['reminders'] : []),
    ];
}

/** @param array<string, mixed> $snapshot */
function jeab_store_snapshot_checksum(array $snapshot): string
{
    $canon = [
        'customers' => jeab_store_sort_rows($snapshot['customers'] ?? [], 'id'),
        'bookings' => jeab_store_sort_rows($snapshot['bookings'] ?? [], 'id'),
        'reminders' => jeab_store_sort_rows($snapshot['reminders'] ?? [], 'id'),
    ];
    $json = json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

function jeab_store_sql_contact(array $row): ?string
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    return $id !== '' ? $id : null;
}

function jeab_store_sql_fill_contact(PDO $pdo, string $table, int $id, ?string $contactId): void
{
    if ($id <= 0 || $contactId === null || ($table !== 'bookings' && $table !== 'reminders')) {
        return;
    }
    $pdo->prepare(
        'UPDATE ' . $table . ' SET contact_id = ? WHERE id = ? AND (contact_id IS NULL OR contact_id = \'\')'
    )->execute([$contactId, $id]);
}

/** @param list<mixed> $rows */
function jeab_store_sort_rows(array $rows, string $idKey): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row[$idKey] ?? 0);
        if ($id > 0) {
            $out[$id] = $row;
        }
    }
    ksort($out);
    return array_values($out);
}

/** @return array<string, mixed> */
function jeab_store_sql_snapshot(PDO $pdo): array
{
    $customers = [];
    foreach ($pdo->query('SELECT * FROM customers ORDER BY id ASC')->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $customers[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'created_at' => (string) $row['created_at'],
        ];
    }
    $bookings = [];
    foreach ($pdo->query('SELECT * FROM bookings ORDER BY id ASC')->fetchAll() as $row) {
        if (is_array($row) && function_exists('jeab_hydrate_booking')) {
            $bookings[] = jeab_hydrate_booking($row);
        }
    }
    $reminders = [];
    foreach ($pdo->query('SELECT * FROM reminders ORDER BY id ASC')->fetchAll() as $row) {
        if (is_array($row) && function_exists('jeab_hydrate_reminder')) {
            $reminders[] = jeab_hydrate_reminder($row);
        }
    }
    return [
        'customers' => $customers,
        'bookings' => $bookings,
        'reminders' => $reminders,
    ];
}

/** @return array<string, mixed> */
function jeab_store_json_snapshot_for_verify(): array
{
    $raw = jeab_store_json_snapshot();
    $bookings = [];
    foreach ($raw['bookings'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $bookings[] = function_exists('jeab_hydrate_booking') ? jeab_hydrate_booking($row) : $row;
    }
    $reminders = [];
    foreach ($raw['reminders'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $reminders[] = function_exists('jeab_hydrate_reminder') ? jeab_hydrate_reminder($row) : $row;
    }
    return [
        'customers' => jeab_store_sort_rows($raw['customers'], 'id'),
        'bookings' => $bookings,
        'reminders' => $reminders,
    ];
}

function jeab_store_sql_import_json(PDO $pdo, bool $includeReminders = true): void
{
    if (function_exists('jeab_mysql_import_store_json')) {
        jeab_mysql_import_store_json($pdo);
    }
    if (!$includeReminders) {
        return;
    }
    $snapshot = jeab_store_json_snapshot();
    $have = [];
    foreach ($pdo->query('SELECT id FROM reminders') as $row) {
        $have[(int) $row['id']] = true;
    }
    $listId = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($listId <= 0) {
        $listId = jeab_sql_insert_id($pdo, 'reminder_lists', ['name', 'created_at'], ['Påminnelser', jeab_now()]);
        if ($listId <= 0) {
            throw new RuntimeException('Påminnelselistan gick inte att spara (inget id).');
        }
    }
    foreach ($snapshot['reminders'] as $reminder) {
        if (!is_array($reminder)) {
            continue;
        }
        $id = (int) ($reminder['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $contactId = jeab_store_sql_contact($reminder);
        if (!empty($have[$id])) {
            jeab_store_sql_fill_contact($pdo, 'reminders', $id, $contactId);
            continue;
        }
        $company = function_exists('jeab_normalize_company')
            ? (jeab_normalize_company($reminder['company'] ?? null) ?? 'jarfalla')
            : 'jarfalla';
        $names = function_exists('jeab_parse_assignees')
            ? jeab_parse_assignees($reminder['assignees'] ?? $reminder['assignee'] ?? [], $company)
            : [];
        $tags = function_exists('jeab_parse_tags') ? jeab_parse_tags($reminder['tags'] ?? []) : [];
        $person = trim((string) ($reminder['person'] ?? ''));
        $importCols = ['id', 'list_id', 'title', 'customer_name', 'email', 'phone', 'address', 'ort', 'postal', 'assignee', 'assignees', 'company', 'person', 'flagged', 'tags', 'note', 'due_at', 'completed_at', 'booking_id', 'contact_id', 'thread_id', 'created_at', 'updated_at', 'ringt', 'pratat', 'mailat', 'smsat'];
        $importVals = [
            $id,
            (int) ($reminder['list_id'] ?? $listId),
            (string) ($reminder['title'] ?? ''),
            $reminder['customer_name'] ?? null,
            $reminder['email'] ?? null,
            $reminder['phone'] ?? null,
            $reminder['address'] ?? null,
            array_key_exists('ort', $reminder) && $reminder['ort'] !== null && trim((string) $reminder['ort']) !== '' ? trim((string) $reminder['ort']) : null,
            isset($reminder['postal']) && trim((string) $reminder['postal']) !== '' ? trim((string) $reminder['postal']) : null,
            $names && function_exists('jeab_assignees_join') ? jeab_assignees_join($names) : ($reminder['assignee'] ?? null),
            function_exists('jeab_assignees_json') ? jeab_assignees_json($names) : json_encode($names),
            $company,
            $person !== '' ? $person : null,
            !empty($reminder['flagged']) ? 1 : 0,
            $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            $reminder['note'] ?? null,
            $reminder['due_at'] ?? null,
            $reminder['completed_at'] ?? null,
            $reminder['booking_id'] ?? null,
            $contactId,
            isset($reminder['thread_id']) && trim((string) $reminder['thread_id']) !== '' ? trim((string) $reminder['thread_id']) : null,
            (string) ($reminder['created_at'] ?? jeab_now()),
            (string) ($reminder['updated_at'] ?? jeab_now()),
            max(0, (int) ($reminder['ringt'] ?? 0)),
            max(0, (int) ($reminder['pratat'] ?? 0)),
            max(0, (int) ($reminder['mailat'] ?? 0)),
            max(0, (int) ($reminder['smsat'] ?? 0)),
        ];
        [$importCols, $importVals] = jeab_copy_filter_write($pdo, 'reminders', $importCols, $importVals);
        $pdo->prepare(
            'INSERT INTO reminders (' . implode(', ', $importCols) . ') VALUES (' . implode(', ', array_fill(0, count($importCols), '?')) . ')'
        )->execute($importVals);
        $have[$id] = true;
    }
}
