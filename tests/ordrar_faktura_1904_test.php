<?php

declare(strict_types=1);

/**
 * Order 40 Järfälla har Fortnox-order 1904. Spara tre gånger och Skapa faktura
 * får inte skapa någon ny Fortnox-order, även om 1904 är låst i lagret.
 */

$tmp = sys_get_temp_dir() . '/jeab-faktura-1904-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function f1904_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$created = 0;
$finds = 0;
$order = [
    'Order' => [
        'DocumentNumber' => '1904',
        'WarehouseReady' => true,
        'Cancelled' => false,
        'InvoiceReference' => '',
        'YourOrderNumber' => '40',
        'TotalToPay' => 1000,
        'OrderRows' => [[
            'RowId' => 1,
            'ArticleNumber' => '1',
            'Description' => 'Laddbox',
            'Price' => 1000,
            'DeliveredQuantity' => '1',
            'HouseWork' => true,
            'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        ]],
    ],
];
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use (&$order): array {
    return $order;
};
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$order): array {
    f1904_ok($no === '1904', 'raderna skrivs på 1904, fick ' . $no);
    $order['Order']['OrderRows'] = $rows;

    return $order;
};
$GLOBALS['_JEAB_FORTNOX_INVOICE'] = static function (): array {
    return ['Invoice' => ['DocumentNumber' => '1'], 'Order' => ['InvoiceReference' => '1', 'DocumentNumber' => '1904']];
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
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path) use (&$created): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'POST' && $route === '/orders') {
        $created++;
    }

    return [];
};
$GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'] = [];
$GLOBALS['_JEAB_FORTNOX_ORDER_FIND'] = static function () use (&$finds): string {
    $finds++;

    return '1904';
};

jeab_purchase_put([
    'reminder_id' => 40,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Order 40',
    'huvudsokande' => 'Order 40',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'JÄRFÄLLA 1:1',
    'pris' => 1000,
    'kundpris' => 1000,
    'fortnox_order' => '1904',
    'fortnox_order_rows' => $order['Order']['OrderRows'],
    'steps' => ['bekraftad' => '2026-10-01'],
]);

$post = [
    'id' => 40,
    'pris' => '1000',
    'fx_rowid' => ['1'],
    'fx_article' => ['1'],
    'fx_desc' => ['Laddbox'],
    'fx_hw' => ['CHARGINGSTATIONELECTRICVEHICLE'],
    'fx_qty' => ['1'],
    'fx_price' => ['1000'],
    'fx_touched' => ['0'],
];
for ($i = 0; $i < 3; $i++) {
    $saved = jeab_ordrar_save_fakturering($post + ['intent' => 'save']);
    f1904_ok(trim((string) ($saved['fortnox_order'] ?? '')) === '1904', 'Spara ' . ($i + 1) . ' behåller 1904');
}
$invoiced = jeab_ordrar_save_fakturering($post + ['intent' => 'faktura', 'faktura_bekrafta' => '1']);
f1904_ok(trim((string) ($invoiced['fortnox_order'] ?? '')) === '1904', 'Skapa faktura behåller 1904');
f1904_ok($created === 0 && !in_array('create', $GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'], true), 'Spara x3 + Skapa faktura skapar 0 nya Fortnox-order, fick ' . $created);
f1904_ok($finds === 0, 'befintligt fortnox_order gör att sökningen inte behövs');

jeab_purchase_patch(40, static function (array $fresh): array {
    $fresh['fortnox_order'] = '';

    return $fresh;
});
$reused = jeab_ordrar_save_fakturering($post + ['intent' => 'save']);
f1904_ok(trim((string) ($reused['fortnox_order'] ?? '')) === '1904', 'tomt nummer med samma YourOrderNumber återanvänder 1904');
f1904_ok($created === 0 && !in_array('create', $GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'], true), 'återanvändningen skapar ingen order');

echo "ordrar_faktura_1904_test ok\n";
