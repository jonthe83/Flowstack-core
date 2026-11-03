<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/classify-pending.json → classify_pending. Ett dokument.
 */

function jeab_migrate_classify_pending_module(): array
{
    $schema = jeab_migrate_doc_schema('classify_pending', 'doc_key', 'VARCHAR(64)', 'TEXT');

    return jeab_migrate_declare(
        'classify_pending',
        'classify_pending',
        'data/inkorg/classify-pending.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_classify_pending_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'classify_pending', jeab_migrate_classify_pending_module()['columns']);
}

function jeab_migrate_classify_pending_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_classify_pending_rows($source));
}
