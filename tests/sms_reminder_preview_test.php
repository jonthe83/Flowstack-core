<?php

declare(strict_types=1);

/**
 * sms.reminder_preview är torr läsning av samma bokningsurval som påminnelse-SMS.
 * Den skickar inget och skriver varken sms_sent, någon tabell eller JSON.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sms-reminder-preview-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rp_counts(PDO $pdo): array
{
    $out = [];
    $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $name) {
        $name = (string) $name;
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            continue;
        }
        $out[$name] = (int) $pdo->query('SELECT COUNT(*) FROM "' . $name . '"')->fetchColumn();
    }
    return $out;
}

function rp_schema(PDO $pdo): string
{
    $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY sql")->fetchAll(PDO::FETCH_COLUMN);
    return implode("\n", array_map('strval', $sql));
}

function rp_json_hashes(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (preg_match('/\.jsonl?$/', $path) !== 1) {
            continue;
        }
        $out[$path] = hash_file('sha256', $path);
    }
    ksort($out);
    return $out;
}

$root = dirname(__DIR__);
$smsSrc = (string) file_get_contents($root . '/src/sms.php');
$hookSrc = (string) file_get_contents($root . '/hook.php');
$selectAt = strpos($smsSrc, 'function jeab_sms_reminder_select(');
$previewSrc = $selectAt === false ? '' : substr($smsSrc, $selectAt);
$hookAt = strpos($hookSrc, "=== 'sms.reminder_preview'");
$hookNext = strpos($hookSrc, "=== 'notes.import'");
$hookSlice = ($hookAt !== false && $hookNext !== false && $hookNext > $hookAt)
    ? substr($hookSrc, $hookAt, $hookNext - $hookAt)
    : '';

rp_assert($selectAt !== false, 'urvalet ligger i jeab_sms_reminder_select');
rp_assert(str_contains($smsSrc, 'jeab_sms_reminder_select($store, $nowSql, $smsTo)'), 'tickern använder samma urval');
rp_assert(!str_contains($previewSrc, 'jeab_sms_send('), 'förhandsvisningen anropar inte jeab_sms_send');
rp_assert(!str_contains($previewSrc, 'jeab_sms_record_sent') && !str_contains($previewSrc, 'jeab_sms_save_sent'), 'förhandsvisningen skriver inte sms_sent');
rp_assert(!str_contains($previewSrc, 'file_put_contents') && !str_contains($previewSrc, 'jeab_sms_sent_path'), 'förhandsvisningen skriver ingen JSON');
rp_assert(!str_contains($previewSrc, 'bookingsUpdatedBetween'), 'förhandsvisningen tar inte mejlkö');
rp_assert($hookAt !== false && strpos($hookSrc, 'jeab_parse_incoming') > $hookAt, 'hooken tar åtgärden innan en bokning skapas');
rp_assert(str_contains($hookSlice, "\$input['dry'] = 1"), 'hooken tvingar dry på');
rp_assert(!str_contains($hookSlice, 'jeab_sms_send') && !str_contains($hookSlice, 'jeab_sms_tick'), 'hookgrenen skickar inte');

rp_assert(jeab_driver() === 'sqlite', 'testet använder sqlite');
$store = jeab_store();
rp_assert($store instanceof JeabPdoStore, 'PDO-hjälparen är öppen');
$pdo = $store->pdo();

$secretName = [
    'batteri' => 'Batteri Testman',
    'laddbox' => 'Laddbox Testman',
    'sol' => 'Sol Jarfalla',
    'malmo' => 'Sol Malmo',
    'service' => 'Service Testman',
    'empty' => 'Utan Produkt',
    'outlook' => 'Veckosammantrade',
    'late' => 'Sen Bokning',
    'early' => 'Tidig Bokning',
    'cancelled' => 'Avbokad Testman',
];
$phone = '0709998877';
$pnr = '199001011234';
$pnrDash = '19900101-1234';

$insert = static function (string $key, array $over) use ($store, $secretName, $phone, $pnr, $pnrDash): array {
    $name = $secretName[$key];
    $customer = jeab_find_or_create_customer($store, $name);
    return $store->insertBooking(array_merge([
        'customer_id' => (int) $customer['id'],
        'customer_name' => $name,
        'email' => $pnr . '@example.test',
        'phone' => $phone,
        'address' => 'Lindstigen',
        'notes' => 'personnummer ' . $pnrDash,
        'assignees' => ['Sam L'],
        'company' => 'jarfalla',
        'job_type' => null,
        'starts_at' => '2026-10-22 09:00:00',
        'ends_at' => '2026-10-22 12:00:00',
        'status' => 'booked',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-01 08:00:00',
    ], $over));
};

$rows = [
    'batteri' => $insert('batteri', [
        'job_type' => 'batteri',
        'address' => 'Björkvägen 8, 177 31 Järfälla',
    ]),
    'laddbox' => $insert('laddbox', [
        'job_type' => 'laddbox',
        'address' => 'Ekvägen',
        'starts_at' => '2026-10-24 12:00:00',
        'ends_at' => '2026-10-24 16:00:00',
    ]),
    'sol' => $insert('sol', [
        'job_type' => 'sol',
        'company' => 'jarfalla',
        'address' => 'Solstigen 2',
    ]),
    'malmo' => $insert('malmo', [
        'job_type' => 'sol',
        'company' => 'malmo',
        'address' => 'Malmovagen',
    ]),
    'service' => $insert('service', [
        'job_type' => 'service',
        'address' => 'Servicegatan 4',
    ]),
    'empty' => $insert('empty', [
        'job_type' => null,
        'address' => 'Produktlosen',
    ]),
    'outlook' => $insert('outlook', [
        'job_type' => null,
        'address' => 'Samtalsrummet',
        'notes' => 'Outlook-möte utan tagg ' . $pnrDash,
    ]),
    'early' => $insert('early', [
        'job_type' => 'batteri',
        'starts_at' => '2026-10-03 09:00:00',
        'ends_at' => '2026-10-03 12:00:00',
    ]),
    'late' => $insert('late', [
        'job_type' => 'batteri',
        'starts_at' => '2026-10-25 09:00:00',
        'ends_at' => '2026-10-25 12:00:00',
    ]),
    'cancelled' => $insert('cancelled', [
        'job_type' => 'batteri',
        'status' => 'cancelled',
    ]),
];

$countsBefore = rp_counts($pdo);
$schemaBefore = rp_schema($pdo);
$versionBefore = (int) $pdo->query('PRAGMA data_version')->fetchColumn();
$jsonBefore = rp_json_hashes($tmp);
rp_assert(!isset($countsBefore['sms_sent']), 'sms_sent finns inte före förhandsvisningen');

$sent = 0;
$GLOBALS['JEAB_SMS_SEND_WATCH'] = static function () use (&$sent): void {
    $sent++;
};
$GLOBALS['JEAB_SMS_SEND'] = static function () use (&$sent): array {
    $sent++;
    return ['ok' => true];
};

$span = [
    'action' => 'sms.reminder_preview',
    'from' => '2026-10-04',
    'to' => '2026-10-24',
    'dry' => 0,
];
$result = jeab_sms_reminder_preview_dispatch($store, $span);
$again = jeab_sms_reminder_preview_dispatch($store, ['from' => '2026-10-04', 'to' => '2026-10-24', 'dry' => '0', 'send' => 1]);

rp_assert(($result['ok'] ?? false) === true, 'spannet på 21 dagar går');
rp_assert((int) ($result['dry'] ?? 0) === 1 && (int) ($again['dry'] ?? 0) === 1, 'dry tvingas på även när indata säger 0');
rp_assert(($result['read_only'] ?? false) === true, 'svaret är read_only');
rp_assert((int) ($result['count'] ?? -1) === 7, 'sju bokningar i spannet, fick ' . (int) ($result['count'] ?? -1));
rp_assert($sent === 0, 'jeab_sms_send anropades inte');

$byId = [];
foreach ($result['bookings'] ?? [] as $item) {
    rp_assert(is_array($item), 'varje rad är en array');
    rp_assert(array_keys($item) === ['id', 'date', 'street', 'job_types', 'company', 'sms', 'reason'], 'raden har bara de tillåtna fälten');
    $byId[(int) $item['id']] = $item;
}

$expect = [
    'batteri' => [true, 'ok batteri', 'jarfalla', ['batteri'], '2026-10-22', 'Björkvägen 8'],
    'laddbox' => [true, 'ok laddbox', 'jarfalla', ['laddbox'], '2026-10-24', 'Ekvägen'],
    'sol' => [true, 'ok sol', 'jarfalla', ['sol'], '2026-10-22', 'Solstigen 2'],
    'malmo' => [false, 'malmo sol', 'malmo', ['sol'], '2026-10-22', 'Malmovagen'],
    'service' => [false, 'service', 'jarfalla', ['service'], '2026-10-22', 'Servicegatan 4'],
    'empty' => [false, 'ingen produkt', 'jarfalla', [], '2026-10-22', 'Produktlosen'],
    'outlook' => [false, 'ingen produkt', 'jarfalla', [], '2026-10-22', 'Samtalsrummet'],
];
foreach ($expect as $key => $wanted) {
    $id = (int) $rows[$key]['id'];
    $item = $byId[$id] ?? null;
    rp_assert(is_array($item), $key . ' finns med');
    if (!is_array($item)) {
        continue;
    }
    rp_assert($item['sms'] === $wanted[0], $key . ' sms ' . ($wanted[0] ? 'ja' : 'nej'));
    rp_assert($item['reason'] === $wanted[1], $key . ' anledning ' . $wanted[1] . ', fick ' . (string) $item['reason']);
    rp_assert($item['company'] === $wanted[2], $key . ' bolag ' . $wanted[2]);
    rp_assert($item['job_types'] === $wanted[3], $key . ' produkter');
    rp_assert($item['date'] === $wanted[4], $key . ' datum');
    rp_assert($item['street'] === $wanted[5], $key . ' gata «' . (string) $item['street'] . '»');
}
rp_assert(!isset($byId[(int) $rows['early']['id']]) && !isset($byId[(int) $rows['late']['id']]), 'utanför spannet följer inte med');
rp_assert(!isset($byId[(int) $rows['cancelled']['id']]), 'avbokad följer inte med');

$encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
rp_assert(is_string($encoded), 'svaret kan kodas');
foreach (array_merge(array_values($secretName), [$phone, '+46709998877', $pnr, $pnrDash, 'personnummer', '@example.test']) as $secret) {
    rp_assert(!str_contains((string) $encoded, $secret), 'svaret saknar den hemliga texten');
}

$tooLong = jeab_sms_reminder_preview_dispatch($store, ['from' => '2026-10-03', 'to' => '2026-10-24', 'dry' => 0]);
rp_assert(($tooLong['ok'] ?? true) === false && (int) ($tooLong['dry'] ?? 0) === 1, '22 dagar nekas och förblir dry');
rp_assert(!isset($tooLong['bookings']), 'för långt spann lämnar inga rader');
$bad = jeab_sms_reminder_preview_dispatch($store, ['from' => '2026-13-40', 'to' => '2026-10-24', 'dry' => false]);
rp_assert(($bad['ok'] ?? true) === false && (int) ($bad['dry'] ?? 0) === 1, 'ogiltigt datum nekas');
$backwards = jeab_sms_reminder_preview_dispatch($store, ['from' => '2026-10-24', 'to' => '2026-10-04']);
rp_assert(($backwards['ok'] ?? true) === false, 'from efter to nekas');

rp_assert($sent === 0, 'nekade anrop skickade inte heller');
rp_assert(rp_counts($pdo) === $countsBefore, 'inga tabeller ändrade radantal');
rp_assert(rp_schema($pdo) === $schemaBefore, 'schemat är orört, sms_sent skapades inte');
rp_assert((int) $pdo->query('PRAGMA data_version')->fetchColumn() === $versionBefore, 'databasen skrev inget');
rp_assert(rp_json_hashes($tmp) === $jsonBefore, 'inga JSON-filer skrevs');
rp_assert(!is_file($tmp . '/sms-sent.json') && !isset(rp_counts($pdo)['sms_sent']), 'sms_sent finns inte efteråt');

unset($GLOBALS['JEAB_SMS_SEND'], $GLOBALS['JEAB_SMS_SEND_WATCH']);
echo "sms reminder preview: ok\n";
