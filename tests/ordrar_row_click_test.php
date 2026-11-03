<?php

declare(strict_types=1);

/**
 * Orderrad: klick och tap öppnar ordern. Personfiltret byts inte till
 * inloggningens förval för att träffen landade på ett chip eller en flik.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-row-click-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function orc_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function orc_ok(string $message): void
{
    echo "OK  {$message}\n";
}

/** @return array<string, string> */
function orc_href_query(string $html): array
{
    if (!preg_match('/<a class="[^"]*ordrar-row[^"]*" href="([^"]+)"/', $html, $m)) {
        orc_fail('raden saknar href');
    }
    $q = [];
    parse_str((string) parse_url(html_entity_decode($m[1], ENT_QUOTES), PHP_URL_QUERY), $q);
    $out = [];
    foreach ($q as $key => $value) {
        $out[(string) $key] = (string) $value;
    }
    return $out;
}

$row = [
    'reminder_id' => 42,
    'company' => 'jarfalla',
    'kind' => 'sol',
    'customer' => 'Krokus Test',
    'address' => 'Krokusvägen 10, 176 74 Järfälla',
    'huvudsokande' => 'Krokus Test',
    'created_at' => '2026-10-01 10:00:00',
    'steps' => [],
];

$alla = jeab_ordrar_board_row_html($row, 'jarfalla', null, [
    'who' => null,
    'job' => null,
    'explicit' => true,
]);
$q = orc_href_query($alla);
if (($q['p'] ?? '') !== 'ordrar' || ($q['id'] ?? '') !== '42' || ($q['who'] ?? '') !== 'all' || ($q['job'] ?? '') !== 'all' || !ctype_digit($q['ws'] ?? '')) {
    orc_fail('Alla-raden tappar filtret: ' . json_encode($q, JSON_UNESCAPED_UNICODE));
}
orc_ok('raden med Alla bär id, who=all, job=all och ws');
if (!str_contains($alla, 'data-tags="') || !str_contains($alla, 'data-workers="') || !str_contains($alla, 'data-product="')) {
    orc_fail('raden saknar data-tags, data-workers eller data-product');
}
if (preg_match('/\sdata-(?:filter|who|job)=/', $alla) === 1) {
    orc_fail('raden har data-filter, data-who eller data-job');
}
if (!str_contains($alla, 'Krokus Test')) {
    orc_fail('kundnamnet saknas i raden');
}
orc_ok('raden använder data-tags och inte filterattribut');

$plain = jeab_ordrar_board_row_html($row, 'jarfalla');
$plainQ = orc_href_query($plain);
if (isset($plainQ['who']) || isset($plainQ['job']) || isset($plainQ['ws']) || ($plainQ['id'] ?? '') !== '42') {
    orc_fail('rad utan explicit filter ska bara ha id: ' . json_encode($plainQ, JSON_UNESCAPED_UNICODE));
}
orc_ok('rad utan explicit filter lämnar who och job tomma');

$named = jeab_ordrar_board_row_html($row, 'jarfalla', null, [
    'who' => 'Sam L',
    'job' => null,
    'explicit' => true,
]);
$namedQ = orc_href_query($named);
if (($namedQ['who'] ?? '') !== 'Sam L' || ($namedQ['job'] ?? '') !== 'all' || ($namedQ['id'] ?? '') !== '42') {
    orc_fail('valt personfilter följer inte med: ' . json_encode($namedQ, JSON_UNESCAPED_UNICODE));
}
orc_ok('explicit Sam L följer med i radens href');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
$shell = (string) file_get_contents(dirname(__DIR__) . '/assets/js/shell.js');
if (substr_count($page, 'jeab_ordrar_board_row_html($row, $company, $store ?? null, $staffFilter)') !== 5) {
    orc_fail('listan skickar inte med personfiltret på huvudlistan, Slutfas, övriga träffar, Allt klart och intent=rows');
}
if (!str_contains($page, "a.getAttribute('data-tags')") || !str_contains($page, "t.closest('.ordrar-filter')") || !str_contains($page, "t.closest('a.ordrar-row')")) {
    orc_fail('filterscriptet läser fel attribut');
}
if (str_contains($page, "closest('[data-filter]')") || str_contains($page, "closest('[data-who]')")) {
    orc_fail('filterscriptet fångar fortfarande data-filter eller data-who');
}
foreach (['function eventElement', 'function rememberRow', 'function takePressedRow', 'a.ordrar-row'] as $needle) {
    if (!str_contains($shell, $needle)) {
        orc_fail('skalet saknar ' . $needle);
    }
}
if (!str_contains($shell, "closest('button, input, select, textarea, label, option')")) {
    orc_fail('skalet släpper inte längre formulärkontroller');
}
orc_ok('sida och skal har radkontraktet');

if (!preg_match("/<script>\s*(\(function \(\) \{\s*var board = document\\.querySelector\\('\\.ordrar-board\\.is-day'\\);.*?\}\)\(\);)\s*<\/script>/s", $page, $scriptMatch)) {
    orc_fail('listans klickscript gick inte att läsa');
}
$boardScript = $scriptMatch[1];

