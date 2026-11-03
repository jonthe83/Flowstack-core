<?php

declare(strict_types=1);

/**
 * Laddbox läggs till på en batteriorder som redan har grön teknik-ansökan.
 * Klippingvägen 19 / Fortnox 1892: två ägare, i dag Batteri 94 175 (Lagring)
 * och Schablon 2 912 med ansökan 23 543 + 23 544.
 *
 * Fortnox (live 7/10) låser två regler mot varandra:
 * (a) ansökan får inte ha en typ som saknar husarbetsrad: «Ogiltig
 *     husarbetestyp. Det finns ingen husarbetesrad på dokumentet med typen …»;
 * (b) orderrader får inte skrivas medan ansökan finns och en typ på raderna
 *     har summan 0: «Den totala ansökta summan för en typ får inte vara 0 …».
 * Dessutom nekas DELETE av sista ansökan på en husarbetsorder («använd PUT»).
 * Mocken nedan håller alla tre. Väntat för Kalkyl «Endast batteri» 65 000 med
 * laddbox 28 000: Batteri 94 428 + Laddbox 28 000 + Schablon 3 786 = 126 214,
 * grön teknik 47 214 + 14 000 = 61 214 (23 607 + 7 000 per person),
 * Att betala 65 000.
 */

$tmp = sys_get_temp_dir() . '/jeab-gt-new-type-' . getmypid();
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
    'Malmo' => ['refresh_token' => 'test-malmo'],
], JSON_UNESCAPED_UNICODE));

function nt_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

const NT_L = 'STORAGESELFPRODUCEDELECTRICITY';
const NT_C = 'CHARGINGSTATIONELECTRICVEHICLE';
const NT_NAMES = [
    NT_L => 'Lagring egenproducerad el',
    NT_C => 'Laddningspunkt elfordon',
    'SOLARCELLS' => 'Solceller',
];

function nt_rows(int $battery = 94175, int $schablon = 2912, int $laddbox = 0): array
{
    $rows = [
        ['RowId' => 54380, 'ArticleNumber' => '8436575273277', 'Description' => 'Batteri', 'Price' => $battery, 'Total' => $battery, 'HouseWork' => true, 'HouseWorkType' => NT_L, 'HouseWorkHoursToReport' => 24, 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'ReservedQuantity' => 0, 'VAT' => 25],
        ['RowId' => 54381, 'ArticleNumber' => '8436575273276', 'Description' => 'Schablon', 'Price' => $schablon, 'Total' => $schablon, 'HouseWork' => false, 'HouseWorkType' => 'OTHERCOSTS', 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'ReservedQuantity' => 0, 'VAT' => 25],
    ];
    if ($laddbox > 0) {
        $rows[] = ['RowId' => 54382, 'Description' => 'Sigenergy Laddbox', 'Price' => $laddbox, 'Total' => $laddbox, 'HouseWork' => true, 'HouseWorkType' => NT_C, 'HouseWorkHoursToReport' => 4, 'DeliveredQuantity' => '1', 'OrderedQuantity' => '1', 'VAT' => 25];
    }
    return $rows;
}

function nt_by_type(array $taxes): array
{
    $out = [];
    foreach ($taxes as $tax) {
        foreach ($tax['TaxReductionAmounts'] as $amount) {
            $out[$amount['WorkType']] = ($out[$amount['WorkType']] ?? 0) + (int) $amount['AskedAmount'];
        }
    }
    ksort($out);
    return $out;
}

function nt_hw_types(array $rows): array
{
    $types = [];
    foreach ($rows as $row) {
        if (!empty($row['HouseWork']) && isset(NT_NAMES[(string) ($row['HouseWorkType'] ?? '')])) {
            $types[(string) $row['HouseWorkType']] = true;
        }
    }
    return $types;
}

