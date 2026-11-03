<?php

declare(strict_types=1);

/** SQL-loggar. Påhittad data. dry=1 skriver inget. JSON lämnas kvar som läsreserv. */

$tmp = sys_get_temp_dir() . '/jeab-log-store-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ls_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ls_hash(string $path): string
{
    return is_file($path) ? hash('sha256', (string) file_get_contents($path)) : '';
}

$calendarPath = jeab_calendar_log_path();
$eventsPath = jeab_gp_events_path();
$weekPath = jeab_gp_week_events_cache_path();
$picksPath = jeab_who_picks_path();
$diagPath = jeab_who_diag_path();
$followPath = jeab_follow_reminder_audit_path();
$hookPath = jeab_inbox_reminder_hook_audit_path();
$shopPath = jeab_webshop_index_path();
$errorsPath = jeab_app_errors_path();
$asksPath = jeab_fellogg_open_asks_path();

file_put_contents($calendarPath, json_encode([
    'entries' => [[
        'id' => 1,
        'type' => 'booked',
        'at' => '2026-10-01 10:00:00',
        'booking_id' => 1,
        'starts_at' => '2026-10-02 09:00:00',
        'title' => 'Påhittad bokning',
    ]],
    'backfilled' => true,
], JSON_UNESCAPED_UNICODE));
file_put_contents($eventsPath, json_encode([
    'saljare5@example.test' => ['41' => 'evt-paahittad'],
    'elin@example.test' => ['42' => 'evt-andra'],
], JSON_UNESCAPED_SLASHES));
$monday = new DateTimeImmutable('2026-10-19 00:00:00');
$weekKey = $monday->format('Y-m-d H:i:s') . '|Roberto|';
file_put_contents($weekPath, json_encode([
    $weekKey => ['at' => time(), 'events' => [[
        'who' => 'Roberto',
        'title' => 'Påhittat möte',
        'event_id' => 'E1',
        'all_day' => false,
        'starts_at' => '2026-10-20 10:00:00',
        'ends_at' => '2026-10-20 11:00:00',
    ]]],
], JSON_UNESCAPED_UNICODE));
file_put_contents($picksPath, json_encode([
    'fp:0123abcd0123abcd' => ['person' => 'roberto', 'at' => time()],
], JSON_UNESCAPED_UNICODE));
$diagLine = json_encode(['at' => date('Y-m-d H:i:s'), 'page' => 'test', 'who' => 'Alla'], JSON_UNESCAPED_UNICODE);
$staleDiag = json_encode(['at' => '2020-01-01 00:00:00', 'page' => 'test', 'who' => 'Alla', 'n' => 1], JSON_UNESCAPED_UNICODE);
file_put_contents($diagPath, $staleDiag . "\n" . $diagLine . "\n");
$followLine = json_encode(['at' => date('c'), 'id' => 5, 'status' => 'open', 'note' => 'påhittad'], JSON_UNESCAPED_UNICODE);
file_put_contents($followPath, $followLine . "\n");
$hookLine = json_encode(['at' => date('c'), 'action' => 'close', 'id' => 5], JSON_UNESCAPED_UNICODE);
file_put_contents($hookPath, $hookLine . "\n");
file_put_contents($shopPath, json_encode([
    'jarfalla:1001' => ['number' => '1001', 'company' => 'jarfalla', 'name' => 'Påhittad Kund'],
], JSON_UNESCAPED_UNICODE));
$errorLine = json_encode([
    'time' => '2026-10-01 10:00:00',
    'surface' => 'test',
    'message' => 'påhittat fel',
    'fingerprint' => 'abc123',
], JSON_UNESCAPED_UNICODE);
file_put_contents($errorsPath, $errorLine . "\n" . $errorLine . "\n" . "{trasig\n");
file_put_contents($asksPath, json_encode([
    ['id' => 'a1', 'asked_at' => time(), 'kind' => 'visa_mig', 'status' => 'open', 'fingerprint' => 'abc123'],
], JSON_UNESCAPED_UNICODE));

