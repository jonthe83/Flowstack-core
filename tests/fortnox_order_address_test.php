<?php

declare(strict_types=1);

/**
 * fortnox.order_export: Address1 är bara gata och nummer.
 * Ort och postnummer ligger i City och ZipCode. Syntetiska adresser.
 */

$tmp = sys_get_temp_dir() . '/jeab-fortnox-address-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/fortnox.php';

function addr_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function addr_wire(array $raw): array
{
    return FortnoxAPI::wireAddressFields($raw);
}

function addr_no_country(array $fields): void
{
    foreach ($fields as $key => $value) {
        if (!is_string($value) || !preg_match('/address|zip|city|country/i', (string) $key)) {
            continue;
        }
        addr_assert(preg_match('/sverige|sweden/iu', $value) !== 1, $key . ' innehåller inte Sverige');
    }
}

$combined = addr_wire([
    'address' => 'Gymnastikvägen 29 Järfälla',
    'postnr' => '177 00',
    'ort' => 'Järfälla',
]);
addr_assert(($combined['Address1'] ?? '') === 'Gymnastikvägen 29', 'gata och nummer när orten också står i City, fick: ' . ($combined['Address1'] ?? ''));
addr_assert(($combined['City'] ?? '') === 'Järfälla', 'orten ligger i City');
addr_assert(($combined['ZipCode'] ?? '') === '17700', 'postnumret ligger i ZipCode');
addr_no_country($combined);

$comma = addr_wire(['address' => 'Exempelvägen 4, Exempelby']);
addr_assert(($comma['Address1'] ?? '') === 'Exempelvägen 4' && ($comma['City'] ?? '') === 'Exempelby', 'kontaktadress «gata, ort» delas');
addr_assert(!isset($comma['ZipCode']), 'utan postnummer skrivs inget ZipCode');

$spaced = addr_wire(['address' => 'Exempelvägen 4 Exempelby']);
addr_assert(($spaced['Address1'] ?? '') === 'Exempelvägen 4' && ($spaced['City'] ?? '') === 'Exempelby', 'kontaktadress «gata ort» delas');

$full = addr_wire(['address' => 'Exempelvägen 4, 123 45 Exempelby, Sverige']);
addr_assert(($full['Address1'] ?? '') === 'Exempelvägen 4', 'gata före postnummer och land');
addr_assert(($full['ZipCode'] ?? '') === '12345' && ($full['City'] ?? '') === 'Exempelby', 'postnummer och ort från en rad');
addr_no_country($full);
addr_assert(!isset($full['Country']), 'Sverige blir inte land');

$countryCity = addr_wire([
    'gata' => 'Exempelvägen 4 Sverige',
    'ort' => 'Sverige',
    'land' => 'Sverige',
]);
addr_assert(($countryCity['Address1'] ?? '') === 'Exempelvägen 4', 'Sverige lämnar gatan');
addr_assert(!isset($countryCity['City']) && !isset($countryCity['Country']), 'Sverige blir inte ort');

$letter = addr_wire(['address' => 'Exempelvägen 4 B']);
addr_assert(($letter['Address1'] ?? '') === 'Exempelvägen 4 B' && !isset($letter['City']), 'bokstav efter numret stannar i gatan');

$letterCity = addr_wire(['address' => 'Exempelvägen 4 B Järfälla', 'postnr' => '17700']);
addr_assert(($letterCity['Address1'] ?? '') === 'Exempelvägen 4 B', 'bokstav följer med när orten lyfts ut, fick: ' . ($letterCity['Address1'] ?? ''));
addr_assert(($letterCity['City'] ?? '') === 'Järfälla' && ($letterCity['ZipCode'] ?? '') === '17700', 'ort och postnummer bredvid bokstaven');

$apt = addr_wire(['address' => 'Exempelvägen 4 lgh 2, Exempelby']);
addr_assert(($apt['Address1'] ?? '') === 'Exempelvägen 4 lgh 2' && ($apt['City'] ?? '') === 'Exempelby', 'lägenhet stannar i gatan');

$row = [
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'huvudsokande' => 'Test Person',
    'personnummer' => '199001011234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'huvudsokande_address' => 'Gymnastikvägen 29 Järfälla',
    'postnummer' => '177 00',
    'ort' => 'Järfälla',
    'pris' => 80000,
    'kundpris' => 80000,
];
$payload = jeab_ordrar_fortnox_payload($row);
$plan = (new FortnoxAPI('Jarfalla'))->kopExportPlan($payload);
$order = is_array($plan['order'] ?? null) ? $plan['order'] : [];
addr_assert(($order['Address1'] ?? '') === 'Gymnastikvägen 29', 'orderexportens Address1 är bara gatan, fick: ' . ($order['Address1'] ?? ''));
addr_assert(($order['DeliveryAddress1'] ?? '') === 'Gymnastikvägen 29', 'leveransadressen är bara gatan');
addr_assert(($order['City'] ?? '') === 'Järfälla' && ($order['DeliveryCity'] ?? '') === 'Järfälla', 'orten ligger i City och DeliveryCity');
addr_assert(($order['ZipCode'] ?? '') === '17700' && ($order['DeliveryZipCode'] ?? '') === '17700', 'postnumret ligger i ZipCode och DeliveryZipCode');
addr_no_country($order);

$commaRow = $row;
$commaRow['huvudsokande_address'] = 'Exempelvägen 8, Exempelby';
$commaRow['postnummer'] = '';
$commaRow['ort'] = '';
$commaOrder = (new FortnoxAPI('Jarfalla'))->kopExportPlan(jeab_ordrar_fortnox_payload($commaRow))['order'] ?? [];
addr_assert(($commaOrder['Address1'] ?? '') === 'Exempelvägen 8' && ($commaOrder['City'] ?? '') === 'Exempelby', 'export delar «gata, ort» från kontakten');

$spaceRow = $row;
$spaceRow['huvudsokande_address'] = 'Exempelvägen 8 Exempelby';
$spaceRow['gata'] = 'Exempelvägen 8 Exempelby';
$spaceRow['postnummer'] = '12345';
$spaceRow['ort'] = 'Exempelby';
$spaceOrder = (new FortnoxAPI('Jarfalla'))->kopExportPlan(jeab_ordrar_fortnox_payload($spaceRow))['order'] ?? [];
addr_assert(($spaceOrder['Address1'] ?? '') === 'Exempelvägen 8', 'export delar «gata ort» även när gata-fältet har orten, fick: ' . ($spaceOrder['Address1'] ?? ''));
addr_assert(($spaceOrder['City'] ?? '') === 'Exempelby' && ($spaceOrder['ZipCode'] ?? '') === '12345', 'exportens ort och postnummer ligger i egna fält');
addr_no_country($spaceOrder);

echo "OK\n";
