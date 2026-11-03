<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/einstein_webhook.php';

function jeab_bokmalen_ordrar_webhook_secret_path(): string
{
    return jeab_data_file('secrets/bokmalen-ordrar-webhook.json');
}

function jeab_bokmalen_ordrar_webhook_secret(): ?array
{
    return jeab_webhook_secret_from_path(jeab_bokmalen_ordrar_webhook_secret_path());
}

function jeab_bokmalen_ordrar_webhook_kind(array $row): string
{
    $kind = function_exists('jeab_ordrar_ui_kind')
        ? jeab_ordrar_ui_kind($row['kind'] ?? '')
        : trim((string) ($row['kind'] ?? ''));
    return $kind === 'sol' || $kind === 'batteri' ? $kind : '';
}

function jeab_ordrar_bokmalen_nat(array $row): string
{
    $saved = trim((string) ($row['natbolag'] ?? ''));
    $options = function_exists('jeab_ordrar_natbolag_options')
        ? jeab_ordrar_natbolag_options()
        : ['Ellevio', 'E.ON', 'Vattenfall', 'SEOM', 'Kraftringen', 'Öresundskraft'];
    if (in_array($saved, $options, true)) {
        return $saved;
    }
    if (function_exists('jeab_ordrar_natbolag')) {
        $resolved = trim((string) jeab_ordrar_natbolag($row));
        if (in_array($resolved, $options, true)) {
            return $resolved;
        }
    }
    return $saved;
}

function jeab_ordrar_bokmalen_skips_anlaggnings_id(array $row): bool
{
    if (jeab_ordrar_bokmalen_nat($row) === 'Ellevio') {
        return true;
    }
    // Manuell/ingen (C) på föranmälan. Beställningens C räcker inte.
    $status = strtoupper(trim((string) ($row['foranmalan_status'] ?? '')));
    return $status === 'C';
}

function jeab_ordrar_bokmalen_missing(array $row, string $type = ''): array
{
    $kind = jeab_bokmalen_ordrar_webhook_kind($row);
    if ($kind === '') {
        return [];
    }
    // Beställ/shop behöver inte nät, anläggnings-id eller kWh.
    // Tomma fält får inte stoppa ordrar.bestall (varken automatisk eller utkast).
    if ($type === 'ordrar.bestall') {
        return [];
    }
    $missing = [];
    $nat = jeab_ordrar_bokmalen_nat($row);
    $options = function_exists('jeab_ordrar_natbolag_options')
        ? jeab_ordrar_natbolag_options()
        : ['Ellevio', 'E.ON', 'Vattenfall', 'SEOM', 'Kraftringen', 'Öresundskraft'];
    if (!in_array($nat, $options, true)) {
        $missing[] = 'nätbolag';
    }
    // Ellevio: kunden fyller anläggnings-id i fullmakten. Bokmalen tar värdet efter signering.
    $aid = function_exists('jeab_ordrar_anlaggnings_id_digits')
        ? jeab_ordrar_anlaggnings_id_digits((string) ($row['anlaggnings_id'] ?? ''))
        : (preg_replace('/\D+/u', '', (string) ($row['anlaggnings_id'] ?? '')) ?? '');
    if (!jeab_ordrar_bokmalen_skips_anlaggnings_id($row) && $aid === '') {
        $missing[] = 'anläggnings-id';
    }
    // kWh stannar på föranmälan: det är den enda installerade storleken vi skickar
    // till nätportalen. Växelriktarmodell ensam räcker inte som effektuppgift.
    $kwhOk = function_exists('jeab_ordrar_kwh_amount')
        ? jeab_ordrar_kwh_amount((string) ($row['kwh'] ?? '')) !== null
        : trim((string) ($row['kwh'] ?? '')) !== '';
    if (!$kwhOk) {
        $missing[] = 'kWh';
    }
    return $missing;
}

function jeab_bokmalen_ordrar_notify_fail(string $type, string $reason): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $safeType = in_array($type, ['ordrar.foranmalan', 'ordrar.bestall', 'ordrar.plombbrytning'], true) ? $type : 'ordrar';
    $reason = trim(preg_replace('#https?://\S+#i', '[url]', $reason) ?? $reason);
    $reason = trim(preg_replace('/Bearer\s+\S+/i', 'Bearer [nyckel]', $reason) ?? $reason);
    if ($reason === '') {
        $reason = 'okänt fel';
    }
    // Fellogg får inte innehålla webhook-url eller nyckel.
    jeab_log_app_error('ordrar', 'Bokmalen ordrar-webhook: ' . $reason . ' (' . $safeType . ').');
}

function jeab_bokmalen_ordrar_webhook_line(array $line): array
{
    $sku = trim((string) ($line['sku'] ?? $line['artikelnummer'] ?? ''));
    $title = trim((string) ($line['title'] ?? $line['artikelnamn'] ?? ''));
    $qtyRaw = $line['qty'] ?? $line['antal'] ?? '';
    if (is_int($qtyRaw) || (is_string($qtyRaw) && is_numeric(trim($qtyRaw))) || is_float($qtyRaw)) {
        $qty = (int) $qtyRaw;
    } else {
        $qty = trim((string) $qtyRaw);
    }
    $shop = trim((string) ($line['shop'] ?? $line['webshop'] ?? ''));
    if ($shop !== '' && function_exists('jeab_ordrar_normalize_shop')) {
        $normalized = jeab_ordrar_normalize_shop($shop);
        if ($normalized !== '') {
            $shop = $normalized;
        }
    }
    $out = [
        'sku' => $sku,
        'qty' => $qty,
        'title' => $title,
        'shop' => $shop,
    ];
    $skip = ['sku', 'artikelnummer', 'qty', 'antal', 'title', 'artikelnamn', 'shop', 'webshop'];
    foreach ($line as $key => $value) {
        if (!is_string($key) || $key === '' || in_array($key, $skip, true)) {
            continue;
        }
        if (is_array($value) || is_object($value)) {
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $out[$key] = $value;
            continue;
        }
        $out[$key] = trim((string) $value);
    }
    return $out;
}

function jeab_bokmalen_ordrar_line_qty(array $line): ?int
{
    $raw = $line['qty'] ?? $line['antal'] ?? '';
    if (is_string($raw)) {
        $raw = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw));
        $raw = str_replace(',', '.', $raw);
        if ($raw === '') {
            return null;
        }
    }
    if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
        return null;
    }
    return (int) $raw;
}

function jeab_bokmalen_ordrar_line_qty_positive(array $line): bool
{
    $qty = jeab_bokmalen_ordrar_line_qty($line);
    return $qty !== null && $qty > 0;
}

function jeab_bokmalen_ordrar_webhook_lines(array $row, string $type = ''): array
{
    $lines = $row['inkop_lines'] ?? [];
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        // Beställ/shop: bara antal > 0. Blank och 0 stannar i JEAB och beställs inte.
        // Tom eller fejk-SKU med positivt antal följer med så Bokmalen kan rapportera.
        // Antal fylls inte i från mall, annan order eller default.
        if ($type === 'ordrar.bestall' && !jeab_bokmalen_ordrar_line_qty_positive($line)) {
            continue;
        }
        $out[] = jeab_bokmalen_ordrar_webhook_line($line);
    }
    return $out;
}

