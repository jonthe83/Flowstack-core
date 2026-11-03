(function () {
  if (!document.body.classList.contains('page-rot')) {
    return;
  }

  const CHEVRON = '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M5.2 7.4a1 1 0 0 1 1.4 0L10 10.8l3.4-3.4a1 1 0 1 1 1.4 1.4l-4.1 4.1a1 1 0 0 1-1.4 0L5.2 8.8a1 1 0 0 1 0-1.4z"/></svg>';

  function boot() {
    const empty = { action: '', csrf: '', company: 'jarfalla', leverans: '', mallar: [] };
    const el = document.getElementById('inkop-boot');
    if (!el) {
      return empty;
    }
    try {
      const data = JSON.parse(el.textContent || '{}');
      const company = data.company === 'malmo' ? 'malmo' : 'jarfalla';
      return {
        action: String(data.action || ''),
        csrf: String(data.csrf || (document.querySelector('meta[name="csrf"]') || {}).content || ''),
        company,
        leverans: String(data.leverans || ''),
        mallar: Array.isArray(data.mallar) ? data.mallar : [],
      };
    } catch (err) {
      return empty;
    }
  }

  function textOf(el) {
    return (el.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function fortnoxAccount(company) {
    return company === 'malmo' ? 'Malmo' : 'Jarfalla';
  }

  function applyCompany(company, chips) {
    const id = company === 'malmo' ? 'malmo' : 'jarfalla';
    document.body.dataset.jeabCompany = id;
    document.body.dataset.fortnoxAccount = fortnoxAccount(id);
    chips.forEach((chip) => {
      chip.classList.toggle('is-on', chip.getAttribute('data-company') === id);
    });
    const importRow = document.querySelector('.rot-import-row');
    if (!importRow) {
      return;
    }
    importRow.querySelectorAll('button, a').forEach((btn) => {
      const malmo = /malmö|malmo/i.test(textOf(btn));
      btn.classList.toggle('is-on', id === 'malmo' ? malmo : !malmo);
      btn.classList.toggle('is-selected', id === 'malmo' ? malmo : !malmo);
    });
  }

  function createValBar(root, company) {
    let row = root.querySelector(':scope > .inkop-val-row');
    if (!row) {
      row = document.createElement('div');
      row.className = 'inkop-val-row';
      row.innerHTML = `
        <div class="cal-company" role="group" aria-label="Val">
          <button type="button" class="chip" data-company="jarfalla">Järfälla</button>
          <button type="button" class="chip" data-company="malmo">Malmö</button>
        </div>
        <button type="button" class="inkop-nasta">Nästa</button>
        <button type="button" class="inkop-toggle">${CHEVRON}</button>
      `;
      root.insertBefore(row, root.firstChild);
    }
    applyCompany(company, [...row.querySelectorAll('[data-company]')]);
    return row;
  }

  function hideDummyMall(order) {
    const match = (el) => {
      if (el.closest('.inkop-step')) {
        return false;
      }
      const t = textOf(el).toLowerCase();
      return /mall|template|artikelnummer|artikelnamn/.test(t);
    };
    const forms = [...order.querySelectorAll('form')].filter(match);
    const tables = [...order.querySelectorAll('table')].filter((el) => match(el) && !el.closest('form'));
    const last = (forms.length ? forms : tables).pop();
    if (!last) {
      return;
    }
    last.classList.add('inkop-dummy-mall');
    last.hidden = true;
  }

  function wrapOrder(root, valRow) {
    const existing = root.querySelector(':scope > .inkop-order');
    if (existing) {
      return existing;
    }
    const valTop = (function () {
      let node = valRow;
      while (node && node.parentElement !== root) {
        node = node.parentElement;
      }
      return node && root.contains(node) ? node : null;
    })();
    const wrap = document.createElement('div');
    wrap.className = 'inkop-order';
    [...root.children].forEach((child) => {
      if (child === valTop || child.classList.contains('inkop-step') || child.classList.contains('inkop-order')) {
        return;
      }
      wrap.appendChild(child);
    });
    if (!wrap.children.length && valRow && valRow !== root) {
      const moved = [];
      let sib = valRow.nextElementSibling;
      while (sib) {
        const next = sib.nextElementSibling;
        if (!sib.classList.contains('inkop-step') && !sib.classList.contains('inkop-order')) {
          moved.push(sib);
        }
        sib = next;
      }
      moved.forEach((el) => wrap.appendChild(el));
    }
    if (valTop && valTop.parentElement === root && valTop.nextSibling) {
      root.insertBefore(wrap, valTop.nextSibling);
    } else if (valRow && valRow.parentElement) {
      valRow.after(wrap);
    } else {
      root.appendChild(wrap);
    }
    return wrap;
  }

  function stockholmYmd(offsetDays) {
    const parts = new Intl.DateTimeFormat('sv-SE', {
      timeZone: 'Europe/Stockholm',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).formatToParts(new Date());
    const map = {};
    parts.forEach((p) => {
      map[p.type] = p.value;
    });
    const base = new Date(Date.UTC(Number(map.year), Number(map.month) - 1, Number(map.day)));
    base.setUTCDate(base.getUTCDate() + (offsetDays || 0));
    const y = base.getUTCFullYear();
    const m = String(base.getUTCMonth() + 1).padStart(2, '0');
    const d = String(base.getUTCDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function isSnarast(iso) {
    if (!iso) {
      return false;
    }
    return iso === stockholmYmd(0) || iso === stockholmYmd(1) || iso === stockholmYmd(2);
  }

  function formatKr(n) {
    const abs = Math.round(Number(n) || 0);
    return String(abs).replace(/\B(?=(\d{3})+(?!\d))/g, '\u202F');
  }

  function emptyLines() {
    return Array.from({ length: 20 }, () => ({ sku: '', title: '', qty: '' }));
  }

  function readLines(step) {
    return [...step.querySelectorAll('.inkop-line')].map((row) => ({
      sku: (row.querySelector('[name="sku"]') || {}).value || '',
      title: (row.querySelector('[name="title"]') || {}).value || '',
      qty: (row.querySelector('[name="qty"]') || {}).value || '',
    }));
  }

  function fillLines(step, lines) {
    const padded = emptyLines();
    (lines || []).slice(0, 20).forEach((line, i) => {
      padded[i] = {
        sku: line.sku || '',
        title: line.title || '',
        qty: line.qty || line.qty === 0 ? String(line.qty) : '',
      };
    });
    step.querySelectorAll('.inkop-line').forEach((row, i) => {
      const sku = row.querySelector('[name="sku"]');
      const title = row.querySelector('[name="title"]');
      const qty = row.querySelector('[name="qty"]');
      const line = padded[i];
      if (sku) sku.value = line.sku;
      if (title) title.value = line.title;
      if (qty) qty.value = line.qty === 0 || line.qty === '0' ? '0' : (line.qty || '');
    });
  }

  function currentKind(step) {
    const on = step.querySelector('.inkop-kind .chip.is-on');
    return on && on.getAttribute('data-kind') === 'sol' ? 'sol' : 'batteri';
  }

  function setKind(step, kind) {
    step.querySelectorAll('.inkop-kind .chip').forEach((chip) => {
      chip.classList.toggle('is-on', chip.getAttribute('data-kind') === kind);
    });
  }

  function fillMallPick(step, mallar, selectedId) {
    const pick = step.querySelector('.inkop-mall-pick');
    if (!pick) {
      return;
    }
    const current = selectedId || pick.value;
    pick.innerHTML = '';
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = 'Ny mall';
    pick.appendChild(blank);
    mallar.slice(0, 10).forEach((mall) => {
      const opt = document.createElement('option');
      opt.value = mall.id;
      opt.textContent = mall.name;
      pick.appendChild(opt);
    });
    if (current && [...pick.options].some((o) => o.value === current)) {
      pick.value = current;
    }
  }

  function applyMall(step, mall) {
    if (!mall) {
      return;
    }
    setKind(step, mall.kind === 'sol' ? 'sol' : 'batteri');
    const name = step.querySelector('.inkop-name');
    if (name) {
      name.value = mall.name || '';
    }
    fillLines(step, mall.lines || []);
    const pick = step.querySelector('.inkop-mall-pick');
    if (pick) {
      pick.value = mall.id || '';
    }
  }

  function buildStep(state) {
    const step = document.createElement('section');
    step.className = 'inkop-step';
    step.innerHTML = `
      <div class="inkop-card">
        <div class="inkop-card-inner">
          <div class="inkop-kind cal-company" role="group" aria-label="Val">
            <button type="button" class="chip is-on" data-kind="batteri">Batteri</button>
            <button type="button" class="chip" data-kind="sol">Solpaneler</button>
          </div>
          <label class="inkop-mall-row">
            <span class="inkop-mall-lbl">Mall</span>
            <select class="inkop-mall-pick"></select>
          </label>
          <label class="inkop-mall-row">
            <span class="inkop-mall-lbl">Namn</span>
            <input class="inkop-name" type="text" maxlength="80" placeholder="Namn på mall">
          </label>
          <label class="inkop-leverans">
            <span class="inkop-leverans-lbl">Leverans</span>
            <span class="inkop-leverans-box">
              <input class="inkop-leverans-input" type="date">
              <span class="inkop-leverans-word">snarast</span>
            </span>
          </label>
          <div class="inkop-lines"></div>
          <button type="button" class="inkop-save">Spara som mall</button>
          <p class="inkop-status" role="status"></p>
        </div>
      </div>
    `;
    const lines = step.querySelector('.inkop-lines');
    emptyLines().forEach(() => {
      const row = document.createElement('div');
      row.className = 'inkop-line';
      row.innerHTML = `
        <input name="sku" type="text" placeholder="Artikelnummer" autocomplete="off">
        <input name="title" type="text" placeholder="Artikelnamn" autocomplete="off">
        <input name="qty" type="number" inputmode="numeric" min="0" step="1" placeholder="Antal">
      `;
      lines.appendChild(row);
    });
    fillMallPick(step, state.mallar, '');
    const date = step.querySelector('.inkop-leverans-input');
    date.value = state.leverans || stockholmYmd(7);
    paintLeverans(step);
    return step;
  }

  function paintLeverans(step) {
    const date = step.querySelector('.inkop-leverans-input');
    const box = step.querySelector('.inkop-leverans-box');
    if (!date || !box) {
      return;
    }
    box.classList.toggle('is-snarast', isSnarast(date.value));
  }

  function setOpen(root, open, nasta, toggle, order, step) {
    document.body.classList.toggle('inkop-article-on', !open);
    order.classList.toggle('is-collapsed', !open);
    nasta.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Dölj order' : 'Visa order');
    toggle.classList.toggle('is-open', open);
    step.hidden = open;
  }

  async function post(state, action, extra) {
    if (!state.action) {
      return { ok: false, error: 'Ingen åtgärd.' };
    }
    const body = new FormData();
    body.set('_csrf', state.csrf);
    body.set('action', action);
    Object.keys(extra || {}).forEach((key) => {
      body.set(key, extra[key]);
    });
    const res = await fetch(state.action, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body,
    });
    return res.json();
  }

  const state = boot();
  const root = document.querySelector('.rot-app') || document.querySelector('main.main');
  if (!root) {
    return;
  }
  const valRow = createValBar(root, state.company);
  const nasta = valRow.querySelector('.inkop-nasta');
  const toggle = valRow.querySelector('.inkop-toggle');
  if (!nasta || !toggle) {
    return;
  }

  const order = wrapOrder(root, valRow);
  hideDummyMall(order);

  let step = root.querySelector(':scope > .inkop-step');
  if (!step) {
    step = buildStep(state);
    order.after(step);
  }

  setOpen(root, true, nasta, toggle, order, step);

  nasta.addEventListener('click', () => {
    setOpen(root, false, nasta, toggle, order, step);
  });
  toggle.addEventListener('click', () => {
    const open = order.classList.contains('is-collapsed');
    setOpen(root, open, nasta, toggle, order, step);
  });
  valRow.querySelector('.cal-company').addEventListener('click', (ev) => {
    const chip = ev.target.closest('[data-company]');
    if (!chip) {
      return;
    }
    const id = chip.getAttribute('data-company') === 'malmo' ? 'malmo' : 'jarfalla';
    state.company = id;
    applyCompany(id, [...valRow.querySelectorAll('[data-company]')]);
    post(state, 'inkop.company', { company: id });
  });

  step.querySelector('.inkop-kind').addEventListener('click', (ev) => {
    const chip = ev.target.closest('.chip');
    if (!chip) {
      return;
    }
    setKind(step, chip.getAttribute('data-kind') === 'sol' ? 'sol' : 'batteri');
  });

  step.querySelector('.inkop-leverans-input').addEventListener('change', () => paintLeverans(step));
  step.querySelector('.inkop-leverans-input').addEventListener('input', () => paintLeverans(step));

  step.querySelector('.inkop-mall-pick').addEventListener('change', (ev) => {
    const id = ev.target.value;
    const mall = state.mallar.find((row) => row.id === id);
    if (mall) {
      applyMall(step, mall);
    } else {
      const name = step.querySelector('.inkop-name');
      if (name) name.value = '';
      fillLines(step, []);
    }
  });

  step.querySelector('.inkop-save').addEventListener('click', async () => {
    const status = step.querySelector('.inkop-status');
    status.textContent = '';
    try {
      const data = await post(state, 'inkop.save', {
        id: step.querySelector('.inkop-mall-pick').value,
        name: step.querySelector('.inkop-name').value,
        kind: currentKind(step),
        lines: JSON.stringify(readLines(step)),
      });
      if (!data || !data.ok) {
        status.textContent = (data && data.error) || 'Kunde inte spara.';
        return;
      }
      const row = data.row || {};
      state.mallar = Array.isArray(row.mallar) ? row.mallar : state.mallar;
      fillMallPick(step, state.mallar, row.mall ? row.mall.id : '');
      if (row.mall) {
        applyMall(step, row.mall);
      }
      status.textContent = 'Mall sparad.';
    } catch (err) {
      status.textContent = 'Kunde inte spara.';
    }
  });

  window.jeabInkopKr = formatKr;
})();
