<?php

declare(strict_types=1);

/**
 * ordrar.clear_stale_leverans_error tömmer bara «Fortnox-order saknas.» där
 * Fortnox-order och faktura saknas. dry=1 är standard. Inget Fortnox-anrop.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-stale-lev-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/ordrar_v3.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

$fortnoxCalls = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$fortnoxCalls): array {
    $fortnoxCalls++;
    throw new RuntimeException('Fortnox ska inte anropas');
};

function sl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $row */
function sl_rest(array $row): string
{
    unset($row['fortnox_leverans_error']);
    $json = json_encode($row, JSON_UNESCAPED_UNICODE);

    return is_string($json) ? $json : '';
}

$store = jeab_store();

/** @param array<string, mixed> $extra */
function sl_order(JeabStore $store, string $name, array $extra): array
{
    $reminder = $store->insertReminder([
        'title' => 'Laddbox',
        'company' => 'jarfalla',
        'assignee' => 'Elin',
        'customer_name' => $name,
        'address' => 'Testgatan 1',
        'notes' => 'laddbox',
    ]);
    $row = jeab_purchase_put(array_replace([
        'reminder_id' => (int) $reminder['id'],
        'company' => 'jarfalla',
        'kind' => 'laddbox',
        'customer' => $name,
        'booking_id' => 8800 + (int) $reminder['id'],
        'fortnox_order' => '',
        'fortnox_invoice' => '',
        'fortnox_leverans_error' => '',
        'steps' => ['bokad_tid' => '2026-11-16 09:00:00'],
        'updated_at' => '2026-10-01 08:00:00',
    ], $extra));
    sl(is_array($row), 'order sparad för ' . $name);

    return $row;
}

$stale = sl_order($store, 'Stale Saknas', [
    'fortnox_leverans_error' => 'Fortnox-order saknas.',
]);
$invoiceZero = sl_order($store, 'Faktura Noll', [
    'fortnox_invoice' => '0',
    'fortnox_leverans_error' => 'Fortnox-order saknas.',
]);
$hasOrder = sl_order($store, 'Har Order', [
    'fortnox_order' => '4242',
    'fortnox_leverans_error' => 'Fortnox-order saknas.',
]);
$hasInvoice = sl_order($store, 'Har Faktura', [
    'fortnox_invoice' => '9001',
    'fortnox_leverans_error' => 'Fortnox-order saknas.',
]);
$realError = sl_order($store, 'Riktigt Fel', [
    'fortnox_leverans_error' => 'Fortnox nekade DeliveryDate',
]);
$clean = sl_order($store, 'Ren Rad', []);
$almost = sl_order($store, 'Utan Punkt', [
    'fortnox_leverans_error' => 'Fortnox-order saknas',
]);

$staleId = (int) $stale['reminder_id'];
$zeroId = (int) $invoiceZero['reminder_id'];
$orderId = (int) $hasOrder['reminder_id'];
$invoiceId = (int) $hasInvoice['reminder_id'];
$realId = (int) $realError['reminder_id'];
$cleanId = (int) $clean['reminder_id'];
$almostId = (int) $almost['reminder_id'];
$want = [$staleId, $zeroId];
sort($want);

$staleHtml = jeab_ordrar_v3_html($stale, 'jarfalla', $staleId, $store);
sl(!str_contains($staleHtml, 'data-v3-leverans') && !str_contains($staleHtml, 'Fortnox-order saknas.'), 'banner döljer texten utan Fortnox-nummer');
$zeroHtml = jeab_ordrar_v3_html($invoiceZero, 'jarfalla', $zeroId, $store);
sl(!str_contains($zeroHtml, 'data-v3-leverans'), 'fakturanummer 0 räknas som ingen faktura');
$orderHtml = jeab_ordrar_v3_html($hasOrder, 'jarfalla', $orderId, $store);
sl(str_contains($orderHtml, 'data-v3-leverans') && str_contains($orderHtml, 'Fortnox-order saknas.'), 'texten visas när ordernumret finns');
$invoiceHtml = jeab_ordrar_v3_html($hasInvoice, 'jarfalla', $invoiceId, $store);
sl(str_contains($invoiceHtml, 'data-v3-leverans') && str_contains($invoiceHtml, 'Fortnox-order saknas.'), 'texten visas när fakturanumret finns');
$realHtml = jeab_ordrar_v3_html($realError, 'jarfalla', $realId, $store);
sl(str_contains($realHtml, 'data-v3-leverans') && str_contains($realHtml, 'nekade'), 'ett annat fel visas utan Fortnox-nummer');

