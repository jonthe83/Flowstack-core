<?php

declare(strict_types=1);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ord-del-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/ordrar_deleted_sql_migrate.php';

function od(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL'] = true;
$pdo = jeab_ordrar_deleted_sql_pdo();
od($pdo instanceof PDO, 'live-väg utan flagga');
jeab_sql_doc_put($pdo, JEAB_ORDRAR_DELETED_DOC_KEY, ['ids' => ['900002' => ['at' => '2026-02-01', 'hs' => [], 'booking_id' => 0]], 'hs' => []]);
unset($GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL']);

$doc = ['ids' => ['900001' => ['at' => '2026-01-01', 'hs' => [], 'booking_id' => 0]], 'hs' => []];
file_put_contents(jeab_ordrar_deleted_path(), json_encode($doc, JSON_UNESCAPED_UNICODE));

$GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL'] = true;
$m = jeab_ordrar_deleted_sql_migrate(['dry' => 0]);
od((int) ($m['inserted_ids'] ?? 0) === 1, 'migrate lägger bara json-only id');
$merged = jeab_ordrar_deleted_doc();
od(isset($merged['ids']['900001'], $merged['ids']['900002']), 'sql behåller båda');
od(!str_contains((string) file_get_contents(jeab_ordrar_deleted_path()), '900002'), 'json oförändrad av sql-läsning');
$verify = jeab_ordrar_deleted_sql_verify([]);
od((int) ($verify['json_ids'] ?? -1) === 1 && (int) ($verify['sql_ids'] ?? -1) === 2, 'verify antal');
od(($verify['checksum_match'] ?? true) === false && strlen((string) ($verify['json_checksum'] ?? '')) === 64, 'checksum skiljer när antalen skiljer');

$noop = jeab_ordrar_deleted_sql_cutover(['enable' => 1, 'dry' => 0]);
od(($noop['noop'] ?? '') === 'mysql_already_sql', 'cutover no-op live');
unset($GLOBALS['_JEAB_ORDRAR_DELETED_LEGACY_SQL']);

echo "ordrar_deleted_store_test ok\n";
