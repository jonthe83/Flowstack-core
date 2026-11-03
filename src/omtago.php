<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Omtago-leads. Publik POST /api/omtago-lead.php.
 * Nyckeln ligger utanför webbroten: dirname(JEAB_ROOT)/privat/omtago_api_key.
 * Idempotens och rate-limit ligger i SQL. Inkorgen skrivs bara när inkorg_storage=sql.
 */

function jeab_omtago_key_file(): string
{
    if (!empty($GLOBALS['_JEAB_OMTAGO_KEY_FILE']) && is_string($GLOBALS['_JEAB_OMTAGO_KEY_FILE'])) {
        return $GLOBALS['_JEAB_OMTAGO_KEY_FILE'];
    }
    $root = realpath(JEAB_ROOT);
    if ($root === false) {
        $root = JEAB_ROOT;
    }
    return dirname($root) . '/privat/omtago_api_key';
}

function jeab_omtago_presented_key(array $server): string
{
    $auth = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m) === 1) {
        return (string) $m[1];
    }
    return trim((string) ($server['HTTP_X_API_KEY'] ?? ''));
}

function jeab_omtago_key_ok(string $given): bool
{
    $key = function_exists('jeab_read_token_file') ? jeab_read_token_file(jeab_omtago_key_file()) : '';
    if ($key === '' || $given === '' || strlen($key) !== strlen($given)) {
        return false;
    }
    return hash_equals($key, $given);
}

/** Bara om filen har innehåll. Värdet lämnas aldrig med. */
function jeab_omtago_key_status(): array
{
    $key = function_exists('jeab_read_token_file') ? jeab_read_token_file(jeab_omtago_key_file()) : '';

    return ['exists' => $key !== '', 'length' => $key === '' ? 0 : strlen($key)];
}

function jeab_omtago_rate_max(): int
{
    $n = (int) ($GLOBALS['_JEAB_OMTAGO_RATE_MAX'] ?? 60);
    return $n > 0 ? $n : 60;
}

function jeab_omtago_thread_id(string $leadId): string
{
    return 'om' . substr(hash('sha256', $leadId), 0, 14);
}

function jeab_omtago_rate_upsert_sql(string $driver): string
{
    // hits räknas i SQL. Två anrop som läser samma rad skriver inte samma tal.
    if ($driver === 'mysql') {
        return 'INSERT INTO omtago_rate (ip_hash, window_start, hits) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE
                hits = IF(omtago_rate.window_start <= 0 OR (VALUES(window_start) - omtago_rate.window_start) >= 60, 1, omtago_rate.hits + 1),
                window_start = IF(omtago_rate.window_start <= 0 OR (VALUES(window_start) - omtago_rate.window_start) >= 60, VALUES(window_start), omtago_rate.window_start)';
    }
    return 'INSERT INTO omtago_rate (ip_hash, window_start, hits) VALUES (?, ?, 1)
        ON CONFLICT(ip_hash) DO UPDATE SET
            hits = CASE WHEN omtago_rate.window_start <= 0 OR (excluded.window_start - omtago_rate.window_start) >= 60 THEN 1 ELSE omtago_rate.hits + 1 END,
            window_start = CASE WHEN omtago_rate.window_start <= 0 OR (excluded.window_start - omtago_rate.window_start) >= 60 THEN excluded.window_start ELSE omtago_rate.window_start END';
}

function jeab_omtago_redact_log(string $text): string
{
    $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [nyckel]', $text) ?? $text;
    $key = function_exists('jeab_read_token_file') ? jeab_read_token_file(jeab_omtago_key_file()) : '';
    if ($key !== '' && strlen($key) >= 4) {
        $text = str_replace($key, '[nyckel]', $text);
    }
    if (function_exists('jeab_log_mask_text')) {
        $text = jeab_log_mask_text($text);
    }
    if (strlen($text) > 500) {
        $text = substr($text, 0, 500);
    }
    return $text;
}

function jeab_omtago_log_failure(string $step, ?Throwable $error = null, string $note = ''): void
{
    if (!function_exists('jeab_log_app_error')) {
        return;
    }
    $detail = $note;
    if ($error instanceof Throwable) {
        $detail = $error::class . ': ' . $error->getMessage();
    }
    jeab_log_app_error('omtago', jeab_omtago_redact_log($step . ': ' . $detail));
}

/**
 * @return array{status:int,body:array{ok:bool,error:string}}
 */
function jeab_omtago_unavailable(string $step, ?Throwable $error = null, string $note = ''): array
{
    jeab_omtago_log_failure($step, $error, $note);
    return ['status' => 503, 'body' => ['ok' => false, 'error' => 'Kunde inte ta emot leadet.']];
}