/** Svenskt mobilnummer som +46… Tomt för riktnummer och annat. */
function jeab_bokmalen_ordrar_mobil(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($digits, '0046')) {
        $digits = '0' . substr($digits, 4);
    } elseif (str_starts_with($digits, '46') && strlen($digits) >= 11) {
        $digits = '0' . substr($digits, 2);
    }
    if (preg_match('/\A07[02369]\d{7}\z/', $digits) !== 1) {
        return '';
    }
    if (function_exists('jeab_norm_phone')) {
        $norm = jeab_norm_phone($digits);
        if ($norm !== '') {
            return $norm;
        }
    }

    return $digits;
}

/** @param list<mixed> $candidates */
function jeab_bokmalen_ordrar_first_mobil(array $candidates): string
{
    foreach ($candidates as $raw) {
        if (!is_scalar($raw)) {
            continue;
        }
        $mobil = jeab_bokmalen_ordrar_mobil((string) $raw);
        if ($mobil !== '') {
            return $mobil;
        }
    }

    return '';
}

/** @return list<mixed> */
function jeab_bokmalen_ordrar_phone_candidates(array $row): array
{
    $out = [];
    foreach (['mobil', 'mobile', 'telefon', 'phone'] as $key) {
        $out[] = $row[$key] ?? '';
    }
    $applicant = is_array($row['applicant'] ?? null) ? $row['applicant'] : [];
    foreach (['mobil', 'mobile', 'telefon', 'phone'] as $key) {
        $out[] = $applicant[$key] ?? '';
    }

    return $out;
}

function jeab_bokmalen_ordrar_pnr_key(string $key): bool
{
    $fold = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? $key);
    if ($fold === '') {
        return false;
    }
    foreach ([
        'personnummer', 'personnr', 'pnr', 'idnr', 'ssn', 'socialsecuritynumber',
        'organisationnumber', 'organisationsnummer', 'orgnr', 'personalidentitynumber',
    ] as $bad) {
        if ($fold === $bad) {
            return true;
        }
    }

    return str_contains($fold, 'personnummer')
        || str_ends_with($fold, 'pnr')
        || str_ends_with($fold, 'idnr')
        || str_ends_with($fold, 'orgnr');
}

function jeab_bokmalen_ordrar_strip_pnr_text(string $value): string
{
    $value = preg_replace('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', '', $value) ?? $value;
    $value = preg_replace('/[ \t]{2,}/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+,/', ',', $value) ?? $value;

    return trim($value, " \t,");
}

/** @param array<mixed> $payload */
function jeab_bokmalen_ordrar_payload_without_pnr(array $payload): array
{
    $out = [];
    foreach ($payload as $key => $value) {
        if (is_string($key) && jeab_bokmalen_ordrar_pnr_key($key)) {
            continue;
        }
        if (is_array($value)) {
            $out[$key] = jeab_bokmalen_ordrar_payload_without_pnr($value);
            continue;
        }
        if (is_string($value)) {
            $out[$key] = jeab_bokmalen_ordrar_strip_pnr_text($value);
            continue;
        }
        $out[$key] = $value;
    }

    return $out;
}

/**
 * Gata, postnummer och ort. Postnummer blir «123 45» när det är fem siffror.
 *
 * @param array{street?:string,postal?:string,city?:string} $place
 * @return array{street:string,postal:string,city:string}
 */
function jeab_bokmalen_ordrar_place_clean(array $place): array
{
    $street = trim((string) ($place['street'] ?? ''));
    $postal = trim((string) ($place['postal'] ?? ''));
    $city = trim((string) ($place['city'] ?? ''));
    if ($street !== '' && str_contains($street, ',') && function_exists('jeab_address_parts')) {
        $parts = jeab_address_parts($street);
        $splitStreet = trim((string) ($parts['street'] ?? ''));
        if ($splitStreet !== '') {
            $street = $splitStreet;
            if ($postal === '') {
                $postal = trim((string) ($parts['postal'] ?? ''));
            }
            if ($city === '') {
                $city = trim((string) ($parts['city'] ?? ''));
            }
        }
    }
    if ($street !== '' && function_exists('jeab_ellevio_split_address')) {
        $parts = jeab_ellevio_split_address($street);
        $splitStreet = trim((string) ($parts['adress'] ?? ''));
        $splitPost = trim((string) ($parts['postnummer'] ?? ''));
        if ($splitStreet !== '' && $splitPost !== '') {
            $street = $splitStreet;
            if ($postal === '') {
                $postal = $splitPost;
            }
            if ($city === '') {
                $city = trim((string) ($parts['ort'] ?? ''));
            }
        }
    }
    $city = preg_replace('/\s*,?\s*(?:sverige|sweden)\s*$/iu', '', $city) ?? $city;
    $city = trim($city, " \t,");
    if (function_exists('jeab_ordrar_format_postnummer')) {
        $fmt = jeab_ordrar_format_postnummer($postal);
        if ($fmt !== '') {
            $postal = $fmt;
        }
    }

    return ['street' => $street, 'postal' => $postal, 'city' => $city];
}

/**
 * @param array{street?:string,postal?:string,city?:string} $place
 * @param array{street?:string,postal?:string,city?:string} $next
 * @return array{street:string,postal:string,city:string}
 */
function jeab_bokmalen_ordrar_place_fill(array $place, array $next): array
{
    foreach (['street', 'postal', 'city'] as $key) {
        if (trim((string) ($place[$key] ?? '')) === '' && trim((string) ($next[$key] ?? '')) !== '') {
            $place[$key] = trim((string) $next[$key]);
        }
    }

    return [
        'street' => trim((string) ($place['street'] ?? '')),
        'postal' => trim((string) ($place['postal'] ?? '')),
        'city' => trim((string) ($place['city'] ?? '')),
    ];
}

/** @return array{street:string,postal:string,city:string} */
function jeab_bokmalen_ordrar_order_place(array $row): array
{
    if (function_exists('jeab_ordrar_status_address_stored')) {
        $stored = jeab_ordrar_status_address_stored($row);

        return jeab_bokmalen_ordrar_place_clean([
            'street' => (string) ($stored['street'] ?? ''),
            'postal' => (string) ($stored['postnummer'] ?? ''),
            'city' => (string) ($stored['place'] ?? ''),
        ]);
    }

    return jeab_bokmalen_ordrar_place_clean([
        'street' => (string) ($row['gata'] ?? $row['address'] ?? ''),
        'postal' => (string) ($row['postnummer'] ?? $row['postnr'] ?? ''),
        'city' => (string) ($row['ort'] ?? ''),
    ]);
}

/** @return array{street:string,postal:string,city:string} */
function jeab_bokmalen_ordrar_reminder_place(array $reminder): array
{
    $mapped = $reminder;
    if (trim((string) ($mapped['postnummer'] ?? $mapped['postnr'] ?? '')) === '') {
        $mapped['postnummer'] = (string) ($reminder['postal'] ?? $reminder['postal_code'] ?? '');
    }
    if (trim((string) ($mapped['ort'] ?? '')) === '') {
        $mapped['ort'] = (string) ($reminder['city'] ?? '');
    }
    if (trim((string) ($mapped['gata'] ?? '')) === '') {
        $mapped['gata'] = (string) ($reminder['street'] ?? '');
    }

    return jeab_bokmalen_ordrar_order_place($mapped);
}

function jeab_bokmalen_ordrar_linked_reminder(array $row): ?array
{
    if (!function_exists('jeab_ordrar_reminder_for_row')) {
        return null;
    }
    try {
        $reminder = jeab_ordrar_reminder_for_row($row);
    } catch (Throwable) {
        return null;
    }

    return is_array($reminder) ? $reminder : null;
}

