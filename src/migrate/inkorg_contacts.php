<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/contacts.json → inkorg_contacts.
 */

/**
 * Inkorgen i SQL är facit när den redan har rader. JSON får inte skriva över dem.
 */
function jeab_migrate_inkorg_contacts_preserve_sql(): bool
{
    return true;
}

function jeab_migrate_inkorg_contacts_module(): array
{
    return jeab_migrate_module(
        'inkorg_contacts',
        'inkorg_contacts',
        'data/inkorg/contacts.json',
        'id',
        ['id', 'row_json', 'email', 'mobile', 'updated_at'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_contacts (
    id VARCHAR(64) NOT NULL,
    row_json LONGTEXT NOT NULL,
    email VARCHAR(191) NOT NULL DEFAULT '',
    mobile VARCHAR(32) NOT NULL DEFAULT '',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_contacts (
    id TEXT PRIMARY KEY,
    row_json TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    mobile TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
)
SQL,
        ['contacts']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_inkorg_contacts_rows(array $source): array
{
    $rows = [];
    $stamp = '1970-01-01 00:00:00';
    foreach (jeab_migrate_list($source, 'contacts') as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $index = function_exists('jeab_kontakt_inbox_index_fields')
            ? jeab_kontakt_inbox_index_fields($row)
            : ['email' => '', 'mobile' => ''];
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'id' => $id,
            'row_json' => is_string($json) ? $json : '{}',
            'email' => (string) ($index['email'] ?? ''),
            'mobile' => (string) ($index['mobile'] ?? ''),
            'updated_at' => jeab_migrate_text(isset($row['updated_at']) ? (string) $row['updated_at'] : null) ?? $stamp,
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_inkorg_contacts_module()['columns']);
}

function jeab_migrate_inkorg_contacts_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inkorg_contacts_rows($source));
}
