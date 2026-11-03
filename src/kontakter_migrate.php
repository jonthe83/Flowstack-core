<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Flyttar inkorgens kontakter (contacts.json) till kontaktregistret.
 * Hook kontakter.migrate_inbox_contacts, dry=1 som standard.
 * Filen lämnas kvar. En lyckad dry=0 skriver contacts.json.migrated
 * och därefter läser och skriver alla vägar registret i stället.
 */

function jeab_kontakt_migrate_marker_path(): string
{
    if (!function_exists('jeab_inkorg_dir')) {
        return '';
    }
    return jeab_inkorg_dir() . '/contacts.json.migrated';
}

function jeab_inkorg_contacts_archived(): bool
{
    $path = jeab_kontakt_migrate_marker_path();
    return $path !== '' && is_file($path);
}

function jeab_inkorg_contacts_url(string $id = ''): string
{
    $archived = jeab_inkorg_contacts_archived();
    if ($archived && $id !== '' && !str_starts_with($id, 'kt_') && function_exists('jeab_kontakter_load')) {
        $found = jeab_kontakt_migrate_already(jeab_kontakter_load(), $id);
        if ($found !== null) {
            $id = $found;
        }
    }
    $query = ['p' => $archived ? 'kontakter' : 'inkorg-kontakter'];
    if ($id !== '') {
        $query[$archived ? 'id' : 'c'] = $id;
    }
    $href = 'index.php?' . http_build_query($query);
    return function_exists('jeab_url') ? jeab_url($href) : $href;
}

/**
 * @return array<string, array<string, mixed>>
 */
function jeab_kontakt_migrate_read_old(): array
{
    if (function_exists('jeab_kontakt_inbox_read')) {
        return jeab_kontakt_inbox_read();
    }
    if (!function_exists('jeab_inkorg_dir')) {
        return [];
    }
    $path = jeab_inkorg_dir() . '/contacts.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    $rows = is_array($data) ? (array) ($data['contacts'] ?? []) : [];
    $out = [];
    foreach ($rows as $id => $row) {
        if (is_array($row)) {
            $out[(string) $id] = $row;
        }
    }
    return $out;
}

function jeab_kontakt_migrate_is_secret(string $value): bool
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    return strlen($digits) >= 10 && preg_match('/\d{6}-?\d{4}/', $value) === 1;
}

/** @param array<string, mixed> $row */
function jeab_kontakt_migrate_is_empty(array $row): bool
{
    foreach (['name', 'address', 'town', 'city', 'mobile', 'phone', 'notes', 'email'] as $key) {
        if (trim((string) ($row[$key] ?? '')) !== '') {
            return false;
        }
    }
    foreach ((array) ($row['emails'] ?? []) as $email) {
        if (trim((string) $email) !== '') {
            return false;
        }
    }
    $known = [
        'id', 'name', 'emails', 'email', 'mobile', 'phone', 'address', 'town', 'city', 'notes',
        'created_at', 'updated_at', 'test', 'source', 'contact_id',
    ];
    foreach (array_keys(jeab_kontakt_external_ref_keys()) as $key) {
        $known[] = $key;
    }
    foreach ($row as $key => $value) {
        if (in_array((string) $key, $known, true) || is_array($value)) {
            continue;
        }
        if (trim((string) $value) !== '') {
            return false;
        }
    }
    foreach (array_keys(jeab_kontakt_external_ref_keys()) as $key) {
        if (trim((string) ($row[$key] ?? '')) !== '') {
            return false;
        }
    }
    return true;
}

/** @param array<string, mixed> $row @return list<string> */
function jeab_kontakt_migrate_email_list(array $row): array
{
    $raw = [];
    if (is_array($row['emails'] ?? null)) {
        $raw = $row['emails'];
    }
    if (trim((string) ($row['email'] ?? '')) !== '') {
        $raw[] = $row['email'];
    }
    $out = [];
    foreach ($raw as $email) {
        $email = jeab_kontakt_email((string) $email);
        if ($email === '' || !str_contains($email, '@') || isset($out[$email]) || jeab_kontakt_migrate_is_secret($email)) {
            continue;
        }
        $out[$email] = true;
    }
    return array_keys($out);
}

