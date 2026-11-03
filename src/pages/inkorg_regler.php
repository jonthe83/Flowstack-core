<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once dirname(__DIR__) . '/inkorg_section.php';

$GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'] = 1;
$GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = 1;

if (function_exists('jeab_inkorg_review_threshold_migrate')) {
    jeab_inkorg_review_threshold_migrate();
}
$reviewMin = function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70;
$rules = jeab_inkorg_rules_ordered();
$editId = (string) ($_GET['edit'] ?? '');
$edit = null;
foreach ($rules as $r) {
    if (($r['id'] ?? '') === $editId) {
        $edit = $r;
    }
}
$prefill = $edit ?? (!empty($_GET['ny']) ? [
    'id' => '', 'name' => (string) ($_GET['name'] ?? ''), 'match' => 'all',
    'conditions' => [['field' => (string) ($_GET['field'] ?? 'from'), 'header' => '', 'op' => (string) ($_GET['op'] ?? 'equals'), 'value' => (string) ($_GET['value'] ?? '')]],
    'type' => (string) ($_GET['type'] ?? ''), 'action' => (string) ($_GET['act'] ?? 'arkivera'), 'priority' => (int) ($_GET['priority'] ?? 4),
    'confidence' => 100, 'product' => (string) ($_GET['type'] ?? '') === 'Ny lead', 'lead_sender' => false,
] : null);
if ($prefill !== null) {
    $prefill['conditions'][] = ['field' => '', 'header' => '', 'op' => 'contains', 'value' => ''];
}
$fields = jeab_inkorg_rule_fields();
$ops = jeab_inkorg_rule_ops();
$post = e(jeab_url('index.php?p=inkorg-regler'));
$lists = jeab_inkorg_lists();
$listNames = function (string $ids) use ($lists): string {
    return implode('/', array_map(fn($id) => preg_replace('/^Produkt:\s*/u', '', (string) ($lists[$id]['name'] ?? $id . ' (saknas)')), jeab_inkorg_word_list($ids)));
};
$desc = function (array $r) use ($fields, $ops, $listNames, $reviewMin): string {
    $parts = [];
    foreach ($r['conditions'] as $c) {
        $v = (string) $c['value'];
        $what = mb_strtolower($c['field'] === 'header' ? 'mejlhuvudet ' . $c['header'] : ($fields[$c['field']] ?? $c['field']));
        $p = jeab_inkorg_word_list($v);
        $parts[] = match ($c['op']) {
            'exists' => $what . ' finns',
            'in_list' => $what . ' nämner ' . $listNames($v),
            'not_in_list' => $what . ' nämner inte ' . $listNames($v),
            'dominant' => count($p) >= 4 ? 'mer ' . $listNames($p[0]) . ' än ' . $listNames($p[1]) . ' (minst ' . $p[2] . ', ' . $p[3] . ' gånger fler)' : $v,
            default => $what . ' ' . ($ops[$c['op']] ?? $c['op']) . ' «' . (mb_strlen($v) > 60 ? mb_substr($v, 0, 59) . '…' : $v) . '»',
        };
    }
    $tagNames = implode(', ', array_map(fn($t) => (string) (jeab_inkorg_tags()[$t]['name'] ?? $t), (array) ($r['add_tags'] ?? [])));
    if (($r['type'] ?? '') === '') {
        return 'Om ' . implode($r['match'] === 'any' ? ' ELLER ' : ' OCH ', $parts) . ' → etikett ' . $tagNames . ', fortsätter till nästa regel';
    }
    $type = jeab_inkorg_type_new((string) $r['type']);
    return 'Om ' . implode($r['match'] === 'any' ? ' ELLER ' : ' OCH ', $parts) . ' → ' . $type
        . ((int) $r['confidence'] < $reviewMin ? ', till «Behöver granskas»' : '')
        . ($tagNames !== '' ? ', etikett ' . $tagNames : '') . (!empty($r['assign']) ? ', tilldela ' . (jeab_inkorg_staff()[$r['assign']] ?? $r['assign']) : '') . (!empty($r['continue']) ? ', fortsätter till nästa regel' : '');
};

ob_start();
?>
<div class="rg-wrap apple-app">
<?= jeab_inkorg_sidebar_html('', 'inkorg-regler') ?>
<div class="inkorg-page regler-page">
<a class="rg-back" href="<?= e(jeab_url('index.php?p=inkorg')) ?>">← Inkorg</a>
<header class="crm-top">
    <div class="crm-top-text">
        <?php $regelBox = function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : 'jarfalla'; ?>
        <p class="apple-eyebrow"><?= e($regelBox === 'malmo' ? 'Malmö' : 'Järfälla') ?></p>
        <h1 class="crm-title">Regler</h1>
    </div>
