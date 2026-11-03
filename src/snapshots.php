<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Skickad offert, Fortnox-dokument, fullmakt och sent_log bär sin egen kopia
 * av namn, adress, e-post, telefon och ägare. Omskick läser kopian.
 * Ny version (new_version=1) tar en ny kopia vid själva skicket.
 * snapshots.audit räknar bara. snapshots.backfill fyller saknade kopior
 * från det som redan ligger på raden, aldrig från kontakten.
 * Svaren har inga personnummer.
 */

/** @return list<string> */
function jeab_snapshots_types(): array
{
    return ['offert', 'invoice', 'fullmakt', 'mail'];
}

/** @return list<string> */
function jeab_snapshots_companies(): array
{
    return ['jarfalla', 'malmo', 'none'];
}

function jeab_snapshots_company(string $raw): string
{
    $fold = function_exists('mb_strtolower') ? mb_strtolower(trim($raw), 'UTF-8') : strtolower(trim($raw));
    $fold = strtr($fold, ['ä' => 'a', 'å' => 'a', 'ö' => 'o', 'é' => 'e']);
    if (in_array($fold, ['jarfalla', 'jeab'], true)) {
        return 'jarfalla';
    }
    if (in_array($fold, ['malmo', 'meab'], true)) {
        return 'malmo';
    }

    return 'none';
}

/**
 * @param array<string, mixed> $input
 */
function jeab_snapshots_wants_new(array $input): bool
{
    foreach (['new_version', 'ny_version'] as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $value = $input[$key];
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
    }

    return false;
}

function jeab_snapshots_now(): string
{
    return function_exists('jeab_now') ? jeab_now() : date('c');
}

function jeab_snapshots_text(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    return trim((string) $value);
}

/**
 * @param array<string, mixed> $row
 */
