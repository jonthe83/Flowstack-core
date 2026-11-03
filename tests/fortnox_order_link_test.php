<?php

declare(strict_types=1);

/**
 * fortnox.order_relink pekar om en order till ett annat fakturanummer lokalt.
 * fortnox.invoice_order_lookup förklarar varför en faktura saknar order, utan personuppgifter.
 */

$tmp = sys_get_temp_dir() . '/jeab-fortnox-link-' . bin2hex(random_bytes(4));
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
    'Malmo' => ['refresh_token' => 'test-refresh'],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox_order_link.php';

function link_assert(bool $ok, string $message): void
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

$secretName = 'Anna Andersson';
$secretMail = 'anna@example.com';
$secretPnr = '198001011234';

$reminder = jeab_create_reminder($store, $secretName, null, 'Order', null, [
    'customer' => $secretName,
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$other = jeab_create_reminder($store, 'Annan', null, 'Order', null, [
    'customer' => 'Annan',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$order = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => $secretName,
    'email' => $secretMail,
    'personnummer' => $secretPnr,
    'address' => 'Hemliggatan 1',
    'huvudsokande' => $secretName,
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order' => '900',
    'fortnox_invoice' => '13605',
    'fortnox_customer' => '42',
    'steps' => [],
]);
jeab_purchase_put([
    'reminder_id' => (int) $other['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Annan',
    'huvudsokande' => 'Annan',
    'fastighetsbeteckning' => 'TEST 1:2',
    'fortnox_invoice' => '999',
    'fortnox_customer' => '77',
    'steps' => [],
]);
link_assert((int) $order['reminder_id'] > 0, 'order finns');
$orderId = (string) (int) $order['reminder_id'];
$before = jeab_purchase_get((int) $orderId);
link_assert(is_array($before), 'ordern går att läsa');

$calls = [];
$invoices = [
    '13592' => [
        'DocumentNumber' => '13592',
        'InvoiceType' => 'INVOICE',
        'Booked' => true,
        'CustomerNumber' => '42',
        'CustomerName' => $secretName,
        'EmailInformation' => ['EmailAddressTo' => $secretMail],
        'Address1' => 'Hemliggatan 1',
        'OrganisationNumber' => $secretPnr,
        'OrderReference' => '777',
        'YourOrderNumber' => $orderId,
        'Comments' => 'ring ' . $secretName,
    ],
    '13606' => [
        'DocumentNumber' => '13606',
        'InvoiceType' => 'INVOICE',
        'CustomerNumber' => '42',
        'CustomerName' => $secretName,
        'OrderReference' => '900',
        'YourOrderNumber' => $orderId,
    ],
    '13607' => [
        'DocumentNumber' => '13607',
        'InvoiceType' => 'CREDITINVOICE',
        'Credit' => true,
        'CustomerNumber' => '42',
        'CustomerName' => $secretName,
        'InvoiceReference' => '13606',
        'OrderReference' => '900',
    ],
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$calls, &$invoices): array {
    $calls[] = $method . ' ' . $path;
    if ($method !== 'GET') {
        throw new RuntimeException('lookup får bara läsa');
    }
    $route = strtok($path, '?') ?: $path;
    if (preg_match('#^/invoices/(\d+)$#', $route, $match) === 1 && isset($invoices[$match[1]])) {
        return ['Invoice' => $invoices[$match[1]]];
    }
    throw new FortnoxApiException('Kunde inte hitta fakturan.', 404, 2000433);
};

$dry = jeab_fortnox_order_relink([
    'action' => 'fortnox.order_relink',
    'account' => 'JEAB',
    'order_id' => $orderId,
    'invoice_number' => '13606',
    'from_invoice' => '13605',
]);
link_assert(($dry['dry'] ?? null) === 1 && ($dry['status'] ?? '') === 'skulle_byta', 'dry är standard och skriver inte');
link_assert(($dry['from_invoice'] ?? '') === '13605' && ($dry['invoice_number'] ?? '') === '13606', 'dry visar från krediterad faktura till den nya');
link_assert(jeab_fortnox_order_link_invoice_of(jeab_purchase_get((int) $orderId) ?? []) === '13605', 'dry lämnar ordern');
link_assert($calls === [], 'ompekning anropar inte Fortnox');

$denied = false;
try {
    jeab_fortnox_order_relink([
        'account' => 'JEAB',
        'order_id' => $orderId,
        'invoice_number' => '13606',
        'dry' => 0,
    ]);
} catch (InvalidArgumentException $e) {
    $denied = $e->getMessage() === 'Bekräftelse saknas.';
}
link_assert($denied, 'dry=0 utan confirm=SAM nekas');
link_assert(jeab_fortnox_order_link_invoice_of(jeab_purchase_get((int) $orderId) ?? []) === '13605', 'nekad bekräftelse skriver inte');

@unlink(jeab_app_errors_path());
$written = jeab_fortnox_order_relink([
    'account' => 'jeab',
    'order_id' => $orderId,
    'invoice_number' => '13606',
    'from_invoice' => '13605',
    'dry' => '0',
    'confirm' => 'SAM',
]);
$fresh = jeab_purchase_get((int) $orderId);
link_assert(is_array($fresh) && ($written['status'] ?? '') === 'bytt', 'dry=0 med confirm byter fakturan');
link_assert((string) ($fresh['fortnox_invoice'] ?? '') === '13606', 'ordern pekar på 13606');
link_assert(
    (string) ($fresh['customer'] ?? '') === (string) ($before['customer'] ?? '')
    && (string) ($fresh['personnummer'] ?? '') === (string) ($before['personnummer'] ?? '')
    && (string) ($fresh['address'] ?? '') === (string) ($before['address'] ?? '')
    && (string) ($fresh['fortnox_order'] ?? '') === '900'
    && (string) ($fresh['email'] ?? '') === (string) ($before['email'] ?? ''),
    'övriga fält på ordern är orörda'
);
$logText = json_encode($fresh['log'] ?? [], JSON_UNESCAPED_UNICODE);
link_assert(is_string($logText) && str_contains($logText, '13605') && str_contains($logText, '13606'), 'auditloggen nämner båda fakturanumren');
link_assert(is_string($logText) && !str_contains($logText, $secretName) && !str_contains($logText, $secretMail) && !str_contains($logText, $secretPnr), 'auditloggen har inga personuppgifter');
$appLog = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
link_assert(str_contains($appLog, 'fortnox_invoice_relink') && !str_contains($appLog, $secretName) && !str_contains($appLog, 'test-refresh'), 'felloggen har bytet och ingen hemlighet');
$logCount = count(jeab_ordrar_activity_list($fresh));

$again = jeab_fortnox_order_relink([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'invoice_number' => '13606',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$freshAgain = jeab_purchase_get((int) $orderId);
link_assert(($again['status'] ?? '') === 'oforandrat' && count(jeab_ordrar_activity_list($freshAgain ?? [])) === $logCount, 'samma faktura skrivs inte om');

$mismatch = jeab_fortnox_order_relink([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'invoice_number' => '13608',
    'from_invoice' => '13605',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$afterMismatch = jeab_purchase_get((int) $orderId);
link_assert(($mismatch['ok'] ?? true) === false && (string) ($afterMismatch['fortnox_invoice'] ?? '') === '13606', 'fel from_invoice skriver inte');

$taken = jeab_fortnox_order_relink([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'invoice_number' => '999',
    'dry' => 1,
]);
link_assert(($taken['ok'] ?? true) === false && ($taken['other_order_id'] ?? '') === (string) (int) $other['id'], 'faktura som redan har en order pekas inte om');

$otherCompany = jeab_fortnox_order_relink([
    'account' => 'MEAB',
    'order_id' => $orderId,
    'invoice_number' => '13606',
    'dry' => 1,
]);
link_assert(($otherCompany['ok'] ?? true) === false && ($otherCompany['error'] ?? '') === 'Ordern finns inte.', 'MEAB träffar inte JEAB-ordern');

$lookup = jeab_fortnox_invoice_order_lookup([
    'action' => 'fortnox.invoice_order_lookup',
    'account' => 'JEAB',
    'invoice_number' => '13592',
]);
$lookupJson = json_encode($lookup, JSON_UNESCAPED_UNICODE);
$reasons = [];
foreach ((array) ($lookup['reasons'] ?? []) as $reason) {
    if (is_array($reason)) {
        $reasons[(string) ($reason['key'] ?? '')] = (string) ($reason['reason'] ?? '');
    }
}
link_assert(($lookup['read_only'] ?? 0) === 1 && ($lookup['linked'] ?? true) === false, '13592 har ingen order på fortnox_invoice');
link_assert(($reasons['fortnox_invoice'] ?? '') === 'no_matching_order', 'orsaken är att ingen order har fakturan');
link_assert(($reasons['OrderReference'] ?? '') === 'no_matching_order', 'OrderReference 777 saknar lokal order');
link_assert(($reasons['CustomerNumber'] ?? '') === 'customer_number', 'kundnumret finns på en order');
link_assert(($reasons['YourOrderNumber'] ?? '') === 'order_points_elsewhere', 'YourOrderNumber träffar ordern men den pekar på en annan faktura');
link_assert(is_string($lookupJson) && !str_contains($lookupJson, $secretName) && !str_contains($lookupJson, $secretMail) && !str_contains($lookupJson, $secretPnr) && !str_contains($lookupJson, 'Hemliggatan'), 'lookup returnerar inga personuppgifter');
link_assert($calls !== [] && !array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')), 'lookup gör bara GET');

$invoices['13606']['CustomerName'] = $secretName;
jeab_fortnox_order_relink([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'invoice_number' => '13606',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$linked = jeab_fortnox_invoice_order_lookup(['account' => 'Jarfalla', 'invoice_number' => '13606']);
link_assert(($linked['linked'] ?? false) === true, '13606 är kopplad efter ompekningen');
$linkedKeys = [];
foreach ((array) ($linked['reasons'] ?? []) as $reason) {
    if (is_array($reason) && ($reason['reason'] ?? '') === 'matching_order') {
        $linkedKeys[] = (string) ($reason['key'] ?? '');
    }
}
link_assert(in_array('fortnox_invoice', $linkedKeys, true) && in_array('OrderReference', $linkedKeys, true), 'träff via fortnox_invoice och OrderReference');

$credit = jeab_fortnox_invoice_order_lookup(['account' => 'JEAB', 'invoice_number' => '13607']);
$creditReasons = [];
foreach ((array) ($credit['reasons'] ?? []) as $reason) {
    if (is_array($reason)) {
        $creditReasons[(string) ($reason['key'] ?? '')] = $reason;
    }
}
link_assert(($credit['linked'] ?? true) === false, 'kreditfakturan 13607 har ingen egen order');
link_assert(($creditReasons['InvoiceReference']['reason'] ?? '') === 'order_points_at_other_invoice', 'ordern pekar på debetfakturan i stället');
link_assert(($creditReasons['InvoiceType']['value'] ?? '') === 'CREDITINVOICE', 'fakturatypen följer med');
link_assert(!str_contains((string) json_encode($credit), $secretName), 'kredit-svaret har inget kundnamn');

$missing = jeab_fortnox_invoice_order_lookup(['account' => 'JEAB', 'invoice_number' => '13590']);
$missingJson = json_encode($missing, JSON_UNESCAPED_UNICODE);
link_assert(($missing['linked'] ?? true) === false, 'okänd faktura har ingen order');
link_assert(is_string($missingJson) && str_contains($missingJson, 'Kunde inte hitta fakturan.') && str_contains($missingJson, '2000433'), 'Fortnox 404 blir message och code');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$gate = strpos($hook, 'jeab_fortnox_lon_hook_authorized');
$relink = strpos($hook, "'fortnox.order_relink'");
$lookupAt = strpos($hook, "'fortnox.invoice_order_lookup'");
link_assert($gate !== false && $relink !== false && $lookupAt !== false && $relink > $gate && $lookupAt > $gate, 'båda hookarna ligger bakom fortnox.*-grinden');

echo "Alla orderlänk-tester OK\n";
