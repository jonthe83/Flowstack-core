<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/hook_scoped_tokens.php';

/**
 * Hook kontakter.relink_case. Rättar ett ärende som kopplats till fel persons kontakt.
 *
 * dry=1 är standard och skriver inget. Svaret visar kontakterna och trådens contact_id före och efter.
 * write=1 kräver den delade backup-zipen (samma som data.inkorg_contact_link) och en före-bild
 * i data/backups. Sedan, i en kontakttransaktion: ny kontakt skapas (tvingad, ingen matchning på
 * adress eller e-post) och exakt remove_email tas bort från from_contact_id. Namn, adress och
 * andra e-postadresser på from_contact_id ligger kvar. Trådens contact_id pekas om till den nya
 * kontakten, liksom inkorgens kontaktrader för samma tråd/e-post. Påminnelser, ordrar och
 * bokningar rörs inte. En historikrad skrivs i kontakter-relink-case.jsonl och kontakter-dedupe.log.
 * En andra körning gör inget (already=1). Skickar inget.
 *
 * POST hook.php
 * {"action":"kontakter.relink_case","thread_id":"g-…","from_contact_id":"kt_…","remove_email":"x@y.se",
 *  "new_name":"…","new_email":"x@y.se","new_phone":"","new_address":"Gata 1","postnummer":"123 45","ort":"Ort","write":1}
 */

const JEAB_RELINK_CASE_RULE = 'relink_case';

