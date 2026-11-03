<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_copies.php';

/**
 * contacts.customers_contact_key. dry=1 är standard.
 *
 * Ger customers en egen nyckel mot kontakten (customers.contact_id), så en bokning hittar sin
 * kund via kontakten och inte via namnet. En kund får contact_id när alla dess bokningar med
 * contact_id pekar på exakt en kontakt och ingen annan kund pekar på samma. Platshållarkunden
 * (·) och kunder utan bokningar lämnas.
 *
 * dry=0: kolumnen läggs till om den saknas, backup (id och föregående contact_id, inga namn)
 * skrivs till data/.private/customers-contact-key/<batch>.json 0600 med <batch>.sha256.
 * customers och bookings låses, planen läses om och jämförs; ändrades något: 409.
 * Markeringen customers_contact_key sätts sist. En ny körning med samma läge gör inget.
 *
 * contacts.customers_contact_key_undo: batch_id, dry=1 standard, kontrollerar sha256,
 * lägger tillbaka föregående värde bara där värdet är oförändrat, tar bort kolumnen om
 * batchen lade till den och den är tom, och tar bort markeringen.
 */

function jeab_customers_contact_key_marker_key(): string
{
    return 'customers_contact_key';
}

function jeab_customers_contact_key_dir(): string
{
    $base = jeab_contacts_copies_dir();

    return $base === '' ? '' : dirname($base) . '/customers-contact-key';
}

function jeab_customers_contact_key_has_column(PDO $pdo): bool
{
    try {
        $rows = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('PRAGMA table_info(customers)')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return false;
    }
    foreach (is_array($rows) ? $rows : [] as $col) {
        if ((string) ($col['Field'] ?? $col['name'] ?? '') === 'contact_id') {
            return true;
        }
    }

    return false;
}

function jeab_customers_contact_key_has_index(PDO $pdo): bool
{
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return $pdo->query("SHOW INDEX FROM customers WHERE Key_name = 'customers_contact_id'")->fetch() !== false;
        }
        foreach ($pdo->query('PRAGMA index_list(customers)')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string) ($r['name'] ?? '') === 'customers_contact_id') {
                return true;
            }
        }
    } catch (Throwable) {
    }

    return false;
}

/** Indexet kontrolleras för sig, så en körning där ALTER lyckades men indexet föll lagar det nästa gång. */
function jeab_customers_contact_key_ensure_index(PDO $pdo): void
{
    if (!jeab_customers_contact_key_has_column($pdo) || jeab_customers_contact_key_has_index($pdo)) {
        return;
    }
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec('CREATE INDEX ' . ($mysql ? '' : 'IF NOT EXISTS ') . 'customers_contact_id ON customers (contact_id)');
}

