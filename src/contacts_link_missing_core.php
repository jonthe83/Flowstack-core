<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';

/**
 * contacts.link_missing_core. dry=1 är standard.
 *
 * Påminnelser, bokningar, köp och ordrar utan contact_id. Ordrar är köpraderna
 * och behandlas en gång. Träff mot en befintlig kontakt i samma bolag: e-post,
 * telefon eller adress (gata+postnummer, annars gata+ort). Exakt en träff länkas.
 * Flera träffar lämnas. Ingen träff ger ett förslag att skapa en kontakt av
 * radens egna uppgifter. Kontakter slås inte ihop. Ingenting skickas.
 * Skickade offerter, fakturor och mejlkopior rörs inte.
 * dry=0 skriver och lämnar ett ånger-id. contacts.link_missing_core_undo
 * är torr som standard och tar bara bort länken om den fortfarande är densamma.
 * En kontakt som körningen skapade tas bort bara när ingen kärnrad pekar på den.
 */

function jeab_contacts_link_missing_core_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_link_missing_core_ident(string $name): string
{
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        throw new RuntimeException('Ogiltigt namn.');
    }

    return $name;
}

function jeab_contacts_link_missing_core_company(string $raw): string
{
    if (function_exists('jeab_normalize_company')) {
        return (string) (jeab_normalize_company($raw) ?? '');
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($raw), 'UTF-8') : strtolower(trim($raw));
    $fold = strtr($fold, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
    if (in_array($fold, ['jarfalla', 'jeab'], true)) {
        return 'jarfalla';
    }
    if (in_array($fold, ['malmo', 'meab'], true)) {
        return 'malmo';
    }

    return '';
}

function jeab_contacts_link_missing_core_email(string $raw): string
{
    if (function_exists('jeab_kontakt_email')) {
        return jeab_kontakt_email($raw);
    }
    $raw = strtolower(trim($raw));

    return str_contains($raw, '@') ? $raw : '';
}

function jeab_contacts_link_missing_core_phone(string $raw): string
{
    if (function_exists('jeab_phone_ascii')) {
        $raw = jeab_phone_ascii($raw);
    }
    if (function_exists('jeab_norm_phone')) {
        return jeab_norm_phone($raw);
    }

    return '';
}

function jeab_contacts_link_missing_core_city(string $raw): string
{
    if (function_exists('jeab_kontakt_city_name')) {
        $raw = jeab_kontakt_city_name($raw);
    }
    if (function_exists('jeab_kontakt_fold_text')) {
        return jeab_kontakt_fold_text($raw);
    }

    return strtolower(trim($raw));
}

function jeab_contacts_link_missing_core_postal(string $raw): string
{
    if (function_exists('jeab_kontakt_postal')) {
        return jeab_kontakt_postal($raw);
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    return strlen($digits) >= 5 ? substr($digits, 0, 5) : '';
}

/** @return array<string, true> */
function jeab_contacts_link_missing_core_columns(PDO $pdo, string $table): array
{
    $table = jeab_contacts_link_missing_core_ident($table);
    $have = [];
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && isset($row['COLUMN_NAME'])) {
                $have[(string) $row['COLUMN_NAME']] = true;
            }
        }

        return $have;
    }
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (is_array($row) && isset($row['name'])) {
            $have[(string) $row['name']] = true;
        }
    }

    return $have;
}

