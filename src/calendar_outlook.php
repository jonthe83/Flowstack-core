<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Outlook-koppling per bokning och resurs, i SQL.
 * Nylas (jeab_nylas_sync_booking, jeab_nylas_apply_pushed_times) togs bort i
 * 9e1d873 / PR 420. Graph skriver händelsen och cron läser tillbaka tid och free/busy.
 * Kunden blir aldrig deltagare och inget kundmejl skickas härifrån.
 */

const JEAB_OUTLOOK_BACKFILL_FROM = '2026-10-18 00:00:00';

function jeab_outlook_hook_authorized(): bool
{
    return function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized();
}

function jeab_outlook_pdo(): ?PDO
{
    $injected = $GLOBALS['_JEAB_OUTLOOK_PDO'] ?? null;
    if ($injected instanceof PDO) {
        jeab_outlook_ensure($injected);
        return $injected;
    }
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    if (!$store instanceof JeabPdoStore) {
        return null;
    }
    $pdo = $store->pdo();
    jeab_outlook_ensure($pdo);
    return $pdo;
}

function jeab_outlook_driver(PDO $pdo): string
{
    $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    return $name === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_outlook_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    // Tre CREATE per vy höll kalendern. Tick och CLI skapar tabellerna.
    if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi !== 'cli' && $sapi !== 'phpdbg' && ($method === 'GET' || $method === 'HEAD') && empty($GLOBALS['_JEAB_TICK']) && empty($GLOBALS['_JEAB_SCHEMA_DDL'])) {
        return;
    }
    $driver = jeab_outlook_driver($pdo);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_outlook_events (
                booking_id INT UNSIGNED NOT NULL,
                resource VARCHAR(191) NOT NULL,
                email VARCHAR(191) NOT NULL,
                graph_event_id TEXT NOT NULL,
                show_as VARCHAR(32) NOT NULL DEFAULT \'busy\',
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (booking_id, resource),
                KEY booking_outlook_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_resource_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                booking_id INT UNSIGNED NOT NULL,
                resources TEXT NOT NULL,
                dry TINYINT(1) NOT NULL DEFAULT 0,
                changed TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY booking_resource_audit_booking (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS outlook_sync_state (
                email VARCHAR(191) NOT NULL,
                delta_link TEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_outlook_events (
                booking_id INTEGER NOT NULL,
                resource TEXT NOT NULL,
                email TEXT NOT NULL,
                graph_event_id TEXT NOT NULL,
                show_as TEXT NOT NULL DEFAULT \'busy\',
                updated_at TEXT NOT NULL,
                PRIMARY KEY (booking_id, resource)
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS booking_outlook_email ON booking_outlook_events (email)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_resource_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL,
                booking_id INTEGER NOT NULL,
                resources TEXT NOT NULL,
                dry INTEGER NOT NULL DEFAULT 0,
                changed INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS outlook_sync_state (
                email TEXT PRIMARY KEY,
                delta_link TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    }
    jeab_outlook_import_json($pdo);
}

function jeab_outlook_import_json(PDO $pdo): void
{
    if (function_exists('jeab_log_import_graph_events_file')) {
        try {
            jeab_log_import_graph_events_file(false);
        } catch (Throwable $e) {
            error_log('outlook import ' . $e::class);
        }
    }
    if (!function_exists('jeab_gp_events_path')) {
        return;
    }
    $path = jeab_gp_events_path();
    if (!is_file($path)) {
        return;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return;
    }
    $map = json_decode($raw, true);
    if (!is_array($map)) {
        return;
    }
    $staff = function_exists('jeab_gp_staff_emails') ? jeab_gp_staff_emails() : [];
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    foreach ($map as $email => $rows) {
        if (!is_string($email) || !is_array($rows)) {
            continue;
        }
        $norm = function_exists('jeab_normalize_person_email') ? (jeab_normalize_person_email($email) ?? '') : strtolower($email);
        if ($norm === '' || !isset($staff[$norm])) {
            continue;
        }
        $resource = (string) ($staff[$norm]['name'] ?? '');
        if ($resource === '') {
            continue;
        }
        foreach ($rows as $bookingId => $eventId) {
            $bookingId = (int) $bookingId;
            $eventId = trim((string) $eventId);
            if ($bookingId <= 0 || $eventId === '') {
                continue;
            }
            $exists = $pdo->prepare('SELECT 1 FROM booking_outlook_events WHERE booking_id = ? AND resource = ?');
            $exists->execute([$bookingId, $resource]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO booking_outlook_events (booking_id, resource, email, graph_event_id, show_as, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$bookingId, $resource, $norm, $eventId, 'busy', $now]);
        }
    }
    @unlink($path);
}

function jeab_outlook_resource_for_email(array $row, string $email): string
{
    $email = function_exists('jeab_normalize_person_email') ? (jeab_normalize_person_email($email) ?? '') : strtolower(trim($email));
    $company = function_exists('jeab_booking_company') ? jeab_booking_company($row) : null;
    foreach (jeab_assignees_from_row($row) as $name) {
        $who = jeab_gp_person_email((string) $name, $company);
        if ($who !== '' && strcasecmp($who, $email) === 0) {
            return (string) $name;
        }
    }
    foreach (jeab_gp_staff_emails() as $addr => $info) {
        if (strcasecmp((string) $addr, $email) === 0) {
            return (string) ($info['name'] ?? $email);
        }
    }
    return $email;
}

/**
 * Bokningar som redan har just detta Graph-id. Kopior på en annan postlåda
 * har ett annat id och träffar inte här.
 *
 * @return list<int>
 */
function jeab_outlook_booking_ids_for_graph(string $eventId): array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return [];
    }
    $pdo = jeab_outlook_pdo();
    if (!$pdo) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT booking_id FROM booking_outlook_events WHERE graph_event_id = ?');
    $stmt->execute([$eventId]);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['booking_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function jeab_outlook_event_id(int $bookingId, string $email): string
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo || $bookingId <= 0) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT graph_event_id FROM booking_outlook_events WHERE booking_id = ? AND email = ? LIMIT 1');
    $stmt->execute([$bookingId, $email]);
    $id = $stmt->fetchColumn();
    return is_string($id) ? $id : '';
}

function jeab_outlook_event_save(int $bookingId, string $resource, string $email, string $eventId, string $showAs): void
{
    if ($bookingId > 0 && function_exists('jeab_store') && function_exists('jeab_calendar_test_lead')) {
        try {
            $row = jeab_store()->getBooking($bookingId);
            if (is_array($row) && jeab_calendar_test_lead($row)) {
                if (function_exists('jeab_booking_stamp_test')) {
                    jeab_booking_stamp_test($row);
                }

                return;
            }
        } catch (Throwable) {
        }
    }
    $pdo = jeab_outlook_pdo();
    if (!$pdo || $bookingId <= 0 || $resource === '' || $email === '' || $eventId === '') {
        return;
    }
    $showAs = strtolower(trim($showAs)) !== '' ? strtolower(trim($showAs)) : 'busy';
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('DELETE FROM booking_outlook_events WHERE booking_id = ? AND email = ? AND resource <> ?')
        ->execute([$bookingId, $email, $resource]);
    if (jeab_outlook_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO booking_outlook_events (booking_id, resource, email, graph_event_id, show_as, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email = VALUES(email), graph_event_id = VALUES(graph_event_id), show_as = VALUES(show_as), updated_at = VALUES(updated_at)'
        )->execute([$bookingId, $resource, $email, $eventId, $showAs, $now]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO booking_outlook_events (booking_id, resource, email, graph_event_id, show_as, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(booking_id, resource) DO UPDATE SET
            email = excluded.email,
            graph_event_id = excluded.graph_event_id,
            show_as = excluded.show_as,
            updated_at = excluded.updated_at'
    )->execute([$bookingId, $resource, $email, $eventId, $showAs, $now]);
}

function jeab_outlook_event_delete(int $bookingId, string $email): void
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo || $bookingId <= 0 || $email === '') {
        return;
    }
    $pdo->prepare('DELETE FROM booking_outlook_events WHERE booking_id = ? AND email = ?')->execute([$bookingId, $email]);
}

/** @return list<array{booking_id:int,resource:string,email:string,graph_event_id:string,show_as:string}> */
function jeab_outlook_events_for_booking(int $bookingId): array
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo || $bookingId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT booking_id, resource, email, graph_event_id, show_as FROM booking_outlook_events WHERE booking_id = ? ORDER BY resource');
    $stmt->execute([$bookingId]);
    return jeab_outlook_event_rows($stmt->fetchAll());
}

