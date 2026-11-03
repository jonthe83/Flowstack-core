<?php

declare(strict_types=1);

/**
 * kontakter.sole_fill_empty, kontakter.sole_resnapshot och kontakter.pnr_ambiguous_resolve.
 * Påhittade namn. Personnummer finns bara som testdata och får inte synas i svar eller logg.
 */

$mariadb = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
$mariadbd = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
if ($mariadb === '' || $mariadbd === '') {
    echo "SKIP MariaDB saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sole-hooks-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$port = 33000 + random_int(0, 2000);
$data = $tmp . '/db';
$sock = $tmp . '/mysql.sock';
$pidFile = $tmp . '/mysql.pid';
$init = $tmp . '/init.sql';
$log = $tmp . '/mysql.log';
$errLog = $tmp . '/php-error.log';
file_put_contents($init, <<<SQL
CREATE DATABASE IF NOT EXISTS jeab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'jeab'@'127.0.0.1' IDENTIFIED BY 'jeabpass';
GRANT ALL PRIVILEGES ON jeab.* TO 'jeab'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL);
$install = sprintf(
    'mariadb-install-db --datadir=%s --auth-root-authentication-method=normal --skip-test-db >%s 2>&1',
    escapeshellarg($data),
    escapeshellarg($log)
);
exec($install, $unused, $installCode);
if ($installCode !== 0) {
    fwrite(STDERR, "FAIL MariaDB kunde inte initieras\n");
    exit(1);
}
$cmd = sprintf(
    'mariadbd --datadir=%s --bind-address=127.0.0.1 --port=%d --socket=%s --pid-file=%s --init-file=%s',
    escapeshellarg($data),
    $port,
    escapeshellarg($sock),
    escapeshellarg($pidFile),
    escapeshellarg($init)
);
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL MariaDB startade inte\n");
    exit(1);
}
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    try {
        new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass', [PDO::ATTR_TIMEOUT => 1]);
        $up = true;
        break;
    } catch (Throwable) {
        usleep(200000);
    }
}
if (!$up) {
    fwrite(STDERR, "FAIL MariaDB svarade inte\n" . (string) file_get_contents($log) . "\n");
    exit(1);
}

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('CONTACTS_SOLE');
putenv('JEAB_PNR_HMAC_KEY=sole-hooks-hmac');
ini_set('log_errors', '1');
ini_set('error_log', $errLog);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'mysql',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => [
            'host' => '127.0.0.1;port=' . $port,
            'name' => 'jeab',
            'user' => 'jeab',
            'pass' => 'jeabpass',
            'charset' => 'utf8mb4',
        ],
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

$setup = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass');
$setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = require JEAB_ROOT . '/schema/mysql.php';
$schema = preg_replace('/^\s*--.*$/m', '', is_string($schema) ? $schema : '');
foreach (array_filter(array_map('trim', explode(';', (string) $schema))) as $stmt) {
    if ($stmt !== '') {
        $setup->exec($stmt);
    }
}
unset($setup);

function hook_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function hook_save_contact(array $row): void
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

function hook_owner_field(array $contact, int $index, string $field): string
{
    $owners = is_array($contact['owners'] ?? null) ? $contact['owners'] : [];
    $owner = $owners[$index] ?? null;

    return is_array($owner) ? trim((string) ($owner[$field] ?? '')) : '';
}

$root = dirname(__DIR__);
$hookSrc = (string) file_get_contents($root . '/hook.php');
$soleSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
hook_ok(str_contains($hookSrc, "'kontakter.sole_fill_empty'") && str_contains($hookSrc, "'kontakter.sole_resnapshot'") && str_contains($hookSrc, "'kontakter.pnr_ambiguous_resolve'") && str_contains($hookSrc, "'kontakter.sole_undo_rows'") && str_contains($hookSrc, "'kontakter.link_compare'") && str_contains($hookSrc, "'kontakter.sole_paint_resolve'"), 'hook.php registrerar läshookarna');
$paintFrom = strpos($soleSrc, 'function jeab_contacts_sole_paint_resolve(');
$paintTo = strpos($soleSrc, 'function jeab_contacts_sole_dispatch(');
$paintBody = ($paintFrom !== false && $paintTo !== false && $paintTo > $paintFrom) ? substr($soleSrc, $paintFrom, $paintTo - $paintFrom) : '';
hook_ok($paintBody !== '' && str_contains($paintBody, 'jeab_contacts_sole_write_error') && strpos($paintBody, 'jeab_contacts_sole_write_error') < strpos($paintBody, 'jeab_contacts_sole_paint_apply'), 'paint vaktas av write_error före skrivning');
hook_ok($paintBody !== '' && !str_contains($paintBody, 'jeab_contacts_sole()'), 'paint kräver inte flaggan');
foreach ([
    'jeab_contacts_sole_fill_empty(' => 'function jeab_contacts_sole_resnapshot(',
    'jeab_contacts_sole_resnapshot(' => 'function jeab_contacts_sole_pnr_name_source(',
    'jeab_contacts_sole_pnr_ambiguous_resolve(' => 'function jeab_contacts_sole_dispatch(',
] as $start => $end) {
    $from = strpos($soleSrc, 'function ' . $start);
    $to = strpos($soleSrc, $end);
    $body = ($from !== false && $to !== false && $to > $from) ? substr($soleSrc, $from, $to - $from) : '';
    hook_ok($body !== '' && str_contains($body, 'jeab_contacts_sole_write_error'), $start . ' vaktas av write_error');
}
$fillBody = substr($soleSrc, (int) strpos($soleSrc, 'function jeab_contacts_sole_fill_empty('), (int) strpos($soleSrc, 'function jeab_contacts_sole_resnapshot(') - (int) strpos($soleSrc, 'function jeab_contacts_sole_fill_empty('));
hook_ok(str_contains($fillBody, 'jeab_contacts_sole_fill_empty_guard') && str_contains($fillBody, 'jeab_contacts_sole_write_link') && strpos($fillBody, 'jeab_contacts_sole_fill_empty_guard') < strpos($fillBody, 'jeab_contacts_sole_write_link'), 'antalsspärren ligger före skrivningen');
hook_ok(!str_contains($fillBody, 'jeab_contacts_sole()'), 'fyllningen kräver inte flaggan');
$guardMsg = jeab_contacts_sole_fill_empty_guard(
    ['reminders' => 2, 'orders' => 1, 'bookings' => 1],
    ['reminders' => 3, 'orders' => 1, 'bookings' => 1],
    []
);
hook_ok(str_contains($guardMsg, 'Antalet') && str_contains($guardMsg, 'Inget skrevs'), 'ojämnt antal stoppar utan skrivning');
hook_ok(jeab_contacts_sole_fill_empty_guard(
    ['reminders' => 2, 'orders' => 1, 'bookings' => 1],
    ['reminders' => 2, 'orders' => 1, 'bookings' => 1],
    []
) === '', 'lika antal släpps igenom');
hook_ok(str_contains(jeab_contacts_sole_fill_empty_guard(
    ['reminders' => 1, 'orders' => 1, 'bookings' => 1],
    ['reminders' => 1, 'orders' => 1, 'bookings' => 1],
    [['kind' => 'reminder', 'id' => 1]]
), 'flyttas'), 'en giltig länk som skulle flyttas stoppar');

