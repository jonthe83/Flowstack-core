<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Saknade påminnelser och inkorgsärenden, och återställning av dem.
 * Samma torrt/confirm/undo som ordrar. Skriver inte över, skickar inget
 * och rör inte frysta ögonblicksbilder.
 */

function jeab_restore_kind(array $input): string
{
    $kind = strtolower(trim((string) ($input['type'] ?? $input['kind'] ?? 'order')));

    return match ($kind) {
        'reminder', 'reminders', 'paminnelse' => 'reminder',
        'thread', 'threads', 'arende', 'inkorg' => 'thread',
        default => 'order',
    };
}

function jeab_restore_company_from_reminder(JeabStore $store, int $id): string
{
    if ($id <= 0 || !function_exists('jeab_get_reminder')) {
        return '';
    }
    $row = jeab_get_reminder($store, $id);

    return is_array($row) ? jeab_restore_company($row['company'] ?? '') : '';
}

function jeab_restore_company(mixed $raw): string
{
    $value = is_string($raw) || is_numeric($raw) ? (string) $raw : '';
    if ($value === '' || !function_exists('jeab_normalize_company')) {
        return '';
    }
    $company = jeab_normalize_company($value);

    return $company === 'jarfalla' || $company === 'malmo' ? $company : '';
}

/** @param array<string, mixed> $reasons */
/** @param array{reasons?:array<string, true>} $hit */
function jeab_restore_backup_only(array $hit): bool
{
    $reasons = is_array($hit['reasons'] ?? null) ? $hit['reasons'] : [];

    return $reasons !== [] && array_keys($reasons) === ['backup'];
}

/**
 * Fält som får synas för en påminnelse som bara finns i en backupfil.
 *
 * @param array<string, mixed> $row
 * @return array{status:string,contact_id:string,order_id:?int}
 */
function jeab_restore_backup_public(array $row): array
{
    $status = trim((string) ($row['status'] ?? ''));
    if ($status === '' && trim((string) ($row['completed_at'] ?? '')) !== '') {
        $status = 'done';
    }
    if ($status === '' || str_contains($status, '@') || strlen($status) > 16 || preg_match('/\A[A-Za-z0-9_-]+\z/', $status) !== 1) {
        $status = '';
    }
    $contact = trim((string) ($row['contact_id'] ?? ''));
    if ($contact === '' || str_contains($contact, '@') || strlen($contact) > 64 || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $contact) !== 1) {
        $contact = '';
    }
    $order = null;
    $rawOrder = $row['order_id'] ?? null;
    if (is_int($rawOrder) && $rawOrder > 0) {
        $order = $rawOrder;
    } elseif (is_string($rawOrder) && preg_match('/\A[1-9]\d*\z/', $rawOrder) === 1) {
        $order = (int) $rawOrder;
    }

    return [
        'status' => $status,
        'contact_id' => $contact,
        'order_id' => $order,
    ];
}

/** @param array<string, mixed> $reasons */
function jeab_restore_reason_text(array $reasons): string
{
    $parts = [];
    foreach (['snapshot', 'backup', 'fellogg'] as $name) {
        if (!empty($reasons[$name])) {
            $parts[] = $name;
        }
    }

    return implode('+', $parts);
}

function jeab_restore_thread_key(mixed $raw): string
{
    $id = trim((string) $raw);
    if ($id === '' || strlen($id) > 191 || str_contains($id, '@') || preg_match('/\A[A-Za-z0-9._:-]+\z/', $id) !== 1) {
        return '';
    }

    return $id;
}

/** @return array<string, string> */
function jeab_restore_seal_read(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT id, before_json, undone FROM contacts_sole_undo WHERE kind = 'snapshot' ORDER BY id")->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[(string) ($row['id'] ?? '')] = (string) ($row['before_json'] ?? '') . '|' . (string) ($row['undone'] ?? '');
    }

    return $out;
}

function jeab_reminder_delete_guard(): void
{
    if ((int) ($GLOBALS['_JEAB_REMINDER_DELETE_LOCK'] ?? 0) !== 1) {
        throw new RuntimeException('Påminnelsen kan bara raderas via Radera.');
    }
}

function jeab_thread_delete_guard(): void
{
    if ((int) ($GLOBALS['_JEAB_THREAD_DELETE_LOCK'] ?? 0) !== 1) {
        throw new RuntimeException('Ärendet kan bara raderas via Radera.');
    }
}

