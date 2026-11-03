<?php

declare(strict_types=1);

/**
 * CSRF «Ogiltig session» i felloggen får url, method, hashad aktör och reason.
 * Avvisningen är oförändrad: ogiltig token ger 419, giltig token loggas inte,
 * och inget rensar inloggningen.
 */

function csrf_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function csrf_child(string $case): void
{
    $tmp = (string) getenv('JEAB_CSRF_TEST_TMP');
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
    putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
    $_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $GLOBALS['_JEAB_EINSTEIN_SECRET'] = [
        'url' => 'https://example.com/fellogg',
        'key' => 'not-sent',
    ];

    require dirname(__DIR__) . '/src/bootstrap.php';

    session_save_path($tmp . '/sess');
    session_name('jeab');
    session_start();

    $email = 'sam@example.com';
    $loggedIn = in_array($case, ['accept', 'accept-fresh', 'mismatch', 'fresh', 'other'], true);
    if ($loggedIn) {
        $_SESSION['jeab_email'] = $email;
        jeab_auth_set_cookie($email);
        $_COOKIE['jeab'] = session_id();
    }
    $GLOBALS['_JEAB_SESSION_FRESH'] = in_array($case, ['accept-fresh', 'fresh'], true);

    $bad = str_repeat('ab', 32);
    $good = jeab_csrf_token();
    if (hash_equals($good, $bad)) {
        $bad = str_repeat('cd', 32);
    }
    $token = str_starts_with($case, 'accept') ? $good : ($case === 'other' ? '' : $bad);

    $paths = [
        'accept' => '/index.php?p=reminders',
        'accept-fresh' => '/index.php?p=bookings',
        'mismatch' => '/index.php?p=reminders&token=super-secret-token&_csrf=' . $bad,
        'fresh' => '/index.php?p=bokningar&token=super-secret-token',
        'expired' => '/index.php?p=notes&token=super-secret-token',
        'other' => '/index.php?p=purchase&token=super-secret-token',
    ];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $paths[$case] ?? '/index.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['CONTENT_TYPE']);
    $_POST = [
        'action' => 'csrf-test-' . $case,
    ];
    if ($token !== '') {
        $_POST['_csrf'] = $token;
    }
    if ($case === 'mismatch') {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $bad;
    }

    $sid = session_id();
    $cookie = (string) ($_COOKIE['jeab_auth'] ?? '');
    $seenEmail = (string) ($_SESSION['jeab_email'] ?? '');
    $resultFile = $tmp . '/result-' . $case . '.json';
    $csrfOk = jeab_csrf_ok();

    register_shutdown_function(static function () use ($resultFile, $case, $token, $sid, $cookie, $seenEmail, $csrfOk): void {
        if (is_file($resultFile)) {
            return;
        }
        $webhooks = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
        file_put_contents($resultFile, json_encode([
            'case' => $case,
            'csrf_ok' => $csrfOk,
            'code' => http_response_code(),
            'auth_ok' => function_exists('jeab_auth_ok') && jeab_auth_ok(),
            'cookie_same' => ((string) ($_COOKIE['jeab_auth'] ?? '')) === $cookie,
            'sid_same' => session_id() === $sid,
            'email_same' => ((string) ($_SESSION['jeab_email'] ?? '')) === $seenEmail,
            'webhooks' => is_array($webhooks) ? count($webhooks) : -1,
            'token' => $token,
            'email' => $seenEmail,
            'sid' => $sid,
            'returned' => false,
        ], JSON_UNESCAPED_SLASHES));
    });

    jeab_require_csrf();

    file_put_contents($resultFile, json_encode([
        'case' => $case,
        'csrf_ok' => $csrfOk,
        'code' => http_response_code() ?: 200,
        'auth_ok' => jeab_auth_ok(),
        'cookie_same' => ((string) ($_COOKIE['jeab_auth'] ?? '')) === $cookie,
        'sid_same' => session_id() === $sid,
        'email_same' => ((string) ($_SESSION['jeab_email'] ?? '')) === $seenEmail,
        'webhooks' => 0,
        'token' => $token,
        'email' => $email,
        'sid' => $sid,
        'returned' => true,
    ], JSON_UNESCAPED_SLASHES));
}

