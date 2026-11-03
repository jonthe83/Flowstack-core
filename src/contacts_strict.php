<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * CONTACTS_STRICT är av som standard. På: tomt kontaktfält visas tomt,
 * utgående flöden använder inte radens kopia, och påminnelse, bokning,
 * köp och order måste ha contact_id. Inkorg får sakna contact_id om
 * ingen påminnelse pekar på raden. gap_fill fyller bara tomma fält.
 */

function jeab_contacts_strict(): bool
{
    return function_exists('jeab_flag') && jeab_flag('CONTACTS_STRICT');
}

function jeab_contacts_strict_active(): bool
{
    return jeab_contacts_strict() && function_exists('jeab_contacts_sole') && jeab_contacts_sole();
}

/** CONTACTS_SOLE och CONTACTS_STRICT. Samma håll som contacts.inbox_strip. */
function jeab_contacts_inbox_hold(): bool
{
    return jeab_contacts_strict_active();
}

function jeab_contacts_inbox_first_text(string ...$parts): string
{
    foreach ($parts as $part) {
        $text = trim($part);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

/** @param array<string, mixed> $row */
function jeab_contacts_inbox_contact(array $row): ?array
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '' || !function_exists('jeab_kontakt_get')) {
        return null;
    }
    $contact = jeab_kontakt_get($id);

    return is_array($contact) ? $contact : null;
}

/** @param array<string, mixed> $row */
function jeab_contacts_inbox_contact_email(array $row): string
{
    $contact = jeab_contacts_inbox_contact($row);
    if ($contact === null || !function_exists('jeab_kontakt_row_email')) {
        return '';
    }

    return trim((string) jeab_kontakt_row_email($contact));
}

/** @param array<string, mixed> $row */
function jeab_contacts_inbox_contact_phone(array $row): string
{
    $contact = jeab_contacts_inbox_contact($row);
    if ($contact === null || !function_exists('jeab_kontakt_row_phone')) {
        return '';
    }

    return trim((string) jeab_kontakt_row_phone($contact));
}

/** @param array<string, mixed> $row */
function jeab_contacts_inbox_contact_name(array $row): string
{
    $contact = jeab_contacts_inbox_contact($row);
    if ($contact === null || !function_exists('jeab_kontakt_row_name')) {
        return '';
    }

    return trim((string) jeab_kontakt_row_name($contact));
}

/** Feltext utan kundvärden. */
function jeab_contacts_strict_link_message(string $kind): string
{
    return match ($kind) {
        'booking' => 'Bokningen saknar kontakt.',
        'purchase', 'order' => 'Ordern saknar kontakt.',
        'inbox' => 'Ärendet har en påminnelse men saknar kontakt.',
        default => 'Påminnelsen saknar kontakt.',
    };
}

/**
 * Påminnelse, bokning, köp och order måste ha contact_id när flaggan är på.
 * Tomt id och flaggan av gör inget.
 */
function jeab_contacts_strict_assert_link(string $kind, string $contactId): void
{
    if (!jeab_contacts_strict_active() || trim($contactId) !== '') {
        return;
    }
    throw new InvalidArgumentException(jeab_contacts_strict_link_message($kind));
}

function jeab_contacts_strict_stamp_live(): bool
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;

    return $pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

/**
 * Innan raden skapas. Känd eller upplöst kontakt lämnas tillbaka.
 * Saknas den, och länken inte kan skrivas efteråt, kastas felet utan att raden finns.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_strict_prepare_link(string $kind, string $contactId, array $row): string
{
    if (!jeab_contacts_strict_active()) {
        return trim($contactId);
    }
    $contactId = trim($contactId);
    if ($contactId !== '') {
        return $contactId;
    }
    if ($kind === 'reminder' && function_exists('jeab_kontakt_find_or_create')) {
        $lookup = !array_key_exists('lookup', $row)
            || !($row['lookup'] === false || $row['lookup'] === 0 || (string) $row['lookup'] === '0');
        $found = jeab_kontakt_find_or_create([
            'name' => trim((string) ($row['customer_name'] ?? $row['name'] ?? '')),
            'email' => trim((string) ($row['email'] ?? '')),
            'phone' => trim((string) ($row['phone'] ?? '')),
            'address' => trim((string) ($row['address'] ?? '')),
            'city' => trim((string) ($row['ort'] ?? $row['city'] ?? '')),
            'postal_code' => trim((string) ($row['postal'] ?? $row['postal_code'] ?? '')),
            'company' => (string) ($row['company'] ?? ''),
            'thread_id' => (string) ($row['thread_id'] ?? ''),
            'require_postal' => !empty($row['require_postal']),
            'email_link' => true,
        ], $lookup, false, true);
        if (is_array($found)) {
            $contactId = trim((string) ($found['id'] ?? ''));
        }
        if ($contactId !== '') {
            return $contactId;
        }
        if (jeab_contacts_strict_stamp_live()) {
            return '';
        }
    }
    if ($kind === 'booking' && function_exists('jeab_contacts_sole_hint') && function_exists('jeab_contacts_sole_booking_link_skips_create')) {
        $hint = jeab_contacts_sole_hint('booking', $row);
        if (!jeab_contacts_sole_booking_link_skips_create($hint)) {
            $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
            $index = function_exists('jeab_contacts_sole_index') ? jeab_contacts_sole_index($contacts) : ['email' => [], 'phone' => []];
            $resolved = function_exists('jeab_contacts_sole_resolve') ? jeab_contacts_sole_resolve($contacts, $index, $hint) : null;
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
            if (jeab_contacts_strict_stamp_live()) {
                return '';
            }
        }
    }
    jeab_contacts_strict_assert_link($kind, '');

    return '';
}

function jeab_contacts_strict_inbox_has_reminder(string $threadId): bool
{
    $threadId = trim($threadId);
    if ($threadId === '' || !function_exists('jeab_data_pdo')) {
        return false;
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM reminders WHERE thread_id = ? LIMIT 1');
        $stmt->execute([$threadId]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Inkorg utan contact_id är tillåten. En påminnelse som pekar på raden gör det till fel.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_strict_assert_inbox(array $row): void
{
    if (!empty($GLOBALS['_JEAB_INKORG_RELINK_UNDO']) || !empty($GLOBALS['_JEAB_OFFER_CLOSE'])) {
        return;
    }
    if (!jeab_contacts_strict_active() || trim((string) ($row['contact_id'] ?? '')) !== '') {
        return;
    }
    $tid = trim((string) ($row['id'] ?? $row['thread_id'] ?? ''));
    if ($tid === '' || !jeab_contacts_strict_inbox_has_reminder($tid)) {
        return;
    }
    if (jeab_contacts_strict_inbox_contact_id($tid, $row) !== '') {
        return;
    }
    throw new InvalidArgumentException(jeab_contacts_strict_link_message('inbox'));
}

/**
 * Kontakt för ett ärende utan contact_id: påminnelsens contact_id, annars en
 * kontakt med ärendets e-post (jeab_kontakt_email). Adress och telefon krävs inte.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_strict_inbox_contact_id(string $threadId, array $row): string
{
    $threadId = trim($threadId);
    if ($threadId === '') {
        return '';
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare("SELECT contact_id FROM reminders WHERE thread_id = ? AND contact_id IS NOT NULL AND contact_id <> '' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$threadId]);
            $cid = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($cid !== '') {
                return $cid;
            }
        } catch (Throwable) {
        }
    }
    $email = '';
    foreach (['email', 'customer_email', 'from_email'] as $key) {
        $raw = trim((string) ($row[$key] ?? ''));
        if ($raw !== '') {
            $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($raw) : mb_strtolower($raw);
            break;
        }
    }
    if ($email === '' || !function_exists('jeab_kontakter_load') || !function_exists('jeab_kontakt_find')) {
        return '';
    }
    try {
        $found = jeab_kontakt_find(jeab_kontakter_load(), '', '', $email, '', false, '', '');
    } catch (Throwable) {
        return '';
    }

    return is_string($found) ? trim($found) : '';
}

/**
 * Fyller contact_id på ärendet från påminnelsen eller e-posten innan sparning.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_strict_inbox_adopt(string $threadId, array $row): array
{
    if (trim((string) ($row['contact_id'] ?? '')) !== '' || !jeab_contacts_strict_active()) {
        return $row;
    }
    if (!jeab_contacts_strict_inbox_has_reminder($threadId)) {
        return $row;
    }
    $cid = jeab_contacts_strict_inbox_contact_id($threadId, $row);
    if ($cid !== '') {
        $row['contact_id'] = $cid;
    }

    return $row;
}

/**
 * Ägare från kontakten, utan att låna personnummer eller adress från ordern.
 *
 * @param array<string, mixed> $contact
 * @return list<array<string, mixed>>
 */
function jeab_contacts_strict_owners(array $contact): array
{
    $raw = $contact['owners'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $email = trim((string) ($owner['email'] ?? ''));
        $phone = trim((string) ($owner['phone'] ?? ''));
        $pnr = function_exists('jeab_contacts_sole_pnr_text')
            ? jeab_contacts_sole_pnr_text((string) ($owner['personnummer'] ?? ''))
            : trim((string) ($owner['personnummer'] ?? ''));
        $address = trim((string) ($owner['address'] ?? $owner['adress'] ?? ''));
        if ($name === '' && $email === '' && $phone === '' && $pnr === '') {
            continue;
        }
        $out[] = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'personnummer' => $pnr,
        ];
    }

    return $out;
}

/**
 * @return list<string>
 */
