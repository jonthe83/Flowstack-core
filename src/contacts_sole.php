<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_strict.php';
require_once __DIR__ . '/contacts_copies.php';
require_once __DIR__ . '/contacts_sole_audit.php';
require_once __DIR__ . '/snapshots.php';

/**
 * Spår D. CONTACTS_SOLE är på som kodstandard och gör kontakten till enda läskälla
 * för kundfält på påminnelser, ordrar, bokningar, kalender och inkorgens kundpanel.
 * $GLOBALS, env 1/true eller 0/false, privat/flags.json och en contacts_sole-rad
 * 0 eller 1 vinner över kodstandarden, i den ordningen. Filen 0 är rollback.
 * Saknas raden gäller kodstandarden.
 * Migreringen fyller bara tom contact_id. En befintlig länk skrivs aldrig över.
 * Avvikelser listas. Skrivningen rör bara contact_id, inte updated_at.
 * Före-bilden, länkarna, skapade kontakter och fastighetsändringar ligger i contacts_sole_undo.
 * Tabellen finns i MySQL och sqlite. dry=1 räknar planen även utan flaggan.
 * dry=0 för migreringen kräver CONTACTS_SOLE=1 och MySQL.
 * sole_fill_empty, sole_resnapshot och pnr_ambiguous_resolve kräver inte flaggan.
 * Deras dry=0 stoppas av jeab_contacts_sole_write_error. Inget personnummer lämnar svaret.
 * sole_undo_rows, link_compare, pnr_ambiguous_list och contacts.sole_audit läser bara.
 * pnr_ambiguous_list använder samma villkor som pnr_name_ambiguous.
 * Varje rad säger varför ordern är kvar, per ägarplats, utan personnummer.
 * Den skriver inget. Inget personnummer lämnar svaret, inte maskat och inte i logg.
 * pnr_fix skriver bara ett kontrollerat nummer från Fortnox till ägarplats 1 eller 2
 * när ordern är tvetydig, kontakten stämmer och kundnumret redan ligger på ordern
 * eller kontakten. Skrivningen går genom jeab_kontakt_save_input.
 * dry=1 skriver inte. Svaret är ok eller ett fel utan numret.
 * Ångerloggen har vem, tid, order, kontakt, plats och resultat, och den tomma före-bilden.
 * kontakter.owner_pnr_fill fyller tomt ägarnummer och tom fastighetsbeteckning från order och fullmakt.
 * Den skriver inte över ifyllda värden. pnr_name_ambiguous hoppas över och listas som id.
 * En ägare med giltigt personnummer är inte tvetydig när för- och efternamn stämmer
 * (mellannamn räknas inte) eller när platsen sparats i Kontakter efter ordern.
 * Svaret har bara antal och id. Ångerloggen får HMAC, inte numret.
 * sole_undo_rows och link_compare skriver inte värden, bara id och ja/nej.
 * sole_paint_resolve skriver orderns kopia till kontakten bara när ordern är nyare.
 * order_ts är orderns updated_at, annars created_at. contact_ts är senaste riktiga
 * ändringen. sole_undo och sole_resnapshot räknas inte: ångrade rader och
 * snapshot-rader bidrar inte, och sole_undo skriver tillbaka det gamla updated_at.
 * Saknas det i före-bilden ignoreras en klocka som bara är nyare än den ångrade batchen.
 * Adress jämförs del för del mot visningen. Delar ordern saknar rörs inte.
 * JSON-läget kan bara köras torrt.
 * Med flaggan på lagras personnummer som 12 siffror. Utan flaggan gäller dagens format.
 * Ångerloggen får HMAC i stället för numret. Saknas nyckel utelämnas fältet.
 * Inga personnummer lämnar den här filen.
 */

const JEAB_CONTACTS_SOLE_KEY = 'contacts_sole';

function jeab_contacts_sole_env(): ?bool
{
    return jeab_flag_env_bool('CONTACTS_SOLE');
}

function jeab_contacts_sole_stored(): string
{
    if (array_key_exists('_JEAB_CONTACTS_SOLE_STORED', $GLOBALS)) {
        return (string) $GLOBALS['_JEAB_CONTACTS_SOLE_STORED'];
    }
    if (!empty($GLOBALS['_JEAB_STORE_OPENING'])) {
        return '';
    }
    $value = '';
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $got = jeab_settings_value($pdo, $driver, JEAB_CONTACTS_SOLE_KEY);
        $got = strtolower(trim($got ?? ''));
        if ($got === '1' || $got === '0') {
            $value = $got;
        }
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_STORED'] = $value;

    return $value;
}

function jeab_contacts_sole_wants_dry(array $input): bool
{
    if (function_exists('jeab_kontakt_dry')) {
        return jeab_kontakt_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_contacts_sole_setting_put(bool $on): bool
{
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_settings_put')) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $ok = jeab_settings_put($pdo, $driver, JEAB_CONTACTS_SOLE_KEY, $on ? '1' : '0');
    unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);

    return $ok;
}

function jeab_contacts_sole(): bool
{
    $explicit = jeab_flag_explicit('CONTACTS_SOLE');
    if ($explicit !== null) {
        return $explicit;
    }
    $stored = jeab_contacts_sole_stored();
    if ($stored === '1' || $stored === '0') {
        return $stored === '1';
    }

    return jeab_flag('CONTACTS_SOLE');
}

/**
 * settings.contacts_sole. dry=1 visar current och would utan att skriva.
 * on=false skriver contacts_sole=0 så flaggan kan slås av igen.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_setting_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $dry = jeab_contacts_sole_wants_dry($input);
    $on = $input['on'] ?? null;
    $want = null;
    if ($on === true || $on === 1 || $on === '1' || $on === 'true') {
        $want = true;
    } elseif ($on === false || $on === 0 || $on === '0' || $on === 'false') {
        $want = false;
    }
    $current = jeab_contacts_sole_stored() === '1' ? '1' : '0';
    if ($want === null) {
        return [
            'ok' => false,
            'error' => 'on ska vara true eller false.',
            'status' => 400,
            'dry' => $dry,
            'written' => false,
            'current' => $current,
            'would' => null,
        ];
    }
    $would = $want ? '1' : '0';
    if ($dry) {
        return [
            'ok' => true,
            'dry' => true,
            'written' => false,
            'current' => $current,
            'would' => $would,
            'status' => 200,
        ];
    }
    if (!jeab_contacts_sole_setting_put($want)) {
        return [
            'ok' => false,
            'error' => 'Inställningen kunde inte sparas.',
            'status' => 400,
            'dry' => false,
            'written' => false,
            'current' => $current,
            'would' => $would,
        ];
    }

    return [
        'ok' => true,
        'dry' => false,
        'written' => true,
        'current' => $current,
        'would' => $would,
        'value' => jeab_contacts_sole_stored(),
        'status' => 200,
    ];
}

/**
 * @return array{linked:bool,missing:bool,id:string,name:string,email:string,phone:string,street:string,postal:string,postal_fmt:string,city:string,display:string}
 */
function jeab_contacts_sole_empty_view(): array
{
    return [
        'linked' => false,
        'missing' => false,
        'id' => '',
        'name' => '',
        'email' => '',
        'phone' => '',
        'street' => '',
        'postal' => '',
        'postal_fmt' => '',
        'city' => '',
        'display' => '',
    ];
}

function jeab_contacts_sole_postal_fmt(string $postal): string
{
    $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postal) : preg_replace('/\D+/', '', $postal) ?? '';
    if (strlen($postal) !== 5) {
        return $postal;
    }

    return substr($postal, 0, 3) . ' ' . substr($postal, 3);
}

/**
 * Flaggan av: null, och anroparen använder kopian.
 * Flaggan på: kontakten hämtas med get($id). Saknas id eller raden
 * lämnas kopian, med missing så paint kan flagga länken utan att tömma fält.
 *
 * @param array<string, mixed> $row
 * @return array{linked:bool,missing:bool,id:string,name:string,email:string,phone:string,street:string,postal:string,postal_fmt:string,city:string,display:string}|null
 */
function jeab_contacts_sole_view_for(array $row): ?array
{
    $started = microtime(true);
    $view = jeab_contacts_sole_view_for_uncached($row);
    if (function_exists('jeab_timing_add')) {
        jeab_timing_add('sole_view', microtime(true) - $started);
    }

    return $view;
}

/**
 * @param array<string, mixed> $row
 * @return array{linked:bool,missing:bool,id:string,name:string,email:string,phone:string,street:string,postal:string,postal_fmt:string,city:string,display:string}|null
 */
function jeab_contacts_sole_view_for_uncached(array $row): ?array
{
    if (!jeab_contacts_sole()) {
        return null;
    }
    $id = trim((string) ($row['contact_id'] ?? ''));
    $contact = jeab_contacts_sole_linked_contact($id);
    if (!is_array($contact)) {
        $view = jeab_contacts_sole_unlinked_view($row);
        $view['missing'] = true;

        return $view;
    }

    $view = jeab_contacts_sole_view_from_contact($contact);
    $view['name'] = jeab_contacts_sole_resolve_display_name(
        $contact,
        (string) $view['name'],
        jeab_contacts_sole_row_stored_names($row),
        array_merge([(string) $view['email']], jeab_contacts_sole_row_emails($row)),
        false,
        $id
    );

    return $view;
}

/**
 * En rad. get($id) läser inte hela registret.
 *
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_linked_contact(string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) && array_key_exists($id, $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        $cached = $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id];

        return is_array($cached) ? $cached : null;
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] ?? 0) + 1;
    $contact = null;
    if (function_exists('jeab_kontakt_get')) {
        $got = jeab_kontakt_get($id);
        $contact = is_array($got) ? $got : null;
    } elseif (function_exists('jeab_reminder_linked_contact')) {
        $got = jeab_reminder_linked_contact(['contact_id' => $id]);
        $contact = is_array($got) ? $got : null;
    }
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [];
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id] = $contact;

    return $contact;
}

/**
 * Glöm en cachad kontakt efter en skrivning, så paint i samma request
 * läser det nya värdet. null eller tom sträng tömmer hela cachen,
 * även ordernamn per bokning.
 */
function jeab_contacts_sole_cache_forget(?string $id = null): void
{
    unset(
        $GLOBALS['_JEAB_KONTAKTER_MEM'],
        $GLOBALS['_JEAB_KONTAKTER_MEM_KEY'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'],
        $GLOBALS['_JEAB_SHOWN_CUSTOMER'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'],
        $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_INDEX']
    );
    jeab_contacts_sole_booking_order_cache_forget($id);
    if ($id === null || trim($id) === '') {
        unset(
            $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'],
            $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'],
            $GLOBALS['_JEAB_BOOKING_SIBLING_MAP']
        );

        return;
    }
    $id = trim($id);
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id]);
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'])) {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id]);
    }
}

/**
 * Glöm cachade orderrader för en bokning. null eller tom sträng tömmer
 * hela requesten. Samma nyckel som de andra sole-cacharna: contact_id.
 */
function jeab_contacts_sole_booking_order_cache_forget(?string $id = null): void
{
    if ($id === null || trim($id) === '') {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER']);

        return;
    }
    $id = trim($id);
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'])) {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'][$id]);
    }
}

/**
 * Kontakter för en sida, en fråga mot contacts (id, row_json, street_key).
 * Saknade id lagras som null så listan inte gör om get per rad.
 *
 * @param list<string> $ids
 * @return array<string, array<string, mixed>>
 */
function jeab_contacts_sole_load_ids(array $ids): array
{
    $want = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $want[$id] = true;
        }
    }
    if ($want === []) {
        return [];
    }
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [];
    }
    $missing = [];
    foreach (array_keys($want) as $id) {
        if (!array_key_exists($id, $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
            $missing[] = $id;
        }
    }
    if ($missing !== []) {
        $loaded = jeab_contacts_sole_fetch_ids($missing);
        foreach ($missing as $id) {
            $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id] = $loaded[$id] ?? null;
        }
    }
    $out = [];
    foreach (array_keys($want) as $id) {
        $row = $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id] ?? null;
        if (is_array($row)) {
            $out[$id] = $row;
        }
    }

    return $out;
}

/**
 * @param list<string> $ids
 * @return array<string, array<string, mixed>>
 */
function jeab_contacts_sole_fetch_ids(array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $clean[$id] = true;
        }
    }
    $ids = array_keys($clean);
    if ($ids === []) {
        return [];
    }
    $out = [];
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    $sqlOn = $pdo instanceof PDO && function_exists('jeab_kontakter_use_sql') && jeab_kontakter_use_sql();
    if ($sqlOn) {
        foreach (array_chunk($ids, 500) as $chunk) {
            try {
                foreach (jeab_contacts_sole_select_ids($pdo, $chunk) as $id => $row) {
                    $out[$id] = $row;
                }
            } catch (Throwable) {
                // Tabellen kan saknas när kontakter fortfarande ligger i JSON.
            }
        }
        if (function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source()) {
            return $out;
        }
    }
    $gap = [];
    foreach ($ids as $id) {
        if (!isset($out[$id])) {
            $gap[] = $id;
        }
    }
    if ($gap !== [] && function_exists('jeab_kontakter_load')) {
        $all = jeab_kontakter_load();
        foreach ($gap as $id) {
            if (isset($all[$id]) && is_array($all[$id])) {
                $out[$id] = $all[$id];
            }
        }
    }

    return $out;
}

/**
 * En SELECT för högst 500 id. street_key och ägarkolumnerna följer med
 * så sök kan träffa dem även när row_json saknar nyckeln.
 *
 * @param list<string> $ids
 * @return array<string, array<string, mixed>>
 */
function jeab_contacts_sole_select_ids(PDO $pdo, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] ?? 0) + 1;
    $wide = true;
    try {
        $stmt = $pdo->prepare('SELECT id, row_json, street_key, owner_email, owner_phone, owner_name_fold FROM contacts WHERE id IN (' . $marks . ')');
        $stmt->execute($ids);
    } catch (Throwable) {
        $wide = false;
        $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] ?? 0) + 1;
        $stmt = $pdo->prepare('SELECT id, row_json FROM contacts WHERE id IN (' . $marks . ')');
        $stmt->execute($ids);
    }
    $out = [];
    foreach ($stmt->fetchAll() as $dbRow) {
        if (!is_array($dbRow)) {
            continue;
        }
        $data = json_decode((string) ($dbRow['row_json'] ?? ''), true);
        if (!is_array($data)) {
            continue;
        }
        $id = trim((string) ($dbRow['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $data['id'] = $id;
        if ($wide) {
            foreach (['street_key', 'owner_email', 'owner_phone', 'owner_name_fold'] as $column) {
                $value = trim((string) ($dbRow[$column] ?? ''));
                if ($value !== '' && trim((string) ($data[$column] ?? '')) === '') {
                    $data[$column] = $value;
                }
                if ($column === 'street_key' && $value !== '') {
                    $data['street_key_column'] = $value;
                }
            }
        }
        $out[$id] = $data;
    }

    return $out;
}

/**
 * Kopian på raden. Används när länken saknas, så visningen inte blir tom.
 *
 * @param array<string, mixed> $row
 * @return array{linked:bool,missing:bool,id:string,name:string,email:string,phone:string,street:string,postal:string,postal_fmt:string,city:string,display:string}
 */
function jeab_contacts_sole_unlinked_view(array $row): array
{
    $view = jeab_contacts_sole_empty_view();
    $view['missing'] = true;
    $view['id'] = trim((string) ($row['contact_id'] ?? ''));
    $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
    $name = '';
    foreach (['huvudsokande', 'customer_name', 'name'] as $key) {
        $name = trim((string) ($row[$key] ?? ''));
        if ($name !== '') {
            break;
        }
    }
    if ($name === '') {
        $name = trim((string) ($applicant['name'] ?? ''));
    }
    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '') {
        $email = trim((string) ($applicant['email'] ?? ''));
    }
    $phone = trim((string) ($row['phone'] ?? ''));
    if ($phone === '') {
        $phone = trim((string) ($applicant['phone'] ?? ''));
    }
    $street = trim((string) ($row['gata'] ?? $row['street'] ?? ''));
    $city = trim((string) ($row['ort'] ?? $row['city'] ?? ''));
    $postal = trim((string) ($row['postnummer'] ?? $row['postal'] ?? $row['postnr'] ?? ''));
    $address = trim((string) ($row['address'] ?? $row['huvudsokande_address'] ?? ''));
    if (($street === '' || $city === '' || $postal === '') && $address !== '' && function_exists('jeab_address_parts')) {
        $parts = jeab_address_parts($address);
        if ($street === '') {
            $street = trim((string) ($parts['street'] ?? ''));
            if ($street === '') {
                $street = $address;
            }
        }
        if ($city === '') {
            $city = trim((string) ($parts['city'] ?? ''));
        }
        if ($postal === '') {
            $postal = trim((string) ($parts['postal'] ?? ''));
        }
    } elseif ($street === '' && $address !== '') {
        $street = $address;
    }
    if (function_exists('jeab_address_town')) {
        $city = jeab_address_town($city);
    }
    if (function_exists('jeab_kontakt_postal')) {
        $norm = jeab_kontakt_postal($postal);
        if ($norm !== '') {
            $postal = $norm;
        }
    }
    $postalFmt = jeab_contacts_sole_postal_fmt($postal);
    $tail = trim($postalFmt . ($city !== '' ? ' ' . $city : ''));
    if ($street !== '' && $tail !== '' && !str_contains($street, $tail)) {
        $display = $street . ', ' . $tail;
    } elseif ($street !== '') {
        $display = $street;
    } else {
        $display = $tail !== '' ? $tail : $address;
    }
    $view['name'] = $name;
    $view['email'] = $email;
    $view['phone'] = $phone;
    $view['street'] = $street;
    $view['postal'] = $postal;
    $view['postal_fmt'] = $postalFmt;
    $view['city'] = $city;
    $view['display'] = $display;

    return $view;
}

/**
 * @param array<string, mixed> $contact
 * @return array{linked:bool,missing:bool,id:string,name:string,email:string,phone:string,street:string,postal:string,postal_fmt:string,city:string,display:string}
 */
function jeab_contacts_sole_view_from_contact(array $contact): array
{
    $ownerEmails = jeab_contacts_sole_contact_emails($contact);
    $name = jeab_contacts_sole_best_owner_name($contact, $ownerEmails);
    $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '';
    $street = trim((string) ($contact['street'] ?? ''));
    $postal = function_exists('jeab_kontakt_postal')
        ? jeab_kontakt_postal((string) ($contact['postal_code'] ?? ''))
        : trim((string) ($contact['postal_code'] ?? ''));
    $city = function_exists('jeab_address_town')
        ? jeab_address_town((string) ($contact['city'] ?? ''))
        : trim((string) ($contact['city'] ?? ''));
    if ($city === '' && function_exists('jeab_kontakt_city_name')) {
        $city = jeab_kontakt_city_name((string) ($contact['city'] ?? ''));
    }
    if ($street === '') {
        $street = trim((string) ($contact['gata'] ?? ''));
    }
    if ($street === '') {
        $rawAddress = trim((string) ($contact['address'] ?? $contact['adress'] ?? ''));
        if ($rawAddress !== '') {
            $parts = function_exists('jeab_address_parts')
                ? jeab_address_parts($rawAddress)
                : ['street' => $rawAddress, 'postal' => '', 'city' => ''];
            $street = trim((string) ($parts['street'] ?? ''));
            if ($street === '') {
                $street = $rawAddress;
            }
            if ($postal === '') {
                $postal = function_exists('jeab_kontakt_postal')
                    ? jeab_kontakt_postal((string) ($parts['postal'] ?? ''))
                    : trim((string) ($parts['postal'] ?? ''));
            }
            if ($city === '') {
                $parsedCity = trim((string) ($parts['city'] ?? ''));
                $city = function_exists('jeab_address_town') ? jeab_address_town($parsedCity) : $parsedCity;
            }
        }
    }
    $postalFmt = jeab_contacts_sole_postal_fmt($postal);
    $tail = trim($postalFmt . ($city !== '' ? ' ' . $city : ''));
    if ($street !== '' && $tail !== '') {
        $display = $street . ', ' . $tail;
    } elseif ($street !== '') {
        $display = $street;
    } else {
        $display = $tail;
    }

    return [
        'linked' => true,
        'missing' => false,
        'id' => trim((string) ($contact['id'] ?? '')),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'street' => $street,
        'postal' => $postal,
        'postal_fmt' => $postalFmt,
        'city' => $city,
        'display' => $display,
    ];
}

/**
 * «Kund», «Namn» och annat som inte är en person.
 */
function jeab_contacts_sole_name_is_placeholder(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $fold = preg_replace('/\s+/u', ' ', $fold) ?? $fold;
    if (in_array($fold, ['kund', 'kundnamn', 'namn', 'name', 'customer', 'okänd', 'okand', 'unknown'], true)) {
        return true;
    }
    if (function_exists('jeab_reminder_placeholder_name') && jeab_reminder_placeholder_name($name)) {
        return true;
    }
    if (function_exists('jeab_kontakt_name_is_garbage') && jeab_kontakt_name_is_garbage($name)) {
        return true;
    }

    return false;
}

/**
 * Namnet är e-postens lokaldel, till exempel Estrellaanders eller Lerehn.
 * Ett namn med mellanslag räknas inte.
 *
 * @param list<string> $emails
 */
function jeab_contacts_sole_name_is_local_part(string $name, array $emails): bool
{
    $name = trim($name);
    if ($name === '' || preg_match('/\s/u', $name) === 1) {
        return false;
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $compact = preg_replace('/[._+\-]+/u', '', $fold) ?? $fold;
    foreach (jeab_contacts_sole_clean_emails($emails) as $email) {
        $at = strrpos($email, '@');
        if ($at === false) {
            continue;
        }
        $local = function_exists('mb_strtolower') ? mb_strtolower(substr($email, 0, $at), 'UTF-8') : strtolower(substr($email, 0, $at));
        if ($local === '') {
            continue;
        }
        if ($fold === $local) {
            return true;
        }
        $localCompact = preg_replace('/[._+\-]+/u', '', $local) ?? $local;
        if ($compact !== '' && $compact === $localCompact) {
            return true;
        }
    }

    return false;
}

/**
 * Ett namn som får visas: inte tomt, inte platshållare, inte en e-postadress
 * och inte lokaldelen av en länkad adress.
 *
 * @param list<string> $emails
 */
function jeab_contacts_sole_name_is_real(string $name, array $emails): bool
{
    $name = trim($name);
    if ($name === '' || str_contains($name, '@')) {
        return false;
    }
    if (function_exists('jeab_kontakt_name_is_email') && jeab_kontakt_name_is_email($name)) {
        return false;
    }
    if (jeab_contacts_sole_name_is_placeholder($name) || jeab_contacts_sole_name_is_local_part($name, $emails)) {
        return false;
    }

    return true;
}

function jeab_contacts_sole_name_is_full(string $name): bool
{
    return trim($name) !== '' && preg_match('/\s/u', trim($name)) === 1;
}

/**
 * @param list<string> $emails
 * @return list<string>
 */
function jeab_contacts_sole_clean_emails(array $emails): array
{
    $out = [];
    foreach ($emails as $email) {
        if (!is_scalar($email)) {
            continue;
        }
        $email = trim((string) $email);
        if ($email === '' || !str_contains($email, '@')) {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
        $out[$key] = $email;
    }

    return array_values($out);
}

/**
 * @param array<string, mixed> $contact
 * @return list<string>
 */
function jeab_contacts_sole_contact_emails(array $contact): array
{
    $emails = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $emails[] = trim((string) ($owner['email'] ?? ''));
    }
    $emails[] = trim((string) ($contact['email'] ?? ''));
    if (function_exists('jeab_kontakt_row_email')) {
        $emails[] = jeab_kontakt_row_email($contact);
    }
    if (function_exists('jeab_kontakt_row_emails')) {
        foreach (jeab_kontakt_row_emails($contact) as $email) {
            $emails[] = (string) $email;
        }
    }

    return jeab_contacts_sole_clean_emails($emails);
}

/**
 * Kontaktpersonens namn först, sedan övriga ägare. Ett riktigt fullständigt
 * namn vinner över «Kund» och en e-postlokaldel.
 *
 * @param array<string, mixed> $contact
 * @param list<string> $emails
 */
function jeab_contacts_sole_best_owner_name(array $contact, array $emails): string
{
    $person = '';
    $others = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (!empty($owner['contact']) && $person === '') {
            $person = $name;
            continue;
        }
        $others[] = $name;
    }
    $full = static function (string $name) use ($emails): bool {
        return jeab_contacts_sole_name_is_real($name, $emails) && jeab_contacts_sole_name_is_full($name);
    };
    $real = static function (string $name) use ($emails): bool {
        return jeab_contacts_sole_name_is_real($name, $emails);
    };
    if ($person !== '' && $full($person)) {
        return $person;
    }
    foreach ($others as $name) {
        if ($full($name)) {
            return $name;
        }
    }
    if ($person !== '' && $real($person)) {
        return $person;
    }
    foreach ($others as $name) {
        if ($real($name)) {
            return $name;
        }
    }
    if ($person !== '') {
        return $person;
    }

    return $others[0] ?? (function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '');
}

/**
 * @param list<string> $names
 * @param list<string> $emails
 */
function jeab_contacts_sole_first_real_name(array $names, array $emails, bool $fullOnly): string
{
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '' || !jeab_contacts_sole_name_is_real($name, $emails)) {
            continue;
        }
        if ($fullOnly && !jeab_contacts_sole_name_is_full($name)) {
            continue;
        }

        return $name;
    }

    return '';
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_contacts_sole_row_stored_names(array $row): array
{
    $names = [];
    foreach (['huvudsokande', 'customer_name', 'name'] as $key) {
        $names[] = trim((string) ($row[$key] ?? ''));
    }
    $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
    $names[] = trim((string) ($applicant['name'] ?? ''));

    return $names;
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_contacts_sole_row_emails(array $row): array
{
    $emails = [trim((string) ($row['email'] ?? ''))];
    $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
    $emails[] = trim((string) ($applicant['email'] ?? ''));

    return $emails;
}

/**
 * Förberedelse till visningsnamnet. Samma e-post och kandidater som
 * jeab_contacts_sole_resolve_display_name använde innan uppslaget.
 *
 * @param array<string, mixed>|null $contact
 * @param list<string> $stored
 * @param list<string> $emails
 * @return array{0:string,1:list<string>,2:string,3:list<string>}
 */
function jeab_contacts_sole_display_parts(?array $contact, string $contactName, array $stored, array $emails, string $contactId): array
{
    $emails = jeab_contacts_sole_clean_emails($emails);
    if (is_array($contact)) {
        foreach (jeab_contacts_sole_contact_emails($contact) as $email) {
            $emails[] = $email;
        }
        $emails = jeab_contacts_sole_clean_emails($emails);
        if ($contactId === '') {
            $contactId = trim((string) ($contact['id'] ?? ''));
        }
        $best = jeab_contacts_sole_best_owner_name($contact, $emails);
        if ($best !== '') {
            $contactName = $best;
        }
    }
    $candidates = [];
    foreach ($stored as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $candidates[] = $name;
        }
    }

    return [trim($contactName), $emails, trim($contactId), $candidates];
}

/**
 * Sant när ett riktigt namn fortfarande kan ligga på en order eller en
 * annan påminnelse. Ett redan fullständigt namn behöver inget uppslag:
 * namn med mellanslag slås inte ut av fler e-postadresser.
 *
 * @param array<string, mixed>|null $contact
 * @param list<string> $stored
 * @param list<string> $emails
 */
function jeab_contacts_sole_needs_linked_names(?array $contact, string $contactName, array $stored, array $emails, bool $allowStoredWhenEmpty, string $contactId): bool
{
    [$contactName, $emails, $contactId, $candidates] = jeab_contacts_sole_display_parts($contact, $contactName, $stored, $emails, $contactId);
    if ($contactId === '') {
        return false;
    }
    if ($contactName !== '' && jeab_contacts_sole_name_is_real($contactName, $emails)) {
        return false;
    }
    if ($contactName === '' && !$allowStoredWhenEmpty) {
        return false;
    }
    if (jeab_contacts_sole_first_real_name($candidates, $emails, true) !== '') {
        return false;
    }

    return true;
}

/**
 * Synliga kort. En fråga för de kontakter som fortfarande saknar namn.
 *
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_sole_prefetch_reminder_names(array $rows): void
{
    if (!jeab_contacts_sole()) {
        return;
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['contact_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $contact = jeab_contacts_sole_linked_contact($id);
        if (!is_array($contact)) {
            continue;
        }
        $view = jeab_contacts_sole_view_from_contact($contact);
        if (!jeab_contacts_sole_needs_linked_names(
            $contact,
            (string) $view['name'],
            jeab_contacts_sole_row_stored_names($row),
            array_merge([(string) $view['email']], jeab_contacts_sole_row_emails($row)),
            false,
            $id
        )) {
            continue;
        }
        $ids[$id] = $id;
    }
    if ($ids === []) {
        return;
    }
    jeab_contacts_sole_prefetch_linked_names(array_values($ids));
}

/**
 * @param list<string> $contactIds
 */
function jeab_contacts_sole_prefetch_linked_names(array $contactIds): void
{
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'] = [];
    }
    $need = [];
    foreach ($contactIds as $id) {
        $id = trim((string) $id);
        if (function_exists('mb_substr')) {
            $id = mb_substr($id, 0, 64);
        } else {
            $id = substr($id, 0, 64);
        }
        if ($id === '' || isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id])) {
            continue;
        }
        $need[$id] = ['names' => [], 'emails' => []];
    }
    if ($need === []) {
        return;
    }
    foreach ($need as $id => $pack) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id] = $pack;
    }
    $ids = array_keys($need);
    foreach (array_chunk($ids, 80) as $chunk) {
        jeab_contacts_sole_linked_names_purchases($chunk);
        jeab_contacts_sole_linked_names_reminders($chunk);
    }
}

/**
 * @param list<string> $contactIds
 */
function jeab_contacts_sole_linked_names_purchases(array $contactIds): void
{
    if ($contactIds === [] || !function_exists('jeab_purchase_sql_live')) {
        return;
    }
    try {
        $pdo = jeab_purchase_sql_live();
        if (!$pdo instanceof PDO) {
            return;
        }
        $mysql = function_exists('jeab_sql_purchases_is_mysql')
            ? jeab_sql_purchases_is_mysql($pdo)
            : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        // MySQL: JSON_UNQUOTE av JSON null är texten "null", så Påminnelser
        // kan måla det ordet. IF/CASE hoppar över trasig JSON (fel 3141) så
        // en rad inte fäller hela bunten. WHERE JSON_VALID tar bort raden.
        $text = static function (string $path) use ($mysql): string {
            if ($mysql) {
                $extract = "JSON_UNQUOTE(JSON_EXTRACT(payload, '" . $path . "'))";

                return "IF(JSON_VALID(payload), NULLIF(" . $extract . ", 'null'), NULL)";
            }
            $extract = "json_extract(payload, '" . $path . "')";

            return "CASE WHEN JSON_VALID(payload) THEN NULLIF(" . $extract . ", 'null') END";
        };
        $marks = implode(', ', array_fill(0, count($contactIds), '?'));
        $sql = 'SELECT reminder_id, contact_id, '
            . $text('$.huvudsokande') . ' AS huvudsokande, '
            . $text('$.customer_name') . ' AS customer_name, '
            . $text('$.name') . ' AS name, '
            . $text('$.applicant.name') . ' AS applicant_name, '
            . $text('$.email') . ' AS email, '
            . $text('$.applicant.email') . ' AS applicant_email '
            . 'FROM purchases WHERE JSON_VALID(payload) AND contact_id IN (' . $marks . ') ORDER BY reminder_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($contactIds));
        $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] ?? 0) + 1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['contact_id'] ?? ''));
            if ($id === '' || !isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id]) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id])) {
                continue;
            }
            jeab_contacts_sole_linked_take_row($id, $row, ['huvudsokande', 'customer_name', 'name', 'applicant_name'], ['email', 'applicant_email']);
        }
    } catch (Throwable) {
        // Orderlagret kan saknas i ett test. Visningen fortsätter utan det.
    }
}

/**
 * @param list<string> $contactIds
 */
function jeab_contacts_sole_linked_names_reminders(array $contactIds): void
{
    if ($contactIds === []) {
        return;
    }
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
        if (!$store instanceof JeabPdoStore) {
            return;
        }
        if (!empty($GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAMES_NO_WINDOW'])) {
            throw new RuntimeException('window');
        }
        $marks = implode(', ', array_fill(0, count($contactIds), '?'));
        $copy = jeab_copy_select($store->pdo(), 'reminders', ['customer_name', 'email']);
        $sql = 'SELECT contact_id, customer_name, email FROM ('
            . 'SELECT contact_id, ' . $copy . ', ROW_NUMBER() OVER (PARTITION BY contact_id ORDER BY id) AS jeab_n '
            . 'FROM reminders WHERE contact_id IN (' . $marks . ') AND id > 0'
            . ') jeab_named WHERE jeab_n <= 40 ORDER BY contact_id, jeab_n';
        $stmt = $store->pdo()->prepare($sql);
        $stmt->execute(array_values($contactIds));
        $GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] ?? 0) + 1;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['contact_id'] ?? ''));
            if ($id === '' || !isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id])) {
                continue;
            }
            jeab_contacts_sole_linked_take_row($id, $row, ['customer_name'], ['email']);
        }
    } catch (Throwable) {
        jeab_contacts_sole_linked_names_reminders_batch($contactIds);
    }
}

/**
 * En fråga för alla kontakter när fönsterfunktionen saknas (MySQL 5.7).
 * Högst 40 rader per kontakt, samma gräns som fönstret. Per-kontakt-loopen
 * används bara om den här frågan också faller.
 *
 * @param list<string> $contactIds
 */
function jeab_contacts_sole_linked_names_reminders_batch(array $contactIds): void
{
    if ($contactIds === []) {
        return;
    }
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
        if (!$store instanceof JeabPdoStore) {
            return;
        }
        $marks = implode(', ', array_fill(0, count($contactIds), '?'));
        $sql = 'SELECT contact_id, ' . jeab_copy_select($store->pdo(), 'reminders', ['customer_name', 'email']) . ' FROM reminders WHERE contact_id IN ('
            . $marks . ') AND id > 0 ORDER BY contact_id, id';
        $stmt = $store->pdo()->prepare($sql);
        $stmt->execute(array_values($contactIds));
        $GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] ?? 0) + 1;
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['contact_id'] ?? ''));
            if ($id === '' || !isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id])) {
                continue;
            }
            $seen[$id] = ($seen[$id] ?? 0) + 1;
            if ($seen[$id] > 40) {
                continue;
            }
            jeab_contacts_sole_linked_take_row($id, $row, ['customer_name'], ['email']);
        }
    } catch (Throwable) {
        jeab_contacts_sole_linked_names_reminders_each($contactIds);
    }
}

/**
 * Samma gräns som den tidigare per-kontakt-frågan när fönsterfunktionen saknas.
 *
 * @param list<string> $contactIds
 */
function jeab_contacts_sole_linked_names_reminders_each(array $contactIds): void
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
        if (!$store instanceof JeabPdoStore) {
            return;
        }
        $stmt = $store->pdo()->prepare('SELECT ' . jeab_copy_select($store->pdo(), 'reminders', ['customer_name', 'email']) . ' FROM reminders WHERE contact_id = ? AND id > 0 LIMIT 40');
        foreach ($contactIds as $id) {
            if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id])) {
                continue;
            }
            $stmt->execute([$id]);
            $GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] ?? 0) + 1;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                jeab_contacts_sole_linked_take_row($id, $row, ['customer_name'], ['email']);
            }
        }
    } catch (Throwable) {
        // Saknad tabell lämnar de namn som redan samlats.
    }
}

/**
 * @param array<string, mixed> $row
 * @param list<string> $nameKeys
 * @param list<string> $emailKeys
 */
function jeab_contacts_sole_linked_take_row(string $contactId, array $row, array $nameKeys, array $emailKeys): void
{
    $pack = $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$contactId] ?? null;
    if (!is_array($pack)) {
        return;
    }
    $names = is_array($pack['names'] ?? null) ? $pack['names'] : [];
    $emails = is_array($pack['emails'] ?? null) ? $pack['emails'] : [];
    foreach ($nameKeys as $key) {
        $name = jeab_contacts_sole_linked_text($row[$key] ?? null);
        if ($name !== '') {
            $names[] = $name;
        }
    }
    foreach ($emailKeys as $key) {
        $email = jeab_contacts_sole_linked_text($row[$key] ?? null);
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$contactId] = [
        'names' => $names,
        'emails' => jeab_contacts_sole_clean_emails($emails),
    ];
}

/** JSON-objekt och listor är inte namn. En sträng lämnas trimmad. */
function jeab_contacts_sole_linked_text(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '{') || str_starts_with($value, '[')) {
        return '';
    }

    return $value;
}

