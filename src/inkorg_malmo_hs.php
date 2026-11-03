<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_inbox_strip.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/hs_import_store.php';

/**
 * Malmö Help Scout → Inkorgen, en väg.
 * Läser via samma hjälpfunktioner som helpscout.import (klassning, kontakter, datum, dubbletter).
 * Samma lås: jeab_hs_import_with_lock. Ingen kundsvarsskrivning till Help Scout.
 * Skrivning dit är stängning plus intern anteckning när brytaren är på, och
 * POST conversations bara när imported är true, status är active och den enda
 * tråden är en anteckning. Saknas imported nekas anropet. Help Scout skickar då inget.
 */

const JEAB_MALMO_HS_NOTE = 'Hanteras av Kim i flowstack.example.test';
const JEAB_MALMO_HS_SOURCE = 'malmo_hs';
/** Golv när malmo_hs_since är tomt, så första körningen inte läser hela den stängda högen. */
const JEAB_MALMO_HS_FIXED_START = '2026-10-20';

function jeab_malmo_hs_date(mixed $raw): string
{
    $s = trim((string) $raw);
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) === 1 ? substr($s, 0, 10) : '';
}

/** @return list<string> */
function jeab_malmo_hs_skip_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = preg_split('/[\s,;]+/', $raw) ?: [];
    }
    $out = [];
    foreach ((array) $raw as $id) {
        $id = trim((string) $id);
        if (preg_match('/^\d{1,12}$/', $id) === 1) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function jeab_malmo_hs_settings(): array
{
    $d = function_exists('jeab_inkorg_json_file') ? jeab_inkorg_json_file('settings.json') : null;
    $d = is_array($d) ? $d : [];
    return [
        'sync' => !empty($d['malmo_hs_sync']) && $d['malmo_hs_sync'] !== '0',
        'close' => !empty($d['malmo_hs_close']) && $d['malmo_hs_close'] !== '0',
        'since' => jeab_malmo_hs_date($d['malmo_hs_since'] ?? ''),
        'skip' => array_key_exists('malmo_hs_skip', $d) ? jeab_malmo_hs_skip_ids($d['malmo_hs_skip']) : ['25206', '25221'],
    ];
}

function jeab_malmo_hs_settings_save(array $in): array
{
    return jeab_inkorg_locked('settings.json', function (?array $d) use ($in) {
        $d = is_array($d) ? $d : [];
        if (array_key_exists('malmo_hs_sync', $in)) {
            $d['malmo_hs_sync'] = !empty($in['malmo_hs_sync']) && $in['malmo_hs_sync'] !== '0';
        }
        if (array_key_exists('malmo_hs_close', $in)) {
            $d['malmo_hs_close'] = !empty($in['malmo_hs_close']) && $in['malmo_hs_close'] !== '0';
        }
        if (array_key_exists('malmo_hs_since', $in)) {
            $d['malmo_hs_since'] = jeab_malmo_hs_date($in['malmo_hs_since']);
        }
        if (array_key_exists('malmo_hs_skip', $in)) {
            $d['malmo_hs_skip'] = jeab_malmo_hs_skip_ids($in['malmo_hs_skip']);
        } elseif (!array_key_exists('malmo_hs_skip', $d)) {
            $d['malmo_hs_skip'] = ['25206', '25221'];
        }
        $out = [
            'sync' => !empty($d['malmo_hs_sync']) && $d['malmo_hs_sync'] !== '0',
            'close' => !empty($d['malmo_hs_close']) && $d['malmo_hs_close'] !== '0',
            'since' => jeab_malmo_hs_date($d['malmo_hs_since'] ?? ''),
            'skip' => jeab_malmo_hs_skip_ids($d['malmo_hs_skip'] ?? []),
        ];
        return [$out, $d];
    });
}

function jeab_malmo_hs_state_path(): string
{
    return jeab_inkorg_dir() . '/malmo-hs-sync.json';
}

function jeab_malmo_hs_state_row(array $state): array
{
    return [
        'watermark' => (string) ($state['watermark'] ?? ''),
        'page' => max(1, (int) ($state['page'] ?? 1)),
        'offset' => max(0, (int) ($state['offset'] ?? 0)),
        'query' => (string) ($state['query'] ?? ''),
    ];
}

function jeab_malmo_hs_state_json(): array
{
    $d = is_file(jeab_malmo_hs_state_path()) ? json_decode((string) file_get_contents(jeab_malmo_hs_state_path()), true) : null;
    return jeab_malmo_hs_state_row(is_array($d) ? $d : []);
}

function jeab_malmo_hs_state(): array
{
    if (function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('malmo_hs_cursor') === 'json') {
        return jeab_malmo_hs_state_json();
    }
    $sql = jeab_hs_import_store_malmo_get();
    if (is_array($sql)) {
        return $sql;
    }
    return jeab_malmo_hs_state_json();
}

function jeab_malmo_hs_state_save(array $state): void
{
    jeab_hs_import_store_malmo_put(jeab_malmo_hs_state_row($state));
}

function jeab_malmo_hs_job_label(string $job): string
{
    $job = function_exists('jeab_normalize_job_key') ? (jeab_normalize_job_key($job) ?? '') : $job;
    return isset(JEAB_JOB_TYPES[$job]) ? (string) JEAB_JOB_TYPES[$job] : '';
}

function jeab_malmo_hs_owner_name(string $job): string
{
    $name = function_exists('jeab_job_person') ? (string) (jeab_job_person($job, 'malmo') ?? '') : '';
    if ($name !== '') {
        return $name;
    }
    $crew = function_exists('jeab_malmo_job_crew') ? jeab_malmo_job_crew($job) : [];
    return (string) ($crew[0] ?? '');
}

function jeab_malmo_hs_person_id(string $name): string
{
    if ($name === '' || !function_exists('jeab_people')) {
        return $name;
    }
    foreach (jeab_people() as $p) {
        if (!is_array($p)) {
            continue;
        }
        if (strcasecmp((string) ($p['name'] ?? ''), $name) === 0 && (string) ($p['company'] ?? '') === 'malmo') {
            return (string) ($p['id'] ?? $name);
        }
    }
    return $name;
}

function jeab_malmo_hs_flag(array $in, string $key): bool
{
    if (!array_key_exists($key, $in)) {
        return false;
    }
    $v = $in[$key];
    return $v === 1 || $v === '1' || $v === true;
}

/**
 * Manuell dry=0 utan since, eller since äldre än 14 dagar, nekas.
 * since=1 är den vanliga vattenstämpeln. backfill=1 får läsa äldre.
 */
function jeab_malmo_hs_since_block(array $in): string
{
    if (jeab_malmo_hs_flag($in, 'backfill')) {
        return '';
    }
    if (!array_key_exists('since', $in)) {
        return 'since_saknas';
    }
    $since = $in['since'];
    if ($since === 1 || $since === '1' || $since === true) {
        return '';
    }
    $stamp = jeab_malmo_hs_since_stamp($in);
    if ($stamp === '') {
        return 'since_saknas';
    }
    $ts = strtotime($stamp);
    if ($ts === false || $ts < time() - 14 * 86400) {
        return 'since_for_gammal';
    }
    return '';
}

/**
 * @param array<string, mixed> $conversation
 * @param list<array<string, mixed>> $threads
 * @return array{from: string, subject: string, body: string}
 */
function jeab_malmo_hs_classify_msg(array $conversation, array $threads): array
{
    $tags = [];
    foreach ((array) ($conversation['tags'] ?? []) as $tag) {
        $name = is_array($tag) ? (string) ($tag['tag'] ?? $tag['name'] ?? '') : (string) $tag;
        $name = trim($name);
        if ($name !== '') {
            $tags[] = $name;
        }
    }
    $body = '';
    foreach ($threads as $thread) {
        if (!is_array($thread) || (string) ($thread['type'] ?? '') !== 'customer') {
            continue;
        }
        $plain = function_exists('jeab_hs_import_plain')
            ? jeab_hs_import_plain((string) ($thread['body'] ?? ''))
            : trim(strip_tags((string) ($thread['body'] ?? '')));
        if ($plain === '') {
            continue;
        }
        $body .= ($body === '' ? '' : "\n") . $plain;
    }
    if ($tags !== []) {
        $body .= ($body === '' ? '' : "\n\n") . 'Etiketter: ' . implode(', ', $tags);
    }
    return [
        'from' => '',
        'subject' => (string) ($conversation['subject'] ?? ''),
        'body' => mb_substr($body, 0, 4000),
    ];
}

/** @param array<string, mixed> $conversation @return array<string, mixed>|null */
function jeab_malmo_hs_llm(array $conversation): ?array
{
    static $cache = [];
    $threads = jeab_malmo_hs_threads($conversation);
    $msg = jeab_malmo_hs_classify_msg($conversation, $threads);
    $key = (string) ($conversation['id'] ?? '') . '|' . hash('sha256', $msg['subject'] . "\n" . $msg['body']);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $labels = null;
    try {
        if (function_exists('jeab_inkorg_llm_classify')) {
            $labels = jeab_inkorg_llm_classify($msg);
        }
    } catch (Throwable $e) {
        unset($e);
        $labels = null;
    }
    $cache[$key] = is_array($labels) ? $labels : null;
    return $cache[$key];
}

/** Samma köpregel som inkorgen, med avsändare och trådar från Help Scout. */
function jeab_malmo_hs_purchase_msg(array $conversation): array
{
    $threads = jeab_malmo_hs_threads($conversation);
    $base = jeab_malmo_hs_classify_msg($conversation, $threads);
    $messages = [];
    foreach ($threads as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $type = (string) ($thread['type'] ?? '');
        $email = trim((string) ($thread['createdBy']['email'] ?? $thread['customer']['email'] ?? ''));
        $from = $email;
        $source = 'helpscout';
        if ($type !== '' && $type !== 'customer') {
            $source = 'outbound';
            if ($from === '') {
                $from = 'kontakt@malmo.example.test';
            }
        }
        $messages[] = [
            'from' => $from,
            'subject' => (string) ($conversation['subject'] ?? ''),
            'body' => trim(strip_tags((string) ($thread['body'] ?? ''))),
            'source' => $source,
        ];
    }
    $from = jeab_malmo_hs_customer_email($conversation);
    if ($from === '') {
        $from = (string) ($base['from'] ?? '');
    }
    return [
        'from' => $from,
        'from_name' => '',
        'subject' => (string) ($base['subject'] ?? $conversation['subject'] ?? ''),
        'body' => (string) ($base['body'] ?? ''),
        'headers' => is_array($conversation['headers'] ?? null) ? $conversation['headers'] : [],
        'thread_id' => (string) ($conversation['id'] ?? ''),
        '_messages' => $messages,
    ];
}

function jeab_malmo_hs_content_offer(array $conversation): string
{
    if (!function_exists('jeab_inkorg_purchase_offer')) {
        return '';
    }
    return jeab_inkorg_purchase_offer(jeab_malmo_hs_purchase_msg($conversation));
}

/**
 * Innehåll utan produktetikett: batteri eller laddbox blir Kim och Ny lead.
 * Sol blir Ny lead i Historik. Support från modellen lämnas.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_malmo_hs_decision_lift(array $conversation, array $row): array
{
    if (($row['target'] ?? '') === 'kim' || ($row['reason'] ?? '') === 'support') {
        return $row;
    }
    $offer = jeab_malmo_hs_content_offer($conversation);
    if ($offer === 'batteri' || $offer === 'laddbox') {
        $job = $offer;
        $owner = jeab_malmo_hs_owner_name($job);
        if ($owner === '') {
            $owner = 'Kim';
        }
        $tag = $job === 'laddbox' ? 'elbilsladdare' : 'batteri';
        return [
            'classification' => jeab_malmo_hs_job_label($job) !== '' ? jeab_malmo_hs_job_label($job) : ($job === 'laddbox' ? 'Elbilsladdare' : 'Batteri'),
            'target' => 'kim',
            'target_label' => 'Kim aktiv',
            'jobs' => [$job],
            'tags' => [$tag],
            'assignee' => jeab_malmo_hs_person_id($owner),
            'type' => 'Ny lead',
            'reason' => $job === 'laddbox' ? 'köpintresse elbilsladdare' : 'köpintresse batteri',
            'confidence' => 90,
        ];
    }
    if ($offer === 'sol' && (string) ($row['type'] ?? '') !== 'Ny lead') {
        $row['type'] = 'Ny lead';
        $row['tags'] = array_values(array_unique(array_merge(array_map('strval', (array) ($row['tags'] ?? [])), ['sol'])));
        if ((string) ($row['classification'] ?? '') === 'Övrigt') {
            $row['classification'] = 'Sol';
        }
    }
    return $row;
}

/**
 * Köp av bara batteri, eller köp av Elbilsladdare → Kim.
 * Ny sol, sol+batteri, support och osäker klassning → Historik. Etiketter är bara ledtrådar till modellen.
 *
 * @param array<string, mixed> $conversation
 * @return array{classification: string, target: string, target_label: string, jobs: list<string>, tags: list<string>, assignee: string, type: string, reason: string, confidence: int}
 */
function jeab_malmo_hs_decision(array $conversation): array
{
    $class = jeab_hs_import_classify($conversation);
    $hintTags = array_values(array_filter(array_map('strval', (array) ($class['tags'] ?? []))));
    $purchaseMsg = jeab_malmo_hs_purchase_msg($conversation);
    $block = $purchaseMsg !== [] && function_exists('jeab_inkorg_purchase_blocked') ? jeab_inkorg_purchase_blocked($purchaseMsg) : '';
    if (in_array($block, ['support_form', 'support_words'], true)) {
        return [
            'classification' => 'Övrigt',
            'target' => 'historik',
            'target_label' => 'Historik',
            'jobs' => [],
            'tags' => [],
            'assignee' => '',
            'type' => 'Information',
            'reason' => 'support',
            'confidence' => 0,
        ];
    }
    if ($block !== '' && !in_array($block, ['support_reminder', 'support_order', 'support_installation'], true)) {
        return [
            'classification' => 'Övrigt',
            'target' => 'historik',
            'target_label' => 'Historik',
            'jobs' => [],
            'tags' => [],
            'assignee' => '',
            'type' => 'Information',
            'reason' => $block === 'outside' ? 'utanför området' : 'undantagen',
            'confidence' => 0,
        ];
    }
    $form = $purchaseMsg !== [] && function_exists('jeab_inkorg_form_product') ? jeab_inkorg_form_product($purchaseMsg) : '';
    if ($form === 'sol') {
        return [
            'classification' => 'Sol',
            'target' => 'historik',
            'target_label' => 'Historik',
            'jobs' => [],
            'tags' => ['sol'],
            'assignee' => '',
            'type' => 'Ny lead',
            'reason' => 'formulär sol',
            'confidence' => 90,
        ];
    }
    $contentOffer = $purchaseMsg !== [] && function_exists('jeab_malmo_hs_content_offer') ? jeab_malmo_hs_content_offer($conversation) : '';
    if ($contentOffer === 'sol') {
        return [
            'classification' => 'Sol',
            'target' => 'historik',
            'target_label' => 'Historik',
            'jobs' => [],
            'tags' => ['sol'],
            'assignee' => '',
            'type' => 'Ny lead',
            'reason' => 'sol',
            'confidence' => 90,
        ];
    }
    if ($form === 'batteri' || $form === 'laddbox') {
        return jeab_malmo_hs_decision_lift($conversation, [
            'classification' => 'Övrigt',
            'target' => 'historik',
            'target_label' => 'Historik',
            'jobs' => [],
            'tags' => $hintTags,
            'assignee' => '',
            'type' => 'Information',
            'reason' => 'formulär',
            'confidence' => 0,
        ]);
    }
    $blank = static function (string $reason, string $classification = 'Övrigt') use ($class, $hintTags): array {
        return [
            'classification' => $classification,
            'target' => 'historik',
            'target_label' => 'Historik',
            'jobs' => [],
            'tags' => array_values(array_intersect($hintTags, ['sol', 'batteri', 'elbilsladdare'])),
            'assignee' => '',
            'type' => (string) ($class['type'] ?? 'Information'),
            'reason' => $reason,
            'confidence' => 0,
        ];
    };
    $labels = jeab_malmo_hs_llm($conversation);
    if (!is_array($labels)) {
        $name = in_array('sol', $hintTags, true) ? 'Sol' : 'Övrigt';
        return jeab_malmo_hs_decision_lift($conversation, $blank('klassning saknas', $name));
    }
    $case = is_array($labels['case_class'] ?? null) ? $labels['case_class'] : [];
    $confidence = (int) ($labels['confidence'] ?? 0);
    $reviewMin = function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70;
    $fieldMin = function_exists('jeab_inkorg_review_field_min') ? jeab_inkorg_review_field_min() : 0.7;
    $low = $confidence < $reviewMin || !empty($labels['needs_review']);
    if (!$low && $case !== []) {
        $fc = (array) ($case['confidence'] ?? []);
        if ((isset($fc['intent']) && (float) $fc['intent'] < $fieldMin) || (isset($fc['primary_product']) && (float) $fc['primary_product'] < $fieldMin)) {
            $low = true;
        }
    }
    if ($low) {
        return jeab_malmo_hs_decision_lift($conversation, $blank('osäker klassning'));
    }
    $intent = (string) ($labels['intent'] ?? '');
    $caseIntent = (string) ($case['intent'] ?? '');
    if ($caseIntent === 'support' || $intent === 'support') {
        return jeab_malmo_hs_decision_lift($conversation, $blank('support'));
    }
    $purchase = $intent === 'kop' || $caseIntent === 'kopintresse';
    if (!$purchase) {
        return jeab_malmo_hs_decision_lift($conversation, $blank('inget köpintresse'));
    }
    $primary = (string) ($case['primary_product'] ?? '');
    $secondary = array_map('strval', (array) ($case['secondary_products'] ?? []));
    $offer = (string) ($labels['offer'] ?? '');
    if ($primary === '') {
        $primary = ['batteri' => 'Batteri', 'laddbox' => 'Elbilsladdare', 'sol' => 'Sol'][$offer] ?? '';
    }
    $product = mb_strtolower((string) ($labels['product'] ?? ''));
    $wantsSolar = $primary === 'Sol' || in_array('Sol', $secondary, true) || $offer === 'sol' || str_contains($product, 'solcell');
    $wantsBattery = $primary === 'Batteri' || in_array('Batteri', $secondary, true) || $offer === 'batteri' || str_contains($product, 'batteri');
    $wantsCharger = $primary === 'Elbilsladdare' || in_array('Elbilsladdare', $secondary, true) || $offer === 'laddbox' || str_contains($product, 'elbil');
    if ($wantsSolar) {
        $name = $wantsBattery ? 'Sol och Batteri' : 'Sol';
        return jeab_malmo_hs_decision_lift($conversation, $blank($wantsBattery ? 'sol och batteri' : 'sol', $name));
    }
    $jobs = [];
    if ($wantsBattery && ($primary === 'Batteri' || $primary === '' || !$wantsCharger)) {
        $jobs[] = 'batteri';
    }
    if ($wantsCharger && ($primary === 'Elbilsladdare' || $primary === '' || !$wantsBattery)) {
        $jobs[] = 'laddbox';
    }
    if ($wantsBattery && $wantsCharger) {
        $jobs = $primary === 'Elbilsladdare' ? ['laddbox', 'batteri'] : ['batteri', 'laddbox'];
    }
    if ($jobs === []) {
        return jeab_malmo_hs_decision_lift($conversation, $blank('inget köpintresse'));
    }
    $names = [];
    foreach ($jobs as $job) {
        $label = jeab_malmo_hs_job_label($job);
        if ($label !== '') {
            $names[] = $label;
        }
    }
    $owner = jeab_malmo_hs_owner_name($jobs[0]);
    if ($owner === '') {
        $owner = 'Kim';
    }
    $tagFor = ['batteri' => 'batteri', 'laddbox' => 'elbilsladdare'];
    $tags = [];
    foreach ($jobs as $job) {
        if (isset($tagFor[$job])) {
            $tags[] = $tagFor[$job];
        }
    }
    return [
        'classification' => $names !== [] ? implode(' och ', $names) : 'Övrigt',
        'target' => 'kim',
        'target_label' => 'Kim aktiv',
        'jobs' => $jobs,
        'tags' => $tags,
        'assignee' => jeab_malmo_hs_person_id($owner),
        'type' => 'Ny lead',
        'reason' => $jobs[0] === 'laddbox' ? 'köpintresse elbilsladdare' : 'köpintresse batteri',
        'confidence' => $confidence,
    ];
}

function jeab_malmo_hs_kim_thread(string $tid): bool
{
    if ($tid === '') {
        return false;
    }
    $meta = jeab_inkorg_thread_meta($tid);
    return !empty($meta['malmo_hs_kim']);
}

function jeab_malmo_hs_thread_key(string $conversationId): string
{
    return 'mhs-' . substr(hash('sha256', 'malmo-hs:' . $conversationId), 0, 16);
}

function jeab_malmo_hs_find_thread(string $cid, string $email): string
{
    $email = function_exists('jeab_norm_email') ? jeab_norm_email($email) : mb_strtolower(trim($email));
    $meta = jeab_inkorg_threads_meta();
    foreach ($meta as $tid => $t) {
        if (!is_array($t) || empty($t['malmo_hs_kim']) || (string) ($t['deleted_at'] ?? '') !== '') {
            continue;
        }
        $ids = array_map('strval', (array) ($t['hs_conversation_ids'] ?? []));
        $current = (string) ($t['hs_conversation_id'] ?? '');
        if ($current !== '') {
            $ids[] = $current;
        }
        if ($cid !== '' && in_array($cid, $ids, true)) {
            return (string) $tid;
        }
    }
    if ($email === '' || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email))) {
        return '';
    }
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_party_id')) {
        $party = jeab_contacts_sole_party_id($email);
        if ($party !== '') {
            foreach ($meta as $tid => $t) {
                if (!is_array($t) || empty($t['malmo_hs_kim']) || (string) ($t['deleted_at'] ?? '') !== '') {
                    continue;
                }
                if (trim((string) ($t['contact_id'] ?? '')) === $party) {
                    return (string) $tid;
                }
            }
        }
    }
    foreach ($meta as $tid => $t) {
        if (!is_array($t) || empty($t['malmo_hs_kim']) || (string) ($t['deleted_at'] ?? '') !== '') {
            continue;
        }
        if (mb_strtolower((string) ($t['customer_email'] ?? '')) === $email) {
            return (string) $tid;
        }
    }
    foreach (jeab_inkorg_messages() as $m) {
        if (!is_array($m)) {
            continue;
        }
        $tid = (string) ($m['thread_id'] ?? '');
        if ($tid === '' || empty($meta[$tid]['malmo_hs_kim']) || !empty($m['internal_note'])) {
            continue;
        }
        if (mb_strtolower((string) ($m['from'] ?? '')) === $email) {
            return $tid;
        }
    }
    return '';
}

