<?php

declare(strict_types=1);

/**
 * Två skrivningar av olika fält på samma order tappar inget.
 * En läsväg (saknad-koll och backfill) skriver inte ordern.
 * Påhittad data. Inget skickas.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'worker') {
    $dir = (string) ($argv[2] ?? '');
    $field = (string) ($argv[3] ?? '');
    $value = (string) ($argv[4] ?? '');
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $dir;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require dirname(__DIR__) . '/src/bootstrap.php';
    usleep(200000);
    $pdo = jeab_purchase_sql_pdo();
    if (!$pdo instanceof PDO) {
        fwrite(STDERR, "no sql\n");
        exit(1);
    }
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => 42,
        $field => $value,
    ]);
    exit(0);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-merge-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$_SERVER['REQUEST_METHOD'] = 'GET';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ellevio_fullmakt.php';

function om(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_purchases_store_pdo();
om($pdo instanceof PDO, 'sqlite är öppen');
om(jeab_settings_put($pdo, 'sqlite', 'purchases_storage', 'sql'), 'orderflaggan går att sätta');
om(jeab_purchases_storage_mode() === 'sql', 'ordrar ligger i SQL');
jeab_ordrar_external_live(false);

jeab_purchase_put([
    'reminder_id' => 42,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Bas',
    'ui_note' => 'base',
    'fortnox_order' => '',
    'updated_at' => '2026-10-01 08:00:00',
]);

$procs = [];
$pipes = [];
foreach (['ui_note' => 'from-ui', 'fortnox_order' => 'F-99'] as $field => $value) {
    $workerPipes = [];
    $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $tmp, $field, $value], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $workerPipes);
    om(is_resource($proc), 'worker startade');
    fclose($workerPipes[0]);
    $procs[$field] = $proc;
    $pipes[$field] = $workerPipes;
}
foreach ($procs as $field => $proc) {
    $err = stream_get_contents($pipes[$field][2]);
    fclose($pipes[$field][1]);
    fclose($pipes[$field][2]);
    $code = proc_close($proc);
    om($code === 0, 'worker ' . $field . ' klar' . ($err !== '' && $err !== false ? ' ' . trim((string) $err) : ''));
}
$merged = jeab_sql_purchases_get($pdo, 42);
om(is_array($merged), 'ordern finns kvar');
om((string) ($merged['ui_note'] ?? '') === 'from-ui', 'UI-fältet finns kvar');
om((string) ($merged['fortnox_order'] ?? '') === 'F-99', 'Fortnox-fältet finns kvar');
om((string) ($merged['kind'] ?? '') === 'laddbox' && (string) ($merged['name'] ?? '') === 'Bas', 'orörda fält finns kvar');
om(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/db.php'), 'FOR UPDATE'), 'orderlås är SELECT FOR UPDATE');

jeab_purchase_put([
    'reminder_id' => 77,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'huvudsokande' => 'Test Person',
    'fastighetsbeteckning' => 'TESTEN 1:1',
    'address' => 'Testgatan 1, 183 30 Täby',
    'postnummer' => '',
    'updated_at' => '2026-01-01 00:00:00',
]);
$before = (string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 77')->fetchColumn();
$stored = jeab_purchase_get(77);
om(is_array($stored), 'läsordern finns');
$filled = jeab_ordrar_backfill_lookup($stored);
om((string) ($filled['postnummer'] ?? '') !== '', 'uppslaget fyller postnummer i minnet');
jeab_ordrar_fortnox_missing($stored);
$after = (string) $pdo->query('SELECT payload FROM purchases WHERE reminder_id = 77')->fetchColumn();
om($after === $before && !str_contains($after, '18330'), 'läsvägen skriver inte ordern');

$web = (string) file_get_contents(dirname(__DIR__) . '/src/webshop.php');
$wix = (string) file_get_contents(dirname(__DIR__) . '/src/webshop_wix.php');
$fortnox = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar_fortnox.php');
om(str_contains($web, 'jeab_ordrar_backfill_lookup($p, null, false)'), 'webbshopens förkoll sparar inte');
om(str_contains($wix, 'jeab_ordrar_backfill_lookup($p, null, false)'), 'wix-förkollen sparar inte');
om(str_contains($fortnox, 'jeab_ordrar_backfill_lookup($row, null, false)'), 'Fortnox-saknadkollen sparar inte');

echo "ok\n";
