<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
function jeab_fire_webhook(JeabStore $store, string $event, array $data): void
{
    if (!empty($data['_strict_blocked'])) {
        return;
    }
    if (!empty($GLOBALS['_JEAB_QUIET_BOOKING'])) {
        return; // självtest av ombokning: inga webhooks för testbokningen
    }
    $url = jeab_outgoing_url($store);
    if ($url === '') {
        return;
    }

    $payload = [
        'event' => $event,
        'occurred_at' => jeab_iso(jeab_now()),
        'timezone' => date_default_timezone_get(),
        'data' => $data,
    ];

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        return;
    }

    if (getenv('JEAB_WEBHOOK_CAPTURE')) {
        $GLOBALS['_JEAB_WEBHOOKS'][] = $payload;
        return;
    }

    jeab_post_json($url, $body);
}

function jeab_ip_is_blocked_ssrf(string $ip): bool
{
    $ip = trim($ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return true;
    }
    // Private + reserved (incl. 127/8, 10/8, 172.16/12, 192.168/16, ::1, fc00::/7, link-local).
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    // Explicit metadata / link-local (belt-and-suspenders).
    if (str_starts_with($ip, '169.254.') || $ip === '169.254.169.254') {
        return true;
    }
    return false;
}

function jeab_host_is_local_test(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '0.0.0.0';
}

function jeab_http_no_follow(array $http): array
{
    $http['follow_location'] = 0;
    $http['max_redirects'] = 0;
    return $http;
}

function jeab_url_ssrf_safe(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    if ($host === '') {
        return false;
    }
    if ($scheme !== 'https') {
        return false;
    }
    if (jeab_host_is_local_test($host)) {
        return false;
    }
    // Respect Einstein pastebin / requestbin host blocklist when available.
    if (function_exists('jeab_einstein_webhook_host_blocked') && jeab_einstein_webhook_host_blocked($url)) {
        return false;
    }
    $blockedHostnames = [
        'metadata.google.internal',
        'metadata',
        'instance-data',
    ];
    foreach ($blockedHostnames as $bad) {
        if ($host === $bad || str_ends_with($host, '.' . $bad)) {
            return false;
        }
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !jeab_ip_is_blocked_ssrf($host);
    }
    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        foreach ($v4 as $ip) {
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
    }
    if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $row) {
                $ip = (string) ($row['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[] = $ip;
                }
            }
        }
    }
    if ($ips === []) {
        // Fail closed: unresolved host must not be fetched.
        return false;
    }
    foreach ($ips as $ip) {
        if (jeab_ip_is_blocked_ssrf($ip)) {
            return false;
        }
    }
    return true;
}

function jeab_post_json(string $url, string $body): void
{
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('webhook', $url)) {
        return;
    }
    if (!jeab_url_ssrf_safe($url)) {
        return;
    }

    $context = stream_context_create([
        'http' => jeab_http_no_follow([
            'method' => 'POST',
            'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => 3,
            'ignore_errors' => true,
        ]),
    ]);

    try {
        @file_get_contents($url, false, $context);
    } catch (Throwable) {
        // Utgående hook får inte blockera appen.
    }
}

