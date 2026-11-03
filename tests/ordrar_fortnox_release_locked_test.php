<?php

declare(strict_types=1);

/**
 * En nyskapad Fortnox-order har WarehouseReady eftersom JEAB-rader inte är
 * lagervaror. Spara och create_fortnox får inte släppa den. Bara ett uttryckligt
 * anrop släpper, och bara när Fortnox säger Cancelled.
 */

$tmp = sys_get_temp_dir() . '/jeab-release-locked-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
if (!mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL rot\n");
    exit(1);
}
if (!mkdir($tmp . '/fortnox', 0775, true) && !is_dir($tmp . '/fortnox')) {
    fwrite(STDERR, "FAIL fortnox\n");
    exit(1);
}
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-refresh', 'access_token' => 'test-access'],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function rl_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$auditPdo = new PDO('sqlite::memory:');
$auditPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_FORTNOX_ORDER_AUDIT_PDO'] = $auditPdo;
jeab_ensure_fortnox_order_audit($auditPdo, 'sqlite');

$live = [
    'DocumentNumber' => '1904',
    'CustomerNumber' => 'K9',
    'Cancelled' => false,
    'WarehouseReady' => true,
    'InvoiceReference' => '0',
    'YourOrderNumber' => '40',
    'OrderRows' => [[
        'RowId' => 54561,
        'ArticleNumber' => '1',
        'Description' => 'Laddbox',
        'Price' => 1000,
        'DeliveredQuantity' => '1',
        'HouseWork' => true,
        'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
    ]],
];
$posts = 0;
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use (&$live): array {
    return ['Order' => $live];
};
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $rows) use (&$live): array {
    rl_ok($no === '1904', 'raderna skrivs på 1904, fick ' . $no);
    $live['OrderRows'] = $rows;

    return ['Order' => $live];
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
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$posts, &$live): array {
    $route = strtok($path, '?') ?: $path;
    if ($method === 'POST' && $route === '/orders') {
        $posts++;

        return ['Order' => [
            'DocumentNumber' => '1915',
            'CustomerNumber' => 'K9',
            'Cancelled' => false,
            'WarehouseReady' => true,
            'InvoiceReference' => '0',
        ]];
    }
    if (preg_match('#^/orders/(\d+)$#', $route, $match) === 1) {
        $doc = $match[1];
        if ($method === 'PUT' && is_array($body) && is_array($body['Order'] ?? null)) {
            return ['Order' => ['DocumentNumber' => $doc, 'CustomerNumber' => 'K9'] + $body['Order']];
        }

        return ['Order' => $live + ['DocumentNumber' => $doc]];
    }
    if (str_contains($route, '/customers')) {
        return [
            'Customers' => [[
                'CustomerNumber' => 'K9',
                'Name' => 'Ada Person',
                'Active' => true,
            ]],
            'Customer' => ['CustomerNumber' => 'K9', 'Name' => 'Ada Person', 'Active' => true],
            'MetaInformation' => ['@TotalPages' => 1],
        ];
    }
    if (str_contains($route, 'taxreduction')) {
        return ['TaxReductions' => [], 'TaxReduction' => ['Id' => '1'], 'MetaInformation' => ['@TotalPages' => 1]];
    }

    return [];
};
$GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'] = [];

jeab_purchase_put([
    'reminder_id' => 40,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Person',
    'huvudsokande' => 'Ada Person',
    'personnummer' => '198001011234',
    'email' => 'ada@kund.se',
    'phone' => '0701234567',
    'address' => 'Testgatan 1',
    'postnr' => '17700',
    'ort' => 'Järfälla',
    'fastighetsbeteckning' => 'JÄRFÄLLA 1:1',
    'pris' => 1000,
    'kundpris' => 1000,
    'fortnox_order' => '1904',
    'fortnox_customer' => 'K9',
    'fortnox_order_rows' => $live['OrderRows'],
    'steps' => ['bekraftad' => '2026-10-01'],
]);

rl_ok(jeab_ordrar_fortnox_order_lock_reason($live) === 'warehouse_ready', 'WarehouseReady på en ny order klassas som lagerlås');
rl_ok(jeab_ordrar_fortnox_order_lock_reason(['Cancelled' => false, 'WarehouseReady' => false, 'InvoiceReference' => '0']) === '', 'InvoiceReference 0 är inte en låsning');
rl_ok(jeab_ordrar_fortnox_order_lock_reason(['DeliveryState' => 'reservation', 'WarehouseReady' => false, 'Cancelled' => false]) === '', 'reservation är inte en lagerlåsning');
$warehouseText = jeab_ordrar_fortnox_lock_message('warehouse_ready', '1904');
rl_ok(
    $warehouseText === 'Fortnox-ordern 1904 är låst i lagret. Den uppdaterades inte och ingen ny order skapades.',
    'lagertexten lovar ingen ny order, fick ' . $warehouseText
);

