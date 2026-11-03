<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Läs-hooks för kontakter. Räknar och läser text. Skriver ingen fil,
 * loggar ingen brödtext och skickar inget.
 * kontakter.stats ger bara antal. kontakter.text_export ger en sida kontakter
 * med inkommande text. Citerat svar, bilaga och {#HS:}-kod tas bort.
 * Signaturen ligger kvar i ett eget fält, liksom formulärfält.
 */

function jeab_kontakter_export_read_json(string $path): ?array
{
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function jeab_kontakter_export_data_dir(): string
{
    return function_exists('jeab_data_dir') ? jeab_data_dir() : '';
}

function jeab_kontakter_export_time(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);

    return $ts === false ? null : $ts;
}

function jeab_kontakter_export_flag(array $input, string $key): bool
{
    if (!array_key_exists($key, $input)) {
        return false;
    }
    $value = $input[$key];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function jeab_kontakter_export_limit(array $input): int
{
    if (!array_key_exists('limit', $input) || $input['limit'] === '' || $input['limit'] === null) {
        return 100;
    }
    $n = (int) $input['limit'];
    if ($n < 1) {
        return 100;
    }

    return min(200, $n);
}

function jeab_kontakter_export_max_chars(array $input): int
{
    if (!array_key_exists('max_chars_per_message', $input) || $input['max_chars_per_message'] === '' || $input['max_chars_per_message'] === null) {
        return 4000;
    }
    $n = (int) $input['max_chars_per_message'];
    if ($n < 1) {
        return 4000;
    }

    return min(100000, $n);
}

/** @param array<string, mixed> $input @return list<string> */
function jeab_kontakter_export_sources(array $input): array
{
    $raw = $input['sources'] ?? null;
    if (is_string($raw)) {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];
    } elseif (is_array($raw)) {
        $parts = $raw;
    } else {
        return [];
    }
    $allow = ['hs' => true, 'inbox' => true, 'forms' => true, 'quotes' => true];
    $out = [];
    foreach ($parts as $part) {
        $part = strtolower(trim((string) $part));
        if (isset($allow[$part]) && !isset($out[$part])) {
            $out[$part] = true;
        }
    }

    return array_keys($out);
}

function jeab_kontakter_export_since(array $input): ?int
{
    return jeab_kontakter_export_time((string) ($input['since'] ?? ''));
}

/** @param array<string, mixed> $row */
function jeab_kontakter_export_full_address(array $row): bool
{
    $street = trim((string) ($row['street'] ?? ''));
    $city = trim((string) ($row['city'] ?? ''));
    if ($street === '' || $city === '') {
        return false;
    }
    $key = function_exists('jeab_kontakt_street_key') ? jeab_kontakt_street_key($street) : '';

    return $key !== '';
}

/** @param array<string, mixed> $row */
function jeab_kontakter_export_has_email(array $row): bool
{
    if (function_exists('jeab_kontakt_row_emails')) {
        return jeab_kontakt_row_emails($row) !== [];
    }

    return trim((string) ($row['email'] ?? '')) !== '';
}

/** @param array<string, mixed> $row */
function jeab_kontakter_export_has_phone(array $row): bool
{
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner) && trim((string) ($owner['phone'] ?? '')) !== '') {
            return true;
        }
    }

    if (trim((string) ($row['phone'] ?? '')) !== '') {
        return true;
    }

    return trim((string) ($row['phone2'] ?? '')) !== '';
}

/** @param array<string, mixed> $row */
function jeab_kontakter_export_email(array $row): string
{
    if (function_exists('jeab_kontakt_row_email')) {
        $email = jeab_kontakt_row_email($row);
        if ($email !== '') {
            return $email;
        }
    }
    if (function_exists('jeab_kontakt_row_emails')) {
        $emails = jeab_kontakt_row_emails($row);

        return (string) ($emails[0] ?? '');
    }

    return function_exists('jeab_kontakt_email') ? jeab_kontakt_email((string) ($row['email'] ?? '')) : trim((string) ($row['email'] ?? ''));
}

