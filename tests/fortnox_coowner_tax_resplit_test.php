<?php

declare(strict_types=1);

/**
 * Saknad medsökande på en befintlig Fortnox-order.
 * Stens ansökan sänks till sin andel innan Ingrids skapas,
 * annars överskrider Lagring möjligt belopp.
 * Order 103 / Fortnox 1891: Batteri 131 845 + Schablon 4 077 → 65 922 grön teknik, att betala 70 000.
 */

$tmp = sys_get_temp_dir() . '/jeab-coowner-resplit-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function co_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

const CO_TYPE_CAP = 65922;
const CO_SHARE = 32961;
const CO_OVERFLOW = 'Den ansökta summan för en typ får inte överskrida möjligt belopp för den typen (Lagring egenproducerad el)';

function co_rows(): array
{
    return [
        [
            'Description' => 'Batteri',
            'Price' => 131845,
            'Total' => 131845,
            'HouseWork' => true,
            'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
            'DeliveredQuantity' => '1',
        ],
        [
            'Description' => 'Schablon',
            'Price' => 4077,
            'Total' => 4077,
            'HouseWork' => false,
            'HouseWorkType' => 'OTHERCOSTS',
            'DeliveredQuantity' => '1',
        ],
    ];
}

function co_storage_sum(array $taxes): int
{
    $sum = 0;
    foreach ($taxes as $tax) {
        if (!is_array($tax)) {
            continue;
        }
        foreach ($tax['TaxReductionAmounts'] ?? [] as $amount) {
            if (!is_array($amount)) {
                continue;
            }
            if ((string) ($amount['WorkType'] ?? '') === 'STORAGESELFPRODUCEDELECTRICITY') {
                $sum += (int) ($amount['AskedAmount'] ?? 0);
            }
        }
    }
    return $sum;
}

/** @return array{taxes:array<string,array<string,mixed>>,mutations:list<array<string,mixed>>} */
function co_fresh_state(): array
{
    return [
        'taxes' => [
            '1' => [
                'Id' => '1',
                'CustomerName' => 'Sten Håkansson',
                'SocialSecurityNumber' => '196007218214',
                'PropertyDesignation' => 'ÖSTERÅKER BOLBY 3:166',
                'ReferenceNumber' => '1891',
                'TaxReductionAmounts' => [
                    ['WorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'AskedAmount' => 50000],
                ],
            ],
        ],
        'mutations' => [],
    ];
}

function co_order_payload(): array
{
    return ['Order' => [
        'DocumentNumber' => '1891',
        'CustomerNumber' => '100',
        'Total' => 135922,
        'TotalToPay' => 85922,
        'TaxReduction' => 50000,
        'OrderRows' => co_rows(),
        '@urlTaxReductionList' => 'https://api.fortnox.se/3/taxreductions?filter=orders&referencenumber=1891',
    ]];
}

function co_install_stub(array &$state): void
{
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$state): array {
        if ($method === 'GET' && str_contains($path, '/customers')) {
            return ['Customers' => [[
                'CustomerNumber' => '100',
                'Name' => 'Sten Håkansson',
                'OrganisationNumber' => '196007218214',
                'Active' => true,
            ]]];
        }
        if ($method === 'PUT' && str_contains($path, '/customers/')) {
            return ['Customer' => ['CustomerNumber' => '100']];
        }
        if ($method === 'GET' && str_contains($path, '/orders/')) {
            return co_order_payload();
        }
        if ($method === 'PUT' && str_contains($path, '/orders/')) {
            return co_order_payload();
        }
        if ($method === 'GET' && str_contains($path, '/taxreductions?')) {
            $list = [];
            foreach ($state['taxes'] as $tax) {
                $list[] = [
                    'Id' => $tax['Id'],
                    'ReferenceNumber' => '1891',
                    'CustomerName' => $tax['CustomerName'],
                    'SocialSecurityNumber' => $tax['SocialSecurityNumber'],
                    'PropertyDesignation' => $tax['PropertyDesignation'],
                ];
            }
            return ['TaxReductions' => $list];
        }
        if ($method === 'GET' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if (!isset($state['taxes'][$id])) {
                throw new Exception('saknad skattereduktion ' . $id);
            }
            return ['TaxReduction' => $state['taxes'][$id]];
        }
        if (($method === 'PUT' || $method === 'POST') && str_contains($path, '/taxreductions')) {
            $taxBody = is_array($body['TaxReduction'] ?? null) ? $body['TaxReduction'] : [];
            $next = $state['taxes'];
            if ($method === 'PUT' && preg_match('#/taxreductions/([^/?]+)#', $path, $m)) {
                $id = rawurldecode($m[1]);
                if (!isset($next[$id])) {
                    throw new Exception('saknad skattereduktion ' . $id);
                }
                if (isset($taxBody['TaxReductionAmounts']) && is_array($taxBody['TaxReductionAmounts'])) {
                    $next[$id]['TaxReductionAmounts'] = $taxBody['TaxReductionAmounts'];
                }
                $name = (string) $next[$id]['CustomerName'];
            } else {
                $id = (string) (count($next) + 1);
                $name = (string) ($taxBody['CustomerName'] ?? '');
                $next[$id] = [
                    'Id' => $id,
                    'CustomerName' => $name,
                    'SocialSecurityNumber' => (string) ($taxBody['SocialSecurityNumber'] ?? ''),
                    'PropertyDesignation' => (string) ($taxBody['PropertyDesignation'] ?? ''),
                    'ReferenceNumber' => '1891',
                    'TaxReductionAmounts' => is_array($taxBody['TaxReductionAmounts'] ?? null) ? $taxBody['TaxReductionAmounts'] : [],
                ];
            }
            $sum = co_storage_sum($next);
            if ($sum > CO_TYPE_CAP) {
                throw new Exception(CO_OVERFLOW);
            }
            $state['taxes'] = $next;
            $amount = 0;
            foreach ($next[$id]['TaxReductionAmounts'] as $row) {
                if (is_array($row) && (string) ($row['WorkType'] ?? '') === 'STORAGESELFPRODUCEDELECTRICITY') {
                    $amount += (int) ($row['AskedAmount'] ?? 0);
                }
            }
            $state['mutations'][] = [
                'method' => $method,
                'name' => $name,
                'amount' => $amount,
                'sum' => $sum,
            ];
            return ['TaxReduction' => $state['taxes'][$id]];
        }
        throw new Exception('oväntat Fortnox-anrop ' . $method . ' ' . $path);
    };
}

