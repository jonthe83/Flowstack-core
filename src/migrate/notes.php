<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/notes.json → notes. Payload är kanonisk anteckning. next_id ligger i notes_state.
 */

function jeab_migrate_notes_module(): array
{
    return jeab_migrate_declare(
        'notes',
        'notes',
        'data/notes.json',
        'id',
        ['id', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notes (
    id INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS notes (
    id INTEGER PRIMARY KEY,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_notes_rows(array $source): array
{
    $rows = [];
    foreach (jeab_migrate_list($source, 'notes') as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $row['id'] = $id;
        $rows[] = ['id' => $id, 'payload' => jeab_migrate_payload($row)];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_notes_module()['columns']);
}

function jeab_migrate_notes_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_notes_rows($source));
}
