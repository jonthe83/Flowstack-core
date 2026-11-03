<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/graph-poll.json → graph_poll.
 */

function jeab_migrate_graph_poll_module(): array
{
    $schema = jeab_migrate_doc_schema('graph_poll', 'doc_key', 'VARCHAR(64)', 'TEXT');
    return jeab_migrate_declare(
        'graph_poll',
        'graph_poll',
        'data/inkorg/graph-poll.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_graph_poll_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'graph_poll', jeab_migrate_graph_poll_module()['columns']);
}

function jeab_migrate_graph_poll_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_graph_poll_rows($source));
}
