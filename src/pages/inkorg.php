<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);

/*
 * Inkorg. Utseende som Påminnelser och Ordrar: kort, chips och en mint primärknapp.
 * Mobil: listan i full bredd; ärendet på egen sida med «← Tillbaka» och fast svarsruta.
 * Inget skickas: svar blir utkast, godkännande = «godkänd, ej skickad».
 */
$tags = jeab_inkorg_tags();
$part = (string) ($_GET['ix_part'] ?? '');
if (!in_array($part, ['', 'list', 'thread', 'app', 'counts', 'all'], true)) {
    $part = '';
}
$jeabIxSlot = null;
if (!empty($GLOBALS['_JEAB_IX_SLOT'])) {
    $jeabIxSlot = $GLOBALS['_JEAB_IX_SLOT'];
} elseif (in_array($part, ['list', 'counts', 'thread', 'app', 'all'], true) && function_exists('jeab_db_slot_acquire')) {
    $jeabIxSlot = jeab_db_slot_acquire(2);
    if ($jeabIxSlot === null && $part === 'thread') {
        $jeabIxSlot = 'open';
    }
    if ($jeabIxSlot === null) {
        if (function_exists('jeab_inkorg_trace_event')) {
            jeab_inkorg_trace_event('page.slot_busy', null, ['part' => $part, 'scope' => function_exists('jeab_inkorg_list_scope') ? jeab_inkorg_list_scope() : '']);
        }
        if (function_exists('jeab_request_release')) {
            jeab_request_release();
        }
        jeab_json(['ok' => true, 'busy' => 1, 'retry' => 1, 'stale' => 1]);
    }
    register_shutdown_function(static function () use ($jeabIxSlot): void {
        if (function_exists('jeab_db_slot_release')) {
            jeab_db_slot_release($jeabIxSlot);
        }
    });
}
if ($part === 'counts') {
    if (function_exists('jeab_timing_begin')) {
        jeab_timing_begin();
    }
    $ixWall = microtime(true);
    $ixSql = function_exists('jeab_sql_stats') ? (float) jeab_sql_stats()['seconds'] : 0.0;
    $bag = jeab_inkorg_fast_counts_refresh();
    if (function_exists('jeab_inkorg_trace_page')) {
        jeab_inkorg_trace_page('counts', [
            'counts' => (array) ($bag['counts'] ?? []),
            'box' => function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : '',
            'scope' => function_exists('jeab_inkorg_list_scope') ? jeab_inkorg_list_scope() : '',
            'vyId' => (string) ($_GET['view'] ?? $_GET['vy'] ?? ''),
        ]);
    }
    $ixDb = (function_exists('jeab_sql_stats') ? (float) jeab_sql_stats()['seconds'] : 0.0) - $ixSql;
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('db', $ixDb);
        jeab_timing_span('render', max(0, microtime(true) - $ixWall - $ixDb));
    }
    jeab_json([
        'ok' => true,
        'counts' => (array) ($bag['counts'] ?? []),
        'unread' => (int) ($bag['unread'] ?? 0),
        'fresh' => (int) ($bag['fresh'] ?? 0),
        'box' => function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : '',
    ]);
}
if ($part === 'app') {
    unset($_GET['id']);
}
if (function_exists('jeab_timing_begin')) {
    jeab_timing_begin();
}
$ixWall = microtime(true);
$ixSql = function_exists('jeab_sql_stats') ? (float) jeab_sql_stats()['seconds'] : 0.0;
$jeabAllCounts = null;
$jeabAppCounts = null;
if ($part === 'app' && function_exists('jeab_inkorg_fast_counts_refresh')) {
    $jeabAppCounts = jeab_inkorg_fast_counts_refresh();
}
try {
    $prepareAs = ($part === 'list' || $part === 'all') ? 'list' : ($part === 'thread' ? 'thread' : '');
    $fast = jeab_inkorg_fast_prepare(false, $prepareAs);
} catch (Throwable $e) {
    if (function_exists('jeab_inkorg_trace_event')) {
        jeab_inkorg_trace_event('page.prepare_throw', $e, ['part' => $part]);
    }
    if ($part === 'thread' && function_exists('jeab_inkorg_thread_part_fail')) {
        jeab_inkorg_thread_part_fail($e, 'ix_open');
    }
    throw $e;
}
if ($part === 'all' && function_exists('jeab_inkorg_fast_counts_ready')) {
    $readyCounts = jeab_inkorg_fast_counts_ready(
        is_array($fast['menu'] ?? null) ? $fast['menu'] : [],
        (string) ($fast['box'] ?? ''),
        (string) ($fast['me'] ?? '')
    );
    $readyMap = is_array($readyCounts['counts'] ?? null) ? $readyCounts['counts'] : [];
    if ((int) ($readyCounts['at'] ?? 0) > 0 && $readyMap !== []) {
        $jeabAllCounts = $readyCounts;
    }
}
$jeabAllThread = false;
if ($part === 'all') {
    if (is_array($jeabAllCounts)) {
        if (is_array($jeabAllCounts['counts'] ?? null)) {
            $fast['counts'] = $jeabAllCounts['counts'];
        }
        $fast['counts_fresh'] = (int) ($jeabAllCounts['fresh'] ?? 0);
        $GLOBALS['_JEAB_INKORG_UNREAD'] = (int) ($jeabAllCounts['unread'] ?? ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0));
    }
    $wantId = trim((string) ($_GET['id'] ?? ''));
    if ($wantId === '' && preg_match('/data-ix-id="([^"]+)"/', (string) ($fast['html'] ?? ''), $jeabAllMatch) === 1) {
        $wantId = html_entity_decode($jeabAllMatch[1], ENT_QUOTES);
    }
    if ($wantId !== '') {
        $_GET['id'] = $wantId;
        try {
            $threadFast = jeab_inkorg_fast_prepare(false, 'thread');
            $fast['open'] = $threadFast['open'] ?? null;
            $fast['panel'] = $threadFast['panel'] ?? [];
            $jeabAllThread = is_array($fast['open']);
        } catch (Throwable $e) {
            if (function_exists('jeab_inkorg_trace_event')) {
                jeab_inkorg_trace_event('page.all_thread_catch', $e);
            }
            $fast['open'] = null;
            $fast['panel'] = [];
        }
    }
}
if (function_exists('jeab_inkorg_trace_page')) {
    jeab_inkorg_trace_page($part, $fast);
}
$ixDb = (function_exists('jeab_sql_stats') ? (float) jeab_sql_stats()['seconds'] : 0.0) - $ixSql;
if ($part === 'all' && !$jeabAllThread) {
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('db', $ixDb);
        jeab_timing_span('render', max(0, microtime(true) - $ixWall - $ixDb));
    }
    jeab_json([
        'ok' => true,
        'html' => (string) ($fast['html'] ?? ''),
        'range' => (string) ($fast['range'] ?? ''),
        'total' => (int) ($fast['total'] ?? 0),
        'page' => (int) ($fast['page'] ?? 1),
        'pages' => (int) ($fast['pages'] ?? 1),
        'counts' => (array) ($fast['counts'] ?? []),
        'counts_fresh' => (int) ($fast['counts_fresh'] ?? 0),
        'unread' => (int) ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0),
        'title' => (string) ($fast['view']['name'] ?? 'Inkorg'),
        'view' => (string) ($fast['vyId'] ?? ''),
        'box' => (string) ($fast['box'] ?? ''),
        'typ' => (string) ($fast['typ'] ?? ''),
        'stale' => 0,
        'thread' => null,
    ]);
}
if ($part === 'list') {
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('db', $ixDb);
        jeab_timing_span('render', max(0, microtime(true) - $ixWall - $ixDb));
    }
    jeab_json([
        'ok' => true,
        'html' => (string) $fast['html'],
        'range' => (string) $fast['range'],
        'total' => (int) ($fast['total'] ?? 0),
        'page' => (int) ($fast['page'] ?? 1),
        'pages' => (int) ($fast['pages'] ?? 1),
        'counts' => (array) $fast['counts'],
        'counts_fresh' => (int) ($fast['counts_fresh'] ?? 0),
        'unread' => (int) ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0),
        'title' => (string) ($fast['view']['name'] ?? 'Inkorg'),
        'view' => (string) $fast['vyId'],
        'box' => (string) $fast['box'],
        'typ' => (string) $fast['typ'],
        'stale' => 0,
    ]);
}
$me = (string) $fast['me'];
$box = (string) $fast['box'];
$vyId = (string) $fast['vyId'];
$view = is_array($fast['view']) ? $fast['view'] : ['id' => $vyId, 'name' => 'Inkorg', 'filter' => []];
$typ = (string) $fast['typ'];
$sok = (string) $fast['q'];
$filtered = (bool) $fast['filtered'];
$counts = (array) $fast['counts'];
$open = is_array($fast['open']) ? $fast['open'] : null;
$readonly = function_exists('jeab_inkorg_box_readonly') && jeab_inkorg_box_readonly();
if ($open !== null && (string) ($open['company'] ?? '') !== '' && (string) $open['company'] !== $box) {
    $open = null;
}
$ixPrefetch = function_exists('jeab_request_is_prefetch') && jeab_request_is_prefetch();
$readOnOpenHtml = $open !== null
    && !in_array($part, ['thread', 'all'], true)
    && !$ixPrefetch
    && jeab_inkorg_is_unread($open, $me)
    && (string) ($open['import_source'] ?? '') !== 'helpscout_import';
$panelThreads = is_array($fast['panel'] ?? null) ? $fast['panel'] : [];
if (is_array($open)) {
    $known = false;
    foreach ($panelThreads as $t) {
        if ((string) ($t['id'] ?? '') === (string) $open['id']) {
            $known = true;
            break;
        }
    }
    if (!$known) {
        $panelThreads[] = $open;
    }
}
if (!in_array($part, ['thread', 'all'], true) && is_array($open) && function_exists('jeab_hs_import_related_threads')) {
    $relEmail = mb_strtolower((string) ($open['first']['from'] ?? $open['last']['from'] ?? ''));
    $panelThreads = array_merge($panelThreads, jeab_hs_import_related_threads($relEmail, (string) $open['id']));
}
if (($part === 'thread' || $part === 'all') && is_array($open) && count($panelThreads) > 24) {
    $openKeep = (string) $open['id'];
    $keptPanel = [];
    $restPanel = [];
    foreach ($panelThreads as $panelRow) {
        if (!is_array($panelRow)) {
            continue;
        }
        if ((string) ($panelRow['id'] ?? '') === $openKeep) {
            $keptPanel[] = $panelRow;
        } else {
            $restPanel[] = $panelRow;
        }
    }
    $panelThreads = array_merge($keptPanel, array_slice($restPanel, 0, max(0, 24 - count($keptPanel))));
}
$scope = (string) ($fast['scope'] ?? 'allas');
$ko = function_exists('jeab_inkorg_queue') ? jeab_inkorg_queue() : (string) ($fast['ko'] ?? '');
$produkt = function_exists('jeab_inkorg_produkt_filter') ? jeab_inkorg_produkt_filter() : (string) ($fast['produkt'] ?? '');
$keep = ['p' => 'inkorg', 'view' => $vyId, 'scope' => $scope, 'ko' => $ko, 'produkt' => $produkt, 'q' => $sok, 'box' => $box];
$q = fn(array $over) => e(jeab_url('index.php?' . http_build_query(array_filter(array_merge($keep, $over), fn($v) => $v !== '' && $v !== null))));
$sources = array_filter((array) ($fast['sources'] ?? []), fn($n, $s) => preg_match('/^(helpscout-test|test)/', (string) $s) === 1, ARRAY_FILTER_USE_BOTH);
$name = fn(array $m) => (string) (($m['from_name'] ?? '') !== '' ? $m['from_name'] : $m['from']);
$staff = is_array($fast['staff'] ?? null) ? $fast['staff'] : jeab_inkorg_staff();
$ownerName = fn(string $id) => (string) ($staff[$id] ?? ((function_exists('jeab_who_person_by_id') ? jeab_who_person_by_id($id) : null)['name'] ?? $id));
$initials = function (string $id) use ($ownerName): string {
    $n = trim($ownerName($id));
    $p = array_values(array_filter(preg_split('/\s+/u', $n) ?: []));
    return mb_strtoupper(count($p) > 1 ? mb_substr($p[0], 0, 1) . mb_substr($p[1], 0, 1) : mb_substr($p[0] ?? '', 0, 2));
};
$contactHits = $sok !== '' ? array_slice(array_filter(jeab_inkorg_contacts(), fn($c) => jeab_inkorg_contact_matches($c, $sok)), 0, 5, true) : [];
$actionLabel = ['arkivera' => 'Arkivera', 'radera' => 'Radera (föreslås)'];
$post = e(jeab_url('index.php?p=inkorg'));
$hidden = '<input type="hidden" name="view" value="' . e($vyId) . '">';
$csrf = jeab_csrf_field();
$title = (string) ($view['name'] ?? 'Inkorg');
$canApprove = jeab_inkorg_can_approve();
$menuViews = is_array($fast['menu'] ?? null) ? $fast['menu'] : [];
$eyebrow = $box === 'malmo' ? 'Malmö' : ($box === 'jarfalla' ? 'Järfälla' : 'Mejl');
$threadReadonly = $readonly;
if ($readonly && is_array($open) && function_exists('jeab_malmo_hs_kim_thread') && jeab_malmo_hs_kim_thread((string) $open['id'])) {
    $threadReadonly = false;
}

ob_start();
if ($part !== 'thread' && $part !== 'all'):
?>
<div class="apple-app crm-app ix<?= $open ? ' is-open' : '' ?>" id="ix" data-ix-box="<?= e($box) ?>" data-ix-view="<?= e($vyId) ?>"<?= $open ? ' data-ix-open="' . e((string) $open['id']) . '"' : '' ?><?= $readOnOpenHtml ? ' data-ix-read-on-open="1"' : '' ?>>
<?php if ($readonly && $threadReadonly): ?>
<p class="ink-muted" role="status">Sol hanteras i Help Scout.</p>
<?php elseif (!jeab_inkorg_send_enabled()): ?>
<div class="ix-banner" role="status">⏸ Utskick är avstängt. Svar sparas som utkast och väntar på Sams godkännande.</div>
<?php endif; ?>

<header class="crm-top">
    <div class="crm-top-text">
        <p class="apple-eyebrow"><?= e($eyebrow) ?></p>
        <div class="crm-title-line">
            <h1 class="crm-title">Inkorg</h1>
            <?php if (function_exists('jeab_auth_ok') && jeab_auth_ok()): ?><span class="ix-storage"><?= e(jeab_inkorg_storage_mode()) ?></span><?php endif; ?>
            <details class="crm-gear">
                <summary aria-label="Inställningar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.6.9 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
                </summary>
                <div class="crm-gear-menu">
                    <?php if (function_exists('jeab_is_admin') && jeab_is_admin()): ?><a href="<?= e(jeab_url('index.php?p=inkorg-regler')) ?>">Regler</a><?php endif; ?>
                    <a href="<?= e(jeab_url('index.php?p=inkorg-vyer')) ?>">Vyer och etiketter</a>
                </div>
            </details>
        </div>
    </div>
    <?php if (!$readonly && array_filter(jeab_inkorg_person_companies(), 'jeab_inkorg_company_live') !== []): ?><button type="button" class="chip chip-primary crm-add" onclick="document.getElementById('ix-newmail').showModal()">+ Nytt mejl</button><?php endif; ?>
</header>

<?= jeab_inkorg_sidebar_html($vyId, 'inkorg', $counts) ?>

