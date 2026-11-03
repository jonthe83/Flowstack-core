<?php

declare(strict_types=1);

/** Ordrarnas index: tom SQL-tabell ger tom lista, saknad tabell kastar och fångas. Inga kunddata. */

$tmp = sys_get_temp_dir() . '/jeab-ws-empty-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function we(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;
jeab_log_pdo();
$threw = false;
try {
    jeab_webshop_index();
} catch (RuntimeException) {
    $threw = true;
}
we($threw, 'tom tabell utan flagga kastar (ej migrerat, ingen tom lista som kan ge dubbletter)');
$held = false;
try {
    jeab_inkorg_find_order('90001');
} catch (RuntimeException) {
    $held = true;
}
we($held, 'find_order kastar vidare; fångsten ligger hos anroparen');
jeab_log_flag_set($pdo, 'webshop_orders');
we(jeab_log_webshop_read() === [], 'flaggad tom tabell ger tom lista, inte null');
we(jeab_webshop_index() === [], 'indexet är tomt utan kast');
we(jeab_inkorg_find_order('90001') === null, 'orderbekräftelsen hittar ingen order och kastar inte');

$pdo->exec('DROP TABLE log_webshop_orders');
we(jeab_log_webshop_read() === null, 'saknad tabell ger null');
$threw = false;
try {
    jeab_webshop_index();
} catch (RuntimeException) {
    $threw = true;
}
we($threw, 'saknad tabell kastar RuntimeException (webshop.php:42)');
$src = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
we(str_contains($src, "catch (RuntimeException \$e) {\n        // Ordrarnas SQL"), 'hook.php fångar RuntimeException för webshop-hooken');
$auto = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
we(str_contains($auto, "'why' => 'Ordrar gick inte att läsa'"), 'orderbekräftelsen håller tråden när Ordrar inte går att läsa');
echo "klart\n";
