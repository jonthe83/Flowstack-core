<?php

declare(strict_types=1);

/**
 * Solkalkylens vinst: 54 000 + 500 kr per panel över 10 + laddbox + TP2.
 * Föreslaget kundpris är det pris som ger den vinsten efter moms och grön teknik.
 */

$tmp = sys_get_temp_dir() . '/jeab-sol-profit-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

$calc = jeab_offert_calc([
    'b14' => 14,
    'laddare' => 1,
    'b15' => 0,
    'sigen_typ' => '',
]);
$profit = (int) round((float) $calc['sol_profit']);
$shown = (int) round((float) $calc['al20']);
$real = (int) round(((float) $calc['i15'] - (float) $calc['al21']) * 0.8);
if ($profit !== 59000 || $shown !== 59000 || $real !== 59000) {
    fwrite(STDERR, "FAIL 14 paneler + laddbox mål {$profit} visad {$shown} verklig {$real}\n");
    exit(1);
}
if (abs((float) $calc['c21'] - (float) $calc['c10']) > 0.0001 || (float) $calc['c21'] <= 0.0) {
    fwrite(STDERR, "FAIL 14 paneler marginal sol {$calc['c21']} batteri {$calc['c10']}\n");
    exit(1);
}
$ten = jeab_offert_calc([
    'b14' => 13,
    'b3' => 10,
    'sokande' => 2,
    'laddare' => 0,
    'sigen_typ' => 'tp2',
    'sigen_kw' => '12.0',
]);
$tenShown = (int) round((float) $ten['al20']);
$tenPrice = (int) round((float) $ten['i14']);
if ($tenShown !== 57000 || $tenPrice !== (int) round((float) $ten['c24'])) {
    fwrite(STDERR, "FAIL 13 paneler 10 batterier TP2 vinst {$tenShown} pris {$tenPrice}\n");
    exit(1);
}
if (abs((float) $ten['c21'] - (float) $ten['c10']) > 0.0001 || (float) $ten['c21'] <= 0.0) {
    fwrite(STDERR, "FAIL 10 batterier marginal sol {$ten['c21']} batteri {$ten['c10']}\n");
    exit(1);
}
echo "OK  14 paneler + laddbox ger vinst 59000 och positiv marginal\n";
echo "OK  13 paneler 10 batterier TP2 ger vinst 57000, pris {$tenPrice}\n";
