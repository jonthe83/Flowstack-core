<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Lead utan kundens mejladress (Sam 21/10).
 * Ingen automatisk offert. Påminnelse till samma säljare som offerten,
 * och högst ett SMS per lead. Nyckeln ligger i SQL, inte i JSON.
 */

function jeab_no_email_reminder_text(): string
{
    return 'ring kunden och ta mejladressen';
}

function jeab_no_email_sms_body(string $who): string
{
    $base = 'Får jag din mejladress så skickar jag offert';
    $who = trim($who);
    return $who === '' ? $base : $base . ' ' . $who;
}

/** Roberto batteri, Elin elbilsladdare, Sam L sol, Kim i Malmö. */
function jeab_no_email_owner(string $offer, string $company): string
{
    if ($offer === 'sol+batteri' || (function_exists('jeab_auto_offer_canonical') && jeab_auto_offer_canonical($offer) === 'sol+batteri')) {
        $offer = 'sol';
    }
    if (function_exists('jeab_user_responsibility_for')) {
        $region = $company === 'malmo' ? 'skane' : 'stockholm';
        $pid = in_array($offer, ['batteri', 'laddbox', 'sol'], true) ? jeab_user_responsibility_for($offer, $region, 'owner') : '';
        if ($pid === '') {
            // Ingen regel (t.ex. sol i Skåne): samma reserv som förut.
            $pid = $company === 'malmo' ? jeab_user_responsibility_for('batteri', 'skane', 'owner') : jeab_user_responsibility_for('sol', 'stockholm', 'owner');
        }
        if ($pid !== '') {
            return jeab_user_responsibility_name($pid);
        }
    }
    if ($company === 'malmo') {
        return 'Kim';
    }
    return match ($offer) {
        'batteri' => 'Roberto',
        'laddbox' => 'Elin',
        'sol' => 'Sam L',
        default => 'Sam L',
    };
}

function jeab_no_email_offer(array $thread, array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $offer = (string) ($labels['offer'] ?? '');
    $exclusive = function_exists('jeab_lead_grok_product_exclusive') ? jeab_lead_grok_product_exclusive($labels) : '';
    if ($exclusive !== '') {
        $offer = $exclusive;
    }
    if ($offer === '' && function_exists('jeab_lead_infer_offer')) {
        $offer = jeab_lead_infer_offer($msg, (array) ($thread['tags'] ?? []));
    }
    if (function_exists('jeab_inkorg_form_quote_offer')) {
        $form = jeab_inkorg_form_quote_offer($msg);
        if (function_exists('jeab_auto_offer_is') && jeab_auto_offer_is($form)) {
            $offer = $form;
        }
    }
    if ($offer === 'sol+batteri' || (function_exists('jeab_auto_offer_canonical') && jeab_auto_offer_canonical($offer) === 'sol+batteri')) {
        return 'sol';
    }
    if (!in_array($offer, ['laddbox', 'batteri', 'sol'], true) && function_exists('jeab_inkorg_product_token')) {
        $token = jeab_inkorg_product_token($offer);
        if (in_array($token, ['laddbox', 'batteri', 'sol'], true)) {
            $offer = $token;
        }
    }
    return in_array($offer, ['laddbox', 'batteri', 'sol'], true) ? $offer : '';
}

function jeab_no_email_company(array $thread): string
{
    $company = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : 'jarfalla';
    return $company === 'malmo' ? 'malmo' : 'jarfalla';
}

function jeab_no_email_suppressed(): bool
{
    return !empty($GLOBALS['_JEAB_AUTO_SELFTEST']) || trim((string) ($GLOBALS['_JEAB_AUTO_DELIVER_TO'] ?? '')) !== '';
}

function jeab_no_email_blocked(array $thread, array $msg): bool
{
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        return true;
    }
    if (function_exists('jeab_hs_import_frozen') && (jeab_hs_import_frozen($msg) || jeab_hs_import_frozen($thread))) {
        return true;
    }
    if (function_exists('jeab_hs_import_no_auto') && (jeab_hs_import_no_auto($msg) || jeab_hs_import_no_auto($thread))) {
        return true;
    }
    return false;
}

