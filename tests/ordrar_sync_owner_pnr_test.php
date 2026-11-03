<?php

declare(strict_types=1);

/**
 * Ägarens personnummer på ordern ska nå kontaktens ägarplats och Fortnox-kontrollen.
 * ordrar.sync_owner_pnr är torr som standard. Skrivning kräver confirm=SAM
 * och sparar ångring. ordrar.fortnox_rerun förhandsvisar en order och skriver bara
 * med confirm=SAM. Inget kundmejl och inget personnummer i svaret.
 */

$tmp = sys_get_temp_dir() . '/jeab-sync-owner-pnr-' . bin2hex(random_bytes(4));
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

function sop(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sop_make(string $yyMMdd, string $three): string
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

function sop_same(string $stored, string $raw): bool
{
    $a = jeab_norm_pnr($stored);
    $b = jeab_norm_pnr($raw);

    return $a !== '' && $b !== '' && hash_equals($a, $b);
}

function sop_leak(string $hay, array $secrets, string $label): void
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
    sop(!$bad, $label);
}

$store = jeab_store();
sop($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$purchase = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchase;
jeab_purchases_store_ensure($purchase);
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$pnrA = sop_make('900101', '321');
$pnrB = sop_make('850615', '432');
$pnrC = sop_make('780303', '210');
$secrets = [$pnrA, $pnrB, $pnrC];

$made = jeab_kontakt_find_or_create([
    'name' => 'Kund Test',
    'email' => 'kund.sync@example.test',
    'phone' => '0701000411',
    'address' => 'Flottarestigen 6, 192 51 Sollentuna',
    'city' => 'Sollentuna',
    'postal_code' => '19251',
], false);
sop(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'kontakten finns');
$contactId = (string) $made['id'];
$contact = jeab_kontakt_get($contactId);
sop(is_array($contact), 'kontakten kan läsas');
$contact['owners'] = [[
    'name' => 'Kund Test',
    'personnummer' => '',
    'contact' => true,
    'email' => 'kund.sync@example.test',
    'phone' => '0701000411',
    'address' => 'Flottarestigen 6',
]];
$contact['street'] = 'Flottarestigen 6';
$contact['postal_code'] = '19251';
$contact['city'] = 'Sollentuna';
$contact['fastighetsbeteckning'] = 'SOLLENTUNA LERTAVLAN 4';
$storeContact = jeab_contact_store();
sop($storeContact instanceof JeabPdoContactStore, 'kontaktlagret är SQL');
$storeContact->upsert($contactId, $contact);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

jeab_purchase_put([
    'reminder_id' => 20,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Kund Test',
    'huvudsokande' => 'Ada Huvud',
    'contact_id' => $contactId,
    'email' => 'kund.sync@example.test',
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
        ['name' => 'Bo Med', 'personnummer' => $pnrB],
    ],
    'pris' => 15000,
    'steps' => [],
]);

$order = (array) jeab_purchase_get(20);
sop(jeab_ordrar_canonical_personnummer($order) === jeab_pnr_normalize($pnrA), 'Fortnox läser orderns ägare när kontakten är tom');
$missing = jeab_ordrar_fortnox_missing($order);
sop(!in_array('personnummer', $missing, true), 'saknas-listan tar inte personnummer');
$block = jeab_contacts_strict_block($order, 'fortnox');
sop($block === '', 'strikt Fortnox släpper igenom orderns ägare');
sop(!str_contains($block, 'personnummer'), 'spärren är tom');

$denied = jeab_ordrar_sync_owner_pnr_dispatch($store, ['order_id' => 20]);
sop(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'sync_owner_pnr kräver token');

