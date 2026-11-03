<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Öppna inkorgsärenden som redan har en påminnelse.
 * Listan är bara id, datum och orsak. Inga namn och inga adresser.
 *
 * Graph sätter thread_key till konversations-id, så ett nytt Wix-formulär
 * blir ett eget ärende. jeab_lead_process och jeab_create_reminder kopplade
 * påminnelsen till en tråd och stängde inte syskonen. close_quoted kräver
 * dessutom offert på just det ärendet. Den här filen stänger alla öppna
 * träffar när en påminnelse skapas eller kopplas, utom ett kundsvar som
 * just öppnat ärendet (reopened_at). Hooken inbox.close_with_reminder
 * stänger även de, med confirm=SAM.
 */

function jeab_inkorg_reminder_company_key(array $rem): string
{
    $raw = (string) ($rem['company'] ?? '');
    if (function_exists('jeab_normalize_company')) {
        $norm = jeab_normalize_company($raw);
        return is_string($norm) ? $norm : '';
    }

    return $raw === 'jarfalla' || $raw === 'malmo' ? $raw : '';
}

function jeab_inkorg_reminder_email_usable(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email)) {
        return false;
    }
    if (function_exists('jeab_lead_platform') && jeab_lead_platform($email) !== '') {
        return false;
    }
    if (function_exists('jeab_auto_service_recipient') && jeab_auto_service_recipient($email)) {
        return false;
    }

    return true;
}

function jeab_inkorg_hs_like_id(string $id): bool
{
    return preg_match('/^hs[-_]/i', $id) === 1;
}

/** Kundadressen på ärendet. Tom om den inte går att använda. */
function jeab_inkorg_open_thread_email(array $thread): string
{
    $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
    if (!function_exists('jeab_contacts_inbox_contact_email')) {
        require_once __DIR__ . '/contacts_strict.php';
    }
    $candidates = [
        jeab_contacts_inbox_contact_email($thread),
        (string) ($thread['customer_email'] ?? ''),
        (string) ($lead['email'] ?? ''),
    ];
    $first = is_array($thread['first'] ?? null) ? $thread['first'] : [];
    if ($first !== [] && function_exists('jeab_lead_customer')) {
        try {
            $cust = jeab_lead_customer($first);
            if (is_array($cust)) {
                $candidates[] = (string) ($cust['email'] ?? '');
            }
        } catch (Throwable $e) {
            unset($e);
        }
    }
    $candidates[] = (string) ($first['from'] ?? '');
    foreach ($candidates as $raw) {
        $email = mb_strtolower(trim($raw));
        if (jeab_inkorg_reminder_email_usable($email)) {
            return $email;
        }
    }

    return '';
}

function jeab_inkorg_open_view_status(array $thread): bool
{
    if (!empty($thread['deleted'])) {
        return false;
    }
    $status = function_exists('jeab_inkorg_thread_status')
        ? jeab_inkorg_thread_status($thread)
        : (string) ($thread['status'] ?? '');

    return in_array($status, ['', 'open', 'waiting_customer', 'waiting_supplier'], true);
}

function jeab_inkorg_open_reminder_match(array $thread, array $rem): bool
{
    $tid = (string) ($thread['id'] ?? '');
    if ($tid !== '' && function_exists('jeab_inkorg_reminder_links_thread') && jeab_inkorg_reminder_links_thread($rem, $tid)) {
        return true;
    }
    $remEmail = mb_strtolower(trim((string) ($rem['email'] ?? '')));
    $threadEmail = jeab_inkorg_open_thread_email($thread);
    if (jeab_inkorg_reminder_email_usable($remEmail) && $threadEmail !== '' && $remEmail === $threadEmail) {
        return true;
    }
    $cid = trim((string) ($thread['contact_id'] ?? ''));
    $remCid = trim((string) ($rem['contact_id'] ?? ''));

    return $cid !== '' && $cid === $remCid;
}

function jeab_inkorg_open_reminder_date(array $thread, array $rem): string
{
    $first = is_array($thread['first'] ?? null) ? $thread['first'] : [];
    $raw = (string) ($first['received_at'] ?? $first['date'] ?? '');
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) {
        $raw = (string) ($rem['created_at'] ?? $rem['due_at'] ?? '');
        $ts = strtotime($raw);
    }
    if ($ts === false || $ts <= 0) {
        return '';
    }

    return date('Y-m-d', $ts);
}

