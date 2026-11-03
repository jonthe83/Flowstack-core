<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

$contacts = jeab_inkorg_contacts();
$hiddenContacts = array_filter(jeab_inkorg_contacts(true), static fn($c): bool => is_array($c) && !empty($c['deleted_at']));
$sok = trim((string) ($_GET['q'] ?? ''));
$cid = (string) ($_GET['c'] ?? '');
$isNew = !empty($_GET['ny']);
$cur = $cid !== '' ? ($contacts[$cid] ?? null) : null;
$threads = ($cur !== null) ? jeab_inkorg_threads() : [];
$rows = array_filter($contacts, fn($c) => jeab_inkorg_contact_matches($c, $sok));
uasort($rows, fn($a, $b) => strcasecmp((string) ($a['name'] ?: ($a['emails'][0] ?? '')), (string) ($b['name'] ?: ($b['emails'][0] ?? ''))));
$post = e(jeab_url('index.php?p=inkorg-kontakter'));
$statuses = jeab_inkorg_statuses();
$show = $cur !== null || $isNew;
$c = $cur ?? ['name' => '', 'address' => '', 'town' => '', 'emails' => [], 'mobile' => '', 'notes' => ''];

ob_start();
?>
<div class="kontakt-page<?= $show ? ' has-open' : '' ?>">
<style>
.kontakt-page { display: grid; grid-template-columns: minmax(280px, 380px) minmax(0, 1fr); gap: 16px; align-items: start; }
.kontakt-page * { overflow-wrap: anywhere; }
.kontakt-page h1 { grid-column: 1 / -1; margin: 0; font-size: 22px; text-align: left; }
.k-col { display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.kontakt-page input, .kontakt-page textarea { min-height: 44px; border-radius: 6px; background: var(--bg-field); color: var(--text-primary); border: 1px solid var(--border); padding: 0 12px; font: inherit; width: 100%; box-sizing: border-box; }
.kontakt-page textarea { padding: 12px; }
.k-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.k-list a { display: flex; flex-direction: column; gap: 2px; padding: 12px; border-radius: 6px; background: #1C1C1E; color: var(--text-primary); text-decoration: none; }
.k-list a.is-on { outline: 2px solid var(--brand); }
.k-muted { color: var(--text-secondary); font-size: 14px; }
.k-plate { background: #1C1C1E; border-radius: 6px; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.k-plate h2, .k-plate h3 { margin: 0; font-size: 18px; }
.k-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.k-form label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--text-secondary); }
.k-form .full { grid-column: 1 / -1; }
.k-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.k-row .btn { width: auto; }
.k-cases { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.k-cases a { color: var(--brand); }
.k-back { display: none; }
@media (max-width: 760px) {
  .kontakt-page { grid-template-columns: minmax(0, 1fr); }
  .has-open .k-listcol { display: none; }
  .k-form { grid-template-columns: 1fr; }
  .k-back { display: flex; position: sticky; top: 0; z-index: 5; align-items: center; min-height: 44px; padding: 0 12px; background: var(--bg-glass, #0D0D0F); color: var(--brand); text-decoration: none; font-weight: 600; border-bottom: 1px solid var(--border); }
}
</style>
<h1>Kontakter</h1>
<div class="k-col k-listcol">
    <?= jeab_flash_html($flash ?? null) ?>
    <?= jeab_error_html($error ?? null) ?>
    <form method="get" action="<?= e(jeab_url('index.php')) ?>" role="search">
        <input type="hidden" name="p" value="inkorg-kontakter">
        <input type="search" name="q" value="<?= e($sok) ?>" placeholder="Sök namn, e-post, mobil, ort" aria-label="Sök kontakt">
    </form>
    <div class="k-row"><span class="k-muted">Visar <?= count($rows) ?> av <?= count($contacts) ?></span><?php if ($sok !== ''): ?><a href="<?= $post ?>">Återställ</a><?php endif; ?></div>
    <div class="k-row"><a class="btn btn-ghost" href="<?= e(jeab_url('index.php?p=inkorg-kontakter&ny=1')) ?>">Ny kontakt</a><a class="btn btn-ghost" href="<?= e(jeab_url('index.php?p=inkorg')) ?>">Till Inkorg</a></div>
    <?php if (!$rows): ?><div class="k-plate k-muted">Inga kontakter.</div><?php else: ?>
    <ul class="k-list">
        <?php foreach ($rows as $id => $r): ?>
        <li><a href="<?= e(jeab_url('index.php?' . http_build_query(array_filter(['p' => 'inkorg-kontakter', 'c' => (string) $id, 'q' => $sok])))) ?>"<?= $id === $cid ? ' class="is-on" aria-current="true"' : '' ?>>
            <strong><?= e((string) ($r['name'] !== '' ? $r['name'] : ($r['emails'][0] ?? '(namnlös)'))) ?></strong>
            <span class="k-muted"><?= e(implode(' · ', array_filter([(string) ($r['emails'][0] ?? ''), (string) ($r['mobile'] ?? ''), (string) ($r['town'] ?? '')]))) ?><?= !empty($r['test']) ? ' · test' : '' ?></span>
        </a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($hiddenContacts): ?>
    <div class="k-plate">
        <h3>Dolda kontakter</h3>
        <ul class="k-list">
        <?php foreach ($hiddenContacts as $hid => $hr): ?>
            <li>
                <span class="k-muted"><?= e((string) ($hr['name'] !== '' ? $hr['name'] : ($hr['emails'][0] ?? $hid))) ?> · dold <?= e(substr((string) $hr['deleted_at'], 0, 16)) ?><?= ($hr['deleted_by'] ?? '') !== '' ? ' av ' . e((string) $hr['deleted_by']) : '' ?></span>
                <form method="post" action="<?= $post ?>"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.contact_restore"><input type="hidden" name="id" value="<?= e((string) $hid) ?>"><button class="btn btn-ghost" type="submit">Återställ</button></form>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<div class="k-col">
<?php if (!$show): ?>
    <div class="k-plate k-muted">Välj en kontakt i listan.</div>
<?php else: ?>
    <a class="k-back" href="<?= $post ?>">← Tillbaka till listan</a>
    <div class="k-plate">
        <h2><?= $cur ? e((string) ($c['name'] !== '' ? $c['name'] : ($c['emails'][0] ?? ''))) : 'Ny kontakt' ?></h2>
        <form class="k-form" method="post" action="<?= $post ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="inkorg.contact_save">
            <input type="hidden" name="id" value="<?= e($cur ? $cid : '') ?>">
            <label>Namn<input name="name" value="<?= e((string) $c['name']) ?>" maxlength="120"></label>
            <label>Mobil<input name="mobile" type="tel" value="<?= e((string) $c['mobile']) ?>" inputmode="tel" autocomplete="tel" placeholder="07x eller +46"></label>
            <label class="full">E-post (flera med komma)<input name="emails" type="email" multiple value="<?= e(implode(', ', (array) $c['emails'])) ?>" inputmode="email" autocomplete="email"></label>
            <label>Adress<input name="address" value="<?= e((string) $c['address']) ?>" maxlength="200"></label>
            <label>Ort<input name="town" value="<?= e((string) $c['town']) ?>" maxlength="80"></label>
            <label class="full">Anteckningar<textarea name="notes" rows="4"><?= e((string) $c['notes']) ?></textarea></label>
            <div class="full k-row"><button class="btn" type="submit">Spara kontakt</button></div>
        </form>
        <?php if ($cur): ?>
        <form method="post" action="<?= $post ?>" onsubmit="return confirm('Dölj kontakten? Den kan återställas. Ärendena finns kvar.')"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.contact_delete"><input type="hidden" name="id" value="<?= e($cid) ?>"><button class="btn btn-ghost" type="submit" style="width:auto">Dölj kontakt</button></form>
        <?php endif; ?>
    </div>
    <?php if ($cur): $cases = jeab_inkorg_contact_threads($cur, $threads); ?>
    <div class="k-plate">
        <h3>Ärenden (<?= count($cases) ?>)</h3>
        <?php if (!$cases): ?><div class="k-muted">Inga ärenden.</div><?php else: ?>
        <ul class="k-cases"><?php foreach ($cases as $t): ?><li><a href="<?= e(jeab_url('index.php?p=inkorg&id=' . rawurlencode($t['id']))) ?>"><?= e((string) ($t['first']['subject'] !== '' ? $t['first']['subject'] : '(ingen rubrik)')) ?></a> <span class="k-muted"><?= e(jeab_inkorg_list_time($t['updated_at'])) ?> · <?= e($statuses[$t['status']] ?? $t['status']) ?><?= $t['deleted'] ? ' · papperskorg' : '' ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>
</div>
<?php
return ob_get_clean();