function jeab_omtago_pdo(): ?PDO
{
    if (!function_exists('jeab_lead_sql')) {
        return null;
    }
    $pdo = jeab_lead_sql();
    if (!$pdo instanceof PDO) {
        return null;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_omtago_ensure_tables($pdo, $driver);
    return $pdo;
}

function jeab_omtago_ensure_tables(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS omtago_leads (
                lead_id VARCHAR(191) NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                received_at DATETIME NOT NULL,
                test TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (lead_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS omtago_rate (
                ip_hash CHAR(64) NOT NULL,
                window_start INT NOT NULL,
                hits INT NOT NULL,
                PRIMARY KEY (ip_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS omtago_leads (
            lead_id TEXT PRIMARY KEY,
            thread_id TEXT NOT NULL,
            received_at TEXT NOT NULL,
            test INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS omtago_rate (
            ip_hash TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            hits INTEGER NOT NULL
        )'
    );
}

/** @return array{status:int,body:array{ok:bool,error?:string}}|null null = släpp igenom */
function jeab_omtago_rate_gate(PDO $pdo, string $ip): ?array
{
    $hash = hash('sha256', $ip !== '' ? $ip : 'unknown');
    $now = time();
    $max = jeab_omtago_rate_max();
    try {
        $pdo->beginTransaction();
        $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
        $write = $pdo->prepare(jeab_omtago_rate_upsert_sql($driver));
        $write->execute([$hash, $now]);
        $stmt = $pdo->prepare('SELECT hits FROM omtago_rate WHERE ip_hash = ?');
        $stmt->execute([$hash]);
        $hits = (int) $stmt->fetchColumn();
        $pdo->commit();
        if ($hits > $max) {
            return ['status' => 429, 'body' => ['ok' => false, 'error' => 'För många anrop.']];
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return jeab_omtago_unavailable('rate', $error);
    }
    return null;
}

function jeab_omtago_strip_country(string $value): string
{
    $value = preg_replace('/\s*,?\s*\b(?:sverige|sweden|suede|suède)\b/iu', '', $value) ?? $value;
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return trim($value, " ,");
}

function jeab_omtago_is_country(string $value): bool
{
    return jeab_omtago_strip_country($value) === '' && trim($value) !== '';
}

/**
 * @return array{error:string}|array{lead:array<string,mixed>}
 */
function jeab_omtago_validate(array $input): array
{
    $leadId = $input['lead_id'] ?? null;
    if (!is_string($leadId) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $leadId) !== 1) {
        return ['error' => 'lead_id saknas eller är ogiltigt.'];
    }
    $created = $input['created_at'] ?? null;
    if (!is_string($created) || trim($created) === '' || strtotime($created) === false) {
        return ['error' => 'created_at är inte ett datum.'];
    }
    $leadType = $input['lead_type'] ?? null;
    if (!is_string($leadType) || !in_array($leadType, ['Batteri', 'Solceller'], true)) {
        return ['error' => 'lead_type måste vara Batteri eller Solceller.'];
    }
    $property = $input['property_type'] ?? null;
    if (!is_string($property) || trim($property) === '') {
        return ['error' => 'property_type saknas.'];
    }
    $property = jeab_omtago_strip_country($property);
    if ($property === '' || mb_strlen($property) > 80) {
        return ['error' => 'property_type är ogiltigt.'];
    }
    $contact = $input['contact'] ?? null;
    if (!is_array($contact)) {
        return ['error' => 'contact saknas.'];
    }
    $first = $contact['first_name'] ?? null;
    $last = $contact['last_name'] ?? null;
    $email = $contact['email'] ?? null;
    $phone = $contact['phone'] ?? null;
    if (!is_string($first) || trim($first) === '' || mb_strlen(trim($first)) > 80) {
        return ['error' => 'contact.first_name saknas.'];
    }
    if (!is_string($last) || trim($last) === '' || mb_strlen(trim($last)) > 80) {
        return ['error' => 'contact.last_name saknas.'];
    }
    if (!is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
        return ['error' => 'contact.email är ogiltig.'];
    }
    $email = mb_strtolower(trim($email));
    if (function_exists('jeab_lead_source_blocked') && jeab_lead_source_blocked($email)) {
        return ['error' => 'contact.email är en leadkälla.'];
    }
    if (!is_string($phone) || preg_match_all('/\d/', $phone) < 7 || mb_strlen(trim($phone)) > 40) {
        return ['error' => 'contact.phone är ogiltigt.'];
    }
    $address = $input['address'] ?? null;
    if (!is_array($address)) {
        return ['error' => 'address saknas.'];
    }
    $street = $address['street'] ?? null;
    $city = $address['city'] ?? null;
    $postal = $address['postal_code'] ?? null;
    if (!is_string($street) || trim($street) === '') {
        return ['error' => 'address.street saknas.'];
    }
    if (jeab_omtago_is_country($street)) {
        return ['error' => 'address.street får inte vara Sverige.'];
    }
    $street = jeab_omtago_strip_country($street);
    if (function_exists('jeab_address_strip_leading_filler')) {
        $street = jeab_address_strip_leading_filler($street);
    }
    if ($street === '' || mb_strlen($street) > 120) {
        return ['error' => 'address.street saknas.'];
    }
    if (!is_string($city) || trim($city) === '') {
        return ['error' => 'address.city saknas.'];
    }
    if (jeab_omtago_is_country($city)) {
        return ['error' => 'address.city får inte vara Sverige.'];
    }
    $city = jeab_omtago_strip_country($city);
    if ($city === '' || mb_strlen($city) > 80) {
        return ['error' => 'address.city får inte vara Sverige.'];
    }
    if (!is_string($postal) || preg_match('/^\d{3}\s?\d{2}$/', trim($postal)) !== 1) {
        return ['error' => 'address.postal_code är ogiltigt.'];
    }
    $postal = preg_replace('/\s+/', '', trim($postal)) ?? trim($postal);
    $postal = substr($postal, 0, 3) . ' ' . substr($postal, 3);
    $summary = $input['summary'] ?? null;
    if (!is_string($summary) || mb_strlen($summary) > 4000) {
        return ['error' => 'summary saknas.'];
    }
    $summary = jeab_omtago_strip_country($summary);
    $profileIn = $input['profile'] ?? null;
    if (!is_array($profileIn) || count($profileIn) > 30) {
        return ['error' => 'profile är ogiltigt.'];
    }
    $profile = [];
    foreach ($profileIn as $row) {
        if (!is_array($row)) {
            return ['error' => 'profile är ogiltigt.'];
        }
        $label = $row['label'] ?? null;
        $value = $row['value'] ?? null;
        if (!is_string($label) || !is_string($value)) {
            return ['error' => 'profile är ogiltigt.'];
        }
        if (mb_strlen($label) > 200 || mb_strlen($value) > 200) {
            return ['error' => 'profile är ogiltigt.'];
        }
        $label = jeab_omtago_strip_country($label);
        $value = jeab_omtago_strip_country($value);
        if ($label === '' || $value === '') {
            continue;
        }
        $profile[] = ['label' => $label, 'value' => $value];
    }
    if (!array_key_exists('test', $input) || !is_bool($input['test'])) {
        return ['error' => 'test måste vara bool.'];
    }
    $name = trim(trim($first) . ' ' . trim($last));
    return ['lead' => [
        'lead_id' => $leadId,
        'created_at' => trim($created),
        'lead_type' => $leadType,
        'offer' => $leadType === 'Solceller' ? 'sol' : 'batteri',
        'property_type' => $property,
        'name' => $name,
        'first_name' => trim($first),
        'last_name' => trim($last),
        'email' => $email,
        'phone' => trim($phone),
        'street' => $street,
        'city' => $city,
        'postal_code' => $postal,
        'summary' => $summary,
        'profile' => $profile,
        'test' => $input['test'] === true,
    ]];
}

/** @param array<string,mixed> $lead */
function jeab_omtago_body(array $lead): string
{
    $lines = [
        'Källa: Omtago',
        'Lead-id: ' . $lead['lead_id'],
        'Skapad: ' . $lead['created_at'],
        'Typ: ' . $lead['lead_type'],
        'Fastighet: ' . $lead['property_type'],
        'Kund: ' . $lead['name'] . ' · ' . $lead['email'] . ' · ' . $lead['phone'],
        'Namn: ' . $lead['name'],
        'E-post: ' . $lead['email'],
        'Telefon: ' . $lead['phone'],
        'Adress: ' . $lead['street'],
        'Postnummer: ' . $lead['postal_code'],
        'Ort: ' . $lead['city'],
        $lead['street'] . ', ' . $lead['postal_code'] . ' ' . $lead['city'],
        'Sammanfattning: ' . $lead['summary'],
    ];
    $profile = $lead['profile'];
    if (is_array($profile) && $profile !== []) {
        $lines[] = 'Profil:';
        foreach ($profile as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = (string) $row['label'] . ': ' . (string) $row['value'];
        }
    }
    $text = implode("\n", $lines);
    return jeab_omtago_strip_country($text);
}

/** @param array<string,mixed> $msg */
function jeab_omtago_labels(array $msg, string $offer): array
{
    $probe = function_exists('jeab_test_lead_fields') ? jeab_test_lead_fields($msg) : ['test' => !empty($msg['is_test'])];
    $classified = [];
    if (empty($probe['test']) && function_exists('jeab_inkorg_classify')) {
        $classified = jeab_inkorg_classify($msg);
    }
    $product = $offer === 'sol' ? 'solceller' : 'batteri';
    $labels = is_array($classified) ? $classified : [];
    $labels['type'] = 'Ny lead';
    $labels['action'] = jeab_inkorg_derived_action('Ny lead');
    $labels['priority'] = jeab_inkorg_default_priority('Ny lead');
    $labels['intent'] = 'kop';
    $labels['offer'] = $offer;
    $labels['product'] = $product;
    $labels['confidence'] = 100;
    $labels['question'] = '';
    unset($labels['lead'], $labels['lead_sender']);
    if (!empty($probe['test']) && function_exists('jeab_test_lead_log')) {
        jeab_test_lead_log('offert', (string) ($probe['reason'] ?? 'test'), (string) ($probe['email'] ?? ''), (string) ($probe['phone'] ?? ''));
    }
    if (!empty($probe['test']) || !empty($msg['is_test'])) {
        $labels['is_test'] = true;
        $labels['test_reason'] = (string) ($probe['reason'] ?? 'testflagga');
        $labels['add_tags'] = array_values(array_unique(array_merge((array) ($labels['add_tags'] ?? []), ['test'])));
    }
    if ((string) ($classified['source'] ?? '') === 'rule') {
        $labels['source'] = 'rule';
        $labels['rule_id'] = (string) ($classified['rule_id'] ?? '');
        $reason = trim((string) ($classified['reason'] ?? ''));
        $labels['reason'] = $reason !== '' ? $reason : 'Omtago';
    } else {
        $labels['source'] = 'omtago';
        $labels['reason'] = 'Omtago';
    }
    $primary = $offer === 'sol' ? 'Sol' : 'Batteri';
    $labels['case_class'] = [
        'is_lead_seller' => false,
        'seller_name' => '',
        'customer_email' => (string) ($msg['from'] ?? ''),
        'customer_name' => (string) ($msg['from_name'] ?? ''),
        'intent' => 'kopintresse',
        'primary_product' => $primary,
        'secondary_products' => [],
        'kind' => 'atgard',
        'type' => 'Ny lead',
        'label' => '',
        'confidence' => [
            'is_lead_seller' => 1.0,
            'seller_name' => 1.0,
            'customer_email' => 1.0,
            'customer_name' => 1.0,
            'intent' => 1.0,
            'primary_product' => 1.0,
            'kind' => 1.0,
            'type' => 1.0,
            'label' => 1.0,
        ],
    ];
    return function_exists('jeab_inkorg_finish_labels') ? jeab_inkorg_finish_labels($labels, $msg) : $labels;
}

/** @return array{thread_id:string,test:int}|null */
function jeab_omtago_find(PDO $pdo, string $leadId): ?array
{
    $stmt = $pdo->prepare('SELECT thread_id, test FROM omtago_leads WHERE lead_id = ?');
    $stmt->execute([$leadId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return ['thread_id' => (string) ($row['thread_id'] ?? ''), 'test' => (int) ($row['test'] ?? 0)];
}

function jeab_omtago_insert(PDO $pdo, string $leadId, string $threadId, bool $test): bool
{
    $stmt = $pdo->prepare('INSERT INTO omtago_leads (lead_id, thread_id, received_at, test) VALUES (?, ?, ?, ?)');
    try {
        $stmt->execute([$leadId, $threadId, date('c'), $test ? 1 : 0]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array{email:string,name:string,phone:string} $contact
 */
function jeab_omtago_follow(string $messageId, string $threadId, array $contact): void
{
    if (function_exists('jeab_inkorg_thread_write')) {
        jeab_inkorg_thread_write($threadId, static function (array $t) use ($contact): array {
            $t['lead_source'] = 'omtago';
            $linked = function_exists('jeab_contacts_inbox_hold') && jeab_contacts_inbox_hold()
                && trim((string) ($t['contact_id'] ?? '')) !== '';
            if (!$linked) {
                $t['customer_email'] = $contact['email'];
                $t['customer_name'] = $contact['name'];
                $t['customer_source'] = 'hook';
            }
            return $t;
        });
    }
    if (function_exists('jeab_lead_party_remember')) {
        jeab_lead_party_remember($threadId, $contact['email'], $contact['name'], $contact['phone'], 'omtago');
    }
    $ranAuto = false;
    $testContact = false;
    if (function_exists('jeab_test_lead_match')) {
        $testContact = jeab_test_lead_match([
            'email' => (string) ($contact['email'] ?? ''),
            'name' => (string) ($contact['name'] ?? ''),
            'phone' => (string) ($contact['phone'] ?? ''),
            'is_test' => !empty($contact['is_test']),
            'flag_reason' => !empty($contact['is_test']) ? 'testflagga' : '',
        ])['test'];
    }
    if (!$testContact && $messageId !== '' && function_exists('jeab_inkorg_get') && function_exists('jeab_test_lead_fields')) {
        $storedMsg = jeab_inkorg_get($messageId);
        if (is_array($storedMsg)) {
            $testContact = jeab_test_lead_fields($storedMsg)['test'];
        }
    }
    if ($testContact && function_exists('jeab_auto_on_message')) {
        jeab_auto_on_message($messageId);
        return;
    }
    if (function_exists('jeab_rebook_on_message')) {
        $rebook = jeab_rebook_on_message($messageId);
        $action = is_array($rebook) ? (string) ($rebook['action'] ?? '') : '';
        if (!in_array($action, ['moved', 'offered', 'hold'], true) && function_exists('jeab_auto_on_message')) {
            jeab_auto_on_message($messageId);
            $ranAuto = true;
        }
    }
    if (!$ranAuto && function_exists('jeab_auto_on_message')) {
        jeab_auto_on_message($messageId);
    }
}

/**
 * @param array<string,mixed> $lead
 * @return array{status:int,body:array<string,mixed>}
 */
function jeab_omtago_accept(PDO $pdo, array $lead): array
{
    $leadId = (string) $lead['lead_id'];
    $known = jeab_omtago_find($pdo, $leadId);
    if ($known !== null && $known['thread_id'] !== '') {
        return ['status' => 200, 'body' => ['ok' => true, 'id' => $known['thread_id'], 'lead_id' => $leadId]];
    }
    $threadId = jeab_omtago_thread_id($leadId);
    $probe = function_exists('jeab_test_lead_match') ? jeab_test_lead_match([
        'email' => (string) ($lead['email'] ?? ''),
        'name' => (string) ($lead['name'] ?? ''),
        'phone' => (string) ($lead['phone'] ?? ''),
        'is_test' => !empty($lead['test']),
        'flag_reason' => 'testflagga',
    ]) : ['test' => !empty($lead['test']), 'reason' => 'testflagga', 'email' => '', 'phone' => ''];
    if (!function_exists('jeab_inkorg_use_sql') || !jeab_inkorg_use_sql()) {
        return ['status' => 503, 'body' => ['ok' => false, 'error' => 'Inkorgen är inte i SQL.']];
    }
    $body = jeab_omtago_body($lead);
    if (preg_match('/sverige|sweden/iu', $body) === 1) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'address.city får inte vara Sverige.']];
    }
    $offer = (string) $lead['offer'];
    $msg = [
        'from' => (string) $lead['email'],
        'from_name' => (string) $lead['name'],
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Ny lead',
        'body' => $body,
        'source' => 'omtago',
        'company' => 'jarfalla',
        'is_test' => !empty($probe['test']),
        'flag_reason' => (string) ($probe['reason'] ?? ''),
    ];
    $labels = jeab_omtago_labels($msg, $offer);
    $messageId = 'omtago-' . $leadId . '@lead.jeab';
    $res = jeab_inkorg_ingest([
        'message_id' => $messageId,
        'from' => (string) $lead['email'],
        'from_name' => (string) $lead['name'],
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Ny lead',
        'date' => (string) $lead['created_at'],
        'received_at' => (string) $lead['created_at'],
        'body_text' => $body,
        'source' => 'omtago',
        'company' => 'jarfalla',
        'thread_key' => $threadId,
        'preset_labels' => $labels,
    ]);
    if (empty($res['ok'])) {
        return ['status' => 424, 'body' => ['ok' => false, 'error' => 'Kunde inte ta emot leadet.']];
    }
    if (function_exists('jeab_contacts_sole_inflow_upsert')) {
        try {
            jeab_contacts_sole_inflow_upsert([
                'name' => (string) ($lead['name'] ?? ''),
                'email' => (string) ($lead['email'] ?? ''),
                'phone' => (string) ($lead['phone'] ?? ''),
                'street' => (string) ($lead['street'] ?? ''),
                'city' => (string) ($lead['city'] ?? ''),
                'postal_code' => (string) ($lead['postal_code'] ?? ''),
                'address' => trim((string) ($lead['street'] ?? '') . ', ' . trim((string) ($lead['postal_code'] ?? '') . ' ' . (string) ($lead['city'] ?? ''))),
                'company' => 'jarfalla',
            ]);
        } catch (Throwable) {
        }
    }
    $storedId = (string) ($res['thread_id'] ?? '');
    if ($storedId === '') {
        $storedId = $threadId;
    }
    $messageStored = (string) ($res['id'] ?? '');
    try {
        jeab_omtago_follow($messageStored, $storedId, [
            'email' => (string) $lead['email'],
            'name' => (string) $lead['name'],
            'phone' => (string) $lead['phone'],
            'is_test' => !empty($probe['test']),
        ]);
    } catch (Throwable) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('omtago', 'Lead togs emot men automatiken stoppades.');
        }
    }
    if (!jeab_omtago_insert($pdo, $leadId, $storedId, !empty($probe['test']))) {
        $again = jeab_omtago_find($pdo, $leadId);
        if ($again === null || $again['thread_id'] === '') {
            return ['status' => 503, 'body' => ['ok' => false, 'error' => 'Kunde inte ta emot leadet.']];
        }
        $storedId = $again['thread_id'];
    }
    return ['status' => 200, 'body' => ['ok' => true, 'id' => $storedId, 'lead_id' => $leadId]];
}

/**
 * @param array<string,mixed> $server
 * @return array{status:int,body:array<string,mixed>}
 */
function jeab_omtago_handle(string $method, string $raw, array $server): array
{
    if (strtoupper($method) !== 'POST') {
        return ['status' => 405, 'body' => ['ok' => false, 'error' => 'Använd POST.']];
    }
    if (strlen($raw) > 65536) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'Payload är för stor.']];
    }
    // Nyckeln läses från fil, inte från SQL. Fel nyckel får aldrig bli 503.
    if (!jeab_omtago_key_ok(jeab_omtago_presented_key($server))) {
        return ['status' => 401, 'body' => ['ok' => false, 'error' => 'Fel nyckel.']];
    }
    if (function_exists('jeab_boot')) {
        try {
            jeab_boot();
        } catch (Throwable $error) {
            return jeab_omtago_unavailable('boot', $error);
        }
    }
    try {
        $pdo = jeab_omtago_pdo();
    } catch (Throwable $error) {
        return jeab_omtago_unavailable('pdo', $error);
    }
    if (!$pdo instanceof PDO) {
        return jeab_omtago_unavailable('pdo', null, 'Ingen SQL-anslutning.');
    }
    $limited = jeab_omtago_rate_gate($pdo, trim((string) ($server['REMOTE_ADDR'] ?? '')));
    if ($limited !== null) {
        return $limited;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'JSON kunde inte läsas.']];
    }
    $checked = jeab_omtago_validate($decoded);
    if (isset($checked['error'])) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => (string) $checked['error']]];
    }
    return jeab_omtago_accept($pdo, $checked['lead']);
}

