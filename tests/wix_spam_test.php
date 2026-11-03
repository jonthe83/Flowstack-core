<?php

declare(strict_types=1);

/**
 * wix.spam_check läser spamrapporter via mock. Ingen nyckel skrivs ut.
 * Tick flaggar bara icke-testdomän, som intern anteckning, utan kundmejl.
 */

$tmp = sys_get_temp_dir() . '/jeab-wix-spam-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/public', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function ws_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ws_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    ws_fail($message);
}

$secret = 'wix-key-should-not-leak-' . bin2hex(random_bytes(4));
$sites = jeab_wix_spam_sites();
$jarfalla = '';
$malmo = '';
foreach ($sites as $id => $company) {
    if ($company === 'jarfalla' && $jarfalla === '') {
        $jarfalla = (string) $id;
    }
    if ($company === 'malmo' && $malmo === '') {
        $malmo = (string) $id;
    }
}
ws_ok(str_starts_with($jarfalla, 'b1dfa86d') && str_starts_with($malmo, '5a7c71c4'), 'sajterna är Järfälla och Malmö från befintlig konfig');

$siteFile = $tmp . '/wix-sites.json';
file_put_contents($siteFile, json_encode([
    'api_key' => $secret,
    'sites' => [$jarfalla => 'jarfalla', $malmo => 'malmo'],
], JSON_UNESCAPED_SLASHES));
$GLOBALS['_JEAB_WIX_SPAM_SITE_FILE'] = $siteFile;
$loaded = jeab_wix_spam_sites();
ws_ok($loaded === [$jarfalla => 'jarfalla', $malmo => 'malmo'], 'sajtlistan läses från privat fil och tar inte med nyckeln');
ws_ok(!str_contains(json_encode($loaded), $secret), 'sajtlistan innehåller inte nyckeln');

$now = 1761100000;
$GLOBALS['_JEAB_WIX_SPAM_NOW'] = $now;
$recent = gmdate('c', $now - 600);
$mid = gmdate('c', $now - 3 * 3600);
$old = gmdate('c', $now - 60 * 3600);
$calls = [];
$timeouts = [];
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $siteId, string $method, string $path, ?array $body) use (&$calls, &$timeouts, $jarfalla, $malmo, $recent, $mid, $old): array {
    $calls[] = ['site' => $siteId, 'method' => $method, 'path' => $path, 'body' => $body];
    $timeouts[] = $GLOBALS['_JEAB_WIX_TIMEOUT'] ?? null;
    if ($path === JEAB_WIX_SPAM_FORMS) {
        $form = $siteId === $malmo ? 'malmo-form' : 'jarfalla-form';
        $name = $siteId === $malmo ? 'Kontakt Malmö' : 'Batteri lead';
        return ['forms' => [['id' => $form, 'name' => $name]], 'metadata' => ['hasNext' => false]];
    }
    if ($path !== JEAB_WIX_SPAM_REPORTS) {
        throw new RuntimeException('oväntad sökväg');
    }
    if ($siteId === $malmo) {
        return ['formSpamSubmissionReports' => [[
            'id' => 'rep-malmo-test',
            'formId' => 'malmo-form',
            'reportedDate' => $recent,
            'reportReason' => 'EMAIL_BLOCKLISTED',
            'submissions' => ['email_9' => 'testare@test.se'],
        ]], 'pagingMetadata' => ['hasNext' => false]];
    }
    return ['formSpamSubmissionReports' => [
        [
            'id' => 'rep-gmail',
            'formId' => 'jarfalla-form',
            'reportedDate' => $recent,
            'reportReason' => 'AI_REPORTED',
            'reportReasonDescription' => 'uniklokal123@gmail.com ser ut som spam',
            'submissions' => ['email_1' => ['stringValue' => 'uniklokal123@gmail.com'], 'message' => 'Hej'],
        ],
        [
            'id' => 'rep-test-local',
            'formId' => 'jarfalla-form',
            'reportedDate' => $mid,
            'reportReason' => 'MANUALLY_REPORTED',
            'submissions' => ['e-post' => 'test@example.invalid'],
        ],
        [
            'id' => 'rep-example',
            'formId' => 'jarfalla-form',
            'reportedDate' => $recent,
            'reportReason' => 'EMAIL_QUOTA_REACHED',
            'submissions' => ['email' => 'ada@example.com'],
        ],
        [
            'id' => 'rep-old',
            'formId' => 'jarfalla-form',
            'reportedDate' => $old,
            'reportReason' => 'IP_BLOCKLISTED',
            'submissions' => ['email' => 'gammal@gmail.com'],
        ],
    ], 'pagingMetadata' => ['hasNext' => false]];
};

