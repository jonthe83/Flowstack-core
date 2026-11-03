<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * contacts.merge_runs och rows.keys är skrivskyddade.
 * Svaret har id, nyckelnamn och tider. Inga värden och inga personuppgifter.
 * ordrar.get fields:1 använder jeab_hook_readonly_id och jeab_hook_readonly_field_names.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hook_readonly_dispatch(array $input): array
{
    $action = trim((string) ($input['action'] ?? ''));
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'Fel token.',
            'status' => 401,
            'action' => $action,
        ]);
    }
    if ($action === 'contacts.merge_runs') {
        return jeab_hook_readonly_merge_runs($input);
    }
    if ($action === 'rows.keys') {
        return jeab_hook_readonly_rows_keys($input);
    }

    return jeab_hook_readonly_public([
        'ok' => false,
        'error' => 'Okänd åtgärd.',
        'status' => 400,
        'action' => $action,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hook_readonly_merge_runs(array $input): array
{
    $action = 'contacts.merge_runs';
    $company = jeab_hook_readonly_company_filter($input);
    if (isset($company['error'])) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => (string) $company['error'],
            'status' => 422,
            'action' => $action,
        ]);
    }
    $pdo = jeab_hook_readonly_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'SQL saknas.',
            'status' => 400,
            'action' => $action,
        ]);
    }
    $loaded = jeab_hook_readonly_merge_load($pdo);
    $want = (string) ($company['company'] ?? '');
    $runs = [];
    foreach ($loaded as $undoId => $pack) {
        if ($want !== '' && !jeab_hook_readonly_merge_company($pdo, $pack['rows'], $want)) {
            continue;
        }
        $runs[] = [
            'undo_id' => $undoId,
            'at' => (string) $pack['at'],
            'rows' => $pack['public'],
        ];
    }

    $body = [
        'ok' => true,
        'action' => $action,
        'runs' => $runs,
        'status' => 200,
    ];
    if ($want !== '') {
        $body['co'] = $want;
    }

    return jeab_hook_readonly_public($body);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_hook_readonly_rows_keys(array $input): array
{
    $action = 'rows.keys';
    $tbl = trim((string) ($input['tbl'] ?? ''));
    $allowed = ['purchases', 'reminders', 'bookings', 'inbox_threads'];
    if (!in_array($tbl, $allowed, true)) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'tbl ska vara purchases, reminders, bookings eller inbox_threads.',
            'status' => 422,
            'action' => $action,
        ]);
    }
    $id = jeab_hook_readonly_row_id($tbl, $input['id'] ?? null);
    if ($id === '') {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'id krävs.',
            'status' => 422,
            'action' => $action,
            'tbl' => $tbl,
        ]);
    }
    $company = jeab_hook_readonly_company_filter($input);
    if (isset($company['error'])) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => (string) $company['error'],
            'status' => 422,
            'action' => $action,
            'tbl' => $tbl,
            'id' => $id,
        ]);
    }
    $beforeRaw = array_key_exists('before', $input) ? trim((string) $input['before']) : '';
    $beforeTs = null;
    if (array_key_exists('before', $input)) {
        $beforeTs = jeab_hook_readonly_parse_time($beforeRaw);
        if ($beforeTs === null) {
            return jeab_hook_readonly_public([
                'ok' => false,
                'error' => 'before ska vara en ISO-tid.',
                'status' => 422,
                'action' => $action,
                'tbl' => $tbl,
                'id' => $id,
            ]);
        }
    }
    $pdo = jeab_hook_readonly_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'SQL saknas.',
            'status' => 400,
            'action' => $action,
        ]);
    }
    $live = jeab_hook_readonly_live($pdo, $tbl, $id);
    if ($live === null) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'Raden saknas.',
            'status' => 404,
            'action' => $action,
            'tbl' => $tbl,
            'id' => $id,
        ]);
    }
    $want = (string) ($company['company'] ?? '');
    if ($want !== '' && $live['company'] !== $want) {
        return jeab_hook_readonly_public([
            'ok' => false,
            'error' => 'Raden saknas.',
            'status' => 404,
            'action' => $action,
            'tbl' => $tbl,
            'id' => $id,
        ]);
    }
    $body = [
        'ok' => true,
        'action' => $action,
        'tbl' => $tbl,
        'id' => $id,
        'keys' => $live['keys'],
        'prior' => null,
        'status' => 200,
    ];
    if ($want !== '') {
        $body['co'] = $want;
    }
    if ($beforeTs !== null) {
        $body['before'] = jeab_hook_readonly_stamp($beforeTs);
        $prior = jeab_hook_readonly_prior($tbl, $id, $beforeTs);
        $body['prior'] = $prior;
    }

    return jeab_hook_readonly_public($body);
}

