<?php

declare(strict_types=1);

/**
 * Signeringsstatus i SQL: viewed_at, pending-uppdatering och listan
 * under Övrigt. JSON skrivs inte när SQL är aktiv. data_backend=json
 * är reserven. Påhittade rader. Inga personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-viewed-sql-' . bin2hex(random_bytes(4));
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
require dirname(__DIR__) . '/src/fullmakt_signed.php';
require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/store.php';
require dirname(__DIR__) . '/fullmakt/lib/viewed.php';
fullmakt_boot();

function fvs(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_data_pdo();
fvs($pdo instanceof PDO, 'sqlite finns');
fvs(fullmakt_sql_active(), 'SQL-vägen är aktiv');
$module = jeab_migrate_find('fullmakt');
fvs(is_array($module), 'fullmakt-modulen finns');
jeab_migrate_ensure($pdo, $module);

$created = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Ada Viewed',
    'epost' => 'ada.viewed@example.test',
    'telefon' => '0701000021',
    'adress' => 'Testvägen 8',
    'postnummer' => '177 31',
    'ort' => 'Järfälla',
]);
$token = (string) ($created['token'] ?? '');
fvs($token !== '', 'dokumentet fick token');
fvs(!is_file(fullmakt_pending_path($token)), 'SQL-skapande skriver inte pending-JSON');
fvs(fullmakt_pending_exists($token), 'pending finns i SQL');

fvs(fullmakt_mark_viewed($token), 'första visningen sparas');
$afterView = fullmakt_sql_get($token);
fvs(is_array($afterView) && trim((string) ($afterView['viewed_at'] ?? '')) !== '', 'viewed_at ligger i SQL');
$viewedAt = (string) ($afterView['viewed_at'] ?? '');
fvs(!is_file(fullmakt_pending_path($token)), 'visning skriver inte pending-JSON');
fvs(fullmakt_mark_viewed($token), 'andra visningen är idempotent');
fvs((string) (fullmakt_sql_get($token)['viewed_at'] ?? '') === $viewedAt, 'viewed_at skrivs inte om');
fvs(fullmakt_mark_viewed('finns-inte') === false, 'saknad token är false');

$attached = jeab_ellevio_fullmakt_attach_order_id($afterView, [
    'reminder_id' => 8802,
    'id' => 8802,
]);
fvs((string) ($attached['order_id'] ?? '') === '8802', 'order_id fästs utan pending-JSON');
fvs((string) (fullmakt_sql_get($token)['order_id'] ?? '') === '8802', 'order_id sparas i SQL');
fvs(!is_file(fullmakt_pending_path($token)), 'order_id skriver inte pending-JSON');

$signedPdf = "%PDF-1.4 viewed-sql\n";
$signed = fullmakt_mark_signed($token, $created, $signedPdf);
fvs(($signed['status'] ?? '') === 'signed', 'dokumentet signeras i SQL');
fvs(fullmakt_mark_viewed($token) === false, 'signerad visning är false');
fvs(!is_file(fullmakt_signed_json_path($token)), 'signering skriver inte signed-JSON');
fvs(is_file(fullmakt_signed_pdf_path($token)), 'PDF ligger kvar som fil');

$list = jeab_fullmakt_signed_rows();
$names = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $list);
fvs(in_array('Ada Viewed', $names, true), 'SQL-signerad fullmakt listas utan signed-JSON');
$id = '';
foreach ($list as $row) {
    if (($row['name'] ?? '') === 'Ada Viewed') {
        $id = (string) ($row['id'] ?? '');
    }
}
fvs(preg_match('/^[a-f0-9]{24}$/', $id) === 1, 'listan har visnings-id');
$pdf = jeab_fullmakt_signed_find_pdf($id);
fvs(is_string($pdf) && is_file($pdf), 'PDF hittas via visnings-id från SQL');

$GLOBALS['_JEAB_STEG4_CUTOVER'] = false;
$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
putenv('DATA_BACKEND=json');
fvs(!fullmakt_sql_active(), 'flaggan av stänger SQL-vägen');
$jsonDoc = fullmakt_create_doc([
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'namn' => 'Bo Json',
    'epost' => 'bo.json@example.test',
]);
$jsonToken = (string) ($jsonDoc['token'] ?? '');
fvs(is_file(fullmakt_pending_path($jsonToken)), 'json-läget skriver pending-JSON');
fvs(fullmakt_mark_viewed($jsonToken), 'json-läget markerar visning i filen');
$fromFile = fullmakt_read_json(fullmakt_pending_path($jsonToken));
fvs(is_array($fromFile) && trim((string) ($fromFile['viewed_at'] ?? '')) !== '', 'viewed_at ligger i pending-JSON');

echo "ALL OK\n";
