<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * contacts.sole_audit. Bara SELECT.
 * Per bolag (jarfalla, malmo) och per lager: antal rader, tom contact_id,
 * contact_id som saknar kontakt, och rader där kopian skiljer sig från kontakten.
 * Trådens bolag är $.company eller $.brand. Saknas båda används listradens
 * company-kolumn och dess JSON, samma ställe som inkorgens lista sparar
 * bolaget som sidan visar. contact_id på tråden är row_json, det fält
 * threadsMap läser.
 * Skillnad räknas bara när båda sidor har ett värde (samma regel som paint för
 * telefon, e-post och namn). Adress: kopian är ifylld och gata eller postnummer
 * i kopian inte träffar kontakten. Inga värden lämnar svaret, bara antal.
 * En tråd med kopplad kontakt jämför inte trådens namn- och mejlkopia.
 * Telefon och adress jämförs. differ.thread_ids är id för de trådar som skiljer sig.
 * Tom contact_id på påminnelser, bokningar, köp och ordrar är fel.
 * Ordrar är köpraderna. Inkorg (lista, tråd, ärende) utan contact_id är info,
 * utom när en påminnelse pekar på raden: då är det fel.
 * Solkampanj är en egen lista utanför kontakter. Den räknas inte i täckning
 * eller kvarvarande kopior.
 */

/** @return list<string> */
function jeab_contacts_sole_audit_companies(): array
{
    return ['jarfalla', 'malmo'];
}

/**
 * @return array{total:int,empty_contact_id:int,missing_contact:int,differ:array{phone:int,email:int,name:int,address:int},failed:int}
 */
function jeab_contacts_sole_audit_empty_store(): array
{
    return [
        'total' => 0,
        'empty_contact_id' => 0,
        'missing_contact' => 0,
        'differ' => ['phone' => 0, 'email' => 0, 'name' => 0, 'address' => 0],
        'failed' => 0,
    ];
}

function jeab_contacts_sole_audit_mysql(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function jeab_contacts_sole_audit_table(PDO $pdo, string $table): bool
{
    if (preg_match('/^[a-z_]+$/', $table) !== 1) {
        return false;
    }
    if (jeab_contacts_sole_audit_mysql($pdo)) {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

/** @return array<string, true> */
function jeab_contacts_sole_audit_columns(PDO $pdo, string $table): array
{
    if (!jeab_contacts_sole_audit_table($pdo, $table)) {
        return [];
    }
    $have = [];
    if (jeab_contacts_sole_audit_mysql($pdo)) {
        $stmt = $pdo->query('SHOW COLUMNS FROM ' . $table);
        $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['Field'])) {
                $have[(string) $row['Field']] = true;
            }
        }

        return $have;
    }
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['name'])) {
            $have[(string) $row['name']] = true;
        }
    }

    return $have;
}

function jeab_contacts_sole_audit_fold_sql(string $expr): string
{
    $expr = "COALESCE($expr, '')";
    foreach (['Å' => 'a', 'Ä' => 'a', 'Ö' => 'o', 'å' => 'a', 'ä' => 'a', 'ö' => 'o'] as $from => $to) {
        $expr = "REPLACE($expr, '" . $from . "', '" . $to . "')";
    }
    $expr = "LOWER(TRIM($expr))";
    for ($i = 0; $i < 4; $i++) {
        $expr = "REPLACE($expr, '  ', ' ')";
    }

    return $expr;
}

function jeab_contacts_sole_audit_digits_sql(string $expr): string
{
    $expr = "COALESCE($expr, '')";
    foreach ([' ', '-', '+', '(', ')', '.', '/', "\u{00A0}"] as $char) {
        $expr = "REPLACE($expr, '" . $char . "', '')";
    }

    return $expr;
}

function jeab_contacts_sole_audit_phone_sql(bool $mysql, string $expr): string
{
    $digits = jeab_contacts_sole_audit_digits_sql($expr);
    $tail = $mysql ? "CONCAT('0', SUBSTR($digits, 3))" : "('0' || SUBSTR($digits, 3))";

    return "CASE WHEN $digits LIKE '46%' AND LENGTH($digits) >= 11 THEN $tail ELSE $digits END";
}

