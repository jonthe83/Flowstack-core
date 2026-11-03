<?php

declare(strict_types=1);

/**
 * Två sparningar av olika ordrar ska båda bli kvar.
 * List-GET av Ordrar skriver inte om orderlagret.
 * Påhittad data. Inga hemligheter.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'worker') {
    $dir = (string) ($argv[2] ?? '');
    $id = (int) ($argv[3] ?? 0);
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    putenv('JEAB_DISABLE_SQLITE=1');
    $_ENV['JEAB_DISABLE_SQLITE'] = '1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require dirname(__DIR__) . '/src/bootstrap.php';
    for ($n = 0; $n < 6; $n++) {
        jeab_purchase_put([
            'reminder_id' => $id,
            'kind' => 'laddbox',
            'company' => 'jarfalla',
            'name' => 'Order ' . $id,
            'booking_id' => 5000 + $id,
            'steps' => ['bokad_tid' => '2026-11-02 09:30:00'],
            'updated_at' => '2026-10-18 09:00:00',
        ]);
        usleep(20000);
    }
    jeab_purchase_patch($id, function (array $row) use ($id): array {
        usleep(40000);
        $row['contact_id'] = 'kt_' . $id;
        return $row;
    });
    exit(0);
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'sql-worker') {
    $path = (string) ($argv[2] ?? '');
    $id = (int) ($argv[3] ?? 0);
    $GLOBALS['_JEAB_DATA_DIR'] = dirname($path);
    putenv('JEAB_DISABLE_SQLITE=1');
    $_ENV['JEAB_DISABLE_SQLITE'] = '1';
    require dirname(__DIR__) . '/src/bootstrap.php';
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => $id,
        'kind' => 'batteri',
        'company' => 'jarfalla',
        'name' => 'Sql ' . $id,
        'booking_id' => 7000 + $id,
        'steps' => ['bokad_tid' => '2026-12-01 13:00:00'],
        'updated_at' => '2026-10-18 09:00:00',
    ]);
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-purchase-race-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$_GET = ['p' => 'ordrar', 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/index.php?p=ordrar&co=jarfalla';

$hash = password_hash('ordrar-test-kod', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [['hash' => $hash, 'note' => 'test']],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
], true) . ";\n");
@mkdir($tmp . '/secrets', 0775, true);
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-ordrar-backfill']));

require dirname(__DIR__) . '/src/bootstrap.php';

function pc_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function pc_purchases_raw(): string
{
    $path = jeab_purchase_path();
    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @return array<string, mixed> */
function pc_purchases_disk(): array
{
    $data = json_decode(pc_purchases_raw(), true);
    return is_array($data) ? $data : [];
}

