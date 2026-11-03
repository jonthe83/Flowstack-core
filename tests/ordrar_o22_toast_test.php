<?php

declare(strict_types=1);

/**
 * O22: toast «tiden bokad» har Ångra som avbokar. Påhittad order. Inget utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-o22-toast-' . bin2hex(random_bytes(4));
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

function o22_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$rem = jeab_create_reminder($store, 'Ada Toast', '2026-11-02 00:00:00', 'laddbox', null, [
    'customer' => 'Ada Toast',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1, Järfälla',
]);
$rid = (int) ($rem['id'] ?? 0);
o22_ok($rid > 0, 'påminnelsen finns');
jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Toast',
    'address' => 'Testgatan 1, Järfälla',
    'booking_id' => 4242,
    'fortnox_order' => '9003',
    'steps' => ['fatt_alla_uppgifter' => '2026-11-02 10:00:00'],
]);
$row = jeab_purchase_get($rid);
o22_ok(is_array($row) && (int) ($row['booking_id'] ?? 0) === 4242, 'ordern har bokning');

$html = jeab_ordrar_v3_html($row, 'jarfalla', $rid, $store, null, 'Ordern skapad och tiden bokad.');
o22_ok(str_contains($html, 'data-booking-id="4242"'), 'sidan bär booking-id');
o22_ok(str_contains($html, 'id="v3-f-avboka"') && str_contains($html, 'name="action" value="booking.cancel"'), 'Ångra postar booking.cancel');
o22_ok(str_contains($html, 'tiden bokad'), 'flash säger tiden bokad');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
o22_ok(str_contains($js, "btn.textContent = 'Ångra'") && str_contains($js, 'undoBookedTime') && str_contains($js, 'tiden bokad'), 'klienten ritar Ångra på tiden-bokad-toast');

echo "ordrar o22 toast: ok\n";
