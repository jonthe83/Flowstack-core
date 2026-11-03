<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Hook reminders.restore_from_backup och reminders.restore_undo.
 * Läser JSON-kopior. Skriver bara reminders och reminder_restore_log.
 * completed=1 sätter samma fält som en vanlig klarmarkering.
 * Inget utskick och ingen JSON-fil.
 */

function jeab_reminders_restore_flag(mixed $value): bool
{
    return $value === 1 || $value === '1' || $value === true;
}

/**
 * @return list<string>
 */
function jeab_reminders_restore_patterns(): array
{
    return [
        'store.json',
        'store.bak-*',
        'reminders*.bak-*',
        'reminders-export*',
        'kontakter.bak-*',
    ];
}

/**
 * @return list<array{path:string,file:string,mtime:int,private:bool}>
 */
function jeab_reminders_restore_files(): array
{
    $dirs = jeab_reminders_backup_dirs();
    $found = [];
    foreach ($dirs as $dir) {
        foreach (jeab_reminders_restore_patterns() as $pattern) {
            foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
                if (!is_file($file) || !jeab_reminders_backup_inside($file, $dirs)) {
                    continue;
                }
                $real = realpath($file);
                if ($real === false) {
                    continue;
                }
                $found[$real] = [
                    'path' => $real,
                    'file' => basename($real),
                    'mtime' => (int) filemtime($real),
                    'private' => false,
                ];
            }
        }
    }
    if (!function_exists('jeab_restore_private_backup_dirs')) {
        require_once __DIR__ . '/restore_entities.php';
    }
    if (function_exists('jeab_restore_private_backup_dirs')) {
        foreach (jeab_restore_private_backup_dirs() as $dir) {
            foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $real = realpath($file);
                if ($real === false) {
                    continue;
                }
                $found[$real] = [
                    'path' => $real,
                    'file' => basename($real),
                    'mtime' => (int) filemtime($real),
                    'private' => true,
                ];
            }
        }
    }

    return array_values($found);
}

/**
 * @param array<string, mixed> $data
 * @return array<int, array<string, mixed>>
 */
function jeab_reminders_restore_rows(array $data): array
{
    $list = $data['reminders'] ?? null;
    if (!is_array($list)) {
        $list = [];
    }
    if (isset($data['reminder']) && is_array($data['reminder'])) {
        $list[] = $data['reminder'];
    }
    if ($list === []) {
        return [];
    }
    $out = [];
    foreach ($list as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            $id = (int) $key;
        }
        if ($id <= 0) {
            continue;
        }
        $row['id'] = $id;
        $out[$id] = $row;
    }

    return $out;
}

/**
 * Nyaste raden per id. copies fylls bara när raden ska kunna återställas.
 *
 * @param list<int>|null $onlyIds
 * @return array{files:int,newest:array<int, array{file:string,mtime:int,booking_id:int,private:bool,row?:array<string,mixed>}>,copies:array<int, list<array{file:string,mtime:int,booking_id:int,private:bool,row?:array<string,mixed>}>>,booking_ids:array<int, true>}
 */
function jeab_reminders_restore_scan(?array $onlyIds, bool $keepRow): array
{
    $only = null;
    if ($onlyIds !== null) {
        $only = [];
        foreach ($onlyIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $only[$id] = true;
            }
        }
    }
    $newest = [];
    $copies = [];
    $bookingIds = [];
    $files = 0;
    foreach (jeab_reminders_restore_files() as $file) {
        $files++;
        $decoded = json_decode((string) file_get_contents($file['path']), true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach (jeab_reminders_restore_rows($decoded) as $id => $row) {
            if ($only !== null && !isset($only[$id])) {
                continue;
            }
            $item = [
                'file' => $file['file'],
                'mtime' => $file['mtime'],
                'booking_id' => (int) ($row['booking_id'] ?? 0),
                'private' => !empty($file['private']),
            ];
            if ($keepRow) {
                $item['row'] = $row;
                $copies[$id][] = $item;
            }
            $prev = $newest[$id] ?? null;
            if (
                $prev === null
                || $file['mtime'] > $prev['mtime']
                || ($file['mtime'] === $prev['mtime'] && strcmp($file['file'], (string) $prev['file']) > 0)
            ) {
                $newest[$id] = $item;
            }
        }
        $bookings = $decoded['bookings'] ?? null;
        if (!is_array($bookings)) {
            continue;
        }
        foreach ($bookings as $brow) {
            if (!is_array($brow)) {
                continue;
            }
            $id = (int) ($brow['reminder_id'] ?? 0);
            if ($id <= 0 || ($only !== null && !isset($only[$id]))) {
                continue;
            }
            $bookingIds[$id] = true;
        }
    }

    return [
        'files' => $files,
        'newest' => $newest,
        'copies' => $copies,
        'booking_ids' => $bookingIds,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{ids?:list<int>,error?:string}
 */
function jeab_reminders_restore_parse_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $split = preg_split('/\s*,\s*/', trim($raw));
        $raw = is_array($split) ? $split : [];
    }
    if (!is_array($raw)) {
        return ['error' => 'ids ska vara en lista.'];
    }
    $index = 0;
    foreach ($raw as $key => $_value) {
        if ($key !== $index) {
            return ['error' => 'ids ska vara en lista.'];
        }
        $index++;
    }
    if ($raw === []) {
        return ['error' => 'ids krävs.'];
    }
    if (count($raw) > 50) {
        return ['error' => 'Högst 50 id.'];
    }
    $ids = [];
    foreach ($raw as $value) {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9]\d*\z/', $value) === 1) {
            $id = (int) $value;
        } else {
            return ['error' => 'Ogiltigt id.'];
        }
        if ($id <= 0) {
            return ['error' => 'Ogiltigt id.'];
        }
        $ids[$id] = $id;
    }

    return ['ids' => array_values($ids)];
}

