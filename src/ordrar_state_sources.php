<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * ordrar.state_sources, ordrar.get och ordrar.rebuild_state.
 *
 * state_sources letar ett order-id i data/*.json, data/.private (även
 * säkerhetskopior och trash), cutover-dumpar, purchases_history,
 * order_audit, purchase_store, påminnelser och inkorg. Stora filer läses
 * i bitar. Svaret har namn, relativ sökväg, tid och vilka lägesnycklar som
 * finns. Inga personuppgifter.
 *
 * Kalenderbokning och Fortnox-mappning rapporteras som fakta. De används
 * av rebuild_state när ingen lagrad källa har läge (step plus steps,
 * install_date, fortnox_order, rows eller kalkyl). Bara co faller igenom
 * till bokningen: bookings.order_id, installationsdatum därifrån, och
 * step minst dit installationen är bokad. Ingen skrivning till Fortnox,
 * inget mail, inget sms och ingen kalenderinbjudan.
 */

function jeab_ordrar_state_sources_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => 'Fel token.',
            'status' => 401,
            'sent' => 0,
        ]);
    }
    $action = trim((string) ($input['action'] ?? 'ordrar.state_sources'));
    if ($action === 'ordrar.get') {
        return jeab_ordrar_get($store, $input);
    }
    if ($action === 'ordrar.rebuild_state') {
        return jeab_ordrar_rebuild_state($store, $input);
    }

    return jeab_ordrar_state_sources($store, $input);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_state_sources(JeabStore $store, array $input): array
{
    $parsed = function_exists('jeab_ordrar_restore_parse_ids')
        ? jeab_ordrar_restore_parse_ids($input['ids'] ?? null)
        : ['error' => 'ids ska vara en lista.'];
    if (isset($parsed['error'])) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => (string) $parsed['error'],
            'status' => 422,
            'action' => 'ordrar.state_sources',
        ]);
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    if (!$pdo instanceof PDO) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => 'SQL saknas.',
            'status' => 400,
            'action' => 'ordrar.state_sources',
        ]);
    }
    $orders = [];
    foreach ($parsed['ids'] ?? [] as $id) {
        $id = (int) $id;
        $rows = jeab_ordrar_state_sources_collect($pdo, $id);
        $sources = [];
        foreach ($rows as $row) {
            $sources[] = jeab_ordrar_state_sources_public_source($row);
        }
        $best = jeab_ordrar_restore_state_pick(jeab_ordrar_state_sources_payloads($rows));
        $orders[] = [
            'id' => $id,
            'sources' => $sources,
            'best' => $best === null ? null : jeab_ordrar_state_sources_public_source($best),
        ];
    }

    return jeab_ordrar_state_sources_label(jeab_ordrar_restore_state_public([
        'ok' => true,
        'action' => 'ordrar.state_sources',
        'orders' => $orders,
        'status' => 200,
    ]));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_get(JeabStore $store, array $input): array
{
    $id = jeab_ordrar_state_sources_one_id($input['id'] ?? null);
    if ($id === null) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => 'id krävs.',
            'status' => 422,
            'action' => 'ordrar.get',
        ]);
    }
    if (!function_exists('jeab_purchase_get')) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => 'SQL saknas.',
            'status' => 400,
            'action' => 'ordrar.get',
        ]);
    }
    $live = jeab_purchase_get($id);
    if (!is_array($live)) {
        return jeab_ordrar_restore_state_public([
            'ok' => false,
            'error' => 'Ordern saknas.',
            'status' => 404,
            'action' => 'ordrar.get',
            'id' => $id,
        ]);
    }
    $state = jeab_ordrar_restore_state_extract($live);
    $install = (string) ($state['install_date'] ?? '');
    if ($install === '' && function_exists('jeab_ordrar_install_start')) {
        $install = jeab_ordrar_restore_state_date(jeab_ordrar_install_start($live, $store));
    }
    $price = (int) ($state['pris'] ?? $state['kundpris'] ?? 0);
    $bookingId = (int) ($state['booking_id'] ?? 0);
    if ($bookingId <= 0 && $store instanceof JeabPdoStore) {
        $linked = jeab_ordrar_state_sources_linked_booking($store->pdo(), $id, $live);
        if (is_array($linked)) {
            $bookingId = (int) ($linked['id'] ?? 0);
        }
    }

    $body = [
        'ok' => true,
        'action' => 'ordrar.get',
        'id' => $id,
        'step' => $state['step'] ?? null,
        'steps' => is_array($state['steps'] ?? null) ? $state['steps'] : [],
        'install_date' => $install,
        'fortnox_order' => (string) ($state['fortnox_order'] ?? ''),
        'price' => $price,
        'company' => (string) ($state['company'] ?? ''),
        'booking_id' => $bookingId,
        'status' => 200,
    ];
    if (jeab_ordrar_get_wants_fields($input)) {
        require_once __DIR__ . '/hook_readonly_keys.php';
        $body['contact_id'] = jeab_hook_readonly_id((string) ($live['contact_id'] ?? ''));
        $body['keys'] = jeab_hook_readonly_field_names($live);
    }

    return jeab_ordrar_restore_state_public($body);
}