</header>
<p class="ink-muted rg-lead">Grok läser hela mejlet och sätter produkt, säljare och avsikt. En regel får bara säga om leadet är eget eller köpt. Säkerhet under <?= (int) $reviewMin ?> % hamnar i «Behöver granskas» utan produkt.</p>
<nav class="tabs rg-jump" data-rg-tabs aria-label="Avsnitt"></nav>
<?php
$regelFlash = (isset($flash) && is_string($flash) && $flash !== '') ? $flash : (isset($_GET['flash']) ? (string) $_GET['flash'] : null);
if (function_exists('jeab_flash_text')) {
    $regelFlash = jeab_flash_text(is_string($regelFlash) ? $regelFlash : null);
}
?>
<?= jeab_flash_html(is_string($regelFlash) ? $regelFlash : null) ?>
<?= jeab_error_html($error ?? null) ?>
<?php
$set = jeab_inkorg_settings();
$tagsAll = jeab_inkorg_tags();
$changed = function (string $file): string {
    $path = jeab_inkorg_dir() . '/' . $file;
    return is_file($path) ? ' · senast ändrad ' . date('Y-m-d', (int) filemtime($path)) : '';
};
$sectionChanged = function (string $section): string {
    return function_exists('jeab_inkorg_section_saved_label') ? jeab_inkorg_section_saved_label($section) : '';
};
jeab_inkorg_section_open('instruktion', 'Grok-instruktion', 'Instruktion och produktetiketter' . $sectionChanged('instruktion'), ['tag' => 'form', 'action' => $post, 'class' => 'regel-form is-wide']);
?>
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="inkorg.settings_save">
        <div class="ink-plate">
        <div class="rg-split full">
        <div class="rg-split-main">
        <label class="full">Instruktion<textarea name="intent" rows="12" data-clamp><?= e((string) $set['intent']) ?></textarea></label>
        </div>
        <aside class="rg-help">Grok läser hela mejlet och sätter produkt, säljare och avsikt. En regel får bara säga om leadet är eget eller köpt. Osäkert hamnar i «Behöver granskas» utan produkt.</aside>
        </div>
        <h3 class="full">Produkt → etikett</h3>
        <?php foreach (['solceller' => 'Solceller', 'batteri' => 'Batteri', 'elbilsladdare' => 'Elbilsladdare', 'support' => 'Support'] as $pk => $pl):
            $savedTag = (string) ($set['product_tags'][$pk] ?? '');
            $tagKnown = $savedTag !== '' && array_key_exists($savedTag, $tagsAll);
        ?>
        <div class="full">Etikett när produkten är <?= e($pl) ?>
            <div class="chips" role="radiogroup" aria-label="Etikett när produkten är <?= e($pl) ?>">
                <label class="chip-opt"><input type="radio" name="product_tags[<?= e($pk) ?>]" value=""<?= $tagKnown ? '' : ' checked' ?>><span>(ingen)</span></label>
                <?php foreach ($tagsAll as $tid => $tg): ?>
                <label class="chip-opt"><input type="radio" name="product_tags[<?= e($pk) ?>]" value="<?= e((string) $tid) ?>"<?= $tagKnown && $savedTag === (string) $tid ? ' checked' : '' ?>><span><?= e((string) $tg['name']) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
<?php jeab_inkorg_section_close('Spara instruktion'); ?>