function jeab_bokmalen_ordrar_linked_contact(array $row, ?array $reminder): ?array
{
    $ids = [];
    foreach ([$row['contact_id'] ?? '', is_array($reminder) ? ($reminder['contact_id'] ?? '') : ''] as $id) {
        $id = trim((string) $id);
        if ($id !== '' && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    foreach ($ids as $id) {
        $contact = null;
        if (function_exists('jeab_contacts_sole_linked_contact')) {
            $contact = jeab_contacts_sole_linked_contact($id);
        } elseif (function_exists('jeab_kontakt_get')) {
            $got = jeab_kontakt_get($id);
            $contact = is_array($got) ? $got : null;
        }
        if (is_array($contact)) {
            return $contact;
        }
    }

    return null;
}

/** @return array{street:string,postal:string,city:string} */
function jeab_bokmalen_ordrar_contact_place(array $contact): array
{
    if (function_exists('jeab_contacts_sole_view_from_contact')) {
        $view = jeab_contacts_sole_view_from_contact($contact);

        return jeab_bokmalen_ordrar_place_clean([
            'street' => (string) ($view['street'] ?? ''),
            'postal' => (string) ($view['postal'] ?? ''),
            'city' => (string) ($view['city'] ?? ''),
        ]);
    }

    return jeab_bokmalen_ordrar_place_clean([
        'street' => (string) ($contact['street'] ?? $contact['address'] ?? ''),
        'postal' => (string) ($contact['postal_code'] ?? $contact['postal'] ?? $contact['postnummer'] ?? ''),
        'city' => (string) ($contact['city'] ?? $contact['ort'] ?? ''),
    ]);
}

/** @return list<mixed> */
function jeab_bokmalen_ordrar_contact_phones(array $contact): array
{
    $out = [];
    foreach (['mobil', 'mobile', 'telefon', 'phone'] as $key) {
        $out[] = $contact[$key] ?? '';
    }
    if (function_exists('jeab_kontakt_row_phone')) {
        $out[] = jeab_kontakt_row_phone($contact);
    }
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        foreach (['mobil', 'mobile', 'telefon', 'phone'] as $key) {
            $out[] = $owner[$key] ?? '';
        }
    }

    return $out;
}

function jeab_bokmalen_ordrar_date_ymd(mixed $raw): string
{
    if (!is_scalar($raw)) {
        return '';
    }
    $text = trim((string) $raw);
    if ($text === '') {
        return '';
    }
    if (function_exists('jeab_ordrar_ymd')) {
        return jeab_ordrar_ymd($text);
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $m) === 1) {
        return $m[1];
    }

    return '';
}

/** Önskat leveransdatum som ordersidan visar, sedan ett sparat datum på raden. */
function jeab_bokmalen_ordrar_order_leveransdatum(array $row): string
{
    try {
        if (function_exists('jeab_ordrar_onskad_leverans')) {
            $ymd = jeab_bokmalen_ordrar_date_ymd(jeab_ordrar_onskad_leverans($row));
            if ($ymd !== '') {
                return $ymd;
            }
        }
    } catch (Throwable) {
        // Datumet får inte stoppa beställningen.
    }
    foreach (['leveransdatum', 'onskad_leverans', 'önskad_leverans', 'leverans'] as $key) {
        $ymd = jeab_bokmalen_ordrar_date_ymd($row[$key] ?? '');
        if ($ymd !== '') {
            return $ymd;
        }
    }

    return '';
}

function jeab_bokmalen_ordrar_row_leveransdatum(array $row): string
{
    foreach (['leveransdatum', 'onskad_leverans', 'önskad_leverans', 'delivery_date', 'leverans'] as $key) {
        $ymd = jeab_bokmalen_ordrar_date_ymd($row[$key] ?? '');
        if ($ymd !== '') {
            return $ymd;
        }
    }

    return '';
}

/** Fortnox-kundkort. Tomt när kundnummer saknas eller Fortnox inte svarar. */
function jeab_bokmalen_ordrar_fortnox_customer(array $row): array
{
    if (isset($GLOBALS['_JEAB_FORTNOX_CUSTOMER_GET']) && is_callable($GLOBALS['_JEAB_FORTNOX_CUSTOMER_GET'])) {
        try {
            $got = ($GLOBALS['_JEAB_FORTNOX_CUSTOMER_GET'])($row);
        } catch (Throwable) {
            return [];
        }

        return is_array($got) ? $got : [];
    }
    $number = '';
    foreach (['fortnox_customer', 'fortnox_customer_number', 'fortnox_kundnummer'] as $key) {
        $number = trim((string) ($row[$key] ?? ''));
        if ($number !== '') {
            break;
        }
    }
    if ($number === '' || !class_exists('FortnoxAPI')) {
        return [];
    }
    try {
        $account = function_exists('jeab_ordrar_fortnox_account')
            ? jeab_ordrar_fortnox_account($row['company'] ?? null)
            : 'Jarfalla';

        return (new FortnoxAPI($account))->getCustomer($number);
    } catch (Throwable) {
        return [];
    }
}

/** @return array{street:string,postal:string,city:string} */
function jeab_bokmalen_ordrar_fortnox_place(array $customer): array
{
    $body = is_array($customer['Customer'] ?? null) ? $customer['Customer'] : $customer;
    $street = trim((string) ($body['DeliveryAddress1'] ?? ''));
    $postal = trim((string) ($body['DeliveryZipCode'] ?? ''));
    $city = trim((string) ($body['DeliveryCity'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($body['Address1'] ?? ''));
    }
    if ($postal === '') {
        $postal = trim((string) ($body['ZipCode'] ?? ''));
    }
    if ($city === '') {
        $city = trim((string) ($body['City'] ?? ''));
    }

    return jeab_bokmalen_ordrar_place_clean([
        'street' => $street,
        'postal' => $postal,
        'city' => $city,
    ]);
}

/** @return list<mixed> */
function jeab_bokmalen_ordrar_fortnox_phones(array $customer): array
{
    $body = is_array($customer['Customer'] ?? null) ? $customer['Customer'] : $customer;
    $out = [];
    foreach (['MobilePhone', 'Mobile', 'Phone2', 'Phone1', 'Phone'] as $key) {
        $out[] = $body[$key] ?? '';
    }

    return $out;
}

function jeab_bokmalen_ordrar_fortnox_leveransdatum(array $customer, array $row): string
{
    $body = is_array($customer['Customer'] ?? null) ? $customer['Customer'] : $customer;
    foreach (['DeliveryDate', 'deliveryDate'] as $key) {
        $ymd = jeab_bokmalen_ordrar_date_ymd($body[$key] ?? '');
        if ($ymd !== '') {
            return $ymd;
        }
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) === '' || !function_exists('jeab_ordrar_fortnox_fetch_order')) {
        return '';
    }
    try {
        $order = jeab_ordrar_fortnox_fetch_order($row);
    } catch (Throwable) {
        return '';
    }
    $orderBody = is_array($order['Order'] ?? null) ? $order['Order'] : $order;
    foreach (['DeliveryDate', 'deliveryDate', 'leveransdatum'] as $key) {
        $ymd = jeab_bokmalen_ordrar_date_ymd($orderBody[$key] ?? '');
        if ($ymd !== '') {
            return $ymd;
        }
    }

    return '';
}

