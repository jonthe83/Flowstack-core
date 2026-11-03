<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Hook data.inkorg_contact_create.
 * Hittar inkorgs-id som inte blev kontakter (fellogg, log_lines, inkorg-trace)
 * och skapar en ny kontakt per id. Befintliga kontakter uppdateras inte
 * och slås aldrig ihop. Inget mejl eller sms skickas.
 * Torr körning jämför e-post, svenskt telefonnummer och adress+postnummer
 * mot registret och mot de andra kandidaterna. Träff blir osäker och skapas
 * inte. only_ids begränsar en skarp körning till exakt de id:n.
 * dry saknas betyder dry=1. Flaggan inkorg_contact_create_hold tvingar dry.
 */

function jeab_inkorg_contact_create_dry(array $input, bool $honorHold = true): bool
{
    if ($honorHold && function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('inkorg_contact_create_hold')) {
        return true;
    }
    if (array_key_exists('write', $input) && ($input['write'] === 1 || $input['write'] === '1' || $input['write'] === true)) {
        return false;
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_inkorg_contact_create_failure(string $text): bool
{
    $text = strtolower($text);
    // Texterna är de som skrivs vid misslyckat kontaktskapande:
    // inkorg_contact_link.php «skapades inte», contacts_sole.inflow_fail
    // (surface + undantagsklass och ref), kontakter.php «Kunde inte koppla
    // kontakt…», kontakter_migrate.php «Kontakten kunde inte sparas.»,
    // contacts_sole.php «En kontakt kunde inte skapas.», inkorg_auto.php
    // «kontakt kunde inte skapas».
    foreach ([
        'inkorg_contact_create',
        'skapades inte',
        'create_failed',
        'contacts_sole.inflow_link',
        'contacts_sole.divert_property',
        'kunde inte koppla kontakt',
        'kontakten kunde inte sparas',
        'inkorgens kontakter kunde inte arkiveras',
        'kontakt kunde inte skapas',
        // SQL-sparningen kastar de här. Lead-vägen fångar dem och skriver ingen app_error.
        'kunde inte spara kontakten',
        'kontaktregistret kan inte sparas',
        'inkorgen kan inte sparas',
    ] as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function jeab_inkorg_contact_create_ids_in(string $text): array
{
    if (!jeab_inkorg_contact_create_failure($text)) {
        return [];
    }
    $ids = [];
    $patterns = [
        '/\bc-[a-z0-9]{6,}\b/',
        '/\bkt_[a-f0-9]{8,32}\b/',
        '/\bink_[a-f0-9]{8,64}\b/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, strtolower($text), $match) < 1) {
            continue;
        }
        foreach ($match[0] ?? [] as $id) {
            $ids[(string) $id] = true;
        }
    }
    if ($ids === []) {
        return [];
    }
    $out = array_keys($ids);
    sort($out, SORT_STRING);

    return $out;
}

function jeab_inkorg_contact_create_ref_ok(string $id): bool
{
    return preg_match('/\Ac-[a-z0-9][a-z0-9_-]{0,62}\z/', $id) === 1
        || preg_match('/\Akt_[a-f0-9]{8,32}\z/', $id) === 1
        || preg_match('/\Aink_[a-f0-9]{8,64}\z/', $id) === 1;
}

/** @param array<string, true> $into */
function jeab_inkorg_contact_create_keep(array &$into, string $id, string $text): void
{
    $id = strtolower(trim($id));
    if ($id === '' || !jeab_inkorg_contact_create_ref_ok($id) || !jeab_inkorg_contact_create_failure($text)) {
        return;
    }
    $into[$id] = true;
}

/** @param array<string, true> $into @param array<string, mixed> $row */
function jeab_inkorg_contact_create_keep_row(array &$into, array $row, string $text): void
{
    foreach (['ref', 'inbox_id', 'inboxId', 'thread_id', 'threadId', 'contact_id', 'contactId'] as $key) {
        if (isset($row[$key]) && is_string($row[$key])) {
            jeab_inkorg_contact_create_keep($into, $row[$key], $text);
        }
    }
    $ctx = $row['ctx'] ?? null;
    if (is_array($ctx)) {
        foreach (['ref', 'inbox_id', 'inboxId', 'thread_id', 'threadId', 'contact_id', 'contactId', 'id'] as $key) {
            if (isset($ctx[$key]) && is_string($ctx[$key])) {
                jeab_inkorg_contact_create_keep($into, $ctx[$key], $text . ' ' . (string) ($row['where'] ?? '') . ' ' . (string) ($row['k'] ?? ''));
            }
        }
    }
    $payload = $row['payload'] ?? null;
    if (is_string($payload) && $payload !== '' && $payload !== $text) {
        jeab_inkorg_contact_create_collect($into, $payload);
    }
}

/** @param array<string, true> $into */
function jeab_inkorg_contact_create_collect(array &$into, string $text): void
{
    foreach (jeab_inkorg_contact_create_ids_in($text) as $id) {
        $into[$id] = true;
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return;
    }
    jeab_inkorg_contact_create_keep_row($into, $decoded, $text);
}

/**
 * @param array<string, int> $debug
 * @return list<string>
 */
function jeab_inkorg_contact_create_found(array $input, ?array &$debug = null, ?PDO $also = null): array
{
    $ids = [];
    $debug = [
        'app_errors_file' => 0,
        'trace_file' => 0,
        'log_lines' => 0,
        'kind_app_error' => 0,
        'type_app_error' => 0,
        'level_error' => 0,
        'date_window' => 0,
        'older_than_90d' => 0,
        'json_docs' => 0,
        'co' => 0,
        'failure_text' => 0,
        'refs' => 0,
        'message_prefixes' => [],
    ];
    foreach ((array) ($input['ids'] ?? []) as $id) {
        $id = strtolower(trim((string) $id));
        if (preg_match('/\Ac-[a-z0-9]{6,}\z/', $id) === 1) {
            $ids[$id] = true;
        }
    }
    $path = function_exists('jeab_app_errors_path') ? jeab_app_errors_path() : '';
    if ($path !== '' && is_file($path)) {
        $raw = file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
                if (trim((string) $line) === '') {
                    continue;
                }
                $debug['app_errors_file']++;
                jeab_inkorg_contact_create_collect($ids, (string) $line);
            }
        }
    }
    $trace = function_exists('jeab_inkorg_trace_path') ? jeab_inkorg_trace_path() : '';
    if ($trace !== '' && is_file($trace)) {
        $raw = file_get_contents($trace);
        if (is_string($raw) && trim($raw) !== '') {
            $debug['trace_file'] = 1;
            jeab_inkorg_contact_create_collect($ids, $raw);
        }
    }
    foreach (jeab_inkorg_contact_create_sql_scan($also, $debug) as $text) {
        jeab_inkorg_contact_create_collect($ids, $text);
    }
    $out = array_keys($ids);
    sort($out, SORT_STRING);
    $debug['refs'] = count($out);

    return $out;
}

/** @return list<PDO> */
function jeab_inkorg_contact_create_pdos(?PDO $also): array
{
    $list = [];
    $push = static function (?PDO $pdo) use (&$list): void {
        if (!$pdo instanceof PDO) {
            return;
        }
        $id = spl_object_id($pdo);
        if (isset($list[$id])) {
            return;
        }
        $list[$id] = $pdo;
    };
    $push($also);
    // jeab_log_pdo() skapar log_store-index och får inte anropas i en torr läsning.
    $push(function_exists('jeab_log_pdo_raw') ? jeab_log_pdo_raw() : null);
    $push(function_exists('jeab_sql_store_pdo') ? jeab_sql_store_pdo() : null);
    $push(function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);

    return array_values($list);
}

/** @return list<string> */
function jeab_inkorg_contact_create_columns(PDO $pdo, string $table): array
{
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $table) !== 1) {
        return [];
    }
    $names = [];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        } else {
            $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        }
        if ($stmt instanceof PDOStatement) {
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = (string) ($row['Field'] ?? $row['name'] ?? '');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
    } catch (Throwable) {
        $names = [];
    }
    if ($names !== []) {
        return $names;
    }
    try {
        $stmt = $pdo->query('SELECT * FROM ' . $table . ' LIMIT 0');
        if ($stmt instanceof PDOStatement) {
            for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
                $meta = $stmt->getColumnMeta($i);
                $name = is_array($meta) ? (string) ($meta['name'] ?? '') : '';
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
    } catch (Throwable) {
    }

    return $names;
}

