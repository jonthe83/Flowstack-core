<?php
declare(strict_types=1);
define('JEAB_ROOT', dirname(__DIR__));
require dirname(__DIR__) . '/src/idnr.php';
$cases = ["861201\u{2013}0143", "861201 \u{2014} 0143", "19861201\u{2011}0143", "8612010143", " 861201\u{2212}0143 "];
foreach ($cases as $i => $c) {
    if (jeab_pnr_normalize($c) !== '19861201-0143') { fwrite(STDERR, "FAIL pnr case $i\n"); exit(1); }
}
$dashes = [
    "\u{2013}", "\u{2014}", "\u{2212}", "\u{2010}", "\u{2011}", "\u{2012}", "\u{2015}",
    "\u{FE58}", "\u{FE63}", "\u{FF0D}", "\u{00AD}",
];
foreach ($dashes as $i => $dash) {
    $ten = '861201' . $dash . '0143';
    $twelve = '19861201' . $dash . '0143';
    if (jeab_pnr_normalize($ten) !== '19861201-0143' || jeab_pnr_normalize($twelve) !== '19861201-0143') {
        fwrite(STDERR, "FAIL dash $i\n");
        exit(1);
    }
    $org = '556677' . $dash . '8899';
    if (jeab_orgnr_normalize($org) !== '556677-8899' || str_contains(jeab_orgnr_normalize($org), $dash)) {
        fwrite(STDERR, "FAIL org dash $i\n");
        exit(1);
    }
}
$spaces = [' ', "\u{00A0}", "\u{202F}", "\u{2009}"];
foreach ($spaces as $i => $space) {
    $pnr = '19861201' . $space . '0143';
    $wrapped = $space . '861201' . $space . '-' . $space . '0143' . $space;
    if (jeab_pnr_normalize($pnr) !== '19861201-0143' || jeab_pnr_normalize($wrapped) !== '19861201-0143') {
        fwrite(STDERR, "FAIL space $i\n");
        exit(1);
    }
    $org = '556677' . $space . '8899';
    if (jeab_orgnr_normalize($org) !== '556677-8899') {
        fwrite(STDERR, "FAIL org space $i\n");
        exit(1);
    }
}
$b = jeab_fortnox_normalize_ids([
    'Order' => ['HouseWork' => [['SocialSecurityNumber' => "861201\u{2013}0143"]]],
    'Customer' => ['OrganisationNumber' => "556677 \u{2013} 8899"],
    'Invoice' => ['TaxReduction' => [['SocialSecurityNumber' => "19861201\u{00A0}\u{2014}0143"]]],
]);
$invoicePnr = $b['Invoice']['TaxReduction'][0]['SocialSecurityNumber'] ?? '';
if ($b['Order']['HouseWork'][0]['SocialSecurityNumber'] !== '19861201-0143' || $b['Customer']['OrganisationNumber'] !== '556677-8899' || $invoicePnr !== '19861201-0143') {
    fwrite(STDERR, "FAIL payload\n");
    exit(1);
}
$encoded = json_encode($b, JSON_UNESCAPED_UNICODE);
if (!is_string($encoded) || str_contains($encoded, "\u{2013}") || str_contains($encoded, "\u{2014}") || str_contains($encoded, "\u{00A0}")) {
    fwrite(STDERR, "FAIL payload hyphen\n");
    exit(1);
}
echo "ok\n";
$r = jeab_idnr_normalize_row(['personnummer' => "861201\u{2013}0143", 'medsokande' => [['name' => 'X', 'personnummer' => "19700101 \u{2014} 1234"]], 'orgnr' => "556677\u{2013}8899"]);
if ($r['personnummer'] !== '19861201-0143' || $r['medsokande'][0]['personnummer'] !== '19700101-1234' || $r['orgnr'] !== '556677-8899') { fwrite(STDERR, "FAIL row\n"); exit(1); }
echo "ok row\n";
