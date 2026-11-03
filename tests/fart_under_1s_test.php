<?php

declare(strict_types=1);

/**
 * Sidladdning under en sekund: arbetsgrupp i stället för hela modulistan,
 * Sol-filtret i SQL, kalenderns personfilter och bilagan sol_jarfalla
 * utan Inkorg eller Fortnox. Påhittad data. Skickar inget.
 */

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$tmp = sys_get_temp_dir() . '/jeab-fart-1s-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}

function fs_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function fs_ok(string $message): void
{
    echo "OK  {$message}\n";
}

function fs_run(string $php, string $root, string $code): string
{
    $cmd = [$php, '-d', 'display_errors=1', '-r', $code];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        fs_fail('kunde inte starta php');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codeExit = proc_close($proc);
    if ($codeExit !== 0) {
        fs_fail('underprocess ' . $codeExit . ' ' . trim((string) $out . "\n" . $err));
    }

    return (string) $out;
}

function fs_has(string $output, string $name): bool
{
    return str_contains($output, '/' . $name) || str_contains($output, '\\' . $name);
}

$private = $tmp . '/private';
mkdir($private, 0775, true);
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $private . '/app.sqlite', 'json_path' => $private . '/store.json'],
], true) . ";\n");

$card = fs_run($php, $root, '$_GET=["p"=>"reminders","intent"=>"card","open"=>"117"];'
    . '$_SERVER["REQUEST_METHOD"]="GET";'
    . '$_SERVER["SCRIPT_FILENAME"]=' . var_export($root . '/index.php', true) . ';'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($private, true) . ';'
    . 'putenv("JEAB_DISABLE_SQLITE=0");'
    . 'putenv("CONTACTS_SOLE=0");'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . 'if (session_status()!==PHP_SESSION_ACTIVE) { session_start(); }'
    . '$files=implode("\n", get_included_files());'
    . 'echo $files, "\n";'
    . '$_SESSION["jeab_email"]="agenter@flowstack.example.test";'
    . '$row=["id"=>117,"title"=>"Testgatan 9","customer_name"=>"Ada Kort","company"=>"jarfalla","due_at"=>"2026-10-29 09:00:00","note"=>"En rad","phone"=>"0700000001","created_at"=>"2026-10-01 08:00:00","updated_at"=>"2026-10-01 08:00:00"];'
    . '$open=jeab_reminder_row($row, true);'
    . '$shut=jeab_reminder_row($row, false);'
    . 'echo "OPEN ", (str_contains($open, "Ada Kort") && str_contains($open, "item-head-open") ? "1" : "0"), "\n";'
    . 'echo "SHUT ", (str_contains($shut, "item-head-shut") && !str_contains($shut, "item-head-open") ? "1" : "0"), "\n";'
    . 'echo "FRAG ", (jeab_request_is_read_fragment() ? "1" : "0"), "\n";'
    . '$GLOBALS["_JEAB_SAPI"]="fpm-fcgi";'
    . 'unset($GLOBALS["_JEAB_PAGE_REQUEST"]);'
    . 'echo "DDL ", (jeab_schema_request_skips_ddl() ? "1" : "0"), "\n";'
);
if (fs_has($card, 'ordrar.php') || fs_has($card, 'inkorg_auto.php') || fs_has($card, 'fortnox.php')) {
    fs_fail('kortet laddade en tung modul');
}
if (!str_contains($card, 'OPEN 1') || !str_contains($card, 'SHUT 1') || !str_contains($card, 'FRAG 1') || !str_contains($card, 'DDL 1')) {
    fs_fail('kort eller ddl: ' . trim($card));
}
fs_ok('påminnelsekortet ritas utan ordrar, inkorg_auto och fortnox, och intent=card skippar DDL');

$pdfDir = $private . '/inkorg/offert';
if (!is_dir($pdfDir) && !mkdir($pdfDir, 0775, true) && !is_dir($pdfDir)) {
    fs_fail('pdf-katalog');
}
file_put_contents($pdfDir . '/sol_jarfalla.pdf', "%PDF-1.4 sol-jarfalla\n");
$fileHit = fs_run($php, $root, '$_GET=["p"=>"notiser","fil"=>"sol_jarfalla"];'
    . '$_SERVER["REQUEST_METHOD"]="GET";'
    . '$_SERVER["SCRIPT_FILENAME"]=' . var_export($root . '/index.php', true) . ';'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($private, true) . ';'
    . 'putenv("JEAB_DISABLE_SQLITE=0");'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . '$files=implode("\n", get_included_files());'
    . 'ob_start();'
    . 'jeab_notiser_send_file("sol_jarfalla");'
    . '$body=ob_get_clean();'
    . 'echo $files, "\n";'
    . 'echo "PDF ", (str_starts_with((string) $body, "%PDF-1.4 sol-jarfalla") ? "1" : "0"), "\n";'
    . 'ob_start();'
    . 'jeab_notiser_send_file("okand_nyckel");'
    . '$miss=ob_get_clean();'
    . 'echo "MISS ", (str_contains((string) $miss, "Bilagan finns inte") ? "1" : "0"), "\n";'
);
if (fs_has($fileHit, 'inkorg_auto.php') || fs_has($fileHit, 'fortnox.php') || fs_has($fileHit, 'inkorg.php') || fs_has($fileHit, 'ordrar.php')) {
    fs_fail('bilagan laddade inkorg eller fortnox');
}
if (!str_contains($fileHit, 'PDF 1') || !str_contains($fileHit, 'MISS 1')) {
    fs_fail('sol_jarfalla: ' . trim($fileHit));
}
fs_ok('p=notiser&fil=sol_jarfalla lämnar PDF utan 500 och utan inkorg_auto');