/**
 * Första 60 tecknen av meddelandet. Mejl, telefon och personnummer maskeras.
 */
function jeab_inkorg_contact_create_message_prefix(string $text): string
{
    $message = '';
    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            $message = trim((string) ($decoded['surface'] ?? ''));
        }
    }
    if ($message === '') {
        $message = trim($text);
    }
    if (function_exists('jeab_log_mask_text')) {
        $message = jeab_log_mask_text($message);
    } else {
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[mejl]', $message) ?? $message;
        $message = preg_replace('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', '[pnr]', $message) ?? $message;
    }
    $message = preg_replace('/\s+/', ' ', $message) ?? $message;
    $message = trim($message);
    if (function_exists('mb_substr')) {
        return mb_substr($message, 0, 60, 'UTF-8');
    }

    return substr($message, 0, 60);
}

function jeab_inkorg_contact_create_count(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Live log_lines har inte alltid kolumnen kind. jeab_log_lines_payloads
 * (src/log_store.php) filtrerar bara WHERE kind = 'app_error' och lämnar
 * tillbaka en tom lista när kolumnen saknas eller värdet ligger i type.
 * Den tomma listan är en array, så den gamla grenen i found() slog inte
 * över till json_docs. Datum och co används inte som filter.
 *
 * @param array<string, int> $debug
 * @return list<string>
 */
function jeab_inkorg_contact_create_sql_scan(?PDO $also, array &$debug): array
{
    $texts = [];
    $seen = [];
    $prefixes = [];
    $notePrefix = static function (string $text) use (&$prefixes): void {
        $prefix = jeab_inkorg_contact_create_message_prefix($text);
        if ($prefix === '') {
            return;
        }
        $prefixes[$prefix] = ($prefixes[$prefix] ?? 0) + 1;
    };
    $keep = static function (string $text) use (&$texts, &$seen, &$debug): void {
        $text = trim($text);
        if ($text === '' || !jeab_inkorg_contact_create_failure($text)) {
            return;
        }
        $hash = hash('sha256', $text);
        if (isset($seen[$hash])) {
            return;
        }
        $seen[$hash] = true;
        $debug['failure_text']++;
        $texts[] = $text;
    };
    $cut = date('Y-m-d H:i:s', time() - 90 * 86400);
    foreach (jeab_inkorg_contact_create_pdos($also) as $pdo) {
        $cols = jeab_inkorg_contact_create_columns($pdo, 'log_lines');
        if ($cols !== []) {
            $debug['log_lines'] += jeab_inkorg_contact_create_count($pdo, 'SELECT COUNT(*) FROM log_lines');
            if (in_array('kind', $cols, true)) {
                $debug['kind_app_error'] += jeab_inkorg_contact_create_count(
                    $pdo,
                    'SELECT COUNT(*) FROM log_lines WHERE kind IN (?, ?)',
                    ['app_error', 'app_errors']
                );
            }
            if (in_array('type', $cols, true)) {
                $debug['type_app_error'] += jeab_inkorg_contact_create_count(
                    $pdo,
                    'SELECT COUNT(*) FROM log_lines WHERE type IN (?, ?)',
                    ['app_error', 'app_errors']
                );
            }
            $payloadCol = '';
            foreach (['payload', 'body', 'line', 'data', 'message'] as $name) {
                if (in_array($name, $cols, true)) {
                    $payloadCol = $name;
                    break;
                }
            }
            $textCols = [];
            foreach (['payload', 'body', 'line', 'data', 'message', 'surface'] as $name) {
                if (in_array($name, $cols, true)) {
                    $textCols[] = $name;
                }
            }
            $where = [];
            $params = [];
            if (in_array('kind', $cols, true)) {
                $where[] = 'kind IN (?, ?)';
                array_push($params, 'app_error', 'app_errors');
            }
            if (in_array('type', $cols, true)) {
                $where[] = 'type IN (?, ?)';
                array_push($params, 'app_error', 'app_errors');
            }
            if ($payloadCol !== '') {
                foreach ([
                    '%Kunde inte spara kontakten%',
                    '%Kontaktregistret kan inte sparas%',
                    '%Inkorgen kan inte sparas%',
                ] as $needle) {
                    $where[] = $payloadCol . ' LIKE ?';
                    $params[] = $needle;
                }
            }
            $select = [];
            foreach (array_merge($textCols, ['ref', 'thread_id', 'contact_id', 'at', 'co', 'company', 'level']) as $name) {
                if ($name !== '' && in_array($name, $cols, true) && !in_array($name, $select, true)) {
                    $select[] = $name;
                }
            }
            $rows = [];
            if ($select !== [] && $where !== []) {
                try {
                    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM log_lines WHERE ' . implode(' OR ', $where));
                    $stmt->execute($params);
                    $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $rows = is_array($fetched) ? $fetched : [];
                } catch (Throwable) {
                    $rows = [];
                }
            }
            if ($rows === [] && $payloadCol !== '') {
                try {
                    $stmt = $pdo->prepare(
                        'SELECT ' . $payloadCol . ' AS payload FROM log_lines WHERE ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ? OR ' . $payloadCol . ' LIKE ?'
                    );
                    $stmt->execute(['%inkorg_contact_create%', '%skapades inte%', '%contacts_sole.inflow_link%', '%kunde inte koppla kontakt%', '%kontakten kunde inte sparas%', '%kontakt kunde inte skapas%', '%Kunde inte spara kontakten%', '%Kontaktregistret kan inte sparas%', '%Inkorgen kan inte sparas%']);
                    $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $rows = is_array($fetched) ? $fetched : [];
                } catch (Throwable) {
                    $rows = [];
                }
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $level = strtolower(trim((string) ($row['level'] ?? '')));
                if ($level === '' || $level === 'error') {
                    $debug['level_error']++;
                }
                $at = trim((string) ($row['at'] ?? ''));
                if ($at !== '' && $at < $cut) {
                    $debug['older_than_90d']++;
                }
                $debug['date_window']++;
                $debug['co']++;
                $parts = [];
                foreach (['payload', 'body', 'line', 'data', 'message', 'surface'] as $name) {
                    $part = $row[$name] ?? '';
                    if (is_resource($part)) {
                        $part = stream_get_contents($part);
                    }
                    if (is_string($part) && trim($part) !== '') {
                        $parts[] = $part;
                    }
                }
                $text = implode("\n", $parts);
                foreach (['ref', 'thread_id', 'contact_id'] as $name) {
                    $extra = trim((string) ($row[$name] ?? ''));
                    if ($extra !== '' && !str_contains($text, $extra)) {
                        $text .= ' ' . $extra;
                    }
                }
                $notePrefix($text);
                $keep($text);
            }
        }
        $docCols = jeab_inkorg_contact_create_columns($pdo, 'json_docs');
        if (!in_array('payload', $docCols, true) || !in_array('doc_key', $docCols, true)) {
            continue;
        }
        try {
            $stmt = $pdo->query("SELECT payload FROM json_docs WHERE doc_key IN ('app_error', 'app_errors', 'app-errors')");
            $docs = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable) {
            $docs = [];
        }
        if (!is_array($docs)) {
            continue;
        }
        foreach ($docs as $payload) {
            if (!is_string($payload) || trim($payload) === '') {
                continue;
            }
            $lines = preg_split("/\r\n|\n|\r/", $payload) ?: [];
            $hit = false;
            foreach ($lines as $line) {
                if (jeab_inkorg_contact_create_failure((string) $line)) {
                    $debug['json_docs']++;
                    $keep((string) $line);
                    $hit = true;
                }
            }
            if (!$hit) {
                $before = $debug['failure_text'];
                $keep($payload);
                if ($debug['failure_text'] > $before) {
                    $debug['json_docs']++;
                }
            }
        }
    }
    arsort($prefixes, SORT_NUMERIC);
    $top = [];
    foreach ($prefixes as $prefix => $n) {
        $top[] = ['prefix' => (string) $prefix, 'n' => (int) $n];
        if (count($top) >= 10) {
            break;
        }
    }
    $debug['message_prefixes'] = $top;

    return $texts;
}

/**
 * Ny kontakt. Andra rader i registret lämnas som de är.
 *
 * @param array<string, mixed> $hint
 * @return array<string, mixed>|null
 */
function jeab_kontakt_create_unmerged(array $hint): ?array
{
    if (!function_exists('jeab_kontakt_blank') || !function_exists('jeab_kontakter_mutate')) {
        return null;
    }
    $fresh = jeab_kontakt_blank($hint);

    return jeab_kontakter_mutate(static function (array &$contacts) use ($fresh): ?array {
        $id = (string) ($fresh['id'] ?? '');
        if ($id === '' || isset($contacts[$id])) {
            return null;
        }
        $contacts[$id] = $fresh;

        return $fresh;
    });
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_create_hint(array $row): array
{
    $emails = function_exists('jeab_inkorg_contact_link_row_emails')
        ? jeab_inkorg_contact_link_row_emails($row)
        : [];
    $name = trim((string) ($row['name'] ?? ''));
    $phone = trim((string) ($row['mobile'] ?? $row['phone'] ?? ''));
    $street = trim((string) ($row['address'] ?? $row['street'] ?? ''));
    $city = trim((string) ($row['town'] ?? $row['city'] ?? ''));
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if ($name === '') {
            $name = trim((string) ($owner['name'] ?? ''));
        }
        $ownerEmail = trim((string) ($owner['email'] ?? ''));
        if ($ownerEmail !== '' && !in_array(strtolower($ownerEmail), array_map('strtolower', $emails), true)) {
            $emails[] = $ownerEmail;
        }
        if ($phone === '') {
            $phone = trim((string) ($owner['phone'] ?? $owner['mobile'] ?? ''));
        }
    }

    return [
        'name' => $name,
        'email' => $emails[0] ?? trim((string) ($row['email'] ?? '')),
        'phone' => $phone,
        'street' => $street,
        'city' => $city,
        'source' => 'inkorg',
    ];
}

/**
 * Leadens e-post och telefon ur ärendet. Tom sträng när fältet saknas.
 *
 * @param array<string, mixed> $row
 * @return array{name:string,email:string,phone:string,address:string}
 */
function jeab_inkorg_contact_create_party(array $row): array
{
    $lead = is_array($row['lead_customer'] ?? null) ? $row['lead_customer'] : [];
    $email = trim((string) ($lead['email'] ?? ''));
    if ($email === '') {
        $email = trim((string) ($row['customer_email'] ?? ''));
    }
    $phone = trim((string) ($lead['phone'] ?? ''));
    $name = trim((string) ($lead['name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['customer_name'] ?? ''));
    }

    return [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => trim((string) ($lead['address'] ?? '')),
    ];
}

/** @param array<string, mixed> $row @param array{name:string,email:string,phone:string,address:string} $party */
function jeab_inkorg_contact_create_is_lead(array $row, array $party): bool
{
    if (!empty($row['deleted']) || trim((string) ($row['deleted_at'] ?? '')) !== '') {
        return false;
    }
    if ($party['email'] === '' && $party['phone'] === '') {
        return false;
    }
    $first = is_array($row['first'] ?? null) ? $row['first'] : [];
    $labels = is_array($first['labels'] ?? null) ? $first['labels'] : [];
    if ($labels === [] && is_array($row['labels'] ?? null)) {
        $labels = $row['labels'];
    }
    $type = (string) ($labels['type'] ?? $row['list_type'] ?? '');
    if ($type === 'Ny lead') {
        return true;
    }

    return is_array($row['lead_customer'] ?? null);
}

/** @param array<string, mixed> $row */
function jeab_inkorg_contact_create_row_error(array $row): string
{
    foreach (['contact_error', 'contact_create_error', 'create_error'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Ärenden i inkorg_list och inkorg_threads som är lead med e-post eller telefon
 * och saknar contact_id. contact_error är ett registrerat fel. Övriga är candidates.
 * Svaret har id och hint. Hint används bara internt.
 *
 * @return array{failures:array<string, array<string, string>>,candidates:array<string, array<string, string>>}
 */
function jeab_inkorg_contact_create_gaps(PDO $pdo): array
{
    $failures = [];
    $candidates = [];
    $seen = [];
    $tables = [
        'inkorg_list' => true,
        'inkorg_threads' => false,
    ];
    foreach ($tables as $table => $hasEmail) {
        $cols = jeab_inkorg_contact_create_columns($pdo, $table);
        if (!in_array('id', $cols, true) || !in_array('row_json', $cols, true)) {
            continue;
        }
        $select = ['id', 'row_json'];
        if (in_array('contact_id', $cols, true)) {
            $select[] = 'contact_id';
        }
        if (in_array('company', $cols, true)) {
            $select[] = 'company';
        }
        if ($hasEmail && in_array('customer_email', $cols, true)) {
            $select[] = 'customer_email';
        }
        $where = ["row_json LIKE '%Ny lead%' OR row_json LIKE '%\"lead_customer\"%'"];
        if ($hasEmail && in_array('customer_email', $cols, true)) {
            $where[] = "customer_email <> ''";
        }
        try {
            $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM ' . $table . ' WHERE ' . implode(' OR ', $where));
            $fetched = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $fetched = [];
        }
        if (!is_array($fetched)) {
            continue;
        }
        foreach ($fetched as $db) {
            if (!is_array($db)) {
                continue;
            }
            $id = trim((string) ($db['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $columnId = trim((string) ($db['contact_id'] ?? ''));
            if ($columnId !== '') {
                $seen[$id] = true;
                continue;
            }
            $row = json_decode((string) ($db['row_json'] ?? ''), true);
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['contact_id'] ?? '')) !== '') {
                $seen[$id] = true;
                continue;
            }
            if ($hasEmail && trim((string) ($row['customer_email'] ?? '')) === '' && trim((string) ($db['customer_email'] ?? '')) !== '') {
                $row['customer_email'] = (string) $db['customer_email'];
            }
            $party = jeab_inkorg_contact_create_party($row);
            if (!jeab_inkorg_contact_create_is_lead($row, $party)) {
                continue;
            }
            $email = $party['email'];
            $blocked = $email !== '' && function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email);
            $source = $email !== '' && function_exists('jeab_lead_source_blocked') && jeab_lead_source_blocked($email);
            if (($blocked || $source) && $party['phone'] === '') {
                continue;
            }
            $seen[$id] = true;
            $company = trim((string) ($db['company'] ?? ''));
            if ($company === '') {
                $company = trim((string) ($row['company'] ?? ''));
            }
            $hint = [
                'name' => $party['name'],
                'email' => $blocked || $source ? '' : $email,
                'phone' => $party['phone'],
                'address' => $party['address'],
                'source' => 'inkorg',
                'company' => $company,
            ];
            if (jeab_inkorg_contact_create_row_error($row) !== '') {
                $failures[$id] = $hint;
            } else {
                $candidates[$id] = $hint;
            }
        }
    }

    return ['failures' => $failures, 'candidates' => $candidates];
}

function jeab_inkorg_contact_create_bind(PDO $pdo, string $id, string $cid): void
{
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        $cols = jeab_inkorg_contact_create_columns($pdo, $table);
        if (!in_array('id', $cols, true) || !in_array('row_json', $cols, true)) {
            continue;
        }
        $hasContact = in_array('contact_id', $cols, true);
        try {
            $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
            $stmt->execute([$id]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable) {
            continue;
        }
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== '') {
            continue;
        }
        $row['contact_id'] = $cid;
        $enc = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($enc)) {
            continue;
        }
        try {
            if ($hasContact) {
                $pdo->prepare('UPDATE ' . $table . ' SET row_json = ?, contact_id = ? WHERE id = ?')->execute([$enc, $cid, $id]);
            } else {
                $pdo->prepare('UPDATE ' . $table . ' SET row_json = ? WHERE id = ?')->execute([$enc, $id]);
            }
        } catch (Throwable) {
        }
    }
}

function jeab_inkorg_contact_create_email_key(string $raw): string
{
    if (function_exists('jeab_kontakt_email')) {
        return jeab_kontakt_email($raw);
    }
    $norm = function_exists('jeab_norm_email') ? jeab_norm_email($raw) : strtolower(trim($raw));

    return str_contains($norm, '@') ? $norm : '';
}

function jeab_inkorg_contact_create_phone_key(string $raw): string
{
    return function_exists('jeab_norm_phone') ? jeab_norm_phone($raw) : '';
}

/** Adressnyckel bara när både gata med nummer och postnummer finns. */
function jeab_inkorg_contact_create_address_key(string $street, string $postal): string
{
    $street = trim($street);
    $postalNorm = function_exists('jeab_norm_postal') ? jeab_norm_postal($postal) : '';
    if ($postalNorm === '' && $street !== '' && function_exists('jeab_kontakt_postal_scan')) {
        $scan = jeab_kontakt_postal_scan($street);
        if (($scan['postal'] ?? '') !== '' && (int) ($scan['offset'] ?? -1) >= 0) {
            $postalNorm = (string) $scan['postal'];
            $street = trim(substr($street, 0, (int) $scan['offset']));
        }
    }
    $street = trim($street, " \t,;");
    if ($postalNorm === '' || $street === '' || !function_exists('jeab_norm_street_key')) {
        return '';
    }
    $key = jeab_norm_street_key($street, $postalNorm);
    if ($key !== '' && str_contains($key, '|' . $postalNorm)) {
        return $key;
    }
    if (preg_match('/^(.+?\s+\d+\s*[A-Za-zÅÄÖåäö]?)(?:\s+(?:lgh|lägenhet)\s*(\d+[A-Za-zÅÄÖåäö]?))?/u', $street, $m) === 1) {
        $clip = trim((string) $m[1]);
        if (!empty($m[2])) {
            $clip .= ' lgh ' . $m[2];
        }
        $key = jeab_norm_street_key($clip, $postalNorm);
        if ($key !== '' && str_contains($key, '|' . $postalNorm)) {
            return $key;
        }
    }

    return '';
}

/**
 * @param array<string, string> $hint
 * @return array{email:string,phone:string,address:string}
 */
function jeab_inkorg_contact_create_candidate_keys(array $hint): array
{
    $street = trim((string) ($hint['street'] ?? ''));
    $postal = trim((string) ($hint['postal_code'] ?? $hint['postal'] ?? $hint['postnummer'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($hint['address'] ?? ''));
    }

    return [
        'email' => jeab_inkorg_contact_create_email_key((string) ($hint['email'] ?? '')),
        'phone' => jeab_inkorg_contact_create_phone_key((string) ($hint['phone'] ?? '')),
        'address' => jeab_inkorg_contact_create_address_key($street, $postal),
    ];
}

/** @return list<string> */
function jeab_inkorg_contact_create_secondary_email_columns(): array
{
    return ['secondary_email', 'email_secondary', 'owner_email_2', 'alt_email', 'email2', 'email3'];
}

/** @return list<string> */
function jeab_inkorg_contact_create_email_source_names(): array
{
    return array_merge(
        ['owner_email'],
        jeab_inkorg_contact_create_secondary_email_columns(),
        ['owners.email', 'email', 'emails', 'row_emails', 'contact_emails']
    );
}

/**
 * E-postnyckel till källfält. Samma nycklar som email_keys, med fältnamn.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $db
 * @return array<string, array<string, true>>
 */
function jeab_inkorg_contact_create_email_sources(array $row, array $db = []): array
{
    $found = [];
    $add = static function (string $raw, string $source) use (&$found): void {
        if (!in_array($source, jeab_inkorg_contact_create_email_source_names(), true)) {
            return;
        }
        $key = jeab_inkorg_contact_create_email_key($raw);
        if ($key !== '') {
            $found[$key][$source] = true;
        }
    };
    if (array_key_exists('owner_email', $db)) {
        $add((string) $db['owner_email'], 'owner_email');
    }
    foreach (jeab_inkorg_contact_create_secondary_email_columns() as $col) {
        if (array_key_exists($col, $db)) {
            $add((string) $db[$col], $col);
        }
    }
    foreach ((array) ($row['emails'] ?? []) as $one) {
        $add((string) $one, 'emails');
    }
    if (isset($row['email'])) {
        $add((string) $row['email'], 'email');
    }
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $add((string) ($owner['email'] ?? ''), 'owners.email');
        }
    }
    if (function_exists('jeab_kontakt_row_emails')) {
        foreach (jeab_kontakt_row_emails($row) as $one) {
            $add((string) $one, 'row_emails');
        }
    }

    return $found;
}

/**
 * Samma e-postnycklar för dup_existing_email och contacts.link_by_email.
 * Primär kolumn, sekundär kolumn när den finns (email2, email3 och äldre namn),
 * ägarfält, emails i JSON och sekundäradresserna email2/email3.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $db
 * @return array<string, true>
 */
function jeab_inkorg_contact_create_email_keys(array $row, array $db = []): array
{
    $found = [];
    $add = static function (string $raw) use (&$found): void {
        $key = jeab_inkorg_contact_create_email_key($raw);
        if ($key !== '') {
            $found[$key] = true;
        }
    };
    $add((string) ($db['owner_email'] ?? ''));
    foreach (jeab_inkorg_contact_create_secondary_email_columns() as $col) {
        if (array_key_exists($col, $db)) {
            $add((string) $db[$col]);
        }
    }
    $list = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($row) : [];
    foreach ((array) ($row['emails'] ?? []) as $one) {
        $list[] = (string) $one;
    }
    if (isset($row['email'])) {
        $list[] = (string) $row['email'];
    }
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $add((string) ($owner['email'] ?? ''));
    }
    foreach ($list as $one) {
        $add((string) $one);
    }
    if (function_exists('jeab_kontakt_secondary_list')) {
        foreach (jeab_kontakt_secondary_list($row) as $one) {
            $add((string) $one);
        }
    } else {
        $add((string) ($row['email2'] ?? ''));
        $add((string) ($row['email3'] ?? ''));
    }

    return $found;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $db
 */
function jeab_inkorg_contact_create_contact_company(array $row, array $db = []): string
{
    $raws = [(string) ($db['company'] ?? '')];
    foreach (['company', 'brand'] as $key) {
        $raws[] = (string) ($row[$key] ?? '');
    }
    foreach ($raws as $raw) {
        if (function_exists('jeab_normalize_company')) {
            $company = (string) (jeab_normalize_company($raw) ?? '');
        } else {
            $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($raw), 'UTF-8') : strtolower(trim($raw));
            $fold = strtr($fold, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
            $company = in_array($fold, ['jarfalla', 'jeab'], true) ? 'jarfalla' : (in_array($fold, ['malmo', 'meab'], true) ? 'malmo' : '');
        }
        if ($company !== '') {
            return $company;
        }
    }

    return '';
}

/**
 * Kontakter med e-post, per id. JSON-registret, SQL-raden och contact_emails.
 * Bolag följer med så en länk kan kräva samma bolag. Skriver inget.
 *
 * @return array<string, array{company:string,emails:array<string, true>,sources:array<string, array<string, true>>}>
 */
function jeab_inkorg_contact_create_email_directory(PDO $pdo): array
{
    $dir = [];
    $put = static function (string $id, string $company, array $emails, bool $overwriteCompany, array $sources = []) use (&$dir): void {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        if (!isset($dir[$id])) {
            $dir[$id] = ['company' => '', 'emails' => [], 'sources' => []];
        }
        if ($company !== '' && ($overwriteCompany || $dir[$id]['company'] === '')) {
            $dir[$id]['company'] = $company;
        }
        foreach ($emails as $email => $yes) {
            if ($yes && is_string($email) && $email !== '') {
                $dir[$id]['emails'][$email] = true;
            }
        }
        foreach ($sources as $email => $fields) {
            if (!is_string($email) || $email === '' || !is_array($fields)) {
                continue;
            }
            $dir[$id]['emails'][$email] = true;
            foreach ($fields as $field => $yes) {
                if ($yes && is_string($field) && $field !== '') {
                    $dir[$id]['sources'][$email][$field] = true;
                }
            }
        }
    };
    if (function_exists('jeab_kontakter_load')) {
        unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
        foreach (jeab_kontakter_load() as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? $key));
            $put($id, jeab_inkorg_contact_create_contact_company($row), jeab_inkorg_contact_create_email_keys($row), false, jeab_inkorg_contact_create_email_sources($row));
        }
    }
    $cols = jeab_inkorg_contact_create_columns($pdo, 'contacts');
    if (in_array('id', $cols, true)) {
        $select = ['id'];
        foreach (array_merge(['owner_email', 'company', 'row_json'], jeab_inkorg_contact_create_secondary_email_columns()) as $col) {
            if (in_array($col, $cols, true) && !in_array($col, $select, true)) {
                $select[] = $col;
            }
        }
        try {
            $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM contacts');
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $rows = [];
        }
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as $db) {
            if (!is_array($db)) {
                continue;
            }
            $json = [];
            if (isset($db['row_json']) && is_string($db['row_json']) && $db['row_json'] !== '') {
                $decoded = json_decode($db['row_json'], true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }
            $put(
                (string) ($db['id'] ?? ''),
                jeab_inkorg_contact_create_contact_company($json, $db),
                jeab_inkorg_contact_create_email_keys($json, $db),
                true,
                jeab_inkorg_contact_create_email_sources($json, $db)
            );
        }
    }
    $mailCols = jeab_inkorg_contact_create_columns($pdo, 'contact_emails');
    if (in_array('email', $mailCols, true) && in_array('contact_id', $mailCols, true)) {
        try {
            $stmt = $pdo->query('SELECT email, contact_id FROM contact_emails');
            $found = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $found = [];
        }
        if (!is_array($found)) {
            $found = [];
        }
        foreach ($found as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = jeab_inkorg_contact_create_email_key((string) ($item['email'] ?? ''));
            if ($key === '') {
                continue;
            }
            $put((string) ($item['contact_id'] ?? ''), '', [$key => true], false, [$key => ['contact_emails' => true]]);
        }
    }

    return $dir;
}

