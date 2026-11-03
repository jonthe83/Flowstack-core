<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_name_source.php';
require_once __DIR__ . '/inbox_link_contacts.php';

/**
 * contacts.name_candidates och contacts.fill_name.
 *
 * Kandidatnamn slås ihop på normaliserad form (gemener, trim, hopslagna
 * mellanslag). Svaret listar {sources, ids} utan namnvärden. Källor:
 * booking_thread (påminnelse.thread_id för bokningen, leadtext),
 * contact_thread (inkorgstråd med samma contact_id), fortnox_customer
 * (CustomerName/fortnox-namn på länkad order).
 *
 * fill_name skriver namn via jeab_kontakter_mutate när kontaktpersonen
 * saknar riktigt namn och det finns exakt ett distinkt kandidatnamn.
 * En delägare utan contact:true med riktigt namn skrivs inte över. Saknas
 * en tom eller platshållar-ägare läggs en ny ägare till.
 * Undo i inbox_link_contacts_undo med action=fill_name. Före mutate committas
 * en rad med tomt new_contact_id och pending-markör i old_value. Efter lyckad
 * ändring fylls contact_id och old_value (JSON med owners, toppnivå-name och
 * name_missing när den är satt, läst i låset). Ändras inget, eller mutate
 * misslyckas, tas raden bort. Går det inte markeras den undone. En pending-rad
 * (tomt contact_id, old_value NULL eller pending-markören) återställer inget.
 * Äldre rader kan ha old_value som en ren sträng. Undo återställer bara om
 * owners fortfarande är det fill_name skrev.
 *
 * Testkrok, läses bara i jeab_contacts_fill_name_set precis före mutate:
 * $GLOBALS['_JEAB_FILL_NAME_BEFORE_LOCK'] som callable. Den får ett namn
 * att dyka upp mellan dry-kontrollen och låset.
 */

function jeab_contacts_name_candidates_dry(array $input): bool
{
    return function_exists('jeab_inbox_link_contacts_dry')
        ? jeab_inbox_link_contacts_dry($input)
        : true;
}

function jeab_contacts_name_candidates_wants_undo(array $input): bool
{
    return function_exists('jeab_inbox_link_contacts_wants_undo')
        && jeab_inbox_link_contacts_wants_undo($input);
}

function jeab_contacts_name_candidates_norm(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return trim($name);
}

function jeab_contacts_name_candidates_real(string $name, array $emails = []): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (function_exists('jeab_contacts_sole_name_is_real')) {
        return jeab_contacts_sole_name_is_real($name, $emails);
    }

    return !str_contains($name, '@');
}

/**
 * @param array<string, mixed> $meta
 */