function jeab_contacts_sole_audit_json_text(bool $mysql, string $column, string $path): string
{
    if (preg_match('/^\$(\.[A-Za-z_][A-Za-z0-9_]*)+$/', $path) !== 1) {
        return "''";
    }
    if ($mysql) {
        $extract = "JSON_UNQUOTE(JSON_EXTRACT($column, '$path'))";

        return "CASE WHEN JSON_VALID($column) THEN NULLIF($extract, 'null') ELSE NULL END";
    }

    return "CASE WHEN json_valid($column) THEN json_extract($column, '$path') ELSE NULL END";
}

/**
 * @param array<string, true> $cols
 * @param list<string> $paths
 */
function jeab_contacts_sole_audit_value_sql(bool $mysql, string $alias, array $cols, ?string $jsonCol, string $column, array $paths): string
{
    $pieces = [];
    if ($column !== '' && isset($cols[$column]) && preg_match('/^[a-z_]+$/', $column) === 1) {
        $pieces[] = "NULLIF(TRIM(COALESCE($alias.$column, '')), '')";
    }
    if ($jsonCol !== null && isset($cols[$jsonCol]) && preg_match('/^[a-z_]+$/', $jsonCol) === 1) {
        foreach ($paths as $path) {
            $pieces[] = "NULLIF(TRIM(COALESCE(" . jeab_contacts_sole_audit_json_text($mysql, $alias . '.' . $jsonCol, $path) . ", '')), '')";
        }
    }
    if ($pieces === []) {
        return "''";
    }

    return 'COALESCE(' . implode(', ', $pieces) . ", '')";
}

function jeab_contacts_sole_audit_company_sql(string $expr): string
{
    $fold = jeab_contacts_sole_audit_fold_sql($expr);

    return "CASE WHEN $fold IN ('jarfalla', 'jeab') THEN 'jarfalla' WHEN $fold IN ('malmo', 'meab') THEN 'malmo' ELSE '' END";
}

/**
 * @return array<string, array{table:string,company_col:string,company_paths:list<string>,contact_col:string,contact_paths:list<string>,json_col:?string,phone_col:string,phone_paths:list<string>,email_col:string,email_paths:list<string>,name_col:string,name_paths:list<string>,address_col:string,address_paths:list<string>}>
 */
function jeab_contacts_sole_audit_specs(): array
{
    $column = static function (string $table, string $phone, string $email, string $name, string $address): array {
        return [
            'table' => $table,
            'company_col' => 'company',
            'company_paths' => [],
            'contact_col' => 'contact_id',
            'contact_paths' => [],
            'json_col' => null,
            'phone_col' => $phone,
            'phone_paths' => [],
            'email_col' => $email,
            'email_paths' => [],
            'name_col' => $name,
            'name_paths' => [],
            'address_col' => $address,
            'address_paths' => [],
        ];
    };

    return [
        'bookings' => $column('bookings', 'phone', 'email', 'customer_name', 'address'),
        'reminders' => $column('reminders', 'phone', 'email', 'customer_name', 'address'),
        'purchases' => [
            'table' => 'purchases',
            'company_col' => 'company',
            'company_paths' => ['$.company'],
            'contact_col' => 'contact_id',
            'contact_paths' => ['$.contact_id'],
            'json_col' => 'payload',
            'phone_col' => '',
            'phone_paths' => ['$.phone', '$.mobil', '$.customer_phone', '$.applicant.phone'],
            'email_col' => '',
            'email_paths' => ['$.email', '$.customer_email', '$.applicant.email'],
            'name_col' => '',
            'name_paths' => ['$.customer_name', '$.name', '$.huvudsokande', '$.applicant.name'],
            'address_col' => '',
            'address_paths' => ['$.address', '$.adress', '$.huvudsokande_address'],
        ],
        'inkorg_threads' => [
            'table' => 'inkorg_threads',
            'company_col' => '',
            'company_paths' => ['$.company', '$.brand'],
            'contact_col' => '',
            'contact_paths' => ['$.contact_id'],
            'json_col' => 'row_json',
            'phone_col' => '',
            'phone_paths' => ['$.lead_customer.phone', '$.phone', '$.customer_phone'],
            'email_col' => '',
            'email_paths' => ['$.customer_email', '$.lead_customer.email', '$.email'],
            'name_col' => '',
            'name_paths' => ['$.customer_name', '$.lead_customer.name', '$.name'],
            'address_col' => '',
            'address_paths' => ['$.lead_customer.address', '$.address', '$.customer_address'],
        ],
        'inkorg_list' => [
            'table' => 'inkorg_list',
            'company_col' => 'company',
            'company_paths' => ['$.company', '$.brand'],
            'contact_col' => 'contact_id',
            'contact_paths' => ['$.contact_id'],
            'json_col' => 'row_json',
            'phone_col' => '',
            'phone_paths' => ['$.lead_customer.phone', '$.phone', '$.customer_phone'],
            'email_col' => 'customer_email',
            'email_paths' => ['$.customer_email', '$.lead_customer.email', '$.email'],
            'name_col' => '',
            'name_paths' => ['$.customer_name', '$.lead_customer.name', '$.name'],
            'address_col' => '',
            'address_paths' => ['$.lead_customer.address', '$.address', '$.customer_address'],
        ],
    ];
}

