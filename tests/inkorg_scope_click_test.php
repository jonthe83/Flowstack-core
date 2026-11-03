<?php

declare(strict_types=1);

/**
 * Klick på Allas ska fångas i inkorg-nav.js (delegerat, med ?v=),
 * skriva scope=allas i URL:en och hämta om listan. Tillbaka-cachen
 * får inte svälja klicket.
 */

$root = dirname(__DIR__);

function scope_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$nav = (string) file_get_contents($root . '/assets/js/inkorg-nav.js');
$boot = (string) file_get_contents($root . '/src/bootstrap.php');

scope_ok(str_contains($boot, "function jeab_asset_url(string \$path): string") && str_contains($boot, "\$keep[] = 'v=' . \$v;"), 'jeab_asset_url lägger ?v=mtime på skriptet');
scope_ok(str_contains($page, "jeab_asset_url('assets/js/inkorg-nav.js')"), 'sidan laddar inkorg-nav.js via jeab_asset_url');
scope_ok(str_contains($nav, "el.closest('a[data-ix-scope]')"), 'klick på data-ix-scope är delegerat');
scope_ok(str_contains($nav, "doc.addEventListener('click'") && str_contains($nav, '}, true);'), 'lyssnaren sitter på document i capture');

$skip = strpos($page, "a.hasAttribute('data-ix-scope')");
$back = strpos($page, 'ixPerformBack(url, false)');
scope_ok($skip !== false && $back !== false && $skip < $back, 'scope-klick når inte tillbaka-cachen');

$bind = strpos($page, 'jeabInkorgBindScopeClicks');
$push = strpos($page, "history.pushState({ ix: 'list', scope: hit.scope }");
$fetch = $push === false ? false : strpos($page, "ixFetch(url, 'list', true)", $push);
scope_ok($bind !== false && $fetch !== false && $push !== false && $bind < $push && $push < $fetch, 'klicket skriver scope i URL:en och hämtar listan');
scope_ok(str_contains($page, 'ixMarkScope(hit.scope)'), 'klicket markerar Egna eller Allas direkt');
scope_ok(str_contains($page, 'ixCountsFor(url)') && str_contains($nav, 'counts: next !== cur'), 'tillbaka till Egna räknar om räknarna');
scope_ok(str_contains($page, 'jeabInkorgBindSearch') && str_contains($nav, "doc.addEventListener('submit'"), 'Enter i sökfältet fångas innan sidan laddas om');
$searchBind = strpos($page, 'jeabInkorgBindSearch(function');
$searchPush = $searchBind === false ? false : strpos($page, "history.pushState({ ix: 'list', q: q }", $searchBind);
$searchFetch = $searchPush === false ? false : strpos($page, "ixFetch(url, 'list', true)", $searchPush);
scope_ok($searchBind !== false && $searchPush !== false && $searchFetch !== false && $searchPush < $searchFetch, 'Enter skriver q i URL:en innan listan hämtas');

$node = getenv('NODE') ?: 'node';
$script = <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);

const plan = nav.jeabInkorgScopeClickPlan('egna', 'allas');
assert.strictEqual(plan.scope, 'allas');
assert.strictEqual(plan.changed, true);
assert.strictEqual(plan.fetch, true);
assert.strictEqual(plan.reveal, false);
assert.strictEqual(plan.counts, true);

const cached = nav.jeabInkorgBackPlan({ hasId: false, sameFilters: false, hasCache: true, inbox: true });
assert.strictEqual(cached.reveal, true);
assert.strictEqual(plan.reveal, false, 'scope-byte får inte använda reveal bara för att listan finns i cache');

const missing = nav.jeabInkorgScopeClickPlan('', 'egna');
const deep = nav.jeabInkorgThreadRequest('/index.php?p=inkorg&scope=alla&box=jarfalla&id=g-9620ccf527eab062');
assert.strictEqual(deep.id, 'g-9620ccf527eab062');
assert.strictEqual(deep.scope, 'alla');
assert.strictEqual(deep.box, 'jarfalla');
assert.ok(deep.search.indexOf('ix_part=thread') !== -1);
assert.ok(deep.search.indexOf('id=g-9620ccf527eab062') !== -1);
assert.ok(deep.search.indexOf('scope=allas') === -1, deep.search);
assert.ok(deep.search.indexOf('4c24ac03b9ab6b1b') === -1);
assert.strictEqual(nav.jeabInkorgThreadRequest('/index.php?p=inkorg&scope=alla&box=jarfalla'), null);

assert.strictEqual(nav.jeabInkorgScopeKey(''), 'allas');
assert.strictEqual(nav.jeabInkorgScopeKey('egna'), 'egna');
assert.strictEqual(missing.fetch, true, 'adress utan scope är Allas, så Egna ska hämtas');
assert.strictEqual(missing.scope, 'egna');
assert.strictEqual(missing.counts, true);

