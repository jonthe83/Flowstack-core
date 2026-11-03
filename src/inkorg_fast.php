<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_inbox_strip.php';
require_once __DIR__ . '/inkorg_trace.php';

/**
 * Inkorgens lista: en vy i taget, 50 rader (Historik 20), från en lätt projektion.
 * Sidan visar en cachad lista direkt och hämtar om den i bakgrunden när den är äldre än några sekunder.
 * Ingen kundtext skrivs till loggen.
 */

const JEAB_INKORG_FAST_FRESH = 20;

/** Räknarcache. Nyckeln följer inte meddelandenas fingerprint. Efter den här tiden räknas bara ix_part=counts om. */
const JEAB_INKORG_COUNT_TTL = 60;
const JEAB_INKORG_FAST_STALE = 120;

function jeab_inkorg_fast_dir(): string
{
    $dir = jeab_inkorg_dir() . '/.cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function jeab_inkorg_fast_page_size(array $view, string $q): int
{
    if ($q === '') {
        if (function_exists('jeab_inkorg_view_is_historik') && jeab_inkorg_view_is_historik($view)) {
            return 20;
        }
    }

    return 50;
}

/** Laddar-indikator när cachen saknar nyckeln. Aldrig streck eller 0 som platshållare. */
const JEAB_INKORG_COUNT_PENDING = '…';

function jeab_inkorg_count_label(array $counts, string $id): string
{
    if ($id === '' || !array_key_exists($id, $counts)) {
        return JEAB_INKORG_COUNT_PENDING;
    }
    $n = $counts[$id];
    if ($n === null || $n === '' || !is_numeric($n)) {
        return JEAB_INKORG_COUNT_PENDING;
    }
    if ((int) $n < 0) {
        return JEAB_INKORG_COUNT_PENDING;
    }

    return (string) (int) $n;
}

/** Regler och Vyer. De får inte bygga trådar eller läsa importindexet. */
function jeab_inkorg_settings_request(): bool
{
    $page = strtolower(trim((string) ($_GET['p'] ?? '')));

    return $page === 'inkorg-regler' || $page === 'inkorg-vyer';
}

/** @param array<string, mixed> $bag */
function jeab_inkorg_counts_all_zero(array $bag): bool
{
    $counts = $bag['counts'] ?? null;
    if (!is_array($counts) || $counts === []) {
        return false;
    }
    foreach ($counts as $n) {
        if ((int) $n !== 0) {
            return false;
        }
    }

    return true;
}

/**
 * Sidan har redan rader i vyn. En noll-cache som inte är bekräftad ska inte ritas.
 *
 * @param array<string, mixed> $bag
 * @return array<string, mixed>
 */
function jeab_inkorg_counts_for_page(array $bag, int $listTotal): array
{
    if ($listTotal > 0 && jeab_inkorg_counts_all_zero($bag) && empty($bag['settled'])) {
        $bag['counts'] = [];
        $bag['fresh'] = 0;
    }

    return $bag;
}

/** Billig förhandsrad. Rör inte rensningen och läser inte andra mejl. */
function jeab_inkorg_list_snippet(array $msg, int $len = 120): string
{
    $raw = (string) ($msg['body'] ?? $msg['snippet'] ?? '');
    $text = trim((string) (preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? ''));

    return jeab_inkorg_snippet($text, $len);
}

function jeab_inkorg_fast_who(array $t): string
{
    $last = (array) ($t['last'] ?? []);
    $from = (string) ($t['first']['from'] ?? $last['from'] ?? '');
    if ($from !== '' && function_exists('jeab_inkorg_party') && function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($from)) {
        $party = jeab_inkorg_party($t);
        if ((string) ($party['email'] ?? '') !== '') {
            return (string) ($party['name'] !== '' ? $party['name'] : $party['email']);
        }
    }
    $name = (string) (($last['from_name'] ?? '') !== '' ? $last['from_name'] : ($last['from'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($t['customer_name'] ?? ''));
    }

    return $name;
}

/**
 * @return array{rows: list<array<string, mixed>>, sources: array<string, int>}
 */
function jeab_inkorg_fast_project(): array
{
    $fp = jeab_inkorg_messages_fp();
    if (isset($GLOBALS['_JEAB_INKORG_PROJ']) && is_array($GLOBALS['_JEAB_INKORG_PROJ']) && ($GLOBALS['_JEAB_INKORG_PROJ_FP'] ?? '') === $fp) {
        return $GLOBALS['_JEAB_INKORG_PROJ'];
    }
    $built = jeab_inkorg_fast_project_build($fp);
    $GLOBALS['_JEAB_INKORG_PROJ'] = $built;
    $GLOBALS['_JEAB_INKORG_PROJ_FP'] = $fp;

    return $built;
}

/**
 * @return array{rows: list<array<string, mixed>>, sources: array<string, int>}
 */
function jeab_inkorg_fast_project_build(string $fp): array
{
    unset($fp);
    $off = [];
    $mid = [];
    $groups = [];
    $sources = [];
    $placeByTid = [];
    $bodies = [];
    foreach (jeab_inkorg_messages() as $m) {
        if (!is_array($m)) {
            continue;
        }
        $tid = (string) ($m['thread_id'] ?? $m['id'] ?? '');
        if ($tid === '') {
            continue;
        }
        $src = (string) ($m['source'] ?? '');
        $sources[$src] = ($sources[$src] ?? 0) + 1;
        $id = (string) ($m['id'] ?? '');
        if ($id !== '') {
            $mid[$id] = $tid;
        }
        $off[$tid] = [[0, 1]];
        $bodies[$tid][] = $m;
        $plain = trim((string) (preg_replace('/\s+/u', ' ', strip_tags((string) ($m['body'] ?? ''))) ?? ''));
        $placeBlob = (string) ($m['subject'] ?? '') . "\n" . (string) ($m['body'] ?? '') . "\n" . (string) ($m['body_text'] ?? '');
        if (!str_starts_with($src, 'outbound') && jeab_inkorg_place_is_malmo($placeBlob)) {
            $placeByTid[$tid][] = $m;
        }
        unset($m['body'], $m['body_text'], $m['headers'], $m['html']);
        $m['_plain'] = mb_substr($plain, 0, 500);
        $groups[$tid][] = $m;
    }
    unset($GLOBALS['_JEAB_INKORG_MSGS'], $GLOBALS['_JEAB_INKORG_MSGS_FP']);
    $stored = [];
    foreach ($bodies as $tid => $list) {
        $stored[$tid] = array_reverse($list);
    }
    $GLOBALS['_JEAB_INKORG_PROJ_BODIES'] = $stored;
    $GLOBALS['_JEAB_INKORG_PROJ_OFF'] = $off;
    $GLOBALS['_JEAB_INKORG_PROJ_MID'] = $mid;
    $meta = jeab_inkorg_threads_meta();
    $rows = [];
    foreach ($groups as $tid => $newestFirst) {
        $msgs = array_reverse($newestFirst);
        $plains = [];
        foreach ($msgs as $i => $m) {
            $plains[] = (string) ($m['_plain'] ?? '');
            unset($msgs[$i]['_plain']);
        }
        $row = jeab_inkorg_thread_row((string) $tid, $msgs, (array) ($meta[$tid] ?? []));
        if (!empty($placeByTid[$tid])) {
            $fullById = [];
            foreach ($placeByTid[$tid] as $full) {
                $fullById[(string) ($full['id'] ?? '')] = $full;
            }
            $withBody = $msgs;
            foreach ($withBody as $i => $one) {
                $midKey = (string) ($one['id'] ?? '');
                if ($midKey !== '' && isset($fullById[$midKey])) {
                    $withBody[$i] = $fullById[$midKey];
                }
            }
            $mailbox = jeab_inkorg_thread_company([
                'id' => (string) $tid,
                'brand' => (string) ($row['brand'] ?? ''),
                'messages' => $withBody,
                'assignee' => (string) ($row['assignee'] ?? ''),
                'drafts' => (array) ($row['drafts'] ?? []),
            ]);
            $row['company'] = function_exists('jeab_company_thread_shown')
                ? jeab_company_thread_shown($row, $mailbox)
                : $mailbox;
            unset($placeByTid[$tid], $withBody, $fullById);
        }
        $newest = $plains === [] ? '' : (string) $plains[array_key_last($plains)];
        $row['list_snippet'] = jeab_inkorg_snippet($newest, 120);
        $hay = $plains;
        foreach ($msgs as $m) {
            $hay[] = (string) ($m['subject'] ?? '');
            $hay[] = (string) ($m['from'] ?? '');
            $hay[] = (string) ($m['from_name'] ?? '');
        }
        $row['search_hay'] = mb_strtolower(implode("\n", $hay));
        $row['list_type'] = (string) ($row['labels']['type'] ?? '');
        $row['list_reminder'] = jeab_inkorg_thread_has_reminder((string) $tid);
        $row['list_marks'] = jeab_inkorg_list_marks($row);
        $row['message_count'] = count($msgs);
        $row['mail_at'] = (string) ($row['last']['received_at'] ?? '');
        $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

    return ['rows' => $rows, 'sources' => $sources];
}

/** @return array{fp: string, off: array<string, list<array{0: int, 1: int}>>, mid: array<string, string>} */
function jeab_inkorg_fast_index(): array
{
    $fp = jeab_inkorg_messages_fp();
    if (($GLOBALS['_JEAB_INKORG_PROJ_FP'] ?? '') !== $fp) {
        jeab_inkorg_fast_project();
    }
    $off = is_array($GLOBALS['_JEAB_INKORG_PROJ_OFF'] ?? null) ? $GLOBALS['_JEAB_INKORG_PROJ_OFF'] : [];
    $mid = is_array($GLOBALS['_JEAB_INKORG_PROJ_MID'] ?? null) ? $GLOBALS['_JEAB_INKORG_PROJ_MID'] : [];

    return ['fp' => $fp, 'off' => $off, 'mid' => $mid];
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_fast_read_bodies(string $tid): array
{
    jeab_inkorg_fast_index();
    $rows = $GLOBALS['_JEAB_INKORG_PROJ_BODIES'][$tid] ?? null;

    return is_array($rows) ? array_values($rows) : [];
}

function jeab_inkorg_fast_open(string $id): ?array
{
    if ($id === '') {
        return null;
    }
    if (function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
        $store = jeab_inkorg_pdo_store();
        if ($store instanceof JeabPdoInkorgStore) {
            $row = jeab_inkorg_sql_open($store, $id);
            if (is_array($row)) {
                jeab_inkorg_trace_source('thread_sql');

                return function_exists('jeab_inkorg_thread_row_allowed') && !jeab_inkorg_thread_row_allowed($row) ? null : $row;
            }
        } else {
            jeab_inkorg_trace_event('fast_open.sql_store_null_json_index');
        }
    }
    $jsonReserve = function_exists('jeab_inkorg_json_reserve_closed') && jeab_inkorg_json_reserve_closed();
    if ($jsonReserve) {
        if (function_exists('jeab_hs_import_open_thread')) {
            $imported = jeab_hs_import_open_thread($id);
            if (is_array($imported)) {
                jeab_inkorg_trace_source('thread_import');

                return function_exists('jeab_inkorg_thread_row_allowed') && !jeab_inkorg_thread_row_allowed($imported) ? null : $imported;
            }
        }

        return null;
    }
    $idx = jeab_inkorg_fast_index();
    $tid = isset($idx['off'][$id]) ? $id : (string) ($idx['mid'][$id] ?? '');
    if ($tid !== '') {
        $msgs = jeab_inkorg_fast_read_bodies($tid);
        if ($msgs !== []) {
            jeab_inkorg_trace_source('thread_json');
            $row = jeab_inkorg_thread_row($tid, $msgs, (array) (jeab_inkorg_threads_meta()[$tid] ?? []));
            return function_exists('jeab_inkorg_thread_row_allowed') && !jeab_inkorg_thread_row_allowed($row) ? null : $row;
        }
    }
    if (function_exists('jeab_hs_import_open_thread')) {
        $imported = jeab_hs_import_open_thread($id);
        if (is_array($imported)) {
            jeab_inkorg_trace_source('thread_import');

            return function_exists('jeab_inkorg_thread_row_allowed') && !jeab_inkorg_thread_row_allowed($imported) ? null : $imported;
        }
    }

    return null;
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_fast_light(): array
{
    if (function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
        $sqlRows = jeab_inkorg_sql_light();
        jeab_inkorg_trace_source('sql_light');

        return function_exists('jeab_contacts_inbox_apply_labels') ? jeab_contacts_inbox_apply_labels($sqlRows) : $sqlRows;
    }
    jeab_inkorg_trace_source('json_light');
    $fp = jeab_inkorg_messages_fp() . '|' . jeab_inkorg_threads_fp();
    if (isset($GLOBALS['_JEAB_INKORG_LIGHT']) && is_array($GLOBALS['_JEAB_INKORG_LIGHT']) && ($GLOBALS['_JEAB_INKORG_LIGHT_FP'] ?? '') === $fp) {
        return $GLOBALS['_JEAB_INKORG_LIGHT'];
    }
    $proj = jeab_inkorg_fast_project();
    $meta = jeab_inkorg_threads_meta();
    $rows = [];
    foreach ($proj['rows'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = (string) ($row['id'] ?? '');
        $m = (array) ($meta[$tid] ?? []);
        $row['status'] = (string) ($m['status'] ?? $row['status'] ?? 'open');
        $row['tags'] = (array) ($m['tags'] ?? $row['tags'] ?? []);
        $row['test_lead'] = !empty($m['test_lead']) || !empty($row['test_lead']);
        $row['manual_tags'] = array_values(array_map('strval', (array) ($m['manual_tags'] ?? $row['manual_tags'] ?? [])));
        $row['assignee'] = (string) ($m['assignee'] ?? $row['assignee'] ?? '');
        $row['drafts'] = array_values((array) ($m['drafts'] ?? $row['drafts'] ?? []));
        $row['read'] = (array) ($m['read'] ?? $row['read'] ?? []);
        $row['malmo_hs_kim'] = !empty($m['malmo_hs_kim']) || !empty($row['malmo_hs_kim']);
        $row['deleted_at'] = (string) ($m['deleted_at'] ?? '');
        $row['deleted'] = $row['deleted_at'] !== '';
        $row['updated_at'] = jeab_inkorg_thread_list_at($m, (string) ($row['mail_at'] ?? $row['last']['received_at'] ?? ''));
        $row['customer_email'] = (string) ($m['customer_email'] ?? $row['customer_email'] ?? '');
        $row['customer_name'] = (string) ($m['customer_name'] ?? $row['customer_name'] ?? '');
        $row['contact_id'] = (string) ($m['contact_id'] ?? $row['contact_id'] ?? '');
        $row['park_until'] = (string) ($m['park_until'] ?? $m['waiting_until'] ?? $row['park_until'] ?? '');
        $stamp = jeab_inkorg_closed_stamp($m);
        if ($stamp !== '') {
            $row['closed_at'] = $stamp;
        } else {
            unset($row['closed_at'], $row['closedAt'], $row['hs_closed_at']);
        }
        if (isset($m['quote_sent']) || isset($row['quote_sent'])) {
            $row['quote_sent'] = jeab_inkorg_quote_sent_public((array) ($m['quote_sent'] ?? []));
        }
        $row['list_type'] = (string) ($row['labels']['type'] ?? '');
        $row['list_reminder'] = jeab_inkorg_thread_has_reminder($tid);
        $row['list_marks'] = jeab_inkorg_list_marks($row);
        $rows[] = $row;
    }
    usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
    if (function_exists('jeab_contacts_inbox_apply_labels')) {
        $rows = jeab_contacts_inbox_apply_labels($rows);
    }
    $GLOBALS['_JEAB_INKORG_LIGHT'] = $rows;
    $GLOBALS['_JEAB_INKORG_LIGHT_FP'] = $fp;
    $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] = $proj['sources'];

    return $rows;
}

/**
 * @param list<array<string, mixed>> $menu
 */
function jeab_inkorg_fast_count_key(array $menu, string $box, string $me): string
{
    $bits = [];
    foreach ($menu as $v) {
        if (!is_array($v)) {
            continue;
        }
        $bits[] = (string) ($v['id'] ?? '') . ':' . (string) json_encode($v['filter'] ?? []);
    }

    $scope = function_exists('jeab_inkorg_list_scope') ? jeab_inkorg_list_scope() : 'allas';

    // Lagringsläget ingår. En påse räknad på JSON-vägen får inte ritas när sidan läser SQL.
    return md5('count-same|' . JEAB_INKORG_COUNT_VER . '|' . jeab_inkorg_count_mode() . '|' . $box . '|' . $me . '|' . $scope . '|' . implode(',', $bits));
}

/** Version på räknarpåsen. Höjs när påsens innehåll eller nyckel ändras. */
if (!defined('JEAB_INKORG_COUNT_VER')) {
    define('JEAB_INKORG_COUNT_VER', 'v2');
}

/** sql eller json. Samma svar som sidans väg (jeab_inkorg_use_sql). */
function jeab_inkorg_count_mode(): string
{
    return function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql() ? 'sql' : 'json';
}

/**
 * Spår för proben: vilka räknarnycklar som lästes och varifrån (apcu, sql, miss).
 *
 * @param array<string, mixed> $row
 */
function jeab_inkorg_count_trace(array $row): void
{
    $from = preg_replace('/[^a-z0-9_]/i', '', (string) ($row['from'] ?? ''));
    if ($from !== '') {
        jeab_inkorg_trace_source('counts_' . $from . (!empty($row['rejected']) ? '_rejected' : ''));
    }
    if (empty($GLOBALS['_JEAB_IXC_TRACE_ON'])) {
        return;
    }
    $cur = $GLOBALS['_JEAB_IXC_TRACE'] ?? [];
    if (!is_array($cur)) {
        $cur = [];
    }
    if (count($cur) < 40) {
        $cur[] = $row;
    }
    $GLOBALS['_JEAB_IXC_TRACE'] = $cur;
}

function jeab_inkorg_compute_mark(string $what): void
{
    $cur = $GLOBALS['_JEAB_IX_COMPUTE'] ?? [];
    if (!is_array($cur)) {
        $cur = [];
    }
    if (!in_array($what, $cur, true)) {
        $cur[] = $what;
    }
    $GLOBALS['_JEAB_IX_COMPUTE'] = $cur;
}

function jeab_inkorg_compute_header_value(): string
{
    $cur = $GLOBALS['_JEAB_IX_COMPUTE'] ?? [];

    return is_array($cur) && $cur !== [] ? implode(',', $cur) : '0';
}

function jeab_inkorg_compute_header(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Ix-Compute: ' . jeab_inkorg_compute_header_value());
}

function jeab_inkorg_sql_may_rebuild(): bool
{
    if (!empty($GLOBALS['_JEAB_INKORG_ALLOW_REBUILD'])) {
        return true;
    }
    $part = (string) ($GLOBALS['_JEAB_INKORG_PART'] ?? '');
    if ($part === '' && function_exists('jeab_inkorg_ix_part')) {
        $part = jeab_inkorg_ix_part();
    }

    return $part === 'counts';
}

/** Släpp sessionen så nästa klick (bolagsbyte, Historik) inte väntar på den här listan. */
function jeab_inkorg_release_session(): void
{
    if (PHP_SAPI === 'cli' && empty($GLOBALS['_JEAB_RELEASE_SESSION_TEST'])) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return;
    }
    session_write_close();
}

function jeab_inkorg_fast_count_store(): ?JeabPdoInkorgStore
{
    if (!function_exists('jeab_inkorg_use_sql') || !jeab_inkorg_use_sql()) {
        return null;
    }
    $store = jeab_inkorg_pdo_store();

    return $store instanceof JeabPdoInkorgStore ? $store : null;
}

/** @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}|null */
function jeab_inkorg_fast_count_read(string $key): ?array
{
    $bag = null;
    $from = 'miss';
    $mode = jeab_inkorg_count_mode();
    $rejected = '';
    // En påse utan läge, eller räknad i ett annat läge, är från före bytet. Den är en miss.
    $usable = static function (mixed $hit) use ($mode, &$rejected): bool {
        if (!is_array($hit) || !isset($hit['counts']) || !is_array($hit['counts'])) {
            return false;
        }
        if ((string) ($hit['mode'] ?? '') !== $mode || (string) ($hit['ver'] ?? '') !== JEAB_INKORG_COUNT_VER) {
            $rejected = (string) ($hit['mode'] ?? '') === '' ? 'no-mode' : 'mode:' . (string) $hit['mode'];

            return false;
        }

        return true;
    };
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $hit = apcu_fetch('jeab_ixc_' . $key, $ok);
        if ($ok && $usable($hit)) {
            $bag = $hit;
            $from = 'apcu';
        }
    }
    if ($bag === null) {
        $store = jeab_inkorg_fast_count_store();
        if ($store instanceof JeabPdoInkorgStore) {
            $hit = $store->countCacheGet($key, 6 * 3600);
            if ($usable($hit)) {
                $bag = $hit;
                $from = 'sql';
            }
        }
    }
    if ($bag === null) {
        jeab_inkorg_count_trace(['key' => substr($key, 0, 8), 'mode' => $mode, 'from' => 'miss', 'rejected' => $rejected]);

        return null;
    }
    $age = time() - (int) ($bag['at'] ?? 0);
    $bag['fresh'] = ($age >= 0 && (int) ($bag['at'] ?? 0) > 0 && $age <= JEAB_INKORG_COUNT_TTL) ? 1 : 0;
    $bag['unread'] = (int) ($bag['unread'] ?? 0);
    $bag['sources'] = is_array($bag['sources'] ?? null) ? $bag['sources'] : [];
    $bag['at'] = (int) ($bag['at'] ?? 0);
    jeab_inkorg_count_trace([
        'key' => substr($key, 0, 8),
        'mode' => $mode,
        'from' => $from,
        'age' => $bag['at'] > 0 ? max(0, time() - $bag['at']) : -1,
        'fresh' => (int) $bag['fresh'],
    ]);

    return $bag;
}

/** @param array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh?: int, at?: int} $bag */
function jeab_inkorg_fast_count_write(string $key, array $bag): void
{
    $bag['at'] = time();
    $bag['fresh'] = 1;
    $bag['mode'] = jeab_inkorg_count_mode();
    $bag['ver'] = JEAB_INKORG_COUNT_VER;
    if (function_exists('apcu_store')) {
        apcu_store('jeab_ixc_' . $key, $bag, 6 * 3600);
    }
    $store = jeab_inkorg_fast_count_store();
    if ($store instanceof JeabPdoInkorgStore) {
        $store->countCachePut($key, $bag);
    }
}

/**
 * Vykortens tal. $compute false läser bara MySQL/APCu och returnerar direkt.
 * Räkningen körs i ix_part=counts, aldrig i en klick-hämtning.
 *
 * @param list<array<string, mixed>> $light
 * @param list<array<string, mixed>> $menu
 * @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}
 */
function jeab_inkorg_fast_counts(array $light, array $menu, string $box, string $me, bool $compute = false): array
{
    $t0 = microtime(true);
    $key = jeab_inkorg_fast_count_key($menu, $box, $me);
    static $mem = [];
    if (!empty($GLOBALS['_JEAB_IXC_MEM_RESET'])) {
        // Proben kör flera delanrop i samma process. Varje del ska börja som ett eget anrop.
        $mem = [];
        unset($GLOBALS['_JEAB_IXC_MEM_RESET']);
    }
    $force = !empty($GLOBALS['_JEAB_INKORG_COUNT_FORCE']);
    if (!$force && isset($mem[$key]) && is_array($mem[$key]) && ($compute === false || !empty($mem[$key]['fresh']))) {
        jeab_inkorg_count_trace(['key' => substr($key, 0, 8), 'mode' => jeab_inkorg_count_mode(), 'from' => 'mem', 'fresh' => (int) ($mem[$key]['fresh'] ?? 0)]);
        if (function_exists('jeab_timing_span')) {
            jeab_timing_span('fast_counts', microtime(true) - $t0);
        }

        return $mem[$key];
    }
    $cached = jeab_inkorg_fast_count_read($key);
    if (!$force && is_array($cached) && ($compute === false || !empty($cached['fresh']))) {
        $sources = is_array($GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] ?? null) ? $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] : [];
        if ($sources !== []) {
            $cached['sources'] = $sources;
        }
        $mem[$key] = $cached;
        if (function_exists('jeab_timing_span')) {
            jeab_timing_span('fast_counts', microtime(true) - $t0);
        }

        return $cached;
    }
    if ($compute === false) {
        $sources = is_array($GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] ?? null) ? $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] : [];
        $counts = [];
        $unread = 0;
        $at = 0;
        if (is_array($cached)) {
            $counts = is_array($cached['counts'] ?? null) ? $cached['counts'] : [];
            $unread = (int) ($cached['unread'] ?? 0);
            if ($sources === [] && is_array($cached['sources'] ?? null)) {
                $sources = $cached['sources'];
            }
            $at = (int) ($cached['at'] ?? 0);
        }
        $empty = ['counts' => $counts, 'unread' => $unread, 'sources' => $sources, 'fresh' => 0, 'at' => $at];
        $mem[$key] = $empty;
        if (function_exists('jeab_timing_span')) {
            jeab_timing_span('fast_counts', microtime(true) - $t0);
        }

        return $empty;
    }
    jeab_inkorg_compute_mark('fast_counts');
    $GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] = (int) ($GLOBALS['_JEAB_INKORG_COUNT_COMPUTE'] ?? 0) + 1;
    $counts = [];
    $unreadFilter = ['folder' => 'inkorg', 'company' => $box];
    foreach ($menu as $v) {
        $id = (string) ($v['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $filter = (array) ($v['filter'] ?? []) + ['company' => $box];
        $folder = (string) ($filter['folder'] ?? '');
        if ($folder === 'historik' || !empty($filter['historik'])) {
            $who = (string) ($filter['assignee'] ?? '');
            $whoId = $me;
            if ($who === 'none-person') {
                $who = 'me';
                $whoId = '';
            }
            $counts[$id] = jeab_inkorg_historik_total_noddl($box, $who, $whoId, trim((string) ($filter['q'] ?? '')));
            continue;
        }
        $counts[$id] = jeab_inkorg_view_total($light, $filter);
        if ($id === 'v-inkorg') {
            $unreadFilter = $filter;
        }
    }
    $unread = 0;
    foreach ($light as $t) {
        if (!is_array($t) || !jeab_inkorg_thread_matches($t, $unreadFilter) || !jeab_inkorg_is_unread($t, $me)) {
            continue;
        }
        $unread++;
    }
    $sources = is_array($GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] ?? null) ? $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] : [];
    $bag = ['counts' => $counts, 'unread' => $unread, 'sources' => $sources, 'fresh' => 1, 'at' => time()];
    jeab_inkorg_fast_count_write($key, $bag);
    $mem[$key] = $bag;
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('fast_counts', microtime(true) - $t0);
    }

    return $bag;
}

