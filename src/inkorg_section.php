<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Ett kort på Regler. Nytt avsnitt:
 *   jeab_inkorg_section_open('id', 'Rubrik', 'En rad', ['tag' => 'form', 'action' => $post]);
 *   …fält…
 *   jeab_inkorg_section_close('Spara');
 * Flikraden byggs från korten. Öppet läge sparas i localStorage och i #ankaret.
 */

function jeab_inkorg_section_open(string $id, string $title, string $summary, array $opt = []): void
{
    $tag = ($opt['tag'] ?? '') === 'form' ? 'form' : 'section';
    $class = 'rg-sec' . (!empty($opt['class']) ? ' ' . $opt['class'] : '');
    $attrs = ' class="' . e($class) . '" id="' . e($id) . '" data-rg-sec="' . e($id) . '"';
    if (!empty($opt['force'])) {
        $attrs .= ' data-rg-force="1"';
    }
    if ($tag === 'form') {
        $attrs .= ' method="post" action="' . e((string) ($opt['action'] ?? '')) . '"';
    }
    $panel = $id . '-panel';
    echo '<' . $tag . $attrs . '>';
    echo '<button type="button" class="rg-sec-toggle" aria-expanded="false" aria-controls="' . e($panel) . '">';
    echo '<span class="rg-sec-text"><span class="rg-sec-title">' . e($title) . '</span>';
    echo '<span class="rg-sec-sum">' . e($summary) . '</span></span>';
    echo '<svg class="rg-chev" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 7.5 L10 12.5 L15 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    echo '</button>';
    echo '<div class="rg-sec-panel" id="' . e($panel) . '" hidden>';
    $GLOBALS['_JEAB_RG_SEC_STACK'][] = $tag;
}

function jeab_inkorg_section_close(?string $saveLabel = null): void
{
    if ($saveLabel !== null && $saveLabel !== '') {
        echo '<div class="rg-sec-foot"><button class="rg-save" type="submit" hidden disabled>' . e($saveLabel) . '</button></div>';
    }
    $tag = array_pop($GLOBALS['_JEAB_RG_SEC_STACK']) ?: 'section';
    echo '</div></' . $tag . '>';
}

function jeab_inkorg_section_script(): void
{
    ?>
<script>
(function () {
  var key = 'jeab.inkorg.regler.open';
  var nav = document.querySelector('[data-rg-tabs]');
  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-rg-sec]'));
  function saved() {
    try {
      var v = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(v) ? v : [];
    } catch (e) { return []; }
  }
  function remember() {
    var open = sections.filter(function (sec) { return sec.classList.contains('is-open'); }).map(function (sec) { return sec.getAttribute('data-rg-sec'); });
    try { localStorage.setItem(key, JSON.stringify(open)); } catch (e) {}
  }
  function setOpen(sec, on) {
    var btn = sec.querySelector('.rg-sec-toggle');
    var panel = sec.querySelector('.rg-sec-panel');
    sec.classList.toggle('is-open', on);
    if (btn) btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (panel) panel.hidden = !on;
  }
  function openId(id, scroll) {
    sections.forEach(function (sec) {
      if (sec.getAttribute('data-rg-sec') === id) setOpen(sec, true);
    });
    remember();
    if (nav) {
      nav.querySelectorAll('.tab').forEach(function (a) {
        var on = a.getAttribute('href') === '#' + id;
        a.classList.toggle('is-on', on);
        if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
      });
    }
    if (scroll) {
      var el = document.getElementById(id);
      if (el) el.scrollIntoView({ block: 'start' });
    }
  }
  if (nav) {
    sections.forEach(function (sec) {
      var id = sec.getAttribute('data-rg-sec');
      var title = sec.querySelector('.rg-sec-title');
      var a = document.createElement('a');
      a.className = 'tab';
      a.href = '#' + id;
      a.textContent = title ? title.textContent : id;
      nav.appendChild(a);
    });
  }
  var hash = (location.hash || '').replace('#', '');
  var have = saved();
  sections.forEach(function (sec) {
    var id = sec.getAttribute('data-rg-sec');
    setOpen(sec, have.indexOf(id) >= 0 || id === hash || sec.getAttribute('data-rg-force') === '1');
  });
  if (hash) openId(hash, true); else remember();
  sections.forEach(function (sec) {
    var btn = sec.querySelector('.rg-sec-toggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var on = !sec.classList.contains('is-open');
      setOpen(sec, on);
      remember();
      if (on && history.replaceState) history.replaceState(null, '', '#' + sec.getAttribute('data-rg-sec'));
    });
  });
  window.addEventListener('hashchange', function () {
    openId((location.hash || '').replace('#', ''), true);
  });
  function snap(form) {
    var parts = [];
    Array.prototype.forEach.call(form.querySelectorAll('input, textarea, select'), function (el) {
      if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
      parts.push(el.name + '=' + ((el.type === 'checkbox' || el.type === 'radio') ? (el.checked ? el.value : '') : el.value));
    });
    return parts.join('\n');
  }
  var forms = Array.prototype.slice.call(document.querySelectorAll('form')).filter(function (f) { return f.querySelector('.rg-save'); });
  var base = new WeakMap();
  var leaveOk = false;
  function dirty(form) { return snap(form) !== base.get(form); }
  function paint(form) {
    var btn = form.querySelector('.rg-save');
    if (!btn) return;
    var on = dirty(form);
    btn.hidden = !on;
    btn.disabled = !on;
  }
  forms.forEach(function (form) {
    base.set(form, snap(form));
    paint(form);
    form.addEventListener('input', function () { paint(form); });
    form.addEventListener('change', function () { paint(form); });
    form.addEventListener('submit', function () {
      if (forms.some(function (other) { return other !== form && dirty(other); })) return;
      if (dirty(form)) leaveOk = true;
    });
  });
  window.addEventListener('beforeunload', function (e) {
    if (leaveOk || !forms.some(dirty)) return;
    e.preventDefault();
    e.returnValue = '';
  });
  function grow(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
  }
  document.querySelectorAll('textarea[data-clamp]').forEach(function (el) {
    var more = document.createElement('button');
    more.type = 'button';
    more.className = 'rg-more';
    more.textContent = 'Visa hela';
    el.insertAdjacentElement('afterend', more);
    el.addEventListener('focus', function () {
      el.classList.add('is-editing');
      grow(el);
    });
    el.addEventListener('blur', function () {
      el.classList.remove('is-editing');
      if (!el.classList.contains('is-full')) el.style.height = '';
    });
    el.addEventListener('input', function () {
      if (el.classList.contains('is-editing') || el.classList.contains('is-full')) grow(el);
    });
    more.addEventListener('click', function () {
      var on = !el.classList.contains('is-full');
      el.classList.toggle('is-full', on);
      more.textContent = on ? 'Visa mindre' : 'Visa hela';
      if (on) grow(el); else el.style.height = '';
    });
  });
})();
</script>
    <?php
}
