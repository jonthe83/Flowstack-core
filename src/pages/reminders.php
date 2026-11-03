<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var array $reminders */
/** @var array $visible */
/** @var array $counts */
/** @var array $tags */
/** @var string $view */
/** @var string|null $activeTag */
/** @var string $search */

$view = $view ?? 'today';
$counts = $counts ?? ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
$tags = $tags ?? [];
$activeTag = $activeTag ?? null;
$search = $search ?? '';
$visible = $visible ?? ($reminders ?? []);
$visibleTotal = isset($visibleTotal) ? (int) $visibleTotal : count($visible);
$visibleNext = isset($visibleNext) ? (int) $visibleNext : null;
/** @var string|null $flash */
/** @var string|null $error */

$titles = [
    'today' => 'Idag',
    'scheduled' => 'Schemalagda',
    'all' => 'Alla',
    'waiting' => 'Väntande',
    'flagged' => 'Väntande',
    'done' => 'Slutförda',
];
$viewTitle = $titles[$view] ?? 'Alla';
$dayNum = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('j');
$company = jeab_selected_company();
$whoFilter = jeab_reminder_who_filter();
// Inget namn valt (Alla valt, eller ingen person bakom inloggningen) = Alla är på.
$whoAllOn = $whoFilter === null;
$tagFilter = $tagFilter ?? jeab_reminder_tag_filter();
$tagMode = $tagFilter['mode'] ?? 'none';
$activeTag = $tagMode === 'tag' ? ($tagFilter['tag'] ?? null) : null;
$tagQuery = jeab_reminder_tag_query($tagFilter);
$companyRows = isset($remindersAll) && is_array($remindersAll) ? $remindersAll : [];
$whoCounts = (isset($whoCountsReady) && is_array($whoCountsReady) && $whoCountsReady !== [])
    ? $whoCountsReady
    : jeab_reminder_who_counts($companyRows, $company, $view, $tagFilter, $search);
$whoDefault = $whoFilter ?? (function_exists('jeab_reminder_mina_me_name') ? jeab_reminder_mina_me_name($company) : jeab_reminder_who_me_name($company));
$createAction = jeab_reminders_url(['open' => null]);
// Sök visar träffar i alla vyer (#261) men flikarna hoppar inte till Alla; rensa = tillbaka dit man var.
$searching = $search !== '';
$homeView = jeab_reminder_view();
$idagLeaveUrl = jeab_reminders_url(['v' => $homeView, 'tag' => 'none', 'q' => '', 'open' => null]);
$tabTag = $searching ? 'none' : $tagQuery;
$tabUrl = static fn (string $v): string => jeab_reminders_url(['v' => $v, 'tag' => $tabTag, 'q' => '', 'open' => null]);
$tabOn = static fn (string $v): string => (!$searching && $view === $v) ? ' is-on' : '';
// Idag-vyn delas i «Försenat» (datum passerat) och «Idag».
$grouped = $view === 'today' && !$searching && $visible !== [];
$lateCount = 0;
if ($grouped) {
    foreach ($visible as $row) {
        if (jeab_reminder_is_late($row)) {
            $lateCount++;
        }
    }
}
$todayCount = $visibleTotal - $lateCount;
$clockSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
$plusSvg = '<svg class="crm-add-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg>';
$searchSvg = '<svg class="crm-search-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>';

