<?php

declare(strict_types=1);

require_once __DIR__ . '/canon.php';

/**
 * data/graph-person-events.json → log_graph_events. Nyckel är e-post och boknings-id.
 */

function jeab_migrate_graph_events_module(): array
{
    return jeab_migrate_declare(
        'graph_events',
        'log_graph_events',
        'data/graph-person-events.json',
        'event_key',
        ['event_key', 'email', 'booking_id', 'event_id'],
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_graph_events (
    event_key VARCHAR(255) NOT NULL,
    email VARCHAR(191) NOT NULL,
    booking_id INT UNSIGNED NOT NULL,
    event_id TEXT NOT NULL,
    PRIMARY KEY (event_key),
    UNIQUE KEY log_graph_events_pair (email, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS log_graph_events (
    event_key TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    booking_id INTEGER NOT NULL,
    event_id TEXT NOT NULL,
    UNIQUE (email, booking_id)
)
SQL
    );
}

/** @param array<string, mixed> $source */
function jeab_migrate_graph_events_rows(array $source): array
{
    $map = $source['events'] ?? $source;
    $rows = [];
    foreach ($map as $email => $bookings) {
        if (!is_string($email) || $email === 'events' || !is_array($bookings)) {
            continue;
        }
        foreach ($bookings as $bookingId => $eventId) {
            $id = (int) $bookingId;
            if ($id <= 0 || is_array($eventId)) {
                continue;
            }
            $event = trim((string) $eventId);
            if ($event === '') {
                continue;
            }
            $rows[] = [
                'event_key' => $email . ':' . $id,
                'email' => $email,
                'booking_id' => $id,
                'event_id' => $event,
            ];
        }
    }
    return jeab_migrate_project($rows, 'event_key', jeab_migrate_graph_events_module()['columns']);
}

function jeab_migrate_graph_events_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_graph_events_rows($source));
}
