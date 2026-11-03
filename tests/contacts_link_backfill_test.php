<?php

declare(strict_types=1);

/**
 * contacts.link_backfill. Påhittade namn. Svaret har id, bolag och booleska kontroller.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-backfill-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-backfill']));
require dirname(__DIR__) . '/src/bootstrap.php';

function lb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/src/contacts_link_backfill.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
lb(str_contains($hookSrc, "'contacts.link_backfill'") && str_contains($hookSrc, "'contacts.link_backfill_undo'"), 'hook.php tar båda åtgärderna');
lb(str_contains($dispatchSrc, "=== 'contacts.link_backfill'") && str_contains($dispatchSrc, 'jeab_contacts_link_backfill('), 'dispatch skickar backfill');
lb(str_contains($dispatchSrc, 'jeab_contacts_link_backfill_undo('), 'dispatch skickar undo');
foreach (['starts_at', 'ends_at', 'quotes', 'invoices', '/rot/', 'DELETE FROM', 'INSERT INTO contacts'] as $banned) {
    lb(!str_contains($src, $banned), 'källan innehåller inte ' . $banned);
}

$store = jeab_store();
lb($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

function lb_cols(PDO $pdo, string $table): array
{
    $have = [];
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (is_array($row) && isset($row['name'])) {
            $have[(string) $row['name']] = true;
        }
    }

    return $have;
}

function lb_add(PDO $pdo, string $table, string $column): void
{
    if (lb_cols($pdo, $table) !== [] && !isset(lb_cols($pdo, $table)[$column])) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT');
    }
}

foreach (['bookings', 'reminders', 'purchases'] as $table) {
    lb_add($pdo, $table, 'company');
    lb_add($pdo, $table, 'contact_id');
}
lb_add($pdo, 'purchases', 'list_json');
lb_add($pdo, 'contacts', 'company');
lb_add($pdo, 'contacts', 'row_json');
lb_add($pdo, 'contacts', 'owner_email');
lb_add($pdo, 'contacts', 'owner_phone');
if (!isset(lb_cols($pdo, 'contacts')['id'])) {
    $pdo->exec("CREATE TABLE contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL DEFAULT '{}',
        owner_email TEXT NOT NULL DEFAULT '',
        owner_phone TEXT NOT NULL DEFAULT '',
        company TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}
if (lb_cols($pdo, 'contact_emails') === []) {
    $pdo->exec('CREATE TABLE contact_emails (email TEXT NOT NULL, contact_id TEXT NOT NULL)');
}
if (lb_cols($pdo, 'inkorg_threads') === []) {
    $pdo->exec("CREATE TABLE inkorg_threads (id TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT 'open', updated_at TEXT, row_json TEXT NOT NULL)");
}
if (lb_cols($pdo, 'inkorg_list') === []) {
    $pdo->exec("CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT '',
        customer_email TEXT NOT NULL DEFAULT '',
        contact_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}
if (lb_cols($pdo, 'inkorg_contacts') === []) {
    $pdo->exec("CREATE TABLE inkorg_contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL,
        email TEXT NOT NULL DEFAULT '',
        mobile TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL
    )");
}
lb_add($pdo, 'inkorg_contacts', 'contact_id');
$pdo->exec("CREATE TABLE IF NOT EXISTS quotes (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS invoices (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS rot_jobs (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS mail_snapshots (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("INSERT INTO quotes (id, body) VALUES ('q1', 'offert-behall')");
$pdo->exec("INSERT INTO invoices (id, body) VALUES ('i1', 'faktura-behall')");
$pdo->exec("INSERT INTO rot_jobs (id, body) VALUES ('r1', 'rot-behall')");
$pdo->exec("INSERT INTO mail_snapshots (id, body) VALUES ('m1', 'skickat-behall')");

$stamp = '2026-01-01 00:00:00';
$later = '2026-02-02 00:00:00';
$pdo->exec("INSERT INTO customers (name, created_at) VALUES ('Testkund', '$stamp')");
$customerId = (int) $pdo->lastInsertId();
$listId = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id LIMIT 1')->fetchColumn();
if ($listId <= 0) {
    $pdo->exec("INSERT INTO reminder_lists (name, created_at) VALUES ('Paminelser', '$stamp')");
    $listId = (int) $pdo->lastInsertId();
}

$people = [
    'kt_ada' => ['jarfalla', 'ada@example.test', '+46701111111', 'Ada Lov'],
    'kt_bo' => ['malmo', 'bo@example.test', '+46702222222', 'Bo Ek'],
    'kt_duo1' => ['jarfalla', 'duo@example.test', '+46707777777', 'Duo Ett'],
    'kt_duo2' => ['jarfalla', 'duo@example.test', '', 'Duo Tva'],
    'kt_mail' => ['jarfalla', 'mail@example.test', '', 'Maj Post'],
    'kt_fon' => ['jarfalla', '', '+46703333333', 'Fon En'],
    'kt_split' => ['jarfalla', 'split@example.test', '+46704444444', 'Split A'],
    'kt_other' => ['jarfalla', 'other@example.test', '+46705555555', 'Split B'],
    'kt_extra' => ['jarfalla', '', '', 'Extra Post'],
];
$extraLinks = 12;
for ($i = 0; $i < $extraLinks; $i++) {
    $people[sprintf('kt_b%02d', $i)] = ['jarfalla', sprintf('b%02d@example.test', $i), '', 'Bulk ' . $i];
}
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, owner_email, owner_phone, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($people as $id => $person) {
    $json = json_encode([
        'id' => $id,
        'company' => $person[0],
        'owners' => [[
            'name' => $person[3],
            'email' => $person[1],
            'phone' => $person[2],
            'contact' => true,
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $ins->execute([$id, $json, $person[1], $person[2], $person[0], $stamp, $stamp]);
}
$pdo->exec("INSERT INTO contact_emails (email, contact_id) VALUES ('extra@example.test', 'kt_extra')");

$book = $pdo->prepare('INSERT INTO bookings (customer_id, customer_name, email, phone, address, assignee, starts_at, ends_at, status, contact_id, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$book->execute([$customerId, 'Ada Lov', 'Ada@Example.TEST', '070-111 11 11', 'Storgatan 1', 'Test', $stamp, $later, 'booked', '', 'jarfalla', $stamp, $stamp]);
$safeEmail = (string) $pdo->lastInsertId();
$book->execute([$customerId, 'Fon En', '', '070-333 33 33', 'Fonvagen 1', 'Test', $stamp, $later, 'booked', '', 'JEAB', $stamp, $stamp]);
$safePhone = (string) $pdo->lastInsertId();
$book->execute([$customerId, 'Duo', 'duo@example.test', '070-777 77 77', 'Duovagen 1', 'Test', $stamp, $later, 'booked', '', 'jarfalla', $stamp, $stamp]);
$ambig = (string) $pdo->lastInsertId();
$book->execute([$customerId, 'Split', 'split@example.test', '070-555 55 55', 'Splitvagen 1', 'Test', $stamp, $later, 'booked', '', 'jarfalla', $stamp, $stamp]);
$conflict = (string) $pdo->lastInsertId();
$book->execute([$customerId, 'Ada Lov', 'bo@example.test', '070-222 22 22', 'Kvar', 'Test', $stamp, $later, 'booked', 'kt_ada', 'jarfalla', $stamp, $stamp]);
$kept = (string) $pdo->lastInsertId();
$book->execute([$customerId, 'Ada Lov', 'ada@example.test', '070-111 11 11', 'Fel bolag', 'Test', $stamp, $later, 'booked', '', 'malmo', $stamp, $stamp]);
$otherCo = (string) $pdo->lastInsertId();

$rem = $pdo->prepare('INSERT INTO reminders (list_id, title, customer_name, email, phone, address, contact_id, company, due_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$rem->execute([$listId, 'Post', 'Maj Post', 'mail@example.test', '', 'Postvagen 1', '', 'jarfalla', $later, $stamp, $stamp]);
$safeRem = (string) $pdo->lastInsertId();
foreach ($people as $id => $person) {
    if (!str_starts_with((string) $id, 'kt_b')) {
        continue;
    }
    $rem->execute([$listId, 'Bulk', 'Bulk', $person[1], '', 'Bulkvagen 1', '', 'jarfalla', $later, $stamp, $stamp]);
}
$rem->execute([$listId, 'Obekr', 'Maj Post', 'mail@example.test', '070-999 99 99', 'Obekrvagen 1', '', 'jarfalla', $later, $stamp, $stamp]);
$failRem = (string) $pdo->lastInsertId();
$rem->execute([$listId, 'Kvar', 'Bo Ek', 'ada@example.test', '', 'Kvar', 'kt_bo', 'malmo', $later, $stamp, $stamp]);
$keptRem = (string) $pdo->lastInsertId();

$purchaseKept = json_encode([
    'contact_id' => 'kt_bo',
    'company' => 'malmo',
    'email' => 'ada@example.test',
    'note' => 'lanken-ska-vara-kvar',
], JSON_UNESCAPED_UNICODE);
$purchaseNew = json_encode([
    'company' => 'jarfalla',
    'email' => 'extra@example.test',
    'note' => 'fyll-bara-id',
], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, company, list_json) VALUES (?, ?, ?, ?, ?, ?)')->execute([
    801,
    $purchaseNew,
    $stamp,
    '',
    'jarfalla',
    json_encode(['note' => 'fyll-bara-id'], JSON_UNESCAPED_UNICODE),
]);
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, company) VALUES (?, ?, ?, ?, ?)')->execute([
    802,
    $purchaseKept,
    $stamp,
    '',
    '',
]);

$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)');
$thread->execute(['th-safe', 'open', $stamp, json_encode([
    'company' => 'jarfalla',
    'customer_email' => 'extra@example.test',
    'customer_name' => 'Extra Post',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-keep', 'open', $stamp, json_encode([
    'company' => 'malmo',
    'contact_id' => 'kt_bo',
    'customer_email' => 'ada@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-src', 'open', $stamp, json_encode([
    'source' => 'graph-jarfalla',
    'customer_email' => 'ada@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-msg', 'open', $stamp, json_encode([
    'customer_email' => 'bo@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-list', 'open', $stamp, json_encode([
    'customer_email' => 'mail@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-clash', 'open', $stamp, json_encode([
    'source' => 'graph-malmo',
    'customer_email' => 'ada@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-both', 'open', $stamp, json_encode([
    'source' => 'graph-malmo graph-jarfalla',
    'customer_email' => 'ada@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-none', 'open', $stamp, json_encode([
    'customer_email' => 'ada@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-nomatch', 'open', $stamp, json_encode([
    'source' => 'graph-malmo',
    'customer_email' => 'nobody@example.test',
], JSON_UNESCAPED_UNICODE)]);
$thread->execute(['th-city', 'open', $stamp, json_encode([
    'company' => 'stockholm',
    'source' => 'graph-jarfalla',
    'customer_email' => 'ada@example.test',
], JSON_UNESCAPED_UNICODE)]);
$msgJson = json_encode(['source' => 'graph-malmo', 'id' => 'msg-1'], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO inkorg_messages (id, thread_id, row_json) VALUES (?, ?, ?)')->execute(['msg-1', 'th-msg', $msgJson]);

$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$list->execute(['ls-bo', 'MEAB', 'bo@example.test', '', json_encode(['company' => 'malmo', 'customer_email' => 'bo@example.test'], JSON_UNESCAPED_UNICODE)]);
$list->execute(['ls-duo', 'jarfalla', 'duo@example.test', '', json_encode(['company' => 'jarfalla'], JSON_UNESCAPED_UNICODE)]);
$list->execute(['th-list', 'jarfalla', '', '', json_encode(['company' => 'jarfalla'], JSON_UNESCAPED_UNICODE)]);
$list->execute(['th-clash', 'jarfalla', '', '', json_encode(['company' => 'jarfalla'], JSON_UNESCAPED_UNICODE)]);

$inbox = $pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, email, mobile, contact_id, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
$inbox->execute(['ic-safe', json_encode(['id' => 'ic-safe', 'name' => 'Ada Lov', 'emails' => ['Ada@Example.TEST']], JSON_UNESCAPED_UNICODE), 'ada@example.test', '', '', $stamp]);
$inbox->execute(['ic-malmo', json_encode(['id' => 'ic-malmo', 'company' => 'malmo', 'emails' => ['ada@example.test']], JSON_UNESCAPED_UNICODE), 'ada@example.test', '', '', $stamp]);
$inbox->execute(['ic-duo', json_encode(['id' => 'ic-duo', 'emails' => ['duo@example.test']], JSON_UNESCAPED_UNICODE), 'duo@example.test', '', '', $stamp]);
$inbox->execute(['ic-none', json_encode(['id' => 'ic-none', 'emails' => ['nobody@example.test']], JSON_UNESCAPED_UNICODE), 'nobody@example.test', '', '', $stamp]);
$inbox->execute(['ic-keep', json_encode(['id' => 'ic-keep', 'contact_id' => 'kt_bo', 'emails' => ['ada@example.test']], JSON_UNESCAPED_UNICODE), 'ada@example.test', '', 'kt_bo', $stamp]);
file_put_contents($tmp . '/inkorg/contacts.json', json_encode([
    'contacts' => [
        'ic-safe' => ['id' => 'ic-safe', 'name' => 'Ada Lov', 'emails' => ['ada@example.test']],
        'ic-duo' => ['id' => 'ic-duo', 'emails' => ['duo@example.test']],
    ],
], JSON_UNESCAPED_UNICODE));

function lb_cid(PDO $pdo, string $sql): string
{
    $value = $pdo->query($sql)->fetchColumn();

    return trim((string) $value);
}

function lb_snapshot(PDO $pdo): array
{
    return [
        'contacts' => (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
        'bookings' => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
        'reminders' => (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn(),
        'purchases' => (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn(),
        'threads' => (int) $pdo->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn(),
        'list' => (int) $pdo->query('SELECT COUNT(*) FROM inkorg_list')->fetchColumn(),
        'inbox' => (int) $pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn(),
        'contact_hash' => (string) $pdo->query("SELECT GROUP_CONCAT(id || row_json, '|') FROM contacts")->fetchColumn(),
        'kept_booking' => lb_cid($pdo, "SELECT contact_id FROM bookings WHERE id = (SELECT id FROM bookings WHERE contact_id = 'kt_ada' LIMIT 1)"),
        'purchase_kept' => (string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 802')->fetchColumn(),
        'purchase_at' => (string) $pdo->query('SELECT updated_at FROM purchases WHERE reminder_id = 801')->fetchColumn(),
        'starts' => (string) $pdo->query('SELECT starts_at FROM bookings ORDER BY id LIMIT 1')->fetchColumn(),
        'ends' => (string) $pdo->query('SELECT ends_at FROM bookings ORDER BY id LIMIT 1')->fetchColumn(),
        'due' => (string) $pdo->query('SELECT due_at FROM reminders ORDER BY id LIMIT 1')->fetchColumn(),
        'quotes' => (string) $pdo->query('SELECT body FROM quotes')->fetchColumn(),
        'invoices' => (string) $pdo->query('SELECT body FROM invoices')->fetchColumn(),
        'rot' => (string) $pdo->query('SELECT body FROM rot_jobs')->fetchColumn(),
        'mail' => (string) $pdo->query('SELECT body FROM mail_snapshots')->fetchColumn(),
        'thread_keep' => (string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-keep'")->fetchColumn(),
    ];
}

$before = lb_snapshot($pdo);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill', 'dry' => 0]);
lb(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
lb(lb_snapshot($pdo) === $before, 'nekad körning skriver inget');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-backfill';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill']);
lb(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'utelämnad dry skriver inte');
lb(lb_snapshot($pdo) === $before, 'torrkörningen lämnar raderna');
lb(empty($dry['inkorg_contacts_migrated']), 'torrkörningen sätter ingen markör');
lb(!is_dir($tmp . '/contacts-link-backfill'), 'torrkörningen skriver ingen ångerfil');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
lb(is_string($dryJson), 'svaret kan kodas');
foreach (['example.test', '@', 'Ada', '070', '+46', 'Storgatan', 'offert', 'faktura'] as $secret) {
    lb(!str_contains((string) $dryJson, $secret), 'svaret saknar ' . $secret);
}
$tables = is_array($dry['tables'] ?? null) ? $dry['tables'] : [];
lb(($tables['bookings']['linked'] ?? 0) === 2 && ($tables['bookings']['uncertain'] ?? 0) === 3 && ($tables['bookings']['kept'] ?? 0) === 1, 'bokningar: två säkra, tre osäkra, en befintlig länk');
lb(($tables['bookings']['by_email'] ?? 0) === 1 && ($tables['bookings']['by_phone'] ?? 0) === 1, 'en träff via e-post och en via telefon');
$bookCo = is_array($tables['bookings']['co'] ?? null) ? $tables['bookings']['co'] : [];
lb(($bookCo['jarfalla']['linked'] ?? -1) === 2 && ($bookCo['jarfalla']['uncertain'] ?? -1) === 2 && ($bookCo['jarfalla']['kept'] ?? -1) === 1 && ($bookCo['jarfalla']['empty'] ?? -1) === 4, 'Järfällas bokningar räknas per bolag');
lb(($bookCo['malmo']['uncertain'] ?? -1) === 1 && ($bookCo['malmo']['empty'] ?? -1) === 1 && ($bookCo['malmo']['linked'] ?? -1) === 0 && ($bookCo['malmo']['kept'] ?? -1) === 0, 'Malmös bokning utan träff räknas för sig');
lb(($bookCo['none']['empty'] ?? -1) === 0 && ($bookCo['none']['linked'] ?? -1) === 0 && ($bookCo['none']['uncertain'] ?? -1) === 0, 'bokningar utan bolag är noll');
lb(($tables['reminders']['linked'] ?? 0) === 1 + $extraLinks && ($tables['reminders']['kept'] ?? 0) === 1, 'påminnelsen med länk rörs inte');
lb(($tables['purchases']['linked'] ?? 0) === 1 && ($tables['purchases']['kept'] ?? 0) === 1, 'ordern med länk i payload rörs inte');
lb(($tables['inkorg_threads']['linked'] ?? 0) === 4 && ($tables['inkorg_threads']['kept'] ?? 0) === 1 && ($tables['inkorg_threads']['uncertain'] ?? 0) === 5, 'trådar med entydig källa länkas, motstridiga lämnas');
$threadCo = is_array($tables['inkorg_threads']['co'] ?? null) ? $tables['inkorg_threads']['co'] : [];
lb(($threadCo['jarfalla']['linked'] ?? -1) === 3 && ($threadCo['malmo']['linked'] ?? -1) === 1 && ($threadCo['malmo']['uncertain'] ?? -1) === 1 && ($threadCo['none']['uncertain'] ?? -1) === 4, 'trådarnas bolag räknas var för sig');
lb(($tables['inkorg_list']['linked'] ?? 0) === 1 && ($tables['inkorg_list']['uncertain'] ?? 0) === 3, 'listan skiljer säker och tvetydig');
lb(($tables['inkorg_contacts']['linked'] ?? 0) === 1 && ($tables['inkorg_contacts']['uncertain'] ?? 0) === 3 && ($tables['inkorg_contacts']['kept'] ?? 0) === 1, 'gamla inkorgskontakter länkas bara vid entydig träff');
lb(($dry['inkorg_contacts_unmatched'] ?? -1) === 3, 'torrkörningen räknar olänkade inkorgskontakter');
$reasons = [];
foreach (is_array($dry['uncertain'] ?? null) ? $dry['uncertain'] : [] as $row) {
    if (is_array($row)) {
        $key = (string) ($row['table'] ?? '') . ':' . (string) ($row['id'] ?? '');
        lb(array_key_exists('co', $row) && is_bool($row['co_derived'] ?? null) && !array_key_exists('verify', $row), 'osäker rad har bolag och ingen kontroll: ' . $key);
        $reasons[(string) ($row['table'] ?? '') . ':' . (string) ($row['id'] ?? '')] = $row;
    }
}
lb(($reasons['bookings:' . $ambig]['reason'] ?? '') === 'ambiguous_email', 'två kontakter med samma e-post är osäkert');
lb(($reasons['bookings:' . $conflict]['reason'] ?? '') === 'conflict', 'e-post och telefon som pekar åt olika håll är en konflikt');
lb(($reasons['bookings:' . $otherCo]['reason'] ?? '') === 'no_match' && ($reasons['bookings:' . $otherCo]['co'] ?? '') === 'malmo', 'samma e-post i ett annat bolag länkas inte');
lb(($reasons['inkorg_list:ls-duo']['reason'] ?? '') === 'ambiguous_email', 'listans tvetydiga rad lämnas');
lb(($reasons['inkorg_contacts:ic-duo']['reason'] ?? '') === 'ambiguous_email', 'tvetydig inkorgskontakt lämnas');
lb(($reasons['inkorg_contacts:ic-malmo']['reason'] ?? '') === 'no_match' && ($reasons['inkorg_contacts:ic-malmo']['co'] ?? '') === 'malmo', 'inkorgskontakt i fel bolag lämnas');
lb(($reasons['inkorg_contacts:ic-none']['reason'] ?? '') === 'no_match' && ($reasons['inkorg_contacts:ic-none']['co'] ?? '') === '', 'saknad träff lämnas');
lb(($reasons['inkorg_threads:th-clash']['reason'] ?? '') === 'no_company' && ($reasons['inkorg_threads:th-clash']['co'] ?? 'x') === '' && ($reasons['inkorg_threads:th-clash']['co_derived'] ?? true) === false, 'motstridig källa och lista ger inget bolag');
lb(($reasons['inkorg_threads:th-both']['reason'] ?? '') === 'no_company' && ($reasons['inkorg_threads:th-both']['co_derived'] ?? true) === false, 'båda bolagen i samma källa ger inget bolag');
lb(($reasons['inkorg_threads:th-none']['reason'] ?? '') === 'no_company', 'en tråd utan signal får inget bolag');
lb(($reasons['inkorg_threads:th-city']['reason'] ?? '') === 'no_company' && ($reasons['inkorg_threads:th-city']['co_derived'] ?? true) === false, 'ett okänt bolag ersätts inte');
lb(($reasons['inkorg_threads:th-nomatch']['reason'] ?? '') === 'no_match' && ($reasons['inkorg_threads:th-nomatch']['co'] ?? '') === 'malmo' && ($reasons['inkorg_threads:th-nomatch']['co_derived'] ?? false) === true, 'härlett bolag syns även utan träff');
lb(($reasons['reminders:' . $failRem]['reason'] ?? '') === 'verify_failed' && ($reasons['reminders:' . $failRem]['co'] ?? '') === 'jarfalla', 'en träff som inte klarar kontrollen listas som verify_failed');
$sampleIds = [];
$samples = [];
foreach (is_array($dry['samples'] ?? null) ? $dry['samples'] : [] as $row) {
    if (is_array($row)) {
        lb(array_key_exists('co', $row) && is_bool($row['co_derived'] ?? null), 'träff har bolag: ' . (string) ($row['table'] ?? '') . ':' . (string) ($row['id'] ?? ''));
        $key = (string) ($row['table'] ?? '') . ':' . (string) ($row['id'] ?? '');
        $sampleIds[] = $key;
        $samples[$key] = $row;
    }
}
lb(in_array('bookings:' . $safeEmail, $sampleIds, true) && in_array('bookings:' . $safePhone, $sampleIds, true), 'säkra rader syns som id');
lb(!in_array('bookings:' . $ambig, $sampleIds, true) && !in_array('bookings:' . $kept, $sampleIds, true), 'osäkra och redan länkade rader är inte träffar');
$emailVerify = is_array($samples['bookings:' . $safeEmail]['verify'] ?? null) ? $samples['bookings:' . $safeEmail]['verify'] : [];
lb(($samples['bookings:' . $safeEmail]['co'] ?? '') === 'jarfalla' && ($samples['bookings:' . $safeEmail]['co_derived'] ?? true) === false, 'bokningens bolag kommer från raden');
lb(($emailVerify['matched_by_email'] ?? false) === true && ($emailVerify['matched_by_phone'] ?? true) === false && ($emailVerify['match_count_one'] ?? false) === true && ($emailVerify['other_agrees'] ?? false) === true && ($emailVerify['other_absent'] ?? true) === false, 'e-postträffen har en träff och telefonen pekar åt samma håll');
$phoneVerify = is_array($samples['bookings:' . $safePhone]['verify'] ?? null) ? $samples['bookings:' . $safePhone]['verify'] : [];
lb(($phoneVerify['matched_by_phone'] ?? false) === true && ($phoneVerify['matched_by_email'] ?? true) === false && ($phoneVerify['match_count_one'] ?? false) === true && ($phoneVerify['other_absent'] ?? false) === true && ($phoneVerify['other_agrees'] ?? true) === false, 'telefonträffen saknar e-post');
lb(($samples['inkorg_threads:th-src']['contact_id'] ?? '') === 'kt_ada' && ($samples['inkorg_threads:th-src']['co'] ?? '') === 'jarfalla' && ($samples['inkorg_threads:th-src']['co_derived'] ?? false) === true, 'trådens källa ger Järfälla');
lb(($samples['inkorg_threads:th-msg']['contact_id'] ?? '') === 'kt_bo' && ($samples['inkorg_threads:th-msg']['co'] ?? '') === 'malmo' && ($samples['inkorg_threads:th-msg']['co_derived'] ?? false) === true, 'mejlradens källa ger Malmö');
lb(($samples['inkorg_threads:th-list']['contact_id'] ?? '') === 'kt_mail' && ($samples['inkorg_threads:th-list']['co'] ?? '') === 'jarfalla' && ($samples['inkorg_threads:th-list']['co_derived'] ?? false) === true, 'listradens bolag ger Järfälla');
lb(($samples['inkorg_threads:th-safe']['co_derived'] ?? true) === false, 'ett sparat bolag markeras inte som härlett');
lb(!in_array('reminders:' . $failRem, $sampleIds, true), 'en underkänd träff är inte ett förslag som skrivs');
$passCount = (int) ($dry['verify_pass_count'] ?? -1);
$failCount = (int) ($dry['verify_fail_count'] ?? -1);
lb($passCount > count($samples) && count($samples) === 20, 'kontrollen räknar alla förslag, inte bara urvalet');
$sumPass = 0;
$sumFail = 0;
$sumLinked = 0;
foreach ($tables as $name => $row) {
    if (!is_array($row)) {
        continue;
    }
    $tablePass = (int) ($row['verify_pass_count'] ?? -1);
    $tableFail = (int) ($row['verify_fail_count'] ?? -1);
    lb($tablePass >= 0 && $tableFail >= 0, 'tabellen räknar kontrollen: ' . (string) $name);
    $coPass = 0;
    $coFail = 0;
    foreach (is_array($row['co'] ?? null) ? $row['co'] : [] as $coRow) {
        if (!is_array($coRow)) {
            continue;
        }
        $coPass += (int) ($coRow['verify_pass_count'] ?? 0);
        $coFail += (int) ($coRow['verify_fail_count'] ?? 0);
    }
    lb($coPass === $tablePass && $coFail === $tableFail, 'bolagen summerar till tabellen: ' . (string) $name);
    $sumPass += $tablePass;
    $sumFail += $tableFail;
    $sumLinked += (int) ($row['linked'] ?? 0);
}
lb($sumPass === $passCount && $sumFail === $failCount && $sumLinked === $passCount && $failCount === 1, 'pass och fail täcker varje förslag');
$remCo = is_array($tables['reminders']['co'] ?? null) ? $tables['reminders']['co'] : [];
lb(($tables['reminders']['verify_pass_count'] ?? -1) === 1 + $extraLinks && ($tables['reminders']['verify_fail_count'] ?? -1) === 1, 'påminnelserna räknar både godkända och underkända');
lb(($remCo['jarfalla']['verify_pass_count'] ?? -1) === 1 + $extraLinks && ($remCo['jarfalla']['verify_fail_count'] ?? -1) === 1, 'Järfällas påminnelser räknar kontrollen');
lb(($tables['bookings']['verify_pass_count'] ?? -1) === 2 && ($tables['bookings']['verify_fail_count'] ?? -1) === 0, 'bokningarnas träffar klarar kontrollen');
lb(!array_key_exists('full_path', $dry), 'torrkörningen utan full skriver ingen filsökväg');

$full = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill', 'full' => 1]);
lb(!empty($full['ok']) && ($full['dry'] ?? false) === true && ($full['written'] ?? true) === false, 'full=1 skriver inte länkar');
lb(lb_snapshot($pdo) === $before, 'full=1 lämnar raderna');
$fullPath = (string) ($full['full_path'] ?? '');
lb(preg_match('#^contacts-link-backfill/proposed-[a-f0-9]{16}\\.json$#', $fullPath) === 1, 'svaret pekar på filen under data/.private');
$fullFile = $tmp . '/' . $fullPath;
lb(is_file($fullFile), 'förslagslistan ligger på disk');
$fullBody = (string) file_get_contents($fullFile);
$fullJson = json_encode($full, JSON_UNESCAPED_UNICODE);
foreach (['example.test', '@', 'Ada', '070', '+46', 'Maj', 'Bulk'] as $secret) {
    lb(!str_contains($fullBody, $secret) && !str_contains((string) $fullJson, $secret), 'full-svaret saknar ' . $secret);
}
$fullDecoded = json_decode($fullBody, true);
$fullRows = is_array($fullDecoded['rows'] ?? null) ? $fullDecoded['rows'] : [];
lb(count($fullRows) === $passCount + $failCount && count($fullRows) > count($samples), 'filen har varje förslag');
lb(!array_key_exists('proposed', $full), 'listan skrivs till filen och inte i svaret');
$fullById = [];
$fullPii = false;
foreach ($fullRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    foreach (['email', 'phone', 'name', 'customer_name', 'mobile'] as $bannedKey) {
        if (array_key_exists($bannedKey, $row)) {
            $fullPii = true;
        }
    }
    $fullById[(string) ($row['table'] ?? '') . ':' . (string) ($row['id'] ?? '')] = $row;
}
lb($fullPii === false, 'filen har bara id och kontroller');
$failRow = is_array($fullById['reminders:' . $failRem] ?? null) ? $fullById['reminders:' . $failRem] : [];
$failVerify = is_array($failRow['verify'] ?? null) ? $failRow['verify'] : [];
lb(($failRow['contact_id'] ?? '') === 'kt_mail' && ($failVerify['match_count_one'] ?? false) === true && ($failVerify['other_agrees'] ?? true) === false && ($failVerify['other_absent'] ?? true) === false, 'filen visar varför raden underkänns');
$passRow = is_array($fullById['bookings:' . $safeEmail] ?? null) ? $fullById['bookings:' . $safeEmail] : [];
$passVerify = is_array($passRow['verify'] ?? null) ? $passRow['verify'] : [];
lb(($passVerify['match_count_one'] ?? false) === true && (($passVerify['other_agrees'] ?? false) === true || ($passVerify['other_absent'] ?? false) === true), 'filen har en godkänd träff');

$wet = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill', 'dry' => 0]);
lb(!empty($wet['ok']) && ($wet['dry'] ?? true) === false && ($wet['written'] ?? false) === true, 'dry=0 skriver');
$undoId = (string) ($wet['undo_id'] ?? '');
lb(preg_match('/^[a-f0-9]{16}$/', $undoId) === 1 && is_file($tmp . '/contacts-link-backfill/' . $undoId . '.json'), 'ångerfilen ligger under data/.private');
$undoBody = (string) file_get_contents($tmp . '/contacts-link-backfill/' . $undoId . '.json');
lb(!str_contains($undoBody, '@') && !str_contains($undoBody, 'Ada') && str_contains($undoBody, $safeEmail), 'ångerfilen har id och inte persondata');
lb(empty($wet['inkorg_contacts_migrated']) && !is_file($tmp . '/inkorg/contacts.json.migrated'), 'markören sätts inte medan inkorgskontakter saknar länk');
lb(($wet['inkorg_contacts_unmatched'] ?? -1) === 3, 'skrivningen räknar tre olänkade inkorgskontakter');
$wetSamples = is_array($wet['samples'] ?? null) ? $wet['samples'] : [];
lb($wetSamples !== [], 'skrivningen lämnar träffar');
foreach ($wetSamples as $row) {
    if (is_array($row)) {
        lb(array_key_exists('co', $row) && is_bool($row['co_derived'] ?? null) && !array_key_exists('verify', $row), 'skriven träff har bolag och ingen kontroll: ' . (string) ($row['table'] ?? '') . ':' . (string) ($row['id'] ?? ''));
    }
}
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $safeEmail) === 'kt_ada', 'säker e-post fyller bokningen');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $safePhone) === 'kt_fon', 'säker telefon fyller bokningen');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $ambig) === '', 'tvetydig bokning är tom');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $conflict) === '', 'konflikt skriver inget');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $kept) === 'kt_ada', 'befintlig bokningslänk ligger kvar');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $otherCo) === '', 'annat bolag skriver inget');
lb((string) $pdo->query('SELECT starts_at FROM bookings WHERE id = ' . (int) $safeEmail)->fetchColumn() === $stamp, 'bokningens starttid ligger kvar');
lb((string) $pdo->query('SELECT ends_at FROM bookings WHERE id = ' . (int) $safeEmail)->fetchColumn() === $later, 'bokningens sluttid ligger kvar');
lb(lb_cid($pdo, 'SELECT contact_id FROM reminders WHERE id = ' . (int) $safeRem) === 'kt_mail', 'påminnelsen länkas');
lb(lb_cid($pdo, 'SELECT contact_id FROM reminders WHERE id = ' . (int) $failRem) === '', 'en underkänd träff skrivs inte');
lb((int) ($wet['verify_pass_count'] ?? -1) === $passCount && (int) ($wet['verify_fail_count'] ?? -1) === $failCount, 'skrivningen räknar samma kontroll som torrkörningen');
$wetFail = false;
foreach (is_array($wet['uncertain'] ?? null) ? $wet['uncertain'] : [] as $row) {
    if (is_array($row) && (string) ($row['table'] ?? '') === 'reminders' && (string) ($row['id'] ?? '') === $failRem) {
        $wetFail = (string) ($row['reason'] ?? '') === 'verify_failed';
    }
}
lb($wetFail, 'skrivningen listar den underkända raden som verify_failed');
lb(lb_cid($pdo, 'SELECT contact_id FROM reminders WHERE id = ' . (int) $keptRem) === 'kt_bo', 'påminnelsens länk ligger kvar');
lb((string) $pdo->query('SELECT due_at FROM reminders WHERE id = ' . (int) $safeRem)->fetchColumn() === $later, 'påminnelsens tid ligger kvar');
$filled = json_decode((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 801')->fetchColumn(), true);
$listFilled = json_decode((string) $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 801')->fetchColumn(), true);
lb(is_array($filled) && ($filled['contact_id'] ?? '') === 'kt_extra' && ($filled['note'] ?? '') === 'fyll-bara-id', 'ordern får contact_id och behåller övriga fält');
lb(lb_cid($pdo, 'SELECT contact_id FROM purchases WHERE reminder_id = 801') === 'kt_extra', 'orderns kolumn fylls');
lb(is_array($listFilled) && ($listFilled['contact_id'] ?? '') === 'kt_extra' && ($listFilled['note'] ?? '') === 'fyll-bara-id', 'orderns listkopia får bara contact_id');
lb((string) $pdo->query('SELECT updated_at FROM purchases WHERE reminder_id = 801')->fetchColumn() === $stamp, 'orderns tid ligger kvar');
lb((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 802')->fetchColumn() === $purchaseKept, 'ordern med länk är orörd');
lb(lb_cid($pdo, 'SELECT contact_id FROM purchases WHERE reminder_id = 802') === '', 'en fylld payload-länk kopieras inte om');
$threadRow = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-safe'")->fetchColumn(), true);
lb(is_array($threadRow) && ($threadRow['contact_id'] ?? '') === 'kt_extra' && ($threadRow['customer_name'] ?? '') === 'Extra Post', 'tråden länkas via contact_emails');
lb((string) $pdo->query("SELECT updated_at FROM inkorg_threads WHERE id = 'th-safe'")->fetchColumn() === $stamp, 'trådens tid ligger kvar');
lb((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-keep'")->fetchColumn() === $before['thread_keep'], 'tråden med länk är orörd');
lb(lb_cid($pdo, "SELECT contact_id FROM inkorg_list WHERE id = 'ls-bo'") === 'kt_bo', 'listan länkar MEAB mot malmo');
lb(lb_cid($pdo, "SELECT contact_id FROM inkorg_list WHERE id = 'ls-duo'") === '', 'tvetydig lista är tom');
$ic = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'ic-safe'")->fetchColumn(), true);
lb(is_array($ic) && ($ic['contact_id'] ?? '') === 'kt_ada' && ($ic['name'] ?? '') === 'Ada Lov', 'inkorgskontakten länkas och namnet ligger kvar');
lb(lb_cid($pdo, "SELECT contact_id FROM inkorg_contacts WHERE id = 'ic-safe'") === 'kt_ada', 'inkorgskontaktens kolumn fylls');
$icDuo = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'ic-duo'")->fetchColumn(), true);
lb(is_array($icDuo) && !array_key_exists('contact_id', $icDuo), 'tvetydig inkorgskontakt får inget id');
lb(lb_cid($pdo, "SELECT contact_id FROM inkorg_contacts WHERE id = 'ic-keep'") === 'kt_bo', 'inkorgskontaktens länk ligger kvar');
lb((int) $pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn() === 5, 'ingen inkorgskontakt raderas');
lb((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === count($people), 'inga kontakter skapas');
lb((string) $pdo->query("SELECT GROUP_CONCAT(id || row_json, '|') FROM contacts")->fetchColumn() === $before['contact_hash'], 'kontakterna slås inte ihop och skrivs inte om');
lb((string) $pdo->query('SELECT body FROM quotes')->fetchColumn() === 'offert-behall', 'offerter ligger kvar');
lb((string) $pdo->query('SELECT body FROM invoices')->fetchColumn() === 'faktura-behall', 'fakturor ligger kvar');
lb((string) $pdo->query('SELECT body FROM rot_jobs')->fetchColumn() === 'rot-behall', 'rot ligger kvar');
lb((string) $pdo->query('SELECT body FROM mail_snapshots')->fetchColumn() === 'skickat-behall', 'skickad post ligger kvar');
$srcRow = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-src'")->fetchColumn(), true);
lb(is_array($srcRow) && ($srcRow['contact_id'] ?? '') === 'kt_ada' && ($srcRow['source'] ?? '') === 'graph-jarfalla' && !array_key_exists('company', $srcRow) && !array_key_exists('brand', $srcRow), 'härlett bolag skrivs inte på tråden');
$msgRow = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-msg'")->fetchColumn(), true);
lb(is_array($msgRow) && ($msgRow['contact_id'] ?? '') === 'kt_bo' && !array_key_exists('company', $msgRow), 'mejlradens bolag skrivs inte på tråden');
lb((string) $pdo->query("SELECT row_json FROM inkorg_messages WHERE id = 'msg-1'")->fetchColumn() === $msgJson, 'mejlraden är orörd');
$listThread = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-list'")->fetchColumn(), true);
lb(is_array($listThread) && ($listThread['contact_id'] ?? '') === 'kt_mail' && !array_key_exists('company', $listThread), 'listans bolag skrivs inte på tråden');
lb(lb_cid($pdo, "SELECT company FROM inkorg_list WHERE id = 'th-list'") === 'jarfalla' && lb_cid($pdo, "SELECT contact_id FROM inkorg_list WHERE id = 'th-list'") === '', 'listradens bolag och tomma länk ligger kvar');
$clash = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-clash'")->fetchColumn(), true);
lb(is_array($clash) && !array_key_exists('contact_id', $clash) && !array_key_exists('company', $clash), 'motstridig tråd skrivs inte');
$nomatch = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'th-nomatch'")->fetchColumn(), true);
lb(is_array($nomatch) && !array_key_exists('contact_id', $nomatch) && !array_key_exists('company', $nomatch) && ($nomatch['source'] ?? '') === 'graph-malmo', 'träfflös härledning skriver inget bolag');
$inboxStore = new JeabPdoInboxContactStore($pdo);
lb(isset($inboxStore->sqlAll()['ic-safe']) && $inboxStore->findByEmail('ada@example.test') !== [], 'läsningen av inkorg_contacts fortsätter');
lb((int) $pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn() === 5, 'tabellen finns kvar');
$shown = jeab_inkorg_contacts();
lb(isset($shown['ic-safe']) && isset($shown['ic-duo']), 'sidans kontaktlista läser fortfarande de gamla raderna');

$again = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill']);
lb(($again['tables']['bookings']['linked'] ?? -1) === 0 && ($again['tables']['inkorg_contacts']['linked'] ?? -1) === 0, 'en andra torrkörning har inga nya träffar');

$pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute(['kt_bo', (int) $safePhone]);
$undoDry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill_undo', 'undo_id' => $undoId]);
lb(!empty($undoDry['ok']) && ($undoDry['dry'] ?? false) === true && ($undoDry['written'] ?? true) === false, 'undo utan dry skriver inte');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $safeEmail) === 'kt_ada', 'torr undo lämnar länken');
$undo = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill_undo', 'undo_id' => $undoId, 'dry' => 0]);
lb(!empty($undo['ok']) && ($undo['written'] ?? false) === true && ($undo['marker_removed'] ?? true) === false, 'undo återställer och lämnar en markör som inte sattes');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $safeEmail) === '', 'undo tömmer länken som körningen satte');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $safePhone) === 'kt_bo', 'en länk som bytts efteråt ligger kvar');
lb(lb_cid($pdo, 'SELECT contact_id FROM reminders WHERE id = ' . (int) $safeRem) === '', 'undo tömmer påminnelsen');
lb(lb_cid($pdo, 'SELECT contact_id FROM reminders WHERE id = ' . (int) $keptRem) === 'kt_bo', 'undo rör inte påminnelsens gamla länk');
lb(lb_cid($pdo, 'SELECT contact_id FROM purchases WHERE reminder_id = 801') === '', 'undo tömmer ordern');
$restoredPurchase = json_decode((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 801')->fetchColumn(), true);
lb(is_array($restoredPurchase) && !array_key_exists('contact_id', $restoredPurchase) && ($restoredPurchase['note'] ?? '') === 'fyll-bara-id', 'undo tar bort bara contact_id i ordern');
lb((string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 802')->fetchColumn() === $purchaseKept, 'undo rör inte ordern som redan hade länk');
lb(lb_cid($pdo, "SELECT contact_id FROM inkorg_list WHERE id = 'ls-bo'") === '', 'undo tömmer listan');
$icBack = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'ic-safe'")->fetchColumn(), true);
lb(is_array($icBack) && !array_key_exists('contact_id', $icBack) && ($icBack['name'] ?? '') === 'Ada Lov', 'undo tar bort bara contact_id på inkorgskontakten');
lb(!is_file($tmp . '/inkorg/contacts.json.migrated'), 'markören är borta');
lb(isset($inboxStore->sqlAll()['ic-safe']), 'läsningen av inkorg_contacts är igång igen');
lb(lb_cid($pdo, 'SELECT contact_id FROM bookings WHERE id = ' . (int) $kept) === 'kt_ada', 'undo rör inte bokningens gamla länk');
lb((string) $pdo->query('SELECT starts_at FROM bookings WHERE id = ' . (int) $safeEmail)->fetchColumn() === $stamp, 'undo rör inte bokningens tid');
lb((int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === count($people), 'undo skapar inga kontakter');
lb((int) $pdo->query('SELECT COUNT(*) FROM inkorg_contacts')->fetchColumn() === 5, 'undo raderar inga inkorgskontakter');

$pdo->prepare("UPDATE inkorg_contacts SET contact_id = 'kt_bo' WHERE id IN ('ic-malmo', 'ic-duo', 'ic-none')")->execute();
$closed = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill', 'dry' => 0]);
lb(!empty($closed['ok']) && ($closed['inkorg_contacts_unmatched'] ?? -1) === 0, 'noll olänkade inkorgskontakter rapporteras');
lb(!empty($closed['inkorg_contacts_migrated']) && is_file($tmp . '/inkorg/contacts.json.migrated'), 'markören sätts först när inga inkorgskontakter saknar länk');
$closedMarker = (string) file_get_contents($tmp . '/inkorg/contacts.json.migrated');
lb(!str_contains($closedMarker, '@'), 'markören har ingen e-post');
lb($inboxStore->sqlAll() === [] && $inboxStore->findByEmail('ada@example.test') === [], 'läsningen av inkorg_contacts är av efter markören');
$closedShown = jeab_inkorg_contacts();
lb(!isset($closedShown['ic-safe']) && !isset($closedShown['ic-duo']), 'sidans kontaktlista läser inte de gamla raderna');
$closedUndo = (string) ($closed['undo_id'] ?? '');
$reopen = jeab_contacts_sole_dispatch(['action' => 'contacts.link_backfill_undo', 'undo_id' => $closedUndo, 'dry' => 0]);
lb(!empty($reopen['ok']) && ($reopen['marker_removed'] ?? false) === true && !is_file($tmp . '/inkorg/contacts.json.migrated'), 'undo tar bort markören som den körningen satte');
lb(isset($inboxStore->sqlAll()['ic-safe']), 'läsningen är igång när markören är borta');
lb(lb_cid($pdo, "SELECT contact_id FROM inkorg_contacts WHERE id = 'ic-malmo'") === 'kt_bo', 'en länk som redan fanns lämnas av undo');

echo "OK  contacts.link_backfill\n";
