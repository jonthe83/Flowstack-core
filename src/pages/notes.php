<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var list<array<string,mixed>> $notes */
/** @var array<string,mixed>|null $open */
/** @var array<string,string> $sections */

$notes = $notes ?? [];
$open = $open ?? null;
$sections = $sections ?? jeab_notes_sections();
$openId = is_array($open) ? (int) ($open['id'] ?? 0) : 0;

ob_start();
?>
<div class="notes-app" data-js="notes" data-action="<?= e(jeab_url('index.php')) ?>">
  <header class="notes-top">
    <h1>Interna anteckningar</h1>
    <div class="notes-top-tools">
      <label class="notes-search-wrap">
        <span class="visually-hidden">Sök</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16.2 16.2 21 21" stroke-linecap="round"/></svg>
        <input type="search" data-js="notes-q" placeholder="Sök" autocomplete="off">
      </label>
    </div>
  </header>

  <div class="notes-cols">
    <aside class="notes-side">
      <label class="notes-filter">
        <span>Avsnitt —</span>
        <select data-js="notes-section">
          <option value="faltnoteringar">Fältnoteringar</option>
        </select>
      </label>
      <ul class="notes-list" data-js="notes-list"></ul>
      <button type="button" class="notes-new" data-js="notes-new">+ Ny anteckning</button>
    </aside>

    <section class="notes-main" data-js="notes-editor" hidden>
      <input class="notes-title" data-js="notes-title" maxlength="200" placeholder="Ny anteckning">
      <div class="notes-when">
        <input type="date" data-js="notes-date">
        <input type="time" data-js="notes-start">
        <span class="notes-when-dash">–</span>
        <input type="time" data-js="notes-end">
      </div>
      <ul class="notes-body" data-js="notes-body" contenteditable="true"></ul>
      <p class="notes-saved" data-js="notes-saved"></p>
      <button type="button" class="notes-delete" data-js="notes-delete">Ta bort anteckning</button>
    </section>

    <p class="notes-empty" data-js="notes-empty">Inga anteckningar än. Skapa den första.</p>

    <aside class="notes-photos" data-js="notes-photos-wrap" hidden>
      <h2 data-js="notes-photos-title">Bilder (0)</h2>
      <div class="notes-photos-list" data-js="notes-photos"></div>
      <button type="button" class="notes-photo-add" data-js="notes-photo-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 8.2A2.2 2.2 0 0 1 6.2 6h2L9.6 4.4A1.2 1.2 0 0 1 10.5 4h3a1.2 1.2 0 0 1 .9.4L15.8 6h2A2.2 2.2 0 0 1 20 8.2v9.6A2.2 2.2 0 0 1 17.8 20H6.2A2.2 2.2 0 0 1 4 17.8V8.2Z"/><circle cx="12" cy="13" r="3.2"/></svg>
        <span>Ta foto / Infoga</span>
        <small>Ta ett foto eller infoga från enheten</small>
      </button>
      <input id="notes-file" type="file" accept="image/*" capture="environment" hidden>
    </aside>
  </div>
