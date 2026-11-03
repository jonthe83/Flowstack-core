<?php

declare(strict_types=1);

/**
 * contacts.link_by_email ska se samma e-post som dup_existing_email
 * och samma olänkade rader som contacts.sole_audit.
 * Påhittade adresser.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-align-' . bin2hex(random_bytes(4));
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-align']));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contact_create.php';
require_once dirname(__DIR__) . '/src/contacts_sole_audit.php';

function lba(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
lba($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

function lba_cols(PDO $pdo, string $table): array
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

function lba_add(PDO $pdo, string $table, string $column): void
{
    if (lba_cols($pdo, $table) !== [] && !isset(lba_cols($pdo, $table)[$column])) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TEXT NOT NULL DEFAULT \'\'');
    }
}

if (!isset(lba_cols($pdo, 'contacts')['id'])) {
    $pdo->exec("CREATE TABLE contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL DEFAULT '{}',
        owner_email TEXT NOT NULL DEFAULT '',
        company TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}
lba_add($pdo, 'contacts', 'row_json');
lba_add($pdo, 'contacts', 'owner_email');
lba_add($pdo, 'contacts', 'company');
if (lba_cols($pdo, 'contact_emails') === []) {
    $pdo->exec('CREATE TABLE contact_emails (email TEXT NOT NULL, contact_id TEXT NOT NULL, PRIMARY KEY (email, contact_id))');
}
if (lba_cols($pdo, 'inkorg_threads') === []) {
    $pdo->exec("CREATE TABLE inkorg_threads (
        id TEXT PRIMARY KEY,
        status TEXT NOT NULL DEFAULT 'open',
        updated_at TEXT,
        row_json TEXT NOT NULL
    )");
}
lba_add($pdo, 'inkorg_threads', 'contact_id');
if (lba_cols($pdo, 'inkorg_list') === []) {
    $pdo->exec("CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT '',
        customer_email TEXT NOT NULL DEFAULT '',
        contact_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}
lba_add($pdo, 'inkorg_list', 'company');
lba_add($pdo, 'inkorg_list', 'customer_email');
lba_add($pdo, 'inkorg_list', 'contact_id');
lba_add($pdo, 'inkorg_list', 'row_json');

$stamp = '2026-03-01 00:00:00';
$json = static function (array $row): string {
    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '{}';
};
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, owner_email, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
$ins->execute(['kt_owner', $json([
    'id' => 'kt_owner',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Agare', 'email' => 'Owner@Example.TEST', 'contact' => true]],
]), '', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_box', $json([
    'id' => 'kt_box',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Box', 'contact' => true]],
]), '', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_twin1', $json([
    'id' => 'kt_twin1',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Ett', 'email' => 'twin@example.test']],
]), '', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_twin2', $json([
    'id' => 'kt_twin2',
    'company' => 'jarfalla',
    'emails' => ['twin@example.test'],
]), '', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_ada', $json([
    'id' => 'kt_ada',
    'company' => 'jarfalla',
    'email' => 'ada@example.test',
]), 'ada@example.test', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_malmo', $json([
    'id' => 'kt_malmo',
    'company' => 'malmo',
    'owners' => [['email' => 'only-malmo@example.test']],
]), '', 'malmo', $stamp, $stamp]);
$pdo->exec("INSERT INTO contact_emails (email, contact_id) VALUES ('box@example.test', 'kt_box')");

$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$list->execute(['list-owner', 'jarfalla', 'owner@example.test', '', $json(['customer_email' => 'owner@example.test', 'customer_name' => 'Agare'])]);
$list->execute(['list-box', 'jarfalla', 'Box@Example.TEST', '', $json(['customer_email' => 'Box@Example.TEST'])]);
$list->execute(['list-twin', 'jarfalla', 'twin@example.test', '', $json(['customer_email' => 'twin@example.test'])]);
$list->execute(['list-other', 'jarfalla', 'only-malmo@example.test', '', $json(['customer_email' => 'only-malmo@example.test'])]);
$list->execute(['box+1/a', 'jarfalla', 'plus@example.test', '', $json(['customer_email' => 'plus@example.test'])]);
$list->execute(['list-keep', 'jarfalla', 'ada@example.test', 'kt_ada', $json(['customer_email' => 'ada@example.test', 'contact_id' => 'kt_ada'])]);
$list->execute(['th-via-list', 'jarfalla', '', 'kt_ada', $json(['company' => 'jarfalla', 'contact_id' => 'kt_ada'])]);

$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json, contact_id) VALUES (?, ?, ?, ?, ?)');
$thread->execute(['th-via-list', 'open', $stamp, $json([
    'customer_email' => 'ada@example.test',
    'customer_name' => 'Trad',
]), '']);
$thread->execute(['th-nocompany', 'open', $stamp, $json([
    'customer_email' => 'ada@example.test',
    'customer_name' => 'Ensam',
]), '']);

$plan = [
    'list-owner' => ['email' => 'Owner@Example.TEST', 'phone' => '', 'address' => ''],
    'list-box' => ['email' => 'box@example.test', 'phone' => '', 'address' => ''],
    'list-twin' => ['email' => 'twin@example.test', 'phone' => '', 'address' => ''],
    'list-other' => ['email' => 'only-malmo@example.test', 'phone' => '', 'address' => ''],
];
$companyOf = [
    'list-owner' => 'jarfalla',
    'list-box' => 'jarfalla',
    'list-twin' => 'jarfalla',
    'list-other' => 'jarfalla',
];
$classified = jeab_inkorg_contact_create_classify($pdo, $plan, $companyOf);
$dup = [];
foreach ($classified['uncertain'] as $row) {
    if (($row['reason'] ?? '') === 'dup_existing_email') {
        $dup[(string) $row['id']] = true;
    }
}
lba(isset($dup['list-owner'], $dup['list-box'], $dup['list-twin'], $dup['list-other']), 'dup-kollen ser ägare, contact_emails och annat bolag');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-align';
$dry = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email']);
lba(!empty($dry['ok']) && ($dry['dry'] ?? false) === true, 'torrkörning');

function lba_link(array $block, string $id): string
{
    foreach ($block['would_link']['ids'] ?? [] as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
            return (string) ($row['contact_id'] ?? '');
        }
    }

    return '';
}

function lba_ids(array $block, string $reason): array
{
    $ids = $block['remaining'][$reason]['ids'] ?? [];

    return is_array($ids) ? array_map('strval', $ids) : [];
}

function lba_scanned(array $block): int
{
    $n = (int) ($block['would_link']['count'] ?? 0);
    foreach (['ambiguous_email', 'no_email', 'no_match', 'no_company'] as $reason) {
        $n += (int) ($block['remaining'][$reason]['count'] ?? 0);
    }

    return $n;
}

$list = $dry['companies']['jarfalla']['inkorg_list'] ?? [];
$threads = $dry['companies']['jarfalla']['inkorg_threads'] ?? [];
$noneThreads = $dry['companies']['none']['inkorg_threads'] ?? [];
lba(lba_link($list, 'list-owner') === 'kt_owner', 'ägarens e-post i JSON länkas');
lba(lba_link($list, 'list-box') === 'kt_box', 'contact_emails länkas');
lba(lba_link($list, 'list-twin') === '', 'två JSON-e-post länkas inte');
lba(in_array('list-twin', lba_ids($list, 'ambiguous_email'), true), 'två JSON-e-post är ambiguous_email');
lba(lba_link($list, 'list-other') === '', 'annat bolag länkas inte');
lba(in_array('list-other', lba_ids($list, 'no_match'), true), 'annat bolag är no_match trots dup');
lba(in_array('box+1/a', lba_ids($list, 'no_match'), true), 'id med plus och snedstreck är med');
lba(lba_link($list, 'list-keep') === '', 'ifylld länk är utanför');
lba(lba_link($threads, 'th-via-list') === 'kt_ada', 'tråd utan bolag tar bolag från listraden');
lba(lba_link($threads, 'th-nocompany') === '', 'tråd utan bolag länkas inte');
lba(in_array('th-nocompany', lba_ids($noneThreads, 'no_company'), true), 'tråd utan bolag är no_company');

$spec = jeab_contacts_sole_audit_specs()['inkorg_list'];
$audited = jeab_contacts_sole_audit_store($pdo, $spec, jeab_contacts_sole_audit_columns($pdo, 'contacts'));
$empty = (int) ($audited['jarfalla']['empty_contact_id'] ?? 0);
lba($empty === lba_scanned($list), 'listan skannar samma olänkade Järfällarader som auditen (' . $empty . ')');
lba($empty === 5, 'auditen räknar de fem olänkade listaderna');

$encoded = json_encode($dry, JSON_UNESCAPED_UNICODE);
lba(is_string($encoded) && !str_contains($encoded, 'example.test') && !str_contains($encoded, '@'), 'svaret saknar e-post');

echo "OK  contacts.link_by_email align\n";
