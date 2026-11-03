<?php

declare(strict_types=1);

/**
 * Fortnox DeliveryDate är installationsdagen.
 * Mockad Fortnox: skapande, flytt, bokförd faktura, hook dry/write, ingen bokning.
 */

$tmp = sys_get_temp_dir() . '/jeab-fortnox-lev-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox_leveransdatum.php';
date_default_timezone_set('Europe/Stockholm');

function ld_assert(bool $ok, string $message): void
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

$posts = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$posts): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'POST' && $route === '/orders') {
        $posts[] = is_array($body) ? $body : [];
        return ['Order' => ['DocumentNumber' => '4242', 'CustomerNumber' => '42']];
    }
    if ($method === 'GET' && str_starts_with($route, '/customers')) {
        return ['Customers' => [['CustomerNumber' => '42', 'Active' => true]]];
    }
    if ($method === 'GET' && str_starts_with($route, '/orders/')) {
        return ['Order' => ['DocumentNumber' => '4242', 'OrderRows' => []]];
    }
    if ($method === 'GET' && str_contains($route, 'taxreductions')) {
        return ['TaxReductions' => [], 'MetaInformation' => ['@TotalPages' => 1]];
    }
    return [];
};

$api = new FortnoxAPI('Jarfalla');
$created = $api->createKopOrder([
    'kind' => 'laddbox',
    'name' => 'Ada Kund',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'TEST 1:1',
    'price' => 9990,
    'delivery_date' => '2026-11-02 09:00:00',
    'address' => [],
]);
ld_assert(($created['orderNumber'] ?? '') === '4242', 'order skapas');
$orderBody = is_array($posts[0]['Order'] ?? null) ? $posts[0]['Order'] : [];
ld_assert(($orderBody['DeliveryDate'] ?? '') === '2026-11-02', 'skapad order får installationsdagen som DeliveryDate');

$booking = jeab_create_booking(
    $store,
    'Ada Kund',
    ['Elin'],
    '2026-11-02 09:00:00',
    '2026-11-02 10:00:00',
    'laddbox',
    ['company' => 'jarfalla', 'email' => 'ada@kund.se']
);
$reminder = jeab_create_reminder($store, 'Ada Kund', null, 'Order', null, [
    'customer' => 'Ada Kund',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$row = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Kund',
    'huvudsokande' => 'Ada Kund',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'TEST 1:1',
    'address' => 'Testgatan 1',
    'pris' => 9990,
    'booking_id' => (int) $booking['id'],
    'fortnox_order' => '4242',
    'fortnox_invoice' => '88',
    'steps' => ['bokad_tid' => '2026-11-02 09:00:00'],
]);
$payload = jeab_ordrar_fortnox_payload($row);
ld_assert(str_starts_with((string) ($payload['delivery_date'] ?? ''), '2026-11-02'), 'payload läser installationsdagen från bokningen');

$world = [
    'order' => ['DocumentNumber' => '4242', 'DeliveryDate' => '', 'InvoiceReference' => '', 'Cancelled' => false],
    'invoice' => ['DocumentNumber' => '88', 'DeliveryDate' => '', 'Booked' => false, 'Cancelled' => false, 'OrderReference' => '4242'],
    'puts' => [],
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$world): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'PUT' && $route === '/orders/4242/createinvoice') {
        $world['order']['InvoiceReference'] = '88';
        return ['Invoice' => $world['invoice']];
    }
    if ($method === 'GET' && $route === '/orders/4242') {
        return ['Order' => $world['order']];
    }
    if ($method === 'GET' && $route === '/invoices/88') {
        return ['Invoice' => $world['invoice']];
    }
    if ($method === 'PUT' && $route === '/orders/4242') {
        $date = (string) ($body['Order']['DeliveryDate'] ?? '');
        $world['puts'][] = 'order:' . $date;
        $world['order']['DeliveryDate'] = $date;
        return ['Order' => $world['order']];
    }
    if ($method === 'PUT' && $route === '/invoices/88') {
        $date = (string) ($body['Invoice']['DeliveryDate'] ?? '');
        $world['puts'][] = 'invoice:' . $date;
        $world['invoice']['DeliveryDate'] = $date;
        return ['Invoice' => $world['invoice']];
    }
    return [];
};

