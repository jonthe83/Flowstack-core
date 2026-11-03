<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/store.json → bookings. Ingen flagga, inget hook.
 * Beroende: customers. jeab_migrate_bookings_module / _rows / _checksum.
 */

function jeab_migrate_bookings_module(): array
{
    return jeab_migrate_module(
        'bookings',
        'bookings',
        'data/store.json',
        'id',
        jeab_migrate_copy_filter('bookings', ['id', 'customer_id', 'customer_name', 'email', 'phone', 'address', 'notes', 'assignee', 'assignees', 'company', 'job_type', 'starts_at', 'ends_at', 'status', 'contact_id', 'created_at', 'updated_at']),
        <<<'SQL'
CREATE TABLE IF NOT EXISTS bookings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id INT UNSIGNED NOT NULL,
    customer_name VARCHAR(191) NOT NULL,
    email VARCHAR(191) DEFAULT NULL,
    phone VARCHAR(64) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    assignee VARCHAR(191) NOT NULL,
    assignees TEXT,
    company VARCHAR(32) DEFAULT NULL,
    job_type VARCHAR(64) DEFAULT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'booked',
    contact_id VARCHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY bookings_starts_at (starts_at),
    KEY bookings_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    customer_name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    address TEXT,
    notes TEXT,
    assignee TEXT NOT NULL,
    assignees TEXT,
    company TEXT,
    job_type TEXT,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'booked',
    contact_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL,
        ['customers']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_bookings_rows(array $source): array
{
    $list = isset($source['bookings']) && is_array($source['bookings']) ? $source['bookings'] : $source;
    $rows = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $names = $row['assignees'] ?? $row['assignee'] ?? [];
        $stamp = '1970-01-01 00:00:00';
        $rows[] = [
            'id' => $id,
            'customer_id' => (int) ($row['customer_id'] ?? 0) ?: 1,
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'email' => jeab_migrate_text(isset($row['email']) ? (string) $row['email'] : null),
            'phone' => jeab_migrate_text(isset($row['phone']) ? (string) $row['phone'] : null),
            'address' => jeab_migrate_text(isset($row['address']) ? (string) $row['address'] : null),
            'notes' => jeab_migrate_text(isset($row['notes']) ? (string) $row['notes'] : null),
            'assignee' => jeab_migrate_names_join($names) ?? '',
            'assignees' => jeab_migrate_names_json($names),
            'company' => jeab_migrate_company($row['company'] ?? null),
            'job_type' => jeab_migrate_text(isset($row['job_type']) ? (string) $row['job_type'] : null),
            'starts_at' => jeab_migrate_text(isset($row['starts_at']) ? (string) $row['starts_at'] : null) ?? $stamp,
            'ends_at' => jeab_migrate_text(isset($row['ends_at']) ? (string) $row['ends_at'] : null) ?? $stamp,
            'status' => jeab_migrate_text(isset($row['status']) ? (string) $row['status'] : null) ?? 'booked',
            'contact_id' => jeab_migrate_text(isset($row['contact_id']) ? (string) $row['contact_id'] : null),
            'created_at' => jeab_migrate_text(isset($row['created_at']) ? (string) $row['created_at'] : null) ?? $stamp,
            'updated_at' => jeab_migrate_text(isset($row['updated_at']) ? (string) $row['updated_at'] : null) ?? $stamp,
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_bookings_module()['columns']);
}

function jeab_migrate_bookings_checksum(array $rows): string
{
    return jeab_migrate_checksum(jeab_migrate_bookings_rows($rows));
}
