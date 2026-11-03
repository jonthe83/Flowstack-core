<?php

declare(strict_types=1);

/**
 * app.js bara där den binder. Typsnitt från egen domän, font-src self.
 * Ingen kunddata.
 */

$tmp = sys_get_temp_dir() . '/jeab-assets-pages-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function ap_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($tmp);
    session_start();
}
$_SESSION['jeab_company'] = 'jarfalla';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$root = dirname(__DIR__);
$need = ['reminders', 'reminder', 'bookings', 'offert', 'kalkyl', 'ordrar', 'rot', 'manuell-order'];
$skip = ['links', 'notes', 'notiser', 'utskick', 'inkorg', 'inkorg-regler', 'inkorg-vyer', 'kontakter', 'purchase', 'zapier', 'logg', 'bugg'];
ap_ok(jeab_app_js_pages() === $need, 'app.js-listan är sidorna som binder');
foreach ($need as $page) {
    ap_ok(jeab_page_needs_app_js($page), $page . ' laddar app.js');
}
foreach ($skip as $page) {
    ap_ok(!jeab_page_needs_app_js($page), $page . ' hoppar över app.js');
}

ap_ok(jeab_fonts_hosted_locally(), 'alla sex woff2 finns');
$bytes = 0;
foreach (jeab_self_hosted_font_rels() as $rel) {
    $file = $root . '/' . $rel;
    $head = (string) file_get_contents($file, false, null, 0, 4);
    ap_ok($head === 'wOF2', $rel . ' är woff2');
    $bytes += (int) filesize($file);
}
ap_ok(is_file($root . '/assets/fonts/OFL-Inter.txt') && is_file($root . '/assets/fonts/OFL-IBMPlex.txt'), 'OFL följer med typsnitten');

$faces = jeab_font_face_css();
ap_ok($faces !== '' && str_contains($faces, 'font-family:"Inter"') && str_contains($faces, 'font-family:"IBM Plex Mono"'), 'lokala @font-face');
ap_ok(str_contains($faces, 'font-display:swap'), 'saknad fil faller tillbaka till nästa font-family');
ap_ok(substr_count($faces, 'font-weight:400') === 2 && str_contains($faces, 'font-weight:700'), 'Inter 400–700 och Plex 400/500');
ap_ok(!str_contains(jeab_font_link_tags(), 'fonts.googleapis.com'), 'länken pekar inte på Google när filerna finns');
ap_ok(str_contains(jeab_font_link_tags(), 'assets/fonts/inter-latin-400.woff2'), 'typsnittet versioneras som övriga assets');
ap_ok(str_contains(jeab_font_link_tags(), 'rel="preload"') && str_contains(jeab_font_link_tags(), 'as="font"'), 'brödtextens woff2 förhämtas');
$layoutSrc = (string) file_get_contents($root . '/src/layout.php');
ap_ok(!str_contains($layoutSrc, 'fonts.googleapis.com') && !str_contains($layoutSrc, 'media="print"'), 'ingen render-blockerande Google-CSS');

$csp = jeab_csp_value();
ap_ok(str_contains($csp, "font-src 'self'") && !str_contains($csp, 'fonts.gstatic.com'), 'font-src self');
$open = jeab_csp_value('self', false);
ap_ok(!str_contains($open, 'fonts.googleapis.com') && !str_contains($open, 'fonts.gstatic.com'), 'CSP öppnar inte Google även om ett argument säger det');

function ap_script(string $html, string $file): bool
{
    return preg_match('#<script[^>]+src="[^"]*' . preg_quote($file, '#') . '[?"]#', $html) === 1;
}

function ap_html(string $page): string
{
    unset($GLOBALS['_JEAB_LAYOUT_OPEN']);
    ob_start();
    jeab_layout(ucfirst($page) . ' · JEAB', $page, '<p>test</p>');
    return (string) ob_get_clean();
}

$links = ap_html('links');
ap_ok(!ap_script($links, 'assets/js/app.js') && !ap_script($links, 'assets/js/optimistic.js'), 'Övrigt saknar app.js och optimistic.js');
ap_ok(str_contains($links, 'assets/js/shell.js') && str_contains($links, 'assets/js/poll-lock.js'), 'Övrigt behåller skal och poll-lås');
ap_ok(str_contains($links, 'jeabApplyTheme') && str_contains($links, 'data-js="theme-toggle"'), 'temaknappen binds utan app.js');
ap_ok(str_contains($links, 'id="jeab-app-js-pages"') && str_contains($links, '"bookings"'), 'skalet får sidlistan');
ap_ok(str_contains($links, '@font-face') && !str_contains($links, 'fonts.googleapis.com'), 'Övrigt ritar lokala typsnitt');

$cal = ap_html('bookings');
ap_ok(ap_script($cal, 'assets/js/app.js') && ap_script($cal, 'assets/js/optimistic.js'), 'Kalender laddar app.js efter optimistic.js');
$optAt = strpos($cal, 'assets/js/optimistic.js?');
$appAt = strpos($cal, 'assets/js/app.js?');
ap_ok($optAt !== false && $appAt !== false && $optAt < $appAt, 'optimistic.js kommer före app.js');

$orders = ap_html('ordrar');
ap_ok(ap_script($orders, 'assets/js/app.js'), 'Ordrar laddar app.js');
$inkorg = ap_html('inkorg');
ap_ok(!ap_script($inkorg, 'assets/js/app.js'), 'Inkorg hoppar över app.js');
$kontakter = ap_html('kontakter');
ap_ok(!ap_script($kontakter, 'assets/js/app.js'), 'Kontakter hoppar över app.js');

$appJs = (string) file_get_contents($root . '/assets/js/app.js');
ap_ok(!str_contains($appJs, 'jeab_theme='), 'app.js sätter inte temacookien längre');
ap_ok(str_contains($appJs, 'function jeabBootCalendar') && str_contains($appJs, 'function jeabBootMall'), 'kalender och mall binder kvar i app.js');

$shell = (string) file_get_contents($root . '/assets/js/shell.js');
ap_ok(str_contains($shell, 'function ensurePageScripts') && str_contains($shell, 'function pageNeedsAppJs'), 'skalet hämtar app.js vid sidbyte');
ap_ok(str_contains($shell, "loadScriptOnce('/assets/js/optimistic.js')") && str_contains($shell, "loadScriptOnce('/assets/js/app.js')"), 'skalet laddar optimistic.js före app.js');
ap_ok(str_contains($shell, 'rel = \'preload\'') || str_contains($shell, 'rel = "preload"'), 'hovring förhämtar scriptet utan att köra det');
ap_ok(strpos($shell, "loadScriptOnce('/assets/js/optimistic.js')") < strpos($shell, "loadScriptOnce('/assets/js/app.js')"), 'ordningen i skalet är optimistic sedan app');

$fullmakt = (string) file_get_contents($root . '/fullmakt/lib/view.php');
ap_ok(!str_contains($fullmakt, 'fonts.googleapis.com'), 'fullmakt ber inte Google om Great Vibes');
ap_ok(is_file($root . '/fullmakt/assets/fonts/GreatVibes-Regular.woff2'), 'underskriftens typsnitt ligger kvar lokalt');

$appBytes = (int) filesize($root . '/assets/js/app.js');
$optBytes = (int) filesize($root . '/assets/js/optimistic.js');
echo 'BYTES app.js=' . $appBytes . ' optimistic.js=' . $optBytes . ' fonts=' . $bytes . "\n";
echo "assets pages: ok\n";
