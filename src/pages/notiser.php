<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

/** @var array $rows */
/** @var array $visible */
/** @var array $counts */
/** @var array $filter */
$rows = $rows ?? [];
$visible = $visible ?? $rows;
$counts = $counts ?? ['all' => count($rows), 'Aktiv' => 0, 'Av' => 0, 'Problem' => 0];
$filter = $filter ?? ['co' => '', 'ch' => '', 'st' => '', 'pr' => ''];
$filter['flik'] = (string) ($filter['flik'] ?? 'notiser');
if (!in_array($filter['flik'], ['alla', 'notiser', 'offerter'], true)) {
    $filter['flik'] = 'notiser';
}
$flik = (string) $filter['flik'];
$offerAllowed = function_exists('jeab_offert_utskick_allowed') && jeab_offert_utskick_allowed();
$showNotiser = $showNotiser ?? ($flik !== 'offerter');
$showOffer = $showOffer ?? ($flik !== 'notiser' && $offerAllowed);
$offerDenied = $offerDenied ?? ($flik === 'offerter' && !$offerAllowed);
$offerResult = $offerResult ?? null;
$offerPost = is_array($offerPost ?? null) ? $offerPost : [];
$postTo = jeab_notiser_url(['flik' => $flik]);

$chip = static function (string $label, array $next, bool $on) use ($filter): string {
    $class = 'chip' . ($on ? ' chip-primary is-on' : '');
    $set = '';
    foreach ($next as $key => $value) {
        $set = ' data-set="' . e((string) $key) . '" data-val="' . e((string) $value) . '"';
        break;
    }
    return '<a class="' . $class . '"' . $set . ' href="' . e(jeab_notiser_url(array_merge($filter, $next))) . '">' . e($label) . '</a>';
};

$tab = static function (string $label, string $nextFlik) use ($filter): string {
    $on = (string) ($filter['flik'] ?? '') === $nextFlik;
    $class = 'chip' . ($on ? ' chip-primary is-on' : '');
    return '<a class="' . $class . '" data-set="flik" data-val="' . e($nextFlik) . '" href="' . e(jeab_notiser_url(array_merge($filter, ['flik' => $nextFlik]))) . '">' . e($label) . '</a>';
};

