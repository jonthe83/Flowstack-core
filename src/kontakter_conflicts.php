<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Hook kontakter.conflicts. dry=1 är standard och skriver inget.
 * dry=0 löser varje konflikt och varje saknat postnummer.
 * Nyaste datan vinner. Samma normaliserade adress slås ihop.
 * Postnummer tas från order, bokning, inkorg, kalkyl eller påminnelse,
 * sedan från gatan, samma ort, och sist från uppslag.
 * Varje ändring loggas i contact_conflict_undo.
 * Order, bokning och påminnelse får inte försvinna eller tappa contact_id.
 * Avviker antalet: transaktionen rullas tillbaka.
 * Svaret innehåller inte personnummer.
 */

function jeab_kontakter_conflicts(array $input, ?JeabStore $store = null): array
{
    $state = jeab_kontakter_conflicts_initial($input);
    $skipLog = function_exists('jeab_kontakt_merge_skip_guard') ? jeab_kontakt_merge_skip_guard(!empty($state['dry'])) : null;
    try {
        $guard = 0;
        do {
            $state = jeab_kontakter_conflicts_chunk($state, $store);
            $guard++;
        } while (empty($state['done']) && $guard < 100000);
        $result = $state['result'] ?? null;

        return is_array($result) ? $result : ['ok' => false, 'dry' => true, 'error' => 'Svepet avbröts.'];
    } finally {
        jeab_kontakter_conflicts_cache_clear();
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_initial(array $input): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;

    return [
        'kind' => 'conflicts',
        'phase' => 'postal',
        'cursor' => 0,
        'sub' => 0,
        'limit' => jeab_kontakter_conflicts_limit($input),
        'total' => 0,
        'gen' => bin2hex(random_bytes(4)),
        'dry' => $dry,
        'decisions' => [],
        'done' => false,
    ];
}

/** @param array<string, mixed> $input */
function jeab_kontakter_conflicts_limit(array $input): int
{
    if (!array_key_exists('limit', $input)) {
        return 500;
    }
    $limit = (int) $input['limit'];
    if ($limit < 1) {
        return 1;
    }
    if ($limit > 2000) {
        return 2000;
    }

    return $limit;
}

/**
 * En bit av svepet. Cursor och limit styr postnummer och behåll-par.
 * Arbetsraderna ligger i minnet, inte i state.
 *
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_chunk(array $state, ?JeabStore $store = null): array
{
    if (!empty($state['done'])) {
        return $state;
    }
    $gen = (string) ($state['gen'] ?? '');
    if ($gen === '') {
        $state['done'] = true;
        $state['phase'] = 'done';
        $state['result'] = ['ok' => false, 'dry' => true, 'error' => 'Svepet saknar körning.'];

        return $state;
    }
    $bag = &jeab_kontakter_conflicts_cache_bag();
    if (!isset($bag[$gen]) || !is_array($bag[$gen])) {
        if (!$store instanceof JeabStore && function_exists('jeab_store')) {
            try {
                $opened = jeab_store();
                $store = $opened instanceof JeabStore ? $opened : null;
            } catch (Throwable) {
                $store = null;
            }
        }
        $bag[$gen] = jeab_kontakter_conflicts_boot($state, $store);
    }
    $cache = &$bag[$gen];
    $phase = (string) ($state['phase'] ?? 'postal');
    if ($phase === 'postal') {
        return jeab_kontakter_conflicts_chunk_postal($state, $cache);
    }
    if ($phase === 'merge') {
        return jeab_kontakter_conflicts_chunk_merge($state, $cache);
    }
    if ($phase === 'keep') {
        return jeab_kontakter_conflicts_chunk_keep($state, $cache);
    }

    return jeab_kontakter_conflicts_chunk_close($state, $cache);
}

/** @return array<string, array<string, mixed>> */
function &jeab_kontakter_conflicts_cache_bag(): array
{
    static $data = [];

    return $data;
}

function jeab_kontakter_conflicts_cache_clear(): void
{
    $bag = &jeab_kontakter_conflicts_cache_bag();
    $bag = [];
}

/**
 * @param array<string, mixed> $state
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_boot(array $state, ?JeabStore $store): array
{
    $rows = jeab_kontakter_conflicts_rows();
    $working = $rows;
    $decisions = is_array($state['decisions'] ?? null) ? $state['decisions'] : [];
    $repoints = jeab_kontakter_conflicts_replay($working, $decisions);
    $ids = array_map('strval', array_keys($rows));
    sort($ids, SORT_STRING);
    $seen = [];
    foreach ($decisions as $decision) {
        if (!is_array($decision) || (string) ($decision['action'] ?? '') !== 'keep') {
            continue;
        }
        $pair = array_values(array_map('strval', (array) ($decision['ids'] ?? [])));
        if (count($pair) !== 2) {
            continue;
        }
        sort($pair, SORT_STRING);
        $seen[$pair[0] . '|' . $pair[1]] = true;
    }
    $phase = (string) ($state['phase'] ?? 'postal');
    $buckets = [];
    if ($phase === 'keep' || $phase === 'close' || $phase === 'done') {
        $buckets = jeab_kontakter_conflicts_keep_buckets($rows, $working);
    }

    return [
        'rows' => $rows,
        'working' => $working,
        'sources' => jeab_kontakter_conflicts_sources($store),
        'liveLinks' => jeab_kontakter_conflicts_live_links($store),
        'activity' => function_exists('jeab_kontakt_activity_index') ? jeab_kontakt_activity_index($rows, $store) : [],
        'ort' => jeab_kontakter_conflicts_ort_index($working),
        'ids' => $ids,
        'repoints' => $repoints,
        'buckets' => $buckets,
        'store' => $store,
        'seen' => $seen,
    ];
}

/**
 * @param array<string, array<string, mixed>> $working
 * @param list<array<string, mixed>> $decisions
 * @return list<array{from:string,to:string}>
 */
function jeab_kontakter_conflicts_replay(array &$working, array $decisions): array
{
    $repoints = [];
    foreach ($decisions as $decision) {
        if (!is_array($decision)) {
            continue;
        }
        $action = (string) ($decision['action'] ?? '');
        if ($action === 'fill') {
            $id = (string) (($decision['ids'][0] ?? ''));
            $postal = (string) ($decision['postal_code'] ?? '');
            if ($id === '' || $postal === '' || !isset($working[$id])) {
                continue;
            }
            $working[$id]['postal_code'] = $postal;
            if (trim((string) ($working[$id]['city'] ?? '')) === '' && trim((string) ($decision['city'] ?? '')) !== '') {
                $working[$id]['city'] = (string) $decision['city'];
            }
            continue;
        }
        if ($action !== 'merge') {
            continue;
        }
        $winner = (string) ($decision['winner'] ?? '');
        if ($winner === '' || !isset($working[$winner])) {
            continue;
        }
        foreach ((array) ($decision['ids'] ?? []) as $loser) {
            $loser = (string) $loser;
            if ($loser === '' || $loser === $winner || !isset($working[$loser])) {
                continue;
            }
            $working[$winner] = jeab_kontakter_conflicts_merge_rows($working[$winner], $working[$loser]);
            unset($working[$loser]);
            $repoints[] = ['from' => $loser, 'to' => $winner];
        }
    }

    return $repoints;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $cache
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_chunk_postal(array $state, array &$cache): array
{
    $ids = is_array($cache['ids'] ?? null) ? $cache['ids'] : [];
    $total = count($ids);
    $cursor = (int) ($state['cursor'] ?? 0);
    $limit = (int) ($state['limit'] ?? 500);
    $processed = 0;
    $working = &$cache['working'];
    $ort = &$cache['ort'];
    $sources = is_array($cache['sources'] ?? null) ? $cache['sources'] : [];
    while ($cursor < $total && $processed < $limit) {
        $id = (string) $ids[$cursor];
        $cursor++;
        $processed++;
        $row = $working[$id] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($row['postal_code'] ?? '')) : '';
        if ($postal !== '') {
            continue;
        }
        $found = jeab_kontakter_conflicts_postal_for($id, $row, $working, $sources, $ort);
        $state['decisions'][] = [
            'action' => $found['postal'] !== '' ? 'fill' : 'keep',
            'ids' => [$id],
            'winner' => $id,
            'postal_code' => $found['postal'],
            'city' => $found['city'],
            'source' => $found['source'],
            'reason' => $found['postal'] !== '' ? 'postnummer från ' . $found['source'] : 'inget postnummer i data eller uppslag',
        ];
        if ($found['postal'] === '') {
            continue;
        }
        $working[$id]['postal_code'] = $found['postal'];
        if (trim((string) ($working[$id]['city'] ?? '')) === '' && $found['city'] !== '') {
            $working[$id]['city'] = $found['city'];
        }
        jeab_kontakter_conflicts_ort_add($ort, $id, $working[$id]);
    }
    $state['cursor'] = $cursor;
    $state['sub'] = 0;
    $state['total'] = $total;
    if ($cursor >= $total) {
        $state['phase'] = 'merge';
        $state['cursor'] = 0;
    }

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $cache
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_chunk_merge(array $state, array &$cache): array
{
    $working = &$cache['working'];
    $activity = is_array($cache['activity'] ?? null) ? $cache['activity'] : [];
    $merges = jeab_kontakter_conflicts_merge_plan($working, $activity);
    $repoints = [];
    foreach ($merges as $merge) {
        $winner = (string) $merge['winner'];
        $losers = (array) $merge['from'];
        if (!isset($working[$winner])) {
            continue;
        }
        $applied = [];
        foreach ($losers as $loser) {
            $loser = (string) $loser;
            if (!isset($working[$loser]) || $loser === $winner) {
                continue;
            }
            $working[$winner] = jeab_kontakter_conflicts_merge_rows($working[$winner], $working[$loser]);
            unset($working[$loser]);
            $repoints[] = ['from' => $loser, 'to' => $winner];
            $applied[] = $loser;
        }
        if ($applied === []) {
            continue;
        }
        $state['decisions'][] = [
            'action' => 'merge',
            'ids' => array_values(array_unique(array_merge([$winner], $applied))),
            'winner' => $winner,
            'postal_code' => (string) ($working[$winner]['postal_code'] ?? ''),
            'city' => (string) ($working[$winner]['city'] ?? ''),
            'source' => (string) $merge['reason'],
            'reason' => (string) $merge['reason'],
        ];
    }
    $rows = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
    $cache['repoints'] = $repoints;
    $cache['buckets'] = jeab_kontakter_conflicts_keep_buckets($rows, $working);
    $state['phase'] = 'keep';
    $state['cursor'] = 0;
    $state['sub'] = 0;
    $state['total'] = count($cache['buckets']);

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $cache
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_chunk_keep(array $state, array &$cache): array
{
    $buckets = is_array($cache['buckets'] ?? null) ? $cache['buckets'] : [];
    $total = count($buckets);
    $cursor = (int) ($state['cursor'] ?? 0);
    $sub = (int) ($state['sub'] ?? 0);
    $limit = (int) ($state['limit'] ?? 500);
    $seen = &$cache['seen'];
    if (!is_array($seen)) {
        $seen = [];
    }
    $produced = 0;
    while ($cursor < $total && $produced < $limit) {
        $ids = is_array($buckets[$cursor] ?? null) ? $buckets[$cursor] : [];
        $n = count($ids);
        $pairIndex = 0;
        $advanced = false;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($pairIndex < $sub) {
                    $pairIndex++;
                    continue;
                }
                $pairIndex++;
                $sub = $pairIndex;
                $advanced = true;
                $pair = [(string) $ids[$i], (string) $ids[$j]];
                sort($pair, SORT_STRING);
                $key = $pair[0] . '|' . $pair[1];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $state['decisions'][] = [
                        'action' => 'keep',
                        'ids' => $pair,
                        'winner' => '',
                        'postal_code' => '',
                        'city' => '',
                        'source' => '',
                        'reason' => 'olika adress, behåll',
                    ];
                    $produced++;
                    if ($produced >= $limit) {
                        break 2;
                    }
                }
            }
        }
        if (!$advanced || $sub >= ($n * ($n - 1) / 2)) {
            $cursor++;
            $sub = 0;
        }
    }
    $state['cursor'] = $cursor;
    $state['sub'] = $sub;
    $state['total'] = $total;
    if ($cursor >= $total) {
        $state['phase'] = 'close';
        $state['cursor'] = 0;
        $state['sub'] = 0;
        $state['total'] = 1;
    }

    return $state;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $cache
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_chunk_close(array $state, array &$cache): array
{
    $rows = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
    $working = is_array($cache['working'] ?? null) ? $cache['working'] : [];
    $liveLinks = is_array($cache['liveLinks'] ?? null) ? $cache['liveLinks'] : [];
    $repoints = is_array($cache['repoints'] ?? null) ? $cache['repoints'] : [];
    $decisions = is_array($state['decisions'] ?? null) ? $state['decisions'] : [];
    $afterLinks = jeab_kontakter_conflicts_repoint_links($liveLinks, $repoints);
    $before = jeab_kontakter_conflicts_census($liveLinks, jeab_kontakter_conflicts_id_set($rows));
    $after = jeab_kontakter_conflicts_census($afterLinks, jeab_kontakter_conflicts_id_set($working));
    $guard = jeab_kontakter_conflicts_guard_reason($liveLinks, $afterLinks, $before, $after);
    $plan = [
        'decisions' => $decisions,
        'rows' => $working,
        'repoints' => $repoints,
        'links' => ['before' => $before, 'after' => $after],
        'guard' => $guard,
    ];
    $store = $cache['store'] ?? null;
    $store = $store instanceof JeabStore ? $store : null;
    $state['result'] = jeab_kontakter_conflicts_response($plan, !empty($state['dry']), $store);
    $state['done'] = true;
    $state['phase'] = 'done';
    $state['cursor'] = 1;
    $state['sub'] = 0;
    $state['total'] = 1;
    $state['decisions'] = [];

    return $state;
}

/**
 * @param array{decisions:list<array<string,mixed>>,rows:array<string,array<string,mixed>>,repoints:list<array{from:string,to:string}>,links:array{before:array<string,int>,after:array<string,int>},guard:string} $plan
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_response(array $plan, bool $dry, ?JeabStore $store): array
{
    $written = false;
    $blocked = '';
    $rolledBack = false;
    $batch = '';
    $error = '';
    if (!$dry) {
        if (!function_exists('jeab_kontakter_writes_sql_only') || !jeab_kontakter_writes_sql_only()) {
            $blocked = 'json';
        } elseif ($plan['guard'] !== '') {
            $rolledBack = true;
            $error = $plan['guard'];
        } elseif (jeab_kontakter_conflicts_mutating($plan['decisions'])) {
            try {
                $batch = jeab_kontakter_conflicts_commit($plan, $store);
                $written = $batch !== '';
            } catch (JeabKontaktConflictGuard $guard) {
                $rolledBack = true;
                $error = $guard->getMessage();
                $plan['links']['after'] = $guard->after;
            } catch (Throwable) {
                $rolledBack = true;
                $error = 'Inget skrevs.';
            }
        }
    }
    $response = [
        'ok' => $error === '' && !$rolledBack,
        'dry' => $dry,
        'written' => $written,
        'blocked' => $blocked,
        'rolled_back' => $rolledBack,
        'batch' => $batch,
        'storage' => function_exists('jeab_kontakter_storage_mode') ? jeab_kontakter_storage_mode() : 'json',
        'counts' => jeab_kontakter_conflicts_decision_counts($plan['decisions']),
        'links' => $plan['links'],
        'guard' => (string) $plan['guard'],
        'decisions' => jeab_kontakter_conflicts_public_decisions($plan['decisions']),
    ];
    if ($error !== '') {
        $response['error'] = $error;
    }

    return jeab_kontakter_conflicts_redact_value($response);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_undo(array $input, ?JeabStore $store = null): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen SQL-databas.'];
    }
    jeab_kontakter_conflicts_ensure_undo($pdo);
    $batch = trim((string) ($input['batch'] ?? ''));
    if ($batch === '') {
        $batch = jeab_kontakter_conflicts_latest_batch($pdo);
    }
    if ($batch === '') {
        return ['ok' => false, 'dry' => $dry, 'error' => 'Ingen körning att ångra.'];
    }
    $entries = jeab_kontakter_conflicts_undo_rows($pdo, $batch);
    if ($entries === []) {
        return ['ok' => false, 'dry' => $dry, 'batch' => $batch, 'error' => 'Körningen är redan ångrad.'];
    }
    $before = jeab_kontakter_conflicts_census(jeab_kontakter_conflicts_live_links($store), jeab_kontakter_conflicts_id_set(jeab_kontakter_conflicts_rows()));
    $written = false;
    $rolledBack = false;
    $error = '';
    if (!$dry) {
        if (!function_exists('jeab_kontakter_writes_sql_only') || !jeab_kontakter_writes_sql_only()) {
            return ['ok' => false, 'dry' => false, 'batch' => $batch, 'blocked' => 'json', 'error' => 'Flaggan är json.'];
        }
        try {
            jeab_kontakter_conflicts_undo_commit($pdo, $store, $batch, $entries);
            $written = true;
        } catch (JeabKontaktConflictGuard $guard) {
            $rolledBack = true;
            $error = $guard->getMessage();
        } catch (Throwable) {
            $rolledBack = true;
            $error = 'Inget ångrades.';
        }
    }
    $afterLinks = $written
        ? jeab_kontakter_conflicts_live_links($store)
        : jeab_kontakter_conflicts_project_undo($entries, jeab_kontakter_conflicts_live_links($store));
    $afterIds = $written
        ? jeab_kontakter_conflicts_id_set(jeab_kontakter_conflicts_rows())
        : jeab_kontakter_conflicts_project_undo_ids($entries, jeab_kontakter_conflicts_id_set(jeab_kontakter_conflicts_rows()));
    $response = [
        'ok' => $error === '' && !$rolledBack,
        'dry' => $dry,
        'written' => $written,
        'rolled_back' => $rolledBack,
        'batch' => $batch,
        'entries' => count($entries),
        'links' => [
            'before' => $before,
            'after' => jeab_kontakter_conflicts_census($afterLinks, $afterIds),
        ],
    ];
    if ($error !== '') {
        $response['error'] = $error;
    }
    return jeab_kontakter_conflicts_redact_value($response);
}

/**
 * Arbetar mot SQL när tabellen har rader. all() kan fortfarande visa JSON-rader
 * som bara finns i filen, men de ska inte bli en ny sammanslagning varje körning.
 *
 * @return array<string, array<string, mixed>>
 */
function jeab_kontakter_conflicts_rows(): array
{
    $source = [];
    $pdoStore = function_exists('jeab_kontakter_pdo_store') ? jeab_kontakter_pdo_store() : null;
    if ($pdoStore instanceof JeabPdoContactStore) {
        $sql = $pdoStore->sqlAll();
        if ($sql !== []) {
            $source = $sql;
        }
    }
    if ($source === [] && function_exists('jeab_kontakter_load')) {
        $source = jeab_kontakter_load();
    }
    $rows = [];
    foreach ($source as $id => $row) {
        if (is_array($row)) {
            $rows[(string) ($row['id'] ?? $id)] = $row;
        }
    }
    return $rows;
}

/**
 * @param array<string, array<string, mixed>> $rows
 * @param array<string, list<array{source:string,postal:string,city:string,street_key:string,at:int}>> $sources
 * @param list<array{entity:string,id:string,contact_id:string}> $liveLinks
 * @return array{decisions:list<array<string,mixed>>,rows:array<string,array<string,mixed>>,repoints:list<array{from:string,to:string}>,links:array{before:array<string,int>,after:array<string,int>},guard:string}
 */
function jeab_kontakter_conflicts_simulate(array $rows, array $sources, array $liveLinks, ?JeabStore $store): array
{
    $working = $rows;
    $decisions = [];
    $activity = function_exists('jeab_kontakt_activity_index') ? jeab_kontakt_activity_index($working, $store) : [];
    $ortIndex = jeab_kontakter_conflicts_ort_index($working);
    foreach ($working as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($row['postal_code'] ?? '')) : '';
        if ($postal !== '') {
            continue;
        }
        $found = jeab_kontakter_conflicts_postal_for((string) $id, $row, $working, $sources, $ortIndex);
        $decisions[] = [
            'action' => $found['postal'] !== '' ? 'fill' : 'keep',
            'ids' => [(string) $id],
            'winner' => (string) $id,
            'postal_code' => $found['postal'],
            'city' => $found['city'],
            'source' => $found['source'],
            'reason' => $found['postal'] !== '' ? 'postnummer från ' . $found['source'] : 'inget postnummer i data eller uppslag',
        ];
        if ($found['postal'] === '') {
            continue;
        }
        $working[$id]['postal_code'] = $found['postal'];
        if (trim((string) ($working[$id]['city'] ?? '')) === '' && $found['city'] !== '') {
            $working[$id]['city'] = $found['city'];
        }
        jeab_kontakter_conflicts_ort_add($ortIndex, (string) $id, $working[$id]);
    }
    $merges = jeab_kontakter_conflicts_merge_plan($working, $activity);
    $repoints = [];
    foreach ($merges as $merge) {
        $winner = (string) $merge['winner'];
        $losers = (array) $merge['from'];
        if (!isset($working[$winner])) {
            continue;
        }
        foreach ($losers as $loser) {
            $loser = (string) $loser;
            if (!isset($working[$loser]) || $loser === $winner) {
                continue;
            }
            $working[$winner] = jeab_kontakter_conflicts_merge_rows($working[$winner], $working[$loser]);
            unset($working[$loser]);
            $repoints[] = ['from' => $loser, 'to' => $winner];
        }
        $decisions[] = [
            'action' => 'merge',
            'ids' => array_values(array_unique(array_merge([(string) $winner], array_map('strval', $losers)))),
            'winner' => (string) $winner,
            'postal_code' => (string) ($working[$winner]['postal_code'] ?? ''),
            'city' => (string) ($working[$winner]['city'] ?? ''),
            'source' => (string) $merge['reason'],
            'reason' => (string) $merge['reason'],
        ];
    }
    foreach (jeab_kontakter_conflicts_keep_pairs($rows, $working) as $pair) {
        $decisions[] = $pair;
    }
    $afterLinks = jeab_kontakter_conflicts_repoint_links($liveLinks, $repoints);
    $beforeIds = jeab_kontakter_conflicts_id_set($rows);
    $afterIds = jeab_kontakter_conflicts_id_set($working);
    $before = jeab_kontakter_conflicts_census($liveLinks, $beforeIds);
    $after = jeab_kontakter_conflicts_census($afterLinks, $afterIds);
    $guard = jeab_kontakter_conflicts_guard_reason($liveLinks, $afterLinks, $before, $after);
    return [
        'decisions' => $decisions,
        'rows' => $working,
        'repoints' => $repoints,
        'links' => ['before' => $before, 'after' => $after],
        'guard' => $guard,
    ];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, array<string, mixed>> $rows
 * @param array<string, list<array{source:string,postal:string,city:string,street_key:string,at:int}>> $sources
 * @param array<string, list<array{id:string,postal:string,city:string,stamp:int}>>|null $ortIndex
 * @return array{postal:string,city:string,source:string}
 */
function jeab_kontakter_conflicts_postal_for(string $id, array $row, array $rows, array $sources, ?array $ortIndex = null): array
{
    $empty = ['postal' => '', 'city' => '', 'source' => ''];
    $best = $empty;
    $bestAt = -1;
    foreach ($sources[$id] ?? [] as $place) {
        if (($place['postal'] ?? '') === '' || (int) ($place['at'] ?? 0) <= $bestAt) {
            continue;
        }
        $streetKey = jeab_kontakter_conflicts_street_key($row);
        $placeStreet = (string) ($place['street_key'] ?? '');
        if ($streetKey !== '' && $placeStreet !== '' && function_exists('jeab_kontakt_street_keys_equal') && !jeab_kontakt_street_keys_equal($streetKey, $placeStreet)) {
            continue;
        }
        $bestAt = (int) ($place['at'] ?? 0);
        $best = [
            'postal' => (string) $place['postal'],
            'city' => jeab_kontakt_city_name((string) ($place['city'] ?? '')),
            'source' => (string) ($place['source'] ?? 'existing'),
        ];
    }
    if ($best['postal'] !== '') {
        return $best;
    }
    $fromStreet = jeab_kontakter_conflicts_from_address((string) ($row['street'] ?? ''));
    if ($fromStreet['postal'] !== '') {
        return ['postal' => $fromStreet['postal'], 'city' => $fromStreet['city'], 'source' => 'address'];
    }
    $sibling = is_array($ortIndex)
        ? jeab_kontakter_conflicts_ort_lookup($ortIndex, $id, $row)
        : jeab_kontakter_conflicts_from_ort($id, $row, $rows);
    if ($sibling['postal'] !== '') {
        return $sibling;
    }
    $street = trim((string) ($row['street'] ?? ''));
    if ($street !== '' && function_exists('jeab_kontakt_lookup_postort')) {
        $found = jeab_kontakt_lookup_postort($street);
        $postal = (string) ($found['postal_code'] ?? '');
        if ($postal === '') {
            $city = jeab_kontakt_city_name((string) ($row['city'] ?? ''));
            if ($city !== '') {
                $found = jeab_kontakt_lookup_postort(trim($street . ' ' . $city));
                $postal = (string) ($found['postal_code'] ?? '');
            }
        }
        if ($postal !== '') {
            $city = jeab_kontakt_city_name((string) ($found['city'] ?? ''));
            return ['postal' => $postal, 'city' => $city, 'source' => 'lookup'];
        }
    }
    return $empty;
}

/**
 * @param array<string, array<string, mixed>> $rows
 * @param array<string, int> $activity
 * @return list<array{winner:string,from:list<string>,reason:string}>
 */
function jeab_kontakter_conflicts_merge_plan(array $rows, array $activity): array
{
    $parent = [];
    foreach (array_keys($rows) as $id) {
        $parent[(string) $id] = (string) $id;
    }
    $find = static function (string $id) use (&$parent, &$find): string {
        if (($parent[$id] ?? $id) !== $id) {
            $parent[$id] = $find($parent[$id]);
        }
        return $parent[$id] ?? $id;
    };
    $join = static function (string $a, string $b) use (&$parent, $find): void {
        $left = $find($a);
        $right = $find($b);
        if ($left !== $right) {
            $parent[$right] = $left;
        }
    };
    $byAddress = [];
    foreach ($rows as $id => $row) {
        $key = jeab_kontakter_conflicts_address_key($row);
        if ($key !== '') {
            $byAddress[$key][] = (string) $id;
        }
    }
    foreach ($byAddress as $ids) {
        $first = (string) $ids[0];
        $n = count($ids);
        for ($i = 1; $i < $n; $i++) {
            $join($first, (string) $ids[$i]);
        }
    }
    $buckets = [];
    foreach ($rows as $id => $row) {
        foreach (jeab_kontakter_conflicts_channels($row) as $channel) {
            $buckets[$channel][] = (string) $id;
        }
    }
    foreach ($buckets as $channel => $ids) {
        $ids = array_values(array_unique($ids));
        $n = count($ids);
        $rule = str_starts_with((string) $channel, 'p:') ? 'phone' : 'email';
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $left = $rows[$ids[$i]];
                $right = $rows[$ids[$j]];
                $keyA = function_exists('jeab_kontakt_channel_address_key') ? jeab_kontakt_channel_address_key($left) : '';
                $keyB = function_exists('jeab_kontakt_channel_address_key') ? jeab_kontakt_channel_address_key($right) : '';
                if (!($keyA !== '' && $keyB !== '' && $keyA !== $keyB)) {
                    $join($ids[$i], $ids[$j]);
                    continue;
                }
                if (function_exists('jeab_kontakt_merge_skip_log')) {
                    jeab_kontakt_merge_skip_log($rule, (string) $ids[$i], (string) $ids[$j], 'olika adress');
                }
            }
        }
    }
    $groups = [];
    foreach (array_keys($rows) as $id) {
        $groups[$find((string) $id)][] = (string) $id;
    }
    $out = [];
    foreach ($groups as $members) {
        if (count($members) < 2) {
            continue;
        }
        $winner = jeab_kontakter_conflicts_newest_id($rows, $activity, $members);
        $from = [];
        foreach ($members as $id) {
            if ($id !== $winner) {
                $from[] = $id;
            }
        }
        $reason = 'samma adress';
        $winnerKey = jeab_kontakter_conflicts_address_key($rows[$winner]);
        foreach ($from as $id) {
            if (jeab_kontakter_conflicts_address_key($rows[$id]) !== $winnerKey) {
                $reason = 'samma kanal, nyaste vinner';
                break;
            }
        }
        $out[] = ['winner' => $winner, 'from' => $from, 'reason' => $reason];
    }
    return $out;
}