$made = jeab_ordrar_fortnox_make_invoice($row);
ld_assert(jeab_ordrar_fortnox_invoice_reference($made) === '88', 'faktura skapas från ordern');
ld_assert($world['puts'] === ['invoice:2026-11-02'], 'fakturerad order hoppas över, fakturan får installationsdagen, fick ' . implode(',', $world['puts']));

$world['puts'] = [];
$moved = jeab_update_booking($store, (int) $booking['id'], [
    'customer' => 'Ada Kund',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'starts_at' => '2026-11-09 09:00:00',
    'ends_at' => '2026-11-09 10:00:00',
    'job_type' => 'laddbox',
]);
ld_assert(str_starts_with((string) ($moved['starts_at'] ?? ''), '2026-11-09'), 'bokningen flyttas');
ld_assert($world['puts'] === ['invoice:2026-11-09'], 'flytt skriver bara fakturan när ordern redan är fakturerad, fick ' . implode(',', $world['puts']));

$world['invoice']['Booked'] = true;
$world['puts'] = [];
@unlink(jeab_app_errors_path());
jeab_update_booking($store, (int) $booking['id'], [
    'customer' => 'Ada Kund',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'starts_at' => '2026-11-16 09:00:00',
    'ends_at' => '2026-11-16 10:00:00',
    'job_type' => 'laddbox',
]);
ld_assert($world['puts'] === [], 'bokförd faktura uppdateras inte');
$log = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
ld_assert(str_contains($log, 'bokford'), 'hoppad bokförd faktura loggas');
ld_assert(!str_contains($log, 'Ada Kund') && !str_contains($log, '19800101'), 'loggen har varken kund eller personnummer');

$world['invoice']['Booked'] = false;
$world['order']['DeliveryDate'] = '2026-11-02';
$world['invoice']['DeliveryDate'] = '2026-11-02';
$world['puts'] = [];
$fresh = jeab_purchase_get((int) $reminder['id']);
ld_assert(is_array($fresh), 'ordern finns kvar');
$dry = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'order_id' => (string) (int) $reminder['id'],
    'date' => '1999-01-01',
]);
ld_assert(($dry['dry'] ?? null) === 1, 'dry är standard');
ld_assert(($dry['current'] ?? '') === '2026-11-02' && ($dry['target'] ?? '') === '2026-11-16' && ($dry['date'] ?? '') === '2026-11-16', 'dry listar nuvarande mot installationsdagen');
ld_assert(($dry['status'] ?? '') !== 'satt' && $world['puts'] === [], 'dry skriver inte');
ld_assert(($dry['target'] ?? '') !== '1999-01-01', 'datum från anropet används inte');

$write = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '88',
    'dry' => 0,
    'DeliveryDate' => '1999-01-01',
]);
ld_assert(($write['status'] ?? '') === 'satt' && ($write['date'] ?? '') === '2026-11-16', 'write sätter installationsdagen');
ld_assert($world['puts'] === ['invoice:2026-11-16'], 'write uppdaterar fakturan och inte den låsta ordern, fick ' . implode(',', $world['puts']));
$orderStep = null;
foreach ((array) ($write['steps'] ?? []) as $step) {
    if (is_array($step) && ($step['target'] ?? '') === 'order') {
        $orderStep = $step;
    }
}
ld_assert(is_array($orderStep) && ($orderStep['ok'] ?? false) === true && ($orderStep['skipped_reason'] ?? '') === 'faktura_genererad', 'ordersteget rapporterar faktura_genererad');

