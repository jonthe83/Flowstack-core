<?php

declare(strict_types=1);

/**
 * Orderrader visar 10 rader och växer med 5. En mall med 13 rader visas som 14.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-rows-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function rows_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$empty = jeab_ordrar_inkop_pad([]);
rows_assert(count($empty) === 10, 'tom order visar 10 rader, fick ' . count($empty));

$three = jeab_ordrar_inkop_from_input([
    'line_sku' => ['A', 'B', 'C'],
    'line_title' => ['Ett', 'Två', 'Tre'],
    'line_qty' => ['1', '0', ''],
]);
rows_assert(count($three) === 10, 'tre ifyllda rader fylls ut till 10, fick ' . count($three));
rows_assert($three[1]['qty'] === 0, 'explicit 0 ligger kvar');

$filled = [];
for ($i = 0; $i < 13; $i++) {
    $filled[] = ['sku' => 'S' . $i, 'title' => 'Rad ' . $i, 'qty' => 1];
}
$shown = jeab_ordrar_inkop_pad($filled);
rows_assert(count($shown) === 13, '13 ifyllda rader visas, fick ' . count($shown));
rows_assert(jeab_ordrar_inkop_mall_row_count(13) === 14, 'mall med 13 rader visas som 14');
rows_assert(jeab_ordrar_inkop_mall_row_count(0) === 1, 'tom mall får en tom rad');

$posted = [
    'line_sku' => array_map(static fn (int $i): string => 'S' . $i, range(0, 14)),
    'line_title' => array_map(static fn (int $i): string => 'Rad ' . $i, range(0, 14)),
    'line_qty' => array_fill(0, 15, '1'),
];
$saved = jeab_ordrar_inkop_from_input($posted);
rows_assert(count($saved) === 15, '15 postade rader sparas, fick ' . count($saved));

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
rows_assert(str_contains($page, 'data-js="ordrar-lines-more"'), 'knappen för fler rader finns');
rows_assert(str_contains($page, 'Lägg till 5 rader'), 'knappen säger lägg till 5 rader');
$rowsJs = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
rows_assert(str_contains($rowsJs, "mode === 'mall'"), 'mall-läge finns i skriptet');
rows_assert(str_contains($rowsJs, 'source.length + 1'), 'mall visar en rad mer än mallen');

echo "OK\n";