/**
 * Vykort från cachen. Klick och sidritning räknar inte om, även om talen är gamla.
 *
 * @param list<array<string, mixed>> $menu
 * @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}
 */
function jeab_inkorg_fast_counts_ready(array $menu, string $box, string $me): array
{
    return jeab_inkorg_fast_counts([], $menu, $box, $me, false);
}

/**
 * Sidan läser cachen. En träff ritas även om den är gammal eller saknar en mapp.
 * Saknas cachen helt och listan redan är laddad räknas arbetsmapparna på de raderna
 * och Historik med COUNT. Den påsen skrivs inte. ix_part=counts fyller importen.
 *
 * @param list<array<string, mixed>> $menu
 * @param list<array<string, mixed>>|null $light
 * @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}
 */
function jeab_inkorg_page_counts(array $menu, string $box, string $me, ?array $light = null): array
{
    $ready = jeab_inkorg_fast_counts_ready($menu, $box, $me);
    $counts = is_array($ready['counts'] ?? null) ? $ready['counts'] : [];
    $zeros = jeab_inkorg_counts_all_zero($ready);
    $discard = $zeros && jeab_inkorg_list_open_count_noddl($box) > 0;
    if ($discard) {
        $counts = [];
        $ready['at'] = 0;
        $ready['fresh'] = 0;
    }
    if (!$discard && (int) ($ready['at'] ?? 0) > 0 && $counts !== [] && !$zeros) {
        if ($light !== null) {
            $ready['counts'] = jeab_inkorg_counts_fill_missing($counts, $menu, $box, $me, $light);
        }
        $ready['counts'] = jeab_inkorg_counts_publish($ready, $box, is_array($light) ? $light : [])['counts'];
        $ready['counts'] = jeab_inkorg_counts_repair_historik($ready['counts'], $menu, $box, $me);

        return $ready;
    }
    if ($light === null) {
        $light = jeab_inkorg_page_count_light($box);
    }
    $from = jeab_inkorg_page_counts_from_light($menu, $box, $me, $light);
    $from = jeab_inkorg_counts_publish($from, $box, $light);
    $from['counts'] = jeab_inkorg_counts_repair_historik(is_array($from['counts'] ?? null) ? $from['counts'] : [], $menu, $box, $me);

    return $from;
}

/**
 * Arbetsrader när sidans cache saknas. Tom lista lämnas tom. Ingen ombyggnad.
 *
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_page_count_light(string $box): array
{
    if ($box === '' || !function_exists('jeab_inkorg_use_sql') || !jeab_inkorg_use_sql() || !function_exists('jeab_inkorg_pdo_store')) {
        return [];
    }
    $store = jeab_inkorg_pdo_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        jeab_inkorg_trace_event('page_count_light.store_null');

        return [];
    }
    try {
        return $store->listWorking($box);
    } catch (Throwable $e) {
        jeab_inkorg_trace_event('page_count_light.catch', $e);

        return [];
    }
}

/**
 * Historik 0 i cachen är en miss när COUNT ser stängda rader eller arkivet.
 *
 * @param array<string, int> $counts
 * @param list<array<string, mixed>> $menu
 * @return array<string, int>
 */
function jeab_inkorg_counts_repair_historik(array $counts, array $menu, string $box, string $me): array
{
    foreach ($menu as $view) {
        if (!is_array($view)) {
            continue;
        }
        $id = (string) ($view['id'] ?? '');
        $filter = (array) ($view['filter'] ?? []);
        if ($id === '' || (string) ($filter['folder'] ?? '') !== 'historik') {
            continue;
        }
        $assignee = (string) ($filter['assignee'] ?? '');
        $who = $me;
        if ($assignee === 'none-person') {
            $assignee = 'me';
            $who = '';
        }
        $n = jeab_inkorg_historik_total_noddl($box, $assignee, $who, trim((string) ($filter['q'] ?? '')));
        $cur = array_key_exists($id, $counts) ? (int) $counts[$id] : 0;
        // En gammal liten cache (9) får inte dölja arkivet (~20 000).
        if ($n > $cur) {
            $counts[$id] = $n;
        }
    }

    return $counts;
}

/**
 * Sidomenyns vyer (Sam L, Service, …) som saknas i flikpåsen.
 * Räknas på raderna sidan redan har, annars med COUNT mot inkorg_list.
 * Importarkivet läses inte.
 *
 * @param array<string, int> $counts
 * @param list<array<string, mixed>> $light
 * @return array<string, int>
 */
function jeab_inkorg_menu_counts_merge(array $counts, array $light, string $box): array
{
    if ($box === '' || !function_exists('jeab_inkorg_views') || !function_exists('jeab_inkorg_menu_views') || !function_exists('jeab_inkorg_company_views')) {
        return $counts;
    }
    $prevSkip = $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] ?? null;
    $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = true;
    try {
        return jeab_inkorg_menu_counts_merge_rows($counts, $light, $box);
    } finally {
        if ($prevSkip === null) {
            unset($GLOBALS['_JEAB_INKORG_SKIP_IMPORT']);
        } else {
            $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = $prevSkip;
        }
    }
}

/**
 * @param array<string, int> $counts
 * @param list<array<string, mixed>> $light
 * @return array<string, int>
 */
function jeab_inkorg_menu_counts_merge_rows(array $counts, array $light, string $box): array
{
    $views = jeab_inkorg_menu_views(jeab_inkorg_company_views(jeab_inkorg_views(), $box), '');
    $need = [];
    foreach ($views as $view) {
        if (!is_array($view)) {
            continue;
        }
        $id = (string) ($view['id'] ?? '');
        if ($id === '' || array_key_exists($id, $counts)) {
            continue;
        }
        $need[] = $view;
    }
    if ($need === []) {
        return $counts;
    }
    if ($light === [] && function_exists('jeab_inkorg_pdo_store') && function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
        $store = jeab_inkorg_pdo_store();
        if ($store instanceof JeabPdoInkorgStore) {
            try {
                $light = $store->listWorking($box);
            } catch (Throwable $e) {
                jeab_inkorg_trace_event('menu_counts_merge.catch', $e);
                $light = [];
            }
        }
    }
    $still = [];
    foreach ($need as $view) {
        $id = (string) ($view['id'] ?? '');
        $filter = (array) ($view['filter'] ?? []) + ['company' => $box];
        if ((string) ($filter['folder'] ?? '') === 'historik' || !empty($filter['historik'])) {
            $counts[$id] = jeab_inkorg_settings_historik_total($filter);
            continue;
        }
        if ($light !== [] && function_exists('jeab_inkorg_view_total')) {
            $counts[$id] = jeab_inkorg_view_total($light, $filter);
            continue;
        }
        $still[] = $view;
    }
    if ($still === []) {
        return $counts;
    }
    foreach (jeab_inkorg_settings_view_counts($still, true) as $id => $n) {
        if ((int) $n < 0 || array_key_exists((string) $id, $counts)) {
            continue;
        }
        $counts[(string) $id] = (int) $n;
    }

    return $counts;
}

/**
 * Mappar som saknas i cachen räknas på raderna sidan redan har. Historik med COUNT.
 *
 * @param array<string, int> $counts
 * @param list<array<string, mixed>> $menu
 * @param list<array<string, mixed>> $light
 * @return array<string, int>
 */
function jeab_inkorg_counts_fill_missing(array $counts, array $menu, string $box, string $me, array $light): array
{
    $need = false;
    foreach ($menu as $v) {
        if (!is_array($v)) {
            continue;
        }
        $id = (string) ($v['id'] ?? '');
        if ($id !== '' && !array_key_exists($id, $counts)) {
            $need = true;
            break;
        }
    }
    if (!$need) {
        return $counts;
    }
    $filled = jeab_inkorg_page_counts_from_light($menu, $box, $me, $light);
    foreach ((array) ($filled['counts'] ?? []) as $id => $n) {
        if (!array_key_exists($id, $counts)) {
            $counts[$id] = (int) $n;
        }
    }

    return $counts;
}

/**
 * Arbetsmappar från rader som sidan redan har. Historik är en COUNT, inte importarkivet.
 * fresh och at är 0 så påsen inte blir en cacheträff.
 *
 * @param list<array<string, mixed>> $menu
 * @param list<array<string, mixed>> $light
 * @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}
 */
function jeab_inkorg_page_counts_from_light(array $menu, string $box, string $me, array $light): array
{
    $prevSkip = $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] ?? null;
    $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = true;
    try {
        $counts = [];
        $unreadFilter = ['folder' => 'inkorg', 'company' => $box];
        $store = jeab_inkorg_fast_count_store();
        foreach ($menu as $v) {
            if (!is_array($v)) {
                continue;
            }
            $id = (string) ($v['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $filter = (array) ($v['filter'] ?? []) + ['company' => $box];
            $folder = (string) ($filter['folder'] ?? '');
            if ($folder === 'historik') {
                $assignee = (string) ($filter['assignee'] ?? '');
                $meId = function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '';
                if (function_exists('jeab_inkorg_list_scope') && jeab_inkorg_list_scope() === 'egna' && function_exists('jeab_egna_me_id')) {
                    $meId = jeab_egna_me_id();
                }
                if ($assignee === 'none-person') {
                    $assignee = 'me';
                    $meId = '';
                }
                // inkorg_list är de levande stängda. Arkivet (~20 000) ligger i jeab_hs_import_index
                // och ska räknas även när inbox/inkorg_storage är json och helpscout_import är sql.
                $counts[$id] = jeab_inkorg_historik_total_noddl($box, $assignee, $meId);
                continue;
            }
            $counts[$id] = jeab_inkorg_view_total($light, $filter);
            if ($id === 'v-inkorg') {
                $unreadFilter = $filter;
            }
        }
        $unread = 0;
        foreach ($light as $t) {
            if (!is_array($t) || !jeab_inkorg_thread_matches($t, $unreadFilter) || !jeab_inkorg_is_unread($t, $me)) {
                continue;
            }
            $unread++;
        }
        $sources = is_array($GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] ?? null) ? $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] : [];

        return ['counts' => $counts, 'unread' => $unread, 'sources' => $sources, 'fresh' => 0, 'at' => 0];
    } finally {
        if ($prevSkip === null) {
            unset($GLOBALS['_JEAB_INKORG_SKIP_IMPORT']);
        } else {
            $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = $prevSkip;
        }
    }
}

/**
 * Vykort och lista räknas på samma rader. Importarkivet läggs inte på en gång till.
 *
 * @param list<array<string, mixed>> $light
 * @param list<array<string, mixed>> $menu
 * @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}
 */
