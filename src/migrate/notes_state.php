<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/notes.json → notes_state. En rad med nästa id.
 */

function jeab_migrate_notes_state_module(): array
{
    return jeab_migrate_declare(
        'notes_state',
        'notes_state',
        'data/notes.json',
        'id',
        ['id', 'next_id', 'imported'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notes_state (
    id TINYINT UNSIGNED NOT NULL,
    next_id INT UNSIGNED NOT NULL,
    imported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notes_state (
    id INTEGER PRIMARY KEY,
    next_id INTEGER NOT NULL,
    imported INTEGER NOT NULL DEFAULT 0
)
SQL,
        ['notes']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_notes_state_rows(array $source): array
{
    $next = (int) ($source['next_id'] ?? 1);
    $max = 0;
    foreach (jeab_migrate_list($source, 'notes') as $row) {
        $max = max($max, (int) ($row['id'] ?? 0));
    }
    if ($max >= $next) {
        $next = $max + 1;
    }
    if ($next <= 0) {
        $next = 1;
    }
    return jeab_migrate_project([[
        'id' => 1,
        'next_id' => $next,
        'imported' => 0,
    ]], 'id', jeab_migrate_notes_state_module()['columns']);
}

function jeab_migrate_notes_state_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_notes_state_rows($source));
}
