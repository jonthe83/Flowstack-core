<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Hård spärr mot automatisk offert, kvitto och SMS till testleads.
 * Körs före Grok. Hela ordet «test», inte delsträngar som Testa.
 */

function jeab_test_lead_selftest_active(): bool
{
    return !empty($GLOBALS['_JEAB_AUTO_SELFTEST'])
        || trim((string) ($GLOBALS['_JEAB_AUTO_DELIVER_TO'] ?? '')) !== '';
}

function jeab_test_lead_word(string $value, string $word): bool
{
    $word = preg_quote($word, '/');

    return preg_match('/(?<![\p{L}\p{N}])' . $word . '(?![\p{L}\p{N}])/iu', $value) === 1;
}

function jeab_test_lead_email_address(string $raw): string
{
    $raw = trim($raw);
    if (preg_match('/<([^>]+)>/', $raw, $m) === 1) {
        $raw = trim((string) $m[1]);
    }
    $raw = mb_strtolower(trim($raw, " \t<>\"'"));
    if ($raw === '' || !str_contains($raw, '@')) {
        return '';
    }
    $local = (string) strstr($raw, '@', true);
    $domain = (string) substr((string) strrchr($raw, '@'), 1);
    $domain = rtrim($domain, '.');
    if ($local === '' || $domain === '' || !str_contains($domain, '.')) {
        return '';
    }

    return $local . '@' . $domain;
}

function jeab_test_lead_email_reason(string $email): string
{
    $email = jeab_test_lead_email_address($email);
    if ($email === '') {
        return '';
    }
    $local = (string) strstr($email, '@', true);
    $domain = (string) substr((string) strrchr($email, '@'), 1);
    if (in_array($domain, ['example.org', 'example.com', 'example.net', 'mail.grokbot.com'], true)
        || str_ends_with($domain, '.example.org')
        || str_ends_with($domain, '.example.com')
        || str_ends_with($domain, '.example.net')
        || str_ends_with($domain, '.mail.grokbot.com')) {
        return 'mejldomän';
    }
    $labels = array_values(array_filter(explode('.', $domain), static fn(string $label): bool => $label !== ''));
    $tld = (string) ($labels[count($labels) - 1] ?? '');
    if ($tld === 'test' || $tld === 'invalid') {
        return 'mejldomän';
    }
    if (in_array('test', $labels, true)) {
        return 'mejldomän';
    }
    if (jeab_test_lead_word($local, 'test')) {
        return 'test i mejl';
    }

    return '';
}

/**
 * Adresser självtestet får leverera till. Inte en strikt spärr:
 * samma adresser används som kundmejl på riktiga ärenden.
 *
 * @return list<string>
 */
function jeab_test_lead_selftest_addresses(): array
{
    if (function_exists('jeab_auto_selftest_addresses')) {
        $list = jeab_auto_selftest_addresses();
        if (is_array($list) && $list !== []) {
            $out = [];
            foreach ($list as $one) {
                $email = jeab_test_lead_email_address((string) $one);
                if ($email !== '') {
                    $out[] = $email;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
    }

    return ['demo@example.test', 'demo.person@example.invalid'];
}

function jeab_test_lead_strict_email_reason(string $email): string
{
    $email = jeab_test_lead_email_address($email);
    if ($email === '') {
        return '';
    }
    $local = (string) strstr($email, '@', true);
    $domain = (string) substr((string) strrchr($email, '@'), 1);
    $grokbot = $domain === 'mail.grokbot.com' || str_ends_with($domain, '.mail.grokbot.com');
    if ($grokbot && str_starts_with($local, 'jeab+test-')) {
        return 'jeab+test';
    }
    if (in_array($domain, ['example.com', 'example.org', 'example.net'], true)
        || str_ends_with($domain, '.example.com')
        || str_ends_with($domain, '.example.org')
        || str_ends_with($domain, '.example.net')) {
        return 'mejldomän';
    }

    return '';
}

function jeab_test_lead_phone_digits(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function jeab_test_lead_phone_reason(string $phone): string
{
    $digits = jeab_test_lead_phone_digits($phone);
    if ($digits === '') {
        return '';
    }
    if (in_array($digits, ['0700000000', '46700000000', '1234567'], true)) {
        return 'testtelefon';
    }

    return '';
}

function jeab_test_lead_name_reason(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if (jeab_test_lead_word($name, 'test')) {
        return 'test i namn';
    }
    if (jeab_test_lead_word($name, 'asdf')) {
        return 'namn asdf';
    }

    return '';
}

/** Namn som börjar med «Test » och sedan ett till ord. «Testa» räknas inte. */
function jeab_test_lead_name_prefix(string $name): bool
{
    return preg_match('/^test\s+\S/iu', trim($name)) === 1;
}

/**
 * Känd testadress. Tom sträng när adressen inte är en av de här.
 * einstein och qpsmedia är test bara tillsammans med ett namn som börjar med «Test ».
 * +test, gmail plus-test och word_test känns också igen här.
 */
function jeab_test_lead_known_address_reason(string $email): string
{
    $email = jeab_test_lead_email_address($email);
    if ($email === '') {
        return '';
    }
    $local = (string) strstr($email, '@', true);
    $domain = (string) substr((string) strrchr($email, '@'), 1);
    $labels = array_values(array_filter(explode('.', $domain), static fn(string $label): bool => $label !== ''));
    $gmail = in_array($domain, ['gmail.com', 'googlemail.com'], true);
    if ($gmail && preg_match('/\+test(?![\p{L}\p{N}])/iu', $local) === 1) {
        return 'gmail plus-test';
    }
    if (preg_match('/\+test(?![\p{L}\p{N}])/iu', $local) === 1) {
        return '+test';
    }
    if ($local === 'einstein' || in_array('einstein', $labels, true)) {
        return 'einstein';
    }
    if ($local === 'qpsmedia' || in_array('qpsmedia', $labels, true) || str_contains($domain, 'qpsmedia')) {
        return 'qpsmedia';
    }
    if ($local === 'word_test' || jeab_test_lead_word($local, 'test')) {
        return 'word_test';
    }

    return '';
}

/**
 * «Test Förnamn» tillsammans med +test, einstein, qpsmedia, word_test eller gmail plus-test.
 *
 * @param array<string, mixed> $lead
 */
function jeab_test_lead_named_submission_reason(array $lead): string
{
    $prefix = false;
    foreach (jeab_test_lead_list($lead, 'name', 'names') as $name) {
        if (jeab_test_lead_name_prefix($name)) {
            $prefix = true;
            break;
        }
    }
    if (!$prefix) {
        return '';
    }
    foreach (jeab_test_lead_list($lead, 'email', 'emails') as $email) {
        $reason = jeab_test_lead_known_address_reason($email);
        if ($reason !== '') {
            return $reason;
        }
    }

    return '';
}

/** De här orsakerna ska inte skapa påminnelse eller offert. */
function jeab_test_lead_blocks_reminder(string $reason): bool
{
    return in_array($reason, ['einstein', 'qpsmedia', 'word_test', 'gmail plus-test', '+test', 'testflagga', 'mark_test'], true);
}

/**
 * Uttrycklig testinlämning. Wix-raden «Test: ja», headern X-Jeab-Test och fältet test/is_test.
 *
 * @param array<string, mixed> $src
 */
function jeab_test_submission_marked(array $src): bool
{
    foreach (['test', 'is_test'] as $key) {
        if (!array_key_exists($key, $src)) {
            continue;
        }
        $value = $src[$key];
        if ($value === true || $value === 1) {
            return true;
        }
        if (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'ja', 'yes'], true)) {
            return true;
        }
    }
    $headers = $src['headers'] ?? null;
    if (is_array($headers)) {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== 'x-jeab-test') {
                continue;
            }
            if (in_array(strtolower(trim((string) $value)), ['1', 'true', 'ja', 'yes'], true)) {
                return true;
            }
        }
    }
    $blob = (string) ($src['subject'] ?? '') . "\n" . (string) ($src['body'] ?? '') . "\n" . (string) ($src['body_text'] ?? '');
    if (preg_match('/(?:^|\n)\s*test\s*:\s*(?:ja|yes|true|1)\s*(?:\n|$)/iu', $blob) === 1) {
        return true;
    }

    return false;
}

