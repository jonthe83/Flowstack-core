<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/omtago/leads.json → omtago_leads. Samma kolumner som den befintliga SQL-tabellen.
 */

function jeab_migrate_omtago_leads_module(): array
{
    return jeab_migrate_declare(
        'omtago_leads',
        'omtago_leads',
        'data/omtago/leads.json',
        'lead_id',
        ['lead_id', 'thread_id', 'received_at', 'test'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS omtago_leads (
    lead_id VARCHAR(191) NOT NULL,
    thread_id VARCHAR(191) NOT NULL,
    received_at DATETIME NOT NULL,
    test TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS omtago_leads (
    lead_id TEXT PRIMARY KEY,
    thread_id TEXT NOT NULL,
    received_at TEXT NOT NULL,
    test INTEGER NOT NULL DEFAULT 0
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_omtago_leads_rows(array $source): array
{
    $rows = [];
    foreach (jeab_migrate_list($source, 'leads') as $row) {
        $clean = jeab_migrate_redact($row);
        if (!is_array($clean)) {
            continue;
        }
        $id = trim((string) ($clean['lead_id'] ?? $clean['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $rows[] = [
            'lead_id' => $id,
            'thread_id' => jeab_migrate_text((string) ($clean['thread_id'] ?? '')) ?? $id,
            'received_at' => jeab_migrate_datetime($clean['received_at'] ?? null) ?? '1970-01-01 00:00:00',
            'test' => !empty($clean['test']) ? 1 : 0,
        ];
    }
    return jeab_migrate_project($rows, 'lead_id', jeab_migrate_omtago_leads_module()['columns']);
}

function jeab_migrate_omtago_leads_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_omtago_leads_rows($source));
}
