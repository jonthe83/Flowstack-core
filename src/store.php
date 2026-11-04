<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/store_sql_storage.php';
interface JeabStore
{
    public function listReminders(bool $includeCompleted = true): array;

    public function getReminder(int $id): ?array;

    public function insertReminder(array $data): array;

    public function saveReminder(int $id, array $fields): array;

    public function incrementReminderTap(int $id, string $field): array;

    public function deleteReminders(array $ids): int;

    public function findOpenReminderByBooking(int $bookingId): ?array;

    public function findCustomerByName(string $name): ?array;

    public function insertCustomer(string $name, string $createdAt): array;

    public function listCustomers(): array;

    public function recentAssignees(int $limit): array;

    public function getBooking(int $id): ?array;

    public function bookingsBetween(string $from, string $to): array;

    public function bookingsCreatedBetween(string $from, string $to): array;

    public function bookingsUpdatedBetween(string $from, string $to): array;

    public function listAllBookings(): array;

    public function insertBooking(array $data): array;

    public function saveBooking(int $id, array $fields): array;

    public function setting(string $key, ?string $default = null): ?string;

    public function setSetting(string $key, string $value): void;
}

function jeab_pdo_drivers(): array
{
    $drivers = PDO::getAvailableDrivers();
    if (getenv('JEAB_DISABLE_SQLITE') === '1') {
        $drivers = array_values(array_filter($drivers, static fn(string $d): bool => $d !== 'sqlite'));
    }
    return $drivers;
}

/** SQLite avstängd och json-drivern: ingen SQL finns. Läsare ska ge «SQL saknas», inte en tom JSON-lista. */
function jeab_sql_disabled_json(): bool
{
    if (getenv('JEAB_DISABLE_SQLITE') !== '1' || !function_exists('jeab_driver') || jeab_driver() !== 'json') {
        return false;
    }
    // En inkopplad PDO (neutral krok) betyder att SQL finns.
    return !(function_exists('jeab_store_pdo_override') && jeab_store_pdo_override() instanceof PDO);
}

function jeab_pdo_has(string $driver): bool
{
    return in_array($driver, jeab_pdo_drivers(), true);
}

function jeab_driver(): string
{
    $wanted = strtolower((string) (jeab_config()['db']['driver'] ?? 'sqlite'));
    $mysql = jeab_config()['db']['mysql'] ?? [];
    $mysqlReady = $wanted === 'mysql'
        && jeab_pdo_has('mysql')
        && trim((string) ($mysql['name'] ?? '')) !== ''
        && trim((string) ($mysql['user'] ?? '')) !== '';

    if ($mysqlReady) {
        return 'mysql';
    }
    if ($wanted !== 'mysql' && jeab_pdo_has('sqlite')) {
        return 'sqlite';
    }
    return 'json';
}

function jeab_store(bool $reload = false): JeabStore
{
    static $store;
    if ($reload) {
        if ($store instanceof JeabPdoStore) {
            $store->disconnect();
        }
        $store = null;
        $GLOBALS['_JEAB_ACTIVE_STORE'] = null;
        $GLOBALS['_JEAB_DISCONNECT'] = false;
    } elseif (!empty($GLOBALS['_JEAB_DISCONNECT'])) {
        if ($store instanceof JeabPdoStore) {
            $store->disconnect();
        }
        $GLOBALS['_JEAB_DISCONNECT'] = false;
        if ($store instanceof JeabStore) {
            $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;

            return $store;
        }
        $GLOBALS['_JEAB_ACTIVE_STORE'] = null;

        return new JeabUnavailableStore();
    }
    if ($store instanceof JeabStore) {
        return $store;
    }
    // json-läget frågar data_backend medan lagret öppnas. Den vägen anropar
    // jeab_store() igen. Utan den här spärren blir det en oändlig öppning.
    if (!empty($GLOBALS['_JEAB_STORE_OPENING'])) {
        return new JeabUnavailableStore();
    }
    $GLOBALS['_JEAB_STORE_OPENING'] = true;
    try {
        $store = jeab_open_store();
    } finally {
        $GLOBALS['_JEAB_STORE_OPENING'] = false;
    }
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;

    return $store;
}

function jeab_stores_disconnect(): void
{
    if (function_exists('jeab_inkorg_pdo_store')) {
        $GLOBALS['_JEAB_INKORG_PDO_DROP'] = true;
        jeab_inkorg_pdo_store();
    }
    $GLOBALS['_JEAB_DISCONNECT'] = true;
    jeab_store();
}

final class JeabUnavailableStore implements JeabStore
{
    public function listReminders(bool $includeCompleted = true): array
    {
        unset($includeCompleted);
        return [];
    }

    public function getReminder(int $id): ?array
    {
        unset($id);
        return null;
    }

    public function insertReminder(array $data): array
    {
        unset($data);
        return [];
    }

    public function saveReminder(int $id, array $fields): array
    {
        unset($id, $fields);
        return [];
    }

    public function incrementReminderTap(int $id, string $field): array
    {
        unset($id, $field);
        throw new InvalidArgumentException('Påminnelsen finns inte.');
    }

    public function deleteReminders(array $ids): int
    {
        unset($ids);
        return 0;
    }

    public function findOpenReminderByBooking(int $bookingId): ?array
    {
        unset($bookingId);
        return null;
    }

    public function findCustomerByName(string $name): ?array
    {
        unset($name);
        return null;
    }

    public function insertCustomer(string $name, string $createdAt): array
    {
        unset($name, $createdAt);
        return [];
    }

    public function listCustomers(): array
    {
        return [];
    }

    public function recentAssignees(int $limit): array
    {
        unset($limit);
        return [];
    }

    public function getBooking(int $id): ?array
    {
        unset($id);
        return null;
    }

    public function bookingsBetween(string $from, string $to, ?string $company = null): array
    {
        unset($from, $to, $company);
        return [];
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        unset($from, $to);
        return [];
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        unset($from, $to);
        return [];
    }

    public function listAllBookings(): array
    {
        return [];
    }

    public function insertBooking(array $data): array
    {
        unset($data);
        return [];
    }

    public function saveBooking(int $id, array $fields): array
    {
        unset($id, $fields);
        return [];
    }

    public function deleteBooking(int $id): bool
    {
        unset($id);
        return false;
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        unset($key);
        return $default;
    }

    public function setSetting(string $key, string $value): void
    {
        unset($key, $value);
    }
}

function jeab_store_sql_fail(?Throwable $previous = null): never
{
    static $logged = false;
    if (!$logged && function_exists('jeab_log_app_error')) {
        $logged = true;
        jeab_log_app_error('store', 'SQL saknas');
    }
    throw new RuntimeException('Databasen är inte öppen.', 0, $previous);
}

function jeab_pdo_store_connect(): PDO
{
    $driver = jeab_driver();
    if ($driver === 'mysql') {
        return jeab_pdo_connect_mysql();
    }
    if ($driver === 'sqlite' && jeab_pdo_has('sqlite')) {
        return jeab_pdo_connect_sqlite();
    }
    if (function_exists('jeab_store_use_sql') && jeab_store_use_sql()) {
        try {
            $pdo = jeab_store_sql_pdo();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        } catch (Throwable $e) {
            jeab_store_sql_fail($e);
        }
    }
    // Injektionspunkt. Produktion definierar inte jeab_store_pdo_override.
    // En PDO härifrån används bara när den vanliga öppningen inte räcker.
    if (function_exists('jeab_store_pdo_override')) {
        $pdo = jeab_store_pdo_override();
        if ($pdo instanceof PDO) {
            return $pdo;
        }
    }
    jeab_store_sql_fail();
}

function jeab_open_store(): JeabStore
{
    $driver = jeab_driver();
    if ($driver === 'mysql') {
        return new JeabPdoStore(jeab_pdo_store_connect());
    }
    if ($driver === 'sqlite' && jeab_pdo_has('sqlite')) {
        try {
            return new JeabPdoStore(jeab_pdo_store_connect());
        } catch (Throwable $e) {
            jeab_store_sql_fail($e);
        }
    }
    if (jeab_store_sql_required()) {
        try {
            $pdo = jeab_store_sql_pdo();
            if ($pdo instanceof PDO) {
                return new JeabPdoStore($pdo);
            }
        } catch (Throwable $e) {
            try {
                return new JeabPdoStore(jeab_pdo_store_connect());
            } catch (Throwable) {
                jeab_store_sql_fail($e);
            }
        }
    }
    try {
        return new JeabPdoStore(jeab_pdo_store_connect());
    } catch (Throwable $e) {
        jeab_store_sql_fail($e);
    }
}