function jeab_omtago_hook_authorized(): bool
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

function jeab_omtago_hook_id(array $input): string
{
    $lead = trim((string) ($input['lead_id'] ?? ''));
    if ($lead !== '') {
        return $lead;
    }

    return trim((string) ($input['id'] ?? ''));
}

/**
 * @return array{lead_id:string,thread_id:string,row_test:bool}
 */
function jeab_omtago_lead_row(string $id): array
{
    $empty = ['lead_id' => '', 'thread_id' => '', 'row_test' => false];
    if ($id === '') {
        return $empty;
    }
    try {
        $pdo = jeab_omtago_pdo();
    } catch (Throwable) {
        return $empty;
    }
    if (!$pdo instanceof PDO) {
        return $empty;
    }
    $stmt = $pdo->prepare('SELECT lead_id, thread_id, test FROM omtago_leads WHERE lead_id = ? OR thread_id = ? LIMIT 1');
    $stmt->execute([$id, $id]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return $empty;
    }

    return [
        'lead_id' => (string) ($row['lead_id'] ?? ''),
        'thread_id' => (string) ($row['thread_id'] ?? ''),
        'row_test' => (int) ($row['test'] ?? 0) === 1,
    ];
}

/** @param array<string,mixed> $thread */
function jeab_omtago_thread_is_test(array $thread, bool $rowTest): bool
{
    if ($rowTest || !empty($thread['test_lead'])) {
        return true;
    }
    foreach ((array) ($thread['tags'] ?? []) as $tag) {
        if ((string) $tag === 'test') {
            return true;
        }
    }
    $labels = (array) ($thread['labels'] ?? []);
    $first = (array) ($thread['first'] ?? []);
    if ($labels === [] && isset($first['labels']) && is_array($first['labels'])) {
        $labels = $first['labels'];
    }
    if (!empty($labels['is_test']) || !empty($first['is_test'])) {
        return true;
    }
    if (!function_exists('jeab_test_lead_strict')) {
        return false;
    }
    $emails = [];
    if (!function_exists('jeab_contacts_inbox_first_text')) {
        require_once __DIR__ . '/contacts_strict.php';
    }
    $storedEmail = jeab_contacts_inbox_first_text(
        jeab_contacts_inbox_contact_email($thread),
        (string) ($thread['customer_email'] ?? '')
    );
    foreach ([(string) ($first['from'] ?? ''), $storedEmail] as $email) {
        if (trim($email) !== '') {
            $emails[] = $email;
        }
    }
    $strict = jeab_test_lead_strict([
        'emails' => $emails,
        'is_test' => !empty($labels['is_test']) || !empty($first['is_test']),
        'flag_reason' => (string) ($first['flag_reason'] ?? $labels['test_reason'] ?? ''),
    ]);

    return !empty($strict['test']);
}

