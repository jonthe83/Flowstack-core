<?php

declare(strict_types=1);

/**
 * Stegbyte ska inte slå mot externa källor. Uppdatera (?refresh=1) får göra det.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

function nav_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

nav_assert(jeab_ordrar_external_live() === true, 'externa källor är på tills sidan stänger av dem');

jeab_ordrar_external_live(false);
$t0 = microtime(true);
$lookup = jeab_ordrar_address_lookup('Sibblevägen 8, 147 92 Grödinge');
$http = jeab_ordrar_http_json('https://geocode.arcgis.com/arcgis/rest/services/World/GeocodeServer/findAddressCandidates?f=json');
$row = [
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Guy Nilsson',
    'huvudsokande' => 'Guy Nilsson',
    'address' => 'Sibblevägen 8, 147 92 Grödinge',
    'huvudsokande_address' => 'Sibblevägen 8, 147 92 Grödinge',
    'natbolag' => '',
    'solkoll_status' => '',
    'steps' => ['spec' => '2026-09-02'],
    'fortnox_order' => '1',
];
$nat = jeab_ordrar_natbolag($row);
$status = jeab_ordrar_status_html($row, 'jarfalla', 4242, null);
$ms = (microtime(true) - $t0) * 1000;

nav_assert($lookup === jeab_ordrar_lookup_empty(), 'adressuppslag hoppas över när live är av');
nav_assert($http === null, 'nätbolagets HTTP hoppas över när live är av');
nav_assert($ms < 400, 'lokal rendering utan externa anrop höll sig under 400 ms, fick ' . round($ms, 1));
nav_assert(str_contains($status, 'ordrar-status-card'), 'status-panelen ritas utan Solkoll-anrop');
nav_assert($nat === '' || in_array($nat, jeab_ordrar_natbolag_options(), true), 'nätbolag utan HTTP är sparat värde eller tomt');

jeab_ordrar_external_live(true);
nav_assert(jeab_ordrar_external_live() === true, 'live kan slås på igen för uppdatera');

$url = jeab_ordrar_url(['co' => 'jarfalla', 'id' => 4242, 'stop' => 'spec', 'refresh' => '1']);
nav_assert(str_contains($url, 'refresh=1'), 'uppdatera-länken bär refresh=1');
nav_assert(str_contains($url, 'stop=spec'), 'uppdatera behåller steget');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
nav_assert(str_contains($page, 'ordrar-step-pane'), 'varje steg ligger i en panel');
nav_assert(!str_contains($page, "\$paneBody = 'boka_bestall'"), 'boka och beställ är egna paneler');
nav_assert(str_contains($page, 'data-stop="fakturering"') || str_contains($page, "\$view === 'fakturering'"), 'fakturering är en egen panel');
nav_assert(str_contains($page, 'ordrar-refresh'), 'uppdatera-ikonen finns');
nav_assert(str_contains($page, 'jeabOrdrarShowStop'), 'stegbyte sker i klienten');
nav_assert(str_contains($page, 'data-step-ms'), 'klienten mäter stegbytet');
nav_assert(str_contains($index, "jeab_ordrar_external_live(false)"), 'vanlig GET stänger av externa källor');
nav_assert(str_contains($index, '$ordrarRefresh'), 'uppdatera slår på de tunga anropen');

echo "OK  stegbyte utan externa källor\n";