$root = dirname(__DIR__);
$dbSrc = (string) file_get_contents($root . '/src/db.php');
$purchaseSrc = (string) file_get_contents($root . '/src/purchase.php');
$indexSrc = (string) file_get_contents($root . '/index.php');
$ordrarSrc = (string) file_get_contents($root . '/src/ordrar.php');
$kontakterSrc = (string) file_get_contents($root . '/src/kontakter.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$renderAt = strpos($indexSrc, 'function jeab_render_page');
pc_assert($renderAt !== false, 'render-funktionen finns');
$renderSrc = substr($indexSrc, (int) $renderAt);
pc_assert(!str_contains($renderSrc, 'jeab_ordrar_backfill_'), 'Ordrar-vyn anropar inte backfill');
pc_assert(str_contains(substr($indexSrc, 0, (int) $renderAt), 'jeab_ordrar_backfill_vunnen'), 'vunnen-taggen kör backfill');
pc_assert(str_contains($dbSrc, 'FOR UPDATE'), 'MySQL låser raden med FOR UPDATE');
pc_assert(str_contains($dbSrc, 'ON DUPLICATE KEY UPDATE'), 'MySQL upsert är ON DUPLICATE KEY UPDATE');
pc_assert(!preg_match('/DELETE\s+FROM\s+purchases\s*;/', $dbSrc), 'ingen DELETE FROM purchases utan WHERE');
pc_assert(str_contains($dbSrc, 'DELETE FROM purchases WHERE reminder_id = ?'), 'en enstaka order kan fortfarande raderas');
pc_assert(!str_contains($dbSrc, 'jeab_sql_purchases_replace_all'), 'replace-all är borta');
pc_assert(str_contains($purchaseSrc, 'jeab_sql_purchases_get'), 'en order läses med WHERE reminder_id');
pc_assert(str_contains($purchaseSrc, "purchases.json.lock") || str_contains($purchaseSrc, ".lock'"), 'JSON-låset är en egen lock-fil');
pc_assert(str_contains($purchaseSrc, '.tmp'), 'JSON skrivs via tmp');
pc_assert(!str_contains($ordrarSrc, 'jeab_purchase_save_all'), 'namn-backfill skriver inte om hela lagret');
pc_assert(!str_contains($kontakterSrc, 'jeab_purchase_save_all'), 'kontakter använder inte save_all');
pc_assert(str_contains($kontakterSrc, 'jeab_purchase_patch'), 'kontakter skriver en rad');
pc_assert(str_contains($hookSrc, 'ordrar.backfill'), 'hook.php tar emot ordrar.backfill');

$sqlPath = $tmp . '/rows.sqlite';
$sql = new PDO('sqlite:' . $sqlPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql->exec('PRAGMA busy_timeout = 5000');
jeab_ensure_json_doc_tables($sql, 'sqlite');
jeab_sql_purchases_put($sql, [
    'reminder_id' => 1,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Ada Sql',
    'booking_id' => 8801,
    'steps' => ['bokad_tid' => '2026-11-03 08:00:00'],
    'updated_at' => '2026-10-01 08:00:00',
]);
jeab_sql_purchases_put($sql, [
    'reminder_id' => 2,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'name' => 'Bea Sql',
    'booking_id' => 8802,
    'steps' => ['bokad_tid' => '2026-11-04 10:00:00'],
    'updated_at' => '2026-10-01 09:00:00',
]);
$patched = jeab_sql_purchases_patch($sql, 1, function (array $row): array {
    $row['contact_id'] = 'kt_ada';
    return $row;
});
$other = jeab_sql_purchases_get($sql, 2);
pc_assert(is_array($patched) && ($patched['contact_id'] ?? '') === 'kt_ada', 'radlås uppdaterar bara den raden');
pc_assert(is_array($other) && (int) ($other['booking_id'] ?? 0) === 8802 && (($other['steps']['bokad_tid'] ?? '') === '2026-11-04 10:00:00'), 'den andra radens bokningstid består');
pc_assert((int) $sql->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === 2, 'uppdateringen raderar inte övriga rader');
$unchanged = jeab_sql_purchases_patch($sql, 2, function (array $row): ?array {
    unset($row);
    return null;
});
pc_assert(is_array($unchanged) && !isset($unchanged['contact_id']), 'mutator som avstår skriver inte');

$sqlProcs = [];
$sqlPipes = [];
foreach ([3, 4] as $sqlId) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, 'sql-worker', $sqlPath, (string) $sqlId], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    pc_assert(is_resource($proc), 'sql-worker startade');
    fclose($pipes[0]);
    $sqlProcs[$sqlId] = $proc;
    $sqlPipes[$sqlId] = $pipes;
}
foreach ($sqlProcs as $sqlId => $proc) {
    $sqlErr = stream_get_contents($sqlPipes[$sqlId][2]);
    fclose($sqlPipes[$sqlId][1]);
    fclose($sqlPipes[$sqlId][2]);
    $sqlCode = proc_close($proc);
    pc_assert($sqlCode === 0, 'sql-worker ' . $sqlId . ' klar' . ($sqlErr !== '' ? ' ' . trim($sqlErr) : ''));
}
pc_assert((int) $sql->query('SELECT COUNT(*) FROM purchases')->fetchColumn() === 4, 'två samtidiga SQL-sparningar ligger kvar tillsammans med de gamla');
$kept = jeab_sql_purchases_get($sql, 1);
pc_assert(is_array($kept) && (int) ($kept['booking_id'] ?? 0) === 8801 && ($kept['contact_id'] ?? '') === 'kt_ada', 'samtidig insert rör inte den första raden');

