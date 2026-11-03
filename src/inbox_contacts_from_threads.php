<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/inbox_link_contacts.php';
require_once __DIR__ . '/inkorg_contact_create.php';

/**
 * inbox.contacts_from_threads. dry=1 är standard.
 *
 * Kandidater är trådar utan contact_id, med bolag jarfalla/malmo, av typen
 * Ny lead eller Support (kundärende, inklusive äldre «Ärende från befintlig kund»),
 * som ger no_match både i e-post- och phone_address-matchningen från
 * inbox.link_contacts. Tråd-id som börjar med autotest- hoppas över med
 * reason test och räknas som övriga hopp. Kontaktuppgifter tas från leadtext,
 * customer_email och inbox_lead_party. Plattform, no-reply och egna domäner
 * filtreras med jeab_inkorg_address_blocked (samma lista som jeab_inkorg_party).
 *
 * dry=0 kräver confirm:create och INBOX_CONTACTS_FROM_THREADS=1 (jeab_flag_effective,
 * standard av). Av ger 403 och skriver inget. Saknad/fel confirm ger 400.
 * Ny kontakt skapas med jeab_kontakt_create_unmerged. Finns e-posten redan på
 * en kontakt i samma bolag länkas tråden i stället. Kontakter slås inte ihop
 * och ändras inte. Svaret har id, räknare och kodord. Inga namn eller adresser.
 *
 * Undo: inbox.contacts_from_threads_undo eller undo=1. Dry som standard.
 * Före varje create committas en rad action=create med tomt new_contact_id.
 * Efter lyckad create fylls contact_id i på den radens id. Länkraden committas
 * innan tråden pekas om, samma ordning som inbox.link_contacts. Misslyckad
 * logg skapar inget mer och ger 500. Create som kastar eller ger null avbryter
 * också med 500, så det som redan skapats kan ångras.
 * Undo tar bort trådlänken om den fortfarande är den satta. Skapade kontakter
 * raderas bara om ingen annan rad pekar på dem (reminders, bookings, purchases,
 * inkorg utöver de egna trådarna, solkampanj) och updated_at fortfarande är
 * samma som created_at. Annars skipped in_use|changed|missing. En create-rad
 * med tomt new_contact_id är pending. old_value är {"email","company"} så
 * undo kan hitta en kontakt som skapades inom tio sekunder efter raden
 * (inte tidigare, inte senare än raden plus tio sekunder) om id aldrig skrevs.
 * Radering bara om träffen är entydig och oanvänd enligt reglerna ovan,
 * annars skipped pending. Redan gjord undo ger 409.
 *
 * Testkrok, läses bara i loggskrivningen och i create:
 * $GLOBALS['_JEAB_ICFT_FAIL_LOG'] = true får loggskrivningen att kasta.
 * $GLOBALS['_JEAB_ICFT_FAIL_CREATE_AT'] = N får create nummer N att kasta.
 */

function jeab_inbox_contacts_from_threads_wants_undo(array $input): bool
{
    return function_exists('jeab_inbox_link_contacts_wants_undo')
        && jeab_inbox_link_contacts_wants_undo($input);
}

function jeab_inbox_contacts_from_threads_dry(array $input): bool
{
    return function_exists('jeab_inbox_link_contacts_dry')
        ? jeab_inbox_link_contacts_dry($input)
        : true;
}

function jeab_inbox_contacts_from_threads_flag_on(): bool
{
    if (!function_exists('jeab_flag_effective')) {
        return false;
    }
    $flag = jeab_flag_effective('INBOX_CONTACTS_FROM_THREADS');

    return (int) ($flag['value'] ?? 0) === 1;
}

/** Ny lead och Support (kundärende). */
function jeab_inbox_contacts_from_threads_type_ok(string $type): bool
{
    return $type === 'Ny lead' || $type === 'Support';
}

/**
 * @return array{companies:array<string, array<string, int>>,total:array<string, int>}
 */
function jeab_inbox_contacts_from_threads_counts(): array
{
    $zero = ['would_create' => 0, 'would_link_existing' => 0, 'skipped' => 0];

    return [
        'companies' => ['jarfalla' => $zero, 'malmo' => $zero],
        'total' => $zero,
    ];
}

/**
 * @param array{companies:array<string, array<string, int>>,total:array<string, int>} $counts
 */