/**
 * Namn och e-post på ordrar och påminnelser som pekar på samma kontakt.
 * Anropas bara när kontakten och raden själv saknar ett fullständigt namn.
 * Läser namnfält, inte orderns payload.
 * Med bookingId slås bara den bokningens order och påminnelse upp, och
 * svaret får path och source_id. Orderraderna cachas per contact_id i
 * requesten. Påminnelsen läses varje gång.
 *
 * @param list<string> $emails
 * @return array{names:list<string>,emails:list<string>,name?:string,path?:string,source_id?:int|null}
 */
function jeab_contacts_sole_linked_stored_names(string $contactId, int $bookingId = 0, array $emails = []): array
{
    if ($bookingId > 0) {
        return jeab_contacts_sole_booking_linked_names($bookingId, $emails, $contactId);
    }
    $contactId = trim($contactId);
    $empty = ['names' => [], 'emails' => []];
    if ($contactId === '') {
        return $empty;
    }
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'] = [];
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$contactId]) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$contactId])) {
        return $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$contactId];
    }
    jeab_contacts_sole_prefetch_linked_names([$contactId]);
    $pack = $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$contactId] ?? $empty;

    return is_array($pack) ? $pack : $empty;
}

/**
 * Visningsnamn. Kontaktpersonens eller ägarens riktiga namn, sedan sparat
 * namn på order eller påminnelse. «Kund» och en e-postlokaldel visas inte
 * när ett riktigt namn finns. Saknas namn blir det e-posten, eller tomt.
 * Ett riktigt kontaktnamn vinner alltid före radens kopia och före länkade
 * namn. Tomt kontaktnamn när reserven är av lämnar visningen tom, så en
 * bokningskopia kan stå kvar. Bokningens reserv från just den bokningens
 * order eller påminnelse väljs i jeab_contacts_sole_booking_name_choice.
 *
 * @param array<string, mixed>|null $contact
 * @param list<string> $stored
 * @param list<string> $emails
 */
function jeab_contacts_sole_resolve_display_name(?array $contact, string $contactName, array $stored, array $emails, bool $allowStoredWhenEmpty, string $contactId = ''): string
{
    [$contactName, $emails, $contactId, $candidates] = jeab_contacts_sole_display_parts($contact, $contactName, $stored, $emails, $contactId);
    if ($contactName !== '' && jeab_contacts_sole_name_is_real($contactName, $emails)) {
        return $contactName;
    }
    if ($contactName === '' && !$allowStoredWhenEmpty) {
        return '';
    }
    $full = jeab_contacts_sole_first_real_name($candidates, $emails, true);
    if ($full !== '') {
        return $full;
    }
    if ($contactId !== '') {
        $linked = jeab_contacts_sole_linked_stored_names($contactId);
        foreach ($linked['names'] as $name) {
            $candidates[] = $name;
        }
        foreach ($linked['emails'] as $email) {
            $emails[] = $email;
        }
        $emails = jeab_contacts_sole_clean_emails($emails);
    }
    $full = jeab_contacts_sole_first_real_name($candidates, $emails, true);
    if ($full !== '') {
        return $full;
    }
    $single = jeab_contacts_sole_first_real_name($candidates, $emails, false);
    if ($single !== '') {
        return $single;
    }

    return jeab_contacts_sole_public_email($emails);
}

/**
 * @param list<string> $emails
 */
function jeab_contacts_sole_public_email(array $emails): string
{
    foreach (jeab_contacts_sole_clean_emails($emails) as $email) {
        return $email;
    }

    return '';
}

/**
 * Sista steget i en ruta. Ett riktigt namn lämnas. Annars e-posten, inte «Kund».
 *
 * @param list<string> $emails
 */
function jeab_contacts_sole_finish_display_name(string $name, array $emails): string
{
    $name = trim($name);
    $emails = jeab_contacts_sole_clean_emails($emails);
    if ($name !== '' && jeab_contacts_sole_name_is_real($name, $emails)) {
        return $name;
    }
    if ($name !== '' && str_contains($name, '@')) {
        return $name;
    }
    $email = jeab_contacts_sole_public_email($emails);

    return $email !== '' ? $email : '';
}

/**
 * Kundfält som ordern inte ska lagra när kontakten är källan.
 * Personnummer och Fortnox hör inte hit.
 *
 * @return list<string>
 */
function jeab_contacts_sole_order_duplicate_keys(): array
{
    return [
        'name', 'huvudsokande', 'customer_name',
        'email', 'phone',
        'address', 'huvudsokande_address',
        'gata', 'ort', 'postnummer', 'postnr',
        'street', 'city', 'postal',
    ];
}

/** Ett loggfält per grupp så samma lucka inte blir flera rader. */
function jeab_contacts_sole_order_only_field(string $key): string
{
    $key = strtolower(trim($key));

    return match ($key) {
        'name', 'huvudsokande', 'customer_name', 'applicant.name' => 'name',
        'email', 'applicant.email' => 'email',
        'phone', 'applicant.phone' => 'phone',
        'address', 'huvudsokande_address', 'gata', 'ort', 'postnummer', 'postnr', 'street', 'city', 'postal' => 'address',
        default => $key,
    };
}

/**
 * Kontakt finns och flaggan är på: ordern ska bara peka med contact_id.
 */
function jeab_contacts_sole_order_should_strip(array $row): bool
{
    if (!jeab_contacts_sole()) {
        return false;
    }
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '') {
        return false;
    }

    return is_array(jeab_contacts_sole_linked_contact($id));
}

/**
 * Tar bort namn, e-post, telefon och adress från orderraden.
 * contact_id, personnummer och Fortnox-fält lämnas.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_order_strip_customer(array $row): array
{
    if (!jeab_contacts_sole_order_should_strip($row)) {
        return $row;
    }
    foreach (jeab_contacts_sole_order_duplicate_keys() as $key) {
        $fold = strtolower($key);
        if (str_contains($fold, 'personnummer') || str_contains($fold, 'pnr') || str_starts_with($fold, 'fortnox')) {
            continue;
        }
        unset($row[$key]);
    }
    if (isset($row['applicant']) && is_array($row['applicant'])) {
        unset($row['applicant']['name'], $row['applicant']['email'], $row['applicant']['phone']);
    }

    return $row;
}

/**
 * Samma lucka loggas högst en gång per dygn, även när felloggens egen spärr
 * inte hittar raden bland de senaste felen. Skriver ingen kunddata.
 */
function jeab_contacts_sole_gap_log_allow(string $seenKey): bool
{
    $seenKey = trim($seenKey);
    if ($seenKey === '') {
        return false;
    }
    $path = rtrim(sys_get_temp_dir(), '/') . '/jeab-sole-gap-log.json';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return true;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return true;
        }
        $raw = stream_get_contents($fh);
        $map = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($map)) {
            $map = [];
        }
        $now = time();
        $seenAt = (int) ($map[$seenKey] ?? 0);
        if ($seenAt > 0 && ($now - $seenAt) < 86400) {
            return false;
        }
        $map[$seenKey] = $now;
        foreach ($map as $key => $at) {
            if (!is_string($key) || ($now - (int) $at) > 86400 * 2) {
                unset($map[$key]);
            }
        }
        $encoded = json_encode($map);
        if (!is_string($encoded)) {
            return true;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $encoded);

        return true;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Värdet fanns bara på ordern. En rad per order och fält, utan värdet och utan personnummer.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_sole_log_order_only(array $row, string $key, string $kind = 'order'): void
{
    if (!jeab_contacts_sole()) {
        return;
    }
    $field = jeab_contacts_sole_order_only_field($key);
    $fold = strtolower($field);
    if ($field === '' || str_contains($fold, 'personnummer') || $fold === 'pnr' || str_contains($fold, 'pnr')) {
        return;
    }
    $kind = in_array($kind, ['order', 'reminder', 'booking'], true) ? $kind : 'order';
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    $seenKey = $kind . '|' . $id . '|' . $field;
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG'] = [];
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG'][$seenKey])) {
        return;
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG'][$seenKey] = true;
    if (!jeab_contacts_sole_gap_log_allow($seenKey)) {
        return;
    }
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $fingerprint = hash('sha256', 'contacts_sole_order_only|' . $seenKey);
    $label = match ($kind) {
        'reminder' => 'Påminnelse',
        'booking' => 'Bokning',
        default => 'Order',
    };
    $where = $kind === 'order' ? 'ordern' : 'raden';
    jeab_log_app_error(
        'contacts_sole',
        $label . ' ' . $id . ' fält ' . $field . ' finns bara på ' . $where . ' och saknas på kontakten. Värdet skulle ha migrerats.',
        [
            'fingerprint' => $fingerprint,
            'dedupe_seconds' => 86400 * 365,
            'no_webhook' => true,
            'skip_request' => true,
        ]
    );
}

/**
 * Icke-tomt kontaktvärde skrivs. För en order med flaggan på vinner även tomt:
 * orderns kopia visas inte, och en lucka som bara finns på ordern loggas en gång.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $logRow
 */
function jeab_contacts_sole_paint_keep(array &$row, string $key, string $value, bool $blankWins = false, ?array $logRow = null, string $kind = 'order'): void
{
    if (trim($value) !== '') {
        $row[$key] = $value;

        return;
    }
    if (!$blankWins || !jeab_contacts_sole()) {
        return;
    }
    $prev = trim((string) ($row[$key] ?? ''));
    $row[$key] = '';
    if ($prev === '') {
        return;
    }
    $context = is_array($logRow) ? $logRow : $row;
    jeab_contacts_sole_log_order_only($context, $key, $kind);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_paint_order(array $row): array
{
    $view = jeab_contacts_sole_view_for($row);
    // Flaggan av: orderns egen kopia gäller. Saknad kontakt: kopian gäller och flaggas.
    if ($view === null) {
        return $row;
    }
    if (empty($view['linked'])) {
        $row['contact_link_missing'] = true;

        return $row;
    }
    unset($row['contact_link_missing']);
    jeab_contacts_sole_paint_keep($row, 'name', $view['name'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'huvudsokande', $view['name'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'customer_name', $view['name'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'email', $view['email'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'phone', $view['phone'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'address', $view['display'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'huvudsokande_address', $view['display'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'gata', $view['street'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'ort', $view['city'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'postnummer', $view['postal'], true, $row);
    jeab_contacts_sole_paint_keep($row, 'postnr', $view['postal_fmt'], true, $row);
    if (is_array($row['applicant'] ?? null)) {
        jeab_contacts_sole_paint_keep($row['applicant'], 'name', $view['name'], true, $row);
        jeab_contacts_sole_paint_keep($row['applicant'], 'email', $view['email'], true, $row);
        jeab_contacts_sole_paint_keep($row['applicant'], 'phone', $view['phone'], true, $row);
    }

    return $row;
}

/**
 * Kontakt utan mejl, telefon och adress. Ett namn räcker inte.
 *
 * @param array<string, mixed>|null $contact
 */
function jeab_contacts_sole_contact_hollow(?array $contact): bool
{
    if (!is_array($contact)) {
        return false;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);

    return trim((string) $view['email']) === ''
        && trim((string) $view['phone']) === ''
        && trim((string) $view['street']) === ''
        && trim((string) $view['display']) === '';
}

/**
 * Namn utan mejl, telefon och gata ska inte bli en ny kontakt.
 * En bokning från en order har ofta bara namnet kvar på raden.
 *
 * @param array{email?:string,phone?:string,street_key?:string} $hint
 */
function jeab_contacts_sole_booking_link_skips_create(array $hint): bool
{
    return trim((string) ($hint['email'] ?? '')) === ''
        && trim((string) ($hint['phone'] ?? '')) === ''
        && trim((string) ($hint['street_key'] ?? '')) === '';
}

/**
 * booking_id → mängd av contact_id på ordern och påminnelser som redan pekar på bokningen.
 * En fråga per lager och request. Kalendern läser inte hela kontaktregistret för det här.
 *
 * @return array<int, array<string, true>>
 */
function jeab_contacts_sole_booking_sibling_map(): array
{
    if (isset($GLOBALS['_JEAB_BOOKING_SIBLING_MAP']) && is_array($GLOBALS['_JEAB_BOOKING_SIBLING_MAP'])) {
        /** @var array<int, array<string, true>> $cached */
        $cached = $GLOBALS['_JEAB_BOOKING_SIBLING_MAP'];

        return $cached;
    }
    $map = [];
    $add = static function (int $bookingId, string $contactId) use (&$map): void {
        $contactId = trim($contactId);
        if ($bookingId <= 0 || $contactId === '') {
            return;
        }
        if (!isset($map[$bookingId]) || !is_array($map[$bookingId])) {
            $map[$bookingId] = [];
        }
        $map[$bookingId][$contactId] = true;
    };
    $ordersRead = false;
    $pdo = function_exists('jeab_purchase_sql_live') ? jeab_purchase_sql_live() : null;
    if ($pdo instanceof PDO) {
        try {
            $byReminder = [];
            $stmt = $pdo->query('SELECT reminder_id, contact_id FROM purchases WHERE contact_id <> \'\'');
            if ($stmt !== false) {
                foreach ($stmt as $dbRow) {
                    if (!is_array($dbRow)) {
                        continue;
                    }
                    $byReminder[(int) ($dbRow['reminder_id'] ?? 0)] = (string) ($dbRow['contact_id'] ?? '');
                }
            }
            $bookingMap = function_exists('jeab_purchase_booking_ids') ? jeab_purchase_booking_ids() : [];
            foreach ($bookingMap as $bookingId => $reminderId) {
                $add((int) $bookingId, (string) ($byReminder[(int) $reminderId] ?? ''));
            }
            $ordersRead = true;
        } catch (Throwable) {
            $ordersRead = false;
        }
    }
    if (!$ordersRead && function_exists('jeab_purchase_all')) {
        try {
            foreach (jeab_purchase_all() as $purchase) {
                if (!is_array($purchase)) {
                    continue;
                }
                $add((int) ($purchase['booking_id'] ?? 0), (string) ($purchase['contact_id'] ?? ''));
            }
        } catch (Throwable) {
            // Orderlagret kan saknas. Bokningen visas ändå.
        }
    }
    $store = function_exists('jeab_store') ? jeab_store() : null;
    $remindersRead = false;
    if ($store instanceof JeabPdoStore) {
        try {
            $stmt = $store->pdo()->query(
                'SELECT booking_id, contact_id FROM reminders WHERE booking_id > 0 AND contact_id IS NOT NULL AND contact_id <> \'\''
            );
            if ($stmt !== false) {
                $remindersRead = true;
                foreach ($stmt as $dbRow) {
                    if (!is_array($dbRow)) {
                        continue;
                    }
                    $add((int) ($dbRow['booking_id'] ?? 0), (string) ($dbRow['contact_id'] ?? ''));
                }
            }
        } catch (Throwable) {
            $remindersRead = false;
        }
    }
    if (!$remindersRead && $store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $reminder) {
            if (!is_array($reminder)) {
                continue;
            }
            $add((int) ($reminder['booking_id'] ?? 0), (string) ($reminder['contact_id'] ?? ''));
        }
    }
    $GLOBALS['_JEAB_BOOKING_SIBLING_MAP'] = $map;

    return $map;
}

/**
 * Kontakt-id som redan sitter på ordern eller en påminnelse med samma booking_id.
 * Fler än ett id betyder att de pekar åt olika håll: då väljs inget.
 *
 * @return list<string>
 */
function jeab_contacts_sole_booking_sibling_ids(int $bookingId): array
{
    if ($bookingId <= 0) {
        return [];
    }
    $map = jeab_contacts_sole_booking_sibling_map();
    $ids = $map[$bookingId] ?? null;
    if (!is_array($ids) || $ids === []) {
        return [];
    }

    return array_keys($ids);
}

/**
 * @return array<string, mixed>|false|null false när träffen är tvetydig
 */
function jeab_contacts_sole_channel_lookup(string $kind, string $value): array|false|null
{
    if ($value === '' || ($kind !== 'email' && $kind !== 'phone')) {
        return null;
    }
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'] = [];
    }
    $cacheKey = $kind . "\n" . $value;
    if (array_key_exists($cacheKey, $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'])) {
        $cached = $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'][$cacheKey];

        return is_array($cached) || $cached === false ? $cached : null;
    }
    $ids = [];
    $sql = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($sql instanceof JeabPdoContactStore) {
        $rows = $kind === 'email' ? $sql->findByEmail($value) : $sql->findByPhone($value);
        foreach ($rows as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $id = trim((string) ($hit['id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
    } elseif ($kind === 'email') {
        $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare('SELECT DISTINCT contact_id FROM contact_emails WHERE email = ? LIMIT 3');
                $stmt->execute([mb_substr($value, 0, 191)]);
                foreach ($stmt as $dbRow) {
                    if (!is_array($dbRow)) {
                        continue;
                    }
                    $id = trim((string) ($dbRow['contact_id'] ?? ''));
                    if ($id !== '') {
                        $ids[$id] = true;
                    }
                }
            } catch (Throwable) {
                $ids = [];
            }
        }
    }
    $soleSql = function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source();
    if ($ids === [] && !$soleSql) {
        // Sidan avkodar inte hela JSON-registret för ett mejl eller ett nummer.
        if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
            $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'][$cacheKey] = null;

            return null;
        }
        $index = jeab_contacts_sole_json_channel_index();
        $mapped = $index[$kind][$value] ?? null;
        if ($mapped === '') {
            $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'][$cacheKey] = false;

            return false;
        }
        if (is_string($mapped) && $mapped !== '') {
            $ids[$mapped] = true;
        }
    }
    if (count($ids) > 1) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'][$cacheKey] = false;

        return false;
    }
    if (count($ids) === 1) {
        $contact = jeab_contacts_sole_linked_contact((string) array_key_first($ids));
        $result = is_array($contact) ? $contact : null;
        $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'][$cacheKey] = $result;

        return $result;
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS'][$cacheKey] = null;

    return null;
}

/**
 * Mejl- och telefonindex för JSON-registret. Byggs en gång per request,
 * och bara när en olänkad bokning saknar syskon och SQL-indexet inte svarar.
 *
 * @return array{email:array<string, string>,phone:array<string, string>}
 */
function jeab_contacts_sole_json_channel_index(): array
{
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_INDEX']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_INDEX'])) {
        /** @var array{email:array<string, string>,phone:array<string, string>} $cached */
        $cached = $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_INDEX'];

        return $cached;
    }
    $index = ['email' => [], 'phone' => []];
    $rows = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '';
        if ($email !== '') {
            $index['email'][$email] = !isset($index['email'][$email]) || $index['email'][$email] === $id ? $id : '';
        }
        $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($row) : '';
        if ($phone !== '') {
            $index['phone'][$phone] = !isset($index['phone'][$phone]) || $index['phone'][$phone] === $id ? $id : '';
        }
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_INDEX'] = $index;

    return $index;
}

/**
 * Exakt en kontakt med mejlet eller telefonen. Namn används inte.
 * Tvetydig mejlträff lämnas, även om telefonen skulle träffa en.
 *
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_unique_channel_contact(string $email, string $phone): ?array
{
    if (function_exists('jeab_kontakt_email')) {
        $email = jeab_kontakt_email($email);
    } else {
        $email = strtolower(trim($email));
    }
    if ($email !== '' && function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email)) {
        $email = '';
    }
    if (function_exists('jeab_kontakt_phone')) {
        $phone = jeab_kontakt_phone($phone);
    } else {
        $phone = trim($phone);
    }
    if ($phone !== '' && function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    if ($email !== '') {
        $found = jeab_contacts_sole_channel_lookup('email', $email);
        if ($found === false) {
            return null;
        }
        if (is_array($found)) {
            return $found;
        }
    }
    if ($phone !== '') {
        $found = jeab_contacts_sole_channel_lookup('phone', $phone);
        if (is_array($found)) {
            return $found;
        }
    }

    return null;
}

/**
 * Kontakten kalendern ska visa, och om Spara får byta bokningens contact_id.
 * En riktig länk vinner. Saknas den, eller är den bara ett namn, används
 * orderns eller påminnelsens contact_id när exakt ett id finns.
 * Skriver inte.
 *
 * @param array<string, mixed> $row
 * @return array{contact:array<string, mixed>,view:array<string, mixed>,id:string,repoint:bool}|null
 */
function jeab_contacts_sole_booking_pick(array $row): ?array
{
    if (!jeab_contacts_sole()) {
        return null;
    }
    $storedId = trim((string) ($row['contact_id'] ?? ''));
    $stored = $storedId !== '' ? jeab_contacts_sole_linked_contact($storedId) : null;
    if (is_array($stored) && !jeab_contacts_sole_contact_hollow($stored)) {
        return jeab_contacts_sole_booking_pack($stored, $row, false);
    }
    $siblings = jeab_contacts_sole_booking_sibling_ids((int) ($row['id'] ?? 0));
    if (count($siblings) === 1) {
        $sibling = jeab_contacts_sole_linked_contact($siblings[0]);
        if (is_array($sibling)) {
            $siblingId = trim((string) ($sibling['id'] ?? ''));

            return jeab_contacts_sole_booking_pack($sibling, $row, $siblingId !== '' && $siblingId !== $storedId);
        }
    }
    if (count($siblings) > 1) {
        return is_array($stored) ? jeab_contacts_sole_booking_pack($stored, $row, false) : null;
    }
    if (is_array($stored)) {
        return jeab_contacts_sole_booking_pack($stored, $row, false);
    }
    $channel = jeab_contacts_sole_unique_channel_contact(
        (string) ($row['email'] ?? ''),
        (string) ($row['phone'] ?? '')
    );
    if (is_array($channel)) {
        return jeab_contacts_sole_booking_pack($channel, $row, true);
    }

    return null;
}

/**
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $row
 * @return array{contact:array<string, mixed>,view:array<string, mixed>,id:string,repoint:bool}
 */
function jeab_contacts_sole_booking_pack(array $contact, array $row, bool $repoint): array
{
    $view = jeab_contacts_sole_view_from_contact($contact);
    $id = trim((string) ($contact['id'] ?? $view['id'] ?? ''));
    $view['id'] = $id;
    $view['name'] = jeab_contacts_sole_resolve_display_name(
        $contact,
        (string) $view['name'],
        jeab_contacts_sole_row_stored_names($row),
        array_merge([(string) $view['email']], jeab_contacts_sole_row_emails($row)),
        false,
        $id
    );

    return [
        'contact' => $contact,
        'view' => $view,
        'id' => $id,
        'repoint' => $repoint && $id !== '',
    ];
}

/**
 * Namn och väg för en bokning. Samma funktion målar kalendern och
 * contacts.name_source. Kontaktens riktiga namn vinner. Annars används
 * ett riktigt namn på ordern eller påminnelsen som hör till booking_id.
 * Saknas båda och CONTACTS_NAME_CANDIDATE_DISPLAY är på, och det finns
 * exakt ett distinkt kandidatnamn, blir vägen candidate. Flaggan är av
 * som standard: då körs inget kandidatuppslag och vägen blir som utan
 * den reserven. Annars none och paint lämnar kopian som förut.
 * Order-JSON läses bara som reserv. Kandidater slås upp bara när flaggan
 * är på och de behövs, med request-cache per bokning och kontakt.
 * Skriver inte.
 *
 * @param array<string, mixed> $row
 * @return array{name:string,path:string,source_id:?int,contact_id:string,contact_name_real:bool,picked:?array}
 */
function jeab_contacts_sole_booking_name_choice(array $row): array
{
    $base = [
        'name' => '',
        'path' => 'none',
        'source_id' => null,
        'contact_id' => '',
        'contact_name_real' => false,
        'picked' => null,
    ];
    if (!jeab_contacts_sole()) {
        return $base;
    }
    $picked = jeab_contacts_sole_booking_pick($row);
    if ($picked === null) {
        return $base;
    }
    $contact = is_array($picked['contact'] ?? null) ? $picked['contact'] : [];
    $contactId = trim((string) ($picked['id'] ?? ''));
    $emails = jeab_contacts_sole_contact_emails($contact);
    $best = jeab_contacts_sole_best_owner_name($contact, $emails);
    $real = $best !== '' && jeab_contacts_sole_name_is_real($best, $emails);
    $base['picked'] = $picked;
    $base['contact_id'] = $contactId;
    $base['contact_name_real'] = $real;
    if ($real) {
        // Riktigt namn är inte en reserv. Orderns JSON läses inte.
        $base['name'] = $best;
        $base['path'] = 'contact';

        return $base;
    }
    $linked = jeab_contacts_sole_linked_stored_names($contactId, (int) ($row['id'] ?? 0), $emails);
    $path = (string) ($linked['path'] ?? 'none');
    $linkedName = trim((string) ($linked['name'] ?? ''));
    $sourceId = (int) ($linked['source_id'] ?? 0);
    if (($path === 'linked_order' || $path === 'linked_reminder') && $linkedName !== '' && $sourceId > 0) {
        $base['name'] = $linkedName;
        $base['path'] = $path;
        $base['source_id'] = $sourceId;

        return $base;
    }
    // Flaggan av: inget kandidatuppslag och ingen require. Vägen är som utan reserven.
    if (jeab_contacts_name_candidate_display()) {
        if (!function_exists('jeab_contacts_name_candidates_unique_name')) {
            require_once __DIR__ . '/contacts_name_candidates.php';
        }
        if (function_exists('jeab_contacts_name_candidates_unique_name')) {
            $cand = jeab_contacts_name_candidates_unique_name($row);
            if ($cand !== '') {
                $base['name'] = $cand;
                $base['path'] = 'candidate';

                return $base;
            }
        }
    }
    $view = is_array($picked['view'] ?? null) ? $picked['view'] : [];
    $base['name'] = trim((string) ($view['name'] ?? ''));

    return $base;
}

/**
 * Riktigt namn på ordern eller påminnelsen som pekar på booking_id.
 * Order före påminnelse när båda har ett fullständigt namn. Ett
 * fullständigt namn vinner över ett ensamt ord, i id-ordning.
 * Läser namnfält, inte orderns payload. Orderraderna hämtas en gång
 * per kontakt och bokning i requesten.
 *
 * @param list<string> $emails
 * @return array{names:list<string>,emails:list<string>,name:string,path:string,source_id:?int}
 */
function jeab_contacts_sole_booking_linked_names(int $bookingId, array $emails = [], string $contactId = ''): array
{
    $empty = ['names' => [], 'emails' => [], 'name' => '', 'path' => 'none', 'source_id' => null];
    if ($bookingId <= 0) {
        return $empty;
    }
    $packed = [];
    foreach (jeab_contacts_sole_booking_order_cached_rows($contactId, $bookingId) as $row) {
        $packed[] = ['path' => 'linked_order', 'id' => (int) $row['id'], 'names' => $row['names'], 'emails' => $row['emails']];
    }
    foreach (jeab_contacts_sole_booking_reminder_rows($bookingId) as $row) {
        $packed[] = ['path' => 'linked_reminder', 'id' => (int) $row['id'], 'names' => $row['names'], 'emails' => $row['emails']];
    }
    $choose = static function (bool $fullOnly) use ($packed, $emails): ?array {
        foreach ($packed as $row) {
            if ((int) $row['id'] <= 0) {
                continue;
            }
            $rowEmails = jeab_contacts_sole_clean_emails(array_merge($emails, is_array($row['emails']) ? $row['emails'] : []));
            $name = jeab_contacts_sole_first_real_name(is_array($row['names']) ? $row['names'] : [], $rowEmails, $fullOnly);
            if ($name === '') {
                continue;
            }

            return [
                'names' => is_array($row['names']) ? $row['names'] : [],
                'emails' => $rowEmails,
                'name' => $name,
                'path' => (string) $row['path'],
                'source_id' => (int) $row['id'],
            ];
        }

        return null;
    };
    $hit = $choose(true) ?? $choose(false);

    return $hit ?? $empty;
}

/** CONTACTS_NAME_CANDIDATE_DISPLAY. Standard av, samma källor som övriga flaggor. */
function jeab_contacts_name_candidate_display(): bool
{
    if (!function_exists('jeab_flag_effective')) {
        return false;
    }
    $flag = jeab_flag_effective('CONTACTS_NAME_CANDIDATE_DISPLAY');

    return (int) ($flag['value'] ?? 0) === 1;
}

/**
 * Orderrader för en bokning, cachade på contact_id under requesten.
 * Bokningens id ligger inuti kontakten så två bokningar inte delar namn.
 *
 * @return list<array{id:int,names:list<string>,emails:list<string>}>
 */
function jeab_contacts_sole_booking_order_cached_rows(string $contactId, int $bookingId): array
{
    $contactId = trim($contactId);
    if ($contactId !== '' && $bookingId > 0) {
        $bag = $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'] ?? null;
        if (is_array($bag) && isset($bag[$contactId]) && is_array($bag[$contactId]) && array_key_exists($bookingId, $bag[$contactId]) && is_array($bag[$contactId][$bookingId])) {
            return $bag[$contactId][$bookingId];
        }
    }
    $rows = jeab_contacts_sole_booking_order_rows($bookingId);
    if ($contactId === '' || $bookingId <= 0) {
        return $rows;
    }
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'] = [];
    }
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'][$contactId]) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'][$contactId])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'][$contactId] = [];
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER'][$contactId][$bookingId] = $rows;

    return $rows;
}

/**
 * @return list<array{id:int,names:list<string>,emails:list<string>}>
 */
function jeab_contacts_sole_booking_order_rows(int $bookingId): array
{
    if ($bookingId <= 0 || !function_exists('jeab_purchase_sql_live')) {
        return [];
    }
    try {
        $pdo = jeab_purchase_sql_live();
        if (!$pdo instanceof PDO) {
            return [];
        }
        if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS'])) {
            $GLOBALS['_JEAB_CONTACTS_SOLE_BOOKING_ORDER_CALLS']++;
        }
        $mysql = function_exists('jeab_sql_purchases_is_mysql')
            ? jeab_sql_purchases_is_mysql($pdo)
            : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $text = static function (string $path) use ($mysql): string {
            if ($mysql) {
                $extract = "JSON_UNQUOTE(JSON_EXTRACT(payload, '" . $path . "'))";

                return "IF(JSON_VALID(payload), NULLIF(" . $extract . ", 'null'), NULL)";
            }
            $extract = "json_extract(payload, '" . $path . "')";

            return "CASE WHEN JSON_VALID(payload) THEN NULLIF(" . $extract . ", 'null') END";
        };
        $bookingExpr = $mysql
            ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_id')) AS UNSIGNED)"
            : "CAST(json_extract(payload, '$.booking_id') AS INTEGER)";
        $sql = 'SELECT reminder_id, '
            . $text('$.huvudsokande') . ' AS huvudsokande, '
            . $text('$.customer_name') . ' AS customer_name, '
            . $text('$.name') . ' AS name, '
            . $text('$.applicant.name') . ' AS applicant_name, '
            . $text('$.email') . ' AS email, '
            . $text('$.applicant.email') . ' AS applicant_email '
            . 'FROM purchases WHERE JSON_VALID(payload) AND reminder_id > 0 AND ' . $bookingExpr . ' = ? ORDER BY reminder_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$bookingId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['reminder_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $names = [];
            foreach (['huvudsokande', 'customer_name', 'name', 'applicant_name'] as $key) {
                $name = jeab_contacts_sole_linked_text($row[$key] ?? null);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $mail = [];
            foreach (['email', 'applicant_email'] as $key) {
                $email = jeab_contacts_sole_linked_text($row[$key] ?? null);
                if ($email !== '') {
                    $mail[] = $email;
                }
            }
            $out[] = ['id' => $id, 'names' => $names, 'emails' => $mail];
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * @return list<array{id:int,names:list<string>,emails:list<string>}>
 */
function jeab_contacts_sole_booking_reminder_rows(int $bookingId): array
{
    if ($bookingId <= 0) {
        return [];
    }
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
        if (!$store instanceof JeabPdoStore) {
            return [];
        }
        $pdo = $store->pdo();
        $copy = jeab_copy_select($pdo, 'reminders', ['customer_name', 'email']);
        $stmt = $pdo->prepare('SELECT id, ' . $copy . ' FROM reminders WHERE booking_id = ? AND id > 0 ORDER BY id');
        $stmt->execute([$bookingId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $names = [];
            $name = jeab_contacts_sole_linked_text($row['customer_name'] ?? null);
            if ($name !== '') {
                $names[] = $name;
            }
            $mail = [];
            $email = jeab_contacts_sole_linked_text($row['email'] ?? null);
            if ($email !== '') {
                $mail[] = $email;
            }
            $out[] = ['id' => $id, 'names' => $names, 'emails' => $mail];
        }

        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Kalenderns kundfält. Flaggan av lämnar raden. Flaggan på läser den länkade
 * kontakten. Ett riktigt kontaktnamn vinner över order och påminnelse.
 * Saknas det används namnet på ordern eller påminnelsen med samma booking_id.
 * En bokning utan länk lämnas, och kopian står kvar när ingen reserv finns.
 * Skriver inte och gissar inte på namn.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_paint_booking(array $row): array
{
    if (!jeab_contacts_sole()) {
        return $row;
    }
    $choice = jeab_contacts_sole_booking_name_choice($row);
    $picked = is_array($choice['picked'] ?? null) ? $choice['picked'] : null;
    if ($picked === null) {
        $row['contact_link_missing'] = true;

        return $row;
    }
    unset($row['contact_link_missing']);
    $view = is_array($picked['view'] ?? null) ? $picked['view'] : [];
    $blank = jeab_contacts_strict();
    $path = (string) ($choice['path'] ?? 'none');
    $name = ($path === 'contact' || $path === 'linked_order' || $path === 'linked_reminder' || $path === 'candidate')
        ? (string) ($choice['name'] ?? '')
        : (string) ($view['name'] ?? '');
    jeab_contacts_sole_paint_keep($row, 'customer_name', $name, $blank, $row, 'booking');
    jeab_contacts_sole_paint_keep($row, 'email', (string) ($view['email'] ?? ''), $blank, $row, 'booking');
    jeab_contacts_sole_paint_keep($row, 'phone', (string) ($view['phone'] ?? ''), $blank, $row, 'booking');
    jeab_contacts_sole_paint_keep($row, 'address', (string) ($view['display'] ?? ''), $blank, $row, 'booking');

    return $row;
}

/**
 * Påminnelsens visning. Flaggan av lämnar raden. Flaggan på och en hittad
 * kontakt målar namn, adress, telefon och e-post. Tomma fält och en saknad
 * länk lämnar kopian. Skriver inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_paint_reminder(array $row): array
{
    $view = jeab_contacts_sole_view_for($row);
    if ($view === null) {
        return $row;
    }
    if (empty($view['linked'])) {
        $row['contact_link_missing'] = true;

        return $row;
    }
    unset($row['contact_link_missing']);
    $blank = jeab_contacts_strict();
    jeab_contacts_sole_paint_keep($row, 'customer_name', $view['name'], $blank, $row, 'reminder');
    jeab_contacts_sole_paint_keep($row, 'email', $view['email'], $blank, $row, 'reminder');
    jeab_contacts_sole_paint_keep($row, 'phone', $view['phone'], $blank, $row, 'reminder');
    jeab_contacts_sole_paint_keep($row, 'address', $view['display'], $blank, $row, 'reminder');
    jeab_contacts_sole_paint_keep($row, 'ort', $view['city'], $blank, $row, 'reminder');
    jeab_contacts_sole_paint_keep($row, 'postal', $view['postal'], $blank, $row, 'reminder');

    return $row;
}

/**
 * Radens egen kopia av de fält utgående flöden läser.
 *
 * @param array<string, mixed> $row
 * @return array{name:string,email:string,phone:string,street:string,postal:string,city:string,display:string,owners:list<array<string,mixed>>}
 */
function jeab_contacts_sole_recipient_copy(array $row): array
{
    $name = '';
    foreach (['huvudsokande', 'customer_name', 'name'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            $name = $value;
            break;
        }
    }
    $email = trim((string) ($row['email'] ?? ''));
    $phone = trim((string) ($row['phone'] ?? ''));
    $street = trim((string) ($row['gata'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($row['street'] ?? ''));
    }
    $city = trim((string) ($row['ort'] ?? ''));
    if ($city === '') {
        $city = trim((string) ($row['city'] ?? ''));
    }
    $postal = trim((string) ($row['postnummer'] ?? ''));
    if ($postal === '') {
        $postal = trim((string) ($row['postal'] ?? ''));
    }
    if ($postal === '') {
        $postal = trim((string) ($row['postnr'] ?? ''));
    }
    $display = trim((string) ($row['address'] ?? ''));
    if ($display === '') {
        $display = trim((string) ($row['huvudsokande_address'] ?? ''));
    }
    if ($display === '') {
        $tail = trim($postal . ($city !== '' ? ' ' . $city : ''));
        if ($street !== '' && $tail !== '') {
            $display = $street . ', ' . $tail;
        } elseif ($street !== '') {
            $display = $street;
        } else {
            $display = $tail;
        }
    }

    return [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'street' => $street,
        'postal' => $postal,
        'city' => $city,
        'display' => $display,
        'owners' => jeab_contacts_sole_recipient_owner_copy($row),
    ];
}

/**
 * Ägarlistan på raden. owners om den har poster, annars medsokande.
 *
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function jeab_contacts_sole_recipient_owner_copy(array $row): array
{
    foreach (['owners', 'medsokande'] as $key) {
        if (!isset($row[$key]) || !is_array($row[$key]) || $row[$key] === []) {
            continue;
        }
        $out = [];
        foreach ($row[$key] as $item) {
            if (is_array($item)) {
                $out[] = $item;
                continue;
            }
            if (is_string($item) || is_numeric($item)) {
                $name = trim((string) $item);
                if ($name !== '') {
                    $out[] = ['name' => $name, 'personnummer' => '', 'email' => '', 'phone' => '', 'address' => ''];
                }
            }
        }
        if ($out !== []) {
            return $out;
        }
    }

    return [];
}

function jeab_contacts_sole_recipient_same_name(string $a, string $b): bool
{
    if (function_exists('jeab_ordrar_same_person')) {
        return jeab_ordrar_same_person($a, $b);
    }
    $a = trim($a);
    $b = trim($b);

    return $a !== '' && $b !== '' && strcasecmp($a, $b) === 0;
}

/** Trim, gemener och ett mellanslag mellan orden. Tomt namn ger tom nyckel. */
function jeab_contacts_sole_name_key(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

/**
 * 12 siffror utan bindestreck, för jämförelse. Ogiltig form ger tomt.
 * jeab_norm_pnr_digits lägger till sekel och tar bort bindestreck.
 */
function jeab_contacts_sole_pnr_key(string $raw): string
{
    if (function_exists('jeab_norm_pnr_digits')) {
        return jeab_norm_pnr_digits($raw);
    }

    return '';
}

/**
 * Visning och utgående flöden (Fortnox, fullmakt, UI): ÅÅÅÅMMDD-NNNN.
 * Ogiltig form lämnas trimmad. Maskerade värden rörs inte.
 */
function jeab_contacts_sole_pnr_format(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || str_contains($raw, '•') || str_contains($raw, '*')) {
        return $raw;
    }
    if (function_exists('jeab_pnr_normalize')) {
        $norm = jeab_pnr_normalize($raw);

        return $norm !== '' ? $norm : $raw;
    }
    $digits = function_exists('jeab_norm_pnr_digits') ? jeab_norm_pnr_digits($raw) : '';
    if (strlen($digits) === 12) {
        return substr($digits, 0, 8) . '-' . substr($digits, 8);
    }

    return $raw;
}

/** Samma som jeab_contacts_sole_pnr_format. Utgående anropare läser den här. */
function jeab_contacts_sole_pnr_text(string $raw): string
{
    return jeab_contacts_sole_pnr_format($raw);
}

/**
 * Lagring på kontakten: 12 siffror utan bindestreck via jeab_norm_pnr_digits.
 * Organisationsnummer (tio siffror, tredje siffran 2–9) behåller NNNNNN-NNNN.
 * Ogiltig form lagras trimmad, oförändrad. Maskerade värden rörs inte.
 */
function jeab_contacts_sole_pnr_store_value(string $raw): string
{
    $trimmed = trim($raw);
    if ($trimmed === '' || str_contains($trimmed, '•') || str_contains($trimmed, '*')) {
        return $trimmed;
    }
    $clean = function_exists('jeab_idnr_clean') ? jeab_idnr_clean($trimmed) : $trimmed;
    $only = preg_replace('/\D+/', '', $clean) ?? '';
    if (strlen($only) === 10 && (int) $only[2] >= 2 && function_exists('jeab_orgnr_normalize')) {
        return jeab_orgnr_normalize($trimmed);
    }
    $digits = function_exists('jeab_norm_pnr_digits') ? jeab_norm_pnr_digits($trimmed) : '';

    return $digits !== '' ? $digits : $trimmed;
}

/** Sant bara medan contacts_sole själv skriver ett personnummer och flaggan är på. */
function jeab_contacts_sole_pnr_digits_store(): bool
{
    return jeab_contacts_sole() && !empty($GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS']);
}

function jeab_contacts_sole_pnr_digits_push(): void
{
    $depth = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS_DEPTH'] ?? 0);
    $GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS_DEPTH'] = $depth + 1;
    $GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS'] = true;
}

function jeab_contacts_sole_pnr_digits_pop(): void
{
    $depth = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS_DEPTH'] ?? 0);
    $depth = max(0, $depth - 1);
    $GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS_DEPTH'] = $depth;
    if ($depth === 0) {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_PNR_DIGITS']);
    }
}

/** Nyckel från JEAB_PNR_HMAC_KEY, annars dirname(webbrot)/privat/pnr-hmac.key. Tom om båda saknas. */
function jeab_contacts_sole_pnr_hmac_key(): string
{
    $env = getenv('JEAB_PNR_HMAC_KEY');
    if (is_string($env)) {
        $env = trim($env);
        if ($env !== '') {
            return $env;
        }
    }
    $path = jeab_outside_privat_dir() . '/pnr-hmac.key';
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        return '';
    }
    $lines = preg_split('/\R/', trim($raw), 2) ?: [];
    $key = trim((string) ($lines[0] ?? ''));

    return $key;
}

/** HMAC-SHA256 av det normaliserade numret. Tom nyckel eller ogiltig form ger tom sträng. */
function jeab_contacts_sole_pnr_hmac(string $raw): string
{
    $digits = function_exists('jeab_norm_pnr_digits') ? jeab_norm_pnr_digits($raw) : '';
    if ($digits === '') {
        return '';
    }
    $key = jeab_contacts_sole_pnr_hmac_key();
    if ($key === '') {
        return '';
    }

    return hash_hmac('sha256', $digits, $key);
}

/**
 * Ordens ägare. owners om den har poster, annars medsokande.
 * Samma person i båda listorna räknas en gång.
 *
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function jeab_contacts_sole_order_people(array $row): array
{
    foreach (['owners', 'medsokande'] as $key) {
        $list = $row[$key] ?? null;
        if (!is_array($list)) {
            continue;
        }
        $people = [];
        foreach ($list as $person) {
            if (is_array($person)) {
                $people[] = $person;
            }
        }
        if ($people !== []) {
            return $people;
        }
    }

    return [];
}

/** Första och sista namnet. Mellannamn ingår inte. Tomt när namnet saknar båda. */
function jeab_contacts_sole_name_first_last_same(string $a, string $b): bool
{
    if (!function_exists('jeab_ordrar_name_parts')) {
        return false;
    }
    $left = jeab_ordrar_name_parts($a);
    $right = jeab_ordrar_name_parts($b);
    if (count($left) < 2 || count($right) < 2) {
        return false;
    }

    return $left[0] === $right[0] && $left[count($left) - 1] === $right[count($right) - 1];
}

/**
 * Kontaktens första namn är något av orderpersonens förnamn, och efternamnen är lika.
 * Bindestreck delas. Jämförelsen loggar inget nummer.
 */
function jeab_contacts_sole_name_given_last_same(string $slotName, string $orderName): bool
{
    if (!function_exists('jeab_ordrar_name_parts')) {
        return false;
    }
    $slot = jeab_ordrar_name_parts($slotName);
    $order = jeab_ordrar_name_parts($orderName);
    if (count($slot) < 2 || count($order) < 2) {
        return false;
    }
    if ($slot[count($slot) - 1] !== $order[count($order) - 1]) {
        return false;
    }
    $first = $slot[0];
    $given = [];
    foreach (array_slice($order, 0, -1) as $part) {
        $given[] = $part;
        foreach (explode('-', $part) as $sub) {
            if ($sub !== '') {
                $given[] = $sub;
            }
        }
    }

    return in_array($first, $given, true);
}

/**
 * Personer på ordern med samma för- och efternamn som $name.
 *
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function jeab_contacts_sole_first_last_hits(array $row, string $name): array
{
    if (!jeab_contacts_sole_name_first_last_same($name, $name)) {
        return [];
    }
    $hits = [];
    foreach (jeab_contacts_sole_order_people($row) as $person) {
        $personName = (string) ($person['name'] ?? $person['namn'] ?? '');
        if (jeab_contacts_sole_name_first_last_same($name, $personName)) {
            $hits[] = $person;
        }
    }

    return $hits;
}

/**
 * Personen på ordern när för- och efternamn ger exakt en träff.
 * Mellannamn ignoreras. Flera träffar ger null.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_recipient_order_person_first_last(array $row, string $name): ?array
{
    $hits = jeab_contacts_sole_first_last_hits($row, $name);

    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * Kod för en olöst ägarplats. Tom text när numret saknas, annars ogiltig form,
 * namn som skiljer sig mer än mellannamn, eller flera personer med samma
 * för- och efternamn. Inget nummer ingår.
 */
function jeab_contacts_sole_pnr_ambiguous_reason_code(array $row, string $name, string $pnrText, string $pnrRaw): string
{
    $valid = function_exists('jeab_kontakt_pnr_valid') && jeab_kontakt_pnr_valid($pnrRaw);
    if (!$valid) {
        return trim($pnrRaw) === '' && $pnrText === '' ? 'pnr_missing' : 'pnr_invalid';
    }
    if (count(jeab_contacts_sole_first_last_hits($row, $name)) >= 2) {
        return 'name_multiple';
    }

    return 'name_mismatch';
}

function jeab_contacts_sole_pnr_ambiguous_reason_text(string $code): string
{
    $text = match ($code) {
        'pnr_missing' => 'Personnumret saknas.',
        'pnr_invalid' => 'Personnumret har fel format.',
        'name_mismatch' => 'Namnet skiljer sig mer än mellannamn.',
        'name_multiple' => 'Flera personer har samma för- och efternamn.',
        default => 'Orsaken saknas.',
    };
    $safe = jeab_contacts_sole_pnr_list_text($text);

    return $safe !== '' ? $safe : 'Orsaken saknas.';
}

/**
 * Kontakten sparades i Kontakter efter ordern.
 * Lantmäteriets egen skrivning räknas inte. Saknad ordertid räknas inte.
 *
 * @param array<string, mixed> $owner
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $row
 */
function jeab_contacts_sole_owner_saved_after_order(array $owner, array $contact, array $row): bool
{
    $orderTs = jeab_contacts_sole_paint_order_ts($row);
    if ($orderTs === '') {
        return false;
    }
    $saved = trim((string) ($owner['saved_at'] ?? ''));
    if ($saved === '') {
        if (trim((string) ($contact['owner_stamp'] ?? '')) === 'lantmateriet') {
            return false;
        }
        $saved = trim((string) ($contact['updated_at'] ?? ''));
    }
    if ($saved === '') {
        return false;
    }

    return jeab_contacts_sole_paint_ts_cmp($saved, $orderTs) > 0;
}

/**
 * Personen på ordern när det normaliserade namnet ger exakt en träff.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_recipient_order_person(array $row, string $name): ?array
{
    $nameKey = jeab_contacts_sole_name_key($name);
    if ($nameKey === '') {
        return null;
    }
    $hits = [];
    foreach (jeab_contacts_sole_order_people($row) as $person) {
        $personName = (string) ($person['name'] ?? $person['namn'] ?? '');
        if (jeab_contacts_sole_name_key($personName) === $nameKey) {
            $hits[] = $person;
        }
    }

    return count($hits) === 1 ? $hits[0] : null;
}

/**
 * Exakt namn först. Annars tilltalsnamn: första namnet på platsen finns bland
 * orderpersonens förnamn och efternamnen är lika.
 * Finns ett nummer på platsen måste det vara samma som orderpersonens.
 * Tom plats kräver exakt ett nummer bland träffarna. Numret loggas inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_order_person_for_owner(array $row, string $name, string $pnrRaw): ?array
{
    $exact = jeab_contacts_sole_recipient_order_person($row, $name);
    if (is_array($exact)) {
        return $exact;
    }
    $hits = [];
    foreach (jeab_contacts_sole_order_people($row) as $person) {
        $personName = (string) ($person['name'] ?? $person['namn'] ?? '');
        if (jeab_contacts_sole_name_given_last_same($name, $personName)) {
            $hits[] = $person;
        }
    }
    if ($hits === []) {
        return null;
    }
    $slotKey = jeab_contacts_sole_pnr_key($pnrRaw);
    if ($slotKey !== '') {
        $matched = [];
        foreach ($hits as $person) {
            $personKey = jeab_contacts_sole_pnr_key((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
            if ($personKey !== '' && strlen($personKey) === strlen($slotKey) && hash_equals($personKey, $slotKey)) {
                $matched[] = $person;
            }
        }

        return count($matched) === 1 ? $matched[0] : null;
    }
    $distinct = [];
    foreach ($hits as $person) {
        $raw = trim((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
        $key = jeab_contacts_sole_pnr_distinct_key($raw);
        if ($key === '') {
            continue;
        }
        $distinct[$key] = $person;
    }

    return count($distinct) === 1 ? array_values($distinct)[0] : null;
}

/**
 * Kontaktens ägare när listan har minst en person. Tom lista: null, så kopian gäller.
 * Tomt personnummer eller tom adress fylls bara när namnet ger exakt en person.
 * Noll eller flera träffar lämnar fältet tomt och sätter ambiguous.
 * Giltigt personnummer och samma för- och efternamn (mellannamn ignoreras) är löst.
 * Samma sak när ägarplatsen sparats i Kontakter efter ordern.
 * $reasons fylls med index och orsak för varje olöst plats. Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $contact
 * @param list<array{index:int,reason:string}>|null $reasons
 * @return list<array<string, mixed>>|null
 */
function jeab_contacts_sole_recipient_owners_from(array $row, array $contact, ?bool &$ambiguous = null, ?array &$reasons = null): ?array
{
    $ambiguous = false;
    if (is_array($reasons)) {
        $reasons = [];
    }
    $raw = $contact['owners'] ?? null;
    if (!is_array($raw)) {
        return null;
    }
    $built = [];
    foreach ($raw as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $email = trim((string) ($owner['email'] ?? ''));
        $phone = trim((string) ($owner['phone'] ?? ''));
        $pnr = jeab_contacts_sole_pnr_text((string) ($owner['personnummer'] ?? ''));
        $address = trim((string) ($owner['address'] ?? $owner['adress'] ?? ''));
        if ($name === '' && $email === '' && $phone === '' && $pnr === '') {
            continue;
        }
        if ($pnr === '' || $address === '') {
            $match = jeab_contacts_sole_order_person_for_owner($row, $name, (string) ($owner['personnummer'] ?? ''));
            if (is_array($match)) {
                if ($pnr === '') {
                    $pnr = jeab_contacts_sole_pnr_text((string) ($match['personnummer'] ?? ''));
                }
                if ($address === '') {
                    $address = trim((string) ($match['address'] ?? $match['adress'] ?? ''));
                }
            } else {
                $pnrValid = function_exists('jeab_kontakt_pnr_valid')
                    ? jeab_kontakt_pnr_valid((string) ($owner['personnummer'] ?? ''))
                    : false;
                $loose = $pnrValid ? jeab_contacts_sole_recipient_order_person_first_last($row, $name) : null;
                if (is_array($loose)) {
                    if ($address === '') {
                        $address = trim((string) ($loose['address'] ?? $loose['adress'] ?? ''));
                    }
                } elseif ($pnrValid && jeab_contacts_sole_owner_saved_after_order($owner, $contact, $row)) {
                    // Platsen är redan sparad i Kontakter. Orderns korta namn lämnas.
                } else {
                    $ambiguous = true;
                    if (is_array($reasons)) {
                        $reasons[] = [
                            'index' => (int) $i,
                            'reason' => jeab_contacts_sole_pnr_ambiguous_reason_text(
                                jeab_contacts_sole_pnr_ambiguous_reason_code(
                                    $row,
                                    $name,
                                    $pnr,
                                    (string) ($owner['personnummer'] ?? '')
                                )
                            ),
                        ];
                    }
                }
            }
        }
        if (!$ambiguous && $pnr !== '' && jeab_contacts_sole_recipient_order_person($row, $name) === null) {
            $slotKey = jeab_contacts_sole_pnr_key((string) ($owner['personnummer'] ?? ''));
            if ($slotKey !== '') {
                $conflict = false;
                $same = false;
                foreach (jeab_contacts_sole_order_people($row) as $person) {
                    $personName = (string) ($person['name'] ?? $person['namn'] ?? '');
                    if (!jeab_contacts_sole_name_given_last_same($name, $personName)) {
                        continue;
                    }
                    $personKey = jeab_contacts_sole_pnr_key((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
                    if ($personKey === '') {
                        continue;
                    }
                    if (strlen($personKey) === strlen($slotKey) && hash_equals($personKey, $slotKey)) {
                        $same = true;
                    } else {
                        $conflict = true;
                    }
                }
                if ($conflict && !$same) {
                    $ambiguous = true;
                    if (is_array($reasons)) {
                        $reasons[] = [
                            'index' => (int) $i,
                            'reason' => jeab_contacts_sole_pnr_ambiguous_reason_text('name_mismatch'),
                        ];
                    }
                }
            }
        }
        $built[] = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'personnummer' => $pnr,
        ];
    }

    return $built === [] ? null : $built;
}

/**
 * @param list<array<string, mixed>> $owners
 * @return list<array<string, mixed>>
 */
function jeab_contacts_sole_recipient_med_owners(array $owners, string $huvud): array
{
    $huvud = trim($huvud);
    $out = [];
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($huvud !== '' && jeab_contacts_sole_recipient_same_name($huvud, $name)) {
            continue;
        }
        $out[] = $owner;
    }

    return $out;
}

/**
 * Kontakten för raden. Flaggan av eller saknat id ger null och gör inget uppslag.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_recipient_contact(array $row): ?array
{
    if (!jeab_contacts_sole()) {
        return null;
    }
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '') {
        return null;
    }
    $contact = jeab_contacts_sole_linked_contact($id);
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [];
    }
    if (!array_key_exists($id, $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id] = is_array($contact) ? $contact : null;
    }

    return is_array($contact) ? $contact : null;
}

/**
 * Mottagare för utgående flöden.
 * Flaggan av: radens kopia, utan kontaktuppslag.
 * Flaggan på: kontaktens icke-tomma värden, annars kopian.
 * Saknad kontakt lämnar kopian. Tomma kontaktfält målar inte över.
 * Produkt, nätägare, fastighet och pris ingår inte.
 *
 * @param array<string, mixed> $row
 * @return array{name:string,email:string,phone:string,street:string,postal:string,city:string,display:string,owners:list<array<string,mixed>>}
 */
function jeab_contacts_sole_recipient(array $row): array
{
    $copy = jeab_contacts_sole_recipient_copy($row);
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact)) {
        return $copy;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);
    $strict = jeab_contacts_strict();
    foreach (['name', 'email', 'phone', 'street', 'postal', 'city', 'display'] as $key) {
        $value = trim((string) ($view[$key] ?? ''));
        if ($strict || $value !== '') {
            $copy[$key] = $value;
        }
    }
    if ($strict && function_exists('jeab_contacts_strict_owners')) {
        $copy['owners'] = jeab_contacts_strict_owners($contact);
    } else {
        $owners = jeab_contacts_sole_recipient_owners_from($row, $contact);
        if ($owners !== null) {
            $copy['owners'] = $owners;
        }
    }

    return $copy;
}

/**
 * Kopia med kontaktens mottagarfält. Flaggan av, saknad kontakt eller blank
 * kontakt lämnar samma rad. Skriver inte tillbaka till ordern eller bokningen.
 * Personnummer, produkt, nätägare, fastighet och pris rörs inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_recipient_apply(array $row): array
{
    if (!jeab_contacts_sole()) {
        return $row;
    }
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact)) {
        return $row;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);
    $rec = jeab_contacts_sole_recipient($row);
    $out = $row;
    $changed = false;
    $strict = jeab_contacts_strict();
    if ($strict || trim((string) ($view['name'] ?? '')) !== '') {
        $out['name'] = $rec['name'];
        $out['huvudsokande'] = $rec['name'];
        $out['customer_name'] = $rec['name'];
        $changed = true;
    }
    if ($strict || trim((string) ($view['email'] ?? '')) !== '') {
        $out['email'] = $rec['email'];
        $changed = true;
    }
    if ($strict || trim((string) ($view['phone'] ?? '')) !== '') {
        $out['phone'] = $rec['phone'];
        $changed = true;
    }
    if ($strict || trim((string) ($view['street'] ?? '')) !== '') {
        $out['gata'] = $rec['street'];
        $out['street'] = $rec['street'];
        $changed = true;
    }
    if ($strict || trim((string) ($view['city'] ?? '')) !== '') {
        $out['ort'] = $rec['city'];
        $out['city'] = $rec['city'];
        $changed = true;
    }
    if ($strict || trim((string) ($view['postal'] ?? '')) !== '') {
        $out['postnummer'] = $rec['postal'];
        $out['postal'] = $rec['postal'];
        $fmt = trim((string) ($view['postal_fmt'] ?? ''));
        $out['postnr'] = $fmt !== '' ? $fmt : $rec['postal'];
        $changed = true;
    }
    if ($strict || trim((string) ($view['display'] ?? '')) !== '') {
        $out['address'] = $rec['display'];
        $out['huvudsokande_address'] = $rec['display'];
        $changed = true;
    }
    $ambiguous = false;
    if ($strict && function_exists('jeab_contacts_strict_owners')) {
        $owners = jeab_contacts_strict_owners($contact);
        $ambiguous = false;
    } else {
        $owners = jeab_contacts_sole_recipient_owners_from($row, $contact, $ambiguous);
    }
    if ($strict || ($owners !== null && $owners !== [])) {
        $owners = is_array($owners) ? $owners : [];
        $huvud = trim((string) ($out['huvudsokande'] ?? $row['huvudsokande'] ?? $row['customer_name'] ?? $row['name'] ?? ''));
        $out['owners'] = $owners;
        $out['medsokande'] = jeab_contacts_sole_recipient_med_owners($owners, $huvud);
        $changed = true;
    }
    if ($ambiguous) {
        $out['pnr_name_ambiguous'] = true;
        $changed = true;
    }

    return $changed ? $out : $row;
}

/**
 * Kontaktpersonens personnummer i visningsform. Tomt när ingen ägare har ett.
 * Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $contact
 */
function jeab_contacts_sole_contact_person_pnr(array $contact): string
{
    $owners = is_array($contact['owners'] ?? null) ? $contact['owners'] : [];
    $picked = null;
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (!empty($owner['contact'])) {
            $picked = $owner;
            break;
        }
        if ($picked === null && trim((string) ($owner['name'] ?? '')) !== '') {
            $picked = $owner;
        }
    }
    if (!is_array($picked)) {
        return '';
    }

    return jeab_contacts_sole_pnr_text((string) ($picked['personnummer'] ?? ''));
}

/**
 * Ägarens personnummer när namnet finns på exakt en person. Annars tomt.
 *
 * @param array<string, mixed> $contact
 */
function jeab_contacts_sole_contact_owner_pnr(array $contact, string $name): string
{
    $nameKey = jeab_contacts_sole_name_key($name);
    if ($nameKey === '') {
        return '';
    }
    $hits = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (jeab_contacts_sole_name_key((string) ($owner['name'] ?? '')) === $nameKey) {
            $hits[] = $owner;
        }
    }
    if (count($hits) !== 1) {
        return '';
    }

    return jeab_contacts_sole_pnr_text((string) ($hits[0]['personnummer'] ?? ''));
}

/**
 * Kontaktens personnummer när flaggan är på och kontakten har ett. Annars tomt,
 * så anroparen behåller sin nuvarande källa.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_sole_preferred_pnr(array $row): string
{
    if (!jeab_contacts_sole()) {
        return '';
    }
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact)) {
        return '';
    }

    return jeab_contacts_sole_contact_person_pnr($contact);
}

/**
 * @param array<string, mixed> $row
 */
function jeab_contacts_sole_preferred_owner_pnr(array $row, string $name): string
{
    if (!jeab_contacts_sole()) {
        return '';
    }
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact)) {
        return '';
    }

    return jeab_contacts_sole_contact_owner_pnr($contact, $name);
}

/**
 * Kontaktens fastighetsbeteckning när flaggan är på och fältet är ifyllt.
 *
 * @param array<string, mixed> $row
 */
function jeab_contacts_sole_preferred_fastighet(array $row): string
{
    if (!jeab_contacts_sole()) {
        return '';
    }
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact)) {
        return '';
    }

    return trim((string) ($contact['fastighetsbeteckning'] ?? ''));
}

/**
 * Skriver ändrade kundfält till kontakten. När kontakten finns lämnas
 * namn, e-post, telefon och adress kvar bara som contact_id på ordern.
 * Personnummer och Fortnox-fält lämnas.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array<string, mixed>
 */
function jeab_contacts_sole_divert_order(array $before, array $after): array
{
    if (!jeab_contacts_sole() || !function_exists('jeab_reminder_save_contact_customer') || !function_exists('jeab_kontakt_get')) {
        return $after;
    }
    $id = trim((string) ($before['contact_id'] ?? $after['contact_id'] ?? ''));
    $contact = $id !== '' ? jeab_kontakt_get($id) : null;
    if (!is_array($contact)) {
        return $after;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);
    $patch = [];
    $name = trim((string) ($after['huvudsokande'] ?? $after['name'] ?? ''));
    $beforeName = trim((string) ($before['huvudsokande'] ?? $before['name'] ?? ''));
    if ($name !== '' && $name !== $beforeName && $name !== $view['name']) {
        $patch['name'] = $name;
    }
    $email = trim((string) ($after['email'] ?? ''));
    $beforeEmail = trim((string) ($before['email'] ?? ''));
    if ($email !== $beforeEmail && strcasecmp($email, $view['email']) !== 0) {
        $patch['email'] = $email;
    }
    $phone = trim((string) ($after['phone'] ?? ''));
    $beforePhone = trim((string) ($before['phone'] ?? ''));
    if ($phone !== $beforePhone && $phone !== $view['phone']) {
        $patch['phone'] = $phone;
    }
    $address = trim((string) ($after['address'] ?? ''));
    $beforeAddress = trim((string) ($before['address'] ?? ''));
    $gata = trim((string) ($after['gata'] ?? ''));
    $beforeGata = trim((string) ($before['gata'] ?? ''));
    if ($address !== $beforeAddress || $gata !== $beforeGata || trim((string) ($after['ort'] ?? '')) !== trim((string) ($before['ort'] ?? ''))) {
        $source = $gata !== '' ? $gata : $address;
        $parsed = function_exists('jeab_address_parts') ? jeab_address_parts($source !== '' ? $source : $address) : ['street' => $source, 'city' => '', 'postal' => ''];
        $street = trim((string) ($parsed['street'] ?? ''));
        if ($street === '') {
            $street = $gata !== '' ? $gata : $address;
        }
        $city = trim((string) ($after['ort'] ?? ''));
        if ($city === '') {
            $city = trim((string) ($parsed['city'] ?? ''));
        }
        $postal = trim((string) ($after['postnummer'] ?? $after['postnr'] ?? ''));
        if ($postal === '') {
            $postal = trim((string) ($parsed['postal'] ?? ''));
        }
        if ($street !== '' && $street !== $view['street']) {
            $patch['street'] = $street;
        }
        if ($city !== '' && strcasecmp($city, $view['city']) !== 0) {
            $patch['city'] = $city;
        }
        $postalNorm = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postal) : $postal;
        if ($postalNorm !== '' && $postalNorm !== $view['postal']) {
            $patch['postal'] = $postalNorm;
        }
    }
    if ($patch !== []) {
        jeab_reminder_save_contact_customer($id, $patch);
    }

    return jeab_contacts_sole_order_strip_customer($after);
}

