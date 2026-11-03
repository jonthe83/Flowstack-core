<?php

declare(strict_types=1);

/**
 * Kalkylens knappar räknar om priset direkt.
 * Skalets klick och förhämtning gäller navigeringslänkar, inte formulärkontroller.
 */

$tmp = sys_get_temp_dir() . '/jeab-kalkyl-click-' . bin2hex(random_bytes(4));
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

function kc_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function kc_round(array $input): int
{
    $calc = jeab_offert_calc($input);
    return (int) round((float) $calc['i14']);
}

$base = [
    'sigen_typ' => 'tp2',
    'sigen_kw' => '12.0',
    'b3' => 2,
    'b14' => 12,
    'b15' => 1,
    'laddare' => 0,
    'g27' => 70000,
    'b5' => 24,
    'c13' => 2000,
    'b17' => 16,
    'c28' => 1558,
    'laddare_unit' => 6793,
    'laddare_hours' => 8,
    'hourly' => 750,
    'sokande' => 2,
];
$with = static function (array $over) use ($base): array {
    return array_merge($base, $over);
};
$expect = [
    'boot' => kc_round($base),
    'panels' => kc_round($with(['b14' => 13])),
    'modules' => kc_round($with(['b14' => 13, 'b3' => 3])),
    'neo12' => kc_round($with(['b14' => 13, 'b3' => 3, 'sigen_typ' => 'neo', 'sigen_kw' => '12.0', 'b15' => 0])),
    'neo10' => kc_round($with(['b14' => 13, 'b3' => 3, 'sigen_typ' => 'neo', 'sigen_kw' => '10.0', 'b15' => 0])),
];
$ladder = jeab_offert_neo_kundpris_ladder();
$wantLadder = [
    '7.5' => 39500,
    '15.0' => 55500,
    '19.5' => 65500,
    '22.5' => 68500,
    '30.0' => 81500,
    '37.5' => 94500,
];
if ($ladder !== $wantLadder) {
    kc_fail('NEO-trappan ' . json_encode($ladder, JSON_UNESCAPED_UNICODE));
}
$neoAuto = static function (float $modules, string $kw = '12.0') use ($base): array {
    $input = array_merge($base, [
        'sigen_typ' => 'neo',
        'sigen_kw' => $kw,
        'b15' => 0,
        'b3' => $modules,
        'b14' => 13,
    ]);
    unset($input['g27']);
    return jeab_offert_calc($input);
};
$g27Of = static function (array $calc): int {
    return (int) round((float) $calc['g27']);
};
$two = $neoAuto(2);
$three = $neoAuto(3);
$four = $neoAuto(4);
if ($g27Of($two) !== 55500 || $g27Of($three) !== 68500 || $g27Of($four) !== 81500) {
    kc_fail('kundpris ' . $g27Of($two) . '/' . $g27Of($three) . '/' . $g27Of($four));
}
if ($g27Of($three) <= $g27Of($two) || $g27Of($four) <= $g27Of($three)) {
    kc_fail('fler moduler höjde inte kundpriset');
}
if ($g27Of($two) === 111000 || $g27Of($three) === 137000) {
    kc_fail('kundpriset är dubbelt');
}
if ((int) round((float) $two['h33']) !== 55500) {
    kc_fail('h33 följer inte kundpriset');
}
if ($g27Of($neoAuto(1)) !== 39500 || $g27Of($neoAuto(5)) !== 94500 || jeab_offert_neo_kundpris(2.6) !== 65500) {
    kc_fail('trappsteg 7,5 / 37,5 / 19,5');
}
if ($g27Of($neoAuto(3, '8.0')) !== $g27Of($neoAuto(3, '15.0'))) {
    kc_fail('växelriktarstorlek ändrade kundpriset');
}
$locked = jeab_offert_calc($with(['sigen_typ' => 'neo', 'sigen_kw' => '10.0', 'b15' => 0, 'b3' => 4, 'g27' => 70000]));
if ($g27Of($locked) !== 70000) {
    kc_fail('angivet kundpris skrevs över');
}
$saved = jeab_offert_calc($with(['sigen_typ' => 'neo', 'sigen_kw' => '10.0', 'b15' => 0, 'b3' => 4, 'g27' => 80000]));
if ($g27Of($saved) !== 80000) {
    kc_fail('sparat kundpris skrevs över');
}
$tp2In = $base;
unset($tp2In['g27']);
if ($g27Of(jeab_offert_calc($tp2In)) !== 70000) {
    kc_fail('TP2 utan angivet kundpris');
}
$expect['ladder'] = $ladder;
$expect['g27_3'] = $g27Of($three);
$expect['g27_4'] = $g27Of($four);
$controls = jeab_offert_daily_controls_html(jeab_offert_calc($base));
file_put_contents($tmp . '/controls.html', $controls);
file_put_contents($tmp . '/expect.json', json_encode($expect));

