<?php

declare(strict_types=1);

/**
 * Hässelby strandväg 47 / Fortnox 1872 (sol + batteri, två ägare).
 * Raderna var Kalkylens: Batteri 156 632 (Lagring), Solpaneler 79 280
 * (Solceller), Schablon 7 295. Ansökan låg kvar från äldre rader
 * (Batteri 139 632, Sol 79 270): 34 908 + 5 945 per person = 81 706,
 * Att betala 161 501 i stället för Kundpris 153 000. Ordrar hade
 * fortnox_gt_capped satt av en tidigare synk, så Spara höjde aldrig.
 *
 * Väntat: raderna är Kalkylens, ansökan blir hela möjliga 90 208
 * (39 158 + 5 946 per person), Att betala 153 000. Ett tak på rader som
 * inte är Kalkylens höjs fortfarande inte.
 *
 * Mocken håller Fortnox-reglerna: ansökan per typ ≤ floor(underlag × sats),
 * ≤ 50 000 per person, typ måste ha husarbetsrad, typ på rad får inte ha
 * summan 0, ReservedQuantity/Total är endast läsbara, sista ansökan kan inte
 * raderas, POST för person som redan har ansökan nekas.
 */

$tmp = sys_get_temp_dir() . '/jeab-gt-follows-kalkyl-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

@mkdir($tmp . '/rot', 0775, true);
@mkdir($tmp . '/fortnox', 0775, true);
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-jarfalla'],
], JSON_UNESCAPED_UNICODE));

function fk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

const FK_L = 'STORAGESELFPRODUCEDELECTRICITY';
const FK_S = 'SOLARCELLS';
const FK_NAMES = [FK_L => 'Lagring egenproducerad el', FK_S => 'Solceller', 'CHARGINGSTATIONELECTRICVEHICLE' => 'Laddningspunkt elfordon'];

function fk_rows(int $battery, int $solar, int $schablon): array
{
    return [
        ['RowId' => 54131, 'ArticleNumber' => '8436575273277', 'Description' => 'Batteri', 'Price' => $battery, 'Total' => $battery, 'HouseWork' => true, 'HouseWorkType' => FK_L, 'HouseWorkHoursToReport' => 24, 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'ReservedQuantity' => 0, 'VAT' => 25],
        ['RowId' => 54132, 'ArticleNumber' => '7', 'Description' => 'Solpaneler', 'Price' => $solar, 'Total' => $solar, 'HouseWork' => true, 'HouseWorkType' => FK_S, 'HouseWorkHoursToReport' => 16, 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'ReservedQuantity' => 0, 'VAT' => 25],
        ['RowId' => 54133, 'ArticleNumber' => '8436575273276', 'Description' => 'Schablon', 'Price' => $schablon, 'Total' => $schablon, 'HouseWork' => false, 'HouseWorkType' => 'OTHERCOSTS', 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'ReservedQuantity' => 0, 'VAT' => 25],
    ];
}

function fk_by_type(array $taxes): array
{
    $out = [];
    foreach ($taxes as $tax) {
        foreach ($tax['TaxReductionAmounts'] as $a) {
            $out[$a['WorkType']] = ($out[$a['WorkType']] ?? 0) + (int) $a['AskedAmount'];
        }
    }
    ksort($out);
    return $out;
}

function fk_hw_types(array $rows): array
{
    $t = [];
    foreach ($rows as $r) {
        if (!empty($r['HouseWork']) && isset(FK_NAMES[(string) ($r['HouseWorkType'] ?? '')])) {
            $t[(string) $r['HouseWorkType']] = true;
        }
    }
    return $t;
}

