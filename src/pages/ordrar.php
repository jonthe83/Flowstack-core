<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var array $rows */
/** @var string $company */
/** @var array|null $open */
/** @var string|null $flash */
/** @var string|null $error */

$open = is_array($open ?? null) ? $open : null;
if (is_array($open) && function_exists('jeab_contacts_sole_paint_order')) {
    $open = jeab_contacts_sole_paint_order($open);
}
$company = jeab_normalize_company($company ?? jeab_selected_company()) ?? 'jarfalla';
$requestedId = (int) ($_GET['id'] ?? $_GET['open'] ?? 0);
if ($requestedId > 0 && !$open && function_exists('jeab_purchase_get')) {
    $loaded = function_exists('jeab_ordrar_visible_get') ? jeab_ordrar_visible_get($requestedId) : jeab_purchase_get($requestedId);
    if (is_array($loaded)) {
        $open = function_exists('jeab_contacts_sole_paint_order') ? jeab_contacts_sole_paint_order($loaded) : $loaded;
    }
}
$labels = jeab_ordrar_step_labels();
$listLabels = jeab_ordrar_list_labels();
$rows = [];
$klartRows = [];
$otherRows = [];
$slutfasRows = [];
$boardChip = '';
$boardQuery = '';
$ordrarTotal = 0;
$slutfasTotal = 0;
$klartTotal = 0;
$ordrarNext = null;
$slutfasNext = null;
$klartNext = null;
$ordrarPageSize = function_exists('jeab_ordrar_page_size') ? jeab_ordrar_page_size() : 40;
// Ordrar: inloggad person som förval även utan produktregel (Alla när ingen koppling finns).
$staffFilter = jeab_filter_from_request($company, true);
$whoFilter = $staffFilter['who'];
$jobFilter = $staffFilter['job'];
if ($requestedId > 0 && !$open) {
    $back = jeab_ordrar_url(['co' => $company] + jeab_ordrar_context_params($company));
    return '<section class="ordrar is-open" data-v3-root data-id="' . $requestedId . '">'
        . '<a class="v3-back" href="' . e($back) . '">← Tillbaka</a>'
        . '<h1>Ordern hittades inte</h1></section>';
}
if (!$open) {
    // Brådskande först: närmaste installation, utan datum sist.
    // Statusräknarna tas på hela urvalet (Mina/Alla + produkt) innan Slutfas
    // bryts ut, så chippen Alla/Behöver svar/… räknar båda sektionerna.
    // Öppna ordrar läses som list_json. Allt klart skärs i SQL när inget
    // person-, produkt-, chip- eller sökfilter ligger på.
    $boardChip = jeab_ordrar_board_chip((string) ($_GET['chip'] ?? ''));
    $boardQuery = trim((string) ($_GET['q'] ?? ''));
    $ordrarPageSize = jeab_ordrar_page_size();
    $jeabOrdrarPhp = microtime(true);
    $board = jeab_ordrar_board_collect(
        $company,
        $whoFilter,
        $jobFilter,
        $boardChip,
        $boardQuery,
        (int) ($_GET['n'] ?? 0),
        (int) ($_GET['ns'] ?? 0),
        (int) ($_GET['nk'] ?? 0),
        (isset($store) && $store instanceof JeabStore) ? $store : null
    );
    $rows = $board['rows'];
    $slutfasRows = $board['slutfasRows'];
    $klartRows = $board['klartRows'];
    $otherRows = $board['otherRows'];
    $boardFilters = $board['boardFilters'];
    $ordrarTotal = $board['ordrarTotal'];
    $slutfasTotal = $board['slutfasTotal'];
    $klartTotal = $board['klartTotal'];
    $ordrarNext = $board['ordrarNext'];
    $slutfasNext = $board['slutfasNext'];
    $klartNext = $board['klartNext'];
    if (function_exists('jeab_ordrar_list_warm_contacts')) {
        jeab_ordrar_list_warm_contacts(array_merge($rows, $klartRows, $slutfasRows, $otherRows));
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('ordrar_php', microtime(true) - $jeabOrdrarPhp);
    }
    if ((string) ($_GET['intent'] ?? '') === 'rows') {
        $chunk = $rows;
        $next = $ordrarNext;
        if ((int) ($_GET['ns'] ?? 0) > 0) {
            $chunk = $slutfasRows;
            $next = $slutfasNext;
        } elseif ((int) ($_GET['nk'] ?? 0) > 0) {
            $chunk = $klartRows;
            $next = $klartNext;
        }
        $listHtml = '';
        foreach ($chunk as $row) {
            if (!is_array($row)) {
                continue;
            }
            $listHtml .= jeab_ordrar_board_row_html($row, $company, $store ?? null, $staffFilter);
        }
        jeab_json(['ok' => true, 'html' => $listHtml, 'next' => $next]);
    }
}
$products = ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solpaneler'];
$saknasFx = is_string($error ?? null) && str_contains((string) $error, 'Saknas för Fortnox-order');

