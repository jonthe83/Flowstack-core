<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_sole_audit.php';

/**
 * contacts.inbox_strip. dry=1 är standard.
 *
 * Tar bort lagrade kundfält (namn, e-post, telefon, adress, ort, postnr)
 * på inkorg_threads och inkorg_list som redan har contact_id. Rader utan
 * contact_id lämnas, till exempel spam. Kontaktlänken rörs inte.
 * Inget skickas och inga kontakter skapas eller slås ihop.
 *
 * dry=0 skriver ångerfilen innan någon rad ändras.
 * Filen ligger under data/.private, är 0600 och har bara de tömda kundfälten.
 * Ett personnummer skrivs inte dit och lämnas kvar på raden. Filer äldre än 14 dagar tas bort.
 * Strip och undo vägrar när CONTACTS_STRICT eller CONTACTS_SOLE är av.
 * contacts.inbox_strip_undo är torr som standard och lägger tillbaka fälten
 * bara när de tömda fälten och e-postkolumnen fortfarande är tomma.
 */

function jeab_contacts_inbox_customer_keys(): array
{
    return [
        'customer_name',
        'customer_email',
        'customer_address',
        'customer_phone',
        'phone',
        'customer_city',
        'ort',
        'postal',
        'postnr',
        'email',
        'name',
        'address',
        'lead_customer.email',
        'lead_customer.name',
        'lead_customer.phone',
        'lead_customer.address',
    ];
}

function jeab_contacts_inbox_strip_flags_on(): bool
{
    if (!function_exists('jeab_contacts_inbox_hold')) {
        require_once __DIR__ . '/contacts_strict.php';
    }

    return jeab_contacts_inbox_hold();
}

/** @return array{ok:false,error:string,written:false,status:int} */
function jeab_contacts_inbox_strip_flags_off(): array
{
    return [
        'ok' => false,
        'error' => 'CONTACTS_STRICT och CONTACTS_SOLE måste vara på.',
        'written' => false,
        'status' => 400,
    ];
}

function jeab_contacts_inbox_strip_value(array $row, string $key): string
{
    if (!str_contains($key, '.')) {
        return array_key_exists($key, $row) ? (string) $row[$key] : '';
    }
    [$parent, $child] = explode('.', $key, 2);
    $node = $row[$parent] ?? null;
    if (!is_array($node) || !array_key_exists($child, $node)) {
        return '';
    }

    return (string) $node[$child];
}

/** @param array<string, mixed> $row */
function jeab_contacts_inbox_strip_put(array &$row, string $key, string $value): void
{
    if (!str_contains($key, '.')) {
        $row[$key] = $value;

        return;
    }
    [$parent, $child] = explode('.', $key, 2);
    $node = $row[$parent] ?? null;
    if (!is_array($node)) {
        $node = [];
    }
    $node[$child] = $value;
    $row[$parent] = $node;
}

function jeab_contacts_inbox_strip_ten_pnr(string $ten): bool
{
    if (preg_match('/^\d{10}$/', $ten) !== 1) {
        return false;
    }
    $month = (int) substr($ten, 2, 2);
    $day = (int) substr($ten, 4, 2);
    if ($month < 1 || $month > 12) {
        return false;
    }
    $real = $day > 60 ? $day - 60 : $day;

    return $real >= 1 && $real <= 31;
}

function jeab_contacts_inbox_strip_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_inbox_strip_has_pnr(string $value): bool
{
    if (preg_match('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', $value) === 1) {
        return true;
    }
    if (preg_match_all('/(?<!\d)(\d{10})(?!\d)/', $value, $found) < 1) {
        return false;
    }
    foreach ($found[1] as $ten) {
        if (jeab_contacts_inbox_strip_ten_pnr((string) $ten)) {
            return true;
        }
    }

    return false;
}

/** Loggar tabell, id och fält. Värdet skrivs inte. */
function jeab_contacts_inbox_strip_log_pnr(string $table, string $id, string $field): void
{
    $table = preg_replace('/[^a-z_]/', '', $table) ?? '';
    $id = preg_replace('/[^A-Za-z0-9_.:-]/', '', $id) ?? '';
    $field = preg_replace('/[^a-z_.]/', '', $field) ?? '';
    if ($table === '' || $id === '' || $field === '') {
        return;
    }
    error_log('contacts.inbox_strip pnr-falt lamnat table=' . $table . ' id=' . $id . ' field=' . $field);
}

function jeab_contacts_inbox_strip_dir(): string
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

    return $base . '/contacts-inbox-strip';
}

function jeab_contacts_inbox_strip_prune(string $dir): void
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

/**
 * Bara de nio kundfälten. Värden som innehåller personnummer utelämnas.
 *
 * @param array<string, mixed> $json
 * @return array{table:string,id:string,fields:array<string,string>,email_column:bool,email_before:string}
 */
