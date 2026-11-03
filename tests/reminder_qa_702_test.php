<?php

declare(strict_types=1);

require_once __DIR__ . '/jeab_test_proc_env.php';

/**
 * QA efter #702: svep på 100 px, stäng öppet kort, Ringda-toast, Väntar i sök.
 * Påhittad data. Inget kundutskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-qa702-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_QA_DATA=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function qa(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
$future = $now->modify('+4 hours')->format('Y-m-d H:i:s');
$stamp = $now->format('Y-m-d H:i:s');
$doneAt = $now->modify('-1 hour')->format('Y-m-d H:i:s');

$rows = [];
$rows[] = [
    'id' => 1,
    'title' => 'Sok702 Vägen 1',
    'customer_name' => 'Sok702 Kund',
    'company' => 'jarfalla',
    'due_at' => $future,
    'completed_at' => null,
    'tags' => [],
];
for ($i = 2; $i <= 5; $i++) {
    $rows[] = [
        'id' => $i,
        'title' => 'Sok702 Vägen ' . $i,
        'customer_name' => 'Sok702 Kund',
        'company' => 'jarfalla',
        'due_at' => $future,
        'completed_at' => $doneAt,
        'tags' => [],
    ];
}
for ($i = 6; $i <= 8; $i++) {
    $rows[] = [
        'id' => $i,
        'title' => 'Annan väg ' . $i,
        'customer_name' => 'Annan Person',
        'company' => 'jarfalla',
        'due_at' => $future,
        'completed_at' => null,
        'tags' => [],
    ];
}

$tagAll = ['mode' => 'all', 'tag' => null];
$waiting = jeab_filter_reminders($rows, 'waiting', $tagAll, 'sok702');
$counts = jeab_reminder_counts($rows, $tagAll, 'sok702');
qa(count($waiting) === 1, 'sökens Väntar-lista är ett kort');
qa($counts['waiting'] === 1, 'sökens Väntar-siffra är 1, fick ' . (int) $counts['waiting']);
qa($counts['waiting'] === count($waiting), 'Väntar-siffran följer samma filter som listan');
qa($counts['done'] === 4, 'klara i sökningen räknas som klara, fick ' . (int) $counts['done']);
qa($counts['waiting'] !== 5, 'Väntar tar inte med de fyra klara');

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/app.js');
$opt = (string) file_get_contents($root . '/assets/js/optimistic.js');
qa(str_contains($opt, 'function jeabReminderGesture('), 'gesten finns för mus och finger');
qa(str_contains($opt, 'dist < 24'), 'tryck är bara under 24 px');
qa(str_contains($opt, 'Math.abs(x) >= 80') && strpos($opt, 'Math.abs(x) >= 80') < strpos($opt, 'dist < 24'), 'sveptröskeln vinner över tryck');
$downAt = strpos($js, 'function swipeRowFrom(');
$down = $downAt === false ? '' : substr($js, $downAt, 1600);
qa(str_contains($down, "pointerType === 'mouse'") && !str_contains($down, "pointerType !== 'touch'"), 'svepet lyssnar på mus och finger');
qa(str_contains($js, "event.key !== 'Escape'") && str_contains($js, 'closeOpenReminder()'), 'Escape stänger öppet kort');
qa(str_contains($js, '.item.is-open .item-head-open'), 'ett tryck till på huvudet stänger kortet');
qa(str_contains($js, 'const TAP_UNDO_MS = 6000'), 'Ringda ligger kvar minst 6 s');
qa(str_contains($js, 'function pauseReminderBar(') && str_contains($js, 'mouseenter') && str_contains($js, 'touchstart'), 'toasten pausar vid hovring och finger');

$node = trim((string) shell_exec('command -v node'));
qa($node !== '', 'node finns');
$script = <<<'JS'
const m = require(process.argv[2]);
const g = m.jeabReminderGesture;
const cases = [
  ['mouse', 0, 0, 'pointerup', 'open'],
  ['touch', 23, 0, 'pointerup', 'open'],
  ['mouse', 23, 0, 'pointerup', 'open'],
  ['touch', 24, 0, 'pointerup', 'none'],
  ['mouse', 24, 0, 'pointerup', 'none'],
  ['touch', 100, 0, 'pointerup', 'done'],
  ['mouse', 100, 0, 'pointerup', 'done'],
  ['touch', -100, 0, 'pointerup', 'snooze'],
  ['mouse', -100, 4, 'pointerup', 'snooze'],
  ['touch', 0, 100, 'pointerup', 'none'],
  ['mouse', 10, 100, 'pointerup', 'none'],
  ['touch', 100, 0, 'pointercancel', 'none'],
  ['mouse', 100, 0, 'pointerdown', 'none']
];
for (const row of cases) {
  const got = g(row[0], row[1], row[2], row[3]);
  if (got !== row[4]) {
    console.error(JSON.stringify(row) + ' -> ' + got);
    process.exit(1);
  }
}
console.log('gesture ok');
JS;
$nodeFile = $tmp . '/gesture.js';
file_put_contents($nodeFile, $script);
$ran = shell_exec($node . ' ' . escapeshellarg($nodeFile) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js') . ' 2>&1');
qa(is_string($ran) && str_contains($ran, 'gesture ok'), 'mus och finger: 100 px svep vinner, tryck under 24 px, fick ' . trim((string) $ran));

$store = jeab_store();
$make = static function (string $name, ?string $completed) use ($store, $future, $stamp): void {
    $store->insertReminder([
        'title' => $name,
        'customer_name' => $name,
        'company' => 'jarfalla',
        'due_at' => $future,
        'completed_at' => $completed,
        'created_at' => $stamp,
        'updated_at' => $stamp,
    ]);
};
$make('Sok702 Kund', null);
for ($i = 0; $i < 4; $i++) {
    $make('Sok702 Kund', $doneAt);
}
for ($i = 0; $i < 3; $i++) {
    $make('Annan Person', null);
}

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'qa702-secret',
    'hashes' => [
        ['hash' => 'qa702-hash', 'email' => 'qa702@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('qa702@example.test', 'qa702-hash');
qa(jeab_auth_ok(), 'curl-testaren är inloggad');

$router = $tmp . '/router.php';
$workspace = dirname(__DIR__);
file_put_contents($router, "<?php\n" . jeab_test_sqlite_boot() . <<<PHP
\$tmp = getenv('JEAB_QA_DATA');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/passwords.json');
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$workspace}/index.php';
    return true;
}
return false;
PHP);
$port = 0;
for ($try = 8951; $try < 8970; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
qa($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $workspace, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $workspace,
    jeab_test_proc_env([
        'JEAB_TEST_TEMP_SQLITE' => '1',
        'JEAB_QA_DATA' => $tmp,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '1',
    ])
);
qa(is_resource($server), 'php-servern startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$deadline = microtime(true) + 5;
$ready = false;
do {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if (is_string($probe) && $probe !== '') {
        $ready = true;
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);
qa($ready, 'servern svarar');

$qs = 'p=reminders&v=today&tag=all&q=' . rawurlencode('Sok702') . '&co=jarfalla&who=all&ws=' . time();
$ch = curl_init('http://127.0.0.1:' . $port . '/index.php?' . $qs);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Accept: text/html',
        'Cookie: jeab_auth=' . (string) $_COOKIE['jeab_auth'],
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$html = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
qa($code === 200 && $html !== '', 'curl sök svarar, fick ' . $code);
qa(preg_match('/class="smart-tile is-waiting[^"]*"[\s\S]*?<b>(\d+)<\/b>/', $html, $match) === 1, 'curl hittar Väntar-siffran');
qa((int) ($match[1] ?? -1) === 1, 'curl Väntar i sök är 1 och inte de klara, fick ' . ($match[1] ?? ''));

echo "ALLA OK\n";