$again = jeab_fortnox_leveransdatum_satt(['account' => 'jeab', 'order_id' => '4242', 'dry' => '0']);
ld_assert(($again['status'] ?? '') === 'oforandrat' && $world['invoice']['DeliveryDate'] === '2026-11-16', 'samma datum skrivs inte om');
ld_assert(count($world['puts']) === 1, 'andra skrivningen gör ingen PUT');

$world['invoice']['Booked'] = true;
$skipped = jeab_fortnox_leveransdatum_satt(['account' => 'JEAB', 'invoice_number' => '88', 'dry' => 1]);
ld_assert(($skipped['status'] ?? '') === 'bokford_hoppad' && ($skipped['current'] ?? '') === '2026-11-16' && ($skipped['target'] ?? '') === '2026-11-16', 'dry på bokförd faktura hoppar över');

$none = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'] + 1,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Kund',
    'huvudsokande' => 'Ada Kund',
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order' => '777',
    'steps' => [],
]);
$missing = jeab_fortnox_leveransdatum_satt(['account' => 'JEAB', 'order_id' => (string) (int) $none['reminder_id']]);
ld_assert(($missing['status'] ?? '') === 'ingen_bokning' && ($missing['date'] ?? '') === '', 'utan bokning blir ingen_bokning');

$other = jeab_fortnox_leveransdatum_satt(['account' => 'MEAB', 'order_id' => '4242', 'dry' => 1]);
ld_assert(($other['status'] ?? '') === 'fel' && ($other['account'] ?? '') === 'Malmo', 'MEAB träffar inte JEAB-ordern');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$gate = strpos($hook, 'jeab_fortnox_lon_hook_authorized');
$action = strpos($hook, "fortnox.leveransdatum_satt");
ld_assert($gate !== false && $action !== false && $action > $gate, 'hooken ligger bakom samma tokengrind som övriga fortnox.*');

$wire = jeab_fortnox_delivery_date_write('invoice', '88', '2026-11-16 09:00:00');
ld_assert($wire['method'] === 'PUT' && $wire['path'] === '/invoices/88' && $wire['wire'] === '/3/invoices/88', 'fakturan skrivs med PUT /3/invoices/{n}');
ld_assert($wire['body'] === ['Invoice' => ['DeliveryDate' => '2026-11-16']], 'faktura-payload är bara DeliveryDate som YYYY-MM-DD');
$orderWire = jeab_fortnox_delivery_date_write('order', '4242', '2026-11-16');
ld_assert($orderWire['wire'] === '/3/orders/4242' && $orderWire['body'] === ['Order' => ['DeliveryDate' => '2026-11-16']], 'order-payload är Order.DeliveryDate');
$offerWire = jeab_fortnox_delivery_date_write('offer', '15', '2026-11-16');
ld_assert($offerWire['wire'] === '/3/offers/15' && $offerWire['body'] === ['Offer' => ['DeliveryDate' => '2026-11-16']], 'offert-payload är Offer.DeliveryDate');

