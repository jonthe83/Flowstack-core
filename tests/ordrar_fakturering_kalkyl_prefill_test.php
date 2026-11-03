<?php

declare(strict_types=1);

/** Ingen Fortnox-order: Fakturering förifylls från Kalkylen (Gnejsvägen 15A). */

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';

function kp_assert(bool $ok, string $m): void
{
    if (!$ok) { fwrite(STDERR, "FAIL {$m}\n"); exit(1); }
    echo "OK  {$m}\n";
}

$row = [
    'company' => 'jarfalla', 'kind' => 'sol', 'pris' => 199000, 'price' => 199000,
    'huvudsokande' => 'Test Testsson', 'personnummer' => '19780305-0000', 'kundpris' => 199000,
    'owner_name' => ['Test Testsson'], 'owner_pnr' => ['19780305-0000'],
    'fastighetsbeteckning' => 'TEST 1:1', 'install_what' => 'Solpaneler',
    'kalkyl' => ['b14' => 34, 'b3' => 3, 'sokande' => 2, 'b15' => 1, 'laddare' => 1],
];
$kalkyl = jeab_ordrar_kalkyl_logical($row);
kp_assert($kalkyl !== null, 'Kalkylen finns');
$data = jeab_ordrar_v3_fakturering_data($row);
kp_assert($data['source'] === 'kalkyl', 'källa är kalkyl');
$sum = 0;
foreach ($data['lines'] as $i => $l) {
    if ($l['price'] !== '') { $sum += (int) $l['price']; }
    if (isset($kalkyl['rows'][$i])) {
        kp_assert((int) $l['price'] === (int) $kalkyl['rows'][$i]['price'], 'rad ' . $i . ' = Kalkylens ' . $kalkyl['rows'][$i]['desc']);
        kp_assert($l['qty'] === '1', 'antal 1 på rad ' . $i);
        kp_assert(($kalkyl['rows'][$i]['hw'] ?? null) === null ? $l['housework'] === '' : $l['housework'] !== '', 'husarbete följer rad ' . $i);
    }
}
kp_assert($data['pay'] !== '' && (int) preg_replace('/\D/', '', $data['pay']) === $kalkyl['att_betala'], 'Att betala = Kalkylens ' . $kalkyl['att_betala']);
$noKalkyl = jeab_ordrar_v3_fakturering_data(['kind' => 'sol']);
kp_assert($noKalkyl['source'] === '' && $noKalkyl['lines'][0]['price'] === '', 'utan kalkyl: tomma rader');