function jeab_snapshots_pick(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $value = jeab_snapshots_text($row[$key] ?? '');
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $owner
 * @return array{name:string,address:string,email:string,phone:string}
 */
function jeab_snapshots_owner(array $owner): array
{
    return [
        'name' => jeab_snapshots_pick($owner, ['name', 'namn']),
        'address' => jeab_snapshots_pick($owner, ['address', 'adress', 'display']),
        'email' => jeab_snapshots_pick($owner, ['email', 'epost', 'e-post', 'mejl']),
        'phone' => jeab_snapshots_pick($owner, ['phone', 'telefon', 'tel', 'mobil']),
    ];
}

/**
 * @param mixed $raw
 * @return list<array{name:string,address:string,email:string,phone:string}>
 */
function jeab_snapshots_owner_list(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $one = jeab_snapshots_owner($owner);
        if ($one['name'] === '' && $one['address'] === '' && $one['email'] === '' && $one['phone'] === '') {
            continue;
        }
        $key = strtolower($one['name'] . '|' . $one['email'] . '|' . $one['phone']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $one;
        if (count($out) >= 4) {
            break;
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $snap
 * @return array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}
 */
function jeab_snapshots_normalize(array $snap): array
{
    $owners = jeab_snapshots_owner_list($snap['owners'] ?? []);
    $source = jeab_snapshots_text($snap['source'] ?? '');
    if (!in_array($source, ['send', 'item', 'version', 'backfill'], true)) {
        $source = 'send';
    }

    $email = jeab_snapshots_pick($snap, ['email', 'epost', 'customer_email']);
    $recipient = jeab_snapshots_text($snap['recipient'] ?? '');
    if ($email === '' && $recipient !== '') {
        $email = $recipient;
    }
    $out = [
        'name' => jeab_snapshots_pick($snap, ['name', 'namn', 'customer_name', 'huvudsokande']),
        'address' => jeab_snapshots_pick($snap, ['address', 'adress', 'display', 'huvudsokande_address']),
        'email' => $email,
        'phone' => jeab_snapshots_pick($snap, ['phone', 'telefon']),
        'owners' => $owners,
        'at' => jeab_snapshots_text($snap['at'] ?? ''),
        'source' => $source,
    ];
    if ($recipient !== '') {
        $out['recipient'] = $recipient;
    }
    $subject = jeab_snapshots_text($snap['subject'] ?? '');
    if ($subject !== '') {
        $out['subject'] = $subject;
    }
    $body = jeab_snapshots_text($snap['body'] ?? '');
    if ($body !== '') {
        $out['body'] = $body;
    }
    $batch = jeab_snapshots_text($snap['batch'] ?? '');
    if (preg_match('/^[0-9]{14}-[a-f0-9]+$/', $batch) === 1) {
        $out['batch'] = $batch;
    }

    return $out;
}

function jeab_snapshots_valid(mixed $snap): bool
{
    if (!is_array($snap)) {
        return false;
    }

    return array_key_exists('name', $snap) && array_key_exists('email', $snap) && array_key_exists('owners', $snap);
}

/**
 * Kopia från radens egna fält. Ingen kontaktläsning.
 *
 * @param array<string, mixed> $item
 * @return array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}
 */
function jeab_snapshots_from_item(array $item): array
{
    $address = jeab_snapshots_pick($item, ['address', 'huvudsokande_address', 'display', 'anlaggningsadress']);
    if ($address === '') {
        $address = trim(implode(' ', array_filter([
            jeab_snapshots_pick($item, ['gata', 'street', 'adress']),
            jeab_snapshots_pick($item, ['postnummer', 'postal', 'postnr']),
            jeab_snapshots_pick($item, ['ort', 'city']),
        ], static fn (string $part): bool => $part !== '')));
    }
    $owners = jeab_snapshots_owner_list($item['owners'] ?? null);
    if ($owners === []) {
        $owners = jeab_snapshots_owner_list($item['medsokande'] ?? null);
    }
    $parter = is_array($item['parter'] ?? null) ? $item['parter'] : [];
    if ($owners === [] && $parter !== []) {
        $bag = [];
        if (is_array($parter['huvudsokande'] ?? null)) {
            $bag[] = $parter['huvudsokande'];
        }
        if (is_array($parter['medsokande'] ?? null)) {
            foreach ($parter['medsokande'] as $party) {
                if (is_array($party)) {
                    $bag[] = $party;
                }
            }
        }
        $owners = jeab_snapshots_owner_list($bag);
    }
    $name = jeab_snapshots_pick($item, ['name', 'customer_name', 'huvudsokande', 'namn', 'invite_namn']);
    $email = jeab_snapshots_pick($item, ['email', 'epost', 'customer_email', 'invite_epost', 'to']);
    $phone = jeab_snapshots_pick($item, ['phone', 'telefon', 'tel']);
    $first = $owners[0] ?? null;
    if (is_array($first)) {
        if ($name === '') {
            $name = $first['name'];
        }
        if ($email === '') {
            $email = $first['email'];
        }
        if ($phone === '') {
            $phone = $first['phone'];
        }
        if ($address === '') {
            $address = $first['address'];
        }
    }
    $snap = jeab_snapshots_normalize([
        'name' => $name,
        'address' => $address,
        'email' => $email,
        'phone' => $phone,
        'owners' => $owners,
        'source' => 'item',
        'at' => jeab_snapshots_now(),
    ]);
    $snap['source'] = 'item';

    return $snap;
}

/**
 * @param array<string, mixed> $item
 * @return array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}|null
 */
function jeab_snapshots_decode(mixed $raw): ?array
{
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    if (!jeab_snapshots_valid($raw)) {
        return null;
    }

    return jeab_snapshots_normalize($raw);
}

function jeab_snapshots_encode(array $snap): string
{
    $json = json_encode(jeab_snapshots_normalize($snap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

/**
 * @param array<string, mixed> $item
 */
function jeab_snapshots_has_local(array $item, string $type): bool
{
    if ($type === 'mail') {
        return jeab_snapshots_decode($item['snapshot_json'] ?? null) !== null;
    }
    $bag = $item['snapshots'] ?? null;
    if (!is_array($bag)) {
        return false;
    }

    return jeab_snapshots_valid($bag[$type] ?? null);
}

/**
 * @param array<string, mixed> $item
 * @return array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}|null
 */
function jeab_snapshots_read(array $item, string $type): ?array
{
    if ($type === 'mail') {
        return jeab_snapshots_decode($item['snapshot_json'] ?? null);
    }
    $bag = $item['snapshots'] ?? null;
    if (is_array($bag)) {
        $local = jeab_snapshots_decode($bag[$type] ?? null);
        if ($local !== null) {
            return $local;
        }
    }
    if ($type !== 'offert') {
        return null;
    }
    $tid = jeab_snapshots_text($item['thread_id'] ?? '');
    $own = jeab_snapshots_text($item['id'] ?? '');
    if ($tid === '' || $tid === $own || !function_exists('jeab_inkorg_thread_meta')) {
        return null;
    }
    $meta = jeab_inkorg_thread_meta($tid);
    $bag = is_array($meta['snapshots'] ?? null) ? $meta['snapshots'] : [];

    return jeab_snapshots_decode($bag['offert'] ?? null);
}

/**
 * @param array<string, mixed> $row
 */
function jeab_snapshots_sent(array $row, string $type): bool
{
    if ($type === 'invoice') {
        foreach (['fortnox_invoice', 'fortnox_order'] as $key) {
            $value = jeab_snapshots_text($row[$key] ?? '');
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }

        return false;
    }
    if ($type === 'fullmakt') {
        if (!empty($row['fullmakt_signed'])) {
            return true;
        }
        $mailed = $row['fullmakt_mailed'] ?? false;
        if ($mailed === true || $mailed === 1 || $mailed === '1') {
            return true;
        }
        foreach (['fullmakt_sent_at', 'fullmakt_start_at', 'seom_sam_sent_at', 'seom_customer_sent_at'] as $key) {
            if (jeab_snapshots_text($row[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
    if ($type === 'offert') {
        $mark = $row['quote_sent'] ?? null;
        if (is_array($mark) && $mark !== []) {
            return true;
        }
        if (is_string($mark) && trim($mark) !== '' && $mark !== '0') {
            return true;
        }
        foreach (['offert_sent_at', 'quote_sent_at'] as $key) {
            if (jeab_snapshots_text($row[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    return $type === 'mail';
}

/**
 * @param array<string, mixed> $row
 */
function jeab_snapshots_should_freeze(array $row, string $type): bool
{
    if (!empty($row['_snapshot_new_version']) || !empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION'])) {
        return false;
    }
    $hold = $row['_snapshot_hold'] ?? null;
    if ($hold === true || $hold === $type) {
        return true;
    }
    if (jeab_snapshots_read($row, $type) !== null) {
        return true;
    }

    return jeab_snapshots_sent($row, $type);
}

/**
 * @param array<string, mixed> $row
 * @param array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string} $snap
 * @return array<string, mixed>
 */
function jeab_snapshots_store(array $row, string $type, array $snap): array
{
    $snap = jeab_snapshots_normalize($snap);
    if ($snap['at'] === '') {
        $snap['at'] = jeab_snapshots_now();
    }
    if (!isset($row['snapshots']) || !is_array($row['snapshots'])) {
        $row['snapshots'] = [];
    }
    $row['snapshots'][$type] = $snap;
    if ($type === 'mail') {
        $row['snapshot_json'] = jeab_snapshots_encode($snap);
    }

    return $row;
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function jeab_snapshots_capture_sent(array $row, string $type, array $source, string $how = 'send'): array
{
    $replace = !empty($row['_snapshot_new_version']) || !empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION']);
    if (!$replace && jeab_snapshots_has_local($row, $type)) {
        return $row;
    }
    $snap = jeab_snapshots_from_item($source);
    $snap['source'] = $replace ? 'version' : $how;
    $snap['at'] = jeab_snapshots_now();

    return jeab_snapshots_store($row, $type, $snap);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_snapshots_frozen_party(array $row, string $type): array
{
    $snap = jeab_snapshots_read($row, $type);
    if ($snap === null) {
        $snap = jeab_snapshots_from_item($row);
    }
    $out = $row;
    $out['name'] = $snap['name'];
    $out['customer_name'] = $snap['name'];
    $out['huvudsokande'] = $snap['name'];
    $out['namn'] = $snap['name'];
    $out['email'] = $snap['email'];
    $out['epost'] = $snap['email'];
    $out['customer_email'] = $snap['email'];
    $out['phone'] = $snap['phone'];
    $out['telefon'] = $snap['phone'];
    $out['address'] = $snap['address'];
    $out['huvudsokande_address'] = $snap['address'];
    $orig = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    $owners = [];
    foreach ($snap['owners'] as $i => $owner) {
        $pnr = '';
        $prev = $orig[$i] ?? null;
        if (is_array($prev)) {
            $pnr = jeab_snapshots_text($prev['personnummer'] ?? '');
        }
        $next = $owner;
        if ($pnr !== '') {
            $next['personnummer'] = $pnr;
        }
        $owners[] = $next;
    }
    if ($owners !== []) {
        $out['owners'] = $owners;
        $med = [];
        foreach ($owners as $owner) {
            if (strcasecmp((string) $owner['name'], $snap['name']) === 0) {
                continue;
            }
            $med[] = $owner;
        }
        $out['medsokande'] = $med;
    }
    $out['_party_frozen'] = $type;

    return $out;
}

/**
 * Mottagare vid omskick. Finns en fryst kopia används den.
 * new_version lämnar den levande adressen.
 */
function jeab_snapshots_resend_email(array $item, string $liveEmail, array $input): string
{
    if (!jeab_snapshots_wants_new($input)) {
        $snap = jeab_snapshots_read($item, 'offert');
        $email = $snap !== null ? jeab_snapshots_text($snap['email']) : '';
        if ($email !== '') {
            return $email;
        }
    }

    return $liveEmail;
}

/**
 * Fryst offert om den finns. Annars den mottagare som utskicket just använde.
 * Trådens avsändare blir inte kundadress.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $customer
 * @return array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}
 */
function jeab_snapshots_quote_party(array $thread, string $email, array $customer = []): array
{
    if (empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION'])) {
        $have = jeab_snapshots_read($thread, 'offert');
        if ($have !== null && $have['email'] !== '') {
            return $have;
        }
    }
    $item = [
        'name' => jeab_snapshots_pick($customer, ['name', 'customer_name', 'huvudsokande']),
        'email' => $email,
        'phone' => jeab_snapshots_pick($customer, ['phone', 'telefon']),
        'address' => jeab_snapshots_pick($customer, ['address', 'display', 'huvudsokande_address']),
        'owners' => is_array($customer['owners'] ?? null) ? $customer['owners'] : [],
    ];
    $snap = jeab_snapshots_from_item($item);
    if ($email !== '') {
        $snap['email'] = $email;
    }
    $snap['source'] = 'send';

    return $snap;
}

/**
 * @param array<string, mixed> $party
 */
function jeab_snapshots_commit_offert(string $tid, array $party, bool $replace): void
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_inkorg_thread_write')) {
        return;
    }
    $sentEmail = jeab_snapshots_text($party['email'] ?? '');
    $snap = jeab_snapshots_normalize($party);
    if ($sentEmail !== '') {
        $snap['email'] = $sentEmail;
    }
    if ($sentEmail === '' && $snap['name'] === '' && $snap['email'] === '' && $snap['phone'] === '' && $snap['address'] === '' && $snap['owners'] === []) {
        $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
        $snap = jeab_snapshots_from_item(is_array($meta) ? $meta : []);
    }
    $snap['source'] = $replace ? 'version' : 'send';
    $snap['at'] = jeab_snapshots_now();
    try {
        jeab_inkorg_thread_write($tid, static function (array $thread) use ($snap, $replace): array {
            if (!$replace && jeab_snapshots_has_local($thread, 'offert')) {
                return $thread;
            }

            return jeab_snapshots_store($thread, 'offert', $snap);
        });
    } catch (Throwable) {
        // Utskicket är redan gjort. Kopian får fyllas av backfill från tråden.
    }
}

/**
 * Personnummer som redan ligger på raden. Kontakten läses inte.
 *
 * @param array<string, mixed> $row
 */
function jeab_snapshots_row_pnr(array $row): string
{
    $raw = '';
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    $name = jeab_snapshots_pick($row, ['huvudsokande', 'name', 'customer_name']);
    foreach ($owners as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $ownerName = jeab_snapshots_text($owner['name'] ?? '');
        if ($name !== '' && $ownerName !== '' && strcasecmp($ownerName, $name) !== 0) {
            continue;
        }
        $raw = jeab_snapshots_text($owner['personnummer'] ?? '');
        if ($raw !== '') {
            break;
        }
    }
    if ($raw === '') {
        $raw = jeab_snapshots_text($row['personnummer'] ?? '');
    }
    if ($raw === '') {
        return '';
    }
    if (function_exists('jeab_ordrar_format_pnr')) {
        return jeab_ordrar_format_pnr($raw);
    }

    return $raw;
}

/**
 * @return array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}>
 */
function jeab_snapshots_count_sheet(): array
{
    $blank = ['offert' => 0, 'invoice' => 0, 'fullmakt' => 0, 'mail' => 0];
    $sheet = [];
    foreach (jeab_snapshots_companies() as $company) {
        $sheet[$company] = $blank;
    }

    return $sheet;
}

/**
 * @param array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}> $sheet
 */
function jeab_snapshots_tally(array &$sheet, string $company, string $type): void
{
    if (!isset($sheet[$company][$type])) {
        return;
    }
    $sheet[$company][$type]++;
}

/**
 * @param array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}> $sheet
 * @return array{offert:int,invoice:int,fullmakt:int,mail:int}
 */
function jeab_snapshots_sum(array $sheet): array
{
    $sum = ['offert' => 0, 'invoice' => 0, 'fullmakt' => 0, 'mail' => 0];
    foreach ($sheet as $row) {
        foreach ($sum as $type => $value) {
            $sum[$type] += (int) ($row[$type] ?? 0);
        }
    }

    return $sum;
}

/**
 * @return list<array{store:string,id:string,type:string,company:string,item:array<string,mixed>}>
 */
function jeab_snapshots_sent_items(): array
{
    $items = [];
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['reminder_id'] ?? $row['id'] ?? '');
            if ($id === '' || $id === '0') {
                continue;
            }
            $company = jeab_snapshots_company((string) ($row['company'] ?? ''));
            foreach (['invoice', 'fullmakt'] as $type) {
                if (!jeab_snapshots_sent($row, $type)) {
                    continue;
                }
                $items[] = ['store' => 'purchase', 'id' => $id, 'type' => $type, 'company' => $company, 'item' => $row];
            }
        }
    }
    if (function_exists('jeab_inkorg_threads_meta')) {
        foreach (jeab_inkorg_threads_meta() as $tid => $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $thread['id'] = (string) $tid;
            if (!jeab_snapshots_sent($thread, 'offert')) {
                continue;
            }
            $company = jeab_snapshots_company((string) ($thread['company'] ?? $thread['brand'] ?? ''));
            $items[] = ['store' => 'thread', 'id' => (string) $tid, 'type' => 'offert', 'company' => $company, 'item' => $thread];
        }
    }
    foreach (jeab_snapshots_mail_rows() as $row) {
        $items[] = $row;
    }

    return $items;
}

/**
 * @return list<array{store:string,id:string,type:string,company:string,item:array<string,mixed>}>
 */
function jeab_snapshots_mail_rows(): array
{
    $out = [];
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable) {
        $store = null;
    }
    if (!$store instanceof JeabPdoStore || !function_exists('jeab_ensure_inbox_sent')) {
        return [];
    }
    $pdo = $store->pdo();
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_sent($pdo, $driver);
    $threads = function_exists('jeab_inkorg_threads_meta') ? jeab_inkorg_threads_meta() : [];
    try {
        $stmt = $pdo->query('SELECT id, created_at, thread_id, kind, snapshot_json FROM inbox_sent');
    } catch (Throwable) {
        return [];
    }
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (!is_array($row) || !jeab_snapshots_mail_is_send($row)) {
            continue;
        }
        $tid = jeab_snapshots_text($row['thread_id'] ?? '');
        $thread = is_array($threads[$tid] ?? null) ? $threads[$tid] : [];
        $company = jeab_snapshots_company((string) ($thread['company'] ?? $thread['brand'] ?? ''));
        $row['customer_name'] = jeab_snapshots_pick($thread, ['customer_name', 'name']);
        $row['customer_email'] = jeab_snapshots_pick($thread, ['customer_email', 'email']);
        $row['phone'] = jeab_snapshots_pick($thread, ['phone', 'telefon']);
        $row['address'] = jeab_snapshots_pick($thread, ['address', 'huvudsokande_address']);
        $row['owners'] = is_array($thread['owners'] ?? null) ? $thread['owners'] : [];
        $out[] = [
            'store' => 'mail',
            'id' => (string) ($row['id'] ?? ''),
            'type' => 'mail',
            'company' => $company,
            'item' => $row,
        ];
    }

    return $out;
}

/**
 * Bromsens egna rader (send_guard_brake.php jeab_send_guard_log, kind guard*)
 * har ingen mottagare, bara hashar. jeab_inkorg_send_mark skriver kind k:
 * (och h: utan send_key) som dedupe-markör, inte som ett utskick.
 * De är inget utskick att frysa och räknades förut som mejl utan kopia.
 * Andra rader utan tråd räknas kvar och syns för sig i audit (missing_threadless).
 *
 * @param array<string, mixed> $row
 */
function jeab_snapshots_mail_is_send(array $row): bool
{
    $kind = jeab_snapshots_text($row['kind'] ?? '');

    return !str_starts_with($kind, 'guard') && !str_starts_with($kind, 'k:') && !str_starts_with($kind, 'h:');
}

/** @param array<string, mixed> $entry */
function jeab_snapshots_entry_key(array $entry): string
{
    return (string) $entry['store'] . ':' . (string) $entry['id'];
}

/**
 * @param array<string, mixed> $item
 */
function jeab_snapshots_entry_at(array $item): string
{
    foreach (['created_at', 'sent_at', 'updated_at'] as $key) {
        $v = jeab_snapshots_text($item[$key] ?? '');
        if ($v !== '') {
            return $v;
        }
    }

    return '';
}

/**
 * @param mixed $raw
 * @return list<string>
 */
function jeab_snapshots_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string) $v);
        if (preg_match('/^(purchase|thread|mail):[A-Za-z0-9._-]{1,191}$/', $v) === 1) {
            $out[] = $v;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_snapshots_audit(array $input = []): array
{
    $sent = jeab_snapshots_count_sheet();
    $missing = jeab_snapshots_count_sheet();
    $rows = [];
    $threadless = 0;
    foreach (jeab_snapshots_sent_items() as $entry) {
        $type = (string) $entry['type'];
        $company = (string) $entry['company'];
        jeab_snapshots_tally($sent, $company, $type);
        if (!jeab_snapshots_has_local($entry['item'], $type)) {
            jeab_snapshots_tally($missing, $company, $type);
            // Bara id, tabell, typ, bolag och tid. Inga kundvärden.
            if ($type === 'mail' && jeab_snapshots_text($entry['item']['thread_id'] ?? '') === '') {
                $threadless++;
            }
            $rows[] = [
                'id' => jeab_snapshots_entry_key($entry),
                'table' => ['mail' => 'inbox_sent', 'thread' => 'inbox_threads', 'purchase' => 'purchase'][(string) $entry['store']] ?? (string) $entry['store'],
                'type' => $type,
                'company' => $company,
                'at' => jeab_snapshots_entry_at($entry['item']),
                'kind' => $type === 'mail' ? jeab_snapshots_text($entry['item']['kind'] ?? '') : '',
            ];
        }
    }

    return [
        'ok' => true,
        'action' => 'snapshots.audit',
        'read_only' => true,
        'written' => false,
        'dry' => true,
        'sent' => jeab_snapshots_sum($sent),
        'missing' => jeab_snapshots_sum($missing),
        'companies' => jeab_snapshots_companies_public($sent, $missing),
        'missing_rows' => $rows,
        'missing_threadless' => $threadless,
        'status' => 200,
    ];
}

/**
 * @param array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}> $sent
 * @param array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}> $missing
 * @return array<string, array{sent:array{offert:int,invoice:int,fullmakt:int,mail:int},missing:array{offert:int,invoice:int,fullmakt:int,mail:int}}>
 */
function jeab_snapshots_companies_public(array $sent, array $missing): array
{
    $out = [];
    foreach (jeab_snapshots_companies() as $company) {
        $out[$company] = [
            'sent' => $sent[$company],
            'missing' => $missing[$company],
        ];
    }

    return $out;
}

function jeab_snapshots_undo_dir(): string
{
    $dir = jeab_data_dir() . '/snapshots-backfill';
    if (!is_dir($dir) && !is_file($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_snapshots_backfill(array $input): array
{
    if (!empty($input['undo']) && (string) $input['undo'] !== '0') {
        return jeab_snapshots_backfill_undo($input);
    }
    $dry = true;
    if (array_key_exists('dry', $input)) {
        $value = $input['dry'];
        $dry = !($value === false || $value === 0 || $value === '0' || $value === 'false');
    }
    $ids = jeab_snapshots_ids($input['ids'] ?? null);
    if (array_key_exists('ids', $input) && $ids === []) {
        return ['ok' => false, 'action' => 'snapshots.backfill', 'error' => 'ids ska vara store:id, t.ex. mail:12.', 'written' => false, 'status' => 400];
    }
    $would = jeab_snapshots_count_sheet();
    $plan = [];
    foreach (jeab_snapshots_sent_items() as $entry) {
        $type = (string) $entry['type'];
        $item = $entry['item'];
        if ($ids !== [] && !in_array(jeab_snapshots_entry_key($entry), $ids, true)) {
            continue;
        }
        if (jeab_snapshots_has_local($item, $type)) {
            continue;
        }
        jeab_snapshots_tally($would, (string) $entry['company'], $type);
        $snap = jeab_snapshots_from_item($item);
        $snap['source'] = 'backfill';
        $snap['at'] = jeab_snapshots_now();
        $plan[] = [
            'store' => (string) $entry['store'],
            'id' => (string) $entry['id'],
            'type' => $type,
            'company' => (string) $entry['company'],
            'before' => null,
            'snap' => $snap,
        ];
    }
    $filled = jeab_snapshots_count_sheet();
    $batch = '';
    $failed = 0;
    if (!$dry && $plan !== []) {
        $batch = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $undoRows = [];
        foreach ($plan as $i => $step) {
            $plan[$i]['snap']['batch'] = $batch;
            $plan[$i]['snap']['source'] = 'backfill';
            $undoRows[] = [
                'store' => $step['store'],
                'id' => $step['id'],
                'type' => $step['type'],
                'batch' => $batch,
                'before' => null,
            ];
        }
        $dir = jeab_snapshots_undo_dir();
        $path = $dir . '/' . $batch . '.json';
        $json = json_encode(['batch_id' => $batch, 'rows' => $undoRows], JSON_UNESCAPED_UNICODE);
        if (!is_dir($dir) || !is_string($json) || file_put_contents($path, $json, LOCK_EX) === false) {
            return jeab_snapshots_scrub([
                'ok' => false,
                'action' => 'snapshots.backfill',
                'error' => 'Ångerfilen kunde inte skrivas.',
                'dry' => false,
                'written' => false,
                'failed' => 0,
                'batch_id' => '',
                'status' => 400,
            ]);
        }
        foreach ($plan as $step) {
            $ok = jeab_snapshots_apply($step['store'], $step['id'], $step['type'], $step['snap']);
            if (!$ok) {
                $failed++;
                continue;
            }
            jeab_snapshots_tally($filled, $step['company'], $step['type']);
        }
        $wrote = false;
        foreach (jeab_snapshots_sum($filled) as $count) {
            if ($count > 0) {
                $wrote = true;
                break;
            }
        }
        if ($failed > 0) {
            return jeab_snapshots_scrub([
                'ok' => false,
                'action' => 'snapshots.backfill',
                'dry' => false,
                'written' => $wrote,
                'would' => jeab_snapshots_sum($would),
                'filled' => jeab_snapshots_sum($filled),
                'failed' => $failed,
                'batch_id' => $batch,
                'companies' => jeab_snapshots_pair_public($would, $filled, 'would', 'filled'),
                'status' => 400,
            ]);
        }
    }

    return jeab_snapshots_scrub([
        'ok' => $failed === 0,
        'action' => 'snapshots.backfill',
        'dry' => $dry,
        'written' => !$dry && jeab_snapshots_sum($filled) !== ['offert' => 0, 'invoice' => 0, 'fullmakt' => 0, 'mail' => 0],
        'would' => jeab_snapshots_sum($would),
        'filled' => jeab_snapshots_sum($filled),
        'failed' => $failed,
        'batch_id' => $batch,
        'companies' => jeab_snapshots_pair_public($would, $filled, 'would', 'filled'),
        'status' => 200,
    ]);
}

/**
 * @param array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}> $left
 * @param array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}> $right
 * @return array<string, array<string, array{offert:int,invoice:int,fullmakt:int,mail:int}>>
 */
function jeab_snapshots_pair_public(array $left, array $right, string $leftKey, string $rightKey): array
{
    $out = [];
    foreach (jeab_snapshots_companies() as $company) {
        $out[$company] = [
            $leftKey => $left[$company],
            $rightKey => $right[$company],
        ];
    }

    return $out;
}

/**
 * @param array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}|null $snap
 */
function jeab_snapshots_apply(string $store, string $id, string $type, ?array $snap): bool
{
    try {
        if ($store === 'purchase') {
            $rid = (int) $id;
            if ($rid <= 0 || !function_exists('jeab_purchase_patch')) {
                return false;
            }
            $saved = jeab_purchase_patch($rid, static function (array $row) use ($type, $snap): array {
                return jeab_snapshots_restore($row, $type, $snap);
            });

            return is_array($saved);
        }
        if ($store === 'thread') {
            if ($id === '' || !function_exists('jeab_inkorg_thread_write')) {
                return false;
            }
            jeab_inkorg_thread_write($id, static function (array $thread) use ($type, $snap): array {
                return jeab_snapshots_restore($thread, $type, $snap);
            });

            return true;
        }
        if ($store === 'mail') {
            return jeab_snapshots_apply_mail((int) $id, $snap);
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

/**
 * null tar bort den typen. Övriga typer på raden lämnas kvar.
 *
 * @param array<string, mixed> $prior
 * @param array<string, mixed> $incoming
 * @return array<string, mixed>
 */
function jeab_snapshots_overlay_types(array $prior, array $incoming): array
{
    $bag = $prior;
    foreach ($incoming as $type => $snap) {
        if (!is_string($type) || $type === '') {
            continue;
        }
        if ($snap === null) {
            unset($bag[$type]);
            continue;
        }
        $bag[$type] = $snap;
    }

    return $bag;
}

/**
 * @param array<string, mixed> $row
 * @param array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}|null $snap
 * @return array<string, mixed>
 */
function jeab_snapshots_restore(array $row, string $type, ?array $snap): array
{
    if (!isset($row['snapshots']) || !is_array($row['snapshots'])) {
        $row['snapshots'] = [];
    }
    if ($snap === null) {
        $row['snapshots'][$type] = null;

        return $row;
    }

    return jeab_snapshots_store($row, $type, $snap);
}

/**
 * @param array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string}|null $snap
 */
function jeab_snapshots_apply_mail(int $id, ?array $snap): bool
{
    if ($id <= 0) {
        return false;
    }
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable) {
        return false;
    }
    if (!$store instanceof JeabPdoStore || !function_exists('jeab_ensure_inbox_sent')) {
        return false;
    }
    $pdo = $store->pdo();
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_sent($pdo, $driver);
    $json = $snap === null ? '' : jeab_snapshots_encode($snap);
    $stmt = $pdo->prepare('UPDATE inbox_sent SET snapshot_json = ? WHERE id = ?');
    $stmt->execute([$json, $id]);
    $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;

    return true;
}

/**
 * @return array{name:string,address:string,email:string,phone:string,owners:list<array{name:string,address:string,email:string,phone:string}>,at:string,source:string,batch?:string}|null
 */
function jeab_snapshots_load_current(string $store, string $id, string $type): ?array
{
    if ($store === 'purchase' && function_exists('jeab_purchase_get')) {
        $row = jeab_purchase_get((int) $id);

        return is_array($row) ? jeab_snapshots_read($row, $type) : null;
    }
    if ($store === 'thread' && function_exists('jeab_inkorg_thread_meta')) {
        $row = jeab_inkorg_thread_meta($id);

        return is_array($row) ? jeab_snapshots_read($row, $type) : null;
    }
    if ($store !== 'mail') {
        return null;
    }
    try {
        $pdoStore = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable) {
        return null;
    }
    if (!$pdoStore instanceof JeabPdoStore) {
        return null;
    }
    $stmt = $pdoStore->pdo()->prepare('SELECT snapshot_json FROM inbox_sent WHERE id = ?');
    $stmt->execute([(int) $id]);
    $json = $stmt->fetchColumn();
    $snap = jeab_snapshots_decode(is_string($json) ? $json : '');

    return is_array($snap) ? jeab_snapshots_normalize($snap) : null;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_snapshots_backfill_undo(array $input): array
{
    $batch = jeab_snapshots_text($input['batch_id'] ?? '');
    if ($batch === '' || preg_match('/^[0-9]{14}-[a-f0-9]+$/', $batch) !== 1) {
        return [
            'ok' => false,
            'action' => 'snapshots.backfill_undo',
            'error' => 'batch_id saknas.',
            'status' => 400,
        ];
    }
    $path = jeab_snapshots_undo_dir() . '/' . $batch . '.json';
    if (!is_file($path)) {
        return [
            'ok' => false,
            'action' => 'snapshots.backfill_undo',
            'error' => 'Ångerfilen saknas.',
            'status' => 400,
        ];
    }
    $raw = file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $rows = is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [];
    $undone = 0;
    $failed = 0;
    $skipped = 0;
    foreach ($rows as $step) {
        if (!is_array($step)) {
            $failed++;
            continue;
        }
        $storeName = (string) ($step['store'] ?? '');
        $id = (string) ($step['id'] ?? '');
        $type = (string) ($step['type'] ?? '');
        $current = jeab_snapshots_load_current($storeName, $id, $type);
        if ($current === null) {
            continue;
        }
        $currentBatch = (string) ($current['batch'] ?? '');
        if (($current['source'] ?? '') !== 'backfill' || $currentBatch === '' || $currentBatch !== $batch) {
            $skipped++;
            continue;
        }
        $before = is_array($step['before'] ?? null) ? jeab_snapshots_normalize($step['before']) : null;
        $ok = jeab_snapshots_apply($storeName, $id, $type, $before);
        if ($ok && $before === null) {
            $ok = jeab_snapshots_load_current($storeName, $id, $type) === null;
        }
        if ($ok) {
            $undone++;
        } else {
            $failed++;
        }
    }

    return jeab_snapshots_scrub([
        'ok' => $failed === 0 && $skipped === 0,
        'action' => 'snapshots.backfill_undo',
        'dry' => false,
        'written' => $undone > 0,
        'undone' => $undone,
        'skipped' => $skipped,
        'failed' => $failed,
        'batch_id' => $batch,
        'status' => ($failed === 0 && $skipped === 0) ? 200 : 400,
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_snapshots_handle(array $input): array
{
    $action = (string) ($input['action'] ?? '');
    if ($action === 'snapshots.audit') {
        return jeab_snapshots_scrub(jeab_snapshots_audit($input));
    }
    if ($action === 'snapshots.backfill_undo') {
        return jeab_snapshots_backfill_undo($input);
    }
    if ($action === 'snapshots.backfill') {
        return jeab_snapshots_backfill($input);
    }
    if ($action === 'snapshots.counts') {
        return jeab_snapshots_counts();
    }

    return ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
}

/**
 * Bara antal och tider. Läser, skriver inget.
 *
 * @return array<string, mixed>
 */
function jeab_snapshots_counts(): array
{
    $out = ['ok' => true, 'action' => 'snapshots.counts', 'read_only' => true, 'written' => false];
    $pdo = null;
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
        $pdo = $store instanceof JeabPdoStore ? $store->pdo() : null;
    } catch (Throwable) {
        $pdo = null;
    }
    $one = static function (?PDO $db, string $sql): array {
        if (!$db instanceof PDO) {
            return ['ok' => false];
        }
        try {
            $stmt = $db->query($sql);
            $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
            return ['ok' => true, 'count' => (int) ($row[0] ?? 0), 'max' => (string) ($row[1] ?? '')];
        } catch (Throwable) {
            return ['ok' => false];
        }
    };
    $ic = $one($pdo, 'SELECT COUNT(*), MAX(updated_at) FROM inkorg_contacts');
    $out['inkorg_contacts'] = ['ok' => $ic['ok'], 'count' => $ic['count'] ?? null, 'latest_updated_at' => ($ic['max'] ?? '') !== '' ? $ic['max'] : null];
    $lp = $one($pdo, 'SELECT COUNT(*), MAX(updated_at) FROM inbox_lead_party');
    $out['inbox_lead_party'] = ['ok' => $lp['ok'], 'count' => $lp['count'] ?? null, 'latest_updated_at' => ($lp['max'] ?? '') !== '' ? $lp['max'] : null];
    $logPdo = null;
    try {
        $logPdo = function_exists('jeab_log_pdo') ? jeab_log_pdo() : null;
    } catch (Throwable) {
        $logPdo = null;
    }
    $wo = $one($logPdo instanceof PDO ? $logPdo : null, 'SELECT COUNT(*), NULL FROM log_webshop_orders');
    $out['log_webshop_orders'] = ['ok' => $wo['ok'], 'count' => $wo['count'] ?? null];
    // Ledtrådar utan sparad lead-kopia: mottagaren saknas om tråden länkas och kopian strippas.
    $leads = 0;
    $noParty = 0;
    $partyFailed = !$pdo instanceof PDO;
    if (function_exists('jeab_inkorg_threads_meta')) {
        $have = [];
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->query("SELECT thread_id FROM inbox_lead_party WHERE email <> ''");
                foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $t) {
                    $have[(string) $t] = true;
                }
            } catch (Throwable) {
                $have = [];
                $partyFailed = true;
            }
        }
        foreach (jeab_inkorg_threads_meta() as $tid => $thread) {
            if (!is_array($thread) || jeab_snapshots_text($thread['lead_source'] ?? '') === '') {
                continue;
            }
            $leads++;
            if (!isset($have[(string) $tid])) {
                $noParty++;
            }
        }
    }
    $out['lead_threads'] = ['ok' => !$partyFailed, 'count' => $leads, 'without_lead_party' => $partyFailed ? null : $noParty];
    if ($partyFailed) {
        // Utan inbox_lead_party går det inte att säga hur många trådar som saknar kopia.
        $out['ok'] = false;
    }
    $fm = dirname(__DIR__) . '/fullmakt/lib/store.php';
    if (!function_exists('fullmakt_sql_verify') && is_file($fm)) {
        try {
            require_once $fm;
        } catch (Throwable) {
        }
    }
    try {
        $v = function_exists('fullmakt_sql_verify') ? fullmakt_sql_verify() : null;
        $out['fullmakt_sql_verify'] = is_array($v) ? ['ok' => (bool) ($v['ok'] ?? false), 'json_only' => (int) ($v['json_only'] ?? 0)] : ['ok' => false];
    } catch (Throwable) {
        $out['fullmakt_sql_verify'] = ['ok' => false];
    }
    $out['status'] = 200;

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_snapshots_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }

    return jeab_snapshots_handle($input);
}

/**
 * @param array<string, mixed> $result
 * @return array<string, mixed>
 */
function jeab_snapshots_scrub(array $result): array
{
    $walk = static function (mixed $value) use (&$walk): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $name = strtolower((string) $key);
            if (str_contains($name, 'pnr') || str_contains($name, 'personnummer') || $name === 'idnr') {
                continue;
            }
            $out[$key] = $walk($item);
        }

        return $out;
    };
    $clean = $walk($result);
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if (is_string($json) && function_exists('jeab_inkorg_text_hide_pnr') && preg_match('/\d{6}[-+]?\d{4}/', $json) === 1) {
        $hidden = jeab_inkorg_text_hide_pnr($json);
        $again = json_decode($hidden, true);
        if (is_array($again)) {
            return $again;
        }
    }

    return is_array($clean) ? $clean : $result;
}
