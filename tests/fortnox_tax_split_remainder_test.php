<?php

declare(strict_types=1);

/**
 * H-FX-2: skattereduktionens rest går till sista personen via
 * greenTechSplitsFromTypes även när splitAmong skiljer sig från antalet
 * behöriga. Andelar utan behörig person postas inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-fx-taxsplit-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function fxts_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

const FXTS_T = 'STORAGESELFPRODUCEDELECTRICITY';

$posted = [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$posted): array {
    if ($method === 'POST' && $path === '/taxreductions') {
        $row = is_array($body['TaxReduction'] ?? null) ? $body['TaxReduction'] : [];
        $posted[] = $row;
        return ['TaxReduction' => $row];
    }
    return [];
};

$api = new FortnoxAPI('Jarfalla');
$bases = [FXTS_T => 202.0];
$perType = FortnoxAPI::greenTechPossibleByType($bases);
fxts_assert(($perType[FXTS_T] ?? 0) === 101, '50 % av 202 kr är 101 kr');
$splits = FortnoxAPI::greenTechSplitsFromTypes($perType, 2);
$first = (int) ($splits[0][0]['AskedAmount'] ?? 0);
$second = (int) ($splits[1][0]['AskedAmount'] ?? 0);
fxts_assert($first + $second === 101 && $second === $first + 1, 'resten ligger på sista personen');

$posted = [];
$created = $api->createTaxReductions(
    [
        ['name' => 'Ada Test', 'personnummer' => '198001011234'],
        ['name' => 'Bo Test', 'personnummer' => '198002021234'],
    ],
    'TEST 1:1',
    '9001',
    $bases,
    2
);
fxts_assert($created === 2 && count($posted) === 2, 'två behöriga får två ansökningar');
fxts_assert((int) ($posted[0]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0) === $first, 'första personen får kvoten');
fxts_assert((int) ($posted[1]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0) === $second, 'andra personen får kvoten plus resten');
fxts_assert(
    (int) ($posted[0]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0)
    + (int) ($posted[1]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0) === 101,
    'hela 101 kr postas när personantalet stämmer'
);

$posted = [];
$oddOne = $api->createTaxReductions(
    [['name' => 'Ada Test', 'personnummer' => '198001011234']],
    'TEST 1:1',
    '9002',
    $bases,
    1
);
fxts_assert($oddOne === 1 && count($posted) === 1, 'en person får en ansökan');
fxts_assert((int) ($posted[0]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0) === 101, 'en person får hela beloppet inklusive rest');

$posted = [];
$subset = $api->createTaxReductions(
    [['name' => 'Ada Test', 'personnummer' => '198001011234']],
    'TEST 1:1',
    '9003',
    $bases,
    2
);
fxts_assert($subset === 1 && count($posted) === 1, 'utan andra behöriga postas bara en andel');
fxts_assert((int) ($posted[0]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0) === $first, 'andelen är splits[0], inte intdiv utan rest på fel person');
fxts_assert((string) ($posted[0]['CustomerName'] ?? '') === 'Ada Test', 'ingen extra person hittas på');

$posted = [];
$extra = $api->createTaxReductions(
    [
        ['name' => 'Ada Test', 'personnummer' => '198001011234'],
        ['name' => 'Bo Test', 'personnummer' => '198002021234'],
        ['name' => 'Cecilia Test', 'personnummer' => '198003031234'],
    ],
    'TEST 1:1',
    '9004',
    $bases,
    2
);
fxts_assert($extra === 2 && count($posted) === 2, 'tredje personen utan tilldelad andel postas inte');
$extraSum = (int) ($posted[0]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0)
    + (int) ($posted[1]['TaxReductionAmounts'][0]['AskedAmount'] ?? 0);
fxts_assert($extraSum === 101, 'bara de två tilldelade andelarna postas, med rest');
fxts_assert((string) ($posted[0]['CustomerName'] ?? '') === 'Ada Test' && (string) ($posted[1]['CustomerName'] ?? '') === 'Bo Test', 'Cecilia får ingen andel som inte tilldelats');

echo "fortnox tax split remainder tests done\n";
