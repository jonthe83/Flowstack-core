<?php

declare(strict_types=1);

/**
 * fortnox.invoice_compare läser fakturor och ordern utan personuppgifter.
 */

$tmp = sys_get_temp_dir() . '/jeab-fortnox-cmp-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
if (!mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL rot\n");
    exit(1);
}
if (!mkdir($tmp . '/fortnox', 0775, true) && !is_dir($tmp . '/fortnox')) {
    fwrite(STDERR, "FAIL fortnox\n");
    exit(1);
}
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-refresh'],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox_order_link.php';

function cmp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$name = 'Anna Andersson';
$street = 'Storgatan 1';
$pnr = '198001011234';
$mail = 'anna@example.com';
$reminder = jeab_create_reminder($store, $name, null, 'Order', null, [
    'customer' => $name,
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$order = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => $name,
    'email' => $mail,
    'personnummer' => $pnr,
    'address' => $street . ', 111 22 Teststad',
    'postnr' => '111 22',
    'ort' => 'Teststad',
    'huvudsokande' => $name,
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order' => '900',
    'fortnox_invoice' => '13593',
    'fortnox_customer' => '42',
    'pris' => 15000,
    'steps' => [],
]);
$orderId = (string) (int) $order['reminder_id'];

$calls = [];
$invoices = [
    '13593' => [
        'DocumentNumber' => '13593',
        'InvoiceType' => 'INVOICE',
        'Booked' => false,
        'Cancelled' => false,
        'CustomerNumber' => '42',
        'CustomerName' => $name,
        'Address1' => $street . ', 111 22 Teststad',
        'ZipCode' => '11122',
        'City' => 'Teststad',
        'DeliveryAddress1' => $street,
        'DeliveryZipCode' => '111 22',
        'DeliveryCity' => 'Teststad',
        'OrganisationNumber' => $pnr,
        'TaxReduction' => 5000,
        'TaxReductionType' => 'green',
        'BasisTaxReduction' => 20000,
        'TotalToPay' => 10000,
        'EmailInformation' => ['EmailAddressTo' => $mail],
        'OrderReference' => '900',
        'YourOrderNumber' => $orderId,
        'InvoiceDate' => '2026-10-01',
        'Total' => 15000,
        'TotalVAT' => 3750,
        'CreditInvoiceReference' => '0',
        'InvoiceRows' => [
            ['ArticleNumber' => 'LB1', 'Description' => 'Laddbox 7kW', 'DeliveredQuantity' => 1, 'Price' => 15000, 'AccountNumber' => 3001, 'HouseWork' => true, 'BasisTaxReduction' => 20000, 'CustomerName' => $name],
        ],
    ],
    '13594' => [
        'DocumentNumber' => '13594',
        'InvoiceType' => 'CREDITINVOICE',
        'Booked' => true,
        'Cancelled' => false,
        'CustomerNumber' => '42',
        'CustomerName' => $name,
        'Address1' => 'Annan gata 9',
        'OrderReference' => '900',
        'YourOrderNumber' => $name,
        'InvoiceDate' => '2026-10-02',
        'Total' => -15000,
        'TotalVAT' => -3750,
        'CreditInvoiceReference' => '13593',
        'InvoiceRows' => [
            ['ArticleNumber' => 'LB1', 'Description' => 'Laddbox ' . $pnr . ' ' . $mail, 'DeliveredQuantity' => '1', 'Price' => '-15000', 'HouseWork' => false, 'BasisTaxReduction' => 0],
        ],
    ],
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$calls, &$invoices): array {
    $calls[] = $method . ' ' . $path;
    if ($method !== 'GET') {
        throw new RuntimeException('compare får bara läsa');
    }
    $route = strtok($path, '?') ?: $path;
    if (preg_match('#^/invoices/(\d+)$#', $route, $match) === 1 && isset($invoices[$match[1]])) {
        return ['Invoice' => $invoices[$match[1]]];
    }
    throw new FortnoxApiException('Kunde inte hitta fakturan.', 404, 2000433);
};

$compared = jeab_fortnox_invoice_compare([
    'action' => 'fortnox.invoice_compare',
    'account' => 'JEAB',
    'invoices' => ['13593', '13594', '13590'],
    'order_id' => $orderId,
]);
$json = json_encode($compared, JSON_UNESCAPED_UNICODE);
cmp_assert(($compared['read_only'] ?? 0) === 1 && ($compared['ok'] ?? false) === true, 'compare är läsning');
cmp_assert(($compared['order']['fortnox_invoice'] ?? '') === '13593', 'ordern visar sitt fakturanummer');
cmp_assert(($compared['order']['customer_number'] ?? '') === '42', 'ordern visar kundnummer');
cmp_assert(($compared['order']['amount'] ?? '') === '15000' && ($compared['order']['product'] ?? '') === 'laddbox', 'ordern visar belopp och produkt');
cmp_assert(($compared['order']['address_hash'] ?? '') !== '' && !str_contains((string) ($compared['order']['address_hash'] ?? ''), $street), 'lokal adress är bara en hash');

