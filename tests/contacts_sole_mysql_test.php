<?php

declare(strict_types=1);

/**
 * kontakter.sole dry=0 på MySQL. Påhittade namn. Inga personnummer.
 * JSON-läget täcks av contacts_sole_test.php.
 */

$mariadb = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
$mariadbd = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
if ($mariadb === '' || $mariadbd === '') {
    echo "SKIP MariaDB saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sole-mysql-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$port = 33000 + random_int(0, 2000);
$data = $tmp . '/db';
$sock = $tmp . '/mysql.sock';
$pidFile = $tmp . '/mysql.pid';
$init = $tmp . '/init.sql';
$log = $tmp . '/mysql.log';
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

function sole_mysql_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
sole_mysql_ok($store instanceof JeabPdoStore && $store->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'lagret är MySQL');
sole_mysql_ok(jeab_settings_put($store->pdo(), 'mysql', JEAB_KONTAKTER_STORAGE_KEY, 'sql'), 'kontakterna ligger i MySQL');
sole_mysql_ok(jeab_kontakter_writes_sql_only(), 'kontaktskrivning går till SQL');

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
sole_mysql_ok(is_array($kept) && is_array($byMail) && is_array($byPhone) && is_array($byAddress), 'fyra kontakter finns före migreringen');

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
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([(string) $kept['id'], (int) $keptRow['id']]);
$mailRow = $reminder([
    'customer_name' => 'Kopia Mejl',
    'email' => 'bo.mejl@example.test',
    'phone' => '0701888888',
    'address' => 'Felgatan 1',
]);
$phoneRow = $reminder(['customer_name' => 'Kopia Telefon', 'email' => 'fel@example.test', 'phone' => '0701000003', 'address' => 'Felgatan 2']);
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
]);
$store->pdo()->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_saknas', (int) $dangling['id']]);
$orderReminder = $reminder(['customer_name' => 'Orderkopia', 'email' => 'order.kopia@example.test', 'title' => 'Order']);
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
$closedReminder = $reminder(['customer_name' => 'Stängd kopia', 'email' => 'stangd.kopia@example.test', 'title' => 'Stängd']);
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
$keptStamp = (string) ($store->getReminder((int) $keptRow['id'])['updated_at'] ?? '');
$orderStamp = (string) (jeab_purchase_get((int) $orderReminder['id'])['updated_at'] ?? '');
$bookingStamp = (string) ($store->getBooking((int) $booking['id'])['updated_at'] ?? '');

