<?php

declare(strict_types=1);

/**
 * Låst Fortnox-order (lager) ska ersättas utan att de gamla rad-id:na
 * skickas till den nya ordern. Raderna på den nya ordern ska vara kalkylens.
 * ordrar.fortnox_check läser och skriver inte. Inget personnummer i svaret.
 */

$tmp = sys_get_temp_dir() . '/jeab-locked-rows-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
if (!mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL rot\n");
    exit(1);
}
if (!mkdir($tmp . '/fortnox', 0775, true) && !is_dir($tmp . '/fortnox')) {
    fwrite(STDERR, "FAIL fortnox\n");
    exit(1);
}
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => ['Jarfalla' => ['battery' => '30010001', 'material' => '9001']]];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-refresh'],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar.php';
require_once dirname(__DIR__) . '/src/ordrar_fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function lr_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function lr_leak(string $hay, array $secrets): bool
{
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($hay, $secret)) {
            return true;
        }
        $norm = function_exists('jeab_norm_pnr') ? jeab_norm_pnr($secret) : '';
        if ($norm !== '' && str_contains($hay, $norm)) {
            return true;
        }
        $shown = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize($secret) : '';
        if ($shown !== '' && str_contains($hay, $shown)) {
            return true;
        }
    }
    return false;
}

$pnr = '199001011234';
$secrets = [$pnr];

$kept = jeab_ordrar_fortnox_merge_order_rows(
    [[
        'RowId' => 11,
        'Description' => 'Batteri',
        'Price' => 100,
        'DeliveredQuantity' => '1',
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    ]],
    [[
        'row_id' => '11',
        'article' => '',
        'description' => 'Batteri',
        'housework' => 'STORAGESELFPRODUCEDELECTRICITY',
        'qty' => '1',
        'price' => '100',
    ]]
);
lr_assert((int) ($kept[0]['RowId'] ?? 0) === 11, 'ett rad-id som finns på ordern får följa med');

$foreign = jeab_ordrar_fortnox_merge_order_rows(
    [[
        'RowId' => 91001,
        'Description' => 'Batteri',
        'Price' => 100,
        'DeliveredQuantity' => '1',
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    ]],
    [[
        'row_id' => '54561',
        'article' => '',
        'description' => 'Batteri',
        'housework' => 'STORAGESELFPRODUCEDELECTRICITY',
        'qty' => '1',
        'price' => '100',
    ]]
);
lr_assert((int) ($foreign[0]['RowId'] ?? 0) === 91001, 'rad-id från ett annat dokument byts mot det nya dokumentets id');