function jeab_contacts_strict_required(string $flow): array
{
    return match ($flow) {
        'fortnox' => ['name', 'address', 'idnr'],
        'sms' => ['phone'],
        'email', 'offert' => ['email'],
        'ellevio' => ['name', 'idnr', 'address', 'fastighet'],
        'webhook' => ['email'],
        default => ['name'],
    };
}

/**
 * @param array<string, mixed>|null $view
 * @param array<string, mixed>|null $contact
 * @param array<string, mixed> $row
 */
function jeab_contacts_strict_field_ok(string $field, ?array $view, ?array $contact, array $row): bool
{
    if (!is_array($view) || !is_array($contact)) {
        return false;
    }
    if ($field === 'name') {
        return trim((string) ($view['name'] ?? '')) !== '';
    }
    if ($field === 'email') {
        $email = trim((string) ($view['email'] ?? ''));

        return $email !== '' && str_contains($email, '@');
    }
    if ($field === 'phone') {
        return trim((string) ($view['phone'] ?? '')) !== '';
    }
    if ($field === 'address') {
        return trim((string) ($view['street'] ?? '')) !== '' || trim((string) ($view['display'] ?? '')) !== '';
    }
    if ($field === 'fastighet') {
        return trim((string) ($contact['fastighetsbeteckning'] ?? '')) !== '';
    }
    if ($field === 'idnr') {
        $pnr = function_exists('jeab_contacts_sole_contact_person_pnr')
            ? jeab_contacts_sole_contact_person_pnr($contact)
            : '';
        if (trim($pnr) !== '') {
            return true;
        }

        return function_exists('jeab_ordrar_order_owner_pnr') && jeab_ordrar_order_owner_pnr($row) !== '';
    }

    return false;
}

function jeab_contacts_strict_error(string $flow, array $missing): string
{
    $labels = [
        'name' => 'namn',
        'email' => 'e-post',
        'phone' => 'telefon',
        'address' => 'adress',
        'fastighet' => 'fastighet',
        'idnr' => 'personnummer',
    ];
    $words = [];
    foreach ($missing as $field) {
        $words[] = $labels[$field] ?? $field;
    }
    $what = $words === [] ? 'uppgifter' : implode(', ', $words);
    $where = match ($flow) {
        'fortnox' => 'Fortnox',
        'sms' => 'SMS',
        'email' => 'mejlet',
        'offert' => 'offerten',
        'ellevio' => 'Ellevio-fullmakten',
        'webhook' => 'webhooken',
        default => 'utskicket',
    };

    return 'Kontakten saknar ' . $what . '. ' . $where . ' skickades inte.';
}

/**
 * @param list<string> $missing
 */
function jeab_contacts_strict_log(string $flow, int $id, array $missing, bool $unlinked): void
{
    $safe = [];
    foreach ($missing as $field) {
        if (!is_string($field) || $field === '' || str_contains(strtolower($field), 'pnr')) {
            continue;
        }
        $safe[] = $field === 'idnr' ? 'idnr' : $field;
    }
    $seenKey = $flow . '|' . $id . '|' . ($unlinked ? 'unlinked' : 'blank') . '|' . implode(',', $safe);
    if (!isset($GLOBALS['_JEAB_CONTACTS_STRICT_LOG']) || !is_array($GLOBALS['_JEAB_CONTACTS_STRICT_LOG'])) {
        $GLOBALS['_JEAB_CONTACTS_STRICT_LOG'] = [];
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_STRICT_LOG'][$seenKey])) {
        return;
    }
    $GLOBALS['_JEAB_CONTACTS_STRICT_LOG'][$seenKey] = true;
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $state = $unlinked ? 'saknar kontakt' : 'tomma fält';
    jeab_log_app_error(
        'contacts_sole',
        'Utgående ' . $flow . ' blockerades. Rad ' . $id . ' ' . $state . ': ' . implode(',', $safe) . '.',
        [
            'fingerprint' => hash('sha256', 'contacts_strict|' . $seenKey),
            'dedupe_seconds' => 86400,
            'no_webhook' => true,
            'skip_request' => true,
        ]
    );
}

/**
 * Tom sträng när utskicket får gå. Annars felet till anroparen. Loggar utan värden.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_strict_block(array $row, string $flow): string
{
    if (!jeab_contacts_strict() || !function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return '';
    }
    if ($flow === 'fortnox' && function_exists('jeab_ordrar_fortnox_forget_contact')) {
        jeab_ordrar_fortnox_forget_contact($row);
    }
    $contact = function_exists('jeab_contacts_sole_recipient_contact')
        ? jeab_contacts_sole_recipient_contact($row)
        : null;
    $view = is_array($contact) && function_exists('jeab_contacts_sole_view_from_contact')
        ? jeab_contacts_sole_view_from_contact($contact)
        : null;
    $missing = [];
    foreach (jeab_contacts_strict_required($flow) as $field) {
        if (!jeab_contacts_strict_field_ok($field, $view, is_array($contact) ? $contact : null, $row)) {
            $missing[] = $field;
        }
    }
    if ($missing === []) {
        return '';
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    jeab_contacts_strict_log($flow, $id, $missing, !is_array($contact));

    return jeab_contacts_strict_error($flow, $missing);
}

/**
 * @param array<string, mixed> $row
 * @return array{name:string,email:string,phone:string,street:string,postal:string,city:string,display:string,fastighet:string,owners:list<array<string,mixed>>}
 */
function jeab_contacts_gap_copy(array $row): array
{
    $copy = function_exists('jeab_contacts_sole_recipient_copy')
        ? jeab_contacts_sole_recipient_copy($row)
        : ['name' => '', 'email' => '', 'phone' => '', 'street' => '', 'postal' => '', 'city' => '', 'display' => '', 'owners' => []];
    if (trim((string) ($copy['street'] ?? '')) === '' && trim((string) ($copy['display'] ?? '')) !== '' && function_exists('jeab_address_parts')) {
        $parts = jeab_address_parts((string) $copy['display']);
        if (trim((string) ($parts['street'] ?? '')) !== '') {
            $copy['street'] = trim((string) $parts['street']);
        }
        if (trim((string) ($copy['postal'] ?? '')) === '' && trim((string) ($parts['postal'] ?? '')) !== '') {
            $copy['postal'] = trim((string) $parts['postal']);
        }
        if (trim((string) ($copy['city'] ?? '')) === '' && trim((string) ($parts['city'] ?? '')) !== '') {
            $copy['city'] = trim((string) $parts['city']);
        }
    }
    $copy['fastighet'] = trim((string) ($row['fastighetsbeteckning'] ?? ''));

    return $copy;
}

/**
 * Samma telefon som jeab_kontakt_row_phone läser: kontaktägarens nummer,
 * normaliserat till +46. Hela fältet först, annars första numret i texten.
 * Tomt när inget nummer kan läsas tillbaka.
 */
function jeab_contacts_gap_phone(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $whole = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($raw) : '';
    if ($whole !== '') {
        return $whole;
    }
    if (!function_exists('jeab_find_phones')) {
        return '';
    }
    foreach (jeab_find_phones($raw) as $hit) {
        $norm = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone((string) $hit) : '';
        if ($norm !== '') {
            return $norm;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $row
 * @return array{fields:list<string>,patch:array<string,string>,owners:list<array<string,mixed>>,skipped_duplicate_email:string}
 */
function jeab_contacts_gap_fields(array $contact, array $row): array
{
    $view = function_exists('jeab_contacts_sole_view_from_contact')
        ? jeab_contacts_sole_view_from_contact($contact)
        : [];
    $copy = jeab_contacts_gap_copy($row);
    $fields = [];
    $patch = [];
    $pairs = [
        'name' => [(string) ($view['name'] ?? ''), (string) ($copy['name'] ?? '')],
        'email' => [(string) ($view['email'] ?? ''), (string) ($copy['email'] ?? '')],
        'phone' => [
            jeab_contacts_gap_phone((string) ($view['phone'] ?? '')),
            jeab_contacts_gap_phone((string) ($copy['phone'] ?? '')),
        ],
        'street' => [(string) ($view['street'] ?? ''), (string) ($copy['street'] ?? '')],
        'postal' => [(string) ($view['postal'] ?? ''), (string) ($copy['postal'] ?? '')],
        'city' => [(string) ($view['city'] ?? ''), (string) ($copy['city'] ?? '')],
        'fastighet' => [trim((string) ($contact['fastighetsbeteckning'] ?? '')), (string) ($copy['fastighet'] ?? '')],
    ];
    foreach ($pairs as $field => [$have, $want]) {
        if (trim($have) !== '' || trim($want) === '') {
            continue;
        }
        $fields[] = $field;
        $patch[$field] = trim($want);
    }
    $haveNames = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner) || !function_exists('jeab_contacts_sole_name_key')) {
            continue;
        }
        $key = jeab_contacts_sole_name_key((string) ($owner['name'] ?? ''));
        if ($key !== '') {
            $haveNames[$key] = true;
        }
    }
    $addOwners = [];
    foreach (is_array($copy['owners'] ?? null) ? $copy['owners'] : [] as $owner) {
        if (!is_array($owner) || !function_exists('jeab_contacts_sole_name_key')) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $key = jeab_contacts_sole_name_key($name);
        if ($key === '' || isset($haveNames[$key])) {
            continue;
        }
        $haveNames[$key] = true;
        $addOwners[] = [
            'name' => $name,
            'email' => trim((string) ($owner['email'] ?? '')),
            'phone' => trim((string) ($owner['phone'] ?? '')),
            'address' => trim((string) ($owner['address'] ?? $owner['adress'] ?? '')),
            'personnummer' => '',
        ];
    }
    if ($addOwners !== []) {
        $fields[] = 'owners';
    }
    $skippedEmail = '';
    if (in_array('email', $fields, true)) {
        $skippedEmail = jeab_contacts_gap_duplicate_email($contact, (string) ($patch['email'] ?? ''));
        if ($skippedEmail !== '') {
            $fields = array_values(array_filter($fields, static fn(string $field): bool => $field !== 'email'));
            unset($patch['email']);
        }
    }

    return ['fields' => $fields, 'patch' => $patch, 'owners' => $addOwners, 'skipped_duplicate_email' => $skippedEmail];
}