/** @return array<string, mixed> */
function csrf_spawn(string $case): array
{
    $tmp = (string) getenv('JEAB_CSRF_TEST_TMP');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($case) . ' 2>&1';
    $stdout = shell_exec($cmd);
    $path = $tmp . '/result-' . $case . '.json';
    $raw = is_file($path) ? (string) file_get_contents($path) : '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "FAIL barn {$case} gav inget resultat\n" . (string) $stdout . "\n");
        exit(1);
    }
    $decoded['stdout'] = (string) $stdout;
    return $decoded;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'child') {
    csrf_child((string) ($argv[2] ?? ''));
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-csrf-fellogg-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL tempdir\n");
    exit(1);
}
$sess = $tmp . '/sess';
if (!mkdir($sess, 0775, true) && !is_dir($sess)) {
    fwrite(STDERR, "FAIL sessiondir\n");
    exit(1);
}

$secret = 'csrf-fellogg-test-secret';
$email = 'sam@example.com';
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => $secret,
    'hashes' => [
        ['hash' => password_hash('csrf-kampanjkod', PASSWORD_DEFAULT), 'email' => $email],
    ],
], JSON_UNESCAPED_SLASHES));

putenv('JEAB_CSRF_TEST_TMP=' . $tmp);
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
$_ENV['JEAB_CSRF_TEST_TMP'] = $tmp;
$_ENV['JEAB_PASSWORDS_FILE'] = $passwords;
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

csrf_assert(jeab_csrf_reject_reason('') === 'other', 'tom token är other');
csrf_assert(jeab_csrf_reject_reason('inte-en-token') === 'other', 'fel form är other');
csrf_assert(jeab_csrf_reject_reason(str_repeat('ab', 32)) === 'expired', 'token utan inloggning är expired');

$accept = csrf_spawn('accept');
csrf_assert($accept['returned'] === true, 'giltig token passerar require_csrf');
csrf_assert($accept['csrf_ok'] === true, 'jeab_csrf_ok är sann för aktuell token');
csrf_assert($accept['auth_ok'] === true && $accept['cookie_same'] === true, 'accepterad request lämnar inloggningen');

$acceptFresh = csrf_spawn('accept-fresh');
csrf_assert($acceptFresh['returned'] === true && $acceptFresh['csrf_ok'] === true, 'ny session med giltig signerad token accepteras fortfarande');

$mismatch = csrf_spawn('mismatch');
csrf_assert($mismatch['returned'] === false, 'fel token avvisas');
csrf_assert($mismatch['csrf_ok'] === false, 'jeab_csrf_ok är falsk för fel token');
csrf_assert((int) $mismatch['code'] === 419, 'avvisning svarar 419');
csrf_assert(str_contains($mismatch['stdout'], '{"ok":false,"error":"Ogiltig session. Ladda om sidan."}'), 'JSON-svaret är oförändrat');
csrf_assert($mismatch['auth_ok'] === true && $mismatch['cookie_same'] === true && $mismatch['email_same'] === true && $mismatch['sid_same'] === true, 'avvisning loggar ut inte');
csrf_assert((int) $mismatch['webhooks'] === 1, 'csrf med fel token postas till webhooken (ny felgrupp)');

$fresh = csrf_spawn('fresh');
csrf_assert($fresh['returned'] === false && (int) $fresh['code'] === 419, 'tom session med fel token avvisas');
csrf_assert($fresh['auth_ok'] === true && $fresh['cookie_same'] === true, 'expired-fallet loggar inte ut');
csrf_assert((int) $fresh['webhooks'] === 0, 'expired skickar ingen webhook');

$expired = csrf_spawn('expired');
csrf_assert($expired['returned'] === false && (int) $expired['code'] === 419, 'saknad session avvisas');
csrf_assert($expired['auth_ok'] === false, 'saknad cookie förblir utloggad');
csrf_assert((int) $expired['webhooks'] === 0, 'saknad session skickar ingen webhook');

$other = csrf_spawn('other');
csrf_assert($other['returned'] === false && (int) $other['code'] === 419, 'saknad token avvisas');
csrf_assert($other['auth_ok'] === true && $other['cookie_same'] === true, 'other-fallet loggar inte ut');

$logPath = jeab_app_errors_path();
$rawLog = is_file($logPath) ? (string) file_get_contents($logPath) : '';
$lines = preg_split("/\n/", trim($rawLog), -1, PREG_SPLIT_NO_EMPTY);
csrf_assert(is_array($lines) && count($lines) === 4, 'fyra avvisningar loggas, giltiga token gör det inte');

$byAction = [];
foreach ($lines as $line) {
    $row = json_decode((string) $line, true);
    csrf_assert(is_array($row), 'loggrad är JSON');
    $byAction[(string) ($row['action'] ?? '')] = $row;
}

