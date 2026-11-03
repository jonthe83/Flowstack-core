<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';

/*
 * Inkorgens kontaktrader (inkorg_contacts) pekar på registret (contacts) via contact_id.
 *
 * Läsvägen: jeab_inkorg_contact_linked_view() visar kontaktens namn, mobil, adress och ort
 * när raden har contact_id och kontakten finns. Tomma fält i kontakten faller tillbaka till
 * radens egen kopia. E-post är unionen. Ingen DDL, SHOW eller PRAGMA här.
 *
 * Backfill: hook-åtgärden data.inkorg_contact_link. dry=1 är standard, write=1 skriver.
 * Matchning i ordning: contact_id på en färsk påminnelse med samma e-post/mobil,
 * sedan contacts.owner_email/contact_emails, sedan contacts.owner_phone. Bara entydiga träffar.
 * En befintlig länk skrivs aldrig över. Bara row_json.contact_id och kolumnen contact_id ändras,
 * inte updated_at. Inget raderas. Saknas kontakt och raden är aktiv skapas den i contacts.
 * Före-bilden av de rader som ändras sparas i data/backups innan något skrivs.
 */

/** @param array<string, mixed> $row @return list<string> */
function jeab_inkorg_contact_link_row_emails(array $row): array
{
    $out = [];
    foreach (array_merge((array) ($row['emails'] ?? []), [(string) ($row['email'] ?? '')]) as $one) {
        $norm = jeab_norm_email((string) $one);
        if ($norm !== '' && str_contains($norm, '@') && !in_array($norm, $out, true)) {
            $out[] = $norm;
        }
    }

    return $out;
}

/** Bara mobilnummer som ser riktiga ut används som nyckel. */
function jeab_inkorg_contact_link_phone(string $raw): string
{
    $norm = jeab_norm_phone($raw);

    return preg_match('/^\+46\d{7,10}$/', $norm) === 1 ? $norm : '';
}

/**
 * Kontaktuppgifter via contact_id, med radens kopia som reserv.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_linked_view(array $row): array
{
    $cid = trim((string) ($row['contact_id'] ?? ''));
    $own = trim((string) ($row['id'] ?? ''));
    if ($cid === '' || $cid === $own || !function_exists('jeab_kontakt_get') || !function_exists('jeab_inkorg_contact_project')) {
        return $row;
    }
    static $memo = [];
    if (!array_key_exists($cid, $memo)) {
        try {
            $crm = jeab_kontakt_get($cid);
        } catch (Throwable) {
            $crm = null;
        }
        $memo[$cid] = is_array($crm) ? jeab_inkorg_contact_project($cid, $crm) : null;
        if (count($memo) > 500) {
            $memo = array_slice($memo, -250, null, true);
        }
    }
    $linked = $memo[$cid];
    if (!is_array($linked)) {
        return $row;
    }
    foreach (['name', 'mobile', 'address', 'town'] as $key) {
        $value = trim((string) ($linked[$key] ?? ''));
        if ($value !== '') {
            $row[$key] = $value;
        }
    }
    $emails = [];
    foreach (array_merge((array) ($linked['emails'] ?? []), (array) ($row['emails'] ?? [])) as $one) {
        $one = trim((string) $one);
        if ($one !== '' && !in_array(jeab_norm_email($one), array_map('jeab_norm_email', $emails), true)) {
            $emails[] = $one;
        }
    }
    $row['emails'] = $emails;
    $row['linked'] = true;

    return $row;
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_inkorg_contact_link_dispatch(array $input): array
{
    if (!function_exists('jeab_cutover_all_authorized') || !jeab_cutover_all_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'written' => false];
    }
    $pdo = function_exists('jeab_contact_store_pdo') ? jeab_contact_store_pdo() : null;
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'SQL saknas.', 'status' => 400, 'written' => false];
    }
    try {
        $result = jeab_inkorg_contact_link_run($pdo, $input);
    } catch (Throwable $e) {
        error_log('inkorg_contact_link: ' . $e->getMessage());
        $result = ['ok' => false, 'error' => 'Körningen misslyckades.', 'written' => false];
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;

    return $result;
}

function jeab_inkorg_contact_link_has_column(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT contact_id FROM inkorg_contacts WHERE 1 = 0');

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** DDL körs bara här, i skrivläge. */
function jeab_inkorg_contact_link_ensure_column(PDO $pdo): void
{
    if (jeab_inkorg_contact_link_has_column($pdo)) {
        return;
    }
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $pdo->exec($mysql
        ? 'ALTER TABLE inkorg_contacts ADD COLUMN contact_id VARCHAR(32) NOT NULL DEFAULT \'\''
        : 'ALTER TABLE inkorg_contacts ADD COLUMN contact_id TEXT NOT NULL DEFAULT \'\'');
    $pdo->exec($mysql
        ? 'CREATE INDEX inkorg_contacts_contact_id ON inkorg_contacts (contact_id)'
        : 'CREATE INDEX IF NOT EXISTS inkorg_contacts_contact_id ON inkorg_contacts (contact_id)');
}

