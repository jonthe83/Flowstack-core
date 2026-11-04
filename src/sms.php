<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/sms_store.php';
function jeab_suresms_path(): string
{
    return jeab_data_file('suresms.json');
}

function jeab_helpscout_path(): string
{
    return jeab_data_file('helpscout.json');
}

function jeab_sms_sent_path(): string
{
    return jeab_data_file('sms-sent.json');
}

function jeab_suresms_config(): array
{
    $path = jeab_suresms_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function jeab_helpscout_config(): array
{
    $path = jeab_helpscout_path();
    if (!is_file($path)) {
        return [];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function jeab_sms_sent_store(): array
{
    return jeab_sms_store_load();
}

function jeab_sms_save_sent(int $bookingId, array $row): bool
{
    return jeab_sms_store_update($bookingId, static function (?array $prev) use ($row): array {
        // SMS får skriva om raden när tiden flyttas. Mejlflaggan ska överleva det,
        // annars skickar nästa tick bekräftelsen en gång till.
        if (is_array($prev) && !empty($prev['email']) && empty($row['email'])) {
            $row['email'] = true;
            if (trim((string) ($row['email_at'] ?? '')) === '') {
                $row['email_at'] = (string) ($prev['email_at'] ?? '');
            }
            if (trim((string) ($row['email_version'] ?? '')) === '') {
                $row['email_version'] = (string) ($prev['email_version'] ?? '');
            }
        }
        // Flyttmejlflaggor ska överleva SMS-omskrivning. Saknas nyckeln
        // i den nya raden återställs den. Uttrycklig false/pending vinner.
        if (is_array($prev) && !array_key_exists('move_email', $row) && !empty($prev['move_email'])) {
            $row['move_email'] = true;
            if (trim((string) ($row['move_email_at'] ?? '')) === '') {
                $row['move_email_at'] = (string) ($prev['move_email_at'] ?? '');
            }
            if (trim((string) ($row['move_starts_at'] ?? '')) === '') {
                $row['move_starts_at'] = (string) ($prev['move_starts_at'] ?? '');
            }
        }
        if (is_array($prev) && !array_key_exists('move_pending', $row) && !empty($prev['move_pending'])) {
            $row['move_pending'] = true;
            if (trim((string) ($row['move_starts_at'] ?? '')) === '') {
                $row['move_starts_at'] = (string) ($prev['move_starts_at'] ?? '');
            }
        }
        foreach (['sms_claim_at', 'sms_claim_starts', 'email_claim_at', 'email_claim_starts'] as $claimKey) {
            if (is_array($prev) && !array_key_exists($claimKey, $row) && array_key_exists($claimKey, $prev)) {
                $row[$claimKey] = $prev[$claimKey];
            }
        }
        if (!empty($row['sms'])) {
            unset($row['sms_claim_at'], $row['sms_claim_starts']);
        }
        if (!empty($row['email'])) {
            unset($row['email_claim_at'], $row['email_claim_starts']);
        }
        return $row;
    });
}

function jeab_sms_record_sent(int $bookingId, array $done, array $booking, string $channel): bool
{
    $saved = false;
    for ($try = 0; $try < 3; $try++) {
        if (jeab_sms_save_sent($bookingId, $done)) {
            $saved = true;
            break;
        }
    }
    if (!$saved && ($channel === 'sms' || $channel === 'mail')) {
        // Leverantören har redan svarat ok. Anspråket i SQL dör efter 600 s,
        // så filflaggan måste läsas även när PDO finns. Släpp inte anspråket.
        $flagChannel = $channel === 'sms' ? 'sms' : 'email';
        $starts = (string) ($done['starts_at'] ?? '');
        jeab_sms_channel_file_mark_sent($bookingId, $flagChannel, $starts);
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('sms', 'sms_sent kunde inte sparas efter utskick');
        }
    }
    if ($channel === 'sms') {
        $type = 'sms';
        $at = trim((string) ($done['sms_at'] ?? ''));
    } elseif ($channel === 'move') {
        $type = 'mail';
        $at = trim((string) ($done['move_email_at'] ?? ''));
    } else {
        $type = 'mail';
        $at = trim((string) ($done['email_at'] ?? ''));
    }
    if ($saved && $at !== '' && function_exists('jeab_calendar_log_append')) {
        jeab_calendar_log_append($type, $booking, $at);
    }

    return $saved;
}

function jeab_sms_sent_row(int $bookingId, string $startsAt): array
{
    $row = jeab_sms_sent_store()[(string) $bookingId] ?? [];
    if (!is_array($row) || (string) ($row['starts_at'] ?? '') !== $startsAt) {
        return ['starts_at' => $startsAt, 'sms' => false, 'email' => false];
    }
    return $row;
}

function jeab_sms_starts_at_passed(array $row, string $now): bool
{
    $starts = trim((string) ($row['starts_at'] ?? ''));
    return $starts === '' || $starts <= $now;
}

function jeab_booking_moment(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
    if ($parsed instanceof DateTimeImmutable) {
        return $parsed;
    }
    try {
        return new DateTimeImmutable($value, $tz);
    } catch (Exception) {
        return null;
    }
}

/**
 * Bekräftelsemejl hoppas över bara när starten ligger klart före tickern.
 * starts_at <= now räckte för att en cron som går 16:00 skulle tappa en
 * bokning som börjar 16:00 (samma minut). Femton minuter täcker den
 * minut-träffen och en lite sen cron, utan att mejla ett besök som
 * började för länge sedan.
 *
 * SMS använder fortfarande jeab_sms_starts_at_passed. SMS är påminnelsen
 * «kommer imorgon» inom 18 timmar, inte en bekräftelse vid bokningen.
 */
function jeab_booking_mail_start_clearly_past(array $row, string $nowSql): bool
{
    $starts = jeab_booking_moment((string) ($row['starts_at'] ?? ''));
    $now = jeab_booking_moment($nowSql);
    if ($starts === null || $now === null) {
        return true;
    }
    return $starts < $now->modify('-15 minutes');
}

/**
 * @return array{sent: bool, skipped: bool, error: ?string, reason: string}
 */
function jeab_sms_provider_delivered(array $result): bool
{
    return !empty($result['ok']) && empty($result['queued']);
}

function jeab_booking_channel_result(bool $sent, bool $skipped, ?string $error, string $reason, array $extra = []): array
{
    $out = [
        'sent' => $sent,
        'skipped' => $skipped,
        'error' => $error,
        'reason' => $reason,
    ];
    foreach (['to', 'from', 'path', 'provider'] as $key) {
        if (array_key_exists($key, $extra)) {
            $out[$key] = $extra[$key];
        }
    }
    return $out;
}

/** @param array<string, mixed> $result @return array<string, mixed> */
function jeab_booking_move_mail_provider_extra(array $result, string $deliver): array
{
    if ($deliver === '') {
        return [];
    }
    $provider = is_array($result['provider'] ?? null) ? $result['provider'] : [];
    if ($provider === []) {
        $provider = [
            'name' => trim((string) ($result['path'] ?? '')),
            'code' => isset($result['provider_code']) ? (int) $result['provider_code'] : 0,
            'from' => trim((string) ($result['from'] ?? '')),
            'to' => $deliver,
            'id' => trim((string) ($result['id'] ?? '')),
            'ok' => !empty($result['ok']),
        ];
    }
    return [
        'to' => $deliver,
        'from' => trim((string) ($provider['from'] ?? $result['from'] ?? '')),
        'path' => trim((string) ($result['path'] ?? $provider['name'] ?? '')),
        'provider' => $provider,
    ];
}

/** Sant när nyckeln saknas. Avbockad ruta sparar false och släpper mejlen igen. */
function jeab_sol_calendar_mail_off(): bool
{
    if (!function_exists('jeab_auto_settings')) {
        return true;
    }
    $set = jeab_auto_settings();
    return !array_key_exists('sol_calendar_mail_off', $set) || !empty($set['sol_calendar_mail_off']);
}

function jeab_booking_row_is_sol(array $row): bool
{
    $jobs = function_exists('jeab_job_types_from_row') ? jeab_job_types_from_row($row) : [];
    if (in_array('sol', $jobs, true)) {
        return true;
    }
    $raw = strtolower(trim((string) ($row['job_type'] ?? '')));
    if ($raw !== '' && preg_match('/(?:^|[,;])sol(?:$|[,;])/', $raw) === 1) {
        return true;
    }
    foreach (['product', 'produkt', 'offer'] as $key) {
        $value = strtolower(trim((string) ($row[$key] ?? '')));
        if ($value === '') {
            continue;
        }
        foreach (preg_split('/\s*,\s*/', $value) ?: [] as $part) {
            if (in_array(trim($part), ['sol', 'solceller', 'solpaneler', 'solcell'], true)) {
                return true;
            }
        }
    }
    return false;
}

function jeab_sol_calendar_customer_mail_blocked(array $row): bool
{
    return jeab_sol_calendar_mail_off() && jeab_booking_row_is_sol($row);
}

/**
 * Kundbekräftelse via system_mail (Help Scout, sedan Graph från kontakt@). Inte den inbyggda mail-funktionen.
 *
 * @return array{sent: bool, skipped: bool, error: ?string, reason: string}
 */
/**
 * Bokningens mottagare. Flaggan av lämnar raden. Den målade kopian sparas inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_sms_shown_booking(array $row): array
{
    if (!function_exists('jeab_contacts_sole_recipient_apply')) {
        return $row;
    }

    return jeab_contacts_sole_recipient_apply($row);
}

function jeab_booking_send_customer_mail(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || ($row['status'] ?? 'booked') === 'cancelled' || jeab_sol_calendar_customer_mail_blocked($row)) {
        return jeab_booking_channel_result(false, true, null, 'skip');
    }
    $starts = (string) ($row['starts_at'] ?? '');
    $done = jeab_sms_sent_row($id, $starts);
    $shown = jeab_sms_shown_booking($row);
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'email');
        if ($block !== '') {
            return jeab_booking_channel_result(false, true, $block, 'strict');
        }
    }
    $email = trim((string) ($shown['email'] ?? ''));
    $version = jeab_booking_mail_last_changed($row);
    if (jeab_sms_email_already_sent($id)) {
        return jeab_booking_channel_result(false, false, null, 'already');
    }
    if ($email === '' || !str_contains($email, '@')) {
        return jeab_booking_channel_result(false, $email === '', null, $email === '' ? 'no-email' : 'bad-email');
    }
    $job = jeab_booking_job_type($shown);
    $subject = match ($job) {
        'laddbox' => jeab_email_laddbox_subject($shown),
        'batteri' => jeab_email_batteri_subject($shown),
        'sol' => jeab_email_sol_subject($shown),
        default => null,
    };
    $text = match ($job) {
        'laddbox' => jeab_email_laddbox_text($shown),
        'batteri' => jeab_email_batteri_text($shown),
        'sol' => jeab_email_sol_text($shown),
        default => jeab_email_text($shown),
    };
    $mailCompany = jeab_calendar_mail_company($row);
    $mailOpts = [
        'to' => $email,
        'subject' => $subject ?? '',
        'text' => $text,
        'job_type' => $job,
        'tags' => ['kalender'],
        'company' => $mailCompany,
        'guard_template' => 'booking_notice',
        'guard_mode' => 'auto',
        'guard_via' => 'auto',
        'guard_actor' => 'system',
        'booking_id' => $id,
        'starts_at' => $starts,
        'booking_notice_kind' => 'mail',
    ];
    $fromEmail = jeab_booking_confirm_mail_from($row);
    if ($fromEmail !== null) {
        $mailOpts['from_email'] = $fromEmail;
        $mailOpts['from_name'] = function_exists('jeab_system_mail_from_name')
            ? jeab_system_mail_from_name($mailCompany)
            : 'Demo Energi Malmö';
    }
    $mailClaim = jeab_sms_channel_claim($id, $starts, 'email');
    if ($mailClaim === 'sent') {
        return jeab_booking_channel_result(false, false, null, 'already');
    }
    if ($mailClaim === 'held' || $mailClaim === 'unstored') {
        return jeab_booking_channel_result(false, true, 'sms_sent-anspråk', 'claim');
    }
    $noticeId = function_exists('jeab_notiser_booking_notice_id') ? jeab_notiser_booking_notice_id($row, 'mail') : '';
    $sendMail = static function () use ($mailOpts): array {
        return jeab_system_mail_send($mailOpts);
    };
    $result = function_exists('jeab_notiser_send') && $noticeId !== ''
        ? jeab_notiser_send($noticeId, $sendMail)
        : $sendMail();
    if (!empty($result['skipped']) && ($result['reason'] ?? '') === 'notis-av') {
        jeab_sms_channel_release($id, 'email', $starts);
        return jeab_booking_channel_result(false, true, null, 'notis-av');
    }
    if (empty($result['ok']) || !empty($result['queued'])) {
        if (!empty($result['queued'])) {
            return jeab_booking_channel_result(false, true, null, 'queued');
        }
        jeab_sms_channel_release($id, 'email', $starts);
        $error = trim((string) ($result['error'] ?? ''));
        if ($error === '') {
            $error = 'Mejlet kunde inte skickas.';
        }
        return jeab_booking_channel_result(false, false, $error, 'error');
    }
    $done['email'] = true;
    $done['email_at'] = jeab_now();
    $done['email_version'] = $version;
    $done['starts_at'] = $starts;
    $done['created_at'] = (string) ($row['created_at'] ?? '');
    $done['notified_starts_at'] = $starts;
    jeab_booking_mail_remember_thread($done, $result, (string) ($subject ?? ''));
    jeab_sms_record_sent($id, $done, $row, 'mail');
    return jeab_booking_channel_result(true, false, null, 'sent');
}

/**
 * Flyttmejl efter kalenderflytt. Av som standard.
 * $GLOBALS['_JEAB_BOOKING_MOVE_MAIL'] vinner. Annars env BOOKING_MOVE_MAIL.
 * Saknas env läses 0/1 i privat/flags.json. Vitlistan slår inte på flaggan.
 */
function jeab_booking_move_mail_enabled(): bool
{
    if (array_key_exists('_JEAB_BOOKING_MOVE_MAIL', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_BOOKING_MOVE_MAIL'];
    }
    $env = getenv('BOOKING_MOVE_MAIL');
    if ($env === false || $env === '') {
        $env = (string) ($_ENV['BOOKING_MOVE_MAIL'] ?? '');
    }
    $env = strtolower(trim((string) $env));
    if ($env !== '') {
        return $env === '1' || $env === 'true' || $env === 'on' || $env === 'yes';
    }

    return jeab_booking_move_mail_file_value() === 1;
}

/** 0 eller 1 om flags.json har nyckeln, annars null. Env läses inte här. */
function jeab_booking_move_mail_file_value(): ?int
{
    if (!function_exists('jeab_flags_file_path')) {
        return null;
    }
    $path = jeab_flags_file_path();
    if (!is_string($path) || $path === '' || !is_file($path) || is_link($path)) {
        return null;
    }
    if (function_exists('jeab_flags_realpath_inside') && !jeab_flags_realpath_inside($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !array_key_exists('BOOKING_MOVE_MAIL', $decoded)) {
        return null;
    }
    $value = $decoded['BOOKING_MOVE_MAIL'];
    if ($value === 1 || $value === '1' || $value === true) {
        return 1;
    }
    if ($value === 0 || $value === '0' || $value === false) {
        return 0;
    }

    return null;
}

function jeab_booking_move_mail_test_deliver_address(): string
{
    return 'demo.person@example.invalid';
}

function jeab_booking_move_mail_test_deliver_ok(mixed $raw): bool
{
    return strcasecmp(trim((string) $raw), jeab_booking_move_mail_test_deliver_address()) === 0;
}

/** Bara hookens leveransadress. Tom sträng betyder att mejlet går till bokningen. */
function jeab_booking_move_mail_deliver_override(): string
{
    $raw = trim((string) ($GLOBALS['_JEAB_BOOKING_MOVE_MAIL_DELIVER_TO'] ?? ''));
    if (!jeab_booking_move_mail_test_deliver_ok($raw)) {
        return '';
    }

    return jeab_booking_move_mail_test_deliver_address();
}

/**
 * Testbokningar som får flyttmejl även när flaggan är av.
 * $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS'] vinner helt, även som tom lista.
 * Annars slås env BOOKING_MOVE_MAIL_TEST_IDS ihop med samma nyckel i privat/flags.json.
 *
 * @return list<int>
 */
function jeab_booking_move_mail_test_ids(): array
{
    if (array_key_exists('_JEAB_BOOKING_MOVE_MAIL_TEST_IDS', $GLOBALS)) {
        return jeab_booking_move_mail_parse_ids($GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_IDS']);
    }
    $env = getenv('BOOKING_MOVE_MAIL_TEST_IDS');
    if ($env === false || $env === '') {
        $env = (string) ($_ENV['BOOKING_MOVE_MAIL_TEST_IDS'] ?? '');
    }
    $ids = [];
    foreach (array_merge(jeab_booking_move_mail_parse_ids($env), jeab_booking_move_mail_test_ids_from_flags()) as $id) {
        $ids[$id] = $id;
    }
    $list = array_values($ids);
    sort($list, SORT_NUMERIC);

    return $list;
}

/** @return list<int> */
function jeab_booking_move_mail_parse_ids(mixed $raw): array
{
    if (is_int($raw)) {
        return $raw > 0 ? [$raw] : [];
    }
    if (is_string($raw)) {
        $text = trim($raw);
        if ($text === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $part) {
            if (preg_match('/^[1-9]\d*$/', $part) === 1) {
                $ids[(int) $part] = (int) $part;
            }
        }
        $list = array_values($ids);
        sort($list, SORT_NUMERIC);

        return $list;
    }
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $item) {
        foreach (jeab_booking_move_mail_parse_ids($item) as $id) {
            $ids[$id] = $id;
        }
    }
    $list = array_values($ids);
    sort($list, SORT_NUMERIC);

    return $list;
}

/** @return list<int> */
function jeab_booking_move_mail_test_ids_from_flags(): array
{
    if (!function_exists('jeab_flags_file_path')) {
        return [];
    }
    $path = jeab_flags_file_path();
    if (!is_string($path) || $path === '' || !is_file($path) || is_link($path)) {
        return [];
    }
    if (function_exists('jeab_flags_realpath_inside') && !jeab_flags_realpath_inside($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !array_key_exists('BOOKING_MOVE_MAIL_TEST_IDS', $decoded)) {
        return [];
    }

    return jeab_booking_move_mail_parse_ids($decoded['BOOKING_MOVE_MAIL_TEST_IDS']);
}

function jeab_booking_move_mail_test_forced(int $id): bool
{
    $force = $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE'] ?? null;

    return $id > 0 && is_int($force) && $force === $id;
}

/**
 * Vitlistad testkund, eller hookens enda testbokning.
 * En riktig kund är aldrig med här. Flaggan för riktiga kunder rörs inte.
 *
 * @param array<string, mixed> $row
 */
function jeab_booking_move_mail_for_test(int $id, array $row): bool
{
    if ($id <= 0 || !jeab_booking_customer_mail_is_test($row)) {
        return false;
    }
    if (jeab_booking_move_mail_test_forced($id)) {
        return true;
    }

    return in_array($id, jeab_booking_move_mail_test_ids(), true);
}

/** @param array<string, mixed> $row */
function jeab_booking_customer_mail_is_test(array $row): bool
{
    if (!function_exists('jeab_test_lead_strict')) {
        return false;
    }
    $shown = function_exists('jeab_sms_shown_booking') ? jeab_sms_shown_booking($row) : $row;
    $test = jeab_test_lead_strict([
        'email' => (string) ($shown['email'] ?? ''),
        'is_test' => !empty($shown['is_test']),
        'flag_reason' => (string) ($shown['flag_reason'] ?? ''),
    ]);
    if (!empty($test['test'])) {
        return true;
    }
    $raw = jeab_test_lead_strict([
        'email' => (string) ($row['email'] ?? ''),
        'is_test' => !empty($row['is_test']),
        'flag_reason' => (string) ($row['flag_reason'] ?? ''),
    ]);

    return !empty($raw['test']);
}

/** Senaste tiden kunden redan har fått i bekräftelse eller flyttmejl. */
function jeab_booking_move_mail_notified_at(array $done): string
{
    $notified = trim((string) ($done['notified_starts_at'] ?? ''));
    if ($notified !== '') {
        return jeab_booking_move_mail_slot($notified);
    }
    if (!empty($done['move_email'])) {
        $moved = trim((string) ($done['move_starts_at'] ?? ''));
        if ($moved !== '') {
            return jeab_booking_move_mail_slot($moved);
        }
    }
    if (!empty($done['email']) && empty($done['move_pending'])) {
        return jeab_booking_move_mail_slot((string) ($done['starts_at'] ?? ''));
    }

    return '';
}

function jeab_booking_move_mail_slot(string $starts): string
{
    $starts = trim($starts);
    $moment = jeab_booking_moment($starts);

    return $moment instanceof DateTimeImmutable ? $moment->format('Y-m-d H:i:s') : $starts;
}

function jeab_booking_move_mail_now(): string
{
    $frozen = $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_NOW'] ?? null;
    if (is_string($frozen) && trim($frozen) !== '') {
        return jeab_booking_move_mail_slot($frozen);
    }

    return jeab_booking_move_mail_slot(function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'));
}

/**
 * @param array<string, mixed> $done
 * @param array<string, mixed> $result
 */
function jeab_booking_mail_remember_thread(array &$done, array $result, string $subject): void
{
    $subject = trim($subject);
    if ($subject !== '' && trim((string) ($done['email_subject'] ?? '')) === '') {
        $done['email_subject'] = $subject;
    }
    $path = (string) ($result['path'] ?? '');
    $id = trim((string) ($result['id'] ?? ''));
    if ($path === 'helpscout' && ctype_digit($id) && trim((string) ($done['hs_conversation_id'] ?? '')) === '') {
        $done['hs_conversation_id'] = $id;
    }
    $thread = trim((string) ($result['thread_id'] ?? ''));
    if ($thread === '' && $path !== 'helpscout' && $id !== '' && $id !== 'graph') {
        $thread = $id;
    }
    if ($thread !== '' && trim((string) ($done['email_thread_id'] ?? '')) === '') {
        $done['email_thread_id'] = $thread;
    }
    $graph = trim((string) ($result['graph_id'] ?? ''));
    if ($graph !== '' && trim((string) ($done['email_graph_id'] ?? '')) === '') {
        $done['email_graph_id'] = $graph;
    }
}

function jeab_booking_move_mail_pdo(): ?PDO
{
    return function_exists('jeab_sms_store_pdo') ? jeab_sms_store_pdo() : null;
}

function jeab_booking_move_mail_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_move_queue (
                booking_id INT UNSIGNED NOT NULL,
                starts_at VARCHAR(32) NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_move_sent (
                booking_id INT UNSIGNED NOT NULL,
                starts_at VARCHAR(32) NOT NULL,
                sent_at DATETIME NOT NULL,
                PRIMARY KEY (booking_id, starts_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_move_queue (
                booking_id INTEGER PRIMARY KEY,
                starts_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS booking_move_sent (
                booking_id INTEGER NOT NULL,
                starts_at TEXT NOT NULL,
                sent_at TEXT NOT NULL,
                PRIMARY KEY (booking_id, starts_at)
            )'
        );
    }
    $done[$key] = true;
}

function jeab_booking_move_mail_queue_put(int $id, string $startsAt): void
{
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO || $id <= 0 || $startsAt === '') {
        return;
    }
    jeab_booking_move_mail_ensure($pdo);
    $now = jeab_booking_move_mail_now();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    if ($driver === 'mysql') {
        $pdo->prepare(
            'INSERT INTO booking_move_queue (booking_id, starts_at, updated_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE starts_at = VALUES(starts_at), updated_at = VALUES(updated_at)'
        )->execute([$id, $startsAt, $now]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO booking_move_queue (booking_id, starts_at, updated_at) VALUES (?, ?, ?)
         ON CONFLICT(booking_id) DO UPDATE SET
            starts_at = excluded.starts_at,
            updated_at = excluded.updated_at'
    )->execute([$id, $startsAt, $now]);
}

/**
 * Tillbaka till tiden i den skickade bekräftelsen: ta bort kön.
 * Gäller oavsett BOOKING_MOVE_MAIL och vitlista. Sant när posten togs bort.
 */
function jeab_booking_move_mail_revert_queue(int $id, string $startsAt): bool
{
    if ($id <= 0 || !jeab_sms_email_already_sent($id)) {
        return false;
    }
    $slot = jeab_booking_move_mail_slot($startsAt);
    if ($slot === '') {
        return false;
    }
    $prev = jeab_sms_sent_store()[(string) $id] ?? [];
    if (!is_array($prev)) {
        $prev = [];
    }
    if (trim((string) ($prev['notified_starts_at'] ?? '')) === '') {
        $prev['notified_starts_at'] = jeab_booking_move_mail_notified_at($prev);
    }
    if (jeab_booking_move_mail_slot((string) $prev['notified_starts_at']) !== $slot) {
        return false;
    }
    $prev['starts_at'] = $slot;
    $prev['email'] = true;
    $prev['move_pending'] = false;
    $prev['move_starts_at'] = $slot;
    jeab_sms_save_sent($id, $prev);
    jeab_booking_move_mail_queue_delete($id);

    return true;
}

function jeab_booking_move_mail_queue_delete(int $id): void
{
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO || $id <= 0) {
        return;
    }
    jeab_booking_move_mail_ensure($pdo);
    $pdo->prepare('DELETE FROM booking_move_queue WHERE booking_id = ?')->execute([$id]);
}

/** @return list<array{booking_id:int,starts_at:string}> */
function jeab_booking_move_mail_queue_pending(): array
{
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    jeab_booking_move_mail_ensure($pdo);
    $stmt = $pdo->query(
        'SELECT booking_id, starts_at FROM booking_move_queue ORDER BY updated_at ASC LIMIT 20'
    );
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
        ];
    }

    return $out;
}

function jeab_booking_move_mail_sent_has(int $id, string $startsAt): bool
{
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO || $id <= 0 || $startsAt === '') {
        return false;
    }
    jeab_booking_move_mail_ensure($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM booking_move_sent WHERE booking_id = ? AND starts_at = ? LIMIT 1');
    $stmt->execute([$id, $startsAt]);

    return $stmt->fetchColumn() !== false;
}

/** True när den här körningen fick skriva nyckeln. False när tiden redan är loggad. */
function jeab_booking_move_mail_sent_claim(int $id, string $startsAt): bool
{
    if (jeab_booking_move_mail_sent_has($id, $startsAt)) {
        return false;
    }
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    jeab_booking_move_mail_ensure($pdo);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    $sql = $driver === 'mysql'
        ? 'INSERT IGNORE INTO booking_move_sent (booking_id, starts_at, sent_at) VALUES (?, ?, ?)'
        : 'INSERT OR IGNORE INTO booking_move_sent (booking_id, starts_at, sent_at) VALUES (?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $startsAt, jeab_booking_move_mail_now()]);
    if ($driver === 'mysql') {
        return $stmt->rowCount() > 0;
    }

    return (int) $pdo->query('SELECT changes()')->fetchColumn() > 0;
}

function jeab_booking_move_mail_sent_release(int $id, string $startsAt): void
{
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO || $id <= 0 || $startsAt === '') {
        return;
    }
    jeab_booking_move_mail_ensure($pdo);
    $pdo->prepare('DELETE FROM booking_move_sent WHERE booking_id = ? AND starts_at = ?')->execute([$id, $startsAt]);
}

/**
 * Tid ändrad efter att bekräftelsen gått: skriv en väntande flyttpost.
 * Ny flytt ersätter posten. Sparningen skickar inget mejl.
 * Samma tid som kunden redan fått, eller en avbokning, tar bort kön
 * även när BOOKING_MOVE_MAIL och vitlistan är av.
 *
 * @param array<string, mixed> $booking
 */
function jeab_booking_mark_move_mail(int $id, string $startsAt, array $booking = []): void
{
    if ($id <= 0) {
        return;
    }
    if (($booking['status'] ?? 'booked') === 'cancelled') {
        jeab_booking_move_mail_queue_delete($id);
        return;
    }
    if (jeab_booking_move_mail_revert_queue($id, $startsAt)) {
        return;
    }
    $testPath = $booking !== [] && jeab_booking_move_mail_for_test($id, $booking);
    if (!jeab_booking_move_mail_enabled() && !$testPath) {
        return;
    }
    if (!jeab_sms_email_already_sent($id)) {
        return;
    }
    if ($booking !== [] && jeab_booking_customer_mail_is_test($booking) && !$testPath) {
        return;
    }
    if ($booking !== [] && jeab_sol_calendar_customer_mail_blocked($booking)) {
        return;
    }
    $prev = jeab_sms_sent_store()[(string) $id] ?? [];
    if (!is_array($prev)) {
        $prev = [];
    }
    $slot = jeab_booking_move_mail_slot($startsAt);
    if (trim((string) ($prev['notified_starts_at'] ?? '')) === '') {
        $prev['notified_starts_at'] = jeab_booking_move_mail_notified_at($prev);
    }
    $prev['starts_at'] = $slot;
    $prev['email'] = true;
    if (jeab_booking_move_mail_slot((string) $prev['notified_starts_at']) === $slot) {
        $prev['move_pending'] = false;
        $prev['move_starts_at'] = $slot;
        jeab_sms_save_sent($id, $prev);
        jeab_booking_move_mail_queue_delete($id);
        return;
    }
    $prev['sms'] = false;
    $prev['move_pending'] = true;
    $prev['move_email'] = false;
    $prev['move_email_at'] = '';
    $prev['move_starts_at'] = $slot;
    jeab_sms_save_sent($id, $prev);
    jeab_booking_move_mail_queue_put($id, $slot);
}

function jeab_email_move_letter(): string
{
    return "{hello}\n\nVi har tyvärr behövt flytta din tid. Den nya tiden är {nar}.\n\nPassar det inte, svara bara på det här mejlet med förslag på senare tider.";
}

/** @param array<string, mixed> $row */
function jeab_email_move_text(array $row): string
{
    $letter = jeab_email_move_letter();
    if (function_exists('jeab_notiser_text') && function_exists('jeab_notiser_move_notice_id')) {
        $letter = jeab_notiser_text(jeab_notiser_move_notice_id($row), $letter);
    }

    return strtr($letter, jeab_email_visit_tokens($row));
}

/**
 * Kort kundmejl med den nya tiden. Samma avsändare, ämne och tråd som bekräftelsen.
 * Help Scout först, sedan Graph, via jeab_system_mail_send. Aldrig mail().
 * En gång per sluttid: booking_move_sent har nyckeln booking_id + starts_at.
 *
 * @param array<string, mixed> $row
 * @return array{sent: bool, skipped: bool, error: ?string, reason: string}
 */
function jeab_booking_send_move_mail(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    if (jeab_booking_move_mail_test_forced($id) && !jeab_booking_customer_mail_is_test($row)) {
        return jeab_booking_channel_result(false, true, null, 'skip');
    }
    $testPath = $id > 0 && jeab_booking_move_mail_for_test($id, $row);
    if (!jeab_booking_move_mail_enabled() && !$testPath) {
        return jeab_booking_channel_result(false, true, null, 'skip');
    }
    $starts = jeab_booking_move_mail_slot((string) ($row['starts_at'] ?? ''));
    if ($id <= 0 || ($row['status'] ?? 'booked') === 'cancelled' || jeab_sol_calendar_customer_mail_blocked($row)) {
        if ($id > 0) {
            jeab_booking_move_mail_queue_delete($id);
        }
        return jeab_booking_channel_result(false, true, null, 'skip');
    }
    if (jeab_booking_customer_mail_is_test($row) && !$testPath) {
        jeab_booking_move_mail_queue_delete($id);
        return jeab_booking_channel_result(false, true, null, 'skip');
    }
    $done = jeab_sms_sent_store()[(string) $id] ?? [];
    if (!is_array($done)) {
        $done = [];
    }
    $notified = jeab_booking_move_mail_notified_at($done);
    if ($notified !== '' && $notified === $starts) {
        $done['move_pending'] = false;
        jeab_sms_save_sent($id, $done);
        jeab_booking_move_mail_queue_delete($id);
        return jeab_booking_channel_result(false, true, null, 'same');
    }
    if (empty($done['move_pending'])) {
        $reason = !empty($done['move_email']) ? 'already' : 'skip';
        if ($reason === 'already') {
            jeab_booking_move_mail_queue_delete($id);
        }
        return jeab_booking_channel_result(false, $reason === 'skip', null, $reason);
    }
    if (jeab_booking_move_mail_sent_has($id, $starts)) {
        $done['move_pending'] = false;
        $done['move_email'] = true;
        $done['move_starts_at'] = $starts;
        $done['notified_starts_at'] = $starts;
        jeab_sms_save_sent($id, $done);
        jeab_booking_move_mail_queue_delete($id);
        return jeab_booking_channel_result(false, false, null, 'already');
    }
    $shown = jeab_sms_shown_booking($row);
    // Samma strikta Kontakter-spärr som bekräftelsemejlet (#777).
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'email');
        if ($block !== '') {
            return jeab_booking_channel_result(false, true, $block, 'strict');
        }
    }
    $email = trim((string) ($shown['email'] ?? ''));
    if ($email === '' || !str_contains($email, '@')) {
        return jeab_booking_channel_result(false, $email === '', null, $email === '' ? 'no-email' : 'bad-email');
    }
    $job = jeab_booking_job_type($shown);
    $built = match ($job) {
        'laddbox' => jeab_email_laddbox_subject($shown),
        'batteri' => jeab_email_batteri_subject($shown),
        'sol' => jeab_email_sol_subject($shown),
        default => '',
    };
    $subject = trim((string) ($done['email_subject'] ?? ''));
    if ($subject === '') {
        $subject = $built;
    }
    $mailCompany = jeab_calendar_mail_company($row);
    $mailOpts = [
        'to' => $email,
        'subject' => $subject,
        'text' => jeab_email_move_text($shown),
        'job_type' => $job,
        'tags' => ['kalender'],
        'company' => $mailCompany,
        'guard_template' => 'booking_notice',
        'guard_mode' => 'auto',
        'guard_via' => 'auto',
        'guard_actor' => 'system',
        'booking_id' => $id,
        'starts_at' => $starts,
        'booking_notice_kind' => 'move',
    ];
    $thread = trim((string) ($done['email_thread_id'] ?? ''));
    if ($thread !== '') {
        $mailOpts['thread_id'] = $thread;
    }
    $graph = trim((string) ($done['email_graph_id'] ?? ''));
    if ($graph !== '') {
        $mailOpts['reply_graph_id'] = $graph;
    }
    $hs = trim((string) ($done['hs_conversation_id'] ?? ''));
    if ($hs !== '') {
        $mailOpts['hs_conversation_id'] = $hs;
    }
    $fromEmail = jeab_booking_confirm_mail_from($row);
    if ($fromEmail !== null) {
        $mailOpts['from_email'] = $fromEmail;
        $mailOpts['from_name'] = function_exists('jeab_system_mail_from_name')
            ? jeab_system_mail_from_name($mailCompany)
            : 'Demo Energi Malmö';
    }
    $deliver = jeab_booking_move_mail_test_forced($id) ? jeab_booking_move_mail_deliver_override() : '';
    if ($deliver !== '') {
        $mailOpts['deliver_customer_email'] = $email;
        $mailOpts['to'] = $deliver;
        unset($mailOpts['thread_id'], $mailOpts['reply_graph_id'], $mailOpts['hs_conversation_id']);
        if ($mailCompany === 'malmo') {
            $mailOpts['deliver_direct'] = 1;
        }
    }
    if (!jeab_booking_move_mail_sent_claim($id, $starts)) {
        jeab_booking_move_mail_queue_delete($id);
        return jeab_booking_channel_result(false, false, null, 'already');
    }
    $noticeId = function_exists('jeab_notiser_move_notice_id') ? jeab_notiser_move_notice_id($row) : '';
    $sendMail = static function () use ($mailOpts): array {
        return jeab_system_mail_send($mailOpts);
    };
    $result = function_exists('jeab_notiser_send') && $noticeId !== ''
        ? jeab_notiser_send($noticeId, $sendMail)
        : $sendMail();
    if (!empty($result['skipped']) && ($result['reason'] ?? '') === 'notis-av') {
        jeab_booking_move_mail_sent_release($id, $starts);
        $done['move_pending'] = false;
        jeab_sms_save_sent($id, $done);
        jeab_booking_move_mail_queue_delete($id);
        return jeab_booking_channel_result(false, true, null, 'notis-av');
    }
    if (empty($result['ok']) || !empty($result['queued'])) {
        if (!empty($result['queued'])) {
            return jeab_booking_channel_result(false, true, null, 'queued', jeab_booking_move_mail_provider_extra($result, $deliver));
        }
        jeab_booking_move_mail_sent_release($id, $starts);
        jeab_booking_move_mail_queue_put($id, $starts);
        $error = trim((string) ($result['error'] ?? ''));
        if ($error === '') {
            $error = 'Mejlet kunde inte skickas.';
        }
        return jeab_booking_channel_result(false, false, $error, 'error', jeab_booking_move_mail_provider_extra($result, $deliver));
    }
    $done['move_email'] = true;
    $done['move_email_at'] = jeab_now();
    $done['move_pending'] = false;
    $done['move_starts_at'] = $starts;
    $done['starts_at'] = $starts;
    $done['notified_starts_at'] = $starts;
    $done['email'] = true;
    jeab_sms_record_sent($id, $done, $row, 'move');
    jeab_booking_move_mail_queue_delete($id);
    return jeab_booking_channel_result(true, false, null, 'sent', jeab_booking_move_mail_provider_extra($result, $deliver));
}

/**
 * Ett köat flyttmejl. Samma steg som sms-cronen tar per post.
 *
 * @return array{sent: bool, skipped: bool, error: ?string, reason: string}
 */
function jeab_booking_move_mail_send_queued(JeabStore $store, int $id, ?string $queuedStarts = null): array
{
    $row = $store->getBooking($id);
    if (!is_array($row)) {
        jeab_booking_move_mail_queue_delete($id);

        return jeab_booking_channel_result(false, true, null, 'skip');
    }
    $slot = jeab_booking_move_mail_slot((string) ($row['starts_at'] ?? ''));
    if ($queuedStarts !== null && $slot !== '' && $slot !== jeab_booking_move_mail_slot($queuedStarts)) {
        jeab_booking_move_mail_queue_put($id, $slot);
    }

    return jeab_booking_send_move_mail($row);
}

/**
 * Samma sms-cron som bekräftelserna tar väntande flyttposter.
 * Sluttiden är bokningens tid nu. Samma tid som kunden redan fått hoppas över.
 * Flera flyttar mellan körningarna blir ett mejl.
 * Flaggan av lämnar riktiga kunder i kön. Bara vitlistade testbokningar tas då.
 *
 * @return array{sent:int, skipped:int, errors:list<array{id:int, channel:string, error:string}>}
 */
function jeab_booking_move_mail_drain(JeabStore $store): array
{
    $out = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    $enabled = jeab_booking_move_mail_enabled();
    $testIds = $enabled ? [] : jeab_booking_move_mail_test_ids();
    if (!$enabled && $testIds === []) {
        return $out;
    }
    foreach (jeab_booking_move_mail_queue_pending() as $job) {
        $id = (int) $job['booking_id'];
        if (!$enabled && !in_array($id, $testIds, true)) {
            continue;
        }
        if (!$enabled) {
            $row = $store->getBooking($id);
            if (is_array($row) && !jeab_booking_move_mail_for_test($id, $row)) {
                continue;
            }
        }
        $result = jeab_booking_move_mail_send_queued($store, $id, (string) $job['starts_at']);
        if (!empty($result['sent'])) {
            $out['sent']++;
            continue;
        }
        if (!empty($result['error'])) {
            $out['errors'][] = ['id' => $id, 'channel' => 'email', 'error' => (string) $result['error']];
            continue;
        }
        $out['skipped']++;
    }

    return $out;
}

/**
 * @return array{booking_move_queue:list<array<string, mixed>>, booking_move_sent:list<array<string, mixed>>}
 */
function jeab_booking_move_mail_rows_for(int $id): array
{
    $empty = ['booking_move_queue' => [], 'booking_move_sent' => []];
    $pdo = jeab_booking_move_mail_pdo();
    if (!$pdo instanceof PDO || $id <= 0) {
        return $empty;
    }
    jeab_booking_move_mail_ensure($pdo);
    $queue = $pdo->prepare('SELECT booking_id, starts_at, updated_at FROM booking_move_queue WHERE booking_id = ?');
    $queue->execute([$id]);
    $sent = $pdo->prepare('SELECT booking_id, starts_at, sent_at FROM booking_move_sent WHERE booking_id = ? ORDER BY starts_at ASC');
    $sent->execute([$id]);
    $queueRows = [];
    foreach ($queue->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $queueRows[] = [
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    $sentRows = [];
    foreach ($sent->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sentRows[] = [
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'sent_at' => (string) ($row['sent_at'] ?? ''),
        ];
    }

    return ['booking_move_queue' => $queueRows, 'booking_move_sent' => $sentRows];
}

function jeab_booking_move_mail_test_one_id(array $input): int
{
    if (array_key_exists('ids', $input) || array_key_exists('booking_ids', $input)) {
        return 0;
    }
    $hasBooking = array_key_exists('booking_id', $input);
    $hasId = array_key_exists('id', $input);
    if ($hasBooking && $hasId && (string) $input['booking_id'] !== (string) $input['id']) {
        return 0;
    }
    $raw = $hasBooking ? $input['booking_id'] : ($hasId ? $input['id'] : null);
    if (is_array($raw) || is_float($raw) || is_bool($raw) || $raw === null) {
        return 0;
    }
    if (is_int($raw)) {
        return $raw > 0 ? $raw : 0;
    }
    $text = trim((string) $raw);
    if (preg_match('/^[1-9]\d*$/', $text) !== 1) {
        return 0;
    }

    return (int) $text;
}

/**
 * booking.move_mail_test. Ett boknings-id som måste vara en testbokning.
 * dry saknas eller är 1: inget mejl. dry=0 kräver deliver_to
 * demo.person@example.invalid. Mejlet ritas för testbokningen och
 * skickas dit. send_guard loggar testlead men stoppar inte den här vägen.
 * En bokning som inte är test mejlas aldrig.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_booking_move_mail_test_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'mailed' => false];
    }
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : !array_key_exists('dry', $input);
    $id = jeab_booking_move_mail_test_one_id($input);
    if ($id <= 0) {
        return [
            'ok' => false,
            'error' => 'Ett boknings-id.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'mailed' => false,
        ];
    }
    $row = $store->getBooking($id);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Bokningen finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'mailed' => false,
        ];
    }
    $rows = jeab_booking_move_mail_rows_for($id);
    if (!jeab_booking_customer_mail_is_test($row)) {
        return [
            'ok' => false,
            'error' => 'inte en testbokning',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'mailed' => false,
        ] + $rows;
    }
    $result = null;
    $deliverTo = '';
    if (!$dry) {
        if (!jeab_booking_move_mail_test_deliver_ok($input['deliver_to'] ?? null)) {
            return [
                'ok' => false,
                'error' => 'deliver_to ska vara ' . jeab_booking_move_mail_test_deliver_address() . '.',
                'status' => 400,
                'dry' => 0,
                'id' => $id,
                'mailed' => false,
            ] + $rows;
        }
        $deliverTo = jeab_booking_move_mail_test_deliver_address();
        $queuedStarts = $rows['booking_move_queue'][0]['starts_at'] ?? null;
        $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE'] = $id;
        $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_DELIVER_TO'] = $deliverTo;
        $GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY'] = true;
        try {
            $result = jeab_booking_move_mail_send_queued($store, $id, is_string($queuedStarts) ? $queuedStarts : null);
        } finally {
            unset(
                $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_TEST_FORCE'],
                $GLOBALS['_JEAB_BOOKING_MOVE_MAIL_DELIVER_TO'],
                $GLOBALS['_JEAB_SEND_GUARD_TESTLEAD_LOG_ONLY']
            );
        }
        $rows = jeab_booking_move_mail_rows_for($id);
    }

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'deliver_to' => $deliverTo,
        'mailed' => is_array($result) && !empty($result['sent']),
        'result' => $result,
        'status' => 200,
    ] + $rows;
}

function jeab_booking_move_test_end(array $row, string $start): string
{
    $oldStart = jeab_booking_moment((string) ($row['starts_at'] ?? ''));
    $oldEnd = jeab_booking_moment((string) ($row['ends_at'] ?? ''));
    $newStart = jeab_booking_moment($start);
    if (!$newStart instanceof DateTimeImmutable) {
        return '';
    }
    $seconds = 3600;
    if ($oldStart instanceof DateTimeImmutable && $oldEnd instanceof DateTimeImmutable) {
        $span = $oldEnd->getTimestamp() - $oldStart->getTimestamp();
        if ($span >= 3600) {
            $seconds = $span;
        }
    }

    return $newStart->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
}

/**
 * booking.move_test. Flyttar en testboknings tid via jeab_update_booking,
 * samma väg som kalendern. dry saknas eller är 1: tiden rörs inte.
 * En bokning som inte är test flyttas aldrig.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_booking_move_test_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'moved' => false];
    }
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : !array_key_exists('dry', $input);
    $id = jeab_booking_move_mail_test_one_id($input);
    if ($id <= 0) {
        return [
            'ok' => false,
            'error' => 'Ett boknings-id.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'moved' => false,
        ];
    }
    $row = $store->getBooking($id);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Bokningen finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'moved' => false,
        ];
    }
    if (!jeab_booking_customer_mail_is_test($row)) {
        return [
            'ok' => false,
            'error' => 'inte en testbokning',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'moved' => false,
            'starts_at' => (string) ($row['starts_at'] ?? ''),
        ];
    }
    $start = function_exists('jeab_parse_datetime') ? jeab_parse_datetime((string) ($input['start'] ?? '')) : null;
    if ($start === null || $start === '') {
        return [
            'ok' => false,
            'error' => 'start saknas.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'moved' => false,
        ];
    }
    $end = jeab_booking_move_test_end($row, $start);
    $rows = jeab_booking_move_mail_rows_for($id);
    if ($dry || !function_exists('jeab_update_booking')) {
        return [
            'ok' => $dry,
            'dry' => 1,
            'id' => $id,
            'moved' => false,
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'ends_at' => (string) ($row['ends_at'] ?? ''),
            'would_starts_at' => $start,
            'would_ends_at' => $end,
            'status' => $dry ? 200 : 400,
            'error' => $dry ? null : 'Flytten kan inte sparas.',
        ] + $rows;
    }
    try {
        $updated = jeab_update_booking($store, $id, [
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    } catch (InvalidArgumentException $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 422,
            'dry' => 0,
            'id' => $id,
            'moved' => false,
            'starts_at' => (string) ($row['starts_at'] ?? ''),
        ];
    }
    $rows = jeab_booking_move_mail_rows_for($id);

    return [
        'ok' => true,
        'dry' => 0,
        'id' => $id,
        'moved' => (string) ($updated['starts_at'] ?? '') === $start,
        'starts_at' => (string) ($updated['starts_at'] ?? ''),
        'ends_at' => (string) ($updated['ends_at'] ?? ''),
        'status' => 200,
    ] + $rows;
}

/**
 * booking.move_queue_clear. Tar bort en kvarliggande rad i booking_move_queue.
 * dry saknas eller är 1: raden ligger kvar. Gäller vilket boknings-id som helst.
 * Rör inte booking_move_sent och skickar inget mejl.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_booking_move_queue_clear_dispatch(JeabStore $store, array $input): array
{
    unset($store);
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'cleared' => false];
    }
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : !array_key_exists('dry', $input);
    $id = jeab_booking_move_mail_test_one_id($input);
    if ($id <= 0) {
        return [
            'ok' => false,
            'error' => 'Ett boknings-id.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'cleared' => false,
        ];
    }
    $rows = jeab_booking_move_mail_rows_for($id);
    $had = ($rows['booking_move_queue'] ?? []) !== [];
    if ($dry) {
        return [
            'ok' => true,
            'dry' => 1,
            'id' => $id,
            'cleared' => false,
            'status' => 200,
        ] + $rows;
    }
    jeab_booking_move_mail_queue_delete($id);

    return [
        'ok' => true,
        'dry' => 0,
        'id' => $id,
        'cleared' => $had,
        'status' => 200,
    ] + jeab_booking_move_mail_rows_for($id);
}

/**
 * booking.create_test. Skapar en testbokning för Järfälla eller Malmö.
 * Bekräftelsen markeras som skickad. Inget mejl går. dry saknas eller är 1.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_booking_create_test_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'created' => false];
    }
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : !array_key_exists('dry', $input);
    $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($input['company'] ?? null) : null;
    if ($company !== 'jarfalla' && $company !== 'malmo') {
        return [
            'ok' => false,
            'error' => 'company ska vara jarfalla eller malmo.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'created' => false,
        ];
    }
    $start = function_exists('jeab_parse_datetime') ? jeab_parse_datetime((string) ($input['start'] ?? '')) : null;
    if ($start === null || $start === '') {
        return [
            'ok' => false,
            'error' => 'start saknas.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'created' => false,
            'company' => $company,
        ];
    }
    $email = 'flytttest@example.com';
    $names = $company === 'malmo' ? ['Kim'] : ['Elin'];
    if ($dry || !function_exists('jeab_create_booking')) {
        return [
            'ok' => $dry,
            'dry' => 1,
            'created' => false,
            'company' => $company,
            'would_starts_at' => $start,
            'email' => $email,
            'is_test' => true,
            'status' => $dry ? 200 : 400,
        ];
    }
    try {
        $row = jeab_create_booking($store, 'Flytttest', $names, $start, null, 'laddbox', [
            'company' => $company,
            'assignees' => $names,
            'email' => $email,
            'phone' => '',
            'address' => 'Testgatan 1',
        ], false);
    } catch (InvalidArgumentException $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 422,
            'dry' => 0,
            'created' => false,
            'company' => $company,
        ];
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !jeab_booking_customer_mail_is_test($row)) {
        return [
            'ok' => false,
            'error' => 'Testbokningen kunde inte skapas.',
            'status' => 400,
            'dry' => 0,
            'created' => false,
            'company' => $company,
            'id' => $id,
        ];
    }
    $slot = jeab_booking_move_mail_slot((string) ($row['starts_at'] ?? ''));
    jeab_sms_save_sent($id, [
        'starts_at' => $slot,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'email' => true,
        'email_at' => jeab_now(),
        'notified_starts_at' => $slot,
        'move_pending' => false,
        'sms' => false,
    ]);

    return [
        'ok' => true,
        'dry' => 0,
        'created' => true,
        'id' => $id,
        'company' => (string) ($row['company'] ?? $company),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'is_test' => true,
        'confirmation_sent' => jeab_sms_email_already_sent($id),
        'status' => 200,
    ];
}

/**
 * booking.delete_test. Tar bort en testbokning. En riktig kund rörs inte.
 * dry saknas eller är 1: bokningen ligger kvar.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_booking_delete_test_dispatch(JeabStore $store, array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401, 'deleted' => false];
    }
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : !array_key_exists('dry', $input);
    $id = jeab_booking_move_mail_test_one_id($input);
    if ($id <= 0) {
        return [
            'ok' => false,
            'error' => 'Ett boknings-id.',
            'status' => 400,
            'dry' => $dry ? 1 : 0,
            'deleted' => false,
        ];
    }
    $row = $store->getBooking($id);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'error' => 'Bokningen finns inte.',
            'status' => 404,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    if (!jeab_booking_customer_mail_is_test($row)) {
        return [
            'ok' => false,
            'error' => 'inte en testbokning',
            'status' => 409,
            'dry' => $dry ? 1 : 0,
            'id' => $id,
            'deleted' => false,
        ];
    }
    if ($dry) {
        return [
            'ok' => true,
            'dry' => 1,
            'id' => $id,
            'deleted' => false,
            'company' => (string) ($row['company'] ?? ''),
            'status' => 200,
        ];
    }
    jeab_booking_move_mail_queue_delete($id);
    $deleted = method_exists($store, 'deleteBooking') && $store->deleteBooking($id);
    if (!$deleted) {
        return [
            'ok' => false,
            'error' => 'Bokningen kunde inte tas bort.',
            'status' => 400,
            'dry' => 0,
            'id' => $id,
            'deleted' => false,
        ];
    }

    return [
        'ok' => true,
        'dry' => 0,
        'id' => $id,
        'deleted' => $store->getBooking($id) === null,
        'status' => 200,
    ];
}

/** Minst två timmar orörd (senast ändrad) innan bekräftelsemejlet får gå. */
function jeab_booking_mail_min_age_seconds(): int
{
    return 2 * 60 * 60;
}

/**
 * Senast ändrad. updated_at sätts vid skapande och flyttas vid varje ändring,
 * så en ändring innan mejlet gått startar om tvåtimmarsklockan.
 * Saknas updated_at används created_at. Den senare av de två vinner.
 */
function jeab_booking_mail_last_changed(array $row): string
{
    $created = trim((string) ($row['created_at'] ?? ''));
    $updated = trim((string) ($row['updated_at'] ?? ''));
    if ($updated === '') {
        return $created;
    }
    if ($created === '') {
        return $updated;
    }
    return $updated >= $created ? $updated : $created;
}

/**
 * Öppen nedre gräns. Tickern väljer inte «skapad/ändrad inom senaste 4 timmarna».
 * Allt som varit orört minst två timmar och vars start inte är klart förbi kan mejlas en gång.
 */
function jeab_booking_mail_due_from(): string
{
    return '1970-01-01 00:00:00';
}

/**
 * Store-frågan är updated_at < $until. En sekund läggs på så exakt två timmar ingår.
 */
function jeab_booking_mail_due_until(string $nowSql): string
{
    $now = jeab_booking_moment($nowSql);
    if ($now === null) {
        return $nowSql;
    }
    return $now
        ->modify('-' . jeab_booking_mail_min_age_seconds() . ' seconds')
        ->modify('+1 second')
        ->format('Y-m-d H:i:s');
}

/** Redo när senast ändrad är minst två timmar före $nowSql. Tom stämpel är inte redo. */
function jeab_booking_mail_age_ready(array $row, string $nowSql): bool
{
    $moment = jeab_booking_moment(jeab_booking_mail_last_changed($row));
    $now = jeab_booking_moment($nowSql);
    if ($moment === null || $now === null) {
        return false;
    }
    return $moment->getTimestamp() <= $now->getTimestamp() - jeab_booking_mail_min_age_seconds();
}

/**
 * Kundbekräftelse. Bara mejl, via system_mail, och bara när bokningen
 * varit orörd minst två timmar. Sparvägar anropar inte den här.
 * SMS skickas inte här. Det är påminnelsen «kommer imorgon» i jeab_sms_tick.
 *
 * @return array{email: array{sent: bool, skipped: bool, error: ?string, reason: string}}
 */
function jeab_booking_notify_customer(array $row): array
{
    $empty = jeab_booking_channel_result(false, true, null, 'skip');
    try {
        if (($row['status'] ?? 'booked') === 'cancelled' || jeab_sol_calendar_customer_mail_blocked($row)) {
            $result = ['email' => $empty];
        } elseif (!jeab_booking_mail_age_ready($row, jeab_now())) {
            $result = ['email' => jeab_booking_channel_result(false, true, null, 'wait')];
        } else {
            $result = ['email' => jeab_booking_send_customer_mail($row)];
        }
    } catch (Throwable $e) {
        $message = trim($e->getMessage());
        if ($message === '') {
            $message = 'Kundbekräftelsen kunde inte skickas.';
        }
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('email', $message);
        }
        $result = ['email' => jeab_booking_channel_result(false, false, $message, 'error')];
    }
    $GLOBALS['_JEAB_BOOKING_NOTIFY'] = $result;
    return $result;
}

function jeab_booking_notify_notice(?array $result): string
{
    if (!is_array($result)) {
        return '';
    }
    $email = is_array($result['email'] ?? null) ? $result['email'] : [];
    if (!empty($email['sent'])) {
        return 'Bekräftelsemejl skickat.';
    }
    if (!empty($email['error'])) {
        return 'Bekräftelsemejlet gick inte iväg.';
    }
    return '';
}

function jeab_booking_saved_flash(string $base): string
{
    $notice = jeab_booking_notify_notice(
        isset($GLOBALS['_JEAB_BOOKING_NOTIFY']) && is_array($GLOBALS['_JEAB_BOOKING_NOTIFY'])
            ? $GLOBALS['_JEAB_BOOKING_NOTIFY']
            : null
    );
    $text = $notice !== '' ? $base . ' ' . $notice : $base;
    $outlook = trim((string) ($GLOBALS['_JEAB_BOOKING_OUTLOOK_NOTE'] ?? ''));
    if ($outlook !== '') {
        $text .= ' ' . $outlook;
    }
    $overlap = trim((string) ($GLOBALS['_JEAB_BOOKING_OVERLAP_NOTE'] ?? ''));
    if ($overlap !== '') {
        $text .= ' ' . $overlap;
    }
    return $text;
}

function jeab_sms_booking_is_edit(array $row): bool
{
    $created = trim((string) ($row['created_at'] ?? ''));
    $updated = trim((string) ($row['updated_at'] ?? ''));
    return $created !== '' && $updated !== '' && $updated !== $created;
}

/** En gång per bokning. Oberoende av starts_at och av om updated_at flyttats efteråt. */
function jeab_sms_email_already_sent(int $bookingId): bool
{
    if ($bookingId <= 0) {
        return true;
    }
    $row = jeab_sms_sent_store()[(string) $bookingId] ?? null;
    return is_array($row) && !empty($row['email']);
}

function jeab_sms_tick_token_ok(): bool
{
    $expected = trim((string) (jeab_suresms_config()['tick_token'] ?? ''));
    return jeab_incoming_token_ok($expected);
}

function jeab_booking_job_type(array $row): string
{
    if (function_exists('jeab_job_types_from_row')) {
        $jobs = jeab_job_types_from_row($row);
        foreach ($jobs as $job) {
            if ($job !== 'sol') {
                return $job;
            }
        }
        return $jobs[0] ?? '';
    }
    $job = strtolower(trim((string) ($row['job_type'] ?? '')));
    if (function_exists('jeab_normalize_job_type')) {
        $normalized = jeab_normalize_job_type($job !== '' ? $job : null);
        if ($normalized !== null) {
            return $normalized;
        }
    }
    return $job;
}

function jeab_booking_first_name(array $row): string
{
    $name = trim((string) ($row['customer_name'] ?? ''));
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    return trim((string) ($parts[0] ?? ''));
}

/** @return array{day: string, clock: string} */
function jeab_booking_when(array $row): array
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $start = new DateTimeImmutable((string) $row['starts_at'], $tz);
    $end = new DateTimeImmutable((string) ($row['ends_at'] ?? $row['starts_at']), $tz);
    $day = jeab_sv_weekday($start) . ' ' . $start->format('j') . ' ' . jeab_sv_month($start);
    $allDay = $start->format('H:i') === '00:00' && $end->format('H:i') === '00:00' && $end > $start;
    if ($allDay) {
        return ['day' => $day, 'clock' => ''];
    }
    $from = (int) $start->format('G');
    $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
    $toHour = (int) $end->format('G');
    $toMin = (int) $end->format('i');
    if ($sameDay && $end > $start && $toMin === 0 && $toHour !== $from) {
        return ['day' => $day, 'clock' => 'kl ' . $from . '–' . $toHour];
    }
    return ['day' => $day, 'clock' => 'kl ' . $from];
}

function jeab_sms_letter(): string
{
    return '{brand} kommer imorgon{klocka}.';
}

function jeab_sms_visit_is_today(array $row, ?DateTimeImmutable $now = null): bool
{
    $tz = new DateTimeZone('Europe/Stockholm');
    $now ??= new DateTimeImmutable('now', $tz);
    try {
        $start = new DateTimeImmutable((string) ($row['starts_at'] ?? ''), $tz);
    } catch (Throwable) {
        return false;
    }

    return $start->format('Y-m-d') === $now->setTimezone($tz)->format('Y-m-d');
}

function jeab_notiser_booking_company(array $row): string
{
    $company = function_exists('jeab_calendar_mail_company') ? jeab_calendar_mail_company($row) : (string) ($row['company'] ?? '');
    return $company === 'malmo' ? 'malmo' : 'jarfalla';
}

function jeab_sms_text(array $row): string
{
    // Samma bolag som mejl-From: jeab_calendar_mail_company, sedan
    // jeab_system_mail_from_name (Demo Energi Järfälla / Demo Energi Malmö).
    $brand = jeab_system_mail_from_name(jeab_calendar_mail_company($row));
    $tz = new DateTimeZone(date_default_timezone_get());
    $start = new DateTimeImmutable((string) $row['starts_at'], $tz);
    $end = new DateTimeImmutable((string) ($row['ends_at'] ?? $row['starts_at']), $tz);
    $allDay = $start->format('H:i') === '00:00' && $end->format('H:i') === '00:00' && $end > $start;
    $klocka = '';
    if (!$allDay) {
        $from = (int) $start->format('G');
        $sameDay = $start->format('Y-m-d') === $end->format('Y-m-d');
        $toHour = (int) $end->format('G');
        $toMin = (int) $end->format('i');
        if ($sameDay && $end > $start && $toMin === 0 && $toHour !== $from) {
            $klocka = ' kl ' . $from . '–' . $toHour;
        } else {
            $klocka = ' kl ' . $from;
        }
    }
    $job = jeab_booking_job_type($row);
    if (!in_array($job, ['laddbox', 'batteri', 'sol', 'service'], true)) {
        $job = 'service';
    }
    $letter = jeab_sms_letter();
    if (function_exists('jeab_notiser_text')) {
        $letter = jeab_notiser_text('sms-' . $job . '-' . jeab_notiser_booking_company($row), $letter);
    }
    if (jeab_sms_visit_is_today($row)) {
        $letter = str_replace('imorgon', 'idag', $letter);
    }
    return strtr($letter, ['{brand}' => $brand, '{klocka}' => $klocka]);
}

function jeab_email_service_letter(): string
{
    return 'Bokad tid: {nar}.';
}

function jeab_email_text(array $row): string
{
    $when = jeab_booking_when($row);
    $nar = $when['clock'] === '' ? $when['day'] : $when['day'] . ' ' . $when['clock'];
    $letter = jeab_email_service_letter();
    if (function_exists('jeab_notiser_text')) {
        $letter = jeab_notiser_text('bekraft-service-' . jeab_notiser_booking_company($row), $letter);
    }
    return strtr($letter, ['{nar}' => $nar]);
}

function jeab_email_laddbox_subject(array $row): string
{
    $when = jeab_booking_when($row);
    $subject = 'Installation av laddbox ' . $when['day'];
    if ($when['clock'] !== '') {
        $subject .= ' ' . $when['clock'];
    }
    return $subject;
}

function jeab_email_laddbox_letter(): string
{
    return "{hello}\n\n"
        . "Vi kommer hem till dig {nar} och sätter upp din laddbox.\n\n"
        . "Du behöver inte vara hemma hela tiden. Vi måste däremot komma åt elcentralen och mätarskåpet. Är du hemma i början går vi igenom var boxen ska sitta. Bilen behöver inte vara på plats.\n\n"
        . "Inför besöket:\n\n"
        . "Frigör vägen till elcentral, mätarskåp och väggen där boxen ska sitta.\n\n"
        . "Be nätägaren öppna/aktivera HAN-porten på elmätaren, så lastbalanseringen fungerar när vi kommer.\n\n"
        . "Om kabeln ska i marken: det gula röret (50 mm, med dragtråd) ska redan ligga. Säg till om det inte är gjort, så flyttar vi tiden.\n\n"
        . "Får det inte plats fler säkringar i elcentralen tillkommer 1 000 kr för en ny kapsling. Det säger vi till om på plats, innan vi gör det.\n\n"
        . "Vi tar med boxen. Jobbet tar oftast några timmar. Fakturan kommer när det är klart. Grönt avdrag sköter vi.\n\n"
        . 'Behöver du flytta tiden, säg till senast 72 timmar innan.';
}

function jeab_email_visit_tokens(array $row): array
{
    $when = jeab_booking_when($row);
    $first = jeab_booking_first_name($row);
    return [
        '{hello}' => $first !== '' ? 'Hej ' . $first . ',' : 'Hej,',
        '{nar}' => $when['clock'] !== '' ? $when['day'] . ' ' . $when['clock'] : $when['day'],
    ];
}

function jeab_email_laddbox_text(array $row): string
{
    $letter = jeab_email_laddbox_letter();
    if (function_exists('jeab_notiser_text')) {
        $letter = jeab_notiser_text('bekraft-laddbox-' . jeab_notiser_booking_company($row), $letter);
    }
    return strtr($letter, jeab_email_visit_tokens($row));
}

function jeab_email_batteri_subject(array $row): string
{
    $when = jeab_booking_when($row);
    $subject = 'Installation av batteri ' . $when['day'];
    if ($when['clock'] !== '') {
        $subject .= ' ' . $when['clock'];
    }
    return $subject;
}

function jeab_email_batteri_letter(): string
{
    return "{hello}\n\n"
        . "Vi kommer hem till dig {nar} och sätter upp ditt batteri.\n\n"
        . "Du behöver inte vara hemma hela tiden. Vi måste däremot komma åt elcentralen och mätarskåpet. Vi kan behöva bryta strömmen. Är du hemma i början går vi igenom var batteriet ska sitta. I slutet sätter vi appen.\n\n"
        . "Inför besöket:\n\n"
        . "Frigör vägen till elcentral, mätarskåp och platsen där batteriet ska sitta.\n\n"
        . "Om kabeln ska i marken: det gula röret (50 mm, med dragtråd) ska redan ligga. Säg till om det inte är gjort, så flyttar vi tiden.\n\n"
        . "Skicka wifi-namn och lösenord på mejl eller SMS.\n\n"
        . "Batteriet levereras ungefär en vecka innan. Du får ett separat meddelande om vilken dag. Du behöver inte vara hemma då, men svara gärna.\n\n"
        . 'Behöver du flytta tiden, säg till senast 72 timmar innan.';
}

function jeab_email_batteri_text(array $row): string
{
    $letter = jeab_email_batteri_letter();
    if (function_exists('jeab_notiser_text')) {
        $letter = jeab_notiser_text('bekraft-batteri-' . jeab_notiser_booking_company($row), $letter);
    }
    return strtr($letter, jeab_email_visit_tokens($row));
}

function jeab_email_sol_subject(array $row): string
{
    $when = jeab_booking_when($row);
    $subject = 'Installation av solceller ' . $when['day'];
    if ($when['clock'] !== '') {
        $subject .= ' ' . $when['clock'];
    }
    return $subject;
}

function jeab_email_sol_letter(): string
{
    return "{hello}\n\n"
        . "Vi kommer hem till dig {nar} och påbörjar installation av solceller med batteri.\n\n"
        . "Du behöver inte vara hemma hela tiden. Installationen kommer ta flera dagar.\n\n"
        . "Inför besöket:\n\n"
        . "Frigör vägen till elcentral, mätarskåp och platsen där batteriet ska stå.\n\n"
        . "Om kabeln ska i marken: det gula röret (50 mm, med dragtråd) ska redan ligga. Säg till om det inte är gjort, så flyttar vi tiden.\n\n"
        . "Skicka wifi-namn och lösenord på mejl eller SMS.\n\n"
        . "Materialet levereras ungefär en vecka innan. Du får ett separat meddelande om vilken dag. Du behöver inte vara hemma då, men försök svara i telefonen.\n\n"
        . 'Behöver du flytta tiden, säg till senast 72 timmar innan.';
}

function jeab_email_sol_text(array $row): string
{
    $letter = jeab_email_sol_letter();
    if (function_exists('jeab_notiser_text')) {
        $letter = jeab_notiser_text('bekraft-sol-' . jeab_notiser_booking_company($row), $letter);
    }
    return strtr($letter, jeab_email_visit_tokens($row));
}

function jeab_meab_confirm_mail_from(): string
{
    // Sam: avsändare är kontakt@malmo.example.test, aldrig contact@.
    return 'kontakt@malmo.example.test';
}

function jeab_meab_confirm_sms_from(): string
{
    return '+46700000003';
}

/** From på bekräftelsemejl för en Malmö-bokning. null lämnar bolagets vanliga From. */
function jeab_booking_confirm_mail_from(array $row): ?string
{
    if (!function_exists('jeab_calendar_mail_company') || jeab_calendar_mail_company($row) !== 'malmo') {
        return null;
    }
    if (!in_array(jeab_booking_job_type($row), ['laddbox', 'batteri', 'sol'], true)) {
        return null;
    }
    return jeab_meab_confirm_mail_from();
}

/** Malmö-solceller får inget SMS. Järfälla-sol skickas som tidigare. */
function jeab_booking_confirm_skips_sms(array $row): bool
{
    return function_exists('jeab_calendar_mail_company')
        && jeab_calendar_mail_company($row) === 'malmo'
        && jeab_booking_job_type($row) === 'sol';
}

/**
 * Påminnelse-SMS bara för batteri, solceller och laddbox.
 * Service, tom produkt och Outlook-möten utan den taggen får inget.
 */
function jeab_booking_sms_allowed(array $row): bool
{
    if (jeab_booking_confirm_skips_sms($row)) {
        return false;
    }
    $jobs = function_exists('jeab_job_types_from_row') ? jeab_job_types_from_row($row) : [];
    foreach (['laddbox', 'batteri', 'sol'] as $job) {
        if (in_array($job, $jobs, true)) {
            return true;
        }
    }
    return false;
}

/** SureSMS-avsändare för Malmö laddbox och batteri. null använder config. */
function jeab_booking_confirm_sms_from(array $row): ?string
{
    if (jeab_booking_confirm_skips_sms($row)) {
        return null;
    }
    if (!function_exists('jeab_calendar_mail_company') || jeab_calendar_mail_company($row) !== 'malmo') {
        return null;
    }
    if (!in_array(jeab_booking_job_type($row), ['laddbox', 'batteri'], true)) {
        return null;
    }
    return jeab_meab_confirm_sms_from();
}

/**
 * SureSMS-query. from läggs bara in när config har nyckeln för bolaget
 * (from för Järfälla, from_malmo för Malmö) och värdet inte är tomt.
 * Saknas nyckeln skickas ingen from, samma som tidigare.
 * $fromOverride vinner och används för Malmö-bekräftelser (+46700000003).
 *
 * @param array<string, mixed> $cfg
 * @return array<string, string>
 */
function jeab_sms_request_query(string $to, string $text, ?string $company, array $cfg, ?string $fromOverride = null): array
{
    $query = [
        'login' => trim((string) ($cfg['login'] ?? 'APIKey')),
        'password' => trim((string) ($cfg['password'] ?? '')),
        'to' => $to,
        'Text' => $text,
    ];
    $override = trim((string) $fromOverride);
    if ($override !== '') {
        $query['from'] = $override;
        return $query;
    }
    $fromKey = jeab_system_mail_company($company) === 'malmo' ? 'from_malmo' : 'from';
    if (array_key_exists($fromKey, $cfg)) {
        $from = trim((string) $cfg[$fromKey]);
        if ($from !== '') {
            $query['from'] = $from;
        }
    }
    return $query;
}

function jeab_sms_send(string $to, string $text, ?string $company = null, ?string $fromOverride = null, array $opts = []): array
{
    if (function_exists('jeab_demo_sent')) {
        $demoSent = jeab_demo_sent('sms', $to);
        if ($demoSent !== null) {
            return $demoSent;
        }
    }
    if (isset($GLOBALS['JEAB_SMS_SEND_WATCH']) && is_callable($GLOBALS['JEAB_SMS_SEND_WATCH'])) {
        ($GLOBALS['JEAB_SMS_SEND_WATCH'])();
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'omklassning skickar inte', 'sent' => 0];
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('sms');
        return ['ok' => false, 'error' => 'SMS är avstängt under sammanslagning och import.'];
    }
    if (function_exists('jeab_test_lead_send_probe')
        && !(function_exists('jeab_test_lead_selftest_active') && jeab_test_lead_selftest_active())) {
        $probe = jeab_test_lead_send_probe('sms', $to, $opts);
        if ($probe['test']) {
            jeab_test_lead_log('sms', (string) $probe['reason'], (string) $probe['email'], (string) $probe['phone']);
            return ['ok' => false, 'error' => 'test lead', 'skipped' => true, 'reason' => 'testlead', 'sent' => 0];
        }
    }
    if (isset($GLOBALS['JEAB_SMS_SEND']) && is_callable($GLOBALS['JEAB_SMS_SEND'])) {
        $mocked = ($GLOBALS['JEAB_SMS_SEND'])($to, $text, $company, $fromOverride);
        return is_array($mocked) ? $mocked : ['ok' => false, 'error' => 'SMS-svaret var fel'];
    }
    $cfg = jeab_suresms_config();
    $password = trim((string) ($cfg['password'] ?? ''));
    if ($password === '') {
        $out = ['ok' => false, 'error' => 'SureSMS-nyckel saknas'];
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('sms', $out['error']);
        }
        return $out;
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $smsJob = ['to' => $to, 'text' => $text, 'company' => $company, 'from' => $fromOverride];
        $booking = is_array($opts['booking'] ?? null) ? $opts['booking'] : [];
        $bookingId = (int) ($booking['id'] ?? $opts['booking_id'] ?? 0);
        $startsAt = (string) ($booking['starts_at'] ?? $opts['starts_at'] ?? '');
        if ($bookingId > 0) {
            $smsJob['booking_id'] = $bookingId;
        }
        if ($startsAt !== '') {
            $smsJob['starts_at'] = $startsAt;
        }
        $gate = jeab_send_guard_gate('sms', $to, $text, [
            'template' => (string) ($opts['guard_template'] ?? 'sms'),
            'mode' => (string) ($opts['guard_mode'] ?? 'auto'),
            'via' => (string) ($opts['guard_via'] ?? ''),
            'actor' => (string) ($opts['guard_actor'] ?? ''),
            'body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
            'handler' => 'sms',
            'job' => $smsJob,
            'booking' => $booking,
        ]);
        if (($gate['action'] ?? '') === 'stop') {
            $stopped = $gate['result'] ?? null;
            return is_array($stopped) ? $stopped : ['ok' => false, 'error' => 'Automatiska utskick är stoppade.'];
        }
    }
    try {
    $query = jeab_sms_request_query($to, $text, $company, $cfg, $fromOverride);
    $url = 'https://api.suresms.com/script/v3/sendsms.aspx?' . http_build_query($query);
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('sms', $url)) {
        $out = ['ok' => true, 'error' => null, 'demo' => true, 'id' => 'demo'];
        return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        $out = ['ok' => false, 'error' => $err !== '' ? $err : 'Ingen kontakt med SureSMS'];
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('sms', $out['error']);
        }
        return $out;
    }
    $body = trim((string) $raw);
    $ok = $code === 200 && stripos($body, 'error') === false && $body !== '';
    $out = ['ok' => $ok, 'error' => $ok ? null : ($body !== '' ? $body : 'HTTP ' . $code)];
    if (!$ok && function_exists('jeab_log_app_error')) {
        jeab_log_app_error('sms', (string) ($out['error'] ?? 'SMS misslyckades'));
    }
    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
    } finally {
        if (function_exists('jeab_send_guard_release')) {
            jeab_send_guard_release($gate);
        }
    }
}

