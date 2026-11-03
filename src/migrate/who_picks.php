<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/who-picks.json → log_who_picks.
 */

function jeab_migrate_who_picks_module(): array
{
    return jeab_migrate_declare(
        'who_picks',
        'log_who_picks',
        'data/who-picks.json',
        'pick_key',
        ['pick_key', 'person', 'picked_at'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_who_picks (
    pick_key VARCHAR(191) NOT NULL,
    person VARCHAR(64) NOT NULL,
    picked_at BIGINT NOT NULL,
    PRIMARY KEY (pick_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_who_picks (
    pick_key TEXT PRIMARY KEY,
    person TEXT NOT NULL,
    picked_at INTEGER NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_who_picks_rows(array $source): array
{
    $map = $source['picks'] ?? $source;
    $rows = [];
    foreach ($map as $key => $row) {
        if (!is_string($key) || $key === 'picks' || !is_array($row)) {
            continue;
        }
        $rows[] = [
            'pick_key' => $key,
            'person' => (string) ($row['person'] ?? ''),
            'picked_at' => (int) ($row['at'] ?? $row['picked_at'] ?? 0),
        ];
    }
    return jeab_migrate_project($rows, 'pick_key', jeab_migrate_who_picks_module()['columns']);
}

function jeab_migrate_who_picks_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_who_picks_rows($source));
}
