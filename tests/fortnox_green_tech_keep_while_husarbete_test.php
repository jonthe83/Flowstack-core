<?php

declare(strict_types=1);

/**
 * Fakturering får aldrig nolla/ta bort grön-teknik-ansökan medan
 * orderraderna fortfarande är märkta för husarbete (grön teknik).
 *
 * (a) apply/replace med tomma splits → bygg om eller behåll.
 * (b) Fortnox «använd PUT» + putRelisted misslyckas → behåll, kasta inte
 *     «Behåll en rad med husarbete» när raderna redan har husarbete.
 */

$tmp = sys_get_temp_dir() . '/jeab-gt-keep-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function gtk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$gtRow = [
    'Price' => 131845,
    'Total' => 131845,
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    'DeliveredQuantity' => '1',
];
$zeroPriceRow = [
    'Price' => 0,
    'Total' => 0,
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    'DeliveredQuantity' => '1',
];

// Plan: noll underlag men kvarvarande GT-rad → ingen update mot DELETE.
$planKeep = jeab_ordrar_gt_sync_plan([$gtRow], [$zeroPriceRow], 50000, 1, true, false);
gtk_assert($planKeep['update'] === false, '(plan) update false när mål 0 men husarbete kvar');
gtk_assert((int) $planKeep['asked'] === 50000, '(plan) behåller currentAsked 50000, fick ' . (int) $planKeep['asked']);

$rebuilt = FortnoxAPI::greenTechRebuildSplitsIfEmpty(
    [$gtRow],
    [[]],
    1,
    0
);
gtk_assert(FortnoxAPI::greenTechHasPositiveAmounts($rebuilt), '(rebuild) tomma splits byggs om från underlag');
$rebuiltSum = (int) array_sum(FortnoxAPI::greenTechPerTypeFromSplits($rebuilt));
gtk_assert($rebuiltSum === 50000, '(rebuild) person-tak 50000, fick ' . $rebuiltSum);

$emptyKeep = FortnoxAPI::greenTechRebuildSplitsIfEmpty(
    [$zeroPriceRow],
    [[]],
    1,
    0
);
gtk_assert(FortnoxAPI::greenTechHasPositiveAmounts($emptyKeep) === false, '(rebuild) utan underlag förblir tomt');

// --- (a) sync med nollade priser men GT-rad kvar: ingen DELETE, ingen UI-fel ---
$deletesA = 0;
$putsA = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$deletesA, &$putsA, $zeroPriceRow): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1891',
            'TaxReduction' => 50000,
            'TotalToPay' => 85922,
            'OrderRows' => [$zeroPriceRow],
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        return ['TaxReductions' => [['Id' => 77, 'ReferenceNumber' => '1891', 'CustomerName' => 'Håkansson']]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 77,
            'CustomerName' => 'Håkansson',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'LJUSTERÖ 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 50000],
            ],
        ]];
    }
    if ($method === 'DELETE' && str_contains($path, '/taxreductions/')) {
        $deletesA++;
        throw new Exception('Minst en skattereduktion måste vara kopplat till en faktura som är märkt för husarbete, använd PUT för att istället uppdatera.');
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $putsA++;
        return ['TaxReduction' => ['Id' => 77]];
    }
    return [];
};

