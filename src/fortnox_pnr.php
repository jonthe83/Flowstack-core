<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/fortnox.php';

/**
 * Fortnox-hookar för personnummer och organisationsnummer.
 * Svar och logg får innehålla kundnummer och fakturanummer, aldrig själva numret.
 * Inget skickas till kunden. Inget bokförs.
 */

function jeab_fortnox_pnr_authorized(string $action = ''): bool
{
    if (!function_exists('jeab_fortnox_lon_hook_authorized')) {
        require_once __DIR__ . '/fortnox_lon.php';
    }
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if (!$store instanceof JeabStore && function_exists('jeab_boot')) {
        try {
            $store = jeab_boot();
        } catch (Throwable) {
            return false;
        }
    }
    return $store instanceof JeabStore && jeab_fortnox_lon_hook_authorized($store, $action !== '' ? $action : null);
}

function jeab_fortnox_pnr_client(string $account): FortnoxAPI
{
    $factory = $GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] ?? null;
    if ($factory !== null) {
        if (!is_callable($factory)) {
            throw new RuntimeException('Fortnox-klienten saknas.');
        }
        $api = $factory($account);
        if (!$api instanceof FortnoxAPI) {
            throw new RuntimeException('Fortnox-klienten saknas.');
        }
        return $api;
    }
    return new FortnoxAPI($account);
}

/** @return list<string> */
function jeab_fortnox_pnr_accounts(): array
{
    return ['Jarfalla', 'Malmo'];
}

function jeab_fortnox_pnr_account(array $input): string
{
    $raw = trim((string) ($input['account'] ?? ''));
    return in_array($raw, jeab_fortnox_pnr_accounts(), true) ? $raw : '';
}

function jeab_fortnox_pnr_customer_number(array $customer): string
{
    $number = $customer['CustomerNumber'] ?? $customer['customerNumber'] ?? '';
    if (is_int($number) || is_float($number)) {
        $number = (string) $number;
    }
    if (!is_string($number)) {
        return '';
    }
    return trim($number);
}

function jeab_fortnox_pnr_org_field(array $customer): string
{
    foreach (['OrganisationNumber', 'organisationNumber'] as $key) {
        $value = $customer[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
    }
    return '';
}

function jeab_fortnox_pnr_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === 0 || $dry === '0' || $dry === false);
}

function jeab_fortnox_pnr_flag(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 'true';
}

function jeab_fortnox_pnr_id(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,30}$/', $raw) !== 1 || jeab_idnr_has_bad_separator($raw)) {
        return '';
    }
    return $raw;
}

function jeab_fortnox_pnr_mask(string $raw): string
{
    $digits = preg_replace('/\D+/', '', jeab_idnr_clean($raw)) ?? '';
    if (strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) < 6) {
        return '';
    }
    return substr($digits, 0, 6) . '-****';
}

/** Tio siffror som YYMMDD-XXXX. Sekel tas bort. Tomt om det inte är ett sådant nummer. */
function jeab_fortnox_pnr_yymmdd(string $raw): string
{
    $digits = preg_replace('/\D+/', '', jeab_idnr_clean($raw)) ?? '';
    if (strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) !== 10) {
        return '';
    }
    return substr($digits, 0, 6) . '-' . substr($digits, 6);
}

/** @return list<string> */
function jeab_fortnox_pnr_plain_forms(string $raw): array
{
    $clean = jeab_idnr_clean($raw);
    $digits = preg_replace('/\D+/', '', $clean) ?? '';
    $forms = [];
    $ten = jeab_fortnox_pnr_yymmdd($raw);
    if ($ten !== '') {
        $forms[] = $ten;
    }
    if (strlen($digits) === 12) {
        $forms[] = substr($digits, 0, 8) . '-' . substr($digits, 8);
    }
    return $forms;
}

function jeab_fortnox_pnr_compare(string $fortnoxOrg, string $orderPnr): string
{
    $left = jeab_fortnox_pnr_yymmdd($fortnoxOrg);
    $right = jeab_fortnox_pnr_yymmdd($orderPnr);
    if ($left === '' || $right === '' || $left !== $right) {
        return 'skiljer_sig';
    }
    return in_array(trim($fortnoxOrg), jeab_fortnox_pnr_plain_forms($fortnoxOrg), true) ? 'stammer' : 'bara_tecken';
}

