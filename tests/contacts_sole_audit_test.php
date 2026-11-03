<?php

declare(strict_types=1);

/**
 * contacts.sole_audit. Påhittade namn. Svaret har bara antal.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sole-audit-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
unset(
    $_ENV['CONTACTS_SOLE'],
    $_ENV['CONTACTS_STRICT'],
    $GLOBALS['_JEAB_CONTACTS_SOLE'],
    $GLOBALS['_JEAB_CONTACTS_STRICT'],
    $GLOBALS['_JEAB_CONTACTS_SOLE_STORED']
);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-sole-audit']));
require dirname(__DIR__) . '/src/bootstrap.php';

function audit_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$hookSrc = (string) file_get_contents($root . '/hook.php');
$auditSrc = (string) file_get_contents($root . '/src/contacts_sole_audit.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
audit_ok(str_contains($hookSrc, "'contacts.sole_audit'"), 'hook.php tar contacts.sole_audit');
audit_ok(str_contains($dispatchSrc, "=== 'contacts.sole_audit'") && str_contains($dispatchSrc, 'jeab_contacts_sole_audit('), 'dispatch skickar contacts.sole_audit');
audit_ok(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE\s+INTO|CREATE|ALTER|DROP)\b/i', $auditSrc) !== 1, 'auditen har ingen skriv-SQL');

$store = jeab_store();
audit_ok($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

function audit_cols(PDO $pdo, string $table): array
{
    $have = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $row) {
        if (is_array($row) && isset($row['name'])) {
            $have[(string) $row['name']] = true;
        }
    }

    return $have;
}

function audit_add_col(PDO $pdo, string $table, string $column): void
{
    $have = audit_cols($pdo, $table);
    if (!isset($have[$column]) && $have !== []) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT');
    }
}

if (audit_cols($pdo, 'bookings') === []) {
    fwrite(STDERR, "FAIL bookings saknas efter boot\n");
    exit(1);
}
foreach (['bookings', 'reminders', 'purchases'] as $table) {
    audit_add_col($pdo, $table, 'company');
    audit_add_col($pdo, $table, 'contact_id');
}
if (!jeab_contacts_sole_audit_table($pdo, 'contacts')) {
    $pdo->exec(
        'CREATE TABLE contacts (
            id TEXT PRIMARY KEY,
            row_json TEXT NOT NULL,
            street_key TEXT NOT NULL DEFAULT \'\',
            postal_code TEXT NOT NULL DEFAULT \'\',
            owner_email TEXT NOT NULL DEFAULT \'\',
            owner_phone TEXT NOT NULL DEFAULT \'\',
            owner_name_fold TEXT NOT NULL DEFAULT \'\',
            company TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
}
foreach (['row_json', 'owner_email', 'owner_phone', 'owner_name_fold', 'postal_code'] as $column) {
    audit_add_col($pdo, 'contacts', $column);
}
if (!jeab_contacts_sole_audit_table($pdo, 'inkorg_threads')) {
    $pdo->exec('CREATE TABLE inkorg_threads (id TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT \'open\', updated_at TEXT, row_json TEXT NOT NULL)');
}
if (!jeab_contacts_sole_audit_table($pdo, 'inkorg_list')) {
    $pdo->exec('CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT \'\',
        customer_email TEXT NOT NULL DEFAULT \'\',
        list_at TEXT NOT NULL DEFAULT \'\',
        list_status TEXT NOT NULL DEFAULT \'\',
        contact_id TEXT NOT NULL DEFAULT \'\',
        row_json TEXT NOT NULL
    )');
}
if (!jeab_contacts_sole_audit_table($pdo, 'inkorg_contacts')) {
    $pdo->exec('CREATE TABLE inkorg_contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL,
        email TEXT NOT NULL DEFAULT \'\',
        mobile TEXT NOT NULL DEFAULT \'\',
        updated_at TEXT NOT NULL
    )');
}

$stamp = '2026-01-01 00:00:00';
$pdo->exec("INSERT INTO customers (name, created_at) VALUES ('Testkund', '$stamp')");
$customerId = (int) $pdo->lastInsertId();
$listId = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id LIMIT 1')->fetchColumn();
if ($listId <= 0) {
    $pdo->exec("INSERT INTO reminder_lists (name, created_at) VALUES ('Påminnelser', '$stamp')");
    $listId = (int) $pdo->lastInsertId();
}

$ada = [
    'id' => 'kt_ada',
    'street' => 'Storgatan 1',
    'postal_code' => '17770',
    'city' => 'Jarfalla',
    'owners' => [[
        'name' => 'Ada Lov',
        'email' => 'ada@example.test',
        'phone' => '+46701111111',
        'contact' => true,
    ]],
];
$bo = [
    'id' => 'kt_bo',
    'street' => 'Kyrkvagen 2',
    'postal_code' => '21120',
    'city' => 'Malmo',
    'owners' => [[
        'name' => 'Bo Ek',
        'email' => 'bo@example.test',
        'phone' => '+46702222222',
        'contact' => true,
    ]],
];
$insContact = $pdo->prepare('INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$contactCols = audit_cols($pdo, 'contacts');
if (!isset($contactCols['created_at'])) {
    $insContact = $pdo->prepare('INSERT INTO contacts (id, row_json, owner_email, owner_phone, owner_name_fold, postal_code) VALUES (?, ?, ?, ?, ?, ?)');
    $insContact->execute(['kt_ada', json_encode($ada), 'ada@example.test', '+46701111111', 'ada lov', '17770']);
    $insContact->execute(['kt_bo', json_encode($bo), 'bo@example.test', '+46702222222', 'bo ek', '21120']);
} else {
    $insContact->execute(['kt_ada', json_encode($ada), 'storgatan 1', '17770', 'ada@example.test', '+46701111111', 'ada lov', 'jarfalla', $stamp, $stamp]);
    $insContact->execute(['kt_bo', json_encode($bo), 'kyrkvagen 2', '21120', 'bo@example.test', '+46702222222', 'bo ek', 'malmo', $stamp, $stamp]);
}

$book = $pdo->prepare('INSERT INTO bookings (customer_id, customer_name, email, phone, address, assignee, starts_at, ends_at, status, contact_id, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$book->execute([$customerId, 'Ada Lov', 'ada@example.test', '070-111 11 11', 'Storgatan 1, 177 70 Jarfalla', 'Test', $stamp, $stamp, 'booked', 'kt_ada', 'jarfalla', $stamp, $stamp]);
$book->execute([$customerId, 'Nils Nord', 'nils@example.test', '070-999 99 99', 'Kyrkvagen 9, 11122 Stockholm', 'Test', $stamp, $stamp, 'booked', 'kt_ada', 'jarfalla', $stamp, $stamp]);
$book->execute([$customerId, 'Tom', 'tom@example.test', '070-111 11 11', 'Storgatan 1', 'Test', $stamp, $stamp, 'booked', '', 'jarfalla', $stamp, $stamp]);
$book->execute([$customerId, 'Borta', 'borta@example.test', '070-111 11 11', 'Storgatan 1', 'Test', $stamp, $stamp, 'booked', 'kt_saknas', 'jarfalla', $stamp, $stamp]);
$book->execute([$customerId, 'Ada Lov', 'ada@example.test', '070-111 11 11', 'Storgatan 12, 17770 Jarfalla', 'Test', $stamp, $stamp, 'booked', 'kt_ada', 'jarfalla', $stamp, $stamp]);
$book->execute([$customerId, 'Bo Ek', 'bo@example.test', '+46702222222', 'Kyrkvagen 2, 21120 Malmo', 'Test', $stamp, $stamp, 'booked', 'kt_bo', 'malmo', $stamp, $stamp]);

$rem = $pdo->prepare('INSERT INTO reminders (list_id, title, customer_name, email, phone, address, contact_id, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$rem->execute([$listId, 'Lucka', 'Ada Lov', 'annan@example.test', '', 'Storgatan 1, 17770 Jarfalla', 'kt_ada', 'jarfalla', $stamp, $stamp]);
$rem->execute([$listId, 'Tom', 'Bo Ek', 'bo@example.test', '070-222 22 22', 'Kyrkvagen 2', '', 'malmo', $stamp, $stamp]);
$remLink = $pdo->prepare('INSERT INTO reminders (list_id, title, customer_name, email, phone, address, contact_id, company, thread_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$remLink->execute([$listId, 'Kopplad', 'Ada Lov', 'ada@example.test', '070-111 11 11', 'Storgatan 1', 'kt_ada', 'jarfalla', 'ls-link', $stamp, $stamp]);
$remLink->execute([$listId, 'Trad', 'Bo Ek', 'bo@example.test', '070-222 22 22', 'Kyrkvagen 2', 'kt_bo', 'malmo', 'th-err', $stamp, $stamp]);

$purchase = $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, company) VALUES (?, ?, ?, ?, ?)');
$purchase->execute([
    901,
    json_encode([
        'contact_id' => 'kt_ada',
        'company' => 'jarfalla',
        'name' => 'Ada Lov',
        'email' => 'ada@example.test',
        'phone' => '0701111111',
        'address' => 'Storgatan 1, 17770 Jarfalla',
    ]),
    $stamp,
    'kt_ada',
    'jarfalla',
]);
$purchase->execute([
    902,
    json_encode([
        'contact_id' => 'kt_ada',
        'company' => 'jarfalla',
        'name' => 'Nils Nord',
        'email' => 'nils@example.test',
        'phone' => '0709999999',
        'address' => 'Kyrkvagen 9, 11122 Stockholm',
    ]),
    $stamp,
    '',
    '',
]);
$purchase->execute([
    903,
    json_encode([
        'contact_id' => 'kt_saknas',
        'company' => 'malmo',
        'name' => 'Bo Ek',
        'email' => 'bo@example.test',
        'phone' => '0702222222',
        'address' => 'Kyrkvagen 2, 21120 Malmo',
    ]),
    $stamp,
    '',
    'malmo',
]);

$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)');
$thread->execute(['th-ada', 'open', $stamp, json_encode([
    'company' => 'jarfalla',
    'contact_id' => 'kt_ada',
    'customer_name' => 'Ada Lov',
    'customer_email' => 'ada@example.test',
    'lead_customer' => ['phone' => '070-111 11 11', 'address' => 'Storgatan 1, 177 70 Jarfalla'],
])]);
$thread->execute(['th-malmo', 'open', $stamp, json_encode([
    'brand' => 'malmo',
    'contact_id' => 'kt_bo',
    'customer_name' => 'Bo Ek',
    'customer_email' => 'fel@example.test',
    'phone' => '+46702222222',
    'address' => 'Kyrkvagen 2, 21120 Malmo',
])]);
$thread->execute(['th-info', 'open', $stamp, json_encode([
    'company' => 'jarfalla',
    'customer_email' => 'info@example.test',
    'lead_customer' => ['email' => 'info@example.test'],
])]);
$thread->execute(['th-err', 'open', $stamp, json_encode([
    'company' => 'malmo',
    'list_type' => 'Ny lead',
    'customer_email' => 'fel2@example.test',
    'phone' => '0703333333',
])]);
$thread->execute(['th-mobil', 'open', $stamp, json_encode([
    'brand' => 'malmo',
    'contact_id' => 'kt_bo',
    'customer_name' => 'Annat Namn',
    'customer_email' => 'fel3@example.test',
    'phone' => '0705555555',
    'address' => 'Kyrkvagen 2, 21120 Malmo',
])]);

$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
$list->execute(['ls-tom', 'jarfalla', 'ada@example.test', $stamp, 'open', '', json_encode([
    'company' => 'jarfalla',
    'customer_name' => 'Ada Lov',
    'customer_email' => 'ada@example.test',
])]);
$list->execute(['ls-link', 'jarfalla', 'ny@example.test', $stamp, 'open', '', json_encode([
    'company' => 'jarfalla',
    'customer_email' => 'ny@example.test',
    'lead_customer' => ['email' => 'ny@example.test', 'name' => 'Ny Lead'],
])]);
$list->execute(['ls-bo', 'MEAB', 'bo@example.test', $stamp, 'open', 'kt_bo', json_encode([
    'company' => 'malmo',
    'customer_name' => 'Bo Ek',
    'customer_email' => 'bo@example.test',
    'lead_customer' => ['phone' => '070-222 22 22', 'address' => 'Kyrkvagen 2, 211 20 Malmo'],
])]);
$pdo->exec("INSERT INTO inkorg_contacts (id, row_json, email, mobile, updated_at) VALUES ('ic1', '{}', 'a@example.test', '', '$stamp')");
$pdo->exec("INSERT INTO inkorg_contacts (id, row_json, email, mobile, updated_at) VALUES ('ic2', '{}', 'b@example.test', '', '$stamp')");

function audit_snapshot(PDO $pdo): array
{
    $out = [];
    foreach (['bookings', 'reminders', 'purchases', 'contacts', 'inkorg_threads', 'inkorg_list', 'inkorg_contacts'] as $table) {
        $out[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $out;
}

$before = audit_snapshot($pdo);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.sole_audit', 'dry' => 0]);
audit_ok(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
audit_ok(audit_snapshot($pdo) === $before, 'nekad audit skriver inget');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-sole-audit';
$report = jeab_contacts_sole_dispatch(['action' => 'contacts.sole_audit', 'dry' => 0]);
audit_ok(!empty($report['ok']) && ($report['status'] ?? 0) === 200 && ($report['action'] ?? '') === 'contacts.sole_audit', 'audit svarar ok');
audit_ok(($report['read_only'] ?? false) === true && ($report['written'] ?? true) === false && ($report['dry'] ?? false) === true, 'svaret säger att inget skrivs');
audit_ok(audit_snapshot($pdo) === $before, 'lyckad audit skriver inget');

$json = json_encode($report, JSON_UNESCAPED_UNICODE);
audit_ok(is_string($json), 'svaret kan kodas');
foreach (['ada@example.test', 'Ada Lov', '070', 'Storgatan', 'kt_ada', '17770', '+46'] as $secret) {
    audit_ok(!str_contains((string) $json, $secret), 'svaret saknar ' . $secret);
}

$flags = is_array($report['flags'] ?? null) ? $report['flags'] : [];
$sole = is_array($flags['CONTACTS_SOLE'] ?? null) ? $flags['CONTACTS_SOLE'] : [];
$strict = is_array($flags['CONTACTS_STRICT'] ?? null) ? $flags['CONTACTS_STRICT'] : [];
audit_ok(($sole['value'] ?? -1) === 1 && ($sole['source'] ?? '') === 'default', 'CONTACTS_SOLE är på från standard');
audit_ok(($strict['value'] ?? -1) === 0 && ($strict['source'] ?? '') === 'default', 'CONTACTS_STRICT är av från standard');
audit_ok(($report['inkorg_contacts_migrated'] ?? true) === false, 'migreringsmarkören saknas');
audit_ok(($report['inkorg_contacts'] ?? -1) === 2, 'inkorg_contacts räknas');

$co = is_array($report['co'] ?? null) ? $report['co'] : [];
$jar = is_array($co['jarfalla'] ?? null) ? $co['jarfalla'] : [];
$mal = is_array($co['malmo'] ?? null) ? $co['malmo'] : [];
$bookJ = is_array($jar['bookings'] ?? null) ? $jar['bookings'] : [];
$bookM = is_array($mal['bookings'] ?? null) ? $mal['bookings'] : [];
audit_ok(($bookJ['total'] ?? -1) === 5 && ($bookJ['empty_contact_id'] ?? -1) === 1 && ($bookJ['missing_contact'] ?? -1) === 1, 'jarfalla-bokningar räknar tom och saknad länk');
$diffJ = is_array($bookJ['differ'] ?? null) ? $bookJ['differ'] : [];
audit_ok(($diffJ['phone'] ?? -1) === 1 && ($diffJ['email'] ?? -1) === 1 && ($diffJ['name'] ?? -1) === 1 && ($diffJ['address'] ?? -1) === 2, 'jarfalla-bokningar räknar avvikande kopia per fält');
audit_ok(($bookM['total'] ?? -1) === 1 && ($bookM['empty_contact_id'] ?? -1) === 0 && ($bookM['missing_contact'] ?? -1) === 0, 'malmo-bokningen ligger för sig');
$diffM = is_array($bookM['differ'] ?? null) ? $bookM['differ'] : [];
audit_ok(($diffM['phone'] ?? -1) === 0 && ($diffM['email'] ?? -1) === 0 && ($diffM['name'] ?? -1) === 0 && ($diffM['address'] ?? -1) === 0, 'malmo-bokningen stämmer med kontakten');

$remJ = is_array($jar['reminders']['differ'] ?? null) ? $jar['reminders']['differ'] : [];
audit_ok(($jar['reminders']['total'] ?? -1) === 2 && ($remJ['email'] ?? -1) === 1 && ($remJ['phone'] ?? -1) === 0, 'tom telefonkopia är ingen avvikelse');
audit_ok(($mal['reminders']['total'] ?? -1) === 2 && ($mal['reminders']['empty_contact_id'] ?? -1) === 1, 'malmo-påminnelsen saknar contact_id');

$buyJ = is_array($jar['purchases'] ?? null) ? $jar['purchases'] : [];
$buyDiff = is_array($buyJ['differ'] ?? null) ? $buyJ['differ'] : [];
audit_ok(($buyJ['total'] ?? -1) === 2 && ($buyDiff['name'] ?? -1) === 1 && ($buyDiff['phone'] ?? -1) === 1 && ($buyJ['missing_contact'] ?? -1) === 0, 'ordrar läser payload när kolumnen är tom');
audit_ok(($mal['purchases']['total'] ?? -1) === 1 && ($mal['purchases']['missing_contact'] ?? -1) === 1, 'malmo-ordern pekar på en kontakt som saknas');

$thM = is_array($mal['inkorg_threads']['differ'] ?? null) ? $mal['inkorg_threads']['differ'] : [];
$thJ = is_array($jar['inkorg_threads']['differ'] ?? null) ? $jar['inkorg_threads']['differ'] : [];
audit_ok(($jar['inkorg_threads']['total'] ?? -1) === 2 && ($mal['inkorg_threads']['total'] ?? -1) === 3 && ($thM['email'] ?? -1) === 0 && ($thM['name'] ?? -1) === 0 && ($thM['phone'] ?? -1) === 1, 'kopplad tråd ignorerar namn och mejl, annan mobil räknas');
audit_ok(($thM['thread_ids'] ?? null) === ['th-mobil'] && ($thJ['thread_ids'] ?? null) === [], 'avvikande tråd-id följer med, jarfalla har inga');
audit_ok(($jar['inkorg_list']['empty_contact_id'] ?? -1) === 2 && ($mal['inkorg_list']['total'] ?? -1) === 1, 'listan tar MEAB som malmo och räknar tom länk');
$lsM = is_array($mal['inkorg_list']['differ'] ?? null) ? $mal['inkorg_list']['differ'] : [];
audit_ok(($lsM['email'] ?? -1) === 0 && ($lsM['phone'] ?? -1) === 0 && ($lsM['address'] ?? -1) === 0 && ($lsM['thread_ids'] ?? null) === [], 'malmo-raden i listan stämmer');

$err = is_array($report['errors'] ?? null) ? $report['errors'] : [];
$info = is_array($report['info'] ?? null) ? $report['info'] : [];
$errJ = is_array($err['jarfalla'] ?? null) ? $err['jarfalla'] : [];
$errM = is_array($err['malmo'] ?? null) ? $err['malmo'] : [];
$infoJ = is_array($info['jarfalla'] ?? null) ? $info['jarfalla'] : [];
$infoM = is_array($info['malmo'] ?? null) ? $info['malmo'] : [];
audit_ok(($errJ['bookings'] ?? -1) === 1 && ($errJ['reminders'] ?? -1) === 0 && ($errM['reminders'] ?? -1) === 1, 'tom contact_id på bokning och påminnelse är fel');
audit_ok(($errJ['purchases'] ?? -1) === 0 && ($errJ['orders'] ?? -1) === 0 && ($errM['purchases'] ?? -1) === 0 && ($errM['orders'] ?? -1) === ($errM['purchases'] ?? -2), 'ordrar är samma tomma köp och räknas inte dubbelt');
audit_ok(($errJ['inkorg_list'] ?? -1) === 0 && ($errJ['inkorg_cases'] ?? -1) === 0 && ($errM['inkorg_threads'] ?? -1) === 0 && ($errM['inkorg_cases'] ?? -1) === 0, 'inkorg utan contact_id är aldrig fel');
audit_ok(($infoJ['inkorg_list'] ?? -1) === 2 && ($infoJ['inkorg_threads'] ?? -1) === 1 && ($infoJ['inkorg_cases'] ?? -1) === 2 && ($infoJ['inkorg_with_reminder'] ?? -1) === 1, 'inkorg utan contact_id är info, med påminnelse räknad för sig');
audit_ok(($infoM['inkorg_list'] ?? -1) === 0 && ($infoM['inkorg_threads'] ?? -1) === 1 && ($infoM['inkorg_cases'] ?? -1) === 1 && ($infoM['inkorg_with_reminder'] ?? -1) === 1, 'malmo-tråden med påminnelse är info');
$core = (int) ($errJ['reminders'] ?? 0) + (int) ($errJ['bookings'] ?? 0) + (int) ($errJ['purchases'] ?? 0)
    + (int) ($errM['reminders'] ?? 0) + (int) ($errM['bookings'] ?? 0) + (int) ($errM['purchases'] ?? 0);
$inboxErr = (int) ($errJ['inkorg_list'] ?? 0) + (int) ($errJ['inkorg_threads'] ?? 0) + (int) ($errM['inkorg_list'] ?? 0) + (int) ($errM['inkorg_threads'] ?? 0);
audit_ok(($err['total'] ?? -1) === $core + $inboxErr && ($err['total'] ?? -1) === 2, 'fel-totalen tar inte med ordrar eller ärenden en gång till');
audit_ok(($jar['orders']['total'] ?? -1) === ($jar['purchases']['total'] ?? -2) && ($jar['orders']['empty_contact_id'] ?? -1) === 0, 'ordrar speglar köpen');

file_put_contents($tmp . '/inkorg/contacts.json.migrated', "1\n");
$marked = jeab_contacts_sole_dispatch(['action' => 'contacts.sole_audit']);
audit_ok(($marked['inkorg_contacts_migrated'] ?? false) === true && audit_snapshot($pdo) === $before, 'markören läses och inget skrivs');

putenv('CONTACTS_STRICT=1');
$_ENV['CONTACTS_STRICT'] = '1';
$fromEnv = jeab_contacts_sole_audit_flag('CONTACTS_STRICT');
audit_ok(($fromEnv['value'] ?? 0) === 1 && ($fromEnv['source'] ?? '') === 'env', 'CONTACTS_STRICT läses från env');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_STRICT']);

unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
jeab_settings_put($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY, '0');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);
$fromSettings = jeab_contacts_sole_audit_flag('CONTACTS_SOLE');
audit_ok(($fromSettings['value'] ?? 1) === 0 && ($fromSettings['source'] ?? '') === 'settings', 'CONTACTS_SOLE läses från settings');
jeab_settings_put($pdo, 'sqlite', JEAB_CONTACTS_SOLE_KEY, '1');
unset($GLOBALS['_JEAB_CONTACTS_SOLE_STORED']);

$web = $tmp . '/web';
$privat = $tmp . '/privat';
mkdir($web, 0775, true);
mkdir($privat, 0775, true);
$prevRoot = $GLOBALS['_JEAB_APP_ROOT'] ?? null;
$GLOBALS['_JEAB_APP_ROOT'] = $web;
file_put_contents($privat . '/flags.json', json_encode(['CONTACTS_STRICT' => 1]));
$fromFile = jeab_contacts_sole_audit_flag('CONTACTS_STRICT');
audit_ok(($fromFile['value'] ?? 0) === 1 && ($fromFile['source'] ?? '') === 'flags.json', 'CONTACTS_STRICT läses från flags.json');
if ($prevRoot === null) {
    unset($GLOBALS['_JEAB_APP_ROOT']);
} else {
    $GLOBALS['_JEAB_APP_ROOT'] = $prevRoot;
}

$port = 18080 + random_int(0, 1000);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-sole-audit']));
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($public, true) . ";\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$log = $tmp . '/php-server.log';
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root), escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
audit_ok(is_resource($proc), 'php -S startar');
$pid = proc_get_status($proc)['pid'] ?? 0;
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
audit_ok($up, 'php -S lyssnar');

$post = static function (string $body, string $token) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Token: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $parts = explode("\r\n\r\n", $raw, 2);
    $decoded = json_decode($parts[1] ?? '', true);

    return ['status' => $status, 'json' => is_array($decoded) ? $decoded : [], 'raw' => $raw];
};

$curlDenied = $post('{"action":"contacts.sole_audit","dry":0}', 'fel');
audit_ok($curlDenied['status'] === 401 && empty($curlDenied['json']['ok']), 'curl utan token får 401');
$curlOk = $post('{"action":"contacts.sole_audit","dry":0}', 'tok-sole-audit');
audit_ok($curlOk['status'] === 200 && ($curlOk['json']['action'] ?? '') === 'contacts.sole_audit', 'curl med token får auditen');
audit_ok(($curlOk['json']['co']['jarfalla']['bookings']['total'] ?? -1) === 5, 'curl räknar samma bokningar');
audit_ok(($curlOk['json']['inkorg_contacts'] ?? -1) === 2 && ($curlOk['json']['inkorg_contacts_migrated'] ?? false) === true, 'curl ser markör och inkorg_contacts');
$curlJson = json_encode($curlOk['json'], JSON_UNESCAPED_UNICODE);
audit_ok(is_string($curlJson) && !str_contains($curlJson, 'example.test') && !str_contains($curlJson, 'Ada'), 'curl-svaret saknar persondata');
audit_ok(audit_snapshot($pdo) === $before, 'curl-anropet skriver inga rader');

if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
unset($pid);

echo "OK  contacts.sole_audit\n";
