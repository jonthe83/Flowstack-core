<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/idnr.php';
require_once __DIR__ . '/snapshots.php';
/**
 * Fortnox push/create for Ordrar.
 * Extracted from ordrar.php so UI/steg stay separate.
 * Depends on helpers still defined in ordrar.php (jeab_ordrar_require, backfill, etc.)
 * — this file must be loaded after those exist, or only called at runtime after both load.
 *
 * Loading: ordrar.php require_once this file early; Fortnox functions are only
 * invoked after ordrar.php has defined jeab_ordrar_require / purchase helpers.
 */

function jeab_ordrar_fortnox_app_id(?string $company): string
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    // JEAB (Järfälla) and MEAB (Malmö) are separate Fortnox companies / tenants.
    $ids = [
        'jarfalla' => '5566fe0c8149490fb1d9cd91aad77aa8',
        'malmo' => '',
    ];
    return trim((string) ($ids[$company] ?? ''));
}

function jeab_ordrar_fortnox_order_url(array $row): string
{
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    $app = jeab_ordrar_fortnox_app_id($row['company'] ?? null);
    if ($no === '' || $app === '') {
        return '';
    }
    return 'https://apps5.fortnox.se/app/' . $app . '/order/order/' . rawurlencode($no);
}

function jeab_ordrar_fortnox_account(?string $company): string
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    return $company === 'malmo' ? 'Malmo' : 'Jarfalla';
}

function jeab_ordrar_fortnox_price(array $row): int
{
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '';
    if ($kind === 'laddbox' || $kind === 'batteri') {
        $pris = (int) ($row['pris'] ?? 0);
        if ($pris > 0) {
            return $pris;
        }
        return (int) ($row['kundpris'] ?? 0);
    }
    return (int) ($row['kundpris'] ?? 0);
}

/**
 * Kundnamn, e-post, telefon, adress och ägare från kontakten.
 * Flaggan av lämnar raden. Ifylld ägarslot vinner. Tom slot fylls från orderns ägare
 * på den här kopian. Kontakten skrivs inte. Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_fortnox_forget_contact(array $row): void
{
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '') {
        return;
    }
    // Läser personnumret. Registret är redan laddat; bara den här kontaktens visning glöms.
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'])) {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'][$id]);
    }
    if (isset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES']) && is_array($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'])) {
        unset($GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$id]);
    }
    if (function_exists('jeab_contacts_sole_booking_order_cache_forget')) {
        jeab_contacts_sole_booking_order_cache_forget($id);
    }
}

function jeab_ordrar_fortnox_customer_row(array $row): array
{
    if (function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($row, 'invoice')) {
        return jeab_snapshots_frozen_party($row, 'invoice');
    }
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_recipient_apply')) {
        return $row;
    }
    jeab_ordrar_fortnox_forget_contact($row);
    $out = jeab_contacts_sole_recipient_apply($row);
    $contact = function_exists('jeab_contacts_sole_recipient_contact')
        ? jeab_contacts_sole_recipient_contact($row)
        : null;
    $slots = is_array($contact) ? jeab_ordrar_fortnox_contact_slot_pnrs($contact) : [];
    $owners = isset($out['owners']) && is_array($out['owners']) ? $out['owners'] : [];
    if ($slots !== []) {
        foreach ($owners as $i => $owner) {
            if (!is_array($owner)) {
                continue;
            }
            if (($slots[$i] ?? '') !== '') {
                $owners[$i]['personnummer'] = $slots[$i];
            }
        }
        $out['owners'] = $owners;
    }
    $owners = isset($out['owners']) && is_array($out['owners']) ? $out['owners'] : [];
    $owners = jeab_ordrar_fortnox_fill_empty_owner_pnr($row, $owners);
    $out['owners'] = $owners;
    $huvud = trim((string) ($out['huvudsokande'] ?? ''));
    if (function_exists('jeab_contacts_sole_recipient_med_owners')) {
        $out['medsokande'] = jeab_ordrar_fortnox_fill_med_pnr(
            $row,
            jeab_contacts_sole_recipient_med_owners($owners, $huvud)
        );
    }
    $out['personnummer'] = function_exists('jeab_ordrar_canonical_personnummer')
        ? jeab_ordrar_canonical_personnummer($row)
        : '';

    return $out;
}

/**
 * Tom ägarslot på Fortnox-kopian tar orderns ägare, högst två.
 * Ifylld slot lämnas. Kontakten skrivs inte. Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $owners
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_fortnox_fill_empty_owner_pnr(array $order, array $owners): array
{
    $people = function_exists('jeab_ordrar_sync_lm_people') ? jeab_ordrar_sync_lm_people($order) : [];
    $used = [];
    foreach ($owners as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (trim((string) ($owner['personnummer'] ?? '')) !== '') {
            continue;
        }
        $hit = null;
        foreach ($people as $pi => $person) {
            if (isset($used[$pi]) || !is_array($person)) {
                continue;
            }
            if (trim((string) ($person['personnummer'] ?? '')) === '') {
                continue;
            }
            if (function_exists('jeab_ordrar_person_matches') && jeab_ordrar_person_matches($owner, $person)) {
                $hit = $pi;
                break;
            }
        }
        if ($hit === null && isset($people[$i]) && !isset($used[$i]) && is_array($people[$i]) && trim((string) ($people[$i]['personnummer'] ?? '')) !== '') {
            $hit = $i;
        }
        if ($hit === null) {
            continue;
        }
        $raw = (string) ($people[$hit]['personnummer'] ?? '');
        $pnr = function_exists('jeab_contacts_sole_pnr_text')
            ? jeab_contacts_sole_pnr_text($raw)
            : (function_exists('jeab_ordrar_format_pnr') ? jeab_ordrar_format_pnr($raw) : trim($raw));
        if ($pnr === '') {
            continue;
        }
        $owners[$i]['personnummer'] = $pnr;
        $used[$hit] = true;
    }

    return $owners;
}

/**
 * Medsökande utan nummer tar orderns andra ägare. Kontakten skrivs inte.
 *
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $med
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_fortnox_fill_med_pnr(array $order, array $med): array
{
    $people = function_exists('jeab_ordrar_sync_lm_people') ? jeab_ordrar_sync_lm_people($order) : [];
    $second = is_array($people[1] ?? null) ? $people[1] : null;
    $secondPnr = is_array($second) ? trim((string) ($second['personnummer'] ?? '')) : '';
    if ($med === [] && $secondPnr !== '') {
        $name = trim((string) ($second['name'] ?? ''));
        if ($name !== '') {
            $med[] = ['name' => $name, 'personnummer' => ''];
        }
    }
    foreach ($med as $i => $person) {
        if (!is_array($person) || trim((string) ($person['personnummer'] ?? '')) !== '') {
            continue;
        }
        $raw = '';
        foreach ($people as $candidate) {
            if (!is_array($candidate) || trim((string) ($candidate['personnummer'] ?? '')) === '') {
                continue;
            }
            if (function_exists('jeab_ordrar_person_matches') && jeab_ordrar_person_matches($person, $candidate)) {
                $raw = (string) $candidate['personnummer'];
                break;
            }
        }
        if ($raw === '' && $i === 0 && $secondPnr !== '') {
            $raw = $secondPnr;
        }
        if ($raw === '') {
            continue;
        }
        $pnr = function_exists('jeab_contacts_sole_pnr_text')
            ? jeab_contacts_sole_pnr_text($raw)
            : (function_exists('jeab_ordrar_format_pnr') ? jeab_ordrar_format_pnr($raw) : trim($raw));
        if ($pnr === '') {
            continue;
        }
        $med[$i]['personnummer'] = $pnr;
    }

    return $med;
}

/**
 * Personnummer på kontaktens ägare, i samma ordning som utgående ägarlista.
 * Tom slot förblir tom. Orderns nummer tas inte med. Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $contact
 * @return list<string>
 */
function jeab_ordrar_fortnox_contact_slot_pnrs(array $contact): array
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
        if ($name === '' && $email === '' && $phone === '' && $pnr === '') {
            continue;
        }
        $out[] = $pnr;
    }

    return $out;
}

/**
 * Tar bort personnummer från en Fortnox-kopia så orderns nummer inte följer med.
 * Kontakten skrivs inte. Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_fortnox_clear_personnummer(array $row): array
{
    $row['personnummer'] = '';
    foreach (['owners', 'medsokande'] as $key) {
        if (!isset($row[$key]) || !is_array($row[$key])) {
            continue;
        }
        foreach ($row[$key] as $i => $owner) {
            if (is_array($owner)) {
                $row[$key][$i]['personnummer'] = '';
            }
        }
    }

    return $row;
}

/**
 * Finns en kontakt att läsa personnummer från.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_fortnox_has_recipient(array $row): bool
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return false;
    }
    if (!function_exists('jeab_contacts_sole_recipient_contact')) {
        return false;
    }

    return is_array(jeab_contacts_sole_recipient_contact($row));
}

/**
 * Nummer som Fortnox-anropet får skicka.
 * Ägarsloten på kontakten vinner. En tom slot faller tillbaka på orderns ägare.
 * Saknas kontakten används orderns eget nummer, så Bekräfta utan kontakt fortfarande kan skapa ordern.
 * Värdet skrivs inte i logg.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_fortnox_submit_personnummer(array $row): string
{
    if (function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($row, 'invoice')) {
        return jeab_snapshots_row_pnr($row);
    }
    jeab_ordrar_fortnox_forget_contact($row);
    if (jeab_ordrar_fortnox_has_recipient($row) && function_exists('jeab_contacts_sole_recipient_contact')) {
        $contact = jeab_contacts_sole_recipient_contact($row);
        if (is_array($contact) && function_exists('jeab_contacts_sole_contact_person_pnr')) {
            $fromPerson = jeab_contacts_sole_contact_person_pnr($contact);
            if ($fromPerson !== '') {
                return function_exists('jeab_ordrar_format_pnr') ? jeab_ordrar_format_pnr($fromPerson) : $fromPerson;
            }
        }
    }
    $canonical = function_exists('jeab_ordrar_canonical_personnummer')
        ? jeab_ordrar_canonical_personnummer($row)
        : '';
    if ($canonical !== '') {
        return $canonical;
    }
    if (jeab_ordrar_fortnox_has_recipient($row)) {
        return '';
    }
    if (function_exists('jeab_contacts_strict') && jeab_contacts_strict()) {
        return '';
    }
    $raw = trim((string) ($row['personnummer'] ?? ''));
    if ($raw === '') {
        return '';
    }

    return function_exists('jeab_ordrar_format_pnr') ? jeab_ordrar_format_pnr($raw) : $raw;
}

function jeab_ordrar_fortnox_missing(array $row): array
{
    if (jeab_ordrar_identity_missing($row) !== []) {
        $row = jeab_ordrar_backfill_lookup($row, null, false);
    }
    $submitPnr = jeab_ordrar_fortnox_submit_personnummer($row);
    $unlinked = !jeab_ordrar_fortnox_has_recipient($row);
    $rawMed = $unlinked ? ($row['medsokande'] ?? []) : null;
    $row = jeab_ordrar_fortnox_customer_row($row);
    if ($unlinked && is_array($rawMed)) {
        $row['medsokande'] = $rawMed;
    }
    $missing = [];
    if ($submitPnr === '') {
        $missing[] = 'personnummer';
    }
    if (trim((string) ($row['fastighetsbeteckning'] ?? '')) === '') {
        $missing[] = 'fastighetsbeteckning';
    }
    if (trim((string) ($row['huvudsokande'] ?? '')) === '') {
        $missing[] = 'huvudsökande';
    }
    foreach (jeab_ordrar_other_owners(jeab_ordrar_people_list($row['medsokande'] ?? []), trim((string) ($row['huvudsokande'] ?? ''))) as $med) {
        if (trim((string) ($med['name'] ?? '')) !== '' && trim((string) ($med['personnummer'] ?? '')) === '') {
            $missing[] = 'personnummer för medsökande';
            break;
        }
    }
    $kind = jeab_purchase_normalize_kind($row['kind'] ?? '');
    if ($kind === null) {
        $missing[] = 'produkt';
    } elseif ($kind === 'laddbox' && jeab_ordrar_fortnox_price($row) <= 0) {
        $missing[] = 'pris';
    } elseif (($kind === 'batteri' || $kind === 'sol') && jeab_ordrar_fortnox_price($row) <= 0) {
        $missing[] = 'kundpris';
    }
    return $missing;
}

function jeab_ordrar_fortnox_warehouse_lock_text(string $raw): bool
{
    return preg_match('/klarmarker|warehouseready|2003125/iu', $raw) === 1;
}

/** Fortnox nekade ändringen (2003125). Numret ligger kvar. Ingen ny order. */
function jeab_ordrar_fortnox_warehouse_stop(string $number): string
{
    $number = trim($number);
    $which = $number !== '' ? ' ' . $number : '';

    return 'Fortnox-ordern' . $which . ' är låst i lagret. Den uppdaterades inte och ingen ny order skapades.';
}

function jeab_ordrar_fortnox_sv(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'Fortnox kunde inte skapa ordern.';
    }
    if (preg_match('/makulerat dokument|cancelled document|document is cancelled/iu', $raw)) {
        return 'Fortnox-ordern är makulerad, en ny skapas.';
    }
    if (jeab_ordrar_fortnox_warehouse_lock_text($raw)) {
        return jeab_ordrar_fortnox_warehouse_stop('');
    }
    if (preg_match('/faktura genererad|2001242|värden som är låsta|varden som ar lasta/iu', $raw)) {
        return 'Fortnox-ordern är låst mot en faktura, en ny skapas.';
    }
    if (preg_match('/inaktiv|inte aktiv|inte var aktiv|kunden finns inte|not active|inactive|does not exist/i', $raw)) {
        return 'Fortnox-ordern kunde inte skapas.';
    }
    return $raw;
}

function jeab_ordrar_fortnox_fail(string $raw, string $fallback, string $orderNo = ''): never
{
    $raw = trim($raw);
    if ($raw !== '' && jeab_ordrar_fortnox_warehouse_lock_text($raw)) {
        throw new InvalidArgumentException(jeab_ordrar_fortnox_warehouse_stop($orderNo));
    }
    throw new InvalidArgumentException($raw !== '' ? jeab_ordrar_fortnox_sv($raw) : $fallback);
}

/**
 * Klassar Fortnox-svaret. cancelled, warehouse_ready, invoice_reference eller tom sträng.
 *
 * Fortnox Lager sätter DeliveryState reservation på en ny order. Det är inte
 * en låsning och läses inte här. WarehouseReady är det skrivskyddade fältet
 * för «Lagerhantering klar». Skapandet skickar DeliveredQuantity på varje rad
 * och ingen DeliveryState, så Fortnox kan sätta WarehouseReady direkt när
 * det levererade antalet täcker raden. Det är samma fält, inte Cancelled
 * och inte reservation. Spara uppdaterar ändå. Bara fel 2003125 stoppar,
 * och då utan att skapa en ny order. InvoiceReference "0" är ingen låsning.
 *
 * @param array<string,mixed> $order
 */
