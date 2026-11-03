<?php

declare(strict_types=1);

/**
 * CONTACTS_SOLE: påminnelser dedupas på contact_id och produkt.
 * Samma telefon på två kontakter slås inte ihop. Länken till order och bokning består.
 * Ortcache-JSON skrivs bara när legacy JSON inte är avstängd för biz_docs.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-contact-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
putenv('CONTACTS_SOLE=1');
putenv('STEG4_CUTOVER=1');
putenv('LEGACY_JSON_WRITES=0');
$_ENV['CONTACTS_SOLE'] = '1';
$_ENV['STEG4_CUTOVER'] = '1';
$_ENV['LEGACY_JSON_WRITES'] = '0';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_LEGACY_JSON_WRITES'] = false;

function rc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rc_insert(PDO $pdo, string $contactId, string $title, string $phone, string $email, ?string $completedAt = null, ?string $deletedAt = null): int
{
    $now = jeab_now();
    $pdo->prepare(
        'INSERT INTO reminders (list_id, title, customer_name, email, phone, contact_id, completed_at, deleted_at, created_at, updated_at)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$title, $title, $email, $phone, $contactId, $completedAt, $deletedAt, $now, $now]);

    return (int) $pdo->lastInsertId();
}

$store = jeab_store();
rc($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
rc(jeab_contacts_sole() === true, 'CONTACTS_SOLE är på');
rc(jeab_cutover_enabled() === true, 'STEG4_CUTOVER är på');
rc(jeab_legacy_json_writes() === false, 'LEGACY_JSON_WRITES är av');
$pdo = $store->pdo();
$index = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name = 'reminders_contact_id'")->fetchColumn();
rc($index === 'reminders_contact_id', 'index reminders_contact_id finns');

$deletedCol = false;
foreach ($pdo->query('PRAGMA table_info(reminders)')->fetchAll() as $col) {
    if (is_array($col) && (string) ($col['name'] ?? '') === 'deleted_at') {
        $deletedCol = true;
    }
}
if (!$deletedCol) {
    $pdo->exec('ALTER TABLE reminders ADD COLUMN deleted_at TEXT');
}

$phone = '0701000001';
$email = 'samma.telefon@example.test';
$adaId = 'kt_ada_sole_01';
$boId = 'kt_bo_sole_02';

$doneId = rc_insert($pdo, 'kt_pref_sole', 'Solceller pref klar', $phone, 'pref@example.test', '2026-10-01 10:00:00', null);
$openId = rc_insert($pdo, 'kt_pref_sole', 'Solceller pref', $phone, 'pref@example.test', null, null);
rc($doneId < $openId, 'klar rad fick lägre id än den öppna');
$prefer = jeab_reminder_find_customer_product($store, 'Solceller', [
    'contact_id' => 'kt_pref_sole',
    'email' => $email,
    'phone' => $phone,
    'customer_name' => 'Någon Annan',
]);
rc(is_array($prefer) && (int) ($prefer['id'] ?? 0) === $openId, 'öppen rad vinner över en klar för samma contact_id');

$older = rc_insert($pdo, 'kt_old_sole', 'Solceller gammal', $phone, $email, '2026-09-01 10:00:00', null);
$newer = rc_insert($pdo, 'kt_old_sole', 'Solceller ny', $phone, $email, '2026-10-02 10:00:00', null);
rc($older < $newer, 'äldre klar rad har lägre id');
$oldest = jeab_reminder_find_customer_product($store, 'Batteri', [
    'contact_id' => 'kt_old_sole',
    'phone' => $phone,
]);
rc($oldest === null, 'annan produkt på samma contact_id är ingen träff');
$oldest = jeab_reminder_find_customer_product($store, 'Solceller', [
    'contact_id' => 'kt_old_sole',
    'phone' => '0709999999',
    'email' => 'annan@example.test',
]);
rc(is_array($oldest) && (int) ($oldest['id'] ?? 0) === $older, 'två klara rader: äldsta id, oavsett telefon');

$trashed = rc_insert($pdo, $adaId, 'Solceller papperskorg', $phone, $email, null, '2026-10-03 10:00:00');
$fromTrash = jeab_reminder_find_customer_product($store, 'Solceller', [
    'contact_id' => $adaId,
    'phone' => $phone,
    'email' => $email,
]);
rc($fromTrash === null, 'papperskorgen är ingen dubblett');

$now = jeab_now();
$pdo->prepare('INSERT INTO customers (name, created_at) VALUES (?, ?)')->execute(['Ada Berg', $now]);
$customerId = (int) $pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO bookings (customer_id, customer_name, assignee, phone, email, starts_at, ends_at, status, contact_id, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $customerId,
    'Ada Berg',
    'Elin',
    $phone,
    $email,
    '2026-11-02 09:00:00',
    '2026-11-02 10:00:00',
    'booked',
    $adaId,
    $now,
    $now,
]);
$bookingId = (int) $pdo->lastInsertId();
rc($bookingId > 0, 'bokningen finns');

$ada = jeab_create_reminder($store, 'Ada Berg – Solceller', jeab_today_start(), null, $bookingId, [
    'customer_name' => 'Ada Berg',
    'email' => $email,
    'phone' => $phone,
    'contact_id' => $adaId,
    'company' => 'jarfalla',
]);
$adaReminder = (int) ($ada['id'] ?? 0);
rc($adaReminder > 0 && (string) ($ada['contact_id'] ?? '') === $adaId, 'Ada får en egen påminnelse kopplad till kontakten');
rc((int) ($ada['booking_id'] ?? 0) === $bookingId, 'bokningen sitter kvar på Adas påminnelse');
$order = jeab_purchase_put([
    'reminder_id' => $adaReminder,
    'kind' => 'sol',
    'contact_id' => $adaId,
    'booking_id' => $bookingId,
    'customer_name' => 'Ada Berg',
]);
rc((int) ($order['reminder_id'] ?? 0) === $adaReminder && (string) ($order['contact_id'] ?? '') === $adaId, 'ordern pekar på Adas påminnelse och kontakt');

$noise = $pdo->prepare(
    'INSERT INTO reminders (list_id, title, customer_name, email, phone, contact_id, created_at, updated_at)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?)'
);
for ($i = 0; $i < 40; $i++) {
    $noise->execute([
        'Solceller brus ' . $i,
        'Brus ' . $i,
        $email,
        $phone,
        'kt_noise_' . $i,
        $now,
        $now,
    ]);
}

$GLOBALS['_JEAB_REMINDER_PRODUCT_SQL'] = 0;
$GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'] = 0;
$boFind = jeab_reminder_find_customer_product($store, 'Bo Berg – Solceller', [
    'contact_id' => $boId,
    'email' => $email,
    'phone' => $phone,
    'customer_name' => 'Bo Berg',
]);
rc($boFind === null, 'Bo med samma telefon och e-post är inte Adas dubblett');
rc((int) $GLOBALS['_JEAB_REMINDER_PRODUCT_SQL'] === 1 && (int) $GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'] === 0, 'uppslaget går via SQL och skannar inte alla påminnelser');

[$lookupSql, $needles] = jeab_reminder_contact_product_sql('sqlite', 'sol');
$planStmt = $pdo->prepare('EXPLAIN QUERY PLAN ' . $lookupSql);
$planStmt->execute(array_merge([$boId], $needles));
$plan = '';
foreach ($planStmt->fetchAll() as $line) {
    if (is_array($line)) {
        $plan .= ' ' . (string) ($line['detail'] ?? '');
    }
}
rc(str_contains($plan, 'reminders_contact_id'), 'planen använder index reminders_contact_id, fick ' . trim($plan));

$bo = jeab_create_reminder($store, 'Bo Berg – Solceller', jeab_today_start(), null, null, [
    'customer_name' => 'Bo Berg',
    'email' => $email,
    'phone' => $phone,
    'contact_id' => $boId,
    'company' => 'jarfalla',
]);
$boReminder = (int) ($bo['id'] ?? 0);
rc($boReminder > 0 && $boReminder !== $adaReminder && (string) ($bo['contact_id'] ?? '') === $boId, 'Bo får en egen påminnelse');

$adaAgain = jeab_create_reminder($store, 'Ada Berg – Solceller igen', jeab_today_start(), null, null, [
    'customer_name' => 'Ada Berg',
    'email' => 'ada.annan@example.test',
    'phone' => '0701000099',
    'contact_id' => $adaId,
    'company' => 'jarfalla',
]);
rc((int) ($adaAgain['id'] ?? 0) === $adaReminder && !empty($adaAgain['reused']), 'samma contact_id och produkt återanvänder Adas rad');

$adaBattery = jeab_create_reminder($store, 'Ada Berg – Batteri', jeab_today_start(), null, null, [
    'customer_name' => 'Ada Berg',
    'email' => $email,
    'phone' => $phone,
    'contact_id' => $adaId,
    'company' => 'jarfalla',
]);
$batteryId = (int) ($adaBattery['id'] ?? 0);
rc($batteryId > 0 && $batteryId !== $adaReminder && $batteryId !== $boReminder, 'samma kontakt och annan produkt blir en egen rad');
rc((string) ($adaBattery['contact_id'] ?? '') === $adaId, 'batteriraden behåller Adas kontakt');

$adaRow = $pdo->query('SELECT contact_id, booking_id FROM reminders WHERE id = ' . $adaReminder)->fetch();
$boRow = $pdo->query('SELECT contact_id, booking_id FROM reminders WHERE id = ' . $boReminder)->fetch();
$bookRow = $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . $bookingId)->fetch();
rc(is_array($adaRow) && (string) $adaRow['contact_id'] === $adaId && (int) $adaRow['booking_id'] === $bookingId, 'Adas påminnelse behåller contact_id och booking_id');
rc(is_array($boRow) && (string) $boRow['contact_id'] === $boId && ($boRow['booking_id'] === null || (string) $boRow['booking_id'] === ''), 'Bos påminnelse pekar på Bo och har ingen bokning');
rc(is_array($bookRow) && (string) $bookRow['contact_id'] === $adaId, 'bokningen pekar fortfarande på Ada');
$orderRaw = jeab_purchase_get($adaReminder);
rc(
    is_array($orderRaw)
    && (int) ($orderRaw['reminder_id'] ?? 0) === $adaReminder
    && (string) ($orderRaw['contact_id'] ?? '') === $adaId
    && (int) ($orderRaw['booking_id'] ?? 0) === $bookingId,
    'ordern behåller påminnelse, kontakt och bokning'
);
$adaLive = (int) $pdo->query(
    'SELECT COUNT(*) FROM reminders WHERE contact_id = ' . $pdo->quote($adaId) . " AND (deleted_at IS NULL OR deleted_at = '')"
)->fetchColumn();
rc($adaLive === 2, 'Ada har solraden och batteriraden, papperskorgen oräknad');
rc((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE contact_id = ' . $pdo->quote($adaId))->fetchColumn() === 3, 'papperskorgen räknas fortfarande som Adas rad');
rc((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE contact_id = ' . $pdo->quote($boId))->fetchColumn() === 1, 'Bo har en rad');
rc((int) $trashed > 0 && (string) $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . $trashed)->fetchColumn() === $adaId, 'papperskorgen behåller Adas kontakt');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$byMail = jeab_reminder_find_customer_product($store, 'Solceller', [
    'email' => $email,
    'phone' => $phone,
    'customer_name' => 'Ada Berg',
]);
rc(is_array($byMail) && (int) ($byMail['id'] ?? 0) === $adaReminder, 'utan flaggan kan e-post fortfarande träffa');
$scanAfterOff = (int) $GLOBALS['_JEAB_REMINDER_PRODUCT_SCAN'];
rc($scanAfterOff >= 1, 'utan flaggan söks listan');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$sqlBefore = (int) $GLOBALS['_JEAB_REMINDER_PRODUCT_SQL'];
$noId = jeab_reminder_find_customer_product($store, 'Solceller', [
    'email' => $email,
    'phone' => $phone,
    'customer_name' => 'Ada Berg',
]);
rc($noId === null && (int) $GLOBALS['_JEAB_REMINDER_PRODUCT_SQL'] === $sqlBefore, 'med flaggan och utan contact_id blir det ingen träff och ingen fråga');

unset($GLOBALS['_JEAB_REMINDER_ORT_CACHE'], $GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_TICK']);
rc(jeab_legacy_json_skip('biz_docs') === false, 'utan grön verify skrivs ortcachen');
jeab_reminder_ort_cache_put('nyckel-ett', 'Järfälla', 1700000000);
$cacheFile = $tmp . '/reminder-ort-cache.json';
rc(is_file($cacheFile), 'ortcachen skrivs när biz_docs inte är verifierad SQL');
rc((glob($tmp . '/reminder-ort-cache.json.*.tmp') ?: []) === [], 'tmp-filen byts ut och lämnas inte kvar');

$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
$GLOBALS['_JEAB_DATA_MIGRATE_VERIFY'] = [
    ['type' => 'biz_docs', 'ok' => true, 'match' => true],
];
unset($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX'], $GLOBALS['_JEAB_REMINDER_ORT_CACHE']);
rc(jeab_legacy_json_skip('biz_docs') === true, 'grön verify och avstängd legacy hoppar över JSON');
@unlink($cacheFile);
jeab_reminder_ort_cache_put('nyckel-tva', 'Bromma', 1700000100);
rc(!is_file($cacheFile), 'ortcachen skrivs inte till JSON när biz_docs hoppas över');
rc((glob($tmp . '/*.tmp') ?: []) === [] && (glob($tmp . '/reminder-ort-cache.json.*.tmp') ?: []) === [], 'ingen tmp-fil när JSON hoppas över');
$stored = jeab_sql_doc_get($pdo, 'ort_nyckel-tva');
rc(is_array($stored) && (string) ($stored['c'] ?? '') === 'Bromma', 'orten ligger i SQL i stället');

echo "reminders_contact_product_test ok\n";
