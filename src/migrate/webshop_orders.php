<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/webshop-orders.json → log_webshop_orders.
 * Ingen flagga, inget hook. Live MySQL skriver redan samma tabell.
 */

function jeab_migrate_webshop_orders_module(): array
{
    return jeab_migrate_module(
        'webshop_orders',
        'log_webshop_orders',
        'data/webshop-orders.json',
        'order_key',
        ['order_key', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_webshop_orders (
    order_key VARCHAR(191) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (order_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_webshop_orders (
    order_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_webshop_orders_rows(array $source): array
{
    $rows = [];
    foreach ($source as $key => $row) {
        if (!is_string($key) || $key === '' || !is_array($row)) {
            continue;
        }
        $payload = json_encode(jeab_migrate_sort($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'order_key' => $key,
            'payload' => is_string($payload) ? $payload : '{}',
        ];
    }
    return jeab_migrate_project($rows, 'order_key', jeab_migrate_webshop_orders_module()['columns']);
}

function jeab_migrate_webshop_orders_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_webshop_orders_rows($source));
}