function jeab_contacts_sole_view_has_customer(array $view): bool
{
    foreach (['email', 'phone', 'street', 'city', 'postal'] as $key) {
        if (trim((string) ($view[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Första kontakten som har e-post, telefon eller adress. En namnrad utan de
 * fälten behålls bara när ingen senare kandidat har dem.
 *
 * @param list<string> $ids
 * @param array{emails?:list<string>,phones?:list<string>,internal_sender?:bool} $signals
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_inbox_accept_contact(array $ids, bool $internal, array $signals): ?array
{
    $hollow = null;
    foreach ($ids as $id) {
        $id = trim($id);
        if ($id === '' || !function_exists('jeab_kontakt_get')) {
            continue;
        }
        $found = jeab_kontakt_get($id);
        if (!is_array($found)) {
            continue;
        }
        if (function_exists('jeab_contacts_inbox_contact_is_internal') && jeab_contacts_inbox_contact_is_internal($found)) {
            continue;
        }
        if ($internal && function_exists('jeab_contacts_inbox_contact_matches') && !jeab_contacts_inbox_contact_matches($found, $signals)) {
            continue;
        }
        $view = jeab_contacts_sole_view_from_contact($found);
        if (jeab_contacts_sole_view_has_customer($view)) {
            return $found;
        }
        if ($hollow === null) {
            $hollow = $found;
        }
    }

    return $hollow;
}

/**
 * contact_id på Help Scout-arkivet för samma konversation. Läser bara.
 *
 * @param array<string, mixed> $thread
 * @return list<string>
 */
function jeab_contacts_sole_inbox_import_contact_ids(array $thread): array
{
    if (!function_exists('jeab_hs_import_index') || !function_exists('jeab_hs_import_thread_key')) {
        return [];
    }
    $want = [];
    $keys = [];
    $addDigits = static function (string $raw) use (&$want, &$keys): void {
        if (preg_match('/^(?:hs-|#)?(\d+)$/i', trim($raw), $m) !== 1) {
            return;
        }
        $want[$m[1]] = true;
        $keys[jeab_hs_import_thread_key($m[1])] = true;
    };
    $addDigits((string) ($thread['id'] ?? ''));
    $hsi = trim((string) ($thread['hsi_id'] ?? ''));
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $hsi) === 1) {
        $keys[$hsi] = true;
    }
    $hs = is_array($thread['hs'] ?? null) ? $thread['hs'] : [];
    $addDigits((string) ($hs['id'] ?? ''));
    $addDigits((string) ($hs['number'] ?? ''));
    $addDigits((string) ($thread['hs_conversation_id'] ?? ''));
    if ($keys === [] && $want === []) {
        return [];
    }
    $direct = [];
    if (function_exists('jeab_hs_import_read_conv')) {
        foreach (array_keys($keys) as $key) {
            $conv = jeab_hs_import_read_conv($key);
            if (!is_array($conv)) {
                continue;
            }
            $cid = trim((string) ($conv['contact_id'] ?? ''));
            if ($cid === '' && is_array($conv['meta'] ?? null)) {
                $cid = trim((string) ($conv['meta']['contact_id'] ?? ''));
            }
            if ($cid !== '' && !in_array($cid, $direct, true)) {
                $direct[] = $cid;
            }
        }
    }
    $picked = jeab_contacts_sole_inbox_accept_contact($direct, false, ['emails' => [], 'phones' => []]);
    if (is_array($picked) && jeab_contacts_sole_view_has_customer(jeab_contacts_sole_view_from_contact($picked))) {
        return $direct;
    }
    $index = jeab_hs_import_index();
    $byId = is_array($index['by_id'] ?? null) ? $index['by_id'] : [];
    $ids = [];
    $push = static function (string $id) use (&$ids): void {
        $id = trim($id);
        if ($id !== '' && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    };
    foreach (array_keys($keys) as $key) {
        $row = $byId[$key] ?? null;
        if (is_array($row)) {
            $push((string) ($row['contact_id'] ?? ''));
        }
    }
    foreach ($byId as $key => $row) {
        if (!is_array($row) || isset($keys[(string) $key])) {
            continue;
        }
        $convId = trim((string) ($row['hs_conversation_id'] ?? ''));
        $number = trim((string) ($row['hs_number'] ?? ''));
        if (($convId !== '' && isset($want[$convId])) || ($number !== '' && isset($want[$number]))) {
            $push((string) ($row['contact_id'] ?? ''));
        }
    }

    return $ids;
}

function jeab_contacts_sole_inbox_reminder_contact_id(array $thread): string
{
    $tid = trim((string) ($thread['id'] ?? ''));
    if ($tid === '' || !function_exists('jeab_contacts_inbox_reminder_contacts')) {
        return '';
    }
    $map = jeab_contacts_inbox_reminder_contacts([$tid]);

    return trim((string) ($map[$tid] ?? ''));
}

/**
 * @param array<string, mixed> $thread
 * @return array{linked:bool,id:string,name:string,email:string,phone:string,street:string,postal:string,postal_fmt:string,city:string,display:string}
 */
function jeab_contacts_sole_inbox_view(array $thread): array
{
    $view = jeab_contacts_sole_empty_view();
    if (!jeab_contacts_sole() || !function_exists('jeab_kontakt_get')) {
        return $view;
    }
    $signals = function_exists('jeab_contacts_inbox_thread_signals')
        ? jeab_contacts_inbox_thread_signals($thread)
        : ['emails' => [], 'phones' => [], 'internal_sender' => false];
    $internal = !empty($signals['internal_sender']);
    $contact = null;
    $candidates = [];
    $threadId = trim((string) ($thread['contact_id'] ?? ''));
    if ($threadId !== '') {
        $candidates[] = $threadId;
    }
    if ($threadId !== '' && function_exists('jeab_inkorg_contact_record')) {
        $inbox = jeab_inkorg_contact_record($threadId);
        if (is_array($inbox)) {
            $central = trim((string) ($inbox['contact_id'] ?? ''));
            if ($central !== '' && !in_array($central, $candidates, true)) {
                $candidates[] = $central;
            }
        }
    }
    $found = jeab_contacts_sole_inbox_accept_contact($candidates, $internal, $signals);
    if (is_array($found)) {
        $contact = $found;
        $view = jeab_contacts_sole_view_from_contact($found);
    }
    if (!jeab_contacts_sole_view_has_customer($view)) {
        $extra = jeab_contacts_sole_inbox_import_contact_ids($thread);
        if ($threadId === '') {
            $remId = jeab_contacts_sole_inbox_reminder_contact_id($thread);
            if ($remId !== '') {
                $extra[] = $remId;
            }
        }
        $better = jeab_contacts_sole_inbox_accept_contact($extra, $internal, $signals);
        if (is_array($better)) {
            $betterView = jeab_contacts_sole_view_from_contact($better);
            if (jeab_contacts_sole_view_has_customer($betterView)) {
                $contact = $better;
                $view = $betterView;
            }
        }
    }
    if (!is_array($contact) && $internal && function_exists('jeab_contacts_inbox_pick')) {
        $byEmail = function_exists('jeab_contacts_inbox_by_email')
            ? jeab_contacts_inbox_by_email($signals['emails'])
            : [];
        $byPhone = function_exists('jeab_contacts_inbox_by_phone')
            ? jeab_contacts_inbox_by_phone($signals['phones'])
            : [];
        $picked = jeab_contacts_inbox_pick(null, $thread, $byEmail, $byPhone);
        if (is_array($picked)) {
            $contact = $picked;
            $view = jeab_contacts_sole_view_from_contact($picked);
        }
    }
    if (!is_array($contact) && $internal) {
        return jeab_contacts_sole_empty_view();
    }
    if (is_array($contact) && $internal) {
        return $view;
    }
    if (is_array($contact) && jeab_contacts_sole_name_is_real((string) $view['name'], jeab_contacts_sole_contact_emails($contact))) {
        return $view;
    }
    if (!empty($GLOBALS['_JEAB_CONTACTS_SOLE_INBOX_VIEW'])) {
        return $view;
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_INBOX_VIEW'] = true;
    try {
        $stored = [];
        $emails = [(string) $view['email']];
        $stored[] = trim((string) ($thread['customer_name'] ?? ''));
        $emails[] = trim((string) ($thread['customer_email'] ?? ''));
        $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
        $stored[] = trim((string) ($lead['name'] ?? ''));
        $emails[] = trim((string) ($lead['email'] ?? ''));
        if (function_exists('jeab_inkorg_party')) {
            $party = jeab_inkorg_party($thread);
            $stored[] = trim((string) ($party['name'] ?? ''));
            $emails[] = trim((string) ($party['email'] ?? ''));
        }
        $rem = function_exists('jeab_inkorg_thread_reminder') ? jeab_inkorg_thread_reminder($thread) : null;
        if (is_array($rem)) {
            foreach (jeab_contacts_sole_row_stored_names($rem) as $name) {
                $stored[] = $name;
            }
            foreach (jeab_contacts_sole_row_emails($rem) as $email) {
                $emails[] = $email;
            }
            if (function_exists('jeab_purchase_get')) {
                $order = jeab_purchase_get((int) ($rem['id'] ?? 0));
                if (is_array($order)) {
                    foreach (jeab_contacts_sole_row_stored_names($order) as $name) {
                        $stored[] = $name;
                    }
                    foreach (jeab_contacts_sole_row_emails($order) as $email) {
                        $emails[] = $email;
                    }
                }
            }
        }
        $view['name'] = jeab_contacts_sole_resolve_display_name(
            $contact,
            (string) $view['name'],
            $stored,
            $emails,
            true,
            (string) $view['id']
        );

        return $view;
    } finally {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_INBOX_VIEW']);
    }
}

/**
 * @param array<string, mixed> $thread
 * @return array<string, mixed>
 */
function jeab_contacts_sole_inbox_panel(array $thread): array
{
    $view = jeab_contacts_sole_inbox_view($thread);
    $internal = function_exists('jeab_contacts_inbox_thread_signals')
        && !empty(jeab_contacts_inbox_thread_signals($thread)['internal_sender']);
    if ($internal && $view['id'] === '') {
        return [
            'id' => '',
            'contact_id' => '',
            'sole' => true,
            'name' => '',
            'emails' => [],
            'mobile' => '',
            'address' => '',
            'town' => '',
            'notes' => '',
        ];
    }
    $notes = '';
    $inboxId = trim((string) ($thread['contact_id'] ?? ''));
    if ($inboxId !== '' && function_exists('jeab_inkorg_contact_record')) {
        $inbox = jeab_inkorg_contact_record($inboxId);
        if (is_array($inbox)) {
            $notes = trim((string) ($inbox['notes'] ?? ''));
            if ($view['id'] === '' && trim((string) ($inbox['contact_id'] ?? '')) !== '') {
                $central = jeab_kontakt_get(trim((string) $inbox['contact_id']));
                if (is_array($central)) {
                    $fresh = jeab_contacts_sole_view_from_contact($central);
                    $fresh['name'] = jeab_contacts_sole_resolve_display_name(
                        $central,
                        (string) $fresh['name'],
                        [(string) $view['name']],
                        [(string) $fresh['email'], (string) $view['email']],
                        true,
                        (string) $fresh['id']
                    );
                    $view = $fresh;
                }
            }
        }
    }
    if ($internal && $view['id'] !== '' && $inboxId !== $view['id']) {
        $notes = '';
        $inboxId = $view['id'];
    }

    return [
        'id' => $inboxId,
        'contact_id' => $view['id'],
        'sole' => true,
        'name' => $view['name'],
        'emails' => $view['email'] !== '' ? [$view['email']] : [],
        'mobile' => $view['phone'],
        'address' => $view['street'],
        'town' => $view['city'],
        'notes' => $notes,
    ];
}

/**
 * @param array<string, mixed> $saved
 * @param array<string, mixed> $input
 */
function jeab_contacts_sole_sync_inbox_save(array $saved, array $input): void
{
    if (!jeab_contacts_sole() || !function_exists('jeab_reminder_save_contact_customer') || !function_exists('jeab_kontakt_get')) {
        return;
    }
    $id = trim((string) ($saved['contact_id'] ?? $input['contact_id'] ?? ''));
    if ($id === '' || !is_array(jeab_kontakt_get($id))) {
        return;
    }
    $emails = is_array($saved['emails'] ?? null) ? $saved['emails'] : [];
    $email = trim((string) ($emails[0] ?? ''));
    jeab_reminder_save_contact_customer($id, [
        'name' => trim((string) ($saved['name'] ?? '')),
        'email' => $email,
        'phone' => trim((string) ($saved['mobile'] ?? '')),
        'street' => trim((string) ($saved['address'] ?? '')),
        'city' => trim((string) ($saved['town'] ?? '')),
    ]);
}

/**
 * @return array{reminders:int,orders:int,bookings:int}
 */
function jeab_contacts_sole_counts(JeabStore $store): array
{
    $orders = function_exists('jeab_purchase_all') ? jeab_purchase_all() : [];

    return [
        'reminders' => count($store->listReminders(true)),
        'orders' => is_array($orders) ? count($orders) : 0,
        'bookings' => count($store->listAllBookings()),
    ];
}

/**
 * @return array{reminders:list<int>,orders:list<int>,bookings:list<int>}
 */
function jeab_contacts_sole_ids(JeabStore $store): array
{
    $reminders = [];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $reminders[] = (int) ($row['id'] ?? 0);
        }
    }
    $orders = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $orders[] = (int) ($row['reminder_id'] ?? 0);
            }
        }
    }
    $bookings = [];
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $bookings[] = (int) ($row['id'] ?? 0);
        }
    }
    sort($reminders);
    sort($orders);
    sort($bookings);

    return ['reminders' => $reminders, 'orders' => $orders, 'bookings' => $bookings];
}

