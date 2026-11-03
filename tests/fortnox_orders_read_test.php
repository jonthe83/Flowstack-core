<?php

declare(strict_types=1);

/**
 * fortnox.orders_read läser bara. Mockad Fortnox. Inget namn, personnummer eller adress.
 */

$tmp = sys_get_temp_dir() . '/jeab-orders-read-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
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
require_once dirname(__DIR__) . '/src/fortnox_order_link.php';

function or_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$orders = [
    '1904' => [
        'DocumentNumber' => '1904',
        'CustomerNumber' => 'K9',
        'CustomerName' => 'Ada Person',
        'YourReference' => 'Ada Person',
        'Address1' => 'Testgatan 1',
        'Comments' => '199001011234',
        'OrderDate' => '2026-11-01',
        'DeliveryDate' => '2026-11-02',
        'Total' => 167000,
        'Cancelled' => false,
        'WarehouseReady' => true,
        'InvoiceReference' => '0',
        'YourOrderNumber' => '40',
        'ExternalInvoiceReference1' => '',
        'ExternalInvoiceReference2' => '40-ext',
        'DeliveryState' => 'reservation',
        'OrderRows' => [
            ['RowId' => 1, 'Description' => 'Laddbox', 'Price' => 1000],
            ['RowId' => 2, 'Description' => 'Schablon', 'Price' => 7946],
        ],
    ],
    '1915' => [
        'DocumentNumber' => '1915',
        'CustomerNumber' => 'K9',
        'CustomerName' => 'Ada Person',
        'OrderDate' => '2026-11-01',
        'DeliveryDate' => '',
        'Total' => 10,
        'Cancelled' => false,
        'WarehouseReady' => true,
        'InvoiceReference' => '',
        'YourOrderNumber' => '40',
        'ExternalInvoiceReference1' => '',
        'ExternalInvoiceReference2' => '',
        'OrderRows' => [
            ['Description' => 'Rad'],
        ],
    ],
];
$calls = [];
$getOnlySeen = true;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path) use (&$calls, &$orders, &$getOnlySeen): array {
    if (empty($GLOBALS['_JEAB_FORTNOX_GET_ONLY'])) {
        $getOnlySeen = false;
    }
    if ($method !== 'GET') {
        throw new RuntimeException('skrivning ' . $method);
    }
    $query = [];
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        parse_str(substr($path, $qpos + 1), $query);
    }
    $route = strtok($path, '?') ?: $path;
    $calls[] = $method . ' ' . $route;
    if ($route === '/orders') {
        $wanted = (string) ($query['customernumber'] ?? '');
        $list = [];
        foreach ($orders as $order) {
            if ($wanted === '' || (string) ($order['CustomerNumber'] ?? '') === $wanted) {
                $list[] = [
                    'DocumentNumber' => $order['DocumentNumber'],
                    'CustomerNumber' => $order['CustomerNumber'],
                    'CustomerName' => $order['CustomerName'] ?? '',
                ];
            }
        }

        return ['Orders' => $list];
    }
    if (preg_match('#^/orders/(\d+)$#', $route, $match) === 1 && isset($orders[$match[1]])) {
        return ['Order' => $orders[$match[1]]];
    }
    throw new RuntimeException('okänd läsning ' . $route);
};

$byNumber = jeab_fortnox_orders_read([
    'action' => 'fortnox.orders_read',
    'co' => 'jarfalla',
    'order_numbers' => ['1904'],
]);
or_ok(($byNumber['ok'] ?? false) === true && ($byNumber['read_only'] ?? false) === true && ($byNumber['co'] ?? '') === 'jarfalla', 'läsning med ordernummer');
or_ok($calls === ['GET /orders/1904'], 'bara GET av ordern, fick ' . implode(',', $calls));
or_ok($getOnlySeen && !isset($GLOBALS['_JEAB_FORTNOX_GET_ONLY']), 'GET_ONLY är på under anropet och tas bort efteråt');
$one = is_array($byNumber['orders'][0] ?? null) ? $byNumber['orders'][0] : [];
or_ok(
    ($one['DocumentNumber'] ?? '') === '1904'
        && ($one['CustomerNumber'] ?? '') === 'K9'
        && ($one['OrderDate'] ?? '') === '2026-11-01'
        && ($one['DeliveryDate'] ?? '') === '2026-11-02'
        && ($one['Total'] ?? 0) == 167000
        && ($one['Cancelled'] ?? true) === false
        && ($one['WarehouseReady'] ?? false) === true
        && ($one['InvoiceReference'] ?? '') === '0'
        && ($one['YourOrderNumber'] ?? '') === '40'
        && ($one['ExternalInvoiceReference2'] ?? '') === '40-ext'
        && ($one['rows'] ?? 0) === 2,
    'fälten och radantalet följer med, fick ' . json_encode($one)
);
$json = json_encode($byNumber, JSON_UNESCAPED_UNICODE);
or_ok(
    is_string($json) && !str_contains($json, 'Ada') && !str_contains($json, '199001011234') && !str_contains($json, 'Testgatan') && !isset($byNumber['audit']),
    'svaret har inget namn, personnummer, adress eller audit utan jeab_order'
);