$bases = FortnoxAPI::houseworkBases(co_rows());
co_assert(FortnoxAPI::greenTechPossible($bases, 1) === 50000, 'en person klampas till 50 000');
co_assert(FortnoxAPI::greenTechPossible($bases, 2) === CO_TYPE_CAP, 'två personer rymmer hela lagringen 65 922');
$splits = FortnoxAPI::greenTechSplits($bases, 2, CO_TYPE_CAP);
$shareSum = 0;
foreach ($splits as $person) {
    foreach ($person as $amount) {
        $shareSum += (int) ($amount['AskedAmount'] ?? 0);
        co_assert((int) ($amount['AskedAmount'] ?? 0) === CO_SHARE, 'varje person får 32 961 i lagring');
    }
}
co_assert($shareSum === CO_TYPE_CAP, 'delarna summerar till 65 922');

$purchase = [
    'fortnox_order' => '1891',
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'fastighetsbeteckning' => 'ÖSTERÅKER BOLBY 3:166',
    'huvudsokande' => 'Sten Håkansson',
    'personnummer' => '196007218214',
    'owners' => [
        ['name' => 'Sten Håkansson', 'personnummer' => '196007218214'],
        ['name' => 'Ingrid Valborg Brorsson', 'personnummer' => '194201011111'],
    ],
    'medsokande' => [
        ['name' => 'Ingrid Valborg Brorsson', 'personnummer' => '194201011111'],
    ],
];

$state = co_fresh_state();
co_install_stub($state);
$api = new FortnoxAPI('Jarfalla');
$synced = $api->syncKopTaxReduction($purchase, co_rows(), co_rows(), co_order_payload());
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);

