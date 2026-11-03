<?php

declare(strict_types=1);

/**
 * LEGACY_JSON_WRITES är av som standard. Env 1 skriver dagens JSON-fil.
 * Bara miljövariabeln styr. Settings i databasen ändrar inget.
 * Av, typen explicit i SQL och senaste verify grön: ingen fil, raden ligger i SQL.
 * Av utan det skriver filen. Påhittade rader. Inga personnummer. Inga utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-legacy-json-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('LEGACY_JSON_WRITES');
putenv('DATA_BACKEND');
unset($_ENV['LEGACY_JSON_WRITES'], $_ENV['DATA_BACKEND']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

final class LjClosedStore implements JeabStore
{
    public function listReminders(bool $includeCompleted = true): array
    {
        unset($includeCompleted);
        throw new RuntimeException('ingen lista');
    }

    public function getReminder(int $id): ?array
    {
        unset($id);

        return null;
    }

    public function insertReminder(array $data): array
    {
        unset($data);

        return [];
    }

    public function saveReminder(int $id, array $fields): array
    {
        unset($id, $fields);

        return [];
    }

    public function incrementReminderTap(int $id, string $field): array
    {
        unset($id, $field);

        return [];
    }

    public function deleteReminders(array $ids): int
    {
        unset($ids);

        return 0;
    }

    public function findOpenReminderByBooking(int $bookingId): ?array
    {
        unset($bookingId);

        return null;
    }

    public function findCustomerByName(string $name): ?array
    {
        unset($name);

        return null;
    }

    public function insertCustomer(string $name, string $createdAt): array
    {
        unset($name, $createdAt);

        return [];
    }

    public function listCustomers(): array
    {
        return [];
    }

    public function recentAssignees(int $limit): array
    {
        unset($limit);

        return [];
    }

    public function getBooking(int $id): ?array
    {
        unset($id);

        return null;
    }

    public function bookingsBetween(string $from, string $to): array
    {
        unset($from, $to);

        return [];
    }

    public function bookingsCreatedBetween(string $from, string $to): array
    {
        unset($from, $to);

        return [];
    }

    public function bookingsUpdatedBetween(string $from, string $to): array
    {
        unset($from, $to);

        return [];
    }

    public function listAllBookings(): array
    {
        return [];
    }

    public function insertBooking(array $data): array
    {
        unset($data);

        return [];
    }

    public function saveBooking(int $id, array $fields): array
    {
        unset($id, $fields);

        return [];
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        unset($key);

        return $default;
    }

    public function setSetting(string $key, string $value): void
    {
        unset($key, $value);
    }
}

final class LjEmptyBackup implements JeabContactStore
{
    public function all(): array
    {
        return [];
    }

    public function backup(): string
    {
        return '';
    }

    public function insertMany(array $rows): array
    {
        unset($rows);
        throw new RuntimeException('skrev');
    }
}

function lj(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return list<string> */
function lj_baks(string $dir): array
{
    $found = glob($dir . '/kontakter.bak-*.json');

    return is_array($found) ? $found : [];
}

function lj_flag(?string $value): void
{
    if ($value === null) {
        putenv('LEGACY_JSON_WRITES');
        unset($_ENV['LEGACY_JSON_WRITES']);
        return;
    }
    putenv('LEGACY_JSON_WRITES=' . $value);
    $_ENV['LEGACY_JSON_WRITES'] = $value;
}

/** @param list<array<string, mixed>> $rows */
function lj_verify(PDO $pdo, array $rows): void
{
    jeab_ensure_data_jobs($pdo, 'sqlite');
    $pdo->exec("DELETE FROM data_jobs WHERE job_type = 'data.migrate_all'");
    $now = '2026-10-23 12:00:00';
    $result = json_encode(['ok' => true, 'verify' => $rows], JSON_UNESCAPED_UNICODE);
    $pdo->prepare(
        'INSERT INTO data_jobs (job_id, job_type, status, progress_json, state_json, result_json, payload_json, heartbeat_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        bin2hex(random_bytes(16)),
        'data.migrate_all',
        'done',
        '{}',
        '{}',
        is_string($result) ? $result : '{}',
        '{}',
        $now,
        $now,
        $now,
    ]);
    unset($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX'], $GLOBALS['_JEAB_DATA_MIGRATE_VERIFY']);
}

function lj_flag_state(array $state, ?string $env, bool $effective, string $message): void
{
    lj(array_key_exists('env', $state) && $state['env'] === $env && ($state['effective'] ?? null) === $effective, $message);
}

