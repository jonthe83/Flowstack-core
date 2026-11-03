<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/store.json → reminders. Ingen flagga, inget hook.
 * Beroenden: reminder_lists, bookings.
 */

function jeab_migrate_reminders_module(): array
{
    $columns = ['id', 'list_id', 'title', 'customer_name', 'email', 'phone', 'address', 'ort', 'postal', 'assignee', 'assignees', 'company', 'person', 'flagged', 'tags', 'note', 'due_at', 'completed_at', 'booking_id', 'contact_id', 'thread_id', 'created_at', 'updated_at', 'ringt', 'pratat', 'mailat', 'smsat'];
    $columns = jeab_migrate_copy_filter('reminders', $columns);
    return jeab_migrate_module(
        'reminders',
        'reminders',
        'data/store.json',
        'id',
        $columns,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    customer_name VARCHAR(191) DEFAULT NULL,
    email VARCHAR(191) DEFAULT NULL,
    phone VARCHAR(64) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    ort VARCHAR(191) DEFAULT NULL,
    postal VARCHAR(16) DEFAULT NULL,
    assignee VARCHAR(191) DEFAULT NULL,
    assignees TEXT,
    company VARCHAR(32) DEFAULT NULL,
    person VARCHAR(64) DEFAULT NULL,
    flagged TINYINT(1) NOT NULL DEFAULT 0,
    tags TEXT,
    note TEXT,
    due_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    booking_id INT UNSIGNED DEFAULT NULL,
    contact_id VARCHAR(32) DEFAULT NULL,
    thread_id VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    ringt INT NOT NULL DEFAULT 0,
    pratat INT NOT NULL DEFAULT 0,
    mailat INT NOT NULL DEFAULT 0,
    smsat INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY reminders_list_id (list_id),
    KEY reminders_booking_id (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY,
    list_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    customer_name TEXT,
    email TEXT,
    phone TEXT,
    address TEXT,
    ort TEXT,
    postal TEXT,
    assignee TEXT,
    assignees TEXT,
    company TEXT,
    person TEXT,
    flagged INTEGER NOT NULL DEFAULT 0,
    tags TEXT,
    note TEXT,
    due_at TEXT,
    completed_at TEXT,
    booking_id INTEGER,
    contact_id TEXT,
    thread_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    ringt INTEGER NOT NULL DEFAULT 0,
    pratat INTEGER NOT NULL DEFAULT 0,
    mailat INTEGER NOT NULL DEFAULT 0,
    smsat INTEGER NOT NULL DEFAULT 0
)
SQL,
        ['reminder_lists', 'bookings']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_reminders_rows(array $source): array
{
    $list = isset($source['reminders']) && is_array($source['reminders']) ? $source['reminders'] : $source;
    $rows = [];
    $stamp = '1970-01-01 00:00:00';
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $names = $row['assignees'] ?? $row['assignee'] ?? [];
        $bookingId = (int) ($row['booking_id'] ?? 0);
        $rows[] = [
            'id' => $id,
            'list_id' => (int) ($row['list_id'] ?? 0) ?: 1,
            'title' => (string) ($row['title'] ?? ''),
            'customer_name' => jeab_migrate_text(isset($row['customer_name']) ? (string) $row['customer_name'] : null),
            'email' => jeab_migrate_text(isset($row['email']) ? (string) $row['email'] : null),
            'phone' => jeab_migrate_text(isset($row['phone']) ? (string) $row['phone'] : null),
            'address' => jeab_migrate_text(isset($row['address']) ? (string) $row['address'] : null),
            'ort' => jeab_migrate_text(isset($row['ort']) ? (string) $row['ort'] : null),
            'postal' => jeab_migrate_text(isset($row['postal']) ? (string) $row['postal'] : null),
            'assignee' => jeab_migrate_names_join($names),
            'assignees' => jeab_migrate_names_json($names),
            'company' => jeab_migrate_company($row['company'] ?? null),
            'person' => jeab_migrate_text(isset($row['person']) ? (string) $row['person'] : null),
            'flagged' => !empty($row['flagged']) ? 1 : 0,
            'tags' => jeab_migrate_tags_json($row['tags'] ?? []),
            'note' => jeab_migrate_text(isset($row['note']) ? (string) $row['note'] : null),
            'due_at' => jeab_migrate_text(isset($row['due_at']) ? (string) $row['due_at'] : null),
            'completed_at' => jeab_migrate_text(isset($row['completed_at']) ? (string) $row['completed_at'] : null),
            'booking_id' => $bookingId > 0 ? $bookingId : null,
            'contact_id' => jeab_migrate_text(isset($row['contact_id']) ? (string) $row['contact_id'] : null),
            'thread_id' => jeab_migrate_text(isset($row['thread_id']) ? (string) $row['thread_id'] : null),
            'created_at' => jeab_migrate_text(isset($row['created_at']) ? (string) $row['created_at'] : null) ?? $stamp,
            'updated_at' => jeab_migrate_text(isset($row['updated_at']) ? (string) $row['updated_at'] : null) ?? $stamp,
            'ringt' => max(0, (int) ($row['ringt'] ?? 0)),
            'pratat' => max(0, (int) ($row['pratat'] ?? 0)),
            'mailat' => max(0, (int) ($row['mailat'] ?? 0)),
            'smsat' => max(0, (int) ($row['smsat'] ?? 0)),
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_reminders_module()['columns']);
}

function jeab_migrate_reminders_checksum(array $rows): string
{
    return jeab_migrate_checksum(jeab_migrate_reminders_rows($rows));
}