/**
 * @return array{street:string,postal:string,city:string}
 */
function jeab_kontakt_migrate_parse_address(string $address, string $town): array
{
    $postal = '';
    if (preg_match('/\b(\d{3})\s?(\d{2})\b/', $address, $m) === 1) {
        $postal = $m[1] . $m[2];
        $address = str_replace($m[0], ' ', $address);
    }
    $address = trim(preg_replace('/[,]+/u', ' ', $address) ?? $address);
    $address = trim(preg_replace('/\s+/u', ' ', $address) ?? $address);
    $city = jeab_kontakt_city_name(trim($town));
    return [
        'street' => $address,
        'postal' => jeab_kontakt_postal($postal),
        'city' => $city,
    ];
}

/** @param array<string, mixed> $row */
function jeab_kontakt_migrate_trace(array $row, string $oldId, string $date): string
{
    $parts = [];
    $push = static function (string $text) use (&$parts): void {
        $text = trim($text);
        if ($text === '' || jeab_kontakt_migrate_is_secret($text)) {
            return;
        }
        $parts[] = $text;
    };
    $push((string) ($row['name'] ?? ''));
    $push((string) ($row['address'] ?? ''));
    $push((string) ($row['town'] ?? ''));
    $emails = jeab_kontakt_migrate_email_list($row);
    if ($emails !== []) {
        $push($emails[0]);
        if (count($emails) > 1) {
            $push('även ' . implode(' ', array_slice($emails, 1)));
        }
    }
    $rawPhone = trim((string) ($row['mobile'] ?? ($row['phone'] ?? '')));
    $phone = jeab_kontakt_migrate_phone($rawPhone);
    $push($phone !== '' ? jeab_kontakt_phone_display($phone) : $rawPhone);
    $notes = trim((string) ($row['notes'] ?? ''));
    if ($notes !== '' && !jeab_kontakt_migrate_is_secret($notes)) {
        $push('anteckning: ' . $notes);
    }
    foreach (jeab_kontakt_external_ref_labels($row) as $label) {
        $push($label);
    }
    $known = [
        'id', 'name', 'emails', 'email', 'mobile', 'phone', 'address', 'town', 'city', 'notes',
        'created_at', 'updated_at', 'test', 'source', 'contact_id',
    ];
    foreach (array_keys(jeab_kontakt_external_ref_keys()) as $key) {
        $known[] = $key;
    }
    foreach ($row as $key => $value) {
        if (in_array((string) $key, $known, true) || is_array($value)) {
            continue;
        }
        $text = trim((string) $value);
        if ($text === '' || jeab_kontakt_migrate_is_secret($text)) {
            continue;
        }
        $push((string) $key . ': ' . $text);
    }
    $push('inkorg ' . $oldId);
    return $date . ' från inkorgen: ' . implode(', ', $parts);
}

function jeab_kontakt_migrate_phone(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || jeab_kontakt_migrate_is_secret($raw)) {
        return '';
    }
    if (function_exists('jeab_inkorg_phone_norm')) {
        $norm = jeab_inkorg_phone_norm($raw);
        if ($norm !== '') {
            return $norm;
        }
    }
    return '';
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_kontakt_migrate_virtual(array $row, string $oldId, string $date): array
{
    $parsed = jeab_kontakt_migrate_parse_address((string) ($row['address'] ?? ''), (string) ($row['town'] ?? ($row['city'] ?? '')));
    $emails = jeab_kontakt_migrate_email_list($row);
    $primary = $emails[0] ?? '';
    $name = trim((string) ($row['name'] ?? ''));
    $phone = jeab_kontakt_migrate_phone((string) ($row['mobile'] ?? ($row['phone'] ?? '')));
    $made = jeab_kontakt_blank([
        'name' => $name,
        'email' => $primary,
        'phone' => $phone,
        'street' => $parsed['street'],
        'street_key' => $parsed['street'] !== '' ? jeab_kontakt_street_key($parsed['street']) : '',
        'postal_code' => $parsed['postal'],
        'city' => $parsed['city'],
        'kind' => jeab_kontakt_company_name($name) ? 'company' : 'person',
        'source' => trim((string) ($row['source'] ?? '')),
    ]);
    $stamp = trim((string) ($row['updated_at'] ?? ''));
    if ($stamp === '') {
        $stamp = trim((string) ($row['created_at'] ?? ''));
    }
    if ($stamp !== '') {
        $made['updated_at'] = $stamp;
        $created = trim((string) ($row['created_at'] ?? ''));
        $made['created_at'] = $created !== '' ? $created : $stamp;
    }
    $made['ovrigt'] = jeab_kontakt_migrate_trace($row, $oldId, $date);
    return $made;
}