/**
 * Beställ-payload: orderfält, sedan länkad påminnelse/kontakt, sedan Fortnox leveransadress.
 * Personnummer och andra pnr-fält följer inte med.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function jeab_bokmalen_ordrar_bestall_delivery(array $payload, array $row): array
{
    $place = jeab_bokmalen_ordrar_order_place($row);
    $phone = jeab_bokmalen_ordrar_first_mobil(jeab_bokmalen_ordrar_phone_candidates($row));
    $date = jeab_bokmalen_ordrar_order_leveransdatum($row);
    $reminder = null;
    if ($place['street'] === '' || $place['postal'] === '' || $place['city'] === '' || $phone === '' || $date === '') {
        $reminder = jeab_bokmalen_ordrar_linked_reminder($row);
        // Kontakten fyller bara det ordern saknar, och vinner över påminnelsens kopia.
        $contact = jeab_bokmalen_ordrar_linked_contact($row, $reminder);
        if (is_array($contact)) {
            $place = jeab_bokmalen_ordrar_place_fill($place, jeab_bokmalen_ordrar_contact_place($contact));
            if ($phone === '') {
                $phone = jeab_bokmalen_ordrar_first_mobil(jeab_bokmalen_ordrar_contact_phones($contact));
            }
        }
        if (is_array($reminder)) {
            $place = jeab_bokmalen_ordrar_place_fill($place, jeab_bokmalen_ordrar_reminder_place($reminder));
            if ($phone === '') {
                $phones = jeab_bokmalen_ordrar_phone_candidates($reminder);
                if (function_exists('jeab_reminder_phone')) {
                    try {
                        $phones[] = jeab_reminder_phone($reminder);
                    } catch (Throwable) {
                        // Anteckningar får inte stoppa numret från fältet.
                    }
                }
                $phone = jeab_bokmalen_ordrar_first_mobil($phones);
            }
            if ($date === '') {
                $date = jeab_bokmalen_ordrar_row_leveransdatum($reminder);
            }
        }
    }
    $customer = [];
    if ($place['street'] === '' || $place['postal'] === '' || $place['city'] === '' || $phone === '' || $date === '') {
        $customer = jeab_bokmalen_ordrar_fortnox_customer($row);
        if ($customer !== []) {
            $place = jeab_bokmalen_ordrar_place_fill($place, jeab_bokmalen_ordrar_fortnox_place($customer));
            if ($phone === '') {
                $phone = jeab_bokmalen_ordrar_first_mobil(jeab_bokmalen_ordrar_fortnox_phones($customer));
            }
            if ($date === '') {
                $date = jeab_bokmalen_ordrar_fortnox_leveransdatum($customer, $row);
            }
        } elseif ($date === '') {
            $date = jeab_bokmalen_ordrar_fortnox_leveransdatum([], $row);
        }
    }
    $place = jeab_bokmalen_ordrar_place_clean($place);
    if (trim((string) ($payload['address'] ?? '')) === '') {
        $payload['address'] = $place['street'];
    }
    $payload['leveransadress'] = $place['street'];
    $payload['postnummer'] = $place['postal'];
    $payload['ort'] = $place['city'];
    $payload['telefon'] = $phone;
    $payload['leveransdatum'] = $date;

    return jeab_bokmalen_ordrar_payload_without_pnr($payload);
}

function jeab_bokmalen_ordrar_webhook_payload(string $type, array $row, bool $utkast): ?array
{
    $kind = jeab_bokmalen_ordrar_webhook_kind($row);
    if ($kind === '') {
        return null;
    }
    // Live-anrop använder orderradens reminder_id. Ingen probe-id.
    $orderId = (int) ($row['reminder_id'] ?? 0);
    if ($orderId <= 0) {
        $orderId = (int) ($row['id'] ?? 0);
    }
    if ($orderId <= 0) {
        return null;
    }
    $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($row['address'] ?? ''));
    }
    $customer = function_exists('jeab_ordrar_name')
        ? jeab_ordrar_name($row)
        : trim((string) ($row['name'] ?? $row['customer_name'] ?? ''));
    $payload = [
        'source' => 'jeab',
        'type' => $type,
        'order_id' => $orderId,
        'kind' => $kind,
        'natbolag' => trim((string) ($row['natbolag'] ?? '')),
        'anlaggnings_id' => function_exists('jeab_ordrar_anlaggnings_id_digits')
            ? jeab_ordrar_anlaggnings_id_digits((string) ($row['anlaggnings_id'] ?? ''))
            : (preg_replace('/\D+/u', '', (string) ($row['anlaggnings_id'] ?? '')) ?? ''),
        'kwh' => trim((string) ($row['kwh'] ?? '')),
        'utkast' => $utkast,
        'company' => trim((string) ($row['company'] ?? '')),
        'address' => $address,
        'customer_name' => $customer,
        'fortnox_order' => trim((string) ($row['fortnox_order'] ?? '')),
        'inkop_lines' => jeab_bokmalen_ordrar_webhook_lines($row, $type),
    ];
    if ($type === 'ordrar.foranmalan') {
        $payload['personnummer'] = jeab_bokmalen_ordrar_foranmalan_pnr($row);
        $payload['fastighetsbeteckning'] = jeab_bokmalen_ordrar_foranmalan_fastighet($row);
    }
    if ($type === 'ordrar.bestall') {
        // Samma adress som ordersidan: order, sedan påminnelse/kontakt, sedan Fortnox.
        // Befintliga fält ligger kvar. Personnummer hör bara till föranmälan.
        $payload = jeab_bokmalen_ordrar_bestall_delivery($payload, $row);
    }

    return $payload;
}

function jeab_bokmalen_ordrar_foranmalan_pnr(array $row): string
{
    $fromContact = function_exists('jeab_contacts_sole_preferred_pnr') ? jeab_contacts_sole_preferred_pnr($row) : '';
    if ($fromContact !== '') {
        return $fromContact;
    }
    $raw = trim((string) ($row['personnummer'] ?? ''));
    if ($raw === '') {
        return '';
    }

    return function_exists('jeab_contacts_sole_pnr_text') ? jeab_contacts_sole_pnr_text($raw) : $raw;
}

function jeab_bokmalen_ordrar_foranmalan_fastighet(array $row): string
{
    $fromContact = function_exists('jeab_contacts_sole_preferred_fastighet') ? jeab_contacts_sole_preferred_fastighet($row) : '';
    if ($fromContact !== '') {
        return $fromContact;
    }

    return trim((string) ($row['fastighetsbeteckning'] ?? ''));
}

function jeab_bokmalen_ordrar_order_no_ok(string $value): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 40 || preg_match('/\s/u', $value) === 1) {
        return '';
    }
    if (preg_match('/^(true|false|null|ok|yes|no)$/i', $value) === 1) {
        return '';
    }
    return $value;
}

function jeab_bokmalen_ordrar_order_no_from_mixed(mixed $value): string
{
    if (is_string($value) || is_int($value) || is_float($value)) {
        return jeab_bokmalen_ordrar_order_no_ok((string) $value);
    }
    if (!is_array($value)) {
        return '';
    }
    foreach (['order_no', 'ordernr', 'order', 'shop_order', 'number', 'ordernummer', 'order_number'] as $key) {
        if (!is_scalar($value[$key] ?? null)) {
            continue;
        }
        $got = jeab_bokmalen_ordrar_order_no_ok((string) $value[$key]);
        if ($got !== '') {
            return $got;
        }
    }
    return '';
}

function jeab_bokmalen_ordrar_line_shop_is(array $line, string $shop): bool
{
    $have = strtolower(trim((string) ($line['shop'] ?? $line['webshop'] ?? '')));
    if ($have === $shop) {
        return true;
    }
    return $have === 'aprilice|lightleap' && ($shop === 'aprilice' || $shop === 'lightleap');
}

function jeab_bokmalen_ordrar_pick_order_no(array $node, string $shop): string
{
    foreach ([$shop . '_order', $shop . '_ordernr', $shop . '_order_no', $shop . '_shop_order', $shop] as $key) {
        if (!is_scalar($node[$key] ?? null)) {
            continue;
        }
        $got = jeab_bokmalen_ordrar_order_no_ok((string) $node[$key]);
        if ($got !== '') {
            return $got;
        }
    }
    foreach (['orders', 'shops', 'shop_orders'] as $bagKey) {
        $bag = $node[$bagKey] ?? null;
        if (!is_array($bag)) {
            continue;
        }
        if (array_key_exists($shop, $bag)) {
            $got = jeab_bokmalen_ordrar_order_no_from_mixed($bag[$shop]);
            if ($got !== '') {
                return $got;
            }
        }
        foreach ($bag as $item) {
            if (!is_array($item) || !jeab_bokmalen_ordrar_line_shop_is($item, $shop)) {
                continue;
            }
            $got = jeab_bokmalen_ordrar_order_no_from_mixed($item);
            if ($got !== '') {
                return $got;
            }
        }
    }
    $lines = $node['inkop_lines'] ?? null;
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if (!is_array($line) || !jeab_bokmalen_ordrar_line_shop_is($line, $shop)) {
                continue;
            }
            $got = jeab_bokmalen_ordrar_order_no_from_mixed($line);
            if ($got !== '') {
                return $got;
            }
        }
    }
    return '';
}

function jeab_bokmalen_ordrar_pick_when(array $node, array $keys): string
{
    foreach ($keys as $key) {
        if (!is_scalar($node[$key] ?? null)) {
            continue;
        }
        $raw = trim((string) $node[$key]);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::\d{2})?)?/', $raw, $m) === 1) {
            return isset($m[2]) ? $m[1] . ' ' . $m[2] . ':00' : $m[1];
        }
    }
    return '';
}

/**
 * moms=inkl eller inkl_moms betyder att beloppet är inklusive 25 % moms.
 * moms=exkl betyder att det redan är på samma bas som Aprilice lagras.
 * Tom sträng: anroparen väljer leverantörens standard (Lightleap inkl, Aprilice exkl).
 */
