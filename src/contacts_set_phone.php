<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * contacts.set_phone sätter telefon på en kontakt.
 * dry=1 är standard. dry=0 skriver ångringsfil chmod 0600 under data/.private/
 * och uppdaterar bara den raden. Inget mejl, inget SMS och ingen kundwebhook.
 * contacts.set_phone_undo läser samma fil och sätter tillbaka telefonen.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return jeab_contacts_set_phone_result([
            'ok' => false,
            'error' => 'Fel token.',
            'status' => 401,
            'action' => jeab_contacts_set_phone_action($input),
        ]);
    }
    $undo = jeab_contacts_set_phone_action($input) === 'contacts.set_phone_undo';
    try {
        $result = $undo ? jeab_contacts_set_phone_undo($input) : jeab_contacts_set_phone($input);
    } catch (InvalidArgumentException $e) {
        return jeab_contacts_set_phone_result([
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 422,
            'action' => jeab_contacts_set_phone_action($input),
            'dry' => function_exists('jeab_kontakt_dry') && !jeab_kontakt_dry($input) ? 0 : 1,
        ]);
    }
    if (!isset($result['status'])) {
        $result['status'] = !empty($result['ok']) ? 200 : 400;
    }
    $result['action'] = jeab_contacts_set_phone_action($input);

    return jeab_contacts_set_phone_result($result);
}

/**
 * @param array<string, mixed> $input
 */
function jeab_contacts_set_phone_action(array $input): string
{
    $action = trim((string) ($input['action'] ?? ''));

    return $action === 'contacts.set_phone_undo' ? 'contacts.set_phone_undo' : 'contacts.set_phone';
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone_result(array $result): array
{
    $result['sent'] = 0;
    $result['webhook'] = 0;

    return $result;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone(array $input): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $base = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'changed' => 0,
        'batch' => '',
        'contact_id' => '',
        'phone_hash' => jeab_contacts_set_phone_hash(''),
        'previous_hash' => jeab_contacts_set_phone_hash(''),
    ];
    if (!function_exists('jeab_kontakter_use_sql') || !jeab_kontakter_use_sql()) {
        return [
            'ok' => false,
            'error' => 'Kontakterna ligger inte i SQL.',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
            'changed' => 0,
        ];
    }
    if (!array_key_exists('contact_id', $input) || (!is_string($input['contact_id']) && !is_int($input['contact_id']))) {
        throw new InvalidArgumentException('Skriv contact_id.');
    }
    $id = trim((string) $input['contact_id']);
    if ($id === '') {
        throw new InvalidArgumentException('Skriv contact_id.');
    }
    if (!array_key_exists('phone', $input) || is_array($input['phone'])) {
        throw new InvalidArgumentException('Skriv telefon.');
    }
    $raw = trim((string) $input['phone']);
    $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($raw) : $raw;
    if ($raw !== '' && $phone === '') {
        throw new InvalidArgumentException('Ogiltigt telefonnummer.');
    }
    $row = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($id) : null;
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Kontakten finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
            'changed' => 0,
            'contact_id' => $id,
        ];
    }
    $row['id'] = $id;
    $previous = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($row) : '';
    $before = jeab_contacts_set_phone_capture($row);
    $next = jeab_contacts_set_phone_apply($row, $phone);
    $after = jeab_contacts_set_phone_capture($next);
    $changed = $before !== $after;
    $base['contact_id'] = $id;
    $base['changed'] = $changed ? 1 : 0;
    $base['phone_hash'] = jeab_contacts_set_phone_hash($phone);
    $base['previous_hash'] = jeab_contacts_set_phone_hash($previous);
    if ($dry || !$changed) {
        return $base;
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if (!$store instanceof JeabPdoContactStore) {
        return [
            'ok' => false,
            'error' => 'Kontakterna ligger inte i SQL.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'changed' => 1,
            'contact_id' => $id,
        ];
    }
    $batch = bin2hex(random_bytes(16));
    $payload = [
        'v' => 1,
        'contact_id' => $id,
        'before' => $before,
    ];
    try {
        jeab_contacts_set_phone_write_undo($batch, $payload);
        $store->upsert($id, $next);
    } catch (Throwable) {
        jeab_contacts_set_phone_drop_undo($batch);

        return [
            'ok' => false,
            'error' => 'Kontakten kunde inte skrivas.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'changed' => 1,
            'contact_id' => $id,
            'batch' => '',
        ];
    }
    $base['written'] = 1;
    $base['batch'] = $batch;

    return $base;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone_undo(array $input): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $batch = trim((string) ($input['batch'] ?? ''));
    if (preg_match('/^[a-f0-9]{32}$/', $batch) !== 1) {
        throw new InvalidArgumentException('Ogiltig batch.');
    }
    if (!function_exists('jeab_kontakter_use_sql') || !jeab_kontakter_use_sql()) {
        return [
            'ok' => false,
            'error' => 'Kontakterna ligger inte i SQL.',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
            'batch' => $batch,
        ];
    }
    $saved = jeab_contacts_set_phone_read_undo($batch);
    if ($saved === null) {
        return [
            'ok' => false,
            'error' => 'Ångringen finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
            'batch' => $batch,
        ];
    }
    $id = trim((string) ($saved['contact_id'] ?? ''));
    $before = is_array($saved['before'] ?? null) ? $saved['before'] : [];
    $row = $id !== '' && function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($id) : null;
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Kontakten finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
            'batch' => $batch,
            'contact_id' => $id,
        ];
    }
    $row['id'] = $id;
    $next = jeab_contacts_set_phone_restore($row, $before);
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($next) : '';
    $base = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'batch' => $batch,
        'contact_id' => $id,
        'phone_hash' => jeab_contacts_set_phone_hash($phone),
    ];
    if ($dry) {
        return $base;
    }
    if (function_exists('jeab_data_write_guard')) {
        jeab_data_write_guard();
    }
    $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if (!$store instanceof JeabPdoContactStore) {
        return [
            'ok' => false,
            'error' => 'Kontakterna ligger inte i SQL.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'batch' => $batch,
            'contact_id' => $id,
        ];
    }
    try {
        $store->upsert($id, $next);
    } catch (Throwable) {
        return [
            'ok' => false,
            'error' => 'Kontakten kunde inte skrivas.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'batch' => $batch,
            'contact_id' => $id,
        ];
    }
    jeab_contacts_set_phone_drop_undo($batch);
    $base['written'] = 1;

    return $base;
}