/**
 * @param array<string, array<string, mixed>> $beforeRows
 * @param array<string, array<string, mixed>> $afterRows
 * @return list<array<string, mixed>>
 */
function jeab_kontakter_conflicts_keep_pairs(array $beforeRows, array $afterRows): array
{
    $seen = [];
    $out = [];
    foreach (jeab_kontakter_conflicts_keep_buckets($beforeRows, $afterRows) as $ids) {
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $pair = [(string) $ids[$i], (string) $ids[$j]];
                sort($pair, SORT_STRING);
                $key = $pair[0] . '|' . $pair[1];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'action' => 'keep',
                    'ids' => $pair,
                    'winner' => '',
                    'postal_code' => '',
                    'city' => '',
                    'source' => '',
                    'reason' => 'olika adress, behåll',
                ];
            }
        }
    }

    return $out;
}

/**
 * Bara kanaler som delas av minst två levande kontakter.
 *
 * @param array<string, array<string, mixed>> $beforeRows
 * @param array<string, array<string, mixed>> $afterRows
 * @return list<list<string>>
 */
function jeab_kontakter_conflicts_keep_buckets(array $beforeRows, array $afterRows): array
{
    $buckets = [];
    foreach ($afterRows as $id => $row) {
        $id = (string) $id;
        if (!isset($beforeRows[$id]) || !is_array($beforeRows[$id])) {
            continue;
        }
        foreach (jeab_kontakter_conflicts_channels($beforeRows[$id]) as $channel) {
            $buckets[$channel][$id] = true;
        }
        unset($row);
    }
    ksort($buckets, SORT_STRING);
    $out = [];
    foreach ($buckets as $ids) {
        if (count($ids) < 2) {
            continue;
        }
        $list = array_map('strval', array_keys($ids));
        sort($list, SORT_STRING);
        $out[] = $list;
    }

    return $out;
}

