<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Svar efter första kontakten (Järfälla och Malmö-ärenden som redan är i pipelinen).
 * Ingen ny automatisk offert. Stängd påminnelse öppnas hos samma ansvarig.
 * Köp, fråga eller kontaktbegäran får en kvittens från den personens brevlåda.
 */

function jeab_follow_receipt_default(): string
{
    return 'Tack för ditt svar! Vi hörs på telefon de närmaste dagarna. Slå gärna en signal när du ser detta.';
}

function jeab_follow_receipt_enabled(): bool
{
    $set = function_exists('jeab_auto_settings') ? jeab_auto_settings() : [];
    return !empty($set['follow_receipt_enabled']);
}

function jeab_follow_receipt_text(): string
{
    $d = function_exists('jeab_inkorg_json_file') ? jeab_inkorg_json_file('settings.json') : null;
    $text = trim((string) (is_array($d) ? ($d['follow_receipt'] ?? '') : ''));
    return $text !== '' ? $text : jeab_follow_receipt_default();
}

/**
 * @param array<string, mixed> $thread
 */
function jeab_follow_in_pipeline(array $thread): bool
{
    $company = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : 'jarfalla';
    if ($company === 'jarfalla' && (!function_exists('jeab_inkorg_company_live') || jeab_inkorg_company_live('jarfalla'))) {
        return true;
    }
    $tid = (string) ($thread['id'] ?? '');
    return $company === 'malmo' && function_exists('jeab_malmo_hs_kim_thread') && jeab_malmo_hs_kim_thread($tid);
}

/**
 * @param array<string, mixed> $thread
 */
function jeab_follow_customer_count(array $thread): int
{
    $n = 0;
    foreach ((array) ($thread['messages'] ?? []) as $m) {
        if (!is_array($m) || !empty($m['internal_note'])) {
            continue;
        }
        if (str_starts_with((string) ($m['source'] ?? ''), 'outbound')) {
            continue;
        }
        $from = (string) ($m['from'] ?? '');
        if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
            continue;
        }
        $n++;
    }
    return $n;
}

function jeab_follow_signature_has_mobile(string $sig): bool
{
    return preg_match('/(?:\+46|0)\s*7(?:[\s\-]*\d){7,}/u', $sig) === 1;
}

function jeab_follow_signature_mobile(string $sig): string
{
    if (preg_match('/(?:\+46[\s\-]*7(?:[\s\-]*\d){7,}|0\s*7(?:[\s\-]*\d){7,})/u', $sig, $m) !== 1) {
        return '';
    }
    return trim((string) $m[0]);
}

function jeab_follow_selftest_mode(): bool
{
    return !empty($GLOBALS['_JEAB_AUTO_SELFTEST'])
        || trim((string) ($GLOBALS['_JEAB_AUTO_DELIVER_TO'] ?? '')) !== '';
}

/** Sams adresser, samma lista som självtestets deliver_to. */
function jeab_follow_internal_address(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    if (function_exists('jeab_auto_selftest_deliver_to') && jeab_auto_selftest_deliver_to($email) !== '') {
        return true;
    }
    return function_exists('jeab_auto_selftest_addresses') && in_array($email, jeab_auto_selftest_addresses(), true);
}

/** Intern adress. Utanför självtestet får den inget automatiskt mejl, bara en anteckning. */
function jeab_follow_quiet_address(string $email): bool
{
    if (jeab_follow_selftest_mode()) {
        return false;
    }
    return jeab_follow_internal_address($email);
}

/** Testadress ska inte få kvittens. Nya testlead går den vanliga håll-vägen. */
function jeab_follow_receipt_address_blocked(string $email): bool
{
    if (jeab_follow_selftest_mode()) {
        return false;
    }
    $email = mb_strtolower(trim($email));
    if (jeab_follow_quiet_address($email)) {
        return true;
    }
    return function_exists('jeab_auto_test_email') && jeab_auto_test_email($email);
}

function jeab_follow_platform_address(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    if (function_exists('jeab_lead_platform') && jeab_lead_platform($email) !== '') {
        return true;
    }
    if (function_exists('jeab_inkorg_reply_blocked') && jeab_inkorg_reply_blocked($email)) {
        return true;
    }
    return function_exists('jeab_auto_service_recipient') && jeab_auto_service_recipient($email);
}

/** Kundens adress. Formulärets avsändare (Wix m.fl.) är inte kunden. */
function jeab_follow_party_email(string $parsed, string $from): string
{
    $parsed = mb_strtolower(trim($parsed));
    if ($parsed !== '' && !jeab_follow_platform_address($parsed)) {
        return $parsed;
    }
    $from = mb_strtolower(trim($from));
    if ($from !== '' && !jeab_follow_platform_address($from) && filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return $from;
    }
    return '';
}

/** Test- eller autotestpåminnelse, eller en påminnelse på en intern adress. */
function jeab_follow_reminder_blocked(array $rem): bool
{
    if (jeab_follow_selftest_mode()) {
        return !str_starts_with(trim((string) ($rem['thread_id'] ?? '')), 'autotest-');
    }
    if (function_exists('jeab_inkorg_reminder_is_test') && jeab_inkorg_reminder_is_test($rem)) {
        return true;
    }
    $tid = trim((string) ($rem['thread_id'] ?? ''));
    if ($tid !== '' && str_starts_with($tid, 'autotest-')) {
        return true;
    }
    if (preg_match('/\bautotest-/u', (string) ($rem['note'] ?? '')) === 1) {
        return true;
    }
    return jeab_follow_internal_address(mb_strtolower(trim((string) ($rem['email'] ?? ''))));
}

/** @param array<string, mixed> $thread */
function jeab_follow_thread_blocked(array $thread): bool
{
    if (jeab_follow_selftest_mode()) {
        return false;
    }
    if (str_starts_with((string) ($thread['id'] ?? ''), 'autotest-')) {
        return true;
    }
    return function_exists('jeab_inkorg_thread_is_test') && jeab_inkorg_thread_is_test($thread);
}

function jeab_follow_note_once(string $tid, string $text): void
{
    if ($tid === '' || $text === '' || !function_exists('jeab_auto_note')) {
        return;
    }
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    foreach ((array) (is_array($thread) ? ($thread['drafts'] ?? []) : []) as $draft) {
        if (is_array($draft) && str_contains((string) ($draft['body'] ?? ''), $text)) {
            return;
        }
    }
    jeab_auto_note($tid, $text);
}

