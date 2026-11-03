<?php

declare(strict_types=1);

/**
 * data.migrate_all. En flagga, underhåll, moduler, kopplingar.
 */

$tmp = sys_get_temp_dir() . '/jeab-data-migrate-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
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
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":[]}');
file_put_contents($tmp . '/inkorg/contacts.json', '{"contacts":[]}');
require dirname(__DIR__) . '/src/bootstrap.php';

function dm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$readme = (string) file_get_contents(dirname(__DIR__) . '/src/migrate/README.md');
dm(str_contains($readme, 'jeab_migrate_<id>_rows') && str_contains($readme, 'jeab_migrate_<id>_checksum') && str_contains($readme, 'DATA_BACKEND'), 'gränssnittet är module, rows och checksum');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
dm(str_contains($hookSrc, 'data.migrate_all') && str_contains($hookSrc, 'data.job_status'), 'hook tar data.migrate_all och data.job_status');
dm(function_exists('jeab_migrate_module') && function_exists('jeab_migrate_checksum'), 'canon har samma byggare som spår B');
$byId = [];
foreach (jeab_migrate_registry() as $entry) {
    $byId[(string) ($entry['id'] ?? '')] = $entry;
}
dm(isset($byId['contacts'], $byId['inkorg_contacts'], $byId['purchases'], $byId['bookings'], $byId['customers'], $byId['reminders'], $byId['inbox'], $byId['inbox_threads'], $byId['notes']), 'registret har A, B och C');
dm(!isset($byId['orders']), 'orders är purchases');
$missingRows = [];
foreach ($byId as $id => $entry) {
    if (($entry['rows'] ?? '') === '' || ($entry['checksum'] ?? '') === '') {
        $missingRows[] = $id;
    }
}
dm($missingRows === [], 'varje registrerad typ har rows och checksum');
dm(($byId['purchases']['rows'] ?? '') === 'jeab_migrate_purchases_rows', 'ordrar kommer från spår B');
dm(($byId['bookings']['rows'] ?? '') === 'jeab_migrate_bookings_rows', 'bokningar har en modul');

dm(jeab_data_backend() === 'json' && !jeab_data_backend_explicit(), 'flaggan är inte satt');
dm(jeab_kontakter_storage_mode() === 'json', 'kontakter är json');
dm(jeab_data_backend_set('mysql') && jeab_kontakter_storage_mode() === 'sql' && jeab_inkorg_storage_mode() === 'sql', 'mysql-flaggan slår på läsningen');
dm(jeab_data_backend_set('json') && jeab_kontakter_storage_mode() === 'json', 'json-flaggan återställer');
dm(jeab_data_backend_clear() && !jeab_data_backend_explicit(), 'flaggan kan tas bort');

$row = jeab_kontakt_blank([
    'name' => 'Ada Adress',
    'email' => 'ada.adress@example.test',
    'street' => 'Exempelgatan 12',
    'postal_code' => '17740',
    'city' => 'Järfälla',
]);
$row['id'] = 'kt_dm_ada';
file_put_contents($tmp . '/kontakter.json', json_encode(['contacts' => ['kt_dm_ada' => $row]], JSON_UNESCAPED_UNICODE));
jeab_kontakter_mutate(static function (array &$contacts) use ($row): void {
    $contacts['kt_dm_ada'] = $row;
});
$read = jeab_data_read('contacts');
dm(isset($read['kt_dm_ada']), 'datalagret läser kontakter');

dm(jeab_data_maintenance_set(true) && jeab_data_maintenance(), 'underhåll är på');
$blocked = false;
try {
    jeab_purchase_put(['reminder_id' => 1, 'kind' => 'laddbox', 'customer_name' => 'Ada']);
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'Underhåll');
}
dm($blocked, 'underhåll spärrar en orderskrivning');
require_once dirname(__DIR__) . '/src/tick.php';
$tick = jeab_tick_run();
dm(!empty($tick['paused']) && !empty($tick['maintenance']) && ($tick['jobs'] ?? null) === [], 'tick pausas');
$sms = jeab_sms_tick(jeab_store());
dm(!empty($sms['paused']) && (int) ($sms['sent'] ?? 1) === 0, 'sms-cron pausas');
dm(jeab_data_maintenance_set(false) && !jeab_data_maintenance(), 'underhåll släpps');
dm(jeab_data_maintenance_set(true) && jeab_data_maintenance(), 'underhåll är på igen');
$ttlPdo = jeab_data_pdo();
dm($ttlPdo instanceof PDO && jeab_settings_put($ttlPdo, 'sqlite', JEAB_DATA_MAINTENANCE_UNTIL_KEY, (string) (time() - 60)), 'underhåll kan åldras');
unset($GLOBALS['_JEAB_DATA_MAINTENANCE']);
$GLOBALS['_JEAB_DATA_MAINTENANCE_REREAD'] = true;
dm(!jeab_data_maintenance(), 'underhåll äldre än 30 minuter släpper');
$ttlBlocked = false;
try {
    jeab_data_write_guard();
} catch (RuntimeException $e) {
    $ttlBlocked = str_contains($e->getMessage(), 'Underhåll');
}
dm(!$ttlBlocked, 'en utgången underhållsflagga spärrar inte');
dm(jeab_data_maintenance_set(false) && !jeab_data_maintenance(), 'utgången flagga kan slås av');

