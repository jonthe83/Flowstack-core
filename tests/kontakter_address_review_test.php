<?php

declare(strict_types=1);

/**
 * Adressnyckel och dubblettregler med påhittade adresser.
 * Lägenhet stannar i nyckeln. Saknat postnummer utan samma ort granskas, slås inte ihop.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-review-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $hint */
function kr_put(array $hint): array
{
    $row = jeab_kontakt_blank($hint);
    jeab_kontakter_mutate(static function (array &$contacts) use ($row): array {
        $contacts[(string) $row['id']] = $row;
        return $row;
    });
    return $row;
}

kr(jeab_kontakt_street_key('Norrskensvägen 12a') === 'norrskensvagen 12a', 'gata utan lägenhet behåller nyckeln');
kr(jeab_kontakt_street_key('Norrskensvägen 12a') === jeab_kontakt_street_key('Norrskensvägen 12 a'), '12a och 12 a är samma nyckel');
kr(jeab_kontakt_street_key('Husvägen 5 lgh 1101') === 'husvagen 5 lgh 1101', 'lgh-numret stannar i nyckeln');
kr(jeab_kontakt_street_key('Husvägen 5 lägenhet 1101') === 'husvagen 5 lgh 1101', 'lägenhet normaliseras till lgh');
kr(jeab_kontakt_street_key('Husvägen 5 lgh 1101') !== jeab_kontakt_street_key('Husvägen 5 lgh 1102'), 'olika lgh är olika nycklar');
kr(jeab_kontakt_street_key('Husvägen 5 lgh 1101') !== jeab_kontakt_street_key('Husvägen 5'), 'lgh är inte samma som huset utan lägenhet');
kr(jeab_kontakt_street_key('Trapphusvägen 4, 3 tr') === 'trapphusvagen 4 tr 3', 'trappa stannar i nyckeln');
kr(jeab_kontakt_street_key('Trapphusvägen 4 tr 2') === 'trapphusvagen 4 tr 2', 'tr med nummer stannar i nyckeln');
kr(jeab_kontakt_street_key('Trapphusvägen 4 tr 2') !== jeab_kontakt_street_key('Trapphusvägen 4, 3 tr'), 'olika trappor är olika nycklar');
kr(jeab_kontakt_street_key('Våningsgatan 8 vån 3') === 'vaningsgatan 8 van 3', 'vån stannar i nyckeln');
kr(jeab_kontakt_street_key('Bottenvägen 1 nb') === 'bottenvagen 1 nb', 'nb stannar i nyckeln');

$lghA = kr_put([
    'name' => 'Lina Exempel',
    'email' => 'lina.lgh1101@example.test',
    'phone' => '070-8100001',
    'street' => 'Fiktivvägen 12 lgh 1101',
    'street_key' => 'fiktivvagen 12',
    'postal_code' => '11122',
    'city' => 'Nordstad',
]);
$lghB = kr_put([
    'name' => 'Lars Exempel',
    'email' => 'lars.lgh1102@example.test',
    'phone' => '070-8100002',
    'street' => 'Fiktivvägen 12 lgh 1102',
    'street_key' => 'fiktivvagen 12',
    'postal_code' => '11122',
    'city' => 'Nordstad',
]);
$townA = kr_put([
    'name' => 'Nora Exempel',
    'email' => 'nora.nord@example.test',
    'phone' => '070-8100003',
    'street' => 'Skiljevägen 4',
    'street_key' => jeab_kontakt_street_key('Skiljevägen 4'),
    'postal_code' => '11122',
    'city' => 'Nordstad',
]);
$townB = kr_put([
    'name' => 'Syd Exempel',
    'email' => 'syd.syd@example.test',
    'phone' => '070-8100004',
    'street' => 'Skiljevägen 4',
    'street_key' => jeab_kontakt_street_key('Skiljevägen 4'),
    'postal_code' => '22233',
    'city' => 'Sydstad',
]);
$sameA = kr_put([
    'name' => 'Sam Exempel',
    'email' => 'sam.med.post@example.test',
    'phone' => '070-8100005',
    'street' => 'Ortvägen 9',
    'street_key' => jeab_kontakt_street_key('Ortvägen 9'),
    'postal_code' => '33344',
    'city' => 'Granskningsby',
]);
$sameB = kr_put([
    'name' => 'Samira Exempel',
    'email' => 'samira.utan.post@example.test',
    'phone' => '070-8100006',
    'street' => 'Ortvägen 9',
    'street_key' => jeab_kontakt_street_key('Ortvägen 9'),
    'postal_code' => '',
    'city' => 'Granskningsby',
]);
$reviewA = kr_put([
    'name' => 'Rut Exempel',
    'email' => 'rut.med.post@example.test',
    'phone' => '070-8100007',
    'street' => 'Granskgatan 6',
    'street_key' => jeab_kontakt_street_key('Granskgatan 6'),
    'postal_code' => '44455',
    'city' => 'Reviewstad',
]);
$reviewB = kr_put([
    'name' => 'Rolf Exempel',
    'email' => 'rolf.utan.ort@example.test',
    'phone' => '070-8100008',
    'street' => 'Granskgatan 6',
    'street_key' => jeab_kontakt_street_key('Granskgatan 6'),
    'postal_code' => '',
    'city' => '',
]);

