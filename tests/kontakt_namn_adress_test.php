<?php

declare(strict_types=1);

/**
 * QA: e-post som namn, trasiga adresser och «namn saknas» i Kontakter.
 * Bara påhittade namn och gator.
 */

$tmp = sys_get_temp_dir() . '/jeab-kna-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
putenv('JEAB_CONFIG=' . $tmp . '/config.php');
require dirname(__DIR__) . '/src/bootstrap.php';

$fail = 0;
$eq = static function (string $label, $want, $got) use (&$fail): void {
    if ($want === $got) {
        echo "OK  $label\n";
        return;
    }
    $fail++;
    echo "FAIL $label: ville ha " . json_encode($want, JSON_UNESCAPED_UNICODE) . ', fick ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
};

$place = jeab_name_address_normalize_place('Testgatan 4, 19736 197 36, 197 36', '', '');
$eq('dubbla postnummer blir ingen ort', ['street' => 'Testgatan 4', 'postal' => '19736', 'city' => ''], $place);
$eq('dubbla postnummer i ortfältet', '', jeab_name_address_accept_city('197 36, 197 36', '19736'));
$eq('«och,» först tas bort', 'Provvägen 12', jeab_name_address_prepare_street('och, Provvägen 12'));
$eq('«och» utan komma tas bort', 'Provvägen 12', jeab_name_address_prepare_street('och Provvägen 12'));
$eq('Ochsvägen behålls', 'Ochsvägen 3', jeab_name_address_prepare_street('Ochsvägen 3'));
$eq('ort gissas inte ur 175xx', '', jeab_name_address_normalize_place('Låtsasvägen 9', '17556', '')['city']);
$eq('befintligt uppslag i koden används', 'Järfälla', jeab_name_address_normalize_place('Låtsasvägen 9', '17656', '')['city']);
$eq('okänt postnummer gissas inte', '', jeab_name_address_normalize_place('Låtsasvägen 9', '99999', '')['city']);
$eq('utskriven ort behålls', 'Hittepå', jeab_name_address_normalize_place('Låtsasvägen 9', '17556', 'Hittepå')['city']);

$broken = ['street' => 'och, Provvägen 12', 'postal_code' => '19736', 'city' => '197 36, 197 36', 'owners' => []];
$eq('visning: trasig rad', ['street' => 'Provvägen 12', 'city' => '197 36'], jeab_kontakt_display_place($broken));
$eq('visning: hel rad orörd', ['street' => 'Bokgatan 1', 'city' => 'Hittepå'],
    jeab_kontakt_display_place(['street' => 'Bokgatan 1', 'postal_code' => '99999', 'city' => 'Hittepå']));
$eq('visning: gata utan nummer visas ändå', 'Bokgatan', jeab_kontakt_display_place(['street' => 'Bokgatan', 'postal_code' => '', 'city' => ''])['street']);

$mail = ['owners' => [['name' => 'testa.testsson@example.test', 'contact' => true]], 'name_missing' => true];
$eq('e-post visas inte som namn', '', jeab_kontakt_list_name($mail));
$eq('e-post som namn: namn saknas', 'namn saknas', jeab_kontakt_list_marks($mail));
$mixed = ['owners' => [['name' => 'testa.testsson@example.test', 'contact' => true], ['name' => 'Testa Testsson']]];
$eq('riktigt namn före e-post', 'Testa Testsson', jeab_kontakt_list_name($mixed));
$named = ['owners' => [['name' => 'Testa Testsson', 'contact' => true]], 'name_missing' => true];
$eq('namn finns: ingen «namn saknas»', '', jeab_kontakt_list_marks($named));
$cat = jeab_kontakt_catalog_row('kt_x', $broken + ['name_missing' => true]);
$eq('katalograd: gata', 'Provvägen 12', $cat['street']);
$eq('katalograd: postnummer utan ort', '197 36', $cat['city']);
$eq('visning: radens egen ort', 'Hittepå', jeab_kontakt_display_place(['street' => 'Bokgatan 1', 'postal_code' => '19736', 'city' => 'Hittepå'])['city']);

if ($fail > 0) {
    exit(1);
}
echo "kontakt namn adress ok\n";
