<?php

declare(strict_types=1);

/**
 * Klick på en order medan listan fortfarande hämtas.
 * Det fördröjda listsvaren får inte rita över ordern.
 * Tillbaka visar den sparade listan direkt, innan nästa svar.
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
const app = fs.readFileSync(process.argv[3], 'utf8');
const listKey = '/index.php?p=ordrar&co=jarfalla&who=all&job=all&ws=1&q=krokus';
const listHtml = `<div class="ordrar-board is-day"><label class="ordrar-search"><input data-js="ordrar-search" value=""></label>
<ul class="ordrar-list"><li><a class="ordrar-row" data-name="Krokus Test" href="/index.php?p=ordrar&amp;co=jarfalla&amp;id=71&amp;who=all&amp;job=all&amp;ws=1&amp;q=krokus">Krokusvägen 10</a></li></ul></div>`;
const html = `<!doctype html><html><head><meta name="csrf" content="t"></head>
<body><header class="chrome"><nav class="tabs">
<a id="to-list" href="${listKey}">Ordrar</a>
</nav></header><main class="main"><p>Påminnelser</p></main></body></html>`;
const dom = new JSDOM(html, {
  url: 'https://flowstack.example.test/index.php?p=reminders&co=jarfalla',
  runScripts: 'outside-only',
  pretendToBeVisual: true,
});
const { window } = dom;
window.scrollTo = function () {};
window.sessionStorage.setItem('jeab.view.' + listKey, listHtml);
const releases = [];
const listSignals = [];
window.fetch = function (url, opts) {
  const u = String(url);
  const order = u.indexOf('id=71') !== -1;
  const body = order
    ? '<section class="ov3" data-v3-root data-id="71"><h1>Krokusvägen 10</h1></section>'
    : '<div class="ordrar-board is-day"><ul class="ordrar-list"><li>Violvägen</li><li>Dimvägen</li></ul></div>';
  const response = {
    ok: true,
    headers: { get: () => 'application/json' },
    json: () => Promise.resolve({ ok: true, html: body, title: 'Ordrar', page: 'ordrar', bodyClass: 'page-ordrar', chrome: '', csrf: 't' }),
  };
  if (!order) {
    listSignals.push(opts && opts.signal);
    return new Promise(function (resolve) { releases.push(resolve); }).then(function () { return response; });
  }
  return Promise.resolve(response);
};
window.eval(shell);
window.eval(app);
const listLink = window.document.getElementById('to-list');
listLink.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
setTimeout(() => {
  if (releases.length !== 1) {
    console.error('FAIL listan hämtades inte: ' + releases.length);
    process.exit(1);
  }
  const row = window.document.querySelector('a.ordrar-row');
  if (!row || row.textContent.indexOf('Krokusvägen') === -1) {
    console.error('FAIL cachen ritades inte innan listsvaren');
    process.exit(1);
  }
  row.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  setTimeout(() => {
    const signal = listSignals[0];
    if (!signal || signal.aborted !== true) {
      console.error('FAIL listhämtningen avbröts inte när ordern öppnades');
      process.exit(1);
    }
    const opened = window.document.querySelector('main.main').innerHTML;
    if (!opened.includes('data-v3-root') || !opened.includes('data-id="71"')) {
      console.error('FAIL ordern syntes inte: ' + opened.slice(0, 240));
      process.exit(1);
    }
    releases[0]();
    setTimeout(() => {
      const main = window.document.querySelector('main.main').innerHTML;
      const here = window.location.pathname + window.location.search;
      if (!main.includes('data-v3-root') || !main.includes('data-id="71"') || main.includes('ordrar-board') || main.includes('Violvägen')) {
        console.error('FAIL fördröjt listsvar ritade över ordern: ' + main.slice(0, 240));
        process.exit(1);
      }
      if (here.indexOf('id=71') === -1) {
        console.error('FAIL adressen tappade id: ' + here);
        process.exit(1);
      }
      window.history.back();
      setTimeout(() => {
        const backMain = window.document.querySelector('main.main');
        const backHtml = backMain.innerHTML;
        const backUrl = window.location.pathname + window.location.search;
        const search = backMain.querySelector('[data-js="ordrar-search"]');
        if (backUrl.indexOf('id=71') !== -1 || backUrl.indexOf('q=krokus') === -1) {
          console.error('FAIL tillbaka hamnade fel: ' + backUrl);
          process.exit(1);
        }
        if (!backHtml.includes('ordrar-board') || !backHtml.includes('Krokusvägen') || backHtml.includes('data-v3-root')) {
          console.error('FAIL tillbaka visade inte den sparade listan: ' + backHtml.slice(0, 240));
          process.exit(1);
        }
        if (backHtml.includes('jeab-skel') && !backHtml.includes('Krokusvägen')) {
          console.error('FAIL tillbaka är en tom skelettvy');
          process.exit(1);
        }
        if (!search || search.value !== 'krokus') {
          console.error('FAIL sökningen saknas i den sparade listan');
          process.exit(1);
        }
        if (releases.length < 2) {
          console.error('FAIL tillbaka hämtade inte listan tyst');
          process.exit(1);
        }
        console.log('OK  fördröjt listsvar lämnar ordern, och tillbaka visar cachen');
      }, 30);
    }, 40);
  }, 40);
}, 30);
JS;

$tmp = sys_get_temp_dir() . '/jeab-nav-gen-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
file_put_contents($tmp . '/nav-gen.js', $js);
$node = [];
$code = 1;
exec(
    'NODE_PATH=' . escapeshellarg($cache . '/node_modules') . ' node ' . escapeshellarg($tmp . '/nav-gen.js') . ' '
    . escapeshellarg($root . '/assets/js/shell.js') . ' '
    . escapeshellarg($root . '/assets/js/app.js') . ' 2>&1',
    $node,
    $code
);
echo implode("\n", $node) . "\n";
if ($code !== 0) {
    fwrite(STDERR, "FAIL nav-gen\n");
    exit(1);
}

echo "ordrar nav gen: ok\n";