ob_start();
echo jeab_error_html($error ?? null);
$sectionTag = '';
if ($tagMode === 'tag' && $activeTag) {
    $sectionTag = '#' . $activeTag;
} elseif ($tagMode === 'none') {
    $sectionTag = 'utan tagg';
}
?>
<div class="apple-app crm-app">
    <header class="crm-top">
        <div class="crm-top-text">
            <p class="apple-eyebrow"><?= e($company === 'malmo' ? 'Malmö' : 'Järfälla') ?></p>
            <h1 class="crm-title">Påminnelser</h1>
        </div>
        <button type="button" class="chip chip-primary crm-add" data-js="reminder-create" aria-haspopup="dialog" aria-controls="reminder-create"><?= $plusSvg ?><span>+ Lägg till</span></button>
    </header>
    <?= jeab_flash_html($flash ?? null) ?>

    <div class="smart-grid crm-stats" role="navigation" aria-label="Vy" data-js="reminder-tabs">
        <a class="smart-tile is-today<?= $tabOn('today') ?>" href="<?= e($tabUrl('today')) ?>">
            <b><?= (int) $counts['today'] ?></b>
            <span>Idag</span>
        </a>
        <a class="smart-tile is-done<?= $tabOn('done') ?>" href="<?= e($tabUrl('done')) ?>">
            <b><?= (int) $counts['done'] ?></b>
            <span>Klara</span>
        </a>
        <a class="smart-tile is-all<?= $tabOn('all') ?>" href="<?= e($tabUrl('all')) ?>">
            <b><?= (int) $counts['all'] ?></b>
            <span>Alla</span>
        </a>
        <a class="smart-tile is-waiting<?= $tabOn('waiting') ?>" href="<?= e($tabUrl('waiting')) ?>">
            <b><?= (int) ($counts['waiting'] ?? 0) ?></b>
            <span>Väntar</span>
        </a>
    </div>

    <form class="apple-search" method="get" action="<?= e(jeab_url('index.php')) ?>" data-js="apple-search">
        <input type="hidden" name="p" value="reminders">
        <input type="hidden" name="v" value="<?= e($homeView) ?>">
        <input type="hidden" name="co" value="<?= e($company) ?>">
        <?php if ($whoFilter): ?><input type="hidden" name="who" value="<?= e($whoFilter) ?>"><?php elseif ($whoAllOn): ?><input type="hidden" name="who" value="all"><?php endif; ?><input type="hidden" name="ws" value="<?= time() ?>">
        <input type="hidden" name="tag" value="all">
        <?= $searchSvg ?>
        <div class="apple-search-field">
            <input type="search" role="searchbox" lang="sv" name="q" value="<?= e($search) ?>" placeholder="Sök namn, adress eller notering" autocomplete="off" spellcheck="false" data-js="apple-search-q">
            <a class="apple-search-clear" href="<?= e($idagLeaveUrl) ?>" data-js="search-clear" aria-label="Rensa sök"<?= $search === '' ? ' hidden' : '' ?>>×</a>
        </div>
    </form>

    <div class="tag-block crm-tags apple-side">
        <div class="tag-chips tag-chips-mode crm-scroll">
            <a class="tag-chip<?= $tagMode === 'all' ? ' is-on' : '' ?>" href="<?= e(jeab_reminders_url(['tag' => 'all', 'open' => null])) ?>">Alla taggar</a>
            <a class="tag-chip<?= $tagMode === 'none' ? ' is-on' : '' ?>" href="<?= e(jeab_reminders_url(['tag' => 'none', 'open' => null])) ?>">Utan tagg</a>
            <?php foreach (jeab_reminder_tag_choices() as $tag): ?>
                <?php $tagOn = $tagMode === 'tag' && $activeTag && strcasecmp($activeTag, $tag) === 0; ?>
                <a class="tag-chip<?= $tagOn ? ' is-on' : '' ?>" href="<?= e(jeab_reminders_url(['tag' => $tagOn ? 'none' : $tag, 'open' => null])) ?>">#<?= e($tag) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="tag-chips tag-chips-list crm-scroll" hidden>
            <?php foreach (jeab_reminder_tag_choices() as $tag): ?>
                <a class="tag-chip" href="<?= e(jeab_reminders_url(['tag' => $tag, 'open' => null])) ?>">#<?= e($tag) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="cal-filter crm-who">
        <div class="cal-filter-main crm-scroll">
            <?php
            $meName = function_exists('jeab_reminders_sql_mina_name')
                ? jeab_reminders_sql_mina_name($company)
                : (function_exists('jeab_who_me_name') ? jeab_who_me_name() : '');
            $meName = $meName !== '' ? $meName : null;
            $minaOn = $meName !== null && $whoFilter !== null && strcasecmp($whoFilter, $meName) === 0;
            ?>
            <?php // Okänd person: listan visar Alla med en rad om det, men Mina-chipet räknar 0 (inte Alla-talet). ?>
            <a class="chip<?= $minaOn ? ' is-on' : '' ?>" data-scope="mina" href="<?= e(jeab_reminders_url(['who' => $meName ?? 'all', 'open' => null])) ?>">Mina<span class="chip-count"><?= (int) ($meName !== null ? ($whoCounts[$meName] ?? 0) : 0) ?></span></a>
            <a class="chip<?= $whoAllOn ? ' is-on' : '' ?>" data-scope="alla" href="<?= e(jeab_reminders_url(['who' => 'all', 'open' => null])) ?>">Alla<span class="chip-count"><?= (int) ($whoCounts['all'] ?? 0) ?></span></a>
            <?php if ($meName === null): ?>
                <p class="who-scope-note" role="status" data-mina-fallback="1">Ingen person är kopplad till din inloggning, visar alla</p>
            <?php elseif (function_exists('jeab_who_scope_note_html')): ?>
                <?php $minaNote = jeab_who_scope_note_html($company, jeab_reminders_url(['who' => null, 'open' => null])); ?>
                <?= $minaNote !== '' ? $minaNote : '<p class="who-scope-note">' . e('Mina visar ' . $meName . '.') . '</p>' ?>
            <?php endif; ?>
        </div>
    </div>

    <section class="apple-main crm-main">
        <?php if (!$grouped): ?>
        <header class="apple-head crm-section-head">
            <h2 class="crm-section-title"><?= e($searching ? 'Sökträffar' : $viewTitle) ?><?php if ($sectionTag !== '' && !$searching): ?><span class="crm-section-tag"> · <?= e($sectionTag) ?></span><?php endif; ?></h2>
            <strong><?= $visibleTotal ?></strong>
        </header>
        <?php endif; ?>
        <a class="visually-hidden" data-js="close-row" href="<?= e(jeab_reminders_url(['open' => null])) ?>">Avbryt</a>

        <?php if (!empty($searchFallback)): ?>
            <p class="crm-search-fallback" data-js="search-fallback">Ingen träff på dina egna. Vi hittade däremot följande:</p>
        <?php endif; ?>
        <?php if (!$visible): ?>
            <?= jeab_empty_html() ?>
        <?php endif; ?>

        <?php $deskOpen = function_exists('jeab_reminder_open_id') ? jeab_reminder_open_id() : 0; ?>
        <?php if (function_exists('jeab_reminder_arrived_preload')) { jeab_reminder_arrived_preload($visible); } ?>
        <div class="pam-board" data-js="pam-board" data-open="<?= $deskOpen > 0 ? '1' : '0' ?>">
        <div class="pam-board-list">
        <ul class="list apple-list" data-total="<?= $visibleTotal ?>" data-list-stamp="<?= e(jeab_reminder_list_stamp($visible)) ?>"<?= $grouped ? ' data-groups="today"' : '' ?>>
            <?php if ($grouped): ?>
                <li class="crm-group is-late" data-group="late"<?= $lateCount > 0 ? '' : ' hidden' ?>>
                    <h2 class="crm-section-title">Försenat<?php if ($sectionTag !== ''): ?><span class="crm-section-tag"> · <?= e($sectionTag) ?></span><?php endif; ?></h2>
                    <strong><?= $lateCount ?></strong>
                </li>
            <?php endif; ?>
            <?php $todayHeadDone = !$grouped; ?>
            <?php foreach ($visible as $row): ?>
                <?php if (!$todayHeadDone && !jeab_reminder_is_late($row)): $todayHeadDone = true; ?>
                    <li class="crm-group" data-group="today">
                        <h2 class="crm-section-title">Idag<?php if ($sectionTag !== ''): ?><span class="crm-section-tag"> · <?= e($sectionTag) ?></span><?php endif; ?></h2>
                        <strong><?= $todayCount ?></strong>
                    </li>
                <?php endif; ?>
                <?php if ($deskOpen > 0 && (int) ($row['id'] ?? 0) === $deskOpen && function_exists('jeab_pam_desk_pin')): ?>
                    <?= jeab_pam_desk_pin($row) ?>
                <?php endif; ?>
                <?= jeab_reminder_row($row) ?>
            <?php endforeach; ?>
            <?php if (!$todayHeadDone): ?>
                <li class="crm-group" data-group="today" hidden>
                    <h2 class="crm-section-title">Idag<?php if ($sectionTag !== ''): ?><span class="crm-section-tag"> · <?= e($sectionTag) ?></span><?php endif; ?></h2>
                    <strong>0</strong>
                </li>
            <?php endif; ?>
        </ul>

        <?php if ($visibleNext !== null): ?>
            <a class="crm-more" href="<?= e(jeab_reminders_url(['offset' => $visibleNext, 'open' => null])) ?>" data-js="reminder-more" data-offset="<?= $visibleNext ?>">Visa fler</a>
        <?php endif; ?>
        </div>
        <div class="pam-mid" data-js="pam-mid">
            <p class="pam-mid-empty">Välj en påminnelse</p>
            <div class="pam-mid-list apple-list" data-js="pam-mid-list"></div>
        </div>
        <aside class="pam-side" data-js="pam-side" aria-label="Kund">
            <button type="button" class="pam-side-toggle" data-js="pam-side-toggle" aria-expanded="true" aria-label="Dölj sidopanelen"><span class="pam-side-arrow" aria-hidden="true">›</span></button>
            <h2 class="pam-side-kicker">Kund</h2>
            <div class="pam-side-body" data-js="pam-side-body"></div>
        </aside>
        </div>
        <button type="button" class="close-pad" data-js="close-pad" aria-label="Stäng" hidden></button>
    </section>
