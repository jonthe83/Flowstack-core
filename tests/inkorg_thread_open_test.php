<?php

declare(strict_types=1);

/**
 * Öppna ett stängt ärende (Historik) ska läsa den tråden och dess mejl,
 * inte hela meddelandetabellen, trådtabellen, ordertabellen, kontaktboken
 * eller Help Scout-indexet. När ddl-flaggan är ok skapas index inte om.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixopen-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;

function openok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
openok(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
openok(jeab_settings_put($pdo, 'sqlite', 'kontakter_storage', 'sql'), 'kontaktflaggan går att sätta');
openok(jeab_inkorg_storage_mode() === 'sql', 'inkorgen kör storage sql');
$store = jeab_inkorg_pdo_store();
openok($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$fat = str_repeat('Långt brev till kunden. ', 80);
$n = 80;
for ($i = 1; $i <= $n; $i++) {
    $id = sprintf('fill%03d', $i);
    $store->upsertMessage($id . 'm', [
        'id' => $id . 'm',
        'thread_id' => $id,
        'from' => 'annan' . $i . '@exempel.test',
        'from_name' => 'Annan ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Annat ärende ' . $i,
        'body' => $fat,
        'received_at' => sprintf('2024-01-%02dT08:%02d:00+00:00', ($i % 27) + 1, $i % 60),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Support'],
    ]);
    $store->upsertThread($id, [
        'status' => $i % 3 === 0 ? 'closed' : 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'customer_email' => 'annan' . $i . '@exempel.test',
        'customer_name' => 'Annan ' . $i,
        'updated_at' => sprintf('2024-01-%02dT08:%02d:00+00:00', ($i % 27) + 1, $i % 60),
    ]);
}

$marcin = 'marcin.matulewicz@exempel.test';
$store->upsertMessage('marcin-in', [
    'id' => 'marcin-in',
    'thread_id' => 't-marcin',
    'from' => $marcin,
    'from_name' => 'Marcin Matulewicz',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Solceller till villan',
    'body' => 'Hej, jag vill ha en offert på solceller.',
    'received_at' => date('c', time() - 86400),
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Ny lead'],
]);
$store->upsertMessage('marcin-out', [
    'id' => 'marcin-out',
    'thread_id' => 't-marcin-annan',
    'from' => 'kontakt@jarfalla.example.test',
    'from_name' => 'JEAB',
    'to' => $marcin,
    'subject' => 'Offert solceller',
    'body' => "Hej Marcin.\n[Bilaga: Offert.pdf]\n",
    'received_at' => date('c', time() - 3600),
    'source' => 'outbound-graph',
    'labels' => ['type' => 'Information'],
]);
$store->upsertThread('t-marcin', [
    'status' => 'closed',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'customer_email' => $marcin,
    'customer_name' => 'Marcin Matulewicz',
    'updated_at' => date('c', time() - 86400),
]);
$store->upsertThread('t-marcin-annan', [
    'status' => 'closed',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'customer_email' => $marcin,
    'customer_name' => 'Marcin Matulewicz',
    'updated_at' => date('c', time() - 3600),
]);
openok(true, 'laddade ' . $n . ' andra ärenden och Marcins historik');

$purchasePdo = jeab_purchase_sql_live();
openok($purchasePdo instanceof PDO, 'order-SQL finns');
for ($i = 1; $i <= 24; $i++) {
    jeab_sql_purchases_put($purchasePdo, [
        'reminder_id' => 880000 + $i,
        'kind' => 'sol',
        'customer_name' => 'Tung ' . $i,
        'notes' => str_repeat('order ', 200),
    ]);
}

for ($i = 1; $i <= 20; $i++) {
    $id = 'hsi-' . substr(hash('sha256', 'open-' . $i), 0, 16);
    openok(jeab_hs_import_index_append([
        'id' => $id,
        'from' => 'arkiv' . $i . '@exempel.test',
        'snippet' => str_repeat('arkivrad ', 40),
        'subject' => 'Arkiv ' . $i,
        'company' => 'jarfalla',
        'status' => 'closed',
        'message_ids' => [],
    ]), 'Help Scout-rad ' . $i);
}
$marcinHs = 'hsi-' . substr(hash('sha256', 'marcin-hs'), 0, 16);
openok(jeab_hs_import_index_append([
    'id' => $marcinHs,
    'from' => $marcin,
    'snippet' => 'Hej Marcin',
    'subject' => 'Tidigare',
    'company' => 'jarfalla',
    'status' => 'closed',
    'message_ids' => [],
]), 'Marcins Help Scout-rad');

$need = [
    'inkorg_messages_thread_id',
    'inkorg_messages_thread_date',
    'inkorg_threads_status',
    'inkorg_threads_id_status',
];
$msgIdx = jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite');
$thrIdx = jeab_existing_index_names($pdo, 'inkorg_threads', 'sqlite');
$have = array_merge($msgIdx, $thrIdx);
foreach ($need as $name) {
    openok(in_array($name, $have, true), 'index ' . $name . ' finns');
}
jeab_inkorg_ensure_open_indexes($pdo, 'sqlite');
openok(in_array('inkorg_messages_thread_date', jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite'), true), 'indexet kan skapas igen');

$side = $tmp . '/open-side.json';
$runner = $tmp . '/child.php';
file_put_contents($runner, <<<'PHP'
<?php
$tmp = getenv('JEAB_OPEN_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
register_shutdown_function(static function (): void {
    $log = function_exists('jeab_sql_stats') ? jeab_sql_stats()['log'] : [];
    $fullMsg = false;
    $fullThread = false;
    $fullHs = false;
    $whereThread = false;
    foreach ($log as $sql) {
        $trim = trim((string) $sql);
        if (preg_match('/select\s+row_json\s+from\s+inkorg_messages\s*$/i', $trim) === 1) {
            $fullMsg = true;
        }
        if (preg_match('/select\s+id,\s*row_json\s+from\s+inkorg_threads\s*$/i', $trim) === 1) {
            $fullThread = true;
        }
        if (preg_match('/select\s+payload\s+from\s+jeab_hs_import_index\s*$/i', $trim) === 1) {
            $fullHs = true;
        }
        if (preg_match('/from\s+inkorg_messages\s+where\s+thread_id\s*=/i', $trim) === 1) {
            $whereThread = true;
        }
    }
    file_put_contents((string) getenv('JEAB_OPEN_SIDE'), json_encode([
        'messages' => (int) ($GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] ?? 0),
        'threads' => (int) ($GLOBALS['_JEAB_INKORG_THREADS_ALL'] ?? 0),
        'purchases' => (int) ($GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] ?? 0),
        'hs' => (int) ($GLOBALS['_JEAB_HS_INDEX_FULL'] ?? 0),
        'contacts' => (int) ($GLOBALS['_JEAB_CONTACTS_SQL_ALL'] ?? 0),
        'kontakter' => (int) ($GLOBALS['_JEAB_KONTAKTER_LOADS'] ?? 0),
        'fullMsg' => $fullMsg,
        'fullThread' => $fullThread,
        'fullHs' => $fullHs,
        'whereThread' => $whereThread,
    ], JSON_UNESCAPED_UNICODE));
});
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = [
    'p' => 'inkorg',
    'box' => 'jarfalla',
    'view' => 'historik',
    'id' => 't-marcin',
    'ix_part' => 'thread',
];
require $root . '/src/pages/inkorg.php';
PHP);
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    [
        'JEAB_OPEN_TMP' => $tmp,
        'JEAB_ROOT' => $root,
        'JEAB_OPEN_SIDE' => $side,
        'JEAB_DISABLE_SQLITE' => '0',
        'CONTACTS_SOLE' => '1',
    ]
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
$json = json_decode((string) $out, true);
openok($code === 0 && is_array($json) && ($json['ok'] ?? false) === true, 'ix_part=thread svarar ' . trim((string) $err) . ' ' . substr((string) $out, 0, 240));
$html = (string) ($json['html'] ?? '');
openok(str_contains($html, 'Solceller till villan') && str_contains($html, 'Marcin Matulewicz'), 'tråden ritas');
$sideJson = is_file($side) ? json_decode((string) file_get_contents($side), true) : null;
openok(is_array($sideJson), 'räknarna skrevs');
foreach ([
    'messages' => 'jeab_inkorg_messages',
    'threads' => 'alla trådar',
    'purchases' => 'jeab_purchase_all',
    'hs' => 'hela Help Scout-indexet',
    'contacts' => 'alla kontakter',
    'kontakter' => 'hela kontaktboken',
] as $key => $label) {
    openok((int) ($sideJson[$key] ?? 1) === 0, 'öppningen anropar inte ' . $label . ' (' . ($sideJson[$key] ?? '?') . ')');
}
openok(empty($sideJson['fullMsg']) && empty($sideJson['fullThread']) && empty($sideJson['fullHs']), 'ingen SELECT utan WHERE mot hela tabellen');
openok(!empty($sideJson['whereThread']), 'mejlen hämtas med WHERE thread_id');

$pdo->exec('DROP INDEX IF EXISTS inkorg_messages_thread_date');
$pdo->exec('DROP INDEX IF EXISTS inkorg_threads_id_status');
openok(!in_array('inkorg_messages_thread_date', jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite'), true), 'datumindexet är borta före omstart');
openok(jeab_settings_value($pdo, 'sqlite', 'inkorg_schema_v2') === 'ok', 'schemaflaggan är redan ok');
openok(jeab_settings_value($pdo, 'sqlite', 'inkorg_ddl_v3') === 'ok', 'ddl-flaggan är redan ok');
$idxRunner = $tmp . '/idx.php';
file_put_contents($idxRunner, <<<'PHP'
<?php
$tmp = getenv('JEAB_OPEN_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
require $root . '/src/bootstrap.php';
$pdo = jeab_pdo_connect_sqlite();
if (getenv('JEAB_OPEN_RESET_DDL') === '1') {
    foreach (['inkorg_ddl_v3', 'inkorg_open_indexes_v1', 'inkorg_list_status_v1', 'hs_import_ddl_v1'] as $key) {
        $pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute([$key]);
    }
}
jeab_sql_stats_reset();
jeab_ensure_inkorg_tables($pdo, 'sqlite');
$schema = false;
foreach (jeab_sql_stats()['log'] as $sql) {
    if (preg_match('/\b(SHOW\s+INDEX|SHOW\s+COLUMNS|CREATE\s+|ALTER\s+TABLE|PRAGMA\s+table_info|sqlite_master)\b/i', (string) $sql) === 1) {
        $schema = true;
    }
}
$names = array_merge(
    jeab_existing_index_names($pdo, 'inkorg_messages', 'sqlite'),
    jeab_existing_index_names($pdo, 'inkorg_threads', 'sqlite'),
    jeab_existing_index_names($pdo, 'jeab_hs_import_index', 'sqlite')
);
echo json_encode(['names' => array_values(array_unique($names)), 'schema' => $schema]);
PHP);
$idxProc = proc_open(
    [PHP_BINARY, $idxRunner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $idxPipes,
    null,
    ['JEAB_OPEN_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
$idxOut = stream_get_contents($idxPipes[1]);
$idxErr = stream_get_contents($idxPipes[2]);
fclose($idxPipes[1]);
fclose($idxPipes[2]);
$idxCode = proc_close($idxProc);
$idx = json_decode((string) $idxOut, true);
openok($idxCode === 0 && is_array($idx), 'index-barn svarar ' . trim((string) $idxErr));
$idxNames = is_array($idx['names'] ?? null) ? $idx['names'] : [];
openok(empty($idx['schema']), 'flaggan ok ger ingen CREATE eller SHOW INDEX');
openok(!in_array('inkorg_messages_thread_date', $idxNames, true), 'borttaget datumindex skapas inte när ddl-flaggan är ok');
openok(!in_array('inkorg_threads_id_status', $idxNames, true), 'borttaget trådindex skapas inte när ddl-flaggan är ok');
openok(in_array('jeab_hs_import_index_from', $idxNames, true), 'Help Scout-indexet på from_addr finns');
$redo = proc_open(
    [PHP_BINARY, $idxRunner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $redoPipes,
    null,
    ['JEAB_OPEN_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0', 'JEAB_OPEN_RESET_DDL' => '1']
);
$redoOut = stream_get_contents($redoPipes[1]);
$redoErr = stream_get_contents($redoPipes[2]);
fclose($redoPipes[1]);
fclose($redoPipes[2]);
$redoCode = proc_close($redo);
$redoIdx = json_decode((string) $redoOut, true);
openok($redoCode === 0 && is_array($redoIdx), 'omstart av index svarar ' . trim((string) $redoErr));
$redoNames = is_array($redoIdx['names'] ?? null) ? $redoIdx['names'] : [];
openok(in_array('inkorg_messages_thread_date', $redoNames, true), 'borttaget datumindex skapas när ddl-flaggan tas bort');
openok(in_array('inkorg_threads_id_status', $redoNames, true), 'borttaget trådindex skapas när ddl-flaggan tas bort');

echo "inkorg thread open: ok\n";