$cache = sys_get_temp_dir() . '/jeab-jsdom-cache';
$jsdomPkg = $cache . '/node_modules/jsdom/package.json';
if (!is_file($jsdomPkg)) {
    if (!is_dir($cache) && !mkdir($cache, 0775, true) && !is_dir($cache)) {
        kc_fail('jsdom-cache');
    }
    $npm = [];
    $npmCode = 1;
    exec('npm install --prefix ' . escapeshellarg($cache) . ' jsdom@25 --silent 2>&1', $npm, $npmCode);
    if ($npmCode !== 0 || !is_file($jsdomPkg)) {
        kc_fail('jsdom install: ' . implode("\n", $npm));
    }
}

$js = <<<'JS'
const fs = require('fs');
const { JSDOM } = require('jsdom');
const controls = fs.readFileSync(process.argv[2], 'utf8');
const expect = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const app = fs.readFileSync(process.argv[4], 'utf8');
const shell = fs.readFileSync(process.argv[5], 'utf8');
const companies = ['jarfalla', 'malmo'];

function digits(value) {
  return String(value || '').replace(/\D/g, '');
}

function fail(message) {
  console.error('FAIL ' + message);
  process.exitCode = 1;
}

async function run(co) {
  const html = `<!doctype html><html><head><meta name="csrf" content="t"></head>
<body class="page-offert">
<header class="chrome"><nav class="tabs">
<a class="tab" href="/index.php?p=reminders&co=${co}">Påminnelser</a>
<a class="tab is-on" href="/index.php?p=offert&co=${co}">Kalkyl</a>
</nav></header>
<main class="main"><div class="offert" id="offert-app" data-js="offert">
<form class="offert-daily">
<a class="trap" href="/index.php?p=reminders&co=${co}">${controls}<span class="trap-hit">Öppna</span></a>
</form>
<p><b data-out="i14">0</b></p>
<input data-in="c23" data-auto="1" value="">
<input type="hidden" data-in="g27" data-auto="1" value="70000">
<input type="hidden" data-in="b5" value="24">
<input type="hidden" data-in="c13" value="2000">
<input type="hidden" data-in="b17" value="16">
<input type="hidden" data-in="c28" value="1558">
<input type="hidden" data-in="hourly" value="750">
<input type="hidden" data-in="laddare_unit" value="6793">
<input type="hidden" data-in="laddare_hours" value="8">
</div>
<section data-js="offert-ev"></section>
</main></body></html>`;
  const dom = new JSDOM(html, {
    url: `https://flowstack.example.test/index.php?p=offert&co=${co}`,
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const { window } = dom;
  window.document.querySelector('[data-js="offert"]').setAttribute('data-neo-kundpris', JSON.stringify(expect.ladder));
  window.scrollTo = function () {};
  let fetches = 0;
  window.fetch = function () {
    fetches += 1;
    return Promise.resolve({
      ok: true,
      headers: { get: () => 'application/json' },
      json: () => Promise.resolve({ ok: true, html: '<p>x</p>', title: 't', page: 'reminders', bodyClass: 'page-reminders', chrome: '', csrf: 't' }),
      text: () => Promise.resolve(''),
    });
  };
  window.eval(app);
  window.eval(shell);
  const doc = window.document;
  const price = () => digits(doc.querySelector('[data-out="i14"]').textContent);
  const click = (el) => {
    const event = new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    el.dispatchEvent(event);
    return event;
  };
  const touch = (el) => {
    el.dispatchEvent(new window.Event('touchstart', { bubbles: true, cancelable: true }));
  };
  if (price() !== String(expect.boot)) {
    fail(co + ' startpris ' + price() + ' vill ' + expect.boot);
  }
  const plusPanels = doc.querySelector('[data-for="b14"][data-delta="1"]');
  touch(plusPanels);
  plusPanels.dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true, cancelable: true, button: 0 }));
  const neoSpan = doc.querySelector('input[value="neo"]').parentElement.querySelector('span');
  touch(neoSpan);
  neoSpan.dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true, cancelable: true, button: 0 }));
  await new Promise((resolve) => setTimeout(resolve, 90));
  if (fetches !== 0) {
    fail(co + ' förhämtade formulärkontroll ' + fetches);
  }
  click(plusPanels);
  if (doc.querySelector('[data-in="b14"]').value.replace(/\s/g, '') !== '13' || price() !== String(expect.panels)) {
    fail(co + ' paneler ' + doc.querySelector('[data-in="b14"]').value + ' pris ' + price());
  }
  click(doc.querySelector('[data-for="b3"][data-delta="1"]'));
  if (doc.querySelector('[data-in="b3"]').value.replace(/\s/g, '') !== '3' || price() !== String(expect.modules)) {
    fail(co + ' moduler pris ' + price());
  }
  click(neoSpan);
  const typeOn = [...doc.querySelectorAll('[aria-labelledby="offert-sigen-typ"] .is-on')].map((el) => el.textContent.trim());
  const sizes = [...doc.querySelectorAll('[data-sigen-sizes] input')].map((el) => el.value);
  if (doc.querySelector('[data-sigen-sizes]').hidden) {
    fail(co + ' NEO-storlek är dold');
  }
  if (typeOn.length !== 1 || typeOn[0] !== 'NEO') {
    fail(co + ' systemval ' + typeOn.join(','));
  }
  if (sizes.join(',') !== '8.0,10.0,12.0,15.0') {
    fail(co + ' NEO-storlekar ' + sizes.join(','));
  }
  if (!doc.querySelector('input[value="neo"]').checked || doc.querySelector('input[value="tp2"]').checked) {
    fail(co + ' båda systemen är ikryssade');
  }
  const kundpris = () => digits(doc.querySelector('[data-in="g27"]').value);
  if (kundpris() !== String(expect.g27_3)) {
    fail(co + ' kundpris 3 moduler ' + kundpris() + ' vill ' + expect.g27_3);
  }
  if (price() !== String(expect.neo12)) {
    fail(co + ' NEO 12 pris ' + price() + ' vill ' + expect.neo12);
  }
  const size10 = [...doc.querySelectorAll('[data-sigen-sizes] input')].find((el) => el.value === '10.0');
  click(size10.parentElement.querySelector('span'));
  const sizeOn = [...doc.querySelectorAll('[data-sigen-sizes] .is-on')].map((el) => el.textContent.trim());
  if (sizeOn.length !== 1 || !sizeOn[0].startsWith('10') || price() !== String(expect.neo10)) {
    fail(co + ' storlek 10 ' + sizeOn.join(',') + ' pris ' + price());
  }
  if (kundpris() !== String(expect.g27_3)) {
    fail(co + ' NEO-storlek ändrade kundpris ' + kundpris());
  }
  click(doc.querySelector('[data-for="b3"][data-delta="1"]'));
  if (doc.querySelector('[data-in="b3"]').value.replace(/\s/g, '') !== '4' || kundpris() !== String(expect.g27_4) || Number(kundpris()) <= Number(expect.g27_3)) {
    fail(co + ' fler moduler kundpris ' + kundpris() + ' vill ' + expect.g27_4);
  }
  click(doc.querySelector('[data-for="sokande"][data-delta="1"]'));
  if (doc.querySelector('[data-in="sokande"]').value.replace(/\s/g, '') !== '3') {
    fail(co + ' sökande ändrades inte');
  }
  if (fetches !== 0) {
    fail(co + ' klick på kalkylen navigerade ' + fetches);
  }
  const trap = click(doc.querySelector('.trap-hit'));
  if (!trap.defaultPrevented) {
    fail(co + ' navigeringslänk släpptes igenom');
  }
  const tab = click(doc.querySelector('a.tab:not(.is-on)'));
  if (!tab.defaultPrevented) {
    fail(co + ' menyklick släpptes igenom');
  }
  console.log('OK  ' + co + ' klick, touch och mus');
}

(async () => {
  for (const co of companies) {
    await run(co);
  }
  if (process.exitCode) {
    process.exit(process.exitCode);
  }
})().catch((err) => {
  console.error('FAIL ' + (err && err.stack ? err.stack : err));
  process.exit(1);
});
JS;
file_put_contents($tmp . '/click.js', $js);
$nodeLines = [];
$nodeCode = 1;
$env = 'NODE_PATH=' . escapeshellarg($cache . '/node_modules');
exec(
    $env . ' node ' . escapeshellarg($tmp . '/click.js') . ' '
    . escapeshellarg($tmp . '/controls.html') . ' '
    . escapeshellarg($tmp . '/expect.json') . ' '
    . escapeshellarg(dirname(__DIR__) . '/assets/js/app.js') . ' '
    . escapeshellarg(dirname(__DIR__) . '/assets/js/shell.js') . ' 2>&1',
    $nodeLines,
    $nodeCode
);
echo implode("\n", $nodeLines) . "\n";
if ($nodeCode !== 0) {
    kc_fail('kalkyl-klick');
}

echo "kalkyl click: ok\n";
