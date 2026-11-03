<?php

declare(strict_types=1);

/**
 * calendar_log.cleanup och booking.delete_silent ska neka om jeab_is_admin()
 * inte är sann. Admin är Sam L med egen e-post och den delade
 * agenter@-koden. v1-kaka utan e-post, Elin och Sam W räknas inte.
 * dry=1 lämnar bara id och räkningar.
 * Inget i det här testet tar bort bokning, order eller påminnelse.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-delete-auth-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$realData = realpath($root . '/data');
$ours = realpath($tmp);
if ($realData !== false && $ours !== false && $realData === $ours) {
    fwrite(STDERR, "FAIL temp dir är data/\n");
    exit(1);
}

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'] = $tmp . '/backups';
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [
        ['hash' => 'elin-hash-fixture', 'email' => 'saljare4@jarfalla.example.test'],
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
        ['hash' => 'samw-hash-fixture', 'email' => 'demo@malmo.example.test'],
        ['hash' => 'agenter-hash-fixture', 'email' => 'agenter@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
$_ENV['JEAB_PASSWORDS_FILE'] = $passwords;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'incoming_webhook_token' => 'test-token-not-used',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require_once $root . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');

function g02_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function g02_clear_login(): void
{
    $_SESSION = [];
    unset($_COOKIE['jeab_auth']);
}

function g02_as(?string $email, string $hash): void
{
    g02_clear_login();
    $_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value($email, $hash);
}

function g02_v1(): void
{
    g02_clear_login();
    $payload = '1.' . time() . '.ab';
    $_COOKIE['jeab_auth'] = $payload . '.' . hash_hmac('sha256', $payload, jeab_auth_secret());
}

g02_assert(jeab_data_dir() === $tmp, 'testet använder en tillfällig datakatalog');
g02_assert(jeab_driver() === 'json', 'testet använder json-lagring');
g02_assert(str_starts_with(str_replace('\\', '/', jeab_json_path()), str_replace('\\', '/', $tmp . '/')), 'butiken ligger i den tillfälliga katalogen');

$index = (string) file_get_contents($root . '/index.php');
$gate = "!function_exists('jeab_is_admin') || !jeab_is_admin()";
g02_assert(substr_count($index, $gate) >= 2, 'båda åtgärderna nekar om jeab_is_admin() inte är sann');
g02_assert(!str_contains($index, 'is_array($me) && !in_array'), 'den öppna personkontrollen är borta');
g02_assert(!str_contains($index, 'jeab_require_personal_admin'), 'åtgärderna använder jeab_is_admin()');

g02_clear_login();
g02_assert(jeab_is_admin() === false, 'utan kaka: inte admin');

g02_v1();
g02_assert(jeab_auth_ok() && jeab_auth_user_email() === null, 'v1-kaka är inloggad utan e-post');
g02_assert(jeab_is_admin() === false, 'v1 utan e-post är inte admin');

g02_as('agenter@flowstack.example.test', 'agenter-hash-fixture');
g02_assert(jeab_auth_user_email() === 'agenter@flowstack.example.test', 'agentkoden bär agenter@');
g02_assert(jeab_is_shared_login_email(jeab_auth_user_email()), 'agentkoden är en delad inloggning');
g02_assert(jeab_personal_login_person() === null, 'agentkoden är inte en personlig inloggning');
g02_assert(jeab_is_admin() === true && jeab_inkorg_is_admin() === true, 'agentkoden är admin');

g02_as('saljare4@jarfalla.example.test', 'elin-hash-fixture');
g02_assert(jeab_is_admin() === false && (jeab_person_by_email(jeab_auth_user_email())['id'] ?? '') === 'Elin', 'Elins egen adress är inte admin');

g02_as('demo@malmo.example.test', 'samw-hash-fixture');
g02_assert((jeab_personal_login_person()['id'] ?? '') === 'SamW' && jeab_is_admin() === false, 'Sam W med egen adress är inte admin');

g02_as('demo@example.test', 'sam-hash-fixture');
g02_assert(jeab_is_admin() === true && (jeab_personal_login_person()['id'] ?? '') === 'SamL', 'Sam L med egen adress är admin');

$store = jeab_store(true);
$booking = $store->insertBooking([
    'customer_id' => null,
    'customer_name' => 'Fiktiv Testkund G02',
    'phone' => '0700000911',
    'email' => 'fiktiv.g02@example.invalid',
    'address' => 'Testgatan 91',
    'notes' => null,
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => '2026-10-20 09:00:00',
    'ends_at' => '2026-10-20 10:00:00',
    'created_at' => '2026-10-01 10:00:00',
    'updated_at' => '2026-10-01 10:00:00',
]);
$bookingId = (int) $booking['id'];
g02_assert($bookingId > 0, 'testbokningen skapades i den tillfälliga butiken');
jeab_calendar_log_write(['entries' => [
    ['id' => 1, 'type' => 'booked', 'at' => '2026-10-01 10:00:00', 'booking_id' => 0, 'title' => 'QA Orphan', 'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 10:00:00'],
], 'backfilled' => true]);

$pii = ['Fiktiv Testkund G02', 'fiktiv.g02@example.invalid', '0700000911', 'Testgatan 91'];
$runner = $tmp . '/run-action.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = (string) getenv('JEAB_TEST_DATA');
$root = (string) getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'] = $tmp . '/backups';
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php?format=json';
$_GET = ['format' => 'json'];
require_once $root . '/src/bootstrap.php';
if (jeab_data_dir() !== $tmp || jeab_driver() !== 'json') {
    fwrite(STDERR, "FAIL isolerad lagring\n");
    exit(2);
}
$storePath = str_replace('\\', '/', jeab_json_path());
if (!str_starts_with($storePath, str_replace('\\', '/', $tmp) . '/')) {
    fwrite(STDERR, "FAIL butik utanför temp\n");
    exit(2);
}
$_SESSION = [];
$mode = (string) getenv('JEAB_MODE');
if ($mode === 'out') {
    unset($_COOKIE['jeab_auth']);
} elseif ($mode === 'v1') {
    $GLOBALS['_JEAB_CSRF_AUTH_NONCE'] = '0123456789abcdef0123456789abcdef';
    $payload = '1.' . time() . '.ab';
    $_COOKIE['jeab_auth'] = $payload . '.' . hash_hmac('sha256', $payload, jeab_auth_secret());
} else {
    $value = jeab_auth_issue_value((string) getenv('JEAB_EMAIL'), (string) getenv('JEAB_HASH'));
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "FAIL kaka\n");
        exit(2);
    }
    $_COOKIE['jeab_auth'] = $value;
}
$_POST = [
    'action' => (string) getenv('JEAB_ACTION'),
    '_csrf' => (string) jeab_csrf_token(),
    'id' => (string) getenv('JEAB_ID'),
    'ids' => (string) getenv('JEAB_ID'),
    'confirm_name' => (string) getenv('JEAB_NAME'),
    'dry' => (string) getenv('JEAB_DRY'),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require $root . '/index.php';
PHP);

function g02_http(string $mode, string $action, string $dry, int $id, string $name, string $email = '', string $hash = ''): array
{
    global $runner, $tmp, $root, $passwords;
    $env = [
        'JEAB_TEST_DATA' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_PASSWORDS_FILE' => $passwords,
        'JEAB_DISABLE_SQLITE' => '1',
        'JEAB_MODE' => $mode,
        'JEAB_ACTION' => $action,
        'JEAB_DRY' => $dry,
        'JEAB_ID' => (string) $id,
        'JEAB_NAME' => $name,
        'JEAB_EMAIL' => $email,
        'JEAB_HASH' => $hash,
        'PATH' => (string) getenv('PATH'),
    ];
    $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $tmp . '/php-error.log', $runner];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tmp, $env);
    g02_assert(is_resource($proc), 'åtgärdsprocessen startade');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $text = is_string($out) ? $out : '';
    $start = strpos($text, '{');
    $json = $start === false ? null : json_decode(substr($text, $start), true);
    if (!is_array($json)) {
        fwrite(STDERR, "FAIL svar är inte JSON ({$action} {$mode}) exit={$code}\n" . $text . "\n" . (is_string($err) ? $err : '') . "\n");
        exit(1);
    }
    return $json;
}

function g02_intact(int $id): void
{
    $store = jeab_store(true);
    $row = $store->getBooking($id);
    g02_assert(is_array($row) && ($row['customer_name'] ?? '') === 'Fiktiv Testkund G02' && ($row['status'] ?? '') === 'booked', 'bokningen finns kvar');
    $log = jeab_calendar_log_read();
    $ids = array_map(static fn (array $entry): int => (int) ($entry['id'] ?? 0), $log['entries']);
    g02_assert($ids === [1], 'veckologgen är orörd');
    g02_assert($store->findOpenReminderByBooking($id) === null, 'ingen påminnelse skapades eller togs bort');
}

function g02_no_pii(array $json, string $label): void
{
    global $pii;
    $blob = json_encode($json, JSON_UNESCAPED_UNICODE);
    g02_assert(is_string($blob), $label . ' går att läsa');
    foreach ($pii as $secret) {
        g02_assert(!str_contains((string) $blob, $secret), $label . ' innehåller inte ' . $secret);
    }
}

$cases = [
    ['out', '', '', 'booking.delete_silent', '0', 'utloggad tyst radering'],
    ['v1', '', '', 'booking.delete_silent', '0', 'v1 tyst radering'],
    ['user', 'agenter@flowstack.example.test', 'agenter-hash-fixture', 'booking.delete_silent', '1', 'agenter dry'],
    ['user', 'saljare4@jarfalla.example.test', 'elin-hash-fixture', 'booking.delete_silent', '0', 'Elin tyst radering'],
    ['user', 'demo@malmo.example.test', 'samw-hash-fixture', 'booking.delete_silent', '0', 'Sam W tyst radering'],
    ['user', 'demo@example.test', 'sam-hash-fixture', 'booking.delete_silent', '1', 'Sam L dry'],
    ['out', '', '', 'calendar_log.cleanup', '0', 'utloggad loggstädning'],
    ['v1', '', '', 'calendar_log.cleanup', '0', 'v1 loggstädning'],
    ['user', 'agenter@flowstack.example.test', 'agenter-hash-fixture', 'calendar_log.cleanup', '1', 'agenter dry-städning'],
    ['user', 'saljare4@jarfalla.example.test', 'elin-hash-fixture', 'calendar_log.cleanup', '0', 'Elin loggstädning'],
    ['user', 'demo@example.test', 'sam-hash-fixture', 'calendar_log.cleanup', '1', 'Sam L dry-städning'],
];

foreach ($cases as [$mode, $email, $hash, $action, $dry, $label]) {
    $json = g02_http($mode, $action, $dry, $action === 'calendar_log.cleanup' ? 1 : $bookingId, 'Fiktiv Testkund G02', $email, $hash);
    g02_no_pii($json, $label);
    if ($mode === 'out') {
        g02_assert(($json['ok'] ?? true) === false && in_array((string) ($json['error'] ?? ''), ['Logga in först.', 'Inte behörig.'], true), $label . ' nekas');
    } elseif ($dry === '0') {
        g02_assert(($json['ok'] ?? true) === false && ($json['error'] ?? '') === 'Inte behörig.', $label . ' nekas');
        g02_assert(!isset($json['row']), $label . ' lämnar ingen rad');
    } else {
        g02_assert(($json['ok'] ?? false) === true, $label . ' släpps igenom');
        if ($action === 'booking.delete_silent') {
            $row = $json['row'] ?? null;
            g02_assert(is_array($row) && ($row['dry'] ?? false) === true && (int) ($row['id'] ?? 0) === $bookingId, $label . ' lämnar id');
            g02_assert(array_keys($row) === ['dry', 'id', 'counts'] && is_array($row['counts']), $label . ' lämnar bara id och räkningar');
            g02_assert((int) ($row['counts']['log_entries'] ?? -1) === 0, $label . ' räknar loggrader');
        }
    }
    g02_intact($bookingId);
}

echo "ALLA OK\n";
