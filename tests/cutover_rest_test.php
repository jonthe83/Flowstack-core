<?php

declare(strict_types=1);

/**
 * Kontakter, inkorg, kön och inloggningsspärren i data.cutover_all.
 * Torrkörning ändrar inget. Avvikelse raderar inte. Rollback och inloggning.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || !class_exists('ZipArchive')) {
    echo "SKIP sqlite eller zip saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-cutover-rest-' . bin2hex(random_bytes(4));
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
$until = time() + 3600;
$contacts = ['contacts' => [
    'kt_rest_ada' => ['id' => 'kt_rest_ada', 'name' => 'Ada', 'email' => 'ada.rest@example.test', 'updated_at' => '2026-01-01 00:00:00'],
]];
$inboxContacts = ['contacts' => [
    'c-rest-bo' => ['id' => 'c-rest-bo', 'name' => 'Bo', 'emails' => ['bo.rest@example.test'], 'updated_at' => '2026-01-01 00:00:00'],
]];
$messages = ['messages' => [[
    'id' => 'm-rest-1',
    'thread_id' => 't-rest-1',
    'received_at' => '2026-01-02 08:00:00',
    'from' => 'kund.rest@example.test',
    'subject' => 'Hej',
]]];
$threads = ['threads' => [
    't-rest-1' => ['id' => 't-rest-1', 'status' => 'open', 'updated_at' => '2026-01-02 08:00:00'],
]];
$jobs = ['jobs' => [
    ['kind' => 'order-lookup', 'payload' => ['id' => 1], 'at' => 100, 'attempts' => 0],
    ['kind' => 'order-lookup', 'payload' => ['id' => 2], 'at' => 110, 'attempts' => 1],
    ['kind' => 'order-lookup', 'payload' => ['id' => 3], 'at' => 120, 'attempts' => 0],
]];
$throttle = [
    '203.0.113.9' => ['fails' => 2, 'until' => $until],
    '203.0.113.10' => ['fails' => 1, 'until' => $until],
];
file_put_contents($tmp . '/kontakter.json', json_encode($contacts, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/contacts.json', json_encode($inboxContacts, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/messages.json', json_encode($messages, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode($threads, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/external-queue.json', json_encode($jobs, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/login-throttle.json', json_encode($throttle, JSON_UNESCAPED_UNICODE));
$contactHash = hash('sha256', (string) file_get_contents($tmp . '/kontakter.json'));
$queueHash = hash('sha256', (string) file_get_contents($tmp . '/external-queue.json'));
$throttleHash = hash('sha256', (string) file_get_contents($tmp . '/login-throttle.json'));
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-rest']));
require $root . '/src/bootstrap.php';

function cr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

cr(jeab_cutover_staged_order() === ['kontakter', 'inkorg', 'external_queue', 'login_throttle'], 'ordningen är kontakter, inkorg, kön, spärr');
cr(!in_array('contacts', jeab_cutover_all_types(), true) && !in_array('external_queue', jeab_cutover_all_types(), true), 'svepet tar inte de fyra');

$dry = jeab_cutover_all(['type' => 'kontakter']);
cr(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']) && count($dry['types'] ?? []) === 2, 'kontakter torrkörning');
cr(hash('sha256', (string) file_get_contents($tmp . '/kontakter.json')) === $contactHash, 'torrkörning lämnar kontakter.json');
cr(!is_dir($tmp . '/privat/backup'), 'torrkörning tar ingen backup');
cr(jeab_kontakter_storage_mode() === 'json', 'torrkörning sätter inte flaggan');

$ranJobs = 0;
$GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] = static function () use (&$ranJobs): void {
    $ranJobs++;
};
$pausedDuring = false;
$GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL'] = static function (string $id) use (&$pausedDuring): void {
    if ($id === 'external_queue') {
        $pausedDuring = function_exists('jeab_cutover_sends_paused') && jeab_cutover_sends_paused();
    }
};
$queue = jeab_cutover_all(['type' => 'external_queue', 'write' => 1]);
unset($GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL']);
cr(!empty($queue['ok']) && !empty($queue['written']), 'kön skrivs');
cr($pausedDuring && !jeab_cutover_sends_paused() && !is_file($tmp . '/privat/cutover-send.pause'), 'utskick pausas under kön och släpps');
cr($ranJobs === 0, 'migreringen kör inga jobb');
cr(!is_file($tmp . '/external-queue.json'), 'köns json raderas');
cr(jeab_data_type_forced('sql', 'external_queue'), 'kön är sql');
jeab_external_enqueue('order-lookup', ['id' => 9]);
cr(!is_file($tmp . '/external-queue.json'), 'sql-läge skriver ingen köfil');
$sms = jeab_sms_tick_run(jeab_store());
cr(empty($sms['paused']) && (int) ($sms['sent'] ?? 0) === 0, 'cron skickar inget efter släppet när kön är tom');

jeab_cutover_sends_pause(true);
$held = jeab_sms_tick_run(jeab_store());
$ranJobs = 0;
jeab_external_run_job(['kind' => 'order-lookup', 'payload' => ['id' => 1]]);
cr(!empty($held['paused']) && (int) ($held['sent'] ?? 0) === 0 && $ranJobs === 0, 'pausflaggan stoppar cron och jobb');
jeab_cutover_sends_pause(false);

$restored = jeab_cutover_rollback_type(['type' => 'external_queue', 'write' => 1]);
cr(!empty($restored['ok']) && is_file($tmp . '/external-queue.json'), 'rollback lägger tillbaka kön');
cr(hash('sha256', (string) file_get_contents($tmp . '/external-queue.json')) === $queueHash, 'kön är identisk efter rollback');
cr(!jeab_data_type_forced('sql', 'external_queue'), 'rollback tar bort sql-flaggan');

file_put_contents($tmp . '/external-queue.json', json_encode($jobs, JSON_UNESCAPED_UNICODE));
$GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL'] = static function (string $id): void {
    if ($id !== 'external_queue') {
        return;
    }
    $pdo = jeab_data_pdo();
    if ($pdo instanceof PDO) {
        $pdo->exec("UPDATE external_queue_jobs SET attempts = attempts + 50");
    }
};
$aborted = jeab_cutover_all(['type' => 'external_queue', 'write' => 1]);
unset($GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL']);
cr(empty($aborted['ok']) && is_file($tmp . '/external-queue.json'), 'avvikelse raderar inte kön');
cr(!jeab_data_type_forced('sql', 'external_queue') && !jeab_cutover_sends_paused(), 'avvikelse slår inte om och släpper pausen');

$people = jeab_cutover_all(['type' => 'kontakter', 'write' => 1]);
cr(!empty($people['ok']) && jeab_kontakter_storage_mode() === 'sql', 'kontakter blir sql');
cr(!is_file($tmp . '/kontakter.json') && !is_file($tmp . '/inkorg/contacts.json'), 'kontaktfilerna raderas');
cr(jeab_kontakter_sql_is_sole_source(), 'kontakter läser inte json');
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => [
    'kt_rest_ada' => ['id' => 'kt_rest_ada', 'name' => 'Ada'],
    'kt_rest_extra' => ['id' => 'kt_rest_extra', 'name' => 'Extra'],
]], JSON_UNESCAPED_UNICODE));
cr(jeab_kontakt_get('kt_rest_extra') === null && is_array(jeab_kontakt_get('kt_rest_ada')), 'en json-rad efter omslaget läses inte');
@unlink($tmp . '/kontakter.json');

$box = jeab_cutover_all(['type' => 'inkorg', 'write' => 1]);
cr(!empty($box['ok']) && jeab_inkorg_storage_mode() === 'sql', 'inkorg blir sql');
cr(!is_file($tmp . '/inkorg/messages.json') && !is_file($tmp . '/inkorg/threads.json'), 'inkorgsfilerna raderas');
file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => [[
    'id' => 'm-rest-extra',
    'thread_id' => 't-rest-extra',
    'subject' => 'Bara fil',
]]], JSON_UNESCAPED_UNICODE));
cr(jeab_inkorg_get('m-rest-1') !== null && jeab_inkorg_get('m-rest-extra') === null, 'inkorgen läser sql och inte den nya filen');
@unlink($tmp . '/inkorg/messages.json');

$gate = jeab_cutover_all(['type' => 'login_throttle', 'write' => 1]);
cr(!empty($gate['ok']) && !is_file($tmp . '/login-throttle.json'), 'spärren blir sql och filen raderas');
$state = jeab_auth_throttle_state('203.0.113.9');
$other = jeab_auth_throttle_state('203.0.113.10');
cr(empty($GLOBALS['_JEAB_THROTTLE_UNAVAILABLE']) && (int) ($state['fails'] ?? 0) === 2 && (int) ($other['fails'] ?? 0) === 1, 'inloggningen behåller räknarna');
file_put_contents($tmp . '/login-throttle.json', json_encode(['203.0.113.9' => ['fails' => 99, 'until' => $until]], JSON_UNESCAPED_UNICODE));
$again = jeab_auth_throttle_state('203.0.113.9');
cr((int) ($again['fails'] ?? 0) === 2, 'spärren läser inte json efter omslaget');
@unlink($tmp . '/login-throttle.json');
$back = jeab_cutover_rollback_type(['type' => 'login_throttle', 'write' => 1]);
cr(!empty($back['ok']) && is_file($tmp . '/login-throttle.json'), 'rollback lägger tillbaka spärren');
cr(hash('sha256', (string) file_get_contents($tmp . '/login-throttle.json')) === $throttleHash, 'spärrens innehåll är tillbaka');
$after = jeab_auth_throttle_state('203.0.113.9');
cr(empty($GLOBALS['_JEAB_THROTTLE_UNAVAILABLE']) && (int) ($after['fails'] ?? 0) === 2, 'inloggningen fungerar efter rollback');

$peopleBack = jeab_cutover_rollback_type(['type' => 'kontakter', 'write' => 1]);
cr(!empty($peopleBack['ok']) && is_file($tmp . '/kontakter.json') && jeab_kontakter_storage_mode() === 'json', 'kontakter rullas tillbaka');
cr(hash('sha256', (string) file_get_contents($tmp . '/kontakter.json')) === $contactHash, 'kontakter.json är densamma');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['action' => 'diag.opcache'];
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_diag_opcache_dispatch();
cr(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'diag.opcache utan token skriver inte');
$report = jeab_opcache_report();
$encoded = json_encode($report);
cr(!empty($report['ok']) && is_string($encoded) && !str_contains($encoded, $tmp) && !str_contains((string) ($report['ini']['opcache.restrict_api'] ?? ''), '/'), 'opcache-rapporten har ingen sökväg');
cr(empty($GLOBALS['_JEAB_SCHEMA_DDL']), 'rapporten sätter ingen DDL');
$userIni = (string) file_get_contents($root . '/.user.ini');
cr(str_contains($userIni, 'opcache.enable=1') && str_contains($userIni, 'opcache.validate_timestamps=1'), '.user.ini slår på opcache');

$peopleDoc = json_decode((string) file_get_contents($tmp . '/kontakter.json'), true);
$peopleDoc = is_array($peopleDoc) ? $peopleDoc : ['contacts' => []];
$peopleDoc['contacts']['kt_rest_old'] = ['id' => 'kt_rest_old', 'name' => 'Old', 'updated_at' => '2020-01-01 00:00:00'];
file_put_contents($tmp . '/kontakter.json', json_encode($peopleDoc, JSON_UNESCAPED_UNICODE));
$peopleHash = hash('sha256', (string) file_get_contents($tmp . '/kontakter.json'));
$inboxHash = hash('sha256', (string) file_get_contents($tmp . '/inkorg/contacts.json'));
$pdo = jeab_data_pdo();
cr($pdo instanceof PDO, 'sql finns för kontaktprovet');
if (function_exists('jeab_ensure_contact_id_columns')) {
    jeab_ensure_contact_id_columns($pdo, 'sqlite');
}
$bookingModule = jeab_migrate_find('bookings');
if (is_array($bookingModule)) {
    jeab_migrate_ensure($pdo, $bookingModule);
}
$now = date('Y-m-d H:i:s');
$pdo->prepare('INSERT OR IGNORE INTO customers (id, name, created_at) VALUES (91001, ?, ?)')->execute(['Cutover Ada', $now]);
$pdo->prepare('INSERT OR REPLACE INTO bookings (id, customer_id, customer_name, assignee, starts_at, ends_at, status, contact_id, created_at, updated_at) VALUES (91001, 91001, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute(['Ada', 'test', $now, $now, 'booked', 'kt_rest_ada', $now, $now]);
$GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL'] = static function (string $id) use ($pdo): void {
    if ($id !== 'contacts') {
        return;
    }
    $pdo->prepare('UPDATE contacts SET row_json = ? WHERE id = ?')->execute(['{"id":"kt_rest_ada","street":"Annat"}', 'kt_rest_ada']);
    $pdo->prepare('UPDATE contacts SET row_json = ? WHERE id = ?')->execute(['{"id":"kt_rest_old","street":"Annat"}', 'kt_rest_old']);
};
$keptPeople = jeab_cutover_all(['type' => 'kontakter', 'write' => 1, 'keep_json' => 1]);
unset($GLOBALS['_JEAB_CUTOVER_AFTER_BACKFILL']);
$keptBody = json_encode($keptPeople);
$contactRow = null;
foreach ($keptPeople['types'] ?? [] as $typeRow) {
    if (($typeRow['type'] ?? '') === 'contacts') {
        $contactRow = $typeRow;
    }
}
cr(!empty($keptPeople['ok']) && !empty($keptPeople['json_kept']) && jeab_kontakter_storage_mode() === 'sql', 'kontakter blir sql och json behålls');
cr(is_file($tmp . '/kontakter.json') && is_file($tmp . '/inkorg/contacts.json'), 'båda kontaktfilerna ligger kvar');
cr(hash('sha256', (string) file_get_contents($tmp . '/kontakter.json')) === $peopleHash, 'kontakter.json är orörd');
cr(hash('sha256', (string) file_get_contents($tmp . '/inkorg/contacts.json')) === $inboxHash, 'inkorgens contacts.json är orörd');
cr(is_array($contactRow) && (int) ($contactRow['differ'] ?? 0) === 1 && (int) ($contactRow['inactive'] ?? 0) >= 1, 'bara aktiv kontakt räknas');
$diffIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), is_array($contactRow['diffs'] ?? null) ? $contactRow['diffs'] : []);
cr($diffIds === ['kt_rest_ada'], 'den gamla kontakten stoppar inte');
cr(is_string($keptBody) && !str_contains($keptBody, 'Annat') && !str_contains($keptBody, 'Old') && !str_contains($keptBody, 'Ada'), 'stickprovet visar inte persondata');
cr(jeab_kontakter_sql_is_sole_source(), 'sidan läser kontakter från sql');
$adaSql = (string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_rest_ada'")->fetchColumn();
$peopleFlag = jeab_cutover_rollback_type(['type' => 'kontakter', 'write' => 1]);
cr(!empty($peopleFlag['ok']) && jeab_kontakter_storage_mode() === 'json', 'json kvar: kontakter vrids tillbaka till json');
cr(hash('sha256', (string) file_get_contents($tmp . '/kontakter.json')) === $peopleHash, 'rollback skriver inte om kontakter.json');
cr((string) $pdo->query("SELECT row_json FROM contacts WHERE id = 'kt_rest_ada'")->fetchColumn() === $adaSql, 'rollback skriver inte över sql');

echo "cutover_rest_test ok\n";