/**
 * other_thread: påminnelsen pekar på ett annat ärende, eller bara på kundmejl/kontakt.
 * hs_twin: ena id:t är hs-…
 * not_closed: påminnelsen är kopplad hit och ärendet är ändå öppet.
 * reopened_mail: ett nytt mejl satte reopened_at.
 * reopened_hook: inbox.reopen.
 * import_open: Help Scout-import eller promote lämnade det kopplade ärendet öppet.
 */
function jeab_inkorg_open_reminder_cause(array $thread, array $rem): string
{
    $tid = (string) ($thread['id'] ?? '');
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    if (trim((string) ($meta['reopened_at'] ?? '')) !== '') {
        return 'reopened_mail';
    }
    if (trim((string) ($meta['hook_reopened_at'] ?? '')) !== '') {
        return 'reopened_hook';
    }
    $linked = $tid !== '' && function_exists('jeab_inkorg_reminder_links_thread') && jeab_inkorg_reminder_links_thread($rem, $tid);
    $remTid = trim((string) ($rem['thread_id'] ?? ''));
    $noteTid = function_exists('jeab_reminder_thread_from_text')
        ? jeab_reminder_thread_from_text((string) ($rem['note'] ?? ''))
        : '';
    if (!$linked && (jeab_inkorg_hs_like_id($tid) || jeab_inkorg_hs_like_id($remTid) || jeab_inkorg_hs_like_id($noteTid))) {
        return 'hs_twin';
    }
    if (!$linked) {
        return 'other_thread';
    }
    $import = (string) ($meta['import_source'] ?? '') === 'helpscout_import'
        || !empty($meta['helpscout_import'])
        || (string) ($thread['import_source'] ?? '') === 'helpscout_import'
        || (string) ($thread['source'] ?? '') === 'helpscout_import';
    if ($import) {
        return 'import_open';
    }

    return 'not_closed';
}

/**
 * Öppna ärenden i bolaget där kontakt, kundmejl eller tråd-id träffar en påminnelse.
 *
 * @return list<array{thread_id:string,reminder_id:int,date:string,cause:string}>
 */
function jeab_inkorg_open_with_reminder_rows(string $co): array
{
    if ($co !== 'jarfalla' && $co !== 'malmo') {
        return [];
    }
    if (!function_exists('jeab_store') || !function_exists('jeab_inkorg_threads')) {
        return [];
    }
    $reminders = [];
    try {
        $all = function_exists('jeab_reminders_every') ? jeab_reminders_every(jeab_store()) : jeab_list_reminders(jeab_store(), false);
    } catch (Throwable $e) {
        unset($e);
        return [];
    }
    foreach ($all as $rem) {
        if (!is_array($rem) || (int) ($rem['id'] ?? 0) < 1) {
            continue;
        }
        if (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($rem)) {
            continue;
        }
        $rc = jeab_inkorg_reminder_company_key($rem);
        if ($rc !== '' && $rc !== $co) {
            continue;
        }
        $reminders[] = $rem;
    }
    $rows = [];
    foreach (jeab_inkorg_threads() as $thread) {
        if (!is_array($thread) || !jeab_inkorg_open_view_status($thread)) {
            continue;
        }
        $company = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : (string) ($thread['company'] ?? '');
        if ($company !== $co) {
            continue;
        }
        foreach ($reminders as $rem) {
            if (!jeab_inkorg_open_reminder_match($thread, $rem)) {
                continue;
            }
            $rows[] = [
                'thread_id' => (string) ($thread['id'] ?? ''),
                'reminder_id' => (int) ($rem['id'] ?? 0),
                'date' => jeab_inkorg_open_reminder_date($thread, $rem),
                'cause' => jeab_inkorg_open_reminder_cause($thread, $rem),
            ];
        }
    }
    usort($rows, static function (array $a, array $b): int {
        $byDate = strcmp((string) $b['date'], (string) $a['date']);
        if ($byDate !== 0) {
            return $byDate;
        }
        $byThread = strcmp((string) $a['thread_id'], (string) $b['thread_id']);
        if ($byThread !== 0) {
            return $byThread;
        }

        return ((int) $a['reminder_id']) <=> ((int) $b['reminder_id']);
    });

    return $rows;
}

