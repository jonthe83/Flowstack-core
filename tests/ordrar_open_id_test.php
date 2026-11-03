<?php

declare(strict_types=1);

require_once __DIR__ . '/jeab_test_proc_env.php';

/**
 * ?p=ordrar&id= öppnar ordern, även med who=all&job=all&ws=1.
 * Listan är samma vid två laddningar och har inga dolda reservrader.
 * Sökningen följer med tillbaka. Skalets förhämtning får inte rita listan över ordern.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-open-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_OPEN_TMP=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");
$hash = password_hash('open-id-kod', PASSWORD_DEFAULT);
file_put_contents($tmp . '/pw.json', json_encode([
    'secret' => 'open-id-secret',
    'hashes' => [['hash' => $hash, 'email' => 'open@jarfalla.example.test']],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/pw.json');

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function oid_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function oid_ok(string $message): void
{
    echo "OK  {$message}\n";
}

function oid_order(int $id, string $address, string $created): void
{
    jeab_purchase_put([
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'kind' => 'sol',
        'customer' => 'Kund ' . $id,
        'address' => $address,
        'huvudsokande' => 'Kund ' . $id,
        'created_at' => $created,
        'steps' => [],
    ]);
}

oid_order(71, 'Krokusvägen 10, 176 74 Järfälla', '2026-10-02 09:00:00');
oid_order(24, 'Klippingvägen 19, 176 74 Järfälla', '2026-10-03 09:00:00');
oid_order(10, 'Violvägen 2, 176 74 Järfälla', '2026-10-01 09:00:00');
oid_order(11, 'Dimvägen 4, 176 74 Järfälla', '2026-10-01 09:00:00');

jeab_auth_data(true);
$cookie = jeab_auth_issue_value('open@jarfalla.example.test', $hash);
if (!is_string($cookie) || $cookie === '') {
    oid_fail('testkaka');
}

$root = dirname(__DIR__);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n" . jeab_test_sqlite_boot() . <<<PHP
\$tmp = getenv('JEAB_OPEN_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
\$GLOBALS['_JEAB_FELLOGG_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/pw.json');
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$root}/index.php';
    return true;
}
return false;
PHP);

$port = 8891;
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $root,
    jeab_test_proc_env(['JEAB_TEST_TEMP_SQLITE' => '1', 'JEAB_OPEN_TMP' => $tmp])
);
if (!is_resource($server)) {
    oid_fail('php -S');
}
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});

function oid_curl(string $url, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

$deadline = microtime(true) + 5;
do {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=links');
    if (is_string($probe) && $probe !== '') {
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);

$auth = ['Cookie: jeab_auth=' . rawurlencode((string) $cookie)];
$partial = array_merge($auth, ['X-Jeab-Partial: 1', 'Accept: text/html, application/json']);
$base = 'http://127.0.0.1:' . $port . '/index.php';
$orderUrl = $base . '?p=ordrar&co=jarfalla&id=71&who=all&job=all&ws=1&q=krokus';

foreach (['full' => $auth, 'partial' => $partial] as $kind => $headers) {
    $res = oid_curl($orderUrl, $headers);
    $body = $res[2];
    $html = $body;
    if ($kind === 'partial') {
        $json = json_decode($body, true);
        $html = (string) ($json['html'] ?? '');
        if ($res[0] !== 200 || !is_array($json) || ($json['ok'] ?? false) !== true) {
            oid_fail('partial status ' . $res[0] . ' ' . substr($body, 0, 300));
        }
    } elseif ($res[0] !== 200) {
        oid_fail('full status ' . $res[0] . ' ' . substr($body, 0, 300));
    }
    if (!str_contains($html, 'data-v3-root') || !str_contains($html, 'data-id="71"')) {
        oid_fail($kind . ' saknar orderbilden: ' . substr(preg_replace('/\s+/', ' ', $html) ?? '', 0, 400));
    }
    // Hela dokumentet har kritisk css i head. Listan räknas bara i body.
    $painted = $html;
    if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $html, $bodyMatch) === 1) {
        $painted = $bodyMatch[1];
    }
    if (str_contains($painted, 'ordrar-board') || str_contains($painted, 'ordrar-list') || str_contains($painted, 'Violvägen') || str_contains($painted, 'Dimvägen')) {
        oid_fail($kind . ' ritade listan');
    }
    if (!str_contains($html, 'q=krokus') || !str_contains($html, 'who=all')) {
        oid_fail($kind . ' tappade sökning eller Alla i tillbakalänken');
    }
    if (!str_contains($res[1], 'no-store')) {
        oid_fail($kind . ' får cachas');
    }
    oid_ok($kind . ' id=71 är orderbilden, inte listan');
}

$id24 = oid_curl($base . '?p=ordrar&co=jarfalla&id=24&who=all&job=all&ws=1', $partial);
$id24html = (string) (json_decode($id24[2], true)['html'] ?? '');
if (!str_contains($id24html, 'data-id="24"') || str_contains($id24html, 'ordrar-board')) {
    oid_fail('id=24 öppnade inte ordern');
}
oid_ok('id=24 är orderbilden');

$ws = (string) time();
$listUrl = $base . '?p=ordrar&co=jarfalla&who=all&job=all&ws=' . $ws . '&q=krokus';
$idsOf = static function (string $html): array {
    preg_match_all('/<a class="[^"]*ordrar-row[^"]*" href="([^"]+)"/', $html, $m);
    $ids = [];
    foreach ($m[1] as $href) {
        $q = [];
        parse_str((string) parse_url(html_entity_decode($href, ENT_QUOTES), PHP_URL_QUERY), $q);
        if (isset($q['id']) && !str_contains($href, 'ordrar-fallback')) {
            $ids[] = (string) $q['id'];
        }
    }
    return $ids;
};
$listA = (string) (json_decode(oid_curl($listUrl, $partial)[2], true)['html'] ?? '');
$listB = (string) (json_decode(oid_curl($listUrl, $partial)[2], true)['html'] ?? '');
if ($listA === '' || $idsOf($listA) !== $idsOf($listB) || $idsOf($listA) !== ['71']) {
    oid_fail('sökningen är inte stabil: ' . implode(',', $idsOf($listA)) . ' / ' . implode(',', $idsOf($listB)));
}
oid_ok('q=krokus visar bara Krokusvägen, samma vid två laddningar');
$narrow = (string) (json_decode(oid_curl($base . '?p=ordrar&co=jarfalla&who=Elin&job=all&ws=' . $ws, $partial)[2], true)['html'] ?? '');
if (!str_contains($narrow, '<ul class="ordrar-list"></ul>')) {
    oid_fail('reservraderna ligger som synliga eller dolda rader');
}
if (preg_match('/data-js="ordrar-fallback"[^>]*>[\s\S]*?<ul class="ordrar-list">\s*<li/', $narrow) === 1) {
    oid_fail('fallback-listan har rader i DOM');
}
if (!str_contains($listA, 'q=krokus')) {
    oid_fail('sökningen saknas på radernas länkar');
}
oid_ok('inga dolda reservrader, sökningen sitter på raden');

$cache = sys_get_temp_dir() . '/jeab-jsdom-cache';
$jsdomPkg = $cache . '/node_modules/jsdom/package.json';
if (!is_file($jsdomPkg)) {
    if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
        oid_fail('jsdom-cache');
    }
    $npm = [];
    $npmCode = 1;
    exec('npm install --prefix ' . escapeshellarg($cache) . ' jsdom@25 --silent 2>&1', $npm, $npmCode);
    if ($npmCode !== 0 || !is_file($jsdomPkg)) {
        oid_fail('jsdom ' . implode("\n", $npm));
    }
}
$js = <<<'JS'
const fs = require('fs');
const { JSDOM } = require('jsdom');
const shell = fs.readFileSync(process.argv[2], 'utf8');
const app = fs.readFileSync(process.argv[3], 'utf8');
const html = `<!doctype html><html><head><meta name="csrf" content="t"></head>
<body class="page-ordrar"><header class="chrome"><nav class="tabs">
<a class="tab is-on" href="/index.php?p=ordrar&amp;co=jarfalla">Ordrar</a>
</nav></header><main class="main">
<div class="ordrar-board is-day"><ul class="ordrar-list"><li>
<a class="ordrar-row" href="/index.php?p=ordrar&amp;co=jarfalla&amp;id=71&amp;who=all&amp;job=all&amp;ws=1&amp;q=krokus">Krokusvägen 10</a>
</li></ul></div></main></body></html>`;
const dom = new JSDOM(html, {
  url: 'https://flowstack.example.test/index.php?p=ordrar&co=jarfalla&who=all&job=all&ws=1&q=krokus',
  runScripts: 'outside-only',
  pretendToBeVisual: true,
});
const { window } = dom;
window.scrollTo = function () {};
const seen = [];
window.fetch = function (url, opts) {
  seen.push({ url: String(url), prefetch: !!(opts && opts.headers && opts.headers['Sec-Purpose']) });
  const order = !opts || !opts.headers || !opts.headers['Sec-Purpose'];
  const html = order
    ? '<section class="ov3" data-v3-root data-id="71"><h1>Krokusvägen 10</h1></section>'
    : '<div class="ordrar-board is-day"><ul class="ordrar-list"><li>Violvägen</li><li>Dimvägen</li></ul></div>';
  return Promise.resolve({
    ok: true,
    headers: { get: () => 'application/json' },
    json: () => Promise.resolve({ ok: true, html: html, title: 'Ordrar', page: 'ordrar', bodyClass: 'page-ordrar', chrome: '', csrf: 't' }),
  });
};
window.eval(shell);
window.eval(app);
const row = window.document.querySelector('a.ordrar-row');
row.dispatchEvent(new window.Event('touchstart', { bubbles: true, cancelable: true }));
setTimeout(() => {
  row.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  setTimeout(() => {
    const main = window.document.querySelector('main.main').innerHTML;
    const live = seen.filter((item) => !item.prefetch).map((item) => item.url).join(' ');
    if (!main.includes('data-v3-root') || !main.includes('data-id="71"') || main.includes('ordrar-board') || main.includes('Violvägen')) {
      console.error('FAIL skalet ritade listan över ordern: ' + main.slice(0, 240));
      process.exit(1);
    }
    if (!live.includes('id=71')) {
      console.error('FAIL klicket hämtade inte ordern: ' + live);
      process.exit(1);
    }
    window.history.pushState({}, '', '/index.php?p=ordrar&co=jarfalla&id=71&who=all&job=all&ws=1');
    const before = seen.length;
    if (typeof window.__jeabSoftSyncReminders === 'function') {
      window.__jeabSoftSyncReminders('poll');
    }
    if (seen.length !== before) {
      console.error('FAIL pollern hämtade medan ordern var öppen');
      process.exit(1);
    }
    console.log('OK  förhämtad lista ersätter inte ordern och pollern är tyst');
  }, 40);
}, 30);
JS;
file_put_contents($tmp . '/shell-open.js', $js);
$node = [];
$code = 1;
exec(
    'NODE_PATH=' . escapeshellarg($cache . '/node_modules') . ' node ' . escapeshellarg($tmp . '/shell-open.js') . ' '
    . escapeshellarg($root . '/assets/js/shell.js') . ' '
    . escapeshellarg($root . '/assets/js/app.js') . ' 2>&1',
    $node,
    $code
);
echo implode("\n", $node) . "\n";
if ($code !== 0) {
    oid_fail('skal');
}

echo "ordrar open id: ok\n";
