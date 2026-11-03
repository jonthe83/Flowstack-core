<?php

declare(strict_types=1);

/**
 * Listan byggs om från SQL-fingerprint, inte filens mtime.
 * Två counts-anrop tar inte båda ombyggnaden. En trådskrivning bygger inte om.
 * Kontaktschemat kör DDL högst en gång.
 */

$mode = $argv[1] ?? '';
$root = dirname(__DIR__);
$tmp = getenv('JEAB_LOCK_TMP');
if (!is_string($tmp) || $tmp === '') {
    if ($mode !== '') {
        fwrite(STDERR, "FAIL barnprocess saknar JEAB_LOCK_TMP\n");
        exit(1);
    }
    $tmp = sys_get_temp_dir() . '/jeab-listlock-' . bin2hex(random_bytes(4));
    putenv('JEAB_LOCK_TMP=' . $tmp);
    mkdir($tmp . '/inkorg', 0775, true);
    mkdir($tmp . '/secrets', 0775, true);
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
        ],
    ], true) . ";\n");
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';

function ll(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ll_next_request(): void
{
    static $n = 0;
    $n++;
    $_SERVER['REQUEST_TIME_FLOAT'] = 'll-req-' . $n;
}

if ($mode === 'hold') {
    $name = (string) ($argv[2] ?? 'jeab_inkorg_list');
    $pdo = jeab_pdo_connect_sqlite();
    if (!jeab_db_get_lock($pdo, $name)) {
        fwrite(STDERR, "lock-failed\n");
        exit(1);
    }
    file_put_contents($tmp . '/ready-' . $name, '1');
    sleep(15);
    exit(0);
}

if ($mode === 'worker') {
    $store = jeab_inkorg_pdo_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        fwrite(STDERR, "no-store\n");
        exit(1);
    }
    $GLOBALS['_JEAB_INKORG_PART'] = 'counts';
    $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
    jeab_inkorg_sql_list_sync($store, $tmp . '/inkorg/messages.json|1:2');
    $slot = (string) ($argv[2] ?? '0');
    file_put_contents($tmp . '/worker-' . $slot, (string) (int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD']);
    exit(0);
}

final class JeabSchemaProbe extends PDO
{
    public int $schema = 0;

    public int $begins = 0;

    public function beginTransaction(): bool
    {
        $this->begins++;

        return parent::beginTransaction();
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/\b(CREATE|ALTER|DROP|RENAME)\b/i', $statement) === 1) {
            $this->schema++;
        }

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match('/PRAGMA\s+table_info|sqlite_master|SHOW\s+COLUMNS/i', $query) === 1) {
            $this->schema++;
        }
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function ll_hold(string $name): array
{
    global $tmp;
    @unlink($tmp . '/ready-' . $name);
    $errFile = $tmp . '/err-hold-' . $name;
    $pipes = [];
    $proc = proc_open(
        [PHP_BINARY, __FILE__, 'hold', $name],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $errFile, 'w']],
        $pipes
    );
    ll(is_resource($proc), 'hållprocessen startade för ' . $name);
    $start = microtime(true);
    while (!is_file($tmp . '/ready-' . $name) && microtime(true) - $start < 10) {
        usleep(50000);
    }
    $err = is_file($errFile) ? (string) file_get_contents($errFile) : '';
    ll(is_file($tmp . '/ready-' . $name), 'låset ' . $name . ' är taget ' . trim($err));

    return [$proc, $pipes];
}

function ll_worker_start(string $slot)
{
    global $tmp;
    @unlink($tmp . '/worker-' . $slot);
    $pipes = [];
    $proc = proc_open(
        [PHP_BINARY, __FILE__, 'worker', $slot],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $tmp . '/err-worker-' . $slot, 'w']],
        $pipes
    );
    ll(is_resource($proc), 'worker startade');

    return $proc;
}