$list = jeab_wix_spam_dispatch(['since_hours' => 48]);
$text = (string) ($list['text'] ?? '');
ws_ok(($list['status'] ?? 0) === 200, 'listan svarar 200');
ws_ok(str_contains($text, "Järfälla\n") && str_contains($text, "Malmö\n"), 'båda sajterna är med');
ws_ok(str_contains($text, 'Batteri lead | ') && str_contains($text, 'AI_REPORTED') && str_contains($text, 'u***@gmail.com'), 'formulär, tid, orsak och maskad domän');
ws_ok(str_contains($text, 't***@gmail.com') && str_contains($text, 'a***@example.com') && str_contains($text, 't***@test.se'), 'testdomän och gmail visas maskade');
ws_ok(!str_contains($text, 'uniklokal123') && !str_contains($text, 'gammal@gmail.com') && !str_contains($text, 'g***@gmail.com') && !str_contains($text, 'rep-old'), 'hel adress och rapport utanför fönstret syns inte');
ws_ok(!str_contains($text, $secret) && !str_contains($text, $jarfalla) && !str_contains($text, $malmo), 'varken nyckel eller sajt-id skrivs ut');
ws_ok(!str_contains($text, '{') && !str_contains($text, '['), 'svaret är text, inte JSON');

$methods = array_unique(array_column($calls, 'method'));
$paths = array_unique(array_column($calls, 'path'));
ws_ok($methods === ['POST'], 'bara POST');
ws_ok($paths === [JEAB_WIX_SPAM_FORMS, JEAB_WIX_SPAM_REPORTS] || $paths === [JEAB_WIX_SPAM_REPORTS, JEAB_WIX_SPAM_FORMS], 'bara query för formulär och spamrapporter');
$spamBody = null;
foreach ($calls as $call) {
    if ($call['path'] === JEAB_WIX_SPAM_REPORTS && $call['site'] === $jarfalla) {
        $spamBody = $call['body'];
    }
}
$filter = is_array($spamBody) ? ($spamBody['query']['filter'] ?? null) : null;
ws_ok(
    is_array($filter)
    && ($filter['namespace']['$eq'] ?? '') === 'wix.form_app.form'
    && ($filter['formId']['$eq'] ?? '') === 'jarfalla-form'
    && ($spamBody['query']['sort'][0]['fieldName'] ?? '') === 'reportedDate',
    'spamfrågan filtrerar namespace och formId och sorterar på reportedDate'
);

