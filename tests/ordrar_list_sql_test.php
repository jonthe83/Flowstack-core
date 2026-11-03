<?php

declare(strict_types=1);

/**
 * P1-16 Ordrar-listan filtrerar bolag och Allt klart i SQL.
 * Tick fyller listindexet. Sidan läser inte hela purchases-tabellen.
 * Påhittade rader. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-list-sql-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function ols(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ols_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['reminder_id'] ?? 0);
        }
    }
    sort($ids);

    return $ids;
}

$srcOrdrar = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$visibleAt = strpos($srcOrdrar, 'function jeab_ordrar_visible(');
ols($visibleAt !== false, 'jeab_ordrar_visible finns');
$visibleSrc = substr($srcOrdrar, $visibleAt, 900);
ols(str_contains($visibleSrc, 'jeab_purchase_list'), 'listan går via jeab_purchase_list');
ols(!str_contains($visibleSrc, 'foreach (jeab_purchase_all()'), 'listan loopar inte jeab_purchase_all');
$tickSrc = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
ols(str_contains($tickSrc, 'jeab_purchases_list_index_backfill'), 'tick fyller listindexet');
ols(str_contains($tickSrc, 'jeab_purchases_list_json_backfill'), 'tick fyller list_json');
$listFn = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$listAt = strpos($listFn, 'function jeab_sql_purchases_list(');
$listEnd = strpos($listFn, 'function jeab_sql_purchases_get(', $listAt);
$listBody = $listAt !== false && $listEnd !== false ? substr($listFn, $listAt, $listEnd - $listAt) : '';
ols($listBody !== '' && !str_contains($listBody, 'jeab_purchases_list_index_backfill') && !str_contains($listBody, 'jeab_purchases_list_json_backfill'), 'sidans lista fyller varken company eller list_json');

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$pdo = jeab_purchase_sql_pdo();
ols($pdo instanceof PDO, 'SQL-läget är öppet');

jeab_purchase_put([
    'reminder_id' => 91001,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Lista Test A',
    'created_at' => '2026-09-01 10:00:00',
    'updated_at' => '2026-09-01 10:00:00',
]);
jeab_purchase_put([
    'reminder_id' => 91002,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'name' => 'Lista Test B',
    'allt_klart' => true,
    'closed_at' => '2026-09-20 12:00:00',
    'created_at' => '2026-09-02 10:00:00',
    'updated_at' => '2026-09-20 12:00:00',
]);
jeab_purchase_put([
    'reminder_id' => 91003,
    'company' => 'malmo',
    'kind' => 'laddbox',
    'name' => 'Lista Test C',
    'created_at' => '2026-09-03 10:00:00',
    'updated_at' => '2026-09-03 10:00:00',
]);

$co = (string) $pdo->query('SELECT company FROM purchases WHERE reminder_id = 91001')->fetchColumn();
$klartFlag = (int) $pdo->query('SELECT klart FROM purchases WHERE reminder_id = 91002')->fetchColumn();
ols($co === 'jarfalla', 'sparning skriver company-kolumnen');
ols($klartFlag === 1, 'sparning skriver klart-kolumnen för stängd order');

jeab_purchase_cache_forget();
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$GLOBALS['_JEAB_PURCHASE_LIST_SQL'] = 0;
$active = ols_ids(jeab_ordrar_visible('jarfalla', false));
$klart = ols_ids(jeab_ordrar_visible('jarfalla', true));
$malmo = ols_ids(jeab_ordrar_visible('malmo', false));
ols($active === [91001], 'aktiv Järfälla-lista är bara den öppna ordern, fick ' . implode(',', $active));
ols($klart === [91002], 'Allt klart är bara den stängda Järfälla-ordern, fick ' . implode(',', $klart));
ols($malmo === [91003], 'Malmö-listan är bara Malmö-ordern, fick ' . implode(',', $malmo));
ols((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'listan läser inte hela payload-tabellen');
ols((int) $GLOBALS['_JEAB_PURCHASE_LIST_SQL'] >= 3, 'listan går i SQL');

$pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at) VALUES (?, ?, ?)')->execute([
    91004,
    json_encode([
        'reminder_id' => 91004,
        'company' => 'jarfalla',
        'kind' => 'sol',
        'name' => 'Lista Test D',
        'created_at' => '2026-09-04 10:00:00',
    ], JSON_UNESCAPED_UNICODE),
    '2026-09-04 10:00:00',
]);
$emptyCo = $pdo->query('SELECT company FROM purchases WHERE reminder_id = 91004')->fetchColumn();
ols($emptyCo === null || $emptyCo === '', 'gammal rad saknar company-kolumn');

$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'apache2handler';
unset($GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_TICK']);
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] = 0;
$beforeBackfill = ols_ids(jeab_ordrar_visible('jarfalla', false));
ols(in_array(91004, $beforeBackfill, true), 'ofylld rad syns, den döljs inte');
$stillEmpty = $pdo->query('SELECT company FROM purchases WHERE reminder_id = 91004')->fetchColumn();
ols($stillEmpty === null || $stillEmpty === '', 'sidan fyller inte tom company');
ols(($GLOBALS['_JEAB_PURCHASE_LIST_SQL_MODE'] ?? '') === 'list-legacy', 'sidan läser list_json när någon rad har den, fick ' . (string) ($GLOBALS['_JEAB_PURCHASE_LIST_SQL_MODE'] ?? ''));
ols((int) $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] === 1, 'payload läses bara för den tomma list_json-raden, fick ' . (int) $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD']);
jeab_purchases_list_index_backfill($pdo);
jeab_purchases_list_json_backfill($pdo);
$stillEmpty = $pdo->query('SELECT company FROM purchases WHERE reminder_id = 91004')->fetchColumn();
ols($stillEmpty === null || $stillEmpty === '', 'påfyllnad på ett kontorsklick gör inget');

unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);
jeab_settings_put($pdo, 'sqlite', 'purchases_list_index_v1', '');
jeab_settings_put($pdo, 'sqlite', 'purchases_list_json_v1', '');
$_SERVER['REQUEST_TIME_FLOAT'] = 91004.1;
jeab_purchases_list_index_backfill($pdo);
jeab_purchases_list_json_backfill($pdo);
$_SERVER['REQUEST_TIME_FLOAT'] = 91004.2;
jeab_purchases_list_index_backfill($pdo);
jeab_purchases_list_json_backfill($pdo);
$filled = (string) $pdo->query('SELECT company FROM purchases WHERE reminder_id = 91004')->fetchColumn();
ols($filled === 'jarfalla', 'tick skriver company från payload');
$filledJson = (string) $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 91004')->fetchColumn();
ols(str_contains($filledJson, 'Lista Test D'), 'tick skriver list_json från payload');
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] = 0;
$afterTick = ols_ids(jeab_ordrar_visible('jarfalla', false));
ols(in_array(91004, $afterTick, true), 'raden syns när tick fyllt kolumnerna');
ols(($GLOBALS['_JEAB_PURCHASE_LIST_SQL_MODE'] ?? '') === 'list-strict', 'tavlan läser list_json med company och klart, fick ' . (string) ($GLOBALS['_JEAB_PURCHASE_LIST_SQL_MODE'] ?? ''));
ols((int) $GLOBALS['_JEAB_PURCHASE_LIST_PAYLOAD'] === 0, 'fylld list_json läser inte payload');
ols(!str_contains((string) ($GLOBALS['_JEAB_PURCHASE_LIST_SQL_LAST'] ?? ''), 'payload'), 'listans SELECT tar inte med payload');

jeab_purchase_put([
    'reminder_id' => 91010,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Lista Test E',
    'contact_id' => 'kontakt_gammal',
    'created_at' => '2026-09-05 10:00:00',
    'updated_at' => '2026-09-05 10:00:00',
]);
$stamped = jeab_contacts_sole_stamp_order_sql($pdo, 91010, 'kontakt_ny', 'kontakt_gammal');
ols($stamped, 'omlänkningen skriver contact_id');
$stampJson = (string) $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 91010')->fetchColumn();
ols(str_contains($stampJson, 'kontakt_ny') && !str_contains($stampJson, 'kontakt_gammal'), 'omlänkningen skriver om list_json');
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$linked = null;
foreach (jeab_ordrar_visible('jarfalla', false) as $row) {
    if (is_array($row) && (int) ($row['reminder_id'] ?? 0) === 91010) {
        $linked = (string) ($row['contact_id'] ?? '');
    }
}
ols($linked === 'kontakt_ny', 'tavlan visar den nya kontakten efter omlänkning, fick ' . $linked);

jeab_purchase_put([
    'reminder_id' => 91011,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Lista Test F',
    'contact_id' => 'kontakt_kvar',
    'created_at' => '2026-09-06 10:00:00',
    'updated_at' => '2026-09-06 10:00:00',
]);
$rekeyed = jeab_reminders_dedupe_rekey_order($pdo, 91011, 91012);
ols($rekeyed, 'dedupe byter reminder_id');
$rekeyJson = (string) $pdo->query('SELECT list_json FROM purchases WHERE reminder_id = 91012')->fetchColumn();
ols(str_contains($rekeyJson, '91012') && str_contains($rekeyJson, 'kontakt_kvar'), 'dedupe skriver om list_json med samma kontakt');
unset($GLOBALS['_JEAB_ORDRAR_VISIBLE']);
jeab_purchase_cache_forget();
$rekeyContact = null;
foreach (jeab_ordrar_visible('jarfalla', false) as $row) {
    if (is_array($row) && (int) ($row['reminder_id'] ?? 0) === 91012) {
        $rekeyContact = (string) ($row['contact_id'] ?? '');
    }
}
ols($rekeyContact === 'kontakt_kvar', 'tavlan behåller kontakten efter dedupe, fick ' . (string) $rekeyContact);

$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'apache2handler';
unset($GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_TICK']);
$pagePdo = new PDO('sqlite:' . $tmp . '/page-purchases.sqlite');
$pagePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prevPurchases = $GLOBALS['_JEAB_PURCHASES_PDO'] ?? null;
$GLOBALS['_JEAB_PURCHASES_PDO'] = $pagePdo;
jeab_purchases_store_ensure($pagePdo);
$pageCols = [];
foreach ($pagePdo->query('PRAGMA table_info(purchases)')->fetchAll() as $col) {
    if (is_array($col)) {
        $pageCols[] = (string) ($col['name'] ?? '');
    }
}
ols(in_array('list_json', $pageCols, true), 'sidan skapar list_json så sparningen inte faller');
$pageIndexes = [];
foreach ($pagePdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'purchases'")->fetchAll() as $idx) {
    $name = is_array($idx) ? (string) ($idx['name'] ?? '') : '';
    if ($name !== '' && !str_starts_with($name, 'sqlite_autoindex')) {
        $pageIndexes[] = $name;
    }
}
ols($pageIndexes === [], 'sidan skapar inga purchases-index, fick ' . implode(',', $pageIndexes));
if ($prevPurchases instanceof PDO) {
    $GLOBALS['_JEAB_PURCHASES_PDO'] = $prevPurchases;
} else {
    unset($GLOBALS['_JEAB_PURCHASES_PDO']);
}
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI']);

/**
 * En sparning mellan SELECT och UPDATE får inte skrivas över av den äldre
 * ögonblicksbilden. execute() skriver en ny list_json precis före UPDATE,
 * samma fönster som en annan begäran hinner committa i.
 */