function jeab_fortnox_pnr_text(array $row, string $key): string
{
    $value = $row[$key] ?? '';
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value) || is_float($value)) {
        return trim((string) $value);
    }
    return '';
}

/** @param array<string, mixed> $payload */
function jeab_fortnox_pnr_invoice_body(array $payload): array
{
    $invoice = $payload['Invoice'] ?? $payload;
    return is_array($invoice) ? $invoice : [];
}

function jeab_fortnox_pnr_document(array $invoice): string
{
    return jeab_fortnox_pnr_text($invoice, 'DocumentNumber');
}

function jeab_fortnox_pnr_order_pnr(int $orderId): string
{
    if ($orderId <= 0 || !function_exists('jeab_purchase_get')) {
        return '';
    }
    $row = jeab_purchase_get($orderId);
    if (!is_array($row)) {
        return '';
    }
    $pnr = trim((string) ($row['personnummer'] ?? ''));
    return $pnr;
}

/** @param array<string, mixed> $value */
function jeab_fortnox_pnr_scrub(mixed $value, string $key = ''): mixed
{
    $idKeys = [
        'customer', 'customers', 'invoice', 'invoice_number', 'invoices_open', 'invoices_booked',
        'invoices_cancelled', 'new_invoice', 'credit_invoice', 'order_id', 'number', 'count',
        'account', 'zip', 'result', 'mode',
    ];
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $item) {
            $name = is_string($k) ? $k : (string) $k;
            if (in_array($name, ['OrganisationNumber', 'organisationNumber', 'personnummer', 'pnr', 'SocialSecurityNumber'], true)) {
                continue;
            }
            $out[$k] = jeab_fortnox_pnr_scrub($item, $name);
        }
        return $out;
    }
    if (!is_string($value)) {
        return $value;
    }
    if ($key === 'orgnr') {
        if ($value === '' || preg_match('/^\d{6}-\*{4}$/', $value) === 1) {
            return $value;
        }
        return jeab_fortnox_pnr_mask($value);
    }
    if (in_array($key, $idKeys, true)) {
        return $value;
    }
    $value = preg_replace('/\d{8}\s*\p{Pd}\s*\d{4}/u', '********-****', $value) ?? $value;
    $value = preg_replace('/\d{6}\s*[\p{Pd}\s]\s*\d{4}/u', '******-****', $value) ?? $value;
    return preg_replace('/(?<!\d)\d{10,12}(?!\d)/', '**********', $value) ?? $value;
}

