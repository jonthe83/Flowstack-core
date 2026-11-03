<?php

declare(strict_types=1);

/** Postnummer ska överleva SQLite/MySQL-sparningen. Kolumnen skapas som ort, utan schemafilen. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-postal-store-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ps(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

ps(jeab_driver() === 'sqlite', 'testet använder sqlite');
$store = jeab_store();
$row = jeab_create_reminder($store, 'Kund Postnr', '2026-10-14 09:00:00', null, null, [
    'customer' => 'Kund Postnr',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'address' => 'Exempelgatan 12, Lund',
    'ort' => 'Lund',
    'postal' => '23456',
]);
$again = jeab_get_reminder($store, (int) $row['id']);
ps((string) ($again['postal'] ?? '') === '23456' && (string) ($again['ort'] ?? '') === 'Lund', 'insert sparar postnummer och ort');
if (trim((string) ($again['contact_id'] ?? '')) !== '' && $store instanceof JeabPdoStore) {
    $store->pdo()->prepare('UPDATE reminders SET contact_id = NULL WHERE id = ?')->execute([(int) $again['id']]);
}
$updated = jeab_update_reminder($store, (int) $row['id'], ['postal' => '21122', 'ort' => 'Malmö', 'note' => 'kvar']);
ps((string) ($updated['postal'] ?? '') === '21122' && (string) ($updated['ort'] ?? '') === 'Malmö' && (string) ($updated['note'] ?? '') === 'kvar', 'update sparar postnummer och ort');
$cols = $store instanceof JeabPdoStore ? 'ok' : '';
$pdo = new PDO('sqlite:' . $tmp . '/app.sqlite');
$names = array_map(static fn (array $col): string => (string) ($col['name'] ?? ''), $pdo->query('PRAGMA table_info(reminders)')->fetchAll());
ps(in_array('postal', $names, true) && in_array('ort', $names, true), 'sqlite-kolumnen postal finns utan schemafilen');
unset($cols);

echo "reminder postal store ok\n";
