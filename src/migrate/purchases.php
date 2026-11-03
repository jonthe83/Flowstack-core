<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/purchases.json → purchases. Ingen flagga, inget hook.
 * payload är kanonisk JSON. Personnummer stannar i payload och skrivs inte i loggar.
 * jeab_idnr_normalize_row körs bara om den redan är laddad, så checksumman
 * är densamma i ett rent test och i appen när id-fält saknas.
 */

function jeab_migrate_purchases_module(): array
{
    return jeab_migrate_module(
        'purchases',
        'purchases',
        'data/purchases.json',
        'reminder_id',
        ['reminder_id', 'payload', 'updated_at', 'company', 'kind', 'seller', 'klart', 'created_at'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS purchases (
    reminder_id INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    company VARCHAR(64) DEFAULT NULL,
    kind VARCHAR(64) DEFAULT NULL,
    seller VARCHAR(64) DEFAULT NULL,
    klart TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT NULL,
    list_json LONGTEXT DEFAULT NULL,
    PRIMARY KEY (reminder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS purchases (
    reminder_id INTEGER PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    company TEXT,
    kind TEXT,
    seller TEXT,
    klart INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    list_json TEXT
)
SQL,
        ['reminders']
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_purchases_rows(array $source): array
{
    $list = array_is_list($source) ? $source : array_values($source);
    $rows = [];
    $stamp = '1970-01-01 00:00:00';
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (function_exists('jeab_idnr_normalize_row')) {
            $row = jeab_idnr_normalize_row($row);
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $payload = json_encode(jeab_migrate_sort($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rows[] = [
            'reminder_id' => $id,
            'payload' => is_string($payload) ? $payload : '{}',
            'updated_at' => jeab_migrate_text(isset($row['updated_at']) ? (string) $row['updated_at'] : null) ?? $stamp,
            'company' => jeab_migrate_company($row['company'] ?? null, false),
            'kind' => jeab_migrate_text(isset($row['kind']) ? (string) $row['kind'] : null),
            'seller' => jeab_migrate_text(isset($row['seller']) ? (string) $row['seller'] : null),
            'klart' => !empty($row['klart']) ? 1 : 0,
            'created_at' => jeab_migrate_text(isset($row['created_at']) ? (string) $row['created_at'] : null),
        ];
    }
    return jeab_migrate_project($rows, 'reminder_id', jeab_migrate_purchases_module()['columns']);
}

function jeab_migrate_purchases_checksum(array $rows): string
{
    return jeab_migrate_checksum(jeab_migrate_purchases_rows($rows));
}

function jeab_migrate_purchases_authoritative_sql(): bool
{
    return function_exists('jeab_purchases_mysql_live') && jeab_purchases_mysql_live();
}
