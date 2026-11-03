<?php

declare(strict_types=1);

/** Help Scout-arkivet ligger i SQL. JSON är bara läsreserv. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-hs-archive-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function hsarc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/hs_import_store.php');
$dbSrc = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$importSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_helpscout_import.php');
$malmoSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_malmo_hs.php');
hsarc(str_contains($storeSrc, 'CREATE TABLE IF NOT EXISTS jeab_hs_import_index'), 'index-tabellen skapas i hs_import_store');
hsarc(str_contains($storeSrc, 'CREATE TABLE IF NOT EXISTS jeab_hs_import_conv'), 'konversationstabellen skapas i hs_import_store');
hsarc(str_contains($storeSrc, 'CREATE TABLE IF NOT EXISTS jeab_hs_import_state'), 'checkpointen skapas i hs_import_store');
hsarc(!str_contains($dbSrc, 'jeab_hs_import_index') && !str_contains($dbSrc, 'jeab_hs_import_conv'), 'db.php har inte arkivtabellerna');
hsarc(str_contains($hookSrc, "'hs_archive.import'"), 'hook registrerar hs_archive.import');
hsarc(!str_contains($importSrc, "fopen(\$dir . '/index.jsonl'") && !str_contains($malmoSrc, 'file_put_contents'), 'importen och Malmö-markören skriver inte JSON');

$store = jeab_store();
hsarc($store instanceof JeabPdoStore, 'PDO-hjälparen är öppen');
$pdo = $store->pdo();

$tid = 'hsi-' . substr(hash('sha256', 'arkiv-exempel'), 0, 16);
$conv = [
    'id' => $tid,
    'company' => 'jarfalla',
    'status' => 'closed',
    'import_source' => 'helpscout_import',
    'messages' => [[
        'id' => 'abc123abc123abcd',
        'from' => 'kund.exempel@post.se',
        'subject' => 'Exempelärende',
        'body' => 'En påhittad rad.',
        'received_at' => '2026-01-02T10:00:00+00:00',
        'source' => 'helpscout_import',
    ]],
    'meta' => ['status' => 'closed', 'import_source' => 'helpscout_import'],
];
$indexRow = [
    'id' => $tid,
    'company' => 'jarfalla',
    'status' => 'closed',
    'subject' => 'Exempelärende',
    'from' => 'kund.exempel@post.se',
    'message_ids' => ['abc123abc123abcd'],
    'import_source' => 'helpscout_import',
];
$dir = jeab_hs_import_root() . '/c/' . substr($tid, 4, 2);
mkdir($dir, 0775, true);
file_put_contents($dir . '/' . $tid . '.json', json_encode($conv, JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_hs_import_root() . '/index.jsonl', json_encode($indexRow, JSON_UNESCAPED_UNICODE) . "\n");
$cpBody = '{"mailboxes":{"jarfalla":{"page":2,"watermark":"2026-01-01T00:00:00Z"}},"customers":{"9":"kt-exempel"}}';
file_put_contents(jeab_inkorg_dir() . '/helpscout-import.json', $cpBody);
$malmoBody = '{"watermark":"2026-02-02T00:00:00Z","page":3,"offset":1,"query":"2026-02-02T00:00:00Z"}';
file_put_contents(jeab_malmo_hs_state_path(), $malmoBody);
$before = (string) file_get_contents($dir . '/' . $tid . '.json')
    . (string) file_get_contents(jeab_hs_import_root() . '/index.jsonl')
    . (string) file_get_contents(jeab_inkorg_dir() . '/helpscout-import.json')
    . (string) file_get_contents(jeab_malmo_hs_state_path());
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);

$loaded = jeab_hs_import_read_conv($tid);
hsarc(is_array($loaded) && ($loaded['messages'][0]['subject'] ?? '') === 'Exempelärende', 'oläst SQL faller tillbaka på konversationsfilen');
hsarc((string) (jeab_hs_import_index()['by_id'][$tid]['subject'] ?? '') === 'Exempelärende', 'oläst SQL faller tillbaka på index.jsonl');
hsarc((int) ($pdo->query('SELECT COUNT(*) FROM jeab_hs_import_conv')->fetchColumn()) === 0, 'läsning skapar ingen SQL-rad');
hsarc((int) (jeab_hs_import_checkpoint()['mailboxes']['jarfalla']['page'] ?? 0) === 2, 'oläst SQL faller tillbaka på helpscout-import.json');
hsarc((string) (jeab_malmo_hs_state()['watermark'] ?? '') === '2026-02-02T00:00:00Z', 'oläst SQL faller tillbaka på malmo-hs-sync.json');

$dry = jeab_hs_archive_import_dispatch([]);
hsarc(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['inserted'] ?? 0) === 4, 'hs_archive.import saknar dry och är dry=1');
hsarc((int) $pdo->query('SELECT COUNT(*) FROM jeab_hs_import_conv')->fetchColumn() === 0, 'dry=1 skriver ingen konversation');
hsarc((int) $pdo->query('SELECT COUNT(*) FROM malmo_hs_cursor')->fetchColumn() === 0, 'dry=1 skriver ingen Malmö-markör');
$same = (string) file_get_contents($dir . '/' . $tid . '.json')
    . (string) file_get_contents(jeab_hs_import_root() . '/index.jsonl')
    . (string) file_get_contents(jeab_inkorg_dir() . '/helpscout-import.json')
    . (string) file_get_contents(jeab_malmo_hs_state_path());
hsarc($same === $before, 'dry=1 lämnar JSON');

$run = jeab_hs_archive_import_dispatch(['dry' => 0]);
hsarc((int) ($run['dry'] ?? 1) === 0 && (int) ($run['inserted'] ?? 0) === 4, 'hs_archive.import dry=0 tar in arkivet');
hsarc($same === $before, 'importen skriver inte JSON');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
hsarc((string) (jeab_hs_import_read_conv($tid)['messages'][0]['body'] ?? '') === 'En påhittad rad.', 'konversationen syns i SQL');
hsarc((int) (jeab_hs_import_checkpoint()['mailboxes']['jarfalla']['page'] ?? 0) === 2, 'checkpointen syns i SQL');
hsarc((string) (jeab_malmo_hs_state()['query'] ?? '') === '2026-02-02T00:00:00Z', 'Malmö-markören syns i SQL');

file_put_contents($dir . '/' . $tid . '.json', str_replace('Exempelärende', 'Andrad i json', (string) file_get_contents($dir . '/' . $tid . '.json')));
file_put_contents(jeab_inkorg_dir() . '/helpscout-import.json', str_replace('"page":2', '"page":9', $cpBody));
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
hsarc((string) (jeab_hs_import_read_conv($tid)['messages'][0]['subject'] ?? '') === 'Exempelärende', 'efter import är konversationsfilen bara reserv');
hsarc((int) (jeab_hs_import_checkpoint()['mailboxes']['jarfalla']['page'] ?? 0) === 2, 'efter import är helpscout-import.json bara reserv');
$again = jeab_hs_archive_import_dispatch(['dry' => '0']);
hsarc((int) ($again['inserted'] ?? 1) === 0, 'andra importen sätter in noll');

$fresh = 'hsi-' . substr(hash('sha256', 'ny-sql-rad'), 0, 16);
$freshConv = $conv;
$freshConv['id'] = $fresh;
$freshConv['messages'][0]['subject'] = 'Bara SQL';
hsarc(jeab_hs_import_save_conv($freshConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($freshConv)), 'ny rad skrivs');
hsarc(!is_file((string) jeab_hs_import_conv_path($fresh)) && !str_contains((string) file_get_contents(jeab_hs_import_root() . '/index.jsonl'), $fresh), 'ny rad skrivs inte till JSON');
hsarc((string) (jeab_hs_import_read_conv($fresh)['messages'][0]['subject'] ?? '') === 'Bara SQL', 'ny rad läses från SQL');

$names = array_map(static fn (array $col): string => (string) ($col['name'] ?? ''), $pdo->query('PRAGMA table_info(jeab_hs_import_conv)')->fetchAll());
hsarc(in_array('id', $names, true) && in_array('payload', $names, true), 'jeab_hs_import_conv finns i sqlite');

echo "hs import store ok\n";