/** @param array<string, mixed> $row */
function jeab_kontakter_export_phone(array $row): string
{
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (!empty($owner['contact']) && trim((string) ($owner['phone'] ?? '')) !== '') {
            return trim((string) $owner['phone']);
        }
    }
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner) && trim((string) ($owner['phone'] ?? '')) !== '') {
            return trim((string) $owner['phone']);
        }
    }

    $phone = trim((string) ($row['phone'] ?? ''));
    if ($phone !== '') {
        return $phone;
    }

    return trim((string) ($row['phone2'] ?? ''));
}

/** @param array<string, mixed> $row */
function jeab_kontakter_export_explicit_source(array $row): string
{
    $source = strtolower(trim((string) ($row['source'] ?? '')));
    if ($source === '') {
        return '';
    }
    if (str_contains($source, 'helpscout') || str_contains($source, 'hs-import') || str_contains($source, 'hs_import') || $source === 'hs') {
        return 'hs_import';
    }
    if (in_array($source, ['manual', 'manuell', 'ui'], true)) {
        return 'manual';
    }
    if ($source === 'link' || str_starts_with($source, 'link')) {
        return 'link';
    }
    if (in_array($source, ['inbox', 'graph', 'bridge', 'nylas', 'mail', 'webhook'], true) || str_starts_with($source, 'inbox')) {
        return 'inbox';
    }

    return '';
}

/**
 * @return array{hs: array<string, true>, inbox: array<string, true>, link: array<string, true>}
 */
function jeab_kontakter_export_link_sets(): array
{
    $hs = [];
    $inbox = [];
    $link = [];
    foreach (jeab_kontakter_export_hs_index() as $row) {
        if ($row['contact_id'] !== '') {
            $hs[$row['contact_id']] = true;
        }
    }
    if (function_exists('jeab_inkorg_threads_meta')) {
        foreach (jeab_inkorg_threads_meta() as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $cid = trim((string) ($thread['contact_id'] ?? ''));
            if ($cid === '') {
                continue;
            }
            $import = strtolower(trim((string) ($thread['import_source'] ?? '')));
            if ($import === 'helpscout_import') {
                $hs[$cid] = true;
            } else {
                $inbox[$cid] = true;
            }
        }
    }
    $ordersSql = function_exists('jeab_purchases_storage_mode') && jeab_purchases_storage_mode() === 'sql';
    if ($ordersSql && function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
        if (is_object($store) && method_exists($store, 'listReminders')) {
            foreach ($store->listReminders(true) as $row) {
                jeab_kontakter_export_link_id($link, $row);
            }
        }
        if (is_object($store) && method_exists($store, 'listAllBookings')) {
            foreach ($store->listAllBookings() as $row) {
                jeab_kontakter_export_link_id($link, $row);
            }
        }
    } else {
        $dir = jeab_kontakter_export_data_dir();
        $data = jeab_kontakter_export_read_json($dir . '/store.json');
        if (is_array($data)) {
            foreach (jeab_kontakter_export_link_doc_rows($data) as $row) {
                jeab_kontakter_export_link_id($link, $row);
            }
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            jeab_kontakter_export_link_id($link, $row);
        }
    }

    return ['hs' => $hs, 'inbox' => $inbox, 'link' => $link];
}

/** @param array<string, true> $link @param mixed $row */
function jeab_kontakter_export_link_id(array &$link, mixed $row): void
{
    if (!is_array($row)) {
        return;
    }
    $cid = trim((string) ($row['contact_id'] ?? ''));
    if ($cid !== '') {
        $link[$cid] = true;
    }
}

/**
 * @param array<string, mixed> $data
 * @return list<array<string, mixed>>
 */
