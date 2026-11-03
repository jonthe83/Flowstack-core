<?php

declare(strict_types=1);

/**
 * contacts.link_by_email. Påhittade adresser. Svaret har bara id.
 * Exakt träff länkas. Två kontakter och annat bolag lämnas. Ångra tar bara oförändrade rader.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-by-email-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-email']));
require dirname(__DIR__) . '/src/bootstrap.php';

function lbe(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/src/contacts_link_by_email.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
lbe(str_contains($hookSrc, "'contacts.link_by_email'") && str_contains($hookSrc, "'contacts.link_by_email_undo'"), 'hook.php tar båda åtgärderna');
lbe(str_contains($dispatchSrc, "=== 'contacts.link_by_email'") && str_contains($dispatchSrc, 'jeab_contacts_link_by_email('), 'dispatch skickar länk');
lbe(str_contains($dispatchSrc, 'jeab_contacts_link_by_email_undo('), 'dispatch skickar undo');
foreach (['quotes', 'invoices', 'mail_snapshot', 'INSERT INTO contacts', 'DELETE FROM', 'UPDATE contacts'] as $banned) {
    lbe(!str_contains($src, $banned), 'källan innehåller inte ' . $banned);
}
lbe(str_contains($src, 'contacts-link-by-email'), 'ångerfilen ligger under contacts-link-by-email');

$store = jeab_store();
lbe($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

function lbe_cols(PDO $pdo, string $table): array
{
    $have = [];
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    } catch (Throwable) {
        return [];
    }
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (is_array($row) && isset($row['name'])) {
            $have[(string) $row['name']] = true;
        }
    }

    return $have;
}

function lbe_add(PDO $pdo, string $table, string $column): void
{
    if (lbe_cols($pdo, $table) !== [] && !isset(lbe_cols($pdo, $table)[$column])) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT \'\'');
    }
}

if (!isset(lbe_cols($pdo, 'contacts')['id'])) {
    $pdo->exec("CREATE TABLE contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL DEFAULT '{}',
        owner_email TEXT NOT NULL DEFAULT '',
        secondary_email TEXT NOT NULL DEFAULT '',
        company TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}
lbe_add($pdo, 'contacts', 'row_json');
lbe_add($pdo, 'contacts', 'owner_email');
lbe_add($pdo, 'contacts', 'secondary_email');
lbe_add($pdo, 'contacts', 'company');
if (lbe_cols($pdo, 'inkorg_threads') === []) {
    $pdo->exec("CREATE TABLE inkorg_threads (
        id TEXT PRIMARY KEY,
        status TEXT NOT NULL DEFAULT 'open',
        updated_at TEXT,
        row_json TEXT NOT NULL
    )");
}
lbe_add($pdo, 'inkorg_threads', 'contact_id');
if (lbe_cols($pdo, 'inkorg_list') === []) {
    $pdo->exec("CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT '',
        customer_email TEXT NOT NULL DEFAULT '',
        contact_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}
lbe_add($pdo, 'inkorg_list', 'company');
lbe_add($pdo, 'inkorg_list', 'customer_email');
lbe_add($pdo, 'inkorg_list', 'contact_id');
lbe_add($pdo, 'inkorg_list', 'row_json');
$pdo->exec('CREATE TABLE IF NOT EXISTS quotes (id TEXT PRIMARY KEY, body TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS invoices (id TEXT PRIMARY KEY, body TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS mail_snapshots (id TEXT PRIMARY KEY, body TEXT)');
$pdo->exec("INSERT INTO quotes (id, body) VALUES ('q1', 'offert-behall')");
$pdo->exec("INSERT INTO invoices (id, body) VALUES ('i1', 'faktura-behall')");
$pdo->exec("INSERT INTO mail_snapshots (id, body) VALUES ('m1', 'skickat-behall')");

$stamp = '2026-01-01 00:00:00';
$people = [
    'kt_ada' => ['jarfalla', 'ada@example.test', ''],
    'kt_ada_m' => ['malmo', 'ada@example.test', ''],
    'kt_sec' => ['jarfalla', '', 'sec@example.test'],
    'kt_duo1' => ['jarfalla', 'duo@example.test', ''],
    'kt_duo2' => ['jarfalla', 'duo@example.test', ''],
    'kt_malmo' => ['malmo', 'only-malmo@example.test', ''],
];
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, owner_email, secondary_email, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($people as $id => $person) {
    $json = json_encode([
        'id' => $id,
        'company' => $person[0],
        'owner_email' => $person[1],
        'name' => 'Person ' . $id,
    ], JSON_UNESCAPED_UNICODE);
    $ins->execute([$id, $json, $person[1], $person[2], $person[0], $stamp, $stamp]);
}

$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$listJson = static function (array $extra): string {
    $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
};
$list->execute(['list-exact', 'jarfalla', 'Ada@Example.TEST', '', $listJson([
    'customer_name' => 'Behall Namn',
    'customer_email' => 'Ada@Example.TEST',
    'note' => 'list-exact-behall',
])]);
$list->execute(['list-malmo', 'malmo', 'ada@example.test', '', $listJson([
    'customer_name' => 'Malmo Rad',
    'customer_email' => 'ada@example.test',
    'note' => 'list-malmo-behall',
])]);
$list->execute(['list-amb', 'jarfalla', 'duo@example.test', '', $listJson([
    'customer_name' => 'Tva',
    'customer_email' => 'duo@example.test',
])]);
$list->execute(['list-other', 'JEAB', 'only-malmo@example.test', '', $listJson([
    'customer_name' => 'Annat bolag',
    'customer_email' => 'only-malmo@example.test',
])]);
$list->execute(['list-none', 'jarfalla', '', '', $listJson([
    'customer_name' => 'Utan post',
])]);
$list->execute(['list-keep', 'jarfalla', 'ada@example.test', 'kt_ada', $listJson([
    'customer_name' => 'Redan',
    'customer_email' => 'ada@example.test',
    'contact_id' => 'kt_ada',
])]);
$list->execute(['case-sec', 'jarfalla', '', '', $listJson([
    'customer_name' => 'Case Behall',
    'company' => 'jarfalla',
    'labels' => ['type' => 'Ny lead'],
    'lead_customer' => ['email' => 'Sec@Example.TEST', 'name' => 'Sekund', 'phone' => '0700000000'],
    'note' => 'case-behall',
])]);
$list->execute(['case-phone', 'jarfalla', '', '', $listJson([
    'company' => 'jarfalla',
    'labels' => ['type' => 'Ny lead'],
    'lead_customer' => ['phone' => '0701234567', 'name' => 'Bara telefon'],
])]);

$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json, contact_id) VALUES (?, ?, ?, ?, ?)');
$thread->execute(['th-exact', 'open', $stamp, $listJson([
    'company' => 'jarfalla',
    'customer_email' => 'ada@example.test',
    'customer_name' => 'Trad Behall',
    'note' => 'th-behall',
]), '']);
$thread->execute(['th-other', 'open', $stamp, $listJson([
    'company' => 'malmo',
    'customer_email' => 'sec@example.test',
    'customer_name' => 'Fel bolag',
]), '']);

$thread->execute(['th-from', 'closed', $stamp, $listJson([
    'company' => 'jarfalla',
    'first' => ['from' => 'Ada@Example.TEST', 'subject' => 'fraga'],
    'note' => 'th-from-behall',
]), '']);
$thread->execute(['th-own', 'closed', $stamp, $listJson([
    'company' => 'jarfalla',
    'first' => ['from' => 'info@jarfalla.example.test'],
]), '']);
$thread->execute(['th-platform', 'closed', $stamp, $listJson([
    'company' => 'jarfalla',
    'first' => ['from' => 'noreply@offerta.se'],
]), '']);
$thread->execute(['th-from-amb', 'closed', $stamp, $listJson([
    'company' => 'jarfalla',
    'first' => ['from' => 'duo@example.test'],
]), '']);
foreach (['th-hs' => 'reply@helpscout.net', 'th-hs-sub' => 'x@mail.helpscout.net', 'th-wix' => 'orders@wix.com', 'th-wixstores' => 'no-reply@stores-emails.com'] as $svcId => $svcFrom) {
    $thread->execute([$svcId, 'closed', $stamp, $listJson([
        'company' => 'jarfalla',
        'first' => ['from' => $svcFrom],
    ]), '']);
}

function lbe_contact_hash(PDO $pdo): string
{
    return (string) $pdo->query("SELECT GROUP_CONCAT(id || row_json || owner_email || secondary_email || company, '|') FROM contacts")->fetchColumn();
}

function lbe_sentinels(PDO $pdo): array
{
    return [
        'quotes' => (string) $pdo->query('SELECT body FROM quotes')->fetchColumn(),
        'invoices' => (string) $pdo->query('SELECT body FROM invoices')->fetchColumn(),
        'mail' => (string) $pdo->query('SELECT body FROM mail_snapshots')->fetchColumn(),
        'contacts' => lbe_contact_hash($pdo),
        'contact_count' => (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
    ];
}

function lbe_json(PDO $pdo, string $table, string $id): array
{
    $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);
    $raw = $stmt->fetchColumn();
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($decoded) ? $decoded : [];
}

function lbe_cid(PDO $pdo, string $table, string $id): string
{
    $cols = lbe_cols($pdo, $table);
    if (!isset($cols['contact_id'])) {
        return trim((string) (lbe_json($pdo, $table, $id)['contact_id'] ?? ''));
    }
    $stmt = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);

    return trim((string) $stmt->fetchColumn());
}

function lbe_link(array $block, string $id): string
{
    foreach ($block['would_link']['ids'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
            return (string) ($row['contact_id'] ?? '');
        }
    }

    return '';
}

function lbe_ids(array $block, string $reason): array
{
    $ids = $block['remaining'][$reason]['ids'] ?? [];

    return is_array($ids) ? array_map('strval', $ids) : [];
}

$before = lbe_sentinels($pdo);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email', 'dry' => 0]);
lbe(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
lbe(lbe_sentinels($pdo) === $before, 'nekad körning skriver inget');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-email';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email']);
lbe(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'utelämnad dry skriver inte');
lbe(($dry['undo_id'] ?? '') === '', 'torrkörningen har inget undo-id');
lbe(!is_dir($tmp . '/contacts-link-by-email'), 'torrkörningen skriver ingen ångerfil');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-exact') === '', 'torrkörningen lämnar contact_id tom');
lbe(lbe_sentinels($pdo) === $before, 'torrkörningen lämnar kontakter och skickat material');

$jarfallaList = $dry['companies']['jarfalla']['inkorg_list'] ?? [];
$malmoList = $dry['companies']['malmo']['inkorg_list'] ?? [];
$jarfallaCases = $dry['companies']['jarfalla']['inkorg_cases'] ?? [];
$jarfallaThreads = $dry['companies']['jarfalla']['inkorg_threads'] ?? [];
$malmoThreads = $dry['companies']['malmo']['inkorg_threads'] ?? [];
lbe(lbe_link($jarfallaList, 'list-exact') === 'kt_ada', 'exakt träff i listan pekar på en kontakt');
lbe(lbe_link($malmoList, 'list-malmo') === 'kt_ada_m', 'samma e-post i Malmö pekar på Malmökontakten');
lbe(lbe_link($jarfallaList, 'list-amb') === '', 'två kontakter länkas inte');
lbe(in_array('list-amb', lbe_ids($jarfallaList, 'ambiguous_email'), true), 'två kontakter räknas som ambiguous_email');
lbe(lbe_link($jarfallaList, 'list-other') === '', 'annat bolag länkas inte');
lbe(in_array('list-other', lbe_ids($jarfallaList, 'no_match'), true), 'annat bolag räknas som no_match');
lbe(in_array('list-none', lbe_ids($jarfallaList, 'no_email'), true), 'tom e-post räknas som no_email');
lbe(lbe_link($jarfallaList, 'list-keep') === '', 'en ifylld länk föreslås inte');
lbe(!in_array('list-keep', lbe_ids($jarfallaList, 'no_match'), true), 'en ifylld länk räknas inte som osäker');
lbe(lbe_link($jarfallaCases, 'case-sec') === 'kt_sec', 'sekundär e-post på ett ärende länkas');
lbe(in_array('case-phone', lbe_ids($jarfallaCases, 'no_email'), true), 'ärende utan e-post lämnas');
lbe(lbe_link($jarfallaThreads, 'th-exact') === 'kt_ada', 'exakt träff på en tråd länkas');
lbe(lbe_link($malmoThreads, 'th-other') === '', 'sekundär e-post i annat bolag länkas inte');
lbe(in_array('th-other', lbe_ids($malmoThreads, 'no_match'), true), 'tråd i annat bolag räknas som no_match');
lbe(lbe_link($jarfallaList, 'case-sec') === 'kt_sec', 'ärendet räknas också i listan');
lbe((int) ($jarfallaList['would_link']['count'] ?? 0) === 2, 'listan i Järfälla har listträff och ärende');
lbe((int) ($jarfallaList['remaining']['ambiguous_email']['count'] ?? 0) === 1, 'antalet tvetydiga stämmer');
lbe(lbe_link($jarfallaThreads, 'th-from') === 'kt_ada', 'första avsändaren länkar en vanlig mejltråd');
lbe(in_array('th-own', lbe_ids($jarfallaThreads, 'from_internal'), true), 'egen domän länkas inte och får from_internal');
lbe(in_array('th-platform', lbe_ids($jarfallaThreads, 'from_platform'), true), 'leadplattform länkas inte och får from_platform');
lbe(in_array('th-from-amb', lbe_ids($jarfallaThreads, 'ambiguous_email'), true), 'avsändare med två kontakter länkas inte');
foreach (['th-hs', 'th-hs-sub', 'th-wix', 'th-wixstores'] as $svcId) {
    lbe(lbe_link($jarfallaThreads, $svcId) === '' && in_array($svcId, lbe_ids($jarfallaThreads, 'from_platform'), true), $svcId . ' länkas inte och får from_platform');
}
lbe(jeab_contacts_link_by_email_first_from(['first' => ['from' => 'orders@wix.com']])['skip'] === 'from_platform', 'orders@wix.com hoppas över direkt');

$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
lbe(is_string($dryJson), 'svaret kan kodas');
foreach (['example.test', '@', 'Behall', 'Sekund', '070', 'offert', 'faktura', 'Person '] as $secret) {
    lbe(!str_contains((string) $dryJson, $secret), 'svaret saknar ' . $secret);
}

$wet = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email', 'dry' => 0]);
lbe(!empty($wet['ok']) && ($wet['dry'] ?? true) === false && !empty($wet['written']), 'dry=0 skriver');
$undoId = (string) ($wet['undo_id'] ?? '');
lbe(preg_match('/^[a-f0-9]{16}$/', $undoId) === 1, 'undo-id är satt');
$undoPath = $tmp . '/contacts-link-by-email/' . $undoId . '.json';
lbe(is_file($undoPath), 'ångerfilen ligger i contacts-link-by-email');
$undoFile = (string) file_get_contents($undoPath);
lbe(!str_contains($undoFile, 'example.test') && !str_contains($undoFile, '@'), 'ångerfilen saknar e-post');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-exact') === 'kt_ada', 'listan fick contact_id');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-malmo') === 'kt_ada_m', 'Malmölistan fick sin kontakt');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-amb') === '', 'tvetydig rad skrevs inte');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-other') === '', 'annat bolag skrevs inte');
lbe(lbe_cid($pdo, 'inkorg_list', 'case-sec') === 'kt_sec', 'ärendet fick contact_id');
lbe(lbe_cid($pdo, 'inkorg_threads', 'th-exact') === 'kt_ada', 'tråden fick contact_id');
lbe(lbe_cid($pdo, 'inkorg_threads', 'th-other') === '', 'tråden i annat bolag skrevs inte');
lbe(lbe_cid($pdo, 'inkorg_threads', 'th-from') === 'kt_ada', 'mejltråden fick contact_id');
lbe(!array_key_exists('customer_email', lbe_json($pdo, 'inkorg_threads', 'th-from')), 'avsändaren kopieras inte till customer_email');
lbe(lbe_cid($pdo, 'inkorg_threads', 'th-own') === '' && lbe_cid($pdo, 'inkorg_threads', 'th-platform') === '' && lbe_cid($pdo, 'inkorg_threads', 'th-from-amb') === '', 'egen domän, plattform och tvetydig skrevs inte');
lbe((lbe_json($pdo, 'inkorg_list', 'list-exact')['note'] ?? '') === 'list-exact-behall', 'övriga fält i listan är kvar');
lbe((lbe_json($pdo, 'inkorg_list', 'list-exact')['customer_name'] ?? '') === 'Behall Namn', 'namnet i listan är kvar');
lbe((string) $pdo->query("SELECT customer_email FROM inkorg_list WHERE id = 'list-exact'")->fetchColumn() === 'Ada@Example.TEST', 'e-postkolumnen är orörd');
lbe((lbe_json($pdo, 'inkorg_list', 'case-sec')['note'] ?? '') === 'case-behall', 'övriga fält i ärendet är kvar');
lbe((lbe_json($pdo, 'inkorg_threads', 'th-exact')['note'] ?? '') === 'th-behall', 'övriga fält i tråden är kvar');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-keep') === 'kt_ada', 'en ifylld länk skrevs inte över');
lbe(lbe_sentinels($pdo) === $before, 'kontakter, offerter, fakturor och mejlkopior är orörda');

$undoDry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email_undo', 'undo_id' => $undoId]);
lbe(!empty($undoDry['ok']) && ($undoDry['dry'] ?? false) === true && empty($undoDry['written']), 'undo utan dry skriver inte');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-exact') === 'kt_ada', 'torr undo lämnar länken');
lbe(lbe_link(['would_link' => $undoDry['companies']['jarfalla']['inkorg_list']['would_unlink'] ?? []], 'list-exact') === 'kt_ada', 'torr undo visar raden');

$changed = lbe_json($pdo, 'inkorg_list', 'list-exact');
$changed['customer_name'] = 'Andrat senare';
$changedJson = json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdo->prepare('UPDATE inkorg_list SET row_json = ? WHERE id = ?')->execute([$changedJson, 'list-exact']);

$undoWet = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email_undo', 'undo_id' => $undoId, 'dry' => 0]);
lbe(!empty($undoWet['ok']) && !empty($undoWet['written']), 'undo dry=0 skriver');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-exact') === 'kt_ada', 'ändrad rad behåller länken');
lbe((lbe_json($pdo, 'inkorg_list', 'list-exact')['customer_name'] ?? '') === 'Andrat senare', 'ändrad text lämnas');
lbe(in_array('list-exact', $undoWet['companies']['jarfalla']['inkorg_list']['skipped']['ids'] ?? [], true), 'ändrad rad räknas som skipped');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-malmo') === '', 'oförändrad listlänk tas bort');
lbe(lbe_cid($pdo, 'inkorg_list', 'case-sec') === '', 'oförändrad ärendelänk tas bort');
lbe(lbe_cid($pdo, 'inkorg_threads', 'th-exact') === '', 'oförändrad trådlänk tas bort');
lbe(lbe_cid($pdo, 'inkorg_threads', 'th-from') === '', 'undo tar bort länken från avsändarmatchningen');
lbe((lbe_json($pdo, 'inkorg_threads', 'th-exact')['note'] ?? '') === 'th-behall', 'trådens övriga fält är kvar efter undo');
lbe((lbe_json($pdo, 'inkorg_list', 'case-sec')['note'] ?? '') === 'case-behall', 'ärendets övriga fält är kvar efter undo');
lbe(!array_key_exists('contact_id', lbe_json($pdo, 'inkorg_threads', 'th-exact')), 'tråden hade ingen contact_id innan och får ingen tom nyckel');
lbe(lbe_sentinels($pdo) === $before, 'undo rör inte kontakter eller skickat material');
lbe(lbe_cid($pdo, 'inkorg_list', 'list-amb') === '' && lbe_cid($pdo, 'inkorg_list', 'list-other') === '', 'osäkra rader är fortfarande olänkade');

echo "OK  contacts.link_by_email\n";