$expect = [
    'csrf-test-mismatch' => ['reason' => 'mismatch', 'url' => '/index.php?p=reminders', 'actor' => 'user'],
    'csrf-test-fresh' => ['reason' => 'expired', 'url' => '/index.php?p=bokningar', 'actor' => 'user'],
    'csrf-test-expired' => ['reason' => 'expired', 'url' => '/index.php?p=notes', 'actor' => 'session'],
    'csrf-test-other' => ['reason' => 'other', 'url' => '/index.php?p=purchase', 'actor' => 'user'],
];

foreach ($expect as $action => $spec) {
    csrf_assert(isset($byAction[$action]), "loggrad för {$action}");
    $row = $byAction[$action];
    csrf_assert(($row['surface'] ?? '') === 'csrf', "{$action} surface csrf");
    csrf_assert(($row['message'] ?? '') === 'Ogiltig session.', "{$action} meddelande oförändrat");
    csrf_assert(($row['method'] ?? '') === 'POST', "{$action} method POST");
    csrf_assert(($row['url'] ?? '') === $spec['url'], "{$action} url {$spec['url']}");
    csrf_assert(($row['path'] ?? '') === $spec['url'], "{$action} path utan hemligheter");
    csrf_assert(($row['reason'] ?? '') === $spec['reason'], "{$action} reason {$spec['reason']}");
    $actor = (string) ($row['actor'] ?? '');
    csrf_assert(str_starts_with($actor, $spec['actor'] . ':'), "{$action} actor {$spec['actor']}");
    csrf_assert(preg_match('/\A(?:user|session):[0-9a-f]{16}\z/', $actor) === 1, "{$action} actor är hash");
}

$userHash = jeab_log_id_hash('user', $email);
csrf_assert($userHash !== '' && ($byAction['csrf-test-mismatch']['actor'] ?? '') === $userHash, 'user-hash matchar e-post utan att skriva den');
$sessionHash = jeab_log_id_hash('session', (string) $expired['sid']);
csrf_assert($sessionHash !== '' && ($byAction['csrf-test-expired']['actor'] ?? '') === $sessionHash, 'session-hash matchar session-id utan att skriva den');

csrf_assert(!str_contains($rawLog, 'super-secret-token'), 'query-token läcker inte');
csrf_assert(!str_contains($rawLog, $email), 'e-post läcker inte');
csrf_assert(!str_contains($rawLog, (string) $mismatch['token']), 'csrf-token läcker inte');
csrf_assert(!str_contains($rawLog, (string) $expired['sid']), 'session-id läcker inte');
csrf_assert(!str_contains($rawLog, 'csrf-test-accept'), 'giltig token skapar ingen rad');
csrf_assert(!str_contains($mismatch['stdout'], $userHash), 'klientsvaret får inte aktör-hash');

$entries = jeab_app_errors_read();
$reasons = [];
foreach ($entries as $entry) {
    if (($entry['surface'] ?? '') !== 'csrf') {
        continue;
    }
    $reasons[(string) ($entry['reason'] ?? '')] = $entry;
}
csrf_assert(isset($reasons['mismatch'], $reasons['expired'], $reasons['other']), 'fellogg-läsaren behåller reason');
csrf_assert(($reasons['mismatch']['method'] ?? '') === 'POST' && ($reasons['mismatch']['url'] ?? '') === '/index.php?p=reminders', 'fellogg-läsaren behåller method och url');
csrf_assert(($reasons['mismatch']['actor'] ?? '') === $userHash, 'fellogg-läsaren behåller actor');
csrf_assert(($reasons['mismatch']['fingerprint'] ?? '') !== ($reasons['expired']['fingerprint'] ?? ''), 'expired och mismatch är olika rader');

$entries = array_values($reasons);
$html = include dirname(__DIR__) . '/src/pages/logg.php';
csrf_assert(is_string($html), 'felloggsidan renderar');
csrf_assert(str_contains($html, 'POST /index.php?p=reminders'), 'sidan visar method och url');
csrf_assert(str_contains($html, 'mismatch'), 'sidan visar reason');
csrf_assert(str_contains($html, $userHash), 'sidan visar hashad aktör');
csrf_assert(!str_contains($html, $email), 'sidan visar inte e-post');

$example = json_encode($byAction['csrf-test-mismatch'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
echo "EXAMPLE\n{$example}\n";

$artifactDir = '/opt/cursor/artifacts';
if (!is_dir($artifactDir)) {
    @mkdir($artifactDir, 0775, true);
}
file_put_contents($artifactDir . '/csrf-fellogg-example.json', (string) $example . "\n");
file_put_contents($artifactDir . '/csrf-fellogg.jsonl', $rawLog);

echo "OK  csrf-fellogg\n";