function jeab_record_delete_audit_ensure(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS record_delete_audit (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                entity VARCHAR(16) NOT NULL,
                entity_id VARCHAR(191) NOT NULL,
                actor VARCHAR(191) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS record_delete_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id TEXT NOT NULL,
            actor TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

function jeab_record_delete_audit(PDO $pdo, string $entity, string $id): void
{
    $id = trim($id);
    if ($id === '' || ($entity !== 'reminder' && $entity !== 'thread')) {
        return;
    }
    jeab_record_delete_audit_ensure($pdo);
    $actor = function_exists('jeab_auth_user_email') ? trim((string) jeab_auth_user_email()) : '';
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO record_delete_audit (created_at, entity, entity_id, actor) VALUES (?, ?, ?, ?)')
        ->execute([$at, $entity, mb_substr($id, 0, 191), mb_substr($actor, 0, 191)]);
}

/** @param list<int> $ids */
function jeab_reminders_sql_delete(PDO $pdo, array $ids): int
{
    jeab_reminder_delete_guard();
    $ids = array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return 0;
    }
    foreach ($ids as $id) {
        jeab_record_delete_audit($pdo, 'reminder', (string) $id);
    }
    $marks = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('DELETE FROM reminders WHERE id IN (' . $marks . ')');
    $stmt->execute($ids);
    if (function_exists('jeab_reminders_count_cache_forget')) {
        jeab_reminders_count_cache_forget($pdo);
    }

    return $stmt->rowCount();
}

function jeab_thread_sql_delete(PDO $pdo, string $id): void
{
    jeab_thread_delete_guard();
    $id = jeab_restore_thread_key($id);
    if ($id === '') {
        return;
    }
    jeab_record_delete_audit($pdo, 'thread', $id);
    $pdo->prepare('DELETE FROM inkorg_threads WHERE id = ?')->execute([$id]);
}

/** @return list<string> */
function jeab_restore_private_backup_dirs(): array
{
    $dirs = [];
    if (function_exists('jeab_data_dir')) {
        $dirs[] = rtrim(jeab_data_dir(), '/') . '/.private/backups';
    }
    if (defined('JEAB_ROOT')) {
        $dirs[] = rtrim((string) JEAB_ROOT, '/') . '/data/.private/backups';
    }
    $out = [];
    foreach ($dirs as $dir) {
        if ($dir !== '' && is_dir($dir)) {
            $out[$dir] = true;
        }
    }

    return array_keys($out);
}

/**
 * @param array<string, array<string, array{reasons:array<string, true>, company:string, row:array<string, mixed>, file?:string, backup_mtime?:int, backup_row?:array<string, mixed>}>> $bag
 */
function jeab_restore_bag_add(array &$bag, string $kind, string $id, string $reason, string $company, array $row = [], string $file = '', int $mtime = 0): void
{
    if ($id === '' || $id === '0' || $reason === '') {
        return;
    }
    if (!isset($bag[$kind][$id])) {
        $bag[$kind][$id] = ['reasons' => [], 'company' => '', 'row' => []];
    }
    $bag[$kind][$id]['reasons'][$reason] = true;
    if ($bag[$kind][$id]['company'] === '' && $company !== '') {
        $bag[$kind][$id]['company'] = $company;
    }
    if ($bag[$kind][$id]['row'] === [] && $row !== []) {
        $bag[$kind][$id]['row'] = $row;
    }
    if ($reason !== 'backup' || $file === '') {
        return;
    }
    $name = basename(str_replace('\\', '/', $file));
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '@')) {
        return;
    }
    $prev = (int) ($bag[$kind][$id]['backup_mtime'] ?? -1);
    if ($mtime < $prev) {
        return;
    }
    $bag[$kind][$id]['file'] = $name;
    $bag[$kind][$id]['backup_mtime'] = $mtime;
    if ($row !== []) {
        $bag[$kind][$id]['backup_row'] = $row;
    }
}

/** @param array<string, mixed> $row @param array<string, array<string, array{reasons:array<string, true>, company:string, row:array<string, mixed>}>> $bag */
function jeab_restore_note_reminder(array $row, array &$bag, string $reason, string $file = '', int $mtime = 0): void
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    jeab_restore_bag_add($bag, 'reminder', (string) $id, $reason, jeab_restore_company($row['company'] ?? ''), $row, $file, $mtime);
}