/** Personens egen mall. Produkten väljer inte signatur. */
function jeab_follow_signature_person(string $id, string $email, string $name, string $company): string
{
    $id = trim($id);
    $email = mb_strtolower(trim($email));
    $name = trim($name);
    $company = $company === 'malmo' ? 'malmo' : 'jarfalla';
    $people = function_exists('jeab_people') ? jeab_people() : [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $pid = (string) ($person['id'] ?? '');
        $pemail = mb_strtolower((string) ($person['email'] ?? ''));
        if ($id !== '' && $pid === $id) {
            return $pid;
        }
        if ($email !== '' && $pemail !== '' && $pemail === $email) {
            return $pid;
        }
    }
    foreach ($people as $person) {
        if (!is_array($person) || (string) ($person['company'] ?? '') !== $company) {
            continue;
        }
        if ($name !== '' && strcasecmp((string) ($person['name'] ?? ''), $name) === 0) {
            return (string) ($person['id'] ?? '');
        }
    }
    return '';
}

function jeab_follow_signature_key(string $personId): string
{
    return match ($personId) {
        'Elin' => 'laddbox_jarfalla',
        'Roberto' => 'batteri_jarfalla',
        'SamL' => 'sol_jarfalla',
        'Kim' => 'laddbox_malmo',
        'SamW' => 'sol_malmo',
        default => '',
    };
}

/**
 * Signatur för den som skickar. $offer används inte.
 * Tom sträng när personen saknar en signatur med mobilnummer.
 */
function jeab_follow_seller_signature(string $name, string $offer, string $company, string $email = '', string $id = ''): string
{
    unset($offer);
    $personId = jeab_follow_signature_person($id, $email, $name, $company);
    $key = jeab_follow_signature_key($personId);
    if ($key === '' || !function_exists('jeab_auto_answer_signature')) {
        return '';
    }
    $set = function_exists('jeab_auto_settings') ? jeab_auto_settings() : [];
    $sig = jeab_auto_answer_signature($key, $set, '', $company);
    if ($sig === '' || !jeab_follow_signature_has_mobile($sig)) {
        return '';
    }
    return $sig;
}

/**
 * @return list<array{name:string,has_mobile:bool}>
 */
function jeab_follow_signature_rows(): array
{
    $rows = [
        ['name' => 'Elin', 'offer' => 'laddbox', 'company' => 'jarfalla'],
        ['name' => 'Roberto', 'offer' => 'batteri', 'company' => 'jarfalla'],
        ['name' => 'Sam L', 'offer' => 'sol', 'company' => 'jarfalla'],
        ['name' => 'Kim', 'offer' => 'laddbox', 'company' => 'malmo'],
    ];
    $out = [];
    foreach ($rows as $row) {
        $sig = jeab_follow_seller_signature($row['name'], $row['offer'], $row['company']);
        $out[] = [
            'name' => $row['name'],
            'has_mobile' => jeab_follow_signature_has_mobile($sig),
        ];
    }
    return $out;
}