/**
 * @return array{reminders:int,orders:int,bookings:int}
 */
function jeab_contacts_sole_missing(JeabStore $store): array
{
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $missing = ['reminders' => 0, 'orders' => 0, 'bookings' => 0];
    foreach ($store->listReminders(true) as $row) {
        if (!is_array($row) || !jeab_contacts_sole_row_linked($row, $contacts)) {
            $missing['reminders']++;
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row) || !jeab_contacts_sole_row_linked($row, $contacts)) {
                $missing['orders']++;
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (!is_array($row) || !jeab_contacts_sole_row_linked($row, $contacts)) {
            $missing['bookings']++;
        }
    }

    return $missing;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $contacts
 */
function jeab_contacts_sole_row_linked(array $row, array $contacts): bool
{
    $id = trim((string) ($row['contact_id'] ?? ''));

    return $id !== '' && isset($contacts[$id]) && is_array($contacts[$id]);
}

/**
 * Läser före-bilden som migreringen sparade. Utan den är svaret ok:false.
 *
 * @return array<string, mixed>
 */
function jeab_contacts_sole_verify(JeabStore $store): array
{
    $counts = jeab_contacts_sole_counts($store);
    $ids = jeab_contacts_sole_ids($store);
    $missing = jeab_contacts_sole_missing($store);
    $empty = jeab_contacts_sole_empty($store);
    $extra = jeab_contacts_sole_verify_extra($store);
    $snap = jeab_contacts_sole_snapshot();
    if ($snap === null) {
        return [
            'ok' => false,
            'skipped' => false,
            'match' => false,
            'counts' => ['before' => null, 'after' => $counts],
            'missing' => $missing,
            'empty' => $empty,
            'ids_same' => false,
            'links_same' => false,
            'error' => 'Före-ögonblicksbilden saknas.',
        ] + $extra;
    }
    $beforeCounts = is_array($snap['counts'] ?? null) ? $snap['counts'] : [];
    $beforeIds = is_array($snap['ids'] ?? null) ? $snap['ids'] : [];
    $beforeLinks = is_array($snap['links'] ?? null) ? $snap['links'] : [];
    $sameCounts = (int) ($beforeCounts['reminders'] ?? -1) === $counts['reminders']
        && (int) ($beforeCounts['orders'] ?? -1) === $counts['orders']
        && (int) ($beforeCounts['bookings'] ?? -1) === $counts['bookings'];
    $sameIds = $beforeIds === $ids;
    $sameLinks = jeab_contacts_sole_links_kept($store, $beforeLinks);
    $filled = $empty === ['reminders' => 0, 'orders' => 0, 'bookings' => 0];
    $ok = $sameCounts && $sameIds && $sameLinks && $filled;

    return [
        'ok' => $ok,
        'skipped' => false,
        'match' => $ok,
        'counts' => ['before' => $beforeCounts, 'after' => $counts],
        'missing' => $missing,
        'empty' => $empty,
        'ids_same' => $sameIds,
        'links_same' => $sameLinks,
        'error' => $ok ? '' : 'Kontrollen av contact_id eller antalet rader stoppades.',
    ] + $extra;
}

/**
 * Räknare utan värden. inflow_errors är misslyckade inflödesstämplingar.
 * property_would_create är nya kontakter som divert_property skulle skapa.
 * pnr_name_ambiguous är ordrar där tomt personnummer eller tom adress
 * inte kan fyllas från exakt ett normaliserat namn.
 * paint_conflicts är per fält antal ordrar där kontakt och kopia skiljer sig (S0-1).
 * legacy_json_writes är env-värdet och det effektiva läget. Bara
 * LEGACY_JSON_WRITES styr, och standard är på.
 *
 * @return array{inflow_errors:int,property_would_create:array{orders:int,bookings:int,contacts:int},pnr_name_ambiguous:int,paint_conflicts:array{orders:int,fields:array<string,int>},legacy_json_writes:array{env:?string,effective:bool}}
 */
function jeab_contacts_sole_verify_extra(JeabStore $store): array
{
    $flag = function_exists('jeab_legacy_json_writes_state')
        ? jeab_legacy_json_writes_state()
        : ['env' => null, 'effective' => true];

    return [
        'inflow_errors' => jeab_contacts_sole_inflow_error_count(),
        'property_would_create' => jeab_contacts_sole_property_would_create($store),
        'pnr_name_ambiguous' => jeab_contacts_sole_pnr_name_ambiguous_count($store),
        'paint_conflicts' => jeab_contacts_sole_paint_conflicts($store),
        'legacy_json_writes' => $flag,
        'gaps' => function_exists('jeab_contacts_gap_summary') ? jeab_contacts_gap_summary($store) : ['reminders' => 0, 'bookings' => 0, 'orders' => 0, 'rows' => 0, 'fields' => []],
    ];
}

/**
 * S0-1: båda sidor har värde men skiljer sig. Tomt kontaktfält är ingen konflikt;
 * orderns visning lämnar det tomt och loggar luckan.
 * Bara antal, inga värden. Jämförelsen normaliserar skiftläge, mellanslag och siffror.
 *
 * @return array{orders:int,fields:array<string,int>}
 */
function jeab_contacts_sole_paint_conflicts(JeabStore $store): array
{
    $fields = ['email' => 0, 'phone' => 0, 'address' => 0, 'gata' => 0, 'ort' => 0, 'postnummer' => 0];
    $orders = 0;
    if (!function_exists('jeab_purchase_all')) {
        return ['orders' => 0, 'fields' => $fields];
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['contact_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $contact = jeab_contacts_sole_linked_contact($id);
        if (!is_array($contact)) {
            continue;
        }
        $hit = jeab_contacts_sole_paint_conflict_fields($row, jeab_contacts_sole_view_from_contact($contact));
        foreach ($hit as $f) {
            $fields[$f]++;
        }
        if ($hit !== []) {
            $orders++;
        }
    }

    return ['orders' => $orders, 'fields' => $fields];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $view
 * @return list<string>
 */
function jeab_contacts_sole_paint_conflict_fields(array $row, array $view): array
{
    $pairs = [
        'email' => [(string) ($row['email'] ?? ''), (string) ($view['email'] ?? ''), 'text'],
        'phone' => [(string) ($row['phone'] ?? ''), (string) ($view['phone'] ?? ''), 'digits'],
        'gata' => [(string) ($row['gata'] ?? ''), (string) ($view['street'] ?? ''), 'text'],
        'ort' => [(string) ($row['ort'] ?? ''), (string) ($view['city'] ?? ''), 'text'],
        'postnummer' => [(string) ($row['postnummer'] ?? $row['postnr'] ?? ''), (string) ($view['postal'] ?? ''), 'digits'],
    ];
    $out = [];
    foreach ($pairs as $field => [$copy, $live, $mode]) {
        $a = jeab_contacts_sole_paint_cmp($copy, $mode);
        $b = jeab_contacts_sole_paint_cmp($live, $mode);
        if ($a !== '' && $b !== '' && $a !== $b) {
            $out[] = $field;
        }
    }
    if (jeab_contacts_sole_paint_address_conflict((string) ($row['address'] ?? ''), $view)) {
        $out[] = 'address';
    }
    $order = ['email' => 0, 'phone' => 1, 'address' => 2, 'gata' => 3, 'ort' => 4, 'postnummer' => 5];
    usort($out, static function (string $a, string $b) use ($order): int {
        return ($order[$a] ?? 9) <=> ($order[$b] ?? 9);
    });

    return $out;
}

/** Ort som visningen: jeab_address_town, och jeab_kontakt_city_name när den är tom. */
function jeab_contacts_sole_paint_town(string $raw): string
{
    $town = function_exists('jeab_address_town') ? jeab_address_town($raw) : trim($raw);
    if ($town === '' && function_exists('jeab_kontakt_city_name')) {
        $town = jeab_kontakt_city_name($raw);
    }

    return $town;
}

/**
 * Trim, skiftläge, mellanslag, postnummer som siffror, ort som visningen.
 *
 * @return array{street:string,postal:string,town:string}
 */
function jeab_contacts_sole_paint_address_norm(string $street, string $postal, string $town): array
{
    $digits = preg_replace('/\D+/', '', $postal) ?? '';

    return [
        'street' => jeab_contacts_sole_paint_cmp($street, 'text'),
        'postal' => $digits,
        'town' => jeab_contacts_sole_paint_cmp(jeab_contacts_sole_paint_town($town), 'text'),
    ];
}

/**
 * Delar som går att läsa ur orderns adresskopia. Tomma delar saknas.
 *
 * @return array{street:string,postal:string,town:string}
 */
function jeab_contacts_sole_paint_address_order_parts(string $raw): array
{
    $parsed = function_exists('jeab_kontakt_parse_address')
        ? jeab_kontakt_parse_address($raw)
        : ['street' => trim($raw), 'postal' => '', 'city' => ''];
    $street = trim((string) ($parsed['street'] ?? ''));
    $postal = preg_replace('/\D+/', '', (string) ($parsed['postal'] ?? '')) ?? '';
    $town = jeab_contacts_sole_paint_town((string) ($parsed['city'] ?? ''));
    $norm = jeab_contacts_sole_paint_address_norm($street, $postal, $town);

    return [
        'street' => $norm['street'] === '' ? '' : $street,
        'postal' => $norm['postal'] === '' ? '' : $postal,
        'town' => $norm['town'] === '' ? '' : $town,
    ];
}

/**
 * Orderns adress mot kontaktens visning. Delar ordern saknar jämförs inte.
 *
 * @param array<string, mixed> $view
 */
function jeab_contacts_sole_paint_address_conflict(string $orderAddress, array $view): bool
{
    $parts = jeab_contacts_sole_paint_address_order_parts($orderAddress);
    $order = jeab_contacts_sole_paint_address_norm($parts['street'], $parts['postal'], $parts['town']);
    $live = jeab_contacts_sole_paint_address_norm(
        (string) ($view['street'] ?? ''),
        (string) ($view['postal'] ?? ''),
        (string) ($view['city'] ?? '')
    );
    foreach (['street', 'postal', 'town'] as $part) {
        if ($order[$part] === '') {
            continue;
        }
        if ($order[$part] !== $live[$part]) {
            return true;
        }
    }

    return false;
}

function jeab_contacts_sole_paint_cmp(string $value, string $mode): string
{
    if ($mode === 'digits') {
        $d = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($d, '46') && strlen($d) >= 11) {
            $d = '0' . substr($d, 2);
        }

        return $d;
    }

    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
}

/** Antal ordrar som skulle flaggas. Bara räknaren, inga värden. */
function jeab_contacts_sole_pnr_name_ambiguous_count(JeabStore $store): int
{
    $n = 0;
    if (!function_exists('jeab_purchase_all')) {
        return 0;
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (jeab_contacts_sole_row_pnr_name_ambiguous($row)) {
            $n++;
        }
    }

    return $n;
}

/**
 * Läser ordrar som sole_verify räknar som pnr_name_ambiguous.
 * Samma loop och samma villkor som jeab_contacts_sole_pnr_name_ambiguous_count.
 * Varje rad har order-id, kontakt, gata, postnummer, ort, ägarnamn med index,
 * om personnummer eller adress saknas, Fortnox-kundnummer som ligger på
 * ordern eller kontakten, och reason med orsak per olöst ägarplats.
 * Själva numret lämnas inte, inte maskat, och loggas inte.
 * dry ignoreras. Funktionen skriver aldrig.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_pnr_ambiguous_list(array $input, ?JeabStore $store = null): array
{
    unset($input);
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false, 'count' => 0, 'rows' => []];
    }
    $rows = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!jeab_contacts_sole_row_pnr_name_ambiguous($row)) {
                continue;
            }
            $rows[] = jeab_contacts_sole_pnr_ambiguous_list_row($row);
        }
    }
    usort($rows, static function (array $a, array $b): int {
        $byId = ((int) ($a['reminder_id'] ?? 0)) <=> ((int) ($b['reminder_id'] ?? 0));
        if ($byId !== 0) {
            return $byId;
        }

        return strcmp((string) ($a['contact_id'] ?? ''), (string) ($b['contact_id'] ?? ''));
    });

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => true,
        'written' => false,
        'count' => count($rows),
        'rows' => $rows,
    ]);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_pnr_ambiguous_list_row(array $row): array
{
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = $contactId !== '' ? jeab_contacts_sole_linked_contact($contactId) : null;
    $view = is_array($contact) ? jeab_contacts_sole_view_from_contact($contact) : [];
    $owners = is_array($contact) ? jeab_contacts_sole_pnr_list_owners($contact) : [
        'owners' => [],
        'pnr_missing' => false,
        'address_missing' => false,
    ];
    $reasons = [];
    if (is_array($contact)) {
        $flag = false;
        jeab_contacts_sole_recipient_owners_from($row, $contact, $flag, $reasons);
    }
    $byIndex = [];
    $safeReasons = [];
    foreach ($reasons as $item) {
        if (!is_array($item)) {
            continue;
        }
        $idx = (int) ($item['index'] ?? -1);
        $safe = jeab_contacts_sole_pnr_list_text((string) ($item['reason'] ?? ''));
        if ($safe === '') {
            $safe = 'Orsaken saknas.';
        }
        $byIndex[$idx] = $safe;
        $safeReasons[] = ['index' => $idx, 'reason' => $safe];
    }
    $reasons = $safeReasons;
    foreach ($owners['owners'] as $n => $ownerRow) {
        if (!is_array($ownerRow)) {
            continue;
        }
        $idx = (int) ($ownerRow['index'] ?? -1);
        $owners['owners'][$n]['reason'] = $byIndex[$idx] ?? '';
    }
    $reminderId = (int) ($row['reminder_id'] ?? 0);
    $fortnox = [
        'order' => jeab_contacts_sole_pnr_list_fortnox($row),
        'contact' => is_array($contact) ? jeab_contacts_sole_pnr_list_fortnox($contact) : [],
    ];

    return [
        'reminder_id' => $reminderId,
        'order_id' => $reminderId,
        'contact_id' => $contactId,
        'street' => jeab_contacts_sole_pnr_list_text((string) ($view['street'] ?? '')),
        'postal_code' => jeab_contacts_sole_pnr_list_text((string) ($view['postal'] ?? '')),
        'city' => jeab_contacts_sole_pnr_list_text((string) ($view['city'] ?? '')),
        'owners' => $owners['owners'],
        'pnr_missing' => $owners['pnr_missing'],
        'address_missing' => $owners['address_missing'],
        'reason' => $reasons,
        'fortnox_customer' => $fortnox,
    ];
}

/**
 * Ägarens lagrade lucka. Tomt personnummer och tom adress är booleska.
 * Numret själv kopieras inte.
 *
 * @param array<string, mixed> $contact
 * @return array{owners:list<array{index:int,name:string,pnr_missing:bool,address_missing:bool,reason?:string}>,pnr_missing:bool,address_missing:bool}
 */
function jeab_contacts_sole_pnr_list_owners(array $contact): array
{
    $raw = $contact['owners'] ?? null;
    $owners = [];
    $pnrMissing = false;
    $addressMissing = false;
    if (!is_array($raw)) {
        return ['owners' => [], 'pnr_missing' => false, 'address_missing' => false];
    }
    foreach ($raw as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $pnr = jeab_contacts_sole_pnr_text((string) ($owner['personnummer'] ?? ''));
        $address = trim((string) ($owner['address'] ?? $owner['adress'] ?? ''));
        $missPnr = $pnr === '';
        $missAddress = $address === '';
        if ($missPnr) {
            $pnrMissing = true;
        }
        if ($missAddress) {
            $addressMissing = true;
        }
        $owners[] = [
            'index' => (int) $i,
            'name' => jeab_contacts_sole_pnr_list_text((string) ($owner['name'] ?? '')),
            'pnr_missing' => $missPnr,
            'address_missing' => $missAddress,
        ];
    }

    return [
        'owners' => $owners,
        'pnr_missing' => $pnrMissing,
        'address_missing' => $addressMissing,
    ];
}

/**
 * Kundnummer som ligger på raden. Ett värde som ser ut som personnummer utelämnas.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_contacts_sole_pnr_list_fortnox(array $row): array
{
    $out = [];
    foreach (['fortnox_customer', 'fortnox_customer_number', 'fortnox_kundnummer', 'CustomerNumber'] as $key) {
        if (!array_key_exists($key, $row) || is_array($row[$key])) {
            continue;
        }
        $value = jeab_contacts_sole_pnr_list_text(trim((string) $row[$key]));
        if ($value === '' || in_array($value, $out, true)) {
            continue;
        }
        $out[] = $value;
    }

    return $out;
}

/** Tom sträng när värdet innehåller ett personnummer eller en mask. */
function jeab_contacts_sole_pnr_list_text(string $value): string
{
    $value = trim($value);
    if ($value === '' || jeab_contacts_sole_pnr_list_blocked($value)) {
        return '';
    }

    return $value;
}

function jeab_contacts_sole_pnr_list_blocked(string $value): bool
{
    if ((str_contains($value, '*') || str_contains($value, '•')) && preg_match('/\d{6}/', $value) === 1) {
        return true;
    }
    if (preg_match('/\d{12}/', $value) === 1) {
        return true;
    }
    if (preg_match('/(?<!\d)\d{10}(?!\d)/', $value) === 1) {
        return true;
    }
    if (preg_match('/(?<!\d)\d{8}\s*[-+]\s*\d{4}(?!\d)/', $value) === 1) {
        return true;
    }
    if (preg_match('/(?<!\d)\d{6}\s*[-+]\s*\d{4}(?!\d)/', $value) === 1) {
        return true;
    }

    return false;
}

/**
 * Skriver Fortnox personnummer till en ägarplats. Bara när ordern ingår i
 * pnr_name_ambiguous, contact_id är orderns kontakt och fortnox_kundnr är ett
 * kundnummer som redan ligger på ordern eller kontakten. Plats 1 eller 2.
 * Numret valideras och skrivs bara i det fältet, via jeab_kontakt_save_input.
 * Det lämnar inte svaret, varken maskat eller i logg. dry=1 skriver inte.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_pnr_fix(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    $dry = jeab_contacts_sole_wants_dry($input);
    if (!$store instanceof JeabStore) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Ingen lagring.', $dry, false);
    }
    $slot = jeab_contacts_sole_pnr_fix_slot($input['slot'] ?? null);
    if ($slot === 0) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Ägarplatsen ska vara 1 eller 2.', $dry, false);
    }
    $orderId = (int) ($input['order_id'] ?? 0);
    $contactId = trim((string) ($input['contact_id'] ?? ''));
    $kundnr = trim((string) ($input['fortnox_kundnr'] ?? ''));
    $order = jeab_contacts_sole_pnr_fix_order($orderId);
    if ($order === null) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Ordern är inte tvetydig.', $dry, false);
    }
    $orderContact = trim((string) ($order['contact_id'] ?? ''));
    if ($contactId === '' || $orderContact === '' || $contactId !== $orderContact) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Kontakten hör inte till ordern.', $dry, false);
    }
    $contact = jeab_contacts_sole_linked_contact($contactId);
    if (!is_array($contact)) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Kontakten hör inte till ordern.', $dry, false);
    }
    $storedNumbers = array_merge(
        jeab_contacts_sole_pnr_fix_fortnox_numbers($order),
        jeab_contacts_sole_pnr_fix_fortnox_numbers($contact)
    );
    $storedNumbers = array_values(array_unique($storedNumbers));
    if ($storedNumbers === []) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Fortnox-kundnummer saknas.', $dry, false);
    }
    if ($kundnr === '' || !jeab_contacts_sole_pnr_fix_same($kundnr, $storedNumbers)) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Fortnox-kundnumret stämmer inte.', $dry, false);
    }
    $index = $slot - 1;
    $owners = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $i => $owner) {
        if (is_array($owner)) {
            $owners[(int) $i] = $owner;
        }
    }
    if (!isset($owners[$index]) || !is_array($owners[$index])) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Ägarplatsen finns inte.', $dry, false);
    }
    if (jeab_contacts_sole_pnr_text((string) ($owners[$index]['personnummer'] ?? '')) !== '') {
        return jeab_contacts_sole_pnr_fix_response(false, 'Ägarplatsen har redan ett nummer.', $dry, false);
    }
    $fromFortnox = jeab_contacts_sole_pnr_fix_fetch($order, $kundnr);
    if ($fromFortnox === null) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Fortnox-kunden kunde inte läsas.', $dry, false);
    }
    $digits = function_exists('jeab_norm_pnr') ? jeab_norm_pnr($fromFortnox) : '';
    if ($digits === '') {
        return jeab_contacts_sole_pnr_fix_response(false, 'Personnumret har fel format.', $dry, false);
    }
    $stored = jeab_contacts_sole_pnr_owner_store($digits);
    if ($stored === '' || (function_exists('jeab_norm_pnr') && jeab_norm_pnr($stored) === '')) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Personnumret har fel format.', $dry, false);
    }
    if ($dry) {
        return jeab_contacts_sole_pnr_fix_response(true, '', true, true);
    }
    if (!function_exists('jeab_kontakt_save_input')) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Numret kunde inte skrivas.', false, false);
    }
    $beforeOwners = $owners;
    $audit = [
        'who' => jeab_contacts_sole_pnr_fix_who(),
        'time' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
        'order_id' => $orderId,
        'contact_id' => $contactId,
        'slot' => $slot,
        'result' => 'wrote',
    ];
    $batch = jeab_contacts_sole_batch();
    $logged = jeab_contacts_sole_undo_add($batch, 1, 'owner', 'contact', $contactId, [
        'owners' => $beforeOwners,
        'updated_at' => trim((string) ($contact['updated_at'] ?? '')),
        'audit' => $audit,
    ], [
        'owners' => $beforeOwners,
        'audit' => $audit,
    ]);
    if (!$logged) {
        return jeab_contacts_sole_pnr_fix_response(false, 'Ångerloggen kunde inte skrivas. Inget skrevs.', false, false);
    }
    if (jeab_contacts_sole()) {
        jeab_contacts_sole_pnr_digits_push();
    }
    try {
        $wrote = jeab_contacts_sole_pnr_fix_save($contactId, $contact, $owners, $slot, $stored);
    } catch (Throwable) {
        $wrote = false;
    } finally {
        if (jeab_contacts_sole()) {
            jeab_contacts_sole_pnr_digits_pop();
        }
    }
    if (!$wrote) {
        jeab_contacts_sole_undo_mark($batch);

        return jeab_contacts_sole_pnr_fix_response(false, 'Numret kunde inte skrivas.', false, false);
    }
    jeab_contacts_sole_cache_forget($contactId);

    return jeab_contacts_sole_pnr_fix_response(true, '', false, false);
}

/**
 * Skriver numret genom ägarsparningen. Plats 1 och 2. Övriga platser lämnas.
 * En mask på den andra platsen behåller det som redan ligger där.
 *
 * @param array<string, mixed> $contact
 * @param array<int, array<string, mixed>> $owners
 */
function jeab_contacts_sole_pnr_fix_save(string $contactId, array $contact, array $owners, int $slot, string $stored): bool
{
    if (!function_exists('jeab_kontakt_save_input') || $stored === '') {
        return false;
    }
    foreach ($owners as $i => $owner) {
        if (!is_array($owner) || (int) $i < 2) {
            continue;
        }
        $busy = trim((string) ($owner['name'] ?? ''))
            . trim((string) ($owner['email'] ?? ''))
            . trim((string) ($owner['phone'] ?? ''))
            . trim((string) ($owner['personnummer'] ?? ''));
        if ($busy !== '') {
            return false;
        }
    }
    $input = [
        'id' => $contactId,
        'street' => trim((string) ($contact['street'] ?? '')),
        'postal_code' => trim((string) ($contact['postal_code'] ?? '')),
        'city' => trim((string) ($contact['city'] ?? '')),
        'fastighetsbeteckning' => trim((string) ($contact['fastighetsbeteckning'] ?? '')),
        'contact_person' => '1',
    ];
    if (array_key_exists('ovrigt', $contact)) {
        $input['ovrigt'] = (string) $contact['ovrigt'];
    }
    $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($contact['company'] ?? null) : null;
    if (is_string($company) && $company !== '') {
        $input['company'] = $company;
    }
    $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '';
    $input['email'] = $email;
    $input['phone'] = $phone;
    foreach ([1, 2] as $n) {
        $owner = $owners[$n - 1] ?? null;
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $have = trim((string) ($owner['personnummer'] ?? ''));
        $posted = $n === $slot ? $stored : ($have !== '' ? '******' : '');
        if ($name === '' && $posted === '') {
            continue;
        }
        $input['owner_name_' . $n] = $name;
        $input['owner_pnr_' . $n] = $posted;
        if (!empty($owner['contact'])) {
            $input['contact_person'] = (string) $n;
        }
    }
    if (!isset($input['owner_name_' . $slot]) || trim((string) $input['owner_name_' . $slot]) === '') {
        return false;
    }
    try {
        $saved = jeab_kontakt_save_input($input);
    } catch (Throwable) {
        return false;
    }

    return is_array($saved) && trim((string) ($saved['id'] ?? '')) === $contactId;
}

function jeab_contacts_sole_pnr_fix_slot(mixed $slot): int
{
    if ($slot === 1 || $slot === '1') {
        return 1;
    }
    if ($slot === 2 || $slot === '2') {
        return 2;
    }

    return 0;
}

/** @return array<string, mixed>|null */
function jeab_contacts_sole_pnr_fix_order(int $orderId): ?array
{
    if ($orderId <= 0 || !function_exists('jeab_purchase_all')) {
        return null;
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row) || (int) ($row['reminder_id'] ?? 0) !== $orderId) {
            continue;
        }
        if (jeab_contacts_sole_row_pnr_name_ambiguous($row)) {
            return $row;
        }
    }

    return null;
}

/**
 * Lagrade kundnummer, även sådana som listan inte visar.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_contacts_sole_pnr_fix_fortnox_numbers(array $row): array
{
    $out = [];
    foreach (['fortnox_customer', 'fortnox_customer_number', 'fortnox_kundnummer', 'CustomerNumber'] as $key) {
        if (!array_key_exists($key, $row) || is_array($row[$key])) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value === '' || in_array($value, $out, true)) {
            continue;
        }
        $out[] = $value;
    }

    return $out;
}

/** @param list<string> $stored */
function jeab_contacts_sole_pnr_fix_same(string $input, array $stored): bool
{
    foreach ($stored as $have) {
        if (strlen($have) === strlen($input) && hash_equals($have, $input)) {
            return true;
        }
    }

    return false;
}

/** Tomt när Fortnox inte kan läsas. Returvärdet loggas inte. */
function jeab_contacts_sole_pnr_fix_fetch(array $order, string $kundnr): ?string
{
    if (!function_exists('jeab_fortnox_pnr_client')) {
        require_once __DIR__ . '/fortnox_pnr.php';
    }
    if (!function_exists('jeab_fortnox_pnr_id') || jeab_fortnox_pnr_id($kundnr) === '') {
        return null;
    }
    $account = function_exists('jeab_ordrar_fortnox_account')
        ? jeab_ordrar_fortnox_account(isset($order['company']) ? (string) $order['company'] : null)
        : 'Jarfalla';
    try {
        $api = jeab_fortnox_pnr_client($account);
        $customer = $api->getCustomer($kundnr);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($customer)) {
        return null;
    }
    $raw = function_exists('jeab_fortnox_pnr_org_field') ? jeab_fortnox_pnr_org_field($customer) : '';
    if ($raw === '') {
        foreach (['SocialSecurityNumber', 'personnummer'] as $key) {
            $value = $customer[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $raw = trim($value);
                break;
            }
        }
    }
    $raw = trim($raw);

    return $raw === '' ? null : $raw;
}

function jeab_contacts_sole_pnr_fix_who(): string
{
    $who = 'agent';
    if (function_exists('jeab_who_me_person')) {
        $person = jeab_who_me_person();
        if (is_array($person)) {
            $name = trim((string) ($person['name'] ?? ''));
            if ($name !== '' && !jeab_contacts_sole_pnr_list_blocked($name)) {
                $who = $name;
            }
        }
    }

    return $who;
}

function jeab_contacts_sole_pnr_fix_response(bool $ok, string $error, bool $dry, bool $wouldWrite): array
{
    if (!$ok) {
        $error = trim($error);
        if ($error === '' || jeab_contacts_sole_pnr_list_blocked($error)) {
            $error = 'Det gick inte.';
        }
        $out = ['ok' => false, 'error' => $error];
        if ($dry) {
            $out['dry'] = true;
            $out['would_write'] = false;
        }

        return $out;
    }
    if ($dry) {
        return ['ok' => true, 'dry' => true, 'would_write' => $wouldWrite];
    }

    return ['ok' => true];
}

/** @param array<string, mixed> $row */
function jeab_contacts_sole_row_pnr_name_ambiguous(array $row): bool
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '') {
        return false;
    }
    $contact = jeab_contacts_sole_linked_contact($id);
    if (!is_array($contact)) {
        return false;
    }
    $ambiguous = false;
    jeab_contacts_sole_recipient_owners_from($row, $contact, $ambiguous);

    return $ambiguous;
}

/**
 * @return array{reminders:int,orders:int,bookings:int}
 */
function jeab_contacts_sole_empty(JeabStore $store): array
{
    $empty = ['reminders' => 0, 'orders' => 0, 'bookings' => 0];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row) && jeab_contacts_sole_cid($row['contact_id'] ?? null) === '') {
            $empty['reminders']++;
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row) && jeab_contacts_sole_cid($row['contact_id'] ?? null) === '') {
                $empty['orders']++;
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row) && jeab_contacts_sole_cid($row['contact_id'] ?? null) === '') {
            $empty['bookings']++;
        }
    }

    return $empty;
}

function jeab_contacts_sole_cid(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    $text = (string) $value;

    return $text === '' ? '' : $text;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_plan_limit(array $input): int
{
    $raw = $input['limit'] ?? 2000;
    $limit = is_numeric($raw) ? (int) $raw : 2000;
    if ($limit < 1) {
        return 1;
    }
    if ($limit > 5000) {
        return 5000;
    }

    return $limit;
}

/**
 * @param array<string, mixed> $input
 * @return array{at:int,phantoms:list<array<string,mixed>>}
 */
function jeab_contacts_sole_plan_cursor(array $input): array
{
    $empty = ['at' => 0, 'phantoms' => []];
    $raw = $input['cursor'] ?? null;
    if ($raw === null || $raw === '') {
        return $empty;
    }
    if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', $raw) === 1)) {
        return ['at' => max(0, (int) $raw), 'phantoms' => []];
    }
    $data = null;
    if (is_array($raw)) {
        $data = $raw;
    } elseif (is_string($raw)) {
        $pad = strlen($raw) % 4;
        $b64 = strtr($raw, '-_', '+/');
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        $data = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($data)) {
        return $empty;
    }
    $phantoms = [];
    foreach (array_slice(is_array($data['phantoms'] ?? null) ? $data['phantoms'] : [], 0, 5000) as $phantom) {
        $clean = jeab_contacts_sole_plan_phantom($phantom);
        if ($clean !== null) {
            $phantoms[] = $clean;
        }
    }

    return ['at' => max(0, (int) ($data['at'] ?? 0)), 'phantoms' => $phantoms];
}

/**
 * @param list<array<string, mixed>> $phantoms
 */
function jeab_contacts_sole_plan_encode(int $at, array $phantoms): string
{
    $payload = json_encode([
        'at' => $at,
        'phantoms' => array_values($phantoms),
    ], JSON_UNESCAPED_UNICODE);
    $raw = base64_encode(is_string($payload) ? $payload : '{}');

    return rtrim(strtr($raw, '+/', '-_'), '=');
}

/**
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_plan_phantom(mixed $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '' || strlen($id) > 80) {
        return null;
    }
    $owners = [];
    foreach (array_slice(is_array($row['owners'] ?? null) ? $row['owners'] : [], 0, 4) as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $owners[] = [
            'name' => trim((string) ($owner['name'] ?? '')),
            'contact' => !empty($owner['contact']),
            'email' => trim((string) ($owner['email'] ?? '')),
            'phone' => trim((string) ($owner['phone'] ?? '')),
        ];
    }

    return [
        'id' => $id,
        'street' => trim((string) ($row['street'] ?? '')),
        'street_key' => trim((string) ($row['street_key'] ?? '')),
        'postal_code' => trim((string) ($row['postal_code'] ?? '')),
        'city' => trim((string) ($row['city'] ?? '')),
        'owners' => $owners,
    ];
}

function jeab_contacts_sole_fill_key(string $kind): string
{
    return match ($kind) {
        'order' => 'orders',
        'booking' => 'bookings',
        default => 'reminders',
    };
}

/**
 * @return array{reminders:int,orders:int,bookings:int}
 */
