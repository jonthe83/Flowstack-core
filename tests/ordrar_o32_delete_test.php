<?php

declare(strict_types=1);

/**
 * O32: radera bekräftas i modalen och verkställs direkt (ingen 6 s Ångra-väntan). Påhittad order. Inget utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-o32-delete-' . bin2hex(random_bytes(4));
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

function o32_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$rem = jeab_create_reminder($store, 'Ada Radera', '2026-11-02 00:00:00', 'laddbox', null, [
    'customer' => 'Ada Radera',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1, Järfälla',
]);
$rid = (int) ($rem['id'] ?? 0);
o32_ok($rid > 0, 'påminnelsen finns');
jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Radera',
    'address' => 'Testgatan 1, Järfälla',
]);
$row = jeab_purchase_get($rid);
o32_ok(is_array($row), 'ordern finns');

$html = jeab_ordrar_v3_html($row, 'jarfalla', $rid, $store);
o32_ok(str_contains($html, 'Radera ärendet…') && str_contains($html, 'id="v3-f-delete"') && str_contains($html, 'name="action" value="ordrar.delete"'), 'radera ligger i menyn och postar ordrar.delete');
o32_ok(str_contains($html, 'id="v3-modal"') && str_contains($html, 'data-v3="modal-cancel"'), 'modalen har Avbryt');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
$start = strpos($js, 'radera: function');
$end = strpos($js, 'gamla: function', $start === false ? 0 : $start);
$body = $start !== false && $end !== false ? substr($js, $start, $end - $start) : '';
o32_ok($body !== '', 'radera-handlern finns');
o32_ok(!str_contains($body, 'Det går inte att ångra'), 'modalen säger inte att radering inte går att ångra');
o32_ok(!str_contains($js, 'Det går inte att ångra'), 'ingen raderingstext säger att det inte går att ångra');
o32_ok(str_contains($body, "toast('Raderar ärendet…', false)") && !str_contains($body, "toast('Radering ångrad.')"), 'radering visar status utan Ångra');
o32_ok(str_contains($body, 'commitPendingDelete();') && !str_contains($body, '6000'), 'radering verkställs direkt, ingen väntan');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar-v3.css');
o32_ok(str_contains($css, '.ov3.is-deleting') && str_contains($css, 'pointer-events: none'), 'sidan spärras under Ångra-fönstret');

echo "ordrar o32 delete: ok\n";