/** @return array<string, true> */
function jeab_outlook_known_event_ids(): array
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo) {
        return [];
    }
    $out = [];
    foreach ($pdo->query('SELECT graph_event_id FROM booking_outlook_events') ?: [] as $row) {
        $id = trim((string) ($row['graph_event_id'] ?? ''));
        if ($id !== '') {
            $out[$id] = true;
        }
    }
    return $out;
}

/** @param list<array<string, mixed>> $rows
 *  @return list<array{booking_id:int,resource:string,email:string,graph_event_id:string,show_as:string}>
 */
function jeab_outlook_event_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'resource' => (string) ($row['resource'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'graph_event_id' => (string) ($row['graph_event_id'] ?? ''),
            'show_as' => (string) ($row['show_as'] ?? 'busy'),
        ];
    }
    return $out;
}

function jeab_outlook_resource_blocks(int $bookingId, string $who): bool
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo || $bookingId <= 0 || trim($who) === '') {
        return true;
    }
    $stmt = $pdo->prepare('SELECT resource, email, show_as FROM booking_outlook_events WHERE booking_id = ?');
    $stmt->execute([$bookingId]);
    $want = strtolower(trim($who));
    $seen = false;
    foreach ($stmt->fetchAll() as $row) {
        $resource = strtolower(trim((string) ($row['resource'] ?? '')));
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($resource !== $want && $email !== $want) {
            continue;
        }
        $show = strtolower(trim((string) ($row['show_as'] ?? 'busy')));
        if (in_array($show, ['free', 'workingelsewhere'], true)) {
            return false;
        }
    }
    return true;
}