/**
 * Annan kontakt har redan mejlet och en annan gata och postnummer.
 * Tom sträng när påfyllnaden får ta mejlet.
 *
 * @param array<string, mixed> $contact
 */
function jeab_contacts_gap_duplicate_email(array $contact, string $email): string
{
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : strtolower(trim($email));
    if ($email === '' || !function_exists('jeab_kontakt_channel_address_key') || !function_exists('jeab_kontakter_load')) {
        return '';
    }
    $mine = jeab_kontakt_channel_address_key($contact);
    $self = trim((string) ($contact['id'] ?? ''));
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $other = trim((string) ($row['id'] ?? $id));
        if ($other === '' || $other === $self) {
            continue;
        }
        $emails = function_exists('jeab_kontakt_dup_emails')
            ? jeab_kontakt_dup_emails($row)
            : (function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($row) : []);
        if (!in_array($email, $emails, true)) {
            continue;
        }
        $theirs = jeab_kontakt_channel_address_key($row);
        if ($theirs !== '' && $theirs !== $mine) {
            return $other;
        }
    }

    return '';
}

/**
 * @return Generator<int, array{table:string,id:int,contact_id:string,fields:list<string>,skipped_duplicate_email:string}>
 */
function jeab_contacts_gap_iterate(JeabStore $store): Generator
{
    $sources = [
        'reminders' => $store->listReminders(true),
        'bookings' => $store->listAllBookings(),
        'orders' => function_exists('jeab_purchase_all') ? jeab_purchase_all() : [],
    ];
    foreach ($sources as $table => $rows) {
        if (!is_array($rows)) {
            continue;
        }
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = $table === 'orders' ? (int) ($row['reminder_id'] ?? 0) : (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $indexed[$id] = $row;
        }
        ksort($indexed);
        foreach ($indexed as $id => $row) {
            $contactId = trim((string) ($row['contact_id'] ?? ''));
            if ($contactId === '' || !function_exists('jeab_contacts_sole_linked_contact')) {
                continue;
            }
            $contact = jeab_contacts_sole_linked_contact($contactId);
            if (!is_array($contact)) {
                continue;
            }
            $gap = jeab_contacts_gap_fields($contact, $row);
            $skipped = (string) ($gap['skipped_duplicate_email'] ?? '');
            if ($gap['fields'] === [] && $skipped === '') {
                continue;
            }
            yield [
                'table' => $table,
                'id' => $id,
                'contact_id' => $contactId,
                'fields' => $gap['fields'],
                'skipped_duplicate_email' => $skipped,
            ];
        }
    }
}

/**
 * @return array{reminders:int,bookings:int,orders:int,rows:int,fields:array<string,int>}
 */
function jeab_contacts_gap_summary(JeabStore $store): array
{
    $counts = ['reminders' => 0, 'bookings' => 0, 'orders' => 0, 'rows' => 0, 'fields' => []];
    foreach (jeab_contacts_gap_iterate($store) as $item) {
        if ($item['fields'] === []) {
            continue;
        }
        $table = (string) $item['table'];
        if (isset($counts[$table])) {
            $counts[$table]++;
        }
        $counts['rows']++;
        foreach ($item['fields'] as $field) {
            $counts['fields'][$field] = (int) ($counts['fields'][$field] ?? 0) + 1;
        }
    }
    ksort($counts['fields']);

    return $counts;
}

function jeab_contacts_strict_report(JeabStore $store): array
{
    $gaps = jeab_contacts_gap_summary($store);

    return [
        'ok' => true,
        'read_only' => true,
        'contacts_strict' => jeab_contacts_strict() ? 1 : 0,
        'gaps' => $gaps,
    ];
}

/**
 * @return array{table:string,id:int}|null
 */
function jeab_contacts_gap_after(array $input): ?array
{
    if (!array_key_exists('after_id', $input) || $input['after_id'] === null || $input['after_id'] === '') {
        return ['table' => '', 'id' => 0];
    }
    $raw = trim((string) $input['after_id']);
    if (preg_match('/^(reminders|bookings|orders):(\d+)$/', $raw, $match) !== 1) {
        return null;
    }

    return ['table' => $match[1], 'id' => (int) $match[2]];
}

function jeab_contacts_gap_limit(array $input): int
{
    if (!array_key_exists('limit', $input)) {
        return 50;
    }
    $limit = (int) $input['limit'];
    if ($limit < 1) {
        return 1;
    }
    if ($limit > 200) {
        return 200;
    }

    return $limit;
}

