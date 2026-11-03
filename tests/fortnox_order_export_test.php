<?php

declare(strict_types=1);

/**
 * fortnox.order_export använder samma grön teknik-export som Ordrar.
 * Bara syntetiska uppgifter.
 */

$tmp = sys_get_temp_dir() . '/jeab-fortnox-export-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/ordrar.php';
require_once dirname(__DIR__) . '/src/fortnox_order_link.php';
require_once dirname(__DIR__) . '/src/fortnox_pnr.php';

function ex_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

// Exporten läser bokningen via jeab_store(). JSON-filen syns inte där.
$store = jeab_store();
$reminder = jeab_create_reminder($store, 'Ada Person', null, 'Order', null, [
    'customer' => 'Ada Person',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$booking = jeab_create_booking(
    $store,
    'Ada Person',
    ['Elin'],
    '2026-11-02 09:00:00',
    '2026-11-02 10:00:00',
    'laddbox',
    ['company' => 'jarfalla']
);
$order = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Person',
    'huvudsokande' => 'Ada Person',
    'personnummer' => '199001011234',
    'email' => 'ada@kund.se',
    'phone' => '0701234567',
    'address' => 'Testgatan 1',
    'postnr' => '11122',
    'postnummer' => '11122',
    'ort' => 'Teststad',
    'fastighetsbeteckning' => 'TEST 1:1',
    'booking_id' => (int) $booking['id'],
    'fortnox_order' => '4242',
    'fortnox_customer' => 'K9',
    'pris' => 80000,
    'kundpris' => 80000,
    'steps' => [],
]);
$orderId = (string) (int) $order['reminder_id'];

