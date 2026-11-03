'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

function assert(ok, message) {
  if (!ok) {
    console.error('FAIL ' + message);
    process.exit(1);
  }
  console.log('OK  ' + message);
}

function createDom(html) {
  const listeners = new Map();
  function listen(target, type, fn, opts) {
    const list = listeners.get(target) || [];
    const rec = { type, fn };
    list.push(rec);
    listeners.set(target, list);
    if (opts && opts.signal) {
      opts.signal.addEventListener('abort', () => {
        const cur = listeners.get(target) || [];
        listeners.set(target, cur.filter((row) => row !== rec));
      });
    }
  }
  function fire(target, type) {
    const ev = {
      type,
      target,
      bubbles: true,
      defaultPrevented: false,
      returnValue: undefined,
      preventDefault() { ev.defaultPrevented = true; },
      stopPropagation() {}
    };
    let node = target;
    while (node) {
      const list = (listeners.get(node) || []).filter((row) => row.type === type);
      list.forEach((row) => row.fn(ev));
      node = node.parentNode;
    }
    return ev;
  }

  function matches(el, sel) {
    sel = String(sel || '').trim();
    if (sel === '') return false;
    if (sel.indexOf(',') !== -1) {
      return sel.split(',').some((part) => matches(el, part));
    }
    const chunks = sel.split(/\s+/);
    if (chunks.length > 1) return false;
    let rest = chunks[0];
    const tag = rest.match(/^[a-zA-Z][\w-]*/);
    if (tag) {
      if (el.tagName !== tag[0].toUpperCase()) return false;
      rest = rest.slice(tag[0].length);
    }
    while (rest.charAt(0) === '#') {
      const m = rest.match(/^#([\w-]+)/);
      if (!m || el.attrs.id !== m[1]) return false;
      rest = rest.slice(m[0].length);
    }
    while (rest.charAt(0) === '.') {
      const m = rest.match(/^\.([\w-]+)/);
      if (!m || el.classes.indexOf(m[1]) === -1) return false;
      rest = rest.slice(m[0].length);
    }
    while (rest.charAt(0) === '[') {
      const m = rest.match(/^\[([\w-]+)(?:="([^"]*)")?\]/);
      if (!m) return false;
      if (m[2] === undefined) {
        if (!Object.prototype.hasOwnProperty.call(el.attrs, m[1])) return false;
      } else if (el.attrs[m[1]] !== m[2]) return false;
      rest = rest.slice(m[0].length);
    }
    return rest === '';
  }

  function walk(el, fn) {
    fn(el);
    el.children.forEach((child) => walk(child, fn));
  }
  function queryAll(root, sel) {
    const out = [];
    const parts = String(sel).trim().split(/\s+/);
    function rec(node, i) {
      node.children.forEach((child) => {
        if (matches(child, parts[i])) {
          if (i === parts.length - 1) out.push(child);
          else rec(child, i + 1);
        }
        rec(child, i);
      });
    }
    if (matches(root, parts[0])) {
      if (parts.length === 1) out.push(root);
      else rec(root, 1);
    }
    rec(root, 0);
    return out;
  }

  function make(tag) {
    const el = {
      tagName: String(tag || 'DIV').toUpperCase(),
      nodeType: 1,
      children: [],
      parentNode: null,
      attrs: {},
      classes: [],
      style: {
        setProperty() {},
        removeProperty() {},
        height: ''
      },
      hidden: false,
      value: '',
      defaultValue: '',
      disabled: false,
      textContent: '',
      isConnected: true,
      offsetHeight: 10,
      offsetWidth: 10,
      offsetLeft: 0,
      clientWidth: 10,
      scrollLeft: 0,
      form: null,
      nextElementSibling: null,
      parentElement: null
    };
    el.classList = {
      toggle(name, on) {
        const has = el.classes.indexOf(name) !== -1;
        const force = on === undefined ? !has : !!on;
        if (force && !has) el.classes.push(name);
        if (!force && has) el.classes = el.classes.filter((c) => c !== name);
        return force;
      },
      add(name) { if (el.classes.indexOf(name) === -1) el.classes.push(name); },
      remove(name) { el.classes = el.classes.filter((c) => c !== name); },
      contains(name) { return el.classes.indexOf(name) !== -1; }
    };
    el.getAttribute = (k) => (Object.prototype.hasOwnProperty.call(el.attrs, k) ? el.attrs[k] : null);
    el.setAttribute = (k, v) => {
      el.attrs[k] = String(v);
      if (k === 'class') el.classes = String(v).split(/\s+/).filter(Boolean);
      if (k === 'id') el.id = String(v);
      if (k === 'name') el.name = String(v);
      if (k === 'type') el.type = String(v);
      if (k === 'value') el.value = String(v);
    };
    el.hasAttribute = (k) => Object.prototype.hasOwnProperty.call(el.attrs, k);
    el.removeAttribute = (k) => { delete el.attrs[k]; };
    el.matches = (sel) => matches(el, sel);
    el.closest = (sel) => {
      let n = el;
      while (n) {
        if (n.matches && n.matches(sel)) return n;
        n = n.parentNode;
      }
      return null;
    };
    el.contains = (n) => {
      if (n === el) return true;
      let p = n;
      while (p) {
        if (p === el) return true;
        p = p.parentNode;
      }
      return false;
    };
    el.querySelector = (sel) => queryAll(el, sel)[0] || null;
    el.querySelectorAll = (sel) => queryAll(el, sel);
    el.getBoundingClientRect = () => ({ top: 1000, left: 0, width: 10, height: 10, bottom: 1010, right: 10 });
    el.addEventListener = (type, fn, opts) => listen(el, type, fn, opts);
    el.removeEventListener = () => {};
    el.dispatchEvent = (ev) => fire(el, ev.type || ev);
    el.focus = () => {};
    el.click = () => {};
    el.scrollTo = () => {};
    el.scrollIntoView = () => {};
    el.remove = () => {
      if (el.parentNode) {
        el.parentNode.children = el.parentNode.children.filter((c) => c !== el);
      }
      el.isConnected = false;
      el.form = null;
      el.parentNode = null;
    };
    el.appendChild = (child) => {
      child.parentNode = el;
      child.parentElement = el;
      el.children.push(child);
      let prev = null;
      el.children.forEach((c) => {
        if (prev) prev.nextElementSibling = c;
        prev = c;
      });
      if (prev) prev.nextElementSibling = null;
      let owner = el.tagName === 'FORM' ? el : null;
      let p = el;
      while (!owner && p) {
        if (p.tagName === 'FORM') owner = p;
        p = p.parentNode;
      }
      walk(child, (n) => {
        if (owner && (n.tagName === 'INPUT' || n.tagName === 'TEXTAREA' || n.tagName === 'SELECT' || n === child && (child.tagName === 'INPUT' || child.tagName === 'TEXTAREA'))) n.form = owner;
      });
      if (owner && (child.tagName === 'INPUT' || child.tagName === 'TEXTAREA' || child.tagName === 'SELECT')) child.form = owner;
      return child;
    };
    return el;
  }

  const docEl = make('html');
  const body = make('body');
  docEl.appendChild(body);
  const document = {
    documentElement: docEl,
    body,
    readyState: 'complete',
    visibilityState: 'visible',
    querySelector: (sel) => queryAll(docEl, sel)[0] || null,
    querySelectorAll: (sel) => queryAll(docEl, sel),
    getElementById: (id) => queryAll(docEl, '#' + id)[0] || null,
    addEventListener: (type, fn, opts) => listen(document, type, fn, opts),
    createElement: (tag) => make(tag)
  };
  document.documentElement.scrollHeight = 800;

  function parse(fragment, parent) {
    const re = /<([a-zA-Z0-9]+)([^>]*)>|<\/([a-zA-Z0-9]+)>/g;
    const stack = [parent];
    let m;
    while ((m = re.exec(fragment))) {
      if (m[3]) {
        if (stack.length > 1) stack.pop();
        continue;
      }
      const node = make(m[1]);
      const attrRe = /([:@\w-]+)(?:="([^"]*)")?/g;
      let a;
      const raw = m[2] || '';
      while ((a = attrRe.exec(raw))) {
        if (a[1] === 'class') node.setAttribute('class', a[2] || '');
        else node.setAttribute(a[1], a[2] === undefined ? '' : a[2]);
      }
      stack[stack.length - 1].appendChild(node);
      if (!/\/>$/.test(m[0]) && !/^(input|br|hr|meta|link)$/i.test(m[1])) stack.push(node);
    }
  }

  const window = {
    document,
    location: { href: 'https://flowstack.example.test/index.php?p=ordrar&id=253', hash: '' },
    history: { state: null, replaceState() {} },
    navigator: { sendBeacon() { return false; } },
    innerHeight: 800,
    scrollY: 0,
    scrollTo() {},
    matchMedia() { return { matches: false, addEventListener() {} }; },
    getComputedStyle() { return { position: 'static' }; },
    requestAnimationFrame(fn) { return setTimeout(fn, 0); },
    sessionStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
    addEventListener: (type, fn, opts) => listen(window, type, fn, opts),
    fetch: null,
    FormData: global.FormData,
    AbortController: global.AbortController,
    setTimeout: global.setTimeout,
    clearTimeout: global.clearTimeout
  };
  window.window = window;
  document.defaultView = window;

  function mountHtml(html) {
    body.children = [];
    parse(html, body);
  }
  function pagehide() { return fire(window, 'pagehide'); }
  function beforeunload() { return fire(window, 'beforeunload'); }

  mountHtml(html);
  return { window, document, mountHtml, pagehide, beforeunload, fire };
}

const calls = [];
const html = '<div data-v3-root><form id="v3-f-bekrafta" action="/index.php?p=ordrar&id=253"><input type="hidden" name="_csrf" value="tok"><input type="hidden" name="id" value="253"><div class="v3-fieldline"><textarea id="v3-note" name="install_note" data-v3-autosave-action="ordrar.install_note"></textarea><p class="v3-autosave" data-v3-field-status="" role="status"></p></div></form></div>';

function entriesOf(body) {
  const entries = [];
  if (body && body.entries) {
    for (const pair of body.entries()) entries.push(pair);
  }
  return entries;
}
function savedJson(entries) {
  const map = {};
  entries.forEach((pair) => { map[pair[0]] = pair[1]; });
  const note = String(map.install_note == null ? '' : map.install_note).trim();
  return JSON.stringify({ ok: true, status: 'Sparad.', row: { install_note: note } });
}

const dom = createDom(html);
dom.window.fetch = (url, opts) => {
  const entries = entriesOf(opts && opts.body);
  calls.push({ url, keepalive: !!(opts && opts.keepalive), headers: opts && opts.headers, entries });
  return Promise.resolve({
    ok: true,
    status: 200,
    text: () => Promise.resolve(savedJson(entries))
  });
};

const context = dom.window;
context.global = context;
context.console = console;
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/ordrar-v3.js'), 'utf8'), context, { filename: 'ordrar-v3.js' });

function note() {
  return dom.document.getElementById('v3-note');
}
function status() {
  const el = dom.document.querySelector('[data-v3-field-status]');
  return el ? el.textContent : '';
}
function bodyMap(call) {
  const out = {};
  (call.entries || []).forEach((pair) => { out[pair[0]] = pair[1]; });
  return out;
}
function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

(async () => {
  const area = note();
  assert(!!area && area.form && area.form.getAttribute('id') === 'v3-f-bekrafta', 'fältet sitter i bekräfta-formuläret');
  const calm = dom.beforeunload();
  assert(calm.defaultPrevented !== true, 'beforeunload är tyst innan något har ändrats');
  dom.pagehide();
  await wait(20);
  assert(calls.length === 0, 'oförändrad anteckning skickas inte vid pagehide');

  area.value = 'Nyckel under mattan';
  dom.fire(area, 'input');
  dom.pagehide();
  await wait(30);
  assert(calls.length === 1, 'pagehide före debounce skickar en gång, fick ' + calls.length);
  assert(calls[0].keepalive === true, 'pagehide använder keepalive');
  const posted = bodyMap(calls[0]);
  assert(posted.action === 'ordrar.install_note' && posted.install_note === 'Nyckel under mattan' && posted.id === '253' && posted._csrf === 'tok' && posted.v3 === '1', 'pagehide postar bara anteckningen');
  assert(!Object.prototype.hasOwnProperty.call(posted, 'owner_pnr[]') && !Object.prototype.hasOwnProperty.call(posted, 'personnummer'), 'pagehide tar inte med personnummer');
  dom.beforeunload();
  await wait(20);
  assert(calls.length === 1, 'beforeunload efter pagehide skickar inte igen');
  await wait(560);
  assert(calls.length === 1, 'debounce efter pagehide skickar inte igen');
  assert(status() === 'Sparad.', 'kvitto blir Sparad. när svaret hinner fram');

  calls.length = 0;
  area.value = 'Portkod 1234';
  dom.fire(area, 'input');
  dom.fire(area, 'focusout');
  await wait(30);
  assert(calls.length === 1 && bodyMap(calls[0]).install_note === 'Portkod 1234' && calls[0].keepalive === true, 'blur sparar direkt med keepalive');
  await wait(560);
  assert(calls.length === 1, 'debounce efter blur skickar inte igen');
  dom.pagehide();
  await wait(20);
  assert(calls.length === 1, 'pagehide efter blur skickar inte igen');

  calls.length = 0;
  area.value = 'A';
  dom.fire(area, 'input');
  area.value = 'AB';
  dom.fire(area, 'input');
  await wait(560);
  assert(calls.length === 1 && bodyMap(calls[0]).install_note === 'AB', 'två snabba inmatningar blir ett sparande');

  calls.length = 0;
  area.value = 'Kvar efter omladdning';
  dom.fire(area, 'input');
  const typed = area;
  typed.remove();
  assert(typed.form === null && typed.isConnected === false, 'omritning släpper fältet från formuläret');
  dom.mountHtml('<div data-v3-root><form id="v3-f-bekrafta" action="/index.php?p=ordrar&id=253"><input type="hidden" name="_csrf" value="tok"><input type="hidden" name="id" value="253"><div class="v3-fieldline"><textarea id="v3-note" name="install_note" data-v3-autosave-action="ordrar.install_note"></textarea><p class="v3-autosave" data-v3-field-status role="status"></p></div></form></div>');
  context.jeabOrdrarV3Mount();
  await wait(40);
  assert(calls.length === 1 && calls[0].keepalive === true && bodyMap(calls[0]).install_note === 'Kvar efter omladdning', 'omritning skickar ögonblicksbilden innan nya fältet tar över');
  await wait(560);
  assert(calls.length === 1, 'gammal debounce efter omritning skickar inte igen');
  assert(note().value === '', 'nya kortet visar serverns tomma fält tills svaret sparats');

  calls.length = 0;
  let failOnce = true;
  const realFetch = dom.window.fetch;
  dom.window.fetch = (url, opts) => {
    if (failOnce) {
      failOnce = false;
      const entries = entriesOf(opts && opts.body);
      calls.push({ url, keepalive: !!(opts && opts.keepalive), entries });
      return Promise.resolve({ ok: false, status: 500, text: () => Promise.resolve('{"ok":false,"error":"upptagen"}') });
    }
    return realFetch(url, opts);
  };
  const again = note();
  again.value = 'Försök igen';
  dom.fire(again, 'focusout');
  await wait(30);
  assert(calls.length === 1 && status().indexOf('Kunde inte spara.') === 0, 'fel svar visar Kunde inte spara.');
  dom.fire(again, 'focusout');
  await wait(30);
  assert(calls.length === 2 && bodyMap(calls[1]).install_note === 'Försök igen' && status() === 'Sparad.', 'misslyckat sparande kan skickas igen');

  calls.length = 0;
  const beacons = [];
  dom.window.fetch = () => { throw new Error('keepalive'); };
  dom.window.navigator.sendBeacon = (url, body) => {
    beacons.push({ url, entries: entriesOf(body) });
    return true;
  };
  const beaconNote = note();
  beaconNote.value = 'Bara beacon';
  dom.fire(beaconNote, 'focusout');
  await wait(30);
  assert(beacons.length === 1 && bodyMap({ entries: beacons[0].entries }).install_note === 'Bara beacon', 'fetch som kastar köar sendBeacon med formulärkroppen');
  assert(status() !== 'Sparad.', 'sendBeacon utan HTTP 200 visar inte Sparad.');

  calls.length = 0;
  beacons.length = 0;
  dom.window.navigator.sendBeacon = () => false;
  dom.window.fetch = (url, opts) => {
    const entries = entriesOf(opts && opts.body);
    calls.push({ url, entries });
    return Promise.resolve({
      ok: true,
      status: 200,
      text: () => Promise.resolve('{"ok":true,"status":"Sparad."}')
    });
  };
  const bare = note();
  bare.value = 'Utan rad';
  dom.fire(bare, 'focusout');
  await wait(30);
  assert(calls.length === 1 && status() !== 'Sparad.' && status().indexOf('Kunde inte spara.') === 0, 'ok:true utan sparad anteckning i raden visar inte Sparad.');

  calls.length = 0;
  const pending = [];
  dom.window.fetch = (url, opts) => {
    const entries = entriesOf(opts && opts.body);
    const rec = { url, keepalive: !!(opts && opts.keepalive), headers: opts && opts.headers, entries };
    pending.push(rec);
    calls.push(rec);
    return new Promise(function () {});
  };
  const live = note();
  live.value = 'QA sparar';
  dom.fire(live, 'focusout');
  await wait(30);
  assert(pending.length === 1 && status() === 'Sparar…', 'pågående sparande visar Sparar.');
  const warn = dom.beforeunload();
  assert(warn.defaultPrevented === true && warn.returnValue === '', 'beforeunload varnar medan Sparar. visas');
  assert(pending.length === 1, 'beforeunload skickar inte om en sparning redan pågår');
  dom.document.visibilityState = 'visible';
  dom.fire(dom.document, 'visibilitychange');
  assert(pending.length === 1, 'synlig sida skickar inte om sparandet');
  dom.document.visibilityState = 'hidden';
  dom.fire(dom.document, 'visibilitychange');
  await wait(20);
  assert(pending.length === 2 && pending[1].keepalive === true, 'dold sida skickar den väntande anteckningen med keepalive');
  const hiddenBody = bodyMap(pending[1]);
  assert(hiddenBody.action === 'ordrar.install_note' && hiddenBody.install_note === 'QA sparar' && hiddenBody.id === '253' && hiddenBody._csrf === 'tok' && hiddenBody.v3 === '1', 'dold sida postar samma fält');
  dom.pagehide();
  await wait(20);
  assert(pending.length === 3 && pending[2].keepalive === true && bodyMap(pending[2]).install_note === 'QA sparar', 'pagehide skickar texten igen när den dolda sidans fetch inte är levererad');
  assert(status() !== 'Sparad.', 'obevarat svar visar inte Sparad.');

  dom.document.visibilityState = 'visible';
  dom.fire(dom.document, 'visibilitychange');
  const beaconHide = [];
  dom.window.fetch = () => { throw new Error('keepalive'); };
  dom.window.navigator.sendBeacon = (url, body) => {
    beaconHide.push({ url, entries: entriesOf(body) });
    return true;
  };
  const hideNote = note();
  hideNote.value = 'Beacon vid gömma';
  dom.document.visibilityState = 'hidden';
  dom.fire(dom.document, 'visibilitychange');
  await wait(20);
  assert(beaconHide.length === 1 && bodyMap({ entries: beaconHide[0].entries }).action === 'ordrar.install_note' && bodyMap({ entries: beaconHide[0].entries }).install_note === 'Beacon vid gömma' && bodyMap({ entries: beaconHide[0].entries }).id === '253' && bodyMap({ entries: beaconHide[0].entries })._csrf === 'tok', 'dold sida använder sendBeacon när fetch kastar');
  assert(status() !== 'Sparad.', 'beacon vid gömma visar inte Sparad.');

  const qaFetches = [];
  dom.window.fetch = (url, opts) => {
    const entries = entriesOf(opts && opts.body);
    qaFetches.push({ url, keepalive: !!(opts && opts.keepalive), entries });
    return new Promise((resolve, reject) => {
      const signal = opts && opts.signal;
      if (signal && signal.aborted) {
        reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        return;
      }
      if (signal) {
        signal.addEventListener('abort', () => {
          reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        });
      }
    });
  };
  const qaBeacons = [];
  dom.window.navigator.sendBeacon = (url, body) => {
    qaBeacons.push({ url, entries: entriesOf(body) });
    return true;
  };
  const qa = note();
  qa.value = 'QA10';
  dom.fire(qa, 'focusout');
  await wait(30);
  assert(qaFetches.length === 1 && status() === 'Sparar…' && bodyMap(qaFetches[0]).install_note === 'QA10', 'QA10 är på väg och visar Sparar.');
  const inflightRev = Number(bodyMap(qaFetches[0]).note_rev);
  qa.value = 'QA11';
  dom.pagehide();
  await wait(30);
  assert(qaBeacons.length === 1, 'pagehide köar en beacon, fick ' + qaBeacons.length);
  assert(qaFetches.length === 1, 'pagehide använder inte fetch när sendBeacon tar emot');
  const qaBody = bodyMap({ entries: qaBeacons[0].entries });
  assert(qaBody.install_note === 'QA11' && qaBody.action === 'ordrar.install_note' && qaBody.id === '253' && qaBody._csrf === 'tok' && qaBody.v3 === '1', 'beacon bär den aktuella texten och samma fält');
  assert(Number(qaBody.note_rev) > inflightRev, 'beacon har högre revision än den pågående posten');
  assert(status() !== 'Sparad.', 'beacon av den aktuella texten visar inte Sparad.');
})().catch((err) => {
  console.error(err && err.stack ? err.stack : err);
  process.exit(1);
});