function fk_check(array $taxes, array $rows, bool $perPerson): void
{
    if ($taxes === []) {
        return;
    }
    $present = fk_hw_types($rows);
    $possible = FortnoxAPI::greenTechPossibleByType(FortnoxAPI::houseworkBases($rows));
    $asked = fk_by_type($taxes);
    foreach (array_keys($present) as $type) {
        if (($asked[$type] ?? 0) <= 0) {
            throw new Exception('Den totala ansökta summan för en typ får inte vara 0 (' . FK_NAMES[$type] . ')');
        }
    }
    foreach ($asked as $type => $sum) {
        if (!isset($present[$type])) {
            throw new Exception('Ogiltig husarbetestyp. Det finns ingen husarbetesrad på dokumentet med typen (' . FK_NAMES[$type] . ')');
        }
        if ($sum > (int) ($possible[$type] ?? 0)) {
            throw new Exception('Den ansökta summan för en typ får inte överskrida möjligt belopp för den typen (' . FK_NAMES[$type] . ')');
        }
    }
    if ($perPerson) {
        foreach ($taxes as $tax) {
            $sum = array_sum(array_map(static fn ($a) => (int) $a['AskedAmount'], $tax['TaxReductionAmounts']));
            if ($sum > 50000) {
                throw new Exception('Angivet belopp gör att totalen överskrider 50000 kr.');
            }
        }
    }
}

function fk_order(array $state): array
{
    $total = 0.0;
    foreach ($state['rows'] as $r) {
        $total += (float) ($r['Total'] ?? 0);
    }
    $tax = array_sum(fk_by_type($state['taxes']));
    return ['Order' => [
        'DocumentNumber' => '1872',
        'CustomerNumber' => '200',
        'VATIncluded' => true,
        'Total' => $total,
        'TaxReduction' => $tax,
        'TaxReductionType' => $state['type'],
        'TotalToPay' => $total - $tax,
        'Comments' => 'STOCKHOLM BLOMKRUKAN 17',
        'OrderRows' => $state['rows'],
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders&referencenumber=1872',
    ]];
}

function fk_tax(string $id, string $name, string $pnr, array $amounts): array
{
    return ['Id' => $id, 'CustomerName' => $name, 'SocialSecurityNumber' => $pnr, 'PropertyDesignation' => 'STOCKHOLM BLOMKRUKAN 17', 'ReferenceNumber' => '1872', 'TaxReductionAmounts' => $amounts];
}