function jeab_malmo_hs_customer_email(array $conversation): string
{
    $primary = (array) ($conversation['primaryCustomer'] ?? []);
    $hint = jeab_hs_import_customer_hint($primary, $primary);
    return mb_strtolower(trim((string) ($hint['email'] ?? '')));
}

function jeab_malmo_hs_hs_closed(array $conversation): bool
{
    $status = strtolower(trim((string) ($conversation['status'] ?? '')));
    return $status === 'closed' || $status === 'spam';
}

function jeab_malmo_hs_offer_key(string $job): string
{
    return in_array($job, ['batteri', 'laddbox'], true) ? $job : '';
}

function jeab_malmo_hs_template(string $job): string
{
    return $job === 'batteri' ? 'batteri_malmo' : ($job === 'laddbox' ? 'laddbox_malmo' : '');
}

function jeab_malmo_hs_sender(): string
{
    $sender = function_exists('jeab_gp_person_email') ? jeab_gp_person_email('Kim', 'malmo') : '';
    return $sender !== '' ? $sender : 'saljare.syd@malmo.example.test';
}

function jeab_malmo_hs_product_label(string $job): string
{
    return $job === 'laddbox' ? 'Elbilsladdare' : ($job === 'batteri' ? 'Batteri' : '');
}

function jeab_malmo_hs_has_offer(string $email, string $job, string $tid): bool
{
    $offer = jeab_malmo_hs_offer_key($job);
    if ($offer === '') {
        return false;
    }
    if ($email !== '' && function_exists('jeab_offer_malmo_sent_find') && is_array(jeab_offer_malmo_sent_find($email, $offer))) {
        return true;
    }
    if ($tid !== '' && function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread) || (string) ($thread['id'] ?? '') !== $tid) {
                continue;
            }
            if (function_exists('jeab_auto_quote_sent_note') && !empty(jeab_auto_quote_sent_note($thread, $offer)['hit'])) {
                return true;
            }
            if (function_exists('jeab_auto_quote_sent_marker') && !empty(jeab_auto_quote_sent_marker($thread, $offer)['hit'])) {
                return true;
            }
            break;
        }
    }
    if ($email !== '' && function_exists('jeab_auto_quote_sent_inbox') && !empty(jeab_auto_quote_sent_inbox($email, $offer)['hit'])) {
        return true;
    }
    return false;
}

