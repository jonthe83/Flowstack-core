<?php

declare(strict_types=1);

/**
 * Skrivbord ≥1024px: listan och tråden rullar var för sig.
 * Under 1024px är oförändrat. Inga action-hanterare i src/inkorg.php.
 */

$root = dirname(__DIR__);

function desk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function desk_media_blocks(string $css): array
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
        ];
        $offset = $i;
    }
    return $blocks;
}

function desk_rule(string $body, string $selector): string
{
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
    if (preg_match($pattern, $body, $m) !== 1) {
        return '';
    }
    return $m[1];
}

$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$nav = (string) file_get_contents($root . '/assets/js/inkorg-nav.js');
$handlers = (string) file_get_contents($root . '/src/inkorg.php');

$blocks = desk_media_blocks($css);
$deskBlock = null;
foreach ($blocks as $block) {
    if ($block['query'] === 'min-width: 1024px' && str_contains($block['body'], '#ix-list') && str_contains($block['body'], '#ix-conv')) {
        $deskBlock = $block;
        break;
    }
}
desk($deskBlock !== null, '1024px-blocket har listan och tråden');
$deskBody = (string) ($deskBlock['body'] ?? '');
$list = desk_rule($deskBody, 'body.page-inkorg:has(#ix) #ix-list');
$conv = desk_rule($deskBody, 'body.page-inkorg:has(#ix) #ix-conv');
desk(str_contains($list, 'overflow-y: auto') && str_contains($list, 'align-self: stretch') && !str_contains($list, '/ 2)'), 'listan fyller kolumnen och rullar själv');
desk(str_contains($conv, 'overflow-y: auto') && str_contains($conv, 'position: sticky') && str_contains($conv, 'top: var(--ix-desk-head)') && str_contains($conv, 'max-height: calc(100vh - var(--ix-desk-head))'), 'tråden sitter under sidhuvudet och rullar själv');
desk(str_contains($deskBody, 'body.page-inkorg:has(#ix)'), 'skrivbordslåset gäller bara inkorgen, inte regler');

foreach ($blocks as $block) {
    if (str_contains($block['query'], 'max-width')) {
        desk(!str_contains($block['body'], '--ix-desk-head'), 'under 1024px (' . $block['query'] . ') saknar skrivbordsrutorna');
    }
}
$mobile = null;
foreach ($blocks as $block) {
    if ($block['query'] === 'max-width: 760px') {
        $mobile = $block;
        break;
    }
}
desk($mobile !== null && str_contains(desk_rule($mobile['body'], 'body.page-inkorg .ix-split'), 'display: block'), 'mobilen under 760px är fortfarande en kolumn');
desk(str_contains(desk_rule($mobile['body'], 'body.page-inkorg .ix.is-open .ix-list'), 'display: none'), 'öppet ärende på mobil döljer fortfarande listan');

$snapAt = strpos($page, 'window.__ixDeskSnap = window.jeabInkorgDeskScrollPlan');
$showAt = strpos($page, 'ixShowPending(nextId)');
$preventAt = strpos($page, "ev.preventDefault();\n        var listEl");
desk($snapAt !== false && $showAt !== false && $preventAt !== false && $preventAt < $snapAt && $snapAt < $showAt, 'klicket sparar scrollen innan tråden ritas');
desk(str_contains($page, 'list.scrollTop = plan.listTop') && str_contains($page, 'window.scrollTo(0, plan.pageY)') && str_contains($page, 'conv.scrollTop = 0'), 'listans läge behålls och tråden visas utan att sidan hoppar upp');
desk(str_contains($page, "(min-width: 1024px)") && str_contains($nav, 'function jeabInkorgDeskScrollPlan'), 'planen gäller bara skrivbord');
desk(!str_contains($handlers, 'jeabInkorgDeskScrollPlan') && !str_contains($handlers, '__ixDeskSnap'), 'src/inkorg.php och dess action-hanterare är orörda');

$nodeFile = sys_get_temp_dir() . '/jeab-ix-desk-' . bin2hex(random_bytes(3)) . '.js';
file_put_contents($nodeFile, <<<'JS'
const api = require(process.argv[2]);
function ok(cond, message) {
  if (!cond) {
    console.error('FAIL ' + message);
    process.exit(1);
  }
  console.log('OK  ' + message);
}
ok(api.jeabInkorgDeskWide(1024) === true && api.jeabInkorgDeskWide(1023) === false, '1024 är skrivbord, 1023 är inte');
const low = api.jeabInkorgDeskScrollPlan(1280, 640, 12);
ok(low.desktop === true && low.listTop === 640 && low.pageY === 12 && low.convTop === 0, 'klick långt ner behåller listan och sidans scroll');
const phone = api.jeabInkorgDeskScrollPlan(390, 640, 80);
ok(phone.desktop === false && phone.convTop === null && phone.listTop === 0, 'mobilen får ingen skrivbordsplan');
const state = { listTop: 640, pageY: 12, convTop: 200 };
const next = low.desktop ? { listTop: low.listTop, pageY: low.pageY, convTop: low.convTop } : state;
ok(next.listTop === 640 && next.pageY === 12 && next.convTop === 0, 'applicerad plan visar tråden utan att nollställa listan');
JS);
$proc = proc_open(
    ['node', $nodeFile, $root . '/assets/js/inkorg-nav.js'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
desk(is_resource($proc), 'node startar');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
echo $out;
if ($err !== '') {
    fwrite(STDERR, $err);
}
desk($code === 0, 'node-planen gick igenom');
@unlink($nodeFile);

$http = sys_get_temp_dir() . '/jeab-ix-desk-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/inkorg.css', $css);
$port = 0;
for ($try = 8171; $try < 8190; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
desk($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $serverPipes
);
desk(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/inkorg.css');
    if (is_string($probe) && str_contains($probe, '#ix-list')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
desk($ready, 'css-filen svarar');
$curl = curl_init('http://127.0.0.1:' . $port . '/inkorg.css');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$body = (string) curl_exec($curl);
$httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
desk($httpCode === 200 && str_contains($body, 'overflow-y: auto') && str_contains($body, 'align-self: stretch') && str_contains($body, 'height: calc(100vh - var(--ix-desk-head))'), 'curl ser skrivbordets två rutor, fick ' . $httpCode);
$served = null;
foreach (desk_media_blocks($body) as $block) {
    if ($block['query'] === 'min-width: 1024px' && str_contains($block['body'], '#ix-list')) {
        $served = $block;
    }
}
desk($served !== null && str_contains(desk_rule($served['body'], 'body.page-inkorg:has(#ix) #ix-conv'), 'position: sticky'), 'curl: trådrutan är sticky på skrivbord');

echo "OK  skrivbordets inkorg\n";
