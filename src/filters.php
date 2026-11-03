<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Personal, produkter och inloggningsförval för Kalender, Påminnelser och Ordrar.
 * En ändring här slår igenom på alla tre. Personlistan kommer från personregistret.
 */

function jeab_filter_staff(?string $company = null): array
{
    return jeab_company_names($company);
}

function jeab_filter_products(): array
{
    return JEAB_JOB_TYPES;
}

/**
 * Produkt som är förvald tillsammans med personens namn.
 * Tom sträng = Alla (inget namn, ingen produkt), samma läge som kalendern utan query.
 * Id som saknas faller tillbaka på personens job i registret.
 *
 * @return array<string, string>
 */
function jeab_filter_login_rules(): array
{
    return [
        'Elin' => 'laddbox',
        'Roberto' => 'batteri',
        'Niman' => 'batteri',
        'SamL' => '',
        'SamW' => '',
    ];
}

function jeab_filter_rule_job(array $person): ?string
{
    $id = (string) ($person['id'] ?? '');
    $rules = jeab_filter_login_rules();
    if (array_key_exists($id, $rules)) {
        return jeab_normalize_job_key($rules[$id]);
    }
    return jeab_normalize_job_key($person['job'] ?? null);
}

/**
 * Inloggningens förval för de delade filterpillren.
 * Kalendern (standard): namn + produkt bara för personer med produktregel, annars Alla.
 * $whoOnly (Ordrar): personer utan produktregel (t.ex. Sam L) får sitt eget namn
 * och Alla produkter. Ingen koppling inloggning -> person = Alla.
 *
 * @return array{who:?string,job:?string}
 */
function jeab_filter_login_preset(?string $company = null, bool $whoOnly = false): array
{
    $empty = ['who' => null, 'job' => null];
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $person = function_exists('jeab_who_me_person') ? jeab_who_me_person() : (function_exists('jeab_auth_user_person') ? jeab_auth_user_person() : null);
    if (!is_array($person) || ($person['company'] ?? '') !== $company) {
        return $empty;
    }
    $name = jeab_resolve_person_name((string) ($person['name'] ?? ''), $company);
    if ($name === null) {
        return $empty;
    }
    $job = jeab_filter_rule_job($person);
    if ($job === null) {
        return $whoOnly ? ['who' => $name, 'job' => null] : $empty;
    }
    return ['who' => $name, 'job' => $job];
}

function jeab_filter_is_all_token(string $value): bool
{
    $value = trim($value);
    return $value === '' || strcasecmp($value, 'all') === 0;
}

/**
 * Query vinner över förvalet. Saknas både who och job används inloggningsförvalet.
 * who=all eller job=all är ett medvetet val och slår inte på förvalet igen.
 *
 * @return array{who:?string,job:?string,explicit:bool}
 */
function jeab_filter_from_request(?string $company = null, bool $whoOnly = false): array
{
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $hasWho = array_key_exists('who', $_GET);
    $hasJob = array_key_exists('job', $_GET);
    if ($whoOnly && ($hasWho || $hasJob) && !jeab_filter_ordrar_choice_live($company)) {
        // Gammal who= (bokmärke, öppen flik) faller tillbaka till förvalet.
        // job=sol|batteri|laddbox gäller ändå, så ?p=ordrar&job=sol filtrerar.
        $jobRaw = $hasJob ? trim((string) $_GET['job']) : '';
        $jobSticks = $jobRaw !== ''
            && !jeab_filter_is_all_token($jobRaw)
            && jeab_normalize_job_key($jobRaw) !== null;
        $hasWho = false;
        if (!$jobSticks) {
            $hasJob = false;
            $GLOBALS['_JEAB_WHO_SRC'] = 'url-stale-ignored';
        } else {
            $GLOBALS['_JEAB_WHO_SRC'] = 'url-job';
        }
    } elseif ($whoOnly) {
        $GLOBALS['_JEAB_WHO_SRC'] = ($hasWho || $hasJob) ? 'url' : 'default';
    }
    if (!$hasWho && !$hasJob) {
        $preset = jeab_filter_login_preset($company, $whoOnly);
        if ($whoOnly && function_exists('jeab_who_diag_note')) {
            jeab_who_diag_note('ordrar', $preset['who'], ($GLOBALS['_JEAB_WHO_SRC'] ?? '') . '>default:' . (function_exists('jeab_who_me_source') ? jeab_who_me_source() : '?'));
        }
        return [
            'who' => $preset['who'],
            'job' => $preset['job'],
            'explicit' => false,
        ];
    }
    $who = null;
    $job = null;
    if ($hasWho) {
        $raw = trim((string) $_GET['who']);
        if (!jeab_filter_is_all_token($raw) && !(function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($raw))) {
            $who = jeab_resolve_person_name($raw, $company);
            if ($who !== null && function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($who)) {
                $who = null;
                $GLOBALS['_JEAB_WHO_SRC'] = 'other-sam';
            }
            if ($who === null && function_exists('jeab_who_me_name') && strcasecmp(jeab_who_me_name(), $raw) === 0) {
                $who = jeab_who_me_name();
            }
        } elseif (function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($raw)) {
            $GLOBALS['_JEAB_WHO_SRC'] = 'other-sam';
        }
    }
    if ($hasJob) {
        $raw = trim((string) $_GET['job']);
        if (!jeab_filter_is_all_token($raw)) {
            $job = jeab_normalize_job_key($raw);
        }
    }
    if ($whoOnly && function_exists('jeab_who_diag_note')) {
        jeab_who_diag_note('ordrar', $who, (string) ($GLOBALS['_JEAB_WHO_SRC'] ?? 'url'));
    }
    return ['who' => $who, 'job' => $job, 'explicit' => true];
}

