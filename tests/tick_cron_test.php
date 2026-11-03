<?php

declare(strict_types=1);

/**
 * tick.php: token, lås, felisolering, och att bokningsnotiser stannar på sms-cron.
 * Tokenvärdet skapas i temp och skrivs inte ut.
 */

function tick_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function tick_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    tick_fail($message);
}

function tick_redact(string $text, string $secret): string
{
    if ($secret === '') {
        return $text;
    }
    return str_replace($secret, '[redacted]', $text);
}

function tick_lease_token(string $payload): string
{
    $pos = strpos($payload, '|');
    if ($pos === false) {
        return '';
    }

    return substr($payload, $pos + 1);
}

function tick_pool_live(): int
{
    $pool = $GLOBALS['_JEAB_PDO_POOL'] ?? null;
    if (!is_array($pool)) {
        return 0;
    }
    $live = 0;
    foreach ($pool as $item) {
        if ($item instanceof PDO) {
            $live++;
        }
    }

    return $live;
}

/** @return list<string> */
function tick_called_names(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $names = [];
    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            $names[] = $token[1];
        }
    }
    return $names;
}

/** @param array<string, string> $headers
 * @return array{status: int, body: string, headers: array<string, string>}
 */
function tick_http(string $url, string $method, ?string $body, string $secret, array $headers = []): array
{
    $header = "Connection: close\r\n";
    foreach ($headers as $name => $value) {
        $header .= $name . ': ' . $value . "\r\n";
    }
    if ($method === 'POST' && !isset($headers['Content-Type'])) {
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $header,
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
            'follow_location' => 0,
        ],
    ]);
    $err = '';
    set_error_handler(static function (int $severity, string $message) use (&$err, $secret): bool {
        unset($severity);
        $err = tick_redact($message, $secret);
        return true;
    });
    $raw = file_get_contents($url, false, $ctx);
    restore_error_handler();
    if ($raw === false) {
        tick_fail('HTTP-anrop misslyckades: ' . ($err !== '' ? $err : 'okänt fel'));
    }
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})\b#', $line, $m) === 1) {
            $status = (int) $m[1];
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
    }
    return ['status' => $status, 'body' => tick_redact((string) $raw, $secret), 'headers' => $responseHeaders];
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-tick-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    tick_fail('temp dir');
}
if (!is_dir($tmp . '/public') && !mkdir($tmp . '/public', 0700, true) && !is_dir($tmp . '/public')) {
    tick_fail('public dir');
}
$token = bin2hex(random_bytes(16));
$tokenFile = $tmp . '/jeab_tick_token';
file_put_contents($tokenFile, $token . "\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
$GLOBALS['_JEAB_TICK_TOKEN_FILE'] = $tokenFile;
$GLOBALS['_JEAB_SHIELD_DONE'] = true;

require $root . '/src/bootstrap.php';
require_once $root . '/src/tick.php';

$forbidden = [
    'jeab_sms_tick',
    'jeab_sms_send',
    'jeab_booking_send_customer_mail',
    'jeab_booking_notify_customer',
    'jeab_notiser_send',
];
foreach (['tick.php', 'src/tick.php'] as $rel) {
    $names = tick_called_names($root . '/' . $rel);
    foreach ($forbidden as $name) {
        tick_assert(!in_array($name, $names, true), $rel . ' anropar inte ' . $name);
    }
}
$cronSrc = (string) file_get_contents($root . '/sms-cron.php');
tick_assert(str_contains($cronSrc, 'return jeab_sms_tick($store);'), 'sms-cron.php anropar fortfarande jeab_sms_tick');
tick_assert(!str_contains($cronSrc, 'jeab_tick_run'), 'sms-cron.php känner inte till tick-körningen');
tick_assert(!str_contains($cronSrc, 'JEAB_TICK_TOKEN'), 'sms-cron.php läser inte tick-token');
tick_assert(!str_contains($cronSrc, 'jeab_tick_expected_token'), 'sms-cron.php använder inte tick.php:s token');
tick_assert(str_contains($cronSrc, "jeab_suresms_config()['tick_token']"), 'sms-cron.php har sin egen tick_token');
tick_assert(!str_contains($cronSrc, 'jeab_reject_get_webhook_token'), 'sms-cron.php tvingar inte GET till 405');
$tickEntry = (string) file_get_contents($root . '/tick.php');
tick_assert(!str_contains($tickEntry, 'jeab_reject_get_webhook_token'), 'tick.php tvingar inte GET till 405');
tick_assert(str_contains($tickEntry, 'jeab_cron_http_gate'), 'tick.php använder cron-grinden');
$tickSrc = (string) file_get_contents($root . '/src/tick.php');
tick_assert(str_contains($tickSrc, 'jeab_cron_token_matches(jeab_tick_expected_token())'), 'tick jämför bara sin fil-token');
$securitySrc = (string) file_get_contents($root . '/src/security.php');
$cronFnAt = strpos($securitySrc, 'function jeab_cron_token_matches');
$cronFnEnd = $cronFnAt === false ? false : strpos($securitySrc, "\nfunction ", $cronFnAt + 10);
$cronFn = $cronFnAt === false ? '' : substr($securitySrc, $cronFnAt, ($cronFnEnd === false ? 800 : $cronFnEnd - $cronFnAt));
tick_assert(str_contains($cronFn, 'hash_equals'), 'cron-token jämförs med hash_equals');
tick_assert(str_contains($securitySrc, 'Referrer-Policy: no-referrer'), 'cron-svaret sätter Referrer-Policy no-referrer');
tick_assert(str_contains($securitySrc, 'function jeab_cron_capture_query_token'), 'query-token scrubbas innan logg');
tick_assert(str_contains($tickSrc, "GET_LOCK('jeab_tick_hs'") && str_contains($tickSrc, "RELEASE_LOCK('jeab_tick_hs')"), 'mysql tar GET_LOCK jeab_tick_hs runt HS-batchen');
tick_assert(!str_contains($tickSrc, 'jeab_db_get_lock'), 'tick tar inte databaslåset jeab_tick');
tick_assert(str_contains($tickSrc, 'jeab_tick_lease'), 'sqlite hyr en settings-rad med utgångstid');
tick_assert(str_contains($tickSrc, 'jeab_tick_lock_renew'), 'tick förnyar hyran');
$ttlParam = (new ReflectionFunction('jeab_tick_lock_acquire'))->getParameters()[1] ?? null;
$renewParam = (new ReflectionFunction('jeab_tick_lock_renew'))->getParameters()[1] ?? null;
tick_assert($ttlParam instanceof ReflectionParameter && $ttlParam->getDefaultValue() === 300, 'hyrans TTL är 300 sekunder');
tick_assert($renewParam instanceof ReflectionParameter && $renewParam->getDefaultValue() === 300, 'förnyelsens TTL är 300 sekunder');

$bookingIds = [];
foreach (jeab_notiser_booking_rows() as $row) {
    if (is_array($row)) {
        $bookingIds[] = (string) ($row['id'] ?? '');
    }
}
$bookingIds = array_values(array_unique(array_filter($bookingIds)));
sort($bookingIds);
$excluded = jeab_tick_booking_templates();
sort($excluded);
tick_assert($bookingIds === $excluded, 'uteslutningslistan är exakt bokningsmallarna');
tick_assert(jeab_tick_booking_categories() === ['bokningsnotis', 'bokningspaminnelse'], 'kategorierna är bokningsnotis och bokningspåminnelse');
tick_assert(in_array('bekraft-batteri-jarfalla', $excluded, true), 'bekräftelsemallen för batteri är med');
tick_assert(in_array('sms-sol-malmo', $excluded, true), 'påminnelsemallen för sol är med');

$pdo = jeab_tick_pdo();
tick_assert($pdo instanceof PDO, 'låset har en SQL-anslutning');
tick_assert(jeab_tick_lock_acquire($pdo), 'första låset tas');
tick_assert(!jeab_tick_lock_acquire($pdo), 'andra låset tas inte medan första hålls');
$calledWhileBusy = 0;
$GLOBALS['_JEAB_TICK_GUARD'] = static function () use (&$calledWhileBusy): array {
    $calledWhileBusy++;
    return ['ok' => true, 'count' => 1];
};
$GLOBALS['_JEAB_TICK_INKORG'] = $GLOBALS['_JEAB_TICK_GUARD'];
$GLOBALS['_JEAB_TICK_MALMO'] = $GLOBALS['_JEAB_TICK_GUARD'];
$busy = jeab_tick_run();
tick_assert(!empty($busy['busy']) && $busy['jobs'] === [], 'upptaget lås hoppar över deljobben');
tick_assert($calledWhileBusy === 0, 'upptaget lås anropar inget deljobb');
jeab_tick_lock_release($pdo);
unset($GLOBALS['_JEAB_TICK_GUARD'], $GLOBALS['_JEAB_TICK_INKORG'], $GLOBALS['_JEAB_TICK_MALMO']);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
jeab_settings_put($pdo, $driver, 'jeab_tick_lease', (string) (time() - 5) . '|stale');
tick_assert(jeab_tick_lock_acquire($pdo), 'utgången hyra kan tas över');
tick_assert(!jeab_tick_lock_acquire($pdo), 'ny hyra blockerar ett andra lås');
jeab_tick_lock_release($pdo);

$GLOBALS['_JEAB_TICK_LEASE_NOW'] = 1_800_000_000;
tick_assert(jeab_tick_lock_acquire($pdo), 'fryst hyra tas');
$owned = (string) ($GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '');
$GLOBALS['_JEAB_TICK_LEASE_NOW'] = 1_800_000_000 + 1000;
tick_assert(jeab_tick_lock_renew($pdo), 'egen token förnyar efter TTL');
$renewed = (string) ($GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '');
tick_assert(tick_lease_token($owned) !== '' && tick_lease_token($owned) === tick_lease_token($renewed) && $owned !== $renewed, 'förnyelsen behåller token och flyttar tiden');
tick_assert(!jeab_tick_lock_acquire($pdo), 'förnyad hyra kan inte tas över');
tick_assert((string) ($GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '') === $renewed, 'misslyckat övertagande rör inte den förnyade markören');
jeab_tick_lock_release($pdo);
$GLOBALS['_JEAB_TICK_LEASE_NOW'] = 1_810_000_000;
tick_assert(jeab_tick_lock_acquire($pdo), 'hyra för utgångstest tas');
$GLOBALS['_JEAB_TICK_LEASE_NOW'] = 1_810_000_000 + 301;
tick_assert(jeab_tick_lock_acquire($pdo), 'utan förnyelse kan utgången hyra tas över');
jeab_tick_lock_release($pdo);
unset($GLOBALS['_JEAB_TICK_LEASE_NOW']);

$order = [];
$poolDuring = [];
$leaseDuring = '';
$leaseAfterMiss = '';
$secondBlocked = false;
$GLOBALS['_JEAB_TICK_GUARD'] = static function () use (&$order, &$poolDuring, &$leaseDuring, &$leaseAfterMiss, &$secondBlocked): array {
    $order[] = 'guard';
    $poolDuring[] = tick_pool_live();
    $leaseDuring = (string) ($GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '');
    $again = jeab_tick_pdo();
    $secondBlocked = $again instanceof PDO && !jeab_tick_lock_acquire($again);
    $leaseAfterMiss = (string) ($GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '');
    $again = null;
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    throw new RuntimeException('kund@example.invalid');
};
$GLOBALS['_JEAB_TICK_INKORG'] = static function () use (&$order, &$poolDuring): array {
    $order[] = 'inkorg';
    $poolDuring[] = tick_pool_live();
    return ['ok' => true, 'count' => 2];
};
$GLOBALS['_JEAB_TICK_MALMO'] = static function () use (&$order, &$poolDuring): array {
    $order[] = 'malmo';
    $poolDuring[] = tick_pool_live();
    return ['ok' => true, 'count' => 4];
};
$split = jeab_tick_run();
tick_assert($poolDuring === [0, 0, 0], 'deljobben körs utan öppen databasanslutning');
tick_assert($leaseDuring !== '' && str_contains($leaseDuring, '|'), 'hyresraden lever utan anslutningen');
tick_assert($secondBlocked, 'hyran blockerar ett andra lås på en ny anslutning');
tick_assert($leaseAfterMiss === $leaseDuring, 'ett misslyckat lås rör inte ägarens markör');
$splitJson = (string) json_encode($split);
tick_assert($order === ['guard', 'inkorg', 'malmo'], 'ett fel stoppar inte senare deljobb');
tick_assert(empty($split['ok']), 'tick är inte ok när ett deljobb felar');
tick_assert(($split['jobs']['guard']['error'] ?? '') === 'fel', 'felet i svaret är en kod');
tick_assert(($split['jobs']['inkorg']['count'] ?? null) === 2, 'inkorgen räknas ändå');
tick_assert(($split['jobs']['malmo']['count'] ?? null) === 4, 'Malmö räknas ändå');
tick_assert(!str_contains($splitJson, 'example.invalid'), 'undantagstexten läcker inte ut');
tick_assert(!str_contains($splitJson, '@'), 'svaret har ingen adress');
unset($GLOBALS['_JEAB_TICK_GUARD'], $GLOBALS['_JEAB_TICK_INKORG'], $GLOBALS['_JEAB_TICK_MALMO']);

$GLOBALS['_JEAB_TICK_LEASE_NOW'] = 1_700_000_000;
$GLOBALS['_JEAB_TICK_LEASE_TRACE'] = [];
$longPool = [];
$longBlocked = [];
$longFresh = [];
$longJob = static function (string $name) use (&$longPool, &$longBlocked, &$longFresh): callable {
    return static function () use ($name, &$longPool, &$longBlocked, &$longFresh): array {
        $longPool[] = tick_pool_live();
        $now = (int) $GLOBALS['_JEAB_TICK_LEASE_NOW'];
        $again = jeab_tick_pdo();
        $driver = $again instanceof PDO && $again->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $row = $again instanceof PDO && function_exists('jeab_settings_value')
            ? (string) jeab_settings_value($again, $driver, 'jeab_tick_lease')
            : '';
        $mark = (string) ($GLOBALS['_JEAB_TICK_LEASE_VALUE'] ?? '');
        $longBlocked[$name] = $again instanceof PDO && !jeab_tick_lock_acquire($again);
        $longFresh[$name] = $row !== '' && $row === $mark && ((int) $row) >= $now + 300;
        $again = null;
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
        $GLOBALS['_JEAB_TICK_LEASE_NOW'] = $now + 301;

        return ['ok' => true, 'count' => 1];
    };
};
$GLOBALS['_JEAB_TICK_GUARD'] = $longJob('guard');
$GLOBALS['_JEAB_TICK_INKORG'] = $longJob('inkorg');
$GLOBALS['_JEAB_TICK_MALMO'] = $longJob('malmo');
$long = jeab_tick_run();
tick_assert(($GLOBALS['_JEAB_TICK_LEASE_TRACE'] ?? null) === ['renew', 'renew', 'renew', 'renew', 'renew', 'renew'], 'hyran förnyas före underhåll, varje deljobb, wix-spam och orderikapp');
tick_assert($longPool === [0, 0, 0], 'förnyelsen släpper anslutningen före deljobbet');
tick_assert($longBlocked === ['guard' => true, 'inkorg' => true, 'malmo' => true], 'en körning längre än TTL kan inte tas över när hyran förnyas');
tick_assert($longFresh === ['guard' => true, 'inkorg' => true, 'malmo' => true], 'varje deljobb ser en hyra som räcker 300 sekunder');
tick_assert(!empty($long['ok']) && (int) $GLOBALS['_JEAB_TICK_LEASE_NOW'] >= 1_700_000_000 + 900, 'klockan gick längre än TTL under körningen');
$GLOBALS['_JEAB_TICK_LEASE_TRACE'] = [];
$GLOBALS['_JEAB_TICK_LEASE_NOW'] = 1_700_000_000;
$spamCalls = 0;
$GLOBALS['_JEAB_WIX_REQUEST'] = static function (string $siteId, string $method, string $path, ?array $body) use (&$spamCalls): array {
    unset($siteId, $method, $path, $body);
    $spamCalls++;

    return [];
};
$GLOBALS['_JEAB_TICK_GUARD'] = static function (): array {
    return ['ok' => true, 'count' => 0];
};
$GLOBALS['_JEAB_TICK_INKORG'] = static function (): array {
    return ['ok' => true, 'count' => 0];
};
$GLOBALS['_JEAB_TICK_MALMO'] = static function (): array {
    $GLOBALS['_JEAB_TICK_LEASE_VALUE'] = '';

    return ['ok' => true, 'count' => 0];
};
$blockedSpam = jeab_tick_run();
tick_assert($spamCalls === 0 && !empty($blockedSpam['ok']), 'utan hyra före wix-spam görs inget Wix-anrop');
tick_assert(($GLOBALS['_JEAB_TICK_LEASE_TRACE'] ?? null) === ['renew', 'renew', 'renew', 'renew'], 'wix-spam startar inte när förnyelsen misslyckas');
unset($GLOBALS['_JEAB_WIX_REQUEST'], $GLOBALS['_JEAB_TICK_GUARD'], $GLOBALS['_JEAB_TICK_INKORG'], $GLOBALS['_JEAB_TICK_MALMO'], $GLOBALS['_JEAB_TICK_LEASE_NOW'], $GLOBALS['_JEAB_TICK_LEASE_TRACE']);

$mailCalls = 0;
$smsCalls = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$mailCalls): array {
    $mailCalls++;
    return ['ok' => true, 'id' => 'test'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function () use (&$smsCalls): array {
    $smsCalls++;
    return ['ok' => true];
};
date_default_timezone_set('Europe/Stockholm');
$store = jeab_store();
$customer = $store->insertCustomer('Tick Probe', jeab_now());
$aged = (new DateTimeImmutable('now'))->modify('-3 hours')->format('Y-m-d H:i:s');
$start = (new DateTimeImmutable('now'))->modify('+1 day')->format('Y-m-d H:i:s');
$end = (new DateTimeImmutable('now'))->modify('+1 day +2 hours')->format('Y-m-d H:i:s');
$store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Tick Probe',
    'email' => 'tick-probe@exempel.se',
    'phone' => '0701234567',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $start,
    'ends_at' => $end,
    'status' => 'booked',
    'created_at' => $aged,
    'updated_at' => $aged,
]);
$ran = jeab_tick_run();
tick_assert(isset($ran['jobs']['guard'], $ran['jobs']['inkorg'], $ran['jobs']['malmo']), 'tick har tre deljobb');
tick_assert(!isset($ran['jobs']['sms']), 'tick har inget sms-deljobb');
tick_assert($mailCalls === 0 && $smsCalls === 0, 'tick skickar ingen bokningsnotis');
$control = jeab_sms_tick($store);
tick_assert($mailCalls === 1, 'samma bokning mejlas av jeab_sms_tick');
tick_assert(($control['sent_email'] ?? 0) === 1, 'sms-cron-tickern räknar bekräftelsen');
unset($GLOBALS['JEAB_SYSTEM_MAIL'], $GLOBALS['JEAB_SMS_SEND']);