function jeab_contacts_gap_past(array $after, string $table, int $id): bool
{
    $order = ['reminders' => 0, 'bookings' => 1, 'orders' => 2];
    if (($after['table'] ?? '') === '') {
        return false;
    }
    $left = $order[$after['table']] ?? -1;
    $right = $order[$table] ?? 9;
    if ($right < $left) {
        return true;
    }
    if ($right > $left) {
        return false;
    }

    return $id <= (int) ($after['id'] ?? 0);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_gap_reload(JeabStore $store, string $table, int $id): array
{
    if ($table === 'reminders') {
        $row = $store->getReminder($id);

        return is_array($row) ? $row : [];
    }
    if ($table === 'bookings') {
        $row = $store->getBooking($id);

        return is_array($row) ? $row : [];
    }
    if (function_exists('jeab_purchase_get')) {
        $row = jeab_purchase_get($id);

        return is_array($row) ? $row : [];
    }

    return [];
}

/**
 * @param array<string, mixed> $contact
 * @return array<string, mixed>
 */
function jeab_contacts_gap_snapshot(array $contact): array
{
    $view = function_exists('jeab_contacts_sole_view_from_contact')
        ? jeab_contacts_sole_view_from_contact($contact)
        : [];

    return [
        'name' => (string) ($view['name'] ?? ''),
        'email' => (string) ($view['email'] ?? ''),
        'phone' => (string) ($view['phone'] ?? ''),
        'street' => (string) ($view['street'] ?? ''),
        'postal' => (string) ($view['postal'] ?? ''),
        'city' => (string) ($view['city'] ?? ''),
        'fastighet' => trim((string) ($contact['fastighetsbeteckning'] ?? '')),
        'owners' => is_array($contact['owners'] ?? null) ? $contact['owners'] : [],
    ];
}

/**
 * @param array<string, mixed> $undoRow
 */
function jeab_contacts_gap_restore(array $undoRow): bool
{
    $contactId = trim((string) ($undoRow['entity_id'] ?? ''));
    $before = json_decode((string) ($undoRow['before_json'] ?? ''), true);
    if ($contactId === '' || !is_array($before) || !function_exists('jeab_kontakt_get')) {
        return false;
    }
    $live = jeab_kontakt_get($contactId);
    if (!is_array($live)) {
        return false;
    }
    $ok = true;
    if (array_key_exists('owners', $before) && is_array($before['owners']) && function_exists('jeab_fastighet_write_contact') && function_exists('jeab_contacts_sole_undo_owners_keep_pnr')) {
        $current = [];
        foreach (is_array($live['owners'] ?? null) ? $live['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $current[] = $owner;
            }
        }
        $slice = [
            'id' => $contactId,
            'owners' => jeab_contacts_sole_undo_owners_keep_pnr(
                array_values(array_filter($before['owners'], 'is_array')),
                $current
            ),
        ];
        if (array_key_exists('fastighet', $before)) {
            $slice['fastighetsbeteckning'] = (string) $before['fastighet'];
        }
        $ok = jeab_fastighet_write_contact($contactId, $slice);
        $live = jeab_kontakt_get($contactId);
        if (!is_array($live)) {
            return false;
        }
    } elseif (array_key_exists('fastighet', $before) && function_exists('jeab_fastighet_write_contact')) {
        $ok = jeab_fastighet_write_contact($contactId, [
            'id' => $contactId,
            'fastighetsbeteckning' => (string) $before['fastighet'],
        ]);
    }
    $patch = [];
    foreach (['name', 'email', 'phone', 'street', 'postal', 'city'] as $key) {
        if (array_key_exists($key, $before)) {
            $patch[$key] = (string) $before[$key];
        }
    }
    if ($patch !== [] && function_exists('jeab_reminder_save_contact_customer')) {
        $saved = jeab_reminder_save_contact_customer($contactId, $patch);
        $ok = is_array($saved) && $ok;
    }
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return $ok;
}

/**
 * @param array<string, string> $patch
 * @param list<array<string, mixed>> $owners
 */
function jeab_contacts_gap_apply_contact(string $contactId, array $patch, array $owners): bool
{
    $scalar = $patch;
    unset($scalar['fastighet']);
    $phone = '';
    if (array_key_exists('phone', $scalar)) {
        $phone = jeab_contacts_gap_phone((string) $scalar['phone']);
        if ($phone === '') {
            unset($scalar['phone']);
        } else {
            $scalar['phone'] = $phone;
        }
    }
    $ok = true;
    if ($scalar !== [] && function_exists('jeab_reminder_save_contact_customer')) {
        $saved = jeab_reminder_save_contact_customer($contactId, $scalar);
        $ok = is_array($saved);
    }
    if (!$ok) {
        return false;
    }
    $live = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
    if (!is_array($live) || !function_exists('jeab_fastighet_write_contact')) {
        return $ok && $owners === [] && !array_key_exists('fastighet', $patch);
    }
    $slice = ['id' => $contactId];
    if (array_key_exists('fastighet', $patch) && trim((string) ($live['fastighetsbeteckning'] ?? '')) === '') {
        $slice['fastighetsbeteckning'] = $patch['fastighet'];
    }
    if ($owners !== []) {
        $current = [];
        foreach (is_array($live['owners'] ?? null) ? $live['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $current[] = $owner;
            }
        }
        $have = [];
        foreach ($current as $owner) {
            if (!function_exists('jeab_contacts_sole_name_key')) {
                continue;
            }
            $key = jeab_contacts_sole_name_key((string) ($owner['name'] ?? ''));
            if ($key !== '') {
                $have[$key] = true;
            }
        }
        foreach ($owners as $owner) {
            $key = function_exists('jeab_contacts_sole_name_key')
                ? jeab_contacts_sole_name_key((string) ($owner['name'] ?? ''))
                : '';
            if ($key === '' || isset($have[$key])) {
                continue;
            }
            $have[$key] = true;
            $current[] = $owner;
        }
        $slice['owners'] = $current;
    }
    if (count($slice) > 1) {
        $ok = jeab_fastighet_write_contact($contactId, $slice) && $ok;
    }
    if ($phone !== '' && function_exists('jeab_kontakt_row_phone') && function_exists('jeab_reminder_save_contact_customer')) {
        $check = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
        $have = is_array($check) ? jeab_kontakt_row_phone($check) : '';
        if ($have !== $phone) {
            $saved = jeab_reminder_save_contact_customer($contactId, ['phone' => $phone]);
            $ok = is_array($saved) && $ok;
        }
    }
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return $ok;
}

/**
 * Samma rader och samma contact_id som före skrivningen, även tomma länkar.
 *
 * @param array<string, mixed> $beforeLinks
 */
function jeab_contacts_gap_links_kept(JeabStore $store, array $beforeLinks): bool
{
    if (!function_exists('jeab_contacts_sole_link_map') || !function_exists('jeab_contacts_sole_cid')) {
        return false;
    }
    $now = jeab_contacts_sole_link_map($store);
    foreach (['reminders', 'orders', 'bookings'] as $kind) {
        $prev = is_array($beforeLinks[$kind] ?? null) ? $beforeLinks[$kind] : [];
        $have = is_array($now[$kind] ?? null) ? $now[$kind] : [];
        if (count($prev) !== count($have)) {
            return false;
        }
        foreach ($prev as $id => $cid) {
            $key = (string) $id;
            if (!array_key_exists($key, $have)) {
                return false;
            }
            if (jeab_contacts_sole_cid($have[$key]) !== jeab_contacts_sole_cid($cid)) {
                return false;
            }
        }
    }

    return true;
}

function jeab_contacts_gap_wants_undo(array $input): bool
{
    $value = $input['undo'] ?? null;

    return $value === true || $value === 1 || $value === '1';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_gap_fill(array $input, JeabStore $store): array
{
    if (jeab_contacts_gap_wants_undo($input)) {
        $undoInput = $input;
        if (!array_key_exists('dry', $undoInput)) {
            $undoInput['dry'] = 0;
        }

        return function_exists('jeab_contacts_sole_undo')
            ? jeab_contacts_sole_undo($undoInput, $store)
            : ['ok' => false, 'error' => 'Ingen ånger.'];
    }
    $after = jeab_contacts_gap_after($input);
    if ($after === null) {
        return ['ok' => false, 'error' => 'after_id ska vara reminders:id, bookings:id eller orders:id.', 'dry' => true, 'written' => false];
    }
    $limit = jeab_contacts_gap_limit($input);
    $dry = function_exists('jeab_contacts_sole_wants_dry') ? jeab_contacts_sole_wants_dry($input) : true;
    $before = function_exists('jeab_contacts_sole_counts') ? jeab_contacts_sole_counts($store) : ['reminders' => 0, 'orders' => 0, 'bookings' => 0];
    $linksBefore = function_exists('jeab_contacts_sole_link_map') ? jeab_contacts_sole_link_map($store) : [];
    $page = [];
    $skips = [];
    $seen = 0;
    $next = '';
    foreach (jeab_contacts_gap_iterate($store) as $item) {
        if (jeab_contacts_gap_past($after, (string) $item['table'], (int) $item['id'])) {
            continue;
        }
        $skipOther = trim((string) ($item['skipped_duplicate_email'] ?? ''));
        $hasFields = $item['fields'] !== [];
        if (!$hasFields && $skipOther === '') {
            continue;
        }
        if ($seen >= $limit) {
            $last = $page !== [] ? $page[count($page) - 1] : ($skips !== [] ? $skips[count($skips) - 1] : null);
            if (is_array($last)) {
                $next = (string) $last['table'] . ':' . (string) $last['id'];
            }
            break;
        }
        $seen++;
        if ($skipOther !== '') {
            $skips[] = [
                'table' => (string) $item['table'],
                'id' => (int) $item['id'],
                'contact_id' => (string) $item['contact_id'],
                'other_contact_id' => $skipOther,
            ];
        }
        if (!$hasFields) {
            continue;
        }
        $page[] = [
            'table' => (string) $item['table'],
            'id' => (int) $item['id'],
            'contact_id' => (string) $item['contact_id'],
            'fields' => array_values($item['fields']),
        ];
    }
    $base = [
        'ok' => true,
        'action' => 'kontakter.gap_fill',
        'dry' => $dry,
        'written' => false,
        'counts' => ['before' => $before, 'after' => $before],
        'links_same' => true,
        'rows' => $page,
        'gap_rows' => count($page),
        'skipped_duplicate_email' => $skips,
        'next_after_id' => $next,
        'limit' => $limit,
    ];
    if ($dry || $page === []) {
        return $base;
    }
    $batch = function_exists('jeab_contacts_sole_batch') ? jeab_contacts_sole_batch() : bin2hex(random_bytes(8));
    $seenContact = [];
    $seq = 1;
    $fieldCount = 0;
    $writtenRows = [];
    foreach ($page as $item) {
        $row = jeab_contacts_gap_reload($store, (string) $item['table'], (int) $item['id']);
        $contactId = trim((string) ($row['contact_id'] ?? ''));
        if ($contactId === '' || $contactId !== (string) $item['contact_id'] || !function_exists('jeab_kontakt_get')) {
            return ['ok' => false, 'error' => 'En länk saknas. Inget skrevs.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
        }
        $contact = jeab_kontakt_get($contactId);
        if (!is_array($contact)) {
            return ['ok' => false, 'error' => 'Kontakten saknas. Inget skrevs.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
        }
        $gap = jeab_contacts_gap_fields($contact, $row);
        if ($gap['fields'] === []) {
            continue;
        }
        if (!isset($seenContact[$contactId])) {
            if (!function_exists('jeab_contacts_sole_undo_add') || !jeab_contacts_sole_undo_add($batch, $seq, 'gap', 'contact', $contactId, jeab_contacts_gap_snapshot($contact), ['fields' => $gap['fields']])) {
                return ['ok' => false, 'error' => 'Ångerloggen kunde inte skrivas. Inget skrevs.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
            }
            $seenContact[$contactId] = true;
            $seq++;
        }
        if (!jeab_contacts_gap_apply_contact($contactId, $gap['patch'], $gap['owners'])) {
            if (function_exists('jeab_contacts_sole_undo')) {
                jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch, 'undo' => 1], $store);
            }

            return ['ok' => false, 'error' => 'Kontakten kunde inte fyllas. Ändringen ångrades.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
        }
        $fieldCount += count($gap['fields']);
        $writtenRows[] = [
            'table' => (string) $item['table'],
            'id' => (int) $item['id'],
            'contact_id' => $contactId,
            'fields' => $gap['fields'],
        ];
        if (function_exists('jeab_contacts_sole_cache_forget')) {
            jeab_contacts_sole_cache_forget($contactId);
        }
    }
    $afterCounts = function_exists('jeab_contacts_sole_counts') ? jeab_contacts_sole_counts($store) : $before;
    $linksAfter = function_exists('jeab_contacts_sole_link_map') ? jeab_contacts_sole_link_map($store) : [];
    $linksSame = jeab_contacts_gap_links_kept($store, $linksBefore);
    $countsSame = (int) ($before['reminders'] ?? -1) === (int) ($afterCounts['reminders'] ?? -2)
        && (int) ($before['orders'] ?? -1) === (int) ($afterCounts['orders'] ?? -2)
        && (int) ($before['bookings'] ?? -1) === (int) ($afterCounts['bookings'] ?? -2);
    unset($linksAfter);
    if (!$linksSame || !$countsSame) {
        if (function_exists('jeab_contacts_sole_undo')) {
            jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch, 'undo' => 1], $store);
        }

        return [
            'ok' => false,
            'error' => 'En länk eller ett antal rader ändrades. Inget skrevs.',
            'dry' => false,
            'written' => false,
            'batch_id' => $batch,
            'counts' => ['before' => $before, 'after' => $afterCounts],
            'links_same' => false,
        ];
    }
    if ($writtenRows !== [] && function_exists('jeab_log_app_error')) {
        jeab_log_app_error(
            'contacts_sole',
            'kontakter.gap_fill skrev ' . $fieldCount . ' fält på ' . count($seenContact) . ' kontakter. batch ' . $batch . '.',
            [
                'fingerprint' => hash('sha256', 'gap_fill|' . $batch),
                'dedupe_seconds' => 86400,
                'no_webhook' => true,
                'skip_request' => true,
            ]
        );
    }

    return [
        'ok' => true,
        'action' => 'kontakter.gap_fill',
        'dry' => false,
        'written' => $writtenRows !== [],
        'batch_id' => $batch,
        'counts' => ['before' => $before, 'after' => $afterCounts],
        'links_same' => true,
        'rows' => $writtenRows,
        'gap_rows' => count($writtenRows),
        'skipped_duplicate_email' => $skips,
        'fields_written' => $fieldCount,
        'contacts_written' => count($seenContact),
        'next_after_id' => $next,
        'limit' => $limit,
    ];
}

function jeab_contacts_inbox_email_norm(string $email): string
{
    $email = trim($email);
    if (preg_match('/<([^>]+)>/', $email, $m) === 1) {
        $email = trim($m[1]);
    }
    $email = function_exists('jeab_norm_email') ? jeab_norm_email($email) : strtolower($email);

    return str_contains($email, '@') ? $email : '';
}

/** Egen domän eller personalens brevlåda. Den adressen är aldrig en kund. */
function jeab_contacts_inbox_email_is_own(string $email): bool
{
    $email = jeab_contacts_inbox_email_norm($email);

    return $email !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email);
}

/** En adress som får användas som kundnyckel. Egna domäner och blockerade avsändare räknas inte. */
function jeab_contacts_inbox_email_is_customer(string $email): bool
{
    $email = jeab_contacts_inbox_email_norm($email);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    if (jeab_contacts_inbox_email_is_own($email)) {
        return false;
    }
    if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email)) {
        return false;
    }

    return true;
}