const href = nav.jeabInkorgScopeHref('index.php?p=inkorg&view=v-inkorg&scope=egna&id=abc&sida=2&q=batteri', 'allas');
const built = new URL(href, 'http://local/');
assert.strictEqual(built.searchParams.get('scope'), 'allas');
assert.strictEqual(built.searchParams.get('view'), 'v-inkorg');
assert.strictEqual(built.searchParams.get('q'), 'batteri');
assert.strictEqual(built.searchParams.get('id'), null);
assert.strictEqual(built.searchParams.get('sida'), null);
assert.strictEqual(built.searchParams.get('ix_part'), null);

function El(scope, href) {
  this.scope = scope;
  this.href = href;
  this.nodeType = 1;
}
El.prototype.getAttribute = function (name) {
  if (name === 'data-ix-scope') return this.scope;
  if (name === 'href') return this.href;
  return null;
};
El.prototype.closest = function (sel) {
  return sel === 'a[data-ix-scope]' ? this : null;
};

const listeners = [];
global.document = {
  addEventListener: function (type, fn, capture) {
    listeners.push({ type: type, fn: fn, capture: capture });
  }
};
global.location = { href: 'http://local/index.php?p=inkorg&view=v-inkorg&scope=egna' };

const calls = [];
assert.strictEqual(nav.jeabInkorgBindScopeClicks(function (hit) { calls.push(hit); }), true);
assert.strictEqual(listeners.length, 1);
assert.strictEqual(listeners[0].type, 'click');
assert.strictEqual(listeners[0].capture, true);
assert.strictEqual(nav.jeabInkorgBindScopeClicks(function () {}), true);
assert.strictEqual(listeners.length, 1, 'ombindning efter ommålning ska inte dubblera lyssnaren');

const chip = new El('allas', '/index.php?p=inkorg&view=v-inkorg&scope=egna&id=t1');
let prevented = false;
listeners[0].fn({
  button: 0,
  target: { nodeType: 3, parentNode: chip },
  preventDefault: function () { prevented = true; },
  stopPropagation: function () {}
});
assert.strictEqual(prevented, true);
assert.strictEqual(calls.length, 1);
assert.strictEqual(calls[0].scope, 'allas');
const got = new URL(calls[0].href, 'http://local/');
assert.strictEqual(got.searchParams.get('scope'), 'allas');
assert.strictEqual(got.searchParams.get('p'), 'inkorg');
assert.strictEqual(got.searchParams.get('view'), 'v-inkorg');
assert.strictEqual(got.searchParams.get('id'), null);

const again = new El('allas', '/index.php?p=inkorg&view=v-inkorg&scope=allas');
listeners[0].fn({
  button: 0,
  target: again,
  preventDefault: function () {},
  stopPropagation: function () {}
});
assert.strictEqual(calls.length, 2, 'nytt chip efter listbyte ska fortfarande träffa samma lyssnare');

global.location = { href: 'http://local/index.php?p=inkorg&view=v-inkorg&scope=allas' };
const egna = new El('egna', '/index.php?p=inkorg&view=v-inkorg&scope=allas');
listeners[0].fn({
  button: 0,
  target: egna,
  preventDefault: function () {},
  stopPropagation: function () {}
});
assert.strictEqual(calls.length, 3);
assert.strictEqual(calls[2].scope, 'egna');
assert.strictEqual(new URL(calls[2].href, 'http://local/').searchParams.get('scope'), 'egna');

global.location = { href: 'http://local/index.php?p=inkorg&view=v-inkorg&box=jarfalla' };
const fromDefault = new El('egna', '/index.php?p=inkorg&view=v-inkorg&box=jarfalla&scope=egna');
listeners[0].fn({
  button: 0,
  target: fromDefault,
  preventDefault: function () {},
  stopPropagation: function () {}
});
assert.strictEqual(calls.length, 4, 'Egna från sidan utan scope ska hämtas');
assert.strictEqual(calls[3].scope, 'egna');
assert.strictEqual(new URL(calls[3].href, 'http://local/').searchParams.get('scope'), 'egna');
assert.strictEqual(calls[3].counts, true);

const kept = new URL(nav.jeabInkorgScopeHref(
  nav.jeabInkorgSearchHref('http://local/index.php?p=inkorg&view=v-information&scope=egna&q=batteri', {
    view: 'v-information',
    q: 'batteri',
    scope: 'egna'
  }),
  'allas'
), 'http://local/');
assert.strictEqual(kept.searchParams.get('view'), 'v-information');
assert.strictEqual(kept.searchParams.get('q'), 'batteri');
assert.strictEqual(kept.searchParams.get('scope'), 'allas');

global.location = { href: 'http://local/index.php?p=inkorg&view=v-inkorg&scope=egna' };
global.document.getElementById = function (id) {
  if (id !== 'ix') return null;
  return { getAttribute: function (name) { return name === 'data-ix-view' ? 'v-alla' : (name === 'data-ix-box' ? 'jarfalla' : ''); } };
};
global.document.querySelector = function (sel) {
  if (sel !== '#ix-list form.ix-lhead') return null;
  return {
    querySelector: function (s) {
      if (s === 'input[name=q]') return { value: 'batteri' };
      return null;
    }
  };
};
const stale = new El('allas', '/index.php?p=inkorg&view=v-inkorg&scope=egna');
listeners[0].fn({
  button: 0,
  target: stale,
  preventDefault: function () {},
  stopPropagation: function () {}
});
const stayed = new URL(calls[calls.length - 1].href, 'http://local/');
assert.strictEqual(stayed.searchParams.get('scope'), 'allas');
assert.strictEqual(stayed.searchParams.get('view'), 'v-alla', stayed.search);
assert.strictEqual(stayed.searchParams.get('q'), 'batteri');
assert.strictEqual(calls[calls.length - 1].counts, true);