/**
 * Ordrar: who/job i adressen gäller om de klickats nyss (ws=), redan används i sessionen
 * eller är samma som förvalet. Annars är adressen gammal och förvalet gäller.
 */
function jeab_filter_ordrar_choice_live(string $company): bool
{
    $sig = strtolower(trim((string) ($_GET['who'] ?? ''))) . '|' . strtolower(trim((string) ($_GET['job'] ?? '')));
    $session = session_status() === PHP_SESSION_ACTIVE;
    // Efter session_write_close ligger valet kvar i $_SESSION men status är inte ACTIVE.
    $canRead = $session || !empty($GLOBALS['_JEAB_SESSION_RELEASED']);
    $saved = null;
    if ($canRead && isset($_SESSION['jeab_ordrar_filter']) && is_array($_SESSION['jeab_ordrar_filter'])) {
        $saved = $_SESSION['jeab_ordrar_filter'][$company] ?? null;
    }
    $savedOk = is_array($saved) && ($saved['sig'] ?? '') === $sig
        && time() - (int) ($saved['at'] ?? 0) <= (defined('JEAB_WHO_URL_TTL') ? JEAB_WHO_URL_TTL : 1800);
    $preset = jeab_filter_login_preset($company, true);
    $presetWho = strtolower((string) ($preset['who'] ?? ''));
    $qWhoRaw = trim((string) ($_GET['who'] ?? ''));
    if ($qWhoRaw !== '' && function_exists('jeab_who_is_other_sam') && jeab_who_is_other_sam($qWhoRaw)) {
        if ($session) {
            unset($_SESSION['jeab_ordrar_filter'][$company]);
        }
        return false;
    }
    $qWho = strtolower($qWhoRaw);
    $qJob = strtolower(trim((string) ($_GET['job'] ?? '')));
    $isPreset = $presetWho !== '' && $qWho === $presetWho
        && ($qJob === strtolower((string) ($preset['job'] ?? '')) || ($preset['job'] === null && in_array($qJob, ['', 'all'], true)));
    $fresh = function_exists('jeab_who_url_fresh') && jeab_who_url_fresh();
    $loginWho = function_exists('jeab_who_me_name') ? strtolower(jeab_who_me_name()) : '';
    $isLogin = $loginWho !== '' && $qWho === $loginWho;
    $live = $fresh || $savedOk || $isPreset || $isLogin;
    if ($session) {
        if ($live) {
            $_SESSION['jeab_ordrar_filter'][$company] = ['sig' => $sig, 'at' => time()];
        } else {
            unset($_SESSION['jeab_ordrar_filter'][$company]);
        }
    }
    return $live;
}

/** @return array{who:string,job:string} */
function jeab_filter_url_params(?string $who, ?string $job): array
{
    $state = jeab_filter_from_request();
    if (!$state['explicit'] && $who === null && $job === null) {
        return [];
    }
    return [
        'who' => ($who !== null && $who !== '') ? $who : 'all',
        'job' => ($job !== null && $job !== '') ? $job : 'all',
    ];
}

function jeab_filter_query_value(mixed $value, string $kind): string
{
    $value = trim((string) $value);
    if (jeab_filter_is_all_token($value)) {
        return 'all';
    }
    if ($kind === 'who') {
        return jeab_resolve_person_name($value, jeab_selected_company()) ?? 'all';
    }
    return jeab_normalize_job_key($value) ?? 'all';
}

/**
 * Personer som ett produktfilter på Ordrar ska träffa när ordern inte har en egen tilldelning.
 * Primär bokare (jeab_ordrar_book_who) plus alla vars förval är samma produkt.
 *
 * @return list<string>
 */