/**
 * Kundens e-post och telefon i tråden. Avsändare på egen domän ingår inte.
 *
 * @param array<string, mixed> $thread
 * @return array{emails:list<string>,phones:list<string>,internal_sender:bool}
 */
function jeab_contacts_inbox_thread_signals(array $thread): array
{
    $emails = [];
    $phones = [];
    $sawInternalFrom = false;
    $sawExternalFrom = false;
    $addEmail = static function (string $raw, bool $fromHeader) use (&$emails, &$sawInternalFrom, &$sawExternalFrom): void {
        $email = jeab_contacts_inbox_email_norm($raw);
        if ($email === '') {
            return;
        }
        if (jeab_contacts_inbox_email_is_own($email)) {
            if ($fromHeader) {
                $sawInternalFrom = true;
            }

            return;
        }
        if (!jeab_contacts_inbox_email_is_customer($email)) {
            return;
        }
        if ($fromHeader) {
            $sawExternalFrom = true;
        }
        $emails[$email] = $email;
    };
    $addPhone = static function (string $raw) use (&$phones): void {
        $phone = function_exists('jeab_norm_phone') ? jeab_norm_phone($raw) : '';
        if ($phone === '' && function_exists('jeab_inkorg_phone_norm')) {
            $phone = jeab_inkorg_phone_norm($raw);
        }
        if ($phone !== '') {
            $phones[$phone] = $phone;
        }
    };
    $addText = static function (string $text) use ($addEmail, $addPhone): void {
        if ($text === '') {
            return;
        }
        if (strlen($text) > 20000) {
            $text = substr($text, 0, 20000);
        }
        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $all)) {
            foreach ($all[0] as $cand) {
                $addEmail((string) $cand, false);
            }
        }
        if (function_exists('jeab_inkorg_find_mobile')) {
            $addPhone(jeab_inkorg_find_mobile($text));
        }
    };
    $addEmail(jeab_contacts_inbox_contact_email($thread), false);
    $addEmail(jeab_contacts_inbox_first_text((string) ($thread['customer_email'] ?? ''), (string) ($thread['email'] ?? '')), false);
    $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
    $addEmail(jeab_contacts_inbox_first_text((string) ($lead['email'] ?? '')), false);
    $addPhone(jeab_contacts_inbox_contact_phone($thread));
    $addPhone(jeab_contacts_inbox_first_text(
        (string) ($thread['phone'] ?? ''),
        (string) ($thread['customer_phone'] ?? ''),
        (string) ($lead['phone'] ?? '')
    ));
    $msgs = [];
    foreach ((array) ($thread['messages'] ?? []) as $msg) {
        if (is_array($msg)) {
            $msgs[] = $msg;
        }
    }
    if ($msgs === []) {
        foreach (['first', 'last'] as $key) {
            if (is_array($thread[$key] ?? null)) {
                $msgs[] = $thread[$key];
            }
        }
    }
    foreach ($msgs as $msg) {
        $src = (string) ($msg['source'] ?? '');
        $from = (string) ($msg['from'] ?? '');
        $own = str_starts_with($src, 'outbound') || jeab_contacts_inbox_email_is_own($from);
        if ($own && jeab_contacts_inbox_email_norm($from) !== '') {
            $sawInternalFrom = true;
        } else {
            $addEmail($from, true);
        }
        if ($own) {
            $addEmail((string) ($msg['to'] ?? ''), false);
            $addText((string) ($msg['body'] ?? ''));
            $addText((string) ($msg['body_text'] ?? ''));
        }
        $addPhone((string) ($msg['phone'] ?? ''));
    }
    if (jeab_contacts_inbox_email_is_own((string) ($thread['customer_email'] ?? $thread['email'] ?? ''))) {
        $sawInternalFrom = true;
    }

    return [
        'emails' => array_values($emails),
        'phones' => array_values($phones),
        'internal_sender' => $sawInternalFrom && !$sawExternalFrom,
    ];
}

/**
 * @param array<string, mixed> $contact
 * @return list<string>
 */
function jeab_contacts_inbox_contact_emails(array $contact): array
{
    $raw = function_exists('jeab_contacts_sole_contact_emails')
        ? jeab_contacts_sole_contact_emails($contact)
        : [(string) ($contact['email'] ?? '')];
    $out = [];
    foreach ($raw as $email) {
        $email = jeab_contacts_inbox_email_norm((string) $email);
        if ($email !== '') {
            $out[$email] = $email;
        }
    }
    foreach ((array) ($contact['emails'] ?? []) as $email) {
        if (!is_scalar($email)) {
            continue;
        }
        $email = jeab_contacts_inbox_email_norm((string) $email);
        if ($email !== '') {
            $out[$email] = $email;
        }
    }

    return array_values($out);
}

/**
 * @param array<string, mixed> $contact
 * @return list<string>
 */
function jeab_contacts_inbox_contact_phones(array $contact): array
{
    $raw = [(string) ($contact['phone'] ?? ''), (string) ($contact['mobile'] ?? '')];
    if (function_exists('jeab_kontakt_row_phone')) {
        $raw[] = jeab_kontakt_row_phone($contact);
    }
    foreach ((array) ($contact['owners'] ?? []) as $owner) {
        if (is_array($owner)) {
            $raw[] = (string) ($owner['phone'] ?? '');
        }
    }
    $out = [];
    foreach ($raw as $phone) {
        $phone = function_exists('jeab_norm_phone') ? jeab_norm_phone($phone) : '';
        if ($phone !== '') {
            $out[$phone] = $phone;
        }
    }

    return array_values($out);
}

