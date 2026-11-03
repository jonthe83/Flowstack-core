'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const events = require('events');
events.defaultMaxListeners = 40;
if (typeof events.setMaxListeners === 'function') { events.setMaxListeners(40); }

function assert(ok, message) {
  if (!ok) {
    console.error('FAIL ' + message);
    process.exit(1);
  }
  console.log('OK  ' + message);
}

function createDom() {
  const listeners = new Map();
  function listen(target, type, fn, opts) {
    const list = listeners.get(target) || [];
    const rec = { type, fn };
    list.push(rec);
    listeners.set(target, list);
    if (opts && opts.signal && opts.signal.addEventListener) {
      opts.signal.addEventListener('abort', () => {
        const cur = listeners.get(target) || [];
        listeners.set(target, cur.filter((row) => row !== rec));
      });
    }
  }
  function fire(target, type, extra) {
    const ev = {
      type,
      target,
      bubbles: true,
      defaultPrevented: false,
      preventDefault() { ev.defaultPrevented = true; },
      stopPropagation() {}
    };
    if (extra) {
      Object.keys(extra).forEach((key) => { ev[key] = extra[key]; });
    }
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
    if (sel === '' || sel === ':scope' || sel === '>') return false;
    if (sel.indexOf(',') !== -1) return sel.split(',').some((part) => matches(el, part));
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
    const parts = String(sel).trim().split(/\s+/).filter((part) => part !== '>' && part !== ':scope');
    if (!parts.length) return out;
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
      styleProps: {},
      style: {
        setProperty(name, value) { el.styleProps[name] = String(value); },
        removeProperty(name) { delete el.styleProps[name]; },
        getPropertyValue(name) { return el.styleProps[name] || ''; }
      },
      hidden: false,
      value: '',
      defaultValue: '',
      disabled: false,
      isConnected: true,
      offsetHeight: 40,
      offsetWidth: 40,
      offsetLeft: 0,
      clientWidth: 40,
      scrollLeft: 0,
      form: null,
      nextElementSibling: null,
      parentElement: null
    };
    let text = '';
    let onclick = null;
    Object.defineProperty(el, 'textContent', {
      get() {
        const nested = el.children.map((child) => child.textContent || '').join('');
        return text + nested;
      },
      set(v) {
        text = v == null ? '' : String(v);
        el.children.forEach((child) => { child.parentNode = null; child.isConnected = false; });
        el.children = [];
      }
    });
    Object.defineProperty(el, 'className', {
      get() { return el.classes.join(' '); },
      set(v) { el.classes = String(v || '').split(/\s+/).filter(Boolean); }
    });
    Object.defineProperty(el, 'onclick', {
      get() { return onclick; },
      set(fn) { onclick = typeof fn === 'function' ? fn : null; }
    });
    el.classList = {
      toggle(name, on) {
        const has = el.classes.indexOf(name) !== -1;
        const force = on === undefined ? !has : !!on;
        if (force && !has) el.classes.push(name);
        if (!force && has) el.classes = el.classes.filter((c) => c !== name);
        return force;
      },
      add(...names) {
        names.forEach((name) => {
          if (el.classes.indexOf(name) === -1) el.classes.push(name);
        });
      },
      remove(...names) {
        const drop = new Set(names);
        el.classes = el.classes.filter((c) => !drop.has(c));
      },
      contains(name) { return el.classes.indexOf(name) !== -1; }
    };
    el.getAttribute = (k) => (Object.prototype.hasOwnProperty.call(el.attrs, k) ? el.attrs[k] : null);
    el.setAttribute = (k, v) => {
      el.attrs[k] = String(v);
      if (k === 'class') el.className = String(v);
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
    el.getBoundingClientRect = () => ({ top: 800, left: 0, width: 10, height: 10, bottom: 810, right: 10 });
    el.addEventListener = (type, fn, opts) => listen(el, type, fn, opts);
    el.removeEventListener = () => {};
    el.focus = () => {};
    el.scrollTo = () => {};
    el.scrollIntoView = () => {};
    el.remove = () => {
      if (el.parentNode) el.parentNode.children = el.parentNode.children.filter((c) => c !== el);
      el.isConnected = false;
      el.parentNode = null;
      el.parentElement = null;
    };
    el.click = () => {
      if (onclick) onclick({ preventDefault() {}, stopPropagation() {}, target: el });
      fire(el, 'click');
    };
    el.appendChild = (child) => {
      if (child.parentNode) child.parentNode.children = child.parentNode.children.filter((c) => c !== child);
      child.parentNode = el;
      child.parentElement = el;
      child.isConnected = true;
      el.children.push(child);
      walk(child, (n) => { n.isConnected = true; });
      let owner = el.tagName === 'FORM' ? el : null;
      let p = el;
      while (!owner && p) {
        if (p.tagName === 'FORM') owner = p;
        p = p.parentNode;
      }
      walk(child, (n) => {
        if (owner && (n.tagName === 'INPUT' || n.tagName === 'TEXTAREA' || n.tagName === 'SELECT' || n.tagName === 'BUTTON')) n.form = owner;
      });
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
    createElement: (tag) => make(tag),
    createTextNode: (text) => {
      const n = make('span');
      n.textContent = String(text == null ? '' : text);
      n.nodeType = 3;
      return n;
    }
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
    location: { href: 'https://flowstack.example.test/index.php?p=ordrar&id=10', hash: '', assign() {} },
    history: { state: null, replaceState() {} },
    navigator: { sendBeacon() { return false; } },
    innerHeight: 800,
    scrollY: 0,
    scrollTo() {},
    matchMedia() { return { matches: false, addEventListener() {}, removeEventListener() {} }; },
    getComputedStyle(el) {
      return {
        position: 'static',
        getPropertyValue(name) {
          return el && el.styleProps && el.styleProps[name] ? el.styleProps[name] : '';
        }
      };
    },
    requestAnimationFrame(fn) { return setTimeout(fn, 0); },
    sessionStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
    addEventListener: (type, fn, opts) => listen(window, type, fn, opts),
    AbortController: global.AbortController,
    FormData: null,
    fetch: null,
    setTimeout: global.setTimeout,
    clearTimeout: global.clearTimeout
  };
  window.window = window;
  document.defaultView = window;
  return { window, document, parse, fire };
}

function orderInner(id) {
  return '<section class="ov3" data-v3-root data-id="' + id + '" data-list="/lista" data-self="/order/' + id + '">'
    + '<div id="v3-ohead"><h1>Order ' + id + '</h1></div>'
    + '<div class="v3-toast" data-v3-toast role="status"></div>'
    + '<div id="v3-modal" hidden><h3 id="v3-m-title"></h3><p id="v3-m-text"></p><button type="button" id="v3-m-ok"></button></div>'
    + '<form id="v3-f-delete" method="post" action="/lista">'
    + '<input type="hidden" name="_csrf" value="tok">'
    + '<input type="hidden" name="action" value="ordrar.delete">'
    + '<input type="hidden" name="id" value="' + id + '">'
    + '</form>'
    + '<button type="button" data-v3="act" data-act="radera">Radera</button>'
    + '</section>';
}

function FakeFormData(form) {
  this.map = new Map();
  if (form && form.querySelectorAll) {
    form.querySelectorAll('input').forEach((input) => {
      if (input.getAttribute && input.getAttribute('name')) this.map.set(input.getAttribute('name'), input.value || '');
    });
  }
}
FakeFormData.prototype.set = function (key, value) { this.map.set(String(key), String(value)); };
FakeFormData.prototype.entries = function () { return this.map.entries(); };

function textOf(el) {
  return el && el.textContent ? String(el.textContent) : '';
}

const dom = createDom();
const context = dom.window;
context.console = console;
context.global = context;
let now = 1_000_000;
const tasks = [];
let seq = 1;
context.Date = { now: () => now };
context.setTimeout = (fn, ms) => {
  const id = seq++;
  tasks.push({ id, fn, at: now + Number(ms || 0) });
  return id;
};
context.clearTimeout = (id) => {
  const index = tasks.findIndex((task) => task.id === id);
  if (index >= 0) tasks.splice(index, 1);
};
context.FormData = FakeFormData;
const calls = [];
const nav = [];
context.location.assign = (url) => { nav.push(String(url)); };
context.fetch = (url, opts) => {
  const entries = [];
  if (opts && opts.body && opts.body.entries) {
    for (const pair of opts.body.entries()) entries.push(pair);
  }
  calls.push({ url: String(url), entries });
  return Promise.resolve({
    ok: true,
    status: 200,
    text: () => Promise.resolve('{"ok":true}')
  });
};

dom.parse('<main></main>', dom.document.body);
dom.parse(orderInner('10'), dom.document.querySelector('main'));

vm.createContext(context);
try {
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/js/ordrar-v3.js'), 'utf8'), context, { filename: 'ordrar-v3.js' });
} catch (err) {
  console.error(err && err.stack ? err.stack : err);
  process.exit(1);
}

function advance(ms) {
  now += ms;
  let guard = 0;
  while (guard++ < 30) {
    const due = tasks.filter((task) => task.at <= now).sort((a, b) => a.at - b.at || a.id - b.id);
    if (!due.length) return;
    const task = due[0];
    const index = tasks.findIndex((row) => row.id === task.id);
    if (index >= 0) tasks.splice(index, 1);
    task.fn();
  }
}
async function drain() {
  for (let i = 0; i < 10; i++) await Promise.resolve();
}
function deleteCalls() {
  return calls.filter((call) => call.entries.some((pair) => pair[0] === 'action' && pair[1] === 'ordrar.delete'));
}
function postedId(call) {
  const pair = call.entries.find((row) => row[0] === 'id');
  return pair ? pair[1] : '';
}
function undoButton() {
  return dom.document.querySelector('[data-v3-delete-undo] [data-v3-undo]');
}
function confirmDelete() {
  const act = dom.document.querySelector('[data-v3-root] [data-act="radera"]');
  assert(!!act, 'radera-knappen finns');
  act.click();
  const ok = dom.document.getElementById('v3-m-ok');
  assert(!!ok && typeof ok.onclick === 'function', 'bekräftelsen har en OK-hanterare');
  ok.click();
}

function restoreCalls() {
  return calls.filter((call) => call.entries.some((pair) => pair[0] === 'action' && pair[1] === 'ordrar.restore'));
}
function remount(id) {
  calls.length = 0;
  nav.length = 0;
  const main = dom.document.querySelector('main');
  main.children.slice().forEach((child) => child.remove());
  dom.parse(orderInner(id), main);
  context.jeabOrdrarV3Mount();
}

(async () => {
  // 1) Raderar direkt, Ångra visas efteråt, utan Ångra går man till listan efter ca 6 s.
  confirmDelete();
  await drain();
  assert(deleteCalls().length === 1 && postedId(deleteCalls()[0]) === '10', 'bekräftad radering postas direkt, ingen sexsekundersväntan');
  const btn = undoButton();
  assert(!!btn && btn.isConnected, 'Ångra visas efter raderingen');
  assert(textOf(dom.document.querySelector('[data-v3-delete-undo]')).indexOf('Ärendet raderat.') !== -1, 'toasten säger att ärendet är raderat');
  assert(nav.length === 0, 'stannar på kortet medan Ångra visas');
  advance(6000);
  await drain();
  assert(deleteCalls().length === 1, 'raderingen skickas bara en gång');
  assert(restoreCalls().length === 0, 'ingen återställning utan Ångra');
  assert(nav.length === 1 && nav[0] === '/lista', 'utan Ångra går man till listan efter ca 6 s');

  // 2) Ångra återställer: ordrar.restore postas med orderns id och man går inte till listan.
  remount('11');
  confirmDelete();
  await drain();
  assert(deleteCalls().length === 1 && postedId(deleteCalls()[0]) === '11', 'nästa order raderas också direkt');
  const again = undoButton();
  assert(!!again, 'Ångra visas för nästa order');
  advance(2000);
  again.click();
  await drain();
  const restores = restoreCalls();
  assert(restores.length === 1 && postedId(restores[0]) === '11', 'Ångra postar ordrar.restore för den raderade ordern');
  advance(6000);
  await drain();
  assert(nav.indexOf('/lista') === -1, 'efter Ångra går man inte till listan');
  assert(deleteCalls().length === 1 && restoreCalls().length === 1, 'radera och ångra postas en gång var');

  console.log('ordrar v3 delete undo: ok');
})().catch((err) => {
  console.error(err && err.stack ? err.stack : err);
  process.exit(1);
});