/** @param array<string, mixed> $doc @param array<string, array<string, array{reasons:array<string, true>, company:string, row:array<string, mixed>}>> $bag */
function jeab_restore_note_doc(array $doc, array &$bag, string $reason, string $file = '', int $mtime = 0): void
{
    if (isset($doc['reminder']) && is_array($doc['reminder'])) {
        jeab_restore_note_reminder($doc['reminder'], $bag, $reason, $file, $mtime);
    }
    foreach (['reminders'] as $key) {
        if (!isset($doc[$key]) || !is_array($doc[$key])) {
            continue;
        }
        foreach ($doc[$key] as $row) {
            if (is_array($row)) {
                jeab_restore_note_reminder($row, $bag, $reason, $file, $mtime);
            }
        }
    }
    foreach (['orders', 'purchases'] as $key) {
        if (!isset($doc[$key]) || !is_array($doc[$key])) {
            continue;
        }
        foreach ($doc[$key] as $index => $row) {
            $id = is_array($row) ? (int) ($row['reminder_id'] ?? $row['id'] ?? 0) : 0;
            if ($id <= 0) {
                $id = (int) $index;
            }
            $company = is_array($row) ? jeab_restore_company($row['company'] ?? '') : '';
            jeab_restore_bag_add($bag, 'order', (string) $id, $reason, $company, is_array($row) ? $row : [], $file, $mtime);
        }
    }
    if (isset($doc['threads']) && is_array($doc['threads'])) {
        foreach ($doc['threads'] as $index => $row) {
            $id = jeab_restore_thread_key(is_array($row) ? ($row['id'] ?? $index) : $index);
            if ($id === '') {
                continue;
            }
            $company = is_array($row) ? jeab_restore_company($row['company'] ?? '') : '';
            jeab_restore_bag_add($bag, 'thread', $id, $reason, $company, is_array($row) ? $row : [], $file, $mtime);
        }
    }
    $ids = is_array($doc['ids'] ?? null) ? $doc['ids'] : [];
    foreach (['reminders' => 'reminder', 'orders' => 'order', 'threads' => 'thread'] as $key => $kind) {
        foreach (is_array($ids[$key] ?? null) ? $ids[$key] : [] as $raw) {
            $id = $kind === 'thread' ? jeab_restore_thread_key($raw) : (string) (int) $raw;
            if ($id === '' || $id === '0') {
                continue;
            }
            jeab_restore_bag_add($bag, $kind, $id, $reason, '', [], $file, $mtime);
        }
    }
    foreach (['reminders_rows' => 'reminder', 'orders_rows' => 'order', 'threads_rows' => 'thread', 'rows' => 'order'] as $key => $kind) {
        $rows = $doc[$key] ?? null;
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $index => $row) {
            $id = $kind === 'thread' ? jeab_restore_thread_key(is_array($row) ? ($row['id'] ?? $index) : $index) : (string) (int) $index;
            if ($id === '' || $id === '0') {
                continue;
            }
            $company = is_array($row) ? jeab_restore_company($row['company'] ?? '') : '';
            jeab_restore_bag_add($bag, $kind, $id, $reason, $company, is_array($row) ? $row : [], $file, $mtime);
        }
    }
}

/**
 * @return array<string, array<string, array{reasons:array<string, true>, company:string, row:array<string, mixed>}>>
 */
function jeab_restore_collect(string $since): array
{
    $bag = ['order' => [], 'reminder' => [], 'thread' => []];
    if (function_exists('jeab_ordrar_restore_snapshots')) {
        foreach (jeab_ordrar_restore_snapshots() as $snap) {
            if ($since !== '' && (string) ($snap['at'] ?? '') !== '' && (string) $snap['at'] < $since) {
                continue;
            }
            $data = is_array($snap['data'] ?? null) ? $snap['data'] : [];
            jeab_restore_note_doc($data, $bag, 'snapshot');
        }
    }
    $cut = $since !== '' ? strtotime($since) : false;
    foreach (jeab_restore_private_backup_dirs() as $dir) {
        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            if ($cut !== false && (int) filemtime($file) < $cut) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                jeab_restore_note_doc($decoded, $bag, 'backup', basename($file), (int) filemtime($file));
            }
        }
    }
    foreach (jeab_restore_fellogg_hits() as $hit) {
        $kind = (string) ($hit['kind'] ?? '');
        if (!isset($bag[$kind])) {
            continue;
        }
        jeab_restore_bag_add($bag, $kind, (string) ($hit['id'] ?? ''), 'fellogg', (string) ($hit['company'] ?? ''));
    }

    return $bag;
}

