<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/fellogg-open-asks.json → log_docs.fellogg_open_asks.
 */

function jeab_migrate_fellogg_open_asks_module(): array
{
    $schema = jeab_migrate_doc_schema('log_docs', 'doc_key', 'VARCHAR(64)', 'TEXT');
    return jeab_migrate_declare(
        'fellogg_open_asks',
        'log_docs',
        'data/fellogg-open-asks.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_fellogg_open_asks_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'fellogg_open_asks', jeab_migrate_fellogg_open_asks_module()['columns']);
}

function jeab_migrate_fellogg_open_asks_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_fellogg_open_asks_rows($source));
}