function jeab_outlook_is_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === 0 || $dry === '0' || $dry === false);
}

/** @return list<string> */
function jeab_outlook_resource_names(mixed $raw, ?string $company): array
{
    if (is_string($raw)) {
        $raw = array_map('trim', explode(',', $raw));
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    return jeab_require_installers(jeab_parse_assignees($raw, $company));
}

function jeab_outlook_audit(int $bookingId, array $names, bool $dry, bool $changed): void
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo) {
        throw new RuntimeException('SQL saknas.');
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO booking_resource_audit (created_at, booking_id, resources, dry, changed) VALUES (?, ?, ?, ?, ?)'
    )->execute([$now, $bookingId, implode(', ', $names), $dry ? 1 : 0, $changed ? 1 : 0]);
}

/**
 * @return array{ok:bool,dry:bool,booking_id:int,resources:list<string>,changed:bool}
 */
function jeab_outlook_set_resources(JeabStore $store, int $bookingId, mixed $resources, bool $dry): array
{
    if (jeab_outlook_pdo() === null) {
        throw new RuntimeException('SQL saknas.');
    }
    $row = $store->getBooking($bookingId);
    if (!$row) {
        throw new InvalidArgumentException('Bokningen finns inte.');
    }
    if (($row['status'] ?? '') === 'cancelled') {
        throw new InvalidArgumentException('Bokningen är avbokad.');
    }
    $company = jeab_booking_company($row);
    $names = jeab_outlook_resource_names($resources, $company);
    $before = jeab_assignees_from_row($row);
    $changed = $before !== $names;
    jeab_outlook_audit($bookingId, $names, $dry, $changed);
    if ($dry || !$changed) {
        return ['ok' => true, 'dry' => $dry, 'booking_id' => $bookingId, 'resources' => $names, 'changed' => $changed];
    }
    $saved = $store->saveBooking($bookingId, [
        'assignees' => $names,
        'assignee' => jeab_assignees_join($names),
        'updated_at' => (string) ($row['updated_at'] ?? jeab_now()),
    ]);
    if (function_exists('jeab_gp_sync_booking')) {
        $shown = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($saved) : $saved;
        jeab_gp_sync_booking($shown);
    }
    if (function_exists('jeab_gp_week_events_cache_forget')) {
        jeab_gp_week_events_cache_forget();
    }
    return ['ok' => true, 'dry' => false, 'booking_id' => $bookingId, 'resources' => jeab_assignees_from_row($saved), 'changed' => true];
}