function jeab_contacts_link_missing_core_table(PDO $pdo, string $table): bool
{
    try {
        jeab_contacts_link_missing_core_ident($table);
    } catch (RuntimeException) {
        return false;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

/** @return array<string, mixed> */
function jeab_contacts_link_missing_core_json(string $raw): array
{
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, true> $cols
 */
function jeab_contacts_link_missing_core_cell(array $row, array $cols, string $column): string
{
    if (!isset($cols[$column])) {
        return '';
    }

    return trim((string) ($row[$column] ?? ''));
}

/**
 * @param array<string, mixed> $json
 * @param list<string> $paths
 */
function jeab_contacts_link_missing_core_path(array $json, array $paths): string
{
    foreach ($paths as $path) {
        $cursor = $json;
        $ok = true;
        foreach (explode('.', $path) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                $ok = false;
                break;
            }
            $cursor = $cursor[$part];
        }
        if (!$ok || is_array($cursor)) {
            continue;
        }
        $text = trim((string) $cursor);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $json
 * @return array{email:string,phone:string,name:string,address:string,company:string,contact_id:string}
 */
function jeab_contacts_link_missing_core_facts(array $row, array $cols, array $json, bool $purchase): array
{
    $email = jeab_contacts_link_missing_core_email(jeab_contacts_link_missing_core_cell($row, $cols, 'email'));
    if ($email === '') {
        $email = jeab_contacts_link_missing_core_email(jeab_contacts_link_missing_core_cell($row, $cols, 'customer_email'));
    }
    $phone = jeab_contacts_link_missing_core_phone(jeab_contacts_link_missing_core_cell($row, $cols, 'phone'));
    $name = jeab_contacts_link_missing_core_cell($row, $cols, 'customer_name');
    if ($name === '') {
        $name = jeab_contacts_link_missing_core_cell($row, $cols, 'name');
    }
    $address = jeab_contacts_link_missing_core_cell($row, $cols, 'address');
    $company = jeab_contacts_link_missing_core_company(jeab_contacts_link_missing_core_cell($row, $cols, 'company'));
    $cid = jeab_contacts_link_missing_core_cell($row, $cols, 'contact_id');
    if ($purchase || $email === '') {
        $fromJson = jeab_contacts_link_missing_core_email(jeab_contacts_link_missing_core_path($json, ['email', 'customer_email', 'applicant.email']));
        if ($email === '') {
            $email = $fromJson;
        }
    }
    if ($purchase || $phone === '') {
        $fromJson = jeab_contacts_link_missing_core_phone(jeab_contacts_link_missing_core_path($json, ['phone', 'mobil', 'customer_phone', 'applicant.phone']));
        if ($phone === '') {
            $phone = $fromJson;
        }
    }
    if ($name === '') {
        $name = jeab_contacts_link_missing_core_path($json, ['customer_name', 'name', 'huvudsokande', 'applicant.name']);
    }
    if ($address === '') {
        $address = jeab_contacts_link_missing_core_path($json, ['address', 'adress', 'huvudsokande_address']);
    }
    $jsonCompany = jeab_contacts_link_missing_core_company(jeab_contacts_link_missing_core_path($json, ['company']));
    if ($company !== '' && $jsonCompany !== '' && $company !== $jsonCompany) {
        $company = '';
        $jsonCompany = 'conflict';
    } elseif ($company === '') {
        $company = $jsonCompany;
        $jsonCompany = '';
    }
    $jsonCid = trim(jeab_contacts_link_missing_core_path($json, ['contact_id']));
    if ($cid === '') {
        $cid = $jsonCid;
    }

    return [
        'email' => $email,
        'phone' => $phone,
        'name' => $name,
        'address' => $address,
        'company' => $jsonCompany === 'conflict' ? '' : $company,
        'contact_id' => $cid,
        'company_conflict' => $jsonCompany === 'conflict',
    ];
}

/**
 * @return array{street:string,street_key:string,postal:string,city:string}
 */
function jeab_contacts_link_missing_core_address(string $raw): array
{
    if (function_exists('jeab_kontakt_parse_address')) {
        $parsed = jeab_kontakt_parse_address($raw);

        return [
            'street' => trim((string) ($parsed['street'] ?? '')),
            'street_key' => trim((string) ($parsed['street_key'] ?? '')),
            'postal' => jeab_contacts_link_missing_core_postal((string) ($parsed['postal'] ?? '')),
            'city' => jeab_contacts_link_missing_core_city((string) ($parsed['city'] ?? '')),
        ];
    }

    return ['street' => trim($raw), 'street_key' => '', 'postal' => '', 'city' => ''];
}

/**
 * @param array<string, array<string, mixed>> $index
 * @return array{mode:string,contact_id:string,reason:string}
 */
function jeab_contacts_link_missing_core_match(array $index, string $company, string $email, string $phone, array $address): array
{
    $ids = [];
    $why = [];
    $take = static function (array $bucket, string $reason) use (&$ids, &$why): void {
        foreach ($bucket as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            $ids[$id] = true;
            $why[$id][$reason] = true;
        }
    };
    if ($email !== '' && isset($index['email'][$company][$email])) {
        $take($index['email'][$company][$email], 'email');
    }
    if ($phone !== '' && isset($index['phone'][$company][$phone])) {
        $take($index['phone'][$company][$phone], 'phone');
    }
    $street = (string) ($address['street_key'] ?? '');
    if ($street !== '' && isset($index['rows'][$company]) && is_array($index['rows'][$company])) {
        $postal = (string) ($address['postal'] ?? '');
        $city = (string) ($address['city'] ?? '');
        foreach ($index['rows'][$company] as $id => $contact) {
            if (!is_array($contact)) {
                continue;
            }
            $contactStreet = (string) ($contact['street_key'] ?? '');
            $sameStreet = function_exists('jeab_kontakt_street_keys_equal')
                ? jeab_kontakt_street_keys_equal($street, $contactStreet)
                : ($street !== '' && $street === $contactStreet);
            if (!$sameStreet) {
                continue;
            }
            if ($postal !== '') {
                if ($postal === (string) ($contact['postal'] ?? '') && $postal !== '') {
                    $take([$id], 'address');
                }
                continue;
            }
            if ($city !== '' && $city === (string) ($contact['city'] ?? '')) {
                $take([$id], 'address');
            }
        }
    }
    $found = array_keys($ids);
    if (count($found) > 1) {
        return ['mode' => '', 'contact_id' => '', 'reason' => 'ambiguous'];
    }
    if (count($found) === 1) {
        $id = (string) $found[0];
        $reason = isset($why[$id]['email']) ? 'email' : (isset($why[$id]['phone']) ? 'phone' : 'address');

        return ['mode' => 'link', 'contact_id' => $id, 'reason' => $reason];
    }

    return ['mode' => 'create', 'contact_id' => '', 'reason' => 'create'];
}

/**
 * @param array<string, array<string, mixed>> $index
 * @param array{street:string,street_key:string,postal:string,city:string} $address
 */
function jeab_contacts_link_missing_core_remember(array &$index, string $id, string $company, string $email, string $phone, array $address): void
{
    if (!isset($index['rows'][$company]) || !is_array($index['rows'][$company])) {
        $index['rows'][$company] = [];
    }
    $index['rows'][$company][$id] = [
        'street_key' => $address['street_key'],
        'postal' => $address['postal'],
        'city' => $address['city'],
    ];
    if ($email !== '') {
        $index['email'][$company][$email][] = $id;
    }
    if ($phone !== '') {
        $index['phone'][$company][$phone][] = $id;
    }
}

/** @return array<string, array<string, mixed>> */
function jeab_contacts_link_missing_core_index(PDO $pdo): array
{
    $index = ['email' => [], 'phone' => [], 'rows' => []];
    if (!jeab_contacts_link_missing_core_table($pdo, 'contacts')) {
        return $index;
    }
    $cols = jeab_contacts_link_missing_core_columns($pdo, 'contacts');
    if (!isset($cols['id'])) {
        return $index;
    }
    $pick = ['id'];
    foreach (['company', 'owner_email', 'owner_phone', 'email2', 'email3', 'street_key', 'postal_code', 'row_json'] as $column) {
        if (isset($cols[$column])) {
            $pick[] = $column;
        }
    }
    $stmt = $pdo->query('SELECT ' . implode(', ', $pick) . ' FROM contacts');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $company = jeab_contacts_link_missing_core_company((string) ($row['company'] ?? ''));
        if ($id === '' || $company === '') {
            continue;
        }
        $json = jeab_contacts_link_missing_core_json((string) ($row['row_json'] ?? ''));
        $emails = [];
        foreach (['owner_email', 'email2', 'email3'] as $column) {
            $email = jeab_contacts_link_missing_core_email((string) ($row[$column] ?? ''));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
        foreach (is_array($json['owners'] ?? null) ? $json['owners'] : [] as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $email = jeab_contacts_link_missing_core_email((string) ($owner['email'] ?? ''));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }
        $phones = [];
        $phone = jeab_contacts_link_missing_core_phone((string) ($row['owner_phone'] ?? ''));
        if ($phone !== '') {
            $phones[$phone] = true;
        }
        foreach (is_array($json['owners'] ?? null) ? $json['owners'] : [] as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $phone = jeab_contacts_link_missing_core_phone((string) ($owner['phone'] ?? ''));
            if ($phone !== '') {
                $phones[$phone] = true;
            }
        }
        $street = trim((string) ($row['street_key'] ?? ''));
        if ($street === '') {
            $street = trim((string) ($json['street_key'] ?? ''));
        }
        $postal = jeab_contacts_link_missing_core_postal((string) ($row['postal_code'] ?? ''));
        if ($postal === '') {
            $postal = jeab_contacts_link_missing_core_postal((string) ($json['postal_code'] ?? ''));
        }
        $city = jeab_contacts_link_missing_core_city((string) ($json['city'] ?? ''));
        foreach (array_keys($emails) as $email) {
            jeab_contacts_link_missing_core_remember($index, $id, $company, $email, '', [
                'street' => '',
                'street_key' => $street,
                'postal' => $postal,
                'city' => $city,
            ]);
        }
        foreach (array_keys($phones) as $one) {
            jeab_contacts_link_missing_core_remember($index, $id, $company, '', $one, [
                'street' => '',
                'street_key' => $street,
                'postal' => $postal,
                'city' => $city,
            ]);
        }
        if ($emails === [] && $phones === []) {
            jeab_contacts_link_missing_core_remember($index, $id, $company, '', '', [
                'street' => '',
                'street_key' => $street,
                'postal' => $postal,
                'city' => $city,
            ]);
        }
    }

    return $index;
}

/**
 * @return list<array{table:string,id:string,company:string,email:string,phone:string,name:string,address:string,conflict:bool}>
 */
function jeab_contacts_link_missing_core_scan(PDO $pdo): array
{
    $out = [];
    foreach (['reminders', 'bookings', 'purchases'] as $table) {
        if (!jeab_contacts_link_missing_core_table($pdo, $table)) {
            continue;
        }
        $cols = jeab_contacts_link_missing_core_columns($pdo, $table);
        $idCol = $table === 'purchases' ? 'reminder_id' : 'id';
        if (!isset($cols[$idCol])) {
            continue;
        }
        $pick = [$idCol];
        foreach (['contact_id', 'company', 'email', 'customer_email', 'phone', 'customer_name', 'name', 'address', 'payload'] as $column) {
            if (isset($cols[$column])) {
                $pick[] = $column;
            }
        }
        $sql = 'SELECT ' . implode(', ', array_map('jeab_contacts_link_missing_core_ident', $pick))
            . ' FROM ' . jeab_contacts_link_missing_core_ident($table)
            . ' ORDER BY ' . jeab_contacts_link_missing_core_ident($idCol);
        $stmt = $pdo->query($sql);
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $json = isset($cols['payload']) ? jeab_contacts_link_missing_core_json((string) ($row['payload'] ?? '')) : [];
            $facts = jeab_contacts_link_missing_core_facts($row, $cols, $json, $table === 'purchases');
            if ((string) $facts['contact_id'] !== '') {
                continue;
            }
            $out[] = [
                'table' => $table,
                'id' => trim((string) ($row[$idCol] ?? '')),
                'company' => (string) $facts['company'],
                'email' => (string) $facts['email'],
                'phone' => (string) $facts['phone'],
                'name' => (string) $facts['name'],
                'address' => (string) $facts['address'],
                'conflict' => !empty($facts['company_conflict']),
            ];
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $index
 * @return list<array{table:string,id:string,company:string,mode:string,contact_id:string,reason:string,created:bool,email:string,phone:string,name:string,address:string}>
 */
function jeab_contacts_link_missing_core_plan(PDO $pdo, array &$index): array
{
    $plan = [];
    foreach (jeab_contacts_link_missing_core_scan($pdo) as $row) {
        $company = (string) $row['company'];
        if (!empty($row['conflict']) || $company === '') {
            $plan[] = [
                'table' => (string) $row['table'],
                'id' => (string) $row['id'],
                'company' => '',
                'mode' => '',
                'contact_id' => '',
                'reason' => 'no_company',
                'created' => false,
                'email' => '',
                'phone' => '',
                'name' => '',
                'address' => '',
            ];
            continue;
        }
        $address = jeab_contacts_link_missing_core_address((string) $row['address']);
        $match = jeab_contacts_link_missing_core_match(
            $index,
            $company,
            (string) $row['email'],
            (string) $row['phone'],
            $address
        );
        $created = false;
        $contactId = (string) $match['contact_id'];
        if ($match['mode'] === 'create') {
            $contactId = 'lc' . bin2hex(random_bytes(8));
            $created = true;
            jeab_contacts_link_missing_core_remember($index, $contactId, $company, (string) $row['email'], (string) $row['phone'], $address);
        }
        $plan[] = [
            'table' => (string) $row['table'],
            'id' => (string) $row['id'],
            'company' => $company,
            'mode' => (string) $match['mode'],
            'contact_id' => $contactId,
            'reason' => (string) $match['reason'],
            'created' => $created,
            'email' => (string) $row['email'],
            'phone' => (string) $row['phone'],
            'name' => (string) $row['name'],
            'address' => (string) $row['address'],
        ];
    }

    return $plan;
}

/**
 * @param array<string, mixed> $item
 */
function jeab_contacts_link_missing_core_insert(PDO $pdo, array $item): void
{
    $id = (string) $item['contact_id'];
    $address = jeab_contacts_link_missing_core_address((string) ($item['address'] ?? ''));
    $owner = ['contact' => true];
    $name = trim((string) ($item['name'] ?? ''));
    $email = jeab_contacts_link_missing_core_email((string) ($item['email'] ?? ''));
    $phone = jeab_contacts_link_missing_core_phone((string) ($item['phone'] ?? ''));
    if ($name !== '') {
        $owner['name'] = $name;
    }
    if ($email !== '') {
        $owner['email'] = $email;
    }
    if ($phone !== '') {
        $owner['phone'] = $phone;
    }
    $record = [
        'id' => $id,
        'company' => (string) $item['company'],
        'street' => $address['street'],
        'street_key' => $address['street_key'],
        'postal_code' => $address['postal'],
        'city' => trim((string) ($item['address'] ?? '')) === '' ? '' : (function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name((string) ($address['city'] !== '' ? $address['city'] : '')) : ''),
        'owners' => [$owner],
    ];
    if ($address['city'] !== '' && function_exists('jeab_kontakt_parse_address')) {
        $parsed = jeab_kontakt_parse_address((string) $item['address']);
        $record['city'] = (string) ($parsed['city'] ?? '');
    }
    $idx = function_exists('jeab_contact_store_index_fields')
        ? jeab_contact_store_index_fields($record)
        : [
            'street_key' => $address['street_key'],
            'postal_code' => $address['postal'],
            'owner_email' => $email,
            'owner_phone' => $phone,
            'owner_name_fold' => $name,
        ];
    $list = function_exists('jeab_contact_store_list_fields')
        ? jeab_contact_store_list_fields($record)
        : ['company' => (string) $item['company'], 'city_fold' => $address['city'], 'sort_key' => $name, 'sort_city' => $address['city']];
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Kunde inte skriva kontakten.');
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $cols = jeab_contacts_link_missing_core_columns($pdo, 'contacts');
    $map = [
        'id' => $id,
        'row_json' => $json,
        'street_key' => (string) ($idx['street_key'] ?? ''),
        'postal_code' => (string) ($idx['postal_code'] ?? ''),
        'owner_email' => (string) ($idx['owner_email'] ?? ''),
        'owner_phone' => (string) ($idx['owner_phone'] ?? ''),
        'owner_name_fold' => (string) ($idx['owner_name_fold'] ?? ''),
        'company' => (string) ($list['company'] ?? ''),
        'city_fold' => (string) ($list['city_fold'] ?? ''),
        'sort_key' => (string) ($list['sort_key'] ?? ''),
        'sort_city' => (string) ($list['sort_city'] ?? ''),
        'list_ready' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $names = [];
    $values = [];
    foreach ($map as $name => $value) {
        if (!isset($cols[$name])) {
            continue;
        }
        $names[] = jeab_contacts_link_missing_core_ident($name);
        $values[] = $value;
    }
    if (!isset($cols['id']) || !isset($cols['row_json'])) {
        throw new RuntimeException('Kunde inte skriva kontakten.');
    }
    $sql = 'INSERT INTO contacts (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
    $pdo->prepare($sql)->execute($values);
    $check = $pdo->prepare('SELECT id FROM contacts WHERE id = ?');
    $check->execute([$id]);
    if ((string) $check->fetchColumn() !== $id) {
        throw new RuntimeException('Kunde inte skriva kontakten.');
    }
    if (function_exists('jeab_contact_emails_sync')) {
        jeab_contact_emails_sync($pdo, $id, $record);
    }
}

/**
 * @param array<string, mixed> $item
 */
function jeab_contacts_link_missing_core_bind(PDO $pdo, array $item): bool
{
    $table = jeab_contacts_link_missing_core_ident((string) $item['table']);
    if (!in_array($table, ['reminders', 'bookings', 'purchases'], true)) {
        return false;
    }
    $id = (string) $item['id'];
    $cid = (string) $item['contact_id'];
    if ($id === '' || $cid === '') {
        return false;
    }
    $cols = jeab_contacts_link_missing_core_columns($pdo, $table);
    $idCol = $table === 'purchases' ? 'reminder_id' : 'id';
    if (!isset($cols[$idCol])) {
        return false;
    }
    if ($table === 'purchases') {
        $stmt = $pdo->prepare('SELECT contact_id, payload FROM purchases WHERE reminder_id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            return false;
        }
        $column = trim((string) ($current['contact_id'] ?? ''));
        $json = jeab_contacts_link_missing_core_json((string) ($current['payload'] ?? ''));
        $jsonCid = trim((string) ($json['contact_id'] ?? ''));
        if ($column !== '' || $jsonCid !== '') {
            return false;
        }
        $json['contact_id'] = $cid;
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return false;
        }
        $sets = [];
        $values = [];
        if (isset($cols['contact_id'])) {
            $sets[] = 'contact_id = ?';
            $values[] = $cid;
        }
        if (isset($cols['payload'])) {
            $sets[] = 'payload = ?';
            $values[] = $encoded;
        }
        if ($sets === []) {
            return false;
        }
        $values[] = $id;
        $pdo->prepare('UPDATE purchases SET ' . implode(', ', $sets) . ' WHERE reminder_id = ?')->execute($values);
        $again = $pdo->prepare('SELECT contact_id, payload FROM purchases WHERE reminder_id = ?');
        $again->execute([$id]);
        $got = $again->fetch(PDO::FETCH_ASSOC);
        if (!is_array($got)) {
            return false;
        }
        $gotJson = jeab_contacts_link_missing_core_json((string) ($got['payload'] ?? ''));
        $columnOk = !isset($cols['contact_id']) || trim((string) ($got['contact_id'] ?? '')) === $cid;

        return $columnOk && trim((string) ($gotJson['contact_id'] ?? '')) === $cid;
    }
    if (!isset($cols['contact_id'])) {
        return false;
    }
    $pdo->prepare(
        'UPDATE ' . $table . ' SET contact_id = ? WHERE ' . $idCol . ' = ? AND (contact_id IS NULL OR TRIM(contact_id) = \'\')'
    )->execute([$cid, $id]);
    $check = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE ' . $idCol . ' = ?');
    $check->execute([$id]);

    return trim((string) $check->fetchColumn()) === $cid;
}

function jeab_contacts_link_missing_core_undo_dir(): string
{
    $dir = function_exists('jeab_data_dir') ? rtrim(jeab_data_dir(), '/') : '';
    if ($dir === '') {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }

    return $dir . '/contacts-link-missing-core';
}

/** @param list<array<string, mixed>> $rows */
function jeab_contacts_link_missing_core_write_undo(string $undoId, array $rows): void
{
    $dir = jeab_contacts_link_missing_core_undo_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
    $public = [
        'source' => 'contacts.link_missing_core',
        'rows' => [],
    ];
    foreach ($rows as $row) {
        $public['rows'][] = [
            'table' => (string) ($row['table'] ?? ''),
            'id' => (string) ($row['id'] ?? ''),
            'contact_id' => (string) ($row['contact_id'] ?? ''),
            'created' => !empty($row['created']),
        ];
    }
    $path = $dir . '/' . $undoId . '.json';
    $json = json_encode($public, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || file_put_contents($path, $json) === false) {
        throw new RuntimeException('Kunde inte skriva ångerfilen.');
    }
}

/** @return array<string, mixed>|null */
function jeab_contacts_link_missing_core_read_undo(string $undoId): ?array
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return null;
    }
    $path = jeab_contacts_link_missing_core_undo_dir() . '/' . $undoId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/** @param array<string, true> $ignore */
function jeab_contacts_link_missing_core_referenced(PDO $pdo, string $cid, array $ignore): bool
{
    foreach (['reminders', 'bookings'] as $table) {
        if (!jeab_contacts_link_missing_core_table($pdo, $table)) {
            continue;
        }
        $cols = jeab_contacts_link_missing_core_columns($pdo, $table);
        if (!isset($cols['contact_id']) || !isset($cols['id'])) {
            continue;
        }
        $stmt = $pdo->query('SELECT id, contact_id FROM ' . $table);
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if (isset($ignore[$table . '|' . $id])) {
                continue;
            }
            if (trim((string) ($row['contact_id'] ?? '')) === $cid) {
                return true;
            }
        }
    }
    if (!jeab_contacts_link_missing_core_table($pdo, 'purchases')) {
        return false;
    }
    $cols = jeab_contacts_link_missing_core_columns($pdo, 'purchases');
    $stmt = $pdo->query('SELECT reminder_id, contact_id, payload FROM purchases');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['reminder_id'] ?? ''));
        if (isset($ignore['purchases|' . $id])) {
            continue;
        }
        if (isset($cols['contact_id']) && trim((string) ($row['contact_id'] ?? '')) === $cid) {
            return true;
        }
        $json = jeab_contacts_link_missing_core_json((string) ($row['payload'] ?? ''));
        if (trim((string) ($json['contact_id'] ?? '')) === $cid) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_link_missing_core_scrub(array $node): array
{
    $allow = [
        'contacts.link_missing_core',
        'contacts.link_missing_core_undo',
        'reminders',
        'bookings',
        'purchases',
        'orders',
        'jarfalla',
        'malmo',
        'link',
        'create',
        'email',
        'phone',
        'address',
        'ambiguous',
        'no_company',
        'Fel token.',
        'Ingen lagring.',
        'Kunde inte skriva ångerfilen.',
        'Ångerfilen saknas.',
        'Körningen misslyckades.',
        'Kunde inte skriva kontakten.',
    ];
    $out = [];
    foreach ($node as $key => $value) {
        $name = (string) $key;
        if (preg_match('/pnr|personnummer|idnr/i', $name) === 1) {
            continue;
        }
        if (is_array($value)) {
            $out[$name] = jeab_contacts_link_missing_core_scrub($value);
            continue;
        }
        if (is_int($value) || is_bool($value)) {
            $out[$name] = $value;
            continue;
        }
        if (!is_string($value)) {
            continue;
        }
        if (preg_match('/\d{6}[-+]?\d{4}/', $value) === 1) {
            continue;
        }
        if (in_array($value, $allow, true) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1) {
            $out[$name] = $value;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $plan
 * @return array<string, mixed>
 */
function jeab_contacts_link_missing_core_counts(array $plan, int $written): array
{
    $counts = [
        'rows' => count($plan),
        'link' => 0,
        'create' => 0,
        'ambiguous' => 0,
        'no_company' => 0,
        'written' => $written,
        'jarfalla' => ['reminders' => 0, 'bookings' => 0, 'purchases' => 0, 'orders' => 0],
        'malmo' => ['reminders' => 0, 'bookings' => 0, 'purchases' => 0, 'orders' => 0],
    ];
    foreach ($plan as $row) {
        $reason = (string) ($row['reason'] ?? '');
        if ($reason === 'ambiguous') {
            $counts['ambiguous']++;
        } elseif ($reason === 'no_company') {
            $counts['no_company']++;
        } elseif (($row['mode'] ?? '') === 'link') {
            $counts['link']++;
        } elseif (($row['mode'] ?? '') === 'create') {
            $counts['create']++;
        }
        $company = (string) ($row['company'] ?? '');
        $table = (string) ($row['table'] ?? '');
        if (!isset($counts[$company]) || !is_array($counts[$company]) || !isset($counts[$company][$table])) {
            continue;
        }
        $counts[$company][$table]++;
        if ($table === 'purchases') {
            $counts[$company]['orders']++;
        }
    }

    return $counts;
}

/** @param list<array<string, mixed>> $plan */
function jeab_contacts_link_missing_core_public_rows(array $plan): array
{
    $rows = [];
    foreach ($plan as $row) {
        $item = [
            'id' => (string) ($row['id'] ?? ''),
            'table' => (string) ($row['table'] ?? ''),
            'reason' => (string) ($row['reason'] ?? ''),
        ];
        $company = (string) ($row['company'] ?? '');
        if ($company !== '') {
            $item['company'] = $company;
        }
        $mode = (string) ($row['mode'] ?? '');
        if ($mode !== '') {
            $item['mode'] = $mode;
        }
        $cid = (string) ($row['contact_id'] ?? '');
        if ($cid !== '') {
            $item['contact_id'] = $cid;
        }
        $rows[] = $item;
    }

    return $rows;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_missing_core(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_missing_core_dry($input);
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_missing_core_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_missing_core',
            'dry' => $dry,
            'written' => false,
        ]);
    }
    try {
        return jeab_contacts_link_missing_core_scrub(jeab_contacts_link_missing_core_run($store->pdo(), $dry));
    } catch (Throwable $e) {
        error_log('contacts.link_missing_core: ' . $e->getMessage());

        return jeab_contacts_link_missing_core_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_missing_core',
            'dry' => $dry,
            'written' => false,
        ]);
    }
}

/** @return array<string, mixed> */
function jeab_contacts_link_missing_core_run(PDO $pdo, bool $dry): array
{
    $index = jeab_contacts_link_missing_core_index($pdo);
    $plan = jeab_contacts_link_missing_core_plan($pdo, $index);
    $undoId = '';
    $written = 0;
    if (!$dry) {
        $work = static function (PDO $pdo) use ($plan, &$undoId, &$written): void {
            $undoRows = [];
            foreach ($plan as $item) {
                if (($item['mode'] ?? '') !== 'link' && ($item['mode'] ?? '') !== 'create') {
                    continue;
                }
                if (!empty($item['created'])) {
                    jeab_contacts_link_missing_core_insert($pdo, $item);
                }
                if (!jeab_contacts_link_missing_core_bind($pdo, $item)) {
                    throw new RuntimeException('Körningen misslyckades.');
                }
                $undoRows[] = $item;
                $written++;
            }
            if ($undoRows !== []) {
                $undoId = bin2hex(random_bytes(8));
                jeab_contacts_link_missing_core_write_undo($undoId, $undoRows);
            }
        };
        if (function_exists('jeab_pdo_transaction')) {
            jeab_pdo_transaction($pdo, $work);
        } else {
            $work($pdo);
        }
    }

    $out = [
        'ok' => true,
        'action' => 'contacts.link_missing_core',
        'dry' => $dry,
        'written' => $written,
        'counts' => jeab_contacts_link_missing_core_counts($plan, $written),
        'rows' => jeab_contacts_link_missing_core_public_rows($plan),
    ];
    if ($undoId !== '') {
        $out['undo_id'] = $undoId;
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_link_missing_core_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_link_missing_core_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_link_missing_core_scrub([
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.link_missing_core_undo',
            'dry' => $dry,
            'written' => false,
        ]);
    }
    $file = jeab_contacts_link_missing_core_read_undo($undoId);
    if (!is_array($file) || (string) ($file['source'] ?? '') !== 'contacts.link_missing_core') {
        return jeab_contacts_link_missing_core_scrub([
            'ok' => false,
            'error' => 'Ångerfilen saknas.',
            'action' => 'contacts.link_missing_core_undo',
            'dry' => $dry,
            'written' => false,
        ]);
    }
    try {
        return jeab_contacts_link_missing_core_scrub(jeab_contacts_link_missing_core_undo_run($store->pdo(), $file, $dry, $undoId));
    } catch (Throwable $e) {
        error_log('contacts.link_missing_core_undo: ' . $e->getMessage());

        return jeab_contacts_link_missing_core_scrub([
            'ok' => false,
            'error' => 'Körningen misslyckades.',
            'action' => 'contacts.link_missing_core_undo',
            'dry' => $dry,
            'written' => false,
        ]);
    }
}

/**
 * @param array<string, mixed> $file
 * @return array<string, mixed>
 */
function jeab_contacts_link_missing_core_undo_run(PDO $pdo, array $file, bool $dry, string $undoId): array
{
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $restored = 0;
    $deleted = 0;
    $apply = static function (PDO $pdo) use ($rows, $dry, &$restored, &$deleted): void {
        $created = [];
        $ignore = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $table = (string) ($row['table'] ?? '');
            $id = (string) ($row['id'] ?? '');
            $cid = (string) ($row['contact_id'] ?? '');
            if (!in_array($table, ['reminders', 'bookings', 'purchases'], true) || $id === '' || $cid === '') {
                continue;
            }
            if (jeab_contacts_link_missing_core_unbind($pdo, $table, $id, $cid, $dry)) {
                $restored++;
                $ignore[$table . '|' . $id] = true;
            }
            if (!empty($row['created'])) {
                $created[$cid] = true;
            }
        }
        foreach (array_keys($created) as $cid) {
            if (jeab_contacts_link_missing_core_referenced($pdo, (string) $cid, $ignore)) {
                continue;
            }
            if ($dry) {
                $deleted++;
                continue;
            }
            if (jeab_contacts_link_missing_core_drop($pdo, (string) $cid)) {
                $deleted++;
            }
        }
    };
    if ($dry || !function_exists('jeab_pdo_transaction')) {
        $apply($pdo);
    } else {
        jeab_pdo_transaction($pdo, $apply);
    }

    return [
        'ok' => true,
        'action' => 'contacts.link_missing_core_undo',
        'dry' => $dry,
        'written' => $dry ? 0 : $restored,
        'undo_id' => $undoId,
        'restored' => $restored,
        'deleted' => $deleted,
    ];
}

function jeab_contacts_link_missing_core_unbind(PDO $pdo, string $table, string $id, string $cid, bool $dry): bool
{
    $table = jeab_contacts_link_missing_core_ident($table);
    $cols = jeab_contacts_link_missing_core_columns($pdo, $table);
    $idCol = $table === 'purchases' ? 'reminder_id' : 'id';
    if (!isset($cols[$idCol])) {
        return false;
    }
    if ($table === 'purchases') {
        $stmt = $pdo->prepare('SELECT contact_id, payload FROM purchases WHERE reminder_id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) {
            return false;
        }
        $column = trim((string) ($current['contact_id'] ?? ''));
        $json = jeab_contacts_link_missing_core_json((string) ($current['payload'] ?? ''));
        $jsonCid = trim((string) ($json['contact_id'] ?? ''));
        if ($column !== $cid && $jsonCid !== $cid) {
            return false;
        }
        if ($dry) {
            return true;
        }
        if ($jsonCid === $cid) {
            unset($json['contact_id']);
        }
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return false;
        }
        $nextColumn = $column === $cid ? '' : $column;
        $pdo->prepare('UPDATE purchases SET contact_id = ?, payload = ? WHERE reminder_id = ?')->execute([$nextColumn, $encoded, $id]);
        $again = $pdo->prepare('SELECT contact_id, payload FROM purchases WHERE reminder_id = ?');
        $again->execute([$id]);
        $got = $again->fetch(PDO::FETCH_ASSOC);
        if (!is_array($got)) {
            return false;
        }
        $gotJson = jeab_contacts_link_missing_core_json((string) ($got['payload'] ?? ''));

        return trim((string) ($got['contact_id'] ?? '')) === $nextColumn
            && trim((string) ($gotJson['contact_id'] ?? '')) === ($jsonCid === $cid ? '' : $jsonCid);
    }
    if (!isset($cols['contact_id'])) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE ' . $idCol . ' = ?');
    $stmt->execute([$id]);
    $current = trim((string) $stmt->fetchColumn());
    if ($current !== $cid) {
        return false;
    }
    if ($dry) {
        return true;
    }
    $pdo->prepare('UPDATE ' . $table . ' SET contact_id = ? WHERE ' . $idCol . ' = ? AND contact_id = ?')->execute(['', $id, $cid]);
    $stmt->execute([$id]);

    return trim((string) $stmt->fetchColumn()) === '';
}

function jeab_contacts_link_missing_core_drop(PDO $pdo, string $cid): bool
{
    if ($cid === '' || !jeab_contacts_link_missing_core_table($pdo, 'contacts')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE id = ?');
    $stmt->execute([$cid]);
    if ((string) $stmt->fetchColumn() !== $cid) {
        return false;
    }
    $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$cid]);
    if (jeab_contacts_link_missing_core_table($pdo, 'contact_emails')) {
        try {
            $pdo->prepare('DELETE FROM contact_emails WHERE contact_id = ?')->execute([$cid]);
        } catch (Throwable) {
        }
    }
    $stmt->execute([$cid]);

    return $stmt->fetchColumn() === false;
}
