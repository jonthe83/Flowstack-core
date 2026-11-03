<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/contacts_sole_audit.php';
require_once __DIR__ . '/inkorg_contact_create.php';
require_once __DIR__ . '/inkorg_contacts.php';

/**
 * inbox.link_contacts. dry=1 är standard.
 *
 * Sätter contact_id på trådar som saknar länk, i inkorg_list och i trådens
 * metadata när båda finns. Parametern match är email (standard) eller
 * phone_address. email är oförändrat: e-posten är den inkorgen redan räknar
 * som kunden (jeab_inkorg_party / jeab_inkorg_resolve_thread_customer).
 * Plattformens och våra egna adresser räknas inte. Länken sätts bara när den
 * normaliserade adressen träffar exakt en kontakt i samma bolag. phone_address
 * gör samma e-poststeg först; saknas exakt en e-postträff prövas telefon
 * (leadtext eller inbox_lead_party, E.164) och därefter gata+postnummer.
 * Aldrig bara namn. Okänt match ger 400. Inget skapas, inget slås ihop och
 * kontakter ändras inte. Svaret har match_by per länk (email|phone|address).
 *
 * Trådar utan contact_id får stå kvar. De är inte fel.
 * dry=0 skriver en rad per ändring i inbox_link_contacts_undo, i en transaktion,
 * innan trådarna ändras. Den skrivningen kräver confirm:link. Annars 400
 * och inget skrivs, varken tråd eller ångerrad. dry=1 kräver inte confirm.
 * Ingen JSON-fil. undo=1, eller inbox.link_contacts_undo, är torr om dry
 * saknas eller är något annat än 0. Bara uttryckligt dry=0 skriver tillbaka
 * contact_id, och bara där den fortfarande är den som verktyget satte.
 * Okänt undo_id ger 404 även i torr körning. Första undo som skriver sätter
 * undone_at. Raderna ligger kvar. En andra undo mot samma undo_id ger 409
 * och rör inga trådar, även när den är torr. Undo kräver inte confirm.
 * Tomt gammalt värde blir tom sträng i kolumnen. I row_json tas nyckeln
 * contact_id bort när det gamla värdet är tomt.
 * Svaret har id och antal. Inga adresser och inga namn.
 */