/**
 * @param array<string, array<string, mixed>> $contacts
 */
function jeab_kontakt_migrate_already(array $contacts, string $oldId): ?string
{
    if ($oldId === '') {
        return null;
    }
    $needle = 'inkorg ' . $oldId;
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        if (str_contains((string) ($row['ovrigt'] ?? ''), $needle)) {
            return (string) $id;
        }
    }
    return null;
}

/**
 * @param array<string, string> $oldToVirtual
 * @param list<array{from:string,to:string,rule:string,reason:string}> $merges
 * @param list<string> $fresh
 * @return array{moved:int,merged:int,ovrigt:int,old_to_winner:array<string,string>}
 */
function jeab_kontakt_migrate_tally(array $oldToVirtual, array $merges, array $fresh): array
{
    $freshSet = array_fill_keys($fresh, true);
    $moved = 0;
    $merged = 0;
    $oldToWinner = [];
    foreach ($oldToVirtual as $old => $virtual) {
        $winner = jeab_kontakt_dedupe_follow($virtual, $merges);
        $oldToWinner[$old] = $winner;
        if (!isset($freshSet[$old])) {
            continue;
        }
        if ($winner === $virtual) {
            $moved++;
        } else {
            $merged++;
        }
    }
    return [
        'moved' => $moved,
        'merged' => $merged,
        'ovrigt' => $moved + $merged,
        'old_to_winner' => $oldToWinner,
    ];
}

/**
 * @param array<string, string> $oldToWinner
 */
function jeab_kontakt_migrate_links(array $oldToWinner, bool $write): int
{
    $links = 0;
    if (function_exists('jeab_inkorg_threads_meta')) {
        foreach (jeab_inkorg_threads_meta() as $tid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $current = (string) ($row['contact_id'] ?? '');
            $winner = $oldToWinner[$current] ?? '';
            if ($winner === '' || $winner === $current) {
                continue;
            }
            if ($write && function_exists('jeab_inkorg_thread_write')) {
                jeab_inkorg_thread_write((string) $tid, static function (array $thread) use ($winner): array {
                    $thread['contact_id'] = $winner;
                    return $thread;
                });
            }
            $links++;
        }
    }
    if (function_exists('jeab_inkorg_threads') && function_exists('jeab_inkorg_contact_for_thread')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            if ((string) ($thread['contact_id'] ?? '') !== '') {
                continue;
            }
            $found = jeab_inkorg_contact_for_thread($thread);
            $old = is_array($found) ? (string) ($found['id'] ?? '') : '';
            $winner = $oldToWinner[$old] ?? '';
            if ($winner === '') {
                continue;
            }
            if ($write && function_exists('jeab_inkorg_thread_write')) {
                jeab_inkorg_thread_write((string) $thread['id'], static function (array $row) use ($winner): array {
                    $row['contact_id'] = $winner;
                    return $row;
                });
            }
            $links++;
        }
    }
    if (function_exists('jeab_hs_import_index')) {
        foreach (jeab_hs_import_index()['by_id'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $current = (string) ($row['contact_id'] ?? '');
            $winner = $oldToWinner[$current] ?? '';
            if ($winner === '' || $winner === $current) {
                continue;
            }
            if ($write) {
                $links += jeab_kontakt_repoint_hs($current, $winner);
            } else {
                $links++;
            }
        }
    }
    return $links;
}

/**
 * @param array<string, mixed> $counts
 */