function jeab_contacts_sole_fill_blank(): array
{
    return ['reminders' => 0, 'orders' => 0, 'bookings' => 0];
}

function jeab_contacts_sole_migrate(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $skipLog = function_exists('jeab_kontakt_merge_skip_guard') ? jeab_kontakt_merge_skip_guard($dry) : null;
    if (!$dry && !jeab_contacts_sole()) {
        return [
            'ok' => false,
            'dry' => false,
            'skipped' => false,
            'written' => false,
            'flag' => false,
            'error' => 'kontakter.sole dry=0 kräver CONTACTS_SOLE=1. Inget skrevs.',
            'created' => 0,
            'linked' => 0,
            'kept' => 0,
            'repaired' => 0,
            'fill' => jeab_contacts_sole_fill_blank(),
            'deviations' => [],
        ];
    }
    if (!$dry) {
        $block = jeab_contacts_sole_write_error($store);
        if ($block !== '') {
            return [
                'ok' => false,
                'dry' => false,
                'skipped' => false,
                'written' => false,
                'error' => $block,
                'created' => 0,
                'linked' => 0,
                'kept' => 0,
                'repaired' => 0,
                'deviations' => [],
            ];
        }
    }
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $index = jeab_contacts_sole_index($contacts);
    $created = 0;
    $linked = 0;
    $kept = 0;
    $fill = jeab_contacts_sole_fill_blank();
    $deviations = [];
    $plan = $dry ? jeab_contacts_sole_plan_cursor($input) : ['at' => 0, 'phantoms' => []];
    $phantoms = $plan['phantoms'];
    if ($dry) {
        foreach ($phantoms as $phantom) {
            $contacts[$phantom['id']] = $phantom;
            jeab_contacts_sole_index_add($index, $phantom);
        }
    }
    $rows = [];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $rows[] = ['kind' => 'reminder', 'row' => $row];
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $rows[] = ['kind' => 'order', 'row' => $row];
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $rows[] = ['kind' => 'booking', 'row' => $row];
        }
    }
    $batch = $dry ? '' : jeab_contacts_sole_batch();
    $seq = 1;
    $limit = $dry ? jeab_contacts_sole_plan_limit($input) : count($rows);
    $start = $dry ? min($plan['at'], count($rows)) : 0;
    $deadline = microtime(true) + 20.0;
    $scanned = 0;
    if (!$dry) {
        if (!jeab_contacts_sole_undo_add($batch, $seq, 'snapshot', 'census', $batch, jeab_contacts_sole_census($store), null)) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'batch_id' => $batch,
                'error' => 'Ångerloggen kunde inte skrivas. Inget skrevs.',
                'created' => 0,
                'linked' => 0,
                'kept' => 0,
                'repaired' => 0,
                'deviations' => [],
            ]);
        }
        $seq++;
    }
    $total = count($rows);
    for ($i = $start; $i < $total; $i++) {
        if ($dry && $scanned >= $limit) {
            break;
        }
        if ($dry && $scanned > 0 && microtime(true) >= $deadline) {
            break;
        }
        $scanned++;
        $item = $rows[$i];
        $kind = (string) $item['kind'];
        $row = $item['row'];
        $hint = jeab_contacts_sole_hint($kind, $row);
        $existing = jeab_contacts_sole_cid($row['contact_id'] ?? null);
        $recordId = $kind === 'order' ? (int) ($row['reminder_id'] ?? 0) : (int) ($row['id'] ?? 0);
        if ($existing !== '') {
            $without = $hint;
            $without['contact_id'] = '';
            $resolved = jeab_contacts_sole_resolve($contacts, $index, $without);
            $known = isset($contacts[$existing]) && is_array($contacts[$existing]);
            if (!$known || ($resolved !== null && $resolved !== $existing)) {
                $deviations[] = [
                    'kind' => $kind,
                    'id' => $recordId,
                    'contact_id' => $existing,
                    'reason' => $known ? 'annan' : 'saknad',
                ];
            } else {
                $kept++;
            }
            continue;
        }
        $resolved = jeab_contacts_sole_resolve($contacts, $index, $hint);
        $made = null;
        if ($resolved === null) {
            if ($dry) {
                $phantom = 'dry-' . $kind . '-' . (string) ($recordId > 0 ? $recordId : $created);
                $contacts[$phantom] = [
                    'id' => $phantom,
                    'street' => $hint['street'],
                    'street_key' => $hint['street_key'],
                    'postal_code' => $hint['postal'],
                    'city' => $hint['city'],
                    'owners' => [[
                        'name' => $hint['name'],
                        'contact' => true,
                        'email' => $hint['email'],
                        'phone' => $hint['phone'],
                    ]],
                ];
                jeab_contacts_sole_index_add($index, $contacts[$phantom]);
                $clean = jeab_contacts_sole_plan_phantom($contacts[$phantom]);
                if ($clean !== null) {
                    $phantoms[] = $clean;
                }
                $created++;
                $linked++;
                $fill[jeab_contacts_sole_fill_key($kind)]++;
                continue;
            }
            $made = jeab_contacts_sole_create($hint);
            if (!is_array($made) || trim((string) ($made['id'] ?? '')) === '') {
                return jeab_contacts_sole_public([
                    'ok' => false,
                    'dry' => false,
                    'written' => $linked > 0,
                    'batch_id' => $batch,
                    'error' => 'En kontakt kunde inte skapas. Inga rader togs bort.',
                    'created' => $created,
                    'linked' => $linked,
                    'kept' => $kept,
                    'repaired' => 0,
                    'deviations' => $deviations,
                ] + jeab_contacts_sole_verify($store));
            }
            $resolved = (string) $made['id'];
            $contacts[$resolved] = $made;
            jeab_contacts_sole_index_add($index, $made);
            if (!jeab_contacts_sole_undo_add($batch, $seq, 'contact', 'contact', $resolved, null, jeab_contacts_sole_undo_contact($made))) {
                return jeab_contacts_sole_public([
                    'ok' => false,
                    'dry' => false,
                    'written' => true,
                    'batch_id' => $batch,
                    'error' => 'Ångerloggen kunde inte skrivas. Inga rader togs bort.',
                    'created' => $created,
                    'linked' => $linked,
                    'kept' => $kept,
                    'repaired' => 0,
                    'deviations' => $deviations,
                ] + jeab_contacts_sole_verify($store));
            }
            $seq++;
            $created++;
        }
        if ($dry) {
            $linked++;
            $fill[jeab_contacts_sole_fill_key($kind)]++;
            continue;
        }
        if ($recordId <= 0 || !jeab_contacts_sole_write_link($store, $kind, $recordId, $resolved)) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => true,
                'batch_id' => $batch,
                'error' => 'contact_id kunde inte sättas. Inga rader togs bort.',
                'created' => $created,
                'linked' => $linked,
                'kept' => $kept,
                'repaired' => 0,
                'deviations' => $deviations,
            ] + jeab_contacts_sole_verify($store));
        }
        if (!jeab_contacts_sole_undo_add($batch, $seq, 'link', $kind, (string) $recordId, ['contact_id' => ''], ['contact_id' => $resolved])) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => true,
                'batch_id' => $batch,
                'error' => 'Ångerloggen kunde inte skrivas. Inga rader togs bort.',
                'created' => $created,
                'linked' => $linked,
                'kept' => $kept,
                'repaired' => 0,
                'deviations' => $deviations,
            ] + jeab_contacts_sole_verify($store));
        }
        $seq++;
        $linked++;
        $fill[jeab_contacts_sole_fill_key($kind)]++;
        unset($made);
    }
    $next = null;
    if ($dry && $i < $total) {
        $next = jeab_contacts_sole_plan_encode($i, $phantoms);
    }
    if (function_exists('jeab_reminder_contacts_bump')) {
        jeab_reminder_contacts_bump();
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $verify = $dry ? ['ids_same' => true, 'match' => true] : jeab_contacts_sole_verify($store);

    return jeab_contacts_sole_public([
        'ok' => $dry ? true : !empty($verify['ok']),
        'dry' => $dry,
        'skipped' => false,
        'written' => !$dry,
        'flag' => jeab_contacts_sole(),
        'batch_id' => $batch,
        'created' => $created,
        'linked' => $linked,
        'kept' => $kept,
        'repaired' => 0,
        'fill' => $fill,
        'scanned' => $scanned,
        'limit' => $dry ? $limit : null,
        'cursor' => $dry ? $start : null,
        'next_cursor' => $next,
        'deviations' => $deviations,
    ] + $verify);
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function jeab_contacts_sole_public(array $result): array
{
    if (function_exists('jeab_kontakt_strip_secrets')) {
        $clean = jeab_kontakt_strip_secrets($result);

        return is_array($clean) ? $clean : $result;
    }

    return $result;
}

/**
 * Spärr före skrivning. Ojämna antal stoppar allt.
 * $moves får bara innehålla rader som körningen själv skulle skriva
 * (tom eller trasig contact_id) och som skulle byta en levande kontakt.
 * Giltiga länkar som pekar om hör inte hemma här.
 *
 * @param array<string, int> $before
 * @param array<string, int> $after
 * @param list<array<string, mixed>> $moves
 */
function jeab_contacts_sole_fill_empty_guard(array $before, array $after, array $moves): string
{
    foreach (['reminders', 'orders', 'bookings'] as $key) {
        if ((int) ($before[$key] ?? -1) !== (int) ($after[$key] ?? -2)) {
            return 'Antalet rader skulle ändras. Inget skrevs.';
        }
    }
    if ($moves !== []) {
        return 'En befintlig länk skulle flyttas. Inget skrevs.';
    }

    return '';
}

/**
 * Giltiga länkar som skulle peka på en annan kontakt skrivs inte.
 * De rapporteras som move_candidates. moves är bara rader som skulle skrivas
 * och som samtidigt har en levande kontakt som skulle bytas.
 *
 * @param list<array{kind:string,row:array<string,mixed>}> $items
 * @param array<string, mixed> $contacts
 * @param array{email:array<string,string>,phone:array<string,string>} $index
 * @return array{moves:list<array{kind:string,id:int}>,move_candidates:list<array{kind:string,id:int,contact_id:string,resolved:string}>}
 */
function jeab_contacts_sole_fill_empty_moves(array $items, array $contacts, array $index): array
{
    $moves = [];
    $candidates = [];
    foreach ($items as $item) {
        $kind = (string) $item['kind'];
        $row = $item['row'];
        $existing = jeab_contacts_sole_cid($row['contact_id'] ?? null);
        $recordId = $kind === 'order' ? (int) ($row['reminder_id'] ?? 0) : (int) ($row['id'] ?? 0);
        if ($recordId <= 0) {
            continue;
        }
        $known = $existing !== '' && isset($contacts[$existing]) && is_array($contacts[$existing]);
        $hint = jeab_contacts_sole_hint($kind, $row);
        $hint['contact_id'] = '';
        $resolved = jeab_contacts_sole_resolve($contacts, $index, $hint);
        if ($known) {
            if ($resolved !== null && $resolved !== $existing) {
                $candidates[] = [
                    'kind' => $kind,
                    'id' => $recordId,
                    'contact_id' => $existing,
                    'resolved' => $resolved,
                ];
            }
            continue;
        }
        if ($existing !== '' && isset($contacts[$existing]) && is_array($contacts[$existing]) && $resolved !== null && $resolved !== $existing) {
            $moves[] = ['kind' => $kind, 'id' => $recordId];
        }
    }

    return ['moves' => $moves, 'move_candidates' => $candidates];
}

/**
 * Fyller bara tom contact_id eller en länk till en kontakt som inte finns.
 * En giltig länk som skulle peka om skrivs inte och stoppar inte körningen.
 * Den listas i move_candidates. Ett ändrat antal rader skriver inget.
 * Kräver inte CONTACTS_SOLE. dry=0 stoppas av jeab_contacts_sole_write_error.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_fill_empty(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = jeab_contacts_sole_wants_dry($input);
    $skipLog = function_exists('jeab_kontakt_merge_skip_guard') ? jeab_kontakt_merge_skip_guard($dry) : null;
    if (!$dry) {
        $block = jeab_contacts_sole_write_error($store);
        if ($block !== '') {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'error' => $block,
                'counts' => ['before' => jeab_contacts_sole_fill_blank(), 'after' => jeab_contacts_sole_fill_blank()],
                'rows' => [],
            ]);
        }
    }
    $countsBefore = jeab_contacts_sole_counts($store);
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $index = jeab_contacts_sole_index($contacts);
    $items = [];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $items[] = ['kind' => 'reminder', 'row' => $row];
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $items[] = ['kind' => 'order', 'row' => $row];
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $items[] = ['kind' => 'booking', 'row' => $row];
        }
    }
    $classified = jeab_contacts_sole_fill_empty_moves($items, $contacts, $index);
    $moves = $classified['moves'];
    $moveCandidates = $classified['move_candidates'];
    $guard = jeab_contacts_sole_fill_empty_guard($countsBefore, $countsBefore, $moves);
    if ($guard !== '') {
        return jeab_contacts_sole_public([
            'ok' => false,
            'dry' => $dry,
            'written' => false,
            'error' => $guard,
            'counts' => ['before' => $countsBefore, 'after' => $countsBefore],
            'rows' => [],
            'blocked' => $moves,
            'move_candidates' => $moveCandidates,
        ]);
    }
    $listed = [];
    $batch = '';
    $seq = 1;
    $created = 0;
    $linked = 0;
    foreach ($items as $item) {
        $kind = (string) $item['kind'];
        $row = $item['row'];
        $existing = jeab_contacts_sole_cid($row['contact_id'] ?? null);
        $known = $existing !== '' && isset($contacts[$existing]) && is_array($contacts[$existing]);
        if ($known) {
            continue;
        }
        $recordId = $kind === 'order' ? (int) ($row['reminder_id'] ?? 0) : (int) ($row['id'] ?? 0);
        if ($recordId <= 0) {
            continue;
        }
        $hint = jeab_contacts_sole_hint($kind, $row);
        $resolved = jeab_contacts_sole_resolve($contacts, $index, $hint);
        $action = 'link';
        if ($resolved === null) {
            $action = 'create';
            if ($dry) {
                $phantom = 'dry-' . $kind . '-' . (string) $recordId;
                $contacts[$phantom] = [
                    'id' => $phantom,
                    'street' => $hint['street'],
                    'street_key' => $hint['street_key'],
                    'postal_code' => $hint['postal'],
                    'city' => $hint['city'],
                    'owners' => [[
                        'name' => $hint['name'],
                        'contact' => true,
                        'email' => $hint['email'],
                        'phone' => $hint['phone'],
                    ]],
                ];
                jeab_contacts_sole_index_add($index, $contacts[$phantom]);
                $resolved = $phantom;
            } else {
                if ($batch === '') {
                    $batch = jeab_contacts_sole_batch();
                }
                $made = jeab_contacts_sole_create($hint);
                if (!is_array($made) || trim((string) ($made['id'] ?? '')) === '') {
                    return jeab_contacts_sole_public([
                        'ok' => false,
                        'dry' => false,
                        'written' => $linked > 0,
                        'batch_id' => $batch,
                        'error' => 'En kontakt kunde inte skapas. Inga rader togs bort.',
                        'counts' => ['before' => $countsBefore, 'after' => jeab_contacts_sole_counts($store)],
                    'rows' => $listed,
                    'created' => $created,
                    'linked' => $linked,
                    'move_candidates' => $moveCandidates,
                ]);
                }
                $resolved = (string) $made['id'];
                $contacts[$resolved] = $made;
                jeab_contacts_sole_index_add($index, $made);
                if (!jeab_contacts_sole_undo_add($batch, $seq, 'contact', 'contact', $resolved, null, jeab_contacts_sole_undo_contact($made))) {
                    return jeab_contacts_sole_public([
                        'ok' => false,
                        'dry' => false,
                        'written' => true,
                        'batch_id' => $batch,
                        'error' => 'Ångerloggen kunde inte skrivas. Inga rader togs bort.',
                        'counts' => ['before' => $countsBefore, 'after' => jeab_contacts_sole_counts($store)],
                        'rows' => $listed,
                        'created' => $created,
                        'linked' => $linked,
                        'move_candidates' => $moveCandidates,
                    ]);
                }
                $seq++;
            }
            $created++;
        }
        if (!$dry) {
            if ($batch === '') {
                $batch = jeab_contacts_sole_batch();
            }
            $wrote = $existing === ''
                ? jeab_contacts_sole_write_link($store, $kind, $recordId, $resolved)
                : jeab_contacts_sole_stamp($store, $kind, $recordId, $resolved, $existing);
            if (!$wrote) {
                return jeab_contacts_sole_public([
                    'ok' => false,
                    'dry' => false,
                    'written' => $linked > 0,
                    'batch_id' => $batch,
                    'error' => 'contact_id kunde inte sättas. Inga rader togs bort.',
                    'counts' => ['before' => $countsBefore, 'after' => jeab_contacts_sole_counts($store)],
                    'rows' => $listed,
                    'created' => $created,
                    'linked' => $linked,
                    'move_candidates' => $moveCandidates,
                ]);
            }
            if (!jeab_contacts_sole_undo_add($batch, $seq, 'link', $kind, (string) $recordId, ['contact_id' => $existing], ['contact_id' => $resolved])) {
                return jeab_contacts_sole_public([
                    'ok' => false,
                    'dry' => false,
                    'written' => true,
                    'batch_id' => $batch,
                    'error' => 'Ångerloggen kunde inte skrivas. Inga rader togs bort.',
                    'counts' => ['before' => $countsBefore, 'after' => jeab_contacts_sole_counts($store)],
                    'rows' => $listed,
                    'created' => $created,
                    'linked' => $linked,
                    'move_candidates' => $moveCandidates,
                ]);
            }
            $seq++;
        }
        $listed[] = ['kind' => $kind, 'id' => $recordId, 'action' => $action];
        $linked++;
    }
    $countsAfter = $countsBefore;
    if (!$dry && $batch !== '') {
        if (function_exists('jeab_reminder_contacts_bump')) {
            jeab_reminder_contacts_bump();
        }
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
        $countsAfter = jeab_contacts_sole_counts($store);
        $late = jeab_contacts_sole_fill_empty_guard($countsBefore, $countsAfter, []);
        if ($late !== '') {
            $rolled = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);
            $countsAfter = jeab_contacts_sole_counts($store);
            $kept = [];
            foreach ((array) ($rolled['kept_referenced'] ?? []) as $keptId) {
                $keptId = trim((string) $keptId);
                if ($keptId !== '') {
                    $kept[] = $keptId;
                }
            }

            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'batch_id' => $batch,
                'error' => $late,
                'counts' => ['before' => $countsBefore, 'after' => $countsAfter],
                'rows' => [],
                'created' => 0,
                'linked' => 0,
                'kept_referenced' => $kept,
                'move_candidates' => $moveCandidates,
            ]);
        }
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => $dry,
        'written' => !$dry && $listed !== [],
        'batch_id' => $batch,
        'counts' => ['before' => $countsBefore, 'after' => $countsAfter],
        'rows' => $listed,
        'created' => $created,
        'linked' => $linked,
        'move_candidates' => $moveCandidates,
    ]);
}

/**
 * Ny ögonblicksbild av nuvarande antal, id och länkar. Flyttar inga rader.
 * Ångra markerar raden så verifieringen jämför mot den föregående bilden.
 * Kräver inte CONTACTS_SOLE. dry=0 stoppas av jeab_contacts_sole_write_error.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_resnapshot(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = jeab_contacts_sole_wants_dry($input);
    if (!$dry) {
        $block = jeab_contacts_sole_write_error($store);
        if ($block !== '') {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'error' => $block,
            ]);
        }
    }
    $census = jeab_contacts_sole_census($store);
    $counts = is_array($census['counts'] ?? null) ? $census['counts'] : jeab_contacts_sole_fill_blank();
    $ids = is_array($census['ids'] ?? null) ? $census['ids'] : ['reminders' => [], 'orders' => [], 'bookings' => []];
    if ($dry) {
        return jeab_contacts_sole_public([
            'ok' => true,
            'dry' => true,
            'written' => false,
            'counts' => $counts,
            'ids' => $ids,
        ]);
    }
    $batch = jeab_contacts_sole_batch();
    if (!jeab_contacts_sole_undo_add($batch, 1, 'snapshot', 'census', $batch, $census, null)) {
        return jeab_contacts_sole_public([
            'ok' => false,
            'dry' => false,
            'written' => false,
            'batch_id' => $batch,
            'error' => 'Ångerloggen kunde inte skrivas. Inget skrevs.',
            'counts' => $counts,
        ]);
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => false,
        'written' => true,
        'batch_id' => $batch,
        'counts' => $counts,
        'ids' => $ids,
    ]);
}

/** Jämförelsenyckel. Tom sträng när värdet saknas. Skrivs inte ut. */
function jeab_contacts_sole_pnr_distinct_key(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $digits = function_exists('jeab_norm_pnr_digits') ? jeab_norm_pnr_digits($raw) : '';

    return $digits !== '' ? $digits : $raw;
}

/**
 * Nyaste ordern för samma kontakt där namnet finns på exakt en person
 * och den personen har personnummer eller adress.
 * Andra kunders ordrar ingår inte. Den aktuella ordern får ingå även
 * när contact_id ännu inte är densamma.
 * Flera olika nummer ger conflict och bara order-id.
 *
 * @param list<array<string, mixed>> $orders
 * @return array{personnummer:string,address:string}|array{conflict:true,order_ids:list<int>}|null
 */
function jeab_contacts_sole_pnr_name_source(array $orders, string $nameKey, string $contactId, int $orderId): ?array
{
    if ($nameKey === '' || $contactId === '') {
        return null;
    }
    $cands = [];
    foreach ($orders as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowId = (int) ($row['reminder_id'] ?? 0);
        $rowContact = trim((string) ($row['contact_id'] ?? ''));
        if ($rowContact !== $contactId && $rowId !== $orderId) {
            continue;
        }
        $hits = [];
        foreach (jeab_contacts_sole_order_people($row) as $person) {
            $personName = (string) ($person['name'] ?? $person['namn'] ?? '');
            if (jeab_contacts_sole_name_key($personName) === $nameKey) {
                $hits[] = $person;
            }
        }
        if (count($hits) !== 1) {
            continue;
        }
        $person = $hits[0];
        $pnr = trim((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
        $address = trim((string) ($person['address'] ?? $person['adress'] ?? ''));
        if ($pnr === '' && $address === '') {
            continue;
        }
        $cands[] = [
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'reminder_id' => $rowId,
            'personnummer' => $pnr,
            'address' => $address,
            'pnr_key' => jeab_contacts_sole_pnr_distinct_key($pnr),
        ];
    }
    if ($cands === []) {
        return null;
    }
    $distinct = [];
    $conflictIds = [];
    foreach ($cands as $cand) {
        $key = (string) $cand['pnr_key'];
        if ($key === '') {
            continue;
        }
        $distinct[$key] = true;
        $id = (int) $cand['reminder_id'];
        if ($id > 0) {
            $conflictIds[$id] = $id;
        }
    }
    if (count($distinct) > 1) {
        $ids = array_values($conflictIds);
        sort($ids);

        return ['conflict' => true, 'order_ids' => $ids];
    }
    usort($cands, static function (array $a, array $b): int {
        $byTime = strcmp((string) $b['created_at'], (string) $a['created_at']);
        if ($byTime !== 0) {
            return $byTime;
        }

        return ((int) $b['reminder_id']) <=> ((int) $a['reminder_id']);
    });
    $pick = $cands[0];

    return [
        'personnummer' => (string) $pick['personnummer'],
        'address' => (string) $pick['address'],
    ];
}

/**
 * Ägarindex på kontakten där tomt personnummer eller tom adress inte kan
 * fyllas från exakt ett namn på just den här ordern.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $contact
 * @return list<int>
 */
function jeab_contacts_sole_pnr_ambiguous_indexes(array $row, array $contact): array
{
    $raw = $contact['owners'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $pnr = jeab_contacts_sole_pnr_text((string) ($owner['personnummer'] ?? ''));
        $address = trim((string) ($owner['address'] ?? $owner['adress'] ?? ''));
        if ($pnr !== '' && $address !== '') {
            continue;
        }
        if (is_array(jeab_contacts_sole_recipient_order_person($row, $name))) {
            continue;
        }
        $out[] = (int) $i;
    }

    return $out;
}

/**
 * Lagringsform. Flaggan på ger tolv siffror, annars dagens format.
 */
function jeab_contacts_sole_pnr_owner_store(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (jeab_contacts_sole()) {
        return jeab_contacts_sole_pnr_store_value($raw);
    }

    return jeab_contacts_sole_pnr_text($raw);
}

/**
 * Fyller ägarens tomma personnummer och adress från den nyaste ordern med samma namn.
 * Bara rader som räknas som pnr_name_ambiguous. Torrlistan har order-id, kontakt-id och ägarindex.
 * Före-värden ligger bara i ångerloggen. Kräver inte CONTACTS_SOLE.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_pnr_ambiguous_resolve(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = jeab_contacts_sole_wants_dry($input);
    if (!$dry) {
        $block = jeab_contacts_sole_write_error($store);
        if ($block !== '') {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'error' => $block,
                'rows' => [],
            ]);
        }
    }
    $orders = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $orders[] = $row;
            }
        }
    }
    $publicRows = [];
    $skipped = [];
    $jobs = [];
    foreach ($orders as $row) {
        if (!jeab_contacts_sole_row_pnr_name_ambiguous($row)) {
            continue;
        }
        $contactId = trim((string) ($row['contact_id'] ?? ''));
        $contact = $contactId !== '' ? jeab_contacts_sole_linked_contact($contactId) : null;
        if (!is_array($contact)) {
            continue;
        }
        $orderId = (int) ($row['reminder_id'] ?? 0);
        if ($orderId <= 0) {
            continue;
        }
        foreach (jeab_contacts_sole_pnr_ambiguous_indexes($row, $contact) as $index) {
            $owners = is_array($contact['owners'] ?? null) ? $contact['owners'] : [];
            $owner = $owners[$index] ?? null;
            if (!is_array($owner)) {
                continue;
            }
            $nameKey = jeab_contacts_sole_name_key((string) ($owner['name'] ?? ''));
            $source = jeab_contacts_sole_pnr_name_source($orders, $nameKey, $contactId, $orderId);
            if ($source === null) {
                continue;
            }
            if (!empty($source['conflict'])) {
                $ids = [];
                foreach ((array) ($source['order_ids'] ?? []) as $sourceId) {
                    $sourceId = (int) $sourceId;
                    if ($sourceId > 0) {
                        $ids[$sourceId] = $sourceId;
                    }
                }
                $ids = array_values($ids);
                sort($ids);
                $skipped[] = [
                    'order_id' => $orderId,
                    'contact_id' => $contactId,
                    'owner_index' => $index,
                    'order_ids' => $ids,
                ];
                continue;
            }
            $needPnr = jeab_contacts_sole_pnr_text((string) ($owner['personnummer'] ?? '')) === '';
            $needAddress = trim((string) ($owner['address'] ?? $owner['adress'] ?? '')) === '';
            $pnr = $needPnr ? jeab_contacts_sole_pnr_owner_store((string) $source['personnummer']) : '';
            $address = $needAddress ? trim((string) $source['address']) : '';
            if ($pnr === '' && $address === '') {
                continue;
            }
            $publicRows[] = [
                'order_id' => $orderId,
                'contact_id' => $contactId,
                'owner_index' => $index,
            ];
            $jobs[$contactId][$index] = ['pnr' => $pnr, 'address' => $address];
        }
    }
    if ($dry) {
        return jeab_contacts_sole_public([
            'ok' => true,
            'dry' => true,
            'written' => false,
            'rows' => $publicRows,
            'skipped' => $skipped,
        ]);
    }
    $batch = '';
    $seq = 1;
    $filled = 0;
    foreach ($jobs as $contactId => $fills) {
        $contact = function_exists('jeab_kontakt_get') ? jeab_kontakt_get((string) $contactId) : null;
        if (!is_array($contact) || !function_exists('jeab_fastighet_write_contact')) {
            continue;
        }
        $owners = [];
        foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $owners[] = $owner;
            }
        }
        $beforeOwners = $owners;
        $changed = false;
        foreach ($fills as $index => $fill) {
            if (!is_array($fill) || !isset($owners[$index]) || !is_array($owners[$index])) {
                continue;
            }
            if ((string) ($fill['pnr'] ?? '') !== '' && trim((string) ($owners[$index]['personnummer'] ?? '')) === '') {
                $owners[$index]['personnummer'] = (string) $fill['pnr'];
                $changed = true;
            }
            if ((string) ($fill['address'] ?? '') !== '' && trim((string) ($owners[$index]['address'] ?? $owners[$index]['adress'] ?? '')) === '') {
                $owners[$index]['address'] = (string) $fill['address'];
                $changed = true;
            }
        }
        if (!$changed) {
            continue;
        }
        if ($batch === '') {
            $batch = jeab_contacts_sole_batch();
        }
        if (!jeab_contacts_sole_undo_add($batch, $seq, 'owner', 'contact', (string) $contactId, [
            'owners' => $beforeOwners,
            'updated_at' => trim((string) ($contact['updated_at'] ?? '')),
        ], ['owners' => $owners])) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => $filled > 0,
                'batch_id' => $batch,
                'error' => 'Ångerloggen kunde inte skrivas. Inget mer skrevs.',
                'rows' => $publicRows,
                'skipped' => $skipped,
            ]);
        }
        $seq++;
        if (jeab_contacts_sole()) {
            jeab_contacts_sole_pnr_digits_push();
        }
        try {
            $wrote = jeab_fastighet_write_contact((string) $contactId, ['owners' => $owners]);
        } catch (Throwable) {
            $wrote = false;
        } finally {
            if (jeab_contacts_sole()) {
                jeab_contacts_sole_pnr_digits_pop();
            }
        }
        if (!$wrote) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => $filled > 0,
                'batch_id' => $batch,
                'error' => 'Ägarfältet kunde inte skrivas. Inget mer skrevs.',
                'rows' => $publicRows,
                'skipped' => $skipped,
            ]);
        }
        $filled++;
        jeab_contacts_sole_cache_forget((string) $contactId);
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => false,
        'written' => $filled > 0,
        'batch_id' => $batch,
        'rows' => $publicRows,
        'skipped' => $skipped,
        'filled' => $filled,
    ]);
}

/**
 * Läser en ångerbatch. Varje rad har slag, rad-id och contact_id före och efter.
 * Skapade kontakter listas som id. Inget personnummer och inga kontaktvärden.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_undo_rows(array $input, ?JeabStore $store = null): array
{
    $batch = trim((string) ($input['batch_id'] ?? ''));
    if ($batch === '') {
        return jeab_contacts_sole_public([
            'ok' => false,
            'dry' => true,
            'written' => false,
            'error' => 'batch_id saknas.',
            'rows' => [],
            'created' => [],
        ]);
    }
    jeab_contacts_sole_undo_pdo($store);
    $rows = [];
    $created = [];
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if ((string) ($row['batch_id'] ?? '') !== $batch) {
            continue;
        }
        $before = json_decode((string) ($row['before_json'] ?? ''), true);
        $after = json_decode((string) ($row['after_json'] ?? ''), true);
        $beforeId = is_array($before) ? trim((string) ($before['contact_id'] ?? '')) : '';
        $afterId = is_array($after) ? trim((string) ($after['contact_id'] ?? '')) : '';
        $kind = trim((string) ($row['kind'] ?? ''));
        $entityId = trim((string) ($row['entity_id'] ?? ''));
        if ($kind === 'contact' && $entityId !== '') {
            $created[$entityId] = $entityId;
        }
        $listed = [
            'kind' => $kind,
            'entity' => trim((string) ($row['entity'] ?? '')),
            'id' => $entityId,
            'before_contact_id' => $beforeId,
            'after_contact_id' => $afterId,
        ];
        $field = is_array($before) ? trim((string) ($before['field'] ?? '')) : '';
        if (in_array($field, ['email', 'phone', 'address', 'gata', 'ort', 'postnummer'], true)) {
            $listed['field'] = $field;
        }
        $rows[] = $listed;
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => true,
        'written' => false,
        'batch_id' => $batch,
        'rows' => $rows,
        'created' => array_values($created),
    ]);
}

/**
 * @return array{kind:string,id:string}
 */
function jeab_contacts_sole_link_parse_ref(mixed $ref): array
{
    if (is_array($ref)) {
        $kind = strtolower(trim((string) ($ref['kind'] ?? 'contact')));
        $id = trim((string) ($ref['id'] ?? ''));
    } else {
        $raw = trim((string) $ref);
        $colon = strpos($raw, ':');
        if ($colon === false) {
            $kind = 'contact';
            $id = $raw;
        } else {
            $kind = strtolower(trim(substr($raw, 0, $colon)));
            $id = trim(substr($raw, $colon + 1));
        }
    }
    $map = [
        'order' => 'order',
        'purchase' => 'order',
        'bestallning' => 'order',
        'reminder' => 'reminder',
        'paminnelse' => 'reminder',
        'booking' => 'booking',
        'bokning' => 'booking',
        'contact' => 'contact',
        'kontakt' => 'contact',
    ];
    if (!isset($map[$kind])) {
        return ['kind' => 'contact', 'id' => trim((string) (is_array($ref) ? ($ref['id'] ?? '') : $ref))];
    }

    return ['kind' => $map[$kind], 'id' => $id];
}

function jeab_contacts_sole_link_record_contact(JeabStore $store, string $kind, string $id): string
{
    if ($kind === 'contact') {
        return trim($id);
    }
    $recordId = (int) $id;
    if ($recordId <= 0) {
        return '';
    }
    $row = null;
    if ($kind === 'order' && function_exists('jeab_purchase_get')) {
        $row = jeab_purchase_get($recordId);
    } elseif ($kind === 'booking') {
        $row = $store->getBooking($recordId);
    } elseif ($kind === 'reminder') {
        $row = $store->getReminder($recordId);
    }

    return is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : '';
}

/**
 * Jämförelsenycklar. De lämnar inte funktionen.
 *
 * @param array<string, mixed> $contact
 * @return array{email:string,phone:string,street:string,postcode:string}
 */
function jeab_contacts_sole_link_keys(array $contact): array
{
    $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '';
    $street = trim((string) ($contact['street'] ?? ''));
    $postal = trim((string) ($contact['postal_code'] ?? ''));
    $address = trim((string) ($contact['address'] ?? ''));
    if (($street === '' || $postal === '') && $address !== '' && function_exists('jeab_kontakt_parse_address')) {
        $parsed = jeab_kontakt_parse_address($address);
        if ($street === '') {
            $street = trim((string) ($parsed['street'] ?? ''));
        }
        if ($postal === '') {
            $postal = trim((string) ($parsed['postal'] ?? ''));
        }
    }
    $streetKey = $street !== '' && function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($street) : '';
    if (function_exists('jeab_norm_postal')) {
        $postal = jeab_norm_postal($postal);
    } elseif (function_exists('jeab_kontakt_postal')) {
        $postal = jeab_kontakt_postal($postal);
    }

    return [
        'email' => $email,
        'phone' => $phone,
        'street' => $streetKey,
        'postcode' => $postal,
    ];
}

function jeab_contacts_sole_link_same(string $left, string $right): bool
{
    return $left !== '' && $right !== '' && $left === $right;
}

/**
 * Jämför par av kind:id eller kontakt-id. Svaret har contact_id och ja/nej
 * för mejl, telefon, gata och postnummer. Själva värdena skrivs inte ut.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_link_compare(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $rawPairs = $input['pairs'] ?? null;
    if (!is_array($rawPairs) && isset($input['a'], $input['b'])) {
        $rawPairs = [[$input['a'], $input['b']]];
    }
    if (!is_array($rawPairs)) {
        return jeab_contacts_sole_public([
            'ok' => false,
            'dry' => true,
            'written' => false,
            'error' => 'pairs saknas.',
            'pairs' => [],
        ]);
    }
    $pairs = [];
    foreach ($rawPairs as $pair) {
        $left = null;
        $right = null;
        if (is_string($pair)) {
            $parts = preg_split('/\s+\+\s+/', $pair) ?: [];
            if (count($parts) === 2) {
                $left = $parts[0];
                $right = $parts[1];
            }
        } elseif (is_array($pair)) {
            if (array_key_exists('a', $pair) && array_key_exists('b', $pair)) {
                $left = $pair['a'];
                $right = $pair['b'];
            } elseif (array_key_exists(0, $pair) && array_key_exists(1, $pair)) {
                $left = $pair[0];
                $right = $pair[1];
            }
        }
        if ($left === null || $right === null) {
            continue;
        }
        $a = jeab_contacts_sole_link_parse_ref($left);
        $b = jeab_contacts_sole_link_parse_ref($right);
        $aContact = jeab_contacts_sole_link_record_contact($store, $a['kind'], $a['id']);
        $bContact = jeab_contacts_sole_link_record_contact($store, $b['kind'], $b['id']);
        $aRow = $aContact !== '' ? jeab_contacts_sole_linked_contact($aContact) : null;
        $bRow = $bContact !== '' ? jeab_contacts_sole_linked_contact($bContact) : null;
        $aKeys = is_array($aRow) ? jeab_contacts_sole_link_keys($aRow) : ['email' => '', 'phone' => '', 'street' => '', 'postcode' => ''];
        $bKeys = is_array($bRow) ? jeab_contacts_sole_link_keys($bRow) : ['email' => '', 'phone' => '', 'street' => '', 'postcode' => ''];
        $pairs[] = [
            'items' => [
                ['kind' => $a['kind'], 'id' => $a['id'], 'contact_id' => $aContact],
                ['kind' => $b['kind'], 'id' => $b['id'], 'contact_id' => $bContact],
            ],
            'same_email' => jeab_contacts_sole_link_same($aKeys['email'], $bKeys['email']),
            'same_phone' => jeab_contacts_sole_link_same($aKeys['phone'], $bKeys['phone']),
            'same_street' => jeab_contacts_sole_link_same($aKeys['street'], $bKeys['street']),
            'same_postcode' => jeab_contacts_sole_link_same($aKeys['postcode'], $bKeys['postcode']),
        ];
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => true,
        'written' => false,
        'pairs' => $pairs,
    ]);
}

/**
 * order_ts är köpets updated_at, annars created_at.
 * contact_ts är senaste riktiga ändringen: kontaktens updated_at, eller created_at
 * på en ångerrad som fortfarande gäller (paint, owner, property, contact).
 * sole_resnapshot är en snapshot-rad och räknas inte. sole_undo sätter undone=1
 * och skriver tillbaka före-bildens updated_at, så den klockan inte blir en ny
 * ändring. En redan ångrad batch utan sparat updated_at lämnar kvar en klocka
 * som bara är nyare än batchen; den ignoreras, och ordern vinner om den har en tid.
 * Ordern vinner bara när dess tid är strikt nyare. Lika eller saknad ordertid lämnar kontakten.
 */
function jeab_contacts_sole_paint_ts_cmp(string $left, string $right): int
{
    $a = strtotime($left);
    $b = strtotime($right);
    if ($a !== false && $b !== false) {
        return $a <=> $b;
    }

    return strcmp($left, $right);
}

function jeab_contacts_sole_paint_order_ts(array $row): string
{
    $updated = trim((string) ($row['updated_at'] ?? ''));
    if ($updated !== '') {
        return $updated;
    }

    return trim((string) ($row['created_at'] ?? ''));
}

/**
 * @param array<string, array{real:string,undone:string}> $clocks
 */
function jeab_contacts_sole_paint_contact_ts(array $contact, array $clocks): string
{
    $id = trim((string) ($contact['id'] ?? ''));
    $updated = trim((string) ($contact['updated_at'] ?? ''));
    $clock = $id !== '' && isset($clocks[$id]) && is_array($clocks[$id]) ? $clocks[$id] : ['real' => '', 'undone' => ''];
    $real = trim((string) ($clock['real'] ?? ''));
    $undone = trim((string) ($clock['undone'] ?? ''));
    if ($updated !== '' && $undone !== '' && jeab_contacts_sole_paint_ts_cmp($updated, $undone) >= 0) {
        $realCovers = $real !== '' && jeab_contacts_sole_paint_ts_cmp($real, $updated) >= 0;
        if (!$realCovers) {
            $updated = '';
        }
    }
    if ($updated === '') {
        return $real;
    }
    if ($real === '') {
        return $updated;
    }

    return jeab_contacts_sole_paint_ts_cmp($updated, $real) >= 0 ? $updated : $real;
}

/** @return array<string, array{real:string,undone:string}> */
function jeab_contacts_sole_paint_undo_times(): array
{
    $best = [];
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = (string) ($row['kind'] ?? '');
        if (!in_array($kind, ['contact', 'property', 'owner', 'paint'], true)) {
            continue;
        }
        $at = trim((string) ($row['created_at'] ?? ''));
        if ($at === '') {
            continue;
        }
        $ids = [];
        $entityId = trim((string) ($row['entity_id'] ?? ''));
        if ($entityId !== '' && in_array($kind, ['contact', 'property', 'owner'], true)) {
            $ids[] = $entityId;
        }
        if ($kind === 'paint') {
            $before = json_decode((string) ($row['before_json'] ?? ''), true);
            $cid = is_array($before) ? trim((string) ($before['contact_id'] ?? '')) : '';
            if ($cid !== '') {
                $ids[] = $cid;
            }
        }
        $slot = ((int) ($row['undone'] ?? 0) === 1 && $kind !== 'contact') ? 'undone' : 'real';
        if ((int) ($row['undone'] ?? 0) === 1 && $kind === 'contact') {
            continue;
        }
        foreach ($ids as $id) {
            if (!isset($best[$id])) {
                $best[$id] = ['real' => '', 'undone' => ''];
            }
            $have = $best[$id][$slot];
            if ($have === '' || jeab_contacts_sole_paint_ts_cmp($at, $have) > 0) {
                $best[$id][$slot] = $at;
            }
        }
    }

    return $best;
}

/** @return array<string, true>|null null betyder alla ordrar. */
function jeab_contacts_sole_paint_order_filter(array $input): ?array
{
    if (!array_key_exists('order_ids', $input)) {
        return null;
    }
    $raw = $input['order_ids'];
    if (is_string($raw)) {
        $raw = preg_split('/\s*,\s*/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $id) {
        $n = (int) $id;
        if ($n > 0) {
            $ids[$n] = true;
        }
    }

    return $ids;
}

function jeab_contacts_sole_paint_winner(string $orderTs, string $contactTs): string
{
    if ($orderTs === '') {
        return 'contact';
    }
    if ($contactTs === '') {
        return 'order';
    }

    return jeab_contacts_sole_paint_ts_cmp($orderTs, $contactTs) > 0 ? 'order' : 'contact';
}

/**
 * @param list<array<string, mixed>> $owners
 */
function jeab_contacts_sole_paint_owner_index(array $owners): int
{
    foreach ($owners as $i => $owner) {
        if (is_array($owner) && !empty($owner['contact'])) {
            return (int) $i;
        }
    }
    foreach ($owners as $i => $owner) {
        if (is_array($owner)) {
            return (int) $i;
        }
    }

    return -1;
}

/**
 * @param array<string, mixed> $contact
 * @return array<string, string>
 */
function jeab_contacts_sole_paint_read_slots(array $contact, string $field): array
{
    $owners = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    $idx = jeab_contacts_sole_paint_owner_index($owners);
    $owner = $idx >= 0 ? $owners[$idx] : [];
    if ($field === 'email') {
        return ['owner_email' => trim((string) ($owner['email'] ?? ''))];
    }
    if ($field === 'phone') {
        return ['owner_phone' => trim((string) ($owner['phone'] ?? ''))];
    }
    if ($field === 'gata') {
        return ['street' => trim((string) ($contact['street'] ?? ''))];
    }
    if ($field === 'ort') {
        return ['city' => trim((string) ($contact['city'] ?? ''))];
    }
    if ($field === 'postnummer') {
        return ['postal_code' => trim((string) ($contact['postal_code'] ?? ''))];
    }
    if ($field === 'address') {
        return [
            'address' => trim((string) ($contact['address'] ?? '')),
            'street' => trim((string) ($contact['street'] ?? '')),
            'postal_code' => trim((string) ($contact['postal_code'] ?? '')),
            'city' => trim((string) ($contact['city'] ?? '')),
        ];
    }

    return [];
}

/**
 * @param array<string, string> $before
 * @return array<string, string>
 */
function jeab_contacts_sole_paint_order_slots(string $field, string $raw, array $before): array
{
    $raw = trim($raw);
    if ($field === 'email') {
        return ['owner_email' => $raw];
    }
    if ($field === 'phone') {
        return ['owner_phone' => $raw];
    }
    if ($field === 'gata') {
        return ['street' => $raw];
    }
    if ($field === 'ort') {
        return ['city' => $raw];
    }
    if ($field === 'postnummer') {
        return ['postal_code' => $raw];
    }
    if ($field !== 'address') {
        return [];
    }
    $parts = jeab_contacts_sole_paint_address_order_parts($raw);
    $after = [];
    if ($parts['street'] !== '') {
        $after['street'] = $parts['street'];
    }
    if ($parts['postal'] !== '') {
        $after['postal_code'] = $parts['postal'];
    }
    if ($parts['town'] !== '') {
        $after['city'] = $parts['town'];
    }

    return $after;
}

function jeab_contacts_sole_paint_order_value(array $row, string $field): string
{
    if ($field === 'email') {
        return trim((string) ($row['email'] ?? ''));
    }
    if ($field === 'phone') {
        return trim((string) ($row['phone'] ?? ''));
    }
    if ($field === 'address') {
        return trim((string) ($row['address'] ?? ''));
    }
    if ($field === 'gata') {
        return trim((string) ($row['gata'] ?? ''));
    }
    if ($field === 'ort') {
        return trim((string) ($row['ort'] ?? ''));
    }
    if ($field === 'postnummer') {
        return trim((string) ($row['postnummer'] ?? $row['postnr'] ?? ''));
    }

    return '';
}

/**
 * @param array<string, string> $slots
 */
function jeab_contacts_sole_paint_apply(string $contactId, array $slots): bool
{
    $contactId = trim($contactId);
    if ($contactId === '' || $slots === [] || !function_exists('jeab_kontakter_mutate')) {
        return false;
    }
    $allowed = ['street' => true, 'postal_code' => true, 'city' => true, 'address' => true, 'owner_email' => true, 'owner_phone' => true];
    $clean = [];
    foreach ($slots as $key => $value) {
        if (isset($allowed[(string) $key]) && is_string($value)) {
            $clean[(string) $key] = $value;
        }
    }
    if ($clean === []) {
        return false;
    }
    $wrote = false;
    jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $clean, &$wrote): void {
        $row = $contacts[$contactId] ?? null;
        if (!is_array($row)) {
            return;
        }
        $owners = [];
        foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $owners[] = $owner;
            }
        }
        $idx = jeab_contacts_sole_paint_owner_index($owners);
        $touchOwner = array_key_exists('owner_email', $clean) || array_key_exists('owner_phone', $clean);
        if ($touchOwner && $idx < 0) {
            $owners[] = ['name' => '', 'email' => '', 'phone' => '', 'contact' => true];
            $idx = count($owners) - 1;
        }
        if ($idx >= 0) {
            if (array_key_exists('owner_email', $clean)) {
                $owners[$idx]['email'] = $clean['owner_email'];
            }
            if (array_key_exists('owner_phone', $clean)) {
                $owners[$idx]['phone'] = $clean['owner_phone'];
            }
            $row['owners'] = $owners;
        }
        foreach (['street', 'postal_code', 'city', 'address'] as $key) {
            if (array_key_exists($key, $clean)) {
                $row[$key] = $clean[$key];
            }
        }
        $row['id'] = $contactId;
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$contactId] = $row;
        $wrote = true;
    });
    if ($wrote) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return $wrote;
}

