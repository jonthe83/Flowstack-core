<?php

declare(strict_types=1);

/**
 * Samma utbox på MariaDB. Påhittade namn. Inget skickas.
 */

$mariadb = trim((string) shell_exec('command -v mariadb-install-db 2>/dev/null'));
$mariadbd = trim((string) shell_exec('command -v mariadbd 2>/dev/null'));
if ($mariadb === '' || $mariadbd === '') {
    echo "SKIP MariaDB saknas\n";
    exit(0);
}
if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP pdo_mysql saknas\n";
    exit(0);
}

$stopQuoteOutboxMaria = static function (string $needle): void {
    $ps = @shell_exec('ps -eo pid,args');
    if (!is_string($ps) || $ps === '') {
        return;
    }
    foreach ([15, 9] as $sig) {
        foreach (explode("\n", $ps) as $line) {
            if (!str_contains($line, 'mariadbd') || !str_contains($line, $needle)) {
                continue;
            }
            if (preg_match('/^\s*(\d+)/', $line, $m) !== 1) {
                continue;
            }
            $pid = (int) $m[1];
            if ($pid > 1) {
                exec('kill -' . $sig . ' ' . $pid . ' >/dev/null 2>&1');
            }
        }
        $ps = (string) @shell_exec('ps -eo pid,args');
        usleep(200000);
    }
};
$stopQuoteOutboxMaria('/tmp/jeab-quote-outbox-mysql-');

$tmp = sys_get_temp_dir() . '/jeab-quote-outbox-mysql-' . bin2hex(random_bytes(4));
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
    'mariadbd --datadir=%s --bind-address=127.0.0.1 --port=%d --socket=%s --pid-file=%s --init-file=%s',
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
register_shutdown_function(static function () use ($proc, $sock, $tmp, $pidFile, $stopQuoteOutboxMaria): void {
    $admin = trim((string) shell_exec('command -v mariadb-admin 2>/dev/null'));
    if ($admin !== '' && $sock !== '') {
        exec(sprintf(
            '%s --socket=%s -uroot --connect-timeout=5 shutdown >/dev/null 2>&1',
            escapeshellarg($admin),
            escapeshellarg($sock)
        ));
    }
    if (is_resource($proc)) {
        proc_terminate($proc, 15);
        $deadline = time() + 5;
        do {
            $st = proc_get_status($proc);
            if (empty($st['running'])) {
                break;
            }
            usleep(100000);
        } while (time() < $deadline);
        $st = proc_get_status($proc);
        if (!empty($st['running'])) {
            proc_terminate($proc, 9);
        }
        proc_close($proc);
    }
    $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;
    if ($pid > 1) {
        exec('kill -15 ' . $pid . ' >/dev/null 2>&1');
        exec('kill -9 ' . $pid . ' >/dev/null 2>&1');
    }
    $stopQuoteOutboxMaria($tmp);
});
$up = false;
for ($i = 0; $i < 150; $i++) {
    try {
        new PDO('mysql:host=127.0.0.1;port=' . $port . ';dbname=jeab;charset=utf8mb4', 'jeab', 'jeabpass', [PDO::ATTR_TIMEOUT => 1]);
        $up = true;
        break;
    } catch (Throwable) {
        usleep(200000);
    }
}
if (!$up) {
    fwrite(STDERR, "FAIL MariaDB svarade inte\n");
    exit(1);
}

$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
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
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qo(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$store = jeab_store();
qo($store instanceof JeabPdoStore && $store->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql', 'lagret är MariaDB');

require __DIR__ . '/quote_outbox_cases.inc';
