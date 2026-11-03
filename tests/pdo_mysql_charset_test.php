<?php

declare(strict_types=1);

/**
 * Tom charset i config blir utf8mb4. SET NAMES-fel loggas.
 * @@character_set_connection som inte är utf8mb4 varnar, kastar inte.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-pdo-charset-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$logDir = $tmp . '/fellogg';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    fwrite(STDERR, "FAIL logg\n");
    exit(1);
}
$err = $tmp . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $err);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $logDir;
$GLOBALS['_JEAB_PDO_RETRY_US'] = 0;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => [
            'host' => 'localhost',
            'name' => 'jeab',
            'user' => 'jeab',
            'pass' => '',
            'charset' => '',
        ],
    ],
], true) . ";\n");

require $root . '/src/bootstrap.php';

$fail = 0;
function cs_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

final class JeabCharsetProbePdo extends PDO
{
    public string $connectionCharset = 'utf8mb4';

    public bool $failNames = false;

    public bool $failCheck = false;

    /** @var list<string> */
    public array $execs = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function exec(string $statement): int|false
    {
        $this->execs[] = $statement;
        if (preg_match('/^\s*SET\s+NAMES\b/i', $statement) === 1) {
            if ($this->failNames) {
                throw new PDOException('SQLSTATE[42000]: Syntax error or access violation: SET NAMES rejected');
            }

            return 0;
        }

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, '@@character_set_connection')) {
            if ($this->failCheck) {
                throw new PDOException('SQLSTATE[HY000]: General error: cannot read character_set_connection');
            }
            parent::exec('DROP TABLE IF EXISTS _jeab_cs');
            parent::exec('CREATE TABLE _jeab_cs (v TEXT)');
            $ins = parent::prepare('INSERT INTO _jeab_cs (v) VALUES (?)');
            $ins->execute([$this->connectionCharset]);

            return parent::query('SELECT v FROM _jeab_cs');
        }
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function cs_logs(): array
{
    global $err, $logDir;
    $fellog = is_file($logDir . '/app-errors.jsonl') ? (string) file_get_contents($logDir . '/app-errors.jsonl') : '';
    $php = is_file($err) ? (string) file_get_contents($err) : '';

    return [$fellog, $php];
}

function cs_clear_logs(): void
{
    global $err, $logDir;
    @unlink($logDir . '/app-errors.jsonl');
    @unlink($err);
}

cs_ok(jeab_pdo_mysql_charset(null) === 'utf8mb4', 'null charset blir utf8mb4');
cs_ok(jeab_pdo_mysql_charset('') === 'utf8mb4', 'tom charset blir utf8mb4');
cs_ok(jeab_pdo_mysql_charset('   ') === 'utf8mb4', 'blank charset blir utf8mb4');
cs_ok(jeab_pdo_mysql_charset('utf8mb4') === 'utf8mb4', 'utf8mb4 lämnas kvar');
cs_ok(jeab_pdo_mysql_charset('latin1') === 'latin1', 'angiven charset lämnas kvar');

$src = (string) file_get_contents($root . '/src/db.php');
cs_ok(str_contains($src, 'jeab_pdo_mysql_charset($m[\'charset\'] ?? null)'), 'DSN tar charset via hjälparen, även tom sträng');
cs_ok(!preg_match('/\$m\[\'charset\'\]\s*\?\?\s*\'utf8mb4\'/', $src), 'DSN använder inte bara ?? utf8mb4');
cs_ok(str_contains($src, 'MYSQL_ATTR_INIT_COMMAND') && str_contains($src, 'jeab_pdo_mysql_names_sql()'), 'anslutningen sätter SET NAMES i INIT_COMMAND');
cs_ok(str_contains($src, 'ATTR_PERSISTENT => false'), 'ingen persistent anslutning');
$connectAt = strpos($src, 'function jeab_pdo_connect_mysql');
$connectEnd = $connectAt === false ? false : strpos($src, "\nfunction ", $connectAt + 10);
$connectFn = ($connectAt !== false && $connectEnd !== false) ? substr($src, $connectAt, $connectEnd - $connectAt) : '';
cs_ok($connectFn !== '' && !str_contains($connectFn, 'character_set_connection') && !str_contains($connectFn, 'jeab_pdo_mysql_warn_unless_utf8mb4'), 'ny anslutning läser inte @@character_set_connection');
cs_ok(!str_contains($src, 'jeab_pdo_mysql_warn_unless_utf8mb4($pdo)') && !str_contains($src, 'jeab_pdo_mysql_warn_unless_utf8mb4($fresh)'), 'återanslutning läser inte charset');
cs_ok(!preg_match('/exec\(jeab_pdo_mysql_names_sql\(\)\);\s*}\s*catch\s*\(Throwable\)\s*{\s*\/\//s', $src), 'SET NAMES har ingen tom catch');

