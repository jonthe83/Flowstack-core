<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * fullmakt.docs_status läser fullmakt_docs. Inget skrivs.
 * Svaret har id (sha256, 16 hex — inte token), skapad, status
 * signerad/visad/osignerad, kundnamn, order_id och Help Scout-id
 * när de finns, maskad mottagaradress och resolves.
 * resolves är true när fullmakt_load_doc hittar raden, samma uppslag
 * som /fullmakt/s/{token} efter #1101. Token, personnummer och
 * sign-länk lämnar aldrig svaret.
 */

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_fullmakt_docs_status_dispatch(array $input): array
{
    unset($input);
    $fail = static function (string $error, int $status): array {
        return [
            'ok' => false,
            'error' => $error,
            'action' => 'fullmakt.docs_status',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
            'status' => $status,
        ];
    };
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return $fail('Fel token.', 401);
    }
    if (!function_exists('jeab_data_pdo') || !function_exists('jeab_migrate_ident')) {
        return $fail('SQL saknas.', 400);
    }
    $pdo = jeab_data_pdo();
    if (!$pdo instanceof PDO) {
        return $fail('SQL saknas.', 400);
    }
    try {
        $stmt = $pdo->query(
            'SELECT '
            . jeab_migrate_ident($pdo, 'token') . ', '
            . jeab_migrate_ident($pdo, 'status') . ', '
            . jeab_migrate_ident($pdo, 'created_at') . ', '
            . jeab_migrate_ident($pdo, 'payload')
            . ' FROM ' . jeab_migrate_ident($pdo, 'fullmakt_docs')
            . ' ORDER BY ' . jeab_migrate_ident($pdo, 'created_at')
        );
    } catch (Throwable) {
        return $fail('SQL saknas.', 400);
    }
    if (!$stmt instanceof PDOStatement) {
        return $fail('SQL saknas.', 400);
    }
    $secrets = [];
    $docs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sqlRow) {
        if (!is_array($sqlRow)) {
            continue;
        }
        $token = trim((string) ($sqlRow['token'] ?? ''));
        if ($token === '') {
            continue;
        }
        $secrets[] = $token;
        $payload = json_decode((string) ($sqlRow['payload'] ?? ''), true);
        $doc = is_array($payload) ? $payload : [];
        foreach (jeab_fullmakt_docs_status_secrets($doc, $token) as $secret) {
            $secrets[] = $secret;
        }
        $docs[] = jeab_fullmakt_docs_status_row(
            $token,
            (string) ($sqlRow['status'] ?? ''),
            trim((string) ($sqlRow['created_at'] ?? '')),
            $doc
        );
    }

    return jeab_fullmakt_docs_status_seal([
        'ok' => true,
        'action' => 'fullmakt.docs_status',
        'read_only' => true,
        'sent' => 0,
        'would_send' => false,
        'count' => count($docs),
        'docs' => $docs,
        'status' => 200,
    ], $secrets);
}

/**
 * @param array<string, mixed> $doc
 * @return array<string, mixed>
 */
function jeab_fullmakt_docs_status_row(string $token, string $columnStatus, string $created, array $doc): array
{
    if ($created === '') {
        $created = trim((string) ($doc['created_at'] ?? ''));
    }
    $row = [
        'id' => substr(hash('sha256', $token), 0, 16),
        'created' => $created,
        'status' => jeab_fullmakt_docs_status_label($doc, $columnStatus),
        'namn' => jeab_fullmakt_docs_status_name($doc),
    ];
    $orderId = jeab_fullmakt_docs_status_order_id($doc);
    if ($orderId !== '') {
        $row['order_id'] = $orderId;
    }
    $conversation = jeab_fullmakt_docs_status_hs($doc, $token, [
        'hs_conversation_id',
        'helpscout_conversation_id',
        'conversation_id',
    ]);
    if ($conversation !== '') {
        $row['hs_conversation_id'] = $conversation;
    }
    $thread = jeab_fullmakt_docs_status_hs($doc, $token, [
        'hs_thread_id',
        'helpscout_thread_id',
        'thread_id',
    ]);
    if ($thread !== '') {
        $row['hs_thread_id'] = $thread;
    }
    $row['email'] = jeab_fullmakt_docs_status_email($doc);
    $row['resolves'] = jeab_fullmakt_docs_status_resolves($token);

    return $row;
}

/**
 * @param array<string, mixed> $doc
 */
function jeab_fullmakt_docs_status_label(array $doc, string $columnStatus): string
{
    $status = strtolower(trim($columnStatus));
    if ($status === '') {
        $status = strtolower(trim((string) ($doc['status'] ?? '')));
    }
    if ($status === 'signed') {
        return 'signerad';
    }
    if (trim((string) ($doc['viewed_at'] ?? '')) !== '') {
        return 'visad';
    }

    return 'osignerad';
}

/**
 * @param array<string, mixed> $doc
 */
