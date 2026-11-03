/* Ordrar v3 – öppet ärende som en lång sida (skiss v3).
   Menyn hoppar inom sidan utan omladdning och markerar synligt steg.
   Varje steg postar till samma åtgärder som den gamla vyn. Under arbete
   snurrar knappen tills sidan laddats om med nytt läge; fel visas på plats. */
function jeabOrdrarV3Mount() {
  'use strict';
  var prevFlush = window.__jeabV3FlushFields;
  window.__jeabV3FlushFields = function () {};
  if (typeof prevFlush === 'function') { prevFlush(); }
  if (window.__jeabV3Abort) { window.__jeabV3Abort.abort(); }
  var ac = new AbortController();
  window.__jeabV3Abort = ac;
  var signal = ac.signal;
  var root = document.querySelector('[data-v3-root]');
  if (!root) { return; }
  var $ = function (sel, el) { return (el || root).querySelector(sel); };
  var $$ = function (sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); };
  var MQ = window.matchMedia('(max-width:760px)');
  function mob() { return MQ.matches; }
  var main = $('#v3-main');
  var head = $('#v3-ohead');
  var aside = $('.v3-aside');
  var menu = $('#v3-menu');
  var bar = $('#v3-mbar');
  var curId = null;
  var busy = false;

  /* ---------- hjälp ---------- */
  var toastTimer = 0;
  var toastUndoTimer = 0;
  var pendingDelete = null;
  /* Radera-Ångra ligger på document.body så knappen överlever skalets byte av ordern. */
  function deleteUndoToastEl() {
    var t = null;
    if (document.body && document.body.querySelector) {
      try { t = document.body.querySelector(':scope > [data-v3-delete-undo]'); } catch (err) { t = null; }
    }
    if (!t && document.querySelector) { t = document.querySelector('[data-v3-delete-undo]'); }
    if (t && t.parentNode === document.body) { copyDeleteUndoVars(t); return t; }
    if (t && document.body) { document.body.appendChild(t); copyDeleteUndoVars(t); return t; }
    t = document.createElement('div');
    t.className = 'v3-toast';
    t.setAttribute('data-v3-delete-undo', '');
    t.setAttribute('role', 'status');
    if (document.body) { document.body.appendChild(t); }
    copyDeleteUndoVars(t);
    return t;
  }
  /* Body-toasten ärver inte .ov3, så färger och bottenfältets höjd kopieras hit. */
  function copyDeleteUndoVars(t) {
    if (!t || !t.style || !t.style.setProperty || !window.getComputedStyle) { return; }
    var src = document.querySelector('.ov3');
    if (!src) { return; }
    var cs = null;
    try { cs = window.getComputedStyle(src); } catch (err) { cs = null; }
    if (!cs || !cs.getPropertyValue) { return; }
    ['--v3-pop', '--v3-text', '--v3-border2', '--v3-mint', '--v3-barh'].forEach(function (name) {
      var v = cs.getPropertyValue(name);
      if (v && String(v).trim()) { t.style.setProperty(name, String(v).trim()); }
    });
  }
  function liveToastEl() {
    if (root && root.isConnected !== false) {
      var own = root.querySelector('[data-v3-toast]');
      if (own) { return own; }
    }
    var live = document.querySelector('[data-v3-root]');
    if (live && live.isConnected !== false && live !== root) {
      var next = live.querySelector('[data-v3-toast]');
      if (next) { return next; }
    }
    return root ? root.querySelector('[data-v3-toast]') : null;
  }
  function clearDeleteUndoToast(state) {
    if (state && window.__jeabV3PendingDelete && window.__jeabV3PendingDelete !== state) { return; }
    var t = document.querySelector('[data-v3-delete-undo]');
    if (!t) { return; }
    clearTimeout(t._v3Hide);
    t.classList.remove('is-show', 'has-undo', 'is-undo');
  }
  function flushPendingDelete() {
    var state = window.__jeabV3PendingDelete;
    if (!state || state.undone || state.sent) { return; }
    state.sent = true;
    clearTimeout(state.timer);
    if (pendingDelete === state) { pendingDelete = null; }
    window.__jeabV3PendingDelete = null;
    clearDeleteUndoToast(state);
    var f = state.form;
    if (!f) { return; }
    var url = f.getAttribute('action') || state.listUrl || window.location.href;
    var body = formBody(f, { action: 'ordrar.delete' });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(url, body)) { return; }
    } catch (err) { /* ignore */ }
    try { f.submit(); } catch (err2) { /* ignore */ }
  }
  function commitPendingDelete() {
    var state = window.__jeabV3PendingDelete;
    if (!state || state.undone || state.sent) { return; }
    state.sent = true;
    clearTimeout(state.timer);
    if (window.__jeabV3PendingDelete === state) { window.__jeabV3PendingDelete = null; }
    if (pendingDelete === state) { pendingDelete = null; }
    var f = state.form;
    if (!f) { return; }
    var list = state.listUrl || f.getAttribute('action') || window.location.href;
    post(f, { action: 'ordrar.delete' }).then(function () {
      clearDeleteUndoToast(state);
      // Raderingen är redan gjord. Ångra i ca 6 s lägger tillbaka ordern (ordrar.restore).
      var leave = function () {
        if (state.undone) { return; }
        var live = document.querySelector('[data-v3-root]');
        if (live && state.orderId && live.getAttribute('data-id') === String(state.orderId)) {
          window.location.assign(list);
        }
      };
      toast('Ärendet raderat.', function () { restoreDeleted(state, leave); });
      state.leaveTimer = setTimeout(leave, 6000);
    }).catch(function (err) {
      state.sent = false;
      if (!window.__jeabV3PendingDelete || window.__jeabV3PendingDelete === state) {
        window.__jeabV3PendingDelete = null;
        if (pendingDelete === state) { pendingDelete = null; }
        clearDeleteUndoToast(state);
        if (state.root && state.root.classList) { state.root.classList.remove('is-deleting'); }
        toast(failText(err && err.message ? err.message : 'Kunde inte radera.'), false);
      }
    });
  }
  function restoreDeleted(state, leave) {
    if (!state || state.undone) { return; }
    state.undone = true;
    clearTimeout(state.leaveTimer);
    toast('Återställer ärendet…', false);
    post(state.form, { action: 'ordrar.restore', id: String(state.orderId || '') }).then(function () {
      if (state.root && state.root.classList) { state.root.classList.remove('is-deleting'); }
      reloadTo('', 'Radering ångrad.');
    }).catch(function (err) {
      toast(failText(err && err.message ? err.message : 'Kunde inte ångra raderingen.'), false);
      state.undone = false;
      state.leaveTimer = setTimeout(leave, 2600);
    });
  }
  function deleteUndoStillPressable() {
    var host = document.querySelector('[data-v3-delete-undo]');
    var btn = host && host.querySelector('[data-v3-undo]');
    return !!(host && btn && btn.isConnected && host.classList.contains('is-show') && host.classList.contains('has-undo'));
  }
  /* Ommontning: rensa timern bara när Ångra fortfarande går att trycka, och låt sexsekunderscommitten stå kvar. */
  function adoptPendingDelete() {
    var state = window.__jeabV3PendingDelete;
    if (!state || state.undone || state.sent) { return; }
    pendingDelete = state;
    if (!deleteUndoStillPressable()) { return; }
    clearTimeout(state.timer);
    var left = 6000 - (Date.now() - (state.startedAt || Date.now()));
    if (left < 0) { left = 0; }
    state.timer = setTimeout(commitPendingDelete, left);
  }
  function bookedToastMsg(msg) {
    var s = String(msg || '');
    return s.indexOf('tiden bokad') !== -1 || s.indexOf('Bokad.') === 0;
  }
  function bookingId() {
    return Number(root.getAttribute('data-booking-id') || 0) || 0;
  }
  function undoBookedTime() {
    var form = $('#v3-f-avboka');
    var bid = bookingId();
    if (!form || !bid) { return; }
    post(form, { action: 'booking.cancel', id: String(bid) }).then(function () {
      reloadTo('', 'Bokningen ångrad.');
    }).catch(function (err) {
      toast(failText(err && err.message ? err.message : 'Kunde inte ångra.'), false);
    });
  }
  function toast(msg, withUndo) {
    var undoFn = typeof withUndo === 'function' ? withUndo : null;
    var deleteUndo = !!undoFn && msg === 'Ärendet raderat.';
    var t = deleteUndo ? deleteUndoToastEl() : liveToastEl();
    if (!t || !msg) { return; }
    var undo = !!undoFn || withUndo === true || (withUndo !== false && bookedToastMsg(msg) && bookingId() > 0);
    clearTimeout(t._v3Hide);
    /* Även radering: ett pågående håll (faktura, föranmälan, makulera) får inte posta samtidigt. */
    clearTimeout(toastTimer);
    clearTimeout(toastUndoTimer);
    if (deleteUndo) {
      var live = liveToastEl();
      if (live && live !== t) {
        clearTimeout(live._v3Hide);
        live.textContent = '';
        live.classList.remove('is-show', 'has-undo', 'is-undo');
      }
    }
    t.textContent = '';
    var text = document.createElement('span');
    text.textContent = msg;
    t.appendChild(text);
    t.classList.remove('is-undo');
    t.classList.toggle('has-undo', undo);
    if (undo) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'v3-toast-undo';
      btn.setAttribute('data-v3-undo', '');
      btn.textContent = 'Ångra';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        clearTimeout(t._v3Hide);
        clearTimeout(toastTimer);
        t.classList.remove('is-show', 'has-undo', 'is-undo');
        if (undoFn) { undoFn(); }
        else { undoBookedTime(); }
      });
      t.appendChild(btn);
    }
    t.classList.add('is-show');
    var hideMs = undo ? 6000 : 2600;
    var hide = function () { t.classList.remove('is-show', 'has-undo'); };
    if (deleteUndo) { t._v3Hide = setTimeout(hide, hideMs); }
    else { toastTimer = setTimeout(hide, hideMs); }
  }
  function toastUndo(msg, onUndo, onDone, ms) {
    var t = $('[data-v3-toast]');
    if (!t) { if (typeof onDone === 'function') { onDone(); } return; }
    ms = ms || 5000;
    clearTimeout(toastTimer);
    clearTimeout(toastUndoTimer);
    t.textContent = '';
    t.appendChild(document.createTextNode((msg || 'Stängt') + ' · '));
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'v3-toast-undo';
    btn.setAttribute('data-v3-undo', '');
    btn.textContent = 'Ångra';
    t.appendChild(btn);
    t.classList.add('is-show', 'is-undo', 'has-undo');
    var done = false;
    var finish = function (undone) {
      if (done) { return; }
      done = true;
      clearTimeout(toastUndoTimer);
      t.classList.remove('is-show', 'is-undo', 'has-undo');
      t.textContent = '';
      if (undone) { onUndo(); } else { onDone(); }
    };
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      finish(true);
    });
    toastUndoTimer = setTimeout(function () { finish(false); }, ms);
    try { btn.focus(); } catch (err) { /* focus is optional */ }
  }
  function digits(v) { return String(v || '').replace(/\D+/g, ''); }
  function num(v) {
    var s = String(v == null ? '' : v).replace(/\s+/g, '').replace(/kr$/i, '').replace(',', '.');
    var n = parseFloat(s);
    return isFinite(n) ? n : 0;
  }
  function kr(n) {
    var r = Math.round(n * 100) / 100;
    var whole = Math.trunc(r);
    var dec = Math.abs(Math.round((r - whole) * 100));
    var txt = String(Math.abs(whole)).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
    return (r < 0 ? '−' : '') + txt + (dec ? ',' + (dec < 10 ? '0' : '') + dec : '') + '\u00a0kr';
  }
  function failText(msg) {
    var reason = String(msg || '').trim();
    if (!reason) { return 'Kunde inte spara.'; }
    return reason.indexOf('Kunde inte spara') === 0 ? reason : 'Kunde inte spara. ' + reason;
  }
  var SPIN = '<span class="v3-spin" aria-hidden="true"></span>';

  /* ---------- layout och scroll-spy ---------- */
  function chromeH() {
    var c = document.querySelector('.chrome');
    if (!c || mob()) { return 0; }
    var pos = getComputedStyle(c).position;
    return pos === 'sticky' || pos === 'fixed' ? c.offsetHeight : 0;
  }
  function stickH() { return mob() && head && aside ? head.offsetHeight + aside.offsetHeight : 0; }
  function layoutVars() {
    var s = root.style;
    s.setProperty('--v3-top', chromeH() + 'px');
    if (!mob()) { s.removeProperty('--v3-hh'); s.removeProperty('--v3-stick'); return; }
    s.setProperty('--v3-hh', (head ? head.offsetHeight : 0) + 'px');
    s.setProperty('--v3-stick', stickH() + 'px');
  }
  function cards() { return $$('section.v3-card', main); }
  function sizeSpacer() {
    var c = cards();
    var last = c[c.length - 1];
    var sp = $('.v3-spacer', main);
    if (!last || !sp) { return; }
    if (!mob()) {
      sp.style.height = Math.max(24, window.innerHeight - last.offsetHeight - chromeH() - 32) + 'px';
      return;
    }
    sp.style.height = '0px';
    var need = last.getBoundingClientRect().top + window.scrollY - (stickH() + 10);
    var max = document.documentElement.scrollHeight - window.innerHeight;
    sp.style.height = Math.max(24, Math.ceil(need - max) + 2) + 'px';
  }
  function spy() {
    var secs = cards();
    if (!secs.length) { return; }
    var cur = secs[0].id;
    var lim = mob() ? stickH() + 40 : chromeH() + 80;
    secs.forEach(function (s) { if (s.getBoundingClientRect().top <= lim) { cur = s.id; } });
    $$('.v3-mi', menu).forEach(function (m) { m.classList.toggle('is-active', m.getAttribute('href') === '#' + cur); });
    if (mob() && cur !== curId) {
      var a = $('.v3-mi.is-active', menu);
      if (a && menu) {
        var l = a.offsetLeft - 16;
        if (l < menu.scrollLeft || a.offsetLeft + a.offsetWidth > menu.scrollLeft + menu.clientWidth) {
          menu.scrollTo({ left: l, behavior: 'smooth' });
        }
      }
    }
    curId = cur;
    updateBar();
  }
  function scrollToStep(id, flash) {
    var el = document.getElementById('steg-' + id);
    if (!el) { return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    try { history.replaceState(history.state, '', '#steg-' + id); } catch (e) { /* ignore */ }
    if (flash) {
      el.classList.add('is-flash');
      setTimeout(function () { el.classList.remove('is-flash'); }, 1400);
    }
  }
  /* Vunnen m.fl. landar med ?stop=bekrafta (gamla vyn). Öppna samma steg här. */
  function stepFromStop(raw) {
    var want = String(raw || '').toLowerCase().replace(/[^a-z_]/g, '');
    if (!want) { return ''; }
    var map = {
      status: 'status', objekt: 'status',
      bekrafta: 'bekrafta', confirm: 'bekrafta', vad: 'bekrafta', kop: 'bekrafta', paminelse: 'bekrafta', spec: 'bekrafta',
      foranmalan: 'foranmalan', eon: 'foranmalan', fardiganmal: 'foranmalan', plomb: 'foranmalan', fullmakt: 'foranmalan',
      boka: 'boka', boka_elin: 'boka', boka_bestall: 'boka',
      bestall: 'bestall', bestallning: 'bestall', orderrader: 'bestall',
      faktura: 'faktura', fakturering: 'faktura', fakturera: 'faktura', fortnox: 'faktura', fortnox_l: 'faktura',
      stang: 'stang', aktivitet: 'aktivitet'
    };
    var id = map[want] || want;
    if (id === 'boka' && !document.getElementById('steg-boka')) {
      id = document.getElementById('steg-bestall') ? 'bestall' : 'faktura';
    }
    return document.getElementById('steg-' + id) ? id : '';
  }

  /* ---------- mobil: bottenfält speglar synligt kort ---------- */
  function updateBar() {
    if (!bar) { return; }
    var hide = function () {
      bar.hidden = true;
      root.style.setProperty('--v3-barh', '0px');
    };
    if (!mob() || !curId) { hide(); return; }
    var card = document.getElementById(curId);
    var id = curId.replace('steg-', '');
    // Bekräfta-knappen sitter i kortet. Bottenfältet ska inte visa en andra grön knapp.
    if (id === 'bekrafta') { hide(); return; }
    var pb = card && card.querySelector('[data-v3-primary="' + id + '"]');
    if (id === 'faktura') {
      var confirmPanel = card.querySelector('[data-v3-faktura-confirm]');
      var go = card.querySelector('[data-v3-faktura-go]');
      if (confirmPanel && !confirmPanel.hidden && go) { pb = go; }
    }
    if (!pb) { hide(); return; }
    var title = card.querySelector('.v3-chead h2');
    var n = card.querySelector('.v3-chead .v3-ic');
    $('#v3-mbar-step').textContent = (n ? n.textContent.trim() + ' · ' : '') + (title ? title.textContent : '');
    var pri = $('#v3-mbar-pri');
    pri.innerHTML = pb.innerHTML;
    pri.disabled = pb.disabled;
    pri.classList.toggle('is-working', pb.classList.contains('is-working'));
    pri.setAttribute('data-step', id);
    var rs = card.querySelector('[data-v3-reason="' + id + '"]');
    var err = card.querySelector('[data-v3-err="' + id + '"]');
    var mr = $('#v3-mbar-reason');
    if (err && !err.hidden && err.textContent) {
      mr.textContent = err.textContent;
      mr.classList.add('is-err');
    } else {
      mr.textContent = pb.disabled && rs && rs.classList.contains('is-warn') ? rs.textContent : '';
      mr.classList.remove('is-err');
    }
    var utanWrap = $('#v3-mbar-utanwrap');
    if (utanWrap) { utanWrap.hidden = true; }
    var sb = card.querySelector('.v3-actrow [data-v3-sec]');
    var sec = $('#v3-mbar-sec');
    if (sb) {
      sec.hidden = false;
      sec.innerHTML = sb.innerHTML;
      sec.disabled = sb.disabled;
      sec.classList.toggle('is-working', sb.classList.contains('is-working'));
    } else {
      sec.hidden = true;
    }
    bar.hidden = false;
    root.style.setProperty('--v3-barh', bar.offsetHeight + 'px');
  }

  /* ---------- validering Bekräfta ---------- */
  var bekForm = $('#v3-f-bekrafta');
  function ownerCards() { return bekForm ? $$('.v3-owner', bekForm) : []; }
  function readField(card, key) {
    var el = card.querySelector('[data-field="' + key + '"]');
    if (!el) { return ''; }
    return (el.tagName === 'INPUT' ? el.value : el.textContent).trim();
  }
  function readCard(card) {
    return {
      name: readField(card, 'name') || card.getAttribute('data-name') || '',
      pnr: readField(card, 'pnr') || card.getAttribute('data-pnr') || '',
      addr: readField(card, 'address') || card.getAttribute('data-addr') || ''
    };
  }
  function huvudCard() {
    var cs = ownerCards();
    for (var i = 0; i < cs.length; i++) { if (cs[i].getAttribute('data-huvud') === '1') { return cs[i]; } }
    return cs[0] || null;
  }
  function readKind() {
    var c = bekForm ? bekForm.querySelector('input[name="kind"]:checked') : null;
    return c ? String(c.value || '') : '';
  }
  function missingFortnox() {
    var m = [];
    if (!bekForm) { return m; }
    var h = huvudCard();
    var hp = h ? readCard(h) : { name: '', pnr: '' };
    var p = digits(hp.pnr);
    if (p.length < 10 || p.length > 13) { m.push('personnummer'); }
    var fast = bekForm.querySelector('[name="fastighetsbeteckning"]');
    if (!fast || !String(fast.value || '').trim()) { m.push('fastighetsbeteckning'); }
    if (!hp.name.trim()) { m.push('huvudsökande'); }
    ownerCards().forEach(function (c) {
      if (c === h) { return; }
      var o = readCard(c);
      if (o.name.trim() && digits(o.pnr).length < 10) { m.push('personnummer för medsökande'); }
    });
    var pris = bekForm.querySelector('[name="pris"]');
    if (!(num(pris ? pris.value : '') > 0)) { m.push('pris'); }
    return m;
  }
  function validate(step) {
    if (step === 'bekrafta') {
      if (!readKind()) { return 'Välj produkt.'; }
      var m = missingFortnox();
      return m.length ? 'Saknas för Fortnox-order: ' + m.join(', ') + '.' : '';
    }
    if (step === 'foranmalan') {
      var fm = foranMissing();
      return fm.length ? 'Saknas för Bokmalen: ' + fm.join(', ') + '.' : '';
    }
    if (step === 'bestall') {
      if (bestallMode() !== 'A') { return ''; }
      var any = $$('#v3-f-inkop [name="line_qty[]"]').some(function (q) { return num(q.value) > 0; });
      return any ? '' : 'Ingen rad i inköpslistan har antal större än 0.';
    }
    if (step === 'faktura') {
      var payErr = $('[data-v3-pay-error]');
      if (payErr && String(payErr.textContent || '').trim()) { return String(payErr.textContent).trim(); }
      var ok = $$('[data-v3-fxrow]').some(function (r) {
        var d = r.querySelector('[name="fx_desc[]"]');
        var q = r.querySelector('[name="fx_qty[]"]');
        var p = r.querySelector('[name="fx_price[]"]');
        return d && String(d.value).trim() && num(q && q.value !== '' ? q.value : 1) * num(p ? p.value : 0) !== 0;
      });
      return ok ? '' : 'Fyll i minst en rad med benämning, antal och à-pris.';
    }
    return '';
  }
  /* ---------- Föranmälan och Beställ (batteri/sol) ---------- */
  var foranForm = $('#steg-foranmalan.is-todo #v3-f-foranmalan');
  function radioVal(form, name) {
    var c = form ? form.querySelector('input[name="' + name + '"]:checked') : null;
    return c ? String(c.value || '') : '';
  }
  function foranMode() { return radioVal(foranForm, 'foranmalan_status') || 'A'; }
  function foranNat() { return radioVal(foranForm, 'natbolag'); }
  function bestallMode() { return radioVal($('#v3-f-bestall'), 'bestallning_status') || 'A'; }
  function foranMissing() {
    var m = [];
    if (!foranForm || foranMode() !== 'A') { return m; }
    var nat = foranNat();
    if (!nat) { m.push('nätbolag'); }
    var aid = foranForm.querySelector('[name="anlaggnings_id"]');
    var aidDigits = digits(aid ? aid.value : '');
    if (nat !== 'Ellevio' && aidDigits.length !== 18) { m.push('anläggnings-id (18 siffror)'); }
    var kwh = foranForm.querySelector('[name="kwh"]');
    var kwhVal = String(kwh ? kwh.value : '').trim().replace(/\s+/g, '').replace(',', '.');
    if (!kwhVal || !(parseFloat(kwhVal) > 0) || !/^\d+(\.\d+)?$/.test(kwhVal)) { m.push('kWh'); }
    var vaxel = foranForm.querySelector('[name="vaxelriktare"]');
    if (!String(vaxel ? vaxel.value : '').trim()) { m.push('växelriktare'); }
    return m;
  }
  var FORAN_WHAT = {
    A: 'Föranmälan skickas till Bokmalen som anmäler hos nätbolaget.',
    AE: 'Bokmalen skickar fullmakten till kunden. Föranmälan görs när den är signerad.',
    AS: 'Blanketten mejlas till Sam för signering. Kunden får den bara om du skickar manuellt och SEOM_CUSTOMER_SEND är på.',
    B: 'Sparas som utkast. Inget skickas till Bokmalen.',
    C: 'Föranmälan görs manuellt utanför appen. Inget skickas.'
  };
  function paintForan() {
    if (!foranForm) { return; }
    var mode = foranMode();
    var nat = foranNat();
    var ell = nat === 'Ellevio';
    var seom = nat === 'SEOM';
    var b = $('[data-v3-primary="foranmalan"]');
    if (b && !b.classList.contains('is-working')) {
      b.textContent = mode === 'B' ? 'Spara som utkast' : (mode === 'C' ? 'Markera som manuell' : (ell ? 'Skicka fullmakt' : (seom ? 'Skicka SEOM-blankett' : 'Skicka föranmälan')));
      b.setAttribute('data-working', mode === 'A' ? 'Skickar…' : 'Sparar…');
    }
    var r = $('[data-v3-reason="foranmalan"]');
    if (r) { r.setAttribute('data-what', FORAN_WHAT[mode === 'A' && ell ? 'AE' : (mode === 'A' && seom ? 'AS' : mode)] || ''); }
    var sec = $('#steg-foranmalan [data-v3-sec="foranmalan"]');
    if (sec) { sec.hidden = mode !== 'A'; }
    if (b) {
      if (mode === 'A') { b.setAttribute('data-v3-hold', '1'); }
      else { b.removeAttribute('data-v3-hold'); }
    }
    var ah = foranForm.querySelector('[data-v3-aid-help]');
    var ae = foranForm.querySelector('[data-v3-aid-ellevio]');
    var as = foranForm.querySelector('[data-v3-aid-seom]');
    var aid = foranForm.querySelector('[name="anlaggnings_id"]');
    if (ah) { ah.hidden = ell; }
    if (ae) { ae.hidden = !ell; }
    if (as) { as.hidden = !seom; }
    if (aid) { aid.required = seom && mode === 'A'; }
    var pre = foranForm.querySelector('[data-v3-bm-missing]');
    if (pre) { pre.hidden = true; }
    $$('.v3-seg-opt', foranForm).forEach(function (o) {
      var i = o.querySelector('input');
      o.classList.toggle('is-on', !!(i && i.checked));
    });
    refreshReason('foranmalan');
  }
  function paintBestall() {
    var f = $('#v3-f-bestall');
    if (!f) { return; }
    var mode = bestallMode();
    var b = $('[data-v3-primary="bestall"]');
    if (b && !b.classList.contains('is-working')) {
      b.textContent = mode === 'B' ? 'Skicka utkast till Bokmalen' : (mode === 'C' ? 'Markera som beställd' : 'Beställ');
      b.setAttribute('data-working', mode === 'C' ? 'Sparar…' : (mode === 'B' ? 'Skickar…' : 'Beställer…'));
    }
    var r = $('[data-v3-reason="bestall"]');
    if (r) {
      r.setAttribute('data-what', mode === 'B'
        ? 'Bokmalen får ett utkast. Ingen order läggs.'
        : (mode === 'C' ? 'Beställningen görs utanför appen. Steget markeras som klart.' : 'Bokmalen lägger ordern hos Aprilice/Lightleap med raderna ovan.'));
    }
    $$('.v3-seg-opt', f).forEach(function (o) {
      var i = o.querySelector('input');
      o.classList.toggle('is-on', !!(i && i.checked));
    });
    refreshReason('bestall');
  }

  /* ---------- inköpslista: autospar (ordrar.orderrader) + mobilrader ---------- */
  var ink = $('#v3-f-inkop');
  var inkT = 0;
  var inkSeq = 0;
  var inkLast = '';
  var inkPending = null;
  function inkSnap() {
    var parts = [];
    if (!ink) { return ''; }
    new FormData(ink).forEach(function (v, k) { if (k.indexOf('line_') === 0) { parts.push(k + '=' + v); } });
    return parts.join('&');
  }
  function inkStatus(text, isErr) {
    var st = ink ? ink.querySelector('[data-v3-autosave-status]') : null;
    if (!st) { return; }
    st.textContent = text;
    st.classList.toggle('is-err', !!isErr);
  }
  // Som servern: artikelnr, namn eller antal över 0 (mallens tomma rader har antal 0).
  function rowHas(r) {
    var ins = $$('input', r);
    if (ins.slice(0, 2).some(function (i) { return String(i.value || '').trim() !== ''; })) { return true; }
    var q = parseFloat(String((ins[2] && ins[2].value) || '').replace(',', '.'));
    return q > 0;
  }
  function inkUsed() {
    return $$('.ordrar-line', ink).filter(rowHas).length;
  }
  function paintInkSum() {
    var sm = $('[data-v3-inkop-sum]');
    if (sm) { var n = inkUsed(); sm.textContent = (n ? n + (n === 1 ? ' rad' : ' rader') : 'Tom') + ' · sparas automatiskt'; }
  }
  function inkSave() {
    clearTimeout(inkT);
    if (!ink) { return Promise.resolve(); }
    var now = inkSnap();
    if (now === inkLast) { return inkPending || Promise.resolve(); }
    var ticket = ++inkSeq;
    inkStatus('Sparar…', false);
    inkPending = post(ink, {}).then(function () {
      if (ticket === inkSeq) { inkLast = now; inkStatus('Sparad.', false); }
    }, function (err) {
      if (ticket === inkSeq) { inkStatus(failText(err && err.message ? err.message : ''), true); }
      throw err;
    });
    inkPending.catch(function () {});
    return inkPending;
  }
  function inkSoon() {
    clearTimeout(inkT);
    paintInkSum();
    inkT = setTimeout(function () { inkSave(); }, 500);
  }

  /* Textfält med data-v3-autosave-action sparar bara sitt eget namn och värde.
     F5 avbryter en pågående fetch även med keepalive, och FormData i den
     fetch:en når inte fram. pagehide och dold flik läser textfältet på nytt
     och köar sendBeacon med FormData. fetch keepalive är bara reserv.
     En pågående fetch räknas inte som levererad. sendBeacon visar inte «Sparad.». */
  var fieldAreas = [];
  function fieldStatus(area, text, isErr) {
    if (!area || area.isConnected === false) { return; }
    var line = area.closest ? area.closest('.v3-fieldline') : null;
    var st = line ? line.querySelector('[data-v3-field-status]') : null;
    if (!st && area.nextElementSibling && area.nextElementSibling.hasAttribute && area.nextElementSibling.hasAttribute('data-v3-field-status')) {
      st = area.nextElementSibling;
    }
    if (!st) { return; }
    st.textContent = text;
    st.classList.toggle('is-err', !!isErr);
  }
  function fieldSnapshot(area) {
    var prev = area._v3Snap || {};
    var form = area.form || null;
    var connected = area.isConnected !== false && !!form;
    var csrfEl = connected ? form.querySelector('input[name="_csrf"]') : null;
    var idEl = connected ? form.querySelector('input[name="id"]') : null;
    var snap = {
      url: (connected && (form.getAttribute('action') || form.action)) || prev.url || window.location.href,
      csrf: csrfEl ? csrfEl.value : (prev.csrf || ''),
      id: idEl ? idEl.value : (prev.id || ''),
      action: (area.getAttribute && area.getAttribute('data-v3-autosave-action')) || prev.action || '',
      name: area.name || prev.name || '',
      value: connected ? String(area.value == null ? '' : area.value) : (prev.value != null ? String(prev.value) : '')
    };
    area._v3Snap = snap;
    return snap;
  }
  function fieldBody(snap, rev) {
    var body = new FormData();
    if (snap.csrf) { body.set('_csrf', snap.csrf); }
    if (snap.id) { body.set('id', snap.id); }
    body.set('action', snap.action);
    body.set(snap.name, snap.value);
    body.set('v3', '1');
    if (rev) { body.set('note_rev', String(rev)); }
    return body;
  }
  function fieldTrim(value) {
    return String(value == null ? '' : value).replace(/^\s+|\s+$/g, '');
  }
  function fieldDeliver(url, body, signal) {
    var opts = {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    };
    if (signal) { opts.signal = signal; }
    try {
      return fetch(url, opts).catch(function (err) {
        if (signal && signal.aborted) { throw err; }
        if (navigator.sendBeacon && navigator.sendBeacon(url, body)) {
          var queued = new Error('');
          queued.beacon = true;
          throw queued;
        }
        throw err;
      });
    } catch (err) {
      if (signal && signal.aborted) { return Promise.reject(err); }
      if (navigator.sendBeacon && navigator.sendBeacon(url, body)) {
        return Promise.resolve({ beacon: true });
      }
      return Promise.reject(err);
    }
  }
  function fieldRead(res, want) {
    if (!res || res.beacon) {
      var queued = new Error('');
      queued.beacon = true;
      return Promise.reject(queued);
    }
    return res.text().then(function (txt) {
      var data = null;
      try { data = JSON.parse(txt); } catch (e) { data = null; }
      var got = data && data.row && data.row.install_note != null ? fieldTrim(data.row.install_note) : null;
      if (res.status !== 200 || !res.ok || !data || data.ok !== true || got === null || got !== fieldTrim(want)) {
        throw new Error((data && data.error) || '');
      }
      return data;
    });
  }
  function fieldBusy(area) {
    if (!area || !area.name) { return false; }
    var snap = fieldSnapshot(area);
    if (!snap.action || !snap.name || area._v3Last === snap.value) { return false; }
    return area._v3Dirty === true || area._v3Sent === snap.value;
  }
  function fieldSave(area) {
    if (!area || !area.name) { return Promise.resolve(); }
    clearTimeout(area._v3Timer);
    area._v3Timer = 0;
    var snap = fieldSnapshot(area);
    var value = snap.value;
    if (!snap.action || !snap.name) { return Promise.resolve(); }
    if (area._v3Last === value) { return Promise.resolve(); }
    if (area._v3Sent === value) { return area._v3Pending || Promise.resolve(); }
    if (area._v3Beacon === value) { return Promise.resolve(); }
    var ticket = (area._v3Seq = (area._v3Seq || 0) + 1);
    var rev = (area._v3Rev = (area._v3Rev || 0) + 1);
    var ac = typeof AbortController === 'function' ? new AbortController() : null;
    area._v3Abort = ac;
    area._v3Dirty = false;
    area._v3Sent = value;
    fieldStatus(area, 'Sparar…', false);
    var pending = fieldDeliver(snap.url, fieldBody(snap, rev), ac ? ac.signal : null).then(function (res) {
      return fieldRead(res, value);
    }).then(function () {
      if (ticket !== area._v3Seq) { return; }
      area._v3Last = value;
      area._v3Beacon = null;
      if (area._v3Sent === value) { area._v3Sent = null; }
      fieldStatus(area, 'Sparad.', false);
    }, function (err) {
      if (ticket !== area._v3Seq) { throw err; }
      if (area._v3Sent === value) { area._v3Sent = null; }
      if (err && err.beacon) {
        area._v3Beacon = value;
        fieldStatus(area, '', false);
        return;
      }
      area._v3Dirty = true;
      fieldStatus(area, failText(err && err.message ? err.message : ''), true);
      throw err;
    });
    pending.catch(function () {});
    area._v3Pending = pending;
    return pending;
  }
  function fieldSoon(area) {
    if (!area || !area.hasAttribute || !area.hasAttribute('data-v3-autosave-action')) { return; }
    fieldSnapshot(area);
    area._v3Dirty = true;
    area._v3Beacon = null;
    clearTimeout(area._v3Timer);
    area._v3Timer = setTimeout(function () { fieldSave(area); }, 500);
  }
  function fieldNow(area) {
    var snap = fieldSnapshot(area);
    if (area && area.isConnected !== false) {
      snap.value = String(area.value == null ? '' : area.value);
      if (area.name) { snap.name = area.name; }
      area._v3Snap = snap;
    }
    return snap;
  }
  function fieldUnload(area) {
    if (!area || !area.name) { return; }
    clearTimeout(area._v3Timer);
    area._v3Timer = 0;
    var snap = fieldNow(area);
    var value = snap.value;
    if (!snap.action || !snap.name || area._v3Last === value) { return; }
    area._v3Seq = (area._v3Seq || 0) + 1;
    if (area._v3Abort) {
      area._v3Abort.abort();
      area._v3Abort = null;
    }
    area._v3Sent = null;
    var rev = (area._v3Rev = (area._v3Rev || 0) + 1);
    var url = snap.url;
    var body = fieldBody(snap, rev);
    var queued = false;
    if (navigator.sendBeacon) {
      try { queued = navigator.sendBeacon(url, body) === true; } catch (err) { queued = false; }
    }
    if (queued) {
      area._v3Beacon = value;
      fieldStatus(area, '', false);
      return;
    }
    var ticket = area._v3Seq;
    var ac = typeof AbortController === 'function' ? new AbortController() : null;
    area._v3Abort = ac;
    area._v3Sent = value;
    fieldDeliver(url, fieldBody(snap, rev), ac ? ac.signal : null).then(function (res) {
      return fieldRead(res, value);
    }).then(function () {
      if (ticket !== area._v3Seq) { return; }
      area._v3Last = value;
      area._v3Beacon = null;
      if (area._v3Sent === value) { area._v3Sent = null; }
      fieldStatus(area, 'Sparad.', false);
    }, function (err) {
      if (ticket !== area._v3Seq) { return; }
      if (area._v3Sent === value) { area._v3Sent = null; }
      if (err && err.beacon) {
        area._v3Beacon = value;
        return;
      }
      area._v3Dirty = true;
    });
  }
  function flushFields() {
    fieldAreas.forEach(fieldUnload);
  }
  window.__jeabV3FlushFields = flushFields;
  $$('textarea[data-v3-autosave-action]').forEach(function (area) {
    fieldSnapshot(area);
    area._v3Last = area.value;
    area._v3Sent = null;
    fieldAreas.push(area);
  });
  adoptPendingDelete();
  window.addEventListener('pagehide', flushFields, { signal: signal });
  window.addEventListener('pagehide', flushPendingDelete, { signal: signal });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushFields();
      return;
    }
    fieldAreas.forEach(function (area) {
      if (area._v3Last !== String(area.value == null ? '' : area.value)) {
        area._v3Beacon = null;
        area._v3Dirty = true;
      }
    });
  }, { signal: signal });
  window.addEventListener('beforeunload', function (ev) {
    if (!fieldAreas.some(fieldBusy)) { return; }
    ev.preventDefault();
    ev.returnValue = '';
  }, { signal: signal });
  function mRows() {
    var box = $('[data-v3-mlines]');
    if (!box || !ink) { return; }
    var rows = $$('.ordrar-line', ink);
    var open = {};
    $$('details.v3-mline[open]', box).forEach(function (d) { open[d.getAttribute('data-i')] = true; });
    box.innerHTML = '';
    var shownEmpty = false;
    var hiddenEmpty = 0;
    rows.forEach(function (r, i) {
      var ins = $$('input', r);
      var used = rowHas(r);
      if (!used) {
        if (shownEmpty && !open[i]) { hiddenEmpty++; return; }
        shownEmpty = true;
      }
      var d = document.createElement('details');
      d.className = 'v3-mline';
      d.setAttribute('data-i', String(i));
      if (open[i] || (!used && r.hasAttribute('data-v3-new'))) { d.open = true; }
      var sum = document.createElement('summary');
      var t = document.createElement('span');
      t.className = 'v3-mn' + (used ? '' : ' is-empty');
      t.textContent = used ? (String(ins[1].value || '').trim() || String(ins[0].value || '').trim() || 'Rad') : 'Ny rad';
      var q = document.createElement('span');
      q.className = 'v3-mq';
      q.textContent = used && String(ins[2].value || '').trim() ? String(ins[2].value).trim() + ' st' : '';
      sum.appendChild(t);
      sum.appendChild(q);
      d.appendChild(sum);
      var body = document.createElement('div');
      body.className = 'v3-mlbody';
      [['Artikelnummer', 0, 'text'], ['Artikelnamn', 1, 'text'], ['Antal', 2, 'number']].forEach(function (spec) {
        var lab = document.createElement('label');
        var sp = document.createElement('span');
        sp.textContent = spec[0];
        var inp = document.createElement('input');
        inp.type = spec[2];
        if (spec[2] === 'number') { inp.min = '0'; inp.step = '1'; inp.inputMode = 'numeric'; }
        inp.autocomplete = 'off';
        inp.value = ins[spec[1]].value;
        inp.setAttribute('data-v3-mirror', i + ':' + spec[1]);
        lab.appendChild(sp);
        lab.appendChild(inp);
        body.appendChild(lab);
      });
      d.appendChild(body);
      box.appendChild(d);
    });
    if (hiddenEmpty > 0) {
      var more = document.createElement('p');
      more.className = 'v3-help';
      more.textContent = hiddenEmpty + ' tomma rader till.';
      box.appendChild(more);
    }
  }
  function mirrorInput(t) {
    var key = String(t.getAttribute('data-v3-mirror') || '').split(':');
    var row = $$('.ordrar-line', ink)[Number(key[0])];
    if (!row) { return; }
    var real = $$('input', row)[Number(key[1])];
    if (!real) { return; }
    real.value = t.value;
    var d = t.closest('details');
    if (d) {
      var ins = $$('input', row);
      var used = ins.some(function (x) { return String(x.value || '').trim() !== ''; });
      var mn = d.querySelector('.v3-mn');
      var mq = d.querySelector('.v3-mq');
      if (mn) { mn.textContent = used ? (String(ins[1].value || '').trim() || String(ins[0].value || '').trim() || 'Rad') : 'Ny rad'; mn.classList.toggle('is-empty', !used); }
      if (mq) { mq.textContent = used && String(ins[2].value || '').trim() ? String(ins[2].value).trim() + ' st' : ''; }
    }
    inkSoon();
    refreshReason('bestall');
  }

  var INFO = '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M8 7.2v3.6M8 5.2v.1"/></svg>';
  function refreshReason(step) {
    var b = $('[data-v3-primary="' + step + '"]');
    var r = $('[data-v3-reason="' + step + '"]');
    if (!b || !r || b.classList.contains('is-working')) { return; }
    var v = validate(step);
    b.disabled = !!v;
    r.classList.toggle('is-warn', !!v);
    if (v) { r.innerHTML = INFO + ' '; r.appendChild(document.createTextNode(v)); }
    else { r.textContent = r.getAttribute('data-what') || ''; }
    updateBar();
  }

  /* ---------- ägare (samma kanoniska fält som gamla vyn) ---------- */
  function setNamed(form, name, value) {
    var el = form.querySelector('input[name="' + name + '"]');
    if (el) { el.value = value; }
  }
  function appendHidden(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    input.setAttribute('data-v3-canon', '');
    form.appendChild(input);
  }
  function paintPills(card, isHuvud) {
    card.setAttribute('data-huvud', isHuvud ? '1' : '0');
    var h = card.querySelector('[data-v3="huvud"]');
    var m = card.querySelector('[data-v3="med"]');
    if (h) { h.classList.toggle('is-on', isHuvud); h.setAttribute('aria-pressed', isHuvud ? 'true' : 'false'); }
    if (m) { m.classList.toggle('is-on', !isHuvud); m.setAttribute('aria-pressed', isHuvud ? 'false' : 'true'); }
  }
  function writeCanon(form, hCard) {
    var cs = ownerCards();
    var h = readCard(hCard);
    setNamed(form, 'huvudsokande', h.name);
    setNamed(form, 'huvudsokande_personnummer', h.pnr);
    setNamed(form, 'huvudsokande_address', h.addr);
    $$('input[data-v3-canon]', form).forEach(function (el) { el.parentNode.removeChild(el); });
    cs.forEach(function (c) {
      var p = readCard(c);
      appendHidden(form, 'owner_name[]', p.name);
      appendHidden(form, 'owner_pnr[]', p.pnr);
      appendHidden(form, 'owner_address[]', p.addr);
      if (c === hCard) { return; }
      appendHidden(form, 'medsokande_name[]', p.name);
      appendHidden(form, 'medsokande_personnummer[]', p.pnr);
      appendHidden(form, 'medsokande_address[]', p.addr);
    });
    cs.forEach(function (c) { paintPills(c, c === hCard); });
  }

  /* ---------- POST ---------- */
  function formBody(form, extra) {
    // Läses innan kortet låses: inaktiverade fält följer inte med i FormData.
    var body = new FormData(form);
    Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
    body.set('v3', '1');
    return body;
  }
  function post(form, extra, prebuilt, signal, attempt) {
    var body = prebuilt || formBody(form, extra);
    return fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      signal: signal,
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    }).catch(function (err) {
      if (err && err.name === 'AbortError') { throw err; }
      throw new Error('Ingen kontakt med servern. Kontrollera nätet och försök igen.');
    }).then(function (res) {
      return res.text().then(function (txt) {
        var data = null;
        try { data = JSON.parse(txt); } catch (e) { data = null; }
        if (!data) {
          throw new Error(res.status === 401 || res.status === 403 || /login/i.test(res.url || '')
            ? 'Du är utloggad. Ladda om sidan och logga in igen.'
            : 'Svaret från servern gick inte att läsa (' + res.status + '). Ladda om sidan.');
        }
        if ((data.busy || data.retry || res.status === 503) && (attempt || 0) < 1 && !(signal && signal.aborted)) {
          return post(form, extra, body, signal, 1);
        }
        if (data.busy || data.retry) {
          throw new Error(data.error || 'Databasen är upptagen. Försök igen om en stund.');
        }
        if (!res.ok || data.ok === false) { throw new Error(data.error || 'Okänt fel.'); }
        return data;
      });
    });
  }
  function rebindShell() {
    if (!window.__jeabV3DidMount) {
      window.__jeabV3DidMount = true;
      return;
    }
    var pairs = [
      ['__jeabMallBound', '[data-js="ordrar-mall"]'],
      ['__jeabOffertBound', '[data-js="offert"]'],
      ['__jeabOffertEvBound', '[data-js="offert-ev"]'],
      ['__jeabOffertSaveBound', '[data-js="offert-save-bar"]']
    ];
    pairs.forEach(function (pair) {
      var node = document.querySelector(pair[1]);
      if (!node || node.getAttribute('data-jeab-boot') !== '1') { window[pair[0]] = false; }
    });
    if (window.jeabBootMall) { window.jeabBootMall(); }
    if (window.jeabBootOffert) { window.jeabBootOffert(); }
    if (window.jeabBootOffertEv) { window.jeabBootOffertEv(); }
    if (window.jeabBootOffertSave) { window.jeabBootOffertSave(); }
  }
  function reloadTo(step, msg) {
    try {
      if (msg) { sessionStorage.setItem('v3-toast', msg); }
      if (step === '') { sessionStorage.setItem('v3-next', '1'); }
      else { sessionStorage.removeItem('v3-next'); }
    } catch (e) { /* ignore */ }
    var url = root.getAttribute('data-self') || window.location.pathname + window.location.search;
    url = url.replace(/#.*$/, '');
    var hash = step ? '#steg-' + step : '';
    try { history.replaceState(history.state, '', url + hash); } catch (e) { /* ignore */ }
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' }
    }).then(function (res) {
      return res.text().then(function (txt) { return { ok: res.ok, txt: txt }; });
    }).then(function (pack) {
      var doc = new DOMParser().parseFromString(pack.txt || '', 'text/html');
      var next = doc.querySelector('[data-v3-root]');
      if (!next || !root.parentNode) {
        busy = false;
        toast('Steget kunde inte uppdateras. Du är kvar på sidan.');
        return;
      }
      root.replaceWith(next);
      if (typeof window.jeabOrdrarV3Mount === 'function') { window.jeabOrdrarV3Mount(); }
    }).catch(function () {
      busy = false;
      toast('Steget kunde inte uppdateras. Du är kvar på sidan.');
    });
  }
  /* Produktbyte: byt steglista och kalkyl på plats, utan att riva hela sidan. */
  function paintNavForKind(kind) {
    root.setAttribute('data-kind', kind || '');
    var labels = { laddbox: 'Elbilsladdare', batteri: 'Batteri', sol: 'Solpaneler' };
    var badge = $('.v3-badge');
    if (badge && labels[kind]) { badge.textContent = labels[kind]; }
    var kSol = bekForm && bekForm.querySelector('.v3-kalkyl');
    var kBat = bekForm && bekForm.querySelector('.v3-sigen');
    if (kSol) { kSol.hidden = kind !== 'sol'; }
    if (kBat) { kBat.hidden = kind !== 'batteri'; }
  }
  function applyKindDom(next, kind) {
    var newMenu = next.querySelector('#v3-menu');
    if (newMenu && menu && menu.parentNode) {
      menu.replaceWith(newMenu);
      menu = $('#v3-menu');
    }
    var sticky = { status: 1, bekrafta: 1, faktura: 1, stang: 1, aktivitet: 1 };
    var liveMain = main || $('#v3-main');
    var nextMain = next.querySelector('#v3-main');
    if (liveMain && nextMain) {
      var nextSteps = $$('[data-step]', nextMain);
      var wanted = {};
      nextSteps.forEach(function (el) { wanted[el.getAttribute('data-step') || ''] = 1; });
      $$('[data-step]', liveMain).forEach(function (el) {
        var id = el.getAttribute('data-step') || '';
        if (sticky[id] || wanted[id]) { return; }
        el.remove();
      });
      nextSteps.forEach(function (el) {
        var id = el.getAttribute('data-step') || '';
        if (sticky[id]) { return; }
        var live = document.getElementById(el.id);
        if (live && live !== el) { live.remove(); }
      });
      var prev = document.getElementById('steg-bekrafta');
      nextSteps.forEach(function (el) {
        var id = el.getAttribute('data-step') || '';
        if (sticky[id]) {
          var live = document.getElementById('steg-' + id);
          if (live) { prev = live; }
          return;
        }
        if (prev && prev.parentNode) {
          prev.parentNode.insertBefore(el, prev.nextSibling);
        }
        prev = el;
      });
    }
    if (bekForm) {
      var oldKalkyl = bekForm.querySelector('.v3-kalkyl, .v3-sigen');
      var newKalkyl = next.querySelector('#v3-f-bekrafta .v3-kalkyl, #v3-f-bekrafta .v3-sigen');
      if (oldKalkyl && newKalkyl) { oldKalkyl.replaceWith(newKalkyl); }
      else if (oldKalkyl && !newKalkyl) { oldKalkyl.remove(); }
      else if (!oldKalkyl && newKalkyl) {
        var afterKind = bekForm.querySelector('[data-v3-kind-req]') || bekForm.querySelector('[data-v3-kind]');
        if (afterKind && afterKind.parentNode) {
          afterKind.parentNode.insertBefore(newKalkyl, afterKind.nextSibling);
        }
      }
    }
    [['.v3-badge', next.querySelector('.v3-badge')], ['.v3-prog', next.querySelector('.v3-prog')]].forEach(function (pair) {
      var oldEl = $(pair[0]);
      if (oldEl && pair[1]) { oldEl.replaceWith(pair[1]); }
    });
    var oldNext = $('.v3-nextwrap, .v3-closedtag');
    var newNext = next.querySelector('.v3-nextwrap, .v3-closedtag');
    if (oldNext && newNext) { oldNext.replaceWith(newNext); }
    root.setAttribute('data-kind', next.getAttribute('data-kind') || kind || '');
    var nextAttr = next.getAttribute('data-next');
    if (nextAttr) { root.setAttribute('data-next', nextAttr); }
    foranForm = $('#steg-foranmalan.is-todo #v3-f-foranmalan');
    ink = $('#v3-f-inkop');
    if (ink) { inkLast = inkSnap(); mRows(); paintInkSum(); }
    rememberStatus(foranForm, 'foranmalan_status');
    rememberStatus($('#v3-f-bestall'), 'bestallning_status');
    if (foranForm) { foranForm._jeabNat = radioVal(foranForm, 'natbolag'); }
    paintForan();
    paintBestall();
    if ($('.v3-sigen')) { sigenPaint(); }
    if ($('.v3-kalkyl')) { paintKalk(); }
    rebindShell();
    sizeSpacer();
    spy();
  }
  function applyKindSteps(kind, msg) {
    var url = root.getAttribute('data-self') || window.location.pathname + window.location.search;
    url = url.replace(/#.*$/, '');
    fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'text/html' }
    }).then(function (res) {
      return res.text().then(function (txt) { return { ok: res.ok, txt: txt }; });
    }).then(function (pack) {
      var doc = new DOMParser().parseFromString(pack.txt || '', 'text/html');
      var next = doc.querySelector('[data-v3-root]');
      if (!next || !root.parentNode) { reloadTo('bekrafta', msg); return; }
      applyKindDom(next, kind);
      busy = false;
      var seg = bekForm ? $('[data-v3-kind]', bekForm) : null;
      if (seg) { seg.classList.remove('is-busy'); }
      toast(msg);
    }).catch(function () { reloadTo('bekrafta', msg); });
  }
  function showErr(step, msg, anchor) {
    var box = $('[data-v3-err="' + step + '"]');
    var text = failText(msg);
    if (box) {
      box.textContent = text;
      box.hidden = false;
      var r = box.getBoundingClientRect();
      if (r.top < stickH() + 8 || r.bottom > window.innerHeight - (bar && !bar.hidden ? bar.offsetHeight : 0) - 8) {
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    } else {
      toast(text);
    }
    updateBar();
  }
  function clearErr(step) {
    var box = $('[data-v3-err="' + step + '"]');
    if (box) { box.hidden = true; box.textContent = ''; }
  }
  function setWorking(btn, on) {
    if (!btn) { return; }
    if (on) {
      btn.setAttribute('data-busy', '1');
      btn.setAttribute('data-label', btn.innerHTML);
      btn.classList.add('is-working');
      btn.disabled = true;
      btn.setAttribute('aria-busy', 'true');
      btn.innerHTML = SPIN + ' ';
      btn.appendChild(document.createTextNode(btn.getAttribute('data-working') || 'Sparar…'));
    } else {
      if (btn.hasAttribute('data-label')) { btn.innerHTML = btn.getAttribute('data-label'); }
      btn.classList.remove('is-working');
      btn.disabled = false;
      btn.removeAttribute('aria-busy');
      btn.removeAttribute('data-busy');
    }
  }
  function escText(value) {
    return String(value || '').replace(/[&<>"]/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch];
    });
  }
  function openFakturaConfirm() {
    var form = $('#v3-f-faktura');
    var panel = $('[data-v3-faktura-confirm]');
    if (!form || !panel) { return; }
    var payEl = $('#v3-faktura-att-betala');
    var pay = payEl ? String(payEl.value || '').trim() : '';
    var paySlot = $('[data-v3-faktura-pay]', panel);
    if (paySlot) {
      paySlot.textContent = pay === '' ? '–' : (pay.indexOf('kr') === -1 ? pay + ' kr' : pay);
    }
    var host = $('[data-v3-faktura-lines]', panel);
    if (host) {
      var desc = form.querySelectorAll('[name="fx_desc[]"]');
      var article = form.querySelectorAll('[name="fx_article[]"]');
      var qty = form.querySelectorAll('[name="fx_qty[]"]');
      var price = form.querySelectorAll('[name="fx_price[]"]');
      var html = '';
      for (var i = 0; i < desc.length; i++) {
        var name = String(desc[i].value || '').trim();
        var art = article[i] ? String(article[i].value || '').trim() : '';
        var q = qty[i] ? String(qty[i].value || '').trim() : '';
        var p = price[i] ? String(price[i].value || '').trim() : '';
        if (name === '' && art === '' && p === '') { continue; }
        html += '<div class="v3-li"><span class="v3-mn">' + escText(name || art) + '<small>' + escText(q || '1') + ' × ' + escText(p) + '</small></span></div>';
      }
      if (html !== '') { host.innerHTML = '<div class="v3-mlist">' + html + '</div>'; }
    }
    panel.hidden = false;
    var back = $('[data-v3-faktura-back]', panel);
    if (back) { back.focus(); }
    updateBar();
  }
  function lockCard(card, on, except) {
    if (!card) { return; }
    $$('button, input, select, textarea', card).forEach(function (el) {
      if (el === except || el.type === 'hidden') { return; }
      if (on) {
        if (!el.disabled) { el.setAttribute('data-v3-locked', ''); el.disabled = true; }
      } else if (el.hasAttribute('data-v3-locked')) {
        el.removeAttribute('data-v3-locked');
        el.disabled = false;
      }
    });
  }
  var SUCCESS = {
    bekrafta: 'Ordern skapad och tiden bokad.',
    foranmalan: 'Föranmälan klar.',
    boka: 'Bokad.',
    bestall: 'Beställd.',
    faktura: 'Fakturan skapad.',
    stang: 'Ärendet stängt – ligger under Allt klart.'
  };

  var statusAbort = null;
  function checkedRadio(form, name) {
    var el = form ? form.querySelector('input[name="' + name + '"]:checked') : null;
    return el ? String(el.value || '') : '';
  }
  function rememberStatus(form, name) {
    if (form) { form._jeabStatus = checkedRadio(form, name); }
  }
  function persistStatus(form, name, actionName) {
    if (!form || busy) { return; }
    var value = checkedRadio(form, name);
    if (!value || value === form._jeabStatus) {
      if (name === 'foranmalan_status') { paintForan(); } else { paintBestall(); }
      return;
    }
    var prev = form._jeabStatus || '';
    if (name === 'foranmalan_status') { paintForan(); } else { paintBestall(); }
    if (statusAbort) { statusAbort.abort(); }
    statusAbort = new AbortController();
    var signal = statusAbort.signal;
    post(form, { action: actionName, intent: '' }, null, signal).then(function () {
      if (signal.aborted) { return; }
      form._jeabStatus = value;
    }).catch(function (err) {
      if (!err || err.name === 'AbortError' || signal.aborted) { return; }
      if (form._jeabStatus === value) { return; }
      var back = prev ? form.querySelector('input[name="' + name + '"][value="' + prev + '"]') : null;
      if (back) { back.checked = true; }
      if (name === 'foranmalan_status') { paintForan(); } else { paintBestall(); }
      toast('Kunde inte spara.');
    });
  }

  var natAbort = null;
  function persistNat(form) {
    if (!form || busy) { return; }
    var value = radioVal(form, 'natbolag');
    if (!value || value === form._jeabNat) {
      paintForan();
      return;
    }
    var prev = form._jeabNat || '';
    paintForan();
    if (natAbort) { natAbort.abort(); }
    natAbort = new AbortController();
    var signal = natAbort.signal;
    var extra = { action: 'ordrar.objekt', intent: '', natbolag: value };
    var body = new FormData();
    var csrfEl = form.querySelector('input[name="_csrf"]');
    var idEl = form.querySelector('input[name="id"]');
    if (csrfEl) { body.set('_csrf', csrfEl.value); }
    if (idEl) { body.set('id', idEl.value); }
    body.set('action', extra.action);
    body.set('natbolag', value);
    body.set('v3', '1');
    post(form, extra, body, signal).then(function () {
      if (signal.aborted) { return; }
      form._jeabNat = value;
    }).catch(function (err) {
      if (!err || err.name === 'AbortError' || signal.aborted) { return; }
      if (form._jeabNat === value) { return; }
      var back = prev ? form.querySelector('input[name="natbolag"][value="' + prev + '"]') : null;
      if (back) { back.checked = true; }
      paintForan();
      toast('Kunde inte spara.');
    });
  }

  function submitHeldStep(form, submitter) {
    // Spara under nedräkningen sätter busy. Vänta ut det så fakturan inte tappas.
    var tries = 0;
    var go = function () {
      if (busy && tries < 150) {
        tries += 1;
        setTimeout(go, 200);
        return;
      }
      submitStep(form, submitter);
    };
    go();
  }
  function submitStep(form, submitter) {
    if (busy) { return; }
    if (submitter && submitter.getAttribute('data-busy') === '1') { return; }
    var hot = submitter && (submitter.hasAttribute('data-v3-primary') || submitter.getAttribute('data-v3-utan-bokning') === '1' || submitter.getAttribute('data-v3-faktura-go') === '1');
    if (hot) { setWorking(submitter, true); }
    var releaseHot = function () {
      if (hot) { setWorking(submitter, false); }
    };
    var step = form.getAttribute('data-v3-form') || '';
    if (step === 'stang') {
      busy = true;
      var section = document.getElementById('steg-stang');
      var nav = document.querySelector('#v3-menu [data-nav="stang"]');
      var badge = section ? section.querySelector('.v3-state') : null;
      var sub = section ? section.querySelector('.v3-sub') : null;
      var small = nav ? nav.querySelector('small') : null;
      var snap = {
        state: section ? section.getAttribute('data-state') : '',
        className: section ? section.className : '',
        badge: badge ? badge.textContent : '',
        badgeClass: badge ? badge.className : '',
        sub: sub ? sub.textContent : '',
        navClass: nav ? nav.className : '',
        small: small ? small.textContent : ''
      };
      if (section) {
        section.classList.remove('is-todo');
        section.classList.add('is-done');
        section.setAttribute('data-state', 'klar');
      }
      if (badge) { badge.textContent = 'Klar'; badge.classList.add('is-klar'); }
      if (sub) { sub.textContent = 'Ärendet stängt · ligger under Allt klart'; }
      if (nav) { nav.classList.remove('is-todo'); nav.classList.add('is-klar'); }
      if (small) { small.textContent = 'Stängt'; }
      var extra = {};
      if (submitter && submitter.name) { extra[submitter.name] = submitter.value; }
      post(form, extra).then(function (data) {
        var flash = String((data && data.flash) || '').trim() || SUCCESS.stang;
        var restoreSnap = function () {
          if (section) {
            section.className = snap.className;
            section.setAttribute('data-state', snap.state || 'todo');
          }
          if (badge) { badge.textContent = snap.badge; badge.className = snap.badgeClass; }
          if (sub) { sub.textContent = snap.sub; }
          if (nav) { nav.className = snap.navClass; }
          if (small) { small.textContent = snap.small; }
        };
        toastUndo(flash.replace(/\.$/, ''), function () {
          restoreSnap();
          busy = false;
          releaseHot();
          post(form, { done: '0', v3: '1' }).then(function () {
            toast('Ångrad.');
          }).catch(function () {
            toast('Kunde inte ångra.');
          });
        }, function () {
          reloadTo('', flash);
        });
      }).catch(function () {
        busy = false;
        releaseHot();
        if (section) {
          section.className = snap.className;
          section.setAttribute('data-state', snap.state || 'todo');
        }
        if (badge) { badge.textContent = snap.badge; badge.className = snap.badgeClass; }
        if (sub) { sub.textContent = snap.sub; }
        if (nav) { nav.className = snap.navClass; }
        if (small) { small.textContent = snap.small; }
        toast('Kunde inte spara.');
      });
      return;
    }
    var card = form.closest('section.v3-card') || document.getElementById('steg-' + step);
    var isPrimary = submitter && submitter.hasAttribute('data-v3-primary');
    var extra = {};
    if (submitter && submitter.name) { extra[submitter.name] = submitter.value; }
    var utanBokning = submitter && submitter.getAttribute('data-v3-utan-bokning') === '1';
    if (step === 'bekrafta') {
      var h = huvudCard();
      if (h) { writeCanon(form, h); }
      if (isPrimary) {
        extra.action = (submitter && submitter.name === 'action' && submitter.value) ? submitter.value : 'ordrar.slutfor';
      }
      if (!readKind()) { refreshReason('bekrafta'); showErr('bekrafta', 'Välj produkt.'); releaseHot(); return; }
      if (isPrimary || utanBokning) {
        var v = validate('bekrafta');
        if (v) { refreshReason('bekrafta'); showErr('bekrafta', v); releaseHot(); return; }
      }
    }
    if (step === 'objekt' && !validateObj()) {
      showErr('objekt', 'Rätta de markerade fälten först.');
      releaseHot();
      return;
    }
    if ((step === 'faktura' && form.id === 'v3-f-faktura') || ((step === 'foranmalan' || step === 'bestall') && isPrimary && form.id !== 'v3-f-resend')) {
      var sv = validate(step);
      if (sv) { refreshReason(step); showErr(step, sv); releaseHot(); return; }
    }
    busy = true;
    if (statusAbort) { statusAbort.abort(); statusAbort = null; }
    if (natAbort) { natAbort.abort(); natAbort = null; }
    clearErr(step);
    var body = formBody(form, extra);
    if (step === 'bestall' && ink) {
      // Inköpslistan följer med Beställ: servern sparar den först om den har antal.
      new FormData(ink).forEach(function (v, k) { if (k.indexOf('line_') === 0) { body.append(k, v); } });
    }
    var btn = submitter || form.querySelector('[type="submit"]');
    if (!hot) { setWorking(btn, true); }
    lockCard(card, true, btn);
    updateBar();
    // Beställ läser den sparade inköpslistan: spara pågående ändringar först.
    var before = step === 'bestall' && ink ? inkSave() : Promise.resolve();
    before.then(function () { return post(form, extra, body); }).then(function (data) {
      // Spinnern står kvar tills sidan laddats om med nytt läge (aldrig grön → grå → grön).
      var flash = String((data && data.flash) || '').trim();
      var heldSend = step === 'faktura-void' || form.id === 'v3-f-resend';
      if (isPrimary || utanBokning || heldSend) {
        var land = (step === 'foranmalan' || step === 'faktura' || step === 'bekrafta') ? step : '';
        if (step === 'faktura-void') { land = 'faktura'; }
        if (form.id === 'v3-f-resend') { land = 'foranmalan'; }
        reloadTo(land, flash || (utanBokning ? 'Fortnox-order skapad.' : (SUCCESS[step] || 'Klart.')));
      } else {
        var to = step === 'objekt' ? 'status' : (step === 'solkoll' ? 'bestall' : (step === 'payout' ? 'faktura' : (step === 'plomb' || step === 'fullmakt' ? 'foranmalan' : step)));
        reloadTo(to, flash && flash !== 'Sparad.' ? flash : (step === 'objekt' ? 'Objekt sparat.' : (step === 'solkoll' ? 'Solkoll klart.' : 'Sparat.')));
      }
    }).catch(function (err) {
      busy = false;
      lockCard(card, false);
      setWorking(btn, false);
      if (step === 'foranmalan' || step === 'bestall') {
        var statusName = step === 'foranmalan' ? 'foranmalan_status' : 'bestallning_status';
        var statusAction = step === 'foranmalan' ? 'ordrar.foranmalan-status' : 'ordrar.bestall_steg';
        if (checkedRadio(form, statusName) && checkedRadio(form, statusName) !== form._jeabStatus) {
          persistStatus(form, statusName, statusAction);
        }
      }
      var md = $('#v3-modal');
      if (md) { md.hidden = true; }
      showErr(step, err && err.message ? err.message : '');
      refreshReason(step);
      if (step === 'foranmalan') { paintForan(); }
      if (step === 'bestall') { paintBestall(); }
    });
  }

  root.addEventListener('submit', function (e) {
    var form = e.target;
    if (form && form.hasAttribute('data-v3-autosave')) {
      // Enter i inköpslistan: spara direkt i stället för vanlig POST.
      e.preventDefault();
      inkSave();
      return;
    }
    if (!form || !form.hasAttribute('data-v3-form')) { return; }
    e.preventDefault();
    var submitter = e.submitter || null;
    if (submitter && submitter.getAttribute('data-busy') === '1') { return; }
    // Andra klicket under nedräkningen ska inte posta utan flaggan.
    // Spara är utkast och får gå igenom utan faktura_bekrafta.
    if (form.getAttribute('data-v3-held') === '1') {
      var draftSave = submitter && submitter.name === 'intent' && String(submitter.value || '') === 'save';
      if (!draftSave) { return; }
    }
    if (submitter && submitter.getAttribute('data-v3-hold') === '1' && form.getAttribute('data-v3-held') !== '1') {
      var holdLabel = String(submitter.textContent || 'Skickar').replace(/\s+/g, ' ').trim();
      // Skickas direkt. Ingen nedräkning före utskicket.
      if (form.id === 'v3-f-faktura') {
        var flag = form.querySelector('[data-v3-faktura-flag]');
        if (flag) { flag.disabled = false; flag.value = '1'; }
        var box = form.querySelector('[data-v3-faktura-check]');
        if (box) { box.checked = true; }
      }
      submitHeldStep(form, submitter);
      return;
    }
    submitStep(form, submitter);
  }, { signal: signal });
  // Knappar med form="…" utanför formuläret: submitter följer med i submit-eventet.

  /* ---------- produktval (sparas direkt) ---------- */
  var savedKind = readKind();
  function persistKind(radio) {
    if (!bekForm || busy) { return; }
    var kind = readKind();
    if (!kind || kind === savedKind) { return; }
    var seg = $('[data-v3-kind]', bekForm);
    $$('.v3-seg-opt', seg).forEach(function (o) { o.classList.toggle('is-on', o.contains(radio)); });
    busy = true;
    if (seg) { seg.classList.add('is-busy'); }
    clearErr('bekrafta');
    var h = huvudCard();
    if (h) { writeCanon(bekForm, h); }
    paintNavForKind(kind);
    post(bekForm, { action: 'ordrar.spara' }).then(function () {
      savedKind = kind;
      var labels = { laddbox: 'Elbilsladdare', batteri: 'Batteri', sol: 'Solpaneler' };
      applyKindSteps(kind, 'Produkt sparad: ' + (labels[kind] || kind));
    }).catch(function (err) {
      busy = false;
      if (seg) { seg.classList.remove('is-busy'); }
      var back = bekForm.querySelector('input[name="kind"][value="' + savedKind + '"]');
      $$('input[name="kind"]', bekForm).forEach(function (r) { r.checked = r === back; });
      $$('.v3-seg-opt', seg).forEach(function (o) { o.classList.toggle('is-on', !!back && o.contains(back)); });
      paintNavForKind(savedKind);
      showErr('bekrafta', err && err.message ? err.message : '');
      refreshReason('bekrafta');
    });
  }

  /* ---------- huvudsökande (sparas direkt via ordrar.pick) ---------- */
  var picking = false;
  function pick(card) {
    if (!bekForm || picking || !card) { return; }
    var prev = huvudCard();
    if (prev === card) { return; }
    writeCanon(bekForm, card);
    picking = true;
    var sw = $$('.v3-pillsw', bekForm);
    sw.forEach(function (s) { s.classList.add('is-busy'); });
    post(bekForm, { action: 'ordrar.pick' }).then(function () {
      var pickedName = readCard(card).name || '';
      var title = $('.v3-kname');
      if (title && pickedName) { title.textContent = pickedName; }
      toast('Sparat: ' + (pickedName || 'ägaren') + ' är huvudsökande');
    }).catch(function (err) {
      if (prev) { writeCanon(bekForm, prev); }
      showErr('bekrafta', err && err.message ? err.message : '');
    }).then(function () {
      picking = false;
      sw.forEach(function (s) { s.classList.remove('is-busy'); });
      refreshReason('bekrafta');
    });
  }

  /* ---------- Objekt: mobil och e-post ---------- */
  function validateObj() {
    var f = $('#v3-f-objekt');
    if (!f) { return true; }
    var m = digits((f.querySelector('[name="phone"]') || {}).value || '');
    var raw = String((f.querySelector('[name="phone"]') || {}).value || '').replace(/[\s-]/g, '');
    var e = String((f.querySelector('[name="email"]') || {}).value || '').trim();
    var mok = !raw || /^(\+467|07|467)\d{8}$/.test(raw) || (m.length >= 9 && m.length <= 12 && /^(46|0)7/.test(m));
    var eok = !e || /^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(e);
    var em = $('[data-v3-ferr="mobil"]');
    var ee = $('[data-v3-ferr="epost"]');
    if (em) { em.hidden = mok; }
    if (ee) { ee.hidden = eok; }
    return mok && eok;
  }
  /* Adress-autosave: samma ordrar.objekt och CSRF som Spara, men fetch utan omladdning. */
  var addrSaving = false;
  function fieldDirty(el) {
    if (!el) { return false; }
    if (el.type === 'checkbox' || el.type === 'radio') { return el.checked !== el.defaultChecked; }
    return String(el.value || '') !== String(el.defaultValue || '');
  }
  function markSaved(el) {
    if (!el) { return; }
    if (el.type === 'checkbox' || el.type === 'radio') { el.defaultChecked = el.checked; return; }
    el.defaultValue = el.value;
  }
  function fillPristine(el, value) {
    if (!el) { return; }
    var next = String(value == null ? '' : value);
    if (next === '' || fieldDirty(el)) { return; }
    el.value = next;
    markSaved(el);
  }
  function formHasUnsaved(form) {
    if (!form) { return false; }
    if (form === ink) { return inkSnap() !== inkLast; }
    return $$('input, select, textarea', form).some(function (el) {
      if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.disabled) { return false; }
      return fieldDirty(el);
    });
  }
  function unsavedOutside(except) {
    return $$('form[data-v3-form], form[data-v3-autosave]').some(function (form) {
      return form !== except && formHasUnsaved(form);
    });
  }
  function applyObjektSave(form, contact) {
    ['objekt_adress', 'objekt_postnummer', 'objekt_postort'].forEach(function (n) {
      markSaved(form.querySelector('[name="' + n + '"]'));
    });
    var help = $('[data-v3-addr-help]');
    if (help) { help.hidden = true; }
    /* N-53: Kontakter är enda källan. Orörda fält fylls bara från kontakten. */
    if (!contact || typeof contact !== 'object') { return; }
    var zipDigits = digits(contact.postnummer || '');
    var zipFmt = zipDigits.length === 5 ? zipDigits.slice(0, 3) + ' ' + zipDigits.slice(3) : String(contact.postnummer || '').trim();
    fillPristine(form.querySelector('[name="objekt_adress"]'), contact.gata || '');
    fillPristine(form.querySelector('[name="objekt_postnummer"]'), zipFmt);
    fillPristine(form.querySelector('[name="objekt_postort"]'), contact.ort || '');
    fillPristine(form.querySelector('[name="objekt_fastighetsbeteckning"]'), contact.fastighetsbeteckning || '');
    var fors = form.querySelector('[name="objekt_fastighetsbeteckning_forslag"]');
    if (fors && String(contact.fastighetsbeteckning || '').trim()) { fors.value = String(contact.fastighetsbeteckning); }
    if (bekForm) {
      fillPristine(bekForm.querySelector('[name="fastighetsbeteckning"]'), contact.fastighetsbeteckning || '');
    }
  }
  function flushUnsavedThenReload(form, step, msg) {
    var after = ink ? inkSave() : Promise.resolve();
    return after.then(function () {
      if (unsavedOutside(form)) {
        toast('Adressen sparad. Spara övriga fält först — omladdning skulle ta bort osparad inmatning.');
        return;
      }
      reloadTo(step, msg);
    }, function (err) {
      showErr('objekt', err && err.message ? err.message : '');
    });
  }
  function lookupAddressSoon() {
    var f = $('#v3-f-objekt');
    if (!f || busy || addrSaving) { return; }
    var zip = f.querySelector('[name="objekt_postnummer"]');
    var street = f.querySelector('[name="objekt_adress"]');
    if (!zip || digits(zip.value).length !== 5) { return; }
    if (!street || !String(street.value || '').trim()) { return; }
    var changed = ['objekt_adress', 'objekt_postnummer', 'objekt_postort'].some(function (n) {
      var el = f.querySelector('[name="' + n + '"]');
      return el && String(el.value || '').trim() !== String(el.defaultValue || '').trim();
    });
    if (!changed || !validateObj()) { return; }
    addrSaving = true;
    clearErr('objekt');
    var savedOk = false;
    post(f, {}).then(function (data) {
      savedOk = true;
      applyObjektSave(f, data && data.contact);
      var row = data && data.row;
      var lmErr = row ? String(row.lm_lookup_error || '').trim() : '';
      if (lmErr) { toast(lmErr); }
      var lookupRan = !!(row && String(row.address_previous || '').trim());
      if (lookupRan || lmErr) {
        return flushUnsavedThenReload(f, 'status', 'Objekt sparat.');
      }
      toast('Adress sparad.');
    }).catch(function (err) {
      showErr('objekt', err && err.message ? err.message : '');
    }).then(function () {
      addrSaving = false;
      if (savedOk && !busy) { lookupAddressSoon(); }
    });
  }

  /* ---------- fakturarader ---------- */
  function fxRow(el) { return el.closest('[data-v3-fxrow]'); }
  function paintFx(row) {
    var q = row.querySelector('[name="fx_qty[]"]');
    var p = row.querySelector('[name="fx_price[]"]');
    var d = row.querySelector('[name="fx_desc[]"]');
    var qty = q && String(q.value).trim() !== '' ? num(q.value) : (p && String(p.value).trim() !== '' ? 1 : 0);
    var sum = qty * num(p ? p.value : 0);
    $$('[data-v3-fxsum]', row).forEach(function (c) { c.textContent = sum ? kr(sum) : '–'; });
    var n = row.querySelector('[data-v3-fxn]');
    if (n && d) { n.textContent = String(d.value).trim() || 'Tom rad'; n.classList.toggle('is-empty', !String(d.value).trim()); }
    var qq = row.querySelector('[data-v3-fxq]');
    if (qq && q) { qq.textContent = String(q.value).trim() !== '' ? String(q.value).trim() + ' st' : ''; }
  }
  function paintFxPayable() {
    var form = $('#v3-f-faktura');
    var input = $('#v3-faktura-att-betala');
    if (!form || !input) { return; }
    var rates = {};
    try { rates = JSON.parse(form.getAttribute('data-gt-rates') || '{}'); } catch (err) { rates = {}; }
    var people = parseInt(form.getAttribute('data-gt-people') || '1', 10);
    if (!isFinite(people) || people < 1) { people = 1; }
    var capEach = parseInt(form.getAttribute('data-gt-cap') || '50000', 10);
    if (!isFinite(capEach) || capEach < 0) { capEach = 50000; }
    var sum = 0;
    var byType = {};
    $$('[data-v3-fxrow]', form).forEach(function (row) {
      var priceEl = row.querySelector('[name="fx_price[]"]');
      var qtyEl = row.querySelector('[name="fx_qty[]"]');
      var hwEl = row.querySelector('select[name^="fx_hw["]');
      var price = num(priceEl ? priceEl.value : '');
      if (!price) { return; }
      var qtyText = qtyEl ? String(qtyEl.value || '').trim() : '';
      var qty = qtyText !== '' ? num(qtyText) : 1;
      if (!(qty > 0)) { qty = 1; }
      var line = price * qty;
      sum += line;
      var type = hwEl ? String(hwEl.value || '') : '';
      var rate = Number(rates[type]);
      if (rate > 0 && line > 0) { byType[type] = (byType[type] || 0) + line; }
    });
    var deduction = 0;
    Object.keys(byType).forEach(function (type) {
      deduction += Math.floor(byType[type] * Number(rates[type]) + 1e-6);
    });
    var cap = capEach * people;
    if (deduction > cap) { deduction = cap; }
    var pay = Math.round(sum - deduction);
    input.value = pay > 0 ? String(pay).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') : '';
    mirrorAttBetala(input, false);
  }
  function fxOpenState() {
    var rows = $$('[data-v3-fxrow]');
    if (!rows.length) { return; }
    if (!mob()) {
      rows.forEach(function (r) { r.open = true; });
      return;
    }
    var active = null;
    rows.forEach(function (r) {
      if (r.contains(document.activeElement) || r.hasAttribute('data-v3-touched')) { active = r; }
    });
    if (!active) {
      rows.forEach(function (r) { if (!active && r.open) { active = r; } });
    }
    if (!active) { active = rows[0]; }
    rows.forEach(function (r) { r.open = r === active; });
  }

  /* ---------- klick ---------- */
  function closeKebabs(except) {
    $$('.v3-kebab.is-open').forEach(function (k) {
      if (k === except) { return; }
      k.classList.remove('is-open');
      var b = k.querySelector('.v3-kebab-btn');
      if (b) { b.setAttribute('aria-expanded', 'false'); }
    });
  }
  function confirmBox(title, text, ok, danger, fn) {
    var m = $('#v3-modal');
    $('#v3-m-title').textContent = title;
    $('#v3-m-text').textContent = text;
    var b = $('#v3-m-ok');
    b.textContent = ok;
    b.className = 'v3-btn ' + (danger ? 'is-danger' : 'is-sec');
    b.disabled = false;
    b.onclick = function () {
      b.disabled = true;
      b.innerHTML = SPIN + ' ';
      b.appendChild(document.createTextNode(ok));
      if (fn) { fn(); }
    };
    m.hidden = false;
    b.focus();
  }
  function postManual(undo) {
    var f = $('#v3-f-manual-done');
    var box = $('#v3-modal');
    if (box) { box.hidden = true; }
    if (!f) { return; }
    post(f, { undo: undo ? '1' : '0', action: 'ordrar.manual_done' }).then(function (data) {
      var flash = String((data && data.flash) || '').trim();
      reloadTo('', flash || (undo ? 'Klarmarkeringen är ångrad.' : 'Ordern är klarmarkerad.'));
    }).catch(function (err) {
      toast(failText(err && err.message ? err.message : 'Kunde inte spara.'));
    });
  }
  var ACTS = {
    radera: function () {
      var title = (head && head.querySelector('h1') ? head.querySelector('h1').textContent : 'Ärendet').trim();
      confirmBox('Radera ärendet?', title + ' och all historik tas bort.', 'Radera', true, function () {
        var f = $('#v3-f-delete');
        var box = $('#v3-modal');
        if (box) { box.hidden = true; }
        if (!f) { return; }
        if (pendingDelete && !pendingDelete.undone && !pendingDelete.sent && String(pendingDelete.orderId || '') !== String(root.getAttribute('data-id') || '')) {
          commitPendingDelete();
        } else if (pendingDelete && pendingDelete.timer) {
          clearTimeout(pendingDelete.timer);
        }
        pendingDelete = {
          form: f,
          undone: false,
          sent: false,
          timer: 0,
          startedAt: Date.now(),
          listUrl: root.getAttribute('data-list') || f.getAttribute('action') || window.location.href,
          orderId: root.getAttribute('data-id') || '',
          root: root
        };
        window.__jeabV3PendingDelete = pendingDelete;
        root.classList.add('is-deleting');
        // Raderas direkt efter bekräftelsen. Efteråt visas Ångra i ca 6 s.
        toast('Raderar ärendet…', false);
        commitPendingDelete();
      });
    },
    gamla: function () { window.location.assign(root.getAttribute('data-old') || window.location.href); },
    'manual-done': function () {
      confirmBox(
        'Klarmarkera ordern?',
        'Alla steg blir gröna och ordern räknas som klar. Inget mejl, SMS eller Fortnox-anrop skickas. Bokning och påminnelse ändras inte.',
        'Klarmarkera',
        false,
        function () { postManual(false); }
      );
    },
    'manual-undo': function () {
      confirmBox(
        'Ångra klarmarkering?',
        'Stegen visas som de var innan. Inget mejl, SMS eller Fortnox-anrop skickas. Bokning och påminnelse ändras inte.',
        'Ångra',
        false,
        function () { postManual(true); }
      );
    },
    'edit-bekrafta': function () { openEdit('bekrafta'); },
    'edit-foranmalan': function () { openEdit('foranmalan'); },
    'utan-bokning': function () {
      var b = document.querySelector('[data-v3-utan-bokning]');
      if (b && !b.disabled) { b.click(); }
    },
    'resend-foranmalan': function () {
      var b = document.querySelector('[data-v3-resend]');
      if (b && !b.disabled) { b.click(); return; }
      var f = $('#v3-f-resend');
      if (f && typeof f.requestSubmit === 'function') { f.requestSubmit(); }
    }
  };
  function openEdit(step) {
    var box = $('[data-v3-edit="' + step + '"]');
    var sum = $('#steg-' + step + ' [data-v3-summary]');
    if (box) { box.hidden = false; }
    if (sum) { sum.hidden = true; }
    scrollToStep(step);
  }
  root.addEventListener('click', function (e) {
    var t = e.target;
    var ask = t.closest('[data-v3-faktura-ask]');
    if (ask && root.contains(ask) && !ask.disabled) {
      e.preventDefault();
      openFakturaConfirm();
      return;
    }
    var back = t.closest('[data-v3-faktura-back]');
    if (back && root.contains(back)) {
      e.preventDefault();
      var panel = back.closest('[data-v3-faktura-confirm]');
      if (panel) { panel.hidden = true; }
      var again = $('[data-v3-faktura-ask]');
      if (again) { again.focus(); }
      updateBar();
      return;
    }
    var nav = t.closest('[data-nav]');
    if (nav && root.contains(nav)) {
      e.preventDefault();
      if (nav.classList.contains('is-locked')) {
        toast(nav.getAttribute('data-tip') || nav.getAttribute('title') || 'Steget är låst.');
        return;
      }
      scrollToStep(nav.getAttribute('data-nav'), true);
      return;
    }
    if (!t.closest('.v3-kebab')) { closeKebabs(null); }
    if (t.closest('.v3-kalkyl [data-js="offert-step"]')) { setTimeout(paintKalk, 0); }
    if (t.closest('[data-js="sigen-step"]')) {
      e.preventDefault();
      sigenStep(Number(t.closest('[data-js="sigen-step"]').getAttribute('data-delta')) || 0);
      return;
    }
    var mb = t.closest('#v3-mbar-pri, #v3-mbar-sec, #v3-mbar-utan');
    if (mb && curId) {
      var card = document.getElementById(curId);
      var id = curId.replace('steg-', '');
      var src = null;
      if (card && mb.id === 'v3-mbar-pri') {
        var confirmOpen = id === 'faktura' ? card.querySelector('[data-v3-faktura-confirm]') : null;
        var goBtn = (confirmOpen && !confirmOpen.hidden) ? card.querySelector('[data-v3-faktura-go]') : null;
        src = goBtn || card.querySelector('[data-v3-primary="' + id + '"]');
      } else if (card && mb.id === 'v3-mbar-utan') {
        src = card.querySelector('[data-v3-utan-bokning]');
      } else if (card) {
        src = card.querySelector('.v3-actrow [data-v3-sec]');
      }
      if (src && !src.disabled) {
        if (src.getAttribute('data-v3-faktura-go') === '1') {
          var goForm = src.form || $('#v3-f-faktura');
          if (goForm && typeof goForm.requestSubmit === 'function') { goForm.requestSubmit(src); }
          else { src.click(); }
        } else {
          src.click();
        }
      }
      return;
    }
    if (t.closest('[data-js="ordrar-lines-more"]')) {
      setTimeout(function () {
        var rs = $$('.ordrar-line', ink);
        for (var k = 0; k < rs.length; k++) {
          if (!$$('input', rs[k]).some(function (x) { return String(x.value || '').trim() !== ''; })) { rs[k].setAttribute('data-v3-new', ''); break; }
        }
        mRows();
      }, 0);
    }
    var el = t.closest('[data-v3]');
    if (!el || !root.contains(el)) { return; }
    var a = el.getAttribute('data-v3');
    switch (a) {
      case 'kebab': {
        e.preventDefault();
        var k = el.parentElement;
        var open = k.classList.contains('is-open');
        closeKebabs(k);
        k.classList.toggle('is-open', !open);
        el.setAttribute('aria-expanded', open ? 'false' : 'true');
        return;
      }
      case 'act':
        e.preventDefault();
        closeKebabs(null);
        if (ACTS[el.getAttribute('data-act')]) { ACTS[el.getAttribute('data-act')](); }
        return;
      case 'copy': {
        e.preventDefault();
        var row = el.closest('.v3-kv, .v3-fastrow');
        var v = el.getAttribute('data-v') || '';
        if (row) {
          var inp = row.querySelector('input:not([type="hidden"]), [data-field], .v3-fast-val');
          if (inp) { v = (inp.tagName === 'INPUT' ? inp.value : inp.textContent).trim() || v; }
          var hid = row.querySelector('[data-field="pnr"][hidden]');
          if (hid) { v = hid.textContent.trim() || v; }
        }
        if (!v) {
          toast('Inget att kopiera');
          return;
        }
        var copied = function () { toast('Kopierat'); };
        var failed = function () { toast('Kunde inte kopiera'); };
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(v).then(copied, failed);
          } else {
            failed();
          }
        } catch (err) {
          failed();
        }
        return;
      }
      case 'reveal': {
        e.preventDefault();
        var box = el.closest('[data-v3-reveal]');
        if (!box) { return; }
        var input = box.querySelector('input');
        var full = box.querySelector('[data-field="pnr"][hidden], [data-field="pnr"].is-shown');
        var mask = box.querySelector('.v3-mask:not([data-field])');
        if (input) {
          input.hidden = false;
          if (mask) { mask.hidden = true; }
          el.hidden = true;
          input.focus();
          return;
        }
        if (full && mask) {
          var showing = !full.hidden;
          full.hidden = showing;
          full.classList.toggle('is-shown', !showing);
          mask.hidden = !showing;
          el.textContent = showing ? 'Visa' : 'Dölj';
        }
        return;
      }
      case 'huvud':
        e.preventDefault();
        pick(el.closest('.v3-owner'));
        return;
      case 'med': {
        e.preventDefault();
        var c = el.closest('.v3-owner');
        if (!c || c.getAttribute('data-huvud') !== '1') { return; }
        var other = ownerCards().filter(function (x) { return x !== c; })[0];
        if (other) { pick(other); } else { toast('Det måste finnas en huvudsökande'); }
        return;
      }
      case 'close-banner': {
        var bn = el.closest('[data-v3-banner]');
        if (bn) { bn.remove(); }
        return;
      }
      case 'close-edit': {
        e.preventDefault();
        var ed = el.closest('[data-v3-edit]');
        if (ed) { ed.hidden = true; }
        var sc = el.closest('section.v3-card');
        var s = sc ? sc.querySelector('[data-v3-summary]') : null;
        if (s) { s.hidden = false; }
        return;
      }
      case 'status-refresh':
        e.preventDefault();
        el.classList.add('is-spinning');
        var statusForm = $('#v3-f-status');
        if (!statusForm) {
          el.classList.remove('is-spinning');
          toast('Kunde inte läsa status från Bokmalen.');
          return;
        }
        post(statusForm, { action: 'ordrar.foranmalan_status_live' }).then(function (data) {
          reloadTo('foranmalan', String((data && data.flash) || 'Status uppdaterad.'));
        }).catch(function (err) {
          el.classList.remove('is-spinning');
          toast(failText(err && err.message ? err.message : 'Kunde inte läsa status från Bokmalen.'));
        });
        return;
      case 'modal-cancel':
        $('#v3-modal').hidden = true;
        return;
      case 'refresh':
        // Vanlig länk (GET) – visa bara att den arbetar.
        el.classList.add('is-spinning');
        return;
    }
  }, { signal: signal });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeKebabs(null);
      var m = $('#v3-modal');
      if (m && !m.hidden) { m.hidden = true; }
    }
  }, { signal: signal });
  document.addEventListener('click', function (e) {
    if (!root.contains(e.target)) { closeKebabs(null); }
  }, { signal: signal });

  /* ---------- kalkyl (batteri: Sigen) ---------- */
  function sigenRoot() { return $('.v3-sigen'); }
  function sigenCatalog() {
    var box = sigenRoot();
    if (!box) { return {}; }
    try { return JSON.parse(box.getAttribute('data-sigen') || '{}'); } catch (err) { return {}; }
  }
  function sigenType() {
    var box = sigenRoot();
    if (!box) { return ''; }
    var on = box.querySelector('[name="sigen_typ"]:checked');
    return on ? on.value : '';
  }
  function sigenStep(delta) {
    var box = sigenRoot();
    var el = box && box.querySelector('[data-sigen-qty]');
    if (!el) { return; }
    var n = Math.round(num(el.value)) + delta;
    if (n < 0) { n = 0; }
    el.value = String(n);
    sigenPaint();
  }
  function sigenPaint() {
    var box = sigenRoot();
    if (!box) { return; }
    var type = sigenType();
    var cat = sigenCatalog();
    var spec = cat[type] || null;
    var step2 = box.querySelector('[data-sigen-step2]');
    if (step2) { step2.hidden = !spec; }
    box.querySelectorAll('[data-sigen-types] .v3-seg-opt').forEach(function (lab) {
      var input = lab.querySelector('input');
      lab.classList.toggle('is-on', !!(input && input.checked));
    });
    var picked = null;
    box.querySelectorAll('[data-sigen-type]').forEach(function (lab) {
      var show = lab.getAttribute('data-sigen-type') === type;
      lab.hidden = !show;
      var input = lab.querySelector('input');
      if (!input) { return; }
      input.disabled = !show;
      if (!show) { input.checked = false; }
      lab.classList.toggle('is-on', show && input.checked);
      if (show && input.checked) { picked = input.value; }
    });
    if (spec && !picked) {
      var first = box.querySelector('[data-sigen-type="' + type + '"] input');
      if (first) {
        first.checked = true;
        first.disabled = false;
        if (first.parentElement) { first.parentElement.classList.add('is-on'); }
        picked = first.value;
      }
    }
    var qtyEl = box.querySelector('[data-sigen-qty]');
    var qty = qtyEl ? Math.max(0, Math.round(num(qtyEl.value))) : 0;
    var sum = box.querySelector('[data-v3-sigen-sum]');
    var note = box.querySelector('[data-sigen-ingår]');
    if (!spec || !picked || !spec.sizes || !spec.sizes[picked]) {
      if (sum) { sum.textContent = 'Välj typ'; }
      if (note) { note.textContent = 'Välj typ så läggs batteri och bottenplatta till.'; }
      return;
    }
    var size = spec.sizes[picked];
    var mods = qty === 1 ? '1 batterimodul' : qty + ' batterimoduler';
    if (sum) { sum.textContent = spec.label + ' ' + picked + ' kW · ' + mods; }
    if (!note) { return; }
    var bits = [size.title + ' ' + kr(size.purchase)];
    var bat = spec.battery || {};
    var batBit = (bat.title || 'Batteri') + ' × ' + qty;
    batBit += bat.purchase == null ? ' (inköpspris saknas)' : ' ' + kr(bat.purchase);
    bits.push(batBit);
    var plate = spec.plate || {};
    bits.push((plate.title || 'Bottenplatta') + ' ' + kr(plate.purchase || 0));
    if (spec.surcharge > 0) { bits.push('TP2 tillägg material och arbete ' + kr(spec.surcharge)); }
    note.textContent = 'Ingår: ' + bits.join(', ') + '.';
  }

  /* ---------- kalkyl (sol) ---------- */
  // app.js räknar kalkylen och fyller Pris; här följer sammanfattningsraden med.
  function paintKalk() {
    var box = $('.v3-kalkyl');
    var sum = box && $('[data-v3-kalk-sum]', box);
    if (!sum) { return; }
    var val = function (k) { var el = $('[data-in="' + k + '"]', box); return el ? el : null; };
    var n = function (k, d) { var el = val(k); var x = el ? Math.round(num(el.value)) : d; return isFinite(x) ? x : d; };
    var on = function (k) { var el = val(k); return !!(el && el.checked); };
    var pay = $('.ordrar-kalkyl [data-out="i14"]', box);
    sum.textContent = n('b14', 0) + ' paneler · ' + n('b3', 0) + ' batterimoduler · ' + Math.max(1, n('sokande', 1)) + ' sökande'
      + (on('b15') ? ' · 12 kW TP2' : '') + (on('laddare') ? ' · elbilsladdare' : '')
      + ' · Att betala ' + (pay ? pay.textContent : '');
    refreshReason('bekrafta');
  }

  /* ---------- fält ---------- */
  function mirrorAttBetala(t, format) {
    if (!t) { return; }
    if (format) {
      var n = Math.round(num(t.value));
      if (n > 0) { t.value = String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
    }
    var other = t.id === 'v3-pris' ? $('#v3-faktura-att-betala') : $('#v3-pris');
    if (other && other.value !== t.value) { other.value = t.value; }
    var payErr = $('[data-v3-pay-error]');
    if (payErr) { payErr.parentNode.removeChild(payErr); }
    var blocked = $('[data-v3-primary="faktura"]');
    if (blocked) { blocked.disabled = false; }
    var sec = $('[data-v3-sec="faktura"]');
    if (sec) { sec.disabled = false; }
  }
  function onField(e) {
    var t = e.target;
    if (!t || !root.contains(t)) { return; }
    if (t.matches && t.matches('textarea[data-v3-autosave-action]')) { fieldSoon(t); }
    if (t.name === 'kind' && e.type === 'change') { persistKind(t); return; }
    if (bekForm && bekForm.contains(t)) {
      var oc = t.closest('.v3-owner');
      if (oc) {
        var p = readCard(oc);
        oc.setAttribute('data-name', p.name);
        oc.setAttribute('data-pnr', p.pnr);
        oc.setAttribute('data-addr', p.addr);
      }
      if (t.closest('.v3-kalkyl')) { setTimeout(paintKalk, 0); }
      if (t.closest('.v3-sigen')) { setTimeout(sigenPaint, 0); }
      if (t.name === 'pris') {
        mirrorAttBetala(t, e.type === 'change');
      }
      refreshReason('bekrafta');
      return;
    }
    if (t.closest('#v3-f-objekt')) {
      if (t.name === 'objekt_adress' || t.name === 'objekt_postnummer' || t.name === 'objekt_postort') {
        var help = $('[data-v3-addr-help]');
        if (help && t.name === 'objekt_adress') { help.hidden = t.value.trim() === t.defaultValue.trim(); }
        if (e.type === 'change') { lookupAddressSoon(); }
      }
      if (t.name === 'objekt_postnummer' && e.type === 'change') {
        var d = digits(t.value);
        if (d.length === 5) { t.value = d.slice(0, 3) + ' ' + d.slice(3); }
      }
      if (t.name === 'phone' || t.name === 'email') { validateObj(); }
      return;
    }
    if (t.hasAttribute('data-v3-mirror')) { mirrorInput(t); return; }
    if (foranForm && foranForm.contains(t)) {
      if (e.type === 'change' && t.name === 'foranmalan_status') {
        var mode = String(t.value || '');
        if (mode === 'B' || mode === 'C') {
          paintForan();
          return;
        }
        persistStatus(foranForm, 'foranmalan_status', 'ordrar.foranmalan-status');
        return;
      }
      if (e.type === 'change' && t.name === 'natbolag') {
        persistNat(foranForm);
        return;
      }
      paintForan();
      return;
    }
    if (t.closest('#v3-f-bestall')) {
      if (e.type === 'change' && t.name === 'bestallning_status') {
        persistStatus(t.form, 'bestallning_status', 'ordrar.bestall_steg');
        return;
      }
      paintBestall();
      return;
    }
    if (ink && ink.contains(t)) {
      if (t.name && String(t.name).indexOf('line_') === 0) {
        inkSoon();
        refreshReason('bestall');
      }
      if (t.matches('[data-js="ordrar-mall-pick"]') && e.type === 'change') {
        // app.js fyller raderna från mallen; spara och rita om mobilraderna efteråt.
        setTimeout(function () { mRows(); inkSoon(); refreshReason('bestall'); }, 0);
      }
      return;
    }
    if (t.id === 'v3-faktura-att-betala') {
      mirrorAttBetala(t, e.type === 'change');
      var payStale = $('[data-v3-stale]');
      if (payStale) { payStale.hidden = false; }
      refreshReason('faktura');
      return;
    }
    var row = fxRow(t);
    if (row) {
      row.setAttribute('data-v3-touched', '');
      var flag = row.querySelector('[data-v3-fx-touched]');
      if (flag) { flag.value = '1'; }
      paintFx(row);
      if (t.matches && t.matches('select[name^="fx_hw["]')) { paintFxPayable(); }
      var st = $('[data-v3-stale]');
      if (st) { st.hidden = false; }
      refreshReason('faktura');
    }
  }
  root.addEventListener('input', onField, { signal: signal });
  root.addEventListener('change', onField, { signal: signal });
  root.addEventListener('focusout', function (e) {
    var t = e.target;
    if (t && t.matches && t.matches('textarea[data-v3-autosave-action]')) { fieldSave(t); }
    if (!t || (t.name !== 'objekt_adress' && t.name !== 'objekt_postnummer' && t.name !== 'objekt_postort')) { return; }
    lookupAddressSoon();
  }, { signal: signal });
  root.addEventListener('toggle', function (e) {
    var d = e.target;
    if (d && d.tagName === 'DETAILS') { setTimeout(function () { sizeSpacer(); spy(); }, 0); }
  }, { capture: true, signal: signal });

  /* ---------- start ---------- */
  var ticking = false;
  window.addEventListener('scroll', function () {
    if (ticking) { return; }
    ticking = true;
    requestAnimationFrame(function () { spy(); ticking = false; });
  }, { passive: true, signal: signal });
  var rT = 0;
  window.addEventListener('resize', function () {
    clearTimeout(rT);
    rT = setTimeout(function () { layoutVars(); sizeSpacer(); fxOpenState(); spy(); }, 80);
  }, { signal: signal });
  if (MQ.addEventListener) { MQ.addEventListener('change', function () { layoutVars(); fxOpenState(); sizeSpacer(); spy(); }, { signal: signal }); }

  layoutVars();
  fxOpenState();
  ['bekrafta', 'faktura'].forEach(refreshReason);
  // app.js (defer) fyller Pris från kalkylen när den laddats: räkna om skälet efteråt.
  if ($('.v3-sigen')) { sigenPaint(); }
  if ($('.v3-kalkyl')) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { setTimeout(paintKalk, 0); }, { signal: signal });
    } else {
      setTimeout(paintKalk, 0);
    }
    window.addEventListener('load', paintKalk, { signal: signal });
  }
  paintForan();
  paintBestall();
  rememberStatus(foranForm, 'foranmalan_status');
  rememberStatus($('#v3-f-bestall'), 'bestallning_status');
  if (foranForm) { foranForm._jeabNat = radioVal(foranForm, 'natbolag'); }
  if (ink) { inkLast = inkSnap(); mRows(); }
  window.addEventListener('beforeunload', function () {
    if (ink && inkSnap() !== inkLast) { inkSave(); }
  }, { signal: signal });
  validateObj();
  sizeSpacer();
  var hash = String(window.location.hash || '');
  if (!hash) {
    try {
      var stopId = stepFromStop(new URL(window.location.href).searchParams.get('stop'));
      if (stopId) { hash = '#steg-' + stopId; }
    } catch (e) { /* ignore */ }
  }
  try {
    if (sessionStorage.getItem('v3-next') === '1') {
      sessionStorage.removeItem('v3-next');
      if (!hash) { hash = '#steg-' + (root.getAttribute('data-next') || ''); }
    }
  } catch (e) { /* ignore */ }
  function openHashStep() {
    if (!/^#steg-[a-z]+$/.test(hash)) { return; }
    var target = document.getElementById(hash.slice(1));
    if (!target) { return; }
    layoutVars();
    sizeSpacer();
    var y = target.getBoundingClientRect().top + window.scrollY - (mob() ? stickH() + 10 : chromeH() + 18);
    window.scrollTo({ top: Math.max(0, y), behavior: 'auto' });
    target.classList.add('is-flash');
    setTimeout(function () { target.classList.remove('is-flash'); }, 1400);
    $$('.v3-mi', menu).forEach(function (m) { m.classList.toggle('is-active', m.getAttribute('href') === hash); });
    spy();
  }
  if (/^#steg-[a-z]+$/.test(hash) && document.getElementById(hash.slice(1))) {
    requestAnimationFrame(function () { requestAnimationFrame(openHashStep); });
    window.addEventListener('load', openHashStep, { once: true, signal: signal });
  }
  spy();
  var fromStore = false;
  try {
    var msg = sessionStorage.getItem('v3-toast');
    if (msg) { sessionStorage.removeItem('v3-toast'); fromStore = true; toast(msg); }
  } catch (e) { /* ignore */ }
  if (!fromStore) {
    var flashToast = $('[data-v3-toast].is-show');
    if (flashToast) {
      var flashMsg = (flashToast.textContent || '').trim();
      if (flashMsg) { toast(flashMsg); }
      else { setTimeout(function () { flashToast.classList.remove('is-show'); }, 2600); }
    }
  }
  rebindShell();
}
window.jeabOrdrarV3Mount = jeabOrdrarV3Mount;
jeabOrdrarV3Mount();