$only = jeab_wix_spam_dispatch(['site' => 'Malmö', 'since_hours' => '1']);
$onlyText = (string) ($only['text'] ?? '');
ws_ok(str_contains($onlyText, 'Malmö') && !str_contains($onlyText, 'Järfälla') && str_contains($onlyText, 't***@test.se'), 'site Malmö och since_hours 1 tar bara den färska Malmö-raden');
$narrow = jeab_wix_spam_dispatch(['site' => 'jarfalla', 'since_hours' => 1]);
$narrowText = (string) ($narrow['text'] ?? '');
ws_ok(str_contains($narrowText, 'u***@gmail.com') && str_contains($narrowText, 'a***@example.com') && !str_contains($narrowText, 't***@gmail.com'), 'since_hours 1 släpper tre timmar gammal rapport');
ws_ok((jeab_wix_spam_dispatch(['since_hours' => 0])['status'] ?? 0) === 400, 'since_hours 0 avvisas');
ws_ok((jeab_wix_spam_dispatch(['site' => $secret])['text'] ?? '') === "Okänd sajt.\n", 'okänd sajt ekar inte nyckeln');
ws_ok($timeouts !== [] && !in_array(true, array_map(static fn ($v): bool => $v !== null, $timeouts), true), 'hooken lämnar Wix-timeouten orörd');

$socket = '/tmp/jeab-wix-mysql/mysql.sock';
if (!file_exists($socket)) {
    ws_fail('MariaDB-socket saknas');
}
$admin = new PDO('mysql:unix_socket=' . $socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbName = 'jeab_ws_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $dbName, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['_JEAB_WIX_SPAM_PDO'] = $pdo;
$callsBeforeTick = count($calls);
$sent = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$sent): array {
    $sent++;
    return ['ok' => false];
};
$first = jeab_wix_spam_tick();
ws_ok(($first['count'] ?? 0) === 2 && ($first['ok'] ?? false) === true, 'tick flaggar gmail, även när lokaldelen är test');
$notes = [];
foreach (jeab_inkorg_threads() as $thread) {
    foreach ((array) ($thread['drafts'] ?? []) as $draft) {
        if (is_array($draft) && ($draft['kind'] ?? '') === 'note') {
            $notes[] = (string) ($draft['body'] ?? '');
        }
    }
    foreach ((array) ($thread['messages'] ?? []) as $message) {
        if (is_array($message)) {
            ws_ok(!empty($message['internal_note']) && (string) ($message['source'] ?? '') === 'wix-spam', 'tråden är en intern notis');
            ws_ok((string) ($message['from'] ?? '') === 'kontakt@jarfalla.example.test' || (string) ($message['from'] ?? '') === 'kontakt@malmo.example.test', 'avsändaren är den egna brevlådan');
        }
    }
}
$blob = implode("\n", $notes);
ws_ok(count($notes) === 2 && str_contains($blob, 'u***@gmail.com') && str_contains($blob, 't***@gmail.com'), 'anteckningarna har maskad domän');
ws_ok(!str_contains($blob, 'test.se') && !str_contains($blob, 'example.com') && !str_contains($blob, 'uniklokal123') && !str_contains($blob, $secret), 'testdomän, hel adress och nyckel blir ingen notis');
ws_ok($sent === 0, 'inget kundmejl');

$again = jeab_wix_spam_tick();
ws_ok(($again['skipped'] ?? '') === 'vantar' && count($notes) === 2, 'inom 30 minuter blir det ingen ny notis');
$GLOBALS['_JEAB_WIX_SPAM_NOW'] = $now + 1801;
$same = jeab_wix_spam_tick();
$notesAfter = 0;
foreach (jeab_inkorg_threads() as $thread) {
    foreach ((array) ($thread['drafts'] ?? []) as $draft) {
        if (is_array($draft) && ($draft['kind'] ?? '') === 'note') {
            $notesAfter++;
        }
    }
}
ws_ok(($same['count'] ?? -1) === 0 && $notesAfter === 2, 'samma rapporter flaggas inte igen');
ws_ok(jeab_settings_value($pdo, 'mysql', 'wix_spam_table') === '1', 'settings-flaggan wix_spam_table är 1');
$seen = $pdo->query('SELECT report_id FROM wix_spam_seen ORDER BY report_id')->fetchAll(PDO::FETCH_COLUMN);
ws_ok($seen === ['rep-gmail', 'rep-test-local'], 'sedda rapport-id ligger i MySQL');
$spamSrc = (string) file_get_contents(dirname(__DIR__) . '/src/wix_spam.php');
ws_ok(!str_contains($spamSrc, 'sqlite') && !str_contains($spamSrc, 'wix-spam.sqlite'), 'spamläget öppnar ingen SQLite-fil');
$tickTimeouts = array_slice($timeouts, $callsBeforeTick);
ws_ok($tickTimeouts !== [] && array_reduce($tickTimeouts, static fn (bool $ok, mixed $v): bool => $ok && is_int($v) && $v >= 1 && $v <= 10, true), 'tickens Wix-anrop har timeout 1–10 sekunder');