function jeab_inkorg_fast_counts_same(array $light, array $menu, string $box, string $me): array
{
    $store = jeab_inkorg_fast_count_store();
    $prevSkip = $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] ?? null;
    $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = true;
    if ($store instanceof JeabPdoInkorgStore) {
        $GLOBALS['_JEAB_INKORG_COUNT_HISTORIK'] = $store;
    }
    try {
        return jeab_inkorg_fast_counts($light, $menu, $box, $me, true);
    } finally {
        unset($GLOBALS['_JEAB_INKORG_COUNT_HISTORIK']);
        if ($prevSkip === null) {
            unset($GLOBALS['_JEAB_INKORG_SKIP_IMPORT']);
        } else {
            $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = $prevSkip;
        }
    }
}

/**
 * Sidomenyns tal. Flikarnas sparade ix_part=counts vinner när id:t finns där.
 * Övriga menyvyer räknas mot inkorg_list. Importarkivet läses inte.
 *
 * @param list<array<string, mixed>> $menuViews
 * @return array<string, int>
 */
function jeab_inkorg_sidebar_saved_counts(array $menuViews): array
{
    $box = function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : 'jarfalla';
    $me = function_exists('jeab_inkorg_scope_me_id') ? jeab_inkorg_scope_me_id() : (function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '');
    $scope = function_exists('jeab_inkorg_list_scope') ? jeab_inkorg_list_scope() : 'allas';
    $tabs = function_exists('jeab_inkorg_fast_scope_menu')
        ? jeab_inkorg_fast_scope_menu($scope)
        : (function_exists('jeab_inkorg_folder_views') ? jeab_inkorg_folder_views() : []);
    $ready = jeab_inkorg_fast_counts_ready($tabs, $box, $me);
    $saved = [];
    $cached = is_array($ready['counts'] ?? null) ? $ready['counts'] : [];
    if ((int) ($ready['at'] ?? 0) > 0) {
        foreach ($cached as $id => $n) {
            if (is_numeric($n)) {
                $saved[(string) $id] = (int) $n;
            }
        }
    }
    $need = [];
    foreach ($menuViews as $view) {
        if (!is_array($view)) {
            continue;
        }
        $id = (string) ($view['id'] ?? '');
        if ($id === '' || array_key_exists($id, $saved)) {
            continue;
        }
        $need[] = $view;
    }
    $extra = $need === [] ? [] : jeab_inkorg_settings_view_counts($need, true);

    return $saved + $extra;
}

/**
 * Antal per vy från inkorg_list. Importarkivet läses inte.
 * $withBox lägger på den öppna boxen, samma som sidomenyn.
 *
 * @param list<array<string, mixed>> $views
 * @return array<string, int>
 */