function jeab_helpscout_token(): ?string
{
    $cfg = jeab_helpscout_config();
    $id = trim((string) ($cfg['client_id'] ?? ''));
    $secret = trim((string) ($cfg['client_secret'] ?? ''));
    if ($id === '' || $secret === '') {
        return null;
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', 'oauth')) {
        return null;
    }
    $ch = curl_init('https://api.helpscout.net/v2/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $id,
            'client_secret' => $secret,
        ]),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
    $hsStarted = hrtime(true);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (function_exists('jeab_resend_profile_add')) {
        jeab_resend_profile_add('helpscout', (int) round((hrtime(true) - $hsStarted) / 1e6));
    }
    $data = json_decode((string) $raw, true);
    $token = trim((string) ($data['access_token'] ?? ''));
    return $token !== '' ? $token : null;
}

function jeab_email_helpscout_user_id(string $jobType): ?int
{
    return match ($jobType) {
        'laddbox' => 951809,
        'batteri' => 951810,
        default => null,
    };
}

/**
 * Help Scout mailbox for a company. JEAB uses mailbox_id (default 372708).
 * Malmö uses only an explicit MEAB mailbox. The JEAB mailbox is not reused,
 * because Help Scout would send as kontakt@jarfalla.example.test.
 */
function jeab_helpscout_mailbox_id(?string $company = null): ?int
{
    $cfg = jeab_helpscout_config();
    $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($company) : null;
    if ($company === 'malmo') {
        $candidates = [
            $cfg['mailbox_id_malmo'] ?? null,
            $cfg['meab_mailbox_id'] ?? null,
            $cfg['mailbox_malmo'] ?? null,
        ];
        $boxes = $cfg['mailboxes'] ?? null;
        if (is_array($boxes)) {
            $candidates[] = $boxes['malmo'] ?? $boxes['meab'] ?? null;
        }
        foreach ($candidates as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                return $id;
            }
        }
        return null;
    }
    $id = (int) ($cfg['mailbox_id'] ?? 0);
    $boxes = $cfg['mailboxes'] ?? null;
    if ($id <= 0 && is_array($boxes)) {
        $id = (int) ($boxes['jarfalla'] ?? $boxes['jeab'] ?? 0);
    }
    if ($id <= 0) {
        $id = 372708;
    }
    return $id;
}

