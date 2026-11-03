<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/fortnox.php';

/**
 * fortnox.kund_fakturasummor: läser fakturor och kunder i ett bolag.
 * Bara GET via FortnoxAPI::listAll (/invoices och /customers). Ingen skrivning, ingen sändning.
 * Svaret är en vitlista. Personnummer lämnas kvar hos Fortnox och loggas inte.
 */

function jeab_fortnox_kund_fakturasummor(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = trim((string) ($input['account'] ?? ''));
    if ($account !== 'Jarfalla' && $account !== 'Malmo') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 400];
    }
    $basis = jeab_fortnox_kund_fakturasummor_basis($input);
    if ($basis === '') {
        return ['ok' => false, 'error' => 'Ogiltig basis.', 'status' => 400];
    }
    $maxTotal = jeab_fortnox_kund_fakturasummor_max($input);
    if ($maxTotal === null) {
        return ['ok' => false, 'error' => 'Ogiltigt tak.', 'status' => 400];
    }
    $from = jeab_fortnox_kund_fakturasummor_date($input, 'from_date');
    $to = jeab_fortnox_kund_fakturasummor_date($input, 'to_date');
    if ($from === null || $to === null || ($from !== '' && $to !== '' && $from > $to)) {
        return ['ok' => false, 'error' => 'Ogiltigt datum.', 'status' => 400];
    }
    try {
        $api = jeab_fortnox_kund_fakturasummor_client($account);
        $query = [];
        if ($from !== '') {
            $query['fromdate'] = $from;
        }
        if ($to !== '') {
            $query['todate'] = $to;
        }
        $invoices = $query === []
            ? $api->listAll('/invoices', 'Invoices')
            : $api->listAll('/invoices', 'Invoices', $query);
        $customers = $api->listAll('/customers', 'Customers');
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Fakturorna gick inte att hämta.', 'status' => 400];
    }
    if (!is_array($invoices) || !is_array($customers)) {
        return ['ok' => false, 'error' => 'Fakturorna gick inte att hämta.', 'status' => 400];
    }
    $built = jeab_fortnox_kund_fakturasummor_rows($invoices, $customers, $from, $to, $basis, $maxTotal);

    return [
        'ok' => true,
        'read_only' => 1,
        'account' => $account,
        'period' => $built['period'],
        'count' => count($built['rows']),
        'rows' => $built['rows'],
    ];
}

function jeab_fortnox_kund_fakturasummor_client(string $account): object
{
    $factory = $GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] ?? null;
    if ($factory !== null) {
        if (!is_callable($factory)) {
            throw new RuntimeException('Fortnox-klienten saknas.');
        }
        $api = $factory($account);
        if (!is_object($api) || !method_exists($api, 'listAll')) {
            throw new RuntimeException('Fortnox-klienten saknas.');
        }
        return $api;
    }
    return new FortnoxAPI($account);
}

/**
 * @param list<mixed> $invoices
 * @param list<mixed> $customers
 * @return array{period:array{first_invoice_date:string,last_invoice_date:string},rows:list<array<string,mixed>>}
 */
function jeab_fortnox_kund_fakturasummor_rows(array $invoices, array $customers, string $from, string $to, string $basis, float $maxTotal): array
{
    $byNumber = [];
    foreach ($customers as $customer) {
        if (!is_array($customer)) {
            continue;
        }
        $number = jeab_fortnox_kund_fakturasummor_text($customer, 'CustomerNumber');
        if ($number === '') {
            continue;
        }
        $byNumber[$number] = $customer;
    }
    $groups = [];
    $first = '';
    $last = '';
    foreach ($invoices as $invoice) {
        if (!is_array($invoice)) {
            continue;
        }
        if (jeab_fortnox_kund_fakturasummor_flag($invoice['Cancelled'] ?? false)) {
            continue;
        }
        $number = jeab_fortnox_kund_fakturasummor_text($invoice, 'CustomerNumber');
        if ($number === '') {
            continue;
        }
        $date = jeab_fortnox_kund_fakturasummor_ymd($invoice['InvoiceDate'] ?? '');
        if ($from !== '' && ($date === '' || $date < $from)) {
            continue;
        }
        if ($to !== '' && ($date === '' || $date > $to)) {
            continue;
        }
        $credit = jeab_fortnox_kund_fakturasummor_credit($invoice);
        $total = jeab_fortnox_kund_fakturasummor_amount($invoice, 'Total', $credit);
        $tax = jeab_fortnox_kund_fakturasummor_amount($invoice, 'TaxReduction', $credit);
        $toPay = jeab_fortnox_kund_fakturasummor_to_pay($invoice, $credit);
        if (!isset($groups[$number])) {
            $groups[$number] = [
                'invoice_count' => 0,
                'sum_total_inkl_moms' => 0.0,
                'sum_tax_reduction' => 0.0,
                'sum_to_pay' => 0.0,
                'senaste_fakturadatum' => '',
            ];
        }
        $groups[$number]['invoice_count']++;
        $groups[$number]['sum_total_inkl_moms'] = round($groups[$number]['sum_total_inkl_moms'] + $total, 2);
        $groups[$number]['sum_tax_reduction'] = round($groups[$number]['sum_tax_reduction'] + $tax, 2);
        $groups[$number]['sum_to_pay'] = round($groups[$number]['sum_to_pay'] + $toPay, 2);
        if ($date !== '' && $date > $groups[$number]['senaste_fakturadatum']) {
            $groups[$number]['senaste_fakturadatum'] = $date;
        }
        if ($date !== '' && ($first === '' || $date < $first)) {
            $first = $date;
        }
        if ($date !== '' && $date > $last) {
            $last = $date;
        }
    }
    $rows = [];
    foreach ($groups as $number => $group) {
        $value = $basis === 'total' ? $group['sum_total_inkl_moms'] : $group['sum_to_pay'];
        if ($value >= $maxTotal) {
            continue;
        }
        $customer = $byNumber[(string) $number] ?? [];
        $rows[] = [
            'customer_number' => (string) $number,
            'namn' => jeab_fortnox_kund_fakturasummor_public(jeab_fortnox_kund_fakturasummor_text($customer, 'Name')),
            'gata' => jeab_fortnox_kund_fakturasummor_public(jeab_fortnox_kund_fakturasummor_text($customer, 'Address1')),
            'postnr' => jeab_fortnox_kund_fakturasummor_public(jeab_fortnox_kund_fakturasummor_text($customer, 'ZipCode')),
            'ort' => jeab_fortnox_kund_fakturasummor_public(jeab_fortnox_kund_fakturasummor_text($customer, 'City')),
            'mejl' => jeab_fortnox_kund_fakturasummor_public(jeab_fortnox_kund_fakturasummor_text($customer, 'Email')),
            'mobil' => jeab_fortnox_kund_fakturasummor_public(jeab_fortnox_kund_fakturasummor_mobil($customer)),
            'invoice_count' => $group['invoice_count'],
            'sum_total_inkl_moms' => $group['sum_total_inkl_moms'],
            'sum_tax_reduction' => $group['sum_tax_reduction'],
            'sum_to_pay' => $group['sum_to_pay'],
            'senaste_fakturadatum' => $group['senaste_fakturadatum'],
        ];
    }
    usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['customer_number'], (string) $b['customer_number']));

    return [
        'period' => [
            'first_invoice_date' => $first,
            'last_invoice_date' => $last,
        ],
        'rows' => $rows,
    ];
}

