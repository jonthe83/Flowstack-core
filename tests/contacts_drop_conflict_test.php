<?php

declare(strict_types=1);

/**
 * contacts.drop_customer_fields: parametern conflict.
 * Saknas, tom eller block ger 409. contact_wins släpper en ren namnkonflikt i reminders,
 * droppar kolumnen och lämnar kontakten orörd. Undo lägger tillbaka radens namn från backupen.
 * Bolagskonflikt blockerar ändå. tables begränsar vilka konflikter som räknas.
 * Påhittade namn och example.test-adresser, inget personnummer.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true) && !getenv('JEAB_TEST_MYSQL_PORT')) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-conflict-' . bin2hex(random_bytes(4));
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
$oldName = 'Kopia Vinnare';
$contactName = 'Nora Konfliktsson';
$bookOld = 'Bok Avvikare';
$nora = jeab_kontakt_find_or_create([
    'name' => $contactName, 'email' => 'nora.konflikt@example.test', 'phone' => '0709000091',
    'address' => 'Droppgatan 1, 177 31 Järfälla', 'city' => 'Järfälla', 'postal_code' => '17731', 'company' => 'jarfalla',
], false, false);
cc(is_array($nora) && (string) $nora['id'] !== '', 'kontakten finns');
$noraId = (string) $nora['id'];
$cust = $store->insertCustomer('Kund Hallsson', $stamp);
$rem = $store->insertReminder([
    'title' => 'Konflikt-påminnelse', 'customer_name' => $contactName, 'email' => 'nora.konflikt@example.test',
    'phone' => '', 'address' => '', 'company' => 'jarfalla', 'contact_id' => $noraId, 'note' => 'behall-not',
    'due_at' => '2026-11-02 09:00:00', 'created_at' => $stamp, 'updated_at' => $stamp,
]);
$book = $store->insertBooking([
    'customer_id' => $cust['id'], 'customer_name' => $contactName, 'email' => 'nora.konflikt@example.test',
    'phone' => '', 'address' => '', 'notes' => 'tid-behall', 'assignees' => ['Elin'],
    'company' => 'jarfalla', 'job_type' => 'laddbox', 'contact_id' => $noraId,
    'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 11:00:00', 'status' => 'booked',
    'created_at' => $stamp, 'updated_at' => $stamp,
]);
$remId = (int) $rem['id'];
$bookId = (int) $book['id'];
cc($remId > 0 && $bookId > 0, 'raderna finns');
// Bara namnet skiljer sig. Övriga kopior är tomma eller samma som kontakten, så inget fylls.
$pdo->prepare("UPDATE reminders SET customer_name = ?, email = 'nora.konflikt@example.test', phone = '', address = '', ort = '', postal = '', company = 'jarfalla' WHERE id = ?")->execute([$oldName, $remId]);
$pdo->prepare("UPDATE bookings SET customer_name = ?, email = 'nora.konflikt@example.test', phone = '', address = '', company = 'jarfalla' WHERE id = ?")->execute([$contactName, $bookId]);
cc((string) $pdo->query('SELECT customer_name FROM reminders WHERE id = ' . $remId)->fetchColumn() === $oldName, 'raden har det gamla namnet');

$overName = [['table' => 'reminders', 'id' => $remId, 'fields' => ['name']]];
$contactBefore = jeab_kontakt_get($noraId);
cc(is_array($contactBefore), 'kontakten kan läsas');

// 1. Utan conflict, och med tomt eller block: 409, inget droppas.
$dryBlock = jeab_contacts_drop_customer_fields(['dry' => 1], $store);
$liveBlock = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
$emptyConflict = jeab_contacts_drop_customer_fields(['dry' => 0, 'conflict' => ''], $store);
$blockMode = jeab_contacts_drop_customer_fields(['dry' => 0, 'conflict' => 'block'], $store);
cc(($liveBlock['status'] ?? 0) === 409 && ($liveBlock['ok'] ?? true) === false && ($liveBlock['written'] ?? true) === false, 'utan conflict: 409');
cc(($liveBlock['conflict_mode'] ?? '') === 'block' && ($dryBlock['conflict_mode'] ?? '') === 'block', 'standardläget är block');
cc(($liveBlock['conflicts'] ?? null) === $overName && ($dryBlock['conflicts'] ?? null) === $overName, 'namnkonflikten listas');
cc(($liveBlock['conflicts_overridden'] ?? null) === [] && ($dryBlock['conflicts_overridden'] ?? null) === [], 'standardläget har tom conflicts_overridden');
cc(($emptyConflict['status'] ?? 0) === 409 && ($emptyConflict['conflicts'] ?? null) === $overName && ($emptyConflict['conflicts_overridden'] ?? null) === [], 'tom conflict är block');
cc(($blockMode['status'] ?? 0) === 409 && ($blockMode['conflicts'] ?? null) === $overName && ($blockMode['conflict_mode'] ?? '') === 'block', 'conflict=block är 409');
cc(jeab_copy_column_present($pdo, 'reminders', 'customer_name') && jeab_copy_column_present($pdo, 'bookings', 'customer_name'), '409 droppar inget');
cc(serialize(jeab_kontakt_get($noraId)) === serialize($contactBefore), '409 rör inte kontakten');

// 2. conflict=bogus: 400 även vid dry, och inget läses eller skrivs.
$dirBefore = glob(jeab_contacts_drop_dir() . '/*') ?: [];
$bogusDry = jeab_contacts_drop_customer_fields(['dry' => 1, 'conflict' => 'bogus'], $store);
$bogus = jeab_contacts_drop_customer_fields(['dry' => 0, 'conflict' => 'bogus'], $store);
cc(($bogusDry['ok'] ?? true) === false && ($bogusDry['status'] ?? 0) === 400 && ($bogusDry['error'] ?? '') === 'Okänt conflict-värde.', 'bogus med dry=1 ger 400');
cc(($bogus['ok'] ?? true) === false && ($bogus['status'] ?? 0) === 400 && ($bogus['error'] ?? '') === 'Okänt conflict-värde.' && ($bogus['written'] ?? true) === false, 'bogus ger 400');
cc(!array_key_exists('tables', $bogusDry) && !array_key_exists('conflicts', $bogusDry) && !array_key_exists('tables', $bogus), 'okänt conflict läser ingen plan');
cc((glob(jeab_contacts_drop_dir() . '/*') ?: []) === $dirBefore, 'bogus skriver inget');
cc(jeab_copy_column_present($pdo, 'reminders', 'customer_name') && jeab_copy_column_present($pdo, 'reminders', 'email'), 'bogus lämnar kolumnerna');
cc(serialize(jeab_kontakt_get($noraId)) === serialize($contactBefore), 'bogus rör inte kontakten');

// 3. contact_wins. Dry och live visar samma listor. Kontakten skrivs inte över.
$dryWins = jeab_contacts_drop_customer_fields(['dry' => 1, 'conflict' => 'contact_wins'], $store);
cc(($dryWins['ok'] ?? false) === true && ($dryWins['written'] ?? true) === false && ($dryWins['conflict_mode'] ?? '') === 'contact_wins', 'dry contact_wins skriver inte');
cc(($dryWins['conflicts'] ?? null) === [] && ($dryWins['conflicts_overridden'] ?? null) === $overName, 'dry flyttar namnkonflikten till conflicts_overridden');
cc(($dryWins['fill']['fields'] ?? -1) === 0, 'inget tomt fält ska fyllas');
cc(jeab_copy_column_present($pdo, 'reminders', 'customer_name'), 'dry droppar inget');
$run = jeab_contacts_drop_customer_fields(['dry' => 0, 'conflict' => 'contact_wins'], $store);
jeab_copy_columns_forget();
$runJson = (string) json_encode($run, JSON_UNESCAPED_UNICODE);
cc(($run['ok'] ?? false) === true && ($run['written'] ?? false) === true && ($run['dry'] ?? true) === false, 'contact_wins droppar');
cc(($run['conflict_mode'] ?? '') === 'contact_wins', 'conflict_mode är contact_wins');
cc(($run['conflicts'] ?? null) === [] && ($run['conflicts_overridden'] ?? null) === $overName, 'conflicts_overridden är reminders-namnet');
cc($run['conflicts'] === $dryWins['conflicts'] && $run['conflicts_overridden'] === $dryWins['conflicts_overridden'], 'dry visar samma listor');
cc(!str_contains($runJson, $oldName) && !str_contains($runJson, 'Vinnare'), 'svaret innehåller inte det gamla namnet');
cc(serialize(jeab_kontakt_get($noraId)) === serialize($contactBefore), 'kontakten är byte-för-byte orörd');
cc(!jeab_copy_column_present($pdo, 'reminders', 'customer_name') && !jeab_copy_column_present($pdo, 'reminders', 'email')
    && !jeab_copy_column_present($pdo, 'bookings', 'customer_name') && !jeab_copy_column_present($pdo, 'bookings', 'email'), 'kolumnerna är borta');

// Backupen (seal v3) har hela kolumnens värde, krypterat. Klartexten finns inte i filen.
$batch = (string) ($run['batch_id'] ?? '');
$rawBackup = (string) file_get_contents(jeab_contacts_drop_dir() . '/' . $batch . '.json');
$backup = json_decode($rawBackup, true);
cc(is_array($backup) && ($backup['seal'] ?? '') === 'v3' && is_string($backup['wrapped_key'] ?? null) && ($backup['wrapped_key'] ?? '') !== '', 'backupen är seal v3');
cc(!str_contains($rawBackup, $oldName) && !str_contains($rawBackup, 'Vinnare') && !str_contains($rawBackup, 'nora.konflikt'), 'backupen har ingen klartext');
$privKey = openssl_pkey_get_private($dropPair['pem']);
$privInfo = is_object($privKey) || is_array($privKey) ? openssl_pkey_get_details($privKey) : false;
$privSha = is_array($privInfo) ? jeab_contacts_drop_key_fingerprint((string) ($privInfo['key'] ?? '')) : '';
$batchKey = is_array($privInfo) ? jeab_contacts_drop_batch_key($backup, ['key' => $privKey, 'sha256' => $privSha]) : '';
$sealedName = '';
foreach ((array) ($backup['tables']['reminders']['rows'] ?? []) as $sealedRow) {
    if ((int) ($sealedRow['id'] ?? 0) === $remId) {
        $sealedName = (string) ($sealedRow['fields']['customer_name']['sealed'] ?? '');
    }
}
cc($sealedName !== '' && jeab_contacts_drop_open($sealedName, $batchKey) === $oldName, 'backupen innehåller radens gamla namn');

// 5. Undo lägger tillbaka namnet. Kontakten är fortfarande orörd.
t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
jeab_copy_columns_forget();
cc(($undo['ok'] ?? false) === true && ($undo['written'] ?? false) === true, 'undo skriver');
cc((string) $pdo->query('SELECT customer_name FROM reminders WHERE id = ' . $remId)->fetchColumn() === $oldName, 'undo lägger tillbaka det gamla namnet');
cc((string) $pdo->query('SELECT email FROM reminders WHERE id = ' . $remId)->fetchColumn() === 'nora.konflikt@example.test', 'undo lägger tillbaka mejlet');
cc((string) $pdo->query('SELECT customer_name FROM bookings WHERE id = ' . $bookId)->fetchColumn() === $contactName, 'undo lägger tillbaka bokningens namn');
cc(serialize(jeab_kontakt_get($noraId)) === serialize($contactBefore), 'kontakten är orörd efter undo');
cc(jeab_copy_column_present($pdo, 'reminders', 'customer_name') && jeab_copy_column_present($pdo, 'bookings', 'email'), 'undo lägger tillbaka kolumnerna');

// 6. Bolagskonflikt blockerar även med contact_wins.
$pdo->prepare("UPDATE reminders SET company = 'malmo' WHERE id = ?")->execute([$remId]);
$company = jeab_contacts_drop_customer_fields(['dry' => 0, 'conflict' => 'contact_wins'], $store);
cc(($company['status'] ?? 0) === 409 && ($company['ok'] ?? true) === false && ($company['written'] ?? true) === false, 'bolagskonflikt ger 409 med contact_wins');
cc(($company['conflict_mode'] ?? '') === 'contact_wins', 'läget är contact_wins');
cc(($company['conflicts'] ?? null) === [['table' => 'reminders', 'id' => $remId, 'fields' => ['company']]], 'bolaget ligger kvar i conflicts');
cc(($company['conflicts_overridden'] ?? null) === [], 'bolag åsidosätts inte');
cc(jeab_copy_column_present($pdo, 'reminders', 'customer_name'), 'bolagskonflikten droppar inget');
cc((string) $pdo->query('SELECT customer_name FROM reminders WHERE id = ' . $remId)->fetchColumn() === $oldName, 'radens namn står kvar');
cc(serialize(jeab_kontakt_get($noraId)) === serialize($contactBefore), 'bolagskonflikten rör inte kontakten');

// 4. tables=reminders,bookings. Olänkad solkampanj är en egen lista och blockerar inte.
$pdo->prepare("UPDATE reminders SET company = 'jarfalla' WHERE id = ?")->execute([$remId]);
$pdo->prepare("UPDATE bookings SET customer_name = ? WHERE id = ?")->execute([$bookOld, $bookId]);
$pdo->exec("INSERT INTO solkampanj_recipients (customer_number, namn, first_name, mejl, gata, postnr, ort, contact_id) VALUES ('901', 'Ove Okand', 'Ove', 'ove.okand@example.test', 'Mangatan 2', '177 32', 'Jarfalla', '')");
$solkId = (int) $pdo->lastInsertId();
cc($solkId > 0, 'olänkad solkampanj-rad');
$dryAll = jeab_contacts_drop_customer_fields(['dry' => 1, 'conflict' => 'contact_wins'], $store);
cc(($dryAll['ok'] ?? false) === true && ($dryAll['written'] ?? true) === false && !in_array('solkampanj_recipients', (array) ($dryAll['blocked'] ?? []), true) && !in_array('solkampanj_recipients', (array) ($dryAll['selected'] ?? []), true), 'olänkad solkampanj blockerar inte hela körningen');
cc(jeab_copy_column_present($pdo, 'reminders', 'customer_name') && jeab_solkampanj_has_column($pdo, 'namn'), 'torrkörningen droppar inget');
$overBoth = [
    ['table' => 'reminders', 'id' => $remId, 'fields' => ['name']],
    ['table' => 'bookings', 'id' => $bookId, 'fields' => ['name']],
];
$dryPart = jeab_contacts_drop_customer_fields(['dry' => 1, 'tables' => 'reminders,bookings', 'conflict' => 'contact_wins'], $store);
$part = jeab_contacts_drop_customer_fields(['dry' => 0, 'tables' => 'reminders,bookings', 'conflict' => 'contact_wins'], $store);
jeab_copy_columns_forget();
$partJson = (string) json_encode($part, JSON_UNESCAPED_UNICODE);
cc(($part['ok'] ?? false) === true && ($part['written'] ?? false) === true, 'tables och contact_wins tillsammans');
cc(($part['selected'] ?? null) === ['reminders', 'bookings'] && ($part['conflict_mode'] ?? '') === 'contact_wins', 'vald delmängd i contact_wins');
cc(($part['conflicts'] ?? null) === [] && ($part['conflicts_overridden'] ?? null) === $overBoth, 'bara valda tabellers fältkonflikter åsidosätts');
cc($part['conflicts'] === $dryPart['conflicts'] && $part['conflicts_overridden'] === $dryPart['conflicts_overridden'], 'dry visar samma listor för tables');
cc(!str_contains($partJson, $oldName) && !str_contains($partJson, $bookOld) && !str_contains($partJson, 'Ove') && !str_contains($partJson, 'Okand'), 'delsvaret har inga värden');
cc(!jeab_copy_column_present($pdo, 'reminders', 'customer_name') && !jeab_copy_column_present($pdo, 'bookings', 'customer_name'), 'valda kolumner är borta');
cc(jeab_solkampanj_has_column($pdo, 'namn') && jeab_solkampanj_has_column($pdo, 'mejl'), 'solkampanj står kvar');
cc((string) $pdo->query('SELECT namn FROM solkampanj_recipients WHERE id = ' . $solkId)->fetchColumn() === 'Ove Okand', 'solkampanj-raden är orörd');
cc(serialize(jeab_kontakt_get($noraId)) === serialize($contactBefore), 'kontakten är orörd efter tables');

echo "ALL OK\n";
