<?php

declare(strict_types=1);

/**
 * N-9: Kontakter sorterar Å/Ä/Ö sist på MySQL. Påhittade gator.
 * Anslutningen kör utf8mb4_swedish_ci.
 */

$mariadb = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
$mariadbd = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
if ($mariadb === '' || $mariadbd === '') {
    echo "SKIP MariaDB saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-n9-sort-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$port = 33000 + random_int(0, 2000);
$data = $tmp . '/db';
$sock = $tmp . '/mysql.sock';
$pidFile = $tmp . '/mysql.pid';
$init = $tmp . '/init.sql';
$log = $tmp . '/mysql.log';
file_put_contents($init, <<<SQL
CREATE DATABASE IF NOT EXISTS jeab CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'jeab'@'127.0.0.1' IDENTIFIED BY 'jeabpass';
GRANT ALL PRIVILEGES ON jeab.* TO 'jeab'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL);
$install = sprintf(
    'mariadb-install-db --datadir=%s --auth-root-authentication-method=normal --skip-test-db >%s 2>&1',
    escapeshellarg($data),
    escapeshellarg($log)
);
exec($install, $unused, $installCode);
if ($installCode !== 0) {
    fwrite(STDERR, "FAIL MariaDB kunde inte initieras\n");
    exit(1);
}
$cmd = sprintf(
    'exec mariadbd --datadir=%s --bind-address=127.0.0.1 --port=%d --socket=%s --pid-file=%s --init-file=%s',
    escapeshellarg($data),
    $port,
    escapeshellarg($sock),
    escapeshellarg($pidFile),
    escapeshellarg($init)
);
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL MariaDB startade inte\n");
    exit(1);
}
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    try {
        new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass', [PDO::ATTR_TIMEOUT => 1]);
        $up = true;
        break;
    } catch (Throwable) {
        usleep(200000);
    }
}
if (!$up) {
    fwrite(STDERR, "FAIL MariaDB svarade inte\n" . (string) file_get_contents($log) . "\n");
    exit(1);
}

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
putenv('CONTACTS_SOLE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'mysql',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => [
            'host' => '127.0.0.1;port=' . $port,
            'name' => 'jeab',
            'user' => 'jeab',
            'pass' => 'jeabpass',
            'charset' => 'utf8mb4',
        ],
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

$setup = new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass');
$setup->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = require JEAB_ROOT . '/schema/mysql.php';
$schema = preg_replace('/^\s*--.*$/m', '', is_string($schema) ? $schema : '');
foreach (array_filter(array_map('trim', explode(';', (string) $schema))) as $stmt) {
    if ($stmt !== '') {
        $setup->exec($stmt);
    }
}
unset($setup);
function n9(bool $ok, string $m): void
{
    if ($ok) {
        echo "OK  {$m}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$m}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_mysql();
n9(strcasecmp((string) $pdo->query('SELECT @@collation_connection')->fetchColumn(), 'utf8mb4_swedish_ci') === 0, 'anslutningen har utf8mb4_swedish_ci');
$cols = $pdo->query('SHOW COLUMNS FROM contacts')->fetchAll(PDO::FETCH_COLUMN);
$streets = ['n9a' => 'Östragatan 1', 'n9b' => 'Bokgatan 2', 'n9c' => 'Ängsvägen 3', 'n9d' => 'Zetavägen 4', 'n9e' => 'Åsgatan 5', 'n9f' => 'Almvägen 6'];
foreach ($streets as $id => $street) {
    $row = ['id' => $id, 'street' => $street, 'postal_code' => '12345', 'city' => 'Teststad', 'owner_name_1' => 'Test Testsson'];
    $vals = [
        'id' => $id,
        'row_json' => json_encode($row, JSON_UNESCAPED_UNICODE),
        'street_key' => mb_strtolower($street),
        'postal_code' => '12345',
        'owner_email' => '',
        'owner_phone' => '',
        'owner_name_fold' => 'test testsson',
        'company' => '',
        'city_fold' => 'teststad',
        'sort_key' => jeab_kontakt_sort_key($street),
        'sort_city' => jeab_kontakt_sort_key('Teststad'),
        'list_ready' => 1,
        'created_at' => '2026-10-24 12:00:00',
        'updated_at' => '2026-10-24 12:00:00',
    ];
    $vals = array_intersect_key($vals, array_flip($cols));
    $stmt = $pdo->prepare('INSERT INTO contacts (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')');
    $stmt->execute(array_values($vals));
}
$naive = $pdo->query('SELECT id FROM contacts ORDER BY sort_key, id')->fetchAll(PDO::FETCH_COLUMN);
echo 'utan COLLATE: ' . implode(',', $naive) . "\n";
$page = (new JeabPdoContactStore($pdo))->page('', 'alla', null, 50);
$order = array_values(array_map('strval', array_keys($page['rows'])));
n9($order === ['n9f', 'n9b', 'n9d', 'n9e', 'n9c', 'n9a'], 'Kontakter: A, B, Z, Å, Ä, Ö (fick ' . implode(',', $order) . ')');
echo "n9 sortering ok\n";