<div class="rg-grid">
<?php
jeab_auto_intent_migrate();
if (function_exists('jeab_inkorg_service_intent_migrate')) {
    jeab_inkorg_service_intent_migrate();
}
if (function_exists('jeab_webshop_intent_migrate')) {
    jeab_webshop_intent_migrate();
}
$au = jeab_auto_settings();
$auLabels = ['laddbox_jarfalla' => 'Elbilsladdare Järfälla', 'batteri_jarfalla' => 'Batteri Järfälla', 'sol_jarfalla' => 'Solceller Järfälla', 'laddbox_malmo' => 'Elbilsladdare Malmö', 'batteri_malmo' => 'Batteri Malmö', 'sol_malmo' => 'Solceller Malmö'];
$tplN = count(array_filter($au['templates'], fn($t) => trim((string) $t) !== ''));
jeab_inkorg_section_open('automatik', 'Automatik', ($au['quote_enabled'] ? 'Offerter på' : 'Offerter av') . ' · ' . $tplN . ' mallar' . $sectionChanged('automatik'), ['tag' => 'form', 'action' => $post, 'class' => 'regel-form']);
?>
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="inkorg.auto_save">
        <h2 class="rg-sr">Automatik efter klassning</h2>
        <p class="full ink-muted">Grok avgör utifrån instruktionen ovan. Köpintresse (Elbilsladdare, batteri till befintliga solceller, solceller med batteri): <?= e(jeab_gp_auto_sender_phrase()) ?> med mallen nedan och PDF, bara vid första kontakten. Har kunden en specifik fråga i fritexten läggs 1–2 meningar på svenska, utan löften, ovanför mallen. Går svaret inte att skriva hålls offerten. Inget offertutkast. Sedan en påminnelse med länken «mail» till ärendet, och ärendet stängs. Har kunden redan en offert eller en påminnelse skickas ingen ny offert. Ett svar med köp, fråga eller kontaktbegäran får kvittensen nedan från påminnelsens ägare, och ärendet lämnas öppet. Hålls offerten lämnas ärendet öppet i Väntar på offert, med anteckning och påminnelse till produktägaren. Support om en produkt får bara etikett. Nyhetsbrev och reklam utan produkt hamnar i Papperskorgen. Ett lead med produkt flyttas inte dit av automatiken. Leverantörsinformation blir Information.</p>
        <ul class="ink-muted">
        <?php foreach (jeab_auto_rule_lines() as $ruleLine): ?>
            <li><?= e($ruleLine) ?></li>
        <?php endforeach; ?>
        </ul>
        <div class="ink-plate">
        <label class="full"><input type="checkbox" name="grok_decides" value="1"<?= !empty($au['grok_decides']) ? ' checked' : '' ?>> Grok bestämmer produkt, avsikt och säljare</label>
        <label class="full"><input type="checkbox" name="auto_quote_enabled" value="1"<?= $au['quote_enabled'] ? ' checked' : '' ?>> Skicka offerter automatiskt</label>
        <label class="full"><input type="checkbox" name="follow_receipt_enabled" value="1"<?= !empty($au['follow_receipt_enabled']) ? ' checked' : '' ?>> Skicka kvittens när kunden svarar</label>
        <label class="full">Kvittens när kunden svarar<textarea name="follow_receipt" rows="3" data-clamp><?= e(jeab_follow_receipt_text()) ?></textarea></label>
        <label class="full"><input type="checkbox" name="unknown_product_ask_enabled" value="1"<?= !empty($au['unknown_product_ask']) ? ' checked' : '' ?>> Skicka frågan om okänd produkt</label>
        <label class="full"><input type="checkbox" name="auto_trash_spam" value="1"<?= $au['trash_spam'] ? ' checked' : '' ?>> Nyhetsbrev, reklam och skräp direkt till Papperskorgen</label>
        <label class="full"><input type="checkbox" name="sol_calendar_mail_off" value="1"<?= !empty($au['sol_calendar_mail_off']) ? ' checked' : '' ?>> Solceller: stäng av kalendermejl till kund</label>
        <label class="full">Bara dessa avsändare får offert (test, tomt = alla)<input type="text" name="auto_quote_only_from" value="<?= e(implode(', ', $au['only_from'])) ?>"></label>
        <label class="full">Leadsäljare, en domän per rad<textarea name="lead_sellers" rows="8"><?= e(implode("\n", array_map(static fn(array $row): string => (string) ($row['domain'] ?? ''), (array) ($au['lead_sellers'] ?? [])))) ?></textarea></label>
        <p class="full ink-muted" style="margin:0">Stödlista. När brytaren ovan är på fyller Grok på en domän när is_lead_seller har säkerhet minst 0,8. Av är den som tidigare. Privatpost och operatörer hoppas över. En säljardomän blir aldrig kundens e-post eller mottagare. Adressen tas ur mejltexten.</p>
        <?php foreach ((array) ($au['lead_sellers'] ?? []) as $sellerRow): if (!is_array($sellerRow) || (string) ($sellerRow['added'] ?? '') === '') { continue; } ?>
        <p class="full ink-muted" style="margin:0"><?= e((string) $sellerRow['domain']) ?> tillagd <?= e((string) $sellerRow['added']) ?></p>
        <?php endforeach; ?>
        <label>Lägsta säkerhet från Grok (%)<input type="number" name="auto_min_confidence" min="50" max="100" value="<?= (int) $au['min_confidence'] ?>"></label>
        <label>Automatiska offerter per timme<input type="number" name="auto_send_per_hour" min="1" max="500" value="<?= (int) $au['per_hour'] ?>"></label>
        <label>Automatiska offerter per dygn<input type="number" name="auto_send_per_day" min="1" max="2000" value="<?= (int) $au['per_day'] ?>"></label>
        <?php foreach ($auLabels as $k => $lab): ?>
        <label class="full">Offertmall <?= e($lab) ?><?= str_ends_with($k, '_malmo') && !jeab_inkorg_company_live('malmo') ? ' <strong>(vilande: Malmö i Help Scout)</strong>' : '' ?> <span class="ink-muted">(PDF: <?= (function_exists('jeab_notiser_quote_pdf_bytes') && is_string(jeab_notiser_quote_pdf_bytes($k))) || is_file(jeab_auto_quote_pdf_file($k)) ? 'finns' : 'saknas' ?>; tom mall = ingen automatisk offert)</span><textarea name="tpl[<?= e($k) ?>]" rows="6" data-clamp><?= e((string) ($au['templates'][$k] ?? '')) ?></textarea></label>
        <?php endforeach; ?>
        </div>