$source = ['items' => [['id' => 7, 'name' => 'Yta'], ['id' => 3, 'name' => 'Ada']]];
$module = [
    'id' => 'surface',
    'table' => 'migrate_surface',
    'json' => [],
    'primary_key' => 'id',
    'columns' => ['id', 'name'],
    'schema' => [
        'sqlite' => 'CREATE TABLE IF NOT EXISTS migrate_surface (id INTEGER PRIMARY KEY, name TEXT)',
        'mysql' => 'CREATE TABLE IF NOT EXISTS migrate_surface (id INT PRIMARY KEY, name VARCHAR(64))',
    ],
    'rows' => 'jeab_migrate_surface_rows',
    'checksum' => 'jeab_migrate_surface_checksum',
];
$GLOBALS['_JEAB_MIGRATE_SURFACE_SOURCE'] = $source;
$copied = jeab_migrate_copy($module, false);
$againCopy = jeab_migrate_copy($module, false);
dm(!empty($copied['ok']) && !empty($copied['match']) && ($copied['checksum'] ?? '') === 'match', 'rows och checksum skrivs till sql');
dm((int) ($copied['json_count'] ?? 0) === 2 && (int) ($copied['sql_count'] ?? 0) === 2, 'ytan räknar två rader');
dm(!empty($againCopy['ok']) && !empty($againCopy['match']), 'andra kopian är idempotent');
unset($GLOBALS['_JEAB_MIGRATE_SURFACE_SOURCE']);

$storeDoc = [
    'customers' => [['id' => 1, 'name' => 'Ada Adress', 'created_at' => '2026-01-01 00:00:00']],
    'reminder_lists' => [['id' => 1, 'name' => 'Påminnelser', 'created_at' => '2026-01-01 00:00:00']],
    'bookings' => [[
        'id' => 10,
        'customer_id' => 1,
        'customer_name' => 'Ada Adress',
        'assignee' => 'Elin',
        'starts_at' => '2026-02-01 08:00:00',
        'ends_at' => '2026-02-01 10:00:00',
        'status' => 'booked',
        'contact_id' => 'kt_dm_ada',
        'address' => 'Exempelgatan 12',
    ]],
    'reminders' => [[
        'id' => 20,
        'list_id' => 1,
        'title' => 'Ring Ada',
        'contact_id' => 'kt_dm_ada',
        'booking_id' => 10,
        'address' => 'Exempelgatan 12',
        'postal' => '17740',
        'ort' => 'Järfälla',
    ]],
];
file_put_contents($tmp . '/store.json', json_encode($storeDoc, JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/purchases.json', json_encode([[
    'reminder_id' => 20,
    'contact_id' => 'kt_dm_ada',
    'kind' => 'laddbox',
    'customer_name' => 'Ada Adress',
    'company' => 'jeab',
]], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/notes.json', json_encode(['notes' => [['id' => 4, 'text' => 'Hej']]], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/app-errors.jsonl', "{\"at\":\"2026-10-01T10:00:00Z\",\"msg\":\"timeout\"}\n");
$customerRows = jeab_data_read('customers');
dm(isset($customerRows['1']), 'datalagret läser kunder');
jeab_data_write('customers', array_values($customerRows));
dm(isset(jeab_data_read('customers')['1']), 'datalagret skriver kunder tillbaka');
$notesBlocked = false;
try {
    jeab_data_write('notes', [['id' => 4, 'payload' => '{}']]);
} catch (RuntimeException $e) {
    $notesBlocked = str_contains($e->getMessage(), 'JSON');
}
dm($notesBlocked, 'en delad eller opak typ skrivs inte över');

