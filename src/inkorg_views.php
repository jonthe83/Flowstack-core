<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Vyer: en krysslista (etikett eller typ). Tom lista = alla ärenden utom skräpkategori.
 * Gammalt filter läses oförändrat tills inbox.views_migrate (dry=1 som standard).
 * Parkerat och status rörs inte. inbox.views_migrate tar bort Väntar på offert och Papperskorg.
 */

function jeab_inkorg_view_is_show(array $f): bool
{
    return array_key_exists('show', $f);
}

function jeab_inkorg_view_is_parkerat(array $view): bool
{
    $id = (string) ($view['id'] ?? '');
    $name = mb_strtolower(trim((string) ($view['name'] ?? '')));

    return $id === 'v-vantar' || $name === 'parkerat';
}

/** Historik är en arkivvy. Den öppnas bara via en explicit länk, aldrig som startvy. */
function jeab_inkorg_view_is_historik(array $view): bool
{
    $id = (string) ($view['id'] ?? '');
    $name = mb_strtolower(trim((string) ($view['name'] ?? '')));
    $filter = (array) ($view['filter'] ?? []);

    return $id === 'v-historik' || $name === 'historik' || !empty($filter['historik']);
}

/** Inkorgen har ingen vy för Väntar på offert eller Papperskorg, varken system eller sparad. */
function jeab_inkorg_view_retired(array $view): bool
{
    $id = (string) ($view['id'] ?? '');
    $name = mb_strtolower(trim((string) ($view['name'] ?? '')));

    return in_array($id, ['v-papperskorg', 'v-vantar-offert'], true)
        || in_array($name, ['papperskorg', 'väntar på offert'], true);
}

/** Vyer som inte visas i formuläret. */
function jeab_inkorg_view_hidden_in_editor(array $view): bool
{
    $id = (string) ($view['id'] ?? '');
    $name = mb_strtolower(trim((string) ($view['name'] ?? '')));

    return jeab_inkorg_view_retired($view)
        || $id === 'v-skrap'
        || $name === 'skräp';
}

/**
 * Filterdelar som krysslistan inte visar.
 * De får inte bytas ut när vyn sparas.
 */
function jeab_inkorg_view_has_hidden_filter(array $view): bool
{
    $f = (array) ($view['filter'] ?? []);
    if (!empty($f['historik']) || !empty($f['deleted']) || !empty($f['awaiting_quote']) || !empty($f['review']) || !empty($f['priority'])) {
        return true;
    }
    if ((string) ($f['assignee'] ?? '') !== '' || (array) ($f['types_not'] ?? []) !== []) {
        return true;
    }
    foreach ((array) ($f['tags_any'] ?? []) as $one) {
        if ((string) $one === 'ingen') {
            return true;
        }
    }
    foreach ((array) ($f['status'] ?? []) as $status) {
        if ((string) $status !== '') {
            return true;
        }
    }

    return false;
}

function jeab_inkorg_view_type_label(string $type): string
{
    return match ($type) {
        'Bokning/ombokning' => 'Bokning',
        'Kräver åtgärd från oss' => 'Kräver åtgärd',
        default => $type,
    };
}

/** @return list<array{kind: string, value: string, label: string}> */
function jeab_inkorg_view_choices(): array
{
    $tags = function_exists('jeab_inkorg_tags') ? jeab_inkorg_tags() : [];
    $prefer = ['elbilsladdare', 'sol', 'batteri', 'servicejobb'];
    $out = [];
    foreach ($prefer as $id) {
        if (isset($tags[$id]['name'])) {
            $out[] = ['kind' => 'tag', 'value' => $id, 'label' => (string) $tags[$id]['name']];
        }
    }
    foreach ($tags as $id => $tg) {
        $id = (string) $id;
        if ($id === 'skrap' || in_array($id, $prefer, true)) {
            continue;
        }
        $out[] = ['kind' => 'tag', 'value' => $id, 'label' => (string) ($tg['name'] ?? $id)];
    }
    foreach ([
        'Ny lead' => 'Ny lead',
        'Support' => 'Support',
        'Bokning/ombokning' => 'Bokning',
        'Kräver åtgärd från oss' => 'Kräver åtgärd',
        'Information' => 'Information',
    ] as $value => $label) {
        $out[] = ['kind' => 'type', 'value' => $value, 'label' => $label];
    }

    return $out;
}

/** @return array<string, array{0: string, 1: string}> */
function jeab_inkorg_view_choice_map(): array
{
    $map = [];
    foreach (jeab_inkorg_view_choices() as $c) {
        $map[$c['kind'] . ':' . $c['value']] = [$c['kind'], $c['value']];
    }

    return $map;
}