$resetCron = static function (): void {
    unset($GLOBALS['_JEAB_CRON_QUERY_TOKEN'], $GLOBALS['_JEAB_CRON_QUERY_TOKEN_READY']);
    $_GET = [];
    $_POST = [];
    unset(
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
        $_SERVER['HTTP_X_WEBHOOK_TOKEN'],
        $_SERVER['CONTENT_TYPE']
    );
};
$resetCron();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['token'] = $token;
$_SERVER['REQUEST_URI'] = '/tick.php?token=' . $token;
$_SERVER['QUERY_STRING'] = 'token=' . rawurlencode($token);
tick_assert(jeab_tick_token_ok(), 'GET med tick-filens token godkänns');
tick_assert(!isset($_GET['token']), 'GET-token tas bort från $_GET');
tick_assert(!str_contains((string) $_SERVER['REQUEST_URI'], $token), 'REQUEST_URI innehåller inte token efter jämförelsen');
tick_assert(!str_contains((string) $_SERVER['QUERY_STRING'], $token), 'QUERY_STRING innehåller inte token efter jämförelsen');
tick_assert(!isset($GLOBALS['_JEAB_CRON_QUERY_TOKEN']), 'token ligger inte kvar i GLOBALS');

$resetCron();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['token'] = 'webshop-nyckel-inte-cron';
$_SERVER['REQUEST_URI'] = '/tick.php?token=webshop-nyckel-inte-cron';
$_SERVER['QUERY_STRING'] = 'token=webshop-nyckel-inte-cron';
tick_assert(!jeab_tick_token_ok(), 'en annan nyckel godkänns inte som tick-token');
tick_assert(!str_contains((string) $_SERVER['REQUEST_URI'], 'webshop-nyckel-inte-cron'), 'avvisad token scrubbas ur REQUEST_URI');