$store = new JeabJsonStore($tmp . '/store.json');
$ada = jeab_create_reminder($store, 'Ada Lista', '2026-11-01 00:00:00', 'test', null, [
    'customer' => 'Ada Lista',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1',
]);
$adaId = (int) ($ada['id'] ?? 0);
$closedRem = jeab_create_reminder($store, 'Caj Stangd', '2026-11-01 00:00:00', 'test', null, [
    'customer' => 'Caj Stangd',
    'company' => 'jarfalla',
]);
$closedId = (int) ($closedRem['id'] ?? 0);
$bea = jeab_create_reminder($store, 'Bea Vunnen', '2026-11-02 00:00:00', 'test', null, [
    'customer' => 'Bea Vunnen',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Testgatan 2',
]);
$beaId = (int) ($bea['id'] ?? 0);
pc_assert($adaId > 0 && $closedId > 0 && $beaId > 0, 'påminnelserna skapades');

jeab_purchase_put([
    'reminder_id' => $adaId,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => '',
    'huvudsokande' => 'Ada Lista',
    'personnummer' => '19800101-1234',
    'fastighetsbeteckning' => 'TESTEN 1:1',
    'postnummer' => '18330',
    'address' => 'Testgatan 1',
    'booking_id' => 4242,
    'steps' => ['bokad_tid' => '2026-11-05 09:00:00'],
    'updated_at' => '2026-10-02 08:00:00',
]);
jeab_purchase_put([
    'reminder_id' => $closedId,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => '',
    'huvudsokande' => 'Caj Stangd',
    'closed_at' => '2026-09-01 08:00:00',
    'allt_klart' => true,
    'booking_id' => 4343,
    'steps' => ['bokad_tid' => '2026-11-06 11:00:00'],
    'updated_at' => '2026-09-01 08:00:00',
]);
jeab_purchase_put([
    'reminder_id' => 900,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Kvar Order',
    'booking_id' => 4545,
    'steps' => ['bokad_tid' => '2026-11-07 14:00:00'],
    'updated_at' => '2026-10-03 08:00:00',
]);
jeab_purchase_cache_forget();

$before = pc_purchases_raw();
$dry = jeab_ordrar_backfill_run($store, []);
pc_assert(($dry['dry'] ?? 0) === 1, 'ordrar.backfill är torr som standard');
pc_assert((int) ($dry['names']['updated'] ?? 0) >= 1, 'torrkörning räknar namn som skulle fyllas');
pc_assert((int) ($dry['names']['skipped_closed'] ?? 0) >= 1, 'torrkörning hoppar över stängd order');
pc_assert((int) ($dry['vunnen']['created'] ?? 0) >= 1, 'torrkörning räknar vunnen utan order');
pc_assert(pc_purchases_raw() === $before, 'torrkörning skriver inte');
pc_assert(jeab_purchase_get($beaId) === null, 'torrkörning skapar ingen order');

$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_TEST_DATA');\nputenv('JEAB_DISABLE_SQLITE=1');\n\$_ENV['JEAB_DISABLE_SQLITE'] = '1';\n\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');\nif (\$uri === '/hook.php') {\n    require " . var_export($root . '/hook.php', true) . ";\n    return true;\n}\nrequire " . var_export($root . '/index.php', true) . ";\nreturn true;\n");
$log = $tmp . '/server.log';
$probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
pc_assert(is_resource($probe), 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$server = proc_open([
    PHP_BINARY,
    '-d', 'display_errors=0',
    '-S', '127.0.0.1:' . $port,
    '-t', $root,
    $router,
], [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $log, 'a'],
    2 => ['file', $log, 'a'],
], $serverPipes, $root, [
    'JEAB_TEST_DATA' => $tmp,
    'JEAB_DISABLE_SQLITE' => '1',
]);
pc_assert(is_resource($server), 'testservern startade');
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
pc_assert($up, 'testservern lyssnar');