function ll_worker_finish($proc, string $slot): string
{
    global $tmp;
    $code = is_resource($proc) ? proc_close($proc) : 1;
    $err = is_file($tmp . '/err-worker-' . $slot) ? (string) file_get_contents($tmp . '/err-worker-' . $slot) : '';
    $out = is_file($tmp . '/worker-' . $slot) ? trim((string) file_get_contents($tmp . '/worker-' . $slot)) : '';
    ll($code === 0 && $out !== '', 'worker avslutade rent ' . trim($err) . ' ut=' . $out);

    return $out;
}

function ll_release(array $held): void
{
    [$proc, $pipes] = $held;
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
}

require_once $root . '/src/kontakt_store.php';
require_once $root . '/src/purchase_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';

$pdo = jeab_pdo_connect_sqlite();
ll(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
ll($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$fast = (string) file_get_contents($root . '/src/inkorg_fast.php');
ll(str_contains($fast, "jeab_db_get_lock(\$store->pdo(), 'jeab_inkorg_list')"), 'ombyggnaden tar GET_LOCK jeab_inkorg_list');
$db = (string) file_get_contents($root . '/src/db.php');
ll(str_contains($db, 'SELECT GET_LOCK(') && str_contains($db, ', 0)'), 'MySQL-låset är GET_LOCK med timeout 0');
$storeSrc = (string) file_get_contents($root . '/src/inkorg_store.php');
$replace = substr($storeSrc, (int) strpos($storeSrc, 'function replaceListRows'), 900);
ll(!str_contains($replace, 'inkorg_count_cache'), 'replaceListRows rör inte räknarcachen');
$hook = (string) file_get_contents($root . '/hook.php');
ll(str_contains($hook, "\$jeabHookAction === 'sys.health'"), 'sys.health finns på kroken');

for ($i = 1; $i <= 2; $i++) {
    $id = 'c' . $i;
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'kund' . $i . '@exempel.test',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Lås ' . $id,
        'body' => 'Kort ' . $i,
        'received_at' => sprintf('2024-08-%02dT10:00:00+00:00', $i),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support', 'product' => 'batteri'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => sprintf('2024-08-%02dT10:00:00+00:00', $i),
    ]);
}
$store->clearListStamp();
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":{}}');
clearstatcache();
$beforeFp = $store->listFingerprint();
touch($tmp . '/inkorg/messages.json', time() + 30);
touch($tmp . '/inkorg/threads.json', time() + 30);
clearstatcache();
ll($store->listFingerprint() === $beforeFp, 'filens mtime ändrar inte fingerprint');
ll(!str_contains($beforeFp, 'messages.json') && str_starts_with($beforeFp, 'sql|'), 'fingerprint kommer från SQL');
ll(jeab_inkorg_messages_fp() . '|' . jeab_inkorg_threads_fp() === $beforeFp, 'stämpelformatet är oförändrat');

$store->countCachePut('v-keep', ['counts' => ['v-inkorg' => 1], 'unread' => 0, 'sources' => [], 'at' => time()]);
$held = ll_hold('jeab_inkorg_list');
$procA = ll_worker_start('a');
$procB = ll_worker_start('b');
$a = ll_worker_finish($procA, 'a');
$b = ll_worker_finish($procB, 'b');
ll($a === '0' && $b === '0', 'två parallella counts bygger inte om medan låset är taget, fick ' . $a . ' och ' . $b);
ll_release($held);
usleep(200000);

$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
$GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
jeab_inkorg_sql_list_sync($store, $tmp . '/inkorg/messages.json|9:9');
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === 1, 'ett counts-anrop bygger om när låset är ledigt');
ll($store->listStamp() === $store->listFingerprint(), 'stämpeln följer SQL-fingerprint');
ll(!str_contains($store->listStamp(), 'messages.json'), 'anroparens filmtime sparas inte');
ll($store->countCacheGet('v-keep', 60) !== null, 'ombyggnaden behåller räknarcachen');
$rebuilds = (string) jeab_settings_value($pdo, 'sqlite', 'inkorg_list_rebuilds');
ll(substr_count($rebuilds, ',') === 0 && $rebuilds !== '', 'en ombyggnad loggas');

