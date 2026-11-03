<?php

declare(strict_types=1);

/** purchases: sqlite-lab med flagga + simulerad live-MySQL-väg. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-purchase-store-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/purchase_sql_migrate.php';

function ps(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

ps(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/purchase_store.php'), 'jeab_sql_store_pdo'), 'live mysql-väg finns');
ps(jeab_purchases_storage_mode() === 'json', 'sqlite utan flagga är json');

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
ps(jeab_purchases_sql_pdo() instanceof PDO, 'legacy-sql simulerar live utan cutover');
jeab_sql_purchases_put(jeab_purchases_sql_pdo(), ['reminder_id' => 880099, 'kind' => 'sol', 'customer_name' => 'Live SQL']);
ps((jeab_purchase_get(880099)['kind'] ?? '') === 'sol', 'skrivning till SQL utan flagga (live-beteende)');
unset($GLOBALS['_JEAB_PURCHASES_LEGACY_SQL']);

$fixture = [
    '880001' => ['reminder_id' => 880001, 'kind' => 'sol', 'customer_name' => 'Test A'],
];
file_put_contents(jeab_purchase_path(), json_encode($fixture, JSON_UNESCAPED_UNICODE));
$pdoLab = jeab_purchases_store_pdo();
jeab_purchases_store_ensure($pdoLab);
jeab_sql_purchases_put($pdoLab, ['reminder_id' => 880002, 'kind' => 'batteri', 'customer_name' => 'Bara SQL']);
jeab_purchase_cache_forget();

$jsonHash = hash('sha256', (string) file_get_contents(jeab_purchase_path()));
$migrate = jeab_purchases_sql_migrate(['dry' => 0]);
ps(($migrate['error'] ?? '') === 'retired: SQL is sole source', 'migrate läser inte json');
ps(hash('sha256', (string) file_get_contents(jeab_purchase_path())) === $jsonHash, 'migrate lämnar json-filen');
$sqlRows = jeab_sql_purchases_load($pdoLab);
ps(($sqlRows['880002']['kind'] ?? '') === 'batteri' && !isset($sqlRows['880001']), 'json-ordern importeras inte');

$verify = jeab_purchases_sql_verify(['sample' => 2]);
ps(($verify['error'] ?? '') === 'retired: SQL is sole source', 'verify är avstängd');

$cut = jeab_purchases_sql_cutover(['enable' => 1, 'dry' => 0]);
ps(($cut['error'] ?? '') === 'retired: SQL is sole source' && jeab_purchases_storage_mode() === 'json', 'cutover ändrar inte flaggan');

jeab_purchase_put(['reminder_id' => 880003, 'kind' => 'laddbox', 'customer_name' => 'C']);
ps(!str_contains((string) file_get_contents(jeab_purchase_path()), '880003'), 'skrivning rör inte json');
ps((jeab_purchase_get(880003)['kind'] ?? '') === 'laddbox', 'ny order ligger i sql');

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$break = jeab_purchases_sql_pdo();
ps($break instanceof PDO, 'lista har SQL');
$beforeLoad = (int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0);
$listed = jeab_purchase_list('jarfalla', false);
ps((int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0) === $beforeLoad, 'lyckad SQL-lista läser inte alla payload');
$seen = false;
foreach ($listed as $row) {
    if (is_array($row) && (int) ($row['reminder_id'] ?? 0) === 880003) {
        $seen = true;
    }
}
ps($seen, 'lyckad lista innehåller ordern');
$break->exec('DROP INDEX IF EXISTS purchases_created');
$break->exec('ALTER TABLE purchases DROP COLUMN created_at');
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$fallback = jeab_purchase_list('jarfalla', false);
$seen = false;
foreach ($fallback as $row) {
    if (is_array($row) && (int) ($row['reminder_id'] ?? 0) === 880003) {
        $seen = true;
    }
}
ps($seen, 'trasig SQL-lista visar samma order');
ps((int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0) >= 1, 'reserven läser payload när listfrågan kastade');
echo "purchase_store_test ok\n";