function jeab_contacts_set_phone_hash(string $phone): string
{
    return hash('sha256', $phone);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone_capture(array $row): array
{
    $owners = [];
    foreach (array_values(is_array($row['owners'] ?? null) ? $row['owners'] : []) as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $one = [
            'i' => $i,
            'phone' => (string) ($owner['phone'] ?? ''),
        ];
        if (array_key_exists('mobile', $owner)) {
            $one['mobile'] = (string) $owner['mobile'];
        }
        $owners[] = $one;
    }
    $out = [
        'row_phone' => (string) ($row['phone'] ?? ''),
        'owners' => $owners,
    ];
    if (array_key_exists('mobile', $row)) {
        $out['row_mobile'] = (string) $row['mobile'];
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone_apply(array $row, string $phone): array
{
    $owners = array_values(is_array($row['owners'] ?? null) ? $row['owners'] : []);
    $idx = null;
    foreach ($owners as $i => $owner) {
        if (is_array($owner) && !empty($owner['contact'])) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null && $owners !== []) {
        $idx = 0;
    }
    if ($idx !== null && is_array($owners[$idx])) {
        $owners[$idx]['phone'] = $phone;
        if (array_key_exists('mobile', $owners[$idx])) {
            $owners[$idx]['mobile'] = $phone;
        }
        $row['owners'] = $owners;
    }
    $row['phone'] = $phone;
    if (array_key_exists('mobile', $row)) {
        $row['mobile'] = $phone;
    }
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

    return $row;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $saved
 * @return array<string, mixed>
 */
function jeab_contacts_set_phone_restore(array $row, array $saved): array
{
    if (array_key_exists('row_phone', $saved)) {
        $row['phone'] = (string) $saved['row_phone'];
    }
    if (array_key_exists('row_mobile', $saved)) {
        $row['mobile'] = (string) $saved['row_mobile'];
    }
    $owners = array_values(is_array($row['owners'] ?? null) ? $row['owners'] : []);
    foreach (is_array($saved['owners'] ?? null) ? $saved['owners'] : [] as $savedOwner) {
        if (!is_array($savedOwner)) {
            continue;
        }
        $i = (int) ($savedOwner['i'] ?? -1);
        if (!isset($owners[$i]) || !is_array($owners[$i])) {
            continue;
        }
        $owners[$i]['phone'] = (string) ($savedOwner['phone'] ?? '');
        if (array_key_exists('mobile', $savedOwner)) {
            $owners[$i]['mobile'] = (string) $savedOwner['mobile'];
        }
    }
    $row['owners'] = $owners;
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

    return $row;
}

function jeab_contacts_set_phone_dir(): string
{
    return jeab_data_dir() . '/contacts-set-phone';
}

function jeab_contacts_set_phone_path(string $batch): string
{
    if (preg_match('/^[a-f0-9]{32}$/', $batch) !== 1) {
        throw new InvalidArgumentException('Ogiltig batch.');
    }

    return jeab_contacts_set_phone_dir() . '/' . $batch . '.json';
}

/**
 * @param array<string, mixed> $payload
 */
function jeab_contacts_set_phone_write_undo(string $batch, array $payload): void
{
    $dir = jeab_contacts_set_phone_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara ångringen.');
    }
    @chmod($dir, 0700);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Kunde inte spara ångringen.');
    }
    $path = jeab_contacts_set_phone_path($batch);
    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara ångringen.');
    }
    @chmod($path, 0600);
}

/** @return array<string, mixed>|null */
function jeab_contacts_set_phone_read_undo(string $batch): ?array
{
    $path = jeab_contacts_set_phone_path($batch);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : null;
}

function jeab_contacts_set_phone_drop_undo(string $batch): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $batch) !== 1) {
        return;
    }
    $path = jeab_contacts_set_phone_dir() . '/' . $batch . '.json';
    if (is_file($path)) {
        @unlink($path);
    }
}