$freshRows = jeab_ordrar_fortnox_merge_fresh_rows(
    [
        ['RowId' => 91001, 'Description' => 'Batteri', 'Price' => 40000, 'DeliveredQuantity' => '1', 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY'],
        ['RowId' => 91002, 'Description' => 'Schablon', 'Price' => 5000, 'DeliveredQuantity' => '1', 'HouseWork' => false],
    ],
    [[
        'row_id' => '54561',
        'article' => '',
        'description' => 'Batteri',
        'housework' => 'STORAGESELFPRODUCEDELECTRICITY',
        'qty' => '1',
        'price' => '40000',
    ]],
    [
        ['Description' => 'Batteri', 'Price' => 40000, 'DeliveredQuantity' => '1', 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY'],
        ['Description' => 'Schablon', 'Price' => 5000, 'DeliveredQuantity' => '1'],
    ]
);
$freshDesc = [];
$freshIds = [];
foreach ($freshRows as $line) {
    $freshDesc[] = (string) ($line['Description'] ?? '');
    if (isset($line['RowId'])) {
        $freshIds[] = (string) $line['RowId'];
    }
}
lr_assert(in_array('Batteri', $freshDesc, true) && in_array('Schablon', $freshDesc, true), 'en ny order behåller kalkylens rader när formuläret bara fyller första raden');
lr_assert(!in_array('54561', $freshIds, true), 'den ofullständiga uppdateringen tar inte med det låsta rad-id:t');

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$reminder = jeab_create_reminder($store, 'Bo Berg', null, 'Order', null, [
    'customer' => 'Bo Berg',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$order = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'customer' => 'Bo Berg',
    'huvudsokande' => 'Bo Berg',
    'personnummer' => $pnr,
    'email' => 'bo.berg@kund.se',
    'phone' => '0701112233',
    'address' => 'Testgatan 1',
    'postnr' => '17741',
    'postnummer' => '17741',
    'ort' => 'Jarfalla',
    'fastighetsbeteckning' => 'JARFALLA TEST 1:1',
    'fortnox_order' => '1909',
    'fortnox_customer' => 'K9',
    'pris' => 70000,
    'kundpris' => 70000,
    'kalkyl' => [
        'sokande' => 1,
        'b14' => 0,
        'b3' => 2,
        'b15' => 1,
        'laddare' => 0,
        'sigen_typ' => 'tp2',
        'sigen_kw' => '12.0',
    ],
    'fortnox_order_rows' => [[
        'RowId' => 54561,
        'Description' => 'Batteri',
        'Price' => 1,
        'DeliveredQuantity' => '1',
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
    ]],
    'steps' => [],
]);
$orderId = (int) $order['reminder_id'];
$formula = jeab_ordrar_fortnox_formula_order_rows(jeab_purchase_get($orderId) ?? []);
lr_assert(count($formula) >= 2, 'batterikalkylen har mer än en rad');

$calls = [];
$bodies = [];
$orders = [
    '1909' => [
        'DocumentNumber' => '1909',
        'CustomerNumber' => 'K9',
        'Cancelled' => false,
        'WarehouseReady' => true,
        'InvoiceReference' => '',
        'TaxReductionType' => 'green',
        'OrderRows' => [[
            'RowId' => 54561,
            'Description' => 'Batteri',
            'Price' => 1,
            'DeliveredQuantity' => '1',
            'HouseWork' => true,
            'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        ]],
    ],
];
$taxes = [];
$nextTax = 1;
$nextOrder = 1910;
$nextRow = 91001;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$calls, &$bodies, &$orders, &$taxes, &$nextTax, &$nextOrder, &$nextRow): array {
    $query = [];
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        parse_str(substr($path, $qpos + 1), $query);
    }
    $route = strtok($path, '?') ?: $path;
    $calls[] = $method . ' ' . $route;
    if ($method !== 'GET') {
        $bodies[] = ['method' => $method, 'route' => $route, 'body' => $body];
    }
    if ($method === 'GET' && str_starts_with($route, '/customers')) {
        return ['Customers' => [[
            'CustomerNumber' => 'K9',
            'Name' => 'Bo Berg',
            'Active' => true,
        ]], 'Customer' => [
            'CustomerNumber' => 'K9',
            'Name' => 'Bo Berg',
            'Active' => true,
        ], 'MetaInformation' => ['@TotalPages' => 1]];
    }
    if ($method === 'PUT' && str_starts_with($route, '/customers')) {
        return ['Customer' => ['CustomerNumber' => 'K9', 'Active' => true]];
    }
    if ($method === 'POST' && $route === '/orders' && is_array($body['Order'] ?? null)) {
        $created = $body['Order'];
        $created['DocumentNumber'] = (string) $nextOrder;
        $nextOrder++;
        $created['InvoiceReference'] = '';
        $created['Cancelled'] = false;
        $created['WarehouseReady'] = false;
        $created['TaxReductionType'] = 'green';
        $rows = [];
        foreach (is_array($created['OrderRows'] ?? null) ? $created['OrderRows'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            unset($line['RowId']);
            $line['RowId'] = $nextRow;
            $nextRow++;
            $rows[] = $line;
        }
        $created['OrderRows'] = $rows;
        $orders[$created['DocumentNumber']] = $created;
        return ['Order' => $created];
    }
    if (preg_match('#^/orders/(\d+)$#', $route, $match) === 1) {
        $doc = $match[1];
        if (!isset($orders[$doc])) {
            throw new Exception('Kunde inte hitta ordern.');
        }
        if ($method === 'PUT' && is_array($body['Order'] ?? null)) {
            $incoming = $body['Order'];
            if (isset($incoming['OrderRows']) && is_array($incoming['OrderRows'])) {
                $known = [];
                foreach (is_array($orders[$doc]['OrderRows'] ?? null) ? $orders[$doc]['OrderRows'] : [] as $old) {
                    if (!is_array($old)) {
                        continue;
                    }
                    $id = trim((string) ($old['RowId'] ?? ''));
                    if ($id !== '' && $id !== '0') {
                        $known[$id] = true;
                    }
                }
                foreach ($incoming['OrderRows'] as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $id = trim((string) ($line['RowId'] ?? ''));
                    if ($id !== '' && $id !== '0' && !isset($known[$id])) {
                        throw new Exception('Det finns ingen rad på dokumentet med ID ' . $id);
                    }
                }
                $orders[$doc]['OrderRows'] = $incoming['OrderRows'];
            }
            foreach ($incoming as $key => $value) {
                if ($key === 'OrderRows' || is_array($value)) {
                    continue;
                }
                $orders[$doc][$key] = $value;
            }
        }
        return ['Order' => $orders[$doc]];
    }
    if (preg_match('#^/taxreductions/([^/]+)$#', $route, $match) === 1) {
        $id = rawurldecode($match[1]);
        if ($method === 'DELETE') {
            unset($taxes[$id]);
            return [];
        }
        if (isset($taxes[$id])) {
            return ['TaxReduction' => $taxes[$id]];
        }
        return ['TaxReductions' => [], 'MetaInformation' => ['@TotalPages' => 1]];
    }
    if ($route === '/taxreductions') {
        if ($method === 'POST' && is_array($body['TaxReduction'] ?? null)) {
            $id = (string) $nextTax;
            $nextTax++;
            $taxes[$id] = ['Id' => $id] + $body['TaxReduction'];
            return ['TaxReduction' => $taxes[$id]];
        }
        return ['TaxReductions' => array_values($taxes), 'MetaInformation' => ['@TotalPages' => 1]];
    }
    return [];
};

$storeForCheck = $store;
$beforeCalls = count($calls);
$preview = jeab_ordrar_fortnox_check_run($storeForCheck, ['id' => $orderId]);
lr_assert((int) ($preview['dry'] ?? 0) === 1 && (int) ($preview['written'] ?? 1) === 0 && (int) ($preview['mail'] ?? 1) === 0, 'fortnox_check är torr och skriver inte');
lr_assert((string) ($preview['fortnox_order'] ?? '') === '1909' && (int) ($preview['rows_match'] ?? 1) === 0, 'kollen ser den låsta ordern och att raderna inte är kalkylens');
lr_assert(count($calls) === $beforeCalls, 'torr koll anropar inte Fortnox');
lr_assert(!lr_leak((string) json_encode($preview, JSON_UNESCAPED_UNICODE), $secrets), 'torr koll saknar personnummer');

$lines = jeab_ordrar_fortnox_editor_lines($formula);
$poison = ['54561', '54562', '54563', '54564'];
$input = ['id' => $orderId, 'intent' => 'save'];
foreach (['fx_rowid', 'fx_article', 'fx_desc', 'fx_hw', 'fx_qty', 'fx_price'] as $key) {
    $input[$key] = [];
}
foreach ($lines as $i => $line) {
    $input['fx_rowid'][] = $poison[$i] ?? '';
    $input['fx_article'][] = (string) ($line['article'] ?? '');
    $input['fx_desc'][] = (string) ($line['description'] ?? '');
    $input['fx_hw'][] = (string) ($line['housework'] ?? '');
    $input['fx_qty'][] = (string) ($line['qty'] ?? '');
    $input['fx_price'][] = (string) ($line['price'] ?? '');
}

$saved = null;
$thrown = '';
try {
    $saved = jeab_ordrar_save_fakturering($input);
} catch (Throwable $e) {
    $thrown = $e->getMessage();
}
lr_assert($thrown === '' && is_array($saved), 'sparning av låst order kastar inte');
lr_assert(is_array($saved) && (string) ($saved['fortnox_order'] ?? '') === '1909', 'Spara behåller Fortnox-order 1909');
lr_assert(!in_array('POST /orders', $calls, true), 'Spara skapar ingen ny Fortnox-order');
lr_assert(!lr_leak($thrown, $secrets), 'felet saknar personnummer');

foreach ($bodies as $entry) {
    lr_assert(($entry['method'] ?? '') !== 'POST' || ($entry['route'] ?? '') !== '/orders', 'ingen skrivning skapar en Fortnox-order');
}

$liveRows = is_array($orders['1909']['OrderRows'] ?? null) ? $orders['1909']['OrderRows'] : [];
$have = [];
foreach ($liveRows as $line) {
    if (is_array($line)) {
        $have[] = (string) ($line['Description'] ?? '');
    }
}
$missing = [];
foreach ($formula as $line) {
    $desc = is_array($line) ? (string) ($line['Description'] ?? '') : '';
    if ($desc !== '' && !in_array($desc, $have, true)) {
        $missing[] = $desc;
    }
}
lr_assert($missing === [], 'order 1909 har kalkylens alla rader');
lr_assert(jeab_ordrar_fortnox_rows_match($liveRows, $formula), 'order 1909:s rader matchar kalkylen');

$log = '';
foreach (is_array($saved['activity'] ?? null) ? $saved['activity'] : [] as $entry) {
    if (is_array($entry)) {
        $log .= ' ' . (string) ($entry['text'] ?? '');
    }
}
lr_assert(!str_contains($log, 'en ny skapas'), 'orderloggen skapar ingen ersättningsorder');
lr_assert(!lr_leak($log, $secrets), 'orderloggen saknar personnummer');

$afterWrites = count($bodies);
$liveCheck = jeab_ordrar_fortnox_check_run($store, ['id' => $orderId, 'dry' => 0]);
$checkWrites = count($bodies) - $afterWrites;
lr_assert((int) ($liveCheck['written'] ?? 1) === 0 && (int) ($liveCheck['mail'] ?? 1) === 0 && (int) ($liveCheck['live'] ?? 0) === 1, 'live-kollen läser och skriver inte');
lr_assert((string) ($liveCheck['fortnox_order'] ?? '') === '1909' && (int) ($liveCheck['rows_match'] ?? 0) === 1, 'live-kollen ser 1909 och att raderna stämmer');
lr_assert($checkWrites === 0, 'live-kollen gör ingen Fortnox-skrivning');
lr_assert(!lr_leak((string) json_encode($liveCheck, JSON_UNESCAPED_UNICODE), $secrets), 'live-kollen saknar personnummer');

$denied = jeab_ordrar_fortnox_check_dispatch($store, ['id' => $orderId]);
lr_assert(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver token');

if (!is_dir($tmp . '/secrets') && !mkdir($tmp . '/secrets', 0775, true) && !is_dir($tmp . '/secrets')) {
    fwrite(STDERR, "FAIL secrets\n");
    exit(1);
}
$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $inboxToken]));

$httpRoot = sys_get_temp_dir() . '/jeab-locked-rows-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$logFile = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_LR_DATA');
$root = getenv('JEAB_LR_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $data;
putenv('JEAB_DISABLE_SQLITE=0');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
lr_assert($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_DISABLE_SQLITE=0'
    . ' JEAB_LR_DATA=' . escapeshellarg($tmp)
    . ' JEAB_LR_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($logFile)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
lr_assert(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
lr_assert($up, 'testservern lyssnar');

$curl = static function (array $payload, bool $auth) use ($port, $inboxToken): array {
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    $json = json_decode($text, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text];
};

$noToken = $curl(['action' => 'ordrar.fortnox_check', 'id' => $orderId], false);
lr_assert($noToken['status'] === 401, 'curl utan token är 401');
lr_assert(!lr_leak($noToken['body'], $secrets), 'nekad curl saknar personnummer');

$checked = $curl(['action' => 'ordrar.fortnox_check', 'id' => $orderId], true);
lr_assert($checked['status'] === 200 && (int) ($checked['json']['dry'] ?? 0) === 1 && (int) ($checked['json']['written'] ?? 1) === 0, 'curl-kollen är torr');
lr_assert((string) ($checked['json']['fortnox_order'] ?? '') === '1909' && (int) ($checked['json']['rows_match'] ?? 0) === 1, 'curl rapporterar Fortnox-order 1909 och att raderna stämmer');
lr_assert(!lr_leak($checked['body'], $secrets), 'curl-svaret saknar personnummer');
$serverLog = is_file($logFile) ? (string) file_get_contents($logFile) : '';
lr_assert(!lr_leak($serverLog, $secrets), 'serverloggen saknar personnummer');

echo "fortnox locked order rows: ok\n";