function jeab_inkorg_contact_link_ts(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    $ts = strtotime($raw);

    return $ts === false ? 0 : $ts;
}

/**
 * @param array<string, mixed> $input write, days (90), reminder_days (30), create (1)
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_link_run(PDO $pdo, array $input): array
{
    $write = function_exists('jeab_cutover_wants_write')
        ? jeab_cutover_wants_write($input)
        : ((string) ($input['write'] ?? '') === '1');
    $days = max(1, min(3650, (int) ($input['days'] ?? 90)));
    $remDays = max(1, min($days, (int) ($input['reminder_days'] ?? 30)));
    $create = !array_key_exists('create', $input) || in_array($input['create'], [1, '1', true], true);
    $now = (int) ($input['_now'] ?? time());
    $cutActive = $now - $days * 86400;
    $cutRem = $now - $remDays * 86400;

    $out = [
        'ok' => true, 'action' => 'data.inkorg_contact_link', 'dry' => !$write, 'written' => false,
        'days' => $days, 'reminder_days' => $remDays,
        'total' => 0, 'active' => 0, 'active_reminder' => 0, 'already' => 0, 'broken_link' => 0,
        'matched' => 0, 'by_reminder' => 0, 'by_email' => 0, 'by_phone' => 0,
        'ambiguous' => 0, 'unmatched_active' => 0, 'unmatched_inactive' => 0,
        'created' => 0, 'would_create' => 0, 'create_failed' => 0, 'linked' => 0,
        'backup' => '', 'column' => jeab_inkorg_contact_link_has_column($pdo),
        'sample' => ['ambiguous' => [], 'unmatched_active' => []],
    ];

    $contactIds = [];
    $byEmail = [];
    $byPhone = [];
    foreach ($pdo->query('SELECT id, owner_email, owner_phone FROM contacts')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $id = (string) ($c['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $contactIds[$id] = true;
        $e = jeab_norm_email((string) ($c['owner_email'] ?? ''));
        if ($e !== '') {
            $byEmail[$e][$id] = true;
        }
        $p = jeab_inkorg_contact_link_phone((string) ($c['owner_phone'] ?? ''));
        if ($p !== '') {
            $byPhone[$p][$id] = true;
        }
    }
    try {
        foreach ($pdo->query('SELECT email, contact_id FROM contact_emails')->fetchAll(PDO::FETCH_ASSOC) as $ce) {
            $e = jeab_norm_email((string) ($ce['email'] ?? ''));
            $id = (string) ($ce['contact_id'] ?? '');
            if ($e !== '' && isset($contactIds[$id])) {
                $byEmail[$e][$id] = true;
            }
        }
    } catch (Throwable) {
    }
    if (function_exists('jeab_contact_secondary_index')) {
        jeab_contact_secondary_index($pdo, $byEmail, $contactIds);
    }

    // Färska påminnelser: aktivitet och, när de redan har contact_id, den starkaste nyckeln.
    $remKey = [];
    try {
        // Nyckeln läses från kontakten. Kopian används bara för rader utan kontakt, och bara medan kolumnen finns.
        $remEmail = jeab_copy_column_present($pdo, 'reminders', 'email') ? "COALESCE(NULLIF(c.owner_email, ''), r.email)" : 'c.owner_email';
        $remPhone = jeab_copy_column_present($pdo, 'reminders', 'phone') ? "COALESCE(NULLIF(c.owner_phone, ''), r.phone)" : 'c.owner_phone';
        $stmt = $pdo->prepare('SELECT ' . $remEmail . ' AS email, ' . $remPhone . ' AS phone, r.contact_id, r.created_at, r.updated_at, r.due_at FROM reminders r LEFT JOIN contacts c ON c.id = r.contact_id WHERE r.updated_at >= ? OR r.created_at >= ? OR r.due_at >= ?');
        $since = date('Y-m-d H:i:s', $cutActive);
        $stmt->execute([$since, $since, $since]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ts = max(
                jeab_inkorg_contact_link_ts((string) ($r['updated_at'] ?? '')),
                jeab_inkorg_contact_link_ts((string) ($r['created_at'] ?? '')),
                min($now, jeab_inkorg_contact_link_ts((string) ($r['due_at'] ?? '')))
            );
            $cid = trim((string) ($r['contact_id'] ?? ''));
            $keys = [];
            $e = jeab_norm_email((string) ($r['email'] ?? ''));
            if ($e !== '') {
                $keys[] = 'e:' . $e;
            }
            $p = jeab_inkorg_contact_link_phone((string) ($r['phone'] ?? ''));
            if ($p !== '') {
                $keys[] = 'p:' . $p;
            }
            foreach ($keys as $k) {
                $remKey[$k]['ts'] = max((int) ($remKey[$k]['ts'] ?? 0), $ts);
                if ($cid !== '' && isset($contactIds[$cid])) {
                    $remKey[$k]['ids'][$cid] = true;
                }
            }
        }
    } catch (Throwable) {
    }

    $rows = $pdo->query('SELECT id, row_json, updated_at FROM inkorg_contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $plan = [];
    $toCreate = [];
    foreach ($rows as $db) {
        $id = (string) ($db['id'] ?? '');
        $row = json_decode((string) ($db['row_json'] ?? ''), true);
        if ($id === '' || !is_array($row)) {
            continue;
        }
        $out['total']++;
        $emails = jeab_inkorg_contact_link_row_emails($row);
        $phone = jeab_inkorg_contact_link_phone((string) ($row['mobile'] ?? ''));
        $keys = array_merge(array_map(static fn(string $e): string => 'e:' . $e, $emails), $phone !== '' ? ['p:' . $phone] : []);
        $seen = max(
            jeab_inkorg_contact_link_ts((string) ($db['updated_at'] ?? '')),
            jeab_inkorg_contact_link_ts((string) ($row['updated_at'] ?? '')),
            jeab_inkorg_contact_link_ts((string) ($row['created_at'] ?? '')),
            jeab_inkorg_contact_link_ts((string) ($row['last_seen_at'] ?? ''))
        );
        $remIds = [];
        foreach ($keys as $k) {
            if (isset($remKey[$k])) {
                $seen = max($seen, (int) $remKey[$k]['ts']);
                $remIds += (array) ($remKey[$k]['ids'] ?? []);
            }
        }
        $active = $seen >= $cutActive && empty($row['deleted_at']);
        if ($active) {
            $out['active']++;
            if ($seen >= $cutRem) {
                $out['active_reminder']++;
            }
        }
        $existing = trim((string) ($row['contact_id'] ?? ''));
        if ($existing !== '') {
            $out[isset($contactIds[$existing]) ? 'already' : 'broken_link']++;
            continue;
        }
        $emailIds = [];
        foreach ($emails as $e) {
            $emailIds += (array) ($byEmail[$e] ?? []);
        }
        $phoneIds = $phone !== '' ? (array) ($byPhone[$phone] ?? []) : [];
        $pick = '';
        $how = '';
        $ambiguous = false;
        foreach (['by_reminder' => $remIds, 'by_email' => $emailIds, 'by_phone' => $phoneIds] as $kind => $set) {
            if (count($set) === 1) {
                $pick = (string) array_key_first($set);
                $how = $kind;
                break;
            }
            if (count($set) > 1) {
                $ambiguous = true;
                break;
            }
        }
        if ($pick !== '') {
            $out['matched']++;
            $out[$how]++;
            $plan[$id] = $pick;
            continue;
        }
        if ($ambiguous) {
            $out['ambiguous']++;
            if (count($out['sample']['ambiguous']) < 20) {
                $out['sample']['ambiguous'][] = $id;
            }
            continue;
        }
        if (!$active) {
            $out['unmatched_inactive']++;
            continue;
        }
        $out['unmatched_active']++;
        if (count($out['sample']['unmatched_active']) < 20) {
            $out['sample']['unmatched_active'][] = $id;
        }
        $blocked = $emails !== [] && function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($emails[0]);
        $test = !empty($row['test']);
        $empty = trim((string) ($row['name'] ?? '')) === '' && $emails === [] && $phone === '';
        if ($create && !$blocked && !$test && !$empty) {
            $toCreate[$id] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'email' => $emails[0] ?? '',
                'phone' => $phone,
                'address' => trim((string) ($row['address'] ?? '')),
                'city' => trim((string) ($row['town'] ?? '')),
                'source' => 'inkorg',
            ];
        }
    }
    $out['would_create'] = count($toCreate);
    $left = jeab_inkorg_contact_link_json_left($rows);
    $out['json_count'] = $left['json_count'];
    $out['sql_count'] = count($rows);
    $out['json_only'] = $left['json_only'];
    $out['sample']['json_only'] = $left['sample'];
    $out['sole_source'] = function_exists('jeab_kontakter_sql_is_sole_source') && jeab_kontakter_sql_is_sole_source();
    // Kvar: rader utan länk som går att länka, och JSON-rader som inte finns i SQL (läsreserven).
    $out['left'] = count($plan) + count($toCreate) + $left['json_only'];
    if (!$write) {
        $out['would_link'] = count($plan) + count($toCreate);

        return $out;
    }

    // Före-bild innan något skrivs.
    if (function_exists('jeab_cutover_ensure_tree_zip')) {
        $zip = jeab_cutover_ensure_tree_zip(true);
        $out['backup_zip'] = !empty($zip['ok']) ? basename((string) ($zip['path'] ?? '')) : '';
    }
    $touch = array_keys($plan + $toCreate);
    $before = [];
    foreach ($rows as $db) {
        if (in_array((string) ($db['id'] ?? ''), $touch, true)) {
            $before[(string) $db['id']] = (string) ($db['row_json'] ?? '');
        }
    }
    $dir = rtrim(jeab_data_dir(), '/') . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/inkorg_contact_link-' . date('Ymd-His', $now) . '.json';
    $json = json_encode(['rows' => $before, 'plan' => $plan], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($file, $json, LOCK_EX) === false) {
        $out['ok'] = false;
        $out['error'] = 'Backupen gick inte att skriva. Inget ändrat.';

        return $out;
    }
    $out['backup'] = basename($file);

    if ($toCreate !== [] && function_exists('jeab_kontakt_find_or_create')) {
        foreach ($toCreate as $inboxId => $hint) {
            try {
                $made = jeab_kontakt_find_or_create($hint, false, false, true);
            } catch (Throwable) {
                $made = null;
            }
            $cid = is_array($made) ? trim((string) ($made['id'] ?? '')) : '';
            if ($cid === '') {
                $out['create_failed']++;
                if (function_exists('jeab_log_app_error')) {
                    jeab_log_app_error('inkorg_contact_create', 'skapades inte', ['ref' => (string) $inboxId, 'skip_request' => true]);
                }
                continue;
            }
            $out['created']++;
            $plan[$inboxId] = $cid;
        }
    }

    jeab_inkorg_contact_link_ensure_column($pdo);
    $out['column'] = true;
    $sel = $pdo->prepare('SELECT row_json FROM inkorg_contacts WHERE id = ?');
    $upd = $pdo->prepare('UPDATE inkorg_contacts SET row_json = ?, contact_id = ? WHERE id = ?');
    foreach ($plan as $inboxId => $cid) {
        $pdo->beginTransaction();
        try {
            $sel->execute([$inboxId]);
            $raw = $sel->fetchColumn();
            $row = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($row) || trim((string) ($row['contact_id'] ?? '')) !== '') {
                $pdo->commit();
                continue;
            }
            $row['contact_id'] = $cid;
            $enc = json_encode($row, JSON_UNESCAPED_UNICODE);
            if (!is_string($enc)) {
                $pdo->commit();
                continue;
            }
            $upd->execute([$enc, $cid, $inboxId]);
            $pdo->commit();
            $out['linked']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    $out['written'] = $out['linked'] > 0 || $out['created'] > 0;

    return $out;
}

/**
 * JSON-rader (inkorg/contacts.json) som saknas i SQL. Bara läsning, inga värden ut.
 *
 * @param list<array<string, mixed>> $sqlRows
 * @return array{json_count:int,json_only:int,sample:list<string>}
 */
