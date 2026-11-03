<?php

declare(strict_types=1);

/**
 * Bekräfta-kalkylen återanvänder Offert-formeln och följer med till Fortnox-raderna.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function kk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$empty = jeab_ordrar_kalkyl_from_input(['pris' => '110000', 'b14' => '20']);
kk_assert($empty === null, 'utan kalkyl_present sparas ingen kalkyl');

$parsed = jeab_ordrar_kalkyl_from_input([
    'kalkyl_present' => '1',
    'b14' => '18',
    'b3' => '4',
    'sokande' => '2',
    'b15' => '1',
    'laddare' => '1',
]);
kk_assert(is_array($parsed), 'kalkyl_present ger en kalkyl');
kk_assert($parsed === ['b14' => 18, 'b3' => 4, 'sokande' => 2, 'b15' => 1, 'laddare' => 1], 'kalkylfält tolkas som heltal');

$unchecked = jeab_ordrar_kalkyl_from_input([
    'kalkyl_present' => '1',
    'b14' => '0',
    'b3' => '0',
    'sokande' => '0',
]);
kk_assert($unchecked['sokande'] === 1 && $unchecked['b15'] === 0 && $unchecked['laddare'] === 0, 'saknade kryssrutor är av och sökande är minst 1');

$daho = [
    'kind' => 'sol',
    'kundpris' => 110000,
    'pris' => 110000,
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'inkop_lines' => [
        ['title' => 'Solpaneler', 'qty' => 16, 'sku' => 'PANEL'],
        ['title' => 'Batterimodul', 'qty' => 2, 'sku' => 'MOD'],
    ],
    'medsokande' => [['name' => 'Med Sökande', 'personnummer' => '198001011234']],
];
$base = jeab_offert_fortnox_kop_rows('sol', 110000, '', $daho);
kk_assert($base[0]['price'] === 100926 && $base[1]['price'] === 64041 && $base[2]['price'] === 5102, 'Daho utan sparad kalkyl är oförändrad');

$over = $daho;
$over['kalkyl'] = ['b14' => 20, 'b3' => 4, 'sokande' => 1, 'b15' => 0, 'laddare' => 1];
$changed = jeab_offert_fortnox_kop_rows('sol', 110000, '', $over);
kk_assert($changed[0]['price'] !== $base[0]['price'] || $changed[1]['price'] !== $base[1]['price'], 'sparad kalkyl ändrar Fortnox-raderna');
$overCalc = jeab_offert_calc(jeab_offert_input_from_purchase($over, 'sol'));
kk_assert((int) $overCalc['b14'] === 20 && (int) $overCalc['b3'] === 4 && (int) $overCalc['sokande'] === 1, 'sparad kalkyl vinner över inköpsrader och medsökande');
kk_assert((int) $overCalc['b15'] === 0 && (int) $overCalc['laddare'] === 1, 'sparad kalkyl styr TP2 och elbilsladdare');
kk_assert(
    $changed[0]['price'] + $changed[1]['price'] + $changed[2]['price'] === (int) round((float) $overCalc['i15']),
    'ändrade rader summerar till kalkylens total'
);

$payload = jeab_ordrar_fortnox_payload($over + [
    'personnummer' => '198001011234',
    'huvudsokande' => 'Daho Test',
    'fastighetsbeteckning' => 'TEST 1:1',
    'company' => 'jarfalla',
]);
kk_assert(($payload['kalkyl']['b14'] ?? 0) === 20 && ($payload['kalkyl']['laddare'] ?? 0) === 1, 'Fortnox-payload tar med kalkylen');

$solHtml = jeab_ordrar_kalkyl_html($daho);
foreach (['Paneler', 'Batterimoduler', 'Antal sökande', 'System', 'name="sigen_typ"', 'Elbilsladdare', 'data-js="offert"', 'name="kalkyl_present"', 'name="b14"', 'name="b3"', 'name="sokande"', 'name="b15"', 'name="laddare"', 'Batteri', 'Solpaneler', 'Schablon', 'Att betala'] as $needle) {
    kk_assert(str_contains($solHtml, $needle), 'sol-kalkyl innehåller ' . $needle);
}
kk_assert(!preg_match('/data-js="offert"[^>]*\shidden/', $solHtml), 'sol-kalkylen visas');

$boxHtml = jeab_ordrar_kalkyl_html(['kind' => 'laddbox', 'pris' => 12000]);
kk_assert(str_contains($boxHtml, ' hidden'), 'kalkylen är dold när produkten inte är sol');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
kk_assert(str_contains($page, 'jeab_ordrar_kalkyl_html($open)'), 'Bekräfta bäddar in kalkylen');
$offertPage = (string) file_get_contents(dirname(__DIR__) . '/src/pages/offert.php');
kk_assert(str_contains($offertPage, 'jeab_offert_daily_controls_html($calc)'), 'kalkyl-steget använder samma kontroller');

echo "ordrar kalkyl bekrafta: ok\n";
