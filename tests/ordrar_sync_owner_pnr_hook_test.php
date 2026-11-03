<?php

declare(strict_types=1);

/**
 * ordrar.sync_owner_pnr via hook.php, samma väg som en POST till hook.php.
 * Skrivningen fyller plats 2 från orderns andra ägare. Fortnox-förhandsvisningen
 * blir redo. Inget personnummer i svaret och inget kundmejl.
 */

$tmp = sys_get_temp_dir() . '/jeab-sync-owner-hook-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
jeab_ordrar_external_live(false);

function soh(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function soh_make(string $yyMMdd, string $three): string
{
    for ($check = 0; $check <= 9; $check++) {
        $raw = $yyMMdd . $three . (string) $check;
        if (jeab_norm_pnr($raw) !== '') {
            return $raw;
        }
    }
    fwrite(STDERR, "FAIL kunde inte bygga ett giltigt nummer\n");
    exit(1);
}

function soh_same(string $stored, string $raw): bool
{
    $a = jeab_norm_pnr($stored);
    $b = jeab_norm_pnr($raw);

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function soh_leak(string $hay, array $secrets, string $label): void
{
    $bad = preg_match('/\d{12}|(?<!\d)\d{10}(?!\d)|\d{8}\s*[-+]\s*\d{4}|\d{6}\s*[-+]\s*\d{4}/', $hay) === 1;
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($hay, $secret)) {
            $bad = true;
        }
        $norm = jeab_norm_pnr($secret);
        if ($norm !== '' && str_contains($hay, $norm)) {
            $bad = true;
        }
        $shown = function_exists('jeab_pnr_normalize') ? jeab_pnr_normalize($secret) : '';
        if ($shown !== '' && str_contains($hay, $shown)) {
            $bad = true;
        }
    }
    soh(!$bad, $label);
}