function jeab_bokmalen_ordrar_moms_mode(array $node): string
{
    foreach (['moms', 'vat'] as $key) {
        if (!is_scalar($node[$key] ?? null)) {
            continue;
        }
        $value = strtolower(trim((string) $node[$key]));
        $value = str_replace([' ', '_', '-'], '', $value);
        if (in_array($value, ['inkl', 'inklusive', 'incl', 'inclusive', 'inkluderad'], true)) {
            return 'inkl';
        }
        if (in_array($value, ['exkl', 'exklusive', 'excl', 'exclusive'], true)) {
            return 'exkl';
        }
    }
    foreach (['inkl_moms', 'including_vat', 'vat_included', 'moms_inkl'] as $key) {
        if (!array_key_exists($key, $node)) {
            continue;
        }
        $raw = $node[$key];
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return 'inkl';
        }
        if ($raw === false || $raw === 0 || $raw === '0' || $raw === 'false') {
            return 'exkl';
        }
    }
    foreach (['exkl_moms', 'excluding_vat', 'moms_exkl'] as $key) {
        if (!array_key_exists($key, $node)) {
            continue;
        }
        $raw = $node[$key];
        if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true') {
            return 'exkl';
        }
    }
    return '';
}

/** Beställningens kostnad i kronor ur Bokmalens svar (kostnad/total/…). Tom om den saknas. */
function jeab_bokmalen_ordrar_pick_amount(array $node): string
{
    foreach (['kostnad', 'cost', 'order_total', 'total', 'total_amount', 'amount', 'summa'] as $key) {
        $raw = $node[$key] ?? null;
        if (is_array($raw)) {
            $raw = $raw['amount'] ?? $raw['value'] ?? null;
        }
        if (!is_scalar($raw) || is_bool($raw)) {
            continue;
        }
        $clean = str_replace(["\u{00A0}", "\u{202F}", ' ', 'kr', 'SEK'], '', trim((string) $raw));
        $clean = str_replace(',', '.', $clean);
        if (is_numeric($clean) && (float) $clean > 0) {
            return (string) (int) round((float) $clean);
        }
    }
    return '';
}

/**
 * Shop-nummer, leverans och tid från Bokmalens svar. Tomma fält lämnas tomma.
 *
 * @return array{aprilice:string,lightleap:string,leverans:string,bestalld_at:string,kostnad:string,supplier:string,reference:string,moms:string,lines:list<array<string,mixed>>}
 */
function jeab_bokmalen_ordrar_shop_facts(?array $json): array
{
    $facts = [
        'aprilice' => '', 'lightleap' => '', 'leverans' => '', 'bestalld_at' => '', 'kostnad' => '',
        'supplier' => '', 'reference' => '', 'moms' => '', 'lines' => [],
    ];
    if ($json === null || $json === []) {
        return $facts;
    }
    $nodes = [$json];
    foreach (['data', 'result', 'order', 'response'] as $key) {
        if (is_array($json[$key] ?? null)) {
            $nodes[] = $json[$key];
        }
    }
    foreach ($nodes as $node) {
        foreach (['aprilice', 'lightleap'] as $shop) {
            if ($facts[$shop] !== '') {
                continue;
            }
            $facts[$shop] = jeab_bokmalen_ordrar_pick_order_no($node, $shop);
        }
        if ($facts['leverans'] === '') {
            $facts['leverans'] = jeab_bokmalen_ordrar_pick_when($node, [
                'leverans', 'faktisk_leverans', 'delivery', 'delivery_date', 'leveransdatum', 'deliveryDate',
            ]);
        }
        if ($facts['bestalld_at'] === '') {
            $facts['bestalld_at'] = jeab_bokmalen_ordrar_pick_when($node, [
                'bestalld_at', 'ordered_at', 'placed_at', 'bestalld',
            ]);
        }
        if ($facts['kostnad'] === '') {
            $facts['kostnad'] = jeab_bokmalen_ordrar_pick_amount($node);
        }
        if ($facts['supplier'] === '') {
            foreach (['supplier', 'leverantor'] as $key) {
                if (!is_scalar($node[$key] ?? null)) {
                    continue;
                }
                $shop = function_exists('jeab_ordrar_normalize_shop')
                    ? jeab_ordrar_normalize_shop((string) $node[$key])
                    : strtolower(trim((string) $node[$key]));
                if ($shop !== '') {
                    $facts['supplier'] = $shop;
                    break;
                }
            }
        }
        if ($facts['reference'] === '') {
            foreach (['reference', 'referens', 'order_ref'] as $key) {
                if (is_scalar($node[$key] ?? null) && trim((string) $node[$key]) !== '') {
                    $facts['reference'] = trim((string) $node[$key]);
                    break;
                }
            }
        }
        if ($facts['moms'] === '') {
            $facts['moms'] = jeab_bokmalen_ordrar_moms_mode($node);
        }
        if ($facts['lines'] === []) {
            foreach (['supplier_lines', 'suppliers', 'leverantorer'] as $key) {
                if (!is_array($node[$key] ?? null)) {
                    continue;
                }
                foreach ($node[$key] as $line) {
                    if (is_array($line)) {
                        $facts['lines'][] = $line;
                    }
                }
            }
        }
    }
    return $facts;
}

