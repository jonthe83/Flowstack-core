<?php

declare(strict_types=1);

/**
 * Prefixindex för kontaktsök skapas från tick och migrate, inte från en sidvisning.
 * MySQL-strängen har inget IF NOT EXISTS.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-db-prefix-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function dbp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$emailSql = jeab_create_index_sql('mysql', 'contacts_email_prefix', 'contacts (owner_email(64))');
$phoneSql = jeab_create_index_sql('mysql', 'contacts_phone_prefix', 'contacts (owner_phone(16))');
dbp($emailSql === 'CREATE INDEX contacts_email_prefix ON contacts (owner_email(64))', 'mysql-prefix för e-post saknar IF NOT EXISTS');
dbp($phoneSql === 'CREATE INDEX contacts_phone_prefix ON contacts (owner_phone(16))', 'mysql-prefix för telefon saknar IF NOT EXISTS');
dbp(!str_contains($emailSql, 'IF NOT EXISTS') && !str_contains($phoneSql, 'IF NOT EXISTS'), 'ingen av mysql-strängarna har IF NOT EXISTS');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$at = strpos($src, 'function jeab_ensure_contact_search_prefix');
$end = strpos($src, "\nfunction ", $at + 10);
$body = $at === false ? '' : substr($src, $at, ($end === false ? 0 : $end) - $at);
dbp($body !== '' && str_contains($body, 'jeab_create_index_sql') && str_contains($body, 'owner_email(64)') && str_contains($body, 'owner_phone(16)'), 'ensure bygger prefix via drivern');
dbp(!str_contains($body, 'IF NOT EXISTS'), 'ensure-funktionen har ingen IF NOT EXISTS');
dbp(str_contains($body, 'jeab_schema_ddl_blocked'), 'en sidvisning stoppas av ddl-spärren');
$migrate = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
dbp(str_contains($migrate, "jeab_ensure_contact_search_prefix(\$pdo, 'sqlite')") && str_contains($migrate, "jeab_ensure_contact_search_prefix(\$pdo, 'mysql')"), 'migrate anropar ensure');
$tick = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
dbp(str_contains($tick, 'jeab_ensure_contact_search_prefix'), 'tick anropar ensure');

$store = jeab_store();
dbp($store instanceof JeabPdoStore, 'sqlite är öppen');
$pdo = $store->pdo();
$names = jeab_existing_index_names($pdo, 'contacts', 'sqlite');
dbp(in_array('contacts_email_prefix', $names, true) && in_array('contacts_phone_prefix', $names, true), 'migrate skapade prefixindexen');

$pdo->exec('DROP INDEX IF EXISTS contacts_email_prefix');
$pdo->exec('DROP INDEX IF EXISTS contacts_phone_prefix');
$pdo->prepare('DELETE FROM settings WHERE "key" = ?')->execute(['contacts_search_prefix_v1']);
jeab_settings_cache_forget($pdo, 'sqlite', 'contacts_search_prefix_v1');
unset($GLOBALS['_JEAB_MIGRATE_RUNS'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_SCHEMA_DDL']);
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
jeab_ensure_contact_search_prefix($pdo, 'sqlite');
$afterPage = jeab_existing_index_names($pdo, 'contacts', 'sqlite');
dbp(!in_array('contacts_email_prefix', $afterPage, true) && !in_array('contacts_phone_prefix', $afterPage, true), 'sidvisning skapar inte prefixindex');

$GLOBALS['_JEAB_TICK'] = true;
jeab_ensure_contact_search_prefix($pdo, 'sqlite');
$afterTick = jeab_existing_index_names($pdo, 'contacts', 'sqlite');
dbp(in_array('contacts_email_prefix', $afterTick, true) && in_array('contacts_phone_prefix', $afterTick, true), 'tick skapar prefixindex');
dbp(jeab_settings_value($pdo, 'sqlite', 'contacts_search_prefix_v1') === 'ok', 'flaggan är ok');
jeab_ensure_contact_search_prefix($pdo, 'sqlite');
dbp(jeab_settings_value($pdo, 'sqlite', 'contacts_search_prefix_v1') === 'ok', 'andra tick-anropet lämnar flaggan');

echo "db contact prefix ok\n";
