<?php

declare(strict_types=1);

/**
 * Kontakter är enda källan för ordrar och fakturering.
 * Live-flaggor: STEG4_CUTOVER=1, CONTACTS_SOLE=1, LEGACY_JSON_WRITES=0.
 * Tomt kontaktfält visas tomt och loggas en gång, utan värdet och utan personnummer.
 * Sparning med flaggan på lagrar bara contact_id för namn, e-post, telefon och adress.
 * Äldre rader läses som de ligger. Fakturering läser kontakten.
 * Länkar och antal består. Bokning och påminnelse behåller sin kopia när kontakten är tom.
 * Påhittade rader. Inga riktiga personnummer. Ingen Fortnox.
 */

$tmp = sys_get_temp_dir() . '/jeab-sole-order-fakt-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_LEGACY_JSON_WRITES'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('STEG4_CUTOVER=1');
putenv('CONTACTS_SOLE=1');
putenv('LEGACY_JSON_WRITES=0');
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
$_POST = [];

function of(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $row */
function of_save_contact(array $row): void
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

function of_log(): string
{
    $path = $GLOBALS['_JEAB_FELLOGG_DIR'] . '/app-errors.jsonl';
    if (!is_file($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

function of_log_lines(): array
{
    $raw = trim(of_log());
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => trim($line) !== ''));
}

$store = jeab_store();
of($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);
of(jeab_flag('STEG4_CUTOVER') === true, 'STEG4_CUTOVER är på');
of(jeab_flag('CONTACTS_SOLE') === true, 'CONTACTS_SOLE är på');
of(jeab_flag('LEGACY_JSON_WRITES') === false, 'LEGACY_JSON_WRITES är av');
of(jeab_contacts_sole() === true, 'jeab_contacts_sole läser den påslagna flaggan');

$madeEmpty = jeab_kontakt_find_or_create([
    'name' => 'Tom Kontakt',
    'email' => 'tom.kontakt@example.test',
    'phone' => '0701000201',
    'address' => 'Tomgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
of(is_array($madeEmpty) && trim((string) ($madeEmpty['id'] ?? '')) !== '', 'den tomma kontakten finns');
$emptyId = (string) $madeEmpty['id'];
$emptyContact = jeab_kontakt_get($emptyId);
of(is_array($emptyContact), 'den tomma kontakten kan läsas');
$emptyContact['street'] = '';
$emptyContact['city'] = '';
$emptyContact['postal_code'] = '';
$emptyContact['address'] = '';
$emptyContact['street_key'] = '';
$owners = is_array($emptyContact['owners'] ?? null) ? $emptyContact['owners'] : [];
foreach ($owners as $i => $owner) {
    if (!is_array($owner)) {
        continue;
    }
    $owner['name'] = '';
    $owner['email'] = '';
    $owner['phone'] = '';
    $owner['personnummer'] = '';
    $owners[$i] = $owner;
}
$emptyContact['owners'] = $owners;
of_save_contact($emptyContact);
$emptyView = jeab_contacts_sole_view_from_contact(jeab_kontakt_get($emptyId) ?: []);
of($emptyView['name'] === '' && $emptyView['email'] === '' && $emptyView['phone'] === '' && $emptyView['street'] === '', 'kontakten har inga kundfält');

$madeFull = jeab_kontakt_find_or_create([
    'name' => 'Fia Kontakt',
    'email' => 'fia.kontakt@example.test',
    'phone' => '0701000202',
    'address' => 'Kontaktgatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
of(is_array($madeFull) && trim((string) ($madeFull['id'] ?? '')) !== '', 'Fia Kontakt finns');
$fullId = (string) $madeFull['id'];
$fullContact = jeab_kontakt_get($fullId);
of(is_array($fullContact), 'Fia Kontakt kan läsas');
$fullContact['street'] = 'Kontaktgatan 4';
$fullContact['city'] = 'Teststad';
$fullContact['postal_code'] = '11122';
of_save_contact($fullContact);
$fullPhone = jeab_kontakt_row_phone(jeab_kontakt_get($fullId) ?: []);
of($fullPhone !== '', 'Fia har telefon');

$fakePnr = '901340-0001';
$gapOrder = [
    'reminder_id' => 92001,
    'contact_id' => $emptyId,
    'name' => 'Kopia Lucka',
    'huvudsokande' => 'Kopia Lucka',
    'customer_name' => 'Kopia Lucka',
    'email' => 'kopia.lucka@example.test',
    'phone' => '0701000888',
    'address' => 'Luckgatan 9',
    'huvudsokande_address' => 'Luckgatan 9',
    'gata' => 'Luckgatan 9',
    'ort' => 'Teststad',
    'postnummer' => '99999',
    'postnr' => '999 99',
    'personnummer' => $fakePnr,
    'fortnox_order' => 'FX-92001',
    'applicant' => [
        'name' => 'Kopia Lucka',
        'email' => 'kopia.lucka@example.test',
        'phone' => '0701000888',
        'personnummer' => $fakePnr,
    ],
    'kind' => 'laddbox',
    'company' => 'jarfalla',
];

unset($GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG']);
$painted = jeab_contacts_sole_paint_order($gapOrder);
of($painted['contact_id'] === $emptyId, 'paint lämnar contact_id');
of(empty($painted['contact_link_missing']), 'tom kontakt är länkad');
of($painted['name'] === '' && $painted['huvudsokande'] === '' && $painted['customer_name'] === '', 'tomt namn visas tomt');
of($painted['email'] === '' && $painted['phone'] === '', 'tom e-post och telefon visas tomma');
of($painted['address'] === '' && $painted['gata'] === '' && $painted['postnr'] === '' && $painted['ort'] === '', 'tom adress visas tom');
of(($painted['applicant']['name'] ?? 'x') === '' && ($painted['applicant']['email'] ?? 'x') === '', 'sökandens kopia visas tom');
of((string) ($painted['applicant']['personnummer'] ?? '') !== '' || (string) ($painted['personnummer'] ?? '') !== '', 'paint rör inte personnummer');
of((string) ($painted['fortnox_order'] ?? '') === 'FX-92001', 'paint rör inte Fortnox-ordern');
of(jeab_ordrar_name($gapOrder) === 'Kopia Lucka', 'ordernamnet faller tillbaka när kontakten saknar namn');
$customer = jeab_ordrar_fakturering_customer($gapOrder);
of($customer['name'] === '' && $customer['email'] === '' && $customer['phone'] === '' && $customer['address'] === '', 'fakturering visar tomma kundfält');

$lines = of_log_lines();
of(count($lines) === 4, 'en fellogg-rad per namn, e-post, telefon och adress');
$fields = [];
foreach ($lines as $line) {
    of(str_contains($line, 'contacts_sole'), 'raden är contacts_sole');
    of(str_contains($line, '92001'), 'raden pekar på ordern');
    of(!str_contains($line, 'Kopia Lucka'), 'loggen innehåller inte namnet');
    of(!str_contains($line, 'kopia.lucka@example.test'), 'loggen innehåller inte e-posten');
    of(!str_contains($line, '0701000888'), 'loggen innehåller inte telefonen');
    of(!str_contains($line, 'Luckgatan'), 'loggen innehåller inte gatan');
    of(!str_contains($line, '901340'), 'loggen innehåller inte personnumret');
    of(!str_contains($line, '19901340'), 'loggen innehåller inte ett utökat personnummer');
    of(!str_contains($line, 'personnummer') && !preg_match('/\bpnr\b/i', $line), 'loggen nämner inte personnummer');
    if (preg_match('/fält (name|email|phone|address) /', $line, $m) === 1) {
        $fields[$m[1]] = true;
    }
}
of(isset($fields['name'], $fields['email'], $fields['phone'], $fields['address']), 'alla fyra fält loggas');

$again = jeab_contacts_sole_paint_order($gapOrder);
of($again['email'] === '' && $again['gata'] === '', 'andra visningen är också tom');
of(count(of_log_lines()) === 4, 'samma lucka loggas inte igen');

$unlinked = $gapOrder;
$unlinked['reminder_id'] = 92002;
$unlinked['contact_id'] = '';
$unlinkedPaint = jeab_contacts_sole_paint_order($unlinked);
of($unlinkedPaint['name'] === 'Kopia Lucka' && $unlinkedPaint['email'] === 'kopia.lucka@example.test' && $unlinkedPaint['gata'] === 'Luckgatan 9', 'order utan länk behåller kopian');
of(count(of_log_lines()) === 4, 'olänkad order loggas inte');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$offPaint = jeab_contacts_sole_paint_order($gapOrder);
of($offPaint['name'] === 'Kopia Lucka' && $offPaint['email'] === 'kopia.lucka@example.test' && $offPaint['address'] === 'Luckgatan 9', 'flaggan av visar kopian');
of(count(of_log_lines()) === 4, 'flaggan av skriver ingen fellogg');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$now = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Order',
    'company' => 'jarfalla',
    'customer_name' => 'Kopia Fia',
    'email' => 'kopia.fia@example.test',
    'phone' => '0701000777',
    'address' => 'Kopiagatan 2',
    'due_at' => '2026-11-02 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$reminderId = (int) ($reminder['id'] ?? 0);
of($reminderId > 0, 'påminnelsen finns');
of(jeab_kontakt_stamp_reminder($store, $reminderId, $fullId), 'påminnelsen länkas');
$customerRow = jeab_find_or_create_customer($store, 'Kopia Bokning');
$booking = $store->insertBooking([
    'customer_id' => $customerRow['id'],
    'customer_name' => 'Kopia Bokning',
    'email' => 'kopia.bokning@example.test',
    'phone' => '0701000666',
    'address' => 'Bokningsgatan 3',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-03 09:00:00',
    'ends_at' => '2026-11-03 10:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingId = (int) ($booking['id'] ?? 0);
of($bookingId > 0, 'bokningen finns');
of(jeab_kontakt_stamp_booking($store, $bookingId, $fullId), 'bokningen länkas');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$legacy = jeab_purchase_put([
    'reminder_id' => $reminderId,
    'contact_id' => $fullId,
    'name' => 'Kopia Fia',
    'huvudsokande' => 'Kopia Fia',
    'customer_name' => 'Kopia Fia',
    'email' => 'kopia.fia@example.test',
    'phone' => '0701000777',
    'address' => 'Kopiagatan 2',
    'huvudsokande_address' => 'Kopiagatan 2',
    'gata' => 'Kopiagatan 2',
    'ort' => 'Teststad',
    'postnummer' => '88888',
    'postnr' => '888 88',
    'personnummer' => $fakePnr,
    'fortnox_order' => 'FX-LEGACY',
    'applicant' => ['name' => 'Kopia Fia', 'email' => 'kopia.fia@example.test', 'phone' => '0701000777', 'personnummer' => $fakePnr],
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
of((string) ($legacy['email'] ?? '') === 'kopia.fia@example.test', 'flaggan av sparar kopian');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$storedLegacy = jeab_purchase_get($reminderId);
of(is_array($storedLegacy) && (string) ($storedLegacy['email'] ?? '') === 'kopia.fia@example.test', 'äldre rad läses som den ligger');
of((string) ($storedLegacy['contact_id'] ?? '') === $fullId, 'äldre rad behåller contact_id');
$shown = jeab_ordrar_display_row($storedLegacy);
of($shown['email'] === 'fia.kontakt@example.test' && $shown['name'] === 'Fia Kontakt' && $shown['gata'] === 'Kontaktgatan 4', 'visning läser kontakten');
of(!str_contains((string) $shown['email'], 'kopia.fia'), 'visning faller inte tillbaka till kopian');
$legacyCustomer = jeab_ordrar_fakturering_customer($storedLegacy);
of($legacyCustomer['email'] === 'fia.kontakt@example.test' && $legacyCustomer['name'] === 'Fia Kontakt' && str_contains($legacyCustomer['address'], 'Kontaktgatan 4'), 'fakturering läser kontakten på en äldre rad');
$html = jeab_ordrar_fakturering_html($storedLegacy, 'jarfalla', $reminderId);
of(str_contains($html, 'data-sole-email="fia.kontakt@example.test"'), 'faktureringssidan har kontaktens e-post');
of(str_contains($html, 'data-sole-name="Fia Kontakt"'), 'faktureringssidan har kontaktens namn');
of(str_contains($html, 'data-sole-phone="' . $fullPhone . '"'), 'faktureringssidan har kontaktens telefon');
of(str_contains($html, 'data-sole-address="') && str_contains($html, 'Kontaktgatan 4'), 'faktureringssidan har kontaktens adress');
of(!str_contains($html, 'kopia.fia@example.test') && !str_contains($html, 'Kopiagatan'), 'faktureringssidan visar inte orderns kopia');
$v3 = jeab_ordrar_v3_faktura_html($storedLegacy, 'jarfalla', $reminderId, ['state' => 'open', 'n' => 8]);
of(str_contains($v3, 'data-sole-email="fia.kontakt@example.test"'), 'v3-fakturering har kontaktens e-post');
of(!str_contains($v3, 'kopia.fia@example.test'), 'v3-fakturering visar inte orderns kopia');

$beforeCounts = jeab_contacts_sole_counts($store);
$beforeIds = jeab_contacts_sole_ids($store);
$beforeReminderLink = (string) ($store->getReminder($reminderId)['contact_id'] ?? '');
$beforeBookingLink = (string) ($store->getBooking($bookingId)['contact_id'] ?? '');

$stripped = jeab_purchase_put([
    'reminder_id' => $reminderId,
    'contact_id' => $fullId,
    'name' => 'Kopia Fia',
    'huvudsokande' => 'Kopia Fia',
    'customer_name' => 'Kopia Fia',
    'email' => 'kopia.fia@example.test',
    'phone' => '0701000777',
    'address' => 'Kopiagatan 2',
    'gata' => 'Kopiagatan 2',
    'ort' => 'Teststad',
    'postnummer' => '88888',
    'personnummer' => $fakePnr,
    'fortnox_order' => 'FX-LEGACY',
    'applicant' => ['name' => 'Kopia Fia', 'email' => 'kopia.fia@example.test', 'phone' => '0701000777', 'personnummer' => $fakePnr],
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'updated_at' => $now,
]);
foreach (['name', 'huvudsokande', 'customer_name', 'email', 'phone', 'address', 'gata', 'ort', 'postnummer', 'postnr'] as $key) {
    of(!isset($stripped[$key]) || trim((string) $stripped[$key]) === '', 'sparad order saknar ' . $key);
}
of((string) ($stripped['contact_id'] ?? '') === $fullId, 'sparningen behåller contact_id');
of(trim((string) ($stripped['personnummer'] ?? '')) !== '', 'sparningen behåller personnummer');
of((string) ($stripped['fortnox_order'] ?? '') === 'FX-LEGACY', 'sparningen behåller Fortnox-ordern');
of(trim((string) ($stripped['applicant']['personnummer'] ?? '')) !== '', 'sökandens personnummer består');
of(!isset($stripped['applicant']['email']) || trim((string) $stripped['applicant']['email']) === '', 'sökandens e-post skrivs inte');
$disk = jeab_purchase_get($reminderId);
of(is_array($disk), 'ordern kan läsas efter sparning');
of((string) ($disk['contact_id'] ?? '') === $fullId, 'lagrad contact_id består');
of(trim((string) ($disk['email'] ?? '')) === '' && trim((string) ($disk['phone'] ?? '')) === '' && trim((string) ($disk['name'] ?? '')) === '', 'lagringen har ingen kundkopia');
of(trim((string) ($disk['personnummer'] ?? '')) !== '' && (string) ($disk['fortnox_order'] ?? '') === 'FX-LEGACY', 'personnummer och Fortnox ligger kvar på disken');
$afterPaint = jeab_ordrar_display_row($disk);
of($afterPaint['email'] === 'fia.kontakt@example.test' && $afterPaint['phone'] === $fullPhone, 'läsning efter sparning går via kontakten');
$midCounts = jeab_contacts_sole_counts($store);
$midIds = jeab_contacts_sole_ids($store);
of($midCounts === $beforeCounts, 'antal påminnelser, ordrar och bokningar är oförändrat efter sparningen');
of($midIds === $beforeIds, 'id-listorna är oförändrade efter sparningen');
of((string) ($store->getReminder($reminderId)['contact_id'] ?? '') === $beforeReminderLink && $beforeReminderLink === $fullId, 'påminnelsens länk består genom sparningen');
of((string) ($store->getBooking($bookingId)['contact_id'] ?? '') === $beforeBookingLink && $beforeBookingLink === $fullId, 'bokningens länk består genom sparningen');
of((string) ($disk['contact_id'] ?? '') === $fullId, 'orderns länk består genom sparningen');

$looseReminder = $store->insertReminder([
    'title' => 'Olänkad',
    'company' => 'jarfalla',
    'customer_name' => 'Olänkad Kopia',
    'email' => 'olankad.kopia@example.test',
    'due_at' => '2026-11-04 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$looseId = (int) ($looseReminder['id'] ?? 0);
$loose = jeab_purchase_put([
    'reminder_id' => $looseId,
    'contact_id' => '',
    'name' => 'Olänkad Kopia',
    'email' => 'olankad.kopia@example.test',
    'phone' => '0701000555',
    'address' => 'Olankadgatan 1',
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
]);
of((string) ($loose['email'] ?? '') === 'olankad.kopia@example.test' && (string) ($loose['address'] ?? '') === 'Olankadgatan 1', 'olänkad order behåller kopian');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$offSaved = jeab_purchase_put([
    'reminder_id' => $looseId,
    'contact_id' => $fullId,
    'name' => 'Av Kopia',
    'email' => 'av.kopia@example.test',
    'phone' => '0701000444',
    'address' => 'Avgatan 8',
    'kind' => 'laddbox',
    'updated_at' => $now,
]);
of((string) ($offSaved['email'] ?? '') === 'av.kopia@example.test' && (string) ($offSaved['contact_id'] ?? '') === $fullId, 'flaggan av skriver kopian även med länk');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$relinked = jeab_purchase_get($looseId);
of(is_array($relinked) && (string) ($relinked['email'] ?? '') === 'av.kopia@example.test', 'raden som sparades avstängd läses med kopian');
$relinkedShown = jeab_ordrar_display_row($relinked);
of($relinkedShown['email'] === 'fia.kontakt@example.test', 'samma rad visas från kontakten när flaggan är på');

of((string) ($store->getReminder($reminderId)['contact_id'] ?? '') === $fullId, 'påminnelsens länk består');
of((string) ($store->getBooking($bookingId)['contact_id'] ?? '') === $fullId, 'bokningens länk består');
of((string) ($store->getReminder($looseId)['contact_id'] ?? '') === '', 'den olänkade påminnelsen fick ingen länk');
of((string) ($store->getReminder($reminderId)['customer_name'] ?? '') === 'Kopia Fia', 'påminnelsens egen kopia rörs inte');
of((string) ($store->getBooking($bookingId)['customer_name'] ?? '') === 'Kopia Bokning', 'bokningens egen kopia rörs inte');

$bookingPaint = jeab_contacts_sole_paint_booking([
    'id' => $bookingId,
    'contact_id' => $emptyId,
    'customer_name' => 'Kopia Bokning',
    'email' => 'kopia.bokning@example.test',
    'phone' => '0701000666',
    'address' => 'Bokningsgatan 3',
]);
of($bookingPaint['customer_name'] === 'Kopia Bokning' && $bookingPaint['email'] === 'kopia.bokning@example.test', 'bokning behåller kopian när kontakten är tom');
$reminderPaint = jeab_contacts_sole_paint_reminder([
    'id' => $reminderId,
    'contact_id' => $emptyId,
    'customer_name' => 'Kopia Fia',
    'email' => 'kopia.fia@example.test',
    'phone' => '0701000777',
    'address' => 'Kopiagatan 2',
]);
of($reminderPaint['customer_name'] === 'Kopia Fia' && $reminderPaint['email'] === 'kopia.fia@example.test', 'påminnelse behåller kopian när kontakten är tom');

echo "contacts_sole_order_fakturering_test klar\n";