/**
 * @param array<string, mixed> $input
 * @return array{company?:string,error?:string}
 */
function jeab_hook_readonly_company_filter(array $input): array
{
    if (!array_key_exists('co', $input)) {
        return ['company' => ''];
    }
    $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($input['co']) : null;
    if ($company !== 'jarfalla' && $company !== 'malmo') {
        return ['error' => 'Ogiltigt bolag.'];
    }

    return ['company' => $company];
}

function jeab_hook_readonly_pdo(): ?PDO
{
    if (!function_exists('jeab_store')) {
        return null;
    }
    $store = jeab_store();

    return $store instanceof JeabPdoStore ? $store->pdo() : null;
}

/**
 * @return array<string, array{at:string,rows:list<array<string, mixed>>,public:list<array<string, string>>}>
 */
function jeab_hook_readonly_merge_load(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT undo_id, created_at, kind, tbl, row_key, col, old_value, new_value FROM contacts_merge_undo ORDER BY created_at ASC, id ASC');
        $fetched = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return [];
    }
    $groups = [];
    foreach (is_array($fetched) ? $fetched : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $undoId = jeab_hook_readonly_id((string) ($row['undo_id'] ?? ''));
        $at = jeab_hook_readonly_stamp_text((string) ($row['created_at'] ?? ''));
        if ($undoId === '' || $at === '') {
            continue;
        }
        $public = jeab_hook_readonly_merge_row($row, $at);
        if ($public === null) {
            continue;
        }
        if (!isset($groups[$undoId])) {
            $groups[$undoId] = ['at' => $at, 'rows' => [], 'public' => []];
        }
        if ($at < $groups[$undoId]['at']) {
            $groups[$undoId]['at'] = $at;
        }
        $groups[$undoId]['rows'][] = $row;
        $groups[$undoId]['public'][] = $public;
    }
    foreach ($groups as $undoId => $pack) {
        $groups[$undoId]['public'] = jeab_hook_readonly_fill_master($pack['public']);
    }

    return $groups;
}

/**
 * En raderad dubblett får master-id från länk-raden med samma dup-id.
 *
 * @param list<array<string, string>> $rows
 * @return list<array<string, string>>
 */
