<?php

declare(strict_types=1);

/**
 * Webshop (Wix) → påminnelse vunnen, idempotent, och Fortnox skrivs bara en gång.
 * Inget skickas: webhooks fångas, Fortnox är stubbad.
 */

$tmp = sys_get_temp_dir() . '/jeab-webshop-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
$GLOBALS['_JEAB_LOG_PDO'] = new PDO('sqlite::memory:');
$GLOBALS['_JEAB_LOG_PDO']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_log_pdo();
jeab_log_flag_set($GLOBALS['_JEAB_LOG_PDO'], 'webshop_orders');
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function ws_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = new JeabJsonStore($tmp . '/store.json');
$order = [
    'number' => '90001',
    'id' => 'test-order',
    'createdDate' => '2026-10-14T11:04:35.419Z',
    'status' => 'APPROVED',
    'paymentStatus' => 'PAID',
    'priceSummary' => ['total' => ['amount' => '8490.00']],
    'buyerInfo' => ['email' => 'kund@example.invalid'],
    'billingInfo' => [
        'address' => ['addressLine' => 'Testgatan 1', 'postalCode' => '236 33', 'city' => 'Höllviken'],
        'contactDetails' => ['firstName' => 'Test', 'lastName' => 'Kund', 'phone' => '+46700000000'],
    ],
    'lineItems' => [[
        'productName' => ['original' => 'Easee laddbox med installation'],
        'quantity' => 1,
        'price' => ['amount' => '8490.00'],
        'physicalProperties' => ['shippable' => true], // ingen sku-nyckel alls
        'catalogReference' => ['options' => ['options' => ['Välj färg' => 'Svart', 'Välj paket' => 'Med lastbalansering & 7m laddkabel och krok']]],
    ]],
];

$w = jeab_webshop_normalize(['order' => $order, 'site_id' => '5a7c71c4-ed34-4048-adc7-f390de72698f']);
ws_assert($w['company'] === 'malmo', 'Malmö-sajten ger MEAB');
ws_assert($w['lines'][0]['sku'] === '', 'saknad sku blir tom sträng');
ws_assert(jeab_webshop_kind($w) === 'laddbox', 'Easee blir laddbox');

$env = ['createdEvent' => ['entity' => $order], 'company' => 'malmo'];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
    throw new Exception('Fortnox ska inte anropas vid ingest');
};
$r1 = jeab_webshop_ingest($store, $env);
ws_assert(!empty($r1['ok']) && $r1['reminder_id'] > 0, 'påminnelse skapad');
$r2 = jeab_webshop_ingest($store, ['order' => $order, 'company' => 'malmo']);
ws_assert(!empty($r2['duplicate']) && $r2['reminder_id'] === $r1['reminder_id'], 'samma Wix-order skapar ingen dubblett');

$rem = jeab_get_reminder($store, $r1['reminder_id']);
ws_assert(in_array('vunnen', jeab_parse_tags($rem['tags'] ?? []), true), 'taggad vunnen');
ws_assert(jeab_reminder_company($rem) === 'malmo', 'bolag MEAB');
$p = jeab_purchase_get($r1['reminder_id']);
ws_assert(is_array($p) && ($p['source'] ?? '') === 'webshop' && ($p['webshop']['number'] ?? '') === '90001', 'order märkt webshop med Wix-nummer');
ws_assert(($p['kind'] ?? '') === 'laddbox', 'order är laddbox');

// Fortnox: finns redan → länka, skriv inte.
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static fn (array $ws): array => [['order' => '777', 'customer' => '55', 'match' => 'wix_number']];
$linked = jeab_webshop_fortnox_link($p);
ws_assert(is_array($linked) && $linked['fortnox_order'] === '777', 'befintlig Fortnox-order länkas');
ws_assert(jeab_webshop_fortnox_link($linked) === null, 'redan länkad: inget mer görs');

// Saknas → inget länkas (vanlig skrivning tar över).
$p2 = $p;
$p2['fortnox_order'] = '';
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static fn (array $ws): array => [];
ws_assert(jeab_webshop_fortnox_link($p2) === null, 'saknas i Fortnox: ingen länk');
ws_assert(jeab_webshop_precheck($p2) === null, 'webshop utan träff: flowstack.example.test skriver');
$form = ['reminder_id' => $p['reminder_id'], 'name' => 'Test Kund', 'company' => 'malmo', 'fortnox_order' => ''];
ws_assert(jeab_webshop_precheck($form) === null, 'formulärorder utan träff skrivs som vanligt');
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static fn (array $ws): array => [['order' => '888', 'customer' => '5', 'match' => 'customer']];
$l = jeab_webshop_precheck(jeab_purchase_get($p['reminder_id']) + ['fortnox_order' => ''] === [] ? $form : array_merge(jeab_purchase_get($p['reminder_id']), ['fortnox_order' => '', 'source' => '', 'webshop' => null]));
ws_assert(is_array($l) && $l['fortnox_order'] === '888' && ($l['source'] ?? '') === '', 'formulärorder länkar befintlig webshoporder');

