<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

/** @var JeabStore $store */
$GLOBALS['_JEAB_KONTAKT_T0'] = microtime(true);
$sok = trim((string) ($_GET['q'] ?? ''));
$cid = trim((string) ($_GET['id'] ?? ''));
$sqlSole = function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source();
if (!$sqlSole && $cid !== '' && function_exists('jeab_kontakt_get') && jeab_kontakt_get($cid) === null && function_exists('jeab_kontakt_migrate_already') && function_exists('jeab_kontakter_load')) {
    $found = jeab_kontakt_migrate_already(jeab_kontakter_load(), $cid);
    if ($found !== null) {
        $cid = $found;
    }
}
$isNew = !empty($_GET['ny']);
$filter = (string) ($_GET['f'] ?? 'alla');
if (!in_array($filter, ['alla', 'adress', 'saknas'], true)) {
    $filter = 'alla';
}
$officeAll = jeab_kontakter_all_offices();
$office = $officeAll ? null : jeab_selected_company();
$limit = JEAB_KONTAKT_PAGE;
$asked = (int) ($_GET['n'] ?? 0);
if ($asked > $limit) {
    $limit = min($asked, JEAB_KONTAKT_PAGE * 5);
}
$ktFragment = (string) ($_GET['intent'] ?? '') === 'card' && $cid !== '' && !$isNew;
if ($ktFragment) {
    $view = [
        'rows' => [],
        'total' => 0,
        'next' => null,
        'counts' => ['alla' => 0, 'adress' => 0, 'saknas' => 0],
        'catalog' => [],
        'paged' => true,
    ];
} else {
    $view = jeab_kontakter_view($store, $sok, $filter, $office, $limit, $isNew ? '' : $cid);
}
$rows = $view['rows'];
$counts = $view['counts'];
$total = (int) $view['total'];
$cur = (!$isNew && $cid !== '') ? jeab_kontakt_get($cid) : null;
if ($cur !== null) {
    $cid = (string) ($cur['id'] ?? $cid);
}
$show = $cur !== null || $isNew;
$GLOBALS['_JEAB_KONTAKT_DETAIL'] = $show;
$owners = is_array($cur['owners'] ?? null) ? array_values($cur['owners']) : [];
$owner1 = is_array($owners[0] ?? null) ? $owners[0] : ['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => ''];
$owner2 = is_array($owners[1] ?? null) ? $owners[1] : ['name' => '', 'personnummer' => '', 'contact' => false, 'email' => '', 'phone' => ''];
$contactSlot = !empty($owner2['contact']) ? '2' : '1';
$contactOwner = $contactSlot === '2' ? $owner2 : $owner1;
if ($cur !== null) {
    jeab_kontakt_card_budget_begin(0.7);
    if ($store instanceof JeabPdoStore) {
        jeab_pdo_read_budget($store->pdo(), 700);
    }
}
$linkStarted = microtime(true);
$modules = jeab_kontakt_module_groups_empty();
$modulePdo = ($cur !== null && $store instanceof JeabPdoStore) ? $store->pdo() : null;
if ($modulePdo instanceof PDO) {
    $modules = jeab_kontakt_modules($modulePdo, $cid);
}
if (function_exists('jeab_timing_add')) {
    jeab_timing_add('kontakt_links', microtime(true) - $linkStarted);
}
$ktThreads = (string) ($_GET['intent'] ?? '') === 'threads' && $cid !== '' && !$isNew;
if ($cur !== null && $store instanceof JeabPdoStore) {
    jeab_pdo_read_budget_clear($store->pdo());
}
$post = e(jeab_url('index.php?p=kontakter'));
$email = trim((string) ($contactOwner['email'] ?? ''));
$secondaryPair = function_exists('jeab_kontakt_secondary_pair') && is_array($cur) ? jeab_kontakt_secondary_pair($cur) : ['', ''];
$email2 = $secondaryPair[0];
$email3 = $secondaryPair[1];
$phone = trim((string) ($contactOwner['phone'] ?? ''));
$telHref = preg_replace('/[^\d+]/', '', $phone) ?? '';
$phone2 = '';
if (is_array($cur)) {
    $phone2 = trim((string) ($cur['phone2'] ?? ''));
    if ($phone2 === '' && is_array($contactOwner)) {
        $phone2 = trim((string) ($contactOwner['phone2'] ?? ''));
    }
}
$telHref2 = preg_replace('/[^\d+]/', '', $phone2) ?? '';
$ovrigt = $cur ? jeab_kontakt_ovrigt($cur) : '';
$contactCompany = jeab_normalize_company(is_array($cur) ? ($cur['company'] ?? null) : null) ?? $office;

$fmtPostal = static function (string $digits): string {
    $digits = preg_replace('/\D+/', '', $digits) ?? '';
    return strlen($digits) === 5 ? substr($digits, 0, 3) . ' ' . substr($digits, 3) : $digits;
};

$href = static function (array $extra = []) use ($sok, $filter, $office, $officeAll): string {
    $q = ['p' => 'kontakter', 'co' => $officeAll ? 'alla' : $office];
    if ($sok !== '' && !array_key_exists('q', $extra)) {
        $q['q'] = $sok;
    }
    if ($filter !== 'alla' && !array_key_exists('f', $extra)) {
        $q['f'] = $filter;
    }
    foreach ($extra as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            unset($q[$key]);
            continue;
        }
        $q[$key] = $value;
    }
    return jeab_url('index.php?' . http_build_query($q));
};