function jeab_reminder_counts_as_production_lead(array $row): bool
{
    if (!empty($row['is_test'])) {
        return false;
    }
    $tags = function_exists('jeab_parse_tags') ? jeab_parse_tags($row['tags'] ?? []) : [];

    return !in_array('test', $tags, true);
}

/** @param array<string, mixed> $thread */
function jeab_thread_counts_as_production_lead(array $thread, array $msg = []): bool
{
    if (!empty($thread['is_test']) || !empty($thread['test_lead'])) {
        return false;
    }
    foreach ((array) ($thread['tags'] ?? []) as $tag) {
        if ((string) $tag === 'test') {
            return false;
        }
    }
    if ($msg !== [] && (!empty($msg['test']) || !empty($msg['is_test']) || !empty($msg['labels']['is_test']))) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $lead
 * @return list<string>
 */
function jeab_test_lead_list(array $lead, string $one, string $many): array
{
    $out = [];
    if (isset($lead[$one]) && is_scalar($lead[$one])) {
        $out[] = trim((string) $lead[$one]);
    }
    foreach ((array) ($lead[$many] ?? []) as $item) {
        if (is_scalar($item)) {
            $out[] = trim((string) $item);
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $lead
 * @return array{0:string,1:string}
 */
function jeab_test_lead_seen(array $lead): array
{
    $email = '';
    foreach (jeab_test_lead_list($lead, 'email', 'emails') as $candidate) {
        $email = jeab_test_lead_email_address($candidate);
        if ($email !== '') {
            break;
        }
    }
    $phone = '';
    foreach (jeab_test_lead_list($lead, 'phone', 'phones') as $candidate) {
        if (jeab_test_lead_phone_digits($candidate) !== '') {
            $phone = $candidate;
            break;
        }
    }

    return [$email, $phone];
}

/**
 * @param array<string, mixed> $lead
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_test_lead_match(array $lead): array
{
    $empty = ['test' => false, 'reason' => '', 'email' => '', 'phone' => ''];
    [$seenEmail, $seenPhone] = jeab_test_lead_seen($lead);
    foreach (jeab_test_lead_list($lead, 'email', 'emails') as $email) {
        if (jeab_test_lead_email_reason($email) === 'mejldomän') {
            return ['test' => true, 'reason' => 'mejldomän', 'email' => jeab_test_lead_email_address($email), 'phone' => $seenPhone];
        }
    }
    $named = jeab_test_lead_named_submission_reason($lead);
    if ($named !== '') {
        $hit = $seenEmail;
        foreach (jeab_test_lead_list($lead, 'email', 'emails') as $email) {
            if (jeab_test_lead_known_address_reason($email) === $named) {
                $hit = jeab_test_lead_email_address($email);
                break;
            }
        }

        return ['test' => true, 'reason' => $named, 'email' => $hit, 'phone' => $seenPhone];
    }
    $emailHit = '';
    $emailReason = '';
    foreach (jeab_test_lead_list($lead, 'email', 'emails') as $email) {
        $reason = jeab_test_lead_email_reason($email);
        if ($reason !== '') {
            $emailHit = jeab_test_lead_email_address($email);
            $emailReason = $reason;
            break;
        }
    }
    if ($emailReason !== '') {
        return ['test' => true, 'reason' => $emailReason, 'email' => $emailHit, 'phone' => $seenPhone];
    }
    foreach (jeab_test_lead_list($lead, 'name', 'names') as $name) {
        $reason = jeab_test_lead_name_reason($name);
        if ($reason !== '') {
            return ['test' => true, 'reason' => $reason, 'email' => $seenEmail, 'phone' => $seenPhone];
        }
    }
    foreach (jeab_test_lead_list($lead, 'phone', 'phones') as $phone) {
        if (jeab_test_lead_phone_reason($phone) !== '') {
            return ['test' => true, 'reason' => 'testtelefon', 'email' => $seenEmail, 'phone' => $phone];
        }
    }
    if (!empty($lead['is_test'])) {
        $reason = trim((string) ($lead['flag_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'grok';
        }

        return ['test' => true, 'reason' => $reason, 'email' => $seenEmail, 'phone' => $seenPhone];
    }

    return $empty;
}

/** @param array<string, mixed> $lead */
function is_test_lead(array $lead): bool
{
    return jeab_test_lead_match($lead)['test'];
}

/**
 * Spärr för Fortnox, SMS, Graph och mejlkopia. Mejldomän kräver att varje
 * icke-tom kundadress (email, customer_email, epost, emails) är jeab+test-*
 * på mail.grokbot.com (eller underdomän) eller example.com/.org/.net.
 * En riktig adress räcker för att mejldomänen inte ska träffa. is_test gäller ändå.
 * Självtestets egna adresser räknas inte: de används som kundmejl på riktiga ärenden.
 *
 * @param array<string, mixed> $lead
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_test_lead_strict(array $lead): array
{
    $empty = ['test' => false, 'reason' => '', 'email' => '', 'phone' => ''];
    [$seenEmail, $seenPhone] = jeab_test_lead_seen($lead);
    $allTest = true;
    $seen = 0;
    $reason = '';
    $hit = '';
    foreach (jeab_test_lead_customer_emails($lead) as $email) {
        $seen++;
        $one = jeab_test_lead_strict_email_reason($email);
        if ($one === '') {
            $allTest = false;
            continue;
        }
        if ($reason === '') {
            $reason = $one;
            $hit = jeab_test_lead_email_address($email);
        }
    }
    if ($seen > 0 && $allTest && $reason !== '') {
        return [
            'test' => true,
            'reason' => $reason,
            'email' => $hit,
            'phone' => $seenPhone,
        ];
    }
    if (!empty($lead['is_test'])) {
        $reason = trim((string) ($lead['flag_reason'] ?? ''));
        if ($reason === '') {
            $reason = 'is_test';
        }

        return ['test' => true, 'reason' => $reason, 'email' => $seenEmail, 'phone' => $seenPhone];
    }

    return $empty;
}

/**
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $thread
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_test_lead_fields(array $msg, array $thread = []): array
{
    $cust = [];
    if (function_exists('jeab_lead_customer')) {
        try {
            $parsed = jeab_lead_customer($msg);
            if (is_array($parsed)) {
                $cust = $parsed;
            }
        } catch (Throwable) {
            $cust = [];
        }
    }
    $labels = (array) ($msg['labels'] ?? []);
    $isTest = !empty($labels['is_test']) || !empty($msg['is_test']) || !empty($msg['test']);
    $flag = (string) ($msg['flag_reason'] ?? $labels['test_reason'] ?? '');
    if (!$isTest && (!empty($thread['is_test']) || !empty($thread['test_lead']))) {
        $isTest = true;
        if (trim($flag) === '') {
            $flag = 'mark_test';
        }
    }
    if (!$isTest) {
        foreach ((array) ($thread['tags'] ?? []) as $tag) {
            if ((string) $tag === 'test') {
                $isTest = true;
                if (trim($flag) === '') {
                    $flag = 'mark_test';
                }
                break;
            }
        }
    }
    if (!$isTest && jeab_test_submission_marked($msg)) {
        $isTest = true;
        if (trim($flag) === '') {
            $flag = 'testflagga';
        }
    }
    $blob = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
    if (!$isTest && preg_match('/(?<![\p{L}\p{N}])test\s+inskick(?![\p{L}\p{N}])/iu', $blob) === 1) {
        $isTest = true;
        if (trim($flag) === '') {
            $flag = 'test inskick';
        }
    }

    return jeab_test_lead_match([
        'emails' => [
            (string) ($cust['email'] ?? ''),
            (string) ($msg['from'] ?? ''),
            (string) ($msg['customer_email'] ?? ''),
            (string) ($thread['customer_email'] ?? ''),
        ],
        'names' => [
            (string) ($cust['name'] ?? ''),
            (string) ($msg['from_name'] ?? ''),
            (string) ($msg['customer_name'] ?? ''),
            (string) ($thread['customer_name'] ?? ''),
        ],
        'phones' => [
            (string) ($cust['phone'] ?? ''),
            (string) ($msg['phone'] ?? ''),
        ],
        'is_test' => $isTest,
        'flag_reason' => $flag,
    ]);
}

function jeab_test_lead_mask_email(string $email): string
{
    $email = jeab_test_lead_email_address($email);
    if ($email === '') {
        return '';
    }
    $local = (string) strstr($email, '@', true);
    $domain = (string) substr((string) strrchr($email, '@'), 1);
    $first = $local === '' ? '' : mb_substr($local, 0, 1);

    return $first . '***@' . $domain;
}

function jeab_test_lead_mask_phone(string $phone): string
{
    $digits = jeab_test_lead_phone_digits($phone);
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) < 5) {
        return '***';
    }

    return substr($digits, 0, 3) . '***' . substr($digits, -2);
}

function jeab_test_lead_log(string $flow, string $reason, string $email, string $phone, string $note = ''): void
{
    if (!empty($GLOBALS['_JEAB_INGEST_DRY'])) {
        return;
    }
    $flow = mb_substr(trim($flow), 0, 32);
    $reason = mb_substr(trim($reason), 0, 64);
    $note = mb_substr(trim($note), 0, 80);
    if ($flow === '' || $reason === '') {
        return;
    }
    $maskedEmail = jeab_test_lead_mask_email($email);
    $maskedPhone = jeab_test_lead_mask_phone($phone);
    $key = $flow . '|' . $reason . '|' . $maskedEmail . '|' . $maskedPhone . '|' . $note;
    if (!isset($GLOBALS['_JEAB_TEST_LEAD_LOGGED']) || !is_array($GLOBALS['_JEAB_TEST_LEAD_LOGGED'])) {
        $GLOBALS['_JEAB_TEST_LEAD_LOGGED'] = [];
    }
    if (isset($GLOBALS['_JEAB_TEST_LEAD_LOGGED'][$key])) {
        return;
    }
    $GLOBALS['_JEAB_TEST_LEAD_LOGGED'][$key] = true;
    if (!function_exists('jeab_log_line_insert') || !function_exists('jeab_log_ensure')) {
        return;
    }
    $pdo = function_exists('jeab_log_pdo') ? jeab_log_pdo() : null;
    if (!$pdo instanceof PDO && function_exists('jeab_lead_sql')) {
        try {
            $pdo = jeab_lead_sql();
        } catch (Throwable) {
            $pdo = null;
        }
        if ($pdo instanceof PDO) {
            try {
                jeab_log_ensure($pdo);
            } catch (Throwable) {
                return;
            }
        }
    }
    if (!$pdo instanceof PDO) {
        return;
    }
    $payload = [
        'at' => date('c'),
        'flow' => $flow,
        'reason' => $reason,
        'email' => $maskedEmail,
        'phone' => $maskedPhone,
        'nonce' => bin2hex(random_bytes(4)),
    ];
    if ($note !== '') {
        $payload['note'] = $note;
    }
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    try {
        jeab_log_line_insert($pdo, 'test_lead', hash('sha256', $line), (string) $payload['at'], $line);
    } catch (Throwable) {
    }
}

function jeab_test_lead_mark(string $tid): void
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_inkorg_thread_write')) {
        return;
    }
    try {
        jeab_inkorg_thread_write($tid, static function (array $t): array {
            $tags = array_map('strval', (array) ($t['tags'] ?? []));
            if (!in_array('test', $tags, true)) {
                $tags[] = 'test';
            }
            $t['tags'] = array_values($tags);
            $t['test_lead'] = true;

            return $t;
        });
    } catch (Throwable) {
    }
}

/**
 * @param array<string, mixed> $labels
 * @param array<string, mixed> $msg
 * @return array<string, mixed>
 */
function jeab_test_lead_stamp_labels(array $labels, array $msg): array
{
    $match = jeab_test_lead_fields($msg);
    if (!$match['test'] && empty($labels['is_test'])) {
        return $labels;
    }
    $labels['is_test'] = true;
    if ($match['test'] && (string) ($match['reason'] ?? '') !== '') {
        $labels['test_reason'] = (string) $match['reason'];
    } elseif (trim((string) ($labels['test_reason'] ?? '')) === '') {
        $labels['test_reason'] = 'grok';
    }
    $tags = array_map('strval', (array) ($labels['add_tags'] ?? []));
    if (!in_array('test', $tags, true)) {
        $tags[] = 'test';
    }
    $labels['add_tags'] = array_values($tags);

    return $labels;
}

/**
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $msg
 * @return array<string, mixed>|null
 */
function jeab_test_lead_take(array $thread, array $msg, string $flow, bool $apply = true): ?array
{
    if (jeab_test_lead_selftest_active()) {
        return null;
    }
    $match = jeab_test_lead_fields($msg, $thread);
    if (!$match['test']) {
        return null;
    }
    if (!$apply) {
        return ['action' => 'hold', 'why' => 'testlead'];
    }
    jeab_test_lead_log($flow, (string) $match['reason'], (string) $match['email'], (string) $match['phone']);
    $tid = trim((string) ($thread['id'] ?? $msg['thread_id'] ?? ''));
    jeab_test_lead_mark($tid);
    if ($flow === 'offert') {
        // Känd produkt får samma påminnelse som ett riktigt lead. Offert, kvitto och SMS skickas inte.
        $offer = (string) (($msg['labels']['offer'] ?? '') ?: '');
        if (function_exists('jeab_auto_offer_is') && !jeab_auto_offer_is($offer) && function_exists('jeab_lead_infer_offer')) {
            $offer = jeab_lead_infer_offer($msg, (array) ($thread['tags'] ?? []));
        }
        // Formulärrubriken sätter inte produkt. Bara en redan satt offert, från Grok, ger påminnelse.
        if (function_exists('jeab_auto_offer_is') && jeab_auto_offer_is($offer) && function_exists('jeab_lead_process')) {
            $work = $thread;
            if (is_array($work['first'] ?? null)) {
                $firstLabels = (array) ($work['first']['labels'] ?? []);
                if ((string) ($firstLabels['offer'] ?? '') === '' && $offer !== '') {
                    $firstLabels['offer'] = $offer;
                    $firstLabels['type'] = (string) ($firstLabels['type'] ?? '') !== '' ? (string) $firstLabels['type'] : 'Ny lead';
                    $firstLabels['intent'] = (string) ($firstLabels['intent'] ?? '') !== '' ? (string) $firstLabels['intent'] : 'kop';
                    $work['first']['labels'] = $firstLabels;
                }
            }
            try {
                jeab_lead_process($work, true);
            } catch (Throwable) {
            }
        }
        if ($tid !== '' && function_exists('jeab_inkorg_thread_write')) {
            try {
                jeab_inkorg_thread_write($tid, static function (array $t): array {
                    $t['status'] = 'open';
                    unset($t['deleted_at'], $t['deleted_by'], $t['hook_closed']);
                    $t['updated_at'] = date('c');

                    return $t;
                });
            } catch (Throwable) {
            }
        }

        return ['action' => 'hold', 'why' => 'testlead', 'sent' => 0];
    }

    return ['action' => 'hold', 'why' => 'testlead', 'skipped' => true];
}

/**
 * @param array<string, mixed> $row
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_test_lead_booking(array $row): array
{
    return jeab_test_lead_match([
        'name' => (string) ($row['customer_name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
    ]);
}

/**
 * Kundens egna adresser. Mottagare, avsändare, reply_to och kopia räknas inte.
 *
 * @param array<string, mixed> $src
 * @return list<string>
 */
function jeab_test_lead_customer_emails(array $src): array
{
    $emails = [];
    foreach (['email', 'customer_email', 'epost'] as $key) {
        if (isset($src[$key]) && is_scalar($src[$key]) && trim((string) $src[$key]) !== '') {
            $emails[] = (string) $src[$key];
        }
    }
    foreach ((array) ($src['emails'] ?? []) as $email) {
        if (is_scalar($email) && trim((string) $email) !== '') {
            $emails[] = (string) $email;
        }
    }

    return $emails;
}

/**
 * Strikt koll bara på kundens eget mejl och is_test i lead, order, påminnelse eller bokning.
 *
 * @param array<string, mixed> $src
 * @return array{emails:list<string>,is_test:bool,flag_reason:string}
 */
function jeab_test_lead_customer_probe(array $src): array
{
    $emails = jeab_test_lead_customer_emails($src);
    $isTest = !empty($src['is_test']);
    $flag = trim((string) ($src['flag_reason'] ?? ''));
    foreach (['lead', 'order', 'reminder', 'booking'] as $key) {
        if (!isset($src[$key]) || !is_array($src[$key])) {
            continue;
        }
        foreach (jeab_test_lead_customer_emails($src[$key]) as $email) {
            $emails[] = $email;
        }
        if (!empty($src[$key]['is_test'])) {
            $isTest = true;
            if ($flag === '') {
                $flag = trim((string) ($src[$key]['flag_reason'] ?? ''));
            }
        }
    }

    return ['emails' => $emails, 'is_test' => $isTest, 'flag_reason' => $flag];
}

/**
 * Lead från SMS-anropet: bara kundens mejl och is_test, aldrig mottagare eller avsändare.
 *
 * @param array<string, mixed> $opts
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_test_lead_send_probe(string $channel, string $recipient, array $opts = []): array
{
    unset($channel, $recipient);
    $customer = jeab_test_lead_customer_probe($opts);

    return jeab_test_lead_strict([
        'emails' => $customer['emails'],
        'is_test' => $customer['is_test'],
        'flag_reason' => $customer['flag_reason'],
    ]);
}

/**
 * Testlead får mejl bara till sin egen testadress. Installatör och annan kopia tas bort.
 * block_to: mottagaren är inte kundens egen testadress, så utskicket ska inte gå dit.
 *
 * @param list<mixed> $cc
 * @param array<string, mixed> $lead
 * @return array{cc:list<string>,strip:bool,block_to:bool}
 */
function jeab_test_lead_mail_cc(string $to, array $cc, array $lead = []): array
{
    $customer = jeab_test_lead_customer_probe($lead);
    $probe = jeab_test_lead_strict([
        'emails' => $customer['emails'],
        'is_test' => $customer['is_test'],
        'flag_reason' => $customer['flag_reason'],
    ]);
    $kept = [];
    foreach ($cc as $addr) {
        if (is_scalar($addr)) {
            $kept[] = (string) $addr;
        }
    }
    if (!$probe['test']) {
        return ['cc' => $kept, 'strip' => false, 'block_to' => false];
    }
    $own = '';
    foreach ($customer['emails'] as $email) {
        if (jeab_test_lead_strict_email_reason($email) !== '') {
            $own = jeab_test_lead_email_address($email);
            if ($own !== '') {
                break;
            }
        }
    }
    $toNorm = jeab_test_lead_email_address($to);
    if ($own === '' || $toNorm !== $own) {
        if ($kept !== [] || $toNorm !== '') {
            jeab_test_lead_log('kopia', (string) $probe['reason'], (string) $probe['email'], (string) $probe['phone']);
        }

        return ['cc' => [], 'strip' => true, 'block_to' => true];
    }
    $self = $own;
    $only = [];
    foreach ($kept as $addr) {
        $norm = jeab_test_lead_email_address($addr);
        if ($self !== '' && $norm === $self) {
            $only[] = $addr;
        }
    }
    if ($kept !== $only) {
        jeab_test_lead_log('kopia', (string) $probe['reason'], (string) $probe['email'], (string) $probe['phone']);
    }

    return ['cc' => $only, 'strip' => true, 'block_to' => false];
}

/**
 * @param list<mixed> $cc
 * @param array<string, mixed> $opts
 * @return array{cc:list<string>,opts:array<string,mixed>,block_to:bool}
 */
function jeab_test_lead_take_mail(string $to, array $cc, array $opts = []): array
{
    $lead = [];
    if (isset($opts['lead']) && is_array($opts['lead'])) {
        $lead = $opts['lead'];
    }
    foreach (['email', 'name', 'phone', 'emails', 'names', 'phones', 'is_test', 'flag_reason'] as $key) {
        if (array_key_exists($key, $opts)) {
            $lead[$key] = $opts[$key];
        }
    }
    foreach (['order', 'reminder', 'booking'] as $key) {
        if (isset($opts[$key]) && is_array($opts[$key])) {
            $lead[$key] = $opts[$key];
        }
    }
    $gate = jeab_test_lead_mail_cc($to, $cc, $lead);
    if (!empty($gate['strip'])) {
        $opts['strip_cc'] = true;
    }
    $opts['cc'] = $gate['cc'];

    return ['cc' => $gate['cc'], 'opts' => $opts, 'block_to' => !empty($gate['block_to'])];
}

/** Bokningen är ett testlead: ingen Outlook-händelse. */
function jeab_calendar_test_lead(array $row): bool
{
    $match = jeab_test_lead_strict([
        'email' => (string) ($row['email'] ?? ''),
        'emails' => is_array($row['emails'] ?? null) ? $row['emails'] : [],
        'is_test' => !empty($row['is_test']),
        'flag_reason' => (string) ($row['flag_reason'] ?? ''),
    ]);
    if (!$match['test']) {
        return false;
    }
    jeab_test_lead_log('kalender', (string) $match['reason'], (string) $match['email'], (string) $match['phone']);

    return true;
}

/**
 * @param array<string, mixed> $row
 * @return array{emails:list<string>,names:list<string>,phones:list<string>,is_test:bool,flag_reason:string}
 */
function jeab_test_lead_audit_lead(array $row): array
{
    $emails = [];
    $names = [];
    $phones = [];
    $take = static function (array $src) use (&$emails, &$names, &$phones): void {
        foreach (['email', 'customer_email', 'from', 'epost'] as $key) {
            if (isset($src[$key]) && is_scalar($src[$key]) && trim((string) $src[$key]) !== '') {
                $emails[] = (string) $src[$key];
            }
        }
        foreach (['name', 'customer_name', 'customer', 'huvudsokande', 'from_name'] as $key) {
            if (isset($src[$key]) && is_scalar($src[$key]) && trim((string) $src[$key]) !== '') {
                $names[] = (string) $src[$key];
            }
        }
        foreach (['phone', 'telefon', 'customer_phone'] as $key) {
            if (isset($src[$key]) && is_scalar($src[$key]) && trim((string) $src[$key]) !== '') {
                $phones[] = (string) $src[$key];
            }
        }
    };
    $take($row);
    foreach (['owners', 'medsokande', 'agare', 'applicant'] as $list) {
        if (!isset($row[$list]) || !is_array($row[$list])) {
            continue;
        }
        $people = $row[$list] === [] || array_key_exists(0, $row[$list]) ? $row[$list] : [$row[$list]];
        foreach ($people as $person) {
            if (is_array($person)) {
                $take($person);
            }
        }
    }

    return [
        'emails' => $emails,
        'names' => $names,
        'phones' => $phones,
        'is_test' => !empty($row['is_test']),
        'flag_reason' => (string) ($row['flag_reason'] ?? ''),
    ];
}

/**
 * Bara domän och flaggor. Inget namn, ingen hel adress, inget personnummer.
 *
 * @param array<string, mixed> $lead
 * @return array{domains:list<string>,has_real_email:bool,is_test:bool,has_name:bool}
 */
function jeab_test_lead_audit_public(array $lead): array
{
    $domains = [];
    $hasReal = false;
    foreach (jeab_test_lead_customer_emails($lead) as $email) {
        $addr = jeab_test_lead_email_address($email);
        $domain = $addr === '' ? '' : (string) substr((string) strrchr($addr, '@'), 1);
        if ($domain !== '') {
            $domains[$domain] = true;
        }
        if (jeab_test_lead_strict_email_reason($email) === '') {
            $hasReal = true;
        }
    }
    $hasName = false;
    foreach (jeab_test_lead_list($lead, 'name', 'names') as $name) {
        if ($name !== '') {
            $hasName = true;
            break;
        }
    }
    ksort($domains);

    return [
        'domains' => array_keys($domains),
        'has_real_email' => $hasReal,
        'is_test' => !empty($lead['is_test']),
        'has_name' => $hasName,
    ];
}

/** @return array{count:int,reasons:array<string,array{count:int,ids:list<string>,hits:list<array{id:string,domains:list<string>,has_real_email:bool,is_test:bool,has_name:bool}>}>} */
function jeab_test_lead_audit_empty(): array
{
    return ['count' => 0, 'reasons' => []];
}

/**
 * @param array{count:int,reasons:array<string,array{count:int,ids:list<string>,hits:list<array{id:string,domains:list<string>,has_real_email:bool,is_test:bool,has_name:bool}>}>} $bucket
 * @param array{test?:bool,reason?:string} $match
 * @param array<string, mixed> $lead
 */
function jeab_test_lead_audit_hit(array &$bucket, array $match, string $id, array $lead = []): void
{
    if ($id === '' || empty($match['test'])) {
        return;
    }
    $reason = trim((string) ($match['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'test';
    }
    $bucket['count']++;
    if (!isset($bucket['reasons'][$reason])) {
        $bucket['reasons'][$reason] = ['count' => 0, 'ids' => [], 'hits' => []];
    }
    $public = jeab_test_lead_audit_public($lead);
    $bucket['reasons'][$reason]['count']++;
    $bucket['reasons'][$reason]['ids'][] = $id;
    $bucket['reasons'][$reason]['hits'][] = [
        'id' => $id,
        'domains' => $public['domains'],
        'has_real_email' => $public['has_real_email'],
        'is_test' => $public['is_test'],
        'has_name' => $public['has_name'],
    ];
}

/**
 * Läser kontakter, ordrar, påminnelser och bokningar. Skriver inget.
 *
 * @return array{ok:bool,tables:array<string,array{strict:array{count:int,reasons:array<string,array{count:int,ids:list<string>,hits:list<array{id:string,domains:list<string>,has_real_email:bool,is_test:bool,has_name:bool}>}>},broad:array{count:int,reasons:array<string,array{count:int,ids:list<string>,hits:list<array{id:string,domains:list<string>,has_real_email:bool,is_test:bool,has_name:bool}>}>}}>}
 */
function jeab_test_lead_audit(): array
{
    $tables = [
        'contacts' => ['strict' => jeab_test_lead_audit_empty(), 'broad' => jeab_test_lead_audit_empty()],
        'purchases' => ['strict' => jeab_test_lead_audit_empty(), 'broad' => jeab_test_lead_audit_empty()],
        'reminders' => ['strict' => jeab_test_lead_audit_empty(), 'broad' => jeab_test_lead_audit_empty()],
        'bookings' => ['strict' => jeab_test_lead_audit_empty(), 'broad' => jeab_test_lead_audit_empty()],
    ];
    $scan = static function (string $table, string $id, array $row) use (&$tables): void {
        if ($id === '' || !isset($tables[$table])) {
            return;
        }
        $lead = jeab_test_lead_audit_lead($row);
        jeab_test_lead_audit_hit($tables[$table]['strict'], jeab_test_lead_strict($lead), $id, $lead);
        jeab_test_lead_audit_hit($tables[$table]['broad'], jeab_test_lead_match($lead), $id, $lead);
    };
    if (function_exists('jeab_kontakter_load')) {
        foreach (jeab_kontakter_load() as $id => $row) {
            if (is_array($row)) {
                $scan('contacts', (string) ($row['id'] ?? $id), $row);
            }
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['reminder_id'] ?? $row['id'] ?? ''));
            $scan('purchases', $id, $row);
        }
    }
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (is_array($row)) {
                $scan('reminders', (string) ($row['id'] ?? ''), $row);
            }
        }
        foreach ($store->listAllBookings() as $row) {
            if (is_array($row)) {
                $scan('bookings', (string) ($row['id'] ?? ''), $row);
            }
        }
    }
    foreach ($tables as &$table) {
        foreach (['strict', 'broad'] as $side) {
            ksort($table[$side]['reasons']);
            foreach ($table[$side]['reasons'] as &$reason) {
                $reason['ids'] = array_values(array_unique($reason['ids']));
                sort($reason['ids'], SORT_STRING);
                $byId = [];
                foreach ($reason['hits'] ?? [] as $hit) {
                    if (!is_array($hit)) {
                        continue;
                    }
                    $hid = (string) ($hit['id'] ?? '');
                    if ($hid !== '') {
                        $byId[$hid] = $hit;
                    }
                }
                ksort($byId, SORT_STRING);
                $reason['hits'] = array_values($byId);
            }
            unset($reason);
        }
    }
    unset($table);

    return ['ok' => true, 'tables' => $tables];
}

function jeab_test_lead_audit_authorized(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }
    if (function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }
    $expected = function_exists('jeab_config') ? trim((string) (jeab_config()['incoming_webhook_token'] ?? '')) : '';

    return $expected !== '' && function_exists('jeab_incoming_token_ok') && jeab_incoming_token_ok($expected);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_test_lead_audit_dispatch(array $input): array
{
    unset($input);
    if (!jeab_test_lead_audit_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $result = jeab_test_lead_audit();
    $result['status'] = 200;

    return $result;
}

function jeab_mark_test_undo_path(): string
{
    $dir = function_exists('jeab_inkorg_dir') ? jeab_inkorg_dir() : sys_get_temp_dir();

    return $dir . '/mark_test_undo.json';
}

/** @return list<array<string, mixed>> */
function jeab_mark_test_undo_load(): array
{
    $path = jeab_mark_test_undo_path();
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

/** @param list<array<string, mixed>> $rows */
function jeab_mark_test_undo_store(array $rows): void
{
    $path = jeab_mark_test_undo_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $rows = array_slice($rows, -20);
    file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** @param array<string, mixed> $input @return list<string> */
function jeab_mark_test_thread_ids(array $input): array
{
    $raw = $input['thread_ids'] ?? $input['threads'] ?? [];
    if (is_string($raw)) {
        $raw = preg_split('/\s*,\s*/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    $one = trim((string) ($input['thread_id'] ?? ''));
    if ($one !== '') {
        $raw[] = $one;
    }
    $out = [];
    foreach ($raw as $id) {
        if (!is_scalar($id)) {
            continue;
        }
        $id = trim((string) $id);
        if ($id === '' || isset($out[$id])) {
            continue;
        }
        $out[$id] = true;
        if (count($out) >= 50) {
            break;
        }
    }

    return array_keys($out);
}

/** @param array<string, mixed> $input @return list<int> */
function jeab_mark_test_reminder_ids(array $input): array
{
    $raw = $input['reminder_ids'] ?? $input['reminders'] ?? [];
    if (is_string($raw)) {
        $raw = preg_split('/\s*,\s*/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    if (isset($input['reminder_id']) && is_scalar($input['reminder_id'])) {
        $raw[] = $input['reminder_id'];
    }
    $out = [];
    foreach ($raw as $id) {
        if (!is_scalar($id)) {
            continue;
        }
        $id = (int) $id;
        if ($id <= 0 || isset($out[$id])) {
            continue;
        }
        $out[$id] = true;
        if (count($out) >= 50) {
            break;
        }
    }

    return array_map('intval', array_keys($out));
}

/** @return list<array<string, mixed>> */
function jeab_mark_test_reminders_for(array $threadIds, array $reminderIds): array
{
    $wantThread = array_fill_keys($threadIds, true);
    $wantId = array_fill_keys($reminderIds, true);
    if ($wantThread === [] && $wantId === []) {
        return [];
    }
    $rows = [];
    if (function_exists('jeab_reminders_every') && function_exists('jeab_store')) {
        try {
            $rows = jeab_reminders_every(jeab_store());
        } catch (Throwable) {
            $rows = [];
        }
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $tid = trim((string) ($row['thread_id'] ?? ''));
        if ($id > 0 && (isset($wantId[$id]) || ($tid !== '' && isset($wantThread[$tid])))) {
            $out[$id] = $row;
        }
    }

    return array_values($out);
}

/**
 * inbox.mark_test. dry=1 är standard. Sätter is_test på ärenden och taggen test
 * på kopplade påminnelser. dry=0 kräver confirm=SAM. Sparar ångring.
 * Raderar inget och skickar inget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_mark_test(array $input): array
{
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : true;
    $base = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'sent' => 0,
        'deleted' => 0,
        'would_send' => false,
    ];
    if (!empty($input['undo'])) {
        return jeab_inbox_mark_test_undo($input, $dry) + ['would_send' => false];
    }
    $threadIds = jeab_mark_test_thread_ids($input);
    $reminderIds = jeab_mark_test_reminder_ids($input);
    if ($threadIds === [] && $reminderIds === []) {
        return ['ok' => false, 'error' => 'thread_ids eller reminder_ids krävs', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'deleted' => 0, 'would_send' => false];
    }
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'dry=0 kräver confirm=SAM', 'dry' => 1, 'sent' => 0, 'deleted' => 0, 'would_send' => false, 'written' => 0];
    }
    $threads = [];
    $missingThreads = [];
    foreach ($threadIds as $tid) {
        $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
        $known = is_array($meta) && trim((string) ($meta['created_at'] ?? '')) !== '';
        if (!$known && function_exists('jeab_inkorg_messages')) {
            foreach (jeab_inkorg_messages() as $message) {
                if (is_array($message) && (string) ($message['thread_id'] ?? '') === $tid) {
                    $known = true;
                    break;
                }
            }
        }
        if (!$known) {
            $missingThreads[] = $tid;
            continue;
        }
        $tags = array_map('strval', (array) ($meta['tags'] ?? []));
        $threads[] = [
            'id' => $tid,
            'is_test' => !empty($meta['is_test']) || !empty($meta['test_lead']) || in_array('test', $tags, true),
            'would_set' => true,
        ];
    }
    $reminderRows = jeab_mark_test_reminders_for(array_column($threads, 'id'), $reminderIds);
    $foundReminder = [];
    $reminders = [];
    foreach ($reminderRows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $foundReminder[$id] = true;
        $tags = function_exists('jeab_parse_tags') ? jeab_parse_tags($row['tags'] ?? []) : [];
        $reminders[] = [
            'id' => $id,
            'thread_id' => trim((string) ($row['thread_id'] ?? '')),
            'has_test' => in_array('test', $tags, true),
            'would_tag' => 'test',
            'excluded' => true,
        ];
    }
    $missingReminders = [];
    foreach ($reminderIds as $id) {
        if (!isset($foundReminder[$id])) {
            $missingReminders[] = $id;
        }
    }
    if ($threads === [] && $reminders === []) {
        return $base + [
            'ok' => false,
            'error' => 'not_found',
            'threads' => [],
            'reminders' => [],
            'missing_threads' => $missingThreads,
            'missing_reminders' => $missingReminders,
            'written' => 0,
        ];
    }
    if ($dry) {
        return $base + [
            'threads' => $threads,
            'reminders' => $reminders,
            'missing_threads' => $missingThreads,
            'missing_reminders' => $missingReminders,
            'written' => 0,
        ];
    }
    $undoThreads = [];
    $undoMessages = [];
    foreach ($threads as $one) {
        $tid = (string) $one['id'];
        $meta = jeab_inkorg_thread_meta($tid);
        $undoThreads[$tid] = [
            'is_test' => !empty($meta['is_test']),
            'test_lead' => !empty($meta['test_lead']),
            'tags' => array_values(array_map('strval', (array) ($meta['tags'] ?? []))),
        ];
        if (function_exists('jeab_inkorg_thread_write')) {
            jeab_inkorg_thread_write($tid, static function (array $t): array {
                $tags = array_map('strval', (array) ($t['tags'] ?? []));
                if (!in_array('test', $tags, true)) {
                    $tags[] = 'test';
                }
                $t['tags'] = array_values($tags);
                $t['test_lead'] = true;
                $t['is_test'] = true;
                $t['updated_at'] = date('c');

                return $t;
            });
        }
        if (function_exists('jeab_inkorg_messages') && function_exists('jeab_inkorg_update')) {
            foreach (jeab_inkorg_messages() as $message) {
                if (!is_array($message) || (string) ($message['thread_id'] ?? '') !== $tid) {
                    continue;
                }
                $mid = (string) ($message['id'] ?? '');
                if ($mid === '') {
                    continue;
                }
                $labels = (array) ($message['labels'] ?? []);
                $undoMessages[$mid] = [
                    'thread_id' => $tid,
                    'test' => !empty($message['test']),
                    'is_test' => !empty($message['is_test']),
                    'flag_reason' => (string) ($message['flag_reason'] ?? ''),
                    'labels_is_test' => !empty($labels['is_test']),
                    'test_reason' => (string) ($labels['test_reason'] ?? ''),
                    'add_tags' => array_values(array_map('strval', (array) ($labels['add_tags'] ?? []))),
                ];
                jeab_inkorg_update($mid, static function (array $m): array {
                    $m['test'] = true;
                    $m['is_test'] = true;
                    if (trim((string) ($m['flag_reason'] ?? '')) === '') {
                        $m['flag_reason'] = 'mark_test';
                    }
                    $labels = (array) ($m['labels'] ?? []);
                    $labels['is_test'] = true;
                    if (trim((string) ($labels['test_reason'] ?? '')) === '') {
                        $labels['test_reason'] = 'mark_test';
                    }
                    $tags = array_map('strval', (array) ($labels['add_tags'] ?? []));
                    if (!in_array('test', $tags, true)) {
                        $tags[] = 'test';
                    }
                    $labels['add_tags'] = array_values($tags);
                    $m['labels'] = $labels;

                    return $m;
                });
            }
        }
    }
    $undoReminders = [];
    foreach ($reminders as $one) {
        $id = (int) $one['id'];
        $row = null;
        foreach ($reminderRows as $candidate) {
            if ((int) ($candidate['id'] ?? 0) === $id) {
                $row = $candidate;
                break;
            }
        }
        if (!is_array($row) || !function_exists('jeab_store')) {
            continue;
        }
        $tags = function_exists('jeab_parse_tags') ? jeab_parse_tags($row['tags'] ?? []) : [];
        $undoReminders[(string) $id] = ['tags' => $tags];
        if (!in_array('test', $tags, true)) {
            $tags[] = 'test';
        }
        try {
            jeab_store()->saveReminder($id, ['tags' => array_values($tags), 'updated_at' => function_exists('jeab_now') ? jeab_now() : date('c')]);
        } catch (Throwable) {
        }
    }
    $batch = bin2hex(random_bytes(16));
    $stored = jeab_mark_test_undo_load();
    $stored[] = [
        'batch' => $batch,
        'at' => date('c'),
        'threads' => $undoThreads,
        'messages' => $undoMessages,
        'reminders' => $undoReminders,
    ];
    jeab_mark_test_undo_store($stored);

    return $base + [
        'batch' => $batch,
        'threads' => $threads,
        'reminders' => $reminders,
        'missing_threads' => $missingThreads,
        'missing_reminders' => $missingReminders,
        'written' => count($threads) + count($undoReminders),
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inbox_mark_test_undo(array $input, bool $dry): array
{
    $batch = trim((string) ($input['batch'] ?? ''));
    $base = ['ok' => true, 'dry' => $dry ? 1 : 0, 'sent' => 0, 'deleted' => 0, 'undo' => 1];
    if ($batch === '') {
        return ['ok' => false, 'error' => 'batch krävs', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'deleted' => 0];
    }
    $found = null;
    foreach (jeab_mark_test_undo_load() as $row) {
        if ((string) ($row['batch'] ?? '') === $batch) {
            $found = $row;
        }
    }
    if (!is_array($found)) {
        return ['ok' => false, 'error' => 'not_found', 'dry' => $dry ? 1 : 0, 'sent' => 0, 'deleted' => 0, 'batch' => $batch];
    }
    $threadIds = array_keys((array) ($found['threads'] ?? []));
    $reminderIds = array_map('intval', array_keys((array) ($found['reminders'] ?? [])));
    if ($dry) {
        return $base + ['batch' => $batch, 'threads' => $threadIds, 'reminders' => $reminderIds, 'written' => 0];
    }
    if ((string) ($input['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'dry=0 kräver confirm=SAM', 'dry' => 1, 'sent' => 0, 'deleted' => 0, 'batch' => $batch, 'written' => 0];
    }
    foreach ((array) ($found['threads'] ?? []) as $tid => $before) {
        if (!is_array($before) || !function_exists('jeab_inkorg_thread_write')) {
            continue;
        }
        $tid = (string) $tid;
        jeab_inkorg_thread_write($tid, static function (array $t) use ($before): array {
            $t['tags'] = array_values(array_map('strval', (array) ($before['tags'] ?? [])));
            if (!empty($before['test_lead'])) {
                $t['test_lead'] = true;
            } else {
                unset($t['test_lead']);
            }
            if (!empty($before['is_test'])) {
                $t['is_test'] = true;
            } else {
                unset($t['is_test']);
            }
            $t['updated_at'] = date('c');

            return $t;
        });
    }
    foreach ((array) ($found['messages'] ?? []) as $mid => $before) {
        if (!is_array($before) || !function_exists('jeab_inkorg_update')) {
            continue;
        }
        jeab_inkorg_update((string) $mid, static function (array $m) use ($before): array {
            if (!empty($before['test'])) {
                $m['test'] = true;
            } else {
                unset($m['test']);
            }
            if (!empty($before['is_test'])) {
                $m['is_test'] = true;
            } else {
                unset($m['is_test']);
            }
            $flag = (string) ($before['flag_reason'] ?? '');
            if ($flag !== '') {
                $m['flag_reason'] = $flag;
            } else {
                unset($m['flag_reason']);
            }
            $labels = (array) ($m['labels'] ?? []);
            if (!empty($before['labels_is_test'])) {
                $labels['is_test'] = true;
            } else {
                unset($labels['is_test']);
            }
            $reason = (string) ($before['test_reason'] ?? '');
            if ($reason !== '') {
                $labels['test_reason'] = $reason;
            } else {
                unset($labels['test_reason']);
            }
            $labels['add_tags'] = array_values(array_map('strval', (array) ($before['add_tags'] ?? [])));
            $m['labels'] = $labels;

            return $m;
        });
    }
    foreach ((array) ($found['reminders'] ?? []) as $id => $before) {
        if (!is_array($before) || !function_exists('jeab_store')) {
            continue;
        }
        try {
            jeab_store()->saveReminder((int) $id, [
                'tags' => array_values(array_map('strval', (array) ($before['tags'] ?? []))),
                'updated_at' => function_exists('jeab_now') ? jeab_now() : date('c'),
            ]);
        } catch (Throwable) {
        }
    }

    return $base + [
        'batch' => $batch,
        'threads' => $threadIds,
        'reminders' => $reminderIds,
        'written' => count($threadIds) + count($reminderIds),
    ];
}