function jeab_fortnox_pnr_json(array $result): string
{
    unset($result['status']);
    $clean = jeab_fortnox_pnr_scrub($result);
    $json = json_encode(is_array($clean) ? $clean : ['ok' => false, 'error' => 'Fortnox-fel.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{"ok":false,"error":"Fortnox-fel."}';
}

function jeab_fortnox_pnr_dispatch(string $action, array $input): array
{
    return match ($action) {
        'fortnox.pnr_check' => jeab_fortnox_pnr_check($input),
        'fortnox.kund_hamta' => jeab_fortnox_kund_hamta($input),
        'fortnox.kund_jamfor_order' => jeab_fortnox_kund_jamfor_order($input),
        'fortnox.kund_ratta_orgnr' => jeab_fortnox_kund_ratta_orgnr($input),
        'fortnox.faktura_kopiera' => jeab_fortnox_faktura_kopiera($input),
        'fortnox.faktura_makulera_eller_kreditera' => jeab_fortnox_faktura_makulera_eller_kreditera($input),
        'fortnox.faktura_skapa_om' => jeab_fortnox_faktura_skapa_om($input),
        default => ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400],
    };
}

function jeab_fortnox_pnr_check(array $input): array
{
    unset($input);
    if (!jeab_fortnox_pnr_authorized('fortnox.pnr_check')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $numbers = [];
    try {
        foreach (jeab_fortnox_pnr_accounts() as $account) {
            $api = jeab_fortnox_pnr_client($account);
            $rows = $api->listAll('/customers', 'Customers', [], 0);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $org = jeab_fortnox_pnr_org_field($row);
                if ($org === '' || !jeab_idnr_has_bad_separator($org)) {
                    continue;
                }
                $number = jeab_fortnox_pnr_customer_number($row);
                if ($number !== '') {
                    $numbers[$number] = $number;
                }
            }
        }
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Kundlistan gick inte att hämta.', 'status' => 400];
    }
    $list = array_values($numbers);
    sort($list, SORT_STRING);
    return jeab_fortnox_pnr_scrub([
        'ok' => true,
        'count' => count($list),
        'customers' => $list,
    ]);
}

/** @return array{customer:array<string,mixed>,invoice:array<string,mixed>} */
function jeab_fortnox_kund_load(FortnoxAPI $api, string $customerNumber, string $invoiceNumber): array
{
    $invoice = [];
    if ($invoiceNumber !== '') {
        $invoice = jeab_fortnox_pnr_invoice_body($api->getInvoice($invoiceNumber));
        if ($invoice === [] || jeab_fortnox_pnr_document($invoice) === '') {
            throw new RuntimeException('saknas');
        }
        if ($customerNumber === '') {
            $customerNumber = jeab_fortnox_pnr_text($invoice, 'CustomerNumber');
        }
    }
    if ($customerNumber === '') {
        throw new RuntimeException('saknas');
    }
    $customer = $api->getCustomer($customerNumber);
    if ($customer === [] || jeab_fortnox_pnr_customer_number($customer) === '') {
        throw new RuntimeException('saknas');
    }
    return ['customer' => $customer, 'invoice' => $invoice];
}

function jeab_fortnox_kund_hamta(array $input): array
{
    if (!jeab_fortnox_pnr_authorized('fortnox.kund_hamta')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = jeab_fortnox_pnr_account($input);
    if ($account === '') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422];
    }
    $customerNumber = jeab_fortnox_pnr_id((string) ($input['customer_number'] ?? $input['customer'] ?? ''));
    $invoiceNumber = jeab_fortnox_pnr_id((string) ($input['invoice_number'] ?? $input['invoice'] ?? ''));
    if ($customerNumber === '' && $invoiceNumber === '') {
        return ['ok' => false, 'error' => 'Kundnummer eller fakturanummer saknas.', 'status' => 422];
    }
    try {
        $loaded = jeab_fortnox_kund_load(jeab_fortnox_pnr_client($account), $customerNumber, $invoiceNumber);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Kunden gick inte att läsa.', 'status' => 404];
    }
    $customer = $loaded['customer'];
    $invoice = $loaded['invoice'];
    $org = jeab_fortnox_pnr_org_field($customer);
    $out = [
        'ok' => true,
        'account' => $account,
        'customer' => [
            'name' => jeab_fortnox_pnr_text($customer, 'Name'),
            'customer_number' => jeab_fortnox_pnr_customer_number($customer),
            'address' => [
                'address1' => jeab_fortnox_pnr_text($customer, 'Address1'),
                'address2' => jeab_fortnox_pnr_text($customer, 'Address2'),
                'zip' => jeab_fortnox_pnr_text($customer, 'ZipCode'),
                'city' => jeab_fortnox_pnr_text($customer, 'City'),
            ],
        ],
        'invoice' => $invoice === [] ? null : [
            'number' => jeab_fortnox_pnr_document($invoice),
            'booked' => jeab_fortnox_pnr_flag($invoice['Booked'] ?? false),
            'cancelled' => jeab_fortnox_pnr_flag($invoice['Cancelled'] ?? false),
            'sent' => jeab_fortnox_pnr_flag($invoice['Sent'] ?? false),
        ],
        'invalid_chars' => $org !== '' && jeab_idnr_has_bad_separator($org),
        'orgnr' => jeab_fortnox_pnr_mask($org),
    ];
    return jeab_fortnox_pnr_scrub($out);
}

function jeab_fortnox_kund_jamfor_order(array $input): array
{
    if (!jeab_fortnox_pnr_authorized('fortnox.kund_jamfor_order')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = jeab_fortnox_pnr_account($input);
    if ($account === '') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422];
    }
    $invoiceNumber = jeab_fortnox_pnr_id((string) ($input['invoice_number'] ?? ''));
    $orderId = (int) ($input['order_id'] ?? 0);
    if ($invoiceNumber === '' || $orderId <= 0) {
        return ['ok' => false, 'error' => 'Faktura eller order saknas.', 'status' => 422];
    }
    $orderPnr = jeab_fortnox_pnr_order_pnr($orderId);
    if ($orderPnr === '' || jeab_fortnox_pnr_yymmdd($orderPnr) === '') {
        return ['ok' => false, 'error' => 'Ordern saknar personnummer.', 'status' => 404];
    }
    try {
        $loaded = jeab_fortnox_kund_load(jeab_fortnox_pnr_client($account), '', $invoiceNumber);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Kunden gick inte att läsa.', 'status' => 404];
    }
    return jeab_fortnox_pnr_scrub([
        'ok' => true,
        'account' => $account,
        'result' => jeab_fortnox_pnr_compare(jeab_fortnox_pnr_org_field($loaded['customer']), $orderPnr),
    ]);
}

