<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/json_sql_roll.php';

/**
 * Hookar: kontakter.sql_migrate, kontakter.sql_verify, kontakter.sql_cutover.
 * dry=1 som standard. JSON skrivs bara vid rollback (enable=0, dry=0)
 * när LEGACY_JSON_WRITES är på. Är flaggan av återställs SQL-snapshoten i stället.
 */

function jeab_kontakter_sql_dry(array $input): bool
{
    if (function_exists('jeab_kontakt_dry')) {
        return jeab_kontakt_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_kontakter_sql_store(): JeabPdoContactStore|array
{
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.'];
    }
    return new JeabPdoContactStore($pdo);
}

function jeab_kontakter_sql_inbox_store(): JeabPdoInboxContactStore|array
{
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.'];
    }
    return new JeabPdoInboxContactStore($pdo);
}

/** @param mixed $value */
function jeab_kontakter_sql_canon(mixed $value): string
{
    $norm = jeab_kontakter_sql_sort($value);
    $json = json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? hash('sha256', $json) : '';
}

/** @param mixed $value */
function jeab_kontakter_sql_sort(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $isList = array_is_list($value);
    if (!$isList) {
        ksort($value);
    }
    foreach ($value as $key => $item) {
        $value[$key] = jeab_kontakter_sql_sort($item);
    }
    return $value;
}

/** @param array<string, array<string, mixed>> $rows */
function jeab_kontakter_sql_checksum(array $rows): string
{
    $ids = array_keys($rows);
    sort($ids, SORT_STRING);
    $canonical = [];
    foreach ($ids as $id) {
        $row = $rows[$id] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $row['id'] = (string) ($row['id'] ?? $id);
        $canonical[$id] = $row;
    }
    return jeab_kontakter_sql_canon($canonical);
}

/**
 * @param array<string, array<string, mixed>> $json
 * @param array<string, array<string, mixed>> $sql
 * @return array{match:bool,rows:list<array{id:string,match:bool}>}
 */
