<?php

declare(strict_types=1);

/**
 * Flera leverantörer: inköpskostnaden är summan exkl. moms, leveransdatum är det senaste.
 * Aprilice lagras som skickat (exkl. moms). Lightleap 16 331 inkl. moms blir 13 065.
 * Hooken ordrar.supplier_lines_recompute är torr som standard och skriver inte till Fortnox.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-supplier-lines-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_SERVER['REQUEST_METHOD'] = 'POST';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function sl_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_purchases_store_pdo();
sl_assert($pdo instanceof PDO, 'sqlite är öppen');
sl_assert(jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql'), 'orderflaggan går att sätta');
sl_assert(jeab_purchases_storage_mode() === 'sql', 'ordrar ligger i SQL');

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
if (!is_dir(dirname($secretPath))) {
    mkdir(dirname($secretPath), 0775, true);
}
file_put_contents($secretPath, json_encode(['url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'key' => 'supplier-key'], JSON_UNESCAPED_SLASHES));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-supplier-lines']));

function sl_order(int $id, array $extra = []): array
{
    return jeab_purchase_put(array_merge([
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'kind' => 'batteri',
        'customer' => 'Leverantör Testsson',
        'address' => 'Testgatan 20, 177 00 Järfälla',
        'huvudsokande' => 'Leverantör Testsson',
        'steps' => ['bestalld' => '2026-10-20'],
        'fortnox_order' => 'F-20',
    ], $extra));
}

sl_assert(jeab_ordrar_supplier_exkl_moms(16331) === 13065, '16 331 inkl. moms blir 13 065 exkl. moms');
sl_assert(jeab_ordrar_supplier_exkl_moms(100) === 80, '100 inkl. moms blir 80');
sl_assert(jeab_ordrar_supplier_moms_mode('lightleap', '') === 'inkl' && jeab_ordrar_supplier_moms_mode('aprilice', '') === 'exkl', 'Lightleap är inkl. moms och Aprilice exkl. moms när flaggan saknas');
sl_assert(jeab_ordrar_supplier_moms_mode('lightleap', 'exkl') === 'exkl', 'moms=exkl vinner över Lightleaps standard');

$recomputeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$recomputeAt = strpos($recomputeSrc, 'function jeab_ordrar_supplier_lines_recompute(');
$dispatchAt = strpos($recomputeSrc, 'function jeab_ordrar_supplier_lines_recompute_dispatch(');
sl_assert($recomputeAt !== false && $dispatchAt !== false && $dispatchAt > $recomputeAt, 'omräkningsfunktionen finns');
$recomputeBody = substr($recomputeSrc, (int) $recomputeAt, $dispatchAt - $recomputeAt);
sl_assert(preg_match('/jeab_fortnox|fortnox_/', $recomputeBody) !== 1, 'omräkningen anropar inte Fortnox');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
sl_assert(str_contains($hookSrc, "=== 'ordrar.supplier_lines_recompute'"), 'hook.php tar ordrar.supplier_lines_recompute');

// —— Order 20: Aprilice 11 646 sparat, Lightleap 16 331 inkl. moms, senaste leverans 4/11 ——
sl_order(20, [
    'bestall_kostnad' => 11646,
    'aprilice_order' => '2026104820',
    'leverans' => '2026-10-30',
    'faktisk_leverans' => '2026-10-30',
    'leverans_source' => 'shop',
]);
$first = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.shop_facts',
    'order_id' => 20,
    'supplier' => 'lightleap',
    'lightleap' => 'LL-20',
    'reference' => 'LL-20',
    'kostnad' => 16331,
    'leverans' => '2026-11-04',
]);
$row20 = jeab_purchase_get(20);
sl_assert(($first['ok'] ?? false) === true && !isset($first['conflicts']), 'Lightleap läggs till utan kostnadskonflikt');
sl_assert((int) ($row20['bestall_kostnad'] ?? 0) === 24711, 'order 20 kostar 11 646 + 13 065 = 24 711 exkl. moms');
sl_assert(($row20['leverans'] ?? '') === '2026-11-04' && ($row20['faktisk_leverans'] ?? '') === '2026-11-04', 'order 20 visar senaste leverans 2026-11-04');
sl_assert(($row20['fortnox_order'] ?? '') === 'F-20', 'Fortnox-ordernummer lämnas orört');
$lines20 = jeab_ordrar_supplier_lines_of($row20);
sl_assert(count($lines20) === 2, 'två leverantörsrader sparas');
$byShop = [];
foreach ($lines20 as $line) {
    $byShop[(string) $line['supplier']] = $line;
}
sl_assert((int) ($byShop['aprilice']['amount'] ?? 0) === 11646 && ($byShop['aprilice']['moms'] ?? '') === 'exkl', 'Aprilice 11 646 ligger kvar exkl. moms');
sl_assert((int) ($byShop['lightleap']['amount'] ?? 0) === 13065 && (int) ($byShop['lightleap']['amount_inkl'] ?? 0) === 16331 && ($byShop['lightleap']['moms'] ?? '') === 'inkl', 'Lightleap sparas som 13 065 exkl. och 16 331 inkl.');
sl_assert(jeab_ordrar_v3_bestall_kostnad($row20) === 24711, 'beställningskortets kostnad är summan');
sl_assert(jeab_ordrar_actual_delivery($row20) === '2026-11-04', 'visat leveransdatum är det senaste');
$cells = jeab_ordrar_lista_cells($row20);
sl_assert(($cells['leverans']['text'] ?? '') === '4 nov', 'listan visar 4 nov');
$stale = $row20;
$stale['bestall_kostnad'] = 11646;
$stale['leverans'] = '2026-10-30';
$stale['faktisk_leverans'] = '2026-10-30';
sl_assert(jeab_ordrar_v3_bestall_kostnad($stale) === 24711 && jeab_ordrar_actual_delivery($stale) === '2026-11-04', 'sparade rader vinner över ett gammalt huvud');

$again = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.shop_facts',
    'order_id' => 20,
    'supplier' => 'lightleap',
    'lightleap' => 'LL-20',
    'reference' => 'LL-20',
    'kostnad' => '16 331 kr',
    'inkl_moms' => true,
    'leverans' => '2026-11-04',
]);
$afterAgain = jeab_purchase_get(20);
sl_assert(($again['unchanged'] ?? false) === true && (int) ($afterAgain['bestall_kostnad'] ?? 0) === 24711 && count(jeab_ordrar_supplier_lines_of($afterAgain)) === 2, 'samma Lightleap-referens igen ändrar inte summan');

$earlier = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.shop_facts',
    'order_id' => 20,
    'supplier' => 'aprilice',
    'aprilice' => '2026104820',
    'kostnad' => 11646,
    'leverans' => '2026-10-30',
]);
$afterEarlier = jeab_purchase_get(20);
sl_assert(($earlier['ok'] ?? false) === true && !isset($earlier['conflicts']['kostnad']) && ($afterEarlier['leverans'] ?? '') === '2026-11-04', 'äldre Aprilice-datum skriver inte över 4/11');

$clash = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.shop_facts',
    'order_id' => 20,
    'supplier' => 'lightleap',
    'lightleap' => 'LL-20',
    'reference' => 'LL-20',
    'kostnad' => 1000,
    'moms' => 'exkl',
]);
$afterClash = jeab_purchase_get(20);
sl_assert(isset($clash['conflicts']['kostnad']) && (int) ($afterClash['bestall_kostnad'] ?? 0) === 24711, 'annat belopp på samma leverantör och referens blir konflikt och skrivs inte över');

$exkl = sl_order(21, ['bestall_kostnad' => 11646, 'aprilice_order' => 'A-21', 'leverans' => '2026-10-30', 'leverans_source' => 'shop']);
$explicit = jeab_bokmalen_ordrar_inbound([
    'type' => 'ordrar.shop_facts',
    'order_id' => 21,
    'lightleap' => 'LL-21',
    'kostnad' => 16331,
    'moms' => 'exkl',
    'leverans' => '2026-11-04',
]);
$row21 = jeab_purchase_get(21);
sl_assert(($explicit['ok'] ?? false) === true && (int) ($row21['bestall_kostnad'] ?? 0) === 27977, 'moms=exkl på Lightleap lagras oförändrat och summeras');

// Gammalt huvud utan rader räknas om av hooken när raderna finns, och är torrt utan dry=0.
$dryRow = sl_order(22, [
    'bestall_kostnad' => 11646,
    'leverans' => '2026-10-30',
    'faktisk_leverans' => '2026-10-30',
    'aprilice_order' => 'A-22',
    'fortnox_order' => 'F-22',
    'supplier_lines' => [
        ['supplier' => 'aprilice', 'reference' => 'A-22', 'amount' => 11646, 'moms' => 'exkl', 'leverans' => '2026-10-30'],
        ['supplier' => 'lightleap', 'reference' => 'LL-22', 'amount' => 13065, 'amount_inkl' => 16331, 'moms' => 'inkl', 'leverans' => '2026-11-04'],
    ],
]);
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_ordrar_supplier_lines_recompute_dispatch(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 22, 'dry' => 0]);
sl_assert(($denied['status'] ?? 0) === 401 && (int) (jeab_purchase_get(22)['bestall_kostnad'] ?? 0) === 11646, 'utan token skrivs inget');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-supplier-lines';
$missing = jeab_ordrar_supplier_lines_recompute_dispatch(['action' => 'ordrar.supplier_lines_recompute']);
sl_assert(($missing['ok'] ?? true) === false && ($missing['error'] ?? '') === 'order_id' && ($missing['fortnox'] ?? '') === 'none', 'order_id krävs');
$dry = jeab_ordrar_supplier_lines_recompute_dispatch(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 22]);
$still = jeab_purchase_get(22);
sl_assert(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0 && (int) ($dry['changed'] ?? 0) === 1, 'utelämnad dry skriver inte');
sl_assert((int) ($dry['kostnad'] ?? 0) === 24711 && ($dry['leverans'] ?? '') === '2026-11-04' && ($dry['fortnox'] ?? '') === 'none', 'torrkörningen visar summan och 4/11');
sl_assert((int) ($still['bestall_kostnad'] ?? 0) === 11646 && ($still['leverans'] ?? '') === '2026-10-30' && ($still['fortnox_order'] ?? '') === 'F-22', 'torrkörningen lämnar ordern');
$wet = jeab_ordrar_supplier_lines_recompute_dispatch(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 22, 'dry' => 0]);
$saved = jeab_purchase_get(22);
sl_assert(($wet['written'] ?? 0) === 1 && (int) ($saved['bestall_kostnad'] ?? 0) === 24711 && ($saved['leverans'] ?? '') === '2026-11-04', 'dry=0 skriver summan och senaste datumet');
sl_assert(($saved['fortnox_order'] ?? '') === 'F-22', 'dry=0 rör inte Fortnox-ordern');
unset($GLOBALS['_JEAB_PURCHASE_ROWS'], $GLOBALS['_JEAB_PURCHASE_LIST']);
$second = jeab_ordrar_supplier_lines_recompute_dispatch(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 22, 'dry' => '0']);
sl_assert((int) ($second['changed'] ?? 1) === 0 && (int) ($second['written'] ?? 1) === 0, 'samma omräkning igen skriver inget');

// —— curl mot hook.php, samma tillfälliga lager ——
$router = $tmp . '/router.php';
$workspace = dirname(__DIR__);
mkdir($tmp . '/public', 0775, true);
file_put_contents($router, <<<PHP
<?php
\$tmp = getenv('JEAB_SUPPLIER_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = \$tmp . '/public';
\$GLOBALS['_JEAB_FELLOGG_DIR'] = \$tmp;
putenv('JEAB_DISABLE_SQLITE=0');
\$_ENV['JEAB_DISABLE_SQLITE'] = '0';
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/hook.php') {
    require '{$workspace}/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$port = 18000 + random_int(0, 2000);
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['JEAB_SUPPLIER_TMP'] = $tmp;
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $workspace, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $workspace,
    $env
);
sl_assert(is_resource($server), 'php -S startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$up = false;
$deadline = microtime(true) + 8;
while (microtime(true) < $deadline) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/hook.php');
    if (is_string($probe) && str_contains($probe, '"ok"')) {
        $up = true;
        break;
    }
    usleep(80000);
}
sl_assert($up, 'hook.php svarar');

$curl = static function (array $payload, string $token) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-Webhook-Token: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $body = substr($raw, $headerSize);
    $json = json_decode($body, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $body];
};

sl_order(23, [
    'bestall_kostnad' => 11646,
    'leverans' => '2026-10-30',
    'faktisk_leverans' => '2026-10-30',
    'aprilice_order' => 'A-23',
    'fortnox_order' => 'F-23',
    'supplier_lines' => [
        ['supplier' => 'aprilice', 'reference' => 'A-23', 'amount' => 11646, 'moms' => 'exkl', 'leverans' => '2026-10-30'],
        ['supplier' => 'lightleap', 'reference' => 'LL-23', 'amount' => 13065, 'amount_inkl' => 16331, 'moms' => 'inkl', 'leverans' => '2026-11-04'],
    ],
]);
$curlDenied = $curl(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 23, 'dry' => 0], '');
sl_assert($curlDenied['status'] === 401, 'curl utan token ger 401');
$curlDry = $curl(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 23], 'tok-supplier-lines');
sl_assert($curlDry['status'] === 200 && (int) ($curlDry['json']['dry'] ?? 0) === 1 && (int) ($curlDry['json']['written'] ?? 1) === 0 && (int) ($curlDry['json']['kostnad'] ?? 0) === 24711 && ($curlDry['json']['leverans'] ?? '') === '2026-11-04' && ($curlDry['json']['fortnox'] ?? '') === 'none', 'curl dry visar 24 711 och 2026-11-04');
unset($GLOBALS['_JEAB_PURCHASE_ROWS'], $GLOBALS['_JEAB_PURCHASE_LIST']);
$curlStill = jeab_purchase_get(23);
sl_assert((int) ($curlStill['bestall_kostnad'] ?? 0) === 11646 && ($curlStill['leverans'] ?? '') === '2026-10-30' && ($curlStill['fortnox_order'] ?? '') === 'F-23', 'curl dry lämnar order 23');
$curlWet = $curl(['action' => 'ordrar.supplier_lines_recompute', 'order_id' => 23, 'dry' => 0], 'tok-supplier-lines');
unset($GLOBALS['_JEAB_PURCHASE_ROWS'], $GLOBALS['_JEAB_PURCHASE_LIST']);
$curlSaved = jeab_purchase_get(23);
sl_assert($curlWet['status'] === 200 && (int) ($curlWet['json']['written'] ?? 0) === 1 && (int) ($curlSaved['bestall_kostnad'] ?? 0) === 24711 && ($curlSaved['leverans'] ?? '') === '2026-11-04' && ($curlSaved['fortnox_order'] ?? '') === 'F-23', 'curl dry=0 skriver summan och senaste datumet');

echo "ALL OK\n";