/** Live SQL (mysql eller store_storage=sql) får inte falla tillbaka till store.json. */
function jeab_store_sql_required(): bool
{
    if (jeab_driver() === 'mysql') {
        return true;
    }
    $wanted = strtolower((string) (jeab_config()['db']['driver'] ?? ''));
    if ($wanted === 'mysql') {
        return true;
    }
    if (function_exists('jeab_data_type_read_list')) {
        // Explicit sql-flagga på huvudlagrets typer: ingen reserv till store.json.
        // Läs listan direkt (inte via cachen): butiken håller på att öppnas och settings-läsningen ger null här.
        $forced = jeab_data_type_read_list('sql');
        foreach (['customers', 'bookings', 'reminders', 'reminder_lists'] as $type) {
            if (in_array($type, $forced, true)) {
                return true;
            }
        }
    }
    return function_exists('jeab_store_use_sql') && jeab_store_use_sql();
}

function jeab_sql_limit_clause(?int $limit, int $offset = 0): string
{
    if ($limit === null || $limit <= 0) {
        return '';
    }
    return ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset);
}

/** @param list<array<string, mixed>> $rows */
function jeab_rows_limit(array $rows, ?int $limit, int $offset = 0): array
{
    if ($limit === null || $limit <= 0) {
        return $rows;
    }
    return array_slice(array_values($rows), max(0, $offset), $limit);
}

/**
 * Samma personträff som jeab_reminder_matches_who: id, namn och alias,
 * skiftlägesokänsligt, i person, assignee-texten och JSON-listan.
 * Okänd person ger ingen rad. B-024: villkoret får inte vara ett övermått
 * när sidan begränsar med LIMIT.
 *
 * @return array{0:string,1:list<string>}
 */
function jeab_reminder_assignee_sql(PDO $pdo, string $person, ?string $company): array
{
    $found = function_exists('jeab_person_by_name') ? jeab_person_by_name($person, $company) : null;
    if (!is_array($found) && function_exists('jeab_reminder_person_record')) {
        $found = jeab_reminder_person_record($person, $company);
    }
    if (!is_array($found) && function_exists('jeab_reminder_person_record')) {
        $found = jeab_reminder_person_record($person, null);
    }
    $aliases = is_array($found) && function_exists('jeab_person_aliases') ? jeab_person_aliases($found) : [];
    $name = is_array($found) ? trim((string) ($found['name'] ?? '')) : '';
    $id = is_array($found) ? trim((string) ($found['id'] ?? '')) : '';
    foreach ([$name, $id, $person] as $one) {
        $one = trim($one);
        if ($one !== '' && !in_array($one, $aliases, true)) {
            $aliases[] = $one;
        }
    }
    if ($aliases === []) {
        return ['0', []];
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $parts = [];
    $args = [];
    foreach ($aliases as $alias) {
        $parts[] = "LOWER(IFNULL(person, '')) = LOWER(?)";
        $args[] = $alias;
        if ($driver === 'mysql') {
            $parts[] = "INSTR(LOWER(CONCAT(',', REPLACE(IFNULL(assignee, ''), ', ', ','), ',')), CONCAT(',', LOWER(?), ',')) > 0";
            $args[] = $alias;
            $parts[] = "JSON_SEARCH(LOWER(IF(JSON_VALID(assignees), assignees, '[]')), 'one', LOWER(?), NULL, '$') IS NOT NULL";
            $args[] = $alias;
            $parts[] = "INSTR(LOWER(IFNULL(assignees, '')), LOWER(?)) > 0";
            $args[] = '"' . $alias . '"';
        } else {
            $parts[] = "instr(lower(',' || replace(IFNULL(assignee, ''), ', ', ',') || ','), ',' || lower(?) || ',') > 0";
            $args[] = $alias;
            $parts[] = "EXISTS (SELECT 1 FROM json_each(CASE WHEN json_valid(assignees) THEN assignees ELSE '[]' END) WHERE lower(value) = lower(?))";
            $args[] = $alias;
            $parts[] = "instr(lower(IFNULL(assignees, '')), lower(?)) > 0";
            $args[] = '"' . $alias . '"';
        }
    }

    return ['(' . implode(' OR ', $parts) . ')', $args];
}

function jeab_reminder_list_columns(bool $withNote = true, ?PDO $pdo = null): string
{
    $note = $withNote ? 'note, ' : '';
    $copy = $pdo instanceof PDO && function_exists('jeab_copy_select')
        ? jeab_copy_select($pdo, 'reminders', jeab_copy_columns('reminders'))
        : 'customer_name, email, phone, address, ort, postal';

    return 'id, list_id, title, ' . $copy . ', assignee, assignees, company, person, flagged, tags, ' . $note . 'due_at, completed_at, booking_id, contact_id, thread_id, created_at, updated_at, ringt, pratat, mailat, smsat';
}

function jeab_hydrate_reminder(array $row): array
{
    $row['company'] = jeab_reminder_company($row);
    $names = jeab_assignees_from_row($row);
    return [
        'id' => (int) $row['id'],
        'list_id' => (int) $row['list_id'],
        'title' => (string) $row['title'],
        'customer_name' => !empty($row['customer_name']) ? (string) $row['customer_name'] : null,
        'email' => !empty($row['email']) ? (string) $row['email'] : null,
        'phone' => !empty($row['phone']) ? (string) $row['phone'] : null,
        'address' => !empty($row['address']) ? (string) $row['address'] : null,
        'ort' => array_key_exists('ort', $row) ? ($row['ort'] === null ? null : trim((string) $row['ort'])) : null,
        'postal' => isset($row['postal']) && trim((string) $row['postal']) !== '' ? trim((string) $row['postal']) : null,
        'assignees' => $names,
        'assignee' => $names ? jeab_assignees_join($names) : null,
        'company' => $row['company'],
        'person' => jeab_reminder_person_id($row),
        'flagged' => !empty($row['flagged']),
        'tags' => jeab_parse_tags($row['tags'] ?? []),
        'note' => isset($row['note']) && $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
        'due_at' => !empty($row['due_at']) ? (string) $row['due_at'] : null,
        'completed_at' => !empty($row['completed_at']) ? (string) $row['completed_at'] : null,
        'booking_id' => $row['booking_id'] !== null && $row['booking_id'] !== '' ? (int) $row['booking_id'] : null,
        'contact_id' => isset($row['contact_id']) && $row['contact_id'] !== null && trim((string) $row['contact_id']) !== '' ? trim((string) $row['contact_id']) : null,
        'thread_id' => isset($row['thread_id']) && trim((string) $row['thread_id']) !== '' ? trim((string) $row['thread_id']) : null,
        'deleted_at' => isset($row['deleted_at']) && trim((string) $row['deleted_at']) !== '' ? trim((string) $row['deleted_at']) : null,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
        'ringt' => max(0, (int) ($row['ringt'] ?? 0)),
        'pratat' => max(0, (int) ($row['pratat'] ?? 0)),
        'mailat' => max(0, (int) ($row['mailat'] ?? 0)),
        'smsat' => max(0, (int) ($row['smsat'] ?? 0)),
    ];
}

function jeab_hydrate_booking(array $row): array
{
    $names = jeab_assignees_from_row($row);
    return [
        'id' => (int) $row['id'],
        'customer_id' => (int) $row['customer_id'],
        'customer_name' => (string) ($row['customer_name'] ?? ''),
        'email' => !empty($row['email']) ? (string) $row['email'] : null,
        'phone' => !empty($row['phone']) ? (string) $row['phone'] : null,
        'address' => !empty($row['address']) ? (string) $row['address'] : null,
        'notes' => !empty($row['notes']) ? (string) $row['notes'] : null,
        'assignees' => $names,
        'assignee' => $names ? jeab_assignees_join($names) : '',
        'company' => jeab_booking_company($row),
        'job_type' => !empty($row['job_type']) ? (string) $row['job_type'] : null,
        'starts_at' => (string) $row['starts_at'],
        'ends_at' => (string) $row['ends_at'],
        'status' => (string) $row['status'],
        'contact_id' => isset($row['contact_id']) && $row['contact_id'] !== null && trim((string) $row['contact_id']) !== '' ? trim((string) $row['contact_id']) : null,
        'created_at' => (string) $row['created_at'],
        'updated_at' => (string) $row['updated_at'],
    ];
}

function jeab_json_path(): string
{
    $configured = (string) (jeab_config()['db']['json_path'] ?? '');
    if ($configured !== '') {
        return $configured;
    }
    return jeab_store_json_default_path();
}

function jeab_data_dir_ready(string $path): string
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
    }
    return $dir;
}