$pages = ['bookings' => 'bookings.php', 'ordrar' => 'ordrar.php', 'kontakter' => 'kontakter.php', 'inkorg' => 'inkorg.php'];
foreach ($pages as $page => $need) {
    $hit = fs_run($php, $root, '$_GET=["p"=>' . var_export($page, true) . '];'
        . '$_SERVER["REQUEST_METHOD"]="GET";'
        . '$_SERVER["SCRIPT_FILENAME"]=' . var_export($root . '/index.php', true) . ';'
        . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
        . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
        . 'putenv("JEAB_DISABLE_SQLITE=0");'
        . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
        . 'jeab_bootstrap_load_page_modules();'
        . 'echo implode("\n", get_included_files()), "\n";'
    );
    if (!fs_has($hit, $need)) {
        fs_fail($page . ' saknar ' . $need);
    }
    if (fs_has($hit, 'inkorg_auto.php')) {
        fs_fail($page . ' laddade inkorg_auto');
    }
    if ($page !== 'ordrar' && fs_has($hit, 'ordrar.php')) {
        fs_fail($page . ' laddade ordrar.php');
    }
}
fs_ok('kalender, ordrar, kontakter och inkorg hoppar över inkorg_auto');

putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=0');
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $private;
$GLOBALS['_JEAB_BOOT_ALL'] = true;
require $root . '/src/bootstrap.php';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$_GET = ['p' => 'ordrar', 'co' => 'jarfalla', 'who' => 'all', 'job' => 'sol', 'ws' => (string) time()];
$_SERVER['REQUEST_METHOD'] = 'GET';

$store = jeab_store();
if (!$store instanceof JeabPdoStore) {
    fs_fail('sqlite');
}
$pdo = $store->pdo();
$now = '2026-10-20 10:00:00';
foreach ([
    801 => ['kind' => 'sol', 'customer_name' => 'Sol Testkund', 'name' => 'Sol Testkund'],
    802 => ['kind' => 'laddbox', 'customer_name' => 'Ladd Testkund', 'name' => 'Ladd Testkund'],
] as $id => $extra) {
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'created_at' => $now,
        'updated_at' => $now,
    ] + $extra);
}
jeab_settings_put($pdo, 'sqlite', 'purchases_list_index_v1', 'ok');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_json_v1', 'ok');
jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql');
$GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
jeab_ordrar_list_cache_forget();
$solRows = jeab_ordrar_visible('jarfalla', false, 'sol');
$names = [];
foreach ($solRows as $row) {
    if (is_array($row)) {
        $names[] = (string) ($row['customer_name'] ?? $row['name'] ?? '');
    }
}
if (!in_array('Sol Testkund', $names, true) || in_array('Ladd Testkund', $names, true)) {
    fs_fail('sol-filtret gav ' . implode(',', $names));
}
$lastSql = (string) ($GLOBALS['_JEAB_PURCHASE_LIST_SQL_LAST'] ?? '');
if (!str_contains($lastSql, 'kind =')) {
    fs_fail('sol-filtret saknas i SQL: ' . $lastSql);
}
$state = jeab_filter_from_request('jarfalla', true);
if (($state['job'] ?? null) !== 'sol') {
    fs_fail('job=sol lästes inte: ' . json_encode($state));
}
fs_ok('Ordrar job=sol ligger i SQL och tar inte med laddbox');

$_GET = ['p' => 'bookings', 'co' => 'jarfalla', 'who' => 'Roberto', 'job' => 'all', 'ws' => (string) time()];
$who = jeab_booking_who_filter();
$job = jeab_booking_job_filter();
if ($who !== 'Roberto' || $job !== null) {
    fs_fail('Roberto-filtret blev who=' . (string) $who . ' job=' . (string) $job);
}
$monday = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
$bookings = [
    [
        'id' => 1,
        'customer_name' => 'Roberto Kund',
        'assignees' => ['Roberto'],
        'company' => 'jarfalla',
        'job_type' => 'batteri',
        'starts_at' => $monday . ' 09:00:00',
        'ends_at' => $monday . ' 11:00:00',
        'status' => 'booked',
    ],
    [
        'id' => 2,
        'customer_name' => 'Elin Kund',
        'assignees' => ['Elin'],
        'company' => 'jarfalla',
        'job_type' => 'laddbox',
        'starts_at' => $monday . ' 13:00:00',
        'ends_at' => $monday . ' 15:00:00',
        'status' => 'booked',
    ],
];
$kept = jeab_filter_bookings($bookings, $who, $job);
$keptIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $kept);
if ($keptIds !== [1]) {
    fs_fail('Roberto-filtret behöll ' . implode(',', $keptIds));
}
fs_ok('kalenderns Roberto-filter behåller Roberto och släpper Elin');

$htmlWho = jeab_bookings_url(['who' => 'Roberto', 'job' => 'all']);
if (!str_contains($htmlWho, 'who=Roberto') || !str_contains($htmlWho, 'job=all')) {
    fs_fail('Roberto-länken saknar filtret: ' . $htmlWho);
}
fs_ok('Roberto-länken bär who och job=all så vyn inte blir ett tomt skal av misstag');