/** Kontakten är personal när varje adress ligger på en egen domän. */
function jeab_contacts_inbox_contact_is_internal(array $contact): bool
{
    $emails = jeab_contacts_inbox_contact_emails($contact);
    if ($emails === []) {
        return false;
    }
    foreach ($emails as $email) {
        if (jeab_contacts_inbox_email_is_customer($email)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $contact
 * @param array{emails:list<string>,phones:list<string>,internal_sender:bool} $signals
 */
function jeab_contacts_inbox_contact_matches(array $contact, array $signals): bool
{
    if (jeab_contacts_inbox_contact_is_internal($contact)) {
        return false;
    }
    $wantEmail = array_fill_keys($signals['emails'], true);
    foreach (jeab_contacts_inbox_contact_emails($contact) as $email) {
        if (isset($wantEmail[$email])) {
            return true;
        }
    }
    $wantPhone = array_fill_keys($signals['phones'], true);
    foreach (jeab_contacts_inbox_contact_phones($contact) as $phone) {
        if (isset($wantPhone[$phone])) {
            return true;
        }
    }

    return false;
}

/**
 * Sparad contact_id används bara när den hör till kunden.
 * Intern avsändare matchas på kundens e-post eller telefon, annars ingen kontakt.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed>|null $linked
 * @param array<string, array<string, mixed>> $byEmail
 * @param array<string, array<string, mixed>> $byPhone
 * @return array<string, mixed>|null
 */
function jeab_contacts_inbox_pick(?array $linked, array $thread, array $byEmail, array $byPhone): ?array
{
    $signals = jeab_contacts_inbox_thread_signals($thread);
    $contact = is_array($linked) ? $linked : null;
    if (is_array($contact) && jeab_contacts_inbox_contact_is_internal($contact)) {
        $contact = null;
    }
    if (is_array($contact) && !empty($signals['internal_sender']) && !jeab_contacts_inbox_contact_matches($contact, $signals)) {
        $contact = null;
    }
    if (!is_array($contact)) {
        if (!empty($signals['internal_sender'])) {
            foreach ($signals['emails'] as $email) {
                $hit = $byEmail[$email] ?? null;
                if (is_array($hit) && !jeab_contacts_inbox_contact_is_internal($hit)) {
                    $contact = $hit;
                    break;
                }
            }
            if (!is_array($contact)) {
                foreach ($signals['phones'] as $phone) {
                    $hit = $byPhone[$phone] ?? null;
                    if (is_array($hit) && !jeab_contacts_inbox_contact_is_internal($hit)) {
                        $contact = $hit;
                        break;
                    }
                }
            }
        } else {
            $email = jeab_contacts_inbox_email_norm((string) ($thread['customer_email'] ?? $thread['email'] ?? ''));
            $hit = ($email !== '' && isset($byEmail[$email]) && is_array($byEmail[$email])) ? $byEmail[$email] : null;
            if (is_array($hit) && !jeab_contacts_inbox_contact_is_internal($hit)) {
                $contact = $hit;
            }
        }
    }
    if (is_array($contact) && jeab_contacts_inbox_contact_is_internal($contact)) {
        return null;
    }

    return is_array($contact) ? $contact : null;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function jeab_contacts_inbox_apply_labels(array $rows): array
{
    if ($rows === []) {
        return $rows;
    }
    $threadIds = [];
    $contactIds = [];
    $emails = [];
    $phones = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['contact_id'] ?? ''));
        if ($cid !== '') {
            $contactIds[$cid] = $cid;
        }
        $tid = trim((string) ($row['id'] ?? ''));
        if ($tid !== '') {
            $threadIds[$tid] = $tid;
        }
        $signals = jeab_contacts_inbox_thread_signals($row);
        foreach ($signals['emails'] as $email) {
            $emails[$email] = $email;
        }
        if (!empty($signals['internal_sender'])) {
            foreach ($signals['phones'] as $phone) {
                $phones[$phone] = $phone;
            }
        }
    }
    $byThread = jeab_contacts_inbox_reminder_contacts(array_keys($threadIds));
    foreach ($byThread as $cid) {
        if ($cid !== '') {
            $contactIds[$cid] = $cid;
        }
    }
    $loaded = function_exists('jeab_contacts_sole_load_ids')
        ? jeab_contacts_sole_load_ids(array_values($contactIds))
        : [];
    $byEmail = jeab_contacts_inbox_by_email(array_keys($emails));
    $byPhone = jeab_contacts_inbox_by_phone(array_keys($phones));
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['contact_id'] ?? ''));
        if ($cid === '') {
            continue;
        }
        $linked = isset($loaded[$cid]) && is_array($loaded[$cid]) ? $loaded[$cid] : null;
        $contact = jeab_contacts_inbox_pick($linked, $row, $byEmail, $byPhone);
        if (!is_array($contact) || !function_exists('jeab_contacts_sole_view_from_contact')) {
            continue;
        }
        $view = jeab_contacts_sole_view_from_contact($contact);
        $row['customer_name'] = (string) ($view['name'] ?? '');
        $row['customer_address'] = trim((string) ($view['street'] ?? ''));
        $row['customer_email'] = (string) ($view['email'] ?? '');
        $row['phone'] = (string) ($view['phone'] ?? '');
        $row['customer_phone'] = (string) ($view['phone'] ?? '');
        $row['customer_city'] = (string) ($view['city'] ?? '');
        $row['ort'] = (string) ($view['city'] ?? '');
        $row['postal'] = (string) ($view['postal'] ?? '');
        $row['postnr'] = (string) ($view['postal_fmt'] ?? '');
        $row['contact_id'] = trim((string) ($contact['id'] ?? ''));
        $row['contact_label'] = 1;
        $rows[$i] = $row;
    }

    return $rows;
}

/**
 * @param list<string> $threadIds
 * @return array<string, string>
 */
function jeab_contacts_inbox_reminder_contacts(array $threadIds): array
{
    $threadIds = array_values(array_filter(array_map(static fn ($id): string => trim((string) $id), $threadIds), static fn (string $id): bool => $id !== ''));
    if ($threadIds === []) {
        return [];
    }
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO) {
        return [];
    }
    $out = [];
    foreach (array_chunk($threadIds, 200) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $stmt = $pdo->prepare('SELECT thread_id, contact_id FROM reminders WHERE thread_id IN (' . $place . ") AND contact_id <> ''");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tid = trim((string) ($row['thread_id'] ?? ''));
                $cid = trim((string) ($row['contact_id'] ?? ''));
                if ($tid !== '' && $cid !== '' && !isset($out[$tid])) {
                    $out[$tid] = $cid;
                }
            }
        } catch (Throwable) {
            return $out;
        }
    }

    return $out;
}

/**
 * @param list<string> $emails
 * @return array<string, array<string, mixed>>
 */
function jeab_contacts_inbox_by_email(array $emails): array
{
    $clean = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string) $email));
        if ($email !== '' && str_contains($email, '@')) {
            $clean[$email] = $email;
        }
    }
    if ($clean === []) {
        return [];
    }
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    if (!$pdo instanceof PDO) {
        return [];
    }
    $out = [];
    foreach (array_chunk(array_values($clean), 200) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $stmt = $pdo->prepare('SELECT id, row_json FROM contacts WHERE LOWER(owner_email) IN (' . $place . ') ORDER BY id');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $decoded['id'] = trim((string) ($decoded['id'] ?? $row['id'] ?? ''));
                $keys = jeab_contacts_inbox_contact_emails($decoded);
                $primary = strtolower(trim(function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($decoded) : (string) ($decoded['email'] ?? '')));
                if ($primary !== '') {
                    array_unshift($keys, $primary);
                }
                foreach ($keys as $email) {
                    $email = jeab_contacts_inbox_email_norm($email);
                    if ($email !== '' && jeab_contacts_inbox_email_is_customer($email) && !isset($out[$email])) {
                        $out[$email] = $decoded;
                    }
                }
            }
        } catch (Throwable) {
            return $out;
        }
    }

    return $out;
}

/**
 * @param list<string> $phones
 * @return array<string, array<string, mixed>>
 */
