<?php

declare(strict_types=1);

/**
 * Inkorgen, listrad: namn och datum som idag, produktmärket kvar på ämnesraden,
 * adress och ort från kontakten, ämne, två rader förhandsvisning.
 * Adress hämtas i samma listanrop, inte en fråga per rad.
 */

$tmp = sys_get_temp_dir() . '/jeab-ixrow-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function rowok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $over */
function row_thread(array $over = []): array
{
    return array_replace_recursive([
        'id' => 'rad-1',
        'status' => 'open',
        'company' => 'jarfalla',
        'updated_at' => '2026-10-27T07:15:00+00:00',
        'offer' => 'batteri',
        'first' => ['subject' => 'Nytt tak till villan', 'from' => 'ada@example.test'],
        'last' => ['from_name' => 'Ada Ärende', 'from' => 'ada@example.test', 'received_at' => '2026-10-27T07:15:00+00:00'],
        'list_snippet' => 'Hej, vi vill ha hjälp med taket och undrar när ni kan komma förbi.',
        'read' => [],
    ], $over);
}

function row_between(string $html, string $name): string
{
    $at = strpos($html, '>' . $name . '<');
    if ($at === false) {
        return '';
    }
    $start = strrpos(substr($html, 0, $at), '<li ');
    $end = strpos($html, '</li>', $at);

    return ($start === false || $end === false) ? '' : substr($html, $start, $end - $start);
}

$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Ärende',
    'email' => 'ada.rad@example.test',
    'street' => 'Storgatan 12',
    'postal_code' => '19255',
    'city' => 'Sollentuna',
], false);
$bo = jeab_kontakt_find_or_create([
    'name' => 'Bo Berg',
    'email' => 'bo.rad@example.test',
    'street' => 'Lindvägen 3',
    'postal_code' => '17731',
    'city' => 'Järfälla',
], false);
rowok(is_array($ada) && is_array($bo), 'två kontakter skapas');
$adaId = (string) ($ada['id'] ?? '');
$boId = (string) ($bo['id'] ?? '');

$before = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] ?? 0);
$html = jeab_inkorg_fast_list_html([
    row_thread([
        'id' => 'ada',
        'contact_id' => $adaId,
        'customer_email' => 'ada.rad@example.test',
    ]),
    row_thread([
        'id' => 'bo',
        'contact_id' => $boId,
        'customer_email' => 'bo.rad@example.test',
        'offer' => 'laddbox',
        'first' => ['subject' => 'Laddbox hemma', 'from' => 'bo.rad@example.test'],
        'last' => ['from_name' => 'Bo Berg', 'from' => 'bo.rad@example.test'],
        'list_snippet' => 'Vi behöver en laddbox vid uppfarten innan vintern.',
    ]),
], '', 'me', ['p' => 'inkorg'], [], 1, 1);
$queries = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] ?? 0) - $before;
rowok($queries <= 1, 'adress för båda raderna hämtas i högst en kontaktfråga, fick ' . $queries);

$adaRow = row_between($html, 'Ada Ärende');
$boRow = row_between($html, 'Bo Berg');
rowok($adaRow !== '' && $boRow !== '', 'båda raderna ritas');

$adaName = strpos($adaRow, 'class="ix-nm"');
$adaDate = strpos($adaRow, 'class="ix-dt"');
$adaAddr = strpos($adaRow, 'class="ix-addr"');
$adaL2 = strpos($adaRow, 'class="ix-l2"');
$adaProd = strpos($adaRow, 'class="ix-prod"');
$adaSub = strpos($adaRow, 'class="ix-sub"');
$adaPre = strpos($adaRow, 'class="ix-pre"');
rowok($adaName !== false && $adaDate !== false && $adaName < $adaDate && $adaDate < $adaAddr, 'namn och datum ligger kvar på första raden');
rowok($adaAddr !== false && $adaAddr < $adaL2 && str_contains($adaRow, 'Storgatan 12, Sollentuna'), 'adress och ort ligger på raden under namnet');
rowok($adaL2 !== false && $adaProd !== false && $adaL2 < $adaProd && $adaProd < $adaSub && str_contains($adaRow, '>Batteri<') && str_contains($adaRow, 'Nytt tak till villan'), 'produktmärket ligger kvar med ämnet');
rowok($adaSub !== false && $adaPre !== false && $adaSub < $adaPre && str_contains($adaRow, 'vi vill ha hjälp med taket'), 'förhandsvisningen ligger under ämnet');
rowok(str_contains($boRow, 'Lindvägen 3, Järfälla') && str_contains($boRow, '>Elbilsladdare<') && str_contains($boRow, 'laddbox vid uppfarten'), 'andra raden får sin egen adress, produkt och text');

