<?php

declare(strict_types=1);

/** send_guard.stats är bara läsning. Inga mottagare i svaret. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-send-guard-stats-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sg_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sg_ok(bool $ok, string $message): void
{
    if (!$ok) {
        sg_fail($message);
    }
    echo "OK  {$message}\n";
}

$base = strtotime('2026-01-15 10:00:00');
sg_ok($base !== false, 'klockan går att tolka');
$peaks = jeab_send_guard_peaks(
    [$base, $base + 20, $base + 40, $base + 90, $base + 3600],
    [$base, $base + 10]
);
sg_ok($peaks['email']['minute']['count'] === 3 && $peaks['email']['minute']['started_at'] === '2026-01-15 10:00:00', 'mejl-toppen per minut');
sg_ok($peaks['email']['five_minutes']['count'] === 4 && $peaks['email']['five_minutes']['started_at'] === '2026-01-15 10:00:00', 'mejl-toppen per fem minuter');
sg_ok($peaks['email']['hour']['count'] === 4, 'femte mejlet ligger utanför timmen');
sg_ok($peaks['email']['day']['count'] === 5 && $peaks['email']['day']['started_at'] === '2026-01-15 10:00:00', 'mejl-toppen per dygn');
sg_ok($peaks['sms']['minute']['count'] === 2 && $peaks['sms']['five_minutes']['count'] === 2, 'sms-toppen är egen');
$tie = jeab_send_guard_peak([$base + 600, $base + 620, $base, $base + 20], 60);
sg_ok($tie['count'] === 2 && $tie['started_at'] === '2026-01-15 10:00:00', 'lika topp tar den tidigaste starten');
$empty = jeab_send_guard_peak([], 60);
sg_ok($empty['count'] === 0 && $empty['started_at'] === null, 'tom serie har ingen start');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 17,
    'auto_send_per_day' => 90,
    'tpl' => jeab_auto_quote_seed(),
]);

$store = jeab_store();
sg_ok($store instanceof JeabPdoStore, 'sqlite är öppen');
jeab_ensure_inbox_sent($store->pdo(), 'sqlite');
$store->pdo()->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind) VALUES (?, ?, ?, ?, ?)')->execute([
    '2026-01-15T10:00:00+01:00', 't-auto', 'auto', 'system', 'offert',
]);
$store->pdo()->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind) VALUES (?, ?, ?, ?, ?)')->execute([
    '2026-01-15T10:00:20+01:00', 't-auto-2', 'auto', 'system', 'offert',
]);
$store->pdo()->prepare('INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind) VALUES (?, ?, ?, ?, ?)')->execute([
    '2026-01-15T11:00:00+01:00', 't-manual', 'manual', 'hook', 'svar',
]);

$secretMail = 'kund.stats@example.test';
$secretPhone = '0701234567';
jeab_sms_store_ensure($store->pdo());
jeab_sms_store_upsert($store->pdo(), 41, (string) json_encode([
    'sms' => true,
    'sms_at' => '2026-01-15 12:00:00',
    'email' => true,
    'email_at' => '2026-01-15 12:05:00',
    'to' => $secretMail,
    'phone' => $secretPhone,
], JSON_UNESCAPED_UNICODE));
jeab_sms_store_mark_imported($store->pdo());

$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
jeab_calendar_log_append('mail', [
    'id' => 41,
    'customer_name' => 'Stats Exempel',
    'email' => $secretMail,
    'phone' => $secretPhone,
    'starts_at' => '2026-01-16 09:00:00',
    'ends_at' => '2026-01-16 10:00:00',
    'job_type' => 'batteri',
], '2026-01-15 12:05:00');
jeab_calendar_log_append('sms', [
    'id' => 41,
    'customer_name' => 'Stats Exempel',
    'email' => $secretMail,
    'phone' => $secretPhone,
    'starts_at' => '2026-01-16 09:00:00',
    'job_type' => 'batteri',
], '2026-01-15 12:00:00');
jeab_calendar_log_append('sms', [
    'id' => 42,
    'customer_name' => 'Annan',
    'starts_at' => '2026-01-16 11:00:00',
    'job_type' => 'sol',
], '2026-01-15 12:00:30');

$beforeSent = (int) $store->pdo()->query('SELECT COUNT(*) FROM inbox_sent')->fetchColumn();
$beforeSms = (string) $store->pdo()->query('SELECT payload FROM sms_sent WHERE booking_id = 41')->fetchColumn();
$settingsBefore = (string) file_get_contents(jeab_inkorg_dir() . '/settings.json');

$stats = jeab_send_guard_stats_hook(['to' => $secretMail, 'phone' => $secretPhone]);
$encoded = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
sg_ok(is_string($encoded), 'svaret går att koda');
sg_ok(($stats['ok'] ?? false) === true && ($stats['read_only'] ?? false) === true, 'hooken svarar ok');
sg_ok((int) ($stats['auto_send_per_hour'] ?? 0) === 17 && (int) ($stats['auto_send_per_day'] ?? 0) === 90, 'live-taken kommer från inställningarna');
sg_ok((int) ($stats['email']['minute']['count'] ?? 0) === 2, 'två automatiska mejl i samma minut');
sg_ok((string) ($stats['email']['minute']['started_at'] ?? '') === '2026-01-15 10:00:00', 'mejlminuten börjar 10:00');
sg_ok((int) ($stats['sms']['minute']['count'] ?? 0) === 2, 'två sms i samma minut, dubbletten räknas en gång');
sg_ok((string) ($stats['sms']['minute']['started_at'] ?? '') === '2026-01-15 12:00:00', 'sms-minuten börjar 12:00');
sg_ok((int) ($stats['email']['hour']['count'] ?? 0) >= 2, 'timtoppen finns');
sg_ok((int) ($stats['email']['day']['count'] ?? 0) >= (int) ($stats['email']['hour']['count'] ?? 0), 'dygnet rymmer timmen');
sg_ok(!str_contains((string) $encoded, '@'), 'svaret har ingen e-post');
sg_ok(!str_contains((string) $encoded, $secretPhone) && !str_contains((string) $encoded, 'Stats Exempel'), 'svaret har varken nummer eller namn');
sg_ok(!str_contains((string) $encoded, 'manual'), 'manuellt utskick ingår inte i topparna');
sg_ok((int) $store->pdo()->query('SELECT COUNT(*) FROM inbox_sent')->fetchColumn() === $beforeSent, 'stats skriver ingen sent-rad');
sg_ok((string) $store->pdo()->query('SELECT payload FROM sms_sent WHERE booking_id = 41')->fetchColumn() === $beforeSms, 'stats skriver inte sms-loggen');
sg_ok((string) file_get_contents(jeab_inkorg_dir() . '/settings.json') === $settingsBefore, 'stats skriver inte inställningar');

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

$httpRoot = sys_get_temp_dir() . '/jeab-send-guard-stats-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_SG_DATA');
$root = getenv('JEAB_SG_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $data . '/calendar-log.json';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
sg_ok($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_SG_DATA=' . escapeshellarg($tmp)
    . ' JEAB_SG_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
sg_ok(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
sg_ok($up, 'testservern lyssnar');

$post = static function (bool $auth) use ($port, $inboxToken, $secretMail, $secretPhone): array {
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['action' => 'send_guard.stats', 'to' => $secretMail]),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true)];
};

$denied = $post(false);
sg_ok($denied['status'] === 401, 'hook.php utan token är 401');
$allowed = $post(true);
sg_ok($allowed['status'] === 200 && ($allowed['json']['ok'] ?? false) === true, 'hook.php med token är 200');
sg_ok((int) ($allowed['json']['auto_send_per_hour'] ?? 0) === 17, 'hook.php lämnar live-taket');
sg_ok(!str_contains($allowed['body'], '@') && !str_contains($allowed['body'], $secretPhone) && !str_contains($allowed['body'], $inboxToken), 'hook.php läcker varken adress, nummer eller token');

echo "OK  send_guard.stats\n";