/** @return list<array{kind:string,id:string,company:string}> */
function jeab_restore_fellogg_hits(): array
{
    $floor = date('Y-m-d H:i:s', time() - 7 * 86400);
    $paths = [];
    if (function_exists('jeab_app_errors_path')) {
        $paths[] = jeab_app_errors_path();
    }
    if (function_exists('jeab_data_dir')) {
        $paths[] = rtrim(jeab_data_dir(), '/') . '/.private/app-errors.jsonl';
        $paths[] = rtrim(jeab_data_dir(), '/') . '/app-errors.jsonl';
    }
    $out = [];
    foreach (array_unique($paths) as $path) {
        if (!is_file($path)) {
            continue;
        }
        $lines = preg_split("/\n/", (string) file_get_contents($path)) ?: [];
        foreach ($lines as $line) {
            $row = json_decode(trim((string) $line), true);
            if (!is_array($row)) {
                continue;
            }
            $at = trim((string) ($row['time'] ?? ''));
            if ($at === '' && isset($row['last_seen']) && is_numeric($row['last_seen'])) {
                $at = date('Y-m-d H:i:s', (int) $row['last_seen']);
            }
            if ($at === '' || $at < $floor) {
                continue;
            }
            $company = jeab_restore_company($row['company'] ?? '');
            $action = (string) ($row['action'] ?? '');
            $entity = strtolower(trim((string) ($row['entity'] ?? $row['type'] ?? '')));
            if (isset($row['reminder_id']) && (int) $row['reminder_id'] > 0 && !str_starts_with($action, 'ordrar.')) {
                $out[] = ['kind' => 'reminder', 'id' => (string) (int) $row['reminder_id'], 'company' => $company];
            }
            if ((isset($row['order_id']) && (int) $row['order_id'] > 0) || (str_starts_with($action, 'ordrar.') && (int) ($row['id'] ?? 0) > 0)) {
                $id = (int) ($row['order_id'] ?? $row['id'] ?? 0);
                if ($id > 0) {
                    $out[] = ['kind' => 'order', 'id' => (string) $id, 'company' => $company];
                }
            }
            $thread = jeab_restore_thread_key($row['thread_id'] ?? $row['thread'] ?? '');
            if ($thread === '' && ($entity === 'thread' || str_starts_with($action, 'inbox.') || str_starts_with($action, 'inkorg.'))) {
                $thread = jeab_restore_thread_key($row['id'] ?? '');
            }
            if ($thread !== '') {
                $out[] = ['kind' => 'thread', 'id' => $thread, 'company' => $company];
            }
            if ($entity === 'reminder' && (int) ($row['id'] ?? 0) > 0) {
                $out[] = ['kind' => 'reminder', 'id' => (string) (int) $row['id'], 'company' => $company];
            }
        }
    }

    return $out;
}

/** @return array<int, true> */
function jeab_restore_live_reminders(JeabStore $store): array
{
    $out = [];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
            $out[(int) $row['id']] = true;
        }
    }

    return $out;
}

/** @return array<string, true> */
function jeab_restore_live_threads(PDO $pdo): array
{
    $out = [];
    if (function_exists('jeab_inkorg_threads_meta')) {
        try {
            foreach (jeab_inkorg_threads_meta() as $id => $_row) {
                $key = jeab_restore_thread_key($id);
                if ($key !== '') {
                    $out[$key] = true;
                }
            }
        } catch (Throwable) {
        }
    }
    try {
        foreach ($pdo->query('SELECT id FROM inkorg_threads') ?: [] as $row) {
            $key = jeab_restore_thread_key(is_array($row) ? ($row['id'] ?? '') : '');
            if ($key !== '') {
                $out[$key] = true;
            }
        }
    } catch (Throwable) {
    }

    return $out;
}

/**
 * @return array<string, array{orders:list<array<string, mixed>>,reminders:list<array<string, mixed>>,threads:list<array<string, mixed>>}>
 */