function jeab_kontakter_sql_sample(array $json, array $sql, int $sampleN): array
{
    $ids = array_keys($json);
    sort($ids, SORT_STRING);
    $picked = array_slice($ids, 0, $sampleN);
    $ok = true;
    $rows = [];
    foreach ($picked as $id) {
        $left = $json[$id] ?? null;
        $right = $sql[$id] ?? null;
        // Samma id-regel som checksum: saknas id i JSON-objektet är nyckeln id.
        // Inkorgens contacts.json lagrar c-id som nyckel, inte som fält.
        $match = is_array($left) && is_array($right)
            && jeab_kontakter_sql_checksum([(string) $id => $left]) === jeab_kontakter_sql_checksum([(string) $id => $right]);
        if (!$match) {
            $ok = false;
        }
        $rows[] = ['id' => (string) $id, 'match' => $match];
    }
    return ['match' => $ok, 'rows' => $rows];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_sql_migrate(array $input): array
{
    $dry = jeab_kontakter_sql_dry($input);
    $entity = strtolower(trim((string) ($input['entity'] ?? 'all')));
    if (!in_array($entity, ['all', 'registry', 'inbox'], true)) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'entity ska vara all, registry eller inbox.'];
    }
    $registry = jeab_kontakter_sql_store();
    $inbox = jeab_kontakter_sql_inbox_store();
    if (!$registry instanceof JeabPdoContactStore || !$inbox instanceof JeabPdoInboxContactStore) {
        $err = is_array($registry) ? $registry : (is_array($inbox) ? $inbox : ['error' => 'Ingen SQL-databas.']);
        return $err + ['dry' => $dry];
    }
    $registryPath = function_exists('jeab_kontakter_path') ? jeab_kontakter_path() : '';
    $inboxPath = (function_exists('jeab_data_dir') ? jeab_data_dir() : '') . '/inkorg/contacts.json';
    $registryMissing = jeab_sql_json_source_missing($registryPath, ['contacts']);
    $inboxMissing = jeab_sql_json_source_missing($inboxPath, ['contacts']);
    $wantRegistry = $entity === 'all' || $entity === 'registry';
    $wantInbox = $entity === 'all' || $entity === 'inbox';
    if ((!$wantRegistry || $registryMissing) && (!$wantInbox || $inboxMissing)) {
        return jeab_sql_json_source_skip($dry, 'kontakter.sql_migrate') + [
            'entity' => $entity,
            'registry_json' => 0,
            'inbox_json' => 0,
            'registry_sql' => $registry->count(),
            'inbox_sql' => $inbox->count(),
        ];
    }
    $jsonRegistry = $registryMissing ? [] : jeab_kontakter_json_file_load();
    $jsonInbox = $inboxMissing ? [] : jeab_inkorg_contacts_json_map();
    $beforeRegistry = $registry->count();
    $beforeInbox = $inbox->count();
    if (!$dry) {
        if ($wantRegistry && !$registryMissing) {
            $registry->replaceAll($jsonRegistry);
        }
        if ($wantInbox && !$inboxMissing) {
            $inbox->replaceAll($jsonInbox);
        }
        jeab_contact_store(true);
    }
    $sqlRegistry = $registry->sqlAll();
    $sqlInbox = $inbox->sqlAll();
    if ($dry) {
        $registryMatch = false;
        $inboxMatch = false;
    } else {
        $registryMatch = $entity === 'inbox' || (count($jsonRegistry) === count($sqlRegistry) && jeab_kontakter_sql_checksum($jsonRegistry) === jeab_kontakter_sql_checksum($sqlRegistry));
        $inboxMatch = $entity === 'registry' || (count($jsonInbox) === count($sqlInbox) && jeab_kontakter_sql_checksum($jsonInbox) === jeab_kontakter_sql_checksum($sqlInbox));
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry,
        'entity' => $entity,
        'registry_json' => count($jsonRegistry),
        'registry_sql' => $registry->count(),
        'inbox_json' => count($jsonInbox),
        'inbox_sql' => $inbox->count(),
        'registry_before' => $beforeRegistry,
        'inbox_before' => $beforeInbox,
        'count_match' => $dry ? false : ($registryMatch && $inboxMatch),
        'checksum_match' => $dry ? false : ($registryMatch && $inboxMatch),
        'idempotent' => $dry || ($beforeRegistry === $registry->count() && $beforeInbox === $inbox->count() && $registryMatch && $inboxMatch),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_sql_verify(array $input): array
{
    $sampleN = max(1, min(50, (int) ($input['sample'] ?? 5)));
    $registry = jeab_kontakter_sql_store();
    $inbox = jeab_kontakter_sql_inbox_store();
    if (!$registry instanceof JeabPdoContactStore || !$inbox instanceof JeabPdoInboxContactStore) {
        $err = is_array($registry) ? $registry : (is_array($inbox) ? $inbox : ['error' => 'Ingen SQL-databas.']);
        return $err + ['dry' => true];
    }
    $jsonRegistry = jeab_kontakter_json_file_load();
    $jsonInbox = jeab_inkorg_contacts_json_map();
    $sqlRegistry = $registry->sqlAll();
    $sqlInbox = $inbox->sqlAll();
    $registryChecksum = jeab_kontakter_sql_checksum($jsonRegistry) === jeab_kontakter_sql_checksum($sqlRegistry);
    $inboxChecksum = jeab_kontakter_sql_checksum($jsonInbox) === jeab_kontakter_sql_checksum($sqlInbox);
    $registryCount = count($jsonRegistry) === count($sqlRegistry);
    $inboxCount = count($jsonInbox) === count($sqlInbox);
    $registrySample = jeab_kontakter_sql_sample($jsonRegistry, $sqlRegistry, $sampleN);
    $inboxSample = jeab_kontakter_sql_sample($jsonInbox, $sqlInbox, $sampleN);
    $samplesMatch = $registrySample['match'] && $inboxSample['match'];
    return [
        'ok' => true,
        'dry' => true,
        'match' => $registryChecksum && $inboxChecksum && $registryCount && $inboxCount && $samplesMatch,
        'registry_json' => count($jsonRegistry),
        'registry_sql' => count($sqlRegistry),
        'registry_count_match' => $registryCount,
        'registry_checksum_match' => $registryChecksum,
        'inbox_json' => count($jsonInbox),
        'inbox_sql' => count($sqlInbox),
        'inbox_count_match' => $inboxCount,
        'inbox_checksum_match' => $inboxChecksum,
        'samples_match' => $samplesMatch,
        'samples' => [
            'registry' => $registrySample['rows'],
            'inbox' => $inboxSample['rows'],
        ],
        'storage' => jeab_kontakter_storage_mode(),
        'sql_writes' => jeab_kontakter_writes_sql_only(),
    ];
}

function jeab_kontakter_sql_set_storage(string $mode): bool
{
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $ok = jeab_settings_put($pdo, $driver, JEAB_KONTAKTER_STORAGE_KEY, $mode === 'sql' ? 'sql' : 'json');
    jeab_contact_store(true);
    return $ok;
}

/**
 * Filen hoppas över när jeab_legacy_json_skip säger det, och när
 * LEGACY_JSON_WRITES är av. Av utan grön verify skriver inte de här
 * rollback-filerna; återställning går via SQL-snapshot.
 */
function jeab_kontakter_sql_json_held(string $type): bool
{
    if (function_exists('jeab_legacy_json_skip') && jeab_legacy_json_skip($type)) {
        return true;
    }

    return function_exists('jeab_legacy_json_writes') && !jeab_legacy_json_writes();
}

/** @param array<string, array<string, mixed>> $rows */
function jeab_kontakter_sql_write_registry(array $rows): void
{
    if (jeab_kontakter_sql_json_held('contacts')) {
        return;
    }
    $json = json_encode(['contacts' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Kunde inte skriva kontakter.json');
    }
    $path = jeab_kontakter_path();
    if (is_file($path)) {
        @copy($path, $path . '.prev');
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Kunde inte skriva kontakter.json');
    }
}

/** @param array<string, array<string, mixed>> $rows */
function jeab_kontakter_sql_write_inbox(array $rows): void
{
    if (jeab_kontakter_sql_json_held('inkorg_contacts')) {
        return;
    }
    if (!function_exists('jeab_inkorg_dir')) {
        throw new RuntimeException('Kunde inte skriva contacts.json');
    }
    $json = json_encode(['contacts' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Kunde inte skriva contacts.json');
    }
    $dir = jeab_inkorg_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte skriva contacts.json');
    }
    $path = $dir . '/contacts.json';
    if (is_file($path)) {
        @copy($path, $path . '.prev');
    }
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Kunde inte skriva contacts.json');
    }
}

/**
 * SQL-kopia i samma PDO när JSON-skrivning är av.
 * contacts.row_json kopieras oförändrad och loggas inte.
 * Kopplingarna är bara id och contact_id.
 */
function jeab_kontakter_sql_snapshot_ensure(PDO $pdo): bool
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    try {
        if ($driver === 'mysql') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS contacts_write_snapshot (
                    snapshot_id VARCHAR(40) NOT NULL,
                    kind VARCHAR(16) NOT NULL,
                    entity_id VARCHAR(64) NOT NULL,
                    row_json LONGTEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (snapshot_id, kind, entity_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS contacts_write_snapshot_meta (
                    snapshot_id VARCHAR(40) NOT NULL,
                    created_at DATETIME NOT NULL,
                    contact_count INT NOT NULL,
                    inbox_count INT NOT NULL,
                    PRIMARY KEY (snapshot_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS contacts_write_snapshot (
                    snapshot_id TEXT NOT NULL,
                    kind TEXT NOT NULL,
                    entity_id TEXT NOT NULL,
                    row_json TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    PRIMARY KEY (snapshot_id, kind, entity_id)
                )'
            );
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS contacts_write_snapshot_meta (
                    snapshot_id TEXT PRIMARY KEY,
                    created_at TEXT NOT NULL,
                    contact_count INTEGER NOT NULL,
                    inbox_count INTEGER NOT NULL
                )'
            );
        }
    } catch (Throwable) {
        return false;
    }

    return true;
}

function jeab_kontakter_sql_snapshot_id_ok(string $id): bool
{
    return preg_match('/^sql-snap-\d{8}-\d{6}(?:-\d+)?$/', $id) === 1;
}

/** @return array<string, list<array{id:string,contact_id:string}>> */
function jeab_kontakter_sql_snapshot_links(JeabStore $store): array
{
    $orders = function_exists('jeab_purchase_all') ? jeab_purchase_all() : [];

    return [
        'reminder' => jeab_kontakt_backup_pairs($store->listReminders(true), 'id'),
        'order' => jeab_kontakt_backup_pairs(is_array($orders) ? $orders : [], 'reminder_id'),
        'booking' => jeab_kontakt_backup_pairs($store->listAllBookings(), 'id'),
    ];
}

function jeab_kontakter_sql_snapshot_take(JeabStore $store): string
{
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    if (!$pdo instanceof PDO || !jeab_kontakter_sql_snapshot_ensure($pdo)) {
        return '';
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_ensure_contacts_tables')) {
        jeab_ensure_contacts_tables($pdo, $driver);
    }
    try {
        $links = jeab_kontakter_sql_snapshot_links($store);
    } catch (Throwable) {
        return '';
    }
    $stamp = date('Ymd-His');
    $id = 'sql-snap-' . $stamp;
    try {
        $check = $pdo->prepare('SELECT 1 FROM contacts_write_snapshot_meta WHERE snapshot_id = ?');
        for ($n = 2; $n < 50; $n++) {
            $check->execute([$id]);
            if (!$check->fetchColumn()) {
                break;
            }
            $id = 'sql-snap-' . $stamp . '-' . $n;
        }
    } catch (Throwable) {
        return '';
    }
    if (!jeab_kontakter_sql_snapshot_id_ok($id)) {
        return '';
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    try {
        jeab_pdo_transaction($pdo, static function () use ($pdo, $id, $now, $links): void {
            $ins = $pdo->prepare(
                'INSERT INTO contacts_write_snapshot (snapshot_id, kind, entity_id, row_json, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $contactCount = jeab_kontakter_sql_snapshot_copy($pdo, $ins, $id, 'contact', 'contacts', $now);
            $inboxCount = jeab_kontakter_sql_snapshot_copy($pdo, $ins, $id, 'inbox', 'inkorg_contacts', $now);
            foreach ($links as $kind => $pairs) {
                if (!in_array($kind, ['reminder', 'order', 'booking'], true)) {
                    continue;
                }
                foreach ($pairs as $pair) {
                    $entity = trim((string) ($pair['id'] ?? ''));
                    if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $entity) !== 1) {
                        continue;
                    }
                    $contactId = trim((string) ($pair['contact_id'] ?? ''));
                    if (preg_match('/^[A-Za-z0-9_-]{0,64}$/', $contactId) !== 1) {
                        $contactId = '';
                    }
                    $json = json_encode(['contact_id' => $contactId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (!is_string($json)) {
                        throw new RuntimeException('SQL-snapshoten gick inte att spara.');
                    }
                    $ins->execute([$id, $kind, $entity, $json, $now]);
                }
            }
            $pdo->prepare(
                'INSERT INTO contacts_write_snapshot_meta (snapshot_id, created_at, contact_count, inbox_count) VALUES (?, ?, ?, ?)'
            )->execute([$id, $now, $contactCount, $inboxCount]);
        });
    } catch (Throwable) {
        return '';
    }

    return $id;
}

function jeab_kontakter_sql_snapshot_copy(PDO $pdo, PDOStatement $ins, string $id, string $kind, string $table, string $now): int
{
    if (!in_array($table, ['contacts', 'inkorg_contacts'], true) || !in_array($kind, ['contact', 'inbox'], true)) {
        throw new RuntimeException('SQL-snapshoten gick inte att spara.');
    }
    $count = 0;
    $queried = $pdo->query('SELECT id, row_json FROM ' . $table);
    $rows = $queried instanceof PDOStatement ? $queried->fetchAll() : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entity = trim((string) ($row['id'] ?? ''));
        $json = (string) ($row['row_json'] ?? '');
        if ($entity === '' || strlen($entity) > 64 || $json === '') {
            throw new RuntimeException('SQL-snapshoten gick inte att spara.');
        }
        $ins->execute([$id, $kind, $entity, $json, $now]);
        $count++;
    }

    return $count;
}

/**
 * @return array{ok:bool,error:string,snapshot:string,contacts:int,inbox:int}
 */
function jeab_kontakter_sql_snapshot_restore(?string $snapshotId = null): array
{
    $fail = static function (string $error, string $snapshot = ''): array {
        return ['ok' => false, 'error' => $error, 'snapshot' => $snapshot, 'contacts' => 0, 'inbox' => 0];
    };
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    if (!$pdo instanceof PDO) {
        return $fail('Ingen SQL-databas. JSON skrevs inte.');
    }
    if (!jeab_kontakter_sql_snapshot_ensure($pdo)) {
        return $fail('SQL-snapshoten gick inte att läsa. JSON skrevs inte.');
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_ensure_contacts_tables')) {
        jeab_ensure_contacts_tables($pdo, $driver);
    }
    $id = trim((string) $snapshotId);
    if ($id === '') {
        try {
            $picked = $pdo->query(
                'SELECT snapshot_id FROM contacts_write_snapshot_meta ORDER BY created_at DESC, snapshot_id DESC LIMIT 1'
            )->fetchColumn();
            $id = is_string($picked) ? $picked : '';
        } catch (Throwable) {
            return $fail('SQL-snapshoten gick inte att läsa. JSON skrevs inte.');
        }
    }
    if (!jeab_kontakter_sql_snapshot_id_ok($id)) {
        return $fail('Ingen SQL-snapshot att återställa. JSON skrevs inte.');
    }
    try {
        $counts = jeab_pdo_transaction($pdo, static function () use ($pdo, $id): array {
            $metaStmt = $pdo->prepare(
                'SELECT contact_count, inbox_count FROM contacts_write_snapshot_meta WHERE snapshot_id = ?'
            );
            $metaStmt->execute([$id]);
            $meta = $metaStmt->fetch();
            if (!is_array($meta)) {
                throw new RuntimeException('SQL-snapshoten gick inte att återställa.');
            }
            $contactCount = (int) ($meta['contact_count'] ?? 0);
            $inboxCount = (int) ($meta['inbox_count'] ?? 0);
            $contacts = jeab_kontakter_sql_snapshot_rows($pdo, $id, 'contact');
            $inbox = jeab_kontakter_sql_snapshot_rows($pdo, $id, 'inbox');
            if (count($contacts) !== $contactCount || count($inbox) !== $inboxCount) {
                throw new RuntimeException('SQL-snapshoten gick inte att återställa.');
            }
            $store = new JeabPdoContactStore($pdo);
            $store->mutate(static function (array &$live) use ($contacts): bool {
                $live = $contacts;

                return true;
            }, true);
            $nowContacts = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
            if ($nowContacts !== $contactCount) {
                throw new RuntimeException('SQL-snapshoten gick inte att återställa.');
            }
            $inboxStore = new JeabPdoInboxContactStore($pdo);
            $keep = [];
            foreach ($inbox as $inboxId => $row) {
                $inboxStore->upsert((string) $inboxId, $row);
                $keep[(string) $inboxId] = true;
            }
            $existingQuery = $pdo->query('SELECT id FROM inkorg_contacts');
            $existing = $existingQuery instanceof PDOStatement ? $existingQuery->fetchAll() : [];
            $drop = $pdo->prepare('DELETE FROM inkorg_contacts WHERE id = ?');
            foreach ($existing as $dbRow) {
                if (!is_array($dbRow)) {
                    continue;
                }
                $inboxId = trim((string) ($dbRow['id'] ?? ''));
                if ($inboxId !== '' && !isset($keep[$inboxId])) {
                    $drop->execute([$inboxId]);
                }
            }
            $nowInbox = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn();
            if ($nowInbox !== $inboxCount) {
                throw new RuntimeException('SQL-snapshoten gick inte att återställa.');
            }
            jeab_kontakter_sql_snapshot_restore_links($pdo, $id);

            return ['contacts' => $contactCount, 'inbox' => $inboxCount];
        });
    } catch (Throwable) {
        return $fail('SQL-snapshoten gick inte att återställa. JSON skrevs inte.', $id);
    }

    return [
        'ok' => true,
        'error' => '',
        'snapshot' => $id,
        'contacts' => (int) ($counts['contacts'] ?? 0),
        'inbox' => (int) ($counts['inbox'] ?? 0),
    ];
}

/** @return array<string, array<string, mixed>> */
function jeab_kontakter_sql_snapshot_rows(PDO $pdo, string $id, string $kind): array
{
    if (!in_array($kind, ['contact', 'inbox'], true)) {
        throw new RuntimeException('SQL-snapshoten gick inte att återställa.');
    }
    $stmt = $pdo->prepare(
        'SELECT entity_id, row_json FROM contacts_write_snapshot WHERE snapshot_id = ? AND kind = ? ORDER BY entity_id'
    );
    $stmt->execute([$id, $kind]);
    $out = [];
    foreach ($stmt as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entity = trim((string) ($row['entity_id'] ?? ''));
        $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
        if ($entity === '' || !is_array($decoded)) {
            throw new RuntimeException('SQL-snapshoten gick inte att återställa.');
        }
        $decoded['id'] = $entity;
        $out[$entity] = $decoded;
    }

    return $out;
}

function jeab_kontakter_sql_snapshot_restore_links(PDO $pdo, string $id): void
{
    $tables = [
        'reminder' => ['reminders', 'id'],
        'booking' => ['bookings', 'id'],
        'order' => ['purchases', 'reminder_id'],
    ];
    $stmt = $pdo->prepare(
        'SELECT entity_id, row_json FROM contacts_write_snapshot WHERE snapshot_id = ? AND kind = ?'
    );
    foreach ($tables as $kind => $target) {
        if (!jeab_kontakter_sql_snapshot_table($pdo, $target[0])) {
            continue;
        }
        $stmt->execute([$id, $kind]);
        $linkRows = $stmt->fetchAll();
        $upd = $pdo->prepare('UPDATE ' . $target[0] . ' SET contact_id = ? WHERE ' . $target[1] . ' = ?');
        foreach ($linkRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entity = trim((string) ($row['entity_id'] ?? ''));
            if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $entity) !== 1) {
                continue;
            }
            $payload = json_decode((string) ($row['row_json'] ?? ''), true);
            $contactId = is_array($payload) ? trim((string) ($payload['contact_id'] ?? '')) : '';
            if (preg_match('/^[A-Za-z0-9_-]{0,64}$/', $contactId) !== 1) {
                $contactId = '';
            }
            $upd->execute([$contactId, $entity]);
        }
    }
}

