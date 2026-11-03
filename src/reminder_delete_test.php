<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * reminder.delete_test. Samma Bearer som reminders.get.
 *
 * id är påminnelsens id. contact_id är valfritt och måste vara påminnelsens
 * egen kontakt. dry saknas eller är 1: inget raderas, svaret visar vad som
 * skulle tas bort. dry=0 tar bara bort påminnelsen när rubriken börjar med
 * TEST, efter en JSON-kopia i data/.private/backups. Kontakten tas bort
 * bara när ingen annan påminnelse, order, bokning eller inkorgsärende
 * pekar på den. Annars ligger kontakten kvar och referenserna rapporteras.
 * Inget annat raderas.
 *
 * POST hook.php
 * {"action":"reminder.delete_test","id":123,"contact_id":"kt_…","dry":0}
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_delete_test_dispatch(JeabStore $store, array $input = []): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'deleted' => false];
    }
    $dry = jeab_reminder_delete_test_is_dry($input);
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        return [
            'ok' => false,
            'error' => 'id krävs.',
            'status' => 422,
            'dry' => $dry ? 1 : 0,
            'deleted' => false,
        ];
    }
    $row = $store->getReminder($id);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Påminnelsen finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    $title = trim((string) ($row['title'] ?? ''));
    if (!str_starts_with($title, 'TEST')) {
        return [
            'ok' => false,
            'error' => 'Rubriken börjar inte med TEST.',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    $storedContact = trim((string) ($row['contact_id'] ?? ''));
    $asked = array_key_exists('contact_id', $input);
    if ($asked && $input['contact_id'] !== null && !is_scalar($input['contact_id'])) {
        return [
            'ok' => false,
            'error' => 'contact_id krävs som text.',
            'status' => 422,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    $given = $asked ? trim((string) $input['contact_id']) : '';
    if ($given !== '' && $given !== $storedContact) {
        return [
            'ok' => false,
            'error' => 'contact_id matchar inte påminnelsen.',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    $contactId = $given !== '' ? $storedContact : '';
    $plan = jeab_reminder_delete_test_plan($store, $id, $contactId);
    $body = [
        'ok' => true,
        'action' => 'reminder.delete_test',
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'title' => $title,
        'deleted' => false,
        'would' => [
            'reminder' => $id,
            'contact' => $plan['delete_contact'] ? $contactId : null,
        ],
        'contact' => $plan['contact'],
        'backup' => null,
    ];
    if ($dry) {
        $body['status'] = 200;

        return $body;
    }
    try {
        $backup = jeab_reminder_delete_test_backup($row, $plan['contact_row']);
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 500,
            'dry' => 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    $body['backup'] = $backup['rel'];
    $removed = $store->deleteReminders([$id]);
    if ($removed < 1 || $store->getReminder($id) !== null) {
        return [
            'ok' => false,
            'error' => 'Påminnelsen kunde inte tas bort.',
            'status' => 400,
            'dry' => 0,
            'id' => $id,
            'deleted' => false,
            'backup' => $backup['rel'],
        ];
    }
    $body['deleted'] = ['reminder' => $id, 'contact' => null];
    if (!$plan['delete_contact']) {
        $body['status'] = 200;

        return $body;
    }
    try {
        jeab_kontakt_delete($contactId);
    } catch (Throwable $e) {
        $body['ok'] = false;
        $body['error'] = 'Kontakten kunde inte tas bort.';
        $body['status'] = 400;
        $body['contact']['deleted'] = false;
        $body['contact']['reason'] = 'delete_failed';
        unset($e);

        return $body;
    }
    if (jeab_kontakt_get($contactId) !== null) {
        $body['ok'] = false;
        $body['error'] = 'Kontakten kunde inte tas bort.';
        $body['status'] = 400;
        $body['contact']['deleted'] = false;
        $body['contact']['reason'] = 'delete_failed';

        return $body;
    }
    $body['deleted']['contact'] = $contactId;
    $body['would']['contact'] = $contactId;
    $body['contact']['deleted'] = true;
    $body['contact']['kept'] = false;
    $body['contact']['reason'] = 'deleted';
    $body['status'] = 200;

    return $body;
}

function jeab_reminder_delete_test_is_dry(array $input): bool
{
    if (function_exists('jeab_auto_send_quote_is_dry')) {
        return jeab_auto_send_quote_is_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];

    return !($dry === false || $dry === 0 || $dry === 0.0 || $dry === '0');
}

/**
 * @return array{delete_contact:bool,contact_row:?array<string,mixed>,contact:array<string,mixed>}
 */
function jeab_reminder_delete_test_plan(JeabStore $store, int $reminderId, string $contactId): array
{
    if ($contactId === '') {
        return [
            'delete_contact' => false,
            'contact_row' => null,
            'contact' => [
                'id' => null,
                'deleted' => false,
                'kept' => false,
                'reason' => 'not_requested',
                'references' => jeab_reminder_delete_test_empty_refs(),
            ],
        ];
    }
    $refs = jeab_reminder_delete_test_references($store, $contactId, $reminderId);
    $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
    $contact = is_array($contact) ? $contact : null;
    if ($contact === null) {
        return [
            'delete_contact' => false,
            'contact_row' => null,
            'contact' => [
                'id' => $contactId,
                'deleted' => false,
                'kept' => false,
                'reason' => 'missing',
                'references' => $refs,
            ],
        ];
    }
    if (jeab_reminder_delete_test_refs_exist($refs)) {
        return [
            'delete_contact' => false,
            'contact_row' => null,
            'contact' => [
                'id' => $contactId,
                'deleted' => false,
                'kept' => true,
                'reason' => 'references',
                'references' => $refs,
            ],
        ];
    }

    return [
        'delete_contact' => true,
        'contact_row' => $contact,
        'contact' => [
            'id' => $contactId,
            'deleted' => false,
            'kept' => false,
            'reason' => 'would_delete',
            'references' => $refs,
        ],
    ];
}

/** @return array{reminders:list<int>,orders:list<int>,bookings:list<int>,inbox:list<string>} */
function jeab_reminder_delete_test_empty_refs(): array
{
    return ['reminders' => [], 'orders' => [], 'bookings' => [], 'inbox' => []];
}

/** @param array{reminders:list<int>,orders:list<int>,bookings:list<int>,inbox:list<string>} $refs */
function jeab_reminder_delete_test_refs_exist(array $refs): bool
{
    return $refs['reminders'] !== [] || $refs['orders'] !== [] || $refs['bookings'] !== [] || $refs['inbox'] !== [];
}

/**
 * Andra påminnelser, ordrar, bokningar och inkorgsärenden som pekar på kontakten.
 * Påminnelsen som ska bort räknas inte.
 *
 * @return array{reminders:list<int>,orders:list<int>,bookings:list<int>,inbox:list<string>}
 */
function jeab_reminder_delete_test_references(JeabStore $store, string $contactId, int $exceptReminderId): array
{
    $refs = jeab_reminder_delete_test_empty_refs();
    $refs['reminders'] = jeab_reminder_delete_test_other_reminders($store, $contactId, $exceptReminderId);
    $refs['orders'] = jeab_reminder_delete_test_orders($contactId);
    $refs['bookings'] = jeab_reminder_delete_test_bookings($store, $contactId);
    $refs['inbox'] = jeab_reminder_delete_test_inbox($contactId);

    return $refs;
}

/** @return list<int> */
function jeab_reminder_delete_test_other_reminders(JeabStore $store, string $contactId, int $exceptReminderId): array
{
    $ids = [];
    if ($store instanceof JeabPdoStore) {
        $stmt = $store->pdo()->prepare('SELECT id FROM reminders WHERE contact_id = ? AND id != ?');
        $stmt->execute([$contactId, $exceptReminderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    } else {
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) === $exceptReminderId) {
                continue;
            }
            if (trim((string) ($row['contact_id'] ?? '')) !== $contactId) {
                continue;
            }
            $n = (int) ($row['id'] ?? 0);
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }
    $out = array_values($ids);
    sort($out, SORT_NUMERIC);

    return $out;
}

/** @return list<int> */
function jeab_reminder_delete_test_orders(string $contactId): array
{
    $ids = [];
    $mode = function_exists('jeab_purchases_storage_mode') ? jeab_purchases_storage_mode() : 'json';
    if ($mode === 'sql') {
        try {
            $pdo = function_exists('jeab_purchase_sql_live') ? jeab_purchase_sql_live() : null;
            if ($pdo instanceof PDO) {
                $stmt = $pdo->prepare('SELECT reminder_id FROM purchases WHERE contact_id = ?');
                $stmt->execute([mb_substr($contactId, 0, 64)]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $n = (int) $id;
                    if ($n > 0) {
                        $ids[$n] = $n;
                    }
                }
            }
        } catch (Throwable) {
            $ids = [];
        }
        if ($ids !== []) {
            $out = array_values($ids);
            sort($out, SORT_NUMERIC);

            return $out;
        }
    }
    if (function_exists('jeab_purchase_all')) {
        try {
            foreach (jeab_purchase_all() as $key => $row) {
                if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== $contactId) {
                    continue;
                }
                $n = (int) ($row['reminder_id'] ?? $key);
                if ($n > 0) {
                    $ids[$n] = $n;
                }
            }
        } catch (Throwable) {
        }
    }
    $out = array_values($ids);
    sort($out, SORT_NUMERIC);

    return $out;
}

/** @return list<int> */
function jeab_reminder_delete_test_bookings(JeabStore $store, string $contactId): array
{
    $ids = [];
    if ($store instanceof JeabPdoStore) {
        $stmt = $store->pdo()->prepare('SELECT id FROM bookings WHERE contact_id = ?');
        $stmt->execute([$contactId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    } else {
        foreach ($store->listAllBookings() as $row) {
            if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== $contactId) {
                continue;
            }
            $n = (int) ($row['id'] ?? 0);
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
    }
    $out = array_values($ids);
    sort($out, SORT_NUMERIC);

    return $out;
}

/** @return list<string> */
function jeab_reminder_delete_test_inbox(string $contactId): array
{
    $ids = [];
    $add = static function (string $id) use (&$ids): void {
        $id = trim($id);
        if ($id !== '') {
            $ids[$id] = $id;
        }
    };
    $sql = function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql();
    if ($sql && function_exists('jeab_inkorg_store_pdo')) {
        try {
            $pdo = jeab_inkorg_store_pdo();
        } catch (Throwable) {
            $pdo = null;
        }
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM inkorg_list WHERE contact_id = ?');
                $stmt->execute([$contactId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $add((string) $id);
                }
            } catch (Throwable) {
            }
            try {
                $expr = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? "JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.contact_id')) = ?"
                    : "json_extract(row_json, '$.contact_id') = ?";
                $stmt = $pdo->prepare('SELECT id FROM inkorg_threads WHERE ' . $expr);
                $stmt->execute([$contactId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    $add((string) $id);
                }
            } catch (Throwable) {
            }
            $out = array_values($ids);
            sort($out, SORT_STRING);

            return $out;
        }
    }
    if (function_exists('jeab_inkorg_threads_meta')) {
        try {
            foreach (jeab_inkorg_threads_meta() as $tid => $meta) {
                if (!is_array($meta) || trim((string) ($meta['contact_id'] ?? '')) !== $contactId) {
                    continue;
                }
                $add((string) $tid);
            }
        } catch (Throwable) {
        }
    }
    $out = array_values($ids);
    sort($out, SORT_STRING);

    return $out;
}

/**
 * @param array<string, mixed> $reminder
 * @param array<string, mixed>|null $contact
 * @return array{rel:string,path:string}
 */
function jeab_reminder_delete_test_backup(array $reminder, ?array $contact): array
{
    $payload = [
        'action' => 'reminder.delete_test',
        'at' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
        'reminder' => $reminder,
    ];
    if (is_array($contact)) {
        $payload['contact'] = $contact;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Ingen kopia kunde sparas. Inget ändrat.');
    }
    $dir = jeab_data_dir() . '/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Ingen kopia kunde sparas. Inget ändrat.');
    }
    $name = 'reminder-delete-test-' . (int) ($reminder['id'] ?? 0) . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    $path = $dir . '/' . $name;
    $written = @file_put_contents($path, $json, LOCK_EX);
    if ($written !== strlen($json) || !is_file($path) || (int) filesize($path) !== strlen($json)) {
        @unlink($path);
        throw new RuntimeException('Ingen kopia kunde sparas. Inget ändrat.');
    }
    @chmod($path, 0600);

    return ['rel' => 'backups/' . $name, 'path' => $path];
}
