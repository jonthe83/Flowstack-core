<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/snapshots.php';
require_once __DIR__ . '/einstein_webhook.php';

function jeab_bokmalen_ellevio_fullmakt_webhook_secret_path(): string
{
    return jeab_data_file('secrets/bokmalen-ellevio-fullmakt-webhook.json');
}

function jeab_bokmalen_ellevio_fullmakt_webhook_secret(): ?array
{
    return jeab_webhook_secret_from_path(jeab_bokmalen_ellevio_fullmakt_webhook_secret_path());
}

function jeab_bokmalen_ellevio_fullmakt_is_ellevio(array $doc): bool
{
    $stamp = strtolower(trim((string) ($doc['stamp_method'] ?? '')));
    if ($stamp === 'fpdi') {
        return false;
    }
    if (function_exists('fullmakt_doc_type')) {
        $type = fullmakt_doc_type($doc);
    } else {
        $type = strtolower(trim((string) ($doc['type'] ?? 'ellevio')));
        if ($type === '') {
            $type = 'ellevio';
        }
    }
    if ($type === 'overlay') {
        return false;
    }
    if ($stamp === 'ellevio') {
        return true;
    }
    if (function_exists('fullmakt_anlaggnings_id_required')) {
        return fullmakt_anlaggnings_id_required($doc);
    }
    return true;
}

function jeab_bokmalen_ellevio_fullmakt_anlaggnings_id(array $doc): string
{
    $raw = (string) ($doc['anlaggnings_id'] ?? '');
    $digits = function_exists('fullmakt_anlaggnings_id_digits')
        ? fullmakt_anlaggnings_id_digits($raw)
        : (preg_replace('/\D+/', '', $raw) ?? '');
    return preg_match('/^\d{18}$/', $digits) === 1 ? $digits : '';
}

function jeab_bokmalen_ellevio_fullmakt_pdf_url(string $token): string
{
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    if ($token === '') {
        return '';
    }
    return 'https://flowstack.example.test/fullmakt/pdf.php?token=' . rawurlencode($token);
}

function jeab_bokmalen_ellevio_fullmakt_text(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        return trim((string) $value);
    }
    return trim((string) $value);
}

function jeab_bokmalen_ellevio_fullmakt_orders(): array
{
    try {
        if (!function_exists('jeab_purchase_all')) {
            $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
            $path = $root . '/src/purchase.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!function_exists('jeab_purchase_all')) {
            return [];
        }
        $all = jeab_purchase_all();
        return is_array($all) ? $all : [];
    } catch (Throwable) {
        return [];
    }
}

function jeab_bokmalen_ellevio_fullmakt_clean_token(mixed $raw): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $raw)) ?? '';
}

/** Ordern pekar på fullmakten via fullmakt_token eller sin egen länk (fullmakt_url). */
function jeab_bokmalen_ellevio_fullmakt_row_has_token(array $row, string $token): bool
{
    if ($token === '') {
        return false;
    }
    if (trim((string) ($row['fullmakt_token'] ?? '')) === $token) {
        return true;
    }
    $url = trim((string) ($row['fullmakt_url'] ?? ''));
    if ($url === '') {
        return false;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('#/s/([A-Za-z0-9_-]+)/?$#', $path, $m) === 1 && $m[1] === $token) {
        return true;
    }
    parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
    if (is_string($query['token'] ?? null) && $query['token'] === $token) {
        return true;
    }
    $history = $row['fullmakt_history'] ?? [];
    if (!is_array($history)) {
        return false;
    }
    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (trim((string) ($item['token'] ?? '')) === $token) {
            return true;
        }
    }
    return false;
}