function jeab_inkorg_contact_link_json_left(array $sqlRows): array
{
    $map = function_exists('jeab_inkorg_contacts_json_map') ? jeab_inkorg_contacts_json_map() : [];
    $have = [];
    foreach ($sqlRows as $db) {
        $have[(string) ($db['id'] ?? '')] = true;
    }
    $only = 0;
    $sample = [];
    foreach ($map as $id => $_row) {
        if (isset($have[(string) $id])) {
            continue;
        }
        $only++;
        if (count($sample) < 20) {
            $sample[] = (string) $id;
        }
    }

    return ['json_count' => count($map), 'json_only' => $only, 'sample' => $sample];
}

/**
 * Samma contact_id på tråd och listrad som påminnelsen redan har.
 * En ifylld annan contact_id lämnas. Kontakter skapas inte och slås inte ihop.
 */
function jeab_inkorg_lead_contact_safe(string $id): bool
{
    $id = trim($id);
    if ($id === '' || strlen($id) > 64 || str_contains($id, '@') || preg_match('/\s/u', $id) === 1) {
        return false;
    }
    if (preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $id) !== 1) {
        return false;
    }
    if (function_exists('jeab_norm_pnr') && jeab_norm_pnr($id) !== '') {
        return false;
    }

    return preg_match('/\A\d{6}-?\d{4}\z/', $id) !== 1 && preg_match('/\A\d{8}-?\d{4}\z/', $id) !== 1;
}