/**
 * @param array<string, true> $emails
 * @param array<string, true> $phones
 * @param array<string, true> $addrs
 * @param array<string, mixed> $row
 * @param array<string, mixed> $db
 */
function jeab_inkorg_contact_create_index_contact(array &$emails, array &$phones, array &$addrs, array $row, array $db = []): void
{
    foreach (jeab_inkorg_contact_create_email_keys($row, $db) as $email => $yes) {
        if ($yes) {
            $emails[$email] = true;
        }
    }
    $phone = jeab_inkorg_contact_create_phone_key((string) ($db['owner_phone'] ?? ''));
    if ($phone !== '') {
        $phones[$phone] = true;
    }
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $ownerPhone = jeab_inkorg_contact_create_phone_key((string) ($owner['phone'] ?? $owner['mobile'] ?? ''));
        if ($ownerPhone !== '') {
            $phones[$ownerPhone] = true;
        }
    }
    $rowPhone = jeab_inkorg_contact_create_phone_key((string) ($row['phone'] ?? $row['mobile'] ?? ''));
    if ($rowPhone !== '') {
        $phones[$rowPhone] = true;
    }
    $street = trim((string) ($row['street'] ?? ''));
    $postal = trim((string) ($row['postal_code'] ?? $row['postal'] ?? $db['postal_code'] ?? ''));
    $addr = jeab_inkorg_contact_create_address_key($street !== '' ? $street : (string) ($row['address'] ?? ''), $postal);
    if ($addr !== '') {
        $addrs[$addr] = true;
    }
    $storedPostal = function_exists('jeab_norm_postal') ? jeab_norm_postal((string) ($db['postal_code'] ?? '')) : '';
    $storedKey = trim((string) ($db['street_key'] ?? $row['street_key'] ?? ''));
    if ($storedKey !== '' && $storedPostal !== '' && str_contains($storedKey, '|' . $storedPostal)) {
        $addrs[$storedKey] = true;
    }
}