lj(function_exists('jeab_legacy_json_writes') && jeab_legacy_json_writes() === false, 'flaggan är av utan env och utan rad');
lj_flag_state(jeab_legacy_json_writes_state(), null, false, 'utan env är effektivt läge av');

lj_flag('0');
lj(jeab_legacy_json_writes() === false, 'env 0 slår av');
lj_flag_state(jeab_legacy_json_writes_state(), '0', false, 'env 0 syns i läget');
lj_flag('1');
lj(jeab_legacy_json_writes() === true, 'env 1 slår på');
lj_flag_state(jeab_legacy_json_writes_state(), '1', true, 'env 1 syns i läget');
lj_flag('off');
lj(jeab_legacy_json_writes() === false, 'env off slår av');
lj_flag('on');
lj(jeab_legacy_json_writes() === true, 'env on slår på');
lj_flag(null);
lj(jeab_legacy_json_writes() === false, 'borttagen env är av igen');
lj_flag_state(jeab_legacy_json_writes_state(), null, false, 'borttagen env syns som tom');

$store = jeab_store();
lj($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
$sole = jeab_contacts_sole_verify($store);
lj_flag_state(is_array($sole['legacy_json_writes'] ?? null) ? $sole['legacy_json_writes'] : [], null, false, 'sole_verify visar env och effektivt läge');
$report = jeab_data_migrate_response([
    'dry' => true,
    'errors' => [],
    'verified' => [],
    'migrated' => [],
    'started' => microtime(true),
    'flipped' => false,
]);
lj_flag_state(is_array($report['legacy_json_writes'] ?? null) ? $report['legacy_json_writes'] : [], null, false, 'data-svaret visar env och effektivt läge');
lj_flag('0');
$soleOff = jeab_contacts_sole_verify($store);
lj_flag_state(is_array($soleOff['legacy_json_writes'] ?? null) ? $soleOff['legacy_json_writes'] : [], '0', false, 'sole_verify visar env 0');
$reportOff = jeab_data_migrate_response([
    'dry' => true,
    'errors' => [],
    'verified' => [],
    'migrated' => [],
    'started' => microtime(true),
    'flipped' => false,
]);
lj_flag_state(is_array($reportOff['legacy_json_writes'] ?? null) ? $reportOff['legacy_json_writes'] : [], '0', false, 'data-svaret visar env 0');
lj_flag(null);

lj(jeab_settings_put($pdo, 'sqlite', JEAB_LEGACY_JSON_WRITES_KEY, '0'), 'inställningen sparas');
lj(jeab_legacy_json_writes() === false, 'inställningen 0 slår inte på');
lj_flag('0');
lj(jeab_legacy_json_writes() === false, 'env 0 slår av trots inställningen');
lj(jeab_settings_put($pdo, 'sqlite', JEAB_LEGACY_JSON_WRITES_KEY, '1'), 'inställningen sätts på igen');
lj(jeab_legacy_json_writes() === false, 'inställningen 1 slår inte på när env är 0');
lj_flag('1');
lj(jeab_legacy_json_writes() === true, 'env 1 slår på över inställningen');
lj_flag(null);

$purchasePath = jeab_purchase_path();
lj_flag('1');
lj(jeab_purchase_json_write([
    '880501' => [
        'reminder_id' => 880501,
        'kind' => 'laddbox',
        'customer_name' => 'Fil Order',
        'email' => 'fil.order@example.test',
        'phone' => '0701000501',
    ],
]), 'flagga på skriver purchases.json');
$purchaseRaw = (string) file_get_contents($purchasePath);
lj(is_file($purchasePath) && str_contains($purchaseRaw, '880501') && str_contains($purchaseRaw, 'Fil Order'), 'purchases.json har raden');

lj_flag('0');
lj(jeab_purchases_storage_mode() === 'json', 'purchases är json');
lj(jeab_legacy_json_skip('purchases') === false, 'flagga av i json-läge hoppar inte över');
lj(jeab_purchase_json_write([
    '880511' => [
        'reminder_id' => 880511,
        'kind' => 'sol',
        'customer_name' => 'Fortfarande Fil',
        'email' => 'fortfarande.fil@example.test',
        'phone' => '0701000511',
    ],
]), 'flagga av i json-läge skriver ändå');
lj(str_contains((string) file_get_contents($purchasePath), '880511'), 'json-läge behåller filen');

lj(jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql'), 'purchases_storage=sql');
lj(jeab_purchases_storage_mode() === 'sql' && jeab_legacy_json_skip('purchases') === false, 'sql utan grön verify hoppar inte över');
lj(jeab_purchase_json_write([
    '880512' => [
        'reminder_id' => 880512,
        'kind' => 'sol',
        'customer_name' => 'Overifierad Order',
        'email' => 'overifierad.order@example.test',
        'phone' => '0701000512',
    ],
]), 'overifierad sql skriver json');
lj(str_contains((string) file_get_contents($purchasePath), '880512'), 'purchases.json finns kvar utan verify');
lj_verify($pdo, [['type' => 'purchases', 'ok' => true, 'match' => false]]);
lj(jeab_legacy_json_skip('purchases') === false, 'röd verify hoppar inte över');
lj_verify($pdo, [['type' => 'purchases', 'ok' => true, 'match' => true]]);
lj(jeab_purchases_storage_mode() === 'sql' && jeab_legacy_json_skip('purchases') === true, 'flagga av, sql och grön verify hoppar över');
$GLOBALS['_JEAB_DATA_MIGRATE_VERIFY'] = [['type' => 'purchases', 'ok' => true, 'match' => false]];
unset($GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX']);
lj(jeab_legacy_json_skip('purchases') === false, 'senaste verify i svepet vinner över jobbet');
unset($GLOBALS['_JEAB_DATA_MIGRATE_VERIFY'], $GLOBALS['_JEAB_LEGACY_JSON_VERIFY_INDEX']);
lj(jeab_legacy_json_skip('purchases') === true, 'jobbets gröna verify gäller igen');
unlink($purchasePath);
lj(jeab_purchase_json_write([
    '880522' => [
        'reminder_id' => 880522,
        'kind' => 'batteri',
        'customer_name' => 'Inte Fil',
        'email' => 'inte.fil@example.test',
        'phone' => '0701000522',
    ],
]) === true, 'json-skriv i SQL-läge sparar raden i SQL');
lj(!is_file($purchasePath), 'purchases.json skapas inte');
$sqlSkipped = jeab_sql_purchases_get(jeab_purchase_sql_pdo(), 880522);
lj(is_array($sqlSkipped) && ($sqlSkipped['customer_name'] ?? '') === 'Inte Fil', 'oskriven JSON-väg ligger i SQL');
lj(jeab_purchase_json_write([
    'noll' => [
        'reminder_id' => 0,
        'customer_name' => 'Noll Ordning',
        'email' => 'noll.ordning@example.test',
        'phone' => '0701000520',
    ],
]) === false, 'utan id blir det false');
lj(!is_file($purchasePath), 'misslyckad SQL-väg skapar ingen fil');
$claimed = is_array($sqlSkipped) ? $sqlSkipped : [];
$claimed['customer_name'] = 'Andrat Namn';
lj(jeab_purchase_json_write(['880522' => $claimed], true) === true, 'redan skriven SQL ger true');
$stillSkipped = jeab_sql_purchases_get(jeab_purchase_sql_pdo(), 880522);
lj(is_array($stillSkipped) && ($stillSkipped['customer_name'] ?? '') === 'Inte Fil', 'redan skriven rad skrivs inte om');
lj(jeab_purchase_json_write([
    '880523' => [
        'reminder_id' => 880523,
        'kind' => 'sol',
        'customer_name' => 'Bevis Saknas',
        'email' => 'bevis.saknas@example.test',
        'phone' => '0701000523',
    ],
], true) === true, 'påstådd SQL som saknas skrivs');
$proved = jeab_sql_purchases_get(jeab_purchase_sql_pdo(), 880523);
lj(is_array($proved) && ($proved['customer_name'] ?? '') === 'Bevis Saknas', 'raden finns efter skrivningen');
jeab_purchase_cache_forget();
$saved = jeab_purchase_put([
    'reminder_id' => 880521,
    'kind' => 'laddbox',
    'customer_name' => 'Sql Order',
    'email' => 'sql.order@example.test',
    'phone' => '0701000521',
]);
lj((int) ($saved['reminder_id'] ?? 0) === 880521, 'ordern sparas');
lj(!is_file($purchasePath), 'put skapar ingen purchases.json');
$sqlPurchase = jeab_sql_purchases_get(jeab_purchase_sql_pdo(), 880521);
lj(is_array($sqlPurchase) && ($sqlPurchase['customer_name'] ?? '') === 'Sql Order', 'ordern ligger i SQL');

lj_flag('1');
lj(jeab_legacy_json_skip('store') === false, 'store skrivs när flaggan är på');
$storeFile = $tmp . '/legacy-store.json';
$jsonStore = new JeabJsonStore($storeFile);
$jsonStore->insertCustomer('Fil Kund', jeab_now());
lj(is_file($storeFile) && str_contains((string) file_get_contents($storeFile), 'Fil Kund'), 'store.json skrivs när flaggan är på');

lj_flag('0');
lj(jeab_store_storage_mode() !== 'sql', 'store är json');
$storeFileJson = $tmp . '/legacy-store-json.json';
$jsonStoreStill = new JeabJsonStore($storeFileJson);
$jsonStoreStill->insertCustomer('Fortfarande Store', jeab_now());
lj(is_file($storeFileJson) && str_contains((string) file_get_contents($storeFileJson), 'Fortfarande Store'), 'store.json skrivs i json-läge');

lj(jeab_settings_put($pdo, 'sqlite', 'store_storage', 'sql'), 'store_storage=sql');
lj(jeab_store_storage_mode() === 'sql' && jeab_legacy_json_skip('store') === false, 'store utan grön verify skrivs');
$storeFileSql = $tmp . '/legacy-store-sql.json';
$jsonStoreSql = new JeabJsonStore($storeFileSql);
$jsonStoreSql->insertCustomer('Inte Store', jeab_now());
lj(is_file($storeFileSql) && str_contains((string) file_get_contents($storeFileSql), 'Inte Store'), 'store.json skrivs utan grön verify');
lj_verify($pdo, [['type' => 'customers', 'ok' => true, 'match' => true]]);
lj(jeab_legacy_json_skip('store') === false, 'ofullständig verify skriver store');
lj_verify($pdo, [
    ['type' => 'customers', 'ok' => true, 'match' => true],
    ['type' => 'bookings', 'ok' => true, 'match' => true],
    ['type' => 'reminders', 'ok' => true, 'match' => true],
    ['type' => 'reminder_lists', 'ok' => true, 'match' => false],
]);
lj(jeab_legacy_json_skip('store') === false, 'röd delverify skriver store');
lj_verify($pdo, [
    ['type' => 'customers', 'ok' => true, 'match' => true],
    ['type' => 'bookings', 'ok' => true, 'match' => true],
    ['type' => 'reminders', 'ok' => true, 'match' => true],
    ['type' => 'reminder_lists', 'ok' => true, 'match' => true],
]);
lj(jeab_store_storage_mode() === 'sql' && jeab_legacy_json_skip('store') === true, 'store hoppas över när verify är grön');
$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
lj(jeab_store_storage_mode() === 'json' && jeab_legacy_json_skip('store') === false, 'data_backend=json skriver store');
unset($GLOBALS['_JEAB_DATA_BACKEND']);
lj(jeab_legacy_json_skip('store') === true, 'grön verify gäller igen när json-flaggan tas bort');
$storeFileSql = $tmp . '/legacy-store-sql-green.json';
$jsonStoreSql = new JeabJsonStore($storeFileSql);
$storeThrew = false;
try {
    $jsonStoreSql->insertCustomer('Inte Store', jeab_now());
} catch (RuntimeException $e) {
    $storeThrew = $e->getMessage() === 'JSON-store är avstängd, använd SQL';
}
lj($storeThrew, 'JSON-store kastar i SQL-läge');
lj(!is_file($storeFileSql), 'store.json skapas inte');
$keptPath = $tmp . '/legacy-store-kept.json';
file_put_contents($keptPath, '{"customers":[{"id":1,"name":"Kvar"}]}');
$keptBefore = (string) file_get_contents($keptPath);
$keptStore = new JeabJsonStore($keptPath);
$writeThrew = false;
try {
    $write = new ReflectionMethod(JeabJsonStore::class, 'write');
    $write->invoke($keptStore, ['customers' => [['id' => 2, 'name' => 'Borta']]]);
} catch (RuntimeException $e) {
    $writeThrew = $e->getMessage() === 'JSON-store är avstängd, använd SQL';
}
lj($writeThrew && (string) file_get_contents($keptPath) === $keptBefore, 'write kastar och rör inte filen');
$errRaw = is_file($tmp . '/app-errors.jsonl') ? (string) file_get_contents($tmp . '/app-errors.jsonl') : '';
lj(
    str_contains($errRaw, 'legacy_json_writes_flag_test.php:')
    && !str_contains($errRaw, 'Inte Store')
    && !str_contains($errRaw, 'Borta'),
    'anropsstället loggas utan data'
);
$sqlCustomer = $store->insertCustomer('Sql Kund', jeab_now());
lj((int) ($sqlCustomer['id'] ?? 0) > 0, 'kunden får id');
$customerName = $pdo->query("SELECT name FROM customers WHERE name = 'Sql Kund'")->fetchColumn();
lj($customerName === 'Sql Kund', 'kunden ligger i SQL');

lj_flag('1');
require_once dirname(__DIR__) . '/src/ordrar_deleted_store.php';
lj(jeab_ordrar_deleted_storage_mode() === 'json', 'ordrar-deleted är json');
jeab_ordrar_deleted_save([
    'ids' => [
        '880502' => ['at' => '2026-10-23 10:00:00', 'hs' => [], 'booking_id' => 0],
    ],
    'hs' => [],
]);
$deletedPath = jeab_ordrar_deleted_path();
lj(is_file($deletedPath) && str_contains((string) file_get_contents($deletedPath), '880502'), 'ordrar-deleted.json skrivs när flaggan är på');

lj_flag('0');
lj(jeab_settings_put($pdo, 'sqlite', 'ordrar_deleted_storage', 'sql'), 'ordrar_deleted_storage=sql');
lj(jeab_ordrar_deleted_storage_mode() === 'sql' && jeab_legacy_json_skip('ordrar_deleted') === false, 'ordrar-deleted utan verify är inte sql-läge');
lj_verify($pdo, [['type' => 'ordrar_deleted', 'ok' => true, 'match' => true]]);
lj(jeab_legacy_json_skip('ordrar_deleted') === true, 'ordrar-deleted hoppas över när verify är grön');
unlink($deletedPath);
jeab_ordrar_deleted_save([
    'ids' => [
        '880503' => ['at' => '2026-10-23 11:00:00', 'hs' => [], 'booking_id' => 0],
    ],
    'hs' => [],
]);
lj(!is_file($deletedPath), 'ordrar-deleted.json skapas inte');
$deletedDoc = jeab_sql_doc_get($pdo, 'ordrar_deleted');
lj(is_array($deletedDoc) && isset($deletedDoc['ids']['880503']), 'raderingen ligger i SQL');

lj_flag('1');
lj(jeab_kontakter_storage_mode() === 'json', 'kontakter är json');
jeab_kontakter_sql_write_registry([
    'kt_flag0000000000501' => [
        'id' => 'kt_flag0000000000501',
        'name' => 'Reg Flagga',
        'email' => 'reg.flagga@example.test',
        'phone' => '0701000503',
    ],
]);
$contactsPath = $tmp . '/kontakter.json';
lj(is_file($contactsPath) && str_contains((string) file_get_contents($contactsPath), 'Reg Flagga'), 'kontakter.json skrivs när flaggan är på');
lj(!is_file($contactsPath . '.prev'), 'första skrivningen skapar ingen .prev');
$bakBefore = count(lj_baks($tmp));
$bakName = jeab_kontakter_backup_snapshot($store);
lj($bakName !== '' && is_file($tmp . '/' . $bakName), 'säkerhetskopian skrivs när flaggan är på');

lj_flag('0');
lj(jeab_settings_put($pdo, 'sqlite', JEAB_KONTAKTER_STORAGE_KEY, 'sql'), 'kontakter_storage=sql');
lj(jeab_kontakter_storage_mode() === 'sql' && jeab_legacy_json_skip('contacts') === false, 'kontakter utan verify hoppas inte över');
lj(jeab_legacy_json_skip('inkorg_contacts') === false, 'inkorg utan verify hoppas inte över');
$unverifiedBefore = (string) file_get_contents($contactsPath);
$inboxPathEarly = jeab_inkorg_dir() . '/contacts.json';
if (is_file($inboxPathEarly)) {
    unlink($inboxPathEarly);
}
jeab_kontakter_sql_write_registry([
    'kt_flag0000000000509' => [
        'id' => 'kt_flag0000000000509',
        'name' => 'Overifierad Reg',
        'email' => 'overifierad.reg@example.test',
        'phone' => '0701000509',
    ],
]);
jeab_kontakter_sql_write_inbox([
    'c-flag0509' => [
        'id' => 'c-flag0509',
        'name' => 'Overifierad Inkorg',
        'emails' => ['overifierad.inkorg@example.test'],
    ],
]);
lj((string) file_get_contents($contactsPath) === $unverifiedBefore && !is_file($contactsPath . '.prev'), 'kontakter.json skrivs inte när flaggan är av, även utan grön verify');
lj(!is_file($inboxPathEarly) && !is_file($inboxPathEarly . '.prev'), 'contacts.json skrivs inte när flaggan är av, även utan grön verify');
$rollEarly = jeab_kontakter_sql_cutover(['enable' => 0, 'dry' => 0]);
lj(empty($rollEarly['ok']) && jeab_kontakter_storage_mode() === 'sql', 'rollback utan snapshot stannar i SQL när flaggan är av');
lj(str_contains((string) ($rollEarly['error'] ?? ''), 'SQL-snapshot'), 'felet säger att snapshot saknas innan verify');
lj((string) file_get_contents($contactsPath) === $unverifiedBefore, 'rollback utan verify skriver inte kontakter.json');
lj_verify($pdo, [
    ['type' => 'contacts', 'ok' => true, 'match' => true],
    ['type' => 'inkorg_contacts', 'ok' => true, 'match' => true],
]);
lj(jeab_kontakter_storage_mode() === 'sql' && jeab_legacy_json_skip('contacts') === true, 'kontakter hoppas över');
lj(jeab_legacy_json_skip('inkorg_contacts') === true, 'inkorg-kontakter hoppas över');
$contactsBefore = (string) file_get_contents($contactsPath);
jeab_kontakter_sql_write_registry([
    'kt_flag0000000000502' => [
        'id' => 'kt_flag0000000000502',
        'name' => 'Inte Reg',
        'email' => 'inte.reg@example.test',
        'phone' => '0701000504',
    ],
]);
$inboxPath = jeab_inkorg_dir() . '/contacts.json';
jeab_kontakter_sql_write_inbox([
    'c-flag0503' => [
        'id' => 'c-flag0503',
        'name' => 'Inte Inkorg',
        'emails' => ['inte.inkorg@example.test'],
    ],
]);
lj((string) file_get_contents($contactsPath) === $contactsBefore, 'kontakter.json rörs inte');
lj(!is_file($contactsPath . '.prev'), 'ingen .prev för kontakter');
lj(!is_file($inboxPath) && !is_file($inboxPath . '.prev'), 'contacts.json i inkorg skapas inte');
$bakOn = count(lj_baks($tmp));
lj($bakOn === $bakBefore + 1, 'flaggan på lämnade en JSON-kopia');

$blockedEarly = jeab_kontakter_sql_cutover(['enable' => 0, 'dry' => 0]);
lj(empty($blockedEarly['ok']) && jeab_kontakter_storage_mode() === 'sql', 'rollback utan snapshot ger ok:false');
lj(str_contains((string) ($blockedEarly['error'] ?? ''), 'SQL-snapshot'), 'felet säger att snapshot saknas');
lj((string) file_get_contents($contactsPath) === $contactsBefore, 'rollback rör inte kontakter.json');

jeab_ensure_contacts_tables($pdo, 'sqlite');
$contact = jeab_kontakt_blank([
    'name' => 'Sql Kontakt',
    'email' => 'sql.kontakt@example.test',
    'phone' => '0701000505',
    'street' => 'Flaggagatan 5',
    'postal_code' => '11122',
    'city' => 'Teststad',
]);
$contact['id'] = 'kt_flag0000000000505';
(new JeabPdoContactStore($pdo))->upsert($contact['id'], $contact);
$contactId = $pdo->query("SELECT id FROM contacts WHERE id = 'kt_flag0000000000505'")->fetchColumn();
lj($contactId === 'kt_flag0000000000505', 'kontakten ligger i SQL');
lj(!str_contains((string) file_get_contents($contactsPath), 'Sql Kontakt'), 'kontakten skrevs inte till JSON');
$reminder = $store->insertReminder([
    'title' => 'Flagga Paminnelse',
    'customer_name' => 'Sql Kontakt',
    'email' => 'sql.kontakt@example.test',
    'phone' => '0701000505',
    'company' => 'jarfalla',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$reminderId = (int) ($reminder['id'] ?? 0);
lj($reminderId > 0, 'påminnelsen finns');
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_flag0000000000505', $reminderId]);
$snap = jeab_kontakter_backup_snapshot($store);
lj($snap !== '' && str_starts_with($snap, 'sql-snap-') && count(lj_baks($tmp)) === $bakOn, 'SQL-snapshot i stället för JSON-kopia');
$changed = $contact;
$changed['city'] = 'Annanstad';
(new JeabPdoContactStore($pdo))->upsert($contact['id'], $changed);
$extra = jeab_kontakt_blank([
    'name' => 'Extra Efter',
    'email' => 'extra.efter@example.test',
    'phone' => '0701000506',
]);
$extra['id'] = 'kt_flag0000000000506';
(new JeabPdoContactStore($pdo))->upsert($extra['id'], $extra);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute(['kt_annan', $reminderId]);
$restored = jeab_kontakter_sql_cutover(['enable' => 0, 'dry' => 0]);
lj(!empty($restored['ok']) && ($restored['rollback'] ?? '') === 'sql_snapshot', 'rollback återställer SQL-snapshoten');
lj(jeab_kontakter_storage_mode() === 'sql', 'lagret stannar i SQL');
lj((string) file_get_contents($contactsPath) === $contactsBefore, 'rollback skriver ingen JSON-fil');
lj(count(lj_baks($tmp)) === $bakOn, 'rollback skapar ingen JSON-kopia');
$cityBack = (string) (jeab_kontakter_load()['kt_flag0000000000505']['city'] ?? '');
lj($cityBack === 'Teststad', 'orten är återställd');
$extraId = $pdo->query("SELECT id FROM contacts WHERE id = 'kt_flag0000000000506'")->fetchColumn();
lj($extraId === false, 'kontakten efter snapshoten är borta');
$linkBack = $pdo->query('SELECT contact_id FROM reminders WHERE id = ' . $reminderId)->fetchColumn();
lj((string) $linkBack === 'kt_flag0000000000505', 'contact_id är återställd');

require_once dirname(__DIR__) . '/src/kontakter_address_import.php';
require_once dirname(__DIR__) . '/src/kontakter_import_new.php';
$bare = jeab_kontakt_blank([
    'name' => 'Adress Lost',
    'email' => 'adress.lost@example.test',
    'phone' => '0701000507',
]);
$bare['id'] = 'kt_flag0000000000507';
(new JeabPdoContactStore($pdo))->upsert($bare['id'], $bare);
$addressRow = [
    'contact_id' => 'kt_flag0000000000507',
    'confidence' => 0.95,
    'street' => 'Snapshotgatan',
    'number' => '7',
    'postal' => '11122',
    'city' => 'Teststad',
    'source' => 'test',
    'evidence' => 'testanteckning',
];
$deniedAddress = jeab_kontakter_address_import(['dry' => 0, 'rows' => [$addressRow]], new LjClosedStore());
lj(empty($deniedAddress['ok']) && ($deniedAddress['error'] ?? '') === 'Kunde inte spara säkerhetskopia.', 'adressimport nekas utan snapshot');
lj((string) (jeab_kontakter_load()['kt_flag0000000000507']['street'] ?? '') === '', 'nekad import skriver inte');
$appliedAddress = jeab_kontakter_address_import(['dry' => 0, 'rows' => [$addressRow]], $store);
lj(!empty($appliedAddress['ok']) && str_starts_with((string) ($appliedAddress['backup_file'] ?? ''), 'sql-snap-'), 'adressimport tar SQL-snapshot');
lj(str_contains((string) (jeab_kontakter_load()['kt_flag0000000000507']['street'] ?? ''), 'Snapshotgatan'), 'adressen skrivs i SQL');
lj((string) file_get_contents($contactsPath) === $contactsBefore && count(lj_baks($tmp)) === $bakOn, 'adressimport rör ingen JSON-fil');

$deniedImport = jeab_kontakter_import_new([
    'dry' => 0,
    'rows' => [[
        'namn' => 'Import Nekad',
        'mejl' => 'import.nekad@example.test',
        'telefon' => '0701000508',
        'gata' => 'Nekadgatan 1',
        'postnr' => '11122',
        'ort' => 'Teststad',
    ]],
], new LjEmptyBackup());
lj(empty($deniedImport['ok']) && ($deniedImport['error'] ?? '') === 'Kunde inte spara säkerhetskopia.', 'import nekas utan kopia');
$imported = jeab_kontakter_import_new([
    'dry' => 0,
    'rows' => [[
        'namn' => 'Import Sql',
        'mejl' => 'import.sql@example.test',
        'telefon' => '0701000509',
        'gata' => 'Importgatan 9',
        'postnr' => '11133',
        'ort' => 'Teststad',
    ]],
]);
lj(!empty($imported['ok']) && str_starts_with((string) ($imported['backup_file'] ?? ''), 'sql-snap-'), 'import_new tar SQL-snapshot');
$importedHit = false;
foreach (jeab_kontakter_load() as $importedRow) {
    if (!is_array($importedRow)) {
        continue;
    }
    $owner = is_array($importedRow['owners'][0] ?? null) ? $importedRow['owners'][0] : [];
    $email = (string) ($importedRow['email'] ?? ($owner['email'] ?? ''));
    if ($email === 'import.sql@example.test') {
        $importedHit = true;
    }
}
lj($importedHit, 'importen ligger i SQL');
lj(!str_contains((string) file_get_contents($contactsPath), 'Import Sql') && count(lj_baks($tmp)) === $bakOn, 'importen skriver ingen JSON-fil');

$GLOBALS['_JEAB_LOG_PDO'] = $pdo;
unset($GLOBALS['_JEAB_CALENDAR_LOG_PATH']);
$GLOBALS['_JEAB_CALENDAR_LOG_BACKUP_DIR'] = $tmp . '/cal-backup';
lj_flag('1');
jeab_calendar_log_write([
    'entries' => [[
        'id' => 1,
        'type' => 'booked',
        'at' => '2026-10-23 10:00:00',
        'booking_id' => 1,
        'title' => 'Flagga Mote',
        'starts_at' => '2026-10-24 09:00:00',
    ]],
    'backfilled' => true,
]);
$calendarDoc = jeab_log_doc_get_pdo($pdo, 'calendar_log');
lj(is_array($calendarDoc) && (($calendarDoc['entries'][0]['title'] ?? '') === 'Flagga Mote'), 'veckologgen ligger i SQL');
lj(!is_file(jeab_calendar_log_path()), 'calendar-log.json skapas inte av SQL-skrivningen');
$backupOn = jeab_calendar_log_backup(['entries' => $calendarDoc['entries']]);
$backupAbs = str_starts_with($backupOn, '/') ? $backupOn : $tmp . '/cal-backup/' . basename($backupOn);
lj($backupOn !== '' && is_file($backupAbs), 'loggkopia skrivs när flaggan är på');

lj_flag('0');
lj(jeab_legacy_json_skip('calendar_log') === false, 'veckologgen skrivs utan explicit sql och verify');
$backupStill = jeab_calendar_log_backup(['entries' => [['id' => 2, 'title' => 'Fortfarande Kopia']]]);
lj($backupStill !== '' && is_file(str_starts_with($backupStill, '/') ? $backupStill : $tmp . '/cal-backup/' . basename($backupStill)), 'loggkopia skrivs när typen inte är verifierad sql');
$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
lj(jeab_data_storage('calendar_log', 'json') === 'sql' && jeab_legacy_json_skip('calendar_log') === false, 'explicit sql utan grön verify hoppar inte över');
lj_verify($pdo, [['type' => 'calendar_log', 'ok' => true, 'match' => true]]);
lj(jeab_legacy_json_skip('calendar_log') === true, 'veckologgen hoppas över');
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
lj(jeab_legacy_json_skip('calendar_log') === false, 'utsatt loggfil skrivs');
unset($GLOBALS['_JEAB_CALENDAR_LOG_PATH']);
lj(jeab_legacy_json_skip('calendar_log') === true, 'veckologgen hoppas över igen');
$copiesBefore = glob($tmp . '/cal-backup/*.json');
$copiesBefore = is_array($copiesBefore) ? count($copiesBefore) : 0;
$backupOff = jeab_calendar_log_backup(['entries' => [['id' => 3, 'title' => 'Inte Kopia']]]);
$copiesAfter = glob($tmp . '/cal-backup/*.json');
$copiesAfter = is_array($copiesAfter) ? count($copiesAfter) : 0;
lj($backupOff === '' && $copiesAfter === $copiesBefore, 'ingen ny loggkopia');
unset($GLOBALS['_JEAB_DATA_BACKEND']);
$calendarStill = jeab_log_doc_get_pdo($pdo, 'calendar_log');
lj(is_array($calendarStill) && (($calendarStill['entries'][0]['title'] ?? '') === 'Flagga Mote'), 'veckologgen är kvar i SQL');

echo "legacy_json_writes_flag_test ok\n";