function jeab_ordrar_fortnox_order_lock_reason(array $order): string
{
    $body = isset($order['Order']) && is_array($order['Order']) ? $order['Order'] : $order;
    if (!function_exists('jeab_fortnox_leveransdatum_truthy')) {
        require_once __DIR__ . '/fortnox_leveransdatum.php';
    }
    if (jeab_fortnox_leveransdatum_truthy($body['Cancelled'] ?? false)) {
        return 'cancelled';
    }
    if (jeab_fortnox_leveransdatum_truthy($body['WarehouseReady'] ?? false)) {
        return 'warehouse_ready';
    }
    $ref = trim((string) ($body['InvoiceReference'] ?? ''));
    if ($ref !== '' && $ref !== '0') {
        return 'invoice_reference';
    }
    return '';
}

function jeab_ordrar_fortnox_lock_message(string $reason, string $number): string
{
    $number = trim($number);
    $which = $number !== '' ? ' ' . $number : '';
    return match ($reason) {
        'cancelled' => 'Fortnox-ordern' . $which . ' är makulerad, en ny skapas.',
        'warehouse_ready' => jeab_ordrar_fortnox_warehouse_stop($number),
        'invoice_reference' => 'Fortnox-ordern' . $which . ' är låst mot en faktura, en ny skapas.',
        default => 'Fortnox-ordern' . $which . ' är låst, en ny skapas.',
    };
}

function jeab_fortnox_order_audit_write(int $reminderId, string $fortnoxOrder, string $reason, string $message): void
{
    $pdo = $GLOBALS['_JEAB_FORTNOX_ORDER_AUDIT_PDO'] ?? null;
    if (!$pdo instanceof PDO && function_exists('jeab_purchase_sql_pdo')) {
        $pdo = jeab_purchase_sql_pdo();
    }
    if (!$pdo instanceof PDO) {
        return;
    }
    if (!function_exists('jeab_ensure_fortnox_order_audit')) {
        require_once __DIR__ . '/db.php';
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_fortnox_order_audit($pdo, $driver);
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO fortnox_order_audit (created_at, reminder_id, fortnox_order, reason, message) VALUES (?, ?, ?, ?, ?)'
    )->execute([$at, $reminderId, $fortnoxOrder, $reason, $message]);
}

/**
 * Släpper en makulerad Fortnox-order bara när anropet är uttryckligt.
 * Spara, create_fortnox och export utan force_new anropar den inte.
 * WarehouseReady och InvoiceReference rensar aldrig fortnox_order.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_release_locked(array $row, bool $explicit = false): array
{
    if (!$explicit || jeab_ordrar_fortnox_attach_locked($row)) {
        return $row;
    }
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($no === '' || $id <= 0) {
        return $row;
    }
    try {
        $live = jeab_ordrar_fortnox_fetch_order($row);
    } catch (Throwable) {
        return $row;
    }
    $reason = jeab_ordrar_fortnox_order_lock_reason($live);
    if ($reason !== 'cancelled') {
        return $row;
    }
    $message = jeab_ordrar_fortnox_lock_message($reason, $no);
    $saved = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($id, static function (array $fresh) use ($no, $message, $reason): array {
            if (trim((string) ($fresh['fortnox_order'] ?? '')) !== $no) {
                return $fresh;
            }
            if (function_exists('jeab_ordrar_activity_append')) {
                $fresh = jeab_ordrar_activity_append($fresh, $message, '', 'fortnox-order-locked:' . $reason . ':' . $no, false);
            }
            $fresh['fortnox_order'] = '';
            $storedRows = is_array($fresh['fortnox_order_rows'] ?? null) ? $fresh['fortnox_order_rows'] : [];
            $fresh['fortnox_order_rows'] = jeab_ordrar_fortnox_forget_row_ids($storedRows);
            return $fresh;
        })
        : null;
    if (!is_array($saved) || trim((string) ($saved['fortnox_order'] ?? '')) !== '') {
        return $row;
    }
    jeab_fortnox_order_audit_write($id, $no, $reason, $message);
    return $saved;
}

/** Uttrycklig åtgärd: släpp bara en makulerad Fortnox-order. */
function jeab_ordrar_fortnox_release_cancelled(array $row): array
{
    return jeab_ordrar_fortnox_release_locked($row, true);
}

function jeab_ordrar_fortnox_owner_key(string $name, string $pnr): string
{
    $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
    $digits = preg_replace('/\D+/', '', $pnr) ?? '';
    if (strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    return $digits . '|' . $fold;
}

function jeab_ordrar_fortnox_owners(array $row, ?string $knownPnr = null): array
{
    $submitPnr = $knownPnr !== null
        ? jeab_pnr_normalize($knownPnr)
        : jeab_pnr_normalize(jeab_ordrar_fortnox_submit_personnummer($row));
    $unlinked = !jeab_ordrar_fortnox_has_recipient($row);
    $rawMed = $unlinked && isset($row['medsokande']) ? $row['medsokande'] : null;
    $rawOwners = $unlinked && isset($row['owners']) ? $row['owners'] : null;
    $row = jeab_ordrar_fortnox_customer_row($row);
    if ($unlinked) {
        if (is_array($rawMed)) {
            $row['medsokande'] = $rawMed;
        }
        if (is_array($rawOwners)) {
            $row['owners'] = $rawOwners;
        }
    }
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    $people = [];
    $huvudPnr = jeab_pnr_normalize(jeab_ordrar_canonical_personnummer($row));
    if ($huvudPnr === '' && $submitPnr !== '') {
        $huvudPnr = $submitPnr;
    }
    if ($huvud !== '' || $huvudPnr !== '') {
        $people[] = ['name' => $huvud, 'personnummer' => $huvudPnr, 'role' => 'huvudsökande'];
    }
    foreach (jeab_ordrar_other_owners(jeab_ordrar_people_list($row['medsokande'] ?? []), $huvud) as $med) {
        $people[] = [
            'name' => trim((string) ($med['name'] ?? '')),
            'personnummer' => jeab_pnr_normalize(trim((string) ($med['personnummer'] ?? ''))),
            'role' => 'medsökande',
        ];
    }
    foreach (jeab_ordrar_other_owners(jeab_ordrar_people_list($row['owners'] ?? [], 4), $huvud) as $owner) {
        $people[] = [
            'name' => trim((string) ($owner['name'] ?? '')),
            'personnummer' => jeab_pnr_normalize(trim((string) ($owner['personnummer'] ?? ''))),
            'role' => 'medsökande',
        ];
    }
    $out = [];
    $seen = [];
    foreach ($people as $person) {
        $name = trim((string) ($person['name'] ?? ''));
        $pnr = trim((string) ($person['personnummer'] ?? ''));
        if ($name === '' && $pnr === '') {
            continue;
        }
        $role = trim((string) ($person['role'] ?? 'medsökande'));
        if ($pnr === '') {
            $label = $name !== '' ? $role . ' (' . $name . ')' : $role;
            throw new InvalidArgumentException('Personnummer saknas för ' . $label . '.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Namn saknas för ' . $role . '.');
        }
        $key = jeab_ordrar_fortnox_owner_key($name, $pnr);
        // Samma personnummer är samma person även om namnet stavas olika.
        $pnrKey = explode('|', $key, 2)[0];
        if (isset($seen[$key]) || ($pnrKey !== '' && isset($seen['pnr:' . $pnrKey]))) {
            continue;
        }
        $seen[$key] = true;
        if ($pnrKey !== '') {
            $seen['pnr:' . $pnrKey] = true;
        }
        $out[] = ['name' => $name, 'personnummer' => $pnr];
    }
    return $out;
}

function jeab_ordrar_fortnox_payload(array $row): array
{
    if (!class_exists('FortnoxAPI')) {
        $apiFile = JEAB_ROOT . '/src/fortnox.php';
        if (is_file($apiFile)) {
            require_once $apiFile;
        }
    }
    $pnr = jeab_pnr_normalize(jeab_ordrar_fortnox_submit_personnummer($row));
    $owners = jeab_ordrar_fortnox_owners($row, $pnr);
    $row = jeab_ordrar_fortnox_customer_row($row);
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
    if ($fast === '') {
        throw new InvalidArgumentException('Fastighetsbeteckning saknas.');
    }
    $med = [];
    foreach ($owners as $owner) {
        if (jeab_ordrar_same_person($huvud, (string) ($owner['name'] ?? ''))) {
            continue;
        }
        $med[] = $owner;
    }
    return [
        'company' => trim((string) ($row['company'] ?? '')),
        'kind' => jeab_purchase_normalize_kind($row['kind'] ?? ''),
        'name' => $huvud,
        'personnummer' => $pnr,
        'email' => trim((string) ($row['email'] ?? '')),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'fastighetsbeteckning' => $fast,
        'price' => jeab_ordrar_fortnox_price($row),
        'kundpris' => jeab_ordrar_fortnox_price($row),
        'install_what' => trim((string) ($row['install_what'] ?? '')),
        'kwh' => trim((string) ($row['kwh'] ?? '')),
        'vaxelriktare' => trim((string) ($row['vaxelriktare'] ?? '')),
        'inkop_lines' => is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [],
        'kalkyl' => is_array($row['kalkyl'] ?? null) ? $row['kalkyl'] : [],
        'fortnox_order' => trim((string) ($row['fortnox_order'] ?? '')),
        'fortnox_customer' => trim((string) ($row['fortnox_customer'] ?? '')),
        'delivery_date' => function_exists('jeab_ordrar_install_start') ? jeab_ordrar_install_start($row) : '',
        'address' => FortnoxAPI::collectAddress([
            'address' => trim((string) ($row['huvudsokande_address'] ?? $row['address'] ?? '')),
            'gata' => trim((string) ($row['gata'] ?? '')),
            'postnr' => trim((string) ($row['postnr'] ?? $row['postnummer'] ?? '')),
            'ort' => trim((string) ($row['ort'] ?? '')),
        ]),
        'owners' => $owners,
        'medsokande' => $med,
        'agare' => $med,
    ];
}

function jeab_ordrar_fortnox_require_designation(array $result): void
{
    $err = trim((string) ($result['taxReductionError'] ?? ''));
    if ($err !== '') {
        throw new InvalidArgumentException($err);
    }
}

function jeab_ordrar_push_fortnox(array $row): array
{
    if (jeab_ordrar_fortnox_attach_locked($row)) {
        throw new InvalidArgumentException(jeab_ordrar_fortnox_attach_error());
    }
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'fortnox');
        if ($block !== '') {
            throw new InvalidArgumentException($block);
        }
    }
    $apiFile = JEAB_ROOT . '/src/fortnox.php';
    if (!is_file($apiFile)) {
        $apiFile = JEAB_ROOT . '/rot/fortnox.php';
    }
    $configFile = jeab_fortnox_config_path();
    $tokensFile = jeab_fortnox_tokens_path();
    if (!is_file($apiFile) || !is_file($configFile) || !is_file($tokensFile)) {
        throw new InvalidArgumentException('Fortnox är inte kopplad på servern.');
    }
    require_once $apiFile;
    $payload = jeab_ordrar_fortnox_payload($row);
    $account = jeab_ordrar_fortnox_account($row['company'] ?? null);
    try {
        $api = new FortnoxAPI($account);
        $existing = trim((string) ($row['fortnox_order'] ?? ''));
        if ($existing === '') {
            $existing = jeab_ordrar_fortnox_find_external_order($row);
        }
        if (isset($GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG']) && is_array($GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'])) {
            $GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'][] = $existing !== '' ? 'update' : 'create';
        }
        if ($existing !== '') {
            $result = $api->updateKopOrder($existing, $payload);
        } else {
            $result = $api->createKopOrder($payload);
        }
    } catch (InvalidArgumentException $e) {
        throw $e;
    } catch (Throwable $e) {
        $raw = $e->getMessage();
        if ($existing !== '' && jeab_ordrar_fortnox_warehouse_lock_text($raw)) {
            throw new InvalidArgumentException(jeab_ordrar_fortnox_warehouse_stop($existing));
        }
        throw new InvalidArgumentException(jeab_ordrar_fortnox_sv($raw));
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        jeab_ordrar_fortnox_require_designation($result);
    }
    return $result;
}

function jeab_ordrar_fortnox_pnr_gap(string $error): bool
{
    $error = function_exists('mb_strtolower') ? mb_strtolower($error, 'UTF-8') : strtolower($error);
    return str_contains($error, 'personnummer')
        && (str_contains($error, 'kontakt') || str_contains($error, 'saknas'));
}

/** Länk till kontaktens personnummer och en omförsöksknapp. Själva numret skrivs inte ut. */
function jeab_ordrar_fortnox_pnr_next_html(array $row, int $openId): string
{
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $href = '';
    if ($contactId !== '' && function_exists('jeab_kontakt_page_url')) {
        $href = jeab_kontakt_page_url($contactId);
        if ($href !== '') {
            $href .= '#owner_pnr_1';
        }
    }
    $company = function_exists('jeab_normalize_company') ? (jeab_normalize_company($row['company'] ?? null) ?? '') : '';
    $action = function_exists('jeab_ordrar_url')
        ? jeab_ordrar_url(array_filter(['co' => $company, 'id' => $openId]))
        : '';
    $link = $href !== ''
        ? '<a href="' . e($href) . '">Lägg in personnummer på kontakten</a>'
        : '';
    $form = '<form method="post" action="' . e($action) . '">'
        . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
        . '<input type="hidden" name="action" value="ordrar.fortnox_retry">'
        . '<input type="hidden" name="id" value="' . (int) $openId . '">'
        . '<button type="submit">Försök igen</button>'
        . '</form>';

    return '<div class="ordrar-fortnox-pnr-next">' . $link . $form . '</div>';
}

function jeab_ordrar_fortnox_retry(array $input): array
{
    $id = (int) ($input['id'] ?? 0);
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $row = jeab_ordrar_require($id);
    jeab_ordrar_fortnox_forget_contact($row);

    return jeab_ordrar_create_fortnox(['id' => $id]);
}

function jeab_ordrar_fortnox_attach_locked(array $row): bool
{
    if (function_exists('jeab_fortnox_order_attach_locked')) {
        return jeab_fortnox_order_attach_locked($row);
    }
    $flag = $row['fortnox_attached'] ?? null;

    return $flag === true || $flag === 1 || $flag === '1';
}

function jeab_ordrar_fortnox_attach_error(): string
{
    if (function_exists('jeab_fortnox_order_attach_export_error')) {
        return jeab_fortnox_order_attach_export_error();
    }

    return 'Ordern är kopplad till Fortnox. Ingen export.';
}

/**
 * Skriver Fortnox-numret direkt efter en lyckad create, innan senare lokal jobb.
 *
 * @param array<string, mixed> $row
 */