<?php
$queueLabels = function_exists('jeab_inkorg_queue_labels') ? jeab_inkorg_queue_labels() : [];
$queueCounts = is_array($fast['queues'] ?? null) ? $fast['queues'] : [];
?>
<nav class="ix-queues" aria-label="Köer">
    <?php foreach ($queueLabels as $qid => $qlabel):
        // Kötalen gäller hela boxen (queueCounts), så listan visar Alla-mappen. Förr
        // kombinerades kön med aktuell mapp: Alla öppna 24 i chippet men 15 rader i Inkorg.
        $qView = $qid === 'stangda' ? 'v-historik' : 'v-alla';
        $qOn = $ko === $qid;
        $qNum = array_key_exists($qid, $queueCounts) ? (int) $queueCounts[$qid] : null;
    ?>
    <a class="ix-qtab<?= $qOn ? ' is-on' : '' ?>" data-ix-queue="<?= e((string) $qid) ?>" href="<?= $q(['ko' => $qid, 'view' => $qView, 'scope' => 'allas', 'id' => '', 'typ' => $typ, 'produkt' => $produkt]) ?>"<?= $qOn ? ' aria-current="page"' : '' ?>><?= e($qlabel) ?><?php if ($qNum !== null): ?> <span class="ix-qcount" data-ix-qcount="<?= e((string) $qid) ?>"><?= (int) $qNum ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<nav class="smart-grid crm-stats ix-folders" role="navigation" aria-label="Vyer">
    <?php foreach ($menuViews as $v): ?>
        <?php if (jeab_inkorg_view_retired($v)) { continue; } ?>
        <a class="smart-tile<?= $v['id'] === $vyId ? ' is-on' : '' ?>" data-ix-view="<?= e((string) $v['id']) ?>" href="<?= $q(['view' => $v['id'], 'typ' => '', 'id' => '']) ?>"<?= $v['id'] === $vyId ? ' aria-current="page"' : '' ?>>
            <b data-ix-count="<?= e((string) $v['id']) ?>"><?= e(jeab_inkorg_count_label($counts, (string) $v['id'])) ?></b>
            <span><?= e($v['name']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<div class="ix-split">
<section class="ix-list" id="ix-list" aria-label="Ärenden" data-ix-post="<?= $post ?>" data-ix-q="<?= e($sok) ?>">
<?= $csrf ?>
<?= function_exists('jeab_mail_queue_banner_html') ? jeab_mail_queue_banner_html() : '' ?>
<?php if (!$open): ?><div class="ix-flash"><?= jeab_flash_html($flash ?? null) ?><?= jeab_error_html($error ?? null) ?></div><?php endif; ?>
<header class="apple-head crm-section-head">
    <h2 class="crm-section-title"><?= e($title) ?></h2>
    <strong id="ix-range" data-ix-box="<?= e($box) ?>" data-ix-view="<?= e($vyId) ?>"><?= e((string) $fast['range']) ?></strong><?php if ($filtered): ?> <a href="<?= $q(['scope' => $scope, 'q' => '', 'typ' => '', 'produkt' => '', 'ko' => '']) ?>">Återställ</a><?php endif; ?>
</header>
<form class="ix-filters" method="get" action="<?= e(jeab_url('index.php')) ?>">
    <input type="hidden" name="p" value="inkorg">
    <input type="hidden" name="box" value="<?= e($box) ?>">
    <input type="hidden" name="view" value="<?= e($vyId) ?>">
    <input type="hidden" name="scope" value="<?= e($scope) ?>">
    <input type="hidden" name="ko" value="<?= e($ko) ?>">
    <input type="hidden" name="q" value="<?= e($sok) ?>">
    <label class="ix-filter">
        <select name="typ" aria-label="Typ" onchange="this.form.submit()">
            <option value="">Typ</option>
            <?php foreach (jeab_inkorg_type_list() as $typeOpt): ?>
            <option value="<?= e($typeOpt) ?>"<?= $typ === $typeOpt ? ' selected' : '' ?>><?= e($typeOpt === 'Skräp' ? 'Spam' : $typeOpt) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="ix-filter">
        <select name="produkt" aria-label="Produkt" onchange="this.form.submit()">
            <option value="">Produkt</option>
            <option value="sol"<?= $produkt === 'sol' ? ' selected' : '' ?>>Sol</option>
            <option value="batteri"<?= $produkt === 'batteri' ? ' selected' : '' ?>>Batteri</option>
            <option value="laddbox"<?= $produkt === 'laddbox' ? ' selected' : '' ?>>Elbilsladdare</option>
        </select>
    </label>
</form>
<form class="apple-search ix-lhead" method="get" action="<?= e(jeab_url('index.php')) ?>" role="search">
    <input type="hidden" name="p" value="inkorg">
    <input type="hidden" name="box" value="<?= e($box) ?>">
    <input type="hidden" name="view" value="<?= e($vyId) ?>">
    <input type="hidden" name="scope" value="<?= e($scope) ?>">
    <input type="hidden" name="ko" value="<?= e($ko) ?>">
    <input type="hidden" name="typ" value="<?= e($typ) ?>">
    <input type="hidden" name="produkt" value="<?= e($produkt) ?>">
    <div class="apple-search-field">
        <input type="search" name="q" value="<?= e($sok) ?>" placeholder="Sök namn, adress, rubrik eller text" aria-label="Sök" autocomplete="off">
        <a class="apple-search-clear" href="<?= $q(['q' => '']) ?>" aria-label="Rensa sök"<?= $sok === '' ? ' hidden' : '' ?>>×</a>
    </div>
</form>
<div class="chips ix-types" role="navigation" aria-label="Vems ärenden">
    <a class="chip<?= $scope !== 'allas' ? ' is-on' : '' ?>" data-ix-scope="egna" href="<?= $q(['scope' => 'egna', 'id' => '']) ?>">Egna</a>
    <a class="chip<?= $scope === 'allas' ? ' is-on' : '' ?>" data-ix-scope="allas" href="<?= $q(['scope' => 'allas', 'id' => '']) ?>">Alla</a>
</div>
<?php $ixEgnaUnknown = function_exists('jeab_egna_me_id') && jeab_egna_me_id() === ''; ?>
<p class="ix-muted" role="status" data-ix-egna-fallback="1" data-ix-egna-unknown="<?= $ixEgnaUnknown ? '1' : '0' ?>"<?= ($scope === 'egna' && $ixEgnaUnknown) ? '' : ' hidden' ?>>Ingen person är kopplad till din inloggning, visar alla</p>
<?php if (!empty($fast['search_widened'])): ?>
<p class="ix-muted" role="status">Inget bland dina ärenden. Träffar hos andra medarbetare.</p>
<?php endif; ?>
<?php if ($ko === 'mina' && (int) ($fast['total'] ?? 0) === 0 && (function_exists('jeab_egna_me_id') ? jeab_egna_me_id() : '') === ''): ?>
<p class="ix-muted" role="status">Ingen person vald. Mina är tom tills du väljer vem du är.</p>
<?php endif; ?>
<?php if ($contactHits): ?>
<div class="ix-contacts" aria-label="Kontakter som matchar"><strong>Kontakter</strong>
    <?php foreach ($contactHits as $c):
        $hitEmail = '';
        foreach ((array) ($c['emails'] ?? []) as $em) {
            $em = trim((string) $em);
            if ($em !== '') {
                $hitEmail = $em;
                break;
            }
        }
    ?><div><a href="<?= e(jeab_kontakt_page_url((string) ($c['contact_id'] ?? ''), $hitEmail)) ?>"><?= e((string) ($c['name'] !== '' ? $c['name'] : $hitEmail)) ?></a> <span class="ix-muted"><?= e($hitEmail) ?></span></div><?php endforeach; ?>
</div>
<?php endif; ?>
<div id="ix-items-wrap"><?= $fast['html'] ?></div>
<?php foreach ($sources as $src => $n): ?>
<form method="post" action="<?= $post ?>" class="ix-testdata" onsubmit="return confirm('Ta bort alla <?= (int) $n ?> testmejl från <?= e((string) $src) ?>?')">
    <?= $csrf ?><input type="hidden" name="action" value="inkorg.clear_source"><input type="hidden" name="source" value="<?= e((string) $src) ?>">
    <span>Testdata: <?= (int) $n ?> mejl från <?= e((string) $src) ?></span><button class="ix-btn" type="submit">Rensa testdata</button>
</form>
<?php endforeach; ?>
</section>
<?php endif; ?>

<section class="ix-conv" id="ix-conv" aria-label="Ärende">
<?php if (!$open): ?>
<div class="ix-cmain">
    <div class="empty ix-empty">
        <p>Inget ärende valt.</p>
        <p class="ix-empty-hint">Välj ett mejl i listan så visas det här.</p>
    </div>
</div>
<aside class="ix-cust ix-side ix-cust-empty" aria-label="Kund"><h3>Kund</h3><p class="ix-muted">Ingen kund vald.</p></aside>
<?php else:
try {
    $ixBag = jeab_inkorg_thread_view_bag($open);
    $open['last'] = $ixBag['last'];
    $open['first'] = $ixBag['first'];
    $open['messages'] = $ixBag['messages'];
    $open['drafts'] = $ixBag['drafts'];
    $open['tags'] = $ixBag['tags'];
    $ixSubject = (string) $ixBag['subject'];
    $last = $open['last']; $l = (array) ($last['labels'] ?? []); $cust = jeab_inkorg_customer($open, $panelThreads); $contact = function_exists('jeab_inkorg_panel_contact') ? jeab_inkorg_panel_contact($open) : jeab_inkorg_contact_for_thread($open);
    $viewMsgs = jeab_inkorg_thread_view_messages((array) $open['messages']);
    $msgTotal = count($viewMsgs);
    $msgCap = function_exists('jeab_inkorg_thread_message_cap') ? jeab_inkorg_thread_message_cap() : 200;
    $showAllMsgs = (string) ($_GET['alla'] ?? '') === '1';
    if (!$showAllMsgs && $msgTotal > $msgCap) {
        $viewMsgs = array_slice($viewMsgs, 0, $msgCap);
    }
    $items = [];
    $seq = 0;
    foreach ($viewMsgs as $m) { $items[] = ['at' => (string) $m['received_at'], 'seq' => $seq++, 'm' => $m]; }
    foreach ($open['drafts'] as $d) { $items[] = ['at' => (string) $d['created_at'], 'seq' => $seq++, 'd' => $d]; }
    usort($items, static function (array $a, array $b): int {
        $cmp = strcmp($b['at'], $a['at']);
        return $cmp !== 0 ? $cmp : ($a['seq'] <=> $b['seq']);
    });
    $when = function (string $iso): string { $d = jeab_inkorg_list_time($iso); return str_contains($d, ':') ? $d : $d . ' ' . date('H:i', strtotime($iso) ?: time()); };
    $curType = (string) ($l['type'] ?? '');
    $typeKnown = in_array($curType, jeab_inkorg_type_list(), true);
    $statusFace = jeab_inkorg_status_face((string) $open['status']);
    $statusChip = jeab_inkorg_status_chip($open);
    $faceClass = (string) $statusChip['class'];
    $parkDay = '';
    $parkRaw = jeab_inkorg_park_until($open);
    if ($parkRaw !== '') {
        $parkTs = strtotime($parkRaw);
        $parkDay = $parkTs !== false ? date('Y-m-d', $parkTs) : '';
    }
    $pillText = (string) $statusChip['label'];
    $pillPlain = (string) preg_replace('/\s*▾$/u', '', $pillText);
    $assigneeId = (string) ($open['assignee'] ?? '');
    $assigneeLabel = $assigneeId === '' ? 'Ingen' : $ownerName($assigneeId);
    $assigneeIni = $assigneeId === '' ? '–' : $initials($assigneeId);
    $tagNames = [];
    foreach ($tags as $tid => $tg) {
        if (in_array($tid, $open['tags'], true) && jeab_inkorg_tag_selectable((string) $tid)) {
            $tagNames[] = (string) $tg['name'];
        }
    }
    $typeSum = $curType !== '' ? $curType : 'Oklassad';
    $tagSum = $tagNames !== [] ? implode(', ', $tagNames) : 'Inga etiketter';
    $ktId = '';
    if (is_array($contact)) {
        $ktId = trim((string) ($contact['id'] ?? $contact['contact_id'] ?? ''));
    }
    $ktUrl = jeab_kontakt_page_url($ktId, (string) ($cust['email'] ?? ''));
    $caseCompany = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($open) : '';
    $parkMin = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    $boxCust = function_exists('jeab_inkorg_contact_box')
        ? jeab_inkorg_contact_box($open, is_array($contact) ? $contact : null, $cust)
        : ['emails' => is_array($contact) ? array_values((array) ($contact['emails'] ?? [])) : [(string) ($cust['email'] ?? '')], 'phone' => is_array($contact) ? (string) ($contact['mobile'] ?? '') : (string) ($cust['phone'] ?? '')];
    $companyName = static function (string $name): bool {
        return function_exists('jeab_auto_company_name') && jeab_auto_company_name($name);
    };
    $custName = '';
    $solePanel = function_exists('jeab_contacts_sole') && jeab_contacts_sole();
    if ($solePanel) {
        $custName = is_array($contact) ? trim((string) ($contact['name'] ?? '')) : '';
        $labelEmails = [];
        foreach ((array) ($boxCust['emails'] ?? []) as $em) {
            if (is_scalar($em) && trim((string) $em) !== '') {
                $labelEmails[] = trim((string) $em);
            }
        }
        if (trim((string) ($cust['email'] ?? '')) !== '') {
            $labelEmails[] = trim((string) $cust['email']);
        }
        if (function_exists('jeab_contacts_sole_finish_display_name')) {
            $custName = jeab_contacts_sole_finish_display_name($custName, $labelEmails);
        }
        if (is_array($contact)) {
            $centralId = trim((string) ($contact['contact_id'] ?? ''));
            if ($centralId !== '') {
                $ktId = $centralId;
                $mailForLink = is_array($contact) ? trim((string) (($contact['emails'][0] ?? '') ?: '')) : '';
                $ktUrl = jeab_kontakt_page_url($ktId, $mailForLink !== '' ? $mailForLink : (string) ($cust['email'] ?? ''));
            }
        }
    } elseif (is_array($contact) && trim((string) ($contact['name'] ?? '')) !== '' && !$companyName(trim((string) $contact['name']))) {
        $custName = trim((string) $contact['name']);
    } elseif (trim((string) $cust['name']) !== '' && !$companyName(trim((string) $cust['name']))) {
        $custName = trim((string) $cust['name']);
    } else {
        $lead = is_array($open['lead_customer'] ?? null) ? $open['lead_customer'] : [];
        $leadName = trim((string) ($lead['name'] ?? ''));
        if ($leadName !== '' && !$companyName($leadName)) {
            $custName = $leadName;
        } else {
            $custName = trim((string) ($cust['email'] ?? ''));
        }
    }
    $panelPlace = function_exists('jeab_inkorg_panel_place')
        ? jeab_inkorg_panel_place($open, is_array($contact) ? $contact : null)
        : ['address' => '', 'town' => ''];
    $custTown = (string) $panelPlace['town'];
    $custAddr = (string) $panelPlace['address'];
    if (empty($solePanel) && $boxCust['phone'] === '' && function_exists('jeab_inkorg_party')) {
        $boxCust['phone'] = trim((string) (jeab_inkorg_party($open)['phone'] ?? ''));
    }
    $custHead = $custName;
    if ($custHead === '' && trim((string) ($boxCust['emails'][0] ?? '')) !== '') {
        $custHead = trim((string) $boxCust['emails'][0]);
    }
    $replyWho = $custName;
    $replyParts = array_values(array_filter(preg_split('/\s+/u', $custName) ?: []));
    if ($replyParts !== [] && !str_contains($replyParts[0], '@')) {
        $replyWho = $replyParts[0];
    }
    $replyPh = $replyWho !== '' ? 'Skriv ditt svar till ' . $replyWho . '…' : 'Skriv ett svar till kunden…';
    $replyTo = function_exists('jeab_inkorg_reply_recipient') ? jeab_inkorg_reply_recipient($open) : '';
    $productKey = '';
    foreach (['sol', 'batteri', 'elbilsladdare'] as $productTag) {
        if (in_array($productTag, array_map('strval', (array) $open['tags']), true)) {
            $productKey = $productTag;
            break;
        }
    }
    if ($productKey === '' && function_exists('jeab_inkorg_stored_offer')) {
        $productKey = match (jeab_inkorg_stored_offer($open)) {
            'sol' => 'sol',
            'batteri' => 'batteri',
            'laddbox' => 'elbilsladdare',
            default => '',
        };
    }
    $productShown = function_exists('jeab_inkorg_product_ui') ? jeab_inkorg_product_ui($productKey) : '';
    $keepTags = [];
    foreach (array_map('strval', (array) $open['tags']) as $tagId) {
        if (!in_array($tagId, ['sol', 'batteri', 'elbilsladdare'], true)) {
            $keepTags[] = $tagId;
        }
    }
    $quoteSent = function_exists('jeab_inkorg_quote_was_sent') && jeab_inkorg_quote_was_sent($open);
    $grokLine = '';
    $caseBag = is_array($l['case_class'] ?? null) ? $l['case_class'] : [];
    if ($caseBag === [] && is_array($open['case_class'] ?? null)) {
        $caseBag = $open['case_class'];
    }
    $grokLine = trim((string) ($caseBag['label'] ?? ''));
    if ($grokLine === '') {
        $reasonLine = trim((string) ($l['reason'] ?? ''));
        $skipReason = $reasonLine === ''
            || str_starts_with($reasonLine, 'Ändrad av ')
            || str_starts_with($reasonLine, 'Klassad av ')
            || str_starts_with($reasonLine, 'Behöver granskas');
        if (!$skipReason) {
            $grokLine = $reasonLine;
        }
    }
    $caseUnread = function_exists('jeab_inkorg_is_unread') && jeab_inkorg_is_unread($open, $me);
    $leadTrash = false;
    $others = [];
?>
<div class="ix-cmain ix-v2">
    <a class="ix-back ordrar-back" href="<?= $q(['id' => '']) ?>">← Tillbaka</a>
    <div class="ix-casehead">
    <h1 class="ix-h1"><?= e($ixSubject) ?></h1>
    <div class="ix-flash"><?= jeab_flash_html($flash ?? null) ?><?= jeab_error_html($error ?? null) ?></div>
    <div class="ix-row">
        <?= jeab_inkorg_test_chip_html($open, 'ix-chip is-test') ?>
        <form method="post" action="<?= $post ?>" data-autosave class="ix-headform" data-stored="<?= e((string) $open['status']) ?>" data-face="<?= e($statusFace) ?>">
            <?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.thread"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="status_ui" value="1">
            <div class="ix-dd">
                <button type="button" class="ix-chip ix-pill <?= e($faceClass) ?>" data-menu-btn="ix-st" data-status-btn><?= e($pillText) ?></button>
                <div class="ix-menu" id="ix-st" hidden>
                    <label class="ix-mi"><input type="radio" name="status" value="open" data-status<?= $statusFace === 'open' ? ' checked' : '' ?>><span class="ix-dot ix-s-open"></span><span>Öppen</span></label>
                    <label class="ix-mi"><input type="radio" name="status" value="parked" data-status<?= $statusFace === 'parked' ? ' checked' : '' ?>><span class="ix-dot ix-s-park"></span><span>Parkerad</span>…</label>
                    <label class="ix-parkdate">Åter <input type="date" name="park_until" value="<?= e($parkDay) ?>" min="<?= e($parkMin) ?>" data-park-until aria-label="Datum för återöppning"><button type="button" class="ix-chip" data-park-ok>OK</button></label>
                    <label class="ix-mi"><input type="radio" name="status" value="closed" data-status<?= $statusFace === 'closed' ? ' checked' : '' ?>><span class="ix-dot ix-s-closed"></span><span>Stängd</span></label>
                </div>
            </div>
            <?php if ($threadReadonly): ?>
            <span class="ix-chip">Tilldelad: <span class="ix-av"><?= e($assigneeIni) ?></span> <?= e($assigneeLabel) ?> ▾</span>
            <?php else: ?>
            <div class="ix-dd">
                <button type="button" class="ix-chip" data-menu-btn="ix-as" data-assign-btn>Tilldelad: <span class="ix-av" data-assign-ini><?= e($assigneeIni) ?></span> <span data-assign-name><?= e($assigneeLabel) ?></span> ▾</button>
                <div class="ix-menu" id="ix-as" hidden>
                    <label class="ix-mi"><input type="radio" name="assignee" value=""<?= $assigneeId === '' ? ' checked' : '' ?>><span class="ix-av">–</span><span>Ingen</span></label>
                    <?php
                    $assignStaff = $staff;
                    if (function_exists('jeab_inkorg_assign_staff') && function_exists('jeab_inkorg_thread_company')) {
                        $assignStaff = jeab_inkorg_assign_staff(jeab_inkorg_thread_company($open));
                    }
                    foreach ($assignStaff as $pid => $pn):
                    ?>
                    <label class="ix-mi"><input type="radio" name="assignee" value="<?= e((string) $pid) ?>"<?= $assigneeId !== '' && strcasecmp($assigneeId, (string) $pid) === 0 ? ' checked' : '' ?>><span class="ix-av"><?= e($initials((string) $pid)) ?></span><span><?= e($pn) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <span class="ix-saved" data-saved aria-live="polite"></span>
            <noscript><button class="ix-btn" type="submit">Spara</button></noscript>
        </form>
        <?php if (!$threadReadonly && ($open['status'] ?? '') !== 'closed'): ?>
        <form method="post" action="<?= $post ?>" id="ix-force-close" hidden><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.thread"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="status_ui" value="1"><input type="hidden" name="status_force" value="1"><input type="hidden" name="status" value="closed"></form>
        <?php endif; ?>
        <div class="ix-dd">
            <button type="button" class="ix-chip ix-more" data-menu-btn="ix-mo" aria-label="Mer">⋯</button>
            <div class="ix-menu" id="ix-mo" hidden>
                <?php if (!$threadReadonly && !$open['deleted']): ?>
                <form method="post" action="<?= $post ?>" onsubmit="return confirm('Markera ärendet som skräp?')"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.flag"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="label" value="skrap"><button class="ix-mi" type="submit">Markera som skräp</button></form>
                <?php endif; ?>
                <button type="button" class="ix-mi" data-orig-all>Visa original</button>
                <?php if (!$threadReadonly && $open['deleted']): ?>
                <form method="post" action="<?= $post ?>"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.restore"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><button class="ix-mi" type="submit">Återställ från papperskorgen</button></form>
                <?php endif; ?>
                <?php if (!$threadReadonly && !$open['deleted']): ?>
                <?php if ($replyTo === ''): ?>
                <button type="button" class="ix-mi" disabled>Saknar mejl</button>
                <?php elseif (jeab_inkorg_quote_was_sent($open)): ?>
                <button type="button" class="ix-mi" disabled>Offert skickad</button>
                <?php else: ?>
                <?php if (jeab_inkorg_awaiting_quote($open)): ?>
                <form method="post" action="<?= $post ?>" data-quote-preview="1" onsubmit="if (this.getAttribute('data-busy')==='1') { return false; } this.setAttribute('data-busy','1'); var b=this.querySelector('button'); if (b) { b.setAttribute('aria-busy','true'); setTimeout(function(){ b.disabled=true; }, 0); } return true;"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.send_quote"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="dry" value="1"><button class="ix-mi" type="submit">Förhandsgranska offert</button></form>
                <?php endif; ?>
                <form method="post" action="<?= $post ?>" data-send-quote="1" onsubmit="var s=document.querySelector('[data-case-product] select'); var p=this.querySelector('input[name=product]'); if (p && s) { p.value = s.value; } if (!p || !p.value) { alert('Välj en produkt innan offerten skickas.'); return false; } if (this.getAttribute('data-busy')==='1') { return false; } if (!confirm('Skicka offerten till kunden nu?')) { return false; } this.setAttribute('data-busy','1'); var b=this.querySelector('button'); if (b) { b.setAttribute('aria-busy','true'); b.textContent='Skickar…'; setTimeout(function(){ b.disabled=true; }, 0); } return true;"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.send_quote"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="dry" value="0"><input type="hidden" name="force_product" value="1"><input type="hidden" name="product" value="<?= e($productKey) ?>"><button class="ix-mi" type="submit">Skicka offert</button></form>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!$threadReadonly): ?>
                <form method="post" action="<?= $post ?>"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.read"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="read" value="<?= $caseUnread ? '1' : '0' ?>"><button class="ix-mi" type="submit"><?= $caseUnread ? 'Markera som läst' : 'Markera som oläst' ?></button></form>
                <?php endif; ?>
                <?php if (!$threadReadonly && !$open['deleted']): ?>
                <?php $leadTrash = jeab_lead_trash_protected($open); ?>
                <?php if ($leadTrash): ?>
                <button type="button" class="ix-mi" data-trash-lead="1" onclick="document.getElementById('ix-trash-lead').showModal()">Flytta till papperskorgen</button>
                <?php else: ?>
                <form method="post" action="<?= $post ?>" data-trash="1" onsubmit="if (this.getAttribute('data-busy')==='1') { return false; } if (!confirm('Flytta ärendet till papperskorgen? Det kan återställas därifrån.')) { return false; } this.setAttribute('data-busy','1'); var b=this.querySelector('button'); if (b) { b.setAttribute('aria-busy','true'); setTimeout(function(){ b.disabled=true; }, 0); } return true;"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.trash"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><button class="ix-mi" type="submit" data-trash-lead="0">Flytta till papperskorgen</button></form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$threadReadonly && !$open['deleted'] && !empty($leadTrash)): ?>
        <dialog id="ix-trash-lead" class="ix-trash-dialog">
            <form method="post" action="<?= $post ?>" data-trash="1" onsubmit="if (this.getAttribute('data-busy')==='1') { return false; } this.setAttribute('data-busy','1'); var b=this.querySelector('button[type=submit]'); if (b) { b.setAttribute('aria-busy','true'); setTimeout(function(){ b.disabled=true; }, 0); } return true;">
                <?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.trash"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="confirm_lead" value="1">
                <p>Det här är ett lead med produkt. Flytta till papperskorgen ändå? Det loggas vem som gör det och när.</p>
                <div class="ix-trash-actions">
                    <button type="button" class="ix-btn" onclick="this.closest('dialog').close()">Avbryt</button>
                    <button class="ix-btn is-danger" type="submit">Flytta till papperskorgen</button>
                </div>
            </form>
        </dialog>
        <?php endif; ?>
    </div>
    </div>
    <p class="ix-grok ix-reason ix-grokstrip"<?= $grokLine === '' ? ' hidden' : '' ?>><b>Grok:</b> <span data-grok><?= e($grokLine) ?></span></p>
    <?php $quotePreview = jeab_inkorg_quote_preview_text((string) $open['id']); if ($quotePreview !== ''): ?>
    <pre class="ix-quote-preview"><?= e($quotePreview) ?></pre>
    <?php endif; ?>
    <div class="ix-vgrid">
    <div class="ix-vcol">
    <?php if ($msgTotal > $msgCap): ?>
    <p class="ix-muted" role="status"><?= $showAllMsgs ? 'Visar alla ' . (int) $msgTotal . ' mejl.' : 'Visar ' . (int) $msgCap . ' av ' . (int) $msgTotal . '.' ?> <?php if (!$showAllMsgs): ?><a href="<?= $q(['alla' => '1']) ?>">Visa alla</a><?php endif; ?></p>
    <?php endif; ?>
    <div class="ix-thread ix-readreply">
    <div class="ix-msgs">
    <?php $seenMail = false; foreach ($items as $it): ?>
    <?php if (isset($it['m'])): $m = $it['m']; ?>
    <?php
        $isNote = !empty($m['internal_note']) || (string) ($m['hs_thread_type'] ?? '') === 'note';
        $ownMsg = !$isNote && (str_starts_with((string) ($m['source'] ?? ''), 'outbound-') || jeab_inkorg_own_address((string) ($m['from'] ?? '')));
        $isLatestMail = !$seenMail;
        $seenMail = true;
        $olderSnips = [];
        if ($isLatestMail) {
            $olderN = 0;
            foreach ($viewMsgs as $olderMsg) {
                if ($olderN >= 6) {
                    break;
                }
                if (!is_array($olderMsg) || (string) ($olderMsg['id'] ?? '') === (string) ($m['id'] ?? '')) {
                    continue;
                }
                $olderSnips[] = (string) ($olderMsg['body'] ?? '');
                $olderN++;
            }
        }
        $shown = function_exists('jeab_inkorg_open_message_text')
            ? jeab_inkorg_open_message_text($m, $isLatestMail, $olderSnips)
            : ['clean' => (string) ($m['body'] ?? ''), 'raw' => (string) ($m['body'] ?? '')];
        $rawBody = (string) ($shown['raw'] ?? '');
        $cleanBody = (string) ($shown['clean'] ?? '');
        $msgClass = $isNote ? 'is-note' : ($ownMsg ? 'is-us' : '');
        $clip = !$isLatestMail && mb_strlen($cleanBody) > 220;
        $toLine = trim((string) ($m['to'] ?? ''));
    ?>
    <article class="ix-msg<?= $msgClass !== '' ? ' ' . $msgClass : '' ?>"<?= $isLatestMail ? ' data-ix-latest="1"' : '' ?>>
        <div class="ix-mh"><span class="ix-t16"><?= e($name($m)) ?></span><span class="ix-meta"><?= e($when((string) $m['received_at'])) ?></span></div>
        <?php if ($isNote): ?><div class="ix-sec ix-notehead"><span class="ix-notewho">Anteckning · <?= e($name($m)) ?></span><span class="ix-sr">intern anteckning</span></div><?php elseif ($toLine !== ''): ?><div class="ix-sec">till <?= e($toLine) ?></div><?php endif; ?>
        <?php if (!$ownMsg && !$isNote && $cust['email'] !== '' && mb_strtolower((string) $m['from']) !== $cust['email']): ?><?php $custLine = ($custHead !== '' && !str_contains($custHead, '@')) ? $custHead : ''; ?><div class="ix-sec">Kund: <?= e(jeab_inkorg_trim_sep(($custLine !== '' ? $custLine . ' · ' : '') . $cust['email'])) ?></div><?php endif; ?>
        <div class="ix-body ix-clean<?= $clip ? ' ix-clip' : '' ?>"><?= e($cleanBody) ?></div>
        <?php if ($clip): ?><button type="button" class="ix-morelink" data-clip>Visa mer</button><?php endif; ?>
        <?php if (!$ownMsg): ?><button type="button" class="ix-origbtn" data-orig-toggle>Visa original</button><div class="ix-body ix-orig"><?= e($rawBody) ?></div><?php endif; ?>
        <?php if (!empty($m['test'])): ?><div class="ix-sec">testmejl</div><?php endif; ?>
        <?php foreach ((array) ($m['attachments'] ?? []) as $attI => $att): if (!is_array($att)) { continue; } ?>
        <p class="ix-att"><a href="<?= e(jeab_url('index.php?' . http_build_query(['p' => 'inkorg-bilaga', 'm' => (string) $m['id'], 'n' => (int) $attI]))) ?>"><?= e((string) ($att['name'] ?? 'Bilaga')) ?></a></p>
        <?php endforeach; ?>
    </article>
    <?php else: $d = $it['d']; $cls = $d['kind'] === 'note' ? 'is-note' : (in_array($d['state'], ['approved', 'sent'], true) ? 'is-us' : 'is-draft'); ?>
    <article class="ix-msg <?= $cls ?>">
        <div class="ix-mh"><span class="ix-t16"><?= e((string) $d['author']) ?></span><span class="ix-meta"><?= e($when((string) $d['created_at'])) ?></span></div>
        <div class="ix-sec"><?= $d['kind'] === 'note' ? 'Anteckning · ' . e((string) $d['author']) . '<span class="ix-sr">intern anteckning</span>' : (($d['state'] ?? '') === 'sent' ? 'skickat från ' . e((string) ($d['from'] ?? '')) . ' till ' . e((string) ($d['to'] ?? '')) : ($d['state'] === 'approved' ? 'godkänd, ej skickad' : 'utkast')) ?><?= ($d['source'] ?? '') === 'grok' ? ' · från Grok Bot' : '' ?><?= $d['cc'] ? ' · CC ' . e(implode(', ', $d['cc'])) : '' ?><?php if ($d['status_after'] !== '' && $d['kind'] !== 'note'): $afterFace = jeab_inkorg_status_face((string) $d['status_after']); ?> · status efter: <?= e(jeab_inkorg_status_faces()[$afterFace] ?? (string) $d['status_after']) ?><?php endif; ?></div>
        <div class="ix-body" data-draft-body><?= e((string) $d['body']) ?></div>
        <?php if ($d['kind'] === 'reply' && $d['state'] === 'pending'): ?>
        <div class="ix-dl">
            <?php if ($canApprove): ?>
            <form method="post" action="<?= $post ?>"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.approve"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="draft" value="<?= e($d['id']) ?>"><button class="ix-btn is-primary" type="submit" title="Markerar utkastet som godkänt. Inget skickas – använd Skicka för att skicka.">Godkänn</button></form>
            <?php endif; ?>
            <button type="button" class="ix-btn" data-edit-draft="<?= e($d['id']) ?>" data-cc="<?= e(implode(', ', $d['cc'])) ?>" data-after="<?= e((string) $d['status_after']) ?>">Redigera</button>
            <form method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort utkastet?')"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.draft_delete"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="draft" value="<?= e($d['id']) ?>"><button class="ix-btn is-danger" type="submit">Ta bort utkast</button></form>
        </div>
        <?php endif; ?>
    </article>
    <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <?php if (!$threadReadonly): ?>
    <?php $draftUndo = function_exists('jeab_inkorg_draft_undo_for') ? jeab_inkorg_draft_undo_for((string) $open['id']) : null; ?>
    <?php if (is_array($draftUndo)): ?>
    <form method="post" action="<?= $post ?>" class="ix-draft-undo"><?= $csrf ?><?= $hidden ?><input type="hidden" name="action" value="inkorg.draft_undo"><input type="hidden" name="thread" value="<?= e($open['id']) ?>"><input type="hidden" name="token" value="<?= e((string) ($draftUndo['token'] ?? '')) ?>"><span>Utkastet är borttaget.</span> <button class="ix-btn" type="submit">Ångra</button></form>
    <?php endif; ?>
    <form class="ix-composer ix-attached" method="post" action="<?= $post ?>" id="ix-composer" enctype="multipart/form-data"<?= $replyTo === '' ? ' data-no-mail="1"' : '' ?>>
        <?= $csrf ?><?= $hidden ?>
        <input type="hidden" name="action" value="inkorg.reply">
        <input type="hidden" name="thread" value="<?= e($open['id']) ?>">
        <input type="hidden" name="replace" value="" data-replace>
        <div class="ix-ctabs" role="radiogroup" aria-label="Svar eller anteckning">
            <label class="ix-tab"><input type="radio" name="kind" value="reply" checked><span>Svara</span></label>
            <label class="ix-tab"><input type="radio" name="kind" value="note"><span>Anteckning</span></label>
        </div>
        <p class="ix-to" data-reply-to><?= $replyTo !== '' ? 'Till: ' . e($replyTo) : 'Till: — (saknar mejl)' ?></p>
        <textarea name="body" rows="4" placeholder="<?= e($replyPh) ?>" data-reply-ph="<?= e($replyPh) ?>" aria-label="Text" data-js="grow-note" required></textarea>
        <div class="ix-cfoot">
            <button type="button" class="ix-cc" data-cc>+ CC</button>
            <input type="text" name="cc" class="ix-cc-field" inputmode="email" autocomplete="email" autocapitalize="off" placeholder="CC" aria-label="CC" hidden>
            <input type="file" name="files[]" class="ix-files" multiple aria-label="Bilagor">
            <input type="hidden" name="send_key" value="<?= bin2hex(random_bytes(8)) ?>">
            <button class="ix-btn ix-draftsave" type="submit" name="do" value="draft">Spara utkast</button>
            <span class="ix-saved" data-saved aria-live="polite"></span>
            <div class="ix-split">
                <button class="ix-btn ix-pri" type="submit" name="do" value="send" data-savebtn<?= $replyTo === '' ? ' disabled' : '' ?>>Skicka och stäng</button>
                <button type="button" class="ix-btn ix-pri ix-car ix-replyonly" data-menu-btn="ix-sd" aria-label="Fler sätt att skicka"<?= $replyTo === '' ? ' disabled' : '' ?>>▾</button>
                <div class="ix-menu ix-up ix-replyonly" id="ix-sd" hidden>
                    <label class="ix-mi"><input type="radio" name="status_after" value="closed" checked><span>Skicka och stäng</span></label>
                    <label class="ix-mi"><input type="radio" name="status_after" value="open"><span>Skicka som öppet</span></label>
                    <label class="ix-mi"><input type="radio" name="status_after" value="parked"><span>Skicka som parkerat</span>…</label>
                    <label class="ix-sendpark">Åter <input type="date" name="park_until" min="<?= e($parkMin) ?>" data-send-park aria-label="Datum för återöppning"><button type="button" class="ix-chip" data-send-park-ok>OK</button></label>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>
    </div>
    </div>
    <aside class="ix-cust ix-side" id="ix-cust" aria-label="Kund">
        <button type="button" class="ix-sidefold" data-ix-sidefold aria-controls="ix-kund-body" aria-expanded="true" title="Fäll in eller ut kundrutan"><span class="ix-sidefold-arrow" aria-hidden="true">&rsaquo;</span><span class="ix-sr">Fäll in eller ut kundrutan</span></button>
        <button type="button" class="ix-kund-toggle ix-mcust" data-kund-toggle aria-expanded="false" aria-controls="ix-kund-body">Kund</button>
        <div id="ix-kund-body" class="ix-kund-body" hidden>
        <h2 class="ix-kund-label">Kund</h2>
        <div class="ix-t16"><?php if ($custHead !== ''): ?><a href="<?= e($ktUrl) ?>"><?= e($custHead) ?></a><?php else: ?>Kund<?php endif; ?></div>
        <div class="ix-kv"><span>Adress</span><?= e($custAddr !== '' ? $custAddr : 'Ingen adress') ?></div>
        <div class="ix-kv"><span>Telefon</span><?php if ($boxCust['phone'] !== ''): ?><a href="tel:<?= e($boxCust['phone']) ?>"><?= e($boxCust['phone']) ?></a><?php else: ?>—<?php endif; ?></div>
        <div class="ix-kv"><span>E-post</span><?php if ($boxCust['emails'] === []): ?>—<?php else: ?><?php foreach ($boxCust['emails'] as $em): ?><a href="mailto:<?= e((string) $em) ?>"><?= e((string) $em) ?></a> <?php endforeach; ?><?php endif; ?></div>
        <?php if ($threadReadonly): ?>
        <div class="ix-field"><span>Typ</span><select aria-label="Typ" disabled><option><?= e($curType === 'Skräp' ? 'Spam' : ($curType !== '' ? $curType : 'Oklassad')) ?></option></select></div>
        <div class="ix-field"><span>Produkt</span><select aria-label="Produkt" disabled><option><?= e($productShown !== '' ? $productShown : '—') ?></option></select></div>
        <?php else: ?>
        <form method="post" action="<?= $post ?>" data-autosave class="ix-field">
            <?= $csrf ?>
            <input type="hidden" name="action" value="inkorg.relabel">
            <input type="hidden" name="id" value="<?= e($last['id']) ?>">
            <span>Typ</span>
            <select name="type" aria-label="Typ">
                <?php if ($curType !== '' && !$typeKnown): ?>
                <option value="<?= e($curType) ?>" selected><?= e($curType) ?></option>
                <?php endif; ?>
                <?php foreach (jeab_inkorg_types() as $types): ?>
                    <?php foreach ($types as $t): ?>
                    <option value="<?= e($t) ?>"<?= $curType === $t ? ' selected' : '' ?>><?= e($t === 'Skräp' ? 'Spam' : $t) ?></option>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select>
            <span class="ix-saved" data-saved aria-live="polite"></span>
        </form>
        <form method="post" action="<?= $post ?>" data-autosave data-case-product class="ix-field">
            <?= $csrf ?><?= $hidden ?>
            <input type="hidden" name="action" value="inkorg.thread">
            <input type="hidden" name="thread" value="<?= e($open['id']) ?>">
            <input type="hidden" name="status_ui" value="1">
            <input type="hidden" name="case_product" value="1">
            <input type="hidden" name="status" value="<?= e($statusFace) ?>">
            <input type="hidden" name="park_until" value="<?= e($parkDay) ?>">
            <input type="hidden" name="tags_present" value="1">
            <?php foreach ($keepTags as $tagId): ?>
            <input type="hidden" name="tags[]" value="<?= e($tagId) ?>">
            <?php endforeach; ?>
            <span>Produkt</span>
            <select name="tags[]" aria-label="Produkt">
                <option value=""<?= $productKey === '' ? ' selected' : '' ?>>—</option>
                <option value="sol"<?= $productKey === 'sol' ? ' selected' : '' ?>>Sol</option>
                <option value="batteri"<?= $productKey === 'batteri' ? ' selected' : '' ?>>Batteri</option>
                <option value="elbilsladdare"<?= $productKey === 'elbilsladdare' ? ' selected' : '' ?>>Elbilsladdare</option>
            </select>
            <span class="ix-saved" data-saved aria-live="polite"></span>
        </form>
        <p class="ix-hint">Ändringar sparas direkt och Grok lär sig av dem.</p>
        <?php endif; ?>
        <?php $caseReminder = jeab_inkorg_case_reminder_link((string) $open['id'], (string) $caseCompany); ?>
        <div class="ix-row ix-custacts"><a class="ix-chip" href="<?= e($ktUrl) ?>">Kontakt</a><?php if ($caseReminder['id'] < 1): ?><?= jeab_inkorg_case_reminder_anchor((string) $open['id'], (string) $caseCompany, 'ix-chip') ?><?php endif; ?></div>
        <?php if ($caseReminder['id'] > 0): ?><div class="ix-prev ix-reminder"><a href="<?= e($caseReminder['href']) ?>"><?= e($caseReminder['label']) ?></a></div><?php endif; ?>
        <?php if (!$threadReadonly): ?>
        <form method="post" action="<?= $post ?>" data-quote-preview="1" data-panel-preview="1"<?= $replyTo === '' ? ' data-no-mail="1"' : '' ?><?= $quoteSent ? ' data-sent="1"' : '' ?> onsubmit="var s=document.querySelector('[data-case-product] select'); var p=this.querySelector('input[name=product]'); if (p && s) { p.value = s.value; } if (!p || !p.value) { alert('Välj en produkt innan offerten förhandsgranskas.'); return false; } if (this.getAttribute('data-busy')==='1') { return false; } this.setAttribute('data-busy','1'); var b=this.querySelector('button'); if (b) { b.setAttribute('aria-busy','true'); setTimeout(function(){ b.disabled=true; }, 0); } return true;">
            <?= $csrf ?><?= $hidden ?>
            <input type="hidden" name="action" value="inkorg.send_quote">
            <input type="hidden" name="thread" value="<?= e($open['id']) ?>">
            <input type="hidden" name="dry" value="1">
            <input type="hidden" name="template_only" value="1">
            <input type="hidden" name="force_product" value="1">
            <input type="hidden" name="product" value="<?= e($productKey) ?>">
            <button class="ix-btn" type="submit"<?= ($quoteSent || $replyTo === '' || $productKey === '') ? ' disabled' : '' ?>>Förhandsgranska</button>
        </form>
        <form method="post" action="<?= $post ?>" data-panel-quote<?= $replyTo === '' ? ' data-no-mail="1"' : '' ?><?= $quoteSent ? ' data-sent="1"' : '' ?> onsubmit="var s=document.querySelector('[data-case-product] select'); var p=this.querySelector('input[name=product]'); if (p && s) { p.value = s.value; } if (!p || !p.value) { alert('Välj en produkt innan offerten skickas.'); return false; } if (this.getAttribute('data-no-mail')==='1') { alert('Saknar mejl.'); return false; } if (this.getAttribute('data-busy')==='1') { return false; } if (!confirm('Skicka offerten till kunden nu?')) { return false; } this.setAttribute('data-busy','1'); var b=this.querySelector('button'); if (b) { b.setAttribute('aria-busy','true'); b.textContent='Skickar…'; setTimeout(function(){ b.disabled=true; }, 0); } return true;">
            <?= $csrf ?><?= $hidden ?>
            <input type="hidden" name="action" value="inkorg.send_quote">
            <input type="hidden" name="thread" value="<?= e($open['id']) ?>">
            <input type="hidden" name="dry" value="0">
            <input type="hidden" name="template_only" value="1">
            <input type="hidden" name="force_product" value="1">
            <input type="hidden" name="product" value="<?= e($productKey) ?>">
            <button class="ix-btn is-primary" type="submit"<?= ($quoteSent || $replyTo === '' || $productKey === '') ? ' disabled' : '' ?>><?= $quoteSent ? 'Offert skickad' : 'Skicka offert' ?></button>
        </form>
        <?php endif; ?>
        <?php if (is_array($contact) && ($contact['notes'] ?? '') !== ''): ?><div class="ix-sec"><?= e((string) $contact['notes']) ?></div><?php endif; ?>
        <?php if (is_array($contact)): ?>
        <?php $others = array_values(array_filter(jeab_inkorg_contact_threads($contact, $panelThreads), fn($t) => $t['id'] !== $open['id'])); ?>
        <?php else: ?>
        <?php $others = $cust['cases']; ?>
        <?php endif; ?>
        <?php if (($open['brand'] ?? '') !== ''): ?><div class="ix-sec"><?= $open['brand'] === 'malmo' ? 'Demo Energi Malmö' : 'Demo Energi Järfälla' ?></div><?php endif; ?>
        <?php
            $ktRow = ($ktId !== '' && function_exists('jeab_kontakt_get')) ? jeab_kontakt_get($ktId) : null;
        ?>
        <?php if (is_array($ktRow)): ?>
        <div class="ix-k">Kontaktregister</div>
        <div><a href="<?= e($ktUrl) ?>"><?= e(trim((string) (($ktRow['street'] ?? '') !== '' ? $ktRow['street'] : 'Kontakten'))) ?></a></div>
        <?php endif; ?>
        <?php if (!empty($open['duplicate_of'])): ?>
        <?php
            $dupId = (string) $open['duplicate_of'];
            $dupSubject = '';
            foreach ($panelThreads as $dupThread) {
                if (!is_array($dupThread) || (string) ($dupThread['id'] ?? '') !== $dupId) {
                    continue;
                }
                $dupSubject = trim((string) ((array) ($dupThread['first'] ?? [])['subject'] ?? ''));
                break;
            }
            $dupCaption = function_exists('jeab_hs_import_duplicate_caption')
                ? jeab_hs_import_duplicate_caption($dupId, $dupSubject)
                : ('Dubblett av ' . ($dupSubject !== '' ? $dupSubject : $dupId));
            if ($dupSubject === '') {
                foreach ((array) ($open['messages'] ?? []) as $dupMsg) {
                    if (!is_array($dupMsg)) {
                        continue;
                    }
                    $dupBody = trim((string) ($dupMsg['body'] ?? ''));
                    if (str_starts_with($dupBody, 'Dubblett av ')) {
                        $dupCaption = $dupBody;
                        break;
                    }
                }
            }
        ?>
        <div class="ix-k"><?= e($dupCaption) ?></div><div class="ix-prev"><a href="<?= $q(['id' => $dupId]) ?>">Öppna originalärendet</a></div>
        <?php endif; ?>
        <?php if (!empty($open['same_customer'])): ?><div class="ix-k">Samma kund</div><?php foreach ($open['same_customer'] as $sc): ?><div class="ix-prev"><a href="<?= $q(['id' => $sc]) ?>"><?= e($sc) ?></a></div><?php endforeach; ?><?php endif; ?>
        <div class="ix-k">Andra ärenden från kunden</div>
        <?php if (!$others): ?><div class="ix-sec">Inga andra ärenden</div><?php else: ?>
        <?php foreach ($others as $t): if (!is_array($t)) { continue; } $otherFirst = is_array($t['first'] ?? null) ? $t['first'] : []; $otherSubject = trim((string) ($otherFirst['subject'] ?? '')); if ($otherSubject === '') { $otherSubject = '(ingen rubrik)'; } ?><div class="ix-prev"><a href="<?= $q(['id' => $t['id']]) ?>"><?= e($otherSubject) ?></a><br><small><?= e(jeab_inkorg_list_time((string) ($t['updated_at'] ?? ''))) ?> · <?= e(jeab_inkorg_status_faces()[jeab_inkorg_status_face((string) ($t['status'] ?? ''))]) ?></small></div><?php endforeach; ?>
        <?php endif; ?>
        </div>
    </aside>
    </div>
    <div class="ix-toast" id="ix-toast" hidden><span data-toast-text></span> <button type="button" data-learn-undo hidden>Ångra</button></div>
</div>
<?php
} catch (Throwable $e) {
    if ($part === 'thread') {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        jeab_inkorg_thread_part_fail($e, 'ix_render');
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('inkorg', 'ix_render ' . $e::class, ['no_webhook' => true]);
    }
    echo '<div class="ix-cmain"><p class="empty">Ärendet kunde inte öppnas.</p></div>';
}
endif; ?>
</section>
<?php
if ($part === 'thread' || $part === 'all') {
    $html = (string) ob_get_clean();
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('db', $ixDb);
        jeab_timing_span('render', max(0, microtime(true) - $ixWall - $ixDb));
    }
    $markRead = is_array($open)
        && jeab_inkorg_is_unread($open, $me)
        && (string) ($open['import_source'] ?? '') !== 'helpscout_import';
    $readOnOpen = $markRead && empty($ixPrefetch);
    if ($part === 'all') {
        jeab_json([
            'ok' => true,
            'html' => (string) ($fast['html'] ?? ''),
            'range' => (string) ($fast['range'] ?? ''),
            'total' => (int) ($fast['total'] ?? 0),
            'page' => (int) ($fast['page'] ?? 1),
            'pages' => (int) ($fast['pages'] ?? 1),
            'counts' => (array) ($fast['counts'] ?? []),
            'counts_fresh' => (int) ($fast['counts_fresh'] ?? 0),
            'unread' => (int) ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0),
            'title' => (string) ($fast['view']['name'] ?? 'Inkorg'),
            'view' => $vyId,
            'box' => $box,
            'typ' => (string) ($fast['typ'] ?? ''),
            'stale' => 0,
            'thread' => [
                'ok' => true,
                'html' => $html,
                'id' => is_array($open) ? (string) $open['id'] : '',
                'view' => $vyId,
                'box' => $box,
                'read_on_open' => $readOnOpen,
                'mark_read' => $markRead,
                'rev' => is_array($open) ? jeab_inkorg_thread_rev($open) : '',
            ],
        ]);
    }
    jeab_json([
        'ok' => true,
        'html' => $html,
        'id' => is_array($open) ? (string) $open['id'] : '',
        'view' => $vyId,
        'box' => $box,
        'read_on_open' => $readOnOpen,
        'mark_read' => $markRead,
        'rev' => is_array($open) ? jeab_inkorg_thread_rev($open) : '',
    ]);
}
?>
</div>
<script src="<?= e(jeab_asset_url('assets/js/inkorg-nav.js')) ?>"></script>
<script>
(function () {
  try {
    if (window.jeabInkorgReplyDraftApplyFlash) {
      window.jeabInkorgReplyDraftApplyFlash(sessionStorage, new URLSearchParams(location.search).get('flash') || '');
    }
  } catch (e) {}
  function showNote(msg, undo) {
    var toast = document.getElementById('ix-toast');
    if (!toast) { return; }
    var text = toast.querySelector('[data-toast-text]');
    var b = toast.querySelector('[data-learn-undo]');
    if (text) { text.textContent = msg; }
    if (b) {
      b.hidden = !undo;
      b.disabled = false;
      if (undo) { b.setAttribute('data-learn-undo', undo); }
    }
    toast.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toast.hidden = true; }, 4000);
  }
  function learnToast(out, row) {
    showNote('Sparat – Grok lär sig av detta', String(row.undo || ''));
    if (out) { out.textContent = ''; }
  }
  function closeMenus(except) {
    document.querySelectorAll('#ix-conv .ix-menu').forEach(function (m) { if (m !== except) { m.hidden = true; } });
    if (!except) { ixMenuUnlift(); }
  }
  /*
   * Dator (från 1024 px): menyerna i mittenpanelen (status, Tilldelad, ⋯ och pilen vid
   * Skicka) ligger i föräldrar med overflow: hidden (.ix-cmain, .ix-vcol, .ix-split) och
   * i staplingslager med z-index 1 (.ix-casehead lägre i ordningen än .ix-vcol). Därför
   * ritades menyerna bakom tråden eller klipptes bort helt. Vid öppning läggs menyn med
   * position: fixed vid sin knapp och lagren den ligger i lyfts tills menyn stängs.
   * Under 1024 px görs ingenting: mobil och surfplatta är som förut.
   */
  function ixMenuDesk() {
    return !!(window.matchMedia && window.matchMedia('(min-width: 1024px)').matches);
  }
  function ixMenuUnlift() {
    document.querySelectorAll('.ix-menu-lift').forEach(function (el) { el.classList.remove('ix-menu-lift'); });
  }
  function ixMenuUnfloat(menu) {
    if (!menu) { return; }
    menu.classList.remove('ix-float');
    ['top', 'bottom', 'left', 'right', 'maxHeight'].forEach(function (k) { menu.style[k] = ''; });
  }
  function ixMenuButton(menu) {
    if (!menu || !menu.id) { return null; }
    var host = menu.parentElement;
    return (host && host.querySelector('[data-menu-btn="' + menu.id + '"]')) || document.querySelector('[data-menu-btn="' + menu.id + '"]');
  }
  function ixMenuPlace(menu) {
    if (!menu || menu.hidden) { return; }
    if (!ixMenuDesk()) { ixMenuUnfloat(menu); ixMenuUnlift(); return; }
    var btn = ixMenuButton(menu);
    var up = menu.classList.contains('ix-up');
    var anchor = up ? (menu.closest('.ix-split') || btn) : ((btn && btn.closest('.ix-dd')) || btn);
    if (!anchor) { return; }
    ixMenuUnlift();
    for (var el = menu.parentElement; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
      if (getComputedStyle(el).zIndex !== 'auto') { el.classList.add('ix-menu-lift'); }
    }
    menu.classList.add('ix-float');
    menu.style.maxHeight = '';
    menu.style.top = '0px';
    menu.style.left = '0px';
    menu.style.bottom = 'auto';
    menu.style.right = 'auto';
    var r = anchor.getBoundingClientRect();
    var vw = document.documentElement.clientWidth || window.innerWidth;
    var vh = window.innerHeight;
    var edge = 8;
    var gap = up ? 6 : 4;
    var h = menu.scrollHeight;
    var w = menu.offsetWidth;
    var below = vh - r.bottom - gap - edge;
    var above = r.top - gap - edge;
    var goUp = up ? !(h > above && below > above) : (h > below && below < 240 && above > below);
    if (goUp) {
      menu.style.top = 'auto';
      menu.style.bottom = Math.round(vh - r.top + gap) + 'px';
      if (h > above) { menu.style.maxHeight = Math.max(120, Math.floor(above)) + 'px'; }
    } else {
      menu.style.top = Math.round(r.bottom + gap) + 'px';
      if (h > below) { menu.style.maxHeight = Math.max(120, Math.floor(below)) + 'px'; }
    }
    var left = up ? r.right - w : r.left;
    left = Math.min(left, vw - w - edge);
    left = Math.max(edge, left);
    menu.style.left = Math.round(left) + 'px';
  }
  function ixMenuShow(menu) {
    if (!menu) { return; }
    menu.hidden = false;
    ixMenuPlace(menu);
  }
  function ixMenuReplace() {
    document.querySelectorAll('#ix-conv .ix-menu').forEach(function (m) {
      if (m.hidden) { return; }
      ixMenuPlace(m);
    });
    if (!ixMenuDesk()) {
      document.querySelectorAll('#ix-conv .ix-menu.ix-float').forEach(ixMenuUnfloat);
      ixMenuUnlift();
    }
  }
  function paintStatus() {
    var r = document.querySelector('#ix-st input[name=status]:checked');
    var btn = document.querySelector('[data-status-btn]');
    if (!r || !btn) { return; }
    btn.classList.remove('ix-s-open', 'ix-s-park', 'ix-s-closed');
    var cls = { open: 'ix-s-open', parked: 'ix-s-park', closed: 'ix-s-closed' };
    var labels = { open: 'Öppen', parked: 'Parkerad', closed: 'Stängd' };
    btn.classList.add(cls[r.value] || 'ix-s-open');
    var extra = '';
    if (r.value === 'parked') {
      var d = document.querySelector('[data-park-until]');
      if (d && d.value) {
        var p = d.value.split('-');
        var mon = ['jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
        extra = ' till ' + String(parseInt(p[2], 10)) + ' ' + (mon[parseInt(p[1], 10) - 1] || '');
      }
    }
    btn.textContent = (labels[r.value] || 'Öppen') + extra;
  }
  function ixTagState(form) {
    var vals = [];
    form.querySelectorAll('input[name="tags[]"]').forEach(function (i) {
      if (i.type === 'checkbox' && !i.checked) { return; }
      if (i.value) { vals.push(i.value); }
    });
    var sel = form.querySelector('select[name="tags[]"]');
    if (sel && sel.value) { vals.push(sel.value); }
    return vals.join('\n');
  }
  function ixListTypeLabel(type) {
    if (type === 'Ny lead') { return 'Lead'; }
    return type || 'Oklassad';
  }
  function ixListTypeClass(label) {
    var map = { Lead: ' is-lead', 'Skräp': ' is-skrap', Information: ' is-info', Support: ' is-support' };
    return 'ix-cat' + (map[label] || '');
  }
  function ixClsSnap(form, nextType) {
    var card = document.querySelector('#ix-items-wrap .ix-it.is-on [data-ix-type]');
    var reason = document.querySelector('#ix-conv .ix-reason');
    return {
      form: form,
      prevType: form.dataset.prevType || '',
      prevTags: form.dataset.prevTags || '',
      reason: reason ? reason.textContent : '',
      typeText: card ? card.textContent : '',
      typeClass: card ? card.className : 'ix-cat',
      hadType: !!card,
      nextType: nextType || ''
    };
  }
  function ixPaintListType(type) {
    var cats = document.querySelector('#ix-items-wrap .ix-it.is-on .ix-cats');
    if (!cats) { return; }
    var label = ixListTypeLabel(type);
    var el = cats.querySelector('[data-ix-type]');
    if (!el) {
      el = document.createElement('span');
      el.setAttribute('data-ix-type', '');
      var status = cats.querySelector('.ix-status');
      if (status && status.nextSibling) { cats.insertBefore(el, status.nextSibling); }
      else { cats.appendChild(el); }
    }
    el.textContent = label;
    el.className = ixListTypeClass(label);
  }
  function ixClearReason() {
    var reason = document.querySelector('#ix-conv .ix-reason');
    if (!reason) { return; }
    reason.textContent = '';
    reason.hidden = true;
  }
  function ixClsRestore(snap) {
    if (!snap || !snap.form) { return; }
    if (snap.form.querySelector('select[name=type]')) {
      snap.form.querySelector('select[name=type]').value = snap.prevType;
    } else if (snap.form.querySelector('input[name=type]')) {
      snap.form.querySelectorAll('input[name=type]').forEach(function (i) { i.checked = i.value === snap.prevType; });
    }
    if (snap.form.querySelector('select[name="tags[]"]') || snap.form.querySelector('input[name="tags[]"]')) {
      var keep = snap.prevTags === '' ? [] : snap.prevTags.split('\n');
      var sel = snap.form.querySelector('select[name="tags[]"]');
      if (sel) {
        var product = '';
        ['sol', 'batteri', 'elbilsladdare'].forEach(function (id) {
          if (keep.indexOf(id) !== -1) { product = id; }
        });
        sel.value = product;
      }
      snap.form.querySelectorAll('input[type=checkbox][name="tags[]"]').forEach(function (i) {
        i.checked = keep.indexOf(i.value) !== -1;
      });
    }
    paintCls();
    var card = document.querySelector('#ix-items-wrap .ix-it.is-on [data-ix-type]');
    if (snap.hadType && card) {
      card.textContent = snap.typeText;
      card.className = snap.typeClass;
    } else if (!snap.hadType && card && card.parentNode) {
      card.parentNode.removeChild(card);
    }
    var reason = document.querySelector('#ix-conv .ix-reason');
    if (reason) {
      reason.textContent = snap.reason;
      reason.hidden = snap.reason === '';
    }
  }
  function paintCls() {
    var t = document.querySelector('#ix-cls-panel input[name=type]:checked');
    var el = document.querySelector('[data-cls-type]');
    if (t && el) { el.textContent = t.value; }
    var tags = [];
    document.querySelectorAll('#ix-cls-panel input[type=checkbox][name="tags[]"]:checked').forEach(function (i) {
      var s = i.parentNode.querySelector('span');
      tags.push(s ? s.textContent.trim() : i.value);
    });
    var es = document.querySelector('[data-cls-tags]');
    if (es) { es.textContent = tags.length ? tags.join(', ') : 'Inga etiketter'; }
  }
  function jeabIxBind(root) {
    if (!root) { return; }
    var conv = root.querySelector('#ix-conv') || document.getElementById('ix-conv');
    var btn = root.querySelector('[data-custbtn]');
    function setCust(hide) { if (!conv || !btn) { return; } conv.classList.toggle('is-nocust', hide); btn.textContent = hide ? '◂ Kund' : 'Kund ▸'; btn.setAttribute('aria-expanded', String(!hide)); }
    try { setCust(localStorage.getItem('ix-nocust') === '1'); } catch (e) {}
    if (btn && !btn.dataset.ixBound) {
      btn.dataset.ixBound = '1';
      btn.addEventListener('click', function () { var h = !conv.classList.contains('is-nocust'); setCust(h); try { localStorage.setItem('ix-nocust', h ? '1' : '0'); } catch (e) {} });
    }
    root.querySelectorAll('[data-orig-toggle]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        var box = b.closest('.ix-msg');
        if (!box) { return; }
        var on = box.classList.toggle('is-original');
        b.textContent = on ? 'Visa rensat' : 'Visa original';
      });
    });
    root.querySelectorAll('[data-menu-btn]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var menu = document.getElementById(b.getAttribute('data-menu-btn'));
        if (!menu) { return; }
        var open = menu.hidden;
        closeMenus(open ? menu : null);
        if (open) { ixMenuShow(menu); } else { menu.hidden = true; }
      });
    });
    root.querySelectorAll('#ix-st input[name=status]').forEach(function (r) {
      if (r.dataset.ixBound) { return; }
      r.dataset.ixBound = '1';
      r.addEventListener('change', function () {
        paintStatus();
        if (r.value !== 'parked') { var m = document.getElementById('ix-st'); if (m) { m.hidden = true; } }
        if (r.value === 'parked' && r.checked) {
          var date = r.closest('form') && r.closest('form').querySelector('[data-park-until]');
          if (date) { date.focus(); }
        }
      });
    });
    var closedRadio = root.querySelector('#ix-st input[name=status][value=closed]');
    if (closedRadio && !closedRadio.dataset.ixForce) {
      closedRadio.dataset.ixForce = '1';
      closedRadio.closest('label').addEventListener('click', function (ev) {
        var form = closedRadio.closest('form');
        if (!(form && form.getAttribute('data-face') === 'closed' && form.getAttribute('data-stored') !== 'closed')) {
          return;
        }
        ev.preventDefault();
        ev.stopPropagation();
        var ff = document.getElementById('ix-force-close');
        if (ff && window.confirm('Stäng ärendet? Det visas redan som stängt.')) {
          ff.submit();
        }
      });
    }
    root.querySelectorAll('[data-park-ok]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        var form = b.closest('form');
        var radio = form && form.querySelector('input[name=status][value=parked]');
        var date = form && form.querySelector('[data-park-until]');
        if (!radio || !date || !date.value) { showNote('Parkerad kräver ett datum.', ''); return; }
        radio.checked = true;
        form.dataset.ixUser = '1';
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        var m = document.getElementById('ix-st');
        if (m) { m.hidden = true; }
      });
    });
    root.querySelectorAll('[data-park-until]').forEach(function (date) {
      if (date.dataset.ixParkDate) { return; }
      date.dataset.ixParkDate = '1';
      date.addEventListener('change', function () {
        if (!date.value) { return; }
        var ok = date.parentElement && date.parentElement.querySelector('[data-park-ok]');
        if (ok) { ok.click(); }
      });
    });
    root.querySelectorAll('[data-send-park-ok]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function (ev) {
        ev.preventDefault();
        var form = b.closest('form');
        var radio = form && form.querySelector('input[name=status_after][value=parked]');
        var date = form && form.querySelector('[data-send-park]');
        if (!radio || !date || !date.value) { showNote('Parkerad kräver ett datum.', ''); return; }
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        var m = document.getElementById('ix-sd');
        if (m) { m.hidden = true; }
      });
    });
    root.querySelectorAll('[data-send-park]').forEach(function (date) {
      if (date.dataset.ixSendPark) { return; }
      date.dataset.ixSendPark = '1';
      date.addEventListener('change', function () {
        if (!date.value) { return; }
        var ok = date.parentElement && date.parentElement.querySelector('[data-send-park-ok]');
        if (ok) { ok.click(); }
      });
    });
    root.querySelectorAll('#ix-composer input[name=status_after][value=parked]').forEach(function (r) {
      if (r.dataset.ixParkFocus) { return; }
      r.dataset.ixParkFocus = '1';
      r.addEventListener('change', function () {
        if (!r.checked) { return; }
        var date = r.closest('form') && r.closest('form').querySelector('[data-send-park]');
        if (date) { date.focus(); }
      });
    });
    root.querySelectorAll('#ix-as input[name=assignee]').forEach(function (r) {
      if (r.dataset.ixBound) { return; }
      r.dataset.ixBound = '1';
      r.addEventListener('change', function () {
        var lab = r.closest('label');
        var n = document.querySelector('[data-assign-name]');
        var i = document.querySelector('[data-assign-ini]');
        var name = lab && lab.querySelector('span:last-child');
        var ini = lab && lab.querySelector('.ix-av');
        if (n && name) { n.textContent = name.textContent; }
        if (i && ini) { i.textContent = ini.textContent; }
        var m = document.getElementById('ix-as');
        if (m) { m.hidden = true; }
      });
    });
    var clsBtn = root.querySelector('[data-cls]');
    var clsP = root.querySelector('#ix-cls-panel');
    if (clsBtn && clsP && !clsBtn.dataset.ixBound) {
      clsBtn.dataset.ixBound = '1';
      clsBtn.addEventListener('click', function () { clsP.hidden = !clsP.hidden; });
    }
    /* Kundrutan på dator: pilen fäller in/ut. Valet sparas; utan val styr fönsterbredden. */
    var ixApp = document.getElementById('ix');
    function ixSideCollapsed(aside) { return !!aside && aside.getBoundingClientRect().width < 90; }
    function ixSideSync() {
      var aside = document.getElementById('ix-cust');
      document.querySelectorAll('[data-ix-sidefold]').forEach(function (f) {
        f.setAttribute('aria-expanded', ixSideCollapsed(aside) ? 'false' : 'true');
      });
    }
    if (ixApp && !ixApp.dataset.ixSideInit) {
      ixApp.dataset.ixSideInit = '1';
      try {
        var sv = localStorage.getItem('jeab-ix-side');
        if (sv === 'off') { ixApp.classList.add('ix-side-off'); }
        if (sv === 'on') { ixApp.classList.add('ix-side-on'); }
      } catch (e) {}
      window.addEventListener('resize', ixSideSync);
    }
    root.querySelectorAll('[data-ix-sidefold]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        if (!ixApp) { return; }
        var open = ixSideCollapsed(b.closest('.ix-cust'));
        ixApp.classList.toggle('ix-side-on', open);
        ixApp.classList.toggle('ix-side-off', !open);
        try { localStorage.setItem('jeab-ix-side', open ? 'on' : 'off'); } catch (e) {}
        ixSideSync();
      });
    });
    ixSideSync();
    root.querySelectorAll('[data-kund-toggle]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        var body = document.getElementById(b.getAttribute('aria-controls') || 'ix-kund-body');
        if (!body) { return; }
        body.hidden = !body.hidden;
        b.setAttribute('aria-expanded', body.hidden ? 'false' : 'true');
      });
    });
    root.querySelectorAll('[data-mcust]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        var body = b.nextElementSibling;
        if (!body) { return; }
        body.hidden = !body.hidden;
        b.setAttribute('aria-expanded', body.hidden ? 'false' : 'true');
      });
    });
    root.querySelectorAll('[data-clip]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        var msg = b.closest('.ix-msg');
        if (!msg) { return; }
        var on = msg.classList.toggle('is-expanded');
        b.textContent = on ? 'Visa mindre' : 'Visa mer';
      });
    });
    root.querySelectorAll('[data-orig-all]').forEach(function (b) {
      if (b.dataset.ixBound) { return; }
      b.dataset.ixBound = '1';
      b.addEventListener('click', function () {
        root.querySelectorAll('[data-orig-toggle]').forEach(function (t) { t.click(); });
        b.textContent = document.querySelector('.ix-msg.is-original') ? 'Visa rensat' : 'Visa original';
        closeMenus(null);
      });
    });
    root.querySelectorAll('form[data-autosave]').forEach(function (f) {
      if (f.dataset.ixBound) { return; }
      f.dataset.ixBound = '1';
      var typeSel = f.querySelector('select[name=type]');
      if (typeSel && f.dataset.prevType === undefined) {
        f.dataset.prevType = typeSel.value;
      } else if (f.querySelector('input[name=type]') && f.dataset.prevType === undefined) {
        var curType = f.querySelector('input[name=type]:checked');
        f.dataset.prevType = curType ? curType.value : '';
      }
      if ((f.querySelector('select[name="tags[]"]') || f.querySelector('input[name="tags[]"]')) && f.dataset.prevTags === undefined) {
        f.dataset.prevTags = ixTagState(f);
      }
      f.addEventListener('change', function (ev) {
        if (f.hasAttribute('data-case-product') && ev.target && ev.target.name === 'tags[]') {
          var key = ev.target.value || '';
          var products = { sol: 1, batteri: 1, elbilsladdare: 1 };
          document.querySelectorAll('[data-panel-quote], [data-panel-preview]').forEach(function (quote) {
            var prod = quote.querySelector('input[name=product]');
            var btn = quote.querySelector('button');
            var noMail = quote.getAttribute('data-no-mail') === '1';
            var sent = quote.getAttribute('data-sent') === '1';
            if (prod) { prod.value = products[key] ? key : ''; }
            if (btn && !sent) { btn.disabled = !products[key] || noMail; }
          });
        }
        if (f.hasAttribute('data-panel-class') && ev.target && ev.target.name === 'panel_class') {
          var root = f.closest('[data-panel-root]');
          var quote = root ? root.querySelector('[data-panel-quote]') : null;
          var products = { sol: 1, batteri: 1, elbilsladdare: 1 };
          var key = ev.target.value || '';
          if (quote) {
            var prod = quote.querySelector('input[name=product]');
            var btn = quote.querySelector('button');
            var noMail = quote.getAttribute('data-no-mail') === '1';
            var sent = quote.getAttribute('data-sent') === '1';
            if (prod) { prod.value = products[key] ? key : ''; }
            if (btn && !sent) { btn.disabled = !products[key] || noMail; }
          }
        }
        var userSave = ev.isTrusted || f.dataset.ixUser === '1';
        if (f.dataset.ixUser === '1') { delete f.dataset.ixUser; }
        if (!userSave) { return; }
        var st = ev.target.matches && ev.target.matches('[data-status]') ? ev.target.value : null;
        if (st) { document.querySelectorAll('[data-status-mirror]').forEach(function (x) { x.value = st; }); }
        var park = f.querySelector('[data-park-until]');
        if (park) { document.querySelectorAll('[data-park-mirror]').forEach(function (x) { x.value = park.value; }); }
        var parked = f.querySelector('input[type="radio"][name="status"][value="parked"]');
        var out = f.querySelector('[data-saved]') || (f.parentNode && f.parentNode.querySelector('[data-saved]'));
        var clsChange = ev.target && (ev.target.name === 'type' || ev.target.name === 'tags[]');
        var clsSnap = null;
        if (clsChange) {
          clsSnap = ixClsSnap(f, ev.target.name === 'type' ? ev.target.value : '');
          paintCls();
          if (ev.target.name === 'type') { ixPaintListType(ev.target.value); ixClearReason(); }
        }
        if (parked && parked.checked && park && !park.value) {
          showNote('Parkerad kräver ett datum.', '');
          if (out) { out.textContent = 'Parkerad kräver ett datum.'; }
          return;
        }
        if (out) { out.textContent = 'Sparar…'; }
        if (f.dataset.autosave === 'reload') { f.submit(); return; }
        var url = f.getAttribute('action') || '';
        fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'format=json', { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
          .then(function (x) {
            var row = x[1] && x[1].row;
            var savedOk = x[0] && x[1].ok !== false;
            if (!savedOk) {
              if (clsSnap) { ixClsRestore(clsSnap); }
              var err = (x[1] && (x[1].error || x[1].message)) || 'Kunde inte spara';
              showNote(err, '');
              if (out) { out.textContent = err; }
              return;
            }
            if (clsSnap && ev.target && ev.target.name === 'type') { f.dataset.prevType = ev.target.value; }
            if (clsSnap && ev.target && ev.target.name === 'tags[]') { f.dataset.prevTags = ixTagState(f); }
            if (row && row.learn && row.undo) { learnToast(out, row); return; }
            if (!out) { return; }
            out.textContent = '✓ Sparat';
            setTimeout(function () { if (out.textContent === '✓ Sparat') { out.textContent = ''; } }, 2500);
          })
          .catch(function () {
            if (clsSnap) { ixClsRestore(clsSnap); }
            showNote('Kunde inte spara.', '');
            if (out) { out.textContent = 'Kunde inte spara.'; }
          });
      });
    });
    var c = root.querySelector('#ix-composer');
    if (c && !c.dataset.ixBound) {
      c.dataset.ixBound = '1';
      var ta = c.querySelector('textarea'), sb = c.querySelector('[data-savebtn]');
      function sendLabel() {
        var n = c.querySelector('input[name=kind]:checked') && c.querySelector('input[name=kind]:checked').value === 'note';
        if (n) { return 'Spara anteckning'; }
        var face = c.querySelector('input[name=status_after]:checked');
        var map = { closed: 'Skicka och stäng', open: 'Skicka som öppet', parked: 'Skicka som parkerat' };
        return map[face ? face.value : 'closed'] || 'Skicka och stäng';
      }
      var ccBtn = c.querySelector('[data-cc]'), cc = c.querySelector('input[name=cc]');
      if (ccBtn && cc) {
        ccBtn.addEventListener('click', function () { cc.hidden = !cc.hidden; if (!cc.hidden) { cc.focus(); } });
      }
      function noteMode() {
        return c.querySelector('input[name=kind]:checked') && c.querySelector('input[name=kind]:checked').value === 'note';
      }
      function syncSend() {
        var blocked = c.getAttribute('data-no-mail') === '1' && !noteMode();
        if (sb) { sb.disabled = blocked; }
        c.querySelectorAll('.ix-car').forEach(function (b) { b.disabled = blocked; });
      }
      syncSend();
      c.addEventListener('submit', function (ev) {
        if (c.getAttribute('data-busy') === '1' || (c.getAttribute('data-no-mail') === '1' && !noteMode())) {
          ev.preventDefault();
          return;
        }
        var note = noteMode();
        var face = c.querySelector('input[name=status_after]:checked');
        var date = c.querySelector('[data-send-park]');
        var out = c.querySelector('[data-saved]');
        if (!note && face && face.value === 'parked' && date && !date.value) {
          ev.preventDefault();
          showNote('Parkerad kräver ett datum.', '');
          if (out) { out.textContent = 'Parkerad kräver ett datum.'; }
          var m = document.getElementById('ix-sd');
          if (m) { closeMenus(m); ixMenuShow(m); }
          return;
        }
        ev.preventDefault();
        c.setAttribute('data-busy', '1');
        if (sb) {
          sb.setAttribute('aria-busy', 'true');
          sb.textContent = note ? 'Sparar…' : 'Skickar…';
          setTimeout(function () { sb.disabled = true; }, 0);
        }
        var fd = new FormData(c);
        var sub = ev.submitter;
        if (sub && sub.name) { fd.set(sub.name, sub.value); }
        var doSend = !!(sub && sub.name === 'do' && sub.value === 'send' && !note);
        var sendFace = face ? face.value : 'closed';
        var actionUrl = c.getAttribute('action') || '';
        fetch(actionUrl + (actionUrl.indexOf('?') < 0 ? '?' : '&') + 'format=json', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
          .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
          .then(function (x) {
            var savedOk = x[0] && x[1] && x[1].ok !== false;
            if (!savedOk) {
              c.removeAttribute('data-busy');
              if (sb) { sb.removeAttribute('aria-busy'); sb.disabled = c.getAttribute('data-no-mail') === '1' && !noteMode(); sb.textContent = sendLabel(); }
              var err = (x[1] && (x[1].error || x[1].message)) || 'Kunde inte spara';
              showNote(err, '');
              if (out) { out.textContent = err; }
              return;
            }
            if (doSend && sendFace === 'closed') {
              var leave = new URL(location.href);
              leave.searchParams.delete('id');
              location.href = leave.pathname + '?' + leave.searchParams.toString();
              return;
            }
            var here = new URL(location.href);
            Object.keys(ixMem).forEach(function (k) { delete ixMem[k]; });
            ixLoadThread(here, true).then(function (j) { ixPaintThread(j, here, true); }).catch(function () { location.reload(); });
          })
          .catch(function () {
            c.removeAttribute('data-busy');
            if (sb) { sb.removeAttribute('aria-busy'); sb.disabled = c.getAttribute('data-no-mail') === '1' && !noteMode(); sb.textContent = sendLabel(); }
            showNote('Kunde inte spara.', '');
            if (out) { out.textContent = 'Kunde inte spara.'; }
          });
      });
      if (window.jeabInkorgBindReplyDraft) {
        window.jeabInkorgBindReplyDraft(c, sessionStorage);
        if (ta && ta.value) {
          try { ta.dispatchEvent(new Event('input', { bubbles: true })); } catch (e) {}
        }
      }
      c.querySelectorAll('input[name=kind]').forEach(function (r) {
        r.addEventListener('change', function () {
          var n = c.querySelector('input[name=kind]:checked').value === 'note';
          c.classList.toggle('is-note', n);
          ta.placeholder = n ? 'Intern anteckning – syns bara för kollegor…' : (ta.getAttribute('data-reply-ph') || 'Skriv ett svar till kunden…');
          sb.textContent = sendLabel();
          syncSend();
        });
      });
      c.querySelectorAll('input[name=status_after]').forEach(function (r) {
        r.addEventListener('change', function () {
          sb.textContent = sendLabel();
          if (r.value !== 'parked') { var m = document.getElementById('ix-sd'); if (m) { m.hidden = true; } }
        });
      });
      root.querySelectorAll('.ix-dl form, .ix-dl button').forEach(function (el) {
        if (el.dataset.ixDraftBusy) { return; }
        el.dataset.ixDraftBusy = '1';
      });
      root.querySelectorAll('.ix-dl form').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
          if (f.getAttribute('data-busy') === '1') { ev.preventDefault(); return; }
          f.setAttribute('data-busy', '1');
          var box = f.closest('.ix-dl');
          if (!box) { return; }
          box.querySelectorAll('button').forEach(function (btn) {
            btn.setAttribute('aria-busy', 'true');
            setTimeout(function () { btn.disabled = true; }, 0);
          });
        });
      });
      root.querySelectorAll('[data-edit-draft]').forEach(function (b) {
        b.addEventListener('click', function () {
          var msg = b.closest('.ix-msg');
          ta.value = msg.querySelector('[data-draft-body]').textContent;
          if (window.jeabInkorgReplyDraftWrite) {
            var threadEl = c.querySelector('input[name=thread]');
            window.jeabInkorgReplyDraftWrite(sessionStorage, threadEl ? threadEl.value : '', ta.value);
          }
          c.querySelector('[data-replace]').value = b.dataset.editDraft;
          cc.value = b.dataset.cc || '';
          if (cc.value) { cc.hidden = false; }
          var reply = c.querySelector('input[name=kind][value=reply]');
          if (reply) { reply.checked = true; reply.dispatchEvent(new Event('change', { bubbles: true })); }
          ta.focus();
        });
      });
    }
    var nm = document.getElementById('ix-newmail');
    if (nm && !nm.dataset.ixBound) {
      nm.dataset.ixBound = '1';
      var form = nm.querySelector('form');
      if (form) {
        var storeKey = 'jeab-ix-newmail';
        try {
          var savedNew = sessionStorage.getItem(storeKey);
          if (savedNew) {
            var bag = JSON.parse(savedNew);
            ['to', 'cc', 'subject', 'body', 'company'].forEach(function (n) {
              var el = form.elements[n];
              if (el && bag && typeof bag[n] === 'string' && el.value === '') { el.value = bag[n]; }
            });
          }
        } catch (e) {}
        form.addEventListener('input', function () {
          var next = {};
          ['to', 'cc', 'subject', 'body', 'company'].forEach(function (n) {
            var el = form.elements[n];
            next[n] = el ? el.value : '';
          });
          try { sessionStorage.setItem(storeKey, JSON.stringify(next)); } catch (e) {}
          var status = form.querySelector('[data-newmail-saved]');
          if (status) { status.textContent = 'Utkast sparat i webbläsaren.'; }
        });
        form.addEventListener('submit', function (ev) {
          if (form.getAttribute('data-busy') === '1') { ev.preventDefault(); return; }
          ev.preventDefault();
          form.setAttribute('data-busy', '1');
          var sendBtn = form.querySelector('button[type=submit]');
          if (sendBtn) {
            sendBtn.setAttribute('aria-busy', 'true');
            sendBtn.textContent = 'Skickar…';
            setTimeout(function () { sendBtn.disabled = true; }, 0);
          }
          try { sessionStorage.removeItem(storeKey); } catch (e) {}
          var fd = new FormData(form);
          var actionUrl = form.getAttribute('action') || '';
          var status = form.querySelector('[data-newmail-saved]');
          fetch(actionUrl + (actionUrl.indexOf('?') < 0 ? '?' : '&') + 'format=json', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
            .then(function (x) {
              var savedOk = x[0] && x[1] && x[1].ok !== false;
              if (!savedOk) {
                form.removeAttribute('data-busy');
                if (sendBtn) { sendBtn.removeAttribute('aria-busy'); sendBtn.disabled = false; sendBtn.textContent = 'Skicka'; }
                var err = (x[1] && (x[1].error || x[1].message)) || 'Kunde inte skicka';
                if (status) { status.textContent = err; }
                showNote(err, '');
                return;
              }
              var row = (x[1] && x[1].row) || {};
              var dest = new URL(location.href);
              dest.searchParams.set('p', 'inkorg');
              if (row.thread_id) { dest.searchParams.set('id', String(row.thread_id)); }
              else { dest.searchParams.delete('id'); }
              location.href = dest.pathname + '?' + dest.searchParams.toString();
            })
            .catch(function () {
              form.removeAttribute('data-busy');
              if (sendBtn) { sendBtn.removeAttribute('aria-busy'); sendBtn.disabled = false; sendBtn.textContent = 'Skicka'; }
              if (status) { status.textContent = 'Kunde inte skicka.'; }
              showNote('Kunde inte skicka.', '');
            });
        });
      }
    }
    if (window.jeabInkorgSwipeBind) { window.jeabInkorgSwipeBind(); }
  }
  window.jeabIxBind = jeabIxBind;
  var emptyConv = '<section class="ix-conv" id="ix-conv" aria-label="Ärende"><div class="ix-cmain"><div class="empty ix-empty"><p>Inget ärende valt.</p><p class="ix-empty-hint">Välj ett mejl i listan så visas det här.</p></div></div><aside class="ix-cust ix-side ix-cust-empty" aria-label="Kund"><h3>Kund</h3><p class="ix-muted">Ingen kund vald.</p></aside></section>';
  var ixMem = Object.create(null);
  var ixLists = Object.create(null);
  var ixPending = Object.create(null);
  var ixQueue = [];
  var ixFlight = 0;
  var ixNav = window.jeabInkorgNavCreate();
  var ixCountsTimer = 0;
  var ixPumpTimer = 0;
  var ixCtrl = null;
  function ixIsAbort(err) {
    return !!(err && (err.name === 'AbortError' || err.code === 20));
  }
  function ixAbort() {
    if (ixCtrl) {
      try { ixCtrl.abort(); } catch (e) {}
    }
    ixCtrl = null;
    ixQueue = [];
    ixPending = Object.create(null);
  }
  function ixAbortMore() {
    if (ixMoreCtrl) {
      try { ixMoreCtrl.abort(); } catch (e) {}
    }
    ixMoreCtrl = null;
  }
  function ixCancelFlight() {
    window.jeabInkorgNavBump(ixNav);
    ixFlight = ixNav.flight;
    ixAbort();
    ixAbortMore();
    var box = document.getElementById('ix');
    if (box) { box.removeAttribute('aria-busy'); }
    ixBgRelease();
  }
  function ixSignal() {
    if (!ixCtrl) { ixCtrl = new AbortController(); }
    return ixCtrl.signal;
  }
  function ixRev(url) {
    var id = url.searchParams.get('id') || '';
    var card = ixCard(id);
    return card ? (card.getAttribute('data-ix-rev') || '') : '';
  }
  function ixMemKey(url) {
    return (url.searchParams.get('box') || '') + '\n' + (url.searchParams.get('id') || '') + '\n' + ixRev(url);
  }
  function ixCard(id) {
    var cards = document.querySelectorAll('#ix-items-wrap .ix-it');
    for (var i = 0; i < cards.length; i++) {
      if (cards[i].getAttribute('data-ix-id') === id) { return cards[i]; }
    }
    return null;
  }
  function ixOptimisticRead(id) {
    if (!id) { return; }
    var card = ixCard(id);
    if (card && card.getAttribute('data-ix-unread') !== '1') { return; }
    if (card) {
      if (window.jeabIxSetUnread) { window.jeabIxSetUnread(card, false); }
      else { card.classList.remove('is-unread'); card.setAttribute('data-ix-unread', '0'); }
    }
    var undo = function () {
      if (!card) { return; }
      if (window.jeabIxSetUnread) { window.jeabIxSetUnread(card, true); }
      else { card.classList.add('is-unread'); card.setAttribute('data-ix-unread', '1'); }
    };
    var req = window.jeabIxPost
      ? window.jeabIxPost({ action: 'inkorg.read', thread: id, read: '1' })
      : Promise.resolve({ ok: false });
    req.then(function (res) { if (!res || !res.ok) { undo(); } }).catch(undo);
  }
  function ixCounts(counts, unread) {
    if (counts && typeof counts === 'object') {
      Object.keys(counts).forEach(function (id) {
        var text = window.jeabInkorgCountText ? window.jeabInkorgCountText(counts[id]) : (typeof counts[id] === 'number' ? String(counts[id]) : null);
        if (text === null) { return; }
        document.querySelectorAll('[data-ix-count]').forEach(function (el) {
          if (el.getAttribute('data-ix-count') === id) { el.textContent = text; }
        });
      });
    }
    if (typeof unread !== 'number' || unread < 0) { return; }
    var tab = document.querySelector('nav.tabs a.tab.is-on');
    if (!tab) { return; }
    var badge = tab.querySelector('.tab-count');
    if (!unread) {
      if (badge) { badge.remove(); }
      return;
    }
    if (!badge) {
      tab.appendChild(document.createTextNode(' '));
      badge = document.createElement('span');
      badge.className = 'tab-count';
      tab.appendChild(badge);
    }
    badge.textContent = String(unread);
    badge.setAttribute('aria-label', unread + ' olästa');
  }
  var ixCountHold = null;
  var ixCountByBox = Object.create(null);
  var ixMoreCtrl = null;
  function ixAddressBox(url) {
    var box = window.jeabInkorgAddressBox ? window.jeabInkorgAddressBox(url.searchParams) : (url.searchParams.get('box') || '');
    if (box) { return box; }
    var ix = document.getElementById('ix');
    return ix ? (ix.getAttribute('data-ix-box') || '') : '';
  }
  function ixCountBoxNow() {
    var loc = '';
    var dom = '';
    try { loc = ixAddressBox(new URL(location.href)); } catch (e) {}
    var ix = document.getElementById('ix');
    if (ix) { dom = ix.getAttribute('data-ix-box') || ''; }
    return { gen: window.__ixCountGen || 0, locationBox: loc, domBox: dom };
  }
  function ixCountScope(url) {
    var scope = '';
    try { scope = (url && url.searchParams && url.searchParams.get('scope')) || ''; } catch (e) {}
    if (!scope) {
      try { scope = new URL(location.href).searchParams.get('scope') || ''; } catch (e2) {}
    }
    return scope === 'egna' ? 'egna' : 'allas';
  }
  function ixSaveBoxCounts(box, scope, counts, unread) {
    if (!box || !window.jeabInkorgCountsHaveNumbers || !window.jeabInkorgCountsHaveNumbers(counts)) { return; }
    var key = window.jeabInkorgCountCacheKey ? window.jeabInkorgCountCacheKey(box, scope) : (box + '\n' + scope);
    ixCountByBox[key] = { box: box, counts: counts, unread: unread };
  }
  function ixBlankCounts() {
    document.querySelectorAll('[data-ix-count]').forEach(function (el) {
      el.textContent = '…';
      el.setAttribute('aria-busy', 'true');
    });
  }
  function ixCountsRemember(box, counts, unread, decision, scope) {
    if (decision.paint || decision.hold) {
      ixCountHold = { gen: window.__ixCountGen || 0, box: box, counts: counts, unread: unread };
    }
    if (decision.paint) {
      ixSaveBoxCounts(box, scope || ixCountScope(null), counts, unread);
      ixCounts(counts, unread);
    }
  }
  function ixCountsRepaint() {
    if (!ixCountHold || ixCountHold.gen !== (window.__ixCountGen || 0)) { return; }
    var decision = window.jeabInkorgCountsDecision
      ? window.jeabInkorgCountsDecision({ gen: ixCountHold.gen, box: ixCountHold.box, left: 0 }, ixCountBoxNow(), { fresh: 1, counts: ixCountHold.counts, box: ixCountHold.box })
      : { paint: true };
    if (decision.paint) { ixCounts(ixCountHold.counts, ixCountHold.unread); }
  }
  function ixCountsSoon() {
    if (document.visibilityState === 'hidden') { return; }
    if (ixCountsTimer) { return; }
    ixCountsTimer = setTimeout(function () {
      ixCountsTimer = 0;
      var url;
      try { url = new URL(location.href); } catch (e) { return; }
      if (url.searchParams.get('p') !== 'inkorg') { return; }
      ixCountsFor(url);
    }, 0);
  }
  function ixBgHold() {
    if (!window.jeabBackground) { return true; }
    if (window.__ixBg) { return true; }
    var ctrl = window.jeabBackground.take();
    if (!ctrl) { return false; }
    window.__ixBg = ctrl;
    return true;
  }
  function ixBgRelease() {
    if (ixFlight > 0 || ixQueue.length) { return; }
    if (window.__ixBg && window.jeabBackground) { window.jeabBackground.release(window.__ixBg); }
    window.__ixBg = null;
  }
  function ixPump() {
    if (ixFlight >= 1 || !ixQueue.length) { return; }
    var job = ixQueue.shift();
    var epoch = window.jeabInkorgNavStart(ixNav);
    if (epoch === false) {
      ixQueue.unshift(job);
      ixFlight = ixNav.flight;
      if (!ixPumpTimer) {
        ixPumpTimer = setTimeout(function () { ixPumpTimer = 0; ixPump(); }, 30);
      }
      return;
    }
    ixFlight = ixNav.flight;
    Promise.resolve().then(function () {
      if (epoch !== ixNav.epoch) { return; }
      return job();
    }).then(function () {}, function () {}).then(function () {
      if (!window.jeabInkorgNavFinish(ixNav, epoch)) { return; }
      ixFlight = ixNav.flight;
      ixBgRelease();
      ixPump();
    });
  }
  function ixLoadThread(url, interactive) {
    var key = ixMemKey(url);
    var rev = ixRev(url);
    if (ixMem[key] && typeof ixMem[key].html === 'string' && (!rev || !ixMem[key].rev || ixMem[key].rev === rev)) { return Promise.resolve(ixMem[key]); }
    if (ixPending[key]) { return ixPending[key]; }
    var req = new URL(url.href);
    req.searchParams.set('ix_part', 'thread');
    req.searchParams.set('ix_open', req.searchParams.get('id') || '');
    if (rev) { req.searchParams.set('ix_rev', rev); }
    var headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
    if (interactive) { headers['X-Jeab-Interactive'] = '1'; }
    else { headers.Purpose = 'prefetch'; }
    var ctrl = ixSignal();
    var mine = ixCtrl;
    var timer = setTimeout(function () {
      if (ixCtrl === mine && mine) {
        try { mine.abort(); } catch (e) {}
      }
    }, 10000);
    var p = fetch(req.pathname + '?' + req.searchParams.toString(), { signal: ctrl, headers: headers, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (text) {
          var parsed = null;
          if (text && String(text).trim()) {
            try { parsed = JSON.parse(text); } catch (e) { parsed = null; }
          }
          if (!r.ok) {
            var status = new Error('status');
            status.diag = (parsed && parsed.diag) || 'status';
            throw status;
          }
          if (!parsed) {
            var payload = new Error('payload');
            payload.diag = 'payload';
            throw payload;
          }
          return parsed;
        });
      })
      .then(function (j) {
        var revPlan = window.jeabInkorgThreadRevDecision
          ? window.jeabInkorgThreadRevDecision(ixRev(url), j)
          : null;
        function ixStoreLoaded(payload) {
          if (!interactive && window.jeabInkorgCachePrefetch) {
            payload = window.jeabInkorgCachePrefetch(payload);
          }
          ixMem[key] = payload;
          return payload;
        }
        if (revPlan) {
          if (revPlan.busy) { var busy = new Error('busy'); busy.busy = true; busy.diag = 'busy'; throw busy; }
          if (!revPlan.paint) { var bad = new Error('payload'); bad.diag = revPlan.diag || 'empty'; throw bad; }
          return ixStoreLoaded(j);
        }
        if (j && j.busy && !(j.html && String(j.html).trim())) { var busy2 = new Error('busy'); busy2.busy = true; busy2.diag = 'busy'; throw busy2; }
        if (!j || j.ok === false || typeof j.html !== 'string' || !String(j.html).trim()) {
          var empty = new Error('payload');
          empty.diag = (j && j.diag) || 'empty';
          throw empty;
        }
        return ixStoreLoaded(j);
      })
      .catch(function (err) {
        if (ixIsAbort(err) && ixCtrl === mine) {
          var timeout = new Error('timeout');
          timeout.timeout = true;
          timeout.diag = 'timeout';
          throw timeout;
        }
        throw err;
      })
      .finally(function () { clearTimeout(timer); delete ixPending[key]; });
    ixPending[key] = p;
    return p;
  }
  function ixPrefetch(url) {
    var id = url.searchParams.get('id') || '';
    var key = ixMemKey(url);
    if (id === '' || (ixMem[key] && typeof ixMem[key].html === 'string') || ixPending[key] || ixFlight >= 1 || ixQueue.length >= 1 || !ixBgHold()) { return; }
    ixQueue.push(function () {
      if (ixMem[key]) { return Promise.resolve(); }
      return ixLoadThread(url);
    });
    ixPump();
  }
  function ixPrefetchTop() {
    var addressed = window.jeabInkorgThreadRequest ? window.jeabInkorgThreadRequest(location.href) : null;
    if (addressed && addressed.id) {
      try { ixPrefetch(new URL(location.href)); } catch (e) {}
      return;
    }
    var links = document.querySelectorAll('#ix-items-wrap .ix-it a.item-main');
    var n = 0;
    for (var i = 0; i < links.length && n < 1; i++) {
      var u;
      try { u = new URL(links[i].href, location.href); } catch (e) { continue; }
      if (!u.searchParams.get('id')) { continue; }
      ixPrefetch(u);
      n++;
    }
  }
  function ixStoreThread(payload, url) {
    if (!payload || !payload.id || typeof payload.html !== 'string') { return; }
    var threadUrl;
    try { threadUrl = new URL(url.href); } catch (e) { return; }
    threadUrl.searchParams.set('id', payload.id);
    threadUrl.searchParams.delete('ix_part');
    threadUrl.searchParams.delete('ix_warm');
    ixMem[ixMemKey(threadUrl)] = payload;
  }
  function ixWarm() {
    if (document.visibilityState === 'hidden') { return; }
    var url;
    try { url = new URL(location.href); } catch (e) { return; }
    if (url.searchParams.get('p') !== 'inkorg') { return; }
    if (!(url.searchParams.get('id') || '')) { return; }
    ixLoadThread(url, false);
  }
  /*
   * Dator: visa översta ärendet direkt så att tråden och KUND-panelen är fyllda.
   * Samma läsning som förhämtningen (ix_part=thread, Purpose: prefetch). Adressen
   * ändras inte och ärendet markeras aldrig som läst; det gör bara ett eget klick.
   * Bara från 1024 px där lista och tråd står bredvid varandra; under det döljer
   * ett öppet ärende listan, så platta och mobil visar listan som förut.
   */
  var ixAutoSeq = 0;
  var ixAutoTimer = 0;
  var ixAutoDelays = [700, 1800, 4000];
  function ixAutoOpenTop(attempt) {
    attempt = attempt || 0;
    if (!window.matchMedia || !window.matchMedia('(min-width: 1024px)').matches) { return; }
    var box = document.getElementById('ix');
    if (!box || box.classList.contains('is-open')) { return; }
    try { if (new URL(location.href).searchParams.get('id')) { return; } } catch (e) { return; }
    var link = document.querySelector('#ix-items-wrap .ix-it a.item-main');
    if (!link) { box.setAttribute('data-ix-auto', 'tom'); return; }
    var url;
    try { url = new URL(link.href, location.href); } catch (e) { return; }
    var id = url.searchParams.get('id') || '';
    if (!id) { return; }
    var epoch = ixNav.epoch;
    var seq = ++ixAutoSeq;
    if (ixAutoTimer) { clearTimeout(ixAutoTimer); ixAutoTimer = 0; }
    box.setAttribute('data-ix-auto', attempt ? 'igen' : 'hamtar');
    function still() {
      var now = document.getElementById('ix');
      var asked = '';
      try { asked = new URL(location.href).searchParams.get('id') || ''; } catch (e) {}
      if (seq !== ixAutoSeq || !now || now.classList.contains('is-open') || asked !== '') { return null; }
      if (epoch !== ixNav.epoch) { return null; }
      var top = document.querySelector('#ix-items-wrap .ix-it a.item-main');
      if (!top || top.href !== link.href) { return null; }
      return now;
    }
    ixLoadThread(url, false).then(function (j) {
      var now = still();
      if (!now) { return; }
      if (!j || !j.id || typeof j.html !== 'string') { now.setAttribute('data-ix-auto', 'tomt'); return; }
      var copy = {};
      for (var k in j) { if (Object.prototype.hasOwnProperty.call(j, k)) { copy[k] = j[k]; } }
      copy.read_on_open = false;
      copy.mark_read = false;
      ixPaintThread(copy, url, true);
      now = document.getElementById('ix');
      if (now) { now.setAttribute('data-ix-auto', 'ok'); }
    }).catch(function (err) {
      // Avbruten, upptagen eller långsam hämtning vid första inladdningen: försök igen.
      // Ett listbyte, ett eget klick eller en ny adress stoppar försöken (still()).
      var now = document.getElementById('ix');
      var diag = (err && (err.diag || err.name)) || 'fel';
      if (now) { now.setAttribute('data-ix-auto', 'fel:' + diag); }
      if (attempt >= ixAutoDelays.length) { return; }
      ixAutoTimer = setTimeout(function () {
        ixAutoTimer = 0;
        if (seq !== ixAutoSeq) { return; }
        var box2 = document.getElementById('ix');
        if (!box2 || box2.classList.contains('is-open')) { return; }
        var top = document.querySelector('#ix-items-wrap .ix-it a.item-main');
        if (!top || top.href !== link.href) { return; }
        ixAutoOpenTop(attempt + 1);
      }, ixAutoDelays[attempt]);
    });
  }
  function ixApplyList(j, url) {
    ixAbort();
    ixMem = Object.create(null);
    var wrap = document.getElementById('ix-items-wrap');
    if (wrap && typeof j.html === 'string') { wrap.innerHTML = j.html; }
    var view = (j.view || url.searchParams.get('view') || '');
    var scope = url.searchParams.get('scope') || 'allas';
    var range = document.getElementById('ix-range');
    if (range && typeof j.range === 'string') {
      range.textContent = j.range;
      if (j.box) { range.setAttribute('data-ix-box', j.box); }
      if (view) { range.setAttribute('data-ix-view', view); }
    }
    if (j.counts && window.jeabInkorgCountsHaveNumbers && window.jeabInkorgCountsHaveNumbers(j.counts)) {
      ixSaveBoxCounts(j.box || url.searchParams.get('box') || '', scope, j.counts, typeof j.unread === 'number' ? j.unread : -1);
      ixCounts(j.counts, typeof j.unread === 'number' ? j.unread : -1);
    }
    if (j.thread) { ixStoreThread(j.thread, url); }
    var ixCountsAsked = false;
    if (!Object.prototype.hasOwnProperty.call(j, 'thread')) {
      if (j.counts_fresh && j.counts) { ixPrefetchTop(); }
      else { ixCountsFor(url); ixCountsAsked = true; }
      ixWarm();
    }
    /* Byte Egna/Alla: svaret räknar bara den här vyn, så hämta alla flikars tal för nya scopet (GET, inga skrivningar). */
    var ixPrevScopeEl = document.querySelector('.ix-types a.chip.is-on[data-ix-scope]');
    var ixPrevScope = ixPrevScopeEl ? (ixPrevScopeEl.getAttribute('data-ix-scope') || '') : '';
    if (ixPrevScope && ixPrevScope !== scope && !ixCountsAsked) { ixCountsFor(url); }
    var title = document.querySelector('#ix-list .crm-section-title');
    if (title && j.title) { title.textContent = j.title; }
    document.querySelectorAll('[data-ix-view]').forEach(function (el) {
      var on = el.getAttribute('data-ix-view') === view;
      el.classList.toggle('is-on', on);
      if (on) { el.setAttribute('aria-current', 'page'); } else { el.removeAttribute('aria-current'); }
    });
    document.querySelectorAll('.ix-views a.ix-v').forEach(function (el) {
      var u;
      try { u = new URL(el.href, location.href); } catch (e) { return; }
      var on = (u.searchParams.get('view') || '') === view;
      el.classList.toggle('is-on', on);
      if (on) { el.setAttribute('aria-current', 'page'); } else { el.removeAttribute('aria-current'); }
    });
    ixMarkScope(scope);
    ixMarkQueue(url.searchParams.get('ko') || '');
    ixSyncScopeHrefs(url);
    var ix = document.getElementById('ix');
    if (ix) {
      ix.classList.remove('is-open');
      ix.removeAttribute('data-ix-open');
      if (j.box) { ix.setAttribute('data-ix-box', j.box); }
      if (view) { ix.setAttribute('data-ix-view', view); }
    }
    var conv = document.getElementById('ix-conv');
    if (conv) { conv.outerHTML = emptyConv; }
    var form = document.querySelector('#ix-list form.ix-lhead');
    if (form) {
      var v = form.querySelector('input[name=view]');
      if (v) { v.value = view; }
      var s = form.querySelector('input[name=scope]');
      if (s) { s.value = scope; }
      var q = form.querySelector('input[name=q]');
      if (q) { q.value = url.searchParams.get('q') || ''; }
      var b = form.querySelector('input[name=box]');
      var formBox = j.box || url.searchParams.get('box') || '';
      if (b && formBox) { b.value = formBox; }
    }
    document.querySelectorAll('#ix-items-wrap .ix-it').forEach(function (el) { el.classList.remove('is-on'); });
    var list = document.getElementById('ix-list');
    if (list) { list.setAttribute('data-ix-q', url.searchParams.get('q') || ''); }
    if (window.jeabInkorgSwipeBind) { window.jeabInkorgSwipeBind(); }
    ixRememberList(url);
    ixAutoOpenTop();
  }
  function ixMarkScope(scope) {
    scope = scope === 'egna' ? 'egna' : 'allas';
    ixMarkEgnaFallback(scope);
    document.querySelectorAll('.ix-types a.chip[data-ix-scope]').forEach(function (el) {
      var on = (el.getAttribute('data-ix-scope') || 'allas') === scope;
      el.classList.toggle('is-on', on);
    });
  }
  /* Egna utan känd person visar Alla. Raden följer med vid varje listbyte, inte bara vid omladdning. */
  function ixMarkEgnaFallback(scope) {
    document.querySelectorAll('[data-ix-egna-fallback]').forEach(function (el) {
      var unknown = el.getAttribute('data-ix-egna-unknown') === '1';
      if (scope === 'egna' && unknown) { el.removeAttribute('hidden'); } else { el.setAttribute('hidden', ''); }
    });
  }
  function ixMarkQueue(ko) {
    document.querySelectorAll('a[data-ix-queue]').forEach(function (el) {
      var on = (el.getAttribute('data-ix-queue') || '') === ko && ko !== '';
      el.classList.toggle('is-on', on);
      if (on) { el.setAttribute('aria-current', 'page'); }
      else { el.removeAttribute('aria-current'); }
    });
  }
  function ixSyncScopeHrefs(url) {
    if (!window.jeabInkorgScopeHref || !url) { return; }
    var base = url.pathname + url.search;
    document.querySelectorAll('a[data-ix-scope]').forEach(function (el) {
      el.setAttribute('href', window.jeabInkorgScopeHref(base, el.getAttribute('data-ix-scope') || 'allas'));
    });
    /* Flikarna (Inkorg … Historik) behåller vald Egna/Alla efter ett listbyte i JS. */
    var cur = url.searchParams ? url.searchParams.get('scope') : '';
    if (cur !== 'egna' && cur !== 'allas') { return; }
    document.querySelectorAll('#ix a.smart-tile[data-ix-view]').forEach(function (el) {
      try {
        var t = new URL(el.getAttribute('href') || '', location.href);
        if (t.searchParams.get('scope') === cur) { return; }
        t.searchParams.set('scope', cur);
        el.setAttribute('href', t.pathname + t.search);
      } catch (e) {}
    });
  }
  function ixAppendPage(url) {
    if (!url) { return; }
    ixAbortMore();
    ixMoreCtrl = new AbortController();
    var signal = ixMoreCtrl.signal;
    var req = new URL(url.href);
    req.searchParams.set('p', 'inkorg');
    req.searchParams.set('ix_part', 'all');
    req.searchParams.delete('id');
    req.searchParams.delete('ix_open');
    var asked = ixAddressBox(req);
    if (asked) { req.searchParams.set('box', asked); }
    var askedView = req.searchParams.get('view') || '';
    fetch(req.pathname + '?' + req.searchParams.toString(), { signal: signal, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-Jeab-Interactive': '1' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) { throw new Error('status'); } return r.json(); })
      .then(function (j) {
        if (signal.aborted || !j || j.ok === false || typeof j.html !== 'string') { return; }
        var locBox = '';
        try { locBox = ixAddressBox(new URL(location.href)); } catch (e) {}
        if (window.jeabInkorgResponseLive && !window.jeabInkorgResponseLive(asked, j.box || '', locBox)) { return; }
        if (askedView && j.view && askedView !== j.view) { return; }
        var host = document.createElement('div');
        host.innerHTML = j.html;
        var list = document.querySelector('#ix-items-wrap ul.ix-items');
        if (!list) { ixApplyList(j, url); return; }
        host.querySelectorAll('li.ix-it').forEach(function (li) {
          var id = li.getAttribute('data-ix-id') || '';
          if (id && list.querySelector('[data-ix-id="' + id.replace(/"/g, '') + '"]')) { return; }
          list.appendChild(li);
        });
        var wrap = document.getElementById('ix-items-wrap');
        var oldNav = wrap ? wrap.querySelector('nav[aria-label="Sidor"]') : null;
        if (oldNav) { oldNav.remove(); }
        var newNav = host.querySelector('nav[aria-label="Sidor"]');
        if (newNav && wrap) { wrap.appendChild(newNav); }
        var shown = list.querySelectorAll('li.ix-it').length;
        var total = typeof j.total === 'number' ? j.total : shown;
        var range = document.getElementById('ix-range');
        if (range && (!asked || range.getAttribute('data-ix-box') === asked)) {
          range.textContent = window.jeabInkorgShownRange ? window.jeabInkorgShownRange(shown, total) : String(shown);
          if (j.box) { range.setAttribute('data-ix-box', j.box); }
          if (j.view || askedView) { range.setAttribute('data-ix-view', j.view || askedView); }
        }
        ixRememberList(url);
        if (window.history && history.pushState) {
          history.pushState({ ix: 'list' }, '', url.pathname + url.search);
        }
        if (window.jeabInkorgSwipeBind) { window.jeabInkorgSwipeBind(); }
      })
      .catch(function (err) {
        if (ixIsAbort(err) || signal.aborted) { return; }
      });
  }
  function ixCountsFor(url) {
    if (!url) { return; }
    var req = new URL(url.href);
    req.searchParams.set('p', 'inkorg');
    req.searchParams.set('ix_part', 'counts');
    req.searchParams.delete('id');
    req.searchParams.delete('ix_open');
    var asked = ixAddressBox(req);
    if (asked) {
      req.searchParams.set('box', asked);
      req.searchParams.set('co', asked);
    }
    fetch(req.pathname + '?' + req.searchParams.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || j.ok === false || !j.counts) { return; }
        var here = '';
        try { here = ixAddressBox(new URL(location.href)); } catch (e) {}
        if (asked && here && asked !== here) { return; }
        if (asked && j.box && asked !== j.box) { return; }
        if (window.jeabInkorgCountsHaveNumbers && !window.jeabInkorgCountsHaveNumbers(j.counts)) { return; }
        ixCounts(j.counts, typeof j.unread === 'number' ? j.unread : -1);
      })
      .catch(function () {});
  }
  function ixListUrlFromForm() {
    var form = document.querySelector('#ix-list form.ix-lhead');
    var url = new URL(location.href);
    url.searchParams.set('p', 'inkorg');
    url.searchParams.delete('id');
    url.searchParams.delete('ix_open');
    url.searchParams.delete('ix_part');
    url.searchParams.delete('sida');
    var ix = document.getElementById('ix');
    var box = ix ? (ix.getAttribute('data-ix-box') || '') : '';
    var view = ix ? (ix.getAttribute('data-ix-view') || '') : '';
    var scope = form ? (form.querySelector('input[name=scope]') || {}).value : '';
    var q = form ? (form.querySelector('input[name=q]') || {}).value : '';
    if (!box && form) { box = (form.querySelector('input[name=box]') || {}).value || ''; }
    if (!view && form) { view = (form.querySelector('input[name=view]') || {}).value || ''; }
    if (window.jeabInkorgListQuery) {
      return new URL(url.pathname + '?' + window.jeabInkorgListQuery({
        box: box || '',
        view: view || '',
        scope: scope || '',
        q: q || ''
      }), location.href);
    }
    if (box) { url.searchParams.set('box', box); }
    if (view) { url.searchParams.set('view', view); }
    url.searchParams.set('scope', scope === 'egna' ? 'egna' : 'allas');
    q = String(q || '').trim();
    if (q) { url.searchParams.set('q', q); } else { url.searchParams.delete('q'); }
    return url;
  }
  function ixListPartsFromUrl(url) {
    var ix = document.getElementById('ix');
    return {
      box: url.searchParams.get('box') || (ix ? (ix.getAttribute('data-ix-box') || '') : ''),
      view: url.searchParams.get('view') || (ix ? (ix.getAttribute('data-ix-view') || '') : ''),
      typ: url.searchParams.get('typ') || '',
      scope: url.searchParams.get('scope') || '',
      q: url.searchParams.get('q') || '',
      sida: url.searchParams.get('sida') || '',
      ko: url.searchParams.get('ko') || '',
      produkt: url.searchParams.get('produkt') || ''
    };
  }
  function ixDomParts() {
    var ix = document.getElementById('ix');
    var form = document.querySelector('#ix-list form.ix-lhead');
    var scopeEl = form ? form.querySelector('input[name=scope]') : null;
    var qEl = form ? form.querySelector('input[name=q]') : null;
    var sida = '';
    var ko = '';
    var produkt = '';
    var typ = '';
    try {
      var here = new URL(location.href);
      if (!here.searchParams.get('id')) { sida = here.searchParams.get('sida') || ''; }
      ko = here.searchParams.get('ko') || '';
      produkt = here.searchParams.get('produkt') || '';
      typ = here.searchParams.get('typ') || '';
    } catch (e) {}
    return {
      box: ix ? (ix.getAttribute('data-ix-box') || '') : '',
      view: ix ? (ix.getAttribute('data-ix-view') || '') : '',
      typ: typ,
      scope: scopeEl ? scopeEl.value : '',
      q: qEl ? qEl.value : '',
      sida: sida,
      ko: ko,
      produkt: produkt
    };
  }
  function ixRememberList(url) {
    var wrap = document.getElementById('ix-items-wrap');
    if (!wrap) { return; }
    var parts = url ? ixListPartsFromUrl(url) : ixDomParts();
    if (url) { parts.sida = url.searchParams.get('sida') || ''; }
    ixLists[window.jeabInkorgListKey(parts)] = {
      html: wrap.innerHTML,
      range: (document.getElementById('ix-range') || {}).textContent || '',
      title: (document.querySelector('#ix-list .crm-section-title') || {}).textContent || ''
    };
  }
  function ixSyncListChrome(url, title) {
    var ix = document.getElementById('ix');
    var view = url.searchParams.get('view') || '';
    var scope = url.searchParams.get('scope') || 'allas';
    var box = url.searchParams.get('box') || (ix ? (ix.getAttribute('data-ix-box') || '') : '');
    if (ix) {
      if (view) { ix.setAttribute('data-ix-view', view); }
      if (box) { ix.setAttribute('data-ix-box', box); }
      ix.classList.remove('is-open');
      ix.removeAttribute('data-ix-open');
      ix.removeAttribute('aria-busy');
    }
    var form = document.querySelector('#ix-list form.ix-lhead');
    if (form) {
      var v = form.querySelector('input[name=view]');
      if (v) { v.value = view; }
      var s = form.querySelector('input[name=scope]');
      if (s) { s.value = scope; }
      var q = form.querySelector('input[name=q]');
      if (q) { q.value = url.searchParams.get('q') || ''; }
    }
    if (title) {
      var heading = document.querySelector('#ix-list .crm-section-title');
      if (heading) { heading.textContent = title; }
    }
    ixMarkScope(scope);
    ixMarkQueue(url.searchParams.get('ko') || '');
    ixSyncScopeHrefs(url);
    var list = document.getElementById('ix-list');
    if (list) { list.setAttribute('data-ix-q', url.searchParams.get('q') || ''); }
    document.querySelectorAll('[data-ix-view]').forEach(function (el) {
      var on = el.getAttribute('data-ix-view') === view;
      el.classList.toggle('is-on', on);
      if (on) { el.setAttribute('aria-current', 'page'); } else { el.removeAttribute('aria-current'); }
    });
  }
  function ixAppliedQ() {
    var list = document.getElementById('ix-list');
    return list ? String(list.getAttribute('data-ix-q') || '') : '';
  }
  function ixPerformBack(url, fromPop) {
    var asked = String(url.searchParams.get('q') || '');
    if (asked !== ixAppliedQ()) { return false; }
    var urlKey = window.jeabInkorgListKey(ixListPartsFromUrl(url));
    var domKey = window.jeabInkorgListKey(ixDomParts());
    var plan = window.jeabInkorgBackPlan({
      hasId: (url.searchParams.get('id') || '') !== '',
      sameFilters: domKey === urlKey,
      hasCache: Object.prototype.hasOwnProperty.call(ixLists, urlKey),
      inbox: true
    });
    if (!plan.reveal) { return false; }
    window.__ixBg = null;
    if (window.jeabBackground) { window.jeabBackground.abort(); }
    window.jeabInkorgNavBack(ixNav, plan);
    ixFlight = ixNav.flight;
    ixAbort();
    ixClearPending();
    ixBgRelease();
    if (domKey !== urlKey) {
      var hit = ixLists[urlKey];
      if (hit) {
        var wrap = document.getElementById('ix-items-wrap');
        if (wrap) { wrap.innerHTML = hit.html; }
        var range = document.getElementById('ix-range');
        if (range) { range.textContent = hit.range || ''; }
        ixSyncListChrome(url, hit.title || '');
      }
    } else {
      ixSyncListChrome(url, '');
    }
    var conv = document.getElementById('ix-conv');
    if (conv) { conv.outerHTML = emptyConv; }
    document.querySelectorAll('#ix-items-wrap .ix-it').forEach(function (el) {
      el.classList.remove('is-on', 'is-loading');
      el.removeAttribute('aria-busy');
      var a = el.querySelector('a');
      if (a) { a.classList.remove('is-on'); a.removeAttribute('aria-current'); }
    });
    var box = document.getElementById('ix');
    if (box) { box.removeAttribute('aria-busy'); box.classList.remove('is-open'); }
    if (!fromPop && window.history && history.pushState) {
      history.pushState({ ix: 'list' }, '', url.pathname + url.search);
    }
    if (window.jeabInkorgSwipeBind) { window.jeabInkorgSwipeBind(); }
    return true;
  }
  function ixMeasureDeskHead() {
    if (!window.matchMedia || !window.matchMedia('(min-width: 1024px)').matches) { return; }
    var chrome = document.querySelector('header.chrome');
    if (!chrome || !chrome.getBoundingClientRect) { return; }
    var px = Math.round(chrome.getBoundingClientRect().height);
    if (px > 0 && document.body) {
      document.body.style.setProperty('--ix-desk-head', px + 'px');
    }
  }
  function ixHoldDeskScroll() {
    var plan = window.__ixDeskSnap;
    if (!plan || !plan.desktop) { return; }
    var list = document.getElementById('ix-list');
    if (list) { list.scrollTop = plan.listTop; }
    var y = window.pageYOffset || document.documentElement.scrollTop || 0;
    if (y !== plan.pageY && window.scrollTo) { window.scrollTo(0, plan.pageY); }
    if (!plan.convDone) {
      var conv = document.getElementById('ix-conv');
      if (conv && plan.convTop === 0) { conv.scrollTop = 0; }
      plan.convDone = true;
    }
    if (window.requestAnimationFrame) {
      requestAnimationFrame(function () {
        var again = window.__ixDeskSnap;
        if (!again || !again.desktop) { return; }
        var list2 = document.getElementById('ix-list');
        if (list2) { list2.scrollTop = again.listTop; }
        var y2 = window.pageYOffset || document.documentElement.scrollTop || 0;
        if (y2 !== again.pageY && window.scrollTo) { window.scrollTo(0, again.pageY); }
      });
    }
  }
  function ixPaintThread(j, url, fromPop) {
    var conv = document.getElementById('ix-conv');
    if (!conv || !j || typeof j.html !== 'string') { throw new Error('conv'); }
    var id = window.jeabInkorgPaintId(url.searchParams.get('id') || '', (j && j.id) || '');
    conv.outerHTML = j.html;
    ixHoldDeskScroll();
    var box = document.getElementById('ix');
    if (box) {
      box.classList.add('is-open');
      box.setAttribute('data-ix-open', id);
      box.removeAttribute('aria-busy');
    }
    ixMark(id);
    ixClearPending();
    jeabIxBind(document.getElementById('ix'));
    var readCard = ixCard(id);
    var cardUnread = !!(readCard && readCard.getAttribute('data-ix-unread') === '1');
    var readPlan = window.jeabInkorgOpenReadPlan
      ? window.jeabInkorgOpenReadPlan(j, cardUnread)
      : { send: !!j.read_on_open, refetch: false };
    if (readPlan.send) { ixOptimisticRead(id); }
    if (!fromPop && window.history && history.pushState) { history.pushState({ ix: 'thread' }, '', url.pathname + url.search); }
  }
  function ixShowPending(id) {
    ixMark(id);
    ixClearPending();
    var card = ixCard(id);
    if (card) {
      card.classList.add('is-loading');
      card.setAttribute('aria-busy', 'true');
    }
    var box = document.getElementById('ix');
    if (!box) { return; }
    box.classList.add('is-open');
    box.setAttribute('aria-busy', 'true');
    if (box.getAttribute('data-ix-open') !== id) {
      var conv = document.getElementById('ix-conv');
      if (conv) { conv.innerHTML = '<div class="ix-cmain"><p class="empty">Öppnar ärendet…</p></div>'; }
    }
    ixHoldDeskScroll();
  }
  function ixClearPending() {
    document.querySelectorAll('#ix-items-wrap .ix-it.is-loading').forEach(function (el) {
      el.classList.remove('is-loading');
      el.removeAttribute('aria-busy');
    });
  }
  function ixMark(id) {
    document.querySelectorAll('#ix-items-wrap .ix-it').forEach(function (el) {
      var a = el.querySelector('a');
      var on = false;
      if (a) {
        try { on = new URL(a.href, location.href).searchParams.get('id') === id; } catch (e) {}
      }
      el.classList.toggle('is-on', on);
      if (a) {
        a.classList.toggle('is-on', on);
        if (on) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); }
      }
    });
  }
  function ixThreadFail(url, fromPop, code) {
    var id = '';
    try { id = url.searchParams.get('id') || ''; } catch (e) {}
    var conv = document.getElementById('ix-conv');
    if (conv) {
      conv.innerHTML = window.jeabInkorgThreadFailHtml
        ? window.jeabInkorgThreadFailHtml(code)
        : '<div class="ix-cmain"><p class="empty">Ärendet kunde inte öppnas.</p><p class="ix-empty-hint"><button type="button" class="ix-btn" data-ix-retry>Försök igen</button></p></div>';
      var btn = conv.querySelector('[data-ix-retry]');
      if (btn) {
        btn.addEventListener('click', function () {
          ixShowPending(id);
          ixFetch(url, 'thread', !!fromPop);
        });
      }
    }
    var box = document.getElementById('ix');
    if (box) {
      box.classList.add('is-open');
      box.removeAttribute('aria-busy');
    }
    ixClearPending();
  }
  function ixFetch(url, kind, fromPop) {
    ixCancelFlight();
    var epoch = ixNav.epoch;
    function live() { return epoch === ixNav.epoch; }
    var show = url.pathname + url.search;
    function done() {
      if (!live()) { return; }
      var box = document.getElementById('ix');
      if (box) { box.removeAttribute('aria-busy'); }
      ixClearPending();
    }
    if (kind === 'thread') {
      var key = ixMemKey(url);
      if (ixMem[key] && typeof ixMem[key].html === 'string') {
        try { ixPaintThread(ixMem[key], url, fromPop); return; } catch (e) { delete ixMem[key]; }
      }
      var pendingSame = ixPending[key] || null;
      var busy = document.getElementById('ix');
      if (busy) { busy.setAttribute('aria-busy', 'true'); }
      if (!pendingSame) {
        ixAbort();
        ixCtrl = new AbortController();
      }
      var signal = ixCtrl ? ixCtrl.signal : null;
      var openedAt = Date.now();
      var busySeen = 0;
      function paint() {
        return ixLoadThread(url, true).then(function (j) {
          if (!live()) { return; }
          ixPaintThread(j, url, fromPop);
        });
      }
      function failCode(err) {
        return window.jeabInkorgThreadFailCode ? window.jeabInkorgThreadFailCode(err) : 'payload';
      }
      function onErr(err) {
        if (!live()) { return; }
        if (err && err.timeout) { ixThreadFail(url, fromPop, failCode(err)); return; }
        if (ixIsAbort(err) || (signal && signal.aborted)) {
          if (!pendingSame) { return; }
          delete ixPending[key];
          ixCtrl = new AbortController();
          signal = ixCtrl.signal;
          return ixLoadThread(url, true).then(function (j) {
            if (!live()) { return; }
            ixPaintThread(j, url, fromPop);
          }).catch(onErr);
        }
        if (err && err.busy && !(signal && signal.aborted)) {
          busySeen += 1;
          var elapsed = Date.now() - openedAt;
          var plan = window.jeabInkorgThreadBusyPlan
            ? window.jeabInkorgThreadBusyPlan(busySeen, elapsed)
            : { retry: busySeen < 3 && elapsed < 10000 };
          if (plan.retry) {
            return new Promise(function (resolve) { setTimeout(resolve, 200); }).then(function () {
              if (!live() || (signal && signal.aborted)) { return; }
              return paint().catch(onErr);
            });
          }
        }
        if ((ixIsAbort(err) || (signal && signal.aborted)) && !(err && err.timeout)) { return; }
        ixThreadFail(url, fromPop, failCode(err));
      }
      ixQueue.unshift(function () {
        if (!live()) { return; }
        return paint().catch(onErr).then(done);
      });
      ixPump();
      return;
    }
    var req = new URL(url.href);
    req.searchParams.set('ix_part', kind === 'list' ? 'all' : kind);
    req.searchParams.delete('ix_warm');
    var listBox = ixAddressBox(req);
    if (listBox) { req.searchParams.set('box', listBox); }
    ixAbort();
    ixCtrl = new AbortController();
    var listSignal = ixCtrl.signal;
    var ix = document.getElementById('ix');
    if (ix) { ix.setAttribute('aria-busy', 'true'); }
    ixQueue.push(function () {
      function pull(left) {
        return fetch(req.pathname + '?' + req.searchParams.toString(), { signal: listSignal, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-Jeab-Interactive': '1' }, credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) { throw new Error('status'); } return r.json(); })
          .then(function (j) {
            if (j && j.busy) {
              if (listSignal.aborted) { return null; }
              // Upptagen efter alla försök: ladda om sidan med adressen (t.ex. scope=allas).
              if (left <= 0) { var be = new Error('busy'); be.busy = true; throw be; }
              return new Promise(function (resolve) { setTimeout(resolve, 400); }).then(function () {
                return listSignal.aborted ? null : pull(left - 1);
              });
            }
            return j;
          });
      }
      return pull(4)
        .then(function (j) {
          if (!live()) { return; }
          if (!j) { return; }
          if (!j || j.ok === false || typeof j.html !== 'string') { throw new Error('payload'); }
          if (window.jeabInkorgResponseLive) {
            var locBox = '';
            try { locBox = ixAddressBox(new URL(location.href)); } catch (e) {}
            if (!window.jeabInkorgResponseLive(ixAddressBox(url), j.box || '', locBox)) { return; }
          }
          if (kind === 'app') {
            var cur = document.getElementById('ix');
            if (!cur) { throw new Error('ix'); }
            cur.outerHTML = j.html;
            document.querySelectorAll('.chrome-company a.chip').forEach(function (el) {
              var u;
              try { u = new URL(el.href, location.href); } catch (e) { return; }
              var on = (u.searchParams.get('box') || '') === (j.box || url.searchParams.get('box') || '');
              el.classList.toggle('is-on', on);
              if (on) { el.setAttribute('aria-current', 'true'); } else { el.removeAttribute('aria-current'); }
            });
            jeabIxBind(document.getElementById('ix'));
            if (j.counts) { ixCounts(j.counts, typeof j.unread === 'number' ? j.unread : -1); }
            else { ixCountsRepaint(); }
            ixAutoOpenTop();
          } else {
            ixApplyList(j, url);
          }
          if (!fromPop && window.history && history.pushState) { history.pushState({ ix: kind }, '', show); }
        })
        .catch(function (err) {
          if (!live()) { return; }
          if (ixIsAbort(err) || listSignal.aborted) { return; }
          // Adressen är redan pushad vid Egna/Alla-klick: ladda den, annars står listan kvar på förra urvalet.
          if (fromPop) { location.replace(show); } else { location.href = show; }
        })
        .then(done);
    });
    ixPump();
  }
  if (!window.jeabIxChrome) {
    window.jeabIxChrome = true;
    document.addEventListener('click', function (ev) {
      if (ev.target.closest && ev.target.closest('#ix-conv .ix-dd, #ix-conv .ix-split, #ix-toast')) { return; }
      closeMenus(null);
    });
    window.addEventListener('resize', ixMenuReplace);
    document.addEventListener('change', function (ev) {
      if (ev.target && ev.target.closest && ev.target.closest('#ix-conv .ix-menu')) { ixMenuReplace(); }
    });
    document.addEventListener('scroll', function (ev) {
      var t = ev.target;
      if (t && t.nodeType === 1 && t.closest && t.closest('.ix-menu')) { return; }
      ixMenuReplace();
    }, true);
    document.addEventListener('click', function (ev) {
      var b = ev.target.closest && ev.target.closest('[data-learn-undo]');
      if (!b || b.hidden) { return; }
      var f = (b.closest && b.closest('form')) || document.querySelector('#ix-conv form[data-autosave]');
      if (!f) { return; }
      var data = new FormData(f);
      data.set('action', 'inkorg.learn_undo');
      data.set('token', b.getAttribute('data-learn-undo') || '');
      b.disabled = true;
      var url = f.getAttribute('action') || '';
      fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'format=json', { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return [r.ok, j]; }); })
        .then(function (x) {
          b.disabled = false;
          if (!(x[0] && x[1].ok !== false)) { return; }
          var row = x[1].row || {};
          if (row.kind === 'type' && row.labels && row.labels.type) {
            var typeSel = document.querySelector('#ix-kund-body select[name=type]');
            if (typeSel) { typeSel.value = row.labels.type; }
            var typeRadio = document.querySelector('input[name=type][value="' + String(row.labels.type).replace(/"/g, '') + '"]');
            if (typeRadio) { typeRadio.checked = true; }
          }
          if (row.kind === 'tags' && Array.isArray(row.tags)) {
            var productSel = document.querySelector('#ix-kund-body select[name="tags[]"]');
            if (productSel) {
              var product = '';
              ['sol', 'batteri', 'elbilsladdare'].forEach(function (id) {
                if (row.tags.indexOf(id) !== -1) { product = id; }
              });
              productSel.value = product;
            }
          }
          var out = f.querySelector('[data-saved]');
          if (out) { out.textContent = 'Ångrat.'; }
          var toast = document.getElementById('ix-toast');
          if (toast) { toast.hidden = true; }
        })
        .catch(function () { b.disabled = false; });
    });
  }
  if (!window.jeabIxNav) {
    window.jeabIxNav = true;
    document.addEventListener('click', function (ev) {
      var more = ev.target && ev.target.closest ? ev.target.closest('a[data-ix-more]') : null;
      if (more && document.body && document.body.classList.contains('page-inkorg')) {
        if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) { return; }
        ev.preventDefault();
        if (ev.stopPropagation) { ev.stopPropagation(); }
        try { ixAppendPage(new URL(more.href, location.href)); } catch (e) {}
        return;
      }
      if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) { return; }
      var cardHit = ev.target && ev.target.closest ? ev.target.closest('#ix-items-wrap .ix-it') : null;
      if (ev.defaultPrevented && !cardHit) { return; }
      if (cardHit && ev.target.closest && ev.target.closest('button, [data-ix-act], input, textarea, select')) { return; }
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
      if (cardHit && (!a || !cardHit.contains(a))) { a = cardHit.querySelector('a.item-main'); }
      if (!a || (a.target && a.target !== '_self')) { return; }
      if (a.hasAttribute && a.hasAttribute('data-ix-scope')) { return; }
      if (!document.body || !document.body.classList.contains('page-inkorg')) { return; }
      var url;
      try { url = new URL(a.href, location.href); } catch (e) { return; }
      if (url.origin !== location.origin || url.searchParams.get('p') !== 'inkorg') { return; }
      if (a.closest && a.closest('.chrome-company')) {
        ev.preventDefault();
        location.href = window.jeabInkorgCompanyHref ? window.jeabInkorgCompanyHref(a.href) : a.href;
        return;
      }
      var cur;
      try { cur = new URL(location.href); } catch (e) { return; }
      var scopeAttr = a.getAttribute('data-ix-scope') || '';
      if (scopeAttr && !url.searchParams.get('scope')) { url.searchParams.set('scope', scopeAttr); }
      var ix = document.getElementById('ix');
      var domView = ix ? (ix.getAttribute('data-ix-view') || '') : '';
      var curBox = ix ? (ix.getAttribute('data-ix-box') || '') : (cur.searchParams.get('box') || '');
      var nextId = url.searchParams.get('id') || '';
      var curId = cur.searchParams.get('id') || '';
      var nextBox = url.searchParams.get('box') || curBox;
      var boxChanged = nextBox !== curBox;
      var nextView = url.searchParams.get('view') || '';
      var shownView = domView || cur.searchParams.get('view') || '';
      var plan = window.jeabInkorgClickPlan ? window.jeabInkorgClickPlan({
        sameUrl: url.pathname === cur.pathname && url.search === cur.search,
        shownView: shownView,
        nextView: nextView,
        boxChanged: boxChanged,
        company: false,
        nextId: nextId,
        curId: curId,
        scopeChanged: (url.searchParams.get('scope') || 'allas') !== (cur.searchParams.get('scope') || 'allas') || (!!scopeAttr && scopeAttr !== (cur.searchParams.get('scope') || 'allas')),
        qChanged: (url.searchParams.get('q') || '') !== (cur.searchParams.get('q') || ''),
        koChanged: (url.searchParams.get('ko') || '') !== (cur.searchParams.get('ko') || ''),
        produktChanged: (url.searchParams.get('produkt') || '') !== (cur.searchParams.get('produkt') || ''),
        pageChanged: (url.searchParams.get('sida') || '') !== (cur.searchParams.get('sida') || '')
      }) : null;
      if (!plan || plan.ignore) { return; }
      var kind = plan.kind;
      if (plan.reveal && nextId === '' && ixPerformBack(url, false)) {
        ev.preventDefault();
        return;
      }
      if (kind === 'thread') {
        if (window.__ixOpenId === nextId && window.__ixOpenAt && Date.now() - window.__ixOpenAt < 400) {
          ev.preventDefault();
          return;
        }
        window.__ixOpenId = nextId;
        window.__ixOpenAt = Date.now();
        ev.preventDefault();
        var listEl = document.getElementById('ix-list');
        var deskWide = window.matchMedia && window.matchMedia('(min-width: 1024px)').matches;
        window.__ixDeskSnap = window.jeabInkorgDeskScrollPlan
          ? window.jeabInkorgDeskScrollPlan(deskWide ? 1024 : 0, listEl ? listEl.scrollTop : 0, window.pageYOffset || document.documentElement.scrollTop || 0)
          : null;
        ixRememberList(null);
        ixShowPending(nextId);
      } else {
        window.__ixDeskSnap = null;
      }
      ev.preventDefault();
      window.__ixBg = null;
      if (window.jeabBackground) { window.jeabBackground.abort(); }
      if (plan.pushFirst && window.history && history.pushState) {
        history.pushState({ ix: kind }, '', url.pathname + url.search);
      }
      ixFetch(url, kind, !!plan.pushFirst);
    });
    document.addEventListener('pointerover', function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('#ix-items-wrap a.item-main') : null;
      if (!a) { return; }
      try { ixPrefetch(new URL(a.href, location.href)); } catch (e) {}
    }, true);
    document.addEventListener('touchstart', function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('#ix-items-wrap a.item-main') : null;
      if (!a) { return; }
      try { ixPrefetch(new URL(a.href, location.href)); } catch (e) {}
    }, { capture: true, passive: true });
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (!f || !f.querySelector || !document.body.classList.contains('page-inkorg')) { return; }
      var actionEl = f.querySelector('input[name=action]');
      var statusEl = f.querySelector('input[name=status]');
      if (!actionEl || actionEl.value !== 'inkorg.thread' || !statusEl || statusEl.value !== 'closed') { return; }
      ev.preventDefault();
      var idEl = f.querySelector('input[name=thread]');
      if (window.jeabIxCloseRow && idEl) { window.jeabIxCloseRow(idEl.value); }
    });
    window.addEventListener('popstate', function (ev) {
      try {
        var url = new URL(location.href);
        /* Skalet ritar Tillbaka till en annan flik från sin cache. Ingen omladdning. */
        if (url.searchParams.get('p') !== 'inkorg') { return; }
        if (ev.stopImmediatePropagation) { ev.stopImmediatePropagation(); }
        if (!url.searchParams.get('id') && ixPerformBack(url, true)) { return; }
        ixFetch(url, url.searchParams.get('id') ? 'thread' : 'list', true);
      } catch (e) {}
    });
    window.addEventListener('pageshow', function (ev) {
      if (!ev.persisted) { return; }
      try {
        var url = new URL(location.href);
        if (url.searchParams.get('p') !== 'inkorg') { return; }
        ixCancelFlight();
        if (url.searchParams.get('id')) { return; }
        var box = document.getElementById('ix');
        if (box) {
          box.classList.remove('is-open');
          box.removeAttribute('data-ix-open');
          box.removeAttribute('aria-busy');
        }
        ixClearPending();
        if (!ixPerformBack(url, true)) {
          var conv = document.getElementById('ix-conv');
          if (conv && box && !box.classList.contains('is-open')) { conv.outerHTML = emptyConv; }
        }
      } catch (e) {}
    });
  }
  jeabIxBind(document.getElementById('ix'));
  var ixSearchForm = document.querySelector('#ix-list form.ix-lhead');
  if (ixSearchForm && !ixSearchForm._ixSearch) {
    ixSearchForm._ixSearch = true;
    var ixSearchTimer = 0;
    var ixRunSearch = function () {
      var url = ixListUrlFromForm();
      var here;
      try { here = new URL(location.href); } catch (e) { return; }
      var same = (here.searchParams.get('q') || '') === (url.searchParams.get('q') || '')
        && (here.searchParams.get('view') || '') === (url.searchParams.get('view') || '')
        && (here.searchParams.get('scope') || 'allas') === (url.searchParams.get('scope') || 'allas')
        && (here.searchParams.get('id') || '') === '';
      if (same) { return; }
      if (window.history && history.pushState) {
        history.pushState({ ix: 'list', q: url.searchParams.get('q') || '' }, '', url.pathname + url.search);
      }
      ixFetch(url, 'list', true);
    };
    ixSearchForm.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      ev.preventDefault();
      window.clearTimeout(ixSearchTimer);
      ixRunSearch();
    });
    var ixSearchInput = ixSearchForm.querySelector('input[name=q]');
    if (ixSearchInput) {
      ixSearchInput.addEventListener('input', function () {
        window.clearTimeout(ixSearchTimer);
        ixSearchTimer = window.setTimeout(ixRunSearch, 300);
      });
    }
  }
  if (window.jeabInkorgBindScopeClicks) {
    window.jeabInkorgBindScopeClicks(function (hit) {
      var url = hit.url instanceof URL ? hit.url : new URL(hit.href, location.href);
      ixMarkScope(hit.scope);
      var form = document.querySelector('#ix-list form.ix-lhead');
      if (form) {
        var s = form.querySelector('input[name=scope]');
        if (s) { s.value = hit.scope; }
      }
      if (window.history && history.pushState) {
        history.pushState({ ix: 'list', scope: hit.scope }, '', url.pathname + '?' + url.searchParams.toString());
      }
      window.__ixBg = null;
      if (window.jeabBackground) { window.jeabBackground.abort(); }
      ixFetch(url, 'list', true);
    });
  }
  if (window.jeabInkorgBindSearch) {
    window.jeabInkorgBindSearch(function (hit) {
      var url = hit.url instanceof URL ? new URL(hit.url.href) : new URL(hit.href, location.href);
      var ix = document.getElementById('ix');
      var form = document.querySelector('#ix-list form.ix-lhead');
      var q = String(hit.q == null ? (url.searchParams.get('q') || '') : hit.q).trim();
      var view = String(hit.view || (ix && ix.getAttribute('data-ix-view')) || url.searchParams.get('view') || '');
      var box = (ix && ix.getAttribute('data-ix-box')) || url.searchParams.get('box') || '';
      var scope = url.searchParams.get('scope') || (form && (form.querySelector('input[name=scope]') || {}).value) || 'allas';
      url.searchParams.set('p', 'inkorg');
      if (box) { url.searchParams.set('box', box); }
      if (view) { url.searchParams.set('view', view); }
      url.searchParams.set('scope', scope === 'egna' ? 'egna' : 'allas');
      if (q) { url.searchParams.set('q', q); } else { url.searchParams.delete('q'); }
      url.searchParams.delete('id');
      url.searchParams.delete('ix_open');
      url.searchParams.delete('ix_part');
      url.searchParams.delete('sida');
      window.clearTimeout(ixSearchTimer);
      if (form) {
        var qEl = form.querySelector('input[name=q]');
        if (qEl) { qEl.value = q; }
        var vEl = form.querySelector('input[name=view]');
        if (vEl && view) { vEl.value = view; }
      }
      var show = url.pathname + '?' + url.searchParams.toString();
      if (window.history && history.pushState) {
        history.pushState({ ix: 'list', q: q }, '', show);
      }
      ixFetch(url, 'list', true);
    });
  }
  function ixShownThreadId() {
    var ix = document.getElementById('ix');
    return ix ? (ix.getAttribute('data-ix-open') || '') : '';
  }
  function ixEnsureAddressThread() {
    var addressed = window.jeabInkorgThreadRequest ? window.jeabInkorgThreadRequest(location.href) : null;
    if (!addressed || !addressed.id || ixShownThreadId() === addressed.id) { return; }
    var here;
    try { here = new URL(location.href); } catch (e) { return; }
    var id = addressed.id;
    ixLoadThread(here, true).then(function (j) {
      var now = window.jeabInkorgThreadRequest ? window.jeabInkorgThreadRequest(location.href) : null;
      if (!now || now.id !== id) { return; }
      ixPaintThread(j, here, true);
    }).catch(function () {});
  }
  ixMeasureDeskHead();
  window.addEventListener('resize', ixMeasureDeskHead);
  ixRememberList(null);
  ixEnsureAddressThread();
  ixAutoOpenTop();
  (function () {
    var ix = document.getElementById('ix');
    var box = ix ? (ix.getAttribute('data-ix-box') || '') : '';
    if (!box) { return; }
    var counts = {};
    document.querySelectorAll('#ix [data-ix-count]').forEach(function (el) {
      var id = el.getAttribute('data-ix-count') || '';
      var text = window.jeabInkorgCountText ? window.jeabInkorgCountText(el.textContent || '') : null;
      if (id && text !== null) { counts[id] = parseInt(text, 10); }
    });
    ixSaveBoxCounts(box, ixCountScope(null), counts, -1);
  })();
  var ixBoot = document.getElementById('ix');
  if (ixBoot && ixBoot.getAttribute('data-ix-read-on-open') === '1') {
    ixOptimisticRead(ixBoot.getAttribute('data-ix-open') || '');
  }
  ixWarm();
})();
</script>

</div>
<?php
$html = (string) ob_get_clean();
if ($part === 'app') {
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('db', $ixDb);
        jeab_timing_span('render', max(0, microtime(true) - $ixWall - $ixDb));
    }
    $appCounts = is_array($jeabAppCounts) ? $jeabAppCounts : [];
    jeab_json([
        'ok' => true,
        'html' => $html,
        'view' => $vyId,
        'box' => $box,
        'counts' => is_array($appCounts['counts'] ?? null) ? $appCounts['counts'] : $counts,
        'unread' => (int) ($appCounts['unread'] ?? ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0)),
        'counts_fresh' => (int) ($appCounts['fresh'] ?? 0),
    ]);
}
if (function_exists('jeab_timing_span')) {
    jeab_timing_span('db', $ixDb);
    jeab_timing_span('render', max(0, microtime(true) - $ixWall - $ixDb));
}
return $html;
