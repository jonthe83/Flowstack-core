<?php

declare(strict_types=1);

/**
 * contacts.customers_contact_key: dry skriver inget, live sätter nyckeln bara vid exakt en
 * kontakt, 409 vid ändring före låset, sha-kontroll, undo med kept, markering och
 * findCustomerByContact. Påhittade id, inga kunddata.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && !getenv('JEAB_TEST_MYSQL_PORT')) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-custkey-' . bin2hex(random_bytes(4));
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
$mysqlPort = (int) (getenv('JEAB_TEST_MYSQL_PORT') ?: 0);
$dbConfig = [
    'driver' => 'sqlite',
    'sqlite_path' => $tmp . '/app.sqlite',
    'json_path' => $tmp . '/store.json',
];
if ($mysqlPort > 0) {
    // Egen lokal MariaDB (fejkdata): en ny databas per körning, tas bort vid slut.
    $dbName = 'jeabt_' . bin2hex(random_bytes(4));
    (new PDO('mysql:host=127.0.0.1;port=' . $mysqlPort, 't', 't'))->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    // Grundschemat läggs in här: jeab_migrate_mysql hoppar över första satsen (den börjar med en kommentar).
    $boot = new PDO('mysql:host=127.0.0.1;port=' . $mysqlPort . ';dbname=' . $dbName . ';charset=utf8mb4', 't', 't');
    preg_match("/<<<'SQL'\\n(.*)\\nSQL;/s", (string) file_get_contents(dirname(__DIR__) . '/schema/mysql.php'), $m);
    $schema = (string) preg_replace('/^--.*$/m', '', $m[1] ?? '');
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        $boot->exec($stmt);
    }
    $boot = null;
    register_shutdown_function(static function () use ($mysqlPort, $dbName): void {
        (new PDO('mysql:host=127.0.0.1;port=' . $mysqlPort, 't', 't'))->exec('DROP DATABASE IF EXISTS ' . $dbName);
    });
    $dbConfig = [
        'driver' => 'mysql',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => ['host' => '127.0.0.1;port=' . $mysqlPort, 'name' => $dbName, 'user' => 't', 'pass' => 't', 'charset' => 'utf8mb4'],
    ];
}
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => $dbConfig,
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

require_once dirname(__DIR__) . '/src/customers_contact_key.php';
$store = jeab_store();
$pdo = $store->pdo();
$now = '2026-01-01 00:00:00';

$isMysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$bookingCols = [];
foreach ($pdo->query($isMysql ? 'SHOW COLUMNS FROM bookings' : 'PRAGMA table_info(bookings)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $bookingCols[] = $isMysql
        ? ['name' => (string) $c['Field'], 'notnull' => $c['Null'] === 'NO' ? 1 : 0, 'dflt_value' => $c['Default'], 'type' => (string) $c['Type'], 'auto' => str_contains((string) $c['Extra'], 'auto_increment')]
        : ['name' => (string) $c['name'], 'notnull' => (int) $c['notnull'], 'dflt_value' => $c['dflt_value'], 'type' => (string) $c['type'], 'auto' => false];
}
$book = static function (int $customerId, string $contactId) use ($pdo, $bookingCols): void {
    $vals = ['customer_id' => $customerId, 'contact_id' => $contactId, 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00'];
    foreach ($bookingCols as $c) {
        $name = $c['name'];
        if ($name !== 'id' && !$c['auto'] && $c['notnull'] === 1 && $c['dflt_value'] === null && !array_key_exists($name, $vals)) {
            $t = strtolower($c['type']);
            $vals[$name] = str_contains($t, 'int') ? 0 : (str_contains($t, 'datetime') ? '2026-01-01 00:00:00' : '');
        }
    }
    $pdo->prepare('INSERT INTO bookings (' . implode(', ', array_keys($vals)) . ') VALUES (' . implode(', ', array_fill(0, count($vals), '?')) . ')')->execute(array_values($vals));
};
$one = (int) $store->insertCustomer('Kund Ett', $now)['id'];
$two = (int) $store->insertCustomer('Kund Två', $now)['id'];
$three = (int) $store->insertCustomer('Kund Tre', $now)['id'];
$four = (int) $store->insertCustomer('Kund Fyra', $now)['id'];
$book($one, 'k-ett');
$book($one, 'k-ett');
$book($two, 'k-tva');
$book($two, 'k-annan');
$book($three, 'k-delad');
$book($four, 'k-delad');

$dry = jeab_customers_contact_key([], $store);
cc(($dry['written'] ?? true) === false && ($dry['set'] ?? 0) === 1 && ($dry['ambiguous']['ids'] ?? []) === [$two] && count($dry['shared']['ids'] ?? []) === 2, 'dry: en nyckel, en tvetydig, två delar kontakt');
cc(!jeab_customers_contact_key_has_column($pdo) && jeab_customers_contact_key_marker($pdo) === null, 'dry skriver inget');

$GLOBALS['_JEAB_CUSTKEY_BEFORE_LOCK'] = static function (PDO $pdo) use ($book): void {
    $pdo->exec("INSERT INTO customers (id, name, created_at) VALUES (9999, 'Kund Ny', '2026-01-01 00:00:00')");
    $book(9999, 'k-ny');
};
$race = jeab_customers_contact_key(['dry' => 0], $store);
unset($GLOBALS['_JEAB_CUSTKEY_BEFORE_LOCK']);
cc(($race['status'] ?? 0) === 409 && jeab_customers_contact_key_marker($pdo) === null, 'ändring före låset ger 409 och ingen markering');
cc((int) $pdo->query("SELECT COUNT(*) FROM customers WHERE contact_id <> ''")->fetchColumn() === 0, '409 sätter ingen nyckel');
$undoRace = jeab_customers_contact_key_undo(['dry' => 0, 'batch_id' => (string) $race['batch_id']], $store);
cc(($undoRace['column_dropped'] ?? false) === true && !jeab_customers_contact_key_has_column($pdo), 'undo efter 409 tar bort den tillagda kolumnen');

$run = jeab_customers_contact_key(['dry' => 0], $store);
cc(($run['ok'] ?? false) === true && ($run['set'] ?? 0) === 2 && jeab_customers_contact_key_marker($pdo) !== null, 'live sätter nycklarna och markeringen');
cc(($store->findCustomerByContact('k-ett')['id'] ?? 0) == $one && $store->findCustomerByContact('k-delad') === null && $store->findCustomerByContact('k-tva') === null, 'findCustomerByContact: exakt en träff');
$mode = fileperms(jeab_customers_contact_key_dir() . '/' . $run['batch_id'] . '.json') & 0777;
cc($mode === 0600, 'backupen är 0600');

$viaBooking = jeab_customer_for_contact($store, 'k-ett');
$fresh = jeab_customer_for_contact($store, 'k-utan-kund');
cc((int) $viaBooking['id'] === $one && (string) ($store->findCustomerByContact('k-utan-kund')['id'] ?? '') === (string) $fresh['id'], 'kund för kontakt: befintlig eller ny med nyckel');

$eva = jeab_kontakt_find_or_create(['name' => 'Eva Egen', 'email' => 'eva.egen@example.test', 'company' => 'jarfalla'], false, false);
$evaId = (string) $eva['id'];
$evaCust = jeab_customer_for_contact($store, $evaId);
$evaAgain = jeab_customer_for_contact($store, $evaId);
cc(($evaCust['name'] ?? '') === 'Eva Egen' && (int) $evaCust['id'] === (int) $evaAgain['id'], 'kunden visar kontaktens namn och samma kund kommer tillbaka');
cc((string) $pdo->query('SELECT name FROM customers WHERE id = ' . (int) $evaCust['id'])->fetchColumn() === jeab_contacts_copies_customer_label() . ' ' . $evaId, 'tabellen har bara id i namnet');
$krock = (int) $store->insertCustomer(jeab_contacts_copies_customer_label() . ' k-krock', $now)['id'];
cc((int) jeab_customer_for_contact($store, 'k-krock')['id'] === $krock && (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE contact_id = 'k-krock'")->fetchColumn() === 1, 'befintligt namn återanvänds, ingen dubblett');

$again = jeab_customers_contact_key(['dry' => 0], $store);
cc(($again['already_done'] ?? false) === true, 'andra körningen har inget att göra');

$path = jeab_customers_contact_key_dir() . '/' . $run['batch_id'] . '.json';
$orig = (string) file_get_contents($path);
file_put_contents($path, $orig . ' ');
$badSha = jeab_customers_contact_key_undo(['dry' => 0, 'batch_id' => (string) $run['batch_id']], $store);
cc(($badSha['status'] ?? 0) === 409 && ($store->findCustomerByContact('k-ett')['id'] ?? 0) == $one, 'ändrad backup ger 409 och skriver inget');
file_put_contents($path, $orig);

$pdo->prepare("UPDATE customers SET contact_id = 'k-handredigerad' WHERE id = ?")->execute([9999]);
$undo = jeab_customers_contact_key_undo(['dry' => 0, 'batch_id' => (string) $run['batch_id']], $store);
cc(($undo['ok'] ?? false) === true && ($undo['kept']['ids'] ?? []) === [9999] && ($undo['restore'] ?? 0) === 1, 'undo behåller en ändrad nyckel');
cc((string) $pdo->query('SELECT contact_id FROM customers WHERE id = ' . $one)->fetchColumn() === '' && jeab_customers_contact_key_marker($pdo) === null, 'undo tar bort nyckeln och markeringen');
cc(jeab_customers_contact_key_has_column($pdo), 'kolumnen ligger kvar när andra nycklar finns');
cc(($undo['per_contact_customers']['count'] ?? 0) === 3, 'undo räknar upp kunderna per kontakt som lämnas');

$pdo->exec($isMysql ? 'DROP INDEX customers_contact_id ON customers' : 'DROP INDEX customers_contact_id');
cc(!jeab_customers_contact_key_has_index($pdo), 'indexet saknas, kolumnen finns');
$fix = jeab_customers_contact_key(['dry' => 0], $store);
cc(($fix['ok'] ?? false) === true && jeab_customers_contact_key_has_index($pdo), 'nästa körning lägger tillbaka indexet');

echo "ALL OK\n";