function jeab_ordrar_fortnox_store_created_number(array $row): void
{
    $id = (int) ($row['reminder_id'] ?? 0);
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    if ($id <= 0 || $orderNo === '' || !function_exists('jeab_purchase_patch')) {
        throw new InvalidArgumentException('Fortnox-ordern kunde inte sparas.');
    }
    $customerNo = trim((string) ($row['fortnox_customer'] ?? ''));
    $snapshots = is_array($row['snapshots'] ?? null) ? $row['snapshots'] : null;
    $step = '';
    if (is_array($row['steps'] ?? null)) {
        $step = trim((string) ($row['steps']['order_skriven'] ?? ''));
    }
    $patched = jeab_purchase_patch($id, static function (array $fresh) use ($orderNo, $customerNo, $step, $snapshots): array {
        $fresh['fortnox_order'] = $orderNo;
        if ($customerNo !== '') {
            $fresh['fortnox_customer'] = $customerNo;
        }
        if (is_array($snapshots) && isset($snapshots['invoice']) && is_array($snapshots['invoice'])) {
            if (!isset($fresh['snapshots']) || !is_array($fresh['snapshots'])) {
                $fresh['snapshots'] = [];
            }
            $fresh['snapshots']['invoice'] = $snapshots['invoice'];
        }
        unset($fresh['fortnox_error']);
        if ($step !== '') {
            if (!is_array($fresh['steps'] ?? null)) {
                $fresh['steps'] = [];
            }
            if (trim((string) ($fresh['steps']['order_skriven'] ?? '')) === '') {
                $fresh['steps']['order_skriven'] = $step;
            }
        }

        return $fresh;
    });
    if (!is_array($patched) || trim((string) ($patched['fortnox_order'] ?? '')) !== $orderNo) {
        throw new InvalidArgumentException('Fortnox-ordern kunde inte sparas.');
    }
}

function jeab_ordrar_create_fortnox(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (jeab_ordrar_fortnox_attach_locked($row)) {
        throw new InvalidArgumentException(jeab_ordrar_fortnox_attach_error());
    }
    jeab_ordrar_fortnox_forget_contact($row);
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'fortnox');
        if ($block !== '') {
            throw new InvalidArgumentException($block);
        }
    }
    $row = jeab_ordrar_backfill_lookup($row, null, true);
    $row = jeab_ordrar_sync_matched_owner($row);
    $missing = jeab_ordrar_fortnox_missing($row);
    if ($missing) {
        throw new InvalidArgumentException('Saknas för Fortnox-order: ' . implode(', ', $missing) . '.');
    }
    $pnr = jeab_ordrar_fortnox_submit_personnummer($row);
    $pnrLen = strlen(preg_replace('/\D/', '', $pnr) ?? '');
    if ($pnrLen < 10 || $pnrLen > 13) {
        throw new InvalidArgumentException('Personnummer måste vara 10–13 siffror.');
    }
    // Finns fortnox_order uppdateras den. WarehouseReady på en nyskapad order
    // får inte rensa numret här — då skapar nästa anrop en ny Fortnox-order.
    $releasedLocked = false;
    if (trim((string) ($row['fortnox_order'] ?? '')) === '') {
        $row = jeab_ordrar_fakturering_reuse_order($row);
    }
    $updating = trim((string) ($row['fortnox_order'] ?? '')) !== '';
    $skipPrecheck = false;
    foreach (['skip_precheck', 'released'] as $flagKey) {
        $flag = $input[$flagKey] ?? false;
        if ($flag === true || $flag === 1 || $flag === '1') {
            $skipPrecheck = true;
        }
    }
    if (!$updating && !$releasedLocked && !$skipPrecheck && function_exists('jeab_webshop_precheck')) {
        // Förkoll: finns ordern redan i Fortnox (webshop) länkas den i stället för att skrivas igen.
        $linked = jeab_webshop_precheck($row);
        if (is_array($linked)) {
            return $linked;
        }
    }
    $result = jeab_ordrar_push_fortnox($row);
    if (!$updating && function_exists('jeab_snapshots_capture_sent')) {
        $painted = jeab_ordrar_fortnox_customer_row($row);
        $row = jeab_snapshots_capture_sent($row, 'invoice', $painted, 'send');
    }
    $row['fortnox_order'] = (string) ($result['orderNumber'] ?? $row['fortnox_order'] ?? '');
    if (trim((string) ($result['customerNumber'] ?? '')) !== '') {
        $row['fortnox_customer'] = (string) $result['customerNumber'];
    }
    if ($row['fortnox_order'] === '') {
        throw new InvalidArgumentException('Fortnox skapade ingen order.');
    }
    unset($row['fortnox_error']);
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    if (!$updating) {
        $row['steps']['order_skriven'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        // Numret sparas innan grön teknik och radfångst. Nästa klick uppdaterar den ordern.
        jeab_ordrar_fortnox_store_created_number($row);
    }
    if (isset($GLOBALS['_JEAB_ORDRAR_FORTNOX_AFTER_NUMBER']) && is_callable($GLOBALS['_JEAB_ORDRAR_FORTNOX_AFTER_NUMBER'])) {
        ($GLOBALS['_JEAB_ORDRAR_FORTNOX_AFTER_NUMBER'])($row);
    }
    $gtError = '';
    if ($updating) {
        // Kalkylens rader är skrivna. Ansökan följer dem (updateKopOrder
        // rättar bara fastighet och saknade ägare, inte beloppen).
        $synced = jeab_ordrar_fortnox_sync_gt($row, [], [], []);
        $gtError = trim((string) ($synced['error'] ?? ''));
        if ($gtError === '' && !empty($synced['update'])) {
            if (!empty($synced['capped'])) {
                $row['fortnox_gt_capped'] = true;
            } else {
                unset($row['fortnox_gt_capped']);
            }
            if (isset($synced['asked']) && is_numeric($synced['asked'])) {
                $row['fortnox_gt_asked'] = (int) $synced['asked'];
            }
        }
    }
    $row = jeab_ordrar_fortnox_capture_rows($row);
    $row['updated_at'] = jeab_now();
    $row = jeab_purchase_put($row);
    jeab_ordrar_fortnox_require_designation($result);
    if ($gtError !== '') {
        $row['fortnox_gt_error'] = $gtError;
    }
    $deliveryFile = __DIR__ . '/fortnox_leveransdatum.php';
    if (is_file($deliveryFile)) {
        require_once $deliveryFile;
    }
    if (function_exists('jeab_fortnox_leveransdatum_after_invoice')) {
        $deliveryResult = null;
        try {
            $deliveryResult = jeab_fortnox_leveransdatum_after_invoice($row, is_array($result) ? $result : []);
        } catch (Throwable $e) {
            $deliveryResult = $e;
        }
        if (function_exists('jeab_ordrar_remember_leveransdatum')) {
            $row = jeab_ordrar_remember_leveransdatum($row, $deliveryResult);
        }
    }
    return $row;
}

/**
 * ordrar.fortnox_rerun. Torr förhandsvisning av en Fortnox-order för en order.
 * dry=1 är standard och anropar inte Fortnox. dry=0 kräver confirm=SAM.
 * Inget kundmejl. Svaret har inget personnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_fortnox_rerun_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    $action = 'ordrar.fortnox_rerun';
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => $action, 'dry' => $dry ? 1 : 0, 'written' => 0, 'mail' => 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'mail' => 0, 'status' => 404];
    }
    $block = function_exists('jeab_contacts_strict_block') ? jeab_contacts_strict_block($row, 'fortnox') : '';
    $missing = function_exists('jeab_ordrar_fortnox_missing') ? jeab_ordrar_fortnox_missing($row) : [];
    $ready = $block === '' && $missing === [];
    $base = [
        'ok' => true,
        'action' => $action,
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'mail' => 0,
        'order_id' => $id,
        'ready' => $ready ? 1 : 0,
        'missing' => array_values($missing),
        'blocked' => $block === '' ? 0 : 1,
        'has_order' => trim((string) ($row['fortnox_order'] ?? '')) !== '' ? 1 : 0,
        'would_write' => $ready ? 1 : 0,
        'status' => 200,
    ];
    if ($dry) {
        $clean = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($base) : $base;

        return is_array($clean) ? $clean : $base;
    }
    if (trim((string) ($input['confirm'] ?? '')) !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'mail' => 0,
            'status' => 422,
        ];
    }
    if (!$ready) {
        $why = $block !== '' ? $block : 'Saknas för Fortnox-order.';
        $whyMasked = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($why) : $why;

        return [
            'ok' => false,
            'error' => is_string($whyMasked) && $whyMasked !== '' ? $whyMasked : 'Saknas för Fortnox-order.',
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'mail' => 0,
            'missing' => array_values($missing),
            'status' => 422,
        ];
    }
    try {
        $saved = jeab_ordrar_create_fortnox(['id' => $id, 'skip_precheck' => 1]);
    } catch (Throwable $e) {
        $masked = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($e->getMessage()) : '';
        $message = is_string($masked) ? trim($masked) : '';
        if ($message === '') {
            $message = 'Fortnox-ordern skapades inte.';
        }

        return [
            'ok' => false,
            'error' => $message,
            'action' => $action,
            'dry' => 0,
            'order_id' => $id,
            'written' => 0,
            'mail' => 0,
            'status' => 502,
        ];
    }
    $base['dry'] = 0;
    $base['written'] = 1;
    $base['fortnox_order'] = trim((string) ($saved['fortnox_order'] ?? ''));
    $clean = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($base) : $base;

    return is_array($clean) ? $clean : $base;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_fortnox_rerun_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.fortnox_rerun', 'status' => 401];
    }

    return jeab_ordrar_fortnox_rerun_run($store, $input);
}

function jeab_ordrar_fortnox_row_match_key(array $row): string
{
    $desc = trim((string) ($row['Description'] ?? ''));
    $desc = function_exists('mb_strtolower') ? mb_strtolower($desc, 'UTF-8') : strtolower($desc);
    $price = (int) round((float) ($row['Price'] ?? 0));
    $hw = strtoupper(trim((string) ($row['HouseWorkType'] ?? '')));
    if ($hw === 'EMPTYHOUSEWORK' || $hw === 'NONE') {
        $hw = '';
    }
    $qty = jeab_ordrar_fortnox_qty_text($row['DeliveredQuantity'] ?? '');
    if ($qty === '') {
        $qty = jeab_ordrar_fortnox_qty_text($row['OrderedQuantity'] ?? '');
    }
    if ($qty === '') {
        $qty = '1';
    }
    if ($desc === '' && $price === 0 && $hw === '') {
        return '';
    }
    return $hw . '|' . $qty . '|' . $price . '|' . $desc;
}

/** @param list<array<string,mixed>> $orderRows @param list<array<string,mixed>> $formulaRows */
function jeab_ordrar_fortnox_rows_match(array $orderRows, array $formulaRows): bool
{
    $norm = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = jeab_ordrar_fortnox_row_match_key($row);
            if ($key !== '') {
                $out[] = $key;
            }
        }
        sort($out);
        return $out;
    };
    $have = $norm($orderRows);
    $want = $norm($formulaRows);
    return $want !== [] && $have === $want;
}

/**
 * ordrar.fortnox_check. Torr som standard. Läser vilken Fortnox-order som
 * är kopplad och om dess rader stämmer med orderns kalkyl. Skriver aldrig.
 * dry=0 läser Fortnox. Svaret har inget personnummer.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_fortnox_check_run(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry') ? jeab_ordrar_backfill_wants_dry($input) : true;
    $id = (int) ($input['id'] ?? $input['order_id'] ?? 0);
    $action = 'ordrar.fortnox_check';
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange id', 'action' => $action, 'dry' => $dry ? 1 : 0, 'written' => 0, 'mail' => 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'written' => 0, 'mail' => 0, 'status' => 404];
    }
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    $formula = [];
    $formulaError = '';
    try {
        $formula = jeab_ordrar_fortnox_formula_order_rows($row);
    } catch (Throwable $e) {
        $masked = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($e->getMessage()) : '';
        $formulaError = is_string($masked) ? trim($masked) : '';
        if ($formulaError === '') {
            $formulaError = 'Kalkylrader saknas.';
        }
    }
    $source = is_array($row['fortnox_order_rows'] ?? null) ? $row['fortnox_order_rows'] : [];
    $live = 0;
    if (!$dry && $no !== '') {
        try {
            $source = jeab_ordrar_fortnox_order_rows(jeab_ordrar_fortnox_fetch_order($row));
            $live = 1;
        } catch (Throwable $e) {
            $masked = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($e->getMessage()) : '';
            $message = is_string($masked) ? trim($masked) : '';
            if ($message === '') {
                $message = 'Fortnox-ordern kunde inte hämtas.';
            }
            return [
                'ok' => false,
                'error' => $message,
                'action' => $action,
                'dry' => 0,
                'written' => 0,
                'mail' => 0,
                'order_id' => $id,
                'fortnox_order' => $no,
                'rows_match' => 0,
                'live' => 0,
                'status' => 502,
            ];
        }
    }
    $out = [
        'ok' => true,
        'action' => $action,
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'mail' => 0,
        'order_id' => $id,
        'fortnox_order' => $no,
        'rows_match' => ($no !== '' && $formulaError === '' && jeab_ordrar_fortnox_rows_match($source, $formula)) ? 1 : 0,
        'live' => $live,
        'order_rows' => count(array_values(array_filter($source, 'is_array'))),
        'formula_rows' => count($formula),
        'status' => 200,
    ];
    if ($formulaError !== '') {
        $out['formula_error'] = $formulaError;
    }
    $clean = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($out) : $out;

    return is_array($clean) ? $clean : $out;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_fortnox_check_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.fortnox_check', 'status' => 401];
    }

    return jeab_ordrar_fortnox_check_run($store, $input);
}

/** Husarbete-typer som i Fortnox orderrad. */
function jeab_ordrar_fortnox_housework_choices(): array
{
    return [
        '' => 'Ej husarbete',
        'SOLARCELLS' => 'Solceller',
        'STORAGESELFPRODUCEDELECTRICITY' => 'Lagring egenproducerad el',
        'CHARGINGSTATIONELECTRICVEHICLE' => 'Laddningspunkt elfordon',
        'OTHERCOSTS' => 'Övrig kostnad',
    ];
}

function jeab_ordrar_fortnox_housework_key(mixed $raw): string
{
    $key = strtoupper(trim((string) $raw));
    $choices = jeab_ordrar_fortnox_housework_choices();
    if ($key === 'EMPTYHOUSEWORK' || $key === 'NONE') {
        return '';
    }
    return array_key_exists($key, $choices) ? $key : '';
}

/** Sökande för grön teknik-taket. Kalkylens sökande, annars huvudperson plus medsökande. */
function jeab_ordrar_fx_people(array $row): int
{
    $kalkyl = $row['kalkyl'] ?? null;
    if (is_array($kalkyl) && is_numeric($kalkyl['sokande'] ?? null)) {
        return max(1, (int) round((float) $kalkyl['sokande']));
    }
    $extra = $row['medsokande'] ?? [];
    $n = is_array($extra) ? count($extra) : 0;
    return max(1, 1 + $n);
}

/** Satser och tak till fakturaformuläret. Räknar lokalt, anropar inte Fortnox. */
function jeab_ordrar_fx_gt_attrs(array $row): string
{
    if (!class_exists('FortnoxAPI')) {
        require_once JEAB_ROOT . '/src/fortnox.php';
    }
    $rates = json_encode(FortnoxAPI::greenTechRates(), JSON_UNESCAPED_SLASHES);
    if (!is_string($rates)) {
        $rates = '{}';
    }
    return ' data-gt-rates="' . e($rates) . '" data-gt-people="' . jeab_ordrar_fx_people($row) . '" data-gt-cap="' . FortnoxAPI::greenTechPersonCap() . '"';
}

/**
 * Att betala = radsumma minus möjlig grön teknik.
 * OTHERCOSTS och tom typ (Ej husarbete) ger inget avdrag. ROT/RUT ligger inte i satstabellen.
 *
 * @param list<array<string,mixed>> $rows editorrader eller Fortnox-orderrader
 */