function fk_stub(array &$state): void
{
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$state): array {
        $state['log'][] = $method . ' ' . preg_replace('/\?.*$/', '', $path);
        if ($method === 'GET' && str_contains($path, '/customers')) {
            return ['Customers' => [['CustomerNumber' => '200', 'Name' => 'Karl Joachim Jan Adolfsson', 'OrganisationNumber' => '196501011234', 'Active' => true]]];
        }
        if ($method === 'PUT' && str_contains($path, '/customers/')) {
            return ['Customer' => ['CustomerNumber' => '200']];
        }
        if ($method === 'GET' && str_contains($path, '/orders/')) {
            return fk_order($state);
        }
        if ($method === 'PUT' && str_contains($path, '/orders/')) {
            $order = $body['Order'] ?? [];
            if (isset($order['TaxReductionType'])) {
                if ($order['TaxReductionType'] === 'none' && fk_hw_types($order['OrderRows'] ?? $state['rows']) !== []) {
                    throw new Exception('Skattereduktionstyp får inte vara none om det finns rader med husarbete på ordern.');
                }
                $state['type'] = (string) $order['TaxReductionType'];
            }
            if (array_key_exists('OrderRows', $order)) {
                $known = [];
                foreach ($state['rows'] as $old) {
                    $known[(string) ($old['RowId'] ?? '')] = $old;
                }
                $rows = [];
                foreach ($order['OrderRows'] as $r) {
                    if (array_key_exists('ReservedQuantity', $r) || array_key_exists('Total', $r) || array_key_exists('ContributionValue', $r)) {
                        throw new Exception('Fältet är endast läsbart.');
                    }
                    if (isset($r['RowId']) && !isset($known[(string) $r['RowId']])) {
                        throw new Exception('Raden ' . $r['RowId'] . ' finns inte på ordern.');
                    }
                    if (isset($r['RowId']) && array_key_exists('HouseWorkType', $r) && ($r['HouseWorkType'] === '' || $r['HouseWorkType'] === null)) {
                        $r['HouseWorkType'] = $known[(string) $r['RowId']]['HouseWorkType'] ?? '';
                    }
                    $r['Total'] = (float) ($r['Price'] ?? 0) * (float) ($r['DeliveredQuantity'] ?? 1);
                    if (!isset($r['RowId'])) {
                        $state['rowseq'] = ($state['rowseq'] ?? 60000) + 1;
                        $r['RowId'] = $state['rowseq'];
                    }
                    $rows[] = $r;
                }
                if ($state['type'] !== 'none') {
                    fk_check($state['taxes'], $rows, false);
                }
                $state['rows'] = $rows;
            }
            return fk_order($state);
        }
        if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
            return ['TaxReductions' => array_values(array_map(static fn ($t) => array_diff_key($t, ['TaxReductionAmounts' => true]), $state['taxes']))];
        }
        if ($method === 'GET' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
            return ['TaxReduction' => $state['taxes'][rawurldecode($m[1])]];
        }
        if ($method === 'DELETE' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
            if (count($state['taxes']) === 1 && $state['type'] !== 'none') {
                throw new Exception('Minst en skattereduktion måste vara kopplat till en faktura som är märkt för husarbete, använd PUT för att isället uppdatera.');
            }
            unset($state['taxes'][rawurldecode($m[1])]);
            return [];
        }
        if (($method === 'PUT' || $method === 'POST') && str_contains($path, '/taxreductions')) {
            if ($state['type'] === 'none') {
                throw new Exception('Man kan inte skapa en skattereduktionsrad på ett dokument utan skattereduktion.');
            }
            $tb = $body['TaxReduction'] ?? [];
            $next = $state['taxes'];
            if ($method === 'PUT' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
                $id = rawurldecode($m[1]);
                $next[$id]['TaxReductionAmounts'] = $tb['TaxReductionAmounts'];
            } else {
                foreach ($state['taxes'] as $t) {
                    if ($t['SocialSecurityNumber'] === (string) ($tb['SocialSecurityNumber'] ?? '')) {
                        throw new Exception('Det finns redan en skattereduktion för personen på dokumentet, använd PUT för att istället uppdatera.');
                    }
                }
                $state['seq'] = ($state['seq'] ?? 20000) + 1;
                $id = (string) $state['seq'];
                $next[$id] = fk_tax($id, (string) $tb['CustomerName'], (string) $tb['SocialSecurityNumber'], $tb['TaxReductionAmounts']);
            }
            fk_check($next, $state['rows'], true);
            $state['taxes'] = $next;
            return ['TaxReduction' => $next[$id]];
        }
        throw new Exception('oväntat Fortnox-anrop ' . $method . ' ' . $path);
    };
}

$owners = [
    ['name' => 'Karl Joachim Jan Adolfsson', 'personnummer' => '19650101-1234'],
    ['name' => 'Ann-Charlotte Adolfsson', 'personnummer' => '19660202-2345'],
];
$karl = ['196501011234', $owners[0]['name']];
$ann = ['196602022345', $owners[1]['name']];
$purchase = [
    'reminder_id' => 23,
    'fortnox_order' => '1872',
    'company' => 'jarfalla',
    'kind' => 'sol',
    'pris' => 153000,
    'kundpris' => 153000,
    'install_what' => 'Solceller, batteri, AC laddare',
    'vaxelriktare' => 'Sigenergy 20 kW EC',
    'kwh' => '27',
    'kalkyl' => ['b14' => 14, 'b3' => 3, 'sokande' => 2, 'b15' => 0, 'laddare' => 1],
    'fastighetsbeteckning' => 'STOCKHOLM BLOMKRUKAN 17',
    'huvudsokande' => $owners[0]['name'],
    'personnummer' => $owners[0]['personnummer'],
    'owners' => $owners,
    'medsokande' => [$owners[1]],
    'fortnox_gt_asked' => 81706,
    'fortnox_gt_capped' => true,
];
$stale = [
    '18635' => fk_tax('18635', $karl[1], $karl[0], [['WorkType' => FK_S, 'AskedAmount' => 5945], ['WorkType' => FK_L, 'AskedAmount' => 34908]]),
    '18636' => fk_tax('18636', $ann[1], $ann[0], [['WorkType' => FK_S, 'AskedAmount' => 5945], ['WorkType' => FK_L, 'AskedAmount' => 34908]]),
];