function jeab_reminders_restore_dry(array $input): int
{
    if (!array_key_exists('dry', $input)) {
        return 1;
    }
    $dry = $input['dry'];
    if ($dry === 0 || $dry === '0' || $dry === false) {
        return 0;
    }

    return 1;
}

function jeab_reminders_restore_cut(mixed $value, int $max): ?string
{
    if ($value === null || is_array($value)) {
        return null;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if ($max > 0) {
        $text = function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    return $text;
}

function jeab_reminders_restore_clock(mixed $value): string
{
    if ($value === null || is_array($value)) {
        return '';
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $raw) === 1) {
        $raw .= ' 00:00:00';
    }
    $parsed = jeab_parse_datetime($raw);

    return is_string($parsed) ? $parsed : '';
}

function jeab_reminders_restore_file_date(int $mtime): string
{
    if ($mtime <= 0) {
        return '';
    }

    return (new DateTimeImmutable('@' . $mtime))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s');
}

/**
 * @param array<string, mixed> $row
 * @return array{assignee:?string,assignees:?string}
 */
function jeab_reminders_restore_assignees(array $row): array
{
    $raw = $row['assignees'] ?? null;
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : trim($raw);
    }
    if (!is_array($raw)) {
        $one = jeab_reminders_restore_cut($raw !== null && $raw !== '' ? $raw : ($row['assignee'] ?? null), 191);
        if ($one === null) {
            return ['assignee' => null, 'assignees' => null];
        }

        return [
            'assignee' => $one,
            'assignees' => json_encode([$one], JSON_UNESCAPED_UNICODE),
        ];
    }
    $names = [];
    foreach ($raw as $name) {
        $name = jeab_reminders_restore_cut($name, 191);
        if ($name !== null) {
            $names[] = $name;
        }
    }
    if ($names === []) {
        return ['assignee' => null, 'assignees' => null];
    }
    $joined = jeab_reminders_restore_cut(implode(', ', $names), 191);

    return [
        'assignee' => $joined,
        'assignees' => json_encode($names, JSON_UNESCAPED_UNICODE),
    ];
}

