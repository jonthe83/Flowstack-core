<?php

declare(strict_types=1);

/**
 * Svarsrutan behåller texten i sessionStorage per tråd-id.
 * Nyckeln bär inget utöver tråd-id. Utkastet återställs när tråden
 * öppnas igen och rensas efter lyckat skick, inte vid fel.
 */

$root = dirname(__DIR__);

function rd_ok(bool $ok, string $message): void
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
$app = (string) file_get_contents($root . '/assets/js/app.js');

rd_ok(str_contains($page, 'assets/js/inkorg-nav.js'), 'sidan laddar inkorg-nav.js');
rd_ok(str_contains($page, 'jeabInkorgBindReplyDraft') && str_contains($page, 'sessionStorage'), 'svarsrutan binds mot sessionStorage');
rd_ok(str_contains($page, 'jeabInkorgReplyDraftApplyFlash'), 'lyckat skick rensar utkastet från flash');
rd_ok(str_contains($nav, 'jeab-ix-reply:') && str_contains($nav, 'jeabInkorgReplyDraftKey'), 'nyckeln är jeab-ix-reply plus tråd-id');
rd_ok(!str_contains($nav, 'from_name') && !str_contains($nav, 'personnummer') && !str_contains($nav, 'replyWho'), 'utkastnyckeln tar inte kundfält');
rd_ok(!str_contains($app, 'jeabInkorgBindReplyDraft') && !str_contains($app, 'jeab-ix-reply:'), 'app.js rör inte svarsutkastet');

$node = getenv('NODE') ?: 'node';
$script = <<<'JS'
const assert = require('assert');
const nav = require(process.argv[2]);

function store() {
  const data = Object.create(null);
  return {
    getItem(key) { return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null; },
    setItem(key, value) { data[key] = String(value); },
    removeItem(key) { delete data[key]; },
    data
  };
}

const id = 'g-9620ccf527eab062';
assert.strictEqual(nav.jeabInkorgReplyDraftId(id), id);
assert.strictEqual(nav.jeabInkorgReplyDraftId('kund@exempel.test'), '');
assert.strictEqual(nav.jeabInkorgReplyDraftId('Ada Kund'), '');
assert.strictEqual(nav.jeabInkorgReplyDraftId(''), '');
assert.strictEqual(nav.jeabInkorgReplyDraftKey(id), 'jeab-ix-reply:' + id);
assert.strictEqual(nav.jeabInkorgReplyDraftKey('kund@exempel.test'), '');
assert.ok(nav.jeabInkorgReplyDraftKey(id).indexOf('@') === -1);
assert.ok(nav.jeabInkorgReplyDraftKey(id).indexOf('Ada') === -1);

const mem = store();
assert.strictEqual(nav.jeabInkorgReplyDraftWrite(mem, id, 'Hej, vi bokar tisdag.'), true);
assert.strictEqual(mem.data['jeab-ix-reply:' + id], 'Hej, vi bokar tisdag.');
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem, id), 'Hej, vi bokar tisdag.');

assert.strictEqual(nav.jeabInkorgReplyDraftRestore('', 'Hej, vi bokar tisdag.'), 'Hej, vi bokar tisdag.');
assert.strictEqual(nav.jeabInkorgReplyDraftRestore('redan skrivet', 'sparat'), 'redan skrivet');
assert.strictEqual(nav.jeabInkorgReplyDraftRestore('', ''), '');

assert.strictEqual(nav.jeabInkorgReplyDraftWrite(mem, 'kund@exempel.test', 'hemligt'), false);
assert.strictEqual(Object.keys(mem.data).join(','), 'jeab-ix-reply:' + id);

const other = 't-other-thread';
assert.strictEqual(nav.jeabInkorgReplyDraftWrite(mem, other, 'Annan tråd'), true);
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem, id), 'Hej, vi bokar tisdag.');
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem, other), 'Annan tråd');

assert.strictEqual(nav.jeabInkorgReplyDraftFlashClears('Skickat från oss till kund.'), true);
assert.strictEqual(nav.jeabInkorgReplyDraftFlashClears('Utkast sparat. Inget skickat.'), true);
assert.strictEqual(nav.jeabInkorgReplyDraftFlashClears('Anteckning sparad.'), true);
assert.strictEqual(nav.jeabInkorgReplyDraftFlashClears('Något gick fel.'), false);
assert.strictEqual(nav.jeabInkorgReplyDraftFlashClears(''), false);

assert.strictEqual(nav.jeabInkorgReplyDraftMarkSent(mem, id), true);
assert.strictEqual(mem.data['jeab-ix-reply-sent'], id);
assert.strictEqual(nav.jeabInkorgReplyDraftApplyFlash(mem, 'Något gick fel.'), '');
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem, id), 'Hej, vi bokar tisdag.');
assert.strictEqual(nav.jeabInkorgReplyDraftApplyFlash(mem, 'Skickat från oss till kund. Ärendet stängt.'), id);
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem, id), '');
assert.strictEqual(mem.data['jeab-ix-reply-sent'], undefined);
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem, other), 'Annan tråd');

const mem2 = store();
nav.jeabInkorgReplyDraftWrite(mem2, id, 'Kvar vid omladdning');
assert.strictEqual(nav.jeabInkorgReplyDraftRestore('', nav.jeabInkorgReplyDraftRead(mem2, id)), 'Kvar vid omladdning');
nav.jeabInkorgReplyDraftClear(mem2, id);
assert.strictEqual(nav.jeabInkorgReplyDraftRead(mem2, id), '');

function mockForm(threadId, body) {
  const listeners = { input: [], submit: [] };
  const ta = {
    value: body || '',
    addEventListener(type, fn) { (listeners[type] || (listeners[type] = [])).push(fn); }
  };
  const thread = { value: threadId };
  const form = {
    querySelector(sel) {
      if (String(sel).indexOf('name=thread') !== -1) { return thread; }
      if (String(sel).indexOf('textarea') !== -1) { return ta; }
      return null;
    },
    addEventListener(type, fn) { (listeners[type] || (listeners[type] = [])).push(fn); },
    ta,
    listeners
  };
  return form;
}

const live = store();
const form = mockForm(id, '');
nav.jeabInkorgReplyDraftWrite(live, id, 'Utkast efter bakåt');
const bound = nav.jeabInkorgBindReplyDraft(form, live);
assert.strictEqual(bound.bound, true);
assert.strictEqual(bound.id, id);
assert.strictEqual(form.ta.value, 'Utkast efter bakåt');
form.ta.value = 'Ändrat i rutan';
form.listeners.input.forEach((fn) => fn());
assert.strictEqual(nav.jeabInkorgReplyDraftRead(live, id), 'Ändrat i rutan');
form.listeners.submit.forEach((fn) => fn({ defaultPrevented: true }));
assert.strictEqual(live.data['jeab-ix-reply-sent'], undefined);
form.listeners.submit.forEach((fn) => fn({ defaultPrevented: false }));
assert.strictEqual(live.data['jeab-ix-reply-sent'], id);
nav.jeabInkorgReplyDraftApplyFlash(live, 'Skickat från oss.');
assert.strictEqual(nav.jeabInkorgReplyDraftRead(live, id), '');

console.log('reply-draft node ok');
JS;
$tmp = tempnam(sys_get_temp_dir(), 'ixdraft');
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
rd_ok(is_resource($proc), 'node startar');
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
@unlink($tmp);
rd_ok($code === 0, 'utkastet sparas, återställs och rensas ' . trim($out . $err));
echo trim((string) $out) . "\n";
echo "inkorg-reply-draft: ok\n";
