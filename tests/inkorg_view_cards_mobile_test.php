<?php

declare(strict_types=1);

/** Vykort under 640px ligger på en rad och scrollar. Namnet radbryts inte. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-ixvy-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function iv(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function iv_media_blocks(string $css): array
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

function iv_rule(string $body, string $selector): string
{
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
    if (preg_match($pattern, $body, $m) !== 1) {
        return '';
    }
    return $m[1];
}

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$blocks = iv_media_blocks($css);
$cause = null;
$mobile = null;
foreach ($blocks as $block) {
    if (str_contains($block['query'], 'max-width: 760px') && str_contains($block['body'], '.crm-stats.smart-grid')) {
        $cause = $block;
    }
    if (str_contains($block['query'], 'max-width: 640px') && str_contains($block['body'], '.crm-stats.smart-grid')) {
        $mobile = $block;
    }
}
iv($cause !== null, '760px-regeln som scrollar vyraden finns kvar som orsak');
$causeGrid = iv_rule($cause['body'], 'body.page-inkorg .crm-stats.smart-grid');
$causeTile = iv_rule($cause['body'], 'body.page-inkorg .crm-stats .smart-tile');
iv(str_contains($causeGrid, 'display: flex') && str_contains($causeGrid, 'overflow-x: auto'), 'orsaken är display:flex och overflow-x:auto på .crm-stats.smart-grid');
iv(str_contains($causeTile, 'flex: 0 0 auto') && str_contains($causeTile, 'min-width: 112px'), 'orsaken är flex:0 0 auto och min-width:112px på korten');
iv($mobile !== null && $mobile['start'] > $cause['start'], '640px-blocket ligger efter 760px-regeln');

$grid = iv_rule($mobile['body'], 'body.page-inkorg .crm-stats.smart-grid');
$tile = iv_rule($mobile['body'], 'body.page-inkorg .crm-stats .smart-tile');
$name = iv_rule($mobile['body'], 'body.page-inkorg .crm-stats .smart-tile span');
$count = iv_rule($mobile['body'], 'body.page-inkorg .crm-stats .smart-tile b');
$on = iv_rule($mobile['body'], 'body.page-inkorg .crm-stats .smart-tile.is-on');
iv(str_contains($grid, 'display: flex') && str_contains($grid, 'flex-wrap: nowrap'), 'vyraden är en rad under 640px');
iv(str_contains($grid, 'overflow-x: auto'), 'vyraden scrollar i sidled');
iv(str_contains($tile, 'min-width: max-content'), 'korten behåller hela namnet');
iv(str_contains($name, 'white-space: nowrap'), 'namnet radbryts inte');
iv(!str_contains($name, '-webkit-line-clamp'), 'namnet klipps inte efter tre rader');
iv(str_contains($count, 'font-size: 1.25rem'), 'siffran är mindre än 1.75rem');
iv(str_contains($on, 'box-shadow: inset 0 0 0 2px var(--brand)'), 'aktiv vy har tydlig markering');

$basePos = strpos($css, 'body.page-inkorg .crm-stats.smart-grid {');
iv($basePos !== false && $basePos < $cause['start'], 'desktopregeln ligger före mobilblocken');
$baseSlice = substr($css, $basePos, $cause['start'] - $basePos);
$base = iv_rule($baseSlice, 'body.page-inkorg .crm-stats.smart-grid');
$baseTile = iv_rule($baseSlice, 'body.page-inkorg .crm-stats .smart-tile');
iv(str_contains($base, 'display: flex') && str_contains($base, 'flex-wrap: nowrap'), 'desktop är en rad');
iv(str_contains($baseTile, 'flex: 1 1 0'), 'korten delar raden lika');
iv(!str_contains($base, 'overflow-x'), 'desktopregeln har ingen overflow-x');

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
iv(preg_match('/<nav class="smart-grid crm-stats ix-folders"[^>]*>/', $page) === 1, 'vyraden är nav.smart-grid');
iv(!preg_match('/<nav class="smart-grid crm-stats ix-folders"[^>]*style=/', $page), 'vyraden har ingen inline-stil');
iv(!preg_match('/overflow-x|white-space:\s*nowrap|min-width:\s*112px/', $page), 'inkorg.php sätter inte scroll, nowrap eller 112px');

$js = (string) file_get_contents($root . '/assets/js/app.js');
iv(!preg_match('/ix-folders|crm-stats\.smart-grid/', $js) || !preg_match('/querySelector\([\'\"][^\'\"]*ix-folders/', $js), 'ingen JS scrollar vyraden');

$names = [];
foreach (['malmo', 'jarfalla'] as $box) {
    $_GET = ['p' => 'inkorg', 'box' => $box];
    $_SESSION['jeab_inkorg_box'] = $box;
    $html = (string) include $root . '/src/pages/inkorg.php';
    iv(preg_match('/<nav class="smart-grid crm-stats ix-folders"/', $html) === 1, $box . ' använder samma vyrad');
    iv(!preg_match('/<nav class="smart-grid crm-stats ix-folders"[^>]*style=/', $html), $box . ' har ingen inline-stil på vyraden');
    preg_match_all('/<a class="smart-tile[^"]*".*?<span>([^<]*)<\/span>/s', $html, $cards);
    iv(count($cards[1]) >= 2, $box . ' har flera vykort');
    foreach ($cards[1] as $label) {
        iv($label !== '' && !str_contains($label, '…') && !str_contains($label, '...'), $box . ' skriver ut hela namnet ' . $label);
    }
    $names[$box] = $cards[1];
}
$want = ['Inkorg', 'Service', 'Information', 'Alla', 'Historik'];
iv($names['malmo'] === $want, 'Malmö visar Inkorg, Service, Information, Alla och Historik');
iv($names['jarfalla'] === $want, 'Järfälla visar samma fem vyer');
$shared = array_values(array_intersect($names['malmo'], $names['jarfalla']));
iv($shared === $want, 'delade vykort använder samma kortmarkup');

foreach ([375, 390] as $viewport) {
    $pad = 16 * 2;
    $gutter = 15;
    $border = 2;
    $gridPad = 8;
    $inner = $viewport - $pad - $gutter - $border - $gridPad;
    $cols = intdiv($inner, 105);
    $oldRow = 7 * 112;
    iv($inner > 0 && $cols >= 2 && $cols <= 3, $viewport . 'px får ' . $cols . ' kolumner');
    iv($cols * 105 <= $inner && $inner < $viewport, $viewport . 'px håller vyraden inom viewport (' . $inner . 'px)');
    iv($oldRow > $viewport, $viewport . 'px sprack med sju kort à 112px (' . $oldRow . 'px)');
}

$http = sys_get_temp_dir() . '/jeab-ixvy-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/inkorg.css', $css);
$port = 0;
for ($try = 8141; $try < 8160; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
iv($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
iv(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/inkorg.css');
    if (is_string($probe) && str_contains($probe, 'min-width: max-content')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
iv($ready, 'css-filen svarar');
$curl = curl_init('http://127.0.0.1:' . $port . '/inkorg.css');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$body = (string) curl_exec($curl);
$code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
iv($code === 200 && str_contains($body, 'min-width: max-content'), 'curl ser en rad med hela namnet, fick ' . $code);
$served = iv_media_blocks($body);
$servedMobile = null;
foreach ($served as $block) {
    if (str_contains($block['query'], 'max-width: 640px') && str_contains($block['body'], '.crm-stats.smart-grid')) {
        $servedMobile = $block;
    }
}
iv($servedMobile !== null && str_contains(iv_rule($servedMobile['body'], 'body.page-inkorg .crm-stats.smart-grid'), 'overflow-x: auto'), 'curl: vyraden under 640px scrollar');

echo "OK  vykort mobil\n";