/** @param array<string, mixed> $row @return list<string> */
function jeab_kontakter_conflicts_channels(array $row): array
{
    $out = [];
    foreach (jeab_kontakter_conflicts_emails($row) as $email) {
        $out[] = 'e:' . $email;
    }
    foreach (jeab_kontakter_conflicts_phones($row) as $phone) {
        $out[] = 'p:' . $phone;
    }

    return $out;
}

/**
 * @param array<string, mixed> $plan
 */
function jeab_kontakter_conflicts_commit(array $plan, ?JeabStore $store): string
{
    $pdo = jeab_contact_store_pdo();
    if (!$pdo instanceof PDO || !$store instanceof JeabPdoStore) {
        throw new RuntimeException('Ingen SQL-databas.');
    }
    jeab_kontakter_conflicts_ensure_undo($pdo);
    $batch = bin2hex(random_bytes(8));
    $purchaseSnap = jeab_purchase_sql_pdo() instanceof PDO ? null : (function_exists('jeab_purchase_all') ? jeab_purchase_all() : null);
    $beforeLinks = jeab_kontakter_conflicts_live_links($store);
    try {
    jeab_pdo_transaction($pdo, static function () use ($pdo, $store, $plan, $batch, $purchaseSnap, $beforeLinks): void {
        try {
            $current = (new JeabPdoContactStore($pdo))->sqlAll();
            $seq = 0;
            $undo = [];
            foreach ($plan['decisions'] as $decision) {
                if (($decision['action'] ?? '') !== 'fill') {
                    continue;
                }
                $id = (string) (($decision['ids'][0] ?? ''));
                if ($id === '' || !isset($current[$id]) || (string) ($decision['postal_code'] ?? '') === '') {
                    continue;
                }
                $before = $current[$id];
                $current[$id]['postal_code'] = (string) $decision['postal_code'];
                if (trim((string) ($current[$id]['city'] ?? '')) === '' && trim((string) ($decision['city'] ?? '')) !== '') {
                    $current[$id]['city'] = (string) $decision['city'];
                }
                $seq++;
                $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'fill', 'contact', $id, $before, $current[$id]);
            }
            foreach ($plan['repoints'] as $repoint) {
                $from = (string) $repoint['from'];
                $to = (string) $repoint['to'];
                if (!isset($current[$from], $current[$to])) {
                    continue;
                }
                $before = $current[$to];
                $current[$to] = jeab_kontakter_conflicts_merge_rows($current[$to], $current[$from]);
                $seq++;
                $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'merge', 'contact', $to, $before, $current[$to]);
                $moved = jeab_kontakter_conflicts_repoint_pdo($pdo, $from, $to, $batch, $seq);
                foreach ($moved['undo'] as $entry) {
                    $undo[] = $entry;
                }
                $seq = $moved['seq'];
                $seq++;
                $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'merge', 'contact', $from, $current[$from], null);
                unset($current[$from]);
            }
            if (!empty($GLOBALS['_JEAB_CONFLICTS_DROP_LINK'])) {
                $hit = $pdo->query('SELECT id FROM reminders ORDER BY id LIMIT 1');
                $reminderId = $hit instanceof PDOStatement ? $hit->fetchColumn() : false;
                if ($reminderId !== false) {
                    $pdo->prepare('UPDATE reminders SET contact_id = \'\' WHERE id = ?')->execute([(int) $reminderId]);
                }
            }
            (new JeabPdoContactStore($pdo))->replaceAll($current);
            jeab_kontakter_conflicts_undo_insert($pdo, $undo);
            if (function_exists('jeab_purchase_cache_forget')) {
                jeab_purchase_cache_forget();
            }
            $live = jeab_kontakter_conflicts_live_links($store);
            $census = jeab_kontakter_conflicts_census($live, jeab_kontakter_conflicts_id_set($current));
            $reason = jeab_kontakter_conflicts_guard_reason($beforeLinks, $live, $plan['links']['before'], $census);
            if ($reason !== '') {
                throw new JeabKontaktConflictGuard($reason, $plan['links']['before'], $census);
            }
        } catch (Throwable $e) {
            if (is_array($purchaseSnap) && function_exists('jeab_purchase_save_all')) {
                try {
                    jeab_purchase_save_all($purchaseSnap);
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    });
    } finally {
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
    }
    return $batch;
}