$listHref = $href(['id' => null, 'ny' => null]);
$searchSvg = '<svg class="crm-search-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>';
$emptyText = $sok !== ''
    ? 'Inga träffar för "' . $sok . '"'
    : 'Inga kontakter.';

if ($ktThreads) {
    ob_start();
    $threadOnly = jeab_kontakt_module_groups_empty();
    $threadOnly['inkorg'] = $modules['inkorg'];
    echo jeab_kontakt_modules_html($threadOnly);
    $threadHtml = (string) ob_get_clean();
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('kontakt_threads', microtime(true) - ($GLOBALS['_JEAB_KONTAKT_T0'] ?? microtime(true)));
    }
    jeab_json(['ok' => true, 'html' => $threadHtml]);
}

$catalog = [];
$catalogById = [];
$visibleIds = [];
foreach ($rows as $id => $r) {
    $visibleIds[(string) $id] = true;
}
$ktServer = (!empty($view['paged']) || count($view['catalog']) > count($rows)) ? '1' : '0';
foreach ($view['catalog'] as $item) {
    if (!isset($visibleIds[(string) ($item['id'] ?? '')])) {
        continue;
    }
    $entry = [
        'id' => (string) $item['id'],
        'street' => (string) $item['street'],
        'city' => (string) $item['city'],
        'name' => (string) $item['name'],
        'missing' => !empty($item['missing']),
        'marks' => (string) ($item['marks'] ?? ''),
        'hay' => (string) $item['hay'],
    ];
    $catalog[] = $entry;
    $catalogById[$entry['id']] = $entry;
}

ob_start();
if (!$ktFragment):
?>
<div class="apple-app crm-app kt" data-kt-root data-kt-server="<?= e($ktServer) ?>" data-filter="<?= e($filter) ?>" data-current="<?= e($show && !$isNew ? $cid : '') ?>" data-list="<?= e($listHref) ?>" data-page="<?= (int) JEAB_KONTAKT_PAGE ?>"<?php if (!empty($flash)): ?> data-kt-flash="<?= e((string) $flash) ?>"<?php endif; ?>>
    <header class="crm-top">
        <div class="crm-top-text">
            <p class="apple-eyebrow">MINI-CRM</p>
            <h1 class="crm-title">Kontakter <span class="crm-section-tag" data-kt-heading><?= (int) $counts['alla'] ?></span></h1>
        </div>
        <a class="chip chip-primary crm-add" href="<?= e($href(['ny' => '1', 'id' => null, 'q' => null, 'f' => null])) ?>">+ Ny kontakt</a>
    </header>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= e((string) $error) ?></p><?php endif; ?>
    <?php if (!empty($flash)): ?><p class="flash" role="status"><?= e((string) $flash) ?></p><?php endif; ?>

    <form class="apple-search" method="get" action="<?= e(jeab_url('index.php')) ?>" role="search" data-kt-search>
        <input type="hidden" name="p" value="kontakter">
        <input type="hidden" name="co" value="<?= e($officeAll ? 'alla' : (string) $office) ?>">
        <?php if ($filter !== 'alla'): ?><input type="hidden" name="f" value="<?= e($filter) ?>" data-kt-filter-field><?php else: ?><input type="hidden" name="f" value="" data-kt-filter-field disabled><?php endif; ?>
        <?= $searchSvg ?>
        <div class="apple-search-field">
            <input type="search" name="q" value="<?= e($sok) ?>" placeholder="Sök namn, gata eller ort" autocomplete="off" data-kt-q>
            <button class="apple-search-clear" type="button" data-kt-clear aria-label="Rensa sök"<?= $sok === '' ? ' hidden' : '' ?>>×</button>
        </div>
    </form>

    <div class="smart-grid crm-stats" role="tablist" aria-label="Kontakter">
        <?php
        $chips = [
            'alla' => 'Alla',
            'adress' => 'Med adress',
            'saknas' => 'Saknar adress',
        ];
        foreach ($chips as $key => $label):
            $on = $filter === $key;
            ?>
        <a class="smart-tile<?= $on ? ' is-on' : '' ?>" role="tab" href="<?= e($href(['f' => $key === 'alla' ? null : $key, 'id' => null, 'ny' => null, 'n' => null])) ?>" data-kt-chip="<?= e($key) ?>"<?= $on ? ' aria-selected="true"' : '' ?>>
            <b data-kt-count="<?= e($key) ?>"><?= (int) $counts[$key] ?></b>
            <span><?= e($label) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="kt-split">
        <div class="kt-list">
            <p class="empty" data-kt-empty<?= $total > 0 ? ' hidden' : '' ?>><?= e($emptyText) ?></p>
            <ul class="list apple-list" data-kt-list>
                <?php foreach ($rows as $id => $r):
                    if (!is_array($r)) {
                        continue;
                    }
                    $listed = $catalogById[(string) $id] ?? null;
                    if (is_array($listed)) {
                        $street = (string) $listed['street'];
                        $city = (string) $listed['city'];
                        $who = (string) $listed['name'];
                        $marks = (string) $listed['marks'];
                    } else {
                        $shown = jeab_kontakt_display_place($r);
                        $street = $shown['street'];
                        $city = $shown['city'];
                        $who = jeab_kontakt_list_name($r);
                        $marks = jeab_kontakt_list_marks($r, $who);
                    }
                    $on = (string) $id === $cid && !$isNew;
                    $sub = implode(' · ', array_filter([$who, $city, $marks], static fn (string $part): bool => $part !== ''));
                    ?>
                <li class="item<?= $on ? ' is-on' : '' ?>" data-id="<?= e((string) $id) ?>">
                    <a class="item-main" href="<?= e($href(['id' => (string) $id, 'ny' => null])) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
                        <?php if ($street === ''): ?>
                        <p class="item-shut-addr"><em class="item-note">Adress saknas</em></p>
                        <?php else: ?>
                        <p class="item-shut-addr"><strong><?= e($street) ?></strong></p>
                        <?php endif; ?>
                        <?php if ($sub !== ''): ?><p class="item-shut-name"><?= e($sub) ?></p><?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($view['next'] !== null): ?>
            <a class="crm-more" href="<?= e($href(['n' => (int) $view['next'] + JEAB_KONTAKT_PAGE, 'id' => $cid !== '' ? $cid : null])) ?>" data-kt-more>Visa fler</a>
            <?php else: ?>
            <a class="crm-more" href="<?= e($listHref) ?>" data-kt-more hidden>Visa fler</a>
            <?php endif; ?>
        </div>