/** @return array{open:list<string>,booked:list<string>,cancelled:list<string>} */
function jeab_fortnox_pnr_invoice_groups(FortnoxAPI $api, string $customerNumber): array
{
    $groups = ['open' => [], 'booked' => [], 'cancelled' => []];
    foreach ($api->listInvoicesForCustomer($customerNumber) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!array_key_exists('Booked', $row) && jeab_fortnox_pnr_document($row) !== '') {
            try {
                $row = jeab_fortnox_pnr_invoice_body($api->getInvoice(jeab_fortnox_pnr_document($row)));
            } catch (Throwable) {
                continue;
            }
        }
        $number = jeab_fortnox_pnr_document($row);
        if ($number === '') {
            continue;
        }
        if (jeab_fortnox_pnr_flag($row['Booked'] ?? false)) {
            $groups['booked'][] = $number;
        } elseif (jeab_fortnox_pnr_flag($row['Cancelled'] ?? false)) {
            $groups['cancelled'][] = $number;
        } else {
            $groups['open'][] = $number;
        }
    }
    foreach ($groups as $key => $list) {
        sort($list, SORT_STRING);
        $groups[$key] = $list;
    }
    return $groups;
}

/** @param array<string, mixed> $customer */
function jeab_fortnox_pnr_customer_details(array $customer, string $yymmdd, bool $includeOrg): array
{
    $fields = [
        'CustomerName' => jeab_fortnox_pnr_text($customer, 'Name'),
        'Address1' => jeab_fortnox_pnr_text($customer, 'Address1'),
        'Address2' => jeab_fortnox_pnr_text($customer, 'Address2'),
        'ZipCode' => jeab_fortnox_pnr_text($customer, 'ZipCode'),
        'City' => jeab_fortnox_pnr_text($customer, 'City'),
    ];
    if ($includeOrg && $yymmdd !== '') {
        $fields['OrganisationNumber'] = $yymmdd;
    }
    return $fields;
}

function jeab_fortnox_kund_ratta_orgnr(array $input): array
{
    if (!jeab_fortnox_pnr_authorized('fortnox.kund_ratta_orgnr')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = jeab_fortnox_pnr_account($input);
    if ($account === '') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422];
    }
    $customerNumber = jeab_fortnox_pnr_id((string) ($input['customer_number'] ?? $input['customer'] ?? ''));
    $orderId = (int) ($input['order_id'] ?? 0);
    if ($customerNumber === '' || $orderId <= 0) {
        return ['ok' => false, 'error' => 'Kundnummer eller order saknas.', 'status' => 422];
    }
    $yymmdd = jeab_fortnox_pnr_yymmdd(jeab_fortnox_pnr_order_pnr($orderId));
    if ($yymmdd === '') {
        return ['ok' => false, 'error' => 'Ordern saknar personnummer.', 'status' => 404];
    }
    $dry = jeab_fortnox_pnr_dry($input);
    try {
        $api = jeab_fortnox_pnr_client($account);
        $customer = $api->getCustomer($customerNumber);
        if ($customer === [] || jeab_fortnox_pnr_customer_number($customer) !== $customerNumber) {
            return ['ok' => false, 'error' => 'Kunden finns inte.', 'status' => 404];
        }
        $groups = jeab_fortnox_pnr_invoice_groups($api, $customerNumber);
        $current = jeab_fortnox_pnr_org_field($customer);
        $customerChanged = trim($current) !== $yymmdd;
        if (!$dry) {
            if ($customerChanged) {
                $api->updateCustomerOrganisationNumber($customerNumber, $yymmdd);
            }
            foreach ($groups['open'] as $invoiceNumber) {
                $invoice = jeab_fortnox_pnr_invoice_body($api->getInvoice($invoiceNumber));
                if (jeab_fortnox_pnr_flag($invoice['Booked'] ?? false) || jeab_fortnox_pnr_flag($invoice['Cancelled'] ?? false)) {
                    continue;
                }
                $api->updateInvoice($invoiceNumber, jeab_fortnox_pnr_customer_details(
                    $customer,
                    $yymmdd,
                    array_key_exists('OrganisationNumber', $invoice)
                ));
            }
        }
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Kunden gick inte att uppdatera.', 'status' => 400];
    }
    return jeab_fortnox_pnr_scrub([
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'customer' => $customerNumber,
        'orgnr' => jeab_fortnox_pnr_mask($yymmdd),
        'customer_changed' => $customerChanged,
        'invoices_open' => $groups['open'],
        'invoices_booked' => $groups['booked'],
        'invoices_cancelled' => $groups['cancelled'],
    ]);
}