function jeab_inbox_contacts_from_threads_bump(array &$counts, string $company, string $kind): void
{
    if (function_exists('jeab_inbox_link_contacts_bump')) {
        jeab_inbox_link_contacts_bump($counts, $company, $kind);
    }
}

/**
 * Råa fält från leadtext, sparad kopia och inbox_lead_party.
 *
 * @param array<string, mixed> $item
 * @return array{email:string,name:string,phone:string,blocked:bool,found:bool}
 */
function jeab_inbox_contacts_from_threads_party(array $item): array
{
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $tid = (string) ($item['id'] ?? '');
    $emails = [];
    $addEmail = static function (string $raw) use (&$emails): void {
        $raw = function_exists('jeab_norm_email') ? jeab_norm_email($raw) : strtolower(trim($raw));
        if ($raw !== '' && str_contains($raw, '@') && !in_array($raw, $emails, true)) {
            $emails[] = $raw;
        }
    };
    if (function_exists('jeab_inbox_link_contacts_customer_email')) {
        $addEmail(jeab_inbox_link_contacts_customer_email($meta));
    }
    $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
    $addEmail((string) ($lead['email'] ?? ''));
    $addEmail((string) ($meta['customer_email'] ?? ''));
    $msg = function_exists('jeab_inbox_link_contacts_lead_msg') ? jeab_inbox_link_contacts_lead_msg($meta) : $meta;
    if (function_exists('jeab_lead_customer')) {
        $cust = jeab_lead_customer($msg);
        if (is_array($cust)) {
            $addEmail((string) ($cust['email'] ?? ''));
        }
    }
    $body = (string) ($msg['body'] ?? $msg['body_text'] ?? $meta['body'] ?? '');
    if ($body !== '' && function_exists('jeab_lead_kund_line')) {
        $line = jeab_lead_kund_line($body);
        $addEmail((string) ($line['email'] ?? ''));
    }
    $partyRow = ($tid !== '' && function_exists('jeab_lead_party_get')) ? jeab_lead_party_get($tid) : [];
    $addEmail((string) ($partyRow['email'] ?? ''));

    $names = [];
    foreach ([
        (string) ($lead['name'] ?? ''),
        (string) ($meta['customer_name'] ?? ''),
        (string) ($partyRow['name'] ?? ''),
    ] as $name) {
        $name = trim($name);
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    if (function_exists('jeab_lead_customer')) {
        $cust = isset($cust) && is_array($cust) ? $cust : jeab_lead_customer($msg);
        $fromCust = trim((string) ($cust['name'] ?? ''));
        if ($fromCust !== '' && !in_array($fromCust, $names, true)) {
            $names[] = $fromCust;
        }
    }
    $phone = function_exists('jeab_inbox_link_contacts_thread_phone')
        ? jeab_inbox_link_contacts_thread_phone($meta, $tid)
        : '';

    $usable = '';
    $blocked = false;
    foreach ($emails as $email) {
        if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email)) {
            $blocked = true;
            continue;
        }
        if ($usable === '') {
            $usable = $email;
        }
    }

    return [
        'email' => $usable,
        'name' => $names[0] ?? '',
        'phone' => $phone,
        'blocked' => $blocked && $usable === '',
        'found' => $emails !== [],
    ];
}

/**
 * @param array<string, mixed> $item
 * @param array<string, array<string, array<string, true>>> $emailIndex
 * @param array<string, array<string, array<string, true>>> $phoneIndex
 * @param array<string, array<string, array<string, true>>> $addrIndex
 */
function jeab_inbox_contacts_from_threads_a_no_match(array $item, array $emailIndex, array $phoneIndex, array $addrIndex): bool
{
    $email = function_exists('jeab_inbox_link_contacts_email_hits')
        ? jeab_inbox_link_contacts_email_hits($item, $emailIndex)
        : [];
    $phone = function_exists('jeab_inbox_link_contacts_phone_hits')
        ? jeab_inbox_link_contacts_phone_hits($item, $phoneIndex)
        : [];
    $addr = function_exists('jeab_inbox_link_contacts_address_hits')
        ? jeab_inbox_link_contacts_address_hits($item, $addrIndex)
        : [];

    return $email === [] && $phone === [] && $addr === [];
}

/**
 * @param array<string, array<string, array<string, true>>> $index
 * @return list<string>
 */
