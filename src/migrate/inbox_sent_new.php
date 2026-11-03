<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/inkorg/sent-new.json → inbox_sent_new.
 * Deduplicering av «skicka nytt mejl» per send_key.
 */

function jeab_migrate_inbox_sent_new_module(): array
{
    return jeab_migrate_declare(
        'inbox_sent_new',
        'inbox_sent_new',
        'data/inkorg/sent-new.json',
        'send_key',
        ['send_key', 'at', 'by', 'company', 'from', 'to', 'subject'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inbox_sent_new (
    send_key VARCHAR(191) NOT NULL,
    at VARCHAR(64) NOT NULL DEFAULT '',
    `by` VARCHAR(191) NOT NULL DEFAULT '',
    company VARCHAR(32) NOT NULL DEFAULT '',
    `from` VARCHAR(191) NOT NULL DEFAULT '',
    `to` VARCHAR(191) NOT NULL DEFAULT '',
    subject TEXT NOT NULL,
    PRIMARY KEY (send_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS inbox_sent_new (
    send_key TEXT PRIMARY KEY,
    at TEXT NOT NULL DEFAULT '',
    "by" TEXT NOT NULL DEFAULT '',
    company TEXT NOT NULL DEFAULT '',
    "from" TEXT NOT NULL DEFAULT '',
    "to" TEXT NOT NULL DEFAULT '',
    subject TEXT NOT NULL DEFAULT ''
)
SQL
    );
}

/** @param array<string, mixed> $row */
function jeab_migrate_inbox_sent_new_normalize(array $row): array
{
    $log = [
        'at' => (string) ($row['at'] ?? ''),
        'by' => (string) ($row['by'] ?? ''),
        'company' => (string) ($row['company'] ?? ''),
        'from' => (string) ($row['from'] ?? ''),
        'to' => (string) ($row['to'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'send_key' => trim((string) ($row['send_key'] ?? '')),
    ];
    if ($log['send_key'] === '') {
        $log['send_key'] = 'nk:' . hash('sha256', implode("\n", [
            $log['at'],
            $log['by'],
            $log['company'],
            $log['from'],
            $log['to'],
            $log['subject'],
        ]));
    }

    return $log;
}

/**
 * @param array<string, mixed> $source
 * @return list<array<string, mixed>>
 */
function jeab_migrate_inbox_sent_new_items(array $source): array
{
    foreach (['sent', 'inbox_sent_new'] as $key) {
        if (isset($source[$key]) && is_array($source[$key])) {
            $source = $source[$key];
            break;
        }
    }
    $items = [];
    if (array_is_list($source)) {
        foreach ($source as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return $items;
    }
    foreach ($source as $row) {
        if (is_array($row) && (isset($row['send_key']) || isset($row['at']) || isset($row['to']) || isset($row['subject']))) {
            $items[] = $row;
        }
    }

    return $items;
}

/** @param array<string, mixed> $source */
function jeab_migrate_inbox_sent_new_rows(array $source): array
{
    $rows = [];
    foreach (jeab_migrate_inbox_sent_new_items($source) as $row) {
        $clean = jeab_migrate_redact($row);
        if (!is_array($clean)) {
            continue;
        }
        $rows[] = jeab_migrate_inbox_sent_new_normalize($clean);
    }

    return jeab_migrate_project($rows, 'send_key', jeab_migrate_inbox_sent_new_module()['columns']);
}

function jeab_migrate_inbox_sent_new_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_inbox_sent_new_rows($source));
}
