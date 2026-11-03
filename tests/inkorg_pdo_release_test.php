<?php

declare(strict_types=1);

/**
 * Inkorg efter att poolen släppts mitt i anropet.
 * disconnect ska nolla PDO, och samma instans ska öppna poolen igen.
 * Påhittade rader. Ingen kunddata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ix-pdo-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';
require_once $root . '/src/inkorg_store.php';

function ixp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
ixp(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
ixp($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$prop = new ReflectionProperty(JeabPdoInkorgStore::class, 'pdo');
$type = $prop->getType();
ixp($type instanceof ReflectionNamedType && $type->getName() === 'PDO' && $type->allowsNull(), 'JeabPdoInkorgStore::$pdo är ?PDO');

$store->upsertMessage('m1', [
    'id' => 'm1',
    'thread_id' => 't1',
    'from' => 'ada@example.test',
    'subject' => 'Test',
    'body' => 'Hej',
    'received_at' => '2026-10-01T10:00:00+00:00',
]);
$store->upsertThread('t1', [
    'status' => 'open',
    'tags' => [],
    'updated_at' => '2026-10-01T10:00:00+00:00',
]);
$store->saveListRow([
    'id' => 't1',
    'company' => 'jarfalla',
    'customer_email' => 'ada@example.test',
    'updated_at' => '2026-10-01T10:00:00',
    'status' => 'open',
    'subject' => 'Test',
]);
$store->countCachePut('jarfalla|counts', ['counts' => ['v-inkorg' => 1], 'at' => time()]);

$held = $store;
$held->disconnect();
ixp($prop->isInitialized($held) && $prop->getValue($held) === null, 'disconnect sätter null i stället för att unsetta');

$customer = $held->listForCustomer('ada@example.test');
ixp(count($customer) === 1 && (string) ($customer[0]['id'] ?? '') === 't1', 'listForCustomer efter disconnect');
$threads = $held->threadsByIds(['t1']);
ixp(isset($threads['t1']) && (string) ($threads['t1']['status'] ?? '') === 'open', 'threadsByIds efter disconnect');
$messages = $held->messagesByIds(['m1']);
ixp(isset($messages['m1']) && (string) ($messages['m1']['thread_id'] ?? '') === 't1', 'messagesByIds efter disconnect');
$byThread = $held->findMessagesByThread('t1');
ixp(count($byThread) === 1, 'findMessagesByThread efter disconnect');
$company = $held->listForCompany('jarfalla');
ixp(count($company) === 1, 'listForCompany efter disconnect');
ixp($held->countThreads() === 1, 'countThreads efter disconnect');
$cache = $held->countCacheGet('jarfalla|counts', 3600);
ixp(is_array($cache) && (int) (($cache['counts']['v-inkorg'] ?? 0)) === 1, 'countCacheGet efter disconnect');
ixp($held->listFingerprint() !== '', 'listFingerprint efter disconnect');

jeab_pdo_release();
ixp($prop->isInitialized($held) && $prop->getValue($held) === null, 'poolens reset nollar samma instans');
$again = $held->listForCustomer('ada@example.test', 5);
ixp(count($again) === 1 && (string) ($again[0]['customer_email'] ?? '') === 'ada@example.test', 'listForCustomer efter jeab_pdo_release');
ixp(isset($held->threadsByIds(['t1'])['t1']), 'threadsByIds efter jeab_pdo_release');
$tiles = $held->countCacheGet('jarfalla|counts', 3600);
ixp(is_array($tiles) && (int) (($tiles['counts']['v-inkorg'] ?? 0)) === 1, 'räknekache efter jeab_pdo_release');

echo "inkorg_pdo_release_test ok\n";