/** Regel (b) och totalen: när ordern skrivs medan ansökan finns. */
function nt_check_order(array $taxes, array $rows): void
{
    if ($taxes === []) {
        return;
    }
    $possible = FortnoxAPI::greenTechPossibleByType(FortnoxAPI::houseworkBases($rows));
    $asked = nt_by_type($taxes);
    foreach (array_keys(nt_hw_types($rows)) as $type) {
        if (($asked[$type] ?? 0) <= 0) {
            throw new Exception('Den totala ansökta summan för en typ får inte vara 0 (' . NT_NAMES[$type] . ')');
        }
    }
    if (array_sum($asked) > array_sum($possible)) {
        throw new Exception('Summan av raderna överskrider möjlig skattereduktion på fakturan.');
    }
}

/** Regel (a), möjligt per typ och 50 000 per person: när ansökan skrivs. */
function nt_check_tax(array $taxes, array $rows): void
{
    $present = nt_hw_types($rows);
    $possible = FortnoxAPI::greenTechPossibleByType(FortnoxAPI::houseworkBases($rows));
    foreach ($taxes as $tax) {
        $sum = 0;
        foreach ($tax['TaxReductionAmounts'] as $amount) {
            if (!isset($present[$amount['WorkType']])) {
                throw new Exception('Ogiltig husarbetestyp. Det finns ingen husarbetesrad på dokumentet med typen (' . NT_NAMES[$amount['WorkType']] . ')');
            }
            $sum += (int) $amount['AskedAmount'];
        }
        if ($sum > 50000) {
            throw new Exception('Angivet belopp gör att totalen överskrider 50000 kr.');
        }
    }
    foreach (nt_by_type($taxes) as $type => $sum) {
        if ($sum > (int) ($possible[$type] ?? 0)) {
            throw new Exception('Den ansökta summan för en typ får inte överskrida möjligt belopp för den typen (' . NT_NAMES[$type] . ')');
        }
    }
    nt_check_order($taxes, $rows);
}

function nt_order(array $state): array
{
    $total = 0.0;
    foreach ($state['rows'] as $row) {
        $total += (float) ($row['Total'] ?? 0);
    }
    $tax = array_sum(nt_by_type($state['taxes']));
    return ['Order' => [
        'DocumentNumber' => '1892',
        'CustomerNumber' => '100',
        'VATIncluded' => true,
        'Total' => $total,
        'TaxReduction' => $tax,
        'TaxReductionType' => $state['type'],
        'TotalToPay' => $total - $tax,
        'Comments' => 'UPPLANDS-BRO EKHAMMAR 4:492',
        'OrderRows' => $state['rows'],
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders&referencenumber=1892',
    ]];
}

function nt_tax(string $id, string $name, string $pnr, array $amounts): array
{
    return [
        'Id' => $id,
        'CustomerName' => $name,
        'SocialSecurityNumber' => $pnr,
        'PropertyDesignation' => 'UPPLANDS-BRO EKHAMMAR 4:492',
        'ReferenceNumber' => '1892',
        'TaxReductionAmounts' => $amounts,
    ];
}

/**
 * $state['mode']:
 *   unmark      'allow' | 'reject'  (TaxReductionType none med ansökan kvar)
 *   delete_last bool  Fortnox låter sista ansökan tas bort fast ordern är märkt (live: nej)
 *   fail_post   int   POST nummer n (räknat från 1) avvisas
 */
