<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/ordrar-deleted.json → json_docs.doc_key = ordrar_deleted.
 * Ingen flagga, inget hook. En rad, payload är kanonisk {ids, hs}.
 */

function jeab_migrate_ordrar_deleted_module(): array
{
    return jeab_migrate_module(
        'ordrar_deleted',
        'json_docs',
        'data/ordrar-deleted.json',
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

/** @param array<string, mixed> $source */
function jeab_migrate_ordrar_deleted_canon(array $source): array
{
    $ids = [];
    foreach (is_array($source['ids'] ?? null) ? $source['ids'] : [] as $key => $row) {
        $id = (int) $key;
        if ($id <= 0 || !is_array($row)) {
            continue;
        }
        $hs = [];
        foreach (is_array($row['hs'] ?? null) ? $row['hs'] : [] as $one) {
            $one = trim((string) $one);
            if ($one !== '' && !in_array($one, $hs, true)) {
                $hs[] = $one;
            }
        }
        sort($hs, SORT_STRING);
        $entry = [
            'at' => trim((string) ($row['at'] ?? '')),
            'hs' => $hs,
            'booking_id' => (int) ($row['booking_id'] ?? 0),
        ];
        $by = trim((string) ($row['by'] ?? ''));
        if ($by !== '') {
            $entry['by'] = mb_substr($by, 0, 191);
        }
        $ids[(string) $id] = $entry;
    }
    $hsMap = [];
    foreach (is_array($source['hs'] ?? null) ? $source['hs'] : [] as $key => $reminderId) {
        $hsId = trim((string) $key);
        if ($hsId === '') {
            continue;
        }
        $hsMap[$hsId] = (int) $reminderId;
    }
    ksort($ids, SORT_STRING);
    ksort($hsMap, SORT_STRING);
    return ['ids' => $ids, 'hs' => $hsMap];
}

/** @param array<string, mixed> $source */
function jeab_migrate_ordrar_deleted_rows(array $source): array
{
    $payload = json_encode(jeab_migrate_ordrar_deleted_canon($source), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [[
        'doc_key' => 'ordrar_deleted',
        'payload' => is_string($payload) ? $payload : '{}',
    ]];
}

function jeab_migrate_ordrar_deleted_checksum(array $source): string
{
    $rows = jeab_migrate_ordrar_deleted_rows($source);
    return jeab_migrate_checksum($rows[0]['payload'] ?? '');
}
