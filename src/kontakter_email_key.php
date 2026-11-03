<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/hook_scoped_tokens.php';

/**
 * E-post som kontaktens nyckel (PR C, steg 1).
 *
 * Normalisering: jeab_kontakt_email() i kontakter.php är det enda stället (trim, gemener,
 * «Namn <a@b>» och mailto: skalas, tomt/ogiltigt blir ''). Matchning (jeab_kontakt_find):
 * mejl först, sedan gata med krav på att mejl, telefon eller namn stämmer, sedan telefon+namn.
 * Kontakter utan mejl har ingen unik nyckel och matchas vidare på telefon+namn.
 *
 * Hookar:
 *  - kontakter.email_dupes        Skrivskyddad. Dubblettgrupper per normaliserat mejl.
 *  - kontakter.email_merge        write=1 + groups. Backup-zip och före-bild, radantal före/efter.
 *                                 Slår ihop via jeab_contacts_merge_pair (ånger: contacts.merge_duplicates_undo).
 *  - kontakter.email_unique_index Dry som standard. write=1 skapar unikt index på contact_emails(email)
 *                                 bara när inga dubbletter finns. Körs först när grupperna är lösta.
 * Hushåll (olika namn på samma mejl) slås aldrig ihop automatiskt. De kräver household_ok:1 i gruppen.
 * Inga JSON-filer raderas. Inga utskick.
 */

const JEAB_EMAIL_KEY_INDEX = 'contact_emails_email_unique';
const JEAB_EMAIL_KEY_SAME = 'trolig samma person';
const JEAB_EMAIL_KEY_HOUSEHOLD = 'hushåll (olika namn)';

function jeab_email_key_authorized(string $action): bool
{
    if (function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }
    return jeab_hook_scoped_authorized($action);
}