$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$early = jeab_contacts_sole_verify($store);
sole_mysql_ok(empty($early['ok']) && (string) ($early['error'] ?? '') === 'Före-ögonblicksbilden saknas.', 'verifiering utan sparad före-bild är inte ok');
$dry = jeab_contacts_sole_migrate([], $store);
sole_mysql_ok(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']), 'torrkörning skriver inget');
sole_mysql_ok(jeab_contacts_sole_cid($store->getReminder((int) $mailRow['id'])['contact_id'] ?? null) === '', 'torrkörning sätter inget contact_id');
$dryBroken = false;
foreach ((array) ($dry['deviations'] ?? []) as $deviation) {
    if (is_array($deviation) && (string) ($deviation['contact_id'] ?? '') === 'kt_saknas' && (string) ($deviation['reason'] ?? '') === 'saknad') {
        $dryBroken = true;
    }
}
sole_mysql_ok($dryBroken, 'torrkörningen listar den trasiga länken');

$done = jeab_contacts_sole_migrate(['dry' => 0], $store);
$encoded = json_encode($done, JSON_UNESCAPED_UNICODE);
sole_mysql_ok(is_string($encoded) && !str_contains($encoded, 'personnummer'), 'svaret innehåller inga personnummer');
sole_mysql_ok(!empty($done['ok']) && !empty($done['written']) && !empty($done['ids_same']), 'migreringen behåller varje rad');
sole_mysql_ok(jeab_contacts_sole_counts($store) === $before, 'antalet är oförändrat');
sole_mysql_ok(jeab_contacts_sole_ids($store) === $beforeIds, 'id-listorna är oförändrade');
sole_mysql_ok(jeab_contacts_sole_empty($store) === ['reminders' => 0, 'orders' => 0, 'bookings' => 0], 'ingen tom contact_id');
$verify = jeab_contacts_sole_verify($store);
sole_mysql_ok(!empty($verify['ok']) && !empty($verify['match']) && !empty($verify['links_same']), 'verifieringen läser den sparade före-bilden');
$broken = false;
foreach ((array) ($done['deviations'] ?? []) as $deviation) {
    if (is_array($deviation) && (string) ($deviation['contact_id'] ?? '') === 'kt_saknas') {
        $broken = true;
    }
}
sole_mysql_ok($broken, 'befintlig trasig länk rapporteras och skrivs inte över');
sole_mysql_ok((string) ($store->getReminder((int) $keptRow['id'])['contact_id'] ?? '') === (string) $kept['id'], 'befintlig länk består');
sole_mysql_ok((string) ($store->getReminder((int) $mailRow['id'])['contact_id'] ?? '') === (string) $byMail['id'], 'samma mejl återanvänder kontakten');
sole_mysql_ok((string) ($store->getReminder((int) $mailRow['id'])['customer_name'] ?? '') === 'Kopia Mejl', 'mejlträffen skriver inte om kopian');
sole_mysql_ok((string) ($store->getReminder((int) $phoneRow['id'])['contact_id'] ?? '') === (string) $byPhone['id'], 'samma telefon återanvänder kontakten');
$addressLinked = (string) ($store->getReminder((int) $addressRow['id'])['contact_id'] ?? '');
sole_mysql_ok($addressLinked !== '' && $addressLinked !== (string) $byAddress['id'], 'samma adress med annan e-post blir en egen kontakt');
$createdAfter = $store->getReminder((int) $createRow['id']);
sole_mysql_ok(trim((string) ($createdAfter['contact_id'] ?? '')) !== '' && (string) ($createdAfter['customer_name'] ?? '') === 'Eja Ny', 'ny rad får en kontakt och behåller kopian');
sole_mysql_ok((string) ($store->getReminder((int) $dangling['id'])['contact_id'] ?? '') === 'kt_saknas', 'trasig länk lämnas orörd');
sole_mysql_ok((string) ($store->getReminder((int) $mailRow['id'])['updated_at'] ?? '') === $mailStamp, 'påminnelsens updated_at rörs inte');
sole_mysql_ok((string) ($store->getReminder((int) $keptRow['id'])['updated_at'] ?? '') === $keptStamp, 'länkad påminnelse behåller updated_at');
$orderAfter = jeab_purchase_get((int) $orderReminder['id']);
sole_mysql_ok(is_array($orderAfter) && (string) ($orderAfter['updated_at'] ?? '') === $orderStamp, 'orderns updated_at rörs inte');
sole_mysql_ok(is_array($orderAfter) && (string) ($orderAfter['contact_id'] ?? '') === (string) $byMail['id'], 'ordern matchar mejlet');
sole_mysql_ok((string) ($orderAfter['name'] ?? '') === 'Kopia Order', 'orderns kopia består');
$closedAfter = jeab_purchase_get((int) $closedReminder['id']);
sole_mysql_ok(is_array($closedAfter) && trim((string) ($closedAfter['contact_id'] ?? '')) !== '' && (string) ($closedAfter['closed_at'] ?? '') === $now, 'stängd order får contact_id och finns kvar');
$bookingAfter = $store->getBooking((int) $booking['id']);
sole_mysql_ok(is_array($bookingAfter) && trim((string) ($bookingAfter['contact_id'] ?? '')) !== '' && (string) ($bookingAfter['starts_at'] ?? '') === '2026-11-02 09:00:00', 'bokningen behåller tiden och får contact_id');
sole_mysql_ok((string) ($bookingAfter['updated_at'] ?? '') === $bookingStamp, 'bokningens updated_at rörs inte');
sole_mysql_ok(!is_file($tmp . '/contacts_sole_undo.json'), 'ångerloggen är inte en JSON-fil');

$again = jeab_contacts_sole_migrate(['dry' => 0], $store);
sole_mysql_ok(!empty($again['ok']) && (int) ($again['created'] ?? -1) === 0 && (int) ($again['linked'] ?? -1) === 0, 'andra körningen skapar inget nytt');

$batch = (string) ($done['batch_id'] ?? '');
$undoDry = jeab_contacts_sole_undo(['batch_id' => $batch], $store);
sole_mysql_ok(!empty($undoDry['ok']) && !empty($undoDry['dry']) && empty($undoDry['written']) && (int) ($undoDry['links'] ?? 0) > 0 && (int) ($undoDry['contacts'] ?? 0) > 0, 'ångern är torr som standard');
sole_mysql_ok((string) ($store->getReminder((int) $mailRow['id'])['contact_id'] ?? '') === (string) $byMail['id'], 'torr ånger skriver inte');
$undo = jeab_contacts_sole_undo(['dry' => 0, 'batch_id' => $batch], $store);
sole_mysql_ok(!empty($undo['ok']) && !empty($undo['written']), 'ångern skriver');
sole_mysql_ok(jeab_contacts_sole_cid($store->getReminder((int) $mailRow['id'])['contact_id'] ?? null) === '', 'ångern tömmer contact_id som migreringen satte');
sole_mysql_ok((string) ($store->getReminder((int) $mailRow['id'])['updated_at'] ?? '') === $mailStamp, 'ångern rör inte updated_at');
sole_mysql_ok((string) ($store->getReminder((int) $keptRow['id'])['contact_id'] ?? '') === (string) $kept['id'], 'ångern lämnar den äldre länken');
sole_mysql_ok((string) ($store->getReminder((int) $dangling['id'])['contact_id'] ?? '') === 'kt_saknas', 'ångern lämnar avvikelsen');
$createdId = (string) ($createdAfter['contact_id'] ?? '');
sole_mysql_ok($createdId !== '' && jeab_kontakt_get($createdId) === null, 'ångern tar bort kontakten som körningen skapade');
$log = $store->pdo()->query('SELECT batch_id, before_json, after_json FROM contacts_sole_undo')->fetchAll();
$logText = json_encode($log, JSON_UNESCAPED_UNICODE);
sole_mysql_ok(is_string($logText) && $logText !== '' && !str_contains($logText, 'personnummer') && str_contains($logText, $batch), 'ångerloggen i MySQL har före- och eftervärde utan personnummer');
sole_mysql_ok(is_array($store->getReminder((int) $createRow['id'])) && is_array(jeab_purchase_get((int) $orderReminder['id'])) && is_array($store->getBooking((int) $booking['id'])), 'ångern lämnar påminnelse, order och bokning');

echo "contacts sole mysql ok\n";