function nt_stub(array &$state): void
{
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$state): array {
        $state['log'][] = $method . ' ' . preg_replace('/\?.*$/', '', $path);
        if ($method === 'GET' && str_contains($path, '/customers')) {
            return ['Customers' => [['CustomerNumber' => '100', 'Name' => 'Nils Peter Mild', 'OrganisationNumber' => '195612103217', 'Active' => true]]];
        }
        if ($method === 'PUT' && str_contains($path, '/customers/')) {
            return ['Customer' => ['CustomerNumber' => '100']];
        }
        if ($method === 'GET' && str_contains($path, '/orders/')) {
            return nt_order($state);
        }
        if ($method === 'PUT' && str_contains($path, '/orders/')) {
            $order = $body['Order'] ?? [];
            $type = $state['type'];
            if (isset($order['TaxReductionType'])) {
                $type = (string) $order['TaxReductionType'];
                $unmark = $state['mode']['unmark'] ?? 'allow';
                if ($type === 'none' && $state['taxes'] !== [] && $unmark === 'reject') {
                    throw new Exception('Typ av skattereduktion kan inte ändras när det finns ansökningar.');
                }
                if ($type === 'none' && nt_hw_types($order['OrderRows'] ?? $state['rows']) !== []) {
                    throw new Exception('Skattereduktionstyp får inte vara none om det finns rader med husarbete på ordern.');
                }
                // Live 23:03: inte heller grön husarbetstyp på rad utan husarbete.
                foreach (($type === 'none' ? ($order['OrderRows'] ?? $state['rows']) : []) as $row) {
                    $rowType = (string) ($row['HouseWorkType'] ?? '');
                    $blocked = $rowType !== '' && ($rowType !== 'OTHERCOSTS' || ($state['mode']['none_othercosts'] ?? '') === 'reject');
                    if ($blocked) {
                        throw new Exception("Dokument med skattereduktionstypen 'none' får inte innehålla rader med husarbetestypen '" . $rowType . "'.");
                    }
                }
            }
            $rows = $state['rows'];
            if (array_key_exists('OrderRows', $order)) {
                $rows = [];
                $known = [];
                foreach ($state['rows'] as $old) {
                    $known[(string) ($old['RowId'] ?? '')] = $old;
                }
                foreach ($order['OrderRows'] as $i => $row) {
                    // Live 23:08: endast läsbara fält på en ny rad nekas.
                    if (array_key_exists('ReservedQuantity', $row) || array_key_exists('Total', $row) || array_key_exists('ContributionValue', $row)) {
                        throw new Exception('Fältet ' . (array_key_exists('ReservedQuantity', $row) ? 'ReservedQuantity' : 'Total') . ' är endast läsbart.');
                    }
                    if (isset($row['RowId']) && !isset($known[(string) $row['RowId']])) {
                        throw new Exception('Raden ' . $row['RowId'] . ' finns inte på ordern.');
                    }
                    // Live 23:06: tom husarbetstyp ignoreras, raden behåller sin typ.
                    if (isset($row['RowId']) && array_key_exists('HouseWorkType', $row) && ($row['HouseWorkType'] === '' || $row['HouseWorkType'] === null)) {
                        $row['HouseWorkType'] = $known[(string) $row['RowId']]['HouseWorkType'] ?? '';
                    }
                    $row['Total'] = (float) ($row['Price'] ?? 0) * (float) ($row['DeliveredQuantity'] ?? 1);
                    if (!isset($row['RowId'])) {
                        $state['rowseq'] = ($state['rowseq'] ?? 60000) + 1;
                        $row['RowId'] = $state['rowseq'];
                    }
                    $rows[] = $row;
                }
                // Regel (b) gäller rader med husarbete; utan husarbete på
                // raderna godtogs PUT:en live 22:58.
                if ($type !== 'none') {
                    nt_check_order($state['taxes'], $rows);
                }
            }
            $state['type'] = $type;
            $state['rows'] = $rows;
            return nt_order($state);
        }
        if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
            $list = [];
            foreach ($state['taxes'] as $tax) {
                $list[] = array_diff_key($tax, ['TaxReductionAmounts' => true]);
            }
            return ['TaxReductions' => $list];
        }
        if ($method === 'GET' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
            return ['TaxReduction' => $state['taxes'][rawurldecode($m[1])]];
        }
        if ($method === 'DELETE' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
            $id = rawurldecode($m[1]);
            // Live 22:58: nekas så länge ordern är märkt för skattereduktion,
            // även när raderna har husarbete av.
            if (count($state['taxes']) === 1 && $state['type'] !== 'none' && empty($state['mode']['delete_last'])) {
                throw new Exception('Minst en skattereduktion måste vara kopplat till en faktura som är märkt för husarbete, använd PUT för att isället uppdatera.');
            }
            unset($state['taxes'][$id]);
            return [];
        }
        if (($method === 'PUT' || $method === 'POST') && str_contains($path, '/taxreductions')) {
            if ($state['type'] === 'none') {
                throw new Exception('Man kan inte skapa en skattereduktionsrad på ett dokument utan skattereduktion.');
            }
            if ($method === 'POST') {
                // Regel (d): finns redan en ansökan för personen ska den
                // uppdateras med PUT, aldrig en ny POST.
                foreach ($state['taxes'] as $tax) {
                    if (($tax['SocialSecurityNumber'] ?? '') === (string) ($body['TaxReduction']['SocialSecurityNumber'] ?? '')) {
                        throw new Exception('Det finns redan en skattereduktion för personen på dokumentet, använd PUT för att istället uppdatera.');
                    }
                }
                $state['posts'] = ($state['posts'] ?? 0) + 1;
                if (($state['mode']['fail_post'] ?? 0) === $state['posts']) {
                    throw new Exception('Tillfälligt fel hos Fortnox.');
                }
            }
            $tb = $body['TaxReduction'] ?? [];
            $next = $state['taxes'];
            if ($method === 'PUT' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
                $id = rawurldecode($m[1]);
                $next[$id]['TaxReductionAmounts'] = $tb['TaxReductionAmounts'];
            } else {
                $state['seq'] = ($state['seq'] ?? 10) + 1;
                $id = (string) $state['seq'];
                $next[$id] = nt_tax($id, (string) $tb['CustomerName'], (string) $tb['SocialSecurityNumber'], $tb['TaxReductionAmounts']);
            }
            nt_check_tax($next, $state['rows']);
            $state['taxes'] = $next;
            return ['TaxReduction' => $next[$id]];
        }
        throw new Exception('oväntat Fortnox-anrop ' . $method . ' ' . $path);
    };
}