function jeab_contacts_inbox_strip_undo_row(string $table, string $id, array $json, bool $emailColumn, string $beforeEmail): array
{
    $fields = [];
    foreach (jeab_contacts_inbox_customer_keys() as $key) {
        $value = jeab_contacts_inbox_strip_value($json, $key);
        if (trim($value) === '' || jeab_contacts_inbox_strip_has_pnr($value)) {
            continue;
        }
        $fields[$key] = $value;
    }
    $emailBefore = '';
    if ($emailColumn && trim($beforeEmail) !== '' && !jeab_contacts_inbox_strip_has_pnr($beforeEmail)) {
        $emailBefore = $beforeEmail;
    }

    return [
        'table' => $table,
        'id' => $id,
        'fields' => $fields,
        'email_column' => $emailColumn,
        'email_before' => $emailBefore,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{0: array<string, mixed>, 1: bool, 2: list<string>}
 */
function jeab_contacts_inbox_strip_blank(array $row): array
{
    $changed = false;
    $kept = [];
    foreach (jeab_contacts_inbox_customer_keys() as $key) {
        $value = jeab_contacts_inbox_strip_value($row, $key);
        if (trim($value) === '') {
            continue;
        }
        if (jeab_contacts_inbox_strip_has_pnr($value)) {
            $kept[] = $key;
            continue;
        }
        jeab_contacts_inbox_strip_put($row, $key, '');
        $changed = true;
    }

    return [$row, $changed, $kept];
}

/**
 * @return list<array{table:string,id:string,before_json:string,after_json:string,before_email:string,after_email:string,email_column:bool}>
 */
function jeab_contacts_inbox_strip_plan(PDO $pdo, ?array &$pnr = null): array
{
    $plan = [];
    $pnr = [];
    foreach (['inkorg_threads', 'inkorg_list'] as $table) {
        $cols = jeab_contacts_sole_audit_columns($pdo, $table);
        if ($cols === [] || !isset($cols['row_json'], $cols['id'])) {
            continue;
        }
        $emailColumn = $table === 'inkorg_list' && isset($cols['customer_email']);
        $contactColumn = isset($cols['contact_id']);
        $select = 'id, row_json';
        if ($emailColumn) {
            $select .= ', customer_email';
        }
        if ($contactColumn) {
            $select .= ', contact_id';
        }
        $stmt = $pdo->query('SELECT ' . $select . ' FROM ' . $table);
        if (!$stmt instanceof PDOStatement) {
            continue;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $db) {
            if (!is_array($db)) {
                continue;
            }
            $id = trim((string) ($db['id'] ?? ''));
            $raw = (string) ($db['row_json'] ?? '');
            if ($id === '' || $raw === '') {
                continue;
            }
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                continue;
            }
            $cid = trim((string) ($json['contact_id'] ?? ''));
            if ($cid === '' && $contactColumn) {
                $cid = trim((string) ($db['contact_id'] ?? ''));
            }
            if ($cid === '') {
                continue;
            }
            [$next, $changed, $kept] = jeab_contacts_inbox_strip_blank($json);
            $beforeEmail = $emailColumn ? (string) ($db['customer_email'] ?? '') : '';
            $emailPnr = $emailColumn && trim($beforeEmail) !== '' && jeab_contacts_inbox_strip_has_pnr($beforeEmail);
            $emailChanged = $emailColumn && trim($beforeEmail) !== '' && !$emailPnr;
            if ($emailPnr) {
                $kept[] = 'customer_email';
            }
            foreach ($kept as $field) {
                $pnr[] = ['table' => $table, 'id' => $id, 'field' => $field];
            }
            if (!$changed && !$emailChanged) {
                continue;
            }
            if ($emailChanged && !jeab_contacts_inbox_strip_has_pnr(jeab_contacts_inbox_strip_value($next, 'customer_email'))) {
                $next['customer_email'] = '';
            }
            $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || $encoded === $raw) {
                if (!$emailChanged) {
                    continue;
                }
                $encoded = $raw;
            }
            $plan[] = [
                'table' => $table,
                'id' => $id,
                'before_json' => $raw,
                'after_json' => $encoded,
                'before_email' => $beforeEmail,
                'after_email' => $emailChanged ? '' : $beforeEmail,
                'email_column' => $emailChanged,
            ];
        }
    }

    return $plan;
}

/**
 * @param list<array{table:string,id:string,before_json:string,after_json:string,before_email:string,after_email:string,email_column:bool}> $plan
 * @return array{threads:int,list:int,ids:list<string>}
 */
