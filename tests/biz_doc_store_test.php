<?php

declare(strict_types=1);

/** Mindre biz_docs i SQL. Fingeravtryck, ingen kunddata. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-biz-doc-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/biz_doc_store.php';
require dirname(__DIR__) . '/src/biz_doc_sql_migrate.php';

function bd(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

bd(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'biz_doc.sql_migrate'), 'hook tar biz_doc.sql_migrate');
bd(jeab_biz_doc_storage_mode('code_people') === 'json', 'flagga av');
bd(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/biz_doc_store.php'), 'jeab_sql_store_pdo'), 'live mysql-väg finns');

file_put_contents(jeab_biz_doc_json_path('webshop_catchup'), json_encode(['date' => '2026-01-01']));
$GLOBALS['_JEAB_BIZ_DOC_LEGACY_SQL'] = true;
bd(jeab_biz_doc_storage_mode('webshop_catchup') === 'sql', 'mysql live är sql utan flagga');
bd((jeab_biz_doc_load('webshop_catchup')['date'] ?? '') === '2026-01-01', 'läser json tills sql har data');
jeab_biz_doc_save('webshop_catchup', ['date' => '2026-10-20', 'at' => 1]);
bd((jeab_biz_doc_sql_read('webshop_catchup')['date'] ?? '') === '2026-10-20', 'skriver sql');
bd(str_contains((string) file_get_contents(jeab_biz_doc_json_path('webshop_catchup')), '2026-01-01'), 'json bara läsning');
bd(!str_contains((string) file_get_contents(jeab_biz_doc_json_path('webshop_catchup')), '2026-10-20'), 'ny data hamnar inte i json');
$noop = jeab_biz_doc_sql_cutover(['entity' => 'webshop_catchup', 'enable' => 1, 'dry' => 0]);
bd(($noop['noop'] ?? '') === 'mysql_already_sql', 'cutover no-op på live');
unset($GLOBALS['_JEAB_BIZ_DOC_LEGACY_SQL']);
bd(jeab_biz_doc_storage_mode('webshop_catchup') === 'json', 'sqlite utan flagga är json');

$fp = 'a1b2c3d4e5f67890';
file_put_contents(jeab_biz_doc_json_path('code_people'), json_encode([
    'fp:' . $fp => ['person' => 'Elin', 'via' => 'code', 'at' => '2026-01-01 00:00:00'],
], JSON_PRETTY_PRINT));

$migrate = jeab_biz_doc_sql_migrate(['entity' => 'code_people', 'dry' => 0]);
bd(!empty($migrate['ok']) && (int) ($migrate['docs']['code_people']['json_only'] ?? 1) === 0, 'migrate code_people');

jeab_biz_doc_sql_cutover(['entity' => 'code_people', 'enable' => 1, 'dry' => 0]);
jeab_biz_doc_save('code_people', ['fp:' . $fp => ['person' => 'Kim', 'via' => 'code', 'at' => '2026-02-01 00:00:00']]);
$loaded = jeab_biz_doc_load('code_people');
bd(($loaded['fp:' . $fp]['person'] ?? '') === 'Kim', 'sql-skrivning');
bd(!str_contains((string) file_get_contents(jeab_biz_doc_json_path('code_people')), 'Kim'), 'json oförändrad');

$verify = jeab_biz_doc_sql_verify(['entity' => 'all']);
bd(!empty($verify['ok']), 'verify all');
echo "biz_doc_store_test ok\n";