$rebuild = (int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'];
$store->upsertThread('c1', [
    'status' => 'open',
    'tags' => [],
    'assignee' => 'Elin',
    'read' => [],
    'company' => 'jarfalla',
    'updated_at' => '2024-09-01T12:00:00+00:00',
]);
jeab_inkorg_sql_list_sync($store, 'ignored');
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === $rebuild, 'en trådskrivning orsakar inte en full ombyggnad');
ll($store->listStamp() === $store->listFingerprint(), 'trådskrivningen flyttar stämpeln');
$listed = $store->listForCompany('jarfalla');
$hit = null;
foreach ($listed as $row) {
    if (is_array($row) && (string) ($row['id'] ?? '') === 'c1') {
        $hit = $row;
    }
}
ll(is_array($hit) && (string) ($hit['assignee'] ?? '') === 'Elin', 'listraden uppdateras utan ombyggnad');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'egna', 'ix_part' => 'counts'];
$_SERVER['REQUEST_METHOD'] = 'GET';
jeab_inkorg_fast_counts_refresh();
$rebuild = (int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'];
$pdo->prepare('UPDATE inkorg_threads SET updated_at = ? WHERE id = ?')->execute(['2030-01-01 00:00:00', 'c2']);
jeab_inkorg_fast_counts_refresh();
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === $rebuild, 'ix_part=counts inom 60 s bygger inte om');
$rows = $pdo->query("SELECT cache_key, payload FROM inkorg_count_cache WHERE cache_key NOT IN ('__stamp', '__sources')")->fetchAll(PDO::FETCH_ASSOC);
ll($rows !== [], 'räknarcachen finns');
$upd = $pdo->prepare('UPDATE inkorg_count_cache SET stored_at = ?, payload = ? WHERE cache_key = ?');
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $data = json_decode((string) ($row['payload'] ?? ''), true);
    if (!is_array($data)) {
        continue;
    }
    $data['at'] = time() - 120;
    $upd->execute([time() - 120, json_encode($data), (string) $row['cache_key']]);
}
$aged = jeab_inkorg_fast_counts_refresh();
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === $rebuild, 'gammal cache räknar om från listan utan att bygga om');
ll((int) (($aged['counts'] ?? [])['v-alla'] ?? 0) >= 1, 'omräkningen lämnar inte vykorten tomma');

$pdo->exec('DELETE FROM inkorg_list');
$store->clearListStamp();
ll($store->listStamp() === '', 'stämpeln är tom inför låstestet');
ll((int) $pdo->query('SELECT COUNT(*) FROM inkorg_list')->fetchColumn() === 0, 'listtabellen är tom');
$store->countCachePut('v-fresh-empty', ['counts' => ['v-alla' => 0], 'unread' => 0, 'sources' => [], 'at' => time()]);
$held = ll_hold('jeab_inkorg_list');
$GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = 0;
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC'], $GLOBALS['_JEAB_IX_COMPUTE']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-alla', 'scope' => 'allas'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$t0 = microtime(true);
$painted = jeab_inkorg_fast_prepare(true, '');
$waited = microtime(true) - $t0;
ll($waited < 2, 'sidan med tom stämpel och hållet lås återvänder direkt, ' . round($waited, 3) . ' s');
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === 0, 'hållet lås bygger inte om');
ll((string) ($GLOBALS['_JEAB_INKORG_LIST_SYNC'] ?? '') === 'skip', 'sidan tar inte ombyggnadslåset');
ll($store->listStamp() === '', 'stämpeln skrivs inte när låset är taget');
ll((int) $pdo->query('SELECT COUNT(*) FROM inkorg_list')->fetchColumn() === 0, 'sidan skriver inte inkorg_list');
ll(!str_contains((string) ($painted['html'] ?? ''), 'data-ix-id="c1"'), 'sidan läser inte om alla mejl när listan saknas');
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC']);
$_GET['ix_part'] = 'counts';
$t1 = microtime(true);
$bag = jeab_inkorg_fast_counts_refresh();
$waitedCounts = microtime(true) - $t1;
ll($waitedCounts < 2, 'counts med tom stämpel och hållet lås återvänder direkt, ' . round($waitedCounts, 3) . ' s');
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === 0, 'counts bygger inte om medan låset är taget');
ll((int) (($bag['counts'] ?? [])['v-alla'] ?? 0) >= 1, 'counts räknar från mejlen när listan saknas');
ll_release($held);
usleep(200000);
unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_LIST_SYNC']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-alla', 'scope' => 'allas'];
jeab_inkorg_fast_prepare(true, '');
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === 0, 'ledig lås och tom stämpel bygger inte listan i sidträffen');
$_GET['ix_part'] = 'counts';
jeab_inkorg_fast_counts_refresh();
ll((int) $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] === 1, 'counts bygger listan en gång när låset är ledigt');
ll($store->listStamp() === $store->listFingerprint(), 'första ombyggnaden sätter stämpeln');
ll((int) $pdo->query('SELECT COUNT(*) FROM inkorg_list')->fetchColumn() >= 1, 'ombyggnaden fyller inkorg_list');