<?php jeab_inkorg_section_close('Spara automatik'); ?>

<?php
$hsCos = jeab_inkorg_helpscout_companies();
jeab_inkorg_section_open('mejlboxar', 'Mejlboxar', count($hsCos) . ' i Help Scout' . $sectionChanged('mejlboxar'), ['tag' => 'form', 'action' => $post, 'class' => 'regel-form']);
?>
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="inkorg.helpscout_save">
        <p class="ink-muted">Bocka i ett bolag som ska hanteras i Help Scout. Då läses dess kontakt@ inte in här, inget skickas från Inkorgen för bolaget, automatisk offert och ombokning hoppar över det, och bekräftelser/fullmakt går via Help Scout. Befintliga ärenden ligger kvar men visas inte.</p>
        <div class="ink-plate">
        <?php foreach (['jarfalla' => 'Järfälla (kontakt@jarfalla.example.test)', 'malmo' => 'Malmö (kontakt@malmo.example.test)'] as $hsK => $hsL): ?>
        <label class="full"><input type="checkbox" name="helpscout_companies[]" value="<?= e($hsK) ?>"<?= in_array($hsK, $hsCos, true) ? ' checked' : '' ?>> <?= e($hsL) ?> i Help Scout</label>
        <?php endforeach; ?>
        </div>
<?php jeab_inkorg_section_close('Spara mejlboxar'); ?>

<?php
$malmoHs = jeab_malmo_hs_settings();
$malmoOwner = jeab_malmo_hs_owner_name('laddbox');
if ($malmoOwner === '') {
    $malmoOwner = 'Kim';
}
$labBatteri = (string) (JEAB_JOB_TYPES['batteri'] ?? 'Batteri');
$labLadd = (string) (JEAB_JOB_TYPES['laddbox'] ?? 'Elbilsladdare');
$labSol = (string) (JEAB_JOB_TYPES['sol'] ?? 'Sol');
$malmoSum = ($malmoHs['sync'] ? 'Synk på' : 'Synk av') . ($malmoHs['close'] ? ' · stänger i Help Scout' : '');
jeab_inkorg_section_open('malmo', 'Malmö', $malmoSum, ['tag' => 'form', 'action' => $post, 'class' => 'regel-form']);
?>
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="inkorg.malmo_hs_save">
        <p class="ink-muted"><?= e($labBatteri) ?> och <?= e($labLadd) ?> från Malmö blir aktiva ärenden tilldelade <?= e($malmoOwner) ?>. De hanteras här som Järfälla: offert och svar från <?= e($malmoOwner) ?>s brevlåda, en påminnelse, och svar i inkorgen. När stängning är på stängs de i Help Scout med den interna anteckningen «Hanteras av Kim i flowstack.example.test». Inget kundsvar skickas i Help Scout-tråden.</p>
        <p class="ink-muted">Sol, sol och batteri tillsammans, och ärenden utan köpintresse blir Historik här och lämnas öppna i Help Scout. Inget svar och ingen stängning där. Bilagor sparas inte och inga länkar till Help Scout läggs in.</p>
        <div class="ink-plate">
        <label class="full"><input type="checkbox" name="malmo_hs_sync" value="1"<?= $malmoHs['sync'] ? ' checked' : '' ?>> Synka Malmö från Help Scout</label>
        <label class="full"><input type="checkbox" name="malmo_hs_close" value="1"<?= $malmoHs['close'] ? ' checked' : '' ?>> Stäng i Help Scout</label>
        <label class="full">Bara ärenden skapade från och med (öppna ärenden tas alltid med)<input type="date" name="malmo_hs_since" value="<?= e((string) ($malmoHs['since'] ?? '')) ?>"></label>
        <label class="full">Hoppa över dessa Help Scout-nummer, ett per rad<textarea name="malmo_hs_skip" rows="3"><?= e(implode("\n", (array) ($malmoHs['skip'] ?? []))) ?></textarea></label>
        </div>