function jeab_contacts_inbox_by_phone(array $phones): array
{
    $clean = [];
    foreach ($phones as $phone) {
        $phone = function_exists('jeab_norm_phone') ? jeab_norm_phone((string) $phone) : trim((string) $phone);
        if ($phone !== '') {
            $clean[$phone] = $phone;
        }
    }
    if ($clean === []) {
        return [];
    }
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    if (!$pdo instanceof PDO) {
        return [];
    }
    $out = [];
    foreach (array_chunk(array_values($clean), 200) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        try {
            $stmt = $pdo->prepare('SELECT id, row_json FROM contacts WHERE owner_phone IN (' . $place . ') ORDER BY id');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $decoded['id'] = trim((string) ($decoded['id'] ?? $row['id'] ?? ''));
                if (jeab_contacts_inbox_contact_is_internal($decoded)) {
                    continue;
                }
                foreach (jeab_contacts_inbox_contact_phones($decoded) as $phone) {
                    if (!isset($out[$phone])) {
                        $out[$phone] = $decoded;
                    }
                }
            }
        } catch (Throwable) {
            return $out;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $cust
 * @return array<string, mixed>
 */
function jeab_follow_resolve_party(array $thread, array $cust): array
{
    $cid = trim((string) ($thread['contact_id'] ?? $cust['contact_id'] ?? ''));
    $tid = trim((string) ($thread['id'] ?? ''));
    if ($cid === '' && $tid !== '') {
        $byThread = jeab_contacts_inbox_reminder_contacts([$tid]);
        $cid = trim((string) ($byThread[$tid] ?? ''));
    }
    $row = [[
        'id' => $tid,
        'contact_id' => $cid,
        'customer_email' => (string) ($cust['email'] ?? ''),
        'customer_name' => (string) ($cust['name'] ?? ''),
        'phone' => (string) ($cust['phone'] ?? ''),
    ]];
    $labeled = jeab_contacts_inbox_apply_labels($row);
    $one = $labeled[0] ?? [];
    if (empty($one['contact_label'])) {
        return $cust;
    }
    $cust['name'] = (string) ($one['customer_name'] ?? '');
    $cust['email'] = (string) ($one['customer_email'] ?? '');
    $cust['phone'] = (string) ($one['phone'] ?? '');
    $cust['contact_id'] = (string) ($one['contact_id'] ?? '');

    return $cust;
}

/**
 * @param array<string, mixed> $rem
 * @return array{name:string,email:string,phone:string}
 */
function jeab_follow_reminder_party(array $rem): array
{
    $cid = trim((string) ($rem['contact_id'] ?? ''));
    if ($cid !== '' && function_exists('jeab_contacts_sole_linked_contact')) {
        $contact = jeab_contacts_sole_linked_contact($cid);
        if (is_array($contact) && function_exists('jeab_contacts_sole_view_from_contact')) {
            $view = jeab_contacts_sole_view_from_contact($contact);

            return [
                'name' => (string) ($view['name'] ?? ''),
                'email' => (string) ($view['email'] ?? ''),
                'phone' => (string) ($view['phone'] ?? ''),
            ];
        }
    }

    return [
        'name' => (string) ($rem['customer_name'] ?? ''),
        'email' => (string) ($rem['email'] ?? ''),
        'phone' => (string) ($rem['phone'] ?? ''),
    ];
}

/**
 * Hook kontakter.restore_contact_from_backup.
 * dry=1 är standard. dry=0 kräver confirm=SAM_YES.
 * Skapar den borttagna raden från en namngiven kopia, pekar om angivna rader och tömmer angivna fält.
 * Personnummer skrivs inte i svaret, felloggen eller ångerbatchen.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_restore_contact_from_backup(array $input, JeabStore $store): array
{
    $dry = function_exists('jeab_contacts_sole_wants_dry') ? jeab_contacts_sole_wants_dry($input) : true;
    $contactId = trim((string) ($input['contact_id'] ?? ''));
    $backup = trim((string) ($input['backup'] ?? ''), ' ');
    if ($contactId === '' || $backup === '') {
        return ['ok' => false, 'error' => 'contact_id och backup krävs.', 'dry' => $dry, 'written' => false];
    }
    $loaded = jeab_contacts_restore_backup_contact($backup, $contactId);
    if (empty($loaded['ok']) || !is_array($loaded['row'] ?? null)) {
        return [
            'ok' => false,
            'error' => (string) ($loaded['error'] ?? 'Kopian saknas.'),
            'dry' => $dry,
            'written' => false,
        ];
    }
    $cleared = jeab_contacts_restore_clear_fields($loaded['row'], $input['clear_fields'] ?? []);
    if (empty($cleared['ok']) || !is_array($cleared['row'] ?? null)) {
        return ['ok' => false, 'error' => (string) ($cleared['error'] ?? 'Fältet kan inte tömmas.'), 'dry' => $dry, 'written' => false];
    }
    $links = jeab_contacts_restore_relink_plan($store, $input['relink'] ?? [], $contactId);
    if (empty($links['ok']) || !is_array($links['rows'] ?? null)) {
        return ['ok' => false, 'error' => (string) ($links['error'] ?? 'relink är ogiltig.'), 'dry' => $dry, 'written' => false];
    }
    if (function_exists('jeab_kontakt_get') && is_array(jeab_kontakt_get($contactId))) {
        return ['ok' => false, 'error' => 'Kontakten finns redan.', 'dry' => $dry, 'written' => false];
    }
    $clearFields = is_array($cleared['fields'] ?? null) ? array_values($cleared['fields']) : [];
    $plan = [
        'ok' => true,
        'action' => 'kontakter.restore_contact_from_backup',
        'dry' => true,
        'written' => false,
        'contact_id' => $contactId,
        'backup' => $backup,
        'clear_fields' => $clearFields,
        'relink' => $links['rows'],
    ];
    if ($dry) {
        return $plan;
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM_YES') {
        return ['ok' => false, 'error' => 'confirm ska vara SAM_YES.', 'dry' => false, 'written' => false];
    }
    if (!function_exists('jeab_contacts_sole_undo_add') || !function_exists('jeab_contacts_sole_batch') || !function_exists('jeab_kontakter_mutate')) {
        return ['ok' => false, 'error' => 'Ångerloggen saknas.', 'dry' => false, 'written' => false];
    }
    $batch = jeab_contacts_sole_batch();
    $seq = 1;
    $after = function_exists('jeab_contacts_sole_undo_contact') ? jeab_contacts_sole_undo_contact($cleared['row']) : $cleared['row'];
    if (!jeab_contacts_sole_undo_add($batch, $seq, 'contact', 'contact', $contactId, null, is_array($after) ? $after : null)) {
        return ['ok' => false, 'error' => 'Ångerloggen kunde inte skrivas. Inget skrevs.', 'dry' => false, 'written' => false];
    }
    $seq++;
    foreach ($links['rows'] as $link) {
        if (!is_array($link)) {
            continue;
        }
        $beforeLink = ['contact_id' => (string) ($link['contact_id'] ?? '')];
        $afterLink = ['contact_id' => $contactId];
        $entity = (string) ($link['entity'] ?? '');
        $id = (string) ($link['id'] ?? '');
        if (!jeab_contacts_sole_undo_add($batch, $seq, 'link', $entity, $id, $beforeLink, $afterLink)) {
            return ['ok' => false, 'error' => 'Ångerloggen kunde inte skrivas. Inget skrevs.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
        }
        $seq++;
    }
    $inserted = jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $cleared): bool {
        if (isset($contacts[$contactId])) {
            return false;
        }
        $row = $cleared['row'];
        $row['id'] = $contactId;
        $contacts[$contactId] = $row;

        return true;
    });
    if ($inserted !== true) {
        return ['ok' => false, 'error' => 'Kontakten kunde inte skapas.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
    }
    foreach ($links['rows'] as $link) {
        if (!is_array($link)) {
            continue;
        }
        if (!jeab_contacts_restore_apply_link($store, (string) ($link['table'] ?? ''), (int) ($link['id'] ?? 0), $contactId)) {
            return ['ok' => false, 'error' => 'En länk kunde inte pekas om.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
        }
    }
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return [
        'ok' => true,
        'action' => 'kontakter.restore_contact_from_backup',
        'dry' => false,
        'written' => true,
        'batch_id' => $batch,
        'contact_id' => $contactId,
        'backup' => $backup,
        'clear_fields' => $clearFields,
        'relink' => $links['rows'],
    ];
}

/**
 * @return array{ok:bool,error?:string,row?:array<string,mixed>}
 */
function jeab_contacts_restore_backup_contact(string $backup, string $contactId): array
{
    if (function_exists('jeab_kontakt_backup_filename') && jeab_kontakt_backup_filename($backup)) {
        return jeab_contacts_restore_snapshot_contact($backup, $contactId);
    }
    if (jeab_contacts_restore_stamp_name($backup)) {
        return jeab_contacts_restore_stamp_contact($backup, $contactId);
    }

    return ['ok' => false, 'error' => 'Ogiltigt filnamn.'];
}

/** Bara YYYYMMDD-HHMMSS. Siffror och ett bindestreck, ingen sökväg. */
function jeab_contacts_restore_stamp_name(string $name): bool
{
    if ($name === '' || $name !== basename($name)) {
        return false;
    }
    if (preg_match('/\A\d{8}-\d{6}\z/', $name) !== 1) {
        return false;
    }

    return !str_contains($name, '/') && !str_contains($name, '\\') && !str_contains($name, "\0") && !str_contains($name, '..');
}

/**
 * @return array{ok:bool,error?:string,row?:array<string,mixed>}
 */
function jeab_contacts_restore_snapshot_contact(string $backup, string $contactId): array
{
    if (!function_exists('jeab_data_dir')) {
        return ['ok' => false, 'error' => 'Kopian saknas.'];
    }
    $dir = jeab_data_dir();
    $realDir = realpath($dir);
    $path = $dir . '/' . $backup;
    $realPath = realpath($path);
    if ($realDir === false || $realPath === false || !is_file($realPath) || is_link($path) || dirname($realPath) !== $realDir || basename($realPath) !== $backup) {
        return ['ok' => false, 'error' => 'Kopian saknas.'];
    }
    $payload = json_decode((string) file_get_contents($realPath), true);
    $saved = is_array($payload) && is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
    $row = jeab_contacts_restore_pick_contact($saved, $contactId);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Kontakten saknas i kopian.'];
    }
    $row['id'] = $contactId;

    return ['ok' => true, 'row' => $row];
}

/**
 * privat/backups/YYYYMMDD-HHMMSS från migrate.backup.
 * Kontakten läses som restore_verify: tables/contacts.jsonl, annars files/data/kontakter.json.
 *
 * @return array{ok:bool,error?:string,row?:array<string,mixed>}
 */
function jeab_contacts_restore_stamp_contact(string $stamp, string $contactId): array
{
    $dir = jeab_contacts_restore_stamp_dir($stamp);
    if ($dir === '') {
        return ['ok' => false, 'error' => 'Kopian saknas.'];
    }
    $fromTable = jeab_contacts_restore_jsonl_contact($dir, $contactId);
    if (!empty($fromTable['broken'])) {
        return ['ok' => false, 'error' => 'Kopian kunde inte läsas.'];
    }
    $row = is_array($fromTable['row'] ?? null) ? $fromTable['row'] : null;
    if (!is_array($row)) {
        $row = jeab_contacts_restore_copied_file($dir, $contactId);
    }
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Kontakten saknas i kopian.'];
    }
    $row['id'] = $contactId;

    return ['ok' => true, 'row' => $row];
}

function jeab_contacts_restore_stamp_dir(string $stamp): string
{
    if (!jeab_contacts_restore_stamp_name($stamp)) {
        return '';
    }
    if (!function_exists('jeab_migrate_backup_stamp_dir')) {
        $file = __DIR__ . '/migrate/backup.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    if (!function_exists('jeab_migrate_backup_stamp_dir')) {
        return '';
    }
    $dir = jeab_migrate_backup_stamp_dir($stamp);
    if ($dir === '' || basename(str_replace('\\', '/', $dir)) !== $stamp) {
        return '';
    }

    return $dir;
}

/**
 * @param array<string, mixed> $saved
 * @return array<string, mixed>|null
 */
function jeab_contacts_restore_pick_contact(array $saved, string $contactId): ?array
{
    if (isset($saved[$contactId]) && is_array($saved[$contactId])) {
        return $saved[$contactId];
    }
    foreach ($saved as $id => $item) {
        if (!is_array($item)) {
            continue;
        }
        $savedId = trim((string) ($item['id'] ?? $id));
        if ($savedId === $contactId) {
            return $item;
        }
    }

    return null;
}

/**
 * broken betyder att tabellfilen finns men inte går att läsa. Då används inte filkopian.
 *
 * @return array{broken:bool,row:?array<string,mixed>}
 */
function jeab_contacts_restore_jsonl_contact(string $dir, string $contactId): array
{
    $empty = ['broken' => false, 'row' => null];
    $abs = function_exists('jeab_migrate_restore_abs') ? jeab_migrate_restore_abs($dir, 'tables/contacts.jsonl') : '';
    if ($abs === '') {
        return $empty;
    }
    $fh = fopen($abs, 'rb');
    if ($fh === false) {
        return ['broken' => true, 'row' => null];
    }
    $found = null;
    while (($line = fgets($fh)) !== false) {
        $trim = rtrim($line, "\r\n");
        if ($trim === '') {
            continue;
        }
        $decoded = json_decode($trim, true);
        if (!is_array($decoded)) {
            fclose($fh);

            return ['broken' => true, 'row' => null];
        }
        $id = trim((string) ($decoded['id'] ?? ''));
        $body = $decoded['row_json'] ?? null;
        if (is_string($body)) {
            $body = json_decode($body, true);
        }
        if (!is_array($body)) {
            continue;
        }
        $bodyId = trim((string) ($body['id'] ?? $id));
        if ($id !== $contactId && $bodyId !== $contactId) {
            continue;
        }
        $found = $body;
        break;
    }
    fclose($fh);

    return ['broken' => false, 'row' => $found];
}