function jeab_kontakt_migrate_write_marker(array $counts): void
{
    $path = jeab_kontakt_migrate_marker_path();
    if ($path === '' || is_file($path)) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Inkorgens kontakter kunde inte arkiveras.');
    }
    $payload = [
        'time' => function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
        'counts' => [
            'moved' => (int) ($counts['moved'] ?? 0),
            'merged' => (int) ($counts['merged'] ?? 0),
            'ovrigt' => (int) ($counts['ovrigt'] ?? 0),
            'links' => (int) ($counts['links'] ?? 0),
        ],
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('Inkorgens kontakter kunde inte arkiveras.');
    }
    $fh = @fopen($path, 'x');
    if ($fh === false) {
        return;
    }
    fwrite($fh, $json . "\n");
    fclose($fh);
}

/**
 * @param array<string, string> $oldToVirtual
 * @param list<array{from:string,to:string,rule:string,reason:string}> $merges
 * @return array{moves:list<array{from:string,to:string,rule:string}>,uncertain:list<array{ids:list<string>,rule:string,reason:string}>}
 */
function jeab_kontakt_migrate_public_plan(array $oldToVirtual, array $merges, array $uncertain): array
{
    $virtualToOld = [];
    foreach ($oldToVirtual as $old => $virtual) {
        $virtualToOld[$virtual] = $old;
    }
    $label = static function (string $id) use ($virtualToOld): string {
        return $virtualToOld[$id] ?? $id;
    };
    $moves = [];
    foreach ($merges as $merge) {
        $moves[] = [
            'from' => $label((string) ($merge['from'] ?? '')),
            'to' => $label((string) ($merge['to'] ?? '')),
            'rule' => (string) ($merge['rule'] ?? ''),
        ];
    }
    $listed = [];
    foreach ($uncertain as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ids = [];
        foreach ((array) ($row['ids'] ?? []) as $id) {
            $ids[] = $label((string) $id);
        }
        $listed[] = [
            'ids' => $ids,
            'rule' => (string) ($row['rule'] ?? ''),
            'reason' => (string) ($row['reason'] ?? ''),
        ];
    }
    return ['moves' => $moves, 'uncertain' => $listed];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_migrate_inbox(array $input, ?JeabStore $store = null): array
{
    return jeab_kontakt_quiet_call(static function () use ($input, $store): array {
        $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
        $store = $store instanceof JeabStore ? $store : null;
        $dry = function_exists('jeab_kontakt_dry') ? jeab_kontakt_dry($input) : true;
        $zero = ['moved' => 0, 'merged' => 0, 'ovrigt' => 0, 'links' => 0];
        if (jeab_inkorg_contacts_archived()) {
            return ['ok' => true, 'dry' => $dry, 'archived' => true, 'moves' => [], 'uncertain' => [], 'counts' => $zero];
        }
        $old = jeab_kontakt_migrate_read_old();
        $date = substr((function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s')), 0, 10);
        $base = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
        $work = $base;
        $oldToVirtual = [];
        $fresh = [];
        foreach ($old as $oldId => $row) {
            if (jeab_kontakt_migrate_is_empty($row)) {
                continue;
            }
            $have = jeab_kontakt_migrate_already($work, $oldId);
            if ($have !== null) {
                $oldToVirtual[$oldId] = $have;
                continue;
            }
            $virtual = jeab_kontakt_migrate_virtual($row, $oldId, $date);
            $work[(string) $virtual['id']] = $virtual;
            $oldToVirtual[$oldId] = (string) $virtual['id'];
            $fresh[] = $oldId;
        }
        $folded = jeab_kontakt_dedupe_plan($work, $store);
        if (!$dry && function_exists('jeab_kontakter_mutate')) {
            $applied = jeab_kontakter_mutate(static function (array &$live) use ($old, $date, $store): array {
                $map = [];
                $freshIds = [];
                foreach ($old as $oldId => $row) {
                    if (!is_array($row) || jeab_kontakt_migrate_is_empty($row)) {
                        continue;
                    }
                    $have = jeab_kontakt_migrate_already($live, $oldId);
                    if ($have !== null) {
                        $map[$oldId] = $have;
                        continue;
                    }
                    $virtual = jeab_kontakt_migrate_virtual($row, $oldId, $date);
                    $live[(string) $virtual['id']] = $virtual;
                    $map[$oldId] = (string) $virtual['id'];
                    $freshIds[] = $oldId;
                }
                $merges = jeab_kontakt_dedupe_fold($live, $store);
                return ['map' => $map, 'fresh' => $freshIds, 'merges' => $merges];
            });
            $applied = is_array($applied) ? $applied : [];
            $oldToVirtual = is_array($applied['map'] ?? null) ? $applied['map'] : [];
            $fresh = is_array($applied['fresh'] ?? null) ? $applied['fresh'] : [];
            $folded['merges'] = is_array($applied['merges'] ?? null) ? $applied['merges'] : [];
            $live = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
            $folded['uncertain'] = jeab_kontakt_dedupe_plan($live, $store)['uncertain'];
            jeab_kontakt_dedupe_finish($folded['merges'], $store);
        }
        $tally = jeab_kontakt_migrate_tally($oldToVirtual, $folded['merges'], $fresh);
        $links = jeab_kontakt_migrate_links($tally['old_to_winner'], !$dry);
        $counts = [
            'moved' => $tally['moved'],
            'merged' => $tally['merged'],
            'ovrigt' => $tally['ovrigt'],
            'links' => $links,
        ];
        if (!$dry) {
            jeab_kontakt_migrate_write_marker($counts);
        }
        if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_mirror_inbox_all')) {
            $soleInbox = jeab_contacts_sole_mirror_inbox_all($dry);
            $counts['sole_inbox'] = $dry ? (int) ($soleInbox['would'] ?? 0) : (int) ($soleInbox['written'] ?? 0);
        }
        $public = jeab_kontakt_migrate_public_plan($oldToVirtual, $folded['merges'], $folded['uncertain']);
        return [
            'ok' => true,
            'dry' => $dry,
            'archived' => !$dry,
            'moves' => $public['moves'],
            'uncertain' => $public['uncertain'],
            'counts' => $counts,
        ];
    });
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_migrate_inbox_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_kontakter_migrate_inbox($input);
    $result = function_exists('jeab_kontakt_strip_secrets') ? jeab_kontakt_strip_secrets($result) : $result;
    $result['status'] = !empty($result['ok']) ? 200 : 400;
    return $result;
}

/**
 * Inkorgens kontaktlista efter arkivering: samma fält, id från registret.
 *
 * @return array<string, array<string, mixed>>
 */
function jeab_inkorg_contacts_projected(): array
{
    if (!function_exists('jeab_kontakter_load')) {
        return [];
    }
    $out = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[(string) $id] = jeab_inkorg_contact_project((string) $id, $row);
    }
    return $out;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_project(string $id, array $row): array
{
    $name = '';
    $phone = '';
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if ($name === '' || !empty($owner['contact'])) {
            $ownerName = trim((string) ($owner['name'] ?? ''));
            if ($ownerName !== '') {
                $name = $ownerName;
            }
        }
        if (!empty($owner['contact'])) {
            $phone = jeab_kontakt_phone((string) ($owner['phone'] ?? ''));
        }
    }
    $emails = jeab_kontakt_row_emails($row);
    if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', (string) ($row['ovrigt'] ?? ''), $found) > 0) {
        foreach ($found[0] as $email) {
            $email = jeab_kontakt_email((string) $email);
            if ($email !== '' && !in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }
    }
    return [
        'id' => $id,
        'name' => $name,
        'emails' => $emails,
        'mobile' => $phone,
        'address' => trim((string) ($row['street'] ?? '')),
        'town' => trim((string) ($row['city'] ?? '')),
        'notes' => (string) ($row['ovrigt'] ?? ''),
        'contact_id' => $id,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'source' => (string) ($row['source'] ?? ''),
    ];
}

/**
 * Nytt mejl efter arkivering. Slår upp i registret utan postort-uppslag.
 *
 * @param array<string, mixed> $msg
 */
function jeab_kontakt_inbox_upsert(string $email, string $name, string $phone, string $address, string $town, array $msg): ?string
{
    $email = jeab_kontakt_email($email);
    if ($email === '' || (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email))) {
        return null;
    }
    $parsed = jeab_kontakt_migrate_parse_address($address, $town);
    $phone = jeab_kontakt_migrate_phone($phone);
    $hint = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'street' => $parsed['street'],
        'street_key' => $parsed['street'] !== '' ? jeab_kontakt_street_key($parsed['street']) : '',
        'postal_code' => $parsed['postal'],
        'city' => $parsed['city'],
        'kind' => jeab_kontakt_company_name($name) ? 'company' : 'person',
        'source' => trim((string) ($msg['source'] ?? '')),
    ];
    $madeId = '';
    $applied = [];
    jeab_kontakt_quiet_call(static function () use ($hint, $email, $name, $phone, &$madeId, &$applied): void {
        $saved = jeab_kontakter_mutate(static function (array &$contacts) use ($hint, $email, $name, $phone, &$applied): ?array {
            $virtual = [
                'street' => $hint['street'],
                'street_key' => $hint['street_key'],
                'city' => $hint['city'],
                'kind' => $hint['kind'],
                'owners' => [[
                    'name' => $name,
                    'personnummer' => '',
                    'contact' => true,
                    'email' => $email,
                    'phone' => $phone,
                ]],
            ];
            $id = jeab_kontakt_dedupe_match_contact($contacts, $virtual);
            if ($id === null) {
                foreach ($contacts as $cid => $row) {
                    if (!is_array($row) || !in_array($email, jeab_inkorg_contact_project((string) $cid, $row)['emails'], true)) {
                        continue;
                    }
                    if (jeab_kontakt_dedupe_pair_block($virtual, $row, 'email') !== '') {
                        continue;
                    }
                    $id = (string) $cid;
                    break;
                }
            }
            if ($id !== null && isset($contacts[$id]) && is_array($contacts[$id])) {
                $contacts[$id] = jeab_kontakt_inbox_fill($contacts[$id], $hint);
                $contacts[$id]['id'] = $id;
                $made = $contacts[$id];
            } else {
                $made = jeab_kontakt_blank($hint);
                $contacts[(string) $made['id']] = $made;
            }
            $store = function_exists('jeab_store') ? jeab_store() : null;
            $applied = jeab_kontakt_dedupe_fold($contacts, $store instanceof JeabStore ? $store : null);
            $final = jeab_kontakt_dedupe_follow((string) ($made['id'] ?? ''), $applied);
            if (isset($contacts[$final]) && is_array($contacts[$final])) {
                return $contacts[$final];
            }
            return $made;
        });
        if (is_array($saved)) {
            $madeId = (string) ($saved['id'] ?? '');
        }
        if ($applied !== []) {
            jeab_kontakt_dedupe_finish($applied);
        }
    });
    return $madeId !== '' ? $madeId : null;
}