function jeab_ordrar_fx_lines_att_betala(array $rows, int $people = 1): int
{
    if (!class_exists('FortnoxAPI')) {
        require_once JEAB_ROOT . '/src/fortnox.php';
    }
    $normalized = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $normalized[] = jeab_ordrar_fx_payable_row($row);
        }
    }
    $sum = 0.0;
    foreach ($normalized as $row) {
        $price = isset($row['Price']) && is_numeric($row['Price']) ? (float) $row['Price'] : 0.0;
        $qtyRaw = $row['DeliveredQuantity'] ?? 1;
        if (is_string($qtyRaw)) {
            $qtyRaw = str_replace(',', '.', trim($qtyRaw));
        }
        $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : 1.0;
        if ($qty <= 0) {
            $qty = 1.0;
        }
        $sum += $price * $qty;
    }
    $deduction = FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($normalized), max(1, $people));
    return (int) round($sum) - $deduction;
}

/** @param array<string,mixed> $row @return array<string,mixed> */
function jeab_ordrar_fx_payable_row(array $row): array
{
    if (isset($row['Price']) || array_key_exists('HouseWorkType', $row)) {
        return $row;
    }
    $hw = jeab_ordrar_fortnox_housework_key($row['housework'] ?? '');
    $priceRaw = str_replace(',', '.', trim((string) ($row['price'] ?? '')));
    $priceRaw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $priceRaw);
    $price = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
    $qty = jeab_ordrar_fortnox_qty_text($row['qty'] ?? '');
    if ($qty === '') {
        $qty = '1';
    }
    return [
        'Price' => $price,
        'DeliveredQuantity' => $qty,
        'HouseWork' => $hw !== '' && $hw !== 'OTHERCOSTS',
        'HouseWorkType' => $hw !== '' ? $hw : 'EMPTYHOUSEWORK',
    ];
}

function jeab_ordrar_fortnox_money(mixed $value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    $n = round((float) $value, 2);
    if (abs($n - round($n)) < 0.001) {
        return jeab_ordrar_kr((int) round($n)) . ' kr';
    }
    return number_format($n, 2, ',', ' ') . ' kr';
}

function jeab_ordrar_fortnox_order_body(array $payload): array
{
    if (isset($payload['Order']) && is_array($payload['Order'])) {
        return $payload['Order'];
    }
    return $payload;
}

/** @return list<array<string,mixed>> */
function jeab_ordrar_fortnox_order_rows(array $payload): array
{
    $order = jeab_ordrar_fortnox_order_body($payload);
    $rows = $order['OrderRows'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function jeab_ordrar_fortnox_att_betala(array $payload): string
{
    $order = jeab_ordrar_fortnox_order_body($payload);
    foreach (['TotalToPay', 'totalToPay', 'Balance'] as $key) {
        if (isset($order[$key]) && is_numeric($order[$key])) {
            return jeab_ordrar_fortnox_money($order[$key]);
        }
    }
    if (isset($order['Total']) && is_numeric($order['Total'])) {
        $total = (float) $order['Total'];
        $tax = isset($order['TaxReduction']) && is_numeric($order['TaxReduction']) ? (float) $order['TaxReduction'] : 0.0;
        return jeab_ordrar_fortnox_money($total - $tax);
    }
    return '';
}

function jeab_ordrar_fortnox_invoice_reference(array $payload): string
{
    $order = jeab_ordrar_fortnox_order_body($payload);
    $ref = trim((string) ($order['InvoiceReference'] ?? ''));
    if ($ref !== '' && $ref !== '0') {
        return $ref;
    }
    $invoice = $payload['Invoice'] ?? null;
    if (is_array($invoice)) {
        $no = trim((string) ($invoice['DocumentNumber'] ?? ''));
        if ($no !== '') {
            return $no;
        }
    }
    return '';
}

/**
 * Fyra redigerbara rader: de första från ordern, resten tomma.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array{row_id:string,article:string,description:string,housework:string,qty:string,price:string}>
 */
function jeab_ordrar_fortnox_editor_lines(array $rows): array
{
    $lines = [];
    $slice = array_slice($rows, 0, 4);
    foreach ($slice as $row) {
        $price = $row['Price'] ?? '';
        $priceText = is_numeric($price) ? (string) (0 + $price) : trim((string) $price);
        if ($priceText !== '' && is_numeric($priceText)) {
            $num = (float) $priceText;
            $priceText = abs($num - round($num)) < 0.001
                ? (string) (int) round($num)
                : rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
        }
        $hw = jeab_ordrar_fortnox_housework_key($row['HouseWorkType'] ?? '');
        if ($hw === '' && !empty($row['HouseWork'])) {
            $hw = jeab_ordrar_fortnox_housework_key($row['HouseWorkType'] ?? '');
        }
        $qty = jeab_ordrar_fortnox_qty_text($row['DeliveredQuantity'] ?? '');
        if ($qty === '') {
            $qty = jeab_ordrar_fortnox_qty_text($row['OrderedQuantity'] ?? '');
        }
        $lines[] = [
            'row_id' => trim((string) ($row['RowId'] ?? '')),
            'article' => trim((string) ($row['ArticleNumber'] ?? '')),
            'description' => trim((string) ($row['Description'] ?? '')),
            'housework' => $hw,
            'qty' => $qty,
            'price' => $priceText,
        ];
    }
    while (count($lines) < 4) {
        $lines[] = ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''];
    }
    return $lines;
}

function jeab_ordrar_fortnox_qty_text(mixed $value): string
{
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }
    if ($value === '' || $value === null || !is_numeric($value)) {
        return '';
    }
    $n = (float) $value;
    if ($n <= 0) {
        return '';
    }
    if (abs($n - round($n)) < 0.001) {
        return (string) (int) round($n);
    }
    return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
}

function jeab_ordrar_fortnox_line_sum_text(array $line): string
{
    $priceRaw = str_replace(',', '.', trim((string) ($line['price'] ?? '')));
    $priceRaw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $priceRaw);
    if ($priceRaw === '' || !is_numeric($priceRaw)) {
        return '';
    }
    $qty = (float) (jeab_ordrar_fortnox_qty_text($line['qty'] ?? '') !== '' ? jeab_ordrar_fortnox_qty_text($line['qty'] ?? '') : '1');
    return jeab_ordrar_fortnox_money((float) $priceRaw * $qty);
}

function jeab_ordrar_fortnox_line_blank(array $line): bool
{
    return trim((string) ($line['article'] ?? '')) === ''
        && trim((string) ($line['description'] ?? '')) === ''
        && trim((string) ($line['price'] ?? '')) === '';
}

/** @param list<array<string,mixed>> $rows @return array<string,bool> */
function jeab_ordrar_fortnox_live_row_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['RowId'] ?? ''));
        if ($id !== '' && $id !== '0') {
            $ids[$id] = true;
        }
    }
    return $ids;
}

/**
 * Samma RowId är samma Fortnox-rad som råkat ligga två gånger (till exempel
 * två identiska Schablon). Den första behålls, med sitt pris. Tomt id är en
 * egen rad och lämnas. Olika id behålls även när text och pris är lika.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_dedupe_row_ids(array $rows): array
{
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['RowId'] ?? ''));
        if ($id === '' || $id === '0') {
            $out[] = $row;
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * Samma regel som FortnoxAPI::cleanOrderRows: RowId följer med bara när
 * raden finns på dokumentet som ska skrivas. Annars tas id:t bort före PUT.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $liveRows
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_drop_stale_row_ids(array $rows, array $liveRows): array
{
    $liveIds = jeab_ordrar_fortnox_live_row_ids($liveRows);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['RowId'] ?? ''));
        if ($id === '' || $id === '0' || !isset($liveIds[$id])) {
            unset($row['RowId'], $row['row_id']);
        }
        $out[] = $row;
    }
    return $out;
}

/** Rad-id från en låst order får inte följa med till nästa dokument. */
function jeab_ordrar_fortnox_forget_row_ids(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        unset($row['RowId'], $row['row_id']);
        $out[] = $row;
    }
    return $out;
}

function jeab_ordrar_fortnox_row_content_key(array $row): string
{
    $desc = trim((string) ($row['Description'] ?? ''));
    $desc = function_exists('mb_strtolower') ? mb_strtolower($desc, 'UTF-8') : strtolower($desc);
    $hw = strtoupper(trim((string) ($row['HouseWorkType'] ?? '')));
    if ($hw === 'EMPTYHOUSEWORK' || $hw === 'NONE') {
        $hw = '';
    }
    if ($desc === '' && $hw === '') {
        return '';
    }
    return $hw . "\n" . $desc;
}

/**
 * Lägg tillbaka kalkylrader som saknas, utan rad-id.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $formula
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_complete_with_formula(array $rows, array $formula): array
{
    $have = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = jeab_ordrar_fortnox_row_content_key($row);
        if ($key !== '') {
            $have[$key] = true;
        }
    }
    foreach ($formula as $row) {
        if (!is_array($row)) {
            continue;
        }
        $key = jeab_ordrar_fortnox_row_content_key($row);
        if ($key === '' || isset($have[$key])) {
            continue;
        }
        unset($row['RowId'], $row['row_id'], $row['Total'], $row['ContributionPercent'], $row['ContributionValue']);
        $rows[] = $row;
        $have[$key] = true;
    }
    return $rows;
}

/**
 * Ny order efter en låst: behåll dokumentets egna rader, skriv över med
 * formuläret, och ta aldrig ett rad-id som inte finns på det nya dokumentet.
 *
 * @param list<array<string,mixed>> $existing
 * @param list<array<string,mixed>> $edited
 * @param list<array<string,mixed>> $formula
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_merge_fresh_rows(array $existing, array $edited, array $formula): array
{
    $liveIds = jeab_ordrar_fortnox_live_row_ids($existing);
    $edited = array_slice(array_values($edited), 0, 4);
    $out = [];
    $n = max(count($existing), count($edited));
    for ($i = 0; $i < $n; $i++) {
        $old = isset($existing[$i]) && is_array($existing[$i]) ? $existing[$i] : [];
        $line = isset($edited[$i]) && is_array($edited[$i]) ? $edited[$i] : null;
        if ($line !== null && !jeab_ordrar_fortnox_line_blank($line)) {
            $out[] = jeab_ordrar_fortnox_editor_to_row($line, $old, $liveIds);
            continue;
        }
        if ($old === []) {
            continue;
        }
        $id = trim((string) ($old['RowId'] ?? ''));
        if ($id === '' || $id === '0' || !isset($liveIds[$id])) {
            unset($old['RowId']);
        }
        $out[] = $old;
    }
    return jeab_ordrar_fortnox_drop_stale_row_ids(
        jeab_ordrar_fortnox_dedupe_row_ids(jeab_ordrar_fortnox_complete_with_formula($out, $formula)),
        $existing
    );
}

/**
 * @param list<array<string,mixed>> $existing
 * @param list<array{row_id?:string,article?:string,description?:string,housework?:string,price?:string}> $edited
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_merge_order_rows(array $existing, array $edited): array
{
    $out = [];
    $liveIds = jeab_ordrar_fortnox_live_row_ids($existing);
    $edited = array_slice(array_values($edited), 0, 4);
    foreach ($edited as $i => $line) {
        if (!is_array($line) || jeab_ordrar_fortnox_line_blank($line)) {
            continue;
        }
        $old = isset($existing[$i]) && is_array($existing[$i]) ? $existing[$i] : [];
        $out[] = jeab_ordrar_fortnox_editor_to_row($line, $old, $liveIds);
    }
    foreach (array_slice($existing, 4) as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return jeab_ordrar_fortnox_drop_stale_row_ids(jeab_ordrar_fortnox_dedupe_row_ids($out), $existing);
}

/**
 * @param array<string,mixed> $line
 * @param array<string,mixed> $old
 * @param array<string,bool>|null $liveIds rad-id som finns på dokumentet som skrivs
 */
function jeab_ordrar_fortnox_editor_to_row(array $line, array $old = [], ?array $liveIds = null): array
{
    $row = $old;
    unset($row['Total'], $row['ContributionPercent'], $row['ContributionValue']);
    $article = trim((string) ($line['article'] ?? ''));
    $desc = trim((string) ($line['description'] ?? ''));
    $price = (int) round(jeab_purchase_amount($line['price'] ?? 0));
    $qtyText = jeab_ordrar_fortnox_qty_text($line['qty'] ?? '');
    if ($qtyText === '') {
        $qtyText = '1';
    }
    $hw = jeab_ordrar_fortnox_housework_key($line['housework'] ?? '');
    if ($article !== '') {
        $row['ArticleNumber'] = $article;
    } else {
        unset($row['ArticleNumber']);
    }
    $row['Description'] = $desc;
    $row['Price'] = $price;
    $row['DeliveredQuantity'] = $qtyText;
    $row['OrderedQuantity'] = $qtyText;
    $postedId = trim((string) ($line['row_id'] ?? ''));
    $fromOld = trim((string) ($old['RowId'] ?? ''));
    $rowId = '';
    foreach ([$postedId, $fromOld] as $candidate) {
        if ($candidate === '' || $candidate === '0' || $candidate === $rowId) {
            continue;
        }
        if ($liveIds !== null && !isset($liveIds[$candidate])) {
            continue;
        }
        $rowId = $candidate;
        break;
    }
    if ($rowId !== '') {
        $row['RowId'] = is_numeric($rowId) ? (int) $rowId : $rowId;
    } else {
        unset($row['RowId']);
    }
    if ($hw === '') {
        $row['HouseWork'] = false;
        $row['HouseWorkType'] = 'EMPTYHOUSEWORK';
        unset($row['HouseWorkHoursToReport']);
    } else {
        $row['HouseWork'] = $hw !== 'OTHERCOSTS';
        $row['HouseWorkType'] = $hw;
        $hours = 0;
        if (class_exists('FortnoxAPI')) {
            $hours = FortnoxAPI::houseWorkHoursFor($hw);
        }
        if ($hours > 0) {
            $row['HouseWorkHoursToReport'] = $hours;
        } else {
            unset($row['HouseWorkHoursToReport']);
        }
    }
    return $row;
}

/** @return list<array{row_id:string,article:string,description:string,housework:string,qty:string,price:string}> */
function jeab_ordrar_fortnox_posted_lines(array $input): array
{
    $articles = is_array($input['fx_article'] ?? null) ? $input['fx_article'] : [];
    $descs = is_array($input['fx_desc'] ?? null) ? $input['fx_desc'] : [];
    $hws = is_array($input['fx_hw'] ?? null) ? $input['fx_hw'] : [];
    $qtys = is_array($input['fx_qty'] ?? null) ? $input['fx_qty'] : [];
    $prices = is_array($input['fx_price'] ?? null) ? $input['fx_price'] : [];
    $ids = is_array($input['fx_rowid'] ?? null) ? $input['fx_rowid'] : [];
    $lines = [];
    for ($i = 0; $i < 4; $i++) {
        $lines[] = [
            'row_id' => trim((string) ($ids[$i] ?? '')),
            'article' => trim((string) ($articles[$i] ?? '')),
            'description' => trim((string) ($descs[$i] ?? '')),
            'housework' => jeab_ordrar_fortnox_housework_key($hws[$i] ?? ''),
            'qty' => jeab_ordrar_fortnox_qty_text($qtys[$i] ?? ''),
            'price' => trim((string) ($prices[$i] ?? '')),
        ];
    }
    return $lines;
}