function jeab_fortnox_kund_fakturasummor_basis(array $input): string
{
    if (!array_key_exists('basis', $input) || $input['basis'] === null || $input['basis'] === '') {
        return 'to_pay';
    }
    if (!is_string($input['basis'])) {
        return '';
    }
    $basis = trim($input['basis']);
    return $basis === 'total' || $basis === 'to_pay' ? $basis : '';
}

function jeab_fortnox_kund_fakturasummor_max(array $input): ?float
{
    if (!array_key_exists('max_total', $input) || $input['max_total'] === null || $input['max_total'] === '') {
        return 50000.0;
    }
    $raw = $input['max_total'];
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
    } elseif (!is_int($raw) && !is_float($raw)) {
        return null;
    }
    $max = (float) $raw;
    if (!is_finite($max) || $max < 0) {
        return null;
    }
    return $max;
}

function jeab_fortnox_kund_fakturasummor_date(array $input, string $key): ?string
{
    if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
        return '';
    }
    if (!is_string($input[$key]) && !is_int($input[$key])) {
        return null;
    }
    $raw = trim((string) $input[$key]);
    if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $raw, $match) !== 1) {
        return null;
    }
    if (!checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
        return null;
    }
    return $raw;
}

function jeab_fortnox_kund_fakturasummor_flag(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || (is_string($value) && strcasecmp($value, 'true') === 0);
}

function jeab_fortnox_kund_fakturasummor_credit(array $invoice): bool
{
    if (jeab_fortnox_kund_fakturasummor_flag($invoice['Credit'] ?? false)) {
        return true;
    }
    return strtoupper(trim((string) ($invoice['InvoiceType'] ?? ''))) === 'CREDITINVOICE';
}

function jeab_fortnox_kund_fakturasummor_amount(array $invoice, string $key, bool $credit): float
{
    $raw = $invoice[$key] ?? 0;
    if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric(trim($raw)))) {
        $raw = 0;
    }
    $amount = round((float) $raw, 2);
    if ($credit) {
        $amount = -abs($amount);
    }
    return $amount;
}

function jeab_fortnox_kund_fakturasummor_to_pay(array $invoice, bool $credit): float
{
    if (array_key_exists('TotalToPay', $invoice)) {
        return jeab_fortnox_kund_fakturasummor_amount($invoice, 'TotalToPay', $credit);
    }
    return jeab_fortnox_kund_fakturasummor_amount($invoice, 'Balance', $credit);
}

function jeab_fortnox_kund_fakturasummor_ymd(mixed $value): string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return '';
    }
    $text = trim((string) $value);
    if (preg_match('/\A(\d{4}-\d{2}-\d{2})/', $text, $match) !== 1) {
        return '';
    }
    return $match[1];
}

function jeab_fortnox_kund_fakturasummor_text(array $row, string $key): string
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

function jeab_fortnox_kund_fakturasummor_public(string $value): string
{
    $out = preg_replace('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', '', $value);
    $out = preg_replace('/[ \t]{2,}/', ' ', is_string($out) ? $out : '');
    return trim(is_string($out) ? $out : '');
}

function jeab_fortnox_kund_fakturasummor_mobile(string $phone): bool
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0046')) {
        $digits = '0' . substr($digits, 4);
    } elseif (str_starts_with($digits, '46') && strlen($digits) >= 11) {
        $digits = '0' . substr($digits, 2);
    }
    return preg_match('/\A07[02369]\d{7}\z/', $digits) === 1;
}

function jeab_fortnox_kund_fakturasummor_mobil(array $customer): string
{
    $phone2 = jeab_fortnox_kund_fakturasummor_text($customer, 'Phone2');
    if ($phone2 !== '' && jeab_fortnox_kund_fakturasummor_mobile($phone2)) {
        return $phone2;
    }
    return jeab_fortnox_kund_fakturasummor_text($customer, 'Phone1');
}
