<?php

declare(strict_types=1);

/**
 * Offertfynd O6, O8–O13, O16 och O17.
 * Kalkyllogiken (vinst sätter kundpris) rörs inte. Inget kundutskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-offert-fynd-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_OFFERT_FYND_TMP=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';

function fy(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/app.css');
$inkorgCss = (string) file_get_contents($root . '/assets/css/inkorg.css');
$js = (string) file_get_contents($root . '/assets/js/app.js');
$index = (string) file_get_contents($root . '/index.php');

fy(preg_match('/\.offert-step button\s*\{[^}]*min-height:\s*44px/', $css) === 1, 'O10: −/+ är minst 44 px');
fy(preg_match('/\.offert-step button\s*\{[^}]*width:\s*44px/', $css) === 1, 'O10: −/+ är 44 px breda');
fy(preg_match('/\.offert-check\s*\{[^}]*min-height:\s*44px/', $css) === 1, 'O11: elbilsladdaren är minst 44 px');
fy(preg_match('/\.offert-check-ui svg\s*\{[^}]*width:\s*24px[^}]*height:\s*24px/', $css) === 1, 'O11: ikonen är 24 px');
fy(preg_match('/\.offert-month summary\s*\{[^}]*min-height:\s*44px/', $css) === 1, 'O12: Ändra och visa detaljer är minst 44 px');
fy(preg_match('/\.offert-seg-opt span\s*\{[^}]*min-height:\s*44px/', $css) === 1, 'O13: system och storlek är minst 44 px');
fy(preg_match('/max-width:\s*719px[\s\S]*?\.offert-seg-opt span\s*\{[^}]*min-height:\s*44px/', $css) === 1, 'O13: under 719 px är segmenten 44 px');
fy(preg_match('/\.offert-save button\s*\{[^}]*min-height:\s*44px[^}]*font-size:\s*16px/', $css) === 1, 'O9: spara standard är minst 44 px och 16 px text');
fy(!str_contains($css, 'min-width: 28rem'), 'O17: BRF-tabellen har inte min-width 28rem');
fy(preg_match('/max-width:\s*719px[\s\S]*?\.offert-ev-labor-wrap\s*\{[^}]*overflow-x:\s*clip/', $css) === 1, 'O17: ingen horisontell scroll i BRF-tabellen under 719 px');
fy(preg_match('/max-width:\s*719px[\s\S]*?\.offert-ev-labor-table tr\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1\.35fr\)/', $css) === 1, 'O17: BRF-raderna ligger i rutnät som får plats på 390 px');
fy(preg_match('/\.ix-mcust-btn\s*\{[^}]*min-height:\s*44px[^}]*font-size:\s*16px/', $inkorgCss) === 1, 'O16: mobil kundrad är minst 44 px');
fy(!preg_match('/\.ix-mcust-btn\s*\{[^}]*padding:\s*0;/', $inkorgCss), 'O16: kundraden har inte padding 0');

fy(str_contains($js, 'function showSaveError') && str_contains($js, 'offert-save-error') && str_contains($js, 'Kunde inte spara standard.'), 'O8: sparfel skrivs ut');
fy(!str_contains($js, 'Keep the button if the server did not take it.'), 'O8: tom catch är borta');
fy(str_contains($js, "data.ok === false") && str_contains($js, 'note.setAttribute(\'role\', \'alert\')'), 'O8: serverns fel och role=alert');

fy(str_contains($index, "case 'inkorg.send_quote':") && str_contains($index, '_JEAB_QUOTE_REMINDER') && str_contains($index, 'jeab_quote_sent_with_reminder'), 'O6: skickad offert tar med påminnelsens id');
fy(!str_contains($index, 'jeab_offer_send') && !str_contains($index, 'jeab_auto_send_quote'), 'index skickar ingen offert själv');

$calc = jeab_offert_calc([
    'sigen_typ' => 'neo',
    'sigen_kw' => '10',
    'b3' => 2,
    'b14' => 16,
    'sokande' => 2,
    'laddare' => 0,
    'g27' => 70000,
]);
fy((int) round($calc['i14']) === 121800, 'vinsten sätter fortfarande kundpriset (16 paneler NEO utan laddare)');

$store = jeab_store();
$page = require $root . '/src/pages/offert.php';
fy(is_string($page) && str_contains($page, 'data-js="offert-save-error"') && str_contains($page, 'role="alert"'), 'O8: felraden finns i kalkylen');
fy(str_contains($page, 'width="24" height="24"') && !str_contains($page, 'width="18"'), 'O11: krysset ritas 24 px');
fy(str_contains($page, 'offert-ev-labor-table') && !str_contains($page, 'min-width: 28rem'), 'O17: tabellen är kvar utan fast minbredd i html');

fy(jeab_flash_text('Offerten skickad.') === 'Offerten skickad.', 'O6: flashen Offerten skickad. släpps igenom');
fy(jeab_flash_text('Inte en flash.') === null, 'okänd flash visas fortfarande inte');
$_GET = ['pam' => '42', 'flash' => 'Offerten skickad.', 'co' => 'jarfalla'];
$flash = jeab_flash_html('Offerten skickad.');
fy(str_contains($flash, 'Öppna påminnelsen') && str_contains($flash, 'flash-link') && str_contains($flash, 'open=42'), 'O6: flashen länkar till påminnelsen');
fy(str_contains($flash, 'p=reminders'), 'O6: länken går till påminnelser');
$_GET = ['flash' => 'Sparad.', 'co' => 'jarfalla'];
fy(!str_contains(jeab_flash_html('Sparad.'), 'Öppna påminnelsen'), 'andra flashar får ingen offertlänk');

$with = jeab_quote_sent_with_reminder('https://flowstack.example.test/index.php?p=inkorg&id=thr-1&flash=Offerten+skickad.', 'inkorg.send_quote', 'Offerten skickad.', 42);
fy(str_contains($with, 'pam=42'), 'O6: omdirigeringen får pam');
$without = jeab_quote_sent_with_reminder('https://flowstack.example.test/index.php?p=inkorg', 'inkorg.thread', 'Ärendet sparat.', 42);
fy(!str_contains($without, 'pam='), 'andra inkorgsåtgärder får inte pam');
$dry = jeab_quote_sent_with_reminder('https://flowstack.example.test/index.php?p=inkorg', 'inkorg.send_quote', 'Förhandsvisning klar. Inget skickat.', 42);
fy(!str_contains($dry, 'pam='), 'förhandsvisning länkar inte och skickar inget');

$router = $tmp . '/router.php';
$workspace = $root;
file_put_contents($router, <<<PHP
<?php
\$tmp = getenv('JEAB_OFFERT_FYND_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_FELLOGG_DIR'] = \$tmp;
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/flash') {
    require '{$workspace}/src/bootstrap.php';
    require_once '{$workspace}/src/layout.php';
    \$_GET['pam'] = '42';
    \$_GET['co'] = 'jarfalla';
    echo jeab_flash_html(jeab_flash_text('Offerten skickad.'));
    return true;
}
return false;
PHP);

$port = 8891;
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $workspace, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $workspace
);
fy(is_resource($server), 'curl-servern startar');
$up = false;
for ($i = 0; $i < 40; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/assets/css/app.css');
    if (is_string($probe) && str_contains($probe, '.offert-step button')) {
        $up = true;
        break;
    }
    usleep(100000);
}
fy($up, 'curl når app.css');

function fy_curl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200) {
        fwrite(STDERR, "FAIL curl {$url} status {$status}\n");
        exit(1);
    }
    return $body;
}

$cssBody = fy_curl('http://127.0.0.1:' . $port . '/assets/css/app.css');
fy(str_contains($cssBody, 'min-height: 44px') && !str_contains($cssBody, 'min-width: 28rem'), 'curl app.css: 44 px och ingen 28rem');
$inkorgBody = fy_curl('http://127.0.0.1:' . $port . '/assets/css/inkorg.css');
fy(preg_match('/\.ix-mcust-btn\s*\{[^}]*min-height:\s*44px/', $inkorgBody) === 1, 'curl inkorg.css: kundrad 44 px');
$jsBody = fy_curl('http://127.0.0.1:' . $port . '/assets/js/app.js');
fy(str_contains($jsBody, 'function showSaveError') && str_contains($jsBody, 'Kunde inte spara standard.'), 'curl app.js: sparfel');
$flashBody = fy_curl('http://127.0.0.1:' . $port . '/flash');
fy(str_contains($flashBody, 'Offerten skickad.') && str_contains($flashBody, 'Öppna påminnelsen') && str_contains($flashBody, 'open=42'), 'curl flash länkar till påminnelse 42');

proc_terminate($server);
proc_close($server);

echo "OK  offert-fynd\n";
