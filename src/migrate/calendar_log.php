<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/calendar-log.json → log_docs.doc_key = calendar_log.
 * Ingen flagga, inget hook. Live MySQL skriver redan samma rad.
 */

function jeab_migrate_calendar_log_module(): array
{
    return jeab_migrate_module(
        'calendar_log',
        'log_docs',
        'data/calendar-log.json',
        'doc_key',
        ['doc_key', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_docs (
    doc_key VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_docs (
    doc_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_calendar_log_canon(array $source): array
{
    $entries = is_array($source['entries'] ?? null) ? $source['entries'] : [];
    $clean = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $id = trim((string) ($entry['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $clean[$id] = jeab_migrate_sort($entry);
    }
    ksort($clean, SORT_STRING);
    return [
        'entries' => array_values($clean),
        'backfilled' => !empty($source['backfilled']),
    ];
}

/** @param array<string, mixed> $source */
function jeab_migrate_calendar_log_rows(array $source): array
{
    $payload = json_encode(jeab_migrate_calendar_log_canon($source), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [[
        'doc_key' => 'calendar_log',
        'payload' => is_string($payload) ? $payload : '{}',
    ]];
}

function jeab_migrate_calendar_log_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_calendar_log_canon($source));
}