function jeab_ordrar_get_wants_fields(array $input): bool
{
    $flag = $input['fields'] ?? null;

    return $flag === 1 || $flag === '1' || $flag === true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_rebuild_state(JeabStore $store, array $input): array
{
    $dry = function_exists('jeab_ordrar_restore_dry') ? jeab_ordrar_restore_dry($input) : true;
    $id = jeab_ordrar_state_sources_one_id($input['id'] ?? null);
    $base = [
        'ok' => true,
        'action' => 'ordrar.rebuild_state',
        'id' => $id ?? 0,
        'dry' => $dry ? 1 : 0,
        'read_only' => $dry,
        'diff' => [],
        'undo_id' => null,
        'status' => 200,
    ];
    if ($id === null) {
        $base['ok'] = false;
        $base['error'] = 'id krävs.';
        $base['status'] = 422;

        return jeab_ordrar_restore_state_public($base);
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    if (!$pdo instanceof PDO || !function_exists('jeab_purchase_get') || !function_exists('jeab_sql_purchases_patch')) {
        $base['ok'] = false;
        $base['error'] = 'SQL saknas.';
        $base['status'] = 400;

        return jeab_ordrar_restore_state_public($base);
    }
    $live = jeab_purchase_get($id);
    if (!is_array($live)) {
        $base['ok'] = false;
        $base['error'] = 'Ordern saknas.';
        $base['status'] = 404;

        return jeab_ordrar_restore_state_public($base);
    }
    if (!isset(jeab_ordrar_restore_state_logged($pdo)[$id])) {
        $base['ok'] = false;
        $base['error'] = 'Ordern är inte återställd.';
        $base['status'] = 422;
        $base['diff'] = [];

        return jeab_ordrar_restore_state_public($base);
    }
    $pick = jeab_ordrar_restore_state_pick(jeab_ordrar_restore_state_candidates($pdo, $id));
    if ($pick !== null) {
        $base['reason'] = 'has_state';
        $base['source'] = $pick['source'];
        $base['source_at'] = $pick['at'];
        $base['source_file'] = $pick['file'];

        return jeab_ordrar_restore_state_public($base);
    }
    $derived = jeab_ordrar_state_sources_derive($pdo, $id, $live);
    $state = $derived['state'];
    $base['diff'] = jeab_ordrar_restore_state_diff($live, $state);
    if ($state === [] || $base['diff'] === []) {
        $base['reason'] = 'no_facts';
        $base['diff'] = [];

        return jeab_ordrar_restore_state_public($base);
    }
    if ($dry) {
        return jeab_ordrar_restore_state_public($base);
    }
    if (!function_exists('jeab_pdo_transaction') || !function_exists('jeab_restore_seal_read')) {
        $base['ok'] = false;
        $base['error'] = 'Läget gick inte att spara.';
        $base['status'] = 500;

        return jeab_ordrar_restore_state_public($base);
    }
    try {
        jeab_ordrar_restore_state_ensure_log($pdo);
    } catch (Throwable) {
        $base['ok'] = false;
        $base['error'] = 'Läget gick inte att spara.';
        $base['status'] = 500;

        return jeab_ordrar_restore_state_public($base);
    }
    $undoId = bin2hex(random_bytes(16));
    try {
        jeab_pdo_transaction($pdo, static function (PDO $pdo) use ($id, $state, $live, $undoId): void {
            $seal = jeab_restore_seal_read($pdo);
            $saved = jeab_sql_purchases_patch($pdo, $id, static function (array $current) use ($state): array {
                $next = $current;
                foreach ($state as $key => $value) {
                    if ($key === 'steps' && is_array($value)) {
                        $steps = is_array($current['steps'] ?? null) ? $current['steps'] : [];
                        foreach ($value as $stepKey => $stepValue) {
                            $steps[$stepKey] = $stepValue;
                        }
                        $next['steps'] = $steps;
                        continue;
                    }
                    $next[$key] = $value;
                }
                $next['updated_at'] = (string) ($current['updated_at'] ?? '');

                return $next;
            });
            if (!is_array($saved)) {
                throw new RuntimeException('missing');
            }
            $before = [];
            foreach (array_keys($state) as $key) {
                $before[$key] = array_key_exists($key, $live) ? $live[$key] : null;
            }
            $json = json_encode($before, JSON_UNESCAPED_UNICODE);
            if (!is_string($json)) {
                throw new RuntimeException('json');
            }
            $pdo->prepare('INSERT INTO order_state_restore_log (undo_id, reminder_id, before_json, undone) VALUES (?, ?, ?, 0)')
                ->execute([$undoId, $id, $json]);
            if (jeab_restore_seal_read($pdo) !== $seal) {
                throw new RuntimeException('frozen');
            }
        });
    } catch (Throwable) {
        $base['ok'] = false;
        $base['error'] = 'Läget gick inte att spara.';
        $base['status'] = 500;

        return jeab_ordrar_restore_state_public($base);
    }
    if (function_exists('jeab_purchase_cache_forget')) {
        jeab_purchase_cache_forget();
    }
    $base['dry'] = 0;
    $base['read_only'] = false;
    $base['undo_id'] = $undoId;

    return jeab_ordrar_restore_state_public($base);
}

function jeab_ordrar_state_sources_one_id(mixed $raw): ?int
{
    if (is_int($raw) && $raw > 0) {
        return $raw;
    }
    if (is_string($raw) && preg_match('/\A[1-9]\d*\z/', $raw) === 1) {
        return (int) $raw;
    }

    return null;
}

/**
 * Nycklar som en källa faktiskt har. Värden lämnas inte ut.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_ordrar_state_sources_keys(array $row): array
{
    $row = jeab_ordrar_state_sources_unwrap($row);
    $have = [];
    if (array_key_exists('step', $row) && (int) $row['step'] > 0) {
        $have[] = 'step';
    }
    if (is_array($row['steps'] ?? null) && $row['steps'] !== []) {
        $have[] = 'steps';
    }
    $booked = $row['bokad_tid'] ?? '';
    if (is_array($row['steps'] ?? null) && trim((string) $booked) === '') {
        $booked = $row['steps']['bokad_tid'] ?? '';
    }
    if (trim((string) $booked) !== '') {
        $have[] = 'bokad_tid';
    }
    if (trim((string) ($row['install_date'] ?? $row['install_ymd'] ?? '')) !== '') {
        $have[] = 'install_date';
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        $have[] = 'fortnox_order';
    }
    if ((int) ($row['pris'] ?? 0) > 0) {
        $have[] = 'pris';
    }
    if ((int) ($row['kundpris'] ?? 0) > 0) {
        $have[] = 'kundpris';
    }
    foreach (['rows', 'inkop_lines', 'fortnox_order_rows', 'orderrader'] as $bag) {
        if (is_array($row[$bag] ?? null) && $row[$bag] !== []) {
            $have[] = 'rows';
            break;
        }
    }
    if (is_array($row['kalkyl'] ?? null) && $row['kalkyl'] !== []) {
        $have[] = 'kalkyl';
    }
    if (trim((string) ($row['co'] ?? $row['company'] ?? '')) !== '') {
        $have[] = 'co';
    }

    return $have;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_state_sources_unwrap(array $row): array
{
    $payload = $row['payload'] ?? $row['row_json'] ?? $row['data'] ?? null;
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($payload)) {
        return $row;
    }
    $merged = array_merge($payload, $row);
    unset($merged['payload'], $merged['row_json'], $merged['data']);

    return $merged;
}

/**
 * @param array{source:string,at:string,file:string,path?:string,row:array<string,mixed>} $row
 * @return array{source:string,path:string,at:string,keys:list<string>}
 */
function jeab_ordrar_state_sources_public_source(array $row): array
{
    $path = trim((string) ($row['path'] ?? ''));
    if ($path === '') {
        $path = trim((string) ($row['file'] ?? ''));
    }
    if (str_contains($path, '@')) {
        $path = '';
    }

    return [
        'source' => (string) ($row['source'] ?? ''),
        'path' => $path,
        'at' => (string) ($row['at'] ?? ''),
        'window' => jeab_ordrar_state_sources_window($row) ? 1 : 0,
        'keys' => jeab_ordrar_state_sources_keys($row['row']),
    ];
}

/**
 * Källans namn sätts efter PII-filtret, som annars tar bort nyckeln name.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function jeab_ordrar_state_sources_label(array $body): array
{
    if (!is_array($body['orders'] ?? null)) {
        return $body;
    }
    foreach ($body['orders'] as $index => $order) {
        if (!is_array($order)) {
            continue;
        }
        if (is_array($order['sources'] ?? null)) {
            foreach ($order['sources'] as $slot => $source) {
                if (!is_array($source)) {
                    continue;
                }
                $source['name'] = (string) ($source['source'] ?? '');
                $order['sources'][$slot] = $source;
            }
        }
        if (is_array($order['best'] ?? null)) {
            $order['best']['name'] = (string) ($order['best']['source'] ?? '');
        }
        $body['orders'][$index] = $order;
    }

    return $body;
}

/**
 * @param list<array{source:string,at:string,file:string,path?:string,row:array<string,mixed>}> $rows
 * @return list<array{source:string,at:string,file:string,path?:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_payloads(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (($row['source'] ?? '') === 'calendar' || ($row['source'] ?? '') === 'fortnox') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_collect(PDO $pdo, int $id): array
{
    $rows = jeab_ordrar_restore_state_candidates($pdo, $id);
    if (function_exists('jeab_ordrar_restore_snapshots') && function_exists('jeab_ordrar_restore_detail')) {
        foreach (jeab_ordrar_restore_snapshots() as $snap) {
            if (is_array(jeab_ordrar_restore_detail($snap['data'], $id))) {
                continue;
            }
            $rows[] = [
                'source' => 'snapshot',
                'at' => jeab_ordrar_restore_state_stamp('', (string) ($snap['at'] ?? '')),
                'file' => '',
                'path' => 'contacts_sole_undo',
                'row' => [],
            ];
        }
    }
    foreach (jeab_ordrar_state_sources_facts($pdo, $id) as $fact) {
        $rows[] = $fact;
    }

    return $rows;
}

/**
 * @return list<array{path:string,file:string,rel:string,at:string,source:string}>
 */
function jeab_ordrar_state_sources_files(): array
{
    $out = [];
    $seen = [];
    foreach (jeab_ordrar_state_sources_roots() as $root) {
        jeab_ordrar_state_sources_walk($root, $root, $out, $seen);
    }

    return $out;
}

/** @return list<string> */
function jeab_ordrar_state_sources_roots(): array
{
    $roots = [];
    $add = static function (string $dir) use (&$roots): void {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $real = realpath($dir);
        $key = is_string($real) ? $real : $dir;
        $roots[$key] = $dir;
    };
    if (function_exists('jeab_public_data_dir')) {
        $add(jeab_public_data_dir());
    }
    if (function_exists('jeab_data_dir')) {
        $add(jeab_data_dir());
    }
    if (function_exists('jeab_cutover_privat_dir')) {
        $privat = rtrim(str_replace('\\', '/', jeab_cutover_privat_dir()), '/');
        $add($privat . '/data_cutover');
        $add($privat . '/cutover');
    }

    return array_values($roots);
}

/**
 * @param list<array{path:string,file:string,rel:string,at:string,source:string}> $out
 * @param array<string, true> $seen
 */
function jeab_ordrar_state_sources_walk(string $dir, string $root, array &$out, array &$seen): void
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
            jeab_ordrar_state_sources_walk($path, $root, $out, $seen);
            continue;
        }
        if (!is_file($path) || !jeab_ordrar_state_sources_keep_file($name)) {
            continue;
        }
        $real = realpath($path);
        if (!is_string($real) || isset($seen[$real])) {
            continue;
        }
        $norm = str_replace('\\', '/', $real);
        if ($norm !== $rootNorm && !str_starts_with($norm, $rootNorm . '/')) {
            continue;
        }
        $seen[$real] = true;
        $rel = ltrim(substr($norm, strlen($rootNorm)), '/');
        $out[] = [
            'path' => $path,
            'file' => $name,
            'rel' => $rel,
            'at' => date('Y-m-d H:i:s', (int) filemtime($path)),
            'source' => jeab_ordrar_state_sources_name($rel, $name),
        ];
    }
}

