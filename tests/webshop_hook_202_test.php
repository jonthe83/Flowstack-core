<?php

declare(strict_types=1);

/**
 * P1-20 webshop-hook svarar 202 efter validering och hämtar sedan.
 * Påhittad order. Inga hemligheter, inget utskick.
 */

$root = dirname(__DIR__);

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'hook') {
    $tmp = (string) ($argv[2] ?? '');
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
    $GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
    $GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
    putenv('JEAB_DISABLE_SQLITE=1');
    $_ENV['JEAB_DISABLE_SQLITE'] = '1';
    putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
    $_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
    $purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
    $logPdo = new PDO('sqlite:' . $tmp . '/webshop-log.sqlite');
    $logPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['_JEAB_LOG_PDO'] = $logPdo;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['SCRIPT_NAME'] = '/webshop-hook.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/webshop-hook.php';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_SERVER['REQUEST_URI'] = '/webshop-hook.php?site=malmo';
    $_SERVER['HTTP_X_WEBSHOP_KEY'] = 'test-webshop-key-not-real';
    $_GET = ['site' => 'malmo'];
    $_POST = [
        'orderId' => '11111111-2222-4333-8444-555555555555',
        'siteId' => 'malmo',
    ];
    $order = [
        'number' => '91009',
        'id' => '11111111-2222-4333-8444-555555555555',
        'createdDate' => '2026-10-14T11:04:35.419Z',
        'status' => 'APPROVED',
        'paymentStatus' => 'PAID',
        'priceSummary' => ['total' => ['amount' => '8490.00']],
        'buyerInfo' => ['email' => 'kund@example.invalid'],
        'billingInfo' => [
            'address' => ['addressLine' => 'Testgatan 1', 'postalCode' => '236 33', 'city' => 'Höllviken'],
            'contactDetails' => ['firstName' => 'Test', 'lastName' => 'Kund', 'phone' => '+46700000000'],
        ],
        'lineItems' => [[
            'productName' => ['original' => 'Easee laddbox med installation'],
            'quantity' => 1,
            'price' => ['amount' => '8490.00'],
            'physicalProperties' => ['shippable' => true],
            'catalogReference' => ['options' => ['options' => ['Välj färg' => 'Svart']]],
        ]],
    ];
    $GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $site, string $m, string $path, ?array $b) use ($order): array {
        return ['order' => $order];
    };
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
        throw new RuntimeException('Fortnox anropas inte i hook-testet');
    };
    require $root . '/webshop-hook.php';
    $bought = 0;
    foreach (jeab_purchase_all() as $row) {
        if (is_array($row) && (string) ($row['webshop']['number'] ?? '') === '91009') {
            $bought++;
        }
    }
    fwrite(STDOUT, "\nSTATUS=" . (int) http_response_code() . " BOUGHT=" . $bought . "\n");
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

function wh202(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents($root . '/webshop-hook.php');
$pos202 = strpos($src, 'jeab_webshop_hook_accepted()');
$posFetch = strpos($src, 'jeab_webshop_fetch_and_ingest');
wh202($pos202 !== false, 'hook anropar jeab_webshop_hook_accepted');
wh202($posFetch !== false, 'hook hämtar efter 202');
wh202($pos202 < $posFetch, '202 går före hämtningen');
wh202(!str_contains($src, 'http_response_code(424)'), 'hook svarar inte 424 efter misslyckad hämtning');

$wixSrc = (string) file_get_contents($root . '/src/webshop_wix.php');
$fnAt = strpos($wixSrc, 'function jeab_webshop_hook_accepted(');
wh202($fnAt !== false, 'jeab_webshop_hook_accepted finns');
$fnSrc = substr($wixSrc, $fnAt, 900);
wh202(str_contains($fnSrc, 'http_response_code(202)'), 'accepted sätter 202');
wh202(str_contains($fnSrc, 'fastcgi_finish_request'), 'accepted släpper klienten med fastcgi');

$tmp = sys_get_temp_dir() . '/jeab-webshop-hook-202-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/secrets', 0700, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/webshop-webhook.json', json_encode([
    'key' => 'test-webshop-key-not-real',
], JSON_UNESCAPED_SLASHES));
file_put_contents($tmp . '/secrets/einstein-bug-webhook.json', json_encode([
    'url' => 'https://hooks.example.invalid/bug',
    'key' => 'test-einstein-key-not-real',
], JSON_UNESCAPED_SLASHES));
$logReady = new PDO('sqlite:' . $tmp . '/webshop-log.sqlite');
$logReady->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$logReady->exec('CREATE TABLE IF NOT EXISTS log_docs (doc_key TEXT PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL)');
$logReady->prepare('INSERT INTO log_docs (doc_key, payload, updated_at) VALUES (?, ?, ?)')->execute([
    'flag:webshop_orders',
    '{"ok":true}',
    '2026-10-31 12:00:00',
]);

$cmd = [
    PHP_BINARY,
    '-d', 'display_errors=0',
    __FILE__,
    'hook',
    $tmp,
];
$proc = proc_open($cmd, [
    0 => ['file', '/dev/null', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes, $root);
wh202(is_resource($proc), 'hook-worker startade');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
wh202($code === 0, 'hook-worker avslutade, stderr=' . substr((string) $err, 0, 200));
wh202(str_contains((string) $out, '{"ok":true}'), 'svaret är ok:true, fick ' . substr((string) $out, 0, 120));
wh202(str_contains((string) $out, 'STATUS=202'), 'status är 202, fick ' . substr((string) $out, 0, 160));
wh202(str_contains((string) $out, 'BOUGHT=1'), 'hämtningen kördes efter 202');

echo "webshop hook 202: ok\n";