/** @return array<string, mixed>|null */
function jeab_contacts_restore_copied_file(string $dir, string $contactId): ?array
{
    $abs = function_exists('jeab_migrate_restore_abs') ? jeab_migrate_restore_abs($dir, 'files/data/kontakter.json') : '';
    if ($abs === '') {
        return null;
    }
    $payload = json_decode((string) file_get_contents($abs), true);
    $saved = is_array($payload) && is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];

    return jeab_contacts_restore_pick_contact($saved, $contactId);
}

/**
 * @param mixed $raw
 * @return array{ok:bool,error?:string,row?:array<string,mixed>,fields?:list<string>}
 */
function jeab_contacts_restore_clear_fields(array $row, mixed $raw): array
{
    if ($raw === null) {
        $raw = [];
    }
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'clear_fields ska vara en lista.'];
    }
    $allowed = [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'street' => 'street',
        'postal' => 'postal_code',
        'postal_code' => 'postal_code',
        'city' => 'city',
        'fastighet' => 'fastighetsbeteckning',
        'fastighetsbeteckning' => 'fastighetsbeteckning',
        'ovrigt' => 'ovrigt',
        'owners' => 'owners',
    ];
    $fields = [];
    foreach ($raw as $field) {
        if (!is_string($field) && !is_int($field)) {
            return ['ok' => false, 'error' => 'Okänt fält.'];
        }
        $name = trim((string) $field);
        if ($name === '' || !isset($allowed[$name])) {
            return ['ok' => false, 'error' => 'Okänt fält.'];
        }
        $fields[] = $name;
        $key = $allowed[$name];
        if ($key === 'owners') {
            $row['owners'] = [];
            continue;
        }
        $row[$key] = '';
        if ($key === 'street') {
            $row['street_key'] = '';
        }
        if (in_array($name, ['name', 'email', 'phone'], true) && is_array($row['owners'] ?? null)) {
            foreach ($row['owners'] as $index => $owner) {
                if (!is_array($owner)) {
                    continue;
                }
                $owner[$name] = '';
                $row['owners'][$index] = $owner;
            }
        }
    }

    return ['ok' => true, 'row' => $row, 'fields' => $fields];
}

/**
 * @param mixed $raw
 * @return array{ok:bool,error?:string,rows?:list<array<string,mixed>>}
 */
function jeab_contacts_restore_relink_plan(JeabStore $store, mixed $raw, string $contactId): array
{
    if ($raw === null) {
        $raw = [];
    }
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'relink ska vara en lista.'];
    }
    unset($contactId);
    $tables = [
        'reminder' => 'reminders',
        'reminders' => 'reminders',
        'booking' => 'bookings',
        'bookings' => 'bookings',
        'order' => 'orders',
        'orders' => 'orders',
    ];
    $entities = [
        'reminders' => 'reminder',
        'bookings' => 'booking',
        'orders' => 'order',
    ];
    $rows = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            return ['ok' => false, 'error' => 'relink ska vara tabell och id.'];
        }
        $table = strtolower(trim((string) ($item['table'] ?? '')));
        if (!isset($tables[$table])) {
            return ['ok' => false, 'error' => 'relink ska vara tabell och id.'];
        }
        $table = $tables[$table];
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'relink ska vara tabell och id.'];
        }
        $current = jeab_contacts_restore_link_contact($store, $table, $id);
        if ($current === null) {
            return ['ok' => false, 'error' => 'Raden saknas.'];
        }
        $rows[] = [
            'table' => $table,
            'entity' => $entities[$table],
            'id' => $id,
            'contact_id' => $current,
        ];
    }

    return ['ok' => true, 'rows' => $rows];
}

function jeab_contacts_restore_link_contact(JeabStore $store, string $table, int $id): ?string
{
    if ($table === 'reminders') {
        $row = $store->getReminder($id);

        return is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : null;
    }
    if ($table === 'bookings') {
        $row = $store->getBooking($id);

        return is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : null;
    }
    if ($table === 'orders' && function_exists('jeab_purchase_get')) {
        $row = jeab_purchase_get($id);

        return is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : null;
    }

    return null;
}

function jeab_contacts_restore_apply_link(JeabStore $store, string $table, int $id, string $contactId): bool
{
    if ($id <= 0 || $contactId === '') {
        return false;
    }
    if ($table === 'orders') {
        if (!function_exists('jeab_purchase_patch')) {
            return false;
        }
        $changed = false;
        $result = jeab_purchase_patch($id, static function (array $row) use ($contactId, &$changed): array {
            $row['contact_id'] = $contactId;
            $changed = true;

            return $row;
        });

        return $changed && is_array($result);
    }
    if ($table !== 'reminders' && $table !== 'bookings') {
        return false;
    }
    if ($store instanceof JeabPdoStore) {
        $sqlTable = $table === 'bookings' ? 'bookings' : 'reminders';
        $stmt = $store->pdo()->prepare('UPDATE ' . $sqlTable . ' SET contact_id = ? WHERE id = ?');
        $stmt->execute([$contactId, $id]);

        return $stmt->rowCount() === 1;
    }
    if ($table === 'bookings') {
        $store->saveBooking($id, ['contact_id' => $contactId]);

        return true;
    }
    $store->saveReminder($id, ['contact_id' => $contactId]);

    return true;
}

/**
 * Hook kontakter.prune_orphan.
 * dry=1 är standard. dry=0 kräver confirm=SAM_YES.
 * Tar bara bort kontakter som ingen påminnelse, order, bokning eller inkorg pekar på.
 * Ångerbatchen innehåller inte personnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_prune_orphan(array $input, JeabStore $store): array
{
    $dry = function_exists('jeab_contacts_sole_wants_dry') ? jeab_contacts_sole_wants_dry($input) : true;
    $ids = jeab_contacts_prune_orphan_ids($input['contact_ids'] ?? null);
    if ($ids === []) {
        return ['ok' => false, 'error' => 'contact_ids krävs.', 'dry' => $dry, 'written' => false];
    }
    try {
        $pointed = function_exists('jeab_kontakt_pointed_ids') ? jeab_kontakt_pointed_ids($store) : [];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Länkarna kunde inte läsas. Inget togs bort.', 'dry' => $dry, 'written' => false];
    }
    $delete = [];
    $skipped = [];
    foreach ($ids as $id) {
        $row = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($id) : null;
        if (!is_array($row)) {
            $skipped[] = ['id' => $id, 'reason' => 'saknas'];
            continue;
        }
        if (isset($pointed[$id])) {
            $skipped[] = ['id' => $id, 'reason' => 'har länk'];
            continue;
        }
        $delete[] = $id;
    }
    if ($dry) {
        return [
            'ok' => true,
            'action' => 'kontakter.prune_orphan',
            'dry' => true,
            'written' => false,
            'delete' => $delete,
            'skipped' => $skipped,
        ];
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM_YES') {
        return ['ok' => false, 'error' => 'confirm ska vara SAM_YES.', 'dry' => false, 'written' => false];
    }
    if ($delete === []) {
        return [
            'ok' => true,
            'action' => 'kontakter.prune_orphan',
            'dry' => false,
            'written' => false,
            'delete' => [],
            'skipped' => $skipped,
        ];
    }
    if (!function_exists('jeab_contacts_sole_undo_add') || !function_exists('jeab_contacts_sole_batch') || !function_exists('jeab_kontakter_mutate') || !function_exists('jeab_kontakt_get')) {
        return ['ok' => false, 'error' => 'Ångerloggen saknas.', 'dry' => false, 'written' => false];
    }
    $batch = jeab_contacts_sole_batch();
    $seq = 1;
    foreach ($delete as $id) {
        $row = jeab_kontakt_get($id);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'Kontakten saknas. Inget togs bort.', 'dry' => false, 'written' => false];
        }
        $before = function_exists('jeab_contacts_sole_undo_contact') ? jeab_contacts_sole_undo_contact($row) : $row;
        if (!is_array($before) || !jeab_contacts_sole_undo_add($batch, $seq, 'prune_orphan', 'contact', $id, $before, null)) {
            return ['ok' => false, 'error' => 'Ångerloggen kunde inte skrivas. Inget togs bort.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
        }
        $seq++;
    }
    $targets = $delete;
    $removed = jeab_kontakter_mutate(static function (array &$contacts) use ($targets): bool {
        $changed = false;
        foreach ($targets as $id) {
            if (isset($contacts[$id])) {
                unset($contacts[$id]);
                $changed = true;
            }
        }

        return $changed;
    }, true);
    if ($removed !== true) {
        return ['ok' => false, 'error' => 'Kontakten kunde inte tas bort.', 'dry' => false, 'written' => false, 'batch_id' => $batch];
    }
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget(null);
    }

    return [
        'ok' => true,
        'action' => 'kontakter.prune_orphan',
        'dry' => false,
        'written' => true,
        'batch_id' => $batch,
        'delete' => $delete,
        'skipped' => $skipped,
    ];
}

/** @return list<string> */
function jeab_contacts_prune_orphan_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $parts = preg_split('/[\s,]+/', trim($raw));
        $raw = is_array($parts) ? $parts : [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $id) {
        if (!is_string($id) && !is_int($id)) {
            continue;
        }
        $id = trim((string) $id);
        if ($id === '' || isset($out[$id])) {
            continue;
        }
        $out[$id] = $id;
    }

    return array_values($out);
}
