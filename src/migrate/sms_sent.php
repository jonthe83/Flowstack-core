<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/sms-sent.json → sms_sent. Nyckel är boknings-id, payload är flaggobjektet.
 */

function jeab_migrate_sms_sent_module(): array
{
    return jeab_migrate_declare(
        'sms_sent',
        'sms_sent',
        'data/sms-sent.json',
        'booking_id',
        ['booking_id', 'payload'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sms_sent (
    booking_id INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    PRIMARY KEY (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sms_sent (
    booking_id INTEGER PRIMARY KEY,
    payload TEXT NOT NULL
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_sms_sent_rows(array $source): array
{
    $map = $source;
    if (isset($source['rows']) && is_array($source['rows']) && array_is_list($source['rows'])) {
        $map = $source['rows'];
    }
    $rows = [];
    foreach ($map as $key => $row) {
        if (!is_array($row) || array_is_list($row)) {
            continue;
        }
        $id = (int) ($row['booking_id'] ?? $key);
        if ($id < 0) {
            continue;
        }
        // Payload som appen skriver (jeab_sms_store_upsert) saknar booking_id; id ligger i kolumnen.
        // Lägg inte till nyckeln här, annars ser snabbkollen booking_id som saknat i SQL.
        $rows[] = ['booking_id' => $id, 'payload' => jeab_migrate_payload($row)];
    }
    return jeab_migrate_project($rows, 'booking_id', jeab_migrate_sms_sent_module()['columns']);
}

function jeab_migrate_sms_sent_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_sms_sent_rows($source));
}