function jeab_inbox_link_contacts_wants_undo(array $input): bool
{
    if (!array_key_exists('undo', $input)) {
        return false;
    }
    $value = $input['undo'];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function jeab_inbox_link_contacts_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** Ångra är torr om dry saknas. Bara uttryckligt dry=0 skriver. Samma tolkning som länken. */
function jeab_inbox_link_contacts_undo_dry(array $input): bool
{
    return jeab_inbox_link_contacts_dry($input);
}

function jeab_inbox_link_contacts_ident(string $name): string
{
    if (preg_match('/^[a-z_]+$/', $name) !== 1) {
        throw new RuntimeException('Ogiltigt namn.');
    }

    return $name;
}

/**
 * @param array<string, mixed> $input
 * @return array{company:?string,limit:?int,error:string}
 */
function jeab_inbox_link_contacts_scope(array $input): array
{
    $company = null;
    if (array_key_exists('company', $input) && $input['company'] !== null && trim((string) $input['company']) !== '') {
        $company = trim((string) $input['company']);
        if (!in_array($company, ['jarfalla', 'malmo'], true)) {
            return ['company' => null, 'limit' => null, 'error' => 'Ogiltigt bolag.'];
        }
    }
    $limit = null;
    if (array_key_exists('limit', $input) && $input['limit'] !== null && $input['limit'] !== '') {
        $raw = $input['limit'];
        if (is_int($raw) && $raw >= 0) {
            $limit = $raw;
        } elseif (is_string($raw) && preg_match('/^(0|[1-9][0-9]*)$/', $raw) === 1) {
            $limit = (int) $raw;
        } else {
            return ['company' => null, 'limit' => null, 'error' => 'Ogiltig limit.'];
        }
    }

    return ['company' => $company, 'limit' => $limit, 'error' => ''];
}

/**
 * @return array{companies:array<string, array<string, int>>,total:array<string, int>}
 */
function jeab_inbox_link_contacts_counts(): array
{
    $zero = ['would_link' => 0, 'ambiguous' => 0, 'no_match' => 0];

    return [
        'companies' => ['jarfalla' => $zero, 'malmo' => $zero],
        'total' => $zero,
    ];
}

/**
 * @param array{companies:array<string, array<string, int>>,total:array<string, int>} $counts
 */
function jeab_inbox_link_contacts_bump(array &$counts, string $company, string $kind): void
{
    if ($company === 'jarfalla' || $company === 'malmo') {
        $counts['companies'][$company][$kind]++;
    }
    $counts['total'][$kind]++;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
function jeab_inbox_link_contacts_has_link(array $row, ?array $json, array $cols): bool
{
    if (isset($cols['contact_id']) && trim((string) ($row['contact_id'] ?? '')) !== '') {
        return true;
    }

    return is_array($json) && trim((string) ($json['contact_id'] ?? '')) !== '';
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
function jeab_inbox_link_contacts_row_company(array $row, ?array $json, array $cols): string
{
    $raws = [];
    if (isset($cols['company'])) {
        $raws[] = (string) ($row['company'] ?? '');
    }
    if (is_array($json)) {
        foreach (['company', 'brand'] as $key) {
            $raws[] = (string) ($json[$key] ?? '');
        }
    }
    foreach ($raws as $raw) {
        $office = function_exists('jeab_contacts_sole_audit_office') ? jeab_contacts_sole_audit_office($raw) : '';
        if ($office === 'jarfalla' || $office === 'malmo') {
            return $office;
        }
    }

    return '';
}

/**
 * @return array<string, array<string, mixed>>
 */
function jeab_inbox_link_contacts_load(PDO $pdo): array
{
    $bag = [];
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        $cols = jeab_contacts_sole_audit_columns($pdo, $table);
        if (!isset($cols['id'])) {
            continue;
        }
        $select = ['id'];
        foreach (['contact_id', 'company', 'customer_email', 'row_json'] as $col) {
            if (isset($cols[$col])) {
                $select[] = $col;
            }
        }
        try {
            $sql = 'SELECT ' . implode(', ', array_map('jeab_inbox_link_contacts_ident', $select))
                . ' FROM ' . jeab_inbox_link_contacts_ident($table);
            $stmt = $pdo->query($sql);
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (!isset($bag[$id])) {
                $bag[$id] = [
                    'id' => $id,
                    'linked' => false,
                    'company' => '',
                    'type' => '',
                    'meta' => [],
                    'rows' => [],
                ];
            }
            $json = null;
            $raw = null;
            if (isset($cols['row_json']) && is_string($row['row_json'] ?? null) && $row['row_json'] !== '') {
                $raw = (string) $row['row_json'];
                $decoded = json_decode($raw, true);
                $json = is_array($decoded) ? $decoded : null;
            }
            if (jeab_inbox_link_contacts_has_link($row, $json, $cols)) {
                $bag[$id]['linked'] = true;
            }
            if ($bag[$id]['company'] === '') {
                $bag[$id]['company'] = jeab_inbox_link_contacts_row_company($row, $json, $cols);
            }
            if ($bag[$id]['type'] === '') {
                $bag[$id]['type'] = jeab_inbox_link_contacts_row_type($row, $json, $cols);
            }
            if (is_array($json)) {
                foreach ($json as $key => $value) {
                    $bag[$id]['meta'][$key] = $value;
                }
            }
            if (isset($cols['customer_email'])) {
                $columnEmail = trim((string) ($row['customer_email'] ?? ''));
                if ($columnEmail !== '' && trim((string) ($bag[$id]['meta']['customer_email'] ?? '')) === '') {
                    $bag[$id]['meta']['customer_email'] = $columnEmail;
                }
            }
            $bag[$id]['rows'][$table] = [
                'has_column' => isset($cols['contact_id']),
                'contact_id' => array_key_exists('contact_id', $row) ? $row['contact_id'] : null,
                'raw_json' => $raw,
                'json' => $json,
            ];
        }
    }

    return $bag;
}

/**
 * Kundens e-post, samma väg som inkorgen. Blockerade adresser blir tomt.
 *
 * @param array<string, mixed> $meta
 */
function jeab_inbox_link_contacts_customer_email(array $meta): string
{
    $email = '';
    if (function_exists('jeab_inkorg_party')) {
        $party = jeab_inkorg_party($meta);
        $email = (string) ($party['email'] ?? '');
    }
    if ($email === '') {
        $email = (string) ($meta['customer_email'] ?? '');
        if ($email === '' && is_array($meta['lead_customer'] ?? null)) {
            $email = (string) ($meta['lead_customer']['email'] ?? '');
        }
    }
    $email = function_exists('jeab_norm_email') ? jeab_norm_email($email) : strtolower(trim($email));
    if ($email === '' || (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email))) {
        return '';
    }
    if (function_exists('jeab_inkorg_contact_create_email_key')) {
        $email = jeab_inkorg_contact_create_email_key($email);
    }

    return $email;
}

/**
 * Typen från labels.type, list_type eller äldre namn via jeab_inkorg_type_new.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $json
 * @param array<string, true> $cols
 */
function jeab_inbox_link_contacts_row_type(array $row, ?array $json, array $cols): string
{
    $raw = '';
    if (is_array($json)) {
        $first = is_array($json['first'] ?? null) ? $json['first'] : [];
        $labels = is_array($first['labels'] ?? null) ? $first['labels'] : [];
        if ($labels === [] && is_array($json['labels'] ?? null)) {
            $labels = $json['labels'];
        }
        $raw = (string) ($labels['type'] ?? $json['list_type'] ?? '');
    }
    if ($raw === '' && isset($cols['list_type'])) {
        $raw = (string) ($row['list_type'] ?? '');
    }
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    return function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new($raw) : $raw;
}

/**
 * @param array<string, mixed> $input
 * @return array{mode:string,error:string}
 */
function jeab_inbox_link_contacts_match_mode(array $input): array
{
    if (!array_key_exists('match', $input) || $input['match'] === null || $input['match'] === '') {
        return ['mode' => 'email', 'error' => ''];
    }
    if (!is_string($input['match']) && !is_int($input['match'])) {
        return ['mode' => '', 'error' => 'Ogiltigt match.'];
    }
    $raw = trim((string) $input['match']);
    if ($raw === 'email' || $raw === 'phone_address') {
        return ['mode' => $raw, 'error' => ''];
    }

    return ['mode' => '', 'error' => 'Ogiltigt match.'];
}

/** Gata: gemener, trim, hopslagna mellanslag. */
function jeab_inbox_link_contacts_norm_street(string $street): string
{
    $street = trim($street);
    if ($street === '') {
        return '';
    }
    $street = function_exists('mb_strtolower') ? mb_strtolower($street, 'UTF-8') : strtolower($street);
    $street = preg_replace('/\s+/u', ' ', $street) ?? $street;

    return trim($street);
}

/** Nyckel bara när både gata och postnummer (siffror) finns. */
function jeab_inbox_link_contacts_addr_key(string $street, string $postal): string
{
    $street = jeab_inbox_link_contacts_norm_street($street);
    $postal = function_exists('jeab_norm_postal') ? jeab_norm_postal($postal) : (preg_replace('/\D+/', '', $postal) ?? '');
    if ($street === '' || $postal === '') {
        return '';
    }

    return $street . '|' . $postal;
}

/**
 * Första mejlet i metadatan, annars tråden själv.
 *
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function jeab_inbox_link_contacts_lead_msg(array $meta): array
{
    if (is_array($meta['first'] ?? null)) {
        return $meta['first'];
    }
    $msgs = is_array($meta['messages'] ?? null) ? $meta['messages'] : [];
    foreach ($msgs as $msg) {
        if (is_array($msg)) {
            return $msg;
        }
    }

    return $meta;
}

/**
 * Telefon från leadtext eller inbox_lead_party, E.164.
 *
 * @param array<string, mixed> $meta
 */
function jeab_inbox_link_contacts_thread_phone(array $meta, string $threadId): string
{
    $cands = [];
    $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
    $cands[] = (string) ($lead['phone'] ?? $lead['mobile'] ?? '');
    $msg = jeab_inbox_link_contacts_lead_msg($meta);
    if (function_exists('jeab_lead_customer')) {
        $cust = jeab_lead_customer($msg);
        if (is_array($cust)) {
            $cands[] = (string) ($cust['phone'] ?? '');
        }
    }
    $body = (string) ($msg['body'] ?? $msg['body_text'] ?? $meta['body'] ?? '');
    if ($body !== '' && function_exists('jeab_lead_kund_line')) {
        $line = jeab_lead_kund_line($body);
        $cands[] = (string) ($line['phone'] ?? '');
    }
    if ($threadId !== '' && function_exists('jeab_lead_party_get')) {
        $party = jeab_lead_party_get($threadId);
        $cands[] = (string) ($party['phone'] ?? '');
    }
    foreach ($cands as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        $norm = function_exists('jeab_norm_phone_e164') ? jeab_norm_phone_e164($raw) : (function_exists('jeab_norm_phone') ? jeab_norm_phone($raw) : '');
        if ($norm !== '') {
            return $norm;
        }
    }

    return '';
}

/**
 * Gata, ort och postnummer från leadtext. Ort fylls från city/ort eller tolkad adress.
 *
 * @param array<string, mixed> $meta
 * @return array{street:string,city:string,postal:string}
 */
function jeab_inbox_link_contacts_address_parts(array $meta): array
{
    $street = '';
    $postal = '';
    $city = '';
    $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
    $street = trim((string) ($lead['street'] ?? ''));
    $postal = trim((string) ($lead['postal_code'] ?? $lead['postal'] ?? $lead['postnummer'] ?? ''));
    $city = trim((string) ($lead['city'] ?? $lead['ort'] ?? ''));
    $combined = trim((string) ($lead['address'] ?? $lead['adress'] ?? ''));
    $msg = jeab_inbox_link_contacts_lead_msg($meta);
    if (function_exists('jeab_lead_customer')) {
        $cust = jeab_lead_customer($msg);
        if (is_array($cust)) {
            if ($combined === '') {
                $combined = trim((string) ($cust['address'] ?? ''));
            }
            if ($street === '') {
                $street = trim((string) ($cust['street'] ?? ''));
            }
            if ($postal === '') {
                $postal = trim((string) ($cust['postal_code'] ?? $cust['postal'] ?? ''));
            }
            if ($city === '') {
                $city = trim((string) ($cust['city'] ?? $cust['ort'] ?? ''));
            }
        }
    }
    if (($street === '' || $postal === '' || $city === '') && $combined !== '' && function_exists('jeab_kontakt_parse_address')) {
        $parsed = jeab_kontakt_parse_address($combined);
        if ($street === '') {
            $street = trim((string) ($parsed['street'] ?? ''));
        }
        if ($postal === '') {
            $postal = trim((string) ($parsed['postal'] ?? ''));
        }
        if ($city === '') {
            $city = trim((string) ($parsed['city'] ?? ''));
        }
    }

    return ['street' => $street, 'city' => $city, 'postal' => $postal];
}

/**
 * Gata+postnummer från leadtext. Tomt om något av dem saknas.
 * phone_address använder jeab_inbox_link_contacts_address_parts i stället.
 *
 * @param array<string, mixed> $meta
 */
function jeab_inbox_link_contacts_thread_address(array $meta): string
{
    $parts = jeab_inbox_link_contacts_address_parts($meta);

    return jeab_inbox_link_contacts_addr_key($parts['street'], $parts['postal']);
}

/**
 * @param array<string, mixed> $item
 * @param array<string, array<string, array<string, true>>> $index
 * @return list<string>
 */
function jeab_inbox_link_contacts_email_hits(array $item, array $index): array
{
    $company = (string) ($item['company'] ?? '');
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $email = jeab_inbox_link_contacts_customer_email($meta);
    if ($email === '' || ($company !== 'jarfalla' && $company !== 'malmo')) {
        return [];
    }
    $matched = array_keys($index[$company][$email] ?? []);

    return array_values(array_map('strval', $matched));
}

/**
 * @param array<string, mixed> $item
 * @param array<string, array<string, array<string, true>>> $index
 * @return list<string>
 */
function jeab_inbox_link_contacts_phone_hits(array $item, array $index): array
{
    $company = (string) ($item['company'] ?? '');
    if ($company !== 'jarfalla' && $company !== 'malmo') {
        return [];
    }
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $phone = jeab_inbox_link_contacts_thread_phone($meta, (string) ($item['id'] ?? ''));
    if ($phone === '') {
        return [];
    }
    $matched = array_keys($index[$company][$phone] ?? []);

    return array_values(array_map('strval', $matched));
}

/**
 * @param array<string, mixed> $item
 * @param array<string, array<string, array<string, true>>> $index
 * @return list<string>
 */
function jeab_inbox_link_contacts_address_hits(array $item, array $index): array
{
    $company = (string) ($item['company'] ?? '');
    if ($company !== 'jarfalla' && $company !== 'malmo') {
        return [];
    }
    $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
    $parts = jeab_inbox_link_contacts_address_parts($meta);
    $street = (string) $parts['street'];
    $city = (string) $parts['city'];
    $postal = (string) $parts['postal'];
    $ort = function_exists('jeab_contact_address_key') ? jeab_contact_address_key($street, $city) : '';
    $pkey = function_exists('jeab_contact_address_postal_key') ? jeab_contact_address_postal_key($street, $postal) : '';
    $ids = [];
    $take = static function (string $key) use (&$ids, $index, $company): void {
        if ($key === '') {
            return;
        }
        foreach (array_keys($index[$company][$key] ?? []) as $id) {
            $ids[(string) $id] = true;
        }
    };
    // Gata+ort först. Saknas ort på tråden: gata+postnummer mot alla i bolaget.
    // Saknas ort bara på kontakten: samma postnummer-nyckel kompletterar ortträffen.
    if ($ort !== '') {
        $take('ort:' . $ort);
        if ($pkey !== '') {
            $take('post0:' . $pkey);
        }
    } elseif ($pkey !== '') {
        $take('post:' . $pkey);
    }

    return array_values(array_map('strval', array_keys($ids)));
}

/**
 * En träff blir would_link, två eller fler ambiguous, noll no_match.
 *
 * @param array<string, mixed> $item
 * @param array<string, array<string, array<string, true>>> $emailIndex
 * @param array<string, array<string, array<string, true>>> $phoneIndex
 * @param array<string, array<string, array<string, true>>> $addrIndex
 * @return array{kind:string,contact_ids:list<string>,match_by:string}
 */
function jeab_inbox_link_contacts_decide(array $item, array $emailIndex, array $phoneIndex, array $addrIndex, string $mode): array
{
    $empty = ['kind' => 'no_match', 'contact_ids' => [], 'match_by' => ''];
    $pack = static function (array $ids, string $by) use ($empty): array {
        sort($ids, SORT_STRING);
        if (count($ids) === 1) {
            return ['kind' => 'would_link', 'contact_ids' => $ids, 'match_by' => $by];
        }
        if (count($ids) >= 2) {
            return ['kind' => 'ambiguous', 'contact_ids' => $ids, 'match_by' => $by];
        }

        return $empty;
    };
    $emailHits = jeab_inbox_link_contacts_email_hits($item, $emailIndex);
    if ($mode === 'email') {
        return $pack($emailHits, 'email');
    }
    if (count($emailHits) === 1) {
        return $pack($emailHits, 'email');
    }
    $phoneHits = jeab_inbox_link_contacts_phone_hits($item, $phoneIndex);
    if ($phoneHits !== []) {
        return $pack($phoneHits, 'phone');
    }
    $addrHits = jeab_inbox_link_contacts_address_hits($item, $addrIndex);
    if ($addrHits !== []) {
        return $pack($addrHits, 'address');
    }
    if (count($emailHits) >= 2) {
        return $pack($emailHits, 'email');
    }

    return $empty;
}

/**
 * @return array<string, array<string, array<string, true>>>
 */
function jeab_inbox_link_contacts_index(PDO $pdo): array
{
    $index = [];
    if (!function_exists('jeab_inkorg_contact_create_email_directory')) {
        return $index;
    }
    foreach (jeab_inkorg_contact_create_email_directory($pdo) as $id => $info) {
        if (!is_array($info)) {
            continue;
        }
        $company = (string) ($info['company'] ?? '');
        $contactId = trim((string) $id);
        if (($company !== 'jarfalla' && $company !== 'malmo') || $contactId === '') {
            continue;
        }
        $emails = is_array($info['emails'] ?? null) ? $info['emails'] : [];
        foreach ($emails as $email => $yes) {
            if ($yes && is_string($email) && $email !== '') {
                $index[$company][$email][$contactId] = true;
            }
        }
    }

    return $index;
}

/**
 * Telefoner och gata+postnummer per bolag. Samma bolagskrav som e-postindexet.
 *
 * @return array{phones:array<string, array<string, array<string, true>>>,addrs:array<string, array<string, array<string, true>>>}
 */
function jeab_inbox_link_contacts_field_index(PDO $pdo): array
{
    $phones = [];
    $addrs = [];
    $put = static function (string $id, string $company, array $row, array $db = []) use (&$phones, &$addrs): void {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        if ($company !== 'jarfalla' && $company !== 'malmo') {
            $company = function_exists('jeab_inkorg_contact_create_contact_company')
                ? jeab_inkorg_contact_create_contact_company($row, $db)
                : '';
        }
        if ($company !== 'jarfalla' && $company !== 'malmo') {
            return;
        }
        $addPhone = static function (string $raw) use (&$phones, $company, $id): void {
            $norm = function_exists('jeab_norm_phone_e164') ? jeab_norm_phone_e164($raw) : (function_exists('jeab_norm_phone') ? jeab_norm_phone($raw) : '');
            if ($norm !== '') {
                $phones[$company][$norm][$id] = true;
            }
        };
        $addPhone((string) ($db['owner_phone'] ?? ''));
        $addPhone((string) ($row['phone'] ?? $row['mobile'] ?? ''));
        foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $addPhone((string) ($owner['phone'] ?? $owner['mobile'] ?? ''));
            }
        }
        $street = trim((string) ($row['street'] ?? ''));
        $postal = trim((string) ($row['postal_code'] ?? $row['postal'] ?? $db['postal_code'] ?? ''));
        $city = trim((string) ($row['city'] ?? $db['city'] ?? ''));
        if ($street === '') {
            $street = trim((string) ($row['address'] ?? ''));
        }
        if (($street === '' || $postal === '' || $city === '') && trim((string) ($row['address'] ?? '')) !== '' && function_exists('jeab_kontakt_parse_address')) {
            $parsed = jeab_kontakt_parse_address((string) $row['address']);
            if ($street === '') {
                $street = trim((string) ($parsed['street'] ?? ''));
            }
            if ($postal === '') {
                $postal = trim((string) ($parsed['postal'] ?? ''));
            }
            if ($city === '') {
                $city = trim((string) ($parsed['city'] ?? ''));
            }
        }
        $ort = function_exists('jeab_contact_address_key') ? jeab_contact_address_key($street, $city) : '';
        if ($ort !== '') {
            $addrs[$company]['ort:' . $ort][$id] = true;
        }
        $pkey = function_exists('jeab_contact_address_postal_key') ? jeab_contact_address_postal_key($street, $postal) : '';
        if ($pkey !== '') {
            $addrs[$company]['post:' . $pkey][$id] = true;
            $cityPart = function_exists('jeab_contact_address_part') ? jeab_contact_address_part($city, false) : trim($city);
            if ($cityPart === '') {
                $addrs[$company]['post0:' . $pkey][$id] = true;
            }
        }
    };
    if (function_exists('jeab_kontakter_load')) {
        foreach (jeab_kontakter_load() as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $put((string) ($row['id'] ?? $key), (string) ($row['company'] ?? ''), $row);
        }
    }
    $cols = function_exists('jeab_inkorg_contact_create_columns')
        ? jeab_inkorg_contact_create_columns($pdo, 'contacts')
        : [];
    if (in_array('id', $cols, true)) {
        $select = ['id'];
        foreach (['company', 'row_json', 'owner_phone', 'postal_code', 'city'] as $col) {
            if (in_array($col, $cols, true) && !in_array($col, $select, true)) {
                $select[] = $col;
            }
        }
        try {
            $sql = 'SELECT ' . implode(', ', array_map('jeab_inbox_link_contacts_ident', $select)) . ' FROM contacts';
            $stmt = $pdo->query($sql);
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable) {
            $rows = [];
        }
        foreach (is_array($rows) ? $rows : [] as $db) {
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
            $put((string) ($db['id'] ?? ''), (string) ($db['company'] ?? $json['company'] ?? ''), $json, $db);
        }
    }

    return ['phones' => $phones, 'addrs' => $addrs];
}