/** Lead där mottagaren inte är kundens mejl. Säljarens domän räknas inte: där skickas inget. */
function jeab_no_email_missing(array $thread, array $msg): bool
{
    if (!empty($thread['deleted']) || !function_exists('jeab_lead_is_lead') || !jeab_lead_is_lead($thread, $msg)) {
        return false;
    }
    if (function_exists('jeab_lead_send_recipient')) {
        $resolved = jeab_lead_send_recipient($msg, $thread);
        $resolvedEmail = trim((string) ($resolved['email'] ?? ''));
        if ($resolvedEmail !== '' && function_exists('jeab_lead_bought_partner') && jeab_lead_bought_partner($resolvedEmail)) {
            return true;
        }
        if ($resolvedEmail !== '') {
            return false;
        }
        if (str_contains((string) ($resolved['why'] ?? ''), 'kundadress')) {
            return true;
        }
    }
    if (!function_exists('jeab_lead_customer')) {
        return false;
    }
    $cust = jeab_lead_customer($msg);
    return (string) ($cust['platform'] ?? '') !== '' && trim((string) ($cust['email'] ?? '')) === '';
}

function jeab_no_email_phone_raw(array $thread, array $msg): string
{
    $phone = '';
    if (function_exists('jeab_lead_customer')) {
        $phone = trim((string) (jeab_lead_customer($msg)['phone'] ?? ''));
    }
    if ($phone === '' && function_exists('jeab_lead_send_recipient')) {
        $phone = trim((string) (jeab_lead_send_recipient($msg, $thread)['phone'] ?? ''));
    }
    if ($phone === '' && function_exists('jeab_inkorg_find_mobile')) {
        $blob = (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
        $phone = trim(jeab_inkorg_find_mobile($blob));
    }
    return $phone;
}

/** Svenskt mobilnummer, eller tomt. Landnummer och ogiltiga nummer blir ingen mobil. */
function jeab_no_email_mobile(string $raw): string
{
    if (trim($raw) === '' || !function_exists('jeab_normalize_reminder_phone')) {
        return '';
    }
    try {
        return (string) (jeab_normalize_reminder_phone($raw) ?? '');
    } catch (InvalidArgumentException) {
        return '';
    }
}

function jeab_no_email_ensure(PDO $pdo): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if (function_exists('jeab_driver') && jeab_driver() === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_no_email_sms (
                thread_id VARCHAR(191) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_no_email_sms (
            thread_id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL
        )'
    );
}

function jeab_no_email_pdo(): ?PDO
{
    if (!function_exists('jeab_lead_sql')) {
        return null;
    }
    $pdo = jeab_lead_sql();
    if (!$pdo instanceof PDO) {
        return null;
    }
    try {
        jeab_no_email_ensure($pdo);
    } catch (Throwable) {
        return null;
    }
    return $pdo;
}

function jeab_no_email_sms_seen(string $tid): bool
{
    $tid = mb_substr(trim($tid), 0, 191);
    $pdo = jeab_no_email_pdo();
    if ($tid === '' || !$pdo instanceof PDO) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM inbox_no_email_sms WHERE thread_id = ?');
    $stmt->execute([$tid]);
    return (bool) $stmt->fetchColumn();
}

function jeab_no_email_sms_reserve(string $tid): bool
{
    $tid = mb_substr(trim($tid), 0, 191);
    $pdo = jeab_no_email_pdo();
    if ($tid === '' || !$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->prepare('INSERT INTO inbox_no_email_sms (thread_id, created_at) VALUES (?, ?)')->execute([
            $tid,
            date('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function jeab_no_email_sms_release(string $tid): void
{
    $tid = mb_substr(trim($tid), 0, 191);
    $pdo = jeab_no_email_pdo();
    if ($tid === '' || !$pdo instanceof PDO) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM inbox_no_email_sms WHERE thread_id = ?')->execute([$tid]);
    } catch (Throwable) {
    }
}

/**
 * @return array{thread_id:string,owner:string,company:string,offer:string,quote:bool,reminder:string,reminder_id:int,sms:string}
 */
function jeab_no_email_row(string $tid, string $owner, string $company, string $offer, int $reminderId, string $sms): array
{
    return [
        'thread_id' => $tid,
        'owner' => $owner,
        'company' => $company,
        'offer' => $offer,
        'quote' => false,
        'reminder' => jeab_no_email_reminder_text(),
        'reminder_id' => $reminderId,
        'sms' => $sms,
    ];
}

function jeab_no_email_customer_name(array $msg): string
{
    if (!function_exists('jeab_lead_customer')) {
        return 'Kund';
    }
    $name = trim((string) (jeab_lead_customer($msg)['name'] ?? ''));
    if ($name === '' || (function_exists('jeab_auto_company_name') && jeab_auto_company_name($name)) || (function_exists('jeab_auto_unresolved_name') && jeab_auto_unresolved_name($name))) {
        return 'Kund';
    }
    return $name;
}

/**
 * @return array{thread_id:string,owner:string,company:string,offer:string,quote:bool,reminder:string,reminder_id:int,sms:string}
 */
function jeab_no_email_apply(array $thread, array $msg, bool $dry): array
{
    $tid = trim((string) ($thread['id'] ?? ''));
    $company = jeab_no_email_company($thread);
    $offer = jeab_no_email_offer($thread, $msg);
    $owner = jeab_no_email_owner($offer, $company);
    $mobile = jeab_no_email_mobile(jeab_no_email_phone_raw($thread, $msg));
    $seen = $tid !== '' && jeab_no_email_sms_seen($tid);
    if ($mobile === '') {
        $sms = 'ingen mobil';
    } elseif ($dry) {
        $sms = $seen ? 'redan skickat' : 'skulle skickas';
    } else {
        $sms = $seen ? 'redan skickat' : 'skulle skickas';
    }
    $existing = ($tid !== '' && function_exists('jeab_lead_find_reminder')) ? jeab_lead_find_reminder($tid, '') : null;
    $reminderId = is_array($existing) ? (int) ($existing['id'] ?? 0) : 0;
    if ($dry || jeab_no_email_blocked($thread, $msg)) {
        if (jeab_no_email_blocked($thread, $msg)) {
            $sms = 'stoppad';
        }
        return jeab_no_email_row($tid, $owner, $company, $offer, $reminderId, $sms);
    }
    if ($reminderId < 1 && $tid !== '' && function_exists('jeab_create_reminder') && function_exists('jeab_store')) {
        $label = ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solceller'][$offer] ?? 'Lead';
        $note = jeab_no_email_reminder_text();
        if (function_exists('jeab_lead_mail_url')) {
            $note .= "\nmail: " . jeab_lead_mail_url($tid);
        }
        $storedPhone = $mobile;
        try {
            $created = jeab_create_reminder(jeab_store(), jeab_no_email_customer_name($msg) . ' – ' . $label, function_exists('jeab_today_start') ? jeab_today_start() : null, null, null, [
                'customer' => jeab_no_email_customer_name($msg),
                'email' => '',
                'phone' => $storedPhone,
                'company' => $company,
                'who' => $owner,
                'note' => $note,
                'thread_id' => $tid,
            ]);
            if (is_array($created)) {
                $reminderId = (int) ($created['id'] ?? 0);
            }
        } catch (Throwable) {
            $reminderId = 0;
        }
    } elseif ($reminderId > 0 && is_array($existing) && function_exists('jeab_lead_reminder_append') && !str_contains((string) ($existing['note'] ?? ''), jeab_no_email_reminder_text())) {
        $updated = jeab_lead_reminder_append($existing, $tid, jeab_no_email_reminder_text());
        if (is_array($updated)) {
            $reminderId = (int) ($updated['id'] ?? $reminderId);
        }
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return jeab_no_email_row($tid, $owner, $company, $offer, $reminderId, 'stoppad');
    }
    $testMatch = function_exists('jeab_test_lead_fields') ? jeab_test_lead_fields($msg, $thread) : ['test' => false];
    $strictSms = function_exists('jeab_test_lead_strict') ? jeab_test_lead_strict([
        'email' => (string) ($testMatch['email'] ?? ''),
        'is_test' => !empty($msg['is_test']) || (string) ($testMatch['reason'] ?? '') === 'grok' || (string) ($testMatch['reason'] ?? '') === 'is_test',
        'flag_reason' => (string) ($testMatch['reason'] ?? ''),
    ]) : ['test' => false];
    if (!empty($strictSms['test']) && $mobile !== '' && $sms !== 'redan skickat' && !jeab_test_lead_selftest_active()) {
        jeab_test_lead_log('sms', (string) ($strictSms['reason'] ?? 'test'), (string) ($strictSms['email'] ?? ''), (string) (($strictSms['phone'] ?? '') !== '' ? $strictSms['phone'] : $mobile));
        $sms = 'testlead';
    } elseif ($mobile !== '' && $sms !== 'redan skickat') {
        if (!jeab_no_email_sms_reserve($tid)) {
            $sms = jeab_no_email_sms_seen($tid) ? 'redan skickat' : 'stoppad';
        } else {
            $sent = ['ok' => false];
            try {
                if (function_exists('jeab_sms_send')) {
                    $sent = jeab_sms_send($mobile, jeab_no_email_sms_body($owner), $company, null, [
                        'guard_template' => 'no_email',
                        'guard_mode' => 'auto',
                        'guard_via' => 'auto',
                        'guard_actor' => 'system',
                        'email' => (string) ($testMatch['email'] ?? ''),
                        'name' => jeab_no_email_customer_name($msg),
                        'phone' => $mobile,
                        'lead' => is_array($testMatch) ? $testMatch : [],
                    ]);
                }
            } catch (Throwable) {
                $sent = ['ok' => false];
            }
            if (empty($sent['ok'])) {
                jeab_no_email_sms_release($tid);
                $sms = 'stoppad';
            } else {
                $sms = !empty($sent['queued']) ? 'köad' : 'skickat';
            }
        }
    }
    return jeab_no_email_row($tid, $owner, $company, $offer, $reminderId, $sms);
}

/** Körs från automatiken. Självtest och importerade ärenden hoppas över. */
function jeab_no_email_maybe(array $thread, array $msg, bool $dry = false): ?array
{
    if (jeab_no_email_suppressed() || jeab_no_email_blocked($thread, $msg) || !jeab_no_email_missing($thread, $msg)) {
        return null;
    }
    return jeab_no_email_apply($thread, $msg, $dry);
}

/**
 * inbox.no_email_scan. dry=1 är standard och listar bara vad som skulle hända.
 * dry=0 kräver confirm=SAM och skapar påminnelse och SMS.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_no_email_scan(array $input): array
{
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : true;
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm', 'dry' => 0, 'would_send' => false, 'leads' => []];
    }
    $leads = [];
    foreach (function_exists('jeab_inkorg_threads') ? jeab_inkorg_threads() : [] as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $msg = (array) ($thread['first'] ?? []);
        if ($msg === [] && isset($thread['messages'][0]) && is_array($thread['messages'][0])) {
            $msg = $thread['messages'][0];
        }
        if (jeab_no_email_blocked($thread, $msg) || !jeab_no_email_missing($thread, $msg)) {
            continue;
        }
        $leads[] = jeab_no_email_apply($thread, $msg, $dry);
    }
    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'would_send' => false,
        'count' => count($leads),
        'leads' => $leads,
    ];
}