function jeab_inkorg_settings_view_counts(array $views, bool $withBox = false): array
{
    $prev = $GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'] ?? null;
    $GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'] = 1;
    try {
        $box = $withBox && function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : '';
        $cacheKey = function_exists('jeab_inkorg_fast_count_key')
            ? jeab_inkorg_fast_count_key($views, $withBox ? $box : 'both', 'settings')
            : '';
        if ($cacheKey !== '' && function_exists('jeab_inkorg_fast_count_read')) {
            $cached = jeab_inkorg_fast_count_read($cacheKey);
            if (is_array($cached) && (time() - (int) ($cached['at'] ?? 0)) < 45 && is_array($cached['counts'] ?? null)) {
                $hit = [];
                foreach ($cached['counts'] as $id => $n) {
                    $hit[(string) $id] = (int) $n;
                }

                return $hit;
            }
        }
        $counts = [];
        $pending = [];
        foreach ($views as $view) {
            if (!is_array($view)) {
                continue;
            }
            $id = (string) ($view['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $filter = (array) ($view['filter'] ?? []);
            if ($withBox && $box !== '') {
                $filter += ['company' => $box];
            }
            if (jeab_inkorg_settings_historik_plain($view, $filter)) {
                $total = jeab_inkorg_settings_historik_total($filter);
                if ($total >= 0) {
                    $counts[$id] = $total;
                    continue;
                }
            }
            $pending[] = [$id, $filter];
        }
        if ($pending !== []) {
            $store = jeab_inkorg_fast_count_store();
            if ($store instanceof JeabPdoInkorgStore) {
                foreach ($store->countViewFilters($pending) as $id => $n) {
                    $counts[(string) $id] = (int) $n;
                }
            } else {
                foreach ($pending as [$id]) {
                    $counts[$id] = -1;
                }
            }
        }
        if ($cacheKey !== '' && function_exists('jeab_inkorg_fast_count_write')) {
            jeab_inkorg_fast_count_write($cacheKey, [
                'counts' => $counts,
                'unread' => 0,
                'sources' => [],
                'fresh' => 1,
                'at' => time(),
            ]);
        }

        return $counts;
    } finally {
        if (jeab_inkorg_settings_request()) {
            $GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'] = 1;
        } elseif ($prev === null) {
            unset($GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS']);
        } else {
            $GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'] = $prev;
        }
    }
}

/** @param array<string, mixed> $view @param array<string, mixed> $filter */
function jeab_inkorg_settings_historik_plain(array $view, array $filter): bool
{
    $byView = function_exists('jeab_inkorg_view_is_historik') && jeab_inkorg_view_is_historik($view);
    $byFilter = (string) ($filter['folder'] ?? '') === 'historik' || !empty($filter['historik']);
    if (!$byView && !$byFilter) {
        return false;
    }
    foreach (['tag', 'type', 'q', 'offer', 'queue'] as $key) {
        if (trim((string) ($filter[$key] ?? '')) !== '') {
            return false;
        }
    }
    foreach (['tags_any', 'types', 'types_not', 'status', 'show'] as $key) {
        if ((array) ($filter[$key] ?? []) !== []) {
            return false;
        }
    }

    return empty($filter['review']) && empty($filter['deleted']) && empty($filter['awaiting_quote']);
}

/** @param array<string, mixed> $filter */
function jeab_inkorg_settings_historik_total(array $filter): int
{
    $company = (string) ($filter['company'] ?? '');
    if ($company === 'meab') {
        $company = 'malmo';
    }
    $boxes = $company === '' ? ['jarfalla', 'malmo'] : [$company];
    $assignee = (string) ($filter['assignee'] ?? '');
    // Samma person som json-vägens Egna (sessionens kodperson räknas).
    $me = function_exists('jeab_inkorg_scope_me_id') ? jeab_inkorg_scope_me_id() : (function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '');
    if ($assignee === 'none-person') {
        $assignee = 'me';
        $me = '';
    }
    $n = 0;
    foreach ($boxes as $one) {
        $n += jeab_inkorg_historik_total_noddl($one, $assignee, $me);
    }

    return $n;
}

/**
 * Historik som sidan visar: stängda/raderade i inkorg_list plus Help Scout-arkivet
 * i jeab_hs_import_index som inte redan ligger i listan. Checkpointen
 * (jeab_hs_import_state) och inkorg_messages är inte det talet.
 * Ingen CREATE och inget schema-uppslag.
 */
function jeab_inkorg_historik_total_noddl(string $company, string $assignee = '', string $me = '', string $q = ''): int
{
    if ($company === 'meab') {
        $company = 'malmo';
    }
    $pdo = null;
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if ($store instanceof JeabPdoInkorgStore) {
        $pdo = $store->pdo();
    } elseif (function_exists('jeab_data_pdo')) {
        $pdo = jeab_data_pdo();
    }
    if (!$pdo instanceof PDO) {
        jeab_inkorg_trace_event('historik_total.no_pdo');

        return 0;
    }
    $companies = $company === 'malmo' ? ['malmo', 'meab'] : ($company === '' ? [] : [$company]);
    if ($assignee === 'me' && $me === '') {
        return 0;
    }
    $live = 0;
    if ($companies !== []) {
        try {
            $marks = implode(',', array_fill(0, count($companies), '?'));
            $where = 'company IN (' . $marks . ') AND list_status IN (\'closed\', \'deleted\')';
            $args = $companies;
            if ($assignee === 'me' || $assignee === 'others') {
                $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
                $expr = $mysql
                    ? "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.assignee')), '')"
                    : "COALESCE(json_extract(row_json, '$.assignee'), '')";
                if ($assignee === 'me') {
                    // Egna Historik: bara personens egna, inte hela otilldelade arkivet.
                    $where .= ' AND ' . $expr . " <> '' AND LOWER(" . $expr . ') = LOWER(?)';
                } else {
                    $where .= ' AND ' . $expr . " <> '' AND LOWER(" . $expr . ') <> LOWER(?)';
                }
                $args[] = $me;
            }
            $needle = mb_strtolower(trim($q));
            if ($needle !== '') {
                $where .= ' AND instr(lower(row_json), ?) > 0';
                $args[] = $needle;
            }
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM inkorg_list WHERE ' . $where);
            $stmt->execute($args);
            $live = (int) $stmt->fetchColumn();
            $GLOBALS['_JEAB_LIST_HISTORIK_ROWS'] = $live;
        } catch (Throwable $e) {
            jeab_inkorg_trace_event('historik_total.catch', $e);
            $live = 0;
        }
    }
    $imported = 0;
    if ($companies !== [] && function_exists('jeab_hs_import_historik_count_noddl')) {
        $imported = jeab_hs_import_historik_count_noddl($pdo, $companies, $assignee, $me, $q);
    }

    return $live + $imported;
}

/** Öppna rader i inkorg_list. -1 när frågan inte gick. Ingen DDL. */
function jeab_inkorg_list_open_count_noddl(string $company): int
{
    if ($company === 'meab') {
        $company = 'malmo';
    }
    $pdo = null;
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if ($store instanceof JeabPdoInkorgStore) {
        $pdo = $store->pdo();
    } elseif (function_exists('jeab_data_pdo')) {
        $pdo = jeab_data_pdo();
    }
    if (!$pdo instanceof PDO || $company === '') {
        if (!$pdo instanceof PDO) {
            jeab_inkorg_trace_event('list_open_count.no_pdo');
        }

        return -1;
    }
    $companies = $company === 'malmo' ? ['malmo', 'meab'] : [$company];
    try {
        $marks = implode(',', array_fill(0, count($companies), '?'));
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM inkorg_list WHERE company IN (' . $marks . ") AND (list_status IS NULL OR list_status = '' OR list_status NOT IN ('closed', 'deleted'))"
        );
        $stmt->execute($companies);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        jeab_inkorg_trace_event('list_open_count.catch', $e);

        return -1;
    }
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_settings_working_rows(): array
{
    $store = jeab_inkorg_fast_count_store();
    if ($store instanceof JeabPdoInkorgStore) {
        if ($store->listStamp() === '') {
            return [];
        }
        $rows = [];
        foreach (['jarfalla', 'malmo'] as $box) {
            foreach ($store->listWorking($box) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($box === 'malmo' && (string) ($row['company'] ?? '') === 'meab') {
                    $row['company'] = 'malmo';
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }
    if (!function_exists('jeab_inkorg_fast_light')) {
        return [];
    }

    return jeab_inkorg_fast_light();
}

/** @param list<array<string, mixed>> $rows @param array<string, mixed> $filter */
function jeab_inkorg_settings_count_rows(array $rows, array $filter): int
{
    $n = 0;
    foreach ($rows as $row) {
        if (is_array($row) && function_exists('jeab_inkorg_thread_matches') && jeab_inkorg_thread_matches($row, $filter)) {
            $n++;
        }
    }

    return $n;
}

/**
 * Sök ska även se stängda rader i Historik. Arbetsvyns lista har dem inte.
 *
 * @param list<array<string, mixed>> $light
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_search_with_historik(array $light, string $box, string $q = '', int $limit = 0): array
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$store instanceof JeabPdoInkorgStore || !function_exists('jeab_inkorg_use_sql') || !jeab_inkorg_use_sql()) {
        return $light;
    }
    $seen = [];
    foreach ($light as $row) {
        if (is_array($row) && (string) ($row['id'] ?? '') !== '') {
            $seen[(string) $row['id']] = true;
        }
    }
    $q = trim($q);
    $limit = $limit > 0 ? $limit : 50;
    $closed = $q !== '' ? $store->listHistorikMatching($box, $q, $limit) : [];
    foreach (jeab_inkorg_fast_normalize_company($closed, $box) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $light[] = $row;
    }

    return $light;
}

/**
 * Arbetsraderna listan visar. Help Scout-bolag får även kopiorna från importarkivet,
 * samma filter som vykorten. Ett levande bolag läser inte arkivet.
 *
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_fast_working_light(string $box): array
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    $sql = $store instanceof JeabPdoInkorgStore && function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql();
    if (!$sql) {
        if (function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
            jeab_inkorg_trace_event('working_light.sql_store_null_light');
        }

        return jeab_inkorg_fast_with_imports(jeab_inkorg_fast_company_rows(jeab_inkorg_fast_light(), $box), $box);
    }
    jeab_inkorg_sql_list_sync($store, '');
    $rows = $store->listWorking($box);
    jeab_inkorg_trace_source('sql_working_light');
    if ($rows === [] && $store->listStamp() === '') {
        jeab_inkorg_trace_event('working_light.empty_no_stamp_light');
        $rows = jeab_inkorg_fast_company_rows(jeab_inkorg_fast_light(), $box);
    }

    return jeab_inkorg_fast_with_imports(jeab_inkorg_fast_normalize_company($rows, $box), $box);
}

/** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
function jeab_inkorg_fast_company_rows(array $rows, string $box): array
{
    if ($box === '') {
        return $rows;
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $company = (string) ($row['company'] ?? '');
        if ($company === 'meab') {
            $company = 'malmo';
        }
        if ($company === $box) {
            $out[] = $row;
        }
    }

    return $out;
}

/** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
function jeab_inkorg_fast_normalize_company(array $rows, string $box): array
{
    if ($box !== 'malmo') {
        return $rows;
    }
    foreach ($rows as $i => $row) {
        if (is_array($row) && (string) ($row['company'] ?? '') === 'meab') {
            $rows[$i]['company'] = 'malmo';
        }
    }

    return $rows;
}

/**
 * Indexets stämpel: filens storlek och tid, plus generationen som append ökar.
 * Samma stämpel betyder att den mergade mängden inte behöver läsas om.
 */
function jeab_inkorg_fast_import_stamp(): string
{
    $path = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/index.jsonl' : '';
    $file = ($path !== '' && is_file($path)) ? ((string) filesize($path) . ':' . (string) filemtime($path)) : '0';

    return $file . ':' . (string) ($GLOBALS['_JEAB_HS_IMPORT_INDEX_GEN'] ?? 0);
}

/** Bara en lagrad status som är exakt open. Saknad status blir inte open. */
function jeab_inkorg_fast_import_open(array $row): bool
{
    return array_key_exists('status', $row) && (string) $row['status'] === 'open';
}

/**
 * Samma Malmö-regel som jeab_malmo_hs_decision: bara batteri eller elbilsladdare till Kim.
 * Sol, sol+batteri, support och osäker klassning stannar i Historik.
 * Läser inte konversationen.
 */
function jeab_inkorg_fast_import_kim(array $row): bool
{
    if (!empty($row['deleted'])) {
        return false;
    }
    $fold = static function (array $values): array {
        $out = [];
        foreach ($values as $value) {
            $text = mb_strtolower(trim((string) $value));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    };
    $tags = $fold((array) ($row['tags'] ?? []));
    $jobs = $fold((array) ($row['jobs'] ?? []));
    $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
    $type = trim((string) ($labels['type'] ?? ''));
    $offer = mb_strtolower(trim((string) ($labels['offer'] ?? $row['offer'] ?? '')));
    $target = mb_strtolower(trim((string) ($row['target'] ?? '')));
    $solar = in_array('sol', $tags, true) || in_array('sol', $jobs, true) || $offer === 'sol' || $offer === 'sol+batteri' || str_contains($offer, 'solcell');
    if ($solar || $target === 'historik' || $type === 'Support') {
        return false;
    }
    if (!empty($row['malmo_hs_kim']) || $target === 'kim') {
        return true;
    }
    $battery = in_array('batteri', $tags, true) || in_array('batteri', $jobs, true) || $offer === 'batteri';
    $charger = in_array('elbilsladdare', $tags, true) || in_array('laddbox', $jobs, true) || $offer === 'laddbox' || $offer === 'elbilsladdare';

    return $battery || $charger;
}

function jeab_inkorg_import_cache_key(string $box): string
{
    $box = preg_replace('/[^a-z]/', '', $box) ?? '';

    return 'import:' . $box;
}

/** Filstämpel utan processens generation. Samma mellan sidträffar. */
function jeab_inkorg_import_file_stamp(): string
{
    $path = function_exists('jeab_hs_import_root') ? jeab_hs_import_root() . '/index.jsonl' : '';

    return ($path !== '' && is_file($path)) ? ((string) filesize($path) . ':' . (string) filemtime($path)) : '0';
}

/** @return array{rows: list<array<string, mixed>>, gen: int}|null */
function jeab_inkorg_import_cache_read(string $box, string $fileStamp, int $gen): ?array
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return null;
    }
    $data = $store->importCacheGet(jeab_inkorg_import_cache_key($box));
    if (!is_array($data) || (string) ($data['stamp'] ?? '') !== $fileStamp || !is_array($data['rows'] ?? null)) {
        return null;
    }
    $storedGen = (int) ($data['gen'] ?? 0);
    if ($gen > 0 && $storedGen !== $gen) {
        return null;
    }
    $rows = [];
    foreach ($data['rows'] as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return ['rows' => $rows, 'gen' => $storedGen];
}

/** @param list<array<string, mixed>> $rows */
function jeab_inkorg_import_cache_write(string $box, string $fileStamp, int $gen, array $rows): void
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return;
    }
    $store->importCachePut(jeab_inkorg_import_cache_key($box), [
        'stamp' => $fileStamp,
        'gen' => $gen,
        'rows' => $rows,
    ]);
}

function jeab_inkorg_import_cached(string $box): bool
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return false;
    }
    $data = $store->importCacheGet(jeab_inkorg_import_cache_key($box));

    return is_array($data) && is_array($data['rows'] ?? null);
}

/** Sidan och listklicket läser cachen. Indexet läses av tick och ix_part=counts. */
function jeab_inkorg_import_may_scan(): bool
{
    if (!empty($GLOBALS['_JEAB_INKORG_ALLOW_REBUILD'])) {
        return true;
    }
    $part = (string) ($GLOBALS['_JEAB_INKORG_PART'] ?? '');
    if ($part === '' && function_exists('jeab_inkorg_ix_part')) {
        $part = jeab_inkorg_ix_part();
    }

    return $part === 'counts' || $part === 'tick';
}

/**
 * Öppna Kim-kopior för ett Help Scout-bolag. Cachas på indexets stämpel.
 *
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_fast_import_threads(string $box): array
{
    static $mem = [];
    $fileStamp = jeab_inkorg_import_file_stamp();
    $gen = (int) ($GLOBALS['_JEAB_HS_IMPORT_INDEX_GEN'] ?? 0);
    $stamp = $fileStamp . '|' . $box . '|' . $gen;
    if (isset($mem[$stamp]) && is_array($mem[$stamp])) {
        return $mem[$stamp];
    }
    $cached = jeab_inkorg_import_cache_read($box, $fileStamp, $gen);
    if (is_array($cached)) {
        $mem[$stamp] = $cached['rows'];

        return $cached['rows'];
    }
    $out = [];
    if ($box === '' || !jeab_inkorg_import_may_scan() || !function_exists('jeab_hs_import_index') || !function_exists('jeab_hs_import_row_thread')) {
        $GLOBALS['_JEAB_INKORG_IMPORT_SCAN_SKIP'] = (int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN_SKIP'] ?? 0) + 1;

        return $out;
    }
    $GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] = (int) ($GLOBALS['_JEAB_INKORG_IMPORT_SCAN'] ?? 0) + 1;
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (!is_array($row) || !jeab_inkorg_fast_import_open($row) || !jeab_inkorg_fast_import_kim($row)) {
            continue;
        }
        $company = (string) ($row['company'] ?? '');
        if ($company === 'meab') {
            $company = 'malmo';
        }
        if ($company !== $box) {
            continue;
        }
        $thread = jeab_hs_import_row_thread($row);
        $thread['status'] = 'open';
        $thread['company'] = $company;
        $face = [
            'subject' => (string) ($row['subject'] ?? ''),
            'from' => (string) ($row['from'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
        ];
        $thread['first'] = $face;
        $thread['last'] = $face;
        if ((string) ($thread['list_snippet'] ?? '') === '') {
            $thread['list_snippet'] = (string) ($row['snippet'] ?? '');
        }
        if ((string) ($thread['updated_at'] ?? '') === '') {
            $thread['updated_at'] = (string) ($row['updated_at'] ?? '');
        }
        $out[] = $thread;
    }
    jeab_inkorg_import_cache_write($box, $fileStamp, $gen, $out);
    $mem[$stamp] = $out;

    return $out;
}

/**
 * Fyller liststämpel, Malmö-importens cache och vykort för båda scopen.
 * Sidträffen anropar inte det här.
 *
 * @return array{ok: bool, rebuilt: int}
 */
function jeab_inkorg_tick_warm(): array
{
    $prevAllow = $GLOBALS['_JEAB_INKORG_ALLOW_REBUILD'] ?? null;
    $prevPart = $GLOBALS['_JEAB_INKORG_PART'] ?? null;
    $prevGet = $_GET;
    $prevPin = $GLOBALS['_JEAB_INKORG_BOX_PIN'] ?? null;
    $GLOBALS['_JEAB_INKORG_ALLOW_REBUILD'] = true;
    $GLOBALS['_JEAB_INKORG_PART'] = 'tick';
    $before = (int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0);
    try {
        $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
        if ($store instanceof JeabPdoInkorgStore && function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
            jeab_inkorg_sql_list_sync($store, '');
        }
        foreach (['jarfalla', 'malmo'] as $box) {
            jeab_inkorg_fast_import_threads($box);
            foreach (['allas', 'egna'] as $scope) {
                $_GET['p'] = 'inkorg';
                $_GET['box'] = $box;
                $_GET['co'] = $box;
                $_GET['scope'] = $scope;
                $_GET['view'] = 'v-inkorg';
                unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
                if (function_exists('jeab_inkorg_fast_counts_refresh')) {
                    jeab_inkorg_fast_counts_refresh();
                }
            }
        }
    } finally {
        $_GET = $prevGet;
        if ($prevAllow === null) {
            unset($GLOBALS['_JEAB_INKORG_ALLOW_REBUILD']);
        } else {
            $GLOBALS['_JEAB_INKORG_ALLOW_REBUILD'] = $prevAllow;
        }
        if ($prevPart === null) {
            unset($GLOBALS['_JEAB_INKORG_PART']);
        } else {
            $GLOBALS['_JEAB_INKORG_PART'] = $prevPart;
        }
        if ($prevPin === null) {
            unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
        } else {
            $GLOBALS['_JEAB_INKORG_BOX_PIN'] = $prevPin;
        }
        unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
    }

    return ['ok' => true, 'rebuilt' => (int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) - $before];
}

/**
 * Kopior som inte redan ligger i SQL. Bara bolag som sköts i Help Scout.
 * Bara explicit öppna batteri- och laddärenden till Kim. Allt annat stannar i Historik.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_fast_with_imports(array $rows, string $box): array
{
    if ($box === '' || !function_exists('jeab_inkorg_company_live') || jeab_inkorg_company_live($box)) {
        return $rows;
    }
    $extra = jeab_inkorg_fast_import_threads($box);
    if ($extra === []) {
        return $rows;
    }
    $seen = [];
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id !== '') {
            $seen[$id] = true;
        }
    }
    foreach ($extra as $thread) {
        $id = (string) ($thread['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) {
            continue;
        }
        $rows[] = $thread;
        if ($id !== '') {
            $seen[$id] = true;
        }
    }

    return $rows;
}

function jeab_inkorg_fast_row_is_import(array $row): bool
{
    $source = (string) ($row['import_source'] ?? '');
    if ($source === '') {
        $source = (string) ($row['source'] ?? '');
    }
    if ($source === 'helpscout_import' || (defined('JEAB_HS_IMPORT_SOURCE') && $source === JEAB_HS_IMPORT_SOURCE)) {
        return true;
    }
    $id = (string) ($row['id'] ?? '');

    return preg_match('/^hsi-[a-f0-9]{16}$/', $id) === 1;
}

/**
 * Tal per flik. imports är andelen från arkivet, open_imports den andel som är explicit öppna.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{total: int, imports: int, open_imports: int}
 */
function jeab_inkorg_fast_tab_numbers(array $rows): array
{
    $imports = 0;
    $openImports = 0;
    foreach ($rows as $row) {
        if (!is_array($row) || !jeab_inkorg_fast_row_is_import($row)) {
            continue;
        }
        $imports++;
        if (array_key_exists('status', $row) && (string) $row['status'] === 'open') {
            $openImports++;
        }
    }

    return ['total' => count($rows), 'imports' => $imports, 'open_imports' => $openImports];
}

/**
 * inkorg.counts. Hela boxen, inte en person. Bara tal.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_counts_hook(array $input): array
{
    $prevPart = $GLOBALS['_JEAB_INKORG_PART'] ?? null;
    if ($prevPart === null || $prevPart === '') {
        $GLOBALS['_JEAB_INKORG_PART'] = 'counts';
    }
    try {
        return jeab_inkorg_counts_hook_run($input);
    } finally {
        if ($prevPart === null) {
            unset($GLOBALS['_JEAB_INKORG_PART']);
        } else {
            $GLOBALS['_JEAB_INKORG_PART'] = $prevPart;
        }
    }
}

/**
 * inkorg.counts. Hela boxen, inte en person. Bara tal.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_counts_hook_run(array $input): array
{
    $raw = (string) ($input['box'] ?? '');
    $box = function_exists('jeab_normalize_company') ? (jeab_normalize_company($raw) ?? '') : trim($raw);
    if ($box !== 'jarfalla' && $box !== 'malmo') {
        return ['ok' => false, 'error' => 'box'];
    }
    $boxAllowed = !function_exists('jeab_company_allowed') || jeab_company_allowed($box);
    if (!$boxAllowed) {
        return ['ok' => false, 'error' => 'box'];
    }
    $menu = function_exists('jeab_inkorg_folder_views') ? jeab_inkorg_folder_views() : [];
    $light = jeab_inkorg_fast_working_light($box);
    $tabs = [];
    foreach ($menu as $view) {
        if (!is_array($view)) {
            continue;
        }
        $id = (string) ($view['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $filter = (array) ($view['filter'] ?? []) + ['company' => $box];
        if ((string) ($filter['folder'] ?? '') === 'historik') {
            $store = jeab_inkorg_fast_count_store();
            $sqlTotal = $store instanceof JeabPdoInkorgStore ? $store->countHistorik($box) : 0;
            $imported = function_exists('jeab_hs_import_store_historik_rows') ? jeab_hs_import_store_historik_rows($box) : [];
            $ids = [];
            foreach ($imported as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') !== '') {
                    $ids[] = (string) $row['id'];
                }
            }
            $present = [];
            if ($store instanceof JeabPdoInkorgStore && $ids !== []) {
                foreach ($store->existingIds($ids) as $have) {
                    $present[$have] = true;
                }
            }
            $rows = [];
            foreach ($imported as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowId = (string) ($row['id'] ?? '');
                if ($rowId !== '' && isset($present[$rowId])) {
                    continue;
                }
                $thread = function_exists('jeab_hs_import_row_thread') ? jeab_hs_import_row_thread($row) : $row;
                if (!function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($thread, $filter)) {
                    continue;
                }
                $rows[] = $row;
            }
            $numbers = jeab_inkorg_fast_tab_numbers($rows);
            $numbers['total'] += $sqlTotal;
            $tabs[$id] = $numbers;
            continue;
        }
        $matched = [];
        foreach ($light as $thread) {
            if (!is_array($thread) || !function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($thread, $filter)) {
                continue;
            }
            $matched[] = $thread;
        }
        $tabs[$id] = jeab_inkorg_fast_tab_numbers($matched);
    }

    return ['ok' => true, 'box' => $box, 'tabs' => $tabs];
}

/**
 * Flikpåsen plus sidomenyns vyer. Importarkivet läses inte för chippen.
 *
 * @param array{counts?: array<string, int>, unread?: int, sources?: array<string, int>, fresh?: int, at?: int, settled?: int} $bag
 * @param list<array<string, mixed>> $light
 * @return array{counts: array<string, int>, unread: int, sources: array<string, int>, fresh: int, at: int}
 */
function jeab_inkorg_counts_publish(array $bag, string $box, array $light = []): array
{
    $counts = is_array($bag['counts'] ?? null) ? $bag['counts'] : [];
    $bag['counts'] = jeab_inkorg_menu_counts_merge($counts, $light, $box);
    $bag['unread'] = (int) ($bag['unread'] ?? 0);
    $bag['sources'] = is_array($bag['sources'] ?? null) ? $bag['sources'] : [];
    $bag['fresh'] = (int) ($bag['fresh'] ?? 0);
    $bag['at'] = (int) ($bag['at'] ?? 0);

    return $bag;
}

/** Bakgrund: fyller vykortens tal. Anropas inte från en klick-hämtning. */
function jeab_inkorg_fast_counts_refresh(): array
{
    $prev = $GLOBALS['_JEAB_INKORG_PART'] ?? null;
    $GLOBALS['_JEAB_INKORG_PART'] = 'counts';
    try {
        $views = jeab_inkorg_views();
        $me = jeab_inkorg_me_id();
        $box = jeab_inkorg_box();
        $publish = static function (array $bag, array $light = []) use ($box): array {
            return jeab_inkorg_counts_publish($bag, $box, $light);
        };
        $vyId = jeab_inkorg_requested_view((string) ($_GET['view'] ?? $_GET['vy'] ?? ''), $views, $me);
        $scope = function_exists('jeab_inkorg_list_scope') ? jeab_inkorg_list_scope() : 'allas';
        $menu = function_exists('jeab_inkorg_fast_scope_menu')
            ? jeab_inkorg_fast_scope_menu($scope)
            : (function_exists('jeab_inkorg_folder_views')
                ? jeab_inkorg_folder_views()
                : jeab_inkorg_menu_views(jeab_inkorg_company_views($views, $box), $vyId));
        unset($GLOBALS['_JEAB_INKORG_LIST_SYNC']);
        $key = jeab_inkorg_fast_count_key($menu, $box, $me);
        $cached = jeab_inkorg_fast_count_read($key);
        $countStore = jeab_inkorg_fast_count_store();
        $needsList = $countStore instanceof JeabPdoInkorgStore && $countStore->listStamp() === '';
        if (!$countStore instanceof JeabPdoInkorgStore && function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
            jeab_inkorg_trace_event('counts_refresh.store_null');
        } elseif ($needsList) {
            jeab_inkorg_trace_source('counts_refresh_needs_list');
        }
        $direct = [];
        $suspect = is_array($cached) && !empty($cached['fresh']) && jeab_inkorg_counts_all_zero($cached) && empty($cached['settled']);
        if ($countStore instanceof JeabPdoInkorgStore && !$needsList && ($suspect || !is_array($cached) || empty($cached['fresh']))) {
            try {
                $direct = jeab_inkorg_fast_working_light($box);
            } catch (Throwable $e) {
                jeab_inkorg_trace_event('counts_refresh.direct_light_catch', $e);
                $direct = [];
            }
        }
        if ($suspect && $direct === [] && jeab_inkorg_list_open_count_noddl($box) === 0) {
            $cached['settled'] = 1;
            jeab_inkorg_fast_count_write($key, $cached);

            return $publish($cached);
        }
        if (is_array($cached) && !empty($cached['fresh']) && !$needsList && !$suspect) {
            return $publish($cached);
        }
        if ($direct !== [] && !$needsList) {
            if ($suspect) {
                $GLOBALS['_JEAB_INKORG_COUNT_FORCE'] = true;
            }
            try {
                $bag = jeab_inkorg_fast_counts_same($direct, $menu, $box, $me);
            } catch (Throwable $e) {
                jeab_inkorg_trace_event('counts_refresh.same_direct_catch', $e);
                if (is_array($cached)) {
                    $cached['fresh'] = 0;

                    return $publish($cached);
                }

                return $publish(['counts' => [], 'unread' => 0, 'sources' => [], 'fresh' => 0, 'at' => 0]);
            } finally {
                unset($GLOBALS['_JEAB_INKORG_COUNT_FORCE']);
            }

            return $publish($bag, $direct);
        }
        try {
            $light = jeab_inkorg_fast_working_light($box);
        } catch (Throwable $e) {
            jeab_inkorg_trace_event('counts_refresh.light_catch', $e);
            if (is_array($cached)) {
                $cached['fresh'] = 0;

                return $publish($cached);
            }

            return $publish(['counts' => [], 'unread' => 0, 'sources' => [], 'fresh' => 0, 'at' => 0]);
        }
        if (($GLOBALS['_JEAB_INKORG_LIST_SYNC'] ?? '') === 'locked') {
            if ($light !== []) {
                try {
                    return $publish(jeab_inkorg_fast_counts_same($light, $menu, $box, $me), $light);
                } catch (Throwable $e) {
                    jeab_inkorg_trace_event('counts_refresh.same_locked_catch', $e);
                    if (is_array($cached)) {
                        $cached['fresh'] = 0;

                        return $publish($cached, $light);
                    }
                }
            }
            if (is_array($cached)) {
                $cached['fresh'] = 0;

                return $publish($cached, $light);
            }

            return $publish(['counts' => [], 'unread' => 0, 'sources' => [], 'fresh' => 0, 'at' => 0], $light);
        }

        try {
            return $publish(jeab_inkorg_fast_counts_same($light, $menu, $box, $me), $light);
        } catch (Throwable $e) {
            jeab_inkorg_trace_event('counts_refresh.same_catch', $e);
            if (is_array($cached)) {
                $cached['fresh'] = 0;

                return $publish($cached, $light);
            }

            return $publish(['counts' => [], 'unread' => 0, 'sources' => [], 'fresh' => 0, 'at' => 0], $light);
        }
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_INKORG_PART']);
        } else {
            $GLOBALS['_JEAB_INKORG_PART'] = $prev;
        }
    }
}

function jeab_inkorg_fast_swr_path(string $key): string
{
    return jeab_inkorg_fast_dir() . '/swr-' . $key . '.json';
}

/**
 * @param array<string, mixed> $payload
 */
function jeab_inkorg_fast_swr_write(string $key, array $payload): void
{
    if (!isset($GLOBALS['_JEAB_INKORG_SWR']) || !is_array($GLOBALS['_JEAB_INKORG_SWR'])) {
        $GLOBALS['_JEAB_INKORG_SWR'] = [];
    }
    $GLOBALS['_JEAB_INKORG_SWR'][$key] = $payload;
}

function jeab_inkorg_fast_swr_read(string $key, string $fp): ?array
{
    $saved = $GLOBALS['_JEAB_INKORG_SWR'][$key] ?? null;
    if (!is_array($saved) || (string) ($saved['fp'] ?? '') !== $fp) {
        return null;
    }
    $age = time() - (int) ($saved['at'] ?? 0);
    if ($age < 0 || $age > JEAB_INKORG_FAST_STALE) {
        return null;
    }
    $saved['_stale'] = $age > JEAB_INKORG_FAST_FRESH ? 1 : 0;

    return $saved;
}

function jeab_inkorg_fast_initials(string $id, array $staff): string
{
    $n = trim((string) ($staff[$id] ?? $id));
    $p = array_values(array_filter(preg_split('/\s+/u', $n) ?: []));

    return mb_strtoupper(count($p) > 1 ? mb_substr($p[0], 0, 1) . mb_substr($p[1], 0, 1) : mb_substr($p[0] ?? '', 0, 2));
}

/** Gata och ort som redan ligger på raden. Tomma strängar fylls senare från kontakten. */
function jeab_inkorg_fast_place_from_row(array $t): array
{
    $street = trim((string) ($t['list_street'] ?? ''));
    if ($street === '') {
        $street = trim((string) ($t['customer_address'] ?? ''));
    }
    $city = trim((string) ($t['list_city'] ?? ''));
    if ($city === '') {
        $city = trim((string) ($t['customer_city'] ?? $t['ort'] ?? ''));
    }

    return [$street, $city];
}

/** @param array<string, mixed> $contact */
function jeab_inkorg_fast_place_from_contact(array $contact): array
{
    if (function_exists('jeab_contacts_inbox_contact_is_internal') && jeab_contacts_inbox_contact_is_internal($contact)) {
        return ['', ''];
    }
    if (function_exists('jeab_contacts_sole_view_from_contact')) {
        $view = jeab_contacts_sole_view_from_contact($contact);

        return [trim((string) ($view['street'] ?? '')), trim((string) ($view['city'] ?? ''))];
    }

    return [trim((string) ($contact['street'] ?? '')), trim((string) ($contact['city'] ?? ''))];
}

function jeab_inkorg_fast_place_line(string $street, string $city): string
{
    $street = trim($street);
    $city = trim($city);
    if ($street !== '' && $city !== '') {
        $hay = mb_strtolower($street);
        $tail = mb_strtolower($city);
        if (!str_ends_with($hay, $tail) && !str_contains($hay, ', ' . $tail)) {
            return $street . ', ' . $city;
        }
    }

    return $street !== '' ? $street : $city;
}

function jeab_inkorg_fast_list_email(array $t): string
{
    $email = strtolower(trim((string) ($t['customer_email'] ?? '')));
    if ($email === '' && is_array($t['lead_customer'] ?? null)) {
        $email = strtolower(trim((string) ($t['lead_customer']['email'] ?? '')));
    }
    if ($email === '' && is_array($t['last'] ?? null)) {
        $email = strtolower(trim((string) ($t['last']['from'] ?? '')));
    }
    if ($email === '' && is_array($t['first'] ?? null)) {
        $email = strtolower(trim((string) ($t['first']['from'] ?? '')));
    }
    if (!str_contains($email, '@')) {
        return '';
    }
    if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email)) {
        return '';
    }

    return $email;
}

