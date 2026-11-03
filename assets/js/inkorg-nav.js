/* Tillbaka från ett ärende. Listan finns kvar i DOM eller i cache.
   En avbruten hämtning får inte lämna flyglåset eller aria-busy kvar,
   och historik tillbaka får inte ladda om hela sidan (det avbryter listan). */
(function (root) {
  /* Tom scope är Allas, samma som jeab_inkorg_list_scope. Bara uttryckligt egna är Egna. */
  function jeabInkorgScopeKey(scope) {
    return scope === 'egna' ? 'egna' : 'allas';
  }

  function jeabInkorgListKey(parts) {
    parts = parts || {};
    return [parts.box || '', parts.view || '', parts.typ || '', jeabInkorgScopeKey(parts.scope), parts.q || '', parts.sida || '', parts.ko || '', parts.produkt || ''].join('\n');
  }

  function jeabInkorgCountText(value) {
    if (typeof value === 'number' && isFinite(value)) { return String(Math.trunc(value)); }
    if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) { return String(parseInt(value, 10)); }
    return null;
  }

  function jeabInkorgListQuery(parts) {
    parts = parts || {};
    var q = new URLSearchParams();
    q.set('p', 'inkorg');
    if (parts.box) { q.set('box', parts.box); }
    if (parts.view) { q.set('view', parts.view); }
    q.set('scope', jeabInkorgScopeKey(parts.scope));
    var text = String(parts.q || '').trim();
    if (text) { q.set('q', text); }
    if (parts.sida) { q.set('sida', String(parts.sida)); }
    return q.toString();
  }

  /* Scope-byte är en ny lista. Tillbaka-cachen får inte avslöja Egna
     och hoppa över hämtningen, även om nyckeln råkar träffa. */
  function jeabInkorgScopeClickPlan(currentScope, nextScope) {
    var next = jeabInkorgScopeKey(nextScope);
    var cur = jeabInkorgScopeKey(currentScope);
    return {
      scope: next,
      changed: next !== cur,
      fetch: next !== cur,
      reveal: false,
      counts: next !== cur
    };
  }

  function jeabInkorgLiveQuery(doc, locHref) {
    var cur;
    try { cur = new URL(String(locHref || ''), 'http://local/index.php'); } catch (e) { cur = new URL('http://local/index.php'); }
    var view = '';
    var box = cur.searchParams.get('box') || '';
    var q = cur.searchParams.get('q') || '';
    var scope = cur.searchParams.get('scope') || '';
    if (doc && doc.getElementById) {
      var ix = doc.getElementById('ix');
      if (ix && ix.getAttribute) {
        view = ix.getAttribute('data-ix-view') || '';
        if (!box) { box = ix.getAttribute('data-ix-box') || ''; }
      }
    }
    if (!view) { view = cur.searchParams.get('view') || ''; }
    if (doc && doc.querySelector) {
      var form = doc.querySelector('#ix-list form.ix-lhead');
      if (form && form.querySelector) {
        var vEl = form.querySelector('input[name=view]');
        var bEl = form.querySelector('input[name=box]');
        var qEl = form.querySelector('input[name=q]');
        if (!view && vEl && vEl.value) { view = String(vEl.value); }
        if (!box && bEl && bEl.value) { box = String(bEl.value); }
        if (qEl && String(qEl.value || '').trim() !== '') { q = String(qEl.value || '').trim(); }
        var sEl = form.querySelector('input[name=scope]');
        if (!scope && sEl && sEl.value) { scope = String(sEl.value); }
      }
    }
    return { view: view, box: box, q: q, scope: scope };
  }

  function jeabInkorgScopeHref(base, scope) {
    var params = new URLSearchParams();
    var path = 'index.php';
    if (typeof base === 'string') {
      var raw = base;
      var qat = raw.indexOf('?');
      if (qat >= 0) {
        path = raw.slice(0, qat) || path;
        raw = raw.slice(qat + 1);
      } else if (raw.indexOf('=') < 0) {
        path = raw || path;
        raw = '';
      }
      params = new URLSearchParams(raw);
    } else if (base) {
      if (base.box) { params.set('box', String(base.box)); }
      if (base.view) { params.set('view', String(base.view)); }
      var text = String(base.q || '').trim();
      if (text) { params.set('q', text); }
    }
    params.set('p', 'inkorg');
    params.set('scope', jeabInkorgScopeKey(scope));
    params.delete('id');
    params.delete('ix_open');
    params.delete('ix_part');
    params.delete('sida');
    return path + '?' + params.toString();
  }

  /* Sök-URL. q följer med även vid Enter, och vyn nollställs inte till Inkorg. */
  function jeabInkorgSearchHref(base, parts) {
    parts = parts || {};
    var href = jeabInkorgScopeHref(base, parts.scope);
    var params = new URLSearchParams(href.slice(href.indexOf('?') + 1));
    var path = href.slice(0, href.indexOf('?'));
    if (parts.view) { params.set('view', String(parts.view)); }
    if (parts.box) { params.set('box', String(parts.box)); }
    var text = String(parts.q == null ? (params.get('q') || '') : parts.q).trim();
    if (text) { params.set('q', text); } else { params.delete('q'); }
    params.delete('id');
    params.delete('sida');
    params.delete('ix_part');
    return path + '?' + params.toString();
  }

  function jeabInkorgScopeTarget(node) {
    var el = node;
    if (el && el.nodeType === 3) { el = el.parentNode; }
    if (!el || !el.closest) { return null; }
    return el.closest('a[data-ix-scope]');
  }

  function jeabInkorgBindScopeClicks(handler) {
    if (typeof handler !== 'function') { return false; }
    if (root.__jeabIxScopeClicks) { return true; }
    var doc = root.document;
    if (!doc || !doc.addEventListener) { return false; }
    root.__jeabIxScopeClicks = true;
    doc.addEventListener('click', function (ev) {
      if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) { return; }
      var a = jeabInkorgScopeTarget(ev.target);
      if (!a) { return; }
      var nextScope = jeabInkorgScopeKey(a.getAttribute('data-ix-scope') || '');
      var locHref = root.location && root.location.href ? root.location.href : 'http://local/index.php';
      var live = jeabInkorgLiveQuery(root.document, locHref);
      var anchorHref = a.href || a.getAttribute('href') || locHref;
      var anchor;
      try { anchor = new URL(anchorHref, locHref); } catch (e) { return; }
      if (!live.view) { live.view = anchor.searchParams.get('view') || ''; }
      if (!live.box) { live.box = anchor.searchParams.get('box') || ''; }
      if (!live.q) { live.q = anchor.searchParams.get('q') || ''; }
      var built = jeabInkorgScopeHref(jeabInkorgSearchHref(locHref, live), nextScope);
      var next;
      try { next = new URL(built, locHref); } catch (e2) { return; }
      var plan = jeabInkorgScopeClickPlan(live.scope, nextScope);
      ev.preventDefault();
      if (ev.stopPropagation) { ev.stopPropagation(); }
      if (!plan.fetch) { return; }
      handler({
        scope: plan.scope,
        counts: plan.counts,
        href: next.pathname + '?' + next.searchParams.toString(),
        url: next
      });
    }, true);
    return true;
  }

  function jeabInkorgSearchTarget(node) {
    var el = node;
    if (el && el.nodeType === 3) { el = el.parentNode; }
    if (!el || !el.closest) { return null; }
    return el.closest('#ix-list form.ix-lhead');
  }

  function jeabInkorgBindSearch(handler) {
    if (typeof handler !== 'function') { return false; }
    if (root.__jeabIxSearchBound) { return true; }
    var doc = root.document;
    if (!doc || !doc.addEventListener) { return false; }
    root.__jeabIxSearchBound = true;
    var lastAt = 0;
    function run(form, ev) {
      var now = Date.now();
      if (now - lastAt < 50) {
        if (ev) {
          ev.preventDefault();
          if (ev.stopPropagation) { ev.stopPropagation(); }
        }
        return;
      }
      lastAt = now;
      if (!form) { return; }
      var locHref = root.location && root.location.href ? root.location.href : 'http://local/index.php';
      var live = jeabInkorgLiveQuery(doc, locHref);
      var qEl = form.querySelector ? form.querySelector('input[name=q]') : null;
      live.q = qEl ? String(qEl.value || '').trim() : live.q;
      var vEl = form.querySelector ? form.querySelector('input[name=view]') : null;
      var bEl = form.querySelector ? form.querySelector('input[name=box]') : null;
      var sEl = form.querySelector ? form.querySelector('input[name=scope]') : null;
      var ix = doc.getElementById ? doc.getElementById('ix') : null;
      var attrView = ix && ix.getAttribute ? (ix.getAttribute('data-ix-view') || '') : '';
      if (attrView) { live.view = attrView; }
      else if (!live.view && vEl && vEl.value) { live.view = String(vEl.value); }
      if (!live.box && bEl && bEl.value) { live.box = String(bEl.value); }
      if (sEl && sEl.value) { live.scope = String(sEl.value); }
      var built = jeabInkorgSearchHref(locHref, live);
      var next;
      try { next = new URL(built, locHref); } catch (e) { return; }
      if (ev) {
        ev.preventDefault();
        if (ev.stopPropagation) { ev.stopPropagation(); }
      }
      handler({
        href: next.pathname + '?' + next.searchParams.toString(),
        url: next,
        q: live.q,
        view: live.view
      });
    }
    doc.addEventListener('submit', function (ev) {
      var form = jeabInkorgSearchTarget(ev.target);
      if (!form) { return; }
      run(form, ev);
    }, true);
    doc.addEventListener('keydown', function (ev) {
      if (!ev || ev.key !== 'Enter' || ev.metaKey || ev.ctrlKey || ev.altKey) { return; }
      var t = ev.target;
      if (t && t.nodeType === 3) { t = t.parentNode; }
      if (!t || !t.closest) { return; }
      var input = t.closest('#ix-list form.ix-lhead input[name=q]');
      if (!input) { return; }
      var form = input.closest ? input.closest('form') : null;
      run(form || jeabInkorgSearchTarget(input), ev);
    }, true);
    return true;
  }

  /* Adressen väljer bolag med ?box, annars ?co. Tom sträng om inget av dem är ett känt bolag. */
  function jeabInkorgAddressBox(search) {
    var box = '';
    var co = '';
    try {
      box = String((search && search.get && search.get('box')) || '');
      co = String((search && search.get && search.get('co')) || '');
    } catch (e) {}
    var pick = box || co;
    return pick === 'malmo' || pick === 'jarfalla' ? pick : '';
  }

  function jeabInkorgCompanyHref(href) {
    var url = new URL(String(href || ''), 'http://local/index.php');
    url.searchParams.set('p', 'inkorg');
    var box = url.searchParams.get('box') || url.searchParams.get('co') || '';
    if (box) {
      url.searchParams.set('box', box);
      url.searchParams.set('co', box);
    }
    url.searchParams.set('view', 'v-inkorg');
    ['id', 'ix_open', 'ix_part', 'sida', 'q', 'typ'].forEach(function (key) { url.searchParams.delete(key); });
    return url.pathname + '?' + url.searchParams.toString();
  }

  /* Bolagsbyte skriver URL:en direkt och börjar i Inkorg.
     Historik hämtas även när adressen redan säger v-historik men listan visar något annat.
     Tillbaka från ett ärende får fortfarande avslöja cachen. */
  function jeabInkorgClickPlan(input) {
    input = input || {};
    if (input.company === true) {
      return { ignore: false, kind: 'page', fetch: false, reveal: false, pushFirst: false, navigate: true };
    }
    var nextView = String(input.nextView || '');
    var shownView = String(input.shownView || '');
    var viewChanged = nextView !== '' && nextView !== shownView;
    var company = input.company === true || input.boxChanged === true;
    if (input.sameUrl === true && !viewChanged && !company) {
      return { ignore: true, kind: '', fetch: false, reveal: false, pushFirst: false };
    }
    var kind = 'list';
    if (company) { kind = 'app'; }
    else if (input.nextId && input.nextId !== input.curId) { kind = 'thread'; }
    else if (!input.nextId && (input.curId || viewChanged || input.scopeChanged || input.qChanged || input.pageChanged || input.koChanged || input.produktChanged)) { kind = 'list'; }
    else if (!viewChanged) {
      return { ignore: true, kind: '', fetch: false, reveal: false, pushFirst: false };
    }
    var reveal = kind === 'list' && !input.nextId && !viewChanged && !company && !input.pageChanged;
    return {
      ignore: false,
      kind: kind,
      fetch: !reveal,
      reveal: reveal,
      pushFirst: kind === 'app' || (kind === 'list' && viewChanged)
    };
  }

  /* Bolagsbyte hämtar listan och räknarna för boxen i adressen, sida vid sida. */
  function jeabInkorgSwitchFetch(plan) {
    var on = !!(plan && plan.ignore !== true && plan.kind === 'app');
    return { list: on, counts: on };
  }

  function jeabInkorgCountCacheKey(box, scope) {
    return String(box || '') + '\n' + jeabInkorgScopeKey(scope);
  }

  /* Cachade tal för just den här boxen. En annan box får inte lånas. */
  function jeabInkorgCachedCounts(cache, box, scope) {
    cache = cache || {};
    var hit = cache[jeabInkorgCountCacheKey(box, scope)];
    if (!hit || String(hit.box || '') !== String(box || '') || !jeabInkorgCountsHaveNumbers(hit.counts)) {
      return null;
    }
    return { box: String(box || ''), counts: hit.counts, unread: typeof hit.unread === 'number' ? hit.unread : -1 };
  }

  function jeabInkorgCountsHaveNumbers(counts) {
    if (!counts || typeof counts !== 'object') { return false; }
    var keys = Object.keys(counts);
    for (var i = 0; i < keys.length; i++) {
      if (jeabInkorgCountText(counts[keys[i]]) !== null) { return true; }
    }
    return false;
  }

  function jeabInkorgShownRange(shown, total) {
    shown = typeof shown === 'number' && isFinite(shown) ? Math.max(0, Math.trunc(shown)) : 0;
    total = typeof total === 'number' && isFinite(total) ? Math.max(0, Math.trunc(total)) : shown;
    if (total < shown) { total = shown; }
    if (total > shown) { return 'visar ' + shown + ' av ' + total; }
    return String(shown);
  }

  function jeabInkorgMorePlan(page, pages) {
    page = Math.max(1, parseInt(page, 10) || 1);
    pages = Math.max(1, parseInt(pages, 10) || 1);
    if (page >= pages) { return { more: false, sida: page }; }
    return { more: true, sida: page + 1 };
  }

  function jeabInkorgResponseLive(askedBox, responseBox, locationBox) {
    var asked = String(askedBox || '');
    var response = String(responseBox || '');
    var location = String(locationBox || '');
    var want = asked || location;
    if (want && response && want !== response) { return false; }
    if (asked && location && asked !== location) { return false; }
    return true;
  }

  /* Rubrik och Help Scout-raden följer bolaget i adressen, inte den förra listan. */
  function jeabInkorgCompanyShell(box) {
    box = String(box || '');
    if (box === 'malmo') {
      return { box: 'malmo', eyebrow: 'Malmö', banner: true };
    }
    if (box === 'jarfalla') {
      return { box: 'jarfalla', eyebrow: 'Järfälla', banner: false };
    }
    return { box: box, eyebrow: '', banner: false };
  }

  function jeabInkorgCountsUrl(href) {
    var url = new URL(String(href || ''), 'http://local/index.php');
    url.searchParams.set('p', 'inkorg');
    url.searchParams.set('ix_part', 'counts');
    url.searchParams.delete('id');
    url.searchParams.delete('ix_open');
    return url.pathname + '?' + url.searchParams.toString();
  }

  /* Bolagsbyte hämtar ix_part=counts direkt. Ett sent svar för förra bolaget målas inte.
     En ny tick för samma bolag, eller en DOM-ruta som ännu visar förra bolaget, får inte slänga svaret. */
  function jeabInkorgCountsDecision(pending, now, payload) {
    pending = pending || {};
    now = now || {};
    payload = payload || {};
    var asked = String(pending.box || '');
    var loc = String(now.locationBox || '');
    var dom = String(now.domBox || '');
    var answered = String(payload.box || '');
    var samePlace = asked !== '' && asked === loc && (answered === '' || answered === asked);
    if ((pending.gen || 0) !== (now.gen || 0) && !samePlace) {
      return { paint: false, hold: false, retry: false, reason: 'old' };
    }
    if (asked && loc && asked !== loc) {
      return { paint: false, hold: false, retry: false, reason: 'location' };
    }
    if (asked && answered && asked !== answered) {
      return { paint: false, hold: false, retry: false, reason: 'answer' };
    }
    if (payload.busy) {
      return { paint: false, hold: false, retry: (pending.left || 0) > 0, reason: 'busy' };
    }
    if (!jeabInkorgCountsHaveNumbers(payload.counts)) {
      return { paint: false, hold: false, retry: !payload.fresh && (pending.left || 0) > 0, reason: 'fresh' };
    }
    if (!payload.fresh) {
      return { paint: true, hold: false, retry: (pending.left || 0) > 0, reason: 'stale' };
    }
    if (asked && dom && asked !== dom && !samePlace) {
      return { paint: false, hold: true, retry: false, reason: 'dom' };
    }
    return { paint: true, hold: false, retry: false, reason: 'ok' };
  }

  function jeabInkorgCompanyCounts(plan) {
    return !!(plan && plan.ignore !== true && plan.kind === 'app');
  }

  function jeabInkorgBackPlan(input) {
    input = input || {};
    var hasId = input.hasId === true;
    var reveal = !hasId && (input.sameFilters === true || input.hasCache === true);
    return {
      reveal: reveal,
      fetch: !hasId && !reveal,
      release: true,
      stopReload: input.inbox !== false,
      clearBusy: true
    };
  }

  function jeabInkorgPaintId(urlId, payloadId) {
    var id = urlId || payloadId || '';
    return id === 'undefined' ? '' : String(id);
  }

  /* Tråden i adressen. id och scope följer med som de står, även scope=alla. */
  function jeabInkorgThreadDiagCode(raw) {
    var code = String(raw || '').toLowerCase().replace(/[^a-z0-9_]/g, '').slice(0, 32);
    if (code === 'timeout' || code === 'busy' || code === 'empty' || code === 'status' || code === 'payload' || code === 'ix_render' || code === 'ix_open') {
      return code;
    }
    return '';
  }

  /* Felrutan när ix_part=thread svarar busy/tomt. Texten och koden ligger i den här filen, som sidan laddar. */
  function jeabInkorgThreadFailHtml(code) {
    code = jeabInkorgThreadDiagCode(code);
    var diag = code ? '<p class="ix-diag" style="color:#6b7280;font-size:12px;margin:4px 0 0">' + code + '</p>' : '';
    return '<div class="ix-cmain"><p class="empty">Ärendet kunde inte öppnas.</p>' + diag + '<p class="ix-empty-hint"><button type="button" class="ix-btn" data-ix-retry>Försök igen</button></p></div>';
  }

  function jeabInkorgThreadFailCode(err) {
    err = err || {};
    var raw = err.diag || (err.timeout ? 'timeout' : (err.busy ? 'busy' : 'payload'));
    return jeabInkorgThreadDiagCode(raw) || 'payload';
  }

  /* Högst två omförsök, eller 10 s från första försöket. Sedan visas felrutan. */
  function jeabInkorgThreadBusyPlan(seen, elapsedMs) {
    seen = seen | 0;
    elapsedMs = elapsedMs | 0;
    var retry = seen < 3 && elapsedMs < 10000;
    return { retry: retry, fail: !retry };
  }

  /* Kortets ix_rev är bara en ledtråd. HTML i svaret ritas även om revisionen skiljer. */
  function jeabInkorgThreadRevDecision(cardRev, payload) {
    payload = payload || {};
    var html = typeof payload.html === 'string' ? payload.html : '';
    var diag = jeabInkorgThreadDiagCode(payload.diag);
    if (payload.busy && !String(html).trim()) {
      return { paint: false, busy: true, reason: 'busy', diag: 'busy' };
    }
    if (payload.ok === false || !String(html).trim()) {
      return { paint: false, busy: false, reason: 'empty', diag: diag || 'empty' };
    }
    return { paint: true, busy: false, reason: String(cardRev || '') !== String(payload.rev || '') ? 'rev' : 'ok', diag: '' };
  }

  /* Förhämtningens read_on_open är alltid falskt. Cachen får inte behålla den,
     annars målar ett klick den olästa raden utan att posta inkorg.read. */
  function jeabInkorgCachePrefetch(payload) {
    var src = payload && typeof payload === 'object' ? payload : {};
    var out = {};
    Object.keys(src).forEach(function (key) {
      if (key !== 'read_on_open') { out[key] = src[key]; }
    });
    out.prefetched = true;
    return out;
  }

  /* Riktig öppning. read_on_open från ett vanligt svar vinner.
     En cachad förhämtning av ett oläst ärende postar inkorg.read utan ny trådhämtning.
     Help Scout-import har mark_read false och förblir oläst. */
  function jeabInkorgOpenReadPlan(payload, cardUnread) {
    var row = payload && typeof payload === 'object' ? payload : {};
    if (row.read_on_open === true) {
      return { send: true, refetch: false };
    }
    if (row.read_on_open === false) {
      return { send: false, refetch: false };
    }
    var unread = cardUnread === true || cardUnread === 1 || cardUnread === '1';
    if (row.prefetched === true && unread && row.mark_read !== false) {
      return { send: true, refetch: false };
    }
    return { send: false, refetch: false };
  }

  function jeabInkorgThreadRequest(href) {
    var url;
    try {
      url = new URL(String(href || ''), 'http://local/index.php');
    } catch (e) {
      return null;
    }
    if ((url.searchParams.get('p') || '') !== 'inkorg') {
      return null;
    }
    var id = url.searchParams.get('id') || '';
    if (!id) {
      return null;
    }
    url.searchParams.set('ix_part', 'thread');
    url.searchParams.set('ix_open', id);
    return {
      id: id,
      scope: url.searchParams.get('scope') || '',
      box: url.searchParams.get('box') || '',
      search: url.searchParams.toString()
    };
  }

  /* Svarsrutan: texten ligger i sessionStorage per tråd-id, utan kunddata i nyckeln. */
  var REPLY_DRAFT_PREFIX = 'jeab-ix-reply:';
  var REPLY_DRAFT_SENT = 'jeab-ix-reply-sent';

  function jeabInkorgReplyDraftId(raw) {
    raw = String(raw == null ? '' : raw);
    if (raw.indexOf('@') !== -1 || raw.indexOf(' ') !== -1) {
      return '';
    }
    return /^[A-Za-z0-9._-]{1,80}$/.test(raw) ? raw : '';
  }

  function jeabInkorgReplyDraftKey(threadId) {
    var id = jeabInkorgReplyDraftId(threadId);
    return id ? REPLY_DRAFT_PREFIX + id : '';
  }

  function jeabInkorgReplyDraftRead(storage, threadId) {
    var key = jeabInkorgReplyDraftKey(threadId);
    if (!key || !storage || !storage.getItem) {
      return '';
    }
    try {
      var value = storage.getItem(key);
      return typeof value === 'string' ? value : '';
    } catch (e) {
      return '';
    }
  }

  function jeabInkorgReplyDraftWrite(storage, threadId, text) {
    var key = jeabInkorgReplyDraftKey(threadId);
    if (!key || !storage) {
      return false;
    }
    try {
      text = String(text == null ? '' : text);
      if (text === '') {
        if (storage.removeItem) {
          storage.removeItem(key);
        }
      } else if (storage.setItem) {
        storage.setItem(key, text);
      } else {
        return false;
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function jeabInkorgReplyDraftClear(storage, threadId) {
    return jeabInkorgReplyDraftWrite(storage, threadId, '');
  }

  function jeabInkorgReplyDraftRestore(current, saved) {
    current = String(current == null ? '' : current);
    saved = String(saved == null ? '' : saved);
    return current === '' ? saved : current;
  }

  function jeabInkorgReplyDraftFlashClears(flash) {
    flash = String(flash == null ? '' : flash);
    return flash.indexOf('Skickat') === 0
      || flash.indexOf('Utkast sparat') === 0
      || flash.indexOf('Anteckning sparad') === 0;
  }

  function jeabInkorgReplyDraftMarkSent(storage, threadId) {
    var id = jeabInkorgReplyDraftId(threadId);
    if (!id || !storage || !storage.setItem) {
      return false;
    }
    try {
      storage.setItem(REPLY_DRAFT_SENT, id);
      return true;
    } catch (e) {
      return false;
    }
  }

  function jeabInkorgReplyDraftApplyFlash(storage, flash) {
    if (!jeabInkorgReplyDraftFlashClears(flash) || !storage) {
      return '';
    }
    var id = '';
    try {
      id = jeabInkorgReplyDraftId(storage.getItem ? storage.getItem(REPLY_DRAFT_SENT) : '');
    } catch (e) {
      return '';
    }
    if (!id) {
      return '';
    }
    jeabInkorgReplyDraftClear(storage, id);
    try {
      if (storage.removeItem) {
        storage.removeItem(REPLY_DRAFT_SENT);
      }
    } catch (e) {}
    return id;
  }

  function jeabInkorgBindReplyDraft(form, storage) {
    if (!form || !form.querySelector) {
      return { bound: false, id: '', restored: '' };
    }
    var threadEl = form.querySelector('input[name=thread]');
    var ta = form.querySelector('textarea[name=body]') || form.querySelector('textarea');
    var id = threadEl ? jeabInkorgReplyDraftId(threadEl.value) : '';
    if (!id || !ta) {
      return { bound: false, id: id, restored: '' };
    }
    var saved = jeabInkorgReplyDraftRead(storage, id);
    var next = jeabInkorgReplyDraftRestore(ta.value, saved);
    if (next !== String(ta.value || '')) {
      ta.value = next;
    }
    if (!ta._jeabReplyDraft) {
      ta._jeabReplyDraft = true;
      ta.addEventListener('input', function () {
        jeabInkorgReplyDraftWrite(storage, id, ta.value);
      });
    }
    if (!form._jeabReplyDraftSent) {
      form._jeabReplyDraftSent = true;
      form.addEventListener('submit', function (ev) {
        if (ev && ev.defaultPrevented) {
          return;
        }
        jeabInkorgReplyDraftMarkSent(storage, id);
      });
    }
    return { bound: true, id: id, restored: next };
  }

  /* Skrivbord ≥1024: behåll listans scroll och sidans scroll. Trådrutan börjar överst. */
  function jeabInkorgDeskWide(width) {
    return typeof width === 'number' && isFinite(width) && width >= 1024;
  }

  function jeabInkorgDeskScrollPlan(width, listTop, pageY) {
    if (!jeabInkorgDeskWide(width)) {
      return { desktop: false, listTop: 0, pageY: 0, convTop: null };
    }
    listTop = typeof listTop === 'number' && isFinite(listTop) ? listTop : 0;
    pageY = typeof pageY === 'number' && isFinite(pageY) ? pageY : 0;
    return {
      desktop: true,
      listTop: listTop < 0 ? 0 : listTop,
      pageY: pageY < 0 ? 0 : pageY,
      convTop: 0
    };
  }

  function jeabInkorgNavCreate() {
    return { epoch: 0, flight: 0, busy: false, open: false };
  }

  function jeabInkorgNavBump(state) {
    state.epoch += 1;
    state.flight = 0;
    state.busy = false;
    return state.epoch;
  }

  function jeabInkorgNavStart(state) {
    if (state.flight >= 1) {
      return false;
    }
    state.flight = 1;
    state.busy = true;
    return state.epoch;
  }

  function jeabInkorgNavFinish(state, epoch) {
    if (epoch !== state.epoch) {
      return false;
    }
    state.flight = 0;
    state.busy = false;
    return true;
  }

  function jeabInkorgNavBack(state, plan) {
    var epoch = state.epoch;
    if (plan && plan.release) {
      epoch = jeabInkorgNavBump(state);
    }
    if (plan && plan.clearBusy) {
      state.busy = false;
    }
    if (plan && plan.reveal) {
      state.open = false;
      state.busy = false;
    }
    return {
      epoch: epoch,
      revealed: !!(plan && plan.reveal),
      fetch: !!(plan && plan.fetch),
      stopReload: !!(plan && plan.stopReload)
    };
  }

  var api = {
    jeabInkorgScopeKey: jeabInkorgScopeKey,
    jeabInkorgListKey: jeabInkorgListKey,
    jeabInkorgCountText: jeabInkorgCountText,
    jeabInkorgListQuery: jeabInkorgListQuery,
    jeabInkorgScopeClickPlan: jeabInkorgScopeClickPlan,
    jeabInkorgScopeHref: jeabInkorgScopeHref,
    jeabInkorgLiveQuery: jeabInkorgLiveQuery,
    jeabInkorgSearchHref: jeabInkorgSearchHref,
    jeabInkorgScopeTarget: jeabInkorgScopeTarget,
    jeabInkorgBindScopeClicks: jeabInkorgBindScopeClicks,
    jeabInkorgBindSearch: jeabInkorgBindSearch,
    jeabInkorgAddressBox: jeabInkorgAddressBox,
    jeabInkorgCompanyHref: jeabInkorgCompanyHref,
    jeabInkorgCompanyShell: jeabInkorgCompanyShell,
    jeabInkorgClickPlan: jeabInkorgClickPlan,
    jeabInkorgResponseLive: jeabInkorgResponseLive,
    jeabInkorgCountsUrl: jeabInkorgCountsUrl,
    jeabInkorgCountsDecision: jeabInkorgCountsDecision,
    jeabInkorgCompanyCounts: jeabInkorgCompanyCounts,
    jeabInkorgSwitchFetch: jeabInkorgSwitchFetch,
    jeabInkorgCountCacheKey: jeabInkorgCountCacheKey,
    jeabInkorgCachedCounts: jeabInkorgCachedCounts,
    jeabInkorgCountsHaveNumbers: jeabInkorgCountsHaveNumbers,
    jeabInkorgShownRange: jeabInkorgShownRange,
    jeabInkorgMorePlan: jeabInkorgMorePlan,
    jeabInkorgBackPlan: jeabInkorgBackPlan,
    jeabInkorgPaintId: jeabInkorgPaintId,
    jeabInkorgThreadDiagCode: jeabInkorgThreadDiagCode,
    jeabInkorgThreadFailHtml: jeabInkorgThreadFailHtml,
    jeabInkorgThreadFailCode: jeabInkorgThreadFailCode,
    jeabInkorgThreadBusyPlan: jeabInkorgThreadBusyPlan,
    jeabInkorgThreadRevDecision: jeabInkorgThreadRevDecision,
    jeabInkorgCachePrefetch: jeabInkorgCachePrefetch,
    jeabInkorgOpenReadPlan: jeabInkorgOpenReadPlan,
    jeabInkorgThreadRequest: jeabInkorgThreadRequest,
    jeabInkorgDeskWide: jeabInkorgDeskWide,
    jeabInkorgDeskScrollPlan: jeabInkorgDeskScrollPlan,
    jeabInkorgNavCreate: jeabInkorgNavCreate,
    jeabInkorgNavBump: jeabInkorgNavBump,
    jeabInkorgNavStart: jeabInkorgNavStart,
    jeabInkorgNavFinish: jeabInkorgNavFinish,
    jeabInkorgNavBack: jeabInkorgNavBack,
    jeabInkorgReplyDraftId: jeabInkorgReplyDraftId,
    jeabInkorgReplyDraftKey: jeabInkorgReplyDraftKey,
    jeabInkorgReplyDraftRead: jeabInkorgReplyDraftRead,
    jeabInkorgReplyDraftWrite: jeabInkorgReplyDraftWrite,
    jeabInkorgReplyDraftClear: jeabInkorgReplyDraftClear,
    jeabInkorgReplyDraftRestore: jeabInkorgReplyDraftRestore,
    jeabInkorgReplyDraftFlashClears: jeabInkorgReplyDraftFlashClears,
    jeabInkorgReplyDraftMarkSent: jeabInkorgReplyDraftMarkSent,
    jeabInkorgReplyDraftApplyFlash: jeabInkorgReplyDraftApplyFlash,
    jeabInkorgBindReplyDraft: jeabInkorgBindReplyDraft
  };
  Object.keys(api).forEach(function (name) {
    root[name] = api[name];
  });
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  }
})(typeof window !== 'undefined' ? window : globalThis);