/**
 * @param array<string, mixed> $spec
 * @param array<string, true> $cols
 * @param array<string, true> $contactCols
 * @param array<string, true> $listCols
 */
function jeab_contacts_sole_audit_sql(bool $mysql, array $spec, array $cols, array $contactCols, array $listCols = [], bool $ids = false): string
{
    $table = (string) $spec['table'];
    $jsonCol = is_string($spec['json_col'] ?? null) ? (string) $spec['json_col'] : null;
    $company = jeab_contacts_sole_audit_company_sql(jeab_contacts_sole_audit_value_sql(
        $mysql,
        'b',
        $cols,
        $jsonCol,
        (string) $spec['company_col'],
        is_array($spec['company_paths']) ? $spec['company_paths'] : []
    ));
    $listJoin = '';
    if ($table === 'inkorg_threads' && isset($listCols['id'])) {
        $listCompany = jeab_contacts_sole_audit_company_sql(jeab_contacts_sole_audit_value_sql(
            $mysql,
            'jeab_list',
            $listCols,
            isset($listCols['row_json']) ? 'row_json' : null,
            isset($listCols['company']) ? 'company' : '',
            ['$.company', '$.brand']
        ));
        $company = "CASE WHEN ($company) <> '' THEN ($company) ELSE ($listCompany) END";
        $listJoin = ' LEFT JOIN inkorg_list jeab_list ON jeab_list.id = b.id';
    }
    $cid = jeab_contacts_sole_audit_value_sql(
        $mysql,
        'b',
        $cols,
        $jsonCol,
        (string) $spec['contact_col'],
        is_array($spec['contact_paths']) ? $spec['contact_paths'] : []
    );
    $phone = jeab_contacts_sole_audit_value_sql($mysql, 'b', $cols, $jsonCol, (string) $spec['phone_col'], is_array($spec['phone_paths']) ? $spec['phone_paths'] : []);
    $email = jeab_contacts_sole_audit_value_sql($mysql, 'b', $cols, $jsonCol, (string) $spec['email_col'], is_array($spec['email_paths']) ? $spec['email_paths'] : []);
    $name = jeab_contacts_sole_audit_value_sql($mysql, 'b', $cols, $jsonCol, (string) $spec['name_col'], is_array($spec['name_paths']) ? $spec['name_paths'] : []);
    $address = jeab_contacts_sole_audit_value_sql($mysql, 'b', $cols, $jsonCol, (string) $spec['address_col'], is_array($spec['address_paths']) ? $spec['address_paths'] : []);
    $join = 'LEFT JOIN (SELECT NULL AS id, NULL AS owner_email, NULL AS owner_phone, NULL AS owner_name_fold, NULL AS row_json WHERE 0) c ON 0';
    $street = "''";
    $postal = "''";
    if (isset($contactCols['id'])) {
        $join = 'LEFT JOIN contacts c ON c.id = (' . $cid . ')';
        if (isset($contactCols['row_json'])) {
            $street = jeab_contacts_sole_audit_value_sql($mysql, 'c', $contactCols, 'row_json', '', ['$.street']);
            $postal = jeab_contacts_sole_audit_digits_sql(jeab_contacts_sole_audit_value_sql(
                $mysql,
                'c',
                $contactCols,
                'row_json',
                isset($contactCols['postal_code']) ? 'postal_code' : '',
                ['$.postal_code']
            ));
        }
    }
    $emailLive = isset($contactCols['owner_email']) ? "LOWER(TRIM(COALESCE(c.owner_email, '')))" : "''";
    $phoneLive = isset($contactCols['owner_phone']) ? jeab_contacts_sole_audit_phone_sql($mysql, 'c.owner_phone') : "''";
    $nameLive = isset($contactCols['owner_name_fold']) ? jeab_contacts_sole_audit_fold_sql('c.owner_name_fold') : "''";
    $hit = isset($contactCols['id']) ? 'CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END' : '0';
    $threadCopy = $table === 'inkorg_threads' || $table === 'inkorg_list';
    $emailSum = $threadCopy
        ? '0'
        : 'SUM(CASE WHEN contact_hit = 1 AND email_copy <> \'\' AND email_live <> \'\' AND email_copy <> email_live THEN 1 ELSE 0 END)';
    $nameSum = $threadCopy
        ? '0'
        : 'SUM(CASE WHEN contact_hit = 1 AND name_copy <> \'\' AND name_live <> \'\' AND name_copy <> name_live THEN 1 ELSE 0 END)';
    $addrWhen = 'addr_copy <> \'\' AND ('
        . '(addr_street <> \'\' AND NOT (INSTR(addr_copy, addr_street) > 0 AND SUBSTR(addr_copy, INSTR(addr_copy, addr_street) + LENGTH(addr_street), 1) IN (\'\', \' \', \',\', \'.\')))'
        . ' OR (LENGTH(addr_postal) >= 5 AND INSTR(addr_digits, addr_postal) = 0)'
        . ')';
    $phoneWhen = 'phone_copy <> \'\' AND phone_live <> \'\' AND phone_copy <> phone_live';
    $inner = 'SELECT ' . ($ids ? 'b.id AS row_id, ' : '') . $company . ' AS company_key, ' . $cid . ' AS cid, ' . $hit . ' AS contact_hit, '
        . jeab_contacts_sole_audit_phone_sql($mysql, $phone) . ' AS phone_copy, ' . $phoneLive . ' AS phone_live, '
        . 'LOWER(TRIM(' . $email . ')) AS email_copy, ' . $emailLive . ' AS email_live, '
        . jeab_contacts_sole_audit_fold_sql($name) . ' AS name_copy, ' . $nameLive . ' AS name_live, '
        . jeab_contacts_sole_audit_fold_sql($address) . ' AS addr_copy, '
        . jeab_contacts_sole_audit_digits_sql($address) . ' AS addr_digits, '
        . jeab_contacts_sole_audit_fold_sql($street) . ' AS addr_street, '
        . $postal . ' AS addr_postal'
        . ' FROM ' . $table . ' b' . $listJoin . ' ' . $join;
    if ($ids) {
        return 'SELECT company_key, row_id FROM (' . $inner . ') jeab_audit WHERE contact_hit = 1 AND ((' . $phoneWhen . ') OR (' . $addrWhen . '))';
    }

    return 'SELECT company_key, COUNT(*) AS total,'
        . ' SUM(CASE WHEN cid = \'\' THEN 1 ELSE 0 END) AS empty_contact_id,'
        . ' SUM(CASE WHEN cid <> \'\' AND contact_hit = 0 THEN 1 ELSE 0 END) AS missing_contact,'
        . ' SUM(CASE WHEN contact_hit = 1 AND ' . $phoneWhen . ' THEN 1 ELSE 0 END) AS d_phone,'
        . ' ' . $emailSum . ' AS d_email,'
        . ' ' . $nameSum . ' AS d_name,'
        . ' SUM(CASE WHEN contact_hit = 1 AND ' . $addrWhen . ' THEN 1 ELSE 0 END) AS d_address'
        . ' FROM (' . $inner . ') jeab_audit GROUP BY company_key';
}

