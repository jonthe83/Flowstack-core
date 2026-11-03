<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/reminder-hook.jsonl → log_lines (Hook-audit för påminnelser).
 */

function jeab_migrate_reminder_hook_module(): array
{
    require_once __DIR__ . '/log_lines.php';
    $schema = jeab_migrate_log_lines_schema();
    return jeab_migrate_declare(
        'reminder_hook',
        'log_lines',
        'data/inkorg/reminder-hook.jsonl',
        'line_key',
        ['line_key', 'kind', 'line_hash', 'at', 'payload'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_reminder_hook_rows(array $source): array
{
    require_once __DIR__ . '/log_lines.php';
    return jeab_migrate_log_lines_rows($source, 'reminder_hook');
}

function jeab_migrate_reminder_hook_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_reminder_hook_rows($source));
}