function jeab_restore_company_report(JeabStore $store, string $since, string $coFilter): array
{
    $out = [
        'jarfalla' => ['orders' => [], 'reminders' => [], 'threads' => []],
        'malmo' => ['orders' => [], 'reminders' => [], 'threads' => []],
    ];
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    $liveOrders = function_exists('jeab_purchase_live_ids') ? jeab_purchase_live_ids() : [];
    $liveReminders = jeab_restore_live_reminders($store);
    $liveThreads = $pdo instanceof PDO ? jeab_restore_live_threads($pdo) : [];
    $bag = jeab_restore_collect($since);
    $push = static function (string $company, string $slot, array $item) use (&$out, $coFilter): void {
        if ($company !== 'jarfalla' && $company !== 'malmo') {
            return;
        }
        if ($coFilter !== '' && $company !== $coFilter) {
            return;
        }
        $out[$company][$slot][] = $item;
    };
    foreach ($bag['order'] as $id => $hit) {
        $id = (int) $id;
        if ($id <= 0 || isset($liveOrders[$id])) {
            continue;
        }
        $company = (string) $hit['company'];
        if ($company === '') {
            $company = jeab_restore_company_from_reminder($store, $id);
        }
        $push($company, 'orders', [
            'id' => $id,
            'reason' => jeab_restore_reason_text($hit['reasons']),
        ]);
    }
    foreach ($bag['reminder'] as $id => $hit) {
        $id = (int) $id;
        if ($id <= 0 || isset($liveReminders[$id])) {
            continue;
        }
        $item = [
            'id' => $id,
            'reason' => jeab_restore_reason_text($hit['reasons']),
        ];
        if (jeab_restore_backup_only($hit)) {
            $source = jeab_restore_backup_public(is_array($hit['backup_row'] ?? null) ? $hit['backup_row'] : (is_array($hit['row'] ?? null) ? $hit['row'] : []));
            $item['file'] = (string) ($hit['file'] ?? '');
            $item['status'] = $source['status'];
            $item['contact_id'] = $source['contact_id'];
            $item['order_id'] = $source['order_id'];
        }
        $push((string) $hit['company'], 'reminders', $item);
    }
    foreach ($bag['thread'] as $id => $hit) {
        $id = (string) $id;
        if ($id === '' || isset($liveThreads[$id])) {
            continue;
        }
        $push((string) $hit['company'], 'threads', [
            'id' => $id,
            'reason' => jeab_restore_reason_text($hit['reasons']),
        ]);
    }
    foreach ($out as $company => $slots) {
        foreach (['orders', 'reminders'] as $slot) {
            usort($out[$company][$slot], static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        }
        usort($out[$company]['threads'], static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
    }

    return $out;
}

/** @return array{ids?:list<string>,error?:string} */
function jeab_restore_parse_thread_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $split = preg_split('/\s*,\s*/', trim($raw));
        $raw = is_array($split) ? $split : [];
    }
    if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
        return ['error' => 'ids ska vara en lista.'];
    }
    if (count($raw) > 200) {
        return ['error' => 'Högst 200 id.'];
    }
    $ids = [];
    foreach ($raw as $value) {
        $id = jeab_restore_thread_key($value);
        if ($id === '' || isset($ids[$id])) {
            return ['error' => 'Ogiltigt id.'];
        }
        $ids[$id] = $id;
    }

    return ['ids' => array_values($ids)];
}

function jeab_restore_entity_pdo(JeabStore $store): ?PDO
{
    $pdo = function_exists('jeab_purchase_sql_live') ? jeab_purchase_sql_live() : null;
    if (!$pdo instanceof PDO && $store instanceof JeabPdoStore) {
        $pdo = $store->pdo();
    }

    return $pdo instanceof PDO ? $pdo : null;
}

/** @param array<string, mixed> $log @return array{0:string,1:int|string} */
function jeab_restore_undo_classify(JeabStore $store, array $log): array
{
    $entity = (string) ($log['entity'] ?? '');
    if ($entity === 'reminder') {
        $id = (int) ($log['reminder_id'] ?? 0);
        $live = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : null;
        if (!is_array($live)) {
            return ['gone', $id];
        }
        $same = trim((string) ($live['contact_id'] ?? '')) === trim((string) ($log['contact_id'] ?? ''))
            && trim((string) ($live['created_at'] ?? '')) === trim((string) ($log['order_created_at'] ?? ''));

        return [$same ? 'would' : 'kept', $id];
    }
    $key = (string) ($log['entity_key'] ?? '');
    $live = jeab_restore_thread_get(jeab_restore_entity_pdo($store), $key);
    if (!is_array($live)) {
        return ['gone', $key];
    }
    $same = trim((string) ($live['status'] ?? '')) === trim((string) ($log['status'] ?? ''))
        && trim((string) ($live['contact_id'] ?? '')) === trim((string) ($log['contact_id'] ?? ''))
        && trim((string) ($live['created_at'] ?? '')) === trim((string) ($log['order_created_at'] ?? ''));

    return [$same ? 'would' : 'kept', $key];
}

