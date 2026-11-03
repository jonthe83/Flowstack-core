<?php

declare(strict_types=1);

/**
 * Påminnelser-kort läser inte orderns payload för att måla ett namn (B-020).
 * Ett riktigt namn på kontakten eller raden hoppar över uppslaget.
 * Saknas det läses namnfält för de synliga kontakterna i en fråga.
 * Kortets text är samma som namnreglerna gav när payloaden avkodades.
 * Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-card-names-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=1');
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
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all'];
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_TICK']);
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function cn(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function cn_reset_counts(): void
{
    $GLOBALS['_JEAB_PURCHASE_FOR_CONTACT_CALLS'] = 0;
    $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] = 0;
    $GLOBALS['_JEAB_CONTACTS_SOLE_REMINDER_NAME_SQL'] = 0;
    $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
}

function cn_forget(): void
{
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget();
    }
    if (function_exists('jeab_reminder_contacts_bump')) {
        jeab_reminder_contacts_bump();
    }
    unset($GLOBALS['_JEAB_SHOWN_CUSTOMER'], $GLOBALS['_JEAB_REMINDER_CONTACT_BY_ID']);
}

/** @param array<string, mixed> $contact */
function cn_as_kund(array $contact): string
{
    $id = (string) $contact['id'];
    jeab_kontakter_mutate(static function (array &$contacts) use ($id): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $contacts[$id]['name'] = 'Kund';
        $contacts[$id]['email'] = '';
        $owners = is_array($contacts[$id]['owners'] ?? null) ? $contacts[$id]['owners'] : [];
        if (isset($owners[0]) && is_array($owners[0])) {
            $owners[0]['name'] = 'Kund';
            $owners[0]['email'] = '';
            $contacts[$id]['owners'] = $owners;
        }
    });
    cn_forget();

    return $id;
}

/** @param array<string, mixed> $extra */
function cn_order(PDO $pdo, int $reminderId, string $contactId, array $extra, string $now): void
{
    $payload = array_merge([
        'reminder_id' => $reminderId,
        'contact_id' => $contactId,
        'kind' => 'laddbox',
        'blob' => str_repeat('x', 120000),
    ], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    cn(is_string($json), 'orderpayload kan kodas');
    $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 1)')->execute([
        $reminderId,
        $json,
        $now,
        $contactId,
    ]);
}

$store = jeab_store();
cn($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);
cn(jeab_purchase_sql_pdo() instanceof PDO, 'ordrarna läses från SQL');
cn(jeab_kontakter_sql_is_sole_source(), 'kontakter läses med get mot SQL');
cn(JEAB_REMINDER_DONE_PAGE === 50, 'sidstorleken är oförändrad');

$soleSrc = (string) file_get_contents(dirname(__DIR__) . '/src/contacts_sole.php');
$linkedAt = strpos($soleSrc, 'function jeab_contacts_sole_linked_stored_names');
$resolveAt = strpos($soleSrc, 'function jeab_contacts_sole_resolve_display_name');
cn($linkedAt !== false && $resolveAt !== false && $resolveAt > $linkedAt, 'namnfunktionerna finns');
$linkedFn = substr($soleSrc, $linkedAt, $resolveAt - $linkedAt);
cn(!str_contains($linkedFn, 'jeab_purchase_for_contact'), 'länknamnet anropar inte jeab_purchase_for_contact');
$purchaseAt = strpos($soleSrc, 'function jeab_contacts_sole_linked_names_purchases');
$reminderAt = strpos($soleSrc, 'function jeab_contacts_sole_linked_names_reminders(');
cn($purchaseAt !== false && $reminderAt !== false && $reminderAt > $purchaseAt, 'orderfrågan finns');
$purchaseFn = substr($soleSrc, $purchaseAt, $reminderAt - $purchaseAt);
cn(!str_contains($purchaseFn, 'SELECT reminder_id, payload') && !str_contains($purchaseFn, 'payload FROM'), 'orderfrågan väljer inte payload');
cn(str_contains($purchaseFn, 'contact_id IN'), 'orderfrågan samlar kontakterna');
cn(str_contains($purchaseFn, 'JSON_VALID(payload)'), 'orderfrågan hoppar över trasig JSON');
cn(str_contains($purchaseFn, 'NULLIF(') && str_contains($purchaseFn, ", 'null')"), 'orderfrågan gör JSON-null till SQL NULL');

