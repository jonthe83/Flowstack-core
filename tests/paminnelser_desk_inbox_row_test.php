<?php

declare(strict_types=1);

/**
 * Påminnelser på skrivbord: listraden som inkorgen, filter på en rad,
 * Visa mer-innehållet överst i högerfältet.
 * Mobilkortet ligger i ett eget max-width 899px-block.
 * Öppet kort under 900 px: adressen tar hela bredden och radbryts.
 * Skrivbordets min-width 900px-block låses med en inbäddad hash, utan git.
 */

$tmp = sys_get_temp_dir() . '/jeab-pamdesk-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
require dirname(__DIR__) . '/src/pam_desk.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET['p'] = 'reminders';
$_GET['co'] = 'jarfalla';

function deskok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function desk_media_blocks(string $css, string $kind): string
{
    $out = '';
    $offset = 0;
    $needle = '@media (' . $kind . '-width:';
    while (($at = strpos($css, $needle, $offset)) !== false) {
        $start = $at;
        $depth = 0;
        $end = $start;
        $len = strlen($css);
        for ($i = $start; $i < $len; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        $out .= substr($css, $start, $end - $start + 1) . "\n";
        $offset = $end + 1;
    }

    return $out;
}

/** @return list<string> */
function desk_blocks(string $packed): array
{
    $packed = trim($packed);
    if ($packed === '') {
        return [];
    }
    $chunks = preg_split("/\n(?=@media )/", $packed);

    return is_array($chunks) ? array_values($chunks) : [];
}

function desk_blocks_matching(string $css, string $queryNeedle): string
{
    $out = '';
    foreach (desk_blocks(desk_media_blocks($css, 'min')) as $block) {
        $brace = strpos($block, '{');
        $query = $brace === false ? $block : substr($block, 0, $brace);
        if (str_contains($query, $queryNeedle)) {
            $out .= $block . "\n";
        }
    }

    return $out;
}

$store = jeab_store();
$row = $store->insertReminder([
    'title' => 'Batteri – Nytt tak till villan',
    'customer_name' => 'Ada Ärende',
    'email' => 'ada.pam@example.test',
    'phone' => '0701234567',
    'address' => 'Storgatan 12',
    'ort' => 'Sollentuna',
    'company' => 'jarfalla',
    'tags' => ['batteri'],
    'note' => 'Hej, vi vill ha hjälp med taket och undrar när ni kan komma förbi.',
    'due_at' => '2026-10-20 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$id = (int) ($row['id'] ?? 0);
deskok($id > 0, 'påminnelsen skapas');
$full = $store->getReminder($id);
deskok(is_array($full), 'raden läses tillbaka');

$closed = jeab_reminder_row_closed($full);
deskok(str_contains($closed, 'class="item-shut-addr"') && str_contains($closed, 'class="item-shut-name"'), 'mobilens adress och namn finns kvar');
$faceAt = strpos($closed, 'class="pam-ix"');
$nameAt = strpos($closed, 'class="pam-ix-nm"');
$prodAt = strpos($closed, 'class="pam-ix-prod"');
$dateAt = strpos($closed, 'class="pam-ix-dt"');
$addrAt = strpos($closed, 'class="pam-ix-addr"');
$lineAt = strpos($closed, 'class="pam-ix-l2"');
$subAt = strpos($closed, 'class="pam-ix-sub"');
$preAt = strpos($closed, 'class="pam-ix-pre"');
deskok($faceAt !== false && $nameAt !== false && $nameAt < $dateAt && $dateAt < $addrAt && $addrAt < $lineAt && $lineAt < $preAt, 'skrivbordsraden är namn, datum, adress, ämne, text');
deskok(str_contains($closed, '>Ada Ärende<') && str_contains($closed, 'Storgatan 12, Sollentuna') && str_contains($closed, 'Nytt tak till villan') && str_contains($closed, 'hjälp med taket'), 'namn, adress, ämne och text är ifyllda');
deskok($prodAt !== false && $lineAt < $prodAt && $prodAt < $subAt && $subAt < $preAt && str_contains($closed, '>Batteri<'), 'produktmärket ligger på ämnesraden');
deskok(!str_contains($closed, 'Skicka offert') && !str_contains($closed, 'reminder.send_quote'), 'listan har ingen Skicka offert');

$open = jeab_reminder_row($full, true);
$headAt = strpos($open, 'item-tags-head');
$panelAt = strpos($open, 'item-more-panel');
deskok($headAt !== false && $panelAt !== false && $headAt < $panelAt && str_contains($open, '>Visa mer<'), 'Visa mer ligger kvar i kortet');
deskok(str_contains(substr($open, $panelAt), 'boka tid i kalendern') && str_contains(substr($open, $panelAt), 'name="tag" value="vunnen"') && str_contains(substr($open, $panelAt), 'Ansvarig'), 'kalender, taggar och ansvarig ligger kvar under Visa mer');
deskok(!str_contains($open, 'Skicka offert'), 'öppet kort har ingen Skicka offert');

$_GET['open'] = (string) $id;
$side = jeab_pam_side_payload($store);
$sideHtml = (string) ($side['html'] ?? '');
$toolsAt = strpos($sideHtml, 'class="pam-side-tools"');
$contactAt = strpos($sideHtml, '>Kontakt<');
deskok(($side['ok'] ?? false) === true && $toolsAt !== false && $contactAt !== false && $toolsAt < $contactAt, 'verktygen ligger överst i högerfältet');
$tools = substr($sideHtml, $toolsAt, $contactAt - $toolsAt);
deskok(str_contains($tools, 'boka tid i kalendern') && str_contains($tools, 'name="tag" value="vunnen"') && str_contains($tools, 'name="tag" value="admin"') && str_contains($tools, 'Ansvarig'), 'högerfältet har kalender, taggar och ansvarig');
deskok(!str_contains($sideHtml, 'Skicka offert'), 'högerfältet har ingen Skicka offert');

$pin = jeab_pam_desk_pin($full);
deskok(str_contains($pin, 'class="pam-ix"') && str_contains($pin, 'class="pam-desk-pin ') && str_contains($pin, 'Storgatan 12, Sollentuna'), 'nålen använder samma rad');

$cssPath = dirname(__DIR__) . '/assets/css/paminnelser.css';
$css = (string) file_get_contents($cssPath);
$max = desk_media_blocks($css, 'max');
$cardBlocks = array_values(array_filter(
    desk_blocks($max),
    static fn (string $block): bool => str_contains($block, 'font-size: 28px')
));
deskok(count($cardBlocks) === 1, 'det stora märket finns i ett enda mobilblock');
$card = $cardBlocks[0];
deskok(str_starts_with($card, '@media (max-width: 899px)') && !str_contains($card, 'max-width: 390px') && str_contains($card, '.item-call') && str_contains($card, 'display: none !important') && str_contains($card, 'button.item-due-chip') && str_contains($card, 'width: 100%'), 'under 900 px döljs telefon och datum och datumet får en egen rad');
deskok(str_contains($card, 'textarea.item-title') && str_contains($card, 'width: 100%') && str_contains($card, 'min-width: 0') && str_contains($card, 'flex-direction: column') && str_contains($card, 'white-space: pre-wrap') && str_contains($card, 'field-sizing: content') && str_contains($card, 'flex: 1 1 100%'), 'öppet kort: adressfältet tar hela bredden och radbryts');
deskok(preg_match('/<textarea\b[^>]*name="address"[^>]*>[^<]*Storgatan 12[^<]*<\/textarea>/u', $open) === 1 && !preg_match('/<input\b[^>]*name="address"/', $open), 'öppet kort visar hela adressen i en textarea');
deskok(preg_match('/<input\b[^>]*name="customer"/', $open) === 1 && str_contains($open, 'name="action" value="reminder.update"'), 'namnfältet och sparningen är kvar');
$longAddr = 'Blomsterkungsvägen 90, Hässelby';
$updated = jeab_update_reminder($store, $id, ['address' => $longAddr], true);
$again = $store->getReminder($id);
deskok(is_array($updated) && (string) ($updated['address'] ?? '') === $longAddr && is_array($again) && (string) ($again['address'] ?? '') === $longAddr, 'hela adressen sparas oförändrad');
$openLong = jeab_reminder_row(is_array($again) ? $again : [], true);
deskok(preg_match('/<textarea\b[^>]*name="address"[^>]*>' . preg_quote($longAddr, '/') . '<\/textarea>/u', $openLong) === 1, 'öppet kort visar Blomsterkungsvägen 90, Hässelby');
$desk900 = desk_blocks_matching($css, 'min-width: 900px');
deskok($desk900 !== '' && hash('sha256', $desk900) === '213fb0959977c4471b959ea4a807b2a669f7aff563c13f9914049aa1d2b8be99', 'skrivbordets 900px-block är oförändrade');
deskok(!str_contains($max, '.pam-ix') && !str_contains($max, 'pam-side-tools') && !str_contains($max, 'grid-area: search'), 'de nya reglerna ligger inte i mobil-CSS');
$min900 = desk_media_blocks($css, 'min');
deskok(str_contains($min900, 'grid-area: search') && str_contains($min900, 'grid-area: tags') && str_contains($min900, 'grid-area: who') && str_contains($min900, '.pam-ix-pre') && str_contains($min900, 'line-clamp: 2') && str_contains($min900, 'min-height: 180px'), 'skrivbordet samlar filter och ger mitten mer höjd');
deskok(str_contains($min900, '"stats who tags search"'), 'ordningen är Idag, Mina/Alla, taggar, sök');
deskok(preg_match('/form\.apple-search \{[^}]*height:\s*44px;/', $min900) === 1 && str_contains($min900, 'form.apple-search .crm-search-ico') && str_contains($min900, 'left: 12px;'), 'förstoringsglaset ligger i sökrutan längst till höger');
$whoChip = [];
deskok(preg_match('/\.crm-who \.chip \{([^}]+)\}/', $min900, $whoChip) === 1, 'Mina/Alla har en egen skrivbordsregel');
deskok(
    str_contains($whoChip[1], 'height: 44px;')
        && str_contains($whoChip[1], 'min-height: 44px;')
        && str_contains($whoChip[1], 'padding: 0 12px;')
        && str_contains($whoChip[1], 'font-size: 14px;')
        && str_contains($whoChip[1], 'font-weight: 400;')
        && str_contains($whoChip[1], 'border-radius: 999px !important;'),
    'Mina/Alla har samma höjd, padding, typsnitt och radie som taggarna'
);
deskok(str_contains($min900, '.pam-mid .item-addr-row') && str_contains($min900, 'margin-left: 48px;') && str_contains($min900, '.pam-mid .item.is-open > .check-form') && str_contains($min900, 'field-sizing: fixed') && str_contains($min900, '.pam-mid .item.is-open .snooze') && str_contains($min900, 'order: 5;'), 'adressen ligger vid ringen och 2h-raden ligger under den växande anteckningen');
deskok(str_contains($css, '.pam-board:not(.is-side-collapsed) .pam-mid .item-foot') && str_contains($css, '@media (min-width: 1280px)'), 'Visa mer döljs bara när högerfältet är öppet');

$http = sys_get_temp_dir() . '/jeab-pamdesk-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/rad.html', $closed . $sideHtml);
file_put_contents($http . '/paminnelser.css', $css);
$port = 0;
for ($try = 8261; $try < 8280; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
deskok($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
deskok(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/rad.html');
    if (is_string($probe) && str_contains($probe, 'pam-ix')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
deskok($ready, 'sidan svarar');
$curlPage = curl_init('http://127.0.0.1:' . $port . '/rad.html');
curl_setopt_array($curlPage, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$pageBody = (string) curl_exec($curlPage);
$pageCode = (int) curl_getinfo($curlPage, CURLINFO_HTTP_CODE);
curl_close($curlPage);
$curlCss = curl_init('http://127.0.0.1:' . $port . '/paminnelser.css');
curl_setopt_array($curlCss, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$cssBody = (string) curl_exec($curlCss);
$cssCode = (int) curl_getinfo($curlCss, CURLINFO_HTTP_CODE);
curl_close($curlCss);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
deskok($pageCode === 200 && str_contains($pageBody, 'class="item-shut-addr"') && str_contains($pageBody, 'class="pam-side-tools"') && str_contains($pageBody, 'Storgatan 12, Sollentuna'), 'curl ser mobilraden och högerfältet, fick ' . $pageCode);
deskok($cssCode === 200 && str_contains($cssBody, '@media (max-width: 639px)') && str_contains($cssBody, 'grid-area: search'), 'curl ser mobil- och skrivbords-CSS, fick ' . $cssCode);

echo "OK  skrivbord\n";