$fail = [
    'order' => ['DocumentNumber' => '4242', 'DeliveryDate' => '2026-11-02', 'InvoiceReference' => '88', 'Cancelled' => false],
    'invoice' => ['DocumentNumber' => '88', 'DeliveryDate' => '2026-11-02', 'Booked' => false, 'Cancelled' => false, 'InvoiceType' => 'INVOICE', 'OrderReference' => '4242', 'OfferReference' => '15'],
    'offer' => ['DocumentNumber' => '15', 'DeliveryDate' => ''],
    'puts' => [],
    'bodies' => [],
    'invoice_error' => new FortnoxApiException('Kan inte spara leveransdatum. Bearer secret-token-value', 400, 2000666),
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$fail): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'GET' && $route === '/orders/4242') {
        return ['Order' => $fail['order']];
    }
    if ($method === 'GET' && $route === '/invoices/88') {
        return ['Invoice' => $fail['invoice']];
    }
    if ($method === 'GET' && $route === '/offers/15') {
        return ['Offer' => $fail['offer']];
    }
    if ($method === 'PUT' && $route === '/orders/4242') {
        $fail['bodies'][] = $body;
        $fail['puts'][] = 'order';
        $fail['order']['DeliveryDate'] = (string) ($body['Order']['DeliveryDate'] ?? '');
        return ['Order' => $fail['order']];
    }
    if ($method === 'PUT' && $route === '/invoices/88') {
        $fail['bodies'][] = $body;
        if ($fail['invoice_error'] instanceof Throwable) {
            throw $fail['invoice_error'];
        }
        $fail['puts'][] = 'invoice';
        $fail['invoice']['DeliveryDate'] = (string) ($body['Invoice']['DeliveryDate'] ?? '');
        return ['Invoice' => $fail['invoice']];
    }
    if ($method === 'PUT' && $route === '/offers/15') {
        $fail['bodies'][] = $body;
        $fail['puts'][] = 'offer';
        $fail['offer']['DeliveryDate'] = (string) ($body['Offer']['DeliveryDate'] ?? '');
        return ['Offer' => $fail['offer']];
    }
    return [];
};

$broken = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '88',
    'order_id' => (string) (int) $reminder['id'],
    'dry' => 0,
]);
$brokenJson = json_encode($broken, JSON_UNESCAPED_UNICODE);
ld_assert(($broken['ok'] ?? true) === false && ($broken['status'] ?? '') === 'fel', 'misslyckad fakturaskrivning blir fel');
ld_assert(($broken['error_information']['message'] ?? '') === 'Kan inte spara leveransdatum.' && ($broken['error_information']['code'] ?? 0) === 2000666, 'svaret har Fortnox message och code');
ld_assert(is_string($brokenJson) && !str_contains($brokenJson, 'secret-token-value') && !str_contains(strtolower($brokenJson), 'bearer'), 'svaret har ingen token');
ld_assert(($fail['bodies'][0] ?? null) === ['Invoice' => ['DeliveryDate' => '2026-11-16']], 'misslyckad skrivning skickade fakturan först, fick ' . json_encode($fail['bodies']));
ld_assert($fail['puts'] === [], 'ordern med InvoiceReference skickas inte');

$fail['invoice']['InvoiceType'] = 'CREDITINVOICE';
$fail['order']['InvoiceReference'] = '';
$fail['invoice']['DeliveryDate'] = '2026-11-02';
$fail['order']['DeliveryDate'] = '2026-11-02';
$fail['puts'] = [];
$fail['bodies'] = [];
$fail['invoice_error'] = null;
$credit = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '88',
    'dry' => 0,
]);
ld_assert(($credit['status'] ?? '') === 'datum_pa_order' && ($credit['invoice_skipped'] ?? '') === 'kreditfaktura', 'kreditfaktura skrivs på ordern');
ld_assert($fail['puts'] === ['order'] && ($fail['bodies'][0] ?? null) === ['Order' => ['DeliveryDate' => '2026-11-16']], 'kreditfaktura gör ingen invoice-PUT');
ld_assert(str_contains((string) ($credit['note'] ?? ''), 'PUT /3/invoices/{n}'), 'noten dokumenterar Fortnox-regeln');

$fail['invoice']['InvoiceType'] = 'INVOICE';
$fail['invoice']['Credit'] = false;
$fail['order']['InvoiceReference'] = '';
$fail['order']['DeliveryDate'] = '2026-11-16';
$fail['invoice']['DeliveryDate'] = '';
$fail['puts'] = [];
$fail['bodies'] = [];
$fail['invoice_error'] = new FortnoxApiException('Ett klarmarkerat dokument kan inte ändras', 400, 2003125);
$locked = jeab_fortnox_leveransdatum_satt(['account' => 'JEAB', 'invoice_number' => '88', 'dry' => '0']);
ld_assert(($locked['status'] ?? '') === 'datum_pa_order' && ($locked['error'] ?? '') === '', 'klarmarkerad faktura faller tillbaka på ordern som redan har datumet');
ld_assert($fail['puts'] === [], 'ingen extra PUT när ordern redan har installationsdagen');