$now = jeab_now();
$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Målad',
    'email' => 'ada.malad@example.test',
    'phone' => '0701000301',
    'address' => 'Malargatan 12, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($ada), 'Ada finns');
$adaId = (string) $ada['id'];
$adaRow = $store->insertReminder([
    'title' => 'Ada',
    'customer_name' => 'Kopia Ada',
    'email' => 'kopia.ada@example.test',
    'phone' => '0701000302',
    'address' => 'Kopiagatan 1, Felort',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $adaRow['id'], $adaId), 'Ada länkas');
cn_order($pdo, 91011, $adaId, [
    'customer_name' => 'Annat Ordernamn',
    'name' => 'Annat Ordernamn',
    'email' => 'annat.order@example.test',
], $now);

$kort = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Kort',
    'email' => 'tillfallig.kort@example.test',
    'phone' => '0701000303',
    'address' => 'Kortgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($kort), 'Kort finns');
$kortId = cn_as_kund($kort);
$kortRow = $store->insertReminder([
    'title' => 'Kort',
    'customer_name' => 'Kort Namn',
    'email' => '',
    'phone' => '0701000304',
    'address' => 'Kortgatan 2',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $kortRow['id'], $kortId), 'Kort länkas');
cn_order($pdo, 91021, $kortId, [
    'customer_name' => 'Annat Ordernamn',
    'email' => 'annat.kort@example.test',
], $now);

$orderNamn = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Order',
    'email' => 'tillfallig.order@example.test',
    'phone' => '0701000305',
    'address' => 'Ordergatan 3, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($orderNamn), 'Orderkontakt finns');
$orderId = cn_as_kund($orderNamn);
$orderRow = $store->insertReminder([
    'title' => 'Bara förnamn',
    'customer_name' => 'Kort',
    'email' => '',
    'phone' => '0701000306',
    'address' => 'Ordergatan 3',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $orderRow['id'], $orderId), 'ordernamnet länkas');
cn_order($pdo, 91001, $orderId, [
    'huvudsokande' => 'Alfa Första',
    'customer_name' => 'Alfa Första',
    'email' => 'alfa@example.test',
    'applicant' => ['name' => 'Sökande Person', 'email' => 'sokande@example.test'],
], $now);
cn_order($pdo, 91002, $orderId, [
    'customer_name' => 'Beta Andra',
    'email' => 'beta@example.test',
], $now);

$lokal = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Lokal',
    'email' => 'tillfallig.lokal@example.test',
    'phone' => '0701000307',
    'address' => 'Lokalgatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($lokal), 'Lokalkontakt finns');
$lokalId = cn_as_kund($lokal);
$lokalRow = $store->insertReminder([
    'title' => 'Lokaldel',
    'customer_name' => 'Ada',
    'email' => '',
    'phone' => '0701000308',
    'address' => 'Lokalgatan 4',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 12:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $lokalRow['id'], $lokalId), 'lokaldelen länkas');
cn_order($pdo, 91031, $lokalId, [
    'customer_name' => 'Ada',
    'email' => 'ada@example.test',
], $now);

$syster = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Syster',
    'email' => 'tillfallig.syster@example.test',
    'phone' => '0701000309',
    'address' => 'Systergatan 5, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($syster), 'Systerkontakt finns');