/** inbox.open_with_reminder. Bara läsning. co krävs. */
function jeab_inkorg_open_with_reminder(array $in): array
{
    $co = function_exists('jeab_normalize_company') ? jeab_normalize_company($in['co'] ?? $in['company'] ?? null) : null;
    if ($co !== 'jarfalla' && $co !== 'malmo') {
        return ['ok' => false, 'error' => 'co saknas'];
    }

    return [
        'ok' => true,
        'co' => $co,
        'would_send' => false,
        'rows' => jeab_inkorg_open_with_reminder_rows($co),
    ];
}

/**
 * Nytt lead som jeab_lead_hook ännu inte har behandlat.
 * Syskonstängning får inte ta det förrän kroken har skrivit lead_customer eller lead_hook_at.
 */
function jeab_inkorg_thread_awaits_lead_hook(array $thread): bool
{
    if (trim((string) ($thread['lead_hook_at'] ?? '')) !== '') {
        return false;
    }
    $cust = $thread['lead_customer'] ?? null;
    if (is_array($cust) && (trim((string) ($cust['email'] ?? '')) !== '' || trim((string) ($cust['name'] ?? '')) !== '')) {
        return false;
    }
    $first = is_array($thread['first'] ?? null) ? $thread['first'] : [];
    $labels = (array) ($first['labels'] ?? $thread['labels'] ?? []);
    if ((string) ($labels['type'] ?? '') === 'Ny lead') {
        return true;
    }

    return function_exists('jeab_inkorg_wix_known_product_offer') && jeab_inkorg_wix_known_product_offer($first) !== '';
}

function jeab_inkorg_open_thread_awaits_lead_hook(string $tid): bool
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_inkorg_threads')) {
        return false;
    }
    foreach (jeab_inkorg_threads() as $thread) {
        if (!is_array($thread) || (string) ($thread['id'] ?? '') !== $tid) {
            continue;
        }

        return jeab_inkorg_thread_awaits_lead_hook($thread);
    }

    return false;
}

/** Kroken har sett tråden. Senare syskonstängning får ta den. */
function jeab_inkorg_mark_lead_hook(string $tid): void
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_inkorg_thread_write')) {
        return;
    }
    try {
        jeab_inkorg_thread_write($tid, static function (array $t): array {
            if (trim((string) ($t['lead_hook_at'] ?? '')) === '') {
                $t['lead_hook_at'] = date('c');
            }

            return $t;
        });
    } catch (Throwable $e) {
        unset($e);
    }
}

/**
 * Stänger öppna träffar för påminnelsen. Hoppar över reopened_at och hook_reopened_at
 * när $skipReopened är sant, så ett kundsvar inte stängs i samma veva.
 *
 * @return list<string>
 */
function jeab_inkorg_close_open_for_reminder(array $rem, bool $skipReopened = true): array
{
    if (!empty($GLOBALS['_JEAB_REMINDER_OPEN_CLOSING'])) {
        return [];
    }
    // Omklassning får lämna påminnelsen, men inte stänga det öppna ärendet.
    if (function_exists('jeab_inkorg_klassning_no_send') && jeab_inkorg_klassning_no_send()) {
        return [];
    }
    $id = (int) ($rem['id'] ?? 0);
    if ($id < 1 || !function_exists('jeab_inkorg_mark_hook_closed')) {
        return [];
    }
    $co = jeab_inkorg_reminder_company_key($rem);
    $companies = $co !== '' ? [$co] : ['jarfalla', 'malmo'];
    $GLOBALS['_JEAB_REMINDER_OPEN_CLOSING'] = true;
    $closed = [];
    try {
        $targets = [];
        foreach ($companies as $one) {
            foreach (jeab_inkorg_open_with_reminder_rows($one) as $row) {
                if ((int) ($row['reminder_id'] ?? 0) !== $id) {
                    continue;
                }
                $cause = (string) ($row['cause'] ?? '');
                if ($skipReopened && ($cause === 'reopened_mail' || $cause === 'reopened_hook')) {
                    continue;
                }
                $tid = (string) ($row['thread_id'] ?? '');
                if ($tid !== '' && in_array($cause, ['other_thread', 'hs_twin'], true) && jeab_inkorg_open_thread_awaits_lead_hook($tid)) {
                    continue;
                }
                if ($tid === '') {
                    continue;
                }
                // Kims aktiva Malmö-ärende styrs av malmo_hs_close. Automatiken lämnar det öppet.
                if ($skipReopened && function_exists('jeab_malmo_hs_kim_thread') && jeab_malmo_hs_kim_thread($tid)) {
                    continue;
                }
                $targets[$tid] = true;
            }
        }
        foreach (array_keys($targets) as $tid) {
            try {
                jeab_inkorg_mark_hook_closed($tid, 'reminder');
                $closed[] = $tid;
            } catch (Throwable $e) {
                unset($e);
            }
        }
    } finally {
        unset($GLOBALS['_JEAB_REMINDER_OPEN_CLOSING']);
    }
    if ($closed !== []) {
        $prev = $GLOBALS['_JEAB_REMINDER_CLOSED_THREADS'] ?? [];
        $GLOBALS['_JEAB_REMINDER_CLOSED_THREADS'] = array_values(array_unique(array_merge(
            is_array($prev) ? $prev : [],
            $closed
        )));
    }

    return $closed;
}