$dry = jeab_data_migrate_all([]);
dm(!empty($dry['dry']) && empty($dry['written']) && !empty($dry['ok']), 'torrkörning skriver inte');
dm(jeab_data_backend() === 'json' && !jeab_data_backend_explicit() && !jeab_data_maintenance(), 'torrkörning lämnar flagga och underhåll');
dm(($dry['links']['before'] ?? null) === ($dry['links']['after'] ?? null), 'torrkörningen visar kopplingarna');
dm((int) ($dry['budget_sec'] ?? 0) === 900, 'budgeten är 15 minuter');
dm((int) ($dry['totals']['types'] ?? 0) >= 40, 'torrkörningen räknar typerna');
dm((int) ($dry['totals']['json_rows'] ?? 0) >= 4, 'torrkörningen räknar raderna');
dm((int) ($dry['references']['unknown'] ?? 1) === 0 && (int) ($dry['references']['checked'] ?? 0) === 2, 'bokning och påminnelse pekar på kontakten');
dm(empty($dry['ready']), 'torrkörning slår inte på flaggan');

$GLOBALS['_JEAB_DATA_MIGRATE_FAIL'] = 'purchases';
$blockedWet = jeab_data_migrate_all(['dry' => 0]);
unset($GLOBALS['_JEAB_DATA_MIGRATE_FAIL']);
dm(empty($blockedWet['ok']) && empty($blockedWet['written']) && !jeab_data_backend_explicit(), 'en stoppad kontroll lämnar json');
dm(!jeab_data_maintenance(), 'svepet släpper underhåll');

$wet = jeab_data_migrate_all(['dry' => 0]);
dm(!empty($wet['ok']) && !empty($wet['written']) && jeab_data_backend() === 'mysql', 'svepet slår på mysql');
dm(!jeab_data_maintenance(), 'svepet släpper underhåll efteråt');
dm(jeab_kontakter_storage_mode() === 'sql' && isset(jeab_kontakter_load()['kt_dm_ada']), 'kontakten läses från sql');
dm(($wet['links']['before'] ?? null) === ($wet['links']['after'] ?? null), 'kopplingarna är oförändrade');
dm((int) ($wet['references']['unknown'] ?? 1) === 0, 'kopplingarna finns kvar');
dm((int) ($wet['totals']['unmatched'] ?? 1) === 0, 'alla typer har samma checksumma');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-data-migrate']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-data-migrate';
$hook = jeab_data_migrate_dispatch(['action' => 'data.migrate_all']);
dm(is_string($hook['job_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/', (string) $hook['job_id']) === 1 && ($hook['state'] ?? '') === 'queued' && (int) ($hook['status'] ?? 0) === 200, 'hook svarar med job_id');
dm(!isset($hook['migrate']), 'svaret väntar inte på svepet');
$busy = jeab_data_migrate_dispatch(['action' => 'data.migrate_all']);
dm(empty($busy['ok']) && (int) ($busy['status'] ?? 0) === 409 && ($busy['job_id'] ?? '') === $hook['job_id'], 'samma typ kan inte köra två gånger');
$GLOBALS['_JEAB_DATA_JOB_MAX_STEPS'] = 1;
jeab_data_job_run((string) $hook['job_id']);
unset($GLOBALS['_JEAB_DATA_JOB_MAX_STEPS']);
$mid = jeab_data_job_status_dispatch(['action' => 'data.job_status', 'job_id' => (string) $hook['job_id']]);
dm(($mid['state'] ?? '') === 'running' && ($mid['progress']['phase'] ?? '') === 'copy', 'första biten sparar förloppet');
jeab_data_job_run((string) $hook['job_id']);
$done = jeab_data_job_status_dispatch(['job_id' => (string) $hook['job_id']]);
dm(($done['state'] ?? '') === 'done' && !empty($done['result']['dry']) && empty($done['result']['written']) && (int) ($done['status'] ?? 0) === 200, 'jobbet är torrt när det är klart');
dm((int) ($done['result']['totals']['types'] ?? 0) >= 40, 'jobbet räknar typerna');
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_data_migrate_dispatch(['action' => 'data.migrate_all', 'dry' => 0]);
dm(empty($denied['ok']) && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');
$deniedStatus = jeab_data_job_status_dispatch(['job_id' => (string) $hook['job_id']]);
dm(empty($deniedStatus['ok']) && (int) ($deniedStatus['status'] ?? 0) === 401, 'status utan token skriver inte');

echo "data_migrate_all_test ok\n";

/** @param array<string, mixed> $source */
function jeab_migrate_surface_rows(array $source): array
{
    $source = $GLOBALS['_JEAB_MIGRATE_SURFACE_SOURCE'] ?? $source;
    $list = isset($source['items']) && is_array($source['items']) ? $source['items'] : [];
    $rows = [];
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }
    return jeab_migrate_project($rows, 'id', ['id', 'name']);
}

function jeab_migrate_surface_checksum(array $source): string
{
    return jeab_migrate_checksum(jeab_migrate_surface_rows($source));
}