/**
 * Skicka mejl via Help Scout Conversations API.
 *
 * @param list<array{fileName?:string,file_name?:string,mimeType?:string,mime_type?:string,data:string}> $attachments
 *        Base64-kodade bilagor (utan data:-prefix).
 * @param list<string> $tags
 */
function jeab_email_send(
    string $to,
    string $text,
    ?string $subject = null,
    ?string $jobType = null,
    array $attachments = [],
    array $tags = ['kalender'],
    bool $logFailure = true,
    ?string $company = null
): array {
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'Omklassning skickar inget mejl.'];
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('mail');
        return ['ok' => false, 'error' => 'Mejl är avstängt under sammanslagning och import.'];
    }
    // Help Scout skrivs inte längre till (Sam 2026-10-14). Systemmejl går via Inkorgen.
    // Undantag: bolag som ligger kvar i Help Scout (Malmö, Sam 2026-10-15) skickar som före #342.
    $hsCompany = function_exists('jeab_inkorg_company_live') && !jeab_inkorg_company_live(
        function_exists('jeab_normalize_company') ? (jeab_normalize_company($company) ?? 'jarfalla') : (string) $company
    );
    if (empty($GLOBALS['JEAB_ALLOW_HELPSCOUT_WRITE']) && !$hsCompany) {
        return ['ok' => false, 'error' => 'Help Scout används inte längre; mejlet går via Inkorgen.'];
    }
    $company = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($company) ?? 'jarfalla')
        : 'jarfalla';
    $mailbox = jeab_helpscout_mailbox_id($company);
    if ($mailbox === null) {
        $out = ['ok' => false, 'error' => 'Help Scout har ingen Malmö-brevlåda för kontakt@malmo.example.test.'];
        if ($logFailure && function_exists('jeab_log_app_error')) {
            jeab_log_app_error('email', $out['error']);
        }
        return $out;
    }
    $token = jeab_helpscout_token();
    if ($token === null) {
        $out = ['ok' => false, 'error' => 'Help Scout-nyckel saknas'];
        if ($logFailure && function_exists('jeab_log_app_error')) {
            jeab_log_app_error('email', $out['error']);
        }
        return $out;
    }
    $thread = [
        'type' => 'reply',
        'text' => $text,
        'customer' => ['email' => $to],
    ];
    $hsAttachments = [];
    foreach ($attachments as $i => $att) {
        if (!is_array($att)) {
            continue;
        }
        $data = trim((string) ($att['data'] ?? ''));
        if ($data === '') {
            continue;
        }
        if (str_contains($data, ',')) {
            $data = substr($data, (int) strrpos($data, ',') + 1);
        }
        $data = preg_replace('/\s+/', '', $data) ?? '';
        if ($data === '' || base64_decode($data, true) === false) {
            continue;
        }
        $fileName = trim((string) ($att['fileName'] ?? $att['file_name'] ?? ''));
        if ($fileName === '') {
            $fileName = 'bilaga-' . ($i + 1) . '.jpg';
        }
        $mime = trim((string) ($att['mimeType'] ?? $att['mime_type'] ?? 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $hsAttachments[] = [
            'fileName' => $fileName,
            'mimeType' => $mime,
            'data' => $data,
        ];
    }
    if ($hsAttachments !== []) {
        $thread['attachments'] = $hsAttachments;
    }
    $cleanTags = [];
    foreach ($tags as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '') {
            $cleanTags[] = $tag;
        }
    }
    if ($cleanTags === []) {
        $cleanTags = ['kalender'];
    }
    $payload = [
        'subject' => ($subject !== null && $subject !== '') ? $subject : 'Bokad tid',
        'type' => 'email',
        'mailboxId' => $mailbox,
        'status' => 'closed',
        'customer' => ['email' => $to],
        'tags' => array_values(array_unique($cleanTags)),
        'threads' => [$thread],
    ];
    $userId = $company !== 'malmo' && $jobType !== null && $jobType !== ''
        ? jeab_email_helpscout_user_id($jobType)
        : null;
    if ($userId !== null) {
        $payload['assignTo'] = $userId;
        $payload['threads'][0]['user'] = $userId;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Kunde inte koda mejlet'];
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $gate = jeab_send_guard_gate('email', $to, $text, [
            'template' => 'email',
            'mode' => 'auto',
            'via' => 'auto',
            'actor' => 'system',
            'handler' => 'email_hs',
            'job' => [
                'to' => $to,
                'text' => $text,
                'subject' => $subject,
                'job_type' => $jobType,
                'attachments' => $attachments,
                'tags' => $tags,
                'log_failure' => $logFailure,
                'company' => $company,
            ],
        ]);
        if (($gate['action'] ?? '') === 'stop') {
            $stopped = $gate['result'] ?? null;
            return is_array($stopped) ? $stopped : ['ok' => false, 'error' => 'Automatiska utskick är stoppade.'];
        }
    }
    try {
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', 'conversations')) {
        return ['ok' => true, 'error' => null, 'id' => 'demo', 'demo' => true];
    }
    $ch = curl_init('https://api.helpscout.net/v2/conversations');
    $hsLocation = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => $hsAttachments !== [] ? 60 : 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$hsLocation): int {
            unset($curl);
            if (stripos($header, 'Location:') === 0) {
                $hsLocation = trim(substr($header, strlen('Location:')));
            }

            return strlen($header);
        },
    ]);
    // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
    $hsStarted = hrtime(true);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (function_exists('jeab_resend_profile_add')) {
        jeab_resend_profile_add('helpscout', (int) round((hrtime(true) - $hsStarted) / 1e6));
    }
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        $out = ['ok' => false, 'error' => $err !== '' ? $err : 'Ingen kontakt med Help Scout'];
        if ($logFailure && function_exists('jeab_log_app_error')) {
            jeab_log_app_error('email', $out['error']);
        }
        return $out;
    }
    $ok = $code === 201;
    $hsId = '';
    if ($ok && preg_match('~/conversations/(\d+)~', $hsLocation, $hsMatch) === 1) {
        $hsId = $hsMatch[1];
    }
    $out = ['ok' => $ok, 'error' => $ok ? null : ('HTTP ' . $code), 'id' => $hsId];
    if (!$ok && $logFailure && function_exists('jeab_log_app_error')) {
        $detail = is_string($raw) ? trim(substr($raw, 0, 300)) : '';
        jeab_log_app_error('email', (string) ($out['error'] ?? 'Mejl misslyckades') . ($detail !== '' ? ': ' . $detail : ''));
    }
    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
    } finally {
        if (function_exists('jeab_send_guard_release')) {
            jeab_send_guard_release($gate);
        }
    }
}

