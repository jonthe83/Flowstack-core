<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/malmo-hs-sync.json → malmo_hs_cursor.
 */

function jeab_migrate_malmo_hs_cursor_module(): array
{
    return jeab_migrate_declare(
        'malmo_hs_cursor',
        'malmo_hs_cursor',
        'data/inkorg/malmo-hs-sync.json',
        'id',
        ['id', 'watermark', 'page', 'cursor_offset', 'query'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS malmo_hs_cursor (
    id TINYINT UNSIGNED NOT NULL,
    watermark VARCHAR(40) NOT NULL DEFAULT '',
    page INT UNSIGNED NOT NULL DEFAULT 1,
    cursor_offset INT UNSIGNED NOT NULL DEFAULT 0,
    query VARCHAR(80) NOT NULL DEFAULT '',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS malmo_hs_cursor (
    id INTEGER PRIMARY KEY,
    watermark TEXT NOT NULL DEFAULT '',
    page INTEGER NOT NULL DEFAULT 1,
    cursor_offset INTEGER NOT NULL DEFAULT 0,
    query TEXT NOT NULL DEFAULT ''
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_malmo_hs_cursor_rows(array $source): array
{
    return jeab_migrate_project([[
        'id' => 1,
        'watermark' => (string) ($source['watermark'] ?? ''),
        'page' => max(1, (int) ($source['page'] ?? 1)),
        'cursor_offset' => max(0, (int) ($source['offset'] ?? $source['cursor_offset'] ?? 0)),
        'query' => (string) ($source['query'] ?? ''),
    ]], 'id', jeab_migrate_malmo_hs_cursor_module()['columns']);
}

function jeab_migrate_malmo_hs_cursor_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_malmo_hs_cursor_rows($source));
}