$callsAtDeadline = count($calls);
$GLOBALS['_JEAB_WIX_SPAM_DEADLINE'] = microtime(true) - 5;
$GLOBALS['_JEAB_WIX_SPAM_NOW'] = $now + 1801 + 1801;
$late = jeab_wix_spam_tick();
ws_ok(($late['error'] ?? '') === 'fel' && count($calls) === $callsAtDeadline, 'en passerad tidsgräns gör inget Wix-anrop');
unset($GLOBALS['_JEAB_WIX_SPAM_DEADLINE']);

$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $siteId, string $method, string $path, ?array $body): array {
    unset($siteId, $method, $path, $body);
    throw new RuntimeException('wix-ner hemlig-nyckel');
};
$GLOBALS['_JEAB_WIX_SPAM_NOW'] = $now + 1801 + 1801 + 1801;
$boom = jeab_wix_spam_tick();
$boomJson = (string) json_encode($boom);
ws_ok(($boom['ok'] ?? true) === false && ($boom['error'] ?? '') === 'fel' && !str_contains($boomJson, 'hemlig') && !str_contains($boomJson, 'wix-ner'), 'ett Wix-fel stannar i tick och läcker inte');

$flagDb = 'jeab_ws_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $flagDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$flagPdo = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $flagDb, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$flagPdo->exec(
    'CREATE TABLE settings (
        `key` VARCHAR(64) NOT NULL,
        value TEXT NOT NULL,
        PRIMARY KEY (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
jeab_settings_put($flagPdo, 'mysql', 'wix_spam_table', '1');
$GLOBALS['_JEAB_WIX_SPAM_PDO'] = $flagPdo;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $siteId, string $method, string $path, ?array $body) use ($jarfalla, $malmo, $recent): array {
    unset($body);
    if ($path === JEAB_WIX_SPAM_FORMS) {
        $form = $siteId === $malmo ? 'malmo-form' : 'jarfalla-form';
        return ['forms' => [['id' => $form, 'name' => 'Namn']], 'metadata' => ['hasNext' => false]];
    }
    return ['formSpamSubmissionReports' => [[
        'id' => 'rep-flag',
        'formId' => $siteId === $malmo ? 'malmo-form' : 'jarfalla-form',
        'reportedDate' => $recent,
        'reportReason' => 'AI_REPORTED',
        'submissions' => ['email' => 'ny@gmail.com'],
    ]], 'pagingMetadata' => ['hasNext' => false]];
};
$flagged = jeab_wix_spam_tick();
$flagTables = $flagPdo->query("SHOW TABLES LIKE 'wix_spam_seen'")->fetchAll();
ws_ok(($flagged['count'] ?? -1) === 0 && $flagTables === [], 'flaggan wix_spam_table hoppar över CREATE');
$admin->exec('DROP DATABASE `' . $dbName . '`');
$admin->exec('DROP DATABASE `' . $flagDb . '`');
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $siteId, string $method, string $path, ?array $body) use ($jarfalla, $malmo, $recent, $mid, $old): array {
    unset($siteId, $method, $path, $body, $jarfalla, $malmo, $recent, $mid, $old);
    return ['forms' => [], 'formSpamSubmissionReports' => [], 'pagingMetadata' => ['hasNext' => false]];
};