/**
 * @param array<string, mixed> $stored
 * @return array<string, mixed>|null
 */
function jeab_inbox_link_contacts_plan_row(string $table, string $id, array $stored, string $contactId): ?array
{
    if ($contactId === '' || !in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return null;
    }
    $hasColumn = !empty($stored['has_column']);
    $json = is_array($stored['json'] ?? null) ? $stored['json'] : null;
    $raw = is_string($stored['raw_json'] ?? null) ? (string) $stored['raw_json'] : null;
    $touched = false;
    $had = false;
    $prevJsonContact = '';
    $next = null;
    if (is_array($json) && is_string($raw)) {
        $had = array_key_exists('contact_id', $json);
        $prevJsonContact = $had ? trim((string) $json['contact_id']) : '';
        $json['contact_id'] = $contactId;
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return null;
        }
        $next = $encoded;
        $touched = true;
    } elseif (!$hasColumn) {
        return null;
    }
    $prevColumn = $stored['contact_id'] ?? null;
    $wasNull = $hasColumn && $prevColumn === null;

    return [
        'table' => $table,
        'id' => $id,
        'contact_id' => $contactId,
        'has_column' => $hasColumn,
        'column_was_null' => $wasNull,
        'prev_column' => $wasNull ? '' : trim((string) $prevColumn),
        'touched_json' => $touched,
        'had_json_key' => $had,
        'prev_json_contact_id' => $prevJsonContact,
        'prev_json' => $touched ? $raw : null,
        'next_json' => $next,
    ];
}