function jeab_contacts_name_candidates_thread_name(array $meta, string $threadId): string
{
    $cands = [];
    $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
    $cands[] = trim((string) ($lead['name'] ?? ''));
    $cands[] = trim((string) ($meta['customer_name'] ?? ''));
    $msg = function_exists('jeab_inbox_link_contacts_lead_msg') ? jeab_inbox_link_contacts_lead_msg($meta) : $meta;
    if (function_exists('jeab_lead_customer')) {
        $cust = jeab_lead_customer($msg);
        $cands[] = trim((string) ($cust['name'] ?? ''));
    }
    $body = (string) ($msg['body'] ?? $msg['body_text'] ?? $meta['body'] ?? '');
    if ($body !== '' && function_exists('jeab_lead_kund_line')) {
        $cands[] = trim((string) (jeab_lead_kund_line($body)['name'] ?? ''));
    }
    if ($threadId !== '' && function_exists('jeab_lead_party_get')) {
        $cands[] = trim((string) (jeab_lead_party_get($threadId)['name'] ?? ''));
    }
    $emails = [];
    $email = function_exists('jeab_inbox_link_contacts_customer_email')
        ? jeab_inbox_link_contacts_customer_email($meta)
        : '';
    if ($email !== '') {
        $emails[] = $email;
    }
    foreach ($cands as $name) {
        if (jeab_contacts_name_candidates_real($name, $emails)) {
            return $name;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $payload
 */
function jeab_contacts_name_candidates_fortnox_name(array $payload): string
{
    foreach (['CustomerName', 'fortnox_customer_name', 'fortnox_name'] as $key) {
        $name = trim((string) ($payload[$key] ?? ''));
        if (jeab_contacts_name_candidates_real($name)) {
            return $name;
        }
    }
    $customer = $payload['Customer'] ?? $payload['customer'] ?? null;
    if (is_array($customer)) {
        $name = trim((string) ($customer['Name'] ?? $customer['name'] ?? ''));
        if (jeab_contacts_name_candidates_real($name)) {
            return $name;
        }
    }

    return '';
}

function jeab_contacts_name_candidates_fortnox_id(array $payload): string
{
    foreach (['fortnox_customer', 'fortnox_customer_number', 'fortnox_kundnummer', 'CustomerNumber'] as $key) {
        $id = trim((string) ($payload[$key] ?? ''));
        if ($id !== '') {
            return $id;
        }
    }

    return '';
}

/**
 * @param array<string, array{name:string,sources:list<string>,ids:list<string>}> $bag
 */
function jeab_contacts_name_candidates_put(array &$bag, string $name, string $source, string $id, array $emails = []): void
{
    if (!jeab_contacts_name_candidates_real($name, $emails) || $source === '') {
        return;
    }
    $key = jeab_contacts_name_candidates_norm($name);
    if ($key === '') {
        return;
    }
    if (!isset($bag[$key])) {
        $bag[$key] = ['name' => trim($name), 'sources' => [], 'ids' => []];
    }
    if (!in_array($source, $bag[$key]['sources'], true)) {
        $bag[$key]['sources'][] = $source;
    }
    $id = trim($id);
    if ($id !== '' && !in_array($id, $bag[$key]['ids'], true)) {
        $bag[$key]['ids'][] = $id;
    }
}

/**
 * @param array<string, mixed> $booking
 * @return array{booking_id:int,contact_id:string,contact_name_real:bool,groups:array<string, array{name:string,sources:list<string>,ids:list<string>}>}
 */
function jeab_contacts_name_candidates_collect(array $booking, ?JeabStore $store = null): array
{
    $bookingId = (int) ($booking['id'] ?? 0);
    $contactId = trim((string) ($booking['contact_id'] ?? ''));
    $real = false;
    $emails = [];
    if ($contactId !== '' && function_exists('jeab_kontakt_get')) {
        $contact = jeab_kontakt_get($contactId);
        if (is_array($contact)) {
            $emails = function_exists('jeab_contacts_sole_contact_emails')
                ? jeab_contacts_sole_contact_emails($contact)
                : [];
            $best = function_exists('jeab_contacts_sole_best_owner_name')
                ? jeab_contacts_sole_best_owner_name($contact, $emails)
                : trim((string) ($contact['name'] ?? ''));
            $real = $best !== '' && jeab_contacts_name_candidates_real($best, $emails);
        }
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    $groups = [];
    if ($pdo instanceof PDO && ($bookingId > 0 || $contactId !== '')) {
        // Räknare för test. Ökas när uppslaget kör SQL, inte vid cacheträff.
        $GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] = (int) ($GLOBALS['_JEAB_NAME_CANDIDATE_QUERIES'] ?? 0) + 1;
        if ($bookingId > 0) {
            try {
                $stmt = $pdo->prepare('SELECT thread_id FROM reminders WHERE booking_id = ? AND id > 0');
                $stmt->execute([$bookingId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $tid = trim((string) ($row['thread_id'] ?? ''));
                    if ($tid === '') {
                        continue;
                    }
                    $name = jeab_contacts_name_candidates_from_thread($pdo, $tid);
                    jeab_contacts_name_candidates_put($groups, $name, 'booking_thread', $tid, $emails);
                }
            } catch (Throwable) {
            }
        }
        if ($contactId !== '') {
            foreach (jeab_contacts_name_candidates_contact_threads($pdo, $contactId) as $tid) {
                $name = jeab_contacts_name_candidates_from_thread($pdo, $tid);
                jeab_contacts_name_candidates_put($groups, $name, 'contact_thread', $tid, $emails);
            }
        }
        if ($bookingId > 0) {
            foreach (jeab_contacts_name_candidates_order_payloads($bookingId) as $payload) {
                $name = jeab_contacts_name_candidates_fortnox_name($payload);
                $fid = jeab_contacts_name_candidates_fortnox_id($payload);
                if ($fid === '') {
                    $fid = (string) ((int) ($payload['reminder_id'] ?? 0));
                }
                jeab_contacts_name_candidates_put($groups, $name, 'fortnox_customer', $fid, $emails);
            }
        }
    }

    return [
        'booking_id' => $bookingId,
        'contact_id' => $contactId,
        'contact_name_real' => $real,
        'groups' => $groups,
    ];
}

function jeab_contacts_name_candidates_from_thread(PDO $pdo, string $tid): string
{
    $tid = trim($tid);
    if ($tid === '') {
        return '';
    }
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        try {
            $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
            $stmt->execute([$tid]);
            $raw = $stmt->fetchColumn();
        } catch (Throwable) {
            continue;
        }
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            continue;
        }
        $name = jeab_contacts_name_candidates_thread_name($json, $tid);
        if ($name !== '') {
            return $name;
        }
    }

    return '';
}

/**
 * @return list<string>
 */
function jeab_contacts_name_candidates_contact_threads(PDO $pdo, string $contactId): array
{
    $ids = [];
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        try {
            $stmt = $pdo->prepare('SELECT id, row_json, contact_id FROM ' . $table);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $cid = trim((string) ($row['contact_id'] ?? ''));
                if ($cid === $contactId) {
                    $ids[$id] = true;
                    continue;
                }
                $raw = (string) ($row['row_json'] ?? '');
                if ($raw === '') {
                    continue;
                }
                $json = json_decode($raw, true);
                if (is_array($json) && trim((string) ($json['contact_id'] ?? '')) === $contactId) {
                    $ids[$id] = true;
                }
            }
        } catch (Throwable) {
        }
    }
    $out = array_keys($ids);
    sort($out, SORT_STRING);

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_contacts_name_candidates_order_payloads(int $bookingId): array
{
    if ($bookingId <= 0 || !function_exists('jeab_purchase_sql_live')) {
        return [];
    }
    try {
        $pdo = jeab_purchase_sql_live();
        if (!$pdo instanceof PDO) {
            return [];
        }
        $stmt = $pdo->query('SELECT reminder_id, payload FROM purchases');
        $out = [];
        foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = (string) ($row['payload'] ?? '');
            if ($raw === '') {
                continue;
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                continue;
            }
            if ((int) ($payload['booking_id'] ?? 0) !== $bookingId) {
                continue;
            }
            $payload['reminder_id'] = (int) ($row['reminder_id'] ?? $payload['reminder_id'] ?? 0);
            $out[] = $payload;
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Per request, per bokning och kontakt. Samma par slås inte upp två gånger.
 *
 * @param array<string, mixed> $booking
 * @return array{booking_id:int,contact_id:string,contact_name_real:bool,groups:array<string, array{name:string,sources:list<string>,ids:list<string>}>}
 */
function jeab_contacts_name_candidates_cached(array $booking, ?JeabStore $store = null): array
{
    $bookingId = (int) ($booking['id'] ?? 0);
    $contactId = trim((string) ($booking['contact_id'] ?? ''));
    if ($bookingId > 0) {
        $bag = $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'] ?? null;
        if (is_array($bag) && isset($bag[$bookingId]) && is_array($bag[$bookingId])) {
            $cached = $bag[$bookingId];
            $sameContact = trim((string) ($cached['contact_id'] ?? '')) === $contactId;
            if ($sameContact && array_key_exists('groups', $cached)) {
                return $cached;
            }
        }
    }
    $found = jeab_contacts_name_candidates_collect($booking, $store);
    if ($bookingId > 0) {
        if (!isset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES']) || !is_array($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'])) {
            $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'] = [];
        }
        $GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'][$bookingId] = $found;
    }

    return $found;
}

function jeab_contacts_name_candidates_cache_forget(?int $bookingId = null): void
{
    if ($bookingId === null || $bookingId <= 0) {
        unset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES']);

        return;
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES']) && is_array($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'])) {
        unset($GLOBALS['_JEAB_CONTACTS_NAME_CANDIDATES'][$bookingId]);
    }
}