/**
 * Läser registret. Skriver inget. Sekundär e-post tas med när tabellen finns.
 *
 * @return array{emails:array<string, true>,phones:array<string, true>,addrs:array<string, true>}
 */
function jeab_inkorg_contact_create_existing(PDO $pdo): array
{
    $emails = [];
    $phones = [];
    $addrs = [];
    foreach (jeab_inkorg_contact_create_email_directory($pdo) as $info) {
        foreach ($info['emails'] as $email => $yes) {
            if ($yes) {
                $emails[$email] = true;
            }
        }
    }
    if (function_exists('jeab_kontakter_load')) {
        unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
        foreach (jeab_kontakter_load() as $row) {
            if (is_array($row)) {
                jeab_inkorg_contact_create_index_contact($emails, $phones, $addrs, $row);
            }
        }
    }
    $cols = jeab_inkorg_contact_create_columns($pdo, 'contacts');
    if (in_array('id', $cols, true)) {
        $select = ['id'];
        foreach (['owner_email', 'owner_phone', 'street_key', 'postal_code', 'row_json'] as $col) {
            if (in_array($col, $cols, true)) {
                $select[] = $col;
            }
        }
        try {
            $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM contacts');
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $rows = [];
        }
        if (!is_array($rows)) {
            $rows = [];
        }
        foreach ($rows as $db) {
            if (!is_array($db)) {
                continue;
            }
            $row = [];
            if (isset($db['row_json']) && is_string($db['row_json']) && $db['row_json'] !== '') {
                $decoded = json_decode($db['row_json'], true);
                if (is_array($decoded)) {
                    $row = $decoded;
                }
            }
            jeab_inkorg_contact_create_index_contact($emails, $phones, $addrs, $row, $db);
        }
    }
    $mailCols = jeab_inkorg_contact_create_columns($pdo, 'contact_emails');
    if (in_array('email', $mailCols, true) && in_array('contact_id', $mailCols, true)) {
        try {
            $stmt = $pdo->query('SELECT email FROM contact_emails');
            $found = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } catch (Throwable) {
            $found = [];
        }
        if (!is_array($found)) {
            $found = [];
        }
        foreach ($found as $email) {
            $key = jeab_inkorg_contact_create_email_key((string) $email);
            if ($key !== '') {
                $emails[$key] = true;
            }
        }
    }

    return ['emails' => $emails, 'phones' => $phones, 'addrs' => $addrs];
}

