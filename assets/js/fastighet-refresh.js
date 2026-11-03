(function () {
  if (window.__jeabFastighetRefresh) {
    return;
  }
  window.__jeabFastighetRefresh = true;

  function csrf() {
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
  }

  function dialog() {
    return document.querySelector('[data-fastighet-dialog]');
  }

  function q(sel) {
    var root = dialog();
    return root ? root.querySelector(sel) : null;
  }

  function clear(node) {
    while (node && node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function ownerText(list) {
    if (!list || !list.length) {
      return '–';
    }
    return list.map(function (owner) {
      var name = owner && owner.name ? String(owner.name) : '–';
      return owner && owner.andel ? name + ' (' + owner.andel + ')' : name;
    }).join(', ');
  }

  function line(label, oldText, newText) {
    var p = document.createElement('p');
    p.className = 'fastighet-refresh-line';
    var b = document.createElement('b');
    b.textContent = label;
    p.appendChild(b);
    p.appendChild(document.createTextNode(' ' + (oldText || '–') + ' → ' + (newText || '–')));
    return p;
  }

  function showErr(message) {
    var err = q('[data-fastighet-err]');
    if (!err) {
      return;
    }
    err.hidden = false;
    err.textContent = message || 'Uppslaget misslyckades.';
  }

  function paint(data) {
    var box = q('[data-fastighet-body]');
    var save = q('[data-fastighet-save]');
    var err = q('[data-fastighet-err]');
    if (!box) {
      return;
    }
    clear(box);
    if (err) {
      err.hidden = true;
      err.textContent = '';
    }
    var diff = data && data.diff ? data.diff : {};
    var fast = diff.fastighetsbeteckning;
    var owners = diff.owners;
    var missing = data && data.missing && data.missing.length ? data.missing : [];
    if (!fast && !owners) {
      var empty = document.createElement('p');
      if (missing.length) {
        empty.textContent = 'Saknas: ' + missing.join(', ') + '.';
      } else {
        empty.textContent = 'Ingen skillnad mot det som redan står.';
      }
      box.appendChild(empty);
      if (save) {
        save.hidden = true;
      }
      return;
    }
    if (save) {
      save.hidden = false;
      save.disabled = false;
    }
    if (fast) {
      box.appendChild(line('Fastighetsbeteckning', fast.old, fast.new));
    }
    if (owners) {
      box.appendChild(line('Ägare', ownerText(owners.old), ownerText(owners.new)));
    }
  }

  function post(orderId, dry) {
    var body = new FormData();
    body.set('action', 'order.fastighet_refresh');
    body.set('order_id', String(orderId));
    body.set('id', String(orderId));
    body.set('dry', dry ? '1' : '0');
    body.set('_csrf', csrf());
    return fetch(location.pathname + location.search, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
        'X-CSRF-Token': csrf()
      },
      body: body
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok || !data || data.ok === false) {
          throw new Error((data && data.error) || 'Uppslaget misslyckades.');
        }
        return data;
      });
    });
  }

  var pending = 0;

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }
    var openBtn = target.closest('[data-fastighet-refresh]');
    if (openBtn) {
      event.preventDefault();
      event.stopPropagation();
      pending = parseInt(openBtn.getAttribute('data-order-id') || '0', 10) || 0;
      var root = dialog();
      if (!root || !pending) {
        return;
      }
      var box = q('[data-fastighet-body]');
      clear(box);
      if (box) {
        var wait = document.createElement('p');
        wait.textContent = 'Hämtar…';
        box.appendChild(wait);
      }
      var save = q('[data-fastighet-save]');
      if (save) {
        save.hidden = true;
        save.disabled = false;
      }
      var err = q('[data-fastighet-err]');
      if (err) {
        err.hidden = true;
        err.textContent = '';
      }
      root.hidden = false;
      post(pending, true).then(paint).catch(function (err) {
        clear(box);
        showErr(err && err.message ? err.message : '');
      });
      return;
    }
    if (target.closest('[data-fastighet-cancel]') || target === dialog()) {
      var open = dialog();
      if (open) {
        open.hidden = true;
      }
      return;
    }
    if (target.closest('[data-fastighet-save]')) {
      if (!pending) {
        return;
      }
      var btn = q('[data-fastighet-save]');
      if (btn) {
        btn.disabled = true;
      }
      post(pending, false).then(function () {
        location.reload();
      }).catch(function (err) {
        if (btn) {
          btn.disabled = false;
        }
        showErr(err && err.message ? err.message : '');
      });
    }
  });
})();