$probe = new JeabSchemaProbe('sqlite:' . $tmp . '/app.sqlite');
$probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute(['contacts_schema_v1']);
$before = $probe->schema;
ll_next_request();
jeab_ensure_contacts_tables($probe, 'sqlite');
$mid = $probe->schema;
ll_next_request();
jeab_ensure_contacts_tables($probe, 'sqlite');
ll($mid > $before, 'första schemakörningen gör DDL');
ll($probe->schema === $mid, 'ensure_contacts_tables kör DDL högst en gång');
ll(jeab_settings_value($pdo, 'sqlite', 'contacts_schema_v1') === 'ok', 'contacts_schema_v1 är satt');

$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, created_at, updated_at, list_ready) VALUES (?, ?, ?, ?, 0)');
$now = '2026-10-22 00:00:00';
for ($i = 1; $i <= 450; $i++) {
    $id = sprintf('c%04d', $i);
    $ins->execute([$id, json_encode(['id' => $id, 'email' => 'p' . $i . '@exempel.test']), $now, $now]);
}
jeab_settings_put($pdo, 'sqlite', 'contact_emails_v1', '');
jeab_settings_put($pdo, 'sqlite', 'contact_emails_cursor', '');
$held = ll_hold('jeab_contact_emails');
ll_next_request();
jeab_ensure_contact_emails($pdo, 'sqlite');
ll((string) jeab_settings_value($pdo, 'sqlite', 'contact_emails_cursor') === '', 'e-postfyllning hoppar över när låset är taget');
ll_release($held);
usleep(200000);
ll_next_request();
jeab_ensure_contact_emails($pdo, 'sqlite');
ll((string) jeab_settings_value($pdo, 'sqlite', 'contact_emails_cursor') === 'c0400', 'första biten stannar vid 400');
ll(jeab_settings_value($pdo, 'sqlite', 'contact_emails_v1') !== 'ok', 'flaggan väntar tills bitarna är klara');
ll((int) $pdo->query('SELECT COUNT(*) FROM contact_emails')->fetchColumn() === 400, '400 adresser är indexerade');
ll_next_request();
jeab_ensure_contact_emails($pdo, 'sqlite');
ll(jeab_settings_value($pdo, 'sqlite', 'contact_emails_v1') === 'ok', 'sista biten sätter flaggan');
ll((int) $pdo->query('SELECT COUNT(*) FROM contact_emails')->fetchColumn() === 450, 'resten indexeras utan att börja om');

