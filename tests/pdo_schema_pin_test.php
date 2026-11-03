<?php

declare(strict_types=1);

/**
 * Schema-id måste följa en pin av migrationsfilerna.
 * Ändras schema/sqlite.php eller schema/mysql.php faller testet
 * tills jeab_pdo_schema_migration_pin() byts. Id:t innehåller pin:en.
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

$fail = 0;
function pin_ok(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

$sqlite = (string) file_get_contents($root . '/schema/sqlite.php');
$mysql = (string) file_get_contents($root . '/schema/mysql.php');
$live = hash('sha256', $sqlite . "\n" . $mysql);
$pin = jeab_pdo_schema_migration_pin();
pin_ok($live === $pin, 'migrationspin matchar schema/sqlite.php och schema/mysql.php');
pin_ok(str_starts_with(jeab_pdo_schema_id('sqlite'), '20261023a-' . substr($pin, 0, 12) . '-sqlite'), 'sqlite-id innehåller pin:en');
pin_ok(str_starts_with(jeab_pdo_schema_id('mysql'), '20261023a-' . substr($pin, 0, 12) . '-mysql'), 'mysql-id innehåller pin:en');
pin_ok(jeab_pdo_schema_id('sqlite') !== '20261021d-sqlite', 'gammalt schema-id är bumpat');

exit($fail > 0 ? 1 : 0);