$before = [];
foreach ([$staleId, $zeroId, $orderId, $invoiceId, $realId, $cleanId, $almostId] as $id) {
    $row = jeab_purchase_get($id);
    sl(is_array($row), 'rad ' . $id . ' finns');
    $before[$id] = sl_rest($row);
}

$dry = jeab_ordrar_clear_stale_leverans_error([]);
sl(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && ($dry['action'] ?? '') === 'ordrar.clear_stale_leverans_error', 'utelämnad dry är torr');
sl(($dry['ids'] ?? null) === $want, 'dry listar bara rader utan order och faktura, fick ' . json_encode($dry['ids'] ?? null));
foreach ($before as $id => $rest) {
    $row = jeab_purchase_get($id);
    sl(is_array($row) && sl_rest($row) === $rest && (string) ($row['fortnox_leverans_error'] ?? '') === (string) (match ($id) {
        $staleId, $zeroId, $orderId, $invoiceId => 'Fortnox-order saknas.',
        $realId => 'Fortnox nekade DeliveryDate',
        $almostId => 'Fortnox-order saknas',
        default => '',
    }), 'dry=1 lämnar rad ' . $id);

}

$explicit = jeab_ordrar_clear_stale_leverans_error(['dry' => '1']);
sl((int) ($explicit['dry'] ?? 0) === 1 && ($explicit['ids'] ?? null) === $want, 'dry=1 skriver inte');

$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_ordrar_clear_stale_leverans_error_dispatch(['dry' => 0]);
sl(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken utan token är 401');
sl((string) (jeab_purchase_get($staleId)['fortnox_leverans_error'] ?? '') === 'Fortnox-order saknas.', 'nekad hook skriver inte');

$live = jeab_ordrar_clear_stale_leverans_error(['dry' => 0]);
sl(($live['ok'] ?? false) === true && (int) ($live['dry'] ?? 1) === 0 && ($live['ids'] ?? null) === $want, 'dry=0 returnerar träffade order-id');
$cleared = jeab_purchase_get($staleId);
$clearedZero = jeab_purchase_get($zeroId);
sl(is_array($cleared) && trim((string) ($cleared['fortnox_leverans_error'] ?? '')) === '' && sl_rest($cleared) === $before[$staleId], 'bara feltexten töms på ordern utan nummer');
sl(is_array($clearedZero) && trim((string) ($clearedZero['fortnox_leverans_error'] ?? '')) === '' && sl_rest($clearedZero) === $before[$zeroId], 'fakturanummer 0 töms och övriga fält står kvar');
foreach ([$orderId => 'Fortnox-order saknas.', $invoiceId => 'Fortnox-order saknas.', $realId => 'Fortnox nekade DeliveryDate', $almostId => 'Fortnox-order saknas', $cleanId => ''] as $id => $error) {
    $row = jeab_purchase_get($id);
    sl(is_array($row) && (string) ($row['fortnox_leverans_error'] ?? '') === $error && sl_rest($row) === $before[$id], 'orörd rad ' . $id . ' behåller text och övriga fält');
}
$again = jeab_ordrar_clear_stale_leverans_error(['dry' => 0]);
sl(($again['ids'] ?? null) === [], 'andra körningen har inget kvar');
sl($fortnoxCalls === 0, 'hooken anropar inte Fortnox');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-stale-lev']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-stale-lev';
$authed = jeab_ordrar_clear_stale_leverans_error_dispatch([]);
sl(($authed['ok'] ?? false) === true && (int) ($authed['status'] ?? 0) === 200 && (int) ($authed['dry'] ?? 0) === 1 && ($authed['ids'] ?? null) === [], 'token och utelämnad dry svarar tomt');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
sl(str_contains($hook, "'ordrar.clear_stale_leverans_error'") && str_contains($hook, 'jeab_ordrar_clear_stale_leverans_error_dispatch'), 'hook.php tar emot ordrar.clear_stale_leverans_error');

echo "ALLA OK\n";
