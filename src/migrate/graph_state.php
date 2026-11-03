<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/graph-state.json → graph_state. Prenumerationer och delta som ett dokument.
 */

function jeab_migrate_graph_state_module(): array
{
    $schema = jeab_migrate_doc_schema('graph_state', 'doc_key', 'VARCHAR(64)', 'TEXT');
    return jeab_migrate_declare(
        'graph_state',
        'graph_state',
        'data/inkorg/graph-state.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_graph_state_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'graph_state', jeab_migrate_graph_state_module()['columns']);
}

function jeab_migrate_graph_state_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_graph_state_rows($source));
}
