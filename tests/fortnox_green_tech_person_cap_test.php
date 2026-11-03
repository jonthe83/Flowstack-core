<?php

declare(strict_types=1);

/**
 * Skatteverkets tak 50 000 kr per person för grön teknik.
 * Delas av Offert, Ordrar/Fakturering och Manuell order via FortnoxAPI.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function gtc_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function gtc_sum(array $splits): int
{
    $sum = 0;
    foreach ($splits as $person) {
        if (!is_array($person)) {
            continue;
        }
        foreach ($person as $amount) {
            if (is_array($amount)) {
                $sum += (int) ($amount['AskedAmount'] ?? 0);
            }
        }
    }
    return $sum;
}

gtc_assert(FortnoxAPI::greenTechPersonCap() === 50000, 'person-taket är 50 000 kr');
gtc_assert(FortnoxAPI::greenTechPeopleCap(1) === 50000, 'en person → 50 000');
gtc_assert(FortnoxAPI::greenTechPeopleCap(2) === 100000, 'två personer → 100 000');

$bases = [
    'SOLARCELLS' => 200000.0,
    'STORAGESELFPRODUCEDELECTRICITY' => 120000.0,
];
$raw = (int) floor(200000 * 0.15) + (int) floor(120000 * 0.50);
gtc_assert($raw === 90000, 'rå möjlig utan person-tak är 90 000');
gtc_assert(FortnoxAPI::greenTechPossible($bases) === 50000, 'en person: möjlig klampas till 50 000');
gtc_assert(FortnoxAPI::greenTechPossible($bases, 2) === 90000, 'två personer: rå möjlig ryms under 100 000');
gtc_assert(FortnoxAPI::greenTechPossible($bases, 1) === 50000, 'explicit en person ger 50 000');

$one = FortnoxAPI::greenTechSplits($bases, 1, 90000);
gtc_assert(gtc_sum($one) === 50000, 'splits en person: mål 90 000 blir 50 000, fick ' . gtc_sum($one));
$fromTypes = FortnoxAPI::greenTechSplitsFromTypes(FortnoxAPI::greenTechPossibleByType($bases), 1);
gtc_assert(gtc_sum($fromTypes) === 50000, 'splitsFromTypes en person klampas till 50 000');

$two = FortnoxAPI::greenTechSplits($bases, 2, 90000);
gtc_assert(gtc_sum($two) === 90000, 'splits två personer: hela 90 000 fördelas');
foreach ($two as $i => $person) {
    $personSum = 0;
    foreach ($person as $amount) {
        $personSum += (int) ($amount['AskedAmount'] ?? 0);
    }
    gtc_assert($personSum <= 50000, 'person ' . $i . ' får högst 50 000, fick ' . $personSum);
}

$plan = jeab_ordrar_gt_sync_plan(
    [['Price' => 200000, 'Total' => 200000, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1']],
    [['Price' => 200000, 'Total' => 200000, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1'],
     ['Price' => 120000, 'Total' => 120000, 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'DeliveredQuantity' => '1']],
    30000,
    1,
    true,
    false
);
gtc_assert($plan['mojlig'] === 50000 && $plan['asked'] === 50000, 'Fakturering-synk en person stannar på 50 000');

$msg = 'Angivet belopp gör att totalen överskrider 50000 kr.';
gtc_assert(FortnoxAPI::isPersonCapTaxAmountTooHigh($msg) === true, 'känner igen totalen överskrider 50000');
gtc_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($msg) === false, 'person-taket går inte till per-typ-retry');
gtc_assert(FortnoxAPI::isTaxAmountTooHigh($msg) === true, 'person-taket är för högt belopp');
gtc_assert(
    FortnoxAPI::isPersonCapTaxAmountTooHigh('Angivet belopp gör att totalen överskrider 50 000 kr.') === true,
    'känner igen 50 000 med mellanslag'
);
$perTypeMsg = 'Den ansökta summan för en typ får inte överskrida möjligt belopp för den typen (Solceller)';
gtc_assert(FortnoxAPI::isPersonCapTaxAmountTooHigh($perTypeMsg) === false, 'per-typ-fel är inte person-tak');
gtc_assert(FortnoxAPI::isPerTypeTaxAmountTooHigh($perTypeMsg) === true, 'per-typ-fel går fortfarande till per-typ');

// Retry-väg: person-tak-fel ska skriva det klampade beloppet, inte samma okapade.
$puts = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$puts, $msg) {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1891',
            'OrderRows' => [
                ['Price' => 200000, 'Total' => 200000, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1'],
                ['Price' => 120000, 'Total' => 120000, 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'DeliveredQuantity' => '1'],
            ],
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [['Id' => 99, 'ReferenceNumber' => '1891', 'CustomerName' => 'Kund']]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 99,
            'CustomerName' => 'Kund',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'TEST 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'SOLARCELLS', 'AskedAmount' => 30000],
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 30000],
            ],
        ]];
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $puts[] = $body;
        $sum = 0;
        foreach ($body['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
            $sum += (int) ($amount['AskedAmount'] ?? 0);
        }
        if (count($puts) === 1 && $sum > 50000) {
            throw new Exception($msg);
        }
        return ['TaxReduction' => ['Id' => 99]];
    }
    return [];
};

// Force an uncapped first write by temporarily asking sync with tracking toward raw
// (people=1 so plan already caps — first write should already be 50000 without error).
$api = new FortnoxAPI('Jarfalla');
$synced = $api->syncKopTaxReduction(
    [
        'fortnox_order' => '1891',
        'fastighetsbeteckning' => 'TEST 1:1',
        'huvudsokande' => 'Kund',
        'personnummer' => '198001011234',
    ],
    [
        ['Price' => 200000, 'Total' => 200000, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1'],
    ],
    [
        ['Price' => 200000, 'Total' => 200000, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1'],
        ['Price' => 120000, 'Total' => 120000, 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'DeliveredQuantity' => '1'],
    ],
    ['Order' => ['DocumentNumber' => '1891', 'TotalToPay' => 100000, 'TaxReduction' => 60000]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
gtc_assert(empty($synced['error']), 'synken lyckas, fel: ' . (string) ($synced['error'] ?? ''));
gtc_assert((int) ($synced['asked'] ?? 0) === 50000, 'synkad ansökan är 50 000, fick ' . (int) ($synced['asked'] ?? 0));
gtc_assert($puts !== [], 'minst en skrivning skedde');
$lastSum = 0;
foreach ($puts[count($puts) - 1]['TaxReduction']['TaxReductionAmounts'] ?? [] as $amount) {
    $lastSum += (int) ($amount['AskedAmount'] ?? 0);
}
gtc_assert($lastSum === 50000, 'sista skrivningen är 50 000, fick ' . $lastSum);

// Offert delar samma konstant.
$calc = jeab_offert_calc(['sokande' => 1, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0, 'kundpris' => 70000]);
gtc_assert((float) ($calc['gt_cap'] ?? 0) === 50000.0, 'Offert gt_cap är 50 000 för en sökande');
$calc2 = jeab_offert_calc(['sokande' => 2, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0, 'kundpris' => 70000]);
gtc_assert((float) ($calc2['gt_cap'] ?? 0) === 100000.0, 'Offert gt_cap är 100 000 för två sökande');

echo "fortnox green tech person cap: ok\n";