$cache = sys_get_temp_dir() . '/jeab-jsdom-cache';
$jsdomPkg = $cache . '/node_modules/jsdom/package.json';
if (!is_file($jsdomPkg)) {
    if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
        orc_fail('jsdom-cache');
    }
    $npm = [];
    $npmCode = 1;
    exec('npm install --prefix ' . escapeshellarg($cache) . ' jsdom@25 --silent 2>&1', $npm, $npmCode);
    if ($npmCode !== 0 || !is_file($jsdomPkg)) {
        orc_fail('jsdom install: ' . implode("\n", $npm));
    }
}

$js = <<<'JS'
const fs = require('fs');
const { JSDOM } = require('jsdom');
const shell = fs.readFileSync(process.argv[2], 'utf8');
const boardScript = fs.readFileSync(process.argv[3], 'utf8');

function fail(message) {
  console.error('FAIL ' + message);
  process.exitCode = 1;
}

function pageHtml() {
  return `<!doctype html><html><head><meta name="csrf" content="t"></head>
<body class="page-ordrar">
<header class="chrome"><nav class="tabs">
<a class="tab is-on" href="/index.php?p=ordrar&amp;co=jarfalla">Ordrar</a>
<a class="tab" href="/index.php?p=reminders&amp;co=jarfalla">Påminnelser</a>
</nav></header>
<main class="main">
<div class="ordrar-board is-day is-lista">
<div class="cal-filter ordrar-staff-filter"><div class="cal-filter-main">
<a class="chip is-on" href="/index.php?p=ordrar&amp;co=jarfalla&amp;who=all&amp;job=all&amp;ws=1&amp;q=krokus">Alla</a>
<a class="chip" href="/index.php?p=ordrar&amp;co=jarfalla&amp;who=Sam+L&amp;job=all&amp;ws=1&amp;q=krokus"><span class="chip-who-name">Sam L</span></a>
<a class="chip" href="/index.php?p=ordrar&amp;co=jarfalla&amp;who=Elin&amp;job=all&amp;ws=1&amp;q=krokus">Elin</a>
</div></div>
<div class="ordrar-filters" data-js="ordrar-filters">
<button type="button" class="ordrar-filter is-on" data-filter="" aria-pressed="true">Alla</button>
<button type="button" class="ordrar-filter" data-filter="wait" aria-pressed="false">Behöver svar</button>
</div>
<input data-js="ordrar-search" value="krokus">
<ul class="ordrar-list"><li>
<a class="ordrar-row" href="/index.php?p=ordrar&amp;co=jarfalla&amp;id=42&amp;who=all&amp;job=all&amp;ws=1" data-name="Krokusvägen 10" data-tags="" data-workers="Sam L" data-product="sol">
<span class="ordrar-name">Krokusvägen 10</span>
</a>
</li></ul>
</div>
</main></body></html>`;
}