/** @return array<string, mixed>|null */
function jeab_customers_contact_key_marker(PDO $pdo): ?array
{
    try {
        $col = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`key`' : 'key';
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE ' . $col . ' = ?');
        $stmt->execute([jeab_customers_contact_key_marker_key()]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data : null;
}

function jeab_customers_contact_key_marker_save(PDO $pdo, ?array $marker): void
{
    $col = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`key`' : 'key';
    $pdo->prepare('DELETE FROM settings WHERE ' . $col . ' = ?')->execute([jeab_customers_contact_key_marker_key()]);
    if ($marker !== null) {
        $pdo->prepare('INSERT INTO settings (' . $col . ', value) VALUES (?, ?)')->execute([jeab_customers_contact_key_marker_key(), (string) json_encode($marker, JSON_UNESCAPED_SLASHES)]);
    }
}

/**
 * @return array{set:array<int,string>,ambiguous:list<int>,shared:list<int>,already:int,print:string}
 */
function jeab_customers_contact_key_plan(PDO $pdo): array
{
    $hasCol = jeab_customers_contact_key_has_column($pdo);
    $current = [];
    $placeholder = function_exists('jeab_contacts_copies_customer_label') ? jeab_contacts_copies_customer_label() : '·';
    $skip = [];
    foreach ($pdo->query('SELECT id, name' . ($hasCol ? ', contact_id' : '') . ' FROM customers WHERE id > 0 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $current[$id] = $hasCol ? trim((string) ($row['contact_id'] ?? '')) : '';
        if ((string) $row['name'] === $placeholder) {
            $skip[$id] = true;
        }
    }
    $seen = [];
    foreach ($pdo->query("SELECT customer_id, contact_id FROM bookings WHERE contact_id IS NOT NULL AND contact_id <> '' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $seen[(int) $row['customer_id']][trim((string) $row['contact_id'])] = true;
    }
    $set = [];
    $ambiguous = [];
    $already = 0;
    $byContact = [];
    foreach ($current as $id => $have) {
        if ($have !== '') {
            $already++;
            $byContact[$have][$id] = true;
            continue;
        }
        if (isset($skip[$id]) || !isset($seen[$id])) {
            continue;
        }
        if (count($seen[$id]) !== 1) {
            $ambiguous[] = $id;
            continue;
        }
        $set[$id] = (string) array_key_first($seen[$id]);
        $byContact[$set[$id]][$id] = true;
    }
    // Pekar flera kunder på samma kontakt får ingen av dem nyckeln.
    $shared = [];
    foreach ($set as $id => $contactId) {
        if (count($byContact[$contactId]) > 1) {
            $shared[] = $id;
            unset($set[$id]);
        }
    }
    ksort($set);

    return ['set' => $set, 'ambiguous' => $ambiguous, 'shared' => $shared, 'already' => $already, 'print' => hash('sha256', (string) json_encode([$current, $seen, $skip]))];
}

/** @return array{path:string,sha256:string}|null */
function jeab_customers_contact_key_write_backup(string $batch, array $payload): ?array
{
    $dir = jeab_customers_contact_key_dir();
    if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
        return null;
    }
    @chmod($dir, 0700);
    $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
    $path = $dir . '/' . $batch . '.json';
    $sha = hash('sha256', $json);
    foreach ([$path => $json, $dir . '/' . $batch . '.sha256' => $sha] as $file => $body) {
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, $body) === false || !jeab_contacts_copies_chmod($tmp, 0600) || !@rename($tmp, $file)) {
            @unlink($tmp);
            @unlink($path);

            return null;
        }
    }

    return ['path' => 'data/.private/customers-contact-key/' . $batch . '.json', 'sha256' => $sha];
}

function jeab_customers_contact_key_lock(PDO $pdo, bool $on, bool $ok = true): void
{
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($on) {
        $pdo->exec($mysql ? 'LOCK TABLES customers WRITE, bookings READ, settings WRITE' : 'BEGIN IMMEDIATE');

        return;
    }
    $pdo->exec($mysql ? 'UNLOCK TABLES' : ($ok ? 'COMMIT' : 'ROLLBACK'));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_customers_contact_key(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_copies_dry($input);
    $base = ['ok' => true, 'action' => 'contacts.customers_contact_key', 'dry' => $dry, 'written' => false, 'batch_id' => '', 'sent' => 0];
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return $base + ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400];
    }
    $plan = jeab_customers_contact_key_plan($pdo);
    $base += [
        'column' => jeab_customers_contact_key_has_column($pdo),
        'set' => count($plan['set']),
        'set_ids' => array_slice(array_keys($plan['set']), 0, 200),
        'already' => $plan['already'],
        'ambiguous' => ['count' => count($plan['ambiguous']), 'ids' => array_slice($plan['ambiguous'], 0, 200)],
        'shared' => ['count' => count($plan['shared']), 'ids' => array_slice($plan['shared'], 0, 200)],
        'marker' => jeab_customers_contact_key_marker($pdo) !== null,
    ];
    if ($dry) {
        return $base;
    }
    if ($plan['set'] === [] && $base['column']) {
        try {
            jeab_customers_contact_key_ensure_index($pdo);
        } catch (Throwable $e) {
            error_log('contacts.customers_contact_key index: ' . get_class($e));

            return array_merge($base, ['ok' => false, 'error' => 'Indexet gick inte att skapa. Kör igen.', 'status' => 500]);
        }
        if (!$base['marker']) {
            jeab_customers_contact_key_marker_save($pdo, ['at' => date('c'), 'batch_id' => '']);
        }

        return array_merge($base, ['already_done' => true, 'written' => !$base['marker'], 'marker' => true]);
    }
    $batch = bin2hex(random_bytes(8));
    $added = !$base['column'];
    $payload = ['batch_id' => $batch, 'at' => date('c'), 'added_column' => $added, 'rows' => []];
    foreach ($plan['set'] as $id => $contactId) {
        $payload['rows'][] = ['id' => $id, 'prev' => '', 'set' => $contactId];
    }
    $backup = jeab_customers_contact_key_write_backup($batch, $payload);
    if ($backup === null) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupen gick inte att skriva.', 'status' => 500]);
    }
    $base['batch_id'] = $batch;
    $base['backup'] = $backup;
    try {
        if ($added) {
            $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $pdo->exec($mysql
                ? "ALTER TABLE customers ADD COLUMN contact_id VARCHAR(64) NOT NULL DEFAULT ''"
                : "ALTER TABLE customers ADD COLUMN contact_id TEXT NOT NULL DEFAULT ''");
        }
        jeab_customers_contact_key_ensure_index($pdo);
        $hook = $GLOBALS['_JEAB_CUSTKEY_BEFORE_LOCK'] ?? null;
        if (is_callable($hook)) {
            $hook($pdo);
        }
        jeab_customers_contact_key_lock($pdo, true);
        $again = jeab_customers_contact_key_plan($pdo);
        if ($again['set'] !== $plan['set']) {
            jeab_customers_contact_key_lock($pdo, false, false);

            return array_merge($base, ['ok' => false, 'error' => 'Kunder eller bokningar ändrades. Kör igen.', 'written' => $added, 'status' => 409]);
        }
        $up = $pdo->prepare("UPDATE customers SET contact_id = ? WHERE id = ? AND contact_id = ''");
        foreach ($plan['set'] as $id => $contactId) {
            $up->execute([$contactId, $id]);
        }
        jeab_customers_contact_key_marker_save($pdo, ['at' => date('c'), 'batch_id' => $batch]);
        jeab_customers_contact_key_lock($pdo, false, true);
    } catch (Throwable $e) {
        try {
            jeab_customers_contact_key_lock($pdo, false, false);
        } catch (Throwable) {
        }
        error_log('contacts.customers_contact_key stoppade: ' . get_class($e));

        return array_merge($base, ['ok' => false, 'error' => 'Körningen stoppade. Kör igen eller undo.', 'written' => true, 'status' => 500]);
    }

    return array_merge($base, ['written' => true, 'marker' => true]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_customers_contact_key_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_copies_dry($input);
    $batch = trim((string) ($input['batch_id'] ?? ''));
    $base = ['ok' => true, 'action' => 'contacts.customers_contact_key_undo', 'dry' => $dry, 'written' => false, 'batch_id' => $batch, 'sent' => 0];
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return $base + ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400];
    }
    if (preg_match('/\A[a-f0-9]{16}\z/', $batch) !== 1) {
        return array_merge($base, ['ok' => false, 'error' => 'batch_id saknas.', 'status' => 422]);
    }
    $dir = jeab_customers_contact_key_dir();
    $raw = is_file($dir . '/' . $batch . '.json') ? (string) file_get_contents($dir . '/' . $batch . '.json') : '';
    $want = is_file($dir . '/' . $batch . '.sha256') ? trim((string) file_get_contents($dir . '/' . $batch . '.sha256')) : '';
    $given = strtolower(trim((string) ($input['sha256'] ?? '')));
    if ($raw === '') {
        return array_merge($base, ['ok' => false, 'error' => 'Backupen finns inte.', 'status' => 404]);
    }
    $sha = hash('sha256', $raw);
    if ($want === '' || !hash_equals($want, $sha) || ($given !== '' && !hash_equals($given, $sha))) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupens sha256 stämmer inte. Inget skrevs.', 'status' => 409]);
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload) || !is_array($payload['rows'] ?? null)) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupen går inte att läsa.', 'status' => 409]);
    }
    $hasCol = jeab_customers_contact_key_has_column($pdo);
    if ($dry) {
        return jeab_customers_contact_key_undo_locked($pdo, $payload, $batch, $hasCol, true, $base);
    }
    try {
        jeab_customers_contact_key_lock($pdo, true);
        $result = jeab_customers_contact_key_undo_locked($pdo, $payload, $batch, $hasCol, false, $base);
        jeab_customers_contact_key_lock($pdo, false, true);
    } catch (Throwable $e) {
        try {
            jeab_customers_contact_key_lock($pdo, false, false);
        } catch (Throwable) {
        }
        error_log('contacts.customers_contact_key_undo stoppade: ' . get_class($e));

        return array_merge($base, ['ok' => false, 'error' => 'Undo stoppade. Kör igen.', 'status' => 500]);
    }

    return $result;
}