<?php jeab_inkorg_section_close('Spara Malmö-synk'); ?>

<?php
$rb = jeab_rebook_settings();
$rbLog = jeab_rebook_done();
jeab_inkorg_section_open('ombokning', 'Ombokning', ($rb['enabled'] ? 'På' : 'Av') . $sectionChanged('ombokning'), ['tag' => 'form', 'action' => $post, 'class' => 'regel-form']);
?>
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="inkorg.rebook_save">
        <p class="ink-muted">När en kund med en kommande bokning skriver att tiden inte passar läser Grok mejlet med instruktionen nedan. Med «Bara avsändarens e-post» (på tills den bockas av) agerar ombokningen bara när From är bokningens e-post. Träff på telefon eller bara gatuadress flyttar inget och svarar inte: ärendet lämnas till personalen med anteckning och tilldelning. Är rutan av agerar ombokningen även när telefonnumret i mejlet är bokningens, men en träff som bara är gatuadress lämnas fortfarande till personalen. Föreslår kunden en tid som är ledig enligt bokningsreglerna (vardag 07–17, hel/halvtimme, batteri från 4:e arbetsdagen, Elbilsladdare 2 dagar, sol 14 dagar, Malmö sol mån–tis eller tor–fre, samma personal och längd) flyttas bokningen och bekräftelsen går till bokningens e-post, sedan stängs ärendet. Annars föreslås 2–3 lediga tider, eller kunden ombeds föreslå två egna, och nästa svar prövas igen. Allt syns som anteckning i ärendet och i orderns aktivitet. Osäkert, stängd order eller passerad bokning lämnas öppet till er.</p>
        <div class="ink-plate">
        <label class="full"><input type="checkbox" name="rebook_enabled" value="1"<?= $rb['enabled'] ? ' checked' : '' ?>> Påslagen</label>
        <label class="full"><input type="checkbox" name="rebook_email_only" value="1"<?= !empty($rb['email_only']) ? ' checked' : '' ?>> Bara avsändarens e-post (telefon och adress lämnas till personal)</label>
        <label class="full">Bara dessa avsändare (test, tomt = alla kunder)<input type="text" name="rebook_only_from" value="<?= e(implode(', ', $rb['only_from'])) ?>"></label>
        <label>Lägsta säkerhet från Grok (%)<input type="number" name="rebook_min_confidence" min="50" max="100" value="<?= (int) $rb['min_confidence'] ?>"></label>
        <label class="full">Instruktion till Grok<textarea name="rebook_prompt" rows="8" data-clamp><?= e($rb['prompt']) ?></textarea></label>
        <?php if ($rbLog): ?>
        <div class="full ink-muted"><strong>Senaste prövningar</strong><ul>
        <?php foreach (array_slice(array_reverse($rbLog, true), 0, 10, true) as $rbMid => $rbRow): ?>
            <li><?= e(substr((string) ($rbRow['at'] ?? ''), 0, 16)) ?> · <?= e((string) ($rbRow['action'] ?? '')) ?><?= !empty($rbRow['why']) ? ' · ' . e((string) $rbRow['why']) : '' ?><?= !empty($rbRow['to']) ? ' · ny tid ' . e((string) $rbRow['to']) : '' ?> · <a href="<?= e(jeab_url('index.php?p=inkorg&id=' . rawurlencode((string) ($rbRow['thread'] ?? '')))) ?>">ärendet</a></li>
        <?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
        </div>
<?php jeab_inkorg_section_close('Spara ombokning'); ?>
</div>

<?php jeab_inkorg_section_open('exempel', 'Flaggexempel', count($set['examples']) . ' exempel' . $sectionChanged('exempel')); ?>
<p class="ink-muted">Skräp/Inte skräp-knapparna och varje gång någon ändrar typ eller etiketter i ett ärende sparas här, så att Grok blir bättre. Ta bort ett exempel om det styr fel. De 20 mest relevanta (samma avsändardomän först, sedan de senaste) skickas med till Grok.</p>
<?php if (!$set['examples']): ?><p class="ink-muted">Inga exempel än.</p><?php else: ?>
<ol class="regel-list rg-board">
    <?php foreach (array_reverse($set['examples']) as $ex): ?>
    <li class="rc"><div class="regel-main"><strong class="<?= $ex['label'] === 'skrap' ? 'rg-bad' : 'rg-good' ?>"><?= e(jeab_inkorg_example_label_text((string) $ex['label'])) ?></strong><?= !empty($ex['by']) ? ' · ' . e((string) $ex['by']) : '' ?> · <?= e((string) $ex['from']) ?> · <?= e((string) $ex['subject']) ?><div class="ink-muted"><?= e((string) $ex['snippet']) ?></div></div>
    <div class="regel-actions"><form method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort exemplet?')"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.example_delete"><input type="hidden" name="id" value="<?= e((string) $ex['id']) ?>"><button class="rg-del" type="submit">Ta bort</button></form></div></li>
    <?php endforeach; ?>