$owners = [
    ['name' => 'Nils Peter Mild', 'personnummer' => '19561210-3217'],
    ['name' => 'Rose-Marie Elisabeth Mild', 'personnummer' => '19590907-1069'],
];
$purchase = [
    'reminder_id' => 24,
    'fortnox_order' => '1892',
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'fastighetsbeteckning' => 'UPPLANDS-BRO EKHAMMAR 4:492',
    'huvudsokande' => 'Nils Peter Mild',
    'personnummer' => '19561210-3217',
    'owners' => $owners,
    'medsokande' => [$owners[1]],
    'kalkyl' => ['sokande' => 2],
    'fortnox_gt_asked' => 47087,
];
$nils = ['195612103217', 'Nils Peter Mild'];
$rose = ['195909071069', 'Rose-Marie Elisabeth Mild'];

function nt_input(int $battery, int $schablon, int $laddbox): array
{
    $l = $laddbox > 0;
    return [
        'id' => 24,
        'intent' => 'save',
        'fx_rowid' => ['54380', '54381', '', ''],
        'fx_article' => ['8436575273277', '8436575273276', '', ''],
        'fx_desc' => ['Batteri', 'Schablon', $l ? 'Sigenergy Laddbox' : '', ''],
        'fx_hw' => [NT_L, 'OTHERCOSTS', $l ? NT_C : '', ''],
        'fx_qty' => ['1', '1', $l ? '1' : '', ''],
        'fx_price' => [(string) $battery, (string) $schablon, $l ? (string) $laddbox : '', ''],
    ];
}

/** @return array{saved:?array,error:string,state:array} */
function nt_save(array $purchase, array $taxes, array $input, array $mode = [], ?array $startRows = null): array
{
    $state = ['rows' => $startRows ?? nt_rows(), 'taxes' => $taxes, 'type' => 'green', 'mode' => $mode, 'log' => []];
    jeab_purchase_put($purchase + ['fortnox_order_rows' => $state['rows']]);
    nt_stub($state);
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

function nt_person(array $state, string $name): array
{
    foreach ($state['taxes'] as $tax) {
        if ($tax['CustomerName'] === $name) {
            $out = [];
            foreach ($tax['TaxReductionAmounts'] as $amount) {
                $out[$amount['WorkType']] = (int) $amount['AskedAmount'];
            }
            ksort($out);
            return $out;
        }
    }
    return [];
}

function nt_prices(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = (int) $row['Price'];
    }
    return $out;
}

