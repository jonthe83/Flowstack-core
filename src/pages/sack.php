<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var string $searchUrl */
/** @var string $backUrl */

$searchUrl = $searchUrl ?? jeab_url('index.php?p=sack&intent=search');
$backUrl = $backUrl ?? jeab_url('index.php?p=links');
$csrf = jeab_csrf_token();

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="sv">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf" content="<?= e($csrf) ?>">
  <meta name="theme-color" content="#1f4a32">
  <title>Hämtning av säck · JEAB</title>
  <style>
    :root {
      --bg:#f3efe6; --ink:#142018; --soft:#3d4a40; --muted:#6b746c;
      --panel:#fffdf8; --line:#d7d0c2; --green:#1f4a32; --green2:#2d6a48;
      --yellow:#e6b423;
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
    .wrap{width:min(560px,calc(100% - 32px));margin:28px auto 48px}
    .back{
      display:inline-flex;align-items:center;gap:6px;margin-bottom:14px;
      color:var(--green);font-weight:700;font-size:.9rem;text-decoration:none;
    }
    header{display:flex;align-items:center;gap:14px;margin-bottom:18px}
    .mark{
      width:52px;height:52px;border-radius:14px;
      background:linear-gradient(160deg,var(--green2),var(--green));
      display:grid;place-items:center;flex-shrink:0;
    }
    .brand h1{font-size:1.05rem}
    .brand p{color:var(--muted);font-size:.82rem}
    .card{
      background:var(--panel);border:1px solid var(--line);
      border-radius:18px;box-shadow:0 18px 50px rgba(20,32,24,.12);overflow:hidden;
    }
    .hero{padding:22px 24px 18px;border-bottom:1px solid var(--line)}
    .pill{
      display:inline-flex;align-items:center;gap:8px;
      background:#fff7d6;color:#6a4d00;border:1px solid #efd27a;
      border-radius:999px;padding:5px 11px;font-size:.75rem;font-weight:700;
      text-transform:uppercase;
    }
    .pill .dot{width:9px;height:9px;border-radius:50%;background:var(--yellow)}
    .hero h2{margin-top:12px;font-size:1.55rem}
    .hero .lead{margin-top:8px;color:var(--soft)}
    .meta{margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .meta div{background:#f6f3ea;border:1px solid var(--line);border-radius:12px;padding:10px 12px}
    .meta dt{font-size:.72rem;color:var(--muted);text-transform:uppercase;font-weight:700}
    .meta dd{font-weight:700;margin-top:2px}
    form{padding:8px 24px 8px}
    label{display:block;font-size:.82rem;font-weight:700;margin:14px 0 6px}
    .field{position:relative}
    input[type=text],input[type=tel]{
      width:100%;border:1.5px solid #cfc7b6;background:#fff;color:var(--ink);
      border-radius:12px;padding:13px 14px;font-size:1rem;outline:none;
    }
    textarea{
      width:100%;border:1.5px solid #cfc7b6;background:#fff;color:var(--ink);
      border-radius:12px;padding:13px 14px;font-size:1rem;outline:none;
      font-family:inherit;line-height:1.45;resize:vertical;
    }
    input:focus,textarea:focus{border-color:var(--green2);box-shadow:0 0 0 4px rgba(45,106,72,.16)}
    input.bad{border-color:#c25a5a}
    .suggest{
      display:none;position:absolute;z-index:30;left:0;right:0;top:calc(100% + 4px);
      background:#fff;border:1px solid var(--line);border-radius:12px;
      box-shadow:0 12px 28px rgba(20,32,24,.14);max-height:240px;overflow:auto;
    }
    .suggest.open{display:block}
    .suggest button{
      display:block;width:100%;text-align:left;border:0;background:transparent;
      padding:11px 14px;font:inherit;color:var(--ink);cursor:pointer;
      border-bottom:1px solid #eee8dc;
    }
    .suggest button:last-child{border-bottom:0}
    .suggest button:hover,.suggest button:focus{background:#eef5f0;outline:none}
    .suggest .sub{display:block;color:var(--muted);font-size:.8rem;font-weight:500;margin-top:2px}
    .suggest .typ{
      display:inline-block;margin-right:8px;padding:1px 7px;border-radius:999px;vertical-align:1px;
      font-size:.68rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase;
      background:#eef5f0;color:var(--green);border:1px solid #cfe0d5;
    }
    .suggest .typ-contact{background:#eef1f8;color:#2d4a7a;border-color:#cfd8ea}
    .suggest .typ-order{background:#fbf3e2;color:#7a5a12;border-color:#ecdcb4}
    .suggest .empty{padding:11px 14px;font-size:.85rem;color:var(--muted)}
    .status{padding:8px 24px 0;font-size:.82rem;color:var(--soft);min-height:1.2em}
    .status.err{color:#8b2e2e}
    .actions{padding:16px 24px 24px}
    #send{
      width:100%;border:0;cursor:pointer;
      background:linear-gradient(180deg,var(--green2),var(--green));
      color:#fff;font-weight:750;font-size:1.08rem;padding:16px 18px;border-radius:14px;
      font-family:inherit;
    }
    #send:disabled{opacity:.45;cursor:not-allowed}
    .notes{margin-top:16px;padding:16px 18px;background:#f6f3ea;border:1px solid var(--line);border-radius:14px;font-size:.86rem;color:var(--soft)}
    .notes ul{margin:8px 0 0 18px}
    .done{display:none;padding:28px 24px 24px;text-align:center}
    .done.show{display:block}
    .hide{display:none!important}
    .receipt{text-align:left;margin:18px 0 8px;background:#f6f3ea;border:1px solid var(--line);border-radius:12px;padding:14px 16px;white-space:pre-wrap}
    .again{margin-top:14px;width:100%;border:1px solid var(--line);background:#fff;border-radius:12px;padding:12px;font-weight:700;cursor:pointer;font-family:inherit}
    footer{margin-top:16px;text-align:center;color:var(--muted);font-size:.78rem}
    footer a{color:var(--green)}
  </style>
</head>
<body>
  <div class="wrap">
    <a class="back" href="<?= e($backUrl) ?>">← Övrigt</a>
    <header>
      <div class="mark" aria-hidden="true">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
          <path d="M4 8.5 12 4l8 4.5v7L12 20l-8-4.5v-7Z" stroke="#F3CC4A" stroke-width="1.7"/>
          <path d="M8 14.2V10l4-2.2L16 10v4.2" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
      </div>
      <div class="brand">
        <h1>Hämtning av säck</h1>
        <p>All-inclusive XL 1,6 kbm · Demo Åkeri</p>
      </div>
    </header>

    <main class="card">
      <div class="hero" id="hero">
        <span class="pill"><span class="dot"></span> Gul storsäck · All-inclusive</span>
        <h2>Boka upphämtning</h2>
        <p class="lead">Skriv namn, adress, telefon eller mejl (minst 3 tecken) för att fylla i från påminnelser, kontakter och ordrar.</p>
        <dl class="meta">
          <div><dt>Produkt</dt><dd>XL 1,6 kbm</dd></div>
          <div><dt>Max last</dt><dd>1 500 kg</dd></div>
        </dl>
      </div>

      <form id="form" novalidate autocomplete="off">
        <label for="namn">Namn</label>
        <div class="field">
          <input id="namn" name="namn" type="text" autocomplete="off" required placeholder="För- och efternamn">
          <div class="suggest" id="suggest-namn" role="listbox"></div>
        </div>

        <label for="adress">Adress</label>
        <div class="field">
          <input id="adress" name="adress" type="text" autocomplete="off" required placeholder="Gata och nummer">
          <div class="suggest" id="suggest-adress" role="listbox"></div>
        </div>

        <label for="ort">Ort</label>
        <input id="ort" name="ort" type="text" autocomplete="address-level2" required placeholder="T.ex. Järfälla">

        <label for="telefon">Telefonnummer</label>
        <div class="field">
          <input id="telefon" name="telefon" type="tel" inputmode="tel" autocomplete="off" required placeholder="07X-XXX XX XX">
          <div class="suggest" id="suggest-telefon" role="listbox"></div>
        </div>

        <label for="kommentar">Kommentar</label>
        <textarea id="kommentar" name="kommentar" rows="5" placeholder="Valfritt. Kommer med i mejlet."></textarea>
      </form>

      <p class="status" id="status" aria-live="polite"></p>

      <div class="actions" id="actions">
        <button type="submit" form="form" id="send" disabled>Skicka</button>
        <div class="notes">
          <strong>Inför hämtning</strong>
          <ul>
            <li>Säcken ska stå lättillgängligt för kranbil, max 8 m räckvidd.</li>
            <li>Fri höjd minst 4,5 m och vägbredd minst 3 m.</li>
            <li>Säckar får inte staplas. Inget elavfall, däck, hushålls- eller farligt avfall.</li>
          </ul>
        </div>
      </div>

      <div class="done" id="done">
        <h2>Beställningen är redo</h2>
        <p id="doneText"></p>
        <div class="receipt" id="receipt"></div>
        <button type="button" class="again" id="again">Ny beställning</button>
      </div>
    </main>

    <footer>
      Produkt: All-inclusive XL 1,6 kbm · Demo Åkeri AB<br>
      Stockholm C + Nord:
      <a href="mailto:container@example.test">container@example.test</a>
      · <a href="tel:+4680000001">08-000 00 01</a><br>
      För transport utanför zon tillkommer timdebitering.
      <a href="<?= e(jeab_asset_url('assets/img/zon-karta.jpg')) ?>" target="_blank" rel="noopener">Zon-karta här</a>
    </footer>
  </div>

  <script>
  (() => {
    const PRODUCT = "All-inclusive XL 1,6 kbm";
    const SEARCH_URL = <?= json_encode($searchUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const SEND_URL = <?= json_encode(jeab_url('index.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
    const FOOTER = [
      "Det är endast 1 st välfylld säck, ganska lätt, mestadels kartong och trä.",
      "/ Demo Energi Järfälla, Sam 080000002"
    ].join("\n");

    const form = document.getElementById("form");
    const fields = ["namn", "adress", "ort", "telefon"];
    const sendBtn = document.getElementById("send");
    const statusEl = document.getElementById("status");
    // Namn och adress som förut, plus telefon. Servern söker även mejl, ordernummer och Fortnox-nummer.
    const SUGGEST_FIELDS = ["namn", "adress", "telefon"];
    const TYPE_TEXT = { reminder: "påminnelse", contact: "kontakt", order: "order" };
    let suggestTimer = null;
    let suggestAbort = null;
    let sending = false;

    function vals() {
      return Object.fromEntries(fields.map((id) => [id, document.getElementById(id).value.trim()]));
    }
    function phoneOk(s) {
      const d = s.replace(/\D/g, "");
      return d.length >= 8 && d.length <= 15;
    }
    function filled() {
      const v = vals();
      return !!(v.namn && v.adress && v.ort && phoneOk(v.telefon));
    }
    function refresh() {
      fields.forEach((id) => {
        const el = document.getElementById(id);
        const empty = !el.value.trim();
        const badPhone = id === "telefon" && el.value.trim() && !phoneOk(el.value);
        el.classList.toggle("bad", !!(el.dataset.touched && (empty || badPhone)) || badPhone);
      });
      sendBtn.disabled = !filled() || sending;
    }
    function setStatus(msg, err) {
      statusEl.textContent = msg || "";
      statusEl.classList.toggle("err", !!err);
    }
    function closeSuggest(exceptId) {
      SUGGEST_FIELDS.map((f) => "suggest-" + f).forEach((id) => {
        if (exceptId && id === exceptId) return;
        const box = document.getElementById(id);
        box.classList.remove("open");
        box.innerHTML = "";
      });
    }
    function applyHit(hit) {
      document.getElementById("namn").value = hit.name || "";
      document.getElementById("adress").value = hit.street || hit.address || "";
      document.getElementById("ort").value = hit.city || "";
      if (hit.phone) document.getElementById("telefon").value = hit.phone;
      fields.forEach((id) => { document.getElementById(id).dataset.touched = "1"; });
      closeSuggest();
      refresh();
      setStatus("Uppgifter ifyllda från " + (TYPE_TEXT[hit.type] || "påminnelse") + (hit.ref ? " " + hit.ref : "") + ".");
      const missing = fields.find((id) => !document.getElementById(id).value.trim());
      if (missing) document.getElementById(missing).focus();
      else sendBtn.focus();
    }
    function renderSuggest(boxId, hits, emptyText) {
      const box = document.getElementById(boxId);
      box.innerHTML = "";
      if (!hits.length) {
        closeSuggest(boxId);
        const empty = document.createElement("div");
        empty.className = "empty";
        empty.textContent = emptyText || "Inga träffar i påminnelser, kontakter eller ordrar.";
        box.appendChild(empty);
        box.classList.add("open");
        return;
      }
      closeSuggest(boxId);
      hits.forEach((hit) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.setAttribute("role", "option");
        btn.dataset.type = hit.type || "reminder";
        const typ = document.createElement("span");
        typ.className = "typ typ-" + (hit.type || "reminder");
        typ.textContent = hit.type_label || "Påminnelse";
        btn.appendChild(typ);
        const title = document.createElement("strong");
        title.textContent = hit.name || hit.street || hit.label || "Träff";
        btn.appendChild(title);
        const subText = [hit.street || hit.address, hit.city, hit.phone, hit.ref].filter(Boolean).join(", ");
        if (subText) {
          const sub = document.createElement("span");
          sub.className = "sub";
          sub.textContent = subText;
          btn.appendChild(sub);
        }
        btn.addEventListener("mousedown", (e) => {
          e.preventDefault();
          applyHit(hit);
        });
        box.appendChild(btn);
      });
      box.classList.add("open");
    }
    async function runSuggest(fieldId, q) {
      if (suggestAbort) suggestAbort.abort();
      const boxId = "suggest-" + fieldId;
      if (q.trim().length < 3) {
        closeSuggest();
        return;
      }
      suggestAbort = new AbortController();
      try {
        const url = SEARCH_URL + (SEARCH_URL.includes("?") ? "&" : "?") + "q=" + encodeURIComponent(q.trim());
        const res = await fetch(url, {
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
          credentials: "same-origin",
          signal: suggestAbort.signal
        });
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok || !data || !data.ok) {
          throw new Error((data && data.error) || (res.status === 401 ? "Logga in igen." : "Sökningen svarade inte (" + res.status + ")."));
        }
        const hits = Array.isArray(data.hits) ? data.hits : [];
        const errs = Array.isArray(data.errors) ? data.errors : [];
        renderSuggest(boxId, hits, errs.length ? "Inga träffar. Kunde inte söka i: " + errs.join(", ") + "." : "");
        if (errs.length && hits.length) setStatus("Kunde inte söka i: " + errs.join(", ") + ".", true);
      } catch (err) {
        if (err && err.name === "AbortError") return;
        // Visa felet i rutan. Förr stängdes den tyst och såg ut som "inga förslag".
        renderSuggest(boxId, [], "Sökningen misslyckades: " + ((err && err.message) || "okänt fel"));
      }
    }
    function scheduleSuggest(fieldId) {
      const el = document.getElementById(fieldId);
      clearTimeout(suggestTimer);
      suggestTimer = setTimeout(() => runSuggest(fieldId, el.value), 180);
    }

    fields.forEach((id) => {
      const el = document.getElementById(id);
      el.addEventListener("input", refresh);
      el.addEventListener("blur", () => { el.dataset.touched = "1"; refresh(); });
    });
    SUGGEST_FIELDS.forEach((fieldId) => {
      const el = document.getElementById(fieldId);
      el.addEventListener("input", () => scheduleSuggest(fieldId));
      el.addEventListener("focus", () => {
        if (el.value.trim().length >= 3) scheduleSuggest(fieldId);
      });
      el.addEventListener("keydown", (e) => {
        if (e.key === "Escape") closeSuggest();
      });
    });
    document.addEventListener("click", (e) => {
      if (!e.target.closest(".field")) closeSuggest();
    });

    function commentText() {
      const el = document.getElementById("kommentar");
      return String(el && el.value || "").replace(/\0/g, "").trim();
    }

    function bookingText(v) {
      const lines = [
        "BESTÄLLNING HÄMTNING TILL DemoS",
        "Produkt: " + PRODUCT,
        "",
        "Namn: " + v.namn,
        "Adress: " + v.adress,
        "Ort: " + v.ort,
        "Telefon: " + v.telefon
      ];
      const comment = commentText();
      if (comment) {
        lines.push("", "Kommentar:", comment);
      }
      lines.push("", FOOTER);
      lines.push("", "Skickad: " + new Date().toLocaleString("sv-SE"));
      return lines.join("\n");
    }

    function showDone(body, to) {
      document.getElementById("hero").classList.add("hide");
      form.classList.add("hide");
      document.getElementById("actions").classList.add("hide");
      statusEl.classList.add("hide");
      document.getElementById("done").classList.add("show");
      document.getElementById("receipt").textContent = body;
      document.getElementById("doneText").textContent =
        "Mejlet skickades från kontakt@jarfalla.example.test till " + to + ".";
    }

    form.addEventListener("submit", async (e) => {
      e.preventDefault();
      fields.forEach((id) => { document.getElementById(id).dataset.touched = "1"; });
      refresh();
      if (!filled() || sending) {
        if (!filled()) setStatus("Alla fält måste vara ifyllda.", true);
        return;
      }
      const v = vals();
      sending = true;
      refresh();
      setStatus("Skickar…");
      sendBtn.textContent = "Skickar…";

      const fd = new FormData();
      fd.set("action", "sack.send");
      fd.set("_csrf", CSRF);
      fd.set("namn", v.namn);
      fd.set("adress", v.adress);
      fd.set("ort", v.ort);
      fd.set("telefon", v.telefon);
      fd.set("kommentar", commentText());

      try {
        const res = await fetch(SEND_URL, {
          method: "POST",
          body: fd,
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest"
          }
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || !data.ok) {
          const msg = (data && data.error) ? data.error : ("Kunde inte skicka (HTTP " + res.status + ").");
          throw new Error(msg);
        }
        const row = data.row || {};
        const body = row.body || bookingText(v);
        const to = row.to || "mottagaren";
        showDone(body, to);
      } catch (err) {
        setStatus(err && err.message ? err.message : "Kunde inte skicka mejlet.", true);
        sending = false;
        sendBtn.textContent = "Skicka";
        refresh();
      }
    });

    document.getElementById("again").addEventListener("click", () => location.reload());
    refresh();
  })();
  </script>
</body>
</html>
<?php
exit;
