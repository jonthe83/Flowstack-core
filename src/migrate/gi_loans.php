<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/gi/loans.json → json_docs.doc_key = gi_loans.
 * Live MySQL är redan källan och hoppas över.
 */

function jeab_migrate_gi_loans_module(): array
{
    return jeab_migrate_module(
        'gi_loans',
        'json_docs',
        'data/gi/loans.json',
        'doc_key',
        ['doc_key', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS json_docs (
    doc_key VARCHAR(64) NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (doc_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS json_docs (
    doc_key TEXT PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL
    );
}

function jeab_migrate_gi_loans_authoritative_sql(): bool
{
    return function_exists('jeab_gi_loans_mysql_live') && jeab_gi_loans_mysql_live();
}

/** @param array<string, mixed> $source */
function jeab_migrate_gi_loans_rows(array $source): array
{
    $doc = isset($source['loans']) && is_array($source['loans']) ? ['loans' => array_values($source['loans'])] : null;
    if ($doc === null && $source === [] && function_exists('jeab_gi_loans_json_read')) {
        $read = jeab_gi_loans_json_read();
        $doc = ['loans' => array_values(is_array($read['loans'] ?? null) ? $read['loans'] : [])];
    }
    if ($doc === null) {
        $doc = ['loans' => []];
    }
    $payload = json_encode(jeab_migrate_sort($doc), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [[
        'doc_key' => 'gi_loans',
        'payload' => is_string($payload) ? $payload : '{"loans":[]}',
    ]];
}

function jeab_migrate_gi_loans_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_gi_loans_rows($source));
}
