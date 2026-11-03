/* Svep på ärendekort. Vänster stänger, höger växlar läst. Ett tryck öppnar tråden. */
(function (root) {
  var AXIS_PX = 10;
  var COMMIT_RATIO = 0.35;
  var TOAST_MS = 5000;

  function jeabInkorgSwipeDecide(dx, dy, width, locked) {
    locked = locked || '';
    var adx = Math.abs(dx);
    var ady = Math.abs(dy);
    if (locked === '') {
      if (adx < AXIS_PX && ady < AXIS_PX) {
        return { locked: '', commit: '', ratio: 0 };
      }
      if (ady > adx) {
        return { locked: 'scroll', commit: '', ratio: 0 };
      }
      locked = 'x';
    }
    if (locked === 'scroll') {
      return { locked: 'scroll', commit: '', ratio: 0 };
    }
    var span = width > 0 ? width : 1;
    var ratio = dx / span;
    var commit = '';
    if (Math.abs(ratio) >= COMMIT_RATIO) {
      commit = dx < 0 ? 'close' : 'read';
    }
    return { locked: 'x', commit: commit, ratio: ratio };
  }

  /* Ett tryck utan svep öppnar tråden. Bara ett committat svep sväljer klicket. */
  function jeabInkorgSwipeRelease(dx, dy, width) {
    var first = jeabInkorgSwipeDecide(dx, dy, width, '');
    if (first.locked !== 'x') {
      return { open: true, commit: '' };
    }
    var decision = jeabInkorgSwipeDecide(dx, 0, width, 'x');
    var commit = decision.commit === 'close' || decision.commit === 'read' ? decision.commit : '';
    return { open: commit === '', commit: commit };
  }

  var boundList = null;
  var docsBound = false;
  var swipe = null;
  var toastTimer = 0;
  var toastEl = null;

  function postTarget() {
    return boundList ? (boundList.getAttribute('data-ix-post') || '') : '';
  }

  function boot() {
    var next = document.getElementById('ix-list');
    if (!next) {
      return;
    }
    boundList = next;
    if (next.getAttribute('data-ix-swipe') === '1') {
      return;
    }
    next.setAttribute('data-ix-swipe', '1');
    var list = next;

    function csrf() {
      var input = document.querySelector('input[name="_csrf"]');
      return input ? input.value : '';
    }

    function viewValue() {
      var input = document.querySelector('input[name="view"]');
      return input ? input.value : '';
    }

    function post(fields) {
      var body = new FormData();
      body.append('_csrf', csrf());
      Object.keys(fields).forEach(function (key) {
        body.append(key, fields[key]);
      });
      if (!body.has('view')) {
        body.append('view', viewValue());
      }
      return fetch(postTarget(), {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json'
        }
      }).then(function (res) {
        return res.json().catch(function () {
          return { ok: false };
        }).then(function (json) {
          return { ok: res.ok && json && json.ok !== false, status: res.status, json: json };
        });
      });
    }

    function cardFrom(target) {
      var card = target && target.closest ? target.closest('.ix-it') : null;
      if (!card || !boundList || !boundList.contains(card)) {
        return null;
      }
      return card;
    }

    function mark(card) {
      if (!boundList) {
        return;
      }
      boundList.querySelectorAll('.ix-it.is-ix-current').forEach(function (el) {
        el.classList.remove('is-ix-current');
      });
      if (card) {
        card.classList.add('is-ix-current');
      }
    }

    function selected() {
      if (!boundList) {
        return null;
      }
      return boundList.querySelector('.ix-it.is-ix-current') || boundList.querySelector('.ix-it.is-on');
    }

    function face(card) {
      return card.querySelector('.ix-face');
    }

    function setUnread(card, unread) {
      card.classList.toggle('is-unread', unread);
      card.setAttribute('data-ix-unread', unread ? '1' : '0');
      var dot = card.querySelector('.ix-dot');
      if (dot) {
        dot.setAttribute('aria-label', unread ? 'Oläst' : 'Läst');
      }
      var label = unread ? 'Läst' : 'Oläst';
      var under = card.querySelector('.ix-under-read');
      if (under) {
        under.textContent = label;
      }
      var btn = card.querySelector('[data-ix-act="read"]');
      if (btn) {
        btn.textContent = label;
      }
    }

    function snap(card) {
      var el = face(card);
      if (!el) {
        return;
      }
      el.classList.remove('is-swiping');
      el.classList.add('is-snap');
      el.style.transform = '';
      card.classList.remove('is-swipe-left', 'is-swipe-right');
      window.setTimeout(function () {
        el.classList.remove('is-snap');
      }, 180);
    }

    function hideToast() {
      if (toastTimer) {
        window.clearTimeout(toastTimer);
        toastTimer = 0;
      }
      if (toastEl && toastEl.parentNode) {
        toastEl.parentNode.removeChild(toastEl);
      }
      toastEl = null;
    }

    function restoreCard(card, prevStatus, holder, next) {
      card.setAttribute('data-ix-status', prevStatus || 'open');
      card.setAttribute('data-ix-close', '1');
      if (holder) {
        if (next && next.parentNode === holder) {
          holder.insertBefore(card, next);
        } else {
          holder.appendChild(card);
        }
      }
      snap(card);
    }

    function showToast(card, prevStatus, onUndo) {
      hideToast();
      var holder = card.parentNode;
      var next = card.nextSibling;
      card.remove();
      toastEl = document.createElement('div');
      toastEl.className = 'ix-toast';
      toastEl.setAttribute('role', 'status');
      toastEl.appendChild(document.createTextNode('Stängt · '));
      var undo = document.createElement('button');
      undo.type = 'button';
      undo.textContent = 'Ångra';
      toastEl.appendChild(undo);
      document.body.appendChild(toastEl);
      var used = false;
      undo.addEventListener('click', function () {
        if (used) {
          return;
        }
        used = true;
        hideToast();
        restoreCard(card, prevStatus, holder, next);
        if (onUndo) {
          onUndo(holder, next);
        }
      });
      toastTimer = window.setTimeout(function () {
        if (!used) {
          hideToast();
        }
      }, TOAST_MS);
    }

    function ixOptimisticRow(card, prevStatus, onUndo) {
      card.setAttribute('data-ix-status', 'closed');
      card.setAttribute('data-ix-close', '0');
      showToast(card, prevStatus, onUndo);
    }

    function showReadToast(wasUnread, onUndo) {
      hideToast();
      toastEl = document.createElement('div');
      toastEl.className = 'ix-toast';
      toastEl.setAttribute('role', 'status');
      toastEl.appendChild(document.createTextNode(wasUnread ? 'Läst · ' : 'Oläst · '));
      var undo = document.createElement('button');
      undo.type = 'button';
      undo.textContent = 'Ångra';
      toastEl.appendChild(undo);
      document.body.appendChild(toastEl);
      var used = false;
      undo.addEventListener('click', function () {
        if (used) {
          return;
        }
        used = true;
        hideToast();
        if (onUndo) {
          onUndo();
        }
      });
      toastTimer = window.setTimeout(function () {
        if (!used) {
          hideToast();
        }
      }, TOAST_MS);
    }

    function toggleRead(card) {
      if (card.getAttribute('data-ix-busy') === '1') {
        return;
      }
      var wasUnread = card.getAttribute('data-ix-unread') === '1';
      var id = card.getAttribute('data-ix-id') || '';
      var undone = false;
      var posted = false;
      card.setAttribute('data-ix-busy', '1');
      setUnread(card, !wasUnread);
      showReadToast(wasUnread, function () {
        undone = true;
        setUnread(card, wasUnread);
        if (posted) {
          post({
            action: 'inkorg.read',
            thread: id,
            read: wasUnread ? '0' : '1'
          });
        }
      });
      post({
        action: 'inkorg.read',
        thread: id,
        read: wasUnread ? '1' : '0'
      }).then(function (res) {
        card.setAttribute('data-ix-busy', '0');
        if (!res.ok) {
          throw new Error('read');
        }
        posted = true;
        if (undone) {
          return post({
            action: 'inkorg.read',
            thread: id,
            read: wasUnread ? '0' : '1'
          });
        }
      }).catch(function () {
        card.setAttribute('data-ix-busy', '0');
        if (undone) {
          return;
        }
        hideToast();
        setUnread(card, wasUnread);
      });
    }

    function closeCard(card) {
      if (card.getAttribute('data-ix-close') !== '1' || card.getAttribute('data-ix-busy') === '1') {
        snap(card);
        return;
      }
      var prev = card.getAttribute('data-ix-status') || 'open';
      var id = card.getAttribute('data-ix-id') || '';
      var undone = false;
      var closed = false;
      card.setAttribute('data-ix-busy', '1');
      ixOptimisticRow(card, prev, function (holder) {
        undone = true;
        if (closed) {
          post({
            action: 'inkorg.thread',
            thread: id,
            status: prev
          }).then(function (res) {
            if (!res.ok && holder) {
              card.remove();
            }
          });
        }
      });
      post({
        action: 'inkorg.thread',
        thread: id,
        status: 'closed'
      }).then(function (res) {
        card.setAttribute('data-ix-busy', '0');
        if (!res.ok) {
          throw new Error('close');
        }
        closed = true;
        if (undone) {
          return post({
            action: 'inkorg.thread',
            thread: id,
            status: prev
          });
        }
      }).catch(function () {
        card.setAttribute('data-ix-busy', '0');
        if (undone) {
          return;
        }
        hideToast();
        var holder = card.parentNode || (boundList && boundList.querySelector('.ix-items')) || boundList;
        restoreCard(card, prev, holder, null);
      });
    }

    list.addEventListener('pointerover', function (event) {
      if (event.pointerType === 'touch') {
        return;
      }
      var card = cardFrom(event.target);
      if (card) {
        mark(card);
      }
    });

    list.addEventListener('click', function (event) {
      var btn = event.target.closest ? event.target.closest('[data-ix-act]') : null;
      if (!btn || !boundList || !boundList.contains(btn)) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      var card = cardFrom(btn);
      if (!card) {
        return;
      }
      mark(card);
      if (btn.getAttribute('data-ix-act') === 'close') {
        closeCard(card);
      } else {
        toggleRead(card);
      }
    });

    list.addEventListener('pointerdown', function (event) {
      if (event.pointerType !== 'touch' || !event.isPrimary) {
        return;
      }
      if (event.target.closest && event.target.closest('button, input, textarea, select')) {
        return;
      }
      var card = cardFrom(event.target);
      if (!card) {
        return;
      }
      swipe = {
        card: card,
        id: event.pointerId,
        x: event.clientX,
        y: event.clientY,
        dx: 0,
        dy: 0,
        locked: '',
        suppressClick: false,
        width: card.getBoundingClientRect().width
      };
    }, { passive: true });

    root.jeabIxSetUnread = setUnread;
    root.jeabIxPost = post;
    root.jeabIxCloseRow = function (id) {
      var cards = list.querySelectorAll('.ix-it');
      for (var i = 0; i < cards.length; i++) {
        if (cards[i].getAttribute('data-ix-id') === id) {
          closeCard(cards[i]);
          return;
        }
      }
    };
    if (docsBound) {
      return;
    }
    docsBound = true;

    document.addEventListener('keydown', function (event) {
      if (event.metaKey || event.ctrlKey || event.altKey) {
        return;
      }
      var key = (event.key || '').toLowerCase();
      if (key !== 'e' && key !== 'u') {
        return;
      }
      var target = event.target;
      if (target && target.closest && target.closest('input, textarea, select, [contenteditable="true"]')) {
        return;
      }
      var card = selected();
      if (!card) {
        return;
      }
      event.preventDefault();
      if (key === 'e') {
        closeCard(card);
      } else if (key === 'u') {
        toggleRead(card);
      }
    });

    document.addEventListener('pointermove', function (event) {
      if (!swipe || event.pointerId !== swipe.id || swipe.locked === 'scroll') {
        return;
      }
      var dx = event.clientX - swipe.x;
      var dy = event.clientY - swipe.y;
      var decision = jeabInkorgSwipeDecide(dx, dy, swipe.width, swipe.locked);
      if (swipe.locked === '' && decision.locked !== '') {
        swipe.locked = decision.locked;
        if (decision.locked === 'x' && swipe.card.setPointerCapture) {
          try {
            swipe.card.setPointerCapture(swipe.id);
          } catch (err) {}
        }
      }
      if (swipe.locked !== 'x') {
        return;
      }
      if (event.cancelable) {
        event.preventDefault();
        swipe.suppressClick = true;
      }
      swipe.dx = dx;
      swipe.dy = dy;
      var el = face(swipe.card);
      if (!el) {
        return;
      }
      el.classList.add('is-swiping');
      el.style.transform = 'translateX(' + dx + 'px)';
      swipe.card.classList.toggle('is-swipe-left', dx < 0);
      swipe.card.classList.toggle('is-swipe-right', dx > 0);
    }, { passive: false });

    function endSwipe(event, allowCommit) {
      if (!swipe || (event && event.pointerId !== swipe.id)) {
        return;
      }
      var current = swipe;
      swipe = null;
      var release = allowCommit
        ? jeabInkorgSwipeRelease(current.dx, current.dy || 0, current.width)
        : { open: false, commit: '' };
      if (!release.open) {
        current.card._ixBlockClick = Date.now();
      }
      if (release.commit === 'close') {
        closeCard(current.card);
        return;
      }
      if (release.commit === 'read') {
        snap(current.card);
        toggleRead(current.card);
        return;
      }
      if (current.locked === 'x') {
        snap(current.card);
      }
      if (release.open && current.suppressClick) {
        var link = current.card.querySelector('a.item-main');
        if (link) {
          link.click();
        }
      }
    }

    document.addEventListener('pointerup', function (event) {
      endSwipe(event, true);
    }, true);
    document.addEventListener('pointercancel', function (event) {
      endSwipe(event, false);
    }, true);
    document.addEventListener('click', function (event) {
      var card = cardFrom(event.target);
      if (card && card._ixBlockClick && Date.now() - card._ixBlockClick < 500) {
        event.preventDefault();
        event.stopPropagation();
      }
    }, true);
  }

  root.jeabInkorgSwipeDecide = jeabInkorgSwipeDecide;
  root.jeabInkorgSwipeRelease = jeabInkorgSwipeRelease;
  root.jeabInkorgSwipeBind = boot;
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { jeabInkorgSwipeDecide: jeabInkorgSwipeDecide, jeabInkorgSwipeRelease: jeabInkorgSwipeRelease };
  }
  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', boot);
    } else {
      boot();
    }
  }
})(typeof window !== 'undefined' ? window : globalThis);