$calls = [];
$byCustomer = jeab_fortnox_orders_read([
    'co' => 'JEAB',
    'customer_number' => 'K9',
    'order_numbers' => ['1904'],
]);
or_ok($calls[0] === 'GET /orders' && in_array('GET /orders/1904', $calls, true) && in_array('GET /orders/1915', $calls, true), 'kundnummer listar med GET och hämtar varje order, fick ' . implode(',', $calls));
$docs = [];
foreach (is_array($byCustomer['orders'] ?? null) ? $byCustomer['orders'] : [] as $row) {
    if (is_array($row)) {
        $docs[] = (string) ($row['DocumentNumber'] ?? '');
    }
}
or_ok($docs === ['1904', '1915'], 'samma kund ger 1904 och 1915, fick ' . implode(',', $docs));

$auditPdo = new PDO('sqlite::memory:');
$auditPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_FORTNOX_ORDER_AUDIT_PDO'] = $auditPdo;
jeab_ensure_fortnox_order_audit($auditPdo, 'sqlite');
$auditPdo->prepare(
    'INSERT INTO fortnox_order_audit (created_at, reminder_id, fortnox_order, reason, message) VALUES (?, ?, ?, ?, ?)'
)->execute(['2026-11-01 21:14:00', 40, '1904', 'warehouse_ready', 'Fortnox-ordern 1904 är låst i lagret.']);
$auditPdo->prepare(
    'INSERT INTO fortnox_order_audit (created_at, reminder_id, fortnox_order, reason, message) VALUES (?, ?, ?, ?, ?)'
)->execute(['2026-11-01 21:15:00', 41, '1916', 'warehouse_ready', 'annan order']);
$withAudit = jeab_fortnox_orders_read(['co' => 'jarfalla', 'jeab_order' => '40']);
or_ok(($withAudit['orders'] ?? null) === [] && ($withAudit['jeab_order'] ?? 0) === 40, 'bara jeab_order läser inte Fortnox-ordrar');
$audit = is_array($withAudit['audit'] ?? null) ? $withAudit['audit'] : [];
or_ok(
    count($audit) === 1
        && ($audit[0]['fortnox_order'] ?? '') === '1904'
        && ($audit[0]['reason'] ?? '') === 'warehouse_ready'
        && ($audit[0]['created_at'] ?? '') === '2026-11-01 21:14:00',
    'auditen är bara för jeab-order 40'
);

$blocked = false;
$GLOBALS['_JEAB_FORTNOX_GET_ONLY'] = true;
try {
    (new FortnoxAPI('Jarfalla'))->cancelOrder('1904');
} catch (LogicException $e) {
    $blocked = str_contains($e->getMessage(), 'GET');
}
unset($GLOBALS['_JEAB_FORTNOX_GET_ONLY']);
or_ok($blocked, 'GET_ONLY stoppar en skrivning');

$tooMany = false;
try {
    jeab_fortnox_orders_read(['co' => 'jarfalla', 'order_numbers' => range(1, 21)]);
} catch (InvalidArgumentException $e) {
    $tooMany = str_contains($e->getMessage(), '20');
}
or_ok($tooMany, 'fler än 20 ordernummer stoppas');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$gate = strpos($hook, 'jeab_fortnox_lon_hook_authorized');
$at = strrpos($hook, "'fortnox.orders_read'");
or_ok($gate !== false && $at !== false && $at > $gate, 'hooken ligger bakom fortnox.*');
or_ok(strpos($hook, "'orders_read'") !== false && strpos($hook, '$input[\'type\']') !== false, 'orders_read och type når samma läsning');