$keep = static function (array $filter): string {
    $html = '';
    foreach (['co', 'ch', 'st', 'pr', 'flik'] as $key) {
        $value = trim((string) ($filter[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
    }
    return $html;
};

ob_start();
?>
<?php /* crm-app, apple-list och is-today ägs av Påminnelsernas skript. */ ?>
<div class="notis-page">
    <header class="crm-top">
        <div class="crm-top-text">
            <p class="apple-eyebrow">Övrigt</p>
            <h1 class="crm-title">Utskick</h1>
        </div>
        <a class="chip" href="<?= e(jeab_url('index.php?p=links')) ?>">Övrigt</a>
    </header>

    <div class="notis-filters" role="navigation" aria-label="Utskick">
        <?= $tab('Alla', 'alla') ?>
        <?= $tab('Notiser', 'notiser') ?>
        <?php if ($offerAllowed): ?>
            <?= $tab('Offerter', 'offerter') ?>
        <?php endif; ?>
    </div>

    <div class="notis-notiser" data-notis-pane="notiser"<?= $flik === 'offerter' ? ' hidden' : '' ?>>
    <h2 class="notis-section">Notiser till slutkund</h2>
    <p class="notis-lead">Texten och PDF:en här är det som skickas nästa gång, från alla flöden. Platshållare byts ut vid utskicket.</p>

    <div class="smart-grid crm-stats" role="navigation" aria-label="Status">
        <a class="smart-tile<?= ($filter['st'] ?? '') === '' ? ' is-on' : '' ?>" data-set="st" data-val="" href="<?= e(jeab_notiser_url(array_merge($filter, ['st' => '']))) ?>">
            <b><?= (int) $counts['all'] ?></b>
            <span>Alla</span>
        </a>
        <a class="smart-tile<?= ($filter['st'] ?? '') === 'Aktiv' ? ' is-on' : '' ?>" data-set="st" data-val="Aktiv" href="<?= e(jeab_notiser_url(array_merge($filter, ['st' => 'Aktiv']))) ?>">
            <b><?= (int) $counts['Aktiv'] ?></b>
            <span>Aktiv</span>
        </a>
        <a class="smart-tile<?= ($filter['st'] ?? '') === 'Av' ? ' is-on' : '' ?>" data-set="st" data-val="Av" href="<?= e(jeab_notiser_url(array_merge($filter, ['st' => 'Av']))) ?>">
            <b><?= (int) $counts['Av'] ?></b>
            <span>Av</span>
        </a>
        <a class="smart-tile<?= ($filter['st'] ?? '') === 'Problem' ? ' is-on' : '' ?>" data-set="st" data-val="Problem" href="<?= e(jeab_notiser_url(array_merge($filter, ['st' => 'Problem']))) ?>">
            <b><?= (int) $counts['Problem'] ?></b>
            <span>Problem</span>
        </a>
    </div>

    <div class="notis-filters" role="navigation" aria-label="Produkt, bolag och kanal">
        <?= $chip('Alla produkter', ['pr' => ''], ($filter['pr'] ?? '') === '') ?>
        <?php foreach (jeab_notiser_product_filters() as $productKey => $productLabel): ?>
            <?= $chip($productLabel, ['pr' => $productKey], ($filter['pr'] ?? '') === $productKey) ?>
        <?php endforeach; ?>
        <?= $chip('Alla bolag', ['co' => ''], ($filter['co'] ?? '') === '') ?>
        <?= $chip('Järfälla', ['co' => 'jarfalla'], ($filter['co'] ?? '') === 'jarfalla') ?>
        <?= $chip('Malmö', ['co' => 'malmo'], ($filter['co'] ?? '') === 'malmo') ?>
        <?= $chip('Mejl', ['ch' => 'mail'], ($filter['ch'] ?? '') === 'mail') ?>
        <?= $chip('SMS', ['ch' => 'sms'], ($filter['ch'] ?? '') === 'sms') ?>
    </div>

    <?php
    $notisMatch = static function (array $row) use ($filter): bool {
        if (($filter['co'] ?? '') !== '' && (string) ($row['company'] ?? '') !== $filter['co']) {
            return false;
        }
        if (($filter['ch'] ?? '') !== '' && (string) ($row['channel'] ?? '') !== $filter['ch']) {
            return false;
        }
        if (($filter['st'] ?? '') !== '' && (string) ($row['status'] ?? '') !== $filter['st']) {
            return false;
        }
        if (($filter['pr'] ?? '') !== '' && (string) ($row['product'] ?? '') !== $filter['pr']) {
            return false;
        }

        return true;
    };
    $notisShown = 0;
    foreach ($rows as $row) {
        if (is_array($row) && $notisMatch($row)) {
            $notisShown++;
        }
    }
    ?>
    <p class="notis-empty" data-notis-empty<?= $notisShown === 0 ? '' : ' hidden' ?>>Inga mallar i det här urvalet.</p>

    <ul class="notis-list">
        <?php foreach ($rows as $row): ?>
            <?php
            $status = (string) ($row['status'] ?? '');
            $statusClass = $status === 'Aktiv' ? 'is-aktiv' : ($status === 'Problem' ? 'is-problem' : 'is-av');
            $channel = (string) ($row['channel'] ?? '');
            ?>
            <?php $enabled = !array_key_exists('enabled', $row) || !empty($row['enabled']); ?>
            <li class="notis-card <?= e($statusClass) ?><?= $enabled ? '' : ' is-off' ?>" data-co="<?= e((string) ($row['company'] ?? '')) ?>" data-ch="<?= e($channel) ?>" data-st="<?= e($status) ?>" data-pr="<?= e((string) ($row['product'] ?? '')) ?>"<?= $notisMatch($row) ? '' : ' hidden' ?>>
                <form class="notis-switch" method="post" action="<?= e($postTo) ?>">
                    <?= jeab_csrf_field() ?>
                    <input type="hidden" name="action" value="notiser.toggle">
                    <input type="hidden" name="id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                    <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                    <?= $keep($filter) ?>
                    <button class="btn notis-toggle<?= $enabled ? ' is-on' : '' ?>" type="submit" aria-pressed="<?= $enabled ? 'true' : 'false' ?>" title="<?= e($enabled ? 'Läge: På. Klicka för att stänga av.' : 'Läge: Av. Klicka för att sätta på.') ?>" aria-label="<?= e($enabled ? 'Läge på. Stäng av ' . (string) ($row['name'] ?? 'notisen') : 'Läge av. Sätt på ' . (string) ($row['name'] ?? 'notisen')) ?>"><?= $enabled ? 'På' : 'Av' ?></button>
                </form>
                <details class="notis-fold">
                    <summary>
                        <span class="notis-name"><?= e((string) ($row['name'] ?? '')) ?></span>
                        <span class="notis-line">
                            <span class="chip"><?= e($channel === 'sms' ? 'SMS' : 'Mejl') ?></span>
                            <?php if ((string) ($row['company_label'] ?? '') !== ''): ?><span class="chip"><?= e((string) $row['company_label']) ?></span><?php endif; ?>
                            <span class="notis-status <?= e($statusClass) ?>"><?= e($status) ?></span>
                            <?php if ($status === 'Problem' && (string) ($row['problem'] ?? '') !== ''): ?>
                                <span class="notis-why"><?= e((string) $row['problem']) ?></span>
                            <?php endif; ?>
                            <span class="notis-used">Senast <?= (string) ($row['last_used'] ?? '') !== '' ? e((string) $row['last_used']) : 'aldrig' ?></span>
                            <?php if ((string) ($row['last_test'] ?? '') !== ''): ?>
                                <span class="notis-used">Test <?= e((string) $row['last_test']) ?></span>
                            <?php endif; ?>
                        </span>
                    </summary>
                    <div class="notis-more">
                <?php if ((string) ($row['problem'] ?? '') !== ''): ?>
                    <p class="notis-problem"><?= e((string) $row['problem']) ?></p>
                <?php endif; ?>
                <p class="notis-sender"><span>Avsändare</span> <?= (string) ($row['sender'] ?? '') !== '' ? e((string) $row['sender']) : 'saknas' ?></p>
                <p class="notis-trigger"><?= e((string) ($row['trigger'] ?? '')) ?></p>
                <p class="notis-source"><?= e((string) ($row['edit'] ?? '')) ?><?php if ((string) ($row['source'] ?? '') !== ''): ?> · <?= e((string) $row['source']) ?><?php endif; ?> · Senaste 30 dagarna <?= (int) ($row['sends_30d'] ?? 0) ?></p>
                <?php if ((array) ($row['attachments'] ?? []) !== []): ?>
                    <p class="notis-body-label">Bilagor</p>
                    <ul class="notis-files">
                        <?php foreach ((array) $row['attachments'] as $att): ?>
                            <?php if (!is_array($att)) { continue; } ?>
                            <li>
                                <?php if ((string) ($att['href'] ?? '') !== '' && empty($att['missing'])): ?>
                                    <a href="<?= e((string) $att['href']) ?>"><?= e((string) ($att['name'] ?? 'bilaga')) ?></a>
                                <?php else: ?>
                                    <span><?= e((string) ($att['name'] ?? 'bilaga')) ?></span>
                                <?php endif; ?>
                                <small><?= !empty($att['missing']) ? 'saknas' : e(jeab_notiser_bytes_label((int) ($att['bytes'] ?? 0))) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($row['updated_by'])): ?>
                    <p class="notis-audit">Text ändrad av <?= e((string) $row['updated_by']) ?><?php if ((string) ($row['updated_at'] ?? '') !== ''): ?> · <?= e((string) $row['updated_at']) ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if (!empty($row['pdf_updated_by'])): ?>
                    <p class="notis-audit">PDF ändrad av <?= e((string) $row['pdf_updated_by']) ?><?php if ((string) ($row['pdf_updated_at'] ?? '') !== ''): ?> · <?= e((string) $row['pdf_updated_at']) ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if (!empty($row['editable'])): ?>
                    <form class="notis-edit" method="post" action="<?= e($postTo) ?>" enctype="multipart/form-data">
                        <?= jeab_csrf_field() ?>
                        <input type="hidden" name="action" value="notiser.save">
                        <input type="hidden" name="id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                        <label>
                            <span>Meddelande</span>
                            <textarea name="body" rows="8"><?= e((string) ($row['body'] ?? '')) ?></textarea>
                        </label>
                        <?php if ((string) ($row['pdf_key'] ?? '') !== ''): ?>
                            <label>
                                <span>PDF, högst 8 MB</span>
                                <input type="file" name="pdf" accept="application/pdf,.pdf">
                            </label>
                        <?php endif; ?>
                        <button class="btn" type="submit">Spara</button>
                        <?= $keep($filter) ?>
                    </form>
                    <?php if (!empty($row['can_restore'])): ?>
                        <form class="notis-restore" method="post" action="<?= e($postTo) ?>">
                            <?= jeab_csrf_field() ?>
                            <input type="hidden" name="action" value="notiser.restore">
                            <input type="hidden" name="id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                            <button class="btn" type="submit">Återställ text</button>
                            <?= $keep($filter) ?>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($row['pdf_can_restore'])): ?>
                        <form class="notis-restore" method="post" action="<?= e($postTo) ?>">
                            <?= jeab_csrf_field() ?>
                            <input type="hidden" name="action" value="notiser.restore_pdf">
                            <input type="hidden" name="id" value="<?= e((string) ($row['id'] ?? '')) ?>">
                            <button class="btn" type="submit">Återställ PDF</button>
                            <?= $keep($filter) ?>
                        </form>
                    <?php endif; ?>
                <?php elseif ((string) ($row['body'] ?? '') !== ''): ?>
                    <div class="notis-body">
                        <p class="notis-body-label">Meddelande</p>
                        <pre><?= e((string) $row['body']) ?></pre>
                    </div>
                <?php endif; ?>
                    </div>
                </details>
            </li>
        <?php endforeach; ?>
    </ul>
    </div>

    <?php if ($offerDenied): ?>
        <p class="notis-empty">Bara admin.</p>
    <?php endif; ?>
    <?php if ($offerAllowed && function_exists('jeab_offert_utskick_html')): ?>
        <div class="notis-offer" data-notis-pane="offerter"<?= $flik === 'notiser' ? ' hidden' : '' ?>>
        <?= jeab_offert_utskick_html(is_array($offerResult) ? $offerResult : null, $offerPost) ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
  var page = document.querySelector('.notis-page');
  if (!page) return;
  var state = { flik: 'alla', co: '', ch: '', st: '', pr: '' };
  try {
    var now = new URL(location.href).searchParams;
    state.flik = now.get('flik') || 'alla';
    state.co = now.get('co') || '';
    state.ch = now.get('ch') || '';
    state.st = now.get('st') || '';
    state.pr = now.get('pr') || '';
  } catch (e) {}
  function hrefFor(next) {
    var u = new URL(location.href);
    u.searchParams.set('p', 'utskick');
    ['flik', 'co', 'ch', 'st', 'pr'].forEach(function (key) {
      var value = next[key] || '';
      if (key === 'flik') value = value || 'alla';
      if (value === '' || (key === 'flik' && value === 'alla')) {
        if (key === 'flik') u.searchParams.set('flik', value || 'alla');
        else u.searchParams.delete(key);
      } else {
        u.searchParams.set(key, value);
      }
    });
    if (!u.searchParams.get('flik')) u.searchParams.set('flik', 'alla');
    return u.pathname + u.search;
  }
  function paint() {
    var shown = 0;
    page.querySelectorAll('.notis-card').forEach(function (card) {
      var on = (state.co === '' || card.getAttribute('data-co') === state.co)
        && (state.ch === '' || card.getAttribute('data-ch') === state.ch)
        && (state.st === '' || card.getAttribute('data-st') === state.st)
        && (state.pr === '' || card.getAttribute('data-pr') === state.pr);
      card.hidden = !on;
      if (on) shown += 1;
    });
    var empty = page.querySelector('[data-notis-empty]');
    if (empty) empty.hidden = shown !== 0;
    page.querySelectorAll('[data-notis-pane]').forEach(function (pane) {
      var name = pane.getAttribute('data-notis-pane') || '';
      pane.hidden = (state.flik === 'notiser' && name === 'offerter') || (state.flik === 'offerter' && name === 'notiser');
    });
    page.querySelectorAll('input[type="hidden"]').forEach(function (input) {
      if (Object.prototype.hasOwnProperty.call(state, input.name)) {
        input.value = state[input.name] || '';
      }
    });
    page.querySelectorAll('a[data-set]').forEach(function (link) {
      var key = link.getAttribute('data-set') || '';
      var val = link.getAttribute('data-val') || '';
      var same = (state[key] || '') === val;
      link.classList.toggle('is-on', same);
      link.classList.toggle('chip-primary', same && link.classList.contains('chip'));
      if (link.classList.contains('smart-tile')) {
        if (same) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      }
      var next = { flik: state.flik, co: state.co, ch: state.ch, st: state.st, pr: state.pr };
      next[key] = val;
      link.href = hrefFor(next);
    });
    try { history.pushState({ jeabShell: 1 }, '', hrefFor(state)); } catch (e) {}
  }
  document.addEventListener('click', function (event) {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var node = event.target;
    if (node && node.nodeType === 3) node = node.parentNode;
    var a = node && node.closest ? node.closest('a[data-set]') : null;
    if (!a || !page.contains(a)) return;
    var key = a.getAttribute('data-set') || '';
    if (!Object.prototype.hasOwnProperty.call(state, key)) return;
    state[key] = a.getAttribute('data-val') || '';
    paint();
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
})();
</script>
<?php
return ob_get_clean();