function jeab_fullmakt_docs_status_name(array $doc): string
{
    $name = trim((string) ($doc['namn'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($doc['invite_namn'] ?? ''));
    }
    if ($name === '') {
        $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
        $huvud = is_array($parter['huvudsokande'] ?? null) ? $parter['huvudsokande'] : [];
        $name = trim((string) ($huvud['namn'] ?? ''));
    }

    return jeab_fullmakt_docs_status_plain($name);
}

/**
 * @param array<string, mixed> $doc
 */
function jeab_fullmakt_docs_status_order_id(array $doc): string
{
    foreach (['order_id', 'reminder_id'] as $key) {
        $raw = trim((string) ($doc[$key] ?? ''));
        if ($raw !== '' && preg_match('/\A\d{1,20}\z/', $raw) === 1 && (int) $raw > 0) {
            return (string) (int) $raw;
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $doc
 * @param list<string> $keys
 */
function jeab_fullmakt_docs_status_hs(array $doc, string $token, array $keys): string
{
    $bags = [$doc];
    foreach (['hs', 'helpscout'] as $nest) {
        if (is_array($doc[$nest] ?? null)) {
            $bags[] = $doc[$nest];
        }
    }
    foreach ($bags as $bag) {
        foreach ($keys as $key) {
            $id = jeab_fullmakt_docs_status_hs_id($bag[$key] ?? null, $token);
            if ($id !== '') {
                return $id;
            }
        }
    }

    return '';
}

function jeab_fullmakt_docs_status_hs_id(mixed $raw, string $token): string
{
    if (is_int($raw) || is_float($raw)) {
        $raw = (string) (int) $raw;
    }
    if (!is_string($raw)) {
        return '';
    }
    $text = trim($raw);
    if ($text === '' || ($token !== '' && str_contains($text, $token))) {
        return '';
    }
    if (preg_match('/\A\d{1,20}\z/', $text) === 1) {
        return $text;
    }
    if (preg_match('#helpscout\.net/(?:conversation|thread)/(\d{1,20})\b#i', $text, $match) === 1) {
        return $match[1];
    }

    return '';
}

/**
 * @param array<string, mixed> $doc
 */
function jeab_fullmakt_docs_status_email(array $doc): string
{
    $email = trim((string) ($doc['invite_epost'] ?? ''));
    if ($email === '') {
        $email = trim((string) ($doc['epost'] ?? ''));
    }
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }
    if (function_exists('jeab_lead_mask_email')) {
        return jeab_lead_mask_email($email);
    }
    $email = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
    $local = (string) strstr($email, '@', true);
    $domain = substr($email, strlen($local));
    $first = $local === '' ? '' : (function_exists('mb_substr') ? mb_substr($local, 0, 1, 'UTF-8') : substr($local, 0, 1));

    return $first . '***' . $domain;
}

function jeab_fullmakt_docs_status_resolves(string $token): bool
{
    if ($token === '') {
        return false;
    }
    if (!function_exists('fullmakt_load_doc') && function_exists('jeab_ellevio_fullmakt_load')) {
        jeab_ellevio_fullmakt_load();
    }
    if (!function_exists('fullmakt_load_doc')) {
        return false;
    }
    $found = fullmakt_load_doc($token);

    return is_array($found);
}

function jeab_fullmakt_docs_status_plain(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($text);
        if (is_string($scrubbed)) {
            $text = $scrubbed;
        }
    }
    $text = str_replace('[pnr]', '', $text);
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (preg_match('/\A\[pnr\]\z/', $text) === 1) {
        return '';
    }

    return $text;
}

/**
 * @param array<string, mixed> $doc
 * @return list<string>
 */
function jeab_fullmakt_docs_status_secrets(array $doc, string $token): array
{
    $out = [];
    $walk = static function (mixed $value) use (&$walk, &$out, $token): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $walk($item);
            }
            return;
        }
        if (!is_string($value) && !is_int($value)) {
            return;
        }
        $text = trim((string) $value);
        if ($text === '' || $text === $token) {
            return;
        }
        if (preg_match('/(?<!\d)(?:\d{6}|\d{8})[-+]?\d{4}(?!\d)/', $text) === 1) {
            $out[] = $text;
        }
        if (str_contains($text, '/fullmakt/s/') || stripos($text, 'sign_url') !== false) {
            $out[] = $text;
        }
    };
    $walk($doc);

    return $out;
}

/**
 * @param array<string, mixed> $body
 * @param list<string> $secrets
 * @return array<string, mixed>
 */
function jeab_fullmakt_docs_status_seal(array $body, array $secrets): array
{
    $drop = ['token', 'personnummer', 'pnr', 'sign_url', 'fullmakt_url', 'payload', 'pdf', 'ip'];
    $walk = static function (mixed $value) use (&$walk, $secrets, $drop): mixed {
        if (!is_array($value)) {
            if (!is_string($value)) {
                return $value;
            }
            foreach ($secrets as $secret) {
                if ($secret !== '' && str_contains($value, $secret)) {
                    $value = str_replace($secret, '', $value);
                }
            }
            $value = preg_replace('#https?://\S+#i', '', $value) ?? $value;
            $value = preg_replace('#/fullmakt/s/\S*#', '', $value) ?? $value;
            if (function_exists('jeab_ordrar_scrub_pnr')) {
                $scrubbed = jeab_ordrar_scrub_pnr($value);
                if (is_string($scrubbed)) {
                    $value = str_replace('[pnr]', '', $scrubbed);
                }
            }

            return trim($value);
        }
        $keep = ['id', 'order_id', 'hs_conversation_id', 'hs_thread_id', 'action', 'error', 'status'];
        $out = [];
        foreach ($value as $key => $item) {
            $keyText = (string) $key;
            if (in_array($keyText, $drop, true)) {
                continue;
            }
            if (in_array($keyText, $keep, true)) {
                $out[$key] = $item;
                continue;
            }
            $out[$key] = $walk($item);
        }

        return $out;
    };
    $clean = $walk($body);

    return is_array($clean) ? $clean : $body;
}
