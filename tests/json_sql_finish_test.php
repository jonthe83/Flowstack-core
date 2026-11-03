<?php

declare(strict_types=1);

/**
 * JSON → SQL för borttagna ordrar, kalenderlogg, inkorgsvyer,
 * inkorgens JSON-reserv och kontakter som inte skapades.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-json-sql-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg/hs-import', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
putenv('STEG4_CUTOVER');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/ordrar_deleted_sql_migrate.php';
require dirname(__DIR__) . '/src/calendar_log_sql.php';
require dirname(__DIR__) . '/src/inkorg_views_sql.php';
require_once dirname(__DIR__) . '/src/inkorg_contact_create.php';

function js(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function js_hash(string $path): string
{
    return hash('sha256', (string) file_get_contents($path));
}

$pdo = jeab_store()->pdo();
js($pdo instanceof PDO, 'sqlite är öppen');

$deletedPath = jeab_ordrar_deleted_path();
$deletedDoc = [
    'ids' => ['900001' => ['at' => '2026-01-01', 'hs' => ['555001'], 'booking_id' => 0]],
    'hs' => ['555001' => 900001],
];
file_put_contents($deletedPath, json_encode($deletedDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$deletedHash = js_hash($deletedPath);
$GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL'] = true;
jeab_sql_doc_put($pdo, JEAB_ORDRAR_DELETED_DOC_KEY, [
    'ids' => ['900002' => ['at' => '2026-02-01', 'hs' => [], 'booking_id' => 0]],
    'hs' => [],
]);
$before = jeab_ordrar_deleted_doc();
js(isset($before['ids']['900002']) && !isset($before['ids']['900001']), 'sql-läge läser inte json-id');
$dryDel = jeab_ordrar_deleted_sql_migrate([]);
js(($dryDel['dry'] ?? false) === true && (int) ($dryDel['would'] ?? 0) === 1 && (int) ($dryDel['inserted_ids'] ?? 0) === 1 && ($dryDel['written'] ?? true) === false, 'ordrar dry räknar den saknade raden');
js(js_hash($deletedPath) === $deletedHash, 'ordrar dry lämnar filen');
js(!isset(jeab_ordrar_deleted_doc()['ids']['900001']), 'ordrar dry skriver inte');
$wetDel = jeab_ordrar_deleted_sql_migrate(['dry' => 0]);
js((int) ($wetDel['inserted_ids'] ?? 0) === 1 && (int) ($wetDel['inserted_hs'] ?? 0) === 1 && ($wetDel['written'] ?? false) === true, 'ordrar wet lägger id och hs');
$both = jeab_ordrar_deleted_doc();
js(isset($both['ids']['900001'], $both['ids']['900002']) && isset($both['hs']['555001']), 'sql har båda och hs');
js(js_hash($deletedPath) === $deletedHash, 'ordrar wet lämnar filen');
$againDel = jeab_ordrar_deleted_sql_migrate(['dry' => 0]);
js((int) ($againDel['would'] ?? 1) === 0 && (int) ($againDel['inserted_ids'] ?? 1) === 0 && ($againDel['written'] ?? true) === false, 'ordrar andra körningen är 0');
$raw = json_decode((string) file_get_contents($deletedPath), true);
$raw['hs']['555002'] = 900001;
file_put_contents($deletedPath, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$hsHash = js_hash($deletedPath);
$hsDry = jeab_ordrar_deleted_sql_migrate([]);
js((int) ($hsDry['inserted_ids'] ?? 1) === 0 && (int) ($hsDry['inserted_hs'] ?? 0) === 1 && (int) ($hsDry['would'] ?? 0) === 1, 'bara saknad hs räknas');
$hsWet = jeab_ordrar_deleted_sql_migrate(['dry' => '0']);
js((int) ($hsWet['inserted_hs'] ?? 0) === 1 && isset(jeab_ordrar_deleted_doc()['hs']['555002']), 'hs skrivs utan att röra befintliga id');
js(js_hash($deletedPath) === $hsHash, 'hs-synk lämnar filen');
$roll = jeab_ordrar_deleted_sql_cutover(['enable' => 0, 'dry' => 0]);
js(($roll['ok'] ?? false) === true && ($roll['storage'] ?? '') === 'json' && ($roll['file_unchanged'] ?? false) === true, 'ordrar rollback sätter flaggan');
js(js_hash($deletedPath) === $hsHash, 'ordrar rollback skriver inte om filen');
$fromFile = jeab_ordrar_deleted_doc();
js(isset($fromFile['ids']['900001']) && !isset($fromFile['ids']['900002']), 'flaggan läser json');
$back = jeab_ordrar_deleted_sql_cutover(['enable' => 1, 'dry' => 0]);
js(!empty($back['cleared_rollback']) && jeab_ordrar_deleted_storage_mode() === 'sql' && isset(jeab_ordrar_deleted_doc()['ids']['900002']), 'enable=1 tar bort flaggan');
js(js_hash($deletedPath) === $hsHash, 'enable=1 lämnar filen');
unset($GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL']);

$logPdo = new PDO('sqlite::memory:');
$logPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $logPdo;
jeab_log_pdo();
$calPath = jeab_calendar_log_path();
$calJson = [
    'entries' => [
        ['id' => 1, 'type' => 'booked', 'at' => '2026-10-01 09:00:00'],
        ['id' => 2, 'type' => 'cancelled', 'at' => '2026-10-02 09:00:00'],
    ],
    'backfilled' => false,
];
file_put_contents($calPath, json_encode($calJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$calHash = js_hash($calPath);
jeab_log_doc_put_pdo($logPdo, 'calendar_log', ['entries' => [['id' => 1, 'type' => 'booked', 'at' => '2026-10-01 09:00:00']], 'backfilled' => false]);
js(count(jeab_calendar_log_read()['entries']) === 1, 'kalender läser sql');
$calDry = jeab_calendar_log_sql_resync([]);
js(($calDry['dry'] ?? false) === true && (int) ($calDry['would'] ?? 0) === 1 && (int) ($calDry['json_entries'] ?? 0) === 2 && (int) ($calDry['sql_entries'] ?? 0) === 1 && (int) ($calDry['updated'] ?? 1) === 0, 'kalender dry räknar');
js(js_hash($calPath) === $calHash && count(jeab_calendar_log_read()['entries']) === 1, 'kalender dry skriver inte');
$calWet = jeab_calendar_log_sql_resync(['dry' => 0]);
js((int) ($calWet['updated'] ?? 0) === 1 && count(jeab_calendar_log_read()['entries']) === 2, 'kalender wet ersätter sql-dokumentet');
js(js_hash($calPath) === $calHash, 'kalender wet lämnar filen');
$calAgain = jeab_calendar_log_sql_resync(['dry' => 0]);
js((int) ($calAgain['would'] ?? 1) === 0 && (int) ($calAgain['updated'] ?? 1) === 0, 'kalender andra körningen är 0');
jeab_log_doc_put_pdo($logPdo, 'calendar_log', ['entries' => [['id' => 9, 'type' => 'sms', 'at' => '2026-10-03 09:00:00']], 'backfilled' => false]);
$flagDry = jeab_calendar_log_sql_resync(['rollback' => 1]);
js((int) ($flagDry['would_rollback'] ?? 0) === 1 && ($flagDry['written'] ?? true) === false && !jeab_json_sql_rollback('calendar_log_json'), 'kalender rollback dry sätter inte flaggan');
jeab_calendar_log_sql_resync(['rollback' => 1, 'dry' => 0]);
js(jeab_json_sql_rollback('calendar_log_json') && count(jeab_calendar_log_read()['entries']) === 2, 'kalenderflaggan läser json');
js(js_hash($calPath) === $calHash, 'kalenderflaggan lämnar filen');
jeab_calendar_log_sql_resync(['rollback' => 0, 'dry' => 0]);
js(!jeab_json_sql_rollback('calendar_log_json') && count(jeab_calendar_log_read()['entries']) === 1, 'kalenderflaggan av läser sql');
unset($GLOBALS['_JEAB_LOG_PDO']);

$rules = [
    'views' => [
        ['id' => 'v-same', 'name' => 'Samma', 'filter' => ['status' => ['open']], 'priority' => 1],
        ['id' => 'v-diff', 'name' => 'Ny', 'filter' => ['priority' => 2], 'priority' => 2],
        ['id' => 'v-miss', 'name' => 'Saknas', 'filter' => ['tag' => 'sol'], 'priority' => 3],
    ],
    'rules' => [],
    'lists' => [],
];
$rulesPath = $tmp . '/inkorg/rules.json';
file_put_contents($rulesPath, json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
$rulesHash = js_hash($rulesPath);
$pdo->exec('CREATE TABLE inkorg_views (view_id TEXT PRIMARY KEY, name TEXT NOT NULL, position INTEGER NOT NULL, payload TEXT NOT NULL)');
jeab_inkorg_views_sql_boot();
$mapped = jeab_migrate_inkorg_views_rows(json_decode((string) file_get_contents($rulesPath), true));
$byView = [];
foreach ($mapped as $row) {
    $byView[$row['view_id']] = $row;
}
$ins = $pdo->prepare('INSERT INTO inkorg_views (view_id, name, position, payload) VALUES (?, ?, ?, ?)');
$ins->execute(['v-same', $byView['v-same']['name'], $byView['v-same']['position'], $byView['v-same']['payload']]);
$ins->execute(['v-diff', 'Gammal', 1, '{"id":"v-diff","name":"Gammal"}']);
$ins->execute(['v-extra', 'Extra', 9, '{"id":"v-extra","name":"Extra"}']);
$cols = $pdo->query('PRAGMA table_info(inkorg_views)')->fetchAll(PDO::FETCH_COLUMN, 1);
js(!in_array('filter', $cols, true) && !in_array('priority', $cols, true), 'vyer saknar filter och priority');
$viewDry = jeab_inkorg_views_sql_resync([]);
js(($viewDry['dry'] ?? false) === true && (int) ($viewDry['json_rows'] ?? 0) === 3 && (int) ($viewDry['sql_rows'] ?? 0) === 3 && (int) ($viewDry['differ'] ?? 0) === 1 && (int) ($viewDry['would_insert'] ?? 0) === 1 && (int) ($viewDry['sql_only'] ?? 0) === 1, 'vyer dry räknar');
js(($viewDry['columns_missing'] ?? []) === ['filter', 'priority'], 'vyer dry visar saknade kolumner');
js(($viewDry['would_add_columns'] ?? []) === ['filter', 'priority'], 'vyer dry rapporterar would_add_columns');
$cols = $pdo->query('PRAGMA table_info(inkorg_views)')->fetchAll(PDO::FETCH_COLUMN, 1);
js(!in_array('filter', $cols, true), 'vyer dry gör ingen ALTER');
js(js_hash($rulesPath) === $rulesHash, 'vyer dry lämnar rules.json');
$viewWet = jeab_inkorg_views_sql_resync(['dry' => 0]);
js((int) ($viewWet['updated'] ?? 0) >= 1 && (int) ($viewWet['inserted'] ?? 0) === 1, 'vyer wet uppdaterar och lägger till');
$cols = $pdo->query('PRAGMA table_info(inkorg_views)')->fetchAll(PDO::FETCH_COLUMN, 1);
js(in_array('filter', $cols, true) && in_array('priority', $cols, true), 'vyer wet lägger kolumnerna');
$diff = $pdo->query("SELECT name, priority FROM inkorg_views WHERE view_id = 'v-diff'")->fetch(PDO::FETCH_ASSOC);
js(is_array($diff) && ($diff['name'] ?? '') === 'Ny' && (int) ($diff['priority'] ?? 0) === 2, 'vyernas priority följer json');
js((string) $pdo->query("SELECT view_id FROM inkorg_views WHERE view_id = 'v-extra'")->fetchColumn() === 'v-extra', 'extra sql-rad ligger kvar');
js((string) $pdo->query("SELECT view_id FROM inkorg_views WHERE view_id = 'v-miss'")->fetchColumn() === 'v-miss', 'saknad vy läggs till');
js(js_hash($rulesPath) === $rulesHash, 'vyer wet lämnar rules.json');
$viewAgain = jeab_inkorg_views_sql_resync(['dry' => 0]);
js((int) ($viewAgain['differ'] ?? 1) === 0 && (int) ($viewAgain['would'] ?? 1) === 0 && (int) ($viewAgain['inserted'] ?? 1) === 0, 'vyer andra körningen är 0');
js(($viewAgain['would_add_columns'] ?? ['x']) === [] && ($viewAgain['columns_missing'] ?? ['x']) === [], 'vyer andra körningen har kolumnerna');
$priorityType = '';
foreach ($pdo->query('PRAGMA table_info(inkorg_views)')->fetchAll(PDO::FETCH_ASSOC) as $info) {
    if (is_array($info) && ($info['name'] ?? '') === 'priority') {
        $priorityType = strtoupper((string) ($info['type'] ?? ''));
    }
}
js(str_contains($priorityType, 'INT'), 'priority är heltal');

$errPath = jeab_app_errors_path();
$lines = [];
for ($i = 1; $i <= 6; $i++) {
    $lines[] = json_encode(['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => sprintf('c-fail%04d', $i)], JSON_UNESCAPED_UNICODE);
}
file_put_contents($errPath, implode("\n", $lines) . "\n");
$errHash = js_hash($errPath);
file_put_contents(jeab_inkorg_trace_path(), json_encode([
    'rows' => [['event' => 'inkorg_contact_create', 'ref' => 'c-fail0007', 'message' => 'skapades inte']],
], JSON_UNESCAPED_UNICODE));
$traceHash = js_hash(jeab_inkorg_trace_path());
$GLOBALS['_JEAB_LOG_PDO'] = $logPdo;
jeab_log_line_append_raw('app_error', json_encode(['surface' => 'inkorg_contact_create', 'message' => 'skapades inte', 'ref' => 'c-fail0008'], JSON_UNESCAPED_UNICODE));
$pdo->exec('DROP TABLE IF EXISTS inkorg_contacts');
$pdo->exec("CREATE TABLE inkorg_contacts (id TEXT PRIMARY KEY, row_json TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', mobile TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL, contact_id TEXT NOT NULL DEFAULT '')");
$addInbox = static function (string $id, string $email) use ($pdo): void {
    $row = ['id' => $id, 'name' => 'Person ' . $id, 'emails' => [$email], 'mobile' => '', 'address' => 'Storgatan 1'];
    $pdo->prepare('INSERT INTO inkorg_contacts (id, row_json, email, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$id, json_encode($row, JSON_UNESCAPED_UNICODE), $email, '2026-10-01 10:00:00']);
};
for ($i = 1; $i <= 7; $i++) {
    $addInbox(sprintf('c-fail%04d', $i), $i === 1 ? 'samma@example.test' : sprintf('ny%d@example.test', $i));
}
$pdo->prepare('UPDATE inkorg_contacts SET row_json = ? WHERE id = ?')->execute([
    json_encode(['id' => 'c-fail0002', 'name' => 'Redan', 'emails' => ['redan@example.test'], 'contact_id' => 'kt_finns'], JSON_UNESCAPED_UNICODE),
    'c-fail0002',
]);
$seed = jeab_kontakt_create_unmerged(['name' => 'Finns', 'email' => 'samma@example.test', 'phone' => '', 'source' => 'seed']);
js(is_array($seed) && ($seed['id'] ?? '') !== '', 'befintlig kontakt finns');
$seedId = (string) $seed['id'];
$seedBefore = jeab_kontakter_mutate(static function (array &$contacts) use ($seedId): array {
    return $contacts[$seedId];
});
$cDry = jeab_inkorg_contact_create_run($pdo, []);
js(($cDry['dry'] ?? false) === true && (int) ($cDry['found'] ?? 0) === 8 && (int) ($cDry['would_create'] ?? 0) === 5 && (int) ($cDry['skipped_linked'] ?? 0) === 1 && (int) ($cDry['skipped_missing'] ?? 0) === 1 && (int) ($cDry['created'] ?? 1) === 0, 'kontakter dry räknar åtta och skapar noll');
$seedMid = jeab_kontakter_mutate(static function (array &$contacts) use ($seedId): array {
    return $contacts[$seedId];
});
js($seedMid === $seedBefore, 'kontakter dry rör inte befintlig kontakt');
js(js_hash($errPath) === $errHash && js_hash(jeab_inkorg_trace_path()) === $traceHash, 'kontakter dry lämnar loggarna');
$cWet = jeab_inkorg_contact_create_run($pdo, ['dry' => 0]);
js((int) ($cWet['created'] ?? 0) === 5, 'kontakter wet skapar de som saknas');
$linked = json_decode((string) $pdo->query("SELECT row_json FROM inkorg_contacts WHERE id = 'c-fail0001'")->fetchColumn(), true);
js(is_array($linked) && (string) ($linked['contact_id'] ?? '') === '', 'dubblett av e-post skapas inte och slås inte ihop');
$seedAfter = jeab_kontakter_mutate(static function (array &$contacts) use ($seedId): array {
    return $contacts[$seedId];
});
js(($seedAfter['id'] ?? '') === $seedId && ($seedAfter['owners'][0]['email'] ?? '') === 'samma@example.test', 'befintlig kontakt behåller id och e-post');
js((string) $pdo->query("SELECT updated_at FROM inkorg_contacts WHERE id = 'c-fail0001'")->fetchColumn() === '2026-10-01 10:00:00', 'updated_at rörs inte');
$cAgain = jeab_inkorg_contact_create_run($pdo, ['write' => '1']);
js((int) ($cAgain['created'] ?? 1) === 0 && (int) ($cAgain['would_create'] ?? 1) === 0, 'kontakter andra körningen är 0');
jeab_inkorg_contact_create_run($pdo, ['hold' => 1, 'dry' => 0]);
$held = jeab_inkorg_contact_create_run($pdo, ['dry' => 0]);
js((int) ($held['held'] ?? 0) === 1 && ($held['dry'] ?? false) === true && (int) ($held['created'] ?? 1) === 0, 'hold tvingar dry');
js(js_hash($errPath) === $errHash, 'kontakter lämnar felloggen');
unset($GLOBALS['_JEAB_LOG_PDO']);

file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => [[
    'id' => 'm-json',
    'thread_id' => 'th-json',
    'received_at' => '2026-10-01 12:00:00',
    'subject' => 'Json',
]]], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => ['th-json' => ['id' => 'th-json', 'status' => 'open']]], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/hs-import/index.jsonl', json_encode(['id' => 'hsi-0123456789abcdef', 'subject' => 'Arkiv']) . "\n");
$jsonlHash = js_hash($tmp . '/inkorg/hs-import/index.jsonl');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
js(jeab_inkorg_json_messages() !== [] && isset(jeab_hs_import_index()['by_id']['hsi-0123456789abcdef']), 'json-läge läser reserven');
js(jeab_data_backend_set('mysql') && jeab_inkorg_use_sql(), 'inkorg är sql');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
js(jeab_inkorg_json_reserve_closed() && jeab_inkorg_json_messages() === [] && !isset(jeab_hs_import_index()['by_id']['hsi-0123456789abcdef']), 'sql-läge läser inte json-reserven');
js(js_hash($tmp . '/inkorg/hs-import/index.jsonl') === $jsonlHash && is_file($tmp . '/inkorg/messages.json'), 'reservfilerna ligger kvar');
$GLOBALS['_JEAB_JSON_SQL_ROLLBACK']['inkorg_json_fallback'] = true;
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
js(!jeab_inkorg_json_reserve_closed() && jeab_inkorg_json_messages() !== [] && isset(jeab_hs_import_index()['by_id']['hsi-0123456789abcdef']), 'en flagga slår på reserven igen');

echo "json_sql_finish_test ok\n";