$apiA = new FortnoxAPI('Jarfalla');
$syncedA = $apiA->syncKopTaxReduction(
    ['fortnox_order' => '1891', 'fastighetsbeteckning' => 'LJUSTERÖ 1:1', 'fortnox_gt_asked' => 50000],
    [$gtRow],
    [$zeroPriceRow],
    ['Order' => ['DocumentNumber' => '1891', 'TaxReduction' => 50000, 'OrderRows' => [$zeroPriceRow]]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);

gtk_assert(empty($syncedA['error']), '(a) sync utan gt_error, fick: ' . (string) ($syncedA['error'] ?? ''));
gtk_assert($deletesA === 0, '(a) ingen DELETE mot Fortnox');
gtk_assert($putsA === 0, '(a) ingen PUT med nollade splits');
gtk_assert(($syncedA['update'] ?? null) === false, '(a) ingen update när typen fortfarande finns på raden');
gtk_assert((int) ($syncedA['asked'] ?? 0) === 50000, '(a) behåller ansökan 50000, fick ' . (int) ($syncedA['asked'] ?? 0));

// --- (b) must-update + putRelisted misslyckas (inga Id) medan husarbete finns → behåll ---
$mustPut = 'Minst en skattereduktion måste vara kopplat till en faktura som är märkt för husarbete, använd PUT för att istället uppdatera.';
$deletesB = 0;
$putsB = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$deletesB, &$putsB, $gtRow, $mustPut): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1891',
            'TaxReduction' => 50000,
            'OrderRows' => [$gtRow],
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        // Lista utan Id → putRelisted hittar inget att PUT:a
        return ['TaxReductions' => [['ReferenceNumber' => '1891', 'CustomerName' => 'Håkansson']]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 88,
            'CustomerName' => 'Håkansson',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'LJUSTERÖ 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 50000],
            ],
        ]];
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $putsB++;
        throw new Exception($mustPut);
    }
    if ($method === 'POST' && str_contains($path, '/taxreductions')) {
        throw new Exception($mustPut);
    }
    if ($method === 'DELETE' && str_contains($path, '/taxreductions/')) {
        $deletesB++;
        throw new Exception($mustPut);
    }
    return [];
};

// Force path (b): hydrate with Id, then PUT throws must-update, putRelisted gets empty list (no Id on list).
// First GET taxreductions? returns no Id — hydrate from list won't get Id for sync's initial hydrate either!
// Need hydrate to succeed with Id, then apply PUT fails with must-update, then putRelisted re-lists without Id.

$taxGetCount = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$deletesB, &$putsB, &$taxGetCount, $gtRow, $mustPut): array {
    if ($method === 'GET' && str_contains($path, '/orders/')) {
        return ['Order' => [
            'DocumentNumber' => '1891',
            'TaxReduction' => 50000,
            'OrderRows' => [$gtRow],
            '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders',
        ]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
        $taxGetCount++;
        if ($taxGetCount === 1) {
            return ['TaxReductions' => [['Id' => 88, 'ReferenceNumber' => '1891', 'CustomerName' => 'Håkansson']]];
        }
        // Relist för putRelisted: utan Id → false
        return ['TaxReductions' => [['ReferenceNumber' => '1891', 'CustomerName' => 'Håkansson']]];
    }
    if ($method === 'GET' && str_contains($path, '/taxreductions/')) {
        return ['TaxReduction' => [
            'Id' => 88,
            'CustomerName' => 'Håkansson',
            'SocialSecurityNumber' => '198001011234',
            'PropertyDesignation' => 'LJUSTERÖ 1:1',
            'TaxReductionAmounts' => [
                ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 65922],
            ],
        ]];
    }
    if ($method === 'PUT' && str_contains($path, '/taxreductions/')) {
        $putsB++;
        throw new Exception($mustPut);
    }
    if ($method === 'DELETE' && str_contains($path, '/taxreductions/')) {
        $deletesB++;
        throw new Exception($mustPut);
    }
    return [];
};

$apiB = new FortnoxAPI('Jarfalla');
$syncedB = $apiB->syncKopTaxReduction(
    ['fortnox_order' => '1891', 'fastighetsbeteckning' => 'LJUSTERÖ 1:1', 'fortnox_gt_asked' => 65922],
    [$gtRow],
    [$gtRow],
    ['Order' => ['DocumentNumber' => '1891', 'TaxReduction' => 65922, 'OrderRows' => [$gtRow]]]
);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);

$errB = (string) ($syncedB['error'] ?? '');
gtk_assert($errB === '' || !str_contains($errB, 'Behåll en rad med husarbete'), '(b) ingen «Behåll en rad»-UI-fel, fick: ' . $errB);
gtk_assert($deletesB === 0, '(b) ingen DELETE');
// Antingen lyckad rebuild-PUT via recover, eller behåll hydrated summa utan fel.
gtk_assert(empty($syncedB['error']), '(b) sync utan error, fick: ' . $errB);
gtk_assert((int) ($syncedB['asked'] ?? 0) >= 50000, '(b) ansökan minst person-tak/behållen, fick ' . (int) ($syncedB['asked'] ?? 0));

echo "ALL PASS\n";
