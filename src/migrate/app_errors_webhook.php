<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * app-errors-webhook-seen.json och app-errors-webhook-resolved.json.
 * En modul, en checksumma. Radnyckeln är slot plus fingeravtryck.
 */

function jeab_migrate_app_errors_webhook_module(): array
{
    return jeab_migrate_declare(
        'app_errors_webhook',
        'app_error_webhook',
        'data/app-errors-webhook-seen.json',
        'row_key',
        ['row_key', 'slot', 'fp', 'at', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS app_error_webhook (
    row_key VARCHAR(191) NOT NULL,
    slot VARCHAR(16) NOT NULL,
    fp VARCHAR(64) NOT NULL,
    at VARCHAR(40) NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (row_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS app_error_webhook (
    row_key TEXT PRIMARY KEY,
    slot TEXT NOT NULL,
    fp TEXT NOT NULL,
    at TEXT NOT NULL,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @return array<string, mixed> */
function jeab_migrate_app_errors_webhook_source(): array
{
    $seenPath = function_exists('jeab_log_app_error_webhook_seen_path')
        ? jeab_log_app_error_webhook_seen_path()
        : (function_exists('jeab_data_file') ? jeab_data_file('app-errors-webhook-seen.json') : '');
    $resolvedPath = function_exists('jeab_log_app_error_webhook_resolved_path')
        ? jeab_log_app_error_webhook_resolved_path()
        : (function_exists('jeab_data_file') ? jeab_data_file('app-errors-webhook-resolved.json') : '');
    $read = static function (string $path): array {
        if ($path === '' || !is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    };

    return ['seen' => $read($seenPath), 'resolved' => $read($resolvedPath)];
}

/** @param array<string, mixed> $source */
function jeab_migrate_app_errors_webhook_rows(array $source): array
{
    $rows = [];
    $seen = is_array($source['seen'] ?? null) ? $source['seen'] : [];
    foreach ($seen as $fp => $ts) {
        $fp = strtolower(trim((string) $fp));
        if (preg_match('/^[0-9a-f]{8,64}$/', $fp) !== 1) {
            continue;
        }
        $at = (int) $ts;
        $rows[] = [
            'row_key' => 'seen:' . $fp,
            'slot' => 'seen',
            'fp' => $fp,
            'at' => (string) $at,
            'payload' => jeab_migrate_payload(['at' => $at]),
        ];
    }
    $resolved = is_array($source['resolved'] ?? null) ? $source['resolved'] : [];
    foreach ($resolved as $fp => $row) {
        $fp = strtolower(trim((string) $fp));
        if (preg_match('/^[0-9a-f]{8,64}$/', $fp) !== 1) {
            continue;
        }
        $clean = is_array($row) ? $row : ['at' => (int) $row];
        $rows[] = [
            'row_key' => 'resolved:' . $fp,
            'slot' => 'resolved',
            'fp' => $fp,
            'at' => (string) (int) ($clean['at'] ?? 0),
            'payload' => jeab_migrate_payload($clean),
        ];
    }

    return jeab_migrate_project($rows, 'row_key', jeab_migrate_app_errors_webhook_module()['columns']);
}

function jeab_migrate_app_errors_webhook_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_app_errors_webhook_rows($source));
}
