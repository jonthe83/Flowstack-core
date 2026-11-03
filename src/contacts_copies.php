<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/contacts_strict.php';
require_once __DIR__ . '/contacts_sole_audit.php';

/**
 * contacts.copies_strip. dry=1 är standard.
 *
 * Påminnelser och bokningar med contact_id slutar bära en egen kopia av
 * namn, e-post, telefon och adress. Visningen läser kontakten. Rader utan
 * contact_id lämnas. contact_id, bokningstider och antal rader rörs inte.
 * Frysta snapshots och rot rörs inte.
 *
 * dry=0 skriver ångerfilen innan någon rad ändras. Filen ligger under
 * data/.private, är 0600 och har bara de rensade fälten. Ett personnummer
 * ligger där krypterat, inte i klartext. Filer äldre än 14 dagar tas bort.
 * dry=0 och undo vägrar när kontakter inte är enda källan.
 * Krypteringsnyckeln läses från JEAB_PNR_HMAC_KEY eller privat/pnr-hmac.key.
 * Den skapas inte, och den ligger inte i ångerkatalogen. Saknas den vägrar dry=0.
 * contacts.copies_strip_undo tar batch_id, är torr som standard och lägger
 * tillbaka fälten bara när de fortfarande är tomma och contact_id är samma.
 */

function jeab_contacts_copies_hold(): bool
{
    if (!function_exists('jeab_contacts_sole') && is_file(__DIR__ . '/contacts_sole.php')) {
        require_once __DIR__ . '/contacts_sole.php';
    }

    return function_exists('jeab_contacts_strict_active') && jeab_contacts_strict_active();
}

function jeab_contacts_copies_customer_label(): string
{
    return '·';
}

/** @return array<string, mixed> */
function jeab_contacts_copies_placeholder_customer(JeabStore $store): array
{
    $name = jeab_contacts_copies_customer_label();
    $row = $store->findCustomerByName($name);
    if (is_array($row)) {
        return $row;
    }
    try {
        return $store->insertCustomer($name, function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'));
    } catch (Throwable) {
        $again = $store->findCustomerByName($name);
        if (is_array($again)) {
            return $again;
        }
        throw new RuntimeException('Kunden gick inte att spara.');
    }
}

/** @param array<string, mixed> $data */
function jeab_contacts_copies_apply_reminder(array $data): array
{
    if (!jeab_contacts_copies_hold() || trim((string) ($data['contact_id'] ?? '')) === '') {
        return $data;
    }
    foreach (['customer_name', 'email', 'phone', 'address', 'ort', 'postal'] as $key) {
        $data[$key] = null;
    }

    return $data;
}

/** @param array<string, mixed> $data */
function jeab_contacts_copies_apply_booking(array $data): array
{
    if (!jeab_contacts_copies_hold() || trim((string) ($data['contact_id'] ?? '')) === '') {
        return $data;
    }
    $data['customer_name'] = '';
    $data['email'] = null;
    $data['phone'] = null;
    $data['address'] = null;

    return $data;
}

/** @return list<string> */
function jeab_contacts_copies_fields(string $table): array
{
    if ($table === 'reminders') {
        return ['customer_name', 'email', 'phone', 'address', 'ort', 'postal'];
    }
    if ($table === 'bookings') {
        return ['customer_name', 'email', 'phone', 'address'];
    }

    return [];
}

function jeab_contacts_copies_empty(string $table, string $column): ?string
{
    if ($table === 'bookings' && $column === 'customer_name') {
        return '';
    }

    return null;
}

function jeab_contacts_copies_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_copies_has_pnr(string $value): bool
{
    if (preg_match_all('/(?<!\d)((?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]?\d{4})(?!\d)/', $value, $found) < 1) {
        return false;
    }
    foreach ($found[1] as $hit) {
        if (!is_string($hit)) {
            continue;
        }
        $digits = preg_replace('/\D+/', '', $hit) ?? '';
        $rawLen = strlen($digits);
        if ($rawLen !== 10 && $rawLen !== 12) {
            continue;
        }
        $luhnMissing = !function_exists('jeab_norm_pnr_luhn')
            || (array_key_exists('_JEAB_COPIES_LUHN_OFF', $GLOBALS) && $GLOBALS['_JEAB_COPIES_LUHN_OFF'] === true);
        if ($luhnMissing) {
            return true;
        }
        if ($rawLen === 12) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) !== 10 || str_starts_with($digits, '07')) {
            continue;
        }
        if (jeab_norm_pnr_luhn($digits)) {
            return true;
        }
    }

    return false;
}