/** @param array<string, mixed> $invoice @param array<string, mixed> $customer */
function jeab_fortnox_pnr_draft_from(array $invoice, array $customer): array
{
    $rows = [];
    foreach (is_array($invoice['InvoiceRows'] ?? null) ? $invoice['InvoiceRows'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $keep = [];
        foreach (['ArticleNumber', 'Description', 'DeliveredQuantity', 'Price', 'Discount', 'DiscountType', 'VAT', 'Unit', 'AccountNumber', 'HouseWork', 'HouseWorkType', 'HouseWorkHoursToReport'] as $key) {
            if (array_key_exists($key, $row) && !is_array($row[$key])) {
                $keep[$key] = $row[$key];
            }
        }
        if ($keep !== []) {
            $rows[] = $keep;
        }
    }
    $draft = [
        'CustomerNumber' => jeab_fortnox_pnr_customer_number($customer),
        'CustomerName' => jeab_fortnox_pnr_text($customer, 'Name'),
        'Address1' => jeab_fortnox_pnr_text($customer, 'Address1'),
        'Address2' => jeab_fortnox_pnr_text($customer, 'Address2'),
        'ZipCode' => jeab_fortnox_pnr_text($customer, 'ZipCode'),
        'City' => jeab_fortnox_pnr_text($customer, 'City'),
        'InvoiceRows' => $rows,
    ];
    unset($draft['Booked'], $draft['Sent'], $draft['Cancelled'], $draft['EmailInformation']);
    return $draft;
}

function jeab_fortnox_faktura_kopiera(array $input): array
{
    if (!jeab_fortnox_pnr_authorized('fortnox.faktura_kopiera')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = jeab_fortnox_pnr_account($input);
    if ($account === '') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422];
    }
    $invoiceNumber = jeab_fortnox_pnr_id((string) ($input['invoice_number'] ?? ''));
    if ($invoiceNumber === '') {
        return ['ok' => false, 'error' => 'Fakturanummer saknas.', 'status' => 422];
    }
    $dry = jeab_fortnox_pnr_dry($input);
    try {
        $api = jeab_fortnox_pnr_client($account);
        $loaded = jeab_fortnox_kund_load($api, '', $invoiceNumber);
        $customer = $loaded['customer'];
        $org = jeab_fortnox_pnr_org_field($customer);
        $yymmdd = jeab_fortnox_pnr_yymmdd($org);
        $fixCustomer = $yymmdd !== '' && !in_array(trim($org), jeab_fortnox_pnr_plain_forms($org), true);
        $newNumber = '';
        if (!$dry) {
            if ($fixCustomer) {
                $api->updateCustomerOrganisationNumber(jeab_fortnox_pnr_customer_number($customer), $yymmdd);
            }
            $created = $api->createDraftInvoice(jeab_fortnox_pnr_draft_from($loaded['invoice'], $customer));
            $newNumber = jeab_fortnox_pnr_document(jeab_fortnox_pnr_invoice_body($created));
            if ($newNumber === '') {
                return ['ok' => false, 'error' => 'Utkastet skapades inte.', 'status' => 400];
            }
        }
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Fakturan gick inte att kopiera.', 'status' => 400];
    }
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'invoice' => $invoiceNumber,
        'customer' => jeab_fortnox_pnr_customer_number($customer),
        'would_create' => true,
    ];
    if (!$dry) {
        $out['new_invoice'] = $newNumber;
    }
    return jeab_fortnox_pnr_scrub($out);
}

