<?php

declare(strict_types=1);

/**
 * fortnox.order_attach läser bara Fortnox, kopplar lokalt och blockerar senare export.
 * fortnox.order_attach_undo tar tillbaka kopplingen. Inget personnummer i svaret.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-fortnox-attach-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
putenv('JEAB_DISABLE_SQLITE');
$hashAdmin = password_hash('kod-sam-attach', PASSWORD_DEFAULT);
$hashElin = password_hash('kod-elin-attach', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $hashAdmin],
        ['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin],
    ],
], JSON_UNESCAPED_UNICODE));
$incoming = 'kundtjanst-hook-' . bin2hex(random_bytes(8));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => $incoming,
    'outgoing_webhook_url' => '',
    'passwords_file' => $tmp . '/passwords.json',
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
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-refresh'],
    'Malmo' => ['refresh_token' => 'test-refresh'],
], JSON_UNESCAPED_UNICODE));

require $root . '/src/bootstrap.php';
require_once $root . '/src/fortnox_order_link.php';
require_once $root . '/src/hook_scoped_tokens.php';

function attach_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;

$secretName = 'Anna Andersson';
$secretMail = 'anna.attach@example.com';
$secretPnr = '198001011234';
$secretStreet = 'Hemliggatan 1';

$reminder = jeab_create_reminder($store, $secretName, null, 'Order', null, [
    'customer' => $secretName,
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$other = jeab_create_reminder($store, 'Annan', null, 'Order', null, [
    'customer' => 'Annan',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$httpReminder = jeab_create_reminder($store, 'Http Kund', null, 'Order', null, [
    'customer' => 'Http Kund',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
]);
$order = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => $secretName,
    'email' => $secretMail,
    'personnummer' => $secretPnr,
    'address' => $secretStreet,
    'huvudsokande' => $secretName,
    'fastighetsbeteckning' => 'TEST 1:1',
    'steps' => [],
]);
jeab_purchase_put([
    'reminder_id' => (int) $other['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Annan',
    'huvudsokande' => 'Annan',
    'fastighetsbeteckning' => 'TEST 1:2',
    'fortnox_order' => '555',
    'fortnox_invoice' => '999',
    'fortnox_customer' => '77',
    'steps' => [],
]);
$httpOrder = jeab_purchase_put([
    'reminder_id' => (int) $httpReminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Http Kund',
    'huvudsokande' => 'Http Kund',
    'fastighetsbeteckning' => 'TEST 1:3',
    'steps' => [],
]);
$orderId = (string) (int) $order['reminder_id'];
$httpOrderId = (string) (int) $httpOrder['reminder_id'];
attach_assert($orderId !== '0' && $httpOrderId !== '0' && $httpOrderId !== $orderId, 'ordrar finns');

$calls = [];
$fx = [
    'invoices' => [
        '13606' => [
            'DocumentNumber' => '13606',
            'InvoiceType' => 'INVOICE',
            'CustomerNumber' => '42',
            'CustomerName' => $secretName,
            'EmailInformation' => ['EmailAddressTo' => $secretMail],
            'Address1' => $secretStreet,
            'OrganisationNumber' => $secretPnr,
            'OrderReference' => '900',
            'Comments' => 'ring ' . $secretName,
        ],
        '13607' => [
            'DocumentNumber' => '13607',
            'CustomerNumber' => '42',
            'CustomerName' => $secretName,
            'OrganisationNumber' => $secretPnr,
            'OrderReference' => '901',
        ],
        '13609' => [
            'DocumentNumber' => '13609',
            'CustomerNumber' => '77',
            'CustomerName' => $secretName,
            'OrganisationNumber' => $secretPnr,
            'OrderReference' => '900',
        ],
        '13610' => [
            'DocumentNumber' => '13610',
            'CustomerNumber' => '77',
            'CustomerName' => $secretName,
            'OrganisationNumber' => $secretPnr,
            'OrderReference' => '903',
        ],
    ],
    'orders' => [
        '900' => [
            'DocumentNumber' => '900',
            'CustomerNumber' => '42',
            'CustomerName' => $secretName,
            'OrganisationNumber' => $secretPnr,
            'InvoiceReference' => '13606',
            'Address1' => $secretStreet,
        ],
        '901' => [
            'DocumentNumber' => '901',
            'CustomerNumber' => '42',
            'OrganisationNumber' => $secretPnr,
            'InvoiceReference' => '13607',
        ],
        '902' => [
            'DocumentNumber' => '902',
            'CustomerNumber' => '77',
            'OrganisationNumber' => $secretPnr,
            'InvoiceReference' => '13609',
        ],
        '903' => [
            'DocumentNumber' => '903',
            'CustomerNumber' => '42',
            'OrganisationNumber' => $secretPnr,
            'InvoiceReference' => '13610',
        ],
    ],
];
file_put_contents($tmp . '/fx.json', json_encode($fx, JSON_UNESCAPED_UNICODE));
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path) use (&$calls, $fx): array {
    $route = strtok($path, '?') ?: $path;
    $calls[] = $method . ' ' . $route;
    if ($method !== 'GET') {
        throw new RuntimeException('attach får bara läsa');
    }
    if (preg_match('#^/invoices/(\d+)$#', $route, $match) === 1 && isset($fx['invoices'][$match[1]])) {
        return ['Invoice' => $fx['invoices'][$match[1]]];
    }
    if (preg_match('#^/orders/(\d+)$#', $route, $match) === 1 && isset($fx['orders'][$match[1]])) {
        return ['Order' => $fx['orders'][$match[1]]];
    }
    throw new FortnoxApiException('Kunde inte hitta fakturan.', 404, 2000433);
};

$clean = static function (mixed $value) use ($secretName, $secretMail, $secretPnr, $secretStreet): bool {
    $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    return !str_contains($text, $secretName)
        && !str_contains($text, $secretMail)
        && !str_contains($text, $secretPnr)
        && !str_contains($text, $secretStreet);
};

$dry = jeab_fortnox_order_attach([
    'action' => 'fortnox.order_attach',
    'order_id' => $orderId,
    'fortnox_order' => '900',
    'invoice_number' => '13606',
]);
attach_assert(($dry['dry'] ?? null) === 1 && ($dry['status'] ?? '') === 'skulle_koppla' && ($dry['read_only'] ?? 0) === 1, 'dry är standard');
attach_assert(($dry['customer_number'] ?? '') === '42', 'dry visar kundnumret från Fortnox');
attach_assert($clean($dry), 'dry-svaret har inget personnummer');
$afterDry = jeab_purchase_get((int) $orderId);
attach_assert(is_array($afterDry) && trim((string) ($afterDry['fortnox_order'] ?? '')) === '' && trim((string) ($afterDry['fortnox_invoice'] ?? '')) === '', 'dry skriver inte');
attach_assert($calls !== [] && !array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')), 'dry gör bara GET');
$callsBeforeDeny = $calls;

$denied = false;
try {
    jeab_fortnox_order_attach([
        'order_id' => $orderId,
        'fortnox_order' => '900',
        'invoice_number' => '13606',
        'dry' => 0,
    ]);
} catch (InvalidArgumentException $e) {
    $denied = $e->getMessage() === 'Bekräftelse saknas.';
}
attach_assert($denied, 'dry=0 utan confirm=SAM nekas');
attach_assert($calls === $callsBeforeDeny, 'nekad bekräftelse anropar inte Fortnox');
attach_assert(trim((string) (jeab_purchase_get((int) $orderId)['fortnox_invoice'] ?? '')) === '', 'nekad bekräftelse skriver inte');

$mismatch = jeab_fortnox_order_attach([
    'order_id' => $orderId,
    'fortnox_order' => '900',
    'invoice_number' => '13607',
    'dry' => 0,
    'confirm' => 'SAM',
]);
attach_assert(($mismatch['ok'] ?? true) === false && ($mismatch['error'] ?? '') === 'Fakturan hör inte till Fortnox-ordern.', 'faktura från en annan Fortnox-order nekas');
attach_assert($clean($mismatch) && trim((string) (jeab_purchase_get((int) $orderId)['fortnox_order'] ?? '')) === '', 'fel faktura skriver inte och läcker inte');

$otherCustomer = jeab_fortnox_order_attach([
    'order_id' => $orderId,
    'fortnox_order' => '903',
    'invoice_number' => '13610',
    'dry' => 0,
    'confirm' => 'SAM',
]);
attach_assert(($otherCustomer['ok'] ?? true) === false && ($otherCustomer['error'] ?? '') === 'Fakturan och ordern har olika kund.', 'olika kund nekas');
attach_assert(trim((string) (jeab_purchase_get((int) $orderId)['fortnox_customer'] ?? '')) === '', 'olika kund skriver inte');

$heldInvoice = jeab_fortnox_order_attach([
    'order_id' => $orderId,
    'fortnox_order' => '900',
    'invoice_number' => '999',
    'dry' => 1,
]);
attach_assert(($heldInvoice['ok'] ?? true) === false && ($heldInvoice['other_order_id'] ?? '') === (string) (int) $other['id'], 'faktura som redan har en order nekas');
$heldOrder = jeab_fortnox_order_attach([
    'order_id' => $orderId,
    'fortnox_order' => '555',
    'invoice_number' => '13606',
    'dry' => 1,
]);
attach_assert(($heldOrder['ok'] ?? true) === false && str_contains((string) ($heldOrder['error'] ?? ''), 'Fortnox-ordern'), 'Fortnox-order som redan har en order nekas');
attach_assert($clean($heldInvoice) && $clean($heldOrder), 'konflikt-svaret har inget personnummer');

$callsAfterReads = count($calls);
@unlink(jeab_app_errors_path());
$written = jeab_fortnox_order_attach([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'fortnox_order' => '900',
    'invoice_number' => '13606',
    'dry' => '0',
    'confirm' => 'SAM',
]);
$fresh = jeab_purchase_get((int) $orderId);
$logText = is_array($fresh) ? implode("\n", array_map(static fn (array $item): string => (string) ($item['text'] ?? ''), jeab_ordrar_activity_list($fresh))) : '';
attach_assert(($written['status'] ?? '') === 'kopplad' && ($written['read_only'] ?? 0) === 1, 'confirm kopplar');
attach_assert(is_array($fresh)
    && (string) ($fresh['fortnox_order'] ?? '') === '900'
    && (string) ($fresh['fortnox_invoice'] ?? '') === '13606'
    && (string) ($fresh['fortnox_customer'] ?? '') === '42'
    && (int) ($fresh['fortnox_attached'] ?? 0) === 1, 'nummer och spärr sparas');
$steps = is_array($fresh['steps'] ?? null) ? $fresh['steps'] : [];
attach_assert(trim((string) ($steps['order_skriven'] ?? '')) !== '' && trim((string) ($steps['faktura_skapad'] ?? '')) !== '', 'ordern markeras skriven och fakturerad');
attach_assert(str_contains($logText, '«Fortnox-order 900 / faktura 13606 kopplad (ingen export)»'), 'aktivitetsloggen har kopplingstexten');
attach_assert($clean($written) && $clean($logText), 'kopplingen skriver inte ut personnummer');
attach_assert((string) ($fresh['personnummer'] ?? '') === $secretPnr && (string) ($fresh['email'] ?? '') === $secretMail, 'kundfält på ordern är orörda');
attach_assert(count($calls) > $callsAfterReads && !array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')), 'kopplingen gör bara GET');
$appLog = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
attach_assert(str_contains($appLog, 'fortnox_order_attach') && $clean($appLog) && !str_contains($appLog, 'test-refresh'), 'felloggen har kopplingen och ingen hemlighet');

$again = jeab_fortnox_order_attach([
    'order_id' => $orderId,
    'fortnox_order' => '900',
    'invoice_number' => '13606',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$freshAgain = jeab_purchase_get((int) $orderId);
attach_assert(($again['status'] ?? '') === 'oforandrat' && count(jeab_ordrar_activity_list($freshAgain ?? [])) === count(jeab_ordrar_activity_list($fresh ?? [])), 'samma koppling skrivs inte om');

$callCount = count($calls);
$export = jeab_fortnox_order_export([
    'account' => 'JEAB',
    'order_id' => $orderId,
    'dry' => 0,
    'confirm' => 'SAM',
]);
attach_assert(($export['ok'] ?? true) === false && ($export['error'] ?? '') === 'Ordern är kopplad till Fortnox. Ingen export.', 'senare fortnox.order_export nekas');
attach_assert(count($calls) === $callCount, 'nekad export anropar inte Fortnox');

$createRefused = false;
try {
    jeab_ordrar_create_fortnox(['id' => (int) $orderId]);
} catch (InvalidArgumentException $e) {
    $createRefused = $e->getMessage() === 'Ordern är kopplad till Fortnox. Ingen export.';
}
attach_assert($createRefused, 'ordrar.fortnox nekas');

$invoiceRefused = false;
try {
    jeab_ordrar_save_fakturering(['id' => (int) $orderId, 'intent' => 'faktura']);
} catch (InvalidArgumentException $e) {
    $invoiceRefused = $e->getMessage() === 'Ordern är kopplad till Fortnox. Ingen export.';
}
attach_assert($invoiceRefused, 'fakturera nekas');

$spy = 0;
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function () use (&$spy): array {
    $spy++;
    throw new RuntimeException('export');
};
$next = jeab_ordrar_fortnox_on_next(is_array($freshAgain) ? $freshAgain : []);
attach_assert($spy === 0 && (string) ($next['fortnox_order'] ?? '') === '900', 'ordrar.slutfor exporterar inte en kopplad order');
unset($GLOBALS['_JEAB_FORTNOX_CREATE']);
attach_assert(count($calls) === $callCount && !array_filter($calls, static fn (string $call): bool => !str_starts_with($call, 'GET ')), 'ingen export skrev till Fortnox');

$undoDry = jeab_fortnox_order_attach_undo(['order_id' => $orderId]);
attach_assert(($undoDry['dry'] ?? null) === 1 && ($undoDry['status'] ?? '') === 'skulle_angra', 'undo är torr som standard');
attach_assert((int) (jeab_purchase_get((int) $orderId)['fortnox_attached'] ?? 0) === 1, 'torr undo lämnar spärren');

$undoDenied = false;
try {
    jeab_fortnox_order_attach_undo(['order_id' => $orderId, 'dry' => 0]);
} catch (InvalidArgumentException $e) {
    $undoDenied = $e->getMessage() === 'Bekräftelse saknas.';
}
attach_assert($undoDenied, 'undo utan confirm nekas');

$undone = jeab_fortnox_order_attach_undo([
    'order_id' => $orderId,
    'dry' => 0,
    'confirm' => 'SAM',
]);
$restored = jeab_purchase_get((int) $orderId);
$undoLog = is_array($restored) ? implode("\n", array_map(static fn (array $item): string => (string) ($item['text'] ?? ''), jeab_ordrar_activity_list($restored))) : '';
attach_assert(($undone['status'] ?? '') === 'angrad' && $clean($undone) && $clean($undoLog), 'undo svarar utan personnummer');
attach_assert(is_array($restored)
    && !jeab_fortnox_order_attach_locked($restored)
    && trim((string) ($restored['fortnox_order'] ?? '')) === ''
    && trim((string) ($restored['fortnox_invoice'] ?? '')) === ''
    && trim((string) ($restored['fortnox_customer'] ?? '')) === '', 'undo tar bort nummer och spärr');
$restoredSteps = is_array($restored['steps'] ?? null) ? $restored['steps'] : [];
attach_assert(trim((string) ($restoredSteps['order_skriven'] ?? '')) === '' && trim((string) ($restoredSteps['faktura_skapad'] ?? '')) === '', 'undo tar bort stämplarna som kopplingen satte');
attach_assert(str_contains($undoLog, '«Fortnox-order 900 / faktura 13606 frånkopplad»'), 'undo loggar frånkopplingen');
attach_assert(count($calls) === $callCount, 'undo anropar inte Fortnox');
attach_assert((string) ($restored['personnummer'] ?? '') === $secretPnr, 'undo rör inte personnumret');

$bokmalen = JEAB_HOOK_SCOPED_TOKENS['270ec26eb71511fd57caff4f821122a9cef78d3ac9128a077dbaaf64119b459c'] ?? null;
$bokmalenScopes = is_array($bokmalen) ? ($bokmalen['scopes'] ?? []) : [];
$covers = static function (string $action) use ($bokmalenScopes): bool {
    foreach ($bokmalenScopes as $scope) {
        if (is_string($scope) && jeab_hook_scope_covers($scope, $action)) {
            return true;
        }
    }
    return false;
};
attach_assert($covers('fortnox.order_attach') && $covers('fortnox.order_attach_undo'), 'Bokmalen-token får attach och undo');

$hook = (string) file_get_contents($root . '/hook.php');
$gate = strpos($hook, 'if (!$jeabFortnoxAllowed)');
$dispatch = strpos($hook, "\$jeabFortnoxAction === 'fortnox.order_relink' || \$jeabFortnoxAction === 'fortnox.order_attach'");
attach_assert($gate !== false && $dispatch !== false && $dispatch > $gate, 'hook.php tar båda åtgärderna bakom fortnox-grinden');
attach_assert(str_contains($hook, 'jeab_fortnox_order_attach_admin_post'), 'inloggad admin får posta');

$listen = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
attach_assert(is_resource($listen), 'ledig port');
$addr = stream_socket_get_name($listen, false);
fclose($listen);
$port = (int) substr((string) $addr, (int) strrpos((string) $addr, ':') + 1);
$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_ATTACH_TMP');
$root = getenv('JEAB_ATTACH_ROOT');
if (!is_string($tmp) || !is_string($root) || $tmp === '' || $root === '') {
    http_response_code(500);
    echo 'missing';
    return;
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$fx = json_decode((string) file_get_contents($tmp . '/fx.json'), true);
if (!is_array($fx)) {
    $fx = ['invoices' => [], 'orders' => []];
}
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path) use ($tmp, $fx): array {
    $route = strtok($path, '?') ?: $path;
    file_put_contents($tmp . '/http-calls.log', $method . ' ' . $route . "\n", FILE_APPEND);
    if ($method !== 'GET') {
        throw new RuntimeException('attach får bara läsa');
    }
    $invoices = is_array($fx['invoices'] ?? null) ? $fx['invoices'] : [];
    $orders = is_array($fx['orders'] ?? null) ? $fx['orders'] : [];
    if (preg_match('#^/invoices/(\d+)$#', $route, $match) === 1 && isset($invoices[$match[1]]) && is_array($invoices[$match[1]])) {
        return ['Invoice' => $invoices[$match[1]]];
    }
    if (preg_match('#^/orders/(\d+)$#', $route, $match) === 1 && isset($orders[$match[1]]) && is_array($orders[$match[1]])) {
        return ['Order' => $orders[$match[1]]];
    }
    throw new FortnoxApiException('Kunde inte hitta fakturan.', 404, 2000433);
};
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return;
}
http_response_code(404);
echo 'no';
PHP);
$proc = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $tmp . '/httpd.out', 'w'], 2 => ['file', $tmp . '/httpd.err', 'w']],
    $pipes,
    $tmp,
    ['JEAB_ATTACH_TMP' => $tmp, 'JEAB_ATTACH_ROOT' => $root]
);
attach_assert(is_resource($proc), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/hook.php');
    if (is_string($probe) && str_contains($probe, '"ok"')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
attach_assert($ready, 'hook.php svarar');

$curl = static function (array $args) use ($port, $secretPnr, $secretName, $secretMail, $secretStreet): array {
    $cmd = array_merge(['curl', '-sS', '-D', '-', '-o', '-'], $args, ['http://127.0.0.1:' . $port . '/hook.php']);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    attach_assert(is_resource($proc), 'curl startade');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $text = is_string($stdout) ? $stdout : '';
    $err = is_string($stderr) ? $stderr : '';
    attach_assert($code === 0, 'curl lyckades: ' . $err);
    attach_assert(
        !str_contains($text, $secretPnr) && !str_contains($text, $secretName) && !str_contains($text, $secretMail) && !str_contains($text, $secretStreet),
        'curl-svaret har inget personnummer'
    );
    $status = 0;
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/m', $text, $match) === 1) {
        $status = (int) $match[1];
    }
    $jsonStart = strpos($text, '{');
    $json = $jsonStart === false ? null : json_decode(substr($text, $jsonStart), true);

    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text];
};
$body = static function (array $extra) use ($httpOrderId): string {
    return json_encode(['action' => 'fortnox.order_attach', 'order_id' => $httpOrderId, 'fortnox_order' => '900', 'invoice_number' => '13606'] + $extra, JSON_UNESCAPED_UNICODE);
};

$noToken = $curl(['-X', 'POST', '-H', 'Content-Type: application/json', '--data', $body([])]);
attach_assert($noToken['status'] === 401, 'utan token är attach 401');

$kund = $curl(['-X', 'POST', '-H', 'Authorization: Bearer ' . $incoming, '-H', 'Content-Type: application/json', '--data', $body([])]);
attach_assert($kund['status'] === 200 && ($kund['json']['status'] ?? '') === 'skulle_koppla' && ($kund['json']['dry'] ?? null) === 1, 'Kundtjänst-token får torr attach');

$adminCookie = (string) jeab_auth_issue_value('demo@example.test', $hashAdmin);
$admin = $curl(['-X', 'POST', '-H', 'Cookie: jeab_auth=' . $adminCookie, '-H', 'Content-Type: application/json', '--data', $body([])]);
attach_assert($admin['status'] === 200 && ($admin['json']['ok'] ?? false) === true, 'inloggad admin får posta attach');

$elinCookie = (string) jeab_auth_issue_value('saljare4@jarfalla.example.test', $hashElin);
$elin = $curl(['-X', 'POST', '-H', 'Cookie: jeab_auth=' . $elinCookie, '-H', 'Content-Type: application/json', '--data', $body([])]);
attach_assert($elin['status'] === 401, 'inloggad icke-admin nekas');

$bokmalenToken = 'bokmalen-attach-' . bin2hex(random_bytes(6));
$GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'] = [
    hash('sha256', $bokmalenToken) => [
        'name' => 'bokmalen',
        'scopes' => ['fortnox.order_attach', 'fortnox.order_attach_undo'],
    ],
];
file_put_contents($tmp . '/scoped.php', "<?php\n\$GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'] = " . var_export($GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'], true) . ";\n");
$routerSrc = (string) file_get_contents($router);
$routerSrc = str_replace("require \$root . '/hook.php';", "require \$tmp . '/scoped.php';\n    require \$root . '/hook.php';", $routerSrc);
file_put_contents($router, $routerSrc);
$bok = $curl(['-X', 'POST', '-H', 'Authorization: Bearer ' . $bokmalenToken, '-H', 'Content-Type: application/json', '--data', $body(['dry' => 0, 'confirm' => 'SAM'])]);
attach_assert($bok['status'] === 200 && ($bok['json']['status'] ?? '') === 'kopplad', 'Bokmalen-token får bekräfta attach');

jeab_purchase_cache_forget();
$httpFresh = jeab_purchase_get((int) $httpOrderId);
$httpLog = is_array($httpFresh) ? implode("\n", array_map(static fn (array $item): string => (string) ($item['text'] ?? ''), jeab_ordrar_activity_list($httpFresh))) : '';
attach_assert(is_array($httpFresh) && (string) ($httpFresh['fortnox_invoice'] ?? '') === '13606' && (int) ($httpFresh['fortnox_attached'] ?? 0) === 1, 'curl-kopplingen sparades');
attach_assert(str_contains($httpLog, '«Fortnox-order 900 / faktura 13606 kopplad (ingen export)»') && !str_contains($httpLog, $secretPnr), 'curl-loggen är den förväntade texten');

$httpCalls = is_file($tmp . '/http-calls.log') ? (string) file_get_contents($tmp . '/http-calls.log') : '';
attach_assert($httpCalls !== '' && !preg_match('/^(?!GET ).+/m', $httpCalls), 'curl-anropen är bara GET');
attach_assert(!str_contains($httpCalls, 'POST') && !str_contains($httpCalls, 'PUT') && !str_contains($httpCalls, 'createinvoice'), 'curl gjorde ingen export');

$undoHttp = $curl(['-X', 'POST', '-H', 'Authorization: Bearer ' . $incoming, '-H', 'Content-Type: application/json', '--data', json_encode([
    'action' => 'fortnox.order_attach_undo',
    'order_id' => $httpOrderId,
    'dry' => 0,
    'confirm' => 'SAM',
], JSON_UNESCAPED_UNICODE)]);
attach_assert($undoHttp['status'] === 200 && ($undoHttp['json']['status'] ?? '') === 'angrad', 'Kundtjänst-token får ångra');
jeab_purchase_cache_forget();
$httpUndone = jeab_purchase_get((int) $httpOrderId);
attach_assert(is_array($httpUndone) && !jeab_fortnox_order_attach_locked($httpUndone) && trim((string) ($httpUndone['fortnox_invoice'] ?? '')) === '', 'curl-undo återställer ordern');

proc_terminate($proc);
proc_close($proc);

echo "Alla order-attach-tester OK\n";