/** Order-id som fullmakten sparade vid start (order_id, äldre reminder_id). */
function jeab_bokmalen_ellevio_fullmakt_doc_order_id(array $doc): int
{
    foreach (['order_id', 'reminder_id'] as $key) {
        $id = (int) ($doc[$key] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }
    return 0;
}

function jeab_bokmalen_ellevio_fullmakt_order_for_token(string $token, array $doc = []): array
{
    $token = trim($token);
    if ($token !== '') {
        foreach (jeab_bokmalen_ellevio_fullmakt_orders() as $row) {
            if (is_array($row) && jeab_bokmalen_ellevio_fullmakt_row_has_token($row, $token)) {
                return $row;
            }
        }
    }
    // Token hittades inte på någon order: ta ordern som fullmakten själv
    // sparade vid start, så länge den inte redan pekar på en annan fullmakt.
    $id = jeab_bokmalen_ellevio_fullmakt_doc_order_id($doc);
    if ($id <= 0) {
        return [];
    }
    $row = jeab_bokmalen_ellevio_fullmakt_orders()[(string) $id] ?? null;
    if (!is_array($row)) {
        return [];
    }
    $rowToken = trim((string) ($row['fullmakt_token'] ?? ''));
    return $rowToken === '' || $rowToken === $token ? $row : [];
}

/**
 * Order-id i fullmakt.signed. Först det fullmakten sparade vid start,
 * sedan ordern som hittas via token eller orderns egen fullmaktslänk.
 */
function jeab_bokmalen_ellevio_fullmakt_order_id(array $doc, array $order): string
{
    $fromDoc = jeab_bokmalen_ellevio_fullmakt_doc_order_id($doc);
    if ($fromDoc > 0) {
        return (string) $fromDoc;
    }
    foreach ([$order['reminder_id'] ?? null, $order['id'] ?? null] as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            return (string) $id;
        }
    }
    return '';
}