/** @param array<string,mixed> $thread */
function jeab_omtago_list_status(string $tid, array $thread): string
{
    if ($tid !== '') {
        try {
            $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
            if ($store instanceof JeabPdoInkorgStore) {
                $stmt = $store->pdo()->prepare('SELECT list_status FROM inkorg_list WHERE id = ?');
                $stmt->execute([mb_substr($tid, 0, 191)]);
                $col = $stmt->fetchColumn();
                if (is_string($col) && trim($col) !== '') {
                    return trim($col);
                }
            }
        } catch (Throwable) {
        }
    }
    if (!empty($thread['deleted'])) {
        return 'deleted';
    }
    $status = trim((string) ($thread['status'] ?? ''));

    return $status === '' ? 'open' : $status;
}

/**
 * Läser tråden från SQL när inkorgen ligger där, annars från den vanliga trådlistan.
 *
 * @return array<string,mixed>|null
 */
function jeab_omtago_thread_fresh(string $tid): ?array
{
    if ($tid === '') {
        return null;
    }
    $store = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if ($store instanceof JeabPdoInkorgStore && function_exists('jeab_inkorg_thread_row')) {
        $meta = (array) ($store->threadsByIds([$tid])[$tid] ?? []);
        $msgs = $store->findMessagesByThread($tid);
        if ($meta !== [] || $msgs !== []) {
            usort($msgs, static fn(array $a, array $b): int => strcmp((string) ($a['received_at'] ?? ''), (string) ($b['received_at'] ?? '')));

            return jeab_inkorg_thread_row($tid, $msgs, $meta);
        }
    }
    if (function_exists('jeab_auto_thread_by_id')) {
        $thread = jeab_auto_thread_by_id($tid);

        return is_array($thread) ? $thread : null;
    }

    return null;
}