$bare = jeab_inkorg_fast_list_html([
    row_thread([
        'id' => 'tom',
        'contact_id' => '',
        'customer_email' => '',
        'first' => ['subject' => 'Utan adress', 'from' => ''],
        'last' => ['from_name' => 'Utan Adress', 'from' => ''],
        'list_snippet' => '',
        'snippet' => '',
        'offer' => '',
    ]),
], '', 'me', ['p' => 'inkorg'], [], 1, 1);
$bareRow = row_between($bare, 'Utan Adress');
rowok($bareRow !== '' && !str_contains($bareRow, 'class="ix-addr"') && !str_contains($bareRow, 'class="ix-pre"') && str_contains($bareRow, 'class="ix-l1"') && str_contains($bareRow, 'Utan adress'), 'tom adress och tom text ritas inte');

$kept = jeab_inkorg_fast_list_html([
    row_thread([
        'id' => 'sparad',
        'contact_id' => $adaId,
        'list_street' => 'Egen väg 1',
        'list_city' => 'Upplands Väsby',
        'customer_email' => 'ada.rad@example.test',
        'last' => ['from_name' => 'Sparad Adress', 'from' => 'ada.rad@example.test'],
    ]),
], '', 'me', ['p' => 'inkorg'], [], 1, 1);
rowok(str_contains($kept, 'Egen väg 1, Upplands Väsby') && !str_contains($kept, 'Storgatan 12'), 'adress som redan finns på raden används');

$onlyCity = jeab_inkorg_fast_list_html([
    row_thread([
        'id' => 'ort',
        'list_street' => '',
        'list_city' => 'Märsta',
        'contact_id' => '',
        'customer_email' => '',
        'first' => ['subject' => 'Bara ort', 'from' => ''],
        'last' => ['from_name' => 'Bara Ort', 'from' => ''],
    ]),
], '', 'me', ['p' => 'inkorg'], [], 1, 1);
rowok(str_contains($onlyCity, 'class="ix-addr">Märsta<'), 'bara ort räcker för adressraden');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/inkorg.css');
rowok(str_contains($css, '.ix-card-copy .ix-addr') && str_contains($css, 'font-size: 13px') && str_contains($css, '-webkit-line-clamp: 2') && str_contains($css, 'line-clamp: 2'), 'adress och förhandsvisning är liten text med två raders klipp');
$mobileAt = strpos($css, '@media (max-width: 375px)');
rowok($mobileAt !== false, 'det finns en 375px-regel');
$mobile = $mobileAt === false ? '' : substr($css, $mobileAt, 900);
rowok(str_contains($mobile, '.ix-pre') && str_contains($mobile, 'line-clamp: 2') && str_contains($mobile, '.ix-addr') && str_contains($mobile, 'text-overflow: ellipsis'), '375px klipper adress och håller förhandsvisningen på två rader');

$http = sys_get_temp_dir() . '/jeab-ixrow-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/rad.html', $html);
file_put_contents($http . '/inkorg.css', $css);
$port = 0;
for ($try = 8241; $try < 8260; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
rowok($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
rowok(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/rad.html');
    if (is_string($probe) && str_contains($probe, 'ix-addr')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
rowok($ready, 'sidan svarar');
$curlPage = curl_init('http://127.0.0.1:' . $port . '/rad.html');
curl_setopt_array($curlPage, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$pageBody = (string) curl_exec($curlPage);
$pageCode = (int) curl_getinfo($curlPage, CURLINFO_HTTP_CODE);
curl_close($curlPage);
$curlCss = curl_init('http://127.0.0.1:' . $port . '/inkorg.css');
curl_setopt_array($curlCss, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$cssBody = (string) curl_exec($curlCss);
$cssCode = (int) curl_getinfo($curlCss, CURLINFO_HTTP_CODE);
curl_close($curlCss);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
rowok($pageCode === 200 && str_contains($pageBody, 'Storgatan 12, Sollentuna') && str_contains($pageBody, 'class="ix-pre"') && str_contains($pageBody, 'class="ix-l1"'), 'curl ser adress och förhandsvisning, fick ' . $pageCode);
rowok($cssCode === 200 && str_contains($cssBody, '@media (max-width: 375px)') && str_contains($cssBody, 'line-clamp: 2'), 'curl ser 375px-regeln, fick ' . $cssCode);

echo "OK  listrad\n";
