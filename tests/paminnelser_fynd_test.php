<?php

declare(strict_types=1);

/**
 * Påminnelsefynd: trim, tap-etikett, långsamma anrop, 2h-klockslag,
 * #vunnen-varning utan radering, och att korten har 44 px-ytor.
 * Påhittad data. Inget kundutskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-pam-fynd-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function fy(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

fy(jeab_reminder_trim_sep(' · Anna') === 'Anna', 'R1b ledande punkt försvinner');
fy(jeab_reminder_trim_sep('Anna ·') === 'Anna', 'R1b avslutande punkt försvinner');
fy(jeab_reminder_trim_sep('Anna · Berg') === 'Anna · Berg', 'R1b punkt inne i namnet står kvar');

fy(jeab_reminder_tap_total_label(['mailat' => 2, 'ringt' => 0, 'pratat' => 0, 'smsat' => 0]) === '2 mail', 'U20 bara mail');
fy(jeab_reminder_tap_total_label(['mailat' => 1, 'ringt' => 2, 'pratat' => 0, 'smsat' => 0]) === '2 ringda, 1 mail', 'U20 blandade tryck');
fy(jeab_reminder_tap_total_label(['mailat' => 0, 'ringt' => 0, 'pratat' => 0, 'smsat' => 0]) === '', 'U20 tomt utan tryck');

$slow = file_get_contents(dirname(__DIR__) . '/src/reminders.php');
fy(str_contains($slow, 'function jeab_reminder_slow_threshold_ms') && str_contains($slow, 'return 2000;'), 'R6 tröskel 2000 ms');
jeab_reminder_log_slow('test', 0.1);

fy(jeab_reminder_due_keep_clock('2026-10-24 00:00:00', '2026-10-23 15:30:00') === '2026-10-24 15:30:00', 'U47 klockslaget följer med');
fy(jeab_reminder_due_keep_clock('2026-10-24 00:00:00', '2026-10-23 00:00:00') === '2026-10-24 00:00:00', 'U47 midnatt står kvar');
fy(jeab_reminder_due_keep_clock('2026-10-24 09:15:00', '2026-10-23 15:30:00') === '2026-10-24 09:15:00', 'U47 ett skickat klockslag vinner');

$store = jeab_store();
$created = jeab_create_reminder($store, 'Fyndgatan 1', null, null, null, [
    'customer' => ' · Testperson',
    'company' => 'malmo',
]);
$id = (int) ($created['id'] ?? 0);
fy($id > 0, 'testpåminnelse skapad');
$created = jeab_update_reminder($store, $id, [
    'company' => 'malmo',
    'booking_id' => 4242,
    'tags' => ['vunnen'],
]);
jeab_purchase_put([
    'reminder_id' => $id,
    'company' => 'malmo',
    'fortnox_order' => 'TEST-1',
    'fastighetsbeteckning' => 'TEST 1:1',
    'booking_id' => 4242,
]);
$before = jeab_purchase_get($id);
$notice = jeab_reminder_vunnen_untag_notice($store, $created);
fy(str_contains($notice, 'Fortnox-order TEST-1') && str_contains($notice, 'Lantmäteriet') && str_contains($notice, 'Malmö-bokningen'), 'V10 varning nämner order, LM och bokning');
fy(str_contains($notice, 'tas bort') === false || str_contains($notice, 'Inget av det tas bort'), 'V10 säger att inget tas bort');
$after = jeab_purchase_get($id);
fy(is_array($before) && is_array($after) && ($after['fortnox_order'] ?? '') === 'TEST-1' && (int) ($after['booking_id'] ?? 0) === 4242, 'V10 order och bokning finns kvar');

$html = jeab_reminder_row($created);
fy(str_contains($html, 'aria-label="'), 'U20 aria-label finns');
fy(!str_contains($html, 'class="flag-form"') && !str_contains($html, 'class="flag-btn'), 'flaggan är borta från kortet');
fy(str_contains($html, 'class="item-tags-toggle tap44"') && str_contains($html, 'class="item-cal-btn tap44"'), 'U49 V5 V6 44 px');
fy(str_contains($html, 'autocomplete="name"'), 'U44 namn får autofyll');
fy(!str_contains($html, 'Testperson ·') || !str_contains($html, '>·'), 'R1b kundraden trimmas');

$page = file_get_contents(dirname(__DIR__) . '/src/pages/reminders.php');
$emailAt = strpos($page, 'name="email"');
$noteAt = strpos($page, 'name="notes"');
fy($emailAt !== false && $noteAt !== false && $emailAt < $noteAt, 'U50 anteckning efter e-post');
fy(str_contains($page, 'autocomplete="name" autocorrect="off"'), 'U44 skapa-namn');
fy(str_contains($page, 'Datum (valfritt, tomt = idag)'), 'N-35 skapa-datum märkt valfritt');
fy(preg_match('/<input type="date" name="due_at"[^>]*\srequired[\s>]/', $page) !== 1, 'N-35 skapa-datum är inte required');

$css = file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
fy(str_contains($css, 'min-height: 44px') && str_contains($css, '.tap44'), 'U18 .tap44');
fy(preg_match('/apple-search input\[name="q"\][\s\S]*?font-size:\s*16px/', $css) === 1, 'U44 sök 16 px');
fy(!preg_match('/\.flag-form\s*\{\s*display:\s*none/', $css), 'flaggans css finns kvar om ett gammalt kort skickas');
fy(!str_contains($css, 'item-shut-addr:not(:empty) + .item-shut-name::before'), 'namn och adress delar inte rad med punkt');
fy(str_contains($css, '.item-shut-addr + .item-shut-name'), 'namnet följer adressen utan punkt');
fy(str_contains($css, '.jeab-nav-spinner-cancel') && str_contains($css, 'min-height: 44px'), 'V8 avbryt-knapp');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
foreach ([
    'TAP_DEBOUNCE_MS = 450',
    'close_lead',
    'writeOpenParam',
    'countContactLink',
    'mailto:',
    'jeab-nav-spinner-cancel',
    '20000',
    'askVunnenDrop',
    'Behåll',
    'Ta bort',
    'drop_related',
    '#vunnen tas bort. Order, Lantmäteriet-uppgifter och bokning står kvar om du behåller dem.',
    "intent', 'pulse'",
    'data.warn',
    'softNavigateReminders',
    'markReminderDone(check, true)',
    'crm-stats a.smart-tile',
] as $needle) {
    fy(str_contains($js, $needle), 'app.js ' . $needle);
}

$layout = file_get_contents(dirname(__DIR__) . '/src/layout.php');
fy(str_contains($layout, '.ov3 .v3-seg-opt span') && str_contains($layout, 'min-height:44px'), 'V9 produktval minst 44 px');

$index = file_get_contents(dirname(__DIR__) . '/index.php');
fy(str_contains($index, 'jeab_reminder_due_from_post') && str_contains($index, 'jeab_reminder_log_slow') && str_contains($index, '_JEAB_VUNNEN_NOTICE'), 'index kopplar klockslag, logg och vunnen-varning');

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'fynd-test-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
fy(jeab_auth_ok(), 'curl-testaren är inloggad');

$snoozed = jeab_update_reminder($store, $id, ['due_at' => '2026-10-23 15:30:00', 'tags' => ['vunnen']]);
fy(str_contains((string) ($snoozed['due_at'] ?? ''), '15:30'), '2h-tid satt');

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_FYND_DATA');
require getenv('JEAB_ROOT') . '/index.php';
PHP);
$port = 0;
for ($try = 8821; $try < 8840; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
fy($port > 0, 'en lokal port är ledig');
$log = $tmp . '/server.log';
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $serverPipes,
    null,
    [
        'JEAB_FYND_DATA' => $tmp,
        'JEAB_ROOT' => dirname(__DIR__),
        'JEAB_PASSWORDS_FILE' => $passwords,
    ]
);
fy(is_resource($server), 'php-servern startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=csrf');
    if (is_string($probe) && $probe !== '') {
        $ready = true;
        break;
    }
    usleep(100000);
}
fy($ready, 'servern svarar');
$cookie = (string) $_COOKIE['jeab_auth'];
$csrf = (string) jeab_csrf_token();
$ch = curl_init('http://127.0.0.1:' . $port . '/index.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf' => $csrf,
        'action' => 'reminder.due',
        'id' => $id,
        'due_at' => '2026-10-24',
    ]),
    CURLOPT_HTTPHEADER => [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json',
        'Cookie: jeab_auth=' . $cookie,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$dueBody = (string) curl_exec($ch);
$dueCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$dueRow = jeab_get_reminder(jeab_store(), $id);
fy($dueCode === 200 && str_contains((string) ($dueRow['due_at'] ?? ''), '2026-10-24 15:30'), 'curl behåller 15:30 när dagen byts, fick ' . $dueCode . ' ' . substr($dueBody, 0, 160));

$ch = curl_init('http://127.0.0.1:' . $port . '/index.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf' => $csrf,
        'action' => 'reminder.tag',
        'id' => $id,
        'tag' => 'vunnen',
    ]),
    CURLOPT_HTTPHEADER => [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json',
        'Cookie: jeab_auth=' . $cookie,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$tagBody = (string) curl_exec($ch);
$tagCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$tagJson = json_decode($tagBody, true);
$still = jeab_purchase_get($id);
fy($tagCode === 200 && is_array($tagJson) && str_contains((string) ($tagJson['warn'] ?? ''), 'Fortnox-order TEST-1'), 'curl varnar när #vunnen tas bort');
fy(is_array($still) && ($still['fortnox_order'] ?? '') === 'TEST-1' && (int) ($still['booking_id'] ?? 0) === 4242, 'curl lämnar order och bokning');

$fyndCustomer = $store->insertCustomer('Fynd testkund', jeab_now());
$keepBooking = $store->insertBooking([
    'customer_id' => (int) $fyndCustomer['id'],
    'customer_name' => 'Behall test',
    'email' => 'behall@example.com',
    'phone' => '0700000000',
    'address' => 'Behallgatan 1',
    'company' => 'malmo',
    'assignees' => ['Kim'],
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 11:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$keepNow = jeab_now();
$keepId = (int) ($store->insertReminder([
    'title' => 'Behallgatan 1',
    'customer_name' => 'Behall test',
    'company' => 'malmo',
    'booking_id' => (int) $keepBooking['id'],
    'tags' => ['vunnen'],
    'due_at' => '2026-11-02 09:00:00',
    'created_at' => $keepNow,
    'updated_at' => $keepNow,
])['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $keepId,
    'company' => 'malmo',
    'fortnox_order' => 'KEEP-1',
    'fastighetsbeteckning' => 'KEEP 1:1',
    'booking_id' => (int) $keepBooking['id'],
]);
$ch = curl_init('http://127.0.0.1:' . $port . '/index.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf' => $csrf,
        'action' => 'reminder.tag',
        'id' => $keepId,
        'tag' => 'vunnen',
        'drop_related' => '0',
    ]),
    CURLOPT_HTTPHEADER => [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json',
        'Cookie: jeab_auth=' . $cookie,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$keepBody = (string) curl_exec($ch);
curl_close($ch);
$keepJson = json_decode($keepBody, true);
jeab_purchase_cache_forget();
$keepPurchase = jeab_purchase_get($keepId);
$keepAfter = $store->getBooking((int) $keepBooking['id']);
fy(is_array($keepJson) && str_contains((string) ($keepJson['warn'] ?? ''), 'Inget av det tas bort'), 'drop_related 0 behåller varningen');
fy(is_array($keepPurchase) && ($keepPurchase['fortnox_order'] ?? '') === 'KEEP-1', 'drop_related 0 lämnar ordern');
fy(is_array($keepAfter) && (string) ($keepAfter['status'] ?? '') !== 'cancelled', 'drop_related 0 lämnar bokningen');

$dropBooking = $store->insertBooking([
    'customer_id' => (int) $fyndCustomer['id'],
    'customer_name' => 'Tabort test',
    'email' => 'tabort@example.com',
    'phone' => '0700000000',
    'address' => 'Tabortgatan 1',
    'company' => 'malmo',
    'assignees' => ['Kim'],
    'starts_at' => '2026-11-03 09:00:00',
    'ends_at' => '2026-11-03 11:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$dropNow = jeab_now();
$dropId = (int) ($store->insertReminder([
    'title' => 'Tabortgatan 1',
    'customer_name' => 'Tabort test',
    'company' => 'malmo',
    'booking_id' => (int) $dropBooking['id'],
    'tags' => ['vunnen'],
    'due_at' => '2026-11-03 09:00:00',
    'created_at' => $dropNow,
    'updated_at' => $dropNow,
])['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $dropId,
    'company' => 'malmo',
    'fortnox_order' => 'DROP-1',
    'fastighetsbeteckning' => 'DROP 1:1',
    'booking_id' => (int) $dropBooking['id'],
]);
$ch = curl_init('http://127.0.0.1:' . $port . '/index.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_csrf' => $csrf,
        'action' => 'reminder.tag',
        'id' => $dropId,
        'tag' => 'vunnen',
        'drop_related' => '1',
    ]),
    CURLOPT_HTTPHEADER => [
        'X-Requested-With: XMLHttpRequest',
        'Accept: application/json',
        'Cookie: jeab_auth=' . $cookie,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
]);
$dropBody = (string) curl_exec($ch);
curl_close($ch);
$dropJson = json_decode($dropBody, true);
jeab_purchase_cache_forget();
$dropPurchase = jeab_purchase_get($dropId);
$dropAfter = $store->getBooking((int) $dropBooking['id']);
fy(is_array($dropJson) && str_contains((string) ($dropJson['warn'] ?? ''), 'borttagna'), 'drop_related 1 säger att ordern tas bort, fick ' . substr($dropBody, 0, 180));
fy(!is_array($dropPurchase), 'drop_related 1 tar bort ordern');
fy(is_array($dropAfter) && (string) ($dropAfter['status'] ?? '') === 'cancelled', 'drop_related 1 avbokar bokningen');

echo "ALLA OK\n";