/** Produktnamn i plomb-meddelandet. Tomt för okänd produkt. */
function jeab_bokmalen_ordrar_product_label(array $row): string
{
    $kind = function_exists('jeab_ordrar_ui_kind')
        ? jeab_ordrar_ui_kind($row['kind'] ?? '')
        : trim((string) ($row['kind'] ?? ''));
    if ($kind === '') {
        $raw = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string) ($row['kind'] ?? '')), 'UTF-8')
            : strtolower(trim((string) ($row['kind'] ?? '')));
        if ($raw === 'servicejobb' || str_contains($raw, 'servicejobb')) {
            $kind = 'servicejobb';
        }
    }
    return match ($kind) {
        'sol' => 'Sol',
        'batteri' => 'Batteri',
        'laddbox' => 'Elbilsladdare',
        'servicejobb' => 'Servicejobb',
        default => '',
    };
}

/**
 * Plombbrytning på samma ordrar-webhook som föranmälan.
 * Bokmalen skiljer meddelandet på type=ordrar.plombbrytning.
 * Bara adress, nätägare, anläggnings-id (om det finns), produkt och ordernummer.
 *
 * @return array<string,mixed>|null
 */
function jeab_bokmalen_ordrar_plomb_payload(array $row): ?array
{
    $product = jeab_bokmalen_ordrar_product_label($row);
    if ($product === '') {
        return null;
    }
    $orderId = (int) ($row['reminder_id'] ?? 0);
    if ($orderId <= 0) {
        $orderId = (int) ($row['id'] ?? 0);
    }
    if ($orderId <= 0) {
        return null;
    }
    $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($row['address'] ?? ''));
    }
    $aid = function_exists('jeab_ordrar_anlaggnings_id_digits')
        ? jeab_ordrar_anlaggnings_id_digits((string) ($row['anlaggnings_id'] ?? ''))
        : (preg_replace('/\D+/u', '', (string) ($row['anlaggnings_id'] ?? '')) ?? '');
    $orderNo = jeab_bokmalen_ordrar_order_no_ok(trim((string) ($row['fortnox_order'] ?? '')));
    if ($orderNo === '') {
        $orderNo = (string) $orderId;
    }
    $nat = jeab_ordrar_bokmalen_nat($row);
    $kind = match ($product) {
        'Sol' => 'sol',
        'Batteri' => 'batteri',
        'Elbilsladdare' => 'laddbox',
        'Servicejobb' => 'servicejobb',
        default => '',
    };
    return [
        'source' => 'jeab',
        'type' => 'ordrar.plombbrytning',
        'order_id' => $orderId,
        'order_number' => $orderNo,
        'kind' => $kind,
        'product' => $product,
        'address' => $address,
        'natbolag' => $nat,
        'natagare' => $nat,
        'anlaggnings_id' => $aid,
    ];
}

/**
 * @return array{aprilice:string,lightleap:string,leverans:string,bestalld_at:string}|null
 */
function jeab_bokmalen_ordrar_notify(string $type, array $row, bool $utkast, array $extra = []): ?array
{
    // Utkast postas med utkast:true. Bokmalen läser flaggan och kör inte live-shop.
    // Automatisk beställ och skickad föranmälan har utkast:false.
    // Laddbox och okänd produkt ger ingen payload och därmed ingen POST för de typerna.
    // Plombbrytning (ordrar.plombbrytning) använder samma POST och secret, med egen payload
    // som även laddbox och servicejobb får skicka.
    // Svarets shop-nummer, leverans och tid lämnas tillbaka så ordern kan spara dem.
    try {
        if (function_exists('jeab_ellevio_fullmakt_pull_signed')) {
            $row = jeab_ellevio_fullmakt_pull_signed($row, false);
        }
        $payload = $type === 'ordrar.plombbrytning'
            ? jeab_bokmalen_ordrar_plomb_payload($row)
            : jeab_bokmalen_ordrar_webhook_payload($type, $row, $utkast);
        if ($payload !== null && $extra !== []) {
            // T.ex. resend:true när föranmälan skickas igen. Grundfälten skrivs aldrig över.
            foreach ($extra as $key => $value) {
                if (is_string($key) && !array_key_exists($key, $payload) && (is_scalar($value) || $value === null)) {
                    $payload[$key] = $value;
                }
            }
        }
        if ($payload === null) {
            if ($type === 'ordrar.foranmalan' || $type === 'ordrar.bestall' || $type === 'ordrar.plombbrytning') {
                jeab_bokmalen_ordrar_notify_fail($type, 'payload saknas (kind/order_id)');
            }
            return null;
        }
        $secret = jeab_bokmalen_ordrar_webhook_secret();
        if ($secret === null) {
            jeab_bokmalen_ordrar_notify_fail($type, 'secret saknas eller är ogiltig');
            return null;
        }
        // Aprilice kan ta >5s. Vänta upp till 25s på sync shop_facts; annars kommer callback.
        // Ett försök. Misslyckad POST loggas och returnerar null. Ingen automatisk omsändning.
        $exchange = jeab_einstein_webhook_exchange($secret, $payload, 25);
        if (($exchange['ok'] ?? false) !== true) {
            $http = (int) ($exchange['http'] ?? 0);
            jeab_bokmalen_ordrar_notify_fail($type, 'POST misslyckades (HTTP ' . $http . ')');
            return null;
        }
        $json = $exchange['json'] ?? null;
        return jeab_bokmalen_ordrar_shop_facts(is_array($json) ? $json : null);
    } catch (Throwable $e) {
        jeab_bokmalen_ordrar_notify_fail($type, $e->getMessage() !== '' ? $e->getMessage() : 'oväntat fel');
        return null;
    }
}

/**
 * Live read for «Uppdatera status». read:true and utkast:true so Bokmalen does not
 * create a case. A real status in the answer is applied. No status means no write.
 *
 * @return array<string, mixed>
 */
function jeab_bokmalen_ordrar_status_read(array $row): array
{
    $payload = jeab_bokmalen_ordrar_webhook_payload('ordrar.foranmalan', $row, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload'];
    }
    $payload['type'] = 'ordrar.foranmalan_status';
    $payload['read'] = true;
    $payload['utkast'] = true;
    $secret = jeab_bokmalen_ordrar_webhook_secret();
    if ($secret === null) {
        return ['ok' => false, 'error' => 'secret'];
    }
    $exchange = jeab_einstein_webhook_exchange($secret, $payload, 25);
    if (($exchange['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => 'post'];
    }
    $json = $exchange['json'] ?? null;
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'json'];
    }
    $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
    $status = '';
    foreach (['status', 'lage', 'foranmalan_status'] as $key) {
        if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
            $status = trim((string) $data[$key]);
            break;
        }
    }
    if ($status === '' || !function_exists('jeab_ordrar_normalize_foranmalan_case_status')
        || jeab_ordrar_normalize_foranmalan_case_status($status) === '') {
        return ['ok' => false, 'error' => 'status'];
    }
    $comment = '';
    foreach (['kommentar', 'comment', 'note', 'message'] as $key) {
        if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
            $comment = trim((string) $data[$key]);
            break;
        }
    }

    return jeab_bokmalen_ordrar_inbound_foranmalan([
        'order_id' => (int) ($row['reminder_id'] ?? 0),
        'status' => $status,
        'kommentar' => $comment,
        'at' => is_scalar($data['at'] ?? null) ? (string) $data['at'] : '',
    ]);
}

