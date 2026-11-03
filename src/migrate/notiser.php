<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/notiser/mallar.json → notiser_items. items och pdf, utan att röra git-mallar.
 */

function jeab_migrate_notiser_module(): array
{
    return jeab_migrate_declare(
        'notiser',
        'notiser_items',
        'data/notiser/mallar.json',
        'item_key',
        ['item_key', 'slot', 'item_id', 'body', 'previous', 'updated_by', 'updated_at', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notiser_items (
    item_key VARCHAR(191) NOT NULL,
    slot VARCHAR(16) NOT NULL,
    item_id VARCHAR(64) NOT NULL,
    body LONGTEXT,
    previous LONGTEXT,
    updated_by VARCHAR(191) DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notiser_items (
    item_key TEXT PRIMARY KEY,
    slot TEXT NOT NULL,
    item_id TEXT NOT NULL,
    body TEXT,
    previous TEXT,
    updated_by TEXT,
    updated_at TEXT,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_notiser_rows(array $source): array
{
    $rows = [];
    foreach (['items' => 'item', 'pdf' => 'pdf'] as $slot => $label) {
        $map = $source[$slot] ?? [];
        if (!is_array($map)) {
            continue;
        }
        foreach ($map as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $itemId = (string) $id;
            if ($itemId === '') {
                continue;
            }
            $clean = jeab_migrate_redact($row);
            if (!is_array($clean)) {
                continue;
            }
            $rows[] = [
                'item_key' => $label . ':' . $itemId,
                'slot' => $label,
                'item_id' => $itemId,
                'body' => jeab_migrate_text(isset($clean['body']) ? (string) $clean['body'] : null),
                'previous' => jeab_migrate_text(isset($clean['previous']) ? (string) $clean['previous'] : null),
                'updated_by' => jeab_migrate_text(isset($clean['updated_by']) ? (string) $clean['updated_by'] : null),
                'updated_at' => jeab_migrate_datetime($clean['updated_at'] ?? null),
                'payload' => jeab_migrate_payload($clean),
            ];
        }
    }
    return jeab_migrate_project($rows, 'item_key', jeab_migrate_notiser_module()['columns']);
}

function jeab_migrate_notiser_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_notiser_rows($source));
}
