<?php

declare(strict_types=1);

/**
 * Skalet ritar en äldre vy direkt och hämtar bakom.
 * Vila-förhämtning tar de andra modulflikarna.
 * Tillbaka i Inkorgen laddar inte om dokumentet.
 * En skrivning mot den gamla vyn skickas till servern.
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

$shell = (string) file_get_contents($root . '/assets/js/shell.js');
$popAt = strpos($shell, "addEventListener('popstate'");
$showAt = $popAt === false ? false : strpos($shell, "addEventListener('pageshow'", $popAt);
$pop = ($popAt !== false && $showAt !== false) ? substr($shell, $popAt, $showAt - $popAt) : '';
if ($pop === '' || !str_contains($pop, 'jeabIxNav') || str_contains($pop, 'location.reload')) {
    fwrite(STDERR, "FAIL popstate laddar fortfarande om inkorgen\n");
    exit(1);
}
if (!str_contains($shell, 'IDLE_PAGES') || !str_contains($shell, 'requestIdleCallback') || !str_contains($shell, 'Sec-Purpose')) {
    fwrite(STDERR, "FAIL vila-förhämtning saknas\n");
    exit(1);
}
if (!preg_match('/IDLE_PAGES = \[([^\]]*)\]/', $shell, $idleMatch)) {
    fwrite(STDERR, "FAIL IDLE_PAGES går inte att läsa\n");
    exit(1);
}
$idleList = $idleMatch[1];
if (str_contains($idleList, 'inkorg') || str_contains($idleList, 'reminders') || !str_contains($idleList, 'utskick') || !str_contains($idleList, 'offert') || !str_contains($idleList, 'bookings') || !str_contains($idleList, 'ordrar')) {
    fwrite(STDERR, "FAIL vila-förhämtning ska ta de andra flikarna utom Inkorg och Påminnelser: {$idleList}\n");
    exit(1);
}
if (!str_contains($shell, 'DRAW_MS = 5 * 60 * 1000') || !str_contains($shell, 'PREFETCH_MAX = 4') || !str_contains($shell, 'Sec-Purpose')) {
    fwrite(STDERR, "FAIL fem minuters ritning eller taket fyra förhämtningar saknas\n");
    exit(1);
}
if (!str_contains($shell, 'QUIET_MS = 1500') || !str_contains($shell, 'saveData') || !str_contains($shell, 'data-jeab-cold')) {
    fwrite(STDERR, "FAIL förhämtning väntar inte 1,5 s, hoppar inte över save-data eller kallstart\n");
    exit(1);
}
if (!str_contains($shell, "searchParams.delete('ws')") || !str_contains($shell, 'status === 304') || !str_contains($shell, 'If-None-Match')) {
    fwrite(STDERR, "FAIL skalet ska ignorera ws och återanvända ETag\n");
    exit(1);
}
if (!str_contains($shell, 'function prefetchSkips') || !str_contains($shell, 'function invalidateViews')) {
    fwrite(STDERR, "FAIL förhämtning utan sessionsskrivning eller cache-rensning saknas\n");
    exit(1);
}
$index = (string) file_get_contents($root . '/index.php');
$prefAt = strpos($index, '$jeabPrefetch && PHP_SAPI');
$closeAt = $prefAt === false ? false : strpos($index, 'session_write_close()', $prefAt);
$prefBlock = ($prefAt !== false && $closeAt !== false) ? substr($index, $prefAt, $closeAt - $prefAt) : '';
if ($prefBlock === '' || str_contains($prefBlock, 'jeab_inkorg_box')) {
    fwrite(STDERR, "FAIL förhämtning får inte skriva inkorgsbolaget\n");
    exit(1);
}
if (!str_contains($shell, 'data-jeab-stale') || !str_contains($shell, 'data-jeab-server') || !str_contains($shell, "el.id = 'jeab-swr'")) {
    fwrite(STDERR, "FAIL uppdateringsmarkör eller server-skrivning saknas\n");
    exit(1);
}
echo "OK  källan har swr, vila-förhämtning och ingen inkorgs-omladdning\n";

$js = <<<'JS'
const fs = require('fs');
const { JSDOM } = require('jsdom');
const shell = fs.readFileSync(process.argv[2], 'utf8');

function fail(message) {
  console.error('FAIL ' + message);
  process.exit(1);
}

function tick() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function boot(url, html, opts) {
  opts = opts || {};
  const dom = new JSDOM(html, {
    url: url,
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  window.scrollTo = function () {};
  if (opts.idle) {
    window.__idle = [];
    window.__timers = [];
    window.requestIdleCallback = function (cb) {
      window.__idle.push(cb);
      return window.__idle.length;
    };
    window.cancelIdleCallback = function (id) {
      if (id > 0) {
        window.__idle[id - 1] = null;
      }
    };
    window.setTimeout = function (fn) {
      window.__timers.push({ fn: fn });
      return window.__timers.length;
    };
    window.clearTimeout = function (id) {
      if (id > 0 && window.__timers[id - 1]) {
        window.__timers[id - 1].fn = null;
      }
    };
  }
  const seen = [];
  let release = null;
  window.fetch = function (href, options) {
    const headers = (options && options.headers) || {};
    const item = {
      url: String(href),
      purpose: headers['Sec-Purpose'] || '',
      partial: headers['X-Jeab-Partial'] || '',
      method: (options && options.method) || 'GET',
    };
    seen.push(item);
    const response = {
      ok: true,
      headers: { get: () => 'application/json' },
      json: () => Promise.resolve(opts.body ? opts.body(item) : {
        ok: true,
        html: '<p>svar</p>',
        title: 'JEAB',
        bodyClass: 'page-x',
        chrome: '',
        csrf: 'tok',
      }),
    };
    if (opts.hold && item.purpose !== 'prefetch') {
      return new Promise(function (resolve) { release = resolve; }).then(function () { return response; });
    }
    return Promise.resolve(response);
  };
  if (opts.before) {
    opts.before(window);
  }
  /* Sidan har redan app.js, som i en riktig vy för Påminnelser, Kalender och Ordrar.
     Utan det försöker skalet ladda skriptet och jsdom når inte finishApply. */
  if (typeof window.jeabBindReminders !== 'function') {
    window.jeabBindReminders = function () {};
  }
  window.eval(shell);
  return { window, seen, release: () => release };
}

