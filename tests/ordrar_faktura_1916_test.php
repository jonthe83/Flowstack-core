<?php

declare(strict_types=1);

/**
 * Fortnox-order 1916: Batteri 169 583, Solpaneler art 7 87 366,
 * två Schablon 7 946, Att betala 167 000, Goodwill 17 000.
 * Spara är utkast. Skapa faktura kräver bekräftelse och skapar ingen faktura här.
 * Samma rad-id slås ihop. Olika id behålls. Priserna ändras inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-faktura-1916-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';
require_once dirname(__DIR__) . '/src/ordrar_restore_state.php';
jeab_ordrar_external_live(false);

function f1916_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$battery = [
    'RowId' => 101,
    'ArticleNumber' => '1',
    'Description' => 'Batteri',
    'Price' => 169583,
    'DeliveredQuantity' => '1',
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
];
$solar = [
    'RowId' => 102,
    'ArticleNumber' => '7',
    'Description' => 'Solpaneler',
    'Price' => 87366,
    'DeliveredQuantity' => '1',
    'HouseWork' => true,
    'HouseWorkType' => 'SOLARCELLS',
];
$schablon = [
    'RowId' => 103,
    'ArticleNumber' => '',
    'Description' => 'Schablon',
    'Price' => 7946,
    'DeliveredQuantity' => '1',
    'HouseWork' => false,
    'HouseWorkType' => 'EMPTYHOUSEWORK',
];

$logical = [
    ['article' => 'battery', 'desc' => 'Batteri', 'price' => 169583, 'hw' => 'STORAGESELFPRODUCEDELECTRICITY', 'hours' => 24],
    ['article' => '7', 'desc' => 'Solpaneler', 'price' => 87366, 'hw' => 'SOLARCELLS', 'hours' => 16],
    ['article' => 'material', 'desc' => 'Schablon', 'price' => 7946, 'hw' => null, 'hours' => 0],
];
f1916_ok(jeab_offert_rows_att_betala($logical, 2) === 167000, 'en Schablon och två sökande ger Att betala 167 000');
$both = $logical;
$both[] = ['article' => 'material', 'desc' => 'Schablon', 'price' => 7946, 'hw' => null, 'hours' => 0];
f1916_ok(jeab_offert_rows_att_betala($both, 2) === 174946, 'två Schablon ger 174 946, inte 167 000');

$kept = jeab_ordrar_fortnox_complete_with_formula(
    [$schablon],
    [['Description' => 'Schablon', 'Price' => 4051, 'HouseWorkType' => 'EMPTYHOUSEWORK']]
);
f1916_ok(count($kept) === 1 && (int) $kept[0]['Price'] === 7946, 'kalkylens Schablon läggs inte till när en redan finns');

$restored = jeab_ordrar_restore_state_extract([
    'company' => 'jarfalla',
    'fortnox_order_rows' => [$battery, $solar, $schablon],
]);
f1916_ok(
    is_array($restored['fortnox_order_rows'] ?? null) && count($restored['fortnox_order_rows']) === 3,
    'restore_state kopierar raderna en gång, fick ' . count($restored['fortnox_order_rows'] ?? [])
);

$invoices = 0;
$puts = 0;
$GLOBALS['_JEAB_FORTNOX_INVOICE'] = static function () use (&$invoices): array {
    $invoices++;

    return ['Invoice' => ['DocumentNumber' => '9999'], 'Order' => ['InvoiceReference' => '9999']];
};
$GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static function (array $row, array $before, array $after, array $snap): array {
    unset($row, $before, $after);

    return ['order' => $snap, 'update' => false];
};
$GLOBALS['_JEAB_FORTNOX_GT_LOWER'] = static function (array $row, array $before, array $after, array $snap): array {
    unset($row, $before, $after);

    return ['order' => $snap, 'update' => false];
};
$GLOBALS['_JEAB_FORTNOX_GT_REWRITE'] = static function (): array {
    return ['applies' => false];
};

$base = [
    'id' => 1916,
    'pris' => '167 000',
    'payouts_form' => '1',
    'payout_id' => ['gw1'],
    'payout_type' => ['goodwill'],
    'payout_amount' => ['17 000'],
    'payout_konto' => [''],
    'fx_article' => ['1', '7', '', ''],
    'fx_desc' => ['Batteri', 'Solpaneler', 'Schablon', 'Schablon'],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', 'SOLARCELLS', '', ''],
    'fx_qty' => ['1', '1', '1', '1'],
    'fx_price' => ['169583', '87366', '7946', '7946'],
    'fx_touched' => ['0', '0', '0', '0'],
];

try {
    jeab_ordrar_fakturering_gate($base + ['intent' => 'save']);
    $saveGate = true;
} catch (Throwable $e) {
    $saveGate = false;
    fwrite(STDERR, $e->getMessage() . "\n");
}
f1916_ok($saveGate, 'Spara stoppas inte av bekräftelsegrinden');

$threw = '';
try {
    jeab_ordrar_fakturering_gate($base + ['intent' => 'faktura']);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
f1916_ok($threw === 'Bekräfta belopp och rader innan fakturan skapas.', 'Skapa faktura utan bekräftelse: ' . $threw);
f1916_ok(
    jeab_ordrar_save_fail_text($threw) === 'Kunde inte spara. Bekräfta belopp och rader innan fakturan skapas.',
    'meddelandet som visas är Kunde inte spara plus grinden'
);

$confirmed = $base + ['intent' => 'faktura', 'faktura_bekrafta' => '1'];
try {
    jeab_ordrar_fakturering_gate($confirmed);
    $confirmedOk = true;
} catch (Throwable $e) {
    $confirmedOk = false;
    fwrite(STDERR, $e->getMessage() . "\n");
}
f1916_ok($confirmedOk && $invoices === 0, 'bekräftad Skapa faktura passerar grinden utan att skapa faktura');

try {
    jeab_ordrar_fakturering_gate($base + ['intent' => ['faktura', 'save']]);
    $bothOk = jeab_ordrar_fakturering_intent(['intent' => ['faktura', 'save']]) === 'save';
} catch (Throwable) {
    $bothOk = false;
}
f1916_ok($bothOk, 'intent save tillsammans med faktura är utkast');

function f1916_order(array $rows): array
{
    return [
        'Order' => [
            'DocumentNumber' => '1916',
            'TotalToPay' => 167000,
            'TaxReduction' => 97895,
            'OrderRows' => $rows,
        ],
    ];
}

function f1916_put(array $rows): void
{
    jeab_purchase_put([
        'reminder_id' => 1916,
        'company' => 'jarfalla',
        'kind' => 'sol',
        'customer' => 'Test Kund',
        'huvudsokande' => 'Test Kund',
        'personnummer' => '198001011234',
        'fastighetsbeteckning' => 'TEST 1:1',
        'pris' => 167000,
        'kundpris' => 167000,
        'fortnox_order' => '1916',
        'fortnox_order_rows' => $rows,
        'fortnox_att_betala' => '167 000 kr',
        'kalkyl' => ['sokande' => 2, 'b14' => 20, 'b3' => 4],
        'payouts' => [[
            'id' => 'gw1',
            'type' => 'goodwill',
            'amount' => 17000,
            'clearing' => '',
            'account' => '',
            'status' => 'sparad',
            'fortnox_credit' => '',
        ]],
        'steps' => ['bekraftad' => '2026-10-01', 'foranmald' => '2026-10-02', 'bestalld' => '2026-10-03'],
        'foranmalan_status' => 'A',
        'bestallning_status' => 'A',
    ]);
}

function f1916_prices(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = (string) ($row['Description'] ?? '') . '=' . (int) ($row['Price'] ?? 0) . '#' . trim((string) ($row['RowId'] ?? ''));
    }

    return $out;
}

$same = $schablon;
$dup = [$battery, $solar, $schablon, $same];
$order = f1916_order($dup);
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($order): array {
    return $order;
};
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$puts, &$order): array {
    $puts++;
    unset($no);
    $order['Order']['OrderRows'] = $rows;

    return $order;
};
f1916_put($dup);
$savedSame = jeab_ordrar_save_fakturering($base + [
    'intent' => 'save',
    'fx_rowid' => ['101', '102', '103', '103'],
]);
$samePrices = f1916_prices($savedSame['fortnox_order_rows'] ?? []);
f1916_ok($samePrices === ['Batteri=169583#101', 'Solpaneler=87366#102', 'Schablon=7946#103'], 'samma rad-id: en Schablon, priserna orörda, fick ' . implode(', ', $samePrices));
f1916_ok((int) ($savedSame['pris'] ?? 0) === 167000, 'Att betala är kvar 167 000');
$payouts = jeab_ordrar_payouts($savedSame);
f1916_ok(count($payouts) === 1 && (int) $payouts[0]['amount'] === 17000 && (string) $payouts[0]['type'] === 'goodwill', 'Goodwill 17 000 ligger kvar');
f1916_ok($invoices === 0 && trim((string) ($savedSame['fortnox_invoice'] ?? '')) === '', 'Spara skapar ingen faktura');

$other = $schablon;
$other['RowId'] = 104;
$distinct = [$battery, $solar, $schablon, $other];
$order = f1916_order($distinct);
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use (&$order): array {
    return $order;
};
f1916_put($distinct);
$beforeInvoices = $invoices;
$savedBoth = jeab_ordrar_save_fakturering($base + [
    'intent' => 'save',
    'fx_rowid' => ['101', '102', '103', '104'],
]);
$bothPrices = f1916_prices($savedBoth['fortnox_order_rows'] ?? []);
f1916_ok(
    $bothPrices === ['Batteri=169583#101', 'Solpaneler=87366#102', 'Schablon=7946#103', 'Schablon=7946#104'],
    'olika rad-id: båda Schablon behålls, fick ' . implode(', ', $bothPrices)
);
f1916_ok($invoices === $beforeInvoices, 'andra sparningen skapar inte heller faktura');

$html = jeab_ordrar_v3_html(jeab_purchase_get(1916), 'jarfalla', 1916);
f1916_ok(str_contains($html, 'data-v3-faktura-check') && !preg_match('/data-v3-faktura-check[^>]*disabled/', $html), 'bekräftelsen kan kryssas i');
f1916_ok(str_contains($html, 'name="intent" value="save"') && str_contains($html, 'data-v3-faktura-flag disabled'), 'Spara är utkast och den dolda flaggan är inte med förrän den slås på');

echo "ordrar_faktura_1916_test ok\n";
