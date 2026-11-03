<?php

declare(strict_types=1);

/**
 * Sökning som skrivs innan första listrenderingen är klar ska ligga kvar,
 * och klicket ska träffa den filtrerade raden. Tillbaka behåller q.
 */

$root = dirname(__DIR__);
$cache = sys_get_temp_dir() . '/jeab-jsdom-cache';
$jsdomPkg = $cache . '/node_modules/jsdom/package.json';
if (!is_file($jsdomPkg)) {
    if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
        fwrite(STDERR, "FAIL jsdom-cache\n");
        exit(1);
    }
    $npm = [];
    $npmCode = 1;
    exec('npm install --prefix ' . escapeshellarg($cache) . ' jsdom@25 --silent 2>&1', $npm, $npmCode);
    if ($npmCode !== 0 || !is_file($jsdomPkg)) {
        fwrite(STDERR, "FAIL jsdom\n" . implode("\n", $npm) . "\n");
        exit(1);
    }
}

$js = <<<'JS'
const fs = require('fs');
const { JSDOM } = require('jsdom');
const shell = fs.readFileSync(process.argv[2], 'utf8');
const listKey = '/index.php?p=ordrar&co=jarfalla&who=all&job=all&ws=1';
const listHtml = `<section class="ordrar"><header class="ordrar-head-bar">
<label class="ordrar-search"><input type="search" data-js="ordrar-search" value=""></label>
</header><div class="ordrar-board is-day"><ul class="ordrar-list">
<li><a class="ordrar-row" data-name="Sibblevägen 8" href="/index.php?p=ordrar&amp;co=jarfalla&amp;id=8&amp;who=all&amp;job=all&amp;ws=1">Sibblevägen 8</a></li>
<li><a class="ordrar-row" data-name="Krokusvägen 10" href="/index.php?p=ordrar&amp;co=jarfalla&amp;id=71&amp;who=all&amp;job=all&amp;ws=1">Krokusvägen 10</a></li>
</ul></div>
<script src="/assets/js/ordrar-v3.js"></script>
</section>`;
const page = `<!doctype html><html><head>
<meta name="csrf" content="t">
<script type="application/json" id="jeab-asset-v">{"assets/js/ordrar-v3.js":4242}</script>
</head><body><header class="chrome"><nav class="tabs">
<a id="to-list" href="${listKey}">Ordrar</a>
</nav></header><main class="main"><p>Påminnelser</p></main></body></html>`;
const dom = new JSDOM(page, {
  url: 'https://flowstack.example.test/index.php?p=reminders&co=jarfalla',
  runScripts: 'outside-only',
  pretendToBeVisual: true,
});
const { window } = dom;
window.scrollTo = function () {};
window.sessionStorage.setItem('jeab.view./index.php?p=ordrar&co=jarfalla&who=all&job=all', listHtml);
window.sessionStorage.setItem('jeab.view.' + listKey, listHtml);
let releaseList = null;
const seen = [];
window.fetch = function (url, opts) {
  const u = String(url);
  seen.push(u);
  const order = u.indexOf('id=71') !== -1 || u.indexOf('id=8') !== -1;
  const body = order
    ? '<section class="ov3" data-v3-root data-id="' + (u.indexOf('id=71') !== -1 ? '71' : '8') + '"><h1>Order</h1></section>'
    : listHtml;
  const response = {
    ok: true,
    headers: { get: () => 'application/json' },
    json: () => Promise.resolve({ ok: true, html: body, title: 'Ordrar', page: 'ordrar', bodyClass: 'page-ordrar', chrome: '', styles: ['/assets/css/ordrar.css?v=99'], csrf: 't' }),
  };
  if (!order && !(opts && opts.headers && opts.headers['Sec-Purpose'])) {
    return new Promise(function (resolve) { releaseList = resolve; }).then(function () { return response; });
  }
  return Promise.resolve(response);
};
window.eval(shell);
window.document.getElementById('to-list').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
setTimeout(() => {
  const input = window.document.querySelector('[data-js="ordrar-search"]');
  if (!input || !releaseList) {
    console.error('FAIL listan gick inte att söka i innan svaret');
    process.exit(1);
  }
  input.value = 'krokus';
  input.dispatchEvent(new window.Event('input', { bubbles: true }));
  const during = window.document.querySelector('main.main');
  const visibleDuring = [...during.querySelectorAll('.ordrar-list > li')].filter((li) => !li.hidden).map((li) => li.textContent);
  if (window.location.search.indexOf('q=krokus') === -1 || !visibleDuring.some((t) => t.indexOf('Krokusvägen') !== -1) || visibleDuring.some((t) => t.indexOf('Sibblevägen') !== -1)) {
    console.error('FAIL sökningen syntes inte innan listrenderingen: ' + window.location.search + ' ' + visibleDuring.join('|'));
    process.exit(1);
  }
  releaseList();
  setTimeout(() => {
    const main = window.document.querySelector('main.main');
    const field = main.querySelector('[data-js="ordrar-search"]');
    const visible = [...main.querySelectorAll('.ordrar-list > li')].filter((li) => !li.hidden).map((li) => (li.textContent || '').trim());
    const here = window.location.pathname + window.location.search;
    if (!field || field.value !== 'krokus' || here.indexOf('q=krokus') === -1) {
      console.error('FAIL sen render tömde sökningen: ' + (field && field.value) + ' ' + here);
      process.exit(1);
    }
    if (visible.length !== 1 || visible[0].indexOf('Krokusvägen') === -1) {
      console.error('FAIL sen render bytte rader: ' + visible.join('|'));
      process.exit(1);
    }
    const v3 = window.document.querySelector('script[src*="ordrar-v3"]');
    if (!v3 || String(v3.getAttribute('src') || '').indexOf('v=4242') === -1) {
      console.error('FAIL dynamiskt skript saknar ?v=: ' + (v3 && v3.getAttribute('src')));
      process.exit(1);
    }
    const row = [...main.querySelectorAll('a.ordrar-row')].find((a) => a.closest('li') && !a.closest('li').hidden);
    row.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
    setTimeout(() => {
      const opened = window.document.querySelector('main.main').innerHTML;
      const orderUrl = window.location.pathname + window.location.search;
      if (!opened.includes('data-id="71"') || opened.includes('data-id="8"') || orderUrl.indexOf('id=8') !== -1) {
        console.error('FAIL klicket öppnade fel rad: ' + orderUrl + ' ' + opened.slice(0, 180));
        process.exit(1);
      }
      if (orderUrl.indexOf('q=krokus') === -1 || !window.history.state || window.history.state.jeabQ !== 'krokus') {
        console.error('FAIL ordern saknar q i historiken: ' + orderUrl + ' ' + JSON.stringify(window.history.state));
        process.exit(1);
      }
      window.history.back();
      setTimeout(() => {
        const back = window.location.pathname + window.location.search;
        const backField = window.document.querySelector('[data-js="ordrar-search"]');
        if (back.indexOf('q=krokus') === -1 || back.indexOf('id=') !== -1) {
          console.error('FAIL tillbaka tappade q: ' + back);
          process.exit(1);
        }
        if (!backField || backField.value !== 'krokus') {
          console.error('FAIL tillbaka tömde sökfältet');
          process.exit(1);
        }
        if (!window.history.state || window.history.state.jeabQ !== 'krokus') {
          console.error('FAIL history-state saknar q');
          process.exit(1);
        }
        console.log('OK  sökning före listrendering ligger kvar och klicket träffar Krokusvägen');
      }, 30);
    }, 30);
  }, 30);
}, 30);
JS;

$tmp = sys_get_temp_dir() . '/jeab-sok-q-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
file_put_contents($tmp . '/sok-q.js', $js);
$node = [];
$code = 1;
exec(
    'NODE_PATH=' . escapeshellarg($cache . '/node_modules') . ' node ' . escapeshellarg($tmp . '/sok-q.js') . ' '
    . escapeshellarg($root . '/assets/js/shell.js') . ' 2>&1',
    $node,
    $code
);
echo implode("\n", $node) . "\n";
if ($code !== 0) {
    fwrite(STDERR, "FAIL sok-q\n");
    exit(1);
}
echo "ordrar sok q: ok\n";