/**
 * @param array<string, array<string, string>> $before
 * @param array<string, array<string, string>> $after
 */
function jeab_contacts_sole_paint_links_same(array $before, array $after): bool
{
    foreach (['reminders', 'orders', 'bookings'] as $kind) {
        $prev = is_array($before[$kind] ?? null) ? $before[$kind] : [];
        $now = is_array($after[$kind] ?? null) ? $after[$kind] : [];
        if (count($prev) !== count($now)) {
            return false;
        }
        foreach ($prev as $id => $cid) {
            $key = (string) $id;
            if (!array_key_exists($key, $now)) {
                return false;
            }
            if (jeab_contacts_sole_cid($cid) !== jeab_contacts_sole_cid($now[$key])) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Listar paint_conflicts. Skriver orderns värde till kontakten bara där ordern är nyare.
 * Fälten är samma som paint_conflicts: email, phone, address, gata, ort, postnummer.
 * address skriver bara de delar ordern faktiskt har. Saknat postnummer eller ort
 * lämnas kvar på kontakten.
 * En ångerbatch med före- och eftervärde per order och fält. Inget personnummer i svaret.
 * Kräver inte CONTACTS_SOLE. dry=0 stoppas av jeab_contacts_sole_write_error.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_paint_resolve(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = jeab_contacts_sole_wants_dry($input);
    if (!$dry) {
        $block = jeab_contacts_sole_write_error($store);
        if ($block !== '') {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'error' => $block,
                'rows' => [],
                'batch_id' => '',
            ]);
        }
    }
    $filter = jeab_contacts_sole_paint_order_filter($input);
    $undoTs = jeab_contacts_sole_paint_undo_times();
    $rows = [];
    $jobs = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['reminder_id'] ?? 0);
            if ($orderId <= 0 || ($filter !== null && !isset($filter[$orderId]))) {
                continue;
            }
            $contactId = trim((string) ($row['contact_id'] ?? ''));
            if ($contactId === '') {
                continue;
            }
            $contact = jeab_contacts_sole_linked_contact($contactId);
            if (!is_array($contact)) {
                continue;
            }
            $fields = jeab_contacts_sole_paint_conflict_fields($row, jeab_contacts_sole_view_from_contact($contact));
            if ($fields === []) {
                continue;
            }
            $orderTs = jeab_contacts_sole_paint_order_ts($row);
            $contactTs = jeab_contacts_sole_paint_contact_ts($contact, $undoTs);
            $winner = jeab_contacts_sole_paint_winner($orderTs, $contactTs);
            foreach ($fields as $field) {
                $rows[] = [
                    'order_id' => $orderId,
                    'contact_id' => $contactId,
                    'field' => $field,
                    'order_ts' => $orderTs,
                    'contact_ts' => $contactTs,
                    'winner' => $winner,
                ];
                if ($winner === 'order') {
                    $jobs[] = [
                        'order_id' => $orderId,
                        'contact_id' => $contactId,
                        'field' => $field,
                        'order_ts' => $orderTs,
                        'value' => jeab_contacts_sole_paint_order_value($row, $field),
                    ];
                }
            }
        }
    }
    usort($rows, static function (array $a, array $b): int {
        $byOrder = ((int) $a['order_id']) <=> ((int) $b['order_id']);
        if ($byOrder !== 0) {
            return $byOrder;
        }

        return strcmp((string) $a['field'], (string) $b['field']);
    });
    if ($dry || $jobs === []) {
        return jeab_contacts_sole_public([
            'ok' => true,
            'dry' => $dry,
            'written' => false,
            'batch_id' => '',
            'rows' => $rows,
        ]);
    }
    usort($jobs, static function (array $a, array $b): int {
        $byTime = jeab_contacts_sole_paint_ts_cmp((string) $a['order_ts'], (string) $b['order_ts']);
        if ($byTime !== 0) {
            return $byTime;
        }
        $byOrder = ((int) $a['order_id']) <=> ((int) $b['order_id']);
        if ($byOrder !== 0) {
            return $byOrder;
        }

        return strcmp((string) $a['field'], (string) $b['field']);
    });
    $countsBefore = jeab_contacts_sole_counts($store);
    $linksBefore = jeab_contacts_sole_link_map($store);
    $batch = jeab_contacts_sole_batch();
    $seq = 1;
    foreach ($jobs as $job) {
        $contact = jeab_contacts_sole_linked_contact((string) $job['contact_id']);
        if (!is_array($contact)) {
            jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);

            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'batch_id' => $batch,
                'error' => 'Kontakten finns inte. Inget skrevs.',
                'rows' => [],
            ]);
        }
        $beforeSlots = jeab_contacts_sole_paint_read_slots($contact, (string) $job['field']);
        $afterSlots = jeab_contacts_sole_paint_order_slots((string) $job['field'], (string) $job['value'], $beforeSlots);
        if ($afterSlots === [] || $afterSlots === $beforeSlots) {
            continue;
        }
        if (!jeab_contacts_sole_undo_add($batch, $seq, 'paint', 'order', (string) $job['order_id'], [
            'contact_id' => (string) $job['contact_id'],
            'field' => (string) $job['field'],
            'updated_at' => trim((string) ($contact['updated_at'] ?? '')),
            'slots' => $beforeSlots,
        ], [
            'contact_id' => (string) $job['contact_id'],
            'field' => (string) $job['field'],
            'slots' => $afterSlots,
        ])) {
            jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);

            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'batch_id' => $batch,
                'error' => 'Ångerloggen kunde inte skrivas. Inget skrevs.',
                'rows' => [],
            ]);
        }
        $seq++;
        if (!jeab_contacts_sole_paint_apply((string) $job['contact_id'], $afterSlots)) {
            jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);

            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'batch_id' => $batch,
                'error' => 'Kontakten kunde inte skrivas. Inget skrevs.',
                'rows' => [],
            ]);
        }
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $countsAfter = jeab_contacts_sole_counts($store);
    $guard = jeab_contacts_sole_fill_empty_guard($countsBefore, $countsAfter, []);
    $linksAfter = jeab_contacts_sole_link_map($store);
    if ($guard !== '' || !jeab_contacts_sole_paint_links_same($linksBefore, $linksAfter)) {
        jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);

        return jeab_contacts_sole_public([
            'ok' => false,
            'dry' => false,
            'written' => false,
            'batch_id' => $batch,
            'error' => $guard !== '' ? $guard : 'En contact_id ändrades. Inget skrevs.',
            'rows' => [],
        ]);
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => false,
        'written' => $seq > 1,
        'batch_id' => $batch,
        'rows' => $rows,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400];
    }
    if ($action === 'kontakter.sole_verify') {
        $result = jeab_contacts_sole_verify($store);
    } elseif ($action === 'kontakter.gap_fill') {
        $result = function_exists('jeab_contacts_gap_fill')
            ? jeab_contacts_gap_fill($input, $store)
            : ['ok' => false, 'error' => 'Okänd åtgärd.'];
    } elseif ($action === 'kontakter.restore_contact_from_backup') {
        $result = function_exists('jeab_contacts_restore_contact_from_backup')
            ? jeab_contacts_restore_contact_from_backup($input, $store)
            : ['ok' => false, 'error' => 'Okänd åtgärd.'];
    } elseif ($action === 'kontakter.strict_report') {
        $result = function_exists('jeab_contacts_strict_report')
            ? jeab_contacts_strict_report($store)
            : ['ok' => false, 'error' => 'Okänd åtgärd.'];
    } elseif ($action === 'kontakter.sole_undo') {
        $result = jeab_contacts_sole_undo($input, $store);
    } elseif ($action === 'kontakter.sole_undo_rows') {
        $result = jeab_contacts_sole_undo_rows($input, $store);
    } elseif ($action === 'kontakter.link_compare') {
        $result = jeab_contacts_sole_link_compare($input, $store);
    } elseif ($action === 'kontakter.sole_paint_resolve') {
        $result = jeab_contacts_sole_paint_resolve($input, $store);
    } elseif ($action === 'kontakter.sole_fill_empty') {
        $result = jeab_contacts_sole_fill_empty($input, $store);
    } elseif ($action === 'kontakter.sole_resnapshot') {
        $result = jeab_contacts_sole_resnapshot($input, $store);
    } elseif ($action === 'kontakter.sole_snapshot_diff') {
        $result = jeab_contacts_sole_snapshot_diff($store);
    } elseif ($action === 'kontakter.pnr_ambiguous_resolve') {
        $result = jeab_contacts_sole_pnr_ambiguous_resolve($input, $store);
    } elseif ($action === 'kontakter.pnr_ambiguous_list') {
        $result = jeab_contacts_sole_pnr_ambiguous_list($input, $store);
    } elseif ($action === 'kontakter.pnr_fix') {
        $result = jeab_contacts_sole_pnr_fix($input, $store);
    } elseif ($action === 'snapshots.audit' || $action === 'snapshots.backfill' || $action === 'snapshots.backfill_undo' || $action === 'snapshots.counts') {
        $result = function_exists('jeab_snapshots_handle')
            ? jeab_snapshots_handle($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.'];
    } elseif ($action === 'contacts.sole_audit') {
        $result = jeab_contacts_sole_audit($store);
    } elseif ($action === 'contacts.link_diag') {
        require_once __DIR__ . '/contacts_link_diag.php';
        $result = jeab_contacts_link_diag($input, $store);
    } elseif ($action === 'contacts.link_by_email' || $action === 'contacts.link_by_email_undo') {
        require_once __DIR__ . '/contacts_link_by_email.php';
        $result = $action === 'contacts.link_by_email'
            ? jeab_contacts_link_by_email($input, $store)
            : jeab_contacts_link_by_email_undo($input, $store);
    } elseif ($action === 'contacts.link_backfill' || $action === 'contacts.link_backfill_undo') {
        require_once __DIR__ . '/contacts_link_backfill.php';
        $result = $action === 'contacts.link_backfill'
            ? jeab_contacts_link_backfill($input, $store)
            : jeab_contacts_link_backfill_undo($input, $store);
    } elseif ($action === 'contacts.link_missing_core' || $action === 'contacts.link_missing_core_undo') {
        require_once __DIR__ . '/contacts_link_missing_core.php';
        $result = $action === 'contacts.link_missing_core'
            ? jeab_contacts_link_missing_core($input, $store)
            : jeab_contacts_link_missing_core_undo($input, $store);
    } elseif ($action === 'contacts.copies_strip' || $action === 'contacts.copies_strip_undo') {
        require_once __DIR__ . '/contacts_copies.php';
        $result = $action === 'contacts.copies_strip'
            ? jeab_contacts_copies_strip($input, $store)
            : jeab_contacts_copies_strip_undo($input, $store);
    } elseif ($action === 'contacts.drop_customer_fields' || $action === 'contacts.drop_customer_fields_undo') {
        require_once __DIR__ . '/contacts_drop_customer_fields.php';
        $result = $action === 'contacts.drop_customer_fields'
            ? jeab_contacts_drop_customer_fields($input, $store)
            : jeab_contacts_drop_customer_fields_undo($input, $store);
    } elseif ($action === 'contacts.customers_contact_key' || $action === 'contacts.customers_contact_key_undo') {
        require_once __DIR__ . '/customers_contact_key.php';
        $result = $action === 'contacts.customers_contact_key'
            ? jeab_customers_contact_key($input, $store)
            : jeab_customers_contact_key_undo($input, $store);
    } elseif ($action === 'contacts.solkampanj_link') {
        require_once __DIR__ . '/solkampanj.php';
        $result = jeab_solkampanj_link_contacts(jeab_contacts_copies_dry($input));
    } elseif ($action === 'contacts.inbox_strip' || $action === 'contacts.inbox_strip_undo') {
        require_once __DIR__ . '/contacts_inbox_strip.php';
        $result = $action === 'contacts.inbox_strip'
            ? jeab_contacts_inbox_strip($input, $store)
            : jeab_contacts_inbox_strip_undo($input, $store);
    } elseif ($action === 'contacts.link_inkorg_from_reminder' || $action === 'contacts.link_inkorg_from_reminder_undo') {
        require_once __DIR__ . '/contacts_link_inkorg_from_reminder.php';
        $result = $action === 'contacts.link_inkorg_from_reminder'
            ? jeab_contacts_link_inkorg_from_reminder($input, $store)
            : jeab_contacts_link_inkorg_from_reminder_undo($input, $store);
    } elseif ($action === 'contacts.name_source') {
        require_once __DIR__ . '/contacts_name_source.php';
        $result = jeab_contacts_name_source($input, $store);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif ($action === 'contacts.name_candidates') {
        require_once __DIR__ . '/contacts_name_candidates.php';
        $result = jeab_contacts_name_candidates($input, $store);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif ($action === 'contacts.fill_name' || $action === 'contacts.fill_name_undo') {
        require_once __DIR__ . '/contacts_name_candidates.php';
        $undo = $action === 'contacts.fill_name_undo' || jeab_contacts_name_candidates_wants_undo($input);
        $result = $undo
            ? jeab_contacts_fill_name_undo($input, $store)
            : jeab_contacts_fill_name($input, $store);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif ($action === 'inbox.link_contacts' || $action === 'inbox.link_contacts_undo') {
        require_once __DIR__ . '/inbox_link_contacts.php';
        $undo = $action === 'inbox.link_contacts_undo' || jeab_inbox_link_contacts_wants_undo($input);
        $result = $undo
            ? jeab_inbox_link_contacts_undo($input, $store)
            : jeab_inbox_link_contacts($input, $store);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif ($action === 'inbox.link_verify') {
        require_once __DIR__ . '/inbox_link_verify.php';
        $result = jeab_inbox_link_verify($input, $store);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif ($action === 'inbox.contacts_from_threads' || $action === 'inbox.contacts_from_threads_undo') {
        require_once __DIR__ . '/inbox_contacts_from_threads.php';
        $undo = $action === 'inbox.contacts_from_threads_undo' || jeab_inbox_contacts_from_threads_wants_undo($input);
        $result = $undo
            ? jeab_inbox_contacts_from_threads_undo($input, $store)
            : jeab_inbox_contacts_from_threads($input, $store);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif (
        $action === 'contacts.merge_duplicates'
        || $action === 'contacts.merge_duplicates_undo'
        || $action === 'contacts.normalize_addresses'
        || $action === 'contacts.normalize_addresses_undo'
    ) {
        require_once __DIR__ . '/contacts_merge_duplicates.php';
        $result = jeab_contacts_merge_dispatch($input);
        if (!isset($result['status'])) {
            $result['status'] = !empty($result['ok']) ? 200 : 400;
        }

        return jeab_contacts_sole_public($result);
    } elseif ($action === 'kontakter.prune_orphan') {
        $result = function_exists('jeab_contacts_prune_orphan')
            ? jeab_contacts_prune_orphan($input, $store)
            : ['ok' => false, 'error' => 'Okänd åtgärd.'];
    } elseif ($action === 'kontakter.owner_pnr_fill') {
        $result = jeab_contacts_owner_pnr_fill($input, $store);
    } else {
        $result = jeab_contacts_sole_migrate($input, $store);
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return jeab_contacts_sole_public($result);
}

/**
 * Fullmakten för en order. Saknad token eller oläsbar fil ger null.
 *
 * @return array<string, mixed>|null
 */
function jeab_contacts_owner_pnr_fill_doc(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    if (!function_exists('fullmakt_load_doc')) {
        $boot = dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
        if (is_file($boot) && !defined('FULLMAKT_ROOT')) {
            require_once $boot;
        }
        $file = dirname(__DIR__) . '/fullmakt/lib/store.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
    if (!function_exists('fullmakt_load_doc')) {
        return null;
    }
    try {
        $doc = fullmakt_load_doc($token);
    } catch (Throwable) {
        return null;
    }

    return is_array($doc) ? $doc : null;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $doc
 * @return list<array<string, mixed>>
 */
function jeab_contacts_owner_pnr_fill_people(array $row, ?array $doc): array
{
    $people = [];
    foreach (jeab_contacts_sole_order_people($row) as $person) {
        $people[] = $person;
    }
    $topName = trim((string) ($row['huvudsokande'] ?? $row['name'] ?? $row['customer_name'] ?? ''));
    $topPnr = trim((string) ($row['personnummer'] ?? ''));
    if ($topName !== '' && $topPnr !== '') {
        $people[] = ['name' => $topName, 'personnummer' => $topPnr];
    }
    if (!is_array($doc)) {
        return $people;
    }
    $docName = trim((string) ($doc['namn'] ?? ''));
    $docPnr = trim((string) ($doc['personnummer'] ?? ''));
    if ($docName !== '' && $docPnr !== '') {
        $people[] = ['name' => $docName, 'personnummer' => $docPnr];
    }
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $huvud = is_array($parter['huvudsokande'] ?? null) ? $parter['huvudsokande'] : [];
    $huvudName = trim((string) ($huvud['namn'] ?? ''));
    $huvudPnr = trim((string) ($huvud['personnummer'] ?? ''));
    if ($huvudName !== '' && $huvudPnr !== '') {
        $people[] = ['name' => $huvudName, 'personnummer' => $huvudPnr];
    }
    $med = $parter['medsokande'] ?? null;
    if (is_array($med)) {
        foreach ($med as $party) {
            if (!is_array($party)) {
                continue;
            }
            $name = trim((string) ($party['namn'] ?? $party['name'] ?? ''));
            $pnr = trim((string) ($party['personnummer'] ?? ''));
            if ($name !== '' && $pnr !== '') {
                $people[] = ['name' => $name, 'personnummer' => $pnr];
            }
        }
    }

    return $people;
}

/**
 * Ett personnummer när namnet ger exakt ett nummer. Flera olika nummer ger tomt.
 * Returen är lagringsform och lämnar inte funktionen via svar eller logg.
 *
 * @param list<array<string, mixed>> $people
 */
function jeab_contacts_owner_pnr_fill_pick(array $people, string $nameKey): string
{
    if ($nameKey === '') {
        return '';
    }
    $collect = static function (array $people, callable $match) : array {
        $distinct = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $personName = (string) ($person['name'] ?? $person['namn'] ?? '');
            if (!$match($personName)) {
                continue;
            }
            $pnr = trim((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
            $key = jeab_contacts_sole_pnr_distinct_key($pnr);
            if ($key === '') {
                continue;
            }
            $distinct[$key] = $pnr;
        }

        return $distinct;
    };
    $exact = $collect($people, static function (string $personName) use ($nameKey): bool {
        return jeab_contacts_sole_name_key($personName) === $nameKey;
    });
    if (count($exact) === 1) {
        return jeab_contacts_sole_pnr_owner_store((string) array_values($exact)[0]);
    }
    if ($exact !== []) {
        return '';
    }
    $loose = $collect($people, static function (string $personName) use ($nameKey): bool {
        return jeab_contacts_sole_name_given_last_same($nameKey, $personName);
    });
    if (count($loose) !== 1) {
        return '';
    }

    return jeab_contacts_sole_pnr_owner_store((string) array_values($loose)[0]);
}

function jeab_contacts_owner_pnr_fill_blocked(JeabStore $store): string
{
    if (!function_exists('jeab_kontakter_sql_is_sole_source') || !jeab_kontakter_sql_is_sole_source()) {
        return 'Kontakterna måste ligga i SQL. Inget skrevs.';
    }
    if (!jeab_contacts_sole_undo_pdo($store) instanceof PDO) {
        return 'Ångerloggen kräver SQL. Inget skrevs.';
    }
    if (!function_exists('jeab_fastighet_write_contact')) {
        return 'Kontaktlagret saknas. Inget skrevs.';
    }

    return '';
}

/**
 * Fyller tomt personnummer på ägare och tom fastighetsbeteckning från order och fullmakt.
 * Tilltalsnamn räcker när efternamnet stämmer och numret är samma.
 * En saknad andra ägare läggs till när ordern har ett giltigt nummer och kontakten har en ledig plats.
 * Ifyllda värden skrivs inte över. Ordrar som är pnr_name_ambiguous hoppas över
 * och listas med id. Torr som standard. Svaret har bara antal och id.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_owner_pnr_fill(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = jeab_contacts_sole_wants_dry($input);
    if (!$dry) {
        $block = jeab_contacts_owner_pnr_fill_blocked($store);
        if ($block !== '') {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'error' => $block,
                'counts' => ['contacts' => 0, 'owners' => 0, 'fastighet' => 0],
                'ids' => [],
                'pnr_name_ambiguous' => [],
            ]);
        }
    }
    $orders = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $orders[] = $row;
            }
        }
    }
    $ambiguous = [];
    $usable = [];
    foreach ($orders as $row) {
        $orderId = (int) ($row['reminder_id'] ?? 0);
        if ($orderId <= 0) {
            $orderId = (int) ($row['id'] ?? 0);
        }
        if (jeab_contacts_sole_row_pnr_name_ambiguous($row)) {
            if ($orderId > 0) {
                $ambiguous[$orderId] = $orderId;
            }
            continue;
        }
        $usable[] = $row;
    }
    $ambiguousIds = array_values($ambiguous);
    sort($ambiguousIds);
    $byContact = [];
    foreach ($usable as $row) {
        $contactId = trim((string) ($row['contact_id'] ?? ''));
        if ($contactId === '') {
            continue;
        }
        $byContact[$contactId][] = $row;
    }
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $ids = [];
    $pnrCount = 0;
    $fastCount = 0;
    $jobs = [];
    foreach ($byContact as $contactId => $rows) {
        $contact = isset($contacts[$contactId]) && is_array($contacts[$contactId]) ? $contacts[$contactId] : null;
        if (!is_array($contact) && function_exists('jeab_kontakt_get')) {
            $contact = jeab_kontakt_get((string) $contactId);
        }
        if (!is_array($contact)) {
            continue;
        }
        $people = [];
        $fastighets = [];
        foreach ($rows as $row) {
            $doc = jeab_contacts_owner_pnr_fill_doc(trim((string) ($row['fullmakt_token'] ?? '')));
            foreach (jeab_contacts_owner_pnr_fill_people($row, $doc) as $person) {
                $people[] = $person;
            }
            $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
            if ($fast !== '') {
                $fastighets[$fast] = $fast;
            }
            if (is_array($doc)) {
                $docFast = trim((string) ($doc['fastighetsbeteckning'] ?? ''));
                if ($docFast !== '') {
                    $fastighets[$docFast] = $docFast;
                }
            }
        }
        $owners = [];
        foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $owners[] = $owner;
            }
        }
        $beforeOwners = $owners;
        $pnrHere = 0;
        foreach ($owners as $index => $owner) {
            if (trim((string) ($owner['personnummer'] ?? '')) !== '') {
                continue;
            }
            $nameKey = jeab_contacts_sole_name_key((string) ($owner['name'] ?? ''));
            $pnr = jeab_contacts_owner_pnr_fill_pick($people, $nameKey);
            if ($pnr === '') {
                continue;
            }
            $beforeOwners[$index]['pnr_clear'] = 1;
            $owners[$index]['personnummer'] = $pnr;
            $pnrHere++;
        }
        $free = max(0, 2 - count($owners));
        if ($free > 0) {
            $missing = [];
            foreach ($people as $person) {
                if (!is_array($person)) {
                    continue;
                }
                $personName = trim((string) ($person['name'] ?? $person['namn'] ?? ''));
                $rawPnr = trim((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
                if ($personName === '' || $rawPnr === '') {
                    continue;
                }
                if (!function_exists('jeab_kontakt_pnr_valid') || !jeab_kontakt_pnr_valid($rawPnr)) {
                    continue;
                }
                $pnrKey = jeab_contacts_sole_pnr_key($rawPnr);
                if ($pnrKey === '') {
                    continue;
                }
                $stored = jeab_contacts_sole_pnr_owner_store($rawPnr);
                if ($stored === '') {
                    continue;
                }
                $taken = false;
                foreach ($owners as $owner) {
                    if (!is_array($owner)) {
                        continue;
                    }
                    $ownerName = trim((string) ($owner['name'] ?? ''));
                    if ($ownerName !== '') {
                        if (jeab_contacts_sole_name_key($ownerName) === jeab_contacts_sole_name_key($personName)) {
                            $taken = true;
                            break;
                        }
                        if (jeab_contacts_sole_name_given_last_same($ownerName, $personName)
                            || jeab_contacts_sole_name_given_last_same($personName, $ownerName)) {
                            $taken = true;
                            break;
                        }
                    }
                    $ownerKey = jeab_contacts_sole_pnr_key((string) ($owner['personnummer'] ?? ''));
                    if ($ownerKey !== '' && strlen($ownerKey) === strlen($pnrKey) && hash_equals($ownerKey, $pnrKey)) {
                        $taken = true;
                        break;
                    }
                }
                if ($taken || isset($missing[$pnrKey])) {
                    continue;
                }
                $missing[$pnrKey] = [
                    'name' => $personName,
                    'personnummer' => $stored,
                    'contact' => false,
                    'email' => '',
                    'phone' => '',
                ];
            }
            if ($missing !== [] && count($missing) <= $free) {
                foreach ($missing as $extra) {
                    $owners[] = $extra;
                    $pnrHere++;
                }
            }
        }
        $beforeFast = trim((string) ($contact['fastighetsbeteckning'] ?? ''));
        $nextFast = $beforeFast;
        if ($beforeFast === '' && count($fastighets) === 1) {
            $nextFast = (string) array_values($fastighets)[0];
        }
        $fastHere = $nextFast !== $beforeFast ? 1 : 0;
        if ($pnrHere === 0 && $fastHere === 0) {
            continue;
        }
        $ids[] = (string) $contactId;
        $pnrCount += $pnrHere;
        $fastCount += $fastHere;
        $jobs[] = [
            'id' => (string) $contactId,
            'owners' => $owners,
            'before_owners' => $beforeOwners,
            'fastighetsbeteckning' => $nextFast,
            'before_fast' => $beforeFast,
            'updated_at' => trim((string) ($contact['updated_at'] ?? '')),
            'pnr' => $pnrHere,
            'fastighet' => $fastHere,
        ];
    }
    sort($ids);
    $counts = ['contacts' => count($ids), 'owners' => $pnrCount, 'fastighet' => $fastCount];
    if ($dry) {
        return jeab_contacts_sole_public([
            'ok' => true,
            'dry' => true,
            'written' => false,
            'counts' => $counts,
            'ids' => $ids,
            'pnr_name_ambiguous' => $ambiguousIds,
        ]);
    }
    $batch = '';
    $seq = 1;
    $writtenIds = [];
    $writtenPnr = 0;
    $writtenFast = 0;
    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $contactId = (string) $job['id'];
        if ($batch === '') {
            $batch = jeab_contacts_sole_batch();
        }
        $before = [
            'owners' => $job['before_owners'],
            'fastighetsbeteckning' => (string) $job['before_fast'],
            'updated_at' => (string) $job['updated_at'],
        ];
        $after = [
            'owners' => $job['owners'],
            'fastighetsbeteckning' => (string) $job['fastighetsbeteckning'],
        ];
        if (!jeab_contacts_sole_undo_add($batch, $seq, 'property', 'contact', $contactId, $before, $after)) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => $writtenIds !== [],
                'batch_id' => $batch,
                'error' => 'Ångerloggen kunde inte skrivas. Inget mer skrevs.',
                'counts' => ['contacts' => count($writtenIds), 'owners' => $writtenPnr, 'fastighet' => $writtenFast],
                'ids' => $writtenIds,
                'pnr_name_ambiguous' => $ambiguousIds,
            ]);
        }
        $seq++;
        if (jeab_contacts_sole()) {
            jeab_contacts_sole_pnr_digits_push();
        }
        try {
            $wrote = jeab_fastighet_write_contact($contactId, [
                'owners' => $job['owners'],
                'fastighetsbeteckning' => (string) $job['fastighetsbeteckning'],
            ]);
        } catch (Throwable) {
            $wrote = false;
        } finally {
            if (jeab_contacts_sole()) {
                jeab_contacts_sole_pnr_digits_pop();
            }
        }
        if (!$wrote) {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => $writtenIds !== [],
                'batch_id' => $batch,
                'error' => 'Kontakten kunde inte skrivas. Inget mer skrevs.',
                'counts' => ['contacts' => count($writtenIds), 'owners' => $writtenPnr, 'fastighet' => $writtenFast],
                'ids' => $writtenIds,
                'pnr_name_ambiguous' => $ambiguousIds,
            ]);
        }
        $writtenIds[] = $contactId;
        $writtenPnr += (int) $job['pnr'];
        $writtenFast += (int) $job['fastighet'];
        jeab_contacts_sole_cache_forget($contactId);
    }
    sort($writtenIds);

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => false,
        'written' => $writtenIds !== [],
        'batch_id' => $batch,
        'counts' => ['contacts' => count($writtenIds), 'owners' => $writtenPnr, 'fastighet' => $writtenFast],
        'ids' => $writtenIds,
        'pnr_name_ambiguous' => $ambiguousIds,
    ]);
}

/**
 * Kopplar en ny rad när flaggan är på. Kopian rörs inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_link_record(JeabStore $store, string $kind, array $row): array
{
    if (!jeab_contacts_sole() || jeab_contacts_sole_write_error($store) !== '') {
        return $row;
    }
    // Redan satt länk lämnas, innan någon ny kontakt skapas.
    $existing = jeab_contacts_sole_cid($row['contact_id'] ?? null);
    if ($existing !== '') {
        return $row;
    }
    $hint = jeab_contacts_sole_hint($kind, $row);
    if ($kind === 'booking' && jeab_contacts_sole_booking_link_skips_create($hint)) {
        $siblingIds = jeab_contacts_sole_booking_sibling_ids((int) ($row['id'] ?? 0));
        $recordId = (int) ($row['id'] ?? 0);
        if (count($siblingIds) === 1 && $recordId > 0 && jeab_contacts_sole_write_link($store, 'booking', $recordId, $siblingIds[0])) {
            if (function_exists('jeab_reminder_contacts_bump')) {
                jeab_reminder_contacts_bump();
            }
            $fresh = $store->getBooking($recordId);

            return is_array($fresh) ? $fresh : $row;
        }

        return $row;
    }
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $index = jeab_contacts_sole_index($contacts);
    $resolved = jeab_contacts_sole_resolve($contacts, $index, $hint);
    if ($resolved === null) {
        $fresh = jeab_contacts_sole_create($hint);
        $resolved = is_array($fresh) ? trim((string) ($fresh['id'] ?? '')) : '';
    }
    if ($resolved === '') {
        return $row;
    }
    $recordId = $kind === 'order' ? (int) ($row['reminder_id'] ?? 0) : (int) ($row['id'] ?? 0);
    if ($recordId <= 0 || !jeab_contacts_sole_write_link($store, $kind, $recordId, $resolved)) {
        return $row;
    }
    if (function_exists('jeab_reminder_contacts_bump')) {
        jeab_reminder_contacts_bump();
    }
    if ($kind === 'reminder') {
        $fresh = $store->getReminder($recordId);

        return is_array($fresh) ? $fresh : $row;
    }
    if ($kind === 'booking') {
        $fresh = $store->getBooking($recordId);

        return is_array($fresh) ? $fresh : $row;
    }
    if ($kind === 'order' && function_exists('jeab_purchase_get')) {
        $fresh = jeab_purchase_get($recordId);

        return is_array($fresh) ? $fresh : $row;
    }

    return $row;
}

/**
 * @param array<string, mixed> $contacts
 * @return array{email:array<string,string>,phone:array<string,string>}
 */
function jeab_contacts_sole_index(array $contacts): array
{
    $index = ['email' => [], 'phone' => []];
    foreach ($contacts as $row) {
        if (is_array($row)) {
            jeab_contacts_sole_index_add($index, $row);
        }
    }

    return $index;
}

/**
 * @param array{email:array<string,string>,phone:array<string,string>} $index
 * @param array<string, mixed> $row
 */
function jeab_contacts_sole_index_add(array &$index, array $row): void
{
    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '') {
        return;
    }
    $email = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '';
    if ($email !== '' && !isset($index['email'][$email]) && (!function_exists('jeab_kontakt_address_blocked') || !jeab_kontakt_address_blocked($email))) {
        $index['email'][$email] = $id;
    }
    $phone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($row) : '';
    if ($phone !== '' && !isset($index['phone'][$phone]) && (!function_exists('jeab_kontakt_phone_blocked') || !jeab_kontakt_phone_blocked($phone))) {
        $index['phone'][$phone] = $id;
    }
}

/**
 * @param array<string, mixed> $row
 * @return array{contact_id:string,name:string,email:string,phone:string,street:string,street_key:string,postal:string,city:string}
 */
function jeab_contacts_sole_hint(string $kind, array $row): array
{
    if ($kind === 'order') {
        $name = trim((string) ($row['huvudsokande'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['name'] ?? ''));
        }
        if ($name === '') {
            $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
            $name = trim((string) ($applicant['name'] ?? ''));
        }
        if ($name === '') {
            $name = trim((string) ($row['customer_name'] ?? ''));
        }
        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        if ($address === '') {
            $address = trim((string) ($row['huvudsokande_address'] ?? $row['gata'] ?? ''));
        }
        $city = trim((string) ($row['ort'] ?? $row['city'] ?? ''));
        $postal = trim((string) ($row['postnummer'] ?? $row['postnr'] ?? $row['postal'] ?? ''));
    } elseif ($kind === 'booking') {
        $name = trim((string) ($row['customer_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        $city = '';
        $postal = '';
    } else {
        $name = trim((string) ($row['customer_name'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $address = trim((string) ($row['address'] ?? ''));
        $city = trim((string) ($row['ort'] ?? ''));
        $postal = trim((string) ($row['postal'] ?? ''));
    }
    $parsed = function_exists('jeab_kontakt_parse_address') ? jeab_kontakt_parse_address($address) : ['street' => $address, 'street_key' => '', 'postal' => '', 'city' => ''];
    $street = trim((string) ($parsed['street'] ?? ''));
    if ($street === '' && $kind === 'order') {
        $street = trim((string) ($row['gata'] ?? ''));
    }
    if ($city === '') {
        $city = trim((string) ($parsed['city'] ?? ''));
    }
    if ($postal === '') {
        $postal = trim((string) ($parsed['postal'] ?? ''));
    }
    if (function_exists('jeab_kontakt_email')) {
        $email = jeab_kontakt_email($email);
    }
    if ($email !== '' && function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email)) {
        $email = '';
    }
    if (function_exists('jeab_kontakt_phone')) {
        $phone = jeab_kontakt_phone($phone);
    }
    if ($phone !== '' && function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    if (function_exists('jeab_kontakt_city_name')) {
        $city = jeab_kontakt_city_name($city);
    }
    if (function_exists('jeab_kontakt_postal')) {
        $postal = jeab_kontakt_postal($postal);
    }
    $streetKey = trim((string) ($parsed['street_key'] ?? ''));
    if ($streetKey === '' && $street !== '' && function_exists('jeab_kontakt_street_key')) {
        $streetKey = jeab_kontakt_street_key($street);
    }

    return [
        'contact_id' => trim((string) ($row['contact_id'] ?? '')),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'street' => $street,
        'street_key' => $streetKey,
        'postal' => $postal,
        'city' => $city,
    ];
}

/**
 * Befintlig länk, sedan mejl, telefon och adress.
 *
 * @param array<string, mixed> $contacts
 * @param array{email:array<string,string>,phone:array<string,string>} $index
 * @param array{contact_id:string,name:string,email:string,phone:string,street:string,street_key:string,postal:string,city:string} $hint
 */
function jeab_contacts_sole_resolve(array $contacts, array $index, array $hint): ?string
{
    $existing = $hint['contact_id'];
    if ($existing !== '' && isset($contacts[$existing]) && is_array($contacts[$existing])) {
        return $existing;
    }
    if ($hint['email'] !== '' && isset($index['email'][$hint['email']])) {
        return $index['email'][$hint['email']];
    }
    if ($hint['phone'] !== '' && isset($index['phone'][$hint['phone']])) {
        return $index['phone'][$hint['phone']];
    }
    if ($hint['street_key'] !== '' && function_exists('jeab_kontakt_find')) {
        $found = jeab_kontakt_find($contacts, $hint['street_key'], $hint['postal'], '', '', true, $hint['name'], $hint['city']);
        if (is_string($found) && $found !== '') {
            return $found;
        }
    }

    return null;
}

/**
 * @param array{contact_id:string,name:string,email:string,phone:string,street:string,street_key:string,postal:string,city:string} $hint
 * @return array<string, mixed>|null
 */
function jeab_contacts_sole_create(array $hint): ?array
{
    if (!function_exists('jeab_kontakt_blank') || !function_exists('jeab_kontakter_mutate')) {
        return null;
    }
    $made = jeab_kontakt_blank([
        'name' => $hint['name'],
        'email' => $hint['email'],
        'phone' => $hint['phone'],
        'street' => $hint['street'],
        'street_key' => $hint['street_key'],
        'postal_code' => $hint['postal'],
        'city' => $hint['city'],
        'source' => 'contacts_sole',
    ]);
    $saved = jeab_kontakter_mutate(static function (array &$contacts) use ($made): ?array {
        $id = trim((string) ($made['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $contacts[$id] = $made;

        return $made;
    });
    if (is_array($saved)) {
        $createdId = trim((string) ($saved['id'] ?? ''));
        jeab_contacts_sole_cache_forget($createdId !== '' ? $createdId : null);
    }

    return is_array($saved) ? $saved : null;
}

function jeab_contacts_sole_write_link(JeabStore $store, string $kind, int $id, string $contactId): bool
{
    return jeab_contacts_sole_stamp($store, $kind, $id, $contactId, null);
}

/**
 * Sätter bara contact_id. updated_at lämnas. $expect null betyder att fältet måste vara tomt.
 */
function jeab_contacts_sole_stamp(JeabStore $store, string $kind, int $id, string $next, ?string $expect): bool
{
    if ($id <= 0 || ($expect === null && $next === '')) {
        return false;
    }
    if ($kind === 'order') {
        return jeab_contacts_sole_stamp_order($id, $next, $expect);
    }
    if ($kind !== 'reminder' && $kind !== 'booking' || !$store instanceof JeabPdoStore) {
        return false;
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return false;
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return false;
    }
    $table = $kind === 'booking' ? 'bookings' : 'reminders';
    if ($expect === null) {
        $stmt = $pdo->prepare('UPDATE ' . $table . ' SET contact_id = ? WHERE id = ? AND (contact_id IS NULL OR contact_id = \'\')');
        $stmt->execute([$next, $id]);
    } else {
        $stmt = $pdo->prepare('UPDATE ' . $table . ' SET contact_id = ? WHERE id = ? AND contact_id = ?');
        $stmt->execute([$next, $id, $expect]);
    }

    return $stmt->rowCount() === 1;
}

function jeab_contacts_sole_stamp_order(int $id, string $next, ?string $expect): bool
{
    $pdo = function_exists('jeab_purchase_sql_pdo') ? jeab_purchase_sql_pdo() : null;
    if (!$pdo instanceof PDO || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return false;
    }

    return jeab_contacts_sole_stamp_order_sql($pdo, $id, $next, $expect);
}

function jeab_contacts_sole_stamp_order_sql(PDO $pdo, int $id, string $next, ?string $expect): bool
{
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT payload FROM purchases WHERE reminder_id = ?' . ($mysql ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $payload = $stmt->fetchColumn();
        $row = is_string($payload) ? json_decode($payload, true) : null;
        if (!is_array($row)) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        }
        $current = jeab_contacts_sole_cid($row['contact_id'] ?? null);
        if (($expect === null && $current !== '') || ($expect !== null && $current !== $expect)) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        }
        $row['contact_id'] = $next;
        $json = json_encode($row, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        }
        $listJson = function_exists('jeab_sql_purchases_list_json') ? jeab_sql_purchases_list_json($row) : $json;
        $pdo->prepare('UPDATE purchases SET payload = ?, contact_id = ?, contact_indexed = 1, list_json = ? WHERE reminder_id = ?')->execute([
            $json,
            mb_substr($next, 0, 64),
            $listJson,
            $id,
        ]);
        if ($own) {
            $pdo->commit();
        }
        if (function_exists('jeab_purchase_cache_note')) {
            jeab_purchase_cache_note($id, $row);
        }

        return true;
    } catch (Throwable) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
}

/**
 * @return array{counts:array<string,int>,ids:array<string,list<int>>,links:array<string,array<string,string>>}
 */
function jeab_contacts_sole_census(JeabStore $store): array
{
    return [
        'counts' => jeab_contacts_sole_counts($store),
        'ids' => jeab_contacts_sole_ids($store),
        'links' => jeab_contacts_sole_link_map($store),
    ];
}

/**
 * @return array{reminders:array<string,string>,orders:array<string,string>,bookings:array<string,string>}
 */
function jeab_contacts_sole_link_map(JeabStore $store): array
{
    $links = ['reminders' => [], 'orders' => [], 'bookings' => []];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $links['reminders'][(string) (int) ($row['id'] ?? 0)] = jeab_contacts_sole_cid($row['contact_id'] ?? null);
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $links['orders'][(string) (int) ($row['reminder_id'] ?? 0)] = jeab_contacts_sole_cid($row['contact_id'] ?? null);
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $links['bookings'][(string) (int) ($row['id'] ?? 0)] = jeab_contacts_sole_cid($row['contact_id'] ?? null);
        }
    }

    return $links;
}

/**
 * @param array<string, mixed> $beforeLinks
 */
function jeab_contacts_sole_links_kept(JeabStore $store, array $beforeLinks): bool
{
    $now = jeab_contacts_sole_link_map($store);
    foreach (['reminders', 'orders', 'bookings'] as $kind) {
        $prev = is_array($beforeLinks[$kind] ?? null) ? $beforeLinks[$kind] : [];
        $have = is_array($now[$kind] ?? null) ? $now[$kind] : [];
        foreach ($prev as $id => $cid) {
            $was = jeab_contacts_sole_cid($cid);
            $current = jeab_contacts_sole_cid($have[(string) $id] ?? null);
            if (!array_key_exists((string) $id, $have) && !array_key_exists((int) $id, $have)) {
                return false;
            }
            if ($was !== '' && $current !== $was) {
                return false;
            }
            if ($was === '' && $current === '') {
                return false;
            }
        }
    }

    return true;
}

function jeab_contacts_sole_batch(): string
{
    return bin2hex(random_bytes(8));
}

/** Tom sträng när dry=0 får skriva. Annars ett fel som stoppar körningen. */
function jeab_contacts_sole_write_error(JeabStore $store): string
{
    $pdo = jeab_contacts_sole_undo_pdo($store);
    if (!$pdo instanceof PDO || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return 'kontakter.sole dry=0 kräver MySQL. JSON-läget kan bara köras torrt.';
    }
    $orders = function_exists('jeab_purchase_sql_pdo') ? jeab_purchase_sql_pdo() : null;
    if (!$orders instanceof PDO || $orders->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        return 'kontakter.sole dry=0 kräver att ordrarna ligger i MySQL. Inget skrevs.';
    }
    if (!function_exists('jeab_kontakter_writes_sql_only') || !jeab_kontakter_writes_sql_only()) {
        return 'kontakter.sole dry=0 kräver att kontakterna ligger i MySQL. Inget skrevs.';
    }

    return '';
}

function jeab_contacts_sole_undo_pdo(?JeabStore $store = null): ?PDO
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabPdoStore) {
        return null;
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return null;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'mysql' && $driver !== 'sqlite') {
        return null;
    }
    if (function_exists('jeab_ensure_contacts_sole_undo')) {
        jeab_ensure_contacts_sole_undo($pdo, $driver);
    }

    return $pdo;
}

function jeab_contacts_sole_undo_scrub(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $pnrKeys = ['personnummer', 'pnr', 'SocialSecurityNumber', 'owner_pnr', 'huvudsokande_personnummer', 'objekt_personnummer', 'medsokande_personnummer'];
    $dropKeys = ['personnummer_finns', 'OrganisationNumber', 'orgnr', 'organisationsnummer'];
    $out = [];
    foreach ($value as $key => $item) {
        $name = (string) $key;
        if ($name === 'pnr_hmac') {
            if (is_string($item) && preg_match('/\A[0-9a-f]{64}\z/', $item) === 1) {
                $out[$key] = $item;
            }
            continue;
        }
        if (in_array($name, $dropKeys, true)) {
            continue;
        }
        if ($name === 'owner_pnr' && is_array($item)) {
            $list = [];
            foreach ($item as $piece) {
                if (is_string($piece) || is_numeric($piece)) {
                    $hmac = jeab_contacts_sole_pnr_hmac((string) $piece);
                    $list[] = $hmac !== '' ? ['pnr_hmac' => $hmac] : '';
                    continue;
                }
                $list[] = jeab_contacts_sole_undo_scrub($piece);
            }
            $out[$key] = $list;
            continue;
        }
        if (in_array($name, $pnrKeys, true)) {
            if (is_string($item) || is_numeric($item)) {
                $hmac = jeab_contacts_sole_pnr_hmac((string) $item);
                if ($hmac !== '' && !isset($out['pnr_hmac'])) {
                    $out['pnr_hmac'] = $hmac;
                }
            }
            continue;
        }
        $out[$key] = jeab_contacts_sole_undo_scrub($item);
    }

    return $out;
}

/** @param array<string, mixed>|null $value */
function jeab_contacts_sole_undo_json(?array $value): string
{
    if ($value === null) {
        return 'null';
    }
    $clean = jeab_contacts_sole_undo_scrub($value);
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : 'null';
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_undo_contact(array $row): array
{
    $clean = jeab_contacts_sole_undo_scrub($row);

    return is_array($clean) ? $clean : [];
}

/** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
function jeab_contacts_sole_undo_add(string $batch, int $seq, string $kind, string $entity, string $id, ?array $before, ?array $after): bool
{
    if ($batch === '') {
        return false;
    }
    $pdo = jeab_contacts_sole_undo_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $beforeJson = jeab_contacts_sole_undo_json($before);
    $afterJson = jeab_contacts_sole_undo_json($after);
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')
        ->execute([$batch, $seq, $kind, $entity, $id, $beforeJson, $afterJson, $at]);

    return true;
}

/** @return list<array<string, mixed>> */
function jeab_contacts_sole_undo_all(): array
{
    $pdo = jeab_contacts_sole_undo_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $rows = $pdo->query('SELECT id, batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone FROM contacts_sole_undo ORDER BY id ASC')->fetchAll();
    } catch (Throwable) {
        return [];
    }

    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function jeab_contacts_sole_snapshot(): ?array
{
    $found = null;
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if ((string) ($row['kind'] ?? '') !== 'snapshot' || (int) ($row['undone'] ?? 0) === 1) {
            continue;
        }
        $found = $row;
    }
    if (!is_array($found)) {
        return null;
    }
    $data = json_decode((string) ($found['before_json'] ?? ''), true);

    return is_array($data) ? $data : null;
}

function jeab_contacts_sole_snapshot_address_text(string $address): string
{
    $address = trim($address);
    if ($address === '') {
        return '';
    }
    $stripped = preg_replace('/\d{6,8}-?\d{4}/', '', $address);
    $address = trim(preg_replace('/\s+/u', ' ', is_string($stripped) ? $stripped : '') ?? '');

    return $address;
}

/**
 * Adress för en order som fanns i ögonblicksbilden. Bara gata.
 */
function jeab_contacts_sole_snapshot_order_address(?JeabStore $store, int $id, array $snap): string
{
    $address = '';
    if ($store instanceof JeabStore && function_exists('jeab_get_reminder')) {
        $reminder = jeab_get_reminder($store, $id);
        if (is_array($reminder)) {
            if (function_exists('jeab_ordrar_vunnen_source')) {
                $source = jeab_ordrar_vunnen_source($reminder);
                $address = trim((string) ($source['address'] ?? ''));
            }
            if ($address === '') {
                $address = trim((string) ($reminder['address'] ?? ''));
            }
        }
    }
    if ($address === '') {
        $links = is_array($snap['links']['orders'] ?? null) ? $snap['links']['orders'] : [];
        $cid = trim((string) ($links[(string) $id] ?? ''));
        if ($cid !== '' && function_exists('jeab_kontakt_get')) {
            $contact = jeab_kontakt_get($cid);
            if (is_array($contact)) {
                $address = trim((string) ($contact['street'] ?? $contact['gata'] ?? ''));
                if ($address === '') {
                    $address = trim((string) ($contact['address'] ?? ''));
                }
            }
        }
    }

    return jeab_contacts_sole_snapshot_address_text($address);
}

/**
 * kontakter.sole_snapshot_diff. Bara läsning.
 * Order-id som finns i ögonblicksbilden (ångerloggen) men inte bland ordrarna nu.
 * Adress och ordrar_deleted (at, hs, booking_id). Inget personnummer.
 *
 * @return array<string, mixed>
 */
function jeab_contacts_sole_snapshot_diff(?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    $snap = jeab_contacts_sole_snapshot();
    $live = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['reminder_id'] ?? 0);
            if ($id > 0) {
                $live[$id] = true;
            }
        }
    }
    $deletedDoc = function_exists('jeab_ordrar_deleted_doc') ? jeab_ordrar_deleted_doc() : ['ids' => []];
    $deletedIds = is_array($deletedDoc['ids'] ?? null) ? $deletedDoc['ids'] : [];
    $orders = [];
    $seen = [];
    $snapIds = is_array($snap) && is_array($snap['ids']['orders'] ?? null) ? $snap['ids']['orders'] : [];
    foreach ($snapIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0 || isset($seen[$id]) || isset($live[$id])) {
            continue;
        }
        $seen[$id] = true;
        $entry = $deletedIds[(string) $id] ?? null;
        $tomb = null;
        if (is_array($entry)) {
            $hs = [];
            foreach (is_array($entry['hs'] ?? null) ? $entry['hs'] : [] as $one) {
                $hsId = trim((string) $one);
                if ($hsId !== '') {
                    $hs[] = $hsId;
                }
            }
            $tomb = [
                'at' => trim((string) ($entry['at'] ?? '')),
                'hs' => $hs,
                'booking_id' => (int) ($entry['booking_id'] ?? 0),
            ];
        }
        $orders[] = [
            'id' => $id,
            'address' => jeab_contacts_sole_snapshot_order_address($store instanceof JeabStore ? $store : null, $id, is_array($snap) ? $snap : []),
            'ordrar_deleted' => $tomb,
        ];
    }

    return jeab_contacts_sole_public([
        'ok' => true,
        'action' => 'kontakter.sole_snapshot_diff',
        'read_only' => true,
        'dry' => true,
        'written' => false,
        'snapshot' => is_array($snap),
        'orders' => $orders,
        'count' => count($orders),
    ]);
}

function jeab_contacts_sole_latest_batch(): string
{
    $batch = '';
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if ((int) ($row['undone'] ?? 0) === 1 || (string) ($row['kind'] ?? '') !== 'snapshot') {
            continue;
        }
        $batch = (string) ($row['batch_id'] ?? '');
    }

    return $batch;
}

function jeab_contacts_sole_undo_mark(string $batch): void
{
    $pdo = jeab_contacts_sole_undo_pdo();
    if ($pdo instanceof PDO) {
        $pdo->prepare('UPDATE contacts_sole_undo SET undone = 1 WHERE batch_id = ?')->execute([$batch]);
    }
}

/**
 * Återställda ägare får personnumret från den nuvarande ägare vars
 * normaliserade nummer har samma HMAC. Annars från exakt en namnträff.
 * Ett tomt värde skriver inte över ett som finns, utom när före-bilden har pnr_clear.
 * pnr_hmac skrivs inte tillbaka.
 *
 * @param list<array<string, mixed>> $restored
 * @param list<array<string, mixed>> $current
 * @return list<array<string, mixed>>
 */
function jeab_contacts_sole_undo_owners_keep_pnr(array $restored, array $current): array
{
    $sole = jeab_contacts_sole();
    $keep = static function (string $raw) use ($sole): string {
        return $sole ? jeab_contacts_sole_pnr_store_value($raw) : jeab_contacts_sole_pnr_text($raw);
    };
    $byName = [];
    foreach ($current as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $key = jeab_contacts_sole_name_key((string) ($owner['name'] ?? $owner['namn'] ?? ''));
        if ($key === '') {
            continue;
        }
        $byName[$key][] = $owner;
    }
    $out = [];
    foreach ($restored as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $hmac = trim((string) ($owner['pnr_hmac'] ?? ''));
        unset($owner['pnr_hmac']);
        if (!empty($owner['pnr_clear'])) {
            unset($owner['pnr_clear'], $owner['personnummer']);
            $out[] = $owner;
            continue;
        }
        $have = $keep((string) ($owner['personnummer'] ?? ''));
        $live = '';
        if ($sole && $hmac !== '') {
            foreach ($current as $cand) {
                if (!is_array($cand)) {
                    continue;
                }
                $candPnr = $keep((string) ($cand['personnummer'] ?? ''));
                $candHmac = jeab_contacts_sole_pnr_hmac($candPnr);
                if ($candHmac !== '' && strlen($candHmac) === strlen($hmac) && hash_equals($hmac, $candHmac)) {
                    $live = $candPnr;
                    break;
                }
            }
        }
        if ($live === '') {
            $key = jeab_contacts_sole_name_key((string) ($owner['name'] ?? $owner['namn'] ?? ''));
            $matches = ($key !== '' && isset($byName[$key])) ? $byName[$key] : [];
            if (count($matches) === 1) {
                $live = $keep((string) ($matches[0]['personnummer'] ?? ''));
            }
        }
        if ($live !== '') {
            $owner['personnummer'] = $live;
        } elseif ($have !== '') {
            $owner['personnummer'] = $have;
        } else {
            unset($owner['personnummer']);
        }
        $out[] = $owner;
    }

    return $out;
}

/**
 * Kontaktens eget personnummer. HMAC mot nuvarande nummer först, annars
 * före-bildens värde när det finns. Tomt skrivs inte. null lämnar fältet orört.
 *
 * @param list<array<string, mixed>> $currentOwners
 */
function jeab_contacts_sole_undo_pnr_restore(string $hmac, string $have, array $currentOwners, ?string $liveTop): ?string
{
    $sole = jeab_contacts_sole();
    $keep = static function (string $raw) use ($sole): string {
        return $sole ? jeab_contacts_sole_pnr_store_value($raw) : jeab_contacts_sole_pnr_text($raw);
    };
    $hmac = trim($hmac);
    if ($sole && $hmac !== '') {
        $cands = [];
        if ($liveTop !== null) {
            $cands[] = $liveTop;
        }
        foreach ($currentOwners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $cands[] = (string) ($owner['personnummer'] ?? '');
        }
        foreach ($cands as $cand) {
            $stored = $keep($cand);
            $candHmac = jeab_contacts_sole_pnr_hmac($stored);
            if ($candHmac !== '' && strlen($candHmac) === strlen($hmac) && hash_equals($hmac, $candHmac)) {
                return $stored;
            }
        }
    }
    $snapshot = $keep($have);
    if ($snapshot !== '') {
        return $snapshot;
    }

    return null;
}

/** Antal påminnelser, ordrar och bokningar som pekar på kontakten. */
function jeab_contacts_sole_contact_ref_count(JeabStore $store, string $contactId): int
{
    $contactId = trim($contactId);
    if ($contactId === '') {
        return 0;
    }
    $n = 0;
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row) && jeab_contacts_sole_cid($row['contact_id'] ?? null) === $contactId) {
            $n++;
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row) && jeab_contacts_sole_cid($row['contact_id'] ?? null) === $contactId) {
                $n++;
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row) && jeab_contacts_sole_cid($row['contact_id'] ?? null) === $contactId) {
            $n++;
        }
    }

    return $n;
}

/**
 * Återställer ägare från ångerloggens före-bild. Tomt personnummer skrivs tomt.
 * Ett nummer som fanns kvar och har samma HMAC behålls. Numret skrivs inte i svaret.
 *
 * @param list<array<string, mixed>> $beforeOwners
 * @param list<array<string, mixed>> $currentOwners
 * @return list<array<string, mixed>>
 */
function jeab_contacts_sole_undo_owner_fields(array $beforeOwners, array $currentOwners): array
{
    $out = [];
    foreach ($beforeOwners as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $hmac = trim((string) ($owner['pnr_hmac'] ?? ''));
        unset($owner['pnr_hmac']);
        $live = '';
        $current = $currentOwners[$i] ?? null;
        if ($hmac !== '' && is_array($current)) {
            $cand = (string) ($current['personnummer'] ?? '');
            $candHmac = jeab_contacts_sole_pnr_hmac($cand);
            if ($candHmac !== '' && strlen($candHmac) === strlen($hmac) && hash_equals($hmac, $candHmac)) {
                $live = $cand;
            }
        }
        $owner['personnummer'] = $live;
        $out[] = $owner;
    }

    return $out;
}

/** Sätter updated_at till det värde som gällde före en ångrad batch. */
function jeab_contacts_sole_set_updated_at(string $contactId, string $updatedAt): void
{
    $contactId = trim($contactId);
    if ($contactId === '' || !function_exists('jeab_kontakter_mutate')) {
        return;
    }
    jeab_kontakter_mutate(static function (array &$contacts) use ($contactId, $updatedAt): void {
        if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
            return;
        }
        $contacts[$contactId]['id'] = $contactId;
        $contacts[$contactId]['updated_at'] = $updatedAt;
    });
}

