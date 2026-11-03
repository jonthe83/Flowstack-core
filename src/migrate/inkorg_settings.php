<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/settings.json → inkorg_side_doc (Inkorgsinställningar.)
 * Dokumentet är samma array som filen. Vyer ur rules.json är inkorg_views.
 */

function jeab_migrate_inkorg_settings_module(): array
{
    return jeab_migrate_declare(
        'inkorg_settings',
        'inkorg_side_doc',
        'data/inkorg/settings.json',
        'name',
        ['name', 'payload', 'imported'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_side_doc (
    name VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    imported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_side_doc (
    name TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    imported INTEGER NOT NULL DEFAULT 0
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_inkorg_settings_rows(array $source): array
{
    return jeab_migrate_project([[
        'name' => 'settings.json',
        'payload' => jeab_migrate_payload($source),
        'imported' => 0,
    ]], 'name', jeab_migrate_inkorg_settings_module()['columns']);
}

function jeab_migrate_inkorg_settings_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inkorg_settings_rows($source));
}