function jeab_inkorg_fast_list_preview(array $t): string
{
    $text = trim((string) ($t['list_snippet'] ?? ''));
    if ($text === '') {
        $text = trim((string) ($t['snippet'] ?? ''));
    }

    return $text;
}

/**
 * Adress och ort för raderna på den här sidan. En kontaktfråga, inte en per rad.
 * Med kontakten som källa läser en rad med contact_id gata och ort från den kontakten.
 * En rad utan contact_id behåller sina egna fält. Utan flaggan fylls bara tomma fält.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_fast_list_places(array $rows): array
{
    $sole = function_exists('jeab_contacts_sole') && jeab_contacts_sole();
    $strict = $sole && function_exists('jeab_contacts_strict') && jeab_contacts_strict();
    $need = [];
    foreach ($rows as $i => $t) {
        if (!is_array($t)) {
            continue;
        }
        [$street, $city] = jeab_inkorg_fast_place_from_row($t);
        $rows[$i]['_list_street'] = $street;
        $rows[$i]['_list_city'] = $city;
        $cid = trim((string) ($t['contact_id'] ?? ''));
        if ($sole) {
            if ($cid !== '') {
                $need[$i] = true;
            }
        } elseif ($street === '' || $city === '') {
            $need[$i] = true;
        }
    }
    if ($need === []) {
        return $rows;
    }
    $ids = [];
    $emails = [];
    $threadIds = [];
    foreach (array_keys($need) as $i) {
        $t = $rows[$i];
        $cid = trim((string) ($t['contact_id'] ?? ''));
        if ($cid !== '') {
            $ids[$cid] = $cid;
        } else {
            $tid = trim((string) ($t['id'] ?? ''));
            if ($tid !== '') {
                $threadIds[$tid] = $tid;
            }
        }
        $email = jeab_inkorg_fast_list_email($t);
        if ($email !== '') {
            $emails[$email] = $email;
        }
    }
    $byThread = [];
    if ($threadIds !== [] && function_exists('jeab_contacts_inbox_reminder_contacts')) {
        $byThread = jeab_contacts_inbox_reminder_contacts(array_keys($threadIds));
        foreach ($byThread as $cid) {
            $cid = trim((string) $cid);
            if ($cid !== '') {
                $ids[$cid] = $cid;
            }
        }
    }
    $byId = ($ids !== [] && function_exists('jeab_contacts_sole_load_ids'))
        ? jeab_contacts_sole_load_ids(array_values($ids))
        : [];
    $byEmail = ($emails !== [] && function_exists('jeab_contacts_inbox_by_email'))
        ? jeab_contacts_inbox_by_email(array_values($emails))
        : [];
    foreach (array_keys($need) as $i) {
        $t = $rows[$i];
        $cid = trim((string) ($t['contact_id'] ?? ''));
        if ($cid === '') {
            $cid = trim((string) ($byThread[(string) ($t['id'] ?? '')] ?? ''));
        }
        $contact = ($cid !== '' && isset($byId[$cid]) && is_array($byId[$cid])) ? $byId[$cid] : null;
        if (!is_array($contact)) {
            $email = jeab_inkorg_fast_list_email($t);
            $contact = ($email !== '' && isset($byEmail[$email]) && is_array($byEmail[$email])) ? $byEmail[$email] : null;
        }
        if (!is_array($contact)) {
            continue;
        }
        [$street, $city] = jeab_inkorg_fast_place_from_contact($contact);
        if ($sole) {
            $ownId = trim((string) ($t['contact_id'] ?? ''));
            if ($ownId === '' || !isset($byId[$ownId]) || !is_array($byId[$ownId])) {
                continue;
            }
            $contact = $byId[$ownId];
            [$street, $city] = jeab_inkorg_fast_place_from_contact($contact);
            $fmt = function_exists('jeab_contacts_sole_view_from_contact')
                ? (string) (jeab_contacts_sole_view_from_contact($contact)['postal_fmt'] ?? '')
                : trim((string) ($contact['postal_code'] ?? ''));
            // Färdig listadress (list_street/list_city) är redan radens plats.
            // Kundkopian (customer_address) byts mot kontakten. Strict byter även en färdig listadress.
            $ownStreet = trim((string) ($t['list_street'] ?? ''));
            $ownCity = trim((string) ($t['list_city'] ?? ''));
            if (($street !== '' || $strict) && ($strict || $ownStreet === '')) {
                $rows[$i]['_list_street'] = $street;
            }
            if (($city !== '' || $strict) && ($strict || $ownCity === '')) {
                $rows[$i]['_list_city'] = $city;
                $rows[$i]['ort'] = $city;
            }
            if ($fmt !== '' || $strict) {
                $rows[$i]['postnr'] = $fmt;
            }
            continue;
        }
        if ((string) $rows[$i]['_list_street'] === '' && $street !== '') {
            $rows[$i]['_list_street'] = $street;
        }
        if ((string) $rows[$i]['_list_city'] === '' && $city !== '') {
            $rows[$i]['_list_city'] = $city;
        }
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param array<string, string> $keep
 */
function jeab_inkorg_fast_list_html(array $rows, string $openId, string $me, array $keep, array $staff, int $page, int $pages, int $othersFrom = -1): string
{
    $q = static function (array $over) use ($keep): string {
        $merged = array_merge($keep, $over);

        return e(jeab_url('index.php?' . http_build_query(array_filter($merged, static fn($v) => $v !== '' && $v !== null))));
    };
    if ($rows === []) {
        return '<p class="empty">Inget här just nu.</p>';
    }
    $rows = jeab_inkorg_fast_list_places($rows);
    $html = '';
    if ($othersFrom === 0) {
        $html .= '<p class="ix-muted" role="status">Hos andra medarbetare</p>';
    }
    $html .= '<ul class="list ix-items">';
    $rowIndex = 0;
    foreach ($rows as $t) {
        if ($othersFrom > 0 && $rowIndex === $othersFrom) {
            $html .= '</ul><p class="ix-muted" role="status">Hos andra medarbetare</p><ul class="list ix-items">';
        }
        $rowIndex++;
        if (!is_array($t)) {
            continue;
        }
        $unread = jeab_inkorg_is_unread($t, $me);
        $on = $openId !== '' && (string) ($t['id'] ?? '') === $openId;
        $subject = (string) ($t['first']['subject'] ?? '');
        $assignee = (string) ($t['assignee'] ?? '');
        $company = (string) ($t['company'] ?? '');
        $locked = $company !== '' && !jeab_inkorg_company_live($company) && empty($t['malmo_hs_kim']);
        $canClose = !$locked && empty($t['deleted']) && (string) ($t['status'] ?? '') !== 'closed';
        $readLabel = $unread ? 'Läst' : 'Oläst';
        $rev = function_exists('jeab_inkorg_thread_rev') ? jeab_inkorg_thread_rev($t) : (string) ($t['updated_at'] ?? '');
        $html .= '<li class="item ix-it' . ($unread ? ' is-unread' : '') . ($on ? ' is-on' : '') . '" data-ix-id="' . e((string) ($t['id'] ?? '')) . '" data-ix-rev="' . e($rev) . '" data-ix-status="' . e((string) ($t['status'] ?? 'open')) . '" data-ix-unread="' . ($unread ? '1' : '0') . '" data-ix-close="' . ($canClose ? '1' : '0') . '">';
        $html .= '<div class="ix-under" aria-hidden="true"><span class="ix-under-read">' . $readLabel . '</span>';
        if ($canClose) {
            $html .= '<span class="ix-under-close">Stäng</span>';
        }
        $html .= '</div>';
        $who = jeab_inkorg_fast_who($t);
        $assigneeName = $assignee !== '' ? (string) ($staff[$assignee] ?? $assignee) : 'Otilldelad';
        $offerLabel = function_exists('jeab_inkorg_stored_offer') && function_exists('jeab_inkorg_list_offer_label')
            ? jeab_inkorg_list_offer_label(jeab_inkorg_stored_offer($t))
            : '';
        $html .= '<a class="item-main ix-face' . ($on ? ' is-on' : '') . '" href="' . $q(['id' => (string) ($t['id'] ?? '')]) . '"' . ($on ? ' aria-current="true"' : '') . '>';
        $html .= '<span class="ix-dot" aria-label="' . ($unread ? 'Oläst' : 'Läst') . '"></span>';
        $whenIso = (string) ($t['updated_at'] ?? '');
        if ($whenIso === '') {
            $whenIso = (string) ($t['mail_at'] ?? '');
        }
        if ($whenIso === '') {
            $last = is_array($t['last'] ?? null) ? $t['last'] : [];
            $whenIso = (string) ($last['received_at'] ?? '');
        }
        $html .= '<div class="ix-card-copy">';
        $html .= '<div class="ix-l1"><span class="ix-nm">' . e($who !== '' ? $who : '(okänd)') . '</span>';
        $html .= '<span class="ix-dt">' . e(jeab_inkorg_list_time($whenIso)) . '</span></div>';
        $place = jeab_inkorg_fast_place_line((string) ($t['_list_street'] ?? ''), (string) ($t['_list_city'] ?? ''));
        if ($place !== '') {
            $html .= '<div class="ix-addr">' . e($place) . '</div>';
        }
        $html .= '<div class="ix-l2">';
        if ($offerLabel !== '') {
            $html .= '<span class="ix-prod" data-ix-product>' . e($offerLabel) . '</span>';
        }
        $html .= '<span class="ix-sub">' . e($subject !== '' ? $subject : '(ingen rubrik)') . '</span></div>';
        $preview = jeab_inkorg_fast_list_preview($t);
        if ($preview !== '') {
            $html .= '<div class="ix-pre">' . e($preview) . '</div>';
        }
        $html .= '<span class="ix-sr" data-ix-assignee>' . e($assigneeName) . '</span>';
        if (function_exists('jeab_inkorg_test_chip_html')) {
            $html .= jeab_inkorg_test_chip_html($t, 'ix-sr ix-cat is-test');
        }
        $html .= '</div></a>';
        $html .= '<div class="ix-acts">';
        $html .= '<button type="button" class="ix-act" data-ix-act="read">' . $readLabel . '</button>';
        if ($canClose) {
            $html .= '<button type="button" class="ix-act ix-act-close" data-ix-act="close">Stäng</button>';
        }
        $html .= '</div></li>';
    }
    $html .= '</ul>';
    if ($pages > 1) {
        $html .= '<nav class="chips" aria-label="Sidor">';
        if ($page > 1) {
            $html .= '<a class="chip" href="' . $q(['sida' => $page - 1]) . '">Föregående</a>';
        }
        $html .= '<span class="chip">' . (int) $page . ' / ' . (int) $pages . '</span>';
        if ($page < $pages) {
            $next = $page + 1;
            $html .= '<a class="chip crm-more" data-ix-more="1" data-ix-sida="' . (int) $next . '" href="' . $q(['sida' => $next]) . '" aria-label="Nästa sida">Visa fler</a>';
        }
        $html .= '</nav>';
    }

    return $html;
}

function jeab_inkorg_fast_range(int $shown, int $total, int $pages, bool $filtered): string
{
    unset($filtered);
    if ($pages > 1 && $total > $shown) {
        return 'visar ' . $shown . ' av ' . $total;
    }

    return (string) $total;
}

/**
 * @param list<array<string, mixed>> $msgs äldst först
 * @param array<string, mixed> $meta
 * @return array<string, mixed>
 */
function jeab_inkorg_fast_list_row(string $tid, array $msgs, array $meta): array
{
    $plains = [];
    foreach ($msgs as $m) {
        if (!is_array($m)) {
            continue;
        }
        $raw = (string) ($m['body'] ?? $m['body_text'] ?? '');
        $plains[] = mb_substr(trim((string) (preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '')), 0, 500);
    }
    $row = jeab_inkorg_thread_row($tid, $msgs, $meta);
    $newest = $plains === [] ? '' : (string) $plains[array_key_last($plains)];
    $row['list_snippet'] = jeab_inkorg_snippet($newest, 120);
    $hay = $plains;
    foreach ($msgs as $m) {
        if (!is_array($m)) {
            continue;
        }
        $hay[] = (string) ($m['subject'] ?? '');
        $hay[] = (string) ($m['from'] ?? '');
        $hay[] = (string) ($m['from_name'] ?? '');
    }
    $row['search_hay'] = mb_strtolower(implode("\n", $hay));
    $row['list_type'] = (string) ($row['labels']['type'] ?? '');
    $row['list_reminder'] = jeab_inkorg_thread_has_reminder($tid);
    $row['list_marks'] = jeab_inkorg_list_marks($row);
    $row['message_count'] = count($msgs);
    $row['mail_at'] = (string) ($row['last']['received_at'] ?? '');
    if (function_exists('jeab_inkorg_stored_offer')) {
        $offer = jeab_inkorg_stored_offer($row);
        if ($offer !== '') {
            $row['offer'] = $offer;
            $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
            if (trim((string) ($labels['offer'] ?? '')) === '') {
                $labels['offer'] = $offer;
                $row['labels'] = $labels;
            }
        }
    }
    $linkedId = trim((string) ($meta['contact_id'] ?? $row['contact_id'] ?? ''));
    if ($linkedId !== '' && jeab_contacts_inbox_strip_flags_on()) {
        $keys = function_exists('jeab_contacts_inbox_customer_keys')
            ? jeab_contacts_inbox_customer_keys()
            : ['customer_name', 'customer_email', 'customer_address', 'customer_phone', 'phone', 'customer_city', 'ort', 'postal', 'postnr'];
        foreach (array_merge($keys, ['name', 'email', 'address']) as $key) {
            unset($row[$key]);
        }
        if (is_array($row['lead_customer'] ?? null) && function_exists('jeab_inkorg_linked_lead_customer')) {
            $row['lead_customer'] = jeab_inkorg_linked_lead_customer($row['lead_customer'], $linkedId);
        }
    } elseif ($linkedId !== '') {
        $keys = function_exists('jeab_contacts_inbox_customer_keys')
            ? jeab_contacts_inbox_customer_keys()
            : ['customer_name', 'customer_email', 'customer_address', 'customer_phone', 'phone', 'customer_city', 'ort', 'postal', 'postnr'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $meta)) {
                $row[$key] = $meta[$key];
            } else {
                unset($row[$key]);
            }
        }
    } elseif ((string) ($row['customer_email'] ?? '') === '' && function_exists('jeab_inkorg_party')) {
        $party = jeab_inkorg_party($row);
        $row['customer_email'] = (string) ($party['email'] ?? '');
    }
    unset($row['messages']);
    foreach (['first', 'last'] as $key) {
        if (is_array($row[$key] ?? null)) {
            unset($row[$key]['body'], $row[$key]['body_text'], $row[$key]['headers'], $row[$key]['html'], $row[$key]['attachments']);
        }
    }

    return $row;
}

function jeab_inkorg_sql_list_sync(JeabPdoInkorgStore $store, string $fp): void
{
    $t0 = microtime(true);
    $held = false;
    unset($GLOBALS['_JEAB_INKORG_LIST_SYNC']);
    try {
        $fp = $store->listFingerprint();
        $stamp = $store->listStamp();
        if ($stamp === $fp) {
            return;
        }
        // Tom stämpel byggs av ix_part=counts eller tick, inte av sidträffen.
        $part = (string) ($GLOBALS['_JEAB_INKORG_PART'] ?? '');
        if ($part === '' && function_exists('jeab_inkorg_ix_part')) {
            $part = jeab_inkorg_ix_part();
        }
        $first = $stamp === '' && $part === 'counts';
        if (!$first && !jeab_inkorg_sql_may_rebuild()) {
            $GLOBALS['_JEAB_INKORG_LIST_SYNC'] = 'skip';

            return;
        }
        // Listan finns. ix_part=counts räknar de raderna. En ombyggnad håller
        // låset tills hela mejlboxen är omskriven, och nästa anrop svarar då
        // med en tom påse som ritas som 0.
        if ($part === 'counts' && $stamp !== '') {
            $GLOBALS['_JEAB_INKORG_LIST_SYNC'] = 'rows';

            return;
        }
        if (!jeab_db_get_lock($store->pdo(), 'jeab_inkorg_list')) {
            $GLOBALS['_JEAB_INKORG_LIST_SYNC'] = 'locked';

            return;
        }
        $held = true;
        $fp = $store->listFingerprint();
        if ($store->listStamp() === $fp) {
            return;
        }
        $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = (int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) + 1;
        jeab_inkorg_compute_mark('sql_list_sync');
        ignore_user_abort(true);
        @set_time_limit(300);
        jeab_inkorg_sql_list_rebuild($store, $fp);
        $store->noteListRebuild();
    } finally {
        if ($held) {
            jeab_db_release_lock($store->pdo(), 'jeab_inkorg_list');
        }
        if (function_exists('jeab_timing_span')) {
            jeab_timing_span('sql_list_sync', microtime(true) - $t0);
        }
    }
}

/**
 * Samma rader som ombyggnaden, utan att skriva inkorg_list. Används när
 * stämpeln är tom och låset redan är taget, så sidan kan ritas ändå.
 *
 * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
 */
function jeab_inkorg_sql_list_rows(JeabPdoInkorgStore $store): array
{
    $groups = [];
    $sources = [];
    foreach ($store->listMessages() as $m) {
        if (!is_array($m)) {
            continue;
        }
        $tid = (string) ($m['thread_id'] ?? $m['id'] ?? '');
        if ($tid === '') {
            continue;
        }
        $src = (string) ($m['source'] ?? '');
        $sources[$src] = ($sources[$src] ?? 0) + 1;
        $groups[$tid][] = $m;
    }
    $meta = $store->threadsMap();
    $rows = [];
    foreach ($groups as $tid => $msgs) {
        usort($msgs, static fn(array $a, array $b): int => strcmp((string) ($a['received_at'] ?? ''), (string) ($b['received_at'] ?? '')));
        $rows[] = jeab_inkorg_fast_list_row((string) $tid, $msgs, (array) ($meta[$tid] ?? []));
    }

    return [$rows, $sources];
}