/**
 * Osäkra kandidater skapas inte av en skarp körning utan only_ids.
 * Svaret har id och orsak, aldrig e-post, telefon eller adress.
 *
 * @param array<string, array<string, string>> $plan
 * @param array<string, string> $companyOf
 * @return array{clean_ids:list<string>,uncertain:list<array{id:string,reason:string}>,clean_count:int,uncertain_count:int,companies:array<string, array{clean_count:int,uncertain_count:int}>}
 */
function jeab_inkorg_contact_create_classify(PDO $pdo, array $plan, array $companyOf): array
{
    $existing = jeab_inkorg_contact_create_existing($pdo);
    $reasons = [];
    $keys = [];
    foreach ($plan as $id => $hint) {
        $key = jeab_inkorg_contact_create_candidate_keys($hint);
        $keys[$id] = $key;
        if ($key['email'] !== '' && isset($existing['emails'][$key['email']])) {
            $reasons[$id] = 'dup_existing_email';
        } elseif ($key['phone'] !== '' && isset($existing['phones'][$key['phone']])) {
            $reasons[$id] = 'dup_existing_phone';
        } elseif ($key['address'] !== '' && isset($existing['addrs'][$key['address']])) {
            $reasons[$id] = 'dup_existing_address';
        }
    }
    $buckets = ['email' => [], 'phone' => [], 'address' => []];
    foreach ($keys as $id => $key) {
        foreach (['email', 'phone', 'address'] as $field) {
            if ($key[$field] === '') {
                continue;
            }
            $buckets[$field][$key[$field]][] = $id;
        }
    }
    foreach ($buckets as $bucket) {
        foreach ($bucket as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            foreach ($ids as $id) {
                if (!isset($reasons[$id])) {
                    $reasons[$id] = 'dup_candidate';
                }
            }
        }
    }
    $clean = [];
    $uncertain = [];
    $companies = [];
    foreach ($plan as $id => $_hint) {
        $co = (string) ($companyOf[$id] ?? '');
        if (!isset($companies[$co])) {
            $companies[$co] = ['clean_count' => 0, 'uncertain_count' => 0];
        }
        if (isset($reasons[$id])) {
            $companies[$co]['uncertain_count']++;
            $uncertain[] = ['id' => (string) $id, 'reason' => $reasons[$id]];
        } else {
            $companies[$co]['clean_count']++;
            $clean[] = (string) $id;
        }
    }
    sort($clean, SORT_STRING);
    usort($uncertain, static function (array $a, array $b): int {
        return strcmp($a['id'], $b['id']);
    });
    ksort($companies, SORT_STRING);

    return [
        'clean_ids' => $clean,
        'uncertain' => $uncertain,
        'clean_count' => count($clean),
        'uncertain_count' => count($uncertain),
        'companies' => $companies,
    ];
}

