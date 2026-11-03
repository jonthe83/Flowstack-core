/* Skalnavigering: menyn ligger kvar, innehållet byts.
   Sparad HTML yngre än DRAW_MS (5 min) ritas direkt från minnet eller
   sessionStorage. Svaret hämtas alltid bakom och byts tyst när det kommer.
   Det är inte en skrivning. En POST eller annan skrivande fetch tömmer cachen.
   Inkorgen har egen pushState. Tillbaka där laddar inte om dokumentet.
   Formulär, POST, Fortnox och utskick lämnas till vanlig sidladdning. */
(function () {
  if (!window.history || typeof history.pushState !== 'function') {
    return;
  }
  var main0 = document.querySelector('main.main');
  if (!main0 || !document.querySelector('meta[name="csrf"]')) {
    return;
  }
  window.jeabShell = true;

  var HOVER_MS = 65;
  /* Rita sparad HTML i fem minuter. Hämtningen bakom görs ändå. */
  var DRAW_MS = 5 * 60 * 1000;
  /* Vila-förhämtning behöver inte göras om varje gång man byter flik. */
  var IDLE_MS = 60000;
  /* Kort stillhet efter senaste klicket. Scroll avbryter inte. Loopia: en i taget. */
  var QUIET_MS = 1500;
  /* Högst en förhämtning åt gången och högst fyra per fliksession (max_user_connections). */
  var PREFETCH_MAX = 4;
  /* Högst två hämtningar samtidigt. Förhämtning väntar tills inget annat pågår. */
  var FETCH_MAX = 2;
  var PREFETCH_KEY = 'jeab.prefetch.n';
  /* Inkorg och Påminnelser skriver sessionen på GET och ingår inte.
     Kalender och Ordrar först: de är tyngst på ett kallt klick. */
  var IDLE_PAGES = ['bookings', 'ordrar', 'offert', 'utskick'];
  var STORE = 'jeab.view.';
  var idleGen = 0;
  var idleHandle = 0;
  var idleTimer = 0;
  var lastActive = Date.now();
  var gen = 0;
  var mem = new Map();
  var etags = new Map();
  var warm = null;
  var paintArmed = false;
  var doneFor = -1;
  var viewEpoch = 0;
  var inflight = new Map();
  var listCtrls = [];
  var heldListHtml = '';
  var heldListKey = '';
  var listQuery = '';
  var listQueryGen = 0;
  var listQueryTouched = false;
  var lastHtml = '';
  window.jeabNav = {
    current: function () { return gen; },
    hasOrder: function () { return !!urlOrderId(location.href); }
  };
  window.jeabBackground = {
    ctrl: null,
    take: function () {
      if (this.ctrl) {
        return null;
      }
      this.ctrl = new AbortController();
      return this.ctrl;
    },
    release: function (ctrl) {
      if (ctrl && this.ctrl === ctrl) {
        this.ctrl = null;
      }
    },
    abort: function () {
      var ctrl = this.ctrl;
      this.ctrl = null;
      if (ctrl) {
        try { ctrl.abort(); } catch (e) {}
      }
    },
    busy: function () {
      return !!this.ctrl;
    }
  };
  var hoverTimer = 0;
  var hoverEl = null;
  var prevNav = '';

  if (/[?&]_t=1(?:&|$)/.test(location.search)) {
    window.__jeabT = 1;
  }

  function pageOf(url) {
    try {
      return new URL(url, location.href).searchParams.get('p') || 'reminders';
    } catch (e) {
      return '';
    }
  }

  function navKey(p) {
    if (p === 'reminder' || p === 'purchase') {
      return 'reminders';
    }
    if (p === 'kalkyl') {
      return 'offert';
    }
    if (['logg', 'bugg', 'zapier', 'notes', 'manuell-order', 'rot', 'kontakter', 'notiser', 'utskick', 'fullmakter'].indexOf(p) !== -1) {
      return 'links';
    }
    return p;
  }

  function isInbox(p) {
    return p === 'inkorg' || p.indexOf('inkorg-') === 0;
  }

  /* ws är time() på personlänkar och byts varje svar. Nyckeln utan ws
     så samma vy ritas från minnet när bara stämpeln hunnit ändras. */
  function keyOf(url) {
    var u = new URL(url, location.href);
    u.searchParams.delete('_t');
    u.searchParams.delete('ws');
    u.hash = '';
    return u.pathname + u.search;
  }

  /* Listlänken #steg-bekrafta ska ligga kvar i adressfältet. Cachen är utan hash. */
  function shellUrl(url) {
    var u = new URL(url, location.href);
    u.searchParams.delete('_t');
    var hash = /^#steg-[a-z]+$/.test(u.hash) ? u.hash : '';
    return u.pathname + u.search + hash;
  }

  function urlOrderId(url) {
    try {
      var id = new URL(url, location.href).searchParams.get('id') || '';
      return /^[1-9]\d*$/.test(id) ? id : '';
    } catch (e) {
      return '';
    }
  }

  function htmlIsOrder(html) {
    return typeof html === 'string' && (html.indexOf('data-v3-root') !== -1 || html.indexOf('ordrar is-open') !== -1);
  }

  function htmlIsList(html) {
    return typeof html === 'string' && html.indexOf('ordrar-board') !== -1 && !htmlIsOrder(html);
  }

  function isListUrl(url) {
    return pageOf(url) === 'ordrar' && !urlOrderId(url);
  }

  function isRemindersPage(url) {
    var p = pageOf(url);
    return p === 'reminders' || p === 'reminder';
  }

  /* Sidans egna ark, samma urval som jeab_page_style_hrefs. tokens/app/flash ligger redan i dokumentet. */
  function pageStyleHrefs(url) {
    var p = pageOf(url);
    var extra = [];
    if (p === 'purchase' || p === 'reminders' || p === 'reminder') {
      extra.push('/assets/css/kop.css');
    }
    if (p === 'reminders' || p === 'reminder' || p === 'kontakter') {
      extra.push('/assets/css/paminnelser.css');
    }
    if (p === 'bookings') {
      extra.push('/assets/css/bokning-sheet.css');
      extra.push('/assets/css/kalender.css');
    }
    if (p === 'ordrar') {
      extra.push('/assets/css/ordrar.css');
      extra.push('/assets/css/ordrar-v3.css');
    }
    if (p === 'notiser' || p === 'utskick') {
      extra.push('/assets/css/notiser.css');
    }
    if (p === 'notes') {
      extra.push('/assets/css/notes.css');
    }
    if (p === 'inkorg' || p.indexOf('inkorg-') === 0) {
      extra.push('/assets/css/inkorg.css');
    }
    return extra;
  }

  function listSnapKey(url) {
    try {
      var u = new URL(url, location.href);
      return u.pathname
        + '?p=' + (u.searchParams.get('p') || '')
        + '&co=' + (u.searchParams.get('co') || '')
        + '&who=' + (u.searchParams.get('who') || '')
        + '&job=' + (u.searchParams.get('job') || '');
    } catch (e) {
      return '';
    }
  }

  function htmlIsSkeleton(html) {
    if (typeof html !== 'string' || html.indexOf('jeab-skel') === -1) {
      return false;
    }
    /* En gråad lista har fortfarande ordrar-board men inga rader. */
    if (htmlIsOrder(html) || html.indexOf('ordrar-row') !== -1) {
      return false;
    }
    return true;
  }

  /* Minnet och sessionStorage får bara ritas om HTML:en hör till adressen.
     En order får inte bli en lista, och en lista inte en order. */
  function cacheUsable(hit, url) {
    if (!hit || !hit.data || hit.data.ok !== true || typeof hit.data.html !== 'string') {
      return false;
    }
    var html = hit.data.html;
    if (!html || htmlIsSkeleton(html)) {
      return false;
    }
    if (urlOrderId(url)) {
      return htmlIsOrder(html);
    }
    if (isListUrl(url)) {
      return htmlIsList(html);
    }
    return !htmlIsOrder(html);
  }

  function viewHtml(url) {
    var hit = mem.get(keyOf(url));
    if (cacheUsable(hit, url) && (Date.now() - hit.at) < DRAW_MS) {
      return hit.data.html;
    }
    if (isListUrl(url)) {
      return storedList(url) || '';
    }
    if (urlOrderId(url)) {
      var orderHtml = storedFor(url);
      return htmlIsOrder(orderHtml) ? orderHtml : '';
    }
    var stored = storedFor(url);
    if (!stored || htmlIsSkeleton(stored) || htmlIsOrder(stored)) {
      return '';
    }
    return stored;
  }

  /* Listan sparas både på exakt adress och utan sök/tidsstämpel,
     så Tillbaka kan rita den även när q eller ws hunnit ändras. */
  function rememberListHtml(url, html) {
    if (!isListUrl(url) || !htmlIsList(html) || htmlIsSkeleton(html)) {
      return;
    }
    heldListHtml = html;
    heldListKey = listSnapKey(url);
    writeStore(keyOf(url), html);
    if (heldListKey) {
      writeStore('list:' + heldListKey, html);
    }
  }

  function rememberVisibleList() {
    if (!isListUrl(location.href)) {
      return;
    }
    var main = document.querySelector('main.main');
    if (!main) {
      return;
    }
    rememberListHtml(location.href, main.innerHTML);
  }

  function storedList(url) {
    var exact = storedFor(url);
    if (exact && htmlIsList(exact) && !htmlIsSkeleton(exact)) {
      return exact;
    }
    var snapKey = listSnapKey(url);
    var snap = snapKey ? storedHtml('list:' + snapKey) : '';
    if (snap && htmlIsList(snap) && !htmlIsSkeleton(snap)) {
      return snap;
    }
    if (heldListHtml && htmlIsList(heldListHtml) && heldListKey === snapKey) {
      return heldListHtml;
    }
    return '';
  }

  function queryOf(url) {
    try {
      return new URL(url, location.href).searchParams.get('q') || '';
    } catch (e) {
      return '';
    }
  }

  /* Det användaren skrev vinner över en listsvar som startade innan sökningen. */
  function listQueryNow() {
    if (listQueryTouched) {
      return listQuery;
    }
    var input = document.querySelector('main.main [data-js="ordrar-search"]');
    if (input && String(input.value || '') !== '') {
      return String(input.value || '');
    }
    if (isListUrl(location.href)) {
      var fromUrl = queryOf(location.href);
      if (fromUrl) {
        return fromUrl;
      }
    }
    var state = history.state;
    if (state && typeof state.jeabQ === 'string' && state.jeabQ) {
      return state.jeabQ;
    }
    return '';
  }

  function noteListQuery(q) {
    listQuery = String(q || '');
    listQueryTouched = true;
    listQueryGen += 1;
    window.jeabListQuery = listQuery;
    window.jeabListQueryGen = listQueryGen;
  }

  function applyListQuery(root, q) {
    if (!root) {
      return;
    }
    q = String(q || '');
    var input = root.querySelector('[data-js="ordrar-search"]');
    if (input && input.value !== q) {
      input.value = q;
    }
    var needle = q.toLowerCase().trim();
    root.querySelectorAll('.ordrar-list > li').forEach(function (li) {
      if (!needle) {
        li.hidden = false;
        return;
      }
      var a = li.querySelector('a.ordrar-row');
      var name = ((a && (a.getAttribute('data-name') || '')) || '') + ' ' + ((a && a.textContent) || '');
      li.hidden = name.toLowerCase().indexOf(needle) === -1;
    });
    root.querySelectorAll('a.ordrar-row, .ordrar-staff-filter a.chip').forEach(function (link) {
      var href = link.getAttribute('href');
      if (!href) {
        return;
      }
      try {
        var u = new URL(href, location.href);
        if (q) {
          u.searchParams.set('q', q);
        } else {
          u.searchParams.delete('q');
        }
        link.setAttribute('href', u.pathname + u.search + u.hash);
      } catch (e) {}
    });
  }

  function commitListHistory(q) {
    if (!isListUrl(location.href)) {
      return;
    }
    q = String(q || '');
    var u;
    try {
      u = new URL(location.href);
    } catch (e) {
      return;
    }
    if (q) {
      u.searchParams.set('q', q);
    } else if (listQueryTouched) {
      u.searchParams.delete('q');
    }
    var next = u.pathname + u.search + u.hash;
    var prev = history.state && typeof history.state === 'object' ? history.state : {};
    var state = {};
    Object.keys(prev).forEach(function (k) {
      state[k] = prev[k];
    });
    state.jeabShell = 1;
    state.jeabQ = q;
    var here = location.pathname + location.search + location.hash;
    if (next !== here || prev.jeabQ !== q) {
      try {
        history.replaceState(state, '', next);
      } catch (e) {}
    }
    window.jeabListQuery = q;
  }

  function paintListHtml(main, html, q) {
    var box = document.createElement('div');
    box.innerHTML = html;
    applyListQuery(box, q);
    main.innerHTML = '';
    while (box.firstChild) {
      main.appendChild(box.firstChild);
    }
    versionEmbeddedAssets(main);
  }

  function versionEmbeddedAssets(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }
    [].slice.call(root.querySelectorAll('script[src]')).forEach(function (el) {
      var raw = el.getAttribute('src');
      if (!raw) {
        return;
      }
      var next = assetVersion(raw);
      if (next && next !== raw) {
        el.setAttribute('src', next);
      }
    });
  }

  function assetVersion(src) {
    try {
      var u = new URL(src, location.href);
      if (u.origin !== location.origin || !/\.(js|css)$/i.test(u.pathname)) {
        return src;
      }
      var el = document.getElementById('jeab-asset-v');
      if (el) {
        try {
          window.__jeabAssetV = JSON.parse(el.textContent || '{}');
        } catch (e) {
          if (!window.__jeabAssetV) {
            window.__jeabAssetV = {};
          }
        }
      } else if (!window.__jeabAssetV) {
        window.__jeabAssetV = {};
      }
      var path = u.pathname.replace(/^\/+/, '');
      var v = window.__jeabAssetV[path] || '';
      if (!v) {
        Object.keys(window.__jeabAssetV).forEach(function (k) {
          if (!v && path.slice(-k.length) === k) {
            v = window.__jeabAssetV[k];
          }
        });
      }
      // Innehållshashen vinner över en hårdkodad ?v=3 när skalet sätter in länken.
      if (v) {
        u.searchParams.set('v', String(v));
        return u.pathname + u.search + u.hash;
      }
      if (u.searchParams.get('v')) {
        return u.pathname + u.search + u.hash;
      }
      return src;
    } catch (e) {
      return src;
    }
  }

  function restoreListQuery(main) {
    var q = listQueryNow();
    if (!q) {
      q = queryOf(location.href);
    }
    applyListQuery(main, q);
  }

  function abortShellFetches() {
    inflight.forEach(function (entry) {
      if (entry && entry.ctrl) {
        try { entry.ctrl.abort(); } catch (e) {}
      }
    });
    inflight.clear();
    var ctrls = listCtrls.splice(0, listCtrls.length);
    ctrls.forEach(function (ctrl) {
      try { ctrl.abort(); } catch (e) {}
    });
    if (window.jeabBackground) {
      window.jeabBackground.abort();
    }
    if (warm && warm.ctrl && warm.ctrl.signal && warm.ctrl.signal.aborted) {
      warm = null;
    }
  }

  function abortListFetches() {
    var ctrls = listCtrls.splice(0, listCtrls.length);
    var drop = [];
    inflight.forEach(function (entry, key) {
      if (key.indexOf('p=ordrar') !== -1 && !/(?:^|[?&])id=[1-9]\d*(?:&|$)/.test(key)) {
        drop.push(key);
        if (entry && entry.ctrl) {
          ctrls.push(entry.ctrl);
        }
      }
    });
    drop.forEach(function (key) {
      inflight.delete(key);
    });
    ctrls.forEach(function (ctrl) {
      try { ctrl.abort(); } catch (e) {}
    });
  }

  function titleFor(p) {
    var map = {
      reminders: 'Påminnelser',
      bookings: 'Kalender',
      ordrar: 'Ordrar',
      offert: 'Kalkyl',
      kalkyl: 'Kalkyl',
      links: 'Övrigt',
      kontakter: 'Kontakter',
      notes: 'Anteckningar',
      notiser: 'Utskick',
      utskick: 'Utskick',
      logg: 'Fellogg',
      bugg: 'Rapportera bugg',
      purchase: 'Köp',
      zapier: 'Zapier',
      'manuell-order': 'Manuell order',
      fullmakter: 'E-signering'
    };
    return map[p] || 'JEAB';
  }

  function onInbox() {
    /* page-inkorg med egen lista (#ix). Vyer och Regler har inte den listan
       och får skalets partial i stället för en hel sidladdning. */
    return document.body.classList.contains('page-inkorg') && !!document.getElementById('ix');
  }

  function skipLink(a) {
    if (!a) {
      return true;
    }
    if (a.target && a.target !== '_self') {
      return true;
    }
    if (a.hasAttribute('download') || a.hasAttribute('data-jeab-full')) {
      return true;
    }
    if (a.matches('a.item-main, [data-js="close-row"], [data-js="reminder-more"], [data-kt-chip], [data-kt-more]')) {
      var kt = a.closest ? a.closest('[data-kt-server="1"]') : null;
      if (!(kt && a.matches('a.item-main, [data-kt-chip], [data-kt-more]'))) {
        return true;
      }
    }
    var href = a.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') {
      return true;
    }
    if (/^(mailto:|tel:|sms:|javascript:)/i.test(href)) {
      return true;
    }
    var u;
    try {
      u = new URL(a.href, location.href);
    } catch (e) {
      return true;
    }
    if (u.origin !== location.origin) {
      return true;
    }
    if (!/\/index\.php$/.test(u.pathname) && u.pathname !== '/' && !/\/$/.test(u.pathname)) {
      return true;
    }
    var p = u.searchParams.get('p') || 'reminders';
    /* Bara Inkorgens egen lista. Vyer och Regler är vanliga sidor. */
    if (p === 'inkorg' || p === 'csrf' || p === 'sack') {
      return true;
    }
    if (u.searchParams.get('intent') || u.searchParams.get('format') === 'json') {
      return true;
    }
    if (u.searchParams.get('photo') || u.searchParams.get('fil') || u.searchParams.get('vis') || u.searchParams.get('file') || u.searchParams.get('fortnox_kontroll')) {
      return true;
    }
    return false;
  }

  function eventElement(event) {
    var t = event && event.target;
    if (t && t.nodeType === 3) {
      t = t.parentNode;
    }
    return (t && t.closest) ? t : null;
  }

  function formControlTarget(event) {
    var t = eventElement(event);
    if (!t) {
      return false;
    }
    /* Orderraden är en länk. Texten i den får inte räknas som etikett eller knapp. */
    if (t.closest('a.ordrar-row')) {
      return false;
    }
    return !!t.closest('button, input, select, textarea, label, option');
  }

  /* Tryck som börjar på en orderrad. Klicket kan landa på ett personchip
     när tangentbordet stängs eller listan flyttar sig. Då ska ordern öppnas. */
  var rowPress = null;

  function rememberRow(event) {
    if (!event || (event.button != null && event.button > 0)) {
      return;
    }
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    var el = eventElement(event);
    var row = el && el.closest('a.ordrar-row[href]');
    if (!row || !arm({ button: 0 }, row)) {
      if (event.type === 'pointerdown' || event.type === 'touchstart') {
        rowPress = null;
      }
      return;
    }
    rowPress = {
      href: row.href,
      at: Date.now()
    };
    if (urlOrderId(row.href)) {
      prefetch(row.href, { press: true });
    }
  }

  function takePressedRow(event) {
    var press = rowPress;
    rowPress = null;
    if (!press || !press.href) {
      return '';
    }
    if (Date.now() - press.at > 1000) {
      return '';
    }
    if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || (event.button != null && event.button > 0))) {
      return '';
    }
    /* Avståndet ignoreras. När tangentbordet stängs kan klicket landa
       hundratals pixlar bort, på personchippet eller Ordrar-fliken. */
    var el = eventElement(event);
    if (!el || el.closest('a.ordrar-row, a.chip, .ordrar-filter, nav.tabs a')) {
      return press.href;
    }
    return '';
  }

  function arm(event, a) {
    if (formControlTarget(event)) {
      return false;
    }
    if (!a || (event && event.defaultPrevented)) {
      return false;
    }
    if (event && event.button != null && event.button !== 0) {
      return false;
    }
    if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) {
      return false;
    }
    if (onInbox() && !(a.closest && a.closest('header.chrome'))) {
      return false;
    }
    if (skipLink(a)) {
      return false;
    }
    if (keyOf(a.href) === keyOf(location.href)) {
      return false;
    }
    return true;
  }

  function readStore(key) {
    try {
      return sessionStorage.getItem(STORE + key);
    } catch (e) {
      return null;
    }
  }

  /* Sparad vy: {at, html}. Äldre än DRAW_MS ritas inte. Ren HTML från
     ett äldre skal ritas fortfarande, och byts när svaret kommer. */
  function storedHtml(key) {
    var raw = readStore(key);
    if (!raw) {
      return '';
    }
    if (raw.charAt(0) === '{') {
      try {
        var parsed = JSON.parse(raw);
        if (!parsed || typeof parsed.html !== 'string' || typeof parsed.at !== 'number') {
          return '';
        }
        if (Date.now() - parsed.at > DRAW_MS) {
          return '';
        }
        if (parsed.etag) {
          etags.set(key, String(parsed.etag));
        }
        return parsed.html;
      } catch (e) {
        return '';
      }
    }
    return raw;
  }

  /* keyOf tar bort ws. En sparad vy från innan det, med ws kvar i nyckeln, ritas ändå. */
  function rawStoreKey(url) {
    try {
      var u = new URL(url, location.href);
      u.searchParams.delete('_t');
      u.hash = '';
      return u.pathname + u.search;
    } catch (e) {
      return '';
    }
  }

  function storedFor(url) {
    var html = storedHtml(keyOf(url));
    if (html) {
      return html;
    }
    var raw = rawStoreKey(url);
    if (raw && raw !== keyOf(url)) {
      return storedHtml(raw);
    }
    return '';
  }

  function writeStore(key, html, etag) {
    if (!html || html.length > 480000) {
      return;
    }
    try {
      var row = { at: Date.now(), html: html };
      if (etag) {
        row.etag = String(etag);
      }
      sessionStorage.setItem(STORE + key, JSON.stringify(row));
    } catch (e) {
      /* kvoten är full */
    }
  }

  function clearStore() {
    try {
      var drop = [];
      var i;
      for (i = 0; i < sessionStorage.length; i += 1) {
        var k = sessionStorage.key(i) || '';
        if (k.indexOf(STORE) === 0) {
          drop.push(k);
        }
      }
      drop.forEach(function (k) {
        sessionStorage.removeItem(k);
      });
    } catch (e) {
      /* privat läge */
    }
  }

  /* En skrivning gör sparad HTML gammal. Minnet och sessionStorage
     får inte rita vyn från före sparningen. */
  function invalidateViews() {
    viewEpoch += 1;
    mem.clear();
    etags.clear();
    warm = null;
    clearStore();
    heldListHtml = '';
    heldListKey = '';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
  }

  function staffNames() {
    var out = [];
    document.querySelectorAll('.chip-who-name').forEach(function (el) {
      var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (t && out.indexOf(t) === -1) {
        out.push(t);
      }
    });
    if (!out.length) {
      document.querySelectorAll('.cal-filter-people > a.chip, .crm-who a.chip').forEach(function (el) {
        var t = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (t && t !== 'Alla' && out.indexOf(t) === -1) {
          out.push(t);
        }
      });
    }
    return out;
  }

  function markTab(url) {
    var key = navKey(pageOf(url));
    document.querySelectorAll('nav.tabs a.tab').forEach(function (a) {
      var on = navKey(pageOf(a.href)) === key;
      a.classList.toggle('is-on', on);
      if (on) {
        a.setAttribute('aria-current', 'page');
      } else {
        a.removeAttribute('aria-current');
      }
    });
  }

  function togglePill(link) {
    var parent = link.parentElement;
    if (!parent) {
      return;
    }
    var peers = parent.querySelectorAll(':scope > a.chip, :scope > a.tag-chip, :scope > a.smart-tile');
    if (!peers.length) {
      return;
    }
    peers.forEach(function (el) {
      var on = el === link;
      el.classList.toggle('is-on', on);
      if (on) {
        el.setAttribute('aria-current', 'true');
      } else {
        el.removeAttribute('aria-current');
      }
    });
  }

  function bars() {
    return '<div class="jeab-skel" aria-hidden="true"><span></span><span></span><span></span><span></span></div>';
  }

  function grayList(main) {
    var list = main.querySelector('.apple-list, .ordrar-board, .week-grid, .month-cal, ul.list');
    if (list) {
      list.innerHTML = bars();
      return true;
    }
    return false;
  }

  function skeleton(url, link) {
    var picked = link ? (link.textContent || '').replace(/\s+/g, ' ').trim() : '';
    var pills = '<a class="chip' + (picked === 'Alla' ? ' is-on' : '') + '">Alla</a>';
    staffNames().forEach(function (name) {
      pills += '<a class="chip chip-who' + (picked === name ? ' is-on' : '') + '"><span class="chip-who-name">' + escapeHtml(name) + '</span></a>';
    });
    return '<div class="apple-app crm-app"><header class="crm-top"><div class="crm-top-text"><h1 class="crm-title">'
      + escapeHtml(titleFor(pageOf(url)))
      + '</h1></div></header><div class="cal-filter crm-who"><div class="cal-filter-main crm-scroll">'
      + pills
      + '</div></div>'
      + bars()
      + '</div>';
  }

  function logT() {
    if (!window.__jeabT) {
      return;
    }
    var c = performance.getEntriesByName('jeab:click').pop();
    var p = performance.getEntriesByName('jeab:paint').pop();
    var d = performance.getEntriesByName('jeab:done').pop();
    if (!c) {
      return;
    }
    console.log('[jeab]', {
      click: 0,
      paint: p ? Math.round(p.startTime - c.startTime) : null,
      done: d ? Math.round(d.startTime - c.startTime) : null
    });
  }

  function markClick() {
    try {
      performance.clearMarks('jeab:click');
      performance.clearMarks('jeab:paint');
      performance.clearMarks('jeab:done');
      performance.mark('jeab:click');
    } catch (e) {
      /* äldre webbläsare */
    }
  }

  function markPaint() {
    try {
      performance.mark('jeab:paint');
    } catch (e) {
      /* ignore */
    }
    logT();
  }

  function markDone() {
    if (doneFor === gen) {
      return;
    }
    doneFor = gen;
    try {
      performance.mark('jeab:done');
      if (performance.getEntriesByName('jeab:paint').length) {
        performance.measure('jeab:click-to-paint', 'jeab:click', 'jeab:paint');
      }
      performance.measure('jeab:click-to-done', 'jeab:click', 'jeab:done');
      logT();
      reportClickDone();
    } catch (e) {
      /* jsdom och äldre webbläsare saknar delar av performance */
    }
  }

  function reportClickDone() {
    var c = performance.getEntriesByName('jeab:click').pop();
    var d = performance.getEntriesByName('jeab:done').pop();
    if (!c || !d) {
      return;
    }
    var ms = Math.round(d.startTime - c.startTime);
    if (!(ms >= 1500) || ms > 120000) {
      return;
    }
    var meta = document.querySelector('meta[name="csrf"]');
    var token = meta ? (meta.getAttribute('content') || '') : '';
    if (!token) {
      return;
    }
    var body = new URLSearchParams();
    body.set('_csrf', token);
    body.set('action', 'timing.click');
    body.set('ms', String(ms));
    body.set('page', pageOf(location.href));
    fetch(location.pathname || 'index.php', {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json'
      },
      body: body.toString()
    }).catch(function () {});
  }

  function paintNow(url, link) {
    var main = document.querySelector('main.main');
    if (!main) {
      return;
    }
    /* Spara listan innan ordern (eller en annan vy) ersätter den. */
    rememberVisibleList();
    if (urlOrderId(location.href) && !urlOrderId(url)) {
      return;
    }
    var keepQ = '';
    if (isListUrl(url)) {
      keepQ = listQueryNow() || queryOf(url);
    }
    markClick();
    markTab(url);
    ensureStyles(pageStyleHrefs(url));
    if (link && link.closest && link.closest('.chrome-company')) {
      togglePill(link);
    }
    /* Minnet och sessionStorage ritas om de är yngre än DRAW_MS. Hämtningen kommer efter. */
    var cached = viewHtml(url);
    var same = navKey(pageOf(url)) === navKey(pageOf(location.href));
    var showingList = !!(main.querySelector('.ordrar-board') && !main.querySelector('[data-v3-root]'));
    if (cached) {
      if (isListUrl(url)) {
        paintListHtml(main, cached, keepQ);
        rememberListHtml(url, main.innerHTML);
      } else {
        main.innerHTML = cached;
        versionEmbeddedAssets(main);
      }
    } else if (isListUrl(url) && showingList) {
      if (link && link.matches && link.matches('a.chip, a.tag-chip, a.smart-tile')) {
        togglePill(link);
      }
      applyListQuery(main, keepQ);
      rememberListHtml(url, main.innerHTML);
    } else if (isRemindersPage(url)) {
      /* Bara listan byts mot staplar. Rubriken och flikarna står kvar. */
      if (link && link.matches && link.matches('a.chip, a.tag-chip, a.smart-tile')) {
        togglePill(link);
      }
      if (!grayList(main)) {
        main.innerHTML = skeleton(url, link);
      }
    } else if (!urlOrderId(url) && same && link && link.closest('main') && grayList(main)) {
      if (link.matches('a.chip, a.tag-chip, a.smart-tile')) {
        togglePill(link);
      }
    } else {
      main.innerHTML = skeleton(url, link);
    }
    main.setAttribute('aria-busy', 'true');
    markPaint();
    paintArmed = true;
  }

  function ensureStyles(list) {
    (list || []).forEach(function (href) {
      href = assetVersion(href);
      var abs;
      try {
        abs = new URL(href, location.href).href;
      } catch (e) {
        return;
      }
      var found = false;
      document.querySelectorAll('link[rel="stylesheet"]').forEach(function (el) {
        if (el.href === abs) {
          found = true;
        }
      });
      if (found) {
        return;
      }
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      document.head.appendChild(link);
    });
  }

  function runScripts(root) {
    [].slice.call(root.querySelectorAll('script')).forEach(function (old) {
      var type = (old.getAttribute('type') || '').toLowerCase();
      if (type.indexOf('json') !== -1) {
        return;
      }
      var src = old.getAttribute('src');
      if (src) {
        var path = src;
        try {
          path = new URL(src, location.href).pathname;
        } catch (e) {
          path = src;
        }
        window.__jeabSrc = window.__jeabSrc || {};
        if (window.__jeabSrc[path]) {
          if (path.indexOf('ordrar-v3') !== -1 && root.querySelector('[data-v3-root]') && typeof window.jeabOrdrarV3Mount === 'function') {
            window.jeabOrdrarV3Mount();
          } else if (path.indexOf('ordrar-v3') !== -1 && root.querySelector('[data-v3-root]')) {
            window.__jeabShellFallback = true;
          }
          old.remove();
          return;
        }
        window.__jeabSrc[path] = 1;
        var ext = document.createElement('script');
        ext.src = assetVersion(src);
        ext.async = false;
        old.replaceWith(ext);
        return;
      }
      var inline = document.createElement('script');
      inline.text = old.textContent || '';
      old.replaceWith(inline);
    });
  }

  function swapHtml(main, html, url) {
    if (urlOrderId(location.href) && htmlIsList(html)) {
      return false;
    }
    var sheet = document.getElementById('booking-sheet');
    var keep = pageOf(url) === 'bookings' && sheet && main.contains(sheet) ? sheet : null;
    if (keep) {
      keep.remove();
    }
    main.innerHTML = html;
    if (!keep) {
      return true;
    }
    var fresh = main.querySelector('#booking-sheet');
    if (fresh) {
      var freshForm = fresh.querySelector('#booking-form');
      var oldForm = keep.querySelector('#booking-form');
      if (freshForm && oldForm) {
        var action = freshForm.getAttribute('action');
        if (action) {
          oldForm.setAttribute('action', action);
        }
      }
      var company = fresh.querySelector('#booking-company');
      var companyField = keep.querySelector('#booking-company');
      if (company && companyField) {
        companyField.value = company.value;
      }
      fresh.remove();
    }
    main.appendChild(keep);
    return true;
  }

  function pageNeedsAppJs(p) {
    var el = document.getElementById('jeab-app-js-pages');
    if (!el) {
      return true;
    }
    var list;
    try {
      list = JSON.parse(el.textContent || '[]');
    } catch (e) {
      return true;
    }
    if (!list || !list.length) {
      return true;
    }
    return list.indexOf(p) !== -1;
  }

  var appJsPromise = null;

  function loadScriptOnce(src) {
    var path = src;
    try {
      path = new URL(src, location.href).pathname;
    } catch (e) {
      path = src;
    }
    window.__jeabSrc = window.__jeabSrc || {};
    if (window.__jeabSrc[path]) {
      return Promise.resolve();
    }
    return new Promise(function (resolve, reject) {
      var ext = document.createElement('script');
      ext.src = assetVersion(src);
      ext.async = false;
      ext.onload = function () {
        window.__jeabSrc[path] = 1;
        resolve();
      };
      ext.onerror = function () {
        reject(new Error('script'));
      };
      document.body.appendChild(ext);
    });
  }

  /* optimistic.js före app.js: snooze-hjälparna läses när app.js startar. */
  function ensurePageScripts(page) {
    if (!pageNeedsAppJs(page) || typeof window.jeabBindReminders === 'function') {
      return Promise.resolve();
    }
    if (appJsPromise) {
      return appJsPromise;
    }
    var pending = loadScriptOnce('/assets/js/optimistic.js').then(function () {
      return loadScriptOnce('/assets/js/app.js');
    }).then(function () {
      if (typeof window.jeabBindReminders !== 'function') {
        throw new Error('app.js');
      }
    });
    appJsPromise = pending.catch(function (err) {
      appJsPromise = null;
      throw err;
    });
    return appJsPromise;
  }

  function arrive(main) {
    window.__jeabShellFallback = false;
    runScripts(main);
    if (typeof window.jeabBindReminders === 'function') {
      window.jeabBindReminders(main);
    }
    if (typeof window.jeabBootCalendar === 'function') {
      window.jeabBootCalendar();
    }
    if (typeof window.jeabBootOffert === 'function') {
      window.jeabBootOffert();
    }
    if (typeof window.jeabBootOffertEv === 'function') {
      window.jeabBootOffertEv();
    }
    if (typeof window.jeabBootOffertSave === 'function') {
      window.jeabBootOffertSave();
    }
    if (typeof window.jeabBootCalAccounts === 'function') {
      window.jeabBootCalAccounts();
    }
    if (typeof window.jeabBootRot === 'function') {
      window.jeabBootRot();
    }
    if (typeof window.jeabBootMall === 'function') {
      window.jeabBootMall();
    }
  }

  function apply(data, url, epoch) {
    var main = document.querySelector('main.main');
    if (!main || !data || data.ok !== true || typeof data.html !== 'string') {
      throw new Error('partial');
    }
    if (data.csrf) {
      var meta = document.querySelector('meta[name="csrf"]');
      if (meta) {
        meta.setAttribute('content', data.csrf);
      }
      document.querySelectorAll('input[name="_csrf"]').forEach(function (input) {
        input.value = data.csrf;
      });
    }
    ensureStyles(data.styles);
    if (data.title) {
      document.title = data.title;
    }
    if (typeof data.bodyClass === 'string') {
      document.body.className = data.bodyClass;
    }
    if (data.chrome) {
      var chrome = document.querySelector('header.chrome');
      if (chrome) {
        var wrap = document.createElement('div');
        wrap.innerHTML = data.chrome;
        var next = wrap.querySelector('header.chrome');
        if (next) {
          chrome.replaceWith(next);
        }
      }
      if (typeof window.jeabApplyTheme === 'function') {
        window.jeabApplyTheme();
      }
    }
    var key = keyOf(url);
    /* En listvy får aldrig ritas när adressen redan är en order.
       Gäller även om svaret startade före klicket och generationen
       av någon anledning fortfarande matchar. */
    if (urlOrderId(location.href) && (!urlOrderId(url) || htmlIsList(data.html))) {
      if (htmlIsList(data.html)) {
        mem.delete(key);
      }
      return;
    }
    if (urlOrderId(url) && htmlIsList(data.html)) {
      mem.delete(key);
      return;
    }
    var same = main.getAttribute('data-jeab-url') === key && lastHtml === data.html;
    var listQ = '';
    var swapped = false;
    if (!same && isListUrl(url) && htmlIsList(data.html) && !urlOrderId(location.href)) {
      /* Filtrera i en lös DOM innan listan ritas, så raderna inte byter plats under pekaren. */
      listQ = listQueryNow() || queryOf(location.href) || queryOf(url);
      paintListHtml(main, data.html, listQ);
      window.jeabListQuery = listQ;
      commitListHistory(listQ);
      lastHtml = main.innerHTML;
      main.setAttribute('data-jeab-url', keyOf(location.href));
      swapped = true;
    } else if (!same) {
      if (swapHtml(main, data.html, url) === false) {
        return;
      }
      lastHtml = data.html;
      main.setAttribute('data-jeab-url', key);
      swapped = true;
    }
    if (!swapped) {
      finishApply(main, data, url, key, epoch);
      return;
    }
    var started = window.jeabNavGen || 0;
    ensurePageScripts(pageOf(url)).then(function () {
      if ((window.jeabNavGen || 0) !== started) {
        return;
      }
      arrive(main);
      if (window.__jeabShellFallback) {
        location.href = url;
        return;
      }
      finishApply(main, data, url, key, epoch);
    }).catch(function () {
      if ((window.jeabNavGen || 0) !== started) {
        return;
      }
      location.href = url;
    });
  }

  function finishApply(main, data, url, key, epoch) {
    var nextNav = navKey(pageOf(url));
    if (prevNav && nextNav !== prevNav) {
      window.scrollTo(0, 0);
    }
    prevNav = nextNav;
    /* En skrivning under hämtningen gör svaret gammalt. Det ritas, men
       lagras inte så nästa klick inom 2,5 s inte tar recent-vägen. */
    var cacheOk = epoch === undefined || epoch === viewEpoch;
    if (cacheOk && isListUrl(location.href) && htmlIsList(main.innerHTML)) {
      rememberVisibleList();
    } else if (cacheOk && !htmlIsSkeleton(data.html)) {
      writeStore(key, data.html);
    }
    main.removeAttribute('aria-busy');
    markUpdating(false);
    requestAnimationFrame(markDone);
    scheduleIdlePrefetch();
  }

  function fresh(url) {
    var key = keyOf(url);
    var epoch = viewEpoch;
    var existing = inflight.get(key);
    if (existing && existing.gen === gen) {
      return existing.promise;
    }
    var started = gen;
    while (inflight.size >= FETCH_MAX) {
      var oldest = inflight.keys().next().value;
      var old = inflight.get(oldest);
      inflight.delete(oldest);
      if (old && old.ctrl) {
        try { old.ctrl.abort(); } catch (e) {}
      }
    }
    var ctrl = new AbortController();
    if (isListUrl(url)) {
      listCtrls.push(ctrl);
    }
    var pending = fetch(url, {
      credentials: 'same-origin',
      signal: ctrl.signal,
      headers: partialHeaders(url, false)
    }).then(function (res) {
      return readPartial(res, key);
    }).then(function (data) {
      if (started !== gen || (urlOrderId(location.href) && data && htmlIsList(data.html))) {
        var stale = new Error('stale');
        stale.name = 'StaleNav';
        throw stale;
      }
      if (data && data.busy && !data.html) {
        throw new Error('busy');
      }
      if (!data || data.ok !== true) {
        throw new Error('partial');
      }
      if (epoch === viewEpoch && !(urlOrderId(url) && htmlIsList(data.html))) {
        mem.set(key, { at: Date.now(), data: data });
      }
      return data;
    });
    inflight.set(key, { gen: started, promise: pending, ctrl: ctrl });
    var drop = function () {
      var cur = inflight.get(key);
      if (cur && cur.promise === pending) {
        inflight.delete(key);
      }
      var at = listCtrls.indexOf(ctrl);
      if (at !== -1) {
        listCtrls.splice(at, 1);
      }
    };
    pending.then(drop, function (err) {
      drop();
      /* Avbruten hämtning får inte kasta bort en vy som redan ritats. */
      if (err && (err.name === 'AbortError' || err.name === 'StaleNav')) {
        return;
      }
      mem.delete(key);
      etags.delete(key);
    });
    return pending;
  }

  function preloadScript(src) {
    var href = assetVersion(src);
    var abs;
    try {
      abs = new URL(href, location.href).href;
    } catch (e) {
      return;
    }
    var found = false;
    document.querySelectorAll('link[rel="preload"][as="script"]').forEach(function (el) {
      if (el.href === abs) {
        found = true;
      }
    });
    if (found) {
      return;
    }
    var link = document.createElement('link');
    link.rel = 'preload';
    link.as = 'script';
    link.href = href;
    document.head.appendChild(link);
  }

  /* GET som skriver sessionen. Inkorg byter kontor via jeab_inkorg_box_pin.
     Påminnelser utan arbetsläge i adressen glömmer personvalet, men
     förhämtning stänger sessionen först: HTML blir förvalet medan
     $_SESSION['jeab_reminder_who'] ligger kvar. */
  function prefetchWritesSession(url) {
    var p = pageOf(url);
    if (p === 'inkorg' || p.indexOf('inkorg-') === 0) {
      return true;
    }
    return p === 'reminders' || p === 'reminder';
  }

  /* Hovring hoppar över tunga sidor. Vila-förhämtning tar dem, en i taget. */
  function prefetchSkips(url) {
    if (prefetchWritesSession(url)) {
      return true;
    }
    var p = pageOf(url);
    return p === 'bookings' || p === 'ordrar' || p === 'notiser' || p === 'kontakter';
  }

  function prefetchCount() {
    try {
      var n = parseInt(sessionStorage.getItem(PREFETCH_KEY) || '0', 10);
      return n > 0 ? n : 0;
    } catch (e) {
      return PREFETCH_MAX;
    }
  }

  function prefetchBudget() {
    return prefetchCount() < PREFETCH_MAX;
  }

  function prefetchSpend() {
    try {
      sessionStorage.setItem(PREFETCH_KEY, String(prefetchCount() + 1));
    } catch (e) {
      /* privat läge: räkna inte fler */
    }
  }

  function saveDataOn() {
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    return !!(conn && conn.saveData);
  }

  function prefetchAllowed() {
    if (document.documentElement.getAttribute('data-jeab-cold') === '1') {
      return false;
    }
    if (document.visibilityState === 'hidden' || document.readyState !== 'complete') {
      return false;
    }
    if (saveDataOn()) {
      return false;
    }
    return (Date.now() - lastActive) >= QUIET_MS;
  }

  function partialHeaders(url, prefetching) {
    var headers = {
      'X-Jeab-Partial': '1',
      Accept: 'text/html, application/json'
    };
    if (prefetching) {
      headers['Sec-Purpose'] = 'prefetch';
    }
    var et = etags.get(keyOf(url));
    if (et) {
      headers['If-None-Match'] = et;
    }
    return headers;
  }

  function readPartial(res, key) {
    if (res.status === 304) {
      var hit = mem.get(key);
      if (hit && hit.data && hit.data.ok === true) {
        return Promise.resolve(hit.data);
      }
      var html = storedHtml(key);
      if (html) {
        var saved = { ok: true, html: html };
        mem.set(key, { at: Date.now(), data: saved });
        return Promise.resolve(saved);
      }
      throw new Error('partial');
    }
    var ct = res.headers.get('content-type') || '';
    if (!res.ok || ct.indexOf('application/json') === -1) {
      throw new Error('partial');
    }
    var et = res.headers.get('ETag');
    if (et) {
      etags.set(key, et);
    }
    return res.json();
  }

  function takeWarm(key) {
    if (!warm || warm.key !== key) {
      return null;
    }
    var found = warm;
    warm = null;
    if (window.jeabBackground && window.jeabBackground.ctrl === found.ctrl) {
      window.jeabBackground.release(found.ctrl);
    }
    return found.promise;
  }

  function prefetch(url, opts) {
    opts = opts || {};
    if (document.visibilityState === 'hidden' && !opts.press) {
      return null;
    }
    if (inflight.size > 0 || (window.jeabBackground && window.jeabBackground.busy())) {
      return null;
    }
    if (prefetchWritesSession(url)) {
      return null;
    }
    if (!opts.press && !prefetchAllowed()) {
      return null;
    }
    if (!opts.idle && !opts.press && prefetchSkips(url)) {
      return null;
    }
    if (!prefetchBudget()) {
      return 'stop';
    }
    ensureStyles(pageStyleHrefs(url));
    /* Hämta filen men kör den inte förrän mål-DOM:en finns. En tidig körning
       missar sidans egna bindningar (mjuk påminnelsepoll, kalenderark). */
    if (pageNeedsAppJs(pageOf(url)) && typeof window.jeabBindReminders !== 'function') {
      preloadScript('/assets/js/optimistic.js');
      preloadScript('/assets/js/app.js');
    }
    var key = keyOf(url);
    var hit = mem.get(key);
    if ((hit && Date.now() - hit.at < IDLE_MS) || inflight.has(key) || window.jeabBackground.busy()) {
      return null;
    }
    var ctrl = window.jeabBackground.take();
    if (!ctrl) {
      return null;
    }
    prefetchSpend();
    var epoch = viewEpoch;
    var aborted = false;
    ctrl.signal.addEventListener('abort', function () { aborted = true; });
    var promise = fetch(url, {
      credentials: 'same-origin',
      signal: ctrl.signal,
      headers: partialHeaders(url, true)
    }).then(function (res) {
      return readPartial(res, key);
    }).then(function (data) {
      if (epoch !== viewEpoch) {
        return null;
      }
      if (data && data.styles) {
        ensureStyles(data.styles);
      }
      if (data && data.ok === true && !(urlOrderId(url) && htmlIsList(data.html))) {
        mem.set(key, { at: Date.now(), data: data });
        writeStore(key, data.html, etags.get(key));
      }
      return data || null;
    }).catch(function () {
      return null;
    }).then(function (data) {
      window.jeabBackground.release(ctrl);
      if (warm && warm.ctrl === ctrl) {
        warm = null;
      }
      /* Ett avbrutet försök ska inte förbruka en av de fyra platserna. */
      if (aborted) {
        try {
          var n = prefetchCount();
          if (n > 0) {
            sessionStorage.setItem(PREFETCH_KEY, String(n - 1));
          }
        } catch (e) {}
      }
      return data;
    });
    warm = { key: key, ctrl: ctrl, promise: promise, press: !!opts.press };
    return promise;
  }

  function tabHref(page) {
    var href = '';
    document.querySelectorAll('nav.tabs a.tab').forEach(function (a) {
      if (href || !a.href || a.hasAttribute('data-jeab-full')) {
        return;
      }
      if (pageOf(a.href) === page && !urlOrderId(a.href)) {
        href = a.href;
      }
    });
    if (!href && page === 'utskick') {
      try {
        var u = new URL(location.href);
        u.searchParams.set('p', 'utskick');
        u.searchParams.delete('id');
        u.searchParams.delete('flik');
        u.hash = '';
        href = u.pathname + u.search;
      } catch (e) {
        href = '';
      }
    }
    return href;
  }

  function cancelIdlePrefetch() {
    idleGen += 1;
    if (idleTimer) {
      window.clearTimeout(idleTimer);
      idleTimer = 0;
    }
    if (idleHandle && typeof cancelIdleCallback === 'function') {
      try { cancelIdleCallback(idleHandle); } catch (e) {}
    }
    idleHandle = 0;
  }

  /* Först när sidan varit stilla i QUIET_MS. En i taget. Klick och
     navigering avbryter. Dolt fönster, spara-data och kallstart hoppas över. */
  function scheduleIdlePrefetch() {
    if (document.documentElement.getAttribute('data-jeab-cold') === '1') {
      return;
    }
    cancelIdlePrefetch();
    var gen = idleGen;
    var wait = Math.max(0, QUIET_MS - (Date.now() - lastActive));
    idleTimer = window.setTimeout(function () {
      idleTimer = 0;
      if (gen !== idleGen || !prefetchAllowed()) {
        return;
      }
      var run = function () {
        if (gen !== idleGen || !prefetchAllowed()) {
          return;
        }
        idlePrefetchNext(0, gen);
      };
      if (typeof requestIdleCallback === 'function') {
        idleHandle = requestIdleCallback(run);
      } else {
        run();
      }
    }, wait);
  }

  /* Scroll och pekning i sidan får en pågående förhämtning bli klar.
     Bara ett annat skalklick avbryter den, så de fyra platserna inte bränns. */
  function notePrefetchActivity(event) {
    if (!event || event.type === 'wheel') {
      return;
    }
    var el = eventElement(event);
    var link = el && el.closest ? el.closest('a[href]') : null;
    if (event.type === 'pointerdown' || event.type === 'touchstart') {
      if (!(link && arm({ button: 0 }, link))) {
        return;
      }
      lastActive = Date.now();
      if (warm && keyOf(link.href) !== warm.key && window.jeabBackground) {
        window.jeabBackground.abort();
        warm = null;
      }
      scheduleIdlePrefetch();
      return;
    }
    if (event.type === 'keydown') {
      lastActive = Date.now();
      if (!warm) {
        scheduleIdlePrefetch();
      }
    }
  }

  function idlePrefetchNext(index, gen) {
    if (gen !== idleGen || index >= IDLE_PAGES.length || document.visibilityState === 'hidden') {
      return;
    }
    var page = IDLE_PAGES[index];
    if (navKey(page) === navKey(pageOf(location.href))) {
      idlePrefetchNext(index + 1, gen);
      return;
    }
    var href = tabHref(page);
    if (!href) {
      idlePrefetchNext(index + 1, gen);
      return;
    }
    var hit = mem.get(keyOf(href));
    if (hit && Date.now() - hit.at < IDLE_MS) {
      idlePrefetchNext(index + 1, gen);
      return;
    }
    var pending = prefetch(href, { idle: true });
    if (pending === 'stop') {
      return;
    }
    if (!pending || !pending.then) {
      idlePrefetchNext(index + 1, gen);
      return;
    }
    pending.then(function () {
      if (gen !== idleGen || typeof requestIdleCallback !== 'function') {
        return;
      }
      requestIdleCallback(function () {
        idlePrefetchNext(index + 1, gen);
      });
    });
  }

  function ensureSwr() {
    if (!document.getElementById('jeab-swr-style')) {
      var style = document.createElement('style');
      style.id = 'jeab-swr-style';
      style.textContent = '#jeab-swr{position:fixed;top:0;left:0;right:0;height:2px;z-index:80;pointer-events:none;overflow:hidden}'
        + '#jeab-swr[hidden]{display:none}'
        + '#jeab-swr .jeab-swr-bar{display:block;height:2px;width:35%;background:currentColor;opacity:.55;animation:jeab-swr 1.1s ease-in-out infinite}'
        + '@keyframes jeab-swr{50%{opacity:.25;transform:translateX(120%)}}'
        + '#jeab-swr .jeab-swr-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}';
      document.head.appendChild(style);
    }
    var el = document.getElementById('jeab-swr');
    if (el) {
      return el;
    }
    el = document.createElement('div');
    el.id = 'jeab-swr';
    el.hidden = true;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = '<span class="jeab-swr-bar" aria-hidden="true"></span><span class="jeab-swr-text">Uppdaterar</span>';
    document.body.appendChild(el);
    return el;
  }

  function markUpdating(on) {
    var el = ensureSwr();
    el.hidden = !on;
    var main = document.querySelector('main.main');
    if (!main) {
      return;
    }
    if (on) {
      main.setAttribute('data-jeab-stale', '1');
    } else {
      main.removeAttribute('data-jeab-stale');
    }
  }

  function go(url, push, opts) {
    opts = opts || {};
    rememberVisibleList();
    var leavingQ = '';
    if (isListUrl(location.href)) {
      leavingQ = listQueryNow();
      applyListQuery(document.querySelector('main.main'), leavingQ);
      commitListHistory(leavingQ);
    }
    if (leavingQ && urlOrderId(url) && !queryOf(url)) {
      try {
        var withQ = new URL(url, location.href);
        withQ.searchParams.set('q', leavingQ);
        url = withQ.pathname + withQ.search + withQ.hash;
      } catch (e) {}
    }
    if (push && isListUrl(url) && !queryOf(url)) {
      listQueryTouched = false;
      listQuery = '';
      window.jeabListQuery = '';
    }
    var key = keyOf(url);
    if (warm && warm.press) {
      var pressCtrl = warm.ctrl;
      warm = null;
      if (pressCtrl) {
        try { pressCtrl.abort(); } catch (e) {}
      }
      if (window.jeabBackground && window.jeabBackground.ctrl === pressCtrl) {
        window.jeabBackground.ctrl = null;
      }
    }
    var adopted = takeWarm(key);
    abortShellFetches();
    cancelIdlePrefetch();
    if (urlOrderId(url)) {
      abortListFetches();
    }
    var id = ++gen;
    window.jeabNavGen = id;
    try {
      window.dispatchEvent(new CustomEvent('jeab:nav', { detail: { gen: id, url: String(url) } }));
    } catch (e) {
      /* äldre webbläsare utan CustomEvent */
    }
    if (push) {
      var stateQ = queryOf(key);
      if (!stateQ && history.state && typeof history.state.jeabQ === 'string') {
        stateQ = history.state.jeabQ;
      }
      try {
        history.pushState({ jeabShell: 1, jeabGen: id, jeabQ: stateQ }, '', shellUrl(url));
      } catch (e) {
        location.href = url;
        return;
      }
    }
    var hit = mem.get(key);
    if (hit && urlOrderId(url) && !htmlIsOrder(hit.data && hit.data.html)) {
      mem.delete(key);
    }
    /* Sparad HTML är redan ritad. Klicket räknas klart där. Servern får
       sista ordet i bakgrunden, även när cachen är färsk. */
    if (viewHtml(url)) {
      markUpdating(true);
      if (paintArmed) {
        markDone();
      }
    } else {
      markUpdating(false);
    }
    paintArmed = false;
    var epoch = viewEpoch;
    var task = adopted ? adopted.then(function (data) {
      if (data && data.ok === true) {
        return data;
      }
      return fresh(url);
    }) : fresh(url);
    task.then(function (data) {
      if (id !== gen) {
        return;
      }
      if (urlOrderId(location.href) && (!urlOrderId(url) || htmlIsList(data && data.html))) {
        return;
      }
      apply(data, url, epoch);
    }).catch(function (err) {
      if (id !== gen) {
        return;
      }
      if (err && (err.name === 'AbortError' || err.name === 'StaleNav')) {
        return;
      }
      if (urlOrderId(location.href) && !urlOrderId(url)) {
        return;
      }
      location.href = url;
    });
  }

  document.addEventListener('pointerdown', rememberRow, true);

  document.addEventListener('click', function (event) {
    var forced = takePressedRow(event);
    var el = eventElement(event);
    var a = el && el.closest('a[href]');
    var rowEl = null;
    if (forced) {
      var rows = document.querySelectorAll('a.ordrar-row[href]');
      var i;
      for (i = 0; i < rows.length; i += 1) {
        if (rows[i].href === forced) {
          rowEl = rows[i];
          break;
        }
      }
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      paintNow(forced, rowEl);
      go(forced, true);
      return;
    }
    if (formControlTarget(event)) {
      return;
    }
    if (!arm(event, a)) {
      return;
    }
    var dest = a.href;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    paintNow(dest, a);
    go(dest, true);
  }, true);

  var ordrarSearchTimer = 0;
  document.addEventListener('input', function (event) {
    var t = event.target;
    if (!t || !t.getAttribute || t.getAttribute('data-js') !== 'ordrar-search') {
      return;
    }
    var q = String(t.value || '');
    noteListQuery(q);
    applyListQuery(document.querySelector('main.main'), q);
    commitListHistory(q);
    if (ordrarSearchTimer) {
      window.clearTimeout(ordrarSearchTimer);
    }
    ordrarSearchTimer = window.setTimeout(function () {
      var board = document.querySelector('.ordrar-board');
      if (!board || board.getAttribute('data-server-search') !== '1' || !isListUrl(location.href)) {
        return;
      }
      var want = String(t.value || '').trim();
      if (want === String(board.getAttribute('data-q') || '')) {
        return;
      }
      try {
        var u = new URL(location.href);
        if (want) {
          u.searchParams.set('q', want);
        } else {
          u.searchParams.delete('q');
        }
        u.searchParams.delete('n');
        u.searchParams.delete('ns');
        u.searchParams.delete('nk');
        go(u.pathname + u.search + u.hash, true, { fresh: true });
      } catch (e) {}
    }, 350);
  }, true);

  window.addEventListener('popstate', function () {
    if (typeof window.jeabReminderOpenPop === 'function' && window.jeabReminderOpenPop()) {
      return;
    }
    /* Inkorgen ritar Tillbaka själv när dess skript lever.
       Annars hämtas partialen, utan att CSS och app.js laddas om. */
    if (isInbox(pageOf(location.href)) && window.jeabIxNav) {
      return;
    }
    var backState = history.state;
    if (isListUrl(location.href) && backState && typeof backState.jeabQ === 'string' && backState.jeabQ && queryOf(location.href) !== backState.jeabQ) {
      try {
        var backUrl = new URL(location.href);
        backUrl.searchParams.set('q', backState.jeabQ);
        history.replaceState(backState, '', backUrl.pathname + backUrl.search + backUrl.hash);
      } catch (e) {}
      listQuery = backState.jeabQ;
      listQueryTouched = true;
      window.jeabListQuery = listQuery;
    }
    var main = document.querySelector('main.main');
    var keepList = !!(main && isListUrl(location.href) && main.querySelector('.ordrar-board') && !main.querySelector('[data-v3-root]'));
    if (!keepList) {
      paintNow(location.href, null);
    }
    go(location.href, false, { fresh: true });
  });

  window.addEventListener('pageshow', function (event) {
    if (!event.persisted || isInbox(pageOf(location.href)) || urlOrderId(location.href)) {
      return;
    }
    go(location.href, false, { fresh: true });
  });

  document.addEventListener('mouseover', function (event) {
    if (formControlTarget(event)) {
      return;
    }
    var el = eventElement(event);
    var a = el && el.closest('a[href]');
    if (!a || a === hoverEl || !arm({ button: 0 }, a)) {
      return;
    }
    hoverEl = a;
    window.clearTimeout(hoverTimer);
    ensureStyles(pageStyleHrefs(a.href));
    hoverTimer = window.setTimeout(function () {
      prefetch(a.href);
    }, HOVER_MS);
  }, true);

  document.addEventListener('mouseout', function (event) {
    var el = eventElement(event);
    var a = el && el.closest('a[href]');
    if (!a || a !== hoverEl) {
      return;
    }
    var to = event.relatedTarget;
    if (to && a.contains(to)) {
      return;
    }
    window.clearTimeout(hoverTimer);
    hoverEl = null;
  }, true);

  document.addEventListener('touchstart', function (event) {
    rememberRow(event);
    notePrefetchActivity(event);
  }, { capture: true, passive: true });

  document.addEventListener('pointerdown', notePrefetchActivity, true);
  document.addEventListener('keydown', notePrefetchActivity, true);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      cancelIdlePrefetch();
      if (window.jeabBackground) {
        window.jeabBackground.abort();
      }
      return;
    }
    lastActive = Date.now();
    scheduleIdlePrefetch();
  });
  window.addEventListener('load', function () {
    lastActive = Date.now();
    scheduleIdlePrefetch();
  });

  if (typeof window.fetch === 'function' && !window.fetch.__jeabViewWrap) {
    var jeabNativeFetch = window.fetch;
    var jeabWrappedFetch = function (input, init) {
      var method = 'GET';
      if (init && init.method) {
        method = String(init.method);
      } else if (input && typeof input !== 'string' && input.method) {
        method = String(input.method);
      }
      method = method.toUpperCase();
      if (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
        invalidateViews();
      }
      return jeabNativeFetch.apply(this, arguments);
    };
    jeabWrappedFetch.__jeabViewWrap = true;
    window.fetch = jeabWrappedFetch;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.querySelector) {
      return;
    }
    var action = form.querySelector('input[name="action"]');
    var method = String(form.method || 'get').toLowerCase();
    if ((action && action.value === 'logout') || method === 'post') {
      invalidateViews();
    }
    /* En gammal lista får inte behandlas som att skrivningen redan skett.
       Formuläret går till servern, som validerar. */
    var staleMain = document.querySelector('main.main');
    if (staleMain && staleMain.getAttribute('data-jeab-stale') === '1' && staleMain.contains(form)) {
      form.setAttribute('data-jeab-server', '1');
    }
  }, true);

  try {
    if (!history.state || !history.state.jeabShell) {
      history.replaceState({ jeabShell: 1 }, '', location.href);
    }
  } catch (e) {
    /* ignore */
  }
  prevNav = navKey(pageOf(location.href));
  if (isListUrl(location.href)) {
    rememberVisibleList();
  } else if (!htmlIsSkeleton(main0.innerHTML)) {
    writeStore(keyOf(location.href), main0.innerHTML);
  }
  scheduleIdlePrefetch();
})();
