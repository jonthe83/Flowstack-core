<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * kontakter.set_place fyller gata, postnummer och ort på en kontakt.
 * Samma normalisering och SQL-skrivning som formuläret i Kontakter.
 * Tomma fält skrivs. Ett ifyllt fält skrivs bara med force=1.
 * Torrkörning är standard. dry=0 kräver confirm=SAM.
 * Ångring sparas och tas tillbaka med undo=1 och batch.
 * Inget mejl, inget SMS och ingen hopslagning.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_set_place_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return jeab_kontakter_set_place_result([
            'ok' => false,
            'error' => 'Fel token.',
            'status' => 401,
        ]);
    }
    try {
        $result = jeab_kontakter_set_place($input);
    } catch (InvalidArgumentException $e) {
        return jeab_kontakter_set_place_result([
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 422,
            'dry' => function_exists('jeab_kontakt_dry') && !jeab_kontakt_dry($input) ? 0 : 1,
        ]);
    }
    if (!isset($result['status'])) {
        $result['status'] = !empty($result['ok']) ? 200 : 400;
    }

    return jeab_kontakter_set_place_result($result);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_set_place(array $input): array
{
    $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
    $undo = array_key_exists('undo', $input) && jeab_kontakter_set_place_flag($input['undo']);
    if (!function_exists('jeab_kontakter_use_sql') || !jeab_kontakter_use_sql()) {
        return [
            'ok' => false,
            'error' => 'Kontakterna ligger inte i SQL.',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
        ];
    }
    if ($undo) {
        return jeab_kontakter_set_place_undo($input, $dry);
    }

    $id = trim((string) ($input['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('Skriv id.');
    }
    $wanted = jeab_kontakter_set_place_wanted($input);
    $row = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($id) : null;
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Kontakten finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'written' => 0,
            'id' => $id,
        ];
    }
    $row['id'] = $id;
    $applied = jeab_kontakter_set_place_apply($row, $wanted, jeab_kontakter_set_place_flag($input['force'] ?? null));
    $before = jeab_kontakter_set_place_view($row);
    $after = jeab_kontakter_set_place_view($applied['row']);
    $base = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'id' => $id,
        'batch' => '',
        'force' => jeab_kontakter_set_place_flag($input['force'] ?? null) ? 1 : 0,
        'before' => $before,
        'after' => $after,
        'changed' => $applied['changed'],
        'skipped' => $applied['skipped'],
    ];
    if ($dry) {
        return $base;
    }
    if ((string) ($input['confirm'] ?? '') !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'status' => 422,
            'dry' => 0,
            'written' => 0,
            'id' => $id,
            'batch' => '',
            'force' => $base['force'],
            'before' => $before,
            'after' => $after,
            'changed' => $applied['changed'],
            'skipped' => $applied['skipped'],
        ];
    }
    if ($applied['changed'] === []) {
        return $base;
    }
    if (!function_exists('jeab_contacts_sole_undo_pdo') || !jeab_contacts_sole_undo_pdo() instanceof PDO) {
        return [
            'ok' => false,
            'error' => 'Ångringshjälpen saknas.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'id' => $id,
            'before' => $before,
            'after' => $after,
        ];
    }
    $batch = function_exists('jeab_contacts_sole_batch') ? jeab_contacts_sole_batch() : bin2hex(random_bytes(8));
    $report = null;
    try {
        jeab_kontakter_mutate(static function (array &$contacts) use ($id, $wanted, $input, $batch, &$report): array {
            if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
                throw new RuntimeException('Kontakten finns inte.');
            }
            $ids = array_keys($contacts);
            sort($ids, SORT_STRING);
            $live = $contacts[$id];
            $live['id'] = $id;
            $applied = jeab_kontakter_set_place_apply($live, $wanted, jeab_kontakter_set_place_flag($input['force'] ?? null));
            if ($applied['changed'] === []) {
                $report = ['changed' => [], 'skipped' => $applied['skipped'], 'row' => $live];

                return $live;
            }
            $next = $applied['row'];
            $next['id'] = $id;
            $logged = function_exists('jeab_contacts_sole_undo_add') && jeab_contacts_sole_undo_add(
                $batch,
                1,
                'set_place',
                'contact',
                $id,
                jeab_kontakter_set_place_stored($live),
                jeab_kontakter_set_place_stored($next)
            );
            if (!$logged) {
                throw new RuntimeException('Kunde inte spara ångringen.');
            }
            $contacts[$id] = $next;
            $afterIds = array_keys($contacts);
            sort($afterIds, SORT_STRING);
            if ($afterIds !== $ids) {
                throw new RuntimeException('Kontakter slogs ihop.');
            }
            $report = ['changed' => $applied['changed'], 'skipped' => $applied['skipped'], 'row' => $next];

            return $next;
        });
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Kontakten kunde inte skrivas.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'id' => $id,
            'batch' => '',
            'before' => $before,
            'after' => $after,
        ];
    }
    $saved = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($id) : null;
    if (!is_array($saved) || !is_array($report) || ($report['changed'] ?? []) === []) {
        return [
            'ok' => false,
            'error' => 'Kontakten kunde inte skrivas.',
            'status' => 409,
            'dry' => 0,
            'written' => 0,
            'id' => $id,
            'batch' => '',
            'before' => $before,
            'after' => $before,
        ];
    }
    $after = jeab_kontakter_set_place_view($saved);
    jeab_kontakter_set_place_log($id, $batch, 'skrev ' . implode(',', $report['changed']));

    return [
        'ok' => true,
        'dry' => 0,
        'written' => 1,
        'id' => $id,
        'batch' => $batch,
        'force' => $base['force'],
        'before' => $before,
        'after' => $after,
        'changed' => $report['changed'],
        'skipped' => $report['skipped'],
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_set_place_undo(array $input, bool $dry): array
{
    $batch = trim((string) ($input['batch'] ?? ''));
    if ($batch === '') {
        throw new InvalidArgumentException('batch saknas.');
    }
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        return [
            'ok' => false,
            'error' => 'dry=0 kräver confirm=SAM',
            'status' => 422,
            'dry' => 0,
            'undo' => 1,
            'written' => 0,
            'batch' => $batch,
        ];
    }
    if (!function_exists('jeab_contacts_sole_undo_all')) {
        return [
            'ok' => false,
            'error' => 'Ingen ångra-lista.',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'undo' => 1,
            'written' => 0,
            'batch' => $batch,
        ];
    }
    $rows = [];
    foreach (jeab_contacts_sole_undo_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['batch_id'] ?? '') !== $batch || (string) ($row['kind'] ?? '') !== 'set_place' || (int) ($row['undone'] ?? 0) === 1) {
            continue;
        }
        $rows[] = $row;
    }
    if ($rows === []) {
        return [
            'ok' => false,
            'error' => 'Ingen körning att ångra.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'undo' => 1,
            'written' => 0,
            'batch' => $batch,
        ];
    }
    $plans = [];
    foreach ($rows as $row) {
        $cid = trim((string) ($row['entity_id'] ?? ''));
        $storedBefore = json_decode((string) ($row['before_json'] ?? ''), true);
        $storedAfter = json_decode((string) ($row['after_json'] ?? ''), true);
        if ($cid === '' || !is_array($storedBefore) || !is_array($storedAfter)) {
            continue;
        }
        $live = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($cid) : null;
        if (!is_array($live)) {
            continue;
        }
        $live['id'] = $cid;
        $restored = jeab_kontakter_set_place_restore($live, $storedBefore, $storedAfter);
        $plans[] = [
            'id' => $cid,
            'before' => jeab_kontakter_set_place_view($live),
            'after' => jeab_kontakter_set_place_view($restored),
            'stored_before' => $storedBefore,
            'stored_after' => $storedAfter,
        ];
    }
    if ($plans === []) {
        return [
            'ok' => false,
            'error' => 'Ingen kontakt att ångra.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'undo' => 1,
            'written' => 0,
            'batch' => $batch,
        ];
    }
    $focus = trim((string) ($input['id'] ?? ''));
    $shown = $plans[0];
    if ($focus !== '') {
        foreach ($plans as $plan) {
            if ($plan['id'] === $focus) {
                $shown = $plan;
                break;
            }
        }
    }
    $contacts = [];
    foreach ($plans as $plan) {
        $contacts[] = [
            'id' => $plan['id'],
            'before' => $plan['before'],
            'after' => $plan['after'],
        ];
    }
    $preview = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'undo' => 1,
        'written' => 0,
        'id' => (string) $shown['id'],
        'batch' => $batch,
        'before' => $shown['before'],
        'after' => $shown['after'],
        'contacts' => $contacts,
    ];
    if ($dry) {
        return $preview;
    }
    $restored = 0;
    try {
        jeab_kontakter_mutate(static function (array &$live) use ($plans, $batch, &$restored): int {
            $ids = array_keys($live);
            sort($ids, SORT_STRING);
            foreach ($plans as $plan) {
                $cid = (string) $plan['id'];
                if (!isset($live[$cid]) || !is_array($live[$cid])) {
                    continue;
                }
                $live[$cid] = jeab_kontakter_set_place_restore($live[$cid], $plan['stored_before'], $plan['stored_after']);
                $live[$cid]['id'] = $cid;
                $restored++;
            }
            $afterIds = array_keys($live);
            sort($afterIds, SORT_STRING);
            if ($afterIds !== $ids) {
                throw new RuntimeException('Kontakter slogs ihop.');
            }
            if ($restored < 1) {
                throw new RuntimeException('Ingen kontakt att ångra.');
            }
            $pdo = jeab_contacts_sole_undo_pdo();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Ångringshjälpen saknas.');
            }
            $pdo->prepare('UPDATE contacts_sole_undo SET undone = 1 WHERE batch_id = ? AND kind = ?')->execute([$batch, 'set_place']);

            return $restored;
        });
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Kontakten kunde inte skrivas.',
            'status' => 409,
            'dry' => 0,
            'undo' => 1,
            'written' => 0,
            'batch' => $batch,
            'before' => $shown['before'],
            'after' => $shown['after'],
        ];
    }
    $fresh = [];
    foreach ($plans as $plan) {
        $cid = (string) $plan['id'];
        $row = function_exists('jeab_kontakt_fetch') ? jeab_kontakt_fetch($cid) : null;
        $view = is_array($row) ? jeab_kontakter_set_place_view($row) : $plan['after'];
        $fresh[] = ['id' => $cid, 'before' => $plan['before'], 'after' => $view];
        if ($cid === (string) $shown['id']) {
            $shown['after'] = $view;
        }
        jeab_kontakter_set_place_log($cid, $batch, 'ångrade');
    }

    return [
        'ok' => true,
        'dry' => 0,
        'undo' => 1,
        'written' => 1,
        'id' => (string) $shown['id'],
        'batch' => $batch,
        'before' => $shown['before'],
        'after' => $shown['after'],
        'contacts' => $fresh,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array{postnummer:string,ort:string,gata:?string}
 */
function jeab_kontakter_set_place_wanted(array $input): array
{
    if (!array_key_exists('postnummer', $input)) {
        throw new InvalidArgumentException('Skriv postnummer.');
    }
    if (!array_key_exists('ort', $input)) {
        throw new InvalidArgumentException('Skriv ort.');
    }
    $postalRaw = trim((string) $input['postnummer']);
    $postal = jeab_kontakt_postal($postalRaw);
    if ($postalRaw === '' || $postal === '') {
        throw new InvalidArgumentException($postalRaw !== '' ? 'Postnumret ska vara fem siffror.' : 'Skriv postnummer.');
    }
    $cityRaw = trim((string) $input['ort']);
    if ($cityRaw === '') {
        throw new InvalidArgumentException('Skriv ort.');
    }
    $city = jeab_kontakt_city_name($cityRaw);
    if ($city === '') {
        throw new InvalidArgumentException('Skriv ort.');
    }
    $gata = null;
    if (array_key_exists('gata', $input)) {
        $gata = trim((string) $input['gata']);
    }

    return ['postnummer' => $postal, 'ort' => $city, 'gata' => $gata];
}

/**
 * @param array<string, mixed> $row
 * @param array{postnummer:string,ort:string,gata:?string} $wanted
 * @return array{row:array<string,mixed>,changed:list<string>,skipped:list<string>}
 */
function jeab_kontakter_set_place_apply(array $row, array $wanted, bool $force): array
{
    $next = $row;
    $changed = [];
    $skipped = [];
    $map = [
        'postnummer' => 'postal_code',
        'ort' => 'city',
    ];
    if ($wanted['gata'] !== null && $wanted['gata'] !== '') {
        $map['gata'] = 'street';
    }
    foreach ($map as $label => $column) {
        $incoming = $label === 'gata' ? (string) $wanted['gata'] : (string) $wanted[$label];
        $current = trim((string) ($row[$column] ?? ''));
        if ($current !== '' && !$force) {
            if ($current !== $incoming) {
                $skipped[] = $label;
            }
            continue;
        }
        if ($current === $incoming) {
            continue;
        }
        $next[$column] = $incoming;
        $changed[] = $label;
    }
    if (in_array('gata', $changed, true)) {
        $next['street_key'] = jeab_kontakt_street_key((string) $next['street']);
    }
    if ($changed !== []) {
        $next['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    }
    $next['id'] = (string) ($row['id'] ?? '');

    return ['row' => $next, 'changed' => $changed, 'skipped' => $skipped];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $storedBefore
 * @param array<string, mixed> $storedAfter
 * @return array<string, mixed>
 */
function jeab_kontakter_set_place_restore(array $row, array $storedBefore, array $storedAfter): array
{
    foreach (['street', 'street_key', 'postal_code', 'city', 'updated_at'] as $field) {
        if (!array_key_exists($field, $storedBefore) || !array_key_exists($field, $storedAfter)) {
            continue;
        }
        if ((string) $storedBefore[$field] === (string) $storedAfter[$field]) {
            continue;
        }
        $row[$field] = (string) $storedBefore[$field];
    }
    $row['id'] = (string) ($row['id'] ?? '');

    return $row;
}

/** @param array<string, mixed> $row @return array{gata:string,postnummer:string,ort:string} */
function jeab_kontakter_set_place_view(array $row): array
{
    return [
        'gata' => trim((string) ($row['street'] ?? '')),
        'postnummer' => trim((string) ($row['postal_code'] ?? '')),
        'ort' => trim((string) ($row['city'] ?? '')),
    ];
}

/** @param array<string, mixed> $row @return array{street:string,street_key:string,postal_code:string,city:string,updated_at:string} */
function jeab_kontakter_set_place_stored(array $row): array
{
    return [
        'street' => (string) ($row['street'] ?? ''),
        'street_key' => (string) ($row['street_key'] ?? ''),
        'postal_code' => (string) ($row['postal_code'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function jeab_kontakter_set_place_flag(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1';
}

function jeab_kontakter_set_place_log(string $id, string $batch, string $what): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $id = trim($id);
    $batch = trim($batch);
    $what = trim($what);
    $stamp = function_exists('jeab_now') ? (string) jeab_now() : date('c');
    jeab_log_app_error(
        'kontakter',
        'set_place ' . $id . ' batch ' . $batch . ' ' . $what,
        [
            'level' => 'info',
            'no_webhook' => true,
            'skip_request' => true,
            'dedupe_seconds' => 0,
            'fingerprint' => hash('sha256', 'set_place|' . $id . '|' . $batch . '|' . $what . '|' . $stamp . '|' . bin2hex(random_bytes(4))),
        ]
    );
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function jeab_kontakter_set_place_result(array $result): array
{
    $result['action'] = 'kontakter.set_place';
    $result['sent'] = 0;
    $result['mail'] = 0;
    $result['sms'] = 0;
    $result['merged'] = 0;

    return $result;
}