$systerId = cn_as_kund($syster);
$systerA = $store->insertReminder([
    'title' => 'Ensam',
    'customer_name' => 'Ensam',
    'email' => '',
    'phone' => '0701000310',
    'address' => 'Systergatan 5',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 13:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$systerB = $store->insertReminder([
    'title' => 'Syster',
    'customer_name' => 'Syster Kontakt',
    'email' => 'syster.kontakt@example.test',
    'phone' => '0701000311',
    'address' => 'Systergatan 5',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 14:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $systerA['id'], $systerId), 'syster A länkas');
cn(jeab_kontakt_stamp_reminder($store, (int) $systerB['id'], $systerId), 'syster B länkas');

$adaFresh = $store->getReminder((int) $adaRow['id']);
$kortFresh = $store->getReminder((int) $kortRow['id']);
$orderFresh = $store->getReminder((int) $orderRow['id']);
$lokalFresh = $store->getReminder((int) $lokalRow['id']);
$systerFresh = $store->getReminder((int) $systerA['id']);
cn(is_array($adaFresh) && is_array($kortFresh) && is_array($orderFresh) && is_array($lokalFresh) && is_array($systerFresh), 'raderna finns');

cn_forget();
cn_reset_counts();
jeab_reminder_preload_contacts([$adaFresh, $kortFresh]);
$adaShown = jeab_reminder_shown_customer($adaFresh);
$kortShown = jeab_reminder_shown_customer($kortFresh);
$adaHtml = jeab_reminder_row($adaFresh, false);
$kortHtml = jeab_reminder_row($kortFresh, false);
cn($adaShown['name'] === 'Ada Målad' && str_contains($adaHtml, 'Ada Målad') && !str_contains($adaHtml, 'Annat Ordernamn'), 'riktigt kontaktnamn lämnar ordernamnet');
cn($kortShown['name'] === 'Kort Namn' && str_contains($kortHtml, 'Kort Namn') && !str_contains($kortHtml, 'Annat Ordernamn'), 'fullständigt radnamn lämnar ordernamnet');
cn((int) $GLOBALS['_JEAB_PURCHASE_FOR_CONTACT_CALLS'] === 0, 'listan med namn anropar inte jeab_purchase_for_contact');
cn((int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] === 0, 'listan med namn läser inga ordrar');
cn((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'listan laddar inte orderlagret');
$adaView = jeab_contacts_sole_view_for($adaFresh);
cn(is_array($adaView) && $adaView['name'] === 'Ada Målad', 'view_for behåller Ada Målad');

cn_forget();
cn_reset_counts();
jeab_reminder_preload_contacts([$orderFresh, $lokalFresh]);
cn((int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] === 1, 'två kontakter utan namn är en orderfråga, fick ' . (int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL']);
$orderShown = jeab_reminder_shown_customer($orderFresh);
$lokalShown = jeab_reminder_shown_customer($lokalFresh);
$orderHtml = jeab_reminder_row($orderFresh, false);
cn((int) $GLOBALS['_JEAB_PURCHASE_FOR_CONTACT_CALLS'] === 0, 'korten anropar inte jeab_purchase_for_contact');
cn((int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] === 1, 'målningen ställer ingen ny orderfråga');
cn($orderShown['name'] === 'Alfa Första' && str_contains($orderHtml, 'Alfa Första') && !str_contains($orderHtml, 'Beta Andra'), 'första orderns namn i id-ordning visas');
cn(!str_contains($orderHtml, 'xxxx'), 'payloadens skräp ritas inte');
$orderView = jeab_contacts_sole_view_for($orderFresh);
cn(is_array($orderView) && $orderView['name'] === 'Alfa Första', 'view_for ger samma ordernamn');
$lokalView = jeab_contacts_sole_view_for($lokalFresh);
cn(is_array($lokalView) && $lokalView['name'] === 'ada@example.test', 'view_for tar e-posten när ordern gör Ada till en lokaldel, fick ' . (string) ($lokalView['name'] ?? ''));
cn($lokalShown['name'] === 'Ada', 'kortet visar samma radnamn som förut när visningen tvättar e-posten, fick ' . $lokalShown['name']);

cn_forget();
cn_reset_counts();
$systerShown = jeab_reminder_shown_customer($systerFresh);
cn($systerShown['name'] === 'Syster Kontakt', 'namnet kan komma från den andra påminnelsen');
cn((int) $GLOBALS['_JEAB_PURCHASE_FOR_CONTACT_CALLS'] === 0, 'påminnelsenamnet läser ingen payload');
cn((int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] === 1, 'en enstaka rad utan förhandshämtning är en orderfråga');

$jsonNull = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Jsonnull',
    'email' => 'tillfallig.jsonnull@example.test',
    'phone' => '0701000312',
    'address' => 'Nollgatan 6, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($jsonNull), 'Jsonnull-kontakt finns');
$jsonNullId = cn_as_kund($jsonNull);
$jsonNullRow = $store->insertReminder([
    'title' => 'Jsonnull',
    'customer_name' => 'Kund',
    'email' => '',
    'phone' => '0701000313',
    'address' => 'Nollgatan 6',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 15:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $jsonNullRow['id'], $jsonNullId), 'jsonnull länkas');
cn_order($pdo, 91041, $jsonNullId, [
    'huvudsokande' => null,
    'customer_name' => null,
    'name' => null,
    'email' => 'synlig.null@example.test',
    'applicant' => ['name' => null, 'email' => null],
], $now);

$trasig = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Trasig',
    'email' => 'tillfallig.trasig@example.test',
    'phone' => '0701000314',
    'address' => 'Trasiggatan 7, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($trasig), 'Trasig-kontakt finns');
$trasigId = cn_as_kund($trasig);
$trasigRow = $store->insertReminder([
    'title' => 'Trasig',
    'customer_name' => 'Kund',
    'email' => '',
    'phone' => '0701000315',
    'address' => 'Trasiggatan 7',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 16:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $trasigRow['id'], $trasigId), 'trasig länkas');
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 1)')->execute([
    91051,
    'not-json',
    $now,
    $trasigId,
]);

$gamma = jeab_kontakt_find_or_create([
    'name' => 'Tillfällig Gamma',
    'email' => 'tillfallig.gamma@example.test',
    'phone' => '0701000316',
    'address' => 'Gammagatan 8, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
cn(is_array($gamma), 'Gamma-kontakt finns');
$gammaId = cn_as_kund($gamma);
$gammaRow = $store->insertReminder([
    'title' => 'Gamma',
    'customer_name' => 'Kund',
    'email' => '',
    'phone' => '0701000317',
    'address' => 'Gammagatan 8',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 17:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
cn(jeab_kontakt_stamp_reminder($store, (int) $gammaRow['id'], $gammaId), 'gamma länkas');
cn_order($pdo, 91061, $gammaId, [
    'customer_name' => 'Gamma Tredje',
    'name' => null,
    'email' => 'gamma.tredje@example.test',
], $now);

$jsonNullFresh = $store->getReminder((int) $jsonNullRow['id']);
$trasigFresh = $store->getReminder((int) $trasigRow['id']);
$gammaFresh = $store->getReminder((int) $gammaRow['id']);
cn(is_array($jsonNullFresh) && is_array($trasigFresh) && is_array($gammaFresh), 'null- och trasig-raderna finns');
cn_forget();
cn_reset_counts();
jeab_reminder_preload_contacts([$jsonNullFresh, $trasigFresh, $gammaFresh]);
cn((int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] === 1, 'null och trasig JSON är en orderfråga, fick ' . (int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL']);
$nullPack = $GLOBALS['_JEAB_CONTACTS_SOLE_LINKED_NAMES'][$jsonNullId] ?? null;
cn(is_array($nullPack), 'jsonnull har ett namnpaket');
$nullNames = is_array($nullPack['names'] ?? null) ? $nullPack['names'] : [];
cn(!in_array('null', $nullNames, true), 'JSON-null blir inte namnet null');
$nullView = jeab_contacts_sole_view_for($jsonNullFresh);
$nullShown = jeab_reminder_shown_customer($jsonNullFresh);
$nullHtml = jeab_reminder_row($jsonNullFresh, false);
cn(is_array($nullView) && $nullView['name'] === 'synlig.null@example.test', 'JSON-null lämnar e-posten, fick ' . (string) ($nullView['name'] ?? ''));
cn($nullShown['name'] === 'synlig.null@example.test', 'kortet visar inte ordet null, fick ' . $nullShown['name']);
cn(!str_contains($nullHtml, '>null<'), 'kortets HTML målar inte null som namn');
$gammaShown = jeab_reminder_shown_customer($gammaFresh);
$gammaHtml = jeab_reminder_row($gammaFresh, false);
cn($gammaShown['name'] === 'Gamma Tredje' && str_contains($gammaHtml, 'Gamma Tredje'), 'trasig payload i samma bunt döljer inte grannens namn, fick ' . $gammaShown['name']);
cn((int) $GLOBALS['_JEAB_CONTACTS_SOLE_PURCHASE_NAME_SQL'] === 1, 'målningen efter trasig JSON ställer ingen ny orderfråga');

require_once dirname(__DIR__) . '/src/biz_doc_store.php';
cn(jeab_biz_doc_use_sql('reminder_ort_cache'), 'ortcachen ligger i SQL i det här testet');
$biz = jeab_biz_doc_store_pdo();
cn($biz instanceof PDO, 'biz-doc har PDO');
jeab_sql_doc_put($biz, 'biz_reminder_ort_cache', [
    'nyckel' => ['c' => 'Aggregat', 't' => time()],
]);
jeab_sql_doc_put($biz, 'ort_nyckel', ['c' => 'Enskild', 't' => time()]);
unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE'], $GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_TICK']);
$GLOBALS['_JEAB_REMINDER_ORT_LIKE'] = 0;
jeab_reminder_ort_lookup_budget(0);
$without = jeab_reminder_ort_cache_read();
cn((int) $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] === 0, 'uppslagsbudget 0 skannar inte ort_-nycklar');
cn(($without['nyckel']['c'] ?? '') === 'Aggregat', 'listan läser det samlade ort-dokumentet');
unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE']);
jeab_reminder_ort_lookup_budget(1);
$with = jeab_reminder_ort_cache_read();
cn((int) $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] === 1, 'uppslag får fortfarande läsa ort_-nycklar');
cn(($with['nyckel']['c'] ?? '') === 'Enskild', 'en ort_-nyckel vinner när uppslag är tillåtet');

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE'], $GLOBALS['_JEAB_TICK']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_REMINDER_ORT_LIKE'] = 0;
jeab_reminder_ort_lookup_budget(1);
$pageRead = jeab_reminder_ort_cache_read();
cn((int) $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] === 0, 'sidan skannar inte ort_-nycklar även med budget');
cn(($pageRead['nyckel']['c'] ?? '') === 'Aggregat', 'sidan behåller det samlade ort-dokumentet');

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE'], $GLOBALS['_JEAB_PAGE_REQUEST']);
$GLOBALS['_JEAB_TICK'] = true;
$GLOBALS['_JEAB_REMINDER_ORT_LIKE'] = 0;
jeab_reminder_ort_lookup_budget(0);
$tickBlocked = jeab_reminder_ort_cache_read();
cn((int) $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] === 0, 'tick med budget 0 skannar inte ort_-nycklar');
cn(($tickBlocked['nyckel']['c'] ?? '') === 'Aggregat', 'budget 0 lämnar det samlade dokumentet');

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_TICK'] = true;
$GLOBALS['_JEAB_REMINDER_ORT_LIKE'] = 0;
jeab_reminder_ort_lookup_budget(1);
$tickRead = jeab_reminder_ort_cache_read();
cn((int) $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] === 1, 'tick skannar ort_-nycklar även på en sidflagga');
cn(($tickRead['nyckel']['c'] ?? '') === 'Enskild', 'tick läser ort_-nyckeln');

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE'], $GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_TICK']);
$_GET['intent'] = 'pulse';
$GLOBALS['_JEAB_REMINDER_ORT_LIKE'] = 0;
jeab_reminder_ort_lookup_budget(5);
$pulseRead = jeab_reminder_ort_cache_read();
cn((int) $GLOBALS['_JEAB_REMINDER_ORT_LIKE'] === 0, 'puls skannar inte ort_-nycklar');
cn(($pulseRead['nyckel']['c'] ?? '') === 'Aggregat', 'puls läser det samlade ort-dokumentet');
unset($_GET['intent']);

echo "reminder card names ok\n";