/** Nytt ärende: stäng det och syskonen om en påminnelse redan finns. Kundsvar med reopened_at lämnas. */
function jeab_inkorg_close_open_for_thread(string $tid): void
{
    $tid = trim($tid);
    if ($tid === '' || !empty($GLOBALS['_JEAB_REMINDER_OPEN_CLOSING']) || !function_exists('jeab_inkorg_threads')) {
        return;
    }
    $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
    if (trim((string) ($meta['reopened_at'] ?? '')) !== '' || trim((string) ($meta['hook_reopened_at'] ?? '')) !== '') {
        return;
    }
    $thread = null;
    foreach (jeab_inkorg_threads() as $one) {
        if (is_array($one) && (string) ($one['id'] ?? '') === $tid) {
            $thread = $one;
            break;
        }
    }
    if ($thread === null) {
        return;
    }
    $co = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : '';
    if ($co !== 'jarfalla' && $co !== 'malmo') {
        return;
    }
    $reminderId = 0;
    foreach (jeab_inkorg_open_with_reminder_rows($co) as $row) {
        if ((string) ($row['thread_id'] ?? '') === $tid) {
            $reminderId = (int) ($row['reminder_id'] ?? 0);
            break;
        }
    }
    if ($reminderId < 1 || !function_exists('jeab_get_reminder')) {
        return;
    }
    $rem = jeab_get_reminder(jeab_store(), $reminderId);
    if (is_array($rem)) {
        jeab_inkorg_close_open_for_reminder($rem, true);
    }
}

/**
 * inbox.close_with_reminder. dry=1 är standard. dry=0 kräver confirm=SAM.
 * Skickar inget.
 */
function jeab_inkorg_close_with_reminder(array $in): array
{
    $dry = function_exists('jeab_auto_send_quote_is_dry')
        ? jeab_auto_send_quote_is_dry($in)
        : (!array_key_exists('dry', $in) || !in_array($in['dry'], [0, '0', false], true));
    $co = function_exists('jeab_normalize_company') ? jeab_normalize_company($in['co'] ?? $in['company'] ?? null) : null;
    if ($co !== 'jarfalla' && $co !== 'malmo') {
        return ['ok' => false, 'error' => 'co saknas'];
    }
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'dry' => 0, 'co' => $co, 'error' => 'confirm', 'would_send' => false];
    }
    $rows = jeab_inkorg_open_with_reminder_rows($co);
    $closed = 0;
    if (!$dry) {
        $GLOBALS['_JEAB_REMINDER_OPEN_CLOSING'] = true;
        try {
            $seen = [];
            foreach ($rows as $i => $row) {
                $tid = (string) ($row['thread_id'] ?? '');
                if ($tid === '' || isset($seen[$tid])) {
                    $rows[$i]['closed'] = isset($seen[$tid]) ? 1 : 0;
                    continue;
                }
                try {
                    jeab_inkorg_mark_hook_closed($tid, 'reminder');
                    $seen[$tid] = true;
                    $rows[$i]['closed'] = 1;
                    $closed++;
                } catch (Throwable $e) {
                    unset($e);
                    $rows[$i]['closed'] = 0;
                }
            }
        } finally {
            unset($GLOBALS['_JEAB_REMINDER_OPEN_CLOSING']);
        }
    } else {
        foreach ($rows as $i => $row) {
            unset($row);
            $rows[$i]['closed'] = 0;
        }
    }

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'co' => $co,
        'would_send' => false,
        'closed' => $closed,
        'rows' => $rows,
    ];
}

