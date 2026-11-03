<?php

declare(strict_types=1);

/**
 * Kalkylens förvalda batteri är Neo 12 kW.
 * «spara nya värden som standard» sparar batterivalet och sidan förväljer det.
 * Paneler, orderkalkylens TP2-läge och priserna lämnas orörda. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-offert-battery-' . bin2hex(random_bytes(4));
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
file_put_contents($tmp . '/store.json', "{}\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function obd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function obd_checked(string $html, string $name, string $value): bool
{
    return preg_match('/name="' . preg_quote($name, '/') . '" value="' . preg_quote($value, '/') . '" checked/', $html) === 1;
}

$defaults = jeab_offert_defaults();
obd((float) $defaults['b14'] === 12.0 && (float) $defaults['b15'] === 1.0, 'paneler är 12 och TP2-flaggan är 1 i standardvärdena');

$bare = jeab_offert_calc([]);
obd(($bare['sigen_typ'] ?? '') === '' && (int) round((float) $bare['b14']) === 12 && (int) round((float) $bare['b15']) === 1, 'kalkylen utan systemval räknar som förut');

$order = jeab_offert_kalkyl_input(['kind' => 'sol'], 'sol');
obd(($order['sigen_typ'] ?? '') === 'tp2' && ($order['sigen_kw'] ?? '') === '12.0' && (int) round((float) $order['b14']) === 12, 'orderkalkyl utan val är fortfarande TP2 12 kW och 12 paneler');

$store = jeab_store();
$page = require dirname(__DIR__) . '/src/pages/offert.php';
obd(is_string($page), 'kalkylsidan renderas');
$html = (string) $page;
obd(obd_checked($html, 'sigen_typ', 'neo') && !obd_checked($html, 'sigen_typ', 'tp2') && !obd_checked($html, 'sigen_typ', 'ec'), 'Neo är förvalt när inget batteri är sparat');
obd(obd_checked($html, 'sigen_kw', '12.0'), 'Neo 12 kW är förvalt');
obd(preg_match('/name="b14"[^>]*value="12"/', $html) === 1, 'panelerna står kvar på 12');
obd(preg_match('/data-standard="([^"]+)"/', $html, $standardAttr) === 1, 'sparläget har data-standard');
$standardJson = html_entity_decode($standardAttr[1] ?? '', ENT_QUOTES);
obd(str_contains($standardJson, '"sigen_typ":"neo"') && str_contains($standardJson, '"sigen_kw":"12.0"'), 'sparläget känner förvalet Neo 12 kW');
obd(jeab_offert_saved_battery($store) === null, 'förvalet skrivs inte till lagringen förrän knappen används');

$beforePrices = jeab_offert_load_standard($store)['offert'];
$saved = jeab_offert_save_standard($store, [
    'hourly' => '800',
    'sigen_typ' => 'ec',
    'sigen_kw' => '15',
    'b15' => '0',
    'b14' => '12',
]);
obd((int) ($saved['offert']['hourly'] ?? 0) === 800, 'ett ändrat pris sparas som förut');
obd(($saved['sigen']['sigen_typ'] ?? '') === 'ec' && ($saved['sigen']['sigen_kw'] ?? '') === '15.0', 'batterivalet sparas i samma standard');
obd((float) ($saved['offert']['b15'] ?? 0) === 1.0, 'TP2-flaggan skrivs inte om när batteriet sparas');
obd((float) ($saved['offert']['b14'] ?? 0) === 12.0, 'panelantalet är oförändrat');
obd((float) ($saved['offert']['c4'] ?? 0) === (float) $beforePrices['c4'] && (float) ($saved['offert']['c27'] ?? 0) === (float) $beforePrices['c27'], 'inköpspriserna som inte postats ligger kvar');

$kept = jeab_offert_save_standard($store, [
    'sigen_typ' => 'neo',
    'sigen_kw' => '9.12',
    'hourly' => '800',
]);
obd(($kept['sigen']['sigen_typ'] ?? '') === 'ec' && ($kept['sigen']['sigen_kw'] ?? '') === '15.0', 'ogiltig storlek sparas inte över det valda batteriet');

$orderAfter = jeab_offert_kalkyl_input(['kind' => 'sol'], 'sol');
obd(($orderAfter['sigen_typ'] ?? '') === 'tp2' && ($orderAfter['sigen_kw'] ?? '') === '12.0', 'sparat batteri ändrar inte orderkalkylens förval');

$pageSaved = require dirname(__DIR__) . '/src/pages/offert.php';
$savedHtml = (string) $pageSaved;
obd(obd_checked($savedHtml, 'sigen_typ', 'ec') && obd_checked($savedHtml, 'sigen_kw', '15.0'), 'sparat EC 15 kW är förvalt när kalkylen öppnas');
obd(!obd_checked($savedHtml, 'sigen_typ', 'neo'), 'Neo är inte förvalt när ett annat batteri är sparat');
obd(preg_match('/name="b14"[^>]*value="12"/', $savedHtml) === 1, 'panelerna är 12 även med sparat batteri');

file_put_contents($tmp . '/save.js', <<<'JS'
const fs = require('fs');
const app = fs.readFileSync(process.argv[2], 'utf8');
const start = app.indexOf('function jeabBootOffertSave()');
const end = app.indexOf('window.jeabBootOffertSave = jeabBootOffertSave;', start);
if (start < 0 || end < 0) {
  console.error('FAIL hittade inte sparfunktionen');
  process.exit(1);
}

function el(spec) {
  return {
    spec,
    dataset: Object.assign({}, spec.dataset || {}),
    value: spec.value || '',
    checked: !!spec.checked,
    type: spec.type || '',
    name: spec.name || '',
    hidden: false,
    className: '',
    classList: { toggle() {}, add() {} },
    listeners: {},
    attrs: {},
    childList: [],
    setAttribute(name, value) { this.attrs[name] = value; },
    removeAttribute(name) { delete this.attrs[name]; },
    getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; },
    addEventListener(type, fn) { this.listeners[type] = fn; },
    prepend(node) { this.childList.unshift(node); },
    querySelector(sel) {
      return this.childList.find((node) => node.matches(sel)) || null;
    },
    matches(sel) {
      return sel.split(',').some((part) => {
        part = part.trim();
        if (part === '[data-in]' ) {
          return !!this.dataset.in;
        }
        if (part === 'input[name="sigen_typ"]' || part === 'input[name="sigen_kw"]') {
          const want = part.includes('sigen_typ') ? 'sigen_typ' : 'sigen_kw';
          return this.name === want;
        }
        if (part === '[data-js="offert-save-error"]') {
          return this.dataset.js === 'offert-save-error';
        }
        return false;
      });
    },
  };
}

const panels = el({ dataset: { in: 'b14' }, value: '12', type: 'text' });
const hourly = el({ dataset: { in: 'hourly' }, value: '750', type: 'text' });
const g27 = el({ dataset: { in: 'g27', auto: '1' }, value: '70000', type: 'text' });
const neo = el({ name: 'sigen_typ', value: 'neo', type: 'radio', checked: true });
const ec = el({ name: 'sigen_typ', value: 'ec', type: 'radio' });
const kw12 = el({ name: 'sigen_kw', value: '12.0', type: 'radio', checked: true });
const kw15 = el({ name: 'sigen_kw', value: '15.0', type: 'radio' });
const radios = [neo, ec, kw12, kw15];
const fields = [panels, hourly, g27];
const note = el({ dataset: { js: 'offert-save-error' } });
note.hidden = true;
const bar = el({ dataset: { js: 'offert-save-bar', standard: JSON.stringify({ b14: 12, hourly: 750, sigen_typ: 'neo', sigen_kw: '12.0' }), action: 'index.php?p=offert' } });
bar.childList.push(note);
const btn = el({ dataset: { js: 'offert-save' } });
const listeners = {};
const document = {
  addEventListener(type, fn) { (listeners[type] || (listeners[type] = [])).push(fn); },
  querySelector(sel) {
    if (sel === '[data-js="offert-save-bar"]') return bar;
    if (sel === '[data-js="offert-save"]') return btn;
    if (sel === 'meta[name="csrf"]') return { getAttribute: () => 't' };
    if (sel === '[data-js="offert"] [data-in="g27"]') return g27;
    if (sel === '[data-js="offert"] input[name="sigen_typ"]:checked') return radios.find((node) => node.name === 'sigen_typ' && node.checked) || null;
    if (sel === '[data-js="offert"] input[name="sigen_kw"]:checked') return radios.find((node) => node.name === 'sigen_kw' && node.checked) || null;
    return null;
  },
  querySelectorAll(sel) {
    if (sel === '[data-js="offert"] [data-in], [data-js="offert-ev"] [data-in]') return fields;
    return [];
  },
};
const window = { document, FormData: global.FormData, fetch: null, JSON };
let posted = null;
window.fetch = (_url, opts) => {
  posted = {};
  opts.body.forEach((value, key) => { posted[key] = String(value); });
  return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ ok: true }) });
};
const sandbox = { document, window, FormData: global.FormData, fetch: window.fetch, JSON, Promise, Object, String, Number, Math };
sandbox.globalThis = sandbox;
require('vm').runInNewContext(app.slice(start, end) + '\njeabBootOffertSave();', sandbox);
if (!bar.hidden) {
  console.error('FAIL oförändrat Neo 12 visade spara');
  process.exit(1);
}
neo.checked = false;
ec.checked = true;
kw12.checked = false;
kw15.checked = true;
listeners.change.forEach((fn) => fn({ target: ec }));
if (bar.hidden) {
  console.error('FAIL bytt batteri visade inte spara');
  process.exit(1);
}
btn.listeners.click({ preventDefault() {} });
setTimeout(() => {
  if (!posted || posted.sigen_typ !== 'ec' || posted.sigen_kw !== '15.0' || posted.b14 !== '12' || posted.action !== 'offert.defaults') {
    console.error('FAIL post ' + JSON.stringify(posted));
    process.exit(1);
  }
  if (Object.prototype.hasOwnProperty.call(posted, 'g27')) {
    console.error('FAIL automatiskt kundpris postades');
    process.exit(1);
  }
  console.log('OK  knappen postar EC 15 kW och 12 paneler');
}, 30);
JS);
$node = [];
$nodeCode = 1;
exec(
    'node ' . escapeshellarg($tmp . '/save.js') . ' ' . escapeshellarg(dirname(__DIR__) . '/assets/js/app.js') . ' 2>&1',
    $node,
    $nodeCode
);
echo implode("\n", $node) . "\n";
obd($nodeCode === 0, 'spara-knappen skickar det valda batteriet');

echo "offert battery default: ok\n";