function jeab_inbox_contacts_from_threads_email_in_company(array $index, string $company, string $email): array
{
    if ($email === '' || ($company !== 'jarfalla' && $company !== 'malmo')) {
        return [];
    }
    $ids = array_keys($index[$company][$email] ?? []);
    $ids = array_values(array_map('strval', $ids));
    sort($ids, SORT_STRING);

    return $ids;
}

/**
 * @param array<string, array<string, array<string, true>>> $index
 * @return list<string>
 */
function jeab_inbox_contacts_from_threads_email_other(array $index, string $company, string $email): array
{
    if ($email === '') {
        return [];
    }
    $out = [];
    foreach (['jarfalla', 'malmo'] as $office) {
        if ($office === $company) {
            continue;
        }
        foreach (array_keys($index[$office][$email] ?? []) as $id) {
            $out[] = (string) $id;
        }
    }
    sort($out, SORT_STRING);

    return $out;
}

/**
 * En ångerrad committas innan kontakten eller trådlänken skrivs.
 * Testkrok: $GLOBALS['_JEAB_ICFT_FAIL_LOG'] läses bara här.
 *
 * @param array{thread_id:string,tbl:string,old_contact_id:string,new_contact_id:string,action:string,old_value?:?string} $row
 */
function jeab_inbox_contacts_from_threads_log_insert(PDO $pdo, string $undoId, array $row): int|false
{
    if (!empty($GLOBALS['_JEAB_ICFT_FAIL_LOG'])) {
        throw new RuntimeException('Kunde inte skriva ångerloggen.');
    }
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return false;
    }
    if (!function_exists('jeab_ensure_inbox_link_undo_table') || !function_exists('jeab_pdo_transaction')) {
        return false;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    jeab_ensure_inbox_link_undo_table($pdo, $driver);
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $id = jeab_pdo_transaction($pdo, static function (PDO $tx) use ($undoId, $row, $now): int {
        $tbl = (string) ($row['tbl'] ?? '');
        $threadId = trim((string) ($row['thread_id'] ?? ''));
        $action = trim((string) ($row['action'] ?? 'link'));
        if ($action === '') {
            $action = 'link';
        }
        if (!in_array($action, ['link', 'create', 'fill_name'], true)) {
            throw new RuntimeException('Kunde inte skriva ångerloggen.');
        }
        $okTbl = in_array($tbl, ['inkorg_list', 'inkorg_threads'], true)
            || ($tbl === 'contacts' && in_array($action, ['create', 'fill_name'], true));
        if ($threadId === '' || !$okTbl) {
            throw new RuntimeException('Kunde inte skriva ångerloggen.');
        }
        $stmt = $tx->prepare(
            'INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ran = $stmt->execute([
            $undoId,
            $now,
            $threadId,
            $tbl,
            (string) ($row['old_contact_id'] ?? ''),
            (string) ($row['new_contact_id'] ?? ''),
            $action,
            array_key_exists('old_value', $row) ? $row['old_value'] : null,
        ]);
        if ($ran !== true) {
            return 0;
        }
        $newId = (int) $tx->lastInsertId();

        return $newId > 0 ? $newId : 0;
    });

    return is_int($id) && $id > 0 ? $id : false;
}

