<?php

declare(strict_types=1);

if (!defined('JEAB_ROOT')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

return <<<'SQL'
-- JEAB Bokning — SQLite (v1-standard). Körs automatiskt vid första sidladdning.

CREATE TABLE IF NOT EXISTS reminder_lists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS customers_name_unique
    ON customers (name);

CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    customer_name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    address TEXT,
    notes TEXT,
    assignee TEXT NOT NULL,
    assignees TEXT,
    job_type TEXT,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'booked',
    contact_id TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE INDEX IF NOT EXISTS bookings_starts_at ON bookings (starts_at);
CREATE INDEX IF NOT EXISTS bookings_status ON bookings (status);
CREATE INDEX IF NOT EXISTS bookings_assignee ON bookings (assignee);

CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    list_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    customer_name TEXT,
    email TEXT,
    phone TEXT,
    address TEXT,
    assignee TEXT,
    assignees TEXT,
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
    FOREIGN KEY (list_id) REFERENCES reminder_lists(id),
    FOREIGN KEY (booking_id) REFERENCES bookings(id)
);

CREATE INDEX IF NOT EXISTS reminders_list_id ON reminders (list_id);
CREATE INDEX IF NOT EXISTS reminders_due_at ON reminders (due_at);
CREATE INDEX IF NOT EXISTS reminders_completed_at ON reminders (completed_at);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO reminder_lists (id, name, created_at)
    SELECT 1, 'Påminnelser', datetime('now')
    WHERE NOT EXISTS (SELECT 1 FROM reminder_lists LIMIT 1);

CREATE TABLE IF NOT EXISTS purchases (
    reminder_id INTEGER PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS json_docs (
    doc_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS order_owner_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    reminder_id INTEGER NOT NULL DEFAULT 0,
    owner TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS booking_outlook_events (
    booking_id INTEGER NOT NULL,
    resource TEXT NOT NULL,
    email TEXT NOT NULL,
    graph_event_id TEXT NOT NULL,
    show_as TEXT NOT NULL DEFAULT 'busy',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (booking_id, resource)
);

CREATE INDEX IF NOT EXISTS booking_outlook_email ON booking_outlook_events (email);

CREATE TABLE IF NOT EXISTS booking_resource_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    booking_id INTEGER NOT NULL,
    resources TEXT NOT NULL,
    dry INTEGER NOT NULL DEFAULT 0,
    changed INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS outlook_sync_state (
    email TEXT PRIMARY KEY,
    delta_link TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS contact_email_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    thread_id TEXT NOT NULL DEFAULT '',
    reminder_id INTEGER NOT NULL DEFAULT 0,
    old_thread TEXT,
    old_reminder TEXT,
    old_inbox TEXT,
    old_kontakt TEXT,
    email TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS log_webshop_orders (
    order_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS log_docs (
    doc_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS notis_switches (
    notis_id TEXT PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL,
    updated_by TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS send_once (
    idem_key TEXT PRIMARY KEY,
    bucket TEXT NOT NULL,
    claim TEXT NOT NULL,
    expires_at INTEGER NOT NULL,
    result TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS send_once_expires ON send_once (expires_at);

CREATE TABLE IF NOT EXISTS graph_hook_queue (
    idem_key TEXT PRIMARY KEY,
    company TEXT NOT NULL,
    message_id TEXT NOT NULL,
    queued_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS inbox_link_contacts_undo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    undo_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    thread_id TEXT NOT NULL,
    tbl TEXT NOT NULL,
    old_contact_id TEXT NOT NULL DEFAULT '',
    new_contact_id TEXT NOT NULL DEFAULT '',
    action TEXT NOT NULL DEFAULT 'link',
    old_value TEXT,
    undone_at TEXT
);

CREATE INDEX IF NOT EXISTS inbox_link_contacts_undo_undo_id
    ON inbox_link_contacts_undo (undo_id);

CREATE TABLE IF NOT EXISTS contacts_merge_undo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    undo_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    tbl TEXT NOT NULL,
    row_key TEXT NOT NULL DEFAULT '',
    col TEXT NOT NULL DEFAULT '',
    old_value TEXT,
    new_value TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    undone_at TEXT
);

CREATE INDEX IF NOT EXISTS contacts_merge_undo_undo_id
    ON contacts_merge_undo (undo_id);

CREATE TABLE IF NOT EXISTS booking_delete_undo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    undo_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    booking_id INTEGER NOT NULL,
    booking_json TEXT NOT NULL,
    calendar_log_json TEXT,
    undone_at TEXT
);

CREATE INDEX IF NOT EXISTS booking_delete_undo_undo_id
    ON booking_delete_undo (undo_id);

CREATE TABLE IF NOT EXISTS quote_outbox (
    idem_key TEXT PRIMARY KEY,
    thread_id TEXT NOT NULL,
    product TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at INTEGER NOT NULL DEFAULT 0,
    reason TEXT NOT NULL DEFAULT '',
    queued_at INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    manual_send INTEGER NOT NULL DEFAULT 0,
    guard_job INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS quote_outbox_thread ON quote_outbox (thread_id);
CREATE INDEX IF NOT EXISTS quote_outbox_due ON quote_outbox (status, next_attempt_at);

CREATE TABLE IF NOT EXISTS quote_outbox_event (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    thread_id TEXT NOT NULL,
    product TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    attempts INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS quote_outbox_event_thread ON quote_outbox_event (thread_id);

CREATE TABLE IF NOT EXISTS quote_outbox_manual_undo (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    undo_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    idem_key TEXT NOT NULL,
    thread_id TEXT NOT NULL,
    manual_send INTEGER NOT NULL DEFAULT 0,
    undone_at TEXT
);

CREATE INDEX IF NOT EXISTS quote_outbox_manual_undo_undo_id
    ON quote_outbox_manual_undo (undo_id);
SQL;
