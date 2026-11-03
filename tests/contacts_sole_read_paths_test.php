<?php

declare(strict_types=1);

/**
 * Läsvägar för ordrar och köp när contacts_sole är på.
 * Saknad kontakt lämnar orderns kopia. Tomt kontaktfält visas tomt.
 * Sök träffar kontaktens gata även när kopian saknar den.
 * Flaggan av är exakt kopian. Produkt och nätägare målas aldrig från kontakten.
 * Paint efter en skrivning i samma request visar det nya värdet.
 * Sök och lista via kontakten träffar inte ett ogiltigt påhittat nummer.
 * Påhittade rader. Inga riktiga personnummer. Inga utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-contacts-sole-read-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
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
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

function rp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $row */
function rp_save_contact(array $row): void
{
    $id = trim((string) ($row['id'] ?? ''));
    $store = jeab_contact_store();
    if (!$store instanceof JeabPdoContactStore || $id === '') {
        fwrite(STDERR, "FAIL kontaktlagret är inte SQL\n");
        exit(1);
    }
    $store->upsert($id, $row);
    unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
}

/** @return array<string, mixed> */
function rp_order(int $id, string $contactId, string $name, string $email, string $phone, string $street): array
{
    return [
        'reminder_id' => $id,
        'contact_id' => $contactId,
        'name' => $name,
        'huvudsokande' => $name,
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $street,
        'huvudsokande_address' => $street,
        'gata' => $street,
        'ort' => 'Teststad',
        'postnummer' => '99999',
        'postnr' => '999 99',
        'applicant' => ['name' => $name, 'email' => $email, 'phone' => $phone],
        'kind' => 'laddbox',
        'produkt' => 'Orderprodukt',
        'natbolag' => 'E.ON',
        'fastighetsbeteckning' => 'ORDER 1:2',
        'pris' => '15000',
        'company' => 'jarfalla',
    ];
}

$store = jeab_store();
rp($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);
rp(jeab_kontakter_sql_is_sole_source(), 'kontakter läses från SQL');