/**
 * @param list<array{entity:string,id:string,contact_id:string}> $beforeLinks
 * @param list<array{entity:string,id:string,contact_id:string}> $afterLinks
 * @param array<string, int> $before
 * @param array<string, int> $after
 */
function jeab_kontakter_conflicts_guard_reason(array $beforeLinks, array $afterLinks, array $before, array $after): string
{
    foreach (['reminders', 'bookings', 'orders', 'inbox', 'unknown'] as $key) {
        if ((int) ($before[$key] ?? 0) !== (int) ($after[$key] ?? 0)) {
            return 'Antalet ' . $key . ' ändrades.';
        }
    }
    $afterMap = [];
    foreach ($afterLinks as $link) {
        $afterMap[$link['entity'] . ':' . $link['id']] = (string) $link['contact_id'];
    }
    foreach ($beforeLinks as $link) {
        $key = $link['entity'] . ':' . $link['id'];
        if (!array_key_exists($key, $afterMap)) {
            return 'En post försvann.';
        }
        if ((string) $link['contact_id'] !== '' && $afterMap[$key] === '') {
            return 'En kontaktkoppling försvann.';
        }
    }
    return '';
}

/**
 * @param list<array{entity:string,id:string,contact_id:string}> $links
 * @param array<string, bool> $contactIds
 * @return array{reminders:int,bookings:int,orders:int,inbox:int,unknown:int}
 */
