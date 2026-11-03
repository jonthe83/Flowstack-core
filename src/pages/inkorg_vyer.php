<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

$GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'] = 1;
$GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = 1;

$views = jeab_inkorg_views();
$tags = jeab_inkorg_tags();
$edit = (string) ($_GET['vy'] ?? '');
$post = e(jeab_url('index.php?p=inkorg-vyer'));
$choices = jeab_inkorg_view_choices();
$descView = function (array $v) use ($tags): string {
    if (jeab_inkorg_view_is_parkerat($v)) {
        return 'Parkerade ärenden';
    }
    $sum = jeab_inkorg_view_summary($v);
    if ($sum !== '') {
        return $sum;
    }
    $f = (array) ($v['filter'] ?? []);
    $p = [];
    $tag = (string) ($f['tag'] ?? '');
    if ($tag !== '' && $tag !== 'skrap') {
        $p[] = 'etikett ' . ($tags[$tag]['name'] ?? $tag);
    }
    $any = [];
    $sawIngen = false;
    foreach ((array) ($f['tags_any'] ?? []) as $one) {
        $one = (string) $one;
        if ($one === 'ingen') {
            $sawIngen = true;
            continue;
        }
        if ($one === '' || $one === 'skrap') {
            continue;
        }
        $any[] = (string) ($tags[$one]['name'] ?? $one);
    }
    if ($sawIngen) {
        $p[] = 'ingen produktetikett';
    }
    if ($any) {
        $p[] = 'etikett ' . implode(' eller ', $any);
    }
    $types = [];
    $oneType = (string) ($f['type'] ?? '');
    if ($oneType !== '' && $oneType !== 'Skräp') {
        $types[] = $oneType;
    }
    foreach (array_merge((array) ($f['types'] ?? []), []) as $one) {
        $one = (string) $one;
        if ($one !== '' && $one !== 'Skräp') {
            $types[] = $one;
        }
    }
    if ($types) {
        $p[] = 'typ ' . implode(' eller ', array_unique($types));
    }
    $statuses = function_exists('jeab_inkorg_statuses') ? jeab_inkorg_statuses() : [];
    $st = [];
    foreach ((array) ($f['status'] ?? []) as $s) {
        $s = (string) $s;
        if (str_starts_with($s, 'waiting')) {
            continue;
        }
        $label = (string) ($statuses[$s] ?? $s);
        if (preg_match('/väntar|papperskorg|skräp/iu', $label)) {
            continue;
        }
        $st[] = $label;
    }
    if ($st) {
        $p[] = 'status ' . implode(' eller ', $st);
    }
    if (!empty($f['review'])) {
        $p[] = 'behöver granskas';
    }
    if (!empty($f['assignee'])) {
        $who = (string) $f['assignee'];
        $p[] = $who === 'me' ? 'tilldelade mig' : ($who === 'none' ? 'otilldelade' : 'tilldelade ' . (jeab_inkorg_staff()[$who] ?? $who));
    }
    if (!empty($f['historik'])) {
        $p[] = 'historik';
    }

    return $p ? 'Visar ärenden med ' . implode(' och ', $p) : 'Visar ärenden med befintligt filter';
};
$blank = ['id' => '', 'name' => '', 'owner' => '', 'menu' => true, 'company' => '', 'filter' => ['show' => []]];
$rows = array_values(array_filter($views, fn($v) => is_array($v) && !jeab_inkorg_view_hidden_in_editor($v)));
$jeabVyerCountT0 = microtime(true);
$viewCounts = function_exists('jeab_inkorg_settings_view_counts') ? jeab_inkorg_settings_view_counts($rows, false) : [];
if (function_exists('jeab_timing_span')) {
    jeab_timing_span('vyer_counts', microtime(true) - $jeabVyerCountT0);
}
$jeabVyerHtmlT0 = microtime(true);
if ($edit === 'ny') {
    $rows[] = $blank;
}

ob_start();
?>
<div class="vyer-page">
<div class="head">
    <div>
        <?php $vyBox = function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : 'jarfalla'; ?>
        <p class="apple-eyebrow"><?= e($vyBox === 'malmo' ? 'Malmö' : 'Järfälla') ?></p>
        <h1 class="crm-title">Vyer och etiketter</h1>
    </div>
    <a class="vy-back" href="<?= e(jeab_url('index.php?p=inkorg')) ?>">← Inkorg</a>
</div>
<?= jeab_flash_html($flash ?? null) ?>
<?= jeab_error_html($error ?? null) ?>