/**
 * Svar i samma Help Scout-samtal som bekräftelsen. Nytt samtal skapas inte.
 *
 * @return array{ok: bool, error: ?string, id: string}
 */
function jeab_email_reply_conversation(string $conversationId, string $to, string $text, ?string $company = null): array
{
    $conversationId = trim($conversationId);
    if (!ctype_digit($conversationId) || $to === '' || trim($text) === '') {
        return ['ok' => false, 'error' => 'Help Scout-samtalet saknas', 'id' => ''];
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'Omklassning skickar inget mejl.', 'id' => ''];
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('mail');
        return ['ok' => false, 'error' => 'Mejl är avstängt under sammanslagning och import.', 'id' => ''];
    }
    $company = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($company) ?? 'jarfalla')
        : 'jarfalla';
    $hsCompany = function_exists('jeab_inkorg_company_live') && !jeab_inkorg_company_live($company);
    if (empty($GLOBALS['JEAB_ALLOW_HELPSCOUT_WRITE']) && !$hsCompany) {
        return ['ok' => false, 'error' => 'Help Scout används inte längre; mejlet går via Inkorgen.', 'id' => ''];
    }
    $token = jeab_helpscout_token();
    if ($token === null) {
        return ['ok' => false, 'error' => 'Help Scout-nyckel saknas', 'id' => ''];
    }
    $payload = [
        'customer' => ['email' => $to],
        'text' => $text,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Kunde inte koda mejlet', 'id' => ''];
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', 'reply')) {
        return ['ok' => true, 'error' => null, 'id' => 'demo', 'demo' => true];
    }
    $ch = curl_init('https://api.helpscout.net/v2/conversations/' . rawurlencode($conversationId) . '/reply');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'Ingen kontakt med Help Scout', 'id' => ''];
    }
    $ok = $code === 201;

    return ['ok' => $ok, 'error' => $ok ? null : ('HTTP ' . $code), 'id' => $ok ? $conversationId : ''];
}

