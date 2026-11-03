<?php

declare(strict_types=1);

/**
 * send_guard.queue_diag är bara läsning.
 * Svaret har inget adressfält och kön är oförändrad efter anropet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-queue-diag-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('SEND_GUARD_TICK_DRAIN');
unset($GLOBALS['SEND_GUARD_TICK_DRAIN']);

$hashAdmin = password_hash('kod-sam-diag', PASSWORD_DEFAULT);
$hashElin = password_hash('kod-elin-diag', PASSWORD_DEFAULT);
$hashAgent = password_hash('kod-agenter-diag', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $hashAdmin],
        ['email' => 'saljare4@jarfalla.example.test', 'hash' => $hashElin],
        ['email' => 'agenter@flowstack.example.test', 'hash' => $hashAgent],
    ],
], JSON_UNESCAPED_UNICODE));
$token = 'inkorg-' . bin2hex(random_bytes(8));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $token]));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require $root . '/src/bootstrap.php';

function qd_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function qd_ok(bool $ok, string $message): void
{
    if (!$ok) {
        qd_fail($message);
    }
    echo "OK  {$message}\n";
}

function qd_fn(string $src, string $name): string
{
    $pos = strpos($src, 'function ' . $name . '(');
    if ($pos === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $pos + 10);

    return $next === false ? substr($src, $pos) : substr($src, $pos, $next - $pos);
}

function qd_keys(array $node, array &$found): void
{
    foreach ($node as $key => $value) {
        if (is_string($key)) {
            $found[] = strtolower($key);
        }
        if (is_array($value)) {
            qd_keys($value, $found);
        }
    }
}

$brake = (string) file_get_contents($root . '/src/send_guard_brake.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$hookAt = strpos($hookSrc, "=== 'send_guard.queue_diag'");
qd_ok($hookAt !== false, 'hook.php tar send_guard.queue_diag');
$hookSlice = $hookAt === false ? '' : substr($hookSrc, $hookAt, 700);
qd_ok(str_contains($hookSlice, 'jeab_send_guard_diag_authorized()'), 'kroken använder diag-grinden');
qd_ok(str_contains($hookSlice, 'jeab_send_guard_queue_diag_hook'), 'kroken anropar läsfunktionen');
qd_ok(str_contains($brake, 'SELECT @@session.time_zone AS session_time_zone, NOW() AS now_at'), 'tidszonen läses med session.time_zone och NOW()');

$readFns = [
    'jeab_send_guard_queue_diag_hook',
    'jeab_send_guard_db_timezone',
    'jeab_send_guard_diag_error',
    'jeab_send_guard_diag_guard_payload',
    'jeab_send_guard_diag_bounds',
    'jeab_send_guard_diag_stockholm',
    'jeab_send_guard_diag_zone',
    'jeab_send_guard_diag_parse',
    'jeab_send_guard_tick_drain_detail',
    'jeab_send_guard_diag_authorized',
    'jeab_send_guard_diag_person',
    'jeab_send_guard_diag_user_public',
    'jeab_send_guard_diag_actor_label',
    'jeab_send_guard_diag_activity_hit',
    'jeab_send_guard_diag_activity_text',
    'jeab_send_guard_diag_in_window',
    'jeab_send_guard_diag_activity',
    'jeab_send_guard_diag_sent',
];
foreach ($readFns as $name) {
    $body = qd_fn($brake, $name);
    qd_ok($body !== '', $name . ' finns');
    qd_ok(
        preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $body) !== 1
        && !str_contains($body, 'exec(')
        && !str_contains($body, 'send_guard_drain')
        && !str_contains($body, 'jeab_settings_put')
        && !str_contains($body, 'jeab_send_guard_ensure')
        && !str_contains($body, 'jeab_send_guard_claim')
        && !str_contains($body, 'jeab_send_guard_settle')
        && !str_contains($body, 'jeab_send_guard_mirror_kill'),
        $name . ' skriver inte'
    );
}

$_SERVER['REQUEST_METHOD'] = 'POST';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN'], $_COOKIE[JEAB_AUTH_COOKIE]);
qd_ok(!jeab_send_guard_diag_authorized(), 'utan token och utan admin är grinden stängd');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
qd_ok(jeab_send_guard_diag_authorized(), 'inkorgstoken öppnar');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $hashAdmin);
// Lösenordsradens e-post räcker inte när förnamnet delas. Sam L är admin först med kodhuvud.
qd_ok(jeab_auth_ok() && !jeab_is_admin() && !jeab_send_guard_diag_authorized(), 'Järfälla-adress utan kodhuvud får inte läsa');
jeab_code_people_save((string) jeab_auth_hash_fingerprint($hashAdmin), 'SamL', 'prefix', 'SamL');
unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
qd_ok(jeab_auth_ok() && jeab_is_admin() && jeab_send_guard_diag_authorized(), 'inloggad Sam L får läsa utan token');
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('agenter@flowstack.example.test', $hashAgent);
qd_ok(jeab_is_admin() && jeab_send_guard_diag_authorized(), 'inloggad agentkod får läsa');
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('saljare4@jarfalla.example.test', $hashElin);
qd_ok(jeab_auth_ok() && !jeab_is_admin() && !jeab_send_guard_diag_authorized(), 'inloggad icke-admin räcker inte');
unset($_COOKIE[JEAB_AUTH_COOKIE]);

$utcNow = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$utcZone = jeab_send_guard_diag_zone('SYSTEM', $utcNow);
qd_ok($utcZone instanceof DateTimeZone && $utcZone->getName() === 'UTC', 'SYSTEM som matchar UTC tolkas som UTC');
qd_ok(jeab_send_guard_diag_stockholm('2026-06-01 10:00:00', $utcZone) === '2026-06-01 12:00:00', 'UTC-klocka blir Stockholm');
$stockNow = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d H:i:s');
$stockZone = jeab_send_guard_diag_zone('SYSTEM', $stockNow);
qd_ok($stockZone instanceof DateTimeZone && $stockZone->getName() === 'Europe/Stockholm', 'SYSTEM som matchar Stockholm tolkas som lokal tid');
qd_ok(jeab_send_guard_diag_stockholm('2026-06-01 12:00:00', $stockZone) === '2026-06-01 12:00:00', 'lokal klocka behåller tiden');
$offsetZone = jeab_send_guard_diag_zone('+00:00', null);
qd_ok(jeab_send_guard_diag_stockholm('2026-06-01 10:00:00', $offsetZone) === '2026-06-01 12:00:00', 'offset +00:00 blir Stockholm');
$fromDt = jeab_send_guard_diag_parse('2026-06-01 12:00:00');
$toDt = jeab_send_guard_diag_parse('2026-06-01T18:00');
qd_ok($fromDt instanceof DateTimeImmutable && $toDt instanceof DateTimeImmutable, 'from och to tolkas');
qd_ok(jeab_send_guard_diag_bounds($fromDt, $toDt, new DateTimeZone('UTC')) === ['2026-06-01 10:00:00', '2026-06-01 16:00:00'], 'fönstret räknas om när zonen är UTC');
qd_ok(jeab_send_guard_diag_bounds($fromDt, $toDt, null) === ['2026-06-01 12:00:00', '2026-06-01 18:00:00'], 'okänd zon jämförs som Stockholm');
qd_ok(jeab_send_guard_diag_parse('2026-06-01 12:00:00Z') === null, 'offset i indata godtas inte');

$emptyPath = $tmp . '/empty.sqlite';
$empty = new PDO('sqlite:' . $emptyPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['_JEAB_SEND_GUARD_PDO'] = $empty;
$emptyBefore = $empty->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
$emptyRes = jeab_send_guard_queue_diag_hook(['from' => '2026-06-01 00:00:00', 'to' => '2026-06-02 00:00:00']);
$emptyAfter = $empty->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
qd_ok(($emptyRes['ok'] ?? true) === false && ($emptyRes['read_only'] ?? false) === true, 'saknad kö ger fel utan att skapa tabellen');
qd_ok($emptyBefore === [] && $emptyAfter === [], 'tom databas får inga tabeller');

$pdo = new PDO('sqlite:' . $tmp . '/queue.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['_JEAB_SEND_GUARD_PDO'] = $pdo;
jeab_send_guard_ensure($pdo);
$pdo->exec('CREATE TABLE IF NOT EXISTS settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->prepare('INSERT INTO settings ("key", value) VALUES (?, ?)')->execute(['SEND_GUARD_TICK_DRAIN', '1']);
$secretMail = 'kund.diag@example.test';
$secretPhone = '+46701234567';
$secretPnr = '198001011234';
$secretStreet = 'Storgatan 12';
$secretBody = 'hemlig brödtext diag';
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS log_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        line_hash TEXT NOT NULL,
        at TEXT NOT NULL,
        payload TEXT NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS inbox_sent (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        thread_id TEXT NOT NULL DEFAULT \'\',
        via TEXT NOT NULL DEFAULT \'\',
        actor TEXT NOT NULL DEFAULT \'\',
        kind TEXT NOT NULL DEFAULT \'\',
        outcome TEXT NOT NULL DEFAULT \'\',
        channel TEXT NOT NULL DEFAULT \'\',
        body_hash TEXT NOT NULL DEFAULT \'\',
        rcpt_hash TEXT NOT NULL DEFAULT \'\',
        from_addr TEXT NOT NULL DEFAULT \'\'
    )'
);
$whoIn = json_encode([
    'at' => '2026-06-01 12:21:51',
    'page' => 'solkampanj',
    'm' => 'POST',
    'me' => 'SamL',
    'email' => $secretMail,
    'who' => 'Sam L',
    'thread_id' => 'case-9',
    'note' => 'Hej ' . $secretMail . ' ring ' . $secretPhone . ' pnr ' . $secretPnr,
], JSON_UNESCAPED_UNICODE);
$whoOut = json_encode([
    'at' => '2026-06-01 12:30:00',
    'page' => 'inkorg',
    'm' => 'GET',
    'me' => 'Elin',
    'email' => 'saljare4@jarfalla.example.test',
    'note' => 'annan person',
], JSON_UNESCAPED_UNICODE);
$whoOld = json_encode([
    'at' => '2026-05-01 08:00:00',
    'page' => 'solkampanj',
    'me' => 'SamL',
    'note' => 'utanför fönstret ' . $secretMail,
], JSON_UNESCAPED_UNICODE);
$follow = json_encode([
    'at' => '2026-06-01 13:00:00',
    'id' => 42,
    'person' => 'Sam L',
    'note' => 'Påminnelse ' . $secretPhone . ' ' . $secretPnr . ' ' . $secretMail,
], JSON_UNESCAPED_UNICODE);
$lineIns = $pdo->prepare('INSERT INTO log_lines (kind, line_hash, at, payload) VALUES (?, ?, ?, ?)');
$lineIns->execute(['who_diag', hash('sha256', (string) $whoIn), '2026-06-01 12:21:51', $whoIn]);
$lineIns->execute(['who_diag', hash('sha256', (string) $whoOut), '2026-06-01 12:30:00', $whoOut]);
$lineIns->execute(['who_diag', hash('sha256', (string) $whoOld), '2026-05-01 08:00:00', $whoOld]);
$lineIns->execute(['reminder_follow', hash('sha256', (string) $follow), '2026-06-01 13:00:00', $follow]);
$sentIns = $pdo->prepare(
    'INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, rcpt_hash, from_addr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$sentIns->execute([
    '2026-06-01 12:21:51',
    'solkampanj-batteri-1',
    'manual',
    'demo@example.test',
    'batteri',
    'sent',
    'email',
    hash('sha256', 'body'),
    hash('sha256', $secretMail),
    $secretMail,
]);
$sentIns->execute([
    '2026-05-01 08:00:00',
    'solkampanj-batteri-1',
    'manual',
    'demo@example.test',
    'batteri',
    'sent',
    'email',
    hash('sha256', 'old'),
    hash('sha256', 'old'),
    $secretMail,
]);

$job = base64_encode(serialize([
    'recipient' => $secretMail,
    'address' => $secretStreet,
    'body' => $secretBody,
    'phone' => $secretPhone,
    'pnr' => $secretPnr,
    'args' => ['opts' => [
        'to' => $secretMail,
        'text' => $secretBody,
    ]],
]));
$longError = str_repeat('A', 180) . ' ' . $secretMail . ' ' . $secretPhone . ' ' . $secretPnr . ' ' . str_repeat('B', 80);
$insert = $pdo->prepare(
    'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts, last_error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insert->execute(['email', 'offert', hash('sha256', 'a'), hash('sha256', 'b'), '2026-06-01 12:00:00', '2026-06-01 12:00:00', $job, 'waiting', 3, $longError]);
$waitingId = (int) $pdo->lastInsertId();
$insert->execute(['sms', 'paminnelse', hash('sha256', 'c'), hash('sha256', 'd'), '2026-06-01 15:00:00', '2026-06-01 15:00:00', $job, 'sent', 1, '']);
$sentId = (int) $pdo->lastInsertId();
$insert->execute(['email', 'offert', hash('sha256', 'e'), hash('sha256', 'f'), '2026-05-01 08:00:00', '2026-05-01 08:00:00', $job, 'waiting', 0, $secretMail]);
$outsideId = (int) $pdo->lastInsertId();

$dump = static function (PDO $pdo): array {
    return [
        'queue' => $pdo->query('SELECT id, channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts, last_error, claim_token FROM send_queue ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'state' => $pdo->query('SELECT id, killed, killed_at, last_run, alarm_at FROM send_guard_state ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'settings' => $pdo->query('SELECT "key" AS k, value FROM settings ORDER BY "key"')->fetchAll(PDO::FETCH_ASSOC),
        'log_lines' => $pdo->query('SELECT id, kind, line_hash, at, payload FROM log_lines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'inbox_sent' => $pdo->query('SELECT id, created_at, thread_id, via, actor, kind, outcome, channel, body_hash, rcpt_hash, from_addr FROM inbox_sent ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'tables' => $pdo->query("SELECT name, sql FROM sqlite_master WHERE type IN ('table', 'index') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
    ];
};
$before = $dump($pdo);
$mailCalls = 0;
$graphCalls = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$mailCalls): array {
    $mailCalls++;

    return ['ok' => true];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;

    return ['code' => 200, 'body' => []];
};
$GLOBALS['SEND_GUARD_TICK_DRAIN'] = false;

$baseIn = [
    'from' => '2026-06-01 11:00:00',
    'to' => '2026-06-01 18:00:00',
    'address' => $secretStreet,
    'body' => $secretBody,
    'phone' => $secretPhone,
    'pnr' => $secretPnr,
    'recipient' => $secretMail,
];
$bad = jeab_send_guard_queue_diag_hook(['from' => 'inte-en-tid', 'to' => '2026-06-01 18:00:00', 'address' => $secretStreet]);
qd_ok(($bad['ok'] ?? true) === false && ($bad['rows'] ?? null) === [] && !str_contains((string) json_encode($bad), $secretStreet), 'ogiltigt from skriver inget och ekar ingen adress');
$all = jeab_send_guard_queue_diag_hook($baseIn);
$waiting = jeab_send_guard_queue_diag_hook($baseIn + ['status' => 'waiting']);
$invalidStatus = jeab_send_guard_queue_diag_hook($baseIn + ['status' => 'WAITING']);
unset($GLOBALS['SEND_GUARD_TICK_DRAIN']);
putenv('SEND_GUARD_TICK_DRAIN');
$fromSettings = jeab_send_guard_queue_diag_hook($baseIn);
putenv('SEND_GUARD_TICK_DRAIN=0');
$fromEnv = jeab_send_guard_queue_diag_hook($baseIn);
putenv('SEND_GUARD_TICK_DRAIN');
$GLOBALS['SEND_GUARD_TICK_DRAIN'] = true;
$fromGlobal = jeab_send_guard_queue_diag_hook($baseIn);
$withUser = jeab_send_guard_queue_diag_hook($baseIn + [
    'user_id' => 'SamL',
    'sent_thread' => 'solkampanj-batteri-1',
]);
$badUser = jeab_send_guard_queue_diag_hook($baseIn + ['user_id' => $secretMail]);
$badThread = jeab_send_guard_queue_diag_hook($baseIn + ['sent_thread' => $secretMail]);
$after = $dump($pdo);

qd_ok($before === $after, 'kön, spärrläget, settings och schemat är oförändrade');
qd_ok($mailCalls === 0 && $graphCalls === 0, 'diag skickar inget mejl och anropar inte Graph');
qd_ok(!empty($all['ok']) && !empty($all['read_only']), 'svaret är läsning');
qd_ok(($all['tick_drain']['enabled'] ?? true) === false && ($all['tick_drain']['source'] ?? '') === 'global', 'global flagga av vinner');
qd_ok(($fromSettings['tick_drain']['enabled'] ?? false) === true && ($fromSettings['tick_drain']['source'] ?? '') === 'settings', 'settings är källan när global och env saknas');
qd_ok(($fromEnv['tick_drain']['enabled'] ?? true) === false && ($fromEnv['tick_drain']['source'] ?? '') === 'env', 'env går före settings');
qd_ok(($fromGlobal['tick_drain']['enabled'] ?? false) === true && ($fromGlobal['tick_drain']['source'] ?? '') === 'global', 'global går före env');
$dbTz = is_array($all['db_timezone'] ?? null) ? $all['db_timezone'] : [];
qd_ok(
    array_key_exists('session_time_zone', $dbTz)
    && array_key_exists('now', $dbTz)
    && $dbTz['session_time_zone'] === null
    && $dbTz['now'] === null,
    'sqlite lämnar sessionens tidszon och NOW() tomma'
);
qd_ok(($invalidStatus['ok'] ?? true) === false, 'ogiltig status nekas');

$ids = [];
foreach ((array) ($all['rows'] ?? []) as $row) {
    if (is_array($row)) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
}
sort($ids);
qd_ok($ids === [$waitingId, $sentId], 'fönstret tar med raderna och lämnar raden utanför');

$waitingRows = array_values(array_filter((array) ($waiting['rows'] ?? []), 'is_array'));
qd_ok(count($waitingRows) === 1 && (int) ($waitingRows[0]['id'] ?? 0) === $waitingId, 'status filtrerar till waiting');
$row = $waitingRows[0];
$rowKeys = array_keys($row);
sort($rowKeys);
qd_ok($rowKeys === ['attempts', 'channel', 'created_at', 'created_at_stockholm', 'id', 'last_error', 'status', 'template'], 'raden har inget adressfält');
qd_ok(
    ($row['channel'] ?? '') === 'email'
    && ($row['template'] ?? '') === 'offert'
    && ($row['status'] ?? '') === 'waiting'
    && ($row['created_at'] ?? '') === '2026-06-01 12:00:00'
    && array_key_exists('created_at_stockholm', $row)
    && $row['created_at_stockholm'] === null
    && (int) ($row['attempts'] ?? 0) === 3,
    'rå created_at, tom Stockholm-tid och försök följer raden'
);
qd_ok(mb_strlen((string) ($row['last_error'] ?? '')) === 200 && str_starts_with((string) $row['last_error'], str_repeat('A', 180)), 'last_error kapas till 200 tecken');

$foundKeys = [];
qd_keys($all, $foundKeys);
$banned = ['recipient', 'address', 'body', 'phone', 'pnr', 'to', 'job', 'rcpt', 'email', 'rcpt_hash', 'body_hash'];
$encoded = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
qd_ok(is_string($encoded), 'svaret går att koda');
qd_ok(array_intersect($banned, $foundKeys) === [], 'svaret saknar adress, mottagare, brödtext, telefon och personnummer');
qd_ok(
    !str_contains((string) $encoded, $secretMail)
    && !str_contains((string) $encoded, $secretPhone)
    && !str_contains((string) $encoded, $secretPnr)
    && !str_contains((string) $encoded, $secretStreet)
    && !str_contains((string) $encoded, $secretBody)
    && !str_contains((string) $encoded, '@'),
    'varken jobbets adress eller felets mejl, telefon och personnummer följer med'
);
qd_ok(!in_array($outsideId, $ids, true), 'raden utanför fönstret är inte med');

$userJson = json_encode($withUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$userKeys = [];
qd_keys($withUser, $userKeys);
qd_ok(is_string($userJson) && !empty($withUser['ok']) && !empty($withUser['read_only']), 'user_id-svaret är läsning');
qd_ok(array_intersect($banned, $userKeys) === [], 'user_id-svaret har inga adressfält');
qd_ok(
    !str_contains((string) $userJson, $secretMail)
    && !str_contains((string) $userJson, $secretPhone)
    && !str_contains((string) $userJson, $secretPnr)
    && !str_contains((string) $userJson, '@')
    && !str_contains((string) $userJson, 'elin@'),
    'aktivitet och sent_log saknar mejl, telefon och personnummer'
);
$publicUser = is_array($withUser['user'] ?? null) ? $withUser['user'] : [];
qd_ok(
    ($publicUser['id'] ?? '') === 'SamL'
    && ($publicUser['name'] ?? '') === 'Sam L'
    && ($publicUser['username'] ?? '') === 'sam'
    && count($publicUser) === 3,
    'användaren är id, namn och användarnamn'
);
$activity = array_values(array_filter((array) ($withUser['activity'] ?? []), 'is_array'));
qd_ok(count($activity) === 2, 'fönstret tar med två rader för användaren');
$pages = array_column($activity, 'page');
$actions = array_column($activity, 'action');
qd_ok(in_array('solkampanj', $pages, true) && in_array('POST', $actions, true), 'sidan och anropet följer med');
$caseRow = null;
$reminderRow = null;
foreach ($activity as $act) {
    if (($act['thread_id'] ?? '') === 'case-9') {
        $caseRow = $act;
    }
    if ((int) ($act['reminder_id'] ?? 0) === 42) {
        $reminderRow = $act;
    }
}
qd_ok(is_array($caseRow) && ($caseRow['time'] ?? '') === '2026-06-01 12:21:51' && !str_contains((string) ($caseRow['message'] ?? ''), '@'), 'ärendet och den rensade texten följer med');
qd_ok(is_array($reminderRow) && ($reminderRow['time'] ?? '') === '2026-06-01 13:00:00', 'påminnelsens id följer med');
$sent = is_array($withUser['sent'] ?? null) ? $withUser['sent'] : [];
$sentKeys = array_keys($sent);
sort($sentKeys);
qd_ok(
    $sentKeys === ['actor', 'channel', 'template', 'time']
    && ($sent['time'] ?? '') === '2026-06-01 12:21:51'
    && ($sent['template'] ?? '') === 'batteri'
    && ($sent['actor'] ?? '') === 'Sam L'
    && ($sent['channel'] ?? '') === 'email',
    'sent_thread ger tid, mall, aktör och kanal'
);
qd_ok(($badUser['ok'] ?? true) === false && !str_contains((string) json_encode($badUser), $secretMail), 'ogiltigt user_id ekar ingen adress');
qd_ok(($badThread['ok'] ?? true) === false && !str_contains((string) json_encode($badThread), '@'), 'ogiltig sent_thread ekar ingen adress');

echo "OK  send_guard.queue_diag\n";