$token = 'inkorg-' . bin2hex(random_bytes(8));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $token]));
$log = $tmp . '/calls.log';
$wrapper = $tmp . '/run-hook.php';
file_put_contents($wrapper, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = getenv('D');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('D') . '/public';
$GLOBALS['_JEAB_RAW_BODY'] = getenv('BODY');
$GLOBALS['_JEAB_WIX_SPAM_SITE_FILE'] = getenv('SITES');
$GLOBALS['_JEAB_WIX_SPAM_NOW'] = (int) getenv('NOW');
$log = getenv('LOG');
$jarfalla = getenv('JARFALLA');
$malmo = getenv('MALMO');
$recent = getenv('RECENT');
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $siteId, string $method, string $path, ?array $body) use ($log, $jarfalla, $malmo, $recent): array {
    file_put_contents((string) $log, $method . ' ' . $path . "\n", FILE_APPEND);
    if ($method !== 'POST') {
        throw new RuntimeException('skrivning');
    }
    if ($path === '/form-schema-service/v4/forms/query') {
        return ['forms' => [['id' => 'f', 'properties' => ['name' => 'Sol lead']]], 'metadata' => ['hasNext' => false]];
    }
    return ['formSpamSubmissionReports' => [[
        'id' => 'rep-hook',
        'reportedDate' => (string) $recent,
        'reportReason' => 'MODERATION_SPI',
        'submissions' => ['email_a' => 'kund.hook@hotmail.com'],
    ]], 'pagingMetadata' => ['hasNext' => false]];
};
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . getenv('TOK');
require getenv('ROOT') . '/hook.php';
PHP
);
$run = static function (string $body, string $auth) use ($wrapper, $tmp, $siteFile, $now, $log, $jarfalla, $malmo, $recent, $token): array {
    if (is_file($log)) {
        unlink($log);
    }
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = [
        'D' => $tmp,
        'BODY' => $body,
        'SITES' => $siteFile,
        'NOW' => (string) $now,
        'LOG' => $log,
        'JARFALLA' => $jarfalla,
        'MALMO' => $malmo,
        'RECENT' => $recent,
        'TOK' => $auth,
        'ROOT' => dirname(__DIR__),
    ];
    $proc = proc_open([PHP_BINARY, $wrapper], $spec, $pipes, dirname(__DIR__), $env);
    if (!is_resource($proc)) {
        ws_fail('hook-processen startade inte');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['out' => (string) $out, 'err' => (string) $err, 'code' => $code];
};
$denied = $run('{"action":"wix.spam_check"}', 'fel');
ws_ok(str_contains($denied['out'], 'Fel token') && !str_contains($denied['out'], $secret), 'hook utan token nekas och läcker inte nyckeln');
$ok = $run('{"action":"wix.spam_check","site":"b1dfa86d","since_hours":48}', $token);
ws_ok($ok['code'] === 0 && str_contains($ok['out'], 'Sol lead') && str_contains($ok['out'], 'k***@hotmail.com') && str_contains($ok['out'], 'MODERATION_SPI'), 'hook.php listar formulär, orsak och maskad domän');
ws_ok(!str_contains($ok['out'], 'kund.hook') && !str_contains($ok['out'], $secret) && !str_contains($ok['out'], $jarfalla), 'hook.php skriver inte ut adress, nyckel eller sajt-id');
$logged = is_file($log) ? (string) file_get_contents($log) : '';
ws_ok(str_contains($logged, 'POST /form-schema-service/v4/forms/query') && str_contains($logged, 'POST /form-spam-submission-report-service/v4/form-spam-submission-reports/namespace/query') && !str_contains($logged, 'DELETE') && !str_contains($logged, 'PUT'), 'hooken gör bara query');
ws_ok(!is_file($tmp . '/wix-spam.json') && !is_file($tmp . '/secrets/wix-spam.json'), 'ingen JSON-fil för spamläget');

echo "ALLA OK\n";