function jeab_kontakter_relink_case_authorized(): bool
{
    if (function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }
    return jeab_hook_scoped_authorized('kontakter.relink_case');
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_relink_case_dispatch(array $input): array
{
    if (!jeab_kontakter_relink_case_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    try {
        $result = jeab_kontakter_relink_case($input);
    } catch (Throwable $e) {
        error_log('kontakter.relink_case: ' . $e::class . ': ' . $e->getMessage());
        $result = ['ok' => false, 'error' => 'Avbröts. Se loggen.'];
    }
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/** @param array<string, mixed> $input */
function jeab_kontakter_relink_case_wants_write(array $input): bool
{
    if (function_exists('jeab_cutover_wants_write')) {
        return jeab_cutover_wants_write($input);
    }
    $w = $input['write'] ?? null;
    if ($w === 1 || $w === '1' || $w === true) {
        return true;
    }
    $d = $input['dry'] ?? null;
    return array_key_exists('dry', $input) && ($d === 0 || $d === '0' || $d === false);
}

function jeab_kontakter_relink_case_str(mixed $v, int $max = 200): string
{
    if (is_array($v) || is_object($v)) {
        return '';
    }
    return mb_substr(trim((string) $v), 0, $max);
}

/**
 * Kontaktens e-postadresser, samma lista som contact_emails byggs av.
 *
 * @param array<string, mixed> $row
 * @return list<string>
 */
function jeab_kontakter_relink_case_emails(array $row): array
{
    if (function_exists('jeab_contact_email_list')) {
        $list = jeab_contact_email_list($row);
    } else {
        $list = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($row) : [];
    }
    foreach ((array) ($row['emails'] ?? []) as $one) {
        $e = jeab_kontakt_email((string) $one);
        if ($e !== '' && !in_array($e, $list, true)) {
            $list[] = $e;
        }
    }
    return array_values($list);
}

/**
 * Tar bort exakt en adress ur raden. Allt annat ligger kvar.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_kontakter_relink_case_strip_email(array $row, string $email): array
{
    $same = static fn(mixed $v): bool => !is_array($v) && !is_object($v) && jeab_kontakt_email((string) $v) === $email;
    foreach (['email', 'customer_email'] as $key) {
        if (array_key_exists($key, $row) && $same($row[$key])) {
            $row[$key] = '';
        }
    }
    if (is_array($row['emails'] ?? null)) {
        $row['emails'] = array_values(array_filter($row['emails'], static fn($v): bool => !$same($v)));
    }
    foreach (['owners', 'extra_owners'] as $list) {
        if (!is_array($row[$list] ?? null)) {
            continue;
        }
        foreach ($row[$list] as $i => $owner) {
            if (is_array($owner) && $same($owner['email'] ?? '')) {
                $owner['email'] = '';
                $row[$list][$i] = $owner;
            }
        }
    }
    return $row;
}

/**
 * Kort vy av en kontakt för svaret.
 *
 * @param array<string, mixed>|null $row
 * @return array<string, mixed>|null
 */
function jeab_kontakter_relink_case_view(?array $row): ?array
{
    if (!is_array($row)) {
        return null;
    }
    $phone = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $o) {
        if (is_array($o) && trim((string) ($o['phone'] ?? '')) !== '') {
            $phone = (string) $o['phone'];
            break;
        }
    }
    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($row) : (string) ($row['name'] ?? ''),
        'emails' => jeab_kontakter_relink_case_emails($row),
        'phone' => $phone,
        'street' => (string) ($row['street'] ?? ''),
        'postal_code' => (string) ($row['postal_code'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'company' => (string) ($row['company'] ?? ''),
    ];
}

/**
 * En kontakt som en tidigare körning skapade: source relink_case, samma namn och e-post.
 *
 * @param array<string, mixed> $row
 */
function jeab_kontakter_relink_case_is_ours(array $row, string $name, string $email): bool
{
    if ((string) ($row['source'] ?? '') !== JEAB_RELINK_CASE_RULE) {
        return false;
    }
    $rowName = function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($row) : (string) ($row['name'] ?? '');
    return mb_strtolower(trim($rowName)) === mb_strtolower($name)
        && in_array($email, jeab_kontakter_relink_case_emails($row), true);
}

function jeab_kontakter_relink_case_thread_exists(string $tid): bool
{
    if ($tid === '') {
        return false;
    }
    if (function_exists('jeab_inbox_contact_set_email_thread_exists')) {
        return jeab_inbox_contact_set_email_thread_exists($tid);
    }
    return function_exists('jeab_inkorg_threads_meta') && isset(jeab_inkorg_threads_meta()[$tid]);
}

/**
 * Trådens kontakt i registret. contact_id kan peka på en inkorgsrad som i sin tur har contact_id.
 *
 * @param array<string, mixed> $meta
 * @return array{raw:string,resolved:string,inbox_row:string,via:string}
 */
function jeab_kontakter_relink_case_thread_link(array $meta, array $contacts): array
{
    $raw = trim((string) ($meta['contact_id'] ?? ''));
    if ($raw === '') {
        return ['raw' => '', 'resolved' => '', 'inbox_row' => '', 'via' => 'none'];
    }
    if (isset($contacts[$raw])) {
        return ['raw' => $raw, 'resolved' => $raw, 'inbox_row' => '', 'via' => 'contact_id'];
    }
    $inbox = function_exists('jeab_kontakt_inbox_read') ? jeab_kontakt_inbox_read() : [];
    $row = $inbox[$raw] ?? null;
    $central = is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : '';
    return ['raw' => $raw, 'resolved' => $central !== '' ? $central : $raw, 'inbox_row' => is_array($row) ? $raw : '', 'via' => 'inbox_row'];
}

/** Sätter och läser tillbaka inkorg_list.contact_id. Tom sträng utan SQL-lista. */
function jeab_kontakter_relink_case_list_mirror(string $tid, string $contactId): string
{
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return '';
    }
    try {
        $store->assignListContact($tid, $contactId);
        $stmt = $store->pdo()->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
        $stmt->execute([$tid]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return '';
    }
    $row = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($row) ? trim((string) ($row['contact_id'] ?? '')) : '';
}

/**
 * Kontakten som Inkorg och prefill visar för tråden när contact_id saknas:
 * jeab_reminder_case_contact på jeab_reminder_case_bundle (e-post, inkorgsvy, telefon).
 */
function jeab_kontakter_relink_case_page_contact(string $tid): string
{
    if (!function_exists('jeab_reminder_case_contact') || !function_exists('jeab_reminder_case_bundle')) {
        require_once __DIR__ . '/reminders.php';
    }
    if (!function_exists('jeab_contacts_sole_inbox_view') && is_file(__DIR__ . '/contacts_sole.php')) {
        require_once __DIR__ . '/contacts_sole.php';
    }
    if (!function_exists('jeab_reminder_case_contact') || !function_exists('jeab_reminder_case_bundle')) {
        return '';
    }
    try {
        $found = jeab_reminder_case_contact(jeab_reminder_case_bundle($tid));
    } catch (Throwable) {
        return '';
    }
    return is_array($found) ? trim((string) ($found['id'] ?? '')) : '';
}

/**
 * Inkorgens kontaktrader som hör till ärendet: raden tråden pekar på, och rader med
 * contact_id = from som bär den borttagna adressen.
 *
 * @return list<string>
 */
function jeab_kontakter_relink_case_inbox_rows(string $from, string $email, string $threadRow, string $target = ''): array
{
    if (!function_exists('jeab_kontakt_inbox_read')) {
        return [];
    }
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        return [];
    }
    $out = [];
    foreach (jeab_kontakt_inbox_read() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['contact_id'] ?? ''));
        if ($cid !== $from || ($target !== '' && $cid === $target)) {
            continue;
        }
        $mine = (string) $id === $threadRow;
        if (!$mine) {
            foreach ((array) ($row['emails'] ?? []) as $one) {
                if (jeab_kontakt_email((string) $one) === $email) {
                    $mine = true;
                    break;
                }
            }
        }
        if ($mine) {
            $out[] = (string) $id;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_relink_case(array $input): array
{
    $write = jeab_kontakter_relink_case_wants_write($input);
    $base = ['action' => 'kontakter.relink_case', 'dry' => !$write, 'written' => false, 'would_send' => false];
    $tid = jeab_kontakter_relink_case_str($input['thread_id'] ?? '', 120);
    $from = jeab_kontakter_relink_case_str($input['from_contact_id'] ?? '', 80);
    $remove = jeab_kontakt_email(jeab_kontakter_relink_case_str($input['remove_email'] ?? ''));
    $newName = jeab_kontakter_relink_case_str($input['new_name'] ?? '', 120);
    $newEmail = jeab_kontakt_email(jeab_kontakter_relink_case_str($input['new_email'] ?? ''));
    $phoneRaw = jeab_kontakter_relink_case_str($input['new_phone'] ?? '', 40);
    $newPhone = $phoneRaw === '' ? '' : jeab_kontakt_phone($phoneRaw);
    $street = jeab_kontakter_relink_case_str($input['new_address'] ?? $input['new_street'] ?? '');
    $postal = jeab_kontakt_postal(jeab_kontakter_relink_case_str($input['postnummer'] ?? $input['new_postal'] ?? '', 20));
    $city = jeab_kontakt_city_name(jeab_kontakter_relink_case_str($input['ort'] ?? $input['new_city'] ?? '', 120));

    if ($tid === '' || $from === '' || $remove === '' || $newName === '' || $newEmail === '') {
        return ['ok' => false, 'error' => 'thread_id, from_contact_id, remove_email, new_name och new_email krävs.'] + $base;
    }
    if (filter_var($remove, FILTER_VALIDATE_EMAIL) === false || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'ogiltig e-post'] + $base;
    }
    if ($phoneRaw !== '' && $newPhone === '') {
        return ['ok' => false, 'error' => 'ogiltigt telefonnummer'] + $base;
    }
    if (!jeab_kontakter_relink_case_thread_exists($tid)) {
        return ['ok' => false, 'error' => 'not_found', 'thread_id' => $tid] + $base;
    }
    $contacts = jeab_kontakter_load();
    $fromRow = $contacts[$from] ?? null;
    if (!is_array($fromRow)) {
        return ['ok' => false, 'error' => 'from_contact_id finns inte', 'from_contact_id' => $from] + $base;
    }
    $meta = jeab_inkorg_thread_meta($tid);
    $link = jeab_kontakter_relink_case_thread_link($meta, $contacts);
    if ($link['raw'] === '') {
        // Inget sparat contact_id: Inkorg och prefill visar kontakten via e-post. Samma uppslag här.
        $page = jeab_kontakter_relink_case_page_contact($tid);
        if ($page !== '') {
            $link['resolved'] = $page;
            $link['via'] = 'page';
        }
    }
    $linkView = ['contact_id' => $link['raw'], 'via' => $link['via'], 'resolved' => $link['resolved']];
    $fromHolds = in_array($remove, jeab_kontakter_relink_case_emails($fromRow), true);

    // Andra kontakter (inte from) som redan har den nya adressen.
    $holders = [];
    foreach ($contacts as $cid => $row) {
        if ((string) $cid !== $from && is_array($row) && in_array($newEmail, jeab_kontakter_relink_case_emails($row), true)) {
            $holders[] = (string) $cid;
        }
    }

    $out = [
        'ok' => true,
        'thread_id' => $tid,
        'from_contact_id' => $from,
        'remove_email' => $remove,
        'already' => 0,
        'would_change' => 0,
        'changed' => 0,
    ] + $base;

    // Redan klart: tråden pekar på en annan kontakt som har adressen, och from har den inte.
    $resolvedRow = $link['resolved'] !== '' ? ($contacts[$link['resolved']] ?? null) : null;
    $resolvedOurs = !$fromHolds && $link['resolved'] !== $from && is_array($resolvedRow)
        && jeab_kontakter_relink_case_is_ours($resolvedRow, $newName, $newEmail);
    if ($resolvedOurs && $link['via'] !== 'page') {
        $out['already'] = 1;
        $out['new_contact_id'] = $link['resolved'];
        $out['thread'] = ['before' => $link['raw'], 'after' => $link['raw'], 'link_before' => $linkView];
        $out['contacts'] = [
            'from' => ['before' => jeab_kontakter_relink_case_view($fromRow), 'after' => jeab_kontakter_relink_case_view($fromRow)],
            'new' => ['before' => jeab_kontakter_relink_case_view($resolvedRow), 'after' => jeab_kontakter_relink_case_view($resolvedRow)],
        ];
        return $out;
    }
    if ($link['resolved'] !== $from && !$resolvedOurs) {
        return ['ok' => false, 'error' => 'tråden är inte kopplad till from_contact_id', 'thread_contact_id' => $link['raw'], 'thread_link' => $linkView] + $base;
    }

    // Tråden pekar på from. Antingen ska allt göras, eller så blev kontakterna klara men inte tråden.
    $reuse = '';
    if (!$fromHolds) {
        if (count($holders) !== 1 || !jeab_kontakter_relink_case_is_ours($contacts[$holders[0]], $newName, $newEmail)) {
            return ['ok' => false, 'error' => 'from_contact_id har inte remove_email', 'holders' => $holders] + $base;
        }
        $reuse = $holders[0];
    } elseif ($holders !== []) {
        return ['ok' => false, 'error' => 'new_email finns redan på en annan kontakt', 'holders' => $holders] + $base;
    }

    $company = jeab_kontakter_relink_case_str($input['company'] ?? $input['bolag'] ?? '', 40);
    if ($company === '') {
        $company = (string) ($fromRow['company'] ?? $meta['mailbox'] ?? '');
    }
    if ($reuse !== '') {
        $newRow = $contacts[$reuse];
    } else {
        $newRow = jeab_kontakt_blank([
            'name' => $newName,
            'email' => $newEmail,
            'phone' => $newPhone,
            'street' => $street,
            'street_key' => $street !== '' ? jeab_kontakt_street_key($street) : '',
            'postal_code' => $postal,
            'city' => $city,
            'kind' => 'person',
            'company' => $company,
            'source' => JEAB_RELINK_CASE_RULE,
        ]);
    }
    $newId = (string) $newRow['id'];
    $fromAfter = $fromHolds ? jeab_kontakter_relink_case_strip_email($fromRow, $remove) : $fromRow;
    $inboxRows = jeab_kontakter_relink_case_inbox_rows($from, $remove, $link['inbox_row'], $newId);

    $out['new_contact_id'] = $reuse !== '' ? $reuse : '(ny)';
    $out['reuse_existing'] = $reuse !== '' ? 1 : 0;
    $out['thread'] = ['before' => $link['raw'], 'after' => $reuse !== '' || $write ? $newId : '(ny)', 'link_before' => $linkView];
    $out['inbox_rows'] = $inboxRows;
    $out['contacts'] = [
        'from' => ['before' => jeab_kontakter_relink_case_view($fromRow), 'after' => jeab_kontakter_relink_case_view($fromAfter)],
        'new' => ['before' => $reuse !== '' ? jeab_kontakter_relink_case_view($newRow) : null, 'after' => jeab_kontakter_relink_case_view($newRow)],
    ];
    if ($out['contacts']['new']['after'] !== null && $reuse === '' && !$write) {
        $out['contacts']['new']['after']['id'] = '(ny)';
    }
    $out['address_shared_with_from'] = $street !== '' && jeab_kontakt_street_key($street) === (string) ($fromRow['street_key'] ?? jeab_kontakt_street_key((string) ($fromRow['street'] ?? ''))) ? 1 : 0;

    if (!$write) {
        $out['would_change'] = 1;
        return $out;
    }

    // Backup först: delad zip, sedan före-bild av de rader som ändras.
    if (!function_exists('jeab_cutover_ensure_tree_zip')) {
        require_once __DIR__ . '/data_cutover_all.php';
    }
    $zip = function_exists('jeab_cutover_ensure_tree_zip') ? jeab_cutover_ensure_tree_zip(true) : ['ok' => false];
    $zipPath = !empty($zip['ok']) ? (string) ($zip['path'] ?? '') : '';
    if ($zipPath === '') {
        return ['ok' => false, 'error' => 'Backup-zipen saknas. Inget ändrat.'] + $base;
    }
    $out['backup_zip'] = basename($zipPath);
    $dir = rtrim(jeab_data_dir(), '/') . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $inboxBefore = [];
    if ($inboxRows !== [] && function_exists('jeab_kontakt_inbox_read')) {
        $allInbox = jeab_kontakt_inbox_read();
        foreach ($inboxRows as $iid) {
            $inboxBefore[$iid] = $allInbox[$iid] ?? null;
        }
    }
    $file = $dir . '/kontakter-relink-case-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
    $json = json_encode([
        'thread_id' => $tid,
        'thread_contact_id' => $link['raw'],
        'contacts' => [$from => $fromRow] + ($reuse !== '' ? [$reuse => $newRow] : []),
        'inbox_rows' => $inboxBefore,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($file, $json, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Före-bilden gick inte att skriva. Inget ändrat.'] + $base;
    }
    $out['backup'] = basename($file);

    // Kontakterna i en transaktion. Ny kontakt tvingas in med eget id. Ingen matchning.
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $done = jeab_kontakter_mutate(static function (array &$all) use ($from, $remove, $fromHolds, $reuse, $newRow, $newId, $now): bool {
        $cur = $all[$from] ?? null;
        if (!is_array($cur)) {
            return false;
        }
        if ($fromHolds) {
            if (!in_array($remove, jeab_kontakter_relink_case_emails($cur), true)) {
                return false;
            }
            $cur = jeab_kontakter_relink_case_strip_email($cur, $remove);
            $cur['updated_at'] = $now;
            $all[$from] = $cur;
        }
        if ($reuse === '') {
            if (isset($all[$newId])) {
                return false;
            }
            $all[$newId] = $newRow;
        }
        return true;
    });
    if ($done !== true) {
        return ['ok' => false, 'error' => 'Kontakten ändrades under körningen. Inget ändrat.', 'backup' => $out['backup']] + $base;
    }

    jeab_inkorg_thread_write($tid, static function (array $t) use ($newId): array {
        $t['contact_id'] = $newId;
        return $t;
    });
    // Inkorg-listans spegel (inkorg_list.contact_id + row_json). thread_write uppdaterar den på SQL,
    // men refresh sväljer fel, så samma värde sätts uttryckligen.
    $out['list_contact_id'] = jeab_kontakter_relink_case_list_mirror($tid, $newId);
    if ($inboxRows !== [] && function_exists('jeab_kontakt_inbox_locked')) {
        $only = array_fill_keys($inboxRows, true);
        jeab_kontakt_inbox_locked(static function (?array $d) use ($only, $from, $newId): array {
            $d = $d ?? ['contacts' => []];
            foreach ((array) ($d['contacts'] ?? []) as $id => $row) {
                if (is_array($row) && isset($only[(string) $id]) && trim((string) ($row['contact_id'] ?? '')) === $from) {
                    $row['contact_id'] = $newId;
                    $d['contacts'][$id] = $row;
                }
            }
            return [true, $d];
        });
    }
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget(null);
    }

    $fresh = jeab_kontakter_load();
    $out['written'] = true;
    $out['changed'] = 1;
    $out['new_contact_id'] = $newId;
    $out['thread']['after'] = trim((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? ''));
    $out['contacts']['from']['after'] = jeab_kontakter_relink_case_view($fresh[$from] ?? null);
    $out['contacts']['new']['after'] = jeab_kontakter_relink_case_view($fresh[$newId] ?? null);

    $audit = json_encode([
        'time' => $now,
        'thread_id' => $tid,
        'from' => $from,
        'to' => $newId,
        'removed_email' => $remove,
        'created' => $reuse === '',
        'inbox_rows' => $inboxRows,
        'backup' => $out['backup'],
        'backup_zip' => $out['backup_zip'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($audit)) {
        @file_put_contents(rtrim(jeab_data_dir(), '/') . '/kontakter-relink-case.jsonl', $audit . "\n", FILE_APPEND | LOCK_EX);
    }
    if (function_exists('jeab_kontakt_dedupe_log')) {
        jeab_kontakt_dedupe_log($from, $newId, JEAB_RELINK_CASE_RULE, 'ärende ' . $tid, 'jeab_kontakter_relink_case');
    }
    return $out;
}