</div>
<script type="application/json" id="notes-seed"><?= json_encode([
    'notes' => $notes,
    'openId' => $openId,
    'sections' => $sections,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script>
(() => {
  const root = document.querySelector("[data-js=notes]");
  if (!root) return;
  const seed = JSON.parse(document.getElementById("notes-seed").textContent || "{}");
  const ACTION = root.dataset.action || "index.php";
  const CSRF = document.querySelector('meta[name="csrf"]')?.content || "";
  let notes = Array.isArray(seed.notes) ? seed.notes : [];
  let openId = Number(seed.openId || 0);
  let saveTimer = null;
  let saving = false;

  const listEl = root.querySelector("[data-js=notes-list]");
  const editor = root.querySelector("[data-js=notes-editor]");
  const emptyEl = root.querySelector("[data-js=notes-empty]");
  const photosWrap = root.querySelector("[data-js=notes-photos-wrap]");
  const titleEl = root.querySelector("[data-js=notes-title]");
  const dateEl = root.querySelector("[data-js=notes-date]");
  const startEl = root.querySelector("[data-js=notes-start]");
  const endEl = root.querySelector("[data-js=notes-end]");
  const bodyEl = root.querySelector("[data-js=notes-body]");
  const savedEl = root.querySelector("[data-js=notes-saved]");
  const photosEl = root.querySelector("[data-js=notes-photos]");
  const photosTitle = root.querySelector("[data-js=notes-photos-title]");
  const qEl = root.querySelector("[data-js=notes-q]");
  const fileIn = document.getElementById("notes-file");

  function current() {
    return notes.find((n) => Number(n.id) === openId) || null;
  }
  function setUrl(id) {
    const url = new URL(location.href);
    if (id) url.searchParams.set("id", String(id));
    else url.searchParams.delete("id");
    url.searchParams.set("p", "notes");
    history.replaceState(null, "", url.pathname + url.search);
  }
  async function api(action, extra) {
    const fd = extra instanceof FormData ? extra : new FormData();
    if (!(extra instanceof FormData) && extra) {
      Object.entries(extra).forEach(([k, v]) => { if (v !== undefined && v !== null) fd.set(k, String(v)); });
    }
    fd.set("action", action);
    fd.set("_csrf", CSRF);
    const res = await fetch(ACTION, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" }
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.ok) {
      throw new Error((data && data.error) || ("Kunde inte spara (HTTP " + res.status + ")."));
    }
    return data;
  }
  function upsert(row) {
    if (!row || !row.id) return;
    const i = notes.findIndex((n) => Number(n.id) === Number(row.id));
    if (i >= 0) notes[i] = row;
    else notes.unshift(row);
    notes.sort((a, b) => String(b.event_at).localeCompare(String(a.event_at)) || (Number(b.id) - Number(a.id)));
  }
  function filtered() {
    const q = (qEl.value || "").trim().toLowerCase();
    return notes.filter((n) => {
      if (!q) return true;
      return ((n.title || "") + " " + (n.body || "")).toLowerCase().includes(q);
    });
  }
  function renderList() {
    const rows = filtered();
    listEl.innerHTML = "";
    rows.forEach((n) => {
      const li = document.createElement("li");
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "notes-item" + (Number(n.id) === openId ? " is-on" : "");
      const title = document.createElement("strong");
      title.textContent = n.title || "Ny anteckning";
      const date = document.createElement("span");
      date.textContent = n.list_date || "";
      btn.appendChild(title);
      btn.appendChild(date);
      btn.addEventListener("click", () => open(n.id));
      li.appendChild(btn);
      listEl.appendChild(li);
    });
  }
  function linesToBody(text) {
    bodyEl.innerHTML = "";
    const lines = String(text || "").replace(/\r\n/g, "\n").split("\n");
    (lines.length ? lines : [""]).forEach((line) => {
      const li = document.createElement("li");
      li.textContent = line.replace(/^[•\-\u2022]\s*/, "");
      bodyEl.appendChild(li);
    });
  }
  function bodyToText() {
    return Array.from(bodyEl.querySelectorAll("li")).map((li) => li.textContent.replace(/\u00a0/g, " ").trimEnd()).join("\n");
  }
  function bytesLabel(n) {
    if (!n) return "";
    if (n >= 1024 * 1024) return (n / (1024 * 1024)).toFixed(1).replace(".", ",") + " MB";
    return Math.max(1, Math.round(n / 1024)) + " kB";
  }
  function renderPhotos(row) {
    const photos = row && Array.isArray(row.photos) ? row.photos : [];
    photosTitle.textContent = "Bilder (" + photos.length + ")";
    photosEl.innerHTML = "";
    photos.forEach((p) => {
      const fig = document.createElement("figure");
      const img = document.createElement("img");
      img.src = ACTION + "?p=notes&id=" + encodeURIComponent(row.id) + "&photo=" + encodeURIComponent(p.id);
      img.alt = p.name || "Bild";
      const cap = document.createElement("figcaption");
      const name = document.createElement("b");
      name.textContent = p.name || "bild";
      const size = document.createElement("span");
      size.textContent = bytesLabel(p.size);
      cap.appendChild(name);
      cap.appendChild(size);
      const x = document.createElement("button");
      x.type = "button";
      x.className = "notes-photo-x";
      x.setAttribute("aria-label", "Ta bort bild");
      x.textContent = "×";
      x.addEventListener("click", async () => {
        try {
          const data = await api("notes.photo_delete", { id: String(openId), photo: p.id });
          if (data.row) { upsert(data.row); renderPhotos(data.row); savedEl.textContent = data.row.updated_label || ""; }
        } catch (err) {
          savedEl.textContent = err.message || "Kunde inte ta bort bilden.";
        }
      });
      fig.appendChild(img);
      fig.appendChild(cap);
      fig.appendChild(x);
      photosEl.appendChild(fig);
    });
  }
  function fillEditor(row) {
    titleEl.value = row.title || "";
    dateEl.value = row.event_date || "";
    startEl.value = row.event_start || "";
    endEl.value = row.event_end_time || "";
    linesToBody(row.body || "");
    savedEl.textContent = row.updated_label || "";
    renderPhotos(row);
  }
  function open(id) {
    openId = Number(id) || 0;
    const row = current();
    renderList();
    if (!row) {
      editor.hidden = true;
      photosWrap.hidden = true;
      emptyEl.hidden = notes.length > 0;
      setUrl(0);
      return;
    }
    emptyEl.hidden = true;
    editor.hidden = false;
    photosWrap.hidden = false;
    fillEditor(row);
    setUrl(row.id);
  }
  function queueSave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 400);
  }
  async function saveNow() {
    if (!openId || saving) return;
    saving = true;
    savedEl.textContent = "Sparar…";
    try {
      const date = dateEl.value || new Date().toISOString().slice(0, 10);
      const start = startEl.value || "00:00";
      const end = endEl.value || "";
      const data = await api("notes.update", {
        id: String(openId),
        title: titleEl.value,
        body: bodyToText(),
        event_at: date + " " + start + ":00",
        event_end: end ? (date + " " + end + ":00") : ""
      });
      if (data.row) {
        upsert(data.row);
        savedEl.textContent = data.row.updated_label || "Sparad.";
        renderList();
      }
    } catch (err) {
      savedEl.textContent = err.message || "Kunde inte spara.";
    } finally {
      saving = false;
    }
  }
  root.querySelector("[data-js=notes-new]").addEventListener("click", async () => {
    try {
      const data = await api("notes.create", {});
      if (data.row) {
        upsert(data.row);
        open(data.row.id);
        titleEl.focus();
      }
    } catch (err) {
      savedEl.textContent = err.message || "Kunde inte skapa.";
    }
  });
  root.querySelector("[data-js=notes-delete]").addEventListener("click", async () => {
    if (!openId || !confirm("Ta bort anteckningen?")) return;
    try {
      await api("notes.delete", { id: String(openId) });
      notes = notes.filter((n) => Number(n.id) !== openId);
      open(notes[0] ? notes[0].id : 0);
    } catch (err) {
      savedEl.textContent = err.message || "Kunde inte ta bort.";
    }
  });
  titleEl.addEventListener("input", queueSave);
  dateEl.addEventListener("change", queueSave);
  startEl.addEventListener("change", queueSave);
  endEl.addEventListener("change", queueSave);
  bodyEl.addEventListener("input", queueSave);
  qEl.addEventListener("input", renderList);
  bodyEl.addEventListener("keydown", (e) => {
    if (e.key !== "Enter") return;
    e.preventDefault();
    document.execCommand("insertParagraph", false);
  });
  async function addFile(file) {
    if (!openId || !file) return;
    savedEl.textContent = "Laddar upp bild…";
    const fd = new FormData();
    fd.set("id", String(openId));
    fd.set("photo", file, file.name || "bild.jpg");
    try {
      const data = await api("notes.photo", fd);
      if (data.row) { upsert(data.row); renderPhotos(data.row); savedEl.textContent = data.row.updated_label || ""; }
    } catch (err) {
      savedEl.textContent = err.message || "Kunde inte lägga till bilden.";
    }
  }
  root.querySelector("[data-js=notes-photo-btn]").addEventListener("click", () => fileIn.click());
  fileIn.addEventListener("change", () => { const f = fileIn.files && fileIn.files[0]; fileIn.value = ""; if (f) addFile(f); });

  if (!openId && notes[0]) openId = Number(notes[0].id);
  open(openId);
})();
</script>
<?php
return ob_get_clean();