/** @param array<string, mixed> $input */
function jeab_email_key_wants_write(array $input): bool
{
    $w = $input['write'] ?? null;
    return $w === 1 || $w === '1' || $w === true;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_email_key_dispatch(string $action, array $input): array
{
    if (!jeab_email_key_authorized($action)) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    try {
        $result = match ($action) {
            'kontakter.email_dupes' => jeab_email_key_dupes($input),
            'kontakter.email_merge' => jeab_email_key_merge($input),
            'kontakter.email_unique_index' => jeab_email_key_unique_index($input),
            default => ['ok' => false, 'error' => 'Okänd åtgärd.'],
        };
    } catch (Throwable $e) {
        error_log($action . ': ' . $e::class . ': ' . $e->getMessage());
        $result = ['ok' => false, 'error' => 'Avbröts. Se loggen.'];
    }
    if (!isset($result['status'])) {
        $result['status'] = !empty($result['ok']) ? 200 : 400;
    }
    return $result;
}

function jeab_email_key_pdo(): ?PDO
{
    $store = function_exists('jeab_store') ? jeab_store() : null;
    return $store instanceof JeabPdoStore ? $store->pdo() : null;
}

/**
 * Grupper med samma normaliserade mejl på fler än en kontakt.
 *
 * @param array<string, array<string, mixed>> $contacts
 * @return array<string, list<string>> mejl => kontakt-id
 */
function jeab_email_key_groups(array $contacts): array
{
    $by = [];
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (jeab_kontakt_row_email_keys($row) as $email) {
            if (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email)) {
                continue;
            }
            $by[$email][(string) $id] = true;
        }
    }
    $out = [];
    foreach ($by as $email => $ids) {
        if (count($ids) > 1) {
            $list = array_map('strval', array_keys($ids));
            sort($list, SORT_STRING);
            $out[(string) $email] = $list;
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** @param list<array<string, mixed>> $rows */
function jeab_email_key_classify(array $rows): string
{
    $names = [];
    foreach ($rows as $row) {
        $n = trim(jeab_kontakt_row_name($row));
        if ($n !== '' && !jeab_kontakt_name_is_garbage($n) && !jeab_kontakt_name_is_email($n)) {
            $names[] = $n;
        }
    }
    for ($i = 0; $i < count($names); $i++) {
        for ($j = $i + 1; $j < count($names); $j++) {
            if (!jeab_kontakt_party_names_agree($names[$i], $names[$j])) {
                return JEAB_EMAIL_KEY_HOUSEHOLD;
            }
        }
    }
    return JEAB_EMAIL_KEY_SAME;
}

/**
 * Påminnelser, ordrar och bokningar per kontakt. Läser bara.
 * Aktiv påminnelse = ej slutförd. Snoozad (förfaller senare än nu) och parkerad
 * (lista eller tagg med "parker") räknas också som aktiva men visas separat.
 * Ordrar: purchases.contact_id, contact_id/kontakt-id i orderns payload eller
 * orderns påminnelse (purchases.reminder_id) med contact_id. Går en läsning inte
 * att göra blir orders_unknown true, och merge vägrar då gruppen.
 *
 * @param list<string> $ids
 * @return array<string, array<string, mixed>>
 */
function jeab_email_key_activity(?PDO $pdo, array $ids, ?int $now = null): array
{
    $now = $now ?? time();
    $nowStr = date('Y-m-d H:i:s', $now);
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = [
            'reminders' => 0, 'reminders_open' => 0, 'reminders_snoozed' => 0, 'reminders_parked' => 0,
            'reminder_last' => '', 'booking_last' => '', 'bookings' => 0,
            'orders' => 0, 'order_last' => '', 'orders_unknown' => false, 'reminders_unknown' => false,
        ];
    }
    if ($ids === []) {
        return $out;
    }
    if (!$pdo instanceof PDO) {
        foreach ($out as $id => $_) {
            $out[$id]['orders_unknown'] = true;
            $out[$id]['reminders_unknown'] = true;
        }
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT r.contact_id, r.completed_at, r.due_at, r.tags, r.updated_at, r.created_at, l.name AS list_name FROM reminders r LEFT JOIN reminder_lists l ON l.id = r.list_id WHERE r.contact_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (string) ($r['contact_id'] ?? '');
            if (!isset($out[$cid])) {
                continue;
            }
            $out[$cid]['reminders']++;
            $out[$cid]['reminder_last'] = max($out[$cid]['reminder_last'], (string) ($r['updated_at'] ?? ''), (string) ($r['created_at'] ?? ''));
            if (trim((string) ($r['completed_at'] ?? '')) !== '') {
                continue;
            }
            $out[$cid]['reminders_open']++;
            $due = trim((string) ($r['due_at'] ?? ''));
            if ($due !== '' && $due > $nowStr) {
                $out[$cid]['reminders_snoozed']++;
            }
            $tagList = mb_strtolower((string) ($r['tags'] ?? '') . ' ' . (string) ($r['list_name'] ?? ''), 'UTF-8');
            if (str_contains($tagList, 'parker')) {
                $out[$cid]['reminders_parked']++;
            }
        }
    } catch (Throwable) {
        foreach ($out as $id => $_) {
            $out[$id]['reminders_unknown'] = true;
        }
    }
    try {
        $st = $pdo->prepare("SELECT contact_id, COUNT(*) AS n, MAX(updated_at) AS u FROM bookings WHERE contact_id IN ($in) GROUP BY contact_id");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cid = (string) ($r['contact_id'] ?? '');
            if (isset($out[$cid])) {
                $out[$cid]['bookings'] = (int) $r['n'];
                $out[$cid]['booking_last'] = (string) ($r['u'] ?? '');
            }
        }
    } catch (Throwable) {
    }
    // Ordrar: en order räknas en gång per kontakt även om flera vägar träffar.
    $seen = [];
    $hit = static function (string $cid, string $orderId, string $updated) use (&$out, &$seen): void {
        if (!isset($out[$cid]) || isset($seen[$cid][$orderId])) {
            return;
        }
        $seen[$cid][$orderId] = true;
        $out[$cid]['orders']++;
        $out[$cid]['order_last'] = max($out[$cid]['order_last'], $updated);
    };
    $cols = [];
    try {
        $probe = $pdo->query('SELECT * FROM purchases LIMIT 1');
        for ($i = 0; $probe !== false && $i < $probe->columnCount(); $i++) {
            $meta = $probe->getColumnMeta($i);
            if (is_array($meta) && isset($meta['name'])) {
                $cols[(string) $meta['name']] = true;
            }
        }
        if (isset($cols['contact_id'])) {
            $st = $pdo->prepare("SELECT reminder_id, contact_id, updated_at FROM purchases WHERE contact_id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hit((string) $r['contact_id'], (string) $r['reminder_id'], (string) ($r['updated_at'] ?? ''));
            }
        }
        $st = $pdo->prepare("SELECT p.reminder_id, r.contact_id, p.updated_at FROM purchases p JOIN reminders r ON r.id = p.reminder_id WHERE r.contact_id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $hit((string) $r['contact_id'], (string) $r['reminder_id'], (string) ($r['updated_at'] ?? ''));
        }
        // Kontaktreferenser i orderdata. Id:n är hashar, så LIKE ger inga falska träffar på delsträngar av namn.
        foreach ($ids as $cid) {
            $st = $pdo->prepare('SELECT reminder_id, payload, updated_at FROM purchases WHERE payload LIKE ?');
            $st->execute(['%' . $cid . '%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $hit($cid, (string) $r['reminder_id'], (string) ($r['updated_at'] ?? ''));
            }
        }
    } catch (Throwable) {
        foreach ($out as $id => $_) {
            $out[$id]['orders_unknown'] = true;
        }
    }
    return $out;
}

