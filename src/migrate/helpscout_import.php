<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/helpscout-import.json är checkpointen, en rad id=1.
 * Tabellen är id/imported/checkpoint (hs_import_store), inte doc_key/payload.
 * Historik (~20 000) ligger i jeab_hs_import_index plus stängda rader i inkorg_list,
 * inte i den här checkpoint-raden och inte i inkorg_messages.
 */

function jeab_migrate_helpscout_import_module(): array
{
    return jeab_migrate_declare(
        'helpscout_import',
        'jeab_hs_import_state',
        'data/inkorg/helpscout-import.json',
        'id',
        ['id', 'imported', 'checkpoint'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS jeab_hs_import_state (
    id TINYINT UNSIGNED NOT NULL,
    imported TINYINT(1) NOT NULL DEFAULT 0,
    checkpoint LONGTEXT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS jeab_hs_import_state (
    id INTEGER PRIMARY KEY,
    imported INTEGER NOT NULL DEFAULT 0,
    checkpoint TEXT
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_helpscout_import_rows(array $source): array
{
    $body = $source;
    unset($body['updated_at']);
    $checkpoint = function_exists('jeab_migrate_payload') ? jeab_migrate_payload($body) : '{}';

    return jeab_migrate_project([[
        'id' => 1,
        'imported' => $body === [] ? 0 : 1,
        'checkpoint' => $checkpoint,
    ]], 'id', jeab_migrate_helpscout_import_module()['columns']);
}

function jeab_migrate_helpscout_import_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_helpscout_import_rows($source));
}
