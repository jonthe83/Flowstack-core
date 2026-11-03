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
    Object.defineProperty(el, 'innerHTML', {
      get() { return el.textContent; },
      set(v) { el.textContent = String(v || '').replace(/<[^>]*>/g, ''); }
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
      if (k === 'disabled') el.disabled = true;
      if (k === 'checked') el.checked = true;
    };
    el.hasAttribute = (k) => Object.prototype.hasOwnProperty.call(el.attrs, k);
    el.removeAttribute = (k) => {
      delete el.attrs[k];
      if (k === 'disabled') el.disabled = false;
      if (k === 'checked') el.checked = false;
    };
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
    location: { href: 'https://flowstack.example.test/index.php?p=ordrar&co=jarfalla&id=40', hash: '', pathname: '/index.php', search: '?p=ordrar&co=jarfalla&id=40', assign() {} },
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

function orderHtml() {
  return '<section class="ov3" data-v3-root data-id="40" data-list="/lista" data-self="/index.php?p=ordrar&co=jarfalla&id=40">'
    + '<div id="v3-ohead"><h1>Order 40 Järfälla</h1></div>'
    + '<div class="v3-toast" data-v3-toast role="status"></div>'
    + '<div class="v3-err" data-v3-err="faktura" role="alert" hidden></div>'
    + '<form id="v3-f-faktura" method="post" action="/index.php?p=ordrar&co=jarfalla&id=40" data-v3-form="faktura">'
    + '<input type="hidden" name="_csrf" value="tok">'
    + '<input type="hidden" name="action" value="ordrar.fakturering">'
    + '<input type="hidden" name="id" value="40">'
    + '<input type="hidden" name="faktura_bekrafta" value="1" data-v3-faktura-flag disabled>'
    + '<input type="checkbox" name="faktura_bekrafta" value="1" data-v3-faktura-check>'
    + '<div data-v3-fxrow>'
    + '<input name="fx_desc[]" value="Batteri">'
    + '<input name="fx_qty[]" value="1">'
    + '<input name="fx_price[]" value="169583">'
    + '</div>'
    + '</form>'
    + '<button type="submit" form="v3-f-faktura" name="intent" value="save" data-v3-sec="faktura">Spara</button>'
    + '<button type="submit" form="v3-f-faktura" class="v3-btn is-primary" data-v3-primary="faktura" data-v3-hold="1" name="intent" value="faktura" data-working="Skapar faktura…">Skapa faktura</button>'
    + '</section>';
}

function FakeFormData(form) {
  this.pairs = [];
  if (form && form.querySelectorAll) {
    form.querySelectorAll('input').forEach((input) => {
      if (input.disabled) return;
      const type = String(input.getAttribute('type') || '').toLowerCase();
      if ((type === 'checkbox' || type === 'radio') && !input.checked) return;
      const name = input.getAttribute('name');
      if (!name) return;
      this.pairs.push([name, input.value || '']);
    });
  }
}
FakeFormData.prototype.set = function (key, value) {
  this.pairs = this.pairs.filter((pair) => pair[0] !== key);
  this.pairs.push([String(key), String(value)]);
};
FakeFormData.prototype.entries = function () {
  return this.pairs[Symbol.iterator]();
};

const dom = createDom();
const context = dom.window;
context.console = console;
context.global = context;
context.DOMParser = function () {
  this.parseFromString = () => { throw new Error('inget nytt skal i testet'); };
};
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
let gate = null;
context.fetch = (url, opts) => {
  const entries = [];
  if (opts && opts.body && opts.body.entries) {
    for (const pair of opts.body.entries()) entries.push(pair);
  }
  const call = { url: String(url), method: opts && opts.method ? opts.method : 'GET', entries };
  calls.push(call);
  if (opts && opts.method === 'POST') {
    return new Promise((resolve) => {
      const finish = () => resolve({
        ok: true,
        status: 200,
        text: () => Promise.resolve('{"ok":true,"flash":"Sparad."}')
      });
      if (gate) gate.push(finish);
      else finish();
    });
  }
  return Promise.resolve({
    ok: false,
    status: 500,
    text: () => Promise.resolve('')
  });
};

dom.parse('<main></main>', dom.document.body);
dom.parse(orderHtml(), dom.document.querySelector('main'));

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
  while (guard++ < 80) {
    const due = tasks.filter((task) => task.at <= now).sort((a, b) => a.at - b.at || a.id - b.id);
    if (!due.length) return;
    const task = due[0];
    const index = tasks.findIndex((row) => row.id === task.id);
    if (index >= 0) tasks.splice(index, 1);
    task.fn();
  }
}
async function drain() {
  for (let i = 0; i < 20; i++) await Promise.resolve();
}
function field(call, name) {
  const pair = call.entries.find((row) => row[0] === name);
  return pair ? pair[1] : '';
}
function posts() {
  return calls.filter((call) => call.method === 'POST');
}
function submit(button) {
  const form = dom.document.getElementById('v3-f-faktura');
  dom.fire(form, 'submit', { submitter: button });
}
function buttons() {
  return {
    spara: dom.document.querySelector('[data-v3-sec="faktura"]'),
    skapa: dom.document.querySelector('[data-v3-hold="1"]'),
    form: dom.document.getElementById('v3-f-faktura'),
    flag: dom.document.querySelector('[data-v3-faktura-flag]')
  };
}