function fk_person(array $state, string $name): array
{
    foreach ($state['taxes'] as $t) {
        if ($t['CustomerName'] === $name) {
            $o = [];
            foreach ($t['TaxReductionAmounts'] as $a) {
                $o[$a['WorkType']] = (int) $a['AskedAmount'];
            }
            ksort($o);
            return $o;
        }
    }
    return [];
}

function fk_input(int $schablon, int $battery = 156632, int $solar = 79280): array
{
    return [
        'id' => 23,
        'intent' => 'save',
        'fx_rowid' => ['54131', '54132', '54133', ''],
        'fx_article' => ['8436575273277', '7', '8436575273276', ''],
        'fx_desc' => ['Batteri', 'Solpaneler', 'Schablon', ''],
        'fx_hw' => [FK_L, FK_S, 'OTHERCOSTS', ''],
        'fx_qty' => ['1', '1', '1', ''],
        'fx_price' => [(string) $battery, (string) $solar, (string) $schablon, ''],
    ];
}

/** @return array{saved:?array,error:string,state:array} */
function fk_save(array $purchase, array $taxes, array $input, array $rows): array
{
    $state = ['rows' => $rows, 'taxes' => $taxes, 'type' => 'green', 'log' => []];
    jeab_purchase_put($purchase + ['fortnox_order_rows' => $rows]);
    fk_stub($state);
    $saved = null;
    $error = '';
    try {
        $saved = jeab_ordrar_save_fakturering($input);
        $error = (string) ($saved['fortnox_gt_error'] ?? '');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
    return ['saved' => $saved, 'error' => $error, 'state' => $state];
}

// Kalkylen för ordern.
$kalkyl = jeab_ordrar_fortnox_formula_order_rows($purchase);
$prices = array_map(static fn ($r) => (int) $r['Price'], $kalkyl);
fk_assert($prices === [156632, 79280, 7296], 'Kalkylens rader 156 632 / 79 280 / 7 296, fick ' . implode(' / ', $prices));
fk_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases(fk_rows(156632, 79280, 7296)), 2) === 90208, 'möjligt 78 316 + 11 892 = 90 208');
fk_assert(156632 + 79280 + 7296 - 90208 === 153000, 'Kalkylens rader ger Att betala 153 000');
fk_assert(156632 + 79280 + 7295 - 90208 === 152999, 'gamla Schablon 7 295 hade gett 152 999');
fk_assert(jeab_ordrar_gt_follows_kalkyl($purchase, fk_rows(156632, 79280, 7295)), 'raderna med Schablon 7 295 räknas som Kalkylens');
fk_assert(jeab_ordrar_gt_kalkyl_delta($purchase, fk_rows(156632, 79280, 7295)) === -1, 'Schablon 7 295 ligger en krona under Kalkylen');
$k = jeab_ordrar_kalkyl_logical($purchase);
fk_assert($k !== null && $k['att_betala'] === 153000 && $k['people'] === 2, 'Kalkylen: Att betala 153 000 för 2 sökande');
fk_assert(!jeab_ordrar_gt_follows_kalkyl($purchase, fk_rows(150000, 79280, 7296)), 'ändrat batteri är inte Kalkylens');
fk_assert(!jeab_ordrar_gt_follows_kalkyl($purchase, fk_rows(156632, 79280, 9000)), 'ändrad Schablon är inte Kalkylens');

// Planen: följer Kalkyl trots gammalt tak.
$rows = fk_rows(156632, 79280, 7296);
$plan = jeab_ordrar_gt_sync_plan($rows, $rows, 81706, 2, true, true, 81706, true);
fk_assert($plan['update'] === true && $plan['capped'] === false && $plan['asked'] === 90208, 'Kalkylens rader: 81 706 höjs till 90 208');
$manual = jeab_ordrar_gt_sync_plan($rows, $rows, 50000, 2, true, true, 50000, false);
fk_assert($manual['update'] === false && $manual['capped'] === true && $manual['asked'] === 50000, 'utan Kalkyl-rader: manuellt tak 50 000 höjs inte');