/**
 * Fyll bara tomma fält. Ett redigerat namn eller en satt adress skrivs inte över.
 *
 * @param array<string, mixed> $row
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_kontakt_inbox_fill(array $row, array $hint): array
{
    $row = jeab_kontakt_fill_blanks($row, $hint);
    $owners = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }
    if ($owners === []) {
        $owners = [['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => '']];
    }
    $slot = 0;
    foreach ($owners as $i => $owner) {
        if (!empty($owner['contact'])) {
            $slot = $i;
            break;
        }
    }
    $name = trim((string) ($hint['name'] ?? ''));
    if (trim((string) ($owners[$slot]['name'] ?? '')) === '' && $name !== '') {
        $owners[$slot]['name'] = $name;
    }
    $email = jeab_kontakt_email((string) ($hint['email'] ?? ''));
    if ($email !== '' && trim((string) ($owners[$slot]['email'] ?? '')) === '') {
        $owners[$slot]['email'] = $email;
    } elseif ($email !== '' && !in_array($email, jeab_inkorg_contact_project((string) ($row['id'] ?? ''), $row)['emails'], true)) {
        $row['ovrigt'] = jeab_kontakt_append_ovrigt((string) ($row['ovrigt'] ?? ''), 'även ' . $email);
    }
    $phone = jeab_kontakt_migrate_phone((string) ($hint['phone'] ?? ''));
    if ($phone !== '' && trim((string) ($owners[$slot]['phone'] ?? '')) === '') {
        $owners[$slot]['phone'] = $phone;
    }
    $row['owners'] = $owners;
    return $row;
}

/**
 * Formuläret från den gamla kontaktsidan, sparat i registret.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_kontakt_inbox_save(array $in): array
{
    $id = trim((string) ($in['id'] ?? ''));
    $emails = array_values(array_unique(array_filter(array_map(
        static fn($email): string => jeab_kontakt_email((string) $email),
        preg_split('/[\s,;]+/', (string) ($in['emails'] ?? '')) ?: []
    ), static fn(string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    $mobileRaw = trim((string) ($in['mobile'] ?? ''));
    $mobile = $mobileRaw === '' ? '' : jeab_kontakt_migrate_phone($mobileRaw);
    if ($mobileRaw !== '' && $mobile === '') {
        throw new InvalidArgumentException('Mobilnumret ser inte svenskt ut (07x eller +46).');
    }
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
    if ($name === '' && $emails === []) {
        throw new InvalidArgumentException('Ange namn eller e-post.');
    }
    $address = mb_substr(trim((string) ($in['address'] ?? '')), 0, 200);
    $town = mb_substr(trim((string) ($in['town'] ?? '')), 0, 80);
    $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 4000);
    $parsed = jeab_kontakt_migrate_parse_address($address, $town);
    $saved = null;
    $applied = [];
    jeab_kontakt_quiet_call(static function () use ($id, $name, $emails, $mobile, $notes, $parsed, &$saved, &$applied): void {
        $saved = jeab_kontakter_mutate(static function (array &$contacts) use ($id, $name, $emails, $mobile, $notes, $parsed, &$applied): array {
            $key = $id;
            if ($key !== '' && !isset($contacts[$key])) {
                $found = jeab_kontakt_migrate_already($contacts, $key);
                if ($found === null) {
                    throw new InvalidArgumentException('Kontakten finns inte.');
                }
                $key = $found;
            }
            if ($key === '') {
                $row = jeab_kontakt_blank([
                    'name' => $name,
                    'email' => $emails[0] ?? '',
                    'phone' => $mobile,
                    'street' => $parsed['street'],
                    'street_key' => $parsed['street'] !== '' ? jeab_kontakt_street_key($parsed['street']) : '',
                    'postal_code' => $parsed['postal'],
                    'city' => $parsed['city'],
                    'kind' => jeab_kontakt_company_name($name) ? 'company' : 'person',
                ]);
            } else {
                $row = is_array($contacts[$key] ?? null) ? $contacts[$key] : jeab_kontakt_blank(['name' => $name]);
                $row['id'] = $key;
            }
            $row['street'] = $parsed['street'];
            $row['street_key'] = $parsed['street'] !== '' ? jeab_kontakt_street_key($parsed['street']) : '';
            $row['postal_code'] = $parsed['postal'];
            $row['city'] = $parsed['city'];
            $row['ovrigt'] = $notes;
            $owners = [];
            foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
                if (is_array($owner)) {
                    $owners[] = $owner;
                }
            }
            if ($owners === []) {
                $owners = [['name' => '', 'personnummer' => '', 'contact' => true, 'email' => '', 'phone' => '']];
            }
            $slot = 0;
            foreach ($owners as $i => $owner) {
                if (!empty($owner['contact'])) {
                    $slot = $i;
                    break;
                }
            }
            $owners[$slot]['name'] = $name;
            $owners[$slot]['contact'] = true;
            $owners[$slot]['email'] = $emails[0] ?? '';
            $owners[$slot]['phone'] = $mobile;
            $row['owners'] = $owners;
            if (count($emails) > 1) {
                $row['ovrigt'] = jeab_kontakt_append_ovrigt((string) $row['ovrigt'], 'även ' . implode(' ', array_slice($emails, 1)));
            }
            $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
            $contacts[(string) $row['id']] = $row;
            $store = function_exists('jeab_store') ? jeab_store() : null;
            $applied = jeab_kontakt_dedupe_fold($contacts, $store instanceof JeabStore ? $store : null);
            $final = jeab_kontakt_dedupe_follow((string) $row['id'], $applied);
            if (isset($contacts[$final]) && is_array($contacts[$final])) {
                return $contacts[$final];
            }
            return $row;
        });
        if ($applied !== []) {
            jeab_kontakt_dedupe_finish($applied);
        }
    });
    if (!is_array($saved)) {
        throw new RuntimeException('Kontakten kunde inte sparas.');
    }
    return jeab_inkorg_contact_project((string) ($saved['id'] ?? ''), $saved);
}