</ol>
<?php endif; ?>
<?php jeab_inkorg_section_close(); ?>

<?php jeab_inkorg_section_open('regler', 'Egna regler', count($rules) . ' regler' . $changed('rules.json'), ['force' => $prefill !== null]); ?>
<p class="ink-muted">Körs uppifrån och ned före Grok. Första regeln som matchar vinner. Numret är ordningen.</p>
<?php if (!$rules): ?><p class="rc ink-muted" style="margin:0">Inga egna regler. Grok klassar allt utifrån instruktionen. Lägg bara till en regel om något alltid ska behandlas likadant.</p><?php endif; ?>

<?php if ($prefill === null): ?>
<a class="rg-ghost" href="<?= e(jeab_url('index.php?p=inkorg-regler&ny=1')) ?>#regler">+ Ny regel</a>
<?php else: ?>
<form class="regel-form" method="post" action="<?= $post ?>">
    <div class="ink-plate">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="action" value="inkorg.rule_save">
        <input type="hidden" name="id" value="<?= e((string) ($prefill['id'] ?? '')) ?>">
        <label class="full">Namn<input name="name" value="<?= e((string) ($prefill['name'] ?? '')) ?>" maxlength="80"></label>
        <label class="full">Villkoren
            <select name="match"><option value="all"<?= ($prefill['match'] ?? 'all') === 'all' ? ' selected' : '' ?>>Alla ska stämma (OCH)</option><option value="any"<?= ($prefill['match'] ?? '') === 'any' ? ' selected' : '' ?>>Något ska stämma (ELLER)</option></select>
        </label>
        <?php foreach ($prefill['conditions'] as $c): ?>
        <div class="full regel-cond">
            <select name="c_field[]" aria-label="Villkor"><option value="">(inget villkor)</option><?php foreach ($fields as $k => $v): ?><option value="<?= e($k) ?>"<?= ($c['field'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <input name="c_header[]" value="<?= e((string) ($c['header'] ?? '')) ?>" placeholder="Mejlhuvud, t.ex. List-Unsubscribe" aria-label="Mejlhuvud">
            <select name="c_op[]" aria-label="Jämförelse"><?php foreach ($ops as $k => $v): ?><option value="<?= e($k) ?>"<?= ($c['op'] ?? '') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select>
            <textarea name="c_value[]" rows="2" aria-label="Värde" placeholder="Värde, regex (/…/i) eller list-id (t.ex. hoj-prio)"><?= e((string) ($c['value'] ?? '')) ?></textarea>
        </div>
        <?php endforeach; ?>
        <label>Typ
            <select name="type"><option value=""<?= ($prefill['type'] ?? '') === '' && !empty($prefill['continue']) ? ' selected' : '' ?>>(bara etikett)</option><?php foreach (jeab_inkorg_types() as $g => $ts): if ($g === 'Oklassad') continue; ?><optgroup label="<?= e($g) ?>"><?php foreach ($ts as $t): ?><option<?= ($prefill['type'] ?? '') === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
        </label>
        <label>Säkerhet (under <?= (int) $reviewMin ?> = till granskning)<input name="confidence" type="number" min="0" max="100" value="<?= (int) ($prefill['confidence'] ?? 100) ?>"></label>
        <div class="full ink-row"><span class="ink-muted">Lägg till etikett:</span><?php foreach (jeab_inkorg_tags() as $tid => $tg): ?><label class="regel-check"><span><input type="checkbox" name="add_tags[]" value="<?= e((string) $tid) ?>"<?= in_array($tid, (array) ($prefill['add_tags'] ?? []), true) ? ' checked' : '' ?>> <?= e($tg['name']) ?></span></label><?php endforeach; ?></div>
        <label>Tilldela<select name="assign"><option value="">(ingen)</option><?php foreach (jeab_inkorg_staff() as $pid => $pn): ?><option value="<?= e((string) $pid) ?>"<?= ($prefill['assign'] ?? '') === $pid ? ' selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select></label>
        <label class="regel-check"><span><input type="checkbox" name="continue" value="1"<?= !empty($prefill['continue']) ? ' checked' : '' ?>> Fortsätt till nästa regel</span></label>
        <div class="full ink-row">
            <button class="rg-save" type="submit">Spara regel</button>
            <button class="rg-ghost" type="button" data-rule-test>Testa regel</button>
            <a class="rg-ghost" href="<?= $post ?>">Avbryt</a>
        </div>
        <div class="full" data-rule-result aria-live="polite"></div>
    </div>
</form>
<script>
(function () {
  var b = document.querySelector('[data-rule-test]');
  if (!b) { return; }
  b.addEventListener('click', function () {
    var f = b.form, out = f.querySelector('[data-rule-result]'), fd = new FormData(f);
    fd.set('action', 'inkorg.rule_test');
    out.textContent = 'Testar mot befintliga mejl…';
    var url = f.getAttribute('action') || '';
    fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'format=json', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var row = j.row || {};
        if (j.ok === false || row.count === undefined) { out.textContent = j.error || j.message || 'Kunde inte testa.'; return; }
        out.innerHTML = '';
        var h = document.createElement('p'); h.className = 'ink-muted';
        h.textContent = 'Träffar ' + row.count + ' av ' + row.total + ' mejl. Inget har ändrats.' + (row.count > row.hits.length ? ' Visar de ' + row.hits.length + ' senaste.' : '');
        out.appendChild(h);
        var ul = document.createElement('ul'); ul.className = 'regel-hits';
        row.hits.forEach(function (x) {
          var li = document.createElement('li'), a = document.createElement('a');
          a.href = 'index.php?p=inkorg&id=' + encodeURIComponent(x.thread);
          a.textContent = (x.subject || '(ingen rubrik)') + ' · ' + x.from + ' · nu: ' + x.type;
          li.appendChild(a); ul.appendChild(li);
        });
        out.appendChild(ul);
      })
      .catch(function () { out.textContent = 'Kunde inte testa.'; });
  });
})();
</script>
<?php endif; ?>