$store = jeab_store();
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$purchase = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchase;
jeab_purchases_store_ensure($purchase);
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$pnrA = soh_make('900101', '321');
$pnrB = soh_make('850615', '432');
$secrets = [$pnrA, $pnrB];
$made = jeab_kontakt_find_or_create([
    'name' => 'Kund Test',
    'email' => 'kund.hook@example.test',
    'phone' => '0701000411',
    'address' => 'Flottarestigen 6, 192 51 Sollentuna',
    'city' => 'Sollentuna',
    'postal_code' => '19251',
], false);
soh(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'kontakten finns');
$contactId = (string) $made['id'];
$contact = jeab_kontakt_get($contactId);
$contact['owners'] = [[
    'name' => 'Kund Test',
    'personnummer' => '',
    'contact' => true,
    'email' => 'kund.hook@example.test',
    'phone' => '0701000411',
    'address' => 'Flottarestigen 6',
]];
$contact['street'] = 'Flottarestigen 6';
$contact['postal_code'] = '19251';
$contact['city'] = 'Sollentuna';
$contact['fastighetsbeteckning'] = 'SOLLENTUNA LERTAVLAN 4';
jeab_contact_store()->upsert($contactId, $contact);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
jeab_purchase_put([
    'reminder_id' => 20,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Kund Test',
    'huvudsokande' => 'Ada Huvud',
    'contact_id' => $contactId,
    'email' => 'kund.hook@example.test',
    'phone' => '0701000411',
    'address' => 'Flottarestigen 6, Sollentuna',
    'gata' => 'Flottarestigen 6',
    'postnummer' => '19251',
    'ort' => 'Sollentuna',
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'personnummer' => $pnrA,
    'owners' => [
        ['name' => 'Ada Huvud', 'personnummer' => $pnrA, 'address' => 'Flottarestigen 6'],
        ['name' => 'Bo Med', 'personnummer' => $pnrB, 'address' => 'Flottarestigen 6'],
    ],
    'medsokande' => [
        ['name' => 'Bo Med', 'personnummer' => ''],
    ],
    'pris' => 15000,
    'steps' => [],
]);

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$syncPos = strpos($hookSrc, "=== 'ordrar.sync_owner_pnr'");
soh($syncPos !== false, 'hook.php tar ordrar.sync_owner_pnr');
$load = substr($hookSrc, $syncPos, 700);
soh(str_contains($load, "require_once __DIR__ . '/src/contacts_sole.php'"), 'hook.php laddar contacts_sole före synken');
soh(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php'), 'Ångringshjälpen saknas.'), 'saknad ångringshjälp har ett eget fel');
soh(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php'), 'Kontakten kunde inte läsas.'), 'saknad kontakt har ett eget fel');

$httpRoot = sys_get_temp_dir() . '/jeab-sync-owner-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_SOH_DATA');
$root = getenv('JEAB_SOH_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$GLOBALS['_JEAB_FELLOGG_DIR'] = $data;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT=1');
putenv('DATA_BACKEND=mysql');
$purchase = new PDO('sqlite:' . $data . '/purchases.sqlite');
$purchase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchase;
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
soh($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_DISABLE_SQLITE=0 DATA_BACKEND=mysql CONTACTS_SOLE=1 CONTACTS_STRICT=1'
    . ' JEAB_SOH_DATA=' . escapeshellarg($tmp)
    . ' JEAB_SOH_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
soh(is_resource($proc), 'lokal PHP-server startade');
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
soh($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (array $payload, bool $auth) use ($base, $inboxToken): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
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

$denied = $http(['action' => 'ordrar.sync_owner_pnr', 'order_id' => 20, 'dry' => 0, 'confirm' => 'SAM'], false);
soh($denied['status'] === 401 && ($denied['json']['error'] ?? '') === 'Fel token.', 'hook.php utan token är 401');
soh_leak($denied['body'], $secrets, 'nekad hook saknar personnummer');

$wrote = $http(['action' => 'ordrar.sync_owner_pnr', 'order_id' => 20, 'dry' => 0, 'confirm' => 'SAM'], true);
soh($wrote['status'] === 200 && ($wrote['json']['ok'] ?? false) === true && (int) ($wrote['json']['written'] ?? 0) === 1, 'hook.php skriver');
soh((int) ($wrote['json']['mail'] ?? 1) === 0, 'hook.php skickar inget mejl');
soh(trim((string) ($wrote['json']['batch_id'] ?? '')) !== '', 'hook.php sparar ångring');
soh(($wrote['json']['error'] ?? '') !== 'Numret kunde inte skrivas.', 'hook.php använder inte det generella felet');
soh_leak($wrote['body'], $secrets, 'hook.php-svaret saknar personnummer');

$stored = $store->pdo()->prepare('SELECT row_json FROM contacts WHERE id = ?');
$stored->execute([$contactId]);
$blob = (string) $stored->fetchColumn();
$decoded = json_decode($blob, true);
$owners = is_array($decoded['owners'] ?? null) ? $decoded['owners'] : [];
soh((string) ($owners[0]['name'] ?? '') === 'Kund Test', 'hook.php lämnar kontaktnamnet');
soh(soh_same((string) ($owners[0]['personnummer'] ?? ''), $pnrA), 'hook.php skriver huvudägarens nummer på plats 1');
soh((string) ($owners[1]['name'] ?? '') === 'Bo Med', 'hook.php skapar plats 2');
soh(soh_same((string) ($owners[1]['personnummer'] ?? ''), $pnrB), 'hook.php fyller plats 2 från ägare 2');

$preview = $http(['action' => 'ordrar.fortnox_rerun', 'order_id' => 20], true);
$missing = is_array($preview['json']['missing'] ?? null) ? $preview['json']['missing'] : [];
soh($preview['status'] === 200 && (int) ($preview['json']['mail'] ?? 1) === 0 && (int) ($preview['json']['written'] ?? 1) === 0, 'Fortnox-förhandsvisningen skriver inte');
soh(!in_array('personnummer för medsökande', $missing, true), 'medsökande saknar inte längre nummer');
soh(!in_array('personnummer', $missing, true) && (int) ($preview['json']['ready'] ?? 0) === 1, 'Fortnox-förhandsvisningen är redo');
soh_leak($preview['body'], $secrets, 'Fortnox-förhandsvisningen saknar personnummer');

echo "ordrar sync_owner_pnr hook: ok\n";