$loaded = jeab_kontakter_load();
kr(jeab_kontakt_find($loaded, jeab_kontakt_street_key('Fiktivvägen 12 lgh 1101'), '11122', '', '', true, '', 'Nordstad') === (string) $lghA['id'], 'samma lgh hittas');
kr(jeab_kontakt_find($loaded, jeab_kontakt_street_key('Fiktivvägen 12 lgh 1102'), '11122', '', '', true, '', 'Nordstad') === (string) $lghB['id'], 'andra lgh hittas för sig');
kr(jeab_kontakt_find($loaded, jeab_kontakt_street_key('Fiktivvägen 12 lgh 1101'), '', '', '', true, '', '') === null, 'saknat postnummer och saknad ort hittar inte lgh');
kr(jeab_kontakt_find([(string) $townA['id'] => $townA], jeab_kontakt_street_key('Skiljevägen 4'), '22233', '', '', true, '', 'Sydstad') === null, 'samma gata och nummer i annan ort är ingen träff');
kr(jeab_kontakt_find([(string) $townA['id'] => $townA], jeab_kontakt_street_key('Skiljevägen 4'), '', '', '', true, '', 'Sydstad') === null, 'saknat postnummer och annan ort är ingen träff');
kr(jeab_kontakt_find($loaded, jeab_kontakt_street_key('Skiljevägen 4'), '', '', '', true, '', 'Nordstad') === (string) $townA['id'], 'samma gata och samma ort träffar även utan sökt postnummer');
kr(jeab_kontakt_find([(string) $sameB['id'] => $sameB], jeab_kontakt_street_key('Ortvägen 9'), '', '', '', true, '', 'Granskningsby') === (string) $sameB['id'], 'saknat postnummer och samma ort är en träff');
kr(jeab_kontakt_find($loaded, jeab_kontakt_street_key('Granskgatan 6'), '', '', '', true, '', '') === null, 'saknat postnummer utan ort är ingen träff');
kr(jeab_kontakt_find($loaded, jeab_kontakt_street_key('Granskgatan 6'), '44455', '', '', true, '', '') === (string) $reviewA['id'], 'samma postnummer träffar även när sökorten saknas');

$before = hash('sha256', (string) file_get_contents(jeab_kontakter_path()));
$dry = jeab_kontakter_dedupe([]);
kr(($dry['dry'] ?? false) === true && hash('sha256', (string) file_get_contents(jeab_kontakter_path())) === $before, 'torrkörningen skriver inget');
kr(array_key_exists('needs_review', $dry) && array_key_exists('needs_review', (array) ($dry['counts'] ?? [])), 'torrkörningen har kategorin needs review');
kr((int) ($dry['counts']['needs_review'] ?? -1) === count((array) ($dry['needs_review'] ?? [])), 'needs review räknas');

$mergePairs = [];
foreach ((array) ($dry['merges'] ?? []) as $merge) {
    $mergePairs[(string) ($merge['from'] ?? '')] = true;
    $mergePairs[(string) ($merge['to'] ?? '')] = true;
}
$reviewIds = [];
foreach ((array) ($dry['needs_review'] ?? []) as $row) {
    foreach ((array) ($row['ids'] ?? []) as $id) {
        $reviewIds[(string) $id] = (string) ($row['reason'] ?? '');
    }
    kr((string) ($row['rule'] ?? '') === 'address' && (string) ($row['reason'] ?? '') !== '' && count((array) ($row['ids'] ?? [])) >= 2, 'needs review har id och orsak');
}
kr(!isset($mergePairs[(string) $lghA['id']]) && !isset($mergePairs[(string) $lghB['id']]), 'samma gata med olika lgh slås inte ihop');
kr(!isset($mergePairs[(string) $townA['id']]) && !isset($mergePairs[(string) $townB['id']]), 'samma gata och nummer i olika orter slås inte ihop');
kr(!isset($reviewIds[(string) $townA['id']]) && !isset($reviewIds[(string) $townB['id']]), 'olika ort med postnummer är inte needs review');
kr(isset($mergePairs[(string) $sameA['id']]) && isset($mergePairs[(string) $sameB['id']]), 'saknat postnummer och samma ort slås ihop');
kr(!isset($reviewIds[(string) $sameA['id']]) && !isset($reviewIds[(string) $sameB['id']]), 'samma ort är inte needs review');
kr(!isset($mergePairs[(string) $reviewA['id']]) && !isset($mergePairs[(string) $reviewB['id']]), 'saknat postnummer utan ort slås inte ihop');
kr(isset($reviewIds[(string) $reviewA['id']]) && isset($reviewIds[(string) $reviewB['id']]) && $reviewIds[(string) $reviewB['id']] === 'saknar postnummer och ort', 'saknat postnummer utan ort listas som needs review');

