<?php

declare(strict_types=1);

/**
 * Vanligt anrop ska inte köra SHOW INDEX eller CREATE när ensure-flaggan är ok.
 * MySQL-index skapas utan IF NOT EXISTS. Påhittad sqlite, ingen kundrad.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ddl-request-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ddl_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ddl_schema_sql(array $log): bool
{
    foreach ($log as $sql) {
        if (preg_match('/\b(SHOW\s+INDEX|SHOW\s+COLUMNS|CREATE\s+|ALTER\s+TABLE|PRAGMA\s+table_info|sqlite_master)\b/i', (string) $sql) === 1) {
            return true;
        }
    }

    return false;
}

function ddl_fn_body(string $src, string $name): string
{
    $at = strpos($src, 'function ' . $name);
    if ($at === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $at + 10);

    return $next === false ? substr($src, $at) : substr($src, $at, $next - $at);
}

$mysql = jeab_create_index_sql('mysql', 'purchases_contact_indexed', 'purchases (contact_indexed)');
ddl_ok($mysql === 'CREATE INDEX purchases_contact_indexed ON purchases (contact_indexed)', 'mysql-index saknar IF NOT EXISTS');
ddl_ok(!str_contains($mysql, 'IF NOT EXISTS'), 'mysql-strängen innehåller inte IF NOT EXISTS');
$sqliteSql = jeab_create_index_sql('sqlite', 'purchases_contact_indexed', 'purchases (contact_indexed)');
ddl_ok(str_contains($sqliteSql, 'CREATE INDEX IF NOT EXISTS '), 'sqlite behåller IF NOT EXISTS');

$dbSrc = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$openBody = ddl_fn_body($dbSrc, 'jeab_inkorg_ensure_open_indexes');
ddl_ok($openBody !== '' && !str_contains($openBody, 'CREATE INDEX IF NOT EXISTS'), 'öppna-index bygger SQL via drivern');
$hsSrc = (string) file_get_contents(dirname(__DIR__) . '/src/hs_import_store.php');
ddl_ok(!str_contains(ddl_fn_body($hsSrc, 'jeab_hs_import_ensure_from_index'), 'IF NOT EXISTS'), 'Help Scout from-index har ingen IF NOT EXISTS');
ddl_ok(!str_contains(ddl_fn_body($hsSrc, 'jeab_hs_import_ensure_list_page'), 'IF NOT EXISTS'), 'Help Scout sidindex har ingen IF NOT EXISTS');
ddl_ok(!str_contains(ddl_fn_body($hsSrc, 'jeab_hs_import_create_index'), 'IF NOT EXISTS'), 'Help Scout-hjälparen har ingen IF NOT EXISTS');
$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
ddl_ok(!str_contains($indexSrc, 'set_time_limit'), 'index.php höjer inte tidsgränsen');
$tickSrc = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
ddl_ok(str_contains(ddl_fn_body($tickSrc, 'jeab_tick_maintenance'), 'set_time_limit(120)'), 'tick höjer tidsgränsen kring indexen');
$purchaseSrc = (string) file_get_contents(dirname(__DIR__) . '/src/purchase_store.php');
ddl_ok(!preg_match("/'purchases_[^']+'\\s*=>\\s*\\[\\s*'company'\\s*,\\s*'klart'\\s*,\\s*'created_at'\\s*\\]/", $purchaseSrc), 'listindexet med created_at väntar');

$store = jeab_store();
ddl_ok($store instanceof JeabPdoStore, 'sqlite-butiken är öppen');
$pdo = $store->pdo();
jeab_purchases_store_ensure($pdo);
jeab_ensure_inkorg_tables($pdo, 'sqlite');
if (function_exists('jeab_hs_import_store_ensure')) {
    jeab_hs_import_store_ensure($pdo);
}
$names = jeab_existing_index_names($pdo, 'purchases', 'sqlite');
ddl_ok(in_array('purchases_contact_indexed', $names, true), 'contact_indexed har index');
ddl_ok(jeab_settings_value($pdo, 'sqlite', 'purchases_ddl_v1') === 'ok', 'purchases-flaggan är ok');
ddl_ok(jeab_settings_value($pdo, 'sqlite', 'inkorg_ddl_v3') === 'ok', 'inkorg-flaggan är ok');
ddl_ok(jeab_settings_value($pdo, 'sqlite', 'hs_import_ddl_v1') === 'ok', 'import-flaggan är ok');

jeab_sql_stats_reset();
jeab_purchases_store_ensure($pdo);
jeab_ensure_inkorg_tables($pdo, 'sqlite');
jeab_hs_import_store_ensure($pdo);
jeab_inkorg_ensure_open_indexes($pdo, 'sqlite');
jeab_inkorg_ensure_list_status($pdo, 'sqlite');
ddl_ok(!ddl_schema_sql(jeab_sql_stats()['log']), 'andra anropet i processen skapar inget och visar inget index');

$fresh = new JeabTracePdo('sqlite:' . $tmp . '/app.sqlite', null, null, jeab_pdo_base_options());
jeab_sql_stats_reset();
jeab_purchases_store_ensure($fresh);
jeab_ensure_inkorg_tables($fresh, 'sqlite');
jeab_hs_import_store_ensure($fresh);
ddl_ok(!ddl_schema_sql(jeab_sql_stats()['log']), 'ny anslutning med flaggan ok gör ingen SHOW eller CREATE');

$pdo->exec('DROP INDEX IF EXISTS purchases_contact_indexed');
$pdo->exec('DROP INDEX IF EXISTS inkorg_messages_thread_date');
foreach (['purchases_ddl_v1', 'inkorg_ddl_v3', 'inkorg_open_indexes_v1', 'inkorg_list_status_v1', 'hs_import_ddl_v1'] as $key) {
    $pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute([$key]);
}
unset($GLOBALS['_JEAB_MIGRATE_RUNS']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$page = new JeabTracePdo('sqlite:' . $tmp . '/app.sqlite', null, null, jeab_pdo_base_options());
jeab_sql_stats_reset();
jeab_purchases_store_ensure($page);
jeab_ensure_inkorg_tables($page, 'sqlite');
jeab_hs_import_store_ensure($page);
jeab_inkorg_ensure_list_status($page, 'sqlite');
ddl_ok(!ddl_schema_sql(jeab_sql_stats()['log']), 'ett kontorsklick skapar inget index');
$left = jeab_existing_index_names($pdo, 'purchases', 'sqlite');
ddl_ok(!in_array('purchases_contact_indexed', $left, true), 'klicket lämnar det borttagna orderindexet');
$msgLeft = jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite');
ddl_ok(!in_array('inkorg_messages_thread_date', $msgLeft, true), 'klicket lämnar det borttagna mejlindexet');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
$GLOBALS['_JEAB_SCHEMA_DDL'] = true;
$cli = new JeabTracePdo('sqlite:' . $tmp . '/app.sqlite', null, null, jeab_pdo_base_options());
jeab_purchases_store_ensure($cli);
jeab_ensure_inkorg_tables($cli, 'sqlite');
jeab_hs_import_store_ensure($cli);
$back = jeab_existing_index_names($pdo, 'purchases', 'sqlite');
ddl_ok(in_array('purchases_contact_indexed', $back, true), 'engångskörningen skapar orderindexet');
$msgBack = jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite');
ddl_ok(in_array('inkorg_messages_thread_date', $msgBack, true), 'engångskörningen skapar mejlindexet');
ddl_ok(jeab_settings_value($cli, 'sqlite', 'purchases_ddl_v1') === 'ok', 'engångskörningen sätter purchases-flaggan');
$purchaseCols = [];
foreach ($pdo->query('PRAGMA table_info(purchases)') as $col) {
    $purchaseCols[] = (string) ($col['name'] ?? '');
}
ddl_ok(in_array('list_json', $purchaseCols, true), 'purchases har list_json');
jeab_sql_purchases_put($pdo, [
    'reminder_id' => 919001,
    'contact_id' => 'c919',
    'kind' => 'sol',
    'company' => 'jarfalla',
    'customer_name' => 'DDL Test',
]);
$savedList = $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 919001')->fetchColumn();
ddl_ok(is_string($savedList) && str_contains($savedList, 'c919'), 'sparning skriver list_json');
$stamped = jeab_contacts_sole_stamp_order_sql($pdo, 919001, 'c920', 'c919');
$stampedList = $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 919001')->fetchColumn();
ddl_ok($stamped === true && is_string($stampedList) && str_contains($stampedList, 'c920'), 'omlänk uppdaterar list_json');
ddl_ok(is_string($stampedList) && !str_contains($stampedList, 'c919'), 'omlänk tar bort gamla kontakten ur list_json');
require_once dirname(__DIR__) . '/src/reminders_dedupe.php';
jeab_sql_purchases_put($pdo, [
    'reminder_id' => 919003,
    'contact_id' => 'c919',
    'kind' => 'sol',
    'company' => 'jarfalla',
]);
ddl_ok(jeab_reminders_dedupe_rekey_order($pdo, 919003, 919004) === true, 'dedupe byter orderrad');
$rekeyed = $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 919004')->fetchColumn();
ddl_ok(is_string($rekeyed) && str_contains($rekeyed, '919004'), 'dedupe skriver om list_json');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_LIST_INDEX_FAIL']);
$emptyPath = $tmp . '/empty.sqlite';
$empty = new JeabTracePdo('sqlite:' . $emptyPath, null, null, jeab_pdo_base_options());
$empty->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
ddl_ok(PHP_SAPI === 'cli' && (string) ($GLOBALS['_JEAB_SAPI'] ?? '') !== 'cli', 'sidvägen är inte CLI');
jeab_sql_stats_reset();
jeab_purchases_store_ensure($empty);
jeab_ensure_inkorg_tables($empty, 'sqlite');
jeab_hs_import_store_ensure($empty);
$emptyCols = [];
foreach ($empty->query('PRAGMA table_info(purchases)') as $col) {
    $emptyCols[] = (string) ($col['name'] ?? '');
}
ddl_ok(!in_array('list_json', $emptyCols, true), 'klick lägger inte till list_json');
$clickSql = implode("\n", jeab_sql_stats()['log']);
ddl_ok(!preg_match('/\bALTER\s+TABLE\b/i', $clickSql), 'klick mot ny databas kör inte ALTER');
$empty->exec("INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (919002, '{\"reminder_id\":919002,\"contact_id\":\"old\",\"company\":\"jarfalla\"}', '2026-01-01 00:00:00')");
jeab_sql_stats_reset();
$payloadRows = jeab_sql_purchases_list($empty, 'jarfalla', false);
$payloadIds = [];
foreach ($payloadRows as $payloadRow) {
    if (is_array($payloadRow)) {
        $payloadIds[] = (string) ($payloadRow['contact_id'] ?? '');
    }
}
ddl_ok(in_array('old', $payloadIds, true), 'klick utan list_json läser payload');
ddl_ok(!ddl_schema_sql(jeab_sql_stats()['log']), 'läsningen utan list_json kör inte ALTER');
ddl_ok(!in_array('purchases_contact_indexed', jeab_existing_index_names($empty, 'purchases', 'sqlite'), true), 'klick på ny databas skapar inget orderindex');
$listCols = [];
foreach ($empty->query('PRAGMA table_info(inkorg_list)') as $col) {
    $listCols[] = (string) ($col['name'] ?? '');
}
ddl_ok(in_array('contact_id', $listCols, true), 'ny inkorg_list har contact_id');
ddl_ok(!in_array('inkorg_messages_thread_date', jeab_existing_index_names($empty, 'inkorg_messages', 'sqlite'), true), 'klick skapar inget mejlindex');
ddl_ok(jeab_settings_value($empty, 'sqlite', 'purchases_ddl_v1') !== 'ok', 'klick sätter inte orderindex-flaggan');
ddl_ok(jeab_settings_value($empty, 'sqlite', 'inkorg_ddl_v3') !== 'ok', 'klick sätter inte inkorg-flaggan');
ddl_ok(jeab_settings_value($empty, 'sqlite', 'inkorg_list_contact_v1') !== 'ok', 'klick fyller inte contact_id');
jeab_purchases_list_json_backfill($empty);
ddl_ok(!in_array('list_json', $emptyCols, true), 'sidan fyller inte list_json');
$again = new JeabTracePdo('sqlite:' . $emptyPath, null, null, jeab_pdo_base_options());
jeab_sql_stats_reset();
jeab_purchases_store_ensure($again);
jeab_ensure_inkorg_tables($again, 'sqlite');
jeab_hs_import_store_ensure($again);
ddl_ok(!ddl_schema_sql(jeab_sql_stats()['log']), 'andra klicket på ny databas gör ingen DDL');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
$GLOBALS['_JEAB_SCHEMA_DDL'] = true;
$tick = new JeabTracePdo('sqlite:' . $emptyPath, null, null, jeab_pdo_base_options());
jeab_purchases_store_ensure($tick);
$tickCols = [];
foreach ($tick->query('PRAGMA table_info(purchases)') as $col) {
    $tickCols[] = (string) ($col['name'] ?? '');
}
ddl_ok(in_array('list_json', $tickCols, true), 'tick lägger till list_json');
jeab_purchases_list_json_backfill($tick);
$filled = $tick->query('SELECT list_json FROM purchases WHERE reminder_id = 919002')->fetchColumn();
ddl_ok(is_string($filled) && str_contains($filled, 'old'), 'tick fyller list_json');
jeab_ensure_inkorg_tables($tick, 'sqlite');
ddl_ok(in_array('purchases_contact_indexed', jeab_existing_index_names($tick, 'purchases', 'sqlite'), true), 'tick skapar orderindexet på ny databas');
ddl_ok(jeab_settings_value($tick, 'sqlite', 'inkorg_list_contact_v1') === 'ok', 'tick sätter contact_id-flaggan');
ddl_ok(jeab_settings_value($tick, 'sqlite', 'inkorg_ddl_v3') === 'ok', 'tick sätter inkorg-flaggan när alla delar lyckades');

unset($GLOBALS['_JEAB_SCHEMA_DDL']);
$failPath = $tmp . '/fail.sqlite';
$fail = new PDO('sqlite:' . $failPath);
$fail->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fail->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$GLOBALS['_JEAB_SCHEMA_DDL'] = true;
$GLOBALS['_JEAB_LIST_INDEX_FAIL'] = ['inkorg_messages_thread_date'];
jeab_ensure_inkorg_tables($fail, 'sqlite');
ddl_ok(jeab_settings_value($fail, 'sqlite', 'inkorg_open_indexes_v1') !== 'ok', 'misslyckat index blir inte ok');
ddl_ok(jeab_settings_value($fail, 'sqlite', 'inkorg_ddl_v3') !== 'ok', 'paraplyet blir inte ok när en del misslyckades');
unset($GLOBALS['_JEAB_LIST_INDEX_FAIL']);
$retry = new PDO('sqlite:' . $failPath);
$retry->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_ensure_inkorg_tables($retry, 'sqlite');
ddl_ok(jeab_settings_value($retry, 'sqlite', 'inkorg_open_indexes_v1') === 'ok', 'tick försöker fail igen');
ddl_ok(jeab_settings_value($retry, 'sqlite', 'inkorg_ddl_v3') === 'ok', 'paraplyet blir ok efter omförsök');
unset($GLOBALS['_JEAB_SCHEMA_DDL']);

final class DdlHsIndexPdo extends PDO
{
    public bool $failIndexLookup = false;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->failIndexLookup && str_contains($query, 'sqlite_master')) {
            throw new RuntimeException('index names blocked');
        }
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI'], $GLOBALS['_JEAB_LIST_INDEX_FAIL']);
$GLOBALS['_JEAB_SCHEMA_DDL'] = true;
$hsPath = $tmp . '/hs-index.sqlite';
$hs = new DdlHsIndexPdo('sqlite:' . $hsPath, null, null, jeab_pdo_base_options());
$hs->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
$hs->failIndexLookup = true;
jeab_hs_import_store_ensure($hs);
ddl_ok(jeab_settings_value($hs, 'sqlite', 'hs_import_ddl_v1') !== 'ok', 'importflaggan sätts inte när indexuppslaget kastar');
$hs->failIndexLookup = false;
jeab_hs_import_store_ensure($hs);
ddl_ok(jeab_settings_value($hs, 'sqlite', 'hs_import_ddl_v1') === 'ok', 'importflaggan sätts när indexen finns');
ddl_ok(in_array('jeab_hs_import_index_from', jeab_existing_index_names($hs, 'jeab_hs_import_index', 'sqlite'), true), 'from-indexet skapas efter ett misslyckat uppslag');
ddl_ok(in_array('jeab_hs_import_list_page', jeab_existing_index_names($hs, 'jeab_hs_import_index', 'sqlite'), true), 'sidindexet skapas efter ett misslyckat uppslag');

$stallPath = $tmp . '/list-json-stall.sqlite';
$stall = new PDO('sqlite:' . $stallPath);
$stall->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stall->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
unset($GLOBALS['_JEAB_SCHEMA_DDL']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
jeab_sql_stats_reset();
jeab_purchases_store_ensure($stall);
jeab_purchases_list_json_backfill($stall);
$stallCols = [];
foreach ($stall->query('PRAGMA table_info(purchases)') as $col) {
    $stallCols[] = (string) ($col['name'] ?? '');
}
ddl_ok(!in_array('list_json', $stallCols, true), 'klick utan list_json fatalar inte och kör inte ALTER');
ddl_ok(!preg_match('/\bALTER\s+TABLE\b/i', implode("\n", jeab_sql_stats()['log'])), 'påfyllnaden på sidan kör inte ALTER');
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
$GLOBALS['_JEAB_SCHEMA_DDL'] = true;
$stallTick = new PDO('sqlite:' . $stallPath);
$stallTick->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
jeab_purchases_store_ensure($stallTick);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
unset($GLOBALS['_JEAB_SCHEMA_DDL']);
$stall->exec("INSERT INTO purchases (reminder_id, payload, updated_at, list_json) VALUES (919010, 'trasig', '2026-01-01 00:00:00', '')");
$_SERVER['REQUEST_TIME_FLOAT'] = 40;
jeab_purchases_list_json_backfill($stall);
$untouched = $stall->query('SELECT list_json FROM purchases WHERE reminder_id = 919010')->fetchColumn();
ddl_ok($untouched === '' || $untouched === null, 'sidan märker inte oläsbar payload');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
$GLOBALS['_JEAB_SCHEMA_DDL'] = true;
$insBad = $stall->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, list_json) VALUES (?, ?, ?, \'\')');
for ($i = 930001; $i <= 930201; $i++) {
    $insBad->execute([$i, 'trasig-' . $i, '2026-01-01 00:00:00']);
}
$stall->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, company, list_json) VALUES (?, ?, ?, \'\', \'\')')->execute([
    930202,
    '{"reminder_id":930202,"contact_id":"cstall","company":"jarfalla","kind":"sol","name":"Stall Bra"}',
    '2026-01-02 00:00:00',
]);
$rounds = 0;
while (jeab_settings_value($stall, 'sqlite', 'purchases_list_json_v1') !== 'ok' && $rounds < 5) {
    $_SERVER['REQUEST_TIME_FLOAT'] = 50 + $rounds;
    jeab_purchases_list_json_backfill($stall);
    $rounds++;
}
ddl_ok($rounds === 3, 'trasiga rader tar flera omgångar och flaggan blir ok, fick ' . $rounds);
$skipped = (string) $stall->query('SELECT list_json FROM purchases WHERE reminder_id = 919010')->fetchColumn();
ddl_ok(str_contains($skipped, '_jeab_list_skip'), 'oläsbar rad märks och lämnar kön');
$goodList = (string) $stall->query('SELECT list_json FROM purchases WHERE reminder_id = 930202')->fetchColumn();
ddl_ok(str_contains($goodList, 'cstall') && !str_contains($goodList, '_jeab_list_skip'), 'läsbar rad bakom kön får riktig list_json');
$emptyLeft = (int) $stall->query("SELECT COUNT(*) FROM purchases WHERE list_json IS NULL OR list_json = ''")->fetchColumn();
ddl_ok($emptyLeft === 0, 'ingen tom list_json blir kvar');
$GLOBALS['_JEAB_PURCHASES_PDO'] = $stall;
jeab_purchase_cache_forget();
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
$beforeIds = [];
foreach (jeab_purchase_list('jarfalla', false) as $row) {
    if (is_array($row)) {
        $beforeIds[] = (int) ($row['reminder_id'] ?? 0);
    }
}
ddl_ok(in_array(930202, $beforeIds, true) && !in_array(930001, $beforeIds, true), 'Ordrar visar den läsbara ordern och inte den trasiga');
$_SERVER['REQUEST_TIME_FLOAT'] = 70;
jeab_purchases_list_index_backfill($stall);
$goodCompany = (string) $stall->query('SELECT company FROM purchases WHERE reminder_id = 930202')->fetchColumn();
$badCompany = (string) $stall->query('SELECT company FROM purchases WHERE reminder_id = 930001')->fetchColumn();
ddl_ok($goodCompany === 'jarfalla', 'tick skriver bolag från payload');
ddl_ok($badCompany === '-', 'oläsbar rad lämnar bolagskön utan att bli Järfälla');
$_SERVER['REQUEST_TIME_FLOAT'] = 71;
jeab_purchases_list_index_backfill($stall);
ddl_ok(jeab_settings_value($stall, 'sqlite', 'purchases_list_index_v1') === 'ok', 'bolagsflaggan blir ok när kön är tom');
jeab_purchase_cache_forget();
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
$afterIds = [];
$afterName = '';
foreach (jeab_purchase_list('jarfalla', false) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    $afterIds[] = $id;
    if ($id === 930202) {
        $afterName = (string) ($row['name'] ?? '');
    }
}
ddl_ok(in_array(930202, $afterIds, true) && !in_array(930001, $afterIds, true) && $afterName === 'Stall Bra', 'Ordrar ser likadan ut efter märkningen');
unset($GLOBALS['_JEAB_PURCHASES_PDO'], $GLOBALS['_JEAB_SCHEMA_DDL']);

echo "db_ddl_request_test ok\n";