final class OlsListJsonRaceStmt extends PDOStatement
{
    private PDO $racePdo;

    protected function __construct(PDO $pdo)
    {
        $this->racePdo = $pdo;
    }

    public function execute(?array $params = null): bool
    {
        $sql = $this->queryString;
        $id = is_array($params) ? (int) ($params[1] ?? 0) : 0;
        if ($id === 91020 && str_contains($sql, 'UPDATE purchases SET list_json')) {
            $fresh = '{"reminder_id":91020,"contact_id":"nyare","name":"Nyare","company":"jarfalla"}';
            $this->racePdo->exec(
                'UPDATE purchases SET list_json = ' . $this->racePdo->quote($fresh)
                . ', payload = ' . $this->racePdo->quote($fresh)
                . ' WHERE reminder_id = 91020'
            );
        }

        return parent::execute($params);
    }
}

final class OlsListJsonRacePdo extends PDO
{
    public function __construct(string $dsn)
    {
        parent::__construct($dsn);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [OlsListJsonRaceStmt::class, [$this]]);
    }
}

$racePath = $tmp . '/list-json-race.sqlite';
$race = new OlsListJsonRacePdo('sqlite:' . $racePath);
$race->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT NOT NULL)');
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_SAPI'], $GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_SCHEMA_DDL'], $GLOBALS['_JEAB_TICK']);
$_SERVER['REQUEST_TIME_FLOAT'] = 91020;
jeab_purchases_store_ensure($race);
$race->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, list_json) VALUES (?, ?, ?, \'\')')->execute([
    91020,
    json_encode([
        'reminder_id' => 91020,
        'company' => 'jarfalla',
        'name' => 'Race Gammal',
        'contact_id' => 'gammal',
    ], JSON_UNESCAPED_UNICODE),
    '2026-09-07 10:00:00',
]);
jeab_purchases_list_json_backfill($race);
$raced = (string) $race->query('SELECT list_json FROM purchases WHERE reminder_id = 91020')->fetchColumn();
ols(str_contains($raced, 'Nyare') && !str_contains($raced, 'Race Gammal'), 'påfyllnaden skriver inte över en list_json som sparades efter SELECT, fick ' . $raced);
$backfillSrc = (string) file_get_contents(dirname(__DIR__) . '/src/purchase_store.php');
$backfillAt = strpos($backfillSrc, 'function jeab_purchases_list_json_backfill');
$backfillBody = $backfillAt !== false ? substr($backfillSrc, $backfillAt, 2400) : '';
ols(
    str_contains($backfillBody, 'UPDATE purchases SET list_json = ? WHERE reminder_id = ? AND (list_json IS NULL OR list_json ='),
    'påfyllnadens UPDATE kräver att list_json fortfarande är tom'
);

echo "ordrar list sql: ok\n";