function jeab_bokmalen_ellevio_fullmakt_first(array $sources, array $keys): string
{
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($keys as $key) {
            $value = jeab_bokmalen_ellevio_fullmakt_text($source[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function jeab_bokmalen_ellevio_fullmakt_customer_name(array $doc, array $order): string
{
    if (($order['_party_frozen'] ?? '') === 'fullmakt') {
        $fromDoc = jeab_bokmalen_ellevio_fullmakt_first([$doc], ['namn', 'name', 'huvudsokande', 'customer_name']);
        if ($fromDoc !== '') {
            return $fromDoc;
        }
    }
    if (function_exists('jeab_contacts_strict') && jeab_contacts_strict()) {
        if ($order !== [] && function_exists('jeab_ordrar_name')) {
            $fromOrder = trim(jeab_ordrar_name($order));
            if ($fromOrder !== '') {
                return $fromOrder;
            }
        }

        return jeab_bokmalen_ellevio_fullmakt_first([$order], ['huvudsokande', 'name', 'customer_name']);
    }
    $name = jeab_bokmalen_ellevio_fullmakt_text($doc['namn'] ?? '');
    if ($name !== '') {
        return $name;
    }
    if ($order !== [] && function_exists('jeab_ordrar_name')) {
        $fromOrder = trim(jeab_ordrar_name($order));
        if ($fromOrder !== '') {
            return $fromOrder;
        }
    }
    return jeab_bokmalen_ellevio_fullmakt_first([$order], ['huvudsokande', 'name', 'customer_name']);
}

function jeab_bokmalen_ellevio_fullmakt_kw(array $order): string
{
    $raw = jeab_bokmalen_ellevio_fullmakt_text($order['vaxelriktare'] ?? '');
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*k\s*w/iu', $raw, $match) === 1) {
        return str_replace(',', '.', $match[1]);
    }
    $direct = jeab_bokmalen_ellevio_fullmakt_text($order['vaxelriktare_kw'] ?? '');
    if ($direct !== '' && preg_match('/^\d+(?:[.,]\d+)?$/', $direct) === 1) {
        return str_replace(',', '.', $direct);
    }
    $kalkyl = is_array($order['kalkyl'] ?? null) ? $order['kalkyl'] : [];
    if (!empty($kalkyl['b15'])) {
        return '12';
    }
    return '';
}

function jeab_bokmalen_ellevio_fullmakt_batteri_antal(array $order): string
{
    $direct = jeab_bokmalen_ellevio_fullmakt_text($order['batteri_antal'] ?? '');
    if ($direct !== '' && preg_match('/^\d+$/', $direct) === 1 && (int) $direct > 0) {
        return (string) (int) $direct;
    }
    $kalkyl = is_array($order['kalkyl'] ?? null) ? $order['kalkyl'] : [];
    if (isset($kalkyl['b3']) && is_numeric($kalkyl['b3']) && (float) $kalkyl['b3'] > 0) {
        return (string) (int) round((float) $kalkyl['b3']);
    }
    $lines = $order['inkop_lines'] ?? [];
    if (!is_array($lines)) {
        return '';
    }
    $sum = 0;
    $found = false;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $hay = strtolower(trim((string) ($line['title'] ?? '') . ' ' . (string) ($line['sku'] ?? '')));
        if ($hay === '' || preg_match('/panel|solpanel|solcell/u', $hay) === 1) {
            continue;
        }
        if (preg_match('/batteri|battery|modul/u', $hay) !== 1) {
            continue;
        }
        $qty = (int) ($line['qty'] ?? $line['antal'] ?? 0);
        if ($qty > 0) {
            $sum += $qty;
            $found = true;
        }
    }
    return $found ? (string) $sum : '';
}

function jeab_bokmalen_ellevio_fullmakt_sakring(array $doc, array $order): string
{
    $raw = jeab_bokmalen_ellevio_fullmakt_first(
        [$doc, $order],
        ['sakring_amp', 'sakring', 'huvudsakring']
    );
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    return $digits !== '' ? $digits : $raw;
}

function jeab_bokmalen_ellevio_fullmakt_pnr(array $doc, array $order): string
{
    if (($order['_party_frozen'] ?? '') === 'fullmakt') {
        $raw = jeab_bokmalen_ellevio_fullmakt_first([$doc], ['personnummer']);
        if ($raw === '') {
            $raw = jeab_bokmalen_ellevio_fullmakt_first([$order], ['personnummer']);
        }
        if ($raw === '') {
            return '';
        }

        return function_exists('jeab_contacts_sole_pnr_text') ? jeab_contacts_sole_pnr_text($raw) : $raw;
    }
    $fromContact = function_exists('jeab_contacts_sole_preferred_pnr') ? jeab_contacts_sole_preferred_pnr($order) : '';
    $raw = (function_exists('jeab_contacts_strict') && jeab_contacts_strict())
        ? $fromContact
        : ($fromContact !== '' ? $fromContact : jeab_bokmalen_ellevio_fullmakt_first([$doc, $order], ['personnummer']));
    if ($raw === '') {
        return '';
    }

    return function_exists('jeab_contacts_sole_pnr_text') ? jeab_contacts_sole_pnr_text($raw) : $raw;
}

function jeab_bokmalen_ellevio_fullmakt_fastighet(array $doc, array $order): string
{
    if (($order['_party_frozen'] ?? '') === 'fullmakt') {
        $fromDoc = jeab_bokmalen_ellevio_fullmakt_first([$doc], ['fastighetsbeteckning']);
        if ($fromDoc !== '') {
            return $fromDoc;
        }

        return jeab_bokmalen_ellevio_fullmakt_first([$order], ['fastighetsbeteckning']);
    }
    $fromContact = function_exists('jeab_contacts_sole_preferred_fastighet') ? jeab_contacts_sole_preferred_fastighet($order) : '';
    if ($fromContact !== '') {
        return $fromContact;
    }
    if (function_exists('jeab_contacts_strict') && jeab_contacts_strict()) {
        return '';
    }

    return jeab_bokmalen_ellevio_fullmakt_first([$doc, $order], ['fastighetsbeteckning']);
}

function jeab_bokmalen_ellevio_fullmakt_payload(array $doc): ?array
{
    if (!jeab_bokmalen_ellevio_fullmakt_is_ellevio($doc)) {
        return null;
    }
    $aid = jeab_bokmalen_ellevio_fullmakt_anlaggnings_id($doc);
    if ($aid === '') {
        return null;
    }
    $token = jeab_bokmalen_ellevio_fullmakt_clean_token($doc['token'] ?? '');
    $order = jeab_bokmalen_ellevio_fullmakt_order_for_token($token, $doc);
    if (function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($order, 'fullmakt')) {
        $order = jeab_snapshots_frozen_party($order, 'fullmakt');
    } elseif (function_exists('jeab_contacts_sole_recipient_apply')) {
        $order = jeab_contacts_sole_recipient_apply($order);
    }
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($order, 'ellevio');
        if ($block !== '') {
            return null;
        }
    }
    $orderId = jeab_bokmalen_ellevio_fullmakt_order_id($doc, $order);
    if ($orderId === '') {
        // Skickas ändå (token och anläggnings-id behövs), men aldrig tyst.
        jeab_bokmalen_ellevio_fullmakt_notify_fail('order_id saknas, ordern hittades inte via fullmakten eller token', 'fullmakt.signed');
    }
    $strict = function_exists('jeab_contacts_strict') && jeab_contacts_strict();
    $address = jeab_bokmalen_ellevio_fullmakt_first(
        $strict ? [$order] : [$doc, $order],
        ['adress', 'address', 'huvudsokande_address']
    );
    $kwh = jeab_bokmalen_ellevio_fullmakt_first([$order, $doc], ['batteri_kwh', 'kwh']);
    return [
        'type' => 'fullmakt.signed',
        'order_id' => $orderId,
        'token' => $token,
        'anlaggnings_id' => $aid,
        'customer_name' => jeab_bokmalen_ellevio_fullmakt_customer_name($doc, $order),
        'address' => $address,
        'postnummer' => jeab_bokmalen_ellevio_fullmakt_first([$doc, $order], ['postnummer', 'postnr']),
        'ort' => jeab_bokmalen_ellevio_fullmakt_first([$doc, $order], ['ort']),
        'telefon' => jeab_bokmalen_ellevio_fullmakt_first([$doc, $order], ['telefon', 'phone']),
        'epost' => jeab_bokmalen_ellevio_fullmakt_first([$doc, $order], ['epost', 'email']),
        'personnummer' => jeab_bokmalen_ellevio_fullmakt_pnr($doc, $order),
        'fastighetsbeteckning' => jeab_bokmalen_ellevio_fullmakt_fastighet($doc, $order),
        'pdf_url' => jeab_bokmalen_ellevio_fullmakt_pdf_url($token),
        'vaxelriktare_kw' => jeab_bokmalen_ellevio_fullmakt_kw($order),
        'batteri_kwh' => $kwh,
        'batteri_antal' => jeab_bokmalen_ellevio_fullmakt_batteri_antal($order),
        'sakring_amp' => jeab_bokmalen_ellevio_fullmakt_sakring($doc, $order),
    ];
}

/**
 * Skriver kundens anläggnings-id på ordern i samma veva som signeringen.
 * Senare läsning via jeab_ellevio_fullmakt_pull_signed är bara reserv.
 */
function jeab_bokmalen_ellevio_fullmakt_apply_signed_order(array $doc): void
{
    if (!jeab_bokmalen_ellevio_fullmakt_is_ellevio($doc)) {
        return;
    }
    $aid = jeab_bokmalen_ellevio_fullmakt_anlaggnings_id($doc);
    if ($aid === '') {
        return;
    }
    $token = jeab_bokmalen_ellevio_fullmakt_clean_token($doc['token'] ?? '');
    $order = jeab_bokmalen_ellevio_fullmakt_order_for_token($token, $doc);
    if ($order === []) {
        return;
    }
    $current = jeab_bokmalen_ellevio_fullmakt_clean_token($order['fullmakt_token'] ?? '');
    $historical = $current !== '' && $current !== $token;
    $changed = false;
    if ($historical) {
        $history = is_array($order['fullmakt_history'] ?? null) ? $order['fullmakt_history'] : [];
        foreach ($history as $i => $item) {
            if (!is_array($item) || jeab_bokmalen_ellevio_fullmakt_clean_token($item['token'] ?? '') !== $token) {
                continue;
            }
            if (empty($item['signed'])) {
                $history[$i]['signed'] = true;
                $history[$i]['signed_at'] = function_exists('jeab_now') ? jeab_now() : date('c');
                $changed = true;
            }
        }
        $order['fullmakt_history'] = $history;
    } else {
        $changed = empty($order['fullmakt_signed']);
        $order['fullmakt_signed'] = true;
    }
    $haveRaw = (string) ($order['anlaggnings_id'] ?? '');
    $have = function_exists('jeab_ordrar_anlaggnings_id_digits')
        ? jeab_ordrar_anlaggnings_id_digits($haveRaw)
        : (preg_replace('/\D+/u', '', $haveRaw) ?? '');
    if ($have !== '' && $haveRaw !== $have) {
        $order['anlaggnings_id'] = $have;
        $changed = true;
    }
    if ($have === '') {
        $order['anlaggnings_id'] = $aid;
        $changed = true;
    }
    if (!$historical && trim((string) ($order['fullmakt_error'] ?? '')) !== '') {
        $order['fullmakt_error'] = '';
        $changed = true;
    }
    if (!$changed || !function_exists('jeab_purchase_put')) {
        return;
    }
    try {
        jeab_purchase_put($order);
    } catch (Throwable) {
        return;
    }
}

/**
 * fullmakt.start — Bokmalen äger starten.
 *
 * Samma secret som fullmakt.signed: data/secrets/bokmalen-ellevio-fullmakt-webhook.json
 * POST JSON med Authorization: Bearer <key>.
 *
 * Ordrar skapar det väntande dokumentet (sign_url) men skickar inte mejlet.
 * Bokmalen kontaktar kunden och driver starten. Anläggnings-id får vara tomt
 * här. 18 siffror krävs först när kunden signerar, då kommer fullmakt.signed.
 *
 * Payload:
 * source, type=fullmakt.start, order_id (sträng), natbolag=Ellevio,
 * anlaggnings_id (siffror eller tom), customer_name, personnummer, telefon,
 * epost, address, postnummer, ort, anlaggningsadress, token, sign_url,
 * invite_epost, invite_namn, invite_subject, invite_text (samma mejltext
 * som första skick och omskick), parter.huvudsokande, parter.medsokande,
 * kwh, vaxelriktare_kw, batteri_kwh, batteri_antal, sakring_amp.
 */
function jeab_bokmalen_ellevio_fullmakt_start_payload(array $row, array $fields, array $bundle, string $token): ?array
{
    $orderId = (int) ($row['reminder_id'] ?? 0);
    if ($orderId <= 0) {
        $orderId = (int) ($row['id'] ?? 0);
    }
    if ($orderId <= 0) {
        return null;
    }
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token) ?? '';
    $rawAid = (string) ($row['anlaggnings_id'] ?? '');
    $aid = function_exists('fullmakt_anlaggnings_id_digits')
        ? fullmakt_anlaggnings_id_digits($rawAid)
        : (preg_replace('/\D+/', '', $rawAid) ?? '');
    $signUrl = $token !== '' && function_exists('jeab_ellevio_fullmakt_url')
        ? jeab_ellevio_fullmakt_url($token)
        : ($token !== '' ? 'https://flowstack.example.test/fullmakt/s/' . rawurlencode($token) : '');
    $huvud = is_array($bundle['parter']['huvudsokande'] ?? null) ? $bundle['parter']['huvudsokande'] : [];
    $med = is_array($bundle['parter']['medsokande'] ?? null) ? array_values($bundle['parter']['medsokande']) : [];
    $kwh = jeab_bokmalen_ellevio_fullmakt_text($row['kwh'] ?? '');
    $payload = [
        'source' => 'jeab',
        'type' => 'fullmakt.start',
        'order_id' => (string) $orderId,
        'natbolag' => 'Ellevio',
        'anlaggnings_id' => $aid,
        'customer_name' => jeab_bokmalen_ellevio_fullmakt_text($fields['namn'] ?? ''),
        'personnummer' => jeab_bokmalen_ellevio_fullmakt_text($fields['personnummer'] ?? ''),
        'fastighetsbeteckning' => jeab_bokmalen_ellevio_fullmakt_text($fields['fastighetsbeteckning'] ?? ''),
        'telefon' => jeab_bokmalen_ellevio_fullmakt_text($fields['telefon'] ?? ''),
        'epost' => jeab_bokmalen_ellevio_fullmakt_text($fields['epost'] ?? ''),
        'address' => jeab_bokmalen_ellevio_fullmakt_text($fields['adress'] ?? ''),
        'postnummer' => jeab_bokmalen_ellevio_fullmakt_text($fields['postnummer'] ?? ''),
        'ort' => jeab_bokmalen_ellevio_fullmakt_text($fields['ort'] ?? ''),
        'anlaggningsadress' => jeab_bokmalen_ellevio_fullmakt_text($fields['anlaggningsadress'] ?? ''),
        'token' => $token,
        'sign_url' => $signUrl,
        'invite_epost' => jeab_bokmalen_ellevio_fullmakt_text($bundle['invite_epost'] ?? ($huvud['epost'] ?? '')),
        'invite_namn' => jeab_bokmalen_ellevio_fullmakt_text($bundle['invite_namn'] ?? ($huvud['namn'] ?? '')),
        'invite_subject' => '',
        'invite_text' => '',
        'parter' => [
            'huvudsokande' => $huvud,
            'medsokande' => $med,
        ],
        'kwh' => $kwh,
        'vaxelriktare_kw' => jeab_bokmalen_ellevio_fullmakt_kw($row),
        'batteri_kwh' => $kwh,
        'batteri_antal' => jeab_bokmalen_ellevio_fullmakt_batteri_antal($row),
        'sakring_amp' => jeab_bokmalen_ellevio_fullmakt_sakring([], $row),
    ];
    if (function_exists('jeab_ellevio_fullmakt_invite_message')) {
        $invite = jeab_ellevio_fullmakt_invite_message($bundle, $signUrl);
        $payload['invite_subject'] = $invite['subject'];
        $payload['invite_text'] = $invite['text'];
    }
    return $payload;
}