$resetCron();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['token'] = $token;
$_SERVER['REQUEST_URI'] = '/tick.php?token=' . $token;
$_SERVER['QUERY_STRING'] = 'token=' . rawurlencode($token);
tick_assert(!jeab_tick_token_ok(), 'query-token på POST räcker inte');
tick_assert(!str_contains((string) $_SERVER['REQUEST_URI'], $token), 'POST-query scrubbas ändå');

$resetCron();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $token;
tick_assert(jeab_tick_token_ok(), 'POST med header-token godkänns');

$resetCron();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['token'] = $token;
tick_assert(jeab_tick_token_ok(), 'POST med body-token godkänns');

$resetCron();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$GLOBALS['_JEAB_CRON_ALLOW_IPS'] = ['203.0.113.9'];
tick_assert(jeab_cron_access_block() === 'ip', 'IP utanför listan stoppas');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
tick_assert(jeab_cron_access_block() === null, 'listad IP släpps');
$GLOBALS['_JEAB_CRON_ALLOW_IPS'] = [];
tick_assert(jeab_cron_access_block() === null, 'tom IP-lista släpper cron som idag');

$GLOBALS['_JEAB_CRON_RATE_LIMIT'] = 1;
$GLOBALS['_JEAB_CRON_RATE_WINDOW'] = 60;
$GLOBALS['_JEAB_CRON_RATE_DIR'] = $tmp . '/rate';
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
tick_assert(jeab_cron_access_block() === null, 'första anropet inom taket släpps');
tick_assert(jeab_cron_access_block() === 'rate', 'taket stoppar nästa anrop');
$rateDump = '';
foreach (glob($tmp . '/rate/*') ?: [] as $rateFile) {
    $rateDump .= (string) file_get_contents($rateFile);
}
tick_assert(!str_contains($rateDump, $token), 'tak-filen innehåller inte token');
unset($GLOBALS['_JEAB_CRON_ALLOW_IPS'], $GLOBALS['_JEAB_CRON_RATE_LIMIT'], $GLOBALS['_JEAB_CRON_RATE_WINDOW'], $GLOBALS['_JEAB_CRON_RATE_DIR']);
$resetCron();

