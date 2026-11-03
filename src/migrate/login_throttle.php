<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/login-throttle.json → login_throttle. En rad per adress, utan att logga adressen.
 */

function jeab_migrate_login_throttle_module(): array
{
    return jeab_migrate_declare(
        'login_throttle',
        'login_throttle',
        'data/login-throttle.json',
        'ip_key',
        ['ip_key', 'fails', 'until', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS login_throttle (
    ip_key VARCHAR(191) NOT NULL,
    fails INT NOT NULL,
    until BIGINT NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (ip_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS login_throttle (
    ip_key TEXT PRIMARY KEY,
    fails INTEGER NOT NULL,
    until INTEGER NOT NULL,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_login_throttle_rows(array $source): array
{
    $map = isset($source['ips']) && is_array($source['ips']) ? $source['ips'] : $source;
    $rows = [];
    foreach ($map as $ip => $row) {
        if ((!is_string($ip) && !is_int($ip)) || !is_array($row)) {
            continue;
        }
        $key = trim((string) $ip);
        if ($key === '' || $key === 'ips') {
            continue;
        }
        $clean = [
            'fails' => max(0, (int) ($row['fails'] ?? 0)),
            'until' => max(0, (int) ($row['until'] ?? 0)),
        ];
        $rows[] = [
            'ip_key' => $key,
            'fails' => $clean['fails'],
            'until' => $clean['until'],
            'payload' => jeab_migrate_payload($clean),
        ];
    }

    return jeab_migrate_project($rows, 'ip_key', jeab_migrate_login_throttle_module()['columns']);
}

function jeab_migrate_login_throttle_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_login_throttle_rows($source));
}