function jeab_reminders_restore_tags(mixed $tags): ?string
{
    if (is_string($tags)) {
        $decoded = json_decode($tags, true);
        if (is_array($decoded)) {
            $tags = $decoded;
        } else {
            return jeab_reminders_restore_cut($tags, 0);
        }
    }
    if (!is_array($tags)) {
        return null;
    }
    $out = [];
    foreach ($tags as $tag) {
        $tag = jeab_reminders_restore_cut($tag, 64);
        if ($tag !== null) {
            $out[] = $tag;
        }
    }
    if ($out === []) {
        return null;
    }
    $json = json_encode($out, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : null;
}

function jeab_reminders_restore_default_list(PDO $pdo): int
{
    $id = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($id > 0) {
        return $id;
    }
    $pdo->prepare('INSERT INTO reminder_lists (name, created_at) VALUES (?, ?)')->execute(['Påminnelser', jeab_now()]);

    return (int) $pdo->lastInsertId();
}

function jeab_reminders_restore_list_id(PDO $pdo, int $wanted): int
{
    if ($wanted > 0) {
        $stmt = $pdo->prepare('SELECT id FROM reminder_lists WHERE id = ?');
        $stmt->execute([$wanted]);
        if ((int) $stmt->fetchColumn() === $wanted) {
            return $wanted;
        }
    }

    return jeab_reminders_restore_default_list($pdo);
}

function jeab_reminders_restore_booking_fk(PDO $pdo, int $wanted): ?int
{
    if ($wanted <= 0 || !jeab_reminders_sql_table($pdo, 'bookings')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM bookings WHERE id = ?');
    $stmt->execute([$wanted]);
    $id = (int) $stmt->fetchColumn();

    return $id === $wanted ? $id : null;
}

/**
 * @return array<string, mixed>
 */
function jeab_reminders_restore_blank(int $id, int $listId, string $created): array
{
    return [
        'id' => $id,
        'list_id' => $listId,
        'title' => '',
        'customer_name' => null,
        'email' => null,
        'phone' => null,
        'address' => null,
        'ort' => null,
        'postal' => null,
        'assignee' => null,
        'assignees' => null,
        'company' => null,
        'person' => null,
        'flagged' => 0,
        'tags' => null,
        'note' => null,
        'due_at' => null,
        'completed_at' => null,
        'booking_id' => null,
        'contact_id' => null,
        'thread_id' => null,
        'created_at' => $created,
        'updated_at' => $created,
        'ringt' => 0,
        'pratat' => 0,
        'mailat' => 0,
        'smsat' => 0,
        'restored' => 0,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_reminders_restore_from_backup_row(PDO $pdo, array $row, string $fileDate): array
{
    $id = (int) ($row['id'] ?? 0);
    $created = jeab_reminders_restore_clock($row['created_at'] ?? null);
    if ($created === '') {
        $created = $fileDate !== '' ? $fileDate : '1970-01-01 00:00:00';
    }
    $updated = jeab_reminders_restore_clock($row['updated_at'] ?? null);
    if ($updated === '') {
        $updated = $created;
    }
    $fields = jeab_reminders_restore_blank($id, jeab_reminders_restore_list_id($pdo, (int) ($row['list_id'] ?? 0)), $created);
    $fields['updated_at'] = $updated;
    $fields['title'] = jeab_reminders_restore_cut($row['title'] ?? null, 255) ?? '';
    $fields['customer_name'] = jeab_reminders_restore_cut($row['customer_name'] ?? null, 191);
    $fields['email'] = jeab_reminders_restore_cut($row['email'] ?? null, 191);
    $fields['phone'] = jeab_reminders_restore_cut($row['phone'] ?? null, 64);
    $fields['address'] = jeab_reminders_restore_cut($row['address'] ?? null, 255);
    $fields['ort'] = jeab_reminders_restore_cut($row['ort'] ?? null, 191);
    $fields['postal'] = jeab_reminders_restore_cut($row['postal'] ?? null, 16);
    $names = jeab_reminders_restore_assignees($row);
    $fields['assignee'] = $names['assignee'];
    $fields['assignees'] = $names['assignees'];
    $fields['company'] = jeab_normalize_company($row['company'] ?? null);
    $person = jeab_reminders_restore_cut($row['person'] ?? null, 64);
    if ($person === null) {
        $person = jeab_reminders_restore_cut($row['assignee'] ?? null, 64);
    }
    $fields['person'] = $person;
    $fields['flagged'] = !empty($row['flagged']) ? 1 : 0;
    $fields['tags'] = jeab_reminders_restore_tags($row['tags'] ?? null);
    $fields['note'] = jeab_reminders_restore_cut($row['note'] ?? null, 0);
    $due = jeab_reminders_restore_clock($row['due_at'] ?? null);
    $done = jeab_reminders_restore_clock($row['completed_at'] ?? null);
    $fields['due_at'] = $due !== '' ? $due : null;
    $fields['completed_at'] = $done !== '' ? $done : null;
    $fields['booking_id'] = jeab_reminders_restore_booking_fk($pdo, (int) ($row['booking_id'] ?? 0));
    $fields['contact_id'] = jeab_reminders_restore_cut($row['contact_id'] ?? null, 32);
    $fields['thread_id'] = jeab_reminders_restore_cut($row['thread_id'] ?? null, 64);
    $fields['ringt'] = max(0, (int) ($row['ringt'] ?? 0));
    $fields['pratat'] = max(0, (int) ($row['pratat'] ?? 0));
    $fields['mailat'] = max(0, (int) ($row['mailat'] ?? 0));
    $fields['smsat'] = max(0, (int) ($row['smsat'] ?? 0));
    $fields['restored'] = 0;

    return $fields;
}

/**
 * @param array<string, mixed> $payload
 * @param array<string, mixed> $row
 */
function jeab_reminders_restore_pick(array $payload, array $row, string ...$keys): string
{
    foreach ([$payload, $row] as $source) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || is_array($source[$key])) {
                continue;
            }
            $text = trim((string) $source[$key]);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return '';
}

/**
 * Bara de fält en återställning behöver. Inget namn, ingen e-post, inget personnummer.
 *
 * @return array{company:?string,list_id:int,person:string,contact_id:string,address:string,ort:string,date:string,booking_id:int}|null
 */
function jeab_reminders_restore_order_fields(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !jeab_reminders_sql_table($pdo, 'purchases') || !jeab_reminders_sql_column($pdo, 'purchases', 'reminder_id')) {
        return null;
    }
    $cols = ['reminder_id', 'payload'];
    foreach (['company', 'seller', 'created_at', 'updated_at', 'contact_id'] as $col) {
        if (jeab_reminders_sql_column($pdo, 'purchases', $col)) {
            $cols[] = $col;
        }
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM purchases WHERE reminder_id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $payload = json_decode((string) ($row['payload'] ?? ''), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $list = 0;
    foreach (['list_id', 'list'] as $key) {
        if (isset($payload[$key]) && is_numeric($payload[$key]) && (int) $payload[$key] > 0) {
            $list = (int) $payload[$key];
            break;
        }
    }
    $person = jeab_reminders_restore_pick($payload, $row, 'seller', 'person', 'salesperson');
    if ($person === '') {
        $person = jeab_reminders_restore_pick($payload, $row, 'assignee');
    }
    if ($person === '' && isset($payload['assignees']) && is_array($payload['assignees'])) {
        $first = $payload['assignees'][0] ?? '';
        if (!is_array($first)) {
            $person = trim((string) $first);
        }
    }
    $date = '';
    foreach (['created_at', 'date', 'order_date', 'updated_at', 'reminder_created_at'] as $key) {
        $parsed = jeab_reminders_restore_clock(jeab_reminders_restore_pick($payload, $row, $key));
        if ($parsed !== '') {
            $date = $parsed;
            break;
        }
    }

    return [
        'company' => jeab_normalize_company(jeab_reminders_restore_pick($payload, $row, 'company')),
        'list_id' => $list,
        'person' => $person,
        'contact_id' => jeab_reminders_restore_pick($payload, $row, 'contact_id'),
        'address' => jeab_reminders_restore_pick($payload, $row, 'address', 'gata'),
        'ort' => jeab_reminders_restore_pick($payload, $row, 'ort', 'town', 'city'),
        'date' => $date,
        'booking_id' => (int) ($payload['booking_id'] ?? 0),
    ];
}

/**
 * @return array{id:int,company:?string,person:string,contact_id:string,address:string,date:string}|null
 */
function jeab_reminders_restore_booking_for(PDO $pdo, int $reminderId, int $bookingId): ?array
{
    if (!jeab_reminders_sql_table($pdo, 'bookings')) {
        return null;
    }
    $wanted = ['id', 'company', 'assignee', 'address', 'contact_id', 'starts_at', 'created_at'];
    $cols = [];
    foreach ($wanted as $col) {
        if ($col === 'id' || jeab_reminders_sql_column($pdo, 'bookings', $col)) {
            $cols[] = $col;
        }
    }
    $row = null;
    if ($reminderId > 0 && jeab_reminders_sql_column($pdo, 'bookings', 'reminder_id')) {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM bookings WHERE reminder_id = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$reminderId]);
        $fetched = $stmt->fetch();
        $row = is_array($fetched) ? $fetched : null;
    }
    if ($row === null && $bookingId > 0) {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $fetched = $stmt->fetch();
        $row = is_array($fetched) ? $fetched : null;
    }
    if ($row === null) {
        return null;
    }
    $date = jeab_reminders_restore_clock($row['starts_at'] ?? null);
    if ($date === '') {
        $date = jeab_reminders_restore_clock($row['created_at'] ?? null);
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'company' => jeab_normalize_company($row['company'] ?? null),
        'person' => trim((string) ($row['assignee'] ?? '')),
        'contact_id' => trim((string) ($row['contact_id'] ?? '')),
        'address' => trim((string) ($row['address'] ?? '')),
        'date' => $date,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function jeab_reminders_restore_from_ref(PDO $pdo, int $id): ?array
{
    $order = jeab_reminders_restore_order_fields($pdo, $id);
    $booking = jeab_reminders_restore_booking_for($pdo, $id, (int) ($order['booking_id'] ?? 0));
    if ($order === null && $booking === null) {
        return null;
    }
    $date = (string) ($order['date'] ?? '');
    if ($date === '' && $booking !== null) {
        $date = $booking['date'];
    }
    if ($date === '') {
        return null;
    }
    $list = is_array($order) ? (int) ($order['list_id'] ?? 0) : 0;
    $fields = jeab_reminders_restore_blank($id, jeab_reminders_restore_list_id($pdo, $list), $date);
    $fields['updated_at'] = $date;
    $fields['completed_at'] = $date;
    $company = is_array($order) ? ($order['company'] ?? null) : null;
    if ($company === null && $booking !== null) {
        $company = $booking['company'];
    }
    $fields['company'] = $company;
    $person = is_array($order) ? (string) ($order['person'] ?? '') : '';
    if ($person === '' && $booking !== null) {
        $person = $booking['person'];
    }
    $fields['person'] = jeab_reminders_restore_cut($person, 64);
    $contact = is_array($order) ? (string) ($order['contact_id'] ?? '') : '';
    if ($contact === '' && $booking !== null) {
        $contact = $booking['contact_id'];
    }
    $fields['contact_id'] = jeab_reminders_restore_cut($contact, 32);
    $address = is_array($order) ? (string) ($order['address'] ?? '') : '';
    if ($address === '' && $booking !== null) {
        $address = $booking['address'];
    }
    $fields['address'] = jeab_reminders_restore_cut($address, 255);
    $fields['ort'] = jeab_reminders_restore_cut(is_array($order) ? ($order['ort'] ?? null) : null, 191);
    $fields['note'] = 'Restored from order #' . $id;
    $fields['restored'] = 1;
    $bookingId = is_array($order) ? (int) ($order['booking_id'] ?? 0) : 0;
    if ($bookingId <= 0 && $booking !== null) {
        $bookingId = (int) $booking['id'];
    }
    $fields['booking_id'] = jeab_reminders_restore_booking_fk($pdo, $bookingId);

    return $fields;
}

/**
 * @param list<int> $ids
 * @return array<int, true>
 */
function jeab_reminders_restore_live_ids(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT id FROM reminders WHERE id IN (' . $marks . ')');
    $stmt->execute(array_values($ids));
    $live = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) (is_array($row) ? ($row['id'] ?? 0) : 0);
        if ($id > 0) {
            $live[$id] = true;
        }
    }

    return $live;
}

/**
 * @param array<string, mixed> $live
 * @param array<string, mixed> $expected
 */
function jeab_reminders_restore_same(array $live, array $expected): bool
{
    $numbers = ['id', 'list_id', 'flagged', 'ringt', 'pratat', 'mailat', 'smsat', 'restored', 'booking_id'];
    foreach ($expected as $key => $value) {
        $have = $live[$key] ?? null;
        if (in_array($key, $numbers, true)) {
            $left = ($have === null || $have === '') ? null : (int) $have;
            $right = ($value === null || $value === '') ? null : (int) $value;
            if ($left !== $right) {
                return false;
            }
            continue;
        }
        $left = $have === null ? '' : trim((string) $have);
        $right = $value === null ? '' : trim((string) $value);
        if ($left !== $right) {
            return false;
        }
    }

    return true;
}

/**
 * Samma fält som jeab_toggle_reminder när en rad klarmarkeras: completed_at och updated_at.
 *
 * @param array<string, mixed>|null $fields
 * @return array<string, mixed>|null
 */
function jeab_reminders_restore_apply_completed(?array $fields, string $stamp): ?array
{
    if (!is_array($fields)) {
        return null;
    }
    if ($stamp === '') {
        return $fields;
    }
    $fields['completed_at'] = $stamp;
    $fields['updated_at'] = $stamp;

    return $fields;
}

function jeab_reminders_restore_ensure(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $flag = $driver === 'mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0';
    jeab_ensure_table_columns($pdo, 'reminders', ['restored' => $flag], $driver);
    if (!jeab_reminders_sql_table($pdo, 'reminder_restore_log')) {
        if ($driver === 'mysql') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS reminder_restore_log (
                    batch_id VARCHAR(64) NOT NULL,
                    id INT UNSIGNED NOT NULL,
                    source_file VARCHAR(255) DEFAULT NULL,
                    inserted_at DATETIME NOT NULL,
                    completed_at DATETIME DEFAULT NULL,
                    KEY reminder_restore_log_batch (batch_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS reminder_restore_log (
                    batch_id TEXT NOT NULL,
                    id INTEGER NOT NULL,
                    source_file TEXT,
                    inserted_at TEXT NOT NULL,
                    completed_at TEXT
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS reminder_restore_log_batch ON reminder_restore_log (batch_id)');
        }
    }
    if (!jeab_reminders_sql_table($pdo, 'reminder_restore_log')) {
        return;
    }
    $stamp = $driver === 'mysql' ? 'DATETIME DEFAULT NULL' : 'TEXT';
    jeab_ensure_table_columns($pdo, 'reminder_restore_log', ['completed_at' => $stamp], $driver);
    if ($driver !== 'mysql') {
        $pdo->exec('CREATE INDEX IF NOT EXISTS reminder_restore_log_batch ON reminder_restore_log (batch_id)');
    }
}

/**
 * @param array<string, mixed> $fields
 */
function jeab_reminders_restore_insert(PDO $pdo, array $fields): void
{
    foreach (jeab_copy_columns('reminders') as $copyCol) {
        if (array_key_exists($copyCol, $fields) && !jeab_copy_column_present($pdo, 'reminders', $copyCol)) {
            unset($fields[$copyCol]);
        }
    }
    $cols = array_keys($fields);
    $sql = 'INSERT INTO reminders (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($fields));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_restore_from_backup(JeabStore $store, array $input = []): array
{
    $parsed = jeab_reminders_restore_parse_ids($input['ids'] ?? null);
    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => $parsed['error'], 'status' => 422, 'sent' => 0];
    }
    $ids = $parsed['ids'] ?? [];
    $source = strtolower(trim((string) ($input['source'] ?? 'auto')));
    if ($source === '') {
        $source = 'auto';
    }
    if (!in_array($source, ['backup', 'ref', 'auto'], true)) {
        return ['ok' => false, 'error' => 'Okänd källa.', 'status' => 422, 'sent' => 0];
    }
    $dry = jeab_reminders_restore_dry($input);
    $markDone = jeab_reminders_restore_flag($input['completed'] ?? 0);
    if (!$store instanceof JeabPdoStore) {
        return ['ok' => false, 'error' => 'Databasen är inte öppen.', 'status' => 400, 'sent' => 0];
    }
    $pdo = $store->pdo();
    if (!jeab_reminders_sql_table($pdo, 'reminders')) {
        return ['ok' => false, 'error' => 'Databasen är inte öppen.', 'status' => 400, 'sent' => 0];
    }
    $scan = jeab_reminders_restore_scan($ids, true);
    $live = jeab_reminders_restore_live_ids($pdo, $ids);
    $plans = [];
    foreach ($ids as $id) {
        $backup = $scan['newest'][$id] ?? null;
        $useBackup = $backup !== null && ($source === 'backup' || $source === 'auto');
        $fields = null;
        $via = 'missing';
        $file = null;
        $date = null;
        $sourceFile = null;
        if ($useBackup && is_array($backup) && isset($backup['row']) && is_array($backup['row'])) {
            $date = jeab_reminders_restore_file_date((int) $backup['mtime']);
            $fields = jeab_reminders_restore_from_backup_row($pdo, $backup['row'], $date);
            $via = 'backup';
            $file = (string) $backup['file'];
            $sourceFile = $file;
        } elseif ($source === 'ref' || $source === 'auto') {
            $fields = jeab_reminders_restore_from_ref($pdo, $id);
            if ($fields !== null) {
                $via = 'ref';
                $date = (string) ($fields['completed_at'] ?? '');
                $sourceFile = 'ref';
            }
        }
        $order = null;
        $booking = null;
        if ($via !== 'missing') {
            $orderRow = jeab_reminders_restore_order_fields($pdo, $id);
            $orderBooking = is_array($orderRow) ? (int) ($orderRow['booking_id'] ?? 0) : 0;
            $bookingRow = jeab_reminders_restore_booking_for($pdo, $id, $orderBooking);
            if ($orderRow !== null) {
                $order = $id;
            }
            if ($bookingRow !== null && (int) $bookingRow['id'] > 0) {
                $booking = (int) $bookingRow['id'];
            } elseif ($orderRow !== null && (int) $orderRow['booking_id'] > 0) {
                $booking = (int) $orderRow['booking_id'];
            } elseif ($via === 'backup' && is_array($backup) && (int) ($backup['booking_id'] ?? 0) > 0) {
                $booking = (int) $backup['booking_id'];
            }
        }
        $doneAt = '';
        if ($markDone && is_array($fields)) {
            $doneAt = $via === 'backup' && is_string($date) && $date !== '' ? $date : jeab_now();
            $fields = jeab_reminders_restore_apply_completed($fields, $doneAt);
        }
        $plans[] = [
            'id' => $id,
            'live' => isset($live[$id]),
            'via' => $via,
            'file' => $file,
            'date' => $date !== '' ? $date : null,
            'fields' => $fields,
            'source_file' => $sourceFile,
            'order' => $order,
            'booking' => $booking,
            'done_at' => $doneAt,
            'private' => $via === 'backup' && is_array($backup) && !empty($backup['private']),
        ];
    }
    $privateWrite = false;
    if ($dry === 0) {
        foreach ($plans as $plan) {
            if (!$plan['live'] && is_array($plan['fields']) && !empty($plan['private'])) {
                $privateWrite = true;
                break;
            }
        }
        if ($privateWrite && (string) ($input['confirm'] ?? '') !== 'restore') {
            return ['ok' => false, 'error' => 'confirm:restore krävs.', 'status' => 400, 'sent' => 0];
        }
    }
    $inserted = [];
    $batch = null;
    if ($dry === 0) {
        $will = false;
        foreach ($plans as $plan) {
            if (!$plan['live'] && $plan['fields'] !== null) {
                $will = true;
                break;
            }
        }
        if ($will) {
            if (function_exists('jeab_data_write_guard')) {
                try {
                    jeab_data_write_guard();
                } catch (Throwable) {
                    return ['ok' => false, 'error' => 'Underhåll pågår. Försök igen om en stund.', 'status' => 503, 'sent' => 0];
                }
            }
            try {
                jeab_reminders_restore_ensure($pdo);
                if ($privateWrite && !function_exists('jeab_restore_seal_read')) {
                    require_once __DIR__ . '/restore_entities.php';
                }
                $batch = bin2hex(random_bytes(16));
                $pdo->beginTransaction();
                $seal = $privateWrite && function_exists('jeab_restore_seal_read') ? jeab_restore_seal_read($pdo) : null;
                $stamp = jeab_now();
                foreach ($plans as $plan) {
                    if ($plan['live'] || !is_array($plan['fields'])) {
                        continue;
                    }
                    $again = jeab_reminders_restore_live_ids($pdo, [$plan['id']]);
                    if (isset($again[$plan['id']])) {
                        continue;
                    }
                    jeab_reminders_restore_insert($pdo, $plan['fields']);
                    $pdo->prepare('INSERT INTO reminder_restore_log (batch_id, id, source_file, inserted_at, completed_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([
                            $batch,
                            $plan['id'],
                            $plan['source_file'],
                            $stamp,
                            $plan['done_at'] !== '' ? $plan['done_at'] : null,
                        ]);
                    $inserted[$plan['id']] = $plan['id'];
                }
                if (is_array($seal) && function_exists('jeab_restore_seal_read') && jeab_restore_seal_read($pdo) !== $seal) {
                    throw new RuntimeException('frozen');
                }
                $pdo->commit();
            } catch (Throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                return ['ok' => false, 'error' => 'Återställningen gick inte att spara.', 'status' => 500, 'sent' => 0];
            }
        }
    }
    $items = [];
    $skipped = [];
    $missing = [];
    foreach ($plans as $plan) {
        $fields = is_array($plan['fields']) ? $plan['fields'] : null;
        $state = null;
        $company = null;
        $list = null;
        if ($fields !== null) {
            $state = trim((string) ($fields['completed_at'] ?? '')) !== '' ? 'completed' : 'open';
            $company = $fields['company'];
            $list = (int) $fields['list_id'];
        }
        if ($plan['live']) {
            $skipped[] = $plan['id'];
        } elseif ($plan['via'] === 'missing') {
            $missing[] = $plan['id'];
        }
        $items[] = [
            'id' => $plan['id'],
            'file' => $plan['file'],
            'date' => $plan['date'],
            'state' => $state,
            'company' => $company,
            'list' => $list,
            'order' => $plan['order'],
            'booking' => $plan['booking'],
            'via' => $plan['via'],
            'live' => $plan['live'] ? 1 : 0,
            'completed' => $plan['done_at'] !== '' ? 1 : 0,
        ];
    }

    return [
        'ok' => true,
        'action' => 'reminders.restore_from_backup',
        'dry' => $dry,
        'read_only' => $dry === 1,
        'sent' => 0,
        'source' => $source,
        'batch' => $inserted === [] ? null : $batch,
        'inserted' => array_values($inserted),
        'skipped' => $skipped,
        'missing' => $missing,
        'items' => $items,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_restore_from_backup_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminders_restore_from_backup($store, $input);
    $result['status'] = (int) ($result['status'] ?? 200);

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_restore_undo(JeabStore $store, array $input = []): array
{
    $batch = trim((string) ($input['batch'] ?? $input['batch_id'] ?? ''));
    if (preg_match('/\A[a-f0-9]{16,64}\z/', $batch) !== 1) {
        return ['ok' => false, 'error' => 'batch krävs.', 'status' => 422, 'sent' => 0];
    }
    $dry = jeab_reminders_restore_dry($input);
    if (!$store instanceof JeabPdoStore) {
        return ['ok' => false, 'error' => 'Databasen är inte öppen.', 'status' => 400, 'sent' => 0];
    }
    $pdo = $store->pdo();
    $empty = [
        'ok' => true,
        'action' => 'reminders.restore_undo',
        'dry' => $dry,
        'read_only' => $dry === 1,
        'sent' => 0,
        'batch' => $batch,
        'deleted' => [],
        'would_delete' => [],
        'kept' => [],
        'gone' => [],
        'status' => 200,
    ];
    if (!jeab_reminders_sql_table($pdo, 'reminder_restore_log')) {
        return $empty;
    }
    $stampCol = jeab_reminders_sql_column($pdo, 'reminder_restore_log', 'completed_at');
    $stmt = $pdo->prepare(
        $stampCol
            ? 'SELECT id, source_file, completed_at FROM reminder_restore_log WHERE batch_id = ? ORDER BY id ASC'
            : 'SELECT id, source_file FROM reminder_restore_log WHERE batch_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$batch]);
    $logs = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || isset($logs[$id])) {
            continue;
        }
        $loggedStamp = '';
        if ($stampCol) {
            $rawStamp = $row['completed_at'] ?? null;
            if (is_string($rawStamp) && trim($rawStamp) !== '') {
                $loggedStamp = trim($rawStamp);
            }
        }
        $logs[$id] = [
            'file' => (string) ($row['source_file'] ?? ''),
            'stamp' => $loggedStamp,
        ];
    }
    if ($logs === []) {
        return $empty;
    }
    $scan = jeab_reminders_restore_scan(array_keys($logs), true);
    $marks = implode(',', array_fill(0, count($logs), '?'));
    $liveStmt = $pdo->prepare('SELECT * FROM reminders WHERE id IN (' . $marks . ')');
    $liveStmt->execute(array_keys($logs));
    $live = [];
    foreach ($liveStmt->fetchAll() as $row) {
        if (is_array($row)) {
            $live[(int) ($row['id'] ?? 0)] = $row;
        }
    }
    $would = [];
    $kept = [];
    $gone = [];
    foreach ($logs as $id => $logged) {
        $sourceFile = (string) ($logged['file'] ?? '');
        $loggedStamp = (string) ($logged['stamp'] ?? '');
        $row = $live[$id] ?? null;
        if (!is_array($row)) {
            $gone[] = $id;
            continue;
        }
        $matched = false;
        if ($sourceFile === 'ref') {
            $expected = jeab_reminders_restore_apply_completed(jeab_reminders_restore_from_ref($pdo, $id), $loggedStamp);
            $matched = is_array($expected) && jeab_reminders_restore_same($row, $expected);
        } else {
            foreach ($scan['copies'][$id] ?? [] as $copy) {
                if ((string) ($copy['file'] ?? '') !== $sourceFile || !isset($copy['row']) || !is_array($copy['row'])) {
                    continue;
                }
                $expected = jeab_reminders_restore_apply_completed(
                    jeab_reminders_restore_from_backup_row(
                        $pdo,
                        $copy['row'],
                        jeab_reminders_restore_file_date((int) ($copy['mtime'] ?? 0))
                    ),
                    $loggedStamp
                );
                if (is_array($expected) && jeab_reminders_restore_same($row, $expected)) {
                    $matched = true;
                    break;
                }
            }
        }
        if ($matched) {
            $would[] = $id;
        } else {
            $kept[] = $id;
        }
    }
    $deleted = [];
    if ($dry === 0 && $would !== []) {
        if (function_exists('jeab_data_write_guard')) {
            try {
                jeab_data_write_guard();
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'Underhåll pågår. Försök igen om en stund.', 'status' => 503, 'sent' => 0];
            }
        }
        try {
            if (!function_exists('jeab_restore_seal_read')) {
                require_once __DIR__ . '/restore_entities.php';
            }
            $pdo->beginTransaction();
            $seal = function_exists('jeab_restore_seal_read') ? jeab_restore_seal_read($pdo) : [];
            if (!function_exists('jeab_reminders_sql_delete')) {
                require_once __DIR__ . '/restore_entities.php';
            }
            $GLOBALS['_JEAB_REMINDER_DELETE_LOCK'] = 1;
            foreach ($would as $id) {
                $fresh = $pdo->prepare('SELECT * FROM reminders WHERE id = ?');
                $fresh->execute([$id]);
                $row = $fresh->fetch();
                if (!is_array($row)) {
                    $gone[] = $id;
                    continue;
                }
                $sourceFile = (string) ($logs[$id]['file'] ?? '');
                $loggedStamp = (string) ($logs[$id]['stamp'] ?? '');
                $matched = false;
                if ($sourceFile === 'ref') {
                    $expected = jeab_reminders_restore_apply_completed(jeab_reminders_restore_from_ref($pdo, $id), $loggedStamp);
                    $matched = is_array($expected) && jeab_reminders_restore_same($row, $expected);
                } else {
                    foreach ($scan['copies'][$id] ?? [] as $copy) {
                        if ((string) ($copy['file'] ?? '') !== $sourceFile || !isset($copy['row']) || !is_array($copy['row'])) {
                            continue;
                        }
                        $expected = jeab_reminders_restore_apply_completed(
                            jeab_reminders_restore_from_backup_row(
                                $pdo,
                                $copy['row'],
                                jeab_reminders_restore_file_date((int) ($copy['mtime'] ?? 0))
                            ),
                            $loggedStamp
                        );
                        if (is_array($expected) && jeab_reminders_restore_same($row, $expected)) {
                            $matched = true;
                            break;
                        }
                    }
                }
                if (!$matched) {
                    $kept[] = $id;
                    continue;
                }
                jeab_reminders_sql_delete($pdo, [$id]);
                $deleted[] = $id;
            }
            if (function_exists('jeab_restore_seal_read') && jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
            $pdo->commit();
            unset($GLOBALS['_JEAB_REMINDER_DELETE_LOCK']);
        } catch (Throwable) {
            unset($GLOBALS['_JEAB_REMINDER_DELETE_LOCK']);
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'error' => 'Ångringen gick inte att spara.', 'status' => 500, 'sent' => 0];
        }
    }
    $kept = array_values(array_diff(array_unique($kept), $deleted, $gone));
    sort($kept);
    sort($gone);

    return [
        'ok' => true,
        'action' => 'reminders.restore_undo',
        'dry' => $dry,
        'read_only' => $dry === 1,
        'sent' => 0,
        'batch' => $batch,
        'deleted' => $deleted,
        'would_delete' => $would,
        'kept' => $kept,
        'gone' => $gone,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_restore_undo_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_reminders_restore_undo($store, $input);
    $result['status'] = (int) ($result['status'] ?? 200);

    return $result;
}