$jeabOrdrarHtml = microtime(true);
ob_start();
if (!$open) {
    echo jeab_error_html($error ?? null);
}
?>
<section class="ordrar<?= $open ? ' is-open' : '' ?>">
    <header class="ordrar-head-bar<?= $open ? '' : ' ordrar-head-dash' ?>">
        <?php if ($open): ?>
            <h1><?= e(jeab_ordrar_name($open) !== '' ? jeab_ordrar_name($open) : 'Order') ?></h1>
            <?php
            $headId = (int) ($open['reminder_id'] ?? 0);
            if ($headId <= 0) {
                $headId = (int) ($_GET['id'] ?? $_GET['open'] ?? 0);
            }
            $headStop = jeab_ordrar_view_stop($open, is_string($error ?? null) ? $error : null);
            ?>
            <div class="ordrar-head-nav">
            <a class="ordrar-back" href="<?= e(jeab_ordrar_url(['co' => $company] + jeab_ordrar_context_params($company))) ?>">
                <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M16 10H4.5M8.2 5.8 4 10l4.2 4.2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Tillbaka</span>
            </a>
            <a class="ordrar-refresh" href="<?= e(jeab_ordrar_url(['co' => $company, 'id' => $headId, 'stop' => $headStop, 'refresh' => '1'])) ?>" aria-label="Uppdatera" title="Uppdatera">
                <svg viewBox="0 0 24 24" width="16" height="16" style="width:16px;height:16px" aria-hidden="true" focusable="false"><path d="M20 12a8 8 0 1 1-2.2-5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M20 4.2V9h-4.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            </div>
            <?php if ($headId > 0): ?>
                <?= jeab_ordrar_staff_owner_html($open, $company, $headId) ?>
            <?php endif; ?>
            <?= jeab_flash_html($flash ?? null) ?>
        <?php else: ?>
            <?php
            $coLabel = $company === 'malmo' ? 'Malmö' : 'Järfälla';
            $boardFilters = (isset($boardFilters) && is_array($boardFilters))
                ? $boardFilters
                : jeab_ordrar_board_filters($rowsAll ?? $rows, $store ?? null);
            $nOpen = (int) $boardFilters['all'];
            $nWait = (int) $boardFilters['wait'];
            ?>
            <div class="ordrar-dash-titles">
                <p class="ordrar-eyebrow"><?= e($coLabel) ?></p>
                <h1 class="crm-title">Ordrar</h1>
                <p class="ordrar-meta"><?= $nOpen ?> ordrar<?php if ($nWait > 0): ?> · <?= $nWait ?> väntar på följdfrågor<?php endif; ?></p>
            </div>
            <?= jeab_flash_html($flash ?? null) ?>
            <div class="ordrar-dash-tools">
                <label class="ordrar-search">
                    <span class="visually-hidden">Sök kundnamn</span>
                    <input type="search" placeholder="Sök kundnamn" autocomplete="off" data-js="ordrar-search" value="<?= e($boardQuery) ?>">
                </label>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($open): ?>
        <?php
        // P-8: öppning skriver inte shopnummer, aktivitet eller Ellevio-id.
        // De fylls i av tick eller direkt efter en sparande POST. Ett värde som
        // tidigare syntes när ordern öppnades syns vid nästa tick, eller efter att ordern sparats.
        $openId = (int) ($open['reminder_id'] ?? 0);
        if ($openId <= 0) {
            $openId = (int) ($_GET['id'] ?? $_GET['open'] ?? 0);
        }
        $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
        $view = jeab_ordrar_view_stop($open, $error ?? null);
        $identityMissing = jeab_ordrar_identity_missing($open);
        if (trim((string) ($open['fortnox_order'] ?? '')) === '' && $identityMissing !== []) {
            $wantStop = jeab_ordrar_requested_stop();
            if ($wantStop === '' || !jeab_ordrar_stop_clickable($open, $wantStop)) {
                $view = 'bekrafta';
            }
        }
        $reminder = is_array($reminder ?? null) ? $reminder : jeab_ordrar_reminder_for_row($open, $openId);
        $renderView = $view;
        $visiblePane = $renderView;
        if ($renderView === 'fakturera' || $renderView === 'fardiganmal') {
            $visiblePane = 'fakturering';
        } elseif ($renderView === 'vad') {
            $visiblePane = 'bekrafta';
        } elseif ($renderView === 'boka_bestall') {
            $visiblePane = jeab_ordrar_is_booked($open) ? 'orderrader' : 'boka';
        } elseif ($renderView === 'bestall') {
            $visiblePane = 'orderrader';
        }
        $paneStops = [];
        $paneSeen = [];
        $paneCandidates = ['status', 'eon'];
        foreach (jeab_ordrar_flow($open) as $paneStep) {
            $paneCandidates[] = (string) ($paneStep['key'] ?? '');
        }
        $paneCandidates[] = $visiblePane;
        foreach ($paneCandidates as $paneBody) {
            if ($paneBody === '' || $paneBody === 'vad') {
                $paneBody = $paneBody === 'vad' ? 'bekrafta' : $paneBody;
            }
            if ($paneBody === 'fakturera' || $paneBody === 'fardiganmal') {
                $paneBody = 'fakturering';
            }
            if ($paneBody === 'boka_bestall') {
                $paneBody = jeab_ordrar_is_booked($open) ? 'bestall' : 'boka';
            }
            if ($paneBody === 'bestall') {
                continue;
            }
            if ($paneBody === '' || isset($paneSeen[$paneBody])) {
                continue;
            }
            $paneSeen[$paneBody] = true;
            $paneStops[] = $paneBody;
        }
        if (!in_array($visiblePane, $paneStops, true)) {
            $paneStops[] = $visiblePane;
        }
        // Utan stop landar ordern på Bekräfta; Status ligger ovanför.
        $landPane = jeab_ordrar_landing_pane(jeab_ordrar_requested_stop(), $visiblePane, $paneStops);
        $landDefault = in_array('bekrafta', $paneStops, true) ? 'bekrafta' : 'status';
        $suggest = null;
        $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
        $huvud = trim((string) ($open['huvudsokande'] ?? ''));
        $huvudPlaceholder = '';
        $inkop = jeab_ordrar_inkop_pad(is_array($open['inkop_lines'] ?? null) ? $open['inkop_lines'] : []);
        $action = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
        $orderNo = trim((string) ($open['fortnox_order'] ?? ''));
        $stop3 = !empty($open['stop3_saved']);
        $missingFx = jeab_ordrar_fortnox_missing($open);
        $fxPrice = jeab_ordrar_fortnox_price($open);
        $kindLabel = $products[$kind] ?? '';
        ?>
        <div class="ordrar-detail-grid">
        <?= jeab_ordrar_stepper_html($open, $company, $openId, $landPane !== '' ? $landPane : $view) ?>
        <div class="ordrar-detail-main">
        <?= jeab_ordrar_save_fail_html(is_string($error ?? null) ? $error : null) ?>

        <div class="ordrar-panes" data-js="ordrar-panes" data-ordrar-long="1" data-land="<?= e($landPane) ?>" data-land-default="<?= e($landDefault) ?>">
        <?php foreach ($paneStops as $paneStop): ?>
            <?php
            $view = (string) $paneStop;
            $suggest = null;
            $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
            $huvud = trim((string) ($open['huvudsokande'] ?? ''));
            $huvudPlaceholder = '';
            $paintBekrafta = $view === 'bekrafta';
            if ($paintBekrafta) {
                $prepared = function_exists('jeab_ordrar_bekrafta_prepare')
                    ? jeab_ordrar_bekrafta_prepare($open, is_array($reminder) ? $reminder : null)
                    : ['row' => $open, 'suggest' => jeab_ordrar_confirm_suggest($open, is_array($reminder) ? $reminder : null)];
                $open = is_array($prepared['row'] ?? null) ? $prepared['row'] : $open;
                $suggest = is_array($prepared['suggest'] ?? null) ? $prepared['suggest'] : [];
                $address = (string) ($suggest['address'] ?? $address);
                $huvud = (string) ($suggest['huvudsokande'] ?? '');
                $huvudPlaceholder = (string) ($suggest['huvudsokande_placeholder'] ?? '');
            }
            ?>
        <div class="ordrar-step-pane" data-stop="<?= e($view) ?>"<?= ($view === 'status' || $view === $landPane) ? '' : ' hidden' ?><?= ($landPane !== '' && $view === $landPane) ? ' data-land="1"' : '' ?>>
        <?php if ($view === 'status'): ?>
            <?= jeab_ordrar_status_html($open, $company, $openId, $store ?? null) ?>
        <?php elseif ($view === 'bekrafta'): ?>
            <?php
            $copy = jeab_ordrar_copy_btn();
            $huvudPnr = (string) ($suggest['personnummer'] ?? '');
            $huvudAddr = trim((string) ($suggest['huvudsokande_address'] ?? $address));
            $fastighet = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
            $fastAddr = $huvudAddr !== '' ? $huvudAddr : $address;
            $ownerCards = jeab_ordrar_owner_cards($suggest);
            $editIdentity = $huvud === '' || $huvudPnr === '' || $fastighet === '';
            ?>
            <div class="ordrar-confirm" data-kind="<?= e($kind) ?>">
            <form id="ordrar-q" method="post" action="<?= e(jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'bekrafta'])) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.spara">
                <input type="hidden" name="id" value="<?= $openId ?>">
                <input type="hidden" name="huvudsokande" value="<?= e($huvud) ?>">
                <input type="hidden" name="huvudsokande_personnummer" value="<?= e($huvudPnr) ?>">
                <input type="hidden" name="huvudsokande_address" value="<?= e($huvudAddr) ?>">
                <input type="hidden" name="personnummer_forslag" value="<?= e($huvudPnr) ?>">
                <input type="hidden" name="fastighetsbeteckning_forslag" value="<?= e($fastighet) ?>">
                <?php foreach (jeab_ordrar_owner_forslag($open, is_array($suggest) ? $suggest : null, $ownerCards) as $ownerSig): ?>
                    <input type="hidden" name="owner_forslag[]" value="<?= e($ownerSig) ?>">
                <?php endforeach; ?>
                <?php foreach ($ownerCards as $owner): ?>
                    <input type="hidden" name="owner_name[]" value="<?= e((string) ($owner['name'] ?? '')) ?>">
                    <input type="hidden" name="owner_pnr[]" value="<?= e((string) ($owner['personnummer'] ?? '')) ?>">
                    <input type="hidden" name="owner_address[]" value="<?= e((string) ($owner['address'] ?? '')) ?>">
                    <?php if (!empty($owner['huvud'])) { continue; } ?>
                    <input type="hidden" name="medsokande_name[]" value="<?= e((string) ($owner['name'] ?? '')) ?>">
                    <input type="hidden" name="medsokande_personnummer[]" value="<?= e((string) ($owner['personnummer'] ?? '')) ?>">
                    <input type="hidden" name="medsokande_address[]" value="<?= e((string) ($owner['address'] ?? '')) ?>">
                <?php endforeach; ?>
                <h2>Bekräfta</h2>
                <?php if (!$saknasFx && $identityMissing): ?>
                    <p class="ordrar-help" data-js="ordrar-missing-preview">Saknas för Fortnox-order: <?= e(implode(', ', $identityMissing)) ?>.</p>
                <?php endif; ?>
                <article class="ordrar-fastighet">
                    <h3>Fastighet</h3>
                    <div class="ordrar-fast-row">
                        <?php if ($editIdentity): ?>
                            <input type="text" class="ordrar-fast-beteckning" name="fastighetsbeteckning" value="<?= e($fastighet) ?>" placeholder="Fastighetsbeteckning" autocomplete="off">
                        <?php else: ?>
                            <strong class="ordrar-fast-beteckning"><?= e($fastighet) ?></strong>
                            <input type="hidden" name="fastighetsbeteckning" value="<?= e($fastighet) ?>">
                        <?php endif; ?>
                        <?= $copy ?>
                    </div>
                    <?php if ($fastAddr !== ''): ?>
                        <p class="ordrar-fast-addr"><?= e($fastAddr) ?></p>
                    <?php endif; ?>
                    <?php if (function_exists('jeab_fastighet_refresh_button')): ?>
                        <div class="ordrar-fast-refresh"><?= jeab_fastighet_refresh_button($openId) ?></div>
                    <?php endif; ?>
                </article>
                <?php foreach ($ownerCards as $oi => $owner): ?>
                    <?php
                    $isHuvud = !empty($owner['huvud']);
                    $ownerName = (string) ($owner['name'] ?? '');
                    $ownerPnr = (string) ($owner['personnummer'] ?? '');
                    $ownerAddr = (string) ($owner['address'] ?? $huvudAddr);
                    $ownerEdit = $editIdentity && $isHuvud;
                    ?>
                    <article class="ordrar-owner" data-name="<?= e($ownerName) ?>" data-pnr="<?= e($ownerPnr) ?>" data-addr="<?= e($ownerAddr) ?>">
                        <header class="ordrar-owner-head">
                            <strong>Ägare <?= (int) $oi + 1 ?></strong>
                            <?= jeab_ordrar_owner_pills_html($isHuvud) ?>
                        </header>
                        <div class="ordrar-owner-row">
                            <span class="ordrar-owner-lbl">Namn:</span>
                            <?= jeab_ordrar_owner_value_html($ownerName, $ownerEdit, 'name', $isHuvud ? $huvudPlaceholder : '') ?>
                            <?= $copy ?>
                        </div>
                        <div class="ordrar-owner-row">
                            <span class="ordrar-owner-lbl">Personnummer:</span>
                            <?= jeab_ordrar_owner_value_html($ownerPnr, $ownerEdit, 'pnr', '', true) ?>
                            <?= $copy ?>
                        </div>
                        <div class="ordrar-owner-row">
                            <span class="ordrar-owner-lbl">Adress:</span>
                            <?= jeab_ordrar_owner_value_html($ownerAddr, $ownerEdit, 'address', $address) ?>
                            <?= $copy ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <div class="chips ordrar-kind" role="radiogroup" aria-label="Produkt" aria-required="true">
                    <?php foreach ($products as $key => $label): ?>
                        <label class="chip-opt">
                            <input type="radio" name="kind" value="<?= e($key) ?>" required<?= $kind === $key ? ' checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="ordrar-kind-req" data-js="ordrar-kind-req" hidden>Detta är obligatoriskt.</p>
                <?= jeab_ordrar_kalkyl_html($open) ?>
                <div class="ordrar-confirm-vad">
                <label class="ordrar-field">
                    <span>Vad som ska installeras</span>
                    <input type="text" name="install_what" value="<?= e((string) ($open['install_what'] ?? '')) ?>" autocomplete="off">
                    <p class="ordrar-field-help">Detta blir specifikationen på fakturan</p>
                </label>
                <label class="ordrar-field">
                    <span>Pris</span>
                    <?php
                    $confirmPrice = (int) ($open['pris'] ?? 0);
                    if ($confirmPrice <= 0 && ($kind === 'batteri' || $kind === 'sol')) {
                        $confirmPrice = (int) ($open['kundpris'] ?? 0);
                    }
                    ?>
                    <input type="text" name="pris" inputmode="numeric" value="<?= $confirmPrice > 0 ? e(jeab_ordrar_kr($confirmPrice)) : '' ?>" autocomplete="off">
                    <p class="ordrar-field-help">Pris efter grön teknik – det kunden betalar.</p>
                </label>
                <?= jeab_ordrar_payouts_html($open) ?>
                <label class="ordrar-field">
                    <span>Anteckning till installation</span>
                    <textarea name="install_note" rows="3"><?= e((string) ($open['install_note'] ?? '')) ?></textarea>
                    <p class="ordrar-field-help">Syns inte för kunden. kommer med i bokningen i kalendern för installatören</p>
                </label>
                </div>
            </form>
            <div class="ordrar-result" data-js="ordrar-result" aria-live="polite"><?= jeab_ordrar_result_body($open) ?></div>
            <div class="ordrar-footer">
                <?php $confirmSlutfor = $kind === 'laddbox' || $kind === 'batteri' || $kind === 'sol'; ?>
                <?php $booksAtBestall = $kind === 'batteri' || $kind === 'sol'; ?>
                <?php if ($confirmSlutfor && !$booksAtBestall && isset($store) && $store instanceof JeabStore && !jeab_ordrar_is_booked($open, $store)): ?>
                    <?= jeab_ordrar_proposed_slot_html($store, $open) ?>
                <?php endif; ?>
                <div class="ordrar-save-row">
                <button class="ordrar-secondary" form="ordrar-q" type="submit" name="action" value="ordrar.spara">Spara</button>
                <p class="ordrar-save-status" data-js="ordrar-save-status" role="status"></p>
                </div>
                <button class="ordrar-primary" id="ordrar-confirm-go" form="ordrar-q" type="submit" name="action" value="<?= $confirmSlutfor ? 'ordrar.slutfor' : 'ordrar.nasta' ?>" data-js="ordrar-nasta">Nästa</button>
                <p class="ordrar-slutfor-help" data-js="ordrar-confirm-help"><?php
                    if (!$confirmSlutfor) {
                        echo 'Går vidare till föranmälan.';
                    } elseif ($booksAtBestall) {
                        echo 'När ärendet slutförs skrivs ordern i Fortnox. Installationen bokas i Beställ när du bekräftar förslaget.';
                    } else {
                        echo 'När ärendet slutförs skrivs ordern i Fortnox och första lediga tiden i kalendern bokas. Tiden kan ändras efteråt.';
                    }
                ?></p>
            </div>
            </div>
            <script>
            (function () {
                var root = document.querySelector('.page-ordrar');
                if (!root) { return; }
                window.jeabOrdrarBusy = window.jeabOrdrarBusy || function (btn, on) {
                    // Dubbelklicksskydd: grå knapp med spinner tills svaret kommit.
                    if (!btn) { return; }
                    if (on) {
                        btn.disabled = true;
                        btn.setAttribute('aria-busy', 'true');
                        btn.classList.add('is-busy');
                        return;
                    }
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.classList.remove('is-busy');
                };
                function fieldEl(card, key) {
                    return card ? card.querySelector('[data-field="' + key + '"]') : null;
                }
                function readVal(el) {
                    if (!el) { return ''; }
                    return el.tagName === 'INPUT' ? String(el.value || '').trim() : String(el.textContent || '').trim();
                }
                function readCard(card) {
                    return {
                        name: readVal(fieldEl(card, 'name')) || String(card.getAttribute('data-name') || ''),
                        pnr: readVal(fieldEl(card, 'pnr')) || String(card.getAttribute('data-pnr') || ''),
                        addr: readVal(fieldEl(card, 'address')) || String(card.getAttribute('data-addr') || '')
                    };
                }
                function setNamed(form, name, value) {
                    var el = form.querySelector('input[name="' + name + '"]');
                    if (el) { el.value = value; }
                }
                function paintPills(card, isHuvud) {
                    card.querySelectorAll('.ordrar-owner-pill').forEach(function (pill) {
                        pill.textContent = isHuvud ? 'Huvudsökande' : 'Medsökande';
                        pill.setAttribute('data-role', isHuvud ? 'huvudsokande' : 'medsokande');
                        pill.classList.toggle('is-selected', isHuvud);
                        pill.setAttribute('aria-pressed', isHuvud ? 'true' : 'false');
                    });
                }
                function appendHidden(form, name, value) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                }
                function writeCanon(form, huvudCard) {
                    var cards = Array.prototype.slice.call(form.querySelectorAll('.ordrar-owner'));
                    var huvud = readCard(huvudCard);
                    setNamed(form, 'huvudsokande', huvud.name);
                    setNamed(form, 'huvudsokande_personnummer', huvud.pnr);
                    setNamed(form, 'huvudsokande_address', huvud.addr);
                    form.querySelectorAll('input[name="medsokande_name[]"], input[name="medsokande_personnummer[]"], input[name="medsokande_address[]"], input[name="owner_name[]"], input[name="owner_pnr[]"], input[name="owner_address[]"]').forEach(function (el) {
                        el.parentNode.removeChild(el);
                    });
                    cards.forEach(function (card) {
                        var person = readCard(card);
                        appendHidden(form, 'owner_name[]', person.name);
                        appendHidden(form, 'owner_pnr[]', person.pnr);
                        appendHidden(form, 'owner_address[]', person.addr);
                        if (card === huvudCard) { return; }
                        appendHidden(form, 'medsokande_name[]', person.name);
                        appendHidden(form, 'medsokande_personnummer[]', person.pnr);
                        appendHidden(form, 'medsokande_address[]', person.addr);
                    });
                    cards.forEach(function (card) {
                        paintPills(card, card === huvudCard);
                    });
                }
                var form = root.querySelector('#ordrar-q') || root.querySelector('.ordrar-confirm form');
                var confirmBox = root.querySelector('.ordrar-confirm');
                function readKind() {
                    var checked = form ? form.querySelector('input[name="kind"]:checked') : null;
                    return checked ? String(checked.value || '').toLowerCase() : String((confirmBox && confirmBox.getAttribute('data-kind')) || '').toLowerCase();
                }
                function isSolarKind(kind) {
                    kind = String(kind || '').toLowerCase();
                    return kind === 'batteri' || kind === 'sol' || (kind.indexOf('sol') !== -1 && kind.indexOf('ladd') === -1);
                }
                function paintConfirmNext() {
                    if (!confirmBox) { return; }
                    var kind = readKind();
                    if (kind) {
                        confirmBox.setAttribute('data-kind', kind);
                    } else {
                        confirmBox.setAttribute('data-kind', '');
                    }
                    var go = root.querySelector('#ordrar-confirm-go');
                    var help = root.querySelector('[data-js="ordrar-confirm-help"]');
                    var kalkyl = form ? form.querySelector('[data-js="offert"]') : null;
                    if (kalkyl) {
                        var showKalkyl = kind === 'sol';
                        kalkyl.hidden = !showKalkyl;
                        if (showKalkyl) {
                            var probe = kalkyl.querySelector('[data-in="b14"]');
                            if (probe) { probe.dispatchEvent(new Event('input', { bubbles: true })); }
                        }
                    }
                    var hasKind = kind === 'laddbox' || kind === 'batteri' || kind === 'sol';
                    var slutfor = kind === 'laddbox' || kind === 'batteri' || kind === 'sol';
                    var booksLater = kind === 'batteri' || kind === 'sol';
                    if (go) {
                        go.value = slutfor ? 'ordrar.slutfor' : 'ordrar.nasta';
                        go.textContent = 'Nästa';
                    }
                    if (help) {
                        help.textContent = !slutfor
                            ? 'Går vidare till föranmälan.'
                            : (booksLater
                                ? 'När ärendet slutförs skrivs ordern i Fortnox. Installationen bokas i Beställ när du bekräftar förslaget.'
                                : 'När ärendet slutförs skrivs ordern i Fortnox och första lediga tiden i kalendern bokas. Tiden kan ändras efteråt.');
                    }
                    if (hasKind) { clearKindRequired(); }
                }
                function clearKindRequired() {
                    var box = form ? form.querySelector('.ordrar-kind') : null;
                    var msg = form ? form.querySelector('[data-js="ordrar-kind-req"]') : null;
                    if (box) {
                        box.classList.remove('is-invalid');
                        box.removeAttribute('aria-invalid');
                    }
                    if (msg) { msg.hidden = true; }
                }
                function showKindRequired(button) {
                    var box = form ? form.querySelector('.ordrar-kind') : null;
                    var msg = form ? form.querySelector('[data-js="ordrar-kind-req"]') : null;
                    if (!box) { return; }
                    box.classList.add('is-invalid');
                    box.setAttribute('aria-invalid', 'true');
                    if (msg) { msg.hidden = false; }
                    var boxRect = box.getBoundingClientRect();
                    var btnRect = button ? button.getBoundingClientRect() : null;
                    var outOfView = boxRect.top < 8 || boxRect.bottom > window.innerHeight - 8;
                    var farFromBtn = btnRect ? (btnRect.top - boxRect.bottom) > Math.min(220, window.innerHeight * 0.35) : true;
                    if (outOfView || farFromBtn) {
                        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
                function resultSlot() {
                    return root.querySelector('[data-js="ordrar-result"]');
                }
                function saveFailText(message) {
                    var reason = String(message || '').trim();
                    if (!reason) { return 'Kunde inte spara.'; }
                    if (reason.indexOf('Kunde inte spara') === 0) { return reason; }
                    return 'Kunde inte spara. ' + reason;
                }
                function showSaveFail(message) {
                    var text = saveFailText(message);
                    var slot = resultSlot();
                    if (slot) { slot.textContent = ''; }
                    root.querySelectorAll('[data-js="ordrar-ajax-status"]').forEach(function (node) { node.remove(); });
                    root.querySelectorAll('[data-js="ordrar-ajax-error"]').forEach(function (node) { node.remove(); });
                    var pane = root.querySelector('.ordrar-confirm') || root.querySelector('.ordrar-detail-main') || root;
                    var note = document.createElement('p');
                    pane.insertBefore(note, pane.firstChild);
                    note.className = 'ordrar-help is-alert';
                    note.setAttribute('data-js', 'ordrar-ajax-error');
                    note.setAttribute('role', 'alert');
                    note.textContent = text;
                    root.querySelectorAll('[data-js="ordrar-missing-preview"]').forEach(function (node) {
                        var preview = String(node.textContent || '').trim().replace(/\.$/, '');
                        if (preview && text.indexOf(preview) !== -1) {
                            node.remove();
                        }
                    });
                }
                function showSpin() {
                    var slot = resultSlot();
                    if (slot) {
                        slot.innerHTML = '<div class="ordrar-spin" role="status">Sparar…</div>';
                    }
                }
                function replaceStepper(html) {
                    var stepper = root.querySelector('.ordrar-stepper');
                    if (!stepper || !html) { return false; }
                    var wrap = document.createElement('div');
                    wrap.innerHTML = html;
                    var next = wrap.querySelector('.ordrar-stepper');
                    if (!next) { return false; }
                    stepper.replaceWith(next);
                    return true;
                }
                function restoreConfirmResult(data) {
                    var slot = root.querySelector('.ordrar-confirm [data-js="ordrar-result"]');
                    if (!slot || !data || typeof data.result_html !== 'string') { return; }
                    slot.innerHTML = data.result_html;
                }
                function paintNavForKind(kind) {
                    var stepper = root.querySelector('.ordrar-stepper');
                    var shortFlow = kind === 'laddbox';
                    var solarKeys = { spec: true, orderrader: true, boka: true, bestall: true };
                    if (stepper) {
                        Array.prototype.forEach.call(stepper.children, function (li) {
                            var key = li.getAttribute('data-key') || '';
                            li.hidden = shortFlow && !!solarKeys[key];
                        });
                        var n = 0;
                        Array.prototype.forEach.call(stepper.children, function (li) {
                            if (li.hidden || li.classList.contains('is-status')) { return; }
                            n += 1;
                            var num = li.querySelector('.ordrar-step-n');
                            if (num) { num.textContent = String(n); }
                        });
                    }
                    root.querySelectorAll('.ordrar-step-pane').forEach(function (pane) {
                        var stop = pane.getAttribute('data-stop') || '';
                        if (shortFlow && solarKeys[stop]) { pane.hidden = true; }
                    });
                    var slot = root.querySelector('.ordrar-confirm [data-js="ordrar-result"]');
                    if (slot && String(slot.textContent || '').indexOf('Sparar') !== -1) {
                        slot.textContent = '';
                    }
                }
                function paintStepper(flow, current) {
                    var stepper = root.querySelector('.ordrar-stepper');
                    if (!stepper || !Array.isArray(flow)) { return; }
                    var byKey = {};
                    flow.forEach(function (step) {
                        if (step && step.key) { byKey[step.key] = step; }
                    });
                    Array.prototype.forEach.call(stepper.children, function (li) {
                        var key = li.getAttribute('data-key') || '';
                        var step = byKey[key] || {};
                        if (key !== 'status') {
                            li.classList.toggle('is-done', !!step.done);
                        }
                        li.classList.toggle('is-current', key === current);
                        var date = li.querySelector('.ordrar-step-date');
                        if (date && key !== 'status') { date.textContent = step.date || ''; }
                    });
                }
                var savedKind = readKind();
                var kindSave = 0;
                function setKindBusy(on) {
                    if (!form) { return; }
                    form.querySelectorAll('input[name="kind"]').forEach(function (el) { el.disabled = on; });
                    var goBtn = root.querySelector('#ordrar-confirm-go');
                    if (goBtn) { goBtn.disabled = on; }
                }
                function paintSaveStatus(text) {
                    var note = root.querySelector('[data-js="ordrar-save-status"]');
                    if (!note) { return; }
                    note.textContent = String(text || '').trim();
                }
                function applyKindNav(data) {
                    if (data && data.stepper_html) { replaceStepper(data.stepper_html); }
                    restoreConfirmResult(data);
                    paintSaveStatus((data && data.status) || '');
                    var stop = (data && data.stop) || 'bekrafta';
                    if (window.jeabOrdrarShowStop) {
                        window.jeabOrdrarShowStop(stop, (data && data.stop_url) || '', false);
                    }
                    paintConfirmNext();
                }
                function persistKind() {
                    if (!form) { return; }
                    var kind = readKind();
                    paintConfirmNext();
                    paintNavForKind(kind);
                    if (!kind || kind === savedKind) { return; }
                    var seq = ++kindSave;
                    var body = new FormData(form);
                    body.set('action', 'ordrar.spara');
                    setKindBusy(true);
                    fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    }).then(function (data) {
                        if (seq !== kindSave) { return; }
                        savedKind = String((data && data.kind) || kind);
                        applyKindNav(data);
                    }).catch(function (err) {
                        if (seq !== kindSave) { return; }
                        var radio = form.querySelector('input[name="kind"][value="' + savedKind + '"]');
                        if (radio) { radio.checked = true; }
                        paintConfirmNext();
                        paintNavForKind(savedKind);
                        showSaveFail(err && err.message ? err.message : 'Kunde inte spara.');
                    }).then(function () {
                        if (seq === kindSave) { setKindBusy(false); }
                    });
                }
                var confirmBusy = false;
                function postSlutfor() {
                    if (confirmBusy) { return Promise.resolve(); }
                    confirmBusy = true;
                    var body = new FormData(form);
                    var goBtn = root.querySelector('#ordrar-confirm-go');
                    body.set('action', 'ordrar.slutfor');
                    window.jeabOrdrarBusy(goBtn, true);
                    showSpin();
                    return fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    }).then(function (data) {
                        if (data.stepper_html) { replaceStepper(data.stepper_html); }
                        restoreConfirmResult(data);
                        if (window.jeabOrdrarJumpNext) {
                            window.jeabOrdrarJumpNext('bekrafta');
                            return;
                        }
                        applyKindNav(data);
                    }).catch(function (err) {
                        showSaveFail(err && err.message ? err.message : 'Kunde inte spara.');
                        window.jeabOrdrarBusy(goBtn, false);
                    }).then(function () {
                        confirmBusy = false;
                        if (goBtn) { goBtn.classList.remove('is-busy'); goBtn.removeAttribute('aria-busy'); }
                    });
                }
                function postNasta() {
                    if (confirmBusy) { return Promise.resolve(); }
                    confirmBusy = true;
                    var body = new FormData(form);
                    var goBtn = root.querySelector('#ordrar-confirm-go');
                    body.set('action', 'ordrar.nasta');
                    window.jeabOrdrarBusy(goBtn, true);
                    showSpin();
                    return fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    }).then(function (data) {
                        var y = window.scrollY || window.pageYOffset || 0;
                        var html = data.view_html || data.spec_html;
                        var stop = data.stop || 'spec';
                        var specPane = root.querySelector('.ordrar-step-pane[data-stop="' + stop + '"]');
                        if (html && specPane) {
                            var end = specPane.querySelector('.ordrar-step-end');
                            specPane.innerHTML = html;
                            if (end) { specPane.appendChild(end); }
                            if (window.jeabOrdrarShowStop) {
                                window.jeabOrdrarShowStop(stop, data.stop_url || '', false);
                            }
                        } else if (data.stop_url) {
                            window.location.assign(data.stop_url);
                            return;
                        } else {
                            var slot = resultSlot();
                            if (slot && data.result_html) {
                                slot.innerHTML = data.result_html;
                            } else {
                                paintSaveStatus((data && data.status) || 'Sparad.');
                            }
                        }
                        restoreConfirmResult(data);
                        if (data.stepper_html) { replaceStepper(data.stepper_html); }
                        else { paintStepper(data.flow || [], stop); }
                        if (window.jeabOrdrarJumpNext) {
                            window.jeabOrdrarJumpNext('bekrafta');
                        } else {
                            window.scrollTo(0, y);
                        }
                    }).catch(function (err) {
                        showSaveFail(err && err.message ? err.message : 'Kunde inte spara.');
                        window.jeabOrdrarBusy(goBtn, false);
                    }).then(function () {
                        confirmBusy = false;
                        if (goBtn) { goBtn.classList.remove('is-busy'); goBtn.removeAttribute('aria-busy'); }
                    });
                }
                if (form) {
                    var kindBox = form.querySelector('.ordrar-kind');
                    if (kindBox) {
                        kindBox.addEventListener('click', function () {
                            setTimeout(function () {
                                paintConfirmNext();
                                paintNavForKind(readKind());
                            }, 0);
                        });
                    }
                    form.addEventListener('change', function (event) {
                        if (event.target && event.target.name === 'kind') {
                            persistKind();
                        }
                    });
                    paintConfirmNext();
                    form.addEventListener('submit', function (event) {
                        var cards = form.querySelectorAll('.ordrar-owner');
                        var huvudCard = null;
                        cards.forEach(function (card) {
                            if (card.querySelector('.ordrar-owner-pill[data-role="huvudsokande"].is-selected')) {
                                huvudCard = card;
                            }
                        });
                        if (huvudCard) { writeCanon(form, huvudCard); }
                        var submitter = event.submitter;
                        var act = submitter && submitter.name === 'action' ? String(submitter.value || '') : '';
                        var kindNow = readKind();
                        var hasKind = kindNow === 'laddbox' || kindNow === 'batteri' || kindNow === 'sol';
                        if ((act === 'ordrar.nasta' || act === 'ordrar.slutfor' || act === 'ordrar.spara' || act === '') && !hasKind) {
                            event.preventDefault();
                            event.stopPropagation();
                            showKindRequired(submitter || root.querySelector('#ordrar-confirm-go'));
                            return;
                        }
                        if ((kindNow === 'laddbox' || kindNow === 'batteri' || kindNow === 'sol') && (act === 'ordrar.slutfor' || act === 'ordrar.nasta' || act === '')) {
                            event.preventDefault();
                            event.stopPropagation();
                            postSlutfor();
                            return;
                        }
                        if (act === 'ordrar.nasta' || (act === '' && isSolarKind(readKind()))) {
                            event.preventDefault();
                            event.stopPropagation();
                            postNasta();
                        }
                    });
                }
                root.addEventListener('input', function (event) {
                    var val = event.target.closest('.ordrar-owner-val');
                    var card = val ? val.closest('.ordrar-owner') : null;
                    if (!card) { return; }
                    var person = readCard(card);
                    card.setAttribute('data-name', person.name);
                    card.setAttribute('data-pnr', person.pnr);
                    card.setAttribute('data-addr', person.addr);
                });
                var picking = false;
                function postPick(form) {
                    var body = new FormData(form);
                    body.set('action', 'ordrar.pick');
                    return fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    });
                }
                root.addEventListener('click', function (event) {
                    var pill = event.target.closest('.ordrar-owner-pill');
                    if (pill) {
                        event.preventDefault();
                        event.stopPropagation();
                        var form = pill.closest('form');
                        var card = pill.closest('.ordrar-owner');
                        if (!form || !card || picking) { return; }
                        if (card.querySelector('.ordrar-owner-pill[data-role="huvudsokande"].is-selected')) { return; }
                        var prevHuvud = form.querySelector('.ordrar-owner-pill[data-role="huvudsokande"].is-selected');
                        var prevCard = prevHuvud ? prevHuvud.closest('.ordrar-owner') : null;
                        writeCanon(form, card);
                        picking = true;
                        postPick(form).catch(function () {
                            if (prevCard) { writeCanon(form, prevCard); }
                        }).then(function () {
                            picking = false;
                        });
                        return;
                    }
                    var btn = event.target.closest('.ordrar-copy');
                    if (!btn) { return; }
                    event.preventDefault();
                    var row = btn.closest('.ordrar-owner-row, .ordrar-fast-row');
                    var val = row ? row.querySelector('.ordrar-owner-val, .ordrar-fast-beteckning') : null;
                    var text = '';
                    if (val) {
                        text = val.tagName === 'INPUT' ? String(val.value || '').trim() : String(val.textContent || '').trim();
                    }
                    if (text && navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text);
                    }
                });
            })();
            </script>
        <?php elseif ($view === 'boka_bestall'): ?>
            <?= jeab_ordrar_boka_bestall_html($open, $company, $openId, $store ?? null, $error ?? null) ?>
        <?php elseif ($view === 'boka' || $view === 'boka_elin'): ?>
            <?= jeab_ordrar_boka_html($open, $company, $openId, $view, $store ?? null) ?>
        <?php elseif ($view === 'vad'): ?>
            <?= jeab_ordrar_vad_html($open, $company, $openId) ?>
        <?php elseif ($view === 'fakturering'): ?>
            <?= jeab_ordrar_fakturering_html($open, $company, $openId, $error ?? null) ?>
        <?php elseif ($view === 'fortnox' && $orderNo !== ''): ?>
            <div class="ordrar-card">
                <h2>Fortnox-order</h2>
                <p class="ordrar-order-no"><?= e($orderNo) ?></p>
                <?php if ($address !== ''): ?>
                    <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
                <?php endif; ?>
                <p class="ordrar-help"><?= e($kindLabel) ?><?= $fxPrice > 0 ? ' · ' . e(jeab_ordrar_kr($fxPrice)) . ' kr' : '' ?></p>
            </div>
        <?php elseif ($view === 'fortnox'): ?>
            <div class="ordrar-card">
                <h2>Fortnox-order</h2>
                <?php if ($address !== ''): ?>
                    <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
                <?php endif; ?>
                <p class="ordrar-help"><?= e($kindLabel) ?> · <?= e(jeab_ordrar_kr($fxPrice)) ?> kr</p>
                <p class="ordrar-help"><?= e(trim((string) ($open['huvudsokande'] ?? ''))) ?> · <?= e(trim((string) ($open['fastighetsbeteckning'] ?? ''))) ?></p>
                <form method="post" data-once="1" action="<?= e(jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'fortnox'])) ?>">
                    <?= jeab_csrf_field() ?>
                    <input type="hidden" name="action" value="ordrar.fortnox">
                    <input type="hidden" name="id" value="<?= $openId ?>">
                    <button class="ordrar-primary" type="submit">Skapa Fortnox-order</button>
                </form>
            </div>
        <?php elseif ($view === 'laddbox'): ?>
            <form class="ordrar-card" method="post" action="<?= e($action) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.laddbox">
                <input type="hidden" name="id" value="<?= $openId ?>">
                <h2>Vad som ska installeras</h2>
                <?php if ($address !== ''): ?>
                    <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
                <?php endif; ?>
                <label class="ordrar-field">
                    <span>Vad som ska installeras</span>
                    <input type="text" name="install_what" value="<?= e((string) ($open['install_what'] ?? '')) ?>" autocomplete="off">
                    <p class="ordrar-field-help">Detta blir specifikationen på fakturan</p>
                </label>
                <label class="ordrar-field">
                    <span>Pris</span>
                    <input type="text" name="pris" inputmode="numeric" value="<?= !empty($open['pris']) ? e(jeab_ordrar_kr((int) $open['pris'])) : '' ?>" autocomplete="off">
                    <p class="ordrar-field-help">Pris efter grön teknik – det kunden betalar.</p>
                </label>
                <label class="ordrar-field">
                    <span>Anteckning till installation</span>
                    <textarea name="install_note" rows="3"><?= e((string) ($open['install_note'] ?? '')) ?></textarea>
                    <p class="ordrar-field-help">Syns inte för kunden. kommer med i bokningen i kalendern för installatören</p>
                </label>
                <?php if ($stop3 && $missingFx): ?>
                    <p class="ordrar-help">Saknas för Fortnox-order: <?= e(implode(', ', $missingFx)) ?>.</p>
                <?php endif; ?>
                <button class="ordrar-primary" type="submit">Spara</button>
            </form>
        <?php elseif ($view === 'eon'): ?>
            <?= jeab_ordrar_eon_html($open, $company, $openId, is_string($error ?? null) ? $error : null) ?>
        <?php elseif ($view === 'orderrader'): ?>
            <?php
            $mallar = jeab_inkop_list();
            $mallKind = $kind === 'sol' ? 'sol' : 'batteri';
            ?>
            <div class="ordrar-card">
            <form data-js="ordrar-lines-autosave" method="post" action="<?= e(jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'orderrader'])) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="ordrar.orderrader">
                <input type="hidden" name="id" value="<?= $openId ?>">
                <h2>Orderrader</h2>
                <?php if ($address !== ''): ?>
                    <p class="ordrar-help">Leveransadress: <?= e($address) ?></p>
                <?php endif; ?>
                <p class="ordrar-help">Första lediga tiden bokas när ordern läggs.</p>
                <div class="ordrar-mall" data-js="ordrar-mall" data-action="<?= e(jeab_url('index.php')) ?>" data-rows-start="<?= (int) jeab_ordrar_inkop_start_rows() ?>" data-rows-step="<?= (int) jeab_ordrar_inkop_more_rows() ?>" data-rows-max="<?= (int) jeab_ordrar_inkop_max_rows() ?>" data-mallar="<?= e(json_encode($mallar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <div class="inkop-kind cal-company ordrar-mall-kind" role="group" aria-label="Val">
                        <button type="button" class="chip<?= $mallKind === 'batteri' ? ' is-on' : '' ?>" data-kind="batteri">Batteri</button>
                        <button type="button" class="chip<?= $mallKind === 'sol' ? ' is-on' : '' ?>" data-kind="sol">Solpaneler</button>
                    </div>
                    <label class="ordrar-mall-row">
                        <span class="ordrar-mall-lbl">Mall</span>
                        <select class="ordrar-mall-pick inkop-mall-pick" data-js="ordrar-mall-pick">
                            <option value="">Ny mall</option>
                            <?php foreach (array_slice($mallar, 0, 10) as $mall): ?>
                                <?php if (!is_array($mall)) { continue; } ?>
                                <option value="<?= e((string) ($mall['id'] ?? '')) ?>"><?= e((string) ($mall['name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ordrar-mall-row">
                        <span class="ordrar-mall-lbl">Namn</span>
                        <input class="ordrar-mall-name inkop-name" data-js="ordrar-mall-name" type="text" maxlength="80" placeholder="Namn på mall" autocomplete="off">
                    </label>
                    <div class="ordrar-lines">
                        <span class="ordrar-field-lbl">Inköpslista</span>
                        <?php foreach ($inkop as $line): ?>
                            <div class="ordrar-line">
                                <input type="text" name="line_sku[]" value="<?= e((string) $line['sku']) ?>" placeholder="Artikelnummer" autocomplete="off">
                                <input type="text" name="line_title[]" value="<?= e((string) $line['title']) ?>" placeholder="Artikelnamn" autocomplete="off">
                                <input type="number" name="line_qty[]" value="<?= e(jeab_ordrar_inkop_qty_input($line['qty'] ?? '')) ?>" placeholder="Antal" min="0" step="1" inputmode="numeric">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ordrar-lines-tools">
                    <button type="button" class="ordrar-secondary" data-js="ordrar-lines-more"<?= count($inkop) >= jeab_ordrar_inkop_max_rows() ? ' hidden' : '' ?>>Lägg till 5 rader</button>
                    <button type="button" class="ordrar-secondary inkop-save" data-js="ordrar-mall-save">Spara som mall</button>
                    <p class="ordrar-help" data-js="ordrar-lines-status" role="status"></p>
                    </div>
                    <p class="ordrar-mall-status" data-js="ordrar-mall-status" role="status"></p>
                </div>
            </form>
            <script>
            (function () {
                var form = document.querySelector('[data-js="ordrar-lines-autosave"]');
                if (!form || form.getAttribute('data-bound') === '1') { return; }
                form.setAttribute('data-bound', '1');
                var status = form.querySelector('[data-js="ordrar-lines-status"]');
                var timer = 0;
                var seq = 0;
                var last = '';
                function lineSnapshot() {
                    var body = new FormData(form);
                    var parts = [];
                    body.forEach(function (value, key) {
                        if (key.indexOf('line_') === 0) { parts.push(key + '=' + value); }
                    });
                    return parts.join('&');
                }
                function paintStatus(text, alert) {
                    if (!status) { return; }
                    status.textContent = text;
                    status.classList.toggle('is-alert', !!alert);
                }
                function save() {
                    var now = lineSnapshot();
                    if (now === last) { return; }
                    var ticket = ++seq;
                    paintStatus('Sparar…', false);
                    var body = new FormData(form);
                    body.delete('intent');
                    fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    }).then(function (data) {
                        if (ticket !== seq) { return; }
                        last = now;
                        paintStatus((data && data.status) || 'Sparad.', false);
                    }).catch(function (err) {
                        if (ticket !== seq) { return; }
                        paintStatus(err && err.message ? err.message : 'Kunde inte spara.', true);
                    });
                }
                form.addEventListener('input', function (event) {
                    var target = event.target;
                    if (!target || !target.name || String(target.name).indexOf('line_') !== 0) { return; }
                    window.clearTimeout(timer);
                    timer = window.setTimeout(save, 400);
                });
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    window.clearTimeout(timer);
                    save();
                });
                last = lineSnapshot();
            })();
            </script>
            </div>
            <?= function_exists('jeab_solkoll_card_html') ? jeab_solkoll_card_html($open, $company, $openId) : '' ?>
            <?= jeab_ordrar_bestall_step_html($open, $company, $openId, $store ?? null, $error ?? null) ?>
        <?php elseif ($view === 'paminelse' || $view === 'kop' || $view === 'fardiganmal'): ?>
            <?= jeab_ordrar_solo_html($openId, $view, $company) ?>
        <?php elseif ($view === 'spec'): ?>
            <?= jeab_ordrar_spec_html($open, $company, $openId, $error ?? null) ?>
        <?php endif; ?>
        </div>
        <?php endforeach; $view = $renderView; ?>
        </div>
        <?= jeab_ordrar_activity_html($open) ?>
        <div class="ordrar-after-log">
            <?= jeab_ordrar_radera_html($openId, $company) ?>
        </div>
            <script>
            (function () {
                // Landa på panelen direkt vid sidladdning (utan mjukt hopp).
                var pane = document.querySelector('.page-ordrar .ordrar-step-pane[data-land="1"]');
                if (!pane || (location.hash && location.hash.length > 1)) { return; }
                try { if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; } } catch (e) { /* äldre webbläsare */ }
                var landedAt = -1;
                function scrollY() {
                    return window.pageYOffset || document.documentElement.scrollTop || 0;
                }
                function land() {
                    var top = Math.max(0, Math.round(pane.getBoundingClientRect().top + scrollY() - 16));
                    window.scrollTo(0, top);
                    // Ligger top-raden kvar över innehållet (sticky) flyttas panelen ner under den.
                    var chrome = document.querySelector('.chrome');
                    var bar = chrome ? chrome.getBoundingClientRect() : null;
                    if (bar && bar.top <= 1 && bar.bottom > 0 && pane.getBoundingClientRect().top < bar.bottom + 16) {
                        window.scrollTo(0, Math.max(0, Math.round(pane.getBoundingClientRect().top + scrollY() - bar.bottom - 16)));
                    }
                    landedAt = scrollY();
                }
                function reland() {
                    if (landedAt < 0 || Math.abs(scrollY() - landedAt) <= 2) { land(); }
                }
                land();
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', reland);
                }
                window.addEventListener('load', reland);
            })();
            </script>
            <script>
            (function () {
                var root = document.querySelector('.page-ordrar');
                if (!root || root.getAttribute('data-ordrar-ajax') === '1') { return; }
                root.setAttribute('data-ordrar-ajax', '1');
                window.jeabOrdrarBusy = window.jeabOrdrarBusy || function (btn, on) {
                    // Dubbelklicksskydd: grå knapp med spinner tills svaret kommit.
                    if (!btn) { return; }
                    if (on) {
                        btn.disabled = true;
                        btn.setAttribute('aria-busy', 'true');
                        btn.classList.add('is-busy');
                        return;
                    }
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.classList.remove('is-busy');
                };
                var clickedSubmitter = null;
                function visiblePane() {
                    var panes = root.querySelectorAll('.ordrar-step-pane');
                    for (var i = 0; i < panes.length; i++) {
                        if (!panes[i].hidden) { return panes[i]; }
                    }
                    return root.querySelector('.ordrar-detail-main') || root;
                }
                function paneFor(stop) {
                    var panes = root.querySelectorAll('.ordrar-step-pane');
                    for (var i = 0; i < panes.length; i++) {
                        if (panes[i].getAttribute('data-stop') === stop) { return panes[i]; }
                    }
                    return null;
                }
                function resolveStop(stop) {
                    if (stop === 'bestall' && paneFor('orderrader')) { return 'orderrader'; }
                    if (paneFor(stop)) { return stop; }
                    if ((stop === 'boka' || stop === 'bestall') && paneFor('boka_bestall')) { return 'boka_bestall'; }
                    if (stop === 'fakturera' && paneFor('fardiganmal')) { return 'fardiganmal'; }
                    return stop;
                }
                function markStep(stop) {
                    var stepper = root.querySelector('.ordrar-stepper');
                    if (!stepper) { return; }
                    Array.prototype.forEach.call(stepper.children, function (li) {
                        var on = li.getAttribute('data-key') === stop;
                        li.classList.toggle('is-current', on);
                        var link = li.querySelector('a.ordrar-step-link');
                        if (!link) { return; }
                        if (on) { link.setAttribute('aria-current', 'step'); }
                        else { link.removeAttribute('aria-current'); }
                    });
                }
                function showStop(stop, href, push) {
                    var panes = root.querySelectorAll('.ordrar-step-pane');
                    if (!panes.length) { return false; }
                    stop = resolveStop(stop || 'status');
                    var pane = paneFor(stop);
                    if (!pane && stop !== 'status') {
                        stop = 'status';
                        pane = paneFor('status');
                    }
                    if (!pane) { return false; }
                    pane.hidden = false;
                    markStep(stop);
                    if (href && window.history) {
                        var state = { stop: stop };
                        if (push) { history.pushState(state, '', href); }
                        else { history.replaceState(state, '', href); }
                    }
                    syncRefresh(stop);
                    if (pane.scrollIntoView) {
                        pane.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                    return true;
                }
                function nextUngreen(fromKey) {
                    var stepper = root.querySelector('.ordrar-stepper');
                    if (!stepper) { return 'status'; }
                    var keys = [];
                    Array.prototype.forEach.call(stepper.children, function (li) {
                        if (li.hidden) { return; }
                        var key = li.getAttribute('data-key') || '';
                        if (!key || key === 'status') { return; }
                        keys.push({ key: key, done: li.classList.contains('is-done') });
                    });
                    var start = 0;
                    if (fromKey && fromKey !== 'status') {
                        for (var i = 0; i < keys.length; i++) {
                            if (keys[i].key === fromKey || resolveStop(keys[i].key) === fromKey) {
                                start = i + 1;
                                break;
                            }
                        }
                    }
                    for (var j = start; j < keys.length; j++) {
                        if (keys[j].done) { continue; }
                        var stop = resolveStop(keys[j].key);
                        if (paneFor(stop)) { return stop; }
                    }
                    return 'status';
                }
                function jumpNext(fromKey) {
                    return showStop(nextUngreen(fromKey || 'status'), '', false);
                }
                function quenchNasta(scope) {
                    var rootNode = scope || root;
                    if (!rootNode || !rootNode.querySelectorAll) { return; }
                    rootNode.querySelectorAll('button').forEach(function (btn) {
                        if (btn.getAttribute('data-js') === 'ordrar-nasta' || btn.id === 'ordrar-confirm-go' || (btn.name === 'intent' && btn.value === 'next')) {
                            btn.disabled = true;
                        }
                    });
                }
                function syncRefresh(stop) {
                    var link = root.querySelector('a.ordrar-refresh');
                    if (!link || !stop) { return; }
                    try {
                        var url = new URL(link.getAttribute('href') || link.href, window.location.href);
                        url.searchParams.set('stop', stop);
                        url.searchParams.set('refresh', '1');
                        link.href = url.pathname + url.search + url.hash;
                    } catch (e) { /* behåll serverns href */ }
                }
                var panesBox = root.querySelector('[data-js="ordrar-panes"]');
                var landDefault = (panesBox && panesBox.getAttribute('data-land-default')) || 'status';
                var openPane = root.querySelector('.ordrar-step-pane[data-land="1"]') || root.querySelector('.ordrar-step-pane:not([hidden])');
                if (openPane) { syncRefresh(openPane.getAttribute('data-stop') || ''); }
                if (openPane && !/(?:\?|&)stop=/.test(location.search)) {
                    // Utan stop: Uppdatera landar på samma ställe igen (Bekräfta).
                    var refreshLink = root.querySelector('a.ordrar-refresh');
                    if (refreshLink) {
                        try {
                            var refreshUrl = new URL(refreshLink.getAttribute('href') || refreshLink.href, window.location.href);
                            refreshUrl.searchParams.delete('stop');
                            refreshLink.href = refreshUrl.pathname + refreshUrl.search + refreshUrl.hash;
                        } catch (e) { /* behåll serverns href */ }
                    }
                }
                window.jeabOrdrarShowStop = showStop;
                window.jeabOrdrarJumpNext = jumpNext;
                if (window.history && /(?:\?|&)refresh=1(?:&|$)/.test(location.search)) {
                    try {
                        var clean = new URL(location.href);
                        clean.searchParams.delete('refresh');
                        history.replaceState({ stop: clean.searchParams.get('stop') || 'status' }, '', clean.pathname + clean.search + clean.hash);
                    } catch (e) { /* keep the refresh url */ }
                }
                window.addEventListener('popstate', function () {
                    var stop = 'status';
                    try { stop = new URL(location.href).searchParams.get('stop') || landDefault; } catch (e) { stop = landDefault; }
                    stop = resolveStop(stop);
                    if (!showStop(stop, '', false)) { location.reload(); }
                });
                function resultSlot() {
                    var pane = visiblePane();
                    return (pane && pane.querySelector('[data-js="ordrar-result"]')) || root.querySelector('[data-js="ordrar-result"]');
                }
                function showSpin() {
                    var slot = resultSlot();
                    if (slot) {
                        slot.innerHTML = '<div class="ordrar-spin" role="status">Sparar…</div>';
                        return;
                    }
                    var pane = visiblePane().querySelector('[data-js="ordrar-pane"]') || visiblePane();
                    if (!pane) { return; }
                    var note = pane.querySelector('[data-js="ordrar-ajax-status"]');
                    if (!note) {
                        note = document.createElement('p');
                        note.className = 'ordrar-help';
                        note.setAttribute('data-js', 'ordrar-ajax-status');
                        note.setAttribute('role', 'status');
                        pane.insertBefore(note, pane.firstChild);
                    }
                    note.textContent = 'Sparar…';
                }
                function paintStepper(flow, current) {
                    var stepper = root.querySelector('.ordrar-stepper');
                    if (!stepper || !Array.isArray(flow)) { return; }
                    var byKey = {};
                    flow.forEach(function (step) {
                        if (step && step.key) { byKey[step.key] = step; }
                    });
                    Array.prototype.forEach.call(stepper.children, function (li) {
                        var key = li.getAttribute('data-key') || '';
                        var step = byKey[key] || {};
                        if (key !== 'status') {
                            li.classList.toggle('is-done', !!step.done);
                        }
                        li.classList.toggle('is-current', key === current);
                        var date = li.querySelector('.ordrar-step-date');
                        if (date && key !== 'status') { date.textContent = step.date || ''; }
                    });
                }
                function applyPayload(data, opts) {
                    opts = opts || {};
                    var y = window.scrollY || window.pageYOffset || 0;
                    var stop = data.stop || '';
                    var resolved = stop ? resolveStop(stop) : '';
                    if (!opts.nasta && stop && !paneFor(resolved) && data.stop_url) {
                        window.location.assign(data.stop_url);
                        return;
                    }
                    var host = (resolved && paneFor(resolved)) || visiblePane();
                    if (resolved && !opts.nasta) { showStop(resolved, data.stop_url || '', false); host = paneFor(resolved) || host; }
                    else if (resolved && paneFor(resolved)) { host = paneFor(resolved); }
                    var pane = host.querySelector ? host.querySelector('[data-js="ordrar-pane"]') : null;
                    if (data.view_html && pane) {
                        var wrap = document.createElement('div');
                        wrap.innerHTML = data.view_html;
                        var next = wrap.firstElementChild || wrap;
                        pane.replaceWith(next);
                    } else if (data.view_html && host.classList && host.classList.contains('ordrar-step-pane')) {
                        var end = host.querySelector('.ordrar-step-end');
                        host.innerHTML = data.view_html;
                        if (end) { host.appendChild(end); }
                    } else {
                        var slot = (host.querySelector && host.querySelector('[data-js="ordrar-result"]')) || resultSlot();
                        if (slot && data.result_html) {
                            slot.innerHTML = data.result_html;
                        } else {
                            var note = (host.querySelector && host.querySelector('[data-js="ordrar-save-status"]')) || root.querySelector('[data-js="ordrar-save-status"]');
                            if (!note) {
                                var anchor = (host.querySelector && host.querySelector('.ordrar-footer button, .ordrar-next-row button, .ordrar-spec-actions button')) || null;
                                if (anchor && anchor.parentNode) {
                                    note = document.createElement('p');
                                    note.className = 'ordrar-save-status';
                                    note.setAttribute('data-js', 'ordrar-save-status');
                                    note.setAttribute('role', 'status');
                                    anchor.insertAdjacentElement('afterend', note);
                                }
                            }
                            if (note) { note.textContent = String((data && data.status) || 'Sparad.').trim(); }
                        }
                    }
                    if (data.stepper_html) {
                        var stepper = root.querySelector('.ordrar-stepper');
                        if (stepper) {
                            var wrap = document.createElement('div');
                            wrap.innerHTML = data.stepper_html;
                            var next = wrap.querySelector('.ordrar-stepper');
                            if (next) { stepper.replaceWith(next); }
                            else { paintStepper(data.flow || [], stop); }
                        }
                    } else {
                        paintStepper(data.flow || [], stop);
                    }
                    if (opts.nasta) {
                        var fromPane = opts.from ? paneFor(opts.from) : null;
                        quenchNasta(fromPane || host);
                        jumpNext(opts.from || stop || 'status');
                        return;
                    }
                    window.scrollTo(0, y);
                }
                function saveFailText(message) {
                    var reason = String(message || '').trim();
                    if (!reason) { return 'Kunde inte spara.'; }
                    if (reason.indexOf('Kunde inte spara') === 0) { return reason; }
                    return 'Kunde inte spara. ' + reason;
                }
                function showAlert(message) {
                    var text = saveFailText(message);
                    var slot = resultSlot();
                    if (slot) { slot.textContent = ''; }
                    root.querySelectorAll('[data-js="ordrar-ajax-status"]').forEach(function (node) { node.remove(); });
                    root.querySelectorAll('[data-js="ordrar-ajax-error"]').forEach(function (node) { node.remove(); });
                    var pane = visiblePane().querySelector('[data-js="ordrar-pane"]')
                        || visiblePane()
                        || root;
                    var existing = document.createElement('p');
                    pane.insertBefore(existing, pane.firstChild);
                    existing.className = 'ordrar-help is-alert';
                    existing.setAttribute('data-js', 'ordrar-ajax-error');
                    existing.setAttribute('role', 'alert');
                    existing.textContent = text;
                    root.querySelectorAll('[data-js="ordrar-missing-preview"]').forEach(function (node) {
                        var preview = String(node.textContent || '').trim().replace(/\.$/, '');
                        if (preview && text.indexOf(preview) !== -1) {
                            node.remove();
                        }
                    });
                }
                function mirrorIntent(form, submitter) {
                    if (!submitter) { return; }
                    var hidden = form.querySelector('input[data-js="intent-mirror"]');
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'intent';
                        hidden.setAttribute('data-js', 'intent-mirror');
                        form.appendChild(hidden);
                    }
                    hidden.value = submitter.name === 'intent' ? (submitter.value || 'next') : '';
                }
                function isNastaButton(btn) {
                    if (!btn) { return false; }
                    if (btn.getAttribute('data-js') === 'ordrar-nasta' || btn.getAttribute('data-js') === 'ordrar-jump') { return true; }
                    if (btn.id === 'ordrar-confirm-go') { return true; }
                    if (btn.name === 'intent' && btn.value === 'next') { return true; }
                    return String(btn.textContent || '').trim() === 'Nästa' && btn.classList.contains('ordrar-primary');
                }
                function postForm(form, submitter) {
                    // Ett anrop åt gången per formulär (Fortnox, beställning, föranmälan skapar externt).
                    if (form.getAttribute('data-busy') === '1') { return Promise.resolve(); }
                    form.setAttribute('data-busy', '1');
                    var who = submitter || clickedSubmitter;
                    clickedSubmitter = null;
                    var nasta = isNastaButton(who);
                    var fromStop = '';
                    var fromPane = form && form.closest ? form.closest('.ordrar-step-pane') : null;
                    if (fromPane) { fromStop = fromPane.getAttribute('data-stop') || ''; }
                    if (who && who.form === form) {
                        mirrorIntent(form, who);
                    }
                    if (who && who.getAttribute('data-faktura-go') === '1') {
                        var flag = form.querySelector('[data-faktura-flag]');
                        if (flag) { flag.disabled = false; flag.value = '1'; }
                        var box = form.querySelector('[data-faktura-check]');
                        if (box) { box.checked = true; }
                        who.disabled = true;
                        who.setAttribute('aria-busy', 'true');
                        who.setAttribute('data-busy', '1');
                    }
                    var body = new FormData(form);
                    if (who && who.name && who.form === form) {
                        body.set(who.name, who.value);
                    }
                    var btn = submitter || form.querySelector('.ordrar-primary');
                    window.jeabOrdrarBusy(btn, true);
                    showSpin();
                    return fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    }).then(function (data) {
                        form.removeAttribute('data-busy');
                        if (btn) { btn.classList.remove('is-busy'); btn.removeAttribute('aria-busy'); btn.removeAttribute('data-busy'); }
                        applyPayload(data, { nasta: nasta, from: fromStop });
                    }).catch(function (err) {
                        form.removeAttribute('data-busy');
                        showAlert(err && err.message ? err.message : 'Kunde inte spara.');
                        if (btn) { btn.removeAttribute('data-busy'); }
                        window.jeabOrdrarBusy(btn, false);
                    });
                }
                root.addEventListener('click', function (event) {
                    var ask = event.target.closest('[data-faktura-ask]');
                    if (ask && root.contains(ask)) {
                        event.preventDefault();
                        var pane = ask.closest('[data-js="ordrar-pane"]') || root;
                        var panel = pane.querySelector('[data-faktura-confirm]');
                        if (panel) { panel.hidden = false; }
                        var backBtn = panel ? panel.querySelector('[data-faktura-back]') : null;
                        if (backBtn) { backBtn.focus(); }
                        return;
                    }
                    var back = event.target.closest('[data-faktura-back]');
                    if (back && root.contains(back)) {
                        event.preventDefault();
                        var open = back.closest('[data-faktura-confirm]');
                        if (open) { open.hidden = true; }
                        return;
                    }
                    var jump = event.target.closest('[data-js="ordrar-jump"]');
                    if (jump && root.contains(jump)) {
                        event.preventDefault();
                        jump.disabled = true;
                        var fromPane = jump.closest('.ordrar-step-pane');
                        var fromKey = fromPane ? (fromPane.getAttribute('data-stop') || 'status') : 'status';
                        jumpNext(fromKey);
                        return;
                    }
                    var stepLink = event.target.closest('a.ordrar-step-link');
                    if (stepLink && root.contains(stepLink) && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && event.button === 0) {
                        var li = stepLink.closest('.ordrar-step');
                        var stop = resolveStop(li ? (li.getAttribute('data-key') || '') : '');
                        if (stop && paneFor(stop)) {
                            event.preventDefault();
                            var t0 = (window.performance && performance.now) ? performance.now() : Date.now();
                            showStop(stop, stepLink.href, true);
                            var ms = ((window.performance && performance.now) ? performance.now() : Date.now()) - t0;
                            root.setAttribute('data-step-ms', String(Math.round(ms * 10) / 10));
                            return;
                        }
                    }
                    var btn = event.target.closest('button[type="submit"]');
                    if (!btn || !root.contains(btn) || !btn.form) { return; }
                    clickedSubmitter = btn;
                    mirrorIntent(btn.form, btn);
                }, true);
                function stripAnlaggningsId(input) {
                    if (!input || input.name !== 'anlaggnings_id') { return; }
                    var raw = String(input.value || '');
                    var next = raw.replace(/\D+/g, '');
                    if (raw === next) { return; }
                    var start = input.selectionStart;
                    var end = input.selectionEnd;
                    input.value = next;
                    if (start == null || end == null) { return; }
                    var before = raw.slice(0, start).replace(/\D+/g, '').length;
                    var span = raw.slice(start, end).replace(/\D+/g, '').length;
                    try { input.setSelectionRange(before, before + span); } catch (e) { /* type=text only */ }
                }
                root.addEventListener('input', function (event) {
                    stripAnlaggningsId(event.target);
                });
                window.addEventListener('pageshow', function () {
                    root.querySelectorAll('form[data-once="1"][data-sent="1"]').forEach(function (f) {
                        f.removeAttribute('data-sent');
                        window.jeabOrdrarBusy(f.querySelector('button[type="submit"]'), false);
                    });
                });
                root.addEventListener('blur', function (event) {
                    stripAnlaggningsId(event.target);
                }, true);
                function postSolkoll(form, btn) {
                    // Skapa/koppla i Solkoll: ett klick, spinner, fel i samma ruta.
                    if (form.getAttribute('data-busy') === '1') { return; }
                    form.setAttribute('data-busy', '1');
                    var card = form.closest('[data-js="ordrar-solkoll"]');
                    var status = form.querySelector('[data-js="ordrar-solkoll-status"]');
                    window.jeabOrdrarBusy(btn, true);
                    if (status) { status.textContent = 'Arbetar i Solkoll…'; status.classList.remove('is-alert'); status.setAttribute('role', 'status'); }
                    fetch(form.getAttribute('action') || form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            Accept: 'application/json'
                        }
                    }).then(function (res) {
                        return res.json().catch(function () { return {}; }).then(function (data) {
                            if (!res.ok || data.ok === false) {
                                throw new Error((data && data.error) || 'Kunde inte spara.');
                            }
                            return data;
                        });
                    }).then(function (data) {
                        form.removeAttribute('data-busy');
                        if (card && data.solkoll_html) {
                            var wrap = document.createElement('div');
                            wrap.innerHTML = data.solkoll_html;
                            var next = wrap.querySelector('[data-js="ordrar-solkoll"]');
                            if (next) { card.replaceWith(next); return; }
                        }
                        window.jeabOrdrarBusy(btn, false);
                        if (status) { status.textContent = data.status || 'Klart.'; }
                    }).catch(function (err) {
                        form.removeAttribute('data-busy');
                        window.jeabOrdrarBusy(btn, false);
                        if (status) {
                            status.textContent = saveFailText(err && err.message ? err.message : '');
                            status.classList.add('is-alert');
                            status.setAttribute('role', 'alert');
                        }
                    });
                }
                root.addEventListener('submit', function (event) {
                    var solkoll = event.target.closest('[data-js="ordrar-solkoll-form"]');
                    if (solkoll && root.contains(solkoll)) {
                        event.preventDefault();
                        clickedSubmitter = null;
                        postSolkoll(solkoll, event.submitter || solkoll.querySelector('button[type="submit"]'));
                        return;
                    }
                    var once = event.target.closest('form[data-once="1"]');
                    if (once && root.contains(once)) {
                        // Vanlig POST (sidan laddas om): andra klicket skickas inte.
                        if (once.getAttribute('data-sent') === '1') { event.preventDefault(); return; }
                        once.setAttribute('data-sent', '1');
                        window.jeabOrdrarBusy(event.submitter || once.querySelector('button[type="submit"]'), true);
                        return;
                    }
                    var form = event.target;
                    if (form && form.querySelectorAll) {
                        form.querySelectorAll('[name="anlaggnings_id"]').forEach(stripAnlaggningsId);
                    }
                    form = event.target.closest('[data-js="ordrar-ajax"]');
                    if (!form || !root.contains(form)) { return; }
                    event.preventDefault();
                    postForm(form, event.submitter || null);
                });
            })();
            </script>
        </div><!-- .ordrar-detail-main -->
        </div><!-- .ordrar-detail-grid -->
        <?php if (function_exists('jeab_fastighet_refresh_dialog')): ?>
            <?= jeab_fastighet_refresh_dialog() ?>
            <script src="<?= e(jeab_asset_url('assets/js/fastighet-refresh.js')) ?>" defer></script>
        <?php endif; ?>
    <?php else: ?>
        <div class="ordrar-board is-day is-lista" data-server-search="1" data-chip="<?= e($boardChip) ?>" data-q="<?= e($boardQuery) ?>" data-board-who="<?= e((string) ($whoFilter ?? '')) ?>" data-board-job="<?= e((string) ($jobFilter ?? '')) ?>">
            <?php $boardFilters = $boardFilters ?? jeab_ordrar_board_filters($rows, $store ?? null); ?>
            <div class="cal-filter ordrar-staff-filter">
                <div class="cal-filter-main">
                    <?= jeab_ordrar_mina_chips_html($company, $whoFilter, $jobFilter) ?>
                    <?= function_exists('jeab_who_scope_note_html') ? jeab_who_scope_note_html($company, jeab_ordrar_url(['co' => $company])) : '' ?>
                </div>
            </div>
            <div class="ordrar-filters" data-js="ordrar-filters" role="tablist" aria-label="Filtrera ordrar">
                <?php
                $ordrarChipLink = static function (string $chip, string $label, string $extraClass = '') use ($company, $whoFilter, $jobFilter, $boardQuery, $boardChip, $boardFilters): void {
                    $params = ['co' => $company] + jeab_filter_url_params($whoFilter, $jobFilter);
                    if ($chip !== '') {
                        $params['chip'] = $chip;
                    }
                    if ($boardQuery !== '') {
                        $params['q'] = $boardQuery;
                    }
                    $on = $boardChip === $chip;
                    $countKey = $chip === '' ? 'all' : $chip;
                    $cls = 'ordrar-filter' . ($extraClass !== '' ? ' ' . $extraClass : '') . ($on ? ' is-on' : '');
                    echo '<a class="' . e($cls) . '" href="' . e(jeab_ordrar_url($params)) . '" data-filter="' . e($chip) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
                        . $label . ' <em>' . (int) ($boardFilters[$countKey] ?? 0) . '</em></a>';
                };
                $ordrarChipLink('', 'Alla');
                $ordrarChipLink('wait', 'Behöver svar', 'is-wait');
                $ordrarChipLink('foran', 'Föranmälan');
                $ordrarChipLink('besikt', 'Besiktning');
                $ordrarChipLink('boka', 'Boka / beställ');
                $ordrarMore = static function (string $offsetKey, int $offset) use ($company, $whoFilter, $jobFilter, $boardQuery, $boardChip): string {
                    $params = ['co' => $company, $offsetKey => $offset] + jeab_filter_url_params($whoFilter, $jobFilter);
                    if ($boardChip !== '') {
                        $params['chip'] = $boardChip;
                    }
                    if ($boardQuery !== '') {
                        $params['q'] = $boardQuery;
                    }
                    return jeab_ordrar_url($params);
                };
                ?>
            </div>
            <div class="ordrar-cols" aria-hidden="true">
                <span>Adress</span>
                <span>Steg</span>
                <span>Föranmälan</span>
                <span>Besiktning</span>
                <span>Installation</span>
                <span>Beställd</span>
                <span>Leverans</span>
                <span>Nästa</span>
            </div>
            <?php if (!$rows): ?>
                <p class="ordrar-empty"><?= $slutfasRows !== [] ? 'Inga ordrar som behöver åtgärd.' : 'Inga ordrar.' ?></p>
            <?php else: ?>
                <ul class="ordrar-list">
                    <?php foreach ($rows as $row): ?>
                        <?= jeab_ordrar_board_row_html($row, $company, $store ?? null, $staffFilter) ?>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($ordrarNext)): ?>
                <a class="crm-more" href="<?= e($ordrarMore('n', (int) $ordrarNext)) ?>">Visa fler (<?= (int) $ordrarTotal - (int) $ordrarNext ?> kvar)</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($slutfasTotal > 0): ?>
            <details class="ordrar-slutfas" data-js="ordrar-slutfas" open>
                <summary>Slutfas <em data-js="ordrar-slutfas-count"><?= (int) $slutfasTotal ?></em></summary>
                <ul class="ordrar-list">
                    <?php foreach ($slutfasRows as $row): ?>
                        <?= jeab_ordrar_board_row_html($row, $company, $store ?? null, $staffFilter) ?>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($slutfasNext)): ?>
                <a class="crm-more" href="<?= e($ordrarMore('ns', (int) $slutfasNext)) ?>">Visa fler (<?= (int) $slutfasTotal - (int) $slutfasNext ?> kvar)</a>
                <?php endif; ?>
            </details>
            <?php endif; ?>
            <?php if ($otherRows): ?>
            <div class="ordrar-fallback" data-js="ordrar-fallback" hidden>
                <p class="ordrar-fallback-note">Ingen träff på dina egna. Vi hittade däremot följande:</p>
                <ul class="ordrar-list"></ul>
                <template data-js="ordrar-fallback-src">
                    <?php foreach ($otherRows as $row): ?>
                        <?= jeab_ordrar_board_row_html($row, $company, $store ?? null, $staffFilter) ?>
                    <?php endforeach; ?>
                </template>
            </div>
            <?php endif; ?>
            <details class="ordrar-klart">
                <summary>Allt klart</summary>
                <?php if (!$klartRows): ?>
                    <p class="ordrar-empty">Inga klara ordrar.</p>
                <?php else: ?>
                    <ul class="ordrar-list">
                        <?php foreach ($klartRows as $row): ?>
                            <?= jeab_ordrar_board_row_html($row, $company, $store ?? null, $staffFilter) ?>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($klartNext)): ?>
                    <a class="crm-more" href="<?= e($ordrarMore('nk', (int) $klartNext)) ?>">Visa fler (<?= (int) $klartTotal - (int) $klartNext ?> kvar)</a>
                    <?php endif; ?>
                <?php endif; ?>
            </details>
            <script>
            (function () {
                var board = document.querySelector('.ordrar-board.is-day');
                if (!board) return;
                board.querySelectorAll('a.crm-more').forEach(function (more) {
                    more.addEventListener('click', function (event) {
                        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                        event.preventDefault();
                        var url;
                        try { url = new URL(more.href, location.href); } catch (e) { location.href = more.href; return; }
                        url.searchParams.set('intent', 'rows');
                        fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                var list = more.parentElement ? more.parentElement.querySelector('.ordrar-list') : null;
                                if (!data || data.ok !== true || !list) { location.href = more.href; return; }
                                list.insertAdjacentHTML('beforeend', data.html || '');
                                if (data.next) {
                                    var next = new URL(more.href, location.href);
                                    ['n', 'ns', 'nk'].forEach(function (key) {
                                        if (url.searchParams.has(key)) next.searchParams.set(key, String(data.next));
                                    });
                                    more.href = next.pathname + next.search;
                                } else {
                                    more.remove();
                                }
                            })
                            .catch(function () { location.href = more.href; });
                    });
                });
                var navGen = (window.jeabNav && window.jeabNav.current) ? window.jeabNav.current() : 0;
                function listNavAlive() {
                    if (!document.body.contains(board)) return false;
                    try {
                        var id = new URL(location.href).searchParams.get('id') || '';
                        if (/^[1-9]\d*$/.test(id)) return false;
                    } catch (e) { return false; }
                    if (window.jeabNav && window.jeabNav.current && window.jeabNav.current() !== navGen) return false;
                    return true;
                }
                var input = board.parentNode.querySelector('[data-js="ordrar-search"]') || document.querySelector('[data-js="ordrar-search"]');
                var list = board.querySelector('.ordrar-list');
                var filters = board.querySelector('[data-js="ordrar-filters"]');
                var active = '';
                var fallback = board.querySelector('[data-js="ordrar-fallback"]');
                var klart = board.querySelector('.ordrar-klart');
                function ensureFallback() {
                    if (!fallback || fallback.getAttribute('data-ready') === '1') return;
                    var src = fallback.querySelector('template');
                    var ul = fallback.querySelector('.ordrar-list');
                    if (src && ul) {
                        var frag = src.content ? src.content.cloneNode(true) : null;
                        if (frag) { ul.appendChild(frag); }
                    }
                    fallback.setAttribute('data-ready', '1');
                }
                function apply() {
                    if (!listNavAlive()) return;
                    var q = input ? (input.value || '').toLowerCase().trim() : '';
                    var ownHits = 0;
                    var otherHits = 0;
                    Array.prototype.forEach.call(board.querySelectorAll('.ordrar-list'), function (ul) {
                        if (fallback && fallback.contains(ul) && fallback.getAttribute('data-ready') !== '1') return;
                        var isOther = !!(fallback && fallback.contains(ul));
                        Array.prototype.forEach.call(ul.children, function (li) {
                            var a = li.querySelector('.ordrar-row');
                            var name = ((a && a.getAttribute('data-name')) || '').toLowerCase();
                            var tags = ' ' + ((a && (a.getAttribute('data-tags') || '')) || '') + ' ';
                            var hitName = q === '' || name.indexOf(q) !== -1;
                            var hitFilter = active === '' || tags.indexOf(' ' + active + ' ') !== -1;
                            var hit = hitName && hitFilter;
                            li.hidden = !hit;
                            if (hit && q !== '') {
                                if (isOther) { otherHits++; } else { ownHits++; }
                            }
                        });
                    });
                    if (q !== '' && ownHits === 0) {
                        ensureFallback();
                        otherHits = 0;
                        if (fallback) {
                            Array.prototype.forEach.call(fallback.querySelectorAll('.ordrar-list > li'), function (li) {
                                var a = li.querySelector('.ordrar-row');
                                var name = ((a && a.getAttribute('data-name')) || '').toLowerCase();
                                var tags = ' ' + ((a && (a.getAttribute('data-tags') || '')) || '') + ' ';
                                var hit = (name.indexOf(q) !== -1) && (active === '' || tags.indexOf(' ' + active + ' ') !== -1);
                                li.hidden = !hit;
                                if (hit) otherHits++;
                            });
                        }
                    }
                    if (fallback) fallback.hidden = !(q !== '' && ownHits === 0 && otherHits > 0);
                    if (klart && q !== '' && ownHits > 0 && klart.querySelector('.ordrar-list > li:not([hidden])')) klart.open = true;
                    var slutfas = board.querySelector('[data-js="ordrar-slutfas"]');
                    if (slutfas) {
                        var slutfasVis = 0;
                        Array.prototype.forEach.call(slutfas.querySelectorAll('.ordrar-list > li'), function (li) {
                            if (!li.hidden) slutfasVis++;
                        });
                        var slutfasCount = slutfas.querySelector('[data-js="ordrar-slutfas-count"]');
                        if (slutfasCount) slutfasCount.textContent = String(slutfasVis);
                        slutfas.hidden = slutfasVis === 0;
                    }
                    syncChipQ(input ? (input.value || '').trim() : '');
                }
                // Byte till Alla eller annan person, och tillbaka från en order: söktexten följer med.
                function syncChipQ(q) {
                    if (!listNavAlive()) return;
                    if (!q && typeof window.jeabListQuery === 'string' && window.jeabListQuery) q = window.jeabListQuery;
                    Array.prototype.forEach.call(document.querySelectorAll('.ordrar-staff-filter a.chip, a.ordrar-row'), function (link) {
                        try {
                            var u = new URL(link.getAttribute('href'), location.href);
                            if (q) { u.searchParams.set('q', q); } else { u.searchParams.delete('q'); }
                            link.setAttribute('href', u.pathname + u.search + u.hash);
                        } catch (e) {}
                    });
                    try {
                        var here = new URL(location.href);
                        if (q) { here.searchParams.set('q', q); } else { here.searchParams.delete('q'); }
                        var next = here.pathname + here.search + here.hash;
                        if (next !== location.pathname + location.search + location.hash) {
                            history.replaceState(history.state, '', next);
                        }
                    } catch (e) {}
                }
                if (input) {
                    try {
                        var startQ = new URL(location.href).searchParams.get('q') || '';
                        if (!startQ && typeof window.jeabListQuery === 'string') startQ = window.jeabListQuery;
                        if (!startQ && history.state && typeof history.state.jeabQ === 'string') startQ = history.state.jeabQ;
                        if (startQ && !input.value) input.value = startQ;
                    } catch (e) {}
                    input.addEventListener('input', apply);
                    if (input.value) apply();
                }
                board.addEventListener('click', function (event) {
                    var t = event.target;
                    if (t && t.nodeType === 3) t = t.parentNode;
                    var row = t && t.closest ? t.closest('a.ordrar-row') : null;
                    if (!row || !board.contains(row)) return;
                    event.stopPropagation();
                });
                if (filters) {
                    filters.addEventListener('click', function (event) {
                        var t = event.target;
                        if (t && t.nodeType === 3) t = t.parentNode;
                        if (!t || !t.closest) return;
                        if (t.closest('a.ordrar-row')) return;
                        var btn = t.closest('.ordrar-filter');
                        if (!btn || !filters.contains(btn)) return;
                        active = btn.getAttribute('data-filter') || '';
                        Array.prototype.forEach.call(filters.querySelectorAll('.ordrar-filter'), function (el) {
                            var on = el === btn;
                            el.classList.toggle('is-on', on);
                            el.setAttribute('aria-pressed', on ? 'true' : 'false');
                        });
                        apply();
                    });
                }
            })();
            </script>
        </div>
    <?php endif; ?>
</section>
<?php
$jeabOrdrarBody = ob_get_clean();
if (function_exists('jeab_timing_span')) {
    jeab_timing_span('ordrar_html', microtime(true) - $jeabOrdrarHtml);
}
return $jeabOrdrarBody;
