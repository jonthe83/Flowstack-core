<?php

declare(strict_types=1);

/**
 * contacts.drop_customer_fields. Påhittade namn, inget personnummer.
 * dry, vägran för olänkade kopior, live, backup 0600 utan klartext,
 * koden fungerar efter DROP, schemat lägger inte tillbaka, idempotens, undo.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-fields-' . bin2hex(random_bytes(4));
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
    'incoming_webhook_token' => 'not-the-drop-token',
    'outgoing_webhook_url' => '',
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-drop-fields']));
require dirname(__DIR__) . '/src/bootstrap.php';
putenv('JEAB_PNR_HMAC_KEY=drop-fields-test-key');

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
$src = (string) file_get_contents($root . '/src/contacts_drop_customer_fields.php');
cc(str_contains((string) file_get_contents($root . '/hook.php'), "'contacts.drop_customer_fields_undo'"), 'hook.php tar åtgärderna');
cc(str_contains((string) file_get_contents($root . '/src/contacts_sole.php'), 'jeab_contacts_drop_customer_fields_undo('), 'dispatch skickar undo');
cc(!preg_match('/\b(UPDATE|INSERT|DELETE)\s+(snapshots|rot|contacts)\b/i', $src), 'hooken skriver inte kontakter eller snapshots');
cc(!str_contains($src, 'webhook') && !preg_match('/(?<![a-z_])mail\(/', $src), 'hooken skickar inget');

$store = jeab_store();
cc($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$stamp = '2026-01-01 00:00:00';
$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Droppsson', 'email' => 'ada.drop@example.test',
    'address' => 'Droppgatan 1, 177 31 Järfälla', 'city' => 'Järfälla', 'postal_code' => '17731', 'company' => 'jarfalla',
], false, false);
cc(is_array($ada) && (string) $ada['id'] !== '', 'kontakten finns');
$adaId = (string) $ada['id'];
$cust = $store->insertCustomer('Kopia Droppsson', $stamp);
$rem = $store->insertReminder([
    'title' => 'Drop-påminnelse', 'customer_name' => 'Kopia Droppsson', 'email' => 'kopia.drop@example.test',
    'phone' => '0709000011', 'address' => 'Kopiegatan 9', 'ort' => 'Järfälla', 'postal' => '17731',
    'company' => 'jarfalla', 'contact_id' => $adaId, 'note' => 'behall-not', 'due_at' => '2026-11-02 09:00:00',
    'created_at' => $stamp, 'updated_at' => $stamp,
]);
$loose = $store->insertReminder([
    'title' => 'Olänkad', 'customer_name' => 'Lös Kopia', 'email' => 'los@example.test', 'company' => 'malmo',
    'due_at' => '2026-11-03 09:00:00', 'created_at' => $stamp, 'updated_at' => $stamp,
]);
$book = $store->insertBooking([
    'customer_id' => $cust['id'], 'customer_name' => 'Kopia Droppsson', 'email' => 'bok.drop@example.test',
    'phone' => '0709000011', 'address' => 'Kopiegatan 9', 'notes' => 'tid-behall', 'assignees' => ['Elin'],
    'company' => 'jarfalla', 'job_type' => 'laddbox', 'contact_id' => $adaId,
    'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 11:00:00', 'status' => 'booked',
    'created_at' => $stamp, 'updated_at' => $stamp,
]);
$remId = (int) $rem['id'];
$looseId = (int) $loose['id'];
$bookId = (int) $book['id'];
cc($remId > 0 && $looseId > 0 && $bookId > 0, 'raderna finns');

require_once $root . '/src/contacts_drop_customer_fields.php';
require_once $root . '/tests/fixtures/drop_keypair.php';
$dropPair = t_drop_keypair($tmp . '/keys');
t_drop_use_pub($dropPair);
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.drop_customer_fields', 'dry' => 0]);
cc(($denied['status'] ?? 0) === 401, 'dispatch kräver token');
$dry = jeab_contacts_drop_customer_fields([], $store);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
cc(($dry['dry'] ?? null) === true && ($dry['written'] ?? null) === false, 'utelämnad dry är torr');
cc(($dry['tables']['reminders']['drop'] ?? []) === ['customer_name', 'email', 'phone', 'address', 'ort', 'postal'], 'dry visar reminders-kolumnerna');
cc(($dry['tables']['bookings']['drop'] ?? []) === ['customer_name', 'email', 'phone', 'address'], 'dry visar bookings-kolumnerna');
cc(($dry['blocked'] ?? []) === ['reminders'] && in_array($looseId, $dry['tables']['reminders']['unlinked_with_copy']['ids'], true), 'olänkad kopia blockerar och visas med id');
cc(!str_contains($dryJson, 'example.test') && !str_contains($dryJson, 'Kopia') && !str_contains($dryJson, 'Lös'), 'svaret har ingen kunddata');
cc(jeab_copy_column_present($pdo, 'reminders', 'email'), 'dry rör inget');

$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;
$noHold = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($noHold['ok'] ?? true) === false && ($noHold['written'] ?? true) === false, 'dry=0 vägrar utan enda-källa');
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('CONTACTS_STRICT=1');
cc(jeab_contacts_copies_hold(), 'enda-källa är på i testet');
$blocked = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($blocked['status'] ?? 0) === 409 && jeab_copy_column_present($pdo, 'reminders', 'email'), 'olänkad kopia stoppar dry=0');

$pdo->prepare('UPDATE reminders SET customer_name = NULL, email = NULL WHERE id = ?')->execute([$looseId]);
$conflict = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
$conflictJson = (string) json_encode($conflict, JSON_UNESCAPED_UNICODE);
$remConflict = null;
foreach ((array) ($conflict['conflicts'] ?? []) as $c) {
    if (($c['table'] ?? '') === 'reminders' && (int) ($c['id'] ?? 0) === $remId) {
        $remConflict = $c;
    }
}
cc(($conflict['status'] ?? 0) === 409 && ($conflict['written'] ?? true) === false, 'kopia som skiljer sig från kontakten stoppar dry=0');
cc(is_array($remConflict) && in_array('name', $remConflict['fields'], true) && in_array('email', $remConflict['fields'], true) && in_array('street', $remConflict['fields'], true), 'konflikten listar id och fält');
cc(!str_contains($conflictJson, 'example.test') && !str_contains($conflictJson, 'Kopia') && !str_contains($conflictJson, '0709000011') && !str_contains($conflictJson, 'contact_id'), 'konflikten har inga värden');
cc(jeab_copy_column_present($pdo, 'reminders', 'email') && !is_file(jeab_contacts_drop_key_path()), 'konflikten rör inget och skapar ingen nyckel');

// Samma värden som kontakten, och ett telefonnummer kontakten saknar (som påminnelse 298).
$pdo->prepare("UPDATE reminders SET customer_name = 'Ada Droppsson', email = 'ADA.drop@example.test', address = 'Droppgatan 1', ort = 'Järfälla', postal = '177 31', phone = '0709000011' WHERE id = ?")->execute([$remId]);
$pdo->prepare("UPDATE bookings SET customer_name = '·', email = 'ada.drop@example.test', address = 'Droppgatan 1, 17731 Järfälla', phone = '070-900 00 11' WHERE id = ?")->execute([$bookId]);
$fillDry = jeab_contacts_drop_customer_fields([], $store);
cc(($fillDry['conflicts'] ?? null) === [] && ($fillDry['fill']['contacts'] ?? 0) === 1 && ($fillDry['fill']['fields'] ?? 0) === 1, 'dry visar att ett tomt fält fylls');
cc(jeab_kontakt_row_phone((array) jeab_kontakt_get($adaId)) === '', 'dry fyller inget');

$colsBefore = [];
foreach (['reminders', 'bookings'] as $t) {
    foreach ($pdo->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (in_array($c['name'], jeab_copy_columns($t), true)) {
            $colsBefore[$t][$c['name']] = [$c['type'], (int) $c['notnull'], $c['dflt_value']];
        }
    }
}

// Ändras bokningarna mellan planen och DROP stoppar körningen före den tabellen.
$GLOBALS['_JEAB_DROP_BEFORE_LOCK'] = static function (PDO $pdo, string $table) use ($bookId): void {
    if ($table === 'bookings') {
        $pdo->prepare("UPDATE bookings SET phone = '0709000011' WHERE id = ?")->execute([$bookId]);
    }
};
$raced = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
unset($GLOBALS['_JEAB_DROP_BEFORE_LOCK']);
cc(($raced['ok'] ?? true) === false && ($raced['status'] ?? 0) === 409 && ($raced['changed'] ?? '') === 'bookings', 'ändring under körningen stoppar');
jeab_copy_columns_forget();
cc(!jeab_copy_column_present($pdo, 'reminders', 'email') && jeab_copy_column_present($pdo, 'bookings', 'email'), 'reminders är klar och bookings står kvar');
cc(jeab_copy_columns_marker($pdo) === [], 'markeringen sätts inte förrän allt är klart');
$adaAfter = (array) jeab_kontakt_get($adaId);
cc(jeab_kontakt_row_phone($adaAfter) === '' && ($raced['filled']['fields'] ?? -1) === 0, '409 lämnar inga fyllningar');
cc(jeab_kontakt_row_name($adaAfter) === 'Ada Droppsson' && jeab_kontakt_row_email($adaAfter) === 'ada.drop@example.test', 'befintliga värden skrivs inte över');
cc(count(jeab_kontakter_load()) === 1, 'inga kontakter slås ihop eller skapas');
$batch = (string) ($raced['batch_id'] ?? '');
cc(preg_match('/\A[a-f0-9]{16}\z/', $batch) === 1, 'batch_id finns');
$path = jeab_contacts_drop_dir() . '/' . $batch . '.json';
$raw = (string) @file_get_contents($path);
cc(($raced['backup']['sha256'] ?? '') === hash('sha256', $raw) && str_ends_with((string) ($raced['backup']['path'] ?? ''), '.private/contacts-drop-customer-fields/' . $batch . '.json') && !str_contains((string) $raced['backup']['path'], $tmp), 'svaret har backupens sökväg och sha256');
cc(!is_file(jeab_contacts_drop_key_path()) && glob(dirname(jeab_contacts_drop_dir()) . '/*.key') === [], 'ingen nyckel skapas på servern');
$pubSha = hash('sha256', (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $dropPair['pub_pem'])));
$sealedBackup = json_decode((string) @file_get_contents(jeab_contacts_drop_dir() . '/' . $batch . '.json'), true);
cc(($sealedBackup['seal'] ?? '') === 'v3' && ($sealedBackup['pub_sha256'] ?? '') === $pubSha && ($raced['pub_sha256'] ?? '') === $pubSha, 'backupen har fingeravtrycket och svaret bara publika nyckelns sha256');
putenv('JEAB_PNR_HMAC_KEY=en-annan-nyckel');
cc(is_file($path) && (fileperms($path) & 0777) === 0600, 'backupen är 0600');
$backup = json_decode($raw, true);
cc(($backup['tables']['reminders']['defs']['customer_name']['type'] ?? '') === $colsBefore['reminders']['customer_name'][0], 'backupen har kolumntypen');
cc($raw !== '' && !str_contains($raw, 'example.test') && !str_contains($raw, 'Droppsson') && !str_contains($raw, '0709000011'), 'backupen har ingen klartext');

$live = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($live['ok'] ?? false) === true && ($live['written'] ?? false) === true && array_key_exists('bookings', (array) ($live['dropped'] ?? [])) && !array_key_exists('reminders', (array) ($live['dropped'] ?? [])), 'en ny körning gör klart det som är kvar');
$batch2 = (string) ($live['batch_id'] ?? '');
cc($batch2 !== '' && $batch2 !== $batch && ($live['filled']['fields'] ?? -1) === 1, 'fyllningen görs när alla tabeller är klara');
cc(jeab_kontakt_phone(jeab_kontakt_row_phone((array) jeab_kontakt_get($adaId))) === jeab_kontakt_phone('0709000011'), 'kontaktens tomma telefon fylls från kopian');
$fillLog = json_decode((string) @file_get_contents(jeab_contacts_drop_dir() . '/' . $batch2 . '.fills'), true);
$fillMark = (string) ($fillLog[$adaId]['phone']['mark'] ?? '');
$fillJson = (string) json_encode($fillLog);
// Märket är 64 hex och kan av en slump innehålla korta siffror. Hela numret i någon form får inte finnas.
cc(preg_match('/\A[a-f0-9]{64}\z/', $fillMark) === 1 && ($fillLog[$adaId]['phone']['prev'] ?? null) === '' && !isset($fillLog[$adaId]['phone']['intent']), 'fyllningsloggen har föregående värde och ett märke');
cc(!str_contains($fillJson, '709000011') && !str_contains($fillJson, '070-900') && !str_contains($fillJson, '+46'), 'fyllningsloggen har ingen klartext');
cc(($live['pending_waiting'] ?? null) === [$batch] && is_file(jeab_contacts_drop_dir() . '/' . $batch . '.pending'), 'den avbrutna körningens plan väntar på undo-nyckeln och rapporteras');
$noKeyResume = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($noKeyResume['status'] ?? 0) === 500 && is_file(jeab_contacts_drop_dir() . '/' . $batch . '.pending'), 'utan undo-nyckel görs planen inte och ligger kvar');
$resumeKey = t_drop_undo_key($dropPair);
$resume = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($resume['ok'] ?? false) === true && !is_file($resumeKey), 'med undo-nyckeln görs planen klart och nyckelfilen raderas');
cc(glob(jeab_contacts_drop_dir() . '/*.pending') === [], 'inga fyllningsplaner ligger kvar');
cc(array_slice(array_keys(jeab_copy_columns_marker($pdo)), 0, 2) === ['reminders', 'bookings'], 'markeringen sätts när allt är klart');
foreach (['reminders' => ['customer_name', 'email', 'phone', 'address', 'ort', 'postal'], 'bookings' => ['customer_name', 'email', 'phone', 'address']] as $t => $cols) {
    foreach ($cols as $c) {
        cc(!jeab_copy_column_present($pdo, $t, $c), "{$t}.{$c} är borta");
    }
}
cc(jeab_copy_column_present($pdo, 'reminders', 'contact_id') && jeab_copy_column_present($pdo, 'bookings', 'notes'), 'andra kolumner står kvar');
cc(str_contains(str_replace('\\', '/', $path), '/.private/'), 'backupen ligger under .private');
cc(!str_contains((string) json_encode($live, JSON_UNESCAPED_UNICODE), 'example.test'), 'live-svaret har ingen kunddata');

jeab_ensure_reminder_contact_columns($pdo, 'sqlite');
jeab_ensure_booking_contact_columns($pdo, 'sqlite');
jeab_copy_columns_forget();
cc(!jeab_copy_column_present($pdo, 'reminders', 'email') && !jeab_copy_column_present($pdo, 'bookings', 'email'), 'schemat lägger inte tillbaka kolumnerna');

$after = $store->getReminder($remId);
cc(is_array($after) && ($after['note'] ?? '') === 'behall-not' && ($after['contact_id'] ?? '') === $adaId, 'påminnelsen läses efter DROP');
cc(count($store->listReminders()) >= 2, 'listan fungerar efter DROP');
$new = $store->insertReminder(['title' => 'Ny', 'customer_name' => 'Ska inte sparas', 'email' => 'ny@example.test', 'company' => 'jarfalla', 'contact_id' => $adaId, 'due_at' => '2026-11-04 09:00:00', 'created_at' => $stamp, 'updated_at' => $stamp]);
cc((int) ($new['id'] ?? 0) > 0, 'insertReminder fungerar efter DROP');
$saved = $store->saveReminder($remId, ['note' => 'ny-not', 'email' => 'x@example.test']);
cc(($saved['note'] ?? '') === 'ny-not', 'saveReminder fungerar efter DROP');
$nb = $store->insertBooking(['customer_id' => $cust['id'], 'customer_name' => 'Ska inte sparas', 'email' => 'nb@example.test', 'notes' => 'nb', 'assignees' => ['Elin'], 'company' => 'jarfalla', 'job_type' => 'laddbox', 'contact_id' => $adaId, 'starts_at' => '2026-11-05 09:00:00', 'ends_at' => '2026-11-05 10:00:00', 'status' => 'booked', 'created_at' => $stamp, 'updated_at' => $stamp]);
cc((int) ($nb['id'] ?? 0) > 0, 'insertBooking fungerar efter DROP');
$sb = $store->saveBooking($bookId, ['notes' => 'ny-bok-not', 'address' => 'Ska inte sparas']);
cc(($sb['notes'] ?? '') === 'ny-bok-not' && ($sb['starts_at'] ?? '') === '2026-11-02 09:00:00', 'saveBooking fungerar och tiden står kvar');
cc(count($store->bookingsBetween('2026-11-01 00:00:00', '2026-11-30 00:00:00')) >= 2, 'bookingsBetween fungerar efter DROP');
$painted = jeab_contacts_sole_paint_reminder($after);
cc(($painted['customer_name'] ?? '') === 'Ada Droppsson', 'namnet kommer från kontakten');
require_once $root . '/src/purchase.php';
cc(jeab_purchase_reminder_name($after) === 'Ada Droppsson', 'inköpsnamnet kommer från kontakten efter DROP');
require_once $root . '/src/migrate/reminders.php';
require_once $root . '/src/migrate/bookings.php';
$GLOBALS['_JEAB_MIGRATE_PDO'] = $pdo;
$remCols = jeab_migrate_reminders_module()['columns'];
$bookCols = jeab_migrate_bookings_module()['columns'];
unset($GLOBALS['_JEAB_MIGRATE_PDO']);
cc(!in_array('customer_name', $remCols, true) && in_array('note', $remCols, true), 'migreringen hoppar över borttagna påminnelsekopior');
cc(!in_array('customer_name', $bookCols, true) && in_array('starts_at', $bookCols, true), 'migreringen hoppar över borttagna bokningskopior');
require_once $root . '/src/inkorg_rebook.php';
$bookRow = $store->getBooking($bookId);
cc(is_array($bookRow) && trim((string) ($bookRow['customer_name'] ?? '')) === '', 'bokningen har ingen namnkopia efter DROP');
$moved = jeab_rebook_moved_text(jeab_rebook_paint($bookRow));
cc(str_starts_with(jeab_rebook_moved_text($bookRow), 'Hej,'), 'utan kontakten börjar mejlet med Hej,');
cc(str_starts_with($moved, 'Hej Ada') && !str_starts_with($moved, 'Hej,') && str_contains($moved, 'Vi har flyttat din tid'), 'ombokningsmejlet hälsar med namnet från kontakten');
$looseRow = $store->getReminder($looseId);
cc(is_array($looseRow) && (string) ($looseRow['customer_name'] ?? '') === '', 'olänkad rad visas med tomt fält');

$again = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($again['ok'] ?? false) === true && ($again['written'] ?? true) === false && ($again['already'] ?? false) === true, 'andra körningen gör inget');

// En backup som inte går att dekryptera stoppar undo innan något skrivs.
$broken = json_decode((string) file_get_contents($path), true);
$broken['tables']['reminders']['rows'][0]['fields']['email']['sealed'] = 'v2:' . str_repeat('0', 80);
$badBatch = ($batch[0] === 'b' ? 'c' : 'b') . substr($batch, 1);
$brokenJson = (string) json_encode($broken);
file_put_contents(jeab_contacts_drop_dir() . '/' . $badBatch . '.json', $brokenJson);
copy(jeab_contacts_drop_dir() . '/' . $batch . '.done', jeab_contacts_drop_dir() . '/' . $badBatch . '.done');
// Utan rätt sha256 vägrar undo direkt.
copy(jeab_contacts_drop_dir() . '/' . $batch . '.sha256', jeab_contacts_drop_dir() . '/' . $badBatch . '.sha256');
t_drop_undo_key($dropPair);
$shaUndo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $badBatch], $store);
cc(($shaUndo['ok'] ?? true) === false && ($shaUndo['status'] ?? 0) === 409 && str_contains((string) ($shaUndo['error'] ?? ''), 'sha256') && !jeab_copy_column_present($pdo, 'reminders', 'email'), 'ändrad backup ger fel på sha256 och skriver inget');
t_drop_undo_key($dropPair);
$wrongGiven = jeab_contacts_drop_customer_fields_undo(['dry' => 1, 'batch_id' => $batch, 'sha256' => str_repeat('0', 64)], $store);
cc(($wrongGiven['ok'] ?? true) === false && ($wrongGiven['status'] ?? 0) === 409, 'fel sha256 i anropet vägras');
file_put_contents(jeab_contacts_drop_dir() . '/' . $badBatch . '.sha256', hash('sha256', $brokenJson));
t_drop_undo_key($dropPair);
$badUndo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $badBatch], $store);
jeab_copy_columns_forget();
cc(($badUndo['ok'] ?? true) === false && ($badUndo['written'] ?? true) === false && ($badUndo['unreadable'] ?? 0) === 1, 'odekrypterbart värde ger fel');
cc(!jeab_copy_column_present($pdo, 'reminders', 'email') && array_slice(array_keys(jeab_copy_columns_marker($pdo)), 0, 2) === ['reminders', 'bookings'], 'odekrypterbart värde skriver inget');
unlink(jeab_contacts_drop_dir() . '/' . $badBatch . '.json');
unlink(jeab_contacts_drop_dir() . '/' . $badBatch . '.done');
unlink(jeab_contacts_drop_dir() . '/' . $badBatch . '.sha256');

t_drop_undo_key($dropPair);
$undoDry = jeab_contacts_drop_customer_fields_undo(['batch_id' => $batch], $store);
cc(($undoDry['dry'] ?? null) === true && !jeab_copy_column_present($pdo, 'reminders', 'email'), 'undo är torr som standard');
t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
t_drop_undo_key($dropPair);
$undo2 = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch2], $store);
cc(($undo['ok'] ?? false) === true && ($undo['written'] ?? false) === true && ($undo2['ok'] ?? false) === true, 'undo skriver');
cc(in_array('bookings', (array) ($undo['not_dropped'] ?? []), true) && array_keys((array) ($undo['tables'] ?? [])) === ['reminders'], 'undo återställer bara tabellen som batchen tog bort');
cc(($undo['backup']['sha256'] ?? '') === hash('sha256', $raw), 'undo-svaret har backupens sha256');
jeab_copy_columns_forget();
cc(jeab_copy_column_present($pdo, 'reminders', 'postal') && jeab_copy_column_present($pdo, 'bookings', 'address'), 'undo lägger tillbaka kolumnerna');
$colsAfter = [];
foreach (['reminders', 'bookings'] as $t) {
    foreach ($pdo->query('PRAGMA table_info(' . $t . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (in_array($c['name'], jeab_copy_columns($t), true)) {
            $colsAfter[$t][$c['name']] = [$c['type'], (int) $c['notnull'], $c['dflt_value']];
        }
    }
}
// sqlite kräver ett standardvärde för ADD COLUMN NOT NULL; MySQL får exakt samma definition.
$expected = $colsBefore;
$expected['bookings']['customer_name'][2] = "''";
cc($colsBefore['bookings']['customer_name'][1] === 1 && $colsAfter === $expected, 'undo lägger tillbaka samma typer och NOT NULL');
cc(jeab_copy_columns_marker($pdo) === [], 'undo tar bort markeringen');
$r = $pdo->query('SELECT customer_name, email, ort, phone FROM reminders WHERE id = ' . $remId)->fetch(PDO::FETCH_ASSOC);
cc(is_array($r) && $r['email'] === 'ADA.drop@example.test' && $r['ort'] === 'Järfälla' && $r['phone'] === '0709000011', 'undo återställer påminnelsens värden');
$b = $pdo->query('SELECT email, phone FROM bookings WHERE id = ' . $bookId)->fetch(PDO::FETCH_ASSOC);
cc(is_array($b) && $b['email'] === 'ada.drop@example.test' && $b['phone'] === '0709000011', 'undo återställer bokningens värden');
cc(($undo2['unfilled']['reverted'] ?? -1) === 1 && jeab_kontakt_row_phone((array) jeab_kontakt_get($adaId)) === '', 'undo tömmer telefonen som fylldes');
cc(jeab_kontakt_row_email((array) jeab_kontakt_get($adaId)) === 'ada.drop@example.test', 'undo rör inte värden som fanns före');
cc(($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === [], 'inga webhooks');
echo "ALL OK\n";