$okPdo = new JeabCharsetProbePdo();
$okPdo->connectionCharset = 'utf8mb4';
cs_clear_logs();
$threw = false;
try {
    jeab_pdo_mysql_ensure_utf8mb4($okPdo);
} catch (Throwable) {
    $threw = true;
}
[$fellog, $php] = cs_logs();
cs_ok(!$threw, 'utf8mb4 kastar inte');
cs_ok($okPdo->execs === [], 'INIT_COMMAND gör att SET NAMES inte körs en gång till');
cs_ok($fellog === '' && !str_contains($php, 'SET NAMES') && !str_contains($php, 'character_set_connection'), 'utf8mb4 loggar inte');

$GLOBALS['_JEAB_PDO_NAMES_FORCE'] = 1;
$forced = new JeabCharsetProbePdo();
jeab_pdo_mysql_ensure_utf8mb4($forced);
cs_ok($forced->execs !== [] && str_contains($forced->execs[0], 'SET NAMES utf8mb4'), 'utan INIT_COMMAND körs SET NAMES');
unset($GLOBALS['_JEAB_PDO_NAMES_FORCE']);

$failPdo = new JeabCharsetProbePdo();
$failPdo->failNames = true;
$failPdo->connectionCharset = 'utf8mb4';
$GLOBALS['_JEAB_PDO_NAMES_FORCE'] = 1;
cs_clear_logs();
$threw = false;
try {
    jeab_pdo_mysql_ensure_utf8mb4($failPdo);
} catch (Throwable) {
    $threw = true;
}
[$fellog, $php] = cs_logs();
cs_ok(!$threw, 'SET NAMES-fel kastar inte');
cs_ok(str_contains($fellog, 'SET NAMES misslyckades') && str_contains($php, 'SET NAMES misslyckades'), 'SET NAMES-fel loggas i fellogg och error_log');
unset($GLOBALS['_JEAB_PDO_NAMES_FORCE']);

$latin = new JeabCharsetProbePdo();
$latin->connectionCharset = 'latin1';
cs_clear_logs();
$threw = false;
try {
    jeab_pdo_mysql_warn_unless_utf8mb4($latin);
} catch (Throwable) {
    $threw = true;
}
[$fellog, $php] = cs_logs();
cs_ok(!$threw, 'fel charset kastar inte');
cs_ok(str_contains($fellog, '@@character_set_connection=latin1') && str_contains($php, '@@character_set_connection=latin1'), 'fel charset loggas som varning');

$unread = new JeabCharsetProbePdo();
$unread->failCheck = true;
cs_clear_logs();
$threw = false;
try {
    jeab_pdo_mysql_warn_unless_utf8mb4($unread);
} catch (Throwable) {
    $threw = true;
}
[$fellog, $php] = cs_logs();
cs_ok(!$threw, 'oläsbar charset kastar inte');
cs_ok(str_contains($fellog, '@@character_set_connection kunde inte läsas') && str_contains($php, '@@character_set_connection kunde inte läsas'), 'oläsbar charset loggas');

if ($fail > 0) {
    fwrite(STDERR, "pdo_mysql_charset_test {$fail} fail\n");
    exit(1);
}
echo "pdo_mysql_charset_test ok\n";
