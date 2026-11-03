<?php

declare(strict_types=1);

/**
 * H-FX-1: öresresten när à-pris × antal inte går jämnt upp ska följa med
 * även när ingen rad har antal 1.
 */

$tmp = sys_get_temp_dir() . '/jeab-fx-ore-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function fxore_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fxore_total(array $rows): int
{
    $sum = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sum += (int) ($row['Price'] ?? 0) * (int) ($row['DeliveredQuantity'] ?? 0);
    }
    return $sum;
}

$api = new FortnoxAPI('Jarfalla');

$split = $api->kopRowsFromLogical([
    ['desc' => 'Batteri', 'price' => 100, 'qty' => 3, 'article' => 'battery'],
]);
fxore_assert(fxore_total($split) === 100, '100 kr på 3 st blir 100 kr, inte 99');
$qtys = array_map(static fn (array $row): int => (int) ($row['DeliveredQuantity'] ?? 0), $split);
fxore_assert(in_array(1, $qtys, true), 'resten ligger på en rad med antal 1');
$hasQty1 = false;
foreach ($split as $row) {
    if ((int) ($row['DeliveredQuantity'] ?? 0) === 1) {
        fxore_assert((int) ($row['Price'] ?? 0) === 34, 'sista enheten bär enhetspris plus rest');
        $hasQty1 = true;
    }
}
fxore_assert($hasQty1, 'en rad med antal 1 skapades');

$already = $api->kopRowsFromLogical([
    ['desc' => 'Framkörning', 'price' => 45, 'qty' => 1],
    ['desc' => 'Batteri', 'price' => 100, 'qty' => 3, 'hw' => 'STORAGESELFPRODUCEDELECTRICITY'],
]);
fxore_assert(fxore_total($already) === 145, 'varje rad behåller sin egen summa');
fxore_assert(count($already) === 3, 'bara raden vars antal inte går jämnt upp delas');
fxore_assert((int) ($already[0]['DeliveredQuantity'] ?? 0) === 1 && (int) ($already[0]['Price'] ?? 0) === 45, 'en annan rad med antal 1 tar inte emot resten');
fxore_assert((int) ($already[1]['DeliveredQuantity'] ?? 0) === 2 && (int) ($already[1]['Price'] ?? 0) === 33, 'antal−1 behåller à-priset');
fxore_assert((int) ($already[2]['DeliveredQuantity'] ?? 0) === 1 && (int) ($already[2]['Price'] ?? 0) === 34, 'radens egen rest ligger på delen med antal 1');
fxore_assert((int) ($already[1]['HouseWorkHoursToReport'] ?? 0) === 24, 'timmarna ligger kvar på första delen');
fxore_assert(!isset($already[2]['HouseWorkHoursToReport']), 'den nya delen rapporterar inte timmarna en gång till');

$both = $api->kopRowsFromLogical([
    ['desc' => 'Batteri', 'price' => 100, 'qty' => 3, 'hw' => 'STORAGESELFPRODUCEDELECTRICITY'],
    ['desc' => 'Solceller', 'price' => 100, 'qty' => 3, 'hw' => 'SOLARCELLS'],
]);
fxore_assert(count($both) === 4, 'två rader med rest delas var för sig');
fxore_assert(fxore_total(array_slice($both, 0, 2)) === 100, 'batteriet blir 100 kr, inte 99');
fxore_assert(fxore_total(array_slice($both, 2, 2)) === 100, 'solcellerna blir 100 kr, inte 101');
fxore_assert((int) ($both[0]['Price'] ?? 0) === 33 && (int) ($both[0]['DeliveredQuantity'] ?? 0) === 2, 'batteriets första del är 33 × 2');
fxore_assert((int) ($both[1]['Price'] ?? 0) === 34 && (int) ($both[1]['DeliveredQuantity'] ?? 0) === 1, 'batteriets rest är 34, inte den samlade resten');
fxore_assert((int) ($both[2]['Price'] ?? 0) === 33 && (int) ($both[2]['DeliveredQuantity'] ?? 0) === 2, 'solcellernas första del är 33 × 2');
fxore_assert((int) ($both[3]['Price'] ?? 0) === 34 && (int) ($both[3]['DeliveredQuantity'] ?? 0) === 1, 'solcellernas egen rest är 34');
fxore_assert((int) ($both[0]['HouseWorkHoursToReport'] ?? 0) === 24, 'batteriets 24 timmar ligger på första delen');
fxore_assert(!isset($both[1]['HouseWorkHoursToReport']), 'batteriets nya del har inga timmar');
fxore_assert((int) ($both[2]['HouseWorkHoursToReport'] ?? 0) === 16, 'solcellernas 16 timmar ligger på första delen');
fxore_assert(!isset($both[3]['HouseWorkHoursToReport']), 'solcellernas nya del har inga timmar');
fxore_assert((string) ($both[2]['HouseWorkType'] ?? '') === 'SOLARCELLS' && (string) ($both[3]['HouseWorkType'] ?? '') === 'SOLARCELLS', 'båda soldelarna behåller husarbetstypen');

$even = $api->kopRowsFromLogical([
    ['desc' => 'Batteri', 'price' => 99, 'qty' => 3],
]);
fxore_assert(count($even) === 1 && (int) ($even[0]['Price'] ?? 0) === 33 && (int) ($even[0]['DeliveredQuantity'] ?? 0) === 3, 'jämn uppdelning lämnar en rad');
fxore_assert(fxore_total($even) === 99, 'jämn uppdelning behåller beloppet');

echo "fortnox ore remainder tests done\n";