function jeab_fortnox_faktura_makulera_eller_kreditera(array $input): array
{
    if (!jeab_fortnox_pnr_authorized('fortnox.faktura_makulera_eller_kreditera')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = jeab_fortnox_pnr_account($input);
    if ($account === '') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422];
    }
    $invoiceNumber = jeab_fortnox_pnr_id((string) ($input['invoice_number'] ?? ''));
    $mode = trim((string) ($input['mode'] ?? ''));
    if ($invoiceNumber === '' || ($mode !== 'makulera' && $mode !== 'kreditera')) {
        return ['ok' => false, 'error' => 'Faktura eller läge saknas.', 'status' => 422];
    }
    $dry = jeab_fortnox_pnr_dry($input);
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'Bekräftelse saknas.', 'status' => 422];
    }
    $creditNumber = '';
    try {
        $api = jeab_fortnox_pnr_client($account);
        $invoice = jeab_fortnox_pnr_invoice_body($api->getInvoice($invoiceNumber));
        if ($invoice === [] || jeab_fortnox_pnr_document($invoice) !== $invoiceNumber) {
            return ['ok' => false, 'error' => 'Fakturan finns inte.', 'status' => 404];
        }
        if (!$dry) {
            if ($mode === 'makulera') {
                $api->cancelInvoice($invoiceNumber);
            } else {
                $credited = $api->creditInvoice($invoiceNumber);
                $creditNumber = jeab_fortnox_pnr_document(jeab_fortnox_pnr_invoice_body($credited));
            }
        }
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Fakturan gick inte att ändra.', 'status' => 400];
    }
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'invoice' => $invoiceNumber,
        'mode' => $mode,
    ];
    if (!$dry && $mode === 'kreditera' && $creditNumber !== '') {
        $out['credit_invoice'] = $creditNumber;
    }
    return jeab_fortnox_pnr_scrub($out);
}

/** Ägarens personnummer på ordern. Tomt om ordern saknar ett användbart nummer. */
function jeab_fortnox_pnr_owner_pnr(int $orderId): string
{
    if ($orderId <= 0 || !function_exists('jeab_purchase_get')) {
        return '';
    }
    $row = jeab_purchase_get($orderId);
    if (!is_array($row)) {
        return '';
    }
    foreach (FortnoxAPI::collectOwners($row) as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $pnr = trim((string) ($owner['personnummer'] ?? ''));
        if (jeab_fortnox_pnr_yymmdd($pnr) !== '') {
            return $pnr;
        }
    }
    return jeab_fortnox_pnr_order_pnr($orderId);
}

function jeab_fortnox_pnr_order_has_green_tech(array $row): bool
{
    $kind = function_exists('jeab_purchase_normalize_kind')
        ? (string) (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '')
        : trim((string) ($row['kind'] ?? ''));
    if (!in_array($kind, ['laddbox', 'batteri', 'sol'], true)) {
        return false;
    }
    $price = (int) ($row['pris'] ?? 0);
    if ($price <= 0) {
        $price = (int) ($row['kundpris'] ?? 0);
    }
    return $price > 0;
}

function jeab_fortnox_pnr_mark(string $invoiceNumber): string
{
    return 'JEAB-SKAPA-OM-' . $invoiceNumber;
}

/** @return array{mode:string,pending:bool} */
function jeab_fortnox_pnr_old_mode(array $invoice): array
{
    $booked = jeab_fortnox_pnr_flag($invoice['Booked'] ?? false);
    $cancelled = jeab_fortnox_pnr_flag($invoice['Cancelled'] ?? false);
    $credited = jeab_fortnox_pnr_text($invoice, 'CreditInvoiceReference') !== '';
    return [
        'mode' => ($booked || $credited) ? 'kreditera' : 'makulera',
        'pending' => !$cancelled && !$credited,
    ];
}