/**
 * Bara ärendefält. Inget namn, mejl, telefon eller brödtext.
 *
 * @return array<string,mixed>
 */
function jeab_omtago_lead_view(string $id): array
{
    $id = trim($id);
    if ($id === '') {
        return ['ok' => false, 'error' => 'id saknas', 'http_status' => 400];
    }
    $row = jeab_omtago_lead_row($id);
    $tid = $row['thread_id'] !== '' ? $row['thread_id'] : $id;
    $thread = jeab_omtago_thread_fresh($tid);
    if (!is_array($thread)) {
        return ['ok' => false, 'error' => 'not_found', 'http_status' => 404];
    }
    $tid = (string) ($thread['id'] ?? $tid);
    $test = jeab_omtago_thread_is_test($thread, $row['row_test']);
    $threadStatus = trim((string) ($thread['status'] ?? ''));
    if ($threadStatus === '') {
        $threadStatus = 'open';
    }
    $listStatus = jeab_omtago_list_status($tid, $thread);
    $historik = in_array($listStatus, ['closed', 'deleted'], true);
    if (!$historik && function_exists('jeab_inkorg_in_historik')) {
        $historik = jeab_inkorg_in_historik($thread);
    } elseif (!$historik) {
        $historik = $threadStatus === 'closed' || !empty($thread['deleted']);
    }
    $tags = [];
    foreach ((array) ($thread['tags'] ?? []) as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '') {
            $tags[$tag] = true;
        }
    }
    $tagList = array_keys($tags);
    sort($tagList, SORT_STRING);
    $company = function_exists('jeab_inkorg_thread_company')
        ? (string) jeab_inkorg_thread_company($thread)
        : (string) ($thread['company'] ?? '');
    $view = [
        'ok' => true,
        'http_status' => 200,
        'thread_id' => $tid,
        'test' => $test,
        'status' => $threadStatus,
        'folder' => $historik ? 'historik' : 'inkorg',
        'list_status' => $listStatus,
        'tags' => $tagList,
        'company' => $company,
        'historik' => $historik,
    ];
    if ($row['lead_id'] !== '') {
        $view['lead_id'] = $row['lead_id'];
    }

    return $view;
}