$store = jeab_store();
hook_ok($store instanceof JeabPdoStore && $store->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'lagret är MySQL');
hook_ok(jeab_settings_put($store->pdo(), 'mysql', JEAB_KONTAKTER_STORAGE_KEY, 'sql'), 'kontakterna ligger i MySQL');
hook_ok(jeab_kontakter_writes_sql_only(), 'kontaktskrivning går till SQL');
hook_ok(jeab_contacts_sole() === false, 'flaggan är av');

$now = jeab_now();
$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Behåll',
    'email' => 'ada.behall@example.test',
    'phone' => '0701000001',
    'address' => 'Behallgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
$bo = jeab_kontakt_find_or_create([
    'name' => 'Bo Mejl',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701000002',
    'address' => 'Mejlgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($ada) && is_array($bo), 'två kontakter finns');

$reminder = static function (array $extra) use ($store, $now): array {
    return $store->insertReminder(array_merge([
        'title' => 'Batteri',
        'company' => 'jarfalla',
        'due_at' => '2026-10-21 09:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ], $extra));
};
$kept = $reminder([
    'customer_name' => 'Ada Behåll',
    'email' => 'ada.behall@example.test',
    'phone' => '0701000001',
    'address' => 'Behallgatan 1, 111 22 Teststad',
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $ada['id'], (int) $kept['id']]);
$empty = $reminder([
    'customer_name' => 'Kopia Mejl',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701888888',
    'address' => 'Felgatan 1',
]);
$dangling = $reminder([
    'customer_name' => 'Fia Saknad',
    'email' => 'fia.saknad@example.test',
    'phone' => '0701000006',
    'address' => 'Saknadgatan 6, 333 44 Testby',
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_borta', (int) $dangling['id']]);
$orderReminder = $reminder(['customer_name' => 'Orderkopia', 'email' => 'order.kopia@example.test', 'title' => 'Order']);
jeab_purchase_put([
    'reminder_id' => (int) $orderReminder['id'],
    'kind' => 'laddbox',
    'name' => 'Kopia Order',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701666666',
    'address' => 'Ordergatan 7',
    'created_at' => $now,
    'updated_at' => $now,
]);
$customer = jeab_find_or_create_customer($store, 'Kopia Bokning');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Greta Ny',
    'email' => 'greta.ny@example.test',
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

$countsBefore = jeab_contacts_sole_counts($store);
$dry = jeab_contacts_sole_fill_empty([], $store);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
hook_ok(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']), 'fyllningen är torr som standard');
hook_ok(is_string($dryJson) && !str_contains($dryJson, 'personnummer') && !str_contains($dryJson, 'pnr'), 'torrlistan har inga nummerfält');
hook_ok(($dry['counts']['before'] ?? null) === $countsBefore && ($dry['counts']['after'] ?? null) === $countsBefore, 'torrkörningen rapporterar samma antal före och efter');
hook_ok(jeab_contacts_sole_cid($store->getReminder((int) $empty['id'])['contact_id'] ?? null) === '', 'torrkörning sätter inget contact_id');
$planned = [];
foreach ((array) ($dry['rows'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    hook_ok(array_keys($row) === ['kind', 'id', 'action'], 'planen har bara slag, id och åtgärd');
    $planned[(string) $row['kind'] . ':' . (string) $row['id']] = (string) $row['action'];
}
hook_ok(($planned['reminder:' . (int) $empty['id']] ?? '') === 'link', 'tom rad ska kopplas');
hook_ok(($planned['reminder:' . (int) $dangling['id']] ?? '') === 'create', 'trasig länk ska få en ny kontakt');
hook_ok(($planned['order:' . (int) $orderReminder['id']] ?? '') === 'link', 'order utan länk ska kopplas');
hook_ok(($planned['booking:' . (int) $booking['id']] ?? '') === 'create', 'bokning utan träff ska skapa');
hook_ok(!isset($planned['reminder:' . (int) $kept['id']]), 'giltig länk listas inte');

$done = jeab_contacts_sole_fill_empty(['dry' => 0], $store);
hook_ok(!empty($done['ok']) && !empty($done['written']) && empty($done['dry']), 'fyllningen skriver utan flagga');
hook_ok(($done['counts']['before'] ?? null) === $countsBefore && ($done['counts']['after'] ?? null) === $countsBefore, 'antalet per tabell är oförändrat');
hook_ok(jeab_contacts_sole_counts($store) === $countsBefore, 'tabellerna har samma antal efter skrivning');
hook_ok((string) ($store->getReminder((int) $kept['id'])['contact_id'] ?? '') === (string) $ada['id'], 'giltig länk består');
hook_ok((string) ($store->getReminder((int) $empty['id'])['contact_id'] ?? '') === (string) $bo['id'], 'tom rad kopplades via mejl');
$orderAfter = jeab_purchase_get((int) $orderReminder['id']);
hook_ok(is_array($orderAfter) && (string) ($orderAfter['contact_id'] ?? '') === (string) $bo['id'], 'ordern kopplades via mejl');
$bookingCid = (string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '');
$danglingCid = (string) ($store->getReminder((int) $dangling['id'])['contact_id'] ?? '');
hook_ok($bookingCid !== '' && $danglingCid !== '' && $bookingCid !== $danglingCid && $bookingCid !== (string) $ada['id'], 'nya kontakter skapades');
$batch = (string) ($done['batch_id'] ?? '');
$undoDry = jeab_contacts_sole_undo(['batch_id' => $batch], $store);
hook_ok(!empty($undoDry['ok']) && !empty($undoDry['dry']) && empty($undoDry['written']) && (int) ($undoDry['links'] ?? 0) >= 4 && (int) ($undoDry['contacts'] ?? 0) >= 2, 'ångern är torr och räknar länkarna');
hook_ok((string) ($store->getReminder((int) $empty['id'])['contact_id'] ?? '') === (string) $bo['id'], 'torr ånger skriver inte');
$later = $reminder([
    'customer_name' => 'Sen Länk',
    'email' => 'sen.lank@example.test',
    'phone' => '0701000044',
    'address' => 'Senaregatan 4',
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$bookingCid, (int) $later['id']]);
$countsWithLater = jeab_contacts_sole_counts($store);
$undo = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);
hook_ok(!empty($undo['ok']) && !empty($undo['written']), 'ångern skriver');
hook_ok(jeab_contacts_sole_cid($store->getReminder((int) $empty['id'])['contact_id'] ?? null) === '', 'ångern tömmer den nya länken');
hook_ok(jeab_contacts_sole_cid(jeab_purchase_get((int) $orderReminder['id'])['contact_id'] ?? null) === '', 'ångern tömmer orderns länk');
hook_ok(jeab_contacts_sole_cid($store->getBooking((int) $booking['id'])['contact_id'] ?? null) === '', 'ångern tömmer bokningens länk');
hook_ok((string) ($store->getReminder((int) $dangling['id'])['contact_id'] ?? '') === 'kt_borta', 'ångern lägger tillbaka den trasiga länken');
hook_ok((string) ($store->getReminder((int) $kept['id'])['contact_id'] ?? '') === (string) $ada['id'], 'ångern lämnar den giltiga länken');
hook_ok(jeab_kontakt_get($danglingCid) === null, 'okopplad skapad kontakt tas bort');
hook_ok(is_array(jeab_kontakt_get($bookingCid)), 'kontakt som en ny rad pekar på finns kvar');
hook_ok((string) ($store->getReminder((int) $later['id'])['contact_id'] ?? '') === $bookingCid, 'den nya raden behåller länken');
$keptRefs = is_array($undo['kept_referenced'] ?? null) ? $undo['kept_referenced'] : [];
hook_ok(in_array($bookingCid, $keptRefs, true) && !in_array($danglingCid, $keptRefs, true), 'kept_referenced har bara den använda kontakten');
hook_ok(jeab_contacts_sole_counts($store) === $countsWithLater, 'ångern ändrar inte antalet rader');

$conflict = $reminder([
    'customer_name' => 'Ada Behåll',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701000099',
    'address' => 'Annan gata 4',
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $ada['id'], (int) $conflict['id']]);
$blockedEmpty = $reminder([
    'customer_name' => 'Holger Tom',
    'email' => 'holger.tom@example.test',
    'phone' => '0701000077',
    'address' => 'Tomgatan 3, 444 55 Teststad',
]);
$countsNow = jeab_contacts_sole_counts($store);
$blockedDry = jeab_contacts_sole_fill_empty([], $store);
$dryCand = null;
foreach ((array) ($blockedDry['move_candidates'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['kind'] ?? '') === 'reminder' && (int) ($row['id'] ?? 0) === (int) $conflict['id']) {
        $dryCand = $row;
    }
}
$dryWritesConflict = false;
$dryPlansEmpty = false;
foreach ((array) ($blockedDry['rows'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string) ($row['kind'] ?? '') === 'reminder' && (int) ($row['id'] ?? 0) === (int) $conflict['id']) {
        $dryWritesConflict = true;
    }
    if ((string) ($row['kind'] ?? '') === 'reminder' && (int) ($row['id'] ?? 0) === (int) $blockedEmpty['id'] && (string) ($row['action'] ?? '') === 'create') {
        $dryPlansEmpty = true;
    }
}
hook_ok(!empty($blockedDry['ok']) && !empty($blockedDry['dry']) && empty($blockedDry['written']), 'giltig länk som pekar om stoppar inte torrkörningen');
hook_ok(is_array($dryCand) && array_keys($dryCand) === ['kind', 'id', 'contact_id', 'resolved'], 'kandidaten har slag, id och båda kontakterna');
hook_ok((string) ($dryCand['contact_id'] ?? '') === (string) $ada['id'] && (string) ($dryCand['resolved'] ?? '') === (string) $bo['id'], 'kandidaten visar nuvarande och löst kontakt');
hook_ok(!$dryWritesConflict && $dryPlansEmpty, 'den giltiga länken planeras inte, den tomma raden gör det');
hook_ok((string) ($store->getReminder((int) $conflict['id'])['contact_id'] ?? '') === (string) $ada['id'], 'torrkörning lämnar den giltiga länken');
$blocked = jeab_contacts_sole_fill_empty(['dry' => 0], $store);
$wetWritesConflict = false;
foreach ((array) ($blocked['rows'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['kind'] ?? '') === 'reminder' && (int) ($row['id'] ?? 0) === (int) $conflict['id']) {
        $wetWritesConflict = true;
    }
}
$wetCand = false;
foreach ((array) ($blocked['move_candidates'] ?? []) as $row) {
    if (is_array($row) && (int) ($row['id'] ?? 0) === (int) $conflict['id'] && (string) ($row['contact_id'] ?? '') === (string) $ada['id'] && (string) ($row['resolved'] ?? '') === (string) $bo['id']) {
        $wetCand = true;
    }
}
$holgerCid = (string) ($store->getReminder((int) $blockedEmpty['id'])['contact_id'] ?? '');
hook_ok(!empty($blocked['ok']) && !empty($blocked['written']) && empty($blocked['dry']), 'körningen skriver de tomma raderna');
hook_ok(!$wetWritesConflict && $wetCand, 'den giltiga länken skrivs inte och rapporteras');
hook_ok((string) ($store->getReminder((int) $conflict['id'])['contact_id'] ?? '') === (string) $ada['id'], 'den giltiga länken flyttades inte');
hook_ok($holgerCid !== '' && $holgerCid !== (string) $ada['id'] && $holgerCid !== (string) $bo['id'], 'den tomma raden fick en egen kontakt');
hook_ok(jeab_contacts_sole_counts($store) === $countsNow, 'antalet rader är oförändrat');
hook_ok((string) ($blocked['batch_id'] ?? '') !== '', 'länkarna ligger i en ångerbatch');

$adaId = (string) $ada['id'];
$store->pdo()->prepare("UPDATE reminders SET contact_id = ? WHERE contact_id IS NULL OR contact_id = ''")->execute([$adaId]);
$store->pdo()->prepare("UPDATE bookings SET contact_id = ? WHERE contact_id IS NULL OR contact_id = ''")->execute([$adaId]);
foreach (jeab_purchase_all() as $row) {
    if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== '') {
        continue;
    }
    jeab_purchase_patch((int) ($row['reminder_id'] ?? 0), static function (array $order) use ($adaId): array {
        $order['contact_id'] = $adaId;

        return $order;
    });
}
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$snapDry = jeab_contacts_sole_resnapshot([], $store);
hook_ok(!empty($snapDry['ok']) && !empty($snapDry['dry']) && empty($snapDry['written']), 'omfotot är torrt som standard');
hook_ok(jeab_contacts_sole_snapshot() === null, 'torr omfotografering skriver ingen bild');
$snap = jeab_contacts_sole_resnapshot(['dry' => 0], $store);
hook_ok(!empty($snap['ok']) && !empty($snap['written']), 'omfotot skriver en bild utan att flytta länkar');
hook_ok((string) ($store->getReminder((int) $kept['id'])['contact_id'] ?? '') === $adaId, 'omfotot lämnar contact_id');
$verify = jeab_contacts_sole_verify($store);
hook_ok(!empty($verify['ok']) && !empty($verify['ids_same']) && !empty($verify['links_same']), 'verifieringen jämför mot den nya bilden');
hook_ok(($verify['counts']['before'] ?? null) === ($verify['counts']['after'] ?? null), 'bilden har samma antal som nu');
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $bo['id'], (int) $kept['id']]);
$drift = jeab_contacts_sole_verify($store);
hook_ok(empty($drift['links_same']), 'en ändrad länk syns mot den sparade bilden');
$snap2 = jeab_contacts_sole_resnapshot(['dry' => 0], $store);
$verify2 = jeab_contacts_sole_verify($store);
hook_ok(!empty($verify2['ok']) && !empty($verify2['links_same']), 'ny bild matchar det som ligger nu');
$snapUndo = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => (string) ($snap2['batch_id'] ?? '')], $store);
hook_ok(!empty($snapUndo['ok']) && (int) ($snapUndo['snapshots'] ?? 0) === 1, 'ångern tar tillbaka bilden');
$restored = jeab_contacts_sole_verify($store);
hook_ok(empty($restored['links_same']), 'föregående bild är aktuell igen');
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$adaId, (int) $kept['id']]);
$back = jeab_contacts_sole_verify($store);
hook_ok(!empty($back['ok']) && !empty($back['links_same']), 'verifieringen följer den återställda bilden');

$oldDigits = '199013410077';
$newDigits = '199013410088';
$twinDigits = '199013410066';
$maja = jeab_kontakt_find_or_create([
    'name' => 'Maja Prov',
    'email' => 'maja.prov@example.test',
    'phone' => '0701000091',
    'address' => 'Majagatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($maja), 'Maja finns');
$maja['owners'] = [[
    'name' => 'Maja Prov',
    'personnummer' => '',
    'email' => 'maja.prov@example.test',
    'phone' => '0701000091',
    'address' => '',
    'contact' => true,
]];
hook_save_contact($maja);
$majaId = (string) $maja['id'];
$oldOrderRem = $reminder(['customer_name' => 'Maja Prov', 'email' => 'aldre.order@example.test', 'title' => 'Äldre']);
$newOrderRem = $reminder(['customer_name' => 'Maja Prov', 'email' => 'nyare.order@example.test', 'title' => 'Nyare']);
$ambRem = $reminder(['customer_name' => 'Maja Prov', 'email' => 'tvetydig.order@example.test', 'title' => 'Tvetydig']);
$twin = jeab_kontakt_find_or_create([
    'name' => 'Maja Prov',
    'email' => 'maja.tvilling@example.test',
    'phone' => '0701000092',
    'address' => 'Tvillinggatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($twin), 'namne finns');
$twin['owners'] = [[
    'name' => 'Maja Prov',
    'personnummer' => '',
    'email' => 'maja.tvilling@example.test',
    'phone' => '0701000092',
    'address' => '',
    'contact' => true,
]];
hook_save_contact($twin);
$twinId = (string) $twin['id'];
$twinRem = $reminder(['customer_name' => 'Maja Prov', 'email' => 'tvilling.order@example.test', 'title' => 'Namne']);
jeab_purchase_put([
    'reminder_id' => (int) $oldOrderRem['id'],
    'kind' => 'laddbox',
    'name' => 'Maja Prov',
    'contact_id' => $majaId,
    'created_at' => '2024-01-01 08:00:00',
    'updated_at' => '2024-01-01 08:00:00',
    'owners' => [[
        'name' => 'Maja Prov',
        'personnummer' => '',
        'address' => 'Oldgatan 1',
    ]],
]);
jeab_purchase_put([
    'reminder_id' => (int) $newOrderRem['id'],
    'kind' => 'laddbox',
    'name' => 'Maja Prov',
    'contact_id' => $majaId,
    'created_at' => '2026-05-01 08:00:00',
    'updated_at' => '2026-05-01 08:00:00',
    'owners' => [[
        'name' => 'Maja Prov',
        'personnummer' => $newDigits,
        'address' => 'Nygatan 9',
    ]],
]);
jeab_purchase_put([
    'reminder_id' => (int) $twinRem['id'],
    'kind' => 'laddbox',
    'name' => 'Maja Prov',
    'contact_id' => $twinId,
    'created_at' => '2026-07-01 08:00:00',
    'updated_at' => '2026-07-01 08:00:00',
    'owners' => [[
        'name' => 'Maja Prov',
        'personnummer' => $twinDigits,
        'address' => 'Tvillinggatan 2',
    ]],
]);
jeab_purchase_put([
    'reminder_id' => (int) $ambRem['id'],
    'kind' => 'laddbox',
    'name' => 'Maja Prov',
    'contact_id' => $majaId,
    'created_at' => '2026-08-01 08:00:00',
    'updated_at' => '2026-08-01 08:00:00',
    'owners' => [[
        'name' => 'Någon Annan',
        'personnummer' => '',
        'address' => 'Annan gata 3',
    ]],
]);
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$pnrDry = jeab_contacts_sole_pnr_ambiguous_resolve([], $store);
$pnrDryJson = json_encode($pnrDry, JSON_UNESCAPED_UNICODE);
$fresh = jeab_kontakt_get($majaId);
hook_ok(is_array($fresh) && hook_owner_field($fresh, 0, 'personnummer') === '' && hook_owner_field($fresh, 0, 'address') === '', 'torrkörning skriver inget på kontakten');
hook_ok(!empty($pnrDry['ok']) && !empty($pnrDry['dry']) && empty($pnrDry['written']), 'nummerkörningen är torr som standard');
hook_ok(is_string($pnrDryJson) && !str_contains($pnrDryJson, $oldDigits) && !str_contains($pnrDryJson, $newDigits) && !str_contains($pnrDryJson, $twinDigits) && !str_contains($pnrDryJson, '19901341') && !str_contains($pnrDryJson, 'personnummer') && !str_contains($pnrDryJson, 'pnr'), 'torrlistan har inte numret');
$pnrRows = is_array($pnrDry['rows'] ?? null) ? $pnrDry['rows'] : [];
hook_ok(count($pnrRows) === 1 && is_array($pnrRows[0]), 'en tvetydig rad listas');
$pnrRow = is_array($pnrRows[0] ?? null) ? $pnrRows[0] : [];
hook_ok(array_keys($pnrRow) === ['order_id', 'contact_id', 'owner_index'], 'listan har bara order, kontakt och index');
hook_ok((int) ($pnrRow['order_id'] ?? 0) === (int) $ambRem['id'] && (string) ($pnrRow['contact_id'] ?? '') === $majaId && (int) ($pnrRow['owner_index'] ?? -1) === 0, 'listan pekar på den tvetydiga ordern');

$pnrDone = jeab_contacts_sole_pnr_ambiguous_resolve(['dry' => 0], $store);
$pnrDoneJson = json_encode($pnrDone, JSON_UNESCAPED_UNICODE);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$filled = jeab_kontakt_get($majaId);
$filledPnr = is_array($filled) ? hook_owner_field($filled, 0, 'personnummer') : '';
$filledAddress = is_array($filled) ? hook_owner_field($filled, 0, 'address') : '';
$expect = jeab_contacts_sole_pnr_text($newDigits);
$twinExpect = jeab_contacts_sole_pnr_text($twinDigits);
hook_ok(!empty($pnrDone['ok']) && !empty($pnrDone['written']), 'numret skrivs på kontakten');
hook_ok(is_string($pnrDoneJson) && !str_contains($pnrDoneJson, $oldDigits) && !str_contains($pnrDoneJson, $newDigits) && !str_contains($pnrDoneJson, $twinDigits) && !str_contains($pnrDoneJson, '19901341') && !str_contains($pnrDoneJson, 'personnummer'), 'svaret har inte numret');
hook_ok($filledPnr !== '' && $filledPnr === $expect && $filledPnr !== $twinExpect, 'bara samma kontakts nyaste order fyller numret');
hook_ok($filledAddress === 'Nygatan 9', 'nyaste ordern fyller adressen');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$twinAfter = jeab_kontakt_get($twinId);
hook_ok(is_array($twinAfter) && hook_owner_field($twinAfter, 0, 'personnummer') === '', 'namnens nummer kopieras inte över');
$pnrBatch = (string) ($pnrDone['batch_id'] ?? '');
$undoPdo = $store->pdo();
$undoStmt = $undoPdo->prepare('SELECT before_json, after_json FROM contacts_sole_undo WHERE batch_id = ?');
$undoStmt->execute([$pnrBatch]);
$undoText = json_encode($undoStmt->fetchAll(), JSON_UNESCAPED_UNICODE);
hook_ok(is_string($undoText) && !str_contains($undoText, $oldDigits) && !str_contains($undoText, $newDigits) && !str_contains($undoText, '19901341') && !str_contains($undoText, 'personnummer') && str_contains($undoText, 'Nygatan 9'), 'förevärdet ligger i ångerloggen utan nummer');
$errText = is_file($errLog) ? (string) file_get_contents($errLog) : '';
hook_ok(!str_contains($errText, $oldDigits) && !str_contains($errText, $newDigits) && !str_contains($errText, '19901341'), 'felloggen har inte numret');
$pnrUndo = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $pnrBatch], $store);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$cleared = jeab_kontakt_get($majaId);
hook_ok(!empty($pnrUndo['ok']) && !empty($pnrUndo['written']) && (int) ($pnrUndo['owners'] ?? 0) === 1, 'ångern tar tillbaka ägaren');
hook_ok(is_array($cleared) && hook_owner_field($cleared, 0, 'personnummer') === '' && hook_owner_field($cleared, 0, 'address') === '', 'ångern tömmer det som fylldes');

$kimDigitsA = '199013410055';
$kimDigitsB = '199013410044';
$kim = jeab_kontakt_find_or_create([
    'name' => 'Kim Dubbel',
    'email' => 'kim.dubbel@example.test',
    'phone' => '0701000093',
    'address' => 'Kimgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($kim), 'Kim finns');
$kim['owners'] = [[
    'name' => 'Kim Dubbel',
    'personnummer' => '',
    'email' => 'kim.dubbel@example.test',
    'phone' => '0701000093',
    'address' => '',
    'contact' => true,
]];
hook_save_contact($kim);
$kimId = (string) $kim['id'];
$kimAmb = $reminder(['customer_name' => 'Kim Dubbel', 'email' => 'kim.tvetydig@example.test', 'title' => 'Kim tvetydig']);
$kimOld = $reminder(['customer_name' => 'Kim Dubbel', 'email' => 'kim.aldre@example.test', 'title' => 'Kim äldre']);
$kimNew = $reminder(['customer_name' => 'Kim Dubbel', 'email' => 'kim.nyare@example.test', 'title' => 'Kim nyare']);
jeab_purchase_put([
    'reminder_id' => (int) $kimAmb['id'],
    'kind' => 'laddbox',
    'name' => 'Kim Dubbel',
    'contact_id' => $kimId,
    'created_at' => '2026-09-01 08:00:00',
    'updated_at' => '2026-09-01 08:00:00',
    'owners' => [[
        'name' => 'Någon Annan',
        'personnummer' => '',
        'address' => 'Annan gata 8',
    ]],
]);
jeab_purchase_put([
    'reminder_id' => (int) $kimOld['id'],
    'kind' => 'laddbox',
    'name' => 'Kim Dubbel',
    'contact_id' => $kimId,
    'created_at' => '2025-01-01 08:00:00',
    'updated_at' => '2025-01-01 08:00:00',
    'owners' => [[
        'name' => 'Kim Dubbel',
        'personnummer' => $kimDigitsA,
        'address' => 'Kimgatan 1',
    ]],
]);
jeab_purchase_put([
    'reminder_id' => (int) $kimNew['id'],
    'kind' => 'laddbox',
    'name' => 'Kim Dubbel',
    'contact_id' => $kimId,
    'created_at' => '2026-02-01 08:00:00',
    'updated_at' => '2026-02-01 08:00:00',
    'owners' => [[
        'name' => 'Kim Dubbel',
        'personnummer' => $kimDigitsB,
        'address' => 'Kimgatan 9',
    ]],
]);
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$skipDry = jeab_contacts_sole_pnr_ambiguous_resolve([], $store);
$skipJson = json_encode($skipDry, JSON_UNESCAPED_UNICODE);
$skipRows = is_array($skipDry['rows'] ?? null) ? $skipDry['rows'] : [];
$kimListed = false;
foreach ($skipRows as $row) {
    if (is_array($row) && (int) ($row['order_id'] ?? 0) === (int) $kimAmb['id']) {
        $kimListed = true;
    }
}
hook_ok(!$kimListed, 'flera nummer på samma kontakt listas inte som fyllning');
$skipHits = [];
foreach ((array) ($skipDry['skipped'] ?? []) as $row) {
    if (is_array($row) && (int) ($row['order_id'] ?? 0) === (int) $kimAmb['id']) {
        $skipHits[] = $row;
    }
}
hook_ok(count($skipHits) === 1, 'tvetydiga nummer hoppas över');
$skipIds = is_array($skipHits[0]['order_ids'] ?? null) ? $skipHits[0]['order_ids'] : [];
sort($skipIds);
$expectIds = [(int) $kimOld['id'], (int) $kimNew['id']];
sort($expectIds);
hook_ok($skipIds === $expectIds && (string) ($skipHits[0]['contact_id'] ?? '') === $kimId && (int) ($skipHits[0]['owner_index'] ?? -1) === 0, 'hoppet rapporterar bara order-id');
hook_ok(is_string($skipJson) && !str_contains($skipJson, $kimDigitsA) && !str_contains($skipJson, $kimDigitsB) && !str_contains($skipJson, '19901341') && !str_contains($skipJson, 'personnummer'), 'hoppet har inte numret');
$skipWet = jeab_contacts_sole_pnr_ambiguous_resolve(['dry' => 0], $store);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$kimAfter = jeab_kontakt_get($kimId);
$kimWritten = false;
foreach ((array) ($skipWet['rows'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['contact_id'] ?? '') === $kimId) {
        $kimWritten = true;
    }
}
hook_ok(!$kimWritten && is_array($kimAfter) && hook_owner_field($kimAfter, 0, 'personnummer') === '' && hook_owner_field($kimAfter, 0, 'address') === '', 'flera nummer skriver inget på kontakten');

$emptyCidBeforeRead = (string) ($store->getReminder((int) $empty['id'])['contact_id'] ?? '');
$undoMissing = jeab_contacts_sole_undo_rows([], $store);
hook_ok(empty($undoMissing['ok']) && empty($undoMissing['written']) && ($undoMissing['rows'] ?? null) === [], 'ångerlistan kräver batch');
$undoList = jeab_contacts_sole_undo_rows(['batch_id' => $batch], $store);
$undoJson = json_encode($undoList, JSON_UNESCAPED_UNICODE);
$undoLink = null;
foreach ((array) ($undoList['rows'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    hook_ok(array_keys($row) === ['kind', 'entity', 'id', 'before_contact_id', 'after_contact_id'], 'ångerraden har bara slag, id och contact_id');
    if ((string) ($row['kind'] ?? '') === 'link' && (string) ($row['entity'] ?? '') === 'reminder' && (string) ($row['id'] ?? '') === (string) $empty['id']) {
        $undoLink = $row;
    }
}
$undoCreated = is_array($undoList['created'] ?? null) ? $undoList['created'] : [];
hook_ok(!empty($undoList['ok']) && empty($undoList['written']) && !empty($undoList['dry']), 'ångerlistan läser utan att skriva');
hook_ok(is_array($undoLink) && (string) ($undoLink['before_contact_id'] ?? 'x') === '' && (string) ($undoLink['after_contact_id'] ?? '') === (string) $bo['id'], 'länkraden visar contact_id före och efter');
hook_ok(in_array($bookingCid, $undoCreated, true) && in_array($danglingCid, $undoCreated, true), 'skapade kontakter listas som id');
$pnrList = jeab_contacts_sole_undo_rows(['batch_id' => $pnrBatch], $store);
$pnrListJson = json_encode($pnrList, JSON_UNESCAPED_UNICODE);
$bothUndo = (string) $undoJson . (string) $pnrListJson;
hook_ok(is_string($undoJson) && is_string($pnrListJson) && !str_contains($bothUndo, 'example.test') && !str_contains($bothUndo, 'Nygatan') && !str_contains($bothUndo, 'Behallgatan') && !str_contains($bothUndo, '19901341') && !str_contains($bothUndo, 'personnummer') && !str_contains($bothUndo, 'pnr_hmac'), 'ångerlistan har inga kontaktvärden');
hook_ok((string) ($store->getReminder((int) $empty['id'])['contact_id'] ?? '') === $emptyCidBeforeRead, 'ångerlistan lämnar contact_id');

$lea = jeab_kontakt_find_or_create([
    'name' => 'Lea Samma',
    'email' => 'Lea.Same@example.test',
    'phone' => '070-100 00 81',
    'address' => 'Länkgatan 5, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '111 22',
], false);
$leo = jeab_kontakt_find_or_create([
    'name' => 'Leo Annan',
    'email' => 'lea.same@example.test',
    'phone' => '+46 70 100 00 81',
    'address' => 'länkgatan  5, 11122 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($lea) && is_array($leo), 'jämförelsekontakterna finns');
$leaId = (string) $lea['id'];
$leoId = (string) $leo['id'];
$pairRem = $reminder([
    'customer_name' => 'Lea Samma',
    'email' => 'Lea.Same@example.test',
    'phone' => '070-100 00 81',
    'address' => 'Länkgatan 5, 111 22 Teststad',
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$leaId, (int) $pairRem['id']]);
jeab_purchase_put([
    'reminder_id' => (int) $pairRem['id'],
    'kind' => 'laddbox',
    'name' => 'Leo Annan',
    'email' => 'leo.annan@example.test',
    'phone' => '0701999999',
    'address' => 'Annatstalle 9, 222 33 Annat',
    'contact_id' => $leoId,
    'created_at' => $now,
    'updated_at' => $now,
]);
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$contactsBeforeCompare = count(jeab_kontakter_load());
$compare = jeab_contacts_sole_link_compare([
    'pairs' => [
        ['order:' . (int) $pairRem['id'], 'reminder:' . (int) $pairRem['id']],
        [$leaId, (string) $ada['id']],
    ],
], $store);
$compareJson = json_encode($compare, JSON_UNESCAPED_UNICODE);
$comparePairs = is_array($compare['pairs'] ?? null) ? $compare['pairs'] : [];
$orderPair = is_array($comparePairs[0] ?? null) ? $comparePairs[0] : [];
$contactPair = is_array($comparePairs[1] ?? null) ? $comparePairs[1] : [];
$orderItems = is_array($orderPair['items'] ?? null) ? $orderPair['items'] : [];
hook_ok(!empty($compare['ok']) && empty($compare['written']) && count($comparePairs) === 2, 'jämförelsen läser två par');
hook_ok((string) ($orderItems[0]['kind'] ?? '') === 'order' && (string) ($orderItems[0]['contact_id'] ?? '') === $leoId, 'ordern visar sin contact_id');
hook_ok((string) ($orderItems[1]['kind'] ?? '') === 'reminder' && (string) ($orderItems[1]['contact_id'] ?? '') === $leaId, 'påminnelsen visar sin contact_id');
hook_ok(($orderPair['same_email'] ?? null) === true && ($orderPair['same_phone'] ?? null) === true && ($orderPair['same_street'] ?? null) === true && ($orderPair['same_postcode'] ?? null) === true, 'samma kontaktfält ger ja trots olika kopia');
$adaItems = is_array($contactPair['items'] ?? null) ? $contactPair['items'] : [];
hook_ok((string) ($adaItems[0]['contact_id'] ?? '') === $leaId && (string) ($adaItems[1]['contact_id'] ?? '') === (string) $ada['id'], 'kontaktparen visar sina id');
hook_ok(($contactPair['same_email'] ?? null) === false && ($contactPair['same_phone'] ?? null) === false && ($contactPair['same_street'] ?? null) === false && ($contactPair['same_postcode'] ?? null) === true, 'bara delat postnummer ger ja');
hook_ok(is_string($compareJson) && !str_contains($compareJson, 'example.test') && !str_contains($compareJson, 'Länkgatan') && !str_contains($compareJson, 'länkgatan') && !str_contains($compareJson, '11122') && !str_contains($compareJson, '111 22') && !str_contains($compareJson, '070') && !str_contains($compareJson, '+46'), 'jämförelsen skriver inte ut värdena');
hook_ok(count(jeab_kontakter_load()) === $contactsBeforeCompare, 'jämförelsen skapar ingen kontakt');
hook_ok((string) ($store->getReminder((int) $pairRem['id'])['contact_id'] ?? '') === $leaId, 'jämförelsen lämnar länkens contact_id');

$pia = jeab_kontakt_find_or_create([
    'name' => 'Pia Färg',
    'email' => 'pia.gammal@example.test',
    'phone' => '0701000088',
    'address' => 'Gamlagatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($pia), 'Pia finns');
$pia['owners'] = [[
    'name' => 'Pia Färg',
    'email' => 'pia.gammal@example.test',
    'phone' => '0701000088',
    'personnummer' => '',
    'contact' => true,
]];
$pia['street'] = 'Gamlagatan 1';
$pia['postal_code'] = '11122';
$pia['city'] = 'Teststad';
$pia['address'] = 'Gamlagatan 1, 111 22 Teststad';
$pia['updated_at'] = '2020-01-01 08:00:00';
hook_save_contact($pia);
$piaId = (string) $pia['id'];
$piaNew = $reminder(['customer_name' => 'Pia Färg', 'email' => 'pia.ny@example.test', 'title' => 'Pia ny']);
$piaOld = $reminder(['customer_name' => 'Pia Färg', 'email' => 'pia.aldre@example.test', 'title' => 'Pia äldre']);
jeab_purchase_put([
    'reminder_id' => (int) $piaNew['id'],
    'kind' => 'laddbox',
    'name' => 'Pia Färg',
    'email' => 'pia.ny@example.test',
    'gata' => 'Nygatan 8',
    'postnummer' => '33344',
    'contact_id' => $piaId,
    'created_at' => '2026-04-01 08:00:00',
    'updated_at' => '2026-04-01 08:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $piaOld['id'],
    'kind' => 'laddbox',
    'name' => 'Pia Färg',
    'email' => 'pia.aldre@example.test',
    'postnummer' => '11122',
    'contact_id' => $piaId,
    'created_at' => '2019-01-01 08:00:00',
    'updated_at' => '2019-01-01 08:00:00',
]);
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$paintCounts = jeab_contacts_sole_counts($store);
$paintDry = jeab_contacts_sole_paint_resolve(['order_ids' => [(int) $piaNew['id'], (int) $piaOld['id']]], $store);
$paintDryJson = json_encode($paintDry, JSON_UNESCAPED_UNICODE);
$paintByKey = [];
foreach ((array) ($paintDry['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $paintByKey[(int) ($row['order_id'] ?? 0) . ':' . (string) ($row['field'] ?? '')] = (string) ($row['winner'] ?? '');
    }
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$piaStill = jeab_kontakt_get($piaId);
hook_ok(!empty($paintDry['ok']) && !empty($paintDry['dry']) && empty($paintDry['written']), 'paint är torrt som standard');
hook_ok(($paintByKey[(int) $piaNew['id'] . ':email'] ?? '') === 'order' && ($paintByKey[(int) $piaNew['id'] . ':gata'] ?? '') === 'order' && ($paintByKey[(int) $piaNew['id'] . ':postnummer'] ?? '') === 'order', 'nyare order vinner fälten');
hook_ok(($paintByKey[(int) $piaOld['id'] . ':email'] ?? '') === 'contact', 'äldre order lämnar kontakten');
hook_ok(is_array($piaStill) && hook_owner_field($piaStill, 0, 'email') === 'pia.gammal@example.test' && (string) ($piaStill['street'] ?? '') === 'Gamlagatan 1', 'torrkörning skriver inte');
hook_ok(is_string($paintDryJson) && !str_contains($paintDryJson, 'pia.ny') && !str_contains($paintDryJson, 'pia.gammal') && !str_contains($paintDryJson, 'pia.aldre') && !str_contains($paintDryJson, 'Nygatan') && !str_contains($paintDryJson, 'Gamlagatan') && !str_contains($paintDryJson, '33344') && !str_contains($paintDryJson, 'personnummer'), 'paint-listan har inga värden');
$paintOne = jeab_contacts_sole_paint_resolve(['order_ids' => [(int) $piaOld['id']]], $store);
$paintOneRows = is_array($paintOne['rows'] ?? null) ? $paintOne['rows'] : [];
hook_ok(count($paintOneRows) === 1 && (string) ($paintOneRows[0]['winner'] ?? '') === 'contact' && (int) ($paintOneRows[0]['order_id'] ?? 0) === (int) $piaOld['id'], 'order_ids filtrerar listan');
$paintWet = jeab_contacts_sole_paint_resolve(['dry' => 0, 'order_ids' => [(int) $piaNew['id'], (int) $piaOld['id']]], $store);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$piaWrote = jeab_kontakt_get($piaId);
$paintBatch = (string) ($paintWet['batch_id'] ?? '');
hook_ok(!empty($paintWet['ok']) && !empty($paintWet['written']) && empty($paintWet['dry']), 'paint skriver när ordern är nyare');
hook_ok(is_array($piaWrote) && hook_owner_field($piaWrote, 0, 'email') === 'pia.ny@example.test' && (string) ($piaWrote['street'] ?? '') === 'Nygatan 8' && (string) ($piaWrote['postal_code'] ?? '') === '33344', 'nyare orderns värden ligger på kontakten');
hook_ok(hook_owner_field(is_array($piaWrote) ? $piaWrote : [], 0, 'email') !== 'pia.aldre@example.test', 'äldre order skrev inte över mejlet');
$piaNewOrder = jeab_purchase_get((int) $piaNew['id']);
$piaOldOrder = jeab_purchase_get((int) $piaOld['id']);
hook_ok(is_array($piaNewOrder) && (string) ($piaNewOrder['contact_id'] ?? '') === $piaId && is_array($piaOldOrder) && (string) ($piaOldOrder['contact_id'] ?? '') === $piaId, 'contact_id är oförändrat');
hook_ok(jeab_contacts_sole_counts($store) === $paintCounts, 'paint ändrar inte antalet rader');
$paintList = jeab_contacts_sole_undo_rows(['batch_id' => $paintBatch], $store);
$paintListJson = json_encode($paintList, JSON_UNESCAPED_UNICODE);
$paintFields = [];
foreach ((array) ($paintList['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $paintFields[] = (string) ($row['field'] ?? '');
        hook_ok((string) ($row['before_contact_id'] ?? '') === $piaId && (string) ($row['after_contact_id'] ?? '') === $piaId, 'ångerraden har kontakt-id');
    }
}
sort($paintFields);
hook_ok($paintFields === ['email', 'gata', 'postnummer'], 'ångern har ett fält per ändring');
hook_ok(is_string($paintListJson) && !str_contains($paintListJson, 'pia.ny') && !str_contains($paintListJson, 'pia.gammal') && !str_contains($paintListJson, 'Nygatan') && !str_contains($paintListJson, 'Gamlagatan') && !str_contains($paintListJson, '33344') && !str_contains($paintListJson, 'personnummer'), 'ångerlistan har inte värdena');
$paintUndo = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $paintBatch], $store);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$piaBack = jeab_kontakt_get($piaId);
hook_ok(!empty($paintUndo['ok']) && !empty($paintUndo['written']) && (int) ($paintUndo['paints'] ?? 0) === 3, 'ångern tar tillbaka paint');
hook_ok(is_array($piaBack) && hook_owner_field($piaBack, 0, 'email') === 'pia.gammal@example.test' && (string) ($piaBack['street'] ?? '') === 'Gamlagatan 1' && (string) ($piaBack['postal_code'] ?? '') === '11122', 'ångern återställer kontaktens värden');
hook_ok(is_array($piaBack) && (string) ($piaBack['updated_at'] ?? '') === '2020-01-01 08:00:00', 'ångern lägger tillbaka updated_at');
$paintResnap = jeab_contacts_sole_resnapshot(['dry' => 0], $store);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$piaAfterSnap = jeab_kontakt_get($piaId);
$paintAgain = jeab_contacts_sole_paint_resolve(['order_ids' => [(int) $piaNew['id'], (int) $piaOld['id']]], $store);
$paintAgainWin = [];
foreach ((array) ($paintAgain['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $paintAgainWin[(int) ($row['order_id'] ?? 0) . ':' . (string) ($row['field'] ?? '')] = (string) ($row['winner'] ?? '');
    }
}
hook_ok(!empty($paintResnap['ok']) && is_array($piaAfterSnap) && (string) ($piaAfterSnap['updated_at'] ?? '') === '2020-01-01 08:00:00', 'omfotot rör inte updated_at');
hook_ok(($paintAgainWin[(int) $piaNew['id'] . ':email'] ?? '') === 'order' && ($paintAgainWin[(int) $piaNew['id'] . ':gata'] ?? '') === 'order' && ($paintAgainWin[(int) $piaNew['id'] . ':postnummer'] ?? '') === 'order', 'efter paint och ånger vinner ordern fortfarande');
hook_ok(($paintAgainWin[(int) $piaOld['id'] . ':email'] ?? '') === 'contact', 'äldre order förblir kontaktens');
$clockContact = jeab_kontakt_find_or_create([
    'name' => 'Klocka Undan',
    'email' => 'klocka.gammal@example.test',
    'phone' => '0701000092',
    'address' => 'Klockgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
hook_ok(is_array($clockContact), 'Klocka finns');
$clockContact['owners'] = [[
    'name' => 'Klocka Undan',
    'email' => 'klocka.gammal@example.test',
    'phone' => '0701000092',
    'personnummer' => '',
    'contact' => true,
]];
$clockContact['street'] = 'Klockgatan 1';
$clockContact['postal_code'] = '11122';
$clockContact['city'] = 'Teststad';
$clockContact['updated_at'] = '2026-10-23 20:16:00';
hook_save_contact($clockContact);
$clockId = (string) $clockContact['id'];
$clockOrder = $reminder(['customer_name' => 'Klocka Undan', 'email' => 'klocka.ny@example.test', 'title' => 'Klocka order']);
jeab_purchase_put([
    'reminder_id' => (int) $clockOrder['id'],
    'kind' => 'laddbox',
    'name' => 'Klocka Undan',
    'email' => 'klocka.ny@example.test',
    'contact_id' => $clockId,
    'created_at' => '2026-04-01 08:00:00',
    'updated_at' => '2026-04-01 08:00:00',
]);
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
$store->pdo()->prepare('INSERT INTO contacts_sole_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)')
    ->execute([
        'oldundo00000001',
        1,
        'paint',
        'order',
        (string) (int) $clockOrder['id'],
        json_encode(['contact_id' => $clockId, 'field' => 'email', 'slots' => ['owner_email' => 'klocka.gammal@example.test']], JSON_UNESCAPED_UNICODE),
        json_encode(['contact_id' => $clockId, 'field' => 'email', 'slots' => ['owner_email' => 'klocka.ny@example.test']], JSON_UNESCAPED_UNICODE),
        '2026-10-23 20:10:00',
    ]);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$clockDry = jeab_contacts_sole_paint_resolve(['order_ids' => [(int) $clockOrder['id']]], $store);
$clockWin = '';
foreach ((array) ($clockDry['rows'] ?? []) as $row) {
    if (is_array($row) && (string) ($row['field'] ?? '') === 'email') {
        $clockWin = (string) ($row['winner'] ?? '');
    }
}
$clockJson = json_encode($clockDry, JSON_UNESCAPED_UNICODE);
hook_ok($clockWin === 'order', 'en ångrad batch utan sparad klocka gör inte kontakten nyare än ordern');
hook_ok(is_string($clockJson) && !str_contains($clockJson, 'klocka.ny') && !str_contains($clockJson, 'klocka.gammal') && !str_contains($clockJson, 'personnummer'), 'klocklistan har inga värden');

$sol = jeab_kontakt_find_or_create([
    'name' => 'Sol Adress',
    'email' => 'sol.adress@example.test',
    'phone' => '0701000091',
    'address' => 'Solgatan 4, 177 70 Stockholm',
    'city' => 'stockholm',
    'postal_code' => '17770',
], false);
hook_ok(is_array($sol), 'Sol finns');
$sol['owners'] = [[
    'name' => 'Sol Adress',
    'email' => 'sol.adress@example.test',
    'phone' => '0701000091',
    'personnummer' => '',
    'contact' => true,
]];
$sol['street'] = 'Solgatan 4';
$sol['postal_code'] = '17770';
$sol['city'] = 'stockholm';
$sol['address'] = 'Solgatan 4, 177 70 Stockholm';
$sol['updated_at'] = '2020-02-01 08:00:00';
hook_save_contact($sol);
$solId = (string) $sol['id'];
$solStreet = $reminder(['customer_name' => 'Sol Adress', 'email' => 'sol.adress@example.test', 'title' => 'Sol gata']);
$solTown = $reminder(['customer_name' => 'Sol Adress', 'email' => 'sol.adress@example.test', 'title' => 'Sol ort']);
$solMove = $reminder(['customer_name' => 'Sol Adress', 'email' => 'sol.adress@example.test', 'title' => 'Sol flytt']);
jeab_purchase_put([
    'reminder_id' => (int) $solStreet['id'],
    'kind' => 'laddbox',
    'name' => 'Sol Adress',
    'email' => 'sol.adress@example.test',
    'address' => '  SOLGATAN   4  ',
    'contact_id' => $solId,
    'created_at' => '2026-05-01 08:00:00',
    'updated_at' => '2026-05-01 08:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $solTown['id'],
    'kind' => 'laddbox',
    'name' => 'Sol Adress',
    'email' => 'sol.adress@example.test',
    'address' => 'Solgatan 4, 177 70  sthlm',
    'contact_id' => $solId,
    'created_at' => '2026-05-02 08:00:00',
    'updated_at' => '2026-05-02 08:00:00',
]);
jeab_purchase_put([
    'reminder_id' => (int) $solMove['id'],
    'kind' => 'laddbox',
    'name' => 'Sol Adress',
    'email' => 'sol.adress@example.test',
    'address' => 'Nystigen 2',
    'contact_id' => $solId,
    'created_at' => '2026-06-01 08:00:00',
    'updated_at' => '2026-06-01 08:00:00',
]);
if (function_exists('jeab_purchase_cache_forget')) {
    jeab_purchase_cache_forget();
}
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$solContact = jeab_kontakt_get($solId);
$solView = is_array($solContact) ? jeab_contacts_sole_view_from_contact($solContact) : [];
$solStreetOrder = jeab_purchase_get((int) $solStreet['id']);
$solTownOrder = jeab_purchase_get((int) $solTown['id']);
$solMoveOrder = jeab_purchase_get((int) $solMove['id']);
hook_ok(is_array($solStreetOrder) && jeab_contacts_sole_paint_conflict_fields($solStreetOrder, $solView) === [], 'bara gata mot gata, postnummer och ort ger ingen konflikt');
hook_ok(is_array($solTownOrder) && jeab_contacts_sole_paint_conflict_fields($solTownOrder, $solView) === [], 'samma gata med annan ortstavning och postnummer med mellanslag ger ingen konflikt');
hook_ok(is_array($solMoveOrder) && jeab_contacts_sole_paint_conflict_fields($solMoveOrder, $solView) === ['address'], 'ny gata utan postnummer är en adresskonflikt');
$solDry = jeab_contacts_sole_paint_resolve(['order_ids' => [(int) $solStreet['id'], (int) $solMove['id']]], $store);
$solDryRows = [];
foreach ((array) ($solDry['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $solDryRows[(int) ($row['order_id'] ?? 0) . ':' . (string) ($row['field'] ?? '')] = (string) ($row['winner'] ?? '');
    }
}
hook_ok(($solDryRows[(int) $solMove['id'] . ':address'] ?? '') === 'order' && !isset($solDryRows[(int) $solStreet['id'] . ':address']), 'bara den nya gatan vinner');
$solCounts = jeab_contacts_sole_counts($store);
$solWet = jeab_contacts_sole_paint_resolve(['dry' => 0, 'order_ids' => [(int) $solMove['id']]], $store);
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);
$solWrote = jeab_kontakt_get($solId);
$solWetJson = json_encode($solWet, JSON_UNESCAPED_UNICODE);
hook_ok(!empty($solWet['ok']) && !empty($solWet['written']), 'ny gata skrivs');
hook_ok(is_array($solWrote) && (string) ($solWrote['street'] ?? '') === 'Nystigen 2' && (string) ($solWrote['postal_code'] ?? '') === '17770' && (string) ($solWrote['city'] ?? '') === 'stockholm', 'gatan byts och postnummer och ort blir kvar');
$solAfter = jeab_purchase_get((int) $solMove['id']);
$solAfterView = is_array($solWrote) ? jeab_contacts_sole_view_from_contact($solWrote) : [];
$solLeft = jeab_contacts_sole_paint_resolve(['order_ids' => [(int) $solMove['id']]], $store);
hook_ok(is_array($solAfter) && jeab_contacts_sole_paint_conflict_fields($solAfter, $solAfterView) === [] && is_array($solLeft['rows'] ?? null) && $solLeft['rows'] === [], 'vunnen order har inga paint_conflicts kvar');
hook_ok(jeab_contacts_sole_counts($store) === $solCounts, 'adresspaint ändrar inte antalet rader');
hook_ok(is_string($solWetJson) && !str_contains($solWetJson, 'Nystigen') && !str_contains($solWetJson, 'Solgatan') && !str_contains($solWetJson, '17770') && !str_contains($solWetJson, 'personnummer'), 'adresspaint skriver inte ut värden');

echo "contacts sole hooks ok\n";
