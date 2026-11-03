<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/threads.json → inkorg_threads.
 */

function jeab_migrate_inbox_threads_module(): array
{
    return jeab_migrate_module(
        'inbox_threads',
        'inkorg_threads',
        'data/inkorg/threads.json',
        'id',
        ['id', 'status', 'updated_at', 'row_json'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_threads (
    id VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'open',
    updated_at DATETIME DEFAULT NULL,
    row_json LONGTEXT NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_threads (
    id TEXT PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'open',
    updated_at TEXT,
    row_json TEXT NOT NULL
)
SQL,
        ['inbox']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_inbox_threads_rows(array $source): array
{
    $rows = [];
    foreach (jeab_migrate_list($source, 'threads') as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'id' => $id,
            'status' => jeab_migrate_text(isset($row['status']) ? (string) $row['status'] : null) ?? 'open',
            'updated_at' => jeab_migrate_text(isset($row['updated_at']) ? (string) $row['updated_at'] : null),
            'row_json' => is_string($json) ? $json : '{}',
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_inbox_threads_module()['columns']);
}

function jeab_migrate_inbox_threads_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inbox_threads_rows($source));
}