/**
 * @param array<string, mixed> $spec
 * @return array<string, array{total:int,empty_contact_id:int,missing_contact:int,differ:array{phone:int,email:int,name:int,address:int}}>
 */
function jeab_contacts_sole_audit_store(PDO $pdo, array $spec, array $contactCols): array
{
    $out = [];
    foreach (jeab_contacts_sole_audit_companies() as $company) {
        $out[$company] = jeab_contacts_sole_audit_empty_store();
    }
    $table = (string) ($spec['table'] ?? '');
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if ($cols === []) {
        return $out;
    }
    $mysql = jeab_contacts_sole_audit_mysql($pdo);
    $listCols = [];
    if ($table === 'inkorg_threads' && jeab_contacts_sole_audit_table($pdo, 'inkorg_list')) {
        $listCols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_list');
    }
    $sql = jeab_contacts_sole_audit_sql($mysql, $spec, $cols, $contactCols, $listCols);
    $stmt = $pdo->query($sql);
    $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $company = (string) ($row['company_key'] ?? '');
        if (!isset($out[$company])) {
            continue;
        }
        $out[$company] = [
            'total' => (int) ($row['total'] ?? 0),
            'empty_contact_id' => (int) ($row['empty_contact_id'] ?? 0),
            'missing_contact' => (int) ($row['missing_contact'] ?? 0),
            'differ' => [
                'phone' => (int) ($row['d_phone'] ?? 0),
                'email' => (int) ($row['d_email'] ?? 0),
                'name' => (int) ($row['d_name'] ?? 0),
                'address' => (int) ($row['d_address'] ?? 0),
            ],
            'failed' => 0,
        ];
    }
    if (($table === 'inkorg_threads' || $table === 'inkorg_list') && isset($cols['id'])) {
        $seen = [];
        foreach ($out as $company => $bucket) {
            $out[$company]['differ']['thread_ids'] = [];
            $seen[$company] = [];
        }
        $idSql = jeab_contacts_sole_audit_sql($mysql, $spec, $cols, $contactCols, $listCols, true);
        $idStmt = $pdo->query($idSql);
        $idRows = $idStmt instanceof PDOStatement ? $idStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($idRows as $idRow) {
            if (!is_array($idRow)) {
                continue;
            }
            $company = (string) ($idRow['company_key'] ?? '');
            $rowId = trim((string) ($idRow['row_id'] ?? ''));
            if ($rowId === '' || !isset($out[$company])) {
                continue;
            }
            $seen[$company][$rowId] = true;
        }
        foreach ($seen as $company => $ids) {
            $list = array_keys($ids);
            sort($list, SORT_STRING);
            $out[$company]['differ']['thread_ids'] = $list;
        }
    }

    return $out;
}

