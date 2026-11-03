<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/graph-daily.json → graph_daily.
 */

function jeab_migrate_graph_daily_module(): array
{
    $schema = jeab_migrate_doc_schema('graph_daily', 'doc_key', 'VARCHAR(64)', 'TEXT');
    return jeab_migrate_declare(
        'graph_daily',
        'graph_daily',
        'data/inkorg/graph-daily.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_graph_daily_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'graph_daily', jeab_migrate_graph_daily_module()['columns']);
}

function jeab_migrate_graph_daily_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_graph_daily_rows($source));
}