function jeab_inkorg_list_rebuild_authorized(): bool
{
    if (function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }
    if (!function_exists('jeab_hook_scoped_authorized')) {
        $path = __DIR__ . '/hook_scoped_tokens.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    return function_exists('jeab_hook_scoped_authorized') && jeab_hook_scoped_authorized('inkorg.list_rebuild');
}

/** Bygger om listan, stämpeln och räknarcachen. Mejl och trådar lämnas. */
function jeab_inkorg_list_rebuild_hook(): array
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return ['ok' => false, 'error' => 'Ingen SQL-inkorg.', 'status' => 400];
    }
    ignore_user_abort(true);
    @set_time_limit(300);
    if (!jeab_db_get_lock($store->pdo(), 'jeab_inkorg_list')) {
        return ['ok' => true, 'locked' => 1, 'stamp' => $store->listStamp()];
    }
    try {
        $fp = $store->listFingerprint();
        $GLOBALS['_JEAB_INKORG_LIST_REBUILD'] = (int) ($GLOBALS['_JEAB_INKORG_LIST_REBUILD'] ?? 0) + 1;
        jeab_inkorg_compute_mark('sql_list_sync');
        jeab_inkorg_sql_list_rebuild($store, $fp);
        $store->noteListRebuild();
    } finally {
        jeab_db_release_lock($store->pdo(), 'jeab_inkorg_list');
    }

    return ['ok' => true, 'locked' => 0, 'stamp' => $store->listStamp()];
}

function jeab_inkorg_sql_list_rebuild(JeabPdoInkorgStore $store, string $fp): void
{
    [$rows, $sources] = jeab_inkorg_sql_list_rows($store);
    $store->replaceListRows($rows);
    $store->countCachePut('__sources', ['sources' => $sources]);
    $store->setListStamp($fp);
    $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] = $sources;
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_sql_list_project(JeabPdoInkorgStore $store, string $box): array
{
    [$rows, $sources] = jeab_inkorg_sql_list_rows($store);
    $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] = $sources;
    $out = [];
    foreach ($rows as $row) {
        if ((string) ($row['company'] ?? '') === $box) {
            $out[] = $row;
        }
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

    return $out;
}

function jeab_inkorg_sql_refresh_thread(JeabPdoInkorgStore $store, string $tid): void
{
    $hadList = $store->listStamp() !== '';
    $msgs = $store->findMessagesByThread($tid);
    usort($msgs, static fn(array $a, array $b): int => strcmp((string) ($a['received_at'] ?? ''), (string) ($b['received_at'] ?? '')));
    $meta = (array) ($store->threadsByIds([$tid])[$tid] ?? []);
    if ($msgs === [] && $meta === []) {
        $store->deleteListRow($tid);
    } else {
        $store->saveListRow(jeab_inkorg_fast_list_row($tid, $msgs, $meta));
    }
    unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
    if ($hadList) {
        $store->setListStamp($store->listFingerprint());
    }
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_sql_light(): array
{
    $box = jeab_inkorg_box();
    $fp = jeab_inkorg_messages_fp() . '|' . jeab_inkorg_threads_fp();
    $mem = $fp . '|' . $box;
    if (isset($GLOBALS['_JEAB_INKORG_LIGHT']) && is_array($GLOBALS['_JEAB_INKORG_LIGHT']) && ($GLOBALS['_JEAB_INKORG_LIGHT_FP'] ?? '') === $mem) {
        return $GLOBALS['_JEAB_INKORG_LIGHT'];
    }
    $store = jeab_inkorg_pdo_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        jeab_inkorg_trace_event('sql_light.store_null_empty');

        return [];
    }
    jeab_inkorg_sql_list_sync($store, $fp);
    if (($GLOBALS['_JEAB_INKORG_LIST_SYNC'] ?? '') === 'locked' && $store->listStamp() === '') {
        $rows = $store->listForCompany($box);
        if ($rows === []) {
            $rows = jeab_inkorg_sql_list_project($store, $box);
        }
        $GLOBALS['_JEAB_INKORG_LIGHT'] = $rows;
        $GLOBALS['_JEAB_INKORG_LIGHT_FP'] = $mem;

        return $rows;
    }
    $rows = $store->listForCompany($box);
    $sources = $store->countCacheGet('__sources', 86400 * 30);
    $GLOBALS['_JEAB_INKORG_LIGHT'] = $rows;
    $GLOBALS['_JEAB_INKORG_LIGHT_FP'] = $mem;
    $GLOBALS['_JEAB_INKORG_LIGHT_SOURCES'] = is_array($sources['sources'] ?? null) ? $sources['sources'] : [];

    return $rows;
}

function jeab_inkorg_sql_open(JeabPdoInkorgStore $store, string $id): ?array
{
    $t0 = microtime(true);
    try {
        return jeab_inkorg_sql_open_row($store, $id);
    } finally {
        if (function_exists('jeab_timing_span')) {
            jeab_timing_span('sql_open', microtime(true) - $t0);
        }
    }
}

function jeab_inkorg_sql_open_row(JeabPdoInkorgStore $store, string $id): ?array
{
    $tid = $id;
    $msgs = $store->findMessagesByThread($id);
    if ($msgs === []) {
        $one = $store->messagesByIds([$id]);
        $msg = $one[$id] ?? null;
        if (!is_array($msg)) {
            $metaOnly = $store->threadsByIds([$id]);
            if ($metaOnly === []) {
                return null;
            }
        } else {
            $tid = (string) ($msg['thread_id'] ?? $id);
            $msgs = $tid === $id ? [$msg] : $store->findMessagesByThread($tid);
        }
    }
    usort($msgs, static fn(array $a, array $b): int => strcmp((string) ($a['received_at'] ?? ''), (string) ($b['received_at'] ?? '')));
    $meta = (array) ($store->threadsByIds([$tid])[$tid] ?? []);

    return jeab_inkorg_thread_row($tid, $msgs, $meta);
}

/** @param array<string, mixed> $open @return list<array<string, mixed>> */
function jeab_inkorg_sql_related(array $open): array
{
    $store = jeab_inkorg_pdo_store();
    $email = '';
    if (function_exists('jeab_inkorg_party')) {
        $email = (string) (jeab_inkorg_party($open)['email'] ?? '');
    }
    if (!$store instanceof JeabPdoInkorgStore || $email === '') {
        return [$open];
    }
    $cap = function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() === 'thread' ? 24 : 0;
    $rows = $store->listForCustomer($email, $cap);
    $seen = false;
    foreach ($rows as $row) {
        if ((string) ($row['id'] ?? '') === (string) ($open['id'] ?? '')) {
            $seen = true;
            break;
        }
    }
    if (!$seen) {
        $rows[] = $open;
    }

    return $rows;
}

/**
 * Historik från listtabellen och stängda importrader. Öppna rader och partnerklassning körs inte.
 *
 * @param list<array<string, mixed>> $rows
 * @param list<array<string, mixed>> $imports
 * @return list<array{at: string, id: string, live: array<string, mixed>|null, import: array<string, mixed>|null}>
 */
function jeab_inkorg_historik_entries(array $rows, array $imports, array $filter): array
{
    $filterT0 = microtime(true);
    $entries = [];
    $seen = [];
    foreach ($rows as $t) {
        if (!is_array($t)) {
            continue;
        }
        $id = (string) ($t['id'] ?? '');
        if ($id !== '') {
            $seen[$id] = true;
        }
        if (!jeab_inkorg_thread_matches($t, $filter)) {
            continue;
        }
        $entries[] = [
            'at' => (string) ($t['updated_at'] ?? ''),
            'id' => $id,
            'live' => $t,
            'import' => null,
        ];
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('folder_filter', microtime(true) - $filterT0);
    }
    $importT0 = microtime(true);
    foreach ($imports as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) {
            continue;
        }
        $thread = function_exists('jeab_hs_import_row_thread') ? jeab_hs_import_row_thread($row) : $row;
        if (!jeab_inkorg_thread_matches($thread, $filter)) {
            continue;
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        $entries[] = [
            'at' => (string) ($row['updated_at'] ?? ''),
            'id' => $id,
            'live' => null,
            'import' => $row,
        ];
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('import_match', microtime(true) - $importT0);
    }
    usort($entries, static function (array $a, array $b): int {
        if ($a['at'] !== $b['at']) {
            return $a['at'] < $b['at'] ? 1 : -1;
        }

        return strcmp($b['id'], $a['id']);
    });

    return $entries;
}

/** @return array{live: list<array<string, mixed>>, import: array<string, mixed>, rows: list<array<string, mixed>>, total: int, search_widened: bool} */
function jeab_inkorg_fast_historik_listed(JeabPdoInkorgStore $store, array $filter, int $page, int $size): array
{
    $allT0 = microtime(true);
    $backT0 = microtime(true);
    $store->backfillListStatus();
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('historik_backfill', microtime(true) - $backT0);
    }
    $company = (string) ($filter['company'] ?? '');
    $companies = $company === 'malmo' ? ['malmo', 'meab'] : ($company === '' ? [] : [$company]);
    $assignee = (string) ($filter['assignee'] ?? '');
    $meId = function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '';
    if (function_exists('jeab_inkorg_list_scope') && jeab_inkorg_list_scope() === 'egna' && function_exists('jeab_egna_me_id')) {
        $meId = jeab_egna_me_id();
    }
    if ($assignee === 'none-person') {
        $assignee = 'me';
        $meId = '';
    }
    $q = trim((string) ($filter['q'] ?? ''));
    $size = max(1, $size);
    // Samma tal som proben (historik_total_noddl). Webbläsningen kör ingen DDL:
    // ett fel i ensure fick förr importarkivet att bli 0 och Historik visade bara live-raderna.
    $countOf = static function (string $who, string $whoId) use ($company, $q): int {
        return jeab_inkorg_historik_total_noddl($company, $who, $whoId, $q);
    };
    $widened = false;
    $total = $countOf($assignee, $meId);
    $entryFilter = $filter;
    if ($q !== '' && (string) ($filter['assignee'] ?? '') === 'me' && $total === 0) {
        $assignee = 'others';
        $total = $countOf($assignee, $meId);
        $widened = $total > 0;
        if ($widened) {
            $entryFilter['assignee'] = 'others';
        }
    }
    $pages = max(1, (int) ceil($total / $size));
    $page = max(1, min($pages, $page));
    $offset = ($page - 1) * $size;
    $need = $offset + $size;
    $liveKeys = $store->historikKeys($company, $need, 0, $assignee, $meId, $q);
    $importKeys = function_exists('jeab_hs_import_historik_keys')
        ? jeab_hs_import_historik_keys($store->pdo(), $companies, $need, $assignee, $meId, $q, false)
        : [];
    $merged = [];
    foreach ($liveKeys as $key) {
        $merged[] = ['at' => (string) ($key['at'] ?? ''), 'id' => (string) ($key['id'] ?? ''), 'src' => 'live'];
    }
    foreach ($importKeys as $key) {
        $merged[] = ['at' => (string) ($key['at'] ?? ''), 'id' => (string) ($key['id'] ?? ''), 'src' => 'import'];
    }
    usort($merged, static function (array $a, array $b): int {
        if ($a['at'] !== $b['at']) {
            return $a['at'] < $b['at'] ? 1 : -1;
        }

        return strcmp($b['id'], $a['id']);
    });
    $sliceKeys = array_slice($merged, $offset, $size);
    $liveIds = [];
    $importIds = [];
    foreach ($sliceKeys as $key) {
        if ($key['src'] === 'import') {
            $importIds[] = $key['id'];
        } else {
            $liveIds[] = $key['id'];
        }
    }
    $rows = jeab_inkorg_fast_normalize_company($store->listRowsByIds($liveIds), $company);
    $imports = function_exists('jeab_hs_import_rows_by_ids') ? jeab_hs_import_rows_by_ids($store->pdo(), $importIds, false) : [];
    $listT0 = microtime(true);
    $entries = jeab_inkorg_historik_entries($rows, $imports, $entryFilter);
    $slice = $entries;
    $live = [];
    $importRows = [];
    $out = [];
    foreach ($slice as $entry) {
        if (is_array($entry['live'])) {
            $live[] = $entry['live'];
            $out[] = $entry['live'];
            continue;
        }
        $row = $entry['import'];
        if (!is_array($row)) {
            continue;
        }
        $stub = function_exists('jeab_hs_import_stub_thread') ? jeab_hs_import_stub_thread($row) : $row;
        $importRows[] = $stub;
        $out[] = $stub;
    }
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('view_list', microtime(true) - $listT0);
        jeab_timing_span('historik_page', microtime(true) - $allT0);
    }

    return [
        'live' => $live,
        'import' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'rows' => $importRows],
        'rows' => $out,
        'total' => $total,
        'search_widened' => $widened,
    ];
}

/**
 * Kötal från rader sidan redan har. Stängda tar Historik-talet när det är högre
 * (importen ligger inte i arbetsraderna).
 *
 * @param list<array<string, mixed>> $light
 * @param array<string, int> $counts
 * @return array<string, int>
 */
function jeab_inkorg_fast_queue_bag(array $light, array $counts): array
{
    $queues = function_exists('jeab_inkorg_queue_counts') ? jeab_inkorg_queue_counts($light) : [];
    $hist = (int) ($counts['v-historik'] ?? 0);
    if ($hist > (int) ($queues['stangda'] ?? 0)) {
        $queues['stangda'] = $hist;
    }

    return $queues;
}

/** @param array<string, mixed> $a @param array<string, mixed> $b */
function jeab_inkorg_fast_row_newer(array $a, array $b): int
{
    $at = (string) ($a['updated_at'] ?? '');
    $bt = (string) ($b['updated_at'] ?? '');
    if ($at !== $bt) {
        return $at < $bt ? 1 : -1;
    }

    return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
}

/**
 * Arbetsrader som matchar filtret, nyast först, högst $limit stycken.
 * Nästa rad efter gränsen sätter more, så sidan inte läser hela mejlboxen.
 *
 * @param array<string, mixed> $filter
 * @return array{rows: list<array<string, mixed>>, more: bool}
 */
function jeab_inkorg_fast_collect_working(JeabPdoInkorgStore $store, string $box, array $filter, int $limit): array
{
    $limit = max(0, $limit);
    // En rad extra, så en full sida kan stanna utan att läsa nästa batch.
    $batch = max(40, min(80, max($limit + 1, 1)));
    $beforeAt = '';
    $beforeId = '';
    $matched = [];
    $seen = [];
    $more = false;
    $sqlDone = false;
    $guard = 0;
    $q = trim((string) ($filter['q'] ?? ''));
    while ($guard < 500) {
        $guard++;
        $rows = jeab_inkorg_fast_normalize_company($store->listWorking($box, $batch, $beforeAt, $beforeId), $box);
        if ($rows === []) {
            $sqlDone = true;
            break;
        }
        if (function_exists('jeab_inkorg_klassning_contacts_prefetch')) {
            try {
                jeab_inkorg_klassning_contacts_prefetch($rows, true);
            } catch (Throwable $e) {
                jeab_inkorg_trace_event('collect_working.prefetch_catch', $e);
            }
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $beforeAt = (string) ($row['_list_at'] ?? $row['updated_at'] ?? '');
            $beforeId = (string) ($row['_list_id'] ?? $row['id'] ?? '');
            unset($row['_list_at'], $row['_list_id']);
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $seen[$id] = true;
            }
            if (!function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($row, $filter)) {
                continue;
            }
            if (count($matched) < $limit) {
                $matched[] = $row;
                continue;
            }
            $more = true;
            break 2;
        }
        if (count($rows) < $batch) {
            $sqlDone = true;
            break;
        }
    }
    if ($q !== '' && $limit > 0) {
        // Nyaste stängda träffarna kan ligga före öppna rader. De hämtas även när öppna sidan är full.
        $closed = jeab_inkorg_fast_normalize_company($store->listHistorikMatching($box, $q, $limit + 1), $box);
        if (function_exists('jeab_inkorg_klassning_contacts_prefetch') && $closed !== []) {
            try {
                jeab_inkorg_klassning_contacts_prefetch($closed, true);
            } catch (Throwable $e) {
                jeab_inkorg_trace_event('collect_working.closed_prefetch_catch', $e);
            }
        }
        $closedRows = [];
        foreach ($closed as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            if (!function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($row, $filter)) {
                continue;
            }
            if ($id !== '') {
                $seen[$id] = true;
            }
            $closedRows[] = $row;
        }
        if (count($closed) > $limit) {
            $more = true;
        }
        if ($closedRows !== []) {
            $matched = array_merge($matched, $closedRows);
            usort($matched, 'jeab_inkorg_fast_row_newer');
        }
        if (count($matched) > $limit) {
            $more = true;
            $matched = array_slice($matched, 0, $limit);
        }
    }
    $withImports = jeab_inkorg_fast_with_imports($matched, $box);
    if ($withImports !== $matched) {
        $merged = [];
        foreach ($withImports as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($row, $filter)) {
                continue;
            }
            $merged[] = $row;
        }
        usort($merged, 'jeab_inkorg_fast_row_newer');
        if (!$sqlDone || $more) {
            if (count($merged) > $limit) {
                $more = true;
                $merged = array_slice($merged, 0, $limit);
            }
        }
        $matched = $merged;
    } elseif ($sqlDone && !$more && count($matched) > $limit) {
        $more = true;
        $matched = array_slice($matched, 0, $limit);
    }

    return ['rows' => $matched, 'more' => $more];
}

/**
 * Mappfiltret Alla (eller inget) släpper igenom varje arbetsrad.
 * Då är COUNT samma tal som listan. Andra mappar klassas i PHP.
 *
 * @param array<string, mixed> $filter
 */
function jeab_inkorg_fast_working_filter_is_all(array $filter): bool
{
    $folder = (string) ($filter['folder'] ?? '');
    if ($folder !== '' && $folder !== 'alla') {
        return false;
    }
    foreach (['q', 'type', 'offer', 'queue', 'assignee', 'tag'] as $key) {
        if (trim((string) ($filter[$key] ?? '')) !== '') {
            return false;
        }
    }

    return empty($filter['types']) && empty($filter['tags_any']) && empty($filter['status']);
}

/**
 * Importkopior som inte redan ligger i listtabellen och som matchar filtret.
 *
 * @param array<string, mixed> $filter
 */
function jeab_inkorg_fast_unlisted_import_matches(JeabPdoInkorgStore $store, string $box, array $filter): int
{
    if ($box === '' || (function_exists('jeab_inkorg_company_live') && jeab_inkorg_company_live($box))) {
        return 0;
    }
    $extra = jeab_inkorg_fast_import_threads($box);
    if ($extra === []) {
        return 0;
    }
    $ids = [];
    foreach ($extra as $thread) {
        if (is_array($thread) && (string) ($thread['id'] ?? '') !== '') {
            $ids[] = (string) $thread['id'];
        }
    }
    $present = array_fill_keys($store->existingIds($ids), true);
    $n = 0;
    foreach ($extra as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $id = (string) ($thread['id'] ?? '');
        if ($id !== '' && isset($present[$id])) {
            continue;
        }
        if (!function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($thread, $filter)) {
            continue;
        }
        $n++;
    }

    return $n;
}

/**
 * En sida av arbetsvyn. Första sidan har samma ordning som hela listan.
 * Alla räknas med COUNT. En ofullständig sida är inte totalen.
 *
 * @param array<string, mixed> $filter
 * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int, exact: bool, search_widened: bool, others_from: int}
 */