$hookDir = sys_get_temp_dir() . '/jeab-orders-read-hook-' . bin2hex(random_bytes(3));
or_ok(mkdir($hookDir, 0775, true) || is_dir($hookDir), 'hook-temp');
or_ok(mkdir($hookDir . '/secrets', 0775, true) || is_dir($hookDir . '/secrets'), 'hook-secrets');
$inkorgKey = 'inkorg-orders-read-key';
$scopedKey = 'bokmalen-orders-read-key';
file_put_contents($hookDir . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'main-incoming-not-inkorg',
    'db' => ['driver' => 'json', 'sqlite_path' => $hookDir . '/app.sqlite', 'json_path' => $hookDir . '/store.json'],
], true) . ";\n");
file_put_contents($hookDir . '/secrets/inkorg-ingest.json', json_encode(['key' => $inkorgKey]));
file_put_contents($hookDir . '/secrets/bokmalen-ordrar-webhook.json', json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
    'key' => $scopedKey,
], JSON_UNESCAPED_SLASHES));
$runner = $hookDir . '/run-hook.php';
$statusFile = $hookDir . '/status.txt';
$callFile = $hookDir . '/calls.txt';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_OR_DATA');
$root = getenv('JEAB_OR_ROOT');
$bearer = getenv('JEAB_OR_BEARER');
$body = getenv('JEAB_OR_BODY');
$query = getenv('JEAB_OR_QUERY') ?: '';
$statusFile = getenv('JEAB_OR_STATUS');
$callFile = getenv('JEAB_OR_CALLS');
$scopedKey = getenv('JEAB_OR_SCOPED');
if (!is_string($tmp) || !is_string($root) || !is_string($bearer) || !is_string($body) || !is_string($statusFile) || !is_string($callFile) || !is_string($scopedKey)) {
    fwrite(STDERR, "missing env\n");
    exit(2);
}
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'] = [
    hash('sha256', $scopedKey) => [
        'name' => 'bokmalen-test',
        'scopes' => [
            'fortnox.orders_read',
            'fortnox.order_export',
            'fortnox.leveransdatum_satt',
            'fortnox.order_attach',
            'fortnox.faktura_*',
            'fortnox.kund_*',
            'fortnox.invoice_order_lookup',
        ],
    ],
];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path) use ($callFile): array {
    file_put_contents($callFile, $method . ' ' . $path . "\n", FILE_APPEND);
    if ($method !== 'GET') {
        throw new RuntimeException('skrivning ' . $method);
    }
    return ['Order' => [
        'DocumentNumber' => '1904',
        'CustomerNumber' => 'K9',
        'CustomerName' => 'Ada Person',
        'OrderDate' => '2026-11-01',
        'Total' => 10,
        'OrderRows' => [],
    ]];
};
putenv('JEAB_DISABLE_SQLITE=1');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_SERVER['HTTP_AUTHORIZATION'] = $bearer === '' ? '' : 'Bearer ' . $bearer;
$_GET = [];
if ($query !== '') {
    parse_str($query, $_GET);
}
$GLOBALS['_JEAB_RAW_BODY'] = $body;
register_shutdown_function(static function () use ($statusFile): void {
    file_put_contents($statusFile, (string) http_response_code());
});
require $root . '/hook.php';
PHP);
$runHook = static function (string $bearer, string $body, string $query = '') use ($runner, $hookDir, $statusFile, $callFile, $scopedKey, $inkorgKey): array {
    @unlink($statusFile);
    @unlink($callFile);
    if (!is_dir($hookDir . '/public') && !mkdir($hookDir . '/public', 0775, true) && !is_dir($hookDir . '/public')) {
        return ['status' => 0, 'json' => [], 'body' => '', 'calls' => 'mkdir'];
    }
    $cmd = 'JEAB_DISABLE_SQLITE=1'
        . ' JEAB_OR_DATA=' . escapeshellarg($hookDir)
        . ' JEAB_OR_ROOT=' . escapeshellarg(dirname(__DIR__))
        . ' JEAB_OR_BEARER=' . escapeshellarg($bearer)
        . ' JEAB_OR_BODY=' . escapeshellarg($body)
        . ' JEAB_OR_QUERY=' . escapeshellarg($query)
        . ' JEAB_OR_STATUS=' . escapeshellarg($statusFile)
        . ' JEAB_OR_CALLS=' . escapeshellarg($callFile)
        . ' JEAB_OR_SCOPED=' . escapeshellarg($scopedKey)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    or_ok(is_resource($proc), 'hook-processen startade');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $text = is_string($stdout) ? $stdout : '';
    $err = is_string($stderr) ? $stderr : '';
    or_ok(!str_contains($text . $err, $inkorgKey) && !str_contains($text . $err, $scopedKey), 'svaret läcker ingen nyckel');
    $json = json_decode($text, true);
    return [
        'status' => is_file($statusFile) ? (int) file_get_contents($statusFile) : 0,
        'json' => is_array($json) ? $json : [],
        'body' => $text,
        'calls' => is_file($callFile) ? (string) file_get_contents($callFile) : '',
    ];
};
$readBody = json_encode(['action' => 'fortnox.orders_read', 'co' => 'jarfalla', 'order_numbers' => ['1904']], JSON_UNESCAPED_UNICODE);
$read = $runHook($inkorgKey, $readBody);
or_ok($read['status'] === 200 && ($read['json']['ok'] ?? false) === true && ($read['json']['read_only'] ?? false) === true && ($read['json']['action'] ?? '') === 'fortnox.orders_read' && ($read['json']['orders'][0]['DocumentNumber'] ?? '') === '1904' && !str_contains($read['body'], 'Ada'), 'inkorgsnyckel får orders_read, fick ' . $read['status'] . ' ' . $read['body']);
or_ok($read['calls'] === "GET /orders/1904\n", 'inkorgsnyckelns läsning är bara GET, fick ' . json_encode($read['calls']));
$viaType = $runHook($inkorgKey, json_encode(['type' => 'fortnox.orders_read', 'co' => 'jarfalla', 'order_numbers' => ['1904']]));
or_ok($viaType['status'] === 200 && ($viaType['json']['ok'] ?? false) === true && ($viaType['json']['action'] ?? '') === 'fortnox.orders_read', 'type fortnox.orders_read når läsningen, fick ' . $viaType['status'] . ' ' . $viaType['body']);
$viaBare = $runHook($scopedKey, json_encode(['action' => 'orders_read', 'co' => 'jarfalla', 'order_numbers' => ['1904']]));
or_ok($viaBare['status'] === 200 && ($viaBare['json']['error'] ?? '') !== 'not_found' && ($viaBare['json']['read_only'] ?? false) === true, 'action orders_read är inte 404, fick ' . $viaBare['status'] . ' ' . $viaBare['body']);
$viaEvent = $runHook($scopedKey, json_encode(['event' => 'orders_read', 'co' => 'JEAB', 'order_numbers' => ['1904']]));
or_ok($viaEvent['status'] === 200 && ($viaEvent['json']['error'] ?? '') !== 'not_found', 'event orders_read är inte 404, fick ' . $viaEvent['status'] . ' ' . $viaEvent['body']);
$viaQuery = $runHook($scopedKey, json_encode(['co' => 'jarfalla', 'order_numbers' => ['1904']]), 'action=fortnox.orders_read');
or_ok($viaQuery['status'] === 200 && ($viaQuery['json']['error'] ?? '') !== 'not_found' && ($viaQuery['json']['orders'][0]['DocumentNumber'] ?? '') === '1904', 'query-action når läsningen, fick ' . $viaQuery['status'] . ' ' . $viaQuery['body']);
$viaCase = $runHook($scopedKey, json_encode(['action' => 'Fortnox.Orders_Read', 'co' => 'jarfalla', 'order_numbers' => ['1904']]));
or_ok($viaCase['status'] === 200 && ($viaCase['json']['action'] ?? '') === 'fortnox.orders_read', 'skiftläge i namnet är samma läsning, fick ' . $viaCase['status'] . ' ' . $viaCase['body']);
foreach ([
    'fortnox.leveransdatum_satt',
    'fortnox.order_export',
    'fortnox.order_attach',
    'fortnox.faktura_kopiera',
    'fortnox.kund_hamta',
    'fortnox.invoice_order_lookup',
] as $writeAction) {
    $denied = $runHook($inkorgKey, json_encode(['action' => $writeAction, 'co' => 'jarfalla', 'account' => 'JEAB', 'order' => '1904', 'order_id' => '40', 'dry' => 0, 'confirm' => 'SAM']));
    or_ok($denied['status'] === 401 && ($denied['json']['error'] ?? '') === 'Fel token.' && $denied['calls'] === '', 'inkorgsnyckel nekas ' . $writeAction . ', fick ' . $denied['status'] . ' ' . $denied['body']);
}
$writeAsType = $runHook($scopedKey, json_encode(['type' => 'fortnox.order_export', 'account' => 'JEAB', 'dry' => 0, 'confirm' => 'SAM']));
or_ok($writeAsType['status'] === 404 && ($writeAsType['json']['error'] ?? '') === 'not_found' && $writeAsType['calls'] === '', 'type på en skrivning styrs inte om till fortnox, fick ' . $writeAsType['status'] . ' ' . $writeAsType['body']);
$noToken = $runHook('', $readBody);
or_ok($noToken['status'] === 401 && ($noToken['json']['error'] ?? '') === 'Fel token.', 'orders_read utan nyckel är 401');

echo "fortnox_orders_read_test ok\n";
