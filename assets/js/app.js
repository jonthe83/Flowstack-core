(() => {
  /* Mörkt/ljust läge sitter i layoutens jeab_theme_boot_html(), så att sidor
     utan app.js fortfarande kan byta tema. */
  function formPostUrl(form) {
    const attr = form && typeof form.getAttribute === 'function' ? form.getAttribute('action') : '';
    if (attr) {
      return attr;
    }
    return location.href;
  }

  let dueCatcher = null;

  function removeDueCatcher() {
    if (!dueCatcher) {
      return;
    }
    dueCatcher.remove();
    dueCatcher = null;
  }

  function installDueCatcher(form) {
    removeDueCatcher();
    const catcher = document.createElement('div');
    catcher.className = 'due-catcher';
    catcher.setAttribute('data-js', 'due-catcher');
    catcher.setAttribute('aria-hidden', 'true');
    const dismiss = (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (typeof form._jeabRevertDue === 'function') {
        form._jeabRevertDue();
      }
      removeDueCatcher();
    };
    catcher.addEventListener('pointerdown', dismiss, true);
    catcher.addEventListener('touchstart', dismiss, { capture: true, passive: false });
    document.body.appendChild(catcher);
    dueCatcher = catcher;
  }

  function bindDueForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    const input = form.querySelector('input[name="due_at"]');
    if (!input) {
      return;
    }
    let snapshot = input.value;
    let ignoreChange = false;
    let picking = false;

    function closeDuePicker() {
      try {
        if (typeof input.hidePicker === 'function') {
          input.hidePicker();
        }
      } catch {
        // Native picker close is best-effort.
      }
      try {
        input.blur();
      } catch {
        // ignore
      }
    }
    function revertDue() {
      ignoreChange = true;
      picking = false;
      form._jeabPicking = false;
      input.value = snapshot;
      closeDuePicker();
      removeDueCatcher();
      window.clearTimeout(form._jeabIgnoreTimer);
      form._jeabIgnoreTimer = window.setTimeout(() => {
        ignoreChange = false;
      }, 400);
    }
    form._jeabRevertDue = revertDue;
    form._jeabCommitDue = (value) => {
      ignoreChange = true;
      picking = false;
      form._jeabPicking = false;
      snapshot = value || '';
      input.value = snapshot;
      removeDueCatcher();
      window.clearTimeout(form._jeabIgnoreTimer);
      form._jeabIgnoreTimer = window.setTimeout(() => {
        ignoreChange = false;
      }, 400);
    };
    function duePostValue() {
      const day = String(input.value || '');
      const row = form.closest('.apple-list .item');
      const full = row ? String(row.getAttribute('data-due-at') || '') : '';
      const clock = full.length >= 16 ? full.slice(11, 16) : '';
      if (/^\d{4}-\d{2}-\d{2}$/.test(day) && clock !== '' && clock !== '00:00') {
        return day + ' ' + clock + ':00';
      }
      return day;
    }
    function armDuePost() {
      const posted = duePostValue();
      if (posted === input.value) {
        return;
      }
      input.removeAttribute('name');
      let hidden = form.querySelector('input[data-js="due-full"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.setAttribute('data-js', 'due-full');
        form.appendChild(hidden);
      }
      hidden.name = 'due_at';
      hidden.value = posted;
    }
    form._jeabPicking = false;

    input.addEventListener('cancel', (event) => {
      event.preventDefault();
      revertDue();
    });
    input.addEventListener('change', (event) => {
      if (ignoreChange || !picking) {
        input.value = snapshot;
        event.stopImmediatePropagation();
        return;
      }
      if (input.value === snapshot || input.value === '') {
        input.value = snapshot;
        picking = false;
        form._jeabPicking = false;
        removeDueCatcher();
        return;
      }
      picking = false;
      form._jeabPicking = false;
      snapshot = input.value;
      removeDueCatcher();
      armDuePost();
      form.submit();
    });
    function onDueFocusOut() {
      if (!picking) {
        removeDueCatcher();
        return;
      }
      if (input.value === snapshot || input.value === '') {
        revertDue();
        return;
      }
      picking = false;
      form._jeabPicking = false;
      removeDueCatcher();
    }
    input.addEventListener('blur', onDueFocusOut);
    input.addEventListener('focusout', onDueFocusOut);
    form.addEventListener('submit', (event) => {
      if (ignoreChange) {
        event.preventDefault();
        input.value = snapshot;
      }
    });
    const clock = form.querySelector('.due-clock');
    if (!clock) {
      return;
    }
    clock.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      snapshot = input.value;
      picking = true;
      form._jeabPicking = true;
      try {
        input.focus({ preventScroll: true });
      } catch {
        input.focus();
      }
      installDueCatcher(form);
      if (typeof input.showPicker === 'function') {
        try {
          input.showPicker();
        } catch {
          // Native picker open is best-effort after focus.
        }
      }
    });
  }
  document.querySelectorAll('[data-js="due"]').forEach(bindDueForm);

  document.addEventListener('pointerdown', (event) => {
    const target = event.target;
    document.querySelectorAll('[data-js="due"]').forEach((form) => {
      if (!form._jeabPicking || typeof form._jeabRevertDue !== 'function') {
        return;
      }
      if (form.contains(target)) {
        return;
      }
      form._jeabRevertDue();
      const dueInput = form.querySelector('.due-input');
      if (dueInput) {
        try {
          if (typeof dueInput.hidePicker === 'function') {
            dueInput.hidePicker();
          }
        } catch {
          // Native picker close is best-effort.
        }
        try {
          dueInput.blur();
        } catch {
          // ignore
        }
      }
    });
  }, true);

  function growNote(el) {
    if (!el) {
      return;
    }
    if (getComputedStyle(el).display === 'none') {
      el.style.height = 'auto';
      return;
    }
    // Växer med texten: border räknas in så fältet aldrig scrollar inuti.
    el.style.overflowY = 'hidden';
    el.style.height = 'auto';
    const edge = el.offsetHeight - el.clientHeight;
    el.style.height = `${el.scrollHeight + Math.max(0, edge)}px`;
  }

  window.addEventListener('resize', () => {
    document.querySelectorAll('.item.is-open [data-js="grow-note"], dialog[open] [data-js="grow-note"]').forEach((el) => growNote(el));
  });

  function countContactLink(link) {
    const href = String(link.getAttribute('href') || '');
    const field = href.startsWith('mailto:') ? 'mailat' : (href.startsWith('tel:') ? 'ringt' : '');
    if (!field) {
      return;
    }
    const row = link.closest('.apple-list .item');
    if (!row) {
      return;
    }
    const input = row.querySelector('[data-js="tap"] input[name="field"][value="' + field + '"]');
    const form = input ? input.form : null;
    if (!form) {
      return;
    }
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
  }

  document.querySelectorAll('[data-js="tel"], [data-js="mail"]').forEach((link) => {
    if (link._jeabBound) {
      return;
    }
    link._jeabBound = true;
    link.addEventListener('click', (event) => {
      event.stopPropagation();
      countContactLink(link);
    });
  });

  const REMINDER_PHONE_ERROR = 'Ange ett svenskt mobilnummer (07… eller +467…).';

  function normalizeReminderPhone(raw) {
    const trimmed = String(raw || '').trim();
    if (trimmed === '') {
      return '';
    }
    let stripped = trimmed.replace(/[\s.\-()]+/g, '');
    if (stripped === '') {
      return null;
    }
    if (stripped.charAt(0) === '0') {
      stripped = '+46' + stripped.slice(1);
    } else if (stripped.indexOf('467') === 0) {
      stripped = '+' + stripped;
    }
    if (stripped.indexOf('+460') === 0) {
      stripped = '+46' + stripped.slice(4);
    }
    return /^\+467\d{8}$/.test(stripped) ? stripped : null;
  }

  function setContactError(box, message) {
    if (!box) {
      return;
    }
    if (!message) {
      box.hidden = true;
      box.textContent = '';
      return;
    }
    box.hidden = false;
    box.textContent = message;
  }

  function formatPhoneDisplay(phone) {
    const raw = String(phone || '').trim();
    if (!raw) {
      return '';
    }
    let digits = raw.replace(/\D+/g, '');
    if (digits.indexOf('46') === 0 && digits.length === 11) {
      digits = '0' + digits.slice(2);
    }
    if (digits.length === 10) {
      return digits.slice(0, 3) + ' ' + digits.slice(3, 6) + ' ' + digits.slice(6, 8) + ' ' + digits.slice(8);
    }
    return raw;
  }

  function telHrefFromPhone(phone) {
    const parsed = normalizeReminderPhone(phone);
    if (parsed) {
      return 'tel:' + parsed;
    }
    const shown = String(phone || '').trim();
    return shown ? ('tel:' + shown) : '#';
  }

  function shortEmailDisplay(email) {
    const value = String(email || '').trim();
    if (!value) {
      return '';
    }
    if (value.length <= 20) {
      return value;
    }
    const at = value.lastIndexOf('@');
    if (at === -1) {
      return value.slice(0, 19) + '…';
    }
    const domain = value.slice(at + 1);
    const keep = Math.max(1, 20 - domain.length - 2);
    let local = value.slice(0, at);
    if (local.length > keep) {
      local = local.slice(0, Math.max(1, keep - 1)) + '…';
    }
    return local + '@' + domain;
  }

  function paintContactLine(line, phone, email) {
    if (!line) {
      return;
    }
    const digits = line.querySelector('a.contact-digits');
    const mail = line.querySelector('a.contact-mail');
    const phoneInput = line.querySelector('input[name="phone"]');
    const emailInput = line.querySelector('input[name="email"]');
    const shown = String(phone || '').trim();
    const mailValue = String(email || '').trim();
    if (digits) {
      digits.textContent = formatPhoneDisplay(shown);
      digits.setAttribute('href', shown ? telHrefFromPhone(shown) : '#');
      digits.hidden = shown === '';
    }
    if (mail) {
      mail.textContent = shortEmailDisplay(mailValue);
      mail.setAttribute('href', mailValue ? ('mailto:' + mailValue) : '#');
      mail.hidden = mailValue === '';
    }
    if (phoneInput) {
      phoneInput.value = shown;
    }
    if (emailInput) {
      emailInput.value = mailValue;
    }
  }

  function closeContactEditor(line) {
    if (!line) {
      return;
    }
    const show = line.querySelector('.contact-show');
    const form = line.querySelector('[data-js="contact-form"]');
    if (show) {
      show.hidden = false;
    }
    if (form) {
      form.hidden = true;
    }
    setContactError(line.querySelector('[data-js="contact-error"]'), '');
  }

  function openContactEditor(line) {
    if (!line) {
      return;
    }
    document.querySelectorAll('.apple-list .contact-line').forEach((other) => {
      if (other !== line) {
        closeContactEditor(other);
      }
    });
    const show = line.querySelector('.contact-show');
    const form = line.querySelector('[data-js="contact-form"]');
    if (show) {
      show.hidden = true;
    }
    if (form) {
      form.hidden = false;
      const phoneInput = form.querySelector('input[name="phone"]');
      if (phoneInput) {
        try {
          phoneInput.focus({ preventScroll: true });
        } catch {
          phoneInput.focus();
        }
      }
    }
  }

  function postContactForm(form) {
    return fetch(formPostUrl(form), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (res.status === 419) {
        showReloadNotice();
        throw new Error('csrf');
      }
      return res.json().catch(() => ({})).then((data) => {
        if (!res.ok || data.ok === false) {
          throw new Error((data && data.error) || REMINDER_PHONE_ERROR);
        }
        return data;
      });
    });
  }

  function bindContactEdit(btn) {
    if (!btn || btn._jeabBound) {
      return;
    }
    btn._jeabBound = true;
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const line = btn.closest('.contact-line');
      if (!line) {
        return;
      }
      const form = line.querySelector('[data-js="contact-form"]');
      if (form && !form.hidden) {
        closeContactEditor(line);
        return;
      }
      openContactEditor(line);
    });
    btn.addEventListener('pointerdown', (event) => {
      event.stopPropagation();
    });
  }

  function bindContactForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('pointerdown', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const line = form.closest('.contact-line');
      const err = form.querySelector('[data-js="contact-error"]');
      const phoneInput = form.querySelector('input[name="phone"]');
      const emailInput = form.querySelector('input[name="email"]');
      const parsed = normalizeReminderPhone(phoneInput ? phoneInput.value : '');
      if (parsed === null) {
        setContactError(err, REMINDER_PHONE_ERROR);
        return;
      }
      if (phoneInput && parsed !== '') {
        phoneInput.value = parsed;
      }
      if (emailInput) {
        emailInput.value = String(emailInput.value || '').trim();
      }
      setContactError(err, '');
      flushInlineNear(form).then(() => postContactForm(form)).then((data) => {
        const row = data && data.row ? data.row : {};
        paintContactLine(line, row.phone || parsed, row.email != null ? row.email : (emailInput ? emailInput.value : ''));
        closeContactEditor(line);
      }).catch((error) => {
        if (error && error.message === 'csrf') {
          return;
        }
        setContactError(err, (error && error.message) || REMINDER_PHONE_ERROR);
      });
    });
  }

  document.querySelectorAll('[data-js="contact-edit"]').forEach(bindContactEdit);
  document.querySelectorAll('[data-js="contact-form"]').forEach(bindContactForm);

  let closeRow = document.querySelector('[data-js="close-row"]');
  let closePad = document.querySelector('[data-js="close-pad"]');

  function refreshCloseRefs() {
    closeRow = document.querySelector('[data-js="close-row"]');
    closePad = document.querySelector('[data-js="close-pad"]');
  }

  function currentOpenRow() {
    return document.querySelector('.apple-list .item.is-open');
  }

  function setClosePad() {
    if (!closePad) {
      return;
    }
    closePad.hidden = true;
  }

  function writeOpenParam(id) {
    if (!window.history || typeof history.replaceState !== 'function') {
      return;
    }
    const url = new URL(location.href);
    const next = id ? String(id) : '';
    if (next) {
      url.searchParams.set('open', next);
    } else {
      url.searchParams.delete('open');
    }
    history.replaceState({ jeabReminders: true }, '', url.pathname + url.search);
    reminderHistoryUrl = url.pathname + url.search;
  }

  function reminderOpenUrl(id, href) {
    const url = href ? new URL(href, location.href) : new URL(location.href);
    const next = id ? String(id) : '';
    if (next) {
      url.searchParams.set('open', next);
    } else {
      url.searchParams.delete('open');
    }
    return url.pathname + url.search;
  }

  let reminderHistoryUrl = '';
  let reminderOpenedAt = 0;
  try {
    reminderHistoryUrl = location.pathname + location.search;
  } catch (e) {
    reminderHistoryUrl = '';
  }

  function reminderUrlSameExceptOpen(a, b) {
    try {
      const left = new URL(a, location.origin);
      const right = new URL(b, location.origin);
      if (left.pathname !== right.pathname) {
        return false;
      }
      left.searchParams.delete('open');
      right.searchParams.delete('open');
      const keys = new Set([...left.searchParams.keys(), ...right.searchParams.keys()]);
      for (const key of keys) {
        if (left.searchParams.get(key) !== right.searchParams.get(key)) {
          return false;
        }
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function pushReminderOpen(id, href) {
    const next = reminderOpenUrl(id, href);
    const plan = (typeof jeabReminderOpenPlan === 'function')
      ? jeabReminderOpenPlan(true, !!(window.history && typeof history.pushState === 'function'))
      : (window.history && typeof history.pushState === 'function' ? 'push' : 'reload');
    if (plan !== 'push') {
      location.href = next;
      return false;
    }
    try {
      history.pushState({ jeabReminders: true, jeabOpen: id ? String(id) : '' }, '', next);
      reminderHistoryUrl = next;
      return true;
    } catch (e) {
      location.href = next;
      return false;
    }
  }

  function stripReminderHash() {
    if (location.hash.indexOf('#r-') !== 0) {
      return;
    }
    if (window.history && typeof history.replaceState === 'function') {
      history.replaceState(null, '', location.pathname + location.search);
    }
  }

  function syncShutPreview(row) {
    if (!row) {
      return;
    }
    const form = row.querySelector('[data-js="inline"]');
    const shut = row.querySelector('.item-head-shut');
    if (!form || !shut) {
      return;
    }
    const addrInput = form.querySelector('[name="address"]');
    const nameInput = form.querySelector('input[name="customer"]');
    const noteInput = form.querySelector('textarea[name="notes"]');
    const shutAddr = shut.querySelector('.item-shut-addr');
    if (shutAddr && addrInput) {
      const raw = String(addrInput.value || '').trim();
      const cityEl = shutAddr.querySelector('.item-shut-city');
      const cityText = cityEl ? String(cityEl.textContent || '').replace(/^,\s*/, '').trim() : '';
      let street = raw;
      if (cityText) {
        const suffix = ', ' + cityText;
        if (raw.toLowerCase().endsWith(suffix.toLowerCase())) {
          street = raw.slice(0, raw.length - suffix.length).trim();
        }
      }
      shutAddr.textContent = street;
      if (cityText) {
        const span = document.createElement('span');
        span.className = 'item-shut-city';
        span.textContent = ', ' + cityText;
        shutAddr.appendChild(span);
      }
    }
    const copy = shut.querySelector('.item-shut-copy');
    let shutName = shut.querySelector('.item-shut-name');
    const name = nameInput ? String(nameInput.value || '').trim() : '';
    if (name) {
      if (!shutName && copy) {
        shutName = document.createElement('p');
        shutName.className = 'item-shut-name';
        const addr = copy.querySelector('.item-shut-addr');
        if (addr) {
          copy.insertBefore(shutName, addr.nextSibling);
        } else {
          copy.appendChild(shutName);
        }
      }
      if (shutName) {
        shutName.textContent = name;
      }
    } else if (shutName) {
      shutName.remove();
    }
    const note = noteInput ? String(noteInput.value || '').trim() : '';
    const stackNote = row.querySelector('.item-stack > .item-note');
    const shutNotes = [...shut.querySelectorAll('.item-note')].filter((el) => el.tagName !== 'TEXTAREA');
    const existing = (stackNote ? 1 : 0) + shutNotes.length;
    const plan = (typeof jeabShutNotePlan === 'function')
      ? jeabShutNotePlan(existing, note)
      : (note ? (existing ? 'keep' : 'append') : (existing ? 'clear' : 'none'));
    if (plan === 'clear') {
      if (stackNote) {
        stackNote.remove();
      }
      shutNotes.forEach((el) => el.remove());
      return;
    }
    if (plan === 'append') {
      const main = shut.querySelector('a.item-main');
      if (main && !stackNote && shutNotes.length === 0) {
        const shutNote = document.createElement('span');
        shutNote.className = 'item-note';
        shutNote.textContent = note;
        const hidden = main.querySelector('.visually-hidden');
        if (hidden) {
          main.insertBefore(shutNote, hidden);
        } else {
          main.appendChild(shutNote);
        }
      }
      return;
    }
    const host = stackNote || shutNotes[0];
    if (host && host.textContent.trim() !== note && !host.querySelector('a, br')) {
      host.textContent = note;
    }
    if (stackNote) {
      shutNotes.forEach((el) => el.remove());
    } else {
      shutNotes.slice(1).forEach((el) => el.remove());
    }
  }

  function reminderMobileList() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 899px)').matches
      && document.body
      && document.body.classList.contains('page-reminders')
      && !document.body.classList.contains('page-kontakter'));
  }

  function closeOpenReminder(opts) {
    const row = opts?.row || currentOpenRow();
    if (!row) return;
    if (snoozeHoldActive(row)) {
      return;
    }
    syncShutPreview(row);
    const form = row.querySelector('[data-js="inline"]');
    const pending = form && (form._jeabDirty || form._jeabSavePromise)
      ? saveInlineForm(form)
      : Promise.resolve();
    row.classList.remove('is-open');
    row.querySelectorAll('[data-js="due"]').forEach((form) => {
      if (typeof form._jeabRevertDue === 'function') {
        form._jeabRevertDue();
      }
    });
    row.querySelectorAll('.item-head-open, .item-extra, .item-created').forEach((el) => {
      el.hidden = true;
    });
    row.querySelectorAll('.contact-pen').forEach((el) => {
      el.hidden = true;
    });
    row.querySelectorAll('.contact-line').forEach((line) => closeContactEditor(line));
    const shut = row.querySelector('.item-head-shut');
    if (shut) {
      shut.hidden = false;
    }
    const skipHistory = opts && opts.history === false;
    if (!skipHistory) {
      if (window.history && history.state && history.state.jeabOpen && typeof history.back === 'function') {
        history.back();
      } else {
        const href = closeRow && closeRow.getAttribute('href');
        if (href && window.history && typeof history.replaceState === 'function') {
          history.replaceState({ jeabReminders: true }, '', href.replace(/#r-\d+$/, ''));
        } else {
          writeOpenParam('');
        }
      }
    }
    setClosePad();
    pamDeskUnmount(row);
    pending.then(() => {
      if (typeof window.__jeabSoftSyncReminders === 'function') {
        window.__jeabSoftSyncReminders('collapse');
      }
    }).catch(() => {});
  }

  /* Mobil: ett öppet kort. Nästa kort stänger de andra innan det själv öppnas. */
  function collapseOtherReminderCards(except) {
    if (!reminderMobileList()) {
      return;
    }
    document.querySelectorAll('.apple-list .item.is-open').forEach((other) => {
      if (other !== except) {
        closeOpenReminder({ history: false, row: other });
      }
    });
  }

  function reminderCardUrl(row, href) {
    const url = new URL(href || location.href, location.href);
    url.searchParams.set('p', 'reminders');
    url.searchParams.set('open', row.getAttribute('data-id') || '');
    url.searchParams.set('intent', 'card');
    url.searchParams.delete('n');
    url.searchParams.delete('rst');
    return url;
  }

  function paintReminderCard(row, html) {
    const wrap = document.createElement('template');
    wrap.innerHTML = String(html || '').trim();
    const fresh = wrap.content.querySelector('.apple-list .item, .item');
    if (!fresh || !row.parentNode) {
      return null;
    }
    row.replaceWith(fresh);
    if (typeof bindReminderSubtree === 'function') {
      bindReminderSubtree(fresh);
    }
    return fresh;
  }

  let reminderCardTicket = 0;

  function fetchReminderCard(row, opts) {
    const href = opts && opts.href;
    const quiet = opts && opts.history === false;
    const ticket = ++reminderCardTicket;
    if (!quiet && !pushReminderOpen(row.getAttribute('data-id'), href)) {
      return;
    }
    const url = reminderCardUrl(row, href);
    const stale = () => ticket !== reminderCardTicket || !row.isConnected;
    fetch(url.toString(), {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json, text/html',
      },
    }).then((res) => res.json()).then((data) => {
      if (opts && opts.desk && !pamDeskOn()) {
        document.querySelectorAll('[data-js="pam-board"]').forEach((board) => {
          board._jeabDeskOpening = '';
        });
        return;
      }
      if (stale()) {
        return;
      }
      if (!data || !data.ok || !data.html) {
        throw new Error('card');
      }
      const fresh = paintReminderCard(row, data.html);
      if (!fresh) {
        throw new Error('card');
      }
      openReminderRow(fresh, { history: false, desk: !!(opts && opts.desk) });
    }).catch(() => {
      if (opts && opts.desk && !pamDeskOn()) {
        document.querySelectorAll('[data-js="pam-board"]').forEach((board) => {
          board._jeabDeskOpening = '';
        });
        return;
      }
      if (stale()) {
        return;
      }
      location.href = reminderOpenUrl(row.getAttribute('data-id'), href);
    });
  }

  function pamDeskOn() {
    return !!(window.matchMedia && window.matchMedia('(min-width: 900px)').matches
      && document.body && document.body.classList.contains('page-reminders')
      && !document.body.classList.contains('page-kontakter'));
  }

  function pamPreviewText(row) {
    var note = row.querySelector('.item-stack > .item-note, .pam-desk-pin-preview');
    var text = note ? String(note.textContent || '').replace(/\s+/g, ' ').trim() : '';
    if (!text) {
      var area = row.querySelector('textarea[name="notes"]');
      text = area ? String(area.value || '').replace(/\s+/g, ' ').trim() : '';
    }
    if (!text) {
      var name = row.querySelector('.item-shut-name');
      var addr = row.querySelector('.item-shut-addr');
      var n = name ? String(name.textContent || '').trim() : '';
      var a = addr ? String(addr.textContent || '').trim() : '';
      if (n && n !== a) {
        text = n;
      }
    }
    return text.slice(0, 140);
  }

  function pamBuildPin(row) {
    var id = row.getAttribute('data-id') || '';
    var face = row.querySelector('.pam-ix');
    if (face) {
      var liFace = document.createElement('li');
      liFace.className = 'pam-desk-pin is-selected';
      liFace.setAttribute('data-desk-pin', id);
      var markFace = document.createElement('span');
      markFace.className = 'pam-desk-pin-mark';
      markFace.setAttribute('aria-hidden', 'true');
      liFace.appendChild(markFace);
      var link = document.createElement('a');
      link.className = 'pam-desk-pin-main';
      var mainFace = row.querySelector('a.item-main');
      link.href = mainFace ? (mainFace.getAttribute('href') || '#') : '#';
      link.appendChild(face.cloneNode(true));
      liFace.appendChild(link);
      var phoneFace = row.querySelector('a.item-call');
      if (phoneFace) {
        liFace.appendChild(phoneFace.cloneNode(true));
      }
      return liFace;
    }
    var addrEl = row.querySelector('.item-shut-addr');
    var nameEl = row.querySelector('.item-shut-name');
    var dueEl = row.querySelector('.item-due-chip');
    var addr = addrEl ? String(addrEl.textContent || '').replace(/\s+/g, ' ').trim() : '';
    var name = nameEl ? String(nameEl.textContent || '').replace(/\s+/g, ' ').trim() : '';
    var due = dueEl ? String(dueEl.textContent || '').replace(/\s+/g, ' ').trim() : '';
    var li = document.createElement('li');
    li.className = 'pam-desk-pin is-selected';
    li.setAttribute('data-desk-pin', id);
    var mark = document.createElement('span');
    mark.className = 'pam-desk-pin-mark';
    mark.setAttribute('aria-hidden', 'true');
    li.appendChild(mark);
    var a = document.createElement('a');
    a.className = 'pam-desk-pin-main';
    var main = row.querySelector('a.item-main');
    a.href = main ? (main.getAttribute('href') || '#') : '#';
    var head = document.createElement('span');
    head.className = 'pam-desk-pin-addr';
    head.textContent = addr || name || 'Påminnelse';
    a.appendChild(head);
    var preview = pamPreviewText(row);
    if (preview && preview !== head.textContent) {
      var prev = document.createElement('span');
      prev.className = 'pam-desk-pin-preview';
      prev.textContent = preview;
      a.appendChild(prev);
    }
    li.appendChild(a);
    if (due) {
      var dueSpan = document.createElement('span');
      dueSpan.className = 'pam-desk-pin-due';
      dueSpan.textContent = due;
      li.appendChild(dueSpan);
    }
    var phone = row.querySelector('a.item-call');
    if (phone) {
      li.appendChild(phone.cloneNode(true));
    }
    return li;
  }

  function pamDeskMount(row) {
    if (!row || !pamDeskOn()) {
      return;
    }
    var board = (row.closest && row.closest('[data-js="pam-board"]')) || document.querySelector('[data-js="pam-board"]');
    if (!board) {
      return;
    }
    var list = board.querySelector('.pam-board-list .apple-list');
    var slot = board.querySelector('[data-js="pam-mid-list"]');
    if (!list || !slot) {
      return;
    }
    var id = row.getAttribute('data-id') || '';
    var pin = id ? list.querySelector('.pam-desk-pin[data-desk-pin="' + id + '"]') : null;
    if (!pin) {
      pin = pamBuildPin(row);
      if (row.parentNode === list) {
        list.insertBefore(pin, row);
      } else {
        var lateHead = list.querySelector('.crm-group[data-group="late"]');
        var top = lateHead ? lateHead.nextSibling : list.firstChild;
        list.insertBefore(pin, top);
      }
    }
    list.querySelectorAll('.pam-desk-pin').forEach(function (one) {
      one.classList.toggle('is-selected', one === pin);
    });
    slot.querySelectorAll('.item').forEach(function (other) {
      if (other !== row) {
        other.remove();
      }
    });
    if (row.parentNode !== slot) {
      slot.appendChild(row);
    }
    board.setAttribute('data-open', '1');
    if (typeof board._jeabLoadSide === 'function') {
      board._jeabLoadSide();
    }
  }

  function pamDeskUnmount(row) {
    var board = row && row.closest ? row.closest('[data-js="pam-board"]') : null;
    if (!board) {
      board = document.querySelector('[data-js="pam-board"]');
    }
    if (!board || !row) {
      return;
    }
    var list = board.querySelector('.pam-board-list .apple-list');
    var slot = board.querySelector('[data-js="pam-mid-list"]');
    var id = row.getAttribute('data-id') || '';
    var pin = list && id ? list.querySelector('.pam-desk-pin[data-desk-pin="' + id + '"]') : null;
    if (slot && list && slot.contains(row)) {
      if (pin && pin.parentNode === list) {
        list.insertBefore(row, pin);
        pin.remove();
      } else {
        list.appendChild(row);
      }
    }
    if (!board.querySelector('.item.is-open')) {
      board.setAttribute('data-open', '0');
      var body = board.querySelector('[data-js="pam-side-body"]');
      if (body) {
        body.innerHTML = '<p class="pam-side-empty">Öppna en påminnelse.</p>';
        body.removeAttribute('data-side-id');
      }
    }
  }

  function pamDeskTopItem(board) {
    var list = board.querySelector('.pam-board-list .apple-list');
    var el = list ? list.firstElementChild : null;
    while (el) {
      if (el.classList.contains('item')) {
        return el;
      }
      el = el.nextElementSibling;
    }
    return null;
  }

  /* Skrivbord: översta raden är vald direkt, även efter filter. Tom lista behåller tre rutor. */
  function pamDeskEnsureTop(board) {
    if (!board || !pamDeskOn() || board._jeabDeskOpening) {
      return;
    }
    if (board.querySelector('.item.is-open')) {
      return;
    }
    var row = pamDeskTopItem(board);
    if (!row) {
      board.setAttribute('data-open', '0');
      return;
    }
    board._jeabDeskOpening = '1';
    board.setAttribute('data-open', '1');
    openReminderRow(row, { history: false, desk: true });
  }

  function pamDeskSync() {
    document.querySelectorAll('[data-js="pam-board"]').forEach(function (board) {
      var row = board.querySelector('.item.is-open');
      var list = board.querySelector('.pam-board-list .apple-list');
      var slot = board.querySelector('[data-js="pam-mid-list"]');
      if (!pamDeskOn()) {
        if (row && list && slot && slot.contains(row)) {
          var pin = list.querySelector('.pam-desk-pin[data-desk-pin="' + (row.getAttribute('data-id') || '') + '"]');
          if (pin && pin.parentNode === list) {
            list.insertBefore(row, pin.nextSibling);
          } else {
            list.appendChild(row);
          }
        }
        return;
      }
      if (row) {
        pamDeskMount(row);
      } else {
        pamDeskEnsureTop(board);
      }
    });
  }

  function openReminderRow(row, opts) {
    if (!row || (opts && opts.desk && !pamDeskOn())) {
      return;
    }
    collapseOtherReminderCards(row);
    /* Hämtat kort är redan is-open. Utan den här grenen stannar mitten på
       «Välj en påminnelse» och högerpanelen hämtas aldrig. */
    if (row.classList.contains('is-open')) {
      pamDeskMount(row);
      return;
    }
    if (!row.querySelector('.item-head-open') && (!opts || opts.card !== false)) {
      fetchReminderCard(row, opts);
      return;
    }
    const openRow = currentOpenRow();
    if (openRow && openRow !== row) {
      closeOpenReminder({ history: false });
    }
    if (!opts || opts.history !== false) {
      if (!pushReminderOpen(row.getAttribute('data-id'), opts && opts.href)) {
        return;
      }
    }
    row.classList.add('is-open');
    reminderOpenedAt = Date.now();
    const openHead = row.querySelector('.item-head-open');
    if (openHead) {
      openHead.hidden = false;
    }
    const shut = row.querySelector('.item-head-shut');
    if (shut) {
      shut.hidden = true;
    }
    const extra = row.querySelector('.item-extra');
    if (extra) {
      extra.hidden = false;
    }
    row.querySelectorAll('.contact-pen').forEach((el) => {
      el.hidden = false;
    });
    const created = row.querySelector('.item-created');
    if (created) {
      created.hidden = false;
    }
    row.querySelectorAll('[data-js="grow-note"]').forEach((el) => growNote(el));
    stripReminderHash();
    setClosePad();
    if (typeof pulseReminders === 'function') {
      pulseReminders();
    }
    pamDeskMount(row);
  }

  window.jeabReminderOpenPop = function () {
    if (!document.body.classList.contains('page-reminders') || document.body.classList.contains('page-kontakter')) {
      return false;
    }
    let id = '';
    let here = '';
    try {
      const params = new URLSearchParams(location.search);
      if ((params.get('p') || 'reminders') !== 'reminders') {
        return false;
      }
      id = params.get('open') || '';
      here = location.pathname + location.search;
    } catch (e) {
      return false;
    }
    const previous = reminderHistoryUrl;
    reminderHistoryUrl = here;
    if (!previous || !reminderUrlSameExceptOpen(previous, here)) {
      return false;
    }
    const row = id ? document.querySelector('.apple-list .item[data-id="' + id + '"]') : null;
    if (id && !row) {
      return false;
    }
    const open = currentOpenRow();
    if (open && String(open.getAttribute('data-id') || '') !== id) {
      closeOpenReminder({ history: false });
    }
    if (row) {
      openReminderRow(row, { history: false });
    }
    return true;
  };

  stripReminderHash();
  setClosePad();

  function eventPath(event) {
    if (typeof event.composedPath === 'function') {
      return event.composedPath();
    }
    const path = [];
    let node = event.target;
    if (node && node.nodeType === 3) {
      node = node.parentNode;
    }
    while (node) {
      path.push(node);
      node = node.parentNode;
    }
    return path;
  }

  function eventInside(el, event) {
    if (!el) {
      return false;
    }
    const path = eventPath(event);
    if (path.includes(el)) {
      return true;
    }
    const target = event.target;
    if (target && el.contains(target)) {
      return true;
    }
    return !!(target && target.nodeType === 3 && target.parentNode && el.contains(target.parentNode));
  }

  function leavesReminders(event) {
    const path = eventPath(event);
    const link = path.find((node) => node && node.tagName === 'A');
    if (!link || !link.getAttribute) {
      return false;
    }
    const href = link.getAttribute('href') || '';
    if (href.startsWith('tel:') || href.startsWith('mailto:')) {
      return true;
    }
    if (link.closest('.apple-side')) {
      return true;
    }
    if (link.closest('.apple-list .item') && !eventInside(currentOpenRow(), event)) {
      return true;
    }
    if (link.closest && link.closest('nav.tabs')) {
      return true;
    }
    return /p=ordrar|p=inkorg|p=bookings|p=offert|p=kalkyl|p=links|p=zapier|p=manuell-order|\/rot/.test(href);
  }

  const onOutside = (event) => {
    if (event.target && event.target.closest && event.target.closest('.item-snooze-status')) {
      return;
    }
    const openRow = currentOpenRow();
    if (!openRow) {
      return;
    }
    if (!document.body.contains(openRow)) {
      return;
    }
    if (!openRow.classList.contains('is-open') && !openRow.querySelector('.item-extra:not([hidden])')) {
      return;
    }
    if (event.target && event.target.closest && event.target.closest('[data-js="due-catcher"]')) {
      return;
    }
    const field = event.target && event.target.closest
      ? event.target.closest('input, textarea, select')
      : null;
    if (field && openRow.contains(field)) {
      return;
    }
    if (eventInside(openRow, event)) {
      return;
    }
    /* Högerkolumnen är inte inne i kortet. Utan den här grenen stänger
       pointerdown kortet och byter ut panelen innan #vunnen hinner skickas.
       Textnoden i knappen har inget closest, så träffen läses ur sökvägen. */
    const outsidePath = eventPath(event);
    const inSide = outsidePath.some((node) => node && node.classList && node.classList.contains('pam-side'));
    if (inSide) {
      const outsidePlan = (typeof jeabReminderOutsidePlan === 'function')
        ? jeabReminderOutsidePlan('side')
        : 'keep';
      if (outsidePlan === 'keep') {
        return;
      }
    }
    if (snoozeRowBusy(openRow)) {
      return;
    }
    if (leavesReminders(event)) {
      const inline = openRow.querySelector('[data-js="inline"]');
      if (inline && (inline._jeabDirty || inline._jeabSavePromise)) {
        saveInlineForm(inline);
      }
      return;
    }
    const path = eventPath(event);
    if (path.includes(closeRow) || path.includes(closePad)) {
      if (event.type === 'click' || event.type === 'pointerdown') {
        event.preventDefault();
      }
      closeOpenReminder();
      return;
    }
    const link = path.find((node) => node && node.tagName === 'A');
    const passes = !!(link && link.closest && link.closest('.crm-stats a.smart-tile, .crm-who a.chip'));
    if (link && !passes && /p=reminders/.test(link.getAttribute('href') || '')) {
      event.preventDefault();
    }
    closeOpenReminder();
  };
  document.addEventListener('pointerdown', onOutside, true);
  document.addEventListener('click', onOutside, true);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }
    if (!document.body.classList.contains('page-reminders') || document.body.classList.contains('page-kontakter')) {
      return;
    }
    if (document.querySelector('dialog[open]')) {
      return;
    }
    if (!currentOpenRow()) {
      return;
    }
    event.preventDefault();
    closeOpenReminder();
  });

  document.addEventListener('pointerdown', (event) => {
    const field = event.target && event.target.closest
      ? event.target.closest('.apple-list .item.is-open .item-head-open input, .apple-list .item.is-open .item-head-open textarea[name="address"]')
      : null;
    if (field) {
      field._jeabWasFocused = document.activeElement === field;
    }
  }, true);

  function reminderHeadTarget(event) {
    const target = event.target;
    if (!target || !target.closest) {
      return 'head';
    }
    const area = target.closest('textarea');
    if (area && area.getAttribute('name') !== 'address') {
      return 'textarea';
    }
    if (target.closest('select')) {
      return 'select';
    }
    if (target.closest('a[href]')) {
      return 'link';
    }
    if (target.closest('button')) {
      return 'button';
    }
    /* Mobil: hela adress- och namnraden fäller ihop, inte bara datumet.
       Knapp och länk (telefon) har redan återvänt och behåller sin åtgärd. */
    if (reminderMobileList() && target.closest('.item-addr-row, .item-name-row')) {
      return 'header-row';
    }
    const field = target.closest('input, textarea[name="address"]');
    if (field && field._jeabWasFocused) {
      return 'input-focused';
    }
    if (field) {
      return 'input';
    }
    return 'head';
  }

  document.addEventListener('click', (event) => {
    const head = event.target && event.target.closest
      ? event.target.closest('.apple-list .item.is-open .item-head-open')
      : null;
    if (!head) {
      return;
    }
    const kind = reminderHeadTarget(event);
    const decision = (typeof jeabReminderHeadClick === 'function')
      ? jeabReminderHeadClick(reminderOpenedAt, Date.now(), kind)
      : 'close';
    if (decision === 'keep') {
      const since = Date.now() - reminderOpenedAt;
      if ((kind === 'input' || kind === 'head') && since >= 0 && since < 400) {
        event.preventDefault();
        const field = event.target.closest('input, textarea[name="address"]');
        if (field) {
          field.blur();
        }
      }
      return;
    }
    event.preventDefault();
    const field = event.target.closest('input, textarea[name="address"]');
    if (field) {
      field.blur();
    }
    closeOpenReminder();
  }, true);

  function bindInlineForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      saveInlineForm(form);
    });
    form.querySelectorAll('input, textarea, select').forEach((field) => {
      field.addEventListener('input', () => {
        if (field.name === 'address' && field.tagName === 'TEXTAREA') {
          const flat = String(field.value || '').replace(/\r?\n+/g, ' ');
          if (flat !== field.value) {
            const pos = field.selectionStart;
            field.value = flat;
            if (typeof pos === 'number' && typeof field.setSelectionRange === 'function') {
              const next = Math.min(pos, flat.length);
              field.setSelectionRange(next, next);
            }
          }
        }
        form._jeabDirty = true;
      });
      field.addEventListener('change', () => {
        form._jeabDirty = true;
        saveInlineForm(form);
      });
    });
    form.querySelectorAll('textarea[name="address"]').forEach((field) => {
      field.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
          return;
        }
        event.preventDefault();
        if (form._jeabDirty) {
          saveInlineForm(form);
        }
      });
    });
  }

  function bindGrowNote(el) {
    if (!el || el._jeabBound) {
      return;
    }
    el._jeabBound = true;
    growNote(el);
    el.addEventListener('input', () => growNote(el));
  }

  document.querySelectorAll('[data-js="inline"]').forEach(bindInlineForm);
  document.querySelectorAll('[data-js="grow-note"]').forEach(bindGrowNote);
  requestAnimationFrame(() => {
    document.querySelectorAll('[data-js="grow-note"]').forEach((el) => growNote(el));
  });

  function bindReminderCreate() {
    const createDialog = document.getElementById('reminder-create');
    const createOpen = document.querySelector('[data-js="reminder-create"]');
    if (!createDialog || !createOpen || createOpen._jeabBound) {
      return;
    }
    createOpen._jeabBound = true;
    const createForm = createDialog.querySelector('[data-js="reminder-create-form"]');
    const titleInput = createDialog.querySelector('[data-js="reminder-create-title"]');
    const addressInput = createDialog.querySelector('[data-js="reminder-create-address"]');

    function syncCreateWho() {
      if (!createForm) {
        return;
      }
      const picked = createForm.querySelector('input[name="company"]:checked');
      const company = picked ? picked.value : '';
      createForm.querySelectorAll('[data-company-who]').forEach((box) => {
        const on = box.getAttribute('data-company-who') === company;
        box.hidden = !on;
        box.querySelectorAll('input[name="who"]').forEach((radio) => {
          radio.disabled = !on;
          if (!on) {
            radio.checked = false;
          }
        });
        if (on && !box.querySelector('input[name="who"]:checked')) {
          const first = box.querySelector('input[name="who"]');
          if (first) {
            first.checked = true;
          }
        }
      });
    }

    function openCreateDialog() {
      syncCreateWho();
      if (typeof createDialog.showModal === 'function') {
        // ?ny=1 ritas med open (icke-modal); showModal() kastar då InvalidStateError.
        if (createDialog.open) {
          createDialog.removeAttribute('open');
        }
        createDialog.showModal();
      } else {
        createDialog.setAttribute('open', '');
      }
      if (titleInput) {
        try {
          titleInput.focus({ preventScroll: true });
        } catch {
          titleInput.focus();
        }
      }
    }

    function closeCreateDialog() {
      if (typeof createDialog.close === 'function') {
        createDialog.close();
      } else {
        createDialog.removeAttribute('open');
      }
    }

    createOpen.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openCreateDialog();
    });
    createDialog.querySelectorAll('[data-js="reminder-create-close"]').forEach((btn) => {
      btn.addEventListener('click', (event) => {
        event.preventDefault();
        closeCreateDialog();
      });
    });
    createDialog.addEventListener('click', (event) => {
      if (event.target === createDialog) {
        closeCreateDialog();
      }
    });
    createDialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      closeCreateDialog();
    });
    createDialog.addEventListener('close', () => {
      if (createForm) {
        createForm.reset();
        syncCreateWho();
      }
      setContactError(createDialog.querySelector('[data-js="reminder-create-error"]'), '');
    });
    if (new URLSearchParams(window.location.search).get('ny') === '1') {
      openCreateDialog();
    }
    if (createForm) {
      createForm.querySelectorAll('input[name="company"]').forEach((input) => {
        input.addEventListener('change', syncCreateWho);
      });
      createForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (createForm._jeabBusy) {
          return;
        }
        if (addressInput && titleInput) {
          addressInput.value = String(titleInput.value || '').trim();
        }
        const phoneInput = createForm.querySelector('input[name="phone"]');
        const emailInput = createForm.querySelector('input[name="email"]');
        const createErr = createDialog.querySelector('[data-js="reminder-create-error"]');
        const parsed = normalizeReminderPhone(phoneInput ? phoneInput.value : '');
        if (parsed === null) {
          setContactError(createErr, REMINDER_PHONE_ERROR);
          return;
        }
        if (phoneInput && parsed !== '') {
          phoneInput.value = parsed;
        }
        if (emailInput) {
          emailInput.value = String(emailInput.value || '').trim();
        }
        setContactError(createErr, '');
        const saveBtn = createForm.querySelector('.reminder-create-save');
        const saveLabel = saveBtn ? saveBtn.textContent : '';
        createForm._jeabBusy = true;
        if (saveBtn) {
          saveBtn.disabled = true;
          saveBtn.setAttribute('aria-busy', 'true');
          saveBtn.textContent = 'Sparar…';
        }
        postInPlace(createForm).then((data) => {
          closeCreateDialog();
          const flashText = (data && data.flash) ? String(data.flash) : ((data && data.reused) ? 'Befintlig påminnelse.' : 'Sparad.');
          const closedTid = data && data.closed_thread ? String(data.closed_thread) : '';
          const showCreateFlash = () => {
            if (closedTid) {
              showReminderBar(flashText, true, 6000, () => {
                const body = new FormData();
                body.set('action', 'inkorg.thread');
                body.set('thread', closedTid);
                body.set('status', 'open');
                const csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
                if (csrf) {
                  body.set('_csrf', csrf);
                }
                fetch(data.reopen || '/index.php?p=inkorg', {
                  method: 'POST',
                  body,
                  credentials: 'same-origin',
                  headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrf,
                    Accept: 'application/json',
                  },
                }).then((res) => res.json().catch(() => ({}))).then((out) => {
                  if (out && out.ok) {
                    showReminderBar('Öppen igen.', false, 1600);
                  }
                }).catch(() => {});
              });
              const undoBtn = reminderUndoBar().querySelector('.crm-undo-btn');
              if (undoBtn) {
                undoBtn.textContent = 'Öppna igen';
              }
              return;
            }
            showReminderBar(flashText, false, data && data.reused ? 2800 : 1600);
          };
          if (data && data.reused && !closedTid) {
            showReminderBar('Befintlig påminnelse.', false, 2800);
          }
          if (data && data.counts) {
            paintReminderCounts(data.counts);
          }
          const createdId = data && (data.id || (data.row && data.row.id));
          if (data && data.shown && data.html && createdId) {
            insertReminderCard(data);
            reminderSoftStamp = '';
            showCreateFlash();
            return;
          }
          if (data && data.next) {
            softNavigateReminders(data.next);
            return;
          }
          showCreateFlash();
        }).catch((error) => {
          if (error && error.message === 'csrf') {
            return;
          }
          setContactError(createErr, (error && error.message) || 'Kunde inte spara.');
        }).finally(() => {
          createForm._jeabBusy = false;
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.removeAttribute('aria-busy');
            saveBtn.textContent = saveLabel || 'Spara';
          }
        });
      });
    }
  }
  bindReminderCreate();

  let searchForm = null;
  let searchInput = null;
  let searchClear = null;

  function activeSearchQuery() {
    return String(new URLSearchParams(location.search).get('q') || '').trim();
  }

  function idagLeaveHref() {
    if (searchClear && searchClear.getAttribute('href')) {
      return searchClear.getAttribute('href');
    }
    const today = document.querySelector('.smart-tile.is-today');
    if (today && today.getAttribute('href')) {
      return today.getAttribute('href');
    }
    return location.pathname + '?p=reminders&v=today';
  }

  function syncSearchClear() {
    if (!searchClear || !searchInput) {
      return;
    }
    searchClear.hidden = String(searchInput.value || '').trim() === '';
  }

  function leaveReminderSearch() {
    if (searchInput) {
      searchInput.value = '';
    }
    syncSearchClear();
    if (activeSearchQuery() === '') {
      return;
    }
    softNavigateReminders(idagLeaveHref(), { replace: true, keepFocus: true });
  }

  function reminderSearchHref() {
    const url = new URL(location.href);
    const action = searchForm.getAttribute('action') || '';
    if (action) {
      url.pathname = new URL(action, location.href).pathname;
    }
    // Adressens personval vinner över ett formulär som fortfarande har den inloggade.
    const keepWho = url.searchParams.has('who');
    new FormData(searchForm).forEach((value, key) => {
      if (keepWho && (key === 'who' || key === 'ws')) {
        return;
      }
      url.searchParams.set(key, String(value));
    });
    url.searchParams.set('q', String(searchInput.value || '').trim());
    url.searchParams.delete('intent');
    return url.pathname + url.search;
  }

  const SEARCH_DEBOUNCE_MS = 300;
  let searchTimer = 0;
  let searchComposing = false;

  function runReminderSearch() {
    window.clearTimeout(searchTimer);
    searchTimer = 0;
    const q = String(searchInput.value || '').trim();
    if (q === '') {
      leaveReminderSearch();
      return;
    }
    if (q === activeSearchQuery()) {
      return;
    }
    // Första tecknet blir ett historiksteg, resten ersätter det (bakåt = före sök).
    softNavigateReminders(reminderSearchHref(), { replace: activeSearchQuery() !== '', keepFocus: true });
  }

  function bindReminderSearch() {
    const form = document.querySelector('[data-js="apple-search"]');
    const input = form ? form.querySelector('[data-js="apple-search-q"]') : null;
    if (!form || !input || form._jeabSearchBound) {
      return;
    }
    form._jeabSearchBound = true;
    searchForm = form;
    searchInput = input;
    searchClear = form.querySelector('[data-js="search-clear"]');
    syncSearchClear();
    searchForm.addEventListener('submit', (event) => {
      event.preventDefault();
      runReminderSearch();
    });
    searchInput.addEventListener('compositionstart', () => {
      searchComposing = true;
      window.clearTimeout(searchTimer);
    });
    searchInput.addEventListener('compositionend', () => {
      searchComposing = false;
      syncSearchClear();
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(runReminderSearch, SEARCH_DEBOUNCE_MS);
    });
    searchInput.addEventListener('input', (event) => {
      syncSearchClear();
      if (searchComposing || (event && event.isComposing)) {
        window.clearTimeout(searchTimer);
        return;
      }
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(runReminderSearch, SEARCH_DEBOUNCE_MS);
    });
    if (searchClear) {
      searchClear.addEventListener('click', (event) => {
        event.preventDefault();
        window.clearTimeout(searchTimer);
        leaveReminderSearch();
        try {
          searchInput.focus({ preventScroll: true });
        } catch {
          searchInput.focus();
        }
      });
    }
  }
  bindReminderSearch();

  function bindCollapseRow(btn) {
    if (!btn || btn._jeabBound) {
      return;
    }
    btn._jeabBound = true;
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeOpenReminder();
    });
  }

  function isKontaktRow(link) {
    return !!(link && ((link.closest && link.closest('[data-kt-root]')) || document.body.classList.contains('page-kontakter')));
  }

  /* Inkorgens kort är också a.item-main. Påminnelseklicket får inte svälja dem. */
  function isInkorgRow(link) {
    if (!link) {
      return false;
    }
    if (document.body && document.body.classList.contains('page-inkorg')) {
      return true;
    }
    return !!(link.closest && link.closest('.ix-it, #ix-list'));
  }

  function bindReminderItemMains(scope) {
    const root = scope && scope.querySelectorAll ? scope : document;
    root.querySelectorAll('a.item-main').forEach((link) => {
      if (isKontaktRow(link) || isInkorgRow(link)) {
        return;
      }
      bindItemMain(link);
    });
  }

  function bindItemMain(link) {
    if (!link || link._jeabBound) {
      return;
    }
    /* Kontaktlistan och inkorgen använder samma a.item-main. Klicket ska följa href, inte öppna en påminnelserad. */
    if (link.closest('[data-kt-root]') || document.body.classList.contains('page-kontakter') || isInkorgRow(link)) {
      return;
    }
    link._jeabBound = true;
    link.addEventListener('click', (event) => {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      openFromLink();
    });
    link.addEventListener('pointerdown', (event) => {
      link._jeabFromTouch = event.pointerType === 'touch';
    });
    link.addEventListener('mousedown', (event) => {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      if (event.button !== 0) {
        return;
      }
      if (link._jeabFromTouch) {
        return;
      }
      if (event.target && event.target.closest && event.target.closest('input, textarea, select')) {
        return;
      }
      event.preventDefault();
    });
    function openFromLink() {
      const row = link.closest('.apple-list .item');
      if (!row) {
        location.href = link.href;
        return;
      }
      openReminderRow(row, { href: link.getAttribute('href') || link.href });
    }
    link.addEventListener('pointerup', (event) => {
      if (event.pointerType === 'mouse' || (event.button != null && event.button !== 0)) {
        return;
      }
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      openFromLink();
    });
  }

  document.querySelectorAll('[data-js="collapse-row"]').forEach(bindCollapseRow);
  bindReminderItemMains(document);

  document.addEventListener('click', function (event) {
    if (!pamDeskOn()) {
      return;
    }
    var t = event.target && event.target.closest ? event.target : null;
    if (t && t.nodeType === 3) {
      t = t.parentNode;
    }
    if (!t || !t.closest) {
      return;
    }
    if (t.closest('a.item-call, a[href^="tel:"], a[href^="mailto:"], button, input, textarea, select, .check-form')) {
      return;
    }
    if (t.closest('.pam-desk-pin')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (t.closest('.pam-mid, .pam-side')) {
      return;
    }
    var row = t.closest('.pam-board-list .item');
    if (!row || row.classList.contains('is-open') || t.closest('a.item-main')) {
      return;
    }
    event.preventDefault();
    openReminderRow(row);
  }, true);

  document.addEventListener('pointerdown', (event) => {
    if (!window.matchMedia || !window.matchMedia('(max-width: 480px)').matches) {
      return;
    }
    if (document.body.classList.contains('page-kontakter')) {
      return;
    }
    const target = event.target;
    if (!target || !target.closest) {
      return;
    }
    const field = target.closest('.apple-list .item input, .apple-list .item textarea');
    if (field && !field.closest('.check-form, [data-js="snooze"], [data-js="due"], .item-extra')) {
      const row = field.closest('.apple-list .item');
      if (row && !row.classList.contains('is-open')) {
        openReminderRow(row);
        queueMicrotask(() => {
          if (document.activeElement !== field) {
            try {
              field.focus({ preventScroll: true });
            } catch (e) {
              field.focus();
            }
          }
        });
      }
      return;
    }
    const note = target.closest('.apple-list .item:not(.is-open) .item-stack > .item-note');
    if (!note || target.closest('a, button, input, textarea, select')) {
      return;
    }
    const noteRow = note.closest('.apple-list .item');
    if (!noteRow || noteRow.classList.contains('is-open')) {
      return;
    }
    openReminderRow(noteRow);
    const area = noteRow.querySelector('textarea.item-note');
    if (!area) {
      return;
    }
    queueMicrotask(() => {
      try {
        area.focus({ preventScroll: true });
      } catch (e) {
        area.focus();
      }
    });
  }, true);

  function showReloadNotice() {
    if (document.querySelector('[data-js="session-reload"]')) {
      return;
    }
    const bar = document.createElement('p');
    bar.className = 'flash flash-session';
    bar.setAttribute('data-js', 'session-reload');
    bar.setAttribute('role', 'alert');
    bar.appendChild(document.createTextNode('Ogiltig session. '));
    const link = document.createElement('a');
    link.href = location.pathname + location.search;
    link.textContent = 'Ladda om sidan';
    link.addEventListener('click', (event) => {
      event.preventDefault();
      location.reload();
    });
    bar.appendChild(link);
    const host = document.querySelector('.apple-app') || document.querySelector('main') || document.body;
    host.insertBefore(bar, host.firstChild);
  }

  function postInPlace(form) {
    return fetch(formPostUrl(form), {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (res.status === 419) {
        showReloadNotice();
        throw new Error('csrf');
      }
      return res.json().catch(() => ({})).then((data) => {
        if (!res.ok || data.ok === false) {
          const err = new Error((data && data.error) || 'Kunde inte spara.');
          throw err;
        }
        return data;
      });
    });
  }

  function rowInlineForm(from) {
    const row = from && from.closest ? from.closest('.apple-list .item') : null;
    return row ? row.querySelector('[data-js="inline"]') : null;
  }

  function saveInlineForm(form) {
    if (!form) {
      return Promise.resolve();
    }
    const pendingRow = form.closest('.apple-list .item');
    if (pendingRow && pendingRow._jeabDonePending) {
      return Promise.resolve();
    }
    if (form._jeabSavePromise) {
      return form._jeabSavePromise;
    }
    if (!form._jeabDirty) {
      return Promise.resolve();
    }
    form._jeabDirty = false;
    form._jeabSavePromise = postInPlace(form).then(() => {
      showReminderBar('Sparad.', false, 1600);
    }).catch((error) => {
      form._jeabDirty = true;
      if (!error || error.message !== 'csrf') {
        showReminderBar((error && error.message) || 'Kunde inte spara.', false, 2800);
      }
      throw error;
    }).finally(() => {
      form._jeabSavePromise = null;
    });
    return form._jeabSavePromise;
  }

  function flushInlineNear(el) {
    return saveInlineForm(rowInlineForm(el)).catch(() => {});
  }

  function bindInPlace(form, onOk, options) {
    const opts = options || {};
    const btn = form.querySelector('button[type="submit"], .tag-chip');
    if (btn) {
      btn.addEventListener('mousedown', (event) => {
        event.preventDefault();
        event.stopPropagation();
      });
    }
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const rollback = (typeof opts.optimistic === 'function') ? opts.optimistic(form, btn) : null;
      const sent = opts.skipFlush
        ? postInPlace(form)
        : flushInlineNear(form).then(() => postInPlace(form));
      sent.then((data) => {
        onOk(data, btn);
      }).catch((error) => {
        if (typeof rollback === 'function') {
          rollback();
        }
        if (error && error.message === 'csrf') {
          return;
        }
        showReminderBar('Kunde inte spara.', false, 2800);
      });
    });
  }

  function isTodayView() {
    const params = new URLSearchParams(location.search);
    const v = params.get('v');
    if (String(params.get('q') || '').trim() !== '') {
      return false;
    }
    return v === 'today' || v === null || v === '';
  }

  function leaveTodayRow(row) {
    if (!row) {
      return;
    }
    if (row._jeabSnoozeHold && !row._jeabSnoozeLeft) {
      return;
    }
    if (row.classList.contains('is-open')) {
      closeOpenReminder();
    }
    row.hidden = true;
    row.style.setProperty('display', 'none', 'important');
  }

  function restoreTodayRow(row) {
    if (!row) {
      return;
    }
    row.hidden = false;
    row.style.removeProperty('display');
  }

  const SNOOZE_LEAVE_MS = (typeof jeabSnoozeLeaveMs === 'function') ? jeabSnoozeLeaveMs() : 3000;
  const SNOOZE_UNDO_MS = (typeof jeabSnoozeUndoMs === 'function') ? jeabSnoozeUndoMs() : 6000;
  const SNOOZE_COLLAPSE_MS = 500;
  const snoozeHidden = new Set();
  const snoozeLive = new Map();
  const snoozeUndoStore = new Map();
  let snoozeToast = null;
  let snoozeUndoTimer = 0;
  let snoozeShiftTimer = 0;

  function snoozeRowId(row) {
    return String((row && row.getAttribute('data-id')) || '');
  }

  function rememberSnoozeRow(row) {
    const id = snoozeRowId(row);
    if (!id) {
      return;
    }
    const session = snoozeLive.get(id) || {};
    session.row = row;
    if (row.parentElement) {
      session.list = row.parentElement;
      session.next = row.nextSibling;
      session.index = [...row.parentElement.querySelectorAll('.item')].indexOf(row);
    }
    snoozeLive.set(id, session);
  }

  function snoozeHoldActive(row) {
    return !!(row && row._jeabSnoozeHold && !row._jeabSnoozeLeft && !row._jeabSnoozeUndo);
  }

  /* Ångra som väntar på servern ska inte försvinna i en omritning. */
  function snoozeRowPinned(row) {
    return snoozeHoldActive(row) || !!(row && row._jeabSnoozeRestore);
  }

  /* Omritning, poll och «förfaller nu» får inte ta bort kortet under de 2 sekunderna. */
  function ensureSnoozeHoldRow(row) {
    if (!snoozeHoldActive(row)) {
      return;
    }
    row.classList.add('is-snooze-hold');
    row.hidden = false;
    if (row.style.display === 'none') {
      row.style.removeProperty('display');
    }
    const id = snoozeRowId(row);
    const session = id ? snoozeLive.get(id) : null;
    const list = (session && session.list && session.list.isConnected)
      ? session.list
      : document.querySelector('.apple-list');
    if (!list || row.isConnected) {
      return;
    }
    const items = [...list.querySelectorAll('.item')];
    const at = session && session.index != null ? session.index : items.length;
    const next = session && session.next && session.next.parentNode === list
      ? session.next
      : (items[at] || null);
    list.insertBefore(row, next);
  }

  function dropSnoozeRow(row) {
    if (!row || (row._jeabSnoozeLeft && !row.isConnected)) {
      return;
    }
    const id = snoozeRowId(row);
    let anchor = row.isConnected ? row : null;
    if (id) {
      document.querySelectorAll('.apple-list .item[data-id="' + id + '"]').forEach((node) => {
        if (!anchor && node.isConnected) {
          anchor = node;
        }
      });
    }
    if (row._jeabSnoozeLeft && !anchor) {
      return;
    }
    row._jeabSnoozeLeaving = true;
    row._jeabSnoozeLeft = true;
    row._jeabSnoozeHold = false;
    row.classList.remove('is-snooze-hold');
    const placed = anchor || row;
    const wasOpen = row.classList.contains('is-open') || placed.classList.contains('is-open');
    if (id) {
      snoozeHidden.add(id);
      const session = snoozeLive.get(id) || {};
      session.row = row;
      session.list = placed.parentElement;
      session.next = placed.nextSibling;
      snoozeLive.set(id, session);
      document.querySelectorAll('.apple-list .item[data-id="' + id + '"]').forEach((node) => {
        if (node !== row) {
          node._jeabSnoozeLeft = true;
          node.remove();
        }
      });
    }
    if (row.isConnected) {
      row.remove();
    }
    if (wasOpen) {
      writeOpenParam('');
    }
    if (typeof jeabSnoozePointerBlock !== 'function' || jeabSnoozePointerBlock('leave')) {
      armSnoozeShift();
    }
    paintReminderGroups();
  }

  function armSnoozeShift() {
    const ms = (typeof jeabSnoozeShiftMs === 'function') ? jeabSnoozeShiftMs() : 400;
    document.documentElement.classList.add('is-snooze-shift');
    window.clearTimeout(snoozeShiftTimer);
    snoozeShiftTimer = window.setTimeout(() => {
      document.documentElement.classList.remove('is-snooze-shift');
    }, ms);
  }

  function rememberSnoozeUndo(row, fresh) {
    const id = snoozeRowId(row);
    if (!id) {
      return;
    }
    const existing = snoozeUndoStore.get(id);
    if (!fresh && existing && !existing.undone) {
      existing.until = Date.now() + SNOOZE_UNDO_MS;
      return;
    }
    const orig = row._jeabSnoozeOrig || {};
    const dueRaw = orig.dueAt != null ? String(orig.dueAt) : '';
    const dueAt = (typeof jeabSnoozeDueStamp === 'function') ? (jeabSnoozeDueStamp(dueRaw) || dueRaw) : dueRaw;
    const completedAt = orig.completedAt != null ? String(orig.completedAt) : '';
    const base = (typeof jeabSnoozeUndoRecord === 'function')
      ? jeabSnoozeUndoRecord(id, dueAt, completedAt, Date.now())
      : { id, dueAt, completedAt, until: Date.now() + SNOOZE_UNDO_MS };
    const form = row.querySelector('[data-js="due"]');
    const fields = [];
    if (form) {
      new FormData(form).forEach((value, key) => {
        fields.push([String(key), String(value)]);
      });
    }
    snoozeUndoStore.set(id, {
      id: base.id,
      dueAt: base.dueAt,
      completedAt: base.completedAt,
      until: base.until,
      undone: false,
      fields,
      action: form ? (formPostUrl(form) || '') : '',
      html: row.outerHTML,
      queue: row._jeabSnoozeQueue || null,
      guard: (typeof jeabSnoozeGuard === 'function')
        ? jeabSnoozeGuard({ epoch: 0, phase: 'idle', original: '', due: '' }, { type: 'hold', due: base.dueAt })
        : { epoch: 0, phase: 'holding', original: base.dueAt, due: base.dueAt, post: '', ignore: false },
    });
  }

  function reinsertSnoozeCard(entry) {
    if (!entry) {
      return null;
    }
    snoozeHidden.delete(entry.id);
    const list = document.querySelector('.apple-list');
    const existing = list ? list.querySelector('.item[data-id="' + entry.id + '"]') : null;
    const wrap = document.createElement('ul');
    wrap.innerHTML = entry.html || '';
    const row = wrap.querySelector('.item');
    if (row && list) {
      if (existing) {
        existing.replaceWith(row);
      } else {
        const next = entry.next && entry.next.parentNode === list ? entry.next : null;
        if (next) {
          list.insertBefore(row, next);
        } else {
          const items = [...list.querySelectorAll('.item')];
          const at = entry.index != null ? entry.index : items.length;
          list.insertBefore(row, items[at] || null);
        }
      }
      bindReminderSubtree(row);
      return row;
    }
    if (existing) {
      existing.setAttribute('data-due-at', entry.dueAt || '');
      return existing;
    }
    return null;
  }

  function paintUndoRow(entry, data) {
    const list = document.querySelector('.apple-list');
    const current = list ? list.querySelector('.item[data-id="' + entry.id + '"]') : null;
    const html = data && data.html ? String(data.html) : '';
    if (html && list) {
      const wrap = document.createElement('ul');
      wrap.innerHTML = html;
      const fresh = wrap.querySelector('.item');
      if (fresh) {
        const wasOpen = !!(current && current.classList.contains('is-open'));
        if (current) {
          current.replaceWith(fresh);
        } else {
          list.insertBefore(fresh, list.firstChild);
        }
        bindReminderSubtree(fresh);
        if (wasOpen) {
          openReminderRow(fresh, { history: false });
        }
        paintReminderGroups();
        return fresh;
      }
    }
    const dueAt = data && data.row && data.row.due_at ? String(data.row.due_at) : (entry.dueAt || '');
    if (current && dueAt) {
      current.setAttribute('data-due-at', dueAt);
      if (data && data.due) {
        paintSnoozeDue(current, data);
      }
    }
    return current;
  }

  function postUndoEntry(entry) {
    if (!entry || !entry.action) {
      return Promise.resolve();
    }
    const raw = entry.dueAt == null ? '' : String(entry.dueAt);
    const dueAt = (typeof jeabSnoozeDueStamp === 'function') ? jeabSnoozeDueStamp(raw) : raw.trim();
    if (raw.trim() !== '' && dueAt === '') {
      return Promise.reject(new Error('Tiden ändrades inte.'));
    }
    const body = new FormData();
    (entry.fields || []).forEach((pair) => {
      if (pair[0] === 'due_at' || pair[0] === 'completed_at') {
        return;
      }
      body.append(pair[0], pair[1]);
    });
    body.set('due_at', dueAt);
    body.delete('completed_at');
    return fetch(entry.action, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    }).then((res) => res.json().then((data) => {
      if (!res.ok || !data || data.ok === false) {
        throw new Error((data && data.error) || 'Kunde inte spara.');
      }
      const saved = data.row && data.row.due_at != null ? String(data.row.due_at) : '';
      const savedStamp = (typeof jeabSnoozeDueStamp === 'function') ? jeabSnoozeDueStamp(saved) : saved;
      if (dueAt !== '' && savedStamp !== dueAt) {
        throw new Error('Tiden ändrades inte.');
      }
      const live = snoozeUndoStore.get(String(entry.id));
      const liveEpoch = live && live.guard ? live.guard.epoch : null;
      if (entry._jeabUndoEpoch != null && liveEpoch != null && typeof jeabSnoozeEpochAllows === 'function' && !jeabSnoozeEpochAllows(entry._jeabUndoEpoch, liveEpoch)) {
        return { ignored: true };
      }
      paintUndoRow(entry, data);
      return data;
    }));
  }

  function returnSnoozeRow(row) {
    if (!row) {
      return;
    }
    const id = snoozeRowId(row);
    if (id) {
      snoozeHidden.delete(id);
    }
    row._jeabSnoozeLeaving = false;
    row._jeabSnoozeLeft = false;
    if (!row.isConnected) {
      const session = id ? snoozeLive.get(id) : null;
      const list = session && session.list && session.list.isConnected
        ? session.list
        : document.querySelector('.apple-list');
      if (list) {
        const next = session && session.next && session.next.parentNode === list ? session.next : null;
        list.insertBefore(row, next);
      }
    }
    if (id) {
      snoozeLive.delete(id);
    }
    paintReminderGroups();
  }

  function snoozeRowBusy(row) {
    return !!(row && (row._jeabSnoozeTimer || row._jeabSnoozeLeaving || row._jeabSnoozeHold));
  }

  function collapseTodayRow(row) {
    if (!row || row._jeabSnoozeLeaving) {
      return;
    }
    row._jeabSnoozeLeaving = true;
    const parkGen = row._jeabParkGen || 0;
    const height = Math.round(row.getBoundingClientRect().height);
    if (height <= 0) {
      if ((row._jeabParkGen || 0) === parkGen) {
        leaveTodayRow(row);
      }
      return;
    }
    row.style.boxSizing = 'border-box';
    row.style.overflow = 'hidden';
    row.style.maxHeight = height + 'px';
    void row.offsetHeight;
    row.classList.add('is-snooze-leave');
    row.style.maxHeight = '0';
    const finish = () => {
      if ((row._jeabParkGen || 0) !== parkGen || row._jeabSnoozeLeft) {
        return;
      }
      row._jeabSnoozeLeft = true;
      leaveTodayRow(row);
    };
    row.addEventListener('transitionend', (event) => {
      if (event.target !== row) {
        return;
      }
      if (event.propertyName !== 'max-height' && event.propertyName !== 'opacity') {
        return;
      }
      finish();
    });
    setTimeout(finish, SNOOZE_COLLAPSE_MS + 60);
  }

  function scheduleSnoozeLeave(row) {
    if (!row || row._jeabSnoozeLeaving) {
      return;
    }
    const id = snoozeRowId(row);
    const session = snoozeLive.get(id) || {};
    if (session.leaveTimer) {
      clearTimeout(session.leaveTimer);
      session.leaveTimer = 0;
    }
    if (row._jeabSnoozeTimer) {
      clearTimeout(row._jeabSnoozeTimer);
      row._jeabSnoozeTimer = 0;
    }
    if (row._jeabSnoozeUndoTimer) {
      clearTimeout(row._jeabSnoozeUndoTimer);
      row._jeabSnoozeUndoTimer = 0;
    }
    const started = Date.now();
    const by = String(row._jeabSnoozeBy || '');
    const wait = SNOOZE_LEAVE_MS;
    const toastMs = SNOOZE_UNDO_MS;
    rememberSnoozeRow(row);
    const armed = snoozeLive.get(id) || session;
    armed.leaveAt = (typeof jeabSnoozeLeaveDeadline === 'function')
      ? jeabSnoozeLeaveDeadline(started)
      : started + wait;
    armed.row = row;
    snoozeLive.set(id, armed);
    row._jeabSnoozeUntil = started + toastMs;
    const toastGen = row._jeabSnoozeGen || 0;
    row._jeabSnoozeUndoTimer = setTimeout(() => {
      row._jeabSnoozeUndoTimer = 0;
      if (row._jeabSnoozeUndo || (row._jeabSnoozeGen || 0) !== toastGen) {
        return;
      }
      hideSnoozeToast();
      const id = snoozeRowId(row);
      const entry = snoozeUndoStore.get(id);
      if (entry && !entry.undone && Date.now() >= entry.until) {
        snoozeUndoStore.delete(id);
      }
      const session = snoozeLive.get(id);
      if (session && session.row === row && !row._jeabSnoozeTimer) {
        snoozeLive.delete(id);
      }
    }, toastMs);
    const tick = () => {
      row._jeabSnoozeTimer = 0;
      const live = snoozeLive.get(id);
      if (live) {
        live.leaveTimer = 0;
      }
      if (row._jeabSnoozeUndo) {
        return;
      }
      const deadline = live && live.leaveAt ? live.leaveAt : armed.leaveAt;
      const left = deadline - Date.now();
      if (left > 40) {
        const again = setTimeout(tick, left);
        row._jeabSnoozeTimer = again;
        if (live) {
          live.leaveTimer = again;
        }
        return;
      }
      const elapsed = Date.now() - started;
      const ready = (typeof jeabSnoozeHides === 'function')
        ? jeabSnoozeHides(by, elapsed, false)
        : elapsed >= wait;
      if (!ready) {
        row._jeabSnoozeTimer = setTimeout(tick, Math.max(50, wait - elapsed));
        return;
      }
      const form = row._jeabSnoozeForm;
      if (!row._jeabSnoozeSaveStarted) {
        row._jeabSnoozeCommitted = true;
        row._jeabSnoozeSaveStarted = true;
        const parkedSave = snoozeUndoStore.get(id);
        if (parkedSave) {
          parkedSave.saveStarted = true;
        }
        const steps = (row._jeabSnoozeSteps || []).slice();
        row._jeabSnoozeSteps = [];
        row._jeabSnoozeBatch = steps.length ? steps : (by ? [by] : []);
        if (form) {
          form._jeabParkBusy = true;
        }
        queueSnoozeSave(form, row);
      }
      const queued = row._jeabSnoozeQueue;
      const failSave = () => {
        if (row._jeabSnoozeUndo) {
          return;
        }
        row._jeabSnoozeHold = false;
        row._jeabSnoozeOk = false;
        row._jeabSnoozeCommitted = false;
        row._jeabSnoozeSaveStarted = false;
        row._jeabSnoozeSteps = [];
        if (form) {
          form._jeabParkBusy = false;
        }
        returnSnoozeRow(row);
        if (row._jeabSnoozeSnap) {
          restorePark(row, row._jeabSnoozeSnap);
        }
        const orig = row._jeabSnoozeOrig || {};
        if (row._jeabSnoozePosted) {
          postDueRaw(row, orig.dueAt != null ? orig.dueAt : '').catch(() => {});
        }
        hideSnoozeStatus(row, true);
        showReminderBar('Kunde inte spara.', false, 2800);
      };
      const finish = () => {
        if (row._jeabSnoozeUndo || !row._jeabSnoozeOk) {
          return;
        }
        const id = snoozeRowId(row);
        const session = id ? snoozeLive.get(id) : null;
        const current = (session && session.row && session.row.isConnected) ? session.row : row;
        dropSnoozeRow(current);
      };
      row._jeabSnoozeOk = true;
      finish();
      Promise.resolve(queued).then(() => {
        if (form) {
          form._jeabParkBusy = false;
        }
      }, failSave);
    };
    const leaveTimer = setTimeout(tick, wait);
    row._jeabSnoozeTimer = leaveTimer;
    armed.leaveTimer = leaveTimer;
  }

  function snoozeFormFor(row, step, fallback) {
    const stepInput = row.querySelector('[data-js="snooze"] input[name="by"][value="' + step + '"]');
    return (stepInput && stepInput.closest('form')) || fallback || null;
  }

  function paintSnoozeSaved(row, data) {
    if (data == null) {
      return false;
    }
    row._jeabSnoozePosted = true;
    if (row._jeabSnoozeUndo) {
      return data;
    }
    row._jeabSnoozeOk = true;
    if (data.due) {
      paintSnoozeDue(row, data);
    }
    const dueAt = data.row && data.row.due_at ? String(data.row.due_at) : '';
    if (dueAt) {
      row.setAttribute('data-due-at', dueAt);
    }
    paintSnoozeToastFromRow(row, data);
    return data;
  }

  /* Ett anrop med den sammanlagda tiden. Flera snooze-anrop läser samma due_at
     och sista skrivningen vinner med bara +2 h. */
  function postSnoozeTarget(row, dueAt, epoch) {
    const form = row.querySelector('[data-js="due"]');
    if (!form) {
      return Promise.reject(new Error('post'));
    }
    const stamped = (typeof jeabSnoozeDueStamp === 'function') ? jeabSnoozeDueStamp(dueAt) : String(dueAt || '');
    if (!stamped) {
      return Promise.reject(new Error('post'));
    }
    const sentEpoch = Number(epoch) || 0;
    const parked = snoozeUndoStore.get(snoozeRowId(row));
    const controller = (parked && parked.abort) || row._jeabSnoozeAbort || (typeof AbortController === 'function' ? new AbortController() : null);
    if (parked) {
      parked.abort = controller;
      parked.saveSent = true;
    }
    row._jeabSnoozeAbort = controller;
    row._jeabSnoozeSaveSent = true;
    const body = new FormData(form);
    body.delete('due_at');
    body.set('due_at', stamped);
    body.set('keep_clock', '0');
    return fetch(formPostUrl(form), {
      method: 'POST',
      body,
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    }).then((res) => res.json().then((data) => {
      if (!res.ok || !data || data.ok === false) {
        throw new Error((data && data.error) || 'Kunde inte spara.');
      }
      const saved = data.row && data.row.due_at != null ? String(data.row.due_at) : '';
      const savedStamp = (typeof jeabSnoozeDueStamp === 'function') ? jeabSnoozeDueStamp(saved) : saved;
      if (!savedStamp) {
        throw new Error('Tiden ändrades inte.');
      }
      const entry = snoozeUndoStore.get(snoozeRowId(row));
      const liveEpoch = entry && entry.guard ? Number(entry.guard.epoch) : Number(row._jeabSnoozeEpoch || 0);
      const decision = (entry && typeof jeabSnoozeGuard === 'function')
        ? jeabSnoozeGuard(entry.guard || { epoch: liveEpoch, phase: 'inflight', original: entry.dueAt, due: savedStamp }, { type: 'snooze-response', epoch: sentEpoch, due: savedStamp })
        : null;
      if (row._jeabSnoozeUndo || (entry && entry.undone) || (decision && decision.ignore) || (typeof jeabSnoozeEpochAllows === 'function' && !jeabSnoozeEpochAllows(sentEpoch, liveEpoch))) {
        return { ignored: true };
      }
      if (entry && decision) {
        entry.guard = decision;
      }
      return data;
    }));
  }

  function queueSnoozeSave(form, row) {
    const run = () => {
      const controller = typeof AbortController === 'function' ? new AbortController() : null;
      const early = snoozeUndoStore.get(snoozeRowId(row));
      if (early) {
        early.abort = controller;
      }
      row._jeabSnoozeAbort = controller;
      if (row._jeabSnoozeUndo || (early && early.undone) || (controller && controller.signal && controller.signal.aborted)) {
        return Promise.resolve(null);
      }
      const steps = (row._jeabSnoozeBatch || []).slice();
      row._jeabSnoozeBatch = [];
      const undone = snoozeUndoStore.get(snoozeRowId(row));
      if (undone && undone.undone) {
        return Promise.resolve(null);
      }
      const baseDue = (undone && undone.dueAt) || (row._jeabSnoozeOrig && row._jeabSnoozeOrig.dueAt) || '';
      const target = (steps.length && typeof jeabSnoozeStack === 'function')
        ? jeabSnoozeStack(baseDue, steps, new Date())
        : '';
      const anchor = row.querySelector('[data-js="due"]') || form;
      if (!anchor) {
        return Promise.resolve(null);
      }
      return flushInlineNear(anchor).then(() => {
        const parked = snoozeUndoStore.get(snoozeRowId(row));
        if (row._jeabSnoozeUndo || (parked && parked.undone)) {
          return null;
        }
        if (target) {
          const parkedNow = snoozeUndoStore.get(snoozeRowId(row));
          let sentEpoch = parkedNow && parkedNow.guard ? Number(parkedNow.guard.epoch) : 0;
          if (parkedNow && typeof jeabSnoozeGuard === 'function') {
            const decision = jeabSnoozeGuard(parkedNow.guard || { epoch: 0, phase: 'holding', original: parkedNow.dueAt, due: parkedNow.dueAt }, { type: 'snooze-send', due: target });
            if (decision.ignore || decision.post !== 'snooze') {
              return null;
            }
            parkedNow.guard = decision;
            sentEpoch = decision.epoch;
          }
          return postSnoozeTarget(row, target, sentEpoch).then((data) => {
            if (data && data.ignored) {
              return data;
            }
            const again = snoozeUndoStore.get(snoozeRowId(row));
            if (row._jeabSnoozeUndo || (again && again.undone)) {
              return { ignored: true };
            }
            const painted = paintSnoozeSaved(row, data);
            if (painted === false) {
              throw new Error('post');
            }
            return painted;
          }).catch((err) => {
            if (err && err.name === 'AbortError') {
              return { ignored: true };
            }
            throw err;
          });
        }
        const forms = [];
        if (steps.length) {
          steps.forEach((step) => {
            const stepForm = snoozeFormFor(row, step, form);
            if (stepForm) {
              forms.push(stepForm);
            }
          });
        } else if (form) {
          forms.push(form);
        }
        let chain = Promise.resolve(null);
        forms.forEach((stepForm) => {
          chain = chain.then((prev) => {
            const again = snoozeUndoStore.get(snoozeRowId(row));
            if (prev === false || row._jeabSnoozeUndo || (again && again.undone)) {
              return prev === false ? false : null;
            }
            return postInPlace(stepForm).then((data) => paintSnoozeSaved(row, data));
          });
        });
        return chain.then((data) => {
          if (data === false) {
            throw new Error('post');
          }
          return data;
        });
      });
    };
    row._jeabSnoozeQueue = Promise.resolve(row._jeabSnoozeQueue)
      .catch(() => {})
      .then(run);
    const parked = snoozeUndoStore.get(snoozeRowId(row));
    if (parked) {
      parked.queue = row._jeabSnoozeQueue;
    }
    return row._jeabSnoozeQueue;
  }

  function paintSnoozeToastFromRow(row, data) {
    const dueAt = data && data.row && data.row.due_at ? String(data.row.due_at) : '';
    const today = data && data.today ? String(data.today) : '';
    if (!dueAt || typeof jeabSnoozeDueLabel !== 'function') {
      return;
    }
    const label = jeabSnoozeDueLabel(dueAt, today);
    if (!label || !snoozeToast || snoozeToast.hidden) {
      return;
    }
    const id = snoozeRowId(row);
    const entry = snoozeUndoStore.get(id);
    if (entry && entry.undone) {
      return;
    }
    if (snoozeToast._jeabUndoId && id && snoozeToast._jeabUndoId !== id) {
      return;
    }
    const pretty = String((row && row._jeabSnoozePretty) || '');
    const text = pretty ? ('Knuffad ' + pretty + ' · ' + label) : label;
    const boxLabel = snoozeToast.querySelector('.item-snooze-status-text');
    if (boxLabel) {
      boxLabel.textContent = text;
    }
  }

  function hideSnoozeToast() {
    if (snoozeToast) {
      snoozeToast.hidden = true;
    }
    document.documentElement.classList.remove('is-snooze-toast-on');
  }

  function hideSnoozeStatus(row, force) {
    if (!force && row && !row._jeabSnoozeUndo && row._jeabSnoozeUntil && Date.now() < row._jeabSnoozeUntil) {
      return;
    }
    hideSnoozeToast();
    const box = row && row._jeabSnoozeStatus;
    if (box && box !== snoozeToast) {
      box.hidden = true;
    }
  }

  function snoozeToastBox() {
    if (snoozeToast && snoozeToast.isConnected) {
      return snoozeToast;
    }
    const box = document.createElement('div');
    box.className = 'item-snooze-status crm-undo is-floating';
    box.setAttribute('role', 'status');
    const label = document.createElement('span');
    label.className = 'item-snooze-status-text crm-undo-text';
    const undo = document.createElement('button');
    undo.type = 'button';
    undo.className = 'item-snooze-undo crm-undo-btn';
    undo.textContent = 'Ångra';
    const pressUndo = (event) => {
      event.preventDefault();
      event.stopPropagation();
      undoSnoozeId(box._jeabUndoId);
    };
    undo.addEventListener('pointerup', (event) => {
      if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
      }
      pressUndo(event);
    });
    undo.addEventListener('click', pressUndo);
    box.addEventListener('pointerdown', (event) => {
      event.stopPropagation();
    });
    box.appendChild(label);
    box.appendChild(undo);
    (document.body || document.documentElement).appendChild(box);
    snoozeToast = box;
    return box;
  }

  function showSnoozeStatus(row, text) {
    const box = snoozeToastBox();
    const id = snoozeRowId(row);
    box._jeabUndoId = id;
    row._jeabSnoozeStatus = box;
    box.style.zIndex = '80';
    box.style.pointerEvents = 'auto';
    box.style.touchAction = 'manipulation';
    const label = box.querySelector('.item-snooze-status-text');
    if (label) {
      label.textContent = text;
    }
    box.hidden = false;
    document.documentElement.classList.add('is-snooze-toast-on');
  }

  function postDueRaw(row, dueAt) {
    const form = row.querySelector('[data-js="due"]');
    if (!form) {
      return Promise.resolve();
    }
    const body = new FormData(form);
    const stamped = (typeof jeabSnoozeDueStamp === 'function') ? jeabSnoozeDueStamp(dueAt) : String(dueAt || '');
    body.delete('due_at');
    body.delete('completed_at');
    body.set('due_at', stamped !== '' ? stamped : (dueAt == null ? '' : String(dueAt)));
    return fetch(formPostUrl(form), {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (!res.ok) {
        throw new Error('post');
      }
    });
  }

  function undoSnooze(row) {
    undoSnoozeId(snoozeRowId(row));
  }

  function undoSnoozeId(id) {
    const key = String(id || '');
    const entry = snoozeUndoStore.get(key);
    const now = Date.now();
    const inList = !!document.querySelector('.apple-list .item[data-id="' + key + '"]');
    const plan = (typeof jeabSnoozeUndoPlan === 'function')
      ? jeabSnoozeUndoPlan(entry, now, inList)
      : null;
    const live = plan
      ? plan.act === 'restore'
      : ((typeof jeabSnoozeUndoLive === 'function')
        ? jeabSnoozeUndoLive(entry, now)
        : !!(entry && !entry.undone && now <= entry.until));
    if (!live) {
      return;
    }
    if (plan && plan.dueAt) {
      entry.dueAt = plan.dueAt;
    }
    const session = snoozeLive.get(key);
    const row = document.querySelector('.apple-list .item[data-id="' + key + '"]')
      || (session && session.row)
      || null;
    const prior = entry.guard || {
      epoch: 0,
      phase: 'holding',
      original: entry.dueAt,
      due: entry.dueAt,
    };
    let phase = String(prior.phase || 'holding');
    if (row && row._jeabSnoozePosted) {
      phase = 'saved';
    } else if (phase !== 'saved' && ((row && row._jeabSnoozeSaveStarted) || entry.saveStarted)) {
      phase = 'inflight';
    }
    const decision = (typeof jeabSnoozeGuard === 'function')
      ? jeabSnoozeGuard({
        epoch: prior.epoch,
        phase: phase,
        original: prior.original || entry.dueAt,
        due: prior.due || entry.dueAt,
      }, { type: 'undo' })
      : { epoch: (Number(prior.epoch) || 0) + 1, phase: 'restoring', post: 'undo', due: entry.dueAt, original: entry.dueAt, ignore: false };
    entry.guard = decision;
    entry.undone = true;
    entry.dueAt = decision.due || entry.dueAt;
    entry._jeabUndoEpoch = decision.epoch;
    if (session) {
      entry.list = session.list || entry.list || null;
      entry.next = session.next || null;
      if (session.index != null) {
        entry.index = session.index;
      }
      if (session.leaveTimer) {
        clearTimeout(session.leaveTimer);
        session.leaveTimer = 0;
      }
    }
    if (row) {
      row._jeabSnoozeUndo = true;
      row._jeabSnoozeEpoch = decision.epoch;
      row._jeabSnoozeHold = false;
      row._jeabSnoozeCommitted = false;
      row._jeabSnoozeSteps = [];
      row._jeabSnoozeBatch = [];
      if (row._jeabSnoozeTimer) {
        clearTimeout(row._jeabSnoozeTimer);
        row._jeabSnoozeTimer = 0;
      }
      if (row._jeabSnoozeUndoTimer) {
        clearTimeout(row._jeabSnoozeUndoTimer);
        row._jeabSnoozeUndoTimer = 0;
      }
    }
    const abort = entry.abort || (row && row._jeabSnoozeAbort) || null;
    if (!entry.saveSent && !(row && row._jeabSnoozeSaveSent) && abort && typeof abort.abort === 'function') {
      abort.abort();
    }
    window.clearTimeout(snoozeUndoTimer);
    snoozeUndoTimer = 0;
    hideSnoozeToast();
    snoozeHidden.delete(key);
    const placed = reinsertSnoozeCard(entry);
    if (placed) {
      placed._jeabSnoozeUndo = true;
      placed._jeabSnoozeEpoch = decision.epoch;
      placed._jeabSnoozeRestore = decision.post === 'undo';
      placed._jeabSnoozeHold = false;
      placed._jeabSnoozeLeft = false;
      if (decision.due) {
        placed.setAttribute('data-due-at', decision.due);
      }
    }
    snoozeLive.delete(key);
    if (decision.post !== 'undo') {
      return;
    }
    const queued = (row && row._jeabSnoozeQueue) || entry.queue;
    Promise.resolve(queued).catch(() => {}).then(() => {
      if (!entry.guard || entry.guard.epoch !== decision.epoch) {
        return null;
      }
      return postUndoEntry(entry);
    }).then((data) => {
      if (!data || data.ignored) {
        return;
      }
      if (entry.guard && typeof jeabSnoozeGuard === 'function') {
        const applied = jeabSnoozeGuard(entry.guard, { type: 'undo-response', epoch: decision.epoch, due: entry.dueAt });
        if (!applied.ignore) {
          entry.guard = applied;
        }
      }
      const list = document.querySelector('.apple-list');
      const current = list ? list.querySelector('.item[data-id="' + key + '"]') : null;
      if (current) {
        current._jeabSnoozeRestore = false;
      }
    }).catch(() => {
      showReminderBar('Kunde inte spara.', false, 2800);
    });
  }

  let jeabTagPointerAt = 0;

  function bindTagForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    const btn = form.querySelector('button[type="submit"], .tag-chip');
    if (btn) {
      btn.addEventListener('mousedown', (event) => {
        jeabTagPointerAt = Date.now();
        event.stopPropagation();
      });
    }
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (!btn || form._jeabTagBusy) {
        return;
      }
      const wasOn = btn.classList.contains('is-on');
      const row = form.closest('.apple-list .item');
      const rowWasHidden = !!(row && row.hidden);
      const tagInput = form.querySelector('input[name="tag"]');
      const tagName = tagInput ? String(tagInput.value || '') : '';
      const tagMode = new URLSearchParams(location.search).get('tag') || 'none';
      const turningOn = !wasOn;
      const wouldHide = turningOn && isTodayView() && (tagMode === 'none' || tagMode === '' || (tagMode !== 'all' && tagMode !== tagName));
      btn.classList.toggle('is-on');
      form._jeabTagBusy = true;
      flushInlineNear(form).then(() => postInPlace(form)).then((data) => {
        form._jeabTagBusy = false;
        if (data && data.warn) {
          showReminderBar(String(data.warn), false, 6000);
        }
        if (wouldHide && tagName) {
          const url = new URL(location.href);
          url.searchParams.set('v', 'today');
          url.searchParams.set('tag', tagName);
          url.searchParams.delete('q');
          softNavigateReminders(url.pathname + url.search);
        }
      }).catch((error) => {
        form._jeabTagBusy = false;
        btn.classList.toggle('is-on', wasOn);
        if (row && !rowWasHidden) {
          restoreTodayRow(row);
        }
        if (error && error.message === 'csrf') {
          return;
        }
        showReminderBar((error && error.message) || 'Kunde inte spara.', false, 2800);
      });
    });
  }

  document.querySelectorAll('[data-js="tag"]').forEach(bindTagForm);

  function writeDueChip(el, label) {
    const text = String(label || '');
    if (!el.closest('.item-shut-meta')) {
      el.textContent = text;
      return;
    }
    const match = text.match(/^(.*?)(\s\d{2}:\d{2})$/);
    if (!match) {
      el.textContent = text;
      return;
    }
    el.textContent = '';
    el.appendChild(document.createTextNode(match[1]));
    const clock = document.createElement('span');
    clock.className = 'item-due-clock';
    clock.textContent = match[2];
    el.appendChild(clock);
  }

  function shutDueText(data, chipLabel) {
    const raw = data && data.due_value ? String(data.due_value).slice(0, 10) : '';
    const parts = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const clock = String(chipLabel || '').match(/(\d{2}:\d{2})$/);
    const date = parts
      ? String(Number(parts[3])) + '/' + String(Number(parts[2]))
      : String(chipLabel || '').replace(/\s\d{2}:\d{2}$/, '');
    return clock ? date + ' ' + clock[1] : date;
  }

  function paintSnoozeDue(row, data) {
    const chipLabel = data && data.due;
    const footLabel = (data && data.due_contact) || chipLabel;
    if (!row || !chipLabel) {
      return;
    }
    const due = row.querySelector('.due');
    if (due) {
      due.textContent = chipLabel;
      due.classList.toggle('is-empty', !chipLabel);
    }
    row.querySelectorAll('.item-due-chip').forEach((el) => {
      writeDueChip(el, el.closest('.item-shut-meta') ? shutDueText(data, chipLabel) : chipLabel);
    });
    const dueBox = row.querySelector('.item-due');
    if (dueBox) {
      dueBox.classList.toggle('is-late', !!data.due_late);
    }
    const input = row.querySelector('.due-input');
    if (input && data.due_value) {
      if (input.type !== 'date') {
        input.type = 'date';
      }
      const dateValue = String(data.due_value).slice(0, 10);
      const dueForm = input.closest('[data-js="due"]');
      if (dueForm && typeof dueForm._jeabCommitDue === 'function') {
        dueForm._jeabCommitDue(dateValue);
      } else {
        input.value = dateValue;
      }
    }
    if (!footLabel) {
      return;
    }
    const extra = row.querySelector('.item-extra') || row;
    let box = extra.querySelector('.item-created');
    if (!box) {
      const foot = extra.querySelector('.item-foot') || extra;
      box = document.createElement('p');
      box.className = 'item-created';
      foot.appendChild(box);
    }
    box.hidden = false;
    let dueLabel = box.querySelector('.item-due-label');
    if (!dueLabel) {
      dueLabel = document.createElement('span');
      dueLabel.className = 'item-due-label';
      dueLabel.setAttribute('aria-label', 'Kontaktas');
      box.appendChild(dueLabel);
    }
    dueLabel.textContent = footLabel;
  }

  function capturePark(row) {
    return {
      dues: [...row.querySelectorAll('.due')].map((el) => ({
        text: el.textContent,
        empty: el.classList.contains('is-empty'),
      })),
      chips: [...row.querySelectorAll('.item-due-chip')].map((el) => el.textContent),
      late: !!(row.querySelector('.item-due') && row.querySelector('.item-due').classList.contains('is-late')),
      input: row.querySelector('.due-input') ? row.querySelector('.due-input').value : '',
      dueAt: row.getAttribute('data-due-at') || '',
      completedAt: row.getAttribute('data-completed-at') || '',
      hidden: row.hidden,
      display: row.style.display,
      maxHeight: row.style.maxHeight,
      overflow: row.style.overflow,
      boxSizing: row.style.boxSizing,
      className: row.className,
    };
  }

  function restorePark(row, snap) {
    row._jeabParkGen = (row._jeabParkGen || 0) + 1;
    row.querySelectorAll('.due').forEach((el, index) => {
      const one = snap.dues[index];
      if (!one) {
        return;
      }
      el.textContent = one.text;
      el.classList.toggle('is-empty', one.empty);
    });
    row.querySelectorAll('.item-due-chip').forEach((el, index) => {
      if (snap.chips[index] != null) {
        writeDueChip(el, snap.chips[index]);
      }
    });
    const dueBox = row.querySelector('.item-due');
    if (dueBox) {
      dueBox.classList.toggle('is-late', snap.late);
    }
    const input = row.querySelector('.due-input');
    if (input) {
      input.value = snap.input;
    }
    if (snap.dueAt) {
      row.setAttribute('data-due-at', snap.dueAt);
    } else {
      row.removeAttribute('data-due-at');
    }
    if (snap.completedAt) {
      row.setAttribute('data-completed-at', snap.completedAt);
    } else {
      row.setAttribute('data-completed-at', '');
    }
    row.className = snap.className;
    row.hidden = snap.hidden;
    row.style.display = snap.display;
    row.style.maxHeight = snap.maxHeight;
    row.style.overflow = snap.overflow;
    row.style.boxSizing = snap.boxSizing;
    row._jeabSnoozeLeaving = false;
    row._jeabSnoozeLeft = false;
    row._jeabSnoozeOk = false;
    if (row._jeabSnoozeTimer) {
      clearTimeout(row._jeabSnoozeTimer);
      row._jeabSnoozeTimer = 0;
    }
  }

  function bindSnoozeForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
      btn.addEventListener('mousedown', (event) => {
        event.stopPropagation();
      });
      btn.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse' && event.button !== 0) {
          return;
        }
        event.stopPropagation();
        btn._jeabSnoozePtr = event.pointerId;
      });
      btn.addEventListener('pointercancel', () => {
        btn._jeabSnoozePtr = null;
      });
      btn.addEventListener('pointerup', (event) => {
        if (btn._jeabSnoozePtr !== event.pointerId) {
          return;
        }
        btn._jeabSnoozePtr = null;
        if (event.pointerType === 'mouse' && event.button !== 0) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (!acceptSnoozeGesture('pointerup')) {
          return;
        }
        applySnoozeTap();
      });
    }
    function acceptSnoozeGesture(eventName) {
      if (typeof jeabSnoozeTapCounts !== 'function') {
        return eventName === 'submit';
      }
      const verdict = jeabSnoozeTapCounts(form._jeabSnoozeTap, eventName, Date.now());
      form._jeabSnoozeTap = verdict.state;
      return !!verdict.count;
    }
    function applySnoozeTap() {
      const row = form.closest('.apple-list .item');
      if (!row || row._jeabSnoozeLeaving || row._jeabSnoozeCommitted || form._jeabParkBusy) {
        return;
      }
      const byInput = form.querySelector('input[name="by"]');
      const by = byInput ? String(byInput.value || '') : '';
      if (by !== '2h' && by !== '2d' && by !== '1w') {
        return;
      }
      const continuing = !!(row._jeabSnoozeHold && !row._jeabSnoozeUndo && row._jeabSnoozeSnap);
      if (!continuing) {
        row._jeabSnoozeSteps = [];
        row._jeabSnoozeDueRaw = row.getAttribute('data-due-at') || '';
        row._jeabSnoozeGen = (row._jeabSnoozeGen || 0) + 1;
        const snap = capturePark(row);
        row._jeabSnoozeOrig = {
          dueAt: snap.dueAt,
          completedAt: snap.completedAt,
        };
        row._jeabSnoozeSnap = snap;
        row._jeabSnoozePosted = false;
        row._jeabSnoozeSaveStarted = false;
        row._jeabSnoozeOk = false;
        rememberSnoozeUndo(row, true);
      } else {
        rememberSnoozeUndo(row, false);
      }
      row._jeabSnoozeUndo = false;
      row._jeabSnoozeHold = true;
      row._jeabSnoozeBy = by;
      row._jeabSnoozeSteps = row._jeabSnoozeSteps || [];
      row._jeabSnoozeSteps.push(by);
      const baseDue = (row._jeabSnoozeOrig && row._jeabSnoozeOrig.dueAt) || row._jeabSnoozeDueRaw || '';
      const stacked = (typeof jeabSnoozeStack === 'function')
        ? jeabSnoozeStack(baseDue, row._jeabSnoozeSteps, new Date())
        : '';
      const advanced = (typeof jeabSnoozeAdvance === 'function')
        ? jeabSnoozeAdvance(by, new Date(), row._jeabSnoozeDueRaw || baseDue)
        : null;
      const stamp = stacked || (advanced && advanced.stamp) || '';
      let label = advanced && advanced.label ? advanced.label : '';
      if (stamp && (by === '2d' || by === '1w')) {
        label = Number(stamp.slice(8, 10)) + '/' + Number(stamp.slice(5, 7));
      } else if (by === '2h' && stamp && typeof jeabSnoozeDueLabel === 'function') {
        const today = (typeof jeabStockholmToday === 'function') ? jeabStockholmToday(new Date()) : '';
        const fromStack = jeabSnoozeDueLabel(stamp, today);
        if (fromStack) {
          label = fromStack;
        }
      }
      if (!label && typeof jeabParkDueLabel === 'function') {
        label = jeabParkDueLabel(by, new Date(), row._jeabSnoozeDueRaw || '');
      }
      if (stamp) {
        row._jeabSnoozeDueRaw = stamp;
        row.setAttribute('data-due-at', stamp);
      }
      if (label) {
        paintSnoozeDue(row, {
          due: label,
          due_contact: label,
          due_late: false,
          due_value: stamp ? stamp.slice(0, 10) : (advanced && advanced.date ? advanced.date : ''),
        });
      }
      const pretty = btn ? String(btn.textContent || '').trim() : by;
      row._jeabSnoozePretty = pretty;
      showSnoozeStatus(row, 'Knuffad ' + pretty);
      row._jeabSnoozeForm = form;
      scheduleSnoozeLeave(row);
      ensureSnoozeHoldRow(row);
    }
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (!acceptSnoozeGesture('submit')) {
        return;
      }
      applySnoozeTap();
    });
  }

  document.querySelectorAll('[data-js="snooze"]').forEach(bindSnoozeForm);

  function paintWho(row, btn) {
    const wanted = btn ? String(btn.textContent || '').replace(/\s+/g, ' ').trim() : '';
    const roots = [];
    const push = (node) => {
      if (node && roots.indexOf(node) === -1) {
        roots.push(node);
      }
    };
    push(row);
    push(currentOpenRow());
    document.querySelectorAll('[data-js="pam-side-body"]').forEach(push);
    roots.forEach((root) => {
      root.querySelectorAll('.item-who-chip').forEach((chip) => {
        const label = String(chip.textContent || '').replace(/\s+/g, ' ').trim();
        const on = !!btn && (chip === btn || (wanted !== '' && label === wanted));
        chip.classList.toggle('is-on', on);
        chip.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    });
  }

  function bindWhoForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    const btn = form.querySelector('button[type="submit"], .item-who-chip');
    if (btn) {
      btn.addEventListener('mousedown', (event) => {
        event.preventDefault();
        event.stopPropagation();
      });
    }
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const row = form.closest('.apple-list .item') || currentOpenRow() || form.closest('.pam-side');
      if (!row || !btn || form._jeabWhoBusy || btn.classList.contains('is-on')) {
        return;
      }
      const prev = row.querySelector('.item-who-chip.is-on');
      paintWho(row, btn);
      form._jeabWhoBusy = true;
      flushInlineNear(form).then(() => postInPlace(form)).then(() => {
        form._jeabWhoBusy = false;
      }).catch(() => {
        form._jeabWhoBusy = false;
        paintWho(row, prev);
        showReminderBar('Kunde inte spara.', false, 2800);
      });
    });
  }

  function bindToggleTags(btn) {
    if (!btn || btn._jeabBound) {
      return;
    }
    btn._jeabBound = true;
    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const foot = btn.closest('.item-foot');
      const panel = foot ? foot.querySelector('.item-more-panel') : null;
      if (!panel) {
        return;
      }
      const open = panel.hasAttribute('hidden');
      if (open) {
        panel.removeAttribute('hidden');
      } else {
        panel.setAttribute('hidden', '');
      }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  function bindFlagForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    bindInPlace(form, (data) => {
      const row = form.closest('.apple-list .item');
      const on = !!(data && data.row && (data.row.flagged === true || data.row.flagged === 1 || data.row.flagged === '1'));
      if (row) {
        row.classList.toggle('is-flagged', on);
        row.querySelectorAll('.flag-btn').forEach((btn) => {
          btn.classList.toggle('is-on', on);
          btn.setAttribute('aria-label', on ? 'Ta bort flagga' : 'Flagga');
        });
      }
    }, { skipFlush: true });
  }

  document.querySelectorAll('[data-js="who"]').forEach(bindWhoForm);
  document.querySelectorAll('[data-js="toggle-tags"]').forEach(bindToggleTags);
  document.querySelectorAll('[data-js="flag"]').forEach(bindFlagForm);

  function hideJeabNavSpinner() {
    const overlay = document.getElementById('jeab-nav-spinner');
    if (overlay) {
      window.clearTimeout(overlay._jeabTimer);
      overlay.hidden = true;
    }
    document.documentElement.classList.remove('is-nav-loading');
  }

  function showJeabNavSpinner(label, onCancel) {
    let overlay = document.getElementById('jeab-nav-spinner');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'jeab-nav-spinner';
      overlay.className = 'jeab-nav-spinner';
      overlay.setAttribute('role', 'status');
      overlay.innerHTML = '<div class="jeab-nav-spinner-card"><span class="jeab-nav-spinner-ring" aria-hidden="true"></span><span class="jeab-nav-spinner-label"></span><button type="button" class="jeab-nav-spinner-cancel tap44">Avbryt</button></div>';
      document.body.appendChild(overlay);
    }
    const text = overlay.querySelector('.jeab-nav-spinner-label');
    if (text) {
      text.textContent = label || 'Laddar…';
    }
    let cancel = overlay.querySelector('.jeab-nav-spinner-cancel');
    if (!cancel) {
      const card = overlay.querySelector('.jeab-nav-spinner-card') || overlay;
      cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'jeab-nav-spinner-cancel tap44';
      cancel.textContent = 'Avbryt';
      card.appendChild(cancel);
    }
    if (cancel) {
      cancel.onclick = (event) => {
        event.preventDefault();
        event.stopPropagation();
        hideJeabNavSpinner();
        if (typeof onCancel === 'function') {
          onCancel();
        }
      };
    }
    overlay.hidden = false;
    document.documentElement.classList.add('is-nav-loading');
    window.clearTimeout(overlay._jeabTimer);
    overlay._jeabTimer = window.setTimeout(() => {
      hideJeabNavSpinner();
      if (typeof onCancel === 'function') {
        onCancel();
      }
      showReminderBar('Det tog för lång tid. Försök igen.', false, 3200);
    }, 20000);
  }

  function bindReminderBook(link) {
    if (!link || link._jeabBound) {
      return;
    }
    link._jeabBound = true;
    link.addEventListener('click', () => {
      showJeabNavSpinner('Öppnar kalender…');
    });
  }

  function askVunnenDrop() {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'jeab-vunnen-ask';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.setAttribute('aria-labelledby', 'jeab-vunnen-ask-title');
      overlay.innerHTML = '<div class="jeab-vunnen-ask-card"><p id="jeab-vunnen-ask-title">#vunnen tas bort. Order, Lantmäteriet-uppgifter och bokning står kvar om du behåller dem.</p><div class="jeab-vunnen-ask-actions"><button type="button" class="jeab-vunnen-ask-keep">Behåll</button><button type="button" class="jeab-vunnen-ask-drop">Ta bort</button></div></div>';
      const finish = (choice) => {
        document.removeEventListener('keydown', onKey);
        overlay.remove();
        resolve(choice);
      };
      const onKey = (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          finish('abort');
        }
      };
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay) {
          finish('abort');
        }
      });
      overlay.querySelector('.jeab-vunnen-ask-keep').addEventListener('click', () => finish('keep'));
      overlay.querySelector('.jeab-vunnen-ask-drop').addEventListener('click', () => finish('drop'));
      document.body.appendChild(overlay);
      document.addEventListener('keydown', onKey);
      const keep = overlay.querySelector('.jeab-vunnen-ask-keep');
      if (keep) {
        keep.focus();
      }
    });
  }

  function paintVunnenRefusal(form, message) {
    const text = String(message || '').trim();
    if (!form || text === '') {
      return;
    }
    let note = form.querySelector('.vunnen-need');
    if (!note) {
      note = document.createElement('p');
      note.className = 'vunnen-need';
      form.appendChild(note);
    }
    note.hidden = false;
    note.removeAttribute('hidden');
    note.setAttribute('role', 'alert');
    note.textContent = '';
    note.textContent = text;
    form.setAttribute('data-vunnen-need', text);
  }

  function bindVunnenForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (form.getAttribute('data-busy') === '1' || form.getAttribute('data-ask') === '1') {
        return;
      }
      const btn = form.querySelector('.tag-chip');
      const turningOff = !!(btn && btn.classList.contains('is-on'));
      const needText = form.getAttribute('data-vunnen-need')
        || ((form.querySelector('.vunnen-need') || {}).textContent || '');
      const vunnenPlan = (typeof jeabVunnenClickPlan === 'function')
        ? jeabVunnenClickPlan(needText, turningOff)
        : { action: turningOff ? 'ask' : 'purchase' };
      if (vunnenPlan.action === 'message') {
        const refusal = String(vunnenPlan.message || 'Ordern skapades inte.');
        paintVunnenRefusal(form, refusal);
        showReminderBar(refusal, false, 6000);
        return;
      }
      const startSend = (dropRelated) => {
      form.setAttribute('data-busy', '1');
      if (btn) {
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
      }
      let ctrl = null;
      const release = () => {
        form.removeAttribute('data-busy');
        if (btn) {
          btn.removeAttribute('aria-busy');
          btn.disabled = false;
        }
        hideJeabNavSpinner();
      };
      if (!turningOff && typeof AbortController === 'function') {
        ctrl = new AbortController();
        showJeabNavSpinner('Hämtar fastighet och ägare från Lantmäteriet…', () => {
          if (ctrl) {
            ctrl.abort();
          }
          release();
        });
      }
      const body = new FormData(form);
      if (turningOff) {
        body.set('drop_related', dropRelated ? '1' : '0');
      }
      const sent = fetch(formPostUrl(form), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        signal: ctrl ? ctrl.signal : undefined,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
          Accept: 'application/json',
        },
      }).then((res) => {
        if (res.status === 419) {
          showReloadNotice();
          throw new Error('csrf');
        }
        return res.json().catch(() => ({})).then((data) => {
          if (!res.ok || data.ok === false) {
            throw new Error((data && data.error) || 'Kunde inte spara.');
          }
          return data;
        });
      });
      sent.then((data) => {
        release();
        if (btn) {
          btn.classList.toggle('is-on', !turningOff);
        }
        if (data && data.warn) {
          showReminderBar(String(data.warn), false, 6000);
        }
        if (data && data.next) {
          location.href = String(data.next);
        }
      }).catch((error) => {
        if (error && error.name === 'AbortError') {
          return;
        }
        release();
        if (error && error.message === 'csrf') {
          return;
        }
        const refusal = (error && error.message) || 'Kunde inte spara.';
        paintVunnenRefusal(form, refusal);
        showReminderBar(refusal, false, 6000);
      });
      };
      if (!turningOff) {
        startSend(false);
        return;
      }
      form.setAttribute('data-ask', '1');
      askVunnenDrop().then((choice) => {
        form.removeAttribute('data-ask');
        if (choice === 'abort') {
          return;
        }
        startSend(choice === 'drop');
      });
    });
  }

  function tapTotalLabel(rec, total) {
    const mail = parseInt(rec.mailat, 10) || 0;
    if (mail === total) {
      return total + ' mail';
    }
    const names = [['ringt', 'ringda'], ['pratat', 'samtal'], ['mailat', 'mail'], ['smsat', 'sms']];
    const parts = [];
    names.forEach((pair) => {
      const n = parseInt(rec[pair[0]], 10) || 0;
      if (n > 0) {
        parts.push(n + ' ' + pair[1]);
      }
    });
    return parts.join(', ') || (total + ' kontakter');
  }

  function paintTapResponse(form, data) {
      const row = form.closest('.apple-list .item');
      const rec = data && data.row ? data.row : null;
      if (!row || !rec) {
        return;
      }
      ['ringt', 'pratat', 'mailat', 'smsat'].forEach((key) => {
        const n = row.querySelector('[data-tap="' + key + '"]');
        if (!n) {
          return;
        }
        const count = parseInt(rec[key], 10) || 0;
        n.textContent = count > 0 ? String(count) : '';
        n.hidden = count <= 0;
      });
      const total = data && typeof data.tap_total === 'number'
        ? data.tap_total
        : ['ringt', 'pratat', 'mailat', 'smsat'].reduce((sum, key) => {
          return sum + (parseInt(rec[key], 10) || 0);
        }, 0);
      const heading = data && data.heading ? String(data.heading) : '';
      if (heading) {
        row.querySelectorAll('a.item-main .item-title').forEach((el) => {
          el.textContent = heading;
        });
      }
      const paintTapTotal = (el, inShutMeta) => {
        el.textContent = total > 0 ? String(total) : '';
        el.hidden = total <= 0;
        if (!inShutMeta) {
          return;
        }
        const label = (data && data.tap_label) ? String(data.tap_label) : tapTotalLabel(rec, total);
        el.setAttribute('title', label);
        el.setAttribute('aria-label', label);
      };
      row.querySelectorAll('.item-tap-total').forEach((el) => {
        paintTapTotal(el, !!el.closest('.item-shut-meta'));
      });
      if (total > 0) {
        row.querySelectorAll('.item-title-row, .item-name-row').forEach((wrap) => {
          if (wrap.querySelector('.item-tap-total')) {
            return;
          }
          const totalEl = document.createElement('span');
          totalEl.className = 'item-tap-total';
          totalEl.textContent = String(total);
          wrap.appendChild(totalEl);
        });
        row.querySelectorAll('.item-shut-meta').forEach((wrap) => {
          if (wrap.querySelector('.item-tap-total')) {
            return;
          }
          const totalEl = document.createElement('span');
          totalEl.className = 'item-tap-total';
          paintTapTotal(totalEl, true);
          const call = wrap.querySelector('.item-call');
          if (call) {
            wrap.insertBefore(totalEl, call);
          } else {
            wrap.appendChild(totalEl);
          }
        });
      }
  }

  const TAP_DEBOUNCE_MS = 450;
  const TAP_UNDO_MS = 6000;

  function bindTapForm(form) {
    if (!form || form._jeabBound) {
      return;
    }
    form._jeabBound = true;
    form.addEventListener('click', (event) => {
      event.stopPropagation();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const now = Date.now();
      if (form._jeabTapLock && now < form._jeabTapLock) {
        return;
      }
      if (form._jeabTapPending && !form._jeabTapPending.sent) {
        return;
      }
      form._jeabTapLock = now + TAP_DEBOUNCE_MS;
      const row = form.closest('.apple-list .item');
      const field = form.querySelector('input[name="field"]');
      const key = field ? String(field.value || '') : '';
      const badge = row ? row.querySelector('[data-tap="' + key + '"]') : null;
      const before = badge ? { text: badge.textContent, hidden: badge.hidden } : null;
      const totals = row ? [...row.querySelectorAll('.item-tap-total')].map((el) => ({
        el,
        text: el.textContent,
        hidden: el.hidden,
        title: el.getAttribute('title'),
        label: el.getAttribute('aria-label'),
      })) : [];
      if (badge) {
        const count = (parseInt(badge.textContent, 10) || 0) + 1;
        badge.textContent = String(count);
        badge.hidden = false;
      }
      if (row) {
        const nextTotal = (totals.length ? totals.reduce((sum, one) => Math.max(sum, parseInt(one.text, 10) || 0), 0) : 0) + 1;
        const paint = (el) => {
          el.textContent = String(nextTotal);
          el.hidden = false;
        };
        if (totals.length) {
          totals.forEach((one) => paint(one.el));
        }
      }
      const rollback = () => {
        if (badge && before) {
          badge.textContent = before.text;
          badge.hidden = before.hidden;
        }
        totals.forEach((one) => {
          one.el.textContent = one.text;
          one.el.hidden = one.hidden;
          if (one.title != null) {
            one.el.setAttribute('title', one.title);
          }
          if (one.label != null) {
            one.el.setAttribute('aria-label', one.label);
          }
        });
      };
      const labels = { ringt: 'Ringda', mailat: 'Mailat', smsat: 'Smsat', pratat: 'Samtal' };
      /* N-1: stämpeln sparas direkt. Ångra tar bort den på servern igen (reminder.untap). */
      const pending = { form, sent: false, undone: false, timer: 0, req: null };
      form._jeabTapPending = pending;
      const commitTap = () => {
        if (pending.undone || pending.sent) {
          return;
        }
        pending.sent = true;
        pending.req = postInPlace(form).then((data) => {
          if (!pending.undone) {
            paintTapResponse(form, data);
          }
          return true;
        }).catch((error) => {
          rollback();
          if (error && error.message === 'csrf') {
            return false;
          }
          showReminderBar('Kunde inte spara.', false, 2800);
          return false;
        });
      };
      commitTap();
      showReminderBar(labels[key] || 'Kontakt', true, TAP_UNDO_MS, () => {
        if (pending.undone) {
          return;
        }
        pending.undone = true;
        hideReminderBar();
        rollback();
        if (form._jeabTapPending === pending) {
          form._jeabTapPending = null;
        }
        Promise.resolve(pending.req).then((saved) => {
          if (!saved) {
            return;
          }
          const actionEl = form.querySelector('input[name="action"]');
          const prev = actionEl ? actionEl.value : '';
          if (actionEl) {
            actionEl.value = 'reminder.untap';
          }
          const req = postInPlace(form);
          if (actionEl) {
            actionEl.value = prev;
          }
          return req.then((data) => {
            paintTapResponse(form, data);
          });
        }).catch((error) => {
          if (error && error.message === 'csrf') {
            return;
          }
          showReminderBar((error && error.message) || 'Kunde inte ångra.', false, 2800);
        });
      });
    });
  }

  document.querySelectorAll('[data-js="reminder-book"]').forEach(bindReminderBook);
  document.querySelectorAll('[data-js="vunnen"]').forEach(bindVunnenForm);
  document.querySelectorAll('[data-js="tap"]').forEach(bindTapForm);

  var pamSideCtrl = null;
  function bindPamDesk(root) {
    var board = root && root.querySelector ? root.querySelector('[data-js="pam-board"]') : null;
    if (!board || board._jeabPamDesk) {
      return;
    }
    board._jeabPamDesk = true;
    var btn = board.querySelector('[data-js="pam-side-toggle"]');
    var body = board.querySelector('[data-js="pam-side-body"]');
    function loadSide() {
      if (!body) {
        return;
      }
      var wide = window.matchMedia('(min-width: 1280px)').matches;
      if (!wide || board.classList.contains('is-side-collapsed')) {
        return;
      }
      var open = board.querySelector('[data-js="pam-mid-list"] .item.is-open')
        || board.querySelector('.apple-list .item.is-open');
      var id = open ? (open.getAttribute('data-id') || '') : '';
      if (!id) {
        body.innerHTML = '<p class="pam-side-empty">Öppna en påminnelse.</p>';
        body.removeAttribute('data-side-id');
        return;
      }
      if (body.getAttribute('data-side-id') === id) {
        return;
      }
      if (pamSideCtrl) {
        try { pamSideCtrl.abort(); } catch (err) {}
      }
      pamSideCtrl = typeof AbortController === 'function' ? new AbortController() : null;
      var u;
      try {
        u = new URL(location.href);
      } catch (err) {
        return;
      }
      u.searchParams.set('intent', 'side');
      u.searchParams.set('open', id);
      u.searchParams.delete('_t');
      body.innerHTML = '<p class="pam-side-muted">Läser…</p>';
      fetch(u.pathname + u.search, {
        credentials: 'same-origin',
        signal: pamSideCtrl ? pamSideCtrl.signal : undefined,
        headers: { Accept: 'application/json' }
      }).then(function (res) {
        return res.json();
      }).then(function (data) {
        if (!data || data.ok !== true) {
          throw new Error('side');
        }
        body.innerHTML = data.html || '';
        body.setAttribute('data-side-id', id);
        bindReminderSubtree(body);
      }).catch(function (err) {
        if (err && err.name === 'AbortError') {
          return;
        }
        body.innerHTML = '<p class="pam-side-muted">Kunde inte läsa panelen.</p>';
      });
    }
    function applyCollapsed(on) {
      board.classList.toggle('is-side-collapsed', on);
      if (btn) {
        btn.setAttribute('aria-expanded', on ? 'false' : 'true');
        btn.setAttribute('aria-label', on ? 'Visa sidopanelen' : 'Dölj sidopanelen');
      }
      try { localStorage.setItem('jeab.pam.side', on ? '0' : '1'); } catch (err) {}
      if (!on) {
        if (body) {
          body.removeAttribute('data-side-id');
        }
        loadSide();
      }
    }
    try {
      if (localStorage.getItem('jeab.pam.side') === '0') {
        applyCollapsed(true);
      }
    } catch (err) {}
    if (btn && !btn._jeabPam) {
      btn._jeabPam = true;
      btn.addEventListener('click', function () {
        applyCollapsed(!board.classList.contains('is-side-collapsed'));
      });
    }
    board._jeabLoadSide = loadSide;
    if (!window._jeabPamResize) {
      window._jeabPamResize = true;
      window.addEventListener('resize', function () {
        pamDeskSync();
        document.querySelectorAll('[data-js="pam-board"]').forEach(function (node) {
          if (node._jeabLoadSide) {
            node._jeabLoadSide();
          }
        });
      });
    }
    loadSide();
    pamDeskSync();
  }

  function bindReminderSubtree(root) {
    if (!root || !root.querySelectorAll) {
      return;
    }
    root.querySelectorAll('[data-js="due"]').forEach(bindDueForm);
    root.querySelectorAll('[data-js="contact-edit"]').forEach(bindContactEdit);
    root.querySelectorAll('[data-js="contact-form"]').forEach(bindContactForm);
    root.querySelectorAll('[data-js="inline"]').forEach(bindInlineForm);
    root.querySelectorAll('[data-js="grow-note"]').forEach(bindGrowNote);
    root.querySelectorAll('[data-js="collapse-row"]').forEach(bindCollapseRow);
    bindReminderItemMains(root);
    root.querySelectorAll('[data-js="tag"]').forEach(bindTagForm);
    root.querySelectorAll('[data-js="snooze"]').forEach(bindSnoozeForm);
    root.querySelectorAll('[data-js="who"]').forEach(bindWhoForm);
    root.querySelectorAll('[data-js="toggle-tags"]').forEach(bindToggleTags);
    root.querySelectorAll('[data-js="reminder-book"]').forEach(bindReminderBook);
    root.querySelectorAll('[data-js="vunnen"]').forEach(bindVunnenForm);
    root.querySelectorAll('[data-js="tap"]').forEach(bindTapForm);
    root.querySelectorAll('[data-js="flag"]').forEach(bindFlagForm);
    root.querySelectorAll('[data-js="tel"], [data-js="mail"]').forEach((link) => {
      if (link._jeabBound) {
        return;
      }
      link._jeabBound = true;
      link.addEventListener('click', (event) => {
        event.stopPropagation();
        countContactLink(link);
      });
    });
    bindReminderSearch();
    bindReminderCreate();
    bindPamDesk(root);
  }
  window.jeabBindReminders = bindReminderSubtree;
  bindPamDesk(document);

  function reminderRowBusy(row) {
    if (!row) {
      return false;
    }
    if (row.classList.contains('is-open')) {
      return true;
    }
    if (snoozeRowBusy(row)) {
      return true;
    }
    if (row._jeabDonePending || row._jeabSwiping) {
      return true;
    }
    if (row.querySelector('[data-js="inline"]')?._jeabDirty) {
      return true;
    }
    if (row.querySelector('[data-js="inline"]')?._jeabSavePromise) {
      return true;
    }
    if (row.querySelector('[data-js="due"]')?._jeabPicking) {
      return true;
    }
    const active = document.activeElement;
    if (active && row.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) {
      return true;
    }
    return false;
  }

  function insertReminderCard(data) {
    const list = document.querySelector('.apple-list');
    if (!list || !data || !data.html) {
      return;
    }
    const id = String(data.id || (data.row && data.row.id) || '');
    if (id === '' || id === '0') {
      return;
    }
    if (list.querySelector('.item[data-id="' + id + '"]')) {
      return;
    }
    const wrap = document.createElement('ul');
    wrap.innerHTML = data.html;
    const row = wrap.querySelector('.item');
    if (!row) {
      return;
    }
    bindReminderSubtree(row);
    const todayHead = list.querySelector('.crm-group[data-group="today"]');
    if (todayHead && todayHead.nextSibling) {
      list.insertBefore(row, todayHead.nextSibling);
    } else if (todayHead) {
      list.appendChild(row);
    } else {
      list.insertBefore(row, list.firstChild);
    }
    const empty = document.querySelector('.crm-main .empty, .apple-main .empty');
    if (empty) {
      empty.hidden = true;
    }
    paintReminderGroups();
  }

  function paintReminderCounts(counts) {
    if (!counts) {
      return;
    }
    const map = {
      today: '.smart-tile.is-today b',
      done: '.smart-tile.is-done b',
      all: '.smart-tile.is-all b',
      waiting: '.smart-tile.is-waiting b',
    };
    Object.keys(map).forEach((key) => {
      if (counts[key] == null) {
        return;
      }
      document.querySelectorAll(map[key]).forEach((el) => {
        el.textContent = String(counts[key]);
      });
    });
    const headStrong = document.querySelector('.crm-section-head strong, .apple-head strong');
    const list = document.querySelector('.apple-list');
    if (list && list.getAttribute('data-groups')) {
      paintReminderGroups();
    } else if (headStrong && list) {
      const shown = list.querySelectorAll('.item:not([hidden])').length;
      const total = parseInt(list.getAttribute('data-total') || '', 10);
      const paged = !!document.querySelector('[data-js="reminder-more"]');
      headStrong.textContent = String(paged && total > shown ? total : shown);
    }
  }

  function paintReminderMore(payload) {
    const list = document.querySelector('.apple-list');
    if (!list || !payload) {
      return;
    }
    if (payload.total != null) {
      list.setAttribute('data-total', String(payload.total));
    }
    const more = document.querySelector('[data-js="reminder-more"]');
    if (!more || !('next' in payload)) {
      return;
    }
    if (payload.next == null) {
      more.remove();
      return;
    }
    more.setAttribute('data-offset', String(payload.next));
  }

  function bindReminderMore(btn) {
    if (!btn || btn._jeabBound) {
      return;
    }
    btn._jeabBound = true;
    btn.addEventListener('click', (event) => {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      if (btn.getAttribute('aria-busy') === 'true') {
        return;
      }
      const list = document.querySelector('.apple-list');
      if (!list) {
        return;
      }
      const label = btn.textContent;
      btn.setAttribute('aria-busy', 'true');
      btn.textContent = 'Laddar …';
      const url = new URL(location.href);
      url.searchParams.set('p', 'reminders');
      url.searchParams.set('intent', 'more');
      const storedOffset = btn.getAttribute('data-offset');
      const offset = (typeof jeabReminderMoreOffset === 'function')
        ? jeabReminderMoreOffset(storedOffset)
        : '0';
      url.searchParams.set('offset', offset);
      url.searchParams.delete('open');
      url.searchParams.delete('n');
      fetch(url.toString(), {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
      }).then((res) => {
        if (!res.ok) {
          throw new Error('more');
        }
        return res.json();
      }).then((data) => {
        if (!data || !data.ok || !Array.isArray(data.items)) {
          throw new Error('more');
        }
        const frag = document.createDocumentFragment();
        data.items.forEach((item) => {
          if (snoozeHidden.has(String(item.id))) {
            return;
          }
          if (list.querySelector('.item[data-id="' + item.id + '"]')) {
            return;
          }
          const wrap = document.createElement('ul');
          wrap.innerHTML = item.html;
          const row = wrap.querySelector('.item');
          if (!row) {
            return;
          }
          row.setAttribute('data-sync-stamp', item.stamp);
          bindReminderSubtree(row);
          frag.appendChild(row);
        });
        list.appendChild(frag);
        btn.textContent = label;
        btn.removeAttribute('aria-busy');
        paintReminderMore(data);
        paintReminderCounts(data.counts);
        reminderSoftStamp = '';
      }).catch((err) => {
        if (err && err.name === 'AbortError') {
          return;
        }
        if (window.jeabNav && window.jeabNav.hasOrder && window.jeabNav.hasOrder()) {
          return;
        }
        location.href = btn.getAttribute('href') || location.href;
      });
    });
  }

  document.querySelectorAll('[data-js="reminder-more"]').forEach(bindReminderMore);

  function applyReminderSoftSync(payload) {
    const list = document.querySelector('.apple-list');
    if (!list || !payload || !Array.isArray(payload.items)) {
      return false;
    }
    const scrollY = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
    const byId = new Map();
    payload.items.forEach((item) => {
      byId.set(String(item.id), item);
    });
    const before = [...list.querySelectorAll('.item')];
    const placeAt = new Map();
    before.forEach((row, index) => {
      placeAt.set(row, index);
    });

    before.forEach((row) => {
      const id = String(row.getAttribute('data-id') || '');
      const holding = snoozeRowPinned(row) || (snoozeLive.has(id) && !row._jeabSnoozeLeft && !snoozeHidden.has(id));
      const left = !row._jeabSnoozeRestore && (snoozeHidden.has(id) || !!row._jeabSnoozeLeft);
      const survives = (typeof jeabSnoozeHoldSurvives === 'function')
        ? jeabSnoozeHoldSurvives(byId.has(id), holding, left)
        : (byId.has(id) || holding);
      if (!survives && !reminderRowBusy(row)) {
        row.remove();
      }
    });

    const ordered = [];
    payload.items.forEach((item) => {
      const id = String(item.id);
      let row = list.querySelector('.item[data-id="' + id + '"]');
      const syncPlan = (typeof jeabSnoozeSyncKeeps === 'function')
        ? jeabSnoozeSyncKeeps(id, snoozeHidden, snoozeLive)
        : (snoozeHidden.has(id) ? 'drop' : (snoozeLive.has(id) ? 'keep' : 'replace'));
      if (syncPlan === 'drop') {
        if (row && snoozeRowPinned(row)) {
          ordered.push(row);
          return;
        }
        if (row) {
          row.remove();
        }
        return;
      }
      if (row && (syncPlan === 'keep' || reminderRowBusy(row))) {
        const session = snoozeLive.get(id);
        if (session) {
          session.row = row;
        }
        ordered.push(row);
        return;
      }
      if (row && row.getAttribute('data-sync-stamp') === item.stamp) {
        ordered.push(row);
        return;
      }
      if (!item.html) {
        if (row) {
          if (item.stamp) {
            row.setAttribute('data-sync-stamp', item.stamp);
          }
          ordered.push(row);
        }
        return;
      }
      const wrap = document.createElement('ul');
      wrap.innerHTML = item.html;
      const next = wrap.querySelector('.item');
      if (!next) {
        return;
      }
      next.setAttribute('data-sync-stamp', item.stamp);
      if (row) {
        row.replaceWith(next);
      }
      bindReminderSubtree(next);
      ordered.push(next);
    });

    const orphans = [...list.querySelectorAll('.item')].filter((row) => ordered.indexOf(row) === -1);
    const heldOrphans = [];
    const restOrphans = [];
    orphans.forEach((row) => {
      const id = snoozeRowId(row);
      const keep = snoozeRowPinned(row) || (snoozeLive.has(id) && !row._jeabSnoozeLeft && !snoozeHidden.has(id));
      if (keep) {
        heldOrphans.push(row);
      } else {
        restOrphans.push(row);
      }
    });
    heldOrphans.forEach((row) => {
      const at = placeAt.has(row) ? placeAt.get(row) : ordered.length;
      ordered.splice(Math.max(0, Math.min(at, ordered.length)), 0, row);
    });
    const frag = document.createDocumentFragment();
    ordered.forEach((row) => frag.appendChild(row));
    restOrphans.forEach((row) => frag.appendChild(row));
    list.appendChild(frag);
    ordered.forEach((row) => ensureSnoozeHoldRow(row));
    snoozeLive.forEach((session) => {
      if (session && session.row) {
        ensureSnoozeHoldRow(session.row);
      }
    });

    const empty = document.querySelector('.crm-main .empty, .apple-main .empty');
    if (empty) {
      empty.hidden = ordered.length + orphans.length > 0;
    }
    paintReminderMore(payload);
    paintReminderCounts(payload.counts);
    paintReminderGroups();
    document.documentElement.scrollTop = scrollY;
    document.body.scrollTop = scrollY;
    return true;
  }

  const REMINDER_SOFT_POLL_MS = 150000;
  const REMINDER_SOFT_IDLE_MS = 300000;
  let reminderSoftStamp = '';
  let reminderSoftTimer = 0;
  let reminderSoftInflight = null;
  let reminderSoftLastRun = 0;
  let reminderSoftIdleAt = Date.now();
  let reminderSoftPaused = false;

  function reminderSoftBlocked() {
    try {
      var here = new URL(location.href);
      var orderId = here.searchParams.get('id') || '';
      if ((here.searchParams.get('p') || '') === 'ordrar' && /^[1-9]\d*$/.test(orderId)) {
        return true;
      }
    } catch (e) {
      /* adressen går inte att läsa */
    }
    if (!document.querySelector('.apple-list')) {
      return true;
    }
    if (document.visibilityState === 'hidden') {
      return true;
    }
    const create = document.getElementById('reminder-create');
    if (create && create.open) {
      return true;
    }
    if (currentOpenRow() && reminderRowBusy(currentOpenRow()) && document.activeElement && currentOpenRow().contains(document.activeElement)) {
      // Still allow sync; applyReminderSoftSync skips busy rows.
    }
    return false;
  }

  function reminderRowStamps() {
    const list = document.querySelector('.apple-list');
    if (!list) {
      return '';
    }
    const parts = [];
    list.querySelectorAll('.item:not([data-open-extra])').forEach((row) => {
      const id = row.getAttribute('data-id') || '';
      const stamp = row.getAttribute('data-sync-stamp') || '';
      if (/^\d+$/.test(id) && /^[0-9a-f]{16}$/.test(stamp)) {
        parts.push(id + '.' + stamp);
      }
    });
    return parts.join(',');
  }

  function reminderListMatches(items) {
    const list = document.querySelector('.apple-list');
    if (!list || !Array.isArray(items)) {
      return false;
    }
    const ids = [...list.querySelectorAll('.item')].map((row) => String(row.getAttribute('data-id') || ''));
    if (ids.length !== items.length) {
      return false;
    }
    for (let i = 0; i < ids.length; i += 1) {
      if (ids[i] !== String(items[i].id)) {
        return false;
      }
    }
    return true;
  }

  function softSyncReminders(reason) {
    if (reminderSoftBlocked()) {
      return Promise.resolve(false);
    }
    if (window.jeabPollClaim && !window.jeabPollClaim(document.visibilityState === 'hidden')) {
      return Promise.resolve(false);
    }
    const now = Date.now();
    if (reason !== 'force' && now - reminderSoftLastRun < 1200) {
      return Promise.resolve(false);
    }
    if (reminderSoftInflight) {
      return reminderSoftInflight;
    }
    const bg = window.jeabBackground;
    if (bg && bg.busy()) {
      return Promise.resolve(false);
    }
    const bgCtrl = bg ? bg.take() : null;
    if (bg && !bgCtrl) {
      return Promise.resolve(false);
    }
    reminderSoftLastRun = now;
    const startedNav = window.jeabNav && window.jeabNav.current ? window.jeabNav.current() : null;
    const url = new URL(location.href);
    url.searchParams.set('p', 'reminders');
    url.searchParams.set('intent', 'sync');
    if (reminderSoftStamp) {
      url.searchParams.set('stamp', reminderSoftStamp);
    } else {
      url.searchParams.delete('stamp');
    }
    const syncList = document.querySelector('.apple-list');
    if (syncList) {
      url.searchParams.set('n', String(syncList.querySelectorAll('.item:not([data-open-extra])').length));
    }
    const rowStamps = reminderRowStamps();
    if (rowStamps) {
      url.searchParams.set('rst', rowStamps);
    } else {
      url.searchParams.delete('rst');
    }
    reminderSoftInflight = fetch(url.toString(), {
      credentials: 'same-origin',
      signal: bgCtrl ? bgCtrl.signal : undefined,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (res.status === 419) {
        showReloadNotice();
        throw new Error('csrf');
      }
      if (!res.ok) {
        throw new Error('sync');
      }
      return res.json();
    }).then((data) => {
      if (window.jeabNav && window.jeabNav.hasOrder && window.jeabNav.hasOrder()) {
        return false;
      }
      if (startedNav != null && window.jeabNav && window.jeabNav.current && window.jeabNav.current() !== startedNav) {
        return false;
      }
      if (!data || !data.ok) {
        return false;
      }
      if (data.stamp && reminderSoftStamp && data.stamp === reminderSoftStamp && !Array.isArray(data.items)) {
        paintReminderMore(data);
        paintReminderCounts(data.counts);
        return false;
      }
      const sameList = reminderListMatches(data.items);
      if (data.stamp && data.stamp === reminderSoftStamp && sameList) {
        paintReminderMore(data);
        paintReminderCounts(data.counts);
        return false;
      }
      if (!reminderSoftStamp && data.stamp && Array.isArray(data.items) && sameList) {
        const list = document.querySelector('.apple-list');
        if (list) {
          data.items.forEach((item) => {
            const row = list.querySelector('.item[data-id="' + item.id + '"]');
            if (row) {
              row.setAttribute('data-sync-stamp', item.stamp);
            }
          });
        }
        reminderSoftStamp = data.stamp;
        paintReminderMore(data);
        paintReminderCounts(data.counts);
        return false;
      }
      const changed = applyReminderSoftSync(data);
      reminderSoftStamp = data.stamp || reminderSoftStamp;
      return changed;
    }).catch(() => false).finally(() => {
      if (bg && bgCtrl) {
        bg.release(bgCtrl);
      }
      reminderSoftInflight = null;
    });
    return reminderSoftInflight;
  }

  window.__jeabSoftSyncReminders = softSyncReminders;

  function reminderSoftShouldPause() {
    if (document.visibilityState === 'hidden') {
      return true;
    }
    return Date.now() - reminderSoftIdleAt > REMINDER_SOFT_IDLE_MS;
  }

  function reminderSoftNoteActivity() {
    reminderSoftIdleAt = Date.now();
    if (!reminderSoftPaused || document.visibilityState !== 'visible') {
      return;
    }
    reminderSoftPaused = false;
    softSyncReminders('activity');
    scheduleReminderSoftPoll();
  }

  function scheduleReminderSoftPoll() {
    window.clearInterval(reminderSoftTimer);
    reminderSoftTimer = 0;
    if (!document.querySelector('.apple-list')) {
      return;
    }
    if (reminderSoftShouldPause()) {
      reminderSoftPaused = true;
      return;
    }
    reminderSoftPaused = false;
    reminderSoftTimer = window.setInterval(() => {
      if (reminderSoftShouldPause()) {
        reminderSoftPaused = true;
        window.clearInterval(reminderSoftTimer);
        reminderSoftTimer = 0;
        return;
      }
      pulseReminders();
    }, REMINDER_SOFT_POLL_MS);
  }

  function pulseReminders() {
    if (reminderSoftBlocked()) {
      return Promise.resolve(false);
    }
    if (window.jeabPollClaim && !window.jeabPollClaim(document.visibilityState === 'hidden')) {
      return Promise.resolve(false);
    }
    if (reminderSoftInflight) {
      return reminderSoftInflight;
    }
    const bg = window.jeabBackground;
    if (bg && bg.busy()) {
      return Promise.resolve(false);
    }
    const bgCtrl = bg ? bg.take() : null;
    if (bg && !bgCtrl) {
      return Promise.resolve(false);
    }
    const url = new URL(location.href);
    url.searchParams.set('p', 'reminders');
    url.searchParams.set('intent', 'pulse');
    const syncList = document.querySelector('.apple-list');
    if (syncList) {
      url.searchParams.set('n', String(syncList.querySelectorAll('.item:not([data-open-extra])').length));
    }
    let followSync = false;
    reminderSoftInflight = fetch(url.toString(), {
      credentials: 'same-origin',
      signal: bgCtrl ? bgCtrl.signal : undefined,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (res.status === 419) {
        showReloadNotice();
        throw new Error('csrf');
      }
      if (!res.ok) {
        throw new Error('pulse');
      }
      return res.json();
    }).then((data) => {
      if (!data || !data.ok || Array.isArray(data.items)) {
        return false;
      }
      if (data.counts) {
        paintReminderCounts(data.counts);
      }
      followSync = !data.stamp || data.stamp !== reminderSoftStamp;
      return false;
    }).catch(() => false).finally(() => {
      if (bg && bgCtrl) {
        bg.release(bgCtrl);
      }
      reminderSoftInflight = null;
    }).then(() => {
      if (followSync) {
        return softSyncReminders('force');
      }
      return false;
    });
    return reminderSoftInflight;
  }

  if (document.body.classList.contains('page-reminders') && !document.body.classList.contains('page-kontakter') && document.querySelector('.apple-list')) {
    const seeded = document.querySelector('.apple-list');
    if (seeded && seeded.getAttribute('data-list-stamp')) {
      reminderSoftStamp = seeded.getAttribute('data-list-stamp');
    }
    scheduleReminderSoftPoll();
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        reminderSoftPaused = true;
        window.clearInterval(reminderSoftTimer);
        reminderSoftTimer = 0;
        if (window.jeabPollRelease) {
          window.jeabPollRelease();
        }
        return;
      }
      if (document.visibilityState === 'visible') {
        reminderSoftIdleAt = Date.now();
        reminderSoftPaused = false;
        softSyncReminders('visible');
        scheduleReminderSoftPoll();
      }
    });
    ['pointerdown', 'keydown', 'touchstart'].forEach((type) => {
      document.addEventListener(type, reminderSoftNoteActivity, { passive: true });
    });
    window.addEventListener('pageshow', (event) => {
      if (event.persisted || document.visibilityState === 'visible') {
        softSyncReminders('pageshow');
      }
    });
  }

  /* —— Påminnelser: grupper «Försenat»/«Idag» —— */
  function paintReminderGroups() {
    const list = document.querySelector('.apple-list[data-groups="today"]');
    if (!list) {
      return;
    }
    const lateHead = list.querySelector('.crm-group[data-group="late"]');
    const todayHead = list.querySelector('.crm-group[data-group="today"]');
    const rows = [...list.querySelectorAll('.item')].filter((row) => !row.hidden && row.style.display !== 'none');
    const late = rows.filter((row) => row.classList.contains('is-late'));
    const rest = rows.filter((row) => !row.classList.contains('is-late'));
    if (lateHead) {
      lateHead.hidden = late.length === 0;
      const n = lateHead.querySelector('strong');
      if (n) {
        n.textContent = String(late.length);
      }
      list.insertBefore(lateHead, list.firstChild);
    }
    // Försenade först, i den ordning de redan står.
    // Pinnen för valt kort är inte en .item. Den får inte bli ankare, då
    // hamnar den under alla rader och listans topp är en annan påminnelse.
    const skipDeskPin = (node) => {
      while (node && node.nodeType === 1 && node.classList.contains('pam-desk-pin')) {
        node = node.nextSibling;
      }
      return node;
    };
    let anchor = skipDeskPin(lateHead ? lateHead.nextSibling : list.firstChild);
    late.forEach((row) => {
      anchor = skipDeskPin(anchor);
      if (row !== anchor) {
        list.insertBefore(row, anchor);
      } else {
        anchor = skipDeskPin(anchor.nextSibling);
        return;
      }
      anchor = skipDeskPin(row.nextSibling);
    });
    if (todayHead) {
      todayHead.hidden = rest.length === 0;
      const n = todayHead.querySelector('strong');
      if (n) {
        n.textContent = String(rest.length);
      }
      if (rest.length) {
        list.insertBefore(todayHead, rest[0]);
      } else {
        list.appendChild(todayHead);
      }
    }
  }

  paintReminderGroups();

  /* —— Påminnelser: filter, flikar och sök utan helsidesladdning —— */
  let reminderNavGen = 0;

  function reminderPageUrl(href) {
    const url = new URL(href, location.href);
    url.searchParams.set('intent', 'page');
    url.searchParams.delete('open');
    return url.toString();
  }

  function swapReminderPart(doc, selector) {
    const now = document.querySelector(selector);
    const next = doc.querySelector(selector);
    if (now && next) {
      now.replaceWith(next);
      return next;
    }
    return null;
  }

  function applyReminderPage(data, keepFocus) {
    const doc = new DOMParser().parseFromString('<!doctype html><body>' + String(data.html || '') + '</body>', 'text/html');
    if (!doc.querySelector('.crm-main')) {
      return false;
    }
    const heldRows = [...document.querySelectorAll('.apple-list .item')].filter((row) => snoozeRowPinned(row));
    if (currentOpenRow() && !snoozeHoldActive(currentOpenRow())) {
      closeOpenReminder();
    }
    swapReminderPart(doc, '.crm-stats');
    swapReminderPart(doc, '.crm-tags');
    swapReminderPart(doc, '.crm-who');
    const main = swapReminderPart(doc, '.crm-main');
    const flashNow = document.querySelectorAll('.crm-app > .flash');
    flashNow.forEach((el) => el.remove());
    if (searchForm) {
      const nextForm = doc.querySelector('[data-js="apple-search"]');
      if (nextForm) {
        searchForm.querySelectorAll('input[type="hidden"]').forEach((el) => el.remove());
        nextForm.querySelectorAll('input[type="hidden"]').forEach((el) => {
          searchForm.insertBefore(el, searchForm.firstChild);
        });
        const nextClear = nextForm.querySelector('[data-js="search-clear"]');
        if (searchClear && nextClear) {
          searchClear.setAttribute('href', nextClear.getAttribute('href') || '');
        }
        const nextQ = nextForm.querySelector('[data-js="apple-search-q"]');
        if (searchInput && nextQ && !keepFocus && document.activeElement !== searchInput) {
          searchInput.value = nextQ.value;
        }
        syncSearchClear();
      }
    }
    if (data.company) {
      const chrome = document.querySelector('.chrome-company');
      if (chrome) {
        const wrap = document.createElement('div');
        wrap.innerHTML = data.company;
        const next = wrap.querySelector('.chrome-company');
        if (next) {
          chrome.replaceWith(next);
        }
      }
    }
    refreshCloseRefs();
    if (main) {
      const list = main.querySelector('.apple-list');
      heldRows.forEach((row) => {
        if (!list || !snoozeRowPinned(row)) {
          return;
        }
        const id = snoozeRowId(row);
        const existing = id ? list.querySelector('.item[data-id="' + id + '"]') : null;
        if (existing && existing !== row) {
          existing.replaceWith(row);
        } else if (!row.isConnected) {
          const anchor = list.querySelector('.item');
          list.insertBefore(row, anchor);
        }
        ensureSnoozeHoldRow(row);
      });
      bindReminderSubtree(main);
      main.querySelectorAll('[data-js="reminder-more"]').forEach(bindReminderMore);
    }
    paintReminderGroups();
    reminderSoftStamp = '';
    return true;
  }

  let reminderPageCtrl = null;
  window.addEventListener('jeab:nav', () => {
    reminderNavGen += 1;
    if (reminderPageCtrl) {
      reminderPageCtrl.abort();
      reminderPageCtrl = null;
    }
  });

  function softNavigateReminders(href, opts) {
    const options = opts || {};
    const gen = ++reminderNavGen;
    const startedNav = window.jeabNav && window.jeabNav.current ? window.jeabNav.current() : null;
    if (reminderPageCtrl) {
      reminderPageCtrl.abort();
    }
    reminderPageCtrl = new AbortController();
    const pageCtrl = reminderPageCtrl;
    const app = document.querySelector('.crm-app');
    return fetch(reminderPageUrl(href), {
      credentials: 'same-origin',
      signal: pageCtrl.signal,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (!res.ok) {
        throw new Error('page');
      }
      return res.json();
    }).then((data) => {
      if (gen !== reminderNavGen) {
        return;
      }
      if (window.jeabNav && window.jeabNav.hasOrder && window.jeabNav.hasOrder()) {
        return;
      }
      if (startedNav != null && window.jeabNav && window.jeabNav.current && window.jeabNav.current() !== startedNav) {
        return;
      }
      if (!data || !data.ok || !applyReminderPage(data, !!options.keepFocus)) {
        throw new Error('page');
      }
      if (options.history !== false && window.history && typeof history.pushState === 'function') {
        const clean = new URL(href, location.href);
        clean.searchParams.delete('intent');
        const target = clean.pathname + clean.search;
        if (options.replace) {
          history.replaceState({ jeabReminders: true }, '', target);
        } else {
          history.pushState({ jeabReminders: true }, '', target);
        }
        reminderHistoryUrl = target;
      }
    }).catch((err) => {
      if (err && err.name === 'AbortError') {
        return;
      }
      if (window.jeabNav && window.jeabNav.hasOrder && window.jeabNav.hasOrder()) {
        return;
      }
      if (gen === reminderNavGen) {
        location.href = href;
      }
    }).finally(() => {
      if (gen === reminderNavGen && app) {
        app.removeAttribute('aria-busy');
      }
    });
  }

  if (document.querySelector('.crm-app') && !document.body.classList.contains('page-kontakter')) {
    document.addEventListener('click', (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      const link = event.target && event.target.closest
        ? event.target.closest('.crm-stats a.smart-tile, .crm-tags a.tag-chip, .crm-who a.chip')
        : null;
      if (!link || !link.getAttribute('href')) {
        return;
      }
      event.preventDefault();
      window.clearTimeout(typeof searchTimer === 'number' ? searchTimer : 0);
      softNavigateReminders(link.getAttribute('href'));
    });
    window.addEventListener('popstate', () => {
      if (typeof window.jeabReminderOpenPop === 'function' && window.jeabReminderOpenPop()) {
        return;
      }
      if (window.jeabShell) {
        return;
      }
      if (new URLSearchParams(location.search).get('p') === 'reminders' || document.querySelector('.crm-app')) {
        if (searchInput && document.activeElement !== searchInput) {
          searchInput.value = activeSearchQuery();
          syncSearchClear();
        }
        softNavigateReminders(location.href, { history: false });
      }
    });
  }

  /* —— «Markera som klar» med «Klar · Ångra» —— */
  const UNDO_MS = 5000;
  let undoBar = null;
  let undoPending = null;

  function reminderUndoBar() {
    if (undoBar && document.body.contains(undoBar)) {
      return undoBar;
    }
    undoBar = document.createElement('div');
    undoBar.className = 'crm-undo';
    undoBar.setAttribute('role', 'status');
    undoBar.setAttribute('aria-live', 'polite');
    undoBar.hidden = true;
    undoBar.innerHTML = '<span class="crm-undo-text">Klar</span><button type="button" class="crm-undo-btn">Ångra</button>';
    undoBar.querySelector('.crm-undo-btn').addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      if (typeof undoBar._jeabUndo === 'function') {
        const run = undoBar._jeabUndo;
        undoBar._jeabUndo = null;
        run();
        return;
      }
      undoReminderDone();
    });
    document.body.appendChild(undoBar);
    return undoBar;
  }

  function bindReminderBarPause(bar) {
    if (!bar || bar._jeabPauseBound) {
      return;
    }
    bar._jeabPauseBound = true;
    const syncHold = () => {
      if (bar._jeabOver || bar._jeabDown) {
        pauseReminderBar(bar);
        return;
      }
      resumeReminderBar(bar);
    };
    bar.addEventListener('mouseenter', () => {
      bar._jeabOver = true;
      syncHold();
    });
    bar.addEventListener('mouseleave', () => {
      bar._jeabOver = false;
      syncHold();
    });
    bar.addEventListener('pointerdown', (event) => {
      bar._jeabDown = true;
      try {
        bar.setPointerCapture(event.pointerId);
      } catch (e) {
        /* pekaren går att släppa ändå */
      }
      syncHold();
    });
    const release = () => {
      bar._jeabDown = false;
      syncHold();
    };
    bar.addEventListener('pointerup', release);
    bar.addEventListener('pointercancel', release);
    bar.addEventListener('touchstart', () => {
      bar._jeabDown = true;
      syncHold();
    }, { passive: true });
    bar.addEventListener('touchend', release);
    bar.addEventListener('touchcancel', release);
  }

  function startReminderBarTimer(bar, ms) {
    window.clearTimeout(bar._jeabHide);
    bar._jeabRemain = ms;
    bar._jeabArmAt = Date.now();
    bar._jeabPaused = false;
    bar._jeabHide = window.setTimeout(() => {
      if (!bar._jeabPaused) {
        bar.hidden = true;
      }
    }, ms);
  }

  function pauseReminderBar(bar) {
    if (!bar || bar.hidden || bar._jeabPaused) {
      return;
    }
    bar._jeabPaused = true;
    const elapsed = Date.now() - (bar._jeabArmAt || Date.now());
    bar._jeabRemain = Math.max(0, (bar._jeabRemain || 0) - elapsed);
    window.clearTimeout(bar._jeabHide);
    if (typeof bar._jeabSync === 'function') {
      bar._jeabSync(bar._jeabRemain, true);
    }
  }

  function resumeReminderBar(bar) {
    if (!bar || !bar._jeabPaused || bar.hidden) {
      return;
    }
    bar._jeabPaused = false;
    const ms = bar._jeabRemain || 0;
    if (typeof bar._jeabSync === 'function') {
      bar._jeabSync(ms, false);
    }
    startReminderBarTimer(bar, ms);
  }

  function showReminderBar(text, withUndo, ms, onUndo) {
    const bar = reminderUndoBar();
    bar._jeabUndo = typeof onUndo === 'function' ? onUndo : null;
    bar._jeabSync = null;
    bar.querySelector('.crm-undo-text').textContent = text;
    const undoBtn = bar.querySelector('.crm-undo-btn');
    undoBtn.hidden = !withUndo;
    undoBtn.textContent = 'Ångra';
    bar.hidden = false;
    bindReminderBarPause(bar);
    const life = withUndo ? Math.max(6000, ms) : ms;
    startReminderBarTimer(bar, life);
  }

  function hideReminderBar() {
    if (undoBar) {
      window.clearTimeout(undoBar._jeabHide);
      undoBar._jeabPaused = false;
      undoBar._jeabSync = null;
      undoBar.hidden = true;
    }
  }

  function doneFormData(form, closeLead) {
    const data = new FormData(form);
    const id = data.get('id');
    if (id == null || String(id).trim() === '' || String(id) === '0') {
      const row = form.closest('.apple-list .item');
      const fallback = row ? String(row.getAttribute('data-id') || '') : '';
      if (fallback !== '' && fallback !== '0') {
        data.set('id', fallback);
      }
    }
    if (!String(data.get('_csrf') || '')) {
      const token = (document.querySelector('meta[name="csrf"]') || {}).content || '';
      if (token) {
        data.set('_csrf', token);
      }
    }
    data.set('done', '1');
    if (closeLead) {
      data.set('close_lead', '1');
    }
    return data;
  }

  function replaceReminderRow(row, html, wasOpen) {
    if (!row || !html) {
      return row;
    }
    const wrap = document.createElement('ul');
    wrap.innerHTML = html;
    const next = wrap.querySelector('.item');
    if (!next || !row.parentNode) {
      return row;
    }
    row.replaceWith(next);
    bindReminderSubtree(next);
    next._jeabDonePending = false;
    next.classList.remove('is-done', 'is-done-pending');
    if (wasOpen) {
      openReminderRow(next);
    }
    return next;
  }

  function commitReminderDone(pending, beacon) {
    if (!pending || pending.sent) {
      return Promise.resolve();
    }
    pending.sent = true;
    const { form, row } = pending;
    const body = doneFormData(form, !!(beacon && pending.closeLead));
    const req = fetch(formPostUrl(form), {
      method: 'POST',
      body,
      credentials: 'same-origin',
      keepalive: !!beacon,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    });
    pending.req = req;
    if (beacon) {
      pending.ok = true;
      return Promise.resolve();
    }
    return req.then((res) => {
      if (res.status === 419) {
        showReloadNotice();
        throw new Error('csrf');
      }
      if (!res.ok) {
        throw new Error('post');
      }
      pending.ok = true;
      if (pending.undone) {
        return reverseReminderDone(pending);
      }
    }).catch(() => {
      if (pending.undone) {
        return;
      }
      row._jeabDonePending = false;
      row.classList.remove('is-done', 'is-done-pending');
      showReminderBar('Kunde inte spara.', false, 2800);
    });
  }

  function reverseReminderDone(pending) {
    const data = doneFormData(pending.form);
    data.set('done', '0');
    return fetch(formPostUrl(pending.form), {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        Accept: 'application/json',
      },
    }).then((res) => {
      if (!res.ok) {
        throw new Error('post');
      }
      return res.json().catch(() => ({}));
    }).then((payload) => {
      pending.row._jeabDonePending = false;
      pending.row.classList.remove('is-done', 'is-done-pending');
      if (payload && payload.html) {
        pending.row = replaceReminderRow(pending.row, payload.html, !!pending.wasOpen);
      } else if (pending.wasOpen) {
        openReminderRow(pending.row);
      }
      if (payload && payload.counts) {
        paintReminderCounts(payload.counts);
      }
    }).catch(() => {
      pending.row._jeabDonePending = false;
      pending.row.classList.add('is-done', 'is-done-pending');
      showReminderBar('Kunde inte spara.', false, 2800);
    });
  }

  function undoReminderDone() {
    const pending = undoPending;
    hideReminderBar();
    if (!pending) {
      return;
    }
    window.clearTimeout(pending.timer);
    undoPending = null;
    pending.undone = true;
    pending.row.classList.remove('is-done', 'is-done-pending');
    if (pending.sent && pending.ok) {
      reverseReminderDone(pending);
    }
  }

  function markReminderDone(form, closeLead) {
    const row = form.closest('.apple-list .item');
    if (!row || row._jeabDonePending) {
      return;
    }
    if (undoPending && !undoPending.sent) {
      commitReminderDone(undoPending);
    }
    const wasOpen = row.classList.contains('is-open');
    const start = () => {
      row._jeabDonePending = true;
      row.classList.add('is-done', 'is-done-pending');
      const pending = { form, row, sent: false, undone: false, ok: false, timer: 0, closeLead: !!closeLead, wasOpen };
      pending.timer = window.setTimeout(() => {
        if (undoPending === pending) {
          undoPending = null;
        }
        if (pending.undone) {
          return;
        }
        row._jeabDonePending = false;
        if (wasOpen) {
          closeOpenReminder();
        }
        collapseTodayRow(row);
        closeLinkedLead(pending);
        window.setTimeout(paintReminderGroups, SNOOZE_COLLAPSE_MS + 120);
        if (typeof window.__jeabSoftSyncReminders === 'function') {
          window.setTimeout(() => window.__jeabSoftSyncReminders('force'), SNOOZE_COLLAPSE_MS + 200);
        }
      }, UNDO_MS);
      undoPending = pending;
      showReminderBar('Klar', true, UNDO_MS);
      commitReminderDone(pending);
    };
    if (wasOpen) {
      const inline = row.querySelector('[data-js="inline"]');
      const flushing = inline && (inline._jeabDirty || inline._jeabSavePromise)
        ? saveInlineForm(inline)
        : Promise.resolve();
      flushing.then(start, start);
      return;
    }
    start();
  }

  function closeLinkedLead(pending, beacon) {
    if (!pending || !pending.closeLead || pending.leadSent || pending.undone) {
      return;
    }
    pending.leadSent = true;
    const send = () => {
      if (pending.undone) {
        return;
      }
      const body = doneFormData(pending.form, true);
      fetch(formPostUrl(pending.form), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        keepalive: !!beacon,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
          Accept: 'application/json',
        },
      }).catch(() => {});
    };
    if (beacon || pending.ok) {
      send();
      return;
    }
    Promise.resolve(pending.req).then(send, () => {});
  }

  function flushReminderDone() {
    if (undoPending && !undoPending.undone) {
      if (!undoPending.sent) {
        commitReminderDone(undoPending, true);
      } else {
        closeLinkedLead(undoPending, true);
      }
      undoPending = null;
    }
    document.querySelectorAll('[data-js="tap"]').forEach((form) => {
      const pending = form._jeabTapPending;
      if (!pending || pending.sent || pending.undone) {
        return;
      }
      pending.sent = true;
      window.clearTimeout(pending.timer);
      postInPlace(form).catch(() => {});
    });
  }

  window.addEventListener('pagehide', flushReminderDone);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      flushReminderDone();
    }
  });

  document.addEventListener('pointerdown', (event) => {
    const onTag = eventPath(event).some((node) => node && node.closest && node.closest('[data-js="tag"], [data-js="vunnen"]'));
    if (onTag) {
      jeabTagPointerAt = Date.now();
    }
  }, true);

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form || !form.classList || !form.classList.contains('check-form') || !form.closest('.apple-list .item')) {
      return;
    }
    const submitter = event.submitter || null;
    const fromTag = (Date.now() - jeabTagPointerAt) < 500;
    const donePlan = (typeof jeabReminderDoneSubmit === 'function')
      ? jeabReminderDoneSubmit(!!submitter, !!(submitter && submitter.classList && submitter.classList.contains('check')), fromTag)
      : (fromTag ? 'ignore' : 'done');
    if (donePlan !== 'done') {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    const row = form.closest('.apple-list .item');
    event.preventDefault();
    if (row.classList.contains('is-done') && !row._jeabDonePending) {
      row.classList.remove('is-done');
      const data = new FormData(form);
      data.set('done', '0');
      fetch(formPostUrl(form), {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
          Accept: 'application/json',
        },
      }).then((res) => {
        if (!res.ok) {
          throw new Error('post');
        }
        const check = form.querySelector('.check');
        if (check) {
          check.setAttribute('aria-label', 'Markera som klar');
        }
        showReminderBar('Öppen igen.', false, 1600);
      }).catch(() => {
        row.classList.add('is-done');
        showReminderBar('Kunde inte spara.', false, 2800);
      });
      return;
    }
    markReminderDone(form);
  }, true);

  /* —— Mobil: svep vänster = knuffa 2 dagar, svep höger = klar och stäng lead —— */
  const SWIPE_SNOOZE_PX = 80;
  const SWIPE_MAX_PX = 120;

  function swipeRowFrom(target) {
    const row = target && target.closest ? target.closest('.apple-list .item') : null;
    if (!row || row.classList.contains('is-open') || row.classList.contains('is-done') || row._jeabDonePending) {
      return null;
    }
    if (target.closest('a.item-kontakt, a[href^="tel:"], button, input, textarea, select, form')) {
      return null;
    }
    return row.querySelector('[data-js="snooze"] input[name="by"][value="2d"]') ? row : null;
  }

  let swipe = null;

  function resetSwipe(row) {
    row.style.transition = 'transform 160ms ease';
    row.style.transform = '';
    row.classList.remove('is-swiping', 'is-swipe-armed');
    window.setTimeout(() => {
      row.style.transition = '';
      row._jeabSwiping = false;
    }, 180);
  }

  document.addEventListener('pointerdown', (event) => {
    if (event.pointerType === 'mouse' && event.button !== 0) {
      return;
    }
    if (event.pointerType !== 'mouse' && !event.isPrimary) {
      return;
    }
    const row = swipeRowFrom(event.target);
    if (!row) {
      return;
    }
    swipe = {
      row,
      id: event.pointerId,
      pointerType: event.pointerType,
      x: event.clientX,
      y: event.clientY,
      dx: 0,
      active: false,
      dead: false,
      dir: 0,
    };
  }, { passive: true });

  document.addEventListener('pointermove', (event) => {
    if (!swipe || event.pointerId !== swipe.id || swipe.dead) {
      return;
    }
    const dx = event.clientX - swipe.x;
    const dy = event.clientY - swipe.y;
    if (!swipe.active) {
      if (Math.abs(dy) > 10 && Math.abs(dy) > Math.abs(dx)) {
        swipe.dead = true; // scroll
        return;
      }
      if (Math.abs(dx) < 12) {
        return;
      }
      swipe.dir = dx > 0 ? 1 : -1;
      swipe.active = true;
      swipe.row._jeabSwiping = true;
      swipe.row.classList.add('is-swiping');
    }
    const dir = swipe.dir || (dx > 0 ? 1 : -1);
    swipe.dx = dir > 0
      ? Math.min(SWIPE_MAX_PX, Math.max(0, dx))
      : Math.max(-SWIPE_MAX_PX, Math.min(0, dx));
    swipe.row.style.transform = 'translateX(' + swipe.dx + 'px)';
    const armed = dir > 0 ? swipe.dx >= SWIPE_SNOOZE_PX : swipe.dx <= -SWIPE_SNOOZE_PX;
    swipe.row.classList.toggle('is-swipe-armed', armed);
    swipe.row.classList.toggle('is-swipe-close', dir > 0 && armed);
  }, { passive: true });

  function endSwipe(event) {
    if (!swipe || (event && event.pointerId !== swipe.id)) {
      return;
    }
    const s = swipe;
    swipe = null;
    const dx = event && typeof event.clientX === 'number' ? event.clientX - s.x : s.dx;
    const dy = event && typeof event.clientY === 'number' ? event.clientY - s.y : 0;
    const gesture = window.jeabReminderGesture(s.pointerType, dx, dy, event ? event.type : '');
    if (s.active) {
      s.row.classList.remove('is-swipe-close');
      resetSwipe(s.row);
    }
    if (gesture !== 'open') {
      s.row._jeabSwipeClickBlock = Date.now();
    }
    if (gesture === 'open') {
      openReminderRow(s.row);
      return;
    }
    if (gesture === 'done') {
      const check = s.row.querySelector('.check-form');
      if (check) {
        markReminderDone(check, true);
      }
      return;
    }
    if (gesture !== 'snooze') {
      return;
    }
    const input = s.row.querySelector('[data-js="snooze"] input[name="by"][value="2d"]');
    const form = input ? input.form : null;
    if (!form) {
      return;
    }
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
  }

  document.addEventListener('pointerup', endSwipe, true);
  document.addEventListener('pointercancel', endSwipe, true);
  document.addEventListener('click', (event) => {
    const row = event.target && event.target.closest ? event.target.closest('.apple-list .item') : null;
    if (row && row._jeabSwipeClickBlock && Date.now() - row._jeabSwipeClickBlock < 400) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
    }
  }, true);

  function jeabBootCalendar() {
  const sheet = document.getElementById('booking-sheet');
  const bookingForm = document.getElementById('booking-form');
  if (!sheet || !bookingForm) {
    return;
  }
  if (sheet.dataset.jeabCalBoot === '1') {
    if (typeof window.jeabRebindCalendar === 'function') {
      window.jeabRebindCalendar();
    }
    if (typeof window.jeabFillCalendar === 'function') {
      window.jeabFillCalendar();
    }
    return;
  }
  if (window.__jeabCalBound) {
    window.__jeabShellFallback = true;
    return;
  }
  window.__jeabCalBound = true;
  sheet.dataset.jeabCalBoot = '1';

  const action = document.getElementById('booking-action');
  const idField = document.getElementById('booking-id');
  const starts = document.getElementById('booking-starts');
  const ends = document.getElementById('booking-ends');
  const whenLabel = document.getElementById('booking-when-label');
  const whenDate = document.getElementById('booking-when-date');
  const whenTimes = document.getElementById('booking-when-times');
  const whenDays = document.getElementById('booking-when-days');
  const startDay = document.getElementById('booking-start-day');
  const dayCount = document.getElementById('booking-day-count');
  const startTime = document.getElementById('booking-start-time');
  const endTime = document.getElementById('booking-end-time');
  const customer = document.getElementById('booking-customer');
  const email = document.getElementById('booking-email');
  const emailEdit = document.getElementById('booking-email-edit');
  const emailLink = document.getElementById('booking-email-link');
  const phone = document.getElementById('booking-phone');
  const phoneEdit = document.getElementById('booking-phone-edit');
  const phoneLink = document.getElementById('booking-phone-link');
  const address = document.getElementById('booking-address');
  const notes = document.getElementById('booking-notes');
  const save = document.getElementById('booking-save');
  const delBtn = document.getElementById('booking-delete');
  const moreBox = document.getElementById('booking-more');
  function showMore(on) {
    if (moreBox) {
      moreBox.hidden = !on;
      moreBox.open = false;
    }
  }
  function calIsPhone() {
    return window.matchMedia('(max-width: 719px)').matches;
  }
  function calBoot() {
    const el = document.getElementById('jeab-cal-boot');
    try {
      return JSON.parse((el && el.textContent) || 'null') || {};
    } catch {
      return {};
    }
  }
  const pointer = document.getElementById('outlook-pointer');

  const whoJob = bookingForm.querySelector('[data-js="who-job"]');
  const whoJobLabel = bookingForm.querySelector('[data-js="who-job-label"]');

  function paintWhoJob() {
    const names = [...bookingForm.querySelectorAll('input[name="assignees[]"]:checked')].map((box) => box.value);
    const jobs = [...bookingForm.querySelectorAll('input[name="job_types[]"]:checked')].map((box) =>
      box.closest('label')?.querySelector('span')?.textContent || box.value
    );
    const parts = [jobs.join(', '), names.join(', ')].filter(Boolean);
    if (whoJobLabel) {
      whoJobLabel.textContent = parts.join(' · ') || 'Vem · Typ';
    }
  }

  function resetJob(value) {
    const wanted = new Set(
      String(value || '')
        .split(',')
        .map((job) => job.trim().toLowerCase())
        .filter(Boolean)
    );
    bookingForm.querySelectorAll('input[name="job_types[]"]').forEach((box) => {
      box.checked = wanted.has(box.value);
    });
    paintWhoJob();
  }

  function bookingPhoneValue(raw) {
    const digits = String(raw || '').replace(/\D+/g, '');
    if (!digits) {
      return '';
    }
    const idx = digits.indexOf('7');
    if (idx === -1) {
      return digits;
    }
    return `+46${digits.slice(idx)}`;
  }

  function setSheetEmail(value) {
    const next = String(value || '').trim();
    if (email) {
      email.value = next;
    }
    if (emailEdit && emailEdit !== email) {
      emailEdit.value = next;
    }
    if (!emailLink) {
      return;
    }
    if (next) {
      emailLink.hidden = false;
      emailLink.href = `mailto:${next}`;
      emailLink.textContent = next;
      return;
    }
    emailLink.hidden = true;
    emailLink.removeAttribute('href');
    emailLink.textContent = '';
  }

  function setSheetPhone(value) {
    const next = bookingPhoneValue(value);
    if (phone) {
      phone.value = next;
    }
    if (phoneEdit && phoneEdit !== phone) {
      phoneEdit.value = next;
    }
    if (!phoneLink) {
      return;
    }
    if (next) {
      phoneLink.hidden = false;
      phoneLink.href = `tel:${next}`;
      phoneLink.textContent = next;
      return;
    }
    phoneLink.hidden = true;
    phoneLink.removeAttribute('href');
    phoneLink.textContent = '';
  }

  function syncSheetContacts() {
    if (email) {
      const typed = emailEdit && emailEdit !== email && !emailEdit.hidden
        ? emailEdit.value
        : email.value;
      email.value = String(typed || '').trim();
    }
    if (phone) {
      const typed = phoneEdit && phoneEdit !== phone && !phoneEdit.hidden
        ? phoneEdit.value
        : phone.value;
      phone.value = bookingPhoneValue(typed);
    }
  }

  const nameSuggest = document.getElementById('booking-name-suggest');
  const companyField = document.getElementById('booking-company');
  let nameSuggestTimer = 0;
  let nameSuggestGen = 0;
  let nameSuggestHits = [];
  let nameSuggestOn = -1;

  function hideNameSuggest() {
    window.clearTimeout(nameSuggestTimer);
    nameSuggestGen += 1;
    nameSuggestHits = [];
    nameSuggestOn = -1;
    if (nameSuggest) {
      nameSuggest.innerHTML = '';
      nameSuggest.hidden = true;
    }
    if (customer) {
      customer.setAttribute('aria-expanded', 'false');
      customer.removeAttribute('aria-activedescendant');
    }
  }

  function paintNameSuggestOn() {
    if (!nameSuggest) {
      return;
    }
    nameSuggest.querySelectorAll('[role="option"]').forEach((el, i) => {
      el.classList.toggle('is-on', i === nameSuggestOn);
      if (i === nameSuggestOn && el.id) {
        customer.setAttribute('aria-activedescendant', el.id);
      }
    });
    if (nameSuggestOn < 0 && customer) {
      customer.removeAttribute('aria-activedescendant');
    }
  }

  function applyReminderHit(hit) {
    if (!hit) {
      return;
    }
    customer.value = String(hit.name || '');
    setSheetEmail(hit.email || '');
    setSheetPhone(hit.phone || '');
    if (address) {
      address.value = String(hit.address || '');
    }
    if (notes && String(hit.notes || '').trim() !== '') {
      notes.value = String(hit.notes || '');
      growNote(notes);
    }
    hideNameSuggest();
    sheetUserEdited = true;
    if (sheet.open) {
      placeCard(null);
    }
  }

  function clearBookingWhen() {
    starts.value = '';
    ends.value = '';
    sheet.classList.remove('is-allday');
    if (whenLabel) {
      whenLabel.textContent = 'Välj datum och tid';
    }
    if (whenDate) {
      whenDate.hidden = true;
    }
    if (whenTimes) {
      whenTimes.hidden = false;
    }
    if (whenDays) {
      whenDays.hidden = true;
    }
    if (startDay) {
      startDay.value = '';
      startDay.required = true;
      startDay.disabled = false;
    }
    paintSvDay();
    if (dayCount) {
      dayCount.required = false;
      dayCount.disabled = true;
    }
    if (startTime) {
      startTime.value = '';
      startTime.required = true;
    }
    if (endTime) {
      endTime.value = '';
      endTime.required = true;
    }
    showWhenError('');
    if (save) {
      save.disabled = false;
    }
  }

  function reminderPresetDate() {
    const now = new Date();
    const day = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 7);
    return `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`;
  }

  function openCreateFromReminder(hit, anchor) {
    action.value = 'booking.create';
    idField.value = '';
    clearBookingWhen();
    const presetDate = reminderPresetDate();
    setWhen(`${presetDate}T09:00`, `${presetDate}T10:00`);
    customer.value = '';
    setSheetEmail('');
    setSheetPhone('');
    if (address) {
      address.value = '';
    }
    if (notes) {
      notes.value = '';
    }
    hideNameSuggest();
    resetJob('');
    setAssignees(defaultAssignees());
    showMore(false);
    applyReminderHit(hit);
    if (hit && hit.assignee) {
      setAssignees([String(hit.assignee)]);
    }
    showSheet(anchor || newBookingBtn || sheet, 'none');
  }

  function showNameSuggest(hits) {
    if (!nameSuggest) {
      return;
    }
    nameSuggestHits = Array.isArray(hits) ? hits : [];
    nameSuggestOn = -1;
    nameSuggest.innerHTML = '';
    if (!nameSuggestHits.length) {
      nameSuggest.hidden = true;
      customer.setAttribute('aria-expanded', 'false');
      return;
    }
    nameSuggestHits.forEach((hit, i) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.id = `booking-name-hit-${hit.id || i}`;
      const nameEl = document.createElement('span');
      nameEl.className = 'booking-name-suggest-name';
      nameEl.textContent = hit.name || '';
      li.appendChild(nameEl);
      if (hit.address) {
        const addrEl = document.createElement('span');
        addrEl.className = 'booking-name-suggest-addr';
        addrEl.textContent = hit.address;
        li.appendChild(addrEl);
      }
      nameSuggest.appendChild(li);
    });
    nameSuggest.hidden = false;
    customer.setAttribute('aria-expanded', 'true');
    if (sheet.open) {
      placeCard(null);
    }
  }

  function requestNameSuggest(raw) {
    const query = String(raw || '').trim();
    window.clearTimeout(nameSuggestTimer);
    if (query.length < 3) {
      hideNameSuggest();
      return;
    }
    nameSuggestTimer = window.setTimeout(() => {
      const gen = ++nameSuggestGen;
      const action = bookingForm.getAttribute('action') || window.location.pathname;
      const url = new URL(action, window.location.origin);
      url.searchParams.set('intent', 'reminder-names');
      url.searchParams.set('format', 'json');
      url.searchParams.set('q', query);
      if (companyField && companyField.value) {
        url.searchParams.set('co', companyField.value);
      }
      fetch(url.toString(), {
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
      }).then((res) => {
        if (!res.ok) {
          throw new Error('name-suggest');
        }
        return res.json();
      }).then((data) => {
        if (gen !== nameSuggestGen) {
          return;
        }
        showNameSuggest(Array.isArray(data.hits) ? data.hits : []);
      }).catch(() => {
        if (gen === nameSuggestGen) {
          hideNameSuggest();
        }
      });
    }, 200);
  }

  if (customer && nameSuggest) {
    customer.addEventListener('input', () => {
      requestNameSuggest(customer.value);
    });
    customer.addEventListener('keydown', (event) => {
      if (nameSuggest.hidden || !nameSuggestHits.length) {
        if (event.key === 'Escape') {
          hideNameSuggest();
        }
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        nameSuggestOn = nameSuggestOn < nameSuggestHits.length - 1 ? nameSuggestOn + 1 : 0;
        paintNameSuggestOn();
        return;
      }
      if (event.key === 'ArrowUp') {
        event.preventDefault();
        nameSuggestOn = nameSuggestOn > 0 ? nameSuggestOn - 1 : nameSuggestHits.length - 1;
        paintNameSuggestOn();
        return;
      }
      if (event.key === 'Enter' && nameSuggestOn >= 0) {
        event.preventDefault();
        applyReminderHit(nameSuggestHits[nameSuggestOn]);
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        hideNameSuggest();
      }
    });
    nameSuggest.addEventListener('pointerdown', (event) => {
      const item = event.target.closest('[role="option"]');
      if (!item || !nameSuggest.contains(item)) {
        return;
      }
      event.preventDefault();
      const idx = Array.prototype.indexOf.call(nameSuggest.querySelectorAll('[role="option"]'), item);
      applyReminderHit(nameSuggestHits[idx]);
    });
    customer.addEventListener('blur', () => {
      window.setTimeout(() => {
        if (document.activeElement === customer || (nameSuggest && nameSuggest.contains(document.activeElement))) {
          return;
        }
        hideNameSuggest();
      }, 160);
    });
  }

  const phoneField = phoneEdit && phoneEdit !== phone ? phoneEdit : phone;
  if (phoneField) {
    phoneField.addEventListener('blur', () => {
      const next = bookingPhoneValue(phoneField.value);
      if (phoneField.value !== next) {
        phoneField.value = next;
      }
    });
  }

  function namesFrom(csv) {
    return String(csv || '')
      .split(',')
      .map((name) => name.trim())
      .filter(Boolean);
  }

  function setAssignees(names) {
    const chosen = new Set(names);
    bookingForm.querySelectorAll('input[name="assignees[]"]').forEach((box) => {
      box.checked = chosen.has(box.value);
    });
    paintWhoJob();
  }

  function defaultAssignees() {
    const group = bookingForm.querySelector('[data-js="assignee-chips"]');
    return namesFrom(group?.dataset.default || '');
  }

  function solDefaultAssignee() {
    if (String(calBoot().company || '') === 'malmo') {
      return '';
    }
    const box = bookingForm.querySelector('input[name="job_types[]"][value="sol"]');
    return box && box.dataset.who ? String(box.dataset.who) : '';
  }


  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function parseStamp(value) {
    const [date, time] = String(value || '').split('T');
    if (!date || !time) {
      return null;
    }
    const [y, m, d] = date.split('-').map(Number);
    const [hh, mm] = time.split(':').map(Number);
    return new Date(y, m - 1, d, hh, mm || 0, 0, 0);
  }

  function stampOf(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function formatWhen(startIso, endIso) {
    const s = parseStamp(startIso);
    const e = parseStamp(endIso);
    if (!s || !e) {
      return '';
    }
    const days = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
    return `${days[s.getDay()]} ${s.getDate()}/${s.getMonth() + 1} ${pad(s.getHours())}:${pad(s.getMinutes())}–${pad(e.getHours())}:${pad(e.getMinutes())}`;
  }

  function timePart(value) {
    const match = String(value || '').match(/^(\d{2}:\d{2})/);
    return match ? match[1] : '';
  }

  const whenError = document.getElementById('booking-when-error');

  function clockMinutes(clock) {
    const match = String(clock || '').match(/^(\d{2}):(\d{2})/);
    if (!match) {
      return null;
    }
    return (Number(match[1]) * 60) + Number(match[2]);
  }

  function minutesToClock(total) {
    const clamped = Math.max(0, Math.min((23 * 60) + 30, total));
    return `${pad(Math.floor(clamped / 60))}:${pad(clamped % 60)}`;
  }

  function snapHalfMinutes(total) {
    const rem = ((total % 30) + 30) % 30;
    if (rem === 0) {
      return total;
    }
    return rem < 15 ? total - rem : total + (30 - rem);
  }

  function durationMinutes(startIso, endIso) {
    const s = parseStamp(startIso);
    const e = parseStamp(endIso);
    if (!s || !e) {
      return null;
    }
    return Math.round((e.getTime() - s.getTime()) / 60000);
  }

  function snapTimed(startIso, endIso) {
    const s = parseStamp(startIso);
    if (!s) {
      return { start: startIso, end: endIso };
    }
    const day = `${s.getFullYear()}-${pad(s.getMonth() + 1)}-${pad(s.getDate())}`;
    let startMin = snapHalfMinutes((s.getHours() * 60) + s.getMinutes());
    if (startMin > (23 * 60) + 30) {
      startMin = (23 * 60) + 30;
    }
    const e = parseStamp(endIso);
    let endMin = e ? snapHalfMinutes((e.getHours() * 60) + e.getMinutes()) : null;
    if (endMin !== null && endMin > (23 * 60) + 30) {
      endMin = (23 * 60) + 30;
    }
    return {
      start: `${day}T${minutesToClock(startMin)}`,
      end: endMin === null ? endIso : `${day}T${minutesToClock(endMin)}`,
    };
  }

  function showWhenError(message) {
    if (!whenError) {
      return;
    }
    const text = String(message || '');
    whenError.hidden = text === '';
    whenError.textContent = text;
  }

  function guardHalfHourChoices() {
    if (!startTime || !endTime || startTime.tagName !== 'SELECT' || endTime.tagName !== 'SELECT') {
      return;
    }
    Array.from(startTime.options).forEach((opt) => {
      opt.disabled = false;
    });
    Array.from(endTime.options).forEach((opt) => {
      opt.disabled = false;
    });
  }

  function paintDurationError() {
    const allDay = isAllDay(starts.value, ends.value) || (sheet && sheet.classList.contains('is-allday'));
    if (!allDay) {
      guardHalfHourChoices();
    }
    const minutes = durationMinutes(starts.value, ends.value);
    const short = !allDay && minutes !== null && minutes < 60;
    showWhenError(short ? 'Bokningen måste vara minst en timme.' : '');
    if (save && bookingForm.getAttribute('data-busy') !== '1') {
      save.disabled = false;
    }
    return short;
  }

  function addMinutes(startIso, minutes) {
    const d = parseStamp(startIso);
    if (!d) {
      return startIso;
    }
    d.setMinutes(d.getMinutes() + minutes);
    return stampOf(d);
  }

  function isAllDay(startIso, endIso) {
    const s = parseStamp(startIso);
    const e = parseStamp(endIso);
    if (!s || !e) {
      return false;
    }
    return s.getHours() === 0 && s.getMinutes() === 0 && e.getHours() === 0 && e.getMinutes() === 0 && e.getTime() > s.getTime();
  }

  function formatDays(startIso, endIso) {
    const s = parseStamp(startIso);
    const e = parseStamp(endIso);
    if (!s || !e) {
      return '';
    }
    const days = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
    const last = new Date(e.getTime());
    last.setDate(last.getDate() - 1);
    const a = `${days[s.getDay()]} ${s.getDate()}/${s.getMonth() + 1}`;
    if (last.getFullYear() === s.getFullYear() && last.getMonth() === s.getMonth() && last.getDate() === s.getDate()) {
      return a;
    }
    return `${a}–${days[last.getDay()]} ${last.getDate()}/${last.getMonth() + 1}`;
  }

  function isWeekday(date) {
    const day = date.getDay();
    return day !== 0 && day !== 6;
  }

  function nextWeekday(date) {
    const d = new Date(date.getTime());
    while (!isWeekday(d)) {
      d.setDate(d.getDate() + 1);
    }
    return d;
  }

  function weekdayCount(startIso, endIso) {
    const s = parseStamp(String(startIso || '').slice(0, 10) + 'T00:00');
    const e = parseStamp(String(endIso || '').slice(0, 10) + 'T00:00');
    if (!s || !e || e.getTime() <= s.getTime()) {
      return 1;
    }
    let n = 0;
    const d = new Date(s.getTime());
    while (d.getTime() < e.getTime()) {
      if (isWeekday(d)) {
        n++;
      }
      d.setDate(d.getDate() + 1);
    }
    return Math.max(1, Math.min(10, n));
  }

  function addWeekdays(startIso, days) {
    const raw = String(startIso || '').slice(0, 10);
    let d = parseStamp(`${raw}T00:00`);
    if (!d) {
      return `${raw}T00:00`;
    }
    d = nextWeekday(d);
    let left = Math.max(1, Math.min(10, days));
    while (left > 0) {
      if (isWeekday(d)) {
        left--;
        if (left === 0) {
          d.setDate(d.getDate() + 1);
          return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T00:00`;
        }
      }
      d.setDate(d.getDate() + 1);
    }
    return stampOf(d);
  }

  const startDaySv = document.getElementById('booking-start-day-sv');
  const SV_DAYS = ['sön', 'mån', 'tis', 'ons', 'tor', 'fre', 'lör'];
  const SV_MONTHS = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
  /* Datumfältet visar «ons 7 okt» oavsett webbläsarens språk; värdet är fortfarande ISO. */
  function paintSvDay() {
    if (!startDaySv || !startDay) {
      return;
    }
    const m = String(startDay.value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!m) {
      startDaySv.textContent = 'Välj dag';
      return;
    }
    const dt = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    let label = SV_DAYS[dt.getDay()] + ' ' + dt.getDate() + ' ' + SV_MONTHS[dt.getMonth()];
    if (dt.getFullYear() !== new Date().getFullYear()) {
      label += ' ' + dt.getFullYear();
    }
    startDaySv.textContent = label;
  }
  if (startDay) {
    startDay.addEventListener('click', () => {
      if (typeof startDay.showPicker === 'function') {
        try {
          startDay.showPicker();
        } catch {
          // Picker kan nekas utan användargest; fältet går ändå att skriva i.
        }
      }
    });
    startDay.addEventListener('input', paintSvDay);
    startDay.addEventListener('change', paintSvDay);
  }

  function setWhen(startIso, endIso) {
    let start = startIso || '';
    let end = endIso || '';
    const allDay = isAllDay(start, end);
    if (!allDay) {
      const snapped = snapTimed(start, end);
      start = snapped.start;
      end = snapped.end;
    }
    let s = parseStamp(start);
    let e = parseStamp(end);
    if (!allDay && s && e && e.getTime() <= s.getTime()) {
      end = addMinutes(start, 60);
      e = parseStamp(end);
    } else if (!allDay && s && !e) {
      end = addMinutes(start, 60);
      e = parseStamp(end);
    }
    starts.value = start;
    ends.value = end;
    if (whenLabel) {
      whenLabel.textContent = allDay ? formatDays(start, end) : formatWhen(start, end);
    }
    sheet.classList.toggle('is-allday', allDay);
    if (whenDate) {
      whenDate.hidden = true;
    }
    if (whenTimes) {
      whenTimes.hidden = allDay;
    }
    if (whenDays) {
      whenDays.hidden = !allDay;
    }
    if (startDay) {
      startDay.required = true;
      startDay.disabled = false;
      if (s) {
        const day = allDay ? nextWeekday(new Date(s.getFullYear(), s.getMonth(), s.getDate())) : s;
        startDay.value = `${day.getFullYear()}-${pad(day.getMonth() + 1)}-${pad(day.getDate())}`;
      }
    }
    paintSvDay();
    if (dayCount) {
      dayCount.required = allDay;
      dayCount.disabled = !allDay;
      if (allDay) {
        dayCount.value = String(weekdayCount(start, end));
      }
    }
    if (startTime) {
      startTime.required = !allDay;
      if (s && !allDay) {
        startTime.value = `${pad(s.getHours())}:${pad(s.getMinutes())}`;
      }
    }
    if (endTime) {
      endTime.required = !allDay;
      if (e && !allDay) {
        endTime.value = `${pad(e.getHours())}:${pad(e.getMinutes())}`;
      }
    }
    paintDurationError();
  }

  function bookingDayValue() {
    if (startDay && /^\d{4}-\d{2}-\d{2}$/.test(startDay.value)) {
      return startDay.value;
    }
    const fromHidden = String(starts.value || '').slice(0, 10);
    return /^\d{4}-\d{2}-\d{2}$/.test(fromHidden) ? fromHidden : '';
  }

  function syncWhenFromTimes() {
    if (isAllDay(starts.value, ends.value) || (sheet && sheet.classList.contains('is-allday'))) {
      return;
    }
    const date = bookingDayValue();
    if (!date || !startTime) {
      return;
    }
    const startClock = timePart(startTime.value);
    if (!startClock) {
      return;
    }
    const endClock = timePart(endTime ? endTime.value : '');
    setWhen(`${date}T${startClock}`, endClock ? `${date}T${endClock}` : addMinutes(`${date}T${startClock}`, 60));
  }

  function syncWhenFromDays() {
    const date = bookingDayValue();
    if (!date) {
      return;
    }
    const n = dayCount ? parseInt(dayCount.value, 10) || 1 : 1;
    const start = `${date}T00:00`;
    setWhen(start, addWeekdays(start, n));
  }

  if (startTime) {
    startTime.addEventListener('change', syncWhenFromTimes);
    startTime.addEventListener('input', syncWhenFromTimes);
  }
  if (endTime) {
    endTime.addEventListener('change', syncWhenFromTimes);
    endTime.addEventListener('input', syncWhenFromTimes);
  }
  if (startDay) {
    const dayWrap = startDay.closest('.when-day');
    if (dayWrap) {
      dayWrap.addEventListener('click', (event) => {
        if (startDay.disabled || sheet.classList.contains('is-blocking-pickers')) {
          return;
        }
        if (event.target === startDay) {
          return;
        }
        if (typeof startDay.showPicker === 'function') {
          try {
            startDay.showPicker();
          } catch {
            startDay.focus();
          }
        } else {
          startDay.focus();
        }
      });
    }
    startDay.addEventListener('change', () => {
      if (isAllDay(starts.value, ends.value) || (sheet && sheet.classList.contains('is-allday'))) {
        syncWhenFromDays();
        return;
      }
      syncWhenFromTimes();
    });
  }
  if (dayCount) {
    dayCount.addEventListener('change', syncWhenFromDays);
  }
  function bookingSaveButtons() {
    const list = [];
    if (save) {
      list.push(save);
    }
    const extra = sheet.querySelector('[data-js="sheet-discard-save"]');
    if (extra && extra !== save) {
      list.push(extra);
    }
    return list;
  }

  function lockBookingSubmit() {
    bookingForm.setAttribute('data-busy', '1');
    bookingSaveButtons().forEach((btn) => {
      btn.setAttribute('aria-busy', 'true');
      btn.disabled = true;
    });
  }

  function releaseBookingSubmit() {
    bookingForm.removeAttribute('data-busy');
    bookingSaveButtons().forEach((btn) => {
      btn.removeAttribute('aria-busy');
      btn.disabled = false;
    });
  }

  bookingForm.addEventListener('submit', (event) => {
    if (bookingForm.getAttribute('data-busy') === '1') {
      event.preventDefault();
      return;
    }
    syncSheetContacts();
    const allDaySubmit = isAllDay(starts.value, ends.value) || (sheet && sheet.classList.contains('is-allday'));
    if (allDaySubmit) {
      syncWhenFromDays();
    } else {
      syncWhenFromTimes();
    }
    if (paintDurationError()) {
      event.preventDefault();
      hideSheetDiscard();
      releaseBookingSubmit();
      return;
    }
    if (bookingForm.dataset.overlapOk === '1') {
      delete bookingForm.dataset.overlapOk;
      lockBookingSubmit();
      return;
    }
    const names = bookingOverlapNames(starts.value, ends.value, idField ? idField.value : '');
    if (!names) {
      lockBookingSubmit();
      return;
    }
    event.preventDefault();
    hideSheetDiscard();
    if (window.confirm(names + ' är redan bokad då. Spara ändå?')) {
      bookingForm.dataset.overlapOk = '1';
      if (typeof bookingForm.requestSubmit === 'function') {
        bookingForm.requestSubmit();
      } else {
        lockBookingSubmit();
        bookingForm.submit();
      }
    } else {
      releaseBookingSubmit();
    }
  });
  window.addEventListener('pageshow', () => {
    releaseBookingSubmit();
  });

  function viewBox() {
    const vv = window.visualViewport;
    if (vv) {
      return { width: vv.width, height: vv.height, top: vv.offsetTop, left: vv.offsetLeft };
    }
    return { width: window.innerWidth, height: window.innerHeight, top: 0, left: 0 };
  }

  let cardAnchor = null;

  function placeCard(anchor) {
    if (anchor) {
      cardAnchor = anchor;
    }
    const box = viewBox();
    const vw = box.width;
    const vh = box.height;
    const pad = 8;
    const floor = box.top + pad;
    const ceiling = box.top + vh - pad;
    const maxH = Math.max(120, vh - pad * 2);
    sheet.style.maxHeight = `${maxH}px`;
    const phone = window.innerWidth < 720;
    const card = sheet.getBoundingClientRect();
    const ch = Math.min(card.height || maxH, maxH);

    if (phone) {
      sheet.classList.add('is-sheet');
      sheet.style.left = '0px';
      sheet.style.right = '0px';
      sheet.style.top = 'auto';
      sheet.style.bottom = '0px';
      sheet.style.width = '100%';
      sheet.style.maxWidth = '100%';
      sheet.style.margin = '0';
      sheet.style.maxHeight = `${Math.max(120, Math.round(vh))}px`;
      if (pointer) {
        pointer.dataset.side = 'down';
      }
      return;
    }
    sheet.classList.remove('is-sheet');
    sheet.style.bottom = '';
    sheet.style.margin = '';

    const slot = (anchor || cardAnchor)
      ? (anchor || cardAnchor).getBoundingClientRect()
      : { left: vw / 2, right: vw / 2, top: vh / 2, bottom: vh / 2, height: 0 };
    const cw = Math.min(card.width || 340, vw - pad * 2);
    const gap = 14;
    let left = slot.right + gap;
    let top = slot.top;
    let side = 'left';
    if (left + cw > box.left + vw - pad) {
      left = slot.left - cw - gap;
      side = 'right';
    }
    if (left < box.left + pad) {
      left = Math.max(box.left + pad, Math.min(box.left + vw - cw - pad, slot.left));
      top = slot.bottom + gap;
      side = 'up';
      if (top + ch > ceiling) {
        top = Math.max(floor, slot.top - ch - gap);
        side = 'down';
      }
    }
    if (top + ch > ceiling) {
      top = Math.max(floor, ceiling - ch);
    }
    if (top < floor) {
      top = floor;
    }
    const room = Math.max(120, ceiling - top);
    sheet.style.maxHeight = `${Math.min(maxH, room)}px`;
    sheet.style.left = `${Math.round(left)}px`;
    sheet.style.right = 'auto';
    sheet.style.width = '';
    sheet.style.maxWidth = '';
    sheet.style.top = `${Math.round(top)}px`;
    if (pointer) {
      pointer.dataset.side = side;
    }
    try {
      sheet.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    } catch {
      // Older WebKit without scrollIntoView options.
    }
  }

  let ignoreCloseUntil = 0;
  let skipSlotClick = false;
  let ignoreTimeAutofocusUntil = 0;
  let blockPickersTimer = 0;

  function isNativePickerField(el) {
    return !!(el && (el.type === 'time' || el.type === 'date'));
  }

  function blurNativePickers() {
    const ae = document.activeElement;
    if (isNativePickerField(ae) && sheet.contains(ae)) {
      ae.blur();
      focusSheetChrome();
    }
  }

  function focusSheetChrome() {
    try {
      sheet.focus({ preventScroll: true });
    } catch {
      sheet.focus();
    }
  }

  const discardBox = sheet.querySelector('[data-js="sheet-discard"]');
  const discardKeep = sheet.querySelector('[data-js="sheet-discard-keep"]');
  const discardDrop = sheet.querySelector('[data-js="sheet-discard-drop"]');
  let sheetOpenedSnapshot = '';
  let sheetUserEdited = false;
  let sheetBaselineTimer = 0;

  function bookingSheetSnapshot() {
    syncSheetContacts();
    const assignees = [...bookingForm.querySelectorAll('input[name="assignees[]"]:checked')].map((el) => el.value).sort().join(',');
    const jobs = [...bookingForm.querySelectorAll('input[name="job_types[]"]:checked')].map((el) => el.value).sort().join(',');
    return [
      action ? action.value : '',
      idField ? idField.value : '',
      starts ? starts.value : '',
      ends ? ends.value : '',
      customer ? String(customer.value || '').trim() : '',
      email ? String(email.value || '').trim() : '',
      phone ? String(phone.value || '').trim() : '',
      address ? String(address.value || '').trim() : '',
      notes ? String(notes.value || '').trim() : '',
      assignees,
      jobs,
    ].join('\n');
  }

  function isBookingSheetDirty() {
    return bookingSheetSnapshot() !== sheetOpenedSnapshot;
  }

  function absorbSheetAutofill() {
    if (sheetUserEdited || !sheet.open) {
      return;
    }
    captureSheetSnapshot();
  }

  function hideSheetDiscard() {
    if (discardBox) {
      discardBox.hidden = true;
    }
    sheet.classList.remove('is-discarding');
  }

  function closeBookingSheet() {
    hideSheetDiscard();
    sheet.close();
  }

  function showSheetDiscard() {
    if (!discardBox) {
      return;
    }
    discardBox.hidden = false;
    sheet.classList.add('is-discarding');
    if (discardKeep) {
      try {
        discardKeep.focus({ preventScroll: true });
      } catch {
        discardKeep.focus();
      }
    }
  }

  function requestCloseBookingSheet() {
    if (discardBox && !discardBox.hidden) {
      return;
    }
    if (!isBookingSheetDirty()) {
      closeBookingSheet();
      return;
    }
    showSheetDiscard();
  }

  function captureSheetSnapshot() {
    sheetOpenedSnapshot = bookingSheetSnapshot();
    hideSheetDiscard();
  }

  if (discardKeep) {
    discardKeep.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      hideSheetDiscard();
      ignoreCloseUntil = performance.now() + 400;
    });
  }
  if (discardDrop) {
    discardDrop.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeBookingSheet();
    });
  }
  bookingForm.addEventListener('beforeinput', () => {
    sheetUserEdited = true;
  });
  bookingForm.addEventListener('input', (event) => {
    const target = event.target;
    if (event.inputType) {
      sheetUserEdited = true;
      return;
    }
    if (target && target.matches && target.matches('input[type="time"], input[type="date"], input[type="checkbox"], select')) {
      sheetUserEdited = true;
    }
  });
  bookingForm.addEventListener('change', (event) => {
    const target = event.target;
    if (target && target.matches && target.matches('input[type="time"], input[type="date"], input[type="checkbox"], select')) {
      sheetUserEdited = true;
    }
  });
  bookingForm.addEventListener('animationstart', (event) => {
    if (event.animationName === 'jeabAutofillStart') {
      absorbSheetAutofill();
    }
  });

  function showSheet(anchor, focusMode) {
    ignoreCloseUntil = performance.now() + 400;
    if (!sheet.open) {
      sheet.showModal();
    }
    sheetUserEdited = false;
    captureSheetSnapshot();
    window.clearTimeout(sheetBaselineTimer);
    sheetBaselineTimer = window.setTimeout(absorbSheetAutofill, 700);
    document.body.classList.add('sheet-open');
    document.body.classList.add('sheet-open');
    if (whoJob) {
      whoJob.open = true;
    }
    paintWhoJob();
    growNote(notes);
    if (focusMode !== 'time') {
      ignoreTimeAutofocusUntil = performance.now() + 500;
      sheet.classList.add('is-blocking-pickers');
      window.clearTimeout(blockPickersTimer);
      blockPickersTimer = window.setTimeout(() => {
        sheet.classList.remove('is-blocking-pickers');
      }, 500);
      focusSheetChrome();
      blurNativePickers();
    } else {
      ignoreTimeAutofocusUntil = 0;
      sheet.classList.remove('is-blocking-pickers');
      window.clearTimeout(blockPickersTimer);
    }
    requestAnimationFrame(() => {
      growNote(notes);
      placeCard(anchor);
      requestAnimationFrame(() => {
        placeCard(anchor);
        if (focusMode === 'time' && startTime && whenTimes && !whenTimes.hidden) {
          try {
            startTime.focus({ preventScroll: true });
          } catch {
            startTime.focus();
          }
          if (typeof startTime.showPicker === 'function') {
            try {
              startTime.showPicker();
            } catch {
              // Focus is enough when the native picker is already open.
            }
          }
        } else {
          focusSheetChrome();
          blurNativePickers();
        }
        if (!sheetUserEdited) {
          captureSheetSnapshot();
        }
      });
    });
  }

  sheet.addEventListener('focusin', (event) => {
    if (performance.now() >= ignoreTimeAutofocusUntil) {
      return;
    }
    if (!isNativePickerField(event.target)) {
      return;
    }
    event.target.blur();
    focusSheetChrome();
  });

  sheet.addEventListener('close', () => {
    document.body.classList.remove('sheet-open');
    ignoreTimeAutofocusUntil = 0;
    sheet.classList.remove('is-blocking-pickers');
    window.clearTimeout(blockPickersTimer);
    hideNameSuggest();
    hideSheetDiscard();
  });

  sheet.addEventListener('cancel', (event) => {
    event.preventDefault();
    if (performance.now() < ignoreCloseUntil) {
      return;
    }
    requestCloseBookingSheet();
  });

  notes.addEventListener('input', () => {
    growNote(notes);
    if (sheet.open) {
      placeCard(null);
    }
  });
  bookingForm.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
      return;
    }
    if (target.matches('input[name="assignees[]"]') && target.checked && target.dataset.job) {
      const jobOn = bookingForm.querySelector('input[name="job_types[]"]:checked');
      if (!jobOn) {
        resetJob(target.dataset.job);
      }
    }
    if (target.matches('input[name="job_types[]"]') && target.checked) {
      applySelectedJobRange(target.value);
      const solWho = solDefaultAssignee();
      if (target.value === 'sol' && solWho) {
        const checked = [...bookingForm.querySelectorAll('input[name="assignees[]"]:checked')].map((box) => box.value);
        const filterWho = String(calBoot().who || '').trim();
        const baseline = filterWho ? [filterWho] : defaultAssignees();
        const same = checked.length === baseline.length && checked.every((name, index) => name === baseline[index]);
        if (checked.length === 0 || same) {
          setAssignees([solWho]);
        }
      } else if (target.dataset.who) {
        const whoOn = bookingForm.querySelector('input[name="assignees[]"]:checked');
        if (!whoOn) {
          setAssignees([target.dataset.who]);
        }
      }
    }
    if (target.matches('input[name="assignees[]"], input[name="job_types[]"]')) {
      paintWhoJob();
    }
  });
  if (whoJob) {
    whoJob.addEventListener('toggle', () => {
      if (!sheet.open) {
        return;
      }
      requestAnimationFrame(() => {
        placeCard(cardAnchor);
        requestAnimationFrame(() => placeCard(cardAnchor));
      });
    });
  }
  if (window.visualViewport) {
    const relayout = () => {
      if (sheet.open) {
        placeCard(null);
      }
    };
    window.visualViewport.addEventListener('resize', relayout);
    window.visualViewport.addEventListener('scroll', relayout);
  }

  function selectedJobKey() {
    const chip = document.querySelector('.cal-filter .chip-job.is-on');
    return chip && chip.dataset.job ? chip.dataset.job : '';
  }

  function productClickRange(startIso, endIso, dragged, jobKey) {
    const job = jobKey !== undefined ? String(jobKey || '') : selectedJobKey();
    const day = String(startIso || '').slice(0, 10);
    if (!job || !day) {
      return { start: startIso, end: endIso, job: '', skipFiveDay: false };
    }
    if (job === 'sol') {
      const week = solWeekRange(day);
      if (week) {
        return { start: week.start, end: week.end, job, skipFiveDay: true };
      }
    }
    if (dragged) {
      return { start: startIso, end: endIso, job, skipFiveDay: false };
    }
    if (job === 'laddbox') {
      const hour = parseInt(String(startIso || '').slice(11, 13), 10);
      if (Number.isNaN(hour) || hour < 12) {
        return { start: `${day}T07:00`, end: `${day}T12:00`, job, skipFiveDay: true };
      }
      return { start: `${day}T12:00`, end: `${day}T16:00`, job, skipFiveDay: true };
    }
    if (job === 'batteri') {
      // Heldag 07–16 (9 h). En dragning behåller det markerade intervallet.
      return { start: `${day}T07:00`, end: `${day}T16:00`, job, skipFiveDay: true };
    }
    return { start: startIso, end: endIso, job, skipFiveDay: false };
  }

  function applySelectedJobRange(job) {
    if (!action || action.value !== 'booking.create' || !job) {
      return;
    }
    const day = String((starts && starts.value) || '').slice(0, 10);
    if (!day) {
      return;
    }
    const baseStart = !isAllDay(starts.value, ends.value) && String(starts.value || '').slice(11, 16)
      ? starts.value
      : `${day}T09:00`;
    const baseEnd = !isAllDay(starts.value, ends.value) && String(ends.value || '').slice(11, 16)
      ? ends.value
      : `${day}T10:00`;
    const preset = productClickRange(baseStart, baseEnd, false, job);
    setWhen(preset.start, preset.end);
  }

  function openCreate(startIso, endIso, anchor, dragged) {
    action.value = 'booking.create';
    idField.value = '';
    const preset = productClickRange(startIso, endIso, !!dragged);
    let start = preset.start;
    let end = preset.end || addMinutes(startIso, 60);
    if (!isAllDay(start, end)) {
      const span = durationMinutes(start, end);
      if (span !== null && span < 60) {
        end = addMinutes(start, 60);
      }
    }
    if (!preset.skipFiveDay && isAllDay(start, end) && weekdayCount(start, end) < 2) {
      end = addWeekdays(start, 5);
    }
    setWhen(start, end);
    customer.value = '';
    setSheetEmail('');
    setSheetPhone('');
    address.value = '';
    notes.value = '';
    hideNameSuggest();
    resetJob(preset.job);
    const solWho = preset.job === 'sol' ? solDefaultAssignee() : '';
    const filterWho = String(calBoot().who || '').trim();
    setAssignees(solWho ? [solWho] : (filterWho ? [filterWho] : defaultAssignees()));
    showMore(false);
    showSheet(anchor, isAllDay(start, end) ? 'none' : 'time');
  }

  let sheetEditEl = null;

  function openEdit(btn) {
    sheetEditEl = btn;
    action.value = 'booking.update';
    idField.value = btn.dataset.id || '';
    setWhen(btn.dataset.starts || '', btn.dataset.ends || addMinutes(btn.dataset.starts || '', 60));
    customer.value = btn.dataset.customer || '';
    setSheetEmail(btn.dataset.email || '');
    setSheetPhone(btn.dataset.phone || '');
    address.value = btn.dataset.address || '';
    notes.value = btn.dataset.notes || '';
    hideNameSuggest();
    resetJob(btn.dataset.job || '');
    setAssignees(namesFrom(btn.dataset.assignees || ''));
    showMore(true);
    showSheet(btn, 'none');
  }

  /* Läsvy: klick på en bokning visar tid, adress, telefon, e-post och länk till
     ordern/påminnelsen. «Ändra» öppnar formuläret. Avboka ligger i «…». På mobil
     är det ett bottenark med stora Ring- och Navigera-knappar. */
  const readBox = document.getElementById('booking-read');
  let readEl = null;

  function readPart(name) {
    return readBox ? readBox.querySelector('[data-js="' + name + '"]') : null;
  }

  function setReadLink(name, href, text) {
    const el = readPart(name);
    if (!el) {
      return;
    }
    if (href) {
      el.href = href;
      el.textContent = text;
      el.hidden = false;
    } else {
      el.removeAttribute('href');
      el.textContent = '';
      el.hidden = true;
    }
  }

  function openRead(el) {
    if (!readBox || typeof readBox.showModal !== 'function') {
      openEdit(el);
      return;
    }
    if (readBox.open && readEl === el) {
      return;
    }
    readEl = el;
    const d = el.dataset;
    const phoneValue = bookingPhoneValue(d.phone || '');
    const emailValue = String(d.email || '').trim();
    const addr = String(d.address || '').trim();
    const jobs = String(d.job || '').split(',').map((j) => peekJobs[j.trim()] || '').filter(Boolean).join(', ');
    readPart('read-when').textContent = d.when || '';
    readPart('read-address').textContent = addr || d.customer || 'Bokning';
    readPart('read-customer').textContent = addr ? (d.customer || '') : '';
    readPart('read-customer').hidden = !addr || !d.customer;
    setReadLink('read-phone', phoneValue ? 'tel:' + phoneValue : '', phoneValue);
    readPart('read-phone-row').hidden = !phoneValue;
    setReadLink('read-email', emailValue ? 'mailto:' + emailValue : '', emailValue);
    readPart('read-email-row').hidden = !emailValue;
    const who = [jobs, String(d.assignees || '').split(',').map((n) => n.trim()).filter(Boolean).join(', ')].filter(Boolean).join(' · ');
    readPart('read-who').textContent = who;
    readPart('read-who-row').hidden = !who;
    readPart('read-notes').textContent = d.notes || '';
    readPart('read-notes').hidden = !String(d.notes || '').trim();
    setReadLink('read-call', phoneValue ? 'tel:' + phoneValue : '', 'Ring');
    setReadLink('read-nav', addr ? 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addr) : '', 'Navigera');
    readBox.querySelector('.cal-read-big').hidden = !phoneValue && !addr;
    if (d.order) {
      setReadLink('read-open', d.order, 'Öppna order');
    } else if (d.reminder) {
      setReadLink('read-open', d.reminder, 'Öppna påminnelse');
    } else {
      setReadLink('read-open', '', '');
    }
    const more = readBox.querySelector('.cal-read-more');
    if (more) {
      more.open = false;
    }
    readBox.classList.toggle('is-sheet', calIsPhone());
    if (!readBox.open) {
      readBox.showModal();
    }
    const edit = readPart('read-edit');
    if (edit) {
      try {
        edit.focus({ preventScroll: true });
      } catch {
        edit.focus();
      }
    }
  }

  function closeRead() {
    if (readBox && readBox.open) {
      readBox.close();
    }
  }

  function elementUnderDialog(dialog, x, y) {
    if (!dialog) {
      return null;
    }
    const prev = dialog.style.pointerEvents;
    dialog.style.pointerEvents = 'none';
    const under = document.elementFromPoint(x, y);
    dialog.style.pointerEvents = prev;
    return under;
  }

  if (readBox) {
    function releaseReadToday() {
      const el = readEl;
      if (!el) {
        closeRead();
        return;
      }
      const raw = String(el.dataset.starts || '');
      const match = raw.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
      const day = match ? match[1] : '';
      const time = match ? match[2] : '';
      const boot = calBootData();
      const target = String(boot.today || '');
      const plan = (typeof jeabCalPopupToday === 'function')
        ? jeabCalPopupToday(day, time, target)
        : { action: day && target && day === target ? 'feedback' : 'close', day: target || day, time };
      if (plan.action === 'feedback') {
        closeRead();
        requestAnimationFrame(() => flashCalToday(plan.day || day, el));
        return;
      }
      if (plan.action === 'set-today' && plan.day && plan.time) {
        const prevStart = String(el.dataset.starts || '');
        const prevEnd = String(el.dataset.ends || '');
        const nextStart = plan.day + prevStart.slice(10);
        const nextEnd = (prevEnd.slice(0, 10) === prevStart.slice(0, 10) ? plan.day : prevEnd.slice(0, 10)) + prevEnd.slice(10);
        el.dataset.starts = nextStart;
        el.dataset.ends = nextEnd;
        if (el.classList.contains('wg-allday-item') || el.dataset.allday === '1') {
          applyAlldayVisual(el, nextStart, nextEnd);
        } else if (el.closest('.week-grid')) {
          applyTimedVisual(el, nextStart, nextEnd);
        }
        closeRead();
        queueMovedSave(el, nextStart, nextEnd, prevStart, prevEnd);
        requestAnimationFrame(() => flashCalToday(plan.day, el));
        return;
      }
      closeRead();
    }
    readBox.addEventListener('click', (event) => {
      if (event.target !== readBox) {
        return;
      }
      const under = elementUnderDialog(readBox, event.clientX, event.clientY);
      const todayLink = under && under.closest ? under.closest('a.week-today') : null;
      if (todayLink) {
        event.preventDefault();
        releaseReadToday();
        return;
      }
      closeRead();
    });
    const todayBtn = readPart('read-today');
    if (todayBtn) {
      todayBtn.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        releaseReadToday();
      });
    }
    const closeBtn = readPart('read-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', closeRead);
    }
    const editBtn = readPart('read-edit');
    if (editBtn) {
      editBtn.addEventListener('click', () => {
        const el = readEl;
        closeRead();
        if (el) {
          openEdit(el);
          if (calIsPhone() && startTime && whenTimes && !whenTimes.hidden) {
            try {
              startTime.focus({ preventScroll: true });
            } catch {
              startTime.focus();
            }
          }
        }
      });
    }
    function shiftRead(dir) {
      const el = readEl;
      if (!el) {
        return;
      }
      const s = parseStamp(el.dataset.starts || '');
      const e = parseStamp(el.dataset.ends || '');
      if (!s || !e) {
        return;
      }
      const step = dir < 0 ? -1 : 1;
      s.setDate(s.getDate() + step);
      e.setDate(e.getDate() + step);
      while (s.getDay() === 0 || s.getDay() === 6) {
        s.setDate(s.getDate() + step);
        e.setDate(e.getDate() + step);
      }
      const prevStart = el.dataset.starts || '';
      const prevEnd = el.dataset.ends || '';
      const nextStart = stampOf(s);
      const nextEnd = stampOf(e);
      el.dataset.starts = nextStart;
      el.dataset.ends = nextEnd;
      if (el.classList.contains('wg-allday-item') || el.dataset.allday === '1') {
        applyAlldayVisual(el, nextStart, nextEnd);
      } else if (el.closest('.week-grid')) {
        applyTimedVisual(el, nextStart, nextEnd);
      }
      closeRead();
      queueMovedSave(el, nextStart, nextEnd, prevStart, prevEnd);
    }
    const prevDay = readPart('read-prev-day');
    const nextDay = readPart('read-next-day');
    if (prevDay) {
      prevDay.addEventListener('click', () => shiftRead(-1));
    }
    if (nextDay) {
      nextDay.addEventListener('click', () => shiftRead(1));
    }
    const cancelBtn = readPart('read-cancel');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => {
        const el = readEl;
        if (!el || !el.dataset.id || cancelBtn.disabled || cancelBtn.getAttribute('data-busy') === '1') {
          return;
        }
        if (!window.confirm('Avboka den här bokningen?')) {
          return;
        }
        armAvboka(cancelBtn, el.dataset.id);
      });
    }
  }

  if (delBtn) {
    delBtn.addEventListener('click', () => {
      if (!idField.value || action.value === 'booking.create' || delBtn.getAttribute('data-busy') === '1') {
        return;
      }
      if (!window.confirm('Avboka den här bokningen?')) {
        return;
      }
      armAvboka(delBtn, idField.value);
    });
  }

  let pendingCancel = null;

  function setAvbokaBusy(btn, on) {
    if (!btn) {
      return;
    }
    btn.disabled = !!on;
    if (on) {
      btn.setAttribute('data-busy', '1');
      btn.setAttribute('aria-busy', 'true');
    } else {
      btn.removeAttribute('data-busy');
      btn.removeAttribute('aria-busy');
    }
  }

  function hideCalToast() {
    const toast = document.querySelector('[data-js="cal-toast"]');
    if (!toast) {
      return;
    }
    toast.hidden = true;
    const undo = toast.querySelector('[data-js="cal-toast-undo"]');
    if (undo) {
      undo.hidden = true;
    }
  }

  function showCalToast(text, onUndo) {
    const toast = document.querySelector('[data-js="cal-toast"]');
    if (!toast) {
      return;
    }
    const span = toast.querySelector('[data-js="cal-toast-text"]');
    const undo = toast.querySelector('[data-js="cal-toast-undo"]');
    if (span) {
      span.textContent = text;
    }
    if (undo) {
      undo.hidden = typeof onUndo !== 'function';
      undo.onclick = () => {
        if (typeof onUndo === 'function') {
          onUndo();
        }
      };
    }
    toast.hidden = false;
  }

  function commitAvboka(state, keepalive) {
    if (!state || state.sent || state.undone || !bookingForm) {
      return;
    }
    state.sent = true;
    clearTimeout(state.timer);
    action.value = 'booking.cancel';
    idField.value = state.id;
    if (keepalive && typeof fetch === 'function') {
      const csrf = bookingForm.querySelector('input[name="_csrf"]');
      const body = new URLSearchParams();
      body.set('_csrf', csrf ? String(csrf.value || '') : '');
      body.set('action', 'booking.cancel');
      body.set('id', state.id);
      fetch(bookingForm.action || window.location.href, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        keepalive: true,
      }).catch(() => {});
      return;
    }
    bookingForm.submit();
  }

  function armAvboka(btn, id) {
    const bookingId = String(id || '').trim();
    if (bookingId === '' || (pendingCancel && !pendingCancel.sent && !pendingCancel.undone)) {
      return;
    }
    const state = { id: bookingId, sent: false, undone: false, timer: 0 };
    pendingCancel = state;
    closeRead();
    if (sheet && sheet.open) {
      sheet.close();
    }
    setAvbokaBusy(btn, true);
    // Avbokas direkt efter bekräftelsen. Ingen sexsekundersväntan.
    showCalToast('Avbokar…');
    commitAvboka(state, false);
  }


  const newBookingBtn = document.querySelector('[data-js="new-booking"]');
  if (newBookingBtn) {
    newBookingBtn.addEventListener('click', (event) => {
      event.preventDefault();
      const dayNode = document.querySelector('.wg-day-col.is-today')
        || document.querySelector('[data-js="month-day"].is-today')
        || document.querySelector('.wg-day-col')
        || document.querySelector('[data-js="month-day"]');
      const now = new Date();
      const y = now.getFullYear();
      const m = String(now.getMonth() + 1).padStart(2, '0');
      const d = String(now.getDate()).padStart(2, '0');
      const day = (dayNode && dayNode.dataset.day) || `${y}-${m}-${d}`;
      openCreate(`${day}T09:00`, `${day}T10:00`, newBookingBtn, false);
    });
  }

  let slots = Array.from(document.querySelectorAll('[data-js="slot"]'));
  let dayCells = Array.from(document.querySelectorAll('[data-js="allday"]'));
  let drag = null;

  function nearestByX(nodes, x) {
    let best = null;
    let bestDist = Infinity;
    nodes.forEach((node) => {
      const box = node.getBoundingClientRect();
      if (x >= box.left && x < box.right) {
        best = node;
        bestDist = 0;
        return;
      }
      const dist = x < box.left ? box.left - x : x - box.right;
      if (dist < bestDist) {
        bestDist = dist;
        best = node;
      }
    });
    return best;
  }

  function dayAt(x, y) {
    if (dayCells.length) {
      const hit = nearestByX(dayCells, x);
      if (hit) {
        return hit;
      }
    }
    const el = document.elementFromPoint(x, y);
    return el ? el.closest('[data-js="allday"]') : null;
  }

  function sameDay(a, b) {
    return String(a.dataset.starts || '').slice(0, 10) === String(b.dataset.starts || '').slice(0, 10);
  }

  let timedGhost = null;
  let dayGhost = null;

  function ensureGhost(allDay) {
    if (allDay) {
      if (!dayGhost) {
        dayGhost = document.createElement('div');
        dayGhost.className = 'wg-item wg-allday-item wg-ghost is-laddbox';
        dayGhost.setAttribute('aria-hidden', 'true');
      }
      return dayGhost;
    }
    if (!timedGhost) {
      timedGhost = document.createElement('div');
      timedGhost.className = 'wg-item wg-ghost is-laddbox';
      timedGhost.setAttribute('aria-hidden', 'true');
    }
    return timedGhost;
  }

  function hideGhosts() {
    if (timedGhost) {
      timedGhost.remove();
    }
    if (dayGhost) {
      dayGhost.remove();
    }
  }

  function paintSolWeekGhost(startIso) {
    const week = solWeekRange(String(startIso || '').slice(0, 10));
    if (!week) {
      return false;
    }
    if (timedGhost) {
      timedGhost.remove();
    }
    const ghost = ensureGhost(true);
    ghost.className = 'wg-item wg-allday-item wg-ghost is-sol';
    const row = document.querySelector('.wg-allday');
    if (row && ghost.parentElement !== row) {
      row.appendChild(ghost);
    }
    applyAlldayVisual(ghost, week.start, week.end);
    return true;
  }

  function ghostTone(job) {
    if (job === 'batteri' || job === 'sol' || job === 'laddbox' || job === 'service') {
      return 'is-' + job;
    }
    return 'is-laddbox';
  }

  function paintProductGhost(startIso, endIso, dragged) {
    const preset = productClickRange(startIso, endIso, dragged);
    if (preset.job === 'sol' && paintSolWeekGhost(preset.start)) {
      return;
    }
    const timedProduct = !dragged && (preset.job === 'batteri' || preset.job === 'laddbox') && !isAllDay(preset.start, preset.end);
    if (timedProduct || !isAllDay(preset.start, preset.end)) {
      if (dayGhost) {
        dayGhost.remove();
      }
      const ghost = ensureGhost(false);
      ghost.className = 'wg-item wg-ghost ' + ghostTone(preset.job);
      applyTimedVisual(ghost, preset.start, preset.end);
      return;
    }
    if (timedGhost) {
      timedGhost.remove();
    }
    const ghost = ensureGhost(true);
    ghost.className = 'wg-item wg-allday-item wg-ghost ' + ghostTone(preset.job);
    const row = document.querySelector('.wg-allday');
    if (row && ghost.parentElement !== row) {
      row.appendChild(ghost);
    }
    applyAlldayVisual(ghost, preset.start, preset.end);
  }

  function paintTimedGhost(from, to) {
    if (!from || !to) {
      if (timedGhost) {
        timedGhost.remove();
      }
      return;
    }
    const range = rangeOf(from, to);
    paintProductGhost(range.start, range.end, from !== to);
  }

  function paintAlldayGhost(from, to) {
    if (!from || !to) {
      if (dayGhost) {
        dayGhost.remove();
      }
      return;
    }
    const range = dayRangeOf(from, to);
    paintProductGhost(range.start, range.end, from !== to);
  }

  function colAt(x) {
    return nearestByX(Array.from(document.querySelectorAll('.wg-day-col')), x);
  }

  function slotAt(x, y) {
    const col = colAt(x);
    if (!col) {
      const el = document.elementFromPoint(x, y);
      return el ? el.closest('[data-js="slot"]') : null;
    }
    const cells = Array.from(col.querySelectorAll('[data-js="slot"]'));
    if (!cells.length) {
      return null;
    }
    const hit = cells.find((cell) => {
      const box = cell.getBoundingClientRect();
      return y >= box.top && y < box.bottom;
    });
    if (hit) {
      return hit;
    }
    const first = cells[0].getBoundingClientRect();
    const last = cells[cells.length - 1].getBoundingClientRect();
    if (y < first.top) {
      return cells[0];
    }
    if (y >= last.bottom) {
      return cells[cells.length - 1];
    }
    return cells[0];
  }

  function moveIntervalToDay(startIso, endIso, day) {
    const duration = minutesBetween(startIso, endIso);
    const clock = String(startIso || '').slice(11, 16) || '07:00';
    const start = `${day}T${clock}`;
    return { start, end: addMinutes(start, Math.max(60, duration)) };
  }

  function rangeOf(a, b) {
    const startA = a.dataset.starts;
    const startB = b.dataset.starts;
    const first = startA <= startB ? a : b;
    const last = startA <= startB ? b : a;
    return { start: first.dataset.starts, end: last.dataset.ends, anchor: first };
  }

  function slotHeight() {
    const cell = slots[0];
    return cell ? cell.getBoundingClientRect().height : 21;
  }

  function minutesBetween(startIso, endIso) {
    const s = parseStamp(startIso);
    const e = parseStamp(endIso);
    if (!s || !e) {
      return 30;
    }
    return Math.round((e.getTime() - s.getTime()) / 60000);
  }

  function addDaysIso(stamp, days) {
    const raw = String(stamp || '');
    const iso = raw.includes('T') ? raw : `${raw}T00:00`;
    const d = parseStamp(iso);
    if (!d) {
      return raw;
    }
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function weekMondayIso(day) {
    const d = parseStamp(`${String(day || '').slice(0, 10)}T00:00`);
    if (!d) {
      return '';
    }
    const back = d.getDay() === 0 ? 6 : d.getDay() - 1;
    d.setDate(d.getDate() - back);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  function solWeekRange(day) {
    const monday = weekMondayIso(day);
    if (!monday) {
      return null;
    }
    return { start: `${monday}T00:00`, end: `${addDaysIso(monday, 5)}T00:00` };
  }

  function dayDiff(a, b) {
    return minutesBetween(`${String(a).slice(0, 10)}T00:00`, `${String(b).slice(0, 10)}T00:00`) / 1440;
  }

  function applyTimedVisual(btn, startIso, endIso) {
    const day = String(startIso || '').slice(0, 10);
    const col = slots.map((slot) => slot.closest('.wg-day-col')).find((node) => {
      const first = node && node.querySelector('[data-js="slot"]');
      return first && String(first.dataset.starts || '').slice(0, 10) === day;
    });
    const s = parseStamp(startIso);
    const e = parseStamp(endIso);
    if (!s || !e) {
      return;
    }
    const startMin = (s.getHours() * 60) + s.getMinutes();
    const endMin = (e.getHours() * 60) + e.getMinutes();
    const row = Math.floor((startMin - 420) / 30) + 1;
    const span = Math.max(1, Math.ceil((endMin - startMin) / 30));
    btn.style.gridRow = `${row} / span ${span}`;
    if (col && btn.parentElement !== col) {
      col.appendChild(btn);
    }
  }

  function applyAlldayVisual(btn, startIso, endIso) {
    const startDay = String(startIso || '').slice(0, 10);
    const endDay = String(endIso || '').slice(0, 10);
    const first = dayCells[0] ? dayCells[0].dataset.day : '';
    if (!first) {
      return;
    }
    const col = dayDiff(first, startDay) + 2;
    const span = Math.max(1, dayDiff(startDay, endDay));
    btn.style.gridColumn = `${col} / span ${span}`;
  }

  function fillBookingForm(btn, startIso, endIso) {
    action.value = 'booking.update';
    idField.value = btn.dataset.id || '';
    customer.value = btn.dataset.customer || '';
    setSheetEmail(btn.dataset.email || '');
    setSheetPhone(btn.dataset.phone || '');
    address.value = btn.dataset.address || '';
    notes.value = btn.dataset.notes || '';
    resetJob(btn.dataset.job || '');
    setAssignees(namesFrom(btn.dataset.assignees || ''));
    setWhen(startIso, endIso);
  }

  let moveUndo = null;

  function moveUndoBar() {
    let bar = document.querySelector('[data-js="cal-move-undo"]');
    if (bar) {
      return bar;
    }
    bar = document.createElement('div');
    bar.className = 'cal-move-undo';
    bar.dataset.js = 'cal-move-undo';
    bar.setAttribute('role', 'status');
    bar.hidden = true;
    bar.innerHTML = '<p></p><button type="button" data-js="cal-move-keep">Behåll</button><button type="button" data-js="cal-move-undo-btn">Ångra</button>';
    bar.querySelector('[data-js="cal-move-keep"]').addEventListener('click', () => commitMoveUndo(true));
    bar.querySelector('[data-js="cal-move-undo-btn"]').addEventListener('click', () => commitMoveUndo(false));
    document.body.appendChild(bar);
    return bar;
  }

  function hideMoveUndo() {
    const bar = document.querySelector('[data-js="cal-move-undo"]');
    if (bar) {
      bar.hidden = true;
    }
  }

  function commitMoveUndo(send) {
    const pending = moveUndo;
    if (!pending || pending.sent) {
      return;
    }
    window.clearTimeout(pending.timer);
    moveUndo = null;
    hideMoveUndo();
    if (!send) {
      pending.btn.dataset.starts = pending.prevStart;
      pending.btn.dataset.ends = pending.prevEnd;
      if (pending.allDay) {
        applyAlldayVisual(pending.btn, pending.prevStart, pending.prevEnd);
      } else if (!pending.btn.classList.contains('mv-item')) {
        applyTimedVisual(pending.btn, pending.prevStart, pending.prevEnd);
      }
      return;
    }
    pending.sent = true;
    saveMoved(pending.btn, pending.startIso, pending.endIso);
  }

  function queueMovedSave(btn, startIso, endIso, prevStart, prevEnd) {
    if (moveUndo && !moveUndo.sent) {
      commitMoveUndo(true);
    }
    const overlap = bookingOverlapNames(startIso, endIso, btn.dataset.id || '', btn.dataset.assignees || '');
    const mail = 'Bekräftelsemejl skickas inte av flytten.';
    const text = overlap
      ? `${mail} ${overlap} är redan bokad då.`
      : mail;
    const timer = window.setTimeout(() => commitMoveUndo(true), 8000);
    moveUndo = {
      btn,
      startIso,
      endIso,
      prevStart,
      prevEnd,
      allDay: btn.classList.contains('wg-allday-item') || btn.dataset.allday === '1',
      timer,
      sent: false,
    };
    const bar = moveUndoBar();
    bar.querySelector('p').textContent = text;
    bar.hidden = false;
  }

  function bookingOverlapNames(startIso, endIso, ignoreId, assigneeCsv) {
    const mine = new Set();
    const source = assigneeCsv !== undefined
      ? String(assigneeCsv || '')
      : [...bookingForm.querySelectorAll('input[name="assignees[]"]:checked')].map((el) => el.value).join(',');
    source.split(',').forEach((name) => {
      const key = name.trim().toLowerCase();
      if (key) {
        mine.add(key);
      }
    });
    if (!mine.size) {
      return '';
    }
    const start = String(startIso || '').replace(' ', 'T');
    const end = String(endIso || '').replace(' ', 'T');
    const hits = new Set();
    document.querySelectorAll('[data-js="edit-booking"][data-starts]').forEach((el) => {
      if (ignoreId && el.dataset.id === String(ignoreId)) {
        return;
      }
      const a = el.dataset.starts || '';
      const b = el.dataset.ends || '';
      if (!(a < end && start < b)) {
        return;
      }
      String(el.dataset.assignees || '').split(',').forEach((name) => {
        const key = name.trim().toLowerCase();
        if (mine.has(key)) {
          hits.add(name.trim());
        }
      });
    });
    return [...hits].join(', ');
  }

  function saveMoved(btn, startIso, endIso) {
    fillBookingForm(btn, startIso, endIso);
    if (startTime) {
      startTime.required = false;
    }
    if (endTime) {
      endTime.required = false;
    }
    if (startDay) {
      startDay.required = false;
    }
    if (dayCount) {
      dayCount.required = false;
    }
    bookingForm.noValidate = true;
    const poster = document.createElement('form');
    poster.method = 'post';
    poster.action = formPostUrl(bookingForm);
    poster.style.display = 'none';
    bookingForm.querySelectorAll('input[name], textarea[name], select[name]').forEach((field) => {
      if (field.disabled) {
        return;
      }
      if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
        return;
      }
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = field.name;
      hidden.value = field.value;
      poster.appendChild(hidden);
    });
    document.body.appendChild(poster);
    poster.submit();
  }

  let itemDrag = null;

  function pathHasDataJs(event, name) {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    for (let i = 0; i < path.length; i++) {
      const node = path[i];
      if (node && node.getAttribute && node.getAttribute('data-js') === name) {
        return true;
      }
    }
    return !!(event.target && event.target.closest && event.target.closest('[data-js="' + name + '"]'));
  }

  function pathHasEditBooking(event) {
    return pathHasDataJs(event, 'edit-booking');
  }

  function ignoreOccupiedSlot(event) {
    return pathHasEditBooking(event) || pathHasDataJs(event, 'outlook-busy') || !!itemDrag;
  }

  function hitMode(btn, event, allDay) {
    const box = btn.getBoundingClientRect();
    if (allDay) {
      if (event.clientX - box.left <= 8) {
        return 'start';
      }
      if (box.right - event.clientX <= 8) {
        return 'end';
      }
      return 'move';
    }
    if (event.clientY - box.top <= 7) {
      return 'start';
    }
    if (box.bottom - event.clientY <= 7) {
      return 'end';
    }
    return 'move';
  }

  function nodeOpensBooking(node) {
    if (!node || !node.getAttribute) {
      return false;
    }
    if (node.getAttribute('data-js') === 'edit-booking') {
      return true;
    }
    return String(node.getAttribute('data-id') || '') !== '' && node.getAttribute('data-editable') === '1';
  }

  function bindOutlookBusy(el) {
    if (!el || el._jeabOutlookBound) {
      return;
    }
    el._jeabOutlookBound = true;
    el.addEventListener('pointerdown', (event) => {
      event.stopPropagation();
      skipSlotClick = true;
    });
    el.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      skipSlotClick = true;
      if (!nodeOpensBooking(el)) {
        return;
      }
      if (calIsPhone()) {
        openRead(el);
      } else {
        openEdit(el);
      }
    });
  }

  function bindEditBooking(el) {
    if (!el || el._jeabEditBound) {
      return;
    }
    el._jeabEditBound = true;
    el.addEventListener('pointerdown', (event) => {
      event.stopPropagation();
      skipSlotClick = true;
      if (el.closest('.month-cal')) {
        return;
      }
      if (event.pointerType && event.pointerType !== 'mouse') {
        return;
      }
      if (event.button !== 0) {
        return;
      }
      const allDay = el.classList.contains('wg-allday-item') || el.dataset.allday === '1';
      const box = el.getBoundingClientRect();
      const spanDays = Math.max(1, dayDiff(el.dataset.starts || '', el.dataset.ends || ''));
      hidePeek();
      itemDrag = {
        btn: el,
        allDay,
        mode: hitMode(el, event, allDay),
        startX: event.clientX,
        startY: event.clientY,
        origStart: el.dataset.starts || '',
        origEnd: el.dataset.ends || '',
        nextStart: el.dataset.starts || '',
        nextEnd: el.dataset.ends || '',
        moved: false,
        grabSlots: allDay ? 0 : Math.floor(Math.max(0, event.clientY - box.top) / slotHeight()),
        grabDays: allDay ? Math.floor(Math.max(0, event.clientX - box.left) / Math.max(8, box.width / spanDays)) : 0,
      };
      document.body.classList.add('is-cal-dragging');
      try {
        el.setPointerCapture(event.pointerId);
      } catch {
        // optional
      }
    });
    el.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      skipSlotClick = true;
      if (itemDrag) {
        return;
      }
      if (calIsPhone()) {
        openRead(el);
      } else {
        openEdit(el);
      }
    });
  }

  function bindPaintedCalendarItems(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-js="edit-booking"]').forEach(bindEditBooking);
    scope.querySelectorAll('[data-js="outlook-busy"]').forEach((el) => {
      if (nodeOpensBooking(el)) {
        bindEditBooking(el);
        return;
      }
      bindOutlookBusy(el);
    });
  }

  const weekGrid = document.querySelector('.week-grid');
  const peek = document.querySelector('.wg-peek');
  const peekJobs = { batteri: 'Batteri', sol: 'Sol', laddbox: 'Elbilsladdare', service: 'Service' };

  function fineHover() {
    return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  }

  function peekEscape(text) {
    return String(text || '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[ch]));
  }

  function hidePeek() {
    if (!peek) {
      return;
    }
    peek.classList.remove('is-on');
    peek.hidden = true;
    peek.innerHTML = '';
  }

  function placePeek(anchor) {
    if (!peek || !anchor) {
      return;
    }
    const box = anchor.getBoundingClientRect();
    const pad = 16;
    peek.hidden = false;
    peek.classList.add('is-on');
    const w = peek.offsetWidth;
    const h = peek.offsetHeight;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let left = box.left;
    let top = box.bottom + 4;
    left = Math.min(Math.max(pad, left), Math.max(pad, vw - pad - w));
    if (top + h > vh - pad) {
      top = box.top - h - 4;
    }
    top = Math.min(Math.max(pad, top), Math.max(pad, vh - pad - h));
    peek.style.left = `${Math.round(left)}px`;
    peek.style.top = `${Math.round(top)}px`;
  }

  function showPeek(el) {
    if (!peek || !fineHover() || !el || el.classList.contains('wg-ghost')) {
      return;
    }
    if (el.getAttribute('data-overlap') !== '1') {
      return;
    }
    const allDay = el.classList.contains('wg-allday-item') || el.dataset.allday === '1';
    const name = el.dataset.customer || el.dataset.who || '';
    const title = peekJobs[el.dataset.job] || el.dataset.title || '';
    const start = el.dataset.starts || '';
    const end = el.dataset.ends || '';
    let when = '';
    if (allDay) {
      const a = start.slice(0, 10);
      const b = end.slice(0, 10);
      when = a && b && b !== a ? `${a} – ${b}` : 'Heldag';
    } else if (start && end) {
      when = `${start.slice(11, 16)}–${end.slice(11, 16)}`;
    }
    const rows = [];
    if (name) {
      rows.push(`<strong>${peekEscape(name)}</strong>`);
    }
    if (when) {
      rows.push(`<span class="wg-peek-muted">${peekEscape(when)}</span>`);
    }
    if (title) {
      rows.push(`<span>${peekEscape(title)}</span>`);
    }
    if (!rows.length) {
      return;
    }
    peek.innerHTML = rows.join('');
    placePeek(el);
  }

  if (weekGrid && peek) {
    weekGrid.addEventListener('pointerover', (event) => {
      if (event.pointerType && event.pointerType !== 'mouse') {
        return;
      }
      const el = event.target && event.target.closest ? event.target.closest('.wg-item') : null;
      if (!el || !weekGrid.contains(el)) {
        return;
      }
      showPeek(el);
    });
    weekGrid.addEventListener('pointerout', (event) => {
      const el = event.target && event.target.closest ? event.target.closest('.wg-item') : null;
      if (!el) {
        return;
      }
      const next = event.relatedTarget;
      if (next && el.contains(next)) {
        return;
      }
      hidePeek();
    });
  }

  function paintItemDrag(el, startIso, endIso, allDay) {
    const ghost = ensureGhost(!!allDay);
    const job = [...el.classList].find((name) => name.indexOf('is-') === 0 && name !== 'is-dragging') || 'is-none';
    ghost.className = allDay
      ? 'wg-item wg-allday-item wg-ghost ' + job
      : 'wg-item wg-ghost ' + job;
    ghost.innerHTML = el.innerHTML;
    if (allDay) {
      const row = document.querySelector('.wg-allday');
      if (row && ghost.parentElement !== row) {
        row.appendChild(ghost);
      }
      applyAlldayVisual(ghost, startIso, endIso);
    } else {
      applyTimedVisual(ghost, startIso, endIso);
    }
    el.classList.add('is-dragging');
  }

  function finishItemDrag(event) {
    if (!itemDrag) {
      return;
    }
    const el = itemDrag.btn;
    const state = itemDrag;
    itemDrag = null;
    document.body.classList.remove('is-cal-dragging');
    el.classList.remove('is-dragging');
    skipSlotClick = true;
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    hideGhosts();
    if (!state.moved) {
      if (calIsPhone()) {
        openRead(el);
      } else {
        openEdit(el);
      }
      return;
    }
    if (state.nextStart === state.origStart && state.nextEnd === state.origEnd) {
      return;
    }
    el.dataset.starts = state.nextStart;
    el.dataset.ends = state.nextEnd;
    if (state.allDay) {
      applyAlldayVisual(el, state.nextStart, state.nextEnd);
    } else {
      applyTimedVisual(el, state.nextStart, state.nextEnd);
    }
    queueMovedSave(el, state.nextStart, state.nextEnd, state.origStart, state.origEnd);
  }

  function updateItemDrag(event) {
    if (!itemDrag) {
      return;
    }
    const el = itemDrag.btn;
    if (event.pointerType && event.pointerType !== 'mouse') {
      event.preventDefault();
    }
    const dx = event.clientX - itemDrag.startX;
    const dy = event.clientY - itemDrag.startY;
    if (!itemDrag.moved && Math.hypot(dx, dy) < 6) {
      return;
    }
    if (!itemDrag.moved && Math.abs(dx) >= Math.abs(dy)) {
      itemDrag.mode = 'move';
    }
    itemDrag.moved = true;
    if (itemDrag.allDay) {
      const over = dayAt(event.clientX, event.clientY);
      if (!over) {
        return;
      }
      const first = dayCells[0] ? dayCells[0].dataset.day : '';
      const lastEx = dayCells[dayCells.length - 1] ? String(dayCells[dayCells.length - 1].dataset.ends || '').slice(0, 10) : '';
      const span = Math.max(1, dayDiff(itemDrag.origStart, itemDrag.origEnd));
      let startDay = over.dataset.day || '';
      let endDay = addDaysIso(startDay, span);
      if (itemDrag.mode === 'move') {
        startDay = addDaysIso(over.dataset.day || '', -itemDrag.grabDays);
        endDay = addDaysIso(startDay, span);
      } else if (itemDrag.mode === 'start') {
        startDay = over.dataset.day || '';
        endDay = String(itemDrag.origEnd || '').slice(0, 10);
        if (startDay >= endDay) {
          startDay = addDaysIso(endDay, -1);
        }
      } else {
        startDay = String(itemDrag.origStart || '').slice(0, 10);
        endDay = String(over.dataset.ends || '').slice(0, 10);
        if (endDay <= startDay) {
          endDay = addDaysIso(startDay, 1);
        }
      }
      if (startDay < first) {
        const shift = dayDiff(startDay, first);
        startDay = first;
        endDay = addDaysIso(endDay, shift);
      }
      if (endDay > lastEx) {
        const shift = dayDiff(lastEx, endDay);
        endDay = lastEx;
        startDay = addDaysIso(startDay, -shift);
      }
      if (startDay < first) {
        startDay = first;
      }
      itemDrag.nextStart = `${startDay}T00:00`;
      itemDrag.nextEnd = `${endDay}T00:00`;
      paintItemDrag(el, itemDrag.nextStart, itemDrag.nextEnd, true);
      return;
    }
    const origCol = el.closest('.wg-day-col');
    const over = itemDrag.mode === 'move' ? slotAt(event.clientX, event.clientY) : slotAtInCol(event.clientX, event.clientY, origCol);
    if (!over) {
      return;
    }
    const day = String(over.dataset.starts || '').slice(0, 10);
    const minStart = `${day}T07:00`;
    const maxEnd = `${day}T17:00`;
    let startIso = over.dataset.starts || '';
    let endIso = over.dataset.ends || '';
    if (itemDrag.mode === 'move') {
      const origDay = String(itemDrag.origStart || '').slice(0, 10);
      const duration = minutesBetween(itemDrag.origStart, itemDrag.origEnd);
      if (day !== origDay) {
        const shifted = moveIntervalToDay(itemDrag.origStart, itemDrag.origEnd, day);
        startIso = shifted.start;
        endIso = shifted.end;
      } else {
        startIso = addMinutes(over.dataset.starts || '', -itemDrag.grabSlots * 30);
        endIso = addMinutes(startIso, duration);
      }
      if (startIso.slice(0, 10) !== day) {
        startIso = minStart;
        endIso = addMinutes(startIso, duration);
      }
      if (startIso < minStart) {
        const shift = minutesBetween(startIso, minStart);
        startIso = minStart;
        endIso = addMinutes(endIso, shift);
      }
      if (endIso > maxEnd) {
        const shift = minutesBetween(maxEnd, endIso);
        endIso = maxEnd;
        startIso = addMinutes(startIso, -shift);
      }
      if (startIso < minStart) {
        startIso = minStart;
      }
    } else if (itemDrag.mode === 'start') {
      startIso = over.dataset.starts || itemDrag.origStart;
      endIso = itemDrag.origEnd;
      if (startIso.slice(0, 10) !== String(endIso).slice(0, 10)) {
        startIso = `${String(endIso).slice(0, 10)}T${String(startIso).slice(11, 16)}`;
      }
      if (durationMinutes(startIso, endIso) === null || durationMinutes(startIso, endIso) < 60) {
        startIso = addMinutes(endIso, -60);
      }
      if (startIso < `${String(endIso).slice(0, 10)}T07:00`) {
        startIso = `${String(endIso).slice(0, 10)}T07:00`;
      }
    } else {
      startIso = itemDrag.origStart;
      endIso = over.dataset.ends || itemDrag.origEnd;
      if (endIso.slice(0, 10) !== String(startIso).slice(0, 10)) {
        endIso = `${String(startIso).slice(0, 10)}T${String(endIso).slice(11, 16)}`;
      }
      if (durationMinutes(startIso, endIso) === null || durationMinutes(startIso, endIso) < 60) {
        endIso = addMinutes(startIso, 60);
      }
      const dayEnd = `${String(startIso).slice(0, 10)}T17:00`;
      if (endIso > dayEnd) {
        endIso = dayEnd;
        if (durationMinutes(startIso, endIso) === null || durationMinutes(startIso, endIso) < 60) {
          startIso = addMinutes(endIso, -60);
        }
      }
    }
    itemDrag.nextStart = startIso;
    itemDrag.nextEnd = endIso;
    paintItemDrag(el, startIso, endIso, false);
  }

  document.addEventListener('pointermove', updateItemDrag, { capture: true, passive: false });
  document.addEventListener('pointerup', finishItemDrag, true);
  document.addEventListener('pointercancel', finishItemDrag, true);

  bindPaintedCalendarItems(document);

  const openBookingParams = new URLSearchParams(location.search);
  const openBookingId = String(openBookingParams.get('open') || openBookingParams.get('id') || '').trim();
  function tryOpenBookingById() {
    if (!openBookingId) {
      return;
    }
    const openEl = document.querySelector('[data-js="edit-booking"][data-id="' + openBookingId + '"]');
    if (openEl) {
      openRead(openEl);
    }
  }
  tryOpenBookingById();

  let calWaveGen = 0;
  let calWaveAbort = null;
  function fillCalendarWaves() {
    if (document.visibilityState === 'hidden') {
      if (calWaveAbort) {
        calWaveAbort.abort();
        calWaveAbort = null;
      }
      return;
    }
    if (window.jeabBackground) {
      window.jeabBackground.abort();
    }
    if (calWaveAbort) {
      calWaveAbort.abort();
    }
    const waveAbort = new AbortController();
    calWaveAbort = waveAbort;
    const waveGen = ++calWaveGen;
    const bootEl = document.getElementById('jeab-cal-boot');
    if (!bootEl) {
      return;
    }
    let boot = null;
    try {
      boot = JSON.parse(bootEl.textContent || 'null');
    } catch {
      boot = null;
    }
    if (!boot || typeof boot !== 'object') {
      return;
    }
    const cacheName = 'jeab.cal.v3.' + String(boot.cacheKey || '');
    let lastBookings = null;
    let lastOutlook = null;
    let outlookPainted = false;
    const jobFilterOn = String(boot.job || '').trim() !== '';
    let outlookPolls = 0;
    let outlookPoll = 0;

    function showOutlookStale(on) {
      const label = document.querySelector('[data-js="cal-stale"]');
      if (!label) {
        return;
      }
      label.hidden = !on;
    }

    function stopOutlookPoll() {
      if (outlookPoll) {
        clearTimeout(outlookPoll);
        outlookPoll = 0;
      }
    }

    function nodesFromHtml(html) {
      const box = document.createElement('div');
      box.innerHTML = String(html || '').trim();
      return Array.from(box.children);
    }

    function syncAlldayRows() {
      const row = document.querySelector('.wg-allday');
      if (!row) {
        return;
      }
      const n = row.querySelectorAll('.wg-allday-item:not(.wg-ghost)').length;
      row.style.setProperty('--allday-rows', String(n + 1));
      row.classList.toggle('is-stack', n > 0);
    }

    function clearWave(wave) {
      document.querySelectorAll('[data-wave="' + wave + '"]').forEach((el) => el.remove());
    }

    function insertNodes(host, nodes, wave) {
      if (!host) {
        return;
      }
      nodes.forEach((node) => {
        const tagged = node.getAttribute('data-wave') || wave;
        if (!node.getAttribute('data-wave')) {
          node.setAttribute('data-wave', tagged);
        }
        host.appendChild(node);
        if (nodeOpensBooking(node)) {
          bindEditBooking(node);
        } else if (tagged === 'outlook' || node.getAttribute('data-js') === 'outlook-busy') {
          bindOutlookBusy(node);
        } else {
          bindEditBooking(node);
        }
      });
    }

    function paintPayload(payload, wave) {
      if (!payload || typeof payload !== 'object') {
        return;
      }
      if (wave === 'outlook') {
        outlookPainted = true;
        clearWave('bookings');
        clearWave('outlook');
      } else if (outlookPainted) {
        return;
      } else {
        clearWave(wave);
      }
      const timed = payload.timed && typeof payload.timed === 'object' ? payload.timed : {};
      Object.keys(timed).forEach((day) => {
        const col = document.querySelector('.wg-day-col[data-day="' + day + '"]');
        (timed[day] || []).forEach((html) => insertNodes(col, nodesFromHtml(html), wave));
      });
      const alldayHost = document.querySelector('.wg-allday');
      // Dagvyn: bara heldagar som täcker dagen, i en kolumn och en rad var.
      let dayCol = 0;
      if (boot.view === 'day' && boot.day && boot.monday) {
        dayCol = 2 + Math.round((new Date(boot.day + 'T12:00:00') - new Date(boot.monday + 'T12:00:00')) / 86400000);
      }
      let dayLane = 0;
      (payload.allday || []).forEach((html) => {
        let nodes = nodesFromHtml(html);
        if (dayCol > 0) {
          nodes = nodes.filter((node) => {
            const start = parseInt(node.style.gridColumnStart, 10) || 0;
            const spanHit = String(node.style.gridColumnEnd || '').match(/span\s+(\d+)/);
            const span = spanHit ? parseInt(spanHit[1], 10) : 1;
            if (!start || dayCol < start || dayCol >= start + span) {
              return false;
            }
            dayLane += 1;
            node.style.gridColumn = '2 / span 1';
            node.style.gridRow = String(dayLane);
            return true;
          });
        }
        insertNodes(alldayHost, nodes, wave);
      });
      syncAlldayRows();
      ['early', 'late'].forEach((when) => {
        const row = document.querySelector('[data-js="cal-out"][data-when="' + when + '"]');
        if (!row) {
          return;
        }
        const byDay = payload[when] && typeof payload[when] === 'object' ? payload[when] : {};
        Object.keys(byDay).forEach((day) => {
          const cell = row.querySelector('.wg-out-cell[data-day="' + day + '"]');
          const col = document.querySelector('.wg-day-col[data-day="' + day + '"]');
          (byDay[day] || []).forEach((html) => {
            insertNodes(cell, nodesFromHtml(html), wave);
            // Mobil: samma rad överst/underst i dagens lista.
            const copies = nodesFromHtml(html);
            copies.forEach((node) => {
              node.classList.add('wg-out-inline');
              node.style.order = when === 'early' ? '-1' : '100000';
            });
            insertNodes(col, copies, wave);
          });
        });
        const any = !!row.querySelector('.wg-out-item');
        row.hidden = !any;
        document.querySelectorAll('.wg-day-col').forEach((col) => {
          const cell = row.querySelector('.wg-out-cell[data-day="' + col.dataset.day + '"]');
          col.classList.toggle('has-' + when, !!(cell && cell.querySelector('.wg-out-item')));
        });
      });
      const month = payload.month && typeof payload.month === 'object' ? payload.month : {};
      Object.keys(month).forEach((day) => {
        const cell = document.querySelector('[data-js="month-day"][data-day="' + day + '"]');
        (month[day] || []).forEach((html) => insertNodes(cell, nodesFromHtml(html), wave));
      });
      const sols = payload.sols && typeof payload.sols === 'object' ? payload.sols : {};
      Object.keys(sols).forEach((week) => {
        const weekEl = document.querySelector('.mv-week[data-week="' + week + '"]');
        if (!weekEl) {
          return;
        }
        let box = weekEl.querySelector('.mv-sols');
        if (!box && (sols[week] || []).length) {
          box = document.createElement('div');
          box.className = 'mv-sols';
          const days = weekEl.querySelector('.mv-days');
          weekEl.insertBefore(box, days);
        }
        if (!box) {
          return;
        }
        if (wave === 'outlook') {
          box.querySelectorAll('[data-wave="bookings"], [data-wave="outlook"]').forEach((el) => el.remove());
        } else {
          box.querySelectorAll('[data-wave="' + wave + '"]').forEach((el) => el.remove());
        }
        (sols[week] || []).forEach((html) => {
          const nodes = nodesFromHtml(html);
          nodes.forEach((node) => {
            const lane = document.createElement('div');
            lane.className = 'mv-sol-lane';
            const tagged = node.getAttribute('data-wave') || wave;
            lane.setAttribute('data-wave', tagged);
            lane.appendChild(node);
            box.appendChild(lane);
            bindEditBooking(node);
          });
        });
      });
      if (wave === 'bookings' || wave === 'outlook') {
        tryOpenBookingById();
      }
    }

    function freshPaint(data, wave) {
      if (waveGen !== calWaveGen) {
        return;
      }
      paintPayload(data, wave);
    }

    function remember() {
      if (!cacheName) {
        return;
      }
      try {
        window.localStorage.setItem(cacheName, JSON.stringify({
          bookings: lastBookings,
          outlook: lastOutlook,
        }));
      } catch {
        // private mode
      }
    }

    function fetchWave(url) {
      return fetch(url, {
        credentials: 'same-origin',
        signal: waveAbort.signal,
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': (document.querySelector('meta[name="csrf"]') || {}).content || '',
        },
      }).then((res) => {
        if (!res.ok) {
          throw new Error('wave');
        }
        return res.json();
      });
    }

    try {
      const cached = JSON.parse(window.localStorage.getItem(cacheName) || 'null');
      if (cached && typeof cached === 'object') {
        if (cached.bookings) {
          paintPayload(cached.bookings, 'bookings');
        }
        if (cached.outlook && !jobFilterOn) {
          paintPayload(cached.outlook, 'outlook');
        }
      }
    } catch {
      // ignore stale cache
    }

    const bookingsUrl = String(boot.bookings || '');
    const outlookUrl = String(boot.outlook || '');
    const paintBookings = bookingsUrl
      ? fetchWave(bookingsUrl).then((data) => {
        lastBookings = data;
        freshPaint(data, 'bookings');
        remember();
      }).catch(() => {})
      : Promise.resolve();
    showOutlookStale(false);
    paintBookings.then(() => {
      if (waveAbort.signal.aborted || !outlookUrl || jobFilterOn) {
        showOutlookStale(false);
        return;
      }
      return fetchWave(outlookUrl).then((data) => {
        lastOutlook = data;
        freshPaint(data, 'outlook');
        remember();
        const stale = !!(data && data.stale);
        showOutlookStale(stale);
        if (stale) {
          scheduleOutlookPoll();
        } else {
          stopOutlookPoll();
        }
      }).catch(() => {});
    });

    function scheduleOutlookPoll() {
      stopOutlookPoll();
      if (outlookPolls >= 24 || waveAbort.signal.aborted || !outlookUrl || jobFilterOn) {
        return;
      }
      outlookPoll = setTimeout(() => {
        outlookPoll = 0;
        if (waveAbort.signal.aborted || !outlookUrl || jobFilterOn) {
          return;
        }
        outlookPolls += 1;
        fetchWave(outlookUrl).then((data) => {
          if (waveGen !== calWaveGen || waveAbort.signal.aborted) {
            return;
          }
          lastOutlook = data;
          freshPaint(data, 'outlook');
          remember();
          const stale = !!(data && data.stale);
          showOutlookStale(stale);
          if (stale) {
            scheduleOutlookPoll();
          }
        }).catch(() => {});
      }, 15000);
    }
  }
  fillCalendarWaves();

  const fromReminderSeed = document.getElementById('from-reminder-seed');
  if (fromReminderSeed) {
    let hit = null;
    try {
      hit = JSON.parse(fromReminderSeed.textContent || 'null');
    } catch {
      hit = null;
    }
    if (hit && typeof hit === 'object') {
      openCreateFromReminder(hit, newBookingBtn || sheet);
      // Ta bort from_reminder ur adressen så en omladdning inte öppnar arket igen.
      try {
        const url = new URL(window.location.href);
        url.searchParams.delete('from_reminder');
        history.replaceState(null, '', url.pathname + url.search + url.hash);
      } catch {
        // replaceState kan nekas på vissa origins; arket är ändå öppet.
      }
    }
  }

  function slotAtInCol(x, y, col) {
    if (col) {
      const hit = Array.from(col.querySelectorAll('[data-js="slot"]')).find((cell) => {
        const box = cell.getBoundingClientRect();
        return y >= box.top && y < box.bottom;
      });
      if (hit) {
        return hit;
      }
    }
    return slotAt(x, y);
  }

  function clearDrag() {
    drag = null;
    hideGhosts();
    slots.forEach((s) => s.classList.remove('is-dragging'));
  }

  function bindSlotCell(slot) {
    if (slot._jeabSlotBound) {
      return;
    }
    slot._jeabSlotBound = true;
    slot.addEventListener('pointerdown', (event) => {
      if (ignoreOccupiedSlot(event)) {
        return;
      }
      if (event.button !== 0 && event.pointerType === 'mouse') {
        return;
      }
      drag = { start: slot, end: slot };
      paintTimedGhost(slot, slot);
      try {
        slot.setPointerCapture(event.pointerId);
      } catch {
        // Capture is optional; move still works via elementFromPoint.
      }
    });
    slot.addEventListener('pointermove', (event) => {
      if (!drag) {
        return;
      }
      const over = slotAt(event.clientX, event.clientY);
      if (!over || !sameDay(drag.start, over)) {
        return;
      }
      drag.end = over;
      paintTimedGhost(drag.start, drag.end);
    });
    slot.addEventListener('pointerup', (event) => {
      if (!drag) {
        return;
      }
      if (ignoreOccupiedSlot(event)) {
        clearDrag();
        skipSlotClick = true;
        return;
      }
      const state = drag;
      const over = slotAt(event.clientX, event.clientY);
      if (over && sameDay(state.start, over)) {
        state.end = over;
      }
      clearDrag();
      skipSlotClick = true;
      const range = rangeOf(state.start, state.end);
      openCreate(range.start, range.end, range.anchor || state.start, state.start !== state.end);
    });
    slot.addEventListener('pointercancel', () => {
      clearDrag();
    });
    slot.addEventListener('click', (event) => {
      if (ignoreOccupiedSlot(event)) {
        event.preventDefault();
        return;
      }
      if (skipSlotClick) {
        skipSlotClick = false;
        event.preventDefault();
        return;
      }
      openCreate(slot.dataset.starts, slot.dataset.ends, slot);
    });
  }
  slots.forEach(bindSlotCell);

  let dayDrag = null;

  function paintDays(from, to) {
    paintAlldayGhost(from, to);
  }

  function dayRangeOf(a, b) {
    const dayA = a.dataset.day || '';
    const dayB = b.dataset.day || '';
    const first = dayA <= dayB ? a : b;
    const last = dayA <= dayB ? b : a;
    return { start: first.dataset.starts, end: last.dataset.ends, anchor: first };
  }

  function clearDayDrag() {
    dayDrag = null;
    hideGhosts();
    dayCells.forEach((cell) => cell.classList.remove('is-dragging'));
  }

  const weekPrev = document.querySelector('a.week-nav-prev');
  const weekNext = document.querySelector('a.week-nav-next');
  const WEEK_SWIPE_AXIS = 10;
  const WEEK_SWIPE_PX = 48;
  const WEEK_SWIPE_RATIO = 1.25;
  let weekSwipe = null;

  function weekSwipeHref(dir) {
    const link = document.querySelector(dir === 'next' ? 'a.week-nav-next' : 'a.week-nav-prev');
    return link ? link.getAttribute('href') : '';
  }

  function cancelItemDragForSwipe() {
    if (!itemDrag) {
      return;
    }
    const el = itemDrag.btn;
    const state = itemDrag;
    itemDrag = null;
    document.body.classList.remove('is-cal-dragging');
    if (el) {
      el.classList.remove('is-dragging');
      if (state.allDay) {
        applyAlldayVisual(el, state.origStart, state.origEnd);
      } else {
        applyTimedVisual(el, state.origStart, state.origEnd);
      }
    }
    hideGhosts();
  }

  function goWeekSwipe(dir) {
    const href = weekSwipeHref(dir);
    if (!href) {
      return;
    }
    skipSlotClick = true;
    clearDrag();
    cancelItemDragForSwipe();
    calSoftNav(href, true);
  }

  function weekSwipeFromAllDay(event) {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    for (let i = 0; i < path.length; i++) {
      const node = path[i];
      if (node && node.classList && (node.classList.contains('wg-allday') || node.classList.contains('wg-allday-cell') || node.classList.contains('wg-allday-item'))) {
        return true;
      }
    }
    return !!(event.target && event.target.closest && event.target.closest('.wg-allday'));
  }

  if (weekGrid && weekPrev && weekNext) {
    weekGrid.addEventListener('pointerdown', (event) => {
      if (weekSwipe) {
        return;
      }
      if (event.pointerType === 'mouse') {
        return;
      }
      if (event.button !== 0) {
        return;
      }
      if (sheet.open) {
        return;
      }
      if (weekSwipeFromAllDay(event)) {
        return;
      }
      weekSwipe = {
        id: event.pointerId,
        x: event.clientX,
        y: event.clientY,
        axis: '',
      };
    }, true);

    document.addEventListener('pointermove', (event) => {
      if (!weekSwipe || event.pointerId !== weekSwipe.id || weekSwipe.axis === 'y') {
        return;
      }
      const dx = event.clientX - weekSwipe.x;
      const dy = event.clientY - weekSwipe.y;
      const ax = Math.abs(dx);
      const ay = Math.abs(dy);
      if (!weekSwipe.axis) {
        if (ax < WEEK_SWIPE_AXIS && ay < WEEK_SWIPE_AXIS) {
          return;
        }
        weekSwipe.axis = ax > ay * WEEK_SWIPE_RATIO ? 'x' : 'y';
        if (weekSwipe.axis === 'x') {
          skipSlotClick = true;
          clearDrag();
          cancelItemDragForSwipe();
          try {
            const cap = event.target;
            if (cap && typeof cap.releasePointerCapture === 'function' && typeof cap.hasPointerCapture === 'function' && cap.hasPointerCapture(event.pointerId)) {
              cap.releasePointerCapture(event.pointerId);
            }
          } catch {
            // Capture release is optional.
          }
          try {
            event.preventDefault();
          } catch {
            // Some browsers reject preventDefault on passive paths.
          }
        }
      }
      if (weekSwipe.axis === 'x' && ax >= WEEK_SWIPE_PX) {
        try {
          event.preventDefault();
        } catch {
          // ignore
        }
        const dir = dx < 0 ? 'next' : 'prev';
        weekSwipe = null;
        goWeekSwipe(dir);
      }
    }, { capture: true, passive: false });

    const endWeekSwipe = (event) => {
      if (weekSwipe && event.pointerId === weekSwipe.id) {
        weekSwipe = null;
      }
    };
    document.addEventListener('pointerup', endWeekSwipe, true);
    document.addEventListener('pointercancel', endWeekSwipe, true);
  }

  function bindDayCell(cell) {
    if (cell._jeabDayBound) {
      return;
    }
    cell._jeabDayBound = true;
    cell.addEventListener('pointerdown', (event) => {
      if (ignoreOccupiedSlot(event)) {
        return;
      }
      if (event.button !== 0 && event.pointerType === 'mouse') {
        return;
      }
      dayDrag = { start: cell, end: cell };
      paintAlldayGhost(cell, cell);
      try {
        cell.setPointerCapture(event.pointerId);
      } catch {
        // Capture is optional.
      }
    });
    cell.addEventListener('pointermove', (event) => {
      if (!dayDrag) {
        return;
      }
      const over = dayAt(event.clientX, event.clientY);
      if (!over) {
        return;
      }
      dayDrag.end = over;
      paintDays(dayDrag.start, dayDrag.end);
    });
    cell.addEventListener('pointerup', (event) => {
      if (!dayDrag) {
        return;
      }
      if (ignoreOccupiedSlot(event)) {
        clearDayDrag();
        skipSlotClick = true;
        return;
      }
      const state = dayDrag;
      const over = dayAt(event.clientX, event.clientY);
      if (over) {
        state.end = over;
      }
      clearDayDrag();
      skipSlotClick = true;
      const range = dayRangeOf(state.start, state.end);
      openCreate(range.start, range.end, range.anchor || state.start, state.start !== state.end);
    });
    cell.addEventListener('pointercancel', () => {
      clearDayDrag();
    });
    cell.addEventListener('click', (event) => {
      if (ignoreOccupiedSlot(event)) {
        event.preventDefault();
        return;
      }
      if (skipSlotClick) {
        skipSlotClick = false;
        event.preventDefault();
        return;
      }
      openCreate(cell.dataset.starts, cell.dataset.ends, cell);
    });
  }
  dayCells.forEach(bindDayCell);

  function bindMonthDay(cell) {
    if (cell._jeabMonthBound) {
      return;
    }
    cell._jeabMonthBound = true;
    cell.addEventListener('click', (event) => {
      if (event.target.closest('[data-js="edit-booking"], [data-js="outlook-busy"]')) {
        return;
      }
      openCreate(cell.dataset.starts, cell.dataset.ends, cell);
    });
  }
  document.querySelectorAll('[data-js="month-day"]').forEach(bindMonthDay);

  /* Mobil: veckan som lista. Tryck på dagens tomma yta = ny bokning den dagen
     (första halvtimmen som inte är upptagen), förifylld med vald person. */
  function bindMobileDayCol(col) {
    if (col._jeabMobileDayBound) {
      return;
    }
    col._jeabMobileDayBound = true;
    col.addEventListener('click', (event) => {
      if (!calIsPhone()) {
        return;
      }
      if (event.target.closest('[data-js="edit-booking"], [data-js="outlook-busy"]')) {
        return;
      }
      const cells = Array.from(col.querySelectorAll('[data-js="slot"]'));
      if (!cells.length) {
        return;
      }
      event.preventDefault();
      const day = col.dataset.day || String((cells[0] && cells[0].dataset.starts) || '').slice(0, 10);
      if (!day) {
        return;
      }
      const job = selectedJobKey();
      const start = job === 'laddbox' || job === 'batteri' || job === 'sol' ? `${day}T07:00` : `${day}T09:00`;
      const end = job === 'laddbox' || job === 'batteri' || job === 'sol' ? `${day}T08:00` : `${day}T10:00`;
      openCreate(start, end, col, false);
    });
  }
  document.querySelectorAll('.wg-day-col').forEach(bindMobileDayCol);

  function rebindCalendarCells() {
    slots = Array.from(document.querySelectorAll('[data-js="slot"]'));
    dayCells = Array.from(document.querySelectorAll('[data-js="allday"]'));
    slots.forEach(bindSlotCell);
    dayCells.forEach(bindDayCell);
    document.querySelectorAll('[data-js="month-day"]').forEach(bindMonthDay);
    document.querySelectorAll('.wg-day-col').forEach(bindMobileDayCol);
    bindPaintedCalendarItems(document);
  }

  /* Byte av vecka, filter och kontor utan helsidesladdning. Samma vy (vecka/månad)
     byts på plats; annars vanlig navigering. */
  let calNavGen = 0;
  let calNavAbort = null;
  window.addEventListener('jeab:nav', () => {
    calNavGen += 1;
    calWaveGen += 1;
    if (calNavAbort) {
      calNavAbort.abort();
      calNavAbort = null;
    }
    if (calWaveAbort) {
      calWaveAbort.abort();
      calWaveAbort = null;
    }
  });
  function calSameView(doc) {
    const now = document.querySelector('.month-cal') ? 'month' : (document.querySelector('.week-grid') ? 'week' : '');
    const next = doc.querySelector('.month-cal') ? 'month' : (doc.querySelector('.week-grid') ? 'week' : '');
    return now !== '' && now === next;
  }
  function calSwap(doc) {
    const swapInner = (sel) => {
      const from = doc.querySelector(sel);
      const to = document.querySelector(sel);
      if (from && to) {
        to.innerHTML = from.innerHTML;
      }
    };
    const swapOuter = (sel) => {
      const from = doc.querySelector(sel);
      const to = document.querySelector(sel);
      if (from && to) {
        to.replaceWith(document.importNode(from, true));
      }
    };
    swapInner('.cal-page .week-nav');
    swapInner('.cal-page .cal-filter-main');
    swapOuter('.cal-page [data-js="cal-accounts"]');
    swapOuter('.cal-page .cal-week-log');
    swapOuter('#jeab-cal-boot');
    swapInner('.chrome-company');
    const grid = document.querySelector('.cal-page .week-grid');
    const nextGrid = doc.querySelector('.cal-page .week-grid');
    if (grid && nextGrid) {
      grid.className = nextGrid.className;
      const keepPeek = grid.querySelector('.wg-peek');
      grid.innerHTML = nextGrid.innerHTML;
      const freshPeek = grid.querySelector('.wg-peek');
      if (keepPeek && freshPeek) {
        freshPeek.replaceWith(keepPeek);
      }
    } else {
      swapOuter('.cal-page .month-cal');
    }
    const company = doc.getElementById('booking-company');
    const companyField = document.getElementById('booking-company');
    if (company && companyField) {
      companyField.value = company.value;
    }
    const pick = doc.querySelector('#booking-form .who-job-pick');
    const pickHere = document.querySelector('#booking-form .who-job-pick');
    if (pick && pickHere) {
      pickHere.innerHTML = pick.innerHTML;
    }
    const nextForm = doc.getElementById('booking-form');
    if (nextForm) {
      bookingForm.setAttribute('action', nextForm.getAttribute('action') || bookingForm.getAttribute('action'));
    }
    if (doc.title) {
      document.title = doc.title;
    }
  }
  function calSoftNav(href, push) {
    if (!href) {
      return;
    }
    const gen = ++calNavGen;
    const page = document.querySelector('[data-js="cal-page"]');
    if (!page) {
      window.location.href = href;
      return;
    }
    if (sheet.open) {
      closeBookingSheet();
    }
    page.classList.add('is-loading');
    if (window.jeabBackground) {
      window.jeabBackground.abort();
    }
    if (calNavAbort) {
      calNavAbort.abort();
    }
    const navAbort = new AbortController();
    calNavAbort = navAbort;
    fetch(href, { credentials: 'same-origin', signal: navAbort.signal, headers: { Accept: 'text/html' } })
      .then((res) => {
        if (!res.ok) {
          throw new Error('nav');
        }
        return res.text();
      })
      .then((html) => {
        if (gen !== calNavGen) {
          return;
        }
        if (window.jeabNav && window.jeabNav.hasOrder && window.jeabNav.hasOrder()) {
          return;
        }
        const doc = new DOMParser().parseFromString(html, 'text/html');
        if (!doc.querySelector('[data-js="cal-page"]') || !calSameView(doc)) {
          window.location.href = href;
          return;
        }
        calSwap(doc);
        if (push) {
          try {
            history.pushState({ cal: 1 }, '', href);
          } catch {
            // ignore
          }
        }
        rebindCalendarCells();
        fillCalendarWaves();
        page.classList.remove('is-loading');
      })
      .catch((err) => {
        if (err && err.name === 'AbortError') {
          return;
        }
        if (gen !== calNavGen) {
          return;
        }
        if (window.jeabNav && window.jeabNav.hasOrder && window.jeabNav.hasOrder()) {
          return;
        }
        window.location.href = href;
      })
      .finally(() => {
        if (calNavAbort === navAbort) {
          calNavAbort = null;
        }
      });
  }
  window.jeabCalSoftNav = calSoftNav;

  function calBootData() {
    const bootEl = document.getElementById('jeab-cal-boot');
    if (!bootEl) {
      return { view: '', day: '', today: '' };
    }
    try {
      const boot = JSON.parse(bootEl.textContent || 'null') || {};
      return {
        view: String(boot.view || ''),
        day: String(boot.day || ''),
        today: String(boot.today || ''),
      };
    } catch {
      return { view: '', day: '', today: '' };
    }
  }

  function calTodayTarget(link) {
    try {
      return new URL(link.href, window.location.origin).searchParams.get('d') || '';
    } catch {
      return '';
    }
  }

  function calOpenCardWhen() {
    if (sheet && sheet.open && startDay) {
      return {
        day: String(startDay.value || ''),
        time: startTime ? String(startTime.value || '') : '',
        kind: 'sheet',
        el: null,
      };
    }
    if (readBox && readBox.open && readEl && readEl.dataset && readEl.dataset.starts) {
      const raw = String(readEl.dataset.starts || '');
      const match = raw.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
      if (match) {
        return { day: match[1], time: match[2], kind: 'read', el: readEl };
      }
    }
    return { day: '', time: '', kind: '', el: null };
  }

  function flashCalToday(day, cardEl) {
    const col = day ? document.querySelector('.wg-day-col[data-day="' + day + '"]') : null;
    const nodes = [document.querySelector('a.week-today'), col, cardEl];
    nodes.forEach((el) => {
      if (!el) {
        return;
      }
      el.classList.remove('is-today-ping');
      void el.offsetWidth;
      el.classList.add('is-today-ping');
      window.setTimeout(() => {
        el.classList.remove('is-today-ping');
      }, 1000);
    });
    const hour = String(new Date().getHours()).padStart(2, '0');
    const slot = col ? col.querySelector('[data-js="slot"][data-starts="' + day + 'T' + hour + ':00"]') : null;
    const scrollEl = cardEl || slot || col;
    if (scrollEl && typeof scrollEl.scrollIntoView === 'function') {
      try {
        scrollEl.scrollIntoView({ block: 'center', inline: 'nearest' });
      } catch {
        scrollEl.scrollIntoView();
      }
    }
  }

  function applyCalToday(plan, card) {
    if (plan.action === 'set-today' && card && card.kind === 'sheet' && startDay && plan.day && plan.time) {
      startDay.value = plan.day;
      if (startTime) {
        startTime.value = plan.time;
      }
      syncWhenFromTimes();
      paintSvDay();
    }
    flashCalToday(plan.day, card && card.el);
    const sv = document.getElementById('booking-start-day-sv');
    if (sv && sheet && sheet.open) {
      sv.classList.add('is-today-ping');
      window.setTimeout(() => sv.classList.remove('is-today-ping'), 1000);
    }
  }

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    const link = event.target.closest ? event.target.closest('a[href]') : null;
    if (!link || !document.querySelector('[data-js="cal-page"]')) {
      return;
    }
    const inCal = link.matches('[data-js="cal-nav"], .cal-page .cal-filter-main a.chip, .cal-page a.week-chip, .cal-page a.week-now, .chrome-company a.chip');
    if (!inCal) {
      return;
    }
    const href = link.getAttribute('href') || '';
    if (/[?&]view=month/.test(href) !== !!document.querySelector('.month-cal')) {
      return; // vecka ⇄ månad: vanlig sidladdning
    }
    event.preventDefault();
    const pick = link.closest('details.week-pick');
    if (pick) {
      pick.open = false;
    }
    if (link.classList.contains('week-today')) {
      const boot = calBootData();
      const card = calOpenCardWhen();
      const target = calTodayTarget(link) || boot.today;
      const plan = (typeof jeabCalTodayPlan === 'function')
        ? jeabCalTodayPlan(boot.view, boot.day || boot.today, target, card.day, card.time, card.kind)
        : { action: 'navigate', day: target, time: '' };
      if (plan.action === 'feedback' || plan.action === 'set-today') {
        applyCalToday(plan, card);
        return;
      }
    }
    calSoftNav(link.href, true);
  });
  if (document.querySelector('[data-js="cal-page"]')) {
    window.addEventListener('popstate', () => {
      if (window.jeabShell) {
        return;
      }
      calSoftNav(window.location.href, false);
    });
  }

  document.querySelectorAll('[data-js="close-sheet"]').forEach((el) => {
    el.addEventListener('click', () => requestCloseBookingSheet());
  });
  const sheetRead = bookingForm.querySelector('[data-js="sheet-read"]');
  if (sheetRead) {
    sheetRead.addEventListener('click', () => {
      if (sheetEditEl) {
        openRead(sheetEditEl);
      }
    });
  }
  window.addEventListener('pagehide', () => {
    commitMoveUndo(true);
  });

  sheet.addEventListener('click', (event) => {
    if (event.target !== sheet) {
      return;
    }
    if (performance.now() < ignoreCloseUntil) {
      return;
    }
    const under = elementUnderDialog(sheet, event.clientX, event.clientY);
    const todayLink = under && under.closest ? under.closest('a.week-today') : null;
    if (todayLink && sheetEditEl) {
      const raw = String(sheetEditEl.dataset.starts || '');
      const match = raw.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
      const boot = calBootData();
      const plan = (typeof jeabCalPopupToday === 'function')
        ? jeabCalPopupToday(match ? match[1] : '', match ? match[2] : '', String(boot.today || ''))
        : { action: 'close', day: '', time: '' };
      if (plan.action === 'feedback') {
        const card = sheetEditEl;
        closeBookingSheet();
        requestAnimationFrame(() => flashCalToday(plan.day, card));
        return;
      }
    }
    requestCloseBookingSheet();
  });
  window.jeabRebindCalendar = rebindCalendarCells;
  window.jeabFillCalendar = fillCalendarWaves;
  document.addEventListener('toggle', (event) => {
    const box = event.target;
    if (!box || !box.classList || !box.classList.contains('cal-week-log') || !box.open) {
      return;
    }
    if (box.getAttribute('data-loaded') === '1') {
      return;
    }
    const boot = calBoot();
    const logUrl = String(boot.log || '');
    const body = box.querySelector('[data-cal-log-body]');
    if (logUrl === '' || !body) {
      return;
    }
    box.setAttribute('data-loaded', '1');
    fetch(logUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json, text/html' },
    }).then((res) => res.json()).then((data) => {
      const lines = data && Array.isArray(data.lines) ? data.lines : [];
      body.textContent = '';
      if (lines.length === 0) {
        const empty = document.createElement('p');
        empty.textContent = 'Inget den här veckan.';
        body.appendChild(empty);
        return;
      }
      lines.forEach((line) => {
        const p = document.createElement('p');
        p.textContent = String(line);
        body.appendChild(p);
      });
    }).catch(() => {
      box.removeAttribute('data-loaded');
    });
  }, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      if (calWaveAbort) {
        calWaveAbort.abort();
        calWaveAbort = null;
      }
      return;
    }
    if (document.getElementById('jeab-cal-boot')) {
      fillCalendarWaves();
    }
  });
  }
  window.jeabBootCalendar = jeabBootCalendar;
  jeabBootCalendar();
})();

(() => {
  /* Gå till datum i veckoväljaren. Tar «5 okt», «5 oktober 2027», «5/10» och ISO.
     Delegerat så att det fungerar efter byte av vecka och efter skalbyte. */
  const MONTHS = { jan: 1, feb: 2, mar: 3, apr: 4, maj: 5, jun: 6, jul: 7, aug: 8, sep: 9, okt: 10, nov: 11, dec: 12 };

  function parseDay(raw) {
    const text = String(raw || '').trim().toLowerCase();
    let y;
    let m;
    let d;
    let hit = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (hit) {
      [y, m, d] = [Number(hit[1]), Number(hit[2]), Number(hit[3])];
    } else if ((hit = text.match(/^(\d{1,2})\s*([a-zåäö]{3})[a-zåäö]*\.?(?:\s+(\d{4}))?$/))) {
      d = Number(hit[1]);
      m = MONTHS[hit[2]];
      y = hit[3] ? Number(hit[3]) : 0;
    } else if ((hit = text.match(/^(\d{1,2})\/(\d{1,2})(?:\s+(\d{4}))?$/))) {
      d = Number(hit[1]);
      m = Number(hit[2]);
      y = hit[3] ? Number(hit[3]) : 0;
    }
    if (!m || !d) {
      return null;
    }
    const now = new Date();
    if (!y) {
      y = now.getFullYear();
      const guess = new Date(y, m - 1, d);
      if (guess < new Date(now.getFullYear(), now.getMonth() - 2, 1)) {
        y += 1;
      }
    }
    const dt = new Date(y, m - 1, d);
    if (dt.getFullYear() !== y || dt.getMonth() !== m - 1 || dt.getDate() !== d) {
      return null;
    }
    return dt;
  }

  function hrefForMonday(box, monday) {
    const base = new Date(`${box.dataset.base}T00:00:00`);
    const days = Math.round((monday - base) / 86400000);
    const w = Math.trunc(days / 7);
    const params = new URLSearchParams(window.location.search);
    params.set('p', 'bookings');
    if (w === 0) {
      params.delete('w');
    } else {
      params.set('w', String(w));
    }
    params.delete('monday');
    params.delete('id');
    if (box.dataset.who) {
      params.set('who', box.dataset.who);
    } else if (params.get('who') !== 'all') {
      params.delete('who');
    }
    if (box.dataset.job) {
      params.set('job', box.dataset.job);
    } else if (params.get('job') !== 'all') {
      params.delete('job');
    }
    params.delete('d');
    if (box.dataset.view === 'month') {
      params.set('view', 'month');
    } else {
      params.delete('view');
    }
    return `index.php?${params.toString()}`;
  }

  function hrefForDay(box, dt) {
    const params = new URLSearchParams(window.location.search);
    const pad = (n) => String(n).padStart(2, '0');
    params.set('p', 'bookings');
    params.set('view', 'day');
    params.set('d', `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`);
    params.delete('w');
    params.delete('monday');
    params.delete('id');
    return `index.php?${params.toString()}`;
  }

  function jump(field) {
    const box = field.closest('.week-pick-box');
    const dt = box ? parseDay(field.value) : null;
    if (!dt) {
      return false;
    }
    let href;
    if (box.dataset.view === 'day') {
      href = hrefForDay(box, dt);
    } else {
      const n = dt.getDay() || 7;
      dt.setDate(dt.getDate() - (n - 1));
      dt.setHours(0, 0, 0, 0);
      href = hrefForMonday(box, dt);
    }
    const pick = field.closest('details');
    if (pick) {
      pick.open = false;
    }
    if (typeof window.jeabCalSoftNav === 'function') {
      window.jeabCalSoftNav(new URL(href, window.location.href).href, true);
    } else {
      window.location.href = href;
    }
    return true;
  }

  document.addEventListener('keydown', (event) => {
    const field = event.target && event.target.closest ? event.target.closest('.week-date') : null;
    if (field && event.key === 'Enter') {
      event.preventDefault();
      jump(field);
    }
  });
  document.addEventListener('change', (event) => {
    const field = event.target && event.target.closest ? event.target.closest('.week-date') : null;
    if (field && field.type === 'date') {
      jump(field);
    }
  });
  document.addEventListener('click', (event) => {
    document.querySelectorAll('details.week-pick[open]').forEach((pick) => {
      if (!pick.contains(event.target)) {
        pick.open = false;
      }
    });
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
      return;
    }
    document.querySelectorAll('details.week-pick[open]').forEach((pick) => {
      pick.open = false;
    });
  });
  document.addEventListener('input', (event) => {
    const field = event.target && event.target.closest ? event.target.closest('.week-date') : null;
    if (field && /^\d{4}-\d{2}-\d{2}$/.test(field.value.trim())) {
      jump(field);
    }
  });
})();

(() => {
  function sigenKwKey(raw) {
    const n = Number(String(raw || '').replace(/\s/g, '').replace(',', '.'));
    if (!Number.isFinite(n)) {
      return '';
    }
    return n.toFixed(1);
  }

  function applySigenPrices(input, catalog) {
    const type = String(input.sigen_typ || '').trim().toLowerCase();
    const row = catalog && catalog[type];
    if (!row || !row.sizes) {
      return input;
    }
    const kw = sigenKwKey(input.sigen_kw);
    const size = row.sizes[kw];
    if (!size) {
      return input;
    }
    const savedPrice = (key, fallback) => {
      if (key && Object.prototype.hasOwnProperty.call(input, key) && input[key] !== null && input[key] !== '') {
        const n = Number(input[key]);
        if (Number.isFinite(n)) {
          return n;
        }
      }
      return Number(fallback) || 0;
    };
    const next = Object.assign({}, input);
    const panels = input.b14;
    next.b3 = Math.max(0, Math.round(Number(input.b3) || 0));
    next.b15 = type === 'tp2' ? 1 : 0;
    next.c27 = savedPrice(row.battery && row.battery.priceKey, row.battery && row.battery.purchase);
    next.c4 = savedPrice(row.plate && row.plate.priceKey, row.plate && row.plate.purchase);
    next.sigen_surcharge = type === 'tp2' ? savedPrice(row.surchargeKey, row.surcharge) : 0;
    if (type === 'tp2') {
      next.tp2_unit = savedPrice(size.priceKey, size.purchase);
      if (!Number.isFinite(Number(next.not_tp2_unit))) {
        next.not_tp2_unit = 22700;
      }
    } else {
      next.not_tp2_unit = savedPrice(size.priceKey, size.purchase);
      if (!Number.isFinite(Number(next.tp2_unit))) {
        next.tp2_unit = 9580;
      }
    }
    next.b14 = panels;
    next.sigen_typ = type;
    next.sigen_kw = kw;
    return next;
  }

  function num(el, fallback) {
    if (!el) {
      return fallback;
    }
    const raw = String(el.value || '').replace(/\s/g, '').replace(',', '.');
    if (raw === '') {
      return fallback;
    }
    const n = Number(raw);
    return Number.isFinite(n) ? n : fallback;
  }

  function money(value) {
    return formatKr(value);
  }

  function formatKr(value) {
    return new Intl.NumberFormat('sv-SE', { maximumFractionDigits: 0 }).format(Math.round(value));
  }

  function percent(value) {
    return `${new Intl.NumberFormat('sv-SE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)} %`;
  }

  function neoKundpris(modules, ladder) {
    if (!ladder || typeof ladder !== 'object') {
      return null;
    }
    const n = Number(modules) || 0;
    if (n <= 0) {
      return 0;
    }
    const kwh = Math.round(n * 75) / 10;
    const keys = Object.keys(ladder).map(Number).filter((key) => Number.isFinite(key)).sort((a, b) => a - b);
    if (!keys.length) {
      return null;
    }
    let pick = null;
    for (const key of keys) {
      if (Math.abs(key - kwh) < 0.051) {
        pick = key;
        break;
      }
      if (key <= kwh + 0.001) {
        pick = key;
      }
    }
    if (pick === null) {
      pick = keys[0];
    }
    const price = Number(ladder[pick.toFixed(1)]);
    return Number.isFinite(price) ? price : null;
  }

  function calc(input) {
    if (input.sigen_catalog) {
      input = applySigenPrices(input, input.sigen_catalog);
    }
    const b3 = input.b3;
    const b14 = input.b14;
    const b15 = input.b15 ? 1 : 0;
    const b16 = b15 === 1 ? 0 : 1;
    let g27 = input.g27;
    if (g27 === null || g27 === undefined || g27 === '') {
      if (String(input.sigen_typ || '') === 'neo') {
        const priced = neoKundpris(b3, input.neo_ladder);
        g27 = priced === null ? 70000 : priced;
      } else {
        g27 = 70000;
      }
    }
    g27 = Number(g27);
    if (!Number.isFinite(g27)) {
      g27 = 70000;
    }
    const c4 = input.c4;
    const b5 = input.b5;
    const c13 = input.c13;
    const b17 = input.b17;
    const c27 = input.c27;
    const c28 = input.c28;
    const tp2 = Number.isFinite(input.tp2_unit) ? input.tp2_unit : 9580;
    const notTp2 = Number.isFinite(input.not_tp2_unit) ? input.not_tp2_unit : 22700;
    const laddare = input.laddare ? 1 : 0;
    const laddareUnit = Number.isFinite(input.laddare_unit) ? input.laddare_unit : 6793;
    const laddareHours = Number.isFinite(input.laddare_hours) ? input.laddare_hours : 8;
    const hourly = input.hourly;
    const sigenSurcharge = Number.isFinite(input.sigen_surcharge) ? input.sigen_surcharge : 0;
    const sokande = Math.max(1, Math.round(input.sokande || 2));
    const gtCap = sokande * 50000;
    const laddareHw = laddare * laddareUnit;
    const laddareLabor = laddare * laddareHours * hourly;
    const c3 = (b3 * c27) + 1543 + 1179;
    const c5 = b5 * hourly;
    const c6 = (c3 + c4 + c5) * 1.25;
    const cL = (laddareHw + laddareLabor) * 1.25;
    const c14 = b14 * c28;
    const c15 = b15 * tp2;
    const c16 = b16 * notTp2;
    const c17 = b17 * hourly;
    const c18 = (c13 + c14 + c15 + c16 + c17) * 1.25;
    const al21 = (c13 + c3 + c14 + c4 + c15 + c16 + laddareHw) * 1.25;
    const profitBase = Number.isFinite(input.sol_profit_base) ? input.sol_profit_base : 54000;
    const profitPer = Number.isFinite(input.sol_profit_per_panel) ? input.sol_profit_per_panel : 500;
    const profitFrom = Number.isFinite(input.sol_profit_from) ? input.sol_profit_from : 10;
    const profitBox = Number.isFinite(input.sol_profit_laddbox) ? input.sol_profit_laddbox : 3000;
    const profitTp2 = Number.isFinite(input.sol_profit_tp2) ? input.sol_profit_tp2 : 1500;
    const solProfit = profitBase + profitPer * Math.max(0, b14 - profitFrom) + (laddare ? profitBox : 0) + (b15 ? profitTp2 : 0);
    const ratio = c18 > 0 ? (c6 + cL) / c18 : 0;
    const denom = 0.8545 + 0.515 * ratio;
    const gtFactor = 0.1455 + 0.485 * ratio;
    const uncapK = denom !== 0 ? gtFactor / denom : 0;
    const need = solProfit / 0.8 + al21;
    const uncapped = need / (1 + uncapK);
    const c24 = c18 > 0 ? (uncapped * uncapK <= gtCap ? uncapped : need - gtCap) : al21 + solProfit;
    const c23 = input.c23 === null || input.c23 === undefined ? c24 : input.c23;
    const al1 = c18;
    const al2 = c6 + cL;
    const al3 = c23;
    const batteryOnly = b14 <= 0 || al1 <= 0;
    let al5;
    let al11;
    if (al1 > 0) {
      al5 = al3 / (0.8545 + (0.515 * al2 / al1));
      al11 = (al2 * al5) / al1;
    } else {
      al5 = 0;
      al11 = g27;
    }
    const al6 = 0.03 * al5;
    const al7 = 0.97 * al5;
    const al8 = 0.1455 * al5;
    const al9 = 0.8545 * al5;
    const al12 = 0.03 * al11;
    const al13 = 0.97 * al11;
    const al14 = 0.485 * al11;
    const al15 = al11 - al14;
    const al17 = al8 + al14;
    const c9 = al11;
    let batteryList = al11;
    let chargerList = 0;
    if (al2 > 0) {
      batteryList = c6 * al11 / al2;
      chargerList = cL * al11 / al2;
    }
    const c10 = batteryList !== 0 ? (batteryList - c6) / batteryList : 0;
    const laddare_margin = chargerList !== 0 ? (chargerList - cL) / chargerList : 0;
    const c20 = al5;
    const c21 = c20 !== 0 ? (c20 - c18) / c20 : 0;
    const al18kr = al5 - c18;
    const h3 = 0.485 * c9;
    const h4 = 0.1455 * c20;
    const h5 = h3 + h4;
    const i5 = Math.min(h5, gtCap);
    const i6 = h5 - i5;
    const i10 = c9 * 0.97;
    const i11 = c20 * 0.97;
    const i19 = (c9 * 0.03) + (c20 * 0.03);
    const i12 = i19 + i6;
    const i16 = i5;
    const i18 = i10 + i12 + i11 - i16;
    const i17 = c23 - i18;
    const i13 = i17;
    const i14 = i10 + i11 + i12 - i16 + i13;
    const i15 = i10 + i11 + i12 + i13;
    const g21 = i15 - al21;
    const al20 = (i15 - al21) * 0.8;
    const h31 = g27 / 0.515;
    const h29Base = h31 * 0.03;
    const h28 = h31 - h29Base;
    const h30Theo = h28 * 0.5;
    const h30 = Math.min(h30Theo, gtCap);
    const h29 = h29Base + (h30Theo - h30);
    const h33 = g27;
    const g27_cost = (b15 ? tp2 : notTp2) + (c27 * b3) + c4 + laddareHw + sigenSurcharge;
    const g27_vinst = (h31 / 1.25) - g27_cost;
    const al20pct = i15 !== 0 ? (al20 / i15) * 100 : 0;
    let margins = `Marginal sol ${percent(c21 * 100)} · batteri ${percent(c10 * 100)}`;
    if (laddare) {
      margins += ` · laddbox ${percent(laddare_margin * 100)}`;
    }
    return {
      c6, c18, c23, c24, sol_profit: solProfit, al5, al6, al7, al8, al9, al11, al12, al13, al14, al15, al17, al18kr, al20, al20pct, al21, g21,
      h3, h4, h5, i5, i6, i10, i11, i12, i13, i14, i15, i16, h28, h29, h30, h31, h33, g27, g27_cost, g27_vinst,
      c10, c21, laddare_margin, margins, batteryOnly,
    };
  }

  if (typeof globalThis !== 'undefined') {
    globalThis.jeabOffertLive = { calc, applySigenPrices };
  }

  function jeabBootOffertDom() {
  const root = document.querySelector('[data-js="offert"]');
  if (!root) {
    return;
  }
  if (root.dataset.jeabBoot === '1') {
    return;
  }
  if (window.__jeabOffertBound) {
    window.__jeabShellFallback = true;
    return;
  }
  window.__jeabOffertBound = true;
  root.dataset.jeabBoot = '1';

  const offered = root.querySelector('[data-in="c23"]');

  function sigenCatalog() {
    const box = root.querySelector('[data-sigen]');
    if (!box) {
      return null;
    }
    if (!Object.prototype.hasOwnProperty.call(box, '_jeabCatalog')) {
      try {
        box._jeabCatalog = JSON.parse(box.getAttribute('data-sigen') || '{}');
      } catch (err) {
        box._jeabCatalog = {};
      }
    }
    return box._jeabCatalog;
  }

  function neoLadder() {
    if (!Object.prototype.hasOwnProperty.call(root, '_jeabNeoLadder')) {
      try {
        root._jeabNeoLadder = JSON.parse(root.getAttribute('data-neo-kundpris') || '{}');
      } catch (err) {
        root._jeabNeoLadder = {};
      }
    }
    return root._jeabNeoLadder;
  }

  function read() {
    const tp2 = root.querySelector('[data-in="b15"]');
    const laddare = root.querySelector('[data-in="laddare"]');
    const typ = root.querySelector('input[name="sigen_typ"]:checked');
    const kw = root.querySelector('input[name="sigen_kw"]:not(:disabled):checked');
    const g27el = root.querySelector('[data-in="g27"]');
    let b15 = 0;
    if (tp2) {
      b15 = tp2.type === 'checkbox' ? (tp2.checked ? 1 : 0) : (Number(tp2.value) ? 1 : 0);
    }
    const values = {
      b3: num(root.querySelector('[data-in="b3"]'), 2),
      b14: num(root.querySelector('[data-in="b14"]'), 12),
      b15,
      laddare: laddare && laddare.checked ? 1 : 0,
      sigen_typ: typ ? typ.value : '',
      sigen_kw: kw ? kw.value : '',
      sigen_catalog: sigenCatalog(),
      c23: offered && offered.dataset.auto === '1' ? null : num(offered, null),
      g27: g27el && g27el.dataset.auto === '1' ? null : num(g27el, 70000),
      neo_ladder: neoLadder(),
      c4: num(root.querySelector('[data-in="c4"]'), 3000),
      b5: num(root.querySelector('[data-in="b5"]'), 24),
      c13: num(root.querySelector('[data-in="c13"]'), 2000),
      b17: num(root.querySelector('[data-in="b17"]'), 16),
      c27: num(root.querySelector('[data-in="c27"]'), 26362),
      c28: num(root.querySelector('[data-in="c28"]'), 1558),
      tp2_unit: num(root.querySelector('[data-in="tp2_unit"]'), 9580),
      not_tp2_unit: num(root.querySelector('[data-in="not_tp2_unit"]'), 22700),
      laddare_unit: num(root.querySelector('[data-in="laddare_unit"]'), 6793),
      laddare_hours: num(root.querySelector('[data-in="laddare_hours"]'), 8),
      hourly: num(root.querySelector('[data-in="hourly"]'), 750),
      sokande: Math.max(1, Math.round(num(root.querySelector('[data-in="sokande"]'), 2))),
      sigen_surcharge: num(root.querySelector('[data-in="sigen_surcharge"]'), 0),
      sol_profit_base: num(root.querySelector('[data-in="sol_profit_base"]'), 54000),
      sol_profit_per_panel: num(root.querySelector('[data-in="sol_profit_per_panel"]'), 500),
      sol_profit_from: num(root.querySelector('[data-in="sol_profit_from"]'), 10),
      sol_profit_laddbox: num(root.querySelector('[data-in="sol_profit_laddbox"]'), 3000),
      sol_profit_tp2: num(root.querySelector('[data-in="sol_profit_tp2"]'), 1500),
    };
    root.querySelectorAll('[data-in^="sigen_"]').forEach((el) => {
      const key = el.dataset.in || '';
      if (!key || key === 'sigen_surcharge') {
        return;
      }
      values[key] = num(el, 0);
    });
    return values;
  }

  function writeNum(key, value) {
    const el = root.querySelector(`[data-in="${key}"]`);
    if (!el || document.activeElement === el) {
      return;
    }
    el.value = key === 'b15' ? String(value ? 1 : 0) : formatKr(value);
  }

  function sigenKwLabel(kw) {
    return String(kw).replace(/0+$/, '').replace(/\.$/, '') + ' kW';
  }

  function syncSigen() {
    const typ = root.querySelector('input[name="sigen_typ"]:checked');
    const type = typ ? typ.value : 'tp2';
    const catalog = sigenCatalog() || {};
    const row = catalog[type] && catalog[type].sizes ? catalog[type].sizes : {};
    const keys = Object.keys(row);
    const wrap = root.querySelector('[data-sigen-sizes]');
    const group = wrap ? wrap.querySelector('.offert-seg') : null;
    const previous = root.querySelector('input[name="sigen_kw"]:checked');
    const previousKey = previous ? sigenKwKey(previous.value) : '';
    if (group) {
      group.replaceChildren();
      keys.forEach((kw) => {
        const label = document.createElement('label');
        label.className = 'offert-seg-opt';
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'sigen_kw';
        input.value = kw;
        const text = document.createElement('span');
        text.textContent = sigenKwLabel(kw);
        label.append(input, text);
        group.appendChild(label);
      });
      const match = Array.from(group.querySelectorAll('input')).find((input) => sigenKwKey(input.value) === previousKey);
      const pick = match || group.querySelector('input');
      if (pick) {
        pick.checked = true;
      }
    }
    if (wrap) {
      wrap.hidden = keys.length < 2;
    }
    root.querySelectorAll('.offert-seg-opt').forEach((label) => {
      const input = label.querySelector('input');
      label.classList.toggle('is-on', !!(input && input.checked));
    });
    const b15 = root.querySelector('[data-in="b15"]');
    if (b15 && b15.type !== 'checkbox') {
      b15.value = type === 'tp2' ? '1' : '0';
    }
  }

  function paint() {
    let input = read();
    if (input.sigen_catalog && input.sigen_typ) {
      input = applySigenPrices(input, input.sigen_catalog);
      writeNum('c4', input.c4);
      writeNum('c27', input.c27);
      writeNum('tp2_unit', input.tp2_unit);
      writeNum('not_tp2_unit', input.not_tp2_unit);
      writeNum('sigen_surcharge', input.sigen_surcharge);
      writeNum('b15', input.b15);
    }
    const out = calc(input);
    root.querySelectorAll('[data-out]').forEach((el) => {
      const key = el.dataset.out;
      if (!key || !Object.prototype.hasOwnProperty.call(out, key)) {
        return;
      }
      if (key === 'margins' || key === 'al20pct') {
        el.textContent = key === 'margins' ? out.margins : percent(out[key]);
        return;
      }
      el.textContent = money(out[key]);
    });
    if (offered && offered.dataset.auto === '1' && document.activeElement !== offered) {
      offered.value = formatKr(out.c24);
    }
    const batteryPrice = root.querySelector('[data-in="g27"]');
    if (batteryPrice && batteryPrice.dataset.auto === '1' && document.activeElement !== batteryPrice) {
      batteryPrice.value = formatKr(out.g27);
    }
    syncOrderPris(out);
  }

  function syncOrderPris(out) {
    if (root.hidden) {
      return;
    }
    const form = root.closest('form');
    if (!form || !offered || offered.dataset.auto !== '1') {
      return;
    }
    const pris = form.querySelector('input[name="pris"]');
    if (!pris || document.activeElement === pris) {
      return;
    }
    pris.value = formatKr(out.c24);
  }

  function stepField(key, delta) {
    const el = root.querySelector(`[data-in="${key}"]`);
    if (!el) {
      return;
    }
    const fallback = key === 'sokande' ? 2 : 0;
    let n = Math.round(num(el, fallback));
    n += delta;
    if (key === 'sokande') {
      n = Math.max(1, n);
    } else {
      n = Math.max(0, n);
    }
    el.value = formatKr(n);
    paint();
  }

  root.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-js="offert-step"]');
    if (!btn || !root.contains(btn)) {
      return;
    }
    event.preventDefault();
    stepField(btn.dataset.for || '', Number(btn.dataset.delta) || 0);
  });
  if (offered) {
    offered.addEventListener('input', () => {
      offered.dataset.auto = offered.value.trim() === '' ? '1' : '0';
    });
  }
  const batteryPrice = root.querySelector('[data-in="g27"]');
  if (batteryPrice) {
    batteryPrice.addEventListener('input', () => {
      batteryPrice.dataset.auto = batteryPrice.value.trim() === '' ? '1' : '0';
    });
  }
  const orderForm = root.closest('form');
  const orderPris = orderForm ? orderForm.querySelector('input[name="pris"]') : null;
  if (orderPris && offered && orderPris !== offered) {
    orderPris.addEventListener('input', () => {
      offered.dataset.auto = orderPris.value.trim() === '' ? '1' : '0';
      if (offered.dataset.auto === '0') {
        offered.value = orderPris.value;
      }
      paint();
    });
    orderPris.addEventListener('blur', () => {
      if (orderPris.value.trim() === '') {
        offered.dataset.auto = '1';
        paint();
      }
    });
  }
  root.querySelectorAll('input[data-in][type="text"]').forEach((el) => {
    el.addEventListener('blur', () => {
      let n = num(el, NaN);
      if (el.dataset.in === 'sokande') {
        n = Math.max(1, Math.round(Number.isFinite(n) ? n : 2));
      }
      if (Number.isFinite(n)) {
        el.value = formatKr(n);
      }
    });
  });
  root.addEventListener('input', (event) => {
    if (event.target.matches('[data-in]')) {
      paint();
    }
  });
  root.addEventListener('change', (event) => {
    const target = event.target;
    if (!target.matches('[data-in], input[name="sigen_typ"], input[name="sigen_kw"]')) {
      return;
    }
    if (target.closest('.offert-check')) {
      const label = target.closest('.offert-check');
      if (label) {
        label.classList.toggle('is-on', target.checked);
      }
    }
    if (target.name === 'sigen_typ') {
      syncSigen();
    } else if (target.name === 'sigen_kw') {
      root.querySelectorAll('.offert-sigen-size .offert-seg-opt').forEach((label) => {
        const input = label.querySelector('input');
        label.classList.toggle('is-on', !!(input && input.checked));
      });
    }
    paint();
  });
  root.querySelectorAll('input[data-in][type="text"]').forEach((el) => {
    const n = num(el, NaN);
    if (Number.isFinite(n)) {
      el.value = formatKr(n);
    }
  });
  paint();
  }
  window.jeabBootOffert = jeabBootOffertDom;
  jeabBootOffertDom();
})();

(() => {
  function jeabBootOffertEv() {
  const root = document.querySelector('[data-js="offert-ev"]');
  if (!root) {
    return;
  }
  if (root.dataset.jeabBoot === '1') {
    return;
  }
  if (window.__jeabOffertEvBound) {
    window.__jeabShellFallback = true;
    return;
  }
  window.__jeabOffertEvBound = true;
  root.dataset.jeabBoot = '1';

  function num(el, fallback) {
    if (!el) {
      return fallback;
    }
    const raw = String(el.value || '').replace(/\s/g, '').replace(',', '.');
    if (raw === '') {
      return fallback;
    }
    const n = Number(raw);
    return Number.isFinite(n) ? n : fallback;
  }

  function formatKr(value) {
    return new Intl.NumberFormat('sv-SE', { maximumFractionDigits: 0 }).format(Math.round(value));
  }

  function formatHours(value) {
    return new Intl.NumberFormat('sv-SE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value);
  }

  function formatHoursCell(value) {
    const n = Math.round(value * 100) / 100;
    let digits = 2;
    if (Math.abs(n - Math.round(n)) < 1e-9) {
      digits = 0;
    } else if (Math.abs((n * 10) - Math.round(n * 10)) < 1e-9) {
      digits = 1;
    }
    return new Intl.NumberFormat('sv-SE', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n);
  }

  function hoursLine(low, high) {
    return `Estimerad tid låg ${formatHours(low)} h · hög ${formatHours(high)} h`;
  }

  function read() {
    return {
      c4: num(root.querySelector('[data-in="ev_c4"]'), 120),
      c5: num(root.querySelector('[data-in="ev_c5"]'), 120),
      c6: num(root.querySelector('[data-in="ev_c6"]'), 33),
      c7: num(root.querySelector('[data-in="ev_c7"]'), 66),
      c8: num(root.querySelector('[data-in="ev_c8"]'), 0),
      c9: num(root.querySelector('[data-in="ev_c9"]'), 1),
      c10: num(root.querySelector('[data-in="ev_c10"]'), 0),
      c11: num(root.querySelector('[data-in="ev_c11"]'), 0),
      c12: num(root.querySelector('[data-in="ev_c12"]'), 0),
      d4: num(root.querySelector('[data-in="ev_d4"]'), 80),
      d5: num(root.querySelector('[data-in="ev_d5"]'), 180),
      d6: num(root.querySelector('[data-in="ev_d6"]'), 580),
      d7: num(root.querySelector('[data-in="ev_d7"]'), 7800),
      d8: num(root.querySelector('[data-in="ev_d8"]'), 1000),
      d9: num(root.querySelector('[data-in="ev_d9"]'), 10000),
      hourly_extra: num(root.querySelector('[data-in="ev_hourly_extra"]'), 600),
      hour_low: num(root.querySelector('[data-in="ev_hour_low"]'), 750),
      hour_high: num(root.querySelector('[data-in="ev_hour_high"]'), 1000),
      mat_base: num(root.querySelector('[data-in="ev_mat_base"]'), 5000),
      mat_per_low: num(root.querySelector('[data-in="ev_mat_per_low"]'), 200),
      mat_per_high: num(root.querySelector('[data-in="ev_mat_per_high"]'), 500),
      c21: num(root.querySelector('[data-in="ev_c21"]'), 3),
      d21: num(root.querySelector('[data-in="ev_d21"]'), 5),
    };
  }

  function calc(input) {
    const c4 = input.c4;
    const c5 = input.c5;
    const c6 = input.c6;
    const c7 = input.c7;
    const c8 = input.c8;
    const c9 = input.c9;
    const c10 = input.c10;
    const c11 = input.c11;
    const c12 = input.c12;
    const d4 = input.d4;
    const d5 = input.d5;
    const d6 = input.d6;
    const d7 = input.d7;
    const d8 = input.d8;
    const d9 = input.d9;
    const hourlyExtra = input.hourly_extra;
    const hourLow = input.hour_low;
    const hourHigh = input.hour_high;
    const matBase = input.mat_base;
    const matPerLow = input.mat_per_low;
    const matPerHigh = input.mat_per_high;
    const c21 = input.c21;
    const d21 = input.d21;
    const e4 = c4 * d4;
    const e5 = c5 * d5;
    const e6 = c6 * d6;
    const e7 = c7 * d7;
    const e8 = c8 * d8;
    const e9 = c9 * d9;
    const e10 = c10;
    const e11 = c11 * hourlyExtra;
    const e12 = c12;
    const e13 = e4 + e5 + e6 + e7 + e8 + e9 + e10 + e11 + e12;
    const f13 = e13 * 1.25;
    const c16 = matBase + (c7 * matPerLow);
    const d16 = matBase + (c7 * matPerHigh);
    const e16 = c16;
    const f16 = d16;
    const c17 = 2 + ((c4 + c5) * 0.12);
    const d17 = 4 + ((c4 + c5) * 0.14);
    const e17 = c17 * hourLow;
    const f17 = d17 * hourHigh;
    const c18 = c6 * 1;
    const d18 = c6 * 2;
    const e18 = c18 * hourLow;
    const f18 = d18 * hourHigh;
    const c19 = c7 * 2;
    const d19 = c7 * 3;
    const e19 = c19 * hourLow;
    const f19 = d19 * hourHigh;
    const c20 = 4 + (c7 * 0.3);
    const d20 = 4 + (c7 * 0.65);
    const e20 = c20 * hourLow;
    const f20 = d20 * hourHigh;
    const e21 = c21 * hourLow;
    const f21 = d21 * hourHigh;
    const e23 = e16 + e17 + e18 + e19 + e20 + e21;
    const f23 = f16 + f17 + f18 + f19 + f20 + f21;
    const hours_low = c17 + c18 + c19 + c20 + c21;
    const hours_high = d17 + d18 + d19 + d20 + d21;
    const hours_sjalv = hours_low + c11;
    const hours_list = hours_high + c11;
    const hours_rek = ((hours_low + hours_high) / 2) + c11;
    const hardware_ex = e4 + e5 + e6 + e7 + e8 + e9 + e10 + e12;
    const hardware_inkl = hardware_ex * 1.25;
    // Övrigt elmaterial: lågt→självkostnad, högt→list, medel→rekommenderat (inkl moms).
    const mat_low_inkl = c16 * 1.25;
    const mat_high_inkl = d16 * 1.25;
    const mat_rek_inkl = (mat_low_inkl + mat_high_inkl) / 2;
    const labor_low = e23 - e16;
    const labor_high = f23 - f16;
    const labor_rek = (labor_low + labor_high) / 2;
    const rek_inkl = f13 + labor_rek + mat_rek_inkl;
    const sjalv_inkl = f13 + labor_low + mat_low_inkl;
    const list_inkl = f13 + labor_high + mat_high_inkl;
    const rek_ex = rek_inkl * 0.8;
    const sjalv_ex = sjalv_inkl * 0.8;
    const list_ex = list_inkl * 0.8;
    return {
      c16,
      d16,
      e16,
      f16,
      c17,
      d17,
      e17,
      f17,
      c18,
      d18,
      e18,
      f18,
      c19,
      d19,
      e19,
      f19,
      c20,
      d20,
      e20,
      f20,
      c21,
      d21,
      e21,
      f21,
      e13,
      f13,
      e23,
      f23,
      hours_low,
      hours_high,
      hours_rek,
      hours_sjalv,
      hours_list,
      hours: hoursLine(hours_sjalv, hours_list),
      hardware_ex,
      hardware_inkl,
      rek_inkl,
      sjalv_inkl,
      list_inkl,
      rek_ex,
      sjalv_ex,
      list_ex,
      labor_rek,
      inkop_sjalv: hardware_inkl + mat_low_inkl,
      inkop_rek: hardware_inkl + mat_rek_inkl,
      inkop_list: hardware_inkl + mat_high_inkl,
      // Vinst ex moms = (kundpris inkl − inköp inkl inkl övrigt) × 0,8
      vinst_rek: (rek_inkl - (hardware_inkl + mat_rek_inkl)) * 0.8,
      vinst_sjalv: (sjalv_inkl - (hardware_inkl + mat_low_inkl)) * 0.8,
      vinst_list: (list_inkl - (hardware_inkl + mat_high_inkl)) * 0.8,
    };
  }

  function paint() {
    const out = calc(read());
    root.querySelectorAll('[data-out]').forEach((el) => {
      const key = el.dataset.out;
      if (!key || !Object.prototype.hasOwnProperty.call(out, key)) {
        return;
      }
      if (el.dataset.fmt === 'h') {
        el.textContent = formatHoursCell(out[key]);
        return;
      }
      if (key === 'hours') {
        el.textContent = out.hours;
        return;
      }
      if (key.startsWith('hours_')) {
        el.textContent = formatHours(out[key]) + ' h';
        return;
      }
      el.textContent = formatKr(out[key]);
    });
  }

  root.querySelectorAll('input[data-in][type="text"]').forEach((el) => {
    el.addEventListener('blur', () => {
      const n = num(el, NaN);
      if (Number.isFinite(n)) {
        el.value = formatKr(n);
      }
    });
  });
  root.addEventListener('input', (event) => {
    if (event.target.matches('[data-in]')) {
      paint();
    }
  });
  root.querySelectorAll('input[data-in][type="text"]').forEach((el) => {
    const n = num(el, NaN);
    if (Number.isFinite(n)) {
      el.value = formatKr(n);
    }
  });
  paint();
  }
  window.jeabBootOffertEv = jeabBootOffertEv;
  jeabBootOffertEv();
})();

(() => {
  function jeabBootOffertSave() {
  const bar = document.querySelector('[data-js="offert-save-bar"]');
  const btn = document.querySelector('[data-js="offert-save"]');
  if (!bar || !btn) {
    return;
  }
  if (bar.dataset.jeabBoot === '1') {
    return;
  }
  if (window.__jeabOffertSaveBound) {
    window.__jeabShellFallback = true;
    return;
  }
  window.__jeabOffertSaveBound = true;
  bar.dataset.jeabBoot = '1';

  function parseStandard() {
    try {
      const data = JSON.parse(bar.dataset.standard || '{}');
      return data && typeof data === 'object' ? data : {};
    } catch {
      return {};
    }
  }

  let standard = parseStandard();

  function numValue(el) {
    if (el.type === 'checkbox') {
      return el.checked ? 1 : 0;
    }
    const raw = String(el.value || '').replace(/\s/g, '').replace(',', '.');
    const n = Number(raw);
    return Number.isFinite(n) ? n : 0;
  }

  function currentValues() {
    const values = {};
    document.querySelectorAll('[data-js="offert"] [data-in], [data-js="offert-ev"] [data-in]').forEach((el) => {
      const key = el.dataset.in || '';
      if (!key || key === 'c23' || (key === 'g27' && el.dataset.auto === '1')) {
        return;
      }
      values[key] = numValue(el);
    });
    const typ = document.querySelector('[data-js="offert"] input[name="sigen_typ"]:checked');
    const kw = document.querySelector('[data-js="offert"] input[name="sigen_kw"]:checked');
    if (typ && typ.value) {
      values.sigen_typ = typ.value;
    }
    if (kw && kw.value) {
      values.sigen_kw = kw.value;
    }
    return values;
  }

  function sameNum(a, b) {
    return Math.round(Number(a)) === Math.round(Number(b));
  }

  function sameValue(key, a, b) {
    if (key === 'sigen_typ' || key === 'sigen_kw') {
      const left = a === undefined || a === null ? '' : String(a);
      const right = b === undefined || b === null ? '' : String(b);
      return left === right;
    }
    return sameNum(a, b);
  }

  function isDirty() {
    const cur = currentValues();
    const g27el = document.querySelector('[data-js="offert"] [data-in="g27"]');
    const g27Auto = !!(g27el && g27el.dataset.auto === '1');
    return Object.keys(standard).some((key) => (key === 'c23' || (key === 'g27' && g27Auto)) ? false : !sameValue(key, cur[key], standard[key]));
  }

  function syncSave() {
    const dirty = isDirty();
    bar.hidden = !dirty;
    bar.classList.toggle('is-on', dirty);
  }

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf"]');
    return meta ? meta.getAttribute('content') || '' : '';
  }

  function saveErrorEl() {
    let note = bar.querySelector('[data-js="offert-save-error"]');
    if (!note) {
      note = document.createElement('p');
      note.className = 'offert-save-error';
      note.setAttribute('role', 'alert');
      note.dataset.js = 'offert-save-error';
      note.hidden = true;
      bar.prepend(note);
    }
    return note;
  }

  function clearSaveError() {
    const note = bar.querySelector('[data-js="offert-save-error"]');
    if (!note) {
      return;
    }
    note.textContent = '';
    note.hidden = true;
  }

  function showSaveError(message) {
    const note = saveErrorEl();
    note.textContent = message || 'Kunde inte spara standard.';
    note.hidden = false;
    bar.hidden = false;
    bar.classList.add('is-on');
  }

  function saveStandard() {
    const values = currentValues();
    const body = new FormData();
    body.set('action', 'offert.defaults');
    body.set('_csrf', csrfToken());
    Object.keys(values).forEach((key) => {
      body.set(key, String(values[key]));
    });
    clearSaveError();
    btn.setAttribute('aria-busy', 'true');
    fetch(bar.dataset.action || 'index.php?p=offert', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken(),
        Accept: 'application/json',
      },
    }).then(async (res) => {
      let data = {};
      try {
        data = await res.json();
      } catch {
        data = {};
      }
      if (res.status === 503 || (data && (data.busy || data.retry))) {
        throw new Error('Servern är upptagen. Försök igen.');
      }
      if (!res.ok || (data && data.ok === false)) {
        const msg = data && typeof data.error === 'string' && data.error !== ''
          ? data.error
          : 'Kunde inte spara standard.';
        throw new Error(msg);
      }
      return data;
    }).then(() => {
      clearSaveError();
      standard = values;
      bar.dataset.standard = JSON.stringify(values);
      syncSave();
    }).catch((err) => {
      const msg = err && typeof err.message === 'string' && err.message !== ''
        ? err.message
        : 'Kunde inte spara standard.';
      showSaveError(msg);
    }).finally(() => {
      btn.removeAttribute('aria-busy');
    });
  }

  document.addEventListener('input', (event) => {
    if (event.target && event.target.matches && event.target.matches('[data-in]')) {
      syncSave();
    }
  });
  document.addEventListener('change', (event) => {
    const target = event.target;
    if (target && target.matches && target.matches('[data-in], input[name="sigen_typ"], input[name="sigen_kw"]')) {
      syncSave();
    }
  });
  document.addEventListener('click', (event) => {
    if (event.target && event.target.closest && event.target.closest('[data-js="offert-step"]')) {
      requestAnimationFrame(syncSave);
    }
  });
  btn.addEventListener('click', (event) => {
    event.preventDefault();
    saveStandard();
  });
  syncSave();
  }
  window.jeabBootOffertSave = jeabBootOffertSave;
  jeabBootOffertSave();
})();

(function () {
  function jeabBootCalAccounts() {
  const root = document.querySelector('[data-js="cal-accounts"]');
  if (!root || root._jeabBound) {
    return;
  }
  root._jeabBound = true;
  root.addEventListener('toggle', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLDetailsElement) || !target.classList.contains('cal-accounts-person') || !target.open) {
      return;
    }
    root.querySelectorAll('.cal-accounts-person').forEach((el) => {
      if (el !== target) {
        el.open = false;
      }
    });
  }, true);
  }
  window.jeabBootCalAccounts = jeabBootCalAccounts;
  jeabBootCalAccounts();
})();

(function () {
  function jeabBootRot() {
  if (!document.body.classList.contains('page-rot')) {
    return;
  }
  const root = document.querySelector('.rot-app') || document.querySelector('main.main');
  if (!root) {
    return;
  }
  if (root.dataset.jeabBoot === '1') {
    return;
  }
  if (window.__jeabRotBound) {
    window.__jeabShellFallback = true;
    return;
  }
  window.__jeabRotBound = true;
  root.dataset.jeabBoot = '1';
  const spinner = root.querySelector('.loading-spinner');
  let hideTimer = 0;
  function setSearching(on) {
    root.classList.toggle('is-searching', on);
    if (spinner) {
      spinner.classList.toggle('is-on', on);
      spinner.setAttribute('aria-busy', on ? 'true' : 'false');
      spinner.hidden = !on;
      spinner.style.display = on ? '' : 'none';
    }
    window.clearTimeout(hideTimer);
    if (on) {
      hideTimer = window.setTimeout(() => setSearching(false), 20000);
    }
  }
  setSearching(false);
  function controlLabel(el) {
    return `${el.textContent || el.value || ''} ${el.getAttribute('aria-label') || ''}`.replace(/\s+/g, ' ').trim();
  }
  function isDetailsToggle(el) {
    const label = controlLabel(el);
    return /detaljer/i.test(label) && /visa mer|dölj/i.test(label);
  }
  function isSearchControl(el) {
    if (!(el instanceof HTMLElement)) {
      return false;
    }
    const label = controlLabel(el);
    if (isDetailsToggle(el) || /importera|laga/i.test(label)) {
      return false;
    }
    return /sök/i.test(label) || el.matches('button[type="submit"], input[type="submit"]');
  }
  function detailsTargets(btn) {
    const scope = btn.closest('.response-card') || root;
    const wraps = [...scope.querySelectorAll('.extra-details, .extra-info, .extra-wrap, [data-extra], #extra-details, #more-details')];
    if (wraps.length) {
      return wraps;
    }
    return [...scope.querySelectorAll('.extra-table')];
  }
  function isShown(el) {
    if (el.hasAttribute('hidden') || el.classList.contains('hidden') || el.classList.contains('is-hidden')) {
      return false;
    }
    if (el.style.display === 'none') {
      return false;
    }
    if (!el.classList.contains('is-open') && el.matches('.extra-details, .extra-info, .extra-wrap')) {
      return false;
    }
    const css = window.getComputedStyle(el);
    if (css.display === 'none' || css.visibility === 'hidden') {
      return false;
    }
    return el.getClientRects().length > 0;
  }
  function showEl(el) {
    el.removeAttribute('hidden');
    el.classList.remove('hidden', 'is-hidden', 'd-none');
    el.classList.add('is-open');
    el.style.display = '';
  }
  function hideEl(el) {
    el.classList.remove('is-open', 'open');
    el.removeAttribute('hidden');
    el.style.display = '';
  }
  function setDetailsLabel(btn, open) {
    const icon = btn.querySelector('svg, i, .icon, .ico');
    const text = open ? 'Dölj detaljer' : 'Visa mer detaljer';
    const current = controlLabel(btn);
    if (/detaljer/i.test(current) && (open ? /dölj/i.test(current) : /visa mer/i.test(current)) && btn.getAttribute('aria-expanded') === (open ? 'true' : 'false')) {
      return;
    }
    if (icon && btn.childNodes.length) {
      [...btn.childNodes].forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
          node.textContent = ` ${text}`;
        }
      });
      if (![...btn.childNodes].some((node) => node.nodeType === Node.TEXT_NODE && /detaljer/i.test(node.textContent))) {
        btn.append(` ${text}`);
      }
    } else {
      btn.textContent = text;
    }
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function toggleDetails(btn) {
    const card = btn.closest('.response-card') || root;
    const targets = detailsTargets(btn);
    if (!targets.length) {
      return;
    }
    const open = targets.some((el) => !isShown(el));
    targets.forEach((el) => {
      const chain = [];
      let node = el;
      while (node && node !== card && node !== root) {
        chain.unshift(node);
        node = node.parentElement;
      }
      chain.forEach(open ? showEl : hideEl);
    });
    setDetailsLabel(btn, open);
  }
  function closeDetailsOnLoad() {
    root.querySelectorAll('.extra-details, .extra-info, .extra-wrap').forEach((el) => {
      if (!el.classList.contains('is-open')) {
        el.style.display = '';
        el.classList.remove('open');
      }
    });
    root.querySelectorAll('button, a, [role="button"]').forEach((btn) => {
      if (isDetailsToggle(btn) && !/dölj/i.test(controlLabel(btn))) {
        setDetailsLabel(btn, false);
      }
    });
  }
  closeDetailsOnLoad();
  function titleCaseSv(text) {
    return String(text).replace(/[A-ZÅÄÖa-zåäö]+/g, (word) => {
      if (/^\d/.test(word)) {
        return word;
      }
      return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
    });
  }
  function formatMatchedAddress(raw) {
    let text = String(raw).replace(/^(?:Matchad\s+adress|Adress):\s*/i, '').replace(/\s+/g, ' ').trim();
    const tokens = text.split(' ').filter(Boolean);
    const dedup = [];
    tokens.forEach((token) => {
      if (!dedup.length || dedup[dedup.length - 1].toLowerCase() !== token.toLowerCase()) {
        dedup.push(token);
      }
    });
    text = dedup.join(' ');
    const post = text.match(/\b(\d{3})\s?(\d{2})\b/);
    const postnr = post ? `${post[1]} ${post[2]}` : '';
    if (post) {
      text = text.replace(post[0], ' ').replace(/\s+/g, ' ').trim();
    }
    let street = '';
    const streetMatch = text.match(/([A-ZÅÄÖ][\wåäö.-]*(?:vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d+[A-Za-z]?)/i);
    if (streetMatch) {
      street = streetMatch[1].replace(/\s+/g, ' ').trim();
      text = text.replace(streetMatch[1], ' ').replace(/\s+/g, ' ').trim();
    } else {
      const loose = text.match(/([A-ZÅÄÖ][\wåäö.-]+\s+\d+[A-Za-z]?)/);
      if (loose) {
        street = loose[1];
        text = text.replace(loose[1], ' ').replace(/\s+/g, ' ').trim();
      }
    }
    text = text.replace(/[,\s]+/g, ' ').trim();
    const city = [...new Set(text.split(' ').filter(Boolean).map((w) => w))].filter((word, index, arr) => (
      arr.findIndex((other) => other.toLowerCase() === word.toLowerCase()) === index
    )).join(' ');
    return [titleCaseSv(street), [postnr, titleCaseSv(city)].filter(Boolean).join(' ')].filter(Boolean).join(', ');
  }
  function looksLikeRawDump(text) {
    const t = String(text).replace(/\s+/g, ' ').trim();
    if (!t || t.length > 180) {
      return false;
    }
    const tokens = t.split(' ');
    const hasDupCity = tokens.length >= 3 && tokens[0].toLowerCase() === tokens[1].toLowerCase();
    const hasStreet = /(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d/i.test(t);
    const gluedPost = /\b\d{5}\b/.test(t);
    return hasStreet && (hasDupCity || gluedPost || /^[A-ZÅÄÖ]{3,}/.test(t));
  }
  function isSearchWireField(input) {
    if (!(input instanceof HTMLInputElement)) {
      return true;
    }
    if (input.type === 'hidden' || input.hidden || input.hasAttribute('hidden')) {
      return true;
    }
    const name = `${input.name || ''} ${input.id || ''}`.toLowerCase();
    if (/(\bid\b|uuid|objekt|fastighet|designation|beteckning|lmv|token|key|ref)/.test(name)) {
      return true;
    }
    return false;
  }
  function visibleSearchInput() {
    const wrap = root.querySelector('.address-wrapper');
    if (!wrap) {
      return null;
    }
    return [...wrap.querySelectorAll('input')].find((input) => {
      if (isSearchWireField(input)) {
        return false;
      }
      if (input.type && input.type !== 'text' && input.type !== 'search') {
        return false;
      }
      const css = window.getComputedStyle(input);
      if (css.display === 'none' || css.visibility === 'hidden') {
        return false;
      }
      return true;
    }) || null;
  }
  function tidySearchInputs() {
    // Keep the query as typed. Rewriting to "Dimvägen 38, 176 67 Järfälla"
    // makes Lantmäteriet return not_found.
  }
  function rotSuggestList() {
    let list = root.querySelector('#rot-suggest, ul.rot-suggest');
    if (list instanceof HTMLElement) {
      return list;
    }
    const wrap = root.querySelector('.address-wrapper');
    if (!wrap) {
      return null;
    }
    list = document.createElement('ul');
    list.id = 'rot-suggest';
    list.className = 'rot-suggest';
    list.hidden = true;
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Adresser');
    wrap.appendChild(list);
    return list;
  }
  function clearRawLookup() {
    const rawField = root.querySelector('#search-form [name="q_raw"]');
    if (rawField instanceof HTMLInputElement) {
      rawField.value = '';
    }
    const idField = root.querySelector('#search-form [name="q_id"]');
    if (idField instanceof HTMLInputElement) {
      idField.value = '';
    }
  }
  function hideSuggestions() {
    const list = rotSuggestList();
    if (!list) {
      return;
    }
    list.innerHTML = '';
    list.hidden = true;
  }
  function showSuggestions(items) {
    const list = rotSuggestList();
    if (!list) {
      return;
    }
    list.innerHTML = '';
    if (!items.length) {
      list.hidden = true;
      return;
    }
    items.forEach((item) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.dataset.rawAddress = item.raw || '';
      li.dataset.tidyAddress = item.label || '';
      li.dataset.lmId = item.id || '';
      li.textContent = item.label || item.raw || '';
      list.appendChild(li);
    });
    list.hidden = false;
    placeSuggestList(list);
  }
  let suggestTimer = 0;
  let suggestGen = 0;
  function requestSuggestions(q) {
    const query = String(q || '').replace(/,/g, ' ').replace(/\s+/g, ' ').trim();
    window.clearTimeout(suggestTimer);
    if (query.length < 3) {
      hideSuggestions();
      setSearching(false);
      return;
    }
    suggestTimer = window.setTimeout(() => {
      const gen = ++suggestGen;
      const form = root.querySelector('#search-form');
      const action = form instanceof HTMLFormElement ? (form.getAttribute('action') || window.location.pathname) : window.location.pathname;
      const url = new URL(action, window.location.origin);
      url.searchParams.set('intent', 'suggest');
      url.searchParams.set('q', query);
      setSearching(true);
      fetch(url.toString(), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      }).then((res) => {
        if (!res.ok) {
          throw new Error('suggest');
        }
        return res.json();
      }).then((data) => {
        if (gen !== suggestGen) {
          return;
        }
        setSearching(false);
        showSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
      }).catch(() => {
        if (gen === suggestGen) {
          setSearching(false);
        }
      });
    }, 220);
  }
  function applyPickedAddress(item) {
    const raw = String(item.dataset.rawAddress || '').trim();
    const tidy = (item.dataset.tidyAddress || formatMatchedAddress(item.textContent) || item.textContent.replace(/\s+/g, ' ').trim());
    if (!raw && !tidy) {
      return;
    }
    const input = visibleSearchInput();
    const rawField = root.querySelector('#search-form [name="q_raw"]');
    if (rawField instanceof HTMLInputElement && raw) {
      rawField.value = raw;
    }
    const idField = root.querySelector('#search-form [name="q_id"]');
    if (idField instanceof HTMLInputElement) {
      idField.value = String(item.dataset.lmId || '').trim();
    }
    if (input && (tidy || raw)) {
      input.value = tidy || raw;
    }
    hideSuggestions();
    const form = root.querySelector('#search-form');
    if (form instanceof HTMLFormElement && (raw || tidy)) {
      window.setTimeout(() => form.requestSubmit(), 0);
    }
  }
  function tidyMatchedAddresses(scope) {
    const area = scope || root;
    area.querySelectorAll('p, div, span, li, dd, td, strong, small, label').forEach((el) => {
      if (el.childElementCount) {
        return;
      }
      const raw = el.textContent.trim();
      if (!/^Matchad\s+adress:/i.test(raw)) {
        return;
      }
      const next = formatMatchedAddress(raw);
      if (next) {
        el.textContent = next;
      }
    });
    const walk = document.createTreeWalker(area, NodeFilter.SHOW_TEXT);
    const nodes = [];
    let node = walk.nextNode();
    while (node) {
      if (/Matchad\s+adress:/i.test(node.textContent)) {
        nodes.push(node);
      }
      node = walk.nextNode();
    }
    nodes.forEach((textNode) => {
      const next = formatMatchedAddress(textNode.textContent);
      if (next) {
        textNode.textContent = textNode.textContent.replace(/Matchad\s+adress:\s*.*/i, next);
      }
    });
  }
  function looksLikeRawSuggestion(text) {
    const t = String(text).replace(/\s+/g, ' ').trim();
    if (!t || t.length > 160 || t.includes(',') || /^Matchad\s+adress:/i.test(t)) {
      return false;
    }
    const tokens = t.split(' ');
    const hasDupCity = tokens.length >= 3 && tokens[0].toLowerCase() === tokens[1].toLowerCase();
    const hasStreet = /(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d/i.test(t);
    return hasDupCity && hasStreet;
  }
  function isSuggestionHost(el) {
    if (!(el instanceof HTMLElement) || el.closest('.response-card, .tabs, .chrome')) {
      return false;
    }
    const id = (el.id || '').toLowerCase();
    const cls = String(el.className || '').toLowerCase();
    const role = (el.getAttribute('role') || '').toLowerCase();
    if (role === 'listbox' || role === 'menu') {
      return true;
    }
    return /auto.?complete|suggest|typeahead|address.?list|search.?list|ui-menu|pac-container/.test(`${id} ${cls}`);
  }
  function setSuggestionLabel(el, next) {
    if (el.childElementCount === 0) {
      el.textContent = next;
      return;
    }
    const hidden = [...el.querySelectorAll('input[type="hidden"]')];
    el.textContent = next;
    hidden.forEach((input) => el.appendChild(input));
  }
  function tidySuggestionItem(el, inHost) {
    if (!(el instanceof HTMLElement) || el.closest('.response-card, .tabs, .chrome')) {
      return;
    }
    const raw = el.textContent.replace(/\s+/g, ' ').trim();
    if (inHost) {
      if (!looksLikeRawSuggestion(raw) && !/(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d/i.test(raw)) {
        return;
      }
      if (raw.includes(',') && !looksLikeRawSuggestion(raw)) {
        return;
      }
    } else if (!looksLikeRawSuggestion(raw)) {
      return;
    }
    const next = formatMatchedAddress(raw);
    if (!next || next === raw) {
      return;
    }
    if (!el.dataset.rawAddress) {
      el.dataset.rawAddress = raw;
    }
    el.dataset.tidyAddress = next;
    setSuggestionLabel(el, next);
  }
  function collectSuggestionHosts(scope) {
    const area = scope instanceof Element ? scope : document;
    const hosts = [];
    const seen = new Set();
    const add = (el) => {
      if (el instanceof HTMLElement && !seen.has(el) && isSuggestionHost(el)) {
        seen.add(el);
        hosts.push(el);
      }
    };
    area.querySelectorAll('ul, ol, div, nav, [role="listbox"], [role="menu"]').forEach(add);
    root.querySelectorAll('.address-wrapper').forEach((wrap) => {
      wrap.querySelectorAll('ul, ol, [role="listbox"]').forEach(add);
      let sib = wrap.nextElementSibling;
      for (let i = 0; i < 4 && sib; i += 1) {
        if (sib.classList.contains('response-card') || sib.classList.contains('loading-spinner')) {
          sib = sib.nextElementSibling;
          continue;
        }
        if (sib.matches('[role="listbox"], ul, ol') || isSuggestionHost(sib)) {
          seen.add(sib);
          hosts.push(sib);
        } else if (sib.matches('div') && sib.children.length && [...sib.children].every((child) => {
          const t = child.textContent.replace(/\s+/g, ' ').trim();
          return looksLikeRawSuggestion(t) || /(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d/i.test(t);
        })) {
          seen.add(sib);
          hosts.push(sib);
        }
        sib = sib.nextElementSibling;
      }
    });
    return hosts;
  }
  function tidySuggestionRows(scope) {
    const hosts = collectSuggestionHosts(scope);
    hosts.forEach((host) => {
      const items = host.querySelectorAll('li, button, a, [role="option"], [role="menuitem"], .autocomplete-item, .suggestion, .ui-menu-item, .pac-item');
      if (items.length) {
        items.forEach((item) => tidySuggestionItem(item, true));
        return;
      }
      [...host.children].forEach((child) => tidySuggestionItem(child, true));
    });
    const scan = scope instanceof Element ? scope : root;
    scan.querySelectorAll('li, button, a, [role="option"], [role="menuitem"]').forEach((el) => {
      tidySuggestionItem(el, false);
    });
  }
  function isCopyControl(el) {
    if (!(el instanceof HTMLElement)) {
      return false;
    }
    if (el.classList.contains('copy-button') || el.classList.contains('copy-btn') || el.classList.contains('copy') || el.classList.contains('rot-copy')) {
      return true;
    }
    if (el.hasAttribute('data-copy') || el.hasAttribute('data-clipboard') || el.hasAttribute('data-clipboard-text')) {
      return true;
    }
    return /kopiera|\bcopy\b/i.test(controlLabel(el)) || !!el.querySelector('.glyphicon-copy, .glyphicon-duplicate, .glyphicon-clipboard, .fa-copy, .fa-clipboard');
  }
  function labelCopyControl(el) {
    el.classList.add('rot-copy');
    if (!el.querySelector('.rot-copy-ico')) {
      el.innerHTML = '<svg class="rot-copy-ico" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><rect x="5.2" y="5.2" width="8.3" height="8.3" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M10.6 5.2V3.6A1.4 1.4 0 0 0 9.2 2.2H3.6A1.4 1.4 0 0 0 2.2 3.6v5.6A1.4 1.4 0 0 0 3.6 10.6H5.2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>';
    }
    el.setAttribute('aria-label', 'Kopiera');
    if (el.tagName === 'BUTTON' && !el.getAttribute('type')) {
      el.type = 'button';
    }
    syncCopyAttrs(el, copyTargetText(el));
  }
  function visibleCopyValue(btn) {
    const cell = btn.closest('td, dd, p, div, li') || btn.parentElement;
    if (!cell) {
      return '';
    }
    const field = cell.querySelector('.rot-field-value');
    if (field && field.textContent.trim()) {
      return field.textContent.replace(/\s+/g, ' ').trim();
    }
    const clone = cell.cloneNode(true);
    clone.querySelectorAll('button, a, [role="button"], .rot-copy, .copy-button, .copy-btn, .rot-field-label').forEach((node) => node.remove());
    let text = clone.textContent.replace(/\s+/g, ' ').trim();
    if (!text || /^(Namn|Personnummer|Adress|Mailadress|E-post|Telefonnummer|Telefon|Mobilnummer|Mobil):?$/i.test(text)) {
      const prev = cell.previousElementSibling;
      if (prev) {
        const prevClone = prev.cloneNode(true);
        prevClone.querySelectorAll('button, a, [role="button"], .rot-copy, .copy-button, .rot-field-label').forEach((node) => node.remove());
        text = prevClone.textContent.replace(/\s+/g, ' ').trim();
      }
    }
    return text.replace(/^(Namn|Personnummer|Adress|Mailadress|E-post|Telefonnummer|Telefon|Mobilnummer|Mobil):\s*/i, '').trim();
  }
  function copyTargetText(btn) {
    let text = visibleCopyValue(btn);
    if (!text) {
      text = (btn.getAttribute('data-copy') || btn.getAttribute('data-clipboard-text') || btn.getAttribute('data-clipboard') || '').trim();
      text = text.replace(/^(Namn|Personnummer|Adress|Mailadress|E-post|Telefonnummer|Telefon|Mobilnummer|Mobil):\s*/i, '').trim();
    }
    if (text && (needsAddressTidy(text) || looksLikeCardAddress(text))) {
      text = formatMatchedAddress(text) || text;
    }
    return text;
  }
  function syncCopyAttrs(btn, text) {
    if (!text) {
      return;
    }
    ['data-copy', 'data-clipboard', 'data-clipboard-text'].forEach((name) => {
      if (btn.hasAttribute(name)) {
        btn.setAttribute(name, text);
      }
    });
  }
  function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
    } catch {
      // Safari/old WebKit without clipboard API.
    }
    ta.remove();
  }
  function writeClipboard(text) {
    if (!text) {
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(() => fallbackCopy(text));
      return;
    }
    fallbackCopy(text);
  }
  function markRole(el) {
    const label = el.textContent.replace(/\s+/g, ' ').trim();
    el.classList.add('rot-role');
    el.setAttribute('role', 'button');
    if (!el.getAttribute('data-role')) {
      el.setAttribute('data-role', label === 'Huvudsökande' ? 'huvudsokande' : 'medsokande');
    }
    if (el.tagName !== 'BUTTON' && !el.hasAttribute('tabindex')) {
      el.setAttribute('tabindex', '0');
    }
    const twoWay = !!el.closest('.rot-owner-pills');
    const selected = twoWay
      ? el.classList.contains('is-selected') || el.getAttribute('aria-pressed') === 'true'
      : (label === 'Huvudsökande' || el.classList.contains('active') || el.getAttribute('aria-pressed') === 'true');
    el.classList.toggle('is-selected', selected);
    el.setAttribute('aria-pressed', selected ? 'true' : 'false');
    if (el.parentElement && !el.closest('.rot-owner-pills')) {
      el.parentElement.classList.add('rot-owner-line');
    }
  }
  const ownerFieldMap = [
    { key: 'name', input: 'owner_name[]', label: 'Namn' },
    { key: 'pnr', input: 'owner_pnr[]', label: 'Personnummer' },
    { key: 'address', input: 'owner_address[]', label: 'Adress' },
    { key: 'andel', input: 'owner_andel[]', label: 'Andel' },
    { key: 'lagfart', input: 'owner_lagfart[]', label: 'Lagfart' },
  ];
  function ownerCards(fromEl) {
    const scope = fromEl.closest('.rot-order, form, .rot-app') || root;
    const named = [...scope.querySelectorAll('.rot-owner')];
    if (named.length) {
      return named;
    }
    return [...scope.querySelectorAll('.response-card')].filter((card) => card.querySelector('.rot-role, .owner-badge'));
  }
  function ownerValueEl(card, field) {
    const byData = card.querySelector('[data-field="' + field.key + '"]');
    if (byData) {
      return byData;
    }
    return [...card.querySelectorAll('.rot-field-row')].reduce((found, row) => {
      if (found) {
        return found;
      }
      const lbl = row.querySelector('.rot-field-label');
      if (!lbl || lbl.textContent.replace(/\s+/g, ' ').trim() !== field.label) {
        return null;
      }
      return row.querySelector('.rot-field-value');
    }, null);
  }
  function readOwnerCard(card) {
    const data = {};
    ownerFieldMap.forEach((field) => {
      const hidden = card.querySelector('input[name="' + field.input + '"]');
      const vis = ownerValueEl(card, field);
      data[field.key] = hidden ? String(hidden.value || '') : (vis ? String(vis.textContent || '') : '');
    });
    return data;
  }
  function writeOwnerCard(card, data) {
    ownerFieldMap.forEach((field) => {
      const value = data[field.key] || '';
      const hidden = card.querySelector('input[name="' + field.input + '"]');
      if (hidden) {
        hidden.value = value;
      }
      const vis = ownerValueEl(card, field);
      if (vis) {
        vis.textContent = value;
      }
      const row = vis ? vis.closest('.rot-field-row') : null;
      if (row && (field.key === 'andel' || field.key === 'lagfart')) {
        if (value) {
          row.removeAttribute('hidden');
        } else {
          row.setAttribute('hidden', '');
        }
      }
    });
  }
  function paintOwnerRole(card, isHuvud) {
    const pills = [...card.querySelectorAll('.rot-role, .owner-badge')];
    if (!pills.length) {
      return;
    }
    if (pills.length === 1) {
      const pill = pills[0];
      pill.textContent = isHuvud ? 'Huvudsökande' : 'Medsökande';
      pill.setAttribute('data-role', isHuvud ? 'huvudsokande' : 'medsokande');
      pill.classList.toggle('is-selected', isHuvud);
      pill.setAttribute('aria-pressed', isHuvud ? 'true' : 'false');
      return;
    }
    pills.forEach((pill) => {
      const huvud = pill.getAttribute('data-role') === 'huvudsokande'
        || pill.textContent.replace(/\s+/g, ' ').trim() === 'Huvudsökande';
      const selected = isHuvud ? huvud : !huvud;
      pill.classList.toggle('is-selected', selected);
      pill.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  }
  function writeOwnerRole(card, isHuvud) {
    const hidden = card.querySelector('input[name="owner_role[]"]');
    if (hidden) {
      hidden.value = isHuvud ? 'huvudsokande' : 'medsokande';
    }
  }
  function applyHuvudCard(huvudCard) {
    const cards = ownerCards(huvudCard);
    cards.forEach((card) => {
      const isHuvud = card === huvudCard;
      paintOwnerRole(card, isHuvud);
      writeOwnerRole(card, isHuvud);
    });
  }
  function selectRole(el) {
    const cards = ownerCards(el);
    const card = el.closest('.rot-owner, .response-card');
    if (!card || !cards.length) {
      return;
    }
    const role = (el.getAttribute('data-role') || el.textContent || '').replace(/\s+/g, ' ').trim();
    const wantsHuvud = role === 'huvudsokande' || role === 'Huvudsökande';
    let huvudCard = card;
    if (!wantsHuvud) {
      if (cards.length < 2) {
        applyHuvudCard(card);
        return;
      }
      huvudCard = cards.find((item) => item !== card) || cards[0];
    }
    applyHuvudCard(huvudCard);
  }
  function syncOwnerRoles(scope) {
    const cards = ownerCards(scope);
    if (!cards.length) {
      return;
    }
    const huvudCard = cards.find((card) => {
      const pill = card.querySelector('.rot-role[data-role="huvudsokande"], .owner-badge[data-role="huvudsokande"]');
      return !!(pill && (pill.classList.contains('is-selected') || pill.getAttribute('aria-pressed') === 'true'));
    }) || cards[0];
    applyHuvudCard(huvudCard);
  }
  function markImportActions(area) {
    const buttons = [...area.querySelectorAll('button, a, [role="button"]')].filter((el) => /importera/i.test(controlLabel(el)));
    buttons.forEach((el) => {
      el.classList.add('rot-action');
      const secondary = /malmö|malmo/i.test(controlLabel(el));
      el.classList.toggle('is-secondary', secondary);
    });
    const groups = [];
    buttons.forEach((btn) => {
      const lastGroup = groups[groups.length - 1];
      const prev = lastGroup ? lastGroup[lastGroup.length - 1] : null;
      if (prev && prev.parentElement === btn.parentElement && (prev.nextElementSibling === btn || prev.parentElement.classList.contains('rot-import-row'))) {
        lastGroup.push(btn);
      } else {
        groups.push([btn]);
      }
    });
    groups.forEach((group) => {
      if (!group.length || group[0].parentElement.classList.contains('rot-import-row')) {
        return;
      }
      const wrap = document.createElement('div');
      wrap.className = 'rot-import-row';
      group[0].parentElement.insertBefore(wrap, group[0]);
      group.forEach((btn) => wrap.appendChild(btn));
    });
  }
  function placeSuggestList(host) {
    if (!(host instanceof HTMLElement) || host.closest('.response-card, .tabs, .chrome')) {
      return;
    }
    host.classList.add('rot-suggest');
    const wrap = root.querySelector('.address-wrapper');
    const inside = !!(wrap && (host.parentElement === wrap || wrap.contains(host)));
    host.style.listStyle = 'none';
    host.style.float = 'none';
    host.style.display = 'block';
    host.style.width = '100%';
    host.style.maxWidth = '100%';
    host.style.left = '0';
    host.style.right = '0';
    if (inside) {
      host.style.position = 'absolute';
      host.style.top = 'calc(100% + 4px)';
    } else {
      host.style.position = 'relative';
      host.style.top = 'auto';
    }
  }
  const FIELD_LABEL = /^(Namn|Personnummer|Adress|Mailadress|E-post|Telefonnummer|Telefon|Mobilnummer|Mobil)\s*:/i;
  function normalizeFieldLabel(name) {
    const key = name.replace(/:$/, '');
    const map = {
      namn: 'Namn',
      personnummer: 'Personnummer',
      adress: 'Adress',
      mailadress: 'Mailadress',
      'e-post': 'E-post',
      telefonnummer: 'Telefonnummer',
      telefon: 'Telefon',
      mobilnummer: 'Mobilnummer',
      mobil: 'Mobil',
    };
    return `${map[key.toLowerCase()] || key}:`;
  }
  function splitFieldRow(el) {
    if (!(el instanceof HTMLElement) || el.classList.contains('rot-field-row') || el.classList.contains('rot-field-label') || el.classList.contains('rot-field-value') || el.closest('.rot-field-label, .rot-field-value, .rot-copy, button, .rot-suggest, .address-wrapper')) {
      return;
    }
    if (el.querySelector('.rot-field-label, .rot-field-value')) {
      el.classList.add('rot-field-row');
      return;
    }
    const nodes = [...el.childNodes];
    const textNode = nodes.find((node) => node.nodeType === 3 && FIELD_LABEL.test(node.textContent.trim()));
    if (!textNode) {
      return;
    }
    const match = textNode.textContent.match(/^\s*(Namn|Personnummer|Adress|Mailadress|E-post|Telefonnummer|Telefon|Mobilnummer|Mobil)\s*:\s*(.*)$/i);
    if (!match) {
      return;
    }
    const label = document.createElement('span');
    label.className = 'rot-field-label';
    label.textContent = normalizeFieldLabel(match[1]);
    const value = document.createElement('span');
    value.className = 'rot-field-value';
    let val = match[2].trim();
    if (!val) {
      return;
    }
    if (/^adress$/i.test(match[1])) {
      const next = formatMatchedAddress(val);
      if (next) {
        val = next;
      }
    }
    value.textContent = val;
    el.insertBefore(label, textNode);
    el.insertBefore(value, textNode);
    textNode.remove();
    el.classList.add('rot-field-row');
  }
  function looksLikeCardAddress(text) {
    const t = String(text).replace(/\s+/g, ' ').trim();
    return /(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)\s+\d/i.test(t) && /\d{3}\s?\d{2}/.test(t);
  }
  function needsAddressTidy(text) {
    const t = String(text).replace(/\s+/g, ' ').trim();
    if (!looksLikeCardAddress(t) && !/(vägen|gatan|väg|gränd|torg|plan|stig|allén|allé|backe|leden)/i.test(t)) {
      return false;
    }
    if (!looksLikeCardAddress(t) && !/\b\d{5}\b/.test(t)) {
      return false;
    }
    return /\b\d{5}\b/.test(t) || (/[A-ZÅÄÖ]{3,}/.test(t) && t === t.toUpperCase());
  }
  function formatAddressInText(raw) {
    const labeled = String(raw).match(/^(.*?\bAdress:\s*)(.+)$/i);
    if (labeled && needsAddressTidy(labeled[2])) {
      const next = formatMatchedAddress(labeled[2]);
      return next ? `${labeled[1]}${next}` : null;
    }
    if (!needsAddressTidy(raw)) {
      return null;
    }
    return formatMatchedAddress(raw) || null;
  }
  function tidyCardFields(scope) {
    const area = scope || root;
    area.querySelectorAll('.response-card').forEach((card) => {
      const walk = document.createTreeWalker(card, NodeFilter.SHOW_TEXT);
      const nodes = [];
      let node = walk.nextNode();
      while (node) {
        const parent = node.parentElement;
        if (parent && parent.closest('button, .rot-copy, .rot-suggest, .address-wrapper, .tabs, .chrome')) {
          node = walk.nextNode();
          continue;
        }
        if (needsAddressTidy(node.textContent)) {
          nodes.push(node);
        }
        node = walk.nextNode();
      }
      nodes.forEach((textNode) => {
        const next = formatAddressInText(textNode.textContent);
        if (next && next !== textNode.textContent) {
          textNode.textContent = next;
        }
      });
      card.querySelectorAll('p, div, dd, td, li').forEach((el) => {
        splitFieldRow(el);
      });
      card.querySelectorAll('dt, th, label, strong, .rot-field-label').forEach((el) => {
        if (FIELD_LABEL.test(`${el.textContent.trim()}:`) || /^(Namn|Personnummer|Adress|Mailadress|E-post|Telefonnummer|Telefon|Mobilnummer|Mobil):?$/i.test(el.textContent.trim())) {
          el.classList.add('rot-field-label');
        }
      });
    });
  }
  function ownText(el) {
    return [...el.childNodes]
      .filter((node) => node.nodeType === 3)
      .map((node) => node.textContent)
      .join(' ')
      .replace(/\s+/g, ' ')
      .trim();
  }
  function contactLabelText(text) {
    return String(text || '').replace(/\s+/g, ' ').replace(/:$/, '').trim();
  }
  function isMailLabel(text) {
    return /^(Mailadress|E-post|Epost|Email)$/i.test(contactLabelText(text));
  }
  function isMobileLabel(text) {
    return /^(Telefonnummer|Telefon|Mobilnummer|Mobil)$/i.test(contactLabelText(text));
  }
  function isContactLabel(text) {
    return isMailLabel(text) || isMobileLabel(text);
  }
  function fieldBits(input) {
    return `${input.name || ''} ${input.id || ''} ${input.placeholder || ''} ${input.getAttribute('aria-label') || ''}`.toLowerCase();
  }
  function escapeSel(value) {
    if (window.CSS && typeof CSS.escape === 'function') {
      return CSS.escape(value);
    }
    return String(value).replace(/([^a-zA-Z0-9_-])/g, '\\$1');
  }
  function labeledFor(input) {
    if (input.id) {
      const byFor = root.querySelector(`label[for="${escapeSel(input.id)}"]`);
      if (byFor) {
        return byFor.textContent.replace(/\s+/g, ' ').trim();
      }
    }
    const wrap = input.closest('label');
    if (wrap) {
      return wrap.textContent.replace(/\s+/g, ' ').trim();
    }
    const prev = input.previousElementSibling;
    if (prev && /^(LABEL|DT|TH|STRONG|SPAN)$/.test(prev.tagName)) {
      return prev.textContent.replace(/\s+/g, ' ').trim();
    }
    return '';
  }
  function isRotSearchField(input) {
    if (!(input instanceof HTMLInputElement) || isSearchWireField(input)) {
      return true;
    }
    if (input.closest('.address-wrapper, #search-form, .rot-suggest')) {
      return true;
    }
    return input === visibleSearchInput();
  }
  function isMailField(input) {
    if (!(input instanceof HTMLInputElement) || isRotSearchField(input)) {
      return false;
    }
    if (input.type === 'email' || /mail|e-?post|email/.test(fieldBits(input))) {
      return true;
    }
    return isMailLabel(labeledFor(input));
  }
  function isMobileField(input) {
    if (!(input instanceof HTMLInputElement) || isRotSearchField(input) || isMailField(input)) {
      return false;
    }
    if (input.type === 'tel' || input.dataset.rotMobile === '1') {
      return true;
    }
    if (/(mobil|telefon)/.test(fieldBits(input))) {
      return true;
    }
    return isMobileLabel(labeledFor(input));
  }
  function isContactField(input) {
    return isMailField(input) || isMobileField(input);
  }
  function normalizeMobile(raw) {
    const digits = String(raw).replace(/\D+/g, '');
    const idx = digits.indexOf('7');
    if (idx === -1) {
      return digits;
    }
    return `+46${digits.slice(idx)}`;
  }
  function applyMobileValue(input) {
    const next = normalizeMobile(input.value);
    if (input.value !== next) {
      input.value = next;
    }
  }
  function findContactLabel(input) {
    if (input.id) {
      const byFor = root.querySelector(`label[for="${escapeSel(input.id)}"]`);
      if (byFor) {
        return byFor;
      }
    }
    const wrap = input.closest('label');
    if (wrap && wrap !== input.parentElement) {
      return wrap;
    }
    const prev = input.previousElementSibling;
    if (prev && isContactLabel(prev.textContent) && !prev.querySelector('input')) {
      return prev;
    }
    return null;
  }
  function markContactRows(area) {
    area.querySelectorAll('.response-card input').forEach((input) => {
      if (!isContactField(input) || input.closest('.rot-contact-row')) {
        return;
      }
      input.style.maxWidth = '';
      input.style.width = '';
      const parent = input.parentElement;
      if (!parent || parent.classList.contains('rot-contact-row')) {
        return;
      }
      if (parent.matches('td, th')) {
        const cell = parent.previousElementSibling;
        if (cell && isContactLabel(cell.textContent)) {
          cell.classList.add('rot-field-label');
        }
        return;
      }
      const label = findContactLabel(input);
      const row = document.createElement('div');
      row.className = 'rot-contact-row';
      if (label && label.parentElement === parent) {
        parent.insertBefore(row, label);
        row.appendChild(label);
        row.appendChild(input);
      } else {
        parent.insertBefore(row, input);
        if (label && !row.contains(label) && !label.contains(input)) {
          row.appendChild(label);
        }
        row.appendChild(input);
      }
      if (label) {
        label.classList.add('rot-field-label');
      }
    });
  }
  function hideValfriBoxes(area) {
    const doomed = new Set();
    const mark = (el) => {
      if (!(el instanceof HTMLElement)) {
        return;
      }
      if (el.matches('#search-form, form, .response-card, .rot-app, .main, .address-wrapper, body, html')) {
        return;
      }
      doomed.add(el);
    };
    area.querySelectorAll('#optional-check, input[name="optional-check"], input[id="optional-check"]').forEach((input) => {
      const label = input.id ? area.querySelector(`label[for="${escapeSel(input.id)}"]`) : null;
      mark(input);
      if (label) {
        mark(label);
      }
      const wrap = input.closest('label');
      if (wrap) {
        mark(wrap);
      }
      const parent = input.parentElement;
      if (parent) {
        const parentText = parent.textContent.replace(/\s+/g, ' ').trim();
        if (parent.childElementCount <= 4 && parentText.length <= 80 && /valfri/i.test(parentText)) {
          mark(parent);
        }
      }
    });
    area.querySelectorAll('label, p, div, span, small, legend, strong, li').forEach((el) => {
      if (el.closest('.address-wrapper, .rot-suggest, .chrome, .tabs, .rot-contact-row')) {
        return;
      }
      const text = (ownText(el) || el.textContent.replace(/\s+/g, ' ').trim());
      if (!text || text.length > 64 || !/valfri/i.test(text) || !/(telefon|mail)/i.test(text)) {
        return;
      }
      mark(el);
      const parent = el.parentElement;
      if (parent) {
        const parentText = parent.textContent.replace(/\s+/g, ' ').trim();
        if (parent.childElementCount <= 4 && parentText.length <= 80 && /valfri/i.test(parentText)) {
          mark(parent);
        }
      }
    });
    doomed.forEach((el) => {
      el.querySelectorAll('input, select, textarea').forEach((field) => {
        field.required = false;
        field.removeAttribute('required');
        field.removeAttribute('aria-required');
      });
      el.remove();
    });
  }
  function isContactRequiredAlert(msg) {
    const text = String(msg || '');
    if (/valfri/i.test(text) && /(telefon|mail)/i.test(text)) {
      return true;
    }
    return /(telefon|mail|e-?post)/i.test(text) && /(ange|fyll|obligator|måste|required|saknas|behöver|krävs|minst en)/i.test(text);
  }
  function stripContactGateHandlers(area) {
    area.querySelectorAll('[onclick], [onsubmit]').forEach((el) => {
      ['onclick', 'onsubmit'].forEach((attr) => {
        const raw = el.getAttribute(attr);
        if (!raw || !/(telefon|mail|valfri|e-?post|phone|email)/i.test(raw)) {
          return;
        }
        el.setAttribute(attr, raw.replace(/if\s*\([^;]{0,240}?(telefon|mail|valfri|phone|email|e-?post)[^;]{0,240}?\)\s*\{[\s\S]*?\}/i, ''));
      });
    });
    area.querySelectorAll('form').forEach((form) => {
      form.noValidate = true;
    });
    area.querySelectorAll('input, select, textarea').forEach((field) => {
      if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLSelectElement) && !(field instanceof HTMLTextAreaElement)) {
        return;
      }
      if (field instanceof HTMLInputElement && isRotSearchField(field)) {
        return;
      }
      if (field instanceof HTMLInputElement && !isContactField(field) && !/valfri/i.test(`${field.name || ''} ${field.id || ''}`)) {
        return;
      }
      field.required = false;
      field.removeAttribute('required');
      field.removeAttribute('aria-required');
    });
  }
  function wireMobileInputs(area) {
    area.querySelectorAll('input').forEach((input) => {
      if (!isMobileField(input)) {
        return;
      }
      if (input.type === 'tel') {
        input.type = 'text';
      }
      input.setAttribute('inputmode', 'text');
      input.removeAttribute('pattern');
      input.dataset.rotMobile = '1';
      applyMobileValue(input);
    });
  }
  if (!window.__jeabRotAlertPatched) {
    window.__jeabRotAlertPatched = true;
    const nativeAlert = window.alert;
    window.alert = function rotAlert(msg) {
      if (isContactRequiredAlert(msg)) {
        return undefined;
      }
      return nativeAlert.apply(this, arguments);
    };
  }
  let chromeLock = false;
  function isOurChromeNode(node) {
    return node.nodeType === 1 && (
      node.classList.contains('rot-field-label')
      || node.classList.contains('rot-field-value')
      || node.classList.contains('rot-field-row')
      || node.classList.contains('rot-contact-row')
      || node.classList.contains('rot-optional-box')
      || node.classList.contains('rot-import-row')
      || node.classList.contains('rot-copy')
      || node.classList.contains('rot-role')
      || node.classList.contains('rot-action')
    );
  }
  function markOrderChrome(scope) {
    if (chromeLock) {
      return;
    }
    chromeLock = true;
    try {
      const area = scope || root;
      area.querySelectorAll('button, a, [role="button"]').forEach((el) => {
        const label = controlLabel(el);
        if (/importera/i.test(label)) {
          el.classList.add('rot-action');
        }
        if (isDetailsToggle(el)) {
          el.classList.add('rot-details');
        }
        if (isCopyControl(el) || el.querySelector('.glyphicon-copy, .glyphicon-duplicate, .glyphicon-clipboard')) {
          labelCopyControl(el);
        }
      });
      area.querySelectorAll('span, small, em, strong, button, div, p, li, td, label, .owner-badge').forEach((el) => {
        const label = el.textContent.replace(/\s+/g, ' ').trim();
        if (label !== 'Huvudsökande' && label !== 'Medsökande' && !el.classList.contains('owner-badge')) {
          return;
        }
        if (el.childElementCount > 1) {
          return;
        }
        markRole(el);
      });
      markImportActions(area);
      tidyMatchedAddresses(area);
      tidyCardFields(area);
      hideValfriBoxes(area);
      stripContactGateHandlers(area);
      markContactRows(area);
      wireMobileInputs(area);
      tidySuggestionRows(area);
      collectSuggestionHosts(document).forEach(placeSuggestList);
    } finally {
      chromeLock = false;
    }
  }
  markOrderChrome();
  tidySuggestionRows(document);
  const startQ = visibleSearchInput();
  if (startQ && startQ.value.trim().length >= 3 && !root.querySelector('.response-card')) {
    requestSuggestions(startQ.value);
  }
  root.addEventListener('input', (event) => {
    const field = event.target;
    if (field instanceof HTMLInputElement && isMobileField(field)) {
      applyMobileValue(field);
    }
    if (field instanceof HTMLInputElement && field.name === 'q' && field.closest('#search-form .address-wrapper')) {
      clearRawLookup();
      requestSuggestions(field.value);
    }
    window.setTimeout(() => {
      tidySuggestionRows(document);
    }, 0);
  }, true);
  root.addEventListener('invalid', (event) => {
    const field = event.target;
    if (field instanceof HTMLInputElement && isContactField(field)) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);
  root.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.classList.contains('rot-order')) {
      syncOwnerRoles(form);
    }
    hideValfriBoxes(root);
    stripContactGateHandlers(root);
    setSearching(true);
  }, true);
  function onSuggestPick(event) {
    const suggestItem = event.target && event.target.closest && event.target.closest('.rot-suggest li, .rot-suggest [role="option"], .ui-menu-item, .autocomplete-item, [role="option"]');
    if (suggestItem && !suggestItem.closest('.response-card, .tabs, .chrome')) {
      applyPickedAddress(suggestItem);
    }
  }
  root.addEventListener('mousedown', onSuggestPick, true);
  root.addEventListener('mousedown', (event) => {
    const btn = event.target && event.target.closest && event.target.closest('button, a, [role="button"]');
    if (btn && (isCopyControl(btn) || btn.classList.contains('rot-copy'))) {
      syncCopyAttrs(btn, copyTargetText(btn));
    }
  }, true);
  root.addEventListener('click', (event) => {
    onSuggestPick(event);
    const roleEl = event.target && event.target.closest && event.target.closest('.rot-role, .owner-badge');
    if (roleEl && root.contains(roleEl)) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      selectRole(roleEl);
      return;
    }
    const btn = event.target && event.target.closest && event.target.closest('button, a, [role="button"], input[type="submit"]');
    if (!btn) {
      return;
    }
    if (/importera/i.test(controlLabel(btn))) {
      hideValfriBoxes(root);
      stripContactGateHandlers(root);
    }
    if (isCopyControl(btn) || btn.classList.contains('rot-copy')) {
      const text = copyTargetText(btn);
      syncCopyAttrs(btn, text);
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      writeClipboard(text);
      return;
    }
    if (isDetailsToggle(btn)) {
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      toggleDetails(btn);
      return;
    }
    if (isSearchControl(btn)) {
      setSearching(true);
    }
  }, true);
  const watch = new MutationObserver((records) => {
    if (chromeLock) {
      return;
    }
    if (root.classList.contains('is-searching') && root.querySelector('.response-card')) {
      setSearching(false);
    }
    const added = records.some((record) => [...record.addedNodes].some((node) => node.nodeType === 1 && !isOurChromeNode(node)));
    if (added) {
      closeDetailsOnLoad();
      markOrderChrome();
    }
  });
  watch.observe(root, { childList: true, subtree: true });
  const watchBody = new MutationObserver((records) => {
    const added = records.some((record) => [...record.addedNodes].some((node) => node.nodeType === 1));
    if (added) {
      tidySuggestionRows(document);
    }
  });
  watchBody.observe(document.body, { childList: true, subtree: true });
  }
  window.jeabBootRot = jeabBootRot;
  jeabBootRot();
})();

(() => {
  function jeabBootMall() {
  const root = document.querySelector('[data-js="ordrar-mall"]');
  if (!root) {
    return;
  }
  if (root.dataset.jeabBoot === '1') {
    return;
  }
  if (window.__jeabMallBound) {
    window.__jeabShellFallback = true;
    return;
  }
  window.__jeabMallBound = true;
  root.dataset.jeabBoot = '1';
  const pick = root.querySelector('[data-js="ordrar-mall-pick"]');
  const name = root.querySelector('[data-js="ordrar-mall-name"]');
  const save = root.querySelector('[data-js="ordrar-mall-save"]');
  const status = root.querySelector('[data-js="ordrar-mall-status"]');
  const kindBox = root.querySelector('.ordrar-mall-kind');
  if (!pick || !name || !save) {
    return;
  }

  function parseMallar() {
    try {
      const data = JSON.parse(root.dataset.mallar || '[]');
      return Array.isArray(data) ? data : [];
    } catch {
      return [];
    }
  }

  let mallar = parseMallar();

  function csrfToken() {
    const meta = document.querySelector('meta[name="csrf"]');
    return meta ? meta.getAttribute('content') || '' : '';
  }

  function currentKind() {
    const on = root.querySelector('.ordrar-mall-kind .chip.is-on');
    return on && on.getAttribute('data-kind') === 'sol' ? 'sol' : 'batteri';
  }

  function setKind(kind) {
    const next = kind === 'sol' ? 'sol' : 'batteri';
    root.querySelectorAll('.ordrar-mall-kind .chip').forEach((chip) => {
      chip.classList.toggle('is-on', chip.getAttribute('data-kind') === next);
    });
  }

  function rowLimit(name, fallback) {
    const n = Number(root.getAttribute(name) || '');
    return Number.isFinite(n) && n > 0 ? n : fallback;
  }

  const rowStart = rowLimit('data-rows-start', 10);
  const rowStep = rowLimit('data-rows-step', 5);
  const rowMax = rowLimit('data-rows-max', 100);

  function linesBox() {
    return root.querySelector('.ordrar-lines');
  }

  function blankRow() {
    const row = document.createElement('div');
    row.className = 'ordrar-line';
    row.innerHTML = '<input type="text" name="line_sku[]" placeholder="Artikelnummer" autocomplete="off">'
      + '<input type="text" name="line_title[]" placeholder="Artikelnamn" autocomplete="off">'
      + '<input type="number" name="line_qty[]" placeholder="Antal" min="0" step="1" inputmode="numeric">';
    return row;
  }

  function syncMore() {
    const more = root.querySelector('[data-js="ordrar-lines-more"]');
    if (!more) {
      return;
    }
    more.hidden = root.querySelectorAll('.ordrar-line').length >= rowMax;
  }

  function ensureRows(count) {
    const box = linesBox();
    if (!box) {
      return [];
    }
    const target = Math.max(1, Math.min(rowMax, count));
    let rows = [...box.querySelectorAll('.ordrar-line')];
    while (rows.length < target) {
      const row = blankRow();
      box.appendChild(row);
      rows.push(row);
    }
    while (rows.length > target) {
      const row = rows.pop();
      if (row) {
        row.remove();
      }
    }
    syncMore();
    return [...box.querySelectorAll('.ordrar-line')];
  }

  function readLines() {
    return [...root.querySelectorAll('.ordrar-line')].map((row) => {
      const sku = row.querySelector('[name="line_sku[]"]');
      const title = row.querySelector('[name="line_title[]"]');
      const qty = row.querySelector('[name="line_qty[]"]');
      const n = Number(String(qty && qty.value ? qty.value : '').replace(/\s/g, '').replace(',', '.'));
      return {
        sku: sku ? String(sku.value || '').trim() : '',
        title: title ? String(title.value || '').trim() : '',
        qty: Number.isFinite(n) ? n : 0,
      };
    }).filter((line) => line.sku || line.title || line.qty);
  }

  function fillLines(lines, mode) {
    const source = Array.isArray(lines) ? lines : [];
    const count = mode === 'mall'
      ? Math.min(rowMax, source.length + 1)
      : Math.min(rowMax, Math.max(rowStart, source.length));
    const rows = ensureRows(count);
    const padded = source.slice(0, rows.length).map((line) => ({
      sku: line && line.sku ? String(line.sku) : '',
      title: line && line.title ? String(line.title) : '',
      qty: line && (line.qty || line.qty === 0) ? line.qty : '',
    }));
    while (padded.length < rows.length) {
      padded.push({ sku: '', title: '', qty: '' });
    }
    rows.forEach((row, i) => {
      const sku = row.querySelector('[name="line_sku[]"]');
      const title = row.querySelector('[name="line_title[]"]');
      const qty = row.querySelector('[name="line_qty[]"]');
      const line = padded[i];
      if (sku) sku.value = line.sku;
      if (title) title.value = line.title;
      if (qty) qty.value = line.qty === 0 || line.qty === '0' ? '' : (line.qty || '');
    });
  }

  function fillMallPick(rows, selectedId) {
    const current = selectedId || pick.value;
    pick.innerHTML = '';
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = 'Ny mall';
    pick.appendChild(blank);
    rows.slice(0, 10).forEach((mall) => {
      if (!mall || !mall.id) {
        return;
      }
      const opt = document.createElement('option');
      opt.value = mall.id;
      opt.textContent = mall.name || '';
      pick.appendChild(opt);
    });
    if (current && [...pick.options].some((o) => o.value === current)) {
      pick.value = current;
    }
  }

  function applyMall(mall) {
    if (!mall) {
      return;
    }
    setKind(mall.kind === 'sol' ? 'sol' : 'batteri');
    name.value = mall.name || '';
    fillLines(mall.lines || [], 'mall');
    if (mall.id) {
      pick.value = mall.id;
    }
  }

  function post(action, extra) {
    const body = new FormData();
    body.set('_csrf', csrfToken());
    body.set('action', action);
    Object.keys(extra || {}).forEach((key) => {
      body.set(key, extra[key]);
    });
    return fetch(root.dataset.action || 'index.php', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken(),
      },
      body,
      credentials: 'same-origin',
    }).then((res) => res.json());
  }

  if (kindBox) {
    kindBox.addEventListener('click', (ev) => {
      const chip = ev.target.closest('.chip');
      if (!chip) {
        return;
      }
      setKind(chip.getAttribute('data-kind') === 'sol' ? 'sol' : 'batteri');
    });
  }

  pick.addEventListener('change', () => {
    const id = pick.value;
    const mall = mallar.find((row) => row && row.id === id);
    if (mall) {
      applyMall(mall);
      return;
    }
    name.value = '';
    fillLines([], 'start');
  });

  const more = root.querySelector('[data-js="ordrar-lines-more"]');
  if (more) {
    more.addEventListener('click', () => {
      ensureRows(root.querySelectorAll('.ordrar-line').length + rowStep);
    });
  }
  syncMore();

  save.addEventListener('click', () => {
    if (status) {
      status.textContent = '';
    }
    post('inkop.save', {
      id: pick.value,
      name: name.value,
      kind: currentKind(),
      lines: JSON.stringify(readLines()),
    }).then((data) => {
      if (!data || !data.ok) {
        if (status) {
          status.textContent = (data && data.error) || 'Kunde inte spara.';
        }
        return;
      }
      const row = data.row || {};
      mallar = Array.isArray(row.mallar) ? row.mallar : mallar;
      root.dataset.mallar = JSON.stringify(mallar);
      fillMallPick(mallar, row.mall ? row.mall.id : '');
      if (row.mall) {
        applyMall(row.mall);
      }
      if (status) {
        status.textContent = 'Mall sparad.';
      }
    }).catch(() => {
      if (status) {
        status.textContent = 'Kunde inte spara.';
      }
    });
  });
  }
  window.jeabBootMall = jeabBootMall;
  jeabBootMall();
})();
