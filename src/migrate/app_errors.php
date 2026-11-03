<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/app-errors.jsonl → log_lines (Applikationsfel).
 */

function jeab_migrate_app_errors_module(): array
{
    require_once __DIR__ . '/log_lines.php';
    $schema = jeab_migrate_log_lines_schema();
    return jeab_migrate_declare(
        'app_errors',
        'log_lines',
        'data/app-errors.jsonl',
        'line_key',
        ['line_key', 'kind', 'line_hash', 'at', 'payload'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_app_errors_rows(array $source): array
{
    require_once __DIR__ . '/log_lines.php';
    return jeab_migrate_log_lines_rows($source, 'app_error');
}

function jeab_migrate_app_errors_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_app_errors_rows($source));
}