/**
 * Exakt ett distinkt kandidatnamn, annars tomt.
 *
 * @param array<string, mixed> $booking
 */
function jeab_contacts_name_candidates_unique_name(array $booking, ?JeabStore $store = null): string
{
    $found = jeab_contacts_name_candidates_cached($booking, $store);
    $groups = is_array($found['groups'] ?? null) ? $found['groups'] : [];
    if (count($groups) !== 1) {
        return '';
    }
    $one = array_values($groups)[0];

    return trim((string) ($one['name'] ?? ''));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_name_candidates(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return jeab_contacts_name_source_fail(400, 'Ingen lagring.');
    }
    $parsed = jeab_contacts_name_source_input($input);
    if (isset($parsed['error'])) {
        return jeab_contacts_name_source_fail(400, (string) $parsed['error']);
    }
    $bookingId = (int) ($parsed['booking_id'] ?? 0);
    $key = (string) ($parsed['key'] ?? '');
    if ($bookingId > 0) {
        $booking = $store->getBooking($bookingId);
        if (!is_array($booking)) {
            return jeab_contacts_name_source_fail(404, 'Bokningen finns inte.');
        }
        if ($key !== '' && trim((string) ($booking['contact_id'] ?? '')) !== $key) {
            return jeab_contacts_name_source_fail(400, 'booking_id och key är olika bokningar.');
        }
    } else {
        $found = jeab_contacts_name_source_by_key($store->pdo(), $key);
        if (isset($found['error'])) {
            return jeab_contacts_name_source_fail((int) $found['status'], (string) $found['error']);
        }
        $bookingId = (int) ($found['booking_id'] ?? 0);
        $booking = $store->getBooking($bookingId);
        if (!is_array($booking)) {
            return jeab_contacts_name_source_fail(404, 'Bokningen finns inte.');
        }
    }
    $pack = jeab_contacts_name_candidates_cached($booking, $store);
    $candidates = [];
    foreach (is_array($pack['groups'] ?? null) ? $pack['groups'] : [] as $group) {
        $sources = is_array($group['sources'] ?? null) ? $group['sources'] : [];
        $ids = is_array($group['ids'] ?? null) ? $group['ids'] : [];
        sort($sources, SORT_STRING);
        sort($ids, SORT_STRING);
        $candidates[] = ['sources' => $sources, 'ids' => $ids];
    }
    usort($candidates, static function (array $a, array $b): int {
        $as = implode(',', $a['sources']);
        $bs = implode(',', $b['sources']);
        $cmp = strcmp($as, $bs);

        return $cmp !== 0 ? $cmp : strcmp(implode(',', $a['ids']), implode(',', $b['ids']));
    });

    return [
        'ok' => true,
        'status' => 200,
        'booking_id' => $bookingId,
        'contact_id' => (string) ($pack['contact_id'] ?? ''),
        'contact_name_real' => !empty($pack['contact_name_real']),
        'candidates' => $candidates,
    ];
}