/**
 * Kundfälten i en utgående payload. Flaggan av lämnar radens värden, även null.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_webhook_shown_row(array $row): array
{
    if (!function_exists('jeab_contacts_sole_recipient_apply')) {
        return $row;
    }

    return jeab_contacts_sole_recipient_apply($row);
}

function jeab_reminder_payload(array $row): array
{
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'webhook');
        if ($block !== '') {
            return ['_strict_blocked' => 1, 'id' => (int) ($row['id'] ?? 0)];
        }
    }
    $note = jeab_reminder_notes_text($row);
    if ($note !== '' && function_exists('jeab_ordrar_payout_mask_note')) {
        $note = jeab_ordrar_payout_mask_note($note);
    }
    $shown = jeab_webhook_shown_row($row);
    return [
        'id' => (int) $row['id'],
        'title' => $row['title'],
        'customer' => $shown['customer_name'] ?? null,
        'email' => $shown['email'] ?? null,
        'phone' => $shown['phone'] ?? null,
        'address' => $shown['address'] ?? null,
        'assignees' => jeab_assignees_from_row($row),
        'assignee' => jeab_assignees_from_row($row) ? jeab_assignees_join(jeab_assignees_from_row($row)) : null,
        'flagged' => !empty($row['flagged']),
        'tags' => jeab_parse_tags($row['tags'] ?? []),
        'note' => $note !== '' ? $note : null,
        'due_at' => $row['due_at'] ? jeab_iso($row['due_at']) : null,
        'completed' => !empty($row['completed_at']),
        'completed_at' => $row['completed_at'] ? jeab_iso($row['completed_at']) : null,
        'booking_id' => $row['booking_id'] !== null ? (int) $row['booking_id'] : null,
    ];
}

function jeab_booking_payload(array $row): array
{
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'webhook');
        if ($block !== '') {
            return ['_strict_blocked' => 1, 'id' => (int) ($row['id'] ?? 0)];
        }
    }
    $shown = jeab_webhook_shown_row($row);
    return [
        'id' => (int) $row['id'],
        'customer' => $shown['customer_name'],
        'email' => $shown['email'] ?? null,
        'phone' => $shown['phone'] ?? null,
        'address' => $shown['address'] ?? null,
        'notes' => $row['notes'] ?? null,
        'assignees' => jeab_assignees_from_row($row),
        'assignee' => jeab_assignees_join(jeab_assignees_from_row($row)),
        'job_type' => function_exists('jeab_job_types_join')
            ? jeab_job_types_join(jeab_job_types_from_row($row))
            : ($row['job_type'] ?: null),
        'job_types' => function_exists('jeab_job_types_from_row')
            ? jeab_job_types_from_row($row)
            : [],
        'starts_at' => jeab_iso($row['starts_at']),
        'ends_at' => jeab_iso($row['ends_at']),
        'status' => $row['status'],
    ];
}

function jeab_parse_incoming(array $input): array
{
    $type = strtolower(trim((string) ($input['type'] ?? $input['event'] ?? '')));
    $nested = is_array($input['data'] ?? null) ? $input['data'] : [];
    $smsTypes = ['sms', 'sms.sent', 'suresms', 'calendar.sms', 'skickade sms'];
    $mailTypes = ['mail', 'mail.sent', 'email', 'email.sent', 'helpscout', 'calendar.mail', 'skickade mail'];
    if (in_array($type, $smsTypes, true) || in_array($type, $mailTypes, true)) {
        $bookingId = (int) ($input['booking_id'] ?? $input['id'] ?? $nested['booking_id'] ?? $nested['id'] ?? 0);
        if ($bookingId <= 0) {
            throw new InvalidArgumentException('Skickat sms/mail behöver booking_id.');
        }
        $occurred = $input['occurred_at'] ?? $input['sent_at'] ?? $input['at'] ?? $nested['occurred_at'] ?? $nested['sent_at'] ?? null;
        return [
            'type' => in_array($type, $mailTypes, true) ? 'mail' : 'sms',
            'booking_id' => $bookingId,
            'occurred_at' => is_string($occurred) || $occurred === null ? $occurred : null,
        ];
    }
    if (str_starts_with($type, 'reminder') || $type === 'create_reminder') {
        $type = 'reminder';
    } elseif (str_starts_with($type, 'booking') || $type === 'create_booking') {
        $type = 'booking';
    } elseif ($type === '' && isset($input['title']) && !isset($input['customer']) && !isset($input['assignee']) && !isset($input['assignees'])) {
        $type = 'reminder';
    } elseif ($type === '' && (isset($input['customer']) || isset($input['assignee']) || isset($input['assignees']))) {
        $type = 'booking';
    }

    $testSubmission = function_exists('jeab_test_submission_marked') && jeab_test_submission_marked($input);
    if ($type === 'reminder') {
        $title = trim((string) ($input['title'] ?? $input['name'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Påminnelse behöver title.');
        }
        $tags = jeab_parse_tags($input['tags'] ?? []);
        if ($testSubmission && !in_array('test', $tags, true)) {
            $tags[] = 'test';
        }
        return array_merge([
            'type' => 'reminder',
            'title' => $title,
            'note' => jeab_text_or_null($input['notes'] ?? $input['note'] ?? null),
            'due_at' => jeab_parse_datetime(isset($input['due_at']) ? (string) $input['due_at'] : null),
            'customer' => jeab_text_or_null($input['customer'] ?? $input['customer_name'] ?? null),
            'email' => jeab_text_or_null($input['email'] ?? null),
            'phone' => jeab_text_or_null($input['phone'] ?? null),
            'address' => jeab_text_or_null($input['address'] ?? null),
            'assignees' => jeab_assignees_from_input($input),
            'flagged' => !empty($input['flagged']),
            'tags' => $tags,
            'is_test' => $testSubmission,
        ], jeab_reminder_hs_payload_fields($input));
    }

    if ($type === 'booking') {
        $customer = trim((string) ($input['customer'] ?? $input['customer_name'] ?? $input['name'] ?? ''));
        $who = $input;
        if (!isset($who['assignees']) && !isset($who['assignee']) && isset($who['who'])) {
            $who['assignee'] = $who['who'];
        }
        $names = jeab_assignees_from_input($who);
        $starts = jeab_parse_datetime(isset($input['starts_at']) ? (string) $input['starts_at'] : (isset($input['when']) ? (string) $input['when'] : null));
        if ($customer === '' || !$names || !$starts) {
            throw new InvalidArgumentException('Bokning behöver customer, vem åker (Roberto, Sam, Elin eller Niman) och starts_at.');
        }
        $ends = jeab_parse_datetime(isset($input['ends_at']) ? (string) $input['ends_at'] : null);
        if (!$ends) {
            $ends = (new DateTimeImmutable($starts, new DateTimeZone(date_default_timezone_get())))
                ->modify('+60 minutes')
                ->format('Y-m-d H:i:s');
        }
        $jobs = function_exists('jeab_parse_job_types')
            ? jeab_parse_job_types($input['job_types'] ?? $input['job_type'] ?? null)
            : [];
        if ($jobs === []) {
            $job = strtolower(trim((string) ($input['job_type'] ?? '')));
            if ($job !== '' && isset(JEAB_JOB_TYPES[$job])) {
                $jobs = [$job];
            }
        }
        return [
            'type' => 'booking',
            'customer' => $customer,
            'assignees' => $names,
            'assignee' => jeab_assignees_join($names),
            'starts_at' => $starts,
            'ends_at' => $ends,
            'job_type' => function_exists('jeab_job_types_join')
                ? jeab_job_types_join($jobs)
                : ($jobs[0] ?? null),
            'email' => jeab_text_or_null($input['email'] ?? null),
            'phone' => jeab_text_or_null($input['phone'] ?? null),
            'address' => jeab_text_or_null($input['address'] ?? null),
            'notes' => jeab_text_or_null($input['notes'] ?? $input['note'] ?? null),
            'company' => jeab_normalize_company($input['company'] ?? $input['co'] ?? null),
            'is_test' => $testSubmission,
            'test' => $testSubmission,
        ];
    }

    throw new InvalidArgumentException('Okänd type. Använd reminder, booking, sms eller mail.');
}