function jeab_kontakter_conflicts_census(array $links, array $contactIds): array
{
    $counts = ['reminders' => 0, 'bookings' => 0, 'orders' => 0, 'inbox' => 0, 'unknown' => 0];
    foreach ($links as $link) {
        $entity = (string) ($link['entity'] ?? '');
        if (!isset($counts[$entity])) {
            continue;
        }
        $counts[$entity]++;
        $contactId = trim((string) ($link['contact_id'] ?? ''));
        if ($contactId !== '' && !isset($contactIds[$contactId])) {
            $counts['unknown']++;
        }
    }
    return $counts;
}

/** @param list<array{from:string,to:string}> $repoints @param list<array{entity:string,id:string,contact_id:string}> $links */
function jeab_kontakter_conflicts_repoint_links(array $links, array $repoints): array
{
    $map = [];
    foreach ($repoints as $repoint) {
        $map[(string) $repoint['from']] = (string) $repoint['to'];
    }
    $out = [];
    foreach ($links as $link) {
        $id = (string) $link['contact_id'];
        $guard = 0;
        while (isset($map[$id]) && $map[$id] !== $id && $guard < 20) {
            $id = $map[$id];
            $guard++;
        }
        $link['contact_id'] = $id;
        $out[] = $link;
    }
    return $out;
}

/**
 * @return array{undo:list<array<string,mixed>>,seq:int}
 */
function jeab_kontakter_conflicts_repoint_pdo(PDO $pdo, string $from, string $to, string $batch, int $seq): array
{
    $undo = [];
    foreach ([['reminders', 'id', 'reminder'], ['bookings', 'id', 'booking']] as $spec) {
        if (!jeab_kontakter_conflicts_has_table($pdo, $spec[0])) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT ' . $spec[1] . ' AS id, contact_id FROM ' . $spec[0] . ' WHERE contact_id = ?');
        $stmt->execute([$from]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids[] = (string) ($row['id'] ?? '');
        }
        if ($ids === []) {
            continue;
        }
        $update = $pdo->prepare('UPDATE ' . $spec[0] . ' SET contact_id = ? WHERE contact_id = ?');
        $update->execute([$to, $from]);
        foreach ($ids as $id) {
            if ($id === '') {
                continue;
            }
            $seq++;
            $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'repoint', $spec[2], $id, ['contact_id' => $from], ['contact_id' => $to]);
        }
    }
    if (jeab_kontakter_conflicts_has_table($pdo, 'purchases') && jeab_purchase_sql_pdo() === $pdo && function_exists('jeab_sql_purchases_load')) {
        foreach (jeab_sql_purchases_load($pdo) as $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
                continue;
            }
            $before = ['contact_id' => $from];
            $row['contact_id'] = $to;
            jeab_sql_purchases_put($pdo, $row);
            $seq++;
            $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'repoint', 'order', (string) ($row['reminder_id'] ?? ''), $before, ['contact_id' => $to]);
        }
    } elseif (function_exists('jeab_purchase_all') && function_exists('jeab_purchase_save_all') && !jeab_purchase_sql_pdo() instanceof PDO) {
        $all = jeab_purchase_all();
        $changed = false;
        foreach ($all as $key => $row) {
            if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $from) {
                continue;
            }
            $seq++;
            $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'repoint', 'order', (string) ($row['reminder_id'] ?? $key), ['contact_id' => $from], ['contact_id' => $to]);
            $row['contact_id'] = $to;
            $all[$key] = $row;
            $changed = true;
        }
        if ($changed) {
            jeab_purchase_save_all($all);
        }
    }
    if (jeab_kontakter_conflicts_has_table($pdo, 'inkorg_contacts')) {
        $stmt = $pdo->query('SELECT id, row_json FROM inkorg_contacts');
        if ($stmt instanceof PDOStatement) {
            $update = $pdo->prepare('UPDATE inkorg_contacts SET row_json = ? WHERE id = ?');
            foreach ($stmt->fetchAll() as $dbRow) {
                if (!is_array($dbRow)) {
                    continue;
                }
                $data = json_decode((string) ($dbRow['row_json'] ?? ''), true);
                if (!is_array($data) || (string) ($data['contact_id'] ?? '') !== $from) {
                    continue;
                }
                $data['contact_id'] = $to;
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                if (!is_string($json)) {
                    continue;
                }
                $update->execute([$json, (string) $dbRow['id']]);
                $seq++;
                $undo[] = jeab_kontakter_conflicts_undo_entry($batch, $seq, 'repoint', 'inbox', (string) $dbRow['id'], ['contact_id' => $from], ['contact_id' => $to]);
            }
        }
    }
    return ['undo' => $undo, 'seq' => $seq];
}

/**
 * @param list<array<string, mixed>> $entries
 */
