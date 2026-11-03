<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/quote-queue.json → quote_queue. Ett dokument.
 */

function jeab_migrate_quote_queue_module(): array
{
    $schema = jeab_migrate_doc_schema('quote_queue', 'doc_key', 'VARCHAR(64)', 'TEXT');

    return jeab_migrate_declare(
        'quote_queue',
        'quote_queue',
        'data/quote-queue.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_quote_queue_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'quote_queue', jeab_migrate_quote_queue_module()['columns']);
}

function jeab_migrate_quote_queue_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_quote_queue_rows($source));
}
