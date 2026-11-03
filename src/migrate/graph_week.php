<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/graph-week-cache.json → log_graph_week.
 */

function jeab_migrate_graph_week_module(): array
{
    return jeab_migrate_declare(
        'graph_week',
        'log_graph_week',
        'data/graph-week-cache.json',
        'cache_key',
        ['cache_key', 'cached_at', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_graph_week (
    cache_key VARCHAR(255) NOT NULL,
    cached_at INT NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_graph_week (
    cache_key TEXT PRIMARY KEY,
    cached_at INTEGER NOT NULL,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_graph_week_rows(array $source): array
{
    $map = $source['weeks'] ?? $source;
    $rows = [];
    foreach ($map as $key => $row) {
        if (!is_string($key) || $key === 'weeks' || !is_array($row)) {
            continue;
        }
        $events = $row['events'] ?? $row;
        $rows[] = [
            'cache_key' => $key,
            'cached_at' => (int) ($row['at'] ?? $row['cached_at'] ?? 0),
            'payload' => jeab_migrate_payload($events),
        ];
    }
    return jeab_migrate_project($rows, 'cache_key', jeab_migrate_graph_week_module()['columns']);
}

function jeab_migrate_graph_week_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_graph_week_rows($source));
}