function jeab_restore_undo_apply(JeabStore $store, PDO $pdo, string $undoId, string $token): bool
{
    $stmt = $pdo->prepare('SELECT reminder_id, contact_id, order_created_at, status, entity, entity_key FROM order_restore_log WHERE undo_id = ? AND undone = 0');
    $stmt->execute([$undoId]);
    foreach ($stmt->fetchAll() as $log) {
        if (!is_array($log)) {
            continue;
        }
        $entity = (string) ($log['entity'] ?? '');
        $id = $entity === 'thread' ? (string) ($log['entity_key'] ?? '') : (string) (int) ($log['reminder_id'] ?? 0);
        if ($id !== $token) {
            continue;
        }
        [$bucket] = jeab_restore_undo_classify($store, $log);
        if ($bucket !== 'would') {
            return false;
        }
        if ($entity === 'reminder') {
            $GLOBALS['_JEAB_REMINDER_DELETE_LOCK'] = 1;
            try {
                jeab_reminders_sql_delete($pdo, [(int) $id]);
            } finally {
                unset($GLOBALS['_JEAB_REMINDER_DELETE_LOCK']);
            }
            $pdo->prepare('UPDATE order_restore_log SET undone = 1 WHERE undo_id = ? AND reminder_id = ? AND entity = ?')->execute([$undoId, (int) $id, 'reminder']);

            return true;
        }
        $GLOBALS['_JEAB_THREAD_DELETE_LOCK'] = 1;
        try {
            jeab_thread_sql_delete($pdo, $id);
        } finally {
            unset($GLOBALS['_JEAB_THREAD_DELETE_LOCK']);
        }
        $pdo->prepare('UPDATE order_restore_log SET undone = 1 WHERE undo_id = ? AND entity_key = ? AND entity = ?')->execute([$undoId, $id, 'thread']);

        return true;
    }

    return false;
}

/** @return array<string, mixed>|null */
function jeab_restore_thread_get(?PDO $pdo, string $id): ?array
{
    $id = jeab_restore_thread_key($id);
    if ($id === '' || !$pdo instanceof PDO) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT row_json, status FROM inkorg_threads WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
    $out = is_array($decoded) ? $decoded : [];
    $out['status'] = (string) ($out['status'] ?? $row['status'] ?? '');

    return $out;
}