/**
 * null betyder att only_ids saknas. Tom lista är ett medvetet urval.
 *
 * @param array<string, mixed> $input
 * @return list<string>|null
 */
function jeab_inkorg_contact_create_only_ids(array $input): ?array
{
    if (!array_key_exists('only_ids', $input)) {
        return null;
    }
    $raw = $input['only_ids'];
    if (is_string($raw)) {
        $raw = preg_split('/[\s,]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        if (!is_string($id) && !is_int($id)) {
            continue;
        }
        $id = strtolower(trim((string) $id));
        if (jeab_inkorg_contact_create_ref_ok($id)) {
            $ids[$id] = true;
        }
    }
    $out = array_keys($ids);
    sort($out, SORT_STRING);

    return $out;
}

function jeab_inkorg_contact_create_undo_dir(): string
{
    $root = function_exists('jeab_data_dir') ? jeab_data_dir() : '';
    if ($root === '') {
        return '';
    }

    return rtrim(str_replace('\\', '/', $root), '/') . '/.private/inkorg-contact-create';
}

function jeab_inkorg_contact_create_undo_path(string $undoId): string
{
    if (preg_match('/\Auc_[a-f0-9]{16}\z/', $undoId) !== 1) {
        return '';
    }
    $dir = jeab_inkorg_contact_create_undo_dir();
    if ($dir === '') {
        return '';
    }

    return $dir . '/' . $undoId . '.json';
}

/**
 * @param array<string, array{id:string,hash:string,updated_at:string}> $contacts
 * @param list<array{table:string,id:string,contact_id:string}> $links
 */
function jeab_inkorg_contact_create_undo_save(array $contacts, array $links): string
{
    $dir = jeab_inkorg_contact_create_undo_dir();
    if ($dir === '') {
        return '';
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return '';
    }
    $undoId = 'uc_' . bin2hex(random_bytes(8));
    $path = jeab_inkorg_contact_create_undo_path($undoId);
    if ($path === '') {
        return '';
    }
    $payload = [
        'undo_id' => $undoId,
        'contacts' => array_values($contacts),
        'links' => array_values($links),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json) || file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        return '';
    }

    return $undoId;
}

/** @param array<string, mixed> $row */
function jeab_inkorg_contact_create_row_hash(array $row): string
{
    $sort = static function (array &$value) use (&$sort): void {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $sort($child);
            }
        }
        unset($child);
        ksort($value);
    };
    $sort($row);
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return hash('sha256', is_string($json) ? $json : '');
}