function jeab_malmo_hs_has_reminder(string $cid, string $email, string $job, string $contactId, string $tid): bool
{
    if (!function_exists('jeab_reminders_every') || !function_exists('jeab_store')) {
        return false;
    }
    $email = mb_strtolower(trim($email));
    $label = jeab_malmo_hs_product_label($job);
    foreach (jeab_reminders_every(jeab_store()) as $row) {
        if (!is_array($row) || (int) ($row['id'] ?? 0) < 1) {
            continue;
        }
        if (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($row)) {
            continue;
        }
        $note = (string) ($row['note'] ?? '');
        $title = (string) ($row['title'] ?? '');
        $blob = $note . "\n" . $title;
        if ($cid !== '' && function_exists('jeab_reminder_hs_ids_from_text')) {
            $ids = jeab_reminder_hs_ids_from_text($blob);
            if (in_array($cid, $ids, true)) {
                return true;
            }
        }
        if ($cid !== '' && preg_match('/hs_conversation_id:\s*' . preg_quote($cid, '/') . '\b/', $blob) === 1) {
            return true;
        }
        if ($tid !== '' && trim((string) ($row['thread_id'] ?? '')) === $tid) {
            return true;
        }
        if ($tid !== '' && function_exists('jeab_lead_reminder_has_thread') && jeab_lead_reminder_has_thread($note, $tid)) {
            return true;
        }
        if ($contactId !== '' && (string) ($row['contact_id'] ?? '') === $contactId) {
            return true;
        }
        $rowEmail = mb_strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && $rowEmail === $email && $label !== '' && mb_stripos($blob, $label) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Saknad offert skickas från Kim. Saknad påminnelse skapas åt Kim.
 * Redan skickad offert inom 30 dagar (samma mottagare och produkt) skickas inte igen.
 *
 * @param array<string, mixed> $decision
 * @return array{sent: bool, created: bool}
 */
function jeab_malmo_hs_fill(string $tid, string $cid, array $decision, string $email, string $name, string $contactId): array
{
    $job = (string) ($decision['jobs'][0] ?? '');
    $offer = jeab_malmo_hs_offer_key($job);
    $hasOffer = jeab_malmo_hs_has_offer($email, $job, $tid);
    $hasReminder = jeab_malmo_hs_has_reminder($cid, $email, $job, $contactId, $tid);
    $parsed = function_exists('jeab_name_address_from_thread')
        ? jeab_name_address_from_thread($tid)
        : ['name' => '', 'address' => '', 'street' => '', 'postal' => '', 'city' => ''];
    $who = '';
    foreach ([trim($name), trim((string) ($parsed['name'] ?? ''))] as $cand) {
        if ($cand !== '' && function_exists('jeab_name_address_name_real') && jeab_name_address_name_real($cand, [$email])) {
            $who = $cand;
            break;
        }
        if ($cand !== '' && !function_exists('jeab_name_address_name_real') && !str_contains($cand, '@') && mb_strtolower($cand) !== 'kund') {
            $who = $cand;
            break;
        }
    }
    $offerName = $who !== '' ? $who : 'Kund';
    $address = '';
    if (function_exists('jeab_name_address_street_real') && jeab_name_address_street_real((string) ($parsed['street'] ?? $parsed['address'] ?? ''))) {
        $address = trim((string) ($parsed['street'] ?? $parsed['address'] ?? ''));
    }
    $sent = false;
    $error = '';
    $res = [];
    if (!$hasOffer && $offer !== '' && $email !== '' && function_exists('jeab_offer_send_malmo')) {
        try {
            $res = jeab_offer_send_malmo([
                'dry' => 0,
                'product' => $offer,
                'to' => $email,
                'customer_name' => $offerName,
                'hs_conversation_id' => $cid,
            ]);
        } catch (Throwable $e) {
            unset($e);
            $res = ['sent' => false];
            $error = 'offert';
        }
        if (!empty($res['sent'])) {
            $sent = true;
            $hasOffer = true;
            if ((int) ($res['reminder'] ?? 0) > 0) {
                $hasReminder = true;
            }
        } elseif (!empty($res['already_sent'])) {
            $hasOffer = true;
        } else {
            $error = 'offert';
        }
    }
    $created = false;
    $linkedReminder = 0;
    if (!empty($res) && (int) ($res['reminder'] ?? 0) > 0) {
        $linkedReminder = (int) $res['reminder'];
    }
    if (!$hasReminder && $offer !== '' && function_exists('jeab_offer_malmo_reminder_apply')) {
        $label = jeab_malmo_hs_product_label($job);
        $place = [
            'postal' => (string) ($parsed['postal'] ?? ''),
            'ort' => (string) ($parsed['city'] ?? ''),
            'thread_id' => $tid,
        ];
        if ($contactId !== '' && function_exists('jeab_kontakt_get') && is_array(jeab_kontakt_get($contactId))) {
            $place['contact_id'] = $contactId;
        }
        $applied = jeab_offer_malmo_reminder_apply($email, $offerName, $address, $label, $cid, ['action' => 'create', 'id' => 0], $place);
        if ((int) ($applied['id'] ?? 0) > 0) {
            $created = true;
            $hasReminder = true;
            $reminderId = (int) $applied['id'];
            if ($tid !== '' && function_exists('jeab_reminder_remember_thread')) {
                jeab_reminder_remember_thread(jeab_store(), $reminderId, $tid);
            }
            if ($tid !== '' && function_exists('jeab_inkorg_lead_contact_from_reminder')) {
                jeab_inkorg_lead_contact_from_reminder($tid, $reminderId);
            }
        } elseif ($error === '') {
            $error = 'paminnelse';
        }
    } elseif ($linkedReminder > 0 && $tid !== '') {
        if (function_exists('jeab_reminder_remember_thread')) {
            jeab_reminder_remember_thread(jeab_store(), $linkedReminder, $tid);
        }
        if (function_exists('jeab_inkorg_lead_contact_from_reminder')) {
            jeab_inkorg_lead_contact_from_reminder($tid, $linkedReminder);
        }
    }
    if (function_exists('jeab_name_address_save_parsed_contact')) {
        jeab_name_address_save_parsed_contact($email, $who, [
            'contact_id' => $contactId,
            'street' => $address,
            'postal' => (string) ($parsed['postal'] ?? ''),
            'city' => (string) ($parsed['city'] ?? ''),
            'company' => 'malmo',
        ]);
    }
    $ready = $hasOffer && $hasReminder;
    if (!$ready && $error === '' && $offer !== '') {
        $error = 'saknas';
    }

    return [
        'sent' => $sent,
        'created' => $created,
        'offer_ready' => $hasOffer,
        'reminder_ready' => $hasReminder,
        'wanted' => $offer !== '',
        'error' => $ready ? '' : $error,
    ];
}

/** @param array<string, mixed> $conversation */
function jeab_malmo_hs_plan_row(array $conversation, bool $closeEnabled, array $in = []): array
{
    unset($in);
    $cid = (string) ($conversation['id'] ?? '');
    $decision = jeab_malmo_hs_decision($conversation);
    $email = jeab_malmo_hs_customer_email($conversation);
    $job = (string) ($decision['jobs'][0] ?? '');
    $closed = jeab_malmo_hs_hs_closed($conversation);
    $tid = $decision['target'] === 'kim' ? jeab_malmo_hs_find_thread($cid, $email) : '';
    $contactId = $tid !== '' ? (string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') : '';
    $hasOffer = $decision['target'] === 'kim' && jeab_malmo_hs_has_offer($email, $job, $tid);
    $hasReminder = $decision['target'] === 'kim' && jeab_malmo_hs_has_reminder($cid, $email, $job, $contactId, $tid);
    $openKim = $decision['target'] === 'kim' && !$closed;
    $skipListed = jeab_malmo_hs_skip_hit($conversation);
    $template = $decision['target'] === 'kim' ? jeab_malmo_hs_template($job) : '';
    $wouldSend = $openKim && !$skipListed && $template !== '' && !$hasOffer;
    $block = function_exists('jeab_inkorg_purchase_blocked') ? jeab_inkorg_purchase_blocked(jeab_malmo_hs_purchase_msg($conversation)) : '';
    if (in_array($block, ['support_form', 'support_words', 'support_reminder', 'support_order', 'support_installation', 'supplier', 'blocklist', 'bulk', 'noreply', 'staff', 'ours', 'autotest', 'formtest', 'outside'], true)) {
        $wouldSend = false;
    }
    $wouldReminder = $openKim && !$skipListed && !$hasReminder;
    $wouldClose = $openKim && !$skipListed && $closeEnabled;
    $reason = $decision['reason'];
    $skipped = '';
    if ($skipListed) {
        $reason = 'kundtjänst';
        $skipped = 'skip_list';
    } elseif ($closed && $decision['target'] === 'kim') {
        $reason = 'redan stängt i Help Scout';
        $skipped = 'hs_closed';
    } elseif ($closed) {
        $skipped = 'hs_closed';
    }
    return [
        'id' => $cid,
        'decision' => $decision['target'],
        'reason' => $reason,
        'has_reminder' => $hasReminder,
        'has_offer' => $hasOffer,
        'would_send_offer' => $wouldSend,
        'would_create_reminder' => $wouldReminder,
        'would_close' => $wouldClose,
        'would_auto_quote' => $wouldSend,
        'template' => $template,
        'sender' => $template !== '' ? jeab_malmo_hs_sender() : '',
        'classification' => $decision['classification'],
        'target' => $decision['target_label'],
        'merge' => $tid !== '',
        'close' => $wouldClose,
        'skipped' => $skipped,
    ];
}

function jeab_malmo_hs_created_day(array $conversation): string
{
    return jeab_malmo_hs_date($conversation['createdAt'] ?? $conversation['userCreatedAt'] ?? '');
}

/** Öppet ärende, eller stängt som skapats på eller efter datumet. Tomt datum släpper igenom fixturer. */
function jeab_malmo_hs_in_scope(array $conversation): bool
{
    $since = jeab_malmo_hs_settings()['since'];
    if ($since === '' || !jeab_malmo_hs_hs_closed($conversation)) {
        return true;
    }
    $created = jeab_malmo_hs_created_day($conversation);
    return $created !== '' && strcmp($created, $since) >= 0;
}

function jeab_malmo_hs_floor_day(): string
{
    $day = jeab_malmo_hs_settings()['since'];
    return $day !== '' ? $day : JEAB_MALMO_HS_FIXED_START;
}

/**
 * Utan markör: öppna och väntande tas med, stängda bara från startdatumet.
 * Med markör: öppna, väntande och stängda som ändrats sedan markören.
 */
function jeab_malmo_hs_search(string $modifiedSince): string
{
    $changed = '(status:"active" OR status:"pending" OR status:"closed")';
    if ($modifiedSince !== '') {
        return '(' . $changed . ' AND modifiedAt:[' . $modifiedSince . ' TO *])';
    }
    $day = jeab_malmo_hs_floor_day();
    $open = '(status:"active" OR status:"pending")';
    return '(' . $open . ' OR (status:"closed" AND createdAt:[' . $day . 'T00:00:00Z TO *]))';
}

function jeab_malmo_hs_limit(array $in): int
{
    if (jeab_malmo_hs_flag($in, 'all_open')) {
        return 100000;
    }
    return max(1, min(500, (int) ($in['limit'] ?? 25)));
}

/** Spärrlistan håller Help Scout-nummer (25206). Träffar även ärende-id. */
function jeab_malmo_hs_skip_hit(array $conversation): bool
{
    $skip = jeab_malmo_hs_settings()['skip'];
    $id = (string) ($conversation['id'] ?? '');
    $number = (string) ($conversation['number'] ?? '');
    return ($id !== '' && in_array($id, $skip, true)) || ($number !== '' && in_array($number, $skip, true));
}

function jeab_malmo_hs_since_stamp(array $in): string
{
    $since = $in['since'] ?? null;
    if (is_string($since) && preg_match('/^\d{4}-\d{2}-\d{2}/', $since) === 1) {
        return $since;
    }
    return '';
}

/** @param list<array<string, mixed>> $rows */
function jeab_malmo_hs_filter_rows(array $rows, array $in): array
{
    $stamp = jeab_malmo_hs_since_stamp($in);
    if ($stamp !== '') {
        $rows = array_values(array_filter($rows, static function (array $c) use ($stamp): bool {
            $mod = (string) ($c['userModifiedAt'] ?? $c['modifiedAt'] ?? $c['createdAt'] ?? '');
            return $mod === '' || strcmp($mod, $stamp) >= 0;
        }));
    }
    $rows = array_values(array_filter($rows, 'jeab_malmo_hs_in_scope'));
    return array_slice($rows, 0, jeab_malmo_hs_limit($in));
}

function jeab_malmo_hs_fixtures(): ?array
{
    $rows = $GLOBALS['_JEAB_MALMO_HS_FIXTURES'] ?? null;
    if (!is_array($rows)) {
        return null;
    }
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

function jeab_malmo_hs_local_conversations(int $limit): array
{
    if (!function_exists('jeab_hs_import_index')) {
        return [];
    }
    $out = [];
    foreach (jeab_hs_import_index()['by_id'] as $tid => $row) {
        if (!is_array($row) || (string) ($row['company'] ?? '') !== 'malmo') {
            continue;
        }
        $conv = jeab_hs_import_read_conv((string) $tid);
        if (!is_array($conv)) {
            continue;
        }
        $cid = (string) ($conv['hs_ids']['conversation'] ?? $row['hs_conversation_id'] ?? '');
        if ($cid === '') {
            continue;
        }
        $tags = [];
        foreach ((array) ($conv['meta']['tags'] ?? []) as $tag) {
            $tags[] = ['tag' => (string) $tag];
        }
        $email = '';
        foreach ((array) ($conv['messages'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $from = mb_strtolower(trim((string) ($m['from'] ?? '')));
            if ($from !== '' && !jeab_inkorg_own_address($from)) {
                $email = $from;
                break;
            }
        }
        $out[] = [
            'id' => $cid,
            'subject' => (string) (($conv['messages'][0]['subject'] ?? '') ?: ''),
            'status' => (string) ($conv['status'] ?? 'closed'),
            'modifiedAt' => (string) ($conv['meta']['updated_at'] ?? ''),
            'tags' => $tags,
            'primaryCustomer' => ['email' => $email],
            '_local' => true,
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function jeab_malmo_hs_wants_incremental(array $in): bool
{
    if (jeab_malmo_hs_since_stamp($in) !== '') {
        return true;
    }
    $since = $in['since'] ?? null;
    return $since === 1 || $since === '1' || $since === true;
}

/**
 * Går igenom öppna sidor från sida 1. Dry läser och skriver ingen markör.
 * Vattenstämpeln sätts bara när sista sidan är tömd.
 *
 * @return list<array<string, mixed>>
 */
function jeab_malmo_hs_walk_pages(int $mailboxId, string $modified, int $limit, bool $stampWhenDone): array
{
    $out = [];
    $page = 1;
    $finished = false;
    while ($page <= 80) {
        $list = jeab_hs_import_get_json('conversations', [
            'mailbox' => $mailboxId,
            'status' => 'all',
            'sortField' => 'modifiedAt',
            'sortOrder' => 'asc',
            'page' => $page,
            'query' => jeab_malmo_hs_search($modified),
        ]);
        $convs = array_values(array_filter((array) ($list['_embedded']['conversations'] ?? []), 'is_array'));
        $convs = array_values(array_filter($convs, 'jeab_malmo_hs_in_scope'));
        $totalPages = max(1, (int) ($list['page']['totalPages'] ?? 1));
        $room = $limit - count($out);
        $take = array_slice($convs, 0, max(0, $room));
        foreach ($take as $conversation) {
            $out[] = $conversation;
        }
        $moreOnPage = count($take) < count($convs);
        $morePages = $page < $totalPages;
        if (count($out) >= $limit && ($moreOnPage || $morePages)) {
            break;
        }
        if ($page >= $totalPages) {
            $finished = true;
            break;
        }
        $page++;
    }
    if ($stampWhenDone && $finished) {
        jeab_malmo_hs_state_save([
            'watermark' => gmdate('Y-m-d\TH:i:s\Z'),
            'page' => 1,
            'offset' => 0,
            'query' => '',
        ]);
    }
    return $out;
}

function jeab_malmo_hs_fetch(array $in, bool $commit = true): array
{
    $mailboxId = function_exists('jeab_helpscout_mailbox_id') ? jeab_helpscout_mailbox_id('malmo') : null;
    if ($mailboxId === null || $mailboxId < 1) {
        throw new RuntimeException('hs_brevlada');
    }
    $backfill = jeab_malmo_hs_flag($in, 'backfill');
    $explicit = jeab_malmo_hs_since_stamp($in);
    $limit = jeab_malmo_hs_limit($in);
    if (!$commit) {
        return jeab_malmo_hs_walk_pages($mailboxId, $backfill ? '' : $explicit, $limit, false);
    }
    $fullOpen = $backfill || jeab_malmo_hs_flag($in, 'all_open');
    $watermark = trim((string) (jeab_malmo_hs_state()['watermark'] ?? ''));
    $firstOpen = !$fullOpen && jeab_malmo_hs_wants_incremental($in) && $explicit === '' && $watermark === '';
    if ($fullOpen || $firstOpen) {
        return jeab_malmo_hs_walk_pages($mailboxId, '', $limit, true);
    }
    $state = jeab_malmo_hs_state();
    $since = $explicit !== '' ? $explicit : (string) ($state['watermark'] ?? '');
    $queryKey = $explicit !== '' ? $explicit : $since;
    $sameQuery = (string) ($state['query'] ?? '') === $queryKey;
    if ($explicit !== '' && !$sameQuery) {
        $page = 1;
        $offset = 0;
    } else {
        $page = max(1, (int) ($state['page'] ?? 1));
        $offset = max(0, (int) ($state['offset'] ?? 0));
    }
    $list = jeab_hs_import_get_json('conversations', [
        'mailbox' => $mailboxId,
        'status' => 'all',
        'sortField' => 'modifiedAt',
        'sortOrder' => 'asc',
        'page' => $page,
        'query' => jeab_malmo_hs_search($since),
    ]);
    $convs = array_values(array_filter((array) ($list['_embedded']['conversations'] ?? []), 'is_array'));
    $convs = array_values(array_filter($convs, 'jeab_malmo_hs_in_scope'));
    if ($offset > count($convs)) {
        $offset = 0;
    }
    $slice = array_slice($convs, $offset, $limit);
    $consumed = $offset + count($slice);
    $totalPages = max(1, (int) ($list['page']['totalPages'] ?? 1));
    if ($consumed < count($convs)) {
        jeab_malmo_hs_state_save(['watermark' => $since, 'page' => $page, 'offset' => $consumed, 'query' => $queryKey]);
    } elseif ($page + 1 > $totalPages) {
        jeab_malmo_hs_state_save(['watermark' => gmdate('Y-m-d\TH:i:s\Z'), 'page' => 1, 'offset' => 0, 'query' => '']);
    } else {
        jeab_malmo_hs_state_save(['watermark' => $since, 'page' => $page + 1, 'offset' => 0, 'query' => $queryKey]);
    }
    return $slice;
}

function jeab_malmo_hs_conversations(array $in, bool $dry): array
{
    $fixtures = jeab_malmo_hs_fixtures();
    if ($fixtures !== null) {
        return jeab_malmo_hs_filter_rows($fixtures, $in);
    }
    return jeab_malmo_hs_fetch($in, !$dry);
}

function jeab_malmo_hs_threads(array $conversation): array
{
    if (isset($conversation['_threads']) && is_array($conversation['_threads'])) {
        return array_values(array_filter($conversation['_threads'], 'is_array'));
    }
    if (!empty($conversation['_local'])) {
        return [];
    }
    $cid = (string) ($conversation['id'] ?? '');
    if ($cid === '' || !function_exists('jeab_hs_import_threads')) {
        return [];
    }
    return jeab_hs_import_threads($cid);
}

function jeab_malmo_hs_strip_links(string $text): string
{
    $clean = preg_replace('#https?://[^\s<>"\']*helpscout\.net[^\s<>"\']*#i', '', $text);
    return trim((string) $clean);
}

/** @return array{0: array<string, mixed>, 1: string} */
function jeab_malmo_hs_hint(array $conversation, array $threads): array
{
    $primary = (array) ($conversation['primaryCustomer'] ?? []);
    $hint = jeab_hs_import_customer_hint($primary, $primary);
    $author = '';
    $body = '';
    $real = false;
    foreach ($threads as $thread) {
        if (!is_array($thread) || (string) ($thread['type'] ?? '') !== 'customer') {
            continue;
        }
        if (jeab_hs_import_message_is_real_customer($thread)) {
            $real = true;
        }
        if ($author === '') {
            $names = jeab_hs_import_actor_names((array) ($thread['createdBy'] ?? []));
            if ($names['display'] === '') {
                $names = jeab_hs_import_actor_names((array) ($thread['customer'] ?? []));
            }
            $author = (string) $names['display'];
        }
        if ($body === '') {
            $body = jeab_hs_import_plain((string) ($thread['body'] ?? ''));
        }
    }
    $hint['name'] = jeab_hs_import_choose_display((string) ($hint['name'] ?? ''), $author);
    $hint['body'] = $body;
    $hint['subject'] = (string) ($conversation['subject'] ?? '');
    $hint['real_customer'] = $real;
    $hint['indexed'] = false;
    return [$hint, mb_strtolower(trim((string) ($hint['email'] ?? '')))];
}

function jeab_malmo_hs_assert_write(string $method, string $path, array $body): ?string
{
    $method = strtoupper($method);
    $path = ltrim($path, '/');
    if (preg_match('#/(reply|customer)(/|$)#', $path) === 1 || str_contains($path, 'reply')) {
        return 'hs_skriv_nekad';
    }
    // Nytt ärende: bara imported=true, active och en intern anteckning. Annars nekas det.
    if ($method === 'POST' && $path === 'conversations') {
        if (($body['imported'] ?? null) !== true) {
            return 'hs_skriv_nekad';
        }
        if (($body['status'] ?? '') !== 'active') {
            return 'hs_skriv_nekad';
        }
        $threads = $body['threads'] ?? null;
        $thread = is_array($threads) ? ($threads[0] ?? null) : null;
        if (!is_array($threads) || count($threads) !== 1 || !is_array($thread) || ($thread['type'] ?? '') !== 'note') {
            return 'hs_skriv_nekad';
        }

        return null;
    }
    $note = $method === 'POST' && preg_match('#^conversations/\d+/notes$#', $path) === 1;
    $close = $method === 'PATCH' && preg_match('#^conversations/\d+$#', $path) === 1;
    if (!$note && !$close) {
        return 'hs_skriv_nekad';
    }
    if ($note && (string) ($body['text'] ?? '') !== JEAB_MALMO_HS_NOTE) {
        return 'hs_skriv_nekad';
    }
    if ($close && (($body['op'] ?? '') !== 'replace' || ($body['path'] ?? '') !== '/status' || ($body['value'] ?? '') !== 'closed')) {
        return 'hs_skriv_nekad';
    }
    return null;
}

function jeab_malmo_hs_http_write(string $method, string $path, array $body): array
{
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['code' => 0, 'error' => 'omklassning skickar inte'];
    }
    $denied = jeab_malmo_hs_assert_write($method, $path, $body);
    if ($denied !== null) {
        return ['code' => 0, 'error' => $denied];
    }
    if (isset($GLOBALS['_JEAB_MALMO_HS_HTTP']) && is_callable($GLOBALS['_JEAB_MALMO_HS_HTTP'])) {
        $res = ($GLOBALS['_JEAB_MALMO_HS_HTTP'])(strtoupper($method), ltrim($path, '/'), $body);
        $res = is_array($res) ? $res : [];
        return [
            'code' => (int) ($res['code'] ?? 0),
            'error' => (string) ($res['error'] ?? ''),
            'headers' => array_values(array_map('strval', (array) ($res['headers'] ?? []))),
            'body' => (string) ($res['body'] ?? ''),
            'id' => (int) ($res['id'] ?? 0),
        ];
    }
    $token = function_exists('jeab_hs_import_token') ? jeab_hs_import_token() : null;
    if (!is_string($token) || $token === '') {
        return ['code' => 0, 'error' => 'hs_nyckel'];
    }
    $url = 'https://api.helpscout.net/v2/' . ltrim($path, '/');
    if ((string) parse_url($url, PHP_URL_HOST) !== 'api.helpscout.net') {
        return ['code' => 0, 'error' => 'hs_vard'];
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', $url)) {
        return ['code' => 200, 'error' => '', 'headers' => [], 'body' => '{}', 'id' => 0, 'demo' => true];
    }
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            unset($ch);
            $headers[] = $line;

            return strlen($line);
        },
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $id = 0;
    foreach ($headers as $line) {
        if (preg_match('#/conversations/(\d+)#', $line, $m) === 1) {
            $id = (int) $m[1];
            break;
        }
    }

    return [
        'code' => $code,
        'error' => ($code >= 200 && $code < 300) ? '' : 'hs_svar',
        'headers' => $headers,
        'body' => is_string($raw) ? $raw : '',
        'id' => $id,
    ];
}

function jeab_malmo_hs_close_remote(string $cid): array
{
    $note = jeab_malmo_hs_http_write('POST', 'conversations/' . rawurlencode($cid) . '/notes', ['text' => JEAB_MALMO_HS_NOTE]);
    if ((int) ($note['code'] ?? 0) < 200 || (int) ($note['code'] ?? 0) >= 300) {
        return ['ok' => false, 'error' => (string) ($note['error'] ?? 'hs_svar')];
    }
    $close = jeab_malmo_hs_http_write('PATCH', 'conversations/' . rawurlencode($cid), [
        'op' => 'replace',
        'path' => '/status',
        'value' => 'closed',
    ]);
    if ((int) ($close['code'] ?? 0) < 200 || (int) ($close['code'] ?? 0) >= 300) {
        return ['ok' => false, 'error' => (string) ($close['error'] ?? 'hs_svar')];
    }
    return ['ok' => true];
}

/**
 * Med contact_id skrivs ingen kopia av mejladressen. Utan kontakt behålls den.
 *
 * @param array<string, mixed> $t
 * @return array<string, mixed>
 */
function jeab_malmo_hs_stamp_link(array $t, string $contactId, string $email): array
{
    if ($contactId !== '') {
        $t['contact_id'] = $contactId;
        if (jeab_contacts_inbox_strip_flags_on() && function_exists('jeab_inkorg_drop_linked_customer_copy')) {
            return jeab_inkorg_drop_linked_customer_copy($t);
        }
    }
    if ($email !== '') {
        $t['customer_email'] = $email;
    }

    return $t;
}

function jeab_malmo_hs_apply(array $conversation, bool $closeEnabled, array &$cp, array $in = []): array
{
    unset($in);
    $plan = jeab_malmo_hs_plan_row($conversation, $closeEnabled);
    $cid = (string) ($plan['id'] ?? '');
    if ($cid === '' || preg_match('/^\d+$/', $cid) !== 1) {
        return $plan + ['applied' => false];
    }
    if (!empty($conversation['_local']) || ($plan['skipped'] ?? '') !== '' || jeab_malmo_hs_hs_closed($conversation)) {
        return $plan + ['applied' => false];
    }
    $threads = jeab_malmo_hs_threads($conversation);
    $decision = jeab_malmo_hs_decision($conversation);
    $kim = $decision['target'] === 'kim';
    [$hint, $email] = jeab_malmo_hs_hint($conversation, $threads);
    $customerId = (string) (($conversation['primaryCustomer']['id'] ?? '') ?: '');
    $linked = jeab_hs_import_contact_apply($hint, $customerId, false, $cp);
    $contactId = (string) ($linked['id'] ?? '');
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        $hintPhone = trim((string) ($hint['phone'] ?? ''));
        $soleId = function_exists('jeab_contacts_sole_party_id') ? jeab_contacts_sole_party_id($email, $hintPhone) : '';
        if ($soleId === '' && function_exists('jeab_contacts_sole_inflow_upsert')) {
            try {
                $made = jeab_contacts_sole_inflow_upsert([
                    'name' => (string) ($hint['name'] ?? ''),
                    'email' => $email,
                    'phone' => $hintPhone,
                    'company' => 'malmo',
                ]);
                $soleId = is_array($made) ? trim((string) ($made['id'] ?? '')) : '';
            } catch (Throwable) {
                $soleId = '';
            }
        }
        if ($soleId !== '') {
            $contactId = $soleId;
        }
    }
    $mailboxId = function_exists('jeab_helpscout_mailbox_id') ? (int) (jeab_helpscout_mailbox_id('malmo') ?? 0) : 0;
    $existing = $kim ? jeab_malmo_hs_find_thread($cid, $email) : '';
    $plan['merge'] = $existing !== '';
    $tid = $existing !== '' ? $existing : jeab_malmo_hs_thread_key($cid);
    $job = (string) ($decision['jobs'][0] ?? '');
    $prepared = [];
    foreach ($threads as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $row = jeab_hs_import_message_row($thread, $conversation, 'malmo', $mailboxId);
        if ($row === null || !empty($row['internal_note']) || (string) ($row['hs_thread_type'] ?? '') === 'note') {
            continue;
        }
        unset($row['attachments'], $row['hs_conversation_id'], $row['hs_thread_id'], $row['hs_customer_id'], $row['hs_mailbox_id']);
        $row['body_text'] = jeab_malmo_hs_strip_links((string) ($row['body_text'] ?? ''));
        $row['source'] = $kim ? JEAB_MALMO_HS_SOURCE : JEAB_HS_IMPORT_SOURCE;
        if ($kim) {
            unset($row['no_auto']);
        }
        $row['thread_key'] = $tid;
        $row['company'] = 'malmo';
        $row['to'] = jeab_inkorg_sender('malmo');
        $row['message_id'] = 'mhs-' . $cid . '-' . (string) ($thread['id'] ?? '') . '@flowstack.example.test';
        $tags = $decision['tags'];
        $row['preset_labels'] = [
            'type' => $kim ? 'Ny lead' : (string) $decision['type'],
            'action' => $kim ? 'svara' : 'arkivera',
            'priority' => $kim ? 1 : 4,
            'confidence' => 100,
            'source' => 'import',
            'reason' => '',
            'needs_review' => false,
            'offer' => $job,
            'intent' => $kim ? 'kop' : '',
            'product' => $job !== '' ? jeab_malmo_hs_job_label($job) : '',
            'add_tags' => $tags,
            'assign' => (string) $decision['assignee'],
        ];
        $prepared[] = $row;
    }
    if ($prepared === [] && $existing === '') {
        return $plan + ['applied' => false];
    }
    $added = 0;
    foreach ($prepared as $row) {
        $saved = jeab_inkorg_ingest($row);
        if (!empty($saved['ok']) && empty($saved['duplicate'])) {
            $added++;
        }
    }
    $ids = [];
    $meta = jeab_inkorg_thread_meta($tid);
    foreach ((array) ($meta['hs_conversation_ids'] ?? []) as $old) {
        $old = (string) $old;
        if ($old !== '') {
            $ids[$old] = true;
        }
    }
    $ids[$cid] = true;
    $closedIds = array_values(array_filter(array_map('strval', (array) ($meta['malmo_hs_closed_ids'] ?? []))));
    jeab_inkorg_thread_write($tid, function (array $t) use ($kim, $decision, $contactId, $email, $cid, $ids, $closedIds) {
        $t['hs_conversation_id'] = $cid;
        if (!$kim) {
            $t['status'] = 'closed';
            $t['import_source'] = JEAB_HS_IMPORT_SOURCE;
        } else {
            $t['status'] = (string) ($t['status'] ?? 'open');
            if (!in_array($t['status'], ['open', 'waiting_customer', 'waiting_supplier'], true)) {
                $t['status'] = 'open';
            }
            $t['malmo_hs_kim'] = true;
            unset($t['no_auto']);
            $t['assignee'] = (string) ($t['assignee'] ?? '') !== '' ? (string) $t['assignee'] : (string) $decision['assignee'];
            $t['tags'] = array_values(array_unique(array_merge((array) ($t['tags'] ?? []), $decision['tags'])));
            $t['hs_conversation_id'] = $cid;
            $t['hs_conversation_ids'] = array_keys($ids);
            $t['malmo_hs_closed_ids'] = $closedIds;
        }
        $t = jeab_malmo_hs_stamp_link($t, $contactId, $email);
        $t['company'] = 'malmo';
        $t['updated_at'] = date('c');
        return $t;
    });
    $didClose = false;
    $fillReady = false;
    $closeCandidate = $kim && $closeEnabled && !jeab_malmo_hs_hs_closed($conversation) && !in_array($cid, $closedIds, true);
    if ($kim && !jeab_malmo_hs_hs_closed($conversation)) {
        $name = trim((string) ($hint['name'] ?? ''));
        $filled = ['offer_ready' => false, 'reminder_ready' => false, 'error' => 'fyllning'];
        try {
            $got = jeab_malmo_hs_fill($tid, $cid, $decision, $email, $name, $contactId);
            if (is_array($got)) {
                $filled = $got + $filled;
            }
        } catch (Throwable $e) {
            unset($e);
        }
        $fillReady = !empty($filled['offer_ready']) && !empty($filled['reminder_ready']);
        // Planfelet gäller bara när stängning var på. En avstängd stängning ska inte fälla hela synken.
        if ($closeCandidate && !$fillReady) {
            $why = trim((string) ($filled['error'] ?? ''));
            $plan['error'] = $why !== '' ? $why : 'saknas';
        }
        $plan = jeab_malmo_hs_plan_row($conversation, $closeEnabled) + $plan;
    }
    if ($closeCandidate && $fillReady) {
        $remote = jeab_malmo_hs_close_remote($cid);
        if (!empty($remote['ok'])) {
            $closedIds[] = $cid;
            jeab_inkorg_thread_write($tid, function (array $t) use ($closedIds) {
                $t['malmo_hs_closed_ids'] = $closedIds;
                $t['status'] = 'closed';
                $t['malmo_hs_handled'] = true;
                $t['updated_at'] = date('c');
                return $t;
            });
            $didClose = true;
        } else {
            $plan['error'] = (string) ($remote['error'] ?? 'hs_svar');
        }
    }
    $plan['applied'] = true;
    $plan['merge'] = $existing !== '';
    $plan['thread_id'] = $tid;
    $plan['added'] = $added;
    $plan['closed_now'] = $didClose;
    $plan['contact_id'] = $contactId;
    return $plan;
}

function jeab_malmo_hs_sync(array $in): array
{
    $dry = jeab_hs_import_dry($in);
    $settings = jeab_malmo_hs_settings();
    if (!$dry && !$settings['sync']) {
        return ['ok' => true, 'dry' => false, 'skipped' => 'av', 'conversations' => []];
    }
    return jeab_hs_import_with_lock(static function () use ($in): array {
        return jeab_malmo_hs_sync_locked($in);
    });
}

function jeab_malmo_hs_public_row(array $row, bool $closeEnabled): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'decision' => (string) ($row['decision'] ?? ''),
        'reason' => (string) ($row['reason'] ?? ''),
        'has_reminder' => !empty($row['has_reminder']),
        'has_offer' => !empty($row['has_offer']),
        'would_send_offer' => !empty($row['would_send_offer']),
        'would_create_reminder' => !empty($row['would_create_reminder']),
        'would_close' => !empty($row['would_close']),
        'would_auto_quote' => !empty($row['would_auto_quote']),
        'template' => (string) ($row['template'] ?? ''),
        'sender' => (string) ($row['sender'] ?? ''),
        'classification' => (string) ($row['classification'] ?? ''),
        'target' => (string) ($row['target'] ?? ''),
        'merge' => !empty($row['merge']),
        'close' => !empty($row['close']) && $closeEnabled,
        'skipped' => (string) ($row['skipped'] ?? ''),
    ];
}

function jeab_malmo_hs_sync_locked(array $in): array
{
    $dry = jeab_hs_import_dry($in);
    $settings = jeab_malmo_hs_settings();
    $closeEnabled = $settings['close'];
    if (!$dry) {
        $blocked = jeab_malmo_hs_since_block($in);
        if ($blocked !== '') {
            return ['ok' => false, 'dry' => false, 'error' => $blocked, 'conversations' => []];
        }
    }
    try {
        $conversations = jeab_malmo_hs_conversations($in, $dry);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'dry' => $dry,
            'error' => function_exists('jeab_hs_import_safe_error') ? jeab_hs_import_safe_error($e) : 'hs_fel',
            'conversations' => [],
        ];
    }
    if ($dry) {
        $rows = [];
        foreach ($conversations as $conversation) {
            if (is_array($conversation)) {
                $rows[] = jeab_malmo_hs_public_row(jeab_malmo_hs_plan_row($conversation, $closeEnabled, $in), $closeEnabled);
            }
        }
        return [
            'ok' => true,
            'dry' => true,
            'sync' => $settings['sync'],
            'close_enabled' => $closeEnabled,
            'conversations' => $rows,
        ];
    }
    $cp = [];
    $rows = [];
    $errors = [];
    foreach ($conversations as $conversation) {
        if (!is_array($conversation)) {
            continue;
        }
        try {
            $row = jeab_malmo_hs_apply($conversation, $closeEnabled, $cp, $in);
        } catch (Throwable $e) {
            $errors[] = function_exists('jeab_hs_import_safe_error') ? jeab_hs_import_safe_error($e) : 'hs_fel';
            continue;
        }
        if (!empty($row['error'])) {
            $errors[] = (string) $row['error'];
        }
        unset($row['thread_id'], $row['contact_id'], $row['added'], $row['applied'], $row['closed_now'], $row['error']);
        $rows[] = jeab_malmo_hs_public_row($row, $closeEnabled);
    }
    return [
        'ok' => $errors === [],
        'dry' => false,
        'sync' => true,
        'close_enabled' => $closeEnabled,
        'conversations' => $rows,
        'errors' => array_slice($errors, 0, 20),
    ];
}

function jeab_malmo_hs_background(): void
{
    if (!jeab_malmo_hs_settings()['sync']) {
        return;
    }
    try {
        $watermark = trim((string) (jeab_malmo_hs_state()['watermark'] ?? ''));
        if ($watermark === '') {
            jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'all_open' => 1]);
            return;
        }
        jeab_malmo_hs_sync(['dry' => 0, 'limit' => 15, 'since' => 1]);
    } catch (Throwable $e) {
        unset($e);
    }
}

function jeab_malmo_hs_settings_public(): array
{
    $s = jeab_malmo_hs_settings();
    return [
        'ok' => true,
        'malmo_hs_since' => $s['since'],
        'malmo_hs_skip' => $s['skip'],
        'malmo_hs_sync' => $s['sync'],
        'malmo_hs_close' => $s['close'],
    ];
}

function jeab_malmo_hs_bool_strict(mixed $raw): ?bool
{
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_int($raw) && ($raw === 0 || $raw === 1)) {
        return $raw === 1;
    }
    if (is_string($raw)) {
        $s = strtolower(trim($raw));
        if (in_array($s, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
    }
    return null;
}

/**
 * Hook inbox.malmo_hs_settings. Utan de fyra nycklarna: läsning.
 * Sparar bara sedan, spärrlista, synk och stängning, samma som Regler › Malmö.
 */
function jeab_malmo_hs_settings_hook(array $in): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.'];
    }
    $keys = ['malmo_hs_since', 'malmo_hs_skip', 'malmo_hs_sync', 'malmo_hs_close'];
    $present = false;
    foreach ($keys as $key) {
        if (array_key_exists($key, $in)) {
            $present = true;
            break;
        }
    }
    if (!$present) {
        return jeab_malmo_hs_settings_public();
    }
    $save = [];
    if (array_key_exists('malmo_hs_since', $in)) {
        $raw = trim((string) $in['malmo_hs_since']);
        if ($raw === '') {
            $save['malmo_hs_since'] = '';
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $save['malmo_hs_since'] = $raw;
        } else {
            return ['ok' => false, 'error' => 'since ska vara ett datum.'];
        }
    }
    foreach (['malmo_hs_sync', 'malmo_hs_close'] as $key) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $on = jeab_malmo_hs_bool_strict($in[$key]);
        if ($on === null) {
            return ['ok' => false, 'error' => $key . ' ska vara sant eller falskt.'];
        }
        $save[$key] = $on ? '1' : '0';
    }
    if (array_key_exists('malmo_hs_skip', $in)) {
        $save['malmo_hs_skip'] = $in['malmo_hs_skip'];
    }
    jeab_malmo_hs_settings_save($save);
    return jeab_malmo_hs_settings_public();
}

