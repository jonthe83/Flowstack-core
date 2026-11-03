<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * reminder.set_owner, reminder.set_address, reminder.set_email och reminder.create_manual.
 * dry=1 är standard och skriver inget. dry=0 skriver påminnelsen och,
 * för adress, den kopplade kontakten. create_manual skapar påminnelse och
 * kontakt samma väg som ett lead, utan offert, bekräftelse eller sms.
 * Gamla värden ligger i reminder_admin_audit (skalära kolumner) så raden kan ångras.
 */

function jeab_reminder_admin_wants_dry(array $input): bool
{
    if (function_exists('jeab_ordrar_backfill_wants_dry')) {
        return jeab_ordrar_backfill_wants_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];

    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_reminder_admin_audit_pdo(): ?PDO
{
    if (!function_exists('jeab_inbox_contact_set_email_audit_pdo')) {
        return null;
    }
    $pdo = jeab_inbox_contact_set_email_audit_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_ensure_reminder_admin_audit')) {
        return null;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    jeab_ensure_reminder_admin_audit($pdo, $driver === 'mysql' ? 'mysql' : 'sqlite');

    return $pdo;
}

/**
 * En träff i personalistan. Tomt, okänt eller tvetydigt namn ger ingen rad.
 *
 * @return array{ok:bool, error?:string, person?:array<string, mixed>}
 */
function jeab_reminder_admin_staff(string $given): array
{
    $given = trim($given);
    if ($given === '') {
        return ['ok' => false, 'error' => 'ange ägare'];
    }
    $hits = [];
    foreach (jeab_people() as $row) {
        if (jeab_person_alias_matches($row, $given)) {
            $hits[(string) $row['email']] = $row;
        }
    }
    if ($hits === []) {
        return ['ok' => false, 'error' => 'ägaren finns inte i personalistan'];
    }
    if (count($hits) !== 1) {
        return ['ok' => false, 'error' => 'ägaren är inte entydig i personalistan'];
    }

    return ['ok' => true, 'person' => array_values($hits)[0]];
}

function jeab_reminder_admin_owner_label(array $row): string
{
    $raw = trim((string) ($row['person'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $match = jeab_reminder_admin_staff($raw);

    return !empty($match['ok']) && is_array($match['person'] ?? null)
        ? trim((string) ($match['person']['name'] ?? ''))
        : $raw;
}

/**
 * @param array<string, mixed> $row
 * @return array{street:string, zip:string, city:string}
 */
function jeab_reminder_admin_place(array $row): array
{
    $parts = function_exists('jeab_address_parts')
        ? jeab_address_parts((string) ($row['address'] ?? ''))
        : ['street' => trim((string) ($row['address'] ?? '')), 'postal' => '', 'city' => ''];
    $street = trim((string) ($parts['street'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($row['address'] ?? ''));
    }
    $zip = trim((string) ($row['postal'] ?? ''));
    if ($zip === '') {
        $zip = trim((string) ($parts['postal'] ?? ''));
    }
    $city = trim((string) ($row['ort'] ?? ''));
    if ($city === '') {
        $city = trim((string) ($parts['city'] ?? ''));
    }
    if ($zip !== '' && function_exists('jeab_kontakt_postal')) {
        $norm = jeab_kontakt_postal($zip);
        if ($norm !== '') {
            $zip = $norm;
        }
    }
    if ($city !== '' && function_exists('jeab_address_town')) {
        $city = jeab_address_town($city);
    }

    return ['street' => $street, 'zip' => $zip, 'city' => $city];
}

/**
 * @param array<string, mixed> $contact
 * @return array{street:string, zip:string, city:string}
 */
function jeab_reminder_admin_contact_place(array $contact): array
{
    $street = trim((string) ($contact['street'] ?? ''));
    $zip = trim((string) ($contact['postal_code'] ?? ''));
    if ($zip !== '' && function_exists('jeab_kontakt_postal')) {
        $norm = jeab_kontakt_postal($zip);
        if ($norm !== '') {
            $zip = $norm;
        }
    }
    $city = trim((string) ($contact['city'] ?? ''));
    if ($city !== '' && function_exists('jeab_address_town')) {
        $city = jeab_address_town($city);
    }

    return ['street' => $street, 'zip' => $zip, 'city' => $city];
}

/** @param array{street:string, zip:string, city:string} $place */
function jeab_reminder_admin_place_text(array $place): string
{
    $street = $place['street'];
    $tail = trim($place['zip'] . ' ' . $place['city']);
    if ($street === '') {
        return $tail;
    }
    if ($tail === '') {
        return $street;
    }

    return $street . ', ' . $tail;
}

/** @param array{street:string, zip:string, city:string} $a @param array{street:string, zip:string, city:string} $b */
function jeab_reminder_admin_place_same(array $a, array $b): bool
{
    return $a['street'] === $b['street'] && $a['zip'] === $b['zip'] && $a['city'] === $b['city'];
}

function jeab_reminder_admin_note(array $row, string $line): string
{
    $text = function_exists('jeab_reminder_notes_text')
        ? jeab_reminder_notes_text($row)
        : trim((string) ($row['note'] ?? ''));
    $text = trim($text);

    return $text === '' ? $line : $text . "\n" . $line;
}

/**
 * @param array<string, string> $fields
 */
function jeab_reminder_admin_audit(int $id, string $action, array $fields): bool
{
    if ($id <= 0 || !in_array($action, ['set_owner', 'set_address', 'set_email', 'create_manual'], true)) {
        return false;
    }
    $pdo = jeab_reminder_admin_audit_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO reminder_admin_audit (
            created_at, reminder_id, action,
            old_owner, new_owner,
            old_street, new_street, old_zip, new_zip, old_city, new_city,
            contact_id,
            old_contact_street, old_contact_zip, old_contact_city,
            new_contact_street, new_contact_zip, new_contact_city,
            old_email, new_email,
            undone
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    )->execute([
        $at,
        $id,
        $action,
        $fields['old_owner'] ?? '',
        $fields['new_owner'] ?? '',
        $fields['old_street'] ?? '',
        $fields['new_street'] ?? '',
        $fields['old_zip'] ?? '',
        $fields['new_zip'] ?? '',
        $fields['old_city'] ?? '',
        $fields['new_city'] ?? '',
        $fields['contact_id'] ?? '',
        $fields['old_contact_street'] ?? '',
        $fields['old_contact_zip'] ?? '',
        $fields['old_contact_city'] ?? '',
        $fields['new_contact_street'] ?? '',
        $fields['new_contact_zip'] ?? '',
        $fields['new_contact_city'] ?? '',
        $fields['old_email'] ?? '',
        $fields['new_email'] ?? '',
    ]);

    return true;
}

/** @return list<array<string, mixed>> */
function jeab_reminder_admin_audit_list(): array
{
    $pdo = jeab_reminder_admin_audit_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $rows = $pdo->query(
        'SELECT id, created_at, reminder_id, action, old_owner, new_owner,
            old_street, new_street, old_zip, new_zip, old_city, new_city,
            contact_id, old_contact_street, old_contact_zip, old_contact_city,
            new_contact_street, new_contact_zip, new_contact_city,
            old_email, new_email, undone
         FROM reminder_admin_audit ORDER BY id'
    )->fetchAll();

    return is_array($rows) ? $rows : [];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_set_owner(JeabStore $store, array $input): array
{
    $dry = jeab_reminder_admin_wants_dry($input);
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id saknas', 'action' => 'reminder.set_owner', 'would_send' => false, 'status' => 422];
    }
    if (!array_key_exists('owner', $input) || is_array($input['owner'])) {
        return ['ok' => false, 'error' => 'ange ägare', 'action' => 'reminder.set_owner', 'would_send' => false, 'status' => 422];
    }
    $match = jeab_reminder_admin_staff((string) $input['owner']);
    if (empty($match['ok']) || !is_array($match['person'] ?? null)) {
        return [
            'ok' => false,
            'error' => (string) ($match['error'] ?? 'ägaren finns inte i personalistan'),
            'action' => 'reminder.set_owner',
            'would_send' => false,
            'status' => 422,
        ];
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : $store->getReminder($id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'action' => 'reminder.set_owner', 'id' => $id, 'would_send' => false, 'status' => 404];
    }
    $before = jeab_reminder_admin_owner_label($row);
    $after = trim((string) ($match['person']['name'] ?? ''));
    $afterId = trim((string) ($match['person']['id'] ?? ''));
    $changed = $before !== $after;
    $links = [
        'contact_id' => trim((string) ($row['contact_id'] ?? '')),
        'thread_id' => trim((string) ($row['thread_id'] ?? '')),
        'booking_id' => (int) ($row['booking_id'] ?? 0),
        'list_id' => (int) ($row['list_id'] ?? 0),
    ];
    $out = [
        'ok' => true,
        'action' => 'reminder.set_owner',
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'before' => $before,
        'after' => $after,
        'written' => 0,
        'would_send' => false,
        'contact_id' => $links['contact_id'],
        'thread_id' => $links['thread_id'],
        'booking_id' => $links['booking_id'],
        'list_id' => $links['list_id'],
        'status' => 200,
    ];
    if ($dry || !$changed) {
        return $out;
    }
    if (jeab_reminder_admin_audit_pdo() === null) {
        return ['ok' => false, 'error' => 'auditen gick inte att spara', 'action' => 'reminder.set_owner', 'would_send' => false, 'status' => 500];
    }
    $line = 'Ägare: ' . ($before !== '' ? $before : '(tom)') . ' → ' . $after;
    $saved = $store->saveReminder($id, [
        'person' => $afterId !== '' ? $afterId : $after,
        'note' => jeab_reminder_admin_note($row, $line),
        'updated_at' => function_exists('jeab_now') ? jeab_now() : date('c'),
    ]);
    $fresh = is_array($saved) ? $saved : $store->getReminder($id);
    if (!is_array($fresh) || jeab_reminder_admin_owner_label($fresh) !== $after) {
        return ['ok' => false, 'error' => 'ägaren skrevs inte', 'action' => 'reminder.set_owner', 'would_send' => false, 'status' => 409];
    }
    if (
        trim((string) ($fresh['contact_id'] ?? '')) !== $links['contact_id']
        || trim((string) ($fresh['thread_id'] ?? '')) !== $links['thread_id']
        || (int) ($fresh['booking_id'] ?? 0) !== $links['booking_id']
        || (int) ($fresh['list_id'] ?? 0) !== $links['list_id']
    ) {
        return ['ok' => false, 'error' => 'länkarna rördes', 'action' => 'reminder.set_owner', 'would_send' => false, 'status' => 409];
    }
    if (!jeab_reminder_admin_audit($id, 'set_owner', [
        'old_owner' => $before,
        'new_owner' => $after,
        'contact_id' => $links['contact_id'],
    ])) {
        return ['ok' => false, 'error' => 'auditen gick inte att spara', 'action' => 'reminder.set_owner', 'would_send' => false, 'status' => 500];
    }
    $out['written'] = 1;

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array{ok:bool, error?:string, place?:array{street:string, zip:string, city:string}}
 */
function jeab_reminder_admin_address_input(array $input): array
{
    foreach (['street', 'zip', 'city'] as $key) {
        if (!array_key_exists($key, $input) || is_array($input[$key])) {
            return ['ok' => false, 'error' => 'ange gata, postnummer och ort'];
        }
    }
    $street = trim(preg_replace('/\s+/u', ' ', (string) $input['street']) ?? (string) $input['street']);
    $street = preg_replace('/\s*,?\s*(?:sverige|sweden)\s*$/iu', '', $street) ?? $street;
    $street = trim($street, " ,");
    if ($street === '') {
        return ['ok' => false, 'error' => 'ange gata'];
    }
    $zipRaw = trim((string) $input['zip']);
    $zip = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($zipRaw) : preg_replace('/\s+/', '', $zipRaw);
    $zip = is_string($zip) ? $zip : '';
    if ($zip === '' || preg_match('/^\d{5}$/', $zip) !== 1) {
        return ['ok' => false, 'error' => 'ogiltigt postnummer'];
    }
    $city = trim((string) $input['city']);
    $city = function_exists('jeab_address_town') ? jeab_address_town($city) : trim($city);
    if ($city === '') {
        return ['ok' => false, 'error' => 'ange ort'];
    }

    return ['ok' => true, 'place' => ['street' => $street, 'zip' => $zip, 'city' => $city]];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_set_address(JeabStore $store, array $input): array
{
    $dry = jeab_reminder_admin_wants_dry($input);
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id saknas', 'action' => 'reminder.set_address', 'would_send' => false, 'status' => 422];
    }
    $parsed = jeab_reminder_admin_address_input($input);
    if (empty($parsed['ok']) || !is_array($parsed['place'] ?? null)) {
        return [
            'ok' => false,
            'error' => (string) ($parsed['error'] ?? 'ange gata, postnummer och ort'),
            'action' => 'reminder.set_address',
            'would_send' => false,
            'status' => 422,
        ];
    }
    $next = $parsed['place'];
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : $store->getReminder($id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'action' => 'reminder.set_address', 'id' => $id, 'would_send' => false, 'status' => 404];
    }
    $before = jeab_reminder_admin_place($row);
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = $contactId !== '' && function_exists('jeab_reminder_linked_contact')
        ? jeab_reminder_linked_contact($row)
        : null;
    if ($contactId !== '' && !is_array($contact)) {
        return ['ok' => false, 'error' => 'kontakten saknas', 'action' => 'reminder.set_address', 'id' => $id, 'would_send' => false, 'status' => 404];
    }
    $contactBefore = is_array($contact) ? jeab_reminder_admin_contact_place($contact) : ['street' => '', 'zip' => '', 'city' => ''];
    $reminderSame = jeab_reminder_admin_place_same($before, $next);
    $contactSame = !is_array($contact) || jeab_reminder_admin_place_same($contactBefore, $next);
    $changed = !$reminderSame || !$contactSame;
    $links = [
        'contact_id' => $contactId,
        'thread_id' => trim((string) ($row['thread_id'] ?? '')),
        'booking_id' => (int) ($row['booking_id'] ?? 0),
        'list_id' => (int) ($row['list_id'] ?? 0),
    ];
    $out = [
        'ok' => true,
        'action' => 'reminder.set_address',
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'before' => $before,
        'after' => $next,
        'contact_before' => is_array($contact) ? $contactBefore : null,
        'contact_after' => is_array($contact) ? $next : null,
        'written' => 0,
        'would_send' => false,
        'contact_id' => $links['contact_id'],
        'thread_id' => $links['thread_id'],
        'booking_id' => $links['booking_id'],
        'list_id' => $links['list_id'],
        'status' => 200,
    ];
    if ($dry || !$changed) {
        return $out;
    }
    if (jeab_reminder_admin_audit_pdo() === null) {
        return ['ok' => false, 'error' => 'auditen gick inte att spara', 'action' => 'reminder.set_address', 'would_send' => false, 'status' => 500];
    }
    $lines = ['Adress: ' . jeab_reminder_admin_place_text($before) . ' → ' . jeab_reminder_admin_place_text($next)];
    if (is_array($contact)) {
        $lines[] = 'Kontakt: ' . jeab_reminder_admin_place_text($contactBefore) . ' → ' . jeab_reminder_admin_place_text($next);
    }
    $note = trim((string) ($row['note'] ?? ''));
    foreach ($lines as $line) {
        $note = jeab_reminder_admin_note(['note' => $note], $line);
    }
    $saved = $store->saveReminder($id, [
        'address' => $next['street'],
        'postal' => $next['zip'],
        'ort' => $next['city'],
        'note' => $note,
        'updated_at' => function_exists('jeab_now') ? jeab_now() : date('c'),
    ]);
    $fresh = is_array($saved) ? $saved : $store->getReminder($id);
    if (!is_array($fresh) || !jeab_reminder_admin_place_same(jeab_reminder_admin_place($fresh), $next)) {
        return ['ok' => false, 'error' => 'adressen skrevs inte', 'action' => 'reminder.set_address', 'would_send' => false, 'status' => 409];
    }
    if (
        trim((string) ($fresh['contact_id'] ?? '')) !== $links['contact_id']
        || trim((string) ($fresh['thread_id'] ?? '')) !== $links['thread_id']
        || (int) ($fresh['booking_id'] ?? 0) !== $links['booking_id']
        || (int) ($fresh['list_id'] ?? 0) !== $links['list_id']
        || jeab_reminder_admin_owner_label($fresh) !== jeab_reminder_admin_owner_label($row)
    ) {
        return ['ok' => false, 'error' => 'länkarna rördes', 'action' => 'reminder.set_address', 'would_send' => false, 'status' => 409];
    }
    if (is_array($contact) && function_exists('jeab_reminder_save_contact_customer')) {
        $savedContact = jeab_reminder_save_contact_customer($contactId, [
            'street' => $next['street'],
            'postal' => $next['zip'],
            'city' => $next['city'],
        ]);
        if (!is_array($savedContact) || !jeab_reminder_admin_place_same(jeab_reminder_admin_contact_place($savedContact), $next)) {
            return ['ok' => false, 'error' => 'kontakten skrevs inte', 'action' => 'reminder.set_address', 'would_send' => false, 'status' => 409];
        }
    }
    if (!jeab_reminder_admin_audit($id, 'set_address', [
        'old_owner' => jeab_reminder_admin_owner_label($row),
        'new_owner' => jeab_reminder_admin_owner_label($row),
        'old_street' => $before['street'],
        'new_street' => $next['street'],
        'old_zip' => $before['zip'],
        'new_zip' => $next['zip'],
        'old_city' => $before['city'],
        'new_city' => $next['city'],
        'contact_id' => $contactId,
        'old_contact_street' => $contactBefore['street'],
        'old_contact_zip' => $contactBefore['zip'],
        'old_contact_city' => $contactBefore['city'],
        'new_contact_street' => is_array($contact) ? $next['street'] : '',
        'new_contact_zip' => is_array($contact) ? $next['zip'] : '',
        'new_contact_city' => is_array($contact) ? $next['city'] : '',
    ])) {
        return ['ok' => false, 'error' => 'auditen gick inte att spara', 'action' => 'reminder.set_address', 'would_send' => false, 'status' => 500];
    }
    $out['written'] = 1;

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_admin_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'reminder.set_owner') {
        return jeab_reminder_set_owner($store, $input);
    }
    if ($action === 'reminder.set_address') {
        return jeab_reminder_set_address($store, $input);
    }
    if ($action === 'reminder.set_email') {
        return jeab_reminder_set_email($store, $input);
    }
    if ($action === 'reminder.create_manual') {
        return jeab_reminder_create_manual($store, $input);
    }
    if ($action === 'reminders.zero_id_check') {
        return jeab_reminders_zero_id_check($store, $input);
    }

    return ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
}

/**
 * Sätter e-post på den kopplade kontakten. dry=1 skriver inget. Skickar inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_set_email(JeabStore $store, array $input): array
{
    $dry = jeab_reminder_admin_wants_dry($input);
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'id saknas', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 422];
    }
    if (!array_key_exists('email', $input) || is_array($input['email'])) {
        return ['ok' => false, 'error' => 'ange e-post', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 422];
    }
    $after = function_exists('jeab_kontakt_email')
        ? jeab_kontakt_email((string) $input['email'])
        : trim((string) $input['email']);
    if ($after === '' || filter_var($after, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'ogiltig e-post', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 422];
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : $store->getReminder($id);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'action' => 'reminder.set_email', 'id' => $id, 'would_send' => false, 'status' => 404];
    }
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = $contactId !== '' && function_exists('jeab_reminder_linked_contact')
        ? jeab_reminder_linked_contact($row)
        : null;
    if ($contactId === '' || !is_array($contact)) {
        return ['ok' => false, 'error' => 'kontakten saknas', 'action' => 'reminder.set_email', 'id' => $id, 'would_send' => false, 'status' => 404];
    }
    $before = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
    $ownEmail = trim((string) ($row['email'] ?? ''));
    $links = [
        'contact_id' => $contactId,
        'thread_id' => trim((string) ($row['thread_id'] ?? '')),
        'booking_id' => (int) ($row['booking_id'] ?? 0),
        'list_id' => (int) ($row['list_id'] ?? 0),
    ];
    $changed = strcasecmp($before, $after) !== 0;
    $out = [
        'ok' => true,
        'action' => 'reminder.set_email',
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'before' => $before,
        'after' => $after,
        'written' => 0,
        'would_send' => false,
        'contact_id' => $links['contact_id'],
        'thread_id' => $links['thread_id'],
        'booking_id' => $links['booking_id'],
        'list_id' => $links['list_id'],
        'status' => 200,
    ];
    if ($dry || !$changed) {
        return $out;
    }
    if (jeab_reminder_admin_audit_pdo() === null || !function_exists('jeab_reminder_save_contact_customer')) {
        return ['ok' => false, 'error' => 'auditen gick inte att spara', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 500];
    }
    $line = 'E-post: ' . ($before !== '' ? $before : '(tom)') . ' → ' . $after;
    $saved = $store->saveReminder($id, [
        'note' => jeab_reminder_admin_note($row, $line),
        'updated_at' => function_exists('jeab_now') ? jeab_now() : date('c'),
    ]);
    $fresh = is_array($saved) ? $saved : $store->getReminder($id);
    if (
        !is_array($fresh)
        || trim((string) ($fresh['contact_id'] ?? '')) !== $links['contact_id']
        || trim((string) ($fresh['thread_id'] ?? '')) !== $links['thread_id']
        || (int) ($fresh['booking_id'] ?? 0) !== $links['booking_id']
        || (int) ($fresh['list_id'] ?? 0) !== $links['list_id']
        || trim((string) ($fresh['email'] ?? '')) !== $ownEmail
    ) {
        return ['ok' => false, 'error' => 'länkarna rördes', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 409];
    }
    $savedContact = jeab_reminder_save_contact_customer($contactId, ['email' => $after]);
    $nowEmail = is_array($savedContact) && function_exists('jeab_kontakt_row_email')
        ? jeab_kontakt_row_email($savedContact)
        : '';
    if ($nowEmail !== $after) {
        return ['ok' => false, 'error' => 'e-posten skrevs inte', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 409];
    }
    if (!jeab_reminder_admin_audit($id, 'set_email', [
        'old_email' => $before,
        'new_email' => $after,
        'contact_id' => $contactId,
        'old_owner' => jeab_reminder_admin_owner_label($row),
        'new_owner' => jeab_reminder_admin_owner_label($row),
    ])) {
        return ['ok' => false, 'error' => 'auditen gick inte att spara', 'action' => 'reminder.set_email', 'would_send' => false, 'status' => 500];
    }
    $out['written'] = 1;

    return $out;
}

/**
 * Produktetikett som leadflödet. «okänd produkt» är tillåten.
 */
function jeab_reminder_manual_product_label(string $product): ?string
{
    $raw = trim($product);
    if ($raw === '') {
        return null;
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    if ($fold === 'okänd produkt' || $fold === 'okand produkt') {
        return 'okänd produkt';
    }
    if ($fold === 'sol+batteri' || $fold === 'sol + batteri') {
        return 'Sol+batteri';
    }
    $labels = [
        'laddbox' => 'Elbilsladdare',
        'batteri' => 'Batteri',
        'sol' => 'Solceller med batteri',
    ];
    foreach ($labels as $label) {
        $labelFold = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if ($fold === $labelFold) {
            return $label;
        }
    }
    $token = function_exists('jeab_inkorg_product_token') ? jeab_inkorg_product_token($raw) : '';
    if ($token === '' && function_exists('jeab_auto_offer_is') && jeab_auto_offer_is($fold)) {
        $token = $fold;
    }

    return $labels[$token] ?? null;
}

function jeab_reminder_manual_text(mixed $value): ?string
{
    if (is_array($value)) {
        return null;
    }

    return trim((string) $value);
}

/** Gata och postnummer, samma tolkning som kontakter. */
function jeab_reminder_manual_address_key(string $address, string $postal = ''): string
{
    $parsed = function_exists('jeab_kontakt_parse_address')
        ? jeab_kontakt_parse_address($address)
        : ['street_key' => '', 'postal' => ''];
    $street = trim((string) ($parsed['street_key'] ?? ''));
    $street = function_exists('mb_strtolower') ? mb_strtolower($street, 'UTF-8') : strtolower($street);
    $zip = trim((string) ($parsed['postal'] ?? ''));
    if ($zip === '' && trim($postal) !== '' && function_exists('jeab_kontakt_postal')) {
        $zip = jeab_kontakt_postal($postal);
    }
    if ($street === '') {
        $flat = trim(preg_replace('/\s+/u', ' ', $address) ?? $address);
        $street = function_exists('mb_strtolower') ? mb_strtolower($flat, 'UTF-8') : strtolower($flat);
    }

    return $street . '|' . $zip;
}

function jeab_reminder_manual_scrub(string $text, string $phone = ''): string
{
    $text = preg_replace_callback(
        '/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/',
        static fn(array $m): string => function_exists('jeab_mask_pnr') ? jeab_mask_pnr((string) $m[0]) : '••••',
        $text
    ) ?? $text;
    $text = preg_replace_callback(
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu',
        static function (array $m): string {
            $email = (string) $m[0];
            if (function_exists('jeab_lead_mask_email')) {
                $masked = jeab_lead_mask_email($email);
                if ($masked !== '') {
                    return $masked;
                }
            }

            return '***';
        },
        $text
    ) ?? $text;
    $phone = trim($phone);
    if ($phone !== '') {
        $masked = function_exists('jeab_test_lead_mask_phone') ? jeab_test_lead_mask_phone($phone) : '***';
        $text = str_replace($phone, $masked !== '' ? $masked : '***', $text);
    }

    return $text;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_reminder_manual_public(array $row, string $product, string $phone = ''): array
{
    $email = trim((string) ($row['email'] ?? ''));
    $storedPhone = trim((string) ($row['phone'] ?? ''));
    $maskPhone = $storedPhone !== '' ? $storedPhone : $phone;
    $note = (string) ($row['note'] ?? '');

    return [
        'id' => (int) ($row['id'] ?? 0),
        'title' => jeab_reminder_manual_scrub((string) ($row['title'] ?? ''), $maskPhone),
        'customer_name' => jeab_reminder_manual_scrub((string) ($row['customer_name'] ?? ''), $maskPhone),
        'email' => function_exists('jeab_lead_mask_email') ? jeab_lead_mask_email($email) : '',
        'phone' => function_exists('jeab_test_lead_mask_phone') ? jeab_test_lead_mask_phone($maskPhone) : '',
        'address' => jeab_reminder_manual_scrub((string) ($row['address'] ?? ''), $maskPhone),
        'ort' => jeab_reminder_manual_scrub((string) ($row['ort'] ?? ''), $maskPhone),
        'postal' => (string) ($row['postal'] ?? ''),
        'office' => (string) ($row['company'] ?? ''),
        'owner' => jeab_reminder_admin_owner_label($row),
        'product' => $product,
        'note' => jeab_reminder_manual_scrub($note, $maskPhone),
        'contact_id' => trim((string) ($row['contact_id'] ?? '')),
        'due_at' => (string) ($row['due_at'] ?? ''),
    ];
}

/**
 * Samma e-post och adress skapad de senaste 24 timmarna.
 *
 * @return array<string, mixed>|null
 */
function jeab_reminder_manual_recent(JeabStore $store, string $email, string $address): ?array
{
    $wantEmail = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : strtolower(trim($email));
    $wantKey = jeab_reminder_manual_address_key($address);
    if ($wantEmail === '' || $wantKey === '|') {
        return null;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $cutoff = new DateTimeImmutable('-24 hours', $tz);
    $best = null;
    $bestAt = null;
    foreach (jeab_reminders_every($store) as $row) {
        if (!is_array($row) || (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($row))) {
            continue;
        }
        $rowEmail = function_exists('jeab_kontakt_email')
            ? jeab_kontakt_email((string) ($row['email'] ?? ''))
            : strtolower(trim((string) ($row['email'] ?? '')));
        if ($rowEmail === '' || $rowEmail !== $wantEmail) {
            continue;
        }
        $rowKey = jeab_reminder_manual_address_key((string) ($row['address'] ?? ''), (string) ($row['postal'] ?? ''));
        if ($rowKey !== $wantKey) {
            continue;
        }
        $stamp = trim((string) ($row['created_at'] ?? ''));
        $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stamp, $tz);
        if (!$created instanceof DateTimeImmutable) {
            try {
                $created = new DateTimeImmutable($stamp, $tz);
            } catch (Throwable) {
                continue;
            }
        }
        if ($created < $cutoff) {
            continue;
        }
        if ($best === null || ($bestAt instanceof DateTimeImmutable && $created > $bestAt)) {
            $best = $row;
            $bestAt = $created;
        }
    }

    return $best;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_manual_input(array $input): array
{
    $office = jeab_reminder_manual_text($input['office'] ?? null);
    $ownerRaw = jeab_reminder_manual_text($input['owner'] ?? null);
    $productRaw = jeab_reminder_manual_text($input['product'] ?? null);
    $name = jeab_reminder_manual_text($input['name'] ?? null);
    $emailRaw = jeab_reminder_manual_text($input['email'] ?? null);
    $address = jeab_reminder_manual_text($input['address'] ?? null);
    $note = array_key_exists('note', $input) ? jeab_reminder_manual_text($input['note']) : '';
    $phoneRaw = array_key_exists('phone', $input) ? jeab_reminder_manual_text($input['phone']) : '';
    if ($office === null || $ownerRaw === null || $productRaw === null || $name === null || $emailRaw === null || $address === null || $note === null || $phoneRaw === null) {
        return ['ok' => false, 'error' => 'fälten ska vara text'];
    }
    $office = jeab_normalize_company($office);
    if ($office !== 'jarfalla' && $office !== 'malmo') {
        return ['ok' => false, 'error' => 'kontoret ska vara jarfalla eller malmo'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'ange namn'];
    }
    if ($address === '') {
        return ['ok' => false, 'error' => 'ange adress'];
    }
    $product = jeab_reminder_manual_product_label($productRaw);
    if ($product === null) {
        return ['ok' => false, 'error' => 'produkten ska vara en känd produkt eller okänd produkt'];
    }
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($emailRaw) : strtolower($emailRaw);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'ogiltig e-post'];
    }
    $staff = jeab_reminder_admin_staff($ownerRaw);
    if (empty($staff['ok']) || !is_array($staff['person'] ?? null)) {
        return ['ok' => false, 'error' => (string) ($staff['error'] ?? 'ägaren finns inte i personalistan')];
    }
    $person = $staff['person'];
    if ((string) ($person['company'] ?? '') !== $office) {
        return ['ok' => false, 'error' => 'ägaren hör inte till kontoret'];
    }
    $phone = '';
    if ($phoneRaw !== '') {
        try {
            $phone = (string) (jeab_normalize_reminder_phone($phoneRaw) ?? '');
        } catch (InvalidArgumentException) {
            return ['ok' => false, 'error' => function_exists('jeab_reminder_phone_error') ? jeab_reminder_phone_error() : 'ogiltigt telefonnummer'];
        }
    }
    if (function_exists('mb_strlen') && mb_strlen($note, 'UTF-8') > 8000) {
        return ['ok' => false, 'error' => 'anteckningen är för lång'];
    }

    return [
        'ok' => true,
        'office' => $office,
        'owner' => trim((string) ($person['name'] ?? '')),
        'owner_id' => trim((string) ($person['id'] ?? '')),
        'product' => $product,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'note' => $note,
    ];
}

/**
 * @param array<string, mixed> $parsed
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_reminder_manual_result(array $parsed, array $row, bool $dry, int $created, int $reused): array
{
    $public = jeab_reminder_manual_public($row, (string) $parsed['product'], (string) $parsed['phone']);
    if ($public['owner'] === '') {
        $public['owner'] = (string) $parsed['owner'];
    }
    if ($public['office'] === '') {
        $public['office'] = (string) $parsed['office'];
    }

    return [
        'ok' => true,
        'action' => 'reminder.create_manual',
        'dry' => $dry ? 1 : 0,
        'sent' => 0,
        'would_send' => false,
        'written' => $created,
        'created' => $created,
        'reused' => $reused,
        'reminder' => $public,
        'contact_id' => (string) ($public['contact_id'] ?? ''),
        'status' => 200,
    ];
}

/**
 * Skapar en påminnelse och kontakt utan att skicka offert, bekräftelse eller sms.
 * dry=1 är standard och skriver inget. Samma e-post och adress inom 24 timmar
 * lämnar tillbaka den befintliga påminnelsen.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminder_create_manual(JeabStore $store, array $input): array
{
    $dry = jeab_reminder_admin_wants_dry($input);
    $fail = static function (string $error, int $status) use ($dry): array {
        return [
            'ok' => false,
            'error' => $error,
            'action' => 'reminder.create_manual',
            'dry' => $dry ? 1 : 0,
            'sent' => 0,
            'would_send' => false,
            'status' => $status,
        ];
    };
    $parsed = jeab_reminder_manual_input($input);
    if (empty($parsed['ok'])) {
        return $fail((string) ($parsed['error'] ?? 'ogiltiga fält'), 422);
    }
    $existing = jeab_reminder_manual_recent($store, (string) $parsed['email'], (string) $parsed['address']);
    if (is_array($existing)) {
        return jeab_reminder_manual_result($parsed, $existing, $dry, 0, 1);
    }
    $place = function_exists('jeab_inkorg_reminder_place_fields')
        ? jeab_inkorg_reminder_place_fields((string) $parsed['address'])
        : ['address' => (string) $parsed['address'], 'ort' => '', 'postal' => ''];
    $storedAddress = trim((string) ($place['address'] ?? ''));
    if ($storedAddress === '') {
        $storedAddress = (string) $parsed['address'];
    }
    $preview = [
        'id' => 0,
        'title' => (string) $parsed['name'] . ' – ' . (string) $parsed['product'],
        'customer_name' => (string) $parsed['name'],
        'email' => (string) $parsed['email'],
        'phone' => (string) $parsed['phone'],
        'address' => $storedAddress,
        'ort' => (string) ($place['ort'] ?? ''),
        'postal' => (string) ($place['postal'] ?? ''),
        'company' => (string) $parsed['office'],
        'person' => (string) $parsed['owner_id'],
        'note' => (string) $parsed['note'],
        'contact_id' => '',
        'due_at' => function_exists('jeab_today_start') ? (string) jeab_today_start() : '',
    ];
    if ($dry) {
        $matchId = '';
        if (function_exists('jeab_kontakter_load') && function_exists('jeab_kontakt_find') && function_exists('jeab_kontakt_parse_address')) {
            $parsedAddress = jeab_kontakt_parse_address((string) $parsed['address']);
            $found = jeab_kontakt_find(
                jeab_kontakter_load(),
                (string) ($parsedAddress['street_key'] ?? ''),
                (string) ($parsedAddress['postal'] ?? ''),
                (string) $parsed['email'],
                (string) $parsed['phone'],
                true,
                (string) $parsed['name'],
                (string) ($parsedAddress['city'] ?? '')
            );
            $matchId = is_string($found) ? $found : '';
        }
        $preview['contact_id'] = $matchId;

        return jeab_reminder_manual_result($parsed, $preview, true, 0, 0);
    }
    if (jeab_reminder_admin_audit_pdo() === null) {
        return $fail('auditen gick inte att spara', 500);
    }
    try {
        $created = jeab_create_reminder($store, (string) $preview['title'], (string) $preview['due_at'], null, null, [
            'customer' => (string) $parsed['name'],
            'email' => (string) $parsed['email'],
            'phone' => (string) $parsed['phone'],
            'address' => $storedAddress,
            'company' => (string) $parsed['office'],
            'who' => (string) $parsed['owner'],
            'note' => (string) $parsed['note'],
        ]);
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (str_contains($message, '@') || preg_match('/\d{6,}/', $message) === 1) {
            $message = 'påminnelsen skapades inte';
        }

        return $fail($message !== '' ? $message : 'påminnelsen skapades inte', 422);
    }
    if (!is_array($created) || (int) ($created['id'] ?? 0) < 1) {
        return $fail('påminnelsen skapades inte', 500);
    }
    if (!empty($created['reused'])) {
        return jeab_reminder_manual_result($parsed, $created, false, 0, 1);
    }
    $id = (int) $created['id'];
    $extra = [];
    if ((string) ($place['ort'] ?? '') !== '') {
        $extra['ort'] = (string) $place['ort'];
    }
    if ((string) ($place['postal'] ?? '') !== '') {
        $extra['postal'] = (string) $place['postal'];
    }
    if ($extra !== []) {
        $saved = $store->saveReminder($id, $extra);
        if (is_array($saved)) {
            $created = $saved;
        }
    }
    if (function_exists('jeab_kontakt_attach_reminder')) {
        jeab_kontakt_attach_reminder($store, $created, [
            'name' => (string) $parsed['name'],
            'email' => (string) $parsed['email'],
            'phone' => (string) $parsed['phone'],
            'address' => (string) $parsed['address'],
            'city' => (string) ($place['ort'] ?? ''),
            'postal_code' => (string) ($place['postal'] ?? ''),
        ]);
    }
    $fresh = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : $store->getReminder($id);
    if (!is_array($fresh)) {
        $fresh = $created;
    }
    $parts = function_exists('jeab_kontakt_parse_address')
        ? jeab_kontakt_parse_address((string) $parsed['address'])
        : ['street' => $storedAddress, 'postal' => (string) ($place['postal'] ?? ''), 'city' => (string) ($place['ort'] ?? '')];
    if (!jeab_reminder_admin_audit($id, 'create_manual', [
        'new_owner' => (string) $parsed['owner'],
        'new_email' => (string) $parsed['email'],
        'new_street' => (string) ($parts['street'] ?? ''),
        'new_zip' => (string) ($parts['postal'] ?? ''),
        'new_city' => (string) ($parts['city'] ?? ''),
        'contact_id' => trim((string) ($fresh['contact_id'] ?? '')),
    ])) {
        return $fail('auditen gick inte att spara', 500);
    }

    return jeab_reminder_manual_result($parsed, $fresh, false, 1, 0);
}

/**
 * Fönster runt 2026-10-30 12:53 Europe/Stockholm. center i anropet kan flytta det.
 *
 * @param array<string, mixed> $input
 * @return array{center:string,from:string,to:string,tz:string}
 */
function jeab_reminders_zero_id_window(array $input): array
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $center = trim((string) ($input['center'] ?? '2026-10-30 12:53:00'));
    $at = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $center, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$at instanceof DateTimeImmutable || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        $center = '2026-10-30 12:53:00';
        $at = new DateTimeImmutable($center, $tz);
    }
    $minutes = (int) ($input['minutes'] ?? 10);
    if ($minutes < 1 || $minutes > 60) {
        $minutes = 10;
    }

    return [
        'center' => $at->format('Y-m-d H:i:s'),
        'from' => $at->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
        'to' => $at->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
        'tz' => 'Europe/Stockholm',
    ];
}

/**
 * Läser rader med id 0, och rader i fönstret vars titel är tom.
 * Skriver inget.
 *
 * @param array{from:string,to:string} $window
 * @return list<array<string, mixed>>
 */
function jeab_reminders_zero_id_rows(PDO $pdo, array $window): array
{
    $sql = 'SELECT id, title, ' . jeab_copy_select($pdo, 'reminders', ['customer_name']) . ', company, created_at, updated_at FROM reminders'
        . ' WHERE id = 0 OR (created_at >= ? AND created_at <= ? AND (title IS NULL OR TRIM(title) = ?))'
        . ' ORDER BY created_at ASC, id ASC LIMIT 50';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$window['from'], $window['to'], '']);
    $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach (is_array($fetched) ? $fetched : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $title = trim((string) ($row['title'] ?? ''));
        $why = [];
        if ($id === 0) {
            $why[] = 'zero_id';
        }
        if ($title === '') {
            $why[] = 'empty_title';
        }
        $rows[] = [
            'id' => $id,
            'title' => (string) ($row['title'] ?? ''),
            'customer_name' => isset($row['customer_name']) ? (string) $row['customer_name'] : '',
            'company' => isset($row['company']) ? (string) $row['company'] : '',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'why' => $why,
        ];
    }

    return $rows;
}

/**
 * reminders.zero_id_check. Bara läsning. Raderar inget och skickar inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_reminders_zero_id_check(JeabStore $store, array $input = []): array
{
    $action = 'reminders.zero_id_check';
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => $action, 'read_only' => true, 'deleted' => 0, 'status' => 401];
    }
    if (!$store instanceof JeabPdoStore) {
        return ['ok' => false, 'error' => 'SQL-lager saknas.', 'action' => $action, 'read_only' => true, 'deleted' => 0, 'status' => 409];
    }
    $window = jeab_reminders_zero_id_window($input);
    $pdo = $store->pdo();
    $before = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();
    $rows = jeab_reminders_zero_id_rows($pdo, $window);
    $after = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();

    return [
        'ok' => true,
        'action' => $action,
        'read_only' => true,
        'deleted' => 0,
        'changed' => 0,
        'count_before' => $before,
        'count_after' => $after,
        'window' => $window,
        'count' => count($rows),
        'rows' => $rows,
    ];
}