/** @return array{id:string,hash:string,updated_at:string}|null */
function jeab_inkorg_contact_create_contact_snapshot(PDO $pdo, string $cid): ?array
{
    if (preg_match('/\Akt_[a-f0-9]{8,32}\z/', $cid) !== 1) {
        return null;
    }
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
    $row = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($cid) : null;
    if (!is_array($row)) {
        $cols = jeab_inkorg_contact_create_columns($pdo, 'contacts');
        if (in_array('row_json', $cols, true)) {
            try {
                $stmt = $pdo->prepare('SELECT row_json FROM contacts WHERE id = ?');
                $stmt->execute([$cid]);
                $raw = $stmt->fetchColumn();
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $row = is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                $row = null;
            }
        }
    }
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => $cid,
        'hash' => jeab_inkorg_contact_create_row_hash($row),
        'updated_at' => trim((string) ($row['updated_at'] ?? '')),
    ];
}

/**
 * @return list<array{table:string,id:string,contact_id:string}>
 */
function jeab_inkorg_contact_create_link_places(PDO $pdo, string $id, string $cid): array
{
    $out = [];
    foreach (['inkorg_contacts', 'inkorg_list', 'inkorg_threads'] as $table) {
        $cols = jeab_inkorg_contact_create_columns($pdo, $table);
        if (!in_array('id', $cols, true) || !in_array('row_json', $cols, true)) {
            continue;
        }
        try {
            $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
            $stmt->execute([$id]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable) {
            continue;
        }
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== $cid) {
            continue;
        }
        $out[] = ['table' => $table, 'id' => $id, 'contact_id' => $cid];
    }

    return $out;
}

function jeab_inkorg_contact_create_unlink(PDO $pdo, string $table, string $id, string $cid): bool
{
    if (!in_array($table, ['inkorg_contacts', 'inkorg_list', 'inkorg_threads'], true)) {
        return false;
    }
    if (!jeab_inkorg_contact_create_ref_ok($id) || preg_match('/\Akt_[a-f0-9]{8,32}\z/', $cid) !== 1) {
        return false;
    }
    $cols = jeab_inkorg_contact_create_columns($pdo, $table);
    if (!in_array('id', $cols, true) || !in_array('row_json', $cols, true)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
    $row = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== $cid) {
        return false;
    }
    $row['contact_id'] = '';
    $enc = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($enc)) {
        return false;
    }
    try {
        if (in_array('contact_id', $cols, true)) {
            $pdo->prepare('UPDATE ' . $table . ' SET row_json = ?, contact_id = ? WHERE id = ?')->execute([$enc, '', $id]);
        } else {
            $pdo->prepare('UPDATE ' . $table . ' SET row_json = ? WHERE id = ?')->execute([$enc, $id]);
        }
    } catch (Throwable) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_create_run(PDO $pdo, array $input): array
{
    if (array_key_exists('rollback', $input) || array_key_exists('hold', $input)) {
        $raw = array_key_exists('hold', $input) ? $input['hold'] : $input['rollback'];
        $on = !($raw === false || $raw === 0 || $raw === '0' || $raw === 'false');
        $dry = jeab_inkorg_contact_create_dry($input, false);
        if (!$dry && function_exists('jeab_json_sql_rollback_set')) {
            jeab_json_sql_rollback_set('inkorg_contact_create_hold', $on);
        }

        return [
            'ok' => true,
            'dry' => $dry || $on,
            'action' => 'data.inkorg_contact_create',
            'rollback' => $on,
            'held' => $on ? 1 : 0,
            'would_rollback' => 1,
            'written' => !$dry,
            'created' => 0,
            'would_create' => 0,
            'found' => 0,
            'ids' => [],
            'file_unchanged' => true,
        ];
    }
    $held = function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('inkorg_contact_create_hold');
    $dry = jeab_inkorg_contact_create_dry($input);
    $debug = [];
    $found = jeab_inkorg_contact_create_found($input, $debug, $pdo);
    $gaps = jeab_inkorg_contact_create_gaps($pdo);
    $debug['thread_contact_error'] = count($gaps['failures']);
    $debug['candidates'] = count($gaps['candidates']);
    $gapIds = $gaps['failures'] + $gaps['candidates'];
    $out = [
        'ok' => true,
        'dry' => $dry,
        'action' => 'data.inkorg_contact_create',
        'held' => $held ? 1 : 0,
        'found' => count($found),
        'ids' => [],
        'candidates' => count($gaps['candidates']),
        'candidate_ids' => [],
        'failure_ids' => [],
        'would_create' => 0,
        'created' => 0,
        'linked' => 0,
        'skipped_linked' => 0,
        'skipped_missing' => 0,
        'skipped_empty' => 0,
        'create_failed' => 0,
        'written' => false,
        'file_unchanged' => true,
        'clean_ids' => [],
        'uncertain' => [],
        'clean_count' => 0,
        'uncertain_count' => 0,
        'companies' => [],
        'undo_id' => '',
        'sent' => 0,
    ];
    $plan = [];
    $bind = [];
    $companyOf = [];
    $sel = $pdo->prepare('SELECT row_json FROM inkorg_contacts WHERE id = ?');
    foreach ($found as $id) {
        if (isset($gapIds[$id])) {
            continue;
        }
        $sel->execute([$id]);
        $raw = $sel->fetchColumn();
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($row)) {
            $out['skipped_missing']++;
            continue;
        }
        if (trim((string) ($row['contact_id'] ?? '')) !== '') {
            $out['skipped_linked']++;
            continue;
        }
        $hint = jeab_inkorg_contact_create_hint($row);
        if ($hint['name'] === '' && $hint['email'] === '' && $hint['phone'] === '') {
            $out['skipped_empty']++;
            continue;
        }
        $companyOf[$id] = trim((string) ($row['company'] ?? ''));
        $plan[$id] = $hint;
    }
    foreach ($gapIds as $id => $hint) {
        $companyOf[$id] = trim((string) ($hint['company'] ?? ''));
        unset($hint['company']);
        if ($hint['name'] === '' && $hint['email'] === '' && $hint['phone'] === '') {
            $out['skipped_empty']++;
            continue;
        }
        $plan[$id] = $hint;
        $bind[$id] = true;
    }
    $ids = array_keys($plan);
    sort($ids, SORT_STRING);
    $candidateIds = array_keys($gaps['candidates']);
    sort($candidateIds, SORT_STRING);
    $failureIds = array_keys($gaps['failures']);
    sort($failureIds, SORT_STRING);
    $classified = jeab_inkorg_contact_create_classify($pdo, $plan, $companyOf);
    $only = jeab_inkorg_contact_create_only_ids($input);
    $write = [];
    if ($only !== null) {
        $allow = array_fill_keys($only, true);
        foreach ($ids as $id) {
            if (isset($allow[$id])) {
                $write[] = $id;
            }
        }
        $out['only_ids'] = $only;
    } else {
        $write = $classified['clean_ids'];
    }
    $out['ids'] = $ids;
    $out['candidate_ids'] = $candidateIds;
    $out['failure_ids'] = $failureIds;
    $out['clean_ids'] = $classified['clean_ids'];
    $out['uncertain'] = $classified['uncertain'];
    $out['clean_count'] = $classified['clean_count'];
    $out['uncertain_count'] = $classified['uncertain_count'];
    $out['companies'] = $classified['companies'];
    $out['would_create'] = count($write);
    if ($dry) {
        $out['debug'] = $debug;

        return $out;
    }
    $hasColumn = function_exists('jeab_inkorg_contact_link_has_column') && jeab_inkorg_contact_link_has_column($pdo);
    $upd = $hasColumn
        ? $pdo->prepare('UPDATE inkorg_contacts SET row_json = ?, contact_id = ? WHERE id = ?')
        : $pdo->prepare('UPDATE inkorg_contacts SET row_json = ? WHERE id = ?');
    $undoContacts = [];
    $undoLinks = [];
    foreach ($write as $inboxId) {
        $hint = $plan[$inboxId];
        try {
            $made = jeab_kontakt_create_unmerged($hint);
        } catch (Throwable) {
            $made = null;
        }
        $cid = is_array($made) ? trim((string) ($made['id'] ?? '')) : '';
        if ($cid === '') {
            $out['create_failed']++;
            continue;
        }
        $sel->execute([$inboxId]);
        $raw = $sel->fetchColumn();
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($row) && trim((string) ($row['contact_id'] ?? '')) !== '') {
            $out['skipped_linked']++;
            continue;
        }
        if (is_array($row)) {
            $row['contact_id'] = $cid;
            $enc = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($enc)) {
                $out['create_failed']++;
                continue;
            }
            if ($hasColumn) {
                $upd->execute([$enc, $cid, $inboxId]);
            } else {
                $upd->execute([$enc, $inboxId]);
            }
        } elseif (empty($bind[$inboxId])) {
            $out['skipped_linked']++;
            continue;
        }
        if (!empty($bind[$inboxId])) {
            jeab_inkorg_contact_create_bind($pdo, (string) $inboxId, $cid);
        }
        $snap = jeab_inkorg_contact_create_contact_snapshot($pdo, $cid);
        if ($snap !== null) {
            $undoContacts[$cid] = $snap;
        }
        foreach (jeab_inkorg_contact_create_link_places($pdo, (string) $inboxId, $cid) as $place) {
            $undoLinks[] = $place;
        }
        $out['created']++;
    }
    if ($undoContacts !== []) {
        $out['undo_id'] = jeab_inkorg_contact_create_undo_save($undoContacts, $undoLinks);
    }
    $out['written'] = $out['created'] > 0;

    return $out;
}

