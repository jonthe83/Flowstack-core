<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/** Enda testmottagaren. Samma adress som inbox.quote_test. */
function jeab_offert_utskick_test_to(): string
{
    return 'demo@example.test';
}

function jeab_offert_utskick_allowed(): bool
{
    return function_exists('jeab_is_admin') && jeab_is_admin();
}

function jeab_offert_utskick_days(array $in): int
{
    $days = (int) ($in['days'] ?? 30);
    if ($days < 1) {
        $days = 30;
    }
    if ($days > 365) {
        $days = 365;
    }

    return $days;
}

/**
 * Samma funktioner som hookarna. Mottagare, dry och limit kommer inte från formuläret.
 *
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function jeab_offert_utskick_act(array $post): array
{
    $action = (string) ($post['action'] ?? '');
    $days = jeab_offert_utskick_days($post);
    if ($action === 'offert.enable') {
        $res = jeab_inkorg_enable_auto_quote(['dry' => 0]);
        $res['kind'] = 'enable';

        return $res;
    }
    if ($action === 'offert.test_preview') {
        $res = jeab_inkorg_quote_test(['dry' => 1]);
        $res['kind'] = 'test';

        return $res;
    }
    if ($action === 'offert.test_send') {
        $res = jeab_inkorg_quote_test(['dry' => 0, 'to' => jeab_offert_utskick_test_to()]);
        $res['kind'] = 'test';

        return $res;
    }
    if ($action === 'offert.backfill_preview') {
        $res = jeab_inkorg_quote_backfill(['dry' => 1, 'days' => $days, 'limit' => 25]);
        $res['kind'] = 'backfill';

        return $res;
    }
    if ($action === 'offert.backfill_send') {
        $res = jeab_inkorg_quote_backfill(['dry' => 0, 'days' => $days, 'limit' => 25]);
        $res['kind'] = 'backfill';

        return $res;
    }

    return ['ok' => false, 'error' => 'okänd åtgärd', 'sent' => 0, 'would_send' => false, 'kind' => '', 'rows' => []];
}

function jeab_offert_utskick_mall_label(array $row): string
{
    $product = (string) ($row['product'] ?? '');
    $mall = (string) ($row['mall'] ?? '');
    if ($product === 'Sol' || $product === 'Sol+batteri') {
        if ($mall === 'A') {
            return 'Sol köpt';
        }
        if ($mall === 'B') {
            return 'Sol eget';
        }

        return '';
    }

    return $mall === 'A' ? 'Sol köpt' : ($mall === 'B' ? 'Sol eget' : '');
}

function jeab_offert_utskick_outcome(array $row): string
{
    $action = (string) ($row['action'] ?? '');
    $why = trim((string) ($row['why'] ?? ''));
    $missing = trim((string) ($row['missing'] ?? ''));
    $at = trim((string) ($row['mailed_at'] ?? ''));
    if ($action === 'redan_mailad') {
        return 'redan mailad' . ($at !== '' ? ' ' . $at : '');
    }
    if ($action === 'would_send') {
        return 'skulle skickas';
    }
    if ($action === 'sent') {
        return 'skickad';
    }
    if ($action === 'would_hold' || $action === 'hold' || $action === 'skip' || $action === 'redan_offert' || $action === 'history_unread' || $action === 'tak') {
        return $why !== '' ? $why : $action;
    }
    if ($action === 'missing' || $action === 'refused') {
        return $missing !== '' ? $missing : $action;
    }

    return $why !== '' ? $why : $action;
}

function jeab_offert_utskick_safe(string $text): string
{
    $text = function_exists('jeab_auto_mask_text') ? jeab_auto_mask_text($text) : $text;

    return e($text);
}

function jeab_utskick_normalize_flik(string $flik): string
{
    $flik = strtolower(trim($flik));

    return in_array($flik, ['alla', 'notiser', 'offerter'], true) ? $flik : 'alla';
}

/** Gamla GET p=notiser och p=offertutskick. Filhämtning och skalets partial för notiser stannar. */
function jeab_utskick_should_redirect(string $page): bool
{
    if ($page !== 'notiser' && $page !== 'offertutskick') {
        return false;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }
    if ($page === 'notiser' && trim((string) ($_GET['fil'] ?? '')) !== '') {
        return false;
    }
    if ($page === 'notiser' && function_exists('jeab_shell_wants_partial') && jeab_shell_wants_partial()) {
        return false;
    }

    return true;
}

