<?php

declare(strict_types=1);

/**
 * contacts.link_diag. Id och orsakskoder. Auditen och länken läser
 * listradens bolag när tråden saknar bolag, och contact_id skrivs där
 * sidan läser den. Påhittade adresser.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-diag-' . bin2hex(random_bytes(4));
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
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-link-diag']));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/contacts_link_diag.php';
require_once dirname(__DIR__) . '/src/contacts_sole_audit.php';

function diag_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$dispatchSrc = (string) file_get_contents(dirname(__DIR__) . '/src/contacts_sole.php');
diag_ok(str_contains($hookSrc, "'contacts.link_diag'"), 'hook.php tar contacts.link_diag');
diag_ok(str_contains($dispatchSrc, "=== 'contacts.link_diag'"), 'dispatch skickar diagnosen');

$store = jeab_store();
diag_ok($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

function diag_cols(PDO $pdo, string $table): array
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

if (diag_cols($pdo, 'contacts') === []) {
    $pdo->exec("CREATE TABLE contacts (
        id TEXT PRIMARY KEY,
        row_json TEXT NOT NULL DEFAULT '{}',
        owner_email TEXT NOT NULL DEFAULT '',
        company TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT '',
        updated_at TEXT NOT NULL DEFAULT ''
    )");
}
if (diag_cols($pdo, 'inkorg_threads') === []) {
    $pdo->exec("CREATE TABLE inkorg_threads (
        id TEXT PRIMARY KEY,
        status TEXT NOT NULL DEFAULT 'open',
        updated_at TEXT,
        row_json TEXT NOT NULL
    )");
}
diag_ok(!isset(diag_cols($pdo, 'inkorg_threads')['contact_id']), 'trådtabellen har ingen contact_id-kolumn');
if (diag_cols($pdo, 'inkorg_list') === []) {
    $pdo->exec("CREATE TABLE inkorg_list (
        id TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT '',
        customer_email TEXT NOT NULL DEFAULT '',
        contact_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}
if (diag_cols($pdo, 'inkorg_messages') === []) {
    $pdo->exec("CREATE TABLE inkorg_messages (
        id TEXT PRIMARY KEY,
        thread_id TEXT NOT NULL DEFAULT '',
        row_json TEXT NOT NULL
    )");
}

$stamp = '2026-03-01 00:00:00';
$json = static function (array $row): string {
    $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($encoded) ? $encoded : '{}';
};
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, owner_email, company, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
$ins->execute(['kt_ui', $json([
    'id' => 'kt_ui',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Ui', 'email' => 'ui@example.test']],
]), 'ui@example.test', 'jarfalla', $stamp, $stamp]);
$ins->execute(['kt_empty', $json([
    'id' => 'kt_empty',
    'company' => '',
]), 'empty@example.test', '', $stamp, $stamp]);
$ins->execute(['kt_malmo', $json([
    'id' => 'kt_malmo',
    'company' => 'malmo',
    'owners' => [['email' => 'other@example.test']],
]), '', 'malmo', $stamp, $stamp]);

$list = $pdo->prepare('INSERT INTO inkorg_list (id, company, customer_email, contact_id, row_json) VALUES (?, ?, ?, ?, ?)');
$list->execute(['case-ui', 'jarfalla', '', '', $json(['company' => 'jarfalla'])]);
$list->execute(['lead-empty', 'jarfalla', 'empty@example.test', '', $json([
    'customer_name' => 'Tom Bolag',
    'labels' => ['type' => 'Ny lead'],
    'lead_customer' => ['email' => 'empty@example.test', 'name' => 'Tom Bolag'],
])]);
$list->execute(['lead-other', 'jarfalla', 'other@example.test', '', $json([
    'customer_name' => 'Annat Bolag',
    'labels' => ['type' => 'Ny lead'],
    'lead_customer' => ['email' => 'other@example.test', 'name' => 'Annat Bolag'],
])]);

$thread = $pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)');
$thread->execute(['case-ui', 'open', $stamp, $json([
    'customer_email' => 'ui@example.test',
    'customer_name' => 'Ui Trad',
])]);
$thread->execute(['th-box', 'open', $stamp, $json([
    'customer_name' => 'Bara Box',
])]);
$pdo->prepare('INSERT INTO inkorg_messages (id, thread_id, row_json) VALUES (?, ?, ?)')->execute([
    'msg-box',
    'th-box',
    $json(['source' => 'graph-jarfalla', 'thread_id' => 'th-box']),
]);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-link-diag';

$before = [
    'list' => (string) $pdo->query("SELECT contact_id FROM inkorg_list WHERE id = 'case-ui'")->fetchColumn(),
    'thread' => (string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'case-ui'")->fetchColumn(),
];
$report = jeab_contacts_sole_dispatch(['action' => 'contacts.link_diag']);
diag_ok(!empty($report['ok']) && ($report['dry'] ?? false) === true && ($report['written'] ?? true) === false, 'diagnosen är torr');
diag_ok(
    (string) $pdo->query("SELECT contact_id FROM inkorg_list WHERE id = 'case-ui'")->fetchColumn() === $before['list']
    && (string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'case-ui'")->fetchColumn() === $before['thread'],
    'diagnosen skriver inget'
);

$spec = jeab_contacts_sole_audit_specs()['inkorg_threads'];
$audited = jeab_contacts_sole_audit_store($pdo, $spec, jeab_contacts_sole_audit_columns($pdo, 'contacts'));
diag_ok((int) ($audited['jarfalla']['empty_contact_id'] ?? 0) === 1, 'auditen räknar tråden vars bolag bara ligger på listan');
diag_ok((int) ($audited['jarfalla']['total'] ?? 0) === 1, 'tråden utan lagrat bolag och utan lista räknas inte som Järfälla');

function diag_row(array $rows, string $table, string $id): array
{
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['table'] ?? '') === $table && (string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return [];
}

$rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
$uiThread = diag_row($rows, 'inkorg_threads', 'case-ui');
diag_ok($uiThread !== [], 'tråden med listbolag finns i diagnosen');
diag_ok((string) ($uiThread['audit_company_storage'] ?? '') === 'sql:inkorg_list.company', 'auditen läser bolaget från listans kolumn');
diag_ok((string) ($uiThread['linker_company_storage'] ?? '') === 'sql:inkorg_list.company', 'länken läser samma listkolumn');
diag_ok((string) ($uiThread['ui_storage'] ?? '') === 'sql:inkorg_threads.row_json.contact_id', 'sidan läser trådens row_json');
diag_ok((string) ($uiThread['audit_storage'] ?? '') === 'sql:inkorg_threads.row_json.contact_id', 'auditen läser samma contact_id');
diag_ok((int) ($uiThread['has_email'] ?? 0) === 1 && (int) ($uiThread['in_directory'] ?? 0) === 1, 'tråden har e-post som finns i katalogen');
diag_ok((int) ($uiThread['contact_count'] ?? 0) === 1 && ($uiThread['companies'] ?? []) === ['jarfalla'], 'en Järfällakontakt');
diag_ok((string) ($uiThread['mismatch'] ?? '') === 'would_link' && (int) ($uiThread['company_from_list'] ?? 0) === 1, 'träff, bolag från listan');
diag_ok((string) ($uiThread['email_hash'] ?? '') === substr(hash('sha256', 'ui@example.test'), 0, 12), 'e-postnyckeln är kort sha256');

$uiList = diag_row($rows, 'inkorg_list', 'case-ui');
diag_ok((string) ($uiList['audit_storage'] ?? '') === 'sql:inkorg_list.contact_id', 'listan auditeras på contact_id-kolumnen');
diag_ok((string) ($uiList['ui_storage'] ?? '') === 'sql:inkorg_list.contact_id', 'listans sida läser samma kolumn');
diag_ok((string) ($uiList['mismatch'] ?? '') === 'no_email', 'listraden utan e-post är no_email');

$empty = diag_row($rows, 'inkorg_list', 'lead-empty');
diag_ok((string) ($empty['mismatch'] ?? '') === 'directory_empty_company' && (int) ($empty['contact_count'] ?? 0) === 1, 'e-post på kontakt utan bolag är directory_empty_company');
diag_ok(($empty['companies'] ?? []) === ['none'], 'kontakten saknar bolag');

$other = diag_row($rows, 'inkorg_list', 'lead-other');
diag_ok((string) ($other['mismatch'] ?? '') === 'directory_other_company' && ($other['companies'] ?? []) === ['malmo'], 'e-post i andra bolaget är directory_other_company');

$box = diag_row($rows, 'inkorg_threads', 'th-box');
diag_ok((string) ($box['mismatch'] ?? '') === 'audit_omitted', 'mejlboxens bolag syns hos länken och utelämnas av auditen');
diag_ok((string) ($box['linker_company_storage'] ?? '') === 'sql:inkorg_messages.row_json.company', 'länken läste mejlboxen');

$counts = is_array($report['counts'] ?? null) ? $report['counts'] : [];
diag_ok((int) ($counts['would_link'] ?? 0) === 1, 'en would_link');
diag_ok((int) ($counts['company_from_list'] ?? 0) === 1, 'ett bolag hämtat från listan');
diag_ok((int) ($counts['directory_empty_company'] ?? 0) === 1, 'en tom bolagskatalog');
diag_ok((int) ($counts['directory_other_company'] ?? 0) === 1, 'ett annat bolag');
diag_ok((int) ($counts['audit_omitted'] ?? 0) === 1, 'en rad auditen utelämnar');
diag_ok((int) ($counts['no_email'] ?? 0) === 1, 'en rad utan e-post');

function diag_dup(array $dups, string $id): array
{
    foreach ($dups as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') === $id) {
            return $row;
        }
    }

    return [];
}

$dups = is_array($report['dups'] ?? null) ? $report['dups'] : [];
$dupEmpty = diag_dup($dups, 'lead-empty');
diag_ok((string) ($dupEmpty['contact_id'] ?? '') === 'kt_empty', 'dup träffar kontakten utan bolag');
diag_ok((string) ($dupEmpty['company'] ?? '') === 'none' && (string) ($dupEmpty['source'] ?? '') === 'owner_email', 'källfältet är owner_email');
diag_ok((string) ($dupEmpty['linker'] ?? '') === 'no_match' && (string) ($dupEmpty['mismatch'] ?? '') === 'dup_linker_empty_company', 'länken säger no_match');
$dupOther = diag_dup($dups, 'lead-other');
diag_ok((string) ($dupOther['contact_id'] ?? '') === 'kt_malmo' && (string) ($dupOther['company'] ?? '') === 'malmo', 'dup träffar Malmökontakten');
diag_ok((string) ($dupOther['source'] ?? '') === 'owners.email', 'källfältet är owners.email');
diag_ok((string) ($dupOther['linker'] ?? '') === 'no_match' && (string) ($dupOther['mismatch'] ?? '') === 'dup_linker_other_company', 'annat bolag är no_match hos länken');
$dupCounts = is_array($report['dup_counts'] ?? null) ? $report['dup_counts'] : [];
diag_ok((int) ($dupCounts['dup_linker_empty_company'] ?? 0) === 1 && (int) ($dupCounts['dup_linker_other_company'] ?? 0) === 1, 'dup-räknarna är per orsak');

$encoded = json_encode($report, JSON_UNESCAPED_UNICODE);
diag_ok(is_string($encoded) && !str_contains($encoded, '@') && !str_contains($encoded, 'example.test') && !str_contains($encoded, 'Tom Bolag') && !str_contains($encoded, 'Ui Trad'), 'svaret saknar e-post och namn');

$wet = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email', 'dry' => 0]);
diag_ok(!empty($wet['ok']) && ($wet['written'] ?? false) === true, 'länken skriver');
$listContact = (string) $pdo->query("SELECT contact_id FROM inkorg_list WHERE id = 'case-ui'")->fetchColumn();
$listJson = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 'case-ui'")->fetchColumn(), true);
$threadJson = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'case-ui'")->fetchColumn(), true);
diag_ok($listContact === 'kt_ui' && is_array($listJson) && (string) ($listJson['contact_id'] ?? '') === 'kt_ui', 'listans kolumn och row_json får contact_id');
diag_ok(is_array($threadJson) && (string) ($threadJson['contact_id'] ?? '') === 'kt_ui', 'trådens row_json får contact_id');
$after = jeab_contacts_sole_audit_store($pdo, $spec, jeab_contacts_sole_audit_columns($pdo, 'contacts'));
diag_ok((int) ($after['jarfalla']['empty_contact_id'] ?? -1) === 0, 'trådauditen rör sig när länken skrivs');
$listSpec = jeab_contacts_sole_audit_specs()['inkorg_list'];
$listAudit = jeab_contacts_sole_audit_store($pdo, $listSpec, jeab_contacts_sole_audit_columns($pdo, 'contacts'));
diag_ok((int) ($listAudit['jarfalla']['empty_contact_id'] ?? -1) === 2, 'listraden som speglades räknas inte längre som tom');

$undoId = (string) ($wet['undo_id'] ?? '');
$undo = jeab_contacts_sole_dispatch(['action' => 'contacts.link_by_email_undo', 'undo_id' => $undoId, 'dry' => 0]);
diag_ok(!empty($undo['ok']) && ($undo['written'] ?? false) === true, 'ånger skriver');
$listBack = (string) $pdo->query("SELECT contact_id FROM inkorg_list WHERE id = 'case-ui'")->fetchColumn();
$listJsonBack = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_list WHERE id = 'case-ui'")->fetchColumn(), true);
$threadBack = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_threads WHERE id = 'case-ui'")->fetchColumn(), true);
diag_ok($listBack === '' && is_array($listJsonBack) && !array_key_exists('contact_id', $listJsonBack), 'listans länk tas tillbaka');
diag_ok(is_array($threadBack) && !array_key_exists('contact_id', $threadBack), 'trådens länk tas tillbaka');

echo "OK  contacts.link_diag\n";
