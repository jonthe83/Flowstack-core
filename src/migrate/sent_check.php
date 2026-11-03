<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/graph-sent-check.json → sent_check. Ett dokument.
 */

function jeab_migrate_sent_check_module(): array
{
    $schema = jeab_migrate_doc_schema('sent_check', 'doc_key', 'VARCHAR(64)', 'TEXT');

    return jeab_migrate_declare(
        'sent_check',
        'sent_check',
        'data/graph-sent-check.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_sent_check_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'sent_check', jeab_migrate_sent_check_module()['columns']);
}

function jeab_migrate_sent_check_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_sent_check_rows($source));
}