$paths = [$calendarPath, $eventsPath, $weekPath, $picksPath, $diagPath, $followPath, $hookPath, $shopPath, $errorsPath, $asksPath];
$before = [];
foreach ($paths as $path) {
    $before[$path] = ls_hash($path);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;

$dry = jeab_log_import([]);
ls_ok(($dry['dry'] ?? 0) === 1 && ($dry['ok'] ?? false) === true, 'dry saknas betyder dry=1');
ls_ok(($dry['kinds']['calendar_log']['imported'] ?? 1) === 0, 'dry=1 importerar inget');
ls_ok(($dry['kinds']['calendar_log']['would'] ?? 0) === 1, 'dry=1 räknar kalenderfilen');
ls_ok(($dry['kinds']['graph_events']['would'] ?? 0) === 2, 'dry=1 räknar två händelser');
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
ls_ok($tables === [], 'dry=1 skapar inga tabeller');
foreach ($paths as $path) {
    ls_ok(ls_hash($path) === $before[$path], 'dry=1 lämnar ' . basename($path));
}

$wet = jeab_log_import(['dry' => 0]);
ls_ok(($wet['dry'] ?? 1) === 0 && ($wet['kinds']['webshop_orders']['imported'] ?? 0) === 1, 'dry=0 importerar webshop');
ls_ok(($wet['kinds']['who_diag']['imported'] ?? 0) === 1, 'dry=0 importerar who-diag');
ls_ok(($wet['kinds']['who_diag']['skipped_stale'] ?? 0) === 1, 'who-diag äldre än sparfönstret skrivs inte');
ls_ok(($wet['kinds']['app_error']['imported'] ?? 0) === 1, 'dry=0 importerar fellogg');
ls_ok(($wet['kinds']['app_error']['skipped_dupe'] ?? 0) === 1, 'identisk fellogg rad räknas som dubblett');
ls_ok(($wet['kinds']['app_error']['skipped_invalid'] ?? 0) === 1, 'trasig fellogg rad räknas som ogiltig');
ls_ok(($wet['kinds']['fellogg_open_asks']['imported'] ?? 0) === 1, 'dry=0 importerar öppna frågor');
$again = jeab_log_import(['dry' => 0]);
$imported = 0;
foreach ((array) ($again['kinds'] ?? []) as $part) {
    $imported += (int) ($part['imported'] ?? 0);
}
ls_ok($imported === 0, 'andra körningen importerar noll');
ls_ok(($again['kinds']['who_diag']['would'] ?? 1) === 0 && ($again['kinds']['who_diag']['imported'] ?? 1) === 0, 'who-diag igen är 0');
ls_ok((int) $pdo->query("SELECT COUNT(*) FROM log_lines WHERE kind = 'who_diag'")->fetchColumn() === 1, 'who-diag har en rad efter två importer');
$dryAgain = jeab_log_import(['dry' => 1]);
ls_ok(($dryAgain['kinds']['who_diag']['would'] ?? 1) === 0, 'ny dry=1 för who-diag är 0');
ls_ok(($dryAgain['kinds']['who_diag']['skipped_stale'] ?? 0) === 1, 'ny dry=1 visar den gamla who-diag-raden');
ls_ok(($dryAgain['kinds']['app_error']['would'] ?? 1) === 0, 'ny dry=1 för fellogg är 0');
ls_ok(($dryAgain['kinds']['app_error']['skipped_dupe'] ?? 0) === 2, 'ny dry=1 visar felloggens dubbletter');
ls_ok(($dryAgain['kinds']['app_error']['skipped_invalid'] ?? 0) === 1, 'ny dry=1 visar den trasiga felloggraden');
foreach ($paths as $path) {
    ls_ok(ls_hash($path) === $before[$path], 'importen lämnar ' . basename($path));
}

$log = jeab_calendar_log_read();
$log['entries'][] = [
    'id' => 2,
    'type' => 'booked',
    'at' => '2026-10-03 11:00:00',
    'booking_id' => 2,
    'starts_at' => '2026-10-03 11:00:00',
    'title' => 'Andra påhittade',
];
jeab_calendar_log_write($log);
$third = jeab_log_import(['dry' => 0]);
ls_ok(($third['kinds']['calendar_log']['imported'] ?? 1) === 0, 'kalenderimport skriver inte över SQL');
ls_ok(count(jeab_calendar_log_read()['entries']) === 2, 'ny kalenderrad ligger i SQL');
ls_ok(ls_hash($calendarPath) === $before[$calendarPath], 'calendar-log.json är bara läsreserv');

ls_ok(jeab_who_pick_save('fp:ffffffffffffffff', 'elin'), 'personval sparas');
$picks = jeab_who_picks_read();
ls_ok(($picks['fp:0123abcd0123abcd']['person'] ?? '') === 'roberto' && ($picks['fp:ffffffffffffffff']['person'] ?? '') === 'elin', 'båda personvalen läses från SQL');
ls_ok(ls_hash($picksPath) === $before[$picksPath], 'who-picks.json är bara läsreserv');

jeab_who_diag_note('test', 'Alla', 'sql');
jeab_who_diag_record();
$tail = jeab_log_who_diag_tail(20);
ls_ok(is_array($tail) && count($tail) === 2, 'who-diag har import plus ny rad');
ls_ok(ls_hash($diagPath) === $before[$diagPath], 'who-diag.jsonl är bara läsreserv');

jeab_follow_reminder_audit_write([
    'id' => 6,
    'completed_at' => '',
    'person' => 'Roberto',
    'assignee' => 'Roberto',
    'note' => 'ny påhittad rad',
]);
$follow = jeab_follow_reminder_audit_read(6, 0);
ls_ok(count($follow) === 1 && (int) ($follow[0]['id'] ?? 0) === 6, 'påminnelsehistorik läses från SQL');
ls_ok(count(jeab_log_lines_decoded('reminder_follow') ?? []) === 2, 'gammal och ny uppföljning finns');
ls_ok(ls_hash($followPath) === $before[$followPath], 'reminder-follow.jsonl är bara läsreserv');

jeab_inbox_reminder_hook_audit('close', ['id' => 8, 'completed_at' => '', 'person' => 'Elin', 'note' => 'före'], ['id' => 8, 'completed_at' => '2026-10-20 12:00:00', 'person' => 'Elin', 'note' => 'efter']);
ls_ok(count(jeab_log_lines_decoded('reminder_hook') ?? []) === 2, 'hook-historik har import plus ny rad');
ls_ok(ls_hash($hookPath) === $before[$hookPath], 'reminder-hook.jsonl är bara läsreserv');

$shop = jeab_webshop_index();
$shop['jarfalla:1002'] = ['number' => '1002', 'company' => 'jarfalla', 'name' => 'Andra Kunden'];
jeab_webshop_index_save($shop);
$shopRead = jeab_webshop_index();
ls_ok(isset($shopRead['jarfalla:1001'], $shopRead['jarfalla:1002']), 'webshopindex läses från SQL');
ls_ok(ls_hash($shopPath) === $before[$shopPath], 'webshop-orders.json är bara läsreserv');

$events = (int) $pdo->query('SELECT COUNT(*) FROM log_graph_events')->fetchColumn();
ls_ok($events === 2, 'graph-händelser ligger i SQL');
ls_ok(is_file($eventsPath), 'graph-person-events.json ligger kvar');

$http = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$http): array {
    $http++;
    return ['code' => 500, 'body' => []];
};
$cached = jeab_gp_week_events_cached($monday, 'Roberto', null);
ls_ok($http === 0 && ($cached[0]['title'] ?? '') === 'Påhittat möte', 'veckocachen läses från SQL');
ls_ok(ls_hash($weekPath) === $before[$weekPath], 'graph-week-cache.json är bara läsreserv');