function jeab_utskick_legacy_target(string $page): string
{
    $flik = $page === 'offertutskick' ? 'offerter' : 'notiser';
    $q = ['p' => 'utskick', 'flik' => $flik];
    foreach (['co', 'ch', 'st', 'pr', 'flash'] as $key) {
        $value = trim((string) ($_GET[$key] ?? ''));
        if ($value !== '') {
            $q[$key] = $value;
        }
    }

    return jeab_url('index.php?' . http_build_query($q));
}

/**
 * @param array<string, mixed>|null $result
 * @param array<string, mixed> $post
 */
function jeab_utskick_body(string $flik, ?array $result = null, array $post = []): string
{
    $started = microtime(true);
    $flik = jeab_utskick_normalize_flik($flik);
    $offerAllowed = jeab_offert_utskick_allowed();
    $offerDenied = $flik === 'offerter' && !$offerAllowed;
    $rows = jeab_notiser_catalog();
    $filter = jeab_notiser_filter_from_request();
    $filter['flik'] = $flik;
    $visible = jeab_notiser_filter($rows, $filter);
    $counts = jeab_notiser_counts($rows);
    $showNotiser = $flik !== 'offerter';
    $showOffer = $flik !== 'notiser' && $offerAllowed;
    $offerResult = $result;
    $offerPost = $post;

    $html = (string) require __DIR__ . '/pages/notiser.php';
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('utskick', microtime(true) - $started);
    }

    return $html;
}

/**
 * @param array<string, mixed>|null $result
 * @param array<string, mixed> $post
 */
function jeab_utskick_render(string $flik, ?array $result, array $post, string $layoutPage = 'utskick'): never
{
    $body = jeab_utskick_body($flik, $result, $post);
    if (!function_exists('jeab_layout')) {
        require_once __DIR__ . '/layout.php';
    }
    jeab_layout('Utskick · JEAB', $layoutPage, $body);
    exit;
}

/** @param array<string, mixed> $post */
function jeab_utskick_handle_offer_post(): never
{
    $answered = jeab_offert_utskick_respond('POST', $_POST);
    if ($answered['code'] !== 200) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        http_response_code($answered['code']);
        echo $answered['html'];
        exit;
    }
    $result = is_array($answered['result']) ? $answered['result'] : null;
    jeab_utskick_render('offerter', $result, $_POST);
}

/**
 * @param array<string, mixed>|null $result
 * @param array<string, mixed> $post
 */