function jeab_kontakter_conflicts_undo_commit(PDO $pdo, ?JeabStore $store, string $batch, array $entries): void
{
    $purchaseSnap = jeab_purchase_sql_pdo() instanceof PDO ? null : (function_exists('jeab_purchase_all') ? jeab_purchase_all() : null);
    $beforeLinks = jeab_kontakter_conflicts_live_links($store);
    $before = jeab_kontakter_conflicts_census($beforeLinks, jeab_kontakter_conflicts_id_set(jeab_kontakter_conflicts_rows()));
    try {
    jeab_pdo_transaction($pdo, static function () use ($pdo, $store, $batch, $entries, $purchaseSnap, $beforeLinks, $before): void {
        try {
            $storePdo = new JeabPdoContactStore($pdo);
            $current = $storePdo->sqlAll();
            foreach (array_reverse($entries) as $entry) {
                $kind = (string) ($entry['kind'] ?? '');
                $entity = (string) ($entry['entity'] ?? '');
                $entityId = (string) ($entry['entity_id'] ?? '');
                $beforeRow = json_decode((string) ($entry['before_json'] ?? ''), true);
                $afterRow = json_decode((string) ($entry['after_json'] ?? ''), true);
                if ($entity === 'contact' && $kind === 'merge' && !is_array($afterRow) && is_array($beforeRow)) {
                    $current[$entityId] = $beforeRow;
                    continue;
                }
                if ($entity === 'contact' && is_array($beforeRow)) {
                    $current[$entityId] = $beforeRow;
                    continue;
                }
                if ($kind === 'repoint' && is_array($beforeRow)) {
                    jeab_kontakter_conflicts_repoint_one($pdo, $entity, $entityId, (string) ($beforeRow['contact_id'] ?? ''));
                }
            }
            $storePdo->replaceAll($current);
            $pdo->prepare('UPDATE contact_conflict_undo SET undone = 1 WHERE batch_id = ?')->execute([$batch]);
            if (function_exists('jeab_purchase_cache_forget')) {
                jeab_purchase_cache_forget();
            }
            $live = jeab_kontakter_conflicts_live_links($store);
            $census = jeab_kontakter_conflicts_census($live, jeab_kontakter_conflicts_id_set($current));
            $reason = jeab_kontakter_conflicts_guard_reason($beforeLinks, $live, $before, $census);
            if ($reason !== '') {
                throw new JeabKontaktConflictGuard($reason, $before, $census);
            }
        } catch (Throwable $e) {
            if (is_array($purchaseSnap) && function_exists('jeab_purchase_save_all')) {
                try {
                    jeab_purchase_save_all($purchaseSnap);
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    });
    } finally {
        if (function_exists('jeab_purchase_cache_forget')) {
            jeab_purchase_cache_forget();
        }
    }
}

function jeab_kontakter_conflicts_repoint_one(PDO $pdo, string $entity, string $id, string $contactId): void
{
    if ($entity === 'reminder' && jeab_kontakter_conflicts_has_table($pdo, 'reminders')) {
        $pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$contactId, (int) $id]);
        return;
    }
    if ($entity === 'booking' && jeab_kontakter_conflicts_has_table($pdo, 'bookings')) {
        $pdo->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$contactId, (int) $id]);
        return;
    }
    if ($entity === 'order') {
        if (jeab_purchase_sql_pdo() === $pdo && function_exists('jeab_sql_purchases_get')) {
            $row = jeab_sql_purchases_get($pdo, (int) $id);
            if (is_array($row)) {
                $row['contact_id'] = $contactId;
                jeab_sql_purchases_put($pdo, $row);
            }
            return;
        }
        if (function_exists('jeab_purchase_all') && function_exists('jeab_purchase_save_all')) {
            $all = jeab_purchase_all();
            if (isset($all[$id]) && is_array($all[$id])) {
                $all[$id]['contact_id'] = $contactId;
                jeab_purchase_save_all($all);
            }
        }
        return;
    }
    if ($entity === 'inbox' && jeab_kontakter_conflicts_has_table($pdo, 'inkorg_contacts')) {
        $stmt = $pdo->prepare('SELECT row_json FROM inkorg_contacts WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $data['contact_id'] = $contactId;
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (is_string($json)) {
                $pdo->prepare('UPDATE inkorg_contacts SET row_json = ? WHERE id = ?')->execute([$json, $id]);
            }
        }
    }
}

/** @return list<array{entity:string,id:string,contact_id:string}> */
function jeab_kontakter_conflicts_live_links(?JeabStore $store): array
{
    $out = [];
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = ['entity' => 'reminders', 'id' => (string) ($row['id'] ?? ''), 'contact_id' => trim((string) ($row['contact_id'] ?? ''))];
        }
        foreach ($store->listAllBookings() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = ['entity' => 'bookings', 'id' => (string) ($row['id'] ?? ''), 'contact_id' => trim((string) ($row['contact_id'] ?? ''))];
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = ['entity' => 'orders', 'id' => (string) ($row['reminder_id'] ?? $key), 'contact_id' => trim((string) ($row['contact_id'] ?? ''))];
        }
    }
    if (function_exists('jeab_kontakt_inbox_read')) {
        foreach (jeab_kontakt_inbox_read() as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = ['entity' => 'inbox', 'id' => (string) ($row['id'] ?? $id), 'contact_id' => trim((string) ($row['contact_id'] ?? ''))];
        }
    }
    return $out;
}

/**
 * @return array<string, list<array{source:string,postal:string,city:string,street_key:string,at:int}>>
 */
function jeab_kontakter_conflicts_sources(?JeabStore $store): array
{
    $out = [];
    $add = static function (string $id, string $source, string $address, string $postal, string $city, int $at) use (&$out): void {
        $id = trim($id);
        $place = jeab_kontakter_conflicts_place($address, $postal, $city);
        if ($id === '' || $place['postal'] === '') {
            return;
        }
        $out[$id][] = $place + ['source' => $source, 'at' => $at];
    };
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $add((string) ($row['contact_id'] ?? ''), 'reminder', (string) ($row['address'] ?? ''), (string) ($row['postal'] ?? ''), (string) ($row['ort'] ?? ''), jeab_kontakter_conflicts_stamp((string) ($row['updated_at'] ?? $row['created_at'] ?? '')));
        }
        foreach ($store->listAllBookings() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $add((string) ($row['contact_id'] ?? ''), 'booking', (string) ($row['address'] ?? ''), '', '', jeab_kontakter_conflicts_stamp((string) ($row['updated_at'] ?? $row['starts_at'] ?? '')));
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $at = jeab_kontakter_conflicts_stamp((string) ($row['updated_at'] ?? $row['created_at'] ?? ''));
            $add((string) ($row['contact_id'] ?? ''), 'order', (string) ($row['address'] ?? $row['gata'] ?? ''), (string) ($row['postnummer'] ?? $row['postnr'] ?? ''), (string) ($row['ort'] ?? ''), $at);
            $kalkyl = is_array($row['kalkyl'] ?? null) ? $row['kalkyl'] : [];
            $add((string) ($row['contact_id'] ?? ''), 'kalkyl', (string) ($kalkyl['address'] ?? $kalkyl['gata'] ?? ''), (string) ($kalkyl['postnummer'] ?? $kalkyl['postal_code'] ?? ''), (string) ($kalkyl['ort'] ?? $kalkyl['city'] ?? ''), $at);
        }
    }
    if (function_exists('jeab_kontakt_inbox_read')) {
        foreach (jeab_kontakt_inbox_read() as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $add((string) ($row['contact_id'] ?? ''), 'inbox', (string) ($row['address'] ?? ''), (string) ($row['postal_code'] ?? $row['postal'] ?? ''), (string) ($row['town'] ?? $row['city'] ?? ''), jeab_kontakter_conflicts_stamp((string) ($row['updated_at'] ?? $row['created_at'] ?? '')));
            unset($id);
        }
    }
    return $out;
}

/**
 * @return array{postal:string,city:string,street_key:string}
 */
function jeab_kontakter_conflicts_place(string $address, string $postalField, string $cityField): array
{
    $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal($postalField) : '';
    $city = jeab_kontakt_city_name($cityField);
    $street = '';
    if (function_exists('jeab_address_parts')) {
        $parts = jeab_address_parts($address);
        if ($postal === '') {
            $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($parts['postal'] ?? '')) : '';
        }
        if ($city === '') {
            $city = jeab_kontakt_city_name((string) ($parts['city'] ?? ''));
        }
        $street = trim((string) ($parts['street'] ?? ''));
    }
    if ($postalField === '' && preg_match('/(?<!\d)\d{6,}(?!\d)/u', $address) === 1) {
        $postal = '';
    }
    $streetKey = $street !== '' && function_exists('jeab_kontakt_effective_street_key') ? jeab_kontakt_effective_street_key($street, '') : '';
    return ['postal' => $postal, 'city' => $city, 'street_key' => $streetKey];
}

/**
 * @param array<string, array<string, mixed>> $rows
 * @return array<string, list<array{id:string,postal:string,city:string,stamp:int}>>
 */
function jeab_kontakter_conflicts_ort_index(array $rows): array
{
    $index = [];
    foreach ($rows as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        jeab_kontakter_conflicts_ort_add($index, (string) $id, $row);
    }

    return $index;
}

/**
 * @param array<string, list<array{id:string,postal:string,city:string,stamp:int}>> $index
 * @param array<string, mixed> $row
 */
function jeab_kontakter_conflicts_ort_add(array &$index, string $id, array $row): void
{
    $key = jeab_kontakter_conflicts_ort_key($row);
    $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($row['postal_code'] ?? '')) : '';
    if ($key === '' || $postal === '') {
        return;
    }
    $index[$key][] = [
        'id' => $id,
        'postal' => $postal,
        'city' => function_exists('jeab_kontakt_city_name') ? jeab_kontakt_city_name((string) ($row['city'] ?? '')) : trim((string) ($row['city'] ?? '')),
        'stamp' => jeab_kontakter_conflicts_row_stamp($row),
    ];
}