/**
 * Bolag för kundbekräftelse från kalender-tick.
 * Samma ordning som jeab_create_booking: sparad company, annars
 * jeab_infer_company_from_names på tilldelade utan bolagsfilter.
 * Saknas båda blir det jarfalla. Adress och ort används inte.
 */
function jeab_calendar_mail_company(array $row): string
{
    if (function_exists('jeab_normalize_company')) {
        $explicit = jeab_normalize_company($row['company'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
    }
    $names = [];
    if (function_exists('jeab_parse_assignees')) {
        if (array_key_exists('assignees', $row) && $row['assignees'] !== null && $row['assignees'] !== '') {
            $names = jeab_parse_assignees($row['assignees']);
        }
        if ($names === [] && array_key_exists('assignee', $row)) {
            $names = jeab_parse_assignees($row['assignee'] ?? '');
        }
    }
    if ($names !== [] && function_exists('jeab_infer_company_from_names')) {
        $inferred = jeab_infer_company_from_names($names);
        if (is_string($inferred) && $inferred !== '') {
            return $inferred;
        }
    }
    return 'jarfalla';
}

function jeab_sms_tick_absorb_mail(array $result, int $id, int &$sentMail, int &$skipped, array &$errors): void
{
    if (!empty($result['sent'])) {
        $sentMail++;
        return;
    }
    if (!empty($result['error'])) {
        $errors[] = ['id' => $id, 'channel' => 'email', 'error' => (string) $result['error']];
        return;
    }
    if (!empty($result['skipped']) && ($result['reason'] ?? '') === 'no-email') {
        $skipped++;
    }
}

function jeab_sms_tick(JeabStore $store): array
{
    if (function_exists('jeab_data_maintenance') && jeab_data_maintenance() && empty($GLOBALS['_JEAB_DATA_MIGRATE'])) {
        return [
            'ok' => true,
            'paused' => 1,
            'maintenance' => 1,
            'sent' => 0,
            'sent_email' => 0,
            'skipped' => 0,
            'checked' => 0,
            'errors' => [],
            'outlook' => ['ok' => true, 'pulled' => 0, 'updated' => 0, 'freed' => 0, 'removed' => 0],
        ];
    }
    if (function_exists('jeab_job_overlap_acquire') && !jeab_job_overlap_acquire()) {
        return [
            'ok' => true,
            'sent' => 0,
            'sent_email' => 0,
            'skipped' => 0,
            'checked' => 0,
            'errors' => [],
            'outlook' => ['ok' => true, 'pulled' => 0, 'updated' => 0, 'freed' => 0, 'removed' => 0],
            'busy' => 1,
        ];
    }
    try {
        return jeab_sms_tick_locked($store);
    } finally {
        if (function_exists('jeab_job_overlap_release')) {
            jeab_job_overlap_release();
        }
    }
}

function jeab_sms_tick_locked(JeabStore $store): array
{
    $slot = function_exists('jeab_db_slot_acquire') ? jeab_db_slot_acquire(2) : null;
    if ($slot === null && function_exists('jeab_db_slot_acquire')) {
        $wait = (int) ($GLOBALS['_JEAB_PDO_RETRY_US'] ?? 150000);
        if ($wait > 0) {
            usleep($wait);
        }
        $slot = jeab_db_slot_acquire(2);
    }
    if ($slot === null && function_exists('jeab_db_slot_acquire')) {
        return [
            'ok' => true,
            'sent' => 0,
            'sent_email' => 0,
            'skipped' => 0,
            'checked' => 0,
            'errors' => [],
            'outlook' => ['ok' => true, 'pulled' => 0, 'updated' => 0, 'freed' => 0, 'removed' => 0],
            'busy' => 1,
        ];
    }
    try {
        return jeab_sms_tick_run($store);
    } finally {
        if (function_exists('jeab_db_slot_release')) {
            jeab_db_slot_release($slot);
        }
    }
}

function jeab_sms_tick_run(JeabStore $store): array
{
    if (function_exists('jeab_cutover_sends_paused') && jeab_cutover_sends_paused()) {
        return [
            'ok' => true,
            'paused' => 1,
            'sent' => 0,
            'sent_email' => 0,
            'skipped' => 0,
            'checked' => 0,
            'errors' => [],
        ];
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $now = new DateTimeImmutable('now', $tz);
    $nowSql = $now->format('Y-m-d H:i:s');
    // Webbhotellets cron anropar sms-tick varannan timme. Ingen ny cron.
    // SMS är påminnelsen «kommer imorgon»: start i (now, now+18h].
    // Bara batteri, solceller och laddbox. Service och möten utan den taggen hoppas över.
    // starts_at <= now hoppas över. Det är inte en bekräftelse vid bokningen.
    // Bekräftelsemejl går en gång när bokningen varit orörd minst två timmar
    // (senast ändrad = updated_at). En ändring innan mejlet gått flyttar
    // updated_at och startar om klockan. Inte same-minute, och inte ett
    // fönster för «skapad/ändrad inom senaste 4 timmarna».
    // Starten måste ligga klart före nu för att mejlet ska hoppas över.
    // Har bekräftelsen redan gått och tiden ändrats ligger en väntande flyttpost
    // (BOOKING_MOVE_MAIL, av som standard). Den här sms-cron-körningen skickar
    // sluttiden. Vitlistade testbokningar tas även när flaggan är av.
    // Ingen extra väntan, och inte 5-minuters-tickern.
    $smsTo = $now->modify('+18 hours')->format('Y-m-d H:i:s');
    $dueSms = jeab_sms_reminder_select($store, $nowSql, $smsTo);
    $dueMail = [];
    foreach ($store->bookingsUpdatedBetween(jeab_booking_mail_due_from(), jeab_booking_mail_due_until($nowSql)) as $row) {
        if (jeab_booking_mail_start_clearly_past($row, $nowSql)) {
            continue;
        }
        if (!jeab_booking_mail_age_ready($row, $nowSql)) {
            continue;
        }
        $dueMail[] = $row;
    }

    $sentSms = 0;
    $sentMail = 0;
    $skipped = 0;
    $errors = [];

    foreach ($dueSms as $row) {
        $id = (int) $row['id'];
        $starts = (string) $row['starts_at'];
        $done = jeab_sms_sent_row($id, $starts);
        if (jeab_sms_starts_at_passed($row, $nowSql)) {
            $skipped++;
            continue;
        }
        $shown = jeab_sms_shown_booking($row);
        if (function_exists('jeab_contacts_strict_block')) {
            $block = jeab_contacts_strict_block($row, 'sms');
            if ($block !== '') {
                $errors[] = ['id' => $id, 'channel' => 'sms', 'error' => $block];
                $skipped++;
                continue;
            }
        }
        $phone = trim((string) ($shown['phone'] ?? ''));
        if (!empty($done['sms']) || $phone === '') {
            continue;
        }
        $toNumber = function_exists('jeab_norm_phone') ? jeab_norm_phone($phone) : '';
        if ($toNumber === '' || !str_starts_with($toNumber, '+')) {
            continue;
        }
        if (!jeab_booking_sms_allowed($shown)) {
            continue;
        }
        if (function_exists('jeab_test_lead_strict')) {
            $testBooking = jeab_test_lead_strict([
                'email' => (string) ($shown['email'] ?? ''),
                'is_test' => !empty($shown['is_test']),
                'flag_reason' => (string) ($shown['flag_reason'] ?? ''),
            ]);
            // Testspärren gäller om radens kopia ELLER kontakten är test.
            if (!$testBooking['test']) {
                $testRaw = jeab_test_lead_strict([
                    'email' => (string) ($row['email'] ?? ''),
                    'is_test' => !empty($row['is_test']),
                    'flag_reason' => (string) ($row['flag_reason'] ?? ''),
                ]);
                if ($testRaw['test']) {
                    $testBooking = $testRaw;
                }
            }
            if ($testBooking['test']) {
                jeab_test_lead_log('sms', (string) $testBooking['reason'], (string) $testBooking['email'], (string) ($testBooking['phone'] !== '' ? $testBooking['phone'] : $phone));
                $skipped++;
                continue;
            }
        }
        $smsClaim = jeab_sms_channel_claim($id, $starts, 'sms');
        if ($smsClaim === 'held' || $smsClaim === 'sent') {
            continue;
        }
        if ($smsClaim === 'unstored') {
            $errors[] = ['id' => $id, 'channel' => 'sms', 'error' => 'sms_sent-anspråk'];
            continue;
        }
        $noticeId = function_exists('jeab_notiser_booking_notice_id') ? jeab_notiser_booking_notice_id($row, 'sms') : '';
        $sendSms = static function () use ($toNumber, $shown, $phone): array {
            return jeab_sms_send(
                $toNumber,
                jeab_sms_text($shown),
                jeab_calendar_mail_company($shown),
                jeab_booking_confirm_sms_from($shown),
                [
                    'guard_template' => 'booking_notice',
                    'guard_mode' => 'auto',
                    'guard_via' => 'auto',
                    'guard_actor' => 'system',
                    'email' => (string) ($shown['email'] ?? ''),
                    'name' => (string) ($shown['customer_name'] ?? ''),
                    'phone' => $phone,
                    'booking' => $shown,
                ]
            );
        };
        $result = function_exists('jeab_notiser_send') && $noticeId !== ''
            ? jeab_notiser_send($noticeId, $sendSms)
            : $sendSms();
        if (!empty($result['skipped']) && ($result['reason'] ?? '') === 'notis-av') {
            jeab_sms_channel_release($id, 'sms', $starts);
            continue;
        }
        if (!empty($result['queued'])) {
            continue;
        }
        if (jeab_sms_provider_delivered($result)) {
            $done['sms'] = true;
            $done['sms_at'] = jeab_now();
            $done['starts_at'] = $starts;
            jeab_sms_record_sent($id, $done, $row, 'sms');
            $sentSms++;
        } else {
            jeab_sms_channel_release($id, 'sms', $starts);
            $errors[] = ['id' => $id, 'channel' => 'sms', 'error' => (string) ($result['error'] ?? 'fel')];
        }
    }

    foreach ($dueMail as $row) {
        if (jeab_sms_email_already_sent((int) $row['id'])) {
            continue;
        }
        $shownMail = jeab_sms_shown_booking($row);
        if (function_exists('jeab_test_lead_strict')) {
            $testBooking = jeab_test_lead_strict([
                'email' => (string) ($shownMail['email'] ?? ''),
                'is_test' => !empty($shownMail['is_test']),
                'flag_reason' => (string) ($shownMail['flag_reason'] ?? ''),
            ]);
            // Testspärren gäller om radens kopia ELLER kontakten är test.
            if (!$testBooking['test']) {
                $testRaw = jeab_test_lead_strict([
                    'email' => (string) ($row['email'] ?? ''),
                    'is_test' => !empty($row['is_test']),
                    'flag_reason' => (string) ($row['flag_reason'] ?? ''),
                ]);
                if ($testRaw['test']) {
                    $testBooking = $testRaw;
                }
            }
            if ($testBooking['test']) {
                jeab_test_lead_log('kvitto', (string) $testBooking['reason'], (string) $testBooking['email'], (string) $testBooking['phone']);
                $skipped++;
                continue;
            }
        }
        jeab_sms_tick_absorb_mail(jeab_booking_send_customer_mail($row), (int) $row['id'], $sentMail, $skipped, $errors);
    }

    if (jeab_booking_move_mail_enabled() || jeab_booking_move_mail_test_ids() !== []) {
        try {
            $move = jeab_booking_move_mail_drain($store);
            $sentMail += (int) ($move['sent'] ?? 0);
            foreach ($move['errors'] as $err) {
                $errors[] = $err;
            }
        } catch (Throwable) {
        }
    }

    if (function_exists('send_guard_drain')) {
        try {
            send_guard_drain(['exclude_booking' => false]);
        } catch (Throwable) {
        }
    }

    if (function_exists('jeab_malmo_hs_background')) {
        jeab_malmo_hs_background();
    }
    if (function_exists('jeab_external_tick')) {
        try {
            jeab_external_tick($store);
        } catch (Throwable) {
        }
    }
    if (function_exists('jeab_inkorg_mail_poll')) {
        try {
            jeab_inkorg_mail_poll();
        } catch (Throwable) {
        }
    }

    $outlook = ['ok' => true, 'pulled' => 0, 'updated' => 0, 'freed' => 0, 'removed' => 0];
    if (function_exists('jeab_outlook_pull')) {
        try {
            $outlook = jeab_outlook_pull($store);
        } catch (Throwable) {
            $outlook = ['ok' => false, 'pulled' => 0, 'updated' => 0, 'freed' => 0, 'removed' => 0];
        }
    }

    return [
        'ok' => $errors === [],
        'sent' => $sentSms,
        'sent_email' => $sentMail,
        'skipped' => $skipped,
        'checked' => count($dueSms) + count($dueMail),
        'errors' => $errors,
        'outlook' => $outlook,
    ];
}

/**
 * Samma urval som påminnelse-SMS i jeab_sms_tick_run: bokade rader som
 * överlappar [from, to). Ingen mejlkö och inget utskick.
 *
 * @return list<array<string, mixed>>
 */
function jeab_sms_reminder_select(JeabStore $store, string $from, string $to): array
{
    return $store->bookingsBetween($from, $to);
}

function jeab_sms_reminder_preview_max_days(): int
{
    return 21;
}

function jeab_sms_reminder_preview_day(string $value): ?DateTimeImmutable
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
    if (!$day instanceof DateTimeImmutable || $day->format('Y-m-d') !== $value) {
        return null;
    }
    return $day;
}

/**
 * Gata utan krav på husnummer. Namn, telefon och personnummer följer inte med.
 */
function jeab_sms_reminder_preview_street(array $row): string
{
    $address = isset($row['address']) ? (string) $row['address'] : '';
    if (function_exists('jeab_booking_street')) {
        return jeab_booking_street($address);
    }
    $address = trim($address);
    if ($address === '') {
        return '';
    }
    if (str_contains($address, ',')) {
        return trim((string) explode(',', $address, 2)[0]);
    }
    return $address;
}

/**
 * sms kommer från jeab_booking_sms_allowed, som redan tar med
 * jeab_booking_confirm_skips_sms. Anledningarna är de korta skälen.
 *
 * @return array{sms: bool, reason: string, job_types: list<string>}
 */
function jeab_sms_reminder_preview_decision(array $row): array
{
    $jobs = function_exists('jeab_job_types_from_row') ? jeab_job_types_from_row($row) : [];
    if (jeab_booking_confirm_skips_sms($row)) {
        return ['sms' => false, 'reason' => 'malmo sol', 'job_types' => $jobs];
    }
    if (jeab_booking_sms_allowed($row)) {
        foreach (['laddbox', 'batteri', 'sol'] as $job) {
            if (in_array($job, $jobs, true)) {
                return ['sms' => true, 'reason' => 'ok ' . $job, 'job_types' => $jobs];
            }
        }
    }
    if (in_array('service', $jobs, true)) {
        return ['sms' => false, 'reason' => 'service', 'job_types' => $jobs];
    }
    return ['sms' => false, 'reason' => 'ingen produkt', 'job_types' => $jobs];
}

/**
 * En rad till förhandsvisningen. Bara id, datum, gata, produkter, bolag, sms och anledning.
 *
 * @return array{id: int, date: string, street: string, job_types: list<string>, company: string, sms: bool, reason: string}
 */
function jeab_sms_reminder_preview_row(array $row): array
{
    $decision = jeab_sms_reminder_preview_decision($row);
    $company = function_exists('jeab_calendar_mail_company') ? jeab_calendar_mail_company($row) : 'jarfalla';
    if ($company !== 'malmo' && $company !== 'jarfalla') {
        $company = 'jarfalla';
    }
    $starts = trim((string) ($row['starts_at'] ?? ''));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $starts) === 1 ? substr($starts, 0, 10) : '';
    return [
        'id' => (int) ($row['id'] ?? 0),
        'date' => $date,
        'street' => jeab_sms_reminder_preview_street($row),
        'job_types' => $decision['job_types'],
        'company' => $company,
        'sms' => $decision['sms'],
        'reason' => $decision['reason'],
    ];
}

/**
 * Hook sms.reminder_preview. dry tvingas på oavsett indata.
 * Läser samma bokningsurval som påminnelse-SMS, över ett datumspann.
 * Anropar inte jeab_sms_send, skriver inte sms_sent, ingen tabell och ingen JSON-fil.
 *
 * @return array<string, mixed>
 */
function jeab_sms_reminder_preview_dispatch(JeabStore $store, array $input): array
{
    $input['dry'] = 1;
    $fail = static function (string $error, int $status = 400): array {
        return [
            'ok' => false,
            'error' => $error,
            'dry' => 1,
            'read_only' => true,
            'status' => $status,
        ];
    };
    $from = jeab_sms_reminder_preview_day(trim((string) ($input['from'] ?? '')));
    $to = jeab_sms_reminder_preview_day(trim((string) ($input['to'] ?? '')));
    if ($from === null || $to === null) {
        return $fail('Ange from och to som YYYY-MM-DD.');
    }
    if ($from > $to) {
        return $fail('from är efter to.');
    }
    $days = (int) $from->diff($to)->days + 1;
    if ($days > jeab_sms_reminder_preview_max_days()) {
        return $fail('Högst 21 dagar.');
    }
    $fromSql = $from->format('Y-m-d') . ' 00:00:00';
    $toSql = $to->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    try {
        $rows = jeab_sms_reminder_select($store, $fromSql, $toSql);
    } catch (Throwable) {
        return $fail('Bokningarna gick inte att läsa.');
    }
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = jeab_sms_reminder_preview_row($row);
    }
    return [
        'ok' => true,
        'dry' => 1,
        'read_only' => true,
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'days' => $days,
        'count' => count($items),
        'bookings' => $items,
    ];
}
