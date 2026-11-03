<?php

declare(strict_types=1);

/**
 * contacts.link_missing_core. Påhittade namn. Svaret har id, bolag och orsak.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-missing-' . bin2hex(random_bytes(4));
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-missing']));
require dirname(__DIR__) . '/src/bootstrap.php';

function lm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/src/contacts_link_missing_core.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
lm(str_contains($hookSrc, "'contacts.link_missing_core'") && str_contains($hookSrc, "'contacts.link_missing_core_undo'"), 'hook.php tar båda åtgärderna');
lm(!str_contains($hookSrc, 'contacts.company_backfill'), 'hook.php saknar company_backfill');
lm(str_contains($dispatchSrc, 'jeab_contacts_link_missing_core(') && str_contains($dispatchSrc, 'jeab_contacts_link_missing_core_undo('), 'dispatch skickar länk och undo');
lm(!is_file($root . '/src/contacts_company_backfill.php'), 'company_backfill-källan är borta');
foreach (['quotes', 'invoices', 'mail_snapshots', 'jeab_kontakt_find_or_create'] as $banned) {
    lm(!str_contains($src, $banned), 'källan innehåller inte ' . $banned);
}

require_once $root . '/src/contacts_link_missing_core.php';
$store = jeab_store();
lm($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

function lm_cols(PDO $pdo, string $table): array
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

function lm_add(PDO $pdo, string $table, string $column): void
{
    if (lm_cols($pdo, $table) !== [] && !isset(lm_cols($pdo, $table)[$column])) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT');
    }
}

foreach (['bookings', 'reminders', 'purchases'] as $table) {
    lm_add($pdo, $table, 'company');
    lm_add($pdo, $table, 'contact_id');
}

$stamp = '2026-01-01 00:00:00';
$pdo->exec("INSERT INTO customers (name, created_at) VALUES ('Testkund', '$stamp')");
$customerId = (int) $pdo->lastInsertId();
$listId = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id LIMIT 1')->fetchColumn();
if ($listId <= 0) {
    $pdo->exec("INSERT INTO reminder_lists (name, created_at) VALUES ('Påminnelser', '$stamp')");
    $listId = (int) $pdo->lastInsertId();
}

$pnr = '198001011234';
$ada = [
    'id' => 'kt_ada',
    'company' => 'jarfalla',
    'street' => 'Storgatan 1',
    'street_key' => 'storgatan 1',
    'postal_code' => '17770',
    'city' => 'Järfälla',
    'owners' => [[
        'name' => 'Ada Lov',
        'email' => 'ada@example.test',
        'phone' => '+46701111111',
        'contact' => true,
        'personnummer' => $pnr,
    ]],
];
$bo = [
    'id' => 'kt_bo',
    'company' => 'malmo',
    'street' => 'Kyrkvagen 2',
    'street_key' => 'kyrkvagen 2',
    'postal_code' => '21120',
    'city' => 'Malmö',
    'owners' => [[
        'name' => 'Bo Ek',
        'email' => 'bo@example.test',
        'phone' => '+46702222222',
        'contact' => true,
    ]],
];
$twinA = [
    'id' => 'kt_twin_a',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Twin A', 'email' => 'twin@example.test', 'contact' => true]],
];
$twinB = [
    'id' => 'kt_twin_b',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Twin B', 'email' => 'twin@example.test', 'contact' => true]],
];
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$ins->execute(['kt_ada', json_encode($ada), 'storgatan 1', '17770', 'ada@example.test', '+46701111111', 'ada lov', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_bo', json_encode($bo), 'kyrkvagen 2', '21120', 'bo@example.test', '+46702222222', 'bo ek', 'malmo', $stamp, $stamp]);
$ins->execute(['kt_twin_a', json_encode($twinA), '', '', 'twin@example.test', '', 'twin a', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_twin_b', json_encode($twinB), '', '', 'twin@example.test', '', 'twin b', 'jarfalla', $stamp, $stamp]);

$book = $pdo->prepare('INSERT INTO bookings (customer_id, customer_name, email, phone, address, assignee, starts_at, ends_at, status, contact_id, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$book->execute([$customerId, 'Ny Malmo', 'ny.malmo@example.test', '', 'Industrigatan 4, 21120 Malmo', 'Test', $stamp, $stamp, 'booked', '', 'malmo', $stamp, $stamp]);
$bookingId = (int) $pdo->lastInsertId();
$book->execute([$customerId, 'Twin', 'twin@example.test', '', '', 'Test', $stamp, $stamp, 'booked', '', 'jarfalla', $stamp, $stamp]);
$ambiguousId = (int) $pdo->lastInsertId();
$book->execute([$customerId, 'Utan bolag', 'ada@example.test', '', '', 'Test', $stamp, $stamp, 'booked', '', '', $stamp, $stamp]);
$noCompanyId = (int) $pdo->lastInsertId();
$book->execute([$customerId, 'Bara adress', '', '', 'Storgatan 1, 17770 Järfälla', 'Test', $stamp, $stamp, 'booked', '', 'jarfalla', $stamp, $stamp]);
$addressId = (int) $pdo->lastInsertId();
$book->execute([$customerId, 'Redan', 'ada@example.test', '', '', 'Test', $stamp, $stamp, 'booked', 'kt_ada', 'jarfalla', $stamp, $stamp]);

$rem = $pdo->prepare('INSERT INTO reminders (list_id, title, customer_name, email, phone, address, contact_id, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$rem->execute([$listId, 'Lucka', 'Ada Lov', 'ada@example.test', '', 'Storgatan 1', '', 'jarfalla', $stamp, $stamp]);
$reminderId = (int) $pdo->lastInsertId();

$purchase = $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, company) VALUES (?, ?, ?, ?, ?)');
$purchase->execute([
    8801,
    json_encode([
        'company' => 'malmo',
        'name' => 'Bo Ek',
        'phone' => '070-222 22 22',
        'personnummer' => $pnr,
        'address' => 'Kyrkvagen 2, 21120 Malmo',
    ], JSON_UNESCAPED_UNICODE),
    $stamp,
    '',
    'malmo',
]);

if (!jeab_contacts_link_missing_core_table($pdo, 'inkorg_list')) {
    $pdo->exec('CREATE TABLE inkorg_list (id TEXT PRIMARY KEY, company TEXT, contact_id TEXT, row_json TEXT)');
}
$pdo->exec("INSERT INTO inkorg_list (id, company, contact_id, row_json) VALUES ('ls-tom', 'jarfalla', '', '{}')");
$pdo->exec("CREATE TABLE IF NOT EXISTS quotes (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS invoices (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS mail_snapshots (id TEXT PRIMARY KEY, body TEXT)");
$pdo->exec("INSERT INTO quotes (id, body) VALUES ('q1', 'offert-behall')");
$pdo->exec("INSERT INTO invoices (id, body) VALUES ('i1', 'faktura-behall')");
$pdo->exec("INSERT INTO mail_snapshots (id, body) VALUES ('m1', 'skickat-behall')");

function lm_contacts(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
}

function lm_row(array $report, string $table, string $id): array
{
    foreach (is_array($report['rows'] ?? null) ? $report['rows'] : [] as $row) {
        if (is_array($row) && (string) ($row['table'] ?? '') === $table && (string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return [];
}

$beforeContacts = lm_contacts($pdo);
$beforeAda = (string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_ada'")->fetchColumn();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.link_missing_core', 'dry' => 0]);
lm(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
lm(lm_contacts($pdo) === $beforeContacts, 'nekad körning skriver inget');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-missing';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_missing_core']);
lm(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? 1) === 0 && empty($dry['undo_id']), 'dry är standard och skriver inget');
lm(lm_contacts($pdo) === $beforeContacts, 'dry skapar ingen kontakt');
$json = json_encode($dry, JSON_UNESCAPED_UNICODE);
lm(is_string($json) && !str_contains($json, $pnr) && !str_contains($json, 'ada@') && !str_contains($json, 'Ada') && !str_contains($json, '070'), 'dry-svaret saknar personuppgifter');
$counts = is_array($dry['counts'] ?? null) ? $dry['counts'] : [];
lm(($counts['link'] ?? -1) === 3 && ($counts['create'] ?? -1) === 1 && ($counts['ambiguous'] ?? -1) === 1 && ($counts['no_company'] ?? -1) === 1, 'förslagen räknas per orsak');
lm(($counts['malmo']['orders'] ?? -1) === 1 && ($counts['malmo']['purchases'] ?? -1) === 1 && ($counts['jarfalla']['reminders'] ?? -1) === 1, 'ordrar speglar köpen');
$remRow = lm_row($dry, 'reminders', (string) $reminderId);
$buyRow = lm_row($dry, 'purchases', '8801');
$bookRow = lm_row($dry, 'bookings', (string) $bookingId);
$ambRow = lm_row($dry, 'bookings', (string) $ambiguousId);
$noneRow = lm_row($dry, 'bookings', (string) $noCompanyId);
$addrRow = lm_row($dry, 'bookings', (string) $addressId);
lm(($remRow['mode'] ?? '') === 'link' && ($remRow['reason'] ?? '') === 'email' && ($remRow['contact_id'] ?? '') === 'kt_ada' && ($remRow['company'] ?? '') === 'jarfalla', 'påminnelsen träffar e-post i samma bolag');
lm(($buyRow['mode'] ?? '') === 'link' && ($buyRow['reason'] ?? '') === 'phone' && ($buyRow['contact_id'] ?? '') === 'kt_bo' && ($buyRow['company'] ?? '') === 'malmo', 'köpet träffar telefon i Malmö');
lm(($bookRow['mode'] ?? '') === 'create' && ($bookRow['reason'] ?? '') === 'create' && preg_match('/^lc[a-f0-9]{16}$/', (string) ($bookRow['contact_id'] ?? '')) === 1, 'bokningen utan träff föreslår en ny kontakt');
lm(($ambRow['reason'] ?? '') === 'ambiguous' && empty($ambRow['contact_id']), 'två träffar lämnas');
lm(($noneRow['reason'] ?? '') === 'no_company' && empty($noneRow['contact_id']), 'tomt bolag lämnas');
lm(($addrRow['mode'] ?? '') === 'link' && ($addrRow['reason'] ?? '') === 'address' && ($addrRow['contact_id'] ?? '') === 'kt_ada', 'adress träffar gata och postnummer');

$applied = jeab_contacts_sole_dispatch(['action' => 'contacts.link_missing_core', 'dry' => 0]);
lm(!empty($applied['ok']) && ($applied['dry'] ?? true) === false && ($applied['written'] ?? 0) === 4, 'dry=0 skriver länk och ny kontakt');
$undoId = (string) ($applied['undo_id'] ?? '');
lm(preg_match('/^[a-f0-9]{16}$/', $undoId) === 1, 'körningen lämnar ett ånger-id');
$appliedJson = json_encode($applied, JSON_UNESCAPED_UNICODE);
lm(is_string($appliedJson) && !str_contains($appliedJson, $pnr), 'skrivsvaret saknar personnummer');
lm(lm_contacts($pdo) === $beforeContacts + 1, 'en kontakt skapas och ingen slås ihop');
lm((string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_ada'")->fetchColumn() === $beforeAda, 'befintlig kontakt är orörd');
$createdId = (string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . $bookingId)->fetchColumn();
lm(preg_match('/^lc[a-f0-9]{16}$/', $createdId) === 1, 'bokningen fick den nya kontakten');
$createdJson = (string) $pdo->query('SELECT row_json FROM contacts WHERE id = ' . $pdo->quote($createdId))->fetchColumn();
lm($createdJson !== '' && !str_contains($createdJson, $pnr) && str_contains($createdJson, 'ny.malmo@example.test') && str_contains($createdJson, 'malmo'), 'nya kontakten byggs av raden och utan personnummer');
lm((string) $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . $reminderId)->fetchColumn() === 'kt_ada', 'påminnelsen länkas');
$payload = (string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 8801')->fetchColumn();
lm(str_contains($payload, 'kt_bo') && str_contains($payload, $pnr), 'köpets payload får contact_id och behåller övriga fält');
lm((string) $pdo->query('SELECT contact_id FROM purchases WHERE reminder_id = 8801')->fetchColumn() === 'kt_bo', 'köpets kolumn länkas');
lm((string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . $ambiguousId)->fetchColumn() === '', 'tvetydig rad skrivs inte');
lm((string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . $noCompanyId)->fetchColumn() === '', 'rad utan bolag skrivs inte');
lm((string) $pdo->query("SELECT contact_id FROM inkorg_list WHERE id = 'ls-tom'")->fetchColumn() === '', 'inkorgen rörs inte');
lm((string) $pdo->query('SELECT body FROM quotes')->fetchColumn() === 'offert-behall', 'offerter ligger kvar');
lm((string) $pdo->query('SELECT body FROM invoices')->fetchColumn() === 'faktura-behall', 'fakturor ligger kvar');
lm((string) $pdo->query('SELECT body FROM mail_snapshots')->fetchColumn() === 'skickat-behall', 'skickad post ligger kvar');

$undoDry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_missing_core_undo', 'undo_id' => $undoId]);
lm(!empty($undoDry['ok']) && ($undoDry['dry'] ?? false) === true && ($undoDry['written'] ?? 1) === 0 && ($undoDry['restored'] ?? 0) === 4 && ($undoDry['deleted'] ?? 0) === 1, 'undo är torr som standard');
lm((string) $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . $reminderId)->fetchColumn() === 'kt_ada', 'torr undo skriver inte');
lm(lm_contacts($pdo) === $beforeContacts + 1, 'torr undo tar inte bort kontakten');

$undo = jeab_contacts_sole_dispatch(['action' => 'contacts.link_missing_core_undo', 'undo_id' => $undoId, 'dry' => '0']);
lm(!empty($undo['ok']) && ($undo['restored'] ?? 0) === 4 && ($undo['deleted'] ?? 0) === 1, 'undo återställer och tar bort den nya kontakten');
lm((string) $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . $reminderId)->fetchColumn() === '', 'påminnelsens länk är borta');
lm((string) $pdo->query('SELECT contact_id FROM bookings WHERE id = ' . $bookingId)->fetchColumn() === '', 'bokningens länk är borta');
lm((string) $pdo->query('SELECT contact_id FROM purchases WHERE reminder_id = 8801')->fetchColumn() === '', 'köpets kolumn är tom');
$payloadAfter = (string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 8801')->fetchColumn();
lm(!str_contains($payloadAfter, 'kt_bo') && str_contains($payloadAfter, $pnr), 'köpets contact_id tas bort och personnumret ligger kvar i lagret');
lm(lm_contacts($pdo) === $beforeContacts, 'skapad kontakt är borta');
lm((string) $pdo->query("SELECT id FROM contacts WHERE id = 'kt_ada'")->fetchColumn() === 'kt_ada', 'länkad kontakt finns kvar');
lm((string) $pdo->query('SELECT body FROM quotes')->fetchColumn() === 'offert-behall', 'undo rör inte offerter');

$port = 18180 + random_int(0, 800);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-missing']));
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($public, true) . ";\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$log = $tmp . '/php-server.log';
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root), escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
lm(is_resource($proc), 'php -S startar');
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
lm($up, 'php -S lyssnar');
$ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => '{"action":"contacts.link_missing_core","dry":1}',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Token: tok-link-missing'],
    CURLOPT_RETURNTRANSFER => true,
]);
$raw = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$decoded = json_decode(is_string($raw) ? $raw : '', true);
lm($status === 200 && is_array($decoded) && ($decoded['action'] ?? '') === 'contacts.link_missing_core' && ($decoded['dry'] ?? false) === true && ($decoded['written'] ?? 1) === 0, 'curl med token får torr körning');
lm(is_string($raw) && !str_contains($raw, $pnr) && !str_contains($raw, 'example.test'), 'curl-svaret saknar personuppgifter');
lm(lm_contacts($pdo) === $beforeContacts, 'curl skriver inga kontakter');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "OK  contacts.link_missing_core\n";