/**
 * Varför en kontakt stoppar merge. Tom lista = inget hinder.
 *
 * @param array<string, mixed> $a
 * @return list<string>
 */
function jeab_email_key_blockers(array $a): array
{
    $why = [];
    if (($a['orders'] ?? 0) > 0) {
        $why[] = 'order';
    }
    if (($a['reminders_open'] ?? 0) > 0) {
        $why[] = 'aktiv påminnelse';
    }
    if (!empty($a['orders_unknown'])) {
        $why[] = 'ordrar gick inte att läsa';
    }
    if (!empty($a['reminders_unknown'])) {
        $why[] = 'påminnelser gick inte att läsa';
    }
    return $why;
}

/**
 * kontakter.email_dupes. Ändrar inget, oavsett write.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_email_key_dupes(array $input): array
{
    $now = isset($input['now']) && is_string($input['now']) && strtotime($input['now']) !== false ? strtotime($input['now']) : time();
    $remSince = date('Y-m-d H:i:s', $now - 31 * 86400);
    $actSince = date('Y-m-d H:i:s', $now - 92 * 86400);
    $contacts = jeab_kontakter_load();
    $groups = jeab_email_key_groups($contacts);
    $allIds = [];
    foreach ($groups as $ids) {
        foreach ($ids as $id) {
            $allIds[$id] = true;
        }
    }
    $activity = jeab_email_key_activity(jeab_email_key_pdo(), array_keys($allIds), $now);
    $onlyActive = !empty($input['active_only']);
    $out = [];
    $counts = ['groups' => 0, 'contacts' => 0, 'active_groups' => 0, 'households' => 0, 'mergeable_groups' => 0];
    foreach ($groups as $email => $ids) {
        $rows = [];
        $members = [];
        $groupActive = false;
        $groupBlock = [];
        foreach ($ids as $id) {
            $row = $contacts[$id];
            $rows[] = $row;
            $a = $activity[$id];
            // contacts.updated_at räknas inte: bara påminnelser, bokningar och ordrar.
            $last = max($a['reminder_last'], $a['booking_last'], $a['order_last']);
            $blockers = jeab_email_key_blockers($a);
            $active = $blockers !== []
                || ($a['reminder_last'] !== '' && $a['reminder_last'] >= $remSince)
                || ($last !== '' && $last >= $actSince);
            $groupActive = $groupActive || $active;
            foreach ($blockers as $why) {
                $groupBlock[$why] = true;
            }
            $members[] = [
                'id' => $id,
                'name' => jeab_kontakt_row_name($row),
                'address' => trim(implode(', ', array_filter([
                    trim((string) ($row['street'] ?? '')),
                    trim(trim((string) ($row['postal_code'] ?? '')) . ' ' . trim((string) ($row['city'] ?? ''))),
                ], static fn(string $s): bool => $s !== ''))),
                'company' => (string) ($row['company'] ?? ''),
                'last_activity' => $last,
                'reminders' => $a['reminders'],
                'reminders_open' => $a['reminders_open'],
                'reminders_snoozed' => $a['reminders_snoozed'],
                'reminders_parked' => $a['reminders_parked'],
                'reminder_last' => $a['reminder_last'],
                'orders' => $a['orders'],
                'orders_unknown' => $a['orders_unknown'],
                'bookings' => $a['bookings'],
                'active' => $active,
                'blockers' => $blockers,
            ];
        }
        if ($onlyActive && !$groupActive) {
            continue;
        }
        $class = jeab_email_key_classify($rows);
        $counts['groups']++;
        $counts['contacts'] += count($ids);
        $counts['active_groups'] += $groupActive ? 1 : 0;
        $counts['households'] += $class === JEAB_EMAIL_KEY_HOUSEHOLD ? 1 : 0;
        $counts['mergeable_groups'] += $groupBlock === [] ? 1 : 0;
        $out[] = ['email' => $email, 'class' => $class, 'active' => $groupActive, 'mergeable' => $groupBlock === [], 'blockers' => array_keys($groupBlock), 'contacts' => $members];
    }
    usort($out, static fn(array $a, array $b): int => [$b['active'], $a['email']] <=> [$a['active'], $b['email']]);
    return [
        'ok' => true,
        'action' => 'kontakter.email_dupes',
        'dry' => true,
        'written' => false,
        'active_rule' => 'order eller öppen påminnelse, eller påminnelse senaste 31 dagar, eller påminnelse/bokning/order senaste 92 dagar (contacts.updated_at räknas inte)',
        'merge_rule' => 'grupp kan slås ihop bara om ingen kontakt har order eller öppen påminnelse',
        'counts' => $counts,
        'groups' => $out,
    ];
}

/** @return array<string, int> */
function jeab_email_key_row_counts(?PDO $pdo, array $ids): array
{
    $out = ['contacts' => -1, 'contact_emails' => -1, 'reminders' => -1, 'orders' => -1, 'bookings' => -1, 'reminders_linked' => -1, 'bookings_linked' => -1];
    if (!$pdo instanceof PDO) {
        return $out;
    }
    foreach (['contacts' => 'contacts', 'contact_emails' => 'contact_emails', 'reminders' => 'reminders', 'purchases' => 'orders', 'bookings' => 'bookings'] as $t => $k) {
        try {
            $out[$k] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        } catch (Throwable) {
        }
    }
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (['reminders' => 'reminders_linked', 'bookings' => 'bookings_linked'] as $t => $k) {
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE contact_id IN ($in)");
                $st->execute($ids);
                $out[$k] = (int) $st->fetchColumn();
            } catch (Throwable) {
            }
        }
    }
    return $out;
}

