<?php

declare(strict_types=1);

/**
 * Varje egen CSS/JS-adress i ritad HTML har ?v=innehållets hash.
 * Skalet byter ut en hårdkodad ?v=3 när det sätter in samma fil.
 */

$tmp = sys_get_temp_dir() . '/jeab-asset-url-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($tmp);
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_SESSION['jeab_company'] = 'jarfalla';
$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$GLOBALS['_asset_log'] = [];

function asset_ok(bool $ok, string $message): void
{
    if ($ok) {
        $GLOBALS['_asset_log'][] = "OK  {$message}";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

ob_start();
jeab_render_login(null, 0);
$login = (string) ob_get_clean();

function asset_expect(string $rel): string
{
    $file = JEAB_ROOT . '/' . ltrim($rel, '/');
    $hash = is_file($file) ? hash_file('sha256', $file) : false;

    return is_string($hash) ? substr($hash, 0, 12) : '0';
}

/** @return list<string> */
function asset_tag_urls(string $html): array
{
    $urls = [];
    if (preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel\s*=\s*"stylesheet"/i', $tag) !== 1) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*"([^"]+)"/i', $tag, $href)) {
                $urls[] = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }
    if (preg_match_all('/<script\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', $html, $scripts)) {
        foreach ($scripts[1] as $src) {
            $urls[] = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $urls;
}

function asset_assert_html(string $html, string $label): void
{
    $own = 0;
    foreach (asset_tag_urls($html) as $url) {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~(?:^|/)assets/(?:css|js)/[^/?#]+\.(?:css|js)$~', $path) !== 1) {
            continue;
        }
        $own++;
        $rel = ltrim($path, '/');
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        $got = (string) ($query['v'] ?? '');
        $expect = asset_expect($rel);
        asset_ok($got !== '' && $got === $expect, "{$label} {$rel} har v={$expect}, fick " . ($got === '' ? '(saknas) ' . $url : $got));
    }
    asset_ok($own > 0, "{$label} ritade minst en egen css/js");
}

$optimistic = 'assets/js/optimistic.js';
$pam = 'assets/css/paminnelser.css';
$nav = 'assets/js/inkorg-nav.js';
asset_ok(jeab_asset_url($optimistic) === jeab_url($optimistic) . '?v=' . asset_expect($optimistic), 'optimistic.js får sin egen innehållshash');
asset_ok(jeab_asset_url($pam) === jeab_url($pam) . '?v=' . asset_expect($pam), 'paminnelser.css får sin egen innehållshash');
asset_ok(jeab_asset_url($optimistic) !== jeab_asset_url($pam), 'två filer delar inte version');
asset_ok(jeab_asset($nav) === jeab_asset_url($nav), 'jeab_asset är samma hjälp');
asset_ok(
    jeab_asset_url($optimistic . '?v=3') === jeab_asset_url($optimistic)
        && jeab_asset_url($pam . '?foo=1&v=3') === jeab_url($pam) . '?foo=1&v=' . asset_expect($pam),
    'hårdkodad v=3 byts mot filens innehållshash'
);

foreach (jeab_asset_versions() as $rel => $mtime) {
    $url = jeab_asset_url($rel);
    asset_ok(str_contains($url, $rel . '?v=' . $mtime), "kartan och url stämmer för {$rel}");
}

asset_assert_html($login, 'inloggning');

$pages = ['reminders', 'reminder', 'purchase', 'kontakter', 'bookings', 'ordrar', 'notiser', 'utskick', 'notes', 'inkorg'];
foreach ($pages as $page) {
    $GLOBALS['_JEAB_ORDRAR_V3'] = $page === 'ordrar';
    $body = '';
    if ($page === 'inkorg') {
        $_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'co' => 'jarfalla'];
        $body = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
    }
    if ($page === 'ordrar') {
        $company = 'jarfalla';
        $open = [
            'reminder_id' => 9101,
            'company' => 'jarfalla',
            'kind' => 'laddbox',
            'customer' => 'Nils Nilsson',
            'address' => 'Testgatan 1',
            'steps' => [],
        ];
        $openId = 9101;
        $flash = null;
        $error = null;
        $store = null;
        $reminder = null;
        $body = (string) include dirname(__DIR__) . '/src/pages/ordrar_v3.php';
    }
    unset($GLOBALS['_JEAB_LAYOUT_OPEN']);
    ob_start();
    jeab_layout(ucfirst($page), $page, $body);
    $html = (string) ob_get_clean();
    asset_assert_html($html, $page);
    $styleHtml = '';
    foreach (jeab_page_style_hrefs($page) as $href) {
        $styleHtml .= '<link rel="stylesheet" href="' . e($href) . '">';
    }
    asset_assert_html($styleHtml . $html, $page . ' skal-stilar');
}

$versions = jeab_asset_versions();
$versionFile = $tmp . '/versions.json';
file_put_contents($versionFile, json_encode($versions, JSON_UNESCAPED_SLASHES));
$node = <<<'JS'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
const versions = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const start = src.indexOf('function assetVersion(src) {');
if (start < 0) {
  console.error('FAIL assetVersion saknas');
  process.exit(1);
}
let depth = 0;
let end = -1;
for (let p = src.indexOf('{', start); p < src.length; p++) {
  if (src[p] === '{') depth++;
  else if (src[p] === '}') {
    depth--;
    if (depth === 0) {
      end = p + 1;
      break;
    }
  }
}
const fnSrc = src.slice(start, end);
const document = {
  getElementById(id) {
    if (id === 'jeab-asset-v') return { textContent: JSON.stringify(versions) };
    return null;
  },
};
const location = { href: 'https://flowstack.example.test/index.php?p=reminders', origin: 'https://flowstack.example.test' };
const window = { document, location };
const assetVersion = new Function('window', 'document', 'location', fnSrc + '\nreturn assetVersion;')(window, document, location);
function check(input, rel) {
  const out = assetVersion(input);
  const expect = String(versions[rel]);
  const u = new URL(out, location.href);
  if (u.searchParams.get('v') !== expect) {
    console.error('FAIL ' + input + ' -> ' + out + ' väntade v=' + expect);
    process.exit(1);
  }
  console.log('OK  skalet sätter v=' + expect + ' på ' + rel);
}
check('/assets/css/paminnelser.css', 'assets/css/paminnelser.css');
check('/assets/js/inkorg-nav.js', 'assets/js/inkorg-nav.js');
check('/assets/js/optimistic.js?v=3', 'assets/js/optimistic.js');
check('/assets/css/paminnelser.css?x=1&v=3', 'assets/css/paminnelser.css');
const font = 'https://fonts.googleapis.com/css2?family=Inter&display=swap';
if (assetVersion(font) !== font) {
  console.error('FAIL extern stil ska lämnas orörd');
  process.exit(1);
}
console.log('OK  extern stil lämnas orörd');
JS;
$nodeFile = $tmp . '/asset-version.js';
file_put_contents($nodeFile, $node);
$nodeOut = [];
$nodeCode = 1;
exec(
    'node ' . escapeshellarg($nodeFile) . ' '
    . escapeshellarg(dirname(__DIR__) . '/assets/js/shell.js') . ' '
    . escapeshellarg($versionFile) . ' 2>&1',
    $nodeOut,
    $nodeCode
);
foreach ($nodeOut as $line) {
    $GLOBALS['_asset_log'][] = $line;
}
if ($nodeCode !== 0) {
    fwrite(STDERR, implode("\n", $nodeOut) . "\n");
}
asset_ok($nodeCode === 0, 'skalets assetVersion');

echo implode("\n", $GLOBALS['_asset_log']) . "\nasset url: ok\n";