// Matchning mot Fortnox-listan.
$api = new class extends FortnoxAPI {
    public function __construct() {}
    public function listOrders(array $query = []): array
    {
        return [
            ['DocumentNumber' => '10', 'CustomerName' => 'Annan', 'Total' => 100, 'YourOrderNumber' => ''],
            ['DocumentNumber' => '11', 'CustomerName' => 'Test Kund', 'Total' => 8490, 'YourOrderNumber' => ''],
        ];
    }
    public function getOrder(string $orderNumber): array
    {
        return ['Order' => ['DocumentNumber' => $orderNumber, 'CustomerNumber' => '9', 'Total' => 8490, 'YourOrderNumber' => '90001', 'OrderDate' => '2026-10-14']];
    }
};
$hits = jeab_webshop_fortnox_find($p['webshop'] + ['company' => 'malmo'], $api);
ws_assert(count($hits) === 1 && $hits[0]['order'] === '11' && $hits[0]['match'] === 'wix_number', 'Fortnox-träff på Wix-nummer');

ws_assert(jeab_webshop_same_name('olof bladh', 'Jan Olof Martin Bladh'), 'Wix-namn matchar fullt Fortnox-namn');
ws_assert(!jeab_webshop_same_name('olof bladh', 'Olof Svensson'), 'annan kund matchar inte');

$mk = static fn (string $title, ?string $sku, string $opt = ''): array => ['lines' => [['title' => $title, 'options' => $opt, 'qty' => 1, 'price' => '1', 'sku' => (string) $sku]]];
ws_assert(jeab_webshop_kind($mk('SigenStor Neo 15 kWh', '')) === 'batteri', 'Neo via namn blir batteri');
ws_assert(jeab_webshop_kind($mk('Hembatteri', 'SIGENSTOR-NEO-7.5')) === 'batteri', 'Neo via SKU blir batteri');
ws_assert(jeab_webshop_kind($mk('Neo', 'SIGENSTOR-NEO-37.5')) === 'batteri', 'bara Neo + SKU blir batteri');
ws_assert(jeab_webshop_kind($mk('Easee laddbox med installation', null, 'Svart')) === 'laddbox', 'Easee blir laddbox');
$noSku = jeab_webshop_normalize(['company' => 'jarfalla', 'order' => ['number' => '1', 'lineItems' => [['productName' => ['original' => 'SigenStor Neo 30'], 'physicalProperties' => []]]]]);
ws_assert($noSku['lines'][0]['sku'] === '' && jeab_webshop_kind($noSku) === 'batteri', 'saknad sku är säker');

// Wix-intake: bara id ur anropet, ordern hämtas från Wix.
$ids = jeab_webshop_event_ids(['data' => ['orderId' => 'D1C6D7F9-B160-4B9C-8CCF-09DB8D929B8C'], 'siteId' => 'malmo', 'order' => ['number' => '666']]);
ws_assert($ids['order_id'] === 'd1c6d7f9-b160-4b9c-8ccf-09db8d929b8c' && $ids['site_id'] === '5a7c71c4-ed34-4048-adc7-f390de72698f', 'id + sajt ur anropet');
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $m, string $path, ?array $b) use ($order): array {
    if (str_starts_with($path, '/ecom/v1/orders/search')) {
        return ['orders' => [$order + ['id' => 'x']]];
    }
    return ['order' => $order];
};
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array { throw new Exception('nej'); };
$r = jeab_webshop_fetch_and_ingest($store, '5a7c71c4-ed34-4048-adc7-f390de72698f', 'test-order');
ws_assert(!empty($r['duplicate']), 'hämtad order som redan finns blir dubblett');
$c = jeab_webshop_catchup($store, true, 2);
ws_assert($c['sites'][1]['known'] === ['90001'] && $c['sites'][0]['missing'] === ['90001'], 'ikapp dry: känd på MEAB, saknas på JEAB (testdata)');

echo "Alla webshop-tester OK\n";