function jeab_kontakter_sql_snapshot_table(PDO $pdo, string $table): bool
{
    if (!in_array($table, ['reminders', 'bookings', 'purchases'], true)) {
        return false;
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $stmt->execute([$table]);

            return (bool) $stmt->fetchColumn();
        }
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_sql_cutover(array $input): array
{
    $dry = jeab_kontakter_sql_dry($input);
    $enable = !array_key_exists('enable', $input) ? null : !($input['enable'] === false || $input['enable'] === 0 || $input['enable'] === '0' || $input['enable'] === 'false');
    $before = jeab_kontakter_storage_mode();
    if ($enable === null) {
        return ['ok' => true, 'dry' => $dry, 'storage' => $before, 'changed' => false];
    }
    if ($enable) {
        $verify = jeab_kontakter_sql_verify($input);
        if (empty($verify['ok'])) {
            return $verify + ['dry' => $dry, 'storage' => $before];
        }
        if (empty($verify['match']) && empty($input['force'])) {
            return [
                'ok' => false,
                'dry' => $dry,
                'error' => 'Verifieringen matchar inte. Cutover är inte gjord.',
                'storage' => $before,
                'verify' => [
                    'match' => false,
                    'registry_count_match' => $verify['registry_count_match'] ?? false,
                    'inbox_count_match' => $verify['inbox_count_match'] ?? false,
                    'samples_match' => $verify['samples_match'] ?? false,
                ],
            ];
        }
        if (!$dry && !jeab_kontakter_sql_set_storage('sql')) {
            return ['ok' => false, 'dry' => false, 'error' => 'Flaggan kunde inte sättas.', 'storage' => jeab_kontakter_storage_mode()];
        }
        return [
            'ok' => true,
            'dry' => $dry,
            'enable' => 1,
            'storage' => $dry ? $before : 'sql',
            'would_storage' => 'sql',
            'changed' => !$dry && $before !== 'sql',
        ];
    }

    $registry = jeab_kontakter_sql_store();
    $inbox = jeab_kontakter_sql_inbox_store();
    if (!$registry instanceof JeabPdoContactStore || !$inbox instanceof JeabPdoInboxContactStore) {
        $err = is_array($registry) ? $registry : (is_array($inbox) ? $inbox : ['error' => 'Ingen SQL-databas.']);
        return $err + ['dry' => $dry, 'storage' => $before];
    }
    $sqlRegistry = $registry->count();
    $sqlInbox = $inbox->count();
    $jsonRows = count(jeab_kontakter_json_file_load()) + count(jeab_inkorg_contacts_json_map());
    if ($before !== 'sql') {
        return ['ok' => true, 'dry' => $dry, 'enable' => 0, 'storage' => 'json', 'changed' => false, 'rollback' => 'already_json'];
    }
    if (!$dry && jeab_kontakter_sql_json_held('contacts')) {
        // LEGACY_JSON_WRITES av: JSON-filen skrivs inte.
        // Rollback återställer senaste SQL-snapshoten från
        // jeab_kontakter_backup_snapshot (kontakter, inkorgskontakter
        // och contact_id). Saknas snapshoten blir ok:false och inget ändras.
        // Lagret stannar i SQL, eftersom filen inte får skrivas.
        // Tom SQL stoppar inte den här vägen: snapshoten kan fylla tabellerna,
        // och utan snapshot ska felet säga det i stället för att JSON skyddas.
        $restored = jeab_kontakter_sql_snapshot_restore();
        if (empty($restored['ok'])) {
            return [
                'ok' => false,
                'dry' => false,
                'error' => (string) ($restored['error'] ?? 'Ingen SQL-snapshot att återställa. JSON skrevs inte.'),
                'storage' => $before,
            ];
        }

        return [
            'ok' => true,
            'dry' => false,
            'enable' => 0,
            'rollback' => 'sql_snapshot',
            'storage' => 'sql',
            'snapshot' => (string) ($restored['snapshot'] ?? ''),
            'changed' => true,
        ];
    }
    if ($sqlRegistry + $sqlInbox === 0 && $jsonRows > 0 && empty($input['force'])) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'SQL är tom. JSON skrevs inte över.', 'storage' => $before];
    }
    if (!$dry) {
        jeab_kontakter_sql_write_registry($registry->sqlAll());
        jeab_kontakter_sql_write_inbox($inbox->sqlAll());
        if (!jeab_kontakter_sql_set_storage('json')) {
            return ['ok' => false, 'dry' => false, 'error' => 'JSON skrevs men flaggan kunde inte slås av.', 'storage' => jeab_kontakter_storage_mode()];
        }
    }
    return [
        'ok' => true,
        'dry' => $dry,
        'enable' => 0,
        'rollback' => 'json',
        'storage' => $dry ? $before : 'json',
        'would_storage' => 'json',
        'registry_sql' => $sqlRegistry,
        'inbox_sql' => $sqlInbox,
        'changed' => !$dry,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_sql_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'kontakter.sql_verify') {
        $result = jeab_kontakter_sql_verify($input);
    } elseif ($action === 'kontakter.sql_cutover') {
        $result = jeab_kontakter_sql_cutover($input);
    } else {
        $result = jeab_kontakter_sql_migrate($input);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}