$two = [
    '1' => nt_tax('1', $nils[1], $nils[0], [['WorkType' => NT_L, 'AskedAmount' => 23543]]),
    '2' => nt_tax('2', $rose[1], $rose[0], [['WorkType' => NT_L, 'AskedAmount' => 23544]]),
];
$final = nt_input(94428, 3786, 28000);

// Hjälpfunktioner.
nt_assert(FortnoxAPI::greenTechNewTypes(nt_rows(94428, 3786, 28000), [NT_L => 47087]) === [NT_C], 'ny typ: laddningspunkt saknas på ansökan');
nt_assert(FortnoxAPI::greenTechNewTypes(nt_rows(94428, 3786), [NT_L => 47087]) === [], 'bara prisändring: ingen ny typ');
nt_assert(FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases(nt_rows(94428, 3786, 28000)), 2) === 61214, 'möjligt 47 214 + 14 000 = 61 214');
nt_assert(!method_exists('FortnoxAPI', 'greenTechSeedNewTypes'), 'kronan för ny typ (#265) är borttagen');

// Kalkyl «Endast batteri» 65 000: batteri + laddbox ska vara Utan (122 428), Schablon 3 786.
$calc = jeab_offert_calc(['g27' => 65000, 'sokande' => 2]);
nt_assert((int) round($calc['h31']) === 126214 && (int) round($calc['h30']) === 61214 && (int) round($calc['h29']) === 3786, 'Kalkyl 65 000: Summa 126 214, Grön teknik 61 214, Schablon 3 786');
nt_assert(94428 + 28000 + 3786 === 126214, 'raderna summerar till Kalkylens Summa');