function jeab_contacts_sole_audit_office(string $raw): string
{
    $fold = trim($raw);
    if (function_exists('mb_strtolower')) {
        $fold = mb_strtolower($fold, 'UTF-8');
    } else {
        $fold = strtolower($fold);
    }
    $fold = strtr($fold, ['Å' => 'a', 'Ä' => 'a', 'Ö' => 'o', 'å' => 'a', 'ä' => 'a', 'ö' => 'o']);
    if (in_array($fold, ['jarfalla', 'jeab'], true)) {
        return 'jarfalla';
    }
    if (in_array($fold, ['malmo', 'meab'], true)) {
        return 'malmo';
    }

    return '';
}

/** @param array<string, mixed> $json */
function jeab_contacts_sole_audit_case(array $json): bool
{
    if (!empty($json['deleted']) || trim((string) ($json['deleted_at'] ?? '')) !== '') {
        return false;
    }
    $lead = is_array($json['lead_customer'] ?? null) ? $json['lead_customer'] : [];
    $email = trim((string) ($lead['email'] ?? ''));
    if ($email === '') {
        $email = trim((string) ($json['customer_email'] ?? ''));
    }
    $phone = trim((string) ($lead['phone'] ?? ''));
    if ($phone === '') {
        $phone = trim((string) ($json['phone'] ?? ''));
    }
    if ($email === '' && $phone === '') {
        return false;
    }
    $first = is_array($json['first'] ?? null) ? $json['first'] : [];
    $labels = is_array($first['labels'] ?? null) ? $first['labels'] : [];
    if ($labels === [] && is_array($json['labels'] ?? null)) {
        $labels = $json['labels'];
    }
    $type = (string) ($labels['type'] ?? $json['list_type'] ?? '');
    if ($type === 'Ny lead') {
        return true;
    }

    return is_array($json['lead_customer'] ?? null);
}