function jeab_hook_readonly_fill_master(array $rows): array
{
    foreach ($rows as $index => $row) {
        if ($row['master_id'] !== '' || $row['dup_id'] === '') {
            continue;
        }
        foreach ($rows as $other) {
            if ($other['dup_id'] === $row['dup_id'] && $other['master_id'] !== '') {
                $rows[$index]['master_id'] = $other['master_id'];
                break;
            }
        }
    }

    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, string>|null
 */
function jeab_hook_readonly_merge_row(array $row, string $at): ?array
{
    $tbl = jeab_hook_readonly_name((string) ($row['tbl'] ?? ''));
    $col = jeab_hook_readonly_name((string) ($row['col'] ?? ''));
    $kind = jeab_hook_readonly_name((string) ($row['kind'] ?? ''));
    if ($tbl === '' || $kind === '') {
        return null;
    }
    $pair = jeab_hook_readonly_merge_pair($row);

    return [
        'tbl' => $tbl,
        'row_key' => jeab_hook_readonly_id((string) ($row['row_key'] ?? '')),
        'col' => $col,
        'kind' => $kind,
        'master_id' => $pair['master_id'],
        'dup_id' => $pair['dup_id'],
        'at' => $at,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{master_id:string,dup_id:string}
 */
function jeab_hook_readonly_merge_pair(array $row): array
{
    $kind = (string) ($row['kind'] ?? '');
    $col = (string) ($row['col'] ?? '');
    $rowKey = jeab_hook_readonly_id((string) ($row['row_key'] ?? ''));
    $master = '';
    $dup = '';
    if ($kind === 'contact_update' || $kind === 'address_normalize') {
        $master = $rowKey;
    } elseif ($kind === 'contact_delete') {
        $dup = $rowKey;
    } elseif ($kind === 'link' && $col === 'contact_id') {
        $dup = jeab_hook_readonly_id((string) ($row['old_value'] ?? ''));
        $master = jeab_hook_readonly_id((string) ($row['new_value'] ?? ''));
    } elseif ($kind === 'link') {
        $pair = jeab_hook_readonly_contact_swap($row['old_value'] ?? null, $row['new_value'] ?? null);
        $master = $pair['master_id'];
        $dup = $pair['dup_id'];
    }

    return ['master_id' => $master, 'dup_id' => $dup];
}

/**
 * @return array{master_id:string,dup_id:string}
 */
function jeab_hook_readonly_contact_swap(mixed $old, mixed $new): array
{
    $before = jeab_hook_readonly_contact_ids(jeab_hook_readonly_decode_json($old));
    $after = jeab_hook_readonly_contact_ids(jeab_hook_readonly_decode_json($new));
    $gone = array_values(array_diff($before, $after));
    $added = array_values(array_diff($after, $before));
    if (count($gone) === 1 && count($added) === 1) {
        return [
            'master_id' => jeab_hook_readonly_id($added[0]),
            'dup_id' => jeab_hook_readonly_id($gone[0]),
        ];
    }

    return ['master_id' => '', 'dup_id' => ''];
}

function jeab_hook_readonly_decode_json(mixed $raw): mixed
{
    if (is_array($raw)) {
        return $raw;
    }
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return list<string>
 */
function jeab_hook_readonly_contact_ids(mixed $node, int $depth = 0): array
{
    if ($depth > 8 || !is_array($node)) {
        return [];
    }
    $out = [];
    if (array_key_exists('contact_id', $node)) {
        $id = jeab_hook_readonly_id((string) $node['contact_id']);
        if ($id !== '') {
            $out[] = $id;
        }
    }
    foreach ($node as $key => $child) {
        if ($key === 'contact_id' || $key === 'snapshot' || $key === 'snapshots' || $key === 'snapshot_json') {
            continue;
        }
        if (is_array($child)) {
            foreach (jeab_hook_readonly_contact_ids($child, $depth + 1) as $id) {
                $out[] = $id;
            }
        }
    }
    $out = array_values(array_unique($out));
    sort($out, SORT_STRING);

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function jeab_hook_readonly_merge_company(PDO $pdo, array $rows, string $want): bool
{
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tbl = (string) ($row['tbl'] ?? '');
        $rowKey = (string) ($row['row_key'] ?? '');
        if (jeab_hook_readonly_stored_company($pdo, $tbl, $rowKey) === $want) {
            return true;
        }
        $pair = jeab_hook_readonly_merge_pair($row);
        foreach ([$pair['master_id'], $pair['dup_id']] as $id) {
            if ($id !== '' && jeab_hook_readonly_stored_company($pdo, 'contacts', $id) === $want) {
                return true;
            }
        }
    }

    return false;
}

function jeab_hook_readonly_stored_company(PDO $pdo, string $tbl, string $id): string
{
    $id = jeab_hook_readonly_id($id);
    $tbl = jeab_hook_readonly_name($tbl);
    if ($id === '' || $tbl === '') {
        return '';
    }
    $sql = match ($tbl) {
        'contacts' => 'SELECT company FROM contacts WHERE id = ?',
        'purchases' => ctype_digit($id) ? 'SELECT company FROM purchases WHERE reminder_id = ?' : '',
        'reminders' => ctype_digit($id) ? 'SELECT company FROM reminders WHERE id = ?' : '',
        'bookings' => ctype_digit($id) ? 'SELECT company FROM bookings WHERE id = ?' : '',
        default => '',
    };
    if ($sql === '') {
        return '';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tbl === 'contacts' ? $id : (int) $id]);
        $value = $stmt->fetchColumn();
    } catch (Throwable) {
        return '';
    }

    return jeab_hook_readonly_company($value === false ? '' : (string) $value);
}

/**
 * @return array{keys:list<string>,company:string}|null
 */
function jeab_hook_readonly_live(PDO $pdo, string $tbl, string $id): ?array
{
    try {
        if ($tbl === 'purchases') {
            $stmt = $pdo->prepare('SELECT payload, company FROM purchases WHERE reminder_id = ?');
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                if (!function_exists('jeab_purchase_get')) {
                    return null;
                }
                $live = jeab_purchase_get((int) $id);
                if (!is_array($live)) {
                    return null;
                }

                return [
                    'keys' => jeab_hook_readonly_field_names($live),
                    'company' => jeab_hook_readonly_company($live['company'] ?? $live['co'] ?? ''),
                ];
            }
            $doc = jeab_hook_readonly_decode_json($row['payload'] ?? null);
            $company = jeab_hook_readonly_company($row['company'] ?? '');
            if ($company === '' && is_array($doc)) {
                $company = jeab_hook_readonly_company($doc['company'] ?? $doc['co'] ?? '');
            }
            if (!is_array($doc) && function_exists('jeab_purchase_get')) {
                $live = jeab_purchase_get((int) $id);
                if (is_array($live)) {
                    $doc = $live;
                    if ($company === '') {
                        $company = jeab_hook_readonly_company($live['company'] ?? $live['co'] ?? '');
                    }
                }
            }

            return [
                'keys' => jeab_hook_readonly_field_names(is_array($doc) ? $doc : []),
                'company' => $company,
            ];
        }
        if ($tbl === 'reminders' || $tbl === 'bookings') {
            $stmt = $pdo->prepare('SELECT * FROM ' . $tbl . ' WHERE id = ?');
            $stmt->execute([(int) $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }

            return [
                'keys' => jeab_hook_readonly_field_names($row),
                'company' => jeab_hook_readonly_company($row['company'] ?? ''),
            ];
        }
        $stmt = $pdo->prepare('SELECT row_json FROM inkorg_threads WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return null;
        }
        $doc = jeab_hook_readonly_decode_json($raw);

        return [
            'keys' => jeab_hook_readonly_field_names(is_array($doc) ? $doc : []),
            'company' => jeab_hook_readonly_company(is_array($doc) ? ($doc['company'] ?? $doc['co'] ?? '') : ''),
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array{source:string,at:string,keys:list<string>}|null
 */
function jeab_hook_readonly_prior(string $tbl, string $id, int $beforeTs): ?array
{
    $best = null;
    foreach (jeab_hook_readonly_prior_files() as $file) {
        $ts = (int) (@filemtime($file['path']) ?: 0);
        if ($ts <= 0 || $ts >= $beforeTs) {
            continue;
        }
        $decoded = jeab_hook_readonly_read_json($file['path']);
        if (!is_array($decoded)) {
            continue;
        }
        $row = jeab_hook_readonly_doc_row($decoded, $tbl, $id);
        if ($row === null) {
            continue;
        }
        $best = jeab_hook_readonly_prior_pick($best, [
            'source' => $file['source'],
            'at' => jeab_hook_readonly_stamp($ts),
            'ts' => $ts,
            'keys' => jeab_hook_readonly_doc_keys($row),
        ]);
    }
    if (function_exists('jeab_ordrar_restore_snapshots')) {
        foreach (jeab_ordrar_restore_snapshots() as $snap) {
            if (!is_array($snap)) {
                continue;
            }
            $ts = jeab_hook_readonly_parse_time((string) ($snap['at'] ?? ''));
            if ($ts === null || $ts >= $beforeTs) {
                continue;
            }
            $data = is_array($snap['data'] ?? null) ? $snap['data'] : [];
            $row = jeab_hook_readonly_doc_row($data, $tbl, $id);
            if ($row === null) {
                continue;
            }
            $best = jeab_hook_readonly_prior_pick($best, [
                'source' => 'snapshot',
                'at' => jeab_hook_readonly_stamp($ts),
                'ts' => $ts,
                'keys' => jeab_hook_readonly_doc_keys($row),
            ]);
        }
    }
    if ($best === null) {
        return null;
    }

    return [
        'source' => (string) $best['source'],
        'at' => (string) $best['at'],
        'keys' => is_array($best['keys']) ? array_values($best['keys']) : [],
    ];
}

/**
 * @param array{source:string,at:string,ts:int,keys:list<string>}|null $best
 * @param array{source:string,at:string,ts:int,keys:list<string>} $cand
 * @return array{source:string,at:string,ts:int,keys:list<string>}
 */
function jeab_hook_readonly_prior_pick(?array $best, array $cand): array
{
    if ($best === null || $cand['ts'] > $best['ts']) {
        return $cand;
    }

    return $best;
}

/**
 * @return list<array{path:string,source:string}>
 */
function jeab_hook_readonly_prior_files(): array
{
    $roots = [];
    $add = static function (string $dir, string $source) use (&$roots): void {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $real = realpath($dir);
        if (!is_string($real)) {
            return;
        }
        $roots[$real] = $source;
    };
    $bases = [];
    if (function_exists('jeab_data_dir')) {
        $bases[] = rtrim(jeab_data_dir(), '/');
    }
    if (function_exists('jeab_public_data_dir')) {
        $bases[] = rtrim(jeab_public_data_dir(), '/');
    }
    foreach ($bases as $base) {
        $add($base . '/.private/backups', 'backup');
        $add($base . '/.private/trash', 'trash');
    }
    $out = [];
    foreach ($roots as $root => $source) {
        jeab_hook_readonly_walk($root, $root, $source, $out);
    }

    return $out;
}

/**
 * @param list<array{path:string,source:string}> $out
 */
function jeab_hook_readonly_walk(string $dir, string $root, string $source, array &$out): void
{
    $names = @scandir($dir);
    if (!is_array($names)) {
        return;
    }
    $rootReal = realpath($root);
    if (!is_string($rootReal)) {
        return;
    }
    $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
    foreach ($names as $name) {
        if ($name === '.' || $name === '..' || $name === '' || str_contains($name, '@')) {
            continue;
        }
        $path = $dir . '/' . $name;
        if (is_link($path)) {
            continue;
        }
        if (is_dir($path)) {
            jeab_hook_readonly_walk($path, $root, $source, $out);
            continue;
        }
        if (!is_file($path) || !str_ends_with(strtolower($name), '.json')) {
            continue;
        }
        if ((int) (@filesize($path) ?: 0) > 12000000) {
            continue;
        }
        $real = realpath($path);
        if (!is_string($real)) {
            continue;
        }
        $norm = str_replace('\\', '/', $real);
        if ($norm !== $rootNorm && !str_starts_with($norm, $rootNorm . '/')) {
            continue;
        }
        $out[] = ['path' => $path, 'source' => $source];
    }
}

function jeab_hook_readonly_read_json(string $path): mixed
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $doc
 * @return array<string, mixed>|null
 */
function jeab_hook_readonly_doc_row(array $doc, string $tbl, string $id): ?array
{
    $docs = [$doc];
    if (isset($doc['data']) && is_array($doc['data'])) {
        $docs[] = $doc['data'];
    }
    foreach ($docs as $one) {
        $hit = jeab_hook_readonly_doc_row_one($one, $tbl, $id);
        if ($hit !== null) {
            return $hit;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $doc
 * @return array<string, mixed>|null
 */
function jeab_hook_readonly_doc_row_one(array $doc, string $tbl, string $id): ?array
{
    $table = (string) ($doc['table'] ?? '');
    if ($table === $tbl || ($tbl === 'inbox_threads' && $table === 'inkorg_threads') || ($tbl === 'purchases' && $table === 'orders')) {
        $rows = $doc['rows'] ?? null;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $hit = jeab_hook_readonly_row_match($row, $tbl, $id);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
    }
    $keys = match ($tbl) {
        'purchases' => ['purchases', 'orders', 'orders_rows', 'rows'],
        'reminders' => ['reminders', 'reminder'],
        'bookings' => ['bookings', 'booking'],
        'inbox_threads' => ['threads', 'inbox_threads'],
        default => [],
    };
    foreach ($keys as $key) {
        if (!isset($doc[$key]) || !is_array($doc[$key])) {
            continue;
        }
        $bag = $doc[$key];
        if (jeab_hook_readonly_is_row($bag)) {
            $hit = jeab_hook_readonly_row_match($bag, $tbl, $id);
            if ($hit !== null) {
                return $hit;
            }
            continue;
        }
        if (isset($bag[$id]) && is_array($bag[$id])) {
            $hit = jeab_hook_readonly_row_match($bag[$id], $tbl, $id);
            if ($hit !== null) {
                return $hit;
            }
            if (jeab_hook_readonly_is_row($bag[$id])) {
                return $bag[$id];
            }
        }
        foreach ($bag as $index => $row) {
            if ((string) $index === $id && is_array($row) && jeab_hook_readonly_is_row($row)) {
                return $row;
            }
            $hit = jeab_hook_readonly_row_match($row, $tbl, $id);
            if ($hit !== null) {
                return $hit;
            }
        }
    }

    return jeab_hook_readonly_row_match($doc, $tbl, $id);
}

function jeab_hook_readonly_row_match(mixed $row, string $tbl, string $id): ?array
{
    if (!is_array($row) || !jeab_hook_readonly_is_row($row)) {
        return null;
    }
    $candidates = [
        (string) ($row['reminder_id'] ?? ''),
        (string) ($row['id'] ?? ''),
        (string) ($row['thread_id'] ?? ''),
    ];
    if (!in_array($id, $candidates, true)) {
        return null;
    }
    if ($tbl === 'purchases' && (string) ($row['reminder_id'] ?? $row['id'] ?? '') !== $id) {
        return null;
    }

    return $row;
}

function jeab_hook_readonly_is_row(array $row): bool
{
    if ($row === []) {
        return false;
    }
    foreach (array_keys($row) as $key) {
        if (!is_int($key)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_hook_readonly_doc_keys(array $row): array
{
    $payload = $row['payload'] ?? $row['row_json'] ?? null;
    $decoded = jeab_hook_readonly_decode_json($payload);
    if (is_array($decoded)) {
        return jeab_hook_readonly_field_names($decoded);
    }

    return jeab_hook_readonly_field_names($row);
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_hook_readonly_field_names(array $row): array
{
    $names = [];
    foreach (array_keys($row) as $key) {
        if (!is_string($key) || !jeab_hook_readonly_field_name($key)) {
            continue;
        }
        $names[$key] = $key;
    }
    $out = array_values($names);
    sort($out, SORT_STRING);

    return $out;
}

function jeab_hook_readonly_field_name(string $name): bool
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) === 1;
}

function jeab_hook_readonly_row_id(string $tbl, mixed $raw): string
{
    if ($tbl === 'inbox_threads') {
        return jeab_hook_readonly_id(is_scalar($raw) ? (string) $raw : '');
    }
    if (is_int($raw) && $raw > 0) {
        return (string) $raw;
    }
    $text = is_scalar($raw) ? trim((string) $raw) : '';
    if (preg_match('/^[1-9]\d{0,9}$/', $text) !== 1) {
        return '';
    }

    return $text;
}

function jeab_hook_readonly_id(string $value): string
{
    $value = trim($value);
    if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $value) !== 1) {
        return '';
    }
    if (preg_match('/^\d{8,}$/', $value) === 1) {
        return '';
    }
    if (str_contains($value, '@')) {
        return '';
    }

    return $value;
}

function jeab_hook_readonly_name(string $value): string
{
    $value = trim($value);

    return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) === 1 ? $value : '';
}

function jeab_hook_readonly_company(mixed $raw): string
{
    if (!function_exists('jeab_normalize_company')) {
        return '';
    }
    $company = jeab_normalize_company($raw);

    return $company === 'jarfalla' || $company === 'malmo' ? $company : '';
}

function jeab_hook_readonly_zone(): DateTimeZone
{
    return new DateTimeZone('Europe/Stockholm');
}

function jeab_hook_readonly_parse_time(string $raw): ?int
{
    $raw = trim($raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?$/', $raw) !== 1) {
        return null;
    }
    $zoned = str_ends_with($raw, 'Z') || preg_match('/[+-]\d{2}:?\d{2}$/', $raw) === 1;
    try {
        $when = $zoned
            ? new DateTimeImmutable($raw)
            : new DateTimeImmutable($raw, jeab_hook_readonly_zone());
    } catch (Throwable) {
        return null;
    }

    return $when->getTimestamp();
}

function jeab_hook_readonly_stamp(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(jeab_hook_readonly_zone())->format('Y-m-d H:i:s');
}

function jeab_hook_readonly_stamp_text(string $raw): string
{
    $ts = jeab_hook_readonly_parse_time($raw);
    if ($ts === null) {
        return '';
    }

    return jeab_hook_readonly_stamp($ts);
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function jeab_hook_readonly_public(array $body): array
{
    $body['sent'] = 0;
    $body['read_only'] = 1;
    $clean = jeab_hook_readonly_scrub($body);

    return is_array($clean) ? $clean : ['ok' => false, 'error' => 'Svaret gick inte att läsa.', 'status' => 500, 'sent' => 0, 'read_only' => 1];
}

function jeab_hook_readonly_scrub(mixed $value): mixed
{
    if (is_string($value)) {
        if (str_contains($value, '@') || preg_match('/\d{8,}/', $value) === 1 || strlen($value) > 80) {
            return '';
        }

        return $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $child) {
        $name = is_string($key) ? $key : (string) $key;
        if (in_array($name, ['old_value', 'new_value', 'payload', 'row_json', 'email', 'phone', 'name', 'note', 'notes', 'body'], true)) {
            continue;
        }
        $out[$key] = jeab_hook_readonly_scrub($child);
    }

    return $out;
}