const searchListeners = [];
const searchDoc = {
  addEventListener: function (type, fn, capture) {
    searchListeners.push({ type: type, fn: fn, capture: capture });
  },
  getElementById: function () {
    return { getAttribute: function (name) { return name === 'data-ix-view' ? 'v-inkorg' : 'jarfalla'; } };
  },
  querySelector: function () { return null; }
};
global.document = searchDoc;
global.location = { href: 'http://local/index.php?p=inkorg&view=v-inkorg&scope=egna' };
const found = [];
const form = {
  querySelector: function (s) {
    if (s === 'input[name=q]') return { value: 'batteri' };
    if (s === 'input[name=view]') return { value: 'v-inkorg' };
    if (s === 'input[name=scope]') return { value: 'egna' };
    if (s === 'input[name=box]') return { value: 'jarfalla' };
    return null;
  }
};
assert.strictEqual(nav.jeabInkorgBindSearch(function (hit) { found.push(hit); }), true);
const submit = searchListeners.filter(function (row) { return row.type === 'submit' && row.capture === true; })[0];
const enter = searchListeners.filter(function (row) { return row.type === 'keydown' && row.capture === true; })[0];
assert.ok(submit && enter);
let blocked = false;
const input = {
  value: 'batteri',
  closest: function (sel) { return sel.indexOf('input[name=q]') >= 0 || sel.indexOf('form') >= 0 ? (sel.indexOf('form') >= 0 ? form : input) : null; }
};
form.closest = function () { return form; };
enter.fn({
  key: 'Enter',
  target: input,
  preventDefault: function () { blocked = true; },
  stopPropagation: function () {}
});
assert.strictEqual(blocked, true);
assert.strictEqual(found.length, 1);
const searched = new URL(found[0].href, 'http://local/');
assert.strictEqual(searched.searchParams.get('q'), 'batteri');
assert.strictEqual(searched.searchParams.get('view'), 'v-inkorg');
assert.strictEqual(searched.searchParams.get('scope'), 'egna');
blocked = false;
submit.fn({
  target: form,
  preventDefault: function () { blocked = true; },
  stopPropagation: function () {}
});
assert.strictEqual(blocked, true, 'Enter får inte skicka formuläret utan q');
assert.strictEqual(found.length, 1, 'submit direkt efter Enter ska inte nollställa sökningen');

assert.strictEqual(nav.jeabInkorgCountText(0), '0');
assert.strictEqual(nav.jeabInkorgCountText(12), '12');
assert.strictEqual(nav.jeabInkorgCountText(null), null);
assert.strictEqual(nav.jeabInkorgCountText(''), null);
assert.strictEqual(nav.jeabInkorgCountText('nej'), null);

const clock = { now: Date.now() + 1000 };
Date.now = function () { return clock.now; };
global.location = { href: 'http://local/index.php?p=inkorg&view=v-inkorg&scope=egna&id=case-anna&box=jarfalla' };
searchDoc.getElementById = function () {
  return { getAttribute: function (name) { return name === 'data-ix-view' ? 'v-historik' : 'jarfalla'; } };
};
input.value = 'Anna';
form.querySelector = function (s) {
  if (s === 'input[name=q]') return input;
  if (s === 'input[name=view]') return { value: 'v-historik' };
  if (s === 'input[name=scope]') return { value: 'egna' };
  if (s === 'input[name=box]') return { value: 'jarfalla' };
  return null;
};
blocked = false;
enter.fn({
  key: 'Enter',
  target: input,
  preventDefault: function () { blocked = true; },
  stopPropagation: function () {}
});
assert.strictEqual(blocked, true);
assert.strictEqual(found.length, 2);
const anna = new URL(found[1].href, 'http://local/');
assert.strictEqual(anna.searchParams.get('q'), 'Anna');
assert.strictEqual(anna.searchParams.get('view'), 'v-historik');
assert.strictEqual(anna.searchParams.get('scope'), 'egna');
assert.strictEqual(anna.searchParams.get('id'), null);
assert.strictEqual(anna.searchParams.get('box'), 'jarfalla');

console.log('scope-click allas');
JS;
$tmp = tempnam(sys_get_temp_dir(), 'ixscope');
scope_ok($tmp !== false, 'tempfil');
file_put_contents($tmp, $script);
$proc = proc_open(
    [$node, $tmp, $root . '/assets/js/inkorg-nav.js'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
scope_ok(is_resource($proc), 'node startar');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
@unlink($tmp);
scope_ok($code === 0, 'klick på Allas sätter scope=allas och hämtar om ' . trim($out . $err));
echo trim((string) $out) . "\n";
echo "inkorg-scope-click: ok\n";