<ol class="regel-list">
    <?php foreach ($rules as $i => $r): ?>
    <li class="rc<?= empty($r['enabled']) ? ' is-off' : '' ?>" data-rule="<?= e((string) $r['id']) ?>" data-conds="<?= e((string) json_encode(['match' => $r['match'] ?? 'all', 'conditions' => $r['conditions'] ?? []], JSON_UNESCAPED_UNICODE)) ?>">
        <div class="rc-t">
            <span class="regel-nr">#<?= $i + 1 ?></span>
            <b class="rc-name"><?= !empty($r['builtin']) ? '<span class="ink-label">Inbyggd</span> ' : '' ?><?= !empty($r['lead_sender']) ? '<span class="ink-label">Leadavsändare</span> ' : '' ?><?= e((string) $r['name']) ?></b>
            <span class="regel-actions">
                <button class="rg-ghost" type="button" data-rule-run>Testa mot mejl</button>
                <a class="rg-ghost" href="<?= e(jeab_url('index.php?p=inkorg-regler&edit=' . rawurlencode((string) $r['id']))) ?>#regler">Redigera</a>
                <?php foreach ([[-1, '↑', 'Flytta upp'], [1, '↓', 'Flytta ned']] as [$dir, $sym, $lbl]): ?>
                <form method="post" action="<?= $post ?>"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.rule_move"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>"><button class="rg-ghost" type="submit" aria-label="<?= e($lbl) ?>"><?= $sym ?></button></form>
                <?php endforeach; ?>
                <form method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort regeln?')"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.rule_delete"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><button class="rg-del" type="submit">Ta bort</button></form>
                <form method="post" action="<?= $post ?>"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.rule_toggle"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><button class="rg-tog<?= empty($r['enabled']) ? ' is-off' : '' ?>" type="submit" role="switch" aria-checked="<?= empty($r['enabled']) ? 'false' : 'true' ?>" aria-label="<?= empty($r['enabled']) ? 'Slå på' : 'Stäng av' ?>" title="På/av"></button></form>
            </span>
        </div>
        <div class="regel-desc rc-sent"><?= e($desc($r)) ?></div>
        <div class="rc-test" data-rule-out aria-live="polite" hidden></div>
    </li>
    <?php endforeach; ?>
