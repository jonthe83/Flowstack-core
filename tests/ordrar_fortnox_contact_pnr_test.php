<?php

declare(strict_types=1);

/**
 * Fortnox läser personnummer från kontaktens ägarslot när kontakten är källan.
 * Orderns nummer skrivs inte tillbaka på kontakten.
 * Påhittade nummer. Inget Fortnox-anrop och inget nummer i utskrift eller logg.
 */

$tmp = sys_get_temp_dir() . '/jeab-fx-contact-pnr-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
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

function fxpnr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fxpnr_hides(string $blob, array $secrets, string $label): void
{
    foreach ($secrets as $secret) {
        if ($secret !== '' && str_contains($blob, $secret)) {
            fxpnr(false, $label);
        }
    }
    fxpnr(true, $label);
}

/** @param array<string, mixed> $row */
function fxpnr_save(array $row): void
{
    $id = trim((string) ($row['id'] ?? ''));
    $store = jeab_contact_store();
    if (!$store instanceof JeabPdoContactStore || $id === '') {
        fxpnr(false, 'kontaktlagret är inte SQL');
    }
    $store->upsert($id, $row);
    unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
}

function fxpnr_slot(array $contact, int $index): string
{
    $owners = is_array($contact['owners'] ?? null) ? $contact['owners'] : [];
    $owner = $owners[$index] ?? null;

    return is_array($owner) ? (string) ($owner['personnummer'] ?? '') : '';
}