function boot() {
  const dom = new JSDOM(pageHtml(), {
    url: 'https://flowstack.example.test/index.php?p=ordrar&co=jarfalla&who=all&job=all&ws=1&q=krokus',
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  window.scrollTo = function () {};
  const seen = [];
  window.fetch = function (url, opts) {
    const signal = opts && opts.signal;
    const headers = (opts && opts.headers) || {};
    const prefetch = headers['Sec-Purpose'] === 'prefetch';
    const item = { url: String(url), signal: signal || null, aborted: false, prefetch: prefetch };
    seen.push(item);
    if (prefetch && signal) {
      return new Promise(function (resolve, reject) {
        const abort = function () {
          item.aborted = true;
          reject(new DOMException('Aborted', 'AbortError'));
        };
        if (signal.aborted) {
          abort();
          return;
        }
        signal.addEventListener('abort', abort);
      });
    }
    if (signal) {
      signal.addEventListener('abort', function () { item.aborted = true; });
    }
    return Promise.resolve({
      ok: true,
      headers: { get: () => 'application/json' },
      json: () => Promise.resolve({
        ok: true,
        html: '<p>order 42</p>',
        title: 'Order',
        page: 'ordrar',
        bodyClass: 'page-ordrar',
        chrome: '',
        csrf: 't',
      }),
    });
  };
  window.eval(shell);
  window.eval(boardScript);
  return { window, seen };
}

function pointer(window, type, el, x, y) {
  const Ctor = window.PointerEvent || window.MouseEvent;
  el.dispatchEvent(new Ctor(type, {
    bubbles: true,
    cancelable: true,
    button: 0,
    clientX: x,
    clientY: y,
  }));
}

function click(window, el, x, y) {
  const event = new window.MouseEvent('click', {
    bubbles: true,
    cancelable: true,
    button: 0,
    clientX: x,
    clientY: y,
  });
  el.dispatchEvent(event);
  return event;
}

function liveFetches(seen) {
  return seen.filter((item) => !item.prefetch);
}

function urlsOf(items) {
  return items.map((item) => item.url).join(' | ');
}

(async () => {
  const textCase = boot();
  const name = textCase.window.document.querySelector('.ordrar-name').firstChild;
  const textClick = click(textCase.window, name, 20, 420);
  await new Promise((resolve) => setTimeout(resolve, 30));
  const textUrls = urlsOf(liveFetches(textCase.seen));
  if (!textClick.defaultPrevented || !textUrls.includes('id=42') || !textUrls.includes('who=all') || textUrls.includes('who=Sam')) {
    fail('textnod Krokusvägen 10 hämtade ' + textUrls);
  } else {
    console.log('OK  textnod öppnar order 42');
  }

  const shift = boot();
  const row = shift.window.document.querySelector('a.ordrar-row');
  const chip = [...shift.window.document.querySelectorAll('a.chip')].find((el) => el.textContent.includes('Sam'));
  pointer(shift.window, 'pointerdown', row, 12, 640);
  const chipClick = click(shift.window, chip, 180, 48);
  await new Promise((resolve) => setTimeout(resolve, 30));
  const shiftUrls = urlsOf(liveFetches(shift.seen));
  if (!chipClick.defaultPrevented || !shiftUrls.includes('id=42') || shiftUrls.includes('who=Sam')) {
    fail('skiftat klick till Sam L hämtade ' + shiftUrls);
  } else {
    console.log('OK  tryck på raden och klick på Sam L öppnar ordern');
  }

  const tabCase = boot();
  const tabRow = tabCase.window.document.querySelector('a.ordrar-row');
  const tab = tabCase.window.document.querySelector('nav.tabs a.tab.is-on');
  tabCase.window.document.querySelector('a.ordrar-row').dispatchEvent(new tabCase.window.Event('touchstart', { bubbles: true, cancelable: true }));
  const tabClick = click(tabCase.window, tab, 30, 12);
  await new Promise((resolve) => setTimeout(resolve, 30));
  const tabUrls = urlsOf(liveFetches(tabCase.seen));
  if (!tabClick.defaultPrevented || !tabUrls.includes('id=42') || !tabUrls.includes('who=all')) {
    fail('klick på Ordrar-fliken efter radtryck hämtade ' + tabUrls);
  } else if (![...tabCase.seen].some((item) => item.prefetch && item.aborted)) {
    fail('forhamtningen avbrots inte nar ordern oppnades');
  } else if (liveFetches(tabCase.seen).some((item) => tabCase.seen.some((pre) => pre.prefetch && pre.signal && pre.signal === item.signal))) {
    fail('orderhamtningen hade forhamtningens abortsignal');
  } else {
    console.log('OK  flikklick efter radtryck öppnar ordern och förhämtningen avbryts');
  }
  void tabRow;

  const ownChip = boot();
  const sam = [...ownChip.window.document.querySelectorAll('a.chip')].find((el) => el.textContent.includes('Sam'));
  ownChip.window.document.querySelector('a.ordrar-row').dispatchEvent(new ownChip.window.Event('touchstart', { bubbles: true, cancelable: true }));
  sam.dispatchEvent(new ownChip.window.Event('touchstart', { bubbles: true, cancelable: true }));
  pointer(ownChip.window, 'pointerdown', sam, 180, 48);
  const ownClick = click(ownChip.window, sam, 180, 48);
  await new Promise((resolve) => setTimeout(resolve, 30));
  const ownUrls = urlsOf(liveFetches(ownChip.seen));
  if (!ownClick.defaultPrevented || !ownUrls.includes('who=Sam') || ownUrls.includes('id=42')) {
    fail('eget chipklick hämtade ' + ownUrls);
  } else {
    console.log('OK  eget klick på Sam L byter filter');
  }

  const status = boot();
  const wait = status.window.document.querySelector('.ordrar-filter[data-filter="wait"]');
  const before = liveFetches(status.seen).length;
  const statusClick = click(status.window, wait, 80, 90);
  await new Promise((resolve) => setTimeout(resolve, 30));
  if (statusClick.defaultPrevented || liveFetches(status.seen).length !== before || wait.getAttribute('aria-pressed') !== 'true') {
    fail('statusfiltret navigerade eller slogs inte på');
  } else if (!status.window.document.querySelector('a.ordrar-row')) {
    fail('statusfiltret tog bort orderraden');
  } else {
    console.log('OK  statusfilter byter inte person och öppnar ingen order');
  }

  if (process.exitCode) {
    process.exit(process.exitCode);
  }
})().catch((err) => {
  console.error('FAIL ' + (err && err.stack ? err.stack : err));
  process.exit(1);
});
JS;
file_put_contents($tmp . '/row-click.js', $js);
file_put_contents($tmp . '/board.js', $boardScript);
$nodeLines = [];
$nodeCode = 1;
$env = 'NODE_PATH=' . escapeshellarg($cache . '/node_modules');
exec(
    $env . ' node ' . escapeshellarg($tmp . '/row-click.js') . ' '
    . escapeshellarg(dirname(__DIR__) . '/assets/js/shell.js') . ' '
    . escapeshellarg($tmp . '/board.js') . ' 2>&1',
    $nodeLines,
    $nodeCode
);
echo implode("\n", $nodeLines) . "\n";
if ($nodeCode !== 0) {
    orc_fail('jsdom radklick');
}

echo "ordrar row click: ok\n";
