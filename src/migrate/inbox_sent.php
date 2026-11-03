<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * Utskickslogg → inbox_sent. Mottagaren lagras bara som hash, aldrig som adress.
 */

function jeab_migrate_inbox_sent_module(): array
{
    return jeab_migrate_declare(
        'inbox_sent',
        'inbox_sent',
        'data/inkorg/sent.json',
        'row_key',
        ['row_key', 'created_at', 'thread_id', 'via', 'actor', 'kind', 'outcome', 'channel', 'body_hash', 'rcpt_hash'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inbox_sent (
    row_key CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    thread_id VARCHAR(191) NOT NULL DEFAULT '',
    via VARCHAR(16) NOT NULL DEFAULT '',
    actor VARCHAR(191) NOT NULL DEFAULT '',
    kind VARCHAR(32) NOT NULL DEFAULT '',
    outcome VARCHAR(32) NOT NULL DEFAULT '',
    channel VARCHAR(16) NOT NULL DEFAULT '',
    body_hash CHAR(64) NOT NULL DEFAULT '',
    rcpt_hash CHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (row_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inbox_sent (
    row_key TEXT PRIMARY KEY,
    created_at TEXT NOT NULL,
    thread_id TEXT NOT NULL DEFAULT '',
    via TEXT NOT NULL DEFAULT '',
    actor TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL DEFAULT '',
    outcome TEXT NOT NULL DEFAULT '',
    channel TEXT NOT NULL DEFAULT '',
    body_hash TEXT NOT NULL DEFAULT '',
    rcpt_hash TEXT NOT NULL DEFAULT ''
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_inbox_sent_rows(array $source): array
{
    $rows = [];
    foreach (jeab_migrate_list($source, 'rows') as $row) {
        $clean = jeab_migrate_redact($row);
        if (!is_array($clean)) {
            continue;
        }
        unset($clean['to'], $clean['email'], $clean['rcpt'], $clean['recipient']);
        $created = jeab_migrate_datetime($clean['created_at'] ?? $clean['at'] ?? null) ?? '1970-01-01 00:00:00';
        $body = (string) ($clean['body_hash'] ?? '');
        if ($body === '' && isset($clean['body'])) {
            $body = hash('sha256', (string) $clean['body']);
        }
        $rcpt = (string) ($clean['rcpt_hash'] ?? '');
        $projected = [
            'created_at' => $created,
            'thread_id' => (string) ($clean['thread_id'] ?? ''),
            'via' => (string) ($clean['via'] ?? ''),
            'actor' => (string) ($clean['actor'] ?? ''),
            'kind' => (string) ($clean['kind'] ?? ''),
            'outcome' => (string) ($clean['outcome'] ?? ''),
            'channel' => (string) ($clean['channel'] ?? ''),
            'body_hash' => $body,
            'rcpt_hash' => $rcpt,
        ];
        $projected['row_key'] = hash('sha256', jeab_migrate_payload($projected));
        $rows[] = $projected;
    }
    return jeab_migrate_project($rows, 'row_key', jeab_migrate_inbox_sent_module()['columns']);
}

function jeab_migrate_inbox_sent_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inbox_sent_rows($source));
}
