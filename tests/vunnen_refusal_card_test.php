<?php

declare(strict_types=1);

/**
 * #vunnen utan kontakt: meningen syns på kortet,
 * vägran skrivs inte i felloggen, och #admin markerar inte klar.
 * Inget utskick.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-vunnen-refusal-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$fellogg = $tmp . '/fellogg';
mkdir($fellogg, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $fellogg;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'vunnen-refusal-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require $root . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function vref(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$gap = new InvalidArgumentException('Ordern skapades inte. Saknar produkt och kontakt.');
vref(jeab_log_skip_expected_refusal($gap), 'vägran är förväntad');
vref(!jeab_log_skip_expected_refusal(new InvalidArgumentException('Ogiltig tagg.')), 'ogiltig tagg loggas');
vref(!jeab_log_skip_expected_refusal(new RuntimeException('Ordern skapades inte. Saknar produkt och kontakt.')), 'oväntat fel loggas');

$index = (string) file_get_contents($root . '/index.php');
$catchAt = strpos($index, 'jeab_log_skip_expected_refusal');
$logAt = strpos($index, 'jeab_log_app_error(jeab_log_surface_from_action($action), $error)');
vref($catchAt !== false && $logAt !== false && $catchAt < $logAt, 'felloggen hoppar över vägran');

$js = (string) file_get_contents($root . '/assets/js/app.js');
$tagAt = strpos($js, 'function bindTagForm');
$tag = $tagAt === false ? '' : substr($js, $tagAt, 500);
$mouseAt = strpos($tag, "addEventListener('mousedown'");
$mouseEnd = $mouseAt === false ? false : strpos($tag, '});', $mouseAt);
$mouse = ($mouseAt !== false && $mouseEnd !== false) ? substr($tag, $mouseAt, $mouseEnd - $mouseAt) : '';
vref($mouse !== '' && !str_contains($mouse, 'preventDefault'), '#admin flyttar inte klicket till bocken');
$doneAt = strpos($js, "if (donePlan !== 'done')");
$markAt = strpos($js, 'markReminderDone(form);');
vref($doneAt !== false && $markAt !== false && $doneAt < $markAt, 'taggträff markerar inte klar');

$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
vref(jeab_auth_ok(), 'testanvändaren är inloggad');
if (function_exists('jeab_ordrar_external_live')) {
    jeab_ordrar_external_live(false);
}

$store = jeab_store();
$now = '2026-10-30 12:00:00';
$row = $store->insertReminder([
    'title' => 'Hej',
    'company' => 'jarfalla',
    'address' => 'Testgatan 4',
    'tags' => [],
    'created_at' => $now,
    'updated_at' => $now,
]);
$id = (int) ($row['id'] ?? 0);
vref($id > 0, 'testpåminnelsen finns');
$open = jeab_reminder_row($store->getReminder($id), true);
vref(str_contains($open, 'role="alert"') && str_contains($open, 'Ordern skapades inte. Saknar kontakt.'), 'kortet visar vägran');
$checkEnd = strpos($open, '</form>');
$adminAt = strpos($open, 'value="admin"');
vref($checkEnd !== false && $adminAt !== false && $checkEnd < $adminAt, '#admin ligger utanför bockformuläret');
vref(str_contains($open, 'name="tag" value="admin"') && str_contains($open, 'form="'), '#admin skickar sin egen form');

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_DATA');
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = getenv('JEAB_DATA');
$GLOBALS['_JEAB_FELLOGG_DIR'] = getenv('JEAB_FELLOGG');
session_name('jeab');
session_start();
$_SESSION['jeab_user'] = 'Elin';
$_SESSION['jeab_user_via'] = 'choice';
$_SESSION['jeab_code_person'] = 'Elin';
require getenv('JEAB_ROOT') . '/index.php';
PHP);
$port = 0;
for ($try = 8411; $try < 8430; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
vref($port > 0, 'en lokal port är ledig');
$serverLog = $tmp . '/server.log';
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    null,
    [
        'JEAB_DATA' => $tmp,
        'JEAB_FELLOGG' => $fellogg,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '0',
        'XAI_API_KEY' => '',
    ]
);
vref(is_resource($server), 'php-servern startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});

$cookie = 'jeab_auth=' . (string) $_COOKIE['jeab_auth'];
$csrf = (string) jeab_csrf_token();
$post = static function (array $fields) use ($port, $cookie, $csrf): array {
    $fields['_csrf'] = $csrf;
    $ch = curl_init('http://127.0.0.1:' . $port . '/index.php?p=reminders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
            'Cookie: ' . $cookie,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);

    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
};

$ready = false;
for ($i = 0; $i < 30; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if (is_string($probe) && str_contains($probe, 'csrf')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
vref($ready, 'servern svarar');

$denied = $post(['action' => 'reminder.tag', 'id' => $id, 'tag' => 'vunnen']);
$afterDeny = $store->getReminder($id);
$denyError = (string) ($denied['json']['error'] ?? '');
vref(
    $denied['code'] === 422 && $denyError === 'Ordern skapades inte. Saknar kontakt.',
    'servern svarar med vägran på kortets mening, fick ' . $denied['code'] . ' ' . substr($denied['body'], 0, 220)
);
vref(is_array($afterDeny) && empty($afterDeny['completed_at']) && !in_array('vunnen', jeab_parse_tags($afterDeny['tags'] ?? []), true), 'vägran sätter varken vunnen eller klar');
$logPath = $fellogg . '/app-errors.jsonl';
$log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
vref(!str_contains($log, 'Ordern skapades inte'), 'vägran skrivs inte i felloggen');

$bad = $post(['action' => 'reminder.tag', 'id' => $id, 'tag' => 'finns-inte']);
vref($bad['code'] >= 400, 'ogiltig tagg svarar fel, fick ' . $bad['code']);
$log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
vref(str_contains($log, 'Ogiltig tagg'), 'ett riktigt fel skrivs i felloggen');
vref(!str_contains($log, 'Ordern skapades inte'), 'vägran ligger fortfarande inte i felloggen');

$admin = $post(['action' => 'reminder.tag', 'id' => $id, 'tag' => 'admin']);
$afterAdmin = $store->getReminder($id);
vref(
    $admin['code'] === 200
        && is_array($afterAdmin)
        && in_array('admin', jeab_parse_tags($afterAdmin['tags'] ?? []), true)
        && empty($afterAdmin['completed_at']),
    '#admin sparar taggen och lämnar påminnelsen öppen, fick ' . $admin['code'] . ' ' . substr($admin['body'], 0, 180)
);

echo "ALLA OK\n";
