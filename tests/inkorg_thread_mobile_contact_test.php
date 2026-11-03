<?php

declare(strict_types=1);

/** N-29: telefon och e-post i trådhuvudet på mobil 390 px, utan att öppna kund-accordionen. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-ixmhc-' . bin2hex(random_bytes(4));
mkdir($tmp . '/sess', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
session_save_path($tmp . '/sess');
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function mhc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function mhc_media_blocks(string $css): array
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

function mhc_rule(string $body, string $selector): string
{
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
    if (preg_match($pattern, $body, $m) !== 1) {
        return '';
    }
    return $m[1];
}

function mhc_between(string $src, string $start, string $end): string
{
    $from = strpos($src, $start);
    $to = $from === false ? false : strpos($src, $end, $from + strlen($start));
    if ($from === false || $to === false) {
        return '';
    }
    return substr($src, $from, $to - $from);
}

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');

$head = mhc_between($page, 'class="ix-mhead-contact"', 'class="ix-card ix-mcust"');
mhc($head !== '', 'trådhuvudet har ix-mhead-contact före kund-accordionen');
mhc(str_contains($head, 'href="tel:<?= e($boxCust[\'phone\']) ?>"'), 'huvudet har tel:-länk från boxCust');
mhc(str_contains($head, 'href="mailto:<?= e((string) $em) ?>"'), 'huvudet har mailto:-länk från boxCust');
mhc(!str_contains($head, 'ix-mcust-body'), 'huvudets länkar ligger inte i den hopfällda accordionen');

$body = mhc_between($page, 'class="ix-mcust-body" hidden', '</div>');
mhc($body !== '' && str_contains($body, 'href="tel:') && str_contains($body, 'href="mailto:'), 'accordionen har fortfarande tel och mailto inne i hidden-kroppen');

$rowPos = strpos($page, '<div class="ix-row">');
$headPos = strpos($page, 'class="ix-mhead-contact"');
$accPos = strpos($page, 'class="ix-card ix-mcust"');
mhc($rowPos !== false && $headPos !== false && $accPos !== false, 'statusrad, kontaktlänkar och accordion finns');
mhc($rowPos < $headPos && $headPos < $accPos, 'kontaktlänkarna sitter i trådhuvudet mellan statusrad och accordion');

$blocks = mhc_media_blocks($css);
$mobile = null;
foreach ($blocks as $block) {
    if (str_contains($block['query'], 'max-width: 760px') && str_contains($block['body'], '.ix-mhead-contact a')) {
        $mobile = $block;
        break;
    }
}
mhc($mobile !== null, '760px-blocket stylar huvudets kontaktlänkar');
mhc(str_contains($mobile['query'], 'max-width: 760px'), 'brytpunkten täcker 390 px');

$link = mhc_rule($mobile['body'], 'body.page-inkorg .ix-v2 .ix-mhead-contact a');
$wrap = mhc_rule($mobile['body'], 'body.page-inkorg .ix-v2 .ix-mhead-contact');
$mcust = mhc_rule($mobile['body'], 'body.page-inkorg .ix-v2 .ix-mcust');
$side = mhc_rule($mobile['body'], 'body.page-inkorg .ix-v2 .ix-side');
mhc(str_contains($wrap, 'display: flex'), 'mobil visar kontaktlänkarna utan att öppna accordionen');
mhc(str_contains($link, 'min-height: 44px') && str_contains($link, 'min-width: 44px'), 'tel och mailto har minst 44 px träffyta');
mhc(str_contains($mcust, 'display: block'), 'accordionen visas fortfarande på mobil');
mhc(str_contains($side, 'display: none'), 'sidopanelen är dold på mobil');

$baseEnd = $mobile['start'];
$base = substr($css, 0, $baseEnd);
$baseHead = mhc_rule($base, 'body.page-inkorg .ix-v2 .ix-mhead-contact');
$baseMcust = mhc_rule($base, 'body.page-inkorg .ix-v2 .ix-mcust');
$baseSide = mhc_rule($base, 'body.page-inkorg .ix-v2 .ix-side');
mhc(str_contains($baseHead, 'display: none'), 'desktop döljer huvudets kontaktlänkar');
mhc(str_contains($baseMcust, 'display: none'), 'desktop döljer accordionen som tidigare');
mhc($baseSide === '' || !str_contains($baseSide, 'display: none'), 'desktop-sidopanelen är oförändrad');
mhc(!str_contains($base, 'body.page-inkorg .ix-v2 .ix-mhead-contact a'), 'desktop har ingen extra kontaktlayout');

$ing = jeab_inkorg_ingest([
    'message_id' => '<n29@example.test>',
    'from' => 'nora@example.test',
    'from_name' => 'Nora Exempel',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Batteri till testhus',
    'body_text' => "Hej, vill ha batteri.\nNora Exempel\nTestgatan 1, 123 45 Teststad\n070-123 45 67",
    'source' => 'test-n29',
    'test' => true,
]);
mhc(!empty($ing['ok']) && (string) ($ing['thread_id'] ?? '') !== '', 'testtråd med exempeluppgifter');
$tid = (string) $ing['thread_id'];

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $tid];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$html = (string) include $root . '/src/pages/inkorg.php';

$headHtml = mhc_between($html, 'class="ix-mhead-contact"', 'class="ix-card ix-mcust"');
$bodyHtml = mhc_between($html, 'class="ix-mcust-body" hidden', '</div>');
mhc($headHtml !== '', 'renderad tråd har kontaktlänkar i huvudet');
mhc(str_contains($headHtml, 'href="mailto:'), 'renderat huvud har mailto');
// Main plockar inte längre alltid telefon ur signaturen; tel krävs bara om accordionen har den.
mhc(!str_contains($bodyHtml, 'href="tel:') || str_contains($headHtml, 'href="tel:'), 'renderat huvud har tel när kunden har telefon');
mhc(str_contains($headHtml, 'nora@example.test'), 'renderat huvud visar testmejlen');
mhc(str_contains($html, 'class="ix-mcust-body" hidden'), 'accordionkroppen är hopfälld');
mhc(str_contains($bodyHtml, 'href="mailto:'), 'accordionen behåller mailto bakom hidden');
mhc(!str_contains($headHtml, 'ix-mcust-body'), 'renderade huvudlänkar kräver inte att accordionen öppnas');
mhc(!preg_match('/\b(?:19|20)\d{6}-\d{4}\b/', $html), 'ingen personnummersträng i utskriften');

echo "inkorg_thread_mobile_contact_test klart\n";
