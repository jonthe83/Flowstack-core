<?php

declare(strict_types=1);

/**
 * O26: produktbyte byter steglista på plats, utan reloadTo.
 * Påhittad order. Inget utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-o26-kind-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar_v3.php';
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
jeab_ordrar_external_live(false);

function o26_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
$start = strpos($js, 'function persistKind');
$end = strpos($js, 'function pick', $start === false ? 0 : $start);
$body = $start !== false && $end !== false ? substr($js, $start, $end - $start) : '';
o26_ok($body !== '', 'persistKind finns');
o26_ok(str_contains($js, 'function paintNavForKind') && str_contains($js, 'function applyKindSteps') && str_contains($js, 'function applyKindDom'), 'produktbyte ritar om menyn på plats');
o26_ok(str_contains($body, 'paintNavForKind(kind)') && str_contains($body, 'applyKindSteps'), 'produktbyte anropar steglistan efter sparning');
o26_ok(!str_contains($body, 'reloadTo('), 'produktbyte kör inte reloadTo för att byta steglista');
o26_ok(str_contains($js, "menu.replaceWith(newMenu)") && str_contains($js, "querySelector('#v3-menu')"), 'steglistan byts i menyn utan att riva sidan');

$store = jeab_store();
$rem = jeab_create_reminder($store, 'Ada Kind', '2026-11-02 00:00:00', 'laddbox', null, [
    'customer' => 'Ada Kind',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1, Järfälla',
]);
$rid = (int) ($rem['id'] ?? 0);
o26_ok($rid > 0, 'påminnelsen finns');
jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Kind',
    'address' => 'Testgatan 1, Järfälla',
    'steps' => ['fatt_alla_uppgifter' => '2026-11-02 10:00:00'],
]);
$row = jeab_purchase_get($rid);
o26_ok(is_array($row), 'ordern finns');

$ladd = jeab_ordrar_v3_html($row, 'jarfalla', $rid, $store);
o26_ok(str_contains($ladd, 'id="v3-menu"') && str_contains($ladd, 'data-nav="bekrafta"'), 'laddbox har meny');
$batRow = $row;
$batRow['kind'] = 'batteri';
$bat = jeab_ordrar_v3_html($batRow, 'jarfalla', $rid, $store);
o26_ok(str_contains($bat, 'data-nav="foranmalan"') && str_contains($bat, 'data-nav="bestall"'), 'batteri-menyn har Föranmälan och Beställ');

echo "ordrar o26 kind: ok\n";