/**
 * Bokningar från 2026-10-18 som saknar Outlook-händelse för en resurs.
 *
 * @return array{ok:bool,dry:bool,items:list<array{id:int,date:string,resource:string}>,written?:int}
 */
function jeab_outlook_backfill(JeabStore $store, bool $dry): array
{
    if (jeab_outlook_pdo() === null) {
        throw new RuntimeException('SQL saknas.');
    }
    $rows = $store->bookingsBetween(JEAB_OUTLOOK_BACKFILL_FROM, '2100-01-01 00:00:00');
    $items = [];
    $written = 0;
    foreach ($rows as $row) {
        if (!is_array($row) || ($row['status'] ?? '') === 'cancelled') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $date = substr((string) ($row['starts_at'] ?? ''), 0, 10);
        if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        $company = jeab_booking_company($row);
        $have = [];
        foreach (jeab_outlook_events_for_booking($id) as $link) {
            if ((string) ($link['graph_event_id'] ?? '') !== '') {
                $have[strtolower((string) $link['email'])] = true;
            }
        }
        foreach (jeab_assignees_from_row($row) as $name) {
            $email = jeab_gp_person_email((string) $name, $company);
            if ($email === '' || isset($have[strtolower($email)])) {
                continue;
            }
            $items[] = ['id' => $id, 'date' => $date, 'resource' => (string) $name];
            $shown = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
            if (!$dry && function_exists('jeab_gp_upsert_event') && jeab_gp_upsert_event($email, $shown)) {
                $written++;
            }
        }
    }
    $out = ['ok' => true, 'dry' => $dry, 'items' => $items];
    if (!$dry) {
        $out['written'] = $written;
    }
    return $out;
}

function jeab_outlook_graph_zone_is_utc(string $zone): bool
{
    $zone = strtolower(trim($zone));
    return $zone === 'utc' || $zone === 'gmt' || str_contains($zone, 'utc');
}

function jeab_outlook_graph_has_offset(string $raw): bool
{
    return (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', trim($raw));
}

/** Graph-tid till DateTime. UTC utan Z tolkas som UTC, inte som serverns zon. */
function jeab_outlook_parse_graph_datetime(string $raw, string $zone): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $raw = preg_replace('/\.\d+/', '', $raw) ?? $raw;
    try {
        if (jeab_outlook_graph_has_offset($raw)) {
            return new DateTimeImmutable($raw);
        }
        if (jeab_outlook_graph_zone_is_utc($zone)) {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        }
        if ($zone !== '') {
            try {
                return new DateTimeImmutable($raw, new DateTimeZone($zone));
            } catch (Throwable) {
            }
        }

        return new DateTimeImmutable($raw);
    } catch (Throwable) {
        return null;
    }
}

