<?php

declare(strict_types=1);

/**
 * jeab_settings_value läser settings en gång per request och nyckel.
 * En skrivning i samma request syns direkt. Ett lås (busy) cachas inte.
 */

$root = dirname(__DIR__);
require $root . '/src/db.php';

function sc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

final class SettingsCountPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;

        return parent::prepare($query, $options);
    }
}

$pdo = new SettingsCountPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings ("key" TEXT PRIMARY KEY, value TEXT)');
$pdo->prepare('INSERT INTO settings ("key", value) VALUES (?, ?)')->execute(['biz_doc_storage', 'sql']);
$before = $pdo->prepares;

$first = jeab_settings_value($pdo, 'sqlite', 'biz_doc_storage');
$afterFirst = $pdo->prepares;
$second = jeab_settings_value($pdo, 'sqlite', 'biz_doc_storage');
sc($first === 'sql' && $second === 'sql', 'värdet läses');
sc($afterFirst === $before + 1 && $pdo->prepares === $afterFirst, 'andra läsningen använder cachen');

sc(jeab_settings_put($pdo, 'sqlite', 'biz_doc_storage', 'json'), 'skrivningen går igenom');
sc(jeab_settings_value($pdo, 'sqlite', 'biz_doc_storage') === 'json', 'skrivningen syns utan ny SELECT');

$pdo->prepare('UPDATE settings SET value = ? WHERE "key" = ?')->execute(['raw', 'biz_doc_storage']);
sc(jeab_settings_value($pdo, 'sqlite', 'biz_doc_storage') === 'json', 'cachen håller värdet tills den glöms');
jeab_settings_cache_forget($pdo, 'sqlite', 'biz_doc_storage');
sc(jeab_settings_value($pdo, 'sqlite', 'biz_doc_storage') === 'raw', 'forget ser den råa skrivningen');

$GLOBALS['_JEAB_SETTINGS_BUSY'] = true;
$busy = jeab_settings_read($pdo, 'sqlite', 'biz_doc_storage');
unset($GLOBALS['_JEAB_SETTINGS_BUSY']);
sc(($busy['busy'] ?? false) === true && ($busy['ok'] ?? true) === false, 'upptaget svarar busy');
sc(jeab_settings_value($pdo, 'sqlite', 'biz_doc_storage') === 'raw', 'busy cachas inte');

echo "ALLA OK\n";