/**
 * Lägger tillbaka det äldsta sparade updated_at per kontakt i batchen.
 *
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_sole_undo_restore_clocks(array $rows): void
{
    $best = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = (string) ($row['kind'] ?? '');
        if (!in_array($kind, ['paint', 'owner', 'property'], true)) {
            continue;
        }
        $before = json_decode((string) ($row['before_json'] ?? ''), true);
        if (!is_array($before) || !array_key_exists('updated_at', $before)) {
            continue;
        }
        $id = $kind === 'paint'
            ? trim((string) ($before['contact_id'] ?? ''))
            : trim((string) ($row['entity_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $seq = (int) ($row['seq'] ?? 0);
        if (!isset($best[$id]) || $seq < $best[$id]['seq']) {
            $best[$id] = ['seq' => $seq, 'at' => trim((string) $before['updated_at'])];
        }
    }
    foreach ($best as $id => $item) {
        jeab_contacts_sole_set_updated_at((string) $id, (string) $item['at']);
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_sole_undo(array $input, ?JeabStore $store = null): array
{
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore) {
        return ['ok' => false, 'error' => 'Ingen lagring.', 'dry' => true, 'written' => false];
    }
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $batch = trim((string) ($input['batch_id'] ?? ''));
    if ($batch === '') {
        $batch = jeab_contacts_sole_latest_batch();
    }
    $rows = [];
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if ((string) ($row['batch_id'] ?? '') === $batch && (int) ($row['undone'] ?? 0) === 0) {
            $rows[] = $row;
        }
    }
    $propertyOnly = $rows !== [];
    $gapOnly = $rows !== [];
    foreach ($rows as $row) {
        $undoKind = (string) ($row['kind'] ?? '');
        if ($undoKind !== 'property') {
            $propertyOnly = false;
        }
        if ($undoKind !== 'gap') {
            $gapOnly = false;
        }
    }
    if (!$dry && !$propertyOnly && !$gapOnly) {
        $block = jeab_contacts_sole_write_error($store);
        if ($block !== '') {
            return jeab_contacts_sole_public([
                'ok' => false,
                'dry' => false,
                'written' => false,
                'error' => $block,
            ]);
        }
    }
    if ($batch === '' || $rows === []) {
        return jeab_contacts_sole_public([
            'ok' => false,
            'dry' => $dry,
            'written' => false,
            'batch_id' => $batch,
            'error' => 'Ingen körning att ångra.',
        ]);
    }
    usort($rows, static fn(array $a, array $b): int => (int) ($b['seq'] ?? 0) <=> (int) ($a['seq'] ?? 0));
    $links = 0;
    $contacts = 0;
    $properties = 0;
    $owners = 0;
    $snapshots = 0;
    $paints = 0;
    foreach ($rows as $row) {
        $kind = (string) ($row['kind'] ?? '');
        if ($kind === 'link') {
            $links++;
        } elseif ($kind === 'contact') {
            $contacts++;
        } elseif ($kind === 'property') {
            $properties++;
        } elseif ($kind === 'owner') {
            $owners++;
        } elseif ($kind === 'snapshot') {
            $snapshots++;
        } elseif ($kind === 'paint') {
            $paints++;
        } elseif ($kind === 'gap') {
            $contacts++;
        }
    }
    if ($dry) {
        return jeab_contacts_sole_public([
            'ok' => true,
            'dry' => true,
            'written' => false,
            'batch_id' => $batch,
            'links' => $links,
            'contacts' => $contacts,
            'properties' => $properties,
            'owners' => $owners,
            'snapshots' => $snapshots,
            'paints' => $paints,
        ]);
    }
    $links = 0;
    $contacts = 0;
    $properties = 0;
    $owners = 0;
    $snapshots = 0;
    $paints = 0;
    $linkFailed = false;
    $keptReferenced = [];
    foreach ($rows as $row) {
        $kind = (string) ($row['kind'] ?? '');
        if ($kind === 'snapshot') {
            $snapshots++;
            continue;
        }
        if ($kind === 'paint') {
            $before = json_decode((string) ($row['before_json'] ?? ''), true);
            $contactId = is_array($before) ? trim((string) ($before['contact_id'] ?? '')) : '';
            $slots = is_array($before) && is_array($before['slots'] ?? null) ? $before['slots'] : [];
            $clean = [];
            foreach ($slots as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $clean[$key] = $value;
                }
            }
            if ($contactId !== '' && jeab_contacts_sole_paint_apply($contactId, $clean)) {
                $paints++;
            }
            continue;
        }
        if ($kind === 'owner') {
            $before = json_decode((string) ($row['before_json'] ?? ''), true);
            $contactId = trim((string) ($row['entity_id'] ?? ''));
            if ($contactId === '' || !is_array($before) || !is_array($before['owners'] ?? null) || !function_exists('jeab_fastighet_write_contact')) {
                continue;
            }
            $live = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
            $currentOwners = [];
            if (is_array($live)) {
                foreach (is_array($live['owners'] ?? null) ? $live['owners'] : [] as $liveOwner) {
                    if (is_array($liveOwner)) {
                        $currentOwners[] = $liveOwner;
                    }
                }
            }
            $sliceOwners = jeab_contacts_sole_undo_owner_fields(
                array_values(array_filter($before['owners'], 'is_array')),
                $currentOwners
            );
            if (jeab_contacts_sole()) {
                jeab_contacts_sole_pnr_digits_push();
            }
            try {
                $wroteOwner = jeab_fastighet_write_contact($contactId, ['owners' => $sliceOwners]);
            } finally {
                if (jeab_contacts_sole()) {
                    jeab_contacts_sole_pnr_digits_pop();
                }
            }
            if ($wroteOwner) {
                $owners++;
            }
            continue;
        }
        if ($kind === 'property') {
            $before = json_decode((string) ($row['before_json'] ?? ''), true);
            $contactId = trim((string) ($row['entity_id'] ?? ''));
            if ($contactId === '' || !is_array($before) || !function_exists('jeab_fastighet_write_contact')) {
                continue;
            }
            $slice = ['id' => $contactId];
            $live = function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
            $currentOwners = [];
            if (is_array($live)) {
                foreach (is_array($live['owners'] ?? null) ? $live['owners'] : [] as $liveOwner) {
                    if (is_array($liveOwner)) {
                        $currentOwners[] = $liveOwner;
                    }
                }
            }
            if (array_key_exists('fastighetsbeteckning', $before)) {
                $slice['fastighetsbeteckning'] = (string) $before['fastighetsbeteckning'];
            }
            if (array_key_exists('owners', $before) && is_array($before['owners'])) {
                $slice['owners'] = jeab_contacts_sole_undo_owners_keep_pnr(
                    array_values(array_filter($before['owners'], 'is_array')),
                    $currentOwners
                );
            }
            $rootHmac = trim((string) ($before['pnr_hmac'] ?? ''));
            if (array_key_exists('personnummer', $before) || $rootHmac !== '') {
                $restoredTop = jeab_contacts_sole_undo_pnr_restore(
                    $rootHmac,
                    array_key_exists('personnummer', $before) ? (string) $before['personnummer'] : '',
                    $currentOwners,
                    is_array($live) && array_key_exists('personnummer', $live) ? (string) $live['personnummer'] : null
                );
                if ($restoredTop !== null) {
                    $slice['personnummer'] = $restoredTop;
                }
            }
            if (jeab_contacts_sole()) {
                jeab_contacts_sole_pnr_digits_push();
            }
            try {
                $wroteProperty = jeab_fastighet_write_contact($contactId, $slice);
            } finally {
                if (jeab_contacts_sole()) {
                    jeab_contacts_sole_pnr_digits_pop();
                }
            }
            if ($wroteProperty) {
                $properties++;
            }
            continue;
        }
        if ($kind === 'gap') {
            if (function_exists('jeab_contacts_gap_restore') && jeab_contacts_gap_restore($row)) {
                $contacts++;
            }
            continue;
        }
        if ($kind === 'prune_orphan' && function_exists('jeab_kontakter_mutate')) {
            $contactId = (string) ($row['entity_id'] ?? '');
            $before = json_decode((string) ($row['before_json'] ?? ''), true);
            $live = $contactId !== '' && function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
            if ($contactId !== '' && is_array($before) && !is_array($live)) {
                $before['id'] = $contactId;
                jeab_kontakter_mutate(static function (array &$all) use ($contactId, $before): bool {
                    if (isset($all[$contactId])) {
                        return false;
                    }
                    $all[$contactId] = $before;

                    return true;
                }, true);
                $contacts++;
            }
            continue;
        }
        if ($kind === 'link') {
            $after = json_decode((string) ($row['after_json'] ?? ''), true);
            $before = json_decode((string) ($row['before_json'] ?? ''), true);
            $expect = is_array($after) ? jeab_contacts_sole_cid($after['contact_id'] ?? null) : '';
            $back = is_array($before) ? jeab_contacts_sole_cid($before['contact_id'] ?? null) : '';
            $id = (int) ($row['entity_id'] ?? 0);
            if ($expect !== '' && jeab_contacts_sole_stamp($store, (string) ($row['entity'] ?? ''), $id, $back, $expect)) {
                $links++;
            } elseif ($expect !== '') {
                $linkFailed = true;
            }
            continue;
        }
        if ($kind !== 'contact' || !function_exists('jeab_kontakter_mutate')) {
            continue;
        }
        $contactId = (string) ($row['entity_id'] ?? '');
        if ($contactId === '') {
            continue;
        }
        $refs = jeab_contacts_sole_contact_ref_count($store, $contactId);
        if ($refs > 0 || $linkFailed) {
            $keptReferenced[] = $contactId;
            continue;
        }
        // En kontakt som körningen skapade ska bort även i ett litet register.
        // Spärren för massradering skulle annars lämna den kvar.
        jeab_kontakter_mutate(static function (array &$all) use ($contactId): bool {
            unset($all[$contactId]);

            return true;
        }, true);
        $contacts++;
    }
    jeab_contacts_sole_undo_restore_clocks($rows);
    jeab_contacts_sole_undo_mark($batch);
    if (function_exists('jeab_reminder_contacts_bump')) {
        jeab_reminder_contacts_bump();
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    jeab_contacts_sole_cache_forget(null);

    return jeab_contacts_sole_public([
        'ok' => true,
        'dry' => false,
        'written' => true,
        'batch_id' => $batch,
        'links' => $links,
        'contacts' => $contacts,
        'properties' => $properties,
        'owners' => $owners,
        'snapshots' => $snapshots,
        'paints' => $paints,
        'kept_referenced' => $keptReferenced,
    ]);
}

/**
 * Delar en sammanslagen adress i gata, postnummer och ort.
 * address_extract fyller bara saknad ort eller postnummer när bolag och gata finns.
 * Telefonen lämnas orörd.
 *
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_contacts_sole_inflow_place(array $hint, bool $extract = true): array
{
    $phone = trim((string) ($hint['phone'] ?? ''));
    $street = trim((string) ($hint['street'] ?? $hint['gata'] ?? ''));
    $city = trim((string) ($hint['city'] ?? $hint['ort'] ?? ''));
    $postal = trim((string) ($hint['postal_code'] ?? $hint['postal'] ?? $hint['postnr'] ?? $hint['postnummer'] ?? ''));
    $address = trim((string) ($hint['address'] ?? ''));
    if (($street === '' || $city === '' || $postal === '') && $address !== '') {
        $parts = function_exists('jeab_address_parts')
            ? jeab_address_parts($address)
            : (function_exists('jeab_kontakt_parse_address') ? jeab_kontakt_parse_address($address) : ['street' => $address, 'city' => '', 'postal' => '']);
        if ($street === '') {
            $street = trim((string) ($parts['street'] ?? ''));
        }
        if ($city === '') {
            $city = trim((string) ($parts['city'] ?? ''));
        }
        if ($postal === '') {
            $postal = trim((string) ($parts['postal'] ?? ''));
        }
    }
    $company = trim((string) ($hint['company'] ?? ''));
    if ($extract && $company !== '' && $street !== '' && ($city === '' || $postal === '') && function_exists('jeab_address_extract')) {
        try {
            $extracted = jeab_address_extract([
                'company' => $company,
                'street' => $street,
                'city' => $city,
                'postal' => $postal,
                'name' => trim((string) ($hint['name'] ?? '')),
                'thread_id' => trim((string) ($hint['thread_id'] ?? '')),
            ]);
        } catch (Throwable) {
            $extracted = [];
        }
        if (!empty($extracted['certain']) && empty($extracted['review'])) {
            $extractedCity = trim((string) ($extracted['city'] ?? ''));
            $extractedPostal = trim((string) ($extracted['postal'] ?? ''));
            if ($city === '' && $extractedCity !== '') {
                $city = $extractedCity;
            }
            if ($postal === '' && $extractedPostal !== '') {
                $postal = $extractedPostal;
            }
        }
    }
    $hint['phone'] = $phone;
    $hint['street'] = $street;
    $hint['city'] = $city;
    $hint['postal_code'] = $postal;
    if ($address === '' && $street !== '') {
        $hint['address'] = trim($street . ($postal !== '' || $city !== '' ? ', ' . trim($postal . ' ' . $city) : ''));
    }

    return $hint;
}

/**
 * Hittar eller skapar en kontakt. Flaggan av ger null och skriver inget.
 *
 * @param array<string, mixed> $hint
 * @return array<string, mixed>|null
 */