/**
 * Granskade grupper. Varje grupp: {email, keep, merge:[id,…], household_ok?:1}.
 * Id:n måste ligga i dagens dubblettgrupp för mejlet. Hushåll kräver household_ok:1.
 *
 * @param array<string, mixed> $input
 * @return array{plan: list<array{email:string, keep:string, merge:list<string>}>, errors: list<string>}
 */
function jeab_email_key_merge_plan(array $input, array $contacts): array
{
    $groups = jeab_email_key_groups($contacts);
    $plan = [];
    $errors = [];
    $used = [];
    $raw = $input['groups'] ?? null;
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw) || $raw === []) {
        return ['plan' => [], 'errors' => ['groups saknas.']];
    }
    foreach (array_values($raw) as $i => $g) {
        if (!is_array($g)) {
            $errors[] = "grupp $i: ogiltig";
            continue;
        }
        $email = jeab_kontakt_email((string) ($g['email'] ?? ''));
        $keep = trim((string) ($g['keep'] ?? ''));
        $merge = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string) $v), is_array($g['merge'] ?? null) ? $g['merge'] : []), static fn(string $v): bool => $v !== '')));
        $current = $groups[$email] ?? [];
        if ($email === '' || $current === []) {
            $errors[] = "grupp $i: mejlet är ingen dubblettgrupp nu";
            continue;
        }
        if ($keep === '' || !in_array($keep, $current, true) || $merge === [] || in_array($keep, $merge, true)) {
            $errors[] = "grupp $i: keep/merge stämmer inte med gruppen";
            continue;
        }
        foreach ($merge as $m) {
            if (!in_array($m, $current, true)) {
                $errors[] = "grupp $i: $m ingår inte i gruppen";
                continue 2;
            }
        }
        $rows = array_map(static fn(string $id): array => $contacts[$id], array_merge([$keep], $merge));
        $okHousehold = ($g['household_ok'] ?? null) === 1 || ($g['household_ok'] ?? null) === '1' || ($g['household_ok'] ?? null) === true;
        if (jeab_email_key_classify($rows) === JEAB_EMAIL_KEY_HOUSEHOLD && !$okHousehold) {
            $errors[] = "grupp $i: hushåll (olika namn) kräver household_ok:1";
            continue;
        }
        foreach (array_merge([$keep], $merge) as $id) {
            if (isset($used[$id])) {
                $errors[] = "grupp $i: $id finns i flera grupper";
                continue 2;
            }
            $used[$id] = true;
        }
        $plan[] = ['email' => $email, 'keep' => $keep, 'merge' => $merge];
    }
    return ['plan' => $plan, 'errors' => $errors];
}

