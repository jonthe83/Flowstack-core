<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/who-diag.jsonl → log_lines (Who-diagnostik).
 */

function jeab_migrate_who_diag_module(): array
{
    require_once __DIR__ . '/log_lines.php';
    $schema = jeab_migrate_log_lines_schema();
    return jeab_migrate_declare(
        'who_diag',
        'log_lines',
        'data/who-diag.jsonl',
        'line_key',
        ['line_key', 'kind', 'line_hash', 'at', 'payload'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_who_diag_rows(array $source): array
{
    require_once __DIR__ . '/log_lines.php';
    return jeab_migrate_log_lines_rows($source, 'who_diag');
}

function jeab_migrate_who_diag_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_who_diag_rows($source));
}
