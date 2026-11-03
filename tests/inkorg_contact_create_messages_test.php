<?php

declare(strict_types=1);

/**
 * data.inkorg_contact_create ska känna igen de meddelanden som verkligen
 * skrivs när en kontakt inte skapas, och ta ref/thread/contact ur den rad
 * jeab_log_app_error lagrar. Torr körning skapar inget.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-contact-msg-' . bin2hex(random_bytes(4));
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
require dirname(__DIR__) . '/src/inkorg_contact_create.php';

function ccm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/**
 * Samma fält som jeab_log_app_error skriver till log_lines.payload.
 *
 * @param array<string, mixed> $extra
 */
function ccm_line(string $surface, string $message, array $extra = []): string
{
    $row = [
        'time' => '2026-02-02 08:00:00',
        'surface' => $surface,
        'message' => $message,
        'action' => '',
        'path' => '/index.php?p=inkorg',
        'fingerprint' => hash('sha256', $surface . '|' . $message . '|' . json_encode($extra)),
        'count' => 1,
        'file' => 'src/contacts_sole.php',
        'line' => 8550,
    ];
    foreach ($extra as $key => $value) {
        $row[$key] = $value;
    }
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '{}';
}

$thread = 'ink_' . bin2hex(random_bytes(24));
$contact = 'kt_' . bin2hex(random_bytes(8));
$lines = [
    ccm_line('contacts_sole.inflow_link', 'RuntimeException', ['ref' => 'c-inflow1']),
    ccm_line('contacts_sole.divert_property', 'InvalidArgumentException', ['contact_id' => $contact]),
    ccm_line('kontakter', 'Kunde inte koppla kontakt till påminnelse 55', ['thread_id' => $thread]),
    ccm_line('kontakter', 'RuntimeException: Kontakten kunde inte sparas. @ src/kontakter_migrate.php:845', ['ref' => 'c-saved01']),
    ccm_line('inkorg', 'kontakt kunde inte skapas', ['ref' => 'c-auto0001']),
    ccm_line('inkorg_contact_create', 'skapades inte', ['ref' => 'c-skapad1']),
    ccm_line('fart', 'långsam request 900 ms p=inkorg anna@example.test'),
    ccm_line('fart', 'långsam request 900 ms p=inkorg anna@example.test'),
    ccm_line('fart', 'långsam request 900 ms p=inkorg anna@example.test'),
    ccm_line('db', 'RuntimeException'),
];

$log = new PDO('sqlite::memory:');
$log->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$log->exec('CREATE TABLE log_lines (id INTEGER PRIMARY KEY, type TEXT NOT NULL, level TEXT NOT NULL, at TEXT NOT NULL, co TEXT NOT NULL, payload TEXT NOT NULL)');
$ins = $log->prepare('INSERT INTO log_lines (type, level, at, co, payload) VALUES (?, ?, ?, ?, ?)');
foreach ($lines as $line) {
    $ins->execute(['app_error', 'error', '2020-01-15 08:00:00', 'malmo', $line]);
}
$GLOBALS['_JEAB_LOG_PDO'] = $log;

$pdo = jeab_store()->pdo();
$put = $pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, email, updated_at) VALUES (?, ?, ?, ?)');
foreach (['c-inflow1', 'c-saved01', 'c-auto0001', 'c-skapad1'] as $id) {
    $put->execute([
        $id,
        json_encode(['id' => $id, 'name' => 'Live ' . $id, 'emails' => [$id . '@example.test']], JSON_UNESCAPED_UNICODE),
        $id . '@example.test',
        '2020-01-15 08:00:00',
    ]);
}

$dry = jeab_inkorg_contact_create_run($pdo, []);
$debug = is_array($dry['debug'] ?? null) ? $dry['debug'] : [];
$ids = is_array($dry['ids'] ?? null) ? $dry['ids'] : [];
ccm(($dry['dry'] ?? false) === true && (int) ($dry['created'] ?? 1) === 0, 'torr körning skapar inget');
ccm((int) ($debug['failure_text'] ?? 0) === 6, 'sex verkliga feltexter räknas, inte undantagsklassen ensam');
ccm((int) ($debug['refs'] ?? 0) === 6, 'ref, thread_id och contact_id ur payloaden');
ccm(in_array('c-inflow1', $ids, true) && in_array('c-saved01', $ids, true) && in_array('c-auto0001', $ids, true) && in_array('c-skapad1', $ids, true), 'inkorgs-id från de fyra loggraderna');
ccm((int) ($dry['would_create'] ?? 0) === 4 && (int) ($dry['skipped_missing'] ?? -1) === 2, 'tråd och kontakt-id utan inkorgsrad skapas inte');
ccm((int) ($dry['found'] ?? 0) === 6, 'found räknar även tråd- och kontakt-id');

$prefixes = is_array($debug['message_prefixes'] ?? null) ? $debug['message_prefixes'] : [];
ccm(count($prefixes) >= 1 && count($prefixes) <= 10, 'högst tio prefix');
$slow = is_array($prefixes[0] ?? null) ? $prefixes[0] : [];
$slowText = (string) ($slow['prefix'] ?? '');
ccm((int) ($slow['n'] ?? 0) === 3 && strlen($slowText) <= 60 && !str_contains($slowText, '@') && str_contains($slowText, '[mejl]'), 'vanligaste prefixet är maskerat och högst 60 tecken');
$sawException = false;
$sawLink = false;
foreach ($prefixes as $row) {
    if (!is_array($row)) {
        continue;
    }
    $prefix = (string) ($row['prefix'] ?? '');
    ccm(strlen($prefix) <= 60 && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $prefix) !== 1, 'prefix utan mejl: ' . $prefix);
    if ($prefix === 'RuntimeException') {
        $sawException = true;
    }
    if (str_starts_with($prefix, 'Kunde inte koppla kontakt')) {
        $sawLink = true;
    }
}
ccm($sawException && $sawLink, 'prefixlistan visar undantagsklassen och kopplingsmeningen');

echo "inkorg_contact_create_messages_test ok\n";
