<?php

declare(strict_types=1);

/**
 * Kontakter-sidan: samma yta som Påminnelser, svensk sortering, bolagsfilter
 * och en sida i taget när listan växer. Bara påhittad data.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-ui-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ui(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ui_pnr(): string
{
    $nine = '800101123';
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $n = (int) $nine[$i];
        if ($i % 2 === 0) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
    }
    return '19800101-123' . ((10 - ($sum % 10)) % 10);
}

$pnr = ui_pnr();
ui(jeab_kontakt_pnr_mask($pnr) === '800101-****', 'masken är ÅÅMMDD-****');
ui(jeab_kontakt_sort_key('Åsgatan') > jeab_kontakt_sort_key('Zebra'), 'Å kommer efter Z');
ui(jeab_kontakt_sort_key('Ängsvägen') > jeab_kontakt_sort_key('Åsgatan'), 'Ä kommer efter Å');
ui(jeab_kontakt_sort_key('Östergatan') > jeab_kontakt_sort_key('Ängsvägen'), 'Ö kommer efter Ä');
ui(jeab_kontakt_sort_key('Gatan 2') < jeab_kontakt_sort_key('Gatan 10'), 'gatan 2 före gatan 10');

$store = jeab_store();
jeab_kontakter_mutate(function (array &$contacts): void {
    $rows = [
        'kt_zebra' => ['id' => 'kt_zebra', 'street' => 'Zebragatan 1', 'city' => 'Teststad', 'company' => 'jarfalla', 'owners' => [['name' => 'Zeb Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_as' => ['id' => 'kt_as', 'street' => 'Åsgatan 4', 'city' => 'Teststad', 'company' => 'jarfalla', 'owners' => [['name' => 'Åsa Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_ang' => ['id' => 'kt_ang', 'street' => 'Ängsvägen 2', 'city' => 'Teststad', 'company' => 'malmo', 'owners' => [['name' => 'Äng Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_ost' => ['id' => 'kt_ost', 'street' => 'Östergatan 9', 'city' => 'Teststad', 'company' => 'jarfalla', 'owners' => [['name' => 'Östen Test', 'contact' => true, 'personnummer' => '', 'email' => 'osten@example.com', 'phone' => '0701111111']]],
        'kt_vasa' => ['id' => 'kt_vasa', 'street' => 'Vasagatan 3', 'city' => 'Teststad', 'company' => 'jarfalla', 'owners' => [['name' => 'Vasa Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_g2' => ['id' => 'kt_g2', 'street' => 'Gatan 2', 'city' => 'Teststad', 'company' => 'jarfalla', 'owners' => [['name' => 'Två Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_g10' => ['id' => 'kt_g10', 'street' => 'Gatan 10', 'city' => 'Teststad', 'company' => 'jarfalla', 'owners' => [['name' => 'Tio Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_tom' => ['id' => 'kt_tom', 'street' => '', 'city' => '', 'company' => 'jarfalla', 'owners' => [['name' => 'Utan Gata', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
        'kt_fri' => ['id' => 'kt_fri', 'street' => 'Frigatan 1', 'city' => 'Fristad', 'owners' => [['name' => 'Fri Test', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']]],
    ];
    foreach ($rows as $id => $row) {
        $contacts[$id] = $row;
    }
});

$sorted = array_keys(jeab_kontakter_sorted(''));
$want = ['kt_fri', 'kt_g2', 'kt_g10', 'kt_vasa', 'kt_zebra', 'kt_as', 'kt_ang', 'kt_ost', 'kt_tom'];
ui($sorted === $want, 'A–Ö på gata, utan adress sist, fick ' . implode(',', $sorted));

$jarfalla = jeab_kontakter_view($store, '', 'alla', 'jarfalla', 50);
ui(!isset($jarfalla['rows']['kt_ang']), 'Malmö-kontakten syns inte i Järfälla');
ui(isset($jarfalla['rows']['kt_vasa']) && isset($jarfalla['rows']['kt_fri']), 'Järfälla och okopplad kontakt syns');
$malmo = jeab_kontakter_view($store, '', 'alla', 'malmo', 50);
ui(isset($malmo['rows']['kt_ang']) && !isset($malmo['rows']['kt_vasa']), 'Malmö visar sin kontakt och inte Järfällas');
ui(isset($malmo['rows']['kt_fri']), 'kontakt utan bolag syns i båda');

$missing = jeab_kontakter_view($store, '', 'saknas', 'jarfalla', 50);
ui(array_keys($missing['rows']) === ['kt_tom'], 'Saknar adress är bara rader utan gata');
ui($missing['counts']['saknas'] === 1 && $missing['counts']['adress'] >= 1, 'chips räknar med och utan adress');
$none = jeab_kontakter_view($store, 'finns inte alls', 'alla', 'jarfalla', 50);
ui($none['total'] === 0 && $none['rows'] === [], 'sök utan träff ger tom lista');

$saved = jeab_kontakt_save_input([
    'street' => 'Spargatan 8',
    'postal_code' => '12345',
    'city' => 'Sparstad',
    'owner_name_1' => 'Spar Test',
    'owner_pnr_1' => $pnr,
    'contact_person' => '1',
    'email' => 'spar@example.com',
    'phone' => '0701234567',
    'ovrigt' => 'Portkod 1234, påhittat.',
    'company' => 'jarfalla',
]);
$again = jeab_kontakt_save_input([
    'id' => (string) $saved['id'],
    'street' => 'Spargatan 8',
    'postal_code' => '12345',
    'city' => 'Sparstad',
    'owner_name_1' => 'Spar Test',
    'owner_pnr_1' => '800101-****',
    'contact_person' => '1',
    'email' => 'spar@example.com',
    'phone' => '0701234567',
    'ovrigt' => 'Portkod 1234, påhittat.',
    'company' => 'malmo',
]);
ui((string) ($again['owners'][0]['personnummer'] ?? '') === (string) $saved['owners'][0]['personnummer'], 'masken med stjärnor skriver inte över numret');
ui((string) ($again['company'] ?? '') === 'jarfalla', 'sparning byter inte bolag');
ui(jeab_kontakt_ovrigt($again) === 'Portkod 1234, påhittat.', 'Övrigt följer med');
jeab_kontakter_mutate(function (array &$contacts) use ($saved): void {
    $id = (string) $saved['id'];
    unset($contacts[$id]['ovrigt']);
    $contacts[$id]['note'] = 'Anteckning från den andra grenen.';
});
ui(jeab_kontakt_ovrigt(jeab_kontakt_get((string) $saved['id']) ?? []) === 'Anteckning från den andra grenen.', 'Övrigt kan läsas från note');

$error = null;
$flash = null;
$_GET = ['p' => 'kontakter', 'id' => (string) $saved['id'], 'co' => 'jarfalla'];
$html = require dirname(__DIR__) . '/src/pages/kontakter.php';
ui(is_string($html), 'sidan renderas');
$digits = preg_replace('/\D+/', '', $pnr) ?? '';
ui(!str_contains($html, $pnr) && !str_contains($html, $digits), 'hela personnumret finns inte i HTML');
ui(str_contains($html, '800101-****') && str_contains($html, '>Visa<') && str_contains($html, 'kontakter.pnr'), 'Visa och masken ÅÅMMDD-****');
ui(str_contains($html, 'MINI-CRM') && str_contains($html, 'Kontakter') && str_contains($html, '+ Ny kontakt'), 'rubrik som Påminnelser och mint-pillen');
ui(str_contains($html, 'chip-primary crm-add') && str_contains($html, 'class="btn"') && !str_contains($html, '#0A84FF'), 'Spara är appens knapp, inte systemblå');
ui(str_contains($html, 'Med adress') && str_contains($html, 'Saknar adress') && str_contains($html, 'data-kt-q'), 'chips och sök');
ui(str_contains($html, 'mailto:spar@example.com') && str_contains($html, 'href="tel:+46701234567"'), 'e-post och telefon är länkar');
ui(str_contains($html, '>Övrigt<') && str_contains($html, 'name="ovrigt"'), 'eget kort för Övrigt');
ui(str_contains($html, 'Ta bort kontakt') && str_contains($html, "confirm('Ta bort kontakten?')"), 'borttagning frågar först');
ui(str_contains($html, '← Tillbaka') && str_contains($html, 'kt-save'), 'mobil har Tillbaka och Spara');
ui(str_contains($html, 'Anteckning från den andra grenen.'), 'Övrigt-kortet visar den fria texten');
ui(!str_contains($html, 'adresser') && !str_contains($html, 'Inga kontakter ännu'), 'ordvalet är kontakter');
ui(!empty($GLOBALS['_JEAB_KONTAKT_DETAIL']), 'öppen kontakt markerar detaljvyn');

$_GET = ['p' => 'kontakter', 'q' => 'zzz-ingen', 'co' => 'jarfalla'];
$miss = require dirname(__DIR__) . '/src/pages/kontakter.php';
ui(str_contains($miss, 'Inga träffar för &quot;zzz-ingen&quot;'), 'tom sökning säger träffar, inte tom lista');

$_GET = ['p' => 'kontakter', 'id' => 'kt_tom', 'co' => 'jarfalla'];
$bare = require dirname(__DIR__) . '/src/pages/kontakter.php';
ui(str_contains($bare, '<em class="item-note">Adress saknas</em>'), 'saknad adress är kursiv');
ui(str_contains($html, '>Inkorg<') && str_contains($html, '>Påminnelse<') && str_contains($html, '>Kalender<') && str_contains($html, '>Order<') && str_contains($html, '>Fullmakt<') && str_contains($html, '>Inga<'), 'tomma moduler säger Inga');

$bulk = [];
for ($i = 0; $i < 2000; $i++) {
    $id = 'kt_bulk_' . $i;
    $bulk[$id] = [
        'id' => $id,
        'street' => sprintf('Bulkgränd %d', $i + 1),
        'city' => 'Bulkstad',
        'company' => 'jarfalla',
        'owners' => [['name' => 'Bulk ' . $i, 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']],
    ];
}
$bulk['kt_sist'] = [
    'id' => 'kt_sist',
    'street' => '',
    'city' => '',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Östen Sist', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']],
];
jeab_kontakter_mutate(function (array &$contacts) use ($bulk): void {
    foreach ($bulk as $id => $row) {
        $contacts[$id] = $row;
    }
});
$t0 = hrtime(true);
$big = jeab_kontakter_view($store, '', 'alla', 'jarfalla', JEAB_KONTAKT_PAGE);
$ms = (hrtime(true) - $t0) / 1e6;
ui($ms < 500, '2000 kontakter filtreras och sorteras under 500 ms, tog ' . (int) $ms);
ui(count($big['rows']) === JEAB_KONTAKT_PAGE && $big['total'] > 2000 && $big['next'] === JEAB_KONTAKT_PAGE, 'första sidan är ' . JEAB_KONTAKT_PAGE . ' rader');
ui(($big['catalog'][count($big['catalog']) - 1]['id'] ?? '') === 'kt_sist', 'utan adress ligger sist i katalogen');
$_GET = ['p' => 'kontakter', 'co' => 'jarfalla'];
$t1 = hrtime(true);
$page = require dirname(__DIR__) . '/src/pages/kontakter.php';
$pageMs = (hrtime(true) - $t1) / 1e6;
ui($pageMs < 1500, 'sidan med 2000 kontakter renderas under 1,5 s, tog ' . (int) $pageMs);
ui(substr_count($page, 'data-id="') === JEAB_KONTAKT_PAGE, 'HTML innehåller en sida, inte hela listan');
ui(!str_contains($page, '>Östen Sist<'), 'raden utan adress ligger inte på första sidan');
ui(str_contains($page, 'Visa fler'), 'fler rader hämtas med Visa fler');

jeab_kontakt_delete((string) $saved['id']);
ui(jeab_kontakt_get((string) $saved['id']) === null, 'Ta bort kontakt tar bort raden');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
ui(str_contains($css, 'page-kontakter .apple-search input::placeholder') && str_contains($css, 'color: var(--text-secondary)'), 'sökfältets platshållare använder den ljusa sekundärfärgen');

echo "kontakter ui ok\n";
