<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkop-mallar.json → inkop_mallar. En mall per rad.
 */

function jeab_migrate_inkop_mallar_module(): array
{
    return jeab_migrate_declare(
        'inkop_mallar',
        'inkop_mallar',
        'data/inkop-mallar.json',
        'mall_id',
        ['mall_id', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkop_mallar (
    mall_id VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (mall_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkop_mallar (
    mall_id TEXT PRIMARY KEY,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_inkop_mallar_rows(array $source): array
{
    $rows = [];
    foreach ($source as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? $key));
        if ($id === '') {
            continue;
        }
        $row['id'] = $id;
        $rows[] = [
            'mall_id' => $id,
            'payload' => jeab_migrate_payload($row),
        ];
    }

    return jeab_migrate_project($rows, 'mall_id', jeab_migrate_inkop_mallar_module()['columns']);
}

function jeab_migrate_inkop_mallar_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inkop_mallar_rows($source));
}