/**
 * @param array<string, mixed> $write
 */
function jeab_inbox_link_contacts_apply(PDO $pdo, array $write): bool
{
    $table = (string) ($write['table'] ?? '');
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return false;
    }
    $sets = [];
    $params = [];
    $wheres = ['id = ?'];
    $where = [(string) $write['id']];
    if (!empty($write['has_column'])) {
        $sets[] = 'contact_id = ?';
        $params[] = (string) $write['contact_id'];
        if (!empty($write['column_was_null'])) {
            $wheres[] = 'contact_id IS NULL';
        } else {
            $wheres[] = 'contact_id = ?';
            $where[] = (string) ($write['prev_column'] ?? '');
        }
    }
    if (!empty($write['touched_json'])) {
        $sets[] = 'row_json = ?';
        $params[] = (string) $write['next_json'];
        $wheres[] = 'row_json = ?';
        $where[] = (string) $write['prev_json'];
    }
    if ($sets === []) {
        return false;
    }
    $sql = 'UPDATE ' . jeab_inbox_link_contacts_ident($table)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $wheres);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $where));

    return $stmt->rowCount() === 1;
}

/**
 * @param array<string, mixed> $write
 * @return array{thread_id:string,tbl:string,old_contact_id:string,new_contact_id:string,action:string,old_value:?string}
 */
