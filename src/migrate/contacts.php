<?php

declare(strict_types=1);

require_once __DIR__ . '/../shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/canon.php';

/**
 * data/kontakter.json → contacts.
 * jeab_migrate_contacts_module / _rows / _checksum.
 * Läsning och skrivning går via kontaktlagret.
 */

function jeab_migrate_contacts_module(): array
{
    return jeab_migrate_module(
        'contacts',
        'contacts',
        'data/kontakter.json',
        'id',
        ['id', 'row_json', 'street_key', 'postal_code', 'owner_email', 'owner_phone', 'owner_name_fold', 'created_at', 'updated_at'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS contacts (
    id VARCHAR(32) NOT NULL,
    row_json LONGTEXT NOT NULL,
    street_key VARCHAR(191) NOT NULL DEFAULT '',
    postal_code VARCHAR(16) NOT NULL DEFAULT '',
    owner_email VARCHAR(191) NOT NULL DEFAULT '',
    owner_phone VARCHAR(32) NOT NULL DEFAULT '',
    owner_name_fold VARCHAR(191) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS contacts (
    id TEXT PRIMARY KEY,
    row_json TEXT NOT NULL,
    street_key TEXT NOT NULL DEFAULT '',
    postal_code TEXT NOT NULL DEFAULT '',
    owner_email TEXT NOT NULL DEFAULT '',
    owner_phone TEXT NOT NULL DEFAULT '',
    owner_name_fold TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL
    );
}

/**
 * Tom SQL fylls fortfarande från JSON. När tabellen redan har rader är SQL
 * facit för alla typer i jeab_migrate_copy: saknade id läggs till, inget
 * skrivs över och inget raderas. preserve_sql finns kvar som markör.
 */
function jeab_migrate_contacts_authoritative_sql(): bool
{
    return false;
}

function jeab_migrate_contacts_preserve_sql(): bool
{
    return true;
}

/** @param array<string, mixed> $source */
function jeab_migrate_contacts_rows(array $source): array
{
    $rows = [];
    $stamp = '1970-01-01 00:00:00';
    foreach (jeab_migrate_list($source, 'contacts') as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $index = function_exists('jeab_contact_store_index_fields')
            ? jeab_contact_store_index_fields($row)
            : ['street_key' => '', 'postal_code' => '', 'owner_email' => '', 'owner_phone' => '', 'owner_name_fold' => ''];
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'id' => $id,
            'row_json' => is_string($json) ? $json : '{}',
            'street_key' => (string) ($index['street_key'] ?? ''),
            'postal_code' => (string) ($index['postal_code'] ?? ''),
            'owner_email' => (string) ($index['owner_email'] ?? ''),
            'owner_phone' => (string) ($index['owner_phone'] ?? ''),
            'owner_name_fold' => (string) ($index['owner_name_fold'] ?? ''),
            'created_at' => jeab_migrate_text(isset($row['created_at']) ? (string) $row['created_at'] : null) ?? $stamp,
            'updated_at' => jeab_migrate_text(isset($row['updated_at']) ? (string) $row['updated_at'] : null) ?? $stamp,
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_contacts_module()['columns']);
}

function jeab_migrate_contacts_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_contacts_rows($source));
}

/** @return array<string, array<string, mixed>> */
function jeab_migrate_contacts_read(): array
{
    return function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
}

/** @param array<string, mixed> $rows */
function jeab_migrate_contacts_write(array $rows): void
{
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    if (!function_exists('jeab_kontakter_mutate')) {
        throw new RuntimeException('Kontaktlagret saknas.');
    }
    jeab_kontakter_mutate(static function (array &$contacts) use ($rows): void {
        $contacts = $rows;
    });
}