/** Tomt namn eller platshållare («Kund» och samma lista som dry). */
function jeab_contacts_fill_name_blank(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return true;
    }

    return function_exists('jeab_contacts_sole_name_is_placeholder') && jeab_contacts_sole_name_is_placeholder($name);
}

/**
 * Samma real-test som dry (jeab_contacts_sole_best_owner_name och
 * jeab_contacts_name_candidates_real). Ett riktigt namn på kontaktpersonen
 * blockerar. En delägare utan contact:true blockerar inte: den lämnas orörd
 * och en ny ägare kan läggas till.
 *
 * @param array<string, mixed> $contact
 */
function jeab_contacts_fill_name_blocked(array $contact): bool
{
    $emails = function_exists('jeab_contacts_sole_contact_emails')
        ? jeab_contacts_sole_contact_emails($contact)
        : [];
    $best = function_exists('jeab_contacts_sole_best_owner_name')
        ? jeab_contacts_sole_best_owner_name($contact, $emails)
        : trim((string) ($contact['name'] ?? ''));
    if (!jeab_contacts_name_candidates_real($best, $emails)) {
        return false;
    }
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner) || empty($owner['contact'])) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));

        return jeab_contacts_name_candidates_real($name, $emails);
    }

    return false;
}

/**
 * @param mixed $owners
 */
