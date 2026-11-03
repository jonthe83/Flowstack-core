<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

/** @var list<array{ort:string,pending:int}> $solkampanjTowns */
/** @var string $solkampanjOrt */
/** @var array<string, mixed>|null $solkampanjRow */
/** @var array{sent:int,total:int,skipped:int,pending:int} $solkampanjProgress */
/** @var bool $solkampanjTest */
/** @var bool $solkampanjPdf */
/** @var string $solkampanjFlash */
/** @var string $solkampanjError */

$solkampanjTowns = $solkampanjTowns ?? [];
$solkampanjOrt = $solkampanjOrt ?? '';
$solkampanjRow = $solkampanjRow ?? null;
$solkampanjProgress = $solkampanjProgress ?? ['sent' => 0, 'total' => 0, 'skipped' => 0, 'pending' => 0];
$solkampanjTest = $solkampanjTest ?? true;
$solkampanjPdf = $solkampanjPdf ?? false;
$solkampanjFlash = $solkampanjFlash ?? '';
$solkampanjError = $solkampanjError ?? '';
$solkampanjAdmin = function_exists('jeab_is_admin') && jeab_is_admin();
$csrf = jeab_csrf_token();
$backUrl = jeab_url('index.php?p=links');
$boot = json_encode([
    'row' => $solkampanjRow,
    'ort' => $solkampanjOrt,
    'copy' => jeab_solkampanj_copy(),
    'signature' => jeab_solkampanj_signature_html(),
    'testMode' => $solkampanjTest,
    'testTo' => JEAB_SOLKAMPANJ_TEST_TO,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf" content="<?= e($csrf) ?>">
  <meta name="theme-color" content="#1f4a32">
  <title>Solkampanj · JEAB</title>
  <style>
    :root {
      --bg:#f3efe6; --ink:#142018; --soft:#3d4a40; --muted:#6b746c;
      --panel:#fffdf8; --line:#d7d0c2; --green:#1f4a32; --green2:#2d6a48;
      --yellow:#e6b423; --danger:#8b2e2e;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{
      font-family:"Segoe UI","Helvetica Neue",Arial,sans-serif;
      color:var(--ink);
      background:
        radial-gradient(900px 420px at 10% -10%,rgba(230,180,35,.18),transparent 55%),
        linear-gradient(180deg,var(--bg),#e8e2d4);
      line-height:1.45;
      min-height:100vh;
    }
    .wrap{width:min(640px,calc(100% - 24px));margin:20px auto 48px}
    .back{display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;color:var(--green);font-weight:700;font-size:.9rem;text-decoration:none}
    header{display:flex;align-items:center;gap:14px;margin-bottom:14px}
    .mark{width:52px;height:52px;border-radius:14px;background:linear-gradient(160deg,var(--green2),var(--green));display:grid;place-items:center;flex-shrink:0;color:#fff;font-weight:800}
    .brand h1{font-size:1.05rem}
    .brand p{color:var(--muted);font-size:.82rem}
    .progress-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}
    .progress{font-weight:750;margin:0}
    .ort-line{display:flex;align-items:center;gap:6px;margin:0}
    .ort-line label{margin:0;color:var(--muted);font-size:.78rem;font-weight:650}
    .ort-line select{width:auto;max-width:14rem;padding:6px 8px;border-radius:10px;font-size:.9rem;background:#fff}
    .banner{border-radius:14px;padding:12px 14px;margin-bottom:12px;font-size:.92rem}
    .banner.test{background:#fff7d6;border:1px solid #efd27a;color:#6a4d00}
    .banner.live{background:#e7f3ea;border:1px solid #b7d7c2;color:#1f4a32}
    .banner.err{background:#fdecec;border:1px solid #e4b4b4;color:var(--danger)}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:18px;box-shadow:0 18px 50px rgba(20,32,24,.12);overflow:hidden}
    .hero{padding:18px 18px 8px}
    .who{font-size:1.35rem;line-height:1.2}
    .meta{color:var(--soft);margin-top:6px;word-break:break-word}
    label{display:block;font-size:.82rem;font-weight:700;margin:14px 0 6px}
    .pad{padding:0 18px 18px}
    input[type=text],input[type=number],input[type=file],select{
      width:100%;border:1.5px solid #cfc7b6;background:#fff;color:var(--ink);
      border-radius:12px;padding:13px 14px;font-size:16px;outline:none;font-family:inherit;
    }
    input:focus{border-color:var(--green2);box-shadow:0 0 0 4px rgba(45,106,72,.16)}
    .paste{
      margin-top:14px;border:1.5px dashed #b7ad98;border-radius:14px;background:#f6f3ea;
      min-height:120px;display:grid;place-items:center;text-align:center;padding:16px;color:var(--soft);
    }
    .paste.has{border-style:solid;border-color:var(--green2)}
    .paste img{max-width:100%;height:auto;border-radius:10px}
    .paste-zone{
      margin-top:8px;min-height:88px;cursor:text;border-width:2px;border-color:var(--green2);
      background:#fff;color:var(--green);font-weight:750;
    }
    .paste:focus{outline:none;box-shadow:0 0 0 4px rgba(45,106,72,.16)}
    .paste-tools{margin-top:8px}
    #pick-image-btn{
      width:auto;background:#fff;color:var(--green);border:1.5px solid var(--green);
      padding:10px 16px;min-height:44px;font-size:.95rem;
    }
    input.pick-image[type=file]{
      position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
      clip:rect(0,0,0,0);border:0;
    }
    .actions{display:grid;grid-template-columns:1fr;gap:10px;margin-top:16px}
    button{
      border:0;cursor:pointer;font-family:inherit;font-weight:750;font-size:1.05rem;
      padding:16px 18px;border-radius:14px;min-height:48px;
    }
    button:disabled{opacity:.45;cursor:not-allowed}
    #send{background:linear-gradient(180deg,var(--green2),var(--green));color:#fff}
    #send-battery{background:#fff;color:var(--green);border:1.5px solid var(--green)}
    #skip{background:#fff;color:var(--ink);border:1px solid var(--line)}
    .status{min-height:1.2em;font-size:.86rem;color:var(--soft);margin-top:10px}
    .status.err{color:var(--danger)}
    .preview{margin-top:16px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px;overflow:auto}
    .preview h3{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:8px}
    .preview .subject{font-weight:750;margin-bottom:10px}
    .preview p{margin:0 0 10px}
    .preview img{max-width:100%;height:auto}
    .addr{margin-top:12px;color:var(--soft)}
    details{margin-top:18px;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:12px 14px}
    summary{cursor:pointer;font-weight:750}
    .hint{color:var(--muted);font-size:.82rem;margin-top:6px}
    .empty{padding:22px 18px}
    .role-nav{display:flex;gap:8px;margin-bottom:14px}
    .role-nav a{
      flex:1;text-align:center;text-decoration:none;font-weight:750;color:var(--green);
      background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 10px;min-height:44px;
    }
    .role-nav a[aria-current="page"]{background:var(--green);color:#fff;border-color:var(--green)}
    @media (min-width:640px){
      .actions{grid-template-columns:1fr 1fr}
    }
    @media (min-width:840px){
      .actions{grid-template-columns:1.1fr 1.1fr .7fr}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <?php if ($solkampanjAdmin): ?>
      <a class="back" href="<?= e($backUrl) ?>">‹ Övrigt</a>
    <?php else: ?>
      <nav class="role-nav" aria-label="Sidor">
        <a href="<?= e(jeab_url('solkampanj.php')) ?>" aria-current="page">Solkampanj</a>
        <?php if (!(function_exists('jeab_demo_feature_off') && jeab_demo_feature_off('kalkyl'))): ?>
        <a href="<?= e(jeab_url('kalkyl.php')) ?>">Kalkyl</a>
        <?php endif; ?>
      </nav>
      <form method="post" action="<?= e(jeab_url('index.php')) ?>">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button type="submit" id="logout" style="margin-bottom:14px;background:#fff;color:var(--ink);border:1px solid var(--line);padding:10px 14px;min-height:44px;font-size:.9rem">Logga ut</button>
      </form>
    <?php endif; ?>
    <header>
      <div class="mark" aria-hidden="true">S</div>
      <div class="brand">
        <h1>Solkampanj</h1>
        <p>Ett mejl i taget, från Demo Säljare</p>
      </div>
    </header>
    <div class="progress-row">
      <p class="progress" id="progress"><?= (int) $solkampanjProgress['sent'] ?> av <?= (int) $solkampanjProgress['sent'] + (int) $solkampanjProgress['pending'] ?> skickade</p>
      <form id="ort-form" class="ort-line" method="post" action="<?= e(jeab_url('solkampanj.php')) ?>">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="solkampanj.ort">
        <label for="ort">Ort</label>
        <select id="ort" name="ort" aria-label="Ort">
          <?php foreach ($solkampanjTowns as $town): ?>
            <option value="<?= e((string) $town['ort']) ?>"<?= (string) $town['ort'] === $solkampanjOrt ? ' selected' : '' ?>><?= e((string) $town['ort']) ?> (<?= (int) $town['pending'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <div class="banner <?= $solkampanjTest ? 'test' : 'live' ?>" id="mode">
      <?= $solkampanjTest
          ? 'Testläge är på. Skicka går till ' . e(JEAB_SOLKAMPANJ_TEST_TO) . '.'
          : 'Testläge är av. Skicka går till kunden.' ?>
    </div>
    <?php if ($solkampanjError !== ''): ?>
      <div class="banner err"><?= e($solkampanjError) ?></div>
    <?php elseif ($solkampanjFlash !== ''): ?>
      <div class="banner live"><?= e($solkampanjFlash) ?></div>
    <?php endif; ?>

    <section class="card" id="card">
      <div class="hero" id="who-block">
        <?php if ($solkampanjRow === null): ?>
          <h2 class="who">Inga väntande mottagare</h2>
          <?php if ($solkampanjTowns === [] && $solkampanjAdmin): ?>
            <p class="meta">Mottagarlistan importeras med hooken solkampanj.import.</p>
          <?php elseif ($solkampanjOrt === '' && $solkampanjTowns !== []): ?>
            <p class="meta">Välj ort.</p>
          <?php elseif ($solkampanjOrt !== ''): ?>
            <p class="meta">Inga väntande i <?= e($solkampanjOrt) ?>.</p>
          <?php endif; ?>
        <?php else: ?>
          <h2 class="who" id="who-name"><?= e((string) $solkampanjRow['namn']) ?></h2>
          <p class="meta" id="who-mail"><?= e((string) $solkampanjRow['mejl']) ?></p>
          <p class="meta" id="who-addr"><?= e(trim((string) $solkampanjRow['gata'] . ', ' . (string) $solkampanjRow['postnr'] . ' ' . (string) $solkampanjRow['ort'])) ?></p>
        <?php endif; ?>
      </div>
      <div class="pad" id="work" <?= $solkampanjRow === null ? 'hidden' : '' ?>>
        <div class="paste" id="paste" tabindex="0">Klistra in en bild (Ctrl+V eller Cmd+V).</div>
        <div class="paste paste-zone" id="paste-zone" tabindex="0" contenteditable="true" role="textbox" spellcheck="false" aria-label="Klistra in skärmdump här (Ctrl+V)">Klistra in skärmdump här (Ctrl+V)</div>
        <div class="paste-tools">
          <button type="button" id="pick-image-btn">Välj bild</button>
          <input id="pick-image" class="pick-image" type="file" accept="image/*" tabindex="-1" aria-label="Välj bild">
        </div>
        <label for="antal">Antal paneler</label>
        <input id="antal" name="antal" inputmode="numeric" autocomplete="off" enterkeyhint="next">
        <label for="kwh">kWh i maj eller juni</label>
        <input id="kwh" name="kwh" inputmode="decimal" autocomplete="off" enterkeyhint="next">
        <label for="pris">Pris med 15 kWh batteri (kr)</label>
        <input id="pris" name="pris" inputmode="numeric" autocomplete="off" enterkeyhint="done">
        <div class="preview" id="preview" aria-live="polite">
          <h3>Förhandsvisning</h3>
          <div class="subject" id="pv-subject"></div>
          <div id="pv-body"></div>
        </div>
        <div class="actions">
          <button type="button" id="send" disabled>Skicka</button>
          <button type="button" id="send-battery"<?= $solkampanjRow === null ? ' disabled' : '' ?>>Skicka batterioffert</button>
          <button type="button" id="skip">Hoppa över</button>
        </div>
        <p class="status" id="status"></p>
      </div>
    </section>

    <?php if ($solkampanjAdmin): ?>
    <details <?= $solkampanjRow === null ? 'open' : '' ?>>
      <summary>Inställningar</summary>
      <form method="post" action="<?= e(jeab_url('solkampanj.php')) ?>" enctype="multipart/form-data">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="solkampanj.settings">
        <input type="hidden" name="test_mode" value="0">
        <label><input type="checkbox" name="test_mode" value="1" <?= $solkampanjTest ? 'checked' : '' ?>> Testläge (<?= e(JEAB_SOLKAMPANJ_TEST_TO) ?>)</label>
        <p class="hint">På tills du stänger av det. Varje Skicka är ett eget klick. Inget köas.</p>
        <p class="hint">Mottagarlistan importeras med hooken solkampanj.import. Den kan köras igen utan att skickade mejl blir väntande.</p>
        <label for="pdf">soloofferten.pdf</label>
        <input id="pdf" type="file" name="pdf" accept="application/pdf">
        <p class="hint"><?= $solkampanjPdf ? 'En PDF är uppladdad och bifogas.' : 'Ingen PDF. Mejlet går utan bilaga.' ?></p>
        <div class="actions">
          <button type="submit" id="save">Spara inställningar</button>
        </div>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    var boot = <?= $boot !== false ? $boot : '{}' ?>;
    var row = boot.row || null;
    var ortName = boot.ort || '';
    var copy = boot.copy || {};
    var signature = boot.signature || '';
    var imageUrl = '';
    var busy = false;
    var pasteHint = 'Klistra in en bild (Ctrl+V eller Cmd+V).';
    var zoneHint = 'Klistra in skärmdump här (Ctrl+V)';
    var paste = document.getElementById('paste');
    var pasteZone = document.getElementById('paste-zone');
    var pickImage = document.getElementById('pick-image');
    var sendBtn = document.getElementById('send');
    var batteryBtn = document.getElementById('send-battery');
    var skipBtn = document.getElementById('skip');
    var status = document.getElementById('status');
    var csrf = document.querySelector('meta[name="csrf"]').getAttribute('content');

    function text(id, value) {
      var el = document.getElementById(id);
      if (el) el.textContent = value;
    }
    function setStatus(message, err) {
      status.textContent = message || '';
      status.className = err ? 'status err' : 'status';
    }
    function fmtInt(raw) {
      var n = String(raw || '').replace(/\s/g, '').replace(',', '.');
      if (!/^\d+$/.test(n)) return '';
      return n.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
    function fmtKwh(raw) {
      var n = String(raw || '').replace(/\s/g, '').replace(',', '.');
      if (!/^\d+(\.\d+)?$/.test(n)) return '';
      if (n.indexOf('.') === -1) return fmtInt(n);
      var parts = n.split('.');
      return fmtInt(parts[0]) + ',' + parts[1].slice(0, 1);
    }
    function ready() {
      return !!(row && imageUrl && fmtInt(document.getElementById('antal').value) && fmtKwh(document.getElementById('kwh').value) && fmtInt(document.getElementById('pris').value));
    }
    function paint() {
      var body = document.getElementById('pv-body');
      var subject = document.getElementById('pv-subject');
      body.textContent = '';
      if (!row) {
        subject.textContent = '';
        sendBtn.disabled = true;
        batteryBtn.disabled = true;
        return;
      }
      var antal = fmtInt(document.getElementById('antal').value) || '…';
      var kwh = fmtKwh(document.getElementById('kwh').value) || '…';
      var pris = fmtInt(document.getElementById('pris').value) || '…';
      var gata = (row.gata || '').trim();
      subject.textContent = 'Ämne: ' + String(copy.subject || '').replace('{gata}', gata || 'er');
      var hello = row.first_name ? String(copy.hello || '').replace('{first}', row.first_name) : 'Hej,';
      var offer = String(copy.offer || '').replace('{antal}', antal).replace('{kwh}', kwh).replace('{pris}', pris);
      [hello, copy.intro || '', offer].forEach(function (para) {
        var p = document.createElement('p');
        p.textContent = para;
        body.appendChild(p);
      });
      if (imageUrl) {
        var img = document.createElement('img');
        img.alt = 'Solceller';
        img.src = imageUrl;
        body.appendChild(img);
      }
      var sig = document.createElement('div');
      sig.innerHTML = signature;
      body.appendChild(sig);
      var addr = document.createElement('p');
      addr.className = 'addr';
      addr.textContent = (row.gata || '') + ', ' + (row.postnr || '') + ' ' + (row.ort || '');
      body.appendChild(addr);
      sendBtn.disabled = busy || !ready();
      batteryBtn.disabled = busy || !row;
    }
    function paintTowns(towns, selected) {
      var sel = document.getElementById('ort');
      if (!sel || !towns) return;
      var current = selected || sel.value || 'Järfälla';
      sel.textContent = '';
      towns.forEach(function (town) {
        var opt = document.createElement('option');
        opt.value = town.ort;
        opt.textContent = town.ort + ' (' + town.pending + ')';
        if (town.ort === current) opt.selected = true;
        sel.appendChild(opt);
      });
    }
    function showRow(next, progress, towns, ort) {
      row = next;
      if (ort !== undefined && ort !== null) ortName = ort;
      if (towns) paintTowns(towns, ortName);
      if (progress) {
        text('progress', (progress.sent || 0) + ' av ' + ((progress.sent || 0) + (progress.pending || 0)) + ' skickade');
      }
      imageUrl = '';
      paste.className = 'paste';
      paste.textContent = pasteHint;
      if (pasteZone) {
        pasteZone.className = 'paste paste-zone';
        pasteZone.textContent = zoneHint;
      }
      if (pickImage) pickImage.value = '';
      ['antal', 'kwh', 'pris'].forEach(function (id) { document.getElementById(id).value = ''; });
      var block = document.getElementById('who-block');
      var work = document.getElementById('work');
      if (!row) {
        var note = ortName ? ('Inga väntande i ' + ortName + '.') : 'Välj ort.';
        block.textContent = '';
        var emptyTitle = document.createElement('h2');
        emptyTitle.className = 'who';
        emptyTitle.textContent = 'Inga väntande mottagare';
        var emptyMeta = document.createElement('p');
        emptyMeta.className = 'meta';
        emptyMeta.textContent = note;
        block.appendChild(emptyTitle);
        block.appendChild(emptyMeta);
        work.hidden = true;
        paint();
        return;
      }
      work.hidden = false;
      block.innerHTML = '';
      var h = document.createElement('h2');
      h.className = 'who';
      h.id = 'who-name';
      h.textContent = row.namn || '';
      var mail = document.createElement('p');
      mail.className = 'meta';
      mail.id = 'who-mail';
      mail.textContent = row.mejl || '';
      var addr = document.createElement('p');
      addr.className = 'meta';
      addr.id = 'who-addr';
      addr.textContent = (row.gata || '') + ', ' + (row.postnr || '') + ' ' + (row.ort || '');
      block.appendChild(h);
      block.appendChild(mail);
      block.appendChild(addr);
      paint();
    }
    function takeImage(file) {
      if (!file || String(file.type || '').indexOf('image/') !== 0) return;
      if (file.size > 6000000) {
        setStatus('Bilden är för stor.', true);
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        imageUrl = String(reader.result || '');
        paste.className = 'paste has';
        paste.textContent = '';
        var img = document.createElement('img');
        img.alt = 'Inklistrad bild';
        img.src = imageUrl;
        paste.appendChild(img);
        if (pasteZone) pasteZone.textContent = zoneHint;
        paint();
      };
      reader.readAsDataURL(file);
    }
    function imageFileFromClipboard(data) {
      if (!data) return null;
      var items = data.items;
      if (items) {
        for (var i = 0; i < items.length; i++) {
          var item = items[i];
          if (!item || String(item.type || '').indexOf('image/') !== 0 || typeof item.getAsFile !== 'function') continue;
          var fromItem = item.getAsFile();
          if (fromItem) return fromItem;
        }
      }
      var files = data.files;
      if (files) {
        for (var j = 0; j < files.length; j++) {
          if (files[j] && String(files[j].type || '').indexOf('image/') === 0) return files[j];
        }
      }
      return null;
    }
    function onPaste(ev) {
      var file = imageFileFromClipboard(ev.clipboardData);
      if (!file) return;
      ev.preventDefault();
      ev.stopPropagation();
      takeImage(file);
    }
    document.addEventListener('paste', onPaste);
    if (pasteZone) pasteZone.addEventListener('paste', onPaste);
    function bindDrop(el) {
      if (!el) return;
      el.addEventListener('dragover', function (ev) {
        var types = ev.dataTransfer && ev.dataTransfer.types;
        if (!types) return;
        for (var i = 0; i < types.length; i++) {
          if (types[i] === 'Files') {
            ev.preventDefault();
            return;
          }
        }
      });
      el.addEventListener('drop', function (ev) {
        var files = ev.dataTransfer && ev.dataTransfer.files;
        if (!files) return;
        for (var i = 0; i < files.length; i++) {
          if (String(files[i].type || '').indexOf('image/') === 0) {
            ev.preventDefault();
            takeImage(files[i]);
            return;
          }
        }
      });
    }
    bindDrop(paste);
    bindDrop(pasteZone);
    if (pickImage) {
      pickImage.addEventListener('change', function () {
        var file = pickImage.files && pickImage.files[0];
        if (file) takeImage(file);
      });
      var pickBtn = document.getElementById('pick-image-btn');
      if (pickBtn) pickBtn.addEventListener('click', function () { pickImage.click(); });
    }
    ['antal', 'kwh', 'pris'].forEach(function (id) {
      document.getElementById(id).addEventListener('input', paint);
    });
    function post(action) {
      if (busy || !row) return;
      if (action === 'solkampanj.send' && !ready()) return;
      busy = true;
      sendBtn.disabled = true;
      batteryBtn.disabled = true;
      skipBtn.disabled = true;
      setStatus(action === 'solkampanj.skip' ? 'Hoppar över…' : 'Skickar…', false);
      var data = new FormData();
      data.append('_csrf', csrf);
      data.append('action', action);
      data.append('id', String(row.id));
      if (action === 'solkampanj.send') {
        data.append('antal', document.getElementById('antal').value);
        data.append('kwh', document.getElementById('kwh').value);
        data.append('pris', document.getElementById('pris').value);
        data.append('image', imageUrl);
      }
      fetch(window.location.pathname, {
        method: 'POST',
        body: data,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function (res) {
        return res.json().then(function (json) { return { res: res, json: json }; });
      }).then(function (pack) {
        var json = pack.json || {};
        if (!json.ok) {
          setStatus(json.error || 'Det gick inte.', true);
          return;
        }
        setStatus('', false);
        showRow(json.next || null, json.progress || null, json.towns || null, json.ort || '');
      }).catch(function () {
        setStatus('Kunde inte nå servern. Ladda om innan du försöker igen.', true);
      }).then(function () {
        busy = false;
        skipBtn.disabled = false;
        sendBtn.disabled = !ready();
        batteryBtn.disabled = !row;
      });
    }
    function postOrt() {
      if (busy) return;
      var sel = document.getElementById('ort');
      busy = true;
      sendBtn.disabled = true;
      batteryBtn.disabled = true;
      skipBtn.disabled = true;
      setStatus('', false);
      var data = new FormData();
      data.append('_csrf', csrf);
      data.append('action', 'solkampanj.ort');
      data.append('ort', sel ? sel.value : '');
      fetch(window.location.pathname, {
        method: 'POST',
        body: data,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function (res) {
        return res.json().then(function (json) { return { res: res, json: json }; });
      }).then(function (pack) {
        var json = pack.json || {};
        if (!json.ok) {
          setStatus(json.error || 'Det gick inte.', true);
          return;
        }
        setStatus('', false);
        showRow(json.next || null, json.progress || null, json.towns || null, json.ort || '');
      }).catch(function () {
        setStatus('Kunde inte nå servern. Ladda om innan du försöker igen.', true);
      }).then(function () {
        busy = false;
        skipBtn.disabled = false;
        sendBtn.disabled = !ready();
        batteryBtn.disabled = !row;
      });
    }
    sendBtn.addEventListener('click', function () { post('solkampanj.send'); });
    batteryBtn.addEventListener('click', function () { post('solkampanj.send_battery'); });
    skipBtn.addEventListener('click', function () { post('solkampanj.skip'); });
    var ortForm = document.getElementById('ort-form');
    if (ortForm) {
      ortForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        postOrt();
      });
      document.getElementById('ort').addEventListener('change', postOrt);
    }
    paint();
  })();
  </script>
</body>
</html>