function jeab_inbox_link_contacts_undo_row(array $write): array
{
    $old = '';
    if (!empty($write['has_column']) && empty($write['column_was_null'])) {
        $old = trim((string) ($write['prev_column'] ?? ''));
    }
    if ($old === '' && !empty($write['touched_json'])) {
        $old = trim((string) ($write['prev_json_contact_id'] ?? ''));
    }
    $action = trim((string) ($write['action'] ?? 'link'));
    if (!in_array($action, ['link', 'create', 'fill_name'], true)) {
        $action = 'link';
    }

    return [
        'thread_id' => (string) $write['id'],
        'tbl' => (string) $write['table'],
        'old_contact_id' => $old,
        'new_contact_id' => (string) $write['contact_id'],
        'action' => $action,
        'old_value' => array_key_exists('old_value', $write) ? (is_string($write['old_value']) ? $write['old_value'] : null) : null,
    ];
}

/**
 * Alla rader för ett undo_id committas innan trådarna ändras.
 *
 * @param list<array{thread_id:string,tbl:string,old_contact_id:string,new_contact_id:string,action?:string,old_value?:?string}> $rows
 */
function jeab_inbox_link_contacts_write_undo(PDO $pdo, string $undoId, array $rows): void
{
    if ($rows === [] || preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        throw new RuntimeException('Kunde inte skriva ångerloggen.');
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (!function_exists('jeab_ensure_inbox_link_undo_table')) {
        throw new RuntimeException('Kunde inte skriva ångerloggen.');
    }
    jeab_ensure_inbox_link_undo_table($pdo, $driver);
    if (!function_exists('jeab_pdo_transaction')) {
        throw new RuntimeException('Kunde inte skriva ångerloggen.');
    }
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    jeab_pdo_transaction($pdo, static function (PDO $tx) use ($undoId, $rows, $now): void {
        $stmt = $tx->prepare(
            'INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
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
            $stmt->execute([
                $undoId,
                $now,
                $threadId,
                $tbl,
                (string) ($row['old_contact_id'] ?? ''),
                (string) ($row['new_contact_id'] ?? ''),
                $action,
                $row['old_value'] ?? null,
            ]);
        }
    });
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_inbox_link_contacts_read_undo(PDO $pdo, string $undoId): array
{
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return [];
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (function_exists('jeab_ensure_inbox_link_undo_table')) {
        jeab_ensure_inbox_link_undo_table($pdo, $driver);
    }
    $stmt = $pdo->prepare(
        'SELECT undo_id, thread_id, tbl, old_contact_id, new_contact_id, action, old_value, undone_at'
        . ' FROM inbox_link_contacts_undo WHERE undo_id = ? ORDER BY id'
    );
    $stmt->execute([$undoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

/**
 * @return array<string, mixed>|null
 */
function jeab_inbox_link_contacts_current(PDO $pdo, string $table, string $id): ?array
{
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true) || $id === '') {
        return null;
    }
    $cols = jeab_contacts_sole_audit_columns($pdo, $table);
    if (!isset($cols['id'])) {
        return null;
    }
    $select = ['id'];
    foreach (['contact_id', 'row_json'] as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT ' . implode(', ', array_map('jeab_inbox_link_contacts_ident', $select))
            . ' FROM ' . jeab_inbox_link_contacts_ident($table) . ' WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $json = null;
    $raw = null;
    if (isset($cols['row_json']) && is_string($row['row_json'] ?? null) && $row['row_json'] !== '') {
        $raw = (string) $row['row_json'];
        $decoded = json_decode($raw, true);
        $json = is_array($decoded) ? $decoded : null;
    }

    return [
        'has_column' => isset($cols['contact_id']),
        'contact_id' => array_key_exists('contact_id', $row) ? $row['contact_id'] : null,
        'raw_json' => $raw,
        'json' => $json,
    ];
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $row
 */
function jeab_inbox_link_contacts_still(array $state, array $row): bool
{
    $set = trim((string) ($row['new_contact_id'] ?? ''));
    if ($set === '') {
        return false;
    }
    $saw = false;
    if (!empty($state['has_column'])) {
        $saw = true;
        $current = $state['contact_id'] ?? null;
        if ($current === null || trim((string) $current) !== $set) {
            return false;
        }
    }
    $raw = $state['raw_json'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $saw = true;
        $json = $state['json'] ?? null;
        if (!is_array($json) || trim((string) ($json['contact_id'] ?? '')) !== $set) {
            return false;
        }
    }

    return $saw;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $row
 */
function jeab_inbox_link_contacts_restore(PDO $pdo, array $state, array $row): bool
{
    $table = (string) ($row['tbl'] ?? '');
    $id = (string) ($row['thread_id'] ?? '');
    $set = trim((string) ($row['new_contact_id'] ?? ''));
    $old = (string) ($row['old_contact_id'] ?? '');
    if (!in_array($table, ['inkorg_list', 'inkorg_threads'], true) || $id === '' || $set === '') {
        return false;
    }
    $sets = [];
    $params = [];
    $wheres = ['id = ?'];
    $where = [$id];
    if (!empty($state['has_column'])) {
        $sets[] = 'contact_id = ?';
        $params[] = $old;
        $wheres[] = 'contact_id = ?';
        $where[] = $set;
    }
    if (is_array($state['json'] ?? null) && is_string($state['raw_json'] ?? null)) {
        $json = $state['json'];
        if ($old === '') {
            unset($json['contact_id']);
        } else {
            $json['contact_id'] = $old;
        }
        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return false;
        }
        $sets[] = 'row_json = ?';
        $params[] = $encoded;
        $wheres[] = 'row_json = ?';
        $where[] = (string) $state['raw_json'];
    }
    if ($sets === []) {
        return false;
    }
    $sql = 'UPDATE ' . jeab_inbox_link_contacts_ident($table)
        . ' SET ' . implode(', ', $sets)
        . ' WHERE ' . implode(' AND ', $wheres);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $where));

    return $stmt->rowCount() === 1;
}

/**
 * @param list<array{thread_id:string}> $rows
 * @return list<array{thread_id:string}>
 */
function jeab_inbox_link_contacts_unique_threads(array $rows): array
{
    $seen = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['thread_id'] ?? ''));
        if ($id !== '') {
            $seen[$id] = true;
        }
    }
    $ids = array_keys($seen);
    sort($ids, SORT_STRING);
    $out = [];
    foreach ($ids as $id) {
        $out[] = ['thread_id' => $id];
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_link_contacts(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_inbox_link_contacts_dry($input);
    $fail = static function (string $error) use ($dry): array {
        return [
            'ok' => false,
            'error' => $error,
            'action' => 'inbox.link_contacts',
            'dry' => $dry,
            'written' => false,
            'undo_id' => '',
        ];
    };
    // Skrivning kräver den exakta strängen. Torr körning och undo gör det inte.
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'link') {
        $denied = $fail('Kräver confirm:link.');
        $denied['status'] = 400;

        return $denied;
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return $fail('Ingen lagring.');
    }
    $scope = jeab_inbox_link_contacts_scope($input);
    if ($scope['error'] !== '') {
        return $fail($scope['error']);
    }
    $match = jeab_inbox_link_contacts_match_mode($input);
    if ($match['error'] !== '') {
        $denied = $fail($match['error']);
        $denied['status'] = 400;

        return $denied;
    }
    $pdo = $store->pdo();
    $bag = jeab_inbox_link_contacts_load($pdo);
    $index = jeab_inbox_link_contacts_index($pdo);
    $fields = $match['mode'] === 'phone_address'
        ? jeab_inbox_link_contacts_field_index($pdo)
        : ['phones' => [], 'addrs' => []];
    $ids = [];
    foreach ($bag as $id => $item) {
        if (!empty($item['linked'])) {
            continue;
        }
        if ($scope['company'] !== null && (string) ($item['company'] ?? '') !== $scope['company']) {
            continue;
        }
        $ids[] = (string) $id;
    }
    sort($ids, SORT_STRING);
    if ($scope['limit'] !== null) {
        $ids = array_slice($ids, 0, $scope['limit']);
    }
    $would = [];
    $ambiguous = [];
    $none = [];
    $counts = jeab_inbox_link_contacts_counts();
    $writes = [];
    foreach ($ids as $id) {
        $item = $bag[$id];
        $company = (string) ($item['company'] ?? '');
        $decided = jeab_inbox_link_contacts_decide(
            $item,
            $index,
            is_array($fields['phones'] ?? null) ? $fields['phones'] : [],
            is_array($fields['addrs'] ?? null) ? $fields['addrs'] : [],
            $match['mode']
        );
        $matched = is_array($decided['contact_ids'] ?? null) ? $decided['contact_ids'] : [];
        $by = (string) ($decided['match_by'] ?? '');
        if (($decided['kind'] ?? '') === 'would_link' && count($matched) === 1) {
            $would[] = ['thread_id' => $id, 'contact_id' => $matched[0], 'match_by' => $by !== '' ? $by : 'email'];
            jeab_inbox_link_contacts_bump($counts, $company, 'would_link');
            $rows = is_array($item['rows'] ?? null) ? $item['rows'] : [];
            foreach ($rows as $table => $stored) {
                if (!is_array($stored)) {
                    continue;
                }
                $plan = jeab_inbox_link_contacts_plan_row((string) $table, $id, $stored, $matched[0]);
                if ($plan !== null) {
                    $writes[] = $plan;
                }
            }
        } elseif (($decided['kind'] ?? '') === 'ambiguous') {
            $row = ['thread_id' => $id, 'matches' => count($matched), 'contact_ids' => $matched];
            if ($by !== '') {
                $row['match_by'] = $by;
            }
            $ambiguous[] = $row;
            jeab_inbox_link_contacts_bump($counts, $company, 'ambiguous');
        } else {
            $none[] = ['thread_id' => $id];
            jeab_inbox_link_contacts_bump($counts, $company, 'no_match');
        }
    }
    $sortThread = static function (array $a, array $b): int {
        return strcmp((string) ($a['thread_id'] ?? ''), (string) ($b['thread_id'] ?? ''));
    };
    usort($would, $sortThread);
    usort($ambiguous, $sortThread);
    usort($none, $sortThread);
    $out = [
        'ok' => true,
        'action' => 'inbox.link_contacts',
        'dry' => $dry,
        'written' => false,
        'undo_id' => '',
        'would_link' => $would,
        'ambiguous' => $ambiguous,
        'no_match' => $none,
        'companies' => $counts['companies'],
        'total' => $counts['total'],
    ];
    if ($dry || $writes === []) {
        return $out;
    }
    $undoId = bin2hex(random_bytes(8));
    $undoRows = [];
    foreach ($writes as $write) {
        $undoRows[] = jeab_inbox_link_contacts_undo_row($write);
    }
    try {
        jeab_inbox_link_contacts_write_undo($pdo, $undoId, $undoRows);
    } catch (Throwable) {
        return $fail('Kunde inte skriva ångerloggen.');
    }
    $written = [];
    foreach ($writes as $write) {
        if (jeab_inbox_link_contacts_apply($pdo, $write)) {
            $written[(string) $write['id']] = true;
        }
    }
    $out['dry'] = false;
    $out['written'] = $written !== [];
    $out['undo_id'] = $undoId;

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_link_contacts_undo(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_inbox_link_contacts_undo_dry($input);
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    $fail = static function (string $error, int $status = 400) use ($dry, $undoId): array {
        return [
            'ok' => false,
            'error' => $error,
            'status' => $status,
            'action' => 'inbox.link_contacts_undo',
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
        if (is_array($row) && ($row['undone_at'] ?? null) !== null && trim((string) $row['undone_at']) !== '') {
            return $fail('Ångringen är redan gjord.', 409);
        }
        $action = trim((string) ($row['action'] ?? 'link'));
        if ($action === '') {
            $action = 'link';
        }
        if ($action !== 'link') {
            return $fail('Ångringen är redan gjord.', 409);
        }
    }
    $skipped = [];
    $plan = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
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
    if (!$dry) {
        foreach ($plan as $item) {
            $id = (string) ($item['row']['thread_id'] ?? '');
            if (jeab_inbox_link_contacts_restore($pdo, $item['state'], $item['row'])) {
                $restored[] = ['thread_id' => $id];
            } else {
                $skipped[] = ['thread_id' => $id];
            }
        }
        $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $mark = $pdo->prepare('UPDATE inbox_link_contacts_undo SET undone_at = ? WHERE undo_id = ? AND undone_at IS NULL');
        $mark->execute([$now, $undoId]);
    } else {
        foreach ($plan as $item) {
            $restored[] = ['thread_id' => (string) ($item['row']['thread_id'] ?? '')];
        }
    }

    return [
        'ok' => true,
        'action' => 'inbox.link_contacts_undo',
        'dry' => $dry,
        'written' => !$dry && $restored !== [],
        'undo_id' => $undoId,
        'restored' => jeab_inbox_link_contacts_unique_threads($restored),
        'skipped' => jeab_inbox_link_contacts_unique_threads($skipped),
    ];
}