$store = jeab_store();
fxpnr($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
fxpnr(jeab_kontakter_sql_is_sole_source(), 'kontakter läses från SQL');

$slotA = '199013400003';
$slotB = '199013400004';
$orderA = '199013410001';
$orderB = '199013410002';
$shownA = jeab_pnr_normalize($slotA);
$shownB = jeab_pnr_normalize($slotB);
$secrets = [$slotA, $slotB, $orderA, $orderB, $shownA, $shownB, '19901340-0003', '19901340-0004', '19901341-0001', '19901341-0002'];

$off = [
    'huvudsokande' => 'Ada Order',
    'personnummer' => $orderA,
    'owners' => [
        ['name' => 'Ada Order', 'personnummer' => $orderB],
    ],
    'fastighetsbeteckning' => 'TEST 1:1',
    'kind' => 'laddbox',
    'pris' => 15000,
    'company' => 'jarfalla',
];
fxpnr(jeab_ordrar_canonical_personnummer($off) === jeab_pnr_normalize($orderB), 'flaggan av läser ägarkortet på ordern');
$offTop = $off;
$offTop['owners'][0]['personnummer'] = '';
fxpnr(jeab_ordrar_canonical_personnummer($offTop) === jeab_pnr_normalize($orderA), 'flaggan av läser det gamla fältet när kortet är tomt');
fxpnr(jeab_ordrar_fortnox_customer_row($off) === $off, 'flaggan av lämnar raden');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$made = jeab_kontakt_find_or_create([
    'name' => 'Nora Huvud',
    'email' => 'nora.huvud@example.test',
    'phone' => '0701000311',
    'address' => 'Noragatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
fxpnr(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'kontakten finns');
$id = (string) $made['id'];
$contact = jeab_kontakt_get($id);
fxpnr(is_array($contact), 'kontakten kan läsas');
$contact['owners'] = [
    [
        'name' => 'Nora Huvud',
        'personnummer' => $slotA,
        'contact' => true,
        'email' => 'nora.huvud@example.test',
        'phone' => '0701000311',
        'address' => 'Noragatan 1',
    ],
    [
        'name' => 'Nora Med',
        'personnummer' => $slotB,
        'contact' => false,
        'email' => 'nora.med@example.test',
        'phone' => '0701000312',
        'address' => 'Medgatan 4',
    ],
];
fxpnr_save($contact);

$order = [
    'reminder_id' => 92041,
    'contact_id' => $id,
    'name' => 'Order Kopia',
    'huvudsokande' => 'Order Kopia',
    'customer_name' => 'Order Kopia',
    'email' => 'order.kopia@example.test',
    'phone' => '0701000999',
    'address' => 'Kvarngatan 3',
    'gata' => 'Kvarngatan 3',
    'personnummer' => $orderA,
    'owners' => [
        ['name' => 'Order Kopia', 'personnummer' => $orderA, 'email' => 'order.kopia@example.test', 'address' => 'Kvarngatan 3'],
        ['name' => 'Order Med', 'personnummer' => $orderB, 'email' => 'order.med@example.test', 'address' => 'Medgatan 2'],
    ],
    'medsokande' => [
        ['name' => 'Order Med', 'personnummer' => $orderB],
    ],
    'kind' => 'laddbox',
    'pris' => 15000,
    'fastighetsbeteckning' => 'NORA 1:1',
    'company' => 'jarfalla',
    'produkt' => 'Orderprodukt',
];

fxpnr(jeab_ordrar_canonical_personnummer($order) === $shownA, 'kanoniskt nummer är huvudägarens slot');
fxpnr(jeab_ordrar_canonical_personnummer($order) !== jeab_pnr_normalize($orderA), 'kanoniskt nummer är inte orderns');

$fx = jeab_ordrar_fortnox_customer_row($order);
fxpnr((string) ($order['personnummer'] ?? '') === $orderA, 'orderraden behåller sin kopia');
fxpnr((string) ($order['owners'][0]['personnummer'] ?? '') === $orderA && (string) ($order['owners'][1]['personnummer'] ?? '') === $orderB, 'orderns ägare behåller sina nummer');
fxpnr((string) ($fx['huvudsokande'] ?? '') === 'Nora Huvud', 'Fortnox-raden tar namnet från kontakten');
fxpnr((string) ($fx['personnummer'] ?? '') === $shownA, 'Fortnox-raden tar huvudägarens slot');
fxpnr((string) ($fx['produkt'] ?? '') === 'Orderprodukt', 'Fortnox-raden lämnar produkt');
$fxOwners = is_array($fx['owners'] ?? null) ? $fx['owners'] : [];
fxpnr(count($fxOwners) === 2, 'båda ägarslotarna följer med');
fxpnr((string) ($fxOwners[0]['personnummer'] ?? '') === $shownA, 'slot 1 är kontaktens nummer');
fxpnr((string) ($fxOwners[1]['name'] ?? '') === 'Nora Med' && (string) ($fxOwners[1]['personnummer'] ?? '') === $shownB, 'slot 2 är kontaktens nummer');
$again = jeab_kontakt_get($id);
fxpnr(is_array($again) && fxpnr_slot($again, 0) === $slotA && fxpnr_slot($again, 1) === $slotB, 'kontakten får inte orderns nummer');

$missing = jeab_ordrar_fortnox_missing($fx);
fxpnr(!in_array('personnummer', $missing, true) && !in_array('personnummer för medsökande', $missing, true), 'Fortnox saknar inte ägarnas nummer');

$payload = jeab_ordrar_fortnox_payload($order);
fxpnr((string) ($payload['personnummer'] ?? '') === $shownA, 'payloadens nummer är huvudägarens slot');
fxpnr((string) ($payload['name'] ?? '') === 'Nora Huvud', 'payloadens namn kommer från kontakten');
$payloadMed = '';
foreach (is_array($payload['owners'] ?? null) ? $payload['owners'] : [] as $person) {
    if (is_array($person) && (string) ($person['name'] ?? '') === 'Nora Med') {
        $payloadMed = (string) ($person['personnummer'] ?? '');
    }
}
fxpnr($payloadMed === $shownB, 'payloadens medsökande är slot 2');
$payloadBlob = json_encode($payload);
fxpnr(is_string($payloadBlob), 'payload går att läsa');
fxpnr_hides((string) $payloadBlob, [$orderA, $orderB, jeab_pnr_normalize($orderA), jeab_pnr_normalize($orderB)], 'payloaden innehåller inte orderns nummer');

$empty = jeab_kontakt_get($id);
fxpnr(is_array($empty), 'kontakten finns kvar');
$empty['owners'][0]['personnummer'] = '';
$empty['owners'][1]['personnummer'] = '';
fxpnr_save($empty);
$emptyOrder = $order;
$emptyOrder['reminder_id'] = 92042;
$shownOrderA = jeab_pnr_normalize($orderA);
$shownOrderB = jeab_pnr_normalize($orderB);
fxpnr(jeab_ordrar_canonical_personnummer($emptyOrder) === $shownOrderA, 'tom slot faller tillbaka på orderns ägare');
$emptyFx = jeab_ordrar_fortnox_customer_row($emptyOrder);
fxpnr((string) ($emptyFx['personnummer'] ?? '') === $shownOrderA, 'Fortnox-kopian tar orderns ägarnummer');
$emptyOwners = is_array($emptyFx['owners'] ?? null) ? $emptyFx['owners'] : [];
fxpnr((string) ($emptyOwners[0]['personnummer'] ?? '') === $shownOrderA && (string) ($emptyOwners[1]['personnummer'] ?? '') === $shownOrderB, 'tomma slotar fylls från orderns ägare på kopian');
$emptyStored = jeab_kontakt_get($id);
fxpnr(is_array($emptyStored) && fxpnr_slot($emptyStored, 0) === '' && fxpnr_slot($emptyStored, 1) === '', 'tomma slotar på kontakten rörs inte');
$emptyMissing = jeab_ordrar_fortnox_missing($emptyOrder);
fxpnr(!in_array('personnummer', $emptyMissing, true), 'tom slot saknas inte när ordern har ägarnummer');
fxpnr_hides(json_encode($emptyMissing) ?: '', $secrets, 'saknas-listan innehåller inte numret');

$unlinked = $order;
$unlinked['contact_id'] = '';
$unlinkedBefore = $unlinked['personnummer'];
$unlinkedFx = jeab_ordrar_fortnox_customer_row($unlinked);
fxpnr(jeab_ordrar_canonical_personnummer($unlinked) === '', 'olänkad order läser inte orderns nummer');
fxpnr((string) ($unlinkedFx['personnummer'] ?? 'x') === '', 'olänkad Fortnox-rad saknar nummer');
fxpnr((string) ($unlinked['personnummer'] ?? '') === $unlinkedBefore, 'olänkad order skrivs inte om');
fxpnr((string) ($unlinked['owners'][0]['personnummer'] ?? '') === $orderA && (string) ($unlinked['owners'][1]['personnummer'] ?? '') === $orderB, 'olänkad orders ägare skrivs inte om');

$log = is_file($tmp . '/php-error.log') ? (string) file_get_contents($tmp . '/php-error.log') : '';
fxpnr_hides($log, $secrets, 'felloggen innehåller inte personnummer');

echo "ordrar fortnox contact pnr: ok\n";
