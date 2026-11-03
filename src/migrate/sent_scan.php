<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/graph-sent-scan.json → sent_scan. Utskicksskann per person som dokument.
 */

function jeab_migrate_sent_scan_module(): array
{
    $schema = jeab_migrate_doc_schema('sent_scan', 'doc_key', 'VARCHAR(64)', 'TEXT');
    return jeab_migrate_declare(
        'sent_scan',
        'sent_scan',
        'data/graph-sent-scan.json',
        'doc_key',
        ['doc_key', 'payload', 'updated_at'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_sent_scan_rows(array $source): array
{
    return jeab_migrate_doc_rows($source, 'doc_key', 'sent_scan', jeab_migrate_sent_scan_module()['columns']);
}

function jeab_migrate_sent_scan_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_sent_scan_rows($source));
}
