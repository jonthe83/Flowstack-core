<?php

declare(strict_types=1);

/**
 * Insert utan AUTO_INCREMENT (MySQL-liknande INT-nyckel) ska ge id > 0.
 * reminders.zero_id_check läser id 0 och tomma titlar runt 12:53 och raderar inget.
 * Påhittade kunder. Ingenting skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-zero-id-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function zid(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function zid_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE reminder_lists (id INT NOT NULL PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('INSERT INTO reminder_lists (id, name, created_at) VALUES (1, \'Påminnelser\', \'2026-01-01 00:00:00\')');
    $pdo->exec(
        'CREATE TABLE reminders (
            id INT NOT NULL PRIMARY KEY,
            list_id INT NOT NULL,
            title TEXT NOT NULL,
            customer_name TEXT,
            email TEXT,
            phone TEXT,
            address TEXT,
            ort TEXT,
            postal TEXT,
            assignee TEXT,
            assignees TEXT,
            company TEXT,
            person TEXT,
            flagged INT NOT NULL DEFAULT 0,
            tags TEXT,
            note TEXT,
            due_at TEXT,
            completed_at TEXT,
            booking_id INT,
            thread_id TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE TABLE customers (id INT NOT NULL PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec(
        'CREATE TABLE bookings (
            id INT NOT NULL PRIMARY KEY,
            customer_id INT NOT NULL,
            customer_name TEXT NOT NULL,
            email TEXT,
            phone TEXT,
            address TEXT,
            notes TEXT,
            assignee TEXT NOT NULL,
            assignees TEXT,
            company TEXT,
            job_type TEXT,
            starts_at TEXT NOT NULL,
            ends_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'booked\',
            contact_id TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    return $pdo;
}

$plain = new PDO('sqlite::memory:');
$plain->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$plain->exec('CREATE TABLE reminders (id INT NOT NULL PRIMARY KEY, title TEXT)');
zid(jeab_sql_id_generates($plain, 'reminders') === false, 'INT PRIMARY KEY genererar inget id');
unset($plain);
gc_collect_cycles();

$auto = new PDO('sqlite::memory:');
$auto->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auto->exec('CREATE TABLE reminders (id INTEGER PRIMARY KEY, title TEXT)');
zid(jeab_sql_id_generates($auto, 'reminders') === true, 'INTEGER PRIMARY KEY genererar id');
$auto->exec('INSERT INTO reminders (title) VALUES (\'via databas\')');
$generated = (int) $auto->lastInsertId();
zid($generated > 0, 'INTEGER PRIMARY KEY sätter id via lastInsertId');
$viaStore = (new JeabPdoStore($auto));
$auto->exec('CREATE TABLE reminder_lists (id INTEGER PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL)');
$auto->exec('INSERT INTO reminder_lists (id, name, created_at) VALUES (1, \'Påminnelser\', \'2026-01-01 00:00:00\')');
$auto->exec('ALTER TABLE reminders ADD COLUMN list_id INT NOT NULL DEFAULT 1');
$auto->exec('ALTER TABLE reminders ADD COLUMN customer_name TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN email TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN phone TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN address TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN ort TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN postal TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN assignee TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN assignees TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN company TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN person TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN flagged INT NOT NULL DEFAULT 0');
$auto->exec('ALTER TABLE reminders ADD COLUMN tags TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN note TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN due_at TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN completed_at TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN booking_id INT');
$auto->exec('ALTER TABLE reminders ADD COLUMN thread_id TEXT');
$auto->exec('ALTER TABLE reminders ADD COLUMN created_at TEXT NOT NULL DEFAULT \'\'');
$auto->exec('ALTER TABLE reminders ADD COLUMN updated_at TEXT NOT NULL DEFAULT \'\'');
$stored = $viaStore->insertReminder([
    'title' => 'Genererad',
    'created_at' => '2026-10-30 13:20:00',
    'updated_at' => '2026-10-30 13:20:00',
]);
zid((int) ($stored['id'] ?? 0) === (int) $auto->lastInsertId() && (int) ($stored['id'] ?? 0) > $generated, 'insert på INTEGER PRIMARY KEY använder lastInsertId');

$alter = jeab_sql_autoincrement_sql('reminders', 'int unsigned', 'NOT NULL', 880002);
zid($alter['seed'] === 'ALTER TABLE `reminders` AUTO_INCREMENT = 880002', 'AUTO_INCREMENT startar över MAX(id)');
zid(str_contains($alter['modify'], 'AUTO_INCREMENT') && !str_contains($alter['modify'], 'DELETE') && !str_contains($alter['seed'], 'UPDATE'), 'ALTER lägger bara AUTO_INCREMENT');
$again = jeab_sql_autoincrement_sql('reminders', 'int unsigned', 'NOT NULL', 880002);
zid($again === $alter, 'ALTER-texten är idempotent');

$mysqlReminders = (string) file_get_contents(dirname(__DIR__) . '/src/migrate/reminders.php');
$mysqlBookings = (string) file_get_contents(dirname(__DIR__) . '/src/migrate/bookings.php');
$mysqlCustomers = (string) file_get_contents(dirname(__DIR__) . '/src/migrate/customers.php');
$mysqlLists = (string) file_get_contents(dirname(__DIR__) . '/src/migrate/reminder_lists.php');
zid(str_contains($mysqlReminders, 'id INT UNSIGNED NOT NULL AUTO_INCREMENT'), 'migrate reminders har AUTO_INCREMENT');
zid(str_contains($mysqlBookings, 'id INT UNSIGNED NOT NULL AUTO_INCREMENT'), 'migrate bookings har AUTO_INCREMENT');
zid(str_contains($mysqlCustomers, 'id INT UNSIGNED NOT NULL AUTO_INCREMENT'), 'migrate customers har AUTO_INCREMENT');
zid(str_contains($mysqlLists, 'id INT UNSIGNED NOT NULL AUTO_INCREMENT'), 'migrate reminder_lists har AUTO_INCREMENT');
zid(str_contains((string) file_get_contents(dirname(__DIR__) . '/schema/mysql.php'), 'id INT UNSIGNED NOT NULL AUTO_INCREMENT'), 'schema/mysql.php har redan AUTO_INCREMENT');

$pdo = zid_pdo();
zid(jeab_sql_ensure_autoincrement($pdo, 'reminders') === false, 'sqlite kör ingen ALTER');
$pdo->exec("INSERT INTO reminders (id, list_id, title, created_at, updated_at) VALUES (0, 1, '', '2026-10-30 12:53:01', '2026-10-30 12:53:01')");
$pdo->exec("INSERT INTO reminders (id, list_id, title, customer_name, created_at, updated_at) VALUES (4, 1, 'Gammal', 'Kim Gammal', '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
$pdo->exec("INSERT INTO reminders (id, list_id, title, created_at, updated_at) VALUES (8, 1, '', '2026-10-30 12:50:00', '2026-10-30 12:50:00')");
$pdo->exec("INSERT INTO reminders (id, list_id, title, customer_name, created_at, updated_at) VALUES (9, 1, 'Ring kunden', 'Kim Frisk', '2026-10-30 12:53:00', '2026-10-30 12:53:00')");
$pdo->exec("INSERT INTO customers (id, name, created_at) VALUES (3, 'Kim Finns', '2026-01-02 08:00:00')");
$pdo->exec("INSERT INTO bookings (id, customer_id, customer_name, assignee, starts_at, ends_at, status, created_at, updated_at) VALUES (2, 3, 'Kim Finns', 'Roberto', '2026-04-01 09:00:00', '2026-04-01 11:00:00', 'booked', '2026-02-01 10:00:00', '2026-02-01 10:00:00')");

$store = new JeabPdoStore($pdo);
$beforeIds = $pdo->query('SELECT id FROM reminders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$created = $store->insertReminder([
    'title' => 'Ny påminnelse',
    'customer_name' => 'Kim Ny',
    'company' => 'jarfalla',
    'created_at' => '2026-10-30 13:10:00',
    'updated_at' => '2026-10-30 13:10:00',
]);
zid(is_array($created) && (int) ($created['id'] ?? 0) === 10, 'insert utan AUTO_INCREMENT ger id 10');
zid((string) ($created['title'] ?? '') === 'Ny påminnelse', 'den nya raden går att läsa');
$idsAfter = $pdo->query('SELECT id FROM reminders ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
zid($idsAfter === [0, 4, 8, 9, 10], 'befintliga id ligger kvar, inklusive 0');
zid($beforeIds === [0, 4, 8, 9], 'fröet hade id 0, 4, 8 och 9');
$kept = $pdo->query('SELECT title, created_at FROM reminders WHERE id = 4')->fetch(PDO::FETCH_ASSOC);
zid(is_array($kept) && ($kept['title'] ?? '') === 'Gammal' && ($kept['created_at'] ?? '') === '2026-01-01 08:00:00', 'gammal rad är oförändrad');

$customer = $store->insertCustomer('Kim Till', '2026-10-30 13:11:00');
zid((int) ($customer['id'] ?? 0) === 4, 'kund utan AUTO_INCREMENT får nästa id');
zid((int) $pdo->query('SELECT id FROM customers WHERE name = \'Kim Finns\'')->fetchColumn() === 3, 'befintlig kund behåller id');

$booking = $store->insertBooking([
    'customer_id' => 3,
    'customer_name' => 'Kim Till',
    'assignee' => 'Roberto',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'starts_at' => '2026-05-02 09:00:00',
    'ends_at' => '2026-05-02 11:00:00',
    'created_at' => '2026-10-30 13:12:00',
    'updated_at' => '2026-10-30 13:12:00',
]);
zid((int) ($booking['id'] ?? 0) === 3, 'bokning utan AUTO_INCREMENT får nästa id');
$oldBooking = $pdo->query('SELECT starts_at, ends_at FROM bookings WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
zid(is_array($oldBooking) && ($oldBooking['starts_at'] ?? '') === '2026-04-01 09:00:00' && ($oldBooking['ends_at'] ?? '') === '2026-04-01 11:00:00', 'befintlig bokningstid ligger kvar');

$intList = new PDO('sqlite::memory:');
$intList->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$intList->exec('CREATE TABLE reminder_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, created_at TEXT NOT NULL)');
$intList->exec('CREATE TABLE reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, list_id INT NOT NULL, title TEXT NOT NULL, customer_name TEXT, email TEXT, phone TEXT, address TEXT, ort TEXT, postal TEXT, assignee TEXT, assignees TEXT, company TEXT, person TEXT, flagged INT NOT NULL DEFAULT 0, tags TEXT, note TEXT, due_at TEXT, completed_at TEXT, booking_id INT, thread_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$intList->exec('INSERT INTO reminder_lists (id, name, created_at) VALUES (1, \'Påminnelser\', \'2026-01-01 00:00:00\')');
$autoStore = new JeabPdoStore($intList);
$autoRow = $autoStore->insertReminder([
    'title' => 'Auto',
    'created_at' => '2026-10-30 13:20:00',
    'updated_at' => '2026-10-30 13:20:00',
]);
zid((int) ($autoRow['id'] ?? 0) > 0, 'INTEGER PRIMARY KEY ger fortfarande id > 0');

$denied = jeab_reminder_admin_dispatch($store, ['action' => 'reminders.zero_id_check']);
zid(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'zero_id_check kräver token');
zid((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === 5, 'nekad hook raderar inget');

$token = 'ink_zero_id_test_token_value_32b';
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $token]));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $token;
$countBeforeHook = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();
$report = jeab_reminders_zero_id_check($store, []);
zid(($report['ok'] ?? false) === true && ($report['read_only'] ?? false) === true && (int) ($report['deleted'] ?? 1) === 0, 'hooken är läsande');
zid((int) ($report['count_before'] ?? -1) === $countBeforeHook && (int) ($report['count_after'] ?? -1) === $countBeforeHook, 'antalet rader är oförändrat');
zid((string) ($report['window']['center'] ?? '') === '2026-10-30 12:53:00', 'fönstret sitter på 12:53 den 30 oktober');
$listed = [];
foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
    if (is_array($row)) {
        $listed[(int) ($row['id'] ?? -1)] = $row['why'] ?? [];
    }
}
zid(isset($listed[0]) && in_array('zero_id', $listed[0], true), 'id 0 listas');
zid(isset($listed[8]) && in_array('empty_title', $listed[8], true), 'tom titel i fönstret listas');
zid(!isset($listed[9]) && !isset($listed[4]) && !isset($listed[10]), 'hela rader och rader utanför fönstret listas inte');
zid((int) $pdo->query('SELECT COUNT(*) FROM reminders WHERE id = 0')->fetchColumn() === 1, 'id 0 finns kvar efter hooken');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
zid(str_contains($hook, "=== 'reminders.zero_id_check'"), 'hook.php tar reminders.zero_id_check');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/reminder_admin.php');
zid(str_contains($src, 'function jeab_reminders_zero_id_rows') && !str_contains($src, 'DELETE FROM reminders'), 'kontrollen raderar inga påminnelser');
zid((int) ($GLOBALS['_ADMIN_SENT'] ?? 0) === 0, 'inget skickades');

$store->deleteReminders([10]);
$afterDelete = $store->insertReminder([
    'title' => 'Efter radering',
    'customer_name' => 'Kim Igen',
    'company' => 'jarfalla',
    'created_at' => '2026-10-30 13:20:00',
    'updated_at' => '2026-10-30 13:20:00',
]);
zid((int) ($afterDelete['id'] ?? 0) === 11, 'raderat id 10 återanvänds inte');
$still = $pdo->query('SELECT id FROM reminders WHERE id IN (0, 4, 8, 9) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
zid($still === [0, 4, 8, 9], 'raderingen rör inte de äldre raderna');
$keptAfter = $pdo->query('SELECT title FROM reminders WHERE id = 4')->fetchColumn();
zid($keptAfter === 'Gammal', 'id 4 har samma titel');

$jsonPath = $tmp . '/json-store.json';
file_put_contents($jsonPath, json_encode([
    'next_id' => ['reminder_lists' => 2, 'customers' => 1, 'bookings' => 1, 'reminders' => 3],
    'reminder_lists' => [['id' => 1, 'name' => 'Påminnelser', 'created_at' => '2026-01-01 00:00:00']],
    'customers' => [],
    'bookings' => [],
    'reminders' => [
        ['id' => 1, 'list_id' => 1, 'title' => 'Kvar', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        ['id' => 5, 'list_id' => 1, 'title' => 'Hög', 'created_at' => '2026-01-02 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
    ],
    'settings' => [],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$GLOBALS['_JEAB_LEGACY_JSON_WRITES'] = true;
$jsonStore = new JeabJsonStore($jsonPath);
$jsonFirst = $jsonStore->insertReminder([
    'title' => 'Json ny',
    'created_at' => '2026-10-30 13:30:00',
    'updated_at' => '2026-10-30 13:30:00',
]);
zid((int) ($jsonFirst['id'] ?? 0) === 6, 'json-läge hoppar över id som redan finns');
zid($jsonStore->deleteReminders([6]) === 1, 'json-raden går att radera');
$jsonSecond = $jsonStore->insertReminder([
    'title' => 'Json efter',
    'created_at' => '2026-10-30 13:31:00',
    'updated_at' => '2026-10-30 13:31:00',
]);
zid((int) ($jsonSecond['id'] ?? 0) === 7, 'json-läge återanvänder inte raderat id');
$jsonRaw = json_decode((string) file_get_contents($jsonPath), true);
$jsonById = [];
foreach (is_array($jsonRaw['reminders'] ?? null) ? $jsonRaw['reminders'] : [] as $jsonRow) {
    if (is_array($jsonRow)) {
        $jsonById[(int) ($jsonRow['id'] ?? 0)] = (string) ($jsonRow['title'] ?? '');
    }
}
zid(($jsonById[1] ?? '') === 'Kvar' && ($jsonById[5] ?? '') === 'Hög' && !isset($jsonById[6]), 'json lämnar de gamla raderna och tar inte tillbaka id 6');

$fileDb = $tmp . '/race.sqlite';
$race = new PDO('sqlite:' . $fileDb);
$race->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$race->exec('CREATE TABLE reminder_lists (id INT NOT NULL PRIMARY KEY, name TEXT NOT NULL, created_at TEXT NOT NULL)');
$race->exec("INSERT INTO reminder_lists (id, name, created_at) VALUES (1, 'Påminnelser', '2026-01-01 00:00:00')");
$race->exec(
    'CREATE TABLE reminders (
        id INT NOT NULL PRIMARY KEY,
        list_id INT NOT NULL,
        title TEXT NOT NULL,
        customer_name TEXT, email TEXT, phone TEXT, address TEXT, ort TEXT, postal TEXT,
        assignee TEXT, assignees TEXT, company TEXT, person TEXT,
        flagged INT NOT NULL DEFAULT 0, tags TEXT, note TEXT, due_at TEXT, completed_at TEXT,
        booking_id INT, thread_id TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )'
);
$race->exec("INSERT INTO reminders (id, list_id, title, created_at, updated_at) VALUES (4, 1, 'Frö', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
unset($race);
$worker = $tmp . '/race-worker.php';
$workerSrc = <<<'PHP'
<?php
declare(strict_types=1);
$root = $argv[1] ?? '';
$tmp = $argv[2] ?? '';
$title = $argv[3] ?? 'parallell';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/race.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
$pdo = new PDO('sqlite:' . $tmp . '/race.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$row = (new JeabPdoStore($pdo))->insertReminder([
    'title' => $title,
    'created_at' => '2026-10-30 13:40:00',
    'updated_at' => '2026-10-30 13:40:00',
]);
echo (int) ($row['id'] ?? 0);
PHP;
file_put_contents($worker, $workerSrc);
$raceIds = [];
$procs = [];
foreach (['A', 'B'] as $label) {
    $cmd = [PHP_BINARY, $worker, dirname(__DIR__), $tmp, 'Parallell ' . $label];
    $procs[$label] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[$label . '_pipes'] = $pipes;
}
foreach (['A', 'B'] as $label) {
    $pipes = $procs[$label . '_pipes'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($procs[$label]);
    zid($code === 0, 'parallell ' . $label . ' körde klart' . ($err !== '' ? ' ' . trim($err) : ''));
    $raceIds[] = (int) trim((string) $out);
}
sort($raceIds);
zid($raceIds === [5, 6], 'två samtidiga insert ger id 5 och 6');
$seedTitle = (new PDO('sqlite:' . $fileDb))->query('SELECT title FROM reminders WHERE id = 4')->fetchColumn();
zid($seedTitle === 'Frö', 'den gamla raden är oförändrad efter parallella insert');

echo "OK  klart\n";