$calls = [];
$fxOrder = [
    'DocumentNumber' => '4242',
    'CustomerNumber' => 'K9',
    'InvoiceReference' => '',
    'Comments' => '',
    'TaxReductionType' => '',
    'OrderRows' => [[
        'Description' => 'Laddbox',
        'DeliveredQuantity' => 1,
        'Price' => 80000,
        'HouseWork' => true,
        'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
    ]],
];
$taxes = [];
$nextTax = 1;
$nextOrder = 4300;
$orders = ['4242' => $fxOrder];
$invoices = [
    '13605' => [
        'DocumentNumber' => '13605',
        'Booked' => true,
        'Cancelled' => false,
        'CreditInvoiceReference' => '0',
        'InvoiceRows' => [[
            'ArticleNumber' => '1',
            'Description' => 'Laddbox',
            'DeliveredQuantity' => 1,
            'Price' => 80000,
            'HouseWork' => true,
            'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        ]],
    ],
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$calls, &$invoices, &$fxOrder, &$taxes, &$nextTax, &$orders, &$nextOrder): array {
    $query = [];
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        parse_str(substr($path, $qpos + 1), $query);
    }
    $route = strtok($path, '?') ?: $path;
    $calls[] = $method . ' ' . $route;
    if ($method === 'GET' && preg_match('#^/invoices/(\d+)$#', $route, $match) === 1) {
        if (!isset($invoices[$match[1]])) {
            throw new FortnoxApiException('Kunde inte hitta fakturan.', 404, 2000433);
        }
        return ['Invoice' => $invoices[$match[1]]];
    }
    if ($method === 'PUT' && preg_match('#^/invoices/(\d+)$#', $route, $match) === 1 && isset($invoices[$match[1]]) && is_array($body['Invoice'] ?? null)) {
        $invoices[$match[1]] = array_merge($invoices[$match[1]], $body['Invoice']);
        return ['Invoice' => $invoices[$match[1]]];
    }
    if ($method === 'GET' && str_starts_with($route, '/customers')) {
        return ['Customers' => [[
            'CustomerNumber' => 'K9',
            'Name' => 'Ada Person',
            'Active' => true,
        ]], 'Customer' => [
            'CustomerNumber' => 'K9',
            'Name' => 'Ada Person',
            'Active' => true,
        ], 'MetaInformation' => ['@TotalPages' => 1]];
    }
    if ($method === 'PUT' && str_starts_with($route, '/customers')) {
        return ['Customer' => ['CustomerNumber' => 'K9', 'Active' => true]];
    }
    if ($method === 'POST' && $route === '/orders' && is_array($body['Order'] ?? null)) {
        $created = $body['Order'];
        $created['DocumentNumber'] = (string) $nextOrder;
        $nextOrder++;
        $created['InvoiceReference'] = '';
        $created['Cancelled'] = false;
        $created['WarehouseReady'] = false;
        $fxOrder = $created;
        $orders[$created['DocumentNumber']] = $created;
        return ['Order' => $created];
    }
    if (preg_match('#^/orders/(\d+)$#', $route, $match) === 1) {
        $doc = $match[1];
        if (!isset($orders[$doc]) && $doc === '4242') {
            $orders[$doc] = $fxOrder;
        }
        if (!isset($orders[$doc])) {
            throw new FortnoxApiException('Kunde inte hitta ordern.', 404, 2000433);
        }
        if ($method === 'PUT' && is_array($body['Order'] ?? null)) {
            $incoming = $body['Order'];
            if (isset($incoming['OrderRows']) && is_array($incoming['OrderRows'])) {
                $orders[$doc]['OrderRows'] = $incoming['OrderRows'];
            }
            foreach ($incoming as $key => $value) {
                if ($key === 'OrderRows' || is_array($value)) {
                    continue;
                }
                $orders[$doc][$key] = $value;
            }
            if ($doc === '4242') {
                $fxOrder = $orders[$doc];
            }
        }
        return ['Order' => $orders[$doc]];
    }
    if ($method === 'PUT' && preg_match('#^/orders/(\d+)/createinvoice$#', $route, $match) === 1) {
        $doc = $match[1];
        $invoiceNo = $doc === '4242' ? '13610' : '13611';
        $rows = is_array($orders[$doc]['OrderRows'] ?? null) ? $orders[$doc]['OrderRows'] : [];
        if (isset($orders[$doc])) {
            $orders[$doc]['InvoiceReference'] = $invoiceNo;
        }
        $invoices[$invoiceNo] = [
            'DocumentNumber' => $invoiceNo,
            'Booked' => false,
            'Cancelled' => false,
            'CreditInvoiceReference' => '0',
            'OrderReference' => $doc,
            'InvoiceRows' => $rows,
        ];
        return ['Invoice' => $invoices[$invoiceNo]];
    }
    if (preg_match('#^/taxreductions/([^/]+)$#', $route, $match) === 1) {
        $id = rawurldecode($match[1]);
        if ($method === 'DELETE') {
            unset($taxes[$id]);
            return [];
        }
        if ($method === 'PUT' && is_array($body['TaxReduction'] ?? null) && isset($taxes[$id])) {
            $taxes[$id] = array_merge($taxes[$id], $body['TaxReduction']);
            return ['TaxReduction' => $taxes[$id]];
        }
        if (isset($taxes[$id])) {
            return ['TaxReduction' => $taxes[$id]];
        }
        return ['TaxReductions' => [], 'MetaInformation' => ['@TotalPages' => 1]];
    }
    if ($route === '/taxreductions') {
        if ($method === 'POST' && is_array($body['TaxReduction'] ?? null)) {
            $id = (string) $nextTax;
            $nextTax++;
            $taxes[$id] = ['Id' => $id] + $body['TaxReduction'];
            return ['TaxReduction' => $taxes[$id]];
        }
        $filter = (string) ($query['filter'] ?? 'orders');
        $wanted = $filter === 'invoices' ? 'INVOICE' : 'ORDER';
        $list = [];
        foreach ($taxes as $tax) {
            $type = strtoupper((string) ($tax['ReferenceDocumentType'] ?? 'ORDER'));
            if ($type === $wanted) {
                $list[] = $tax;
            }
        }
        return ['TaxReductions' => array_values($list), 'MetaInformation' => ['@TotalPages' => 1]];
    }
    return [];
};

$dry = jeab_fortnox_order_export([
    'action' => 'fortnox.order_export',
    'account' => 'JEAB',
    'order_id' => $orderId,
]);
ex_assert(($dry['dry'] ?? null) === 1 && ($dry['ok'] ?? false) === true, 'export är dry som standard');
$payload = is_array($dry['payload'] ?? null) ? $dry['payload'] : [];
$rows = is_array($payload['OrderRows'] ?? null) ? $payload['OrderRows'] : [];
$house = false;
foreach ($rows as $line) {
    if (is_array($line) && !empty($line['HouseWork']) && ($line['HouseWorkType'] ?? '') === 'CHARGINGSTATIONELECTRICVEHICLE') {
        $house = true;
    }
}
ex_assert($house && ($payload['TaxReductionType'] ?? '') === 'green' && ($dry['green_tech'] ?? false) === true, 'payloaden har grön teknik och husarbete');
ex_assert(($payload['Comments'] ?? '') === 'TEST 1:1' && ($dry['delivery_date'] ?? '') === '2026-11-02', 'fastighet och installationsdag följer med');
ex_assert((int) ($dry['housework'][0]['applicant'] ?? 0) === 1 && (int) ($dry['housework'][0]['amounts'][0]['amount'] ?? 0) > 0, 'husarbete är per sökande');
ex_assert(($dry['clear'] ?? null) === [], 'dry utan force_new rensar inga kopplingar');
ex_assert((int) ($dry['total'] ?? 0) > 0 && (int) ($dry['deduction_total'] ?? 0) > 0, 'total och avdrag är räknade');
ex_assert((int) ($dry['amount_to_pay'] ?? -1) === (int) $dry['total'] - (int) $dry['deduction_total'], 'att betala är total minus avdrag');
ex_assert((int) ($dry['deduction'][0]['amount'] ?? 0) === (int) $dry['deduction_total'] && (int) ($dry['deduction'][0]['applicant'] ?? 0) === 1, 'avdraget är per sökande');
$writes = array_values(array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')));
ex_assert($writes === [], 'dry skriver inte, fick ' . implode(',', $writes));

$linked = jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_invoice'] = '13605';
    return $fresh;
});
ex_assert(is_array($linked), 'fakturan kan pekas ut');
$blocked = jeab_fortnox_order_export(['account' => 'JEAB', 'order_id' => $orderId, 'dry' => 0, 'confirm' => 'SAM']);
ex_assert(($blocked['ok'] ?? true) === false && str_contains((string) ($blocked['error'] ?? ''), 'varken är makulerad'), 'okrediterad faktura stoppar exporten');
$forcedDry = jeab_fortnox_order_export(['account' => 'JEAB', 'order_id' => $orderId, 'force_new' => 1]);
ex_assert(($forcedDry['ok'] ?? true) === false && ($forcedDry['clear'] ?? null) === null, 'force_new rensar inte en faktura som är kvar');
$threw = false;
try {
    jeab_fortnox_order_export(['account' => 'JEAB', 'order_id' => $orderId, 'dry' => 0]);
} catch (InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'Bekräftelse');
}
ex_assert($threw, 'dry=0 utan bekräftelse stoppar');

jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_invoice'] = '';
    return $fresh;
});
$calls = [];
$written = jeab_fortnox_order_export([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'dry' => 0,
    'confirm' => 'SAM',
]);
ex_assert(($written['ok'] ?? false) === true && ($written['invoice'] ?? '') === '13610', 'dry=0 lämnar fakturanumret, fick ' . json_encode($written));
ex_assert((int) ($written['deduction_total'] ?? 0) > 0 && !isset($written['payload']), 'skrivsvaret har avdrag och inte hela underlaget');
$after = jeab_purchase_get((int) $orderId);
ex_assert(is_array($after) && (string) ($after['fortnox_invoice'] ?? '') === '13610', 'ordern pekar på den nya fakturan');
$put = false;
foreach ($calls as $call) {
    if ($call === 'PUT /orders/4242') {
        $put = true;
    }
}
ex_assert($put && in_array('PUT /orders/4242/createinvoice', $calls, true), 'exporten uppdaterar ordern och skapar fakturan');
$designationPosted = false;
foreach ($taxes as $tax) {
    if (($tax['PropertyDesignation'] ?? '') === 'TEST 1:1' && ($tax['ReferenceDocumentType'] ?? '') === 'ORDER') {
        $designationPosted = true;
    }
}
ex_assert($designationPosted, 'ansökan skrivs med fastighetsbeteckningen');