function jeab_offert_utskick_html(?array $result, array $post = []): string
{
    $on = function_exists('jeab_auto_settings') && !empty(jeab_auto_settings()['quote_enabled']);
    $days = jeab_offert_utskick_days($post);
    $kind = (string) ($result['kind'] ?? '');
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $would = 0;
    foreach ($rows as $row) {
        if (is_array($row) && (string) ($row['action'] ?? '') === 'would_send') {
            $would++;
        }
    }
    $previewed = $kind === 'backfill' && (int) ($result['dry'] ?? 0) === 1;
    $sendConfirm = $previewed
        ? 'Skicka ' . $would . ' offerter till kunder? Högst 25 per klick. En rad som redan är mailad eller redan har offert hoppas över.'
        : 'Skicka offerter bakåt i tiden, högst 25, för de dagar som står i fältet? Det mejlar kunder.';
    $notice = '';
    $error = trim((string) ($result['error'] ?? ''));
    if ($kind === 'enable' && !empty($result['ok'])) {
        $notice = !empty($result['changed'])
            ? 'Skicka offerter automatiskt är på. Inget mejl skickades.'
            : 'Den var redan på. Inget mejl skickades.';
    } elseif ($kind === 'test' && (int) ($result['dry'] ?? 0) === 1) {
        $notice = 'Inget skickades. Tabellen visar vad en testkopia skulle innehålla.';
    } elseif ($kind === 'test' && !empty($result['ok'])) {
        $notice = 'Skickade ' . (int) ($result['sent'] ?? 0) . ' testkopior till ' . jeab_offert_utskick_test_to() . '. Inget ärende ändrades.';
    } elseif ($kind === 'backfill' && (int) ($result['dry'] ?? 0) === 1) {
        $notice = 'Inget skickades. ' . $would . ' skulle skickas.';
    } elseif ($kind === 'backfill' && !empty($result['ok'])) {
        $notice = 'Klart. ' . (int) ($result['sent'] ?? 0) . ' offerter skickades.';
    } elseif ($error !== '') {
        $notice = '';
    }
    if ($notice !== '' && function_exists('jeab_demo_mode') && jeab_demo_mode() && in_array($kind, ['test', 'backfill'], true)) {
        $notice = 'Inga utskick i demon. Inget mejl lämnade servern.';
    }
    $self = function_exists('jeab_url') ? jeab_url('index.php?p=utskick&flik=offerter') : 'index.php?p=utskick&flik=offerter';
    $csrf = function_exists('jeab_csrf_field') ? jeab_csrf_field() : '';
    $html = '<section class="notis-offer">';
    $html .= '<h2 class="notis-section">Offerter</h2>';
    $html .= '<p class="notis-lead">Skicka offerter automatiskt är <strong>' . ($on ? 'på' : 'av') . '</strong>. Inget kundmejl går utan ett klick.</p>';
    if ($notice !== '') {
        $html .= '<p class="notis-audit">' . jeab_offert_utskick_safe($notice) . '</p>';
    }
    if ($error !== '') {
        $html .= '<p class="notis-problem">' . jeab_offert_utskick_safe($error) . '</p>';
    }
    if (!$on) {
        $html .= '<form method="post" action="' . e($self) . '" onsubmit="return confirm(\'Slå på Skicka offerter automatiskt? Befintliga ärenden får inget mejl av det här klicket.\')">';
        $html .= $csrf . '<input type="hidden" name="action" value="offert.enable"><button class="btn" type="submit">Slå på</button></form>';
    }
    $html .= '<h2 class="notis-section">Testa mallar</h2>';
    $html .= '<form method="post" action="' . e($self) . '">' . $csrf;
    $html .= '<input type="hidden" name="action" value="offert.test_preview"><button class="btn" type="submit">Testa mallar (visa)</button></form>';
    $html .= '<form method="post" action="' . e($self) . '" onsubmit="return confirm(\'Skicka en testkopia av varje komplett mall till ' . e(jeab_offert_utskick_test_to()) . '? Inget mejl går till en kund.\')">';
    $html .= $csrf . '<input type="hidden" name="action" value="offert.test_send"><button class="btn" type="submit">Skicka testkopia till ' . e(jeab_offert_utskick_test_to()) . '</button></form>';
    if ($kind === 'test') {
        $html .= '<div class="notis-table-wrap"><table class="notis-table"><tr><th>Mall</th><th>Filer</th><th>Saknas</th><th>Resultat</th></tr>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $files = [];
            foreach ((array) ($row['files'] ?? []) as $file) {
                $files[] = (string) $file;
            }
            $html .= '<tr><td>' . jeab_offert_utskick_safe((string) ($row['name'] ?? '')) . '</td><td>'
                . jeab_offert_utskick_safe(implode(', ', $files)) . '</td><td>'
                . jeab_offert_utskick_safe((string) ($row['missing'] ?? '')) . '</td><td>'
                . jeab_offert_utskick_safe(jeab_offert_utskick_outcome($row)) . '</td></tr>';
        }
        $html .= '</table></div>';
    }
    $html .= '<h2 class="notis-section">Utskick bakåt</h2>';
    $html .= '<div class="notis-filters" role="navigation" aria-label="Läge och ort">';
    foreach (['' => 'Alla lägen', 'would_send' => 'Skulle skickas', 'sent' => 'Skickad', 'redan_mailad' => 'Redan mailad', 'ovrigt' => 'Övrigt'] as $value => $label) {
        $onChip = $value === '';
        $html .= '<button type="button" class="chip' . ($onChip ? ' chip-primary is-on' : '') . '" data-offer-st="' . e($value) . '">' . e($label) . '</button>';
    }
    $html .= '<button type="button" class="chip chip-primary is-on" data-offer-ort="">Alla orter</button>';
    $html .= '<button type="button" class="chip" data-offer-ort="jarfalla">Järfälla</button>';
    $html .= '<button type="button" class="chip" data-offer-ort="malmo">Malmö</button>';
    $seenOrt = ['järfälla' => true, 'jarfalla' => true, 'malmö' => true, 'malmo' => true];
    foreach ($rows as $row) {
        if (!is_array($row) || $kind !== 'backfill') {
            continue;
        }
        $ort = trim((string) ($row['ort'] ?? ''));
        $key = function_exists('mb_strtolower') ? mb_strtolower($ort) : strtolower($ort);
        if ($ort === '' || isset($seenOrt[$key])) {
            continue;
        }
        $seenOrt[$key] = true;
        $html .= '<button type="button" class="chip" data-offer-ort="' . e($key) . '">' . jeab_offert_utskick_safe($ort) . '</button>';
    }
    $html .= '</div>';
    $html .= '<form method="post" action="' . e($self) . '">' . $csrf;
    $html .= '<label>Dagar <input name="days" type="number" min="1" max="365" value="' . $days . '"></label>';
    $html .= '<button class="btn" type="submit" name="action" value="offert.backfill_preview">Förhandsgranska utskick bakåt</button>';
    $html .= '<button class="btn" type="submit" name="action" value="offert.backfill_send" onclick="return confirm(' . htmlspecialchars(json_encode($sendConfirm), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')">Skicka offerter till dessa</button>';
    $html .= '</form>';
    if ($kind === 'backfill') {
        $html .= '<div class="notis-table-wrap"><table class="notis-table" data-offer-table><tr><th>Id</th><th>Kund</th><th>Produkt</th><th>Ort</th><th>Mall</th><th>Läge</th></tr>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $action = (string) ($row['action'] ?? '');
            $bucket = in_array($action, ['would_send', 'sent', 'redan_mailad'], true) ? $action : 'ovrigt';
            $ort = trim((string) ($row['ort'] ?? ''));
            $html .= '<tr data-action="' . e($bucket) . '" data-ort="' . e($ort) . '"><td>' . jeab_offert_utskick_safe((string) ($row['id'] ?? '')) . '</td><td>'
                . jeab_offert_utskick_safe((string) ($row['name'] ?? '')) . '</td><td>'
                . jeab_offert_utskick_safe((string) ($row['product'] ?? '')) . '</td><td>'
                . jeab_offert_utskick_safe($ort) . '</td><td>'
                . jeab_offert_utskick_safe(jeab_offert_utskick_mall_label($row)) . '</td><td>'
                . jeab_offert_utskick_safe((string) ($row['status'] ?? '') . ' · ' . jeab_offert_utskick_outcome($row)) . '</td></tr>';
        }
        $html .= '</table></div>';
        $html .= '<script>(function(){var table=document.querySelector("[data-offer-table]");if(!table){return;}var st="";var ort="";function norm(v){return String(v||"").toLowerCase();}function ortOk(row){if(ort===""){return true;}var city=norm(row.getAttribute("data-ort"));if(ort==="jarfalla"){return city.indexOf("järfälla")!==-1||city.indexOf("jarfalla")!==-1;}if(ort==="malmo"){return city.indexOf("malmö")!==-1||city.indexOf("malmo")!==-1;}return city===ort;}function paint(){var rows=table.querySelectorAll("tbody tr");if(!rows.length){rows=table.querySelectorAll("tr[data-action]");}for(var i=0;i<rows.length;i++){var row=rows[i];var okSt=st===""||row.getAttribute("data-action")===st;row.hidden=!(okSt&&ortOk(row));}}function bind(attr){var nodes=document.querySelectorAll("["+attr+"]");for(var i=0;i<nodes.length;i++){nodes[i].addEventListener("click",function(){var group=this.hasAttribute("data-offer-st")?"st":"ort";var name=group==="st"?"data-offer-st":"data-offer-ort";var value=this.getAttribute(name)||"";if(group==="st"){st=value;}else{ort=value;}var all=document.querySelectorAll("["+name+"]");for(var j=0;j<all.length;j++){var on=(all[j].getAttribute(name)||"")===value;all[j].classList.toggle("is-on",on);all[j].classList.toggle("chip-primary",on);}paint();});}}bind("data-offer-st");bind("data-offer-ort");})();</script>';
    }
    $html .= '</section>';

    return $html;
}

/**
 * @param array<string, mixed> $post
 * @return array{code:int,html:string,result:array<string,mixed>|null}
 */
function jeab_offert_utskick_respond(string $method, array $post): array
{
    if (!jeab_offert_utskick_allowed()) {
        return ['code' => 403, 'html' => 'Bara admin.', 'result' => null];
    }
    $method = strtoupper($method);
    if ($method === 'POST') {
        $token = (string) ($post['_csrf'] ?? '');
        if (!function_exists('jeab_csrf_ok') || !jeab_csrf_ok($token)) {
            return ['code' => 419, 'html' => 'Ogiltig session. Ladda om sidan.', 'result' => null];
        }
        $result = jeab_offert_utskick_act($post);

        return ['code' => 200, 'html' => jeab_offert_utskick_html($result, $post), 'result' => $result];
    }

    return ['code' => 200, 'html' => jeab_offert_utskick_html(null, $post), 'result' => null];
}

/** POST p=offertutskick. GET styrs om från index.php till fliken Offerter. */
function jeab_offert_utskick_page(): void
{
    if (strtolower((string) ($_GET['p'] ?? '')) !== 'offertutskick') {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    jeab_utskick_handle_offer_post();
}