jeab_log_app_error('test', 'ett nytt påhittat fel');
$errors = jeab_app_errors_read();
ls_ok(count($errors) === 2, 'felloggen läser import och ny rad från SQL');
ls_ok(jeab_log_app_error_fp_count('abc123') === 1, 'fingeravtryck räknas i SQL');
ls_ok(ls_hash($errorsPath) === $before[$errorsPath], 'app-errors.jsonl är bara läsreserv');

$ask = jeab_fellogg_open_ask_append(['id' => 'a2', 'fingerprint' => 'def456', 'kind' => 'visa_mig']);
$asks = jeab_fellogg_open_asks_read();
$ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $asks);
ls_ok(is_array($ask) && in_array('a1', $ids, true) && in_array('a2', $ids, true), 'öppna frågor läses från SQL');
ls_ok(ls_hash($asksPath) === $before[$asksPath], 'fellogg-open-asks.json är bara läsreserv');

$stalePath = $tmp . '/stale-who.jsonl';
$staleLines = [];
for ($i = 0; $i < 17; $i++) {
    $staleLines[] = json_encode(['at' => sprintf('2020-01-02 00:00:%02d', $i), 'page' => 'test', 'n' => $i], JSON_UNESCAPED_UNICODE);
}
file_put_contents($stalePath, implode("\n", $staleLines) . "\n");
$beforeStaleRows = (int) $pdo->query("SELECT COUNT(*) FROM log_lines WHERE kind = 'who_diag'")->fetchColumn();
$staleWet = jeab_log_import_lines_kind($pdo, 'who_diag', $stalePath, false);
$staleAgain = jeab_log_import_lines_kind($pdo, 'who_diag', $stalePath, true);
ls_ok(($staleWet['would'] ?? 1) === 0 && ($staleWet['imported'] ?? 1) === 0 && ($staleWet['skipped_stale'] ?? 0) === 17, '17 gamla who-diag importeras inte');
ls_ok(($staleAgain['would'] ?? 1) === 0 && ($staleAgain['skipped_stale'] ?? 0) === 17, 'upprepad gammal who-diag är 0');
ls_ok((int) $pdo->query("SELECT COUNT(*) FROM log_lines WHERE kind = 'who_diag'")->fetchColumn() === $beforeStaleRows, 'gammal who-diag skapar inga rader');

