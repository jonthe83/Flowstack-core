<?php

declare(strict_types=1);

/**
 * 2006 och 2013: första exec misslyckas, anslutningen byts, samma sats lyckas en gång.
 * Öppen transaktion körs inte om tyst. Hela enheten körs en gång till.
 * Mejlimporten försöker samma message id en gång till och skriver inte en andra rad.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-pdo-gone-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$logDir = $tmp . '/fellogg';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    fwrite(STDERR, "FAIL logg\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $logDir;
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
function gone_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

function gone_err(int $code, string $secret): PDOException
{
    $text = $code === 2013
        ? 'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'
        : 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away';
    $e = new PDOException($text . ' ' . $secret, $code);
    $e->errorInfo = ['HY000', $code, $code === 2013 ? 'Lost connection to MySQL server during query' : 'MySQL server has gone away'];

    return $e;
}

$secret = 'secret.person@example.com';
$e2006 = gone_err(2006, $secret);
$e2013 = gone_err(2013, $secret);
gone_ok(jeab_pdo_mysql_gone($e2006) && jeab_pdo_mysql_gone($e2013), '2006 och 2013 räknas som bruten anslutning');
gone_ok(!jeab_pdo_mysql_gone(new PDOException('no such table', 1)), 'vanligt PDO-fel är inte 2006');

$pdo = jeab_pdo_connect_sqlite();
$before = spl_object_id($pdo);
$opens = jeab_pdo_open_count();
$pdo->exec('CREATE TABLE gone_probe (id INTEGER PRIMARY KEY, n INTEGER)');

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2006;
$wrote = $pdo->exec('INSERT INTO gone_probe (n) VALUES (7)');
$live = jeab_pdo_connect_sqlite();
$rows = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
$logged = (string) @file_get_contents($logDir . '/app-errors.jsonl');
gone_ok($wrote === 1 && $rows === 1, 'första exec med 2006 skrivs en gång efter omförsök, rader ' . $rows . ' svar ' . var_export($wrote, true));
gone_ok(spl_object_id($live) !== $before && jeab_pdo_live_count() === 1 && jeab_pdo_open_count() === $opens + 1, 'cachen släpps och en ny anslutning öppnas');
gone_ok(str_contains($logged, '2006 exec') && !str_contains($logged, $secret), 'loggen har felkod och exec, inte mejladressen');

$before2013 = spl_object_id($live);
$opens2013 = jeab_pdo_open_count();
$rowsBefore = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$caught2013 = '';
try {
    $live->exec('INSERT INTO gone_probe (n) VALUES (8)');
} catch (PDOException $e) {
    $caught2013 = (string) ($e->errorInfo[1] ?? '');
}
$live = jeab_pdo_connect_sqlite();
$rows = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
$logged = (string) @file_get_contents($logDir . '/app-errors.jsonl');
gone_ok($caught2013 === '2013' && $rows === $rowsBefore, '2013 på vanlig INSERT skrivs inte om, rader ' . $rows . ' kod ' . $caught2013);
gone_ok(spl_object_id($live) !== $before2013 && jeab_pdo_open_count() === $opens2013 + 1, '2013 på vanlig INSERT byter anslutning och kastar om');
gone_ok(str_contains($logged, '2013 exec') && !str_contains($logged, $secret), 'loggen har 2013 och exec, inte mejladressen');

gone_ok(jeab_pdo_sql_retry_safe('SELECT id FROM inkorg_messages WHERE id = ?'), 'SELECT får köras om');
gone_ok(jeab_pdo_sql_retry_safe('SHOW TABLES'), 'SHOW får köras om');
gone_ok(jeab_pdo_sql_retry_safe('INSERT IGNORE INTO t (id) VALUES (1)'), 'INSERT IGNORE får köras om');
gone_ok(jeab_pdo_sql_retry_safe('INSERT INTO t (id, n) VALUES (1, 2) ON DUPLICATE KEY UPDATE n = 2'), 'ON DUPLICATE KEY får köras om');
gone_ok(jeab_pdo_sql_retry_safe('REPLACE INTO t (id, n) VALUES (1, 2)'), 'REPLACE får köras om');
gone_ok(jeab_pdo_sql_retry_safe('UPDATE t SET n = 2 WHERE id = 1'), 'nycklad UPDATE får köras om');
gone_ok(jeab_pdo_sql_retry_safe('DELETE FROM t WHERE idem_key = ?'), 'nycklad DELETE får köras om');
gone_ok(!jeab_pdo_sql_retry_safe('INSERT INTO t (n) VALUES (8)'), 'vanlig INSERT får inte köras om');
gone_ok(!jeab_pdo_sql_retry_safe('UPDATE t SET n = 0'), 'UPDATE utan nyckel får inte köras om');
gone_ok(!jeab_pdo_sql_retry_safe('DELETE FROM t'), 'DELETE utan nyckel får inte köras om');
gone_ok(!jeab_pdo_sql_retry_safe('UPDATE t SET n = 0 WHERE 1 = 1'), 'UPDATE WHERE 1=1 får inte köras om');
gone_ok(jeab_pdo_gone_may_retry(2013, 'prepare', 'INSERT INTO t (n) VALUES (1)'), 'prepare får köras om även för INSERT');
gone_ok(jeab_pdo_gone_may_retry(2013, 'ping', 'INSERT INTO t (n) VALUES (1)'), 'ping får köras om');
gone_ok(jeab_pdo_gone_may_retry(2006, 'exec', 'INSERT INTO t (n) VALUES (1)'), '2006 på vanlig INSERT får fortfarande köras om');

$live->exec('CREATE TABLE gone_idem (id INTEGER PRIMARY KEY, n INTEGER)');
$live->exec('INSERT INTO gone_idem (id, n) VALUES (1, 1)');
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$live->exec('INSERT OR IGNORE INTO gone_idem (id, n) VALUES (2, 5)');
$live = jeab_pdo_connect_sqlite();
$ignored = (int) $live->query('SELECT COUNT(*) FROM gone_idem WHERE id = 2 AND n = 5')->fetchColumn();
gone_ok($ignored === 1, '2013 på INSERT IGNORE körs en gång');

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$live->exec('REPLACE INTO gone_idem (id, n) VALUES (1, 9)');
$live = jeab_pdo_connect_sqlite();
$replaced = (int) $live->query('SELECT n FROM gone_idem WHERE id = 1')->fetchColumn();
gone_ok($replaced === 9, '2013 på REPLACE körs en gång, n ' . $replaced);

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$live->exec('UPDATE gone_idem SET n = 4 WHERE id = 1');
$live = jeab_pdo_connect_sqlite();
$updated = (int) $live->query('SELECT n FROM gone_idem WHERE id = 1')->fetchColumn();
gone_ok($updated === 4, '2013 på nycklad UPDATE körs en gång, n ' . $updated);

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$bareUpdate = '';
try {
    $live->exec('UPDATE gone_idem SET n = 0');
} catch (PDOException $e) {
    $bareUpdate = (string) ($e->errorInfo[1] ?? '');
}
$live = jeab_pdo_connect_sqlite();
$still = (int) $live->query('SELECT n FROM gone_idem WHERE id = 1')->fetchColumn();
gone_ok($bareUpdate === '2013' && $still === 4, '2013 på UPDATE utan nyckel kastas om, n ' . $still);

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$live->exec('DELETE FROM gone_idem WHERE id = 2');
$live = jeab_pdo_connect_sqlite();
$gone = (int) $live->query('SELECT COUNT(*) FROM gone_idem WHERE id = 2')->fetchColumn();
gone_ok($gone === 0, '2013 på nycklad DELETE körs en gång');

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$bareDelete = '';
try {
    $live->exec('DELETE FROM gone_idem');
} catch (PDOException $e) {
    $bareDelete = (string) ($e->errorInfo[1] ?? '');
}
$live = jeab_pdo_connect_sqlite();
$kept = (int) $live->query('SELECT COUNT(*) FROM gone_idem WHERE id = 1')->fetchColumn();
gone_ok($bareDelete === '2013' && $kept === 1, '2013 på DELETE utan nyckel lämnar raden');

$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$seen = (int) $live->query('SELECT COUNT(*) FROM gone_idem')->fetchColumn();
gone_ok($seen === 1, '2013 på SELECT körs om, räknade ' . $seen);

$beforePrepare = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$prepared = $live->prepare('INSERT INTO gone_probe (n) VALUES (77)');
$live = jeab_pdo_connect_sqlite();
$afterPrepare = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
gone_ok($prepared instanceof PDOStatement && $afterPrepare === $beforePrepare, '2013 på prepare av INSERT förbereder om utan att skriva');

$insertStmt = $live->prepare('INSERT INTO gone_probe (n) VALUES (40)');
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$stmtCode = '';
try {
    $insertStmt->execute();
} catch (PDOException $e) {
    $stmtCode = (string) ($e->errorInfo[1] ?? '');
}
$live = jeab_pdo_connect_sqlite();
$forties = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = 40')->fetchColumn();
gone_ok($stmtCode === '2013' && $forties === 0, '2013 på prepared INSERT kastas om');

$readStmt = $live->prepare('SELECT COUNT(*) FROM gone_idem');
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$readStmt->execute();
$readCount = (int) $readStmt->fetchColumn();
gone_ok($readCount === 1, '2013 på prepared SELECT körs om, räknade ' . $readCount);

$ordinary = 0;
try {
    $live->exec('INSERT INTO gone_missing (n) VALUES (1)');
} catch (PDOException) {
    $ordinary = 1;
}
gone_ok($ordinary === 1 && jeab_pdo_live_count() === 1, 'annat SQL-fel byter inte anslutning');

$txPdo = jeab_pdo_connect_sqlite();
$hits = 0;
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2006;
jeab_pdo_transaction($txPdo, static function (PDO $db) use (&$hits): void {
    $hits++;
    $db->exec('INSERT INTO gone_probe (n) VALUES (9)');
});
$live = jeab_pdo_connect_sqlite();
$nines = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = 9')->fetchColumn();
gone_ok($hits === 2 && $nines === 1 && $live->inTransaction() === false, 'öppen transaktion kör hela enheten en gång till, körningar ' . $hits . ' rader ' . $nines);

$txHits = 0;
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
jeab_pdo_transaction(jeab_pdo_connect_sqlite(), static function (PDO $db) use (&$txHits): void {
    $txHits++;
    $db->exec('INSERT INTO gone_probe (n) VALUES (11)');
});
$live = jeab_pdo_connect_sqlite();
$elevens = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = 11')->fetchColumn();
gone_ok($txHits === 2 && $elevens === 1 && $live->inTransaction() === false, '2013 inuti transaktion kör hela enheten en gång, körningar ' . $txHits . ' rader ' . $elevens);

$saved = [];
$runs = 0;
$first = jeab_pdo_retry_mail(static function () use (&$saved, &$runs, $e2006): string {
    $runs++;
    $key = substr(hash('sha256', 'message-id-1'), 0, 16);
    if (isset($saved[$key])) {
        return 'dup';
    }
    if ($runs === 1) {
        throw $e2006;
    }
    $saved[$key] = true;

    return 'new';
});
gone_ok($first === 'new' && $runs === 2 && count($saved) === 1, 'mejlet försöks en gång till och sparas en gång');

$runs = 0;
$second = jeab_pdo_retry_mail(static function () use (&$saved, &$runs, $e2006): string {
    $runs++;
    $key = substr(hash('sha256', 'message-id-2'), 0, 16);
    if (isset($saved[$key])) {
        return 'dup';
    }
    $saved[$key] = true;
    if ($runs === 1) {
        throw $e2006;
    }

    return 'new';
});
gone_ok($second === 'dup' && $runs === 2 && count($saved) === 2, 'redan sparat message id importeras inte en andra gång');

$runs = 0;
$plain = '';
try {
    jeab_pdo_retry_mail(static function () use (&$runs): void {
        $runs++;
        throw new PDOException('no such table', 1);
    });
} catch (PDOException $e) {
    $plain = $e->getMessage();
}
gone_ok($runs === 1 && $plain === 'no such table', 'annat fel i mejlimporten körs inte om');

$logged = (string) @file_get_contents($logDir . '/app-errors.jsonl');
gone_ok(str_contains($logged, '2006 mail') && !str_contains($logged, $secret), 'mejlomförsöket loggar bara 2006 mail');

foreach ([2013 => $e2013, 2006 => $e2006] as $code => $err) {
    $commitHits = 0;
    $commitClass = '';
    $commitMsg = '';
    $GLOBALS['_JEAB_PDO_COMMIT_FAIL'] = $err;
    try {
        jeab_pdo_transaction(jeab_pdo_connect_sqlite(), static function (PDO $db) use (&$commitHits, $code): void {
            $commitHits++;
            $db->exec('INSERT INTO gone_probe (n) VALUES (' . (30 + (int) $code) . ')');
        });
    } catch (JeabPdoCommitUnknownException $e) {
        $commitClass = $e::class;
        $commitMsg = $e->getMessage();
    } catch (Throwable $e) {
        $commitClass = $e::class;
        $commitMsg = $e->getMessage();
    }
    $live = jeab_pdo_connect_sqlite();
    $committed = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = ' . (30 + (int) $code))->fetchColumn();
    gone_ok(
        $commitHits === 1 && $committed === 0 && $commitClass === JeabPdoCommitUnknownException::class && !str_contains($commitMsg, $secret),
        $code . ' under COMMIT kör inte om enheten, körningar ' . $commitHits . ' rader ' . $committed . ' ' . $commitClass
    );
}

$raw = jeab_pdo_connect_sqlite();
jeab_pdo_begin_immediate($raw);
$raw->exec('INSERT INTO gone_probe (n) VALUES (50)');
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = $e2013;
$rawClass = '';
try {
    jeab_pdo_end($raw, true);
} catch (JeabPdoCommitUnknownException $e) {
    $rawClass = $e::class;
} catch (Throwable $e) {
    $rawClass = $e::class;
}
$live = jeab_pdo_connect_sqlite();
$rawRows = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = 50')->fetchColumn();
gone_ok($rawClass === JeabPdoCommitUnknownException::class && $rawRows === 0, '2013 på exec COMMIT blir ett eget fel och skrivs inte om, rader ' . $rawRows . ' ' . $rawClass);

$logged = (string) @file_get_contents($logDir . '/app-errors.jsonl');
gone_ok(str_contains($logged, '2013 commit') && str_contains($logged, '2006 commit') && !str_contains($logged, $secret), 'COMMIT-loggen har bara felkod och commit');

$beforeProbe = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = gone_err(2006, $secret);
$GLOBALS['_JEAB_PDO_IN_TX_THROWS'] = gone_err(2006, $secret);
$probeRead = (int) $live->query('SELECT COUNT(*) FROM gone_probe')->fetchColumn();
$live = jeab_pdo_connect_sqlite();
gone_ok(
    $probeRead === $beforeProbe && !isset($GLOBALS['_JEAB_PDO_IN_TX_THROWS']),
    '2006 när inTransaction kastar kör om en läsning, räknade ' . $probeRead
);

$beforeWrite = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = 21')->fetchColumn();
$GLOBALS['_JEAB_PDO_EXEC_FAIL'] = gone_err(2006, $secret);
$GLOBALS['_JEAB_PDO_IN_TX_THROWS'] = gone_err(2006, $secret);
$writeCode = '';
try {
    $live->exec('INSERT INTO gone_probe (n) VALUES (21)');
} catch (PDOException $e) {
    $writeCode = (string) ($e->errorInfo[1] ?? '');
}
$live = jeab_pdo_connect_sqlite();
$wrote21 = (int) $live->query('SELECT COUNT(*) FROM gone_probe WHERE n = 21')->fetchColumn();
gone_ok(
    $writeCode === '2006' && $wrote21 === $beforeWrite && !isset($GLOBALS['_JEAB_PDO_IN_TX_THROWS']),
    '2006 när inTransaction kastar skriver inte om INSERT, rader ' . $wrote21 . ' kod ' . $writeCode
);

exit($fail > 0 ? 1 : 0);