/** @return array{starts_at:string,ends_at:string}|null */
function jeab_outlook_event_times(array $event): ?array
{
    $tzName = function_exists('jeab_gp_tz') ? jeab_gp_tz() : 'Europe/Stockholm';
    $tz = new DateTimeZone($tzName);
    $allDay = !empty($event['isAllDay']);
    $startRaw = (string) ($event['start']['dateTime'] ?? '');
    $endRaw = (string) ($event['end']['dateTime'] ?? '');
    $zone = (string) ($event['start']['timeZone'] ?? '');
    $start = jeab_outlook_parse_graph_datetime($startRaw, $zone);
    $end = jeab_outlook_parse_graph_datetime($endRaw, (string) ($event['end']['timeZone'] ?? $zone));
    if ($start === null || $end === null) {
        return null;
    }
    $start = $start->setTimezone($tz);
    $end = $end->setTimezone($tz);
    if ($allDay) {
        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(0, 0, 0);
        if ($end <= $start) {
            $end = $start->modify('+1 day');
        }
    }

    return [
        'starts_at' => $start->format('Y-m-d H:i:s'),
        'ends_at' => $end->format('Y-m-d H:i:s'),
    ];
}

function jeab_outlook_delta_link(string $email): string
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT delta_link FROM outlook_sync_state WHERE email = ?');
    $stmt->execute([$email]);
    $link = $stmt->fetchColumn();
    return is_string($link) ? $link : '';
}