jeab_auth_data(true);
$cookie = jeab_auth_issue_value(null, $hash);
pc_assert(is_string($cookie) && $cookie !== '', 'testkaka');

$fetch = static function (string $url, array $headers = []) use ($cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Cookie: ' . JEAB_AUTH_COOKIE . '=' . $cookie], $headers),
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
};

$list = $fetch('http://127.0.0.1:' . $port . '/index.php?p=ordrar&co=jarfalla');
pc_assert($list['status'] === 200 && str_contains($list['body'], 'Ada Lista'), 'listan ritar Ada, status ' . $list['status']);
pc_assert(!str_contains($list['body'], 'Bea Vunnen'), 'vunnen utan order syns inte på list-GET');
pc_assert(pc_purchases_raw() === $before, 'list-GET skriver inte i orderlagret');

jeab_purchase_cache_forget();
$namesLive = jeab_ordrar_backfill_names($store, false);
pc_assert((int) ($namesLive['updated'] ?? 0) >= 1 && (int) ($namesLive['skipped_closed'] ?? 0) >= 1, 'namn-backfill skriver bara öppna rader');
jeab_purchase_cache_forget();
$adaNamed = jeab_purchase_get($adaId);
$closedNamed = jeab_purchase_get($closedId);
pc_assert(is_array($adaNamed) && ($adaNamed['name'] ?? '') === 'Ada Lista' && (int) ($adaNamed['booking_id'] ?? 0) === 4242, 'öppen order får namn och behåller bokningen');
pc_assert((string) ($adaNamed['steps']['bokad_tid'] ?? '') === '2026-11-05 09:00:00', 'namnfyllning rör inte bokad tid');
pc_assert(is_array($closedNamed) && trim((string) ($closedNamed['name'] ?? '')) === '' && (int) ($closedNamed['booking_id'] ?? 0) === 4343, 'stängd order lämnas');
pc_assert(jeab_purchase_get($beaId) === null, 'namn-backfill skapar ingen order');
$dan = jeab_create_reminder($store, 'Dan Sparad', '2026-11-08 00:00:00', 'test', null, [
    'customer' => 'Dan Sparad',
    'company' => 'jarfalla',
]);
$danId = (int) ($dan['id'] ?? 0);
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
jeab_purchase_put([
    'reminder_id' => $danId,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => '',
    'booking_id' => 4646,
    'steps' => ['bokad_tid' => '2026-11-08 15:00:00'],
    'updated_at' => '2026-10-04 08:00:00',
]);
jeab_purchase_cache_forget();
$danRow = jeab_purchase_get($danId);
pc_assert(is_array($danRow) && ($danRow['name'] ?? '') === 'Dan Sparad' && (int) ($danRow['booking_id'] ?? 0) === 4646 && (($danRow['steps']['bokad_tid'] ?? '') === '2026-11-08 15:00:00'), 'sparning fyller tomt namn och lämnar bokningstiden');

$open = $fetch('http://127.0.0.1:' . $port . '/index.php?p=ordrar&co=jarfalla&id=' . $adaId);
pc_assert($open['status'] === 200 && str_contains($open['body'], 'Ada Lista'), 'öppnad order ritar Ada');
$disk = pc_purchases_disk();
pc_assert((int) ($disk[(string) $adaId]['booking_id'] ?? 0) === 4242, 'öppnad order behåller booking_id');
pc_assert((string) ($disk[(string) $adaId]['steps']['bokad_tid'] ?? '') === '2026-11-05 09:00:00', 'öppnad order behåller bokad tid');
pc_assert((int) ($disk['900']['booking_id'] ?? 0) === 4545, 'en annan order finns kvar efter att en order öppnats');
pc_assert(!isset($disk[(string) $beaId]), 'öppnad order skapar inte den vunna påminnelsens order');

$ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['action' => 'ordrar.backfill']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 20,
]);
$deniedBody = curl_exec($ch);
$deniedStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
pc_assert($deniedStatus === 401, 'ordrar.backfill utan token är 401, fick ' . $deniedStatus);
$ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['action' => 'ordrar.backfill']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer tok-ordrar-backfill'],
    CURLOPT_TIMEOUT => 20,
]);
$hookBody = curl_exec($ch);
$hookStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$hookJson = is_string($hookBody) ? json_decode($hookBody, true) : null;
pc_assert($hookStatus === 200 && is_array($hookJson) && (int) ($hookJson['dry'] ?? 0) === 1, 'hook ordrar.backfill dry=1 som standard');
pc_assert(is_string($hookBody) && !str_contains($hookBody, '19800101'), 'hooksvar innehåller inte personnummer');

jeab_purchase_cache_forget();
$live = jeab_ordrar_backfill_run($store, ['dry' => 0]);
pc_assert((int) ($live['dry'] ?? 1) === 0 && (int) ($live['vunnen']['created'] ?? 0) >= 1, 'dry=0 skapar order för vunnen påminnelse');
jeab_purchase_cache_forget();
$adaAfter = jeab_purchase_get($adaId);
$closedAfter = jeab_purchase_get($closedId);
$beaOrder = jeab_purchase_get($beaId);
$keptOrder = jeab_purchase_get(900);
pc_assert(is_array($adaAfter) && ($adaAfter['name'] ?? '') === 'Ada Lista' && (int) ($adaAfter['booking_id'] ?? 0) === 4242, 'namnet består efter vunnen-backfill');
pc_assert(is_array($closedAfter) && trim((string) ($closedAfter['name'] ?? '')) === '', 'stängd order är fortfarande utan namnfyllning');
pc_assert(is_array($beaOrder) && (int) ($beaOrder['reminder_id'] ?? 0) === $beaId, 'vunnen påminnelse blir en order när backfill körs');
pc_assert(is_array($keptOrder) && (int) ($keptOrder['booking_id'] ?? 0) === 4545 && (($keptOrder['steps']['bokad_tid'] ?? '') === '2026-11-07 14:00:00'), 'orörd order behåller sin tid');

$procs = [];
$pipesById = [];
foreach ([901, 902] as $id) {
    $pipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $tmp, (string) $id], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    pc_assert(is_resource($proc), 'worker startade');
    fclose($pipes[0]);
    $procs[$id] = $proc;
    $pipesById[$id] = $pipes;
}
foreach ($procs as $id => $proc) {
    $err = stream_get_contents($pipesById[$id][2]);
    fclose($pipesById[$id][1]);
    fclose($pipesById[$id][2]);
    $code = proc_close($proc);
    pc_assert($code === 0, 'worker ' . $id . ' klar' . ($err !== '' ? ' ' . trim($err) : ''));
}
jeab_purchase_cache_forget();
$final = pc_purchases_disk();
pc_assert((int) ($final['901']['booking_id'] ?? 0) === 5901 && ($final['901']['contact_id'] ?? '') === 'kt_901', 'order 901 sparades');
pc_assert((int) ($final['902']['booking_id'] ?? 0) === 5902 && ($final['902']['contact_id'] ?? '') === 'kt_902', 'order 902 sparades');
pc_assert((int) ($final['900']['booking_id'] ?? 0) === 4545 && (($final['900']['steps']['bokad_tid'] ?? '') === '2026-11-07 14:00:00'), 'samtidiga sparningar raderar inte en annan order');
pc_assert(is_array($final[(string) $beaId] ?? null), 'den nya vunna ordern finns kvar efter samtidiga sparningar');
$tmpLeft = glob(jeab_purchase_path() . '.*.tmp') ?: [];
pc_assert($tmpLeft === [], 'inga tmp-filer lämnas kvar');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

echo "ordrar purchase concurrent: ok\n";