function jeab_malmo_hsi_quote_cause(): string
{
    return 'hsi-kopior har källan helpscout_import och no_auto, så inkorg_auto hoppar över dem innan någon offert. malmo_hs_sync läser bara live Help Scout, inte hsi-filerna. Är malmo_hs_since tomt söker synken bara öppna ärenden, så stängda batteriärenden från 7/10, 14/10 och 17/10 kommer inte med. Saknas en produktetikett sätter importen typen Information, och den typen är inte Ny lead.';
}

/**
 * inbox.malmo_hsi_quotes. Bara läsning. dry=0 nekas. Inget skickas.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_malmo_hsi_quotes(array $in): array
{
    if (array_key_exists('dry', $in)) {
        $dry = $in['dry'];
        if ($dry === 0 || $dry === '0' || $dry === false) {
            return ['ok' => false, 'dry' => 1, 'error' => 'bara_dry', 'cause' => jeab_malmo_hsi_quote_cause(), 'rows' => []];
        }
    }
    $days = 14;
    if (array_key_exists('days', $in) && $in['days'] !== '' && $in['days'] !== null) {
        $days = (int) $in['days'];
        if ($days < 1) {
            $days = 14;
        }
        $days = min(90, $days);
    }
    $wantDays = [];
    if (isset($in['dates']) && is_array($in['dates'])) {
        foreach ($in['dates'] as $day) {
            $day = trim((string) $day);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $wantDays[$day] = true;
            }
        }
    }
    $cut = time() - $days * 86400;
    $settings = jeab_malmo_hs_settings();
    $since = (string) ($settings['since'] ?? '');
    $skip = array_map('strval', (array) ($settings['skip'] ?? []));
    $rows = [];
    if (!function_exists('jeab_hs_import_index') || !function_exists('jeab_hs_import_read_conv')) {
        return ['ok' => true, 'dry' => 1, 'days' => $days, 'cause' => jeab_malmo_hsi_quote_cause(), 'count' => 0, 'rows' => []];
    }
    foreach ((array) (jeab_hs_import_index()['by_id'] ?? []) as $tid => $row) {
        if (!is_array($row) || (string) ($row['company'] ?? '') !== 'malmo') {
            continue;
        }
        $conv = jeab_hs_import_read_conv((string) $tid);
        if (!is_array($conv)) {
            continue;
        }
        $offer = '';
        $type = (string) ($row['labels']['type'] ?? '');
        $at = '';
        foreach ((array) ($conv['messages'] ?? []) as $message) {
            if (!is_array($message) || !empty($message['internal_note'])) {
                continue;
            }
            if ($at === '') {
                $at = (string) ($message['received_at'] ?? $message['date'] ?? '');
            }
            $labels = (array) ($message['labels'] ?? []);
            if ($type === '' && (string) ($labels['type'] ?? '') !== '') {
                $type = (string) $labels['type'];
            }
            if ($offer === '' && function_exists('jeab_inkorg_purchase_offer')) {
                $offer = jeab_inkorg_purchase_offer($message);
            }
        }
        if (!in_array($offer, ['batteri', 'laddbox'], true)) {
            continue;
        }
        $ts = strtotime($at);
        if ($ts === false) {
            continue;
        }
        $day = date('Y-m-d', $ts);
        if ($wantDays !== []) {
            if (!isset($wantDays[$day])) {
                continue;
            }
        } elseif ($ts < $cut) {
            continue;
        }
        $status = (string) ($row['status'] ?? $conv['status'] ?? '');
        $hsId = (string) ($row['hs_conversation_id'] ?? '');
        $number = (string) ($row['hs_number'] ?? '');
        $reasons = ['hsi_no_auto'];
        if ($type !== 'Ny lead') {
            $reasons[] = 'type_not_lead';
        }
        if ($since === '' && in_array($status, ['closed', 'spam'], true)) {
            $reasons[] = 'since_open_only';
        }
        if ($since !== '' && strcmp($day, $since) < 0) {
            $reasons[] = 'before_since';
        }
        if ($number !== '' && in_array($number, $skip, true)) {
            $reasons[] = 'skip_list';
        }
        if ($hsId !== '' && in_array($hsId, $skip, true)) {
            $reasons[] = 'skip_list';
        }
        $rows[] = [
            'thread_id' => (string) $tid,
            'hs_id' => $hsId !== '' ? $hsId : $number,
            'day' => $day,
            'type' => $type === '' ? 'Information' : $type,
            'offer' => $offer,
            'reasons' => array_values(array_unique($reasons)),
            'would_send' => false,
        ];
    }
    usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['day'], (string) $a['day']) ?: strcmp((string) $a['thread_id'], (string) $b['thread_id']));
    return [
        'ok' => true,
        'dry' => 1,
        'days' => $days,
        'cause' => jeab_malmo_hsi_quote_cause(),
        'count' => count($rows),
        'rows' => $rows,
    ];
}