/**
 * kontakter.email_merge. Utan write=1 visas bara planen.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_email_key_merge(array $input): array
{
    $write = jeab_email_key_wants_write($input);
    $base = ['action' => 'kontakter.email_merge', 'dry' => !$write, 'written' => false];
    $contacts = jeab_kontakter_load();
    $p = jeab_email_key_merge_plan($input, $contacts);
    if ($p['errors'] !== []) {
        return ['ok' => false, 'error' => 'Grupperna stämmer inte. Inget ändrat.', 'errors' => $p['errors']] + $base;
    }
    $pdo = jeab_email_key_pdo();
    // Vakt per grupp: order eller aktiv påminnelse på någon kontakt i gruppen (hela dagens grupp) lämnar den.
    $groupsNow = jeab_email_key_groups($contacts);
    $allIds = [];
    foreach ($p['plan'] as $g) {
        foreach ($groupsNow[$g['email']] ?? [] as $id) {
            $allIds[$id] = true;
        }
    }
    $activity = jeab_email_key_activity($pdo, array_keys($allIds));
    $plan = [];
    $left = [];
    foreach ($p['plan'] as $g) {
        $why = [];
        foreach ($groupsNow[$g['email']] ?? [] as $id) {
            foreach (jeab_email_key_blockers($activity[$id] ?? ['orders_unknown' => true]) as $w) {
                $why[$w][] = $id;
            }
        }
        if ($why !== []) {
            $reasons = [];
            foreach ($why as $w => $wIds) {
                $reasons[] = $w . ' (' . implode(', ', $wIds) . ')';
            }
            $left[] = ['email' => $g['email'], 'keep' => $g['keep'], 'merge' => $g['merge'], 'reason' => implode('; ', $reasons)];
            continue;
        }
        $plan[] = $g;
    }
    $base['left'] = $left;
    if ($plan === []) {
        return ['ok' => true, 'plan' => [], 'merged_groups' => [], 'would_merge' => 0, 'merged' => 0, 'note' => 'Alla grupper lämnades. Inget ändrat.'] + $base;
    }
    $ids = [];
    foreach ($plan as $g) {
        foreach (array_merge([$g['keep']], $g['merge']) as $id) {
            $ids[] = $id;
        }
    }
    $before = jeab_email_key_row_counts($pdo, $ids);
    if (!$write) {
        return ['ok' => true, 'plan' => $plan, 'counts_before' => $before, 'would_merge' => count($ids) - count($plan), 'would_merge_groups' => array_column($plan, 'email')] + $base;
    }
    if (!function_exists('jeab_contacts_merge_pair')) {
        require_once __DIR__ . '/contacts_merge_duplicates.php';
    }
    $sqlRequired = jeab_contacts_merge_sql_store('kontakter.email_merge');
    if ($sqlRequired !== null || !$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'sql_store_required', 'status' => 409] + $base;
    }
    // Backup först: delad zip, sedan före-bild av raderna. Inga JSON-filer raderas.
    if (!function_exists('jeab_cutover_ensure_tree_zip')) {
        require_once __DIR__ . '/data_cutover_all.php';
    }
    $zip = function_exists('jeab_cutover_ensure_tree_zip') ? jeab_cutover_ensure_tree_zip(true) : ['ok' => false];
    $zipPath = !empty($zip['ok']) ? (string) ($zip['path'] ?? '') : '';
    if ($zipPath === '') {
        return ['ok' => false, 'error' => 'Backup-zipen saknas. Inget ändrat.'] + $base;
    }
    $dir = rtrim(jeab_data_dir(), '/') . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/kontakter-email-merge-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
    $dump = [];
    foreach ($ids as $id) {
        $dump[$id] = $contacts[$id] ?? null;
    }
    $json = json_encode(['plan' => $plan, 'contacts' => $dump, 'counts_before' => $before], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($file, $json, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Före-bilden gick inte att skriva. Inget ändrat.'] + $base;
    }
    jeab_contacts_merge_ensure($pdo);
    if (!jeab_contacts_merge_run_lock($pdo)) {
        return ['ok' => false, 'error' => 'busy', 'status' => 409, 'backup' => basename($file), 'backup_zip' => basename($zipPath)] + $base;
    }
    $undoId = bin2hex(random_bytes(8));
    $merged = 0;
    $failed = [];
    try {
        foreach ($plan as $g) {
            foreach ($g['merge'] as $dup) {
                try {
                    jeab_pdo_transaction($pdo, static fn(PDO $tx): array => jeab_contacts_merge_pair($tx, $undoId, $g['keep'], $dup));
                    jeab_contacts_merge_forget($dup);
                    $merged++;
                } catch (Throwable $e) {
                    jeab_contacts_merge_forget();
                    $failed[] = ['id' => $dup, 'reason' => $e instanceof JeabContactsMergePairException ? $e->getMessage() : 'error'];
                }
            }
        }
    } finally {
        if (function_exists('jeab_db_release_lock')) {
            jeab_db_release_lock($pdo, 'jeab_contacts_merge');
        }
    }
    $after = jeab_email_key_row_counts($pdo, $ids);
    $out = [
        'ok' => $failed === [],
        'written' => $merged > 0,
        'dry' => false,
        'action' => 'kontakter.email_merge',
        'undo_id' => $undoId,
        'undo' => 'contacts.merge_duplicates_undo',
        'merged' => $merged,
        'failed' => $failed,
        'plan' => $plan,
        'merged_groups' => array_values(array_map(static fn(array $g): string => $g['email'], array_filter($plan, static fn(array $g): bool => !in_array(true, array_map(static fn(string $d): bool => in_array($d, array_column($failed, 'id'), true), $g['merge']), true)))),
        'left' => $left,
        'counts_before' => $before,
        'counts_after' => $after,
        'backup' => basename($file),
        'backup_zip' => basename($zipPath),
    ];
    $audit = json_encode(['at' => date('c')] + $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($audit)) {
        @file_put_contents(rtrim(jeab_data_dir(), '/') . '/kontakter-email-merge.jsonl', $audit . "\n", FILE_APPEND | LOCK_EX);
    }
    return $out;
}

/** SQL för det unika indexet. Samma kolumn och namn i SQLite och MySQL. Inget i schema/*.php (pinen rörs inte). */
function jeab_email_key_index_sql(string $driver): string
{
    return $driver === 'mysql'
        ? 'CREATE UNIQUE INDEX ' . JEAB_EMAIL_KEY_INDEX . ' ON contact_emails (email)'
        : 'CREATE UNIQUE INDEX IF NOT EXISTS ' . JEAB_EMAIL_KEY_INDEX . ' ON contact_emails (email)';
}