function jeab_ordrar_state_sources_keep_file(string $name): bool
{
    $file = strtolower($name);
    if (str_ends_with($file, '.json') || str_ends_with($file, '.bak')) {
        return true;
    }

    return str_ends_with($file, '.sql') && (
        str_contains($file, 'dump')
        || str_contains($file, 'backup')
        || str_contains($file, 'purchases')
        || str_contains($file, 'cutover')
    );
}

/**
 * Källor från 31 okt 21:00 till 1 nov 17:34, när ordersidan fortfarande
 * visade Leverans, Fakturering och väntar på följdfrågor.
 *
 * @param array{source?:string,at?:string,file_at?:string,file?:string,path?:string} $cand
 */
function jeab_ordrar_state_sources_window(array $cand): bool
{
    $path = strtolower(str_replace('\\', '/', (string) ($cand['path'] ?? '') . '/' . (string) ($cand['file'] ?? '')));
    $datedTrash = str_contains($path, 'trash/2026-10-31') || str_contains($path, 'trash/2026-11-01');
    $kind = $datedTrash
        || str_contains($path, 'cutover')
        || str_contains($path, '.bak')
        || str_contains($path, '.sql')
        || preg_match('#(^|/)purchases[^/]*\.(json|bak)(/|$)#', $path) === 1;
    $source = (string) ($cand['source'] ?? '');
    $sql = in_array($source, ['purchases_history', 'audit', 'purchase_store', 'cutover', 'sql', 'bak'], true);
    if (!$kind && !$sql && !$datedTrash) {
        return false;
    }
    if ($datedTrash) {
        return true;
    }
    foreach ([(string) ($cand['at'] ?? ''), (string) ($cand['file_at'] ?? '')] as $stamp) {
        if ($stamp >= '2026-10-31 21:00:00' && $stamp <= '2026-11-01 17:34:59') {
            return true;
        }
    }

    return false;
}

