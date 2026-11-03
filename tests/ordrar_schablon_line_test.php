<?php

declare(strict_types=1);

/**
 * Schablonraden är 3 % (kronoresten) i gamla vyn, Ordrar v3 och Fortnox-raderna.
 * ordrar.schablon_check läser bara och tar aldrig med personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-schablon-line-' . getmypid();
$private = $tmp . '/priv';
$public = $tmp . '/pub';
if (!is_dir($private . '/secrets') && !mkdir($private . '/secrets', 0700, true) && !is_dir($private . '/secrets')) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
if (!is_dir($public) && !mkdir($public, 0700, true) && !is_dir($public)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $private . '/calendar-log.json';
putenv('JEAB_SCHABLON_DATA=' . $private);
putenv('JEAB_SCHABLON_PUBLIC=' . $public);
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
], true) . ";\n");
file_put_contents($private . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-schablon-line']));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';

function sl_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');
jeab_ordrar_external_live(false);
jeab_store(true);

$pnr = '19800101-1234';
$plain = [
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'kundpris' => 70000,
    'pris' => 70000,
    'fastighetsbeteckning' => 'TEST 1:1',
    'huvudsokande' => 'Test Testsson',
    'personnummer' => $pnr,
    'kalkyl' => ['sokande' => 2, 'b14' => 0, 'b3' => 2, 'b15' => 1, 'laddare' => 0],
];
$calc = jeab_offert_calc(jeab_offert_input_from_purchase($plain, 'batteri'));
$kronor = jeab_offert_schablon_kronor($calc, 'batteri');
$h31 = (float) ($calc['h31'] ?? 0);
sl_assert($kronor === 4077, 'batteri utan Sigen är schablonen 4 077');
sl_assert(abs($kronor - ($h31 * 0.03)) < 1.01, '4 077 är 3 % av bruttot, inom en krona');

$rows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $plain);
$byDesc = [];
foreach ($rows as $row) {
    $byDesc[(string) ($row['desc'] ?? '')] = $row;
}
sl_assert(($byDesc['Schablon']['price'] ?? null) === $kronor, 'Fortnox-raden utan Sigen är samma kronor');
sl_assert(array_key_exists('hw', $byDesc['Schablon']) && $byDesc['Schablon']['hw'] === null, 'schablonen har inget husarbete');

$sigen = $plain;
$sigen['kalkyl'] = $plain['kalkyl'] + ['sigen_typ' => 'tp2', 'sigen_kw' => '12.0'];
$sigenRows = jeab_offert_fortnox_kop_rows('batteri', 70000, '', $sigen);
$sigenBy = [];
foreach ($sigenRows as $row) {
    $sigenBy[(string) ($row['desc'] ?? '')] = $row;
}
sl_assert(($sigenBy['Schablon']['price'] ?? null) === $kronor && array_key_exists('hw', $sigenBy['Schablon']) && $sigenBy['Schablon']['hw'] === null, 'Sigen-fakturan behåller schablonen 4 077');
sl_assert(($sigenBy['Schablon']['article'] ?? '') === 'battery', 'Sigen-schablonen använder batteriartikeln');
sl_assert(!isset($sigenBy['Sigen bottenplatta TP2']) && !isset($sigenBy['TP2 tillägg material och arbete']), 'bottenplatta och tillägg är inte fakturarader');

$sol = [
    'kind' => 'sol',
    'company' => 'jarfalla',
    'kundpris' => 199000,
    'pris' => 199000,
    'kalkyl' => ['b14' => 34, 'b3' => 3, 'sokande' => 2, 'b15' => 1, 'laddare' => 1],
];
$solCalc = jeab_offert_calc(jeab_offert_input_from_purchase($sol, 'sol'));
$solRows = jeab_offert_fortnox_kop_rows('sol', 199000, 'Solpaneler', $sol);
$solSchablon = null;
foreach ($solRows as $row) {
    if (($row['desc'] ?? '') === 'Schablon') {
        $solSchablon = (int) ($row['price'] ?? -1);
    }
}
sl_assert($solSchablon === jeab_offert_schablon_kronor($solCalc, 'sol') && $solSchablon > 0, 'sol behåller schablonraden');

$v3 = jeab_ordrar_v3_fakturering_data($plain);
$v3Hit = false;
foreach ($v3['lines'] as $line) {
    if (($line['description'] ?? '') === 'Schablon' && (int) ($line['price'] ?? 0) === $kronor) {
        $v3Hit = true;
    }
}
sl_assert(($v3['source'] ?? '') === 'kalkyl' && $v3Hit, 'Ordrar v3 förifyller schablonen');

$old = jeab_ordrar_fakturering_html($plain, 'jarfalla', 91001);
sl_assert(str_contains($old, 'data-fx-source="kalkyl"') && str_contains($old, 'Schablon') && str_contains($old, (string) $kronor), 'gamla Fakturering visar schablonen innan Fortnox-order finns');

$kalkylHtml = jeab_ordrar_kalkyl_html($plain + ['kind' => 'batteri']);
sl_assert(str_contains($kalkylHtml, 'ordrar-schablon') && str_contains($kalkylHtml, 'data-out="h29"'), 'gamla kalkylen visar batteriets schablon');

$sigenHtml = jeab_ordrar_v3_sigen_kalkyl_html($sigen);
sl_assert(str_contains($sigenHtml, 'data-out="h29"') && str_contains($sigenHtml, 'Schablon'), 'Ordrar v3 visar schablonen i Sigen-kalkylen');

$bookedAt = '2026-06-01 09:00:00';
$missing = $plain + [
    'reminder_id' => 91001,
    'booking_id' => 77,
    'install_start' => $bookedAt,
    'fortnox_order' => '1001',
    'fortnox_order_rows' => [
        ['Description' => 'Batteri', 'ArticleNumber' => '30010001', 'Price' => 131845],
    ],
];
$present = $plain + [
    'reminder_id' => 91002,
    'booking_id' => 78,
    'install_start' => $bookedAt,
    'fortnox_order' => '1002',
    'fortnox_order_rows' => [
        ['Description' => 'Batteri', 'ArticleNumber' => '30010001', 'Price' => 131845],
        ['Description' => 'Schablon', 'ArticleNumber' => '30010001', 'Price' => 4077],
    ],
];
$formulaOrder = $plain + [
    'reminder_id' => 91003,
    'booking_id' => 79,
    'install_start' => $bookedAt,
];
$laddbox = [
    'reminder_id' => 91004,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'pris' => 12000,
    'booking_id' => 80,
    'fortnox_order_rows' => [
        ['Description' => 'Laddbox', 'Price' => 12000],
    ],
];
$empty = [
    'reminder_id' => 91005,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'pris' => 0,
    'booking_id' => 81,
];
foreach ([$missing, $present, $formulaOrder, $laddbox, $empty] as $row) {
    jeab_purchase_put($row);
}
$before = [];
foreach ([91001, 91002, 91003, 91004, 91005] as $id) {
    $before[$id] = jeab_purchase_get($id);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN']);
$denied = jeab_ordrar_schablon_check_dispatch([]);
sl_assert(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false, 'kroken utan POST-token svarar 401');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-schablon-line';
$result = jeab_ordrar_schablon_check_dispatch(['limit' => 50]);
unset($result['status']);
$encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
sl_assert(is_string($encoded), 'svaret går att koda');
$encoded = (string) $encoded;
sl_assert(($result['ok'] ?? false) === true && ($result['read_only'] ?? 0) === 1 && ($result['action'] ?? '') === 'ordrar.schablon_check', 'kroken är läsning');
$ids = [];
foreach ($result['orders'] ?? [] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $ids[] = (int) ($item['id'] ?? 0);
    $keys = array_keys($item);
    sort($keys);
    sl_assert($keys === ['company', 'fortnox_order', 'id', 'kind'], 'listan har bara id, bolag, typ och ordernummer');
}
sl_assert(in_array(91001, $ids, true), 'order utan schablonrad listas');
sl_assert(!in_array(91002, $ids, true), 'order med schablonrad listas inte');
sl_assert(!in_array(91003, $ids, true), 'kalkylens rader räknas som schablon när inget är sparat');
sl_assert(!in_array(91004, $ids, true) && !in_array(91005, $ids, true), 'laddbox och tomt pris hoppas över');
sl_assert(!str_contains($encoded, $pnr) && !str_contains($encoded, '19800101') && !str_contains($encoded, 'personnummer'), 'svaret innehåller inget personnummer');
foreach ([91001, 91002, 91003, 91004, 91005] as $id) {
    sl_assert(jeab_purchase_get($id) === $before[$id], 'kroken skriver inte order ' . $id);
}

$prepend = $tmp . '/prepend.php';
file_put_contents($prepend, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_SCHABLON_DATA');\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_SCHABLON_PUBLIC');\n");
$port = 8765;
$cmd = 'php -d auto_prepend_file=' . escapeshellarg($prepend) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__));
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']];
$env = [
    'PATH' => (string) getenv('PATH'),
    'HOME' => (string) getenv('HOME'),
    'JEAB_SCHABLON_DATA' => $private,
    'JEAB_SCHABLON_PUBLIC' => $public,
];
$proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);
sl_assert(is_resource($proc), 'lokal hook-server startar');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $ready = true;
        break;
    }
    usleep(100000);
}
sl_assert($ready, 'lokal hook-server lyssnar');

$post = static function (array $body, array $headers) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $parts = explode("\r\n\r\n", $raw, 2);
    $json = json_decode($parts[1] ?? '', true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'raw' => $raw];
};
$curlDenied = $post(['action' => 'ordrar.schablon_check'], ['Content-Type: application/json']);
sl_assert($curlDenied['status'] === 401 && ($curlDenied['json']['ok'] ?? true) === false, 'curl utan token får 401');
$curlOk = $post(
    ['action' => 'ordrar.schablon_check', 'limit' => 10],
    ['Content-Type: application/json', 'Authorization: Bearer tok-schablon-line']
);
$curlIds = [];
foreach ($curlOk['json']['orders'] ?? [] as $item) {
    if (is_array($item)) {
        $curlIds[] = (int) ($item['id'] ?? 0);
    }
}
sl_assert($curlOk['status'] === 200 && ($curlOk['json']['read_only'] ?? 0) === 1 && in_array(91001, $curlIds, true) && !in_array(91002, $curlIds, true), 'curl listar bara ordern utan schablon');
sl_assert(!str_contains($curlOk['raw'], $pnr) && !str_contains($curlOk['raw'], '19800101'), 'curl-svaret innehåller inget personnummer');

proc_terminate($proc);
proc_close($proc);

echo "OK  schablonraden och kroken\n";