function jeab_omtago_reopen_log(string $tid, string $leadId, bool $dry, string $result): void
{
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
    }
    if (!$pdo instanceof PDO) {
        return;
    }
    try {
        jeab_log_ensure($pdo);
    } catch (Throwable) {
        return;
    }
    $payload = [
        'at' => date('c'),
        'thread_id' => mb_substr(trim($tid), 0, 80),
        'lead_id' => mb_substr(trim($leadId), 0, 80),
        'dry' => $dry ? 1 : 0,
        'result' => mb_substr(trim($result), 0, 40),
        'nonce' => bin2hex(random_bytes(4)),
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    try {
        jeab_log_line_insert($pdo, 'omtago_reopen', hash('sha256', $line), (string) $payload['at'], $line);
    } catch (Throwable) {
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_omtago_lead_status_dispatch(array $input): array
{
    if (!jeab_omtago_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'http_status' => 401];
    }

    return jeab_omtago_lead_view(jeab_omtago_hook_id($input));
}

/**
 * Läser bara om privat/omtago_api_key finns och hur lång den är.
 *
 * @return array<string,mixed>
 */
function jeab_omtago_key_status_dispatch(): array
{
    if (!jeab_omtago_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'http_status' => 401];
    }
    $st = jeab_omtago_key_status();

    return ['exists' => $st['exists'] === true, 'length' => (int) $st['length'], 'http_status' => 200];
}