$bare = jeab_fortnox_pnr_draft_from([
    'InvoiceRows' => [[
        'ArticleNumber' => '1',
        'Description' => 'Laddbox',
        'DeliveredQuantity' => 1,
        'Price' => 100,
        'HouseWork' => true,
        'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        'HouseWorkHoursToReport' => 1,
        'CustomerName' => 'Ada Person',
    ]],
], ['CustomerNumber' => 'K9', 'Name' => 'Ada Person']);
$kept = is_array($bare['InvoiceRows'][0] ?? null) ? $bare['InvoiceRows'][0] : [];
ex_assert(($kept['HouseWorkType'] ?? '') === 'CHARGINGSTATIONELECTRICVEHICLE' && !isset($kept['CustomerName']), 'kopian behåller husarbete och tappar inte bara raden');

try {
    FortnoxAPI::assertGreenTechPayload(['kind' => 'laddbox', 'price' => 80000, 'owners' => [['name' => 'A', 'personnummer' => '1']]], [
        ['Description' => 'Utan avdrag', 'Price' => 80000, 'DeliveredQuantity' => 1],
    ]);
    ex_assert(false, 'saknat avdrag skulle ha stoppats');
} catch (InvalidArgumentException $e) {
    ex_assert(str_contains($e->getMessage(), 'skattereduktion'), 'spärren nämner skattereduktion');
}

$rebuilt = jeab_fortnox_pnr_replacement_draft(
    ['InvoiceRows' => [['Description' => 'Utan avdrag', 'Price' => 1, 'DeliveredQuantity' => 1]]],
    ['CustomerNumber' => 'K9', 'Name' => 'Ada Person', 'Address1' => '', 'Address2' => '', 'ZipCode' => '', 'City' => ''],
    'JEAB-SKAPA-OM-1',
    jeab_purchase_get((int) $orderId)
);
$rebuiltRows = is_array($rebuilt['InvoiceRows'] ?? null) ? $rebuilt['InvoiceRows'] : [];
$rebuiltHouse = false;
foreach ($rebuiltRows as $line) {
    if (is_array($line) && !empty($line['HouseWork'])) {
        $rebuiltHouse = true;
    }
}
ex_assert($rebuiltHouse && ($rebuilt['TaxReductionType'] ?? '') === 'green' && ($rebuilt['OrderReference'] ?? '') === '4242', 'återskapningen tar grön teknik från ordern');
ex_assert(($rebuilt['DeliveryDate'] ?? '') === '2026-11-02', 'återskapningen tar installationsdagen');
$green = is_array($rebuilt['_jeab_green'] ?? null) ? $rebuilt['_jeab_green'] : [];
(new FortnoxAPI('Jarfalla'))->createTaxReductions(
    is_array($green['owners'] ?? null) ? $green['owners'] : [],
    (string) ($green['fastighet'] ?? ''),
    '13606',
    FortnoxAPI::houseworkBases($rebuiltRows),
    null,
    'INVOICE'
);
$invoiceTax = false;
foreach ($taxes as $tax) {
    if (($tax['ReferenceNumber'] ?? '') === '13606' && ($tax['ReferenceDocumentType'] ?? '') === 'INVOICE' && ($tax['PropertyDesignation'] ?? '') === 'TEST 1:1') {
        $invoiceTax = true;
    }
}
ex_assert($invoiceTax, 'återskapningen postar skattereduktion på fakturan');

jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_order'] = '4242';
    $fresh['fortnox_invoice'] = '13605';
    return $fresh;
});
$invoices['13605']['Cancelled'] = true;
$beforeClear = jeab_purchase_get((int) $orderId);
$calls = [];
$resetDry = jeab_fortnox_order_export([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'force_new' => 1,
]);
$still = jeab_purchase_get((int) $orderId);
ex_assert(($resetDry['ok'] ?? false) === true && ($resetDry['dry'] ?? 0) === 1, 'force_new är dry utan bekräftelse');
ex_assert(($resetDry['clear']['fortnox_order'] ?? '') === '4242' && ($resetDry['clear']['fortnox_invoice'] ?? '') === '13605', 'dry visar vilka kopplingar som skulle rensas');
ex_assert(($resetDry['green_tech'] ?? false) === true && ($resetDry['delivery_date'] ?? '') === '2026-11-02', 'dry visar grön teknik och installationsdag');
ex_assert((int) ($resetDry['housework'][0]['applicant'] ?? 0) === 1 && (int) ($resetDry['total'] ?? 0) > 0, 'dry visar total och husarbete per sökande');
$resetWrites = array_values(array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')));
ex_assert($resetWrites === [] && is_array($still) && (string) ($still['fortnox_order'] ?? '') === (string) ($beforeClear['fortnox_order'] ?? '') && (string) ($still['fortnox_invoice'] ?? '') === '13605', 'dry rensar ingenting');
$threwForce = false;
try {
    jeab_fortnox_order_export(['account' => 'JEAB', 'order_id' => $orderId, 'dry' => 0, 'force_new' => 1]);
} catch (InvalidArgumentException $e) {
    $threwForce = str_contains($e->getMessage(), 'Bekräftelse');
}
ex_assert($threwForce, 'force_new dry=0 utan bekräftelse stoppar');
$calls = [];
$precheckCalls = 0;
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static function (array $probe) use (&$precheckCalls): array {
    $precheckCalls++;
    return [[
        'order' => '4242',
        'customer' => 'K9',
        'match' => 'customer',
        'invoice_reference' => '13605',
    ]];
};
$reset = jeab_fortnox_order_export([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'dry' => 0,
    'force_new' => 1,
    'confirm' => 'SAM',
]);
ex_assert(($reset['ok'] ?? false) === true && ($reset['invoice'] ?? '') === '13611', 'force_new skapar en ny faktura, fick ' . json_encode($reset));
ex_assert($precheckCalls === 0, 'export efter release hoppar över förkollen, anrop ' . $precheckCalls);
$resetRow = jeab_purchase_get((int) $orderId);
ex_assert(is_array($resetRow) && (string) ($resetRow['fortnox_order'] ?? '') === '4300' && (string) ($resetRow['fortnox_invoice'] ?? '') === '13611', 'den gamla Fortnox-ordern byts mot en ny');
$created = is_array($orders['4300'] ?? null) ? $orders['4300'] : [];
$createdHouse = false;
foreach (is_array($created['OrderRows'] ?? null) ? $created['OrderRows'] : [] as $line) {
    if (is_array($line) && !empty($line['HouseWork'])) {
        $createdHouse = true;
    }
}
ex_assert(($created['TaxReductionType'] ?? '') === 'green' && $createdHouse, 'nya FO:n har grön teknik och husarbete');
ex_assert(($created['Address1'] ?? '') === 'Testgatan 1' && ($created['DeliveryAddress1'] ?? '') === 'Testgatan 1', 'nya FO:n har gatan, fick ' . (string) ($created['Address1'] ?? ''));
ex_assert(!str_contains((string) ($created['Address1'] ?? ''), '11122') && !str_contains((string) ($created['DeliveryAddress1'] ?? ''), 'Teststad'), 'postnummer och ort ligger inte i gatan');
jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_order'] = '';
    return $fresh;
});
$releasedHit = jeab_webshop_precheck(jeab_purchase_get((int) $orderId) ?? []);
$afterReleased = jeab_purchase_get((int) $orderId);
ex_assert($releasedHit === null && is_array($afterReleased) && (string) ($afterReleased['fortnox_order'] ?? '') === '', 'förkollen återanvänder inte FO med makulerad faktura');
$invoices['13605']['Cancelled'] = false;
$invoices['13605']['CreditInvoiceReference'] = '9';
$creditedHit = jeab_webshop_precheck(jeab_purchase_get((int) $orderId) ?? []);
$afterCredit = jeab_purchase_get((int) $orderId);
ex_assert($creditedHit === null && is_array($afterCredit) && (string) ($afterCredit['fortnox_order'] ?? '') === '', 'förkollen återanvänder inte FO med krediterad faktura');
$invoices['13605']['CreditInvoiceReference'] = '0';
$invoices['13605']['Cancelled'] = true;
$invoices['13620'] = [
    'DocumentNumber' => '13620',
    'Cancelled' => false,
    'CreditInvoiceReference' => '0',
    'Booked' => false,
];
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static function (array $probe): array {
    return [[
        'order' => '7777',
        'customer' => 'K9',
        'match' => 'customer',
        'invoice_reference' => '13620',
    ]];
};
$liveHit = jeab_webshop_precheck(jeab_purchase_get((int) $orderId) ?? []);
ex_assert(is_array($liveHit) && (string) ($liveHit['fortnox_order'] ?? '') === '7777', 'förkollen länkar FO med levande faktura');
unset($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND']);
jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_order'] = '4300';
    $fresh['fortnox_invoice'] = '13611';
    return $fresh;
});
$postedNew = in_array('POST /orders', $calls, true);
ex_assert($postedNew && in_array('PUT /orders/4300/createinvoice', $calls, true), 'ny order skapas i stället för att den låsta uppdateras');
$audit = '';
foreach (is_array($resetRow['activity'] ?? null) ? $resetRow['activity'] : [] as $entry) {
    if (is_array($entry)) {
        $audit .= ' ' . (string) ($entry['text'] ?? '');
    }
}
ex_assert(str_contains($audit, '4242') && str_contains($audit, '13605') && !str_contains($audit, '199001011234'), 'rensningen ligger i loggen utan personnummer');
ex_assert(jeab_fortnox_order_export_released(['Cancelled' => false, 'CreditInvoiceReference' => '9']) === true, 'krediterad faktura får rensas');
ex_assert(jeab_fortnox_order_export_released(['Cancelled' => false, 'CreditInvoiceReference' => '0']) === false, 'en levande faktura får inte rensas');
ex_assert(jeab_ordrar_fortnox_sv('Det går inte att uppdatera ett makulerat dokument.') === 'Fortnox-ordern är makulerad, en ny skapas.', 'råtext blir svenska');
ex_assert(jeab_ordrar_fortnox_order_lock_reason(['Cancelled' => true]) === 'cancelled', 'makulerad order är låst');
ex_assert(jeab_ordrar_fortnox_order_lock_reason(['WarehouseReady' => true]) === 'warehouse_ready', 'klarmarkerad order är låst');
ex_assert(jeab_ordrar_fortnox_order_lock_reason(['InvoiceReference' => '88']) === 'invoice_reference', 'order med fakturareferens är låst');
ex_assert(jeab_ordrar_fortnox_order_lock_reason(['Cancelled' => false, 'InvoiceReference' => '']) === '', 'öppen order är inte låst');

