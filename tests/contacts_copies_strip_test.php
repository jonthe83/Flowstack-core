<?php

declare(strict_types=1);

/**
 * contacts.copies_strip. Järfälla och Malmö. Påhittade namn.
 * Bara påminnelser och bokningar med contact_id töms. Rader utan länk behåller
 * sina fält. Bokningstider, contact_id och antal rader står kvar.
 * Ångerfilen har bara de rensade fälten och inget personnummer.
 * Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-copies-strip-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
$errLog = $tmp . '/php-error.log';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_STRICT']);
putenv('JEAB_WEBHOOK_CAPTURE=1');
$GLOBALS['_JEAB_WEBHOOKS'] = [];
ini_set('log_errors', '1');
ini_set('error_log', $errLog);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-copies-token',
    'outgoing_webhook_url' => '',
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-copies-strip']));
require dirname(__DIR__) . '/src/bootstrap.php';
putenv('JEAB_PNR_HMAC_KEY=copies-strip-test-key');

function cc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/src/contacts_copies.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
$snapSrc = (string) file_get_contents($root . '/src/snapshots.php');
cc(str_contains($hookSrc, "'contacts.copies_strip'") && str_contains($hookSrc, "'contacts.copies_strip_undo'"), 'hook.php tar båda åtgärderna');
cc(str_contains($dispatchSrc, "=== 'contacts.copies_strip'") && str_contains($dispatchSrc, 'jeab_contacts_copies_strip('), 'dispatch skickar tömning');
cc(str_contains($dispatchSrc, 'jeab_contacts_copies_strip_undo('), 'dispatch skickar undo');
cc(str_contains($src, 'contacts-copies-strip') && str_contains($src, '.private') && str_contains($src, '0600'), 'ångerfilen ligger under .private och är 0600');
cc(str_contains($src, '14 * 86400'), 'ångerfiler äldre än 14 dagar tas bort');
cc(str_contains($src, "array_key_exists('dry'"), 'dry saknas är torr körning');
cc(!preg_match('/\b(UPDATE|INSERT|DELETE|FROM)\s+(snapshots|rot)\b/i', $src), 'hooken skriver inte snapshots eller rot');
cc(str_contains($snapSrc, 'function jeab_snapshots_store('), 'snapshots.php är orörd i den här körningen');
require_once $root . '/src/contacts_copies.php';
cc(jeab_contacts_copies_dry([]) === true, 'utelämnad dry är torr');
cc(jeab_contacts_copies_dry(['dry' => '0x']) === true, '0x är torr');
cc(jeab_contacts_copies_dry(['dry' => '[]']) === true, '[] är torr');
cc(jeab_contacts_copies_dry(['dry' => '']) === true, 'tom dry är torr');
cc(jeab_contacts_copies_dry(['dry' => 0]) === false, 'dry=0 skriver');

$store = jeab_store();
cc($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$pnr = '199001011239';
$pnr10 = '9001011239';
cc(jeab_contacts_copies_has_pnr($pnr10) && jeab_contacts_copies_has_pnr($pnr) && jeab_contacts_copies_has_pnr('900101-1239') && jeab_contacts_copies_has_pnr('8112189876'), 'giltig kontrollsiffra är personnummer');
cc(!jeab_contacts_copies_has_pnr('9001011238') && !jeab_contacts_copies_has_pnr('199001011238'), 'ogiltig kontrollsiffra är inte personnummer');
cc(!jeab_contacts_copies_has_pnr('0701234569') && !jeab_contacts_copies_has_pnr('070123-4569') && !jeab_contacts_copies_has_pnr('0701000001'), 'mobilnummer är inte personnummer');
cc(str_contains($src, "require_once __DIR__ . '/normalize.php'") && str_contains($src, "hash_hkdf('sha256', \$secret, 32, 'jeab-contacts-copies-seal-v1')"), 'luhn laddas och nyckeln härleds med hkdf');
$GLOBALS['_JEAB_COPIES_LUHN_OFF'] = true;
cc(jeab_contacts_copies_has_pnr('9001011238') && jeab_contacts_copies_has_pnr('0701234569') && jeab_contacts_copies_has_pnr('199001011238'), 'saknad luhn behandlar 10- och 12-siffriga som personnummer');
unset($GLOBALS['_JEAB_COPIES_LUHN_OFF']);
cc(!jeab_contacts_copies_has_pnr('9001011238') && !jeab_contacts_copies_has_pnr('0701234569'), 'luhn är på igen');
$stamp = '2026-01-01 00:00:00';

$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Järfälla',
    'email' => 'ada.jarfalla@example.test',
    'phone' => '0701000001',
    'address' => 'Fallgatan 1, 177 31 Järfälla',
    'city' => 'Järfälla',
    'postal_code' => '17731',
    'company' => 'jarfalla',
], false, false);
$bo = jeab_kontakt_find_or_create([
    'name' => 'Bo Malmö',
    'email' => 'bo.malmo@example.test',
    'phone' => '0701000002',
    'address' => 'Limhamnsgatan 2, 216 32 Malmö',
    'city' => 'Malmö',
    'postal_code' => '21632',
    'company' => 'malmo',
], false, false);
cc(is_array($ada) && is_array($bo), 'båda kontorens kontakter finns');
$adaId = (string) $ada['id'];
$boId = (string) $bo['id'];
cc($adaId !== '' && $boId !== '' && $adaId !== $boId, 'kontakterna är olika');

$adaCustomer = $store->insertCustomer('Legacy Järfälla', $stamp);
$boCustomer = $store->insertCustomer('Legacy Malmö', $stamp);
$looseCustomer = $store->insertCustomer('Utan Länk', $stamp);

$remJar = $store->insertReminder([
    'title' => 'Järfälla påminnelse',
    'customer_name' => 'Legacy Järfälla',
    'email' => 'legacy.jarfalla@example.test',
    'phone' => $pnr,
    'address' => '9001011239',
    'ort' => 'Järfälla',
    'postal' => '17731',
    'company' => 'jarfalla',
    'contact_id' => $adaId,
    'note' => 'jarfalla-behall',
    'due_at' => '2026-11-02 09:00:00',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$remMal = $store->insertReminder([
    'title' => 'Malmö påminnelse',
    'customer_name' => 'Legacy Malmö',
    'email' => 'legacy.malmo@example.test',
    'phone' => '0702000002',
    'address' => 'Limhamnsgatan 9',
    'ort' => 'Malmö',
    'postal' => '21632',
    'company' => 'malmo',
    'contact_id' => $boId,
    'note' => 'malmo-behall',
    'due_at' => '2026-11-05 09:00:00',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$remLoose = $store->insertReminder([
    'title' => 'Utan kontakt',
    'customer_name' => 'Utan Länk',
    'email' => 'utan@example.test',
    'phone' => '0703000003',
    'address' => 'Ensamgatan 1',
    'company' => 'jarfalla',
    'note' => 'utan-behall',
    'due_at' => '2026-11-02 10:00:00',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$remEmpty = $store->insertReminder([
    'title' => 'Redan tom',
    'customer_name' => null,
    'email' => null,
    'phone' => null,
    'address' => null,
    'company' => 'malmo',
    'contact_id' => $boId,
    'note' => 'tom-behall',
    'due_at' => '2026-11-05 11:00:00',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$bookJar = $store->insertBooking([
    'customer_id' => $adaCustomer['id'],
    'customer_name' => 'Legacy Järfälla',
    'email' => 'bok.jarfalla@example.test',
    'phone' => '0701000001',
    'address' => 'Fallgatan 9',
    'notes' => 'tid-behall',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'contact_id' => $adaId,
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 11:00:00',
    'status' => 'booked',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$bookMal = $store->insertBooking([
    'customer_id' => $boCustomer['id'],
    'customer_name' => 'Legacy Malmö',
    'email' => 'bok.malmo@example.test',
    'phone' => '0702000002',
    'address' => 'Limhamnsgatan 9',
    'notes' => 'tid-behall-malmo',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'contact_id' => $boId,
    'starts_at' => '2026-11-05 08:00:00',
    'ends_at' => '2026-11-05 12:00:00',
    'status' => 'booked',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$bookLoose = $store->insertBooking([
    'customer_id' => $looseCustomer['id'],
    'customer_name' => 'Utan Länk',
    'email' => 'bok.utan@example.test',
    'phone' => '0703000003',
    'address' => 'Ensamgatan 1',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'laddbox',
    'starts_at' => '2026-11-02 13:00:00',
    'ends_at' => '2026-11-02 14:00:00',
    'status' => 'booked',
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
cc((int) ($remJar['id'] ?? 0) > 0 && (int) ($bookMal['id'] ?? 0) > 0, 'legacyrader finns för båda kontoren');

$refusedStrip = jeab_contacts_copies_strip(['dry' => 0]);
cc(empty($refusedStrip['ok']) && empty($refusedStrip['written']), 'dry=0 vägrar när strict är av');
$dryOff = jeab_contacts_copies_strip([]);
cc(!empty($dryOff['ok']) && ($dryOff['dry'] ?? false) === true && ($dryOff['reminders'] ?? -1) === 0 && ($dryOff['bookings'] ?? -1) === 0 && empty($dryOff['written']), 'torr körning när strict är av skriver inget');
$refusedUndo = jeab_contacts_copies_strip_undo(['batch_id' => '20260101010101-abcdef', 'dry' => 0]);
cc(empty($refusedUndo['ok']) && empty($refusedUndo['written']) && str_contains((string) ($refusedUndo['error'] ?? ''), 'enda källan'), 'undo vägrar när strict är av');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$refusedSole = jeab_contacts_copies_strip(['dry' => 0]);
$refusedSoleUndo = jeab_contacts_copies_strip_undo(['batch_id' => '20260101010101-abcdef', 'dry' => 1]);
cc(empty($refusedSole['ok']) && empty($refusedSole['written']) && empty($refusedSoleUndo['ok']) && empty($refusedSoleUndo['written']), 'dry=0 och undo vägrar när sole är av');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
cc((string) ($store->getReminder((int) $remJar['id'])['customer_name'] ?? '') === 'Legacy Järfälla' && (string) ($store->getBooking((int) $bookMal['id'])['customer_name'] ?? '') === 'Legacy Malmö', 'vägrad tömning lämnar båda kontorens kopior');

$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
cc(jeab_contacts_sole() && jeab_contacts_strict(), 'båda flaggorna är på');

$madeJar = jeab_create_reminder($store, 'Ny Järfälla', '2026-11-02 15:00:00', 'not-jar', null, [
    'customer' => 'Ny Järfälla',
    'email' => 'ny.jarfalla@example.test',
    'phone' => '0701000091',
    'address' => 'Nyfallgatan 1',
    'ort' => 'Järfälla',
    'postal' => '17731',
    'contact_id' => $adaId,
    'company' => 'jarfalla',
    'test' => true,
]);
$madeMal = jeab_create_reminder($store, 'Ny Malmö', '2026-11-05 15:00:00', 'not-mal', null, [
    'customer' => 'Ny Malmö',
    'email' => 'ny.malmo@example.test',
    'phone' => '0701000092',
    'address' => 'Nygatan 2',
    'ort' => 'Malmö',
    'postal' => '21632',
    'contact_id' => $boId,
    'company' => 'malmo',
    'test' => true,
]);
cc((string) ($madeJar['contact_id'] ?? '') === $adaId && trim((string) ($madeJar['customer_name'] ?? '')) === '' && trim((string) ($madeJar['email'] ?? '')) === '' && trim((string) ($madeJar['phone'] ?? '')) === '' && trim((string) ($madeJar['address'] ?? '')) === '', 'ny Järfälla-påminnelse sparar bara contact_id');
cc((string) ($madeMal['contact_id'] ?? '') === $boId && trim((string) ($madeMal['customer_name'] ?? '')) === '' && trim((string) ($madeMal['postal'] ?? '')) === '', 'ny Malmö-påminnelse sparar bara contact_id');
$shownJar = jeab_reminder_shown_customer($madeJar);
$shownMal = jeab_reminder_shown_customer($madeMal);
cc(($shownJar['name'] ?? '') === 'Ada Järfälla' && ($shownJar['email'] ?? '') === 'ada.jarfalla@example.test', 'Järfälla-visningen läser kontakten');
cc(($shownMal['name'] ?? '') === 'Bo Malmö' && ($shownMal['city'] ?? '') === 'Malmö', 'Malmö-visningen läser kontakten');

$newBookJar = jeab_create_booking($store, 'Ny Järfälla', ['Elin'], '2026-11-02 09:00:00', '2026-11-02 10:00:00', 'laddbox', [
    'company' => 'jarfalla',
    'contact_id' => $adaId,
    'email' => 'ny.bok.jarfalla@example.test',
    'phone' => '0701000091',
    'address' => 'Nyfallgatan 3',
    'test' => true,
], false);
$newBookMal = jeab_create_booking($store, 'Ny Malmö', ['Kim'], '2026-11-05 09:00:00', '2026-11-05 10:00:00', 'laddbox', [
    'company' => 'malmo',
    'contact_id' => $boId,
    'email' => 'ny.bok.malmo@example.test',
    'phone' => '0701000092',
    'address' => 'Nygatan 4',
    'test' => true,
], false);
cc((string) ($newBookJar['contact_id'] ?? '') === $adaId && trim((string) ($newBookJar['customer_name'] ?? '')) === '' && trim((string) ($newBookJar['email'] ?? '')) === '' && trim((string) ($newBookJar['address'] ?? '')) === '', 'ny Järfälla-bokning sparar bara contact_id');
cc((string) ($newBookMal['contact_id'] ?? '') === $boId && trim((string) ($newBookMal['customer_name'] ?? '')) === '' && (string) ($newBookMal['starts_at'] ?? '') !== '' && (string) ($newBookMal['ends_at'] ?? '') !== '', 'ny Malmö-bokning sparar bara contact_id och har tider');
$names = [];
foreach ($store->listCustomers() as $customer) {
    $names[] = (string) ($customer['name'] ?? '');
}
cc(!in_array('Ny Järfälla', $names, true) && !in_array('Ny Malmö', $names, true), 'customers.name får inte de nya namnen');
$paintJar = jeab_contacts_sole_paint_booking($newBookJar);
$paintMal = jeab_contacts_sole_paint_booking($newBookMal);
cc((string) ($paintJar['customer_name'] ?? '') === 'Ada Järfälla' && (string) ($paintMal['email'] ?? '') === 'bo.malmo@example.test', 'bokningsvisningen läser kontakten');
$monthJar = jeab_month_booking_label([
    'contact_id' => $adaId,
    'customer_name' => '',
    'address' => '',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 11:00:00',
    'job_type' => 'laddbox',
    'company' => 'jarfalla',
], false);
$monthMal = jeab_month_place_name([
    'contact_id' => $boId,
    'customer_name' => 'Fel Kopia',
    'address' => 'Felgatan 1',
    'company' => 'malmo',
]);
cc(str_contains($monthJar, 'Ada'), 'månadsvyn visar Järfälla-kontakten');
cc(str_contains($monthMal, 'Limhamn') && !str_contains($monthMal, 'Felgatan'), 'månadsvyn visar Malmö-adressen från kontakten');
$helloJar = jeab_rebook_greeting_name(['contact_id' => $adaId, 'customer_name' => '', 'company' => 'jarfalla']);
$helloMal = jeab_rebook_greeting_name(['contact_id' => $boId, 'customer_name' => 'Gammalt Namn', 'company' => 'malmo']);
cc($helloJar === 'Ada' && $helloMal === 'Bo', 'ombokningsmejlet hälsar med kontaktens förnamn');
$gpRow = [
    'id' => 0,
    'contact_id' => $adaId,
    'address' => 'Gammal gata 1',
    'phone' => '0709999999',
    'email' => 'kopia@example.test',
    'customer_name' => '',
];
$visit = jeab_gp_visit_address($gpRow);
$gpPhone = jeab_gp_event_phone($gpRow, ['phone' => '0708888888'], ['phone' => '0707777777'], ['phone' => '0706666666']);
$gpEmail = jeab_gp_event_email($gpRow, ['email' => 'shown@example.test'], ['email' => 'order@example.test'], ['email' => 'rem@example.test']);
cc(str_contains($visit, 'Fallgatan') && !str_contains($visit, 'Gammal'), 'Outlook-adress läser kontakten först');
cc(str_contains($gpPhone, '0701000001') || str_contains($gpPhone, '46701000001'), 'Outlook-telefon läser kontakten först');
cc($gpEmail === 'ada.jarfalla@example.test', 'Outlook-mejl läser kontakten först');
$bestallBefore = count($GLOBALS['_JEAB_WEBHOOKS'] ?? []);
$bestall = jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', [
    'reminder_id' => 88099,
    'kind' => 'sol',
    'company' => 'malmo',
    'address' => 'Felgatan 9, 111 22 Solna',
    'gata' => 'Felgatan 9',
    'postnummer' => '11122',
    'ort' => 'Solna',
    'phone' => '0709999999',
    'mobil' => '0709999999',
    'onskad_leverans' => '2026-11-02',
    'contact_id' => $boId,
], false);
cc(is_array($bestall) && ($bestall['leveransadress'] ?? '') === 'Felgatan 9' && ($bestall['address'] ?? '') === 'Felgatan 9, 111 22 Solna' && !str_contains((string) ($bestall['leveransadress'] ?? ''), 'Limhamn'), 'orderadress går före kontakten');
$gap = jeab_bokmalen_ordrar_webhook_payload('ordrar.bestall', [
    'reminder_id' => 88100,
    'kind' => 'sol',
    'company' => 'malmo',
    'address' => '',
    'gata' => '',
    'postnummer' => '',
    'ort' => '',
    'phone' => '',
    'mobil' => '',
    'onskad_leverans' => '2026-11-02',
    'contact_id' => $boId,
], false);
cc(is_array($gap) && ($gap['leveransadress'] ?? '') === 'Limhamnsgatan 2' && str_contains((string) ($gap['telefon'] ?? ''), '46701000002'), 'kontakten fyller adress och telefon när ordern saknar dem');
cc(!str_contains((string) json_encode($bestall, JSON_UNESCAPED_UNICODE), $pnr) && count($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === $bestallBefore, 'beställningsläsningen skickar inget och har inget personnummer');

$from = jeab_reminder_from_booking($store, $bookJar);
cc((int) ($from['booking_id'] ?? 0) === (int) $bookJar['id'] && (string) ($from['contact_id'] ?? '') === $adaId && trim((string) ($from['customer_name'] ?? '')) === '' && trim((string) ($from['email'] ?? '')) === '', 'påminnelse från bokning tar contact_id och inte kundkopian');
cc(is_array($store->getBooking((int) $bookJar['id'])), 'bokningen finns kvar');

function cc_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}

$remindersBefore = cc_count($pdo, 'reminders');
$bookingsBefore = cc_count($pdo, 'bookings');
$purchasesBefore = cc_count($pdo, 'purchases');
$jarTimes = [(string) $bookJar['starts_at'], (string) $bookJar['ends_at'], (string) $bookJar['updated_at']];
$malTimes = [(string) $bookMal['starts_at'], (string) $bookMal['ends_at'], (string) $bookMal['updated_at']];

function cc_clean(string $json, string $pnr): bool
{
    return !str_contains($json, $pnr)
        && !str_contains($json, '9001011239')
        && !str_contains(strtolower($json), 'personnummer')
        && !str_contains($json, 'Legacy Järfälla')
        && !str_contains($json, 'Legacy Malmö')
        && !str_contains($json, 'legacy.jarfalla@example.test')
        && !str_contains($json, 'bok.malmo@example.test')
        && !str_contains($json, 'Utan Länk')
        && !str_contains($json, 'Fallgatan')
        && !str_contains($json, 'Limhamnsgatan');
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$hooksBefore = count($GLOBALS['_JEAB_WEBHOOKS'] ?? []);
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.copies_strip', 'dry' => 0]);
cc(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
cc((string) ($store->getReminder((int) $remJar['id'])['customer_name'] ?? '') === 'Legacy Järfälla', 'nekad körning skriver inget');
cc(!is_dir($tmp . '/.private/contacts-copies-strip'), 'nekad körning skriver ingen ångerfil');

$undoDir = $tmp . '/.private/contacts-copies-strip';
mkdir($undoDir, 0700, true);
$oldUndo = $undoDir . '/20200101010101-abcdef.json';
file_put_contents($oldUndo, '{"rows":[]}');
touch($oldUndo, time() - 15 * 86400);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-copies-strip';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.copies_strip']);
cc(!is_file($oldUndo), 'ångerfiler äldre än 14 dagar tas bort');
cc(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false && ($dry['batch_id'] ?? 'x') === '', 'utelämnad dry skriver inte');
cc(($dry['reminders'] ?? -1) === 2 && ($dry['bookings'] ?? -1) === 2, 'torrkörningen räknar länkade Järfälla- och Malmö-rader');
$dryIds = is_array($dry['ids'] ?? null) ? $dry['ids'] : [];
sort($dryIds);
$expectIds = [
    'bookings:' . (int) $bookJar['id'],
    'bookings:' . (int) $bookMal['id'],
    'reminders:' . (int) $remJar['id'],
    'reminders:' . (int) $remMal['id'],
];
sort($expectIds);
cc($dryIds === $expectIds, 'tomma och olänkade rader utelämnas');
cc(glob($undoDir . '/*.json') === [], 'torrkörningen skriver ingen ångerfil');
cc((string) ($store->getReminder((int) $remLoose['id'])['customer_name'] ?? '') === 'Utan Länk', 'olänkad påminnelse behåller namnet');
cc(cc_clean((string) json_encode($dry, JSON_UNESCAPED_UNICODE), $pnr), 'dry-svaret saknar kundfält och personnummer');
cc(count($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === $hooksBefore, 'torrkörningen skickar inget');

$port = 18180 + random_int(0, 800);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($public, true) . ";\n\$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;\n\$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$log = $tmp . '/php-server.log';
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root), escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
cc(is_resource($proc), 'php -S startar');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
cc($up, 'php -S lyssnar');
$curl = static function (string $body, string $token) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Webhook-Token: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'raw' => is_string($raw) ? $raw : '', 'json' => json_decode(is_string($raw) ? $raw : '', true)];
};
$curlDenied = $curl('{"action":"contacts.copies_strip","dry":0}', '');
cc($curlDenied['status'] === 401 && is_array($curlDenied['json']) && empty($curlDenied['json']['ok']), 'curl utan token blir 401');
$curlDry = $curl('{"action":"contacts.copies_strip"}', 'tok-copies-strip');
cc($curlDry['status'] === 200 && is_array($curlDry['json']) && ($curlDry['json']['action'] ?? '') === 'contacts.copies_strip' && ($curlDry['json']['dry'] ?? false) === true && ($curlDry['json']['written'] ?? true) === false, 'curl med token får torr körning');
cc(cc_clean($curlDry['raw'], $pnr) && ($curlDry['json']['reminders'] ?? -1) === 2 && ($curlDry['json']['bookings'] ?? -1) === 2, 'curl-svaret har antal och saknar kundfält');
cc((string) ($store->getReminder((int) $remMal['id'])['email'] ?? '') === 'legacy.malmo@example.test', 'curl skriver inga fält');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

$wet = jeab_contacts_sole_dispatch(['action' => 'contacts.copies_strip', 'dry' => 0]);
cc(!empty($wet['ok']) && ($wet['dry'] ?? true) === false && !empty($wet['written']), 'dry=0 skriver');
$batch = (string) ($wet['batch_id'] ?? '');
cc(preg_match('/^[0-9]{14}-[a-f0-9]{6}$/', $batch) === 1, 'batch_id är satt');
$undoPath = $undoDir . '/' . $batch . '.json';
cc(is_file($undoPath), 'ångerfilen ligger under .private/contacts-copies-strip');
cc((fileperms($undoPath) & 0777) === 0600, 'ångerfilen är 0600');
$undoRaw = (string) file_get_contents($undoPath);
cc(!str_contains($undoRaw, $pnr) && !str_contains($undoRaw, $pnr10) && !str_contains(strtolower($undoRaw), 'personnummer') && str_contains($undoRaw, '"sealed"'), 'ångerfilen har personnummer bara krypterat');
cc(str_contains($undoRaw, 'Legacy Järfälla') && str_contains($undoRaw, 'Legacy Malmö') && str_contains($undoRaw, 'legacy.malmo@example.test') && !str_contains($undoRaw, 'jarfalla-behall') && !str_contains($undoRaw, 'tid-behall'), 'ångerfilen har bara de tömda kundfälten');
cc(cc_clean((string) json_encode($wet, JSON_UNESCAPED_UNICODE), $pnr), 'svaret saknar kundfält och personnummer');
cc(cc_count($pdo, 'reminders') === $remindersBefore && cc_count($pdo, 'bookings') === $bookingsBefore && cc_count($pdo, 'purchases') === $purchasesBefore, 'ingen rad försvinner');
$clearedJar = $store->getReminder((int) $remJar['id']);
$clearedMal = $store->getReminder((int) $remMal['id']);
cc(is_array($clearedJar) && trim((string) ($clearedJar['customer_name'] ?? '')) === '' && trim((string) ($clearedJar['phone'] ?? '')) === '' && trim((string) ($clearedJar['email'] ?? '')) === '' && trim((string) ($clearedJar['address'] ?? '')) === '' && trim((string) ($clearedJar['ort'] ?? '')) === '', 'Järfälla-påminnelsen töms');
cc((string) ($clearedJar['contact_id'] ?? '') === $adaId && (string) ($clearedJar['note'] ?? '') === 'jarfalla-behall', 'Järfälla behåller länk och anteckning');
cc(is_array($clearedMal) && trim((string) ($clearedMal['customer_name'] ?? '')) === '' && (string) ($clearedMal['contact_id'] ?? '') === $boId && (string) ($clearedMal['note'] ?? '') === 'malmo-behall', 'Malmö-påminnelsen töms och behåller länken');
cc((string) ($store->getReminder((int) $remLoose['id'])['customer_name'] ?? '') === 'Utan Länk' && (string) ($store->getReminder((int) $remEmpty['id'])['note'] ?? '') === 'tom-behall', 'olänkad och redan tom rad rörs inte');
$freshJar = $store->getBooking((int) $bookJar['id']);
$freshMal = $store->getBooking((int) $bookMal['id']);
cc(is_array($freshJar) && trim((string) ($freshJar['customer_name'] ?? '')) === '' && trim((string) ($freshJar['email'] ?? '')) === '' && (string) ($freshJar['contact_id'] ?? '') === $adaId, 'Järfälla-bokningen töms och behåller länken');
cc([$freshJar['starts_at'], $freshJar['ends_at'], $freshJar['updated_at']] === $jarTimes, 'Järfällas bokningstider står kvar');
cc(is_array($freshMal) && trim((string) ($freshMal['address'] ?? '')) === '' && (string) ($freshMal['contact_id'] ?? '') === $boId, 'Malmö-bokningen töms och behåller länken');
cc([$freshMal['starts_at'], $freshMal['ends_at'], $freshMal['updated_at']] === $malTimes, 'Malmös bokningstider står kvar');
$quietWas = $GLOBALS['_JEAB_QUIET_BOOKING'] ?? null;
$GLOBALS['_JEAB_QUIET_BOOKING'] = true;
$hooksMove = count($GLOBALS['_JEAB_WEBHOOKS'] ?? []);
$moved = jeab_update_booking($store, (int) $bookJar['id'], [
    'starts_at' => '2026-11-03 10:00:00',
    'ends_at' => '2026-11-03 12:00:00',
    'notes' => 'flyttad-jarfalla',
]);
$edited = jeab_update_booking($store, (int) $bookMal['id'], [
    'notes' => 'redigerad-malmo',
]);
if ($quietWas === null) {
    unset($GLOBALS['_JEAB_QUIET_BOOKING']);
} else {
    $GLOBALS['_JEAB_QUIET_BOOKING'] = $quietWas;
}
cc((string) ($moved['starts_at'] ?? '') === '2026-11-03 10:00:00' && (string) ($moved['ends_at'] ?? '') === '2026-11-03 12:00:00' && (string) ($moved['contact_id'] ?? '') === $adaId && trim((string) ($moved['customer_name'] ?? '')) === '' && (string) ($moved['notes'] ?? '') === 'flyttad-jarfalla', 'tömd länkad Järfälla-bokning kan flyttas');
cc((string) ($edited['notes'] ?? '') === 'redigerad-malmo' && (string) ($edited['starts_at'] ?? '') === $malTimes[0] && (string) ($edited['ends_at'] ?? '') === $malTimes[1] && (string) ($edited['contact_id'] ?? '') === $boId && trim((string) ($edited['customer_name'] ?? '')) === '', 'tömd länkad Malmö-bokning kan redigeras');
cc(count($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === $hooksMove, 'flytt och redigering skickar inget');
cc((string) ($store->getBooking((int) $bookLoose['id'])['customer_name'] ?? '') === 'Utan Länk', 'olänkad bokning behåller namnet');
$stillLegacy = $pdo->prepare('SELECT name FROM customers WHERE id = ?');
$stillLegacy->execute([(int) $adaCustomer['id']]);
cc((string) $stillLegacy->fetchColumn() === 'Legacy Järfälla', 'customers.name på den gamla raden rörs inte');
cc(count($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === $hooksBefore, 'tömningen skickar inget');
$errText = is_file($errLog) ? (string) file_get_contents($errLog) : '';
cc(!str_contains($errText, $pnr) && !str_contains($errText, $pnr10), 'loggen har inget personnummer');

$missing = jeab_contacts_sole_dispatch(['action' => 'contacts.copies_strip_undo', 'dry' => 0]);
cc(empty($missing['ok']) && str_contains((string) ($missing['error'] ?? ''), 'batch_id'), 'undo utan batch_id skrivs inte');
$undoDry = jeab_contacts_sole_dispatch(['action' => 'contacts.copies_strip_undo', 'batch_id' => $batch]);
cc(!empty($undoDry['ok']) && ($undoDry['dry'] ?? false) === true && empty($undoDry['written']), 'undo utan dry skriver inte');
$afterDry = $store->getReminder((int) $remJar['id']);
cc(is_array($afterDry) && trim((string) ($afterDry['customer_name'] ?? '')) === '', 'torr undo lämnar den tömda raden');
cc(cc_clean((string) json_encode($undoDry, JSON_UNESCAPED_UNICODE), $pnr), 'undo-svaret saknar kundfält och personnummer');

$pdo->prepare('UPDATE reminders SET email = ? WHERE id = ?')->execute(['annan@example.test', (int) $remMal['id']]);
$undoWet = jeab_contacts_sole_dispatch(['action' => 'contacts.copies_strip_undo', 'batch_id' => $batch, 'dry' => 0]);
cc(!empty($undoWet['ok']) && !empty($undoWet['written']) && ($undoWet['skipped'] ?? 0) >= 1, 'undo dry=0 återställer oförändrade rader och hoppar över en ändrad');
$restoredJar = $store->getReminder((int) $remJar['id']);
cc((string) ($restoredJar['customer_name'] ?? '') === 'Legacy Järfälla' && (string) ($restoredJar['phone'] ?? '') === $pnr && (string) ($restoredJar['address'] ?? '') === $pnr10 && (string) ($restoredJar['contact_id'] ?? '') === $adaId && (string) ($restoredJar['note'] ?? '') === 'jarfalla-behall', 'Järfälla fick tillbaka namnet och personnumret');
$restoredMal = $store->getReminder((int) $remMal['id']);
cc(is_array($restoredMal) && (string) ($restoredMal['email'] ?? '') === 'annan@example.test' && trim((string) ($restoredMal['customer_name'] ?? '')) === '', 'ändrad Malmö-rad hoppas över');
$restoredBook = $store->getBooking((int) $bookMal['id']);
cc((string) ($restoredBook['customer_name'] ?? '') === 'Legacy Malmö' && [$restoredBook['starts_at'], $restoredBook['ends_at']] === [$malTimes[0], $malTimes[1]] && (string) ($restoredBook['contact_id'] ?? '') === $boId, 'Malmö-bokningen fick tillbaka namnet och tiden står kvar');
cc((string) ($store->getReminder((int) $remLoose['id'])['customer_name'] ?? '') === 'Utan Länk', 'undo rör inte olänkad rad');
cc(cc_count($pdo, 'reminders') === $remindersBefore && cc_count($pdo, 'bookings') === $bookingsBefore, 'undo tar inte bort rader');
cc(cc_clean((string) json_encode($undoWet, JSON_UNESCAPED_UNICODE), $pnr), 'våt undo-svar saknar kundfält och personnummer');
cc(!str_contains(is_file($errLog) ? (string) file_get_contents($errLog) : '', $pnr) && !str_contains(is_file($errLog) ? (string) file_get_contents($errLog) : '', $pnr10), 'loggen har fortfarande inget personnummer');
$filesBefore = glob($undoDir . '/*.json') ?: [];
sort($filesBefore);
$GLOBALS['_JEAB_COPIES_CHMOD'] = static fn (string $path, int $mode): bool => false;
$blocked = jeab_contacts_copies_strip(['dry' => 0]);
unset($GLOBALS['_JEAB_COPIES_CHMOD']);
$filesAfter = glob($undoDir . '/*.json') ?: [];
sort($filesAfter);
cc(empty($blocked['ok']) && empty($blocked['written']) && str_contains((string) ($blocked['error'] ?? ''), 'skyddas'), 'chmod-fel avbryter tömningen');
cc((string) ($store->getReminder((int) $remJar['id'])['customer_name'] ?? '') === 'Legacy Järfälla' && (string) ($store->getReminder((int) $remJar['id'])['phone'] ?? '') === $pnr, 'avbruten tömning lämnar Järfälla');
cc($filesAfter === $filesBefore, 'misslyckad chmod lämnar ingen ny ångerfil');
cc(!is_file($undoDir . '/.key'), 'ångerkatalogen har ingen nyckel');
putenv('JEAB_PNR_HMAC_KEY');
$noKey = jeab_contacts_copies_strip(['dry' => 0]);
cc(empty($noKey['ok']) && empty($noKey['written']) && str_contains((string) ($noKey['error'] ?? ''), 'Nyckel'), 'dry=0 vägrar när nyckeln saknas');
cc((string) ($store->getReminder((int) $remJar['id'])['customer_name'] ?? '') === 'Legacy Järfälla' && !is_file($undoDir . '/.key'), 'saknad nyckel skriver inget och skapar ingen nyckel');
putenv('JEAB_PNR_HMAC_KEY=copies-strip-test-key');
$badBatch = '20260101010102-abcdef';
file_put_contents($undoDir . '/' . $badBatch . '.json', json_encode([
    'batch_id' => $badBatch,
    'rows' => [[
        'table' => 'reminders',
        'id' => (int) $remJar['id'],
        'contact_id' => $adaId,
        'fields' => ['phone' => ['sealed' => 'v1:dead']],
    ]],
], JSON_UNESCAPED_UNICODE));
$logBefore = is_file($errLog) ? (string) file_get_contents($errLog) : '';
$badUndo = jeab_contacts_copies_strip_undo(['batch_id' => $badBatch, 'dry' => 0]);
$logAfter = is_file($errLog) ? (string) file_get_contents($errLog) : '';
$logNew = substr($logAfter, strlen($logBefore));
cc(empty($badUndo['written']) && str_contains($logNew, 'contacts.copies_strip_undo sealed reminders ' . (int) $remJar['id'] . ' phone'), 'undo loggar tabell, id och fält när sealed inte går att öppna');
cc(!str_contains($logNew, $pnr) && !str_contains($logNew, $pnr10) && !str_contains($logNew, 'v1:dead'), 'undo-loggen har inga värden');
cc((string) ($store->getReminder((int) $remJar['id'])['phone'] ?? '') === $pnr, 'oläsligt sealed lämnar telefonen');
cc(count($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === $hooksBefore, 'undo skickar inget');

echo "contacts_copies_strip_test klar\n";