function jeab_ordrar_state_sources_name(string $rel, string $base): string
{
    $file = strtolower($base);
    $path = strtolower(str_replace('\\', '/', $rel));
    if (str_contains($file, 'history') || str_contains($path, 'purchases_history')) {
        return 'purchases_history';
    }
    if (str_contains($file, 'audit') || str_contains($path, 'order_audit')) {
        return 'audit';
    }
    if (str_contains($path, 'cutover')) {
        return 'cutover';
    }
    if (str_contains($path, 'trash')) {
        return 'trash';
    }
    if (str_ends_with($file, '.sql')) {
        return 'sql';
    }
    if (str_contains($file, '.bak') || str_contains($path, '.bak')) {
        return 'bak';
    }
    if (str_contains($path, 'inkorg')) {
        return 'inkorg';
    }
    if (str_contains($path, 'reminder')) {
        return 'reminder';
    }
    if (str_contains($path, 'backup')) {
        return 'backup';
    }

    return 'file';
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_state_sources_file_rows(string $path, int $id): array
{
    if ($id <= 0 || !is_file($path)) {
        return [];
    }
    $scan = fopen($path, 'rb');
    $pick = fopen($path, 'rb');
    if ($scan === false || $pick === false) {
        if (is_resource($scan)) {
            fclose($scan);
        }
        if (is_resource($pick)) {
            fclose($pick);
        }

        return [];
    }
    $needle = '"' . $id . '"';
    $needles = [
        $needle => 'key',
        '"reminder_id":' . $id => 'reminder',
        '"reminder_id": ' . $id => 'reminder',
        '"id":' . $id => 'id',
        '"id": ' . $id => 'id',
    ];
    $rows = [];
    $seen = [];
    $carry = '';
    $carryPos = 0;
    $overlap = 48 + strlen((string) $id);
    try {
        while (true) {
            $chunk = fread($scan, 262144);
            if ($chunk === false) {
                break;
            }
            $carry .= $chunk;
            $done = $chunk === '' || feof($scan);
            $limit = $done ? strlen($carry) : max(0, strlen($carry) - $overlap);
            foreach ($needles as $text => $kind) {
                $from = 0;
                while ($from < $limit) {
                    $at = strpos($carry, $text, $from);
                    if ($at === false || $at >= $limit) {
                        break;
                    }
                    $from = $at + 1;
                    if ($kind === 'key' && !jeab_ordrar_state_sources_quoted_id($carry, $at, $id)) {
                        continue;
                    }
                    $row = jeab_ordrar_state_sources_capture($pick, $carryPos + $at, $id, $kind);
                    if ($row === null) {
                        continue;
                    }
                    $sig = md5((string) json_encode(jeab_ordrar_restore_state_extract($row)));
                    if (isset($seen[$sig])) {
                        continue;
                    }
                    $seen[$sig] = true;
                    $rows[] = $row;
                    if (count($rows) >= 8) {
                        break 3;
                    }
                }
            }
            if ($done) {
                break;
            }
            if (strlen($carry) > $overlap) {
                $drop = strlen($carry) - $overlap;
                $carry = substr($carry, $drop);
                $carryPos += $drop;
            }
        }
    } finally {
        fclose($scan);
        fclose($pick);
    }

    return $rows;
}

function jeab_ordrar_state_sources_quoted_id(string $buf, int $at, int $id): bool
{
    $token = '"' . $id . '"';
    if (substr($buf, $at, strlen($token)) !== $token) {
        return false;
    }
    $before = $at > 0 ? $buf[$at - 1] : '';

    return $before !== '"' && !ctype_digit($before);
}

/**
 * @param resource $pick
 * @return array<string, mixed>|null
 */
function jeab_ordrar_state_sources_capture($pick, int $abs, int $id, string $kind): ?array
{
    if ($kind === 'key') {
        $start = jeab_ordrar_state_sources_value_offset($pick, $abs + strlen('"' . $id . '"'));
        $row = $start === null ? null : jeab_ordrar_state_sources_read_value($pick, $start);
    } else {
        $row = jeab_ordrar_state_sources_enclosing($pick, $abs);
    }
    if (!is_array($row)) {
        return null;
    }
    $row = jeab_ordrar_state_sources_unwrap($row);
    $reminder = (int) ($row['reminder_id'] ?? 0);
    $rowId = (int) ($row['id'] ?? 0);
    if ($kind === 'key') {
        if ($reminder !== 0 && $reminder !== $id) {
            return null;
        }
    } elseif ($reminder !== $id && $rowId !== $id) {
        return null;
    }
    if ($kind !== 'key' && jeab_ordrar_restore_state_extract($row) === []) {
        return null;
    }

    return $row;
}

/**
 * @param resource $fh
 */
function jeab_ordrar_state_sources_value_offset($fh, int $from): ?int
{
    fseek($fh, $from);
    $buf = fread($fh, 64);
    if (!is_string($buf)) {
        return null;
    }
    $len = strlen($buf);
    $i = 0;
    while ($i < $len && ctype_space($buf[$i])) {
        $i++;
    }
    if ($i >= $len || $buf[$i] !== ':') {
        return null;
    }
    $i++;
    while ($i < $len && ctype_space($buf[$i])) {
        $i++;
    }
    if ($i >= $len || ($buf[$i] !== '{' && $buf[$i] !== '[')) {
        return null;
    }

    return $from + $i;
}

/**
 * @param resource $fh
 * @return array<string, mixed>|null
 */
function jeab_ordrar_state_sources_read_value($fh, int $start): ?array
{
    fseek($fh, $start);
    $depth = 0;
    $in = false;
    $esc = false;
    $buf = '';
    $started = false;
    $cap = 16777216;
    while (!feof($fh) && strlen($buf) < $cap) {
        $chunk = fread($fh, 8192);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chunk[$i];
            $buf .= $ch;
            if (strlen($buf) > $cap) {
                return null;
            }
            if ($in) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($ch === '\\') {
                    $esc = true;
                    continue;
                }
                if ($ch === '"') {
                    $in = false;
                }
                continue;
            }
            if ($ch === '"') {
                $in = true;
                continue;
            }
            if ($ch === '{' || $ch === '[') {
                $depth++;
                $started = true;
                continue;
            }
            if ($ch === '}' || $ch === ']') {
                if ($depth > 0) {
                    $depth--;
                }
                if ($started && $depth === 0) {
                    $decoded = json_decode($buf, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }
    }

    return null;
}

/**
 * @param resource $fh
 * @return array<string, mixed>|null
 */
function jeab_ordrar_state_sources_enclosing($fh, int $needle): ?array
{
    $window = 262144;
    for ($try = 0; $try < 4; $try++) {
        $start = max(0, $needle - $window);
        fseek($fh, $start);
        $buf = fread($fh, $needle - $start);
        if (!is_string($buf) || $buf === '') {
            return null;
        }
        $open = jeab_ordrar_state_sources_open_brace($buf);
        if ($open !== null) {
            return jeab_ordrar_state_sources_read_value($fh, $start + $open);
        }
        if ($start === 0) {
            return null;
        }
        $window *= 2;
    }

    return null;
}

function jeab_ordrar_state_sources_open_brace(string $buf): ?int
{
    $stack = [];
    $in = false;
    $esc = false;
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $ch = $buf[$i];
        if ($in) {
            if ($esc) {
                $esc = false;
                continue;
            }
            if ($ch === '\\') {
                $esc = true;
                continue;
            }
            if ($ch === '"') {
                $in = false;
            }
            continue;
        }
        if ($ch === '"') {
            $in = true;
            continue;
        }
        if ($ch === '{') {
            $stack[] = $i;
            continue;
        }
        if ($ch === '}' && $stack !== []) {
            array_pop($stack);
        }
    }
    if ($stack === []) {
        return null;
    }

    return $stack[count($stack) - 1];
}

/**
 * Påminnelse och inkorg, bara lägesfält.
 *
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_stored(PDO $pdo, int $id): array
{
    $out = [];
    foreach (jeab_ordrar_state_sources_reminder_rows($pdo, $id) as $hit) {
        $out[] = $hit;
    }
    foreach (jeab_ordrar_state_sources_inkorg_rows($pdo, $id) as $hit) {
        $out[] = $hit;
    }

    return $out;
}

/**
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_reminder_rows(PDO $pdo, int $id): array
{
    if (!jeab_ordrar_restore_state_table($pdo, 'reminders')) {
        return [];
    }
    $cols = jeab_ordrar_restore_state_columns($pdo, 'reminders');
    if (!isset($cols['id'])) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM reminders WHERE id = ?');
        $stmt->execute([$id]);
        $hit = $stmt->fetch();
    } catch (Throwable) {
        return [];
    }
    if (!is_array($hit)) {
        return [];
    }
    $row = jeab_ordrar_state_sources_keep($hit);
    $at = jeab_ordrar_restore_state_stamp($hit['updated_at'] ?? '', (string) ($hit['created_at'] ?? ''));
    if (jeab_ordrar_restore_state_extract($row) === [] && jeab_ordrar_state_sources_keys($row) === []) {
        return [[
            'source' => 'reminder',
            'at' => $at,
            'file' => '',
            'path' => 'reminders',
            'row' => [],
        ]];
    }

    return [[
        'source' => 'reminder',
        'at' => $at,
        'file' => '',
        'path' => 'reminders',
        'row' => $row,
    ]];
}

/**
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_inkorg_rows(PDO $pdo, int $id): array
{
    if (!jeab_ordrar_restore_state_table($pdo, 'inkorg_messages') || $id <= 0) {
        return [];
    }
    $cols = jeab_ordrar_restore_state_columns($pdo, 'inkorg_messages');
    if (!isset($cols['row_json'])) {
        return [];
    }
    $when = isset($cols['received_at']) ? 'received_at' : '';
    $sql = 'SELECT row_json' . ($when !== '' ? ', ' . $when . ' AS at_col' : '') . ' FROM inkorg_messages WHERE row_json LIKE ? OR row_json LIKE ? LIMIT 20';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['%"reminder_id":' . $id . '%', '%"reminder_id": ' . $id . '%']);
        $fetched = $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($fetched) ? $fetched : [] as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $decoded = json_decode((string) ($hit['row_json'] ?? ''), true);
        if (!is_array($decoded)) {
            continue;
        }
        $row = jeab_ordrar_state_sources_keep(jeab_ordrar_state_sources_unwrap($decoded));
        if ((int) ($row['reminder_id'] ?? 0) !== $id && (int) ($row['id'] ?? 0) !== $id) {
            continue;
        }
        if (jeab_ordrar_restore_state_extract($row) === []) {
            continue;
        }
        $out[] = [
            'source' => 'inkorg',
            'at' => jeab_ordrar_restore_state_stamp($row['updated_at'] ?? '', (string) ($hit['at_col'] ?? '')),
            'file' => '',
            'path' => 'inkorg_messages',
            'row' => $row,
        ];
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ordrar_state_sources_keep(array $row): array
{
    $allow = [
        'step', 'steps', 'bokad_tid', 'install_date', 'install_ymd', 'fortnox_order',
        'pris', 'kundpris', 'rows', 'inkop_lines', 'fortnox_order_rows', 'orderrader',
        'kalkyl', 'co', 'company', 'kind', 'product', 'booking_id', 'status',
        'updated_at', 'created_at', 'reminder_id', 'id',
    ];
    $out = [];
    foreach ($allow as $key) {
        if (array_key_exists($key, $row)) {
            $out[$key] = $row[$key];
        }
    }
    foreach (['note', 'payload', 'row_json', 'data'] as $bag) {
        if (!array_key_exists($bag, $row)) {
            continue;
        }
        $decoded = $row[$bag];
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            continue;
        }
        foreach ($allow as $key) {
            if (!array_key_exists($key, $out) && array_key_exists($key, $decoded)) {
                $out[$key] = $decoded[$key];
            }
        }
    }

    return $out;
}

/**
 * Kalender och Fortnox-mappning. Bara läsning, bara fakta.
 *
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_facts(PDO $pdo, int $id): array
{
    $out = [];
    foreach (jeab_ordrar_state_sources_calendar($pdo, $id) as $hit) {
        $out[] = $hit;
    }
    foreach (jeab_ordrar_state_sources_fortnox_map($pdo, $id) as $hit) {
        $out[] = $hit;
    }

    return $out;
}

/**
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_calendar(PDO $pdo, int $id): array
{
    if (!jeab_ordrar_restore_state_table($pdo, 'bookings')) {
        return [];
    }
    $cols = jeab_ordrar_restore_state_columns($pdo, 'bookings');
    if (!isset($cols['id'], $cols['starts_at'])) {
        return [];
    }
    $select = ['id', 'starts_at'];
    foreach (['updated_at', 'created_at', 'status', 'contact_id', 'order_id'] as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }
    $where = [];
    $args = [];
    if (isset($cols['order_id'])) {
        $where[] = 'order_id = ?';
        $args[] = $id;
    }
    $contact = '';
    if (isset($cols['contact_id']) && function_exists('jeab_purchase_get')) {
        $live = jeab_purchase_get($id);
        if (is_array($live)) {
            $contact = trim((string) ($live['contact_id'] ?? ''));
        }
    }
    if ($contact !== '' && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $contact) === 1) {
        $where[] = 'contact_id = ?';
        $args[] = $contact;
    }
    $bookingId = 0;
    if (function_exists('jeab_purchase_get')) {
        $live = jeab_purchase_get($id);
        $bookingId = is_array($live) ? (int) ($live['booking_id'] ?? 0) : 0;
    }
    if ($bookingId > 0) {
        $where[] = 'id = ?';
        $args[] = $bookingId;
    }
    if ($where === []) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM bookings WHERE ' . implode(' OR ', $where) . ' LIMIT 20');
        $stmt->execute($args);
        $fetched = $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach (is_array($fetched) ? $fetched : [] as $hit) {
        if (!is_array($hit)) {
            continue;
        }
        $bid = (int) ($hit['id'] ?? 0);
        if ($bid <= 0 || isset($seen[$bid])) {
            continue;
        }
        if ((string) ($hit['status'] ?? '') === 'cancelled') {
            continue;
        }
        $seen[$bid] = true;
        $starts = trim((string) ($hit['starts_at'] ?? ''));
        $row = [
            'booking_id' => $bid,
            'updated_at' => (string) ($hit['updated_at'] ?? ''),
            'created_at' => (string) ($hit['created_at'] ?? ''),
        ];
        if ($starts !== '') {
            $row['install_date'] = jeab_ordrar_restore_state_date($starts);
            $row['bokad_tid'] = $starts;
            $row['steps'] = ['bokad_tid' => $starts];
        }
        $out[] = [
            'source' => 'calendar',
            'at' => jeab_ordrar_restore_state_stamp($hit['updated_at'] ?? '', (string) ($hit['created_at'] ?? '')),
            'file' => '',
            'path' => 'bookings/' . $bid,
            'row' => $row,
        ];
    }

    return $out;
}

/**
 * @return list<array{source:string,at:string,file:string,path:string,row:array<string,mixed>}>
 */
function jeab_ordrar_state_sources_fortnox_map(PDO $pdo, int $id): array
{
    $tables = jeab_ordrar_state_sources_fortnox_tables($pdo);
    $out = [];
    foreach ($tables as $table) {
        $cols = jeab_ordrar_restore_state_columns($pdo, $table);
        if (!isset($cols['reminder_id'], $cols['fortnox_order'])) {
            continue;
        }
        $when = isset($cols['created_at']) ? 'created_at' : (isset($cols['updated_at']) ? 'updated_at' : '');
        $sql = 'SELECT fortnox_order' . ($when !== '' ? ', ' . $when . ' AS at_col' : '') . ' FROM ' . $table . ' WHERE reminder_id = ?';
        if ($when !== '') {
            $sql .= ' ORDER BY ' . $when . ' DESC';
        }
        $sql .= ' LIMIT 5';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            $fetched = $stmt->fetchAll();
        } catch (Throwable) {
            continue;
        }
        foreach (is_array($fetched) ? $fetched : [] as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $number = trim((string) ($hit['fortnox_order'] ?? ''));
            if ($number === '') {
                continue;
            }
            $at = jeab_ordrar_restore_state_stamp('', (string) ($hit['at_col'] ?? ''));
            $out[] = [
                'source' => 'fortnox',
                'at' => $at,
                'file' => '',
                'path' => $table,
                'row' => [
                    'fortnox_order' => $number,
                    'updated_at' => $at,
                ],
            ];
        }
    }

    return $out;
}

