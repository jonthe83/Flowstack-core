<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Omhämtning av fastighetsbeteckning och ägare från det befintliga Lantmäteriet-uppslaget.
 * dry=1 (standard) skriver inget. dry=0 skriver bara fält som skiljer sig, på ordern
 * och den kopplade kontakten. Gamla värden sparas så order.fastighet_undo kan lägga tillbaka dem.
 * Påminnelse, bokning och kontaktlänk rörs inte. Personnummer lämnar aldrig svar eller logg.
 */

function jeab_fastighet_refresh_wants_dry(array $input): bool
{
    if (function_exists('jeab_ordrar_backfill_wants_dry')) {
        return jeab_ordrar_backfill_wants_dry($input);
    }
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];

    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_fastighet_order_address(array $row): string
{
    $address = trim((string) ($row['address'] ?? ''));
    if ($address !== '') {
        return $address;
    }
    $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($address !== '') {
        return $address;
    }
    $gata = trim((string) ($row['gata'] ?? ''));
    $ort = trim((string) ($row['ort'] ?? ''));

    return trim($gata . ($gata !== '' && $ort !== '' ? ', ' : '') . $ort);
}

function jeab_fastighet_address_hit(string $address, string $query): bool
{
    if (!function_exists('jeab_rot_address_exact') && is_file(__DIR__ . '/address_lookup.php')) {
        require_once __DIR__ . '/address_lookup.php';
    }
    if (function_exists('jeab_rot_address_identity') && function_exists('jeab_rot_address_exact')) {
        $want = jeab_rot_address_identity($query);
        $have = jeab_rot_address_identity($address);
        if ($want['number'] !== '' && $have['number'] !== '') {
            return jeab_rot_address_exact($query, $address);
        }
    }
    $hay = function_exists('jeab_ordrar_name_fold') ? jeab_ordrar_name_fold($address) : strtolower(trim($address));
    $needle = function_exists('jeab_ordrar_name_fold') ? jeab_ordrar_name_fold($query) : strtolower(trim($query));
    if ($hay === '' || $needle === '') {
        return false;
    }
    $pos = 0;
    $len = strlen($needle);
    while (($at = strpos($hay, $needle, $pos)) !== false) {
        $next = $hay[$at + $len] ?? '';
        if ($next === '' || !ctype_digit($next)) {
            return true;
        }
        $pos = $at + 1;
    }

    return false;
}

/**
 * @return list<array{id:int,address:string}>
 */
function jeab_order_find_matches(string $query): array
{
    $query = trim($query);
    if ($query === '' || !function_exists('jeab_purchase_all')) {
        return [];
    }
    $out = [];
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $address = jeab_fastighet_order_address($row);
        $parts = [
            $address,
            trim((string) ($row['address'] ?? '')),
            trim((string) ($row['huvudsokande_address'] ?? '')),
            trim((string) ($row['gata'] ?? '')),
            trim(trim((string) ($row['gata'] ?? '')) . ' ' . trim((string) ($row['ort'] ?? ''))),
        ];
        $hit = false;
        foreach ($parts as $part) {
            if ($part !== '' && jeab_fastighet_address_hit($part, $query)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
        $out[] = ['id' => $id, 'address' => $address];
        if (count($out) >= 20) {
            break;
        }
    }

    return $out;
}

/**
 * order.find. Bara id och adress. Skriver inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_order_find(array $input): array
{
    $query = trim((string) ($input['q'] ?? $input['address'] ?? $input['query'] ?? ''));
    if ($query === '') {
        return ['ok' => false, 'error' => 'ange q', 'action' => 'order.find', 'status' => 422];
    }
    $matches = jeab_order_find_matches($query);

    return jeab_fastighet_scrub_public([
        'ok' => true,
        'action' => 'order.find',
        'matches' => $matches,
        'status' => 200,
    ]);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_order_find_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'order.find', 'status' => 401];
    }

    return jeab_order_find($input);
}

/**
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function jeab_fastighet_stored_people(array $row): array
{
    $people = function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($row['owners'] ?? [], 8) : [];
    if ($people !== []) {
        return $people;
    }
    $seed = [[
        'name' => (string) ($row['huvudsokande'] ?? ''),
        'personnummer' => (string) ($row['personnummer'] ?? ''),
        'address' => (string) ($row['huvudsokande_address'] ?? ''),
        'andel' => (string) ($row['huvudsokande_andel'] ?? ''),
        'lagfart' => (string) ($row['huvudsokande_lagfart'] ?? ''),
    ]];
    foreach (is_array($row['medsokande'] ?? null) ? $row['medsokande'] : [] as $med) {
        $seed[] = $med;
    }

    return function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($seed, 8) : [];
}

function jeab_fastighet_person_key(array $person): string
{
    $name = function_exists('jeab_ordrar_name_fold')
        ? jeab_ordrar_name_fold((string) ($person['name'] ?? ''))
        : strtolower(trim((string) ($person['name'] ?? '')));
    $pnr = preg_replace('/\D+/', '', (string) ($person['personnummer'] ?? '')) ?? '';
    $andel = function_exists('jeab_ordrar_name_fold')
        ? jeab_ordrar_name_fold((string) ($person['andel'] ?? ''))
        : strtolower(trim((string) ($person['andel'] ?? '')));

    return $name . '|' . $pnr . '|' . $andel;
}

/** @param array<string, mixed> $person @return array<string, string> */
function jeab_fastighet_owner_public(array $person): array
{
    $out = ['name' => trim((string) ($person['name'] ?? ''))];
    $andel = trim((string) ($person['andel'] ?? ''));
    if ($andel !== '') {
        $out['andel'] = $andel;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $oldPeople
 * @param list<array<string, mixed>> $newPeople
 * @return array<string, mixed>
 */
function jeab_fastighet_people_same(array $a, array $b): bool
{
    $da = preg_replace('/\D+/', '', (string) ($a['personnummer'] ?? '')) ?? '';
    $db = preg_replace('/\D+/', '', (string) ($b['personnummer'] ?? '')) ?? '';
    if ($da !== '' && $db !== '' && $da === $db) {
        return true;
    }
    $na = function_exists('jeab_ordrar_name_fold')
        ? jeab_ordrar_name_fold((string) ($a['name'] ?? ''))
        : strtolower(trim((string) ($a['name'] ?? '')));
    $nb = function_exists('jeab_ordrar_name_fold')
        ? jeab_ordrar_name_fold((string) ($b['name'] ?? ''))
        : strtolower(trim((string) ($b['name'] ?? '')));

    return $na !== '' && $na === $nb;
}

/**
 * Befintliga ägare ligger kvar. Nya läggs till. Tomt personnummer fylls, ett ifyllt töms inte.
 *
 * @param list<array<string, mixed>> $oldPeople
 * @param list<array<string, mixed>> $newPeople
 * @return list<array<string, mixed>>
 */
function jeab_fastighet_union_people(array $oldPeople, array $newPeople): array
{
    $out = $oldPeople;
    foreach ($newPeople as $person) {
        if (!is_array($person)) {
            continue;
        }
        $match = null;
        foreach ($out as $i => $have) {
            if (is_array($have) && jeab_fastighet_people_same($have, $person)) {
                $match = $i;
                break;
            }
        }
        if ($match === null) {
            $name = trim((string) ($person['name'] ?? ''));
            $pnr = trim((string) ($person['personnummer'] ?? ''));
            if ($name === '' && $pnr === '') {
                continue;
            }
            $out[] = $person;
            continue;
        }
        $havePnr = trim((string) ($out[$match]['personnummer'] ?? ''));
        $newPnr = trim((string) ($person['personnummer'] ?? ''));
        if ($havePnr === '' && $newPnr !== '') {
            $out[$match]['personnummer'] = $newPnr;
        }
        if (trim((string) ($out[$match]['name'] ?? '')) === '' && trim((string) ($person['name'] ?? '')) !== '') {
            $out[$match]['name'] = trim((string) $person['name']);
        }
        if (trim((string) ($out[$match]['andel'] ?? '')) === '' && trim((string) ($person['andel'] ?? '')) !== '') {
            $out[$match]['andel'] = trim((string) $person['andel']);
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $oldPeople
 * @param list<array<string, mixed>> $newPeople
 * @return list<string>
 */
function jeab_fastighet_missing(array $row, array $suggest, array $oldPeople, array $newPeople): array
{
    $missing = [];
    $oldFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($row['fastighetsbeteckning'] ?? ''))
        : trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $newFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''))
        : trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    if ($oldFast === '' && $newFast === '') {
        $missing[] = 'fastighetsbeteckning';
    }
    $union = jeab_fastighet_union_people($oldPeople, $newPeople);
    if (count($oldPeople) < 2 && count($union) <= count($oldPeople) && count($newPeople) > count($oldPeople)) {
        $missing[] = 'ägare';
    }
    if ($oldPeople === [] && $union === []) {
        $missing[] = 'ägare';
    }

    return $missing;
}

function jeab_fastighet_diff(array $row, array $suggest, array $oldPeople, array $newPeople, bool $replace = false): array
{
    $diff = [];
    $oldFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($row['fastighetsbeteckning'] ?? ''))
        : trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $newFast = function_exists('jeab_ordrar_format_beteckning')
        ? jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''))
        : trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    if ($oldFast !== $newFast && $newFast !== '') {
        $diff['fastighetsbeteckning'] = ['old' => $oldFast, 'new' => $newFast];
    }
    $compare = $newPeople;
    if (!$replace) {
        $aligned = jeab_fastighet_align_existing_owners($row, $newPeople);
        if ($aligned !== null) {
            $compare = jeab_fastighet_stored_people($aligned);
        }
    }
    $oldKeys = array_map('jeab_fastighet_person_key', $oldPeople);
    $newKeys = array_map('jeab_fastighet_person_key', $compare);
    if ($newPeople !== [] && $oldKeys !== $newKeys) {
        $diff['owners'] = [
            'old' => array_map('jeab_fastighet_owner_public', $oldPeople),
            'new' => array_map('jeab_fastighet_owner_public', $compare),
        ];
    }

    return $diff;
}