/**
 * Tom contact_id på kärnan är fel. Inkorg utan contact_id är alltid info (historiktrådar);
 * inkorg_with_reminder räknar dem som reminders.thread_id pekar på.
 *
 * @param array<string, array<string, mixed>> $byCompany
 * @return array{errors:array<string, mixed>,info:array<string, mixed>}
 */
function jeab_contacts_sole_audit_gaps(PDO $pdo, array $byCompany): array
{
    $core = static function (): array {
        return [
            'reminders' => 0,
            'bookings' => 0,
            'purchases' => 0,
            'orders' => 0,
            'inkorg_list' => 0,
            'inkorg_threads' => 0,
            'inkorg_cases' => 0,
        ];
    };
    $inbox = static function (): array {
        return ['inkorg_list' => 0, 'inkorg_threads' => 0, 'inkorg_cases' => 0, 'inkorg_with_reminder' => 0];
    };
    $errors = ['total' => 0, 'failed' => 0];
    $info = [];
    foreach (jeab_contacts_sole_audit_companies() as $company) {
        $errors[$company] = $core();
        $info[$company] = $inbox();
        foreach (['reminders', 'bookings', 'purchases'] as $name) {
            $store = is_array($byCompany[$company][$name] ?? null) ? $byCompany[$company][$name] : [];
            $empty = (int) ($store['empty_contact_id'] ?? 0);
            $errors[$company][$name] = $empty;
            $errors['total'] += $empty;
        }
        $errors[$company]['orders'] = $errors[$company]['purchases'];
    }
    try {
        $linked = [];
        $remCols = jeab_contacts_sole_audit_columns($pdo, 'reminders');
        if (isset($remCols['thread_id'])) {
            $stmt = $pdo->query('SELECT thread_id FROM reminders');
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['thread_id'] ?? ''));
                if ($id !== '') {
                    $linked[$id] = true;
                }
            }
        }
        $listById = [];
        $listCols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_list');
        if (isset($listCols['id'])) {
            $pick = ['id'];
            foreach (['company', 'contact_id', 'row_json'] as $column) {
                if (isset($listCols[$column])) {
                    $pick[] = $column;
                }
            }
            $stmt = $pdo->query('SELECT ' . implode(', ', $pick) . ' FROM inkorg_list');
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                $json = jeab_contacts_sole_audit_json((string) ($row['row_json'] ?? ''));
                $listById[$id] = ['company' => (string) ($row['company'] ?? ''), 'json' => $json];
                jeab_contacts_sole_audit_gap_row($errors, $info, 'inkorg_list', $id, (string) ($row['contact_id'] ?? ''), (string) ($row['company'] ?? ''), $json, isset($linked[$id]));
            }
        }
        $threadCols = jeab_contacts_sole_audit_columns($pdo, 'inkorg_threads');
        if (isset($threadCols['id'])) {
            $pick = ['id'];
            if (isset($threadCols['row_json'])) {
                $pick[] = 'row_json';
            }
            $stmt = $pdo->query('SELECT ' . implode(', ', $pick) . ' FROM inkorg_threads');
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                $json = jeab_contacts_sole_audit_json((string) ($row['row_json'] ?? ''));
                $company = jeab_contacts_sole_audit_office((string) ($json['company'] ?? ''));
                if ($company === '') {
                    $company = jeab_contacts_sole_audit_office((string) ($json['brand'] ?? ''));
                }
                if ($company === '' && isset($listById[$id])) {
                    $company = jeab_contacts_sole_audit_office((string) $listById[$id]['company']);
                    if ($company === '') {
                        $listJson = is_array($listById[$id]['json'] ?? null) ? $listById[$id]['json'] : [];
                        $company = jeab_contacts_sole_audit_office((string) ($listJson['company'] ?? ''));
                        if ($company === '') {
                            $company = jeab_contacts_sole_audit_office((string) ($listJson['brand'] ?? ''));
                        }
                    }
                }
                $cid = trim((string) ($json['contact_id'] ?? ''));
                jeab_contacts_sole_audit_gap_row($errors, $info, 'inkorg_threads', $id, $cid, $company, $json, isset($linked[$id]));
            }
        }
    } catch (Throwable) {
        $errors['failed'] = 1;
    }

    return ['errors' => $errors, 'info' => $info];
}