(async () => {
  const oldHtml = '<div class="week-grid" id="cal-old">Vecka 12</div>';
  const newHtml = '<div class="week-grid" id="cal-new">Vecka 12</div>';
  const reminders = '<div class="apple-app"><h1 class="crm-title">Påminnelser</h1><ul class="apple-list"><li class="item">Kvar</li></ul></div>';
  const page = `<!doctype html><html><head><meta name="csrf" content="t"></head><body>
<header class="chrome"><nav class="tabs">
<a class="tab" href="/index.php?p=inkorg&amp;co=jarfalla">Inkorg</a>
<a class="tab is-on" href="/index.php?p=reminders&amp;co=jarfalla">Påminnelser</a>
<a class="tab" id="to-cal" href="/index.php?p=bookings&amp;co=jarfalla">Kalender</a>
<a class="tab" href="/index.php?p=ordrar&amp;co=jarfalla">Ordrar</a>
</nav></header>
<main class="main">${reminders}</main></body></html>`;
  let bookingReads = 0;
  const swr = boot('https://flowstack.example.test/index.php?p=reminders&co=jarfalla', page, {
    hold: true,
    body: (item) => {
      const isCal = item.url.indexOf('p=bookings') !== -1;
      const html = isCal ? (++bookingReads === 1 ? oldHtml : newHtml) : reminders;
      return {
        ok: true,
        html: html,
        title: 'JEAB',
        bodyClass: isCal ? 'page-bookings' : 'page-reminders',
        chrome: '',
        csrf: 'tok',
      };
    },
  });
  let skew = 0;
  const nativeNow = swr.window.Date.now.bind(swr.window.Date);
  swr.window.Date.now = () => nativeNow() + skew;
  const cal = swr.window.document.getElementById('to-cal');
  cal.dispatchEvent(new swr.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  if (swr.seen.length !== 1 || swr.seen[0].partial !== '1') {
    fail('första kalenderklicket hämtade inte partial: ' + swr.seen.length);
  }
  swr.release()();
  await tick();
  await tick();
  const remindersLink = [...swr.window.document.querySelectorAll('nav.tabs a.tab')].find((a) => a.href.indexOf('p=reminders') !== -1);
  remindersLink.dispatchEvent(new swr.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  if (!swr.release()) {
    fail('påminnelser hämtades inte');
  }
  swr.release()();
  await tick();
  await tick();
  const before = swr.seen.length;
  const t0 = swr.window.performance.now();
  cal.dispatchEvent(new swr.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  const paintMs = swr.window.performance.now() - t0;
  const main = swr.window.document.querySelector('main.main');
  if (swr.seen.length !== before + 1 || swr.seen[swr.seen.length - 1].method !== 'GET' || swr.seen[swr.seen.length - 1].partial !== '1') {
    fail('färsk cache hämtades inte bakom: ' + swr.seen.length);
  }
  if (!main.innerHTML.includes('id="cal-old"') || main.innerHTML.includes('id="cal-new"')) {
    fail('färsk cache ritade inte samma kalender direkt: ' + main.innerHTML.slice(0, 180));
  }
  if (main.getAttribute('data-jeab-stale') !== '1') {
    fail('omvalideringen märkte inte att servern fortfarande får sista ordet');
  }
  skew = 10000;
  const t1 = swr.window.performance.now();
  remindersLink.dispatchEvent(new swr.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  if (!swr.release()) {
    fail('gamla påminnelser hämtades inte om');
  }
  swr.release()();
  await tick();
  await tick();
  const staleBefore = swr.seen.length;
  const t2 = swr.window.performance.now();
  cal.dispatchEvent(new swr.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  const stalePaint = swr.window.performance.now() - t2;
  const shown = swr.window.document.querySelector('main.main');
  const bar = swr.window.document.getElementById('jeab-swr');
  if (swr.seen.length !== staleBefore + 1) {
    fail('gammal kalender hämtades inte bakom, såg ' + swr.seen.length);
  }
  if (swr.seen[swr.seen.length - 1].method !== 'GET' || swr.seen[swr.seen.length - 1].partial !== '1') {
    fail('omvalideringen var inte en GET-partial');
  }
  if (!shown.innerHTML.includes('id="cal-old"') || shown.innerHTML.includes('id="cal-new"') || shown.innerHTML.includes('jeab-swr')) {
    fail('gammal kalender byttes innan svaret: ' + shown.innerHTML.slice(0, 200));
  }
  if (shown.innerHTML !== oldHtml) {
    fail('samma data gav annan HTML: ' + shown.innerHTML);
  }
  if (!bar || bar.hidden || shown.getAttribute('data-jeab-stale') !== '1') {
    fail('uppdateringsmarkören syntes inte medan den gamla vyn låg kvar');
  }
  if (stalePaint > 50) {
    fail('gammal vy tog ' + stalePaint.toFixed(1) + ' ms att rita');
  }
  const form = swr.window.document.createElement('form');
  form.innerHTML = '<input name="action" value="reminder.done"><button type="submit">Klar</button>';
  shown.appendChild(form);
  let submitSeen = null;
  form.addEventListener('submit', (ev) => {
    submitSeen = {
      prevented: ev.defaultPrevented,
      server: form.getAttribute('data-jeab-server'),
    };
    ev.preventDefault();
  });
  form.dispatchEvent(new swr.window.Event('submit', { bubbles: true, cancelable: true }));
  if (!submitSeen || submitSeen.prevented || submitSeen.server !== '1') {
    fail('skrivning mot gammal vy stoppades eller skickades inte till servern ' + JSON.stringify(submitSeen));
  }
  if (swr.seen.some((item) => item.method !== 'GET')) {
    fail('skalet skickade en skrivning utan servern');
  }
  const pending = swr.release();
  if (!pending) {
    fail('omvalideringen hade inget svar att släppa');
  }
  pending();
  await tick();
  await tick();
  const done = swr.window.document.querySelector('main.main');
  const barAfter = swr.window.document.getElementById('jeab-swr');
  if (!done.innerHTML.includes('id="cal-new"') || done.getAttribute('data-jeab-stale') === '1' || !barAfter || !barAfter.hidden) {
    fail('svaret ersatte inte den gamla vyn: ' + done.innerHTML.slice(0, 180));
  }
  console.log('swr paint ' + stalePaint.toFixed(1) + 'ms while revalidate held (fresh path ' + paintMs.toFixed(1) + 'ms, detour ' + (t2 - t1).toFixed(1) + 'ms)');

  const idlePage = `<!doctype html><html><head><meta name="csrf" content="t"></head><body>
<header class="chrome"><nav class="tabs">
<a class="tab" href="/index.php?p=inkorg&amp;co=jarfalla">Inkorg</a>
<a class="tab is-on" href="/index.php?p=reminders&amp;co=jarfalla">Påminnelser</a>
<a class="tab" href="/index.php?p=bookings&amp;co=jarfalla">Kalender</a>
<a class="tab" href="/index.php?p=ordrar&amp;co=jarfalla">Ordrar</a>
<a class="tab" href="/index.php?p=offert&amp;co=jarfalla">Kalkyl</a>
<a class="tab" href="/index.php?p=links&amp;co=jarfalla">Övrigt</a>
</nav></header>
<main class="main"><p>Påminnelser</p></main></body></html>`;
  const releases = [];
  const idle = boot('https://flowstack.example.test/index.php?p=reminders&co=jarfalla', idlePage, {
    idle: true,
    before: function (win) {
      win.addEventListener('load', function (ev) {
        ev.stopImmediatePropagation();
      }, true);
    },
  });
  idle.window.fetch = function (href, options) {
    const headers = (options && options.headers) || {};
    const item = {
      url: String(href),
      purpose: headers['Sec-Purpose'] || '',
      partial: headers['X-Jeab-Partial'] || '',
      method: (options && options.method) || 'GET',
    };
    idle.seen.push(item);
    const signal = options && options.signal;
    if (signal && signal.addEventListener) {
      signal.addEventListener('abort', function () { item.aborted = true; });
    }
    return new Promise(function (resolve) {
      releases.push(function () {
        resolve({
          ok: true,
          status: 200,
          headers: { get: (name) => (String(name).toLowerCase() === 'etag' ? '"pre"' : 'application/json') },
          json: () => Promise.resolve({ ok: true, html: '<p>pre</p>', title: 'JEAB', bodyClass: 'page-x', chrome: '', csrf: 'tok' }),
        });
      });
    });
  };
  function takeIdle(win) {
    while (win.__idle.length && !win.__idle[0]) {
      win.__idle.shift();
    }
    return win.__idle.shift();
  }
  if (idle.window.__idle.length) {
    fail('vila-förhämtning köades innan sidan varit stilla');
  }
  try {
    Object.defineProperty(idle.window.document, 'readyState', { configurable: true, get: () => 'complete' });
  } catch (e) {
    /* jsdom kan redan vara complete */
  }
  const idleNow = idle.window.Date.now.bind(idle.window.Date);
  let idleSkew = 0;
  idle.window.Date.now = () => idleNow() + idleSkew;
  idleSkew = 5000;
  const timers = (idle.window.__timers || []).splice(0);
  timers.forEach((item) => {
    if (item && item.fn) item.fn();
  });
  const firstIdle = takeIdle(idle.window);
  if (!firstIdle) {
    fail('vila-förhämtning köades inte efter 5 s');
  }
  firstIdle();
  await tick();
  idle.window.document.dispatchEvent(new idle.window.Event('wheel', { bubbles: true }));
  const idleMain = idle.window.document.querySelector('main.main');
  idleMain.dispatchEvent(new idle.window.MouseEvent('pointerdown', { bubbles: true, button: 0 }));
  await tick();
  const urlsEarly = idle.seen.map((item) => item.url).join(' ');
  if (idle.seen.length !== 1 || idle.seen[0].aborted || urlsEarly.indexOf('p=bookings') === -1 || urlsEarly.indexOf('p=offert') !== -1 || urlsEarly.indexOf('p=ordrar') !== -1 || urlsEarly.indexOf('p=inkorg') !== -1 || urlsEarly.indexOf('p=reminders') !== -1 || urlsEarly.indexOf('p=links') !== -1) {
    fail('vilan startade inte med kalender, scroll avbröt den: ' + urlsEarly + ' aborted=' + (idle.seen[0] && idle.seen[0].aborted));
  }
  if (idle.seen.some((item) => item.purpose !== 'prefetch' || item.method !== 'GET')) {
    fail('vilan var inte GET-prefetch');
  }
  if (releases.length !== 1) {
    fail('mer än en förhämtning pågick');
  }
  releases[0]();
  await tick();
  await tick();
  const nextIdle = takeIdle(idle.window);
  if (!nextIdle) {
    fail('nästa flik köades inte efter den första');
  }
  nextIdle();
  await tick();
  if (idle.seen.length !== 2 || idle.seen[1].url.indexOf('p=ordrar') === -1 || idle.seen[1].purpose !== 'prefetch') {
    fail('andra vilan var inte Ordrar: ' + idle.seen.map((item) => item.url).join(' '));
  }
  releases[1]();
  await tick();
  await tick();
  const thirdIdle = takeIdle(idle.window);
  if (!thirdIdle) {
    fail('tredje fliken köades inte');
  }
  thirdIdle();
  await tick();
  releases[2]();
  await tick();
  await tick();
  const fourthIdle = takeIdle(idle.window);
  if (!fourthIdle) {
    fail('fjärde fliken köades inte');
  }
  fourthIdle();
  await tick();
  const urlsIdle = idle.seen.map((item) => item.url).join(' ');
  if (idle.seen.length !== 4 || urlsIdle.indexOf('p=bookings') === -1 || urlsIdle.indexOf('p=ordrar') === -1 || urlsIdle.indexOf('p=inkorg') !== -1 || urlsIdle.indexOf('p=reminders') !== -1) {
    fail('vilan tog fel fyra flikar: ' + urlsIdle);
  }
  releases[3]();
  await tick();
  await tick();
  const fifthIdle = takeIdle(idle.window);
  if (fifthIdle) {
    fifthIdle();
    await tick();
  }
  if (idle.seen.length !== 4) {
    fail('vilan överskred fyra förhämtningar: ' + idle.seen.length);
  }
  const savedCal = idle.window.sessionStorage.getItem('jeab.view./index.php?p=bookings&co=jarfalla') || '';
  if (savedCal.indexOf('<p>pre</p>') === -1) {
    fail('förhämtad kalender sparades inte i sessionen');
  }
  const calTab = [...idle.window.document.querySelectorAll('nav.tabs a.tab')].find((a) => a.href.indexOf('p=bookings') !== -1);
  const paintT0 = idle.window.performance.now();
  calTab.dispatchEvent(new idle.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  const paintDt = idle.window.performance.now() - paintT0;
  const painted = idle.window.document.querySelector('main.main').innerHTML;
  if (!painted.includes('<p>pre</p>') || paintDt > 50) {
    fail('kall kalender ritades inte från förhämtningen på ' + paintDt.toFixed(1) + ' ms: ' + painted.slice(0, 120));
  }
  console.log('idle prefetch one at a time, max 4, skips session writers, paint ' + paintDt.toFixed(1) + 'ms: ' + urlsIdle);

  function inkorgBoot() {
    return boot(
      'https://flowstack.example.test/index.php?p=inkorg&co=jarfalla',
      '<!doctype html><html><head><meta name="csrf" content="t"></head><body class="page-inkorg"><header class="chrome"><nav class="tabs"><a class="tab is-on" href="/index.php?p=inkorg&amp;co=jarfalla">Inkorg</a></nav></header><main class="main"><div id="ix">LIVE</div></main></body></html>'
    );
  }
  const owned = inkorgBoot();
  let reloads = 0;
  try {
    owned.window.location.reload = function () { reloads += 1; };
  } catch (e) {
    fail('kunde inte spana på reload: ' + e.message);
  }
  owned.window.history.pushState({ ix: 'thread' }, '', '/index.php?p=inkorg&co=jarfalla&id=th1');
  owned.window.jeabIxNav = true;
  owned.window.history.back();
  await wait(50);
  const ownedMain = owned.window.document.querySelector('main.main').innerHTML;
  const ownedUrl = owned.window.location.pathname + owned.window.location.search;
  if (reloads !== 0 || owned.seen.length !== 0 || ownedUrl.indexOf('id=th1') !== -1 || !ownedMain.includes('LIVE')) {
    fail('inkorgens Tillbaka laddade om eller hämtade skalet: ' + ownedUrl + ' ' + ownedMain.slice(0, 120) + ' reloads=' + reloads + ' fetches=' + owned.seen.length);
  }
  console.log('inkorg back kept the document');

  const cold = boot(
    'https://flowstack.example.test/index.php?p=inkorg&co=jarfalla',
    '<!doctype html><html><head><meta name="csrf" content="t"></head><body class="page-inkorg"><header class="chrome"><nav class="tabs"><a class="tab is-on" href="/index.php?p=inkorg&amp;co=jarfalla">Inkorg</a></nav></header><main class="main"><div id="ix">LIVE</div></main></body></html>',
    {
      hold: true,
      body: () => ({ ok: true, html: '<div id="ix">NY</div>', title: 'Inkorg', bodyClass: 'page-inkorg', chrome: '', csrf: 't' }),
    }
  );
  let coldReloads = 0;
  try {
    cold.window.location.reload = function () { coldReloads += 1; };
  } catch (e) {
    fail('kunde inte spana på reload: ' + e.message);
  }
  cold.window.sessionStorage.setItem('jeab.view./index.php?p=inkorg&co=jarfalla', '<div id="ix">LISTA</div>');
  cold.window.history.pushState({ ix: 'thread' }, '', '/index.php?p=inkorg&co=jarfalla&id=th1');
  cold.window.history.back();
  await wait(50);
  const coldMain = cold.window.document.querySelector('main.main').innerHTML;
  if (coldReloads !== 0) {
    fail('inkorg utan eget skript laddade om dokumentet');
  }
  if (!cold.seen.some((item) => item.partial === '1' && item.purpose !== 'prefetch' && item.url.indexOf('p=inkorg') !== -1 && item.url.indexOf('id=') === -1)) {
    fail('inkorg utan eget skript hämtade inte partialen: ' + JSON.stringify(cold.seen));
  }
  if (!coldMain.includes('LISTA') || coldMain.includes('>NY<')) {
    fail('sparad inkorgslista ritades inte innan svaret: ' + coldMain.slice(0, 160));
  }
  const coldRelease = cold.release();
  if (!coldRelease) {
    fail('inkorgs-partialen hade inget svar');
  }
  coldRelease();
  await tick();
  await tick();
  if (!cold.window.document.querySelector('main.main').innerHTML.includes('>NY<')) {
    fail('inkorgs-partialen ersatte inte listan');
  }
  console.log('inkorg back without owner uses partial');

  const sidePage = `<!doctype html><html><head><meta name="csrf" content="t"></head><body>
<header class="chrome"><nav class="tabs">
<a class="tab" href="/index.php?p=inkorg&amp;co=jarfalla">Inkorg</a>
<a class="tab" id="side-rem" href="/index.php?p=reminders&amp;co=jarfalla">Påminnelser</a>
<a class="tab" id="side-cal" href="/index.php?p=bookings&amp;co=jarfalla">Kalender</a>
<a class="tab" id="side-ord" href="/index.php?p=ordrar&amp;co=jarfalla">Ordrar</a>
</nav></header>
<main class="main"><div class="week-grid">Kalender</div></main></body></html>`;
  const side = boot('https://flowstack.example.test/index.php?p=bookings&co=jarfalla', sidePage);
  const remTab = side.window.document.getElementById('side-rem');
  remTab.dispatchEvent(new side.window.MouseEvent('mouseover', { bubbles: true, cancelable: true, button: 0 }));
  remTab.dispatchEvent(new side.window.Event('touchstart', { bubbles: true, cancelable: true }));
  await wait(90);
  if (side.seen.some((item) => item.url.indexOf('p=reminders') !== -1 || item.url.indexOf('p=inkorg') !== -1)) {
    fail('hovring förhämtade en sida som skriver sessionen: ' + JSON.stringify(side.seen));
  }
  const ordHover = side.window.document.getElementById('side-ord');
  ordHover.dispatchEvent(new side.window.MouseEvent('mouseover', { bubbles: true, cancelable: true, button: 0 }));
  await wait(90);
  if (side.seen.some((item) => item.url.indexOf('p=ordrar') !== -1 || item.url.indexOf('p=bookings') !== -1)) {
    fail('hovring förhämtade en tung sida: ' + JSON.stringify(side.seen));
  }
  console.log('hover skips reminders, inkorg and heavy pages');

  const ordHtml = '<div class="ordrar-board"><a class="ordrar-row" href="/index.php?p=ordrar&amp;co=jarfalla&amp;id=7">Order</a></div>';
  const calHtml = '<div class="week-grid" id="cal-live">Kalender</div>';
  const saved = boot('https://flowstack.example.test/index.php?p=bookings&co=jarfalla', sidePage, {
    body: (item) => ({
      ok: true,
      html: item.url.indexOf('p=ordrar') !== -1 ? ordHtml : calHtml,
      title: 'JEAB',
      bodyClass: item.url.indexOf('p=ordrar') !== -1 ? 'page-ordrar' : 'page-bookings',
      chrome: '',
      csrf: 'tok',
    }),
  });
  const ordTab = saved.window.document.getElementById('side-ord');
  const backCal = saved.window.document.getElementById('side-cal');
  ordTab.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  await tick();
  backCal.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  await tick();
  const beforeRecent = saved.seen.length;
  ordTab.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  const immediateOrders = saved.window.document.querySelector('main.main').innerHTML;
  if (!immediateOrders.includes('ordrar-row')) {
    fail('sparad lista ritades inte före svaret: ' + immediateOrders.slice(0, 140));
  }
  await tick();
  if (saved.seen.length !== beforeRecent + 1 || saved.seen[saved.seen.length - 1].method !== 'GET') {
    fail('listan hämtades inte bakom, såg ' + saved.seen.length);
  }
  const storeKey = 'jeab.view./index.php?p=ordrar&co=jarfalla';
  if (!saved.window.sessionStorage.getItem(storeKey)) {
    fail('ordrar saknades i sessionStorage före sparningen');
  }
  backCal.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  if (saved.seen.length !== beforeRecent + 2) {
    fail('kalendern hämtades inte bakom den sparade vyn');
  }
  const postForm = saved.window.document.createElement('form');
  postForm.method = 'post';
  postForm.innerHTML = '<input name="action" value="ordrar.spara">';
  saved.window.document.querySelector('main.main').appendChild(postForm);
  postForm.addEventListener('submit', (ev) => ev.preventDefault());
  postForm.dispatchEvent(new saved.window.Event('submit', { bubbles: true, cancelable: true }));
  if (saved.window.sessionStorage.getItem(storeKey)) {
    fail('POST lämnade kvar jeab.view i sessionStorage');
  }
  const beforeRefetch = saved.seen.length;
  ordTab.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  await tick();
  if (saved.seen.length !== beforeRefetch + 1 || saved.seen[saved.seen.length - 1].partial !== '1' || saved.seen[saved.seen.length - 1].method !== 'GET') {
    fail('POST rensade inte recent-cachen: ' + JSON.stringify(saved.seen.slice(beforeRefetch)));
  }
  backCal.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  await tick();
  const beforeFetchWrite = saved.seen.length;
  ordTab.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  if (saved.seen.length !== beforeFetchWrite + 1) {
    fail('ordrar hämtades inte bakom inför fetch-skrivningen, såg ' + saved.seen.length);
  }
  backCal.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  if (saved.seen.length !== beforeFetchWrite + 2) {
    fail('kalendern hämtades inte bakom innan den skrivande fetchen');
  }
  await saved.window.fetch('/index.php?p=ordrar&co=jarfalla&id=7', { method: 'POST', body: 'action=ordrar.spara' });
  if (saved.window.sessionStorage.getItem(storeKey)) {
    fail('skrivande fetch lämnade kvar jeab.view');
  }
  const beforeAfterFetch = saved.seen.length;
  ordTab.dispatchEvent(new saved.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await tick();
  await tick();
  const afterWrite = saved.seen.slice(beforeAfterFetch).filter((item) => item.method === 'GET');
  if (afterWrite.length !== 1 || afterWrite[0].partial !== '1' || afterWrite[0].url.indexOf('p=ordrar') === -1) {
    fail('skrivande fetch rensade inte recent: ' + JSON.stringify(saved.seen.slice(beforeAfterFetch)));
  }
  console.log('writes drop mem and jeab.view');
  console.log('shell-swr ok');
})().catch((err) => {
  console.error('FAIL ' + (err && err.stack ? err.stack : err));
  process.exit(1);
});
JS;

$tmp = sys_get_temp_dir() . '/jeab-shell-swr-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
file_put_contents($tmp . '/swr.js', $js);
$node = [];
$code = 1;
$nodeBin = getenv('NODE') ?: 'node';
exec(
    'NODE_PATH=' . escapeshellarg($cache . '/node_modules') . ' ' . escapeshellarg($nodeBin) . ' ' . escapeshellarg($tmp . '/swr.js') . ' '
    . escapeshellarg($root . '/assets/js/shell.js') . ' 2>&1',
    $node,
    $code
);
echo implode("\n", $node) . "\n";
if ($code !== 0) {
    fwrite(STDERR, "FAIL shell-swr\n");
    exit(1);
}
echo "shell swr: ok\n";