/**
 * @param array<string, mixed> $hint
 * @return list<array{name:string,pnr:string}>
 */
function jeab_contacts_sole_pnr_hint_jobs(array $hint): array
{
    $jobs = [];
    $top = '';
    foreach (['personnummer', 'pnr'] as $key) {
        if (!array_key_exists($key, $hint)) {
            continue;
        }
        $stored = jeab_contacts_sole_pnr_store_value((string) $hint[$key]);
        if ($stored !== '') {
            $top = $stored;
        }
    }
    if ($top !== '') {
        $jobs[] = [
            'name' => jeab_contacts_sole_name_key((string) ($hint['name'] ?? '')),
            'pnr' => $top,
        ];
    }
    foreach (['owners', 'medsokande'] as $listKey) {
        if (!isset($hint[$listKey]) || !is_array($hint[$listKey])) {
            continue;
        }
        foreach ($hint[$listKey] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $stored = jeab_contacts_sole_pnr_store_value((string) ($person['personnummer'] ?? $person['pnr'] ?? ''));
            if ($stored === '') {
                continue;
            }
            $jobs[] = [
                'name' => jeab_contacts_sole_name_key((string) ($person['name'] ?? $person['namn'] ?? '')),
                'pnr' => $stored,
            ];
        }
    }

    return $jobs;
}

/**
 * @param list<array<string, mixed>> $owners
 */
function jeab_contacts_sole_pnr_owner_slot(array $owners, string $nameKey, string $want): ?int
{
    foreach ($owners as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (jeab_contacts_sole_pnr_store_value((string) ($owner['personnummer'] ?? '')) === $want) {
            return $i;
        }
    }
    if ($nameKey !== '') {
        $hits = [];
        foreach ($owners as $i => $owner) {
            if (!is_array($owner)) {
                continue;
            }
            if (jeab_contacts_sole_name_key((string) ($owner['name'] ?? $owner['namn'] ?? '')) === $nameKey) {
                $hits[] = $i;
            }
        }
        if (count($hits) === 1) {
            return $hits[0];
        }
    }
    $empty = [];
    foreach ($owners as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (trim((string) ($owner['personnummer'] ?? '')) === '') {
            $empty[] = $i;
        }
    }
    if (count($empty) === 1) {
        return $empty[0];
    }

    return null;
}

/**
 * Skriver hintens personnummer i lagringsform. Tomt skriver inte över ett nummer som finns.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_contacts_sole_pnr_commit_hint(array $row, array $hint): array
{
    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '' || !function_exists('jeab_kontakter_mutate') || !function_exists('jeab_kontakt_get')) {
        return $row;
    }
    $jobs = jeab_contacts_sole_pnr_hint_jobs($hint);
    if ($jobs === []) {
        return $row;
    }
    $fresh = jeab_kontakt_get($id);
    if (!is_array($fresh)) {
        return $row;
    }
    $owners = [];
    foreach (is_array($fresh['owners'] ?? null) ? $fresh['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    if ($owners === []) {
        $owners[] = ['name' => trim((string) ($hint['name'] ?? '')), 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => ''];
    }
    $changed = false;
    foreach ($jobs as $job) {
        $want = (string) $job['pnr'];
        $idx = jeab_contacts_sole_pnr_owner_slot($owners, (string) $job['name'], $want);
        if ($idx === null) {
            continue;
        }
        $current = (string) ($owners[$idx]['personnummer'] ?? '');
        $currentStored = jeab_contacts_sole_pnr_store_value($current);
        if ($current !== '' && $currentStored !== $want) {
            continue;
        }
        if ($current !== $want) {
            $owners[$idx]['personnummer'] = $want;
            $changed = true;
        }
    }
    if (array_key_exists('personnummer', $fresh)) {
        $topWant = '';
        foreach (['personnummer', 'pnr'] as $key) {
            if (!array_key_exists($key, $hint)) {
                continue;
            }
            $stored = jeab_contacts_sole_pnr_store_value((string) $hint[$key]);
            if ($stored !== '') {
                $topWant = $stored;
            }
        }
        if ($topWant !== '') {
            $cur = (string) $fresh['personnummer'];
            $curStored = jeab_contacts_sole_pnr_store_value($cur);
            if (($cur === '' || $curStored === $topWant) && $cur !== $topWant) {
                $fresh['personnummer'] = $topWant;
                $changed = true;
            }
        }
    }
    if (!$changed) {
        return $fresh;
    }
    $top = array_key_exists('personnummer', $fresh) ? (string) $fresh['personnummer'] : null;
    jeab_kontakter_mutate(static function (array &$contacts) use ($id, $owners, $top): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $contacts[$id]['owners'] = $owners;
        if ($top !== null) {
            $contacts[$id]['personnummer'] = $top;
        }
        $contacts[$id]['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    });
    $again = jeab_kontakt_get($id);

    return is_array($again) ? $again : $row;
}

function jeab_contacts_sole_inflow_upsert(array $hint): ?array
{
    if (!jeab_contacts_sole() || !function_exists('jeab_kontakt_find_or_create')) {
        return null;
    }
    jeab_contacts_sole_pnr_digits_push();
    try {
        $hint = jeab_contacts_sole_inflow_place($hint);
        try {
            $row = jeab_kontakt_find_or_create($hint, false, false, false);
        } catch (Throwable $e) {
            if (!empty($GLOBALS['_JEAB_LEAD_CONTACT_WATCH'])) {
                $GLOBALS['_JEAB_LEAD_CONTACT_LAST_ERROR'] = $e;
            }
            return null;
        }
        if (is_array($row)) {
            $row = jeab_contacts_sole_pnr_commit_hint($row, $hint);
            $writtenId = trim((string) ($row['id'] ?? ''));
            jeab_contacts_sole_cache_forget($writtenId !== '' ? $writtenId : null);
        }

        return is_array($row) ? $row : null;
    } finally {
        jeab_contacts_sole_pnr_digits_pop();
    }
}

/** Sätter contact_id på påminnelse och, om $order, på ordern. Övriga kolumner rörs inte. */
function jeab_contacts_sole_inflow_link(JeabStore $store, int $reminderId, string $contactId, bool $order = true): void
{
    if (!jeab_contacts_sole() || $reminderId <= 0 || trim($contactId) === '') {
        return;
    }
    $ref = (string) $reminderId;
    if (function_exists('jeab_kontakt_stamp_reminder')) {
        try {
            jeab_kontakt_stamp_reminder($store, $reminderId, $contactId);
        } catch (Throwable $e) {
            jeab_contacts_sole_inflow_fail('contacts_sole.inflow_link', $ref, $e);
        }
    }
    if ($order && function_exists('jeab_kontakt_stamp_order')) {
        try {
            jeab_kontakt_stamp_order($reminderId, $contactId);
        } catch (Throwable $e) {
            jeab_contacts_sole_inflow_fail('contacts_sole.inflow_link', $ref, $e);
        }
    }
}

/**
 * Uppslag på e-post, sedan telefon. Hopslagningsregeln som kräver gata på båda
 * sidor gäller inte: ett tomt uppslag är inte en andra adress.
 *
 * @param array<string, mixed> $contacts
 */
function jeab_contacts_sole_party_match(array $contacts, string $email, string $phone): ?string
{
    if ($email !== '' && !(function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email))) {
        foreach ($contacts as $id => $row) {
            if (!is_array($row) || !function_exists('jeab_kontakt_row_email')) {
                continue;
            }
            $rowEmail = jeab_kontakt_row_email($row);
            if ($rowEmail === '' || $rowEmail !== $email) {
                continue;
            }
            if (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($rowEmail)) {
                continue;
            }

            return (string) $id;
        }
    }
    if ($phone !== '' && !(function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone))) {
        foreach ($contacts as $id => $row) {
            if (!is_array($row) || !function_exists('jeab_kontakt_row_phone')) {
                continue;
            }
            $rowPhone = jeab_kontakt_row_phone($row);
            if ($rowPhone === '' || $rowPhone !== $phone) {
                continue;
            }
            if (function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($rowPhone)) {
                continue;
            }

            return (string) $id;
        }
    }

    return null;
}

/**
 * Index på owner_email / owner_phone. Hela registret läses bara när SQL
 * saknas och det här inte är en sidvisning.
 */
function jeab_contacts_sole_party_id_lookup(string $email, string $phone): string
{
    $sql = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($sql instanceof JeabPdoContactStore) {
        if ($email !== '' && !(function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email))) {
            foreach ($sql->findByEmail($email) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowEmail = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '';
                if ($rowEmail === '' || (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($rowEmail))) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }
        if ($phone !== '' && !(function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone))) {
            foreach ($sql->findByPhone($phone) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowPhone = function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($row) : '';
                if ($rowPhone === '' || (function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($rowPhone))) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    return $id;
                }
            }
        }
        if (function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source()) {
            return '';
        }
        if (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
            return '';
        }
    } elseif (function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl()) {
        return '';
    }
    if (!function_exists('jeab_kontakter_load')) {
        return '';
    }
    $found = jeab_contacts_sole_party_match(jeab_kontakter_load(), $email, $phone);

    return is_string($found) ? $found : '';
}

/** Central contact_id för e-post eller telefon. Flaggan av ger tom sträng. */
function jeab_contacts_sole_party_id(string $email, string $phone = ''): string
{
    if (!jeab_contacts_sole()) {
        return '';
    }
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : mb_strtolower(trim($email));
    $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($phone) : trim($phone);
    if ($email === '' && $phone === '') {
        return '';
    }
    $key = $email . "\n" . $phone;
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_PARTY']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_PARTY']) && array_key_exists($key, $GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'])) {
        return (string) $GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'][$key];
    }
    try {
        $found = jeab_contacts_sole_party_id_lookup($email, $phone);
    } catch (Throwable) {
        return '';
    }
    $id = is_string($found) ? $found : '';
    if (!isset($GLOBALS['_JEAB_CONTACTS_SOLE_PARTY']) || !is_array($GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'])) {
        $GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'] = [];
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_PARTY'][$key] = $id;

    return $id;
}

/**
 * Samma fält som divert_property skickar till find_or_create.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_contacts_sole_property_hint(array $row): array
{
    return [
        'name' => (string) ($row['huvudsokande'] ?? $row['name'] ?? $row['customer_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'address' => (string) ($row['address'] ?? $row['huvudsokande_address'] ?? ''),
        'street' => (string) ($row['gata'] ?? ''),
        'city' => (string) ($row['ort'] ?? $row['city'] ?? ''),
        'postal_code' => (string) ($row['postnummer'] ?? $row['postnr'] ?? ''),
        'company' => (string) ($row['company'] ?? ''),
        'fastighetsbeteckning' => (string) ($row['fastighetsbeteckning'] ?? ''),
    ];
}

/**
 * Samma beslut som find_or_create med lookup av, utan att skriva.
 * new = en ny kontakt skulle skapas. Adressuttag mot nätet körs inte.
 *
 * @param array<string, mixed> $hint
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_contacts_sole_property_outcome(array $hint, array &$contacts): string
{
    $skipLog = function_exists('jeab_kontakt_merge_skip_guard') ? jeab_kontakt_merge_skip_guard(true) : null;
    if (!function_exists('jeab_kontakt_parse_address') || !function_exists('jeab_kontakt_find') || !function_exists('jeab_kontakt_blank') || !function_exists('jeab_kontakt_email') || !function_exists('jeab_kontakt_phone')) {
        return 'skip';
    }
    $extract = function_exists('jeab_address_extract_network') ? !jeab_address_extract_network() : false;
    $hint = jeab_contacts_sole_inflow_place($hint, $extract);
    $parsed = jeab_kontakt_parse_address((string) ($hint['address'] ?? ''));
    if (!is_array($parsed)) {
        $parsed = ['street' => '', 'street_key' => '', 'postal' => '', 'city' => ''];
    }
    if (($parsed['street'] ?? '') === '' && trim((string) ($hint['street'] ?? '')) !== '') {
        $parsed['street'] = trim((string) $hint['street']);
        $parsed['street_key'] = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key((string) $parsed['street']) : '';
    }
    if (($parsed['postal'] ?? '') === '' && trim((string) ($hint['postal_code'] ?? '')) !== '' && function_exists('jeab_kontakt_postal_scan')) {
        $scan = jeab_kontakt_postal_scan((string) $hint['postal_code']);
        if (!empty($scan['invalid'])) {
            $parsed['postal_invalid'] = true;
        } elseif (($scan['postal'] ?? '') !== '') {
            $parsed['postal'] = $scan['postal'];
        }
    }
    if (trim((string) ($parsed['city'] ?? '')) === '') {
        $parsed['city'] = function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name(trim((string) ($hint['city'] ?? ''))) : trim((string) ($hint['city'] ?? ''));
    } elseif (function_exists('jeab_kontakt_city_name')) {
        $parsed['city'] = jeab_kontakt_city_name((string) $parsed['city']);
    }
    $email = jeab_kontakt_email((string) ($hint['email'] ?? ''));
    if ($email !== '' && function_exists('jeab_kontakt_secondary_holder') && jeab_kontakt_secondary_holder($contacts, $email) !== '') {
        return 'have';
    }
    $testHint = $hint;
    $testHint['email'] = $email;
    $testLead = function_exists('jeab_kontakt_hint_is_test_lead') && jeab_kontakt_hint_is_test_lead($testHint);
    if (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email) && !($testLead && function_exists('jeab_kontakt_test_email_kept') && jeab_kontakt_test_email_kept($email))) {
        $email = '';
    }
    $phone = jeab_kontakt_phone((string) ($hint['phone'] ?? ''));
    if (function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    $name = trim((string) ($hint['name'] ?? ''));
    if (function_exists('jeab_kontakt_normalize_person_name')) {
        $norm = jeab_kontakt_normalize_person_name($name, (string) ($parsed['city'] ?? ''));
        $name = (string) ($norm['name'] ?? $name);
        if (trim((string) ($parsed['city'] ?? '')) === '' && trim((string) ($norm['city'] ?? '')) !== '' && function_exists('jeab_kontakt_city_name')) {
            $parsed['city'] = jeab_kontakt_city_name((string) $norm['city']);
        }
    }
    $streetKey = (string) ($parsed['street_key'] ?? '');
    $city = (string) ($parsed['city'] ?? '');
    $street = (string) ($parsed['street'] ?? '');
    if ((function_exists('jeab_kontakt_text_is_internal') && jeab_kontakt_text_is_internal($name))
        || (function_exists('jeab_kontakt_is_internal_place') && jeab_kontakt_is_internal_place($streetKey, $city))
        || (function_exists('jeab_kontakt_is_po_box') && jeab_kontakt_is_po_box($street))
        || (function_exists('jeab_kontakt_nonperson_name') && jeab_kontakt_nonperson_name($name))) {
        return 'skip';
    }
    if (function_exists('jeab_kontakt_platform_name') && jeab_kontakt_platform_name($name)) {
        $name = '';
    }
    if ($streetKey === '' && $email === '' && $phone === '' && $name === '') {
        return 'skip';
    }
    if (!empty($parsed['postal_invalid'])) {
        $streetKey = '';
    }
    $extractCompany = function_exists('jeab_address_company') ? jeab_address_company((string) ($hint['company'] ?? '')) : '';
    if ($extractCompany !== '' && $city !== '' && function_exists('jeab_address_city_kind') && jeab_address_city_kind($extractCompany, $city) === 'outside') {
        $city = '';
        if (function_exists('jeab_address_postal_kind') && jeab_address_postal_kind($extractCompany, (string) ($parsed['postal'] ?? '')) !== 'inside') {
            $parsed['postal'] = '';
        }
        $hint['address_review'] = true;
    } elseif ($city !== '') {
        unset($hint['address_review']);
    }
    $hintOut = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'personnummer' => '',
        'street' => $street,
        'street_key' => $streetKey,
        'postal_code' => (string) ($parsed['postal'] ?? ''),
        'city' => $city,
        'fastighetsbeteckning' => trim((string) ($hint['fastighetsbeteckning'] ?? '')),
        'kind' => function_exists('jeab_kontakt_company_name') && jeab_kontakt_company_name($name) ? 'company' : 'person',
        'address_review' => !empty($hint['address_review']),
        'company' => $extractCompany,
        'test_lead' => $testLead,
    ];
    $allowStreet = empty($parsed['postal_invalid']);
    $id = null;
    if ($testLead) {
        if ($email !== '' && function_exists('jeab_kontakt_row_email') && function_exists('jeab_kontakt_party_names_agree') && function_exists('jeab_kontakt_row_name')) {
            foreach ($contacts as $cid => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowEmail = jeab_kontakt_row_email($row);
                if ($rowEmail === '' || $rowEmail !== $email) {
                    continue;
                }
                if (!jeab_kontakt_party_names_agree($name, jeab_kontakt_row_name($row))) {
                    continue;
                }
                $id = (string) $cid;
                break;
            }
        }
    } else {
        $id = jeab_kontakt_find($contacts, $streetKey, (string) $hintOut['postal_code'], $email, $phone, $allowStreet, $name, $city);
    }
    if ($id === null && !$testLead && function_exists('jeab_kontakt_dedupe_match_contact')) {
        $id = jeab_kontakt_dedupe_match_contact($contacts, [
            'street' => $street,
            'street_key' => $streetKey,
            'city' => $city,
            'kind' => (string) $hintOut['kind'],
            'owners' => [[
                'name' => $name,
                'personnummer' => '',
                'contact' => true,
                'email' => $email,
                'phone' => $phone,
            ]],
        ]);
    }
    if (is_string($id) && $id !== '' && isset($contacts[$id]) && is_array($contacts[$id])) {
        if (function_exists('jeab_kontakt_fill_blanks')) {
            $contacts[$id] = jeab_kontakt_fill_blanks($contacts[$id], $hintOut);
        }

        return 'have';
    }
    if ($name === '' || ($streetKey === '' && $email === '' && $phone === '')) {
        return 'skip';
    }
    $made = jeab_kontakt_blank($hintOut);
    $madeId = trim((string) ($made['id'] ?? ''));
    if ($madeId === '') {
        return 'skip';
    }
    $contacts[$madeId] = $made;

    return 'new';
}

/**
 * Hur många nya kontakter divert_property skulle skapa för ordrar och bokningar utan contact_id.
 * Bara räknare. Skriver inget. Två rader som träffar samma nya person räknas som en kontakt.
 *
 * @return array{orders:int,bookings:int,contacts:int}
 */
function jeab_contacts_sole_property_would_create(JeabStore $store): array
{
    $out = ['orders' => 0, 'bookings' => 0, 'contacts' => 0];
    $seen = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    if (!is_array($seen)) {
        $seen = [];
    }
    $scan = static function (array $row, string $kind) use (&$seen, &$out): void {
        if (jeab_contacts_sole_cid($row['contact_id'] ?? null) !== '') {
            return;
        }
        if (jeab_contacts_sole_property_outcome(jeab_contacts_sole_property_hint($row), $seen) !== 'new') {
            return;
        }
        $out['contacts']++;
        if ($kind === 'booking') {
            $out['bookings']++;
        } else {
            $out['orders']++;
        }
    };
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $scan($row, 'order');
            }
        }
    }
    foreach ($store->listAllBookings() as $row) {
        if (is_array($row)) {
            $scan($row, 'booking');
        }
    }

    return $out;
}

function jeab_contacts_sole_inflow_error_count(): int
{
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_INFLOW_ERRORS'])) {
        return max(0, (int) $GLOBALS['_JEAB_CONTACTS_SOLE_INFLOW_ERRORS']);
    }
    $n = 0;
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $raw = jeab_settings_value($pdo, $driver, 'contacts_sole_inflow_errors');
        if (is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1) {
            $n = (int) $raw;
        }
    }
    $GLOBALS['_JEAB_CONTACTS_SOLE_INFLOW_ERRORS'] = $n;

    return $n;
}

function jeab_contacts_sole_inflow_error_bump(): void
{
    $n = jeab_contacts_sole_inflow_error_count() + 1;
    $GLOBALS['_JEAB_CONTACTS_SOLE_INFLOW_ERRORS'] = $n;
    $pdo = function_exists('jeab_data_pdo') ? jeab_data_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_settings_put')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_settings_put($pdo, $driver, 'contacts_sole_inflow_errors', (string) $n);
    }
}

/** Loggar bara surface, id och felklass. Inget felmeddelande och ingen kunddata. */
function jeab_contacts_sole_inflow_fail(string $surface, string $ref, Throwable $e): void
{
    $surface = strtolower(trim($surface));
    $surface = preg_replace('/[^a-z0-9._-]/', '', $surface) ?? '';
    if ($surface === '') {
        $surface = 'contacts_sole';
    }
    $class = $e::class;
    $pos = strrpos($class, '\\');
    $class = $pos === false ? $class : substr($class, $pos + 1);
    $class = preg_replace('/[^A-Za-z0-9_]/', '', $class) ?? '';
    if ($class === '') {
        $class = 'Throwable';
    }
    $ref = preg_replace('/[^A-Za-z0-9_-]/', '', $ref) ?? '';
    if (strlen($ref) > 64) {
        $ref = substr($ref, 0, 64);
    }
    try {
        if (function_exists('jeab_log_app_error')) {
            $context = ['no_webhook' => true, 'skip_request' => true];
            if ($ref !== '') {
                $context['ref'] = $ref;
            }
            jeab_log_app_error($surface, $class, $context);
        }
    } catch (Throwable) {
    }
    jeab_contacts_sole_inflow_error_bump();
}

/**
 * Sparar fastighetsbeteckning och alla ägare på kontakten.
 * Ordern lämnas. Personnummer skrivs bara in i kontaktens ägarlista.
 * Före-bilden av ägare och fastighetsbeteckning skrivs i contacts_sole_undo innan kontakten ändras.
 *
 * @param array<string, mixed> $order
 */
function jeab_contacts_sole_divert_property(array $order): void
{
    if (!jeab_contacts_sole()) {
        return;
    }
    jeab_contacts_sole_pnr_digits_push();
    $ref = '';
    try {
        $id = trim((string) ($order['contact_id'] ?? ''));
        $reminderId = (int) ($order['reminder_id'] ?? 0);
        $ref = $reminderId > 0 ? (string) $reminderId : $id;
        $have = $id !== '' && function_exists('jeab_kontakt_get') && is_array(jeab_kontakt_get($id));
        if (!$have) {
            $made = jeab_contacts_sole_inflow_upsert(jeab_contacts_sole_property_hint($order));
            $id = is_array($made) ? trim((string) ($made['id'] ?? '')) : '';
            if ($ref === '') {
                $ref = $id;
            }
            if ($id !== '' && $reminderId > 0 && function_exists('jeab_kontakt_stamp_order')) {
                jeab_kontakt_stamp_order($reminderId, $id);
            }
        }
        if ($id === '' || !function_exists('jeab_kontakt_get') || !function_exists('jeab_fastighet_apply_contact_fields') || !function_exists('jeab_fastighet_write_contact')) {
            return;
        }
        if ($ref === '') {
            $ref = $id;
        }
        $contact = jeab_kontakt_get($id);
        if (!is_array($contact)) {
            return;
        }
        $people = [];
        foreach (is_array($order['owners'] ?? null) ? $order['owners'] : [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $name = trim((string) ($person['name'] ?? ''));
            $pnr = jeab_contacts_sole_pnr_store_value((string) ($person['personnummer'] ?? ''));
            if ($name === '' && $pnr === '') {
                continue;
            }
            $people[] = ['name' => $name, 'personnummer' => $pnr];
        }
        $fast = trim((string) ($order['fastighetsbeteckning'] ?? ''));
        if ($fast === '' && $people === []) {
            return;
        }
        $diff = [];
        if ($fast !== '') {
            $diff['fastighetsbeteckning'] = ['new' => $fast];
        }
        if ($people !== []) {
            $diff['owners'] = ['new' => true];
        }
        $next = jeab_fastighet_apply_contact_fields($contact, $diff, $people);
        $slice = ['id' => $id];
        if ($fast !== '') {
            $slice['fastighetsbeteckning'] = (string) ($next['fastighetsbeteckning'] ?? $fast);
        }
        if ($people !== []) {
            $owners = [];
            foreach (is_array($next['owners'] ?? null) ? $next['owners'] : [] as $i => $owner) {
                if (!is_array($owner)) {
                    continue;
                }
                $fromPeople = isset($people[$i]) ? (string) ($people[$i]['personnummer'] ?? '') : (string) ($owner['personnummer'] ?? '');
                $owner['personnummer'] = jeab_contacts_sole_pnr_store_value($fromPeople);
                $owners[] = $owner;
            }
            $slice['owners'] = $owners;
        }
        if (array_key_exists('personnummer', $contact)) {
            $slice['personnummer'] = jeab_contacts_sole_pnr_store_value((string) $contact['personnummer']);
        }
        $previousOwners = [];
        foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $previousOwners[] = $owner;
            }
        }
        $before = [
            'fastighetsbeteckning' => (string) ($contact['fastighetsbeteckning'] ?? ''),
            'owners' => $previousOwners,
            'updated_at' => trim((string) ($contact['updated_at'] ?? '')),
        ];
        if (array_key_exists('personnummer', $contact)) {
            $before['personnummer'] = (string) $contact['personnummer'];
        }
        $batch = jeab_contacts_sole_batch();
        if (!jeab_contacts_sole_undo_add($batch, 1, 'property', 'contact', $id, $before, $slice)) {
            return;
        }
        jeab_fastighet_write_contact($id, $slice);
    } catch (Throwable $e) {
        jeab_contacts_sole_inflow_fail('contacts_sole.divert_property', $ref, $e);
    } finally {
        jeab_contacts_sole_pnr_digits_pop();
    }
}

/**
 * Skriver en inkorgskontakt till registret. dry räknar utan att skriva.
 *
 * @param array<string, mixed> $row
 * @return array{ok:bool,dry:bool,written:int,would:int,contact_id:string,skipped:string}
 */
function jeab_contacts_sole_mirror_inbox(string $inboxId, array $row, bool $dry = false): array
{
    $out = ['ok' => true, 'dry' => $dry, 'written' => 0, 'would' => 0, 'contact_id' => '', 'skipped' => ''];
    if (!jeab_contacts_sole()) {
        $out['skipped'] = 'flag';

        return $out;
    }
    $emails = is_array($row['emails'] ?? null) ? $row['emails'] : [];
    $email = trim((string) ($emails[0] ?? $row['email'] ?? ''));
    $hint = [
        'name' => trim((string) ($row['name'] ?? '')),
        'email' => $email,
        'phone' => trim((string) ($row['mobile'] ?? $row['phone'] ?? '')),
        'address' => trim((string) ($row['address'] ?? '')),
        'city' => trim((string) ($row['town'] ?? $row['city'] ?? '')),
        'company' => trim((string) ($row['company'] ?? '')),
    ];
    if ($hint['name'] === '' && $hint['email'] === '' && $hint['phone'] === '' && $hint['address'] === '') {
        $out['skipped'] = 'empty';

        return $out;
    }
    if ($dry) {
        $out['would'] = 1;

        return $out;
    }
    $made = jeab_contacts_sole_inflow_upsert($hint);
    $contactId = is_array($made) ? trim((string) ($made['id'] ?? '')) : '';
    $inboxId = trim($inboxId);
    if ($contactId !== '' && $inboxId !== '' && $inboxId !== $contactId && function_exists('jeab_kontakt_stamp_inbox')) {
        try {
            jeab_kontakt_stamp_inbox($inboxId, $contactId);
        } catch (Throwable) {
        }
    }
    $out['written'] = $contactId !== '' ? 1 : 0;
    $out['contact_id'] = $contactId;

    return $out;
}

/**
 * @return array{ok:bool,dry:bool,seen:int,written:int,would:int,skipped:string}
 */
function jeab_contacts_sole_mirror_inbox_all(bool $dry): array
{
    $out = ['ok' => true, 'dry' => $dry, 'seen' => 0, 'written' => 0, 'would' => 0, 'skipped' => ''];
    if (!jeab_contacts_sole()) {
        $out['skipped'] = 'flag';

        return $out;
    }
    if (!function_exists('jeab_inkorg_contacts')) {
        $out['skipped'] = 'missing';

        return $out;
    }
    foreach (jeab_inkorg_contacts() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $out['seen']++;
        $one = jeab_contacts_sole_mirror_inbox((string) ($row['id'] ?? $id), $row, $dry);
        $out['written'] += (int) ($one['written'] ?? 0);
        $out['would'] += (int) ($one['would'] ?? 0);
    }

    return $out;
}