function jeab_inkorg_lead_contact_from_reminder(string $tid, int $reminderId): bool
{
    $tid = trim($tid);
    if ($tid === '' || $reminderId < 1 || !function_exists('jeab_store')) {
        return false;
    }
    $store = jeab_store();
    if (!$store instanceof JeabStore) {
        return false;
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $reminderId) : $store->getReminder($reminderId);
    if (!is_array($row)) {
        return false;
    }
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    if (!jeab_inkorg_lead_contact_safe($contactId)) {
        return false;
    }

    return jeab_inkorg_lead_contact_apply($tid, $contactId);
}

/**
 * Skriver contact_id på tråden när den är tom, och samma id på listraden när den är tom.
 */
function jeab_inkorg_lead_contact_apply(string $tid, string $contactId): bool
{
    $tid = trim($tid);
    $contactId = trim($contactId);
    if ($tid === '' || !jeab_inkorg_lead_contact_safe($contactId) || !function_exists('jeab_inkorg_thread_write')) {
        return false;
    }
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    $have = trim((string) ($meta['contact_id'] ?? ''));
    if ($have !== '' && $have !== $contactId) {
        return false;
    }
    if ($have === '') {
        jeab_inkorg_thread_write($tid, static function (array $t) use ($contactId): array {
            $cur = trim((string) ($t['contact_id'] ?? ''));
            if ($cur === '' ) {
                $t['contact_id'] = $contactId;
                $t['updated_at'] = date('c');
            }

            return $t;
        });
    }
    jeab_inkorg_lead_contact_list($tid, $contactId);

    return true;
}

