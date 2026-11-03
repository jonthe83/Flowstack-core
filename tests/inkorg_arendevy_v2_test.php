<?php

declare(strict_types=1);

/**
 * Ärendevy v2: funktionerna finns kvar, typ och produkt sparas som feedback,
 * och kundpanelen går att öppna på mobil. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-arendevy-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
session_save_path($tmp);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function av(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$fast = (string) file_get_contents($root . '/src/inkorg_fast.php');
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
av($page !== '' && $fast !== '' && $css !== '', 'källorna läses');

foreach ([
    'inkorg.trash',
    'inkorg.restore',
    'inkorg.flag',
    'inkorg.read',
    'inkorg.send_quote',
    'inkorg.relabel',
    'inkorg.reply',
    'inkorg.approve',
    'inkorg.draft_delete',
    'data-orig-all',
    'id="ix-force-close"',
    'Skicka och stäng',
    'Skicka som öppet',
    'Skicka som parkerat',
    'Spara utkast',
    'Anteckning',
    'Tilldelad',
    'Kontakt',
    'template_only',
    'Flytta till papperskorgen',
    'Markera som skräp',
    'Förhandsgranska offert',
    'Visa original',
    'aria-label="Mer"',
    'data-ix-scope="egna"',
    'data-ix-scope="allas"',
    'crm-stats',
    'name="type"',
    'value="sol"',
    'value="batteri"',
    'value="elbilsladdare"',
    "'Spam'",
    'id="ix-kund-body"',
    'data-kund-toggle',
] as $needle) {
    av(str_contains($page, $needle), 'sidan har kvar ' . $needle);
}
av(!str_contains($page, '<h2>Etiketter</h2>') && !str_contains($page, 'id="ix-cls-panel"'), 'etikettblocket ovanför mejlet är borta');
av(str_contains($fast, 'class="ix-l1"') && str_contains($fast, 'class="ix-l2"') && str_contains($fast, 'data-ix-act="read"') && str_contains($fast, 'data-ix-act="close"') && str_contains($fast, 'class="ix-prod"'), 'listraden är två rader, en produkt och dolda listknappar');
av(str_contains($css, 'display: none !important') && str_contains($css, '.ix-acts'), 'listknapparna visas inte');
av(str_contains($css, 'minmax(240px, 340px)') && str_contains($css, 'minmax(260px, 340px)') && str_contains($css, '.ix:not(.is-open) > .ix-split'), 'bredderna är 240, 260 och hel lista utan ärende');
av(str_contains($css, 'min-width: max-content') && str_contains($css, 'text-overflow: clip'), 'vyernas namn klipps inte');
av(str_contains($css, 'flex: 0 0 auto') && str_contains($css, 'min-height: min-content') && str_contains($css, 'overflow-y: hidden'), 'räknarraden har automatisk höjd och ingen lodrät scroll');
av(str_contains($css, '.ix-l1 .ix-nm') && str_contains($css, '.ix-l1 .ix-dt') && str_contains($css, 'margin-left: auto'), 'radens namn är fast och tiden ligger till höger');
av(str_contains($css, '.ix-btn.is-primary:disabled') && str_contains($css, 'cursor: not-allowed'), 'avstängd Skicka offert är grå och not-allowed');
av(str_contains($fast, '<span class="ix-prod"') && strpos($fast, 'class="ix-l2"') < strpos($fast, 'class="ix-prod"'), 'produktchippen ligger på rad två');
av(str_contains($page, '>Alla</a>') && !str_contains($page, '>Allas</a>') && str_contains($page, 'data-ix-scope="allas"'), 'filtret heter Alla');

$mobile = false;
if (preg_match_all('/@media \(max-width: 760px\) \{/', $css, $marks, PREG_OFFSET_CAPTURE)) {
    foreach ($marks[0] as $mark) {
        $start = (int) $mark[1];
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
        $block = substr($css, $start, $end - $start);
        if (str_contains($block, '.ix-kund-toggle') && str_contains($block, '.ix-kund-body[hidden]') && str_contains($block, 'display: none !important')) {
            $mobile = true;
        }
    }
}
av($mobile, 'mobilen har en fällbar kundpanel');

$deskOpen = false;
if (preg_match('/@media \(min-width: 761px\) \{/', $css, $deskMark, PREG_OFFSET_CAPTURE) === 1) {
    $start = (int) $deskMark[0][1];
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
    $block = substr($css, $start, $end - $start);
    $deskOpen = str_contains($block, '.ix-kund-body[hidden]') && str_contains($block, 'display: block !important');
}
av($deskOpen, 'kundpanelen är öppen på skrivbord');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$taken = jeab_inkorg_ingest([
    'message_id' => 'arendevy@test',
    'from' => 'kund.arende@exempel.test',
    'from_name' => 'Ada Ärende',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert på laddbox',
    'body_text' => "Namn: Ada Ärende\nE-post: kund.arende@exempel.test\nVi vill ha en laddbox.\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-arendevy',
    'thread_key' => 'arendevy-1',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 90,
        'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'elbilsladdare',
    ],
]);
av(!empty($taken['ok']), 'ärendet tas in');
$tid = (string) $taken['thread_id'];
$caseId = $tid;
$thread = [];
foreach (jeab_inkorg_threads() as $row) {
    if ((string) ($row['id'] ?? '') === $tid) {
        $thread = $row;
        break;
    }
}
$mid = (string) (($thread['last']['id'] ?? '') ?: '');
av($mid !== '', 'senaste mejlet har id');

$before = count($GLOBALS['_SENT']);
$_POST = ['id' => $mid, 'type' => 'Service'];
av(jeab_inkorg_action('inkorg.relabel') === 'Sparat – Grok lär sig av detta', 'typ sparas som feedback');
$afterType = [];
foreach (jeab_inkorg_threads() as $row) {
    if ((string) ($row['id'] ?? '') === $tid) {
        $afterType = $row;
        break;
    }
}
av((string) (($afterType['last']['labels']['type'] ?? '')) === 'Service', 'typen är Service');
av(count($GLOBALS['_SENT']) === $before, 'typsparning skickar inget');

$_POST = [
    'thread' => $tid,
    'status_ui' => '1',
    'case_product' => '1',
    'status' => 'open',
    'tags_present' => '1',
    'tags' => ['sol'],
];
av(jeab_inkorg_action('inkorg.thread') === 'Sparat – Grok lär sig av detta', 'produkt sparas som feedback');
$afterProduct = [];
foreach (jeab_inkorg_threads() as $row) {
    if ((string) ($row['id'] ?? '') === $tid) {
        $afterProduct = $row;
        break;
    }
}
av(in_array('sol', array_map('strval', (array) ($afterProduct['tags'] ?? [])), true), 'produkten ligger som sol-etikett');
av((string) (($afterProduct['last']['labels']['product'] ?? '')) === 'sol', 'produktfältet är sol');
av((string) (($afterProduct['last']['labels']['type'] ?? '')) === 'Service', 'produktvalet lämnar typen');
av(count($GLOBALS['_SENT']) === $before, 'produktsparning skickar inget');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $tid];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$html = (string) include $root . '/src/pages/inkorg.php';
av(str_contains($html, 'id="ix-kund-body"') && str_contains($html, 'value="Service"') && str_contains($html, '>Service<') && str_contains($html, '>Information<') && str_contains($html, '>Spam<') && str_contains($html, 'value="sol" selected'), 'öppet ärende visar sparad typ och produkt');
$reminders = substr_count($html, '>Påminnelse<') + substr_count($html, '>Skapa påminnelse<');
av($reminders === 1, 'en påminnelseknapp, fick ' . $reminders);
av(str_contains($html, 'data-ix-scope="egna"') && str_contains($html, 'class="smart-grid crm-stats ix-folders"'), 'räknare och Egna/Alla finns kvar');
av(str_contains($html, '>Alla</a>') && !str_contains($html, '>Allas</a>'), 'filtret på sidan heter Alla');
$kundAt = strpos($html, 'id="ix-kund-body"');
$kundEnd = strpos($html, '</aside>', $kundAt === false ? 0 : $kundAt);
$kund = $kundAt !== false && $kundEnd !== false ? substr($html, $kundAt, $kundEnd - $kundAt) : '';
av(str_contains($kund, '>Skapa påminnelse</a>') && !str_contains($kund, 'class="ix-prev ix-reminder"'), 'utan påminnelse finns Skapa påminnelse');
av(str_contains($kund, 'class="ix-btn is-primary"') && !preg_match('/class="ix-btn is-primary" type="submit" disabled>/', $kund), 'offertknappen är aktiv när produkt och mejl finns');

$list = jeab_inkorg_fast_list_html([[
    'id' => 'rad-jl',
    'assignee' => 'saml',
    'status' => 'open',
    'company' => 'jarfalla',
    'updated_at' => '',
    'mail_at' => '2026-10-27T07:15:00+00:00',
    'offer' => 'laddbox',
    'first' => ['subject' => 'Offert på laddbox till villan', 'from' => 'ada@exempel.test'],
    'last' => ['from_name' => 'Ada Ärende', 'from' => 'ada@exempel.test', 'received_at' => '2026-10-27T07:15:00+00:00'],
    'read' => ['me' => '2026-10-27T08:00:00+00:00'],
]], '', 'me', ['p' => 'inkorg'], ['saml' => 'Sam L'], 1, 1);
$l1At = strpos($list, 'class="ix-l1"');
$l2At = strpos($list, 'class="ix-l2"');
av($l1At !== false && $l2At !== false && $l1At < $l2At, 'listraden har två rader');
$line1 = substr($list, $l1At, $l2At - $l1At);
$line2 = substr($list, $l2At, 220);
av(str_contains($line1, 'class="ix-nm"') && str_contains($line1, 'Ada Ärende') && str_contains($line1, 'class="ix-dt"') && !str_contains($line1, '>Elbilsladdare<'), 'rad ett har namn och tid');
av(preg_match('/class="ix-dt">([^<]+)</', $line1, $time) === 1 && trim($time[1]) !== '', 'tiden är ifylld även utan updated_at');
av(str_contains($line2, 'class="ix-sub"') && str_contains($line2, 'Offert på laddbox till villan') && str_contains($line2, '>Elbilsladdare<'), 'rad två har produkt och rubrik');
av(str_contains($list, 'class="ix-sr" data-ix-assignee') && str_contains($list, '>Sam L</span>'), 'ansvarig ligger utanför de två raderna');

$made = jeab_create_reminder(jeab_store(), 'Ada påminnelse', jeab_today_start(), null, null, [
    'customer' => 'Ada Ärende',
    'email' => 'kund.arende@exempel.test',
    'company' => 'jarfalla',
    'thread_id' => $caseId,
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode($caseId),
]);
$madeId = (int) ($made['id'] ?? 0);
av($madeId > 0, 'påminnelsen skapas');
jeab_store()->saveReminder($madeId, ['thread_id' => '']);
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $caseId];
$htmlRem = (string) include $root . '/src/pages/inkorg.php';
$kundAt = strpos($htmlRem, 'id="ix-kund-body"');
$kundEnd = strpos($htmlRem, '</aside>', $kundAt === false ? 0 : $kundAt);
$kundRem = $kundAt !== false && $kundEnd !== false ? substr($htmlRem, $kundAt, $kundEnd - $kundAt) : '';
av(str_contains($kundRem, 'class="ix-prev ix-reminder"') && str_contains($kundRem, '>Påminnelse</a>') && str_contains($kundRem, 'open=' . $madeId) && !str_contains($kundRem, '>Skapa påminnelse</a>'), 'befintlig påminnelse öppnas som post');

$http = sys_get_temp_dir() . '/jeab-arendevy-http-' . bin2hex(random_bytes(3));
mkdir($http, 0700, true);
file_put_contents($http . '/inkorg.css', $css);
file_put_contents($http . '/arende.html', $html);
$port = 0;
for ($try = 8311; $try < 8330; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
av($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $http],
    [1 => ['file', $http . '/server.log', 'a'], 2 => ['file', $http . '/server.log', 'a']],
    $pipes
);
av(is_resource($server), 'php-servern startade');
$ready = false;
for ($i = 0; $i < 20; $i++) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/inkorg.css');
    if (is_string($probe) && str_contains($probe, 'minmax(260px, 340px)')) {
        $ready = true;
        break;
    }
    usleep(100000);
}
av($ready, 'css-filen svarar');
$curl = curl_init('http://127.0.0.1:' . $port . '/inkorg.css');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$cssBody = (string) curl_exec($curl);
$cssCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
$pageCurl = curl_init('http://127.0.0.1:' . $port . '/arende.html');
curl_setopt_array($pageCurl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$pageBody = (string) curl_exec($pageCurl);
$pageCode = (int) curl_getinfo($pageCurl, CURLINFO_HTTP_CODE);
curl_close($pageCurl);
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
av($cssCode === 200 && str_contains($cssBody, '.ix-kund-body[hidden]') && str_contains($cssBody, 'minmax(240px, 340px)') && str_contains($cssBody, 'overflow-y: hidden') && str_contains($cssBody, 'cursor: not-allowed') && str_contains($cssBody, 'min-height: min-content'), 'curl ser layouten, fick ' . $cssCode);
av($pageCode === 200 && str_contains($pageBody, 'id="ix-kund-body"') && str_contains($pageBody, 'Flytta till papperskorgen') && str_contains($pageBody, 'Skicka och stäng'), 'curl ser ärendet, fick ' . $pageCode);

echo "ärendevy v2 ok\n";