function jeab_contacts_copies_chmod(string $path, int $mode): bool
{
    $hook = $GLOBALS['_JEAB_COPIES_CHMOD'] ?? null;
    if (is_callable($hook)) {
        return (bool) $hook($path, $mode);
    }

    return chmod($path, $mode);
}

/**
 * Samma hemlighet som personnumrets HMAC: JEAB_PNR_HMAC_KEY, annars
 * dirname(webbrot)/privat/pnr-hmac.key. Skapar ingen fil och skriver
 * aldrig i ångerkatalogen. Tom nyckel gör att dry=0 vägrar.
 */
function jeab_contacts_copies_key(): string
{
    if (!function_exists('jeab_contacts_sole_pnr_hmac_key')) {
        return '';
    }
    $secret = jeab_contacts_sole_pnr_hmac_key();
    if ($secret === '') {
        return '';
    }

    return hash_hkdf('sha256', $secret, 32, 'jeab-contacts-copies-seal-v1');
}

function jeab_contacts_copies_seal(string $plain): ?string
{
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return null;
    }
    $key = jeab_contacts_copies_key();
    if (strlen($key) !== 32) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        return null;
    }
    $parts = str_split(bin2hex($iv . $tag . $cipher), 4);
    if ($parts === []) {
        return null;
    }

    return 'v1:' . implode('g', $parts);
}

function jeab_contacts_copies_open(string $token): ?string
{
    if (!str_starts_with($token, 'v1:') || !function_exists('openssl_decrypt')) {
        return null;
    }
    $hex = str_replace('g', '', substr($token, 3));
    if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
        return null;
    }
    $blob = hex2bin($hex);
    if (!is_string($blob) || strlen($blob) < 29) {
        return null;
    }
    $key = jeab_contacts_copies_key();
    if (strlen($key) !== 32) {
        return null;
    }
    $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));

    return is_string($plain) ? $plain : null;
}

/** @return string|null klartext, eller null när värdet inte kan läsas tillbaka */
function jeab_contacts_copies_stored_plain(mixed $value): ?string
{
    if (is_string($value)) {
        $text = trim($value);

        return $text === '' ? null : $text;
    }
    if (!is_array($value) || !is_string($value['sealed'] ?? null)) {
        return null;
    }
    $plain = jeab_contacts_copies_open($value['sealed']);
    if (!is_string($plain)) {
        return null;
    }
    $text = trim($plain);

    return $text === '' ? null : $text;
}

function jeab_contacts_copies_dir(): string
{
    if (!function_exists('jeab_data_dir')) {
        return '';
    }
    $base = rtrim(jeab_data_dir(), '/');
    if ($base === '') {
        return '';
    }
    $norm = str_replace('\\', '/', $base);
    if (!str_ends_with($norm, '/.private') && !str_contains($norm, '/.private/')) {
        $base .= '/.private';
    }

    return $base . '/contacts-copies-strip';
}

function jeab_contacts_copies_prune(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $cut = time() - 14 * 86400;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < $cut) {
            @unlink($file);
        }
    }
}

function jeab_contacts_copies_blank(string $value): bool
{
    return trim($value) === '';
}

/**
 * fields är ångerfilen. Ett personnummer ligger där som sealed, inte i klartext.
 * before används bara i minnet så skrivningen träffar samma värden som planen såg.
 * Går krypteringen inte att göra lämnas fältet orört.
 *
 * @param array<string, mixed> $row
 * @return array{table:string,id:int,contact_id:string,fields:array<string,string>,before:array<string,string>}|null
 */
function jeab_contacts_copies_plan_row(string $table, array $row, array $fields): ?array
{
    $id = (int) ($row['id'] ?? 0);
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    if ($id <= 0 || $contactId === '' || jeab_contacts_copies_has_pnr($contactId)) {
        return null;
    }
    $kept = [];
    $before = [];
    foreach ($fields as $column) {
        if (!array_key_exists($column, $row)) {
            continue;
        }
        $value = $row[$column];
        $text = $value === null ? '' : trim((string) $value);
        if ($text === '') {
            continue;
        }
        if (jeab_contacts_copies_has_pnr($text)) {
            $sealed = jeab_contacts_copies_seal($text);
            if ($sealed === null || jeab_contacts_copies_has_pnr($sealed)) {
                $GLOBALS['_JEAB_COPIES_PNR_LEFT'] = (int) ($GLOBALS['_JEAB_COPIES_PNR_LEFT'] ?? 0) + 1;
                continue;
            }
            $before[$column] = $text;
            $kept[$column] = ['sealed' => $sealed];
            continue;
        }
        $before[$column] = $text;
        $kept[$column] = $text;
    }
    if ($before === []) {
        return null;
    }

    return [
        'table' => $table,
        'id' => $id,
        'contact_id' => $contactId,
        'fields' => $kept,
        'before' => $before,
    ];
}

