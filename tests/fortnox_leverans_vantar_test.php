<?php

declare(strict_types=1);

/**
 * Bokning utan Fortnox-order är inget fel. En riktig order får leveransdatum.
 * Fortnox är mockad. Ingen kunddata lämnar testet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-lev-vantar-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox_leveransdatum.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function lv(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$calls = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$calls): array {
    $route = strtok($path, '?') ?: $path;
    $calls[] = $method . ' ' . $route;
    if ($method === 'GET' && $route === '/orders/4242') {
        return ['Order' => ['DocumentNumber' => '4242', 'DeliveryDate' => '', 'InvoiceReference' => '']];
    }
    if ($method === 'PUT' && $route === '/orders/4242') {
        return ['Order' => ['DocumentNumber' => '4242', 'DeliveryDate' => (string) ($body['Order']['DeliveryDate'] ?? '')]];
    }
    throw new RuntimeException('oväntat anrop ' . $method . ' ' . $route);
};

$store = jeab_store();
$reminder = $store->insertReminder([
    'title' => 'Laddbox',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'customer_name' => 'Testkund Leverans',
    'address' => 'Testgatan 1',
    'notes' => 'laddbox',
]);
$rid = (int) $reminder['id'];
$booking = jeab_create_booking($store, 'Testkund Leverans', ['Elin'], '2026-11-16 09:00:00', '2026-11-16 10:00:00', 'laddbox', [
    'company' => 'jarfalla',
]);
$waiting = jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Testkund Leverans',
    'booking_id' => (int) $booking['id'],
    'fortnox_order' => '',
    'fortnox_invoice' => '',
    'fortnox_leverans_error' => 'Fortnox-order saknas.',
    'steps' => ['bokad_tid' => '2026-11-16 09:00:00'],
]);
$staleHtml = jeab_ordrar_v3_html($waiting, 'jarfalla', $rid, $store);
lv(!str_contains($staleHtml, 'data-v3-leverans') && !str_contains($staleHtml, 'Fortnox-order saknas.'), 'sparad text Fortnox-order saknas visas inte utan ordernummer');
$calls = [];
jeab_fortnox_leveransdatum_on_booking($booking);
$saved = jeab_purchase_get($rid);
lv(is_array($saved) && trim((string) ($saved['fortnox_leverans_error'] ?? '')) === '', 'bokning utan Fortnox-order sparar inget fel');
lv($calls === [], 'bokning utan Fortnox-order anropar inte Fortnox');
$direct = jeab_fortnox_leveransdatum_push($waiting, true);
lv(($direct['ok'] ?? false) === true && ($direct['status'] ?? '') === 'vantar_order' && ($direct['skipped'] ?? '') === 'Ingen Fortnox-order än', 'status är vantar_order');
foreach (['ingen_bokning', 'test'] as $quiet) {
    $quietRow = jeab_ordrar_remember_leveransdatum($saved, ['ok' => false, 'status' => $quiet, 'error' => 'ska inte visas']);
    lv(trim((string) ($quietRow['fortnox_leverans_error'] ?? '')) === '', $quiet . ' är inget fel');
    $saved = $quietRow;
}
$html = jeab_ordrar_v3_html($saved, 'jarfalla', $rid, $store);
lv(!str_contains($html, 'data-v3-leverans') && !str_contains($html, 'Fortnox-order saknas.'), 'ingen leveransbanner utan Fortnox-order');

$withOrder = jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Testkund Leverans',
    'booking_id' => (int) $booking['id'],
    'fortnox_order' => '4242',
    'fortnox_invoice' => '',
    'fortnox_leverans_error' => '',
    'steps' => ['bokad_tid' => '2026-11-16 09:00:00'],
]);
$stillError = $withOrder;
$stillError['fortnox_leverans_error'] = 'Fortnox-order saknas.';
$stillHtml = jeab_ordrar_v3_html($stillError, 'jarfalla', $rid, $store);
lv(str_contains($stillHtml, 'data-v3-leverans') && str_contains($stillHtml, 'Fortnox-order saknas.'), 'samma text visas när Fortnox-ordern finns');
$calls = [];
$pushed = jeab_fortnox_leveransdatum_after_invoice($withOrder, []);
lv(($pushed['ok'] ?? false) === true && in_array('PUT /orders/4242', $calls, true), 'med Fortnox-order skrivs leveransdatum, fick ' . implode(',', $calls));
$createSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_fortnox.php');
$createAt = strpos($createSrc, 'function jeab_ordrar_create_fortnox');
$createNext = strpos($createSrc, 'function jeab_ordrar_fortnox_housework_choices');
$createBody = ($createAt === false || $createNext === false) ? '' : substr($createSrc, $createAt, $createNext - $createAt);
lv(str_contains($createBody, 'jeab_fortnox_leveransdatum_after_invoice'), 'Beställ anropar after_invoice när Fortnox-ordern skapats');

$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (): array {
    throw new RuntimeException('Fortnox nekade DeliveryDate');
};
$failed = jeab_fortnox_leveransdatum_push($withOrder, true);
$failedSaved = jeab_ordrar_remember_leveransdatum($withOrder, $failed);
lv(($failed['status'] ?? '') === 'fel' && str_contains((string) ($failedSaved['fortnox_leverans_error'] ?? ''), 'nekade'), 'ett riktigt Fortnox-fel sparas');
$failedHtml = jeab_ordrar_v3_html($failedSaved, 'jarfalla', $rid, $store);
lv(str_contains($failedHtml, 'data-v3-leverans') && str_contains($failedHtml, 'Fortnox tog inte emot leveransdatum.'), 'ett riktigt Fortnox-fel visas som banner');

echo "ALLA OK\n";
