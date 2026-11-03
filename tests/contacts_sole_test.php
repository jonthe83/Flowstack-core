<?php

declare(strict_types=1);

/**
 * CONTACTS_SOLE. Påhittade namn. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sole-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('CONTACTS_SOLE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);

function sole_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$now = jeab_now();

$kept = jeab_kontakt_find_or_create([
    'name' => 'Ada Behåll',
    'email' => 'ada.behall@example.test',
    'phone' => '0701000001',
    'address' => 'Behallgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$byMail = jeab_kontakt_find_or_create([
    'name' => 'Bo Mejl',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701000002',
    'address' => 'Mejlgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$byPhone = jeab_kontakt_find_or_create([
    'name' => 'Caj Telefon',
    'email' => 'caj.telefon@example.test',
    'phone' => '0701000003',
    'address' => 'Telefongatan 3, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$byAddress = jeab_kontakt_find_or_create([
    'name' => 'Disa Adress',
    'email' => 'disa.adress@example.test',
    'phone' => '0701000004',
    'address' => 'Adressgatan 4, 222 33 Testort',
    'city' => 'Testort',
    'postal_code' => '22233',
], false);
sole_ok(is_array($kept) && is_array($byMail) && is_array($byPhone) && is_array($byAddress), 'fyra kontakter finns före migreringen');

$reminder = static function (array $extra) use ($store, $now): array {
    return $store->insertReminder(array_merge([
        'title' => 'Batteri',
        'company' => 'jarfalla',
        'due_at' => '2026-10-21 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ], $extra));
};

$keptRow = $reminder([
    'customer_name' => 'Kopia Behåll',
    'email' => 'annan@example.test',
    'phone' => '0701999999',
    'address' => 'Annan gata 9',
    'ort' => 'Annanort',
    'postal' => '99999',
]);
$store->saveReminder((int) $keptRow['id'], ['contact_id' => (string) $kept['id']]);

$mailRow = $reminder([
    'customer_name' => 'Kopia Mejl',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701888888',
    'address' => 'Felgatan 1',
    'ort' => 'Felort',
    'postal' => '88888',
]);
$phoneRow = $reminder([
    'customer_name' => 'Kopia Telefon',
    'email' => 'fel@example.test',
    'phone' => '0701000003',
    'address' => 'Felgatan 2',
]);
$addressRow = $reminder([
    'customer_name' => 'Kopia Adress',
    'email' => 'adress.kopia@example.test',
    'phone' => '0701777777',
    'address' => 'Adressgatan 4, 222 33 Testort',
    'ort' => 'Testort',
    'postal' => '22233',
]);
$createRow = $reminder([
    'customer_name' => 'Eja Ny',
    'email' => 'eja.ny@example.test',
    'phone' => '0701000005',
    'address' => 'Nygatan 5, 333 44 Testby',
    'ort' => 'Testby',
    'postal' => '33344',
]);
$dangling = $reminder([
    'customer_name' => 'Fia Saknad',
    'email' => 'fia.saknad@example.test',
    'phone' => '0701000006',
    'address' => 'Saknadgatan 6',
    'ort' => 'Teststad',
    'postal' => '11122',
]);
$store->saveReminder((int) $dangling['id'], ['contact_id' => 'kt_saknas']);

$orderReminder = $reminder([
    'customer_name' => 'Orderkopia',
    'email' => 'order.kopia@example.test',
    'title' => 'Order',
]);
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'laddbox',
    'name' => 'Kopia Order',
    'customer_name' => 'Kopia Order',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701666666',
    'address' => 'Ordergatan 7',
    'ort' => 'Felort',
    'postnummer' => '77777',
    'created_at' => $now,
    'updated_at' => $now,
]);
$closedReminder = $reminder([
    'customer_name' => 'Stängd kopia',
    'email' => 'stangd.kopia@example.test',
    'title' => 'Stängd',
]);
jeab_purchase_put([
    'reminder_id' => (int) $closedReminder['id'],
    'kind' => 'batteri',
    'name' => 'Kopia Stängd',
    'email' => 'stangd.kopia@example.test',
    'phone' => '0701000007',
    'address' => 'Stangdgatan 8, 444 55 Teststad',
    'ort' => 'Teststad',
    'postnummer' => '44455',
    'closed_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);

$customer = jeab_find_or_create_customer($store, 'Kopia Bokning');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Bokning',
    'email' => 'bokning.kopia@example.test',
    'phone' => '0701000008',
    'address' => 'Bokningsgatan 9, 555 66 Testby',
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

$before = jeab_contacts_sole_counts($store);
$beforeIds = jeab_contacts_sole_ids($store);
$mailStamp = (string) ($store->getReminder((int) $mailRow['id'])['updated_at'] ?? '');
$orderStamp = (string) (jeab_purchase_get((int) $orderReminder['id'])['updated_at'] ?? '');
$bookingStamp = (string) ($store->getBooking((int) $booking['id'])['updated_at'] ?? '');
$keptStamp = (string) ($store->getReminder((int) $keptRow['id'])['updated_at'] ?? '');
$off = jeab_contacts_sole_migrate(['dry' => 0], $store);
sole_ok(empty($off['ok']) && empty($off['written']) && empty($off['skipped']), 'flaggan av skriver inget');
sole_ok(str_contains((string) ($off['error'] ?? ''), 'CONTACTS_SOLE'), 'dry=0 utan flagga säger att flaggan krävs');
sole_ok(jeab_contacts_sole_counts($store) === $before, 'flaggan av lämnar antalet');
sole_ok(trim((string) ($store->getReminder((int) $mailRow['id'])['contact_id'] ?? '')) === '', 'flaggan av lämnar olänkad påminnelse');
sole_ok(jeab_reminder_shown_customer($store->getReminder((int) $mailRow['id']))['name'] === 'Kopia Mejl', 'flaggan av visar kopian');

$plan = jeab_contacts_sole_migrate([], $store);
$planFill = is_array($plan['fill'] ?? null) ? $plan['fill'] : [];
sole_ok(!empty($plan['ok']) && !empty($plan['dry']) && empty($plan['written']) && empty($plan['skipped']) && empty($plan['flag']), 'torr plan räknas utan flagga');
sole_ok((int) ($planFill['reminders'] ?? -1) === 6 && (int) ($planFill['orders'] ?? -1) === 2 && (int) ($planFill['bookings'] ?? -1) === 1, 'planen räknar tomma rader per tabell');
// Kopia Adress delar bara adressen med Disa Adress (annat namn): egen kontakt, ingen adresslänk.
sole_ok((int) ($plan['created'] ?? 0) === 5 && (int) ($plan['linked'] ?? 0) === 9, 'planen räknar nya kontakter och rader som ska fyllas, fick ' . (int) ($plan['created'] ?? 0) . '/' . (int) ($plan['linked'] ?? 0));
sole_ok(array_key_exists('next_cursor', $plan) && $plan['next_cursor'] === null, 'liten mängd blir klar i ett anrop');
$planBroken = false;
foreach (is_array($plan['deviations'] ?? null) ? $plan['deviations'] : [] as $deviation) {
    if (is_array($deviation) && (string) ($deviation['contact_id'] ?? '') === 'kt_saknas' && (string) ($deviation['reason'] ?? '') === 'saknad') {
        $planBroken = true;
    }
}
sole_ok($planBroken, 'planen listar den trasiga länken utan flagga');
sole_ok(trim((string) ($store->getReminder((int) $createRow['id'])['contact_id'] ?? '')) === '', 'planen sätter inget contact_id');
sole_ok(jeab_contacts_sole_counts($store) === $before && jeab_contacts_sole_ids($store) === $beforeIds, 'planen lämnar antal och id');

$pageCreated = 0;
$pageLinked = 0;
$pageDeviations = 0;
$cursor = null;
$pages = 0;
do {
    $pageInput = ['dry' => 1, 'limit' => 2];
    if (is_string($cursor)) {
        $pageInput['cursor'] = $cursor;
    }
    $page = jeab_contacts_sole_migrate($pageInput, $store);
    sole_ok(!empty($page['ok']) && !empty($page['dry']) && empty($page['written']), 'en sida av planen skriver inget');
    $pageCreated += (int) ($page['created'] ?? 0);
    $pageLinked += (int) ($page['linked'] ?? 0);
    $pageDeviations += count(is_array($page['deviations'] ?? null) ? $page['deviations'] : []);
    $cursor = $page['next_cursor'] ?? null;
    $pages++;
} while (is_string($cursor) && $cursor !== '' && $pages < 30);
sole_ok($pages > 1 && $pages < 30, 'limit delar planen i flera svar');
sole_ok($pageCreated === (int) $plan['created'] && $pageLinked === (int) $plan['linked'], 'sidorna summerar till samma skapade och fyllda');
sole_ok($pageDeviations === count(is_array($plan['deviations'] ?? null) ? $plan['deviations'] : []), 'sidorna summerar till samma avvikelser');
sole_ok(jeab_contacts_sole_ids($store) === $beforeIds, 'sidorna skriver inga id');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$dry = jeab_contacts_sole_migrate([], $store);
sole_ok(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']), 'torrkörning skriver inget');
sole_ok(trim((string) ($store->getReminder((int) $createRow['id'])['contact_id'] ?? '')) === '', 'torrkörning sätter inget contact_id');
sole_ok(jeab_contacts_sole_ids($store) === $beforeIds, 'torrkörning behåller samma id');
$dryDeviations = is_array($dry['deviations'] ?? null) ? $dry['deviations'] : [];
$dryBroken = false;
foreach ($dryDeviations as $deviation) {
    if (is_array($deviation) && (string) ($deviation['contact_id'] ?? '') === 'kt_saknas' && (string) ($deviation['reason'] ?? '') === 'saknad') {
        $dryBroken = true;
    }
}
sole_ok($dryBroken, 'torrkörningen listar den trasiga länken');
sole_ok((int) ($dry['created'] ?? 0) >= 1 && (int) ($dry['linked'] ?? 0) >= 1, 'torrkörningen räknar träffar och nya kontakter');
$early = jeab_contacts_sole_verify($store);
sole_ok(empty($early['ok']) && (string) ($early['error'] ?? '') === 'Före-ögonblicksbilden saknas.', 'verifiering utan sparad före-bild är inte ok');

$done = jeab_contacts_sole_migrate(['dry' => 0], $store);
$encoded = json_encode($done, JSON_UNESCAPED_UNICODE);
sole_ok(is_string($encoded) && !str_contains($encoded, 'personnummer') && !str_contains($encoded, 'pnr'), 'svaret innehåller inga personnummer');
sole_ok(empty($done['ok']) && empty($done['written']), 'JSON-läge skriver inte vid dry=0');
sole_ok(str_contains((string) ($done['error'] ?? ''), 'MySQL') && str_contains((string) ($done['error'] ?? ''), 'torrt'), 'felet säger att JSON bara kan köras torrt');
sole_ok(jeab_contacts_sole_counts($store) === $before, 'antalet påminnelser, ordrar och bokningar är oförändrat');
sole_ok(jeab_contacts_sole_ids($store) === $beforeIds, 'id-listorna är oförändrade');
sole_ok(jeab_contacts_sole_cid($store->getReminder((int) $mailRow['id'])['contact_id'] ?? null) === '', 'tom contact_id lämnas tom');
sole_ok((string) ($store->getReminder((int) $keptRow['id'])['contact_id'] ?? '') === (string) $kept['id'], 'befintlig länk består');
sole_ok((string) ($store->getReminder((int) $dangling['id'])['contact_id'] ?? '') === 'kt_saknas', 'trasig länk lämnas orörd');
sole_ok((string) ($store->getReminder((int) $mailRow['id'])['updated_at'] ?? '') === $mailStamp, 'påminnelsens updated_at rörs inte');
sole_ok((string) ($store->getReminder((int) $keptRow['id'])['updated_at'] ?? '') === $keptStamp, 'länkad påminnelse behåller updated_at');
sole_ok((string) (jeab_purchase_get((int) $orderReminder['id'])['updated_at'] ?? '') === $orderStamp, 'orderns updated_at rörs inte');
sole_ok((string) ($store->getBooking((int) $booking['id'])['updated_at'] ?? '') === $bookingStamp, 'bokningens updated_at rörs inte');
sole_ok(!is_file($tmp . '/contacts_sole_undo.json'), 'ingen JSON-ångerlogg');
$undoBlocked = jeab_contacts_sole_undo(['dry' => 0], $store);
sole_ok(empty($undoBlocked['ok']) && empty($undoBlocked['written']) && str_contains((string) ($undoBlocked['error'] ?? ''), 'MySQL'), 'ånger utan MySQL skriver inte');
$still = jeab_contacts_sole_verify($store);
sole_ok(empty($still['ok']) && (string) ($still['error'] ?? '') === 'Före-ögonblicksbilden saknas.', 'verifiering utan före-bild är fortfarande inte ok');

$bookingContact = jeab_kontakt_find_or_create([
    'name' => 'Kopia Bokning',
    'email' => 'bokning.kopia@example.test',
    'phone' => '0701000008',
    'address' => 'Bokningsgatan 9, 555 66 Testby',
    'city' => 'Testby',
    'postal_code' => '55566',
], false);
sole_ok(is_array($bookingContact), 'bokningens kontakt finns för lästestet');
$store->saveReminder((int) $mailRow['id'], ['contact_id' => (string) $byMail['id']]);
$store->saveBooking((int) $booking['id'], ['contact_id' => (string) $bookingContact['id']]);
jeab_purchase_patch((int) $orderReminder['id'], static function (array $row) use ($byMail): array {
    $row['contact_id'] = (string) $byMail['id'];

    return $row;
});
$mailAfter = $store->getReminder((int) $mailRow['id']);
$orderAfter = jeab_purchase_get((int) $orderReminder['id']);
$bookingAfter = $store->getBooking((int) $booking['id']);
sole_ok((string) ($mailAfter['contact_id'] ?? '') === (string) $byMail['id'], 'lästestet pekar på mejlkontakten');
sole_ok(is_array($orderAfter) && (string) ($orderAfter['contact_id'] ?? '') === (string) $byMail['id'], 'lästestet pekar på orderns kontakt');
sole_ok(is_array($bookingAfter) && (string) ($bookingAfter['contact_id'] ?? '') === (string) $bookingContact['id'], 'lästestet pekar på bokningens kontakt');

$shown = jeab_reminder_shown_customer($mailAfter);
sole_ok($shown['name'] === 'Bo Mejl' && $shown['street'] === 'Mejlgatan 2' && $shown['city'] === 'Teststad', 'påminnelsen läser kontakten');
sole_ok(!str_contains(jeab_reminder_row($mailAfter, true), 'Kopia Mejl') && !str_contains(jeab_reminder_row($mailAfter, false), 'Felgatan'), 'påminnelsekortet visar inte kopian');
$hits = jeab_filter_reminders([$mailAfter], 'all', ['mode' => 'all', 'tag' => null], 'kopia mejl');
sole_ok($hits === [], 'sök träffar inte kopians namn');
$hits = jeab_filter_reminders([$mailAfter], 'all', ['mode' => 'all', 'tag' => null], 'bo mejl');
sole_ok(count($hits) === 1, 'sök träffar kontakten');

$store->saveBooking((int) $bookingAfter['id'], ['customer_name' => 'Annat Kopia', 'address' => 'Hemliggatan 1']);
$bookingView = $store->getBooking((int) $bookingAfter['id']);
$sum = jeab_booking_summary_html($bookingView);
sole_ok(str_contains($sum, 'Bokningsgatan 9') && !str_contains($sum, 'Annat Kopia') && !str_contains($sum, 'Hemliggatan'), 'kalendern läser kontakten');
$attrs = jeab_booking_data_attrs($bookingView);
sole_ok(str_contains($attrs, 'Kopia Bokning') && !str_contains($attrs, 'Annat Kopia'), 'bokningsformuläret läser namnet från kontakten');
sole_ok(str_contains($attrs, 'Bokningsgatan 9') && !str_contains($attrs, 'Hemliggatan'), 'bokningsformuläret läser gatan från kontakten');
sole_ok(jeab_ordrar_name($orderAfter) === 'Bo Mejl', 'orderns namn kommer från kontakten');
sole_ok(!str_contains(jeab_ordrar_board_place($orderAfter), 'Ordergatan'), 'orderns plats kommer inte från kopian');
$lines = jeab_ordrar_status_address_lines($orderAfter);
sole_ok($lines['street'] === 'Mejlgatan 2' && $lines['place'] === 'Teststad', 'orderns adressfält läser kontakten');
$box = jeab_ordrar_status_contact($orderAfter, (int) $orderReminder['id']);
sole_ok($box['email'] === 'bo.mejl@example.test' && $box['phone'] !== '0701666666', 'orderns mejl och telefon läser kontakten');

$updated = jeab_update_reminder($store, (int) $mailAfter['id'], [
    'customer' => 'Bo Nyberg',
    'email' => 'bo.nyberg@example.test',
    'phone' => '0701000099',
    'address' => 'Mejlgatan 20, 111 23 Teststad',
]);
sole_ok((string) ($updated['customer_name'] ?? '') === 'Kopia Mejl' && (string) ($updated['email'] ?? '') === 'bo.mejl@example.test', 'påminnelsens kopia skrivs inte om');
$mailContact = jeab_kontakt_get((string) $byMail['id']);
sole_ok(is_array($mailContact) && jeab_kontakt_row_name($mailContact) === 'Bo Nyberg', 'påminnelsens namn skrivs på kontakten');
sole_ok((string) ($mailContact['street'] ?? '') === 'Mejlgatan 20', 'påminnelsens gata skrivs på kontakten');

$bookingSaved = jeab_update_booking($store, (int) $bookingAfter['id'], [
    'customer' => 'Greta Bokning',
    'email' => 'greta.bokning@example.test',
    'address' => 'Bokningsgatan 90, 555 67 Testby',
]);
sole_ok((string) ($bookingSaved['customer_name'] ?? '') === 'Annat Kopia' && (string) ($bookingSaved['address'] ?? '') === 'Hemliggatan 1', 'bokningens kopia skrivs inte om');
sole_ok((string) ($bookingSaved['starts_at'] ?? '') === '2026-11-02 09:00:00', 'bokningens tid består vid kundändring');
$bookingContact = jeab_kontakt_get((string) ($bookingSaved['contact_id'] ?? ''));
sole_ok(is_array($bookingContact) && jeab_kontakt_row_name($bookingContact) === 'Greta Bokning', 'bokningens namn skrivs på kontakten');
sole_ok(str_contains(jeab_booking_summary_html($bookingSaved), 'Greta Bokning') && str_contains(jeab_booking_title($bookingSaved), 'Greta Bokning'), 'kalendern visar det nya namnet');

$orderSaved = jeab_ordrar_save_objekt([
    'id' => (int) $orderReminder['id'],
    'objekt_adress' => 'Mejlgatan 21',
    'objekt_postort' => 'Teststad',
    'objekt_postnummer' => '11124',
    'email' => 'bo.order@example.test',
    'phone' => '0701000011',
]);
$orderStored = jeab_purchase_get((int) $orderReminder['id']);
sole_ok(is_array($orderStored) && trim((string) ($orderStored['address'] ?? '')) === '' && trim((string) ($orderStored['email'] ?? '')) === '' && trim((string) ($orderStored['phone'] ?? '')) === '', 'ordern lagrar inte adress, e-post eller telefon');
sole_ok((string) ($orderStored['contact_id'] ?? '') === (string) $byMail['id'], 'orderns contact_id består efter sparning');
$orderContact = jeab_kontakt_get((string) $byMail['id']);
sole_ok(is_array($orderContact) && (string) ($orderContact['street'] ?? '') === 'Mejlgatan 21', 'orderns gata skrivs på kontakten');
sole_ok(jeab_kontakt_row_email($orderContact) === 'bo.order@example.test', 'orderns mejl skrivs på kontakten');
sole_ok((string) ($orderContact['postal_code'] ?? '') === '11124', 'orderns postnummer skrivs på kontakten');
unset($orderSaved);

$thread = ['id' => 'arende-sole', 'contact_id' => (string) $byAddress['id'], 'customer_email' => 'kopia@example.test', 'lead_customer' => ['name' => 'Kopia Panel', 'email' => 'kopia@example.test']];
$panel = jeab_inkorg_panel_contact($thread);
$place = jeab_inkorg_panel_place($thread, is_array($panel) ? $panel : null);
$panelBox = jeab_inkorg_contact_box($thread, is_array($panel) ? $panel : null, ['email' => 'kopia@example.test', 'name' => 'Kopia Panel', 'phone' => '0701000000']);
sole_ok(is_array($panel) && (string) ($panel['name'] ?? '') === 'Disa Adress', 'kundpanelen läser namnet från kontakten');
sole_ok(($place['address'] ?? '') !== '' && str_contains((string) $place['address'], 'Adressgatan 4') && !str_contains((string) $place['address'], 'Kopia'), 'kundpanelen läser adressen från kontakten');
sole_ok(in_array('disa.adress@example.test', $panelBox['emails'], true) && !in_array('kopia@example.test', $panelBox['emails'], true), 'kundpanelen läser mejlet från kontakten');

$inboxReminder = $store->insertReminder([
    'title' => 'Från inkorgen',
    'customer_name' => 'Hedda Inkorg',
    'email' => 'hedda.inkorg@example.test',
    'phone' => '0701000012',
    'address' => 'Inkorgsgatan 12',
    'ort' => 'Testhamn',
    'postal' => '55667',
    'company' => 'jarfalla',
    'due_at' => '2026-10-28 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$attached = jeab_kontakt_attach_reminder($store, $inboxReminder, [
    'name' => 'Hedda Inkorg',
    'email' => 'hedda.inkorg@example.test',
    'phone' => '0701000012',
    'address' => 'Inkorgsgatan 12',
]);
sole_ok(is_array($attached) && (string) ($attached['city'] ?? '') === 'Testhamn' && (string) ($attached['postal_code'] ?? '') === '55667', 'inkorgen sätter ort och postnummer på kontakten');
$inboxStored = $store->getReminder((int) $inboxReminder['id']);
sole_ok(trim((string) ($inboxStored['contact_id'] ?? '')) === (string) ($attached['id'] ?? ''), 'inkorgspåminnelsen pekar på kontakten');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
sole_ok(jeab_reminder_shown_customer($store->getReminder((int) $mailRow['id']))['name'] === 'Bo Nyberg', 'flaggan av igen använder kontakten när den redan är länkad');
sole_ok(jeab_ordrar_name(jeab_purchase_get((int) $closedReminder['id'])) === 'Kopia Stängd', 'flaggan av igen visar orderns egen kopia');

sole_ok(is_array($store->getReminder((int) $createRow['id'])) && is_array(jeab_purchase_get((int) $orderReminder['id'])) && is_array($store->getBooking((int) $booking['id'])), 'påminnelse, order och bokning finns kvar');
sole_ok(!is_file($tmp . '/contacts_sole_undo.json'), 'ångerloggen skapas inte som JSON');

echo "contacts sole ok\n";
