<?php

declare(strict_types=1);

/**
 * Nattligt MySQL-schema: prefixindex skapas utan IF NOT EXISTS.
 * Utan JEAB_MYSQL_DSN hoppas den levande delen över. Ingen kundrad.
 */

$tmp = sys_get_temp_dir() . '/jeab-mysql-nightly-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function my(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$at = strpos($src, 'function jeab_ensure_contact_search_prefix');
$end = strpos($src, "\nfunction ", $at + 10);
$body = substr($src, (int) $at, ($end === false ? 0 : $end) - (int) $at);
my($body !== '' && !str_contains($body, 'IF NOT EXISTS'), 'prefix-ensure har inget IF NOT EXISTS');
my(str_contains(jeab_create_index_sql('mysql', 'contacts_phone_prefix', 'contacts (owner_phone(16))'), 'CREATE INDEX contacts_phone_prefix'), 'mysql-hjälparen skriver CREATE INDEX');

$dsn = getenv('JEAB_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo "SKIP mysql-live, JEAB_MYSQL_DSN saknas\n";
    echo "db mysql nightly ok\n";
    exit(0);
}
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "FAIL pdo_mysql saknas trots DSN\n");
    exit(1);
}
$user = getenv('JEAB_MYSQL_USER');
$pass = getenv('JEAB_MYSQL_PASS');
$pdo = new PDO($dsn, is_string($user) ? $user : 'root', is_string($pass) ? $pass : '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('DROP TABLE IF EXISTS contacts');
$pdo->exec('DROP TABLE IF EXISTS settings');
$pdo->exec(
    'CREATE TABLE settings (`key` VARCHAR(64) NOT NULL, value TEXT NOT NULL, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$pdo->exec(
    'CREATE TABLE contacts (
        id VARCHAR(32) NOT NULL,
        row_json LONGTEXT NOT NULL,
        street_key VARCHAR(191) NOT NULL DEFAULT \'\',
        postal_code VARCHAR(16) NOT NULL DEFAULT \'\',
        owner_email VARCHAR(191) NOT NULL DEFAULT \'\',
        owner_phone VARCHAR(32) NOT NULL DEFAULT \'\',
        owner_name_fold VARCHAR(191) NOT NULL DEFAULT \'\',
        company VARCHAR(32) NOT NULL DEFAULT \'\',
        city_fold VARCHAR(191) NOT NULL DEFAULT \'\',
        sort_key VARCHAR(191) NOT NULL DEFAULT \'\',
        sort_city VARCHAR(191) NOT NULL DEFAULT \'\',
        list_ready TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
$GLOBALS['_JEAB_TICK'] = true;
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_MIGRATE_RUNS']);
jeab_ensure_contact_search_prefix($pdo, 'mysql');
$names = jeab_existing_index_names($pdo, 'contacts', 'mysql');
my(in_array('contacts_email_prefix', $names, true), 'MySQL har e-postprefixet');
my(in_array('contacts_phone_prefix', $names, true), 'MySQL har telefonprefixet');
$pdo->exec("INSERT INTO contacts (id, row_json, owner_phone, created_at, updated_at) VALUES ('n1', '{}', '+46701230000', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$explain = $pdo->query("EXPLAIN SELECT id FROM contacts WHERE owner_phone >= '+4670123' AND owner_phone < '+4670124'")->fetchAll();
$used = '';
foreach ($explain as $row) {
    $used .= ' ' . (string) ($row['key'] ?? $row['Key'] ?? '');
}
my(str_contains($used, 'contacts_phone_prefix'), 'EXPLAIN använder telefonprefixet:' . $used);
echo 'perf mysql explain key ' . trim($used) . "\n";

echo "db mysql nightly ok\n";
