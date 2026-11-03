<?php

declare(strict_types=1);

/**
 * Förvald nätägare utan EI-karta.
 * Järfälla 175–177 (order #71, 176 74) är E.ON. Hässelby/Lidingö är Ellevio.
 * Grödinge 147 är Vattenfall. Okänd adress ger inget grönt förslag.
 * Ett sparat val ligger kvar.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

function nat_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function nat_row(array $extra): array
{
    return array_replace([
        'kind' => 'sol',
        'company' => 'jarfalla',
        'name' => 'Adolfsson',
        'huvudsokande' => 'Adolfsson',
        'personnummer' => '197001011234',
        'fastighetsbeteckning' => 'HÄSSELBY 47:1',
        'natbolag' => '',
        'steps' => [],
    ], $extra);
}

jeab_ordrar_external_live(false);

$hasselby = nat_row([
    'address' => 'Hässelby Strandväg 47, Hässelby',
    'huvudsokande_address' => 'Hässelby Strandväg 47, Hässelby',
    'natbolag' => '',
]);
nat_assert(jeab_ordrar_natbolag_address_fallback($hasselby) === 'Ellevio', 'Hässelby Strandväg 47 faller tillbaka till Ellevio');
nat_assert(jeab_ordrar_natbolag($hasselby) === 'Ellevio', 'tomt fält i Hässelby föreslår Ellevio');
$hasselbySaved = $hasselby;
$hasselbySaved['natbolag'] = 'Vattenfall';
nat_assert(jeab_ordrar_natbolag($hasselbySaved) === 'Vattenfall', 'sparat Vattenfall i Hässelby ligger kvar');

$postOnly = nat_row([
    'address' => 'Strandväg 47',
    'huvudsokande_address' => 'Strandväg 47',
    'postnr' => '165 65',
    'ort' => 'Stockholm',
]);
nat_assert(jeab_ordrar_natbolag($postOnly) === 'Ellevio', 'postnummer 165 är Ellevio även utan ortnamnet Hässelby');

$ascii = nat_row([
    'huvudsokande_address' => 'Hasselby Strandvag 47, Hasselby',
]);
nat_assert(jeab_ordrar_natbolag($ascii) === 'Ellevio', 'Hässelby utan diakriter är Ellevio');

$lidingo = nat_row([
    'huvudsokande_address' => 'Breviksvägen 1, Lidingö',
    'postnr' => '181 31',
]);
nat_assert(jeab_ordrar_natbolag($lidingo) === 'Ellevio', 'Lidingö är fortfarande Ellevio');

$barkarby = nat_row([
    'huvudsokande_address' => 'Barkarbyvägen 1, Barkarby',
]);
nat_assert(jeab_ordrar_natbolag($barkarby) === 'E.ON', 'Barkarby är fortfarande E.ON');

$house = nat_row([
    'huvudsokande_address' => 'Enköpingsvägen 165, Järfälla',
]);
nat_assert(jeab_ordrar_natbolag($house) === 'E.ON', 'Järfälla utan postnummer är E.ON');
nat_assert(jeab_ordrar_natbolag_from_postnummer('165') === '', 'husnummer 165 är inte ett postnummer');

$houseOnly = nat_row([
    'huvudsokande_address' => 'Enköpingsvägen 165',
    'company' => '',
]);
nat_assert(jeab_ordrar_natbolag($houseOnly) !== 'Ellevio', 'husnummer 165 är inte postnummer 165');
nat_assert(jeab_ordrar_natbolag($houseOnly) === '', 'gata och husnummer utan känt område ger inget förslag');

$grodinge = nat_row([
    'huvudsokande_address' => 'Sibblevägen 8, 147 92 Grödinge',
]);
nat_assert(jeab_ordrar_natbolag($grodinge) === 'Vattenfall', '147 92 Grödinge är Vattenfall');

$krokus = nat_row([
    'name' => 'Karlsson',
    'huvudsokande' => 'Karlsson',
    'huvudsokande_address' => 'Krokusvägen 10',
    'address' => 'Krokusvägen 10',
    'postnr' => '17674',
    'ort' => 'Järfälla',
    'natbolag' => '',
]);
nat_assert(jeab_ordrar_natbolag_postnummer_digits($krokus) === '17674', '17674 läses från postnummerfältet');
nat_assert(jeab_ordrar_natbolag_from_postnummer('17674') === 'E.ON', 'prefix 176 är E.ON');
nat_assert(jeab_ordrar_natbolag_address_fallback($krokus) === 'E.ON', 'Krokusvägen 10, 176 74 faller tillbaka till E.ON');
nat_assert(jeab_ordrar_natbolag($krokus) === 'E.ON', 'order #71 föreslår E.ON');

$krokusLine = nat_row([
    'huvudsokande_address' => 'KROKUSVÄGEN 10, 17674 JÄRFÄLLA',
    'natbolag' => '',
]);
nat_assert(jeab_ordrar_natbolag($krokusLine) === 'E.ON', '17674 i adressraden är E.ON även utan eget postnummerfält');

$unknown = nat_row([
    'huvudsokande_address' => 'Storgatan 1',
    'ort' => 'Okändort',
    'natbolag' => '',
]);
nat_assert(jeab_ordrar_natbolag($unknown) === '', 'okänd adress ger inget säkert förslag');
nat_assert(jeab_ordrar_natbolag_address_fallback($unknown) === '', 'okänd adress faller inte tillbaka till Vattenfall');

$html = jeab_ordrar_spec_html($hasselby, 'jarfalla', 9001, null);
nat_assert(str_contains($html, 'Hässelby Strandväg 47, Hässelby'), 'föranmälan visar leveransadressen');
nat_assert(str_contains($html, 'value="Ellevio" checked'), 'Ellevio är förvald i nätbolagsvalet');
nat_assert(!str_contains($html, 'value="Vattenfall" checked'), 'Vattenfall är inte förvald för Hässelby');
nat_assert(str_contains($html, 'value="Vattenfall"'), 'Vattenfall går fortfarande att välja');

$krokusHtml = jeab_ordrar_spec_html($krokus, 'jarfalla', 71, null);
nat_assert(str_contains($krokusHtml, 'value="E.ON" checked'), '176 74 har E.ON förvalt');
nat_assert(!str_contains($krokusHtml, 'value="Vattenfall" checked'), '176 74 har inte Vattenfall förvalt');
nat_assert(!str_contains($krokusHtml, 'Ingen säker träff'), 'säker träff visar ingen osäkerhetstext');
nat_assert(str_contains($krokusHtml, 'value="Ellevio"'), 'Ellevio går att välja för 176 74');

$krokusStatus = jeab_ordrar_status_html($krokus, 'jarfalla', 71, null);
nat_assert(str_contains($krokusStatus, 'Nätägare'), 'status visar nätägare');
nat_assert(str_contains($krokusStatus, 'value="E.ON" selected'), 'status föreslår E.ON för 176 74');

$override = $krokus;
$override['natbolag'] = 'Vattenfall';
$overrideHtml = jeab_ordrar_spec_html($override, 'jarfalla', 71, null);
nat_assert(str_contains($overrideHtml, 'value="Vattenfall" checked'), 'sparat Vattenfall är förvalt på följdfrågor');
nat_assert(!str_contains($overrideHtml, 'value="E.ON" checked'), 'sparat Vattenfall skrivs inte över av postnumret');
$overrideStatus = jeab_ordrar_status_html($override, 'jarfalla', 71, null);
nat_assert(str_contains($overrideStatus, 'value="Vattenfall" selected'), 'sparat Vattenfall är valt på status');

$unknownHtml = jeab_ordrar_spec_html($unknown, 'jarfalla', 72, null);
nat_assert(!str_contains($unknownHtml, 'value="Vattenfall" checked'), 'okänd adress kryssar inte Vattenfall');
nat_assert(!str_contains($unknownHtml, 'value="E.ON" checked'), 'okänd adress kryssar inte E.ON');
nat_assert(!str_contains($unknownHtml, 'value="Ellevio" checked'), 'okänd adress kryssar inte Ellevio');
foreach (['SEOM', 'Kraftringen', 'Öresundskraft'] as $owner) {
    nat_assert(in_array($owner, jeab_ordrar_natbolag_options(), true), $owner . ' finns bland nätägarna');
    nat_assert(str_contains($unknownHtml, 'value="' . $owner . '"'), $owner . ' går att välja utan träff');
    nat_assert(!str_contains($unknownHtml, 'value="' . $owner . '" checked'), 'okänd adress kryssar inte ' . $owner);
}
$sollentuna = nat_row([
    'huvudsokande_address' => 'Flottarestigen 6',
    'postnr' => '192 51',
    'ort' => 'Sollentuna',
    'natbolag' => '',
]);
nat_assert(jeab_ordrar_natbolag_from_postnummer('19251') === 'SEOM', 'prefix 192 är SEOM');
nat_assert(jeab_ordrar_natbolag($sollentuna) === 'SEOM', '192 51 Sollentuna föreslår SEOM');
nat_assert(str_contains($unknownHtml, 'Ingen säker träff. Välj nätbolag själv.'), 'okänd adress säger att man ska välja själv');
$unknownStatus = jeab_ordrar_status_html($unknown, 'jarfalla', 72, null);
nat_assert(str_contains($unknownStatus, 'name="natbolag"'), 'osäker nätägare går ändå att välja');
nat_assert(str_contains($unknownStatus, 'value="" selected>Välj'), 'osäker nätägare har inget bolag förvalt');

echo "OK  nätbolag följer postnummer och sparat val\n";
