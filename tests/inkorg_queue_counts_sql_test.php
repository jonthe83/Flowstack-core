<?php

declare(strict_types=1);

/**
 * Kötal i SQL följer jeab_inkorg_queue_ok.
 * Sam L och Sam W hålls isär. Tom person är TRIM och JSON null.
 * Stängd är raderad, väntar på kund eller stängd, inte en avvikande list_status.
 * Flaggan inkorg_list_status_backfill sätts bara när en rad faktiskt fylldes.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixqsql-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once $root . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

function qc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
qc(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan');
$store = jeab_inkorg_pdo_store();
qc($store instanceof JeabPdoInkorgStore, 'SQL-lager');
qc(jeab_egna_me_id() === 'SamL', 'inloggad är Sam L');

$threads = [];
$add = static function (string $id, string $status, mixed $assignee, bool $deleted = false) use ($store, &$threads): void {
    $row = [
        'id' => $id,
        'company' => 'jarfalla',
        'status' => $status,
        'deleted' => $deleted,
        'assignee' => $assignee,
        'updated_at' => '2024-06-01T10:00:00+00:00',
        'customer_email' => $id . '@exempel.test',
        'customer_name' => $id,
        'labels' => ['type' => 'Support'],
        'tags' => [],
    ];
    $store->saveListRow($row);
    $threads[] = $row;
};
$add('jl-space', 'open', 'Sam L');
$add('jl-tight', 'open', 'SamL');
$add('jw', 'open', 'Sam W');
$add('jw-pad', 'open', '  Sam W  ');
$add('blank', 'open', '   ');
$add('json-null', 'open', null);
$add('wait-cust', 'waiting_customer', 'Sam L');
$add('parked', 'waiting_supplier', 'Sam L');
$add('closed', 'closed', '');
$add('deleted', 'open', 'Sam L', true);
$add('drift', 'open', 'Sam L');
$pdo->prepare('UPDATE inkorg_list SET list_status = ? WHERE id = ?')->execute(['closed', 'drift']);

qc($store->backfillListStatus() === 0, 'backfill utan tomma rader ändrar inget');
qc(!$store->listStatusBackfillDone(), 'flaggan sätts inte när inget fylldes');

$me = jeab_egna_me_id();
$sql = $store->queueCounts('jarfalla', $me);
$php = jeab_inkorg_queue_counts($threads);
qc($sql === $php, 'SQL-kötalen är samma som jeab_inkorg_queue_ok, fick ' . json_encode($sql) . ' mot ' . json_encode($php));
qc($sql === ['mina' => 3, 'otilldelade' => 2, 'oppna' => 7, 'parkerade' => 1, 'stangda' => 3], 'Mina är Sam L, otilldelade är tomma, stängda är inte parkerade, fick ' . json_encode($sql));
qc((int) $sql['mina'] === 3 && !jeab_inkorg_same_person('Sam L', 'Sam W'), 'Sam W räknas inte som Sam L');

$src = (string) file_get_contents($root . '/src/inkorg_store.php');
$queueAt = strpos($src, 'function queueCounts');
$queueBody = $queueAt === false ? '' : substr($src, $queueAt, 2200);
$scalarAt = strpos($src, 'function jsonScalar');
$scalarBody = $scalarAt === false ? '' : substr($src, $scalarAt, 500);
qc(str_contains($scalarBody, "NULLIF(\$raw, 'null')") && str_contains($queueBody, 'TRIM(') && str_contains($queueBody, 'REPLACE(TRIM('), 'Mina trimmar och Otilldelade tar bort JSON null');
qc(!str_contains($queueBody, "list_status IN ('closed', 'deleted', 'waiting_customer')"), 'Stängda följer status och raderad, inte bara list_status');

$store->saveListRow([
    'id' => 'fill-me',
    'company' => 'jarfalla',
    'status' => 'closed',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-02-01T00:00:00+00:00',
    'customer_email' => 'fill@exempel.test',
    'customer_name' => 'Fyll',
    'labels' => ['type' => 'Support'],
    'tags' => [],
]);
$pdo->prepare('UPDATE inkorg_list SET list_status = \'\' WHERE id = ?')->execute(['fill-me']);
qc($store->backfillListStatus() >= 1, 'backfill fyller en tom list_status');
qc($store->listStatusBackfillDone(), 'flaggan sätts när en rad fylldes');
$filled = $pdo->query("SELECT list_status FROM inkorg_list WHERE id = 'fill-me'")->fetchColumn();
qc((string) $filled === 'closed', 'tom list_status blir closed');

$store->saveListRow([
    'id' => 'later-empty',
    'company' => 'jarfalla',
    'status' => 'open',
    'deleted' => false,
    'assignee' => '',
    'updated_at' => '2024-02-02T00:00:00+00:00',
    'customer_email' => 'later@exempel.test',
    'customer_name' => 'Senare',
    'labels' => ['type' => 'Support'],
    'tags' => [],
]);
$pdo->prepare('UPDATE inkorg_list SET list_status = \'\' WHERE id = ?')->execute(['later-empty']);
qc($store->backfillListStatus() === 0, 'flaggan gör att en senare tom rad inte fylls om');
$later = $pdo->query("SELECT list_status FROM inkorg_list WHERE id = 'later-empty'")->fetchColumn();
qc((string) $later === '', 'den senare tomma raden ligger kvar');

$ids = [];
foreach ($store->listWorking('jarfalla') as $row) {
    $ids[] = (string) ($row['id'] ?? '');
}
qc(in_array('drift', $ids, true), 'stängd list_status med JSON-status open är kvar i arbetsvyn efter flaggan');
qc(in_array('later-empty', $ids, true), 'tom list_status är kvar i arbetsvyn');

echo "OK  kötal och list_status\n";