$post = [
    'id' => 40,
    'intent' => 'save',
    'pris' => '1000',
    'fx_rowid' => ['54561'],
    'fx_article' => ['1'],
    'fx_desc' => ['Laddbox'],
    'fx_hw' => ['CHARGINGSTATIONELECTRICVEHICLE'],
    'fx_qty' => ['1'],
    'fx_price' => ['1000'],
    'fx_touched' => ['0'],
];
$saved = jeab_ordrar_save_fakturering($post);
rl_ok(trim((string) ($saved['fortnox_order'] ?? '')) === '1904', 'Spara behåller 1904 trots WarehouseReady');
rl_ok($posts === 0, 'Spara skapar ingen ny Fortnox-order');
$savedLog = '';
foreach (is_array($saved['activity'] ?? null) ? $saved['activity'] : [] as $entry) {
    if (is_array($entry)) {
        $savedLog .= ' ' . (string) ($entry['text'] ?? '');
    }
}
rl_ok(!str_contains($savedLog, 'en ny skapas'), 'Spara skriver inte att en ny order skapas');

$okPut = $GLOBALS['_JEAB_FORTNOX_ORDER_PUT'];
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (): void {
    throw new Exception('Ett klarmarkerat dokument kan inte ändras');
};
$stopped = false;
$stopText = '';
try {
    jeab_ordrar_save_fakturering($post);
} catch (InvalidArgumentException $e) {
    $stopped = true;
    $stopText = $e->getMessage();
}
$GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = $okPut;
$afterStop = jeab_purchase_get(40);
rl_ok(
    $stopped && $stopText === 'Fortnox-ordern 1904 är låst i lagret. Den uppdaterades inte och ingen ny order skapades.',
    'nekad uppdatering stannar med text, fick ' . $stopText
);
rl_ok(is_array($afterStop) && (string) ($afterStop['fortnox_order'] ?? '') === '1904' && $posts === 0, 'nekad uppdatering behåller 1904 och skapar ingen order');

$created = jeab_ordrar_create_fortnox(['id' => 40]);
rl_ok(trim((string) ($created['fortnox_order'] ?? '')) === '1904', 'create_fortnox uppdaterar 1904');
rl_ok($posts === 0 && !in_array('create', $GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'], true), 'create_fortnox skapar ingen order när fortnox_order finns');

$silent = jeab_ordrar_fortnox_release_locked(jeab_purchase_get(40) ?? []);
rl_ok(trim((string) ($silent['fortnox_order'] ?? '')) === '1904', 'release_locked utan uttryckligt anrop gör inget');
$warehouseRelease = jeab_ordrar_fortnox_release_cancelled(jeab_purchase_get(40) ?? []);
rl_ok(trim((string) ($warehouseRelease['fortnox_order'] ?? '')) === '1904', 'uttryckligt släpp rör inte WarehouseReady');
rl_ok((int) $auditPdo->query('SELECT COUNT(*) FROM fortnox_order_audit')->fetchColumn() === 0, 'WarehouseReady skriver ingen audit');

$live['Cancelled'] = true;
$savedCancelled = jeab_ordrar_save_fakturering($post);
rl_ok(trim((string) ($savedCancelled['fortnox_order'] ?? '')) === '1904', 'Spara släpper inte en makulerad order');
$createdCancelled = jeab_ordrar_create_fortnox(['id' => 40]);
rl_ok(trim((string) ($createdCancelled['fortnox_order'] ?? '')) === '1904' && $posts === 0, 'create_fortnox släpper inte en makulerad order');
rl_ok((int) $auditPdo->query('SELECT COUNT(*) FROM fortnox_order_audit')->fetchColumn() === 0, 'automatisk väg skriver ingen audit');

$released = jeab_ordrar_fortnox_release_cancelled(jeab_purchase_get(40) ?? []);
rl_ok(trim((string) ($released['fortnox_order'] ?? '')) === '', 'uttryckligt släpp rensar makulerad order');
$storedRows = is_array($released['fortnox_order_rows'] ?? null) ? $released['fortnox_order_rows'] : [];
$rowId = trim((string) ($storedRows[0]['RowId'] ?? $storedRows[0]['row_id'] ?? ''));
rl_ok($rowId === '', 'uttryckligt släpp glömmer rad-id');
$audit = $auditPdo->query('SELECT reminder_id, fortnox_order, reason FROM fortnox_order_audit')->fetch(PDO::FETCH_ASSOC);
rl_ok(
    is_array($audit) && (int) $audit['reminder_id'] === 40 && (string) $audit['fortnox_order'] === '1904' && (string) $audit['reason'] === 'cancelled',
    'auditen har cancelled för 1904'
);
$after = jeab_ordrar_create_fortnox(['id' => 40]);
rl_ok($posts === 1 && trim((string) ($after['fortnox_order'] ?? '')) === '1915', 'ny order skapas först efter uttryckligt släpp, fick ' . (string) ($after['fortnox_order'] ?? '') . ' poster ' . $posts);

echo "ordrar_fortnox_release_locked_test ok\n";