/** inbox.signatures_check. Bara namn och om signaturen har ett mobilnummer. */
function jeab_follow_signatures_check(): array
{
    return ['ok' => true, 'sellers' => jeab_follow_signature_rows()];
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_follow_wants_receipt(array $msg): bool
{
    if (function_exists('jeab_inkorg_sales_block_reason') && jeab_inkorg_sales_block_reason($msg) !== '') {
        return false;
    }
    $labels = (array) ($msg['labels'] ?? []);
    $intent = (string) ($labels['intent'] ?? '');
    if ($intent === 'kop') {
        return true;
    }
    $case = (string) ($labels['case_intent'] ?? '');
    if ($case === '' && is_array($labels['case_class'] ?? null)) {
        $case = (string) ($labels['case_class']['intent'] ?? '');
    }
    if ($case === 'kopintresse') {
        return true;
    }
    if (trim((string) ($labels['question'] ?? '')) !== '') {
        return true;
    }
    $blob = mb_strtolower(trim((string) ($labels['reason'] ?? '')));
    return $blob !== '' && preg_match('/ring|kontakt|hör av|hor av|återkom|aterkom|signal/u', $blob) === 1;
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_follow_summary(array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $text = trim((string) ($labels['question'] ?? ''));
    if ($text === '') {
        $text = trim((string) ($labels['reason'] ?? ''));
    }
    if ($text === '') {
        $text = trim((string) ($msg['body'] ?? ''));
    }
    $text = trim((string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $text));
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return 'svar';
    }
    if (mb_strlen($text) > 80) {
        return rtrim(mb_substr($text, 0, 77)) . '…';
    }
    return $text;
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_follow_day(array $msg): string
{
    $raw = trim((string) (($msg['received_at'] ?? '') ?: ($msg['date'] ?? '')));
    try {
        $dt = new DateTimeImmutable($raw !== '' ? $raw : 'now');
    } catch (Throwable $e) {
        unset($e);
        $dt = new DateTimeImmutable('now');
    }
    return $dt->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('d/m');
}

/**
 * @param array<string, mixed> $msg
 */
function jeab_follow_line(array $msg): string
{
    return 'Kunden svarade ' . jeab_follow_day($msg) . ': ' . jeab_follow_summary($msg) . ', ring.';
}

/**
 * @param array<string, mixed> $rem
 */
function jeab_follow_reminder_is_this_case(array $rem, string $tid): bool
{
    if ($tid === '') {
        return false;
    }
    if (trim((string) ($rem['thread_id'] ?? '')) === $tid) {
        return true;
    }
    return function_exists('jeab_lead_reminder_has_thread')
        && jeab_lead_reminder_has_thread((string) ($rem['note'] ?? ''), $tid);
}

/**
 * Kontakt-id för samma e-post, eller samma telefon när namnen inte säger emot varandra.
 *
 * @param array<string, mixed> $cust
 * @return list<string>
 */
function jeab_follow_contact_ids(array $cust): array
{
    if (!function_exists('jeab_kontakter_load')) {
        return [];
    }
    try {
        $contacts = jeab_kontakter_load();
    } catch (Throwable $e) {
        unset($e);
        return [];
    }
    if (!is_array($contacts)) {
        return [];
    }
    $email = mb_strtolower(trim((string) ($cust['email'] ?? '')));
    $phone = function_exists('jeab_inkorg_party_phone') ? jeab_inkorg_party_phone((string) ($cust['phone'] ?? '')) : '';
    $name = (string) ($cust['name'] ?? '');
    $ids = [];
    foreach ($contacts as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowEmail = function_exists('jeab_kontakt_row_email') ? mb_strtolower(jeab_kontakt_row_email($row)) : '';
        $rowPhone = function_exists('jeab_kontakt_row_phone') ? jeab_inkorg_party_phone(jeab_kontakt_row_phone($row)) : '';
        $rowName = (string) ($row['name'] ?? '');
        $byEmail = $email !== '' && $rowEmail !== '' && $email === $rowEmail;
        $byPhone = $phone !== '' && $rowPhone !== '' && $phone === $rowPhone
            && trim($name) !== '' && trim($rowName) !== ''
            && function_exists('jeab_inkorg_party_names_agree')
            && jeab_inkorg_party_names_agree($name, $rowName);
        if ($byEmail || $byPhone) {
            $ids[] = (string) $id;
        }
    }
    return $ids;
}

/**
 * @param array<string, mixed> $rem
 * @param array<string, mixed> $cust
 * @param list<string> $contactIds
 */
function jeab_follow_reminder_rank(array $rem, array $cust, array $contactIds, string $email, string $phone): int
{
    $rank = 0;
    $known = trim((string) ($cust['contact_id'] ?? ''));
    if ($known !== '' && !in_array($known, $contactIds, true)) {
        $contactIds[] = $known;
    }
    $remContact = trim((string) ($rem['contact_id'] ?? ''));
    if ($remContact !== '' && in_array($remContact, $contactIds, true)) {
        $rank = 1;
    }
    $party = function_exists('jeab_follow_reminder_party') ? jeab_follow_reminder_party($rem) : [
        'name' => (string) ($rem['customer_name'] ?? ''),
        'email' => (string) ($rem['email'] ?? ''),
        'phone' => (string) ($rem['phone'] ?? ''),
    ];
    $remPhone = function_exists('jeab_inkorg_party_phone') ? jeab_inkorg_party_phone((string) ($party['phone'] ?? '')) : '';
    $remName = (string) ($party['name'] ?? '');
    $custName = trim((string) ($cust['name'] ?? ''));
    $namesOk = $custName !== '' && trim($remName) !== ''
        && function_exists('jeab_inkorg_party_names_agree')
        && jeab_inkorg_party_names_agree($custName, $remName);
    if ($phone !== '' && $remPhone !== '' && $phone === $remPhone && $namesOk) {
        $rank = 2;
    }
    $remEmail = mb_strtolower(trim((string) ($party['email'] ?? '')));
    if ($email !== '' && $remEmail !== '' && $email === $remEmail) {
        $rank = 3;
    }
    return $rank;
}

/**
 * @param array<string, mixed> $cust
 * @return array<string, mixed>|null
 */
function jeab_follow_find_reminder(array $cust, string $skipTid): ?array
{
    $email = mb_strtolower(trim((string) ($cust['email'] ?? '')));
    if (jeab_follow_internal_address($email) || (function_exists('jeab_auto_test_email') && jeab_auto_test_email($email))) {
        $email = '';
    }
    $phone = function_exists('jeab_inkorg_party_phone') ? jeab_inkorg_party_phone((string) ($cust['phone'] ?? '')) : '';
    $contactIds = jeab_follow_contact_ids($cust);
    $rows = function_exists('jeab_reminders_every') ? jeab_reminders_every(jeab_store()) : jeab_list_reminders(jeab_store(), true);
    $preload = [];
    foreach ($rows as $rem) {
        if (!is_array($rem)) {
            continue;
        }
        $cid = trim((string) ($rem['contact_id'] ?? ''));
        if ($cid !== '') {
            $preload[$cid] = $cid;
        }
    }
    if ($preload !== [] && function_exists('jeab_contacts_sole_load_ids')) {
        jeab_contacts_sole_load_ids(array_values($preload));
    }
    $best = null;
    $bestRank = 0;
    foreach ($rows as $rem) {
        if (!is_array($rem) || (int) ($rem['id'] ?? 0) < 1) {
            continue;
        }
        if (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($rem)) {
            continue;
        }
        if (jeab_follow_reminder_blocked($rem)) {
            continue;
        }
        if ($skipTid !== '' && jeab_follow_reminder_is_this_case($rem, $skipTid)) {
            continue;
        }
        $rank = jeab_follow_reminder_rank($rem, $cust, $contactIds, $email, $phone);
        if ($rank === 0) {
            continue;
        }
        $open = empty($rem['completed_at']);
        $bestOpen = is_array($best) && empty($best['completed_at']);
        $newer = !is_array($best) || (int) $rem['id'] > (int) $best['id'];
        if ($best === null || $rank > $bestRank || ($rank === $bestRank && $open && !$bestOpen) || ($rank === $bestRank && $open === $bestOpen && $newer)) {
            $best = $rem;
            $bestRank = $rank;
        }
    }
    return $best;
}

/** Bara påminnelsen som hör till just detta autotest-ärende. */
function jeab_follow_reminder_for_autotest(string $tid): ?array
{
    if ($tid === '' || !str_starts_with($tid, 'autotest-')) {
        return null;
    }
    $rows = function_exists('jeab_reminders_every') ? jeab_reminders_every(jeab_store()) : jeab_list_reminders(jeab_store(), true);
    foreach ($rows as $rem) {
        if (!is_array($rem) || (int) ($rem['id'] ?? 0) < 1) {
            continue;
        }
        if (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($rem)) {
            continue;
        }
        if (trim((string) ($rem['thread_id'] ?? '')) !== $tid) {
            continue;
        }
        return $rem;
    }
    return null;
}

/**
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $cust
 */
function jeab_follow_prior_quote(array $thread, array $cust, bool $reply): bool
{
    $tid = (string) ($thread['id'] ?? '');
    $email = mb_strtolower(trim((string) ($cust['email'] ?? '')));
    if (jeab_follow_internal_address($email)) {
        $email = '';
    }
    $phone = function_exists('jeab_inkorg_party_phone') ? jeab_inkorg_party_phone((string) ($cust['phone'] ?? '')) : '';
    $contactIds = jeab_follow_contact_ids($cust);
    $want = function_exists('jeab_auto_thread_classified_offer') ? jeab_auto_thread_classified_offer($thread) : '';
    if ($reply && !jeab_follow_thread_blocked($thread) && function_exists('jeab_inkorg_quote_sent_in_thread') && jeab_inkorg_quote_sent_in_thread($thread, $want)) {
        return true;
    }
    foreach (jeab_inkorg_threads() as $other) {
        if (!is_array($other) || (string) ($other['id'] ?? '') === $tid || !empty($other['deleted'])) {
            continue;
        }
        if (jeab_follow_thread_blocked($other)) {
            continue;
        }
        $has = function_exists('jeab_inkorg_quote_sent_in_thread') && jeab_inkorg_quote_sent_in_thread($other, $want);
        if (!$has) {
            continue;
        }
        $old = function_exists('jeab_lead_customer') ? jeab_lead_customer((array) ($other['first'] ?? [])) : [];
        $oldEmail = jeab_follow_party_email((string) ($old['email'] ?? ''), (string) ($other['first']['from'] ?? ''));
        $oldPhone = function_exists('jeab_inkorg_party_phone') ? jeab_inkorg_party_phone((string) ($old['phone'] ?? '')) : '';
        $oldName = (string) ($old['name'] ?? ($other['first']['from_name'] ?? ''));
        $namesOk = !function_exists('jeab_inkorg_party_names_agree') || jeab_inkorg_party_names_agree((string) ($cust['name'] ?? ''), $oldName);
        $oldContact = trim((string) ($other['contact_id'] ?? ''));
        $same = ($email !== '' && $oldEmail !== '' && $email === $oldEmail)
            || ($phone !== '' && $oldPhone !== '' && $phone === $oldPhone && $namesOk)
            || ($oldContact !== '' && in_array($oldContact, $contactIds, true));
        if ($same) {
            return true;
        }
    }
    $rows = function_exists('jeab_reminders_every') ? jeab_reminders_every(jeab_store()) : jeab_list_reminders(jeab_store(), true);
    foreach ($rows as $rem) {
        if (!is_array($rem)) {
            continue;
        }
        if (jeab_follow_reminder_blocked($rem)) {
            continue;
        }
        if (!$reply && jeab_follow_reminder_is_this_case($rem, $tid)) {
            continue;
        }
        $note = (string) ($rem['note'] ?? '');
        $remOffer = function_exists('jeab_inkorg_reminder_offer') ? jeab_inkorg_reminder_offer($rem) : '';
        $mark = is_array($rem['quote_sent'] ?? null) ? $rem['quote_sent'] : [];
        $marked = function_exists('jeab_quote_mark_matches_offer') && jeab_quote_mark_matches_offer($mark, $want, $remOffer);
        $noted = function_exists('jeab_quote_note_matches_offer') && jeab_quote_note_matches_offer($note, $want, $remOffer);
        if (!$marked && !$noted) {
            continue;
        }
        if (jeab_follow_reminder_rank($rem, $cust, $contactIds, $email, $phone) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $rem
 * @return array{name:string,email:string,company:string,id:string}
 */
function jeab_follow_owner(array $rem): array
{
    $company = function_exists('jeab_reminder_company') ? jeab_reminder_company($rem) : (string) ($rem['company'] ?? 'jarfalla');
    if (!in_array($company, ['jarfalla', 'malmo'], true)) {
        $company = 'jarfalla';
    }
    $personId = trim((string) ($rem['person'] ?? ''));
    foreach (jeab_people() as $person) {
        if ($personId !== '' && (string) ($person['id'] ?? '') === $personId) {
            return [
                'name' => (string) ($person['name'] ?? ''),
                'email' => (string) ($person['email'] ?? ''),
                'company' => $company,
                'id' => $personId,
            ];
        }
    }
    $assignee = trim((string) ($rem['assignee'] ?? ''));
    if ($assignee === '' && is_array($rem['assignees'] ?? null)) {
        $assignee = trim((string) ($rem['assignees'][0] ?? ''));
    }
    $email = function_exists('jeab_gp_person_email') ? jeab_gp_person_email($assignee, $company) : '';
    return ['name' => $assignee, 'email' => $email, 'company' => $company, 'id' => $personId];
}

/**
 * @param array<string, mixed> $rem
 * @return array<string, mixed>
 */
function jeab_follow_reopen(array $rem): array
{
    $id = (int) ($rem['id'] ?? 0);
    if ($id < 1 || empty($rem['completed_at'])) {
        return $rem;
    }
    try {
        $saved = jeab_toggle_reminder(jeab_store(), $id, false);
        return is_array($saved) ? $saved : $rem;
    } catch (Throwable $e) {
        unset($e);
        return $rem;
    }
}

function jeab_follow_receipt_allowed(string $tid, string $msgId): bool
{
    if ($tid === '' || $msgId === '') {
        return false;
    }
    $meta = jeab_inkorg_thread_meta($tid);
    $ids = array_map('strval', (array) ($meta['follow_receipt_msgs'] ?? []));
    if (in_array($msgId, $ids, true)) {
        return false;
    }
    $at = (int) ($meta['follow_receipt_at'] ?? 0);
    return $at < 1 || (time() - $at) >= 86400;
}

function jeab_follow_receipt_stamp(string $tid, string $msgId): void
{
    jeab_inkorg_thread_write($tid, static function (array $t) use ($msgId): array {
        $ids = array_values(array_filter(array_map('strval', (array) ($t['follow_receipt_msgs'] ?? []))));
        $ids[] = $msgId;
        $t['follow_receipt_msgs'] = array_slice($ids, -20);
        $t['follow_receipt_at'] = time();
        return $t;
    });
}

/**
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $rem
 * @param array{name:string,email:string,company:string,id:string} $owner
 */
function jeab_follow_send_receipt(array $thread, array $msg, array $rem, array $owner): bool
{
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return false;
    }
    $tid = (string) ($thread['id'] ?? '');
    $msgId = (string) ($msg['id'] ?? '');
    if (!jeab_follow_receipt_allowed($tid, $msgId)) {
        return false;
    }
    $from = mb_strtolower(trim($owner['email']));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $override = trim((string) ($GLOBALS['_JEAB_AUTO_DELIVER_TO'] ?? ''));
    if ($override !== '') {
        $rcpt = function_exists('jeab_auto_selftest_deliver_to') ? jeab_auto_selftest_deliver_to($override) : '';
        if ($rcpt === '') {
            return false;
        }
    } else {
        if (function_exists('jeab_lead_send_recipient')) {
            $resolved = jeab_lead_send_recipient($msg, $thread);
            if (!empty($resolved['lead']) && (string) ($resolved['email'] ?? '') === '') {
                jeab_follow_note_once($tid, 'Kvittens skickades inte: kundens e-post saknas i leaden.');
                return false;
            }
            $rcpt = mb_strtolower(trim((string) ($resolved['email'] ?? '')));
        } else {
            $cust = function_exists('jeab_lead_customer') ? jeab_lead_customer($msg) : [];
            $rcpt = mb_strtolower(trim((string) ($cust['email'] ?? '')));
        }
        if ($rcpt === '') {
            $rcpt = mb_strtolower(trim((string) ($msg['from'] ?? '')));
        }
        if ($rcpt === '' || !filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (function_exists('jeab_auto_service_recipient') && jeab_auto_service_recipient($rcpt)) {
            return false;
        }
        if (jeab_follow_receipt_address_blocked($rcpt)) {
            jeab_follow_note_once($tid, 'Kvittens skickades inte: adressen är intern eller ett test.');
            return false;
        }
    }
    $company = $owner['company'];
    $sig = jeab_follow_seller_signature((string) $owner['name'], '', $company, $from, (string) $owner['id']);
    if ($sig === '') {
        jeab_follow_note_once($tid, 'Kvittens skickades inte: avsändaren saknar signatur med mobilnummer.');
        return false;
    }
    $text = jeab_follow_receipt_text() . "\n\n" . $sig;
    $html = function_exists('jeab_auto_answer_html') ? jeab_auto_answer_html($text) : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    $subject = function_exists('jeab_auto_reply_subject') ? jeab_auto_reply_subject((string) ($msg['subject'] ?? '')) : (string) ($msg['subject'] ?? '');
    $firm = $company === 'malmo' ? 'Demo Energi Malmö' : 'Demo Energi Järfälla';
    $sendOpts = [
        'from_name' => trim($owner['name']) !== '' ? $owner['name'] . ', ' . $firm : $firm,
        'person_email' => $from,
        'malmo_hs_kim' => $company === 'malmo',
        'reply_to_graph_id' => trim((string) ($msg['graph_id'] ?? '')),
        'in_reply_to' => function_exists('jeab_auto_reply_message_id') ? jeab_auto_reply_message_id($msg) : '',
        'keep_subject' => true,
        'answer_only' => true,
        'guard_seller' => true,
        'seller_msg' => $msg,
    ];
    $send = static function () use ($company, $rcpt, $subject, $html, $sendOpts): array {
        $sent = jeab_outbound_graph_send($company, $rcpt, $subject, $html, true, [], [], $sendOpts);
        if (empty($sent['ok'])) {
            if (class_exists('JeabSendOnceMiss')) {
                throw new JeabSendOnceMiss();
            }

            return ['ok' => false];
        }

        return $sent;
    };
    if (function_exists('jeab_send_once')) {
        // Krav innan Graph. Misslyckat utskick släpper raden så nästa tick får försöka.
        $claimKey = hash('sha256', $tid . "\nkvittens\n" . $msgId);
        $once = jeab_send_once('kvittens', $claimKey, 86400, $send);
        if (!empty($once['duplicate']) || !empty($once['miss']) || empty($once['result']['ok'])) {
            return false;
        }
        $sent = $once['result'];
    } else {
        try {
            $sent = $send();
        } catch (JeabSendOnceMiss) {
            return false;
        }
        if (empty($sent['ok'])) {
            return false;
        }
    }
    if (function_exists('jeab_inbox_sent_record')) {
        $loggedFrom = trim((string) ($sent['from'] ?? ''));
        jeab_inbox_sent_record($tid, 'auto', 'system', 'kvittens', ['from' => $loggedFrom !== '' ? $loggedFrom : $from]);
    }
    jeab_follow_receipt_stamp($tid, $msgId);
    if (function_exists('jeab_auto_note')) {
        $shown = trim((string) ($sent['from'] ?? ''));
        if ($shown === '') {
            $shown = $from;
        }
        jeab_auto_note($tid, 'Kvittens skickad från ' . $shown . '.');
    }
    return true;
}

/** Test, självtest och autotest får inte skriva i en påminnelse. */
function jeab_follow_may_touch_reminder(array $thread, array $msg, array $rem): bool
{
    $tid = (string) ($thread['id'] ?? '');
    if (jeab_follow_selftest_mode() || str_starts_with($tid, 'autotest-')) {
        return false;
    }
    if (jeab_follow_reminder_blocked($rem)) {
        return false;
    }
    $emails = [
        (string) ($msg['from'] ?? ''),
        (string) ($rem['email'] ?? ''),
    ];
    if (function_exists('jeab_lead_customer')) {
        $cust = jeab_lead_customer($msg);
        $emails[] = (string) ($cust['email'] ?? '');
    }
    foreach ($emails as $email) {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            continue;
        }
        if (jeab_follow_internal_address($email)) {
            return false;
        }
        if (function_exists('jeab_auto_test_email') && jeab_auto_test_email($email)) {
            return false;
        }
    }
    return true;
}

function jeab_follow_reminder_audit_path(): string
{
    return jeab_inkorg_dir() . '/reminder-follow.jsonl';
}

/** @param array<string, mixed> $before */
function jeab_follow_reminder_audit_write(array $before): void
{
    $id = (int) ($before['id'] ?? 0);
    if ($id < 1) {
        return;
    }
    $line = json_encode([
        'at' => date('c'),
        'id' => $id,
        'status' => empty($before['completed_at']) ? 'open' : 'closed',
        'completed_at' => (string) ($before['completed_at'] ?? ''),
        'person' => (string) ($before['person'] ?? ''),
        'assignee' => (string) ($before['assignee'] ?? ''),
        'note' => (string) ($before['note'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return;
    }
    if (function_exists('jeab_log_line_append_raw') && jeab_log_line_append_raw('reminder_follow', $line)) {
        return;
    }
    @file_put_contents(jeab_follow_reminder_audit_path(), $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_follow_reminder_audit_read(int $id, int $sinceTs): array
{
    if (function_exists('jeab_log_reminder_read')) {
        $sql = jeab_log_reminder_read('reminder_follow', $id, $sinceTs);
        if (is_array($sql)) {
            return $sql;
        }
    }
    $path = jeab_follow_reminder_audit_path();
    if ($id < 1 || !is_file($path)) {
        return [];
    }
    $out = [];
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [];
    }
    while (($line = fgets($fh)) !== false) {
        $row = json_decode(trim($line), true);
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== $id) {
            continue;
        }
        $at = strtotime((string) ($row['at'] ?? '')) ?: 0;
        if ($sinceTs > 0 && $at < $sinceTs) {
            continue;
        }
        $out[] = $row;
    }
    fclose($fh);
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? ''));
    });
    return $out;
}

function jeab_follow_handle(array $thread, array $msg, array $rem, bool $reply): array
{
    $tid = (string) ($thread['id'] ?? '');
    $ownerBefore = jeab_follow_owner($rem);
    $wasClosed = !empty($rem['completed_at']);
    $touch = jeab_follow_may_touch_reminder($thread, $msg, $rem);
    if ($touch) {
        jeab_follow_reminder_audit_write($rem);
        $rem = jeab_follow_reopen($rem);
    }
    $owner = jeab_follow_owner($rem);
    if ($owner['id'] === '' && $ownerBefore['id'] !== '') {
        $owner = $ownerBefore;
    }
    $wants = jeab_follow_wants_receipt($msg);
    if ($touch && $wants && function_exists('jeab_lead_reminder_append')) {
        $saved = jeab_lead_reminder_append($rem, $tid, jeab_follow_line($msg));
        if (is_array($saved)) {
            $rem = $saved;
        }
    }
    $receipt = false;
    if ($wants) {
        $msgId = (string) ($msg['id'] ?? '');
        if ($msgId !== '') {
            jeab_inkorg_update($msgId, static function (array $row): array {
                $labels = (array) ($row['labels'] ?? []);
                $labels['type'] = 'Kräver åtgärd från oss';
                $labels['action'] = 'åtgärda';
                $labels['follow'] = 'atgard';
                $row['labels'] = $labels;
                return $row;
            });
        }
        if ($tid !== '') {
            try {
                jeab_inkorg_thread_set($tid, 'open', null);
            } catch (Throwable $e) {
                unset($e);
            }
        }
        if (jeab_follow_receipt_enabled()) {
            $receipt = jeab_follow_send_receipt($thread, $msg, $rem, $owner);
        } elseif ($tid !== '') {
            jeab_follow_note_once($tid, 'Kvittens skickades inte: kvittensutskick är av.');
        }
    } elseif ($reply && $tid !== '') {
        try {
            jeab_inkorg_thread_set($tid, 'open', null);
        } catch (Throwable $e) {
            unset($e);
        }
    }
    $fresh = jeab_get_reminder(jeab_store(), (int) ($rem['id'] ?? 0));
    if (is_array($fresh)) {
        $rem = $fresh;
    }
    return [
        'action' => 'follow',
        'quoted' => false,
        'reminder' => (int) ($rem['id'] ?? 0),
        'reopened' => $wasClosed && empty($rem['completed_at']) ? 1 : 0,
        'open' => empty($rem['completed_at']) ? 1 : 0,
        'owner' => $owner['id'] !== '' ? $owner['id'] : $ownerBefore['id'],
        'from' => $receipt ? $owner['email'] : '',
        'receipt' => $receipt ? 1 : 0,
    ];
}

/**
 * null = första kontakten, den vanliga offerten får köras.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $msg
 * @return array<string, mixed>|null
 */
function jeab_follow_try(array $thread, array $msg): ?array
{
    if (isset($GLOBALS['JEAB_FOLLOW_TRY_WATCH']) && is_callable($GLOBALS['JEAB_FOLLOW_TRY_WATCH'])) {
        ($GLOBALS['JEAB_FOLLOW_TRY_WATCH'])();
    }
    if (!empty($thread['deleted']) || !jeab_follow_in_pipeline($thread)) {
        return null;
    }
    $tidEarly = (string) ($thread['id'] ?? '');
    $selftestCase = jeab_follow_selftest_mode() || str_starts_with($tidEarly, 'autotest-');
    if ($selftestCase && jeab_follow_customer_count($thread) <= 1) {
        return null;
    }
    $set = function_exists('jeab_auto_settings') ? jeab_auto_settings() : [];
    if (empty($set['quote_enabled'])) {
        return null;
    }
    if (function_exists('jeab_hs_import_frozen') && (jeab_hs_import_frozen($msg) || jeab_hs_import_frozen($thread))) {
        return null;
    }
    if (function_exists('jeab_hs_import_no_auto') && (jeab_hs_import_no_auto($msg) || jeab_hs_import_no_auto($thread))) {
        return null;
    }
    if (function_exists('jeab_lead_is_store_mail') && jeab_lead_is_store_mail($msg)) {
        return null;
    }
    if (function_exists('jeab_inkorg_sales_block_reason')) {
        $salesBlock = jeab_inkorg_sales_block_reason($msg);
        if ($salesBlock !== '') {
            if (jeab_follow_customer_count($thread) <= 1) {
                return null;
            }
            $tidBlock = (string) ($thread['id'] ?? '');
            if (function_exists('jeab_inkorg_sales_skip_log')) {
                jeab_inkorg_sales_skip_log($tidBlock, (string) (($msg['labels']['type'] ?? '')), $salesBlock, 'kvitto');
            }
            return [
                'action' => 'skip',
                'why' => $salesBlock,
                'quoted' => false,
                'reminder' => 0,
                'reopened' => 0,
                'open' => 0,
                'owner' => '',
                'from' => '',
                'receipt' => 0,
            ];
        }
    }
    if (function_exists('jeab_inkorg_form_is_existing_support') && jeab_inkorg_form_is_existing_support($msg)) {
        return null;
    }
    $labels = (array) ($msg['labels'] ?? []);
    $junk = (string) ($labels['intent'] ?? '') === 'skrap' || (string) ($labels['type'] ?? '') === 'Skräp';
    $reply = jeab_follow_customer_count($thread) > 1;
    if ($junk && !$reply) {
        return null;
    }
    $cust = function_exists('jeab_lead_customer') ? jeab_lead_customer($msg) : [];
    $cust['email'] = jeab_follow_party_email((string) ($cust['email'] ?? ''), (string) ($msg['from'] ?? ''));
    $tid = (string) ($thread['id'] ?? '');
    if ($selftestCase && $reply && str_starts_with($tid, 'autotest-')) {
        $own = jeab_follow_reminder_for_autotest($tid);
        return is_array($own) ? jeab_follow_handle($thread, $msg, $own, true) : null;
    }
    if (jeab_follow_internal_address((string) $cust['email'])) {
        $cust['email'] = '';
    }
    if (function_exists('jeab_follow_resolve_party')) {
        $cust = jeab_follow_resolve_party($thread, $cust);
    }
    $fromAddr = mb_strtolower(trim((string) ($msg['from'] ?? '')));
    $partyAddr = mb_strtolower(trim((string) $cust['email']));
    $testParty = function_exists('jeab_auto_test_email') && (jeab_auto_test_email($partyAddr) || jeab_auto_test_email($fromAddr));
    $testMatch = function_exists('jeab_test_lead_fields') ? jeab_test_lead_fields($msg, $thread) : ['test' => false];
    if (!$selftestCase && ($testParty || !empty($testMatch['test']))) {
        if (jeab_follow_customer_count($thread) > 1 && function_exists('jeab_test_lead_log')) {
            jeab_test_lead_log(
                'kvitto',
                (string) ($testMatch['reason'] ?? ($testParty ? 'test i mejl' : 'test')),
                (string) ($testMatch['email'] ?? $partyAddr),
                (string) ($testMatch['phone'] ?? '')
            );
            return [
                'action' => 'note',
                'quoted' => false,
                'reminder' => 0,
                'reopened' => 0,
                'open' => 0,
                'owner' => '',
                'from' => '',
                'receipt' => 0,
                'why' => 'testlead',
            ];
        }
        return null;
    }
    if (jeab_follow_quiet_address((string) $cust['email']) || jeab_follow_quiet_address($fromAddr)) {
        jeab_follow_note_once($tid, 'Inget automatiskt mejl: intern eller självtest-adress.');
        return [
            'action' => 'note',
            'quoted' => false,
            'reminder' => 0,
            'reopened' => 0,
            'open' => 0,
            'owner' => '',
            'from' => '',
            'receipt' => 0,
            'why' => 'ingen kvittens till intern adress',
        ];
    }
    $rem = jeab_follow_find_reminder($cust, $reply ? '' : $tid);
    $priorQuote = jeab_follow_prior_quote($thread, $cust, $reply);
    $newForm = !$reply && function_exists('jeab_lead_wix_kalkyl_sol') && jeab_lead_wix_kalkyl_sol($msg);
    if ($rem === null && $newForm) {
        return null;
    }
    // Första kontakten. Uppföljning (jeab_follow_handle) bara när offerten redan är skickad
    // i det här ärendet. En påminnelse utan offert får den första offerten.
    if (!$reply) {
        $quotedHere = function_exists('jeab_quote_chain_thread_quoted') && jeab_quote_chain_thread_quoted($tid);
        if (!$quotedHere) {
            return null;
        }
        $own = is_array($rem) ? $rem : (function_exists('jeab_quote_chain_reminder_row') ? jeab_quote_chain_reminder_row($thread) : null);
        if (is_array($own)) {
            return jeab_follow_handle($thread, $msg, $own, false);
        }
        return [
            'action' => 'follow',
            'quoted' => false,
            'reminder' => 0,
            'reopened' => 0,
            'open' => 0,
            'owner' => '',
            'from' => '',
            'receipt' => 0,
            'why' => 'offerten är redan skickad',
        ];
    }
    if (is_array($rem) && function_exists('jeab_quote_chain_reminder_has_offer') && !jeab_quote_chain_reminder_has_offer($rem) && !$priorQuote) {
        return null;
    }
    if ($rem === null) {
        if ($reply || !$priorQuote) {
            return null;
        }
        if (jeab_follow_wants_receipt($msg)) {
            $msgId = (string) ($msg['id'] ?? '');
            if ($msgId !== '') {
                jeab_inkorg_update($msgId, static function (array $row): array {
                    $labels = (array) ($row['labels'] ?? []);
                    $labels['type'] = 'Kräver åtgärd från oss';
                    $labels['action'] = 'åtgärda';
                    $labels['follow'] = 'atgard';
                    $row['labels'] = $labels;
                    return $row;
                });
            }
            if ($tid !== '') {
                try {
                    jeab_inkorg_thread_set($tid, 'open', null);
                } catch (Throwable $e) {
                    unset($e);
                }
            }
        }
        return [
            'action' => 'follow',
            'quoted' => false,
            'reminder' => 0,
            'reopened' => 0,
            'open' => 0,
            'owner' => '',
            'from' => '',
            'receipt' => 0,
            'why' => 'offerten är redan skickad',
        ];
    }
    return jeab_follow_handle($thread, $msg, $rem, $reply);
}

/**
 * inbox.reminder_get. Status, ägare och de sista raderna i loggen. Ingen kundlista.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_reminder_get(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'id saknas'];
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder(jeab_store(), $id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'id' => $id];
    }
    $text = function_exists('jeab_reminder_notes_text') ? jeab_reminder_notes_text($row) : trim((string) ($row['note'] ?? ''));
    $lines = preg_split('/\R/u', $text) ?: [];
    $lines = array_values(array_filter(array_map(static fn($line): string => trim((string) $line), $lines), static fn(string $line): bool => $line !== ''));
    $shown = function_exists('jeab_reminder_shown_customer') ? jeab_reminder_shown_customer($row) : [];
    return [
        'ok' => true,
        'id' => $id,
        'status' => empty($row['completed_at']) ? 'open' : 'closed',
        'owner' => (string) ($row['person'] ?? ''),
        'email' => (string) ($shown['email'] ?? ''),
        'contact_name' => (string) ($shown['name'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'tags' => function_exists('jeab_parse_tags') ? jeab_parse_tags($row['tags'] ?? []) : [],
        'address' => [
            'street' => (string) ($shown['street'] ?? ''),
            'zip' => (string) ($shown['postal'] ?? ''),
            'city' => (string) ($shown['city'] ?? ''),
        ],
        'list_id' => (int) ($row['list_id'] ?? 0),
        'created' => (string) ($row['created_at'] ?? ''),
        'log' => array_slice($lines, -8),
    ];
}

/**
 * inbox.reminder_restore. dry=1 är standard.
 * Tar bort «Kunden svarade …» som följningen lade till efter since,
 * och lägger tillbaka status och ägare från historiken.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_reminder_restore(array $in): array
{
    $dry = !array_key_exists('dry', $in) || !($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false);
    $id = (int) ($in['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'id saknas'];
    }
    $since = trim((string) ($in['since'] ?? ''));
    $sinceTs = $since === '' ? 0 : (strtotime($since) ?: 0);
    if ($since !== '' && $sinceTs <= 0) {
        return ['ok' => false, 'error' => 'ogiltigt since'];
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder(jeab_store(), $id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'id' => $id];
    }
    $history = jeab_follow_reminder_audit_read($id, $sinceTs);
    $prior = $history[0] ?? null;
    $priorNote = is_array($prior) ? (string) ($prior['note'] ?? '') : '';
    $text = function_exists('jeab_reminder_notes_text') ? jeab_reminder_notes_text($row) : trim((string) ($row['note'] ?? ''));
    $lines = preg_split('/\R/u', $text) ?: [];
    $kept = [];
    $removed = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $followLine = preg_match('/^Kunden svarade\b/u', $line) === 1;
        if ($followLine && ($prior === null || !str_contains($priorNote, $line))) {
            $removed[] = $line;
            continue;
        }
        $kept[] = $line;
    }
    $note = trim(implode("\n", $kept));
    $status = is_array($prior) ? (string) ($prior['status'] ?? '') : (empty($row['completed_at']) ? 'open' : 'closed');
    if ($status !== 'open' && $status !== 'closed') {
        $status = empty($row['completed_at']) ? 'open' : 'closed';
    }
    $owner = is_array($prior) ? (string) ($prior['person'] ?? '') : (string) ($row['person'] ?? '');
    $completed = is_array($prior) ? (string) ($prior['completed_at'] ?? '') : (string) ($row['completed_at'] ?? '');
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'status' => $status,
        'owner' => $owner,
        'removed' => $removed,
        'history' => is_array($prior) ? 1 : 0,
        'would_send' => false,
    ];
    if ($dry) {
        return $out;
    }
    $fields = [
        'note' => $note !== '' ? $note : null,
        'updated_at' => function_exists('jeab_now') ? jeab_now() : date('c'),
    ];
    if (is_array($prior)) {
        $fields['person'] = $owner !== '' ? $owner : null;
        $fields['completed_at'] = $status === 'closed'
            ? ($completed !== '' ? $completed : (function_exists('jeab_now') ? jeab_now() : date('c')))
            : null;
    }
    jeab_store()->saveReminder($id, $fields);
    $fresh = jeab_inbox_reminder_get(['id' => $id]);
    $out['status'] = (string) ($fresh['status'] ?? $status);
    $out['owner'] = (string) ($fresh['owner'] ?? $owner);
    $out['log'] = (array) ($fresh['log'] ?? []);
    return $out;
}

function jeab_inbox_reminder_hook_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }
    return !($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false);
}

function jeab_inbox_reminder_hook_audit_path(): string
{
    return jeab_inkorg_dir() . '/reminder-hook.jsonl';
}

/** @param array<string, mixed> $before @param array<string, mixed> $after */
function jeab_inbox_reminder_hook_audit(string $action, array $before, array $after): void
{
    $line = json_encode([
        'at' => date('c'),
        'action' => $action,
        'id' => (int) ($before['id'] ?? 0),
        'before' => [
            'status' => empty($before['completed_at']) ? 'open' : 'closed',
            'completed_at' => (string) ($before['completed_at'] ?? ''),
            'person' => (string) ($before['person'] ?? ''),
            'note' => (string) ($before['note'] ?? ''),
        ],
        'after' => [
            'status' => empty($after['completed_at']) ? 'open' : 'closed',
            'completed_at' => (string) ($after['completed_at'] ?? ''),
            'person' => (string) ($after['person'] ?? ''),
            'note' => (string) ($after['note'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return;
    }
    if (function_exists('jeab_log_line_append_raw') && jeab_log_line_append_raw('reminder_hook', $line)) {
        return;
    }
    @file_put_contents(jeab_inbox_reminder_hook_audit_path(), $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * inbox.reminder_close. dry=1 är standard. Stänger påminnelsen. Skickar inget.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_reminder_close(array $in): array
{
    $dry = jeab_inbox_reminder_hook_dry($in);
    $id = (int) ($in['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'id saknas'];
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder(jeab_store(), $id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'id' => $id];
    }
    $already = !empty($row['completed_at']);
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'status' => 'closed',
        'changed' => $already ? 0 : 1,
        'would_send' => false,
    ];
    if ($dry || $already) {
        return $out;
    }
    $stamp = function_exists('jeab_now') ? jeab_now() : date('c');
    $saved = jeab_store()->saveReminder($id, [
        'completed_at' => $stamp,
        'updated_at' => $stamp,
    ]);
    jeab_inbox_reminder_hook_audit('close', $row, is_array($saved) ? $saved : array_merge($row, ['completed_at' => $stamp]));
    $out['status'] = 'closed';
    return $out;
}

/**
 * Bara två säkra mönster. Okända mönster skrivs inte.
 *
 * @param array<string, mixed> $in
 * @return array{ok: bool, error?: string, pattern?: string, patterns: list<string>}
 */
function jeab_inbox_reminder_clean_patterns(array $in): array
{
    $raw = $in['patterns'] ?? ['offert_skipped', 'autotest'];
    if (is_string($raw)) {
        $raw = preg_split('/[\s,;]+/u', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return ['ok' => false, 'error' => 'okänt mönster', 'patterns' => []];
    }
    $want = [];
    foreach ($raw as $item) {
        $token = trim((string) $item);
        if ($token === '') {
            continue;
        }
        $key = mb_strtolower($token);
        if ($key === 'offert_skipped' || str_starts_with($token, 'Offert skickades inte automatiskt:')) {
            $want['offert_skipped'] = true;
            continue;
        }
        if ($key === 'autotest' || $key === 'autotest-*') {
            $want['autotest'] = true;
            continue;
        }
        return ['ok' => false, 'error' => 'okänt mönster', 'pattern' => $token, 'patterns' => []];
    }
    return ['ok' => true, 'patterns' => array_keys($want)];
}

function jeab_inbox_reminder_clean_line(string $line, array $patterns): ?string
{
    if (isset($patterns['offert_skipped']) && preg_match('/^Offert skickades inte automatiskt\s*:/u', $line) === 1) {
        return null;
    }
    if (!isset($patterns['autotest'])) {
        return $line;
    }
    $next = preg_replace('~https?://\S*autotest-[A-Za-z0-9_-]+\S*~iu', '', $line);
    $next = preg_replace('/\bautotest-[A-Za-z0-9_-]+\b/u', '', is_string($next) ? $next : $line);
    $next = preg_replace('/[ \t]{2,}/u', ' ', is_string($next) ? $next : $line);
    $next = trim(is_string($next) ? $next : $line, " \t|:-");
    if ($next === '' || preg_match('/^(mail|länk|link)\s*:?\s*$/iu', $next) === 1) {
        return null;
    }
    return $next;
}

/**
 * inbox.reminder_clean. dry=1 är standard.
 * Tar bort rader som börjar med «Offert skickades inte automatiskt:»
 * och länkar eller rader som pekar på autotest-*. Skickar inget.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_reminder_clean(array $in): array
{
    $dry = jeab_inbox_reminder_hook_dry($in);
    $id = (int) ($in['id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'id saknas'];
    }
    $parsed = jeab_inbox_reminder_clean_patterns($in);
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => (string) ($parsed['error'] ?? 'okänt mönster'), 'pattern' => (string) ($parsed['pattern'] ?? ''), 'would_send' => false];
    }
    $patterns = array_fill_keys($parsed['patterns'], true);
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder(jeab_store(), $id) : null;
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found', 'id' => $id];
    }
    $text = function_exists('jeab_reminder_notes_text') ? jeab_reminder_notes_text($row) : trim((string) ($row['note'] ?? ''));
    $lines = preg_split('/\R/u', $text) ?: [];
    $kept = [];
    $removed = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $next = $patterns === [] ? $line : jeab_inbox_reminder_clean_line($line, $patterns);
        if ($next === null || $next !== $line) {
            $removed[] = $line;
        }
        if ($next !== null && $next !== '') {
            $kept[] = $next;
        }
    }
    $note = trim(implode("\n", $kept));
    $changed = $removed !== [];
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'id' => $id,
        'status' => empty($row['completed_at']) ? 'open' : 'closed',
        'patterns' => $parsed['patterns'],
        'removed' => $removed,
        'changed' => $changed ? 1 : 0,
        'would_send' => false,
    ];
    if ($dry || !$changed) {
        return $out;
    }
    $stamp = function_exists('jeab_now') ? jeab_now() : date('c');
    $saved = jeab_store()->saveReminder($id, [
        'note' => $note !== '' ? $note : null,
        'updated_at' => $stamp,
    ]);
    jeab_inbox_reminder_hook_audit('clean', $row, is_array($saved) ? $saved : array_merge($row, ['note' => $note]));
    return $out;
}