function jeab_inkorg_fast_working_listed(JeabPdoInkorgStore $store, string $box, array $filter, int $page, int $size): array
{
    $size = max(1, $size);
    $page = max(1, $page);
    $q = trim((string) ($filter['q'] ?? ''));
    if ($q !== '' && (string) ($filter['assignee'] ?? '') === 'me') {
        return jeab_inkorg_fast_working_me_search($store, $box, $filter, $page, $size);
    }
    $offset = ($page - 1) * $size;
    $collected = jeab_inkorg_fast_collect_working($store, $box, $filter, $offset + $size + 1);
    $found = $collected['rows'];
    $more = $collected['more'];
    $rows = array_slice($found, $offset, $size);
    $exact = !$more;
    if ($more) {
        $total = $offset + count($rows) + 1;
        $pages = $page + 1;
        if (jeab_inkorg_fast_working_filter_is_all($filter)) {
            $sqlTotal = $store->countWorking($box) + jeab_inkorg_fast_unlisted_import_matches($store, $box, $filter);
            if ($sqlTotal >= $offset + count($rows)) {
                $total = $sqlTotal;
                $pages = max(1, (int) ceil($total / $size));
                $exact = true;
            }
        }
    } else {
        $total = count($found);
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $rows = array_slice($found, ($page - 1) * $size, $size);
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'pages' => max(1, $pages),
        'page' => $page,
        'exact' => $exact,
        'search_widened' => false,
        'others_from' => -1,
    ];
}

/**
 * Sök med «mina»: egna träffar först, sedan andras, utan att bygga hela träfflistan.
 *
 * @param array<string, mixed> $filter
 * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int, exact: bool, search_widened: bool, others_from: int}
 */
function jeab_inkorg_fast_working_me_search(JeabPdoInkorgStore $store, string $box, array $filter, int $page, int $size): array
{
    $size = max(1, $size);
    $page = max(1, $page);
    $offset = ($page - 1) * $size;
    $want = $offset + $size;
    $own = jeab_inkorg_fast_collect_working($store, $box, $filter, $want + 1);
    $ownRows = $own['rows'];
    $ownMore = $own['more'];
    $ownCount = $ownMore ? $want + 1 : count($ownRows);
    $othersFrom = -1;
    $widened = false;
    $otherRows = [];
    $otherMore = false;
    $otherCount = 0;
    if ($ownCount <= $offset || ($ownCount < $want && !$ownMore)) {
        $wide = $filter;
        $wide['assignee'] = 'others';
        $otherNeed = $ownMore ? 1 : max(1, $want - count($ownRows) + 1);
        $other = jeab_inkorg_fast_collect_working($store, $box, $wide, $otherNeed);
        $otherRows = $other['rows'];
        $otherMore = $other['more'];
        $otherCount = $otherMore ? $otherNeed : count($otherRows);
        if ($otherRows !== [] && count($ownRows) === 0 && !$ownMore) {
            $widened = true;
        }
    }
    $merged = array_merge($ownMore ? array_slice($ownRows, 0, $want) : $ownRows, $otherRows);
    $rows = array_slice($merged, $offset, $size);
    $exact = !$ownMore && !$otherMore;
    if ($exact) {
        $total = count($ownRows) + $otherCount;
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $rows = array_slice($merged, ($page - 1) * $size, $size);
        $ownTotal = count($ownRows);
    } else {
        $total = $offset + count($rows) + 1;
        $pages = $page + 1;
        $ownTotal = $ownMore ? $want + 1 : count($ownRows);
    }
    if ($otherRows !== [] && $rows !== []) {
        $end = $offset + count($rows);
        if ($ownTotal <= $offset) {
            $othersFrom = 0;
        } elseif ($ownTotal < $end) {
            $othersFrom = $ownTotal - $offset;
        }
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'pages' => max(1, $pages),
        'page' => $page,
        'exact' => $exact,
        'search_widened' => $widened,
        'others_from' => $othersFrom,
    ];
}

/**
 * Kötal från SQL plus de få importkopior som inte redan ligger i listan.
 *
 * @param array<string, int> $counts
 * @return array<string, int>
 */
function jeab_inkorg_fast_sql_queue_bag(JeabPdoInkorgStore $store, string $box, array $counts): array
{
    $me = function_exists('jeab_egna_me_id') ? jeab_egna_me_id() : '';
    $queues = $store->queueCounts($box, $me);
    // Listan tar bara med importkopior för en Help Scout-box (unlisted_import_matches).
    // En live-box fick förr en kopia extra i chippet (Alla öppna 24 mot listans 23).
    $live = function_exists('jeab_inkorg_company_live') && jeab_inkorg_company_live($box);
    $extra = $live ? [] : jeab_inkorg_fast_import_threads($box);
    if ($extra !== []) {
        $ids = [];
        foreach ($extra as $thread) {
            if (is_array($thread) && (string) ($thread['id'] ?? '') !== '') {
                $ids[] = (string) $thread['id'];
            }
        }
        $present = array_fill_keys($store->existingIds($ids), true);
        $fresh = [];
        foreach ($extra as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $id = (string) ($thread['id'] ?? '');
            if ($id !== '' && isset($present[$id])) {
                continue;
            }
            $fresh[] = $thread;
        }
        if ($fresh !== [] && function_exists('jeab_inkorg_queue_counts')) {
            foreach (jeab_inkorg_queue_counts($fresh) as $key => $n) {
                $queues[$key] = (int) ($queues[$key] ?? 0) + (int) $n;
            }
        }
    }
    $hist = (int) ($counts['v-historik'] ?? 0);
    if (function_exists('jeab_inkorg_list_scope') && jeab_inkorg_list_scope() === 'egna') {
        // Köflikarna gäller hela boxen (de leder till scope=allas). Under Egna är
        // v-historik personens tal, så Stängda räknas för boxen som Historik i Alla.
        $hist = jeab_inkorg_historik_total_noddl($box);
    }
    if ($hist > (int) ($queues['stangda'] ?? 0)) {
        $queues['stangda'] = $hist;
    }

    return $queues;
}

/**
 * Lista, räknare och öppet ärende för den här laddningen.
 * $part list hoppar över tråden. $part thread hämtar bara tråden.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_fast_prepare(bool $force = false, string $part = ''): array
{
    $GLOBALS['_JEAB_INKORG_PART'] = $part;
    $views = jeab_inkorg_views();
    $me = jeab_inkorg_me_id();
    $box = jeab_inkorg_box();
    jeab_inkorg_release_session();
    $vyId = jeab_inkorg_requested_view((string) ($_GET['view'] ?? $_GET['vy'] ?? ''), $views, $me);
    $byId = [];
    foreach (jeab_inkorg_company_views($views, $box) as $v) {
        if (is_array($v) && (string) ($v['id'] ?? '') !== '') {
            $byId[(string) $v['id']] = $v;
        }
    }
    $view = $byId[$vyId] ?? null;
    $view = is_array($view) ? $view : ['id' => $vyId, 'name' => 'Inkorg', 'filter' => []];
    if (function_exists('jeab_inkorg_folder_by_id')) {
        $folderView = jeab_inkorg_folder_by_id($vyId);
        if (is_array($folderView)) {
            $view = $folderView;
        }
    }
    $typ = (string) ($_GET['typ'] ?? '');
    $scope = function_exists('jeab_inkorg_list_scope') ? jeab_inkorg_list_scope() : 'allas';
    $egnaMe = $scope === 'egna' && function_exists('jeab_egna_me_id') ? jeab_egna_me_id() : '';
    $ko = function_exists('jeab_inkorg_queue') ? jeab_inkorg_queue() : '';
    $produkt = function_exists('jeab_inkorg_produkt_filter') ? jeab_inkorg_produkt_filter() : '';
    $sok = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['sida'] ?? 1));
    $size = jeab_inkorg_fast_page_size($view, $sok);
    $openId = (string) ($_GET['id'] ?? '');
    if ($openId === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $openId = trim((string) ($_POST['thread'] ?? ''));
    }
    $highlight = $openId !== '' ? $openId : (string) ($_GET['ix_open'] ?? '');
    $menu = function_exists('jeab_inkorg_fast_scope_menu')
        ? jeab_inkorg_fast_scope_menu($scope)
        : jeab_inkorg_menu_views(jeab_inkorg_company_views($views, $box), $vyId);
    $mode = function_exists('jeab_inkorg_storage_mode') ? jeab_inkorg_storage_mode() : '';
    $key = md5(implode('|', ['park-closed', $mode, $me, $egnaMe, $box, $vyId, (string) $page, $sok, $typ, $scope, $ko, $produkt, (string) $size]));
    $fp = jeab_inkorg_messages_fp() . '|' . jeab_inkorg_threads_fp();
    $staff = jeab_inkorg_staff();
    $keep = ['p' => 'inkorg', 'view' => $vyId, 'typ' => $typ, 'scope' => $scope, 'ko' => $ko, 'produkt' => $produkt, 'q' => $sok, 'box' => $box];
    $sql = function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql();
    jeab_inkorg_trace_note(['prepare_sql' => $sql, 'me' => $me !== '']);
    if ($part === 'thread') {
        $open = jeab_inkorg_fast_open($openId);
        $panel = is_array($open) ? ($sql ? jeab_inkorg_sql_related($open) : jeab_inkorg_fast_light()) : [];

        return [
            'vyId' => $vyId,
            'view' => $view,
            'box' => $box,
            'me' => $me,
            'menu' => $menu,
            'html' => '',
            'range' => '',
            'total' => 0,
            'pages' => 1,
            'page' => 1,
            'stale' => 0,
            'counts' => [],
            'counts_fresh' => 0,
            'sources' => [],
            'open' => $open,
            'panel' => $panel,
            'filtered' => $typ !== '' || $sok !== '' || $ko !== '' || $produkt !== '',
            'typ' => $typ,
            'scope' => $scope,
            'ko' => $ko,
            'produkt' => $produkt,
            'queues' => [],
            'search_widened' => false,
            'q' => $sok,
            'staff' => $staff,
        ];
    }
    $cached = ($force || $sql) ? null : jeab_inkorg_fast_swr_read($key, $fp);
    if (is_array($cached) && is_string($cached['html'] ?? null)) {
        jeab_inkorg_trace_source('cache_swr');
        $overlay = $part === ''
            ? jeab_inkorg_page_counts($menu, $box, $me)
            : jeab_inkorg_counts_for_page(jeab_inkorg_fast_counts_ready($menu, $box, $me), (int) ($cached['total'] ?? 0));
        $counts = is_array($overlay['counts'] ?? null) ? $overlay['counts'] : [];
        if ($counts === [] && is_array($cached['counts'] ?? null)) {
            $counts = $cached['counts'];
        }
        $GLOBALS['_JEAB_INKORG_UNREAD'] = (int) ($overlay['unread'] ?? $cached['unread'] ?? 0);
        $open = $part === 'list' ? null : jeab_inkorg_fast_open($openId);
        $panel = [];
        if (is_array($open)) {
            $panel = jeab_inkorg_fast_light();
        }

        return [
            'vyId' => $vyId,
            'view' => $view,
            'box' => $box,
            'me' => $me,
            'menu' => $menu,
            'html' => (string) $cached['html'],
            'range' => (string) ($cached['range'] ?? ''),
            'total' => (int) ($cached['total'] ?? 0),
            'pages' => (int) ($cached['pages'] ?? 1),
            'page' => (int) ($cached['page'] ?? 1),
            'stale' => 0,
            'counts' => $counts,
            'counts_fresh' => (int) ($overlay['fresh'] ?? 0),
            'sources' => (array) ($overlay['sources'] ?? []) !== [] ? (array) $overlay['sources'] : (is_array($cached['sources'] ?? null) ? $cached['sources'] : []),
            'open' => $open,
            'panel' => $panel,
            'filtered' => $typ !== '' || $sok !== '' || $ko !== '' || $produkt !== '',
            'typ' => $typ,
            'scope' => $scope,
            'ko' => $ko,
            'produkt' => $produkt,
            'queues' => is_array($cached['queues'] ?? null) ? $cached['queues'] : [],
            'search_widened' => !empty($cached['search_widened']),
            'q' => $sok,
            'staff' => $staff,
        ];
    }
    $filter = (array) ($view['filter'] ?? []) + ['company' => $box];
    if (in_array($typ, jeab_inkorg_type_list(), true)) {
        $filter['type'] = $typ;
    }
    if ($ko !== '') {
        $filter['queue'] = $ko;
    }
    if ($produkt !== '') {
        $filter['offer'] = $produkt;
    }
    if ($scope === 'egna' && $egnaMe !== '') {
        $filter['assignee'] = 'me';
    }
    // Egna utan känd person: inget personfilter, listan blir Alla (sidan visar en rad om det).
    $filter['q'] = $sok;
    $othersFrom = -1;
    $widened = false;
    $countLight = null;
    $pagedExact = false;
    $histStore = $sql ? jeab_inkorg_pdo_store() : null;
    if ($sql && !$histStore instanceof JeabPdoInkorgStore) {
        jeab_inkorg_trace_event('prepare.sql_store_null');
    }
    jeab_inkorg_trace_note(['egna_me' => $scope === 'egna' ? $egnaMe !== '' : null]);
    if ($histStore instanceof JeabPdoInkorgStore && (string) ($filter['folder'] ?? '') === 'historik') {
        jeab_inkorg_trace_source('sql_historik');
        $listed = jeab_inkorg_fast_historik_listed($histStore, $filter, $page, $size);
        $rows = $listed['rows'];
        $total = (int) $listed['total'];
        $pages = (int) ($listed['import']['pages'] ?? 1);
        $page = (int) ($listed['import']['page'] ?? 1);
        $widened = !empty($listed['search_widened']);
        $light = [];
    } else {
        $skipImport = $sql && $histStore instanceof JeabPdoInkorgStore;
        $pagedExact = false;
        if ($skipImport) {
            jeab_inkorg_sql_list_sync($histStore, '');
        }
        if ($skipImport && $histStore->listStamp() !== '') {
            $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = true;
            jeab_inkorg_trace_source('sql_working_listed');
            try {
                $listed = jeab_inkorg_fast_working_listed($histStore, $box, $filter, $page, $size);
            } finally {
                unset($GLOBALS['_JEAB_INKORG_SKIP_IMPORT']);
            }
            $rows = $listed['rows'];
            $total = (int) $listed['total'];
            $pages = (int) $listed['pages'];
            $page = (int) $listed['page'];
            $widened = !empty($listed['search_widened']);
            $othersFrom = (int) $listed['others_from'];
            $pagedExact = !empty($listed['exact']);
            $light = $rows;
            $countLight = null;
        } else {
            if ($skipImport) {
                $GLOBALS['_JEAB_INKORG_SKIP_IMPORT'] = true;
                $light = jeab_inkorg_fast_working_light($box);
            } else {
                $light = jeab_inkorg_fast_light();
            }
            $countLight = $light;
            try {
                if ($sok !== '' && (string) ($filter['folder'] ?? '') !== 'historik') {
                    $light = jeab_inkorg_search_with_historik($light, $box, $sok, max($size, $page * $size));
                }
                if ($sok !== '' && ($filter['assignee'] ?? '') === 'me') {
                    $want = max($size, $page * $size);
                    $ownListed = jeab_inkorg_view_list($light, $filter, 1, $want);
                    $wide = $filter;
                    $wide['assignee'] = 'others';
                    $ownRows = array_values(array_filter($ownListed['rows'], 'is_array'));
                    $ownTotal = (int) $ownListed['total'];
                    $otherNeed = $ownTotal >= $want ? 0 : $want - $ownTotal;
                    $otherListed = jeab_inkorg_view_list($light, $wide, 1, max(1, $otherNeed));
                    $otherRows = $otherNeed > 0 ? array_values(array_filter($otherListed['rows'], 'is_array')) : [];
                    $otherTotal = (int) $otherListed['total'];
                    $merged = array_merge($ownRows, $otherRows);
                    $total = $ownTotal + $otherTotal;
                    $pages = max(1, (int) ceil($total / $size));
                    $page = max(1, min($pages, $page));
                    $offset = ($page - 1) * $size;
                    $rows = array_slice($merged, $offset, $size);
                    if ($otherRows !== [] && $ownTotal === 0) {
                        $widened = true;
                    }
                    if ($otherTotal > 0 && $rows !== []) {
                        $end = $offset + count($rows);
                        if ($ownTotal <= $offset) {
                            $othersFrom = 0;
                        } elseif ($ownTotal < $end) {
                            $othersFrom = $ownTotal - $offset;
                        }
                    }
                } else {
                    $listed = jeab_inkorg_view_list($light, $filter, $page, $size);
                    $rows = $listed['rows'];
                    $total = (int) $listed['total'];
                    $pages = (int) ($listed['import']['pages'] ?? 1);
                    $page = (int) ($listed['import']['page'] ?? 1);
                }
            } finally {
                if ($skipImport) {
                    unset($GLOBALS['_JEAB_INKORG_SKIP_IMPORT']);
                }
            }
        }
    }
    $bag = $part === ''
        ? jeab_inkorg_page_counts($menu, $box, $me, $countLight)
        : jeab_inkorg_counts_for_page(jeab_inkorg_fast_counts($light, $menu, $box, $me, false), $total);
    $countsNow = is_array($bag['counts'] ?? null) ? $bag['counts'] : [];
    $cacheWarm = (int) ($bag['at'] ?? 0) > 0 && $countsNow !== [];
    if ($countLight === null && $histStore instanceof JeabPdoInkorgStore && $histStore->listStamp() !== '') {
        $hadCountCache = (int) ($bag['at'] ?? 0) > 0;
        if (!$cacheWarm && !$hadCountCache) {
            if ($pagedExact && $vyId !== '') {
                $countsNow[$vyId] = $total;
            }
            if (!array_key_exists('v-historik', $countsNow)) {
                $who = (string) ($filter['assignee'] ?? '');
                $whoId = function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '';
                if (function_exists('jeab_inkorg_list_scope') && jeab_inkorg_list_scope() === 'egna' && function_exists('jeab_egna_me_id')) {
                    $whoId = jeab_egna_me_id();
                }
                if ($who === 'none-person') {
                    $who = 'me';
                    $whoId = '';
                }
                // Live + Help Scout-arkivet, samma som proben. countHistorik räknade bara live (9 mot 20031).
                $countsNow['v-historik'] = jeab_inkorg_historik_total_noddl($box, $who, $whoId, $sok);
            }
            $bag['counts'] = $countsNow;
        } elseif ($cacheWarm && !$pagedExact && $sok === '' && $typ === '' && $ko === '' && $produkt === '' && isset($countsNow[$vyId])) {
            $cachedTotal = (int) $countsNow[$vyId];
            if ($cachedTotal >= $total) {
                $total = $cachedTotal;
                $pages = max(1, (int) ceil(max(1, $total) / max(1, $size)));
            }
        }
        $queues = jeab_inkorg_fast_sql_queue_bag($histStore, $box, $countsNow);
    } else {
        $queueLight = is_array($countLight) ? $countLight : $light;
        if ($queueLight === [] && function_exists('jeab_inkorg_fast_working_light')) {
            $queueLight = jeab_inkorg_fast_working_light($box);
        }
        $queues = jeab_inkorg_fast_queue_bag($queueLight, $countsNow);
    }
    if ($vyId !== '' && $total > 0 && (int) ($countsNow[$vyId] ?? -1) === 0) {
        $countsNow[$vyId] = $total;
        $bag['counts'] = $countsNow;
    }
    jeab_inkorg_trace_note(['rows' => count($rows), 'light' => is_array($light) ? count($light) : null]);
    $html = jeab_inkorg_fast_list_html($rows, $highlight, $me, $keep, $staff, $page, $pages, $othersFrom);
    $range = jeab_inkorg_fast_range(count($rows), $total, $pages, $typ !== '' || $sok !== '');
    $GLOBALS['_JEAB_INKORG_UNREAD'] = (int) $bag['unread'];
    if (!$sql) {
        jeab_inkorg_fast_swr_write($key, [
            'at' => time(),
            'fp' => $fp,
            'html' => $html,
            'range' => $range,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'counts' => $bag['counts'],
            'unread' => $bag['unread'],
            'sources' => $bag['sources'],
            'search_widened' => $widened,
            'queues' => $queues,
        ]);
    }
    $open = $part === 'list' ? null : jeab_inkorg_fast_open($openId);
    $panel = [];
    if (is_array($open)) {
        $panel = $sql ? jeab_inkorg_sql_related($open) : $light;
    }

    return [
        'vyId' => $vyId,
        'view' => $view,
        'box' => $box,
        'me' => $me,
        'menu' => $menu,
        'html' => $html,
        'range' => $range,
        'total' => $total,
        'pages' => $pages,
        'page' => $page,
        'stale' => 0,
        'counts' => $bag['counts'],
        'counts_fresh' => (int) ($bag['fresh'] ?? 0),
        'sources' => $bag['sources'],
        'open' => $open,
        'panel' => $panel,
        'filtered' => $typ !== '' || $sok !== '' || $ko !== '' || $produkt !== '',
        'typ' => $typ,
        'scope' => $scope,
        'ko' => $ko,
        'produkt' => $produkt,
        'queues' => $queues,
        'search_widened' => $widened,
        'q' => $sok,
        'staff' => $staff,
    ];
}

/**
 * Sidans väg för Järfälla: samma jeab_inkorg_fast_prepare som Inkorg-fliken,
 * plus kolumnfrågorna så avståndet mellan lista och probe syns.
 * person eller email simulerar inloggningen. E-post lämnas inte ut.
 * Ingen CREATE och inget schema-uppslag.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_probe(array $input = []): array
{
    $prevPage = $GLOBALS['_JEAB_PAGE_REQUEST'] ?? null;
    $prevGet = $_GET;
    $prevPin = $GLOBALS['_JEAB_INKORG_BOX_PIN'] ?? null;
    $prevForce = $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] ?? null;
    $prevIdent = $GLOBALS['_JEAB_LOGIN_IDENTITY'] ?? null;
    $GLOBALS['_JEAB_PAGE_REQUEST'] = true;
    $queries = [];
    $parts = null;
    $page = [
        'vy' => '',
        'scope' => '',
        'box' => '',
        'me' => '',
        'total' => 0,
        'empty' => true,
        'queues' => [],
        'counts' => [],
        'unread' => 0,
        'cache_at' => 0,
    ];
    $identity = ['certain' => false, 'reason' => 'none', 'person_id' => '', 'name' => ''];
    try {
        if (function_exists('jeab_login_identity_simulate')) {
            $simulated = jeab_inkorg_probe_simulate($input);
            $person = is_array($simulated['person'] ?? null) ? $simulated['person'] : null;
            $identity = [
                'certain' => !empty($simulated['certain']),
                'reason' => (string) ($simulated['reason'] ?? 'none'),
                'person_id' => is_array($person) ? trim((string) ($person['id'] ?? '')) : '',
                'name' => is_array($person) ? trim((string) ($person['name'] ?? '')) : '',
            ];
        }
        $_GET['p'] = 'inkorg';
        $_GET['box'] = 'jarfalla';
        $_GET['view'] = '';
        $_GET['scope'] = 'allas';
        unset($_GET['ko'], $_GET['q'], $_GET['id'], $_GET['vy'], $_GET['typ'], $_GET['produkt'], $_GET['sida']);
        unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);
        $queries[] = jeab_inkorg_probe_call('page.jarfalla', static function () use (&$page): array {
            $prepared = jeab_inkorg_fast_prepare(true, '');
            $counts = is_array($prepared['counts'] ?? null) ? $prepared['counts'] : [];
            $queues = is_array($prepared['queues'] ?? null) ? $prepared['queues'] : [];
            $page = [
                'vy' => (string) ($prepared['vyId'] ?? ''),
                'scope' => (string) ($prepared['scope'] ?? ''),
                'box' => (string) ($prepared['box'] ?? ''),
                'me' => (string) ($prepared['me'] ?? ''),
                'total' => (int) ($prepared['total'] ?? 0),
                'empty' => str_contains((string) ($prepared['html'] ?? ''), 'Inget här just nu'),
                'queues' => $queues,
                'counts' => $counts,
                'unread' => (int) ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0),
                'cache_at' => (int) ($prepared['counts_fresh'] ?? 0) > 0 ? 1 : 0,
            ];

            return [
                'total' => $page['total'],
                'empty' => $page['empty'] ? 1 : 0,
                'oppna' => (int) ($queues['oppna'] ?? 0),
                'v-inkorg' => (int) ($counts['v-inkorg'] ?? 0),
                'v-historik' => (int) ($counts['v-historik'] ?? 0),
            ];
        });
        foreach (['jarfalla', 'malmo'] as $box) {
            $queries[] = jeab_inkorg_probe_call('open.' . $box, static fn (): int => jeab_inkorg_list_open_count_noddl($box));
            $queries[] = jeab_inkorg_probe_call('historik.' . $box, static fn (): int => jeab_inkorg_historik_total_noddl($box));
            $queries[] = jeab_inkorg_probe_call('working.' . $box, static function () use ($box): int {
                $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
                if (!$store instanceof JeabPdoInkorgStore) {
                    return 0;
                }

                return count($store->listWorking($box));
            });
            $queries[] = jeab_inkorg_probe_call('queues.' . $box, static function () use ($box): array {
                $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
                if (!$store instanceof JeabPdoInkorgStore) {
                    return [];
                }

                return $store->queueCounts($box, '');
            });
            $queries[] = jeab_inkorg_probe_call('chips.' . $box, static function () use ($box): array {
                $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
                $light = $store instanceof JeabPdoInkorgStore ? $store->listWorking($box) : [];
                $menu = function_exists('jeab_inkorg_folder_views') ? jeab_inkorg_folder_views() : [];
                $folders = jeab_inkorg_page_counts_from_light($menu, $box, '', $light);

                return [
                    'folders' => is_array($folders['counts'] ?? null) ? $folders['counts'] : [],
                    'menu' => jeab_inkorg_menu_counts_merge([], $light, $box),
                ];
            });
        }
        $queries[] = jeab_inkorg_probe_call('folders.jarfalla', static function (): array {
            $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
            $light = $store instanceof JeabPdoInkorgStore ? $store->listWorking('jarfalla') : [];
            $out = ['rows' => 0, 'inkorg' => 0, 'information' => 0, 'service' => 0, 'historik' => 0, 'deleted' => 0];
            foreach ($light as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out['rows']++;
                if (!empty($row['deleted'])) {
                    $out['deleted']++;
                }
                if (!function_exists('jeab_inkorg_folder_thread')) {
                    continue;
                }
                foreach (['inkorg', 'information', 'service', 'historik'] as $folder) {
                    if (jeab_inkorg_folder_thread($row, $folder)) {
                        $out[$folder]++;
                    }
                }
            }

            return $out;
        });
        if (!empty($input['parts'])) {
            $parts = jeab_inkorg_page_probe($input);
        }
    } finally {
        $_GET = $prevGet;
        if ($prevPin === null) {
            unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
        } else {
            $GLOBALS['_JEAB_INKORG_BOX_PIN'] = $prevPin;
        }
        if ($prevForce === null) {
            unset($GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE']);
        } else {
            $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] = $prevForce;
        }
        if ($prevIdent === null) {
            unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);
        } else {
            $GLOBALS['_JEAB_LOGIN_IDENTITY'] = $prevIdent;
        }
        if ($prevPage === null) {
            unset($GLOBALS['_JEAB_PAGE_REQUEST']);
        } else {
            $GLOBALS['_JEAB_PAGE_REQUEST'] = $prevPage;
        }
    }
    $settings = '';
    $pdo = function_exists('jeab_inkorg_store_pdo') ? jeab_inkorg_store_pdo() : null;
    if ($pdo instanceof PDO && function_exists('jeab_settings_value')) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $settings = (string) (jeab_settings_value($pdo, $driver, 'inkorg_storage') ?? '');
    }
    $file = function_exists('jeab_cutover_storage_file') ? jeab_cutover_storage_file('inkorg_storage') : '';
    $inbox = function_exists('jeab_data_storage') ? jeab_data_storage('inbox', $settings === 'sql' ? 'sql' : 'json') : '';
    $mode = function_exists('jeab_inkorg_storage_mode') ? jeab_inkorg_storage_mode() : '';
    $forced = [];
    foreach (['inbox', 'inbox_threads'] as $type) {
        $forced[$type] = function_exists('jeab_cutover_type_flag') ? jeab_cutover_type_flag($type) : '';
    }
    // Vilken flagga som bestämmer sidans väg. Samma ordning som jeab_inkorg_storage_mode och jeab_data_storage.
    $decidedBy = 'settings';
    if ($file === 'sql' || $file === 'json') {
        $decidedBy = 'file';
    }
    if ($forced['inbox'] !== '') {
        $decidedBy = 'type_list';
    }
    $note = '';
    if ($mode !== 'sql') {
        $note = 'Sidan läser JSON-vägen (messages.json/threads.json), inte inkorg_list. open.* och historik.* är SQL-tabellerna och följer inte flaggan.';
    }

    return [
        'ok' => true,
        'storage' => [
            'mode' => $mode,
            'settings' => $settings,
            'file' => $file,
            'inbox' => $inbox,
            'forced' => $forced,
            'decided_by' => $decidedBy,
            'note' => $note,
        ],
        'identity' => $identity,
        'page' => $page,
        'queries' => $queries,
    ] + ($parts !== null ? ['parts' => $parts] : []);
}

/**
 * Inloggning för proben. person=none ger ingen person (som en okänd inloggning),
 * person=shared ger samma svar som en delad inloggning. Annars jeab_login_identity_simulate.
 *
 * @param array<string, mixed> $input
 * @return array{certain:bool,person:?array,reason:string}
 */