$made = jeab_kontakt_find_or_create([
    'name' => 'Ada Sole',
    'email' => 'ada.sole@example.test',
    'phone' => '0701000101',
    'address' => 'Solgatan 12, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'Ada Sole finns');
$soleId = (string) $made['id'];
$soleContact = jeab_kontakt_get($soleId);
rp(is_array($soleContact), 'Ada Sole kan läsas');
$soleContact['street'] = 'Solgatan 12';
$soleContact['city'] = 'Teststad';
$soleContact['postal_code'] = '11122';
$soleContact['street_key'] = 'uniknyckel 12';
$soleContact['produkt'] = 'FelproduktXYZ';
$soleContact['natbolag'] = 'Vattenfall';
$soleContact['kind'] = 'sol';
$soleContact['fastighetsbeteckning'] = 'KONTAKT 9:9';
$soleContact['pris'] = '1';
rp_save_contact($soleContact);
$solePhone = jeab_kontakt_row_phone(jeab_kontakt_get($soleId) ?: []);
rp($solePhone !== '' && $solePhone !== '0701000199', 'kontaktens telefon är satt');

$madeBlank = jeab_kontakt_find_or_create([
    'name' => 'Bo Blank',
    'email' => 'bo.blank@example.test',
    'phone' => '0701000102',
    'address' => 'Tomgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp(is_array($madeBlank) && trim((string) ($madeBlank['id'] ?? '')) !== '', 'Bo Blank finns');
$blankId = (string) $madeBlank['id'];
$blankContact = jeab_kontakt_get($blankId);
rp(is_array($blankContact), 'Bo Blank kan läsas');
$blankContact['street'] = '';
$blankContact['city'] = '';
$blankContact['postal_code'] = '';
$blankContact['address'] = '';
$blankContact['street_key'] = '';
$owners = is_array($blankContact['owners'] ?? null) ? $blankContact['owners'] : [];
foreach ($owners as $i => $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $owner['name'] = '';
    $owner['email'] = '';
    $owner['phone'] = '';
    $owners[$i] = $owner;
}
$blankContact['owners'] = $owners;
rp_save_contact($blankContact);
$blankView = jeab_contacts_sole_view_from_contact(jeab_kontakt_get($blankId) ?: []);
rp($blankView['name'] === '' && $blankView['email'] === '' && $blankView['phone'] === '' && $blankView['street'] === '', 'den tomma kontakten har inga kundfält');

$madePart = jeab_kontakt_find_or_create([
    'name' => 'Cia Delvis',
    'email' => 'cia.delvis@example.test',
    'phone' => '0701000103',
    'address' => 'Halvgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp(is_array($madePart) && trim((string) ($madePart['id'] ?? '')) !== '', 'Cia Delvis finns');
$partId = (string) $madePart['id'];
$partContact = jeab_kontakt_get($partId);
rp(is_array($partContact), 'Cia Delvis kan läsas');
$partContact['street'] = '';
$partContact['city'] = '';
$partContact['postal_code'] = '';
$partContact['address'] = '';
$partContact['street_key'] = '';
$owners = is_array($partContact['owners'] ?? null) ? $partContact['owners'] : [];
foreach ($owners as $i => $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $owner['email'] = '';
    $owner['phone'] = '';
    $owners[$i] = $owner;
}
$partContact['owners'] = $owners;
rp_save_contact($partContact);

$soleOrder = rp_order(91001, $soleId, 'Kopia Sole', 'kopia.sole@example.test', '0701000199', '');
$blankOrder = rp_order(91004, $blankId, 'Kopia Blank', 'kopia.blank@example.test', '0701000198', 'Blankgatan 9');
$partOrder = rp_order(91005, $partId, 'Kopia Delvis', 'kopia.delvis@example.test', '0701000197', 'Delvisgatan 4');
$copyOrder = rp_order(91002, '', 'Kopia Kvarn', 'kopia.kvarn@example.test', '0701000196', 'Kvarngatan 3');
$missingOrder = rp_order(91003, 'kt_finns_inte', 'Kopia Saknad', 'kopia.saknad@example.test', '0701000195', 'Saknadgatan 2');
$unlinkedOrder = rp_order(91006, '', 'Kopia Utan', 'kopia.utan@example.test', '0701000194', 'Utangatan 6');
$rows = [$soleOrder, $copyOrder, $missingOrder, $blankOrder, $partOrder];

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$offPaint = jeab_contacts_sole_paint_order($soleOrder);
rp($offPaint == $soleOrder, 'flaggan av ⇒ exakt orderns kopia');
rp(jeab_ordrar_display_row($soleOrder) == $soleOrder, 'visningsraden är kopian när flaggan är av');
rp(jeab_purchase_shown($soleOrder) == $soleOrder, 'köpets visning är kopian när flaggan är av');
rp(jeab_contacts_sole_view_for($soleOrder) === null, 'flaggan av ger ingen kontaktvy');
$GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = 0;
$GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = 0;
rp(jeab_ordrar_search_rows($rows, 'Solgatan') === [], 'flaggan av söker inte på kontaktens gata');
rp(jeab_ordrar_search_rows($rows, 'uniknyckel') === [], 'flaggan av söker inte på kontaktens gatunyckel');
rp(jeab_ordrar_search_rows($rows, 'Ada Sole') === [], 'flaggan av söker inte på kontaktens namn');
$offKvarn = jeab_ordrar_search_rows($rows, 'Kvarngatan');
rp(count($offKvarn) === 1 && (int) ($offKvarn[0]['reminder_id'] ?? 0) === 91002, 'flaggan av hittar orderns egen gata');
rp((int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] === 0, 'flaggan av gör ingen kontaktfråga');
rp(jeab_ordrar_lookup_query(null, $soleOrder) === '', 'flaggan av ger ingen gatfråga när kopian saknar adress');
$offBoard = jeab_ordrar_board_row_html($soleOrder, 'jarfalla');
rp(!str_contains($offBoard, 'Solgatan') && !str_contains($offBoard, 'uniknyckel') && !str_contains($offBoard, 'Ada Sole'), 'listans data-name är kopian när flaggan är av');
$offOverview = jeab_ordrar_v3_overview_html($soleOrder, 'jarfalla', 91001);
rp(str_contains($offOverview, 'value="999 99"') && str_contains($offOverview, 'value="0701000199"') && str_contains($offOverview, 'value="kopia.sole@example.test"'), 'v3 visar kopians postnr, mobil och e-post när flaggan är av');
rp(!str_contains($offOverview, 'ada.sole@example.test') && !str_contains($offOverview, $solePhone), 'v3 visar inte kontaktens mobil eller e-post när flaggan är av');
rp(str_contains($offOverview, 'value="E.ON" selected'), 'v3 behåller orderns nätägare när flaggan är av');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$missingPaint = jeab_contacts_sole_paint_order($missingOrder);
rp(($missingPaint['contact_link_missing'] ?? false) === true, 'saknad kontakt flaggas');
rp($missingPaint['name'] === 'Kopia Saknad' && $missingPaint['email'] === 'kopia.saknad@example.test' && $missingPaint['phone'] === '0701000195', 'kontakt saknas ⇒ orderns kopia');
rp($missingPaint['address'] === 'Saknadgatan 2' && $missingPaint['gata'] === 'Saknadgatan 2', 'saknad kontakt lämnar gatan');
rp($missingPaint['kind'] === 'laddbox' && $missingPaint['produkt'] === 'Orderprodukt' && $missingPaint['natbolag'] === 'E.ON', 'saknad kontakt rör inte produkt eller nätägare');
$unlinkedPaint = jeab_contacts_sole_paint_order($unlinkedOrder);
rp(($unlinkedPaint['contact_link_missing'] ?? false) === true && $unlinkedPaint['gata'] === 'Utangatan 6' && $unlinkedPaint['email'] === 'kopia.utan@example.test', 'order utan contact_id behåller kopian');

$blankPaint = jeab_contacts_sole_paint_order($blankOrder);
rp(empty($blankPaint['contact_link_missing']), 'tom kontakt är fortfarande länkad');
rp($blankPaint['name'] === '' && $blankPaint['email'] === '' && $blankPaint['phone'] === '', 'tom kontakt visas tom, utan orderns namn, e-post eller telefon');
rp($blankPaint['address'] === '' && $blankPaint['gata'] === '' && $blankPaint['postnr'] === '', 'tom kontakt visas tom, utan orderns gata eller postnr');
rp(!str_contains((string) $blankPaint['address'], 'Blankgatan') && !str_contains((string) $blankPaint['address'], 'Tomgatan'), 'varken orderns eller den rensade gatan visas');

$partPaint = jeab_contacts_sole_paint_order($partOrder);
rp($partPaint['name'] === 'Cia Delvis' && $partPaint['huvudsokande'] === 'Cia Delvis', 'kontaktens namn målas när det finns');
rp($partPaint['email'] === '' && $partPaint['phone'] === '', 'tom e-post och telefon visas tomma');
rp($partPaint['gata'] === '' && $partPaint['address'] === '', 'tom gata visas tom');
rp(!str_contains((string) $partPaint['address'], 'Delvisgatan') && !str_contains((string) $partPaint['address'], 'Halvgatan'), 'varken orderns eller den rensade gatan visas');

$solePaint = jeab_contacts_sole_paint_order($soleOrder);
rp($solePaint['gata'] === 'Solgatan 12' && str_contains((string) $solePaint['address'], 'Solgatan 12'), 'kontaktens gata målas när kopian saknar den');
rp($solePaint['name'] === 'Ada Sole' && $solePaint['email'] === 'ada.sole@example.test' && $solePaint['phone'] === $solePhone, 'namn, e-post och telefon kommer från kontakten');
rp($solePaint['postnr'] === '111 22', 'postnr kommer från kontakten');
rp($solePaint['kind'] === 'laddbox' && $solePaint['produkt'] === 'Orderprodukt' && $solePaint['natbolag'] === 'E.ON', 'produkt och nätägare målas inte från kontakten');
rp($solePaint['fastighetsbeteckning'] === 'ORDER 1:2' && (string) $solePaint['pris'] === '15000', 'fastighet och pris målas inte från kontakten');
rp(!str_contains((string) $solePaint['produkt'], 'Felprodukt') && $solePaint['natbolag'] !== 'Vattenfall', 'kontaktens produkt och nätägare ligger kvar utanför ordern');

$GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] = 0;
$GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] = 0;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$byStreet = jeab_ordrar_search_rows($rows, 'Solgatan');
rp(count($byStreet) === 1 && (int) ($byStreet[0]['reminder_id'] ?? 0) === 91001, 'sök hittar order via kontaktens gata när orderns kopia saknar den');
$byKey = jeab_ordrar_search_rows($rows, 'uniknyckel');
rp(count($byKey) === 1 && (int) ($byKey[0]['reminder_id'] ?? 0) === 91001, 'sök hittar order via kontaktens gatunyckel');
$byMail = jeab_ordrar_search_rows($rows, 'ada.sole@example.test');
rp(count($byMail) === 1 && (int) ($byMail[0]['reminder_id'] ?? 0) === 91001, 'sök hittar order via kontaktens e-post');
$byPhone = jeab_ordrar_search_rows($rows, $solePhone);
rp(count($byPhone) === 1 && (int) ($byPhone[0]['reminder_id'] ?? 0) === 91001, 'sök hittar order via kontaktens telefon');
$byName = jeab_ordrar_search_rows($rows, 'Ada Sole');
rp(count($byName) === 1 && (int) ($byName[0]['reminder_id'] ?? 0) === 91001, 'sök hittar order via kontaktens namn');
rp(jeab_ordrar_search_rows($rows, 'FelproduktXYZ') === [], 'sök träffar inte kontaktens produkt');
rp(jeab_ordrar_search_rows($rows, 'Vattenfall') === [], 'sök träffar inte kontaktens nätägare');
rp(jeab_ordrar_search_rows($rows, 'KONTAKT 9:9') === [], 'sök träffar inte kontaktens fastighet');
$byCopy = jeab_ordrar_search_rows($rows, 'Kvarngatan');
rp(count($byCopy) === 1 && (int) ($byCopy[0]['reminder_id'] ?? 0) === 91002, 'order utan kontakt hittas på kopians gata');
$byMissing = jeab_ordrar_search_rows($rows, 'Saknadgatan');
rp(count($byMissing) === 1 && (int) ($byMissing[0]['reminder_id'] ?? 0) === 91003, 'saknad kontakt faller tillbaka till kopians gata');
rp((int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] === 1, 'en sida hämtar kontakterna i en fråga');
rp((int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] === 0, 'listan gör inte get per rad');

// Samma rad ritades ovan med flaggan av. HTML-cachen tar inte med CONTACTS_SOLE.
$staleKey = jeab_ordrar_row_cache_key($soleOrder, 'jarfalla', null);
if (isset($GLOBALS['_JEAB_ORDRAR_ROW_HTML']) && is_array($GLOBALS['_JEAB_ORDRAR_ROW_HTML'])) {
    unset($GLOBALS['_JEAB_ORDRAR_ROW_HTML'][$staleKey]);
}
@unlink(jeab_ordrar_row_cache_file($staleKey));
$board = jeab_ordrar_board_row_html($soleOrder, 'jarfalla');
rp(str_contains($board, 'Solgatan') && str_contains($board, 'uniknyckel') && str_contains($board, 'Ada Sole'), 'listans data-name innehåller kontaktens gata, nyckel och namn');
rp(!str_contains($board, 'FelproduktXYZ') && !str_contains($board, 'Vattenfall'), 'listan målar inte produkt eller nätägare från kontakten');
rp((int) $GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] === 1 && (int) $GLOBALS['_JEAB_CONTACTS_SOLE_GETS'] === 0, 'radens html läser den redan hämtade kontakten');

$beforeLookup = $soleOrder;
$lookup = jeab_ordrar_lookup_query(null, $soleOrder);
rp(str_contains($lookup, 'Solgatan'), 'gatfrågan läser kontaktens gata');
rp($soleOrder == $beforeLookup, 'gatfrågan ändrar inte anroparens order');
$spec = jeab_ordrar_spec_html($soleOrder, 'jarfalla', 91001);
rp(str_contains($spec, 'Solgatan 12') && !str_contains($spec, 'FelproduktXYZ'), 'spec visar kontaktens gata och inte kontaktens produkt');
rp(str_contains($spec, 'value="E.ON" checked') && !str_contains($spec, 'value="Vattenfall" checked'), 'spec behåller orderns nätägare');

$overview = jeab_ordrar_v3_overview_html($soleOrder, 'jarfalla', 91001);
rp(str_contains($overview, 'value="111 22"') && str_contains($overview, 'value="' . $solePhone . '"') && str_contains($overview, 'value="ada.sole@example.test"'), 'v3 renderar postnr, mobil och e-post från den målade raden');
rp(!str_contains($overview, 'value="999 99"') && !str_contains($overview, 'value="0701000199"') && !str_contains($overview, 'kopia.sole@example.test'), 'v3 ersätter kopians postnr, mobil och e-post');
rp(str_contains($overview, 'value="E.ON" selected') && !str_contains($overview, 'value="Vattenfall" selected'), 'v3 målar inte nätägare från kontakten');
rp(str_contains($overview, 'value="ORDER 1:2"') && !str_contains($overview, 'KONTAKT 9:9') && !str_contains($overview, 'FelproduktXYZ'), 'v3 målar inte fastighet eller produkt från kontakten');

$purchase = $soleOrder;
$purchase['lines'] = [];
$reminder = ['id' => 91001, 'company' => 'jarfalla'];
$step = 1;
$flash = null;
$error = null;
$buy1 = require dirname(__DIR__) . '/src/pages/purchase.php';
rp(is_string($buy1) && str_contains($buy1, 'value="ada.sole@example.test"') && str_contains($buy1, 'value="' . $solePhone . '"'), 'köpet visar kontaktens e-post och telefon');
rp(!str_contains((string) $buy1, 'kopia.sole@example.test') && !str_contains((string) $buy1, '0701000199'), 'köpet visar inte kopians e-post eller telefon');
$step = 2;
$buy2 = require dirname(__DIR__) . '/src/pages/purchase.php';
rp(is_string($buy2) && str_contains($buy2, 'value="Ada Sole"'), 'köpets huvudsökande kommer från kontakten');
rp(!str_contains((string) $buy2, 'FelproduktXYZ') && !str_contains((string) $buy2, 'Vattenfall'), 'köpet målar inte produkt eller nätägare');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$purchase = $soleOrder;
$purchase['lines'] = [];
$step = 1;
$buyOff = require dirname(__DIR__) . '/src/pages/purchase.php';
rp(is_string($buyOff) && str_contains($buyOff, 'value="kopia.sole@example.test"') && str_contains($buyOff, 'value="0701000199"'), 'köpet visar kopian när flaggan är av');
rp(!str_contains((string) $buyOff, 'ada.sole@example.test') && !str_contains((string) $buyOff, $solePhone), 'köpet visar inte kontakten när flaggan är av');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$madeEva = jeab_kontakt_find_or_create([
    'name' => 'Eva Cache',
    'email' => 'eva.cache@example.test',
    'phone' => '0701000104',
    'address' => 'Cachegatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp(is_array($madeEva) && trim((string) ($madeEva['id'] ?? '')) !== '', 'Eva Cache finns');
$evaId = (string) $madeEva['id'];
$evaOrder = rp_order(91007, $evaId, 'Kopia Cache', 'kopia.cache@example.test', '0701000193', '');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
jeab_contacts_sole_load_ids([$evaId]);
rp(array_key_exists($evaId, $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] ?? []), 'listan har cachat Eva');
$evaWarm = jeab_contacts_sole_paint_order($evaOrder);
rp($evaWarm['name'] === 'Eva Cache' && $evaWarm['gata'] === 'Cachegatan 1', 'paint läser den cachade gatan');

jeab_kontakter_mutate(static function (array &$contacts) use ($evaId): void {
    if (!isset($contacts[$evaId]) || !is_array($contacts[$evaId])) {
        return;
    }
    $contacts[$evaId]['street'] = 'Nygatan 8';
    $contacts[$evaId]['street_key'] = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key('Nygatan 8') : 'nygatan 8';
    $contacts[$evaId]['updated_at'] = jeab_now();
});
$evaStreet = jeab_contacts_sole_paint_order($evaOrder);
rp($evaStreet['gata'] === 'Nygatan 8' && str_contains((string) $evaStreet['address'], 'Nygatan 8'), 'paint efter skrivning visar den nya gatan');
rp($evaStreet['name'] === 'Eva Cache', 'gatskrivningen lämnar namnet');

unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
jeab_contacts_sole_load_ids([$evaId]);
$evaLive = jeab_kontakt_get($evaId);
rp(is_array($evaLive), 'Eva kan läsas efter gatskrivningen');
$evaOwners = [];
foreach (is_array($evaLive['owners'] ?? null) ? $evaLive['owners'] : [] as $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $owner['name'] = 'Eva Skriven';
    $evaOwners[] = $owner;
}
rp($evaOwners !== [], 'Eva har en ägare att skriva om');
rp(jeab_fastighet_write_contact($evaId, ['owners' => $evaOwners]), 'write_contact skrev ägaren');
$evaNamed = jeab_contacts_sole_paint_order($evaOrder);
rp($evaNamed['name'] === 'Eva Skriven' && $evaNamed['huvudsokande'] === 'Eva Skriven', 'paint efter write_contact visar det nya namnet');
rp($evaNamed['gata'] === 'Nygatan 8', 'write_contact lämnar den nya gatan');

$GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [
    $evaId => ['id' => $evaId, 'street' => 'Felgatan 99', 'city' => 'Teststad', 'postal_code' => '11122', 'owners' => [['name' => 'Eva Gift', 'personnummer' => '', 'contact' => true, 'email' => 'eva.cache@example.test', 'phone' => '0701000104']]],
    $soleId => ['id' => $soleId, 'street' => 'Solgatan 12'],
];
$foundAgain = jeab_kontakt_find_or_create([
    'name' => 'Eva Skriven',
    'email' => 'eva.cache@example.test',
    'phone' => '0701000104',
    'address' => 'Nygatan 8, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp(is_array($foundAgain), 'find_or_create träffar Eva');
$evaAfterFind = jeab_contacts_sole_paint_order($evaOrder);
rp($evaAfterFind['name'] === 'Eva Skriven' && $evaAfterFind['gata'] === 'Nygatan 8', 'paint efter find_or_create visar det sparade värdet');
rp(!str_contains((string) $evaAfterFind['gata'], 'Felgatan') && $evaAfterFind['name'] !== 'Eva Gift', 'den gamla cachen används inte efter find_or_create');

$GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [
    $evaId => ['id' => $evaId, 'street' => 'Felgatan 99', 'owners' => [['name' => 'Eva Gift', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => '']]],
];
$inflow = jeab_contacts_sole_inflow_upsert([
    'name' => 'Eva Skriven',
    'email' => 'eva.cache@example.test',
    'phone' => '0701000104',
    'address' => 'Nygatan 8, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
]);
rp(is_array($inflow), 'inflow_upsert träffar Eva');
$evaAfterInflow = jeab_contacts_sole_paint_order($evaOrder);
rp($evaAfterInflow['name'] === 'Eva Skriven' && $evaAfterInflow['gata'] === 'Nygatan 8', 'paint efter inflow_upsert visar det sparade värdet');

unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
jeab_contacts_sole_load_ids([$evaId]);
rp(jeab_contacts_sole_undo_add('cachebatch1', 1, 'property', 'contact', $evaId, [
    'fastighetsbeteckning' => '',
    'owners' => [[
        'name' => 'Eva Angrad',
        'personnummer' => '',
        'contact' => true,
        'email' => 'eva.cache@example.test',
        'phone' => '0701000104',
    ]],
], [
    'fastighetsbeteckning' => '',
    'owners' => $evaOwners,
]), 'undo-raden för ägaren finns');
$undone = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => 'cachebatch1'], $store);
rp(!empty($undone['ok']) && (int) ($undone['properties'] ?? 0) === 1, 'undo skrev tillbaka ägaren');
$evaAfterUndo = jeab_contacts_sole_paint_order($evaOrder);
rp($evaAfterUndo['name'] === 'Eva Angrad', 'paint efter undo visar det återställda namnet');

$GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'] = [
    $evaId => ['id' => $evaId, 'street' => 'Nygatan 8'],
    $soleId => ['id' => $soleId, 'street' => 'Solgatan 12'],
];
jeab_contacts_sole_cache_forget($evaId);
rp(!array_key_exists($evaId, $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']) && array_key_exists($soleId, $GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']), 'forget tar bara bort det angivna id:t');
jeab_contacts_sole_cache_forget(null);
rp(!isset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']), 'forget utan id tömmer hela cachen');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$evaOff = jeab_contacts_sole_paint_order($evaOrder);
rp($evaOff == $evaOrder, 'flaggan av lämnar orderns kopia efter skrivningarna');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
// Ogiltigt datum (månad 13). Inget riktigt personnummer.
$needle = '19901340-0001';
$madeHedda = jeab_kontakt_find_or_create([
    'name' => 'Hedda Siffra',
    'email' => 'hedda.siffra@example.test',
    'phone' => '0701000107',
    'address' => 'Siffergatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
rp(is_array($madeHedda) && trim((string) ($madeHedda['id'] ?? '')) !== '', 'Hedda Siffra finns');
$heddaId = (string) $madeHedda['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($heddaId, $needle): void {
    if (!isset($contacts[$heddaId]) || !is_array($contacts[$heddaId])) {
        return;
    }
    $owners = [];
    foreach (is_array($contacts[$heddaId]['owners'] ?? null) ? $contacts[$heddaId]['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $owner['personnummer'] = $needle;
        $owners[] = $owner;
    }
    if ($owners === []) {
        $owners[] = ['name' => 'Hedda Siffra', 'personnummer' => $needle, 'contact' => true, 'email' => 'hedda.siffra@example.test', 'phone' => '0701000107'];
    }
    $contacts[$heddaId]['owners'] = $owners;
    $contacts[$heddaId]['personnummer'] = $needle;
});
$hedda = jeab_kontakt_get($heddaId);
rp(is_array($hedda), 'Hedda kan läsas');
$heddaPnr = '';
foreach (is_array($hedda['owners'] ?? null) ? $hedda['owners'] : [] as $owner) {
    if (is_array($owner) && trim((string) ($owner['personnummer'] ?? '')) !== '') {
        $heddaPnr = (string) $owner['personnummer'];
        break;
    }
}
rp($heddaPnr === $needle, 'numret ligger bara på kontakten');
$heddaOrder = rp_order(91008, $heddaId, 'Kopia Siffra', 'kopia.siffra@example.test', '0701000192', 'Kopiagatan 5');
rp(!str_contains(json_encode($heddaOrder, JSON_UNESCAPED_UNICODE) ?: '', '19901340'), 'orderns kopia saknar numret');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$heddaRows = [$heddaOrder];
rp(jeab_ordrar_search_rows($heddaRows, $needle) === [], 'sök via kontakten träffar inte personnummer');
rp(jeab_ordrar_search_rows($heddaRows, '19901340') === [], 'sök på siffrorna i numret ger ingen träff');
rp(jeab_ordrar_search_rows($heddaRows, '199013400001') === [], 'sök utan bindestreck ger ingen träff');
$heddaHay = jeab_ordrar_search_text($heddaOrder);
rp(!str_contains($heddaHay, '19901340'), 'söktexten innehåller inte numret');
$heddaByName = jeab_ordrar_search_rows($heddaRows, 'Hedda Siffra');
rp(count($heddaByName) === 1 && (int) ($heddaByName[0]['reminder_id'] ?? 0) === 91008, 'sök hittar fortfarande kontaktens namn');
$heddaByStreet = jeab_ordrar_search_rows($heddaRows, 'Siffergatan');
rp(count($heddaByStreet) === 1, 'sök hittar fortfarande kontaktens gata');
$heddaBoard = jeab_ordrar_board_row_html($heddaOrder, 'jarfalla');
rp(str_contains($heddaBoard, 'Hedda Siffra') && str_contains($heddaBoard, 'Siffergatan'), 'listan visar namn och gata');
rp(!str_contains($heddaBoard, '19901340'), 'listans träfftext innehåller inte numret');
rp(!jeab_kontakt_matches_query($hedda, $needle) && !jeab_kontakt_matches_query($hedda, '19901340'), 'kontaktens sökfält träffar inte numret');
rp(jeab_kontakt_matches_query($hedda, 'hedda siffra'), 'kontaktens sökfält träffar namnet');
$heddaList = jeab_kontakter_view($store, $needle, 'alla', null, 50);
rp((int) ($heddaList['total'] ?? -1) === 0, 'kontaktlistan ger ingen träff på numret');
$heddaListName = jeab_kontakter_view($store, 'Hedda Siffra', 'alla', null, 50);
rp((int) ($heddaListName['total'] ?? 0) >= 1, 'kontaktlistan hittar namnet');
$heddaReminder = [[
    'id' => 91008,
    'contact_id' => $heddaId,
    'customer_name' => 'Kopia Siffra',
    'title' => 'Påminnelse',
    'address' => 'Kopiagatan 5',
    'email' => 'kopia.siffra@example.test',
    'phone' => '0701000192',
    'tags' => [],
    'company' => 'jarfalla',
]];
rp(jeab_filter_reminders($heddaReminder, 'all', null, $needle) === [], 'påminnelsens sök träffar inte numret');
rp(jeab_filter_reminders($heddaReminder, 'all', null, '19901340') === [], 'påminnelsens sök träffar inte siffrorna');
rp(count(jeab_filter_reminders($heddaReminder, 'all', null, 'Hedda')) === 1, 'påminnelsens sök hittar kontaktens namn');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
rp(jeab_ordrar_search_rows($heddaRows, $needle) === [], 'flaggan av söker inte fram numret');
rp(jeab_ordrar_search_rows($heddaRows, 'Kopiagatan') !== [], 'flaggan av hittar orderns egen gata');

echo "contacts sole read paths ok\n";