function jeab_contacts_fill_name_owners_json(mixed $owners): string
{
    if (!is_array($owners)) {
        $owners = [];
    }
    $json = json_encode($owners, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : '';
}

/**
 * @param array<string, mixed> $contact
 * @return array{owners:array<mixed>,name:string,name_missing?:mixed}
 */
function jeab_contacts_fill_name_snapshot(array $contact): array
{
    $owners = json_decode(jeab_contacts_fill_name_owners_json($contact['owners'] ?? []), true);
    $snap = [
        'owners' => is_array($owners) ? $owners : [],
        'name' => array_key_exists('name', $contact) ? (string) $contact['name'] : '',
    ];
    if (array_key_exists('name_missing', $contact)) {
        $snap['name_missing'] = $contact['name_missing'];
    }

    return $snap;
}

/**
 * @param array<string, mixed> $contact
 * @return array<string, mixed>
 */
function jeab_contacts_fill_name_apply(array $contact, string $name): array
{
    $owners = json_decode(jeab_contacts_fill_name_owners_json($contact['owners'] ?? []), true);
    if (!is_array($owners)) {
        $owners = [];
    }
    $target = null;
    $fallback = null;
    $anyContact = false;
    foreach ($owners as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (!empty($owner['contact'])) {
            $anyContact = true;
        }
        $ownerName = trim((string) ($owner['name'] ?? ''));
        if (!jeab_contacts_fill_name_blank($ownerName)) {
            continue;
        }
        if (!empty($owner['contact']) && $target === null) {
            $target = $i;
        }
        if ($fallback === null) {
            $fallback = $i;
        }
    }
    if ($target === null) {
        $target = $fallback;
    }
    if ($target === null) {
        $owners[] = [
            'contact' => !$anyContact,
            'name' => $name,
            'email' => '',
            'phone' => '',
        ];
    } elseif (is_array($owners[$target] ?? null)) {
        $owners[$target]['name'] = $name;
    }
    $contact['owners'] = $owners;
    $top = trim((string) ($contact['name'] ?? ''));
    if (!array_key_exists('name', $contact) || jeab_contacts_fill_name_blank($top)) {
        $contact['name'] = $name;
    }
    unset($contact['name_missing']);

    return $contact;
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_fill_name_parse_old(string $old): ?array
{
    $trim = ltrim($old);
    if ($trim === '' || $trim[0] !== '{') {
        return null;
    }
    try {
        $decoded = json_decode($old, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($decoded) || !isset($decoded['owners']) || !is_array($decoded['owners']) || !array_key_exists('name', $decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $snapshot
 */
function jeab_contacts_fill_name_same_owners(array $row, array $snapshot, string $filled): bool
{
    if ($filled === '') {
        return false;
    }
    $base = $row;
    $base['owners'] = $snapshot['owners'];
    $base['name'] = (string) ($snapshot['name'] ?? '');
    if (array_key_exists('name_missing', $snapshot)) {
        $base['name_missing'] = $snapshot['name_missing'];
    } else {
        unset($base['name_missing']);
    }
    $expected = jeab_contacts_fill_name_apply($base, $filled);

    return jeab_contacts_fill_name_owners_json($row['owners'] ?? [])
        === jeab_contacts_fill_name_owners_json($expected['owners'] ?? []);
}

/**
 * @param array<string, mixed> $snapshot
 */
function jeab_contacts_fill_name_put_back(string $contactId, array $snapshot, string $updatedAt): void
{
    if ($contactId === '' || !function_exists('jeab_kontakter_mutate')) {
        return;
    }
    jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $snapshot, $updatedAt): void {
        if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
            return;
        }
        $contacts[$contactId]['owners'] = is_array($snapshot['owners'] ?? null) ? $snapshot['owners'] : [];
        $contacts[$contactId]['name'] = (string) ($snapshot['name'] ?? '');
        if (array_key_exists('name_missing', $snapshot)) {
            $contacts[$contactId]['name_missing'] = $snapshot['name_missing'];
        } else {
            unset($contacts[$contactId]['name_missing']);
        }
        if ($updatedAt !== '') {
            $contacts[$contactId]['updated_at'] = $updatedAt;
        }
    });
}

/**
 * Skriver namnet i låset. Riktigt namn som dykt upp ger name_present och ingen skrivning.
 *
 * @return array{status:string,old:?array,updated_at:string}
 */
function jeab_contacts_fill_name_set(string $contactId, string $name): array
{
    $contactId = trim($contactId);
    $empty = ['status' => 'missing', 'old' => null, 'updated_at' => ''];
    if ($contactId === '' || $name === '' || !function_exists('jeab_kontakter_mutate')) {
        return $empty;
    }
    // Testkrok. Precis före mutate, så ett test kan skriva ett namn mellan dry och låset.
    $before = $GLOBALS['_JEAB_FILL_NAME_BEFORE_LOCK'] ?? null;
    if (is_callable($before)) {
        $before();
    }
    $result = jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $name): array {
        if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
            return ['status' => 'missing', 'old' => null, 'updated_at' => ''];
        }
        $row = $contacts[$contactId];
        if (jeab_contacts_fill_name_blocked($row)) {
            return ['status' => 'name_present', 'old' => null, 'updated_at' => ''];
        }
        $old = jeab_contacts_fill_name_snapshot($row);
        $updatedAt = (string) ($row['updated_at'] ?? '');
        $next = jeab_contacts_fill_name_apply($row, $name);
        $next['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$contactId] = $next;

        return ['status' => 'written', 'old' => $old, 'updated_at' => $updatedAt];
    });
    if (!is_array($result) || !isset($result['status'])) {
        return $empty;
    }
    if (($result['status'] ?? '') === 'written' && function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return $result;
}

function jeab_contacts_fill_name_pending_marker(): string
{
    return '{"pending":true}';
}

/**
 * Rad som skrevs före mutate och inte hann få en snapshot.
 * Tomt new_contact_id, NULL eller pending-markören. En ren sträng, även tom, är den äldre formen.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_fill_name_is_pending(array $row): bool
{
    if (trim((string) ($row['new_contact_id'] ?? '')) === '') {
        return true;
    }
    if (!array_key_exists('old_value', $row) || $row['old_value'] === null) {
        return true;
    }

    return trim((string) $row['old_value']) === jeab_contacts_fill_name_pending_marker();
}

/**
 * Pending-rad committas innan mutate. Samma tabell som övrig ångerlogg.
 */
function jeab_contacts_fill_name_log_pending(PDO $pdo, string $undoId, string $threadId): int|false
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1 || trim($threadId) === '') {
        return false;
    }
    if (!function_exists('jeab_ensure_inbox_link_undo_table') || !function_exists('jeab_pdo_transaction')) {
        return false;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    jeab_ensure_inbox_link_undo_table($pdo, $driver);
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $marker = jeab_contacts_fill_name_pending_marker();
    $id = jeab_pdo_transaction($pdo, static function (PDO $tx) use ($undoId, $threadId, $now, $marker): int {
        $stmt = $tx->prepare(
            'INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ran = $stmt->execute([$undoId, $now, $threadId, 'contacts', '', '', 'fill_name', $marker]);
        if ($ran !== true) {
            return 0;
        }
        $newId = (int) $tx->lastInsertId();

        return $newId > 0 ? $newId : 0;
    });

    return is_int($id) && $id > 0 ? $id : false;
}

/** Efter lyckad ändring. WHERE id är den committade pending-raden. */
function jeab_contacts_fill_name_log_finish(PDO $pdo, int $rowId, string $contactId, string $oldJson): bool
{
    $contactId = trim($contactId);
    if ($rowId <= 0 || $contactId === '' || $oldJson === '' || !function_exists('jeab_pdo_transaction')) {
        return false;
    }
    $ok = jeab_pdo_transaction($pdo, static function (PDO $tx) use ($rowId, $contactId, $oldJson): bool {
        $stmt = $tx->prepare(
            'UPDATE inbox_link_contacts_undo SET new_contact_id = ?, old_value = ? WHERE id = ? AND action = ? AND new_contact_id = ?'
        );
        $ran = $stmt->execute([$contactId, $oldJson, $rowId, 'fill_name', '']);
        if ($ran !== true) {
            return false;
        }
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $check = $tx->prepare('SELECT new_contact_id, old_value FROM inbox_link_contacts_undo WHERE id = ? AND action = ?');
        $check->execute([$rowId, 'fill_name']);
        $got = $check->fetch(PDO::FETCH_ASSOC);
        if (!is_array($got)) {
            return false;
        }

        return trim((string) ($got['new_contact_id'] ?? '')) === $contactId
            && (string) ($got['old_value'] ?? '') === $oldJson;
    });

    return $ok === true;
}

/** Tar bort en pending-rad. Kvarvarande rad markeras undone så den inte kan återställa. */
function jeab_contacts_fill_name_log_drop(PDO $pdo, int $rowId): void
{
    if ($rowId <= 0 || !function_exists('jeab_pdo_transaction')) {
        return;
    }
    try {
        jeab_pdo_transaction($pdo, static function (PDO $tx) use ($rowId): void {
            $stmt = $tx->prepare('DELETE FROM inbox_link_contacts_undo WHERE id = ? AND action = ? AND new_contact_id = ?');
            $stmt->execute([$rowId, 'fill_name', '']);
            $check = $tx->prepare('SELECT id FROM inbox_link_contacts_undo WHERE id = ?');
            $check->execute([$rowId]);
            if ($check->fetchColumn() === false) {
                return;
            }
            $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
            $mark = $tx->prepare(
                'UPDATE inbox_link_contacts_undo SET undone_at = ? WHERE id = ? AND action = ? AND new_contact_id = ? AND undone_at IS NULL'
            );
            $mark->execute([$now, $rowId, 'fill_name', '']);
        });
    } catch (Throwable) {
    }
}

function jeab_contacts_fill_name_current(string $contactId): ?string
{
    if ($contactId === '' || !function_exists('jeab_kontakt_get')) {
        return null;
    }
    $row = jeab_kontakt_get($contactId);
    if (!is_array($row)) {
        return null;
    }
    $emails = function_exists('jeab_contacts_sole_contact_emails') ? jeab_contacts_sole_contact_emails($row) : [];
    if (function_exists('jeab_contacts_sole_best_owner_name')) {
        return jeab_contacts_sole_best_owner_name($row, $emails);
    }

    return trim((string) ($row['name'] ?? ''));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_fill_name(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_name_candidates_dry($input);
    $fail = static function (string $error, int $status = 400) use ($dry): array {
        return [
            'ok' => false,
            'error' => $error,
            'status' => $status,
            'action' => 'contacts.fill_name',
            'dry' => $dry,
            'written' => false,
            'undo_id' => '',
            'would_fill' => false,
        ];
    };
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'name') {
        return $fail('Kräver confirm:name.', 400);
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return $fail('Ingen lagring.');
    }
    $parsed = jeab_contacts_name_source_input($input);
    if (isset($parsed['error'])) {
        return $fail((string) $parsed['error'], 400);
    }
    $bookingId = (int) ($parsed['booking_id'] ?? 0);
    $key = (string) ($parsed['key'] ?? '');
    if ($bookingId > 0) {
        $booking = $store->getBooking($bookingId);
        if (!is_array($booking)) {
            return $fail('Bokningen finns inte.', 404);
        }
        if ($key !== '' && trim((string) ($booking['contact_id'] ?? '')) !== $key) {
            return $fail('booking_id och key är olika bokningar.', 400);
        }
    } else {
        $found = jeab_contacts_name_source_by_key($store->pdo(), $key);
        if (isset($found['error'])) {
            return $fail((string) $found['error'], (int) $found['status']);
        }
        $bookingId = (int) ($found['booking_id'] ?? 0);
        $booking = $store->getBooking($bookingId);
        if (!is_array($booking)) {
            return $fail('Bokningen finns inte.', 404);
        }
    }
    $contactId = trim((string) ($booking['contact_id'] ?? ''));
    $out = [
        'ok' => true,
        'status' => 200,
        'action' => 'contacts.fill_name',
        'dry' => $dry,
        'written' => false,
        'undo_id' => '',
        'booking_id' => $bookingId,
        'contact_id' => $contactId,
        'would_fill' => false,
        'skipped' => [],
    ];
    if ($contactId === '') {
        $out['skipped'] = [['reason' => 'missing']];

        return $out;
    }
    $row = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
    if (!is_array($row)) {
        $out['skipped'] = [['contact_id' => $contactId, 'reason' => 'missing']];

        return $out;
    }
    if (jeab_contacts_fill_name_blocked($row)) {
        $out['skipped'] = [['contact_id' => $contactId, 'reason' => 'has_name']];

        return $out;
    }
    $unique = jeab_contacts_name_candidates_unique_name($booking, $store);
    if ($unique === '') {
        $out['skipped'] = [['contact_id' => $contactId, 'reason' => 'no_candidate']];

        return $out;
    }
    $out['would_fill'] = true;
    if ($dry) {
        return $out;
    }
    $pdo = $store->pdo();
    $undoId = bin2hex(random_bytes(8));
    try {
        $pendingId = jeab_contacts_fill_name_log_pending($pdo, $undoId, (string) $bookingId);
    } catch (Throwable) {
        $pendingId = false;
    }
    if ($pendingId === false) {
        return $fail('Kunde inte skriva ångerloggen.');
    }
    try {
        $applied = jeab_contacts_fill_name_set($contactId, $unique);
    } catch (Throwable) {
        jeab_contacts_fill_name_log_drop($pdo, $pendingId);

        return $fail('Kontakten kunde inte skrivas.');
    }
    if (($applied['status'] ?? '') === 'name_present') {
        jeab_contacts_fill_name_log_drop($pdo, $pendingId);
        $out['would_fill'] = false;
        $out['skipped'] = [['contact_id' => $contactId, 'reason' => 'name_present']];

        return $out;
    }
    if (($applied['status'] ?? '') !== 'written' || !is_array($applied['old'] ?? null)) {
        jeab_contacts_fill_name_log_drop($pdo, $pendingId);

        return $fail('Kontakten kunde inte skrivas.');
    }
    $oldJson = json_encode($applied['old'], JSON_UNESCAPED_UNICODE);
    if (!is_string($oldJson)) {
        jeab_contacts_fill_name_put_back($contactId, $applied['old'], (string) ($applied['updated_at'] ?? ''));
        jeab_contacts_fill_name_log_drop($pdo, $pendingId);

        return $fail('Kunde inte skriva ångerloggen.');
    }
    try {
        $finished = jeab_contacts_fill_name_log_finish($pdo, $pendingId, $contactId, $oldJson);
    } catch (Throwable) {
        $finished = false;
    }
    if ($finished !== true) {
        jeab_contacts_fill_name_put_back($contactId, $applied['old'], (string) ($applied['updated_at'] ?? ''));
        jeab_contacts_fill_name_log_drop($pdo, $pendingId);

        return $fail('Kunde inte skriva ångerloggen.');
    }
    jeab_contacts_name_candidates_cache_forget($bookingId);
    $out['dry'] = false;
    $out['written'] = true;
    $out['undo_id'] = $undoId;

    return $out;
}

/**
 * Återställer i låset. JSON-old_value kräver att owners fortfarande är det
 * fill_name skrev. En ren sträng är den äldre formen och sätter tillbaka namnet
 * om det fortfarande är kandidaten.
 *
 * @return string restored|changed|missing
 */
function jeab_contacts_fill_name_undo_apply(string $contactId, string $oldRaw, string $filled): string
{
    if ($contactId === '' || !function_exists('jeab_kontakter_mutate')) {
        return 'missing';
    }
    $status = jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $oldRaw, $filled): string {
        if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
            return 'missing';
        }
        $row = $contacts[$contactId];
        $snap = jeab_contacts_fill_name_parse_old($oldRaw);
        if ($snap !== null) {
            if (!jeab_contacts_fill_name_same_owners($row, $snap, $filled)) {
                return 'changed';
            }
            $row['owners'] = $snap['owners'];
            $row['name'] = (string) ($snap['name'] ?? '');
            if (array_key_exists('name_missing', $snap)) {
                $row['name_missing'] = $snap['name_missing'];
            } else {
                unset($row['name_missing']);
            }
        } else {
            $emails = function_exists('jeab_contacts_sole_contact_emails') ? jeab_contacts_sole_contact_emails($row) : [];
            $current = function_exists('jeab_contacts_sole_best_owner_name')
                ? jeab_contacts_sole_best_owner_name($row, $emails)
                : trim((string) ($row['name'] ?? ''));
            if ($filled === '' || $current !== $filled) {
                return 'changed';
            }
            $row['name'] = $oldRaw;
            unset($row['name_missing']);
            $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
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
            if ($idx !== null && is_array($owners[$idx] ?? null)) {
                $owners[$idx]['name'] = $oldRaw;
                $owners[$idx]['contact'] = true;
                $row['owners'] = $owners;
            }
        }
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$contactId] = $row;

        return 'restored';
    });

    return is_string($status) ? $status : 'changed';
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_fill_name_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_name_candidates_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $fail = static function (string $error, int $status = 400) use ($dry, $undoId): array {
        return [
            'ok' => false,
            'error' => $error,
            'status' => $status,
            'action' => 'contacts.fill_name_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => preg_match('/^[a-f0-9]{16}$/', $undoId) === 1 ? $undoId : '',
            'restored' => [],
            'skipped' => [],
        ];
    };
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return $fail('Ogiltigt undo_id.');
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return $fail('Ingen lagring.');
    }
    $pdo = $store->pdo();
    try {
        $rows = jeab_inbox_link_contacts_read_undo($pdo, $undoId);
    } catch (Throwable) {
        return $fail('Kunde inte läsa ångerloggen.');
    }
    if ($rows === []) {
        return $fail('Ångerloggen saknas.', 404);
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['undone_at'] ?? null) !== null && trim((string) $row['undone_at']) !== '') {
            return $fail('Ångringen är redan gjord.', 409);
        }
        $action = trim((string) ($row['action'] ?? ''));
        if ($action !== 'fill_name') {
            return $fail('Ångringen är redan gjord.', 409);
        }
    }
    $restored = [];
    $skipped = [];
    foreach ($rows as $row) {
        if (!is_array($row) || trim((string) ($row['action'] ?? '')) !== 'fill_name') {
            continue;
        }
        $cid = trim((string) ($row['new_contact_id'] ?? ''));
        $bookingId = (int) ($row['thread_id'] ?? 0);
        if (jeab_contacts_fill_name_is_pending($row)) {
            $skipped[] = ['contact_id' => $cid, 'reason' => 'pending'];
            continue;
        }
        $old = (string) ($row['old_value'] ?? '');
        $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($cid) : null;
        if (!is_array($contact)) {
            $skipped[] = ['contact_id' => $cid, 'reason' => 'missing'];
            continue;
        }
        $booking = $bookingId > 0 ? $store->getBooking($bookingId) : null;
        $filled = is_array($booking) ? jeab_contacts_name_candidates_unique_name($booking, $store) : '';
        $snap = jeab_contacts_fill_name_parse_old($old);
        if ($dry) {
            if ($snap !== null) {
                $ok = jeab_contacts_fill_name_same_owners($contact, $snap, $filled);
            } else {
                $current = jeab_contacts_fill_name_current($cid);
                $ok = $filled !== '' && $current === $filled;
            }
            if ($ok) {
                $restored[] = ['contact_id' => $cid];
            } else {
                $skipped[] = ['contact_id' => $cid, 'reason' => 'changed'];
            }
            continue;
        }
        $status = jeab_contacts_fill_name_undo_apply($cid, $old, $filled);
        if ($status === 'restored') {
            $restored[] = ['contact_id' => $cid];
            jeab_contacts_name_candidates_cache_forget($bookingId);
        } else {
            $skipped[] = ['contact_id' => $cid, 'reason' => $status === 'missing' ? 'missing' : 'changed'];
        }
    }
    if (!$dry) {
        $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $mark = $pdo->prepare('UPDATE inbox_link_contacts_undo SET undone_at = ? WHERE undo_id = ? AND undone_at IS NULL');
        $mark->execute([$now, $undoId]);
    }

    return [
        'ok' => true,
        'action' => 'contacts.fill_name_undo',
        'dry' => $dry,
        'written' => !$dry && $restored !== [],
        'undo_id' => $undoId,
        'restored' => $restored,
        'skipped' => $skipped,
    ];
}