function jeab_inkorg_probe_simulate(array $input): array
{
    $personId = strtolower(trim((string) ($input['person'] ?? $input['person_id'] ?? '')));
    if ($personId === 'none' || $personId === 'shared') {
        $pack = ['certain' => false, 'person' => null, 'reason' => $personId === 'shared' ? 'shared' : 'none'];
        $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] = $pack;
        unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);

        return $pack;
    }

    return jeab_login_identity_simulate($input);
}

/**
 * Sidans väg, del för del. Samma anrop som src/pages/inkorg.php gör för ix_part
 * '', list, all, counts, app och thread, plus menyns sparade siffror.
 * Varje del börjar med tomt minne och visar varifrån räknarna kom (mem, apcu, sql, miss).
 * Inga skrivningar utöver de som sidan själv gör (räknarcachen).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_page_probe(array $input): array
{
    $box = function_exists('jeab_normalize_company') ? (jeab_normalize_company((string) ($input['box'] ?? 'jarfalla')) ?? 'jarfalla') : 'jarfalla';
    $scope = (string) ($input['scope'] ?? '');
    $scope = in_array($scope, ['egna', 'allas'], true) ? $scope : '';
    $view = preg_replace('/[^a-z0-9_-]/i', '', (string) ($input['view'] ?? '')) ?? '';
    $want = ['', 'list', 'all', 'counts', 'app', 'thread'];
    $out = [
        'mode' => jeab_inkorg_count_mode(),
        'ver' => JEAB_INKORG_COUNT_VER,
        'apcu' => function_exists('apcu_fetch') ? 1 : 0,
        'box' => $box,
        'scope_in' => $scope,
        'view_in' => $view,
        'who' => [],
        'mina' => [],
        'runs' => [],
    ];
    $base = ['p' => 'inkorg', 'box' => $box];
    if ($scope !== '') {
        $base['scope'] = $scope;
    }
    if ($view !== '') {
        $base['view'] = $view;
    }
    $prevTrace = $GLOBALS['_JEAB_IXC_TRACE_ON'] ?? null;
    $GLOBALS['_JEAB_IXC_TRACE_ON'] = true;
    $firstId = '';
    try {
        $out['who'] = [
            'me' => function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '',
            'egna_me' => function_exists('jeab_egna_me_id') ? jeab_egna_me_id() : '',
            'name' => function_exists('jeab_who_me_name') ? jeab_who_me_name() : '',
        ];
        if (function_exists('jeab_reminders_sql_mina_name')) {
            foreach (['jarfalla', 'malmo'] as $company) {
                $out['mina'][$company] = jeab_reminders_sql_mina_name($company);
            }
        }
        foreach ($want as $part) {
            $_GET = $base + ($part !== '' ? ['ix_part' => $part] : []);
            if ($part === 'thread') {
                if ($firstId === '') {
                    $out['runs']['thread'] = ['skipped' => 'ingen rad i listan'];
                    continue;
                }
                $_GET['id'] = $firstId;
            }
            unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_INKORG_UNREAD']);
            $GLOBALS['_JEAB_IXC_MEM_RESET'] = true;
            $GLOBALS['_JEAB_IXC_TRACE'] = [];
            $run = jeab_inkorg_probe_call('part.' . ($part === '' ? 'page' : $part), static function () use ($part, &$firstId): array {
                return jeab_inkorg_page_probe_part($part, $firstId);
            });
            $run['trace'] = is_array($GLOBALS['_JEAB_IXC_TRACE'] ?? null) ? $GLOBALS['_JEAB_IXC_TRACE'] : [];
            $out['runs'][$part === '' ? 'page' : $part] = $run;
        }
    } finally {
        if ($prevTrace === null) {
            unset($GLOBALS['_JEAB_IXC_TRACE_ON']);
        } else {
            $GLOBALS['_JEAB_IXC_TRACE_ON'] = $prevTrace;
        }
        unset($GLOBALS['_JEAB_IXC_TRACE'], $GLOBALS['_JEAB_IXC_MEM_RESET']);
    }

    return $out;
}

/**
 * En del av sidan, med samma funktioner och samma ordning som src/pages/inkorg.php.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_page_probe_part(string $part, string &$firstId): array
{
    if ($part === 'counts') {
        $bag = jeab_inkorg_fast_counts_refresh();

        return [
            'counts' => (array) ($bag['counts'] ?? []),
            'unread' => (int) ($bag['unread'] ?? 0),
            'fresh' => (int) ($bag['fresh'] ?? 0),
        ];
    }
    $appCounts = null;
    if ($part === 'app') {
        $appCounts = jeab_inkorg_fast_counts_refresh();
    }
    $prepareAs = ($part === 'list' || $part === 'all') ? 'list' : ($part === 'thread' ? 'thread' : '');
    $fast = jeab_inkorg_fast_prepare(false, $prepareAs);
    $counts = is_array($fast['counts'] ?? null) ? $fast['counts'] : [];
    $fresh = (int) ($fast['counts_fresh'] ?? 0);
    $countsFrom = 'prepare';
    if ($part === 'all') {
        $ready = jeab_inkorg_fast_counts_ready(
            is_array($fast['menu'] ?? null) ? $fast['menu'] : [],
            (string) ($fast['box'] ?? ''),
            (string) ($fast['me'] ?? '')
        );
        $readyMap = is_array($ready['counts'] ?? null) ? $ready['counts'] : [];
        if ((int) ($ready['at'] ?? 0) > 0 && $readyMap !== []) {
            $counts = $readyMap;
            $fresh = (int) ($ready['fresh'] ?? 0);
            $countsFrom = 'ready';
        }
    }
    if ($part === 'app' && is_array($appCounts)) {
        $countsFrom = 'prepare+refresh';
    }
    $html = (string) ($fast['html'] ?? '');
    if ($firstId === '' && preg_match('/data-ix-id="([^"]+)"/', $html, $m) === 1) {
        $firstId = html_entity_decode($m[1], ENT_QUOTES);
    }
    $queues = is_array($fast['queues'] ?? null) ? $fast['queues'] : [];
    $row = [
        'vy' => (string) ($fast['vyId'] ?? ''),
        'scope' => (string) ($fast['scope'] ?? ''),
        'box' => (string) ($fast['box'] ?? ''),
        'me' => (string) ($fast['me'] ?? ''),
        'total' => (int) ($fast['total'] ?? 0),
        'range' => (string) ($fast['range'] ?? ''),
        'rows' => preg_match_all('/data-ix-id="/', $html),
        'empty' => str_contains($html, 'Inget här just nu') ? 1 : 0,
        'queues' => $queues,
        'counts' => $counts,
        'counts_from' => $countsFrom,
        'fresh' => $fresh,
        'unread' => (int) ($GLOBALS['_JEAB_INKORG_UNREAD'] ?? 0),
    ];
    if ($part === 'thread') {
        $row['open'] = is_array($fast['open'] ?? null) ? 1 : 0;
    }
    if ($part === '' && function_exists('jeab_inkorg_sidebar_saved_counts')) {
        $menuViews = is_array($fast['menu'] ?? null) ? $fast['menu'] : [];
        $row['menu_saved'] = jeab_inkorg_sidebar_saved_counts($menuViews);
    }
    if (is_array($appCounts)) {
        $row['app_counts'] = (array) ($appCounts['counts'] ?? []);
    }

    return $row;
}

/** @return array{name:string, ms:int, ok:bool, value:mixed, error:string} */
function jeab_inkorg_probe_call(string $name, callable $fn): array
{
    $t0 = microtime(true);
    try {
        $value = $fn();

        return [
            'name' => $name,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
            'ok' => true,
            'value' => $value,
            'error' => '',
        ];
    } catch (Throwable $e) {
        $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';
        $message = preg_replace('#/[A-Za-z0-9_./-]+#', '', $message) ?? $message;

        return [
            'name' => $name,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
            'ok' => false,
            'value' => null,
            'error' => mb_substr(trim($message), 0, 180),
        ];
    }
}