$auditPdo = new PDO('sqlite::memory:');
$auditPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_FORTNOX_ORDER_AUDIT_PDO'] = $auditPdo;
jeab_ensure_fortnox_order_audit($auditPdo, 'sqlite');
jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_order'] = '4242';
    $fresh['fortnox_invoice'] = '';
    return $fresh;
});
$orders['4242']['Cancelled'] = true;
$orders['4242']['WarehouseReady'] = false;
$orders['4242']['InvoiceReference'] = '';
$calls = [];
$lockedDry = jeab_fortnox_order_export([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'force_new' => 1,
]);
$untouched = jeab_purchase_get((int) $orderId);
$lockedWrites = array_values(array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')));
ex_assert(($lockedDry['ok'] ?? false) === true && ($lockedDry['clear']['fortnox_order'] ?? '') === '4242' && ($lockedDry['clear']['reason'] ?? '') === 'cancelled' && !isset($lockedDry['clear']['fortnox_invoice']), 'dry visar den makulerade Fortnox-ordern, fick ' . json_encode($lockedDry['clear'] ?? null));
ex_assert($lockedWrites === [] && is_array($untouched) && (string) ($untouched['fortnox_order'] ?? '') === '4242' && (string) ($untouched['fortnox_invoice'] ?? '') === '', 'dry rensar inte den makulerade ordern');
$calls = [];
$keptCancelled = jeab_ordrar_create_fortnox(['id' => (int) $orderId]);
$autoPosts = array_values(array_filter($calls, static fn (string $call): bool => $call === 'POST /orders'));
ex_assert((string) ($keptCancelled['fortnox_order'] ?? '') === '4242' && $autoPosts === [], 'makulerad order släpps inte automatiskt, fick ' . (string) ($keptCancelled['fortnox_order'] ?? '') . ' ' . implode(',', $autoPosts));
$autoAudit = (int) $auditPdo->query('SELECT COUNT(*) FROM fortnox_order_audit')->fetchColumn();
ex_assert($autoAudit === 0, 'automatisk create skriver ingen audit, fick ' . $autoAudit);
$releasedCancelled = jeab_ordrar_fortnox_release_cancelled(jeab_purchase_get((int) $orderId) ?? []);
ex_assert(trim((string) ($releasedCancelled['fortnox_order'] ?? '')) === '', 'uttrycklig makulering släpper Fortnox-ordern');
$calls = [];
$replaced = jeab_ordrar_create_fortnox(['id' => (int) $orderId]);
ex_assert((string) ($replaced['fortnox_order'] ?? '') === '4301' && (string) ($replaced['fortnox_invoice'] ?? '') === '', 'ny Fortnox-order skapas utan faktura, fick ' . json_encode(['order' => $replaced['fortnox_order'] ?? '', 'invoice' => $replaced['fortnox_invoice'] ?? '']));
ex_assert(!in_array('PUT /orders/4301/createinvoice', $calls, true), 'ersättningen skapar ingen faktura');
$lockedLog = '';
foreach (is_array($replaced['activity'] ?? null) ? $replaced['activity'] : [] as $entry) {
    if (is_array($entry)) {
        $lockedLog .= ' ' . (string) ($entry['text'] ?? '');
    }
}
ex_assert(str_contains($lockedLog, 'Fortnox-ordern 4242 är makulerad, en ny skapas.') && !str_contains($lockedLog, '199001011234'), 'orderloggen säger att ordern är makulerad');
$auditRow = $auditPdo->query('SELECT reminder_id, fortnox_order, reason, message FROM fortnox_order_audit')->fetch(PDO::FETCH_ASSOC);
ex_assert(is_array($auditRow) && (string) $auditRow['fortnox_order'] === '4242' && (string) $auditRow['reason'] === 'cancelled' && !str_contains((string) $auditRow['message'], '199001011234'), 'SQL-auditen har orsaken utan personnummer');

jeab_purchase_patch((int) $orderId, static function (array $fresh): array {
    $fresh['fortnox_order'] = '4244';
    $fresh['fortnox_invoice'] = '';
    return $fresh;
});
$orders['4244'] = [
    'DocumentNumber' => '4244',
    'CustomerNumber' => 'K9',
    'Cancelled' => false,
    'WarehouseReady' => true,
    'InvoiceReference' => '',
    'OrderRows' => [],
];
$calls = [];
$savedLines = jeab_ordrar_save_fakturering(['id' => (int) $orderId]);
$invoiceCalls = array_values(array_filter($calls, static fn (string $call): bool => str_contains($call, 'createinvoice')));
ex_assert($invoiceCalls === [] && (string) ($savedLines['fortnox_invoice'] ?? '') === '', 'Fakturering utan faktura-knapp skapar ingen faktura, fick ' . implode(',', $invoiceCalls));
ex_assert((string) ($savedLines['fortnox_order'] ?? '') === '4244', 'Spara behåller den låsta Fortnox-ordern, fick ' . (string) ($savedLines['fortnox_order'] ?? ''));
$orderPosts = array_values(array_filter($calls, static fn (string $call): bool => $call === 'POST /orders'));
ex_assert($orderPosts === [], 'sparningen skapar ingen ny Fortnox-order, fick ' . implode(',', $orderPosts));

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$gate = strpos($hook, 'jeab_fortnox_lon_hook_authorized');
$at = strpos($hook, "'fortnox.order_export'");
ex_assert($gate !== false && $at !== false && $at > $gate, 'hooken ligger bakom fortnox.*');

echo "Alla order-export-tester OK\n";
