<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Read-only toppar för automatiska mejl och sms.
 * Svaret har antal och starttid. Ingen mottagaradress och ingen telefon.
 */

function jeab_send_guard_stamp(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $parsed = strtotime($raw);
    return $parsed === false || $parsed <= 0 ? null : $parsed;
}

function jeab_send_guard_use_app_timezone(): void
{
    if (!function_exists('jeab_config')) {
        return;
    }
    $tz = trim((string) (jeab_config()['timezone'] ?? ''));
    if ($tz === '') {
        return;
    }
    try {
        new DateTimeZone($tz);
    } catch (Exception) {
        return;
    }
    date_default_timezone_set($tz);
}

function jeab_send_guard_format_stamp(int $stamp): string
{
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'Europe/Stockholm');
    return (new DateTimeImmutable('@' . $stamp))->setTimezone($tz)->format('Y-m-d H:i:s');
}

/**
 * Tätaste fönstret som börjar på en händelse. Vid lika antal vinner den tidigaste starten.
 *
 * @param list<int|string> $stamps
 * @return array{count:int,started_at:?string}
 */
function jeab_send_guard_peak(array $stamps, int $windowSeconds): array
{
    $times = [];
    foreach ($stamps as $stamp) {
        $parsed = jeab_send_guard_stamp($stamp);
        if ($parsed !== null) {
            $times[] = $parsed;
        }
    }
    sort($times, SORT_NUMERIC);
    $count = count($times);
    if ($count === 0 || $windowSeconds < 1) {
        return ['count' => 0, 'started_at' => null];
    }
    $best = 0;
    $bestStart = $times[0];
    $end = 0;
    for ($start = 0; $start < $count; $start++) {
        if ($end < $start) {
            $end = $start;
        }
        $limit = $times[$start] + $windowSeconds;
        while ($end < $count && $times[$end] < $limit) {
            $end++;
        }
        $n = $end - $start;
        if ($n > $best) {
            $best = $n;
            $bestStart = $times[$start];
        }
    }
    return ['count' => $best, 'started_at' => jeab_send_guard_format_stamp($bestStart)];
}

/**
 * @param list<int|string> $email
 * @param list<int|string> $sms
 * @return array{email:array<string,array{count:int,started_at:?string}>,sms:array<string,array{count:int,started_at:?string}>}
 */
function jeab_send_guard_peaks(array $email, array $sms): array
{
    $windows = [
        'minute' => 60,
        'five_minutes' => 300,
        'hour' => 3600,
        'day' => 86400,
    ];
    $out = ['email' => [], 'sms' => []];
    foreach ($windows as $name => $seconds) {
        $out['email'][$name] = jeab_send_guard_peak($email, $seconds);
        $out['sms'][$name] = jeab_send_guard_peak($sms, $seconds);
    }
    return $out;
}

/**
 * @param array<string, true> $seen
 * @param list<int> $into
 */
function jeab_send_guard_push_stamp(array &$seen, array &$into, string $key, mixed $when): void
{
    $stamp = jeab_send_guard_stamp($when);
    if ($stamp === null || isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $into[] = $stamp;
}

/**
 * Tidpunkter för automatiska mejl och sms. Adresser och nummer lämnas inte med.
 *
 * @return array{email:list<int>,sms:list<int>,sources:list<string>,partial:bool}
 */
function jeab_send_guard_collect_stamps(): array
{
    $email = [];
    $sms = [];
    $seenEmail = [];
    $seenSms = [];
    $sources = [];
    $partial = false;

    if (function_exists('jeab_inbox_sent_log')) {
        $sources[] = 'sent_log';
        try {
            $log = jeab_inbox_sent_log([]);
            foreach ((array) ($log['rows'] ?? []) as $row) {
                if (!is_array($row) || (string) ($row['via'] ?? '') !== 'auto') {
                    continue;
                }
                $kind = (string) ($row['kind'] ?? '');
                $outcome = (string) ($row['outcome'] ?? '');
                if (str_starts_with($kind, 'guard') || in_array($outcome, ['queued', 'blocked', 'duplicate'], true)) {
                    continue;
                }
                $when = (string) ($row['date'] ?? '');
                $key = 'log:' . $when . ':' . (string) ($row['thread_id'] ?? '') . ':' . (string) ($row['kind'] ?? '');
                jeab_send_guard_push_stamp($seenEmail, $email, $key, $when);
            }
        } catch (Throwable) {
            $partial = true;
        }
    }

    if (function_exists('jeab_sms_store_load')) {
        $sources[] = 'sms_sent';
        try {
            foreach (jeab_sms_store_load() as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $booking = (string) $id;
                if (!empty($row['sms'])) {
                    jeab_send_guard_push_stamp($seenSms, $sms, 'sms:' . $booking . ':' . (string) ($row['sms_at'] ?? ''), $row['sms_at'] ?? '');
                }
                if (!empty($row['email'])) {
                    jeab_send_guard_push_stamp($seenEmail, $email, 'mail:' . $booking . ':' . (string) ($row['email_at'] ?? ''), $row['email_at'] ?? '');
                }
            }
        } catch (Throwable) {
            $partial = true;
        }
    }

    if (function_exists('jeab_calendar_log_read')) {
        $sources[] = 'calendar_log';
        try {
            $data = jeab_calendar_log_read();
            foreach ((array) ($data['entries'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $type = (string) ($entry['type'] ?? '');
                if ($type !== 'sms' && $type !== 'mail') {
                    continue;
                }
                $booking = (string) ($entry['booking_id'] ?? '');
                $when = (string) ($entry['at'] ?? '');
                if ($type === 'sms') {
                    jeab_send_guard_push_stamp($seenSms, $sms, 'sms:' . $booking . ':' . $when, $when);
                } else {
                    jeab_send_guard_push_stamp($seenEmail, $email, 'mail:' . $booking . ':' . $when, $when);
                }
            }
        } catch (Throwable) {
            $partial = true;
        }
    }

    return ['email' => $email, 'sms' => $sms, 'sources' => $sources, 'partial' => $partial];
}

/**
 * @return array<string, mixed>
 */
function jeab_send_guard_stats(): array
{
    jeab_send_guard_use_app_timezone();
    $hour = null;
    $day = null;
    if (function_exists('jeab_auto_settings')) {
        try {
            $settings = jeab_auto_settings();
            $hour = (int) ($settings['per_hour'] ?? 0);
            $day = (int) ($settings['per_day'] ?? 0);
        } catch (Throwable) {
            $hour = null;
            $day = null;
        }
    }
    $collected = jeab_send_guard_collect_stamps();
    $peaks = jeab_send_guard_peaks($collected['email'], $collected['sms']);
    return [
        'ok' => true,
        'read_only' => true,
        'auto_send_per_hour' => $hour,
        'auto_send_per_day' => $day,
        'email' => $peaks['email'],
        'sms' => $peaks['sms'],
        'email_events' => count($collected['email']),
        'sms_events' => count($collected['sms']),
        'sources' => $collected['sources'],
        'partial' => $collected['partial'],
    ];
}

/**
 * Staff-hook send_guard.stats. Ignorerar indata. Skriver inget.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_send_guard_stats_hook(array $in): array
{
    unset($in);
    $stats = jeab_send_guard_stats();
    $encoded = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || str_contains($encoded, '@') || preg_match('/(?:\+|00)?\d{8,}/', $encoded) === 1) {
        return ['ok' => false, 'error' => 'Svaret stoppades.', 'read_only' => true];
    }
    return $stats;
}

require_once __DIR__ . '/send_guard_brake.php';