<h2 id="vyer">Vyer</h2>
<p class="vy-desc">Vyer med «Visa i menyn» syns i vänstermenyn i Inkorg, i den här ordningen. Dolda vyer öppnas härifrån med Öppna. Ägaren får vyn som standard när hen öppnar Inkorg.</p>
<ul class="vy-list">
    <?php foreach ($rows as $v):
        $vf = (array) ($v['filter'] ?? []);
        $isEdit = $edit === (string) $v['id'] || ($edit === 'ny' && $v['id'] === '');
        $parkerat = jeab_inkorg_view_is_parkerat($v);
        $hiddenFilter = !$parkerat && jeab_inkorg_view_has_hidden_filter($v);
    ?>
    <li class="vy">
        <?php if ($isEdit):
            $picked = [];
            foreach (jeab_inkorg_view_show_selected($v) as $item) {
                $picked[$item[0] . ':' . $item[1]] = true;
            }
            $legacy = !$parkerat && !array_key_exists('show', $vf) && $v['id'] !== '';
        ?>
        <form class="vy-form" method="post" action="<?= $post ?>">
            <div class="plate">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="inkorg.view_save">
                <input type="hidden" name="view_form" value="show">
                <input type="hidden" name="id" value="<?= e((string) $v['id']) ?>">
                <?php if ($parkerat || $hiddenFilter): ?><input type="hidden" name="keep_filter" value="1"><?php endif; ?>
                <label class="full">Namn<input type="text" name="name" value="<?= e((string) $v['name']) ?>" maxlength="40"></label>
                <label class="vy-check full"> <input type="checkbox" name="menu" value="1"<?= ($v['menu'] ?? true) !== false ? ' checked' : '' ?>>Visa i menyn i Inkorg</label>
                <label>Bolag<select name="company"><option value="">Båda</option><option value="jarfalla"<?= ($v['company'] ?? '') === 'jarfalla' ? ' selected' : '' ?>>Järfälla</option><option value="malmo"<?= ($v['company'] ?? '') === 'malmo' ? ' selected' : '' ?>>Malmö</option></select></label>
                <label>Ägare (öppnas som standard för)<select name="owner"><option value="">(ingen)</option><?php foreach (jeab_people() as $pp): ?><option value="<?= e((string) $pp['id']) ?>"<?= ($v['owner'] ?? '') === $pp['id'] ? ' selected' : '' ?>><?= e((string) $pp['name']) ?></option><?php endforeach; ?></select></label>
                <?php if ($parkerat || $hiddenFilter): ?>
                <p class="vy-note full"><?= $parkerat ? 'Filtret lämnas som det är.' : 'Filtret har villkor som krysslistan inte visar. Det lämnas som det är.' ?></p>
                <?php else: ?>
                <fieldset class="vy-choice full">
                    <legend>Visa ärenden som är</legend>
                    <div class="vy-checks">
                        <?php foreach ($choices as $c): $key = $c['kind'] . ':' . $c['value']; ?>
                        <label class="vy-check"><input type="checkbox" name="show[]" value="<?= e($key) ?>"<?= isset($picked[$key]) ? ' checked' : '' ?>><?= e($c['label']) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <p class="vy-note full"><?= $legacy ? 'Sparar du byts filtret till kryssen. ' : '' ?>Inget ikryssat visar alla ärenden.</p>
                <?php endif; ?>
                <div class="full vy-row"><button class="vy-save" type="submit">Spara vy</button><a class="vy-ghost" href="<?= $post ?>">Avbryt</a></div>
            </div>
        </form>
        <?php else:
            $rawCount = $viewCounts[(string) $v['id']] ?? null;
            $n = is_numeric($rawCount) && (int) $rawCount >= 0 ? (string) (int) $rawCount : '–';
            $extra = [];
            if (($v['menu'] ?? true) === false) {
                $extra[] = 'Dold i menyn';
            }
            if (!empty($v['company'])) {
                $extra[] = $v['company'] === 'malmo' ? 'Malmö' : 'Järfälla';
            }
            if (!empty($v['owner'])) {
                $extra[] = 'standardvy för ' . (string) (jeab_inkorg_staff()[$v['owner']] ?? $v['owner']);
            }
        ?>
        <span class="vy-main">
            <span class="vy-name"><?= e((string) $v['name']) ?> <span class="vy-count">(<?= e($n) ?>)</span></span>
            <span class="vy-desc"><?= e($descView($v) . ($extra ? ' · ' . implode(' · ', $extra) : '')) ?></span>
        </span>
        <span class="vy-actions">
            <a class="vy-link" href="<?= e(jeab_url('index.php?p=inkorg&view=' . rawurlencode((string) $v['id']))) ?>">Öppna</a>
            <a class="vy-ghost" href="<?= e(jeab_url('index.php?p=inkorg-vyer&vy=' . rawurlencode((string) $v['id']))) ?>">Ändra</a>
            <form method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort vyn?')"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.view_delete"><input type="hidden" name="id" value="<?= e((string) $v['id']) ?>"><button class="vy-del" type="submit">Ta bort</button></form>
        </span>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<?php if ($edit !== 'ny'): ?><a class="vy-ghost" href="<?= e(jeab_url('index.php?p=inkorg-vyer&vy=ny')) ?>">Ny vy</a><?php endif; ?>

<h2 id="etiketter">Etiketter</h2>
<p class="vy-desc">Ett ärende kan ha flera etiketter. Sätt dem i ärendet eller låt en regel lägga till dem.</p>
<ul class="vy-list">
    <?php foreach ($tags as $tid => $tg):
        if ((string) $tid === 'skrap' || in_array(mb_strtolower((string) $tg['name']), ['skräp', 'papperskorg', 'väntar på offert'], true)) {
            continue;
        }
    ?>
    <li class="vy">
        <form class="vy-row" method="post" action="<?= $post ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="inkorg.tag_save">
            <input type="hidden" name="tag_id" value="<?= e((string) $tid) ?>">
            <input class="vy-field" type="text" name="tag_name" value="<?= e($tg['name']) ?>" aria-label="Namn">
            <button class="vy-ghost" type="submit">Spara</button>
        </form>
        <form method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort etiketten?')"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.tag_delete"><input type="hidden" name="tag_id" value="<?= e((string) $tid) ?>"><button class="vy-del" type="submit">Ta bort</button></form>
    </li>
    <?php endforeach; ?>
    <li class="vy">
        <form class="vy-row" method="post" action="<?= $post ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="action" value="inkorg.tag_save">
            <input class="vy-field" type="text" name="tag_name" placeholder="Ny etikett" aria-label="Ny etikett">
            <button class="vy-ghost" type="submit">Lägg till</button>
        </form>
    </li>
</ul>
</div>
<?php
if (function_exists('jeab_timing_span')) {
    jeab_timing_span('vyer_html', microtime(true) - ($jeabVyerHtmlT0 ?? microtime(true)));
}
return ob_get_clean();