/**
 * Öppnar ett testlead som ligger i Historik. dry=1 är standard. Skickar inget.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_omtago_reopen_test_dispatch(array $input): array
{
    if (!jeab_omtago_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'http_status' => 401];
    }
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($input) : !array_key_exists('dry', $input);
    $view = jeab_omtago_lead_view(jeab_omtago_hook_id($input));
    if (empty($view['ok'])) {
        return $view;
    }
    $tid = (string) ($view['thread_id'] ?? '');
    $leadId = (string) ($view['lead_id'] ?? '');
    $view['dry'] = $dry ? 1 : 0;
    $view['reopened'] = false;
    if (empty($view['test'])) {
        $view['ok'] = false;
        $view['error'] = 'inte ett testlead';
        $view['http_status'] = 409;
        jeab_omtago_reopen_log($tid, $leadId, $dry, 'inte test');

        return $view;
    }
    if (empty($view['historik'])) {
        $view['reason'] = 'redan i inkorgen';
        jeab_omtago_reopen_log($tid, $leadId, $dry, 'redan inkorg');

        return $view;
    }
    if ($dry) {
        $view['would_status'] = 'open';
        $view['would_folder'] = 'inkorg';
        jeab_omtago_reopen_log($tid, $leadId, true, 'dry');

        return $view;
    }
    if ($tid === '' || !function_exists('jeab_inkorg_thread_write')) {
        $view['ok'] = false;
        $view['error'] = 'not_found';
        $view['http_status'] = 404;

        return $view;
    }
    try {
        jeab_inkorg_thread_write($tid, static function (array $t): array {
            $t['status'] = 'open';
            unset($t['deleted_at'], $t['deleted_by']);
            $t['hook_reopened_at'] = date('c');
            $t['updated_at'] = date('c');

            return $t;
        });
    } catch (Throwable) {
        $view['ok'] = false;
        $view['error'] = 'reopen_failed';
        $view['http_status'] = 500;
        jeab_omtago_reopen_log($tid, $leadId, false, 'fel');

        return $view;
    }
    $fresh = jeab_omtago_lead_view($tid);
    $fresh['dry'] = 0;
    $fresh['reopened'] = !empty($fresh['ok']) && empty($fresh['historik']);
    jeab_omtago_reopen_log($tid, $leadId, false, !empty($fresh['reopened']) ? 'oppnat' : 'kvar');

    return $fresh;
}

function jeab_omtago_http(): void
{
    $raw = isset($GLOBALS['_JEAB_RAW_BODY']) && is_string($GLOBALS['_JEAB_RAW_BODY'])
        ? $GLOBALS['_JEAB_RAW_BODY']
        : (string) (file_get_contents('php://input') ?: '');
    $res = jeab_omtago_handle((string) ($_SERVER['REQUEST_METHOD'] ?? ''), $raw, $_SERVER);
    http_response_code($res['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($res['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
