<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/reminder-follow.jsonl → log_lines (Påminnelse-follow).
 */

function jeab_migrate_reminder_follow_module(): array
{
    require_once __DIR__ . '/log_lines.php';
    $schema = jeab_migrate_log_lines_schema();
    return jeab_migrate_declare(
        'reminder_follow',
        'log_lines',
        'data/inkorg/reminder-follow.jsonl',
        'line_key',
        ['line_key', 'kind', 'line_hash', 'at', 'payload'],
        $schema['mysql'],
        $schema['sqlite']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_reminder_follow_rows(array $source): array
{
    require_once __DIR__ . '/log_lines.php';
    return jeab_migrate_log_lines_rows($source, 'reminder_follow');
}

function jeab_migrate_reminder_follow_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_reminder_follow_rows($source));
}
