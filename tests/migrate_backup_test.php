<?php

declare(strict_types=1);

/**
 * migrate.backup dry/run, gallring och restore_verify.
 * Temp-approot, sqlite och påhittade rader. Inga personnummer.
 */

$home = sys_get_temp_dir() . '/jeab-migrate-backup-' . bin2hex(random_bytes(4));
$root = $home . '/public_html';
$data = $root . '/data';
mkdir($data . '/inkorg', 0700, true);
mkdir($home . '/privat', 0700, true);
mkdir($data . '/notiser', 0700, true);
mkdir($data . '/secrets', 0700, true);
mkdir($root . '/fullmakt/data/pending', 0700, true);
$GLOBALS['_JEAB_APP_ROOT'] = $root;
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('JEAB_APP_ROOT=' . $root);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
putenv('CONTACTS_SOLE=0');
putenv('BACKUP_RETENTION_DAYS=14');
file_put_contents($data . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $data . '/app.sqlite',
        'json_path' => $data . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($data . '/store.json', json_encode([
    'customers' => [[
        'id' => 1,
        'name' => 'Bo Testsson',
        'created_at' => '2026-10-01 10:00:00',
    ]],
    'bookings' => [],
    'reminders' => [],
    'reminder_lists' => [],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/kontakter.json', json_encode([
    'contacts' => [
        'kt_bo' => [
            'id' => 'kt_bo',
            'name' => 'Bo Testsson',
            'email' => 'bo.testsson@example.test',
            'phone' => '0701000045',
        ],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/purchases.json', json_encode([
    [
        'reminder_id' => 9001,
        'name' => 'Nora Backupsson',
        'email' => 'nora.backupsson@example.test',
        'phone' => '0701000044',
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/login-throttle.json', json_encode([
    '198.51.100.8' => ['fails' => 2, 'until' => 1700000000],
    '203.0.113.10' => ['fails' => 1, 'until' => 1700000100],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/solkoll.json', json_encode([
    'name' => 'Testanlaggning',
    'updated_at' => '2026-10-01T10:00:00+02:00',
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/solkoll-cache.json', json_encode([
    'hits' => 3,
    'at' => '2026-10-02T08:00:00+02:00',
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/inkop-mallar.json', json_encode([
    ['id' => 'mall_kabel', 'name' => 'Kabel', 'text' => 'Exempel'],
    ['id' => 'mall_skap', 'name' => 'Skap', 'text' => 'Exempel tva'],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/external-queue.json', json_encode([
    'jobs' => [['kind' => 'ping', 'at' => '2026-10-03T09:00:00+02:00']],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/notiser/mallar.json', json_encode([
    'items' => ['n1' => ['body' => 'Hej test', 'updated_at' => '2026-10-04T09:00:00+02:00']],
    'pdf' => [],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/inkorg/messages.json', json_encode(['messages' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/inkorg/threads.json', json_encode(['threads' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/inkorg/sent-new.json', json_encode(['rows' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/app-errors-webhook-resolved.json', json_encode([
    '11223344' => ['at' => 1700000000, 'by' => 'redaktor@example.test'],
], JSON_UNESCAPED_UNICODE));
file_put_contents($data . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-migrate-backup']));
file_put_contents($root . '/fullmakt/data/pending/testtoken.json', json_encode([
    'token' => 'testtoken',
    'status' => 'pending',
    'name' => 'Bo Testsson',
    'email' => 'bo.testsson@example.test',
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/migrate/backup.php';

function bak(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return array<string, string> */
function bak_json_hashes(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (preg_match('/\.jsonl?$/i', $path) !== 1) {
            continue;
        }
        $hash = hash_file('sha256', $path);
        if (is_string($hash)) {
            $out[substr($path, strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1)] = $hash;
        }
    }
    ksort($out);

    return $out;
}

/** @return list<string> */
function bak_names(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $names = [];
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $names[] = $name . (is_dir($dir . '/' . $name) && !is_link($dir . '/' . $name) ? '/' : '');
    }
    sort($names);

    return $names;
}

function bak_mode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 0777;
}

/** @param array<string, mixed> $report */
function bak_quiet(array $report, string $label): void
{
    $encoded = (string) json_encode($report, JSON_UNESCAPED_UNICODE);
    foreach ([
        'Bo Testsson',
        'Nora Backupsson',
        'bo.testsson@example.test',
        'nora.backupsson@example.test',
        '0701000044',
        '0701000045',
        '198.51.100.8',
        'mall_kabel',
        'tok-migrate-backup',
        'redaktor@example.test',
    ] as $secret) {
        bak(!str_contains($encoded, $secret), $label . ' saknar radinnehåll');
    }
}

/** @param list<array<string, mixed>> $rows @param string $key */
function bak_index(array $rows, string $key): array
{
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row[$key])) {
            $out[(string) $row[$key]] = $row;
        }
    }

    return $out;
}

$pdo = jeab_data_pdo();
bak($pdo instanceof PDO, 'sqlite finns');
if (!$pdo instanceof PDO) {
    exit(1);
}
$throttle = jeab_migrate_find('login_throttle');
$mallar = jeab_migrate_find('inkop_mallar');
bak(is_array($throttle) && is_array($mallar), 'modulerna finns i registret');
if (!is_array($throttle) || !is_array($mallar)) {
    exit(1);
}
jeab_migrate_ensure($pdo, $throttle);
jeab_migrate_ensure($pdo, $mallar);
$logLinesModule = jeab_migrate_find('app_errors');
$logDocsModule = jeab_migrate_find('calendar_log');
bak(is_array($logLinesModule) && is_array($logDocsModule), 'loggmodulerna finns');
if (!is_array($logLinesModule) || !is_array($logDocsModule)) {
    exit(1);
}
jeab_migrate_ensure($pdo, $logLinesModule);
jeab_migrate_ensure($pdo, $logDocsModule);
$pdo->prepare('INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute(['app_error:aaa', 'app_error', 'aaa', '2026-10-01T09:00:00Z', 'rad-ett']);
$pdo->prepare('INSERT INTO log_docs (doc_key, payload, updated_at) VALUES (?, ?, ?)')
    ->execute(['calendar_log', '{"n":1}', '2026-10-01 10:00:00']);
bak(jeab_migrate_backup_log_table('log_lines') && jeab_migrate_backup_log_table('log_docs') && jeab_migrate_backup_log_table('app_error_webhook'), 'loggtabeller är kända');
bak(!jeab_migrate_backup_log_table('bookings') && !jeab_migrate_backup_log_table('contacts') && !jeab_migrate_backup_log_table('login_throttle') && !jeab_migrate_backup_log_table('log_webshop_orders'), 'affärstabeller är strikta');
$throttleSource = json_decode((string) file_get_contents($data . '/login-throttle.json'), true);
$mallSource = json_decode((string) file_get_contents($data . '/inkop-mallar.json'), true);
bak(is_array($throttleSource) && is_array($mallSource), 'testraderna går att läsa');
jeab_migrate_upsert($pdo, $throttle, jeab_migrate_login_throttle_rows(is_array($throttleSource) ? $throttleSource : []));
jeab_migrate_upsert($pdo, $mallar, jeab_migrate_inkop_mallar_rows(is_array($mallSource) ? $mallSource : []));
bak((int) $pdo->query('SELECT COUNT(*) FROM login_throttle')->fetchColumn() === 2, 'spärrtabellen har två rader');
bak((int) $pdo->query('SELECT COUNT(*) FROM inkop_mallar')->fetchColumn() === 2, 'malltabellen har två rader');

$tz = new DateTimeZone('Europe/Stockholm');
$oldName = (new DateTimeImmutable('@' . (time() - 20 * 86400)))->setTimezone($tz)->format('Ymd-His');
$youngName = (new DateTimeImmutable('@' . (time() - 10 * 86400)))->setTimezone($tz)->format('Ymd-His');
$backups = $home . '/privat/backups';
mkdir($backups . '/' . $oldName, 0700, true);
mkdir($backups . '/spara-denna', 0700, true);
mkdir($backups . '/' . $youngName, 0700, true);
file_put_contents($backups . '/' . $oldName . '/marker.txt', 'gammal');
file_put_contents($backups . '/spara-denna/marker.txt', 'behall');
file_put_contents($backups . '/' . $youngName . '/marker.txt', 'ung');
file_put_contents($backups . '/19990101-000000', 'fil-inte-katalog');

$beforeJson = bak_json_hashes($data);
$beforeNames = bak_names($backups);
$liveBefore = (string) $pdo->query('SELECT COUNT(*) || ":" || COALESCE(SUM(LENGTH(payload)), 0) FROM login_throttle')->fetchColumn();
$backendBefore = jeab_data_backend();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_migrate_backup_dispatch(['mode' => 'run']);
bak(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401 && empty($denied['written']), 'utan token skrivs inget');
bak(bak_names($backups) === $beforeNames, 'nekad hook lämnar katalogerna');

$badMode = jeab_migrate_backup(['mode' => 'wipe']);
bak(empty($badMode['ok']) && empty($badMode['written']) && bak_names($backups) === $beforeNames, 'okänt läge skriver inte');

$dry = jeab_migrate_backup(['mode' => 'dry']);
bak(!empty($dry['ok']) && ($dry['mode'] ?? '') === 'dry' && !empty($dry['dry']) && empty($dry['written']), 'dry skriver inte');
bak(bak_json_hashes($data) === $beforeJson, 'dry lämnar json-filerna');
bak(bak_names($backups) === $beforeNames, 'dry gallrar inte');
bak((int) ($dry['retention_days'] ?? 0) === 14, 'dry redovisar gallringsgränsen');
bak_quiet($dry, 'dry');

$files = bak_index(is_array($dry['files'] ?? null) ? $dry['files'] : [], 'path');
$tables = bak_index(is_array($dry['tables'] ?? null) ? $dry['tables'] : [], 'table');
foreach ([
    'data/store.json',
    'data/kontakter.json',
    'data/notiser/mallar.json',
    'data/external-queue.json',
    'data/login-throttle.json',
    'data/solkoll.json',
    'data/solkoll-cache.json',
    'data/inkop-mallar.json',
    'fullmakt/data/pending/testtoken.json',
] as $path) {
    bak(isset($files[$path]) && !empty($files[$path]['exists']), $path . ' listas');
}
bak((int) ($files['data/login-throttle.json']['count'] ?? -1) === 2, 'spärrfilen har två poster');
bak((int) ($files['data/inkop-mallar.json']['count'] ?? -1) === 2, 'mallfilen har två poster');
bak(!isset($files['data/purchases.json']) && !isset($files['data/inkorg/messages.json']) && !isset($files['data/inkorg/threads.json']), 'inkorg och ordrar tas inte från json');
bak((int) ($files['data/kontakter.json']['count'] ?? -1) === 1, 'kontakten räknas');
bak((int) ($files['data/login-throttle.json']['bytes'] ?? -1) === filesize($data . '/login-throttle.json'), 'storleken är filens');
bak(preg_match('/^[a-f0-9]{64}$/', (string) ($files['data/login-throttle.json']['sha256'] ?? '')) === 1, 'filen har sha256');
bak((string) ($files['data/login-throttle.json']['sha256'] ?? '') === hash_file('sha256', $data . '/login-throttle.json'), 'dry-checksumman är filens');
foreach (['contacts', 'purchases', 'fullmakt_docs', 'login_throttle', 'solkoll_docs', 'inkop_mallar', 'external_queue_jobs', 'notiser_items'] as $table) {
    bak(isset($tables[$table]), $table . ' listas');
}
bak(!empty($tables['login_throttle']['exists']) && (int) ($tables['login_throttle']['rows'] ?? -1) === 2, 'spärrtabellen räknas');
bak(!empty($tables['inkop_mallar']['exists']) && (int) ($tables['inkop_mallar']['rows'] ?? -1) === 2, 'malltabellen räknas');
bak(preg_match('/^[a-f0-9]{64}$/', (string) ($tables['login_throttle']['sha256'] ?? '')) === 1, 'tabellen har sha256');
$encodedFiles = (string) json_encode(array_keys($files));
bak(!str_contains($encodedFiles, 'secrets') && !str_contains($encodedFiles, 'inkorg-ingest'), 'hemligheter tas inte med');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-migrate-backup';
$hookDry = jeab_migrate_backup_dispatch(['action' => 'migrate.backup', 'mode' => 'dry']);
bak(!empty($hookDry['ok']) && !empty($hookDry['dry']) && empty($hookDry['written']), 'hookens dry skriver inte');
bak(bak_names($backups) === $beforeNames, 'hookens dry gallrar inte');

$run = jeab_migrate_backup(['mode' => 'run']);
bak(!empty($run['ok']) && ($run['mode'] ?? '') === 'run' && empty($run['dry']) && !empty($run['written']), 'run skriver backupen');
bak(bak_json_hashes($data) === $beforeJson, 'run lämnar källfilerna');
bak((string) $pdo->query('SELECT COUNT(*) || ":" || COALESCE(SUM(LENGTH(payload)), 0) FROM login_throttle')->fetchColumn() === $liveBefore, 'run lämnar live-tabellen');
bak(jeab_data_backend() === $backendBefore, 'flaggan är oförändrad');
bak_quiet($run, 'run');
$stampDir = $home . '/' . (string) ($run['dir'] ?? '');
bak(str_starts_with((string) ($run['dir'] ?? ''), 'privat/backups/'), 'svaret pekar på privat/backups');
bak(is_dir($stampDir) && str_starts_with((string) realpath($stampDir), (string) realpath($backups) . '/'), 'backupen ligger i privat/backups');
bak(!is_dir($root . '/private'), 'public_html/private skapas inte');
bak(!str_starts_with(str_replace('\\', '/', (string) realpath($stampDir)), str_replace('\\', '/', dirname(__DIR__) . '/private')), 'repots private rörs inte');
bak(bak_mode($stampDir) === 0700, 'katalogen är 0700');
$manifestPath = $stampDir . '/manifest.json';
bak(is_file($manifestPath) && bak_mode($manifestPath) === 0600, 'manifestet är 0600');
bak((string) ($run['manifest_sha256'] ?? '') === hash_file('sha256', $manifestPath), 'manifestets sha256 stämmer');
$manifest = json_decode((string) file_get_contents($manifestPath), true);
bak(is_array($manifest) && (int) ($manifest['version'] ?? 0) === 1, 'manifestet går att läsa');
if (!is_array($manifest)) {
    exit(1);
}
$manifestFiles = bak_index(is_array($manifest['files'] ?? null) ? $manifest['files'] : [], 'path');
$manifestTables = bak_index(is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [], 'table');
$copiedThrottle = $stampDir . '/files/data/login-throttle.json';
$exportedThrottle = $stampDir . '/tables/login_throttle.jsonl';
bak(is_file($copiedThrottle) && bak_mode($copiedThrottle) === 0600, 'kopian är 0600');
bak(is_file($exportedThrottle) && bak_mode($exportedThrottle) === 0600, 'tabellexporten är 0600');
bak((string) ($manifestFiles['data/login-throttle.json']['sha256'] ?? '') === hash_file('sha256', $data . '/login-throttle.json'), 'manifestets filchecksumma är källan');
bak((string) ($manifestFiles['data/login-throttle.json']['sha256'] ?? '') === hash_file('sha256', $copiedThrottle), 'manifestets filchecksumma är kopian');
bak((int) ($manifestFiles['data/inkop-mallar.json']['count'] ?? -1) === 2, 'manifestet räknar mallarna');
bak((string) ($manifestTables['login_throttle']['sha256'] ?? '') === hash_file('sha256', $exportedThrottle), 'manifestets tabellchecksumma är exporten');
bak((int) ($manifestTables['login_throttle']['rows'] ?? -1) === 2, 'manifestet räknar spärrraderna');
bak((int) ($manifestTables['inkop_mallar']['rows'] ?? -1) === 2, 'manifestet räknar mallraderna');
bak(!is_dir($backups . '/' . $oldName), 'gammal katalog gallras');
bak(is_dir($backups . '/' . $youngName) && is_file($backups . '/' . $youngName . '/marker.txt'), 'ung katalog lämnas');
bak(is_dir($backups . '/spara-denna') && (string) file_get_contents($backups . '/spara-denna/marker.txt') === 'behall', 'katalog utan mönster lämnas');
bak(is_file($backups . '/19990101-000000'), 'fil med mönstret lämnas');
$pruned = is_array($run['pruned_dirs'] ?? null) ? $run['pruned_dirs'] : [];
bak(in_array($oldName, $pruned, true) && !in_array($youngName, $pruned, true) && !in_array('spara-denna', $pruned, true), 'gallringen redovisar bara den gamla katalogen');
bak((int) ($run['pruned'] ?? -1) === count($pruned), 'pruned är antal och pruned_dirs är listan');

$verified = jeab_migrate_restore_verify(['dir' => basename($stampDir)]);
bak(!empty($verified['ok']) && ($verified['mode'] ?? '') === 'restore_verify' && empty($verified['written']) && (int) ($verified['mismatch'] ?? -1) === 0, 'restore_verify är ok');
bak(($verified['log_drift'] ?? null) === [], 'oförändrad logg ger ingen drift');
bak((string) $pdo->query('SELECT COUNT(*) || ":" || COALESCE(SUM(LENGTH(payload)), 0) FROM login_throttle')->fetchColumn() === $liveBefore, 'verify lämnar live-tabellen');
bak(bak_json_hashes($data) === $beforeJson, 'verify lämnar källfilerna');
bak_quiet($verified, 'verify');
$verifiedTables = bak_index(is_array($verified['tables'] ?? null) ? $verified['tables'] : [], 'table');
bak(!empty($verifiedTables['login_throttle']['match']) && (int) ($verifiedTables['login_throttle']['rows'] ?? -1) === 2, 'spärrtabellen matchar');
bak(!empty($verifiedTables['inkop_mallar']['match']) && (int) ($verifiedTables['inkop_mallar']['live_rows'] ?? -1) === 2, 'malltabellen matchar live');
bak((int) ($verifiedTables['login_throttle']['temp_rows'] ?? -1) === 2 && ($verifiedTables['login_throttle']['temp_sha256'] ?? '') === ($verifiedTables['login_throttle']['sha256'] ?? 'x'), 'återläsning till temporär tabell ger samma antal och sha256');
bak((int) $pdo->query("SELECT COUNT(*) FROM sqlite_temp_master WHERE name LIKE 'jeab_restore_%'")->fetchColumn() === 0, 'temporära tabellen tas bort');
$tmpRead = jeab_migrate_restore_temp($pdo, $exportedThrottle, 'tabell_som_inte_finns', ['ip']);
bak(empty($tmpRead['ok']), 'återläsning mot saknad live-tabell ger ok:false');

$absolute = jeab_migrate_restore_verify(['dir' => $stampDir]);
bak(!empty($absolute['ok']) && ($absolute['mode'] ?? '') === 'restore_verify' && empty($absolute['written']) && (int) ($absolute['mismatch'] ?? -1) === 0, 'restore_verify på absolut privat-sökväg är ok');
bak_quiet($absolute, 'absolut');
$absoluteTables = bak_index(is_array($absolute['tables'] ?? null) ? $absolute['tables'] : [], 'table');
bak(!empty($absoluteTables['login_throttle']['match']) && (int) ($absoluteTables['login_throttle']['rows'] ?? -1) === 2 && (int) ($absoluteTables['login_throttle']['live_rows'] ?? -1) === 2, 'absolut sökväg räknar spärrraderna');

$savedManifest = (string) file_get_contents($manifestPath);
$stripped = $manifest;
$strippedTables = [];
foreach (is_array($stripped['tables'] ?? null) ? $stripped['tables'] : [] as $entry) {
    if (!is_array($entry)) {
        $strippedTables[] = $entry;
        continue;
    }
    unset($entry['columns'], $entry['stored']);
    $strippedTables[] = $entry;
}
$stripped['tables'] = $strippedTables;
file_put_contents($manifestPath, (string) json_encode($stripped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$fallback = jeab_migrate_restore_verify(['dir' => $stampDir]);
bak(!empty($fallback['ok']) && (int) ($fallback['mismatch'] ?? -1) === 0, 'verify utan columns och stored är ok');
bak_quiet($fallback, 'fallback');
$fallbackTables = bak_index(is_array($fallback['tables'] ?? null) ? $fallback['tables'] : [], 'table');
bak((int) ($fallbackTables['login_throttle']['rows'] ?? -1) === 2 && (int) ($fallbackTables['login_throttle']['live_rows'] ?? -1) === 2, 'fallback räknar spärrraderna');
file_put_contents($manifestPath, $savedManifest);

$probe = $stampDir . '/tables/order-probe.jsonl';
$probeBody = json_encode(['id' => '2', 'note' => str_repeat('n', 4000)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    . json_encode(['id' => '1', 'note' => 'kort'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($probe, $probeBody);
$loaded = jeab_migrate_restore_load($probe, ['id', 'note'], 'order_probe');
bak(!empty($loaded['ok']) && (int) ($loaded['rows'] ?? -1) === 2 && (string) ($loaded['sha256'] ?? '') === hash_file('sha256', $probe), 'radräkning följer exportfilen');
unlink($probe);

$pdo->prepare('INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute(['app_error:bbb', 'app_error', 'bbb', '2026-10-01T09:01:00Z', 'rad-tva']);
$pdo->prepare('UPDATE log_docs SET payload = ? WHERE doc_key = ?')->execute(['{"n":2}', 'calendar_log']);
$drifted = jeab_migrate_restore_verify(['dir' => $stampDir]);
bak(!empty($drifted['ok']) && empty($drifted['written']) && (int) ($drifted['mismatch'] ?? -1) === 0, 'loggdrift är inte mismatch');
bak_quiet($drifted, 'drift');
$driftNames = is_array($drifted['log_drift'] ?? null) ? $drifted['log_drift'] : [];
sort($driftNames);
bak($driftNames === ['log_docs', 'log_lines'], 'log_drift namnger log_docs och log_lines');
$driftTables = bak_index(is_array($drifted['tables'] ?? null) ? $drifted['tables'] : [], 'table');
bak(!empty($driftTables['log_lines']['match']) && !empty($driftTables['log_lines']['log_drift']) && (int) ($driftTables['log_lines']['rows'] ?? -1) === 1 && (int) ($driftTables['log_lines']['live_rows'] ?? -1) === 2, 'log_lines kräver bara att live är minst backup');
bak(!empty($driftTables['log_docs']['match']) && !empty($driftTables['log_docs']['log_drift']) && (int) ($driftTables['log_docs']['rows'] ?? -1) === 1 && (int) ($driftTables['log_docs']['live_rows'] ?? -1) === 1, 'log_docs med samma antal är drift');
bak(!empty($driftTables['login_throttle']['match']) && empty($driftTables['login_throttle']['log_drift']), 'oförändrad affärstabell är strikt träff');

$logExport = $stampDir . '/tables/log_lines.jsonl';
$savedLogExport = (string) file_get_contents($logExport);
$brokenLog = $savedLogExport;
$brokenLog[0] = $brokenLog[0] === '{' ? '[' : '{';
file_put_contents($logExport, $brokenLog);
$broken = jeab_migrate_restore_verify(['dir' => basename($stampDir)]);
bak(empty($broken['ok']) && (int) ($broken['mismatch'] ?? 0) === 1, 'trasig loggfil är mismatch');
$brokenTables = bak_index(is_array($broken['tables'] ?? null) ? $broken['tables'] : [], 'table');
bak(empty($brokenTables['log_lines']['match']) && empty($brokenTables['log_lines']['log_drift']), 'trasig loggfil är inte log_drift');
bak(($broken['log_drift'] ?? null) === ['log_docs'], 'övrig loggdrift finns kvar');
file_put_contents($logExport, $savedLogExport);

$pdo->exec('DELETE FROM log_lines');
$shrunk = jeab_migrate_restore_verify(['dir' => basename($stampDir)]);
bak(empty($shrunk['ok']) && (int) ($shrunk['mismatch'] ?? 0) === 1, 'färre loggrader är mismatch');
$shrunkTables = bak_index(is_array($shrunk['tables'] ?? null) ? $shrunk['tables'] : [], 'table');
bak(empty($shrunkTables['log_lines']['match']) && empty($shrunkTables['log_lines']['log_drift']), 'krympt logg är inte log_drift');
$pdo->prepare('INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute(['app_error:aaa', 'app_error', 'aaa', '2026-10-01T09:00:00Z', 'rad-ett']);
$pdo->prepare('INSERT INTO log_lines (line_key, kind, line_hash, at, payload) VALUES (?, ?, ?, ?, ?)')
    ->execute(['app_error:bbb', 'app_error', 'bbb', '2026-10-01T09:01:00Z', 'rad-tva']);

$throttleRow = $pdo->query('SELECT ip_key, payload FROM login_throttle LIMIT 1')->fetch(PDO::FETCH_ASSOC);
bak(is_array($throttleRow), 'spärrraden finns');
$throttleKey = is_array($throttleRow) ? (string) ($throttleRow['ip_key'] ?? '') : '';
$throttlePayload = is_array($throttleRow) ? (string) ($throttleRow['payload'] ?? '') : '';
$pdo->prepare('UPDATE login_throttle SET payload = ? WHERE ip_key = ?')->execute([$throttlePayload . 'x', $throttleKey]);
$strict = jeab_migrate_restore_verify(['dir' => basename($stampDir)]);
bak(empty($strict['ok']) && (int) ($strict['mismatch'] ?? 0) === 1, 'ändrad affärstabell är mismatch');
$strictNames = is_array($strict['log_drift'] ?? null) ? $strict['log_drift'] : [];
sort($strictNames);
bak($strictNames === ['log_docs', 'log_lines'], 'affärsmismatch lämnar log_drift');
$strictTables = bak_index(is_array($strict['tables'] ?? null) ? $strict['tables'] : [], 'table');
bak(empty($strictTables['login_throttle']['match']) && empty($strictTables['login_throttle']['log_drift']), 'affärstabellen räknas inte som log_drift');
$pdo->prepare('UPDATE login_throttle SET payload = ? WHERE ip_key = ?')->execute([$throttlePayload, $throttleKey]);
bak((string) $pdo->query('SELECT COUNT(*) || ":" || COALESCE(SUM(LENGTH(payload)), 0) FROM login_throttle')->fetchColumn() === $liveBefore, 'affärstestet återställer spärrtabellen');

$badDir = jeab_migrate_restore_verify(['dir' => '../data/kontakter.json']);
bak(empty($badDir['ok']) && ($badDir['error'] ?? '') === 'Katalogen saknas.' && empty($badDir['written']), 'sökväg utanför backupen avvisas');

$lines = file($exportedThrottle, FILE_IGNORE_NEW_LINES);
bak(is_array($lines) && isset($lines[0]), 'exporten har en rad');
$line = json_decode((string) ($lines[0] ?? ''), true);
bak(is_array($line), 'exportens rad är json');
if (is_array($line)) {
    foreach ($line as $key => $value) {
        if (is_string($value) && $value !== '') {
            $line[$key] = $value . 'x';
            break;
        }
    }
    $lines[0] = (string) json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($exportedThrottle, implode("\n", $lines) . "\n");
}
$tampered = jeab_migrate_restore_verify(['katalog' => 'privat/backups/' . basename($stampDir)]);
bak(empty($tampered['ok']) && (int) ($tampered['mismatch'] ?? 0) > 0, 'manipulerat innehåll ger mismatch');
$tamperedTables = bak_index(is_array($tampered['tables'] ?? null) ? $tampered['tables'] : [], 'table');
bak(empty($tamperedTables['login_throttle']['match']), 'den ändrade tabellen matchar inte');
bak(!empty($tamperedTables['inkop_mallar']['match']), 'en orörd tabell matchar fortfarande');
bak((string) $pdo->query('SELECT COUNT(*) || ":" || COALESCE(SUM(LENGTH(payload)), 0) FROM login_throttle')->fetchColumn() === $liveBefore, 'mismatch lämnar live-tabellen');
bak(is_file($data . '/login-throttle.json') && hash_file('sha256', $data . '/login-throttle.json') === $beforeJson['login-throttle.json'], 'källfilen är orörd');
bak_quiet($tampered, 'mismatch');

putenv('BACKUP_RETENTION_DAYS=18');
bak(jeab_migrate_backup_retention_days($pdo) === 18, 'env styr gallringen');
putenv('BACKUP_RETENTION_DAYS=5');
bak(jeab_migrate_backup_retention_days($pdo) === 21, 'ogiltig env under 14 används inte');
putenv('BACKUP_RETENTION_DAYS=40');
bak(jeab_migrate_backup_retention_days($pdo) === 21, 'ogiltig env över 30 används inte');
putenv('BACKUP_RETENTION_DAYS');
bak(function_exists('jeab_settings_put') && jeab_settings_put($pdo, 'sqlite', JEAB_BACKUP_RETENTION_KEY, '16'), 'inställningen går att spara');
bak(jeab_migrate_backup_retention_days($pdo) === 16, 'inställningen används när env saknas');
putenv('BACKUP_RETENTION_DAYS=22');
bak(jeab_migrate_backup_retention_days($pdo) === 22, 'giltig env vinner över inställningen');
putenv('BACKUP_RETENTION_DAYS=5');
bak(jeab_migrate_backup_retention_days($pdo) === 16, 'ogiltig env lämnar inställningen');
putenv('BACKUP_RETENTION_DAYS=40');
bak(jeab_migrate_backup_retention_days($pdo) === 16, 'för hög env lämnar inställningen');
putenv('BACKUP_RETENTION_DAYS');
jeab_settings_put($pdo, 'sqlite', JEAB_BACKUP_RETENTION_KEY, 'nej');
bak(jeab_migrate_backup_retention_days($pdo) === 21, 'ogiltigt värde blir 21');
jeab_settings_put($pdo, 'sqlite', JEAB_BACKUP_RETENTION_KEY, '5');
bak(jeab_migrate_backup_retention_days($pdo) === 21, 'inställning under 14 används inte');
jeab_settings_put($pdo, 'sqlite', JEAB_BACKUP_RETENTION_KEY, '40');
bak(jeab_migrate_backup_retention_days($pdo) === 21, 'inställning över 30 används inte');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
bak(str_contains($hook, "'migrate.backup'") && str_contains($hook, "'migrate.restore_verify'"), 'hook.php tar emot backup och verify');
$htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
bak(str_contains($htaccess, 'RewriteRule ^private/ - [F,L]'), 'rotens .htaccess nekar private/');
$ignore = (string) file_get_contents(dirname(__DIR__) . '/.gitignore');
bak(str_contains($ignore, '/private/backups/'), 'private/backups ignoreras');

rename($home . '/privat', $home . '/privat-borta');
$blockedWrite = jeab_migrate_backup(['mode' => 'run']);
bak(empty($blockedWrite['ok']) && empty($blockedWrite['written']), 'saknad privat skriver inte');
bak(!is_dir($root . '/private'), 'saknad privat skapar inte public_html/private');
rename($home . '/privat-borta', $home . '/privat');

echo "migrate_backup_test ok\n";
