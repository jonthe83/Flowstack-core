<?php

declare(strict_types=1);

/**
 * Fakturering Att betala ska vara samma krona som Kalkyl.
 * Order 1877: Batteri 131845 + Schablon 4077 blev 70 002 för att ansökan
 * drog en krona per sökande under floor(underlag × 50 %).
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function pay_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function pay_sum_asked(array $splits): int
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

$purchase = [
    'kind' => 'batteri',
    'kundpris' => 70000,
    'pris' => 70000,
    'kalkyl' => ['sokande' => 2, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
];
$calc = jeab_offert_calc(jeab_offert_input_from_purchase($purchase, 'batteri'));
$want = jeab_offert_kalkyl_att_betala($calc, 'batteri');
pay_assert($want === 70000, 'kalkyl att betala för 70 000 är 70 000, fick ' . $want);

$rows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $purchase);
pay_assert(count($rows) === 2, 'batteri har två rader');
pay_assert($rows[0]['price'] === 131845 && $rows[0]['desc'] === 'Batteri', 'batteriraden är 131845, fick ' . $rows[0]['price']);
pay_assert($rows[0]['hw'] === 'STORAGESELFPRODUCEDELECTRICITY', 'batteri är lagring egenprod');
pay_assert($rows[1]['price'] === 4077 && $rows[1]['desc'] === 'Schablon', 'schablon är 4077, fick ' . $rows[1]['price']);
pay_assert($rows[1]['hw'] === null, 'schablon är övrig kostnad');

$deduction = jeab_offert_logical_gt_deduction($rows, 2);
pay_assert($deduction === 65922, 'möjlig grön teknik är 65 922, fick ' . $deduction);
pay_assert(jeab_offert_rows_att_betala($rows, 2) === 70000, 'raderna ger att betala 70 000, fick ' . jeab_offert_rows_att_betala($rows, 2));

$fxRows = [];
foreach ($rows as $i => $row) {
    $fxRows[] = [
        'RowId' => $i + 1,
        'Description' => $row['desc'],
        'Price' => $row['price'],
        'Total' => $row['price'],
        'DeliveredQuantity' => '1',
        'HouseWork' => $row['hw'] !== null,
        'HouseWorkType' => $row['hw'] ?? 'OTHERCOSTS',
    ];
}
$bases = FortnoxAPI::houseworkBases($fxRows);
$splits = FortnoxAPI::greenTechSplitsFromTypes(FortnoxAPI::greenTechPossibleByType($bases), 2);
pay_assert(pay_sum_asked($splits) === 65922, 'två sökande delar hela 65 922, fick ' . pay_sum_asked($splits));
$oldAsked = 2 * max(1, (int) floor(131845 * 0.5 / 2) - 1);
pay_assert($oldAsked === 65920, 'gamla kronan per person var 65 920');
pay_assert(135922 - $oldAsked === 70002, 'gamla ansökan gav att betala 70 002');

$stale = jeab_ordrar_gt_sync_plan($fxRows, $fxRows, 65920, 2, true, false);
pay_assert($stale['update'] === true && $stale['capped'] === false, '65 920 mot 65 922 skrivs upp');
pay_assert($stale['asked'] === 65922, 'uppdaterad ansökan är 65 922, fick ' . $stale['asked']);
$shown = jeab_ordrar_fortnox_with_asked([
    'Order' => [
        'TotalToPay' => 70002,
        'TaxReduction' => 65920,
        'OrderRows' => $fxRows,
    ],
], 65920, (int) $stale['asked']);
pay_assert((float) ($shown['Order']['TotalToPay'] ?? 0) === 70000.0, 'att betala blir 70 000 när ansökan höjs');
pay_assert(jeab_ordrar_fortnox_att_betala($shown) === jeab_ordrar_fortnox_money(70000), 'visad att betala är 70 000 kr');

$exact = jeab_ordrar_gt_sync_plan($fxRows, $fxRows, 65922, 2, true, false);
pay_assert($exact['update'] === false && $exact['asked'] === 65922, 'exakt möjlig skattereduktion skrivs inte om');

$manual = jeab_ordrar_gt_sync_plan($fxRows, $fxRows, 50000, 2, true, true, 50000);
pay_assert($manual['update'] === false && $manual['capped'] === true && $manual['asked'] === 50000, 'manuellt tak 50 000 höjs inte');

$oneOff = [
    'kind' => 'batteri',
    'kundpris' => 45000,
    'pris' => 45000,
    'kalkyl' => ['sokande' => 2, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
];
$oneCalc = jeab_offert_calc(jeab_offert_input_from_purchase($oneOff, 'batteri'));
$oneRows = jeab_offert_fortnox_kop_rows('batteri', 45000, '', $oneOff);
$oneWant = jeab_offert_kalkyl_att_betala($oneCalc, 'batteri');
pay_assert(jeab_offert_rows_att_betala($oneRows, 2) === $oneWant, '45 000-kalkylen och raderna ger samma att betala, fick ' . jeab_offert_rows_att_betala($oneRows, 2) . ' mot ' . $oneWant);

$solOff = [
    'kind' => 'sol',
    'kundpris' => 80000,
    'pris' => 80000,
    'kalkyl' => ['sokande' => 2, 'b14' => 20, 'b3' => 4, 'b15' => 1, 'laddare' => 0],
];
$solCalc = jeab_offert_calc(jeab_offert_input_from_purchase($solOff, 'sol'));
$solRows = jeab_offert_fortnox_kop_rows('sol', 80000, '', $solOff);
$solWant = jeab_offert_kalkyl_att_betala($solCalc, 'sol');
pay_assert($solWant === 80000, 'sol-kalkylens att betala är kundpriset');
pay_assert(jeab_offert_rows_att_betala($solRows, 2) === $solWant, 'solraderna ger samma att betala som kalkyl, fick ' . jeab_offert_rows_att_betala($solRows, 2));

$capped = [
    'kind' => 'batteri',
    'kundpris' => 70000,
    'pris' => 70000,
    'kalkyl' => ['sokande' => 1, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
];
$capCalc = jeab_offert_calc(jeab_offert_input_from_purchase($capped, 'batteri'));
$capRows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $capped);
$capWant = jeab_offert_kalkyl_att_betala($capCalc, 'batteri');
pay_assert($capWant === (int) round((float) $capCalc['h31'] - (float) $capCalc['h30']), 'taket använder summa minus grön teknik');
pay_assert(jeab_offert_rows_att_betala($capRows, 1) === $capWant, 'en sökande med tak ger samma att betala, fick ' . jeab_offert_rows_att_betala($capRows, 1) . ' mot ' . $capWant);

echo "ordrar fakturering kalkyl pay: ok\n";