$modeFile = $tmp . '/mode.txt';
file_put_contents($modeFile, 'stub');
$guardOut = $tmp . '/guard.json';
$log = $tmp . '/server.log';
$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php

declare(strict_types=1);

$data = getenv('JEAB_TICK_DATA');
if (!is_string($data) || $data === '') {
    http_response_code(500);
    echo 'missing data dir';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$GLOBALS['_JEAB_TICK_TOKEN_FILE'] = getenv('JEAB_TICK_TOKEN_FILE');
$GLOBALS['_JEAB_SHIELD_DONE'] = true;
$modePath = getenv('JEAB_TICK_MODE');
$mode = is_string($modePath) && is_file($modePath) ? trim((string) file_get_contents($modePath)) : 'stub';
if ($mode === 'guard') {
    unset($GLOBALS['_JEAB_TICK_GUARD']);
} else {
    $GLOBALS['_JEAB_TICK_GUARD'] = static function (): array {
        return ['ok' => true, 'count' => 0, 'skipped' => 'saknas'];
    };
}
$GLOBALS['_JEAB_TICK_INKORG'] = static function (): array {
    return ['ok' => true, 'count' => 3];
};
$GLOBALS['_JEAB_TICK_MALMO'] = static function (): array {
    return ['ok' => true, 'count' => 1];
};

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$root = getenv('JEAB_TICK_ROOT');
if (!is_string($root) || $root === '' || $path !== '/tick.php') {
    http_response_code(404);
    echo 'no';
    return true;
}
require $root . '/tick.php';
return true;
PHP
);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    tick_fail('kunde inte välja port');
}
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
if ($port <= 0) {
    tick_fail('ogiltig port');
}
$proc = proc_open([
    PHP_BINARY,
    '-d', 'display_errors=0',
    '-d', 'log_errors=1',
    '-d', 'error_log=' . $log,
    '-S', '127.0.0.1:' . $port,
    '-t', $root,
    $router,
], [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
], $pipes, $root, [
    'JEAB_TICK_DATA' => $tmp,
    'JEAB_TICK_TOKEN_FILE' => $tokenFile,
    'JEAB_TICK_ROOT' => $root,
    'JEAB_TICK_MODE' => $modeFile,
    'JEAB_TICK_GUARD_OUT' => $guardOut,
]);
if (!is_resource($proc)) {
    tick_fail('servern startade inte');
}
$stop = static function () use ($proc, $tmp): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($tmp);
};
register_shutdown_function($stop);

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
tick_assert($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port;
$referrer = static function (array $res): string {
    return strtolower((string) ($res['headers']['referrer-policy'] ?? ''));
};
$missing = tick_http($base . '/tick.php', 'GET', null, $token);
tick_assert($missing['status'] === 401, 'GET utan token är 401, fick ' . $missing['status']);
tick_assert($referrer($missing) === 'no-referrer', '401 sätter Referrer-Policy no-referrer');
$wrongSecret = 'fel-cron-token-inte-den-riktiga';
$wrong = tick_http($base . '/tick.php?token=' . rawurlencode($wrongSecret), 'GET', null, $token);
tick_assert($wrong['status'] === 401, 'GET med fel token är 401, fick ' . $wrong['status']);
tick_assert(str_contains($wrong['body'], 'Fel token.'), 'fel token får en fast feltext');
tick_assert(!str_contains($wrong['body'], $token) && !str_contains($wrong['body'], $wrongSecret), '401-svaret innehåller inte token');
tick_assert($referrer($wrong) === 'no-referrer', 'fel token sätter Referrer-Policy no-referrer');

$goodGet = tick_http($base . '/tick.php?token=' . rawurlencode($token), 'GET', null, $token);
tick_assert($goodGet['status'] === 200, 'GET med rätt token är 200, fick ' . $goodGet['status'] . ' ' . $goodGet['body']);
$goodGetJson = json_decode($goodGet['body'], true);
tick_assert(is_array($goodGetJson) && !empty($goodGetJson['ok']), 'GET med tick-token ger ok');
tick_assert(!str_contains($goodGet['body'], $token), 'GET-svaret innehåller inte token');
tick_assert($referrer($goodGet) === 'no-referrer', 'GET-träffen sätter Referrer-Policy no-referrer');

$good = tick_http($base . '/tick.php', 'POST', '', $token, ['X-Webhook-Token' => $token]);
tick_assert($good['status'] === 200, 'POST med header-token är 200, fick ' . $good['status'] . ' ' . $good['body']);
tick_assert($referrer($good) === 'no-referrer', 'POST sätter Referrer-Policy no-referrer');
$goodJson = json_decode($good['body'], true);
tick_assert(is_array($goodJson) && !empty($goodJson['ok']), 'rätt token ger ok');
tick_assert(($goodJson['jobs']['inkorg']['count'] ?? null) === 3, 'inkorgen rapporterar antal');
tick_assert(($goodJson['jobs']['malmo']['count'] ?? null) === 1, 'Malmö rapporterar antal');
tick_assert(($goodJson['jobs']['guard']['skipped'] ?? '') === 'saknas', 'nödbromsen hoppas över när funktionen saknas');
tick_assert(!isset($goodJson['jobs']['sms']), 'HTTP-svaret har inget sms-jobb');
tick_assert(!str_contains($good['body'], $token), '200-svaret innehåller inte token');

file_put_contents($modeFile, 'guard');
$guarded = tick_http($base . '/tick.php', 'POST', http_build_query(['token' => $token]), $token);
tick_assert($guarded['status'] === 200, 'nödbromsen svarar 200');
$guardJson = json_decode($guarded['body'], true);
tick_assert(($guardJson['jobs']['guard']['count'] ?? null) === 0, 'avstängd tick-avbetning skickar inget');
tick_assert(($guardJson['jobs']['guard']['skipped'] ?? '') === 'av', 'SEND_GUARD_TICK_DRAIN är av som standard');
tick_assert(str_contains($tickSrc, "send_guard_drain(["), 'tick anropar send_guard_drain');
tick_assert(str_contains($tickSrc, "'exclude_booking' => true"), 'tick lämnar bokningsnotiser');
tick_assert(str_contains($tickSrc, "'limit' => 5"), 'tick tar högst fem jobb');
tick_assert(!str_contains($tickSrc, 'jeab_send_guard_drain('), 'tick anropar inte den saknade funktionen');

echo "OK  tick\n";