// 1) Klippingvägen: sista ansökan går inte att ta bort, ordern avmarkeras en stund.
$r = nt_save($purchase, $two, $final, ['unmark' => 'allow']);
nt_assert($r['error'] === '', 'Klippingvägen: Spara utan fel, fick: ' . $r['error']);
nt_assert(nt_prices($r['state']['rows']) === [94428, 3786, 28000], 'raderna i Fortnox: 94 428 / 3 786 / 28 000');
nt_assert($r['state']['type'] === 'green', 'ordern är märkt för grön teknik igen');
nt_assert(nt_person($r['state'], $nils[1]) === [NT_C => 7000, NT_L => 23607], 'Nils: Lagring 23 607 + Laddningspunkt 7 000');
nt_assert(nt_person($r['state'], $rose[1]) === [NT_C => 7000, NT_L => 23607], 'Rose-Marie: Lagring 23 607 + Laddningspunkt 7 000');
nt_assert((int) ($r['saved']['fortnox_gt_asked'] ?? 0) === 61214, 'sparad ansökan 61 214');
nt_assert(empty($r['saved']['fortnox_gt_capped']), 'inte capped');
nt_assert(($r['saved']['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(65000), 'Att betala 65 000 kr, fick ' . ($r['saved']['fortnox_att_betala'] ?? ''));
nt_assert(count($r['saved']['fortnox_order_rows'] ?? []) === 3, 'Ordrar visar samma tre rader');
nt_assert(!in_array('PUT /taxreductions/1', $r['state']['log'], true), 'ingen krona skrivs på ansökan före raden');

// 2) Fortnox låter alla ansökningar tas bort: ingen avmarkering behövs.
$r = nt_save($purchase, $two, $final, ['delete_last' => true]);
nt_assert($r['error'] === '' && nt_by_type($r['state']['taxes']) === [NT_C => 14000, NT_L => 47214], 'alla raderbara: 14 000 + 47 214, fick: ' . $r['error']);
$unmarks = array_filter($r['state']['log'], static fn ($l) => $l === 'PUT /orders/1892');
nt_assert(count($unmarks) === 1, 'bara en order-PUT (raderna)');

// 2b) Sekvensen mot Fortnox för Klippingvägen, anrop för anrop.
$r = nt_save($purchase, $two, $final);
$seq = array_values(array_filter($r['state']['log'], static fn ($l) => !str_starts_with($l, 'GET ')));
nt_assert($seq === ['DELETE /taxreductions/1', 'DELETE /taxreductions/2', 'PUT /orders/1892', 'DELETE /taxreductions/2', 'PUT /orders/1892', 'POST /taxreductions', 'POST /taxreductions'], 'sekvens: ' . implode(', ', $seq));
nt_assert(nt_prices($r['state']['rows']) === [94428, 3786, 28000] && !empty($r['state']['rows'][0]['HouseWork']) && !empty($r['state']['rows'][2]['HouseWork']), 'husarbete tillbaka på batteri och laddbox');

// 2c) Fortnox nekar också OTHERCOSTS under none: andra varianten tar bort alla husarbetsrader.
$r = nt_save($purchase, $two, $final, ['none_othercosts' => 'reject']);
nt_assert($r['error'] === '' && nt_by_type($r['state']['taxes']) === [NT_C => 14000, NT_L => 47214], 'OTHERCOSTS nekas under none: alla husarbetsrader borttagna, fick: ' . $r['error']);
nt_assert(($r['state']['rows'][1]['HouseWorkType'] ?? '') === 'OTHERCOSTS' && ($r['state']['rows'][2]['HouseWorkType'] ?? '') === NT_C, 'typerna tillbaka efter omskrivningen');

// 3) Fortnox nekar avmarkeringen: allt återställs och felet förklaras.
$r = nt_save($purchase, $two, $final, ['unmark' => 'reject']);
nt_assert(str_contains($r['error'], 'Laddningspunkt elfordon') && str_contains($r['error'], 'återställda') && str_contains($r['error'], 'typ none, gröna rader borttagna: Typ av skattereduktion kan inte ändras') && str_contains($r['error'], 'typ none, alla husarbetsrader borttagna:'), 'nekad avmarkering ger tydligt fel med alla Fortnox-svar, fick: ' . $r['error']);
nt_assert(nt_prices($r['state']['rows']) === [94175, 2912], 'raderna är de ursprungliga');
nt_assert(nt_person($r['state'], $nils[1]) === [NT_L => 23543] && nt_person($r['state'], $rose[1]) === [NT_L => 23544], 'ansökningarna är de ursprungliga per person');

// 4) Andra POST:en avvisas efter att raderna skrivits: rader och ansökan återställs.
$r = nt_save($purchase, $two, $final, ['unmark' => 'allow', 'fail_post' => 2]);
nt_assert(str_contains($r['error'], 'Tillfälligt fel hos Fortnox') && str_contains($r['error'], 'återställda'), 'POST-fel förklaras, fick: ' . $r['error']);
nt_assert(nt_prices($r['state']['rows']) === [94175, 2912], 'POST-fel: raderna är de ursprungliga');
nt_assert(nt_person($r['state'], $nils[1]) === [NT_L => 23543] && nt_person($r['state'], $rose[1]) === [NT_L => 23544], 'POST-fel: ansökningarna är de ursprungliga');
nt_assert($r['state']['type'] === 'green', 'POST-fel: ordern märkt för grön teknik');

// 4b) Som live 23:08: alla rader borta en stund, sedan avvisas en POST.
//     Återställningen skriver raderna (bara skrivbara fält) och green före ansökan.
$r = nt_save($purchase, $two, $final, ['none_othercosts' => 'reject', 'fail_post' => 1]);
nt_assert(str_contains($r['error'], 'Tillfälligt fel hos Fortnox') && str_contains($r['error'], 'återställda'), '23:08-fallet: återställt, fick: ' . $r['error']);
nt_assert(nt_prices($r['state']['rows']) === [94175, 2912] && $r['state']['type'] === 'green', '23:08-fallet: raderna 94 175 / 2 912 och green');
nt_assert(nt_person($r['state'], $nils[1]) === [NT_L => 23543] && nt_person($r['state'], $rose[1]) === [NT_L => 23544], '23:08-fallet: ansökningarna per person som innan');

// 5) Spara utan ny typ: ingen omskrivning, ingen DELETE.
$r = nt_save($purchase, $two, nt_input(94175, 2912, 0), ['unmark' => 'allow']);
nt_assert($r['error'] === '' && !in_array('DELETE /taxreductions/1', $r['state']['log'], true), 'oförändrade rader: ingen DELETE, fick: ' . $r['error']);
nt_assert(($r['saved']['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(50000), 'utan laddbox: Att betala 50 000 kr');

// 6) En ägare: 61 214 klampas till 50 000, laddningspunkt behåller 14 000.
$solo = $purchase;
$solo['owners'] = [$owners[0]];
$solo['medsokande'] = [];
$solo['kalkyl'] = ['sokande' => 1];
$one = ['1' => nt_tax('1', $nils[1], $nils[0], [['WorkType' => NT_L, 'AskedAmount' => 47087]])];
$r = nt_save($solo, $one, $final, ['unmark' => 'allow']);
nt_assert($r['error'] === '', 'en ägare: utan fel, fick: ' . $r['error']);
nt_assert(nt_person($r['state'], $nils[1]) === [NT_C => 14000, NT_L => 36000], 'en ägare: 14 000 + 36 000 = 50 000');
nt_assert(($r['saved']['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(76214), 'en ägare: Att betala 126 214 − 50 000 = 76 214');

// 7) Ljusterö (Kalkyl 70 000, två ägare): batteri 131 845 + laddbox 28 000.
$lj = jeab_offert_fortnox_kop_rows('batteri', 70000, '', ['kalkyl' => ['sokande' => 2], 'sokande' => 2]);
$ljBattery = (int) $lj[0]['price'];
$ljSchablon = (int) $lj[1]['price'];
$ljRows = nt_rows($ljBattery, $ljSchablon);
$ljPossible = (int) floor($ljBattery * 0.5);
$ljTwo = [
    '1' => nt_tax('1', $nils[1], $nils[0], [['WorkType' => NT_L, 'AskedAmount' => intdiv($ljPossible, 2)]]),
    '2' => nt_tax('2', $rose[1], $rose[0], [['WorkType' => NT_L, 'AskedAmount' => $ljPossible - intdiv($ljPossible, 2)]]),
];
$r = nt_save($purchase, $ljTwo, nt_input($ljBattery, $ljSchablon, 28000), ['unmark' => 'allow'], $ljRows);
nt_assert($r['error'] === '', 'Ljusterö två ägare: utan fel, fick: ' . $r['error']);
nt_assert(nt_by_type($r['state']['taxes']) === [NT_C => 14000, NT_L => $ljPossible], 'Ljusterö två ägare: Lagring ' . $ljPossible . ' + 14 000');
foreach ($r['state']['taxes'] as $tax) {
    $sum = array_sum(array_map(static fn ($a) => (int) $a['AskedAmount'], $tax['TaxReductionAmounts']));
    nt_assert($sum <= 50000, 'Ljusterö: högst 50 000 per person (' . $tax['CustomerName'] . ' ' . $sum . ')');
}

// 8) Ljusterö en ägare: ansökan redan på taket 50 000, laddbox läggs till.
$ljOne = ['1' => nt_tax('1', $nils[1], $nils[0], [['WorkType' => NT_L, 'AskedAmount' => 50000]])];
$r = nt_save($solo, $ljOne, nt_input($ljBattery, $ljSchablon, 28000), ['unmark' => 'allow'], $ljRows);
nt_assert($r['error'] === '' && nt_person($r['state'], $nils[1]) === [NT_C => 14000, NT_L => 36000], 'Ljusterö en ägare: 14 000 + 36 000, fick: ' . $r['error']);

// 8b) Live 23:08: ordern blev tom (typ none, inga rader, ingen ansökan) efter
//     ett avbrutet försök. Spara med raderna som nya rader skriver om den som
//     en ny order: rader + green, sedan en ansökan per ägare.
$emptyRows = [];
$state0 = ['rows' => [], 'taxes' => [], 'type' => 'none', 'mode' => [], 'log' => []];
jeab_purchase_put($purchase + ['fortnox_order_rows' => []]);
nt_stub($state0);
$input0 = $final;
$input0['fx_rowid'] = ['', '', '', ''];
$input0['fx_article'] = ['8436575273277', '8436575273276', '51', ''];
$error0 = '';
$saved0 = null;
try {
    $saved0 = jeab_ordrar_save_fakturering($input0);
    $error0 = (string) ($saved0['fortnox_gt_error'] ?? '');
} catch (Throwable $e) {
    $error0 = $e->getMessage();
}
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
nt_assert($error0 === '', 'tom order: Spara utan fel, fick: ' . $error0);
nt_assert($state0['type'] === 'green' && nt_prices($state0['rows']) === [94428, 3786, 28000], 'tom order: green och raderna 94 428 / 3 786 / 28 000');
nt_assert(nt_person($state0, $nils[1]) === [NT_C => 7000, NT_L => 23607] && nt_person($state0, $rose[1]) === [NT_C => 7000, NT_L => 23607], 'tom order: 23 607 + 7 000 per person');
nt_assert(($saved0['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(65000), 'tom order: Att betala 65 000 kr');

// 9) Kalkyl-vägen (updateKopOrder): laddboxorder blir batteriorder. Lagring är
//    ny typ medan ansökan bara har laddningspunkt: samma omskrivning.
$state = [
    'rows' => [
        ['RowId' => 1, 'Description' => 'Laddbox', 'Price' => 19910, 'Total' => 19910, 'HouseWork' => true, 'HouseWorkType' => NT_C, 'DeliveredQuantity' => '1'],
        ['RowId' => 2, 'Description' => 'Framkörning', 'Price' => 45, 'Total' => 45, 'HouseWork' => false, 'DeliveredQuantity' => '1'],
    ],
    'taxes' => [
        '1' => nt_tax('1', $nils[1], $nils[0], [['WorkType' => NT_C, 'AskedAmount' => 4977]]),
        '2' => nt_tax('2', $rose[1], $rose[0], [['WorkType' => NT_C, 'AskedAmount' => 4978]]),
    ],
    'type' => 'green',
    'mode' => ['unmark' => 'allow'],
    'log' => [],
];
nt_stub($state);
$api = new FortnoxAPI('Jarfalla');
$kopError = '';
try {
    $res = $api->updateKopOrder('1892', [
        'kind' => 'batteri',
        'price' => 65000,
        'fastighetsbeteckning' => 'UPPLANDS-BRO EKHAMMAR 4:492',
        'owners' => $owners,
        'kalkyl' => ['sokande' => 2],
        'sokande' => 2,
    ]);
    $kopError = (string) ($res['taxReductionError'] ?? '');
} catch (Throwable $e) {
    $kopError = $e->getMessage();
}
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
nt_assert($kopError === '', 'Kalkyl-vägen: utan fel, fick: ' . $kopError);
$kopBy = nt_by_type($state['taxes']);
nt_assert(array_keys($kopBy) === [NT_L] && $kopBy[NT_L] === (int) floor((float) $state['rows'][0]['Price'] * 0.5), 'Kalkyl-vägen: ansökan följer batteriraden (Lagring ' . ($kopBy[NT_L] ?? 0) . ')');
nt_assert(count($state['taxes']) === 2, 'Kalkyl-vägen: en ansökan per person');

echo "fortnox gt new type laddbox: ok\n";