$byNo = [];
foreach ((array) ($compared['invoices'] ?? []) as $row) {
    if (is_array($row)) {
        $byNo[(string) ($row['invoice'] ?? '')] = $row;
    }
}
$main = $byNo['13593'] ?? [];
cmp_assert(($main['InvoiceType'] ?? '') === 'INVOICE' && ($main['Booked'] ?? true) === false && ($main['Cancelled'] ?? true) === false, '13593 har typ och status');
cmp_assert(($main['CustomerNumber'] ?? '') === '42' && ($main['OrderReference'] ?? '') === '900' && ($main['YourOrderNumber'] ?? '') === $orderId, '13593 har referenser');
cmp_assert(($main['InvoiceDate'] ?? '') === '2026-10-01' && ($main['Total'] ?? '') === '15000' && ($main['TotalVAT'] ?? '') === '3750', '13593 har datum och belopp');
cmp_assert(($main['CreditInvoiceReference'] ?? 'x') === '', 'tom kreditreferens utelämnas som tom');
cmp_assert(($main['rows'][0]['ArticleNumber'] ?? '') === 'LB1' && ($main['rows'][0]['Description'] ?? '') === 'Laddbox 7kW' && ($main['rows'][0]['Price'] ?? '') === '15000', 'raderna har artikel, text, antal och pris');
cmp_assert(!isset($main['rows'][0]['AccountNumber']), 'kontonummer på raden följer inte med');
cmp_assert(($main['TaxReduction'] ?? '') === '5000' && ($main['TaxReductionType'] ?? '') === 'green' && ($main['BasisTaxReduction'] ?? '') === '20000' && ($main['TotalToPay'] ?? '') === '10000', 'fakturan visar grön teknik-belopp');
cmp_assert(($main['rows'][0]['HouseWork'] ?? false) === true && ($main['rows'][0]['BasisTaxReduction'] ?? '') === '20000', 'raden visar husarbete och underlag');
cmp_assert(!isset($main['rows'][0]['CustomerName']) && !isset($main['CustomerName']), 'sökandens namn följer inte med');
cmp_assert(($main['order_id'][0] ?? '') === $orderId, 'lokal order som pekar på fakturan följer med');
cmp_assert(($main['address_match'] ?? false) === true, 'samma gata blir address_match');
cmp_assert(($main['address_hash'] ?? '') !== '' && ($main['address_hash'] ?? '') === ($compared['order']['address_hash'] ?? ''), 'Address1 och DeliveryAddress1 hashas som gatudelen');

$credit = $byNo['13594'] ?? [];
cmp_assert(($credit['InvoiceType'] ?? '') === 'CREDITINVOICE' && ($credit['CreditInvoiceReference'] ?? '') === '13593', 'kreditfakturan pekar på debet');
cmp_assert(($credit['YourOrderNumber'] ?? 'x') === '', 'namn i YourOrderNumber följer inte med');
cmp_assert(($credit['address_match'] ?? true) === false, 'annan gata blir inte träff');
cmp_assert(!str_contains((string) ($credit['rows'][0]['Description'] ?? ''), $pnr) && !str_contains((string) ($credit['rows'][0]['Description'] ?? ''), $mail), 'personnummer och mejl tas ur radtexten');
cmp_assert(str_contains((string) ($credit['rows'][0]['Description'] ?? ''), 'Laddbox'), 'produkttexten är kvar');

$missing = $byNo['13590'] ?? [];
cmp_assert(($missing['ok'] ?? true) === false && ($missing['error_information']['code'] ?? 0) === 2000433, 'saknad faktura ger Fortnox-kod');

cmp_assert(is_string($json) && !str_contains($json, $name) && !str_contains($json, $street) && !str_contains($json, 'Annan gata') && !str_contains($json, 'Teststad') && !str_contains($json, '11122') && !str_contains($json, '111 22') && !str_contains($json, $pnr) && !str_contains($json, $mail), 'svaret har varken namn, adress, personnummer eller mejl');
cmp_assert($calls !== [] && array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')) === [], 'compare gör bara GET');

$onlyOrder = jeab_fortnox_invoice_compare(['account' => 'JEAB', 'order_id' => $orderId]);
cmp_assert(count((array) ($onlyOrder['invoices'] ?? [])) === 1 && (($onlyOrder['invoices'][0]['invoice'] ?? '') === '13593'), 'order_id hämtar orderns faktura');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$gate = strpos($hook, 'jeab_fortnox_lon_hook_authorized');
$at = strpos($hook, "'fortnox.invoice_compare'");
cmp_assert($gate !== false && $at !== false && $at > $gate, 'hooken ligger bakom fortnox.*');

echo "Alla invoice-compare-tester OK\n";