/**
 * @param array<string, mixed> $suggest
 * @return list<array<string, mixed>>
 */
function jeab_fastighet_suggest_people(array $suggest): array
{
    if (!empty($suggest['lookup_empty'])) {
        return [];
    }
    $owners = is_array($suggest['owners'] ?? null) ? $suggest['owners'] : [];

    return function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($owners, 8) : [];
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function jeab_fastighet_lookup(array $row, ?array $reminder, string $objektId = '', bool $fresh = false): array
{
    $query = function_exists('jeab_ordrar_lookup_query') ? jeab_ordrar_lookup_query($reminder, $row) : jeab_fastighet_order_address($row);
    $objektId = trim($objektId);
    if ($objektId === '') {
        $objektId = '';
    }
    $forgetId = $objektId !== '' ? $objektId : trim((string) ($row['objektidentitet'] ?? ''));
    if (function_exists('jeab_rot_lm_cache_forget')) {
        jeab_rot_lm_cache_forget((string) ($row['fastighetsbeteckning'] ?? ''), $query, $forgetId);
    }
    $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
    $GLOBALS['_JEAB_LM_CACHE_GEN'] = (int) ($GLOBALS['_JEAB_LM_CACHE_GEN'] ?? 0) + 1;
    $was = function_exists('jeab_ordrar_external_live') ? jeab_ordrar_external_live() : false;
    if (function_exists('jeab_ordrar_external_live')) {
        jeab_ordrar_external_live(true);
    }
    try {
        if (!function_exists('jeab_ordrar_confirm_suggest')) {
            return ['lookup_empty' => true, 'fastighetsbeteckning' => '', 'owners' => []];
        }

        return jeab_ordrar_confirm_suggest($row, $reminder, $query !== '' ? $query : null, $objektId, $fresh);
    } finally {
        if (function_exists('jeab_ordrar_external_live')) {
            jeab_ordrar_external_live($was);
        }
        unset($GLOBALS['_JEAB_LM_CACHE_BYPASS']);
    }
}

/** @return list<string> */
function jeab_fastighet_link_keys(): array
{
    return ['reminder_id', 'booking_id', 'contact_id', 'fortnox_order', 'fortnox_invoice', 'thread_id'];
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function jeab_fastighet_link_snapshot(array $row): array
{
    $out = [];
    foreach (jeab_fastighet_link_keys() as $key) {
        if (array_key_exists($key, $row)) {
            $out[$key] = $row[$key];
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $links
 * @return array<string, mixed>
 */
function jeab_fastighet_restore_links(array $row, array $links): array
{
    foreach ($links as $key => $value) {
        $row[$key] = $value;
    }

    return $row;
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function jeab_fastighet_order_slice(array $row): array
{
    $keys = [
        'fastighetsbeteckning', 'owners', 'medsokande', 'huvudsokande', 'personnummer',
        'huvudsokande_address', 'huvudsokande_andel', 'huvudsokande_lagfart', 'identity_source',
    ];
    $out = [];
    foreach ($keys as $key) {
        $out[$key] = $row[$key] ?? null;
    }

    return $out;
}

/** @param array<string, mixed> $contact @return array<string, mixed> */
function jeab_fastighet_contact_slice(array $contact): array
{
    return [
        'fastighetsbeteckning' => (string) ($contact['fastighetsbeteckning'] ?? ''),
        'owners' => is_array($contact['owners'] ?? null) ? $contact['owners'] : [],
        'huvudsokande' => trim((string) ($contact['huvudsokande'] ?? '')),
    ];
}

function jeab_fastighet_undo_pdo(): ?PDO
{
    $injected = $GLOBALS['_JEAB_FASTIGHET_UNDO_PDO'] ?? null;
    if ($injected instanceof PDO) {
        return $injected;
    }
    if (!function_exists('jeab_sql_store_pdo')) {
        return null;
    }
    $pdo = jeab_sql_store_pdo();

    return $pdo instanceof PDO ? $pdo : null;
}

function jeab_fastighet_undo_driver(PDO $pdo): string
{
    try {
        $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    } catch (Throwable) {
        $name = '';
    }

    return $name === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_fastighet_undo_ready(): ?PDO
{
    $pdo = jeab_fastighet_undo_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_ensure_fastighet_undo')) {
        return null;
    }
    jeab_ensure_fastighet_undo($pdo, jeab_fastighet_undo_driver($pdo));

    return $pdo;
}

/** @param list<string> $fields */
function jeab_fastighet_log(string $event, int $orderId, string $batch, array $fields): void
{
    $names = array_values(array_unique(array_map('strval', $fields)));
    sort($names);
    $fieldsText = implode(',', $names);
    $line = $event . ' order=' . $orderId . ' batch=' . $batch . ' fields=' . $fieldsText;
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($line);
        if (is_string($scrubbed) && $scrubbed !== $line) {
            $fieldsText = '';
        }
    }
    $pdo = jeab_fastighet_undo_ready();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO fastighet_refresh_log (created_at, event, order_id, batch_id, fields) VALUES (?, ?, ?, ?, ?)')
            ->execute([$at, $event, $orderId, $batch, $fieldsText]);
    } catch (Throwable) {
        return;
    }
}

function jeab_fastighet_log_text(): string
{
    $pdo = jeab_fastighet_undo_pdo();
    if (!$pdo instanceof PDO) {
        return '';
    }
    try {
        $rows = $pdo->query('SELECT event, order_id, batch_id, fields FROM fastighet_refresh_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return '';
    }
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = (string) ($row['event'] ?? '')
            . ' order=' . (string) ($row['order_id'] ?? '')
            . ' batch=' . (string) ($row['batch_id'] ?? '')
            . ' fields=' . (string) ($row['fields'] ?? '');
    }

    return implode("\n", $lines);
}

/**
 * @param array<string, mixed> $orderSlice
 * @param array<string, mixed>|null $contactSlice
 */
function jeab_fastighet_undo_add(string $batch, int $orderId, string $contactId, array $orderSlice, ?array $contactSlice): bool
{
    $pdo = jeab_fastighet_undo_ready();
    if (!$pdo instanceof PDO) {
        return false;
    }
    $orderJson = json_encode($orderSlice, JSON_UNESCAPED_UNICODE);
    if (!is_string($orderJson)) {
        return false;
    }
    $contactJson = null;
    if (is_array($contactSlice)) {
        $encoded = json_encode($contactSlice, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return false;
        }
        $contactJson = $encoded;
    }
    try {
        $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO fastighet_undo (batch_id, order_id, contact_id, order_json, contact_json, undone, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)'
        )->execute([$batch, $orderId, $contactId, $orderJson, $contactJson, $at]);
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** @return array<string, mixed>|null */
function jeab_fastighet_undo_get(string $batch): ?array
{
    $batch = trim($batch);
    if ($batch === '') {
        return null;
    }
    $pdo = jeab_fastighet_undo_ready();
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT batch_id, order_id, contact_id, order_json, contact_json, undone FROM fastighet_undo WHERE batch_id = ?');
        $stmt->execute([$batch]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }
    $order = json_decode((string) ($row['order_json'] ?? ''), true);
    $contactRaw = $row['contact_json'] ?? null;
    $contact = is_string($contactRaw) && $contactRaw !== '' ? json_decode($contactRaw, true) : null;

    return [
        'id' => (string) ($row['batch_id'] ?? ''),
        'order_id' => (int) ($row['order_id'] ?? 0),
        'contact_id' => (string) ($row['contact_id'] ?? ''),
        'order' => is_array($order) ? $order : [],
        'contact' => is_array($contact) ? $contact : null,
        'undone' => (int) ($row['undone'] ?? 0),
    ];
}

function jeab_fastighet_undo_mark(string $batch): void
{
    $pdo = jeab_fastighet_undo_ready();
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        $pdo->prepare('UPDATE fastighet_undo SET undone = 1 WHERE batch_id = ?')->execute([$batch]);
    } catch (Throwable) {
        return;
    }
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $diff
 * @param array<string, mixed> $suggest
 * @param list<array<string, mixed>> $newPeople
 * @return array<string, mixed>
 */
function jeab_fastighet_apply_order_fields(array $row, array $diff, array $suggest, array $newPeople, bool $replace = false): array
{
    if (isset($diff['objektidentitet'])) {
        $row['objektidentitet'] = (string) ($diff['objektidentitet']['new'] ?? '');
    }
    if (isset($diff['fastighetsbeteckning'])) {
        $nextFast = trim((string) ($diff['fastighetsbeteckning']['new'] ?? ''));
        if ($nextFast !== '') {
            $row['fastighetsbeteckning'] = $nextFast;
        }
        if (function_exists('jeab_ordrar_mark_identity_source')) {
            $query = function_exists('jeab_ordrar_lookup_query') ? jeab_ordrar_lookup_query(null, $row) : '';
            $row = jeab_ordrar_mark_identity_source($row, 'fastighetsbeteckning', 'lookup', $query);
        }
    }
    if (!isset($diff['owners'])) {
        return $row;
    }
    if (!$replace) {
        $aligned = jeab_fastighet_align_existing_owners($row, $newPeople);
        if ($aligned !== null) {
            return $aligned;
        }
    }
    $huvudName = trim((string) ($suggest['huvudsokande'] ?? ''));
    $huvudPnr = function_exists('jeab_ordrar_format_pnr')
        ? jeab_ordrar_format_pnr((string) ($suggest['personnummer'] ?? ''))
        : trim((string) ($suggest['personnummer'] ?? ''));
    if (function_exists('jeab_ordrar_owners_split_huvud')) {
        [$owners, $med] = jeab_ordrar_owners_split_huvud($newPeople, $huvudName, $huvudPnr);
    } else {
        $owners = $newPeople;
        $med = [];
    }
    $row['owners'] = $owners;
    $row['medsokande'] = $med;
    if ($huvudName !== '') {
        $row['huvudsokande'] = $huvudName;
    } elseif (isset($owners[0]['name'])) {
        $row['huvudsokande'] = (string) $owners[0]['name'];
    }
    if ($huvudPnr !== '') {
        $row['personnummer'] = $huvudPnr;
    } elseif (isset($owners[0]['personnummer'])) {
        $row['personnummer'] = (string) $owners[0]['personnummer'];
    }
    $huvudAddr = trim((string) ($suggest['huvudsokande_address'] ?? ''));
    if ($huvudAddr !== '') {
        $row['huvudsokande_address'] = $huvudAddr;
    }
    if (trim((string) ($suggest['huvudsokande_andel'] ?? '')) !== '') {
        $row['huvudsokande_andel'] = trim((string) $suggest['huvudsokande_andel']);
    }
    if (trim((string) ($suggest['huvudsokande_lagfart'] ?? '')) !== '') {
        $row['huvudsokande_lagfart'] = trim((string) $suggest['huvudsokande_lagfart']);
    }

    return $row;
}

/**
 * @param array<string, mixed> $contact
 * @param array<string, mixed> $diff
 * @param list<array<string, mixed>> $newPeople
 * @return array<string, mixed>
 */
function jeab_fastighet_apply_contact_fields(array $contact, array $diff, array $newPeople): array
{
    if (isset($diff['fastighetsbeteckning'])) {
        $contact['fastighetsbeteckning'] = (string) ($diff['fastighetsbeteckning']['new'] ?? '');
    }
    if (!isset($diff['owners'])) {
        $contact['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

        return $contact;
    }
    $existing = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $existing[] = $owner;
        }
    }
    $email = '';
    $phone = '';
    foreach ($existing as $owner) {
        if (!empty($owner['contact'])) {
            $email = trim((string) ($owner['email'] ?? ''));
            $phone = trim((string) ($owner['phone'] ?? ''));
            break;
        }
    }
    if ($email === '' && isset($existing[0]) && is_array($existing[0])) {
        $email = trim((string) ($existing[0]['email'] ?? ''));
        $phone = trim((string) ($existing[0]['phone'] ?? ''));
    }
    $next = [];
    foreach ($newPeople as $i => $person) {
        $pnr = function_exists('jeab_ordrar_format_pnr')
            ? jeab_ordrar_format_pnr((string) ($person['personnummer'] ?? ''))
            : trim((string) ($person['personnummer'] ?? ''));
        $next[] = [
            'name' => trim((string) ($person['name'] ?? '')),
            'personnummer' => $pnr,
            'contact' => $i === 0,
            'email' => $i === 0 ? $email : '',
            'phone' => $i === 0 ? $phone : '',
        ];
    }
    if ($next !== []) {
        $contact['owners'] = $next;
    }
    $contact['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

    return $contact;
}

/** @param array<string, mixed> $contact @param array<string, mixed> $slice */
function jeab_fastighet_write_contact(string $contactId, array $slice): bool
{
    $contactId = trim($contactId);
    if ($contactId === '' || !function_exists('jeab_kontakter_mutate')) {
        return false;
    }
    $wrote = false;
    jeab_kontakter_mutate(function (array &$contacts) use ($contactId, $slice, &$wrote): void {
        $row = $contacts[$contactId] ?? null;
        if (!is_array($row)) {
            return;
        }
        if (array_key_exists('fastighetsbeteckning', $slice)) {
            $row['fastighetsbeteckning'] = $slice['fastighetsbeteckning'];
        }
        if (array_key_exists('owners', $slice) && is_array($slice['owners'])) {
            $row['owners'] = $slice['owners'];
        }
        if (array_key_exists('personnummer', $slice)) {
            $row['personnummer'] = $slice['personnummer'];
        }
        if (array_key_exists('huvudsokande', $slice)) {
            $row['huvudsokande'] = trim((string) $slice['huvudsokande']);
        }
        $row['id'] = $contactId;
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$contactId] = $row;
        $wrote = true;
    });
    if ($wrote && function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($contactId);
    }

    return $wrote;
}

function jeab_fastighet_scrub_public(mixed $value): mixed
{
    if (is_string($value)) {
        if (!function_exists('jeab_ordrar_scrub_pnr')) {
            return $value;
        }
        $scrubbed = jeab_ordrar_scrub_pnr($value);

        return is_string($scrubbed) ? $scrubbed : $value;
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        if (in_array((string) $key, ['personnummer', 'pnr', 'owner_pnr', 'personnummer_finns', 'SocialSecurityNumber'], true)) {
            continue;
        }
        $out[$key] = jeab_fastighet_scrub_public($item);
    }

    return $out;
}

/**
 * order.fastighet_refresh. dry=1 är standard.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_order_fastighet_refresh(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = jeab_fastighet_refresh_wants_dry($input);
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    $addressQuery = trim((string) ($input['address'] ?? ''));
    if ($id <= 0 && $addressQuery === '') {
        return ['ok' => false, 'error' => 'ange order_id eller address', 'action' => 'order.fastighet_refresh', 'dry' => $dry ? 1 : 0, 'status' => 422];
    }
    if ($id <= 0) {
        $matches = jeab_order_find_matches($addressQuery);
        if (count($matches) !== 1) {
            return jeab_fastighet_scrub_public([
                'ok' => false,
                'error' => $matches === [] ? 'order saknas' : 'flera ordrar',
                'action' => 'order.fastighet_refresh',
                'dry' => $dry ? 1 : 0,
                'matches' => $matches,
                'written' => 0,
                'status' => $matches === [] ? 404 : 409,
            ]);
        }
        $id = (int) $matches[0]['id'];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'order.fastighet_refresh', 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'status' => 404];
    }
    $reminder = null;
    if (function_exists('jeab_ordrar_reminder_for_row')) {
        try {
            $got = jeab_ordrar_reminder_for_row($row);
            $reminder = is_array($got) ? $got : null;
        } catch (Throwable) {
            $reminder = null;
        }
    }
    $address = jeab_fastighet_order_address($row);
    $overrideFast = trim((string) ($input['fastighetsbeteckning'] ?? ''));
    $overrideId = trim((string) ($input['objektidentitet'] ?? ''));
    try {
        $suggest = jeab_fastighet_lookup($row, $reminder, $overrideId, true);
    } catch (Throwable $e) {
        if (function_exists('jeab_rot_lookup_log')) {
            jeab_rot_lookup_log('fel vid omhämt av fastighet', get_class($e));
        }

        return ['ok' => false, 'error' => 'Uppslaget misslyckades.', 'action' => 'order.fastighet_refresh', 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'status' => 502];
    }
    $oldPeople = jeab_fastighet_stored_people($row);
    $newPeople = jeab_fastighet_suggest_people($suggest);
    $suggest = is_array($suggest) ? $suggest : [];
    if ($overrideFast !== '' || $overrideId !== '') {
        $compare = function_exists('jeab_ordrar_lookup_customer_address')
            ? jeab_ordrar_lookup_customer_address($address, $row)
            : $address;
        $have = trim((string) ($suggest['fetched_address'] ?? $suggest['address'] ?? $suggest['huvudsokande_address'] ?? ''));
        $exact = $have !== '' && function_exists('jeab_rot_address_exact') && jeab_rot_address_exact($compare, $have);
        $gotFast = function_exists('jeab_ordrar_format_beteckning')
            ? jeab_ordrar_format_beteckning((string) ($suggest['fastighetsbeteckning'] ?? ''))
            : trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
        $wantFast = $overrideFast !== '' && function_exists('jeab_ordrar_format_beteckning')
            ? jeab_ordrar_format_beteckning($overrideFast)
            : $overrideFast;
        if (!$exact || ($wantFast !== '' && $gotFast !== $wantFast) || !empty($suggest['lookup_empty'])) {
            return jeab_fastighet_scrub_public([
                'ok' => false,
                'error' => 'inte exakt',
                'action' => 'order.fastighet_refresh',
                'order_id' => $id,
                'dry' => $dry ? 1 : 0,
                'written' => 0,
                'status' => 422,
            ]);
        }
    }
    $diff = jeab_fastighet_diff($row, $suggest, $oldPeople, $newPeople, true);
    if ($overrideId !== '' && trim((string) ($row['objektidentitet'] ?? '')) !== $overrideId) {
        $diff['objektidentitet'] = [
            'old' => trim((string) ($row['objektidentitet'] ?? '')),
            'new' => $overrideId,
        ];
    }
    $base = [
        'ok' => true,
        'action' => 'order.fastighet_refresh',
        'dry' => $dry ? 1 : 0,
        'order_id' => $id,
        'address' => $address,
        'changed' => $diff === [] ? 0 : 1,
        'diff' => $diff,
        'missing' => jeab_fastighet_missing($row, $suggest, $oldPeople, $newPeople),
        'written' => 0,
        'status' => 200,
    ];
    if ($dry || $diff === []) {
        return jeab_fastighet_scrub_public($base);
    }
    $links = jeab_fastighet_link_snapshot($row);
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = ($contactId !== '' && function_exists('jeab_kontakt_get')) ? jeab_kontakt_get($contactId) : null;
    $contactSlice = is_array($contact) ? jeab_fastighet_contact_slice($contact) : null;
    $orderSlice = jeab_fastighet_order_slice($row);
    if (isset($diff['objektidentitet'])) {
        $orderSlice['objektidentitet'] = $row['objektidentitet'] ?? null;
    }
    $batch = bin2hex(random_bytes(8));
    if (!jeab_fastighet_undo_add($batch, $id, $contactId, $orderSlice, $contactSlice)) {
        return [
            'ok' => false,
            'error' => 'Kunde inte spara ångringen.',
            'action' => 'order.fastighet_refresh',
            'order_id' => $id,
            'dry' => 0,
            'written' => 0,
            'status' => 503,
        ];
    }
    $GLOBALS['_JEAB_LM_CACHE_BYPASS'] = true;
    $GLOBALS['_JEAB_LM_FRESH'] = true;
    $GLOBALS['_JEAB_LM_OWNER_REPLACE'] = true;
    $wasLive = function_exists('jeab_ordrar_external_live') ? jeab_ordrar_external_live() : false;
    if (function_exists('jeab_ordrar_external_live')) {
        jeab_ordrar_external_live(true);
    }
    try {
        if (function_exists('jeab_ordrar_sync_lookup_owners')) {
            jeab_ordrar_sync_lookup_owners($row, $reminder, true);
        }
    } catch (Throwable) {
    } finally {
        if (function_exists('jeab_ordrar_external_live')) {
            jeab_ordrar_external_live($wasLive);
        }
        unset($GLOBALS['_JEAB_LM_FRESH'], $GLOBALS['_JEAB_LM_OWNER_REPLACE'], $GLOBALS['_JEAB_LM_CACHE_BYPASS']);
    }
    $patched = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($id, function (array $fresh) use ($diff, $suggest, $newPeople, $links): array {
            $fresh = jeab_fastighet_apply_order_fields($fresh, $diff, $suggest, $newPeople, true);
            $fresh = jeab_fastighet_restore_links($fresh, $links);
            $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
            $fresh['lm_lookup_error'] = null;

            return $fresh;
        })
        : null;
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'order.fastighet_refresh', 'order_id' => $id, 'dry' => 0, 'batch_id' => $batch, 'status' => 404];
    }
    $contactUpdated = 0;
    if (is_array($contact)) {
        $nextContact = jeab_fastighet_apply_contact_fields($contact, $diff, $newPeople);
        $write = ['id' => $contactId];
        if (isset($diff['fastighetsbeteckning'])) {
            $write['fastighetsbeteckning'] = (string) ($nextContact['fastighetsbeteckning'] ?? '');
        }
        if (count($write) > 1 && jeab_fastighet_write_contact($contactId, $write)) {
            $contactUpdated = 1;
        }
        if (function_exists('jeab_ordrar_sync_lm_contact') && jeab_ordrar_sync_lm_contact($patched)) {
            $contactUpdated = 1;
        }
    } elseif (function_exists('jeab_contacts_sole_divert_property')) {
        try {
            jeab_contacts_sole_divert_property($patched);
            $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
            $linked = is_array($fresh) ? trim((string) ($fresh['contact_id'] ?? '')) : '';
            if ($linked !== '') {
                $contactUpdated = 1;
            }
        } catch (Throwable) {
        }
    }
    jeab_fastighet_log('apply', $id, $batch, array_keys($diff));
    $base['dry'] = 0;
    $base['written'] = 1;
    $base['batch_id'] = $batch;
    $base['contact_updated'] = $contactUpdated;

    return jeab_fastighet_scrub_public($base);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_order_fastighet_refresh_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'order.fastighet_refresh', 'status' => 401];
    }

    return jeab_order_fastighet_refresh($store, $input);
}

/**
 * order.fastighet_undo. Lägger tillbaka fälten från batchen. Länkar rörs inte.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_order_fastighet_undo(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $batch = trim((string) ($input['batch_id'] ?? $input['batch'] ?? ''));
    if ($batch === '') {
        return ['ok' => false, 'error' => 'ange batch_id', 'action' => 'order.fastighet_undo', 'status' => 422];
    }
    $entry = jeab_fastighet_undo_get($batch);
    if (!is_array($entry)) {
        return ['ok' => false, 'error' => 'batch saknas', 'action' => 'order.fastighet_undo', 'status' => 404];
    }
    if ((int) ($entry['undone'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'batchen är redan ångrad', 'action' => 'order.fastighet_undo', 'batch_id' => $batch, 'status' => 409];
    }
    $orderId = (int) ($entry['order_id'] ?? 0);
    $orderSlice = is_array($entry['order'] ?? null) ? $entry['order'] : [];
    $current = function_exists('jeab_purchase_get') ? jeab_purchase_get($orderId) : null;
    if (!is_array($current)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'order.fastighet_undo', 'batch_id' => $batch, 'status' => 404];
    }
    $links = jeab_fastighet_link_snapshot($current);
    $patched = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($orderId, function (array $fresh) use ($orderSlice, $links): array {
            foreach ($orderSlice as $key => $value) {
                $fresh[(string) $key] = $value;
            }
            $fresh = jeab_fastighet_restore_links($fresh, $links);
            $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

            return $fresh;
        })
        : null;
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => 'order.fastighet_undo', 'batch_id' => $batch, 'status' => 404];
    }
    $contactId = trim((string) ($entry['contact_id'] ?? ''));
    $contactSlice = is_array($entry['contact'] ?? null) ? $entry['contact'] : null;
    if ($contactId !== '' && is_array($contactSlice)) {
        jeab_fastighet_write_contact($contactId, $contactSlice);
    }
    jeab_fastighet_undo_mark($batch);
    $restored = array_values(array_intersect(array_keys($orderSlice), ['fastighetsbeteckning', 'owners', 'objektidentitet', 'pris', 'kundpris']));
    jeab_fastighet_log('undo', $orderId, $batch, $restored);

    return jeab_fastighet_scrub_public([
        'ok' => true,
        'action' => 'order.fastighet_undo',
        'batch_id' => $batch,
        'order_id' => $orderId,
        'restored' => $restored,
        'status' => 200,
    ]);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_order_fastighet_undo_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'order.fastighet_undo', 'status' => 401];
    }

    return jeab_order_fastighet_undo($store, $input);
}

/**
 * ordrar.fastighet_scan. Alltid torr. Listar öppna ordrar som saknar beteckning,
 * eller har färre än två ägare när Lantmäteriet har fler. Skriver inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_fastighet_scan(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $before = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0);
    $GLOBALS['_JEAB_LM_CALL_BUDGET'] = $before + 40;
    $orders = [];
    try {
        foreach (function_exists('jeab_purchase_all') ? jeab_purchase_all() : [] as $row) {
            if (!is_array($row) || !jeab_ordrar_fastighet_scan_open($row)) {
                continue;
            }
            $stored = jeab_fastighet_stored_people($row);
            $ownerCount = count($stored);
            $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
            $needsFast = $fast === '';
            $needsOwners = $ownerCount < 2;
            if (!$needsFast && !$needsOwners) {
                continue;
            }
            $calls = (int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0);
            if ($calls >= (int) $GLOBALS['_JEAB_LM_CALL_BUDGET']) {
                break;
            }
            $reminder = null;
            if (function_exists('jeab_ordrar_reminder_for_row')) {
                try {
                    $got = jeab_ordrar_reminder_for_row($row);
                    $reminder = is_array($got) ? $got : null;
                } catch (Throwable) {
                    $reminder = null;
                }
            }
            try {
                $suggest = jeab_fastighet_lookup($row, $reminder);
            } catch (Throwable) {
                $suggest = ['lookup_empty' => true, 'fastighetsbeteckning' => '', 'owners' => []];
            }
            if ((int) ($GLOBALS['_JEAB_LM_SEARCH_CALLS'] ?? 0) === $calls) {
                break;
            }
            $lmPeople = jeab_fastighet_suggest_people(is_array($suggest) ? $suggest : []);
            $lmFast = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
            $lmCount = count($lmPeople);
            $include = $needsFast || ($needsOwners && $lmCount > $ownerCount);
            if (!$include) {
                continue;
            }
            $orders[] = [
                'order_id' => (int) ($row['reminder_id'] ?? 0),
                'address' => jeab_fastighet_order_address($row),
                'missing_fastighet' => $needsFast ? 1 : 0,
                'owner_count' => $ownerCount,
                'lm_owner_count' => $lmCount,
                'lm_fastighet' => $lmFast !== '' ? 1 : 0,
            ];
        }
    } finally {
        unset($GLOBALS['_JEAB_LM_CALL_BUDGET']);
    }

    return jeab_fastighet_scrub_public([
        'ok' => true,
        'action' => 'ordrar.fastighet_scan',
        'dry' => 1,
        'written' => 0,
        'orders' => $orders,
        'status' => 200,
    ]);
}

/** @param array<string, mixed> $row */
function jeab_ordrar_fastighet_scan_open(array $row): bool
{
    if ((int) ($row['reminder_id'] ?? 0) <= 0) {
        return false;
    }
    if (!empty($row['done'])) {
        return false;
    }
    if (trim((string) ($row['closed_at'] ?? '')) !== '') {
        return false;
    }
    if (trim((string) ($row['fortnox_invoice'] ?? '')) !== '') {
        return false;
    }

    return true;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_fastighet_scan_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.fastighet_scan', 'status' => 401];
    }

    return jeab_ordrar_fastighet_scan($store, $input);
}

/**
 * Lägg hämtade ägare på befintliga platser. Namn, ordning och huvudsökande
 * skrivs inte om. En tredje sökande från samma person läggs inte till.
 *
 * @param array<string, mixed> $row
 * @param list<array<string, mixed>> $newPeople
 * @return array<string, mixed>|null
 */
function jeab_fastighet_align_existing_owners(array $row, array $newPeople): ?array
{
    if (!function_exists('jeab_ordrar_person_matches') || !function_exists('jeab_ordrar_dedupe_people')) {
        return null;
    }
    $existing = jeab_fastighet_stored_people($row);
    $slots = jeab_ordrar_dedupe_people($existing, 8);
    if ($slots === []) {
        return null;
    }
    $used = [];
    $matched = 0;
    foreach ($slots as $i => $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $pick = jeab_fastighet_pick_owner_match($slot, $newPeople, $used);
        if ($pick === null) {
            continue;
        }
        $used[$pick] = true;
        $matched++;
        $incoming = is_array($newPeople[$pick] ?? null) ? $newPeople[$pick] : [];
        $slots[$i] = jeab_ordrar_person_fill_empty($slot, $incoming);
        if (trim((string) ($slots[$i]['andel'] ?? '')) === '' && trim((string) ($incoming['andel'] ?? '')) !== '') {
            $slots[$i]['andel'] = trim((string) $incoming['andel']);
        }
    }
    if ($matched === 0) {
        return null;
    }
    foreach ($newPeople as $j => $person) {
        if (isset($used[$j]) || !is_array($person)) {
            continue;
        }
        if (count($slots) >= 2) {
            break;
        }
        $name = trim((string) ($person['name'] ?? ''));
        $pnr = trim((string) ($person['personnummer'] ?? ''));
        if ($name === '' && $pnr === '') {
            continue;
        }
        $dup = false;
        foreach ($slots as $have) {
            if (is_array($have) && jeab_ordrar_person_matches($have, $person)) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            continue;
        }
        $slots[] = $person;
    }
    $huvudName = trim((string) ($row['huvudsokande'] ?? ''));
    $huvudPnr = trim((string) ($row['personnummer'] ?? ''));
    $probe = ['name' => $huvudName, 'personnummer' => $huvudPnr];
    $med = [];
    $skipped = false;
    foreach ($slots as $person) {
        if (!is_array($person)) {
            continue;
        }
        if (!$skipped && ($huvudName !== '' || $huvudPnr !== '') && jeab_ordrar_person_matches($probe, $person)) {
            $skipped = true;
            if (trim((string) ($row['personnummer'] ?? '')) === '') {
                $filled = trim((string) ($person['personnummer'] ?? ''));
                if ($filled !== '') {
                    $row['personnummer'] = $filled;
                }
            }
            continue;
        }
        $med[] = $person;
    }
    if (!$skipped && $slots !== []) {
        $med = array_slice($slots, 1);
    }
    $row['owners'] = $slots;
    $row['medsokande'] = array_values($med);

    return $row;
}

/**
 * @param array<string, mixed> $slot
 * @param list<array<string, mixed>> $newPeople
 * @param array<int, bool> $used
 */
function jeab_fastighet_pick_owner_match(array $slot, array $newPeople, array $used): ?int
{
    if (!function_exists('jeab_ordrar_person_matches')) {
        return null;
    }
    $hits = [];
    foreach ($newPeople as $j => $person) {
        if (isset($used[$j]) || !is_array($person)) {
            continue;
        }
        if (jeab_ordrar_person_matches($slot, $person)) {
            $hits[] = (int) $j;
        }
    }
    if ($hits === []) {
        return null;
    }
    if (count($hits) === 1) {
        return $hits[0];
    }
    $slotPnr = preg_replace('/\D+/', '', (string) ($slot['personnummer'] ?? '')) ?? '';
    if ($slotPnr !== '') {
        foreach ($hits as $j) {
            $pnr = preg_replace('/\D+/', '', (string) ($newPeople[$j]['personnummer'] ?? '')) ?? '';
            if ($pnr !== '' && $pnr === $slotPnr) {
                return $j;
            }
        }
    }
    $fold = function_exists('jeab_ordrar_name_fold') ? 'jeab_ordrar_name_fold' : null;
    $slotName = $fold !== null ? $fold((string) ($slot['name'] ?? '')) : strtolower(trim((string) ($slot['name'] ?? '')));
    foreach ($hits as $j) {
        $name = $fold !== null
            ? $fold((string) ($newPeople[$j]['name'] ?? ''))
            : strtolower(trim((string) ($newPeople[$j]['name'] ?? '')));
        if ($slotName !== '' && $slotName === $name) {
            return $j;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function jeab_ordrar_applicant_slots(array $row): array
{
    $people = function_exists('jeab_ordrar_people_list')
        ? jeab_ordrar_people_list($row['owners'] ?? [], 8)
        : [];
    if ($people === [] && function_exists('jeab_fastighet_stored_people')) {
        $people = jeab_fastighet_stored_people($row);
    }
    $med = function_exists('jeab_ordrar_people_list')
        ? jeab_ordrar_people_list($row['medsokande'] ?? [], 8)
        : [];
    foreach ($med as $person) {
        $seen = false;
        if (function_exists('jeab_ordrar_person_matches')) {
            foreach ($people as $have) {
                if (is_array($have) && jeab_ordrar_person_matches($have, $person)) {
                    $seen = true;
                    break;
                }
            }
        }
        if (!$seen) {
            $people[] = $person;
        }
    }
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    $huvudPnr = trim((string) ($row['personnummer'] ?? ''));
    $out = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $name = trim((string) ($person['name'] ?? ''));
        $pnr = trim((string) ($person['personnummer'] ?? ''));
        if ($name === '' && $pnr === '') {
            continue;
        }
        $dup = false;
        if (function_exists('jeab_ordrar_person_matches')) {
            foreach ($out as $have) {
                if (jeab_ordrar_person_matches($have, $person)) {
                    $dup = true;
                    break;
                }
            }
        }
        $isHuvud = false;
        if (function_exists('jeab_ordrar_person_matches') && ($huvud !== '' || $huvudPnr !== '')) {
            $isHuvud = jeab_ordrar_person_matches(['name' => $huvud, 'personnummer' => $huvudPnr], $person);
        }
        $out[] = [
            'slot' => count($out) + 1,
            'name' => $name,
            'has_pnr' => $pnr !== '',
            'huvud' => $isHuvud,
            'duplicate' => $dup,
        ];
    }

    return $out;
}

/**
 * ordrar.applicants_repair. dry=1 är standard.
 * Listar platser, huvudsökande och antal sökande. Kan sätta huvudsökande
 * och ta bort en dubblett på plats 3 utan att tappa personnumret.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ordrar_applicants_repair(JeabStore $store, array $input = []): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $dry = function_exists('jeab_ordrar_backfill_wants_dry')
        ? jeab_ordrar_backfill_wants_dry($input)
        : true;
    $action = 'ordrar.applicants_repair';
    $undoBatch = trim((string) ($input['undo'] ?? $input['batch_id'] ?? ''));
    if ($undoBatch !== '' && ($input['undo'] ?? '') !== '') {
        if ($dry) {
            return [
                'ok' => true,
                'action' => $action,
                'dry' => 1,
                'would_undo' => 1,
                'batch_id' => $undoBatch,
                'written' => 0,
                'status' => 200,
            ];
        }

        return jeab_ordrar_applicants_repair_undo($store, $undoBatch);
    }
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0) {
        return ['ok' => false, 'error' => 'ange order_id', 'action' => $action, 'dry' => $dry ? 1 : 0, 'status' => 422];
    }
    $row = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => $dry ? 1 : 0, 'status' => 404];
    }
    $slots = jeab_ordrar_applicant_slots($row);
    $count = count($slots);
    $huvud = trim((string) ($row['huvudsokande'] ?? ''));
    if ($huvud === '' && function_exists('jeab_ordrar_named_huvud')) {
        $huvud = jeab_ordrar_named_huvud($row);
    }
    if ($huvud === '' && function_exists('jeab_ordrar_name')) {
        $huvud = jeab_ordrar_name($row);
    }
    $base = [
        'ok' => true,
        'action' => $action,
        'dry' => $dry ? 1 : 0,
        'order_id' => $id,
        'huvudsokande' => $huvud,
        'applicant_count' => $count,
        'slots' => $slots,
        'written' => 0,
        'status' => 200,
    ];
    $wantSlot = (int) ($input['slot'] ?? 0);
    $wantName = trim((string) ($input['huvudsokande'] ?? ''));
    $remove = $input['remove_duplicate'] ?? null;
    $doRemove = $remove === true || $remove === 1 || $remove === '1';
    $doReset = $wantSlot > 0 || $wantName !== '';
    if ($dry || (!$doReset && !$doRemove)) {
        return jeab_fastighet_scrub_public($base);
    }
    $people = function_exists('jeab_ordrar_applicant_people')
        ? jeab_ordrar_applicant_people($row)
        : [];
    if ($doRemove) {
        $people = function_exists('jeab_ordrar_dedupe_people')
            ? jeab_ordrar_dedupe_people($people, 8)
            : $people;
    }
    $listed = $doRemove ? $people : (function_exists('jeab_ordrar_people_list') ? jeab_ordrar_people_list($row['owners'] ?? [], 8) : $people);
    if ($listed === [] && $people !== []) {
        $listed = $people;
    }
    $chosen = null;
    if ($wantSlot > 0) {
        $chosen = $listed[$wantSlot - 1] ?? null;
        if (!is_array($chosen)) {
            return ['ok' => false, 'error' => 'platsen saknas', 'action' => $action, 'order_id' => $id, 'dry' => 0, 'status' => 422];
        }
    } elseif ($wantName !== '') {
        foreach ($listed as $person) {
            if (!is_array($person)) {
                continue;
            }
            if (function_exists('jeab_ordrar_person_matches') && jeab_ordrar_person_matches(['name' => $wantName, 'personnummer' => ''], $person)) {
                $chosen = $person;
                break;
            }
        }
        if (!is_array($chosen)) {
            return ['ok' => false, 'error' => 'huvudsökande matchar ingen plats', 'action' => $action, 'order_id' => $id, 'dry' => 0, 'status' => 422];
        }
    }
    $next = $row;
    if ($doRemove) {
        $next['owners'] = $people;
        $beforeCount = count(jeab_ordrar_applicant_slots($row));
        $afterCount = count($people);
        $kalkyl = is_array($next['kalkyl'] ?? null) ? $next['kalkyl'] : [];
        if (array_key_exists('sokande', $kalkyl) && $kalkyl['sokande'] !== '' && $kalkyl['sokande'] !== null) {
            $stored = (int) round((float) $kalkyl['sokande']);
            if ($stored > $afterCount && $stored <= $beforeCount) {
                $kalkyl['sokande'] = $afterCount;
                $next['kalkyl'] = $kalkyl;
            }
        }
    }
    $chosenName = is_array($chosen) ? trim((string) ($chosen['name'] ?? '')) : trim((string) ($next['huvudsokande'] ?? ''));
    if (is_array($chosen) && function_exists('jeab_ordrar_apply_primary_name') && $chosenName !== '') {
        $next = jeab_ordrar_apply_primary_name($next, $chosenName);
        $next['huvudsokande_manual'] = true;
        $chosenPnr = trim((string) ($chosen['personnummer'] ?? ''));
        if ($chosenPnr !== '') {
            $next['personnummer'] = $chosenPnr;
        }
    }
    $ownerList = is_array($next['owners'] ?? null) ? $next['owners'] : $listed;
    if (function_exists('jeab_ordrar_other_owners')) {
        $next['medsokande'] = jeab_ordrar_other_owners($ownerList, $chosenName !== '' ? $chosenName : (string) ($next['huvudsokande'] ?? ''));
    }
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = ($contactId !== '' && function_exists('jeab_kontakt_get')) ? jeab_kontakt_get($contactId) : null;
    $orderSlice = jeab_fastighet_order_slice($row);
    $orderSlice['kalkyl'] = $row['kalkyl'] ?? null;
    $orderSlice['name'] = $row['name'] ?? null;
    $orderSlice['applicant'] = $row['applicant'] ?? null;
    $orderSlice['huvudsokande_manual'] = $row['huvudsokande_manual'] ?? null;
    $contactSlice = is_array($contact) ? jeab_fastighet_contact_slice($contact) : null;
    $batch = bin2hex(random_bytes(8));
    if (!jeab_fastighet_undo_add($batch, $id, $contactId, $orderSlice, $contactSlice)) {
        return [
            'ok' => false,
            'error' => 'Kunde inte spara ångringen.',
            'action' => $action,
            'order_id' => $id,
            'dry' => 0,
            'written' => 0,
            'status' => 503,
        ];
    }
    $patched = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($id, static function (array $fresh) use ($next): array {
            foreach (['owners', 'medsokande', 'huvudsokande', 'personnummer', 'kalkyl', 'name', 'applicant', 'huvudsokande_manual'] as $key) {
                if (array_key_exists($key, $next)) {
                    $fresh[$key] = $next[$key];
                }
            }
            $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

            return $fresh;
        })
        : null;
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'order_id' => $id, 'dry' => 0, 'batch_id' => $batch, 'status' => 404];
    }
    if ($chosenName !== '' && function_exists('jeab_ordrar_remember_huvud')) {
        jeab_ordrar_remember_huvud($contactId, $chosenName);
    }
    if ($doRemove && is_array($contact) && function_exists('jeab_ordrar_dedupe_people')) {
        $contactOwners = [];
        foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
            if (is_array($owner)) {
                $contactOwners[] = $owner;
            }
        }
        $cleaned = jeab_ordrar_dedupe_people($contactOwners, 8);
        if (count($cleaned) < count($contactOwners)) {
            jeab_fastighet_write_contact($contactId, [
                'owners' => $cleaned,
                'huvudsokande' => $chosenName,
            ]);
        }
    }
    jeab_fastighet_log('applicants_repair', $id, $batch, ['huvudsokande', 'owners', 'sokande']);
    $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : $patched;
    $fresh = is_array($fresh) ? $fresh : $patched;
    $afterSlots = jeab_ordrar_applicant_slots($fresh);
    $afterCount = count($afterSlots);
    $afterHuvud = $chosenName !== '' ? $chosenName : trim((string) ($fresh['huvudsokande'] ?? ''));
    if (function_exists('jeab_ordrar_named_huvud')) {
        $shown = jeab_ordrar_named_huvud($fresh);
        if ($shown !== '') {
            $afterHuvud = $shown;
        }
    }

    return jeab_fastighet_scrub_public([
        'ok' => true,
        'action' => $action,
        'dry' => 0,
        'order_id' => $id,
        'huvudsokande' => $afterHuvud,
        'applicant_count' => $afterCount,
        'slots' => $afterSlots,
        'written' => 1,
        'batch_id' => $batch,
        'status' => 200,
    ]);
}

/** @return array<string, mixed> */
function jeab_ordrar_applicants_repair_undo(JeabStore $store, string $batch): array
{
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    $action = 'ordrar.applicants_repair';
    $entry = jeab_fastighet_undo_get($batch);
    if (!is_array($entry)) {
        return ['ok' => false, 'error' => 'batch saknas', 'action' => $action, 'batch_id' => $batch, 'status' => 404];
    }
    if ((int) ($entry['undone'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'batchen är redan ångrad', 'action' => $action, 'batch_id' => $batch, 'status' => 409];
    }
    $orderId = (int) ($entry['order_id'] ?? 0);
    $orderSlice = is_array($entry['order'] ?? null) ? $entry['order'] : [];
    $current = function_exists('jeab_purchase_get') ? jeab_purchase_get($orderId) : null;
    if (!is_array($current)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'batch_id' => $batch, 'status' => 404];
    }
    $links = jeab_fastighet_link_snapshot($current);
    $patched = function_exists('jeab_purchase_patch')
        ? jeab_purchase_patch($orderId, static function (array $fresh) use ($orderSlice, $links): array {
            foreach ($orderSlice as $key => $value) {
                $fresh[(string) $key] = $value;
            }
            $fresh = jeab_fastighet_restore_links($fresh, $links);
            $fresh['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');

            return $fresh;
        })
        : null;
    if (!is_array($patched)) {
        return ['ok' => false, 'error' => 'order saknas', 'action' => $action, 'batch_id' => $batch, 'status' => 404];
    }
    $contactId = trim((string) ($entry['contact_id'] ?? ''));
    $contactSlice = is_array($entry['contact'] ?? null) ? $entry['contact'] : null;
    if ($contactId !== '' && is_array($contactSlice)) {
        jeab_fastighet_write_contact($contactId, $contactSlice);
    }
    jeab_fastighet_undo_mark($batch);
    jeab_fastighet_log('applicants_undo', $orderId, $batch, ['huvudsokande', 'owners', 'sokande']);
    $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($orderId) : $patched;
    $fresh = is_array($fresh) ? $fresh : $patched;

    return jeab_fastighet_scrub_public([
        'ok' => true,
        'action' => $action,
        'dry' => 0,
        'order_id' => $orderId,
        'batch_id' => $batch,
        'undone' => 1,
        'huvudsokande' => function_exists('jeab_ordrar_name') ? jeab_ordrar_name($fresh) : '',
        'applicant_count' => count(jeab_ordrar_applicant_slots($fresh)),
        'slots' => jeab_ordrar_applicant_slots($fresh),
        'written' => 1,
        'status' => 200,
    ]);
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_ordrar_applicants_repair_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.applicants_repair', 'status' => 401];
    }

    return jeab_ordrar_applicants_repair($store, $input);
}

function jeab_fastighet_refresh_button(int $orderId, string $class = 'ordrar-secondary'): string
{
    if ($orderId <= 0) {
        return '';
    }

    return '<button type="button" class="' . e($class) . ' fastighet-refresh-btn" data-fastighet-refresh="1" data-order-id="' . $orderId . '">Hämta fastighetsinfo igen</button>';
}

function jeab_fastighet_refresh_dialog(): string
{
    return '<div class="fastighet-refresh-bg" data-fastighet-dialog hidden>'
        . '<div class="fastighet-refresh-modal" role="dialog" aria-modal="true" aria-labelledby="fastighet-refresh-title">'
        . '<h3 id="fastighet-refresh-title">Fastighetsinfo</h3>'
        . '<div data-fastighet-body></div>'
        . '<p class="fastighet-refresh-err" data-fastighet-err hidden></p>'
        . '<div class="fastighet-refresh-row">'
        . '<button type="button" class="ordrar-secondary v3-btn is-sec" data-fastighet-cancel>Avbryt</button>'
        . '<button type="button" class="ordrar-primary v3-btn is-primary" data-fastighet-save>Spara</button>'
        . '</div></div></div>';
}