$blocked = jeab_kontakter_dedupe(['dry' => 0]);
kr(($blocked['ok'] ?? true) === false && str_contains((string) ($blocked['error'] ?? ''), 'allow_mass=1') && isset(jeab_kontakter_load()[(string) $sameA['id']]) && isset(jeab_kontakter_load()[(string) $sameB['id']]), 'dry=0 utan allow_mass stoppar hopslagningen och lämnar båda');
$wet = jeab_kontakter_dedupe(['dry' => 0, 'allow_mass' => 1]);
kr(($wet['dry'] ?? true) === false && (int) ($wet['counts']['needs_review'] ?? 0) >= 1, 'dry=0 behåller needs review');
$after = jeab_kontakter_load();
kr(isset($after[(string) $lghA['id']]) && isset($after[(string) $lghB['id']]), 'olika lgh finns kvar efter dry=0');
kr(isset($after[(string) $townA['id']]) && isset($after[(string) $townB['id']]), 'olika orter finns kvar efter dry=0');
kr(isset($after[(string) $reviewA['id']]) && isset($after[(string) $reviewB['id']]), 'needs review finns kvar efter dry=0');
kr(isset($after[(string) $sameA['id']]) !== isset($after[(string) $sameB['id']]), 'samma ort blev en kontakt');

$item = static function (string $id, string $street, string $postal, string $city): array {
    return [
        'kind' => 'reminder',
        'id' => $id,
        'name' => 'Fiktiv ' . $id,
        'email' => $id . '@example.test',
        'phone' => '',
        'street' => $street,
        'street_key' => jeab_kontakt_street_key($street),
        'postal' => $postal,
        'postal_invalid' => false,
        'city' => $city,
    ];
};
$split = jeab_kontakt_group_sources([
    $item('a', 'Skiljegatan 1', '11122', 'Ort'),
    $item('b', 'Skiljegatan 1', '11133', 'Ort'),
]);
$splitKeys = [];
foreach ($split as $group) {
    $splitKeys[(string) ($group['key'] ?? '')] = true;
}
kr(isset($splitKeys['skiljegatan 1|11122']) && isset($splitKeys['skiljegatan 1|11133']), 'olika postnummer på samma gata stannar isär');

$joined = jeab_kontakt_group_sources([
    $item('c', 'Ortvägen 9', '33344', 'Granskningsby'),
    $item('d', 'Ortvägen 9', '', 'Granskningsby'),
]);
kr(count($joined) === 1 && (string) ($joined[0]['key'] ?? '') === 'ortvagen 9|33344', 'saknat postnummer och samma ort läggs i samma grupp');

$held = jeab_kontakt_group_sources([
    $item('e', 'Granskgatan 6', '44455', 'Reviewstad'),
    $item('f', 'Granskgatan 6', '', ''),
]);
$heldKeys = [];
foreach ($held as $group) {
    $heldKeys[(string) ($group['key'] ?? '')] = true;
}
kr(isset($heldKeys['granskgatan 6|44455']) && !isset($heldKeys['granskgatan 6|']) && count($held) === 2, 'saknat postnummer utan ort slås inte ihop med postnumret');
$alone = jeab_kontakt_group_sources([
    $item('i', 'Ensamgatan 2', '', ''),
    $item('j', 'Ensamgatan 2', '', ''),
]);
kr(count($alone) === 2, 'två adresser utan postnummer och ort blir inte en grupp');

$apartments = jeab_kontakt_group_sources([
    $item('g', 'Fiktivvägen 12 lgh 1101', '11122', 'Nordstad'),
    $item('h', 'Fiktivvägen 12 lgh 1102', '11122', 'Nordstad'),
]);
kr(count($apartments) === 2, 'olika lgh blir inte en grupp');

echo "address review: ok\n";
