<?php

declare(strict_types=1);

/**
 * Lead utan contact_id ligger kvar på ärendet, inte i app_error.
 * Hooken ska rapportera id och, vid dry=0, skapa en ny kontakt utan att slå ihop.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-contact-gap-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contact_create.php';

function gap(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_store()->pdo();
gap($pdo instanceof PDO, 'sqlite är öppen');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_list (
    id TEXT PRIMARY KEY,
    company TEXT NOT NULL DEFAULT \'\',
    customer_email TEXT NOT NULL DEFAULT \'\',
    list_at TEXT NOT NULL DEFAULT \'\',
    list_status TEXT NOT NULL DEFAULT \'\',
    contact_id TEXT NOT NULL DEFAULT \'\',
    row_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_threads (
    id TEXT PRIMARY KEY,
    status TEXT NOT NULL DEFAULT \'open\',
    updated_at TEXT,
    row_json TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE IF NOT EXISTS inkorg_contacts (
    id TEXT PRIMARY KEY,
    row_json TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT \'\',
    mobile TEXT NOT NULL DEFAULT \'\',
    updated_at TEXT NOT NULL,
    contact_id TEXT NOT NULL DEFAULT \'\'
)');

$leadId = 'ink_' . bin2hex(random_bytes(8));
$phoneId = 'ink_' . bin2hex(random_bytes(8));
$failId = 'ink_' . bin2hex(random_bytes(8));
$linkedId = 'ink_' . bin2hex(random_bytes(8));
$otherId = 'ink_' . bin2hex(random_bytes(8));
$email = 'gap.person@example.test';
$phone = '+46701112233';
$name = 'Ada Gap';

$putList = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
$putThread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)');
$store = static function (string $id, array $row, string $contactId) use ($putList, $putThread): void {
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $putList->execute([
        $id,
        'jarfalla',
        (string) ($row['customer_email'] ?? ''),
        '2026-10-01T10:00:00+02:00',
        'open',
        $contactId,
        $json,
    ]);
    $putThread->execute([$id, 'open', '2026-10-01T10:00:00+02:00', $json]);
};
$store($leadId, [
    'id' => $leadId,
    'list_type' => 'Ny lead',
    'customer_email' => $email,
    'customer_name' => $name,
    'contact_id' => '',
    'lead_customer' => ['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => 'Gapgatan 1'],
], '');
$store($phoneId, [
    'id' => $phoneId,
    'list_type' => 'Ny lead',
    'customer_email' => '',
    'contact_id' => '',
    'lead_customer' => ['name' => '', 'email' => '', 'phone' => '+46702223344'],
], '');
$store($failId, [
    'id' => $failId,
    'list_type' => 'Ny lead',
    'customer_email' => 'fail.person@example.test',
    'contact_id' => '',
    'contact_error' => 'Kunde inte spara kontakten.',
    'lead_customer' => ['email' => 'fail.person@example.test', 'phone' => ''],
], '');
$store($linkedId, [
    'id' => $linkedId,
    'list_type' => 'Ny lead',
    'customer_email' => 'linked.person@example.test',
    'contact_id' => 'kt_already',
    'lead_customer' => ['email' => 'linked.person@example.test', 'phone' => ''],
], 'kt_already');
$store($otherId, [
    'id' => $otherId,
    'list_type' => 'Fråga',
    'customer_email' => 'other.person@example.test',
    'contact_id' => '',
], '');

$log = new PDO('sqlite::memory:');
$log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$log->exec('CREATE TABLE log_lines (id INTEGER PRIMARY KEY, type TEXT NOT NULL, level TEXT NOT NULL, at TEXT NOT NULL, payload TEXT NOT NULL)');
$log->prepare('INSERT INTO log_lines (type, level, at, payload) VALUES (?, ?, ?, ?)')->execute([
    'db',
    'error',
    '2026-10-01 10:00:00',
    json_encode(['surface' => 'db', 'message' => 'Kunde inte spara kontakten.', 'ref' => 'c-sqlsave1'], JSON_UNESCAPED_UNICODE),
]);
$GLOBALS['_JEAB_LOG_PDO'] = $log;
$pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, email, updated_at) VALUES (?, ?, ?, ?)')->execute([
    'c-sqlsave1',
    json_encode(['id' => 'c-sqlsave1', 'name' => 'Sql', 'emails' => ['sql.save@example.test']], JSON_UNESCAPED_UNICODE),
    'sql.save@example.test',
    '2026-10-01 10:00:00',
]);

$seed = jeab_kontakt_create_unmerged(['name' => 'Finns', 'email' => $email, 'phone' => '', 'source' => 'seed']);
gap(is_array($seed) && ($seed['id'] ?? '') !== '', 'befintlig kontakt finns');
$seedId = (string) $seed['id'];

$dry = jeab_inkorg_contact_create_run($pdo, []);
$encoded = json_encode($dry, JSON_UNESCAPED_UNICODE);
$debug = is_array($dry['debug'] ?? null) ? $dry['debug'] : [];
$candidates = is_array($dry['candidate_ids'] ?? null) ? $dry['candidate_ids'] : [];
$failures = is_array($dry['failure_ids'] ?? null) ? $dry['failure_ids'] : [];
gap(($dry['dry'] ?? false) === true && (int) ($dry['created'] ?? 1) === 0, 'torr körning skapar inget');
gap(in_array($leadId, $candidates, true) && in_array($phoneId, $candidates, true), 'lead med e-post eller telefon är kandidat');
gap(!in_array($failId, $candidates, true) && in_array($failId, $failures, true), 'contact_error är felkälla, inte kandidat');
gap(!in_array($linkedId, $candidates, true) && !in_array($otherId, $candidates, true), 'kopplad rad och icke-lead tas inte med');
gap((int) ($debug['thread_contact_error'] ?? 0) === 1 && (int) ($debug['candidates'] ?? 0) === 2, 'debug räknar felrad och kandidater');
gap(in_array('c-sqlsave1', (array) ($dry['ids'] ?? []), true) && (int) ($debug['failure_text'] ?? 0) >= 1, 'SQL-sparningens text räknas när den finns');
gap(is_string($encoded) && !str_contains($encoded, $email) && !str_contains($encoded, $phone) && !str_contains($encoded, $name) && !str_contains($encoded, 'fail.person@example.test'), 'svaret har bara id');

$wet = jeab_inkorg_contact_create_run($pdo, ['dry' => 0]);
gap((int) ($wet['created'] ?? 0) === 3, 'wet skapar rena kandidater, felraden och sql-ref');
$linked = json_decode((string) $pdo->query('SELECT row_json FROM inkorg_list WHERE id = ' . $pdo->quote($leadId))->fetchColumn(), true);
gap(is_array($linked) && (string) ($linked['contact_id'] ?? '') === '', 'lead med samma e-post lämnas okopplad');
$threadContact = json_decode((string) $pdo->query('SELECT row_json FROM inkorg_threads WHERE id = ' . $pdo->quote($leadId))->fetchColumn(), true);
gap(is_array($threadContact) && (string) ($threadContact['contact_id'] ?? '') === '', 'tråden lämnas okopplad');
$seedAfter = jeab_kontakter_mutate(static function (array &$contacts) use ($seedId): array {
    return is_array($contacts[$seedId] ?? null) ? $contacts[$seedId] : [];
});
gap(($seedAfter['id'] ?? '') === $seedId, 'befintlig kontakt med samma e-post ligger kvar');
$failLinked = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($failId))->fetchColumn();
gap($failLinked !== '' && str_starts_with($failLinked, 'kt_'), 'felraden får en egen kontakt');
$other = (string) $pdo->query('SELECT contact_id FROM inkorg_list WHERE id = ' . $pdo->quote($otherId))->fetchColumn();
gap($other === '', 'icke-lead lämnas tom');

echo "inkorg_contact_create_candidates_test ok\n";
