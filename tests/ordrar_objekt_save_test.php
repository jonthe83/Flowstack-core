<?php

declare(strict_types=1);

/**
 * Status-objekt: adress, postort, mobil, e-post och nätägare sparas på ordern.
 */

$tmp = sys_get_temp_dir() . '/jeab-objekt-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function obj_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

jeab_ordrar_external_live(false);

$base = [
    'reminder_id' => 4242,
    'kind' => 'laddbox',
    'company' => 'jarfalla',
    'name' => 'Guy Nilsson',
    'huvudsokande' => 'Guy Nilsson',
    'personnummer' => '19800101-1234',
    'fastighetsbeteckning' => 'GRÖDINGE 1:1',
    'address' => 'Sibblevägen 8, 147 92 Grödinge',
    'huvudsokande_address' => 'Sibblevägen 8, 147 92 Grödinge',
    'natbolag' => 'Ellevio',
    'phone' => '+46701234567',
    'email' => 'guy@example.com',
    'fortnox_order' => '2489173',
    'steps' => [],
];
jeab_purchase_put($base);

$saved = jeab_ordrar_save_objekt([
    'id' => 4242,
    'objekt_adress' => 'Nya vägen 3',
    'objekt_postort' => '145 50 Norsborg',
    'phone' => '070-111 22 33',
    'email' => 'ny@example.com',
    'natbolag' => 'E.ON',
]);
obj_assert(($saved['address'] ?? '') === 'Nya vägen 3, 145 50 Norsborg', 'adress och postort skrivs ihop');
obj_assert(($saved['huvudsokande_address'] ?? '') === 'Nya vägen 3, 145 50 Norsborg', 'huvudsökande-adress följer med');
obj_assert(($saved['postnr'] ?? '') === '145 50', 'postnummer sparas');
obj_assert(($saved['ort'] ?? '') === 'Norsborg', 'postort sparas');
obj_assert(($saved['phone'] ?? '') === '+46701112233', 'mobil normaliseras');
obj_assert(($saved['email'] ?? '') === 'ny@example.com', 'e-post sparas');
obj_assert(($saved['natbolag'] ?? '') === 'E.ON', 'nätägare sparas');
obj_assert(trim((string) ($saved['objekt_saved_at'] ?? '')) !== '', 'sparning stämplas');
obj_assert(($saved['applicant']['phone'] ?? '') === '+46701112233', 'sökande-mobil följer med');
obj_assert(($saved['applicant']['email'] ?? '') === 'ny@example.com', 'sökande-e-post följer med');

$again = jeab_purchase_get(4242);
obj_assert(is_array($again), 'ordern finns kvar');
obj_assert(($again['phone'] ?? '') === '+46701112233', 'mobil ligger kvar efter omläsning');
obj_assert(($again['email'] ?? '') === 'ny@example.com', 'e-post ligger kvar efter omläsning');
obj_assert(($again['natbolag'] ?? '') === 'E.ON', 'nätägare ligger kvar efter omläsning');

$html = jeab_ordrar_status_html($again, 'jarfalla', 4242, null);
foreach (['Nya vägen 3', 'name="objekt_postnummer"', 'value="145 50"', 'value="Norsborg"', '+46701112233', 'ny@example.com', 'E.ON', '>Postnummer<'] as $needle) {
    obj_assert(str_contains($html, $needle), 'statuskort visar ' . $needle);
}
obj_assert(str_contains($html, 'selected'), 'vald nätägare är markerad');

$cleared = jeab_ordrar_save_objekt([
    'id' => 4242,
    'objekt_adress' => 'Nya vägen 3',
    'objekt_postort' => 'Grödinge',
    'phone' => '',
    'email' => '',
    'natbolag' => 'Vattenfall',
]);
obj_assert(($cleared['phone'] ?? 'x') === '', 'tömd mobil stannar tom');
obj_assert(($cleared['email'] ?? 'x') === '', 'tömd e-post stannar tom');
obj_assert(($cleared['ort'] ?? '') === 'Grödinge', 'postort utan postnummer sparas');
obj_assert(($cleared['postnr'] ?? 'x') === '', 'gammalt postnummer töms');
obj_assert(($cleared['natbolag'] ?? '') === 'Vattenfall', 'nätägare kan bytas igen');
$clearHtml = jeab_ordrar_status_html($cleared, 'jarfalla', 4242, null);
obj_assert(str_contains($clearHtml, 'value=""'), 'tomma fält ritas ut');
obj_assert(!str_contains($clearHtml, 'ny@example.com'), 'gammal e-post syns inte efter tömning');
obj_assert(!str_contains($clearHtml, '+46701112233'), 'gammal mobil syns inte efter tömning');

$kept = jeab_ordrar_save_objekt([
    'id' => 4242,
    'objekt_adress' => 'Nya vägen 3',
    'objekt_postort' => 'Grödinge',
    'phone' => '',
    'email' => '',
    'natbolag' => 'Inte ett bolag',
]);
obj_assert(($kept['natbolag'] ?? '') === 'Vattenfall', 'ogiltig nätägare skrivs inte över');

$natOnly = jeab_ordrar_save_objekt([
    'id' => 4242,
    'natbolag' => 'E.ON',
]);
obj_assert(($natOnly['natbolag'] ?? '') === 'E.ON', 'nätägare ensam post sparas');
obj_assert(($natOnly['address'] ?? '') === 'Nya vägen 3, Grödinge', 'adress ligger kvar när bara nätägare postas');
obj_assert(($natOnly['phone'] ?? 'x') === '', 'mobil rörs inte när bara nätägare postas');

$threw = false;
try {
    jeab_ordrar_save_objekt([
        'id' => 4242,
        'objekt_adress' => 'Nya vägen 3',
        'objekt_postort' => 'Grödinge',
        'phone' => '070-111 22 33',
        'email' => 'inte-en-adress',
        'natbolag' => 'Vattenfall',
    ]);
} catch (InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'e-post');
}
obj_assert($threw, 'ogiltig e-post avvisas');
$afterEmail = jeab_purchase_get(4242);
obj_assert(($afterEmail['email'] ?? 'x') === '', 'avvisad e-post sparas inte');

$threwPhone = false;
try {
    jeab_ordrar_save_objekt([
        'id' => 4242,
        'objekt_adress' => 'Nya vägen 3',
        'objekt_postort' => 'Grödinge',
        'phone' => '12345',
        'email' => 'ok@example.com',
        'natbolag' => 'Vattenfall',
    ]);
} catch (InvalidArgumentException $e) {
    $threwPhone = str_contains($e->getMessage(), 'mobil');
}
obj_assert($threwPhone, 'ogiltig mobil avvisas');

obj_assert(jeab_ordrar_keep_status_stop('ordrar.objekt', 'bekrafta') === 'status', 'sparning stannar på status');
obj_assert(jeab_ordrar_keep_status_stop('ordrar.spara', 'bekrafta') === 'bekrafta', 'andra sparningar behåller sitt stop');
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
obj_assert(str_contains($index, "case 'ordrar.objekt':"), 'index tar emot ordrar.objekt');
obj_assert(str_contains($index, 'jeab_ordrar_keep_status_stop'), 'index skickar tillbaka till status');

echo "ordrar objekt save: ok\n";