/**
 * Nästa påminnelse-id i JSON-läget. Räknaren går bara upp, även när en rad
 * raderats, och hoppar över id som redan finns. Befintliga rader rörs inte.
 *
 * @param array<string, mixed> $store
 */
function jeab_json_reminder_alloc_id(array &$store): int
{
    if (!isset($store['next_id']) || !is_array($store['next_id'])) {
        $store['next_id'] = [];
    }
    $claimed = (int) ($store['next_id']['reminders'] ?? 0);
    $highest = 0;
    $rows = $store['reminders'] ?? [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $have = (int) ($row['id'] ?? 0);
            if ($have > $highest) {
                $highest = $have;
            }
        }
    }
    $id = max($claimed, $highest + 1, 1);
    $store['next_id']['reminders'] = $id + 1;

    return $id;
}

final class JeabJsonStore implements JeabStore
{
    private ?array $readMem = null;

    private string $readMemKey = '';

    public function __construct(private string $path)
    {
        if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('store')) {
            return;
        }
        jeab_data_dir_ready($this->path);
        if (!is_file($this->path)) {
            $this->write($this->emptyData());
        }
        if (!is_readable($this->path) || !is_writable($this->path)) {
            throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
        }
    }

    public function listReminders(bool $includeCompleted = true, ?int $limit = null, int $offset = 0): array
    {
        $listId = $this->defaultListId();
        $rows = array_values(array_filter(
            $this->read()['reminders'],
            static function (array $row) use ($includeCompleted, $listId): bool {
                if ((int) $row['list_id'] !== $listId) {
                    return false;
                }
                return $includeCompleted || empty($row['completed_at']);
            }
        ));
        usort($rows, static function (array $a, array $b): int {
            $ac = empty($a['completed_at']) ? 0 : 1;
            $bc = empty($b['completed_at']) ? 0 : 1;
            if ($ac !== $bc) {
                return $ac <=> $bc;
            }
            $ad = $a['due_at'] ? 0 : 1;
            $bd = $b['due_at'] ? 0 : 1;
            if ($ad !== $bd) {
                return $ad <=> $bd;
            }
            if ($a['due_at'] !== $b['due_at']) {
                return strcmp((string) $a['due_at'], (string) $b['due_at']);
            }
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });
        $rows = jeab_rows_limit($rows, $limit, $offset);
        return array_map([$this, 'normalizeReminder'], $rows);
    }

    /** Alla listor och alla statusar. Papperskorg filtreras av anroparen. */
    public function listEveryReminder(?int $limit = null, int $offset = 0): array
    {
        $rows = [];
        foreach ($this->read()['reminders'] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $rows[] = $row;
            }
        }
        $rows = jeab_rows_limit($rows, $limit, $offset);
        return array_map([$this, 'normalizeReminder'], $rows);
    }

    public function getReminder(int $id): ?array
    {
        foreach ($this->read()['reminders'] as $row) {
            if ((int) $row['id'] === $id) {
                return $this->normalizeReminder($row);
            }
        }
        return null;
    }

    public function insertReminder(array $data): array
    {
        return $this->mutate(function (array &$store) use ($data): array {
            $id = jeab_json_reminder_alloc_id($store);
            $company = jeab_normalize_company($data['company'] ?? null) ?? 'jarfalla';
            $names = jeab_parse_assignees($data['assignees'] ?? $data['assignee'] ?? [], $company);
            $person = trim((string) ($data['person'] ?? ''));
            $row = [
                'id' => $id,
                'list_id' => $this->defaultListIdFrom($store),
                'title' => $data['title'],
                'customer_name' => $data['customer_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'ort' => array_key_exists('ort', $data) ? ($data['ort'] === null ? null : trim((string) $data['ort'])) : null,
                'postal' => isset($data['postal']) && trim((string) $data['postal']) !== '' ? trim((string) $data['postal']) : null,
                'assignees' => $names,
                'assignee' => $names ? jeab_assignees_join($names) : null,
                'company' => $company,
                'person' => $person !== '' ? $person : null,
                'flagged' => !empty($data['flagged']),
                'tags' => jeab_parse_tags($data['tags'] ?? []),
                'note' => $data['note'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'completed_at' => $data['completed_at'] ?? null,
                'booking_id' => $data['booking_id'] ?? null,
                'thread_id' => isset($data['thread_id']) && trim((string) $data['thread_id']) !== '' ? trim((string) $data['thread_id']) : null,
                'created_at' => $data['created_at'],
                'updated_at' => $data['updated_at'],
                'ringt' => max(0, (int) ($data['ringt'] ?? 0)),
                'pratat' => max(0, (int) ($data['pratat'] ?? 0)),
                'mailat' => max(0, (int) ($data['mailat'] ?? 0)),
                'smsat' => max(0, (int) ($data['smsat'] ?? 0)),
            ];
            $store['reminders'][] = $row;
            return $this->normalizeReminder($row);
        });
    }

    public function saveReminder(int $id, array $fields): array
    {
        return $this->mutate(function (array &$store) use ($id, $fields): array {
            foreach ($store['reminders'] as $i => $row) {
                if ((int) $row['id'] !== $id) {
                    continue;
                }
                $store['reminders'][$i] = array_merge($row, $fields);
                return $this->normalizeReminder($store['reminders'][$i]);
            }
            throw new InvalidArgumentException('Påminnelsen finns inte.');
        });
    }

    public function incrementReminderTap(int $id, string $field): array
    {
        if (!in_array($field, ['ringt', 'pratat', 'mailat', 'smsat'], true)) {
            throw new InvalidArgumentException('Okänd knapp.');
        }
        return $this->mutate(function (array &$store) use ($id, $field): array {
            foreach ($store['reminders'] as $i => $row) {
                if ((int) $row['id'] !== $id) {
                    continue;
                }
                $row[$field] = max(0, (int) ($row[$field] ?? 0)) + 1;
                $row['updated_at'] = jeab_now();
                $store['reminders'][$i] = $row;
                return $this->normalizeReminder($row);
            }
            throw new InvalidArgumentException('Påminnelsen finns inte.');
        });
    }

    /** Ångra en stämpel: räknaren ner ett steg, aldrig under noll. */
    public function decrementReminderTap(int $id, string $field): array
    {
        if (!in_array($field, ['ringt', 'pratat', 'mailat', 'smsat'], true)) {
            throw new InvalidArgumentException('Okänd knapp.');
        }
        return $this->mutate(function (array &$store) use ($id, $field): array {
            foreach ($store['reminders'] as $i => $row) {
                if ((int) $row['id'] !== $id) {
                    continue;
                }
                $row[$field] = max(0, (int) ($row[$field] ?? 0) - 1);
                $row['updated_at'] = jeab_now();
                $store['reminders'][$i] = $row;
                return $this->normalizeReminder($row);
            }
            throw new InvalidArgumentException('Påminnelsen finns inte.');
        });
    }

    public function deleteReminders(array $ids): int
    {
        $want = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $want[$id] = true;
            }
        }
        if ($want === []) {
            return 0;
        }
        return $this->mutate(function (array &$store) use ($want): int {
            $kept = [];
            $deleted = 0;
            foreach ($store['reminders'] as $row) {
                if (isset($want[(int) $row['id']])) {
                    $deleted++;
                    continue;
                }
                $kept[] = $row;
            }
            $store['reminders'] = $kept;
            return $deleted;
        });
    }

    public function findOpenReminderByBooking(int $bookingId): ?array
    {
        foreach ($this->read()['reminders'] as $row) {
            if ((int) ($row['booking_id'] ?? 0) === $bookingId && empty($row['completed_at'])) {
                return $this->normalizeReminder($row);
            }
        }
        return null;
    }

    public function findCustomerByName(string $name): ?array
    {
        foreach ($this->read()['customers'] as $row) {
            if ($row['name'] === $name) {
                return $this->normalizeCustomer($row);
            }
        }
        return null;
    }

    public function insertCustomer(string $name, string $createdAt): array
    {
        return $this->mutate(function (array &$store) use ($name, $createdAt): array {
            foreach ($store['customers'] as $row) {
                if ($row['name'] === $name) {
                    return $this->normalizeCustomer($row);
                }
            }
            $id = $store['next_id']['customers']++;
            $row = ['id' => $id, 'name' => $name, 'created_at' => $createdAt];
            $store['customers'][] = $row;
            return $this->normalizeCustomer($row);
        });
    }

    public function listCustomers(?int $limit = null, int $offset = 0): array
    {
        $rows = [];
        foreach ($this->read()['customers'] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        $rows = jeab_rows_limit($rows, $limit, $offset);
        return array_map([$this, 'normalizeCustomer'], $rows);
    }

    public function recentAssignees(int $limit): array
    {
        $latest = [];
        $data = $this->read();
        foreach (['bookings', 'reminders'] as $table) {
            foreach ($data[$table] as $row) {
                $name = trim((string) ($row['assignee'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $at = (string) ($row['updated_at'] ?? '');
                if (!isset($latest[$name]) || $at > $latest[$name]) {
                    $latest[$name] = $at;
                }
            }
        }
        arsort($latest);
        return array_slice(array_keys($latest), 0, $limit);
    }

    public function getBooking(int $id): ?array
    {
        if (isset($GLOBALS['_JEAB_GET_BOOKING_CALLS'])) {
            $GLOBALS['_JEAB_GET_BOOKING_CALLS']++;
        }
        foreach ($this->read()['bookings'] as $row) {
            if ((int) $row['id'] === $id) {
                return $this->normalizeBooking($row);
            }
        }
        return null;
    }

    public function bookingsBetween(string $from, string $to, ?string $company = null): array
    {
        unset($company);
        if (isset($GLOBALS['_JEAB_BOOKINGS_BETWEEN_CALLS'])) {
            $GLOBALS['_JEAB_BOOKINGS_BETWEEN_CALLS']++;
        }
        $rows = [];
        foreach ($this->read()['bookings'] as $row) {
            if ($row['status'] !== 'booked') {
                continue;
            }
            $starts = (string) ($row['starts_at'] ?? '');
            $ends = (string) ($row['ends_at'] ?? '');
            if ($ends === '' || $ends < $starts) {
                $ends = $starts;
            }
            if ($starts < $to && $ends > $from) {
                $rows[] = $this->normalizeBooking($row);
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['starts_at'], (string) $b['starts_at']));
        return $rows;
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        $rows = [];
        foreach ($this->read()['bookings'] as $row) {
            if ($row['status'] !== 'booked') {
                continue;
            }
            if ($row['created_at'] >= $from && $row['created_at'] < $to) {
                $rows[] = $this->normalizeBooking($row);
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));
        return $rows;
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        $rows = [];
        foreach ($this->read()['bookings'] as $row) {
            if ($row['status'] !== 'booked') {
                continue;
            }
            $updated = (string) ($row['updated_at'] ?? '');
            if ($updated !== '' && $updated >= $from && $updated < $to) {
                $rows[] = $this->normalizeBooking($row);
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['updated_at'], (string) $b['updated_at']));
        return $rows;
    }

    public function listAllBookings(?int $limit = null, int $offset = 0): array
    {
        $rows = [];
        foreach ($this->read()['bookings'] as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $rows[] = $this->normalizeBooking($row);
            }
        }
        usort($rows, static fn(array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        return jeab_rows_limit($rows, $limit, $offset);
    }

    public function insertBooking(array $data): array
    {
        return $this->mutate(function (array &$store) use ($data): array {
            $id = $store['next_id']['bookings']++;
            $row = [
                'id' => $id,
                'customer_id' => $data['customer_id'],
                'customer_name' => $data['customer_name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'notes' => $data['notes'] ?? null,
                'assignees' => jeab_parse_assignees($data['assignees'] ?? $data['assignee'] ?? []),
                'assignee' => jeab_assignees_join(jeab_parse_assignees($data['assignees'] ?? $data['assignee'] ?? [])),
                'company' => jeab_normalize_company($data['company'] ?? null) ?? 'jarfalla',
                'job_type' => $data['job_type'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'status' => $data['status'] ?? 'booked',
                'contact_id' => isset($data['contact_id']) && trim((string) $data['contact_id']) !== '' ? trim((string) $data['contact_id']) : null,
                'created_at' => $data['created_at'],
                'updated_at' => $data['updated_at'],
            ];
            $store['bookings'][] = $row;
            return $this->normalizeBooking($row);
        });
    }

    public function saveBooking(int $id, array $fields): array
    {
        if (function_exists('jeab_booking_cache_forget')) {
            jeab_booking_cache_forget($id);
        }
        return $this->mutate(function (array &$store) use ($id, $fields): array {
            foreach ($store['bookings'] as $i => $row) {
                if ((int) $row['id'] !== $id) {
                    continue;
                }
                $store['bookings'][$i] = array_merge($row, $fields);
                return $this->normalizeBooking($store['bookings'][$i]);
            }
            throw new InvalidArgumentException('Bokningen finns inte.');
        });
    }

    /** Tar bort en enda bokningsrad. Inga sidoeffekter (mejl, sms, Outlook, logg). */
    public function deleteBooking(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        if (function_exists('jeab_ordrar_v3_steps_forget')) {
            jeab_ordrar_v3_steps_forget();
        }
        return $this->mutate(function (array &$store) use ($id): bool {
            foreach ($store['bookings'] as $i => $row) {
                if ((int) $row['id'] === $id) {
                    array_splice($store['bookings'], $i, 1);
                    return true;
                }
            }
            return false;
        });
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $settings = $this->read()['settings'];
        if (!array_key_exists($key, $settings)) {
            return $default;
        }
        return (string) $settings[$key];
    }

    public function setSetting(string $key, string $value): void
    {
        $this->mutate(function (array &$store) use ($key, $value): true {
            $store['settings'][$key] = $value;
            return true;
        });
    }

    private function defaultListId(): int
    {
        return $this->defaultListIdFrom($this->read());
    }

    private function defaultListIdFrom(array $store): int
    {
        foreach ($store['reminder_lists'] as $row) {
            return (int) $row['id'];
        }
        return 1;
    }

    private function emptyData(): array
    {
        return [
            'next_id' => [
                'reminder_lists' => 2,
                'customers' => 1,
                'bookings' => 1,
                'reminders' => 1,
            ],
            'reminder_lists' => [
                ['id' => 1, 'name' => 'Påminnelser', 'created_at' => jeab_now()],
            ],
            'customers' => [],
            'bookings' => [],
            'reminders' => [],
            'settings' => [],
        ];
    }

    private function normalizeReminder(array $row): array
    {
        return jeab_hydrate_reminder($row);
    }

    private function normalizeBooking(array $row): array
    {
        return jeab_hydrate_booking($row);
    }

    private function normalizeCustomer(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function readKey(): string
    {
        $req = (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '0');
        clearstatcache(true, $this->path);
        if (!is_file($this->path)) {
            return $req . '|missing';
        }

        return $req . '|' . (int) filemtime($this->path) . '|' . (int) filesize($this->path);
    }

    private function read(): array
    {
        if (!is_file($this->path) && function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip('store')) {
            return $this->emptyData();
        }
        $key = $this->readKey();
        if ($this->readMem !== null && $this->readMemKey === $key) {
            return $this->readMem;
        }
        $fp = $this->open();
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($data) ? array_replace_recursive($this->emptyData(), $data) : $this->emptyData();
        $this->readMem = $data;
        $this->readMemKey = $key;

        return $data;
    }

    private function mutate(callable $fn): mixed
    {
        if (function_exists('jeab_data_write_guard')) {
            // Flaggan cachas i jeab_data_maintenance(), så skrivningen
            // inte läser om underhållsläget från databasen.
            jeab_data_write_guard();
        }
        // Skrivtransaktionen. Avstängd JSON-store kastar i stället för att
        // köra ändringen och slänga den.
        $this->refuseClosedJsonStore();
        $this->readMem = null;
        $this->readMemKey = '';
        $fp = $this->open();
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
        }
        $raw = stream_get_contents($fp);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $store = is_array($data) ? array_replace_recursive($this->emptyData(), $data) : $this->emptyData();
        try {
            $result = $fn($store);
            $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
            }
            rewind($fp);
            if (ftruncate($fp, 0) === false || fwrite($fp, $json) === false) {
                throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
            }
            fflush($fp);
            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function write(array $data): void
    {
        $this->readMem = null;
        $this->readMemKey = '';
        $this->refuseClosedJsonStore();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
        }
    }

    /**
     * Flaggan av och store i SQL-läge: ingen tyst no-op.
     * App-errors får bara fil och rad för anropet, ingen data.
     */
    private function refuseClosedJsonStore(): void
    {
        if (!function_exists('jeab_legacy_json_skip') || !jeab_legacy_json_skip('store')) {
            return;
        }
        if (empty($GLOBALS['_JEAB_JSON_STORE_REFUSING'])) {
            $GLOBALS['_JEAB_JSON_STORE_REFUSING'] = true;
            try {
                $this->logClosedJsonStore();
            } catch (Throwable) {
            } finally {
                unset($GLOBALS['_JEAB_JSON_STORE_REFUSING']);
            }
        }
        throw new RuntimeException('JSON-store är avstängd, använd SQL');
    }

    private function logClosedJsonStore(): void
    {
        if (!function_exists('jeab_log_app_error')) {
            return;
        }
        $file = 'store.php';
        $line = 0;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $fn = (string) ($frame['function'] ?? '');
            if ($fn === 'logClosedJsonStore' || $fn === 'refuseClosedJsonStore' || $fn === 'mutate' || $fn === 'write') {
                continue;
            }
            $raw = (string) ($frame['file'] ?? '');
            if ($raw === '') {
                continue;
            }
            $file = basename($raw);
            $line = (int) ($frame['line'] ?? 0);
            break;
        }
        jeab_log_app_error('json-store', $file . ':' . $line, [
            'skip_request' => true,
            'no_webhook' => true,
        ]);
    }

    /** @return resource */
    private function open()
    {
        $fp = @fopen($this->path, 'c+');
        if ($fp === false) {
            throw new RuntimeException('Kunde inte spara i data/. På webbhotellet: chmod 775 data (eller 777 om 775 inte räcker).');
        }
        return $fp;
    }
}

final class JeabPdoStore implements JeabStore
{
    private bool $reopen = false;

    private ?int $defaultListId = null;

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function hasPdo(): bool
    {
        return $this->pdo instanceof PDO;
    }

    public function holdsPdo(PDO $pdo): bool
    {
        return $this->pdo === $pdo;
    }

    public function adoptPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
        $this->reopen = false;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        if ($this->reopen) {
            throw new RuntimeException('Databasen är inte öppen.');
        }
        $this->reopen = true;
        try {
            $this->pdo = jeab_pdo_store_connect();
        } finally {
            $this->reopen = false;
        }

        return $this->pdo;
    }

    public function listReminders(bool $includeCompleted = true, ?int $limit = null, int $offset = 0, bool $withNote = true): array
    {
        $sql = 'SELECT ' . jeab_reminder_list_columns($withNote, $this->pdo()) . ' FROM reminders WHERE list_id = ? AND id > 0';
        if (!$includeCompleted) {
            $sql .= ' AND completed_at IS NULL';
        }
        $sql .= ' ORDER BY CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END,
            CASE WHEN due_at IS NULL THEN 1 ELSE 0 END,
            due_at ASC,
            created_at DESC';
        $sql .= jeab_sql_limit_clause($limit, $offset);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$this->defaultListId()]);
        return array_map('jeab_hydrate_reminder', $stmt->fetchAll());
    }

    /**
     * Listan filtrerar bolag och person i SQL. Personvillkoret följer
     * jeab_reminder_matches_who. PHP-filtret efteråt är facit, och LIMIT
     * appliceras först då.
     *
     * @return list<array<string, mixed>>
     */
    public function listRemindersFiltered(bool $includeCompleted, ?string $company, ?string $person, ?int $limit = null, int $offset = 0, bool $withNote = true): array
    {
        $sql = 'SELECT ' . jeab_reminder_list_columns($withNote, $this->pdo()) . ' FROM reminders WHERE list_id = ? AND id > 0';
        $args = [$this->defaultListId()];
        if (!$includeCompleted) {
            $sql .= ' AND completed_at IS NULL';
        }
        if ($company !== null && $company !== '') {
            if ($company === 'jarfalla') {
                $sql .= " AND (company = ? OR company IS NULL OR company = '')";
            } else {
                $sql .= ' AND company = ?';
            }
            $args[] = $company;
        }
        if ($person !== null && $person !== '') {
            [$whoSql, $whoArgs] = jeab_reminder_assignee_sql($this->pdo(), $person, $company);
            $sql .= ' AND ' . $whoSql;
            array_push($args, ...$whoArgs);
        }
        $sql .= ' ORDER BY CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END,
            CASE WHEN due_at IS NULL THEN 1 ELSE 0 END,
            due_at ASC,
            created_at DESC';
        // Personfiltret i PHP är facit. LIMIT före det filtret kan tappa en rad
        // som SQL släppte in men jeab_reminder_matches_who släpper ut (B-024).
        $sliceLimit = $limit;
        $sliceOffset = $offset;
        if ($person !== null && $person !== '') {
            $sliceLimit = $limit;
            $sliceOffset = $offset;
            $limit = null;
            $offset = 0;
        }
        $sql .= jeab_sql_limit_clause($limit, $offset);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = array_map('jeab_hydrate_reminder', $stmt->fetchAll());
        if ($person !== null && $person !== '' && function_exists('jeab_reminder_matches_who')) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => jeab_reminder_matches_who($row, $person)
            ));
            if ($sliceLimit !== null && $sliceLimit > 0) {
                $rows = array_slice($rows, max(0, $sliceOffset), $sliceLimit);
            }
        }
        return $rows;
    }

    /**
     * Full list columns for the ids on the visible page.
     * The company list can omit note; this puts it back on those rows only.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function reminderRowsByIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $out = [];
        foreach (array_chunk(array_values($clean), 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo()->prepare('SELECT ' . jeab_reminder_list_columns(true, $this->pdo()) . ' FROM reminders WHERE id IN (' . $marks . ')');
            $stmt->execute($chunk);
            $fetched = $stmt->fetchAll();
            foreach (is_array($fetched) ? $fetched : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $hydrated = jeab_hydrate_reminder($row);
                $out[(int) $hydrated['id']] = $hydrated;
            }
        }

        return $out;
    }

    /** @return list<array{id:int,completed_at:string}> */
    public function completedReminderIds(): array
    {
        $rows = $this->pdo()->query('SELECT id, completed_at FROM reminders WHERE completed_at IS NOT NULL')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'completed_at' => (string) ($row['completed_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** Alla listor och alla statusar. Papperskorg filtreras av anroparen. */
    public function listEveryReminder(?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM reminders WHERE id > 0';
        $sql .= jeab_sql_limit_clause($limit, $offset);
        $rows = $this->pdo()->query($sql)->fetchAll();
        return array_map('jeab_hydrate_reminder', is_array($rows) ? $rows : []);
    }

    public function getReminder(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM reminders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? jeab_hydrate_reminder($row) : null;
    }

    public function insertReminder(array $data): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        if (!function_exists('jeab_contacts_copies_apply_reminder') && is_file(__DIR__ . '/contacts_copies.php')) {
            require_once __DIR__ . '/contacts_copies.php';
        }
        if (function_exists('jeab_contacts_copies_apply_reminder')) {
            $data = jeab_contacts_copies_apply_reminder($data);
        }
        $contactId = isset($data['contact_id']) && trim((string) $data['contact_id']) !== '' ? trim((string) $data['contact_id']) : null;
        $company = jeab_normalize_company($data['company'] ?? null) ?? 'jarfalla';
        $names = jeab_parse_assignees($data['assignees'] ?? $data['assignee'] ?? [], $company);
        $tags = jeab_parse_tags($data['tags'] ?? []);
        $person = trim((string) ($data['person'] ?? ''));
        $columns = ['list_id', 'title', 'customer_name', 'email', 'phone', 'address', 'ort', 'postal', 'assignee', 'assignees', 'company', 'person', 'flagged', 'tags', 'note', 'due_at', 'completed_at', 'booking_id'];
        $values = [
            $this->defaultListId(),
            $data['title'],
            $data['customer_name'] ?? null,
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            array_key_exists('ort', $data) && $data['ort'] !== null && trim((string) $data['ort']) !== '' ? trim((string) $data['ort']) : null,
            isset($data['postal']) && trim((string) $data['postal']) !== '' ? trim((string) $data['postal']) : null,
            $names ? jeab_assignees_join($names) : null,
            jeab_assignees_json($names),
            $company,
            $person !== '' ? $person : null,
            !empty($data['flagged']) ? 1 : 0,
            $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            $data['note'] ?? null,
            $data['due_at'] ?? null,
            $data['completed_at'] ?? null,
            $data['booking_id'] ?? null,
        ];
        if (jeab_sql_has_column($this->pdo(), 'reminders', 'contact_id')) {
            $columns[] = 'contact_id';
            $values[] = $contactId;
        }
        $columns[] = 'thread_id';
        $columns[] = 'created_at';
        $columns[] = 'updated_at';
        $values[] = isset($data['thread_id']) && trim((string) $data['thread_id']) !== '' ? trim((string) $data['thread_id']) : null;
        $values[] = trim((string) ($data['created_at'] ?? '')) !== '' ? $data['created_at'] : jeab_now();
        $values[] = trim((string) ($data['updated_at'] ?? '')) !== '' ? $data['updated_at'] : jeab_now();
        [$columns, $values] = jeab_copy_filter_write($this->pdo(), 'reminders', $columns, $values);
        $id = jeab_sql_insert_id($this->pdo(), 'reminders', $columns, $values);
        // Id läses inuti jeab_sql_insert_id, före räknecachen. I MySQL nollar nästa
        // sats (t.ex. SELECT DATABASE() i räknecachens scope) lastInsertId.
        if (function_exists('jeab_reminders_count_cache_forget')) {
            jeab_reminders_count_cache_forget($this->pdo());
        }
        $row = $id > 0 ? $this->getReminder($id) : null;
        if (!is_array($row)) {
            throw new RuntimeException('Påminnelsen sparades men kunde inte läsas tillbaka (id ' . $id . ').');
        }

        return $row;
    }

    public function saveReminder(int $id, array $fields): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $pdo = $this->pdo();
        $own = !jeab_pdo_in_tx($pdo);
        if ($own) {
            jeab_pdo_begin_immediate($pdo); // BEGIN IMMEDIATE: skrivlås innan raden läses om
        }
        try {
        $row = $this->getReminder($id);
        if (!$row) {
            throw new InvalidArgumentException('Påminnelsen finns inte.');
        }
        $merged = array_merge($row, $fields);
        if (!function_exists('jeab_contacts_copies_apply_reminder') && is_file(__DIR__ . '/contacts_copies.php')) {
            require_once __DIR__ . '/contacts_copies.php';
        }
        if (function_exists('jeab_contacts_copies_apply_reminder')) {
            $merged = jeab_contacts_copies_apply_reminder($merged);
        }
        $names = jeab_assignees_from_row($merged);
        $tags = jeab_parse_tags($merged['tags'] ?? []);
        $person = trim((string) ($merged['person'] ?? ''));
        $updateCols = ['title', 'customer_name', 'email', 'phone', 'address', 'ort', 'postal', 'assignee', 'assignees', 'company', 'person', 'flagged', 'tags', 'note', 'thread_id', 'due_at', 'completed_at', 'created_at', 'updated_at', 'ringt', 'pratat', 'mailat', 'smsat'];
        $updateVals = [
            $merged['title'],
            $merged['customer_name'] ?? null,
            $merged['email'] ?? null,
            $merged['phone'] ?? null,
            $merged['address'] ?? null,
            $merged['ort'] ?? null,
            isset($merged['postal']) && trim((string) $merged['postal']) !== '' ? trim((string) $merged['postal']) : null,
            $names ? jeab_assignees_join($names) : null,
            jeab_assignees_json($names),
            jeab_reminder_company($merged),
            $person !== '' ? $person : null,
            !empty($merged['flagged']) ? 1 : 0,
            $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
            $merged['note'],
            isset($merged['thread_id']) && trim((string) $merged['thread_id']) !== '' ? trim((string) $merged['thread_id']) : null,
            $merged['due_at'],
            $merged['completed_at'],
            trim((string) ($merged['created_at'] ?? '')) !== '' ? (string) $merged['created_at'] : jeab_now(),
            $merged['updated_at'] ?? jeab_now(),
            max(0, (int) ($merged['ringt'] ?? 0)),
            max(0, (int) ($merged['pratat'] ?? 0)),
            max(0, (int) ($merged['mailat'] ?? 0)),
            max(0, (int) ($merged['smsat'] ?? 0)),
        ];
        // JSON-lagret slog ihop hela raden, så contact_id följde med saveReminder.
        // SQL skrev inte kolumnen och länken försvann vid varje uppdatering.
        if (jeab_sql_has_column($this->pdo(), 'reminders', 'contact_id')) {
            $contact = $merged['contact_id'] ?? null;
            $updateCols[] = 'contact_id';
            $updateVals[] = $contact !== null && trim((string) $contact) !== '' ? trim((string) $contact) : null;
        }
        [$updateCols, $updateVals] = jeab_copy_filter_write($this->pdo(), 'reminders', $updateCols, $updateVals);
        $updateVals[] = $id;
        $this->pdo()->prepare(
            'UPDATE reminders SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $updateCols)) . ' WHERE id = ?'
        )->execute($updateVals);
        $fresh = $this->getReminder($id);
        if ($own) {
            jeab_pdo_end($pdo, true);
        }
        if (function_exists('jeab_reminders_count_cache_forget')) {
            jeab_reminders_count_cache_forget($pdo);
        }
        return $fresh;
        } catch (Throwable $e) {
            if ($own) {
                try {
                    jeab_pdo_end($pdo, false);
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }

    public function incrementReminderTap(int $id, string $field): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $sql = match ($field) {
            'ringt' => 'UPDATE reminders SET ringt = ringt + 1, updated_at = ? WHERE id = ?',
            'pratat' => 'UPDATE reminders SET pratat = pratat + 1, updated_at = ? WHERE id = ?',
            'mailat' => 'UPDATE reminders SET mailat = mailat + 1, updated_at = ? WHERE id = ?',
            'smsat' => 'UPDATE reminders SET smsat = smsat + 1, updated_at = ? WHERE id = ?',
            default => throw new InvalidArgumentException('Okänd knapp.'),
        };
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([jeab_now(), $id]);
        $row = $this->getReminder($id);
        if (!$row) {
            throw new InvalidArgumentException('Påminnelsen finns inte.');
        }
        return $row;
    }

    /** Ångra en stämpel: räknaren ner ett steg, aldrig under noll. */
    public function decrementReminderTap(int $id, string $field): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $sql = match ($field) {
            'ringt' => 'UPDATE reminders SET ringt = CASE WHEN ringt > 0 THEN ringt - 1 ELSE 0 END, updated_at = ? WHERE id = ?',
            'pratat' => 'UPDATE reminders SET pratat = CASE WHEN pratat > 0 THEN pratat - 1 ELSE 0 END, updated_at = ? WHERE id = ?',
            'mailat' => 'UPDATE reminders SET mailat = CASE WHEN mailat > 0 THEN mailat - 1 ELSE 0 END, updated_at = ? WHERE id = ?',
            'smsat' => 'UPDATE reminders SET smsat = CASE WHEN smsat > 0 THEN smsat - 1 ELSE 0 END, updated_at = ? WHERE id = ?',
            default => throw new InvalidArgumentException('Okänd knapp.'),
        };
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([jeab_now(), $id]);
        $row = $this->getReminder($id);
        if (!$row) {
            throw new InvalidArgumentException('Påminnelsen finns inte.');
        }
        return $row;
    }

    public function deleteReminders(array $ids): int
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int) $id, $ids),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }
        if (!function_exists('jeab_reminders_sql_delete')) {
            require_once __DIR__ . '/restore_entities.php';
        }
        $GLOBALS['_JEAB_REMINDER_DELETE_LOCK'] = 1;
        try {
            return jeab_reminders_sql_delete($this->pdo(), $ids);
        } finally {
            unset($GLOBALS['_JEAB_REMINDER_DELETE_LOCK']);
        }
    }

    public function findOpenReminderByBooking(int $bookingId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM reminders WHERE booking_id = ? AND completed_at IS NULL LIMIT 1');
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        return $row ? jeab_hydrate_reminder($row) : null;
    }

    /** Kunden med customers.contact_id (contacts.customers_contact_key). Exakt en träff, annars null. */
    public function findCustomerByContact(string $contactId): ?array
    {
        $contactId = trim($contactId);
        if ($contactId === '') {
            return null;
        }
        if (!function_exists('jeab_customers_contact_key_has_column') && is_file(__DIR__ . '/customers_contact_key.php')) {
            require_once __DIR__ . '/customers_contact_key.php';
        }
        if (!function_exists('jeab_customers_contact_key_has_column') || !jeab_customers_contact_key_has_column($this->pdo())) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM customers WHERE contact_id = ? AND id > 0 ORDER BY id LIMIT 2');
        $stmt->execute([$contactId]);
        $rows = $stmt->fetchAll();

        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    public function findCustomerByName(string $name): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM customers WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertCustomer(string $name, string $createdAt): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $id = jeab_sql_insert_id($this->pdo(), 'customers', ['name', 'created_at'], [$name, $createdAt]);
        if ($id <= 0) {
            throw new RuntimeException('Kunden gick inte att spara (inget id).');
        }

        return [
            'id' => $id,
            'name' => $name,
            'created_at' => $createdAt,
        ];
    }

    public function listCustomers(?int $limit = null, int $offset = 0): array
    {
        $limitSql = jeab_sql_limit_clause($limit, $offset);
        if (jeab_driver() === 'mysql') {
            return $this->pdo()->query('SELECT * FROM customers WHERE id > 0 ORDER BY name' . $limitSql)->fetchAll();
        }
        return $this->pdo()->query('SELECT * FROM customers WHERE id > 0 ORDER BY name COLLATE NOCASE' . $limitSql)->fetchAll();
    }

    public function recentAssignees(int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $sql = 'SELECT assignee FROM (
            SELECT assignee, updated_at FROM bookings WHERE assignee <> \'\'
            UNION ALL
            SELECT assignee, updated_at FROM reminders WHERE assignee IS NOT NULL AND assignee <> \'\'
        ) t GROUP BY assignee ORDER BY MAX(updated_at) DESC LIMIT ' . $limit;
        return array_values(array_filter(array_map(
            static fn(array $r): string => (string) $r['assignee'],
            $this->pdo()->query($sql)->fetchAll()
        )));
    }

    public function getBooking(int $id): ?array
    {
        if (isset($GLOBALS['_JEAB_GET_BOOKING_CALLS'])) {
            $GLOBALS['_JEAB_GET_BOOKING_CALLS']++;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? jeab_hydrate_booking($row) : null;
    }

    public function bookingsBetween(string $from, string $to, ?string $company = null): array
    {
        if (isset($GLOBALS['_JEAB_BOOKINGS_BETWEEN_CALLS'])) {
            $GLOBALS['_JEAB_BOOKINGS_BETWEEN_CALLS']++;
        }
        $pdo = $this->pdo();
        $mode = function_exists('jeab_booking_query_mode') ? jeab_booking_query_mode($pdo) : ['ends_sort' => false, 'company' => false];
        $end = function_exists('jeab_sql_booking_ends_after')
            ? jeab_sql_booking_ends_after(!empty($mode['ends_sort']))
            : "COALESCE(NULLIF(ends_at, ''), starts_at) > ?";
        $company = $company !== null && function_exists('jeab_normalize_company') ? jeab_normalize_company($company) : null;
        $cols = 'id, customer_id, ' . jeab_copy_select($this->pdo(), 'bookings', jeab_copy_columns('bookings')) . ', notes, assignee, assignees, company, job_type, starts_at, ends_at, status, contact_id, created_at, updated_at';
        if ($company !== null && !empty($mode['company'])) {
            $sql = 'SELECT ' . $cols . ' FROM bookings WHERE company = ? AND status = ? AND starts_at < ? AND ' . $end . ' ORDER BY starts_at ASC';
            $params = [$company, 'booked', $to, $from];
        } else {
            $sql = 'SELECT ' . $cols . ' FROM bookings WHERE status = ? AND starts_at < ? AND ' . $end . ' ORDER BY starts_at ASC';
            $params = ['booked', $to, $from];
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('jeab_hydrate_booking', $stmt->fetchAll());
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM bookings WHERE status = ? AND created_at >= ? AND created_at < ? ORDER BY created_at ASC'
        );
        $stmt->execute(['booked', $from, $to]);
        return array_map('jeab_hydrate_booking', $stmt->fetchAll());
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT * FROM bookings WHERE status = ? AND updated_at >= ? AND updated_at < ? ORDER BY updated_at ASC'
        );
        $stmt->execute(['booked', $from, $to]);
        return array_map('jeab_hydrate_booking', $stmt->fetchAll());
    }

    public function listAllBookings(?int $limit = null, int $offset = 0): array
    {
        $sql = 'SELECT * FROM bookings WHERE id > 0 ORDER BY id ASC' . jeab_sql_limit_clause($limit, $offset);
        $rows = $this->pdo()->query($sql)->fetchAll();
        return array_map('jeab_hydrate_booking', $rows);
    }

    public function insertBooking(array $data): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        $names = jeab_require_installers($data['assignees'] ?? $data['assignee'] ?? []);
        $contactId = isset($data['contact_id']) && trim((string) $data['contact_id']) !== '' ? trim((string) $data['contact_id']) : null;
        if (!function_exists('jeab_contacts_copies_apply_booking') && is_file(__DIR__ . '/contacts_copies.php')) {
            require_once __DIR__ . '/contacts_copies.php';
        }
        if (function_exists('jeab_contacts_copies_apply_booking')) {
            $data['contact_id'] = $contactId;
            $data = jeab_contacts_copies_apply_booking($data);
        }
        $pdo = $this->pdo();
        $endsSort = function_exists('jeab_booking_ends_sort_value')
            ? jeab_booking_ends_sort_value(isset($data['ends_at']) ? (string) $data['ends_at'] : null, isset($data['starts_at']) ? (string) $data['starts_at'] : null)
            : (string) ($data['ends_at'] ?? '');
        $withEndsSort = function_exists('jeab_booking_ends_sort_column') && jeab_booking_ends_sort_column($pdo);
        $bookingCols = ['customer_id', 'customer_name', 'email', 'phone', 'address', 'notes', 'assignee', 'assignees', 'company', 'job_type', 'starts_at', 'ends_at'];
        $bookingParams = [
            $data['customer_id'],
            $data['customer_name'],
            $data['email'] ?? null,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            $data['notes'] ?? null,
            jeab_assignees_join($names),
            jeab_assignees_json($names),
            jeab_normalize_company($data['company'] ?? null) ?? 'jarfalla',
            $data['job_type'] ?? null,
            $data['starts_at'],
            $data['ends_at'],
        ];
        if ($withEndsSort) {
            $bookingCols[] = 'ends_sort';
            $bookingParams[] = $endsSort;
        }
        $bookingCols[] = 'status';
        $bookingCols[] = 'contact_id';
        $bookingCols[] = 'created_at';
        $bookingCols[] = 'updated_at';
        array_push($bookingParams, $data['status'] ?? 'booked', $contactId, $data['created_at'], $data['updated_at']);
        [$bookingCols, $bookingParams] = jeab_copy_filter_write($pdo, 'bookings', $bookingCols, $bookingParams);
        $id = jeab_sql_insert_id($pdo, 'bookings', $bookingCols, $bookingParams);
        $row = $this->getBooking($id);
        if (!is_array($row)) {
            throw new RuntimeException('Bokningen gick inte att spara (inget id).');
        }

        return $row;
    }

    /** Tar bort en enda bokningsrad. Inga sidoeffekter (mejl, sms, Outlook, logg). */
    public function deleteBooking(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        if (function_exists('jeab_ordrar_v3_steps_forget')) {
            jeab_ordrar_v3_steps_forget();
        }
        $stmt = $this->pdo()->prepare('DELETE FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    }

    public function saveBooking(int $id, array $fields): array
    {
        if (function_exists('jeab_data_write_guard')) {
            jeab_data_write_guard();
        }
        if (function_exists('jeab_booking_cache_forget')) {
            jeab_booking_cache_forget($id);
        }
        $pdo = $this->pdo();
        $withEndsSort = function_exists('jeab_booking_ends_sort_column') && jeab_booking_ends_sort_column($pdo);
        // Bara fälten anropet skickar. Raden låses och updated_at jämförs, så en
        // samtidig skrivning inte skriver tillbaka en gammal kopia av övriga kolumner.
        return jeab_pdo_transaction($pdo, function (PDO $tx) use ($id, $fields, $withEndsSort): array {
            return $this->saveBookingLocked($tx, $id, $fields, $withEndsSort);
        });
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function saveBookingLocked(PDO $pdo, int $id, array $fields, bool $withEndsSort): array
    {
        $driver = 'sqlite';
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            $driver = 'sqlite';
        }
        if ($driver !== 'mysql') {
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $raw = $this->bookingLockRead($pdo, $id, $driver);
            if (!$raw) {
                throw new InvalidArgumentException('Bokningen finns inte.');
            }
            $sets = $this->bookingPatchColumns($raw, $fields, $withEndsSort);
            foreach (jeab_copy_columns('bookings') as $copyCol) {
                if (array_key_exists($copyCol, $sets) && !jeab_copy_column_present($pdo, 'bookings', $copyCol)) {
                    unset($sets[$copyCol]);
                }
            }
            if ($sets === []) {
                return jeab_hydrate_booking($raw);
            }
            $expected = $raw['updated_at'] ?? null;
            if ($attempt === 0) {
                $race = $GLOBALS['_JEAB_BOOKING_SAVE_RACE'] ?? null;
                if (is_callable($race)) {
                    $race($pdo, $id);
                }
            }
            $this->bookingPatchWrite($pdo, $id, $sets, $expected);
            $fresh = $this->bookingRead($pdo, $id);
            if (is_array($fresh) && $this->bookingPatchLanded($fresh, $sets)) {
                return jeab_hydrate_booking($fresh);
            }
        }
        throw new RuntimeException('Bokningen ändrades av någon annan. Försök igen.');
    }

    /** @return array<string, mixed>|null */
    private function bookingRead(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function bookingLockRead(PDO $pdo, int $id, string $driver): ?array
    {
        if ($driver !== 'mysql') {
            $pdo->prepare('UPDATE bookings SET updated_at = updated_at WHERE id = ?')->execute([$id]);
        }
        $sql = 'SELECT * FROM bookings WHERE id = ?' . ($driver === 'mysql' ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Kolumner anropet faktiskt skickade. Assignee-par och ends_sort följer med
     * när de fälten ingår, på samma sätt som en helradsskrivning normaliserade dem.
     *
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function bookingPatchColumns(array $raw, array $fields, bool $withEndsSort): array
    {
        $sets = [];
        foreach (['customer_id', 'customer_name', 'email', 'phone', 'address', 'notes', 'job_type', 'starts_at', 'ends_at', 'status'] as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[$col] = $fields[$col];
            }
        }
        if (array_key_exists('company', $fields)) {
            $sets['company'] = jeab_normalize_company($fields['company']) ?? 'jarfalla';
        }
        if (array_key_exists('updated_at', $fields)) {
            $sets['updated_at'] = $fields['updated_at'] ?? jeab_now();
        }
        if (array_key_exists('contact_id', $fields)) {
            $contact = $fields['contact_id'];
            $sets['contact_id'] = $contact !== null && trim((string) $contact) !== '' ? trim((string) $contact) : null;
        }
        if (!function_exists('jeab_contacts_copies_hold') && is_file(__DIR__ . '/contacts_copies.php')) {
            require_once __DIR__ . '/contacts_copies.php';
        }
        $linked = trim((string) ($sets['contact_id'] ?? $raw['contact_id'] ?? ''));
        if ($linked !== '' && function_exists('jeab_contacts_copies_hold') && jeab_contacts_copies_hold()) {
            foreach (['customer_name', 'email', 'phone', 'address'] as $col) {
                if (array_key_exists($col, $sets)) {
                    $sets[$col] = $col === 'customer_name' ? '' : null;
                }
            }
        }
        if (array_key_exists('assignee', $fields) || array_key_exists('assignees', $fields)) {
            $names = jeab_assignees_from_row(array_merge($raw, $fields));
            $sets['assignee'] = jeab_assignees_join($names);
            $sets['assignees'] = jeab_assignees_json($names);
        }
        if ($withEndsSort && (array_key_exists('starts_at', $fields) || array_key_exists('ends_at', $fields)) && function_exists('jeab_booking_ends_sort_value')) {
            $starts = array_key_exists('starts_at', $fields) ? $fields['starts_at'] : ($raw['starts_at'] ?? null);
            $ends = array_key_exists('ends_at', $fields) ? $fields['ends_at'] : ($raw['ends_at'] ?? null);
            $sets['ends_sort'] = jeab_booking_ends_sort_value(
                $ends === null ? null : (string) $ends,
                $starts === null ? null : (string) $starts
            );
        }

        return $sets;
    }

    /**
     * @param array<string, mixed> $sets
     */
    private function bookingPatchWrite(PDO $pdo, int $id, array $sets, mixed $expected): void
    {
        $parts = [];
        $params = [];
        foreach ($sets as $col => $value) {
            $parts[] = $col . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;
        if ($expected === null) {
            $sql = 'UPDATE bookings SET ' . implode(', ', $parts) . ' WHERE id = ? AND updated_at IS NULL';
        } else {
            $sql = 'UPDATE bookings SET ' . implode(', ', $parts) . ' WHERE id = ? AND updated_at = ?';
            $params[] = $expected;
        }
        $pdo->prepare($sql)->execute($params);
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $sets
     */
    private function bookingPatchLanded(array $raw, array $sets): bool
    {
        foreach ($sets as $col => $value) {
            $actual = array_key_exists($col, $raw) ? $raw[$col] : null;
            if ($value === null) {
                if ($actual !== null) {
                    return false;
                }
                continue;
            }
            if ($actual === null || (string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        $col = jeab_driver() === 'mysql' ? '`key`' : 'key';
        $stmt = $this->pdo()->prepare('SELECT value FROM settings WHERE ' . $col . ' = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetchColumn();
        if ($row === false) {
            return $default;
        }
        return (string) $row;
    }

    public function setSetting(string $key, string $value): void
    {
        if (jeab_driver() === 'mysql') {
            $sql = 'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)';
        } else {
            $sql = 'INSERT INTO settings ("key", value) VALUES (?, ?) ON CONFLICT("key") DO UPDATE SET value = excluded.value';
        }
        $pdo = $this->pdo();
        $pdo->prepare($sql)->execute([$key, $value]);
        if (function_exists('jeab_settings_cache_store')) {
            jeab_settings_cache_store($pdo, jeab_driver(), $key, $value);
        }
    }

    private function defaultListId(): int
    {
        if ($this->defaultListId !== null && $this->defaultListId > 0) {
            return $this->defaultListId;
        }
        $id = $this->pdo()->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id) {
            $this->defaultListId = (int) $id;
            return $this->defaultListId;
        }
        $this->defaultListId = jeab_sql_insert_id($this->pdo(), 'reminder_lists', ['name', 'created_at'], ['Påminnelser', jeab_now()]);
        if ($this->defaultListId <= 0) {
            throw new RuntimeException('Påminnelselistan gick inte att spara (inget id).');
        }

        return $this->defaultListId;
    }
}