function jeab_kontakter_export_link_doc_rows(array $data): array
{
    $rows = [];
    $wrapped = isset($data['reminders']) || isset($data['bookings']) || isset($data['orders']) || isset($data['purchases']);
    if ($wrapped) {
        foreach (['reminders', 'bookings', 'orders', 'purchases'] as $key) {
            foreach ((array) ($data[$key] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
    foreach ($data as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @param array{hs: array<string, true>, inbox: array<string, true>, link: array<string, true>} $sets
 */
function jeab_kontakter_export_classify(array $row, string $id, array $sets): string
{
    $explicit = jeab_kontakter_export_explicit_source($row);
    if ($explicit !== '') {
        return $explicit;
    }
    if (isset($sets['hs'][$id])) {
        return 'hs_import';
    }
    if (isset($sets['inbox'][$id])) {
        return 'inbox';
    }
    if (isset($sets['link'][$id])) {
        return 'link';
    }

    return '';
}

/** @return array<string, mixed> */
function jeab_kontakter_stats(): array
{
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $sets = jeab_kontakter_export_link_sets();
    $total = 0;
    $full = 0;
    $channel = 0;
    $none = 0;
    $by = ['hs_import' => 0, 'inbox' => 0, 'link' => 0, 'manual' => 0];
    foreach ($contacts as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? $key);
        $total++;
        $hasFull = jeab_kontakter_export_full_address($row);
        $hasChannel = jeab_kontakter_export_has_email($row) || jeab_kontakter_export_has_phone($row);
        if ($hasFull) {
            $full++;
        } elseif ($hasChannel) {
            $channel++;
        } else {
            $none++;
        }
        $bucket = jeab_kontakter_export_classify($row, $id, $sets);
        if (isset($by[$bucket])) {
            $by[$bucket]++;
        }
    }

    return [
        'ok' => true,
        'total' => $total,
        'with_full_address' => $full,
        'only_email_or_phone' => $channel,
        'none' => $none,
        'by_source' => $by,
    ];
}

function jeab_kontakter_export_pnr_date(string $digits): bool
{
    if (strlen($digits) === 12) {
        $year = (int) substr($digits, 0, 4);
        $month = (int) substr($digits, 4, 2);
        $day = (int) substr($digits, 6, 2);
    } elseif (strlen($digits) === 10) {
        $yy = (int) substr($digits, 0, 2);
        $year = $yy + ($yy > (int) date('y') ? 1900 : 2000);
        $month = (int) substr($digits, 2, 2);
        $day = (int) substr($digits, 4, 2);
    } else {
        return false;
    }
    if ($month < 1 || $month > 12) {
        return false;
    }
    if ($day > 60) {
        $day -= 60;
    }

    return $day >= 1 && checkdate($month, $day, $year);
}

function jeab_kontakter_export_luhn(string $digits): bool
{
    if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
        return false;
    }
    $sum = 0;
    $alt = false;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $n = (int) $digits[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) {
                $n -= 9;
            }
        }
        $sum += $n;
        $alt = !$alt;
    }

    return $sum % 10 === 0;
}

function jeab_kontakter_export_mask(string $text): string
{
    if ($text === '') {
        return '';
    }
    $text = preg_replace_callback(
        '/(?<![\d])((?:(?:19|20)\d{6})|\d{6})[ \t]*[-+‐‑–—−][ \t]*(\d{4})(?!\d)/u',
        static function (array $m): string {
            $digits = $m[1] . $m[2];
            if (!jeab_kontakter_export_pnr_date($digits)) {
                return $m[0];
            }

            return '[PNR]';
        },
        $text
    ) ?? $text;
    $text = preg_replace_callback(
        '/(?<![\d])(\d{12}|\d{10})(?!\d)/',
        static function (array $m): string {
            if (function_exists('jeab_norm_pnr') && jeab_norm_pnr($m[1]) !== '') {
                return '[PNR]';
            }
            if (jeab_kontakter_export_pnr_date($m[1]) && function_exists('jeab_norm_pnr_luhn')) {
                $ten = strlen($m[1]) === 12 ? substr($m[1], 2) : $m[1];
                if (jeab_norm_pnr_luhn($ten)) {
                    return '[PNR]';
                }
            }

            return $m[0];
        },
        $text
    ) ?? $text;
    $text = preg_replace_callback(
        '/(?<![\dA-Z])(?:\d[ -]?){12,18}\d(?![\d])/i',
        static function (array $m): string {
            $digits = preg_replace('/\D+/', '', $m[0]) ?? '';
            $len = strlen($digits);
            if ($len < 13 || $len > 19 || !jeab_kontakter_export_luhn($digits)) {
                return $m[0];
            }

            return '[KONTO]';
        },
        $text
    ) ?? $text;
    $text = preg_replace(
        '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b/',
        '[KONTO]',
        $text
    ) ?? $text;
    $text = preg_replace(
        '/(?<![\d])\d{4}[ -]\d{6,12}(?!\d)/',
        '[KONTO]',
        $text
    ) ?? $text;

    return $text;
}

/** @param mixed $value */
function jeab_kontakter_export_mask_value(mixed $value): mixed
{
    if (is_string($value)) {
        return jeab_kontakter_export_mask($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $out = [];
    foreach ($value as $key => $item) {
        $maskedKey = is_string($key) ? jeab_kontakter_export_mask($key) : $key;
        $out[$maskedKey] = jeab_kontakter_export_mask_value($item);
    }

    return $out;
}

function jeab_kontakter_export_clip(string $text, int $max): string
{
    if ($max < 1) {
        return '';
    }
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return mb_substr($text, 0, $max);
}

/**
 * Delar av signaturen. Den lämnas kvar, eftersom adress och telefon ofta står där.
 *
 * @return array{0: string, 1: string}
 */
function jeab_kontakter_export_split_signature(string $text): array
{
    $lines = preg_split("/\n/", str_replace("\r", '', $text)) ?: [];
    $cut = count($lines);
    foreach ($lines as $i => $line) {
        $t = trim((string) $line);
        if ($t === '') {
            continue;
        }
        if (preg_match('/^(?:--|—|–)\s*$/u', $t) === 1) {
            $cut = (int) $i;
            break;
        }
        if (preg_match('/^(?:med vänliga hälsningar|med vänlig hälsning|vänliga hälsningar|vänlig hälsning|best regards|kind regards|mvh|m\.v\.h\.|hälsningar|sent from my |skickat från min )/iu', $t) === 1) {
            $cut = (int) $i;
            break;
        }
    }
    $body = trim(implode("\n", array_slice($lines, 0, $cut)));
    $signature = trim(implode("\n", array_slice($lines, $cut)));

    return [$body, $signature];
}

/**
 * @return array{0: string, 1: array<string, string>}
 */
function jeab_kontakter_export_split_fields(string $text): array
{
    $fields = [];
    $prose = [];
    foreach (preg_split("/\n/", str_replace("\r", '', $text)) ?: [] as $line) {
        $trim = trim((string) $line);
        if (preg_match('/^([^:\n]{1,40}):\s*(\S.*)$/u', $trim, $m) === 1) {
            $key = trim((string) $m[1]);
            if (preg_match('/^(?:https?|re|sv|fw)$/i', $key) !== 1) {
                $fields[$key] = trim((string) $m[2]);
                continue;
            }
        }
        $prose[] = (string) $line;
    }
    $body = trim(implode("\n", $prose));
    $body = preg_replace("/\n{3,}/u", "\n\n", $body) ?? $body;

    return [trim($body), $fields];
}

/**
 * @param array<string, mixed> $msg
 * @return array{0: string, 1: array<string, string>, 2: string}
 */
function jeab_kontakter_export_message_text(array $msg): array
{
    if (function_exists('jeab_inkorg_clean_prepared')) {
        $text = jeab_inkorg_clean_prepared($msg);
    } else {
        $text = (string) ($msg['body'] ?? '');
        if (function_exists('jeab_inkorg_clean_cut_quotes')) {
            $lines = jeab_inkorg_clean_cut_quotes(preg_split("/\n/", str_replace("\r", '', $text)) ?: []);
            $text = implode("\n", $lines);
        }
    }
    if (function_exists('jeab_inkorg_clean_collapse')) {
        $text = jeab_inkorg_clean_collapse($text);
    }
    if (function_exists('jeab_inkorg_strip_hs_junk')) {
        $text = jeab_inkorg_strip_hs_junk($text);
    }
    $text = preg_replace('/\{#HS:[^{}]*\}/u', '', $text) ?? $text;
    $text = preg_replace('/\[Bilaga:[^\]]*\]/iu', '', $text) ?? $text;
    $text = preg_replace('/^.*\bhelpscout\.(?:net|com)\b.*$/imu', '', $text) ?? $text;
    [$text, $signature] = jeab_kontakter_export_split_signature($text);
    [$body, $fields] = jeab_kontakter_export_split_fields($text);

    return [$body, $fields, $signature];
}

/** @param array<string, mixed> $msg */
function jeab_kontakter_export_incoming(array $msg): bool
{
    if (!empty($msg['internal_note'])) {
        return false;
    }
    $type = strtolower(trim((string) ($msg['hs_thread_type'] ?? '')));
    if ($type === 'note') {
        return false;
    }
    $src = strtolower(trim((string) ($msg['source'] ?? '')));
    if (str_starts_with($src, 'outbound')) {
        return false;
    }
    $direction = strtolower(trim((string) ($msg['direction'] ?? '')));
    if (in_array($direction, ['out', 'outgoing', 'outbound'], true)) {
        return false;
    }
    $from = (string) ($msg['from'] ?? '');
    if ($from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $thread
 */
function jeab_kontakter_export_message_source(array $msg, array $thread): string
{
    $raw = strtolower(trim((string) ($msg['source'] ?? '')));
    $import = strtolower(trim((string) ($thread['import_source'] ?? $msg['import_source'] ?? '')));
    if ($raw === 'helpscout_import' || $import === 'helpscout_import' || str_contains($raw, 'helpscout')) {
        return 'hs';
    }
    $from = strtolower((string) ($msg['from'] ?? ''));
    $host = '';
    if (preg_match('/@([a-z0-9.-]+)/', $from, $m) === 1) {
        $host = $m[1];
    }
    if (str_contains($host, 'wix')) {
        return 'wix';
    }
    if (str_contains($host, 'hitta')) {
        return 'hitta';
    }
    if (str_contains($host, 'kalkyl')) {
        return 'kalkyl';
    }
    $kind = strtolower(trim((string) ($msg['kind'] ?? '')));
    $label = strtolower(trim((string) (is_array($msg['labels'] ?? null) ? ($msg['labels']['type'] ?? '') : '')));
    if (in_array($kind, ['offert', 'quote'], true) || in_array($raw, ['quote', 'offert'], true) || $label === 'offert') {
        return 'quote';
    }

    return 'inbox';
}

function jeab_kontakter_export_source_bucket(string $messageSource): string
{
    return match ($messageSource) {
        'hs' => 'hs',
        'wix', 'hitta', 'kalkyl' => 'forms',
        'quote' => 'quotes',
        default => 'inbox',
    };
}

/** @param array<string, mixed> $msg */
function jeab_kontakter_export_message_email(array $msg): string
{
    if (function_exists('jeab_inkorg_resolve_customer')) {
        $resolved = jeab_inkorg_resolve_customer($msg);
        $email = trim((string) ($resolved['email'] ?? ''));
        if ($email !== '') {
            return function_exists('jeab_kontakt_email') ? jeab_kontakt_email($email) : strtolower($email);
        }
    }
    $from = (string) ($msg['from'] ?? '');
    if (function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($from)) {
        return '';
    }
    if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($from)) {
        return '';
    }

    return function_exists('jeab_kontakt_email') ? jeab_kontakt_email($from) : strtolower(trim($from));
}

/** @return list<array<string, mixed>> */
function jeab_kontakter_export_inbox_threads(): array
{
    $rows = function_exists('jeab_inkorg_messages') ? jeab_inkorg_messages() : [];
    $meta = function_exists('jeab_inkorg_threads_meta') ? jeab_inkorg_threads_meta() : [];
    $grouped = [];
    foreach ($rows as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $tid = trim((string) ($msg['thread_id'] ?? $msg['id'] ?? ''));
        if ($tid === '') {
            continue;
        }
        $grouped[$tid][] = $msg;
    }
    $out = [];
    foreach ($grouped as $tid => $msgs) {
        $t = is_array($meta[$tid] ?? null) ? $meta[$tid] : [];
        $customer = (string) ($t['customer_email'] ?? '');
        $out[] = [
            'id' => (string) $tid,
            'messages' => $msgs,
            'contact_id' => trim((string) ($t['contact_id'] ?? '')),
            'import_source' => trim((string) ($t['import_source'] ?? '')),
            'customer_email' => function_exists('jeab_kontakt_email') ? jeab_kontakt_email($customer) : strtolower(trim($customer)),
            'origin' => 'inbox',
        ];
    }

    return $out;
}

/** @return list<array{id: string, contact_id: string, from: string}> */
function jeab_kontakter_export_hs_index(): array
{
    if (!function_exists('jeab_hs_import_index')) {
        return [];
    }
    $index = jeab_hs_import_index();
    $out = [];
    foreach (is_array($index['by_id'] ?? null) ? $index['by_id'] : [] as $row) {
        if (!is_array($row) || !empty($row['deleted'])) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $from = (string) ($row['from'] ?? '');
        $out[] = [
            'id' => $id,
            'contact_id' => trim((string) ($row['contact_id'] ?? '')),
            'from' => function_exists('jeab_kontakt_email') ? jeab_kontakt_email($from) : strtolower(trim($from)),
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function jeab_kontakter_export_hs_thread(string $id): ?array
{
    if ($id === '' || !function_exists('jeab_hs_import_read_conv')) {
        return null;
    }
    $conv = jeab_hs_import_read_conv($id);
    if (!is_array($conv)) {
        return null;
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $msgs = [];
    foreach ((array) ($conv['messages'] ?? []) as $msg) {
        if (is_array($msg)) {
            $msgs[] = $msg;
        }
    }
    $customer = (string) ($meta['customer_email'] ?? $conv['customer_email'] ?? '');

    return [
        'id' => (string) ($conv['id'] ?? $id),
        'messages' => $msgs,
        'contact_id' => trim((string) ($meta['contact_id'] ?? $conv['contact_id'] ?? '')),
        'import_source' => 'helpscout_import',
        'customer_email' => function_exists('jeab_kontakt_email') ? jeab_kontakt_email($customer) : strtolower(trim($customer)),
        'origin' => 'hs',
    ];
}

/**
 * @param array<string, list<string>> $emailIndex
 * @param array<string, mixed> $thread
 * @return list<string>
 */
function jeab_kontakter_export_thread_contact_ids(array $thread, array $emailIndex): array
{
    $ids = [];
    $cid = trim((string) ($thread['contact_id'] ?? ''));
    if ($cid !== '') {
        $ids[$cid] = true;
    }
    $emails = [];
    $customer = trim((string) ($thread['customer_email'] ?? ''));
    if ($customer !== '') {
        $emails[] = $customer;
    }
    foreach ((array) ($thread['messages'] ?? []) as $msg) {
        if (!is_array($msg) || !jeab_kontakter_export_incoming($msg)) {
            continue;
        }
        $email = jeab_kontakter_export_message_email($msg);
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    foreach ($emails as $email) {
        foreach ($emailIndex[$email] ?? [] as $id) {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

/**
 * @param array<string, array<string, mixed>> $contacts
 * @return array<string, list<string>>
 */
function jeab_kontakter_export_email_index(array $contacts): array
{
    $out = [];
    foreach ($contacts as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? $key);
        $emails = function_exists('jeab_kontakt_row_emails') ? jeab_kontakt_row_emails($row) : [];
        if ($emails === []) {
            $one = jeab_kontakter_export_email($row);
            if ($one !== '') {
                $emails = [$one];
            }
        }
        foreach ($emails as $email) {
            $out[$email][] = $id;
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $threads
 * @param array<string, list<string>> $emailIndex
 * @return list<array<string, mixed>>
 */
function jeab_kontakter_export_tag_threads(array $threads, array $emailIndex): array
{
    $out = [];
    foreach ($threads as $thread) {
        $ids = jeab_kontakter_export_thread_contact_ids($thread, $emailIndex);
        $thread['_ids'] = array_fill_keys($ids, true);
        $out[] = $thread;
    }

    return $out;
}

/**
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $thread
 * @param list<string> $sources
 */
function jeab_kontakter_export_message_allowed(array $msg, array $thread, array $sources, ?int $since): bool
{
    if (!jeab_kontakter_export_incoming($msg)) {
        return false;
    }
    $bucket = jeab_kontakter_export_source_bucket(jeab_kontakter_export_message_source($msg, $thread));
    if ($sources !== [] && !in_array($bucket, $sources, true)) {
        return false;
    }
    if ($since !== null) {
        $at = jeab_kontakter_export_time((string) ($msg['received_at'] ?? $msg['date'] ?? ''));
        if ($at === null || $at < $since) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $thread
 * @return array<string, mixed>
 */
function jeab_kontakter_export_public_message(array $msg, array $thread, int $maxChars): array
{
    [$body, $fields, $signature] = jeab_kontakter_export_message_text($msg);
    $body = jeab_kontakter_export_clip(jeab_kontakter_export_mask($body), $maxChars);
    $signature = jeab_kontakter_export_clip(jeab_kontakter_export_mask($signature), $maxChars);
    $publicFields = [];
    foreach ($fields as $key => $value) {
        $publicFields[jeab_kontakter_export_clip(jeab_kontakter_export_mask((string) $key), $maxChars)] = jeab_kontakter_export_clip(jeab_kontakter_export_mask($value), $maxChars);
    }
    $subject = (string) ($msg['subject'] ?? '');
    if (function_exists('jeab_inkorg_strip_hs_junk')) {
        $subject = jeab_inkorg_strip_hs_junk($subject);
    }
    $subject = jeab_kontakter_export_clip(jeab_kontakter_export_mask($subject), min(300, $maxChars));
    $date = trim((string) ($msg['received_at'] ?? $msg['date'] ?? ''));

    return [
        'source' => jeab_kontakter_export_message_source($msg, $thread),
        'thread_id' => (string) ($thread['id'] ?? $msg['thread_id'] ?? ''),
        'date' => $date,
        'direction' => 'incoming',
        'subject' => $subject,
        'body' => $body,
        'signature' => $signature,
        'form_fields' => $publicFields === [] ? new stdClass() : $publicFields,
    ];
}

/**
 * @param list<array<string, mixed>> $inbox
 * @param list<array{id: string, contact_id: string, from: string}> $hsIndex
 * @param list<string> $emails
 * @param list<string> $sources
 * @return list<array<string, mixed>>
 */
function jeab_kontakter_export_messages_for(string $id, array $emails, array $inbox, array $hsIndex, array $sources, ?int $since, int $maxChars): array
{
    $messages = [];
    $seen = [];
    $push = static function (array $thread) use (&$messages, &$seen, $id, $sources, $since, $maxChars): void {
        $ids = is_array($thread['_ids'] ?? null) ? $thread['_ids'] : [];
        if (!isset($ids[$id])) {
            return;
        }
        foreach ((array) ($thread['messages'] ?? []) as $msg) {
            if (!is_array($msg) || !jeab_kontakter_export_message_allowed($msg, $thread, $sources, $since)) {
                continue;
            }
            $key = (string) ($thread['id'] ?? '') . '|' . (string) ($msg['id'] ?? '') . '|' . (string) ($msg['received_at'] ?? '') . '|' . (string) ($msg['subject'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $messages[] = jeab_kontakter_export_public_message($msg, $thread, $maxChars);
        }
    };
    foreach ($inbox as $thread) {
        $push($thread);
    }
    $wantHs = $sources === [] || in_array('hs', $sources, true);
    if ($wantHs) {
        $emailSet = array_fill_keys($emails, true);
        foreach ($hsIndex as $row) {
            $hit = $row['contact_id'] === $id || ($row['from'] !== '' && isset($emailSet[$row['from']]));
            if (!$hit) {
                continue;
            }
            $thread = jeab_kontakter_export_hs_thread($row['id']);
            if ($thread === null) {
                continue;
            }
            if ($thread['contact_id'] === '') {
                $thread['contact_id'] = $row['contact_id'];
            }
            $thread['_ids'] = [$id => true];
            if ($row['contact_id'] !== '' && $row['contact_id'] !== $id && !isset($emailSet[$row['from']])) {
                continue;
            }
            $push($thread);
        }
    }
    usort($messages, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['thread_id'] ?? ''), (string) ($b['thread_id'] ?? ''));
    });

    return $messages;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_text_export(array $input): array
{
    $contacts = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    $rows = [];
    foreach ($contacts as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? $key);
        if ($id === '') {
            continue;
        }
        $row['id'] = $id;
        $rows[$id] = $row;
    }
    $emailIndex = jeab_kontakter_export_email_index($rows);
    $inbox = jeab_kontakter_export_tag_threads(jeab_kontakter_export_inbox_threads(), $emailIndex);
    $hsIndex = jeab_kontakter_export_hs_index();
    $sources = jeab_kontakter_export_sources($input);
    $since = jeab_kontakter_export_since($input);
    $missing = jeab_kontakter_export_flag($input, 'missing_address');
    $ids = [];
    foreach ($rows as $id => $row) {
        if ($missing && jeab_kontakter_export_full_address($row)) {
            continue;
        }
        if ($since !== null) {
            $at = jeab_kontakter_export_time((string) ($row['updated_at'] ?? ''));
            if ($at === null) {
                $at = jeab_kontakter_export_time((string) ($row['created_at'] ?? ''));
            }
            if ($at === null || $at < $since) {
                continue;
            }
        }
        if ($sources !== []) {
            $emails = [];
            foreach ($emailIndex as $email => $owners) {
                if (in_array($id, $owners, true)) {
                    $emails[] = $email;
                }
            }
            $emailSet = array_fill_keys($emails, true);
            $hit = false;
            if (in_array('hs', $sources, true)) {
                foreach ($hsIndex as $hsRow) {
                    if ($hsRow['contact_id'] === $id || ($hsRow['from'] !== '' && isset($emailSet[$hsRow['from']]))) {
                        $hit = true;
                        break;
                    }
                }
            }
            if (!$hit) {
                foreach ($inbox as $thread) {
                    $linked = is_array($thread['_ids'] ?? null) ? $thread['_ids'] : [];
                    if (!isset($linked[$id])) {
                        continue;
                    }
                    foreach ((array) ($thread['messages'] ?? []) as $msg) {
                        if (is_array($msg) && jeab_kontakter_export_message_allowed($msg, $thread, $sources, null)) {
                            $hit = true;
                            break 2;
                        }
                    }
                }
            }
            if (!$hit) {
                continue;
            }
        }
        $ids[] = $id;
    }
    usort($ids, 'strcmp');
    $total = count($ids);
    $cursor = trim((string) ($input['cursor'] ?? ''));
    if ($cursor !== '') {
        $ids = array_values(array_filter($ids, static function (string $id) use ($cursor): bool {
            return strcmp($id, $cursor) > 0;
        }));
    }
    $offset = array_key_exists('offset', $input) ? max(0, (int) $input['offset']) : 0;
    $limit = jeab_kontakter_export_limit($input);
    $window = count($ids);
    $page = array_slice($ids, $offset, $limit);
    $maxChars = jeab_kontakter_export_max_chars($input);
    $out = [];
    foreach ($page as $id) {
        $row = $rows[$id];
        $emails = [];
        foreach ($emailIndex as $email => $owners) {
            if (in_array($id, $owners, true)) {
                $emails[] = (string) $email;
            }
        }
        $out[] = [
            'contact_id' => $id,
            'email' => jeab_kontakter_export_email($row),
            'email2' => function_exists('jeab_kontakt_email') ? jeab_kontakt_email((string) ($row['email2'] ?? '')) : trim((string) ($row['email2'] ?? '')),
            'email3' => function_exists('jeab_kontakt_email') ? jeab_kontakt_email((string) ($row['email3'] ?? '')) : trim((string) ($row['email3'] ?? '')),
            'phone' => jeab_kontakter_export_phone($row),
            'phone2' => function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone((string) ($row['phone2'] ?? '')) : trim((string) ($row['phone2'] ?? '')),
            'street' => trim((string) ($row['street'] ?? '')),
            'postal' => trim((string) ($row['postal_code'] ?? $row['postal'] ?? '')),
            'city' => trim((string) ($row['city'] ?? '')),
            'messages' => jeab_kontakter_export_messages_for($id, $emails, $inbox, $hsIndex, $sources, $since, $maxChars),
        ];
    }
    $next = null;
    if ($page !== [] && ($offset + count($page)) < $window) {
        $next = (string) $page[count($page) - 1];
    }

    return [
        'ok' => true,
        'next_cursor' => $next,
        'total' => $total,
        'returned' => count($out),
        'contacts' => $out,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_kontakter_export_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $action = (string) ($input['action'] ?? '');
    try {
        if ($action === 'kontakter.stats') {
            $result = jeab_kontakter_stats();
        } elseif ($action === 'kontakter.text_export') {
            $result = jeab_kontakter_text_export($input);
        } else {
            $result = ['ok' => false, 'error' => 'Okänd åtgärd.'];
        }
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'SQL saknas') {
            return ['ok' => false, 'error' => 'SQL saknas', 'status' => 503];
        }
        throw $e;
    }
    if (function_exists('jeab_kontakt_strip_secrets')) {
        $result = jeab_kontakt_strip_secrets($result);
    }
    $result = jeab_kontakter_export_mask_value($result);
    if (is_array($result)) {
        $result['status'] = !empty($result['ok']) ? 200 : 400;
    }

    return is_array($result) ? $result : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
}