jeab_settings_put($pdo, 'sqlite', 'contacts_list_cols_v1', '');
$ready = (int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE list_ready = 1')->fetchColumn();
$held = ll_hold('jeab_contacts_list_backfill');
ll_next_request();
jeab_contacts_list_backfill($pdo);
ll((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE list_ready = 1')->fetchColumn() === $ready, 'listbackfill hoppar över när låset är taget');
ll_release($held);
usleep(200000);
$begins = $probe->begins;
ll_next_request();
jeab_contacts_list_backfill($probe);
ll($probe->begins === $begins + 1, 'listbackfill är en transaktion');
ll((int) $pdo->query('SELECT COUNT(*) FROM contacts WHERE list_ready = 0')->fetchColumn() === 50, 'listbackfill tar 400 rader');

jeab_purchases_store_ensure($pdo);
jeab_settings_put($pdo, 'sqlite', 'purchases_contact_id_v1', '');
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_indexed) VALUES (?, ?, ?, 0)')->execute([
    1,
    json_encode(['contact_id' => 'c0001']),
    $now,
]);
$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_indexed) VALUES (?, ?, ?, 0)')->execute([
    2,
    json_encode(['contact_id' => 'c0002']),
    $now,
]);
$held = ll_hold('jeab_purchases_contact_index');
ll_next_request();
jeab_purchases_contact_index_backfill($pdo);
ll((int) $pdo->query('SELECT COUNT(*) FROM purchases WHERE contact_indexed = 1')->fetchColumn() === 0, 'orderbackfill hoppar över när låset är taget');
ll_release($held);
usleep(200000);
$begins = $probe->begins;
ll_next_request();
jeab_purchases_contact_index_backfill($probe);
ll($probe->begins === $begins + 1, 'orderbackfill är en transaktion');
ll((int) $pdo->query('SELECT COUNT(*) FROM purchases WHERE contact_indexed = 1')->fetchColumn() === 2, 'orderbackfill skriver contact_id');

$pdo->prepare('INSERT INTO data_jobs (job_id, job_type, status, progress_json, state_json, result_json, payload_json, heartbeat_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
    'job-health',
    'data.migrate_all',
    'running',
    '{}',
    '{}',
    null,
    '{}',
    '2026-10-22 03:00:00',
    '2026-10-22 03:00:00',
    '2026-10-22 03:00:00',
]);
$pdo->prepare('INSERT INTO data_job_locks (job_type, job_id, heartbeat_at) VALUES (?, ?, ?)')->execute([
    'data.migrate_all',
    'job-health',
    '2026-10-22 03:00:00',
]);
$beforeSettings = (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
$health = jeab_sys_health();
ll(($health['ok'] ?? false) === true && ($health['read_only'] ?? false) === true, 'sys.health är läsning');
ll((int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === $beforeSettings, 'sys.health skriver inte settings');
ll(($health['flags']['contacts_schema'] ?? null) === 'ok', 'sys.health visar contacts_schema');
ll(array_key_exists('contact_emails_v1', (array) ($health['flags'] ?? [])) && array_key_exists('contacts_list_cols_v1', (array) ($health['flags'] ?? [])), 'sys.health visar e-post- och listflaggan');
ll((string) (($health['inkorg_list']['stamp'] ?? '')) !== '' && ($health['inkorg_list']['fingerprint'] ?? '') === ($health['inkorg_list']['stamp'] ?? null), 'sys.health jämför stämpel och fingerprint');
ll((int) ($health['rebuilds_last_hour'] ?? 0) >= 1, 'sys.health räknar ombyggnader senaste timmen');
$running = (array) (($health['data_jobs']['running'] ?? []));
$locks = (array) (($health['data_jobs']['locks'] ?? []));
ll(isset($running[0]['job_id']) && (string) $running[0]['job_id'] === 'job-health', 'sys.health visar körande data_jobs');
ll(isset($locks[0]['job_id']) && (string) $locks[0]['job_id'] === 'job-health', 'sys.health visar data_job_locks');

echo "OK  klart\n";