/**
 * @return list<array{table:string,id:int,contact_id:string,fields:array<string,string>,before:array<string,string>}>
 */
function jeab_contacts_copies_plan(PDO $pdo): array
{
    if (!jeab_contacts_copies_hold()) {
        return [];
    }
    $plan = [];
    foreach (['reminders', 'bookings'] as $table) {
        $want = jeab_contacts_copies_fields($table);
        $cols = jeab_contacts_sole_audit_columns($pdo, $table);
        if ($cols === [] || !isset($cols['id'], $cols['contact_id'])) {
            continue;
        }
        $fields = [];
        foreach ($want as $column) {
            if (isset($cols[$column])) {
                $fields[] = $column;
            }
        }
        if ($fields === []) {
            continue;
        }
        $sql = 'SELECT id, contact_id, ' . implode(', ', $fields) . ' FROM ' . $table . ' WHERE id > 0';
        $stmt = $pdo->query($sql);
        if (!$stmt instanceof PDOStatement) {
            continue;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $step = jeab_contacts_copies_plan_row($table, $row, $fields);
            if ($step !== null) {
                $plan[] = $step;
            }
        }
    }

    return $plan;
}

/**
 * @param list<array{table:string,id:int,contact_id:string,fields:array<string,string>,before:array<string,string>}> $plan
 * @return array{reminders:int,bookings:int,ids:list<string>}
 */
