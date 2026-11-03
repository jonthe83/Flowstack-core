<?php

declare(strict_types=1);

/**
 * Tillbaka från ett ärende i Service (mobil) ska visa listan direkt.
 * En avbruten hämtning får inte lämna flyglåset eller aria-busy, och
 * popstate får inte ladda om sidan ovanpå inkorgens egen historik.
 */

$root = dirname(__DIR__);

function back_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function back_between(string $src, string $start, string $end): string
{
    $from = strpos($src, $start);
    $to = $from === false ? false : strpos($src, $end, $from + strlen($start));
    if ($from === false || $to === false) {
        return '';
    }

    return substr($src, $from, $to - $from);
}

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$nav = (string) file_get_contents($root . '/assets/js/inkorg-nav.js');
$shell = (string) file_get_contents($root . '/assets/js/shell.js');

$paint = back_between($page, 'function ixPaintThread', 'function ixShowPending');
back_ok($paint !== '', 'ixPaintThread finns');
$declared = strpos($paint, 'var id = window.jeabInkorgPaintId');
$attr = strpos($paint, "setAttribute('data-ix-open', id)");
back_ok($declared !== false && $attr !== false && $declared < $attr, 'data-ix-open sätts efter att id är deklarerad');
back_ok(strpos($paint, "setAttribute('data-ix-open', id)") !== false && !preg_match('/setAttribute\(\'data-ix-open\',\s*id\);\s*var id/', $paint), 'id används inte före var id');

$pop = back_between($page, "addEventListener('popstate'", "addEventListener('pageshow'");
back_ok(str_contains($pop, 'stopImmediatePropagation') && str_contains($pop, 'ixPerformBack'), 'popstate stannar skalets omladdning och visar listan');
back_ok(!str_contains($pop, 'location.reload'), 'Tillbaka laddar inte om dokumentet');
$shellPop = back_between($shell, "addEventListener('popstate'", "addEventListener('pageshow'");
back_ok($shellPop !== '' && str_contains($shellPop, 'isInbox(pageOf(location.href))') && str_contains($shellPop, 'jeabIxNav') && !str_contains($shellPop, 'location.reload'), 'skalet laddar inte om inkorgen på popstate');

$show = back_between($page, "addEventListener('pageshow'", 'jeabIxBind(document.getElementById');
back_ok(str_contains($show, 'ev.persisted') && str_contains($show, 'ixCancelFlight') && str_contains($show, "removeAttribute('aria-busy')"), 'bfcache släpper låset och tar bort snurran');
back_ok(str_contains($page, 'function ixCancelFlight') && str_contains($page, 'jeabInkorgNavBump'), 'avbruten hämtning nollställer flyglåset');
back_ok(str_contains($page, 'assets/js/inkorg-nav.js'), 'sidan laddar nav-hjälpen');
back_ok(str_contains($page, 'ixPerformBack(url, false)'), 'Tillbaka och Service-fliken ritar listan utan att vänta på nätet');

$node = getenv('NODE') ?: 'node';
$script = <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);
const key = nav.jeabInkorgListKey({ box: 'jarfalla', view: 'v-servicekund', typ: '', q: '', sida: '' });
assert.strictEqual(key, nav.jeabInkorgListKey({ box: 'jarfalla', view: 'v-servicekund' }));

const plan = nav.jeabInkorgBackPlan({
  hasId: false,
  sameFilters: true,
  hasCache: true,
  inbox: true
});
assert.strictEqual(plan.reveal, true);
assert.strictEqual(plan.fetch, false);
assert.strictEqual(plan.release, true);
assert.strictEqual(plan.stopReload, true);
assert.strictEqual(plan.clearBusy, true);

const cold = nav.jeabInkorgBackPlan({ hasId: false, sameFilters: false, hasCache: false, inbox: true });
assert.strictEqual(cold.reveal, false);
assert.strictEqual(cold.fetch, true);

function oldPaint() {
  let sink;
  function set(v) { sink = String(v); }
  set(id);
  var id = 'svc-1';
  return sink;
}
assert.strictEqual(oldPaint(), 'undefined');
assert.strictEqual(nav.jeabInkorgPaintId('svc-1', ''), 'svc-1');
assert.strictEqual(nav.jeabInkorgPaintId('', 'svc-1'), 'svc-1');
assert.notStrictEqual(nav.jeabInkorgPaintId('svc-1', ''), 'undefined');

const state = nav.jeabInkorgNavCreate();
state.open = true;
state.busy = true;
const hung = nav.jeabInkorgNavStart(state);
assert.notStrictEqual(hung, false);
assert.strictEqual(state.flight, 1);
const t0 = Date.now();
const back = nav.jeabInkorgNavBack(state, plan);
const ms = Date.now() - t0;
assert.strictEqual(back.revealed, true);
assert.strictEqual(back.fetch, false);
assert.strictEqual(back.stopReload, true);
assert.strictEqual(nav.jeabInkorgNavFinish(state, hung), false);
assert.strictEqual(state.flight, 0);
assert.strictEqual(state.busy, false);
assert.strictEqual(state.open, false);
assert.ok(ms < 1000, 'listan ska vara tillbaka inom 1 s, tog ' + ms);
const next = nav.jeabInkorgNavStart(state);
assert.notStrictEqual(next, false);
assert.strictEqual(state.flight, 1);
console.log('service-back ' + ms + 'ms flight-released');
JS;
$tmp = tempnam(sys_get_temp_dir(), 'ixback');
if ($tmp === false) {
    fwrite(STDERR, "FAIL tempfil\n");
    exit(1);
}
file_put_contents($tmp, $script);
$proc = proc_open(
    [$node, $tmp, $root . '/assets/js/inkorg-nav.js'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
back_ok(is_resource($proc), 'node startar');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
@unlink($tmp);
back_ok($code === 0, 'nav-simuleringen visar Service-listan utan hängande lås ' . trim($out . $err));
echo trim((string) $out) . "\n";
echo "inkorg-back-nav: ok\n";