/** Efter lyckad create. WHERE id är den committade pending-raden. */
function jeab_inbox_contacts_from_threads_log_set_contact(PDO $pdo, int $rowId, string $contactId): bool
{
    $contactId = trim($contactId);
    if ($rowId <= 0 || $contactId === '' || !function_exists('jeab_pdo_transaction')) {
        return false;
    }
    $ok = jeab_pdo_transaction($pdo, static function (PDO $tx) use ($rowId, $contactId): bool {
        $stmt = $tx->prepare('UPDATE inbox_link_contacts_undo SET new_contact_id = ? WHERE id = ?');
        $ran = $stmt->execute([$contactId, $rowId]);
        if ($ran !== true) {
            return false;
        }
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $check = $tx->prepare('SELECT new_contact_id FROM inbox_link_contacts_undo WHERE id = ?');
        $check->execute([$rowId]);

        return trim((string) $check->fetchColumn()) === $contactId;
    });

    return $ok === true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_contacts_from_threads(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_inbox_contacts_from_threads_dry($input);
    $fail = static function (string $error, int $status = 400) use ($dry): array {
        return [
            'ok' => false,
            'error' => $error,
            'status' => $status,
            'action' => 'inbox.contacts_from_threads',
            'dry' => $dry,
            'written' => false,
            'undo_id' => '',
        ];
    };
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'create') {
        return $fail('Kräver confirm:create.', 400);
    }
    if (!$dry && !jeab_inbox_contacts_from_threads_flag_on()) {
        return $fail('INBOX_CONTACTS_FROM_THREADS är av.', 403);
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return $fail('Ingen lagring.');
    }
    // Kontakter skapas bara i SQL. Ligger registret i JSON skrivs inget.
    if (!$dry && (!function_exists('jeab_kontakter_pdo_store') || !jeab_kontakter_pdo_store() instanceof JeabPdoContactStore)) {
        return $fail('Kontaktregistret ligger inte i SQL.', 409);
    }
    $scope = function_exists('jeab_inbox_link_contacts_scope')
        ? jeab_inbox_link_contacts_scope($input)
        : ['company' => null, 'limit' => null, 'error' => ''];
    if (($scope['error'] ?? '') !== '') {
        return $fail((string) $scope['error']);
    }
    $pdo = $store->pdo();
    $bag = jeab_inbox_link_contacts_load($pdo);
    $emailIndex = jeab_inbox_link_contacts_index($pdo);
    $fields = jeab_inbox_link_contacts_field_index($pdo);
    $phoneIndex = is_array($fields['phones'] ?? null) ? $fields['phones'] : [];
    $addrIndex = is_array($fields['addrs'] ?? null) ? $fields['addrs'] : [];
    $ids = array_keys($bag);
    sort($ids, SORT_STRING);
    if ($scope['limit'] !== null) {
        $ids = array_slice($ids, 0, $scope['limit']);
    }
    $wouldCreate = [];
    $wouldLink = [];
    $skipped = [];
    $counts = jeab_inbox_contacts_from_threads_counts();
    $plan = [];
    foreach ($ids as $id) {
        $item = $bag[$id];
        if (!is_array($item)) {
            continue;
        }
        $company = (string) ($item['company'] ?? '');
        if (str_starts_with($id, 'autotest-')) {
            $skipped[] = ['thread_id' => $id, 'reason' => 'test'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        if (!empty($item['linked'])) {
            $skipped[] = ['thread_id' => $id, 'reason' => 'linked'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        if ($scope['company'] !== null && $company !== $scope['company']) {
            continue;
        }
        if ($company !== 'jarfalla' && $company !== 'malmo') {
            $skipped[] = ['thread_id' => $id, 'reason' => 'no_company'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        $type = (string) ($item['type'] ?? '');
        if (!jeab_inbox_contacts_from_threads_type_ok($type)) {
            $skipped[] = ['thread_id' => $id, 'reason' => 'type'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        if (!jeab_inbox_contacts_from_threads_a_no_match($item, $emailIndex, $phoneIndex, $addrIndex)) {
            continue;
        }
        $party = jeab_inbox_contacts_from_threads_party($item);
        if ($party['blocked']) {
            $skipped[] = ['thread_id' => $id, 'reason' => 'platform'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        if ($party['email'] === '') {
            $skipped[] = ['thread_id' => $id, 'reason' => 'no_email'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        $same = jeab_inbox_contacts_from_threads_email_in_company($emailIndex, $company, $party['email']);
        if (count($same) === 1) {
            $wouldLink[] = ['thread_id' => $id, 'contact_id' => $same[0]];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'would_link_existing');
            $plan[] = ['kind' => 'link', 'id' => $id, 'item' => $item, 'contact_id' => $same[0], 'company' => $company];
            continue;
        }
        if (count($same) >= 2) {
            continue;
        }
        $other = jeab_inbox_contacts_from_threads_email_other($emailIndex, $company, $party['email']);
        if ($other !== []) {
            $skipped[] = ['thread_id' => $id, 'reason' => 'other_company'];
            jeab_inbox_contacts_from_threads_bump($counts, $company, 'skipped');
            continue;
        }
        $wouldCreate[] = ['thread_id' => $id];
        jeab_inbox_contacts_from_threads_bump($counts, $company, 'would_create');
        $plan[] = [
            'kind' => 'create',
            'id' => $id,
            'item' => $item,
            'company' => $company,
            'name' => $party['name'],
            'email' => $party['email'],
            'phone' => $party['phone'],
        ];
    }
    $sortThread = static function (array $a, array $b): int {
        return strcmp((string) ($a['thread_id'] ?? ''), (string) ($b['thread_id'] ?? ''));
    };
    usort($wouldCreate, $sortThread);
    usort($wouldLink, $sortThread);
    usort($skipped, $sortThread);
    $out = [
        'ok' => true,
        'action' => 'inbox.contacts_from_threads',
        'dry' => $dry,
        'written' => false,
        'undo_id' => '',
        'would_create' => $wouldCreate,
        'would_link_existing' => $wouldLink,
        'skipped' => $skipped,
        'companies' => $counts['companies'],
        'total' => $counts['total'],
    ];
    if ($dry || $plan === []) {
        return $out;
    }
    $undoId = bin2hex(random_bytes(8));
    $made = [];
    $wrote = false;
    $logged = false;
    $createNo = 0;
    $stop = static function (string $error) use ($dry, $undoId, &$wrote, &$logged): array {
        return [
            'ok' => false,
            'error' => $error,
            'status' => 500,
            'action' => 'inbox.contacts_from_threads',
            'dry' => $dry,
            'written' => $wrote,
            'undo_id' => ($wrote || $logged) ? $undoId : '',
        ];
    };
    foreach ($plan as $job) {
        $contactId = trim((string) ($job['contact_id'] ?? ''));
        $email = (string) ($job['email'] ?? '');
        $company = (string) ($job['company'] ?? '');
        if (($job['kind'] ?? '') === 'create') {
            if ($email !== '' && isset($made[$company][$email])) {
                $contactId = $made[$company][$email];
            } else {
                if (!function_exists('jeab_kontakt_create_unmerged')) {
                    return $fail('Kunde inte skapa kontakt.');
                }
                try {
                    $pendingOld = json_encode(
                        ['email' => $email, 'company' => $company],
                        JSON_UNESCAPED_UNICODE
                    );
                    $pendingId = jeab_inbox_contacts_from_threads_log_insert($pdo, $undoId, [
                        'thread_id' => (string) $job['id'],
                        'tbl' => 'contacts',
                        'old_contact_id' => '',
                        'new_contact_id' => '',
                        'action' => 'create',
                        'old_value' => is_string($pendingOld) ? $pendingOld : null,
                    ]);
                } catch (Throwable) {
                    return $stop('Kunde inte skriva ångerloggen.');
                }
                if ($pendingId === false) {
                    return $stop('Kunde inte skriva ångerloggen.');
                }
                $logged = true;
                $createNo++;
                try {
                    // Testkrok: läses bara här. N är create-numret i den här körningen.
                    $failAt = $GLOBALS['_JEAB_ICFT_FAIL_CREATE_AT'] ?? null;
                    if ($failAt !== null && (int) $failAt === $createNo) {
                        throw new RuntimeException('Skapa kontakt misslyckades.');
                    }
                    $fresh = jeab_kontakt_create_unmerged([
                        'name' => (string) ($job['name'] ?? ''),
                        'email' => $email,
                        'phone' => (string) ($job['phone'] ?? ''),
                        'company' => $company,
                        'source' => 'inbox.contacts_from_threads',
                    ]);
                } catch (Throwable) {
                    return $stop('Kunde inte skapa kontakt.');
                }
                if ($fresh === null) {
                    return $stop('Kunde inte skapa kontakt.');
                }
                $contactId = trim((string) ($fresh['id'] ?? ''));
                if ($contactId === '') {
                    return $stop('Kunde inte skapa kontakt.');
                }
                try {
                    $filled = jeab_inbox_contacts_from_threads_log_set_contact($pdo, $pendingId, $contactId);
                } catch (Throwable) {
                    return $stop('Kunde inte skriva ångerloggen.');
                }
                if ($filled !== true) {
                    return $stop('Kunde inte skriva ångerloggen.');
                }
                if ($email !== '') {
                    $made[$company][$email] = $contactId;
                    $emailIndex[$company][$email][$contactId] = true;
                }
                $wrote = true;
            }
        }
        if ($contactId === '') {
            continue;
        }
        $item = is_array($job['item'] ?? null) ? $job['item'] : [];
        $rows = is_array($item['rows'] ?? null) ? $item['rows'] : [];
        foreach ($rows as $table => $stored) {
            if (!is_array($stored)) {
                continue;
            }
            $link = jeab_inbox_link_contacts_plan_row((string) $table, (string) $job['id'], $stored, $contactId);
            if ($link === null) {
                continue;
            }
            try {
                $linkId = jeab_inbox_contacts_from_threads_log_insert($pdo, $undoId, jeab_inbox_link_contacts_undo_row($link));
            } catch (Throwable) {
                return $stop('Kunde inte skriva ångerloggen.');
            }
            if ($linkId === false) {
                return $stop('Kunde inte skriva ångerloggen.');
            }
            $logged = true;
            if (jeab_inbox_link_contacts_apply($pdo, $link)) {
                $wrote = true;
            }
        }
    }
    if (!$logged && !$wrote) {
        return $out;
    }
    $out['dry'] = false;
    $out['written'] = $wrote;
    $out['undo_id'] = $undoId;

    return $out;
}

/**
 * @return list<string>
 */
function jeab_inbox_contacts_from_threads_own_threads(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $action = trim((string) ($row['action'] ?? 'link'));
        if ($action === '') {
            $action = 'link';
        }
        if ($action !== 'link') {
            continue;
        }
        $id = trim((string) ($row['thread_id'] ?? ''));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

function jeab_inbox_contacts_from_threads_contact_in_use(PDO $pdo, string $cid, array $ownThreads): bool
{
    $cid = trim($cid);
    if ($cid === '') {
        return true;
    }
    $own = [];
    foreach ($ownThreads as $tid) {
        $own[(string) $tid] = true;
    }
    $check = static function (string $sql, array $params) use ($pdo): bool {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    };
    if ($check('SELECT COUNT(*) FROM reminders WHERE contact_id = ?', [$cid])) {
        return true;
    }
    if ($check('SELECT COUNT(*) FROM bookings WHERE contact_id = ?', [$cid])) {
        return true;
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE contact_id = ?');
        $stmt->execute([$cid]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }
    } catch (Throwable) {
    }
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        try {
            $stmt = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE contact_id = ?');
            $stmt->execute([$cid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '' && !isset($own[$id])) {
                    return true;
                }
            }
        } catch (Throwable) {
        }
        try {
            $stmt = $pdo->query('SELECT id, row_json FROM ' . $table);
            foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '' || isset($own[$id])) {
                    continue;
                }
                $raw = (string) ($row['row_json'] ?? '');
                if ($raw === '') {
                    continue;
                }
                $json = json_decode($raw, true);
                if (is_array($json) && trim((string) ($json['contact_id'] ?? '')) === $cid) {
                    return true;
                }
            }
        } catch (Throwable) {
        }
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM solkampanj_recipients WHERE contact_id = ?');
        $stmt->execute([$cid]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }
    } catch (Throwable) {
    }

    return false;
}

function jeab_inbox_contacts_from_threads_contact_changed(array $contact): bool
{
    $created = trim((string) ($contact['created_at'] ?? ''));
    $updated = trim((string) ($contact['updated_at'] ?? ''));
    if ($created === '' || $updated === '') {
        return true;
    }

    return $created !== $updated;
}

/**
 * @return array{email:string,company:string}|null
 */
function jeab_inbox_contacts_from_threads_pending_hint(mixed $old): ?array
{
    if (!is_string($old) || $old === '' || $old[0] !== '{') {
        return null;
    }
    $decoded = json_decode($old, true);
    if (!is_array($decoded)) {
        return null;
    }
    $email = trim((string) ($decoded['email'] ?? ''));
    $company = trim((string) ($decoded['company'] ?? ''));
    if ($email === '' || ($company !== 'jarfalla' && $company !== 'malmo')) {
        return null;
    }

    return ['email' => $email, 'company' => $company];
}

function jeab_inbox_contacts_from_threads_pending_stamp(PDO $pdo, array $row): string
{
    try {
        $stmt = $pdo->prepare(
            'SELECT created_at, old_value FROM inbox_link_contacts_undo'
            . ' WHERE undo_id = ? AND thread_id = ? AND action = ? AND new_contact_id = ? ORDER BY id'
        );
        $stmt->execute([
            (string) ($row['undo_id'] ?? ''),
            (string) ($row['thread_id'] ?? ''),
            'create',
            '',
        ]);
        $want = (string) ($row['old_value'] ?? '');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            if ((string) ($hit['old_value'] ?? '') !== $want) {
                continue;
            }
            $stamp = trim((string) ($hit['created_at'] ?? ''));
            if ($stamp !== '') {
                return $stamp;
            }
        }
    } catch (Throwable) {
        return '';
    }

    return '';
}

/**
 * Senaste tidsstämpel som fortfarande hör till ångerraden. Tomt om radens tid inte går att läsa.
 */
function jeab_inbox_contacts_from_threads_pending_until(string $after): string
{
    $after = trim($after);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $after) !== 1) {
        return '';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $after, $tz);
    if (!$dt instanceof DateTimeImmutable || $dt->format('Y-m-d H:i:s') !== $after) {
        return '';
    }

    return $dt->modify('+10 seconds')->format('Y-m-d H:i:s');
}

/**
 * Kontakter med e-posten i bolaget, skapade inom tio sekunder efter ångerraden.
 * Sekundprecision: samma tidsstämpel räknas, annars missas en kontakt från samma sekund.
 * Tidigare än raden, eller senare än raden plus tio sekunder, räknas inte.
 *
 * @return list<string>
 */
function jeab_inbox_contacts_from_threads_pending_ids(string $email, string $company, string $after): array
{
    $after = trim($after);
    $want = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : strtolower(trim($email));
    $until = jeab_inbox_contacts_from_threads_pending_until($after);
    if ($want === '' || $after === '' || $until === '' || ($company !== 'jarfalla' && $company !== 'malmo')) {
        return [];
    }
    $rows = [];
    $store = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if (is_object($store) && method_exists($store, 'all')) {
        $loaded = $store->all();
        $rows = is_array($loaded) ? $loaded : [];
    } elseif (function_exists('jeab_kontakter_load')) {
        $rows = jeab_kontakter_load();
    }
    $ids = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? $key));
        if ($id === '') {
            continue;
        }
        $office = trim((string) ($row['company'] ?? ''));
        if (function_exists('jeab_address_company')) {
            $norm = jeab_address_company($office);
            if ($norm !== '') {
                $office = $norm;
            }
        }
        if ($office !== $company) {
            continue;
        }
        $created = trim((string) ($row['created_at'] ?? ''));
        if ($created === '' || strcmp($created, $after) < 0 || strcmp($created, $until) > 0) {
            continue;
        }
        $emails = function_exists('jeab_contact_email_list') ? jeab_contact_email_list($row) : [];
        if (!in_array($want, $emails, true)) {
            continue;
        }
        $ids[$id] = true;
    }
    $out = array_keys($ids);
    sort($out, SORT_STRING);

    return $out;
}

/**
 * Ett id bara när träffen är entydig och oanvänd. Annars tomt (skipped pending).
 *
 * @param array<string, mixed> $row
 * @param list<string> $ownThreads
 */
function jeab_inbox_contacts_from_threads_pending_contact(PDO $pdo, array $row, array $ownThreads): string
{
    $hint = jeab_inbox_contacts_from_threads_pending_hint($row['old_value'] ?? null);
    if ($hint === null) {
        return '';
    }
    $after = jeab_inbox_contacts_from_threads_pending_stamp($pdo, $row);
    if ($after === '') {
        return '';
    }
    $ids = jeab_inbox_contacts_from_threads_pending_ids($hint['email'], $hint['company'], $after);
    if (count($ids) !== 1) {
        return '';
    }
    $cid = $ids[0];
    $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($cid) : null;
    if (!is_array($contact)) {
        return '';
    }
    if (jeab_inbox_contacts_from_threads_contact_in_use($pdo, $cid, $ownThreads)) {
        return '';
    }
    if (jeab_inbox_contacts_from_threads_contact_changed($contact)) {
        return '';
    }

    return $cid;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_contacts_from_threads_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_inbox_contacts_from_threads_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $fail = static function (string $error, int $status = 400) use ($dry, $undoId): array {
        return [
            'ok' => false,
            'error' => $error,
            'status' => $status,
            'action' => 'inbox.contacts_from_threads_undo',
            'dry' => $dry,
            'written' => false,
            'undo_id' => preg_match('/^[a-f0-9]{16}$/', $undoId) === 1 ? $undoId : '',
            'restored' => [],
            'deleted' => [],
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
    $sawCreate = false;
    $sawLink = false;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (($row['undone_at'] ?? null) !== null && trim((string) $row['undone_at']) !== '') {
            return $fail('Ångringen är redan gjord.', 409);
        }
        $action = trim((string) ($row['action'] ?? 'link'));
        if ($action === '') {
            $action = 'link';
        }
        if ($action === 'fill_name') {
            return $fail('Ångringen är redan gjord.', 409);
        }
        if ($action === 'create') {
            $sawCreate = true;
        }
        if ($action === 'link') {
            $sawLink = true;
        }
    }
    if (!$sawCreate && !$sawLink) {
        return $fail('Ångringen är redan gjord.', 409);
    }
    $ownThreads = jeab_inbox_contacts_from_threads_own_threads($rows);
    $skipped = [];
    $plan = [];
    $delete = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $action = trim((string) ($row['action'] ?? 'link'));
        if ($action === '') {
            $action = 'link';
        }
        if ($action === 'create') {
            $cid = trim((string) ($row['new_contact_id'] ?? ''));
            if ($cid === '') {
                // Pending utan id. old_value kan peka ut kontakten som hann skapas.
                $pendingId = jeab_inbox_contacts_from_threads_pending_contact($pdo, $row, $ownThreads);
                if ($pendingId === '') {
                    $skipped[] = ['contact_id' => '', 'reason' => 'pending'];
                    continue;
                }
                $delete[] = $pendingId;
                continue;
            }
            $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($cid) : null;
            if (!is_array($contact)) {
                $skipped[] = ['contact_id' => $cid, 'reason' => 'missing'];
                continue;
            }
            if (jeab_inbox_contacts_from_threads_contact_in_use($pdo, $cid, $ownThreads)) {
                $skipped[] = ['contact_id' => $cid, 'reason' => 'in_use'];
                continue;
            }
            if (jeab_inbox_contacts_from_threads_contact_changed($contact)) {
                $skipped[] = ['contact_id' => $cid, 'reason' => 'changed'];
                continue;
            }
            $delete[] = $cid;
            continue;
        }
        if ($action !== 'link') {
            continue;
        }
        $id = trim((string) ($row['thread_id'] ?? ''));
        $table = (string) ($row['tbl'] ?? '');
        if ($id === '' || !in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
            continue;
        }
        $state = jeab_inbox_link_contacts_current($pdo, $table, $id);
        if ($state === null || !jeab_inbox_link_contacts_still($state, $row)) {
            $skipped[] = ['thread_id' => $id];
            continue;
        }
        $plan[] = ['row' => $row, 'state' => $state];
    }
    $restored = [];
    $deleted = [];
    if (!$dry) {
        foreach ($plan as $item) {
            $id = (string) ($item['row']['thread_id'] ?? '');
            if (jeab_inbox_link_contacts_restore($pdo, $item['state'], $item['row'])) {
                $restored[] = ['thread_id' => $id];
            } else {
                $skipped[] = ['thread_id' => $id];
            }
        }
        foreach (array_unique($delete) as $cid) {
            try {
                if (function_exists('jeab_kontakt_delete')) {
                    jeab_kontakt_delete($cid);
                    // Skyddet mot massradering kan vägra tyst. Kontrollera att raden är borta.
                    $left = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?');
                    $left->execute([$cid]);
                    if ((int) $left->fetchColumn() > 0) {
                        $skipped[] = ['contact_id' => $cid, 'reason' => 'refused'];
                        continue;
                    }
                    $deleted[] = ['contact_id' => $cid];
                }
            } catch (Throwable) {
                $skipped[] = ['contact_id' => $cid, 'reason' => 'in_use'];
            }
        }
        $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $mark = $pdo->prepare('UPDATE inbox_link_contacts_undo SET undone_at = ? WHERE undo_id = ? AND undone_at IS NULL');
        $mark->execute([$now, $undoId]);
    } else {
        foreach ($plan as $item) {
            $restored[] = ['thread_id' => (string) ($item['row']['thread_id'] ?? '')];
        }
        foreach (array_unique($delete) as $cid) {
            $deleted[] = ['contact_id' => $cid];
        }
    }
    $restored = function_exists('jeab_inbox_link_contacts_unique_threads')
        ? jeab_inbox_link_contacts_unique_threads($restored)
        : $restored;
    $skipOut = [];
    $seenSkip = [];
    foreach ($skipped as $row) {
        $key = (string) json_encode($row);
        if (isset($seenSkip[$key])) {
            continue;
        }
        $seenSkip[$key] = true;
        $skipOut[] = $row;
    }

    return [
        'ok' => true,
        'action' => 'inbox.contacts_from_threads_undo',
        'dry' => $dry,
        'written' => !$dry && ($restored !== [] || $deleted !== []),
        'undo_id' => $undoId,
        'restored' => $restored,
        'deleted' => $deleted,
        'skipped' => $skipOut,
    ];
}