function jeab_contacts_inbox_strip_counts(array $plan): array
{
    $threads = 0;
    $list = 0;
    $ids = [];
    foreach ($plan as $step) {
        if ($step['table'] === 'inkorg_list') {
            $list++;
        } else {
            $threads++;
        }
        $ids[] = $step['id'];
    }
    $ids = array_values(array_unique($ids));
    sort($ids);

    return ['threads' => $threads, 'list' => $list, 'ids' => $ids];
}

/** @param array<string, mixed> $input */
function jeab_contacts_inbox_strip(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_inbox_strip_dry($input);
    if (!jeab_contacts_inbox_strip_flags_on()) {
        return [
            'ok' => false,
            'action' => 'contacts.inbox_strip',
            'dry' => $dry,
            'read_only' => true,
            'written' => false,
            'undo_id' => '',
            'threads' => 0,
            'list' => 0,
            'ids' => [],
        ] + jeab_contacts_inbox_strip_flags_off();
    }
    $base = [
        'ok' => true,
        'action' => 'contacts.inbox_strip',
        'dry' => $dry,
        'read_only' => $dry,
        'written' => false,
        'undo_id' => '',
        'threads' => 0,
        'list' => 0,
        'ids' => [],
    ];
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return $base + ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400];
    }
    $pnr = [];
    $plan = jeab_contacts_inbox_strip_plan($pdo, $pnr);
    if (!$dry) {
        foreach ($pnr as $note) {
            jeab_contacts_inbox_strip_log_pnr((string) $note['table'], (string) $note['id'], (string) $note['field']);
        }
    }
    $counts = jeab_contacts_inbox_strip_counts($plan);
    $base['threads'] = $counts['threads'];
    $base['list'] = $counts['list'];
    $base['ids'] = $counts['ids'];
    $dir = jeab_contacts_inbox_strip_dir();
    jeab_contacts_inbox_strip_prune($dir);
    if ($dry || $plan === []) {
        return $base;
    }
    if ($dir === '') {
        return $base + ['ok' => false, 'error' => 'Ångerfilen kunde inte skrivas.', 'written' => false, 'status' => 400];
    }
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return $base + ['ok' => false, 'error' => 'Ångerfilen kunde inte skrivas.', 'written' => false, 'status' => 400];
    }
    @chmod($dir, 0700);
    $undoId = date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $path = $dir . '/' . $undoId . '.json';
    $rows = [];
    foreach ($plan as $step) {
        $json = json_decode($step['before_json'], true);
        if (!is_array($json)) {
            continue;
        }
        $rows[] = jeab_contacts_inbox_strip_undo_row(
            $step['table'],
            $step['id'],
            $json,
            $step['email_column'],
            $step['before_email']
        );
    }
    $payload = json_encode(['undo_id' => $undoId, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || file_put_contents($path, $payload, LOCK_EX) === false) {
        return $base + ['ok' => false, 'error' => 'Ångerfilen kunde inte skrivas.', 'written' => false, 'status' => 400];
    }
    if (!chmod($path, 0600)) {
        @unlink($path);

        return $base + ['ok' => false, 'error' => 'Ångerfilen kunde inte skyddas.', 'written' => false, 'status' => 400];
    }
    clearstatcache(true, $path);
    $mode = fileperms($path);
    if ($mode === false || ($mode & 0777) !== 0600) {
        @unlink($path);

        return $base + ['ok' => false, 'error' => 'Ångerfilen kunde inte skyddas.', 'written' => false, 'status' => 400];
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($plan as $step) {
            if (!in_array($step['table'], ['inkorg_threads', 'inkorg_list'], true)) {
                continue;
            }
            if ($step['email_column'] && $step['table'] === 'inkorg_list') {
                $stmt = $pdo->prepare('UPDATE inkorg_list SET row_json = ?, customer_email = ? WHERE id = ? AND row_json = ?');
                $stmt->execute([$step['after_json'], '', $step['id'], $step['before_json']]);
            } else {
                $stmt = $pdo->prepare('UPDATE ' . $step['table'] . ' SET row_json = ? WHERE id = ? AND row_json = ?');
                $stmt->execute([$step['after_json'], $step['id'], $step['before_json']]);
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($path);

        return $base + ['ok' => false, 'error' => 'Raden kunde inte skrivas.', 'written' => false, 'status' => 400];
    }
    $base['dry'] = false;
    $base['read_only'] = false;
    $base['written'] = true;
    $base['undo_id'] = $undoId;

    return $base;
}

/** @return array<string, mixed>|null */
function jeab_contacts_inbox_strip_load(string $undoId): ?array
{
    if (preg_match('/^[0-9]{14}-[a-f0-9]{6}$/', $undoId) !== 1) {
        return null;
    }
    $path = jeab_contacts_inbox_strip_dir() . '/' . $undoId . '.json';
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/** @param array<string, mixed> $input */
function jeab_contacts_inbox_strip_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_inbox_strip_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    if (!jeab_contacts_inbox_strip_flags_on()) {
        return [
            'ok' => false,
            'action' => 'contacts.inbox_strip_undo',
            'dry' => $dry,
            'read_only' => true,
            'written' => false,
            'undo_id' => $undoId,
            'threads' => 0,
            'list' => 0,
            'skipped' => 0,
            'ids' => [],
        ] + jeab_contacts_inbox_strip_flags_off();
    }
    $base = [
        'ok' => true,
        'action' => 'contacts.inbox_strip_undo',
        'dry' => $dry,
        'read_only' => $dry,
        'written' => false,
        'undo_id' => $undoId,
        'threads' => 0,
        'list' => 0,
        'skipped' => 0,
        'ids' => [],
    ];
    jeab_contacts_inbox_strip_prune(jeab_contacts_inbox_strip_dir());
    $file = jeab_contacts_inbox_strip_load($undoId);
    if (!is_array($file)) {
        return $base + ['ok' => false, 'error' => 'Ångerfilen saknas.', 'status' => 400];
    }
    $rows = is_array($file['rows'] ?? null) ? $file['rows'] : [];
    $ids = [];
    foreach ($rows as $step) {
        if (is_array($step) && trim((string) ($step['id'] ?? '')) !== '') {
            $ids[] = (string) $step['id'];
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    $base['ids'] = $ids;
    $base['threads'] = count(array_filter($rows, static fn($step): bool => is_array($step) && ($step['table'] ?? '') === 'inkorg_threads'));
    $base['list'] = count(array_filter($rows, static fn($step): bool => is_array($step) && ($step['table'] ?? '') === 'inkorg_list'));
    if ($dry) {
        return $base;
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return $base + ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400];
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
            if (!in_array($table, ['inkorg_threads', 'inkorg_list'], true)) {
                $skipped++;
                continue;
            }
            $id = (string) ($step['id'] ?? '');
            $fields = is_array($step['fields'] ?? null) ? $step['fields'] : [];
            if ($id === '') {
                $skipped++;
                continue;
            }
            $emailColumn = !empty($step['email_column']) && $table === 'inkorg_list'
                && (!function_exists('jeab_copy_column_present') || jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'));
            // Kolumnen är borttagen (contacts.drop_customer_fields): e-posten läggs i row_json i stället.
            if (!$emailColumn && $table === 'inkorg_list' && trim((string) ($step['email_before'] ?? '')) !== '' && !array_key_exists('customer_email', $fields)) {
                $fields['customer_email'] = (string) $step['email_before'];
            }
            $select = 'row_json';
            if ($emailColumn) {
                $select .= ', customer_email';
            }
            $read = $pdo->prepare('SELECT ' . $select . ' FROM ' . $table . ' WHERE id = ?');
            $read->execute([$id]);
            $db = $read->fetch(PDO::FETCH_ASSOC);
            if (!is_array($db)) {
                $skipped++;
                continue;
            }
            $raw = (string) ($db['row_json'] ?? '');
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                $skipped++;
                continue;
            }
            $changedSince = false;
            foreach (jeab_contacts_inbox_customer_keys() as $key) {
                if (!array_key_exists($key, $fields)) {
                    continue;
                }
                if (trim(jeab_contacts_inbox_strip_value($json, $key)) !== '') {
                    $changedSince = true;
                    break;
                }
            }
            if ($emailColumn && trim((string) ($db['customer_email'] ?? '')) !== '') {
                $changedSince = true;
            }
            if ($changedSince) {
                $skipped++;
                continue;
            }
            foreach ($fields as $key => $value) {
                if (!is_string($key) || !in_array($key, jeab_contacts_inbox_customer_keys(), true)) {
                    continue;
                }
                if (jeab_contacts_inbox_strip_has_pnr((string) $value)) {
                    continue;
                }
                jeab_contacts_inbox_strip_put($json, $key, (string) $value);
            }
            $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                $skipped++;
                continue;
            }
            if ($emailColumn) {
                $stmt = $pdo->prepare('UPDATE inkorg_list SET row_json = ?, customer_email = ? WHERE id = ? AND row_json = ? AND customer_email = ?');
                $stmt->execute([$encoded, (string) ($step['email_before'] ?? ''), $id, $raw, '']);
            } else {
                $stmt = $pdo->prepare('UPDATE ' . $table . ' SET row_json = ? WHERE id = ? AND row_json = ?');
                $stmt->execute([$encoded, $id, $raw]);
            }
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

        return $base + ['ok' => false, 'error' => 'Raden kunde inte skrivas.', 'written' => false, 'status' => 400];
    }
    $base['dry'] = false;
    $base['read_only'] = false;
    $base['written'] = $restored > 0;
    $base['skipped'] = $skipped;

    return $base;
}
