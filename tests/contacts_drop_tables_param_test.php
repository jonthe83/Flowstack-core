<?php

declare(strict_types=1);

/**
 * contacts.drop_customer_fields: parametern tables väljer vilka tabeller som tas.
 * En delmängd droppas medan en solkampanj-rad (egen lista) och en reminders-konflikt lämnas,
 * okänt namn och tables=solkampanj_recipients ger 400, senare körning tar reminders,
 * undo av första batchen rör bara den. Påhittade namn och adresser, inget personnummer.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && !getenv('JEAB_TEST_MYSQL_PORT')) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-tables-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
$errLog = $tmp . '/php-error.log';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_SOLKAMPANJ_DIR'] = $tmp . '/privat/solkampanj';
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

$root = dirname(__DIR__);
require_once $root . '/src/contacts_drop_customer_fields.php';
require_once $root . '/tests/fixtures/drop_keypair.php';
$dropPair = t_drop_keypair($tmp . '/keys');
t_drop_use_pub($dropPair);
require_once $root . '/src/solkampanj.php';
require_once $root . '/src/inkorg_store.php';
$store = jeab_store();
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('CONTACTS_STRICT=1');
$pdo = $store->pdo();
jeab_solkampanj_ensure($pdo);
jeab_solkampanj_ensure_contact($pdo);
(new JeabPdoInkorgStore($pdo))->listForCompany('jarfalla');

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
$book = $store->insertBooking([
    'customer_id' => $cust['id'], 'customer_name' => 'Ada Droppsson', 'email' => 'ada.drop@example.test',
    'phone' => '', 'address' => 'Droppgatan 1, 177 31 Järfälla', 'notes' => 'tid-behall', 'assignees' => ['Elin'],
    'company' => 'jarfalla', 'job_type' => 'laddbox', 'contact_id' => $adaId,
    'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 11:00:00', 'status' => 'booked',
    'created_at' => $stamp, 'updated_at' => $stamp,
]);
$remId = (int) $rem['id'];
$bookId = (int) $book['id'];
cc($remId > 0 && $bookId > 0, 'raderna finns');
// Kopia som skiljer sig från kontakten: konflikt om reminders väljs.
$pdo->prepare("UPDATE reminders SET customer_name = 'Kopia Droppsson', email = 'kopia.drop@example.test', address = 'Kopiegatan 9', ort = 'Järfälla', postal = '17731', phone = '0709000011' WHERE id = ?")->execute([$remId]);
$pdo->prepare("UPDATE bookings SET customer_name = 'Ada Droppsson', email = 'ada.drop@example.test', address = 'Droppgatan 1, 177 31 Järfälla', phone = '' WHERE id = ?")->execute([$bookId]);

$put = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
$put->execute(['t-l1', 'jarfalla', 'ada.drop@example.test', '2026-01-03', 'closed', $adaId, json_encode(['id' => 't-l1', 'subject' => 'Länkad'])]);

$pdo->exec("INSERT INTO solkampanj_recipients (customer_number, namn, first_name, mejl, gata, postnr, ort, contact_id) VALUES ('901', 'Ove Okand', 'Ove', 'ove.okand@example.test', 'Mangatan 2', '177 32', 'Jarfalla', '')");
$solkId = (int) $pdo->lastInsertId();
cc($solkId > 0, 'olänkad solkampanj-rad med kopior');

$remBefore = $pdo->query('SELECT customer_name, email, phone, address, ort, postal FROM reminders WHERE id = ' . $remId)->fetch(PDO::FETCH_ASSOC);
$solkBefore = $pdo->query('SELECT namn, first_name, mejl, gata FROM solkampanj_recipients WHERE id = ' . $solkId)->fetch(PDO::FETCH_ASSOC);

// tables=bookings,inkorg_list: ovald solkampanj och reminders-konflikt stoppar inte.
$run = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'bookings,inkorg_list'], $store);
jeab_copy_columns_forget();
cc(($run['ok'] ?? false) === true && ($run['written'] ?? false) === true, 'dry=0 lyckas för vald delmängd');
cc(($run['selected'] ?? null) === ['bookings', 'inkorg_list'], 'selected är bookings och inkorg_list');
cc(!jeab_copy_column_present($pdo, 'bookings', 'email') && !jeab_copy_column_present($pdo, 'bookings', 'customer_name')
    && !jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'), 'bookings- och inkorg_list-kolumnerna är borta');
cc(jeab_copy_column_present($pdo, 'reminders', 'email') && jeab_copy_column_present($pdo, 'reminders', 'customer_name')
    && jeab_solkampanj_has_column($pdo, 'mejl') && jeab_solkampanj_has_column($pdo, 'namn'), 'reminders- och solkampanj-kolumnerna står kvar');
$remAfter = $pdo->query('SELECT customer_name, email, phone, address, ort, postal FROM reminders WHERE id = ' . $remId)->fetch(PDO::FETCH_ASSOC);
$solkAfter = $pdo->query('SELECT namn, first_name, mejl, gata FROM solkampanj_recipients WHERE id = ' . $solkId)->fetch(PDO::FETCH_ASSOC);
cc($remAfter === $remBefore, 'reminders-värdena är orörda');
cc($solkAfter === $solkBefore, 'solkampanj-värdena är orörda');
cc(array_keys(jeab_copy_columns_marker($pdo)) === ['bookings', 'inkorg_list'], 'markören har bara bookings och inkorg_list');
$raw = (string) file_get_contents(jeab_contacts_drop_dir() . '/' . $run['batch_id'] . '.json');
$backup = json_decode($raw, true);
cc(is_array($backup) && array_keys($backup['tables'] ?? []) === ['bookings', 'inkorg_list'], 'backupen har bara de två tabellerna');
$again = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => ['bookings', 'inkorg_list']], $store);
cc(($again['ok'] ?? false) === true && ($again['already'] ?? false) === true, 'andra körningen av samma tabeller gör inget');
cc(array_keys(jeab_copy_columns_marker($pdo)) === ['bookings', 'inkorg_list'], 'tom todo markerar bara valda');

// Samma läge utan tables: 409 för reminders-konflikten. Solkampanj blockerar inte.
$all = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($all['status'] ?? 0) === 409 && ($all['conflicts'][0]['table'] ?? '') === 'reminders' && !in_array('solkampanj_recipients', (array) ($all['blocked'] ?? []), true) && !in_array('solkampanj_recipients', (array) ($all['selected'] ?? []), true), 'utan tables: 409 för reminders, inte för solkampanj');
cc(jeab_copy_column_present($pdo, 'reminders', 'email') && jeab_solkampanj_has_column($pdo, 'mejl'), '409 utan tables rör inget');
cc((string) $pdo->query('SELECT namn FROM solkampanj_recipients WHERE id = ' . $solkId)->fetchColumn() === 'Ove Okand', '409 lämnar solkampanj-raden');

// tables=reminders med konflikten: 409, inget tas bort.
$onlyRem = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'reminders'], $store);
cc(($onlyRem['status'] ?? 0) === 409 && ($onlyRem['blocked'] ?? []) === [] && ($onlyRem['conflicts'][0]['table'] ?? '') === 'reminders', 'tables=reminders ger 409 för konflikten');
cc(jeab_copy_column_present($pdo, 'reminders', 'email') && !jeab_copy_column_present($pdo, 'bookings', 'email'), 'konflikten tar inte bort reminders');

// tables=bookings,okand: 400 med unknown, inget skrivs (även dry=1).
$badDry = jeab_contacts_drop_customer_fields(['dry' => 1, 'tables' => 'bookings,okand'], $store);
cc(($badDry['ok'] ?? true) === false && ($badDry['status'] ?? 0) === 400 && ($badDry['error'] ?? '') === 'Okänd tabell.' && ($badDry['unknown'] ?? null) === ['okand'], 'okänt namn ger 400 även vid dry');
$badLive = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'bookings,okand'], $store);
cc(($badLive['status'] ?? 0) === 400 && ($badLive['unknown'] ?? null) === ['okand'] && ($badLive['written'] ?? true) === false, 'okänt namn ger 400 och skriver inget');
cc(jeab_copy_column_present($pdo, 'reminders', 'email') && jeab_solkampanj_has_column($pdo, 'mejl'), '400 rör inga kolumner');
cc(array_keys(jeab_copy_columns_marker($pdo)) === ['bookings', 'inkorg_list'], '400 rör inte markören');

// tables=solkampanj_recipients är okänd, även tillsammans med en riktig tabell.
$badSolk = jeab_contacts_drop_customer_fields(['dry' => 1, 'tables' => 'solkampanj_recipients'], $store);
cc(($badSolk['ok'] ?? true) === false && ($badSolk['status'] ?? 0) === 400 && ($badSolk['error'] ?? '') === 'Okänd tabell.' && ($badSolk['unknown'] ?? null) === ['solkampanj_recipients'], 'solkampanj_recipients ger 400');
$badMix = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'reminders,solkampanj_recipients'], $store);
cc(($badMix['status'] ?? 0) === 400 && ($badMix['unknown'] ?? null) === ['solkampanj_recipients'] && ($badMix['written'] ?? true) === false, 'solkampanj_recipients skriver inget');
cc(jeab_copy_column_present($pdo, 'reminders', 'email') && (string) $pdo->query('SELECT mejl FROM solkampanj_recipients WHERE id = ' . $solkId)->fetchColumn() === 'ove.okand@example.test', '400 lämnar reminders och solkampanj');

// Rätta konflikten. Solkampanj-raden får ligga kvar. Bara reminders droppas.
$pdo->prepare("UPDATE reminders SET customer_name = 'Ada Droppsson', email = 'ADA.drop@example.test', address = 'Droppgatan 1', ort = 'Järfälla', postal = '177 31', phone = '0709000011' WHERE id = ?")->execute([$remId]);
$rest = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'reminders'], $store);
jeab_copy_columns_forget();
cc(($rest['ok'] ?? false) === true && ($rest['selected'] ?? null) === ['reminders'], 'resten droppas');
cc(!jeab_copy_column_present($pdo, 'reminders', 'email') && jeab_solkampanj_has_column($pdo, 'mejl') && jeab_solkampanj_has_column($pdo, 'namn'), 'reminders är borta, solkampanj står kvar');
cc((string) $pdo->query('SELECT namn FROM solkampanj_recipients WHERE id = ' . $solkId)->fetchColumn() === 'Ove Okand', 'solkampanj-värdet står kvar efter reminders');
$mark = jeab_copy_columns_marker($pdo);
cc(isset($mark['bookings'], $mark['inkorg_list'], $mark['reminders']) && !isset($mark['solkampanj_recipients']), 'markören har de tre tabellerna, inte solkampanj');

// Undo av första batchen lägger bara tillbaka bookings och inkorg_list.
t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => (string) $run['batch_id']], $store);
jeab_copy_columns_forget();
cc(($undo['ok'] ?? false) === true && jeab_copy_column_present($pdo, 'bookings', 'email') && jeab_copy_column_present($pdo, 'inkorg_list', 'customer_email'), 'undo lägger tillbaka bookings och inkorg_list');
cc(!jeab_copy_column_present($pdo, 'reminders', 'email') && jeab_solkampanj_has_column($pdo, 'mejl'), 'undo rör inte reminders eller solkampanj');
cc((string) $pdo->query('SELECT email FROM bookings WHERE id = ' . $bookId)->fetchColumn() === 'ada.drop@example.test', 'undo återställer bokningens e-post');
cc((string) $pdo->query("SELECT customer_email FROM inkorg_list WHERE id = 't-l1'")->fetchColumn() === 'ada.drop@example.test', 'undo återställer trådens e-post');
cc((string) $pdo->query('SELECT gata FROM solkampanj_recipients WHERE id = ' . $solkId)->fetchColumn() === 'Mangatan 2', 'undo rör inte solkampanj-värdet');
$mark = jeab_copy_columns_marker($pdo);
cc(!isset($mark['bookings']) && !isset($mark['inkorg_list']) && isset($mark['reminders']) && !isset($mark['solkampanj_recipients']), 'markören för de tabellerna tas bort');

echo "ALL OK\n";