</ol>
<script>
document.querySelectorAll('[data-rule-run]').forEach(function (b) {
  b.addEventListener('click', function () {
    var li = b.closest('.rc'), out = li.querySelector('[data-rule-out]'), d = JSON.parse(li.dataset.conds || '{}'), fd = new FormData();
    if (!out.hidden && out.dataset.done) { out.hidden = true; out.dataset.done = ''; return; }
    fd.set('_csrf', (document.querySelector('input[name=_csrf]') || {}).value || '');
    fd.set('action', 'inkorg.rule_test'); fd.set('match', d.match || 'all');
    (d.conditions || []).forEach(function (c) { fd.append('c_field[]', c.field || ''); fd.append('c_header[]', c.header || ''); fd.append('c_op[]', c.op || ''); fd.append('c_value[]', c.value || ''); });
    out.hidden = false; out.textContent = 'Testar mot befintliga mejl…';
    fetch('<?= $post ?>'.replace(/&amp;/g, '&') + '&format=json', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var row = j.row || {}; out.dataset.done = '1';
        if (j.ok === false || row.count === undefined) { out.textContent = j.error || j.message || 'Kunde inte testa.'; return; }
        out.innerHTML = '';
        var h = document.createElement('b'); h.textContent = 'Träffar ' + row.count + ' av ' + row.total + ' mejl'; out.appendChild(h);
        row.hits.forEach(function (x) { var a = document.createElement('a'); a.href = 'index.php?p=inkorg&id=' + encodeURIComponent(x.thread); a.textContent = '· ' + (x.subject || '(ingen rubrik)') + ' – ' + x.from + ' (nu: ' + x.type + ')'; out.appendChild(a); });
        var n = document.createElement('div'); n.className = 'ink-muted'; n.textContent = 'Inget ändras förrän du sparar regeln.'; out.appendChild(n);
      })
      .catch(function () { out.textContent = 'Kunde inte testa.'; });
  });
});
</script>

<?php jeab_inkorg_section_close(); ?>
<?php jeab_inkorg_section_open('listor', 'Listor', count($lists) . ' listor' . $changed('rules.json'), ['force' => (string) ($_GET['lista'] ?? '') !== '']); ?>
<p class="ink-muted">Ord och orter som reglerna använder. Ett ord per rad; en rad som börjar med / är en regex.</p>
<?php $editList = (string) ($_GET['lista'] ?? ''); ?>
<ul class="regel-list rg-board">
    <?php foreach ($lists + ($editList === 'ny' ? ['' => ['name' => '', 'entries' => []]] : []) as $lid => $list): ?>
    <li class="regel">
        <?php if ($editList === (string) $lid || ($editList === 'ny' && $lid === '')): ?>
        <form class="regel-form full" method="post" action="<?= $post ?>" style="flex:1">
            <div class="ink-plate">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="inkorg.list_save">
                <label>Id<input name="list_id" value="<?= e((string) $lid) ?>"<?= $lid !== '' ? ' readonly' : '' ?>></label>
                <label>Namn<input name="list_name" value="<?= e((string) ($list['name'] ?? '')) ?>"></label>
                <label class="full">Ord (ett per rad)<textarea name="entries" rows="10" data-clamp><?= e(implode("\n", (array) ($list['entries'] ?? []))) ?></textarea></label>
                <div class="full ink-row"><button class="rg-save" type="submit">Spara lista</button><a class="rg-ghost" href="<?= $post ?>#listor">Avbryt</a></div>
            </div>
        </form>
        <?php else: ?>
        <span class="regel-main">
            <span class="regel-name"><?= e((string) ($list['name'] ?? $lid)) ?></span>
            <span class="regel-desc"><?= e((string) $lid) ?> · <?= count((array) ($list['entries'] ?? [])) ?> rader: <?= e(mb_substr(implode(', ', (array) ($list['entries'] ?? [])), 0, 120)) ?>…</span>
        </span>
        <span class="regel-actions">
            <a class="rg-ghost" href="<?= e(jeab_url('index.php?p=inkorg-regler&lista=' . rawurlencode((string) $lid) . '#listor')) ?>">Ändra</a>
            <form method="post" action="<?= $post ?>" onsubmit="return confirm('Ta bort listan?')"><?= jeab_csrf_field() ?><input type="hidden" name="action" value="inkorg.list_delete"><input type="hidden" name="list_id" value="<?= e((string) $lid) ?>"><button class="rg-del" type="submit">Ta bort</button></form>
        </span>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
<a class="rg-ghost" href="<?= e(jeab_url('index.php?p=inkorg-regler&lista=ny#listor')) ?>">Ny lista</a>
<?php jeab_inkorg_section_close(); ?>
<?php jeab_inkorg_section_script(); ?>
</div>
</div>
<?php
return ob_get_clean();