/** @param array<string, mixed> $row */
function jeab_kontakter_conflicts_ort_key(array $row): string
{
    $street = jeab_kontakter_conflicts_street_key($row);
    $city = function_exists('jeab_kontakt_city_key') ? jeab_kontakt_city_key((string) ($row['city'] ?? '')) : '';
    if ($street === '' || $city === '') {
        return '';
    }
    $canon = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($street) : $street;
    if ($canon === '') {
        $canon = $street;
    }

    return $canon . '|' . $city;
}

/**
 * @param array<string, list<array{id:string,postal:string,city:string,stamp:int}>> $index
 * @param array<string, mixed> $row
 * @return array{postal:string,city:string,source:string}
 */
function jeab_kontakter_conflicts_ort_lookup(array $index, string $id, array $row): array
{
    $empty = ['postal' => '', 'city' => '', 'source' => 'ort'];
    $key = jeab_kontakter_conflicts_ort_key($row);
    if ($key === '') {
        return $empty;
    }
    $bestStamp = -1;
    $bestPostal = '';
    $city = '';
    foreach ($index[$key] ?? [] as $item) {
        if ((string) ($item['id'] ?? '') === $id || (string) ($item['postal'] ?? '') === '') {
            continue;
        }
        if ($city === '') {
            $city = (string) ($item['city'] ?? '');
        }
        $stamp = (int) ($item['stamp'] ?? 0);
        if ($stamp >= $bestStamp) {
            $bestStamp = $stamp;
            $bestPostal = (string) $item['postal'];
        }
    }
    if ($bestPostal === '') {
        return $empty;
    }

    return ['postal' => $bestPostal, 'city' => $city, 'source' => 'ort'];
}

/**
 * @param array<string, array<string, mixed>> $rows
 * @return array{postal:string,city:string,source:string}
 */
function jeab_kontakter_conflicts_from_ort(string $id, array $row, array $rows): array
{
    $empty = ['postal' => '', 'city' => '', 'source' => 'ort'];
    $streetKey = jeab_kontakter_conflicts_street_key($row);
    $cityKey = function_exists('jeab_kontakt_city_key') ? jeab_kontakt_city_key((string) ($row['city'] ?? '')) : '';
    if ($streetKey === '' || $cityKey === '') {
        return $empty;
    }
    $postals = [];
    $city = '';
    foreach ($rows as $otherId => $other) {
        if ((string) $otherId === $id || !is_array($other)) {
            continue;
        }
        if (!jeab_kontakt_street_keys_equal($streetKey, jeab_kontakter_conflicts_street_key($other))) {
            continue;
        }
        $otherCity = function_exists('jeab_kontakt_city_key') ? jeab_kontakt_city_key((string) ($other['city'] ?? '')) : '';
        if ($otherCity === '' || $otherCity !== $cityKey) {
            continue;
        }
        $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($other['postal_code'] ?? '')) : '';
        if ($postal === '') {
            continue;
        }
        $postals[$postal] = jeab_kontakter_conflicts_row_stamp($other);
        if ($city === '') {
            $city = jeab_kontakt_city_name((string) ($other['city'] ?? ''));
        }
    }
    if ($postals === []) {
        return $empty;
    }
    arsort($postals);
    return ['postal' => (string) array_key_first($postals), 'city' => $city, 'source' => 'ort'];
}

/** @return array{postal:string,city:string} */
function jeab_kontakter_conflicts_from_address(string $street): array
{
    $empty = ['postal' => '', 'city' => ''];
    if ($street === '' || !function_exists('jeab_kontakt_postal_scan') || preg_match('/(?<!\d)\d{6,}(?!\d)/u', $street) === 1) {
        return $empty;
    }
    $scan = jeab_kontakt_postal_scan($street);
    if (($scan['postal'] ?? '') === '' || !empty($scan['invalid'])) {
        return $empty;
    }
    $tail = trim(substr($street, (int) $scan['offset'] + (int) $scan['length']));
    $city = jeab_kontakt_city_name(trim($tail, " ,"));
    if ($city !== '' && preg_match('/\d/u', $city) === 1) {
        $city = '';
    }
    return ['postal' => (string) $scan['postal'], 'city' => $city];
}

/** @param array<string, mixed> $winner @param array<string, mixed> $loser */
function jeab_kontakter_conflicts_merge_rows(array $winner, array $loser): array
{
    foreach (['street', 'street_key', 'postal_code', 'city', 'fastighetsbeteckning'] as $field) {
        if (trim((string) ($winner[$field] ?? '')) === '' && trim((string) ($loser[$field] ?? '')) !== '') {
            $winner[$field] = (string) $loser[$field];
        }
    }
    if (function_exists('jeab_kontakt_postal')) {
        $winner['postal_code'] = jeab_kontakt_postal((string) ($winner['postal_code'] ?? ''));
    }
    $owners = [];
    foreach (is_array($winner['owners'] ?? null) ? $winner['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    foreach (is_array($loser['owners'] ?? null) ? $loser['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $name = trim((string) ($owner['name'] ?? ''));
        $placed = false;
        foreach ($owners as $i => $have) {
            $same = $name !== '' && function_exists('jeab_kontakt_names_same') && jeab_kontakt_names_same($name, (string) ($have['name'] ?? ''));
            if (!$same) {
                continue;
            }
            if (trim((string) ($have['personnummer'] ?? '')) === '' && trim((string) ($owner['personnummer'] ?? '')) !== '') {
                $owners[$i]['personnummer'] = (string) $owner['personnummer'];
            }
            if (trim((string) ($have['email'] ?? '')) === '' && trim((string) ($owner['email'] ?? '')) !== '') {
                $owners[$i]['email'] = (string) $owner['email'];
            }
            if (trim((string) ($have['phone'] ?? '')) === '' && trim((string) ($owner['phone'] ?? '')) !== '') {
                $owners[$i]['phone'] = (string) $owner['phone'];
            }
            $placed = true;
            break;
        }
        if ($placed || $name === '' || count($owners) >= 2) {
            continue;
        }
        $owners[] = $owner;
    }
    $winner['owners'] = $owners;
    $winner['id'] = (string) ($winner['id'] ?? '');
    return $winner;
}

/**
 * @param array<string, array<string, mixed>> $rows
 * @param array<string, int> $activity
 * @param list<string> $ids
 */
function jeab_kontakter_conflicts_newest_id(array $rows, array $activity, array $ids): string
{
    $winner = (string) $ids[0];
    $best = -1;
    foreach ($ids as $id) {
        $id = (string) $id;
        $stamp = max(jeab_kontakter_conflicts_row_stamp($rows[$id] ?? []), (int) ($activity[$id] ?? 0));
        if ($stamp > $best || ($stamp === $best && $id > $winner)) {
            $best = $stamp;
            $winner = $id;
        }
    }
    return $winner;
}

/** @param array<string, mixed> $row */
function jeab_kontakter_conflicts_address_key(array $row): string
{
    $street = jeab_kontakter_conflicts_street_key($row);
    if ($street === '') {
        return '';
    }
    $postal = function_exists('jeab_kontakt_postal') ? jeab_kontakt_postal((string) ($row['postal_code'] ?? '')) : '';
    if ($postal !== '') {
        return $street . '|' . $postal;
    }
    $city = function_exists('jeab_kontakt_city_key') ? jeab_kontakt_city_key((string) ($row['city'] ?? '')) : '';
    return $city !== '' ? $street . '|ort:' . $city : '';
}

/** @param array<string, mixed> $row */
function jeab_kontakter_conflicts_row_stamp(array $row): int
{
    return max(
        jeab_kontakter_conflicts_stamp((string) ($row['updated_at'] ?? '')),
        jeab_kontakter_conflicts_stamp((string) ($row['created_at'] ?? ''))
    );
}

function jeab_kontakter_conflicts_stamp(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts === false ? 0 : $ts;
}

/** @param array<string, mixed>|null $before @param array<string, mixed>|null $after @return array<string, mixed> */
function jeab_kontakter_conflicts_undo_entry(string $batch, int $seq, string $kind, string $entity, string $id, ?array $before, ?array $after): array
{
    return [
        'batch_id' => $batch,
        'seq' => $seq,
        'kind' => $kind,
        'entity' => $entity,
        'entity_id' => $id,
        'before_json' => jeab_kontakter_conflicts_undo_json($before),
        'after_json' => jeab_kontakter_conflicts_undo_json($after),
        'created_at' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
    ];
}

/** @param list<array<string, mixed>> $rows */
function jeab_kontakter_conflicts_undo_insert(PDO $pdo, array $rows): void
{
    if ($rows === []) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO contact_conflict_undo (batch_id, seq, kind, entity, entity_id, before_json, after_json, created_at, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
    foreach ($rows as $row) {
        $stmt->execute([
            $row['batch_id'],
            $row['seq'],
            $row['kind'],
            $row['entity'],
            $row['entity_id'],
            $row['before_json'],
            $row['after_json'],
            $row['created_at'],
        ]);
    }
}

function jeab_kontakter_conflicts_ensure_undo(PDO $pdo): void
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_ensure_contact_conflict_undo')) {
        jeab_ensure_contact_conflict_undo($pdo, $driver);
    }
}