function jeab_bokmalen_ordrar_inbound_authorized(): bool
{
    $secret = jeab_bokmalen_ordrar_webhook_secret();
    if ($secret === null) {
        return false;
    }
    $key = trim((string) ($secret['key'] ?? ''));
    return $key !== '' && function_exists('jeab_incoming_token_ok') && jeab_incoming_token_ok($key);
}

/**
 * Lås per order för inkommande Bokmalen-anrop. Egen låsfil (inte samma som utgående
 * jeab_ordrar_bokmalen_once), så ett svar som Bokmalen skickar medan vårt eget anrop
 * väntar inte låser fast sig. Två inkommande för samma order körs efter varandra.
 */
function jeab_bokmalen_ordrar_inbound_lock(int $orderId, callable $fn): mixed
{
    $dir = (function_exists('jeab_data_dir') ? jeab_data_dir() : sys_get_temp_dir()) . '/locks';
    if ($orderId <= 0 || (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))) {
        return $fn();
    }
    $fh = @fopen($dir . '/bokmalen-in-' . $orderId . '.lock', 'c');
    if ($fh === false) {
        return $fn();
    }
    try {
        flock($fh, LOCK_EX);
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Bokmalens valfria event_id (för omsändningar). Tom om saknas eller ogiltig. */
function jeab_bokmalen_ordrar_event_id(array $input): string
{
    foreach (['event_id', 'idempotency_key', 'delivery_id'] as $key) {
        if (!is_scalar($input[$key] ?? null)) {
            continue;
        }
        $id = trim((string) $input[$key]);
        if ($id !== '' && strlen($id) <= 100 && preg_match('/^[A-Za-z0-9._:\-]+$/', $id) === 1) {
            return $id;
        }
    }
    return '';
}

function jeab_bokmalen_ordrar_event_seen(array $row, string $eventId): bool
{
    return $eventId !== '' && in_array($eventId, is_array($row['bokmalen_events'] ?? null) ? $row['bokmalen_events'] : [], true);
}

/** Sparar event_id (senaste 30) så samma leverans igen blir en no-op. */
function jeab_bokmalen_ordrar_event_remember(array $row, string $eventId): array
{
    if ($eventId === '') {
        return $row;
    }
    $seen = is_array($row['bokmalen_events'] ?? null) ? array_values($row['bokmalen_events']) : [];
    $seen[] = $eventId;
    $row['bokmalen_events'] = array_slice(array_values(array_unique($seen)), -30);
    return $row;
}

/** Tidsstämpel (Y-m-d H:i:s, serverns zon) ur Bokmalens «at»/«occurred_at». Tom om saknas. */
function jeab_bokmalen_ordrar_event_at(array $data): string
{
    foreach (['at', 'occurred_at', 'status_at', 'updated_at'] as $key) {
        if (!is_scalar($data[$key] ?? null)) {
            continue;
        }
        $raw = trim((string) $data[$key]);
        if ($raw === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) !== 1) {
            continue;
        }
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return '';
}

function jeab_bokmalen_ordrar_inbound_kind_ok(array $row): bool
{
    if (function_exists('jeab_ordrar_is_solbatteri')) {
        return jeab_ordrar_is_solbatteri($row);
    }
    return in_array(jeab_bokmalen_ordrar_webhook_kind($row), ['batteri', 'sol'], true);
}

/**
 * Async shop_facts från Bokmalen (ordernummer, leverans, beställningstid, kostnad).
 * type: ordrar.shop_facts | ordrar.bestall_result
 *
 * Idempotent per leverantör och referens. Ett nytt leverantörsbelopp läggs till
 * (summa exkl. moms) i stället för att bli en kostnadskonflikt. Samma leverantör
 * och referens igen ändrar inte beloppet. Annan referens för samma leverantör,
 * eller ett annat belopp på samma nyckel, rapporteras i «conflicts» och lämnas.
 * Ordernummer som redan har ett annat värde skrivs inte över. Leveransdatum på
 * ordern blir det senaste bland leverantörerna. Samma anrop igen ger unchanged:true.
 * steps.bestalld sätts bara när ett ordernummer eller bestalld_at kommer (aldrig av
 * enbart kostnad/leverans).
 *
 * @return array<string,mixed>
 */
function jeab_bokmalen_ordrar_inbound(array $input): array
{
    $type = strtolower(trim((string) ($input['type'] ?? $input['event'] ?? '')));
    if ($type === 'ordrar.foranmalan_status') {
        return jeab_bokmalen_ordrar_inbound_foranmalan($input);
    }
    if ($type !== 'ordrar.bestall_result' && $type !== 'ordrar.shop_facts') {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $orderId = (int) ($input['order_id'] ?? $input['reminder_id'] ?? $input['id'] ?? 0);
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'order_id'];
    }
    if (!function_exists('jeab_purchase_get') || !function_exists('jeab_purchase_put') || !function_exists('jeab_ordrar_apply_placed_shop')) {
        return ['ok' => false, 'error' => 'store'];
    }
    $facts = jeab_bokmalen_ordrar_shop_facts($input);
    $factsEmpty = static function (array $facts): bool {
        return $facts['aprilice'] === '' && $facts['lightleap'] === '' && $facts['leverans'] === ''
            && $facts['bestalld_at'] === '' && $facts['kostnad'] === '' && ($facts['lines'] ?? []) === [];
    };
    if ($factsEmpty($facts)) {
        // Tillåt platt payload utan wrapper.
        $facts = jeab_bokmalen_ordrar_shop_facts(['data' => $input]);
    }
    if ($factsEmpty($facts)) {
        return ['ok' => false, 'error' => 'empty'];
    }
    $eventId = jeab_bokmalen_ordrar_event_id($input);
    return jeab_bokmalen_ordrar_inbound_lock($orderId, static function () use ($orderId, $facts, $eventId): array {
        $row = jeab_purchase_get($orderId);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'missing'];
        }
        if (!jeab_bokmalen_ordrar_inbound_kind_ok($row)) {
            return ['ok' => false, 'error' => 'kind'];
        }
        $out = static function (array $row, bool $unchanged, array $conflicts = [], bool $duplicate = false) use ($orderId): array {
            $res = [
                'ok' => true,
                'order_id' => $orderId,
                'unchanged' => $unchanged,
                'aprilice' => (string) ($row['aprilice_order'] ?? ''),
                'lightleap' => (string) ($row['lightleap_order'] ?? ''),
                'kostnad' => trim((string) ($row['bestall_kostnad'] ?? '')),
                'leverans' => (string) ($row['leverans'] ?? ''),
                'bestalld' => (string) ((is_array($row['steps'] ?? null) ? $row['steps'] : [])['bestalld'] ?? ''),
            ];
            if ($conflicts !== []) {
                $res['conflicts'] = $conflicts;
            }
            if ($duplicate) {
                $res['duplicate'] = true;
            }
            return $res;
        };
        if (jeab_bokmalen_ordrar_event_seen($row, $eventId)) {
            return $out($row, true, [], true);
        }
        $conflicts = [];
        foreach (['aprilice' => 'aprilice_order', 'lightleap' => 'lightleap_order'] as $fact => $field) {
            $sent = trim((string) ($facts[$fact] ?? ''));
            $have = trim((string) ($row[$field] ?? ''));
            if ($sent !== '' && $have !== '' && $sent !== $have) {
                $conflicts[$fact] = ['stored' => $have, 'sent' => $sent];
            }
        }
        if (function_exists('jeab_ordrar_supplier_merge_preview')) {
            $preview = jeab_ordrar_supplier_merge_preview($row, $facts);
            if (!empty($preview['cost_conflict'])) {
                $conflicts['kostnad'] = [
                    'stored' => (string) ($preview['stored_amount'] ?? ''),
                    'sent' => (string) ($preview['sent_amount'] ?? ''),
                ];
            }
        }
        $before = $row;
        $row = jeab_ordrar_apply_placed_shop($row, $facts);
        $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
        $placed = $facts['aprilice'] !== '' || $facts['lightleap'] !== '' || $facts['bestalld_at'] !== '';
        if ($placed && trim((string) ($steps['bestalld'] ?? '')) === '') {
            $day = '';
            if ($facts['bestalld_at'] !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $facts['bestalld_at'], $m) === 1) {
                $day = $m[1];
            }
            if ($day === '' && function_exists('jeab_ordrar_today')) {
                $day = jeab_ordrar_today();
            }
            if ($day !== '') {
                $steps['bestalld'] = $day;
                $row['steps'] = $steps;
            }
        }
        if (function_exists('jeab_ordrar_append_shop_activity_row')) {
            foreach (['aprilice', 'lightleap'] as $shop) {
                $no = trim((string) ($facts[$shop] ?? ''));
                if ($no === '' || isset($conflicts[$shop])) {
                    continue;
                }
                $label = $shop === 'lightleap' ? 'Lightleap' : 'Aprilice';
                $row = jeab_ordrar_append_shop_activity_row($row, $label . ' ' . $no, 'shop:' . $shop);
            }
        }
        $changed = $row !== $before;
        $row = jeab_bokmalen_ordrar_event_remember($row, $eventId);
        if ($row !== $before) {
            if ($changed) {
                $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : ($row['updated_at'] ?? '');
            }
            $row = jeab_purchase_put($row);
        }
        return $out($row, !$changed, $conflicts);
    });
}