$fail['invoice_error'] = new FortnoxApiException('Utleveransdatum kan inte vara senare än dagens datum', 400, 2003126);
$fail['order']['DeliveryDate'] = '2026-11-16';
$fail['invoice']['DeliveryDate'] = '2026-11-02';
$future = jeab_fortnox_leveransdatum_satt(['account' => 'JEAB', 'invoice_number' => '88', 'dry' => 0]);
ld_assert(($future['status'] ?? '') === 'fel' && ($future['error_information']['code'] ?? 0) === 2003126, '2003126 lämnas kvar och datumet skrivs inte om till idag');
ld_assert(($future['error_information']['message'] ?? '') === 'Utleveransdatum kan inte vara senare än dagens datum', '2003126-meddelandet följer med');

$offerOnly = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'] + 2,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Kund',
    'huvudsokande' => 'Ada Kund',
    'fastighetsbeteckning' => 'TEST 1:1',
    'booking_id' => (int) $booking['id'],
    'fortnox_invoice' => '89',
    'steps' => [],
]);
$fail['invoice89'] = [
    'DocumentNumber' => '89',
    'DeliveryDate' => '',
    'Booked' => false,
    'Cancelled' => false,
    'InvoiceType' => 'SUMMARYINVOICE',
    'OrderReference' => '0',
    'OfferReference' => '15',
    'CustomerName' => 'Ada Kund',
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$fail): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'GET' && $route === '/invoices/89') {
        return ['Invoice' => $fail['invoice89']];
    }
    if ($method === 'GET' && $route === '/offers/15') {
        return ['Offer' => $fail['offer']];
    }
    if ($method === 'PUT' && $route === '/offers/15') {
        $fail['bodies'][] = $body;
        $fail['puts'][] = 'offer';
        return ['Offer' => ['DeliveryDate' => (string) ($body['Offer']['DeliveryDate'] ?? '')]];
    }
    if ($method === 'PUT') {
        $fail['puts'][] = $route;
    }
    return [];
};
$fail['puts'] = [];
$fail['bodies'] = [];
$fail['offer']['DeliveryDate'] = '';
$summary = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'order_id' => (string) (int) $offerOnly['reminder_id'],
    'dry' => 0,
]);
ld_assert(($summary['status'] ?? '') === 'datum_pa_offert' && ($summary['invoice_skipped'] ?? '') === 'samlingsfaktura', 'samlingsfaktura utan order skrivs på offerten');
ld_assert($fail['puts'] === ['offer'] && ($fail['bodies'][0] ?? null) === ['Offer' => ['DeliveryDate' => '2026-11-16']], 'offerten får YYYY-MM-DD och fakturan rörs inte');
ld_assert(!str_contains((string) json_encode($summary), 'Ada Kund'), 'offertsvaret har inget kundnamn');