function jeab_kontakter_conflicts_latest_batch(PDO $pdo): string
{
    if (!jeab_kontakter_conflicts_has_table($pdo, 'contact_conflict_undo')) {
        return '';
    }
    $value = $pdo->query('SELECT batch_id FROM contact_conflict_undo WHERE undone = 0 ORDER BY id DESC LIMIT 1');
    $batch = $value instanceof PDOStatement ? $value->fetchColumn() : false;
    return is_string($batch) ? $batch : '';
}

/** @return list<array<string, mixed>> */
function jeab_kontakter_conflicts_undo_rows(PDO $pdo, string $batch): array
{
    $stmt = $pdo->prepare('SELECT batch_id, seq, kind, entity, entity_id, before_json, after_json FROM contact_conflict_undo WHERE batch_id = ? AND undone = 0 ORDER BY seq ASC');
    $stmt->execute([$batch]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/**
 * @param list<array<string, mixed>> $entries
 * @param list<array{entity:string,id:string,contact_id:string}> $links
 * @return list<array{entity:string,id:string,contact_id:string}>
 */
function jeab_kontakter_conflicts_project_undo(array $entries, array $links): array
{
    $map = [];
    foreach ($entries as $entry) {
        if ((string) ($entry['kind'] ?? '') !== 'repoint') {
            continue;
        }
        $before = json_decode((string) ($entry['before_json'] ?? ''), true);
        $after = json_decode((string) ($entry['after_json'] ?? ''), true);
        if (!is_array($before) || !is_array($after)) {
            continue;
        }
        $map[jeab_kontakter_conflicts_link_entity((string) ($entry['entity'] ?? '')) . ':' . (string) ($entry['entity_id'] ?? '')] = (string) ($before['contact_id'] ?? '');
    }
    foreach ($links as $i => $link) {
        $key = $link['entity'] . ':' . $link['id'];
        if (isset($map[$key])) {
            $links[$i]['contact_id'] = $map[$key];
        }
    }
    return $links;
}

/**
 * @param list<array<string, mixed>> $entries
 * @param array<string, bool> $ids
 * @return array<string, bool>
 */
function jeab_kontakter_conflicts_project_undo_ids(array $entries, array $ids): array
{
    foreach ($entries as $entry) {
        if ((string) ($entry['entity'] ?? '') !== 'contact') {
            continue;
        }
        $id = (string) ($entry['entity_id'] ?? '');
        $before = json_decode((string) ($entry['before_json'] ?? ''), true);
        if ((string) ($entry['after_json'] ?? '') === 'null' && is_array($before)) {
            $ids[$id] = true;
        }
    }
    return $ids;
}

/** @param array<string, array<string, mixed>> $rows @return array<string, bool> */
function jeab_kontakter_conflicts_id_set(array $rows): array
{
    $out = [];
    foreach (array_keys($rows) as $id) {
        $out[(string) $id] = true;
    }
    return $out;
}

/** @param list<array<string, mixed>> $decisions */
function jeab_kontakter_conflicts_mutating(array $decisions): bool
{
    foreach ($decisions as $decision) {
        $action = (string) ($decision['action'] ?? '');
        if ($action === 'merge' || ($action === 'fill' && (string) ($decision['postal_code'] ?? '') !== '')) {
            return true;
        }
    }
    return false;
}

/** @param list<array<string, mixed>> $decisions @return array<string, int> */
function jeab_kontakter_conflicts_decision_counts(array $decisions): array
{
    $counts = ['decisions' => count($decisions), 'fill' => 0, 'merge' => 0, 'keep' => 0, 'manual' => 0];
    foreach ($decisions as $decision) {
        $action = (string) ($decision['action'] ?? '');
        if (isset($counts[$action])) {
            $counts[$action]++;
        }
    }
    return $counts;
}

/** @param list<array<string, mixed>> $decisions @return list<array<string, mixed>> */
function jeab_kontakter_conflicts_public_decisions(array $decisions): array
{
    $out = [];
    foreach ($decisions as $decision) {
        $ids = array_values(array_map('strval', (array) ($decision['ids'] ?? [])));
        sort($ids, SORT_STRING);
        $out[] = [
            'action' => (string) ($decision['action'] ?? ''),
            'ids' => $ids,
            'winner' => (string) ($decision['winner'] ?? ''),
            'postal_code' => (string) ($decision['postal_code'] ?? ''),
            'city' => (string) ($decision['city'] ?? ''),
            'source' => (string) ($decision['source'] ?? ''),
            'reason' => (string) ($decision['reason'] ?? ''),
        ];
    }
    return $out;
}

function jeab_kontakter_conflicts_has_table(PDO $pdo, string $table): bool
{
    if (!in_array($table, ['reminders', 'bookings', 'purchases', 'inkorg_contacts', 'contact_conflict_undo'], true)) {
        return false;
    }
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @param array<string, mixed> $row @return list<string> */
function jeab_kontakter_conflicts_emails(array $row): array
{
    if (!function_exists('jeab_kontakt_row_emails')) {
        return [];
    }
    $out = [];
    foreach (jeab_kontakt_row_emails($row) as $email) {
        if ($email === '' || (function_exists('jeab_kontakt_email_is_staff_or_platform') && jeab_kontakt_email_is_staff_or_platform($email))) {
            continue;
        }
        $out[$email] = true;
    }
    return array_keys($out);
}

/** @param array<string, mixed> $row @return list<string> */
function jeab_kontakter_conflicts_phones(array $row): array
{
    $out = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner) || !function_exists('jeab_kontakt_phone')) {
            continue;
        }
        $phone = jeab_kontakt_phone((string) ($owner['phone'] ?? ''));
        if ($phone === '' || (function_exists('jeab_kontakt_phone_blocked') && jeab_kontakt_phone_blocked($phone))) {
            continue;
        }
        $out[$phone] = true;
    }
    return array_keys($out);
}

/** @param array<string, mixed> $row */
function jeab_kontakter_conflicts_street_key(array $row): string
{
    if (!function_exists('jeab_kontakt_effective_street_key')) {
        return trim((string) ($row['street_key'] ?? ''));
    }
    return jeab_kontakt_effective_street_key((string) ($row['street'] ?? ''), (string) ($row['street_key'] ?? ''));
}

function jeab_kontakter_conflicts_undo_json(?array $value): string
{
    if ($value === null) {
        return 'null';
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : 'null';
}

function jeab_kontakter_conflicts_link_entity(string $entity): string
{
    return match ($entity) {
        'reminder' => 'reminders',
        'booking' => 'bookings',
        'order' => 'orders',
        default => $entity,
    };
}

function jeab_kontakter_conflicts_redact(string $text): string
{
    return preg_replace('/\d{10,}/', '######', $text) ?? $text;
}

/** @param mixed $value */
function jeab_kontakter_conflicts_redact_value(mixed $value): mixed
{
    if (is_string($value)) {
        return jeab_kontakter_conflicts_redact($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        $name = (string) $key;
        if (in_array($name, ['personnummer', 'pnr', 'SocialSecurityNumber', 'OrganisationNumber', 'before_json', 'after_json'], true)) {
            continue;
        }
        // batch är bin2hex(random_bytes(8)). Tio siffror i rad är vanligt och är inte ett personnummer.
        // Redigering bytte ut id:t, och ångra hittade inte körningen.
        if ($name === 'batch' && is_string($item)) {
            $out[$key] = $item;
            continue;
        }
        $out[$key] = jeab_kontakter_conflicts_redact_value($item);
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_conflicts_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    if ($action === 'kontakter.conflicts_undo') {
        $result = jeab_kontakter_conflicts_undo($input);
        $result['status'] = !empty($result['ok']) ? 200 : 400;
        return $result;
    }
    require_once __DIR__ . '/data_jobs.php';

    return jeab_data_job_accept('kontakter.conflicts', $input);
}

final class JeabKontaktConflictGuard extends RuntimeException
{
    /** @param array<string, int> $before @param array<string, int> $after */
    public function __construct(string $message, public array $before, public array $after)
    {
        parent::__construct($message);
    }
}