function jeab_bokmalen_ellevio_fullmakt_start_notify(array $row, array $fields, array $bundle, string $token): bool
{
    try {
        $payload = jeab_bokmalen_ellevio_fullmakt_start_payload($row, $fields, $bundle, $token);
        if ($payload === null) {
            jeab_bokmalen_ellevio_fullmakt_notify_fail('order saknas', 'fullmakt.start');
            return false;
        }
        $secret = jeab_bokmalen_ellevio_fullmakt_webhook_secret();
        if ($secret === null) {
            jeab_bokmalen_ellevio_fullmakt_notify_fail('secret saknas eller är ogiltig', 'fullmakt.start');
            return false;
        }
        if (jeab_einstein_webhook_post($secret, $payload) !== true) {
            jeab_bokmalen_ellevio_fullmakt_notify_fail('POST misslyckades', 'fullmakt.start');
            return false;
        }
        return true;
    } catch (Throwable $e) {
        jeab_bokmalen_ellevio_fullmakt_notify_fail($e->getMessage() !== '' ? $e->getMessage() : 'oväntat fel', 'fullmakt.start');
        return false;
    }
}

function jeab_bokmalen_ellevio_fullmakt_notify_fail(string $reason, string $type = ''): void
{
    $reason = trim(preg_replace('#https?://\S+#i', '[url]', $reason) ?? $reason);
    $reason = trim(preg_replace('/Bearer\s+\S+/i', 'Bearer [nyckel]', $reason) ?? $reason);
    if ($reason === '') {
        $reason = 'okänt fel';
    }
    $type = $type === 'fullmakt.start' || $type === 'fullmakt.signed' ? $type : '';
    $suffix = $type !== '' ? ' (' . $type . ')' : '';
    $message = 'Bokmalen Ellevio-fullmakt-webhook: ' . $reason . $suffix . '.';
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('ordrar', $message);
        return;
    }
    if (!function_exists('jeab_data_dir')) {
        return;
    }
    try {
        $dir = jeab_data_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $line = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'surface' => 'ordrar',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($line) || $line === '') {
            return;
        }
        if (function_exists('jeab_cutover_log_append') && jeab_cutover_log_append('app_errors', $line)) {
            return;
        }
        @file_put_contents($dir . '/app-errors.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable) {
    }
}

function jeab_bokmalen_ellevio_fullmakt_notify(array $doc): void
{
    try {
        $payload = jeab_bokmalen_ellevio_fullmakt_payload($doc);
        if ($payload === null) {
            return;
        }
        $secret = jeab_bokmalen_ellevio_fullmakt_webhook_secret();
        if ($secret === null) {
            jeab_bokmalen_ellevio_fullmakt_notify_fail('secret saknas eller är ogiltig', 'fullmakt.signed');
            return;
        }
        if (jeab_einstein_webhook_post($secret, $payload) !== true) {
            jeab_bokmalen_ellevio_fullmakt_notify_fail('POST misslyckades', 'fullmakt.signed');
        }
    } catch (Throwable $e) {
        jeab_bokmalen_ellevio_fullmakt_notify_fail($e->getMessage() !== '' ? $e->getMessage() : 'oväntat fel', 'fullmakt.signed');
    }
}
