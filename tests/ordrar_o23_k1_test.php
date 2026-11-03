<?php

declare(strict_types=1);

/**
 * O23: två parallella «Skapa order och boka» ger en bokning och en Fortnox-order.
 * K1: «Skapa faktura» kräver bekräftelse. Utan den skrivs ingen faktura.
 * Påhittad testkund. Fortnox är en stub. Inget utskick.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'slutfor-worker') {
    $dir = (string) ($argv[2] ?? '');
    $id = (int) ($argv[3] ?? 0);
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $dir;
    $GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
    require dirname(__DIR__) . '/src/bootstrap.php';
    $GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
    jeab_ordrar_external_live(false);
    $GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row) use ($dir): array {
        usleep(300000);
        file_put_contents($dir . '/fx.log', "1\n", FILE_APPEND | LOCK_EX);
        $row['fortnox_order'] = '4501';
        $row['updated_at'] = jeab_now();

        return jeab_purchase_put($row);
    };
    try {
        jeab_ordrar_slutfor(jeab_store(), [
            'id' => $id,
            'kind' => 'laddbox',
            'pris' => '12 500',
            'install_what' => 'Laddbox',
            'huvudsokande' => 'Ada Slutfor',
            'huvudsokande_personnummer' => '198001010000',
            'fastighetsbeteckning' => 'TESTBY 1:1',
            'huvudsokande_address' => 'Testgatan 1, Järfälla',
            'owner_name' => ['Ada Slutfor'],
            'owner_pnr' => [''],
            'owner_address' => ['Testgatan 1, Järfälla'],
            'owner_forslag' => [],
            'payouts_form' => '1',
        ]);
    } catch (Throwable $e) {
        file_put_contents($dir . '/slutfor-err-' . getmypid(), $e->getMessage());
        exit(1);
    }
    exit(0);
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'invoice-worker') {
    $dir = (string) ($argv[2] ?? '');
    $id = (int) ($argv[3] ?? 0);
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $dir;
    $GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
    require dirname(__DIR__) . '/src/bootstrap.php';
    $GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
    jeab_ordrar_external_live(false);
    $GLOBALS['_JEAB_FORTNOX_INVOICE'] = static function (string $no) use ($dir): array {
        unset($no);
        usleep(300000);
        file_put_contents($dir . '/inv.log', "1\n", FILE_APPEND | LOCK_EX);

        return ['Invoice' => ['DocumentNumber' => '7771'], 'Order' => ['InvoiceReference' => '7771']];
    };
    $row = jeab_purchase_get($id);
    if (!is_array($row)) {
        file_put_contents($dir . '/inv-err-' . getmypid(), 'saknas');
        exit(1);
    }
    try {
        jeab_ordrar_fortnox_make_invoice_once($row, false);
    } catch (Throwable $e) {
        file_put_contents($dir . '/inv-err-' . getmypid(), $e->getMessage());
        exit(1);
    }
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-o23-k1-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
putenv('JEAB_O23_TMP=' . $tmp);
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/pw.json');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'passwords_file' => $tmp . '/pw.json',
], true) . ";\n");
$hash = password_hash('o23-test-kod', PASSWORD_DEFAULT);
file_put_contents($tmp . '/pw.json', json_encode([
    'secret' => 'o23-test-secret',
    'hashes' => [['hash' => $hash, 'email' => 'o23@jarfalla.example.test']],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
jeab_ordrar_external_live(false);

function o23_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function o23_lines(string $path): int
{
    $text = is_file($path) ? (string) file_get_contents($path) : '';

    return substr_count($text, "1\n");
}

$ordrarSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$fxSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_fortnox.php');
$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$jsSrc = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
o23_ok(str_contains($ordrarSrc, 'function jeab_ordrar_reminder_effect_lock'), 'låsfunktionen finns');
o23_ok(str_contains($ordrarSrc, "SELECT GET_LOCK(") && str_contains($ordrarSrc, 'jeab_reminder_lock'), 'låset är GET_LOCK och fil-lås per påminnelse');
o23_ok(str_contains($jsSrc, "setAttribute('data-busy', '1')") && str_contains($jsSrc, 'data-v3-hold') && str_contains($jsSrc, 'submitHeldStep(form, submitter)') && !str_contains($jsSrc, "' om 6 sekunder'"), 'knappen blir busy och fakturan skickas direkt');
o23_ok(str_contains($indexSrc, 'jeab_ordrar_fakturering_gate') && str_contains($fxSrc, 'function jeab_ordrar_faktura_confirmed') && str_contains($fxSrc, 'jeab_ordrar_faktura_confirmed($input)'), 'servern kräver bekräftelse före faktura');
o23_ok(jeab_ordrar_faktura_confirmed(['faktura_bekrafta' => '1']) === true, 'bekräftad faktura');
o23_ok(jeab_ordrar_faktura_confirmed(['intent' => 'faktura']) === false, 'intent utan bekräftelse räcker inte');

$store = jeab_store();
$reminder = jeab_create_reminder($store, 'Ada Slutfor', '2026-11-02 00:00:00', 'test', null, [
    'customer' => 'Ada Slutfor',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1, Järfälla',
]);
$rid = (int) $reminder['id'];
o23_ok($rid > 0, 'påminnelsen finns');
jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Slutfor',
    'address' => 'Testgatan 1, Järfälla',
    'huvudsokande' => 'Ada Slutfor',
    'steps' => [],
]);

$php = PHP_BINARY;
$spawn = static function (string $mode, int $id) use ($php, $tmp): array {
    $proc = proc_open(
        [$php, __FILE__, $mode, $tmp, (string) $id],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    return [$proc, $pipes];
};
[$a, $pipesA] = $spawn('slutfor-worker', $rid);
[$b, $pipesB] = $spawn('slutfor-worker', $rid);
o23_ok(is_resource($a) && is_resource($b), 'två slutför-arbetare startade');
$errA = stream_get_contents($pipesA[2]);
$errB = stream_get_contents($pipesB[2]);
$outA = stream_get_contents($pipesA[1]);
$outB = stream_get_contents($pipesB[1]);
fclose($pipesA[1]);
fclose($pipesA[2]);
fclose($pipesB[1]);
fclose($pipesB[2]);
$codeA = proc_close($a);
$codeB = proc_close($b);
$errs = '';
foreach (glob($tmp . '/slutfor-err-*') ?: [] as $errFile) {
    $errs .= ' ' . (string) file_get_contents($errFile);
}
o23_ok($codeA === 0 && $codeB === 0, 'båda slutför tar slut, ' . $codeA . '/' . $codeB . ' ' . $errA . $errB . $outA . $outB . $errs);
o23_ok(o23_lines($tmp . '/fx.log') === 1, 'Fortnox-order skapas en gång, fick ' . o23_lines($tmp . '/fx.log'));
jeab_purchase_cache_forget();
$after = jeab_purchase_get($rid);
$bookings = jeab_booked_bookings(jeab_store());
$linked = 0;
foreach ($bookings as $booking) {
    if ((int) ($booking['id'] ?? 0) === (int) ($after['booking_id'] ?? 0)) {
        $linked++;
    }
}
o23_ok(is_array($after) && (int) ($after['reminder_id'] ?? 0) === $rid, 'ordern pekar kvar på samma påminnelse');
o23_ok((string) ($after['fortnox_order'] ?? '') === '4501', 'ett Fortnox-ordernummer sparas');
o23_ok(count($bookings) === 1 && (int) ($after['booking_id'] ?? 0) > 0 && $linked === 1, 'en bokning och den sitter kvar på ordern, fick ' . count($bookings));
$still = jeab_get_reminder(jeab_store(), $rid);
o23_ok(is_array($still) && (int) ($still['id'] ?? 0) === $rid, 'påminnelsen finns kvar');

jeab_purchase_cache_forget();
$again = jeab_ordrar_slutfor(jeab_store(), [
    'id' => $rid,
    'kind' => 'laddbox',
    'pris' => '12 500',
    'install_what' => 'Laddbox',
    'huvudsokande' => 'Ada Slutfor',
    'huvudsokande_personnummer' => '198001010000',
    'fastighetsbeteckning' => 'TESTBY 1:1',
    'huvudsokande_address' => 'Testgatan 1, Järfälla',
]);
o23_ok(o23_lines($tmp . '/fx.log') === 1 && (int) ($again['booking_id'] ?? 0) === (int) ($after['booking_id'] ?? 0), 'ett till klick skapar varken bokning eller Fortnox-order');
o23_ok(count(jeab_booked_bookings(jeab_store())) === 1, 'bokningen är fortfarande en');

$invoiceId = $rid + 50;
jeab_purchase_put([
    'reminder_id' => $invoiceId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Test Kund',
    'fortnox_order' => '4502',
    'fortnox_att_betala' => '7 992 kr',
    'pris' => 9990,
    'kundpris' => 9990,
    'fortnox_order_rows' => [[
        'RowId' => '1',
        'ArticleNumber' => '100',
        'Description' => 'Laddbox test',
        'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        'DeliveredQuantity' => '1',
        'Price' => '9990',
    ]],
    'steps' => ['order_skriven' => '2026-10-02'],
]);
[$ia, $pipesIa] = $spawn('invoice-worker', $invoiceId);
[$ib, $pipesIb] = $spawn('invoice-worker', $invoiceId);
o23_ok(is_resource($ia) && is_resource($ib), 'två faktura-arbetare startade');
$ierrA = stream_get_contents($pipesIa[2]);
$ierrB = stream_get_contents($pipesIb[2]);
fclose($pipesIa[1]);
fclose($pipesIa[2]);
fclose($pipesIb[1]);
fclose($pipesIb[2]);
$icodeA = proc_close($ia);
$icodeB = proc_close($ib);
$ierrs = '';
foreach (glob($tmp . '/inv-err-*') ?: [] as $errFile) {
    $ierrs .= ' ' . (string) file_get_contents($errFile);
}
o23_ok($icodeA === 0 && $icodeB === 0, 'båda fakturaanropen tar slut, ' . $icodeA . '/' . $icodeB . ' ' . $ierrA . $ierrB . $ierrs);
o23_ok(o23_lines($tmp . '/inv.log') === 1, 'Fortnox-faktura skapas en gång, fick ' . o23_lines($tmp . '/inv.log'));
jeab_purchase_cache_forget();
$invoiced = jeab_purchase_get($invoiceId);
o23_ok(is_array($invoiced) && (string) ($invoiced['fortnox_invoice'] ?? '') === '7771' && (string) ($invoiced['fortnox_order'] ?? '') === '4502', 'fakturanummer sitter på samma order');

$previewId = $invoiceId + 7;
$openInvoice = [
    'reminder_id' => $previewId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Test Kund',
    'huvudsokande' => 'Test Kund',
    'fortnox_order' => '4502',
    'fortnox_att_betala' => '7 992 kr',
    'pris' => 9990,
    'kundpris' => 9990,
    'fortnox_order_rows' => [[
        'RowId' => '1',
        'ArticleNumber' => '100',
        'Description' => 'Laddbox test',
        'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE',
        'DeliveredQuantity' => '1',
        'Price' => '9990',
    ]],
    'steps' => ['order_skriven' => '2026-10-02'],
];
jeab_purchase_put($openInvoice);
$preview = jeab_ordrar_v3_html($openInvoice, 'jarfalla', $previewId);
o23_ok(str_contains($preview, 'data-v3-hold="1"') && str_contains($preview, 'skapas i Fortnox direkt') && !str_contains($preview, 'Ångra stoppar det') && !str_contains($preview, 'data-v3-faktura-confirm'), 'fakturan skapas direkt, utan extra dialog');
o23_ok(str_contains($preview, 'Att betala') && str_contains($preview, 'Laddbox test') && str_contains($preview, 'name="fx_desc[]"'), 'steget visar Att betala och raden');
o23_ok(str_contains($preview, 'name="intent" value="faktura"') && str_contains($preview, 'data-v3-faktura-flag'), 'bekräftad knapp postar faktura');
o23_ok(str_contains($preview, 'Inget skickas till kunden'), 'texten säger att inget skickas till kunden');
o23_ok(substr_count($preview, 'data-v3-primary=') === 1, 'en grön knapp före bekräftelsen');

jeab_auth_data(true);
$cookie = jeab_auth_issue_value('o23@jarfalla.example.test', $hash);
o23_ok(is_string($cookie) && $cookie !== '', 'testkaka');
$_COOKIE['jeab_auth'] = $cookie;
$csrf = jeab_csrf_token();
o23_ok($csrf !== '', 'csrf-token');

$root = dirname(__DIR__);
$router = $tmp . '/router.php';
file_put_contents($router, <<<PHP
<?php
\$tmp = getenv('JEAB_O23_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_FELLOGG_DIR'] = \$tmp;
\$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/pw.json');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$root}/index.php';
    return true;
}
return false;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
o23_ok($probe !== false, 'port vald');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $serverPipes,
    $root,
    ['JEAB_O23_TMP' => $tmp, 'JEAB_PASSWORDS_FILE' => $tmp . '/pw.json']
);
o23_ok(is_resource($server), 'php -S');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$deadline = microtime(true) + 5;
$up = false;
do {
    $ping = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=links');
    if (is_string($ping) && $ping !== '') {
        $up = true;
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);
o23_ok($up, 'testservern lyssnar');

$curl = static function (string $url, array $headers, ?array $post = null) use ($cookie): array {
    $ch = curl_init($url);
    $headers[] = 'Cookie: jeab_auth=' . rawurlencode($cookie);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [$status, substr($raw, $headerSize)];
};
$base = 'http://127.0.0.1:' . $port;
$js = $curl($base . '/assets/js/ordrar-v3.js', []);
o23_ok($js[0] === 200 && str_contains($js[1], "setAttribute('data-busy', '1')") && str_contains($js[1], 'data-v3-hold') && str_contains($js[1], 'submitHeldStep(form, submitter)'), 'curl hämtar direktutskicket');
$page = $curl($base . '/index.php?p=ordrar&co=jarfalla&id=' . $previewId, ['Accept: text/html']);
if (str_contains($page[1], 'Ordern hittades inte')) {
    // Samma kända avvikelse som tidigare: testservern ser inte orderraden.
    o23_ok(false, 'curl visar bekräftelsen med rad och Tillbaka, status ' . $page[0]);
}
o23_ok($page[0] === 200 && str_contains($page[1], 'data-v3-hold="1"') && str_contains($page[1], 'Laddbox test') && !str_contains($page[1], 'data-v3-faktura-confirm'), 'curl visar fakturan med Ångra och utan dialog, status ' . $page[0]);
$beforeInvoice = (string) (jeab_purchase_get($previewId)['fortnox_invoice'] ?? '');
$denied = $curl($base . '/index.php?p=ordrar&co=jarfalla&id=' . $previewId, [
    'X-Requested-With: XMLHttpRequest',
    'Accept: application/json',
], [
    '_csrf' => $csrf,
    'action' => 'ordrar.fakturering',
    'id' => (string) $previewId,
    'intent' => 'faktura',
]);
$deniedJson = json_decode($denied[1], true);
$afterDenied = jeab_purchase_get($previewId);
o23_ok($denied[0] === 422 && is_array($deniedJson) && ($deniedJson['ok'] ?? true) === false, 'curl utan bekräftelse nekas, status ' . $denied[0] . ' ' . substr($denied[1], 0, 240));
o23_ok((string) ($afterDenied['fortnox_invoice'] ?? '') === $beforeInvoice && (string) ($afterDenied['fortnox_order'] ?? '') === '4502', 'nekad faktura lämnar ordern och kopplingen');
o23_ok(o23_lines($tmp . '/inv.log') === 1, 'nekad curl skapar ingen ny faktura');

echo "ordrar_o23_k1_test ok\n";
