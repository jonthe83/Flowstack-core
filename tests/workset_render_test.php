<?php

declare(strict_types=1);

/**
 * Varje arbetsgrupp ska kunna rita sin sida och sina kort utan
 * «Call to undefined function». Ett rent PHP-anrop per fall, samma
 * bootstrap som index.php. Påhittad data. Skickar inget.
 */

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;

if (($argv[1] ?? '') === 'worker') {
    wr_worker($root, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-workset-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}

function wr_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function wr_ok(string $message): void
{
    echo "OK  {$message}\n";
}

$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'workset-secret',
    'hashes' => [
        ['hash' => 'agenter-hash-fixture', 'email' => 'agenter@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_BOOT_ALL'] = true;
require $root . '/src/bootstrap.php';
if (function_exists('jeab_ordrar_external_live')) {
    jeab_ordrar_external_live(false);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$cookie = jeab_auth_issue_value('agenter@flowstack.example.test', 'agenter-hash-fixture');
if (!is_string($cookie) || $cookie === '') {
    wr_fail('kunde inte utfärda kaka');
}
file_put_contents($tmp . '/cookie.txt', $cookie);

$store = jeab_store();
if (!$store instanceof JeabPdoStore) {
    wr_fail('sqlite');
}
$now = jeab_now();
$reminder = $store->insertReminder([
    'title' => 'Testgatan 9',
    'customer_name' => 'Ada Kort',
    'company' => 'jarfalla',
    'note' => 'En anteckning i kortet.',
    'phone' => '0700000001',
    'due_at' => '2026-10-29 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$reminderId = (int) ($reminder['id'] ?? 0);
if ($reminderId <= 0) {
    wr_fail('påminnelse');
}
$monday = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
$customer = $store->insertCustomer('Roberto Kund', $now);
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Roberto Kund',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'address' => 'Testgatan 2',
    'starts_at' => $monday . ' 09:00:00',
    'ends_at' => $monday . ' 11:00:00',
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$bookingId = (int) ($booking['id'] ?? 0);
$pdo = $store->pdo();
$orders = [];
foreach (['sol' => 'Sol Testkund', 'batteri' => 'Batteri Testkund', 'laddbox' => 'Ladd Testkund'] as $kind => $name) {
    $id = 8800 + count($orders) + 1;
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'kind' => $kind,
        'customer_name' => $name,
        'name' => $name,
        'address' => 'Testgatan 1, 175 00 Järfälla',
        'huvudsokande' => $name,
        'pris' => 12000,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $orders[$kind] = $id;
}
jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_index_v1', 'ok');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_json_v1', 'ok');
$ada = [
    'id' => 'kt_ada',
    'name' => 'Ada Kontakt',
    'street' => 'Testgatan 1',
    'postal_code' => '17500',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'owners' => [[
        'name' => 'Ada Kontakt',
        'email' => 'ada@example.test',
        'phone' => '0700000001',
        'contact' => true,
    ]],
];
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => ['kt_ada' => $ada]], JSON_UNESCAPED_UNICODE));
$contacts = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
if ($contacts instanceof JeabPdoContactStore) {
    $contacts->upsert('kt_ada', $ada);
}
$inkorg = $tmp . '/inkorg';
if (!is_dir($inkorg)) {
    mkdir($inkorg, 0775, true);
}
file_put_contents($inkorg . '/settings.json', json_encode(['helpscout_companies' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($inkorg . '/messages.json', json_encode(['messages' => [[
    'id' => 'm-ada',
    'thread_id' => 't-ada',
    'from' => 'kund.ada@gmail.com',
    'from_name' => 'Ada Kontakt',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fråga om sol',
    'body_text' => 'Hej, en testfråga.',
    'source' => 'graph-jarfalla',
    'received_at' => '2026-10-20T08:00:00Z',
    'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol'],
]]], JSON_UNESCAPED_UNICODE));
file_put_contents($inkorg . '/threads.json', json_encode(['threads' => [
    't-ada' => ['status' => 'open', 'tags' => [], 'subject' => 'Fråga om sol', 'brand' => 'jarfalla'],
]], JSON_UNESCAPED_UNICODE));
$ixStore = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
if ($ixStore instanceof JeabPdoInkorgStore) {
    $ixStore->upsertMessage('m-ada', [
        'id' => 'm-ada',
        'thread_id' => 't-ada',
        'from' => 'kund.ada@gmail.com',
        'from_name' => 'Ada Kontakt',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga om sol',
        'body_text' => 'Hej, en testfråga.',
        'source' => 'graph-jarfalla',
        'received_at' => '2026-10-20T08:00:00Z',
        'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol'],
    ]);
    $ixStore->upsertThread('t-ada', [
        'status' => 'open',
        'tags' => [],
        'subject' => 'Fråga om sol',
        'brand' => 'jarfalla',
    ]);
}
file_put_contents($tmp . '/ids.json', json_encode([
    'reminder' => $reminderId,
    'booking' => $bookingId,
    'orders' => $orders,
    'contact' => 'kt_ada',
    'thread' => 't-ada',
], JSON_UNESCAPED_UNICODE));

$cases = [
    'reminders' => ['Påminnelser', 'reminder-create', false],
    'reminders-page' => ['Påminnelser', 'Ada Kort', false],
    'reminders-card' => ['Ada Kort', 'item-head-open', false],
    'reminders-open' => ['Ada Kort', 'item-head-open', false],
    'bookings' => ['Kalender', 'Roberto', false],
    'bookings-roberto' => ['Roberto', 'who=Roberto', false],
    'bookings-month' => ['Kalender', 'month-cal', false],
    'bookings-day' => ['Kalender', 'Idag', false],
    'bookings-wave' => ['"ok":true', 'bookings', false],
    'bookings-log' => ['"ok":true', 'lines', false],
    'bookings-outlook' => ['"ok":true', 'outlook', false],
    'ordrar' => ['Ordrar', 'data-board-job', false],
    'ordrar-sol' => ['Sol Testkund', 'data-v3-root', true],
    'ordrar-batteri' => ['Batteri Testkund', 'data-sigen', true],
    'ordrar-laddbox' => ['Ladd Testkund', 'data-v3-root', true],
    'kontakter' => ['Kontakter', 'Ada Kontakt', false],
    'kontakter-filter' => ['data-kt-root', 'data-filter="adress"', false],
    'kontakter-sok' => ['data-kt-root', 'Ada', false],
    'kontakter-card' => ['Ada Kontakt', 'kt_ada', false],
    'inkorg' => ['Inkorg', 'ix', false],
    'inkorg-filter' => ['Inkorg', 'Fråga', false],
    'inkorg-thread' => ['Inkorg', 't-ada', false],
    'inkorg-list' => ['"ok":true', 'html', false],
    'inkorg-part-thread' => ['t-ada', 'ok', false],
];

foreach ($cases as $case => $expect) {
    $cmd = [$php, '-d', 'display_errors=1', '-d', 'log_errors=1', $root . '/tests/workset_render_test.php', 'worker', $case, $tmp];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        wr_fail($case . ' startade inte');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $blob = $out . "\n" . $err;
    if ($code !== 0 || str_contains($blob, 'undefined function') || str_contains($blob, 'FAILED')) {
        wr_fail($case . ' kod ' . $code . ' ' . trim(substr($blob, 0, 1200)));
    }
    if (!str_contains($out, 'MARK1') || !str_contains($out, 'MARK2')) {
        wr_fail($case . ' ritade inte sidan: ' . trim(substr($blob, 0, 800)));
    }
    if ($expect[2] && (!str_contains($err, 'sigen.php') || !str_contains($err, 'offert.php') || !str_contains($err, 'seom_fullmakt.php') || !str_contains($err, 'system_mail.php'))) {
        wr_fail($case . ' laddade inte hela orderkortet: ' . trim(substr($err, -500)));
    }
    wr_ok($case);
}

function wr_worker(string $root, string $case, string $tmp): void
{
    $ids = json_decode((string) file_get_contents($tmp . '/ids.json'), true);
    if (!is_array($ids)) {
        fwrite(STDOUT, "FAILED ids\n");
        exit(1);
    }
    $orders = is_array($ids['orders'] ?? null) ? $ids['orders'] : [];
    $get = ['p' => 'reminders', 'co' => 'jarfalla'];
    $mark1 = 'Påminnelser';
    $mark2 = 'reminder-create';
    switch ($case) {
        case 'reminders-page':
            $get['intent'] = 'page';
            $mark1 = 'Påminnelser';
            $mark2 = 'Ada Kort';
            break;
        case 'reminders-card':
            $get['intent'] = 'card';
            $get['open'] = (string) ($ids['reminder'] ?? 0);
            $mark1 = 'Ada Kort';
            $mark2 = 'item-head-open';
            break;
        case 'reminders-open':
            $get['open'] = (string) ($ids['reminder'] ?? 0);
            $mark1 = 'Ada Kort';
            $mark2 = 'item-head-open';
            break;
        case 'bookings':
            $get['p'] = 'bookings';
            $mark1 = 'Kalender';
            $mark2 = 'Roberto';
            break;
        case 'bookings-roberto':
            $get = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'Roberto', 'job' => 'all', 'ws' => (string) time()];
            $mark1 = 'Roberto';
            $mark2 = 'who=Roberto';
            break;
        case 'bookings-month':
            $get = ['p' => 'bookings', 'co' => 'jarfalla', 'view' => 'month'];
            $mark1 = 'Kalender';
            $mark2 = 'month-cal';
            break;
        case 'bookings-day':
            $get = ['p' => 'bookings', 'co' => 'jarfalla', 'view' => 'day', 'd' => (new DateTimeImmutable('today'))->format('Y-m-d')];
            $mark1 = 'Kalender';
            $mark2 = 'Idag';
            break;
        case 'bookings-wave':
            $get = ['p' => 'bookings', 'co' => 'jarfalla', 'intent' => 'calendar-bookings'];
            $mark1 = '"ok":true';
            $mark2 = 'bookings';
            break;
        case 'bookings-log':
            $get = ['p' => 'bookings', 'co' => 'jarfalla', 'intent' => 'calendar-log'];
            $mark1 = '"ok":true';
            $mark2 = 'lines';
            break;
        case 'bookings-outlook':
            $get = ['p' => 'bookings', 'co' => 'jarfalla', 'intent' => 'calendar-outlook'];
            $mark1 = '"ok":true';
            $mark2 = 'outlook';
            break;
        case 'ordrar':
            $get = ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'all', 'ws' => (string) time()];
            $mark1 = 'Ordrar';
            $mark2 = 'data-board-job';
            break;
        case 'ordrar-sol':
        case 'ordrar-batteri':
        case 'ordrar-laddbox':
            $kind = substr($case, strlen('ordrar-'));
            $get = ['p' => 'ordrar', 'co' => 'jarfalla', 'id' => (string) ($orders[$kind] ?? 0)];
            $mark1 = ['sol' => 'Sol Testkund', 'batteri' => 'Batteri Testkund', 'laddbox' => 'Ladd Testkund'][$kind];
            $mark2 = $kind === 'batteri' ? 'data-sigen' : 'data-v3-root';
            break;
        case 'kontakter':
            $get = ['p' => 'kontakter', 'co' => 'jarfalla'];
            $mark1 = 'Kontakter';
            $mark2 = 'Ada Kontakt';
            break;
        case 'kontakter-filter':
            $get = ['p' => 'kontakter', 'co' => 'jarfalla', 'f' => 'adress'];
            $mark1 = 'data-kt-root';
            $mark2 = 'data-filter="adress"';
            break;
        case 'kontakter-sok':
            $get = ['p' => 'kontakter', 'co' => 'jarfalla', 'q' => 'Ada'];
            $mark1 = 'data-kt-root';
            $mark2 = 'Ada';
            break;
        case 'kontakter-card':
            $get = ['p' => 'kontakter', 'co' => 'jarfalla', 'intent' => 'card', 'id' => (string) ($ids['contact'] ?? '')];
            $mark1 = 'Ada Kontakt';
            $mark2 = 'kt_ada';
            break;
        case 'inkorg':
            $get = ['p' => 'inkorg', 'co' => 'jarfalla'];
            $mark1 = 'Inkorg';
            $mark2 = 'ix';
            break;
        case 'inkorg-filter':
            $get = ['p' => 'inkorg', 'co' => 'jarfalla', 'q' => 'Fråga'];
            $mark1 = 'Inkorg';
            $mark2 = 'Fråga';
            break;
        case 'inkorg-thread':
            $get = ['p' => 'inkorg', 'co' => 'jarfalla', 'id' => (string) ($ids['thread'] ?? '')];
            $mark1 = 't-ada';
            $mark2 = 'Skicka offert';
            break;
        case 'inkorg-list':
            $get = ['p' => 'inkorg', 'co' => 'jarfalla', 'ix_part' => 'list'];
            $mark1 = '"ok":true';
            $mark2 = 'html';
            break;
        case 'inkorg-part-thread':
            $get = ['p' => 'inkorg', 'co' => 'jarfalla', 'ix_part' => 'thread', 'id' => (string) ($ids['thread'] ?? '')];
            $mark1 = 't-ada';
            $mark2 = 'Skicka offert';
            break;
    }
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_COOKIE['jeab_auth'] = trim((string) file_get_contents($tmp . '/cookie.txt'));
    $GLOBALS['_JEAB_BOOT_LAZY'] = true;
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
    putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/passwords.json');
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('XAI_API_KEY');
    ini_set('log_errors', '1');
    ini_set('error_log', $tmp . '/php-error.log');
    ob_start();
    register_shutdown_function(static function () use ($mark1, $mark2, $tmp): void {
        $html = (string) ob_get_contents();
        $files = array_map('basename', get_included_files());
        fwrite(STDERR, 'FILES ' . implode(',', $files) . "\n");
        $err = error_get_last();
        $errText = is_array($err) ? (string) ($err['message'] ?? '') : '';
        $log = is_file($tmp . '/php-error.log') ? (string) file_get_contents($tmp . '/php-error.log') : '';
        $appLog = '';
        foreach (glob($tmp . '/app-errors*.jsonl') ?: [] as $appFile) {
            if (is_string($appFile)) {
                $appLog .= (string) file_get_contents($appFile);
            }
        }
        $blob = $html . "\n" . $errText . "\n" . $log . "\n" . $appLog;
        if (str_contains($blob, 'undefined function') || str_contains($blob, 'Uncaught') || str_contains($blob, 'ix_render')) {
            fwrite(STDOUT, "FAILED\n" . substr($blob, 0, 1500) . "\n");
            return;
        }
        $hit1 = str_contains($html, $mark1);
        $hit2 = str_contains($html, $mark2);
        fwrite(STDOUT, ($hit1 ? 'MARK1' : 'NO1') . ' ' . ($hit2 ? 'MARK2' : 'NO2') . "\n");
        if (!$hit1 || !$hit2) {
            fwrite(STDOUT, "FAILED snippet " . substr(preg_replace('/\s+/', ' ', $html) ?? '', 0, 700) . "\n");
        }
    });
    require $root . '/index.php';
}