/**
 * Tar bort kontakter från en skarp körning och kopplar loss ärendena.
 * Bara orörda kontakter som den körningen skapade. dry saknas betyder dry=1.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_create_undo_run(PDO $pdo, array $input): array
{
    $dry = jeab_inkorg_contact_create_dry($input, false);
    $undoId = strtolower(trim((string) ($input['undo_id'] ?? '')));
    $out = [
        'ok' => false,
        'dry' => $dry,
        'action' => 'data.inkorg_contact_create_undo',
        'undo_id' => $undoId,
        'would_delete' => 0,
        'would_unlink' => 0,
        'deleted' => 0,
        'unlinked' => 0,
        'skipped_touched' => 0,
        'skipped_missing' => 0,
        'skipped_foreign' => 0,
        'contact_ids' => [],
        'inbox_ids' => [],
        'sent' => 0,
        'written' => false,
    ];
    $path = jeab_inkorg_contact_create_undo_path($undoId);
    if ($path === '' || !is_file($path)) {
        $out['error'] = 'Ångerfilen saknas.';

        return $out;
    }
    $raw = file_get_contents($path);
    $file = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($file) || (string) ($file['undo_id'] ?? '') !== $undoId) {
        $out['error'] = 'Ångerfilen saknas.';

        return $out;
    }
    $contacts = is_array($file['contacts'] ?? null) ? $file['contacts'] : [];
    $links = is_array($file['links'] ?? null) ? $file['links'] : [];
    $drop = [];
    $contactIds = [];
    foreach ($contacts as $item) {
        if (!is_array($item)) {
            continue;
        }
        $cid = trim((string) ($item['id'] ?? ''));
        if (preg_match('/\Akt_[a-f0-9]{8,32}\z/', $cid) !== 1) {
            continue;
        }
        unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
        $stored = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($cid) : null;
        if (!is_array($stored)) {
            $out['skipped_missing']++;
            continue;
        }
        if ((string) ($stored['source'] ?? '') !== 'inkorg') {
            $out['skipped_foreign']++;
            continue;
        }
        $snap = [
            'hash' => jeab_inkorg_contact_create_row_hash($stored),
            'updated_at' => trim((string) ($stored['updated_at'] ?? '')),
        ];
        $wantHash = (string) ($item['hash'] ?? '');
        $sameHash = $wantHash !== '' && strlen($wantHash) === strlen($snap['hash']) && hash_equals($wantHash, $snap['hash']);
        $sameAt = trim((string) ($item['updated_at'] ?? '')) === $snap['updated_at'];
        if (!$sameHash || !$sameAt) {
            $out['skipped_touched']++;
            continue;
        }
        $drop[$cid] = true;
        $contactIds[] = $cid;
    }
    sort($contactIds, SORT_STRING);
    $inboxIds = [];
    $todo = [];
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $cid = trim((string) ($link['contact_id'] ?? ''));
        $id = strtolower(trim((string) ($link['id'] ?? '')));
        $table = (string) ($link['table'] ?? '');
        if (!isset($drop[$cid]) || !jeab_inkorg_contact_create_ref_ok($id)) {
            continue;
        }
        $todo[] = ['table' => $table, 'id' => $id, 'contact_id' => $cid];
        $inboxIds[$id] = true;
    }
    $inboxList = array_keys($inboxIds);
    sort($inboxList, SORT_STRING);
    $out['ok'] = true;
    $out['would_delete'] = count($drop);
    $out['would_unlink'] = count($todo);
    $out['contact_ids'] = $contactIds;
    $out['inbox_ids'] = $inboxList;
    if ($dry) {
        return $out;
    }
    foreach (array_keys($drop) as $cid) {
        $gone = false;
        try {
            if (function_exists('jeab_kontakter_mutate')) {
                jeab_kontakter_mutate(static function (array &$contacts) use ($cid): void {
                    unset($contacts[$cid]);
                }, true);
            } else {
                $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$cid]);
            }
            $gone = jeab_inkorg_contact_create_contact_snapshot($pdo, $cid) === null;
        } catch (Throwable) {
            $gone = false;
        }
        if (!$gone) {
            unset($drop[$cid]);
            continue;
        }
        $out['deleted']++;
    }
    foreach ($todo as $link) {
        if (!isset($drop[$link['contact_id']])) {
            continue;
        }
        if (jeab_inkorg_contact_create_unlink($pdo, $link['table'], $link['id'], $link['contact_id'])) {
            $out['unlinked']++;
        }
    }
    $out['written'] = $out['deleted'] > 0 || $out['unlinked'] > 0;

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_create_undo_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO && function_exists('jeab_contact_store_pdo')) {
        $pdo = jeab_contact_store_pdo();
    }
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400];
    }
    try {
        $result = jeab_inkorg_contact_create_undo_run($pdo, $input);
    } catch (Throwable) {
        $result = ['ok' => false, 'error' => 'Körningen misslyckades.', 'written' => false, 'sent' => 0];
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_create_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO && function_exists('jeab_contact_store_pdo')) {
        $pdo = jeab_contact_store_pdo();
    }
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400];
    }
    try {
        $result = jeab_inkorg_contact_create_run($pdo, $input);
    } catch (Throwable) {
        $result = ['ok' => false, 'error' => 'Körningen misslyckades.', 'written' => false];
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}
