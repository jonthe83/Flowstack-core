<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * Delad tabell för jsonl-loggar. Anropas av who_diag, reminder_follow, reminder_hook och app_errors.
 */

function jeab_migrate_log_lines_schema(): array
{
    return [
        'mysql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS log_lines (
    line_key CHAR(97) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    line_hash CHAR(64) NOT NULL,
    at VARCHAR(40) NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (line_key),
    UNIQUE KEY log_lines_kind_hash (kind, line_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'sqlite' => <<<'SQL'
CREATE TABLE IF NOT EXISTS log_lines (
    line_key TEXT PRIMARY KEY,
    kind TEXT NOT NULL,
    line_hash TEXT NOT NULL,
    at TEXT NOT NULL,
    payload TEXT NOT NULL,
    UNIQUE (kind, line_hash)
)
SQL,
    ];
}

/**
 * @param array<string, mixed> $source
 * @return list<array<string, mixed>>
 */
function jeab_migrate_log_lines_rows(array $source, string $kind): array
{
    $lines = $source['lines'] ?? $source;
    if (isset($lines['lines']) && is_array($lines['lines'])) {
        $lines = $lines['lines'];
    }
    if (is_string($lines)) {
        $lines = preg_split("/\r\n|\n|\r/", $lines) ?: [];
    }
    if (!is_array($lines)) {
        return [];
    }
    $rows = [];
    foreach ($lines as $line) {
        $at = '';
        if (is_array($line)) {
            $clean = jeab_migrate_redact($line);
            if (!is_array($clean)) {
                continue;
            }
            $at = jeab_migrate_text((string) ($clean['at'] ?? $clean['time'] ?? '')) ?? '';
            $payload = jeab_migrate_payload($clean);
        } else {
            $text = trim((string) $line);
            if ($text === '') {
                continue;
            }
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $clean = jeab_migrate_redact($decoded);
                $at = is_array($clean) ? (jeab_migrate_text((string) ($clean['at'] ?? $clean['time'] ?? '')) ?? '') : '';
                $payload = jeab_migrate_payload($clean);
            } else {
                $redacted = jeab_migrate_redact($text);
                $payload = is_string($redacted) ? trim($redacted) : '';
                if ($payload === '') {
                    continue;
                }
            }
        }
        $hash = hash('sha256', $payload);
        $rows[] = [
            'line_key' => $kind . ':' . $hash,
            'kind' => $kind,
            'line_hash' => $hash,
            'at' => $at,
            'payload' => $payload,
        ];
    }
    return jeab_migrate_project($rows, 'line_key', ['line_key', 'kind', 'line_hash', 'at', 'payload']);
}
