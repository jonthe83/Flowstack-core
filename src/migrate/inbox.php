<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/messages.json → inkorg_messages.
 * Anteckningar ligger i samma fil och samma tabell.
 */

function jeab_migrate_inbox_module(): array
{
    return jeab_migrate_module(
        'inbox',
        'inkorg_messages',
        'data/inkorg/messages.json',
        'id',
        ['id', 'thread_id', 'received_at', 'from_addr', 'is_note', 'row_json'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_messages (
    id VARCHAR(191) NOT NULL,
    thread_id VARCHAR(191) NOT NULL DEFAULT '',
    received_at DATETIME DEFAULT NULL,
    from_addr VARCHAR(191) NOT NULL DEFAULT '',
    is_note TINYINT(1) NOT NULL DEFAULT 0,
    row_json LONGTEXT NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_messages (
    id TEXT PRIMARY KEY,
    thread_id TEXT NOT NULL DEFAULT '',
    received_at TEXT,
    from_addr TEXT NOT NULL DEFAULT '',
    is_note INTEGER NOT NULL DEFAULT 0,
    row_json TEXT NOT NULL
)
SQL
    );
}

function jeab_migrate_inbox_authoritative_sql(): bool
{
    return false;
}

/** @param array<string, mixed> $source @return list<array<string, mixed>> */
function jeab_migrate_inbox_collect(array $source): array
{
    $rows = [];
    if (isset($source['messages']) && is_array($source['messages'])) {
        $rows = array_merge($rows, jeab_migrate_list(['messages' => $source['messages']], 'messages'));
    }
    if (isset($source['notes']) && is_array($source['notes'])) {
        $rows = array_merge($rows, jeab_migrate_list(['notes' => $source['notes']], 'notes'));
    }
    if ($rows === [] && !isset($source['messages']) && !isset($source['notes'])) {
        $rows = jeab_migrate_list($source, 'messages');
    }
    return $rows;
}

/** @param array<string, mixed> $source */
function jeab_migrate_inbox_rows(array $source): array
{
    $rows = [];
    foreach (jeab_migrate_inbox_collect($source) as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $note = function_exists('jeab_inkorg_row_is_note')
            ? jeab_inkorg_row_is_note($row)
            : (!empty($row['internal_note']) || (string) ($row['hs_thread_type'] ?? '') === 'note');
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'id' => $id,
            'thread_id' => trim((string) ($row['thread_id'] ?? '')),
            'received_at' => jeab_migrate_text(isset($row['received_at']) ? (string) $row['received_at'] : null),
            'from_addr' => trim((string) ($row['from'] ?? $row['from_addr'] ?? '')),
            'is_note' => $note ? 1 : 0,
            'row_json' => is_string($json) ? $json : '{}',
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_inbox_module()['columns']);
}

function jeab_migrate_inbox_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inbox_rows($source));
}
