<?php

declare(strict_types=1);

require_once __DIR__ . '/../shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Ersätts av src/migrate/purchases.php (jeab_migrate_purchases_rows).
 * Registret anropar purchases, inte orders.
 * Grupp B. Ersätt kroppen om gränssnittet i README behålls.
 *
 * @return array<string, mixed>
 */
function jeab_migrate_orders_module(): array
{
    return [
        'type' => 'orders',
        'group' => 'B',
        'title' => 'Ordrar',
        'tables' => ['purchases'],
        'json' => ['data/purchases.json'],
        'authoritative_sql' => 'jeab_migrate_orders_authoritative_sql',
        'migrate' => 'jeab_migrate_orders_migrate',
        'verify' => 'jeab_migrate_orders_verify',
    ];
}

function jeab_migrate_orders_authoritative_sql(): bool
{
    return function_exists('jeab_purchases_mysql_live') && jeab_purchases_mysql_live();
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_migrate_orders_migrate(array $input): array
{
    unset($input);

    return jeab_migrate_retired_shape('orders');
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_migrate_orders_verify(array $input): array
{
    unset($input);

    return jeab_migrate_retired_shape('orders');
}

/** @return array<string, mixed> */
function jeab_migrate_orders_already(): array
{
    $count = 0;
    if (function_exists('jeab_purchase_all')) {
        $count = count(jeab_purchase_all());
    }
    return jeab_migrate_shape('orders', [
        'ok' => true,
        'dry' => true,
        'match' => true,
        'skipped' => true,
        'json_count' => $count,
        'sql_count' => $count,
    ]);
}
