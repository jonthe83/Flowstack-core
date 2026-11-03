<?php

declare(strict_types=1);

/**
 * Backfill-undo i JSON-läget, när SQL inte är öppet.
 * Påhittade namn. Inget personnummer i utskriften.
 */

$tmp = sys_get_temp_dir() . '/jeab-snapshots-json-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
mkdir($private . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
mkdir($GLOBALS['_JEAB_PUBLIC_DATA_DIR'], 0775, true);
putenv('DATA_BACKEND=json');
$_ENV['DATA_BACKEND'] = 'json';
$GLOBALS['_JEAB_DATA_BACKEND'] = 'json';
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'mysql',
        'mysql' => [
            'host' => 'localhost',
            'name' => '',
            'user' => '',
            'pass' => '',
            'charset' => 'utf8mb4',
        ],
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function json_undo_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

json_undo_ok(jeab_driver() === 'json', 'köpen ligger i JSON-läget');
json_undo_ok(jeab_purchase_sql_live() === null, 'SQL är inte öppet');
json_undo_ok(!jeab_purchase_sql_required(), 'JSON-läget kräver inte SQL');

$batch = '20200303030303-c3d4e5';
$invoice = [
    'name' => 'Borttagen Kopia',
    'address' => 'Jsonvagen 1',
    'email' => 'json.kopia@example.test',
    'phone' => '0701000291',
    'owners' => [],
    'at' => '2026-03-03T10:00:00+01:00',
    'source' => 'backfill',
    'batch' => $batch,
];
$fullmakt = [
    'name' => 'Kvar Fullmakt',
    'address' => 'Kvarvagen 2',
    'email' => 'kvar.fullmakt@example.test',
    'phone' => '0701000292',
    'owners' => [],
    'at' => '2026-03-03T10:00:00+01:00',
    'source' => 'send',
];
jeab_purchase_put([
    'reminder_id' => 88091,
    'company' => 'jarfalla',
    'fortnox_order' => '88091',
    'name' => 'Json Undo',
    'email' => 'json.undo@example.test',
    'phone' => '0701000291',
    'address' => 'Jsonvagen 1',
    'snapshots' => ['invoice' => $invoice],
]);
jeab_purchase_put([
    'reminder_id' => 88092,
    'company' => 'jarfalla',
    'fortnox_order' => '88092',
    'name' => 'Json Tva',
    'email' => 'json.tva@example.test',
    'phone' => '0701000292',
    'address' => 'Jsonvagen 2',
    'snapshots' => [
        'invoice' => $invoice,
        'fullmakt' => $fullmakt,
    ],
]);
$dir = jeab_data_dir() . '/snapshots-backfill';
mkdir($dir, 0775, true);
file_put_contents($dir . '/' . $batch . '.json', json_encode([
    'batch_id' => $batch,
    'rows' => [
        ['store' => 'purchase', 'id' => '88091', 'type' => 'invoice', 'before' => null],
        ['store' => 'purchase', 'id' => '88092', 'type' => 'invoice', 'before' => null],
    ],
], JSON_UNESCAPED_UNICODE));
$undo = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'undo' => '1', 'batch_id' => $batch]);
json_undo_ok(($undo['ok'] ?? false) === true && (int) ($undo['failed'] ?? 1) === 0 && (int) ($undo['undone'] ?? 0) === 2, 'undo i JSON-läget tar bort påfyllnaden');
$only = jeab_purchase_get(88091);
json_undo_ok(is_array($only) && !jeab_snapshots_has_local($only, 'invoice'), 'enda kopian är borta');
json_undo_ok(is_array($only) && !array_key_exists('snapshots', $only), 'tom snapshot-nyckel skrivs inte tillbaka');
$kept = jeab_purchase_get(88092);
$keptFull = jeab_snapshots_read(is_array($kept) ? $kept : [], 'fullmakt');
json_undo_ok(is_array($kept) && !jeab_snapshots_has_local($kept, 'invoice'), 'fakturakopian tas bort');
json_undo_ok(is_array($keptFull) && ($keptFull['name'] ?? '') === 'Kvar Fullmakt', 'den andra kopian ligger kvar');
$raw = (string) file_get_contents(jeab_purchase_path());
json_undo_ok(!str_contains($raw, 'Borttagen Kopia') && !str_contains($raw, 'json.kopia@example.test'), 'JSON-filen har inte kvar den borttagna kopian');
json_undo_ok(str_contains($raw, 'Kvar Fullmakt'), 'JSON-filen har kvar den andra kopian');

echo "OK  snapshots json undo\n";