/**
 * Undo under låset. Kunder som skapats per kontakt (namn «· id») räknas upp men lämnas:
 * bokningar kan peka på dem.
 *
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $base
 * @return array<string, mixed>
 */
function jeab_customers_contact_key_undo_locked(PDO $pdo, array $payload, string $batch, bool $hasCol, bool $dry, array $base): array
{
    $restore = [];
    $kept = [];
    $now = [];
    $perContact = [];
    if ($hasCol) {
        $label = jeab_contacts_copies_customer_label();
        foreach ($pdo->query('SELECT id, name, contact_id FROM customers')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $now[(int) $r['id']] = trim((string) $r['contact_id']);
            if ($now[(int) $r['id']] !== '' && str_starts_with((string) $r['name'], $label . ' ')) {
                $perContact[(int) $r['id']] = true;
            }
        }
    }
    foreach ($payload['rows'] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if (($now[$id] ?? null) === (string) ($row['set'] ?? '')) {
            $restore[$id] = (string) ($row['prev'] ?? '');
            unset($perContact[$id]);
        } else {
            $kept[] = $id;
        }
    }
    $perContact = array_keys($perContact);
    sort($perContact);
    $base += [
        'restore' => count($restore),
        'kept' => ['count' => count($kept), 'ids' => array_slice($kept, 0, 200)],
        'per_contact_customers' => ['count' => count($perContact), 'ids' => array_slice($perContact, 0, 200)],
    ];
    if ($dry) {
        return $base;
    }
    $up = $pdo->prepare('UPDATE customers SET contact_id = ? WHERE id = ? AND contact_id = ?');
    foreach ($restore as $id => $prev) {
        $up->execute([$prev, $id, $now[$id]]);
    }
    $dropped = false;
    if (!empty($payload['added_column']) && $hasCol && (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE contact_id <> ''")->fetchColumn() === 0) {
        $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if (jeab_customers_contact_key_has_index($pdo)) {
            $pdo->exec($mysql ? 'DROP INDEX customers_contact_id ON customers' : 'DROP INDEX IF EXISTS customers_contact_id');
        }
        $pdo->exec('ALTER TABLE customers DROP COLUMN contact_id');
        $dropped = true;
    }
    $marker = jeab_customers_contact_key_marker($pdo);
    if ($marker !== null && (string) ($marker['batch_id'] ?? '') === $batch) {
        jeab_customers_contact_key_marker_save($pdo, null);
    }

    return array_merge($base, ['written' => true, 'column_dropped' => $dropped]);
}

/**
 * Kunden för en kontakt: findCustomerByContact när nyckeln finns, annars platshållaren.
 * Med markeringen skapas en egen kund per kontakt. Namnet i tabellen är bara ett id;
 * det som visas är kontaktens namn. Hitta/skapa är atomisk via det unika kundnamnet.
 *
 * @return array<string, mixed>
 */
function jeab_customer_for_contact(JeabStore $store, string $contactId): array
{
    $contactId = trim($contactId);
    if ($contactId !== '' && $store instanceof JeabPdoStore && jeab_customers_contact_key_marker($store->pdo()) !== null) {
        $hit = $store->findCustomerByContact($contactId);
        if (is_array($hit)) {
            return jeab_customer_for_contact_view($hit, $contactId);
        }
        $label = jeab_contacts_copies_customer_label() . ' ' . $contactId;
        $row = $store->findCustomerByName($label);
        if (!is_array($row)) {
            try {
                $row = $store->insertCustomer($label, function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'));
            } catch (Throwable) {
                // En annan körning hann skapa samma namn (unikt index): läs om.
                $row = $store->findCustomerByName($label);
            }
        }
        if (!is_array($row)) {
            return jeab_contacts_copies_placeholder_customer($store);
        }
        $store->pdo()->prepare("UPDATE customers SET contact_id = ? WHERE id = ? AND contact_id = ''")->execute([$contactId, (int) $row['id']]);

        return jeab_customer_for_contact_view($store->findCustomerByContact($contactId) ?? $row, $contactId);
    }

    return jeab_contacts_copies_placeholder_customer($store);
}

/**
 * Byter platshållarnamnet («·» eller «· id») mot kontaktens namn i minnet. Tabellen ändras inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_customer_for_contact_view(array $row, string $contactId): array
{
    $label = jeab_contacts_copies_customer_label();
    $name = (string) ($row['name'] ?? '');
    if ($name !== $label && $name !== $label . ' ' . $contactId) {
        return $row;
    }
    $contact = function_exists('jeab_contacts_sole_linked_contact') ? jeab_contacts_sole_linked_contact($contactId) : null;
    if (is_array($contact) && function_exists('jeab_contacts_sole_view_from_contact')) {
        $shown = trim((string) (jeab_contacts_sole_view_from_contact($contact)['name'] ?? ''));
        if ($shown !== '') {
            $row['name'] = $shown;
        }
    }

    return $row;
}
