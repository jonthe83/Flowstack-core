<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/store.json → customers. Ingen flagga, inget hook.
 * jeab_migrate_customers_module / _rows / _checksum.
 */

function jeab_migrate_customers_module(): array
{
    return jeab_migrate_module(
        'customers',
        'customers',
        'data/store.json',
        'id',
        ['id', 'name', 'created_at'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY customers_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source store.json eller en lista */
function jeab_migrate_customers_rows(array $source): array
{
    $list = isset($source['customers']) && is_array($source['customers']) ? $source['customers'] : $source;
    $rows = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = jeab_migrate_text(isset($row['name']) ? (string) $row['name'] : null);
        if ($id <= 0 || $name === null) {
            continue;
        }
        $rows[] = [
            'id' => $id,
            'name' => $name,
            'created_at' => jeab_migrate_text(isset($row['created_at']) ? (string) $row['created_at'] : null) ?? '1970-01-01 00:00:00',
        ];
    }
    return jeab_migrate_project($rows, 'id', jeab_migrate_customers_module()['columns']);
}

function jeab_migrate_customers_checksum(array $rows): string
{
    return jeab_migrate_checksum(jeab_migrate_customers_rows($rows));
}