function jeab_email_key_index_exists(PDO $pdo, string $driver): bool
{
    try {
        if ($driver === 'mysql') {
            $st = $pdo->prepare('SHOW INDEX FROM contact_emails WHERE Key_name = ?');
            $st->execute([JEAB_EMAIL_KEY_INDEX]);
            return $st->fetch() !== false;
        }
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?");
        $st->execute([JEAB_EMAIL_KEY_INDEX]);
        return $st->fetchColumn() !== false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * kontakter.email_unique_index. Dry visar dubbletter i contact_emails. write=1 skapar indexet
 * bara när dubbletterna är noll och contact_emails är ifylld (contact_emails_v1 = ok).
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_email_key_unique_index(array $input): array
{
    $write = jeab_email_key_wants_write($input);
    $base = ['action' => 'kontakter.email_unique_index', 'dry' => !$write, 'written' => false, 'index' => JEAB_EMAIL_KEY_INDEX];
    $pdo = jeab_email_key_pdo();
    if (!$pdo instanceof PDO || !function_exists('jeab_contact_emails_table_exists') || !jeab_contact_emails_table_exists($pdo)) {
        return ['ok' => false, 'error' => 'contact_emails saknas.'] + $base;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $exists = jeab_email_key_index_exists($pdo, $driver);
    $dupes = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT email FROM contact_emails GROUP BY email HAVING COUNT(*) > 1) d')->fetchColumn();
    $backfilled = function_exists('jeab_settings_value') && jeab_settings_value($pdo, $driver, 'contact_emails_v1') === 'ok';
    $out = ['ok' => true, 'exists' => $exists, 'duplicate_emails' => $dupes, 'backfilled' => $backfilled, 'sql' => jeab_email_key_index_sql($driver)] + $base;
    if (!$write || $exists) {
        return $out;
    }
    if ($dupes > 0) {
        return ['ok' => false, 'error' => 'Dubbletter finns kvar. Lös dem först (kontakter.email_dupes).'] + $out;
    }
    if (!$backfilled) {
        return ['ok' => false, 'error' => 'contact_emails är inte ifylld än (contact_emails_v1).'] + $out;
    }
    $pdo->exec(jeab_email_key_index_sql($driver));
    $out['exists'] = jeab_email_key_index_exists($pdo, $driver);
    $out['written'] = $out['exists'];
    return $out;
}