/** @return array<string, mixed> */
function jeab_contacts_sole_audit_json(string $raw): array
{
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string, mixed> $errors
 * @param array<string, mixed> $info
 * @param array<string, mixed> $json
 */
function jeab_contacts_sole_audit_gap_row(array &$errors, array &$info, string $table, string $id, string $columnId, string $companyRaw, array $json, bool $linked): void
{
    if ($id === '' || !isset($errors['jarfalla']) || !is_array($errors['jarfalla'])) {
        return;
    }
    $cid = trim($columnId);
    if ($cid === '') {
        $cid = trim((string) ($json['contact_id'] ?? ''));
    }
    if ($cid !== '') {
        return;
    }
    $company = jeab_contacts_sole_audit_office($companyRaw);
    if ($company === '') {
        $company = jeab_contacts_sole_audit_office((string) ($json['company'] ?? ''));
    }
    if ($company === '') {
        $company = jeab_contacts_sole_audit_office((string) ($json['brand'] ?? ''));
    }
    if (!isset($errors[$company]) || !is_array($errors[$company]) || !isset($info[$company]) || !is_array($info[$company])) {
        return;
    }
    // Historiktrådar utan kontakt visas och räknas som info, även när en påminnelse pekar på dem.
    if ($linked) {
        $info[$company]['inkorg_with_reminder'] = (int) ($info[$company]['inkorg_with_reminder'] ?? 0) + 1;
    }
    $info[$company][$table] = (int) ($info[$company][$table] ?? 0) + 1;
    if (jeab_contacts_sole_audit_case($json)) {
        $info[$company]['inkorg_cases'] = (int) ($info[$company]['inkorg_cases'] ?? 0) + 1;
    }
}

function jeab_contacts_sole_audit_marker(): bool
{
    if (!function_exists('jeab_data_dir')) {
        return false;
    }
    $dir = rtrim(jeab_data_dir(), '/');
    if ($dir === '') {
        return false;
    }

    return is_file($dir . '/inkorg/contacts.json.migrated');
}

function jeab_contacts_sole_audit_inbox_count(PDO $pdo): int
{
    if (!jeab_contacts_sole_audit_table($pdo, 'inkorg_contacts')) {
        return 0;
    }
    $stmt = $pdo->query('SELECT COUNT(*) FROM inkorg_contacts');
    if (!$stmt instanceof PDOStatement) {
        return 0;
    }

    return (int) $stmt->fetchColumn();
}

/**
 * @return array{value:int,source:string}
 */
function jeab_contacts_sole_audit_flag(string $name): array
{
    $resolved = function_exists('jeab_flag_effective')
        ? jeab_flag_effective($name)
        : ['value' => 0, 'source' => 'default'];
    $source = (string) ($resolved['source'] ?? 'default');
    if ($source === 'file') {
        $source = 'flags.json';
    }
    if (!in_array($source, ['env', 'flags.json', 'settings', 'default', 'globals'], true)) {
        $source = 'default';
    }

    return [
        'value' => !empty($resolved['value']) ? 1 : 0,
        'source' => $source,
    ];
}

/**
 * Släpper igenom bara räknare och kända etiketter.
 *
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function jeab_contacts_sole_audit_scrub(array $node): array
{
    $allow = [
        'contacts.sole_audit',
        'Fel token.',
        'Ingen lagring.',
        'env',
        'flags.json',
        'settings',
        'default',
        'globals',
        'Solkampanj är en egen lista utanför kontakter.',
    ];
    $out = [];
    foreach ($node as $key => $value) {
        if ((string) $key === 'thread_ids' && is_array($value)) {
            $ids = [];
            foreach ($value as $id) {
                if (!is_string($id) || $id === '' || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
                    continue;
                }
                if (preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $id) !== 1) {
                    continue;
                }
                $ids[$id] = true;
            }
            $list = array_keys($ids);
            sort($list, SORT_STRING);
            $out['thread_ids'] = $list;
            continue;
        }
        if (is_array($value)) {
            $out[(string) $key] = jeab_contacts_sole_audit_scrub($value);
            continue;
        }
        if (is_int($value) || is_bool($value)) {
            $out[(string) $key] = $value;
            continue;
        }
        if (is_string($value) && in_array($value, $allow, true)) {
            $out[(string) $key] = $value;
        }
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function jeab_contacts_sole_audit(?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return [
            'ok' => false,
            'error' => 'Ingen lagring.',
            'action' => 'contacts.sole_audit',
            'read_only' => true,
            'written' => false,
            'dry' => true,
        ];
    }
    $pdo = $store->pdo();
    $contactCols = jeab_contacts_sole_audit_columns($pdo, 'contacts');
    $byCompany = [];
    foreach (jeab_contacts_sole_audit_companies() as $company) {
        $byCompany[$company] = [];
    }
    foreach (jeab_contacts_sole_audit_specs() as $name => $spec) {
        try {
            $counts = jeab_contacts_sole_audit_store($pdo, $spec, $contactCols);
        } catch (Throwable) {
            $counts = [];
            foreach (jeab_contacts_sole_audit_companies() as $company) {
                $empty = jeab_contacts_sole_audit_empty_store();
                $empty['failed'] = 1;
                $counts[$company] = $empty;
            }
        }
        foreach (jeab_contacts_sole_audit_companies() as $company) {
            $byCompany[$company][$name] = $counts[$company] ?? jeab_contacts_sole_audit_empty_store();
        }
    }
    foreach (jeab_contacts_sole_audit_companies() as $company) {
        $byCompany[$company]['orders'] = $byCompany[$company]['purchases'] ?? jeab_contacts_sole_audit_empty_store();
    }
    try {
        $inbox = jeab_contacts_sole_audit_inbox_count($pdo);
    } catch (Throwable) {
        $inbox = 0;
    }
    $gaps = jeab_contacts_sole_audit_gaps($pdo, $byCompany);

    return jeab_contacts_sole_audit_scrub([
        'ok' => true,
        'action' => 'contacts.sole_audit',
        'read_only' => true,
        'written' => false,
        'dry' => true,
        'flags' => [
            'CONTACTS_SOLE' => jeab_contacts_sole_audit_flag('CONTACTS_SOLE'),
            'CONTACTS_STRICT' => jeab_contacts_sole_audit_flag('CONTACTS_STRICT'),
        ],
        'inkorg_contacts_migrated' => jeab_contacts_sole_audit_marker(),
        'inkorg_contacts' => $inbox,
        'errors' => $gaps['errors'],
        'info' => $gaps['info'],
        'co' => $byCompany,
        'note' => 'Solkampanj är en egen lista utanför kontakter.',
    ]);
}