function jeab_contacts_copies_counts(array $plan): array
{
    $reminders = 0;
    $bookings = 0;
    $ids = [];
    foreach ($plan as $step) {
        if ($step['table'] === 'bookings') {
            $bookings++;
            $ids[] = 'bookings:' . $step['id'];
        } else {
            $reminders++;
            $ids[] = 'reminders:' . $step['id'];
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);

    return ['reminders' => $reminders, 'bookings' => $bookings, 'ids' => $ids];
}

function jeab_contacts_copies_batch_ok(string $batch): bool
{
    return preg_match('/^[0-9]{14}-[a-f0-9]{6}$/', $batch) === 1;
}

/** @param array<string, mixed> $input */
function jeab_contacts_copies_strip(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_copies_dry($input);
    $base = [
        'ok' => true,
        'action' => 'contacts.copies_strip',
        'dry' => $dry,
        'read_only' => $dry,
        'written' => false,
        'batch_id' => '',
        'reminders' => 0,
        'bookings' => 0,
        'ids' => [],
    ];
    if (!jeab_contacts_copies_hold()) {
        if (!$dry) {
            return array_merge($base, [
                'ok' => false,
                'error' => 'Kontakter är inte enda källan.',
                'written' => false,
                'status' => 400,
            ]);
        }

        return $base;
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return array_merge($base, ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400]);
    }
    $GLOBALS['_JEAB_COPIES_PNR_LEFT'] = 0;
    $plan = jeab_contacts_copies_plan($pdo);
    if (!$dry && (int) ($GLOBALS['_JEAB_COPIES_PNR_LEFT'] ?? 0) > 0) {
        error_log('contacts.copies_strip lämnade ett personnummerfält');
    }
    $counts = jeab_contacts_copies_counts($plan);
    $base['reminders'] = $counts['reminders'];
    $base['bookings'] = $counts['bookings'];
    $base['ids'] = $counts['ids'];
    $dir = jeab_contacts_copies_dir();
    jeab_contacts_copies_prune($dir);
    if ($dry || $plan === []) {
        return $base;
    }
    if (jeab_contacts_copies_key() === '') {
        return array_merge($base, ['ok' => false, 'error' => 'Nyckel saknas.', 'written' => false, 'status' => 400]);
    }
    if ($dir === '') {
        return array_merge($base, ['ok' => false, 'error' => 'Ångerfilen kunde inte skrivas.', 'written' => false, 'status' => 400]);
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return array_merge($base, ['ok' => false, 'error' => 'Ångerfilen kunde inte skrivas.', 'written' => false, 'status' => 400]);
    }
    @chmod($dir, 0700);
    $batch = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $path = $dir . '/' . $batch . '.json';
    $rows = [];
    foreach ($plan as $step) {
        $fields = [];
        foreach ($step['fields'] as $column => $value) {
            if (!is_string($column) || !in_array($column, jeab_contacts_copies_fields($step['table']), true)) {
                continue;
            }
            if (is_array($value) && is_string($value['sealed'] ?? null) && !jeab_contacts_copies_has_pnr($value['sealed'])) {
                $fields[$column] = ['sealed' => $value['sealed']];
                continue;
            }
            if (!is_string($value) || jeab_contacts_copies_has_pnr($value)) {
                continue;
            }
            $fields[$column] = $value;
        }
        $rows[] = [
            'table' => $step['table'],
            'id' => $step['id'],
            'contact_id' => $step['contact_id'],
            'fields' => $fields,
        ];
    }
    $payload = json_encode(['batch_id' => $batch, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || jeab_contacts_copies_has_pnr($payload) || file_put_contents($path, $payload, LOCK_EX) === false) {
        @unlink($path);

        return array_merge($base, ['ok' => false, 'error' => 'Ångerfilen kunde inte skrivas.', 'written' => false, 'status' => 400]);
    }
    if (!jeab_contacts_copies_chmod($path, 0600) || (fileperms($path) & 0777) !== 0600) {
        @unlink($path);

        return array_merge($base, ['ok' => false, 'error' => 'Ångerfilen kunde inte skyddas.', 'written' => false, 'status' => 400]);
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    $wrote = 0;
    try {
        foreach ($plan as $step) {
            if (!in_array($step['table'], ['reminders', 'bookings'], true)) {
                continue;
            }
            $fields = jeab_contacts_copies_fields($step['table']);
            $cols = jeab_contacts_sole_audit_columns($pdo, $step['table']);
            $live = [];
            foreach ($fields as $column) {
                if (isset($cols[$column])) {
                    $live[] = $column;
                }
            }
            if ($live === []) {
                continue;
            }
            $before = is_array($step['before'] ?? null) ? $step['before'] : [];
            $readCols = [];
            foreach ($live as $column) {
                if (array_key_exists($column, $before)) {
                    $readCols[] = $column;
                }
            }
            if ($readCols === []) {
                continue;
            }
            $read = $pdo->prepare('SELECT contact_id, ' . implode(', ', $readCols) . ' FROM ' . $step['table'] . ' WHERE id = ?');
            $read->execute([$step['id']]);
            $current = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || trim((string) ($current['contact_id'] ?? '')) !== $step['contact_id']) {
                continue;
            }
            $sets = [];
            $params = [];
            $stale = false;
            foreach ($readCols as $column) {
                $seen = (string) $before[$column];
                $now = $current[$column] === null ? '' : trim((string) $current[$column]);
                if ($now !== $seen) {
                    $stale = true;
                    break;
                }
                $sets[] = $column . ' = ?';
                $params[] = jeab_contacts_copies_empty($step['table'], $column);
            }
            if ($stale || $sets === []) {
                continue;
            }
            $params[] = $step['id'];
            $params[] = $step['contact_id'];
            $stmt = $pdo->prepare('UPDATE ' . $step['table'] . ' SET ' . implode(', ', $sets) . ' WHERE id = ? AND contact_id = ?');
            $stmt->execute($params);
            if ($stmt->rowCount() === 1) {
                $wrote++;
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($path);

        return array_merge($base, ['ok' => false, 'error' => 'Raden kunde inte skrivas.', 'written' => false, 'status' => 400]);
    }
    if ($wrote === 0) {
        @unlink($path);

        return $base;
    }
    $base['dry'] = false;
    $base['read_only'] = false;
    $base['written'] = true;
    $base['batch_id'] = $batch;

    return $base;
}

/** @return array<string, mixed>|null */
function jeab_contacts_copies_load(string $batch): ?array
{
    if (!jeab_contacts_copies_batch_ok($batch)) {
        return null;
    }
    $path = jeab_contacts_copies_dir() . '/' . $batch . '.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/** @param array<string, mixed> $input */
function jeab_contacts_copies_strip_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_copies_dry($input);
    $batch = trim((string) ($input['batch_id'] ?? ''));
    $base = [
        'ok' => true,
        'action' => 'contacts.copies_strip_undo',
        'dry' => $dry,
        'read_only' => $dry,
        'written' => false,
        'batch_id' => $batch,
        'reminders' => 0,
        'bookings' => 0,
        'skipped' => 0,
        'ids' => [],
    ];
    if (!jeab_contacts_copies_hold()) {
        return array_merge($base, [
            'ok' => false,
            'error' => 'Kontakter är inte enda källan.',
            'written' => false,
            'status' => 400,
        ]);
    }
    jeab_contacts_copies_prune(jeab_contacts_copies_dir());
    if ($batch === '' || !jeab_contacts_copies_batch_ok($batch)) {
        return array_merge($base, ['ok' => false, 'error' => 'batch_id saknas.', 'status' => 400]);
    }
    $file = jeab_contacts_copies_load($batch);
    if (!is_array($file)) {
        return array_merge($base, ['ok' => false, 'error' => 'Ångerfilen saknas.', 'status' => 400]);
    }
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $ids = [];
    $reminders = 0;
    $bookings = 0;
    foreach ($rows as $step) {
        if (!is_array($step)) {
            continue;
        }
        $table = (string) ($step['table'] ?? '');
        $id = (int) ($step['id'] ?? 0);
        if ($id <= 0 || !in_array($table, ['reminders', 'bookings'], true)) {
            continue;
        }
        if ($table === 'bookings') {
            $bookings++;
            $ids[] = 'bookings:' . $id;
        } else {
            $reminders++;
            $ids[] = 'reminders:' . $id;
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    $base['ids'] = $ids;
    $base['reminders'] = $reminders;
    $base['bookings'] = $bookings;
    if ($dry) {
        return $base;
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return array_merge($base, ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400]);
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    $restored = 0;
    $skipped = 0;
    try {
        foreach ($rows as $step) {
            if (!is_array($step)) {
                continue;
            }
            $table = (string) ($step['table'] ?? '');
            $id = (int) ($step['id'] ?? 0);
            $contactId = trim((string) ($step['contact_id'] ?? ''));
            $fields = is_array($step['fields'] ?? null) ? $step['fields'] : [];
            $allowed = jeab_contacts_copies_fields($table);
            if ($id <= 0 || $contactId === '' || $allowed === [] || !in_array($table, ['reminders', 'bookings'], true)) {
                $skipped++;
                continue;
            }
            $cols = jeab_contacts_sole_audit_columns($pdo, $table);
            $live = [];
            foreach ($allowed as $column) {
                if (isset($cols[$column]) && array_key_exists($column, $fields)) {
                    $live[] = $column;
                }
            }
            if ($live === []) {
                $skipped++;
                continue;
            }
            $read = $pdo->prepare('SELECT contact_id, ' . implode(', ', $live) . ' FROM ' . $table . ' WHERE id = ?');
            $read->execute([$id]);
            $current = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || trim((string) ($current['contact_id'] ?? '')) !== $contactId) {
                $skipped++;
                continue;
            }
            $changed = false;
            $sets = [];
            $params = [];
            foreach ($live as $column) {
                $rawField = $fields[$column] ?? null;
                $sealed = is_array($rawField) && is_string($rawField['sealed'] ?? null);
                $value = jeab_contacts_copies_stored_plain($rawField);
                if ($value === null) {
                    if ($sealed) {
                        error_log('contacts.copies_strip_undo sealed ' . $table . ' ' . $id . ' ' . $column);
                    }
                    continue;
                }
                $now = $current[$column] === null ? '' : trim((string) $current[$column]);
                if ($now !== '') {
                    $changed = true;
                    break;
                }
                $sets[] = $column . ' = ?';
                $params[] = $value;
            }
            if ($changed || $sets === []) {
                $skipped++;
                continue;
            }
            $params[] = $id;
            $params[] = $contactId;
            $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ? AND contact_id = ?');
            $stmt->execute($params);
            if ($stmt->rowCount() === 1) {
                $restored++;
            } else {
                $skipped++;
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return array_merge($base, ['ok' => false, 'error' => 'Raden kunde inte skrivas.', 'written' => false, 'status' => 400]);
    }
    $base['dry'] = false;
    $base['read_only'] = false;
    $base['written'] = $restored > 0;
    $base['skipped'] = $skipped;

    return $base;
}
