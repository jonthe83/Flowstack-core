<?php

declare(strict_types=1);

/**
 * Kalenderns bokning läser namn, e-post, telefon och adress från gemensamma kontakter.
 * Påhittade personer. Inga personnummer och ingen live-data.
 */

$tmp = sys_get_temp_dir() . '/jeab-booking-contact-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('CONTACTS_SOLE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);

function bc_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
bc_ok($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contact_id_columns($store->pdo(), 'sqlite');
$now = jeab_now();

$ulf = jeab_kontakt_find_or_create([
    'name' => 'Ulf Delad',
    'email' => 'ulf.delad@example.test',
    'phone' => '0701000041',
    'address' => 'Deladgatan 8, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Tva',
    'email' => 'ada.tva@example.test',
    'phone' => '0701000043',
    'address' => 'Tvagatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$bo = jeab_kontakt_find_or_create([
    'name' => 'Bo Tva',
    'email' => 'bo.tva@example.test',
    'phone' => '0701000044',
    'address' => 'Tvabogatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$caj = jeab_kontakt_find_or_create([
    'name' => 'Caj Unik',
    'email' => 'caj.unik@example.test',
    'phone' => '0701000045',
    'address' => 'Unikgatan 3, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$rita = jeab_kontakt_find_or_create([
    'name' => 'Rita Paminnelse',
    'email' => 'rita.paminnelse@example.test',
    'phone' => '0701000046',
    'address' => 'Paminnelsegatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$greta = jeab_kontakt_find_or_create([
    'name' => 'Greta Kopplad',
    'email' => 'greta.kopplad@example.test',
    'phone' => '0701000047',
    'address' => 'Koppladgatan 5, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$sameA = jeab_kontakt_find_or_create([
    'name' => 'Samma Namn',
    'email' => 'samma.a@example.test',
    'phone' => '0701000048',
    'address' => 'Sammaagatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$sameB = jeab_kontakt_find_or_create([
    'name' => 'Samma Namn',
    'email' => 'samma.b@example.test',
    'phone' => '0701000049',
    'address' => 'Sammabgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$shareA = jeab_kontakt_find_or_create([
    'name' => 'Delad Mejl A',
    'email' => 'delad.a@example.test',
    'phone' => '0701000051',
    'address' => 'Deladagatan 1',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$shareB = jeab_kontakt_find_or_create([
    'name' => 'Delad Mejl B',
    'email' => 'delad.b@example.test',
    'phone' => '0701000052',
    'address' => 'Deladbgatan 2',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
bc_ok(is_array($ulf) && is_array($ada) && is_array($bo) && is_array($caj) && is_array($rita) && is_array($greta), 'kontakterna finns');
bc_ok(is_array($sameA) && is_array($sameB) && (string) $sameA['id'] !== (string) $sameB['id'], 'samma namn är två kontakter');
bc_ok(is_array($shareA) && is_array($shareB), 'två kontakter finns före det delade mejlet');

$stub = jeab_kontakt_blank(['name' => 'Tom Stub', 'source' => 'booking_contact_resolve_test']);
$stubId = (string) $stub['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($stub, $stubId, $shareA, $shareB): void {
    $contacts[$stubId] = $stub;
    foreach ([$shareA, $shareB] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) $row['id'];
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            continue;
        }
        $owners = is_array($contacts[$id]['owners'] ?? null) ? $contacts[$id]['owners'] : [];
        if (isset($owners[0]) && is_array($owners[0])) {
            $owners[0]['email'] = 'delad.mejl@example.test';
            $contacts[$id]['owners'] = $owners;
        }
    }
});
jeab_contacts_sole_cache_forget(null);
$stubRow = jeab_kontakt_get($stubId);
bc_ok(is_array($stubRow) && jeab_kontakt_row_email($stubRow) === '' && trim((string) ($stubRow['street'] ?? '')) === '', 'namnkontakten saknar mejl och gata');

$unflagged = jeab_kontakt_blank([
    'name' => 'Eva Utan',
    'email' => 'eva.utan@example.test',
    'phone' => '0701000050',
    'street' => 'Utvagen 6',
    'city' => 'Teststad',
    'postal_code' => '11122',
]);
$unflagged['owners'][0]['contact'] = false;
bc_ok(jeab_kontakt_row_email($unflagged) === 'eva.utan@example.test', 'mejl utan contact-flagga följer med');
bc_ok(jeab_kontakt_row_phone($unflagged) === jeab_kontakt_phone('0701000050'), 'telefon utan contact-flagga följer med');
$flaggedEmpty = $unflagged;
$flaggedEmpty['owners'] = [
    ['name' => 'Eva Tom', 'contact' => true, 'email' => '', 'phone' => ''],
    ['name' => 'Eva Annan', 'contact' => false, 'email' => 'eva.annan@example.test', 'phone' => '0701000053'],
];
bc_ok(jeab_kontakt_row_email($flaggedEmpty) === '' && jeab_kontakt_row_phone($flaggedEmpty) === '', 'flaggad tom kontaktperson vinner över den andra ägaren');

$addressOnly = jeab_kontakt_blank(['name' => 'Ada Adressfalt']);
$addressOnly['street'] = '';
$addressOnly['address'] = 'Adressgatan 4, 222 33 Testort';
$addressView = jeab_contacts_sole_view_from_contact($addressOnly);
bc_ok(str_contains((string) $addressView['street'], 'Adressgatan 4') && str_contains((string) $addressView['display'], 'Adressgatan 4'), 'adressfält används när gatan saknas');

$customer = jeab_find_or_create_customer($store, 'Kalender Kund');
$insert = static function (string $name, ?string $email, ?string $phone, ?string $address, ?string $contactId) use ($store, $customer, $now): array {
    return $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'notes' => 'anteckningen består',
        'assignees' => ['Sam L'],
        'company' => 'jarfalla',
        'job_type' => 'batteri',
        'starts_at' => '2026-11-11 07:00:00',
        'ends_at' => '2026-11-11 16:00:00',
        'status' => 'booked',
        'contact_id' => $contactId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
};

$hollow = $insert('Tom Stub', null, null, null, $stubId);
$linked = $insert('Annat Kopia', null, null, null, (string) $greta['id']);
$byReminder = $insert('Utan Länk', null, null, null, null);
$split = $insert('Kvar Kopia', 'kvar.kopia@example.test', '0701000060', 'Kvarvagen 1', null);
$byMail = $insert('Fel Kopia', 'caj.unik@example.test', '0701999999', 'Felgatan 1', null);
$byName = $insert('Samma Namn', null, null, null, null);
$ambiguous = $insert('Otydlig Kopia', 'delad.mejl@example.test', '0701000045', 'Otydliggatan 1', null);
bc_ok((string) ($store->getBooking((int) $hollow['id'])['contact_id'] ?? '') === $stubId, 'insert sparar contact_id');

$reminder = static function (string $title, int $bookingId, string $contactId) use ($store, $now): array {
    $row = $store->insertReminder([
        'title' => $title,
        'customer_name' => $title,
        'company' => 'jarfalla',
        'due_at' => '2026-11-01 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $store->pdo()->prepare('UPDATE reminders SET booking_id = ?, contact_id = ? WHERE id = ?')->execute([
        $bookingId,
        $contactId,
        (int) $row['id'],
    ]);

    return $store->getReminder((int) $row['id']);
};
$ritaReminder = $reminder('Rita', (int) $byReminder['id'], (string) $rita['id']);
$adaReminder = $reminder('Ada', (int) $split['id'], (string) $ada['id']);
bc_ok((string) ($ritaReminder['contact_id'] ?? '') === (string) $rita['id'], 'påminnelsen pekar på Rita');
bc_ok((int) ($adaReminder['booking_id'] ?? 0) === (int) $split['id'], 'påminnelsen pekar på bokningen');

$orderReminder = $store->insertReminder([
    'title' => 'Order Ulf',
    'customer_name' => 'Order Ulf',
    'company' => 'jarfalla',
    'due_at' => '2026-11-01 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$splitOrderReminder = $store->insertReminder([
    'title' => 'Order Split',
    'customer_name' => 'Order Split',
    'company' => 'jarfalla',
    'due_at' => '2026-11-01 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'booking_id' => (int) $hollow['id'],
    'contact_id' => (string) $ulf['id'],
    'kind' => 'batteri',
    'name' => 'Ulf Delad',
    'email' => '',
    'phone' => '',
    'address' => '',
    'created_at' => $now,
    'updated_at' => $now,
]);
jeab_purchase_put([
    'reminder_id' => (int) $splitOrderReminder['id'],
    'booking_id' => (int) $split['id'],
    'contact_id' => (string) $bo['id'],
    'kind' => 'batteri',
    'name' => 'Bo Tva',
    'created_at' => $now,
    'updated_at' => $now,
]);
unset($GLOBALS['_JEAB_BOOKING_SIBLING_MAP'], $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_INDEX'], $GLOBALS['_JEAB_CONTACTS_SOLE_CHANNEL_HITS']);

$beforeContacts = count(jeab_kontakter_load());
$hollowStored = $store->getBooking((int) $hollow['id']);
$hollowPaint = jeab_contacts_sole_paint_booking($hollowStored);
$attrs = jeab_booking_data_attrs($hollowStored);
bc_ok(str_contains($attrs, 'data-customer="Ulf Delad"'), 'formuläret visar kontaktens namn');
bc_ok(str_contains($attrs, 'data-email="ulf.delad@example.test"'), 'formuläret visar kontaktens e-post');
bc_ok(!str_contains($attrs, 'data-phone=""'), 'formuläret visar telefon');
bc_ok(str_contains($attrs, 'Deladgatan 8'), 'formuläret visar adressen');
bc_ok(str_contains($attrs, 'data-notes="anteckningen består"'), 'anteckningen är bokningens egen');
bc_ok((string) ($store->getBooking((int) $hollow['id'])['contact_id'] ?? '') === $stubId, 'visningen skriver inte om contact_id');
bc_ok(count(jeab_kontakter_load()) === $beforeContacts, 'visningen skapar ingen kontakt');
bc_ok((string) ($hollowPaint['email'] ?? '') === 'ulf.delad@example.test' && empty($hollowPaint['contact_link_missing']), 'paint läser orderns kontakt');

$linkedAttrs = jeab_booking_data_attrs($store->getBooking((int) $linked['id']));
bc_ok(str_contains($linkedAttrs, 'data-email="greta.kopplad@example.test"') && str_contains($linkedAttrs, 'Koppladgatan 5'), 'länkad bokning utan kopior visar kontakten');

$ritaAttrs = jeab_booking_data_attrs($store->getBooking((int) $byReminder['id']));
bc_ok(str_contains($ritaAttrs, 'data-email="rita.paminnelse@example.test"') && str_contains($ritaAttrs, 'Paminnelsegatan 4'), 'påminnelsens kontakt följer med när bokningen saknar länk');

$splitPaint = jeab_contacts_sole_paint_booking($store->getBooking((int) $split['id']));
bc_ok(!empty($splitPaint['contact_link_missing']) && (string) ($splitPaint['email'] ?? '') === 'kvar.kopia@example.test' && (string) ($splitPaint['address'] ?? '') === 'Kvarvagen 1', 'två olika syskon väljs inte');
bc_ok(!str_contains((string) ($splitPaint['email'] ?? ''), 'ada.tva') && !str_contains((string) ($splitPaint['email'] ?? ''), 'bo.tva'), 'inget av syskonens mejl gissas');

$mailPaint = jeab_contacts_sole_paint_booking($store->getBooking((int) $byMail['id']));
bc_ok(empty($mailPaint['contact_link_missing']) && str_contains((string) ($mailPaint['address'] ?? ''), 'Unikgatan 3') && !str_contains((string) ($mailPaint['address'] ?? ''), 'Felgatan'), 'unikt mejl visar den kontakten');
bc_ok((string) ($store->getBooking((int) $byMail['id'])['contact_id'] ?? '') === '', 'mejlträffen skriver inte vid visning');

$namePaint = jeab_contacts_sole_paint_booking($store->getBooking((int) $byName['id']));
bc_ok(!empty($namePaint['contact_link_missing']) && trim((string) ($namePaint['email'] ?? '')) === '' && (string) ($namePaint['customer_name'] ?? '') === 'Samma Namn', 'bara namn kopplar ingen kontakt');

$ambPaint = jeab_contacts_sole_paint_booking($store->getBooking((int) $ambiguous['id']));
bc_ok(!empty($ambPaint['contact_link_missing']) && (string) ($ambPaint['address'] ?? '') === 'Otydliggatan 1' && !str_contains((string) ($ambPaint['address'] ?? ''), 'Unikgatan'), 'två kontakter med samma mejl väljs inte');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$off = jeab_contacts_sole_paint_booking($store->getBooking((int) $linked['id']));
bc_ok(!array_key_exists('contact_link_missing', $off) && trim((string) ($off['email'] ?? '')) === '', 'flaggan av lämnar bokningens tomma kopia');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$saved = jeab_update_booking($store, (int) $hollow['id'], [
    'customer' => 'Ulf Delad',
    'assignees' => ['Sam L'],
    'company' => 'jarfalla',
    'starts_at' => '2026-11-11 07:00:00',
    'ends_at' => '2026-11-11 16:00:00',
    'job_type' => 'batteri',
    'email' => 'ulf.ny@example.test',
    'phone' => '0701000042',
    'address' => 'Deladgatan 9, 111 22 Teststad',
    'notes' => 'anteckningen består',
]);
bc_ok((string) ($saved['contact_id'] ?? '') === (string) $ulf['id'], 'Spara pekar om bokningen till orderns kontakt');
bc_ok(trim((string) ($saved['email'] ?? '')) === '' && (string) ($saved['notes'] ?? '') === 'anteckningen består', 'bokningens kopia och anteckning skrivs inte om');
$ulfAfter = jeab_kontakt_get((string) $ulf['id']);
$stubAfter = jeab_kontakt_get($stubId);
bc_ok(is_array($ulfAfter) && jeab_kontakt_row_email($ulfAfter) === 'ulf.ny@example.test', 'ändringen skrivs på den gemensamma kontakten');
bc_ok(str_contains((string) ($ulfAfter['street'] ?? ''), 'Deladgatan 9'), 'gatan skrivs på den gemensamma kontakten');
bc_ok(is_array($stubAfter) && jeab_kontakt_row_email($stubAfter) === '' && (string) ($stubAfter['id'] ?? '') === $stubId, 'namnkontakten finns kvar och slås inte ihop');
$afterSave = jeab_booking_data_attrs($store->getBooking((int) $hollow['id']));
bc_ok(str_contains($afterSave, 'data-email="ulf.ny@example.test"') && str_contains($afterSave, 'Deladgatan 9'), 'formuläret visar det som sparades på kontakten');

$mailSaved = jeab_update_booking($store, (int) $byMail['id'], [
    'customer' => 'Caj Unik',
    'assignees' => ['Sam L'],
    'company' => 'jarfalla',
    'starts_at' => '2026-11-11 07:00:00',
    'ends_at' => '2026-11-11 16:00:00',
    'job_type' => 'batteri',
    'email' => 'caj.unik@example.test',
    'phone' => '0701000045',
    'address' => 'Unikgatan 3, 111 22 Teststad',
    'notes' => 'anteckningen består',
]);
bc_ok((string) ($mailSaved['contact_id'] ?? '') === (string) $caj['id'], 'Spara länkar unikt mejl utan att gissa namn');
bc_ok((string) ($store->getBooking((int) $byName['id'])['contact_id'] ?? '') === '', 'namnträffen länkas inte vid en annan sparning');

bc_ok(jeab_ordrar_booking_inherit_contact_id(['contact_id' => 'kt_order'], ['contact_id' => 'kt_paminnelse']) === 'kt_order', 'orderns kontakt ärvs före påminnelsens');
bc_ok(jeab_ordrar_booking_inherit_contact_id(['contact_id' => ''], ['contact_id' => 'kt_paminnelse']) === 'kt_paminnelse', 'påminnelsens kontakt ärvs när ordern saknar länk');
bc_ok(jeab_ordrar_booking_inherit_contact_id([], null) === '', 'utan länk ärvs inget');

$contactsBeforeCreate = count(jeab_kontakter_load());
$created = jeab_create_booking($store, 'Ulf Delad', ['Sam L'], '2026-11-12 07:00:00', '2026-11-12 16:00:00', 'batteri', [
    'company' => 'jarfalla',
    'assignees' => ['Sam L'],
    'contact_id' => (string) $ulf['id'],
    'email' => '',
    'phone' => '',
    'address' => '',
    'notes' => 'från ordern',
]);
bc_ok((string) ($created['contact_id'] ?? '') === (string) $ulf['id'], 'ny bokning får orderns contact_id');
bc_ok(count(jeab_kontakter_load()) === $contactsBeforeCreate, 'ny bokning med redan satt kontakt skapar ingen namnkontakt');
$bare = jeab_create_booking($store, 'Bara Namn', ['Sam L'], '2026-11-13 07:00:00', '2026-11-13 16:00:00', 'batteri', [
    'company' => 'jarfalla',
    'assignees' => ['Sam L'],
]);
bc_ok(trim((string) ($bare['contact_id'] ?? '')) === '', 'bokning med bara namn får ingen kontakt');
bc_ok(count(jeab_kontakter_load()) === $contactsBeforeCreate, 'bokning med bara namn skapar ingen kontakt');
bc_ok(jeab_contacts_sole_booking_link_skips_create(['email' => '', 'phone' => '', 'street_key' => '']) === true, 'namn utan mejl, telefon och gata skapar ingen kontakt');
bc_ok(jeab_contacts_sole_booking_link_skips_create(['email' => 'ny@example.test', 'phone' => '', 'street_key' => '']) === false, 'mejl får fortfarande kopplas');

echo "booking contact resolve ok\n";