$dry = jeab_ordrar_sync_owner_pnr_run($store, ['order_id' => 20]);
sop((int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0 && (int) ($dry['mail'] ?? 1) === 0, 'utan dry är körningen torr');
sop((int) ($dry['would'] ?? 0) === 1, 'förhandsvisningen skulle skriva');
$slots = is_array($dry['slots'] ?? null) ? $dry['slots'] : [];
sop(count($slots) === 2 && (int) ($slots[0]['would'] ?? 0) === 1 && (int) ($slots[1]['would'] ?? 0) === 1, 'båda platserna skulle fyllas');
$still = jeab_kontakt_get($contactId);
sop(is_array($still) && trim((string) ($still['owners'][0]['personnummer'] ?? '')) === '', 'torr körning skriver inte');
sop_leak((string) json_encode($dry, JSON_UNESCAPED_UNICODE), $secrets, 'torr sync saknar personnummer');

$unconfirmed = jeab_ordrar_sync_owner_pnr_run($store, ['order_id' => 20, 'dry' => 0, 'confirm' => 'nej']);
sop(($unconfirmed['ok'] ?? true) === false && (int) ($unconfirmed['written'] ?? 1) === 0, 'skrivning utan confirm görs inte');
sop(trim((string) (jeab_kontakt_get($contactId)['owners'][0]['personnummer'] ?? '')) === '', 'nekad skrivning lämnar kontakten');

$wrote = jeab_ordrar_sync_owner_pnr_run($store, ['order_id' => 20, 'dry' => 0, 'confirm' => 'SAM']);
sop(!empty($wrote['ok']) && (int) ($wrote['written'] ?? 0) === 1 && (int) ($wrote['mail'] ?? 1) === 0, 'bekräftad sync skriver');
sop(trim((string) ($wrote['batch_id'] ?? '')) !== '', 'ångringen har ett batch-id');
$filled = jeab_kontakt_get($contactId);
sop(is_array($filled) && (string) ($filled['owners'][0]['name'] ?? '') === 'Kund Test', 'kontaktens namn ligger kvar');
sop(is_array($filled) && sop_same((string) ($filled['owners'][0]['personnummer'] ?? ''), $pnrA), 'plats 1 får huvudägarens nummer');
sop(is_array($filled) && (string) ($filled['owners'][1]['name'] ?? '') === 'Bo Med', 'plats 2 skapas för andra ägaren');
sop(is_array($filled) && sop_same((string) ($filled['owners'][1]['personnummer'] ?? ''), $pnrB), 'plats 2 får numret');
sop_leak((string) json_encode($wrote, JSON_UNESCAPED_UNICODE), $secrets, 'skrivsvaret saknar personnummer');

$undoPdo = function_exists('jeab_contacts_sole_undo_pdo') ? jeab_contacts_sole_undo_pdo($store) : $store->pdo();
$undoRows = $undoPdo instanceof PDO
    ? $undoPdo->prepare('SELECT before_json, after_json, kind FROM contacts_sole_undo WHERE batch_id = ?')
    : $store->pdo()->prepare('SELECT before_json, after_json, kind FROM contacts_sole_undo WHERE batch_id = ?');
$undoRows->execute([(string) $wrote['batch_id']]);
$undo = $undoRows->fetch(PDO::FETCH_ASSOC);
sop(is_array($undo) && (string) ($undo['kind'] ?? '') === 'owner', 'ångerloggen är sparad');
$undoBlob = (string) ($undo['before_json'] ?? '') . (string) ($undo['after_json'] ?? '');
sop(str_contains($undoBlob, 'pnr_clear'), 'tom plats markeras så ångring kan tömma den');
$undoSecret = false;
foreach ($secrets as $secret) {
    $norm = jeab_norm_pnr($secret);
    $shown = jeab_pnr_normalize($secret);
    if (str_contains($undoBlob, $secret) || ($norm !== '' && str_contains($undoBlob, $norm)) || ($shown !== '' && str_contains($undoBlob, $shown))) {
        $undoSecret = true;
    }
}
sop(!$undoSecret, 'ångerloggen innehåller inte numret');
$before = json_decode((string) ($undo['before_json'] ?? ''), true);
$currentOwners = is_array($filled['owners'] ?? null) ? $filled['owners'] : [];
$restored = jeab_contacts_sole_undo_owner_fields(
    is_array($before['owners'] ?? null) ? $before['owners'] : [],
    $currentOwners
);
sop(jeab_fastighet_write_contact($contactId, ['owners' => $restored]), 'ångringen skrivs tillbaka');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$cleared = jeab_kontakt_get($contactId);
sop(is_array($cleared) && trim((string) ($cleared['owners'][0]['personnummer'] ?? '')) === '', 'ångring tömmer plats 1');

$again = jeab_ordrar_sync_owner_pnr_run($store, ['order_id' => 20, 'dry' => '0', 'confirm' => 'SAM']);
sop((int) ($again['written'] ?? 0) === 1, 'sync kan köras igen efter ångring');
$kept = jeab_kontakt_get($contactId);
$kept['owners'][0]['personnummer'] = $pnrC;
$storeContact->upsert($contactId, $kept);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$blocked = jeab_ordrar_sync_owner_pnr_run($store, ['order_id' => 20, 'dry' => 0, 'confirm' => 'SAM']);
sop((int) ($blocked['would'] ?? 1) === 0 || (int) ($blocked['slots'][0]['would'] ?? 1) === 0, 'ifyllt annat nummer på plats 1 skrivs inte över');
$keptAfter = jeab_kontakt_get($contactId);
sop(is_array($keptAfter) && sop_same((string) ($keptAfter['owners'][0]['personnummer'] ?? ''), $pnrC), 'det andra numret ligger kvar');

$fxDenied = jeab_ordrar_fortnox_rerun_dispatch($store, ['order_id' => 20]);
sop(($fxDenied['ok'] ?? true) === false && (int) ($fxDenied['status'] ?? 0) === 401, 'fortnox_rerun kräver token');
$beforeFx = (string) (jeab_purchase_get(20)['fortnox_order'] ?? '');
$fxDry = jeab_ordrar_fortnox_rerun_run($store, ['order_id' => 20]);
sop((int) ($fxDry['dry'] ?? 0) === 1 && (int) ($fxDry['written'] ?? 1) === 0 && (int) ($fxDry['mail'] ?? 1) === 0, 'Fortnox-förhandsvisningen skriver inte');
sop((int) ($fxDry['ready'] ?? 0) === 1 && (int) ($fxDry['would_write'] ?? 0) === 1, 'förhandsvisningen är redo');
sop((string) (jeab_purchase_get(20)['fortnox_order'] ?? '') === $beforeFx, 'förhandsvisningen skapar ingen order');
sop_leak((string) json_encode($fxDry, JSON_UNESCAPED_UNICODE), $secrets, 'Fortnox-förhandsvisningen saknar personnummer');
$fxNo = jeab_ordrar_fortnox_rerun_run($store, ['order_id' => 20, 'dry' => 0, 'confirm' => '']);
sop(($fxNo['ok'] ?? true) === false && (int) ($fxNo['written'] ?? 1) === 0 && (int) ($fxNo['mail'] ?? 1) === 0, 'Fortnox-skrivning utan confirm görs inte');
$fxWet = jeab_ordrar_fortnox_rerun_run($store, ['order_id' => 20, 'dry' => 0, 'confirm' => 'SAM']);
sop(($fxWet['ok'] ?? true) === false && (int) ($fxWet['written'] ?? 1) === 0 && (int) ($fxWet['mail'] ?? 1) === 0, 'Fortnox utan koppling skriver ingen order');
sop((string) (jeab_purchase_get(20)['fortnox_order'] ?? '') === '', 'ordern har fortfarande inget Fortnox-nummer');
sop_leak((string) json_encode($fxWet, JSON_UNESCAPED_UNICODE), $secrets, 'Fortnox-felet saknar personnummer');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
sop(str_contains($hook, "=== 'ordrar.sync_owner_pnr'") && str_contains($hook, 'jeab_ordrar_sync_owner_pnr_dispatch'), 'hook.php tar ordrar.sync_owner_pnr');
sop(str_contains($hook, "=== 'ordrar.fortnox_rerun'") && str_contains($hook, 'jeab_ordrar_fortnox_rerun_dispatch'), 'hook.php tar ordrar.fortnox_rerun');
$fxSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_fortnox.php');
$fxStart = strpos($fxSrc, 'function jeab_ordrar_fortnox_rerun_run(');
$fxEnd = strpos($fxSrc, 'function jeab_ordrar_fortnox_rerun_dispatch(');
$fxBody = $fxStart !== false && $fxEnd !== false ? substr($fxSrc, $fxStart, $fxEnd - $fxStart) : '';
sop(str_contains($fxBody, 'jeab_ordrar_create_fortnox') && str_contains($fxBody, "'mail' => 0") && !str_contains($fxBody, 'jeab_mail') && !str_contains($fxBody, 'jeab_sms'), 'omkörningen skickar inget kundmejl');

$other = jeab_kontakt_find_or_create([
    'name' => 'Annan Kund',
    'email' => 'annan.kund@example.test',
    'phone' => '0701000412',
    'address' => 'Flottarestigen 6, 192 51 Sollentuna',
    'city' => 'Sollentuna',
    'postal_code' => '19251',
], false);
sop(is_array($other) && trim((string) ($other['id'] ?? '')) !== '', 'andra kontakten finns');
$otherId = (string) $other['id'];
$otherRow = jeab_kontakt_get($otherId);
sop(is_array($otherRow), 'andra kontakten kan läsas');
$otherRow['owners'] = [[
    'name' => 'Annan Kund',
    'personnummer' => '',
    'contact' => true,
    'email' => 'annan.kund@example.test',
    'phone' => '0701000412',
]];
$otherRow['street'] = 'Flottarestigen 6';
$otherRow['postal_code'] = '19251';
$otherRow['city'] = 'Sollentuna';
$storeContact->upsert($otherId, $otherRow);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
jeab_purchase_put([
    'reminder_id' => 21,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Annan Kund',
    'huvudsokande' => 'Cia Gammal',
    'contact_id' => $otherId,
    'address' => 'Flottarestigen 6, Sollentuna',
    'gata' => 'Flottarestigen 6',
    'postnummer' => '19251',
    'ort' => 'Sollentuna',
    'fastighetsbeteckning' => 'SOLLENTUNA LERTAVLAN 4',
    'owners' => [
        ['name' => 'Cia Gammal', 'personnummer' => $pnrC, 'address' => 'Flottarestigen 6'],
        ['name' => 'Ada Huvud', 'personnummer' => $pnrA, 'address' => 'Flottarestigen 6'],
    ],
    'pris' => 15000,
    'steps' => [],
]);
$GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] = $store->pdo();
$set = jeab_ordrar_set_owners_run($store, [
    'order_id' => 21,
    'keep' => ['Ada'],
    'dry' => 0,
    'confirm' => 'SAM',
]);
sop((int) ($set['written'] ?? 0) === 1 && (int) ($set['contact_synced'] ?? 0) === 1, 'set_owners synkar ägarnumret till kontakten');
sop_leak((string) json_encode($set, JSON_UNESCAPED_UNICODE), $secrets, 'set_owners-svaret saknar personnummer');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$otherAfter = jeab_kontakt_get($otherId);
sop(is_array($otherAfter) && (string) ($otherAfter['owners'][0]['name'] ?? '') === 'Annan Kund', 'set_owners lämnar kontaktnamnet');
sop(is_array($otherAfter) && sop_same((string) ($otherAfter['owners'][0]['personnummer'] ?? ''), $pnrA), 'set_owners skriver huvudägarens nummer');

$log = is_file($tmp . '/php-error.log') ? (string) file_get_contents($tmp . '/php-error.log') : '';
sop_leak($log, $secrets, 'felloggen saknar personnummer');

echo "ordrar sync_owner_pnr: ok\n";
