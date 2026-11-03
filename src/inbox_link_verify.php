<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * inbox.link_verify. Bara läsning.
 *
 * En rad per länk i inbox_link_contacts_undo (action link, eller tom).
 * inkorg_list och inkorg_threads med samma tråd är samma länk.
 * E-post är den ursprungliga avsändaren (tidigaste inkommande, inte
 * anteckning, inte utgående, inte egen adress). Telefon läses som
 * inbox_link_contacts vid phone_address: trådfält från båda tabellerna,
 * sedan samma utdrag ur leadtext/meddelande, samma normaliserare, mot
 * kontaktens alla mobil- och telefonfält. match_by tas bara om ångerraden
 * redan har email, phone eller address. Inga kunduppgifter i svaret.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_link_verify(array $input, ?JeabStore $store = null): array
{
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return ['ok' => false, 'error' => 'Ogiltigt undo_id.', 'status' => 400];
    }
    $store = $store ?? (function_exists('jeab_store') ? jeab_store() : null);
    if (!$store instanceof JeabStore || !method_exists($store, 'pdo')) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'undo_id' => $undoId, 'status' => 400];
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        $pdo = null;
    }
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'Ingen SQL-databas.', 'undo_id' => $undoId, 'status' => 400];
    }
    if (!function_exists('jeab_ensure_inbox_link_undo_table')) {
        return ['ok' => false, 'error' => 'Kunde inte läsa ångerloggen.', 'undo_id' => $undoId, 'status' => 400];
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        jeab_ensure_inbox_link_undo_table($pdo, $driver);
        $stmt = $pdo->prepare(
            'SELECT id, thread_id, tbl, new_contact_id, action, old_value FROM inbox_link_contacts_undo WHERE undo_id = ? ORDER BY id'
        );
        $stmt->execute([$undoId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'Kunde inte läsa ångerloggen.', 'undo_id' => $undoId, 'status' => 400];
    }
    if (!is_array($rows) || $rows === []) {
        return ['ok' => false, 'error' => 'Okänt undo_id.', 'undo_id' => $undoId, 'status' => 404];
    }
    jeab_inbox_link_verify_load_matcher();
    $groups = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !jeab_inbox_link_verify_is_link($row)) {
            continue;
        }
        $threadId = trim((string) ($row['thread_id'] ?? ''));
        if ($threadId === '') {
            continue;
        }
        $groups[$threadId][] = $row;
    }
    $out = [];
    $index = 0;
    foreach ($groups as $threadId => $group) {
        if (!is_array($group) || $group === []) {
            continue;
        }
        $canon = jeab_inbox_link_verify_canon($group);
        $contactId = trim((string) ($canon['new_contact_id'] ?? ''));
        [$email, $phone] = jeab_inbox_link_verify_sender($pdo, (string) $threadId);
        [$emails, $phones] = jeab_inbox_link_verify_contact($pdo, $contactId);
        $out[] = [
            'index' => $index,
            'link_id' => (int) ($canon['id'] ?? 0),
            'thread_ref_kind' => jeab_inbox_link_verify_ref_kind((string) ($canon['tbl'] ?? '')),
            'email_match' => jeab_inbox_link_verify_match($email, $emails),
            'phone_match' => jeab_inbox_link_verify_match($phone, $phones),
            'match_by' => jeab_inbox_link_verify_match_by($group),
        ];
        $index++;
    }

    return [
        'ok' => true,
        'undo_id' => $undoId,
        'rows' => $out,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $row
 */
function jeab_inbox_link_verify_is_link(array $row): bool
{
    $action = trim((string) ($row['action'] ?? ''));
    if ($action === '') {
        $action = 'link';
    }
    if ($action !== 'link') {
        return false;
    }

    return in_array((string) ($row['tbl'] ?? ''), ['inkorg_list', 'inkorg_threads'], true);
}

function jeab_inbox_link_verify_load_matcher(): void
{
    if (!function_exists('jeab_inbox_link_contacts_thread_phone')) {
        require_once __DIR__ . '/inbox_link_contacts.php';
    }
}

/**
 * List-raden är samma länk som tråd-raden. Den bär lead-fälten.
 *
 * @param list<array<string, mixed>> $group
 * @return array<string, mixed>
 */
function jeab_inbox_link_verify_canon(array $group): array
{
    $best = null;
    $bestRank = 2;
    $bestId = PHP_INT_MAX;
    foreach ($group as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rank = (string) ($row['tbl'] ?? '') === 'inkorg_list' ? 0 : 1;
        $id = (int) ($row['id'] ?? 0);
        if ($best === null || $rank < $bestRank || ($rank === $bestRank && $id < $bestId)) {
            $best = $row;
            $bestRank = $rank;
            $bestId = $id;
        }
    }

    return is_array($best) ? $best : [];
}

function jeab_inbox_link_verify_ref_kind(string $table): string
{
    return $table === 'inkorg_list' ? 'list' : 'thread';
}

/**
 * @param list<array<string, mixed>> $group
 */
function jeab_inbox_link_verify_match_by(array $group): ?string
{
    foreach ($group as $row) {
        if (!is_array($row)) {
            continue;
        }
        $by = jeab_inbox_link_verify_match_by_row($row);
        if ($by !== null) {
            return $by;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $row
 */
function jeab_inbox_link_verify_match_by_row(array $row): ?string
{
    $direct = jeab_inbox_link_verify_match_token($row['match_by'] ?? null);
    if ($direct !== null) {
        return $direct;
    }
    $old = $row['old_value'] ?? null;
    if (!is_string($old)) {
        return null;
    }
    $trim = trim($old);
    $plain = jeab_inbox_link_verify_match_token($trim);
    if ($plain !== null) {
        return $plain;
    }
    if ($trim === '' || $trim[0] !== '{') {
        return null;
    }
    $decoded = json_decode($trim, true);
    if (!is_array($decoded)) {
        return null;
    }

    return jeab_inbox_link_verify_match_token($decoded['match_by'] ?? null);
}

function jeab_inbox_link_verify_match_token(mixed $raw): ?string
{
    if (!is_string($raw) && !is_int($raw)) {
        return null;
    }
    $token = trim((string) $raw);

    return in_array($token, ['email', 'phone', 'address'], true) ? $token : null;
}

/**
 * @param array<string, true> $set
 */
function jeab_inbox_link_verify_match(string $needle, array $set): ?bool
{
    if ($needle === '' || $set === []) {
        return null;
    }

    return isset($set[$needle]);
}

/**
 * @return array{0:string,1:string}
 */
function jeab_inbox_link_verify_sender(PDO $pdo, string $threadId): array
{
    $meta = jeab_inbox_link_verify_merged_meta($pdo, $threadId);
    $chosen = jeab_inbox_link_verify_from_messages($pdo, $threadId);
    if ($chosen === null) {
        $chosen = jeab_inbox_link_verify_from_meta($meta);
    }
    $email = is_array($chosen) ? (string) ($chosen['email'] ?? '') : '';

    return [$email, jeab_inbox_link_verify_sender_phone($threadId, $meta, $chosen)];
}

/**
 * Samma telefon som inbox_link_contacts: sammanslagen tråd (listan först),
 * sedan leadtexten i det ursprungliga meddelandet. Nycklar på mejlet är reserv.
 *
 * @param array<string, mixed> $meta
 * @param array{email:string,msg:array<string,mixed>}|null $chosen
 */
function jeab_inbox_link_verify_sender_phone(string $threadId, array $meta, ?array $chosen): string
{
    if (function_exists('jeab_inbox_link_contacts_thread_phone')) {
        $phone = jeab_inbox_link_contacts_thread_phone($meta, $threadId);
        if ($phone !== '') {
            return $phone;
        }
        $msg = is_array($chosen['msg'] ?? null) ? $chosen['msg'] : null;
        if (is_array($msg)) {
            $fromMsg = jeab_inbox_link_contacts_thread_phone(['first' => $msg], $threadId);
            if ($fromMsg !== '') {
                return $fromMsg;
            }
        }
    }
    if (is_array($chosen) && is_array($chosen['msg'] ?? null)) {
        $phone = jeab_inbox_link_verify_message_phone($chosen['msg']);
        if ($phone !== '') {
            return $phone;
        }
    }

    return jeab_inbox_link_verify_lead_phone($meta);
}

/**
 * inkorg_list först, sedan inkorg_threads. Samma hopslagning som länkningen.
 *
 * @return array<string, mixed>
 */
function jeab_inbox_link_verify_merged_meta(PDO $pdo, string $threadId): array
{
    $meta = [];
    foreach (['inkorg_list', 'inkorg_threads'] as $table) {
        foreach (jeab_inbox_link_verify_meta($pdo, $table, $threadId) as $key => $value) {
            $meta[$key] = $value;
        }
    }

    return $meta;
}

/**
 * @return array{email:string,msg:array<string,mixed>}|null
 */
function jeab_inbox_link_verify_from_messages(PDO $pdo, string $threadId): ?array
{
    if ($threadId === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT id, from_addr, is_note, received_at, row_json FROM inkorg_messages WHERE thread_id = ? ORDER BY received_at ASC, id ASC'
        );
        $stmt->execute([$threadId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $msg = [];
        if (is_string($row['row_json'] ?? null) && $row['row_json'] !== '') {
            $decoded = json_decode($row['row_json'], true);
            if (is_array($decoded)) {
                $msg = $decoded;
            }
        }
        $chosen = jeab_inbox_link_verify_accept($msg, (string) ($row['from_addr'] ?? ''), $row['is_note'] ?? null);
        if ($chosen !== null) {
            return $chosen;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $meta
 * @return array{email:string,msg:array<string,mixed>}|null
 */
function jeab_inbox_link_verify_from_meta(array $meta): ?array
{
    $bag = [];
    foreach (is_array($meta['messages'] ?? null) ? $meta['messages'] : [] as $msg) {
        if (is_array($msg)) {
            $bag[] = $msg;
        }
    }
    if (is_array($meta['first'] ?? null)) {
        $bag[] = $meta['first'];
    }
    usort($bag, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['received_at'] ?? ''), (string) ($b['received_at'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });
    foreach ($bag as $msg) {
        $chosen = jeab_inbox_link_verify_accept($msg, '', $msg['is_note'] ?? null);
        if ($chosen !== null) {
            return $chosen;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $msg
 * @return array{email:string,msg:array<string,mixed>}|null
 */
function jeab_inbox_link_verify_accept(array $msg, string $columnFrom, mixed $columnNote): ?array
{
    if (jeab_inbox_link_verify_is_note($columnNote, $msg) || jeab_inbox_link_verify_is_outbound($msg)) {
        return null;
    }
    $raw = trim($columnFrom) !== '' ? $columnFrom : (string) ($msg['from'] ?? '');
    $email = jeab_inbox_link_verify_extract_email($raw);
    if ($email === '') {
        return null;
    }
    if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email)) {
        return null;
    }

    return ['email' => $email, 'msg' => $msg];
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_inbox_link_verify_is_note(mixed $columnNote, array $msg): bool
{
    if ($columnNote === true || $columnNote === 1 || $columnNote === '1') {
        return true;
    }
    $flag = $msg['is_note'] ?? null;

    return $flag === true || $flag === 1 || $flag === '1';
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_inbox_link_verify_is_outbound(array $msg): bool
{
    $source = strtolower(trim((string) ($msg['source'] ?? '')));

    return $source !== '' && str_starts_with($source, 'outbound');
}

function jeab_inbox_link_verify_extract_email(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/<([^<>]+)>/', $raw, $match) === 1) {
        $raw = trim((string) $match[1]);
    }
    $norm = function_exists('jeab_norm_email') ? jeab_norm_email($raw) : strtolower($raw);
    if ($norm === '' || !str_contains($norm, '@')) {
        return '';
    }

    return $norm;
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_inbox_link_verify_message_phone(array $msg): string
{
    foreach (['phone', 'mobile', 'from_phone', 'sender_phone'] as $key) {
        $norm = jeab_inbox_link_verify_norm_phone(jeab_inbox_link_verify_text($msg[$key] ?? ''));
        if ($norm !== '') {
            return $norm;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $meta
 */
function jeab_inbox_link_verify_lead_phone(array $meta): string
{
    $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
    foreach (['phone', 'mobile'] as $key) {
        $norm = jeab_inbox_link_verify_norm_phone(jeab_inbox_link_verify_text($lead[$key] ?? ''));
        if ($norm !== '') {
            return $norm;
        }
    }

    return '';
}

function jeab_inbox_link_verify_text(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return '';
}

function jeab_inbox_link_verify_norm_phone(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('jeab_norm_phone_e164')) {
        return jeab_norm_phone_e164($raw);
    }
    if (function_exists('jeab_norm_phone')) {
        return jeab_norm_phone($raw);
    }

    return '';
}

/**
 * @return array<string, mixed>
 */
function jeab_inbox_link_verify_meta(PDO $pdo, string $table, string $id): array
{
    if ($id === '' || !in_array($table, ['inkorg_list', 'inkorg_threads'], true)) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT row_json FROM ' . $table . ' WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return [];
    }
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array{0:array<string,true>,1:array<string,true>}
 */
function jeab_inbox_link_verify_contact(PDO $pdo, string $contactId): array
{
    $emails = [];
    $phones = [];
    if ($contactId === '') {
        return [$emails, $phones];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT row_json, owner_email, owner_phone, email2, email3 FROM contacts WHERE id = ?'
        );
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            jeab_inbox_link_verify_add_email($emails, (string) ($row['owner_email'] ?? ''));
            jeab_inbox_link_verify_add_email($emails, (string) ($row['email2'] ?? ''));
            jeab_inbox_link_verify_add_email($emails, (string) ($row['email3'] ?? ''));
            jeab_inbox_link_verify_add_phone($phones, (string) ($row['owner_phone'] ?? ''));
            jeab_inbox_link_verify_harvest_json($emails, $phones, (string) ($row['row_json'] ?? ''));
        }
    } catch (Throwable) {
    }
    try {
        $stmt = $pdo->prepare('SELECT row_json, email, mobile FROM inkorg_contacts WHERE id = ?');
        $stmt->execute([$contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            jeab_inbox_link_verify_add_email($emails, (string) ($row['email'] ?? ''));
            jeab_inbox_link_verify_add_phone($phones, (string) ($row['mobile'] ?? ''));
            jeab_inbox_link_verify_harvest_json($emails, $phones, (string) ($row['row_json'] ?? ''));
        }
    } catch (Throwable) {
    }

    return [$emails, $phones];
}

/**
 * @param array<string, true> $emails
 * @param array<string, true> $phones
 */
function jeab_inbox_link_verify_harvest_json(array &$emails, array &$phones, string $raw): void
{
    if ($raw === '') {
        return;
    }
    $row = json_decode($raw, true);
    if (!is_array($row)) {
        return;
    }
    if (function_exists('jeab_inkorg_contact_emails_of')) {
        foreach (jeab_inkorg_contact_emails_of($row) as $email) {
            jeab_inbox_link_verify_add_email($emails, (string) $email);
        }
    }
    if (function_exists('jeab_kontakt_row_email')) {
        jeab_inbox_link_verify_add_email($emails, jeab_kontakt_row_email($row));
    }
    if (function_exists('jeab_kontakt_row_phone')) {
        jeab_inbox_link_verify_add_phone($phones, jeab_kontakt_row_phone($row));
    }
    foreach (['email', 'owner_email', 'email2', 'email3'] as $key) {
        jeab_inbox_link_verify_add_email($emails, (string) ($row[$key] ?? ''));
    }
    foreach (is_array($row['emails'] ?? null) ? $row['emails'] : [] as $email) {
        jeab_inbox_link_verify_add_email($emails, (string) $email);
    }
    foreach (['phone', 'mobile', 'telefon', 'mobil', 'owner_phone', 'customer_phone'] as $key) {
        jeab_inbox_link_verify_add_phone($phones, jeab_inbox_link_verify_text($row[$key] ?? ''));
    }
    foreach (is_array($row['phones'] ?? null) ? $row['phones'] : [] as $phone) {
        jeab_inbox_link_verify_add_phone($phones, jeab_inbox_link_verify_text($phone));
    }
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        jeab_inbox_link_verify_add_email($emails, jeab_inbox_link_verify_text($owner['email'] ?? ''));
        foreach (['phone', 'mobile', 'telefon', 'mobil'] as $key) {
            jeab_inbox_link_verify_add_phone($phones, jeab_inbox_link_verify_text($owner[$key] ?? ''));
        }
    }
}

/**
 * @param array<string, true> $set
 */
function jeab_inbox_link_verify_add_email(array &$set, string $raw): void
{
    $norm = jeab_inbox_link_verify_extract_email($raw);
    if ($norm !== '') {
        $set[$norm] = true;
    }
}

/**
 * @param array<string, true> $set
 */
function jeab_inbox_link_verify_add_phone(array &$set, string $raw): void
{
    $norm = jeab_inbox_link_verify_norm_phone($raw);
    if ($norm !== '') {
        $set[$norm] = true;
    }
}