<?php endif; ?>
        <div class="kt-detail">
            <?php if (!$show): ?>
            <article class="card"><p class="muted">Välj en kontakt i listan.</p></article>
            <?php else: ?>
            <a class="back kt-back" href="<?= e($listHref) ?>">← Tillbaka</a>
            <?php
            $listedCur = ($cur && isset($catalogById[$cid])) ? $catalogById[$cid] : null;
            if (is_array($listedCur)) {
                $headingPlace = ['street' => (string) $listedCur['street'], 'city' => (string) $listedCur['city']];
                $headingMarks = (string) $listedCur['marks'];
            } else {
                $headingPlace = $cur ? jeab_kontakt_display_place($cur) : ['street' => '', 'city' => ''];
                $headingMarks = $cur ? jeab_kontakt_list_marks($cur) : '';
            }
            $headingAddr = trim(implode(', ', array_filter([$headingPlace['street'], $headingPlace['city']])));
            $headingName = trim((string) ($owner1['name'] ?? ''));
            $heading = $cur ? ($headingAddr !== '' ? $headingAddr : ($headingName !== '' ? $headingName : 'Kontakt')) : 'Ny kontakt';
            ?>
            <h2 class="crm-section-title"><?= e($heading) ?><?php if ($headingMarks !== ''): ?> <span class="muted"><?= e($headingMarks) ?></span><?php endif; ?></h2>
            <?php if ($cur): ?>
            <?php
            $ktRemCompany = jeab_normalize_company($contactCompany) ?? jeab_selected_company();
            $ktRemWho = jeab_reminder_who_me_name($ktRemCompany) ?? '';
            $ktRemNames = jeab_company_names($ktRemCompany);
            ?>
            <p class="kt-reminder-open"><button type="button" class="btn" data-kt-reminder>Skapa påminnelse</button></p>
            <dialog class="reminder-create" id="kt-reminder">
                <form class="reminder-create-form" method="post" action="<?= e(jeab_url('index.php?p=reminders')) ?>">
                    <?= jeab_csrf_field() ?>
                    <input type="hidden" name="action" value="reminder.create">
                    <input type="hidden" name="ui" value="1">
                    <input type="hidden" name="from_contact" value="1">
                    <input type="hidden" name="contact_id" value="<?= e($cid) ?>">
                    <input type="hidden" name="company" value="<?= e($ktRemCompany) ?>">
                    <h2>Skapa påminnelse</h2>
                    <p class="reminder-create-label">Produkt</p>
                    <div class="chips" role="radiogroup" aria-label="Produkt">
                        <?php foreach (jeab_reminder_product_labels() as $code => $label): ?>
                        <label class="chip-opt"><input type="radio" name="product" value="<?= e((string) $code) ?>"<?= $code === 'okand' ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <p class="reminder-create-label">Ansvarig</p>
                    <div class="chips" role="radiogroup" aria-label="Ansvarig">
                        <?php foreach ($ktRemNames as $name): ?>
                        <label class="chip-opt"><input type="radio" name="who" value="<?= e($name) ?>"<?= $ktRemWho !== '' && strcasecmp($ktRemWho, $name) === 0 ? ' checked' : '' ?>><span><?= e($name) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                    <label class="field"><span>Datum</span>
                        <input type="date" name="due_at" aria-label="Datum">
                    </label>
                    <label class="field"><span>Anteckning</span>
                        <textarea name="notes" rows="2" placeholder="Anteckning"></textarea>
                    </label>
                    <div class="reminder-create-foot">
                        <button type="button" class="reminder-create-cancel" data-kt-reminder-close>Avbryt</button>
                        <button type="submit" class="reminder-create-save">Spara</button>
                    </div>
                </form>
            </dialog>
            <?php endif; ?>
            <form class="reminder-create kt-form" method="post" action="<?= $post ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="kontakter.save">
                <input type="hidden" name="id" value="<?= e($cur ? $cid : '') ?>">
                <input type="hidden" name="company" value="<?= e($contactCompany) ?>">

                <article class="card">
                    <h3 class="crm-section-title">Adress</h3>
                    <label class="field"><span>Gata och nummer</span>
                        <input name="street" value="<?= e((string) ($cur['street'] ?? '')) ?>" maxlength="200" required autocomplete="street-address">
                    </label>
                    <label class="field"><span>Postnummer</span>
                        <input name="postal_code" value="<?= e($fmtPostal((string) ($cur['postal_code'] ?? ''))) ?>" inputmode="numeric" maxlength="6" autocomplete="postal-code">
                    </label>
                    <label class="field"><span>Ort</span>
                        <input name="city" value="<?= e(jeab_kontakt_city_name((string) ($cur['city'] ?? ''))) ?>" maxlength="80" autocomplete="address-level2">
                    </label>
                    <label class="field"><span>Fastighetsbeteckning</span>
                        <input name="fastighetsbeteckning" value="<?= e((string) ($cur['fastighetsbeteckning'] ?? '')) ?>" maxlength="120">
                    </label>
                </article>

                <article class="card">
                    <h3 class="crm-section-title">Ägare</h3>
                    <label class="field"><span>Ägare 1</span>
                        <input name="owner_name_1" value="<?= e((string) ($owner1['name'] ?? '')) ?>" maxlength="120" autocomplete="name">
                    </label>
                    <label class="field"><span>Personnummer</span>
                        <span class="pnr">
                            <input id="owner_pnr_1" name="owner_pnr_1" value="<?= e(jeab_kontakt_pnr_mask((string) ($owner1['personnummer'] ?? ''))) ?>" autocomplete="off" inputmode="numeric" maxlength="20">
                            <?php if (trim((string) ($owner1['personnummer'] ?? '')) !== ''): ?>
                            <button class="btn btn-ghost" type="button" data-reveal="1" data-slot="1">Visa</button>
                            <?php endif; ?>
                        </span>
                    </label>
                    <label class="field"><span>Ägare 2</span>
                        <input name="owner_name_2" value="<?= e((string) ($owner2['name'] ?? '')) ?>" maxlength="120" autocomplete="name">
                    </label>
                    <label class="field"><span>Personnummer</span>
                        <span class="pnr">
                            <input name="owner_pnr_2" value="<?= e(jeab_kontakt_pnr_mask((string) ($owner2['personnummer'] ?? ''))) ?>" autocomplete="off" inputmode="numeric" maxlength="20">
                            <?php if (trim((string) ($owner2['personnummer'] ?? '')) !== ''): ?>
                            <button class="btn btn-ghost" type="button" data-reveal="1" data-slot="2">Visa</button>
                            <?php endif; ?>
                        </span>
                    </label>
                    <p class="reminder-create-label">Kontaktperson</p>
                    <div class="chips" role="radiogroup" aria-label="Kontaktperson">
                        <label class="chip-opt"><input type="radio" name="contact_person" value="1" <?= $contactSlot === '1' ? 'checked' : '' ?>><span>Ägare 1</span></label>
                        <label class="chip-opt"><input type="radio" name="contact_person" value="2" <?= $contactSlot === '2' ? 'checked' : '' ?>><span>Ägare 2</span></label>
                    </div>
                </article>

                <article class="card">
                    <h3 class="crm-section-title">Kontakt</h3>
                    <label class="field kt-channel"><span>Mobil</span>
                        <?php if ($phone !== '' && $telHref !== ''): ?><a class="contact-digits" href="tel:<?= e($telHref) ?>"><?= e($phone) ?></a><?php endif; ?>
                        <input name="phone" type="tel" inputmode="tel" value="<?= e($phone) ?>" maxlength="32" autocomplete="tel" aria-label="Mobil">
                    </label>
                    <label class="field kt-channel"><span>Mobil 2</span>
                        <?php if ($phone2 !== '' && $telHref2 !== ''): ?><a class="contact-digits" href="tel:<?= e($telHref2) ?>"><?= e($phone2) ?></a><?php endif; ?>
                        <input name="phone2" type="tel" inputmode="tel" value="<?= e($phone2) ?>" maxlength="32" autocomplete="off" aria-label="Mobil 2">
                    </label>
                    <label class="field kt-channel"><span>E-post</span>
                        <?php if ($email !== ''): ?><a class="contact-mail" href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php endif; ?>
                        <input name="email" type="email" value="<?= e($email) ?>" maxlength="191" autocomplete="email" inputmode="email" aria-label="E-post">
                    </label>
                    <label class="field kt-channel"><span>E-post 2</span>
                        <?php if ($email2 !== ''): ?><a class="contact-mail" href="mailto:<?= e($email2) ?>"><?= e($email2) ?></a><?php endif; ?>
                        <input name="email2" type="email" value="<?= e($email2) ?>" maxlength="191" autocomplete="off" inputmode="email" aria-label="E-post 2">
                    </label>
                    <label class="field kt-channel"><span>E-post 3</span>
                        <?php if ($email3 !== ''): ?><a class="contact-mail" href="mailto:<?= e($email3) ?>"><?= e($email3) ?></a><?php endif; ?>
                        <input name="email3" type="email" value="<?= e($email3) ?>" maxlength="191" autocomplete="off" inputmode="email" aria-label="E-post 3">
                    </label>
                </article>

                <article class="card">
                    <h3 class="crm-section-title">Övrigt</h3>
                    <label class="field"><span>Övrigt</span>
                        <textarea name="ovrigt" rows="4" maxlength="4000"><?= e($ovrigt) ?></textarea>
                    </label>
                </article>
                <?php
                $history = array_values(array_filter(preg_split("/\r\n|\n|\r/", $ovrigt) ?: [], static fn(string $line): bool => trim($line) !== ''));
                $historyMore = count($history) > 12;
                if ($historyMore) {
                    $history = array_slice($history, 0, 12);
                }
                if ($history !== []): ?>
                <article class="card">
                    <h3 class="crm-section-title">Historik</h3>
                    <ul class="list">
                        <?php foreach ($history as $line): ?>
                        <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                        <?php if ($historyMore): ?><li class="muted">Fler rader ligger i Övrigt.</li><?php endif; ?>
                    </ul>
                </article>
                <?php endif; ?>

                <p class="muted" data-pnr-status hidden></p>
                <div class="kt-save">
                    <button class="btn" type="submit">Spara</button>
                    <p class="kt-save-status" data-kt-save-status role="status"></p>
                </div>
            </form>

            <?php if ($cur): ?>
            <?= jeab_kontakt_modules_html($modules) ?>
            <form class="kt-delete" method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort kontakten?')">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="kontakter.delete">
                <input type="hidden" name="id" value="<?= e($cid) ?>">
                <button class="ix-btn is-danger" type="submit">Ta bort kontakt</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
