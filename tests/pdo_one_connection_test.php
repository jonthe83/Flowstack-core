<?php

declare(strict_types=1);

/**
 * En request får en PDO. SQLSTATE 1203 försöks en gång.
 * List-AJAX, Graph och tick delar högst två platser.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-pdo-one-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function one_assert(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$e1203 = new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);
one_assert(jeab_pdo_is_max_user_connections($e1203), '1203 räknas som max_user_connections');
one_assert(!jeab_pdo_is_max_user_connections(new PDOException('no such table', 1)), 'vanligt PDO-fel är inte 1203');

$a = jeab_pdo_connect_sqlite();
$b = jeab_pdo_connect_sqlite();
$store = jeab_store();
$sql = jeab_store_sql_pdo();
$audit = jeab_inbox_contact_set_email_audit_pdo();
one_assert($store instanceof JeabPdoStore, 'store är PDO');
$ids = array_map('spl_object_id', [$a, $b, $store->pdo(), $sql, $audit]);
one_assert(count(array_unique($ids)) === 1, 'samma PDO för connect, store, sql och audit');
one_assert(jeab_pdo_open_count() === 1, 'en fysisk anslutning, fick ' . jeab_pdo_open_count());
one_assert(jeab_pdo_live_count() === 1, 'en levande anslutning i poolen');

jeab_pdo_release();
one_assert(jeab_pdo_live_count() === 0, 'poolen är tom efter release');

$again = jeab_pdo_connect_sqlite();
one_assert(spl_object_id($again) !== $ids[0], 'ny anslutning efter release');
one_assert(jeab_pdo_open_count() === 2, 'två öppningar totalt efter återanslutning');
one_assert(jeab_pdo_live_count() === 1, 'fortfarande en levande anslutning');

jeab_pdo_release();
$tries = 0;
$GLOBALS['_JEAB_PDO_FACTORY'] = static function (string $dsn) use (&$tries): PDO {
    $tries++;
    if ($tries === 1) {
        throw new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);
    }

    return new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
};
$retried = jeab_pdo_connect_sqlite();
unset($GLOBALS['_JEAB_PDO_FACTORY']);
one_assert($retried instanceof PDO, 'anslutning efter ett 1203-försök');
one_assert($tries === 2, 'exakt ett nytt försök, fick ' . $tries);
one_assert(jeab_pdo_live_count() === 1, 'retry lämnar en anslutning');

$held = [];
for ($i = 0; $i < 2; $i++) {
    $slot = jeab_db_slot_acquire(2);
    one_assert(is_resource($slot), 'plats ' . $i . ' tas');
    $held[] = $slot;
}
$dir = jeab_db_slot_dir();
$childCode = '<?php
$a = fopen($argv[1], "c");
$b = fopen($argv[2], "c");
flock($a, LOCK_EX);
flock($b, LOCK_EX);
fwrite(STDOUT, "held\n");
fflush(STDOUT);
sleep(20);
';
$childFile = $tmp . '/hold.php';
file_put_contents($childFile, $childCode);
foreach ($held as $fh) {
    jeab_db_slot_release($fh);
}
$proc = proc_open(
    [PHP_BINARY, $childFile, $dir . '/slot-0.lock', $dir . '/slot-1.lock'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $tmp
);
one_assert(is_resource($proc), 'låsprocess startar');
$line = is_resource($proc) ? fgets($pipes[1]) : '';
one_assert(trim((string) $line) === 'held', 'andra processen håller båda platserna');
$blocked = jeab_db_slot_acquire(2);
one_assert($blocked === null, 'tredje platsen nekas');
$tick = jeab_sms_tick(new JeabUnavailableStore());
one_assert(($tick['busy'] ?? 0) === 1, 'tick väntar inte ut en upptagen kö');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}
$free = jeab_db_slot_acquire(2);
one_assert(is_resource($free), 'plats ledig när kön släppt');
jeab_db_slot_release($free);

jeab_pdo_release();
$idle = jeab_pdo_connect_sqlite();
one_assert($idle instanceof PDO && jeab_pdo_live_count() === 1, 'en anslutning före släpp');
jeab_pdo_release_if_idle();
one_assert(jeab_pdo_live_count() === 0, 'ledig anslutning släpps före externt anrop');
$busyPdo = jeab_pdo_connect_sqlite();
$busyPdo->beginTransaction();
jeab_pdo_release_if_idle();
one_assert(jeab_pdo_live_count() === 1 && $busyPdo->inTransaction(), 'öppen transaktion släpps inte');
$busyPdo->rollBack();

$probe = jeab_pdo_connect_sqlite();
$probe->exec('CREATE TABLE IF NOT EXISTS busy_probe (id INTEGER PRIMARY KEY, n INTEGER)');
$probe->exec('DELETE FROM busy_probe');
$hits = 0;
$rolled = false;
try {
    jeab_pdo_transaction($probe, static function (PDO $db) use (&$hits): void {
        $hits++;
        $db->exec('INSERT INTO busy_probe (n) VALUES (1)');
        throw new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);
    });
} catch (JeabDbBusyException) {
    $rolled = true;
}
$rows = (int) $probe->query('SELECT COUNT(*) FROM busy_probe')->fetchColumn();
one_assert($rolled && $hits === 1 && $rows === 0, '1203 kör transaktionen en gång och rullar tillbaka, körningar ' . $hits . ' rader ' . $rows);
one_assert(!$probe->inTransaction(), 'ingen halv transaktion efter 1203');
$always = 0;
$busyMsg = '';
try {
    jeab_pdo_transaction($probe, static function () use (&$always): void {
        $always++;
        throw new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);
    });
} catch (JeabDbBusyException $e) {
    $busyMsg = $e->getMessage();
}
one_assert($always === 1 && $busyMsg === jeab_pdo_busy_message(), '1203 blir ett tydligt fel utan omkörning, fick ' . $always . ' ' . $busyMsg);
one_assert(!$probe->inTransaction(), 'transaktionen är stängd efter upptaget fel');
$_GET = ['p' => 'inkorg', 'ix_part' => 'thread'];
$_SERVER['REQUEST_METHOD'] = 'GET';
one_assert(jeab_inkorg_ix_part() === 'thread', 'tråd-delanrop känns igen');
$_SERVER['REQUEST_METHOD'] = 'POST';
one_assert(jeab_inkorg_ix_part() === '', 'POST är inte ett delanrop');

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$boot = (string) file_get_contents($root . '/src/bootstrap.php');
$graph = (string) file_get_contents($root . '/src/inkorg_graph.php');
$sms = (string) file_get_contents($root . '/src/sms.php');
one_assert(str_contains($page, 'ixFlight >= 1'), 'klienten kör högst ett delanrop åt gången');
one_assert(str_contains($page, 'AbortController'), 'snabba klick avbryter föregående hämtning');
one_assert(str_contains($page, 'n < 1'), 'förhämtning tar bara nästa tråd');
one_assert(jeab_pdo_base_options()[PDO::ATTR_PERSISTENT] === false, 'PDO är inte persistent');
$enter = substr($boot, (int) strpos($boot, 'function jeab_inkorg_part_enter'));
one_assert(str_contains($enter, 'session_write_close') && str_contains($enter, 'jeab_db_slot_acquire(1)'), 'delanrop släpper sessionen och tar en plats innan PDO');
one_assert(str_contains($graph, 'jeab_graph_read_may_release') && str_contains($graph, "['GET', 'HEAD']"), 'Graph släpper bara före en läsning');
one_assert(str_contains($page, "in_array(\$part, ['list', 'counts', 'thread', 'app']"), 'list, räkning, tråd och app tar serverlås');
one_assert(str_contains($boot, 'session_write_close'), 'sessionen stängs när svaret är klart');
one_assert(str_contains($boot, 'jeab_request_release'), 'PDO släpps efter svaret');
one_assert(str_contains($graph, 'jeab_db_slot_acquire'), 'mejlhämtning tar en plats');
one_assert(str_contains($sms, 'jeab_db_slot_acquire'), 'tick tar en plats');

$GLOBALS['_JEAB_PDO_INTERACTIVE'] = true;
$GLOBALS['_JEAB_PDO_INTERACTIVE_BUDGET_US'] = 300000;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 250000;
$GLOBALS['_JEAB_PDO_WAITED_US'] = 0;
$GLOBALS['_JEAB_PDO_FACTORY'] = static function (): void {
    throw new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);
};
$t0 = hrtime(true);
$caught = false;
try {
    jeab_pdo_open_once('sqlite::memory:', null, null, jeab_pdo_base_options());
} catch (JeabDbBusyException) {
    $caught = true;
}
$waitMs = (hrtime(true) - $t0) / 1e6;
$waited = (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0);
unset($GLOBALS['_JEAB_PDO_FACTORY'], $GLOBALS['_JEAB_PDO_INTERACTIVE']);
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
one_assert($caught, 'interaktivt 1203 blir upptaget');
one_assert($waited > 0 && $waited <= 300000, 'backoff sover högst 300 ms, sov ' . $waited);
one_assert($waitMs < 500, 'interaktivt 1203 vägg tid ' . (int) round($waitMs) . ' ms');
$index = (string) file_get_contents($root . '/index.php');
one_assert(str_contains($index, '$jeabPrefetch') && str_contains($index, 'session_write_close()'), 'förhämtning släpper sessionen efter inloggning');
one_assert(str_contains($index, 'jeab_request_skips_send_guard'), 'förhämtning och poll startar inte utskickskö');
one_assert(strpos($index, 'jeab_csrf_refresh_respond') !== false && strpos($index, 'jeab_csrf_refresh_respond') < strpos($index, 'jeab_boot_store()'), 'csrf svarar innan butiken öppnas');
$shell = (string) file_get_contents($root . '/assets/js/shell.js');
one_assert(str_contains($shell, 'jeabBackground') && str_contains($shell, 'signal: ctrl.signal'), 'skalets förhämtning är en åt gången och kan avbrytas');
one_assert(str_contains($page, 'ixShowPending') && str_contains($page, 'X-Jeab-Interactive'), 'klicket ritar raden direkt och märker anropet interaktivt');
$clickAt = strpos($page, "if (kind === 'thread') { ixShowPending(nextId); }");
$fetchAt = $clickAt === false ? false : strpos($page, 'ixFetch(url, kind, false);', $clickAt);
one_assert($clickAt !== false && $fetchAt !== false && $clickAt < $fetchAt, 'aktiv rad sätts före hämtningen');

exit($fail > 0 ? 1 : 0);
