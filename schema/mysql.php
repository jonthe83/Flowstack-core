<?php

declare(strict_types=1);

if (!defined('JEAB_ROOT')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

return <<<'SQL'
-- JEAB Bokning — MySQL (Loopia senare). Kör i phpMyAdmin / Kundzon.
-- Byt sedan driver till mysql i data/config.php.

CREATE TABLE IF NOT EXISTS reminder_lists (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY customers_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    job_type VARCHAR(64) DEFAULT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'booked',
    contact_id VARCHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY bookings_starts_at (starts_at),
    KEY bookings_status (status),
    KEY bookings_assignee (assignee),
    CONSTRAINT bookings_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    list_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    customer_name VARCHAR(191) DEFAULT NULL,
    email VARCHAR(191) DEFAULT NULL,
    phone VARCHAR(64) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    assignee VARCHAR(191) DEFAULT NULL,
    assignees TEXT,
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
    PRIMARY KEY (id),
    KEY reminders_list_id (list_id),
    KEY reminders_due_at (due_at),
    KEY reminders_completed_at (completed_at),
    CONSTRAINT reminders_list_fk FOREIGN KEY (list_id) REFERENCES reminder_lists (id),
    CONSTRAINT reminders_booking_fk FOREIGN KEY (booking_id) REFERENCES bookings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(64) NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reminder_lists (id, name, created_at)
SELECT 1, 'Påminnelser', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM reminder_lists LIMIT 1);

CREATE TABLE IF NOT EXISTS purchases (
    reminder_id INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (reminder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS json_docs (
    doc_key VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_owner_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    reminder_id INT NOT NULL DEFAULT 0,
    owner VARCHAR(191) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_outlook_events (
    booking_id INT UNSIGNED NOT NULL,
    resource VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL,
    graph_event_id TEXT NOT NULL,
    show_as VARCHAR(32) NOT NULL DEFAULT 'busy',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (booking_id, resource),
    KEY booking_outlook_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_resource_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    booking_id INT UNSIGNED NOT NULL,
    resources TEXT NOT NULL,
    dry TINYINT(1) NOT NULL DEFAULT 0,
    changed TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY booking_resource_audit_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outlook_sync_state (
    email VARCHAR(191) NOT NULL,
    delta_link TEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_email_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    thread_id VARCHAR(64) NOT NULL DEFAULT '',
    reminder_id INT NOT NULL DEFAULT 0,
    old_thread VARCHAR(191) DEFAULT NULL,
    old_reminder VARCHAR(191) DEFAULT NULL,
    old_inbox TEXT,
    old_kontakt TEXT,
    email VARCHAR(191) NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_webshop_orders (
    order_key VARCHAR(191) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (order_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_docs (
    doc_key VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notis_switches (
    notis_id VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(191) NOT NULL DEFAULT '',
    PRIMARY KEY (notis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS send_once (
    idem_key CHAR(64) NOT NULL,
    bucket VARCHAR(32) NOT NULL,
    claim CHAR(16) NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    result MEDIUMTEXT NOT NULL,
    PRIMARY KEY (idem_key),
    KEY send_once_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS graph_hook_queue (
    idem_key CHAR(64) NOT NULL,
    company VARCHAR(32) NOT NULL,
    message_id TEXT NOT NULL,
    queued_at DATETIME NOT NULL,
    PRIMARY KEY (idem_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inbox_link_contacts_undo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    undo_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    thread_id VARCHAR(191) NOT NULL,
    tbl VARCHAR(64) NOT NULL,
    old_contact_id VARCHAR(191) NOT NULL DEFAULT '',
    new_contact_id VARCHAR(191) NOT NULL DEFAULT '',
    action VARCHAR(16) NOT NULL DEFAULT 'link',
    old_value TEXT NULL,
    undone_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY inbox_link_contacts_undo_undo_id (undo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts_merge_undo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    undo_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    kind VARCHAR(32) NOT NULL,
    tbl VARCHAR(64) NOT NULL,
    row_key VARCHAR(191) NOT NULL DEFAULT '',
    col VARCHAR(64) NOT NULL DEFAULT '',
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    undone_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY contacts_merge_undo_undo_id (undo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_delete_undo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    undo_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    booking_id INT UNSIGNED NOT NULL,
    booking_json LONGTEXT NOT NULL,
    calendar_log_json LONGTEXT NULL,
    undone_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY booking_delete_undo_undo_id (undo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_outbox (
    idem_key VARCHAR(255) NOT NULL,
    thread_id VARCHAR(191) NOT NULL,
    product VARCHAR(32) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    next_attempt_at INT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(191) NOT NULL DEFAULT '',
    queued_at INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    manual_send TINYINT(1) NOT NULL DEFAULT 0,
    guard_job INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (idem_key),
    KEY quote_outbox_thread (thread_id),
    KEY quote_outbox_due (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_outbox_event (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL,
    thread_id VARCHAR(191) NOT NULL,
    product VARCHAR(32) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL,
    reason VARCHAR(191) NOT NULL DEFAULT '',
    attempts INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY quote_outbox_event_thread (thread_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quote_outbox_manual_undo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    undo_id VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    idem_key VARCHAR(255) NOT NULL,
    thread_id VARCHAR(191) NOT NULL,
    manual_send TINYINT(1) NOT NULL DEFAULT 0,
    undone_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY quote_outbox_manual_undo_undo_id (undo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
