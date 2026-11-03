<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/corrections.jsonl → inkorg_corrections. Radnyckel är hash så importen är idempotent.
 */

function jeab_migrate_corrections_module(): array
{
    return jeab_migrate_declare(
        'corrections',
        'inkorg_corrections',
        'data/inkorg/corrections.jsonl',
        'line_hash',
        ['line_hash', 'line'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_corrections (
    line_hash CHAR(64) NOT NULL,
    line LONGTEXT NOT NULL,
    PRIMARY KEY (line_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inkorg_corrections (
    line_hash TEXT PRIMARY KEY,
    line TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_corrections_rows(array $source): array
{
    $lines = $source['lines'] ?? $source;
    if (is_string($lines)) {
        $lines = preg_split("/\r\n|\n|\r/", $lines) ?: [];
    }
    if (!is_array($lines)) {
        return [];
    }
    $rows = [];
    foreach ($lines as $line) {
        if (is_array($line)) {
            $payload = jeab_migrate_payload($line);
        } else {
            $text = trim((string) $line);
            if ($text === '') {
                continue;
            }
            $decoded = json_decode($text, true);
            $payload = is_array($decoded) ? jeab_migrate_payload($decoded) : (string) jeab_migrate_redact($text);
        }
        if ($payload === '' || $payload === '{}') {
            continue;
        }
        $rows[] = ['line_hash' => hash('sha256', $payload), 'line' => $payload];
    }
    return jeab_migrate_project($rows, 'line_hash', jeab_migrate_corrections_module()['columns']);
}

function jeab_migrate_corrections_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_corrections_rows($source));
}