/**
 * Bokmalen rapporterar läget hos nätbolaget för en föranmälan.
 * type: ordrar.foranmalan_status, order_id, status (skickad|nekad|godkand|fardiganmald,
 * eller sent|rejected|approved|complete), valfri kommentar, valfri at och event_id.
 * Det är detta «Uppdatera status» i Ordrar visar.
 *
 * Idempotent: samma status och kommentar igen ändrar ingenting (unchanged:true).
 * Samma event_id igen är en no-op (duplicate:true). Ett anrop med «at» äldre än det
 * senast sparade från Bokmalen ignoreras (stale:true), så sen leverans inte backar läget.
 *
 * @return array<string,mixed>
 */
function jeab_bokmalen_ordrar_inbound_foranmalan(array $input): array
{
    $orderId = (int) ($input['order_id'] ?? $input['reminder_id'] ?? $input['id'] ?? 0);
    if ($orderId <= 0) {
        return ['ok' => false, 'error' => 'order_id'];
    }
    if (!function_exists('jeab_purchase_get') || !function_exists('jeab_purchase_put') || !function_exists('jeab_ordrar_normalize_foranmalan_case_status')) {
        return ['ok' => false, 'error' => 'store'];
    }
    $data = is_array($input['data'] ?? null) ? $input['data'] + $input : $input;
    $status = jeab_ordrar_normalize_foranmalan_case_status((string) ($data['status'] ?? $data['lage'] ?? $data['foranmalan_status'] ?? ''));
    if ($status === '') {
        return ['ok' => false, 'error' => 'status'];
    }
    $comment = '';
    foreach (['kommentar', 'comment', 'note', 'message'] as $key) {
        if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
            $comment = trim((string) $data[$key]);
            break;
        }
    }
    $comment = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $comment));
    if (function_exists('mb_substr')) {
        $comment = mb_substr($comment, 0, 500, 'UTF-8');
    }
    $arende = '';
    foreach (['arende', 'arende_nr', 'arendenummer', 'case_number', 'case_id', 'reference'] as $key) {
        if (is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== '') {
            $arende = substr((string) preg_replace('/[^A-Za-z0-9\-]/', '', (string) $data[$key]), 0, 40);
            break;
        }
    }
    $eventAt = jeab_bokmalen_ordrar_event_at($data);
    $eventId = jeab_bokmalen_ordrar_event_id($input);
    return jeab_bokmalen_ordrar_inbound_lock($orderId, static function () use ($orderId, $status, $comment, $arende, $eventAt, $eventId): array {
        $row = jeab_purchase_get($orderId);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'missing'];
        }
        if (!jeab_bokmalen_ordrar_inbound_kind_ok($row)) {
            return ['ok' => false, 'error' => 'kind'];
        }
        $before = jeab_ordrar_foranmalan_case_status($row);
        $res = ['ok' => true, 'order_id' => $orderId, 'status' => $status];
        if (jeab_bokmalen_ordrar_event_seen($row, $eventId)) {
            return $res + ['status_now' => $before, 'unchanged' => true, 'duplicate' => true];
        }
        $lastAt = trim((string) ($row['foranmalan_status_source_at'] ?? ''));
        if ($eventAt !== '' && $lastAt !== '' && strcmp($eventAt, $lastAt) < 0) {
            return $res + ['status_now' => $before, 'unchanged' => true, 'stale' => true];
        }
        $oldComment = trim((string) ($row['foranmalan_kommentar'] ?? ''));
        $oldArende = trim((string) ($row['foranmalan_arende'] ?? ''));
        $steps0 = is_array($row['steps'] ?? null) ? $row['steps'] : [];
        // Ett ärende hos nätbolaget betyder att föranmälan är inskickad: stämpla steget om det saknas.
        $needSent = $status !== 'nekad' && trim((string) ($steps0['foranmald'] ?? '')) === '';
        $same = $status === $before && ($comment === '' || $comment === $oldComment)
            && ($arende === '' || $arende === $oldArende) && !$needSent;
        if ($same) {
            $orig = $row;
            $row = jeab_bokmalen_ordrar_event_remember($row, $eventId);
            if ($eventAt !== '' && strcmp($eventAt, $lastAt) > 0) {
                $row['foranmalan_status_source_at'] = $eventAt;
            }
            if ($row !== $orig) {
                jeab_purchase_put($row);
            }
            return $res + ['status_now' => $before, 'unchanged' => true];
        }
        $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $row['foranmalan_case_status'] = $status;
        if ($arende !== '') {
            $row['foranmalan_arende'] = $arende;
        }
        if ($needSent) {
            if (!is_array($row['steps'] ?? null)) {
                $row['steps'] = [];
            }
            $row['steps']['foranmald'] = $eventAt !== '' ? substr($eventAt, 0, 10) : substr($now, 0, 10);
        }
        if ($comment !== '') {
            $row['foranmalan_kommentar'] = $comment;
        }
        $row['foranmalan_status_at'] = $now;
        if ($eventAt !== '') {
            $row['foranmalan_status_source_at'] = $eventAt;
        }
        if (function_exists('jeab_ordrar_activity_append')) {
            $labels = jeab_ordrar_foranmalan_case_statuses();
            $text = 'Föranmälan: ' . ($labels[$status] ?? $status) . ' (Bokmalen)' . ($arende !== '' ? ' · ärende ' . $arende : '') . ($comment !== '' ? ' – ' . $comment : '');
            $row = jeab_ordrar_activity_append($row, $text, $now, 'foranmalan_lage:' . $status . ':' . $now, false);
        }
        $row = jeab_bokmalen_ordrar_event_remember($row, $eventId);
        $row['updated_at'] = $now;
        jeab_purchase_put($row);
        return $res + ['status_now' => $status, 'unchanged' => false];
    });
}
