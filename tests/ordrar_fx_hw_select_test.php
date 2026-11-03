<?php

declare(strict_types=1);

/**
 * Fakturering steg 4: husarbete är en select med Fortnox-typerna,
 * etiketten ligger i kolumnhuvudet på desktop, och Att betala följer avdraget.
 * Anropar inte Fortnox.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-fx-hw-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function hw_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function hw_row(): array
{
    return [
        'reminder_id' => 1914,
        'company' => 'jarfalla',
        'kind' => 'laddbox',
        'customer' => 'Nils Nilsson',
        'address' => 'Testgatan 1, 175 00 Järfälla',
        'huvudsokande' => 'Nils Nilsson',
        'personnummer' => '19800101-0000',
        'fastighetsbeteckning' => 'Testet 1:1',
        'pris' => 5000,
        'kundpris' => 5000,
        'kalkyl' => ['sokande' => 1],
        'fortnox_order' => '1914',
        'fortnox_order_rows' => [[
            'RowId' => '1',
            'ArticleNumber' => 'BAT',
            'Description' => 'Batteri',
            'HouseWork' => true,
            'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
            'DeliveredQuantity' => '1',
            'Price' => '10000',
        ]],
        'steps' => [],
    ];
}

$choices = jeab_ordrar_fortnox_housework_choices();
$lines = jeab_ordrar_fortnox_editor_lines(hw_row()['fortnox_order_rows']);
$fx = jeab_ordrar_v3_fx_rows_html($lines, false);
hw_ok(str_contains($fx, '<span>Husarbete</span>') && str_contains($fx, 'class="v3-fxhead"'), 'kolumnhuvudet säger Husarbete');
hw_ok(!str_contains($fx, 'class="v3-lbl"') && !str_contains($fx, 'v3-seg') && !str_contains($fx, 'type="radio"'), 'ingen synlig radetikett och ingen radiogrupp');
hw_ok(preg_match('/<label class="v3-fx-hw"><span>Husarbete<\/span><select name="fx_hw\[0\]">/', $fx) === 1, 'husarbete är en select per radindex');
hw_ok(!str_contains($fx, 'name="fx_hw[]"'), 'namnet är inte en tom array');
foreach ($choices as $value => $label) {
    hw_ok(str_contains($fx, '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"'), 'alternativ ' . ($value === '' ? 'ingen' : $value));
    hw_ok(str_contains($fx, '>' . $label . '</option>'), 'text ' . $label);
}
hw_ok(str_contains($fx, '<option value="STORAGESELFPRODUCEDELECTRICITY" selected>'), 'sparad typ är vald');
hw_ok(substr_count($fx, '<select name="fx_hw[') === 4, 'varje rad har en select');

$html = jeab_ordrar_v3_html(hw_row(), 'jarfalla', 1914);
$formAt = strpos($html, 'id="v3-f-faktura"');
$formEnd = $formAt === false ? false : strpos($html, '</form>', $formAt);
hw_ok($formAt !== false && $formEnd !== false, 'fakturasteget ritas');
$form = substr($html, (int) $formAt, (int) $formEnd - (int) $formAt);
hw_ok(str_contains($form, 'name="fx_hw[0]"') && str_contains($form, 'value="STORAGESELFPRODUCEDELECTRICITY" selected'), 'sidan har select med vald lagring');
$fxAt = strpos($form, 'class="v3-fxlines"');
$fxEnd = strpos($form, 'class="v3-fxpay"', (int) $fxAt);
$fxForm = ($fxAt !== false && $fxEnd !== false) ? substr($form, $fxAt, $fxEnd - $fxAt) : '';
hw_ok($fxForm !== '' && !str_contains($fxForm, 'class="v3-lbl"') && !str_contains($fxForm, 'v3-seg'), 'fakturaraderna upprepar inte HUSARBETE som v3-lbl');
hw_ok(str_contains($form, 'data-gt-rates="') && str_contains($form, 'SOLARCELLS') && str_contains($form, '0.15') && str_contains($form, 'data-gt-cap="50000"') && str_contains($form, 'data-gt-people="1"'), 'grön teknik-satserna följer med formuläret');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar-v3.css');
$mobileAt = strpos($css, '@media (max-width: 760px)');
hw_ok($mobileAt !== false, 'mobilbrytpunkten finns');
$desktop = substr($css, 0, (int) $mobileAt);
hw_ok(preg_match('/\.ov3 \.v3-fxbody label > span \{[^}]*position:\s*absolute/s', $desktop) === 1, 'desktop döljer radetiketten');
hw_ok(str_contains($desktop, '.ov3 .v3-fxhead { display: grid;'), 'desktop visar kolumnhuvudet');
hw_ok(str_contains($desktop, '.ov3 .v3-fx-hw select { width: 100%; max-width: 100%; }'), 'selecten fyller kolumnen');
$mobileEnd = strpos($css, '@media (max-width: 390px)', (int) $mobileAt);
$mobile = substr($css, (int) $mobileAt, ($mobileEnd === false ? strlen($css) : $mobileEnd) - (int) $mobileAt);
hw_ok(str_contains($mobile, '.ov3 .v3-tbl, .ov3 .v3-fxhead { display: none; }'), 'mobilen gömmer kolumnhuvudet');
hw_ok(str_contains($mobile, '.ov3 .v3-fxbody label > span { position: static;'), 'mobilen visar radetiketten');
hw_ok(str_contains($mobile, '.ov3 .v3-fx-hw select { width: 100%; min-height: 44px;'), 'mobilens select är vanlig bredd');

$existing = hw_row()['fortnox_order_rows'];
$posted = jeab_ordrar_fortnox_posted_lines([
    'fx_rowid' => ['1', '', '', ''],
    'fx_article' => ['BAT', '', '', ''],
    'fx_desc' => ['Batteri', '', '', ''],
    'fx_hw' => ['SOLARCELLS', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['10000', '', '', ''],
]);
$saved = jeab_ordrar_fortnox_merge_order_rows($existing, $posted);
hw_ok(($saved[0]['HouseWorkType'] ?? '') === 'SOLARCELLS' && ($saved[0]['HouseWork'] ?? null) === true, 'vald typ sparas på orderraden');
hw_ok((int) ($saved[0]['Price'] ?? 0) === 10000, 'à-priset lämnas');

$storagePay = jeab_ordrar_fx_lines_att_betala($existing, 1);
$solarPay = jeab_ordrar_fx_lines_att_betala($saved, 1);
hw_ok($storagePay === 5000, 'lagring 50 % ger Att betala 5 000, fick ' . $storagePay);
hw_ok($solarPay === 8500, 'solceller 15 % ger Att betala 8 500, fick ' . $solarPay);
hw_ok($storagePay !== $solarPay, 'Att betala ändras när typen ändras');

$cleared = jeab_ordrar_fortnox_merge_order_rows($existing, jeab_ordrar_fortnox_posted_lines([
    'fx_rowid' => ['1', '', '', ''],
    'fx_article' => ['BAT', '', '', ''],
    'fx_desc' => ['Batteri', '', '', ''],
    'fx_hw' => ['', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['10000', '', '', ''],
]));
hw_ok(($cleared[0]['HouseWork'] ?? null) === false && ($cleared[0]['HouseWorkType'] ?? '') === 'EMPTYHOUSEWORK', 'Ej husarbete sparas som tom typ');
hw_ok(jeab_ordrar_fx_lines_att_betala($cleared, 1) === 10000, 'ingen typ ger hela radsumman');

$other = $saved;
$other[0]['HouseWork'] = false;
$other[0]['HouseWorkType'] = 'OTHERCOSTS';
hw_ok(jeab_ordrar_fx_lines_att_betala($other, 1) === 10000, 'övrig kostnad drar inte grön teknik');
$rot = $saved;
$rot[0]['HouseWork'] = true;
$rot[0]['HouseWorkType'] = 'ROT';
hw_ok(jeab_ordrar_fx_lines_att_betala($rot, 1) === 10000, 'ROT ligger inte i grön teknik-satserna');
$capped = [[
    'Price' => 200000,
    'DeliveredQuantity' => '1',
    'HouseWork' => true,
    'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
]];
hw_ok(jeab_ordrar_fx_lines_att_betala($capped, 1) === 150000, 'en person takar avdraget vid 50 000');
hw_ok(jeab_ordrar_fx_lines_att_betala($capped, 2) === 100000, 'två personer tar hela 50 %-avdraget');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
hw_ok(str_contains($js, 'function paintFxPayable()') && str_contains($js, 'select[name^="fx_hw["]'), 'bytet räknar om Att betala i vyn');

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_HW_TMP') ?: '';
$root = getenv('JEAB_HW_ROOT') ?: '';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
require $root . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
$row = [
    'reminder_id' => 1914,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Nils Nilsson',
    'address' => 'Testgatan 1, 175 00 Järfälla',
    'huvudsokande' => 'Nils Nilsson',
    'pris' => 5000,
    'kundpris' => 5000,
    'kalkyl' => ['sokande' => 1],
    'fortnox_order' => '1914',
    'fortnox_order_rows' => [[
        'RowId' => '1',
        'ArticleNumber' => 'BAT',
        'Description' => 'Batteri',
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
        'Price' => '10000',
    ]],
    'steps' => [],
];
header('Content-Type: text/html; charset=utf-8');
echo jeab_ordrar_v3_html($row, 'jarfalla', 1914);
PHP);

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
hw_ok($probe !== false, 'port för curl');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$log = $tmp . '/php.log';
$empty = $tmp . '/empty';
mkdir($empty);
$cmd = escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($empty)
    . ' ' . escapeshellarg($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), [
    'JEAB_HW_TMP' => $tmp,
    'JEAB_HW_ROOT' => dirname(__DIR__),
    'JEAB_DISABLE_SQLITE' => '1',
]);
hw_ok(is_resource($proc), 'php -S startade');
$up = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
hw_ok($up, 'php -S lyssnar');
try {
    $ch = curl_init('http://127.0.0.1:' . $port . '/ordrar?id=1914');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    hw_ok($code === 200 && is_string($body) && $body !== '', 'curl av fakturasidan svarar 200, fick ' . $code);
    hw_ok(str_contains((string) $body, '<select name="fx_hw[0]">') && str_contains((string) $body, 'value="STORAGESELFPRODUCEDELECTRICITY" selected'), 'curl visar selecten med vald typ');
    hw_ok(str_contains((string) $body, '>Solceller</option>') && str_contains((string) $body, '>Ej husarbete</option>'), 'curl visar typerna');
    $curlFxAt = strpos((string) $body, 'class="v3-fxlines"');
    $curlFxEnd = $curlFxAt === false ? false : strpos((string) $body, 'class="v3-fxpay"', $curlFxAt);
    $curlFx = ($curlFxAt !== false && $curlFxEnd !== false) ? substr((string) $body, $curlFxAt, $curlFxEnd - $curlFxAt) : '';
    hw_ok($curlFx !== '' && !str_contains($curlFx, 'class="v3-lbl"') && !str_contains($curlFx, 'v3-seg'), 'curl-sidan har ingen HUSARBETE-etikett per rad');
} finally {
    proc_terminate($proc);
    proc_close($proc);
}

echo "OK  ordrar fx hw select\n";