(async () => {
  const ui = buttons();
  assert(!!ui.spara && !!ui.skapa && !!ui.form && !!ui.flag, 'order 40 har Spara, Skapa faktura och dold flagga');
  assert(ui.flag.disabled === true, 'flaggan är av före klicket');

  submit(ui.skapa);
  await drain();
  assert(ui.form.getAttribute('data-v3-held') === null, 'ingen nedräkning startas');
  assert(!dom.document.querySelector('[data-v3-undo]'), 'ingen Ångra-fördröjning före utskicket');
  assert(posts().length === 1, 'första klicket postar fakturan direkt');
  assert(field(posts()[0], 'intent') === 'faktura', 'posten är Skapa faktura');
  assert(field(posts()[0], 'faktura_bekrafta') === '1', 'posten har faktura_bekrafta');
  assert(field(posts()[0], 'id') === '40', 'posten gäller order 40');
  assert(ui.flag.disabled === false, 'flaggan slås på innan posten');

  function armAgain() {
    ui.flag.disabled = true;
    const box = dom.document.querySelector('[data-v3-faktura-check]');
    if (box) box.checked = false;
    ui.skapa.removeAttribute('data-busy');
    ui.skapa.disabled = false;
    ui.spara.removeAttribute('data-busy');
    ui.spara.disabled = false;
    ui.form.removeAttribute('data-v3-held');
    calls.length = 0;
  }

  armAgain();
  submit(ui.spara);
  await drain();
  assert(posts().length === 1 && field(posts()[0], 'intent') === 'save', 'Spara är utkast');
  assert(field(posts()[0], 'faktura_bekrafta') === '', 'Spara kräver inte flaggan');

  armAgain();
  gate = [];
  submit(ui.spara);
  await drain();
  submit(ui.skapa);
  await drain();
  assert(posts().length === 1 && field(posts()[0], 'intent') === 'save', 'Skapa faktura väntar bara ut ett pågående Spara');
  gate.splice(0).forEach((finish) => finish());
  await drain();
  advance(200);
  await drain();
  const invoices = posts().filter((call) => field(call, 'intent') === 'faktura');
  assert(invoices.length === 1 && field(invoices[0], 'faktura_bekrafta') === '1', 'fakturan går igenom så fort Spara är klart');

  console.log('ordrar_v3_faktura_hold_run ok');
})().catch((err) => {
  console.error(err && err.stack ? err.stack : err);
  process.exit(1);
});