$lockedOrder = [
    'order' => ['DocumentNumber' => '500', 'DeliveryDate' => '2026-11-02', 'InvoiceReference' => '13593', 'Invoiced' => true],
    'invoice' => ['DocumentNumber' => '13593', 'DeliveryDate' => '2026-11-02', 'Booked' => false, 'Cancelled' => false, 'InvoiceType' => 'INVOICE', 'OrderReference' => '500'],
    'puts' => [],
    'order_error' => new FortnoxApiException('Order som sparas får inte ha en faktura genererad från sig och innehålla ändringar på värden som är låsta.', 400, 2001242),
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$lockedOrder): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'GET' && $route === '/orders/500') {
        return ['Order' => $lockedOrder['order']];
    }
    if ($method === 'GET' && $route === '/invoices/13593') {
        return ['Invoice' => $lockedOrder['invoice']];
    }
    if ($method === 'PUT' && $route === '/orders/500') {
        $lockedOrder['puts'][] = 'order';
        if ($lockedOrder['order_error'] instanceof Throwable) {
            throw $lockedOrder['order_error'];
        }
        return ['Order' => $lockedOrder['order']];
    }
    if ($method === 'PUT' && $route === '/invoices/13593') {
        $lockedOrder['puts'][] = 'invoice';
        $lockedOrder['invoice']['DeliveryDate'] = (string) ($body['Invoice']['DeliveryDate'] ?? '');
        return ['Invoice' => $lockedOrder['invoice']];
    }
    return [];
};
$invoiced = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'] + 3,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Kund',
    'huvudsokande' => 'Ada Kund',
    'fastighetsbeteckning' => 'TEST 1:1',
    'booking_id' => (int) $booking['id'],
    'fortnox_order' => '500',
    'fortnox_invoice' => '13593',
    'steps' => [],
]);
$dated = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '13593',
    'order_id' => (string) (int) $invoiced['reminder_id'],
    'dry' => 0,
]);
ld_assert(($dated['ok'] ?? false) === true && ($dated['status'] ?? '') === 'satt', '13593 skrivs på fakturan trots låst order');
ld_assert($lockedOrder['puts'] === ['invoice'], 'order-PUT med 2001242 körs inte när InvoiceReference finns, fick ' . implode(',', $lockedOrder['puts']));
$byTarget = [];
foreach ((array) ($dated['steps'] ?? []) as $step) {
    if (is_array($step)) {
        $byTarget[(string) ($step['target'] ?? '')] = $step;
    }
}
ld_assert(($byTarget['invoice']['ok'] ?? false) === true && ($byTarget['invoice']['skipped_reason'] ?? 'x') === '', 'fakturasteget är ok');
ld_assert(($byTarget['order']['ok'] ?? false) === true && ($byTarget['order']['skipped_reason'] ?? '') === 'faktura_genererad', 'ordersteget är överhoppat');

$lockedOrder['order']['InvoiceReference'] = '';
$lockedOrder['order']['Invoiced'] = false;
$lockedOrder['invoice']['DeliveryDate'] = '2026-11-02';
$lockedOrder['puts'] = [];
$surprise = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '13593',
    'dry' => 0,
]);
ld_assert(($surprise['ok'] ?? false) === true && ($surprise['status'] ?? '') === 'satt', '2001242 på ordern stoppar inte fakturan');
ld_assert($lockedOrder['puts'] === ['invoice', 'order'], 'fakturan skrivs före ordern, fick ' . implode(',', $lockedOrder['puts']));
$surpriseOrder = null;
foreach ((array) ($surprise['steps'] ?? []) as $step) {
    if (is_array($step) && ($step['target'] ?? '') === 'order') {
        $surpriseOrder = $step;
    }
}
ld_assert(is_array($surpriseOrder) && ($surpriseOrder['skipped_reason'] ?? '') === 'faktura_genererad' && ($surpriseOrder['ok'] ?? false) === true, '2001242 blir skipped_reason på ordersteget');

