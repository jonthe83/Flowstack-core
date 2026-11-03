<?php

declare(strict_types=1);

/**
 * Bokningar, påminnelser och kontaktregistret är bara SQL.
 * Påhittade rader. JSON-filerna skrivs inte, och SQL-fel kastar.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$php = PHP_BINARY;

function sql_only_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sql_only(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    sql_only_fail($message);
}

$down = sys_get_temp_dir() . '/jeab-sql-only-down-' . bin2hex(random_bytes(4));
$downPrivate = $down . '/private';
$downPublic = $down . '/public';
mkdir($downPrivate, 0775, true);
mkdir($downPublic, 0775, true);
$decoyStore = '{"bookings":[{"id":1,"customer_name":"Decoy"}],"reminders":[{"id":1,"title":"Decoy"}]}';
$decoyContacts = '{"contacts":{"kt_decoy":{"id":"kt_decoy","name":"Decoy"}}}';
file_put_contents($downPrivate . '/store.json', $decoyStore);
file_put_contents($downPrivate . '/kontakter.json', $decoyContacts);
file_put_contents($downPrivate . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $downPrivate . '/app.sqlite',
        'json_path' => $downPrivate . '/store.json',
    ],
], true) . ";\n");
$runner = $down . '/down.php';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = $argv[1];
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $argv[2];
$GLOBALS['_JEAB_FELLOGG_DIR'] = $argv[1];
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
putenv('JEAB_ASSERT_SQL_DOWN=1');
$_ENV['JEAB_ASSERT_SQL_DOWN'] = '1';
require $argv[3] . '/src/bootstrap.php';
$storeMsg = '';
try {
    jeab_store();
    $storeMsg = 'open';
} catch (RuntimeException $e) {
    $storeMsg = $e->getMessage();
}
$contactMsg = '';
try {
    jeab_kontakter_mutate(static function (array &$contacts): void {
        $contacts['kt_fail'] = ['id' => 'kt_fail', 'name' => 'Fail Test'];
    });
    $contactMsg = 'open';
} catch (RuntimeException $e) {
    $contactMsg = $e->getMessage();
}
echo $storeMsg . "\n";
echo $contactMsg . "\n";
echo is_file($argv[1] . '/app.sqlite') ? "sqlite-created\n" : "sqlite-absent\n";
$log = is_file($argv[1] . '/app-errors.jsonl') ? (string) file_get_contents($argv[1] . '/app-errors.jsonl') : '';
echo str_contains($log, 'SQL saknas') ? "fellogg\n" : "no-fellogg\n";
echo substr_count($log, 'SQL saknas') >= 2 ? "fellogg-both\n" : "fellogg-short\n";
PHP);
$proc = proc_open(
    [$php, $runner, $downPrivate, $downPublic, $root],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$stdout = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$stderr = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : '';
if (is_resource($proc)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
} else {
    $code = 1;
}
sql_only($code === 0, 'sql-nere-processen avslutas');
if ($code !== 0) {
    fwrite(STDERR, $stderr);
}
$lines = array_values(array_filter(explode("\n", trim($stdout)), static fn (string $line): bool => $line !== ''));
sql_only(($lines[0] ?? '') === 'Databasen är inte öppen.', 'butiken kastar när SQL inte kan öppnas');
sql_only(($lines[1] ?? '') === 'Kontaktregistret kan inte sparas.', 'kontaktregistret kastar utan JSON');
sql_only(($lines[2] ?? '') === 'sqlite-absent', 'avstängd sqlite skapar ingen databas');
sql_only(($lines[3] ?? '') === 'fellogg' && ($lines[4] ?? '') === 'fellogg-both', 'felloggen får SQL saknas för butik och kontakter');
sql_only((string) file_get_contents($downPrivate . '/store.json') === $decoyStore, 'store.json ändras inte när SQL fallerar');
sql_only((string) file_get_contents($downPrivate . '/kontakter.json') === $decoyContacts, 'kontakter.json ändras inte när SQL fallerar');
sql_only(!is_file($downPublic . '/store.json') && !is_file($downPublic . '/kontakter.json'), 'ingen JSON-reserv skapas');

$tmp = sys_get_temp_dir() . '/jeab-sql-only-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
mkdir($private, 0775, true);
mkdir($tmp . '/public', 0775, true);
$keptStore = '{"bookings":[{"id":9,"customer_name":"Oläst"}],"reminders":[{"id":9,"title":"Oläst"}]}';
$keptContacts = '{"contacts":{"kt_olast":{"id":"kt_olast","name":"Oläst"}}}';
file_put_contents($private . '/store.json', $keptStore);
file_put_contents($private . '/kontakter.json', $keptContacts);
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
$GLOBALS['_JEAB_FELLOGG_DIR'] = $private;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
require $root . '/src/bootstrap.php';
date_default_timezone_set('Europe/Stockholm');

sql_only(jeab_driver() === 'sqlite', 'drivern är sqlite');
$store = jeab_store();
sql_only($store instanceof JeabPdoStore, 'butiken är SQL');
sql_only(jeab_driver() === 'sqlite', 'öppningen stannar på sqlite');
sql_only(jeab_kontakter_storage_mode() === 'json', 'inkorgsflaggan är kvar');
$customer = $store->insertCustomer('Sql Only', '2026-11-01 08:00:00');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Sql Only',
    'email' => 'sql.only@example.test',
    'phone' => '0701000001',
    'address' => 'Exempelgatan 1',
    'assignees' => ['Kim'],
    'company' => 'jarfalla',
    'job_type' => 'service',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => '2026-11-01 08:00:00',
    'updated_at' => '2026-11-01 08:00:00',
]);
$reminder = $store->insertReminder([
    'title' => 'Sql påminnelse',
    'customer_name' => 'Sql Only',
    'company' => 'jarfalla',
    'created_at' => '2026-11-01 08:00:00',
    'updated_at' => '2026-11-01 08:00:00',
]);
sql_only((int) ($booking['id'] ?? 0) > 0 && (string) ($booking['customer_name'] ?? '') === 'Sql Only', 'bokningen läses från SQL');
sql_only((int) ($reminder['id'] ?? 0) > 0 && (string) ($reminder['title'] ?? '') === 'Sql påminnelse', 'påminnelsen läses från SQL');
sql_only($store->getBooking((int) $booking['id'])['customer_name'] === 'Sql Only', 'bokningen finns kvar i SQL');
sql_only((string) ($store->getReminder((int) $reminder['id'])['title'] ?? '') === 'Sql påminnelse', 'påminnelsen finns kvar i SQL');
$names = array_map(static fn (array $row): string => (string) ($row['customer_name'] ?? ''), $store->listAllBookings());
sql_only(!in_array('Oläst', $names, true), 'store.json läses inte');

$row = jeab_kontakt_blank([
    'name' => 'Sql Kontakt',
    'email' => 'sql.kontakt@example.test',
    'phone' => '0701000002',
    'street' => 'Sqlgatan 2',
    'postal_code' => '11122',
    'city' => 'Teststad',
]);
$row['id'] = 'kt_sqlonly000000001';
jeab_kontakter_mutate(static function (array &$contacts) use ($row): void {
    $contacts[(string) $row['id']] = $row;
});
$loaded = jeab_kontakter_load();
sql_only(isset($loaded['kt_sqlonly000000001']) && !isset($loaded['kt_olast']), 'registret läser SQL och inte kontakter.json');
sql_only((string) file_get_contents($private . '/store.json') === $keptStore, 'sparad bokning skriver inte store.json');
sql_only((string) file_get_contents($private . '/kontakter.json') === $keptContacts, 'sparad kontakt skriver inte kontakter.json');
sql_only(jeab_driver() === 'sqlite' && jeab_kontakter_storage_mode() === 'json', 'inkorgsflaggan är kvar');

echo "ok\n";
