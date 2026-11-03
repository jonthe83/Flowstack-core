<?php

declare(strict_types=1);

/**
 * P1-9 orderindexet fylls i tick, inte när en kontakt läses.
 * P1-11 länkad kontakt hämtas med get($id).
 * E2 telefon och e-post har type.
 * Paint skriver inte tomma kundfält över kopian och flaggar en saknad länk.
 * Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-links-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
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
require_once dirname(__DIR__) . '/src/tick.php';
require_once dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function kl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
kl($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_ensure_contact_id_columns($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);
kl(jeab_purchase_sql_pdo() instanceof PDO, 'ordrarna läses från SQL');
kl(jeab_kontakter_sql_is_sole_source(), 'kontakter läses med get mot SQL');

$now = jeab_now();
$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Länk',
    'email' => 'ada.lank@example.test',
    'phone' => '0701000091',
    'address' => 'Lankgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
kl(is_array($ada) && trim((string) ($ada['id'] ?? '')) !== '', 'kontakt finns');
$adaId = (string) $ada['id'];

$keptReminder = $store->insertReminder([
    'title' => 'Behållen påminnelse',
    'customer_name' => 'Kopia Påminnelse',
    'email' => 'paminnelse.kopia@example.test',
    'phone' => '0701000092',
    'address' => 'Paminnelsegatan 2',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
kl(jeab_kontakt_stamp_reminder($store, (int) $keptReminder['id'], $adaId), 'påminnelsen får contact_id');
$customer = jeab_find_or_create_customer($store, 'Kopia Bokning');
$keptBooking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Bokning',
    'email' => 'bokning.kopia@example.test',
    'phone' => '0701000093',
    'address' => 'Bokningsgatan 3',
    'notes' => 'tiden ska bestå',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
kl(jeab_kontakt_stamp_booking($store, (int) $keptBooking['id'], $adaId), 'bokningen får contact_id');
jeab_purchase_put([
    'reminder_id' => (int) $keptReminder['id'],
    'kind' => 'laddbox',
    'name' => 'Kopia Order',
    'customer_name' => 'Kopia Order',
    'email' => 'order.kopia@example.test',
    'phone' => '0701000094',
    'address' => 'Ordergatan 4',
    'contact_id' => $adaId,
    'created_at' => $now,
    'updated_at' => $now,
]);

$before = [
    'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
    'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'purchases' => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
];
$beforeLinks = [
    'reminder' => (string) $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . (int) $keptReminder['id'])->fetchColumn(),
    'booking' => (string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . (int) $keptBooking['id'])->fetchColumn(),
    'order' => (string) $pdo->query('SELECT contact_id FROM purchases WHERE reminder_id = ' . (int) $keptReminder['id'])->fetchColumn(),
];
kl($beforeLinks['reminder'] === $adaId && $beforeLinks['booking'] === $adaId && $beforeLinks['order'] === $adaId, 'befintliga länkar är satta');

jeab_settings_put($pdo, 'sqlite', 'purchases_contact_id_v1', '');
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 0)')->execute([
    88001,
    json_encode([
        'reminder_id' => 88001,
        'contact_id' => $adaId,
        'name' => 'Ofylld order',
        'email' => 'ofylld@example.test',
        'kind' => 'batteri',
    ], JSON_UNESCAPED_UNICODE),
    $now,
    '',
]);
jeab_purchase_cache_forget();
$before['purchases']++;
$early = jeab_purchase_for_contact($adaId);
$earlyIds = array_map(static fn(array $row): int => (int) ($row['reminder_id'] ?? 0), $early);
kl(!in_array(88001, $earlyIds, true), 'kontaktläsningen fyller inte orderindexet');
kl((int) $pdo->query('SELECT contact_indexed FROM purchases WHERE reminder_id = 88001')->fetchColumn() === 0, 'ofylld order är fortfarande oindexerad');
kl((string) $pdo->query('SELECT contact_id FROM purchases WHERE reminder_id = 88001')->fetchColumn() === '', 'ofylld order behåller tom kolumn tills tick');

jeab_tick_maintenance($pdo);
kl((int) $pdo->query('SELECT contact_indexed FROM purchases WHERE reminder_id = 88001')->fetchColumn() === 1, 'tick fyller orderindexet');
kl((string) $pdo->query('SELECT contact_id FROM purchases WHERE reminder_id = 88001')->fetchColumn() === $adaId, 'tick skriver contact_id från payload');
jeab_purchase_cache_forget();
$later = jeab_purchase_for_contact($adaId);
$laterIds = array_map(static fn(array $row): int => (int) ($row['reminder_id'] ?? 0), $later);
kl(in_array(88001, $laterIds, true) && in_array((int) $keptReminder['id'], $laterIds, true), 'båda ordrarna syns efter tick');

$after = [
    'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
    'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'purchases' => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
];
$afterLinks = [
    'reminder' => (string) $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . (int) $keptReminder['id'])->fetchColumn(),
    'booking' => (string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . (int) $keptBooking['id'])->fetchColumn(),
    'order' => (string) $pdo->query('SELECT contact_id FROM purchases WHERE reminder_id = ' . (int) $keptReminder['id'])->fetchColumn(),
];
kl($after === $before, 'antal påminnelser, bokningar och ordrar är oförändrat');
kl($afterLinks === $beforeLinks, 'befintliga contact_id består');
$payload = json_decode((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 88001')->fetchColumn(), true);
kl(is_array($payload) && (string) ($payload['name'] ?? '') === 'Ofylld order' && (string) ($payload['contact_id'] ?? '') === $adaId, 'payloadens namn och länk består');

$GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = 0;
$linked = jeab_contacts_sole_linked_contact($adaId);
kl(is_array($linked) && (string) ($linked['id'] ?? '') === $adaId, 'länkad kontakt hämtas med get');
kl((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === 0, 'get läser inte hela kontakttabellen');
kl(jeab_contacts_sole_linked_contact('kt_finns_inte') === null, 'saknad kontakt är null');
kl((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === 0, 'en missad get läser inte hela tabellen');

$error = null;
$flash = null;
$_GET = ['p' => 'kontakter', 'id' => 'kt_finns_inte', 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = 0;
$missPage = require dirname(__DIR__) . '/src/pages/kontakter.php';
kl(is_string($missPage), 'kontaktsidan med okänt id renderas');
kl((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === 0, 'okänt id på sidan läser inte hela registret');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$order = [
    'reminder_id' => 88001,
    'contact_id' => '',
    'name' => 'Kopia Namn',
    'huvudsokande' => 'Kopia Namn',
    'customer_name' => 'Kopia Namn',
    'email' => 'kopia@example.test',
    'phone' => '0701000095',
    'address' => 'Kopiagatan 5',
    'huvudsokande_address' => 'Kopiagatan 5',
    'gata' => 'Kopiagatan 5',
    'ort' => 'Teststad',
    'postnummer' => '11122',
    'postnr' => '111 22',
    'applicant' => ['name' => 'Kopia Namn', 'email' => 'kopia@example.test', 'phone' => '0701000095'],
    'kind' => 'laddbox',
];
$painted = jeab_contacts_sole_paint_order($order);
kl(($painted['contact_link_missing'] ?? false) === true, 'order utan contact_id flaggas');
kl($painted['name'] === 'Kopia Namn' && $painted['email'] === 'kopia@example.test' && $painted['phone'] === '0701000095', 'order utan länk behåller kopian');
kl($painted['address'] === 'Kopiagatan 5' && $painted['gata'] === 'Kopiagatan 5', 'order utan länk behåller adressen');
kl($painted['applicant']['email'] === 'kopia@example.test', 'sökande skrivs inte tom');
kl((string) ($painted['contact_id'] ?? '') === '', 'paint sätter inget contact_id');

$dangling = $order;
$dangling['contact_id'] = 'kt_borta';
$danglingPaint = jeab_contacts_sole_paint_order($dangling);
kl(($danglingPaint['contact_link_missing'] ?? false) === true && $danglingPaint['email'] === 'kopia@example.test', 'trasig länk flaggas och lämnar kopian');
kl((string) $danglingPaint['contact_id'] === 'kt_borta', 'trasig contact_id lämnas');

$booking = [
    'id' => (int) $keptBooking['id'],
    'contact_id' => '',
    'customer_name' => 'Kopia Bokning',
    'email' => 'bokning.kopia@example.test',
    'phone' => '0701000093',
    'address' => 'Bokningsgatan 3',
    'starts_at' => '2026-11-02 09:00:00',
    'notes' => 'tiden ska bestå',
];
$bookingPaint = jeab_contacts_sole_paint_booking($booking);
kl(($bookingPaint['contact_link_missing'] ?? false) === true, 'bokning utan länk flaggas');
kl($bookingPaint['customer_name'] === 'Kopia Bokning' && $bookingPaint['address'] === 'Bokningsgatan 3' && $bookingPaint['notes'] === 'tiden ska bestå', 'bokningens kopia och tid består');

$partial = $order;
$partial['contact_id'] = $adaId;
$partial['contact_link_missing'] = true;
$ada['email'] = '';
jeab_kontakter_mutate(static function (array &$contacts) use ($adaId): void {
    if (!isset($contacts[$adaId]) || !is_array($contacts[$adaId])) {
        return;
    }
    $owners = is_array($contacts[$adaId]['owners'] ?? null) ? $contacts[$adaId]['owners'] : [];
    if (isset($owners[0]) && is_array($owners[0])) {
        $owners[0]['email'] = '';
        $contacts[$adaId]['owners'] = $owners;
    }
});
if (function_exists('jeab_reminder_contacts_bump')) {
    jeab_reminder_contacts_bump();
}
$partialPaint = jeab_contacts_sole_paint_order($partial);
kl(empty($partialPaint['contact_link_missing']), 'hittad kontakt tar bort saknad-flaggan');
kl($partialPaint['name'] === 'Ada Länk', 'hittad kontakt fyller namnet');
kl($partialPaint['email'] === '', 'tom e-post på kontakten visas tom');
$freshAda = jeab_kontakt_get($adaId);
$wantPhone = is_array($freshAda) ? jeab_kontakt_row_phone($freshAda) : '';
kl($wantPhone !== '' && $partialPaint['phone'] === $wantPhone && $partialPaint['phone'] !== '0701000095', 'kontaktens telefon används när den finns');
kl((string) $partialPaint['contact_id'] === $adaId, 'paint lämnar contact_id');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$off = jeab_contacts_sole_paint_order($order);
kl(!array_key_exists('contact_link_missing', $off) && $off['name'] === 'Kopia Namn', 'flaggan av lämnar raden');

$shown = $store->getReminder((int) $keptReminder['id']);
kl(is_array($shown), 'påminnelsen finns kvar');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['', (int) $keptReminder['id']]);
$bare = $store->getReminder((int) $keptReminder['id']);
kl(is_array($bare) && trim((string) ($bare['contact_id'] ?? '')) === '', 'påminnelsen kan sakna länk');
$customerView = jeab_reminder_shown_customer($bare);
kl($customerView['name'] === 'Kopia Påminnelse' && $customerView['email'] === 'paminnelse.kopia@example.test', 'påminnelse utan länk visar kopian');
kl(empty($customerView['linked']), 'påminnelse utan länk är inte kopplad');
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$adaId, (int) $keptReminder['id']]);
$restored = $store->getReminder((int) $keptReminder['id']);
kl(is_array($restored) && (string) ($restored['contact_id'] ?? '') === $adaId, 'påminnelsens länk kan sättas tillbaka');

$root = dirname(__DIR__);
$registry = (string) file_get_contents($root . '/src/pages/kontakter.php');
$inbox = (string) file_get_contents($root . '/src/pages/inkorg_kontakter.php');
kl(str_contains($registry, 'type="email"') && str_contains($registry, 'type="tel"'), 'kontakter har type på e-post och telefon');
kl(str_contains($inbox, 'name="mobile" type="tel"') && str_contains($inbox, 'name="emails" type="email"'), 'inkorgens kontakter har type på mobil och e-post');
$_GET = ['p' => 'inkorg-kontakter', 'ny' => '1'];
$inboxHtml = require $root . '/src/pages/inkorg_kontakter.php';
kl(is_string($inboxHtml) && str_contains($inboxHtml, 'type="tel"') && str_contains($inboxHtml, 'type="email"'), 'nytt kontaktformulär renderar type');

$purchaseSrc = (string) file_get_contents($root . '/src/purchase.php');
$fnAt = strpos($purchaseSrc, 'function jeab_purchase_for_contact');
$fnEnd = $fnAt === false ? false : strpos($purchaseSrc, "\nfunction ", $fnAt + 10);
$fn = $fnAt === false ? '' : ($fnEnd === false ? substr($purchaseSrc, $fnAt) : substr($purchaseSrc, $fnAt, $fnEnd - $fnAt));
kl($fn !== '' && !str_contains($fn, 'jeab_purchases_contact_index_backfill'), 'purchase_for_contact anropar inte backfill');
$tickSrc = (string) file_get_contents($root . '/src/tick.php');
$maintAt = strpos($tickSrc, 'function jeab_tick_maintenance');
$maintEnd = $maintAt === false ? false : strpos($tickSrc, "\nfunction ", $maintAt + 10);
$maint = $maintAt === false ? '' : ($maintEnd === false ? substr($tickSrc, $maintAt) : substr($tickSrc, $maintAt, $maintEnd - $maintAt));
kl(str_contains($maint, 'jeab_purchases_contact_index_backfill'), 'tick fyller orderindexet');
kl(str_contains($maint, 'jeab_purchases_list_json_backfill'), 'tick fyller list_json');

$end = [
    'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
    'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
    'purchases' => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
];
kl($end === $before, 'testen lämnar samma antal rader');
kl((string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . (int) $keptBooking['id'])->fetchColumn() === $adaId, 'bokningens länk består');
kl((string) $pdo->query('SELECT notes FROM bookings WHERE id = ' . (int) $keptBooking['id'])->fetchColumn() === 'tiden ska bestå', 'bokningens anteckning består');

echo "kontakter links paint ok\n";
