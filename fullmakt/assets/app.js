(function () {
  function fullmaktToken() {
    var path = String(window.location.pathname || '');
    var fromPath = path.match(/\/fullmakt\/s\/([A-Za-z0-9_-]+)/);
    if (fromPath) return fromPath[1];
    var fromQuery = new URLSearchParams(window.location.search).get('token') || '';
    return /^[A-Za-z0-9_-]+$/.test(fromQuery) ? fromQuery : '';
  }

  function watchFullmaktView() {
    var token = fullmaktToken();
    if (!token) return;
    var left = 5000;
    var started = 0;
    var timer = 0;
    var sent = false;
    function send() {
      if (sent) return;
      sent = true;
      timer = 0;
      var body = new URLSearchParams();
      body.set('token', token);
      fetch('/fullmakt/viewed.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
        credentials: 'same-origin',
        keepalive: true
      }).catch(function () {});
    }
    function tick() {
      if (sent || document.visibilityState === 'hidden') return;
      if (left <= 0) {
        send();
        return;
      }
      started = Date.now();
      timer = window.setTimeout(send, left);
    }
    function pause() {
      if (!timer) return;
      window.clearTimeout(timer);
      timer = 0;
      left -= Date.now() - started;
    }
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') pause();
      else tick();
    });
    tick();
  }

  watchFullmaktView();

  var form = document.getElementById('sign-form');
  if (!form) return;

  var canvas = document.getElementById('pad');
  var typed = document.getElementById('skriv-namn');
  var hidden = document.getElementById('underskrift');
  var clearBtn = document.getElementById('rensa');
  var submitBtn = document.getElementById('skicka');
  if (!canvas || !typed || !hidden || !clearBtn || !submitBtn) return;

  var aid = document.getElementById('anlaggnings_id');
  var boxes = document.getElementById('aid-boxes');

  function onlyDigits(value) {
    return String(value || '').replace(/\s+/g, '').replace(/\D+/g, '').slice(0, 18);
  }

  if (aid && boxes) {
    var cells = [];
    for (var g = 0; g < 6; g++) {
      var group = document.createElement('div');
      group.className = 'aid-group';
      for (var i = 0; i < 3; i++) {
        var cell = document.createElement('div');
        cell.className = 'aid-box';
        group.appendChild(cell);
        cells.push(cell);
      }
      boxes.appendChild(group);
    }
    function renderAid() {
      var v = onlyDigits(aid.value);
      if (aid.value !== v) aid.value = v;
      for (var n = 0; n < 18; n++) {
        cells[n].textContent = v[n] || '';
        cells[n].classList.toggle('is-focus', document.activeElement === aid && n === v.length);
      }
    }
    aid.addEventListener('input', renderAid);
    aid.addEventListener('focus', renderAid);
    aid.addEventListener('blur', function () {
      cells.forEach(function (c) { c.classList.remove('is-focus'); });
    });
    renderAid();
  }

  var ctx;
  var drawing = false;
  var dirtyDraw = false;
  var cssW = 0;
  var cssH = 0;
  var last = null;
  var typedGen = 0;
  var SIGN_FONT = 'Great Vibes';
  var SIGN_SIZE = 52;

  function signFontUrl() {
    var nodes = document.getElementsByTagName('script');
    for (var i = 0; i < nodes.length; i++) {
      var src = nodes[i].src || '';
      if (/\/assets\/app\.js(?:\?|$)/.test(src)) {
        return src.replace(/app\.js(\?.*)?$/, 'fonts/GreatVibes-Regular.woff2');
      }
    }
    return 'assets/fonts/GreatVibes-Regular.woff2';
  }

  function signFontSpec() {
    return SIGN_SIZE + 'px "' + SIGN_FONT + '", cursive';
  }

  var signFontReady = (function () {
    if (!window.FontFace || !document.fonts) return Promise.resolve();
    try {
      var face = new FontFace(SIGN_FONT, 'url(' + signFontUrl() + ')');
      return face.load().then(function (loaded) {
        document.fonts.add(loaded);
      }).catch(function () {
        return document.fonts.load(signFontSpec());
      });
    } catch (err) {
      return document.fonts.load ? document.fonts.load(signFontSpec()) : Promise.resolve();
    }
  })();

  function signFontLoaded() {
    return !!(document.fonts && document.fonts.check && document.fonts.check(signFontSpec()));
  }

  function setupCanvas() {
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    cssW = rect.width || canvas.clientWidth || 640;
    cssH = rect.height || canvas.clientHeight || 180;
    if (cssW < 2) cssW = 640;
    if (cssH < 2) cssH = 180;
    canvas.width = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111111';
    ctx.lineWidth = 2.2;
  }

  function drawCursive(name) {
    var maxW = Math.max(40, cssW - 28);
    var maxH = Math.max(24, cssH - 16);
    var size = SIGN_SIZE;
    ctx.fillStyle = '#111111';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (var n = 0; n < 8; n++) {
      ctx.font = size + 'px "' + SIGN_FONT + '", cursive';
      var metrics = ctx.measureText(name);
      var w = metrics.width;
      var h = (metrics.actualBoundingBoxAscent || size * 0.8) + (metrics.actualBoundingBoxDescent || size * 0.35);
      if ((w <= maxW && h <= maxH) || size <= 22) break;
      var scale = Math.min(maxW / Math.max(w, 1), maxH / Math.max(h, 1));
      size = Math.max(22, Math.floor(size * scale));
    }
    ctx.fillText(name, cssW / 2, cssH / 2 + 4);
  }

  function paintTyped() {
    var name = typed.value.trim();
    var gen = ++typedGen;
    setupCanvas();
    dirtyDraw = false;
    if (!name) return;
    function draw() {
      if (gen !== typedGen || dirtyDraw || drawing) return;
      if (typed.value.trim() !== name) return;
      drawCursive(name);
    }
    if (signFontLoaded()) {
      draw();
      return;
    }
    signFontReady.then(draw);
  }

  function pos(ev) {
    var rect = canvas.getBoundingClientRect();
    var t = ev.touches ? ev.touches[0] : ev;
    return { x: t.clientX - rect.left, y: t.clientY - rect.top };
  }

  function start(ev) {
    ev.preventDefault();
    drawing = true;
    typedGen += 1;
    last = pos(ev);
  }

  function move(ev) {
    if (!drawing) return;
    ev.preventDefault();
    var p = pos(ev);
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    last = p;
    dirtyDraw = true;
  }

  function end(ev) {
    if (!drawing) return;
    ev.preventDefault();
    drawing = false;
    last = null;
  }

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  window.addEventListener('mouseup', end);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move, { passive: false });
  canvas.addEventListener('touchend', end, { passive: false });

  typed.addEventListener('input', function () {
    paintTyped();
  });

  clearBtn.addEventListener('click', function () {
    typed.value = '';
    dirtyDraw = false;
    setupCanvas();
  });

  function hasInk() {
    if (dirtyDraw || typed.value.trim()) return true;
    try {
      var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
      for (var i = 0; i < data.length; i += 16) {
        if (data[i + 3] > 20 && (data[i] < 245 || data[i + 1] < 245 || data[i + 2] < 245)) return true;
      }
    } catch (err) {
      return dirtyDraw || typed.value.trim().length > 0;
    }
    return false;
  }

  var aidError = document.getElementById('aid-error');
  var aidWrap = aid ? aid.closest('.aid-wrap') : null;

  function showAidError(message) {
    if (aidError) {
      aidError.hidden = false;
      aidError.textContent = message;
    }
    if (aid) aid.setAttribute('aria-invalid', 'true');
    if (aidWrap) aidWrap.classList.add('is-invalid');
  }

  function clearAidError() {
    if (aidError) {
      aidError.hidden = true;
      aidError.textContent = '';
    }
    if (aid) aid.setAttribute('aria-invalid', 'false');
    if (aidWrap) aidWrap.classList.remove('is-invalid');
  }

  if (aid) {
    aid.addEventListener('input', function () {
      if (onlyDigits(aid.value).length === 18) clearAidError();
    });
  }

  form.addEventListener('submit', function (ev) {
    if (aid) {
      var id = onlyDigits(aid.value);
      aid.value = id;
      if (id.length !== 18) {
        ev.preventDefault();
        showAidError(id.length === 0
          ? 'Fyll i anläggnings-id innan du signerar. 18 siffror, står vid elmätaren och på elfakturan.'
          : 'Ange anläggnings-id med exakt 18 siffror. Inga bokstäver.');
        if (aidError && aidError.scrollIntoView) aidError.scrollIntoView({ block: 'nearest' });
        aid.focus();
        return;
      }
      clearAidError();
    }
    if (!hasInk()) {
      ev.preventDefault();
      typed.focus();
      return;
    }
    hidden.value = canvas.toDataURL('image/png');
    submitBtn.disabled = true;
  });

  setupCanvas();
  window.addEventListener('resize', function () {
    var prev = canvas.toDataURL('image/png');
    var had = dirtyDraw || typed.value.trim();
    setupCanvas();
    if (had) {
      var img = new Image();
      img.onload = function () {
        ctx.drawImage(img, 0, 0, cssW, cssH);
      };
      img.src = prev;
    }
  });
})();
