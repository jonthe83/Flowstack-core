<?php

declare(strict_types=1);

/**
 * data.cutover_all. Torrkörning ändrar inget. Backup, snabbkoll och rollback.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !class_exists('ZipArchive')) {
    echo "SKIP sqlite eller zip saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-cutover-all-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/privat', 0700, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$queue = ['th-1' => ['at' => 10], 'th-2' => ['at' => 20], 'th-3' => ['at' => 30]];
$queuePath = $tmp . '/quote-queue.json';
file_put_contents($queuePath, json_encode($queue, JSON_UNESCAPED_UNICODE));
$queueHash = hash('sha256', (string) file_get_contents($queuePath));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-cutover']));
require $root . '/src/bootstrap.php';

function ca(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$hook = (string) file_get_contents($root . '/hook.php');
ca(str_contains($hook, "'data.cutover_all'") && str_contains($hook, "'data.rollback_type'"), 'hook tar båda kommandona');
ca(!in_array('contacts', jeab_cutover_all_types(), true) && !in_array('inbox', jeab_cutover_all_types(), true), 'kontakter och inkorg ingår inte');
ca(!in_array('external_queue', jeab_cutover_all_types(), true) && !in_array('login_throttle', jeab_cutover_all_types(), true), 'kön och inloggning ingår inte');

$dry = jeab_cutover_all([]);
ca(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']), 'torrkörning är grön');
ca(hash('sha256', (string) file_get_contents($queuePath)) === $queueHash, 'torrkörning lämnar filen');
ca(!is_dir($tmp . '/privat/backup'), 'torrkörning tar ingen backup');
ca(!jeab_data_type_forced('sql', 'quote_queue'), 'torrkörning sätter ingen flagga');

$nope = jeab_cutover_all(['type' => 'finns_inte', 'write' => 1]);
ca(empty($nope['ok']) && ($nope['error'] ?? '') === 'Typen körs inte här.' && empty($nope['written']), 'okänd typ stoppas');
$skipped = jeab_cutover_all(['type' => 'auto_send_cap', 'write' => 1]);
ca(empty($skipped['ok']) && ($skipped['error'] ?? '') === 'Typen körs inte här.', 'avstängd typ stoppas');
$staged = jeab_cutover_all(['type' => 'contacts', 'write' => 1]);
ca(empty($staged['ok']) && ($staged['error'] ?? '') === 'Typen körs inte här.', 'staged modul-id stoppas');
$plain = jeab_cutover_all(['type' => 'quote_queue']);
ca(!empty($plain['ok']) && !empty($plain['dry']) && count($plain['types'] ?? []) === 1 && (($plain['types'][0]['type'] ?? '') === 'quote_queue'), 'en vanlig typ torrkörs');
unset($GLOBALS['_JEAB_SCHEMA_DDL']);
$stepDry = jeab_cutover_all(['step' => 'backup']);
ca(!empty($stepDry['ok']) && !empty($stepDry['dry']) && empty($stepDry['written']) && empty($stepDry['reused']), 'backupsteget torrkör');
ca((glob($tmp . '/privat/backup/data-privat*.zip') ?: []) === [], 'torr backup skriver ingen zip');
ca(!array_key_exists('_JEAB_SCHEMA_DDL', $GLOBALS), 'backupsteget sätter ingen DDL');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_cutover_all_dispatch(['action' => 'data.cutover_all', 'write' => 1]);
ca(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');

$done = jeab_cutover_one('quote_queue', true);
ca(!empty($done['ok']) && !empty($done['written']) && !empty($done['sql']), 'omslaget skriver sql');
ca(!is_file($queuePath), 'json raderas efter grön snabbkoll');
ca(is_file($tmp . '/privat/backup') || is_dir($tmp . '/privat/backup'), 'backupen finns');
$backup = jeab_cutover_latest_backup('quote_queue');
$zipFile = is_array($backup) ? jeab_cutover_backup_zip_file($backup) : '';
ca(is_array($backup) && $zipFile !== '' && is_file($zipFile) && is_file($backup['dir'] . '/tables.dump'), 'zip och dump sparas');
ca(dirname($zipFile) === $tmp . '/privat/backup', 'zipen ligger i backup-roten');
ca((int) ($GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] ?? 0) === 1, 'första typen zippar en gång');
ca(jeab_data_type_forced('sql', 'quote_queue'), 'typen är sql');

$rolled = jeab_cutover_rollback_type(['type' => 'quote_queue', 'write' => 1]);
ca(!empty($rolled['ok']) && is_file($queuePath), 'rollback skriver tillbaka filen');
ca(hash('sha256', (string) file_get_contents($queuePath)) === $queueHash, 'rollback återställer innehållet');
ca(jeab_data_type_forced('json', 'quote_queue') && !jeab_data_type_forced('sql', 'quote_queue'), 'rollback sätter json');

$GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL'] = static function (string $id): void {
    if ($id !== 'quote_queue') {
        return;
    }
    $pdo = jeab_data_pdo();
    if ($pdo instanceof PDO) {
        $pdo->prepare('UPDATE quote_queue SET payload = ? WHERE doc_key = ?')->execute(['{"th-1":{"at":99}}', 'quote_queue']);
    }
};
$aborted = jeab_cutover_one('quote_queue', true);
unset($GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL']);
ca(empty($aborted['ok']) && !empty($aborted['aborted']), 'ändrad rad avbryter typen');
ca(is_file($queuePath) && hash('sha256', (string) file_get_contents($queuePath)) === $queueHash, 'avbrottet raderar inte filen');
ca(!jeab_data_type_forced('sql', 'quote_queue'), 'avbrottet slår inte om typen');
ca((int) ($GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] ?? 0) === 1, 'avbrottet zippar inte om');

$built = (int) ($GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] ?? 0);
$other = jeab_cutover_all(['type' => 'sent_check', 'write' => 1]);
$otherBackup = jeab_cutover_latest_backup('sent_check');
ca(!empty($other['ok']) && !empty($other['written']) && count($other['types'] ?? []) === 1, 'sent_check körs ensam');
ca((int) ($GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] ?? 0) === $built, 'nästa typ återanvänder zipen');
ca(is_array($otherBackup) && jeab_cutover_backup_zip_file($otherBackup) === $zipFile && is_file($otherBackup['dir'] . '/tables.dump'), 'samma zip, egen tabelldump');
$archive = new ZipArchive();
ca($archive->open($zipFile) === true, 'zipen går att läsa');
$zippedBackup = false;
for ($i = 0; $i < $archive->numFiles; $i++) {
    $name = (string) $archive->getNameIndex($i);
    if (str_starts_with($name, 'privat/backup') || str_contains($name, '/backup/')) {
        $zippedBackup = true;
    }
}
$archive->close();
ca(!$zippedBackup, 'backup-mappen zippades inte');
$step = jeab_cutover_all(['step' => 'backup', 'write' => 1]);
ca(!empty($step['ok']) && !empty($step['reused']) && empty($GLOBALS['_JEAB_SCHEMA_DDL']), 'färsk zip återanvänds utan DDL');
touch($zipFile, time() - 1801);
clearstatcache(true, $zipFile);
unset($GLOBALS['_JEAB_CUTOVER_TREE_ZIP']);
$rebuilt = jeab_cutover_ensure_tree_zip(true);
ca(!empty($rebuilt['ok']) && empty($rebuilt['reused']) && (int) ($GLOBALS['_JEAB_CUTOVER_ZIP_BUILDS'] ?? 0) === $built + 1, 'zip äldre än 30 min byggs om');
ca(is_file($zipFile) && (string) ($rebuilt['path'] ?? '') !== $zipFile, 'den gamla zipen skrivs inte över');

$same = jeab_cutover_row_fields(
    ['payload' => '{"b":1,"a":"2","at":"2026-01-02T03:04:05"}'],
    ['payload' => '{"a":2,"b":1,"at":"2026-01-02 03:04:05"}'],
    ['payload']
);
ca($same === [], 'nyckelordning, tal och tidsstämpel räknas som lika');
ca(jeab_cutover_stamp_newer('2026-02-01 00:00:00', '2026-01-01 00:00:00'), 'nyare sql-tid släpps igenom');
$real = jeab_cutover_row_fields(
    ['payload' => '{"note":"kvar"}'],
    ['payload' => '{"note":"annan"}'],
    ['payload']
);
ca($real !== [] && ($real[0]['name'] ?? '') === 'payload.note' && !str_contains(json_encode($real), 'annan'), 'verklig skillnad ger fältnamn utan värde');

$dryKeep = jeab_cutover_all(['type' => 'quote_queue', 'delete' => 0]);
ca(!empty($dryKeep['dry']) && in_array('keep_json', $dryKeep['types'][0]['would'] ?? [], true) && empty($dryKeep['json_kept']), 'delete=0 torrkör och behåller json');

$queueBefore = hash('sha256', (string) file_get_contents($queuePath));
$kept = jeab_cutover_all(['type' => 'quote_queue', 'write' => 1, 'keep_json' => 1]);
$keptJson = json_encode($kept);
$pdo = jeab_data_pdo();
$sqlPayload = $pdo instanceof PDO ? (string) $pdo->query("SELECT payload FROM quote_queue WHERE doc_key = 'quote_queue'")->fetchColumn() : '';
ca(!empty($kept['ok']) && !empty($kept['json_kept']) && (int) ($kept['differ'] ?? 0) > 0, 'keep_json sätter sql och visar differ');
ca(is_file($queuePath) && hash('sha256', (string) file_get_contents($queuePath)) === $queueBefore, 'keep_json raderar inte filen');
ca(jeab_data_type_forced('sql', 'quote_queue'), 'keep_json slår om flaggan trots skillnad');
ca(str_contains($sqlPayload, '99'), 'backfill skriver inte över raden som redan fanns');
ca(is_string($keptJson) && !str_contains($keptJson, '99'), 'svaret har fältnamn men inga värden');
$names = [];
foreach ($kept['types'][0]['diffs'] ?? [] as $diff) {
    foreach ($diff['fields'] ?? [] as $field) {
        $names[] = (string) ($field['name'] ?? '');
    }
}
ca($names !== [] && $names === array_filter($names, static fn (string $name): bool => !str_contains($name, '99')), 'differ-raden namnger fält');

$timeOnly = jeab_cutover_row_fields(
    ['watermark' => '2026-01-02T03:04:05Z', 'payload' => '{"note":"samma","at":"2026-01-02T01:00:00Z"}'],
    ['watermark' => '2026-01-02 04:04:05', 'payload' => '{"note":"samma","at":"2026-01-02 02:00:00"}'],
    ['watermark', 'payload']
);
ca(jeab_cutover_real_fields($timeOnly) === [], 'ren tidsstämpel är inte en fältförlust');
$lostVia = jeab_cutover_real_fields(jeab_cutover_row_fields(
    ['payload' => '{"via":"prefix","at":"2026-01-02T03:04:05Z"}'],
    ['payload' => '{"via":"code","at":"2026-01-02 04:04:05"}'],
    ['payload']
));
ca($lostVia !== [] && ($lostVia[0]['name'] ?? '') === 'payload.via', 'via är en riktig skillnad även när at bara är tid');
$missing = jeab_cutover_real_fields(jeab_cutover_row_fields(
    ['payload' => '{"via":"prefix","note":"kvar"}'],
    ['payload' => '{"note":"kvar"}'],
    ['payload']
));
ca($missing !== [] && ($missing[0]['kind'] ?? '') === 'saknas_i_sql', 'saknat riktigt fältnamn stoppar');

$hs = jeab_migrate_helpscout_import_module();
ca(($hs['primary_key'] ?? '') === 'id' && in_array('checkpoint', $hs['columns'] ?? [], true) && !in_array('payload', $hs['columns'] ?? [], true), 'helpscout jämför checkpoint, inte payload');
$hsRows = jeab_migrate_helpscout_import_rows(['mailboxes' => ['jarfalla' => ['page' => 1]]]);
ca((int) ($hsRows[0]['id'] ?? 0) === 1 && str_contains((string) ($hsRows[0]['checkpoint'] ?? ''), 'jarfalla') && !isset($hsRows[0]['payload']), 'helpscout-raden är id och checkpoint');

$prefer = jeab_code_people_prefer_newer(
    ['fp:aaaaaaaaaaaaaaaa' => ['person' => 'Elin', 'via' => 'prefix', 'at' => '2026-06-02 12:00:00']],
    [
        'fp:aaaaaaaaaaaaaaaa' => ['person' => 'Elin', 'via' => 'code', 'at' => '2026-01-01 00:00:00'],
        'fp:bbbbbbbbbbbbbbbb' => ['person' => 'Kim', 'via' => 'manual', 'at' => '2026-07-01 00:00:00'],
    ]
);
ca(($prefer['fp:aaaaaaaaaaaaaaaa']['via'] ?? '') === 'prefix', 'nyare SQL-via behålls framför filen');
ca(($prefer['fp:bbbbbbbbbbbbbbbb']['person'] ?? '') === 'Kim', 'fingeravtryck som bara finns i filen följer med');

$fig = jeab_cutover_page_figures_check('quote_queue');
ca(!empty($fig['ok']) && ($fig['drop'] ?? []) === [] && is_file($tmp . '/privat/cutover-page-figures.log'), 'sidans siffror loggas före flipp');
ca(str_contains((string) file_get_contents($tmp . '/privat/cutover-page-figures.log'), '"mina"'), 'loggen har Mina');

$linksHtml = require $root . '/src/pages/links.php';
ca(is_string($linksHtml) && str_contains($linksHtml, 'btn btn-danger') && str_contains($linksHtml, 'Logga ut från JEAB') && str_contains($linksHtml, 'name="action" value="logout"'), 'Övrigt har en utloggningsknapp');
ca(is_string($linksHtml) && !str_contains($linksHtml, 'is-logout'), 'utloggning är inte röd rad i listan');
$linkLabels = array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
ca(!in_array('Logga ut från JEAB', $linkLabels, true), 'listan under Övrigt har inte utloggning som länk');

$flagBack = jeab_cutover_rollback_type(['type' => 'quote_queue', 'write' => 1]);
$afterPayload = $pdo instanceof PDO ? (string) $pdo->query("SELECT payload FROM quote_queue WHERE doc_key = 'quote_queue'")->fetchColumn() : '';
ca(!empty($flagBack['ok']) && !jeab_data_type_forced('sql', 'quote_queue'), 'json kvar: rollback vrider bara flaggan');
ca(hash('sha256', (string) file_get_contents($queuePath)) === $queueBefore && $afterPayload === $sqlPayload, 'rollback rör varken filen eller sql');

echo "cutover_all_test ok\n";
