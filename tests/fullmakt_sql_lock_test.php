<?php

declare(strict_types=1);

/**
 * F1 låst signering: andra försöket är no-op och after_signed räknas till 1.
 * F2 SQL är enda sanningen. F3 en rad via prepared SELECT. F4 före-bilden
 * raderar inte en rad som läggs till under skrivningen. F12 logg-append.
 * F13 index på status. R1 PDF-tmp före UPDATE, rename efter commit.
 * R2 öppen transaktion ger LogicException. R3 pending-JSON läses inte
 * när SQL är på, och verify räknar JSON-bara dokument.
 * Påhittade rader. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-sql-lock-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/fullmakt', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_STEG4_CUTOVER'] = true;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":{}}');

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/store.php';
fullmakt_boot();

function fsl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_data_pdo();
fsl($pdo instanceof PDO, 'sqlite finns');
fsl(fullmakt_sql_active(), 'SQL-vägen är aktiv');

$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/store.php');
$getAt = strpos($storeSrc, 'function fullmakt_sql_get');
$getEnd = strpos($storeSrc, 'function fullmakt_read_json');
$getBody = $getAt !== false && $getEnd !== false ? substr($storeSrc, $getAt, $getEnd - $getAt) : '';
fsl(str_contains($getBody, 'SELECT payload FROM') && str_contains($getBody, 'WHERE token = ?'), 'F3 hämtar en rad med prepared SELECT');
fsl(!str_contains($getBody, 'jeab_cutover_read_rows'), 'F3 läser inte hela tabellen');

$markAt = strpos($storeSrc, 'function fullmakt_mark_signed_sql');
$markEnd = strpos($storeSrc, 'function fullmakt_write_signed_pdf');
$markBody = $markAt !== false && $markEnd !== false ? substr($storeSrc, $markAt, $markEnd - $markAt) : '';
fsl(str_contains($markBody, 'BEGIN IMMEDIATE') && str_contains($markBody, 'FOR UPDATE'), 'F1 låser med BEGIN IMMEDIATE och FOR UPDATE');
fsl(str_contains($markBody, "status <> 'signed'") && str_contains($markBody, 'rowCount()'), 'F1 kräver rowCount 1 och status inte signed');
fsl(str_contains($markBody, 'rollBack()'), 'F1 rullar tillbaka i finally');
fsl(!str_contains($markBody, 'fullmakt_read_json'), 'F2 läser inte JSON före signering');
$stageAt = strpos($markBody, 'fullmakt_stage_signed_pdf');
$updateAt = strpos($markBody, 'SET status');
$publishAt = strpos($markBody, 'fullmakt_publish_signed_pdf');
$afterAt = strpos($markBody, 'fullmakt_bokmalen_ellevio_after_signed');
$commitAfterStage = $stageAt !== false ? strpos($markBody, 'fullmakt_sql_tx_commit', $stageAt) : false;
$txBeginAt = strpos($markBody, 'fullmakt_sql_tx_begin(');
$openTxAt = strpos($markBody, 'inTransaction()');
fsl($stageAt !== false && $updateAt !== false && $stageAt < $updateAt, 'R1 skriver PDF-tmp före UPDATE');
fsl($commitAfterStage !== false && $publishAt !== false && $afterAt !== false && $commitAfterStage < $publishAt && $publishAt < $afterAt, 'R1 byter namn på PDF efter commit och kör after_signed sedan');
fsl(str_contains($markBody, 'fullmakt_discard_signed_pdf_tmp'), 'R1 rensar tmp-filen vid rollback');
fsl(str_contains($markBody, 'LogicException') && $openTxAt !== false && $txBeginAt !== false && $openTxAt < $txBeginAt, 'R2 kastar LogicException om transaktionen redan är öppen');

$module = jeab_migrate_find('fullmakt');
fsl(is_array($module), 'fullmakt-modulen finns');
$mysqlSchema = (string) ($module['schema']['mysql'] ?? '');
fsl(str_contains($mysqlSchema, 'fullmakt_docs_status'), 'F13 schemat har index på status');
jeab_migrate_ensure($pdo, $module);
jeab_migrate_ensure($pdo, $module);
$indexNames = [];
$indexStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'fullmakt_docs'");
foreach ($indexStmt instanceof PDOStatement ? $indexStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $indexRow) {
    if (is_array($indexRow)) {
        $indexNames[] = (string) ($indexRow['name'] ?? '');
    }
}
fsl(count(array_keys($indexNames, 'fullmakt_docs_status', true)) === 1, 'F13 indexet skapas en gång');

$created = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Ada Fullmakt',
    'epost' => 'ada.fullmakt@example.test',
    'telefon' => '0701000011',
]);
$token = (string) ($created['token'] ?? '');
fsl($token !== '' && fullmakt_sql_get($token)['namn'] === 'Ada Fullmakt', 'F3 hittar den skapade raden');

$other = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Bo Fullmakt',
    'epost' => 'bo.fullmakt@example.test',
    'telefon' => '0701000012',
]);
$otherToken = (string) ($other['token'] ?? '');
fsl(fullmakt_sql_get($token)['namn'] === 'Ada Fullmakt' && fullmakt_sql_get($otherToken)['namn'] === 'Bo Fullmakt', 'F3 hämtar rätt token');
fsl(fullmakt_sql_get('finns-inte') === null, 'F3 saknad token är null');

$public = jeab_data_public([
    'ok' => true,
    'payload' => 'Ada Fullmakt',
    'nested' => ['payload' => 'Ada Fullmakt', 'antal' => 1],
    'antal' => 2,
]);
$publicJson = (string) json_encode($public);
fsl(!array_key_exists('payload', $public) && !isset($public['nested']['payload']) && !str_contains($publicJson, 'Ada Fullmakt'), 'F3 hook-svar har ingen payload');
fsl(($public['antal'] ?? 0) === 2 && ($public['nested']['antal'] ?? 0) === 1, 'F3 hook-svar behåller antal');
$dry = jeab_migrate_copy($module, true);
fsl(!str_contains((string) json_encode($dry), 'Ada Fullmakt'), 'F3 migrate-svaret har ingen dokumenttext');

$jsonOnly = 'json' . bin2hex(random_bytes(3));
fullmakt_write_json(fullmakt_pending_path($jsonOnly), [
    'token' => $jsonOnly,
    'status' => 'pending',
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Json Bara',
]);
$GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] = 0;
$missingThrew = false;
try {
    fullmakt_mark_signed($jsonOnly, [], "%PDF-1.4 json\n");
} catch (RuntimeException $e) {
    $missingThrew = str_contains($e->getMessage(), 'hittades inte');
}
fsl($missingThrew, 'F2 saknad SQL-token är fel');
$jsonLeft = fullmakt_read_json(fullmakt_pending_path($jsonOnly));
fsl(is_array($jsonLeft) && ($jsonLeft['status'] ?? '') === 'pending', 'F2 JSON-filen signeras inte');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === 0 && !is_file(fullmakt_signed_pdf_path($jsonOnly)), 'F2 ingen sidoeffekt när token saknas');
fsl(fullmakt_load_doc($jsonOnly) === null, 'R3 pending-JSON läses inte när SQL är på');
$signedOnly = 'js' . bin2hex(random_bytes(3));
fullmakt_write_json(fullmakt_signed_json_path($signedOnly), [
    'token' => $signedOnly,
    'status' => 'signed',
    'type' => 'overlay',
    'namn' => 'Signerad Fil',
]);
fsl((fullmakt_load_doc($signedOnly)['namn'] ?? '') === 'Signerad Fil', 'R3 signerad JSON på disk får läsas');
$verify = fullmakt_sql_verify();
$verifyJson = (string) json_encode($verify);
fsl((int) ($verify['json_only'] ?? -1) === 2, 'R3 verify räknar JSON-bara dokument');
fsl(!str_contains($verifyJson, 'Json Bara') && !str_contains($verifyJson, 'Signerad Fil'), 'R3 verify har ingen dokumenttext');
$dryJson = jeab_migrate_copy($module, true);
fsl((int) ($dryJson['json_only'] ?? -1) === 2, 'R3 migrate-verify har samma räknare');
fsl(!str_contains((string) json_encode($dryJson), 'Json Bara'), 'R3 migrate-verify har ingen dokumenttext');

function fsl_pdf_tmps(): array
{
    $hits = [];
    if (!is_dir(FULLMAKT_SIGNED)) {
        return $hits;
    }
    foreach (scandir(FULLMAKT_SIGNED) ?: [] as $name) {
        if (is_string($name) && str_contains($name, '.tmp.')) {
            $hits[] = $name;
        }
    }

    return $hits;
}

$GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] = 0;
$firstPdf = "%PDF-1.4 forsta\n";
$signed = fullmakt_mark_signed($token, $created, $firstPdf);
fsl(($signed['status'] ?? '') === 'signed' && ($signed['namn'] ?? '') === 'Ada Fullmakt', 'F1 första signeringen skriver SQL');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === 1, 'F1 after_signed körs en gång');
fsl(is_file(fullmakt_signed_pdf_path($token)) && (string) file_get_contents(fullmakt_signed_pdf_path($token)) === $firstPdf, 'F1 PDF skrivs av vinnaren');
fsl((string) $pdo->query('SELECT status FROM fullmakt_docs WHERE token = ' . $pdo->quote($token))->fetchColumn() === 'signed', 'F1 status är signed');

$second = fullmakt_mark_signed($token, ['namn' => 'Ska Inte Vinna', 'type' => 'overlay', 'stamp_method' => 'fpdi'], "%PDF-1.4 andra\n");
fsl(($second['status'] ?? '') === 'signed' && ($second['namn'] ?? '') === 'Ada Fullmakt', 'F1 andra signeringen är no-op');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === 1, 'F1 after_signed räknas till 1');
fsl((string) file_get_contents(fullmakt_signed_pdf_path($token)) === $firstPdf, 'F1 PDF skrivs inte om');
fsl((int) $pdo->query('SELECT COUNT(*) FROM fullmakt_docs WHERE token = ' . $pdo->quote($token))->fetchColumn() === 1, 'F1 en rad');
fsl(fsl_pdf_tmps() === [], 'R1 lyckad signering lämnar ingen tmp-fil');

$regen = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Cajsa Fullmakt',
    'epost' => 'cajsa.fullmakt@example.test',
    'telefon' => '0701000013',
]);
$regenToken = (string) ($regen['token'] ?? '');
$regenPdf = "%PDF-1.4 cajsa\n";
$regenSigned = fullmakt_mark_signed($regenToken, $regen, $regenPdf);
fsl(($regenSigned['status'] ?? '') === 'signed' && is_file(fullmakt_signed_pdf_path($regenToken)), 'R1 Cajsa är signerad');
unlink(fullmakt_signed_pdf_path($regenToken));
$replacedPdf = "%PDF-1.4 cajsa-igen\n";
$regenSecond = fullmakt_mark_signed($regenToken, ['namn' => 'Ska Inte Vinna', 'type' => 'overlay', 'stamp_method' => 'fpdi'], $replacedPdf);
fsl(($regenSecond['namn'] ?? '') === 'Cajsa Fullmakt' && (string) file_get_contents(fullmakt_signed_pdf_path($regenToken)) === $replacedPdf, 'R1 saknad PDF skrivs om utan att röra SQL');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === 2, 'R1 omgenerering kör inte after_signed');
$regenThird = "%PDF-1.4 cajsa-tredje\n";
fullmakt_mark_signed($regenToken, ['type' => 'overlay', 'stamp_method' => 'fpdi'], $regenThird);
fsl((string) file_get_contents(fullmakt_signed_pdf_path($regenToken)) === $replacedPdf && fsl_pdf_tmps() === [], 'R1 omgenerering är idempotent när filen finns');

$roll = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Dani Fullmakt',
    'epost' => 'dani.fullmakt@example.test',
    'telefon' => '0701000014',
]);
$rollToken = (string) ($roll['token'] ?? '');
$pdo->exec('CREATE TRIGGER fullmakt_docs_abort_update BEFORE UPDATE ON fullmakt_docs BEGIN SELECT RAISE(ABORT, \'test-rollback\'); END');
$rolled = false;
try {
    fullmakt_mark_signed($rollToken, $roll, "%PDF-1.4 dani\n");
} catch (Throwable) {
    $rolled = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS fullmakt_docs_abort_update');
fsl($rolled, 'R1 avbruten UPDATE kastar');
fsl(!$pdo->inTransaction(), 'R1 rollback stänger transaktionen');
fsl((string) $pdo->query('SELECT status FROM fullmakt_docs WHERE token = ' . $pdo->quote($rollToken))->fetchColumn() === 'pending', 'R1 rollback lämnar status pending');
fsl(!is_file(fullmakt_signed_pdf_path($rollToken)) && fsl_pdf_tmps() === [], 'R1 rollback tar bort tmp-filen och skriver ingen PDF');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === 2, 'R1 rollback kör inte after_signed');

$nested = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Ella Fullmakt',
    'epost' => 'ella.fullmakt@example.test',
    'telefon' => '0701000015',
]);
$nestedToken = (string) ($nested['token'] ?? '');
$pdo->beginTransaction();
$nestedThrew = false;
try {
    fullmakt_mark_signed($nestedToken, $nested, "%PDF-1.4 ella\n");
} catch (LogicException) {
    $nestedThrew = true;
}
fsl($nestedThrew && $pdo->inTransaction(), 'R2 kastar LogicException och lämnar den yttre transaktionen');
$pdo->rollBack();
fsl(!$pdo->inTransaction(), 'R2 testet stänger den yttre transaktionen');
fsl((string) $pdo->query('SELECT status FROM fullmakt_docs WHERE token = ' . $pdo->quote($nestedToken))->fetchColumn() === 'pending', 'R2 signerar inte inne i en öppen transaktion');
fsl(!is_file(fullmakt_signed_pdf_path($nestedToken)) && fsl_pdf_tmps() === [] && (int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === 2, 'R2 ingen PDF och ingen after_signed');

// Omgranskning: after_signed körs även när PDF-publiceringen efter commit faller, och vid retry.
$pubFail = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Fia Fullmakt',
    'epost' => 'fia.fullmakt@example.test',
    'telefon' => '0701000016',
]);
$pubToken = (string) ($pubFail['token'] ?? '');
$hitsBefore = (int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'];
mkdir(fullmakt_signed_pdf_path($pubToken), 0700, true);
$pubSigned = fullmakt_mark_signed($pubToken, $pubFail, "%PDF-1.4 fia\n");
rmdir(fullmakt_signed_pdf_path($pubToken));
fsl(($pubSigned['status'] ?? '') === 'signed', 'P1 publiceringsfel efter commit: signeringen står');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === $hitsBefore + 1, 'P1 after_signed körs trots publiceringsfel');
fsl(fsl_pdf_tmps() === [], 'P1 ingen tmp-fil kvar');
$retryPdf = "%PDF-1.4 fia-retry\n";
fullmakt_mark_signed($pubToken, ['type' => 'overlay', 'stamp_method' => 'fpdi'], $retryPdf);
fsl((string) file_get_contents(fullmakt_signed_pdf_path($pubToken)) === $retryPdf, 'P1 retry skriver PDF:en');
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === $hitsBefore + 1, 'P1 retry notifierar inte igen');

$setPayload = static function (string $tok, callable $fn) use ($pdo): void {
    $raw = (string) $pdo->query('SELECT payload FROM fullmakt_docs WHERE token = ' . $pdo->quote($tok))->fetchColumn();
    $doc = json_decode($raw, true);
    $doc = $fn($doc);
    $st = $pdo->prepare('UPDATE fullmakt_docs SET payload = ? WHERE token = ?');
    $st->execute([json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $tok]);
};
$setPayload($pubToken, static function (array $d): array { $d['signed_notified'] = false; return $d; });
fullmakt_mark_signed($pubToken, ['type' => 'overlay', 'stamp_method' => 'fpdi'], $retryPdf);
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === $hitsBefore + 2, 'P2 retry kör after_signed som inte hann köras');
fullmakt_mark_signed($pubToken, ['type' => 'overlay', 'stamp_method' => 'fpdi'], $retryPdf);
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === $hitsBefore + 2, 'P2 bara en gång');
$setPayload($pubToken, static function (array $d): array { unset($d['signed_notified']); return $d; });
fullmakt_mark_signed($pubToken, ['type' => 'overlay', 'stamp_method' => 'fpdi'], $retryPdf);
fsl((int) $GLOBALS['_JEAB_FULLMAKT_AFTER_SIGNED_HITS'] === $hitsBefore + 2, 'P3 äldre signerad rad utan nyckel notifieras inte om');


$job = static function (int $id): array {
    return ['kind' => 'ping', 'payload' => ['id' => $id], 'at' => 1700000000 + $id];
};
jeab_data_put_source('external_queue', ['jobs' => [$job(1), $job(2)]], true);
$beforeIds = [];
foreach (jeab_cutover_external_jobs() ?? [] as $row) {
    $beforeIds[] = (int) ($row['payload']['id'] ?? 0);
}
sort($beforeIds);
fsl($beforeIds === [1, 2], 'F4 kön har två jobb');

$GLOBALS['_JEAB_PUT_SOURCE_AFTER_BEFORE'] = static function (PDO $pdo) use ($job): void {
    $queue = jeab_migrate_find('external_queue');
    if (!is_array($queue)) {
        return;
    }
    jeab_migrate_upsert($pdo, $queue, jeab_migrate_external_queue_rows(['jobs' => [$job(3)]]));
};
jeab_data_put_source('external_queue', ['jobs' => [$job(1)]], true);
unset($GLOBALS['_JEAB_PUT_SOURCE_AFTER_BEFORE']);
$afterIds = [];
foreach (jeab_cutover_external_jobs() ?? [] as $row) {
    $afterIds[] = (int) ($row['payload']['id'] ?? 0);
}
sort($afterIds);
fsl($afterIds === [1, 3], 'F4 borttagen rad försvinner och parallell rad står kvar');

$cutoverSrc = (string) file_get_contents(dirname(__DIR__) . '/src/data_cutover.php');
fsl(str_contains($cutoverSrc, "jeab_put:") && str_contains($cutoverSrc, 'GET_LOCK(') && str_contains($cutoverSrc, ', 5)'), 'F4 tar GET_LOCK i fem sekunder');
fsl(str_contains($cutoverSrc, 'RELEASE_LOCK('), 'F4 släpper låset');

$line = json_encode([
    'time' => '2026-10-23 12:00:00',
    'surface' => 'test',
    'message' => 'syntetiskt fel',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
fsl(is_string($line) && jeab_cutover_log_pdo_missing(), 'F12 log-PDO saknas');
fsl(jeab_cutover_log_append('app_errors', $line) === true, 'F12 append skriver när log-PDO saknas');
$logCount = static function (PDO $pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM log_lines WHERE kind = 'app_error'");

    return $stmt instanceof PDOStatement ? (int) $stmt->fetchColumn() : -1;
};
fsl($logCount($pdo) === 1, 'F12 en rad i log_lines');
$lineTwo = json_encode([
    'time' => '2026-10-23 12:01:00',
    'surface' => 'test',
    'message' => 'andra raden',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $mem;
fsl(is_string($lineTwo) && !jeab_cutover_log_pdo_missing(), 'F12 injicerad log-PDO räknas');
fsl(jeab_cutover_log_append('app_errors', $lineTwo) === false, 'F12 append skriver inte när log-PDO finns');
fsl($logCount($pdo) === 1, 'F12 ingen extra rad när log-PDO finns');
unset($GLOBALS['_JEAB_LOG_PDO']);
$GLOBALS['_JEAB_STEG4_CUTOVER'] = false;
fsl(jeab_cutover_log_append('app_errors', (string) $lineTwo) === false && $logCount($pdo) === 1, 'F12 flaggan av skriver inte');
fsl(!fullmakt_sql_active(), 'flaggan av stänger SQL-vägen för fullmakt');
fsl((fullmakt_load_doc($jsonOnly)['namn'] ?? '') === 'Json Bara', 'flaggan av läser pending-JSON');
fsl(fullmakt_json_only_count() === 0, 'flaggan av ger räknaren 0');


echo "ALL OK\n";