function jeab_filter_crew(string $job, ?string $company = null): array
{
    $job = jeab_normalize_job_key($job);
    if ($job === null) {
        return [];
    }
    $company = jeab_normalize_company($company);
    $names = [];
    $add = static function (?string $name) use (&$names): void {
        $name = trim((string) $name);
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    };
    if ($company !== null && function_exists('jeab_ordrar_book_who')) {
        $primary = jeab_ordrar_book_who($job, $company);
        if ($primary !== '') {
            $add(jeab_resolve_person_name($primary, $company));
        }
    }
    foreach (jeab_people() as $person) {
        if ($company !== null && ($person['company'] ?? '') !== $company) {
            continue;
        }
        if (jeab_filter_rule_job($person) !== $job) {
            continue;
        }
        $add(jeab_resolve_person_name((string) ($person['name'] ?? ''), (string) ($person['company'] ?? '')));
    }
    return $names;
}

/** @return list<string> */
function jeab_order_explicit_workers(array $row, ?array $booking = null): array
{
    $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    $names = [];
    $add = static function (array $found) use (&$names): void {
        foreach ($found as $name) {
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    };
    if (is_array($booking)) {
        $add(jeab_assignees_from_row($booking));
    } elseif (function_exists('jeab_ordrar_linked_booking')) {
        $linked = jeab_ordrar_linked_booking($row);
        if (is_array($linked)) {
            $add(jeab_assignees_from_row($linked));
        }
    }
    foreach (['assignees', 'assignee', 'person', 'who', 'worker'] as $key) {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            continue;
        }
        $add(jeab_parse_assignees($row[$key], $company));
    }
    return $names;
}

function jeab_order_job(array $row): ?string
{
    $kind = '';
    if (function_exists('jeab_ordrar_ui_kind')) {
        $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    }
    if ($kind !== '' && isset(JEAB_JOB_TYPES[$kind])) {
        return $kind;
    }
    foreach (['job', 'job_type', 'vad', 'produkt', 'product'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $norm = jeab_normalize_job_key($row[$key]);
        if ($norm !== null) {
            return $norm;
        }
    }
    if (function_exists('jeab_ordrar_board_product')) {
        return jeab_normalize_job_key(jeab_ordrar_board_product($row));
    }
    return null;
}

/** @return list<string> */
function jeab_order_workers(array $row, ?array $booking = null): array
{
    $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    $names = jeab_order_explicit_workers($row, $booking);
    $job = jeab_order_job($row);
    if ($job !== null) {
        foreach (jeab_filter_crew($job, $company) as $name) {
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    }
    if ($names === [] && $job !== null && function_exists('jeab_ordrar_book_who')) {
        $fallback = jeab_resolve_person_name(jeab_ordrar_book_who($job, $company), $company);
        if ($fallback !== null) {
            $names[] = $fallback;
        }
    }
    return $names;
}

function jeab_order_matches_filter(array $row, ?string $who, ?string $job, ?array $booking = null): bool
{
    if ($who !== null) {
        $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
        $want = jeab_resolve_person_name($who, $company) ?? $who;
        $hit = false;
        foreach (jeab_order_workers($row, $booking) as $name) {
            if (strcasecmp($name, $want) === 0) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }
    if ($job !== null && jeab_order_job($row) !== $job) {
        return false;
    }
    return true;
}

function jeab_filter_orders(array $rows, ?string $who, ?string $job): array
{
    if ($who === null && $job === null) {
        return array_values($rows);
    }
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row) && jeab_order_matches_filter($row, $who, $job)) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * @param callable(array<string, string>): string $href
 */
function jeab_filter_chips_html(callable $href, ?string $who, ?string $job, ?string $company = null, string $which = 'all'): string
{
    $html = '';
    if ($which !== 'job') {
        $html .= '<a class="chip' . ($who === null && $job === null ? ' is-on' : '') . '" href="'
            . e($href(['who' => 'all', 'job' => 'all'])) . '">Alla</a>';
        foreach (jeab_filter_staff($company) as $name) {
            $on = $who !== null && strcasecmp($who, $name) === 0;
            $params = jeab_calendar_filter_next($who, $job, 'who', $name);
            $html .= '<a class="chip' . ($on ? ' is-on' : '') . '" href="' . e($href($params)) . '">' . e($name) . '</a>';
        }
    }
    if ($which !== 'staff') {
        foreach (jeab_filter_products() as $key => $label) {
            $on = $job === $key;
            $params = jeab_calendar_filter_next($who, $job, 'job', $key);
            $html .= '<a class="chip chip-job is-' . e((string) $key) . ($on ? ' is-on' : '') . '" data-job="'
                . e((string) $key) . '" href="' . e($href($params)) . '">' . e($label) . '</a>';
        }
    }
    return $html;
}