// 1) Fakturering Spara med Kalkylens rader (Schablon 7 296): ett sparande.
$r = fk_save($purchase, $stale, fk_input(7296), fk_rows(156632, 79280, 7295));
fk_assert($r['error'] === '', 'H47 Spara utan fel, fick: ' . $r['error']);
fk_assert(array_map(static fn ($x) => (int) $x['Price'], $r['state']['rows']) === [156632, 79280, 7296], 'raderna 156 632 / 79 280 / 7 296');
fk_assert(fk_person($r['state'], $karl[1]) === [FK_S => 5946, FK_L => 39158], 'Karl: Lagring 39 158 + Solceller 5 946');
fk_assert(fk_person($r['state'], $ann[1]) === [FK_S => 5946, FK_L => 39158], 'Ann-Charlotte: Lagring 39 158 + Solceller 5 946');
fk_assert(count($r['state']['taxes']) === 2 && isset($r['state']['taxes']['18635'], $r['state']['taxes']['18636']), 'samma två ansökningar (PUT, inga nya)');
fk_assert(!array_filter($r['state']['log'], static fn ($l) => str_starts_with($l, 'DELETE') || $l === 'POST /taxreductions'), 'ingen DELETE eller POST');
$o = fk_order($r['state'])['Order'];
fk_assert((int) $o['TaxReduction'] === 90208 && (int) $o['TotalToPay'] === 153000, 'Fortnox: TaxReduction 90 208, TotalToPay 153 000');
fk_assert(($r['saved']['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(153000), 'Ordrar Att betala 153 000 kr, fick ' . ($r['saved']['fortnox_att_betala'] ?? ''));
fk_assert((int) ($r['saved']['fortnox_gt_asked'] ?? 0) === 90208 && empty($r['saved']['fortnox_gt_capped']), 'sparad ansökan 90 208, inte capped');

// 2) Samma Spara igen: exakt träff, ingen skrivning av ansökan.
$again = fk_save($r['saved'], $r['state']['taxes'], fk_input(7296), $r['state']['rows']);
fk_assert($again['error'] === '' && !array_filter($again['state']['log'], static fn ($l) => str_starts_with($l, 'PUT /taxreductions')), 'andra Spara skriver inte om ansökan');

// 3) Rader som inte är Kalkylens med gammalt tak: höjs inte (tidigare beteende).
$r = fk_save($purchase, $stale, fk_input(7296, 150000), fk_rows(156632, 79280, 7295));
fk_assert($r['error'] === '' && fk_by_type($r['state']['taxes']) === [FK_S => 11890, FK_L => 69816], 'ändrade rader med tak: ansökan oförändrad 81 706, fick: ' . $r['error'] . ' ' . json_encode(fk_by_type($r['state']['taxes'])));

// 4) Kalkyl-vägen (Bekräfta → updateKopOrder): raderna skrivs och ansökan följer.
$state = ['rows' => fk_rows(139632, 79270, 7295), 'taxes' => $stale, 'type' => 'green', 'log' => []];
jeab_purchase_put($purchase + ['fortnox_order_rows' => $state['rows']]);
fk_stub($state);
$err = '';
try {
    $saved = jeab_ordrar_create_fortnox(['id' => 23]);
    $err = (string) ($saved['fortnox_gt_error'] ?? '');
} catch (Throwable $e) {
    $err = $e->getMessage();
}
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
fk_assert($err === '', 'Kalkyl-vägen utan fel, fick: ' . $err);
fk_assert(array_map(static fn ($x) => (int) $x['Price'], $state['rows']) === [156632, 79280, 7296], 'Kalkyl-vägen: Kalkylens rader');
fk_assert(fk_by_type($state['taxes']) === [FK_S => 11892, FK_L => 78316], 'Kalkyl-vägen: ansökan följer, 78 316 + 11 892');
$o = fk_order($state)['Order'];
fk_assert((int) $o['TotalToPay'] === 153000, 'Kalkyl-vägen: TotalToPay 153 000');
fk_assert((int) ($saved['fortnox_gt_asked'] ?? 0) === 90208 && empty($saved['fortnox_gt_capped']), 'Kalkyl-vägen: Ordrar 90 208, inte capped');

// 5) En ägare: 50 000-taket per person håller.
$solo = $purchase;
$solo['owners'] = [$owners[0]];
$solo['medsokande'] = [];
$solo['kalkyl']['sokande'] = 1;
$one = ['18635' => fk_tax('18635', $karl[1], $karl[0], [['WorkType' => FK_S, 'AskedAmount' => 5945], ['WorkType' => FK_L, 'AskedAmount' => 34908]])];
$r = fk_save($solo, $one, fk_input(7296), fk_rows(156632, 79280, 7295));
$sum = array_sum(fk_person($r['state'], $karl[1]));
fk_assert($r['error'] === '' && $sum <= 50000, 'en ägare: högst 50 000, fick ' . $sum . ' ' . $r['error']);


// 6) Fakturering = Kalkyl. Formuläret skickas som det står (Schablon 7 295):
//    Kalkylens rättning ger 7 296 och Att betala 153 000.
$r = fk_save($purchase, $stale, fk_input(7295), fk_rows(156632, 79280, 7295));
fk_assert($r['error'] === '', 'H47 med 7 295 i formuläret: utan fel, fick: ' . $r['error']);
fk_assert(array_map(static fn ($x) => (int) $x['Price'], $r['state']['rows']) === [156632, 79280, 7296], 'Schablon rättas till Kalkylens 7 296');
fk_assert((int) fk_order($r['state'])['Order']['TotalToPay'] === jeab_ordrar_kalkyl_logical($purchase)['att_betala'], 'Fakturering = Kalkyl: 153 000');
fk_assert(fk_person($r['state'], $karl[1]) === [FK_S => 5946, FK_L => 39158] && fk_person($r['state'], $ann[1]) === [FK_S => 5946, FK_L => 39158], 'per person 39 158 + 5 946');

// 7) Batteriordrar: Klippingvägen 65 000 (batteri + laddbox), Ljusterö 70 000 och en ägare.
const FK_C = 'CHARGINGSTATIONELECTRICVEHICLE';
function fk_brows(array $lines): array
{
    $out = [];
    foreach ($lines as $i => [$desc, $price, $hw]) {
        $out[] = ['RowId' => 55000 + $i, 'ArticleNumber' => '', 'Description' => $desc, 'Price' => $price, 'Total' => $price, 'HouseWork' => $hw !== null, 'HouseWorkType' => $hw ?? 'OTHERCOSTS', 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'VAT' => 25];
    }
    return $out;
}
function fk_binput(array $rows, array $prices): array
{
    $in = ['id' => 23, 'intent' => 'save', 'fx_rowid' => [], 'fx_article' => [], 'fx_desc' => [], 'fx_hw' => [], 'fx_qty' => [], 'fx_price' => []];
    for ($i = 0; $i < 4; $i++) {
        $r = $rows[$i] ?? null;
        $in['fx_rowid'][] = $r ? (string) $r['RowId'] : '';
        $in['fx_article'][] = '';
        $in['fx_desc'][] = $r ? $r['Description'] : '';
        $in['fx_hw'][] = $r ? (string) $r['HouseWorkType'] : '';
        $in['fx_qty'][] = $r ? '1' : '';
        $in['fx_price'][] = $r ? (string) ($prices[$i] ?? $r['Price']) : '';
    }
    return $in;
}
$battery = static fn (int $price, array $own, int $sok) => [
    'reminder_id' => 23, 'fortnox_order' => '1872', 'company' => 'jarfalla', 'kind' => 'batteri',
    'pris' => $price, 'kundpris' => $price, 'install_what' => '',
    'kalkyl' => ['sokande' => $sok, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
    'fastighetsbeteckning' => 'STOCKHOLM BLOMKRUKAN 17',
    'huvudsokande' => $own[0]['name'], 'personnummer' => $own[0]['personnummer'],
    'owners' => $own, 'medsokande' => array_slice($own, 1),
];
$cases = [
    // namn, köp, rader, ansökan före, väntat Att betala, väntat per person
    ['Klippingvägen 65 000', array_merge($battery(65000, $owners, 2), ['kalkyl' => ['sokande' => 2]]),
        fk_brows([['Batteri', 94428, FK_L], ['Schablon', 3786, null], ['Sigenergy Laddbox', 28000, FK_C]]),
        ['1' => fk_tax('1', $karl[1], $karl[0], [['WorkType' => FK_L, 'AskedAmount' => 23606], ['WorkType' => FK_C, 'AskedAmount' => 7000]]),
         '2' => fk_tax('2', $ann[1], $ann[0], [['WorkType' => FK_L, 'AskedAmount' => 23606], ['WorkType' => FK_C, 'AskedAmount' => 7000]])],
        65000, [FK_C => 7000, FK_L => 23607]],
    ['Ljusterö 70 000', $battery(70000, $owners, 2),
        fk_brows([['Batteri', 131845, FK_L], ['Schablon', 4077, null]]),
        ['1' => fk_tax('1', $karl[1], $karl[0], [['WorkType' => FK_L, 'AskedAmount' => 32960]]),
         '2' => fk_tax('2', $ann[1], $ann[0], [['WorkType' => FK_L, 'AskedAmount' => 32960]])],
        70000, [FK_L => 32961]],
    ['en ägare 70 000', $battery(70000, [$owners[0]], 1),
        fk_brows([['Batteri', 131845, FK_L], ['Schablon', 4077, null]]),
        ['1' => fk_tax('1', $karl[1], $karl[0], [['WorkType' => FK_L, 'AskedAmount' => 40000]])],
        85922, [FK_L => 50000]],
];
foreach ($cases as [$name, $buy, $rows0, $taxes0, $pay, $each]) {
    $k = jeab_ordrar_kalkyl_logical($buy);
    fk_assert($k !== null && $k['att_betala'] === $pay, $name . ': Kalkylens Att betala ' . $pay . ', fick ' . ($k['att_betala'] ?? 'null'));
    $buy['fortnox_gt_asked'] = array_sum(fk_by_type($taxes0));
    $buy['fortnox_gt_capped'] = true;
    $r = fk_save($buy, $taxes0, fk_binput($rows0, []), $rows0);
    fk_assert($r['error'] === '', $name . ': Spara utan fel, fick: ' . $r['error']);
    $o = fk_order($r['state'])['Order'];
    fk_assert((int) $o['TotalToPay'] === $k['att_betala'], $name . ': Fakturering = Kalkyl ' . $k['att_betala'] . ', Fortnox ' . $o['TotalToPay']);
    fk_assert(($r['saved']['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money($pay), $name . ': Ordrar visar ' . $pay);
    foreach ($r['state']['taxes'] as $t) {
        $mine = [];
        foreach ($t['TaxReductionAmounts'] as $a) {
            $mine[$a['WorkType']] = (int) $a['AskedAmount'];
        }
        ksort($mine);
        fk_assert(abs(array_sum($mine) - array_sum($each)) <= 1 && array_sum($mine) <= 50000, $name . ': ' . $t['CustomerName'] . ' ' . json_encode($mine));
    }
}
// Schablon en krona fel på Ljusterö rättas också.
$lj = $cases[1];
$r = fk_save($lj[1], $lj[3], fk_binput($lj[2], [131845, 4076]), $lj[2]);
fk_assert($r['error'] === '' && (int) $r['state']['rows'][1]['Price'] === 4077 && (int) fk_order($r['state'])['Order']['TotalToPay'] === 70000, 'Ljusterö: Schablon 4 076 i formuläret blir 4 077, Att betala 70 000');
// Ett ändrat batteripris är inte Kalkylens: ingen rättning av Schablonen.
$r = fk_save($lj[1], $lj[3], fk_binput($lj[2], [130000, 4076]), $lj[2]);
fk_assert($r['error'] === '' && (int) $r['state']['rows'][1]['Price'] === 4076, 'ändrat batteri: Schablon lämnas som skriven');

echo "fortnox gt follows kalkyl: ok\n";