/** Listradens contact_id, bara när den är tom. */
function jeab_inkorg_lead_contact_list(string $tid, string $contactId): void
{
    $tid = trim($tid);
    $contactId = trim($contactId);
    if ($tid === '' || !jeab_inkorg_lead_contact_safe($contactId) || !function_exists('jeab_inkorg_pdo_store')) {
        return;
    }
    $store = jeab_inkorg_pdo_store();
    if (!$store instanceof JeabPdoInkorgStore) {
        return;
    }
    $rows = $store->listRowsByIds([$tid]);
    $row = $rows[0] ?? null;
    if (!is_array($row)) {
        if (function_exists('jeab_inkorg_list_refresh_thread')) {
            jeab_inkorg_list_refresh_thread($tid);
        }

        return;
    }
    if (trim((string) ($row['contact_id'] ?? '')) === '') {
        $store->assignListContact($tid, $contactId);
    }
}

function jeab_inkorg_relink_missing_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

function jeab_inkorg_relink_missing_company(string $raw): string
{
    $raw = strtolower(trim($raw));
    if ($raw === 'meab') {
        return 'malmo';
    }

    return in_array($raw, ['jarfalla', 'malmo'], true) ? $raw : '';
}

function jeab_inkorg_relink_missing_dir(): string
{
    return jeab_data_dir() . '/inkorg-relink-missing';
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_relink_missing_reminders(): array
{
    if (!function_exists('jeab_store')) {
        return [];
    }
    $store = jeab_store();
    if (!$store instanceof JeabStore) {
        return [];
    }
    if ($store instanceof JeabPdoStore) {
        try {
            $stmt = $store->pdo()->query('SELECT id, thread_id, contact_id, company, note, title FROM reminders WHERE id > 0');
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        } catch (Throwable) {
            return [];
        }
    }
    $out = [];
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $row
 */
function jeab_inkorg_relink_missing_thread_of(array $row): string
{
    $tid = trim((string) ($row['thread_id'] ?? ''));
    if ($tid !== '') {
        return $tid;
    }
    if (!function_exists('jeab_reminder_thread_from_text')) {
        return '';
    }

    return trim(jeab_reminder_thread_from_text((string) ($row['note'] ?? '')));
}

/**
 * @param array<string, array{contacts: array<string, true>, reminder: int, company: string}> $grouped
 * @param array<string, mixed> $row
 */
function jeab_inkorg_relink_missing_consider(array &$grouped, string $tid, array $row): void
{
    if (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($row)) {
        return;
    }
    if (function_exists('jeab_reminder_is_contact_fail') && jeab_reminder_is_contact_fail($row)) {
        return;
    }
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    if (!jeab_inkorg_lead_contact_safe($contactId)) {
        return;
    }
    if ($tid === '' || preg_match('/\A[A-Za-z0-9_.:-]{1,191}\z/', $tid) !== 1) {
        return;
    }
    $company = jeab_inkorg_relink_missing_company((string) ($row['company'] ?? ''));
    $id = (int) ($row['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    if (!isset($grouped[$tid])) {
        $grouped[$tid] = ['contacts' => [], 'reminder' => $id, 'company' => $company];
    }
    $grouped[$tid]['contacts'][$contactId] = true;
    if ($id < (int) $grouped[$tid]['reminder']) {
        $grouped[$tid]['reminder'] = $id;
    }
    if ($grouped[$tid]['company'] === '' && $company !== '') {
        $grouped[$tid]['company'] = $company;
    }
}

/**
 * Relink via trådens reminder_id. Påminnelsens thread_id ska vara tom eller samma tråd,
 * och bolaget (Järfälla eller Malmö) ska stämma. Annars hoppas raden över.
 *
 * @param array<string, mixed> $reminder
 * @param array<string, mixed> $thread
 */
function jeab_inkorg_relink_missing_reminder_fits(string $threadId, array $reminder, array $thread): bool
{
    $threadId = trim($threadId);
    $reminderId = (int) ($reminder['id'] ?? 0);
    $reminderThread = trim((string) ($reminder['thread_id'] ?? ''));
    $reason = '';
    if ($reminderThread !== '' && $reminderThread !== $threadId) {
        $reason = 'thread_id';
    } else {
        $reminderCompany = jeab_inkorg_relink_missing_company((string) ($reminder['company'] ?? ''));
        $threadCompany = jeab_inkorg_relink_missing_company((string) ($thread['company'] ?? $thread['brand'] ?? ''));
        if ($reminderCompany === '' || $threadCompany === '' || $reminderCompany !== $threadCompany) {
            $reason = 'bolag';
        }
    }
    if ($reason === '') {
        return true;
    }
    $safeThread = preg_match('/\A[A-Za-z0-9_.:-]{1,191}\z/', $threadId) === 1 ? $threadId : '-';
    error_log('inkorg.relink_missing hoppar over thread=' . $safeThread . ' reminder=' . $reminderId . ' orsak=' . $reason);

    return false;
}

/**
 * Trådar där en kopplad påminnelse har contact_id och tråden saknar den.
 * Påminnelsen hittas via reminder.thread_id, id i anteckningen, eller trådens reminder_id.
 * Flera olika kontakter på samma tråd lämnas. Inget skickas.
 *
 * @return array{links:list<array{thread:string,reminder:int,contact_id:string,company:string}>,ambiguous:int,kept:int}
 */
function jeab_inkorg_relink_missing_plan(): array
{
    $grouped = [];
    $byId = [];
    foreach (jeab_inkorg_relink_missing_reminders() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = $row;
        }
        $tid = jeab_inkorg_relink_missing_thread_of($row);
        if ($tid !== '') {
            jeab_inkorg_relink_missing_consider($grouped, $tid, $row);
        }
    }
    $meta = function_exists('jeab_inkorg_threads_meta') ? jeab_inkorg_threads_meta() : [];
    foreach ($meta as $tid => $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $rid = (int) ($thread['reminder_id'] ?? 0);
        if ($rid < 1 || !isset($byId[$rid])) {
            continue;
        }
        if (!jeab_inkorg_relink_missing_reminder_fits((string) $tid, $byId[$rid], $thread)) {
            continue;
        }
        jeab_inkorg_relink_missing_consider($grouped, (string) $tid, $byId[$rid]);
    }
    $links = [];
    $ambiguous = 0;
    $kept = 0;
    foreach ($grouped as $tid => $pack) {
        $contacts = array_keys($pack['contacts']);
        if (count($contacts) !== 1) {
            $ambiguous++;
            continue;
        }
        if (!array_key_exists($tid, $meta) || !is_array($meta[$tid])) {
            continue;
        }
        $company = jeab_inkorg_relink_missing_company((string) ($pack['company'] ?? ''));
        if ($company === '') {
            $company = jeab_inkorg_relink_missing_company((string) ($meta[$tid]['company'] ?? $meta[$tid]['brand'] ?? ''));
        }
        if ($company === '') {
            continue;
        }
        $have = trim((string) ($meta[$tid]['contact_id'] ?? ''));
        $want = (string) $contacts[0];
        if ($have !== '') {
            if ($have !== $want) {
                $kept++;
            }
            continue;
        }
        $links[] = [
            'thread' => (string) $tid,
            'reminder' => (int) $pack['reminder'],
            'contact_id' => $want,
            'company' => $company,
        ];
    }
    usort($links, static fn (array $a, array $b): int => strcmp($a['thread'], $b['thread']));

    return ['links' => $links, 'ambiguous' => $ambiguous, 'kept' => $kept];
}

/**
 * inkorg.relink_missing. dry=1 är standard.
 * dry=0 skriver contact_id och en undo-fil chmod 0600 under data/.private/.
 * Inget utskick. Personnummer och kundfält lämnas utanför svaret.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_relink_missing(array $input): array
{
    if (trim((string) ($input['undo'] ?? '')) !== '') {
        return jeab_inkorg_relink_missing_undo($input);
    }
    $dry = jeab_inkorg_relink_missing_dry($input);
    $plan = jeab_inkorg_relink_missing_plan();
    $base = [
        'ok' => true,
        'action' => 'inkorg.relink_missing',
        'dry' => $dry ? 1 : 0,
        'written' => 0,
        'sent' => 0,
        'would_send' => false,
        'ambiguous' => (int) $plan['ambiguous'],
        'kept' => (int) $plan['kept'],
        'threads' => $plan['links'],
    ];
    if ($dry) {
        return $base;
    }
    $undoRows = [];
    $written = 0;
    foreach ($plan['links'] as $link) {
        $tid = (string) $link['thread'];
        $contactId = (string) $link['contact_id'];
        $before = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
        if (trim((string) ($before['contact_id'] ?? '')) !== '') {
            continue;
        }
        if (!jeab_inkorg_lead_contact_apply($tid, $contactId)) {
            continue;
        }
        $after = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
        if (trim((string) ($after['contact_id'] ?? '')) !== $contactId) {
            continue;
        }
        $written++;
        $undoRows[] = [
            'thread' => $tid,
            'reminder' => (int) $link['reminder'],
            'contact_id' => $contactId,
            'company' => (string) $link['company'],
        ];
    }
    $undoId = '';
    if ($undoRows !== []) {
        $undoId = jeab_inkorg_relink_missing_save($undoRows);
    }

    return [
        'written' => $written,
        'threads' => $undoRows,
        'undo_id' => $undoId,
    ] + $base;
}

/**
 * @param list<array{thread:string,reminder:int,contact_id:string,company:string}> $rows
 */
function jeab_inkorg_relink_missing_save(array $rows): string
{
    $dir = jeab_inkorg_relink_missing_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return '';
    }
    @chmod($dir, 0700);
    $id = 'relink-' . bin2hex(random_bytes(8));
    $path = $dir . '/' . $id . '.json';
    $payload = [
        'id' => $id,
        'at' => date('c'),
        'rows' => $rows,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '';
    }
    $tmp = $dir . '/.' . $id . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        @unlink($tmp);

        return '';
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return '';
    }
    @chmod($path, 0600);

    return $id;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_relink_missing_undo(array $input): array
{
    $dry = jeab_inkorg_relink_missing_dry($input);
    $id = trim((string) ($input['undo'] ?? ''));
    $base = [
        'ok' => false,
        'action' => 'inkorg.relink_missing',
        'dry' => $dry ? 1 : 0,
        'undo' => 1,
        'sent' => 0,
        'would_send' => false,
        'written' => 0,
    ];
    if (preg_match('/\Arelink-[a-f0-9]{16}\z/', $id) !== 1) {
        return $base + ['error' => 'undo saknas'];
    }
    $path = jeab_inkorg_relink_missing_dir() . '/' . $id . '.json';
    if (!is_file($path)) {
        return $base + ['error' => 'undo saknas', 'undo_id' => $id];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $rows = is_array($data) && is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $plan = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = trim((string) ($row['thread'] ?? ''));
        $contactId = trim((string) ($row['contact_id'] ?? ''));
        if ($tid === '' || !jeab_inkorg_lead_contact_safe($contactId)) {
            continue;
        }
        $plan[] = ['thread' => $tid, 'contact_id' => $contactId, 'reminder' => (int) ($row['reminder'] ?? 0), 'company' => jeab_inkorg_relink_missing_company((string) ($row['company'] ?? ''))];
    }
    if ($dry) {
        return [
            'ok' => true,
            'action' => 'inkorg.relink_missing',
            'dry' => 1,
            'undo' => 1,
            'sent' => 0,
            'would_send' => false,
            'written' => 0,
            'undo_id' => $id,
            'threads' => $plan,
        ];
    }
    $written = 0;
    $prev = $GLOBALS['_JEAB_INKORG_RELINK_UNDO'] ?? null;
    $GLOBALS['_JEAB_INKORG_RELINK_UNDO'] = 1;
    try {
        foreach ($plan as $row) {
            $tid = (string) $row['thread'];
            $contactId = (string) $row['contact_id'];
            $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
            if (trim((string) ($meta['contact_id'] ?? '')) !== $contactId) {
                continue;
            }
            if (function_exists('jeab_inkorg_thread_write')) {
                jeab_inkorg_thread_write($tid, static function (array $t) use ($contactId): array {
                    if (trim((string) ($t['contact_id'] ?? '')) === $contactId) {
                        $t['contact_id'] = '';
                        $t['updated_at'] = date('c');
                    }

                    return $t;
                });
            }
            if (function_exists('jeab_inkorg_pdo_store')) {
                $store = jeab_inkorg_pdo_store();
                if ($store instanceof JeabPdoInkorgStore) {
                    $list = $store->listRowsByIds([$tid]);
                    $listRow = $list[0] ?? null;
                    if (is_array($listRow) && trim((string) ($listRow['contact_id'] ?? '')) === $contactId) {
                        $store->assignListContact($tid, '');
                    }
                }
            }
            $after = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
            if (trim((string) ($after['contact_id'] ?? '')) === '') {
                $written++;
            }
        }
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_INKORG_RELINK_UNDO']);
        } else {
            $GLOBALS['_JEAB_INKORG_RELINK_UNDO'] = $prev;
        }
    }

    return [
        'ok' => true,
        'action' => 'inkorg.relink_missing',
        'dry' => 0,
        'undo' => 1,
        'sent' => 0,
        'would_send' => false,
        'written' => $written,
        'undo_id' => $id,
        'threads' => $plan,
    ];
}