/** @param list<array{0: string, 1: string}> $show */
function jeab_inkorg_view_show_key(array $show): array
{
    $map = jeab_inkorg_view_choice_map();
    $out = [];
    $seen = [];
    foreach ($show as $item) {
        $key = (string) ($item[0] ?? '') . ':' . (string) ($item[1] ?? '');
        if (!isset($map[$key]) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $map[$key];
    }

    return $out;
}

/** @return list<array{0: string, 1: string}> */
function jeab_inkorg_view_show_from_request(array $in): array
{
    $map = jeab_inkorg_view_choice_map();
    $out = [];
    $seen = [];
    foreach ((array) ($in['show'] ?? []) as $raw) {
        $key = (string) $raw;
        if (!isset($map[$key]) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $map[$key];
    }

    return $out;
}

/** Kryss som formuläret ska visa för en sparad vy. Status läses inte. */
function jeab_inkorg_view_show_selected(array $view): array
{
    $f = (array) ($view['filter'] ?? []);
    if (array_key_exists('show', $f)) {
        return jeab_inkorg_view_show_key(array_values((array) $f['show']));
    }
    $show = [];
    $tag = (string) ($f['tag'] ?? '');
    if ($tag !== '' && $tag !== 'skrap') {
        $show[] = ['tag', $tag];
    }
    foreach ((array) ($f['tags_any'] ?? []) as $one) {
        $one = (string) $one;
        if ($one !== '' && $one !== 'ingen' && $one !== 'skrap') {
            $show[] = ['tag', $one];
        }
    }
    $type = (string) ($f['type'] ?? '');
    if ($type !== '' && $type !== 'Skräp') {
        $show[] = ['type', $type];
    }
    foreach ((array) ($f['types'] ?? []) as $one) {
        $one = (string) $one;
        if ($one !== '' && $one !== 'Skräp') {
            $show[] = ['type', $one];
        }
    }

    return jeab_inkorg_view_show_key($show);
}

function jeab_inkorg_view_summary(array $view): string
{
    $f = (array) ($view['filter'] ?? []);
    if (!array_key_exists('show', $f)) {
        return '';
    }
    $tags = function_exists('jeab_inkorg_tags') ? jeab_inkorg_tags() : [];
    $parts = [];
    foreach (jeab_inkorg_view_show_key(array_values((array) $f['show'])) as $item) {
        if ($item[0] === 'tag') {
            $parts[] = (string) ($tags[$item[1]]['name'] ?? $item[1]);
        } else {
            $parts[] = jeab_inkorg_view_type_label($item[1]);
        }
    }
    if ($parts === []) {
        return 'Visar alla ärenden';
    }

    return 'Visar ärenden som är ' . implode(' eller ', $parts);
}

function jeab_inkorg_view_matches_show(array $t, array $f): bool
{
    if (!empty($t['deleted'])) {
        return false;
    }
    if ((string) ($t['labels']['type'] ?? '') === 'Skräp' || in_array('skrap', array_map('strval', (array) ($t['tags'] ?? [])), true)) {
        return false;
    }
    if (!empty($f['company']) && (string) ($t['company'] ?? '') !== (string) $f['company']) {
        return false;
    }
    if (!empty($f['brand']) && (string) ($t['brand'] ?? '') !== '' && (string) $t['brand'] !== (string) $f['brand']) {
        return false;
    }
    if (!empty($f['brand']) && (string) ($t['brand'] ?? '') === '' && empty($f['brand_keep_unknown'])) {
        return false;
    }
    if (!empty($f['q']) && isset($t['messages']) && is_array($t['messages']) && function_exists('jeab_inkorg_thread_search') && !jeab_inkorg_thread_search($t, (string) $f['q'])) {
        return false;
    }
    $show = jeab_inkorg_view_show_key(array_values((array) ($f['show'] ?? [])));
    if ($show === []) {
        return true;
    }
    $type = (string) ($t['labels']['type'] ?? '');
    $tags = function_exists('jeab_inkorg_effective_tags')
        ? jeab_inkorg_effective_tags($t)
        : array_map('strval', (array) ($t['tags'] ?? []));
    foreach ($show as $item) {
        if ($item[0] === 'tag' && in_array($item[1], $tags, true)) {
            return true;
        }
        if ($item[0] === 'type' && $type === $item[1]) {
            return true;
        }
    }

    return false;
}

function jeab_inkorg_view_is_service(array $view): bool
{
    return (string) ($view['id'] ?? '') === 'v-servicekund' || mb_strtolower(trim((string) ($view['name'] ?? ''))) === 'service';
}

/**
 * @return array{action: string, view: array<string, mixed>}
 */
function jeab_inkorg_view_migrate_one(array $view): array
{
    $f = (array) ($view['filter'] ?? []);
    if (jeab_inkorg_view_is_show($f) || jeab_inkorg_view_is_parkerat($view) || jeab_inkorg_view_hidden_in_editor($view)) {
        return ['action' => jeab_inkorg_view_is_show($f) ? 'already' : 'kept', 'view' => $view];
    }
    if (!empty($f['historik']) || !empty($f['awaiting_quote']) || !empty($f['review']) || !empty($f['deleted']) || !empty($f['priority']) || (string) ($f['assignee'] ?? '') !== '') {
        return ['action' => 'kept', 'view' => $view];
    }
    if (jeab_inkorg_view_is_service($view)) {
        $view['filter'] = ['show' => jeab_inkorg_view_show_key([['tag', 'servicejobb'], ['type', 'Support']])];

        return ['action' => 'show', 'view' => $view];
    }
    if (!empty($f['types_not']) || in_array('ingen', array_map('strval', (array) ($f['tags_any'] ?? [])), true)) {
        return ['action' => 'kept', 'view' => $view];
    }
    $show = jeab_inkorg_view_show_selected($view);
    if ($show === []) {
        return ['action' => 'kept', 'view' => $view];
    }
    $view['filter'] = ['show' => $show];

    return ['action' => 'show', 'view' => $view];
}

function jeab_inkorg_view_count_threads(array $threads, array $filter): int
{
    $n = 0;
    foreach ($threads as $t) {
        if (is_array($t) && function_exists('jeab_inkorg_thread_matches') && jeab_inkorg_thread_matches($t, $filter)) {
            $n++;
        }
    }

    return $n;
}

function jeab_inkorg_views_json_rollback(): bool
{
    return function_exists('jeab_json_sql_rollback') && jeab_json_sql_rollback('inkorg_views_json');
}

/** SQL när tabellen går att läsa. JSON bara i json-läge eller med återgångsflaggan. */
function jeab_inkorg_views_read(): array
{
    if (jeab_inkorg_views_json_rollback()) {
        $disk = function_exists('jeab_inkorg_json_side_disk') ? jeab_inkorg_json_side_disk('rules.json') : null;
        $views = is_array($disk) ? ($disk['views'] ?? []) : [];

        return array_values(is_array($views) ? $views : []);
    }
    if (function_exists('jeab_cutover_views_list')) {
        $sql = jeab_cutover_views_list();
        if (is_array($sql)) {
            return $sql;
        }
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('inkorg_views')) {
        return [];
    }

    return array_values(function_exists('jeab_inkorg_data_part') ? jeab_inkorg_data_part('views') : []);
}

function jeab_inkorg_views_backup_once(array $views): string
{
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('inkorg_views') && function_exists('jeab_cutover_type_boot') && jeab_cutover_type_boot('inkorg_views')) {
        return '';
    }
    $path = jeab_inkorg_dir() . '/views-backup.json';
    if (!is_file($path)) {
        file_put_contents($path, json_encode([
            'saved_at' => date('c'),
            'views' => array_values($views),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    return $path;
}

/** @param list<array<string, mixed>> $views @param list<string> $removedIds */
function jeab_inkorg_views_replace(array $views, array $removedIds = []): void
{
    if (!jeab_inkorg_views_json_rollback() && function_exists('jeab_cutover_sql') && jeab_cutover_sql('inkorg_views') && function_exists('jeab_inkorg_views_sql_replace')) {
        jeab_inkorg_views_sql_replace($views);
        return;
    }
    jeab_inkorg_locked('rules.json', function (?array $d) use ($views, $removedIds) {
        $d = $d ?? [];
        $d['views'] = array_values($views);
        $d['views_show_v1'] = date('c');
        if ($removedIds !== []) {
            $d['removed_views'] = array_values(array_unique(array_merge((array) ($d['removed_views'] ?? []), $removedIds)));
        }

        return [null, $d];
    });
}

/**
 * Ärenden som räknas i Historik, och hur många av dem är raderade (tidigare Papperskorg).
 * Bara antal. Inga namn, adresser eller ämnen.
 *
 * @return array{historik: int, historik_deleted: int}
 */
function jeab_inkorg_views_historik_counts(): array
{
    $filter = ['historik' => true];
    $historik = 0;
    $deleted = 0;
    $seen = [];
    $add = static function (array $thread) use (&$historik, &$deleted, &$seen, $filter): void {
        $id = (string) ($thread['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) {
            return;
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        if (!function_exists('jeab_inkorg_thread_matches') || !jeab_inkorg_thread_matches($thread, $filter)) {
            return;
        }
        $historik++;
        if (!empty($thread['deleted'])) {
            $deleted++;
        }
    };
    foreach (function_exists('jeab_inkorg_threads') ? jeab_inkorg_threads() : [] as $thread) {
        if (is_array($thread)) {
            $add($thread);
        }
    }
    if (function_exists('jeab_hs_import_index') && function_exists('jeab_hs_import_row_thread')) {
        foreach (jeab_hs_import_index()['by_id'] as $row) {
            if (is_array($row)) {
                $add(jeab_hs_import_row_thread($row));
            }
        }
    }

    return ['historik' => $historik, 'historik_deleted' => $deleted];
}

/** inbox.views_migrate. dry=1 som standard. Skriver bara när dry=0. */
function jeab_inkorg_views_migrate(array $input = []): array
{
    $dry = !array_key_exists('dry', $input) || !in_array($input['dry'], [0, '0', false], true);
    $threads = function_exists('jeab_inkorg_threads') ? jeab_inkorg_threads() : [];
    $views = function_exists('jeab_inkorg_views') ? jeab_inkorg_views() : [];
    $next = [];
    $rows = [];
    $writes = 0;
    $removedIds = [];
    foreach ($views as $view) {
        if (!is_array($view)) {
            continue;
        }
        $beforeF = (array) ($view['filter'] ?? []);
        $before = jeab_inkorg_view_count_threads($threads, $beforeF);
        if (jeab_inkorg_view_retired($view)) {
            $rows[] = [
                'id' => (string) ($view['id'] ?? ''),
                'name' => (string) ($view['name'] ?? ''),
                'action' => 'remove',
                'before' => $before,
                'after' => 0,
            ];
            $removedIds[] = (string) ($view['id'] ?? '');
            $writes++;
            continue;
        }
        $plan = jeab_inkorg_view_migrate_one($view);
        $afterF = (array) ($plan['view']['filter'] ?? []);
        $rows[] = [
            'id' => (string) ($view['id'] ?? ''),
            'name' => (string) ($view['name'] ?? ''),
            'action' => $plan['action'],
            'before' => $before,
            'after' => jeab_inkorg_view_count_threads($threads, $afterF),
        ];
        if ($plan['action'] === 'show') {
            $writes++;
        }
        $next[] = $plan['view'];
    }
    $counts = jeab_inkorg_views_historik_counts();
    $backup = '';
    if (!$dry && $writes > 0) {
        $backup = jeab_inkorg_views_backup_once($views);
        jeab_inkorg_views_replace($next, array_values(array_filter($removedIds, static fn(string $id): bool => $id !== '')));
    }

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'updated' => $dry ? 0 : $writes,
        'backup' => $backup !== '' ? basename($backup) : '',
        'removed' => array_values(array_filter($removedIds, static fn(string $id): bool => $id !== '')),
        'historik' => $counts['historik'],
        'historik_deleted' => $counts['historik_deleted'],
        'views' => $rows,
    ];
}

function jeab_inkorg_view_save_simple(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Ange ett namn på vyn.');
    }
    $id = trim((string) ($in['id'] ?? ''));
    $id = $id !== '' ? $id : 'v-' . bin2hex(random_bytes(4));
    $owner = preg_match('/^[A-Za-z0-9]{0,40}$/', (string) ($in['owner'] ?? '')) ? (string) ($in['owner'] ?? '') : '';
    $company = in_array((string) ($in['company'] ?? ''), ['jarfalla', 'malmo'], true) ? (string) $in['company'] : '';
    $view = [
        'id' => $id,
        'name' => mb_substr($name, 0, 40),
        'owner' => $owner,
        'menu' => !empty($in['menu']),
        'company' => $company,
        'filter' => ['show' => jeab_inkorg_view_show_from_request($in)],
    ];

    return jeab_inkorg_locked('rules.json', function (?array $d) use ($view, $in) {
        $d = $d ?? [];
        $views = array_values((array) ($d['views'] ?? []));
        $found = false;
        foreach ($views as $i => $v) {
            if (!is_array($v) || (string) ($v['id'] ?? '') !== $view['id']) {
                continue;
            }
            $keep = (!empty($in['keep_filter']) && jeab_inkorg_view_is_parkerat($v))
                || jeab_inkorg_view_has_hidden_filter($v);
            if ($keep) {
                $v['name'] = $view['name'];
                $v['owner'] = $view['owner'];
                $v['menu'] = $view['menu'];
                $v['company'] = $view['company'];
                $views[$i] = $v;
                $view = $v;
            } else {
                $views[$i] = $view;
            }
            $found = true;
        }
        if (!$found) {
            $views[] = $view;
        }
        $d['views'] = $views;

        return [$view, $d];
    });
}