</div>

<?php
$nyThread = (string) ($_GET['ny'] ?? '') === '1' ? trim((string) ($_GET['thread'] ?? '')) : '';
$nyCustomer = '';
$nyAddress = '';
$nyPhone = '';
$nyEmail = '';
$nyPostal = '';
$nyCity = '';
$nyContactId = '';
$nyProduct = 'okand';
$nyCompany = '';
$nyAssignee = '';
$nyNote = '';
if ($nyThread !== '' && function_exists('jeab_reminder_case_prefill')) {
    $nyPrefill = jeab_reminder_case_prefill($nyThread);
    $nyCustomer = trim((string) ($nyPrefill['name'] ?? ''));
    $nyAddress = trim((string) ($nyPrefill['address'] ?? ''));
    $nyPhone = trim((string) ($nyPrefill['phone'] ?? ''));
    $nyEmail = trim((string) ($nyPrefill['email'] ?? ''));
    $nyPostal = trim((string) ($nyPrefill['postal'] ?? ''));
    $nyCity = trim((string) ($nyPrefill['city'] ?? ''));
    $nyContactId = trim((string) ($nyPrefill['contact_id'] ?? ''));
    $nyCompany = trim((string) ($nyPrefill['company'] ?? ''));
    $nyAssignee = trim((string) ($nyPrefill['assignee'] ?? ''));
    $nyNote = trim((string) ($nyPrefill['note'] ?? ''));
    $offered = trim((string) ($nyPrefill['product'] ?? ''));
    if ($offered !== '' && isset(jeab_reminder_product_labels()[$offered])) {
        $nyProduct = $offered;
    }
}
$createCompany = $nyThread !== '' && $nyCompany !== '' ? $nyCompany : $company;
$nyWho = $whoDefault;
if ($nyThread !== '') {
    $nyWho = $nyAssignee !== '' ? $nyAssignee : jeab_reminder_who_me_name($createCompany);
}
?>
<dialog class="reminder-create" id="reminder-create"<?= $nyThread !== '' ? ' open' : '' ?>>
    <form class="reminder-create-form" method="post" action="<?= e($createAction) ?>" data-js="reminder-create-form">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="reminder.create">
        <input type="hidden" name="ui" value="1">
        <input type="hidden" name="address" value="<?= e($nyAddress) ?>" data-js="reminder-create-address">
        <?php if ($nyThread !== ''): ?>
        <input type="hidden" name="thread_id" value="<?= e($nyThread) ?>">
        <input type="hidden" name="from_case" value="1">
        <input type="hidden" name="contact_id" value="<?= e($nyContactId) ?>">
        <?php endif; ?>
        <h2><?= $nyThread !== '' ? 'Skapa påminnelse' : 'Ny påminnelse' ?></h2>
        <label class="field">
            <span>Adress</span>
            <input type="text" name="title"<?= $nyThread === '' ? ' required' : '' ?> placeholder="Adress" autocomplete="street-address" data-js="reminder-create-title" value="<?= e($nyAddress) ?>">
        </label>
        <?php if ($nyThread !== ''): ?>
        <label class="field">
            <span>Postnummer</span>
            <input type="text" name="postal" inputmode="numeric" placeholder="Postnummer" autocomplete="postal-code" value="<?= e($nyPostal) ?>">
        </label>
        <label class="field">
            <span>Ort</span>
            <input type="text" name="ort" placeholder="Ort" autocomplete="address-level2" value="<?= e($nyCity) ?>">
        </label>
        <?php endif; ?>
        <label class="field">
            <span>Namn</span>
            <input type="text" name="customer" placeholder="Namn" autocomplete="name" autocorrect="off" spellcheck="false" value="<?= e($nyCustomer) ?>">
        </label>
        <label class="field">
            <span>Telefon</span>
            <input type="tel" name="phone" placeholder="Telefon" autocomplete="tel" value="<?= e($nyPhone) ?>">
        </label>
        <p class="reminder-create-error" data-js="reminder-create-error" role="alert" hidden></p>
        <label class="field">
            <span>E-post</span>
            <input type="email" name="email" placeholder="E-post" autocomplete="email" value="<?= e($nyEmail) ?>">
        </label>
        <label class="field">
            <span>Anteckning</span>
            <textarea name="notes" rows="2" placeholder="Anteckning" data-js="grow-note"><?= e($nyNote) ?></textarea>
        </label>
        <label class="field">
            <span>Datum (valfritt, tomt = idag)</span>
            <input type="date" name="due_at" aria-required="false">
        </label>
        <?php if ($nyThread !== ''): ?>
        <p class="reminder-create-label">Produkt</p>
        <div class="chips" role="radiogroup" aria-label="Produkt">
            <?php foreach (jeab_reminder_product_labels() as $code => $label): ?>
                <label class="chip-opt">
                    <input type="radio" name="product" value="<?= e($code) ?>"<?= $nyProduct === $code ? ' checked' : '' ?>>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p class="reminder-create-label">Ansvarig</p>
        <?php foreach (['jarfalla', 'malmo'] as $coKey): ?>
            <?php $whoNames = jeab_company_names($coKey); ?>
            <div class="chips reminder-create-who" data-company-who="<?= e($coKey) ?>"<?= $coKey !== $createCompany ? ' hidden' : '' ?>>
                <?php foreach ($whoNames as $name): ?>
                    <?php $whoOn = $coKey === $createCompany && $nyWho !== null && strcasecmp((string) $nyWho, $name) === 0; ?>
                    <label class="chip-opt">
                        <input type="radio" name="who" value="<?= e($name) ?>"<?= $whoOn ? ' checked' : '' ?><?= $coKey !== $createCompany ? ' disabled' : '' ?>>
                        <span><?= e($name) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <p class="reminder-create-label">Val</p>
        <div class="chips" role="group" aria-label="Val">
            <label class="chip-opt">
                <input type="radio" name="company" value="malmo"<?= $createCompany === 'malmo' ? ' checked' : '' ?>>
                <span>Malmö</span>
            </label>
            <label class="chip-opt">
                <input type="radio" name="company" value="jarfalla"<?= $createCompany === 'jarfalla' ? ' checked' : '' ?>>
                <span>Järfälla</span>
            </label>
        </div>
        <p class="reminder-create-label">Taggar</p>
        <div class="chips tag-chips">
            <?php foreach (jeab_reminder_tag_choices() as $tag): ?>
                <label class="chip-opt">
                    <input type="checkbox" name="tags[]" value="<?= e($tag) ?>">
                    <span>#<?= e($tag) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="reminder-create-foot">
            <button type="button" class="reminder-create-cancel" data-js="reminder-create-close">Avbryt</button>
            <button type="submit" class="reminder-create-save">Spara</button>
        </div>
    </form>
</dialog>
<?php
return ob_get_clean();