function jeab_inkorg_service_scan_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }
    $dry = $in['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_inkorg_service_reminder_seller(array $rem): string
{
    $assignee = trim((string) ($rem['assignee'] ?? ''));
    if ($assignee !== '') {
        return $assignee;
    }
    $names = $rem['assignees'] ?? [];
    if (is_array($names) && $names !== []) {
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $out[] = $name;
            }
        }
        if ($out !== []) {
            return implode(', ', $out);
        }
    }
    return trim((string) ($rem['person'] ?? ''));
}

function jeab_inkorg_service_reminder_date(array $rem): string
{
    $raw = (string) ($rem['due_at'] ?? $rem['created_at'] ?? '');
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) {
        return '';
    }
    return date('Y-m-d', $ts);
}

function jeab_inkorg_service_thread_hit(array $thread): bool
{
    $messages = (array) ($thread['messages'] ?? []);
    $first = is_array($thread['first'] ?? null) ? $thread['first'] : (is_array($messages[0] ?? null) ? $messages[0] : []);
    $labels = (array) ($first['labels'] ?? $thread['labels'] ?? []);
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($labels['type'] ?? '')) : (string) ($labels['type'] ?? '');
    if (in_array($type, ['Service', 'Support'], true)) {
        return true;
    }
    if (!function_exists('jeab_inkorg_service_keywords') || !function_exists('jeab_inkorg_message_blob')) {
        return false;
    }
    $blob = jeab_inkorg_message_blob($first);
    foreach ($messages as $message) {
        if (is_array($message)) {
            $blob .= "\n" . jeab_inkorg_message_blob($message);
        }
    }
    return jeab_inkorg_service_keywords($blob);
}

/**
 * inbox.service_reminders_scan. Bara dry=1. Läser öppna påminnelser och stänger inget.
 *
 * @return list<array{id:int,address:string,seller:string,date:string}>
 */
function jeab_inkorg_service_reminder_rows(): array
{
    if (!function_exists('jeab_store')) {
        return [];
    }
    try {
        $all = function_exists('jeab_reminders_every') ? jeab_reminders_every(jeab_store()) : jeab_list_reminders(jeab_store(), false);
    } catch (Throwable $e) {
        unset($e);
        return [];
    }
    $rows = [];
    foreach ($all as $rem) {
        if (!is_array($rem) || (int) ($rem['id'] ?? 0) < 1) {
            continue;
        }
        if (!empty($rem['completed_at'])) {
            continue;
        }
        if (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($rem)) {
            continue;
        }
        $tid = trim((string) ($rem['thread_id'] ?? ''));
        if ($tid === '' && function_exists('jeab_reminder_thread_from_text')) {
            $tid = jeab_reminder_thread_from_text((string) ($rem['note'] ?? ''));
        }
        if ($tid === '' || !function_exists('jeab_auto_thread_by_id')) {
            continue;
        }
        $thread = jeab_auto_thread_by_id($tid);
        if (!is_array($thread) || !jeab_inkorg_service_thread_hit($thread)) {
            continue;
        }
        $rows[] = [
            'id' => (int) $rem['id'],
            'address' => trim((string) ($rem['address'] ?? '')),
            'seller' => jeab_inkorg_service_reminder_seller($rem),
            'date' => jeab_inkorg_service_reminder_date($rem),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $byDate = strcmp((string) $b['date'], (string) $a['date']);
        return $byDate !== 0 ? $byDate : ((int) $a['id']) <=> ((int) $b['id']);
    });
    return $rows;
}

/** inbox.service_reminders_scan. dry=1 är det enda läget. Inget stängs. */
function jeab_inkorg_service_reminders_scan(array $in): array
{
    if (!jeab_inkorg_service_scan_dry($in)) {
        return ['ok' => false, 'dry' => 0, 'error' => 'bara läsning', 'would_send' => false, 'closed' => 0, 'rows' => []];
    }
    return [
        'ok' => true,
        'dry' => 1,
        'would_send' => false,
        'closed' => 0,
        'rows' => jeab_inkorg_service_reminder_rows(),
    ];
}