function jeab_outlook_delta_save(string $email, string $link): void
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo || $email === '') {
        return;
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    if (jeab_outlook_driver($pdo) === 'mysql') {
        $pdo->prepare(
            'INSERT INTO outlook_sync_state (email, delta_link, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE delta_link = VALUES(delta_link), updated_at = VALUES(updated_at)'
        )->execute([$email, $link, $now]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO outlook_sync_state (email, delta_link, updated_at) VALUES (?, ?, ?)
         ON CONFLICT(email) DO UPDATE SET delta_link = excluded.delta_link, updated_at = excluded.updated_at'
    )->execute([$email, $link, $now]);
}

/** @return list<array<string, mixed>>|null */
function jeab_outlook_delta_events(string $email): ?array
{
    $saved = jeab_outlook_delta_link($email);
    if ($saved !== '') {
        $path = $saved;
    } else {
        $from = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 days');
        $to = $from->modify('+400 days');
        $path = 'users/' . rawurlencode($email) . '/calendarView/delta?startDateTime='
            . rawurlencode($from->format('Y-m-d\TH:i:s\Z'))
            . '&endDateTime=' . rawurlencode($to->format('Y-m-d\TH:i:s\Z'));
    }
    $events = [];
    for ($page = 0; $page < 5; $page++) {
        $res = jeab_graph_api('GET', $path, null, ['Prefer: outlook.timezone="Europe/Stockholm"']);
        $code = (int) ($res['code'] ?? 0);
        if ($code !== 200) {
            if ($saved !== '' && in_array($code, [400, 410], true)) {
                jeab_outlook_delta_save($email, '');
            }
            return null;
        }
        $body = is_array($res['body'] ?? null) ? $res['body'] : [];
        foreach ((array) ($body['value'] ?? []) as $event) {
            if (is_array($event)) {
                $events[] = $event;
            }
        }
        $delta = trim((string) ($body['@odata.deltaLink'] ?? ''));
        $next = trim((string) ($body['@odata.nextLink'] ?? ''));
        if ($delta !== '') {
            jeab_outlook_delta_save($email, $delta);
            return $events;
        }
        if ($next === '') {
            return $events;
        }
        $path = $next;
        $saved = '';
    }
    return $events;
}

function jeab_outlook_fetch_event(string $email, string $eventId): ?array
{
    $res = jeab_graph_api(
        'GET',
        'users/' . rawurlencode($email) . '/events/' . rawurlencode($eventId) . '?$select=id,start,end,isCancelled,showAs,isAllDay,attendees',
        null,
        ['Prefer: outlook.timezone="Europe/Stockholm"']
    );
    $code = (int) ($res['code'] ?? 0);
    if ($code === 404) {
        return ['id' => $eventId, '@removed' => ['reason' => 'deleted'], '_jeab_outlook_fetched' => true];
    }
    if ($code !== 200 || !is_array($res['body'] ?? null)) {
        return null;
    }
    $body = $res['body'];
    $body['_jeab_outlook_fetched'] = true;
    return $body;
}

function jeab_outlook_removed_reason(array $event): string
{
    $removed = $event['@removed'] ?? null;
    if (!is_array($removed)) {
        return '';
    }

    return strtolower(trim((string) ($removed['reason'] ?? '')));
}

/**
 * Avboka bara när händelsen är borta: isCancelled, eller @removed deleted
 * bekräftat med GET 404. reason=changed betyder att den lämnade deltafönstret.
 */
function jeab_outlook_event_should_drop(string $email, array $event): bool
{
    if (!empty($event['isCancelled'])) {
        return true;
    }
    $reason = jeab_outlook_removed_reason($event);
    if ($reason === '') {
        return false;
    }
    if ($reason === 'changed') {
        return false;
    }
    if ($reason !== 'deleted') {
        return false;
    }
    if (!empty($event['_jeab_outlook_fetched'])) {
        return true;
    }
    $id = trim((string) ($event['id'] ?? ''));
    if ($id === '' || $email === '') {
        return false;
    }
    $fresh = jeab_outlook_fetch_event($email, $id);
    if ($fresh === null) {
        return false;
    }
    if (!empty($fresh['isCancelled'])) {
        return true;
    }

    return jeab_outlook_removed_reason($fresh) === 'deleted' && !empty($fresh['_jeab_outlook_fetched']);
}

function jeab_outlook_show_save(int $bookingId, string $email, string $showAs): void
{
    $pdo = jeab_outlook_pdo();
    if (!$pdo) {
        return;
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE booking_outlook_events SET show_as = ?, updated_at = ? WHERE booking_id = ? AND email = ?')
        ->execute([strtolower(trim($showAs)) !== '' ? strtolower(trim($showAs)) : 'busy', $now, $bookingId, $email]);
}

function jeab_outlook_drop_resource(JeabStore $store, array $row, string $resource, string $email): void
{
    $id = (int) ($row['id'] ?? 0);
    jeab_outlook_event_delete($id, $email);
    $names = [];
    foreach (jeab_assignees_from_row($row) as $name) {
        if (strcasecmp((string) $name, $resource) !== 0) {
            $names[] = (string) $name;
        }
    }
    $updated = (string) ($row['updated_at'] ?? jeab_now());
    if ($names === []) {
        $saved = $store->saveBooking($id, [
            'status' => 'cancelled',
            'updated_at' => $updated,
        ]);
        if (function_exists('jeab_ordrar_clear_bokad_tid_from_booking')) {
            try {
                jeab_ordrar_clear_bokad_tid_from_booking($saved);
            } catch (Throwable) {
            }
        }
        return;
    }
    $store->saveBooking($id, [
        'assignees' => $names,
        'assignee' => jeab_assignees_join($names),
        'updated_at' => $updated,
    ]);
}

function jeab_outlook_apply_link(JeabStore $store, array $link, ?array $event): string
{
    if ($event === null) {
        return '';
    }
    $bookingId = (int) ($link['booking_id'] ?? 0);
    $row = $store->getBooking($bookingId);
    if (!$row || ($row['status'] ?? '') === 'cancelled') {
        return '';
    }
    $email = (string) ($link['email'] ?? '');
    $resource = (string) ($link['resource'] ?? '');
    if (jeab_outlook_event_should_drop($email, $event)) {
        jeab_outlook_drop_resource($store, $row, $resource, $email);
        return 'removed';
    }
    if (jeab_outlook_removed_reason($event) !== '') {
        return '';
    }
    $show = strtolower(trim((string) ($event['showAs'] ?? 'busy')));
    if ($show === '') {
        $show = 'busy';
    }
    if ($show !== strtolower((string) ($link['show_as'] ?? 'busy'))) {
        jeab_outlook_show_save($bookingId, $email, $show);
    }
    $times = jeab_outlook_event_times($event);
    if ($times === null) {
        return $show === 'free' ? 'free' : '';
    }
    if ((string) $row['starts_at'] === $times['starts_at'] && (string) $row['ends_at'] === $times['ends_at']) {
        return $show === 'free' || $show === 'workingelsewhere' ? 'free' : '';
    }
    $saved = $store->saveBooking($bookingId, [
        'starts_at' => $times['starts_at'],
        'ends_at' => $times['ends_at'],
        'updated_at' => (string) ($row['updated_at'] ?? jeab_now()),
    ]);
    if (function_exists('jeab_calendar_log_append')) {
        jeab_calendar_log_append('rescheduled', $saved, null, 'outlook', [
            'prev_starts_at' => (string) $row['starts_at'],
            'prev_ends_at' => (string) ($row['ends_at'] ?? ''),
            'who' => $resource,
        ]);
    }
    if (function_exists('jeab_booking_mark_move_mail')) {
        jeab_booking_mark_move_mail($bookingId, $times['starts_at'], $saved);
    }
    if (function_exists('jeab_ordrar_sync_bokad_tid_from_booking')) {
        jeab_ordrar_sync_bokad_tid_from_booking($saved);
    }
    if (empty($GLOBALS['_JEAB_OUTLOOK_PULL']) && function_exists('jeab_gp_sync_booking')) {
        $GLOBALS['_JEAB_OUTLOOK_PULL'] = true;
        try {
            $shown = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($saved) : $saved;
            jeab_gp_sync_booking($shown, $email);
        } finally {
            unset($GLOBALS['_JEAB_OUTLOOK_PULL']);
        }
    }
    return 'updated';
}

/**
 * Delta per resursbrevlåda. Saknas delta läses varje sparad händelse med GET.
 * Uppdaterar tid och free/busy. Skickar inget mejl härifrån; en flyttad
 * tid märks med samma flyttkö som en manuell sparning.
 *
 * @return array{ok:bool,pulled:int,updated:int,freed:int,removed:int}
 */
function jeab_outlook_pull(JeabStore $store): array
{
    $empty = ['ok' => true, 'pulled' => 0, 'updated' => 0, 'freed' => 0, 'removed' => 0];
    if (!empty($GLOBALS['_JEAB_OUTLOOK_PULL'])) {
        return $empty;
    }
    $mocked = isset($GLOBALS['_JEAB_GRAPH_HTTP']) && is_callable($GLOBALS['_JEAB_GRAPH_HTTP']);
    if (!$mocked && (!function_exists('jeab_graph_configured') || !jeab_graph_configured())) {
        return $empty;
    }
    $pdo = jeab_outlook_pdo();
    if (!$pdo) {
        return $empty;
    }
    $links = jeab_outlook_event_rows($pdo->query('SELECT booking_id, resource, email, graph_event_id, show_as FROM booking_outlook_events')?->fetchAll() ?: []);
    $pdo = null;
    $byEmail = [];
    foreach ($links as $link) {
        if ($link['email'] === '' || $link['graph_event_id'] === '') {
            continue;
        }
        $byEmail[$link['email']][] = $link;
    }
    $updated = 0;
    $freed = 0;
    $removed = 0;
    $pulled = 0;
    foreach ($byEmail as $email => $rows) {
        $delta = jeab_outlook_delta_events($email);
        $byId = [];
        if (is_array($delta)) {
            foreach ($delta as $event) {
                $eid = trim((string) ($event['id'] ?? ''));
                if ($eid !== '') {
                    $byId[$eid] = $event;
                }
            }
        }
        foreach ($rows as $link) {
            $event = $byId[$link['graph_event_id']] ?? null;
            if ($event === null) {
                $event = jeab_outlook_fetch_event($email, $link['graph_event_id']);
            }
            if ($event === null) {
                continue;
            }
            $pulled++;
            $kind = jeab_outlook_apply_link($store, $link, $event);
            if ($kind === 'updated') {
                $updated++;
            } elseif ($kind === 'free') {
                $freed++;
            } elseif ($kind === 'removed') {
                $removed++;
            }
        }
    }

    return ['ok' => true, 'pulled' => $pulled, 'updated' => $updated, 'freed' => $freed, 'removed' => $removed];
}

/**
 * dry saknas = torr. confirm=1 ensamt skriver. dry=0 utan confirm = 422. dry=1 skriver aldrig.
 *
 * @return 'dry'|'wet'|'need_confirm'
 */
function jeab_outlook_details_mode(array $input): string
{
    $confirm = ($input['confirm'] ?? null) === 1
        || ($input['confirm'] ?? null) === '1'
        || ($input['confirm'] ?? null) === true;
    $hasDry = array_key_exists('dry', $input);
    $dry = jeab_outlook_is_dry($input);
    if ($hasDry && !$dry && !$confirm) {
        return 'need_confirm';
    }
    if ($confirm && (!$hasDry || !$dry)) {
        return 'wet';
    }

    return 'dry';
}

/**
 * Befintliga framtida händelser får besöksadress, telefon, e-post, installationsanteckning
 * och hela påminnelsetexten. Bara location och body. Ingen tid, inga deltagare, sendUpdates=none.
 *
 * @return array<string, mixed>
 */
function jeab_outlook_backfill_details(JeabStore $store, array $input): array
{
    if (jeab_outlook_pdo() === null) {
        throw new RuntimeException('SQL saknas.');
    }
    $mode = jeab_outlook_details_mode($input);
    if ($mode === 'need_confirm') {
        return [
            'ok' => false,
            'error' => 'Bekräfta med confirm=1.',
            'action' => 'calendar.backfill_details',
            'dry' => true,
            'written' => 0,
            'status' => 422,
        ];
    }
    $dry = $mode !== 'wet';
    $tz = new DateTimeZone(function_exists('jeab_gp_tz') ? jeab_gp_tz() : 'Europe/Stockholm');
    $now = (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    $rows = $store->bookingsBetween($now, '2100-01-01 00:00:00');
    $items = [];
    $written = 0;
    $failed = 0;
    foreach ($rows as $row) {
        if (!is_array($row) || ($row['status'] ?? '') === 'cancelled') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $targets = [];
        foreach (jeab_outlook_events_for_booking($id) as $link) {
            $eventId = trim((string) ($link['graph_event_id'] ?? ''));
            $email = trim((string) ($link['email'] ?? ''));
            if ($eventId === '' || $email === '') {
                continue;
            }
            $targets[] = ['email' => $email, 'event_id' => $eventId];
        }
        if ($targets === []) {
            continue;
        }
        $date = substr((string) ($row['starts_at'] ?? ''), 0, 10);
        $items[] = ['id' => $id, 'date' => $date, 'events' => count($targets)];
        if ($dry || !function_exists('jeab_gp_patch_event_details')) {
            continue;
        }
        $shown = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($row) : $row;
        foreach ($targets as $target) {
            if (jeab_gp_patch_event_details($target['email'], $target['event_id'], $shown)) {
                $written++;
            } else {
                $failed++;
            }
        }
    }
    $out = [
        'ok' => true,
        'action' => 'calendar.backfill_details',
        'dry' => $dry,
        'items' => $items,
    ];
    if (!$dry) {
        $out['written'] = $written;
        $out['failed'] = $failed;
    }

    return $out;
}

function jeab_outlook_hook(JeabStore $store, array $input): array
{
    $action = (string) ($input['action'] ?? '');
    if ($action === 'calendar.outlook_backfill') {
        return jeab_outlook_backfill($store, jeab_outlook_is_dry($input));
    }
    if ($action === 'calendar.backfill_details') {
        return jeab_outlook_backfill_details($store, $input);
    }
    if ($action === 'calendar.booking_set_resources') {
        $id = (int) ($input['booking_id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('booking_id saknas.');
        }
        if (!array_key_exists('resources', $input)) {
            throw new InvalidArgumentException('resources saknas.');
        }
        return jeab_outlook_set_resources($store, $id, $input['resources'], jeab_outlook_is_dry($input));
    }
    return ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
}
