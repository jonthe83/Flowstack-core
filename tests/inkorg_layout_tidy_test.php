<?php

declare(strict_types=1);

/**
 * Inkorgens skrivbord: en jämn räknarrad, smalare lista och kontakt,
 * svar under mejlet, och en sammanhållen skicka-knapp.
 * Inget skickas.
 */

$root = dirname(__DIR__);

function tidy(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function tidy_media_blocks(string $css): array
{
    $blocks = [];
    $offset = 0;
    while (preg_match('/@media\s*\(([^)]*)\)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $m[0][1];
        $headerLen = strlen($m[0][0]);
        $depth = 1;
        $i = $start + $headerLen;
        $len = strlen($css);
        while ($i < $len && $depth > 0) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        }
        $blocks[] = [
            'query' => trim($m[1][0]),
            'body' => substr($css, $start + $headerLen, $i - $start - $headerLen - 1),
            'start' => $start,
        ];
        $offset = $i;
    }

    return $blocks;
}

function tidy_rule(string $body, string $selector): string
{
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
    if (preg_match($pattern, $body, $m) !== 1) {
        return '';
    }

    return $m[1];
}

$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
tidy($css !== '' && $page !== '', 'css och sidan läses');

$stats = tidy_rule($css, 'body.page-inkorg .crm-stats.smart-grid');
$tile = tidy_rule($css, 'body.page-inkorg .crm-stats .smart-tile');
$historik = tidy_rule($css, 'body.page-inkorg .crm-stats .smart-tile[data-ix-view="v-historik"]');
tidy(str_contains($stats, 'display: flex') && str_contains($stats, 'flex-wrap: nowrap') && str_contains($stats, 'gap: 8px'), 'räknarna är en rad med jämnt mellanrum');
tidy(str_contains($tile, 'flex: 1 1 0') && str_contains($tile, 'min-height: 72px'), 'räknarna har samma bredd och höjd');
tidy(str_contains($historik, 'order: 1'), 'Historik ligger längst till höger');

$split = tidy_rule($css, 'body.page-inkorg .ix-split');
tidy(str_contains($split, 'minmax(240px, 340px)') && str_contains($split, 'minmax(0, 1fr)'), 'listan är minst 240px och mitten tar resten');
tidy(str_contains($css, '.ix:not(.is-open) > .ix-split') && str_contains($css, 'grid-template-columns: minmax(0, 1fr);'), 'utan öppet ärende tar listan hela bredden');

$blocks = tidy_media_blocks($css);
$desk = null;
foreach ($blocks as $block) {
    if ($block['query'] === 'min-width: 1024px' && str_contains($block['body'], '#ix-list')) {
        $desk = $block;
        break;
    }
}
tidy($desk !== null, 'skrivbordsblocket finns');
$list = tidy_rule((string) $desk['body'], 'body.page-inkorg:has(#ix) #ix-list');
tidy(str_contains($list, 'align-self: stretch') && str_contains($list, 'overflow-y: auto') && !str_contains($list, '/ 2)'), 'listan fyller kolumnen och rullar själv');

$vgrid = tidy_rule($css, 'body.page-inkorg .ix-v2 .ix-vgrid');
tidy(str_contains($vgrid, 'minmax(260px, 340px)'), 'kundkolumnen är minst 260px');
tidy(preg_match('/body\.page-inkorg \.ix-v2 \.ix-side \{\s*max-height: none;\s*height: auto;\s*overflow: visible;/', $css) === 1, 'kontaktpanelen har ingen höjdtak');

$attach = null;
foreach ($blocks as $block) {
    if ($block['query'] === 'min-width: 761px' && str_contains($block['body'], '.ix-attached')) {
        $attach = $block;
        break;
    }
}
tidy($attach !== null, 'svaret fästs under mejlet från 761px');
$composer = tidy_rule((string) $attach['body'], 'body.page-inkorg .ix-v2 .ix-readreply > .ix-composer.ix-attached');
tidy(str_contains($composer, 'margin-top: -16px') && str_contains($composer, 'border-top: 0') && str_contains($composer, 'position: static'), 'svarsrutan sitter ihop med mejlet');
tidy(str_contains($page, 'class="ix-thread ix-readreply"') && str_contains($page, 'class="ix-composer ix-attached"'), 'svarsrutan ligger i trådkolumnen');
$threadAt = strpos($page, 'class="ix-thread ix-readreply"');
$composerAt = strpos($page, 'class="ix-composer ix-attached"');
$asideAt = strpos($page, 'class="ix-cust ix-side"');
tidy($threadAt !== false && $composerAt !== false && $asideAt !== false && $threadAt < $composerAt && $composerAt < $asideAt, 'läs, sedan svar, sedan kundpanelen');

tidy(preg_match('/body\.page-inkorg \.ix-v2 \.ix-split \{\s*display: inline-flex;\s*gap: 0;[^}]*background: var\(--brand\);[^}]*overflow: hidden;/', $css) === 1, 'skicka och pilen delar en yta');
$car = tidy_rule($css, 'body.page-inkorg .ix-v2 .ix-split .ix-car');
tidy(str_contains($car, 'margin: 0') && str_contains($car, 'border: 0') && !str_contains($car, 'margin-left: 1px'), 'ingen vit linje mellan skicka och pilen');

$mobileSplit = null;
$mobileSide = null;
foreach ($blocks as $block) {
    if ($block['query'] !== 'max-width: 760px') {
        continue;
    }
    if (str_contains($block['body'], 'body.page-inkorg .ix-split')) {
        $mobileSplit = $block;
    }
    if (str_contains($block['body'], '.ix-v2 .ix-side')) {
        $mobileSide = $block;
    }
}
tidy($mobileSplit !== null && str_contains(tidy_rule((string) $mobileSplit['body'], 'body.page-inkorg .ix-split'), 'display: block'), 'mobilen är fortfarande en kolumn');
tidy($mobileSide !== null && str_contains(tidy_rule((string) $mobileSide['body'], 'body.page-inkorg .ix-v2 .ix-side'), 'display: block') && str_contains((string) $mobileSide['body'], '.ix-kund-body[hidden]') && str_contains((string) $mobileSide['body'], 'display: none !important'), 'mobilen visar kundpanelen som en fällbar ruta');
tidy($mobileSide !== null && str_contains(tidy_rule((string) $mobileSide['body'], 'body.page-inkorg .ix-v2 .ix-composer'), 'position: sticky'), 'mobilens svarsruta sitter kvar längst ner');

$http = sys_get_temp_dir() . '/jeab-ix-tidy-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/inkorg.css', $css);
$port = 0;
for ($try = 8191; $try < 8210; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
tidy($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $serverPipes
);
tidy(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/inkorg.css');
    if (is_string($probe) && str_contains($probe, 'ix-attached')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
tidy($ready, 'css-filen svarar');
$curl = curl_init('http://127.0.0.1:' . $port . '/inkorg.css');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$body = (string) curl_exec($curl);
$code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
tidy($code === 200 && str_contains($body, 'minmax(240px, 340px)') && str_contains($body, 'minmax(260px, 340px)') && str_contains($body, 'ix-attached'), 'curl ser den nya layouten, fick ' . $code);

echo "inkorg layout tidy ok\n";
