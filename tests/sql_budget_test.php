<?php

declare(strict_types=1);

/**
 * T5: en tom påminnelselista har ett tak på antalet SQL-frågor.
 * Taket är ett tak, inte en exakt siffra. Ingen kunddata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sqlbudget-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function budget(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
jeab_sql_stats_reset();
$list = jeab_reminders_load_company($store, 'jarfalla', '');
$first = jeab_sql_stats();
budget(is_array($list), 'tom lista går att läsa');
budget($first['n'] >= 1, 'läsningen räknas, fick ' . $first['n']);
budget($first['n'] <= 20, 'tom lista håller sig under 20 frågor, fick ' . $first['n']);

jeab_sql_stats_reset();
jeab_reminders_load_company($store, 'jarfalla', '');
$second = jeab_sql_stats();
budget($second['n'] <= $first['n'], 'andra läsningen frågar inte fler gånger, ' . $second['n'] . ' mot ' . $first['n']);
budget($second['n'] <= 20, 'andra läsningen håller taket, fick ' . $second['n']);

echo "OK  sql budget " . $first['n'] . "/" . $second['n'] . "\n";
