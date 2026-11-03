<?php

declare(strict_types=1);

/**
 * Fortnox-omförsök läser kontaktens nya personnummer.
 * Gammal cache och orderns kopia vinner inte. Felrutan visar nästa steg.
 * Påhittade nummer. Inget nummer i utskrift, HTML eller logg.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-fx-pnr-retry-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT=1');
ini_set('log_errors', '1');
ini_set('error_log', $tmp . '/php-error.log');
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

$purchase = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchase;
jeab_purchases_store_ensure($purchase);

function fxr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fxr_same(string $got, string $secret): bool
{
    $a = jeab_pnr_normalize($got);
    $b = jeab_pnr_normalize($secret);
    if ($a === '' || $b === '') {
        $a = $got;
        $b = $secret;
    }

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function fxr_leak(string $hay, array $secrets, string $label): void
{
    $bad = preg_match('/\d{12}|\d{8}\s*[-+]\s*\d{4}|\d{6}\s*[-+]\s*\d{4}/', $hay) === 1;
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($hay, $secret)) {
            $bad = true;
        }
        $norm = jeab_norm_pnr($secret);
        if ($norm !== '' && str_contains($hay, $norm)) {
            $bad = true;
        }
    }
    fxr(!$bad, $label);
}

$store = jeab_store();
fxr($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$contactPnr = '199013400003';
$orderPnr = '199013410001';
$shownContact = jeab_pnr_normalize($contactPnr);
$secrets = [$contactPnr, $orderPnr, $shownContact, jeab_pnr_normalize($orderPnr), '19901340-0003', '19901341-0001'];

$made = jeab_kontakt_find_or_create([
    'name' => 'Nora Huvud',
    'email' => 'nora.retry@example.test',
    'phone' => '0701000311',
    'address' => 'Noragatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
fxr(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'kontakten finns');
$contactId = (string) $made['id'];
$contact = jeab_kontakt_get($contactId);
fxr(is_array($contact), 'kontakten kan läsas');
$contact['owners'] = [[
    'name' => 'Nora Huvud',
    'personnummer' => $contactPnr,
    'contact' => true,
    'email' => 'nora.retry@example.test',
    'phone' => '0701000311',
]];
$storeContact = jeab_contact_store();
fxr($storeContact instanceof JeabPdoContactStore, 'kontaktlagret är SQL');
$storeContact->upsert($contactId, $contact);

$order = jeab_purchase_put([
    'reminder_id' => 93011,
    'contact_id' => $contactId,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Order Kopia',
    'huvudsokande' => 'Order Kopia',
    'customer_name' => 'Order Kopia',
    'email' => 'order.retry@example.test',
    'phone' => '0701000999',
    'address' => 'Kvarngatan 3',
    'personnummer' => $orderPnr,
    'owners' => [
        ['name' => 'Order Kopia', 'personnummer' => $orderPnr],
        ['name' => 'Order Med', 'personnummer' => $orderPnr],
    ],
    'fastighetsbeteckning' => 'NORA 1:1',
    'kundpris' => 15000,
    'pris' => 15000,
]);

$stale = $contact;
$stale['owners'][0]['personnummer'] = '';
$GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [$contactId => $stale];
$submit = jeab_ordrar_fortnox_submit_personnummer($order);
fxr(fxr_same($submit, $contactPnr), 'omförsök läser kontakten, inte cachen');
fxr(!fxr_same($submit, $orderPnr), 'orderns kopia vinner inte när kontakten har nummer');

$fx = jeab_ordrar_fortnox_customer_row($order);
$fxOwners = is_array($fx['owners'] ?? null) ? $fx['owners'] : [];
$slot0 = is_array($fxOwners[0] ?? null) ? (string) ($fxOwners[0]['personnummer'] ?? '') : '';
fxr(fxr_same($slot0, $contactPnr), 'Fortnox-kopian tar kontaktens slot även när antalet skiljer');
fxr(!fxr_same($slot0, $orderPnr), 'Fortnox-kopian skriver inte orderns nummer över kontakten');

$contact['owners'][0]['personnummer'] = '';
$storeContact->upsert($contactId, $contact);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$fallback = jeab_ordrar_fortnox_submit_personnummer($order);
fxr(fxr_same($fallback, $orderPnr), 'tom kontaktslot faller tillbaka på ordern');

$contact['owners'][0]['personnummer'] = $contactPnr;
$storeContact->upsert($contactId, $contact);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;

$caught = '';
try {
    jeab_ordrar_fortnox_retry([
        'id' => 93011,
        'personnummer' => $orderPnr,
        'owner_pnr' => ['', ''],
        'huvudsokande_personnummer' => '',
    ]);
    fxr(false, 'omförsök stannar innan Fortnox');
} catch (InvalidArgumentException $e) {
    $caught = $e->getMessage();
}
fxr_leak($caught, $secrets, 'omförsökets fel saknar personnummer');
fxr($caught === 'Fortnox är inte kopplad på servern.', 'omförsök läser numret och når Fortnox-grinden');
$stored = jeab_purchase_get(93011);
fxr(is_array($stored) && (string) ($stored['personnummer'] ?? '') === $orderPnr, 'omförsök skriver inte om orderns kopia');

$gap = 'Kontakten saknar personnummer. Fortnox skickades inte.';
$order['fortnox_error'] = $gap;
$order['fortnox_order'] = '';
$html = jeab_ordrar_fortnox_pnr_next_html($order, 93011);
fxr(jeab_ordrar_fortnox_pnr_gap($gap), 'personnummer-felet känns igen');
fxr(str_contains($html, 'Lägg in personnummer på kontakten'), 'felrutan länkar till kontakten');
fxr(str_contains($html, '#owner_pnr_1'), 'länken pekar på personnummerfältet');
fxr(str_contains($html, 'ordrar.fortnox_retry'), 'omförsök är en egen åtgärd');
fxr(str_contains($html, 'Försök igen'), 'felrutan har Försök igen');
fxr_leak($html, $secrets, 'nästa steg saknar personnummer');

$saknas = 'Saknas för Fortnox-order: personnummer.';
fxr(jeab_ordrar_fortnox_pnr_gap($saknas), 'saknas-texten får samma nästa steg');
$order['fortnox_error'] = $saknas;
$tile = jeab_ordrar_v3_overview_html($order, 'jarfalla', 93011, $store);
fxr(str_contains($tile, 'Försök igen') && str_contains($tile, 'Lägg in personnummer på kontakten'), 'översikten visar nästa steg');
fxr_leak($tile, $secrets, 'översikten saknar personnummer');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
fxr(str_contains($page, 'id="owner_pnr_1"'), 'kontaktkortet har ett ankare för personnummer');

$log = is_file($tmp . '/php-error.log') ? (string) file_get_contents($tmp . '/php-error.log') : '';
fxr_leak($log, $secrets, 'felloggen saknar personnummer');

echo "ordrar fortnox pnr retry: ok\n";