$gapPath = $tmp . '/gap-errors.jsonl';
$gapLines = [];
for ($i = 0; $i < 263; $i++) {
    $gapLines[] = json_encode([
        'time' => '2026-10-01 10:00:00',
        'surface' => 'test',
        'message' => 'påhittat fel ' . $i,
        'fingerprint' => sprintf('gap%03d', $i),
    ], JSON_UNESCAPED_UNICODE);
}
for ($i = 0; $i < 23; $i++) {
    $gapLines[] = $gapLines[0];
}
file_put_contents($gapPath, implode("\n", $gapLines) . "\n");
$gapDry = jeab_log_import_lines_kind($pdo, 'app_error', $gapPath, true);
ls_ok(($gapDry['would'] ?? 0) === 263 && ($gapDry['skipped_dupe'] ?? 0) === 23 && ($gapDry['skipped_invalid'] ?? 1) === 0, '286 felloggrader varav 23 identiska');
$gapWet = jeab_log_import_lines_kind($pdo, 'app_error', $gapPath, false);
$gapAfter = jeab_log_import_lines_kind($pdo, 'app_error', $gapPath, true);
ls_ok(($gapWet['imported'] ?? 0) === 263 && ($gapWet['skipped_dupe'] ?? 0) === 23, 'dry=0 tar in de unika felloggraderna');
ls_ok(($gapAfter['would'] ?? 1) === 0 && ($gapAfter['skipped_dupe'] ?? 0) === 286, 'ny dry=1 har inga felloggrader kvar');
ls_ok((int) $pdo->query("SELECT COUNT(*) FROM log_lines WHERE payload = " . $pdo->quote($gapLines[0]))->fetchColumn() === 1, 'identisk felloggrad lagras en gång');

$denied = jeab_log_import_dispatch(['dry' => 0]);
ls_ok(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'hooken kräver token');
$deniedDedupe = jeab_log_dedupe_dispatch([]);
ls_ok(($deniedDedupe['ok'] ?? true) === false && ($deniedDedupe['status'] ?? 0) === 401, 'logs.dedupe kräver token');

$pdo->exec('CREATE TABLE log_lines_keep (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, line_hash TEXT NOT NULL, at TEXT NOT NULL, payload TEXT NOT NULL)');
$pdo->exec('INSERT INTO log_lines_keep (kind, line_hash, at, payload) SELECT kind, line_hash, at, payload FROM log_lines');
$pdo->exec('DROP TABLE log_lines');
$pdo->exec('ALTER TABLE log_lines_keep RENAME TO log_lines');
$dupeLine = '{"at":"2026-10-20 12:00:00","page":"test"}';
$dupeHash = hash('sha256', $dupeLine);
$dupeIns = $pdo->prepare('INSERT INTO log_lines (kind, line_hash, at, payload) VALUES (?, ?, ?, ?)');
$dupeIns->execute(['who_diag', $dupeHash, '2026-10-20 12:00:00', $dupeLine]);
$dupeIns->execute(['who_diag', $dupeHash, '2026-10-20 12:00:00', $dupeLine]);
$dupeDry = jeab_log_dedupe([]);
ls_ok(($dupeDry['dry'] ?? 0) === 1 && ($dupeDry['duplicate_rows'] ?? 0) === 1 && ($dupeDry['removed'] ?? 1) === 0, 'logs.dedupe dry räknar en extra rad');
ls_ok((int) $pdo->query('SELECT COUNT(*) FROM log_lines WHERE line_hash = ' . $pdo->quote($dupeHash))->fetchColumn() === 2, 'dry dedupe skriver inte');
$dupeWet = jeab_log_dedupe(['dry' => 0]);
ls_ok(($dupeWet['removed'] ?? 0) === 1 && ($dupeWet['kinds']['who_diag']['removed'] ?? 0) === 1, 'logs.dedupe tar bort extraraden');
ls_ok((int) $pdo->query('SELECT COUNT(*) FROM log_lines WHERE line_hash = ' . $pdo->quote($dupeHash))->fetchColumn() === 1, 'en kopia av who-diag är kvar');
$dupeThird = jeab_log_dedupe(['dry' => 0]);
ls_ok(($dupeThird['duplicate_rows'] ?? 1) === 0 && ($dupeThird['removed'] ?? 1) === 0, 'logs.dedupe igen är 0');

$shield = (string) file_get_contents(dirname(__DIR__) . '/src/shield.php');
ls_ok(!str_contains($shield, 'log_store'), 'shield.php nämner inte log_store');
ls_ok(str_contains($shield, 'app-errors.jsonl') && str_contains($shield, 'fellogg-open-asks.json'), 'shield.php behåller sina filnamn');
ls_ok(function_exists('jeab_log_app_error') && function_exists('jeab_fellogg_open_asks_write') && function_exists('jeab_fellogg_json_write'), 'fellogg-funktionerna som finns kvar heter likadant');

echo "log_store ok\n";
