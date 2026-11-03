<?php

declare(strict_types=1);

/**
 * contacts.link_inkorg_from_reminder. Påhittade id. Svaret har id och antal.
 * En påminnelse med samma bolag länkar. Olika kontakter och annat bolag lämnas.
 * Ångra tar bara oförändrade rader. Inget skickas och inga kontakter skapas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-reminder-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-reminder']));
file_put_contents($tmp . '/snap-marker.txt', "snapshot-behall\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lir(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/src/contacts_link_inkorg_from_reminder.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
lir(str_contains($hookSrc, "'contacts.link_inkorg_from_reminder'") && str_contains($hookSrc, "'contacts.link_inkorg_from_reminder_undo'"), 'hook.php tar båda åtgärderna');
lir(str_contains($dispatchSrc, "=== 'contacts.link_inkorg_from_reminder'") && str_contains($dispatchSrc, 'jeab_contacts_link_inkorg_from_reminder('), 'dispatch skickar länk');
lir(str_contains($dispatchSrc, 'jeab_contacts_link_inkorg_from_reminder_undo('), 'dispatch skickar undo');
foreach (['INSERT INTO contacts', 'UPDATE contacts', 'DELETE FROM', 'mail_snapshot', 'FROM contacts', 'INSERT INTO reminders', 'UPDATE reminders'] as $banned) {
    lir(!str_contains($src, $banned), 'källan innehåller inte ' . $banned);
}
lir(str_contains($src, 'contacts-link-inkorg-from-reminder'), 'ångerfilen ligger under contacts-link-inkorg-from-reminder');

$store = jeab_store();
lir($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();
$pnr = '198001132391';
$stamp = '2026-01-01 00:00:00';

function lir_cols(PDO $pdo, string $table): array
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

function lir_add(PDO $pdo, string $table, string $column): void
{
    if (lir_cols($pdo, $table) !== [] && !isset(lir_cols($pdo, $table)[$column])) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT \'\'');
    }
}

foreach (['reminders'] as $table) {
    lir_add($pdo, $table, 'company');
    lir_add($pdo, $table, 'contact_id');
    lir_add($pdo, $table, 'thread_id');
}
if (lir_cols($pdo, 'contacts') === []) {
    $pdo->exec("CREATE TABLE contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL DEFAULT '{}',
        company TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}
lir_add($pdo, 'contacts', 'row_json');
lir_add($pdo, 'contacts', 'company');
if (lir_cols($pdo, 'inkorg_threads') === []) {
    $pdo->exec("CREATE TABLE inkorg_threads (
        id TEXT PRIMARY KEY,
        status TEXT NOT NULL DEFAULT 'open',
        updated_at TEXT,
        row_json TEXT NOT NULL
    )");
}
lir_add($pdo, 'inkorg_threads', 'contact_id');
lir_add($pdo, 'inkorg_threads', 'row_json');
if (lir_cols($pdo, 'inkorg_list') === []) {
    $pdo->exec("CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT '',
        customer_email TEXT NOT NULL DEFAULT '',
        contact_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}
lir_add($pdo, 'inkorg_list', 'company');
lir_add($pdo, 'inkorg_list', 'customer_email');
lir_add($pdo, 'inkorg_list', 'contact_id');
lir_add($pdo, 'inkorg_list', 'row_json');

$contactSql = 'INSERT INTO contacts (id, row_json, company) VALUES (?, ?, ?)';
if (isset(lir_cols($pdo, 'contacts')['created_at'])) {
    $contactSql = 'INSERT INTO contacts (id, row_json, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?)';
}
$contact = $pdo->prepare($contactSql);
foreach ([
    ['kt_ada', 'jarfalla'],
    ['kt_bo', 'malmo'],
    ['kt_twin', 'jarfalla'],
] as $item) {
    $body = json_encode(['id' => $item[0], 'personnummer' => $pnr, 'note' => 'kontakt-behall'], JSON_UNESCAPED_UNICODE);
    if (isset(lir_cols($pdo, 'contacts')['created_at'])) {
        $contact->execute([$item[0], $body, $item[1], $stamp, $stamp]);
    } else {
        $contact->execute([$item[0], $body, $item[1]]);
    }
}

$listId = (int) $pdo->query('SELECT id FROM reminder_lists ORDER BY id LIMIT 1')->fetchColumn();
if ($listId <= 0) {
    $pdo->exec("INSERT INTO reminder_lists (name, created_at) VALUES ('Paminnelser', '$stamp')");
    $listId = (int) $pdo->lastInsertId();
}

$rem = $pdo->prepare('INSERT INTO reminders (list_id, title, customer_name, email, phone, address, contact_id, company, thread_id, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$addRem = static function (string $title, string $contact, string $company, string $thread) use ($rem, $listId, $stamp, $pnr): void {
    $rem->execute([$listId, $title, 'Ada Lov', 'ada@example.test', '0701111111', 'Storgatan 1', $contact, $company, $thread, $pnr, $stamp, $stamp]);
};
$addRem('ada', 'kt_ada', 'JEAB', 'ls-ada');
$addRem('same-a', 'kt_ada', 'jarfalla', 'ls-same');
$addRem('same-b', 'kt_ada', 'jarfalla', 'ls-same');
$addRem('one-empty', 'kt_ada', '', 'ls-onecmp');
$addRem('one-co', 'kt_ada', 'jarfalla', 'ls-onecmp');
$addRem('amb-a', 'kt_ada', 'jarfalla', 'ls-amb');
$addRem('amb-b', 'kt_twin', 'jarfalla', 'ls-amb');
$addRem('mixed-a', 'kt_ada', 'jarfalla', 'ls-mixed');
$addRem('mixed-b', 'kt_bo', 'malmo', 'ls-mixed');
$addRem('other', 'kt_bo', 'malmo', 'ls-other');
$addRem('noc', '', 'jarfalla', 'ls-noc');
$addRem('remco', 'kt_ada', '', 'ls-remco');
$addRem('nocompany', 'kt_ada', 'jarfalla', 'ls-nocompany');
$addRem('keep', 'kt_ada', 'jarfalla', 'ls-keep');
$addRem('meab', 'kt_bo', 'meab', 'ls-meab');
$addRem('th-bo', 'kt_bo', 'malmo', 'th-bo');
$addRem('th-case', 'kt_bo', 'malmo', 'th-case');
$addRem('th-brand', 'kt_bo', 'MEAB', 'th-brand');
$addRem('th-listco', 'kt_ada', 'JEAB', 'th-listco');
$addRem('nowhere', 'kt_ada', 'jarfalla', 'missing-row');

$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$row = static function (array $extra) use ($pnr): string {
    $payload = array_merge([
        'personnummer' => $pnr,
        'customer_name' => 'Ada Lov',
        'customer_email' => 'ada@example.test',
    ], $extra);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
};
$list->execute(['ls-ada', 'jarfalla', 'ada@example.test', '', $row([
    'company' => 'jarfalla',
    'note' => 'list-ada-behall',
    'lead_customer' => ['email' => 'lead.ada@example.test'],
])]);
$list->execute(['ls-same', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-same-behall'])]);
$list->execute(['ls-onecmp', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-one-behall'])]);
$list->execute(['ls-amb', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-amb-behall'])]);
$list->execute(['ls-mixed', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-mixed-behall'])]);
$list->execute(['ls-other', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-other-behall'])]);
$list->execute(['ls-noc', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-noc-behall'])]);
$list->execute(['ls-remco', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-remco-behall'])]);
$list->execute(['ls-nocompany', '', 'ada@example.test', '', $row(['note' => 'list-nocompany-behall'])]);
$list->execute(['ls-keep', 'jarfalla', 'ada@example.test', 'kt_ada', $row(['company' => 'jarfalla', 'contact_id' => 'kt_ada', 'note' => 'list-keep-behall'])]);
$list->execute(['ls-free', 'jarfalla', 'ada@example.test', '', $row(['company' => 'jarfalla', 'note' => 'list-free-behall'])]);
$list->execute(['ls-meab', 'MEAB', 'bo@example.test', '', $row(['note' => 'list-meab-behall'])]);
$list->execute(['th-listco', 'jarfalla', 'ada@example.test', 'kt_ada', $row(['company' => 'jarfalla', 'contact_id' => 'kt_ada', 'note' => 'list-listco-behall'])]);

$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)');
$thread->execute(['th-bo', 'open', $stamp, $row(['company' => 'malmo', 'note' => 'trad-bo-behall'])]);
$thread->execute(['th-case', 'open', $stamp, $row([
    'company' => 'malmo',
    'list_type' => 'Ny lead',
    'customer_email' => 'lead.bo@example.test',
    'phone' => '0702222222',
    'note' => 'trad-case-behall',
])]);
$thread->execute(['th-brand', 'open', $stamp, $row(['brand' => 'MEAB', 'note' => 'trad-brand-behall'])]);
$thread->execute(['th-listco', 'open', $stamp, json_encode(['note' => 'trad-listco-behall', 'personnummer' => $pnr], JSON_UNESCAPED_UNICODE)]);

if (lir_cols($pdo, 'quotes') === []) {
    $pdo->exec('CREATE TABLE quotes (id TEXT PRIMARY KEY, body TEXT)');
}
if (lir_cols($pdo, 'invoices') === []) {
    $pdo->exec('CREATE TABLE invoices (id TEXT PRIMARY KEY, body TEXT)');
}
if (lir_cols($pdo, 'mail_snapshots') === []) {
    $pdo->exec('CREATE TABLE mail_snapshots (id TEXT PRIMARY KEY, body TEXT)');
}
$pdo->exec("INSERT INTO quotes (id, body) VALUES ('q-lir', 'offert-behall')");
$pdo->exec("INSERT INTO invoices (id, body) VALUES ('i-lir', 'faktura-behall')");
$pdo->exec("INSERT INTO mail_snapshots (id, body) VALUES ('m-lir', 'skickat-behall')");

function lir_cid(PDO $pdo, string $table, string $id): string
{
    $stmt = $pdo->prepare('SELECT contact_id FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);
    $value = $stmt->fetchColumn();

    return trim((string) $value);
}

function lir_json(PDO $pdo, string $table, string $id): array
{
    $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
    $stmt->execute([$id]);
    $decoded = json_decode((string) $stmt->fetchColumn(), true);

    return is_array($decoded) ? $decoded : [];
}

function lir_pairs(array $block): array
{
    $out = [];
    foreach (is_array($block['would_link']['ids'] ?? null) ? $block['would_link']['ids'] : [] as $link) {
        if (!is_array($link)) {
            continue;
        }
        $out[] = (string) ($link['table'] ?? '') . ':' . (string) ($link['id'] ?? '') . '=' . (string) ($link['contact_id'] ?? '');
    }
    sort($out);

    return $out;
}

function lir_ids(array $block, string $reason): array
{
    $ids = $block['remaining'][$reason]['ids'] ?? [];

    return is_array($ids) ? array_map('strval', $ids) : [];
}

function lir_secrets(): array
{
    return ['198001132391', 'ada@example.test', 'lead.ada@example.test', 'lead.bo@example.test', 'Ada Lov', '070', 'Storgatan', 'list-ada-behall', 'trad-bo-behall', 'offert-behall', 'snapshot-behall', 'kontakt-behall'];
}

function lir_clean(string $json): bool
{
    foreach (lir_secrets() as $secret) {
        if (str_contains($json, $secret)) {
            return false;
        }
    }

    return true;
}

function lir_guard(PDO $pdo, string $tmp, string $contactsBefore, int $contactCount, string $remindersBefore): array
{
    $contactsNow = (string) $pdo->query('SELECT GROUP_CONCAT(id || row_json, "|") FROM contacts')->fetchColumn();
    $countNow = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
    $remindersNow = (string) $pdo->query('SELECT GROUP_CONCAT(thread_id || ":" || COALESCE(contact_id, ""), "|") FROM reminders')->fetchColumn();

    return [
        $contactsNow === $contactsBefore,
        $countNow === $contactCount,
        $remindersNow === $remindersBefore,
        (string) $pdo->query("SELECT body FROM quotes WHERE id = 'q-lir'")->fetchColumn() === 'offert-behall',
        (string) $pdo->query("SELECT body FROM invoices WHERE id = 'i-lir'")->fetchColumn() === 'faktura-behall',
        (string) $pdo->query("SELECT body FROM mail_snapshots WHERE id = 'm-lir'")->fetchColumn() === 'skickat-behall',
        (string) file_get_contents($tmp . '/snap-marker.txt') === "snapshot-behall\n",
    ];
}

$contactsBefore = (string) $pdo->query('SELECT GROUP_CONCAT(id || row_json, "|") FROM contacts')->fetchColumn();
$contactCount = (int) $pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn();
$remindersBefore = (string) $pdo->query('SELECT GROUP_CONCAT(thread_id || ":" || COALESCE(contact_id, ""), "|") FROM reminders')->fetchColumn();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder', 'dry' => 0]);
lir(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
lir(!in_array(false, lir_guard($pdo, $tmp, $contactsBefore, $contactCount, $remindersBefore), true), 'nekad körning skriver inget');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-reminder';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder']);
lir(!empty($dry['ok']) && ($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false && ($dry['undo_id'] ?? 'x') === '', 'utelämnad dry skriver inte');
lir(($dry['sent'] ?? 1) === 0 && ($dry['contacts_created'] ?? 1) === 0 && ($dry['contacts_merged'] ?? 1) === 0, 'dry skapar inga kontakter och skickar inget');
lir(!is_dir($tmp . '/contacts-link-inkorg-from-reminder'), 'torrkörningen skriver ingen ångerfil');
lir(lir_cid($pdo, 'inkorg_list', 'ls-ada') === '', 'torrkörningen lämnar contact_id tom');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
lir(is_string($dryJson) && lir_clean($dryJson), 'dry-svaret saknar personuppgifter');

$jarList = $dry['companies']['jarfalla']['inkorg_list'] ?? [];
$jarCases = $dry['companies']['jarfalla']['inkorg_cases'] ?? [];
$jarThreads = $dry['companies']['jarfalla']['inkorg_threads'] ?? [];
$malList = $dry['companies']['malmo']['inkorg_list'] ?? [];
$malThreads = $dry['companies']['malmo']['inkorg_threads'] ?? [];
$malCases = $dry['companies']['malmo']['inkorg_cases'] ?? [];
$noneList = $dry['companies']['none']['inkorg_list'] ?? [];
lir(lir_pairs($jarList) === ['inkorg_list:ls-ada=kt_ada', 'inkorg_list:ls-onecmp=kt_ada', 'inkorg_list:ls-same=kt_ada'], 'listan i Järfälla föreslår tre länkar');
lir(lir_pairs($jarCases) === ['inkorg_list:ls-ada=kt_ada'], 'ärendet redovisas under inkorg_cases');
lir(lir_pairs($jarThreads) === ['inkorg_threads:th-listco=kt_ada'], 'tråd utan bolag tar listradens bolag');
lir(lir_pairs($malList) === ['inkorg_list:ls-meab=kt_bo'], 'MEAB räknas som Malmö');
lir(lir_pairs($malThreads) === ['inkorg_threads:th-bo=kt_bo', 'inkorg_threads:th-brand=kt_bo', 'inkorg_threads:th-case=kt_bo'], 'Malmötrådarna föreslås');
lir(lir_pairs($malCases) === ['inkorg_threads:th-case=kt_bo'], 'Ny lead redovisas som ärende');
lir(lir_ids($jarList, 'ambiguous_contact') === ['ls-amb', 'ls-mixed'], 'olika kontakter listas och länkas inte');
lir(lir_ids($jarList, 'company_mismatch') === ['ls-other'], 'annat bolag listas');
lir(lir_ids($jarList, 'no_contact') === ['ls-noc'], 'påminnelse utan kontakt listas');
lir(lir_ids($jarList, 'no_company') === ['ls-remco'], 'påminnelse utan bolag listas');
lir(lir_ids($noneList, 'no_company') === ['ls-nocompany'], 'rad utan bolag listas under none');
lir(!str_contains((string) $dryJson, 'ls-keep') && !str_contains((string) $dryJson, 'ls-free') && !str_contains((string) $dryJson, 'missing-row'), 'ifylld länk, rad utan påminnelse och okänd tråd utelämnas');
$jarCounts = $dry['counts']['jarfalla']['inkorg_list'] ?? [];
lir(($jarCounts['link'] ?? -1) === 3 && ($jarCounts['ambiguous_contact'] ?? -1) === 2 && ($jarCounts['company_mismatch'] ?? -1) === 1 && ($jarCounts['no_contact'] ?? -1) === 1 && ($jarCounts['no_company'] ?? -1) === 1, 'antal per orsak stämmer för listan');
lir(($dry['counts']['jarfalla']['inkorg_cases']['link'] ?? -1) === 1 && ($dry['counts']['malmo']['inkorg_cases']['link'] ?? -1) === 1, 'ärenden räknas per bolag');
lir(($jarList['would_link']['count'] ?? -1) === 3 && ($jarList['remaining']['ambiguous_contact']['count'] ?? -1) === 2, 'antalen ligger också på tabellen');

$port = 18180 + random_int(0, 800);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($tmp, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($public, true) . ";\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$log = $tmp . '/php-server.log';
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root), escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
lir(is_resource($proc), 'php -S startar');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
lir($up, 'php -S lyssnar');
$curl = static function (string $body, string $token) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Webhook-Token: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'raw' => is_string($raw) ? $raw : '', 'json' => json_decode(is_string($raw) ? $raw : '', true)];
};
$curlDenied = $curl('{"action":"contacts.link_inkorg_from_reminder","dry":0}', '');
lir($curlDenied['status'] === 401 && is_array($curlDenied['json']) && empty($curlDenied['json']['ok']), 'curl utan token blir 401');
$curlDry = $curl('{"action":"contacts.link_inkorg_from_reminder","dry":1}', 'tok-link-reminder');
lir($curlDry['status'] === 200 && is_array($curlDry['json']) && ($curlDry['json']['action'] ?? '') === 'contacts.link_inkorg_from_reminder' && ($curlDry['json']['dry'] ?? false) === true && ($curlDry['json']['written'] ?? true) === false, 'curl med token får torr körning');
lir(lir_clean($curlDry['raw']) && ($curlDry['json']['counts']['jarfalla']['inkorg_list']['link'] ?? -1) === 3, 'curl-svaret har antal och saknar personuppgifter');
lir(lir_cid($pdo, 'inkorg_list', 'ls-ada') === '', 'curl skriver ingen contact_id');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

$wet = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder', 'dry' => 0]);
lir(!empty($wet['ok']) && ($wet['dry'] ?? true) === false && !empty($wet['written']) && ($wet['sent'] ?? 1) === 0, 'dry=0 skriver utan att skicka');
$undoId = (string) ($wet['undo_id'] ?? '');
lir(preg_match('/^[a-f0-9]{16}$/', $undoId) === 1, 'undo-id är satt');
$undoPath = $tmp . '/contacts-link-inkorg-from-reminder/' . $undoId . '.json';
lir(is_file($undoPath), 'ångerfilen ligger i contacts-link-inkorg-from-reminder');
lir(lir_clean((string) file_get_contents($undoPath)), 'ångerfilen saknar personuppgifter');
lir(lir_cid($pdo, 'inkorg_list', 'ls-ada') === 'kt_ada', 'listan fick contact_id');
lir((lir_json($pdo, 'inkorg_list', 'ls-ada')['contact_id'] ?? '') === 'kt_ada', 'listans json fick contact_id');
lir((lir_json($pdo, 'inkorg_list', 'ls-ada')['note'] ?? '') === 'list-ada-behall' && (lir_json($pdo, 'inkorg_list', 'ls-ada')['personnummer'] ?? '') === $pnr, 'listans övriga fält är kvar');
lir(lir_cid($pdo, 'inkorg_list', 'ls-same') === 'kt_ada' && lir_cid($pdo, 'inkorg_list', 'ls-onecmp') === 'kt_ada' && lir_cid($pdo, 'inkorg_list', 'ls-meab') === 'kt_bo', 'övriga träffar skrevs');
lir(lir_cid($pdo, 'inkorg_list', 'ls-amb') === '' && lir_cid($pdo, 'inkorg_list', 'ls-mixed') === '' && lir_cid($pdo, 'inkorg_list', 'ls-other') === '' && lir_cid($pdo, 'inkorg_list', 'ls-noc') === '' && lir_cid($pdo, 'inkorg_list', 'ls-remco') === '' && lir_cid($pdo, 'inkorg_list', 'ls-nocompany') === '', 'osäkra rader skrevs inte');
lir(lir_cid($pdo, 'inkorg_list', 'ls-keep') === 'kt_ada' && lir_cid($pdo, 'inkorg_list', 'ls-free') === '', 'ifylld länk och olänkad rad utan påminnelse rördes inte');
lir((lir_json($pdo, 'inkorg_threads', 'th-bo')['contact_id'] ?? '') === 'kt_bo' && lir_cid($pdo, 'inkorg_threads', 'th-bo') === 'kt_bo', 'tråden fick contact_id i json och kolumn');
lir((lir_json($pdo, 'inkorg_threads', 'th-case')['contact_id'] ?? '') === 'kt_bo' && (lir_json($pdo, 'inkorg_threads', 'th-brand')['contact_id'] ?? '') === 'kt_bo', 'ärende och brand skrevs');
lir((lir_json($pdo, 'inkorg_threads', 'th-listco')['contact_id'] ?? '') === 'kt_ada' && (lir_json($pdo, 'inkorg_threads', 'th-listco')['note'] ?? '') === 'trad-listco-behall', 'tråden ärvde bolag och behöll övriga fält');
lir((lir_json($pdo, 'inkorg_threads', 'th-bo')['note'] ?? '') === 'trad-bo-behall', 'trådens övriga fält är kvar');
lir(!in_array(false, lir_guard($pdo, $tmp, $contactsBefore, $contactCount, $remindersBefore), true), 'kontakter, påminnelser, offerter och mejlkopior är orörda');

$again = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder']);
lir(($again['companies']['jarfalla']['inkorg_list']['would_link']['count'] ?? -1) === 0 && ($again['companies']['malmo']['inkorg_threads']['would_link']['count'] ?? -1) === 0, 'en ny torrkörning föreslår inte redan länkade rader');

$undoDry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder_undo', 'undo_id' => $undoId]);
lir(!empty($undoDry['ok']) && ($undoDry['dry'] ?? false) === true && empty($undoDry['written']), 'undo utan dry skriver inte');
lir(lir_cid($pdo, 'inkorg_list', 'ls-ada') === 'kt_ada', 'torr undo lämnar länken');
$undoPairs = lir_pairs(['would_link' => $undoDry['companies']['jarfalla']['inkorg_cases']['would_unlink'] ?? []]);
lir($undoPairs === ['inkorg_list:ls-ada=kt_ada'], 'torr undo visar ärendet');
lir(lir_clean((string) json_encode($undoDry, JSON_UNESCAPED_UNICODE)), 'undo-svaret saknar personuppgifter');

$changed = lir_json($pdo, 'inkorg_list', 'ls-ada');
$changed['note'] = 'andrat-senare';
$pdo->prepare('UPDATE inkorg_list SET row_json = ? WHERE id = ?')->execute([
    json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'ls-ada',
]);

$undoWet = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder_undo', 'undo_id' => $undoId, 'dry' => 0]);
lir(!empty($undoWet['ok']) && !empty($undoWet['written']) && ($undoWet['sent'] ?? 1) === 0 && ($undoWet['contacts_created'] ?? 1) === 0, 'undo dry=0 skriver utan att skapa kontakter');
lir(lir_cid($pdo, 'inkorg_list', 'ls-ada') === 'kt_ada', 'ändrad rad behåller länken');
lir((lir_json($pdo, 'inkorg_list', 'ls-ada')['note'] ?? '') === 'andrat-senare' && (lir_json($pdo, 'inkorg_list', 'ls-ada')['personnummer'] ?? '') === $pnr, 'ändrad text och personnummer lämnas');
lir(in_array('ls-ada', $undoWet['companies']['jarfalla']['inkorg_list']['skipped']['ids'] ?? [], true), 'ändrad rad räknas som skipped');
lir(in_array('ls-ada', $undoWet['companies']['jarfalla']['inkorg_cases']['skipped']['ids'] ?? [], true), 'ändrat ärende räknas som skipped');
lir(lir_cid($pdo, 'inkorg_list', 'ls-same') === '' && lir_cid($pdo, 'inkorg_list', 'ls-meab') === '' && lir_cid($pdo, 'inkorg_list', 'ls-onecmp') === '', 'oförändrade listlänkar tas bort');
lir((lir_json($pdo, 'inkorg_list', 'ls-same')['note'] ?? '') === 'list-same-behall' && !array_key_exists('contact_id', lir_json($pdo, 'inkorg_list', 'ls-same')), 'listans json tappar bara contact_id');
lir(!array_key_exists('contact_id', lir_json($pdo, 'inkorg_threads', 'th-bo')) && lir_cid($pdo, 'inkorg_threads', 'th-bo') === '' && (lir_json($pdo, 'inkorg_threads', 'th-bo')['note'] ?? '') === 'trad-bo-behall', 'tråden återställs utan tom nyckel');
lir(!array_key_exists('contact_id', lir_json($pdo, 'inkorg_threads', 'th-case')) && (lir_json($pdo, 'inkorg_threads', 'th-case')['note'] ?? '') === 'trad-case-behall', 'ärendets övriga fält är kvar efter undo');
lir(!array_key_exists('contact_id', lir_json($pdo, 'inkorg_threads', 'th-listco')) && (lir_json($pdo, 'inkorg_threads', 'th-listco')['note'] ?? '') === 'trad-listco-behall', 'ärvd tråd återställs');
lir(lir_cid($pdo, 'inkorg_list', 'ls-amb') === '' && lir_cid($pdo, 'inkorg_list', 'ls-keep') === 'kt_ada', 'osäkra rader och den gamla länken är kvar');
lir(!in_array(false, lir_guard($pdo, $tmp, $contactsBefore, $contactCount, $remindersBefore), true), 'undo rör inte kontakter, påminnelser eller skickat material');

$missing = jeab_contacts_sole_dispatch(['action' => 'contacts.link_inkorg_from_reminder_undo', 'undo_id' => 'inte-ett-id', 'dry' => 0]);
lir(empty($missing['ok']) && ($missing['written'] ?? true) === false && ($missing['error'] ?? '') === 'Ångerfilen saknas.', 'okänt undo-id skriver inte');

echo "OK  contacts.link_inkorg_from_reminder\n";