/** @return list<string> */
function jeab_ordrar_state_sources_fortnox_tables(PDO $pdo): array
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $rows = $pdo->query("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'fortnox\\_%'")->fetchAll();
        } else {
            $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'fortnox_%'")->fetchAll();
        }
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = (string) ($row['name'] ?? $row['table_name'] ?? $row['TABLE_NAME'] ?? '');
        if (preg_match('/\Afortnox_[a-z0-9_]{1,40}\z/', $name) === 1) {
            $out[] = $name;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $live
 * @return array{state:array<string, mixed>}
 */
function jeab_ordrar_state_sources_derive(PDO $pdo, int $id, array $live): array
{
    $state = [];
    $booking = jeab_ordrar_state_sources_linked_booking($pdo, $id, $live);
    if (is_array($booking)) {
        $bid = (int) ($booking['id'] ?? 0);
        $starts = trim((string) ($booking['starts_at'] ?? ''));
        $install = jeab_ordrar_restore_state_date($starts);
        if ($bid > 0) {
            $state['booking_id'] = $bid;
        }
        if ($install !== '') {
            $state['install_date'] = $install;
            $state['steps'] = ['bokad_tid' => $starts !== '' ? $starts : $install];
        }
        // bokad_tid är det fjärde steget: installationen är bokad. Lägre steg höjs dit.
        $bookedStep = 4;
        if ((int) ($live['step'] ?? 0) < $bookedStep) {
            $state['step'] = $bookedStep;
        }
    }
    $number = jeab_ordrar_state_sources_fortnox_number($pdo, $id);
    if ($number !== '') {
        $state['fortnox_order'] = $number;
    }

    return ['state' => jeab_ordrar_restore_state_extract($state)];
}

/**
 * Kopplad bokning: bookings.order_id, annars orderns booking_id.
 *
 * @param array<string, mixed> $live
 * @return array<string, mixed>|null
 */
function jeab_ordrar_state_sources_linked_booking(PDO $pdo, int $id, array $live): ?array
{
    if (!jeab_ordrar_restore_state_table($pdo, 'bookings')) {
        return null;
    }
    $cols = jeab_ordrar_restore_state_columns($pdo, 'bookings');
    if (!isset($cols['id'], $cols['starts_at'])) {
        return null;
    }
    $select = 'id, starts_at';
    if (isset($cols['status'])) {
        $select .= ', status';
    }
    if (isset($cols['updated_at'])) {
        $select .= ', updated_at';
    }
    $pick = static function (array $fetched): ?array {
        $best = null;
        $bestAt = '';
        foreach ($fetched as $hit) {
            if (!is_array($hit) || (string) ($hit['status'] ?? '') === 'cancelled') {
                continue;
            }
            $at = trim((string) ($hit['updated_at'] ?? ''));
            if ($best === null || strcmp($at, $bestAt) > 0) {
                $best = $hit;
                $bestAt = $at;
            }
        }

        return $best;
    };
    if (isset($cols['order_id'])) {
        try {
            $stmt = $pdo->prepare('SELECT ' . $select . ' FROM bookings WHERE order_id = ? LIMIT 20');
            $stmt->execute([$id]);
            $fetched = $stmt->fetchAll();
            $hit = $pick(is_array($fetched) ? $fetched : []);
            if (is_array($hit)) {
                return $hit;
            }
        } catch (Throwable) {
            return null;
        }
    }
    $bookingId = (int) ($live['booking_id'] ?? 0);
    if ($bookingId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT ' . $select . ' FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $hit = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!is_array($hit) || (string) ($hit['status'] ?? '') === 'cancelled') {
        return null;
    }

    return $hit;
}

function jeab_ordrar_state_sources_fortnox_number(PDO $pdo, int $id): string
{
    $best = '';
    $bestAt = '';
    foreach (jeab_ordrar_state_sources_fortnox_map($pdo, $id) as $hit) {
        $number = trim((string) ($hit['row']['fortnox_order'] ?? ''));
        if ($number === '') {
            continue;
        }
        $at = (string) ($hit['at'] ?? '');
        if ($best === '' || strcmp($at, $bestAt) > 0) {
            $best = $number;
            $bestAt = $at;
        }
    }

    return $best;
}