function jeab_fortnox_pnr_find_replacement(FortnoxAPI $api, string $customerNumber, string $invoiceNumber): string
{
    $mark = jeab_fortnox_pnr_mark($invoiceNumber);
    foreach ($api->listInvoicesForCustomer($customerNumber) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $number = jeab_fortnox_pnr_document($row);
        if ($number === '' || $number === $invoiceNumber) {
            continue;
        }
        if (array_key_exists('ExternalInvoiceReference1', $row)) {
            $ref = jeab_fortnox_pnr_text($row, 'ExternalInvoiceReference1');
            $cancelled = jeab_fortnox_pnr_flag($row['Cancelled'] ?? false);
        } else {
            try {
                $full = jeab_fortnox_pnr_invoice_body($api->getInvoice($number));
            } catch (Throwable) {
                continue;
            }
            $ref = jeab_fortnox_pnr_text($full, 'ExternalInvoiceReference1');
            $cancelled = jeab_fortnox_pnr_flag($full['Cancelled'] ?? false);
        }
        if ($ref === $mark && !$cancelled) {
            return $number;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $invoice
 * @param array<string, mixed> $customer
 * @param array<string, mixed>|null $orderRow
 */
function jeab_fortnox_pnr_replacement_draft(array $invoice, array $customer, string $mark, ?array $orderRow = null): array
{
    $draft = jeab_fortnox_pnr_draft_from($invoice, $customer);
    if (is_array($orderRow) && jeab_fortnox_pnr_order_has_green_tech($orderRow)) {
        if (!function_exists('jeab_ordrar_fortnox_payload')) {
            require_once __DIR__ . '/ordrar_fortnox.php';
        }
        $export = jeab_ordrar_fortnox_payload($orderRow);
        $plan = jeab_fortnox_pnr_client(jeab_ordrar_fortnox_account($orderRow['company'] ?? null))->kopExportPlan($export);
        $order = is_array($plan['order'] ?? null) ? $plan['order'] : [];
        $rows = is_array($order['OrderRows'] ?? null) ? $order['OrderRows'] : [];
        FortnoxAPI::assertGreenTechPayload($export, $rows, (int) ($plan['deduction_total'] ?? 0));
        $draft['InvoiceRows'] = $rows;
        $draft['TaxReductionType'] = 'green';
        if (trim((string) ($order['DeliveryDate'] ?? '')) !== '') {
            $draft['DeliveryDate'] = (string) $order['DeliveryDate'];
        }
        foreach (['Address1', 'Address2', 'ZipCode', 'City', 'DeliveryAddress1', 'DeliveryAddress2', 'DeliveryZipCode', 'DeliveryCity'] as $key) {
            if (trim((string) ($order[$key] ?? '')) !== '') {
                $draft[$key] = (string) $order[$key];
            }
        }
        $fxOrder = trim((string) ($orderRow['fortnox_order'] ?? ''));
        if ($fxOrder !== '') {
            $draft['OrderReference'] = $fxOrder;
        }
        $draft['_jeab_green'] = [
            'owners' => $plan['owners'] ?? [],
            'fastighet' => (string) ($plan['fastighet'] ?? ''),
            'deduction_total' => (int) ($plan['deduction_total'] ?? 0),
        ];
    }
    $draft['ExternalInvoiceReference1'] = $mark;
    unset(
        $draft['Booked'],
        $draft['Sent'],
        $draft['Cancelled'],
        $draft['EmailInformation'],
        $draft['PrintTemplate'],
        $draft['EDIInformation'],
        $draft['WayOfDelivery']
    );
    return $draft;
}

function jeab_fortnox_faktura_skapa_om(array $input): array
{
    if (!jeab_fortnox_pnr_authorized('fortnox.faktura_skapa_om')) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = jeab_fortnox_pnr_account($input);
    if ($account === '') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 422];
    }
    $invoiceNumber = jeab_fortnox_pnr_id((string) ($input['invoice_number'] ?? ''));
    $orderId = (int) ($input['order_id'] ?? 0);
    if ($invoiceNumber === '' || $orderId <= 0) {
        return ['ok' => false, 'error' => 'Faktura eller order saknas.', 'status' => 422];
    }
    $dry = jeab_fortnox_pnr_dry($input);
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'Bekräftelse saknas.', 'status' => 422];
    }
    $orderRow = function_exists('jeab_purchase_get') ? jeab_purchase_get($orderId) : null;
    if (is_array($orderRow)) {
        $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($orderRow['company'] ?? null) : null;
        $want = $account === 'Malmo' ? 'malmo' : 'jarfalla';
        if ($company !== $want) {
            return ['ok' => false, 'error' => 'Ordern hör till ett annat bolag.', 'status' => 422];
        }
    }
    $yymmdd = jeab_fortnox_pnr_yymmdd(jeab_fortnox_pnr_owner_pnr($orderId));
    if ($yymmdd === '') {
        return ['ok' => false, 'error' => 'Ordern saknar personnummer.', 'status' => 404];
    }
    $customerNumber = '';
    $diff = '';
    $mode = '';
    $newNumber = '';
    $already = false;
    $customerChanged = false;
    $pending = false;
    try {
        $api = jeab_fortnox_pnr_client($account);
        $loaded = jeab_fortnox_kund_load($api, '', $invoiceNumber);
        $customer = $loaded['customer'];
        $invoice = $loaded['invoice'];
        $customerNumber = jeab_fortnox_pnr_customer_number($customer);
        if ($customerNumber === '') {
            return ['ok' => false, 'error' => 'Kunden finns inte.', 'status' => 404];
        }
        $diff = jeab_fortnox_pnr_compare(jeab_fortnox_pnr_org_field($customer), $yymmdd);
        $old = jeab_fortnox_pnr_old_mode($invoice);
        $mode = $old['mode'];
        $pending = $old['pending'];
        $customerChanged = trim(jeab_fortnox_pnr_org_field($customer)) !== $yymmdd;
        $newNumber = jeab_fortnox_pnr_find_replacement($api, $customerNumber, $invoiceNumber);
        $already = $newNumber !== '';
        if (!$dry) {
            if ($customerChanged) {
                $api->updateCustomerOrganisationNumber($customerNumber, $yymmdd);
            }
            if (!$already) {
                if (!is_array($orderRow) && function_exists('jeab_purchase_get')) {
                    $orderRow = jeab_purchase_get($orderId);
                }
                $draft = jeab_fortnox_pnr_replacement_draft(
                    $invoice,
                    $customer,
                    jeab_fortnox_pnr_mark($invoiceNumber),
                    is_array($orderRow) ? $orderRow : null
                );
                $green = is_array($draft['_jeab_green'] ?? null) ? $draft['_jeab_green'] : null;
                unset($draft['_jeab_green']);
                $created = $api->createDraftInvoice($draft);
                $newNumber = jeab_fortnox_pnr_document(jeab_fortnox_pnr_invoice_body($created));
                if ($newNumber === '') {
                    return ['ok' => false, 'error' => 'Utkastet skapades inte.', 'status' => 400];
                }
                if (is_array($green) && (int) ($green['deduction_total'] ?? 0) > 0) {
                    $bases = FortnoxAPI::houseworkBases(is_array($draft['InvoiceRows'] ?? null) ? $draft['InvoiceRows'] : []);
                    $api->createTaxReductions(
                        is_array($green['owners'] ?? null) ? $green['owners'] : [],
                        (string) ($green['fastighet'] ?? ''),
                        $newNumber,
                        $bases,
                        null,
                        'INVOICE'
                    );
                }
            }
            if ($pending) {
                $fresh = jeab_fortnox_pnr_invoice_body($api->getInvoice($invoiceNumber));
                $old = jeab_fortnox_pnr_old_mode($fresh !== [] ? $fresh : $invoice);
                $mode = $old['mode'];
                if ($old['pending']) {
                    if ($mode === 'kreditera') {
                        $api->creditInvoice($invoiceNumber);
                    } else {
                        $api->cancelInvoice($invoiceNumber);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        $safe = jeab_fortnox_pnr_scrub($msg, 'detail');
        $safe = is_string($safe) ? trim($safe) : '';
        if ($safe === '') {
            $safe = 'Fakturan gick inte att skapa om.';
        }
        if (str_contains($msg, 'skattereduktion') || str_contains($msg, 'Fastighetsbeteckning') || str_contains($msg, 'Personnummer')) {
            return ['ok' => false, 'error' => $safe, 'status' => 422];
        }
        return ['ok' => false, 'error' => 'Fakturan gick inte att skapa om.', 'status' => 400];
    }
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'invoice' => $invoiceNumber,
        'customer' => $customerNumber,
        'order_id' => $orderId,
        'orgnr_diff' => $diff,
        'mode' => $mode,
        'already' => $already,
        'would_update_customer' => $customerChanged,
        'would_create' => !$already,
        'would_close' => $pending,
    ];
    if ($newNumber !== '') {
        $out['new_invoice'] = $newNumber;
    }
    return jeab_fortnox_pnr_scrub($out);
}