<?php if (!$ktFragment): ?>
    </div>
</div>
<script type="application/json" id="kt-catalog"><?= json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
  var root = document.querySelector('[data-kt-root]');
  var SAVE_MS = 2500;
  var saveNoteTimer = 0;
  function ktSaveNote(text, isErr) {
    var note = root ? root.querySelector('[data-kt-save-status]') : null;
    if (!note) return;
    note.textContent = text || '';
    note.classList.toggle('is-err', !!isErr);
    window.clearTimeout(saveNoteTimer);
    if (!text) return;
    if (note.scrollIntoView) {
      try { note.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (e) { /* äldre webbläsare */ }
    }
    saveNoteTimer = window.setTimeout(function () {
      note.textContent = '';
      note.classList.remove('is-err');
    }, SAVE_MS);
  }
  if (root) {
    var flashText = root.getAttribute('data-kt-flash') || '';
    if (flashText) {
      ktSaveNote(flashText, false);
      try {
        var flashUrl = new URL(location.href);
        if (flashUrl.searchParams.has('flash')) {
          flashUrl.searchParams.delete('flash');
          history.replaceState(null, '', flashUrl.pathname + flashUrl.search + flashUrl.hash);
        }
      } catch (e) { /* adressen får behålla flash */ }
    }
  }
  if (root && root.getAttribute('data-kt-rows') !== '1') {
    root.setAttribute('data-kt-rows', '1');
    root.addEventListener('click', function (event) {
      var a = event.target && event.target.closest ? event.target.closest('a.item-main') : null;
      if (!a || !root.contains(a)) return;
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      if (a.target && a.target !== '_self') return;
      event.preventDefault();
      event.stopPropagation();
      if (!a.href) return;
      var cardUrl = new URL(a.href, location.href);
      cardUrl.searchParams.set('intent', 'card');
      var list = root.querySelector('[data-kt-list]');
      if (list) {
        list.querySelectorAll('.item.is-on').forEach(function (el) { el.classList.remove('is-on'); });
        var item = a.closest('.item');
        if (item) item.classList.add('is-on');
      }
      fetch(cardUrl.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json, text/html' }
      }).then(function (res) { return res.json(); }).then(function (data) {
        var detail = root.querySelector('.kt-detail');
        if (!detail || !data || !data.ok || !data.html) {
          location.assign(a.href);
          return;
        }
        detail.outerHTML = data.html;
        try { history.pushState(null, '', a.href); } catch (e) { /* adressen får stå */ }
        if (window.jeabKontaktBindCard) window.jeabKontaktBindCard();
      }).catch(function () {
        location.assign(a.href);
      });
    });
  }
  var catalogEl = document.getElementById('kt-catalog');
  if (!root || !catalogEl) return;
  var catalog = [];
  try { catalog = JSON.parse(catalogEl.textContent || '[]'); } catch (e) { catalog = []; }
  var list = root.querySelector('[data-kt-list]');
  var empty = root.querySelector('[data-kt-empty]');
  var input = root.querySelector('[data-kt-q]');
  var clearBtn = root.querySelector('[data-kt-clear]');
  var more = root.querySelector('[data-kt-more]');
  var form = root.querySelector('[data-kt-search]');
  var pageSize = parseInt(root.getAttribute('data-page') || '80', 10) || 80;
  var filter = root.getAttribute('data-filter') || 'alla';
  var current = root.getAttribute('data-current') || '';
  var serverList = root.getAttribute('data-kt-server') === '1';
  function serverGo(url) {
    var dest = url.pathname + url.search;
    if (window.jeabShell) {
      var link = document.createElement('a');
      link.href = dest;
      link.className = 'item-main';
      root.appendChild(link);
      link.click();
      link.remove();
      return;
    }
    location.assign(dest);
  }
  var shown = list ? list.querySelectorAll('.item').length : 0;
  var timer = 0;

  window.addEventListener('keydown', function (event) {
    if (!input || event.target !== input) return;
    if (event.metaKey || event.ctrlKey || event.altKey || event.isComposing) return;
    var key = event.key || '';
    if (key === 'Enter' || key === 'Escape' || key === 'Tab' || key.length !== 1) return;
    event.stopPropagation();
  }, true);

  function fold(value) {
    return String(value || '').toLowerCase()
      .replace(/[åäáà]/g, 'a')
      .replace(/[öø]/g, 'o')
      .replace(/æ/g, 'ae')
      .replace(/[éè]/g, 'e')
      .replace(/ü/g, 'u')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function listUrl() {
    var url = new URL(root.getAttribute('data-list') || location.href, location.href);
    var q = input ? String(input.value || '').trim() : '';
    if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
    if (filter && filter !== 'alla') url.searchParams.set('f', filter); else url.searchParams.delete('f');
    url.searchParams.delete('n');
    url.searchParams.delete('ny');
    url.searchParams.delete('id');
    return url;
  }

  function itemHref(id) {
    var url = listUrl();
    url.searchParams.set('id', id);
    return url.pathname + url.search;
  }

  function matches(item, q) {
    if (q && fold(item.id) === q) return true;
    if (filter === 'adress' && item.missing) return false;
    if (filter === 'saknas' && !item.missing) return false;
    if (!q) return true;
    var hay = String(item.hay || '');
    var tight = q.replace(/ /g, '');
    return hay.indexOf(q) !== -1 || (tight !== '' && hay.indexOf(tight) !== -1);
  }

  function filtered() {
    var q = fold(input ? input.value : '');
    return catalog.filter(function (item) { return matches(item, q); });
  }

  function paintRow(item) {
    var li = document.createElement('li');
    li.className = 'item' + (item.id === current ? ' is-on' : '');
    li.setAttribute('data-id', item.id);
    var a = document.createElement('a');
    a.className = 'item-main';
    a.href = itemHref(item.id);
    if (item.id === current) a.setAttribute('aria-current', 'true');
    var addr = document.createElement('p');
    addr.className = 'item-shut-addr';
    if (item.missing || !item.street) {
      var em = document.createElement('em');
      em.className = 'item-note';
      em.textContent = 'Adress saknas';
      addr.appendChild(em);
    } else {
      var strong = document.createElement('strong');
      strong.textContent = item.street;
      addr.appendChild(strong);
    }
    a.appendChild(addr);
    var sub = [item.name || '', item.city || '', item.marks || ''].filter(Boolean).join(' · ');
    if (sub) {
      var name = document.createElement('p');
      name.className = 'item-shut-name';
      name.textContent = sub;
      a.appendChild(name);
    }
    li.appendChild(a);
    return li;
  }

  function paint(reset) {
    if (!list) return;
    var q = fold(input ? input.value : '');
    if (!serverList && /^kt_[a-z0-9]+$/.test(q) && !catalog.some(function (item) { return fold(item.id) === q; })) {
      var url = listUrl();
      location.assign(url.pathname + url.search);
      return;
    }
    var rows = filtered();
    if (reset) shown = Math.min(pageSize, rows.length);
    else shown = Math.min(Math.max(shown, pageSize), rows.length);
    list.replaceChildren.apply(list, rows.slice(0, shown).map(paintRow));
    if (empty) {
      var q = input ? String(input.value || '').trim() : '';
      empty.hidden = rows.length > 0;
      empty.textContent = q ? 'Inga träffar för "' + q + '"' : 'Inga kontakter.';
    }
    if (more) {
      var rest = rows.length > shown;
      more.hidden = !rest;
      if (rest) {
        var url = listUrl();
        url.searchParams.set('n', String(shown + pageSize));
        if (current) url.searchParams.set('id', current);
        more.href = url.pathname + url.search;
      }
    }
    if (clearBtn && input) clearBtn.hidden = String(input.value || '').trim() === '';
    var field = form ? form.querySelector('[data-kt-filter-field]') : null;
    if (field) {
      if (filter === 'alla') field.disabled = true;
      else { field.disabled = false; field.value = filter; }
    }
  }

  function syncUrl() {
    if (!window.history || !history.replaceState) return;
    var url = listUrl();
    if (current) url.searchParams.set('id', current);
    history.replaceState(null, '', url.pathname + url.search);
  }

  if (form && input) {
    form.addEventListener('submit', function (event) {
      if (serverList) return;
      event.preventDefault();
      window.clearTimeout(timer);
      paint(true);
      syncUrl();
    });
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        if (clearBtn) clearBtn.hidden = String(input.value || '').trim() === '';
        if (serverList) {
          var url = listUrl();
          if (current) url.searchParams.set('id', current);
          if (window.jeabShell) serverGo(url);
          else location.assign(url.pathname + url.search);
          return;
        }
        paint(true);
        syncUrl();
      }, 200);
    });
  }
  if (clearBtn && input) {
    clearBtn.addEventListener('click', function () {
      input.value = '';
      window.clearTimeout(timer);
      if (serverList) {
        var url = listUrl();
        if (current) url.searchParams.set('id', current);
        if (window.jeabShell) serverGo(url);
        else location.assign(url.pathname + url.search);
        return;
      }
      paint(true);
      syncUrl();
      try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); }
    });
  }
  root.querySelectorAll('[data-kt-chip]').forEach(function (chip) {
    chip.addEventListener('click', function (event) {
      if (serverList) return;
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      filter = chip.getAttribute('data-kt-chip') || 'alla';
      root.setAttribute('data-filter', filter);
      root.querySelectorAll('[data-kt-chip]').forEach(function (el) {
        var on = el === chip;
        el.classList.toggle('is-on', on);
        if (on) el.setAttribute('aria-selected', 'true');
        else el.removeAttribute('aria-selected');
      });
      paint(true);
      syncUrl();
    });
  });
  if (more) {
    more.addEventListener('click', function (event) {
      if (serverList) return;
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      shown += pageSize;
      paint(false);
    });
  }
  if (document.body.classList.contains('is-detail') && window.matchMedia('(max-width: 768px)').matches) {
    window.scrollTo(0, 0);
  }

  function bindKontaktCard() {
  document.querySelectorAll('[data-kt-threads]').forEach(function (slot) {
    if (slot.getAttribute('data-kt-threads-on') === '1') return;
    var url = slot.getAttribute('data-kt-threads') || '';
    if (!url) return;
    slot.setAttribute('data-kt-threads-on', '1');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.html) return;
        slot.outerHTML = data.html;
      })
      .catch(function () {});
  });
  document.querySelectorAll('[data-reveal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var slot = btn.getAttribute('data-slot') || '';
      var saveForm = btn.closest('form');
      var field = saveForm ? saveForm.querySelector('input[name="owner_pnr_' + slot + '"]') : null;
      var status = document.querySelector('[data-pnr-status]');
      if (!field || !saveForm) return;
      if (btn.getAttribute('data-open') === '1') {
        field.value = field.getAttribute('data-mask') || '';
        btn.textContent = 'Visa';
        btn.setAttribute('data-open', '0');
        if (status) { status.hidden = true; status.textContent = ''; }
        return;
      }
      var body = new FormData();
      var idInput = saveForm.querySelector('input[name="id"]');
      var csrf = saveForm.querySelector('input[name="_csrf"]');
      body.append('action', 'kontakter.pnr');
      body.append('id', idInput ? idInput.value : '');
      body.append('slot', slot);
      if (csrf) body.append('_csrf', csrf.value);
      btn.disabled = true;
      if (status) { status.hidden = true; status.textContent = ''; }
      fetch(saveForm.getAttribute('action') || location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        body: body,
        credentials: 'same-origin'
      }).then(function (res) { return res.json(); }).then(function (data) {
        btn.disabled = false;
        var pnr = data && data.row && data.row.personnummer ? String(data.row.personnummer) : '';
        if (!data || !data.ok || pnr === '') {
          if (status) { status.hidden = false; status.textContent = 'Kunde inte visa personnumret.'; }
          return;
        }
        field.setAttribute('data-mask', field.value);
        field.value = pnr;
        btn.textContent = 'Dölj';
        btn.setAttribute('data-open', '1');
      }).catch(function () {
        btn.disabled = false;
        if (status) { status.hidden = false; status.textContent = 'Kunde inte visa personnumret.'; }
      });
    });
  });

  var saveForm = root.querySelector('form.kt-form');
  var saveId = saveForm ? saveForm.querySelector('input[name="id"]') : null;
  if (saveForm && saveId && String(saveId.value || '').trim() !== '') {
    saveForm.setAttribute('data-kt-optimistic', '1');
    var smallNames = ['street', 'postal_code', 'city', 'fastighetsbeteckning', 'owner_name_1', 'owner_name_2', 'email', 'email2', 'email3', 'phone', 'phone2', 'ovrigt'];
    // Personnummer sparas bara med Spara-knappen, aldrig via autosave.
    var heavyNames = ['owner_pnr_1', 'owner_pnr_2'];
    var saveBtn = saveForm.querySelector('.kt-save button[type="submit"]');
    function fieldValue(name) {
      var el = saveForm.elements[name];
      if (!el) return '';
      if (el.length && el[0] && el[0].type === 'radio') {
        var on = saveForm.querySelector('input[name="' + name + '"]:checked');
        return on ? String(on.value || '') : '';
      }
      return String(el.value || '');
    }
    function readSnap() {
      var snap = { contact_person: fieldValue('contact_person') };
      smallNames.concat(heavyNames).forEach(function (name) { snap[name] = fieldValue(name); });
      return snap;
    }
    function writeSnap(snap, names) {
      names.forEach(function (name) {
        var el = saveForm.elements[name];
        if (!el || snap[name] == null) return;
        if (el.length && el[0] && el[0].type === 'radio') {
          Array.prototype.forEach.call(el, function (radio) { radio.checked = radio.value === snap[name]; });
          return;
        }
        el.value = snap[name];
      });
    }
    function paintHeading(snap) {
      var h = document.querySelector('.kt-detail h2.crm-section-title');
      if (!h) return;
      var street = String(snap.street || '').trim();
      var city = String(snap.city || '').trim();
      var name = String(snap.owner_name_1 || '').trim();
      var text = street && city ? street + ', ' + city : (street || name || 'Kontakt');
      if (h.firstChild && h.firstChild.nodeType === 3) h.firstChild.nodeValue = text;
      else h.insertBefore(document.createTextNode(text), h.firstChild);
    }
    function setSaveBusy(on) {
      if (on) {
        if (saveForm.getAttribute('data-busy') === '1') return false;
        saveForm.setAttribute('data-busy', '1');
        if (saveBtn) {
          saveBtn.setAttribute('data-busy', '1');
          saveBtn.setAttribute('aria-busy', 'true');
          saveBtn.disabled = true;
        }
        return true;
      }
      saveForm.removeAttribute('data-busy');
      if (saveBtn) {
        saveBtn.removeAttribute('data-busy');
        saveBtn.removeAttribute('aria-busy');
        saveBtn.disabled = false;
      }
      return true;
    }
    var saved = readSnap();
    var saveTimer = 0;
    var saveGen = 0;
    function heavyDirty() {
      return heavyNames.some(function (name) { return fieldValue(name) !== saved[name]; });
    }
    function savedText(data) {
      return (data && data.flash) ? String(data.flash) : 'Sparad.';
    }
    function push(includeHeavy) {
      if (!includeHeavy && heavyDirty()) return;
      var now = readSnap();
      var same = smallNames.concat(['contact_person']).concat(includeHeavy ? heavyNames : []).every(function (name) {
        return now[name] === saved[name];
      });
      if (same) return;
      if (includeHeavy && !setSaveBusy(true)) return;
      paintHeading(now);
      var gen = ++saveGen;
      var body = new FormData(saveForm);
      if (!includeHeavy) {
        body.delete('owner_pnr_1');
        body.delete('owner_pnr_2');
        body.append('kt_autosave', '1');
      }
      fetch(saveForm.getAttribute('action') || location.href, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok || !data || data.ok === false) {
            throw new Error((data && data.error) ? String(data.error) : 'Kunde inte spara.');
          }
          return data;
        });
      }).then(function (data) {
        if (includeHeavy) setSaveBusy(false);
        if (includeHeavy) ktSaveNote(savedText(data), false);
        if (gen !== saveGen) return;
        saved = now;
      }).catch(function (err) {
        if (includeHeavy) setSaveBusy(false);
        if (gen !== saveGen) return;
        writeSnap(saved, includeHeavy ? smallNames.concat(heavyNames).concat(['contact_person']) : smallNames.concat(['contact_person']));
        paintHeading(saved);
        ktSaveNote(err && err.message ? err.message : 'Kunde inte spara.', true);
      });
    }
    function soon() {
      window.clearTimeout(saveTimer);
      saveTimer = window.setTimeout(function () { push(false); }, 400);
    }
    smallNames.forEach(function (name) {
      var el = saveForm.elements[name];
      if (!el) return;
      el.addEventListener('input', soon);
      el.addEventListener('change', function () { window.clearTimeout(saveTimer); push(false); });
    });
    saveForm.querySelectorAll('input[name="contact_person"]').forEach(function (radio) {
      radio.addEventListener('change', function () { window.clearTimeout(saveTimer); push(false); });
    });
    saveForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (saveForm.getAttribute('data-busy') === '1') return;
      window.clearTimeout(saveTimer);
      push(true);
    });
  }
  var remBtn = document.querySelector('[data-kt-reminder]');
  var remDlg = document.getElementById('kt-reminder');
  if (remBtn && remDlg) {
    remBtn.addEventListener('click', function () {
      if (remDlg.showModal) remDlg.showModal();
      else remDlg.setAttribute('open', '');
    });
    remDlg.querySelectorAll('[data-kt-reminder-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (remDlg.close) remDlg.close();
        else remDlg.removeAttribute('open');
      });
    });
  }
  }
  bindKontaktCard();
  window.jeabKontaktBindCard = bindKontaktCard;
})();
</script>
<?php endif;
$html = (string) ob_get_clean();
if ($ktFragment) {
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('kontakt', microtime(true) - ($GLOBALS['_JEAB_KONTAKT_T0'] ?? microtime(true)));
    }
    jeab_json(['ok' => $cur !== null, 'html' => $html]);
}
return $html;
