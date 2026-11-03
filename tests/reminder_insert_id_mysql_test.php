<?php

declare(strict_types=1);

/**
 * Skapa påminnelse från Kontakter gav "Något gick fel." (TypeError:
 * insertReminder() fick null). I MySQL nollar nästa sats lastInsertId, och
 * räknecachens scope körde SELECT DATABASE() mellan INSERT och lastInsertId.
 * Testet härmar MySQL på SQLite: varje sats efter INSERT nollar id:t.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-insid-' . bin2hex(random_bytes(4));
mkdir($tmp . '/privat', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'people' => [
        ['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
    ],
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require_once $root . '/src/reminders.php';
$_SERVER['REQUEST_METHOD'] = 'POST';

function ins(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

/** PDO som beter sig som MySQL: en ny sats efter INSERT ger lastInsertId 0. */
final class InsMysqlLikePdo extends PDO
{
    public bool $stale = false;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->stale = true;

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->stale ? '0' : parent::lastInsertId($name);
    }
}

final class InsMysqlLikeStmt extends PDOStatement
{
    protected function __construct(private InsMysqlLikePdo $owner)
    {
    }

    public function execute(?array $params = null): bool
    {
        $ok = parent::execute($params);
        $this->owner->stale = !preg_match('/^\s*INSERT\b/i', $this->queryString);

        return $ok;
    }
}

$base = jeab_store();
ins($base instanceof JeabPdoStore, 'SQL-butik (schema skapat)');
$seedAt = jeab_now();
$base->insertReminder(['title' => 'frö', 'company' => 'jarfalla', 'created_at' => $seedAt, 'updated_at' => $seedAt]);
$pdo = new InsMysqlLikePdo('sqlite:' . $tmp . '/app.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InsMysqlLikeStmt::class, [$pdo]]);
$store = new JeabPdoStore($pdo);
unset($GLOBALS['_JEAB_REMINDER_COUNT_SCOPE']);

$now = jeab_now();
try {
    $row = $store->insertReminder([
        'title' => 'Solceller',
        'company' => 'jarfalla',
        'customer_name' => 'Test Kontakt',
        'assignee' => 'Sam L',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
} catch (Throwable $e) {
    ins(false, 'insertReminder kastade: ' . get_class($e) . ': ' . $e->getMessage());
}
ins(is_array($row) && (int) ($row['id'] ?? 0) > 0 && (string) ($row['title'] ?? '') === 'Solceller', 'insertReminder ger tillbaka raden även när nästa sats nollar id:t');
$n = (int) $base->pdo()->query("SELECT COUNT(*) FROM reminders WHERE title = 'Solceller'")->fetchColumn();
ins($n === 1, 'exakt en rad skapad');

echo "OK  reminder-insert-id\n";
