<?php

declare(strict_types=1);

/**
 * 1203 i jeab_pdo_transaction kör callbacken en gång.
 * Det gäller tick och sms-cron likaväl som ett klick.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-tx-once-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 60000;
$GLOBALS['_JEAB_PDO_BG_BUDGET_US'] = 120000;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function once_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$probe = jeab_pdo_connect_sqlite();
$probe->exec('CREATE TABLE IF NOT EXISTS busy_probe (id INTEGER PRIMARY KEY, n INTEGER)');

$err = new PDOException("SQLSTATE[HY000] [1203] User already has more than 'max_user_connections' active connections", 1203);

$runTx = static function (bool $background) use ($probe, $err): array {
    $GLOBALS['_JEAB_PDO_BACKGROUND'] = $background;
    $GLOBALS['_JEAB_PDO_WAITED_US'] = 0;
    $hits = 0;
    $caught = '';
    $probe->exec('DELETE FROM busy_probe');
    try {
        jeab_pdo_transaction($probe, static function (PDO $db) use (&$hits, $err): void {
            $hits++;
            $db->exec('INSERT INTO busy_probe (n) VALUES (1)');
            throw $err;
        });
    } catch (JeabDbBusyException $e) {
        $caught = $e->getMessage();
    } catch (Throwable $e) {
        $caught = $e::class . ' ' . $e->getMessage();
    }
    $rows = (int) $probe->query('SELECT COUNT(*) FROM busy_probe')->fetchColumn();

    return [
        'hits' => $hits,
        'rows' => $rows,
        'caught' => $caught,
        'open' => $probe->inTransaction(),
        'waited' => (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0),
    ];
};

foreach ([[true, 'bakgrund'], [false, 'klick']] as [$background, $label]) {
    $got = $runTx($background);
    once_ok(
        $got['hits'] === 1 && $got['rows'] === 0 && $got['caught'] === jeab_pdo_busy_message() && $got['open'] === false && $got['waited'] === 0,
        $label . ' kör transaktionen en gång vid 1203, körningar ' . $got['hits'] . ' rader ' . $got['rows'] . ' väntan ' . $got['waited'] . ' ' . $got['caught']
    );
}

$calls = 0;
$GLOBALS['_JEAB_PDO_BACKGROUND'] = true;
$GLOBALS['_JEAB_PDO_WAITED_US'] = 0;
$callMsg = '';
try {
    jeab_pdo_call(static function () use (&$calls, $err): void {
        $calls++;
        throw $err;
    });
} catch (JeabDbBusyException $e) {
    $callMsg = $e->getMessage();
}
once_ok($calls === 1 && $callMsg === jeab_pdo_busy_message() && (int) ($GLOBALS['_JEAB_PDO_WAITED_US'] ?? 0) === 0, 'bakgrund kör frågan en gång vid 1203, körningar ' . $calls);

$db = (string) file_get_contents($root . '/src/db.php');
$open = substr($db, (int) strpos($db, 'function jeab_pdo_open_once'));
$open = substr($open, 0, (int) strpos($open, 'function jeab_pdo_pool_get'));
$tx = substr($db, (int) strpos($db, 'function jeab_pdo_transaction'));
$tx = substr($tx, 0, (int) strpos($tx, 'function jeab_db_slot_dir'));
$call = substr($db, (int) strpos($db, 'function jeab_pdo_call'));
$call = substr($call, 0, (int) strpos($call, 'function jeab_pdo_open_new'));
once_ok(str_contains($open, 'jeab_pdo_open_wait') && !str_contains($tx, 'jeab_pdo_busy_wait') && !str_contains($tx, 'jeab_pdo_open_wait') && !str_contains($call, 'jeab_pdo_busy_wait'), 'bara anslutningen väntar på 1203');

exit($fail > 0 ? 1 : 0);