/**
 * Vilka fakturarader användaren har handredigerat i formuläret (fx_touched[]).
 *
 * @return list<bool>
 */
function jeab_ordrar_fortnox_touched_flags(array $input): array
{
    $raw = $input['fx_touched'] ?? [];
    if (!is_array($raw)) {
        $raw = [];
    }
    $out = [];
    for ($i = 0; $i < 4; $i++) {
        $v = strtolower(trim((string) ($raw[$i] ?? '')));
        $out[] = $v !== '' && $v !== '0' && $v !== 'false' && $v !== 'off';
    }
    return $out;
}

/**
 * Tidigare sparade handredigerade rader (index 0–3).
 *
 * @return list<int>
 */
function jeab_ordrar_fortnox_manual_indexes(array $row): array
{
    $raw = $row['fortnox_manual_rows'] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $i) {
        $n = (int) $i;
        if ($n >= 0 && $n < 4) {
            $out[] = $n;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Kalkylens rader för nytt Att betala, men handredigerade rader lämnas.
 *
 * @param list<array{row_id:string,article:string,description:string,housework:string,qty:string,price:string}> $posted
 * @param list<array{row_id:string,article:string,description:string,housework:string,qty:string,price:string}> $formula
 * @param list<bool> $touched
 * @param list<int> $manual
 * @return array{lines:list<array{row_id:string,article:string,description:string,housework:string,qty:string,price:string}>,manual:list<int>}
 */
function jeab_ordrar_fortnox_keep_manual_editor_lines(array $posted, array $formula, array $touched, array $manual): array
{
    $empty = ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''];
    $lines = [];
    $kept = [];
    for ($i = 0; $i < 4; $i++) {
        $post = isset($posted[$i]) && is_array($posted[$i]) ? $posted[$i] : $empty;
        $form = isset($formula[$i]) && is_array($formula[$i]) ? $formula[$i] : $empty;
        $keep = (!empty($touched[$i]) || in_array($i, $manual, true))
            && !jeab_ordrar_fortnox_line_blank($post);
        if ($keep) {
            $lines[] = $post;
            $kept[] = $i;
            continue;
        }
        if (!jeab_ordrar_fortnox_line_blank($form)) {
            $rid = trim((string) ($post['row_id'] ?? ''));
            if ($rid !== '') {
                $form['row_id'] = $rid;
            }
            $lines[] = $form;
            continue;
        }
        $lines[] = $post;
    }
    return ['lines' => $lines, 'manual' => $kept];
}

/**
 * Kort text om vilka rader som ändrades (benämning gammalt → nytt pris).
 *
 * @param list<array{row_id?:string,article?:string,description?:string,housework?:string,qty?:string,price?:string}> $before
 * @param list<array{row_id?:string,article?:string,description?:string,housework?:string,qty?:string,price?:string}> $after
 */
function jeab_ordrar_fortnox_editor_change_text(array $before, array $after): string
{
    $bits = [];
    for ($i = 0; $i < 4; $i++) {
        $b = isset($before[$i]) && is_array($before[$i]) ? $before[$i] : [];
        $a = isset($after[$i]) && is_array($after[$i]) ? $after[$i] : [];
        $bp = trim((string) ($b['price'] ?? ''));
        $ap = trim((string) ($a['price'] ?? ''));
        $bd = trim((string) ($b['description'] ?? ''));
        $ad = trim((string) ($a['description'] ?? ''));
        if ($bp === $ap && $bd === $ad) {
            continue;
        }
        $name = $ad !== '' ? $ad : ($bd !== '' ? $bd : 'Rad ' . ($i + 1));
        if ($bp !== $ap && $bp !== '' && $ap !== '') {
            $bits[] = $name . ' ' . $bp . ' → ' . $ap;
        } elseif ($bd !== $ad) {
            $from = $bd !== '' ? $bd : 'tom';
            $to = $ad !== '' ? $ad : 'tom';
            $bits[] = $from . ' → ' . $to;
        }
    }
    return implode(' · ', $bits);
}

/**
 * @return 'makulera'|'kreditera'|'already'|'booked'
 */
function jeab_ordrar_faktura_void_plan(array $invoice, string $mode): string
{
    $inv = is_array($invoice['Invoice'] ?? null) ? $invoice['Invoice'] : $invoice;
    if (!empty($inv['Cancelled'])) {
        return 'already';
    }
    if ($mode === 'makulera' && !empty($inv['Booked'])) {
        return 'booked';
    }

    return $mode === 'kreditera' ? 'kreditera' : 'makulera';
}

function jeab_ordrar_fortnox_credit_number(array $payload): string
{
    $inv = is_array($payload['Invoice'] ?? null) ? $payload['Invoice'] : $payload;
    if (!is_array($inv)) {
        return '';
    }
    foreach (['DocumentNumber', 'InvoiceNumber', 'GivenNumber'] as $key) {
        $value = trim((string) ($inv[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function jeab_ordrar_faktura_void(array $input, string $mode): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $number = jeab_ordrar_invoice_number($row);
    if ($number === '') {
        throw new InvalidArgumentException('Ingen faktura att ändra.');
    }
    if ($mode === 'kreditera' && trim((string) ($row['fortnox_credit_invoice'] ?? '')) !== '') {
        throw new InvalidArgumentException('Fakturan är redan krediterad.');
    }
    if ($mode === 'makulera' && !empty($row['fortnox_invoice_cancelled'])) {
        throw new InvalidArgumentException('Fakturan är redan makulerad.');
    }
    $api = jeab_ordrar_fortnox_api_for($row);
    $plan = jeab_ordrar_faktura_void_plan($api->getInvoice($number), $mode);
    if ($plan === 'already') {
        throw new InvalidArgumentException('Fakturan är redan makulerad.');
    }
    if ($plan === 'booked') {
        throw new InvalidArgumentException('Fakturan är bokförd. Kreditera i stället.');
    }
    $creditNo = '';
    if ($plan === 'kreditera') {
        $made = $api->creditInvoice($number);
        $creditNo = jeab_ordrar_fortnox_credit_number(is_array($made) ? $made : []);
        if ($creditNo === '' || $creditNo === $number) {
            $ref = '';
            $inv = is_array($made['Invoice'] ?? null) ? $made['Invoice'] : [];
            $ref = trim((string) ($inv['CreditInvoiceReference'] ?? $inv['DocumentNumber'] ?? ''));
            $creditNo = $ref !== '' && $ref !== $number ? $ref : $creditNo;
        }
    } else {
        $api->cancelInvoice($number);
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    $text = $plan === 'kreditera'
        ? 'Faktura krediterad' . ($creditNo !== '' ? ' ' . $creditNo : '')
        : 'Faktura makulerad';
    if ($id > 0 && function_exists('jeab_purchase_patch')) {
        $saved = jeab_purchase_patch($id, static function (array $current) use ($plan, $creditNo, $text): array {
            if ($plan === 'kreditera') {
                $current['fortnox_credit_invoice'] = $creditNo;
            } else {
                $current['fortnox_invoice_cancelled'] = true;
            }
            if (function_exists('jeab_ordrar_activity_append')) {
                $current = jeab_ordrar_activity_append($current, $text, '', $plan === 'kreditera' ? 'kredit:' . $creditNo : 'makulerad', false);
            }
            $current['updated_at'] = function_exists('jeab_now') ? jeab_now() : (string) ($current['updated_at'] ?? '');

            return $current;
        });
        if (is_array($saved)) {
            return $saved;
        }
    }
    if ($plan === 'kreditera') {
        $row['fortnox_credit_invoice'] = $creditNo;
    } else {
        $row['fortnox_invoice_cancelled'] = true;
    }

    return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
}

function jeab_ordrar_faktura_makulera(array $input): array
{
    return jeab_ordrar_faktura_void($input, 'makulera');
}

function jeab_ordrar_faktura_kreditera(array $input): array
{
    return jeab_ordrar_faktura_void($input, 'kreditera');
}

function jeab_ordrar_fortnox_api_for(array $row): FortnoxAPI
{
    $apiFile = JEAB_ROOT . '/src/fortnox.php';
    if (!is_file($apiFile)) {
        throw new InvalidArgumentException('Fortnox är inte kopplad på servern.');
    }
    require_once $apiFile;
    if (!is_file(jeab_fortnox_config_path()) || !is_file(jeab_fortnox_tokens_path())) {
        throw new InvalidArgumentException('Fortnox är inte kopplad på servern.');
    }
    return new FortnoxAPI(jeab_ordrar_fortnox_account($row['company'] ?? null));
}

/**
 * Order rows for a laddbox/batteri/sol create.
 * Prefer the Fortnox order that was just written. If that read fails, use the
 * same Offert rows as createKopOrder (jeab_offert_fortnox_kop_rows via kopOrderRows).
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_capture_rows(array $row): array
{
    $kind = function_exists('jeab_ordrar_ui_kind') ? jeab_ordrar_ui_kind($row['kind'] ?? '') : '';
    $captures = $kind === 'laddbox' || $kind === 'batteri' || $kind === 'sol';
    if (!$captures && (!function_exists('jeab_ordrar_bekrafta_slutfor') || !jeab_ordrar_bekrafta_slutfor($row))) {
        return $row;
    }
    $snapshot = [];
    try {
        $snapshot = jeab_ordrar_fortnox_fetch_order($row);
    } catch (Throwable) {
        $snapshot = [];
    }
    $rows = jeab_ordrar_fortnox_order_rows($snapshot);
    if ($rows === []) {
        try {
            $rows = jeab_ordrar_fortnox_formula_order_rows($row);
        } catch (Throwable) {
            return $row;
        }
        $snapshot = ['Order' => ['OrderRows' => $rows]];
    }
    if ($rows === []) {
        return $row;
    }
    $row['fortnox_order_rows'] = $rows;
    $pay = jeab_ordrar_fortnox_att_betala($snapshot);
    if ($pay !== '') {
        $row['fortnox_att_betala'] = $pay;
    }
    return $row;
}

/**
 * Same stack as Offert → Fortnox. Laddbox: jeab_offert_fortnox_kop_rows
 * ((kundpris − 45) × 2 + Framkörning 45). Batteri: h28 + h29 via jeab_offert_calc.
 * Sol: Batteri i10, Solpaneler i11, Schablon i15 − i10 − i11.
 *
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_formula_order_rows(array $row): array
{
    $payload = jeab_ordrar_fortnox_payload($row);
    $kind = (string) ($payload['kind'] ?? '');
    $price = (int) ($payload['price'] ?? 0);
    $what = (string) ($payload['install_what'] ?? '');
    $apiFile = JEAB_ROOT . '/src/fortnox.php';
    if (!class_exists('FortnoxAPI') && is_file($apiFile)) {
        require_once $apiFile;
    }
    if (!class_exists('FortnoxAPI')) {
        throw new InvalidArgumentException('Fortnox-rader saknas.');
    }
    $api = new FortnoxAPI(jeab_ordrar_fortnox_account($row['company'] ?? null));
    return $api->kopOrderRows($kind, $price, $what, $payload);
}

/** @param list<array<string,mixed>> $rows */
function jeab_ordrar_invoice_has_schablon(array $rows): bool
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $desc = (string) ($row['Description'] ?? $row['description'] ?? $row['desc'] ?? '');
        if (preg_match('/schablon/iu', $desc) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Rader som faktureringen visar: sparade Fortnox-rader, annars kalkylens rader.
 *
 * @param array<string,mixed> $row
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_invoice_rows_for_schablon(array $row): array
{
    $stored = is_array($row['fortnox_order_rows'] ?? null) ? $row['fortnox_order_rows'] : [];
    if ($stored !== []) {
        return $stored;
    }
    try {
        return jeab_ordrar_fortnox_formula_order_rows($row);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Läsning: ordrar vars fakturering saknar en Schablon-rad.
 * Skriver inget och rör inte bokningstider. Svaret har inget personnummer.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_ordrar_schablon_check(array $input = []): array
{
    $limit = (int) ($input['limit'] ?? 100);
    if ($limit < 1) {
        $limit = 100;
    }
    if ($limit > 500) {
        $limit = 500;
    }
    $missing = [];
    $checked = 0;
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = function_exists('jeab_ordrar_ui_kind') ? jeab_ordrar_ui_kind($row['kind'] ?? '') : '';
        if ($kind !== 'batteri' && $kind !== 'sol') {
            continue;
        }
        $checked++;
        $rows = jeab_ordrar_invoice_rows_for_schablon($row);
        if (jeab_ordrar_invoice_has_schablon($rows)) {
            continue;
        }
        $price = function_exists('jeab_ordrar_fortnox_price') ? jeab_ordrar_fortnox_price($row) : (int) ($row['pris'] ?? 0);
        if ($rows === [] && $price <= 0) {
            continue;
        }
        $missing[] = [
            'id' => (int) ($row['reminder_id'] ?? $key),
            'company' => trim((string) ($row['company'] ?? '')),
            'kind' => $kind,
            'fortnox_order' => trim((string) ($row['fortnox_order'] ?? '')),
        ];
    }

    return [
        'ok' => true,
        'action' => 'ordrar.schablon_check',
        'read_only' => 1,
        'checked' => $checked,
        'missing' => count($missing),
        'orders' => array_slice($missing, 0, $limit),
    ];
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function jeab_ordrar_schablon_check_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.schablon_check', 'status' => 401];
    }
    $result = jeab_ordrar_schablon_check($input);
    $result['status'] = 200;

    return $result;
}

function jeab_ordrar_fortnox_store_rows(array $row, array $orderRows, string $attBetala): void
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_get') || !function_exists('jeab_purchase_put')) {
        return;
    }
    try {
        $current = jeab_purchase_get($id);
    } catch (Throwable) {
        return;
    }
    if (!is_array($current)) {
        return;
    }
    $current['fortnox_order_rows'] = $orderRows;
    if ($attBetala !== '') {
        $current['fortnox_att_betala'] = $attBetala;
    }
    $current['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    try {
        jeab_purchase_put($current);
    } catch (Throwable) {
        // Visningen får inte falla om sparningen av hämtade rader misslyckas.
    }
}

function jeab_ordrar_fortnox_fetch_order(array $row): array
{
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    if ($no === '') {
        return [];
    }
    if (isset($GLOBALS['_JEAB_FORTNOX_ORDER_GET']) && is_callable($GLOBALS['_JEAB_FORTNOX_ORDER_GET'])) {
        $got = ($GLOBALS['_JEAB_FORTNOX_ORDER_GET'])($row);
        return is_array($got) ? $got : [];
    }
    try {
        return jeab_ordrar_fortnox_api_for($row)->getOrder($no);
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        jeab_ordrar_fortnox_fail($msg, 'Fortnox-ordern kunde inte hämtas.');
    }
}

function jeab_ordrar_fortnox_formula_rows_or_empty(array $row): array
{
    try {
        return jeab_ordrar_fortnox_formula_order_rows($row);
    } catch (Throwable) {
        return [];
    }
}

/**
 * @param list<array<string,mixed>> $edited
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_rows_to_write(array $row, array $current, array $edited, bool $fresh): array
{
    $live = jeab_ordrar_fortnox_order_rows($current);
    if ($fresh) {
        return jeab_ordrar_fortnox_merge_fresh_rows($live, $edited, jeab_ordrar_fortnox_formula_rows_or_empty($row));
    }
    return jeab_ordrar_fortnox_merge_order_rows($live, $edited);
}

function jeab_ordrar_fortnox_write_lines(array $row, array $edited, bool $fresh = false): array
{
    if (jeab_ordrar_fortnox_attach_locked($row)) {
        throw new InvalidArgumentException(jeab_ordrar_fortnox_attach_error());
    }
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    if ($no === '') {
        throw new InvalidArgumentException('Fortnox-order saknas.');
    }
    $blank = true;
    foreach ($edited as $line) {
        if (is_array($line) && !jeab_ordrar_fortnox_line_blank($line)) {
            $blank = false;
            break;
        }
    }
    if ($blank && !$fresh) {
        return jeab_ordrar_fortnox_fetch_order($row);
    }
    $current = jeab_ordrar_fortnox_fetch_order($row);
    $liveRows = jeab_ordrar_fortnox_order_rows($current);
    $merged = jeab_ordrar_fortnox_drop_stale_row_ids(
        jeab_ordrar_fortnox_rows_to_write($row, $current, $edited, $fresh),
        $liveRows
    );
    try {
        if (isset($GLOBALS['_JEAB_FORTNOX_ORDER_PUT']) && is_callable($GLOBALS['_JEAB_FORTNOX_ORDER_PUT'])) {
            $saved = ($GLOBALS['_JEAB_FORTNOX_ORDER_PUT'])($no, $merged, $row);
            return is_array($saved) ? $saved : ['Order' => ['DocumentNumber' => $no, 'OrderRows' => $merged]];
        }
        return jeab_ordrar_fortnox_api_for($row)->saveOrderRows($no, $merged);
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        jeab_ordrar_fortnox_fail($msg, 'Fortnox-ordern kunde inte uppdateras.', $no);
    }
}

function jeab_ordrar_fortnox_make_invoice(array $row): array
{
    if (jeab_ordrar_fortnox_attach_locked($row)) {
        throw new InvalidArgumentException(jeab_ordrar_fortnox_attach_error());
    }
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    if ($no === '') {
        throw new InvalidArgumentException('Fortnox-order saknas.');
    }
    if (isset($GLOBALS['_JEAB_FORTNOX_INVOICE']) && is_callable($GLOBALS['_JEAB_FORTNOX_INVOICE'])) {
        $made = ($GLOBALS['_JEAB_FORTNOX_INVOICE'])($no, $row);
        return is_array($made) ? $made : [];
    }
    try {
        $made = jeab_ordrar_fortnox_api_for($row)->createInvoiceFromOrder($no);
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        jeab_ordrar_fortnox_fail($msg, 'Fortnox kunde inte skapa fakturan.');
    }
    return jeab_ordrar_fortnox_stamp_delivery($row, is_array($made) ? $made : []);
}

/** Fakturan och ordern får bokningens installationsdag som DeliveryDate. */
function jeab_ordrar_fortnox_stamp_delivery(array $row, array $made): array
{
    $file = __DIR__ . '/fortnox_leveransdatum.php';
    if (is_file($file)) {
        require_once $file;
    }
    if (function_exists('jeab_fortnox_leveransdatum_after_invoice')) {
        $deliveryResult = null;
        try {
            $deliveryResult = jeab_fortnox_leveransdatum_after_invoice($row, $made);
        } catch (Throwable $e) {
            $deliveryResult = $e;
        }
        if (function_exists('jeab_ordrar_remember_leveransdatum')) {
            jeab_ordrar_remember_leveransdatum($row, $deliveryResult);
        }
    }
    return $made;
}

/**
 * Beslut för grön teknik när rader ändras.
 * Obegränsad ansökan följer möjlig skattereduktion. Ett lägre belopp som
 * användaren satt lämnas, utom när det nya underlaget inte räcker.
 *
 * @param list<array<string,mixed>> $beforeRows
 * @param list<array<string,mixed>> $afterRows
 * @return array{update:bool,capped:bool,asked:int,mojlig:int,splits:list<list<array{WorkType:string,AskedAmount:int}>>}
 */
function jeab_ordrar_gt_sync_plan(
    array $beforeRows,
    array $afterRows,
    int $currentAsked,
    int $people,
    bool $hasApplication,
    bool $explicitCap,
    int $storedAsked = 0,
    bool $followsKalkyl = false
): array {
    if (!class_exists('FortnoxAPI')) {
        require_once JEAB_ROOT . '/src/fortnox.php';
    }
    $people = max(1, $people);
    $beforeBases = FortnoxAPI::houseworkBases($beforeRows);
    $afterBases = FortnoxAPI::houseworkBases($afterRows);
    $beforePossible = FortnoxAPI::greenTechPossible($beforeBases, $people);
    $possible = FortnoxAPI::greenTechPossible($afterBases, $people);
    $slack = max(15, $people * 3);
    $tracksStored = $storedAsked > 0 && abs($currentAsked - $storedAsked) <= $slack;
    $tracksPossible = $hasApplication && $currentAsked + $slack >= $beforePossible && $currentAsked <= $beforePossible + $slack;
    if ($explicitCap && $possible > 0 && $currentAsked + $slack >= $possible && $currentAsked <= $possible + $slack) {
        $explicitCap = false;
    }
    // Raderna är Kalkylens: Att betala = Kundpris kräver hela möjliga
    // skattereduktionen. En gammal lägre ansökan är inget tak.
    if ($followsKalkyl) {
        $explicitCap = false;
    }
    $tracking = $followsKalkyl || !$explicitCap && (
        !$hasApplication
        || $tracksPossible
        || $tracksStored
        || ($beforePossible === 0 && $currentAsked === 0)
    );
    if ($tracking) {
        $target = $possible;
        // Kalkylens Att betala är radsumman minus hela möjliga skattereduktionen.
        // En ansökan en eller ett par kronor under (kronan som drogs per person)
        // ska upp till det beloppet. Exakt träff skrivs inte om.
        $atTarget = $hasApplication && $currentAsked === $possible;
        $update = !$atTarget;
        if (!$hasApplication && $possible <= 0) {
            $update = false;
        }
        $capped = false;
    } else {
        $target = min($currentAsked, $possible);
        $update = $hasApplication && $currentAsked > $possible;
        $capped = true;
    }
    // Nollmål medan grön-teknik-rader finns kvar: behåll ansökan.
    // Annars går synken mot DELETE och Fortnox/UI svarar «kan inte tas bort».
    if ($target <= 0 && $hasApplication && FortnoxAPI::rowsHaveGreenTechHouseWork($afterRows)) {
        return [
            'update' => false,
            'capped' => $capped,
            'asked' => $currentAsked,
            'mojlig' => $possible,
            'splits' => [],
        ];
    }
    return [
        'update' => $update,
        'capped' => $capped,
        'asked' => $target,
        'mojlig' => $possible,
        'splits' => FortnoxAPI::greenTechSplits($afterBases, $people, $target),
    ];
}

/**
 * Kalkylen för ordern, med Kalkylens egna funktioner: raderna
 * (jeab_offert_fortnox_kop_rows, samma som Bekräfta skriver), sökande och
 * Att betala (jeab_offert_rows_att_betala = rader − hela grön teknik).
 * Fakturering räknar inte själv: den jämför och rättar mot detta.
 *
 * @return array{rows:list<array<string,mixed>>,people:int,att_betala:int}|null
 */
function jeab_ordrar_kalkyl_logical(array $row): ?array
{
    try {
        if (!function_exists('jeab_offert_fortnox_kop_rows')) {
            require_once JEAB_ROOT . '/src/offert.php';
        }
        if (!class_exists('FortnoxAPI')) {
            require_once JEAB_ROOT . '/src/fortnox.php';
        }
        $payload = jeab_ordrar_fortnox_payload($row);
        $kind = (string) ($payload['kind'] ?? '');
        $price = (int) ($payload['price'] ?? 0);
        $rows = jeab_offert_fortnox_kop_rows($kind, $price, (string) ($payload['install_what'] ?? ''), $payload);
        $input = jeab_offert_input_from_purchase($payload + ['kundpris' => $price], $kind);
        $people = max(1, (int) round((float) ($input['sokande'] ?? 1)));
    } catch (Throwable) {
        return null;
    }
    if ($rows === []) {
        return null;
    }
    return ['rows' => $rows, 'people' => $people, 'att_betala' => jeab_offert_rows_att_betala($rows, $people)];
}

/**
 * Fortnox-rader i Kalkylens radformat (price = à-pris × antal, hw bara för
 * grön teknik med husarbete på).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array{article:string,desc:string,price:int,hw:?string,hours:int}>
 */
function jeab_ordrar_fortnox_logical_rows(array $rows): array
{
    if (!class_exists('FortnoxAPI')) {
        require_once JEAB_ROOT . '/src/fortnox.php';
    }
    $rates = FortnoxAPI::greenTechRates();
    $out = [];
    foreach ($rows as $r) {
        if (!is_array($r) || !isset($r['Price']) || !is_numeric($r['Price'])) {
            continue;
        }
        $qty = $r['DeliveredQuantity'] ?? $r['OrderedQuantity'] ?? 1;
        $qty = is_string($qty) ? str_replace(',', '.', trim($qty)) : $qty;
        $qty = is_numeric($qty) && (float) $qty > 0 ? (float) $qty : 1.0;
        $type = (string) ($r['HouseWorkType'] ?? '');
        $flag = $r['HouseWork'] ?? true;
        $on = !($flag === false || $flag === 0 || (is_string($flag) && in_array(strtolower(trim($flag)), ['0', 'false', 'no'], true)));
        $out[] = [
            'article' => (string) ($r['ArticleNumber'] ?? ''),
            'desc' => (string) ($r['Description'] ?? ''),
            'price' => (int) round((float) $r['Price'] * $qty),
            'hw' => $on && isset($rates[$type]) ? $type : null,
            'hours' => 0,
        ];
    }
    return $out;
}

/**
 * Följer orderraderna Kalkylen? Att betala på raderna med hela grön teknik
 * (Kalkylens jeab_offert_rows_att_betala, Kalkylens sökande) är Kalkylens
 * Att betala ±2 kr (Schablonens avrundning). Då ska ansökan vara hela möjliga
 * beloppet. Hässelby strandväg 47 / 1872 låg kvar på en gammal ansökan
 * 81 706 fast raderna var Kalkylens med möjligt 90 208.
 *
 * @param list<array<string,mixed>> $rows
 */
function jeab_ordrar_gt_follows_kalkyl(array $row, array $rows): bool
{
    return jeab_ordrar_gt_kalkyl_delta($row, $rows) !== null;
}

/**
 * Kronor som raderna ligger över Kalkylens Att betala (−2…2), eller null när
 * raderna inte är Kalkylens.
 *
 * @param list<array<string,mixed>> $rows
 */
function jeab_ordrar_gt_kalkyl_delta(array $row, array $rows): ?int
{
    if ($rows === []) {
        return null;
    }
    $kalkyl = jeab_ordrar_kalkyl_logical($row);
    if ($kalkyl === null) {
        return null;
    }
    $logical = jeab_ordrar_fortnox_logical_rows($rows);
    $green = array_filter($logical, static fn ($r) => $r['hw'] !== null && $r['price'] > 0);
    if ($green === []) {
        return null;
    }
    $delta = jeab_offert_rows_att_betala($logical, $kalkyl['people']) - $kalkyl['att_betala'];
    return abs($delta) <= 2 ? $delta : null;
}

/**
 * Raderna är Kalkylens men Schablonen (rad utan grön teknik) är en eller två
 * kronor fel, till exempel 7 295 från äldre avrundning mot Kalkylens 7 296.
 * Samma rättning som Kalkylen gör (jeab_offert_align_rows_to_att_betala):
 * resten läggs på sista raden utan grön teknik.
 *
 * @param list<array<string,mixed>> $beforeRows
 * @param list<array<string,mixed>> $edited
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_fortnox_align_edited_to_kalkyl(array $row, array $beforeRows, array $edited): array
{
    $prospective = jeab_ordrar_fortnox_merge_order_rows($beforeRows, $edited);
    $delta = jeab_ordrar_gt_kalkyl_delta($row, $prospective);
    if ($delta === null || $delta === 0) {
        return $edited;
    }
    if (!class_exists('FortnoxAPI')) {
        require_once JEAB_ROOT . '/src/fortnox.php';
    }
    $rates = FortnoxAPI::greenTechRates();
    for ($i = count($edited) - 1; $i >= 0; $i--) {
        $line = $edited[$i];
        $price = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], (string) ($line['price'] ?? ''));
        if ($price === '' || !is_numeric($price) || isset($rates[(string) ($line['housework'] ?? '')])) {
            continue;
        }
        $qty = str_replace(',', '.', (string) ($line['qty'] ?? '1'));
        if ($qty !== '' && (!is_numeric($qty) || abs((float) $qty - 1.0) > 0.0001)) {
            return $edited;
        }
        $edited[$i]['price'] = (string) ((int) round((float) $price) - $delta);
        return $edited;
    }
    return $edited;
}

/**
 * Att betala följer Fortnox. Om skattereduktionen på ordern ännu är den gamla
 * dras skillnaden i ansökt belopp av från TotalToPay.
 *
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_with_asked(array $order, int $previousAsked, int $nextAsked): array
{
    if ($nextAsked === $previousAsked) {
        return $order;
    }
    $wrapped = isset($order['Order']) && is_array($order['Order']);
    $body = jeab_ordrar_fortnox_order_body($order);
    $tax = isset($body['TaxReduction']) && is_numeric($body['TaxReduction'])
        ? (int) round((float) $body['TaxReduction'])
        : $previousAsked;
    if ($tax === $nextAsked) {
        return $order;
    }
    if (isset($body['TotalToPay']) && is_numeric($body['TotalToPay'])) {
        $body['TotalToPay'] = round((float) $body['TotalToPay'] - ($nextAsked - $previousAsked), 2);
    }
    $body['TaxReduction'] = $nextAsked;
    if ($wrapped) {
        $order['Order'] = $body;
        return $order;
    }
    return $body;
}

/**
 * @param list<array<string,mixed>> $beforeRows
 * @param list<array<string,mixed>> $afterRows
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_sync_gt(array $row, array $beforeRows, array $afterRows, array $order): array
{
    if (isset($GLOBALS['_JEAB_FORTNOX_GT_SYNC']) && is_callable($GLOBALS['_JEAB_FORTNOX_GT_SYNC'])) {
        $out = ($GLOBALS['_JEAB_FORTNOX_GT_SYNC'])($row, $beforeRows, $afterRows, $order);
        return is_array($out) ? $out : ['order' => $order];
    }
    try {
        $api = jeab_ordrar_fortnox_api_for($row);
        $synced = $api->syncKopTaxReduction($row, $beforeRows, $afterRows, $order);
        return is_array($synced) ? $synced : ['order' => $order];
    } catch (Throwable $e) {
        $message = trim($e->getMessage());
        return ['order' => $order, 'error' => $message !== '' ? $message : 'Grön teknik kunde inte uppdateras.'];
    }
}

/**
 * Sänk grön teknik innan orderraderna skrivs. Fortnox avvisar radskrivningen
 * om ansökan fortfarande är högre än det nya underlaget.
 *
 * @param list<array<string,mixed>> $beforeRows
 * @param list<array<string,mixed>> $afterRows
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_lower_gt_before_write(array $row, array $beforeRows, array $afterRows, array $order): array
{
    if (isset($GLOBALS['_JEAB_FORTNOX_GT_LOWER']) && is_callable($GLOBALS['_JEAB_FORTNOX_GT_LOWER'])) {
        $out = ($GLOBALS['_JEAB_FORTNOX_GT_LOWER'])($row, $beforeRows, $afterRows, $order);
        return is_array($out) ? $out : ['order' => $order, 'update' => false];
    }
    if (!is_file(jeab_fortnox_config_path()) || !is_file(jeab_fortnox_tokens_path())) {
        return ['order' => $order, 'update' => false];
    }
    try {
        $api = jeab_ordrar_fortnox_api_for($row);
        $lowered = $api->lowerKopTaxReduction($row, $beforeRows, $afterRows, $order);
        return is_array($lowered) ? $lowered : ['order' => $order, 'update' => false];
    } catch (Throwable $e) {
        $message = trim($e->getMessage());
        return ['order' => $order, 'error' => $message !== '' ? $message : 'Grön teknik kunde inte sänkas.', 'update' => false];
    }
}

/**
 * Skriv hela radlistan till Fortnox-ordern (samma PUT som Spara).
 *
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_put_rows(array $row, array $rows): array
{
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    if ($no === '') {
        throw new InvalidArgumentException('Fortnox-order saknas.');
    }
    $clean = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        unset($r['Total'], $r['ContributionPercent'], $r['ContributionValue']);
        $clean[] = $r;
    }
    $liveRows = [];
    try {
        $liveRows = jeab_ordrar_fortnox_order_rows(jeab_ordrar_fortnox_fetch_order($row));
    } catch (Throwable) {
        $liveRows = [];
    }
    $clean = jeab_ordrar_fortnox_drop_stale_row_ids($clean, $liveRows);
    try {
        if (isset($GLOBALS['_JEAB_FORTNOX_ORDER_PUT']) && is_callable($GLOBALS['_JEAB_FORTNOX_ORDER_PUT'])) {
            $saved = ($GLOBALS['_JEAB_FORTNOX_ORDER_PUT'])($no, $clean, $row);
            return is_array($saved) ? $saved : ['Order' => ['DocumentNumber' => $no, 'OrderRows' => $clean]];
        }
        return jeab_ordrar_fortnox_api_for($row)->saveOrderRows($no, $clean);
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        jeab_ordrar_fortnox_fail($msg, 'Fortnox-ordern kunde inte uppdateras.', $no);
    }
}

/**
 * Ny grön teknik-typ (till exempel laddbox på en batteriorder) på en order
 * som redan har ansökan. FortnoxAPI tar bort ansökan, skriver raderna och
 * skapar ansökan igen per person och typ. applies=false när inget behövs.
 *
 * @param list<array<string,mixed>> $afterRows
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_rewrite_new_gt_types(array $row, array $afterRows): array
{
    if (isset($GLOBALS['_JEAB_FORTNOX_GT_REWRITE']) && is_callable($GLOBALS['_JEAB_FORTNOX_GT_REWRITE'])) {
        $out = ($GLOBALS['_JEAB_FORTNOX_GT_REWRITE'])($row, $afterRows);
        return is_array($out) ? $out : ['applies' => false];
    }
    // Tester som stubbar ordernivån (radskrivning eller grön teknik) går den vanliga vägen.
    foreach (['_JEAB_FORTNOX_ORDER_PUT', '_JEAB_FORTNOX_GT_LOWER', '_JEAB_FORTNOX_GT_SYNC'] as $hook) {
        if (isset($GLOBALS[$hook]) && is_callable($GLOBALS[$hook])) {
            return ['applies' => false];
        }
    }
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    if ($no === '' || !is_file(jeab_fortnox_config_path()) || !is_file(jeab_fortnox_tokens_path())) {
        return ['applies' => false];
    }
    $owners = [];
    try {
        $owners = jeab_ordrar_fortnox_owners($row);
    } catch (Throwable) {
        $owners = [];
    }
    try {
        return jeab_ordrar_fortnox_api_for($row)->rewriteRowsWithNewGreenTechTypes($no, $afterRows, $owners, [], trim((string) ($row['fastighetsbeteckning'] ?? '')));
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        jeab_ordrar_fortnox_fail($msg, 'Fortnox-ordern kunde inte uppdateras.', $no);
    }
}

/**
 * Kontroll av Fortnox-ordern (bara läsning): summor, rader och ansökan per person.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_kontroll(array $row): array
{
    $no = trim((string) ($row['fortnox_order'] ?? ''));
    if ($no === '') {
        return ['ok' => false, 'error' => 'Fortnox-order saknas.'];
    }
    try {
        $api = jeab_ordrar_fortnox_api_for($row);
        $out = ['ok' => true, 'fortnox_order' => $no] + $api->greenTechReadout($no) + ['ordrar' => jeab_ordrar_fortnox_kontroll_ordrar($row)];
        $inv = trim((string) ($out['order']['InvoiceReference'] ?? ''));
        if ($inv !== '' && $inv !== '0') {
            try {
                $out['faktura'] = $api->invoiceGreenTechReadout($inv);
            } catch (Throwable $e) {
                $out['faktura'] = ['error' => $e->getMessage()];
            }
        }
        return $out;
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Ordrars sida av kontrollen: det appen har sparat om grön teknik och vad
 * Kalkylen ger för rader. Inga personnummer.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_kontroll_ordrar(array $row): array
{
    $owners = [];
    try {
        $owners = jeab_ordrar_fortnox_owners($row);
    } catch (Throwable) {
        $owners = [];
    }
    $out = [
        'kind' => (string) ($row['kind'] ?? ''),
        'pris' => (int) ($row['pris'] ?? 0),
        'kundpris' => (int) ($row['kundpris'] ?? 0),
        'fortnox_price' => jeab_ordrar_fortnox_price($row),
        'fortnox_gt_asked' => $row['fortnox_gt_asked'] ?? null,
        'fortnox_gt_capped' => !empty($row['fortnox_gt_capped']),
        'fortnox_att_betala' => (string) ($row['fortnox_att_betala'] ?? ''),
        'kalkyl' => is_array($row['kalkyl'] ?? null) ? $row['kalkyl'] : [],
        'vaxelriktare' => (string) ($row['vaxelriktare'] ?? ''),
        'kwh' => (string) ($row['kwh'] ?? ''),
        'owners' => array_map(static fn ($o) => is_array($o) ? (string) ($o['name'] ?? '') : '', $owners),
        'stored_rows' => array_map(static fn ($r) => is_array($r) ? array_intersect_key($r, array_flip(['RowId', 'Description', 'Price', 'HouseWorkType'])) : [], is_array($row['fortnox_order_rows'] ?? null) ? $row['fortnox_order_rows'] : []),
    ];
    try {
        $out['kalkyl_rows'] = array_map(static fn ($r) => is_array($r) ? array_intersect_key($r, array_flip(['Description', 'Price', 'HouseWorkType'])) : [], jeab_ordrar_fortnox_formula_order_rows($row));
    } catch (Throwable $e) {
        $out['kalkyl_rows_error'] = $e->getMessage();
    }
    return $out;
}

/** Kundpriset som både Bekräfta och Fakturera visar. Ett lagrat belopp. */
function jeab_ordrar_att_betala_amount(array $row): int
{
    $pris = (int) ($row['pris'] ?? 0);
    if ($pris > 0) {
        return $pris;
    }
    $kund = (int) ($row['kundpris'] ?? 0);
    if ($kund > 0) {
        return $kund;
    }
    return max(0, jeab_ordrar_fortnox_price($row));
}

function jeab_ordrar_att_betala_parse(mixed $raw): ?int
{
    if ($raw === null) {
        return null;
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }
    if (!function_exists('jeab_purchase_amount')) {
        return null;
    }
    $amount = jeab_purchase_amount($text);
    if (!is_finite($amount)) {
        return null;
    }
    return (int) round($amount);
}

/**
 * Fältet heter pris i båda stegen. Saknas nyckeln är beloppet orört.
 *
 * @param array<string,mixed> $input
 */
function jeab_ordrar_att_betala_posted(array $input): ?int
{
    if (!array_key_exists('pris', $input)) {
        return null;
    }
    return jeab_ordrar_att_betala_parse($input['pris']);
}

/** Tom sträng när beloppet kan faktureras. Annars texten som blockerar Fakturera. */
function jeab_ordrar_att_betala_error(array $row): string
{
    $kind = function_exists('jeab_purchase_normalize_kind')
        ? (string) (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '')
        : '';
    if (!in_array($kind, ['laddbox', 'batteri', 'sol'], true)) {
        return '';
    }
    $amount = jeab_ordrar_att_betala_amount($row);
    if ($amount <= 0) {
        return '';
    }
    if (!function_exists('jeab_offert_rows_for_payable')) {
        require_once JEAB_ROOT . '/src/offert.php';
    }
    try {
        jeab_offert_rows_for_payable($kind, $amount, (string) ($row['install_what'] ?? ''), $row);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return '';
}

/** UI: fakturan skapas bara efter att belopp och rader bekräftats. */
function jeab_ordrar_faktura_confirmed(array $input): bool
{
    $raw = $input['faktura_bekrafta'] ?? '';
    $values = is_array($raw) ? $raw : [$raw];
    foreach ($values as $value) {
        if (in_array(strtolower(trim((string) $value)), ['1', 'ja', 'true'], true)) {
            return true;
        }
    }

    return false;
}

/**
 * Spara är utkast (intent saknas eller save) även om faktura också följer med.
 * Bara ett ensamt intent=faktura skapar faktura.
 */
function jeab_ordrar_fakturering_intent(array $input): string
{
    $raw = $input['intent'] ?? '';
    $values = is_array($raw) ? $raw : [$raw];
    $sawInvoice = false;
    foreach ($values as $value) {
        $intent = strtolower(trim((string) $value));
        if ($intent === 'save') {
            return 'save';
        }
        if ($intent === 'faktura') {
            $sawInvoice = true;
        }
    }

    return $sawInvoice ? 'faktura' : '';
}

/** Kastar innan någon faktura skapas. Spara går alltid förbi. */
function jeab_ordrar_fakturering_gate(array $input): void
{
    if (jeab_ordrar_fakturering_intent($input) !== 'faktura') {
        return;
    }
    if (!jeab_ordrar_faktura_confirmed($input)) {
        throw new InvalidArgumentException('Bekräfta belopp och rader innan fakturan skapas.');
    }
}

/**
 * En faktura per påminnelse även om två bekräftelser når servern samtidigt.
 * Finns numret redan anropas inte Fortnox igen.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_make_invoice_once(array $row, bool $forceNew): array
{
    $rid = (int) ($row['reminder_id'] ?? 0);
    $run = static function () use ($row, $forceNew, $rid): array {
        if (!$forceNew && $rid > 0 && function_exists('jeab_purchase_get') && function_exists('jeab_ordrar_invoice_number')) {
            $fresh = jeab_purchase_get($rid);
            $have = is_array($fresh) ? jeab_ordrar_invoice_number($fresh) : '';
            if ($have !== '') {
                return ['Invoice' => ['DocumentNumber' => $have], 'Order' => ['InvoiceReference' => $have]];
            }
        }
        $made = jeab_ordrar_fortnox_make_invoice($row);
        $no = jeab_ordrar_fortnox_invoice_reference(is_array($made) ? $made : []);
        if ($no !== '' && $rid > 0 && function_exists('jeab_purchase_get') && function_exists('jeab_purchase_put')) {
            $stamp = jeab_purchase_get($rid);
            if (is_array($stamp) && jeab_ordrar_invoice_number($stamp) === '') {
                $stamp['fortnox_invoice'] = $no;
                if (function_exists('jeab_ordrar_stamp_today')) {
                    $stamp = jeab_ordrar_stamp_today($stamp, 'faktura_skapad');
                }
                $stamp['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
                jeab_purchase_put($stamp);
            }
        }

        return is_array($made) ? $made : [];
    };
    if ($rid > 0 && function_exists('jeab_ordrar_reminder_effect_lock')) {
        $made = jeab_ordrar_reminder_effect_lock($rid, $run);

        return is_array($made) ? $made : [];
    }

    return $run();
}

/**
 * Fortnox-order med samma ärendenummer i YourOrderNumber eller ExternalInvoiceReference.
 * Tom sträng om ingen träff. Skapar ingen order.
 */
function jeab_ordrar_fortnox_find_external_order(array $row): string
{
    if (isset($GLOBALS['_JEAB_FORTNOX_ORDER_FIND']) && is_callable($GLOBALS['_JEAB_FORTNOX_ORDER_FIND'])) {
        return trim((string) ($GLOBALS['_JEAB_FORTNOX_ORDER_FIND'])($row));
    }
    // Skapandet skriver inte ärendenumret i YourOrderNumber, och andra kunders
    // ordrar (t.ex. utbetalningar) kan ha samma siffror. Live-matchning kunde
    // därför koppla och skriva rader på fel kunds order. Avstängd.
    if (empty($GLOBALS['_JEAB_FORTNOX_ORDER_FIND_LIVE'])) {
        return '';
    }
    $needle = (string) (int) ($row['reminder_id'] ?? 0);
    if ($needle === '0') {
        return '';
    }
    try {
        $api = jeab_ordrar_fortnox_api_for($row);
        if (!method_exists($api, 'listOrders')) {
            return '';
        }
        $list = $api->listOrders(['limit' => 50]);
    } catch (Throwable) {
        return '';
    }
    $seen = 0;
    foreach ($list as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($seen >= 20) {
            break;
        }
        $seen++;
        $doc = trim((string) ($item['DocumentNumber'] ?? ''));
        if ($doc === '') {
            continue;
        }
        $refs = [
            (string) ($item['YourOrderNumber'] ?? ''),
            (string) ($item['ExternalInvoiceReference1'] ?? ''),
            (string) ($item['ExternalInvoiceReference2'] ?? ''),
        ];
        $hasRef = false;
        foreach ($refs as $ref) {
            if (trim($ref) !== '') {
                $hasRef = true;
            }
            if (trim($ref) === $needle) {
                return $doc;
            }
        }
        if ($hasRef || !method_exists($api, 'getOrder')) {
            continue;
        }
        try {
            $full = $api->getOrder($doc);
        } catch (Throwable) {
            continue;
        }
        $body = isset($full['Order']) && is_array($full['Order']) ? $full['Order'] : $full;
        foreach (['YourOrderNumber', 'ExternalInvoiceReference1', 'ExternalInvoiceReference2'] as $key) {
            if (trim((string) ($body[$key] ?? '')) === $needle) {
                return $doc;
            }
        }
    }

    return '';
}

/** Finns numret lokalt eller i Fortnox med samma referens: återanvänd, skapa inte. */
function jeab_ordrar_fakturering_reuse_order(array $row): array
{
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return $row;
    }
    $found = jeab_ordrar_fortnox_find_external_order($row);
    if ($found === '') {
        return $row;
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    $row['fortnox_order'] = $found;
    if ($id <= 0 || !function_exists('jeab_purchase_patch')) {
        return $row;
    }
    $saved = jeab_purchase_patch($id, static function (array $fresh) use ($found): array {
        if (trim((string) ($fresh['fortnox_order'] ?? '')) !== '') {
            return $fresh;
        }
        $fresh['fortnox_order'] = $found;

        return $fresh;
    });

    return is_array($saved) ? $saved : $row;
}

function jeab_ordrar_save_fakturering(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (jeab_ordrar_fortnox_attach_locked($row)) {
        throw new InvalidArgumentException(jeab_ordrar_fortnox_attach_error());
    }
    $createInvoice = jeab_ordrar_fakturering_intent($input) === 'faktura';
    if (function_exists('jeab_ordrar_payouts_posted') && jeab_ordrar_payouts_posted($input)) {
        $row = jeab_ordrar_payouts_merge($row, $input);
        $row = jeab_purchase_put($row);
    }
    $orderId = (int) ($row['reminder_id'] ?? $input['id'] ?? 0);
    $ensureOrder = static function () use ($input, $createInvoice, $orderId): array {
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
        $current = jeab_ordrar_require($orderId > 0 ? $orderId : (int) ($input['id'] ?? 0));
        // Numret får inte rensas här. En låst eller återställd order ska återanvändas,
        // inte bli en ny Fortnox-order vid varje Spara.
        $current = jeab_ordrar_fakturering_reuse_order($current);
        if (trim((string) ($current['fortnox_order'] ?? '')) !== '') {
            return $current;
        }
        if (!$createInvoice) {
            return $current;
        }
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
        $again = jeab_ordrar_fakturering_reuse_order(jeab_ordrar_require((int) ($current['reminder_id'] ?? $orderId)));
        if (trim((string) ($again['fortnox_order'] ?? '')) !== '') {
            return $again;
        }

        return jeab_ordrar_create_fortnox(['id' => (int) ($again['reminder_id'] ?? $orderId)]);
    };
    if ($orderId > 0 && function_exists('jeab_ordrar_reminder_effect_lock')) {
        $ensured = jeab_ordrar_reminder_effect_lock($orderId, $ensureOrder);
        $row = is_array($ensured) ? $ensured : $row;
    } else {
        $row = $ensureOrder();
    }
    $fresh = false;
    $beforeSnap = [];
    try {
        $beforeSnap = jeab_ordrar_fortnox_fetch_order($row);
    } catch (Throwable) {
        $stored = is_array($row['fortnox_order_rows'] ?? null) ? $row['fortnox_order_rows'] : [];
        if ($fresh) {
            $stored = jeab_ordrar_fortnox_forget_row_ids($stored);
        }
        $beforeSnap = ['Order' => ['OrderRows' => $stored]];
    }
    $beforeRows = jeab_ordrar_fortnox_order_rows($beforeSnap);
    $edited = jeab_ordrar_fortnox_posted_lines($input);
    $editedPosted = $edited;
    $touched = jeab_ordrar_fortnox_touched_flags($input);
    $manualIdx = jeab_ordrar_fortnox_manual_indexes($row);
    $closed = jeab_ordrar_has_invoice($row) || !empty($row['allt_klart']);
    $postedPrice = $closed ? null : jeab_ordrar_att_betala_posted($input);
    $rebuilt = false;
    if ($postedPrice !== null && $postedPrice > 0) {
        $kind = (string) (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '');
        $priced = $row;
        $priced['pris'] = $postedPrice;
        $priced['kundpris'] = $postedPrice;
        $changed = $postedPrice !== jeab_ordrar_att_betala_amount($row);
        $needsEngine = $changed && in_array($kind, ['laddbox', 'batteri', 'sol'], true);
        if (!$needsEngine && in_array($kind, ['laddbox', 'batteri', 'sol'], true)) {
            if (!function_exists('jeab_offert_rows_match_payable')) {
                require_once JEAB_ROOT . '/src/offert.php';
            }
            try {
                $probe = jeab_offert_fortnox_kop_rows($kind, $postedPrice, (string) ($priced['install_what'] ?? ''), $priced);
                $probeInput = jeab_offert_input_from_purchase($priced, $kind);
                $probePeople = max(1, (int) round((float) ($probeInput['sokande'] ?? 1)));
                $needsEngine = !jeab_offert_rows_match_payable($probe, $postedPrice, $probePeople);
            } catch (InvalidArgumentException) {
                $needsEngine = true;
            }
        }
        if ($needsEngine) {
            if (!function_exists('jeab_offert_rows_for_payable')) {
                require_once JEAB_ROOT . '/src/offert.php';
            }
            $logical = jeab_offert_rows_for_payable($kind, $postedPrice, (string) ($priced['install_what'] ?? ''), $priced);
            if (!class_exists('FortnoxAPI')) {
                require_once JEAB_ROOT . '/src/fortnox.php';
            }
            $formula = (new FortnoxAPI(jeab_ordrar_fortnox_account($row['company'] ?? null)))->kopRowsFromLogical($logical);
            if ($formula !== []) {
                $row['pris'] = $postedPrice;
                $row['kundpris'] = $postedPrice;
                $kept = jeab_ordrar_fortnox_keep_manual_editor_lines(
                    $editedPosted,
                    jeab_ordrar_fortnox_editor_lines($formula),
                    $touched,
                    $manualIdx
                );
                $edited = $kept['lines'];
                $manualIdx = $kept['manual'];
                $rebuilt = true;
                $changeText = jeab_ordrar_fortnox_editor_change_text($editedPosted, $edited);
                if ($changeText !== '') {
                    $keptNote = $manualIdx !== [] ? ' Handredigerade rader lämnades.' : '';
                    $GLOBALS['_JEAB_FAKTURA_CHANGES'] = $changeText . $keptNote;
                } elseif ($manualIdx !== []) {
                    $GLOBALS['_JEAB_FAKTURA_CHANGES'] = 'Handredigerade rader lämnades.';
                }
            }
        } elseif ($changed) {
            $row['pris'] = $postedPrice;
            $row['kundpris'] = $postedPrice;
        }
    }
    foreach ($touched as $i => $on) {
        if ($on && $on === true) {
            $manualIdx[] = (int) $i;
        }
    }
    $row['fortnox_manual_rows'] = array_values(array_unique(array_map('intval', $manualIdx)));
    if (!$rebuilt) {
        $edited = jeab_ordrar_fortnox_align_edited_to_kalkyl($row, $beforeRows, $edited);
    }
    $prospective = $fresh
        ? jeab_ordrar_fortnox_merge_fresh_rows($beforeRows, $edited, jeab_ordrar_fortnox_formula_rows_or_empty($row))
        : jeab_ordrar_fortnox_merge_order_rows($beforeRows, $edited);
    $prospective = jeab_ordrar_fortnox_drop_stale_row_ids($prospective, $beforeRows);
    $rewritten = false;
    if ($prospective !== []) {
        $rewrite = jeab_ordrar_fortnox_rewrite_new_gt_types($row, $prospective);
        if (!empty($rewrite['applies']) && is_array($rewrite['order'] ?? null)) {
            $rewritten = true;
            unset($row['fortnox_gt_capped']);
            $row['fortnox_gt_asked'] = (int) ($rewrite['asked'] ?? 0);
        }
    }
    if ($prospective !== [] && !$rewritten) {
        $lowered = jeab_ordrar_fortnox_lower_gt_before_write($row, $beforeRows, $prospective, $beforeSnap);
        $lowerError = trim((string) ($lowered['error'] ?? ''));
        if ($lowerError !== '') {
            throw new InvalidArgumentException($lowerError);
        }
        if (!empty($lowered['update'])) {
            if (!empty($lowered['capped'])) {
                $row['fortnox_gt_capped'] = true;
            } else {
                unset($row['fortnox_gt_capped']);
            }
            if (isset($lowered['asked']) && is_numeric($lowered['asked'])) {
                $row['fortnox_gt_asked'] = (int) $lowered['asked'];
            }
        }
    }
    if ($rewritten) {
        // Raderna och ansökan är redan skrivna. Synken jämför mot samma rader.
        $snapshot = $rewrite['order'];
        $afterRows = jeab_ordrar_fortnox_order_rows($snapshot);
        if ($afterRows === []) {
            $afterRows = $prospective;
        }
        $beforeRows = $afterRows;
    } else {
        $snapshot = jeab_ordrar_fortnox_write_lines($row, $edited, $fresh);
        $afterRows = jeab_ordrar_fortnox_order_rows($snapshot);
        if ($afterRows === []) {
            $afterRows = $fresh
                ? jeab_ordrar_fortnox_merge_fresh_rows($beforeRows, $edited, jeab_ordrar_fortnox_formula_rows_or_empty($row))
                : jeab_ordrar_fortnox_merge_order_rows($beforeRows, $edited);
            $body = jeab_ordrar_fortnox_order_body($snapshot);
            $body['OrderRows'] = $afterRows;
            $snapshot = ['Order' => $body];
        }
    }
    $synced = jeab_ordrar_fortnox_sync_gt($row, $beforeRows, $afterRows, $snapshot);
    $gtError = trim((string) ($synced['error'] ?? ''));
    if (is_array($synced['order'] ?? null)) {
        $snapshot = $synced['order'];
        $syncedRows = jeab_ordrar_fortnox_order_rows($snapshot);
        if ($syncedRows !== []) {
            $afterRows = $syncedRows;
        }
    }
    if (!empty($synced['capped'])) {
        $row['fortnox_gt_capped'] = true;
    } else {
        unset($row['fortnox_gt_capped']);
    }
    if (isset($synced['asked']) && is_numeric($synced['asked'])) {
        $row['fortnox_gt_asked'] = (int) $synced['asked'];
    }
    if ($afterRows !== []) {
        $row['fortnox_order_rows'] = $afterRows;
    }
    $pay = jeab_ordrar_fortnox_att_betala($snapshot);
    if ($pay !== '') {
        $row['fortnox_att_betala'] = $pay;
    }
    $invoiceNo = jeab_ordrar_fortnox_invoice_reference($snapshot);
    if ($invoiceNo === '') {
        $invoiceNo = jeab_ordrar_invoice_number($row);
    }
    $forceNew = !empty($input['force_new']);
    $madeInvoice = false;
    if ($createInvoice && ($invoiceNo === '' || $forceNew)) {
        $guardRows = $afterRows;
        try {
            $exportInput = jeab_ordrar_fortnox_payload($row);
            if ($guardRows === []) {
                $plan = jeab_ordrar_fortnox_api_for($row)->kopExportPlan($exportInput);
                $guardRows = is_array($plan['order']['OrderRows'] ?? null) ? $plan['order']['OrderRows'] : [];
            }
            FortnoxAPI::assertGreenTechPayload($exportInput, $guardRows);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            $msg = trim($e->getMessage());
            throw new InvalidArgumentException($msg !== '' ? $msg : 'Ordern har grön teknik men underlaget saknar skattereduktion.');
        }
        $made = jeab_ordrar_fortnox_make_invoice_once($row, $forceNew);
        $invoiceNo = jeab_ordrar_fortnox_invoice_reference($made);
        if ($invoiceNo === '') {
            $again = jeab_ordrar_fortnox_fetch_order($row);
            $invoiceNo = jeab_ordrar_fortnox_invoice_reference($again);
        }
        if ($invoiceNo === '') {
            throw new InvalidArgumentException('Fortnox skapade ingen faktura.');
        }
        $madeInvoice = true;
    }
    if ($madeInvoice && function_exists('jeab_snapshots_capture_sent') && !jeab_snapshots_has_local($row, 'invoice')) {
        $painted = jeab_ordrar_fortnox_customer_row($row);
        $row = jeab_snapshots_capture_sent($row, 'invoice', $painted, 'send');
    }
    if ($invoiceNo !== '') {
        // Fakturan ska ha samma sökande som ordern (medsökande från Bekräfta).
        $invSync = jeab_ordrar_fortnox_sync_invoice_owners($row, $invoiceNo, true);
        $invError = trim((string) ($invSync['error'] ?? ''));
        if ($invError !== '' && $gtError === '') {
            $gtError = $invError;
        }
        $row['fortnox_invoice'] = $invoiceNo;
        $row = jeab_ordrar_stamp_today($row, 'faktura_skapad');
        $row = jeab_ordrar_stamp_today($row, 'fardiganmald');
    }
    $row['updated_at'] = jeab_now();
    unset($row['fortnox_gt_error']);
    if ($createInvoice && $invoiceNo !== '' && function_exists('jeab_ordrar_payouts_credit_locked')) {
        try {
            $saved = jeab_ordrar_payouts_credit_locked($row);
        } catch (Throwable) {
            $fresh = jeab_purchase_get((int) ($row['reminder_id'] ?? 0));
            if (is_array($fresh)) {
                $row['payouts'] = jeab_ordrar_payouts_preserve_credits(
                    jeab_ordrar_payouts($row),
                    jeab_ordrar_payouts($fresh)
                );
            }
            $saved = jeab_purchase_put($row);
        }
    } else {
        $saved = jeab_purchase_put($row);
    }
    if ($gtError !== '') {
        $saved['fortnox_gt_error'] = $gtError;
    }
    return $saved;
}


/**
 * Fakturans sökande följer orderns (huvudsökande + medsökande, samma belopp).
 * Låst (bokförd/makulerad) faktura ändras inte, då returneras planen med locked.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_sync_invoice_owners(array $row, string $invoiceNo, bool $apply): array
{
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    $invoiceNo = trim($invoiceNo);
    if ($orderNo === '' || $invoiceNo === '' || $invoiceNo === '0') {
        return ['skipped' => true];
    }
    try {
        if (isset($GLOBALS['_JEAB_FORTNOX_INVOICE_SYNC']) && is_callable($GLOBALS['_JEAB_FORTNOX_INVOICE_SYNC'])) {
            $res = ($GLOBALS['_JEAB_FORTNOX_INVOICE_SYNC'])($orderNo, $invoiceNo, $apply, $row);
            return is_array($res) ? $res : [];
        }
        $res = jeab_ordrar_fortnox_api_for($row)->syncInvoiceTaxReductionsFromOrder($orderNo, $invoiceNo, $apply);
        if (!empty($res['locked']) && empty($res['in_sync'])) {
            $res['error'] = 'Fakturan ' . $invoiceNo . ' är bokförd och saknar sökande från ordern. Den måste krediteras och göras om.';
        }
        return $res;
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        return ['error' => 'Fakturans sökande kunde inte uppdateras' . ($msg !== '' ? ': ' . $msg : '.')];
    }
}

/**
 * Åtgärd: rätta fakturans sökande efter ordern. apply=0 är torrkörning.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_fortnox_invoice_owners(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $invoiceNo = jeab_ordrar_invoice_number($row);
    if ($invoiceNo === '') {
        try {
            $invoiceNo = jeab_ordrar_fortnox_invoice_reference(jeab_ordrar_fortnox_fetch_order($row));
        } catch (Throwable) {
            $invoiceNo = '';
        }
    }
    if ($invoiceNo === '') {
        return ['ok' => false, 'error' => 'Ingen faktura på ordern.'];
    }
    $apply = in_array(strtolower(trim((string) ($input['apply'] ?? ''))), ['1', 'true', 'ja'], true);
    return ['ok' => true, 'id' => (int) ($row['id'] ?? 0)] + jeab_ordrar_fortnox_sync_invoice_owners($row, $invoiceNo, $apply);
}