co_assert(empty($synced['error']), 'Fakturering-synk utan överskridande, fel: ' . (string) ($synced['error'] ?? ''));
co_assert(count($state['taxes']) === 2, 'två skattereduktioner efter synk, fick ' . count($state['taxes']));
co_assert(count($state['mutations']) === 2, 'en sänkning och ett skapande, fick ' . count($state['mutations']) . ' skrivningar');
co_assert(($state['mutations'][0]['method'] ?? '') === 'PUT', 'Sten sänks med PUT innan Ingrid skapas');
co_assert(($state['mutations'][0]['name'] ?? '') === 'Sten Håkansson', 'första skrivningen gäller Sten');
co_assert((int) ($state['mutations'][0]['amount'] ?? 0) === CO_SHARE, 'Sten sänks till 32 961, fick ' . (int) ($state['mutations'][0]['amount'] ?? 0));
co_assert((int) ($state['mutations'][0]['sum'] ?? 0) === CO_SHARE, 'typtotalen efter sänkningen är bara Stens andel');
co_assert(($state['mutations'][1]['method'] ?? '') === 'POST', 'Ingrid skapas efter sänkningen');
co_assert(($state['mutations'][1]['name'] ?? '') === 'Ingrid Valborg Brorsson', 'POST gäller Ingrid');
co_assert((int) ($state['mutations'][1]['amount'] ?? 0) === CO_SHARE, 'Ingrid får 32 961');
co_assert((int) ($state['mutations'][1]['sum'] ?? 0) === CO_TYPE_CAP, 'typtotalen blir 65 922, inte över lagringstaket');
co_assert((int) ($synced['asked'] ?? 0) === CO_TYPE_CAP, 'ansökan är 65 922, fick ' . (int) ($synced['asked'] ?? 0));
co_assert((int) ($synced['mojlig'] ?? 0) === CO_TYPE_CAP, 'möjligt belopp är 65 922 för två personer');
$payBody = jeab_ordrar_fortnox_order_body(is_array($synced['order'] ?? null) ? $synced['order'] : []);
co_assert((int) round((float) ($payBody['TotalToPay'] ?? 0)) === 70000, 'att betala blir 70 000, fick ' . (string) ($payBody['TotalToPay'] ?? ''));
co_assert((int) round((float) ($payBody['TaxReduction'] ?? 0)) === CO_TYPE_CAP, 'orderns skattereduktion följer 65 922');
co_assert(jeab_ordrar_fortnox_att_betala($synced['order']) === jeab_ordrar_fortnox_money(70000), 'visad att betala är 70 000 kr');

$state = co_fresh_state();
co_install_stub($state);
$updated = (new FortnoxAPI('Jarfalla'))->updateKopOrder('1891', [
    'kind' => 'batteri',
    'name' => 'Sten Håkansson',
    'personnummer' => '196007218214',
    'fastighetsbeteckning' => 'ÖSTERÅKER BOLBY 3:166',
    'price' => 135922,
    'kundpris' => 135922,
    'owners' => [
        ['name' => 'Sten Håkansson', 'personnummer' => '196007218214'],
        ['name' => 'Ingrid Valborg Brorsson', 'personnummer' => '194201011111'],
    ],
]);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
co_assert(trim((string) ($updated['taxReductionError'] ?? '')) === '', 'ordrar.fortnox skapar medsökande utan fel, fick: ' . (string) ($updated['taxReductionError'] ?? ''));
co_assert(count($state['taxes']) === 2, 'ordrar.fortnox lämnar en ansökan per person');
co_assert(($state['mutations'][0]['method'] ?? '') === 'PUT' && ($state['mutations'][1]['method'] ?? '') === 'POST', 'ordrar.fortnox sänker först och skapar sedan');
co_assert((int) ($state['mutations'][0]['amount'] ?? 0) === CO_SHARE && (int) ($state['mutations'][1]['amount'] ?? 0) === CO_SHARE, 'båda ansökningarna är 32 961');
co_assert((int) ($state['mutations'][1]['sum'] ?? 0) <= CO_TYPE_CAP, 'ingen skrivning går över lagringens tak');

@mkdir($tmp . '/rot', 0775, true);
@mkdir($tmp . '/fortnox', 0775, true);
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-jarfalla'],
    'Malmo' => ['refresh_token' => 'test-malmo'],
], JSON_UNESCAPED_UNICODE));

jeab_purchase_put($purchase + [
    'reminder_id' => 103,
    'fortnox_att_betala' => jeab_ordrar_fortnox_money(85922),
    'fortnox_order_rows' => co_rows(),
]);
$state = co_fresh_state();
co_install_stub($state);
$saved = jeab_ordrar_save_fakturering([
    'id' => 103,
    'intent' => 'save',
    'fx_rowid' => ['1', '2', '', ''],
    'fx_article' => ['', '', '', ''],
    'fx_desc' => ['Batteri', 'Schablon', '', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', '', '', ''],
    'fx_qty' => ['1', '1', '', ''],
    'fx_price' => ['131845', '4077', '', ''],
]);
unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
co_assert(empty($saved['fortnox_gt_error']), 'Fakturering Spara utan fel, fick: ' . (string) ($saved['fortnox_gt_error'] ?? ''));
co_assert((int) ($saved['fortnox_gt_asked'] ?? 0) === CO_TYPE_CAP, 'sparad ansökan är 65 922, fick ' . (int) ($saved['fortnox_gt_asked'] ?? 0));
co_assert(($saved['fortnox_att_betala'] ?? '') === jeab_ordrar_fortnox_money(70000), 'Spara visar att betala 70 000 kr, fick ' . (string) ($saved['fortnox_att_betala'] ?? ''));
co_assert(count($state['taxes']) === 2, 'Spara skapar Ingrids skattereduktion');
co_assert(($state['mutations'][0]['method'] ?? '') === 'PUT', 'Spara sänker Sten innan Ingrid skapas');

echo "fortnox coowner tax resplit: ok\n";