$ready = [
    'order' => ['DocumentNumber' => '501', 'DeliveryDate' => '2026-11-02', 'InvoiceReference' => '13594', 'Invoiced' => true],
    'invoice' => [
        'DocumentNumber' => '13594',
        'DeliveryDate' => '2026-11-02',
        'Booked' => false,
        'Cancelled' => false,
        'InvoiceType' => 'INVOICE',
        'WarehouseReady' => true,
        'OrderReference' => '501',
    ],
    'puts' => [],
    'paths' => [],
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$ready): array {
    $route = strtok($path, '?') ?: $path;
    $ready['paths'][] = $method . ' ' . $route;
    if ($method === 'GET' && $route === '/orders/501') {
        return ['Order' => $ready['order']];
    }
    if ($method === 'GET' && $route === '/invoices/13594') {
        return ['Invoice' => $ready['invoice']];
    }
    if ($method === 'PUT' && $route === '/invoices/13594') {
        $ready['puts'][] = 'invoice';
        if (!empty($ready['reject'])) {
            throw new FortnoxApiException('Ett klarmarkerat dokument kan inte ändras', 400, 2003125);
        }
        $ready['invoice']['DeliveryDate'] = (string) ($body['Invoice']['DeliveryDate'] ?? '');
        return ['Invoice' => $ready['invoice']];
    }
    if ($method === 'PUT') {
        $ready['puts'][] = $route;
        throw new RuntimeException('oväntad skrivning ' . $route);
    }
    return [];
};
$readyOrder = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'] + 4,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Kund',
    'huvudsokande' => 'Ada Kund',
    'fastighetsbeteckning' => 'TEST 1:1',
    'booking_id' => (int) $booking['id'],
    'fortnox_order' => '501',
    'fortnox_invoice' => '13594',
    'steps' => [],
]);
$ready['paths'] = [];
$dryReady = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '13594',
    'order_id' => (string) (int) $readyOrder['reminder_id'],
    'dry' => 1,
]);
$invoiceStep = null;
$orderStep = null;
foreach ((array) ($dryReady['steps'] ?? []) as $step) {
    if (!is_array($step)) {
        continue;
    }
    if (($step['target'] ?? '') === 'invoice') {
        $invoiceStep = $step;
    }
    if (($step['target'] ?? '') === 'order') {
        $orderStep = $step;
    }
}
ld_assert(($invoiceStep['skipped_reason'] ?? 'x') === '' && ($dryReady['invoice_skipped'] ?? '') !== 'klarmarkerad', 'dry hoppar inte över fakturan för WarehouseReady');
ld_assert(($orderStep['skipped_reason'] ?? '') === 'faktura_genererad', 'dry hoppar över den redan fakturerade ordern');
ld_assert($ready['puts'] === [] && ($dryReady['calls'] ?? null) === [[
    'method' => 'PUT',
    'path' => '/invoices/13594',
    'wire' => '/3/invoices/13594',
    'body' => ['Invoice' => ['DeliveryDate' => '2026-11-16']],
]], 'dry visar bara faktura-PUT, fick ' . json_encode($dryReady['calls'] ?? null));

$ready['reject'] = true;
$ready['puts'] = [];
$ready['paths'] = [];
$blocked = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '13594',
    'dry' => 0,
]);
$blockedJson = json_encode($blocked, JSON_UNESCAPED_UNICODE);
ld_assert(($blocked['status'] ?? '') === 'last_i_fortnox' && ($blocked['ok'] ?? true) === false, '2003125 på fakturerad order blir last_i_fortnox');
ld_assert(($blocked['advice'] ?? '') === 'Sätt leveransdatumet manuellt i Fortnox.', 'svaret ber om manuell ändring i Fortnox');
ld_assert(($blocked['error_information']['code'] ?? 0) === 2003125, 'Fortnox-koden följer med');
ld_assert($ready['puts'] === ['invoice'], 'bara faktura-PUT gjordes, fick ' . implode(',', $ready['puts']));
ld_assert(is_string($blockedJson) && !str_contains($blockedJson, 'credit') && !str_contains(implode(' ', $ready['paths']), 'credit') && !str_contains(implode(' ', $ready['paths']), 'createinvoice') && !str_contains(implode(' ', $ready['paths']), 'warehouseready'), 'ingen kredit, ny faktura eller warehouseready');

$ready['reject'] = false;
$ready['invoice']['DeliveryDate'] = '2026-11-02';
$ready['puts'] = [];
$accepted = jeab_fortnox_leveransdatum_satt([
    'account' => 'JEAB',
    'invoice_number' => '13594',
    'dry' => 0,
]);
ld_assert(($accepted['status'] ?? '') === 'satt' && $ready['puts'] === ['invoice'], 'WarehouseReady utan Fortnox-fel skrivs på fakturan');

echo "Alla leveransdatum-tester OK\n";