function jeab_restore_threads_table(PDO $pdo): void
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_threads (
                id VARCHAR(191) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT \'open\',
                updated_at DATETIME DEFAULT NULL,
                row_json LONGTEXT NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inkorg_threads (
            id TEXT PRIMARY KEY,
            status TEXT NOT NULL DEFAULT \'open\',
            updated_at TEXT,
            row_json TEXT NOT NULL
        )'
    );
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_restore_entity(JeabStore $store, array $input, string $kind): array
{
    $dry = function_exists('jeab_ordrar_restore_dry') ? jeab_ordrar_restore_dry($input) : true;
    $parsed = $kind === 'thread'
        ? jeab_restore_parse_thread_ids($input['ids'] ?? null)
        : (function_exists('jeab_ordrar_restore_parse_ids') ? jeab_ordrar_restore_parse_ids($input['ids'] ?? null) : ['error' => 'ids saknas.']);
    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => $parsed['error'], 'status' => 422, 'sent' => 0, 'dry' => $dry ? 1 : 0];
    }
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'restore') {
        return ['ok' => false, 'error' => 'confirm:restore krävs.', 'status' => 400, 'sent' => 0, 'dry' => 0];
    }
    $company = array_key_exists('co', $input) ? jeab_restore_company($input['co']) : '';
    $since = '';
    $bag = jeab_restore_collect($since);
    $slice = $bag[$kind] ?? [];
    $pdo = jeab_restore_entity_pdo($store);
    $liveReminders = jeab_restore_live_reminders($store);
    $liveThreads = $pdo instanceof PDO ? jeab_restore_live_threads($pdo) : [];
    $would = [];
    $skipped = [];
    $plans = [];
    foreach ($parsed['ids'] ?? [] as $rawId) {
        $id = $kind === 'thread' ? (string) $rawId : (string) (int) $rawId;
        $hit = $slice[$id] ?? null;
        $backupRow = is_array($hit) && is_array($hit['backup_row'] ?? null) ? $hit['backup_row'] : [];
        $fromBackup = $kind === 'reminder' && is_array($hit) && $backupRow !== [] && !empty($hit['reasons']['backup']);
        if (!is_array($hit) || (empty($hit['reasons']['snapshot']) && !$fromBackup)) {
            $skipped[] = ['id' => $kind === 'thread' ? $id : (int) $id, 'reason' => 'not_in_snapshot'];
            continue;
        }
        if (empty($hit['reasons']['snapshot'])) {
            $hit['row'] = $backupRow;
            if ((string) ($hit['company'] ?? '') === '') {
                $hit['company'] = jeab_restore_company($backupRow['company'] ?? '');
            }
        }
        $rowCompany = (string) ($hit['company'] ?? '');
        if ($company !== '' && $rowCompany !== '' && $rowCompany !== $company) {
            $skipped[] = ['id' => $kind === 'thread' ? $id : (int) $id, 'reason' => 'company'];
            continue;
        }
        $exists = $kind === 'thread' ? isset($liveThreads[$id]) : isset($liveReminders[(int) $id]);
        if ($exists) {
            $skipped[] = ['id' => $kind === 'thread' ? $id : (int) $id, 'reason' => 'exists'];
            continue;
        }
        $built = $kind === 'thread'
            ? jeab_restore_thread_plan($id, $hit)
            : jeab_restore_reminder_plan($pdo, (int) $id, $hit);
        if ($built === null) {
            $skipped[] = ['id' => $kind === 'thread' ? $id : (int) $id, 'reason' => 'not_in_snapshot'];
            continue;
        }
        $plans[] = $built;
        $would[] = [
            'id' => $kind === 'thread' ? $id : (int) $id,
            'address' => (string) ($built['address'] ?? ''),
            'status' => (string) ($built['status'] ?? ''),
            'dates' => [
                'created_at' => (string) ($built['created_at'] ?? ''),
                'updated_at' => (string) ($built['updated_at'] ?? ''),
            ],
        ];
    }
    $base = [
        'ok' => true,
        'action' => 'ordrar.restore_from_snapshot',
        'type' => $kind,
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'sent' => 0,
        'mail' => 0,
        'sms' => 0,
        'fortnox' => 0,
        'calendar' => 0,
        'would_restore' => $would,
        'skipped' => $skipped,
        'undo_id' => null,
        'status' => 200,
    ];
    if ($dry || $plans === []) {
        return function_exists('jeab_ordrar_restore_public') ? jeab_ordrar_restore_public($base) : $base;
    }
    if (!$pdo instanceof PDO || !function_exists('jeab_pdo_transaction')) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400, 'sent' => 0, 'dry' => 0];
    }
    $undoId = bin2hex(random_bytes(16));
    try {
        if (function_exists('jeab_ordrar_restore_ensure_log')) {
            jeab_ordrar_restore_ensure_log($pdo);
        }
        if ($kind === 'thread') {
            jeab_restore_threads_table($pdo);
        }
        if ($kind === 'reminder') {
            if (!function_exists('jeab_reminders_restore_ensure')) {
                require_once __DIR__ . '/reminders_restore.php';
            }
            jeab_reminders_restore_ensure($pdo);
        }
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($plans, $undoId, $kind): void {
            $seal = jeab_restore_seal_read($pdo);
            $insert = $pdo->prepare(
                'INSERT INTO order_restore_log (undo_id, reminder_id, contact_id, kind, order_created_at, step, status, booking_id, undone, entity, entity_key) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)'
            );
            foreach ($plans as $plan) {
                if ($kind === 'reminder') {
                    jeab_restore_reminder_insert($pdo, $plan);
                } else {
                    jeab_restore_thread_insert($pdo, $plan);
                }
                $insert->execute([
                    $undoId,
                    (int) ($plan['reminder_id'] ?? $plan['id'] ?? 0),
                    mb_substr((string) ($plan['contact_id'] ?? ''), 0, 64),
                    '',
                    (string) ($plan['created_at'] ?? ''),
                    0,
                    mb_substr((string) ($plan['status'] ?? ''), 0, 16),
                    $kind,
                    $kind === 'thread' ? (string) ($plan['id'] ?? '') : '',
                ]);
            }
            if (jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Återställningen gick inte att spara.', 'status' => 500, 'sent' => 0, 'dry' => 0];
    }
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['undo_id'] = $undoId;

    return function_exists('jeab_ordrar_restore_public') ? jeab_ordrar_restore_public($base) : $base;
}

/**
 * @param array{reasons:array<string, true>, company:string, row:array<string, mixed>} $hit
 * @return array<string, mixed>|null
 */
function jeab_restore_reminder_plan(?PDO $pdo, int $id, array $hit): ?array
{
    if (!$pdo instanceof PDO || $id <= 0) {
        return null;
    }
    $row = is_array($hit['row'] ?? null) ? $hit['row'] : [];
    $row['id'] = $id;
    if (!function_exists('jeab_reminders_restore_from_backup_row')) {
        require_once __DIR__ . '/reminders_restore.php';
    }
    $fields = jeab_reminders_restore_from_backup_row($pdo, $row, '');
    $fields['id'] = $id;
    $contact = trim((string) ($row['contact_id'] ?? ''));
    if ($contact !== '') {
        $fields['contact_id'] = $contact;
    }
    $company = jeab_restore_company($row['company'] ?? ($hit['company'] ?? ''));
    if ($company !== '') {
        $fields['company'] = $company;
    }
    foreach (['customer_name', 'email', 'phone', 'note', 'personnummer', 'pnr'] as $drop) {
        if (array_key_exists($drop, $fields)) {
            $fields[$drop] = null;
        }
    }
    $address = function_exists('jeab_contacts_sole_snapshot_address_text')
        ? jeab_contacts_sole_snapshot_address_text((string) ($row['address'] ?? $row['title'] ?? ''))
        : trim((string) ($row['address'] ?? ''));
    if ($address !== '') {
        $fields['address'] = $address;
        $fields['title'] = $address;
    }
    $status = (string) ($row['status'] ?? '');
    if ($status === 'done' && empty($fields['completed_at'])) {
        $fields['completed_at'] = (string) ($fields['updated_at'] ?? $fields['created_at'] ?? '');
    }
    $fields['status'] = $fields['completed_at'] ? 'done' : ($status !== '' ? $status : 'open');

    return $fields;
}

/** @param array<string, mixed> $fields */
function jeab_restore_reminder_insert(PDO $pdo, array $fields): void
{
    $id = (int) ($fields['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id FROM reminders WHERE id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() === $id) {
        throw new RuntimeException('exists');
    }
    if (!function_exists('jeab_reminders_restore_insert')) {
        require_once __DIR__ . '/reminders_restore.php';
    }
    jeab_reminders_restore_ensure($pdo);
    $address = (string) ($fields['address'] ?? '');
    unset($fields['status']);
    jeab_reminders_restore_insert($pdo, $fields);
    $fields['address'] = $address;
}

/**
 * @param array{reasons:array<string, true>, company:string, row:array<string, mixed>} $hit
 * @return array<string, mixed>
 */
function jeab_restore_thread_plan(string $id, array $hit): array
{
    $row = is_array($hit['row'] ?? null) ? $hit['row'] : [];
    $created = trim((string) ($row['created_at'] ?? ''));
    if ($created === '') {
        $created = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    }
    $updated = trim((string) ($row['updated_at'] ?? ''));
    if ($updated === '') {
        $updated = $created;
    }
    $keep = [
        'status' => trim((string) ($row['status'] ?? 'open')) ?: 'open',
        'contact_id' => trim((string) ($row['contact_id'] ?? '')),
        'company' => jeab_restore_company($row['company'] ?? ($hit['company'] ?? '')),
        'created_at' => $created,
        'updated_at' => $updated,
    ];
    if (isset($row['tags']) && is_array($row['tags'])) {
        $tags = [];
        foreach ($row['tags'] as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && !str_contains($tag, '@') && strlen($tag) <= 40) {
                $tags[] = $tag;
            }
        }
        $keep['tags'] = $tags;
    }

    return [
        'id' => $id,
        'status' => $keep['status'],
        'contact_id' => $keep['contact_id'],
        'company' => $keep['company'],
        'created_at' => $created,
        'updated_at' => $updated,
        'address' => '',
        'row' => $keep,
    ];
}

/** @param array<string, mixed> $plan */
function jeab_restore_thread_insert(PDO $pdo, array $plan): void
{
    $id = jeab_restore_thread_key($plan['id'] ?? '');
    if ($id === '') {
        throw new RuntimeException('Ogiltigt id.');
    }
    $stmt = $pdo->prepare('SELECT id FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$id]);
    if ((string) $stmt->fetchColumn() === $id) {
        throw new RuntimeException('exists');
    }
    $row = is_array($plan['row'] ?? null) ? $plan['row'] : [];
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Återställningen gick inte att spara.');
    }
    $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')
        ->execute([$id, (string) ($plan['status'] ?? 'open'), (string) ($plan['updated_at'] ?? ''), $json]);
}
