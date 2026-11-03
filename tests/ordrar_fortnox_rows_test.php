<?php

declare(strict_types=1);

/**
 * Laddbox- och batteri-rader till Fortnox återanvänder Offert-formeln.
 * Uppdatera hämtar ordern. Den räknar inte om raderna lokalt.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function fx_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$laddPrice = 12500;
$what = 'Easee Home';
$ladd = jeab_offert_fortnox_kop_rows('laddbox', $laddPrice, $what);
fx_assert(count($ladd) === 2, 'laddbox har två rader');
fx_assert($ladd[0]['article'] === 'charging', 'laddbox-artikel är charging');
fx_assert($ladd[0]['desc'] === $what, 'laddbox-benämning är vad som ska installeras');
fx_assert($ladd[0]['hw'] === 'CHARGINGSTATIONELECTRICVEHICLE', 'laddbox-husarbete är laddningspunkt');
fx_assert($ladd[0]['price'] === ($laddPrice - 45) * 2, 'paketet är (kundpris − 45) × 2, fick ' . $ladd[0]['price']);
fx_assert($ladd[1]['desc'] === 'Framkörning' && $ladd[1]['price'] === 45 && $ladd[1]['hw'] === null, 'framkörning är 45');

$api = new FortnoxAPI('Jarfalla');
$materialized = $api->kopOrderRows('laddbox', $laddPrice, $what);
fx_assert(count($materialized) === 2, 'FortnoxAPI::kopOrderRows ger två laddbox-rader');
fx_assert((int) ($materialized[0]['Price'] ?? 0) === $ladd[0]['price'], 'materialiserat paketpris följer offert-raden');
fx_assert((int) ($materialized[1]['Price'] ?? 0) === 45, 'materialiserad framkörning är 45');
fx_assert((string) ($materialized[0]['HouseWorkType'] ?? '') === 'CHARGINGSTATIONELECTRICVEHICLE', 'materialiserat husarbete');
fx_assert((string) ($materialized[0]['Description'] ?? '') === $what, 'materialiserad benämning är vad');

$batPrice = 70000;
$purchase = ['kind' => 'batteri', 'kundpris' => $batPrice, 'pris' => $batPrice];
$bat = jeab_offert_fortnox_kop_rows('batteri', $batPrice, 'Sigenergy', $purchase);
$calc = jeab_offert_calc(jeab_offert_input_from_purchase($purchase + ['kundpris' => $batPrice], 'batteri'));
$utan = (int) round((float) $calc['h28']);
$schablon = (int) round((float) $calc['h29']);
$summa = (int) round((float) $calc['h31']);
if (abs(((float) $calc['h28'] + (float) $calc['h29']) - (float) $calc['h31']) < 0.5) {
    $schablon = $summa - $utan;
}
fx_assert(count($bat) === 2, 'batteri har två rader');
fx_assert($bat[0]['article'] === 'battery' && $bat[0]['desc'] === 'Batteri', 'batteri-raden heter Batteri');
fx_assert($bat[0]['hw'] === 'STORAGESELFPRODUCEDELECTRICITY', 'batteri-husarbete är lagring');
fx_assert($bat[0]['price'] === $utan, 'batteri-pris är h28, fick ' . $bat[0]['price'] . ' väntade ' . $utan);
fx_assert($bat[1]['article'] === 'material' && $bat[1]['desc'] === 'Schablon', 'schablon-raden');
fx_assert($bat[1]['price'] === $schablon, 'schablon är h29, fick ' . $bat[1]['price'] . ' väntade ' . $schablon);

$sol = jeab_offert_fortnox_kop_rows('sol', 180000, '', ['kind' => 'sol', 'kundpris' => 180000]);
fx_assert(count($sol) === 3 && $sol[1]['article'] === 'solar' && $sol[1]['hw'] === 'SOLARCELLS', 'sol-raderna är oförändrade');

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
$dahoRows = jeab_offert_fortnox_kop_rows('sol', 110000, '', $daho);
$dahoCalc = jeab_offert_calc(jeab_offert_input_from_purchase($daho, 'sol'));
fx_assert(count($dahoRows) === 3, 'sol har tre rader');
fx_assert($dahoRows[0]['desc'] === 'Batteri' && $dahoRows[0]['price'] === 100926, 'Daho batteri är i10, fick ' . $dahoRows[0]['price']);
fx_assert($dahoRows[1]['desc'] === 'Solpaneler' && $dahoRows[1]['article'] === 'solar' && $dahoRows[1]['price'] === 64041, 'Daho solpaneler är i11, fick ' . $dahoRows[1]['price']);
fx_assert($dahoRows[2]['desc'] === 'Schablon' && $dahoRows[2]['price'] === 5102, 'Daho schablon är resten av totalen, fick ' . $dahoRows[2]['price']);
fx_assert($dahoRows[0]['price'] + $dahoRows[1]['price'] + $dahoRows[2]['price'] === (int) round((float) $dahoCalc['i15']), 'raderna summerar till kalkylens total');
fx_assert((int) round((float) $dahoCalc['i15']) === 170069, 'Daho total är 170069');
fx_assert((int) round((float) $dahoCalc['i16']) === 60069, 'Daho grön teknik är 60069');
fx_assert((int) round((float) $dahoCalc['i14']) === 110000, 'Daho att betala är kundpris 110000');
$dahoApi = (new FortnoxAPI('Jarfalla'))->kopOrderRows('sol', 110000, '', $daho);
fx_assert((int) ($dahoApi[0]['Price'] ?? 0) === 100926 && (string) ($dahoApi[0]['Description'] ?? '') === 'Batteri', 'kopOrderRows materialiserar batteri');
fx_assert((int) ($dahoApi[1]['Price'] ?? 0) === 64041 && (string) ($dahoApi[1]['HouseWorkType'] ?? '') === 'SOLARCELLS', 'kopOrderRows materialiserar solpaneler');
fx_assert((int) ($dahoApi[2]['Price'] ?? 0) === 5102 && (string) ($dahoApi[2]['Description'] ?? '') === 'Schablon', 'kopOrderRows materialiserar schablon');

$boxRow = [
    'kind' => 'laddbox',
    'pris' => $laddPrice,
    'kundpris' => 1,
    'install_what' => $what,
    'install_note' => 'Nyckel under mattan',
    'huvudsokande' => 'Test Testsson',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TEST 1:1',
    'company' => 'jarfalla',
];
$payload = jeab_ordrar_fortnox_payload($boxRow);
fx_assert((int) $payload['price'] === $laddPrice && (int) $payload['kundpris'] === $laddPrice, 'laddbox-payload använder Bekräfta-pris, inte gammalt kundpris');
fx_assert((string) $payload['install_what'] === $what, 'laddbox-payload tar med vad');

$batRow = $boxRow;
$batRow['kind'] = 'batteri';
$batRow['pris'] = $batPrice;
$batRow['kundpris'] = 1;
$batRow['install_what'] = 'Sigenergy';
$batPayload = jeab_ordrar_fortnox_payload($batRow);
fx_assert((int) $batPayload['price'] === $batPrice, 'batteri-payload använder Bekräfta-pris');
fx_assert((string) $batPayload['install_what'] === 'Sigenergy', 'batteri-payload tar med vad');

$fromFortnox = [
    ['ArticleNumber' => 'CH-99', 'Description' => 'Från Fortnox', 'Price' => 19910, 'HouseWork' => true, 'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'DeliveredQuantity' => '1'],
    ['ArticleNumber' => 'FK-45', 'Description' => 'Framkörning', 'Price' => 45, 'DeliveredQuantity' => '1'],
];
$boxRow['fortnox_order'] = '4242';
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($fromFortnox): array {
    return ['Order' => ['DocumentNumber' => '4242', 'OrderRows' => $fromFortnox, 'TotalToPay' => 10045]];
};
$captured = jeab_ordrar_fortnox_capture_rows($boxRow);
fx_assert(($captured['fortnox_order_rows'][0]['ArticleNumber'] ?? '') === 'CH-99', 'skapad order sparar Fortnox-raderna');
fx_assert(($captured['fortnox_order_rows'][0]['Description'] ?? '') === 'Från Fortnox', 'sparad rad är hämtningen, inte en omräkning');
fx_assert(($captured['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(10045), 'att betala följer med från Fortnox, fick ' . (string) ($captured['fortnox_att_betala'] ?? ''));

$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function (): array {
    return ['Order' => ['DocumentNumber' => '4242', 'OrderRows' => []]];
};
$fallback = jeab_ordrar_fortnox_capture_rows($boxRow);
fx_assert((int) ($fallback['fortnox_order_rows'][0]['Price'] ?? 0) === ($laddPrice - 45) * 2, 'tom hämtning fylls med samma offert-formel');
fx_assert((string) ($fallback['fortnox_order_rows'][0]['Description'] ?? '') === $what, 'formeln använder vad');
fx_assert((string) ($fallback['fortnox_order_rows'][1]['Description'] ?? '') === 'Framkörning', 'formeln har framkörning');

$solRow = $boxRow;
$solRow['kind'] = 'sol';
$solRow['kundpris'] = 110000;
$solRow['pris'] = 110000;
$solRow['vaxelriktare'] = 'Sigenergy 12 kW TP2';
$solRow['inkop_lines'] = $daho['inkop_lines'];
$solRow['medsokande'] = $daho['medsokande'];
$solRow['fortnox_order'] = '9100';
$solRow['fortnox_order_rows'] = [['ArticleNumber' => 'SKA-INTE']];
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($dahoApi): array {
    return ['Order' => ['DocumentNumber' => '9100', 'OrderRows' => $dahoApi, 'TotalToPay' => 110000, 'Total' => 170069, 'TaxReduction' => 60069]];
};
$solCaptured = jeab_ordrar_fortnox_capture_rows($solRow);
fx_assert(($solCaptured['fortnox_order_rows'][0]['Description'] ?? '') === 'Batteri', 'sol-capture sparar batteriraden');
fx_assert((int) ($solCaptured['fortnox_order_rows'][1]['Price'] ?? 0) === 64041, 'sol-capture sparar solpaneler');
fx_assert(($solCaptured['fortnox_order_rows'][2]['Description'] ?? '') === 'Schablon', 'sol-capture sparar schablon');
fx_assert(($solCaptured['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(110000), 'sol att betala följer Fortnox');
$solOpen = $solCaptured;
$solOpen['company'] = 'jarfalla';
$solOpen['reminder_id'] = 0;
jeab_ordrar_external_live(false);
$solHtml = jeab_ordrar_fakturering_html($solOpen, 'jarfalla', 9, null);
fx_assert(str_contains($solHtml, '9100'), 'fakturering visar sol-ordernumret');
fx_assert(str_contains($solHtml, 'Batteri') && str_contains($solHtml, 'Solpaneler') && str_contains($solHtml, 'Schablon'), 'fakturering visar de tre kalkylraderna');
fx_assert(str_contains($solHtml, '100926') && str_contains($solHtml, '64041') && str_contains($solHtml, '5102'), 'fakturering visar kalkylpriserna');
fx_assert(!str_contains($solHtml, 'Ingen Fortnox-order ännu'), 'skapad sol-order döljer tom-texten');

$stored = [
    ['ArticleNumber' => 'SPARAD-1', 'Description' => 'Sparad laddbox', 'Price' => 19910, 'HouseWork' => true, 'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'DeliveredQuantity' => '1'],
    ['ArticleNumber' => 'SPARAD-2', 'Description' => 'Sparad framkörning', 'Price' => 45, 'DeliveredQuantity' => '1'],
];
$open = [
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'fortnox_order' => '4242',
    'fortnox_order_rows' => $stored,
    'fortnox_att_betala' => '10 045 kr',
    'huvudsokande' => 'Test Testsson',
    'reminder_id' => 0,
];
$fetches = 0;
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use (&$fetches): array {
    $fetches++;
    return ['Order' => ['OrderRows' => [
        ['ArticleNumber' => 'LIVE-1', 'Description' => 'Live från Fortnox', 'Price' => 22222, 'HouseWork' => true, 'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'DeliveredQuantity' => '2'],
        ['ArticleNumber' => 'LIVE-2', 'Description' => 'Live framkörning', 'Price' => 45, 'DeliveredQuantity' => '1'],
    ], 'TotalToPay' => 11111]];
};
jeab_ordrar_external_live(false);
$quiet = jeab_ordrar_fakturering_html($open, 'jarfalla', 7, null);
fx_assert($fetches === 0, 'utan Uppdatera hämtas inte Fortnox');
fx_assert(str_contains($quiet, 'data-fx-source="snapshot"'), 'visningen använder sparade rader');
fx_assert(str_contains($quiet, 'SPARAD-1') && str_contains($quiet, 'Sparad laddbox'), 'sparad artikel och benämning visas');
fx_assert(str_contains($quiet, 'Laddningspunkt elfordon'), 'husarbete visas som laddningspunkt');
fx_assert(!str_contains($quiet, 'LIVE-1') && !str_contains($quiet, 'Live från Fortnox'), 'utan Uppdatera byggs raderna inte om');

$bare = $open;
unset($bare['fortnox_order_rows'], $bare['fortnox_att_betala']);
$beforeBare = $fetches;
jeab_ordrar_external_live(false);
$bareHtml = jeab_ordrar_fakturering_html($bare, 'jarfalla', 7, null);
fx_assert($fetches === $beforeBare, 'saknad sparad rad hämtas inte förrän Uppdatera');
fx_assert(!str_contains($bareHtml, 'data-fx-source="fortnox"') && !str_contains($bareHtml, 'LIVE-1'), 'stängd order utan kopia visar inte en live-hämtning');
$bareV3 = jeab_ordrar_v3_fakturering_data($bare);
fx_assert($fetches === $beforeBare, 'v3 läser sparad data när kopian saknas');
fx_assert(($bareV3['source'] ?? '') !== 'fortnox', 'v3 markerar inte en live-hämtning utan Uppdatera');
jeab_ordrar_external_live(true);
$beforeBareLive = $fetches;
$bareLive = jeab_ordrar_v3_fakturering_data($bare);
fx_assert($fetches === $beforeBareLive + 1 && ($bareLive['source'] ?? '') === 'fortnox', 'v3 hämtar Fortnox en gång på Uppdatera');
jeab_ordrar_external_live(false);

jeab_ordrar_external_live(true);
$beforePull = $fetches;
$pulled = jeab_ordrar_fakturering_html($open, 'jarfalla', 7, null);
fx_assert($fetches === $beforePull + 1, 'Uppdatera hämtar Fortnox en gång');
fx_assert(str_contains($pulled, 'data-fx-source="fortnox"'), 'Uppdatera markerar Fortnox-hämtning');
fx_assert(str_contains($pulled, 'LIVE-1') && str_contains($pulled, 'Live från Fortnox'), 'Uppdatera byter till hämtade rader');
fx_assert(!str_contains($pulled, 'SPARAD-1'), 'Uppdatera skriver inte över med den sparade gissningen');
fx_assert(str_contains($pulled, 'value="2"'), 'antal följer Fortnox');

$ordrarPage = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
fx_assert(str_contains($ordrarPage, "refresh' => '1'") || str_contains($ordrarPage, "'refresh' => '1'"), 'Uppdatera-länken bär refresh=1');
$fakturering = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($fakturering, 'function jeab_ordrar_fakturering_html');
$end = strpos($fakturering, 'function jeab_ordrar_save_vad');
$body = $start !== false && $end !== false ? substr($fakturering, $start, $end - $start) : '';
fx_assert($body !== '' && str_contains($body, 'jeab_ordrar_fortnox_fetch_order'), 'Fakturering hämtar ordern');
fx_assert($body !== '' && !str_contains($body, 'jeab_ordrar_fortnox_formula_order_rows'), 'Fakturering räknar inte om offertformeln');

echo "OK  fortnox rader\n";
