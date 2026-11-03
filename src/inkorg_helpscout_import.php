<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/hs_import_store.php';

/*
 * Help Scout → Inkorgen. Bara GET mot api.helpscout.net (OAuth-token hämtas av
 * jeab_helpscout_token() i sms.php, ingen skrivning till konversationer).
 *
 * Nycklar på servern, filen data/.private/helpscout.json (samma fil som övrig
 * Help Scout-konfig, aldrig i git):
 *   client_id              string   OAuth2 client_credentials
 *   client_secret          string   OAuth2 client_credentials
 *   mailbox_id             int      Järfälla
 *   mailbox_id_malmo       int      Malmö (alias: meab_mailbox_id, mailbox_malmo, mailboxes.malmo)
 *   import_per_minute      int      valfri, standard 200, tak 200
 *   import_budget_seconds  int      valfri, standard 20 (5–60)
 *   import_disk_min_bytes  int      valfri, standard 500 MiB. 0 stänger av den gränsen
 *   import_disk_min_ratio  float    valfri, standard 0.10. 0 stänger av den gränsen
 *   import_supplier_domains list    domäner som aldrig blir en kontakt
 *   import_keep_domains    list     domäner som inte auto-blockeras som leverantör
 *   import_supplier_domain_min int  standard 6 konversationer med olika ämnen
 *
 * Importerade ärenden skrivs i SQL, inte i inkorgens JSON-filer.
 * Arkivet ligger i SQL (jeab_hs_import_index, jeab_hs_import_conv,
 * jeab_hs_import_state). index.jsonl, konversationsfilerna och
 * helpscout-import.json läses bara som reserv tills hs_archive.import.
 * Listan läser bara indexet. Ingen .prev.
 * Hook (POST, samma Bearer som inbox.*): helpscout.import, helpscout.import_status,
 * helpscout.reprocess, system.disk. dry=1 är standard. mailbox=jarfalla|malmo|all. limit, since=1.
 * inbox.import_helpscout med ids (Help Scout-id eller ärendenummer) importerar bara de,
 * rör inte checkpoint eller watermark, och svarar med tråd-id och status.
 * ids tar limit (standard 5, tak 20) och svarar med pending för resten, så ett anrop
 * inte går över webbserverns tidsgräns. dry=1 sätter would_send false på varje id.
 * promote=1 lyfter en ren hsi-kopia till en levande hs-<id>-tråd (status promoted).
 * inbox.find_by_hs är bara läsning och slår upp samma id eller nummer, även hsi-kopior,
 * och svarar med kind hsi eller hs.
 * helpscout.reprocess tar limit (standard 200, tak 500) och offset, och svarar med next_offset.
 * inbox.reindex_dates (i inkorg-hooken) skriver om listdatum. dry=1, limit och offset.
 * Låset helpscout-import.lock delas med inbox.malmo_hs_sync. En andra körning får busy.
 */

const JEAB_HS_IMPORT_SOURCE = 'helpscout_import';
const JEAB_HS_IMPORT_MAX_BYTES = 26214400;

function jeab_hs_import_frozen(array $row): bool
{
    if ((string) ($row['source'] ?? '') === JEAB_HS_IMPORT_SOURCE) {
        return true;
    }
    if ((string) ($row['import_source'] ?? '') === JEAB_HS_IMPORT_SOURCE) {
        return true;
    }
    foreach ((array) ($row['messages'] ?? []) as $m) {
        if (is_array($m) && (string) ($m['source'] ?? '') === JEAB_HS_IMPORT_SOURCE) {
            return true;
        }
    }
    return false;
}

/** Ingen offert, kvittens eller annat utgående mejl. Klassning tittar inte på flaggan. */
function jeab_hs_import_no_auto(array $row): bool
{
    if (!empty($row['no_auto'])) {
        return true;
    }
    if (is_array($row['meta'] ?? null) && !empty($row['meta']['no_auto'])) {
        return true;
    }
    if (is_array($row['labels'] ?? null) && !empty($row['labels']['no_auto'])) {
        return true;
    }
    return false;
}

function jeab_hs_import_rpm(): int
{
    $n = (int) (jeab_helpscout_config()['import_per_minute'] ?? 200);
    if ($n < 1) {
        $n = 200;
    }
    return min(200, $n);
}

function jeab_hs_import_budget(array $in): int
{
    if (array_key_exists('budget', $in)) {
        return max(1, min(60, (int) $in['budget']));
    }
    $n = (int) (jeab_helpscout_config()['import_budget_seconds'] ?? 20);
    if ($n < 1) {
        $n = 20;
    }
    return max(5, min(60, $n));
}

function jeab_hs_import_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }
    $v = $in['dry'];
    return !($v === 0 || $v === '0' || $v === false);
}

function jeab_hs_import_sleep(float $seconds): void
{
    if ($seconds <= 0) {
        return;
    }
    if (isset($GLOBALS['_JEAB_HS_IMPORT_SLEEP']) && is_callable($GLOBALS['_JEAB_HS_IMPORT_SLEEP'])) {
        ($GLOBALS['_JEAB_HS_IMPORT_SLEEP'])($seconds);
        return;
    }
    usleep((int) round($seconds * 1_000_000));
}

function jeab_hs_import_throttle(): void
{
    $gap = 60 / jeab_hs_import_rpm();
    $now = microtime(true);
    $next = (float) ($GLOBALS['_JEAB_HS_IMPORT_NEXT_AT'] ?? 0);
    if ($now < $next) {
        jeab_hs_import_sleep($next - $now);
        $now = microtime(true);
    }
    $GLOBALS['_JEAB_HS_IMPORT_NEXT_AT'] = $now + $gap;
}

/** @param list<string> $headers */
function jeab_hs_import_retry_after(array $headers, int $attempt): float
{
    foreach ($headers as $line) {
        if (!preg_match('/^retry-after:\s*(.+)$/i', trim($line), $m)) {
            continue;
        }
        $raw = trim($m[1]);
        if (ctype_digit($raw)) {
            return min(60.0, max(1.0, (float) $raw));
        }
        $when = strtotime($raw);
        if ($when !== false) {
            return min(60.0, max(1.0, (float) ($when - time())));
        }
    }
    return min(60.0, (float) (2 ** max(0, $attempt - 1)));
}

function jeab_hs_import_token(): ?string
{
    if (isset($GLOBALS['_JEAB_HS_IMPORT_HTTP'])) {
        return 'test';
    }
    static $token;
    if (is_string($token) && $token !== '') {
        return $token;
    }
    $token = function_exists('jeab_helpscout_token') ? jeab_helpscout_token() : null;
    return is_string($token) && $token !== '' ? $token : null;
}

/**
 * Bara GET. Kastar om någon ber om en skrivmetod.
 *
 * @return array{code: int, headers: list<string>, body: string}
 */
function jeab_hs_import_http(string $method, string $pathOrUrl, array $query = []): array
{
    if (strtoupper($method) !== 'GET') {
        throw new RuntimeException('hs_skriv');
    }
    $url = str_starts_with($pathOrUrl, 'https://')
        ? $pathOrUrl
        : 'https://api.helpscout.net/v2/' . ltrim($pathOrUrl, '/');
    if ((string) parse_url($url, PHP_URL_HOST) !== 'api.helpscout.net') {
        throw new RuntimeException('hs_vard');
    }
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $attempt = 0;
    while (true) {
        jeab_hs_import_throttle();
        $res = jeab_hs_import_http_once($url);
        if ((int) $res['code'] !== 429 || $attempt >= 4) {
            return $res;
        }
        $attempt++;
        jeab_hs_import_sleep(jeab_hs_import_retry_after($res['headers'], $attempt));
    }
}

/** @return array{code: int, headers: list<string>, body: string} */
function jeab_hs_import_http_once(string $url): array
{
    if (isset($GLOBALS['_JEAB_HS_IMPORT_HTTP']) && is_callable($GLOBALS['_JEAB_HS_IMPORT_HTTP'])) {
        $res = ($GLOBALS['_JEAB_HS_IMPORT_HTTP'])('GET', $url);
        $res = is_array($res) ? $res : [];
        return [
            'code' => (int) ($res['code'] ?? 0),
            'headers' => array_values(array_map('strval', (array) ($res['headers'] ?? []))),
            'body' => (string) ($res['body'] ?? ''),
        ];
    }
    $token = jeab_hs_import_token();
    if ($token === null) {
        return ['code' => 0, 'headers' => [], 'body' => ''];
    }
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', $url)) {
        return ['code' => 200, 'headers' => [], 'body' => '{}', 'demo' => true];
    }
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json, */*;q=0.1'],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            unset($ch);
            $headers[] = $line;
            return strlen($line);
        },
    ]);
    if (function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = is_string($raw) ? $raw : '';
    if (strlen($body) > JEAB_HS_IMPORT_MAX_BYTES) {
        return ['code' => 413, 'headers' => $headers, 'body' => ''];
    }
    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

/** @return array<string, mixed> */
function jeab_hs_import_get_json(string $path, array $query = []): array
{
    $res = jeab_hs_import_http('GET', $path, $query);
    $code = (int) $res['code'];
    if ($code !== 200) {
        throw new RuntimeException('hs_http_' . $code);
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException('hs_json');
    }
    return $json;
}

function jeab_hs_import_checkpoint_update(callable $fn): mixed
{
    $d = jeab_hs_import_checkpoint();
    $out = $fn($d);
    if (!is_array($d)) {
        return null;
    }
    $d['mailboxes'] = is_array($d['mailboxes'] ?? null) ? $d['mailboxes'] : [];
    $d['customers'] = is_array($d['customers'] ?? null) ? $d['customers'] : [];
    if (!jeab_hs_import_store_checkpoint_put($d)) {
        return null;
    }
    return $out;
}

/** @param array<string, mixed> $d @return array<string, mixed> */
function jeab_hs_import_checkpoint_normalize(array $d): array
{
    $d['mailboxes'] = is_array($d['mailboxes'] ?? null) ? $d['mailboxes'] : [];
    $d['customers'] = is_array($d['customers'] ?? null) ? $d['customers'] : [];
    return $d;
}

/** @return array<string, mixed> */
function jeab_hs_import_checkpoint(): array
{
    if (function_exists('jeab_remain_sql_mode') && jeab_remain_sql_mode('helpscout_import') === 'json') {
        $path = jeab_inkorg_dir() . '/helpscout-import.json';
        $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return jeab_hs_import_checkpoint_normalize(is_array($d) ? $d : []);
    }
    $sql = jeab_hs_import_store_checkpoint_get();
    if (is_array($sql)) {
        return jeab_hs_import_checkpoint_normalize($sql);
    }
    if (jeab_hs_import_store_is_imported()) {
        return jeab_hs_import_checkpoint_normalize([]);
    }
    $path = jeab_inkorg_dir() . '/helpscout-import.json';
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return jeab_hs_import_checkpoint_normalize(is_array($d) ? $d : []);
}

/** @param array<string, mixed> $cp */
function jeab_hs_import_note_error(array &$cp, string $company, string $code): void
{
    if (!isset($cp['mailboxes'][$company]) || !is_array($cp['mailboxes'][$company])) {
        $cp['mailboxes'][$company] = [];
    }
    $box = &$cp['mailboxes'][$company];
    $box['errors'] = array_values((array) ($box['errors'] ?? []));
    $box['errors'][] = $company . ': ' . $code;
    $box['errors'] = array_slice($box['errors'], -20);
    $box['error_count'] = (int) ($box['error_count'] ?? 0) + 1;
}

function jeab_hs_import_safe_error(Throwable $e): string
{
    $msg = $e->getMessage();
    if (preg_match('/^hs_http_\d+$/', $msg) === 1 || in_array($msg, ['hs_json', 'hs_skriv', 'hs_vard'], true)) {
        return $msg;
    }
    return 'hs_fel';
}

/** @return array<string, int> */
function jeab_hs_import_targets(string $which): array
{
    $which = in_array($which, ['jarfalla', 'malmo', 'all'], true) ? $which : 'all';
    $names = $which === 'all' ? ['jarfalla', 'malmo'] : [$which];
    $out = [];
    foreach ($names as $name) {
        $id = function_exists('jeab_helpscout_mailbox_id') ? jeab_helpscout_mailbox_id($name) : null;
        if ($id !== null && $id > 0) {
            $out[$name] = $id;
        }
    }
    return $out;
}

function jeab_hs_import_thread_key(string $conversationId): string
{
    return 'hsi-' . substr(hash('sha256', 'hs-conv:' . $conversationId), 0, 16);
}

function jeab_hs_import_message_id(string $conversationId, string $threadId): string
{
    return 'hs-' . $conversationId . '-' . $threadId . '@helpscout.net';
}

function jeab_hs_import_status_name(string $hs): string
{
    return match ($hs) {
        'closed', 'spam' => 'closed',
        'pending' => 'waiting_customer',
        default => 'open',
    };
}

/**
 * Etiketter och tilldelad från Help Scout, när datan räcker.
 * Köpord fyller produktetiketten. Typen sätts inte om till Ny lead.
 *
 * @param array<string, mixed> $conversation
 * @return array{tags: list<string>, assignee: string, type: string}
 */
function jeab_hs_import_classify(array $conversation): array
{
    $tags = [];
    $rawTags = $conversation['tags'] ?? ($conversation['_embedded']['tags'] ?? []);
    if (is_array($rawTags)) {
        foreach ($rawTags as $tag) {
            $name = is_array($tag) ? (string) ($tag['tag'] ?? $tag['name'] ?? '') : (string) $tag;
            $fold = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($name) : mb_strtolower($name);
            if ($fold === '') {
                continue;
            }
            if (str_contains($fold, 'sol')) {
                $tags[] = 'sol';
            }
            if (str_contains($fold, 'batteri')) {
                $tags[] = 'batteri';
            }
            if (str_contains($fold, 'elbil') || str_contains($fold, 'laddbox') || str_contains($fold, 'laddare')) {
                $tags[] = 'elbilsladdare';
            }
        }
    }
    $tags = array_values(array_unique($tags));
    $purchaseMsg = function_exists('jeab_inkorg_purchase_msg_from_hs') ? jeab_inkorg_purchase_msg_from_hs($conversation) : [
        'subject' => (string) ($conversation['subject'] ?? ''),
        'body' => trim((string) ($conversation['subject'] ?? '') . "\n" . (string) ($conversation['preview'] ?? '') . "\n" . (string) ($conversation['jeab_text'] ?? '')),
    ];
    $assignee = '';
    $who = $conversation['assignee'] ?? $conversation['assignedTo'] ?? null;
    if (is_array($who) && function_exists('jeab_people')) {
        $email = mb_strtolower(trim((string) ($who['email'] ?? '')));
        $name = trim((string) ($who['firstName'] ?? $who['first'] ?? '') . ' ' . (string) ($who['lastName'] ?? $who['last'] ?? ''));
        foreach (jeab_people() as $person) {
            if (!is_array($person)) {
                continue;
            }
            $pid = (string) ($person['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            if ($email !== '' && strcasecmp((string) ($person['email'] ?? ''), $email) === 0) {
                $assignee = $pid;
                break;
            }
            if ($name !== '' && strcasecmp(trim((string) ($person['name'] ?? '')), $name) === 0) {
                $assignee = $pid;
                break;
            }
        }
    }
    if (function_exists('jeab_inkorg_purchase_blocked') && jeab_inkorg_purchase_blocked($purchaseMsg) !== '') {
        return ['tags' => [], 'assignee' => $assignee, 'type' => 'Information'];
    }
    $form = function_exists('jeab_inkorg_form_product') ? jeab_inkorg_form_product($purchaseMsg) : '';
    if ($form !== '') {
        $tag = ['sol' => 'sol', 'batteri' => 'batteri', 'laddbox' => 'elbilsladdare'][$form] ?? '';
        $tags = $tag !== '' ? [$tag] : [];
    } elseif ($tags === [] && function_exists('jeab_inkorg_purchase_offer')) {
        $offer = jeab_inkorg_purchase_offer($purchaseMsg);
        $tag = ['sol' => 'sol', 'batteri' => 'batteri', 'laddbox' => 'elbilsladdare'][$offer] ?? '';
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }
    return ['tags' => $tags, 'assignee' => $assignee, 'type' => 'Information'];
}

/** @param array<string, mixed> $customer @param array<string, mixed> $primary */
function jeab_hs_import_customer_hint(array $customer, array $primary): array
{
    $email = trim((string) ($customer['email'] ?? ''));
    if ($email === '') {
        foreach ((array) ($customer['_embedded']['emails'] ?? $customer['emails'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['value'] ?? '')) !== '') {
                $email = trim((string) $row['value']);
                break;
            }
        }
    }
    if ($email === '') {
        $email = trim((string) ($primary['email'] ?? ''));
    }
    $first = trim((string) ($customer['firstName'] ?? $customer['first'] ?? $primary['first'] ?? ''));
    $last = trim((string) ($customer['lastName'] ?? $customer['last'] ?? $primary['last'] ?? ''));
    $phone = '';
    foreach ((array) ($customer['_embedded']['phones'] ?? $customer['phones'] ?? []) as $row) {
        if (is_array($row) && trim((string) ($row['value'] ?? '')) !== '') {
            $phone = trim((string) $row['value']);
            break;
        }
    }
    $addr = (array) ($customer['_embedded']['address'] ?? $customer['address'] ?? []);
    $lines = $addr['lines'] ?? [];
    if (is_string($lines)) {
        $lines = [$lines];
    }
    $street = trim(implode(' ', array_map('strval', is_array($lines) ? $lines : [])));
    $city = trim((string) ($addr['city'] ?? ''));
    $postal = trim((string) ($addr['postalCode'] ?? $addr['postal_code'] ?? ''));
    $address = $street;
    $tail = trim($postal . ' ' . $city);
    if ($tail !== '') {
        $address = trim($address . ', ' . $tail, ', ');
    }
    return [
        'name' => trim($first . ' ' . $last),
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'street' => $street,
        'city' => $city,
        'postal_code' => $postal,
    ];
}

/**
 * Help Scout-kunden är ofta portalen. När From är partner eller no-reply
 * och brödtexten har en annan adress blir den kundens e-post.
 *
 * @param array<string, mixed> $hint
 * @return array<string, mixed>
 */
function jeab_hs_import_prefer_body_customer(array $hint): array
{
    $from = mb_strtolower(trim((string) ($hint['email'] ?? '')));
    $partner = function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($from);
    $blocked = function_exists('jeab_inkorg_address_blocked') && $from !== '' && jeab_inkorg_address_blocked($from);
    $notify = function_exists('jeab_lead_notify_local') && jeab_lead_notify_local($from);
    if (!$partner && !$blocked && !$notify) {
        return $hint;
    }
    $msg = [
        'from' => $from,
        'from_name' => (string) ($hint['name'] ?? ''),
        'subject' => (string) ($hint['subject'] ?? ''),
        'body' => (string) ($hint['body'] ?? ''),
    ];
    $resolved = function_exists('jeab_inkorg_resolve_customer')
        ? jeab_inkorg_resolve_customer($msg)
        : ['email' => '', 'name' => '', 'phone' => '', 'address' => ''];
    $email = mb_strtolower(trim((string) ($resolved['email'] ?? '')));
    $stillPartner = $email !== '' && function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($email);
    if ($email === '' || $stillPartner || ($blocked && function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email))) {
        if ($notify && !$partner && !$blocked) {
            return $hint;
        }
        $hint['email'] = '';
        return $hint;
    }
    $hint['email'] = $email;
    $name = trim((string) ($resolved['name'] ?? ''));
    if ($name !== '') {
        $hint['name'] = $name;
    }
    if (trim((string) ($hint['phone'] ?? '')) === '' && trim((string) ($resolved['phone'] ?? '')) !== '') {
        $hint['phone'] = (string) $resolved['phone'];
    }
    if (trim((string) ($hint['address'] ?? '')) === '' && trim((string) ($resolved['address'] ?? '')) !== '') {
        $hint['address'] = (string) $resolved['address'];
    }
    return $hint;
}

/** Tar bort citattecken, vänder «Efternamn, Förnamn» och skalar av en ort. Orten ligger i city. */
function jeab_hs_import_normalize_name(string $name): string
{
    return jeab_kontakt_normalize_person_name($name)['name'];
}

function jeab_hs_import_noreply_text(string $value): bool
{
    $fold = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($value) : mb_strtolower($value);
    return $fold !== '' && preg_match('/no-?reply|donotreply|do-not-reply/', $fold) === 1;
}

/**
 * Namnet är bara local-delen («kund42», «kund.exempel») eller innehåller siffror.
 * Ett riktigt namn med mellanslag lämnas kvar även när det liknar adressen.
 */
function jeab_hs_import_name_unusable(string $name, string $email): bool
{
    $name = trim($name);
    if ($name === '') {
        return true;
    }
    if (preg_match('/\d/u', $name) === 1) {
        return true;
    }
    if (preg_match('/\s/u', $name) === 1 || !str_contains($email, '@')) {
        return false;
    }
    $local = (string) strstr($email, '@', true);
    $foldName = preg_replace('/[^a-z0-9]+/u', '', jeab_kontakt_fold_text($name)) ?? '';
    $foldLocal = preg_replace('/[^a-z0-9]+/u', '', jeab_kontakt_fold_text($local)) ?? '';
    if ($foldName !== '' && $foldName === $foldLocal) {
        return true;
    }
    if (!jeab_hs_import_local_is_mail_label($email)) {
        return false;
    }
    $foldHead = preg_replace('/[^a-z0-9]+/u', '', jeab_kontakt_fold_text(explode('.', $local, 2)[0])) ?? '';
    return $foldName !== '' && $foldName === $foldHead;
}

/** Support, Invoice, Network, AB och liknande är en leverantör, inte en kund. Samfällighet och BRF är kunder. */
function jeab_hs_import_supplier_name(string $name): bool
{
    $name = trim($name);
    if ($name === '' || jeab_kontakt_customer_company_name($name)) {
        return false;
    }
    $fold = jeab_kontakt_fold_text($name);
    if (preg_match('/\b(?:support|invoice|network|faktura|leverantor|supplier|company|bolag|foretag)\b/u', $fold) === 1) {
        return true;
    }
    foreach (jeab_kontakt_name_tokens($name) as $token) {
        if (in_array($token, ['ab', 'hb'], true)) {
            return true;
        }
        if (strlen($token) >= 7 && preg_match('/(?:concept|group|support|invoice|network|faktura)$/', $token) === 1) {
            return true;
        }
    }
    return false;
}

function jeab_hs_import_name_rejected(string $name): bool
{
    $name = jeab_kontakt_normalize_person_name($name)['name'];
    if ($name === '') {
        return false;
    }
    return jeab_kontakt_platform_name($name)
        || jeab_kontakt_nonperson_name($name)
        || jeab_kontakt_name_is_garbage($name)
        || jeab_kontakt_name_is_test_booking($name)
        || jeab_kontakt_has_test_word($name)
        || jeab_hs_import_supplier_name($name)
        || jeab_hs_import_noreply_text($name);
}

/** @return list<string> */
function jeab_hs_import_domain_list(string $key): array
{
    $cfg = function_exists('jeab_helpscout_config') ? jeab_helpscout_config() : [];
    $raw = $cfg[$key] ?? [];
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $dom) {
        $dom = mb_strtolower(trim((string) $dom));
        $dom = ltrim($dom, '@');
        if ($dom !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom) === 1) {
            $out[] = $dom;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Konsumentpost. En avsändare här med ett riktigt kundmejl blir alltid en kontakt,
 * även när namnet bara är local-delen. Egna bolagsdomäner ingår inte.
 *
 * @return list<string>
 */
function jeab_hs_import_consumer_domains(): array
{
    $base = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.se', 'outlook.com', 'outlook.se',
        'live.com', 'live.se', 'icloud.com', 'me.com', 'mac.com', 'yahoo.com', 'yahoo.se',
        'telia.com', 'telia.se', 'spray.se', 'comhem.se', 'bredband.net', 'bredband.com',
        'msn.com', 'proton.me', 'protonmail.com', 'pm.me',
    ];
    return array_values(array_unique(array_merge($base, jeab_hs_import_domain_list('import_consumer_domains'))));
}

function jeab_hs_import_consumer_domain(string $email): bool
{
    $dom = jeab_hs_import_email_domain($email);
    if ($dom === '') {
        return false;
    }
    foreach (jeab_hs_import_consumer_domains() as $keep) {
        if ($dom === $keep || str_ends_with($dom, '.' . $keep)) {
            return true;
        }
    }
    return false;
}

/** Kundens egen text. Citerade rader och svarshuvuden räknas inte, så en fot i citatet inte fäller mejlet. */
function jeab_hs_import_customer_text(string $body): string
{
    $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
    $own = [];
    foreach ($lines as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        if (str_starts_with($trim, '>')) {
            continue;
        }
        if (function_exists('jeab_inkorg_clean_quote_header') && jeab_inkorg_clean_quote_header($trim)) {
            continue;
        }
        $own[] = $trim;
    }
    return trim(implode("\n", $own));
}

function jeab_hs_import_body_is_auto(string $body): bool
{
    $text = jeab_hs_import_customer_text($body);
    $fold = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($text) : mb_strtolower($text);
    if ($fold === '') {
        return false;
    }
    return preg_match('/automatiskt meddelande|automatiskt genererat|auto-?submitted|do not reply|detta ar ett automatiskt|out of office|franvaroassistent/u', $fold) === 1;
}

/** Minst ett kundmejl som inte är en anteckning, ett utskick, no-reply eller ett automatiskt svar. */
function jeab_hs_import_message_is_real_customer(array $m): bool
{
    if (!empty($m['internal_note'])) {
        return false;
    }
    $type = (string) ($m['hs_thread_type'] ?? $m['type'] ?? '');
    if (in_array($type, ['note', 'message', 'reply'], true)) {
        return false;
    }
    $from = trim((string) ($m['from'] ?? ''));
    if ($from === '' && is_array($m['customer'] ?? null)) {
        $from = trim((string) ($m['customer']['email'] ?? ''));
    }
    if ($from === '' && is_array($m['createdBy'] ?? null)) {
        $from = trim((string) ($m['createdBy']['email'] ?? ''));
    }
    if ($from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
        return false;
    }
    if (jeab_hs_import_noreply_text($from) || jeab_hs_import_noreply_text((string) ($m['from_name'] ?? ''))) {
        return false;
    }
    $body = (string) ($m['body'] ?? $m['body_text'] ?? '');
    if (jeab_hs_import_body_is_auto($body)) {
        return false;
    }
    if ($type !== '' && !in_array($type, ['customer', 'phone', 'chat', 'beaconchat', 'in'], true)) {
        return false;
    }
    return trim(strip_tags($body)) !== '' || $from !== '';
}

/** @param array<string, mixed> $hint */
function jeab_hs_import_real_customer(array $hint): bool
{
    if (array_key_exists('real_customer', $hint)) {
        return (bool) $hint['real_customer'];
    }
    $body = trim((string) ($hint['body'] ?? ''));
    if ($body === '' || jeab_hs_import_body_is_auto($body)) {
        return false;
    }
    return !jeab_hs_import_noreply_text((string) ($hint['email'] ?? ''));
}

function jeab_hs_import_local_has_digit(string $email): bool
{
    $local = str_contains($email, '@') ? (string) strstr($email, '@', true) : $email;
    return preg_match('/\d/u', $local) === 1;
}

/** Egen eller testadress: «products» eller «sam» i local-delen. */
function jeab_hs_import_local_blocked(string $email): bool
{
    $local = str_contains($email, '@') ? (string) strstr($email, '@', true) : $email;
    $fold = function_exists('jeab_kontakt_fold_text') ? jeab_kontakt_fold_text($local) : mb_strtolower($local);
    return $fold !== '' && preg_match('/products|sam/', $fold) === 1;
}

/** Local-delen är bara ett mejlnamn, till exempel «exempelmail». Då saknas ett personnamn. */
function jeab_hs_import_local_is_mail_label(string $email): bool
{
    $local = str_contains($email, '@') ? (string) strstr($email, '@', true) : $email;
    $head = explode('.', $local, 2)[0];
    $fold = preg_replace('/[^a-z0-9]+/u', '', jeab_kontakt_fold_text($head)) ?? '';
    return strlen($fold) > 4 && str_ends_with($fold, 'mail');
}

function jeab_hs_import_name_is_email(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
        return true;
    }
    return preg_match('/\s/u', $name) !== 1 && str_contains($name, '@');
}

/** Ett riktigt personnamn efter normalisering. En adress eller ett avvisat namn är inte det. */
function jeab_hs_import_name_is_person(string $name): bool
{
    $name = trim($name);
    if ($name === '' || jeab_hs_import_name_is_email($name)) {
        return false;
    }
    $norm = jeab_kontakt_normalize_person_name($name)['name'];
    return $norm !== '' && !jeab_hs_import_name_rejected($norm);
}

/**
 * Trådens avsändare vinner när kundprofilen är tom, en e-postadress eller ett avvisat namn.
 * «Efternamn, Förnamn» på tråden ska inte tappas för att profilen bara har adressen.
 */
function jeab_hs_import_choose_display(string $profile, string $author): string
{
    $author = trim($author);
    $profile = trim($profile);
    if (jeab_hs_import_name_is_person($author) && !jeab_hs_import_name_is_person($profile)) {
        return $author;
    }
    if (jeab_hs_import_name_is_person($profile)) {
        return $profile;
    }
    return $author !== '' ? $author : $profile;
}

/**
 * @param array<string, mixed> $actor
 * @return array{first:string,last:string,display:string}
 */
function jeab_hs_import_actor_names(array $actor): array
{
    $pick = static function (array $keys) use ($actor): string {
        foreach ($keys as $key) {
            $value = trim((string) ($actor[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };
    $first = $pick(['firstName', 'first']);
    $last = $pick(['lastName', 'last']);
    $display = $pick(['displayName', 'display', 'name']);
    if ($display === '') {
        $display = trim($first . ' ' . $last);
    }
    return ['first' => $first, 'last' => $last, 'display' => $display];
}

/** Bokstäver i local-delen, varje del med stor begynnelsebokstav. Siffror ger tom sträng. */
function jeab_hs_import_capitalise_local(string $email): string
{
    $local = str_contains($email, '@') ? (string) strstr($email, '@', true) : $email;
    if (preg_match('/\d/u', $local) === 1) {
        return '';
    }
    $parts = preg_split('/[^\p{L}]+/u', $local) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = (string) $part;
        if ($part === '') {
            continue;
        }
        $out[] = mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1);
    }
    return trim(implode(' ', $out));
}

/** Konsumentpost och egna domäner auto-blockeras inte. Listan kan utökas i helpscout.json. */
function jeab_hs_import_keep_domains(): array
{
    $base = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.se', 'outlook.com', 'outlook.se',
        'live.com', 'live.se', 'icloud.com', 'me.com', 'yahoo.com', 'yahoo.se', 'telia.com',
        'spray.se', 'comhem.se', 'bredband.net', 'msn.com', 'proton.me', 'protonmail.com',
        'jarfalla.example.test', 'malmo.example.test', 'flowstack.example.test',
    ];
    return array_values(array_unique(array_merge($base, jeab_hs_import_domain_list('import_keep_domains'))));
}

function jeab_hs_import_domain_min(): int
{
    $n = (int) ((function_exists('jeab_helpscout_config') ? jeab_helpscout_config() : [])['import_supplier_domain_min'] ?? 6);
    return $n < 2 ? 6 : $n;
}

function jeab_hs_import_email_domain(string $email): string
{
    $email = jeab_kontakt_email($email);
    if (!str_contains($email, '@')) {
        return '';
    }
    return mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1));
}

/**
 * Antal ärenden och olika ämnen per avsändardomän. Cachas på indexfilens storlek och tid.
 *
 * @return array<string, array{n: int, subjects: array<string, true>}>
 */
function jeab_hs_import_domain_stats(): array
{
    $path = jeab_hs_import_root() . '/index.jsonl';
    $fileStamp = is_file($path) ? (string) filesize($path) . ':' . (string) filemtime($path) : '0';
    $stamp = $fileStamp . ':' . (string) ($GLOBALS['_JEAB_HS_IMPORT_INDEX_GEN'] ?? 0);
    $cached = $GLOBALS['_JEAB_HS_DOMAIN_STATS'] ?? null;
    if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp && is_array($cached['rows'] ?? null)) {
        return $cached['rows'];
    }
    $stats = [];
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $dom = jeab_hs_import_email_domain((string) ($row['from'] ?? ''));
        if ($dom === '') {
            continue;
        }
        if (!isset($stats[$dom])) {
            $stats[$dom] = ['n' => 0, 'subjects' => [], 'emails' => []];
        }
        $stats[$dom]['n']++;
        $fromEmail = jeab_kontakt_email((string) ($row['from'] ?? ''));
        if ($fromEmail !== '') {
            $stats[$dom]['emails'][$fromEmail] = true;
        }
        $subject = mb_strtolower(trim((string) ($row['subject'] ?? '')));
        if ($subject !== '') {
            $stats[$dom]['subjects'][$subject] = true;
        }
    }
    $GLOBALS['_JEAB_HS_DOMAIN_STATS'] = ['stamp' => $stamp, 'rows' => $stats];
    return $stats;
}

/**
 * Avsändare som redan är kund i jeab: lead (produkt på tråden), offert, påminnelse eller order.
 *
 * @return array{lead:array<string,true>,quote:array<string,true>,reminder:array<string,true>,order:array<string,true>}
 */
function jeab_hs_import_customer_ties(): array
{
    $reminderN = 0;
    $orderN = 0;
    $messageN = 0;
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if ($store instanceof JeabStore) {
        $reminderN = count($store->listReminders(true));
    }
    if (function_exists('jeab_purchase_all')) {
        $orderN = count(jeab_purchase_all());
    }
    if (function_exists('jeab_inkorg_messages')) {
        $messageN = count(jeab_inkorg_messages());
    }
    $threadStamp = function_exists('jeab_inkorg_threads_fp') ? jeab_inkorg_threads_fp() : '0';
    $fp = $reminderN . ':' . $orderN . ':' . $messageN . ':' . $threadStamp;
    $cached = $GLOBALS['_JEAB_HS_CUSTOMER_TIES'] ?? null;
    if (is_array($cached) && ($cached['fp'] ?? '') === $fp && is_array($cached['rows'] ?? null)) {
        return $cached['rows'];
    }
    $lead = [];
    $quote = [];
    $reminder = [];
    $order = [];
    $add = static function (string $email, array &$into): void {
        $email = jeab_kontakt_email($email);
        if ($email !== '') {
            $into[$email] = true;
        }
    };
    if ($store instanceof JeabStore) {
        foreach ($store->listReminders(true) as $row) {
            if (is_array($row)) {
                $add((string) ($row['email'] ?? ''), $reminder);
            }
        }
    }
    if (function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (is_array($row)) {
                $add((string) ($row['email'] ?? ''), $order);
            }
        }
    }
    if (function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            $from = '';
            foreach ((array) ($thread['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }
                $candidate = jeab_kontakt_email((string) ($message['from'] ?? ''));
                if ($candidate === '' || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($candidate))) {
                    continue;
                }
                $from = $candidate;
                break;
            }
            if ($from === '') {
                continue;
            }
            $labels = is_array($thread['labels'] ?? null) ? $thread['labels'] : [];
            $product = trim((string) ($labels['product'] ?? ''));
            if ($product !== '' || (string) ($labels['type'] ?? '') === 'Ny lead') {
                $lead[$from] = true;
            }
            $mark = is_array($thread['quote_sent'] ?? null) ? $thread['quote_sent'] : [];
            if (trim((string) ($mark['source'] ?? '')) !== '' || trim((string) ($mark['date'] ?? '')) !== '') {
                $quote[$from] = true;
            }
        }
    }
    $rows = ['lead' => $lead, 'quote' => $quote, 'reminder' => $reminder, 'order' => $order];
    $GLOBALS['_JEAB_HS_CUSTOMER_TIES'] = ['fp' => $fp, 'rows' => $rows];
    return $rows;
}

function jeab_hs_import_sender_has_record(string $email): bool
{
    $email = jeab_kontakt_email($email);
    if ($email === '') {
        return false;
    }
    $ties = jeab_hs_import_customer_ties();
    return isset($ties['lead'][$email]) || isset($ties['quote'][$email]) || isset($ties['reminder'][$email]) || isset($ties['order'][$email]);
}

/** @param array<string, mixed> $emails */
function jeab_hs_import_domain_has_lead(array $emails): bool
{
    $ties = jeab_hs_import_customer_ties();
    foreach ($emails as $email => $_) {
        $email = jeab_kontakt_email((string) $email);
        if ($email !== '' && (isset($ties['lead'][$email]) || isset($ties['reminder'][$email]))) {
            return true;
        }
    }
    return false;
}

/**
 * Leverantör bara om domänen står i listan, eller om samma domän har minst
 * import_supplier_domain_min ärenden med olika ämnen och ingen avsändare har lead eller påminnelse.
 * import_keep_domains auto-blockeras inte. En avsändare med lead, offert, påminnelse eller order behålls.
 */
function jeab_hs_import_domain_is_supplier(string $email, string $subject, bool $includeCurrent): bool
{
    $dom = jeab_hs_import_email_domain($email);
    if ($dom === '') {
        return false;
    }
    if (jeab_hs_import_sender_has_record($email)) {
        return false;
    }
    $explicit = false;
    foreach (jeab_hs_import_domain_list('import_supplier_domains') as $blocked) {
        if ($dom === $blocked || str_ends_with($dom, '.' . $blocked)) {
            $explicit = true;
            break;
        }
    }
    if ($explicit) {
        return true;
    }
    foreach (jeab_hs_import_keep_domains() as $keep) {
        if ($dom === $keep || str_ends_with($dom, '.' . $keep)) {
            return false;
        }
    }
    $stats = jeab_hs_import_domain_stats()[$dom] ?? ['n' => 0, 'subjects' => [], 'emails' => []];
    $n = (int) ($stats['n'] ?? 0);
    $subjects = is_array($stats['subjects'] ?? null) ? $stats['subjects'] : [];
    $emails = is_array($stats['emails'] ?? null) ? $stats['emails'] : [];
    if ($includeCurrent) {
        $n++;
        $subject = mb_strtolower(trim($subject));
        if ($subject !== '') {
            $subjects[$subject] = true;
        }
        $current = jeab_kontakt_email($email);
        if ($current !== '') {
            $emails[$current] = true;
        }
    }
    if ($n < jeab_hs_import_domain_min() || count($subjects) < 2) {
        return false;
    }
    return !jeab_hs_import_domain_has_lead($emails);
}

/** Signaturnamn. Local-delen i versaler används inte. */
function jeab_hs_import_signature_name(string $body, string $email): string
{
    if ($body === '' || $email === '' || !function_exists('jeab_customer_signature_name')) {
        return '';
    }
    $sig = trim(jeab_customer_signature_name($body, $email));
    if ($sig === '' || jeab_hs_import_name_unusable($sig, $email) || jeab_hs_import_name_rejected($sig)) {
        return '';
    }
    return $sig;
}

/** Samma e-post är samma kontakt, även när gatan eller namnet skiljer. */
function jeab_hs_import_find_by_email(string $email): ?string
{
    $email = jeab_kontakt_email($email);
    if ($email === '' || jeab_kontakt_address_blocked($email) || jeab_kontakt_test_email($email)) {
        return null;
    }
    if (function_exists('jeab_kontakt_email_is_staff_or_platform') && jeab_kontakt_email_is_staff_or_platform($email)) {
        return null;
    }
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
            if (is_array($owner) && jeab_kontakt_email((string) ($owner['email'] ?? '')) === $email) {
                return (string) $id;
            }
        }
        if (function_exists('jeab_kontakt_secondary_list') && in_array($email, jeab_kontakt_secondary_list($row), true)) {
            return (string) $id;
        }
    }
    return null;
}

/**
 * Samma filter som kontakter.link, plus no-reply, leverantör och namnnormalisering.
 * Hoppade avsändare får ingen kontakt. Ärendet importeras ändå.
 *
 * @return array{action: string, id: string, name: string, shown: string, hint: array<string, mixed>}
 */
function jeab_hs_import_contact_plan(array $hint): array
{
    $parsed = jeab_kontakt_parse_address((string) ($hint['address'] ?? ''));
    if ($parsed['street'] === '' && trim((string) ($hint['street'] ?? '')) !== '') {
        $parsed['street'] = trim((string) $hint['street']);
        $parsed['street_key'] = jeab_kontakt_street_key($parsed['street']);
    }
    if ($parsed['postal'] === '' && trim((string) ($hint['postal_code'] ?? '')) !== '') {
        $parsed['postal'] = jeab_kontakt_postal((string) $hint['postal_code']);
    }
    if ($parsed['city'] === '') {
        $parsed['city'] = jeab_kontakt_city_name(trim((string) ($hint['city'] ?? '')));
    }
    $emailRaw = trim((string) ($hint['email'] ?? ''));
    $email = jeab_kontakt_email($emailRaw);
    $knownCity = $parsed['city'] !== '' ? $parsed['city'] : trim((string) ($hint['city'] ?? ''));
    // Namnet normaliseras innan skräp-, test- och leverantörsfiltren.
    $norm = jeab_kontakt_normalize_person_name((string) ($hint['name'] ?? ''), $knownCity);
    $rawName = (string) $norm['name'];
    if ($parsed['city'] === '' && (string) $norm['city'] !== '') {
        $parsed['city'] = jeab_kontakt_city_name((string) $norm['city']);
    }
    $shown = $rawName;
    $skip = static function (string $filter, string $reason) use (&$shown): array {
        return ['action' => 'skip', 'id' => '', 'name' => '', 'shown' => $shown, 'hint' => [], 'filter' => $filter, 'reason' => $reason];
    };
    if (jeab_kontakt_address_blocked($email) || jeab_kontakt_test_email($email) || jeab_hs_import_noreply_text($emailRaw) || jeab_hs_import_noreply_text($email)) {
        $email = '';
    }
    $phone = jeab_kontakt_phone((string) ($hint['phone'] ?? ''));
    if (jeab_kontakt_phone_blocked($phone)) {
        $phone = '';
    }
    $subject = (string) ($hint['subject'] ?? '');
    $includeCurrent = empty($hint['indexed']);
    $emailForName = $email !== '' ? $email : $emailRaw;
    $rawIsEmail = jeab_hs_import_name_is_email($rawName);
    if (jeab_hs_import_local_blocked($emailRaw)) {
        return $skip('test', 'lokaldelen är ett eget eller testkonto');
    }
    if (jeab_hs_import_noreply_text($rawName) || ($email === '' && jeab_hs_import_noreply_text($emailRaw))) {
        return $skip('no-reply', 'avsändaren är no-reply');
    }
    if (!$rawIsEmail && $rawName !== '') {
        if (jeab_kontakt_has_test_word($rawName) || jeab_kontakt_name_is_test_booking($rawName)) {
            return $skip('test', 'namnet är testdata');
        }
        if (jeab_kontakt_platform_name($rawName)) {
            return $skip('platform', 'namnet hör till en plattform');
        }
        if (jeab_kontakt_name_is_garbage($rawName)) {
            return $skip('junk', 'namnet är skräp');
        }
        if (jeab_hs_import_supplier_name($rawName) || jeab_kontakt_nonperson_name($rawName)) {
            return $skip('supplier', 'namnet är en leverantör');
        }
    }
    if (jeab_kontakt_source_is_staff(['name' => $rawName, 'email' => $emailForName, 'phone' => $phone])) {
        return $skip('staff', 'avsändaren är personal');
    }
    if ($email === '' && jeab_kontakt_test_email(jeab_kontakt_email($emailRaw))) {
        return $skip('test', 'adressen är testdata');
    }
    if ($email === '' && jeab_kontakt_address_blocked(jeab_kontakt_email($emailRaw))) {
        return $skip('supplier', 'domänen är blockerad');
    }
    if (jeab_hs_import_domain_is_supplier($emailRaw, $subject, $includeCurrent)) {
        return $skip('supplier', 'domänen är en leverantör');
    }
    if (jeab_kontakt_is_internal_place($parsed['street_key'], $parsed['city']) || jeab_kontakt_is_po_box($parsed['street'])) {
        return $skip('junk', 'adressen är intern eller en box');
    }
    $name = $rawName;
    $missing = false;
    if ($rawIsEmail || jeab_hs_import_name_unusable($name, $emailForName)) {
        $sig = jeab_hs_import_signature_name((string) ($hint['body'] ?? ''), $emailForName);
        if ($sig !== '') {
            $name = $sig;
            $shown = $sig;
        } elseif (jeab_hs_import_consumer_domain($emailForName) && jeab_hs_import_real_customer($hint) && $email !== '') {
            $mailLabel = jeab_hs_import_local_is_mail_label($emailForName);
            if ($mailLabel || jeab_hs_import_local_has_digit($emailForName) || preg_match('/\d/u', $name) === 1) {
                $name = $email;
                $shown = $email;
                $missing = true;
            } else {
                $pretty = jeab_hs_import_capitalise_local($emailForName);
                $name = $pretty !== '' ? $pretty : $rawName;
                $shown = $name;
            }
        } else {
            $name = '';
            $shown = '';
        }
    }
    if ($name === '' && $parsed['street_key'] === '' && $phone === '') {
        return $skip($rawIsEmail || $rawName === '' ? 'junk' : 'junk', 'namnet kan inte användas');
    }
    $kind = jeab_kontakt_company_name($name) ? 'company' : 'person';
    $hintOut = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'street' => $parsed['street'],
        'street_key' => $parsed['street_key'],
        'postal_code' => $parsed['postal'],
        'city' => $parsed['city'],
        'kind' => $kind,
        'name_missing' => $missing,
    ];
    if ($email !== '') {
        $byEmail = jeab_hs_import_find_by_email($email);
        if ($byEmail !== null) {
            return ['action' => 'merge', 'id' => $byEmail, 'name' => $name, 'shown' => $shown, 'hint' => $hintOut, 'filter' => 'merged into ' . $byEmail, 'reason' => 'samma e-post'];
        }
    } else {
        $id = jeab_kontakt_find(jeab_kontakter_load(), $parsed['street_key'], $parsed['postal'], '', $phone, true, $name, (string) ($parsed['city'] ?? ''));
        if ($id !== null) {
            return ['action' => 'merge', 'id' => $id, 'name' => $name, 'shown' => $shown, 'hint' => $hintOut, 'filter' => 'merged into ' . $id, 'reason' => 'samma namn och kanal'];
        }
    }
    if ($parsed['street_key'] === '' && $email === '' && $phone === '') {
        return $skip('junk', 'ingen e-post, telefon eller gata');
    }
    if (function_exists('jeab_kontakt_dedupe_match_contact')) {
        $match = jeab_kontakt_dedupe_match_contact(jeab_kontakter_load(), $hintOut);
        if ($match !== null) {
            return ['action' => 'merge', 'id' => $match, 'name' => $name, 'shown' => $shown, 'hint' => $hintOut, 'filter' => 'merged into ' . $match, 'reason' => 'samma adress'];
        }
    }
    return ['action' => 'create', 'id' => '', 'name' => $name, 'shown' => $shown, 'hint' => $hintOut, 'filter' => 'kept', 'reason' => $missing ? 'namn saknas, konsumenten behålls' : 'ny kontakt'];
}

/**
 * @param array<string, mixed> $hint
 * @param array<string, mixed> $cp
 * @return array{action: string, id: string, name: string, shown: string}
 */
function jeab_hs_import_contact_apply(array $hint, string $customerId, bool $dry, array &$cp): array
{
    $apply = static function () use ($hint, $customerId, $dry, &$cp): array {
        return jeab_hs_import_contact_apply_body($hint, $customerId, $dry, $cp);
    };
    if (function_exists('jeab_kontakt_quiet_call')) {
        return jeab_kontakt_quiet_call($apply);
    }
    return $apply();
}

/**
 * @param array<string, mixed> $hint
 * @param array<string, mixed> $cp
 * @return array{action: string, id: string, name: string, shown: string}
 */
function jeab_hs_import_contact_apply_body(array $hint, string $customerId, bool $dry, array &$cp): array
{
    $plan = jeab_hs_import_contact_plan($hint);
    if ($plan['action'] === 'skip') {
        return $plan;
    }
    if ($dry) {
        return $plan;
    }
    $id = (string) $plan['id'];
    if ($id === '' && $customerId !== '' && isset($cp['customers'][$customerId]) && is_string($cp['customers'][$customerId])) {
        $cached = $cp['customers'][$customerId];
        if ($cached !== '' && isset(jeab_kontakter_load()[$cached])) {
            $id = $cached;
            $plan['action'] = 'merge';
        }
    }
    if ($id === '') {
        $hintOut = is_array($plan['hint'] ?? null) ? $plan['hint'] : [];
        $hintOut['source'] = 'hs-import';
        $created = false;
        $row = jeab_kontakter_mutate(function (array &$contacts) use ($hintOut, &$created): ?array {
            $email = (string) ($hintOut['email'] ?? '');
            if ($email !== '' && !(function_exists('jeab_kontakt_email_is_staff_or_platform') && jeab_kontakt_email_is_staff_or_platform($email))) {
                foreach ($contacts as $cid => $existing) {
                    if (!is_array($existing)) {
                        continue;
                    }
                    foreach (is_array($existing['owners'] ?? null) ? $existing['owners'] : [] as $owner) {
                        if (is_array($owner) && jeab_kontakt_email((string) ($owner['email'] ?? '')) === $email) {
                            $existing['id'] = (string) $cid;
                            return $existing;
                        }
                    }
                }
            }
            if (function_exists('jeab_kontakt_dedupe_match_contact')) {
                $match = jeab_kontakt_dedupe_match_contact($contacts, $hintOut);
                if ($match !== null && isset($contacts[$match]) && is_array($contacts[$match])) {
                    $contacts[$match]['id'] = $match;
                    return $contacts[$match];
                }
            }
            $made = jeab_kontakt_blank($hintOut);
            $contacts[$made['id']] = $made;
            $created = true;
            return $made;
        });
        if (!is_array($row) || (string) ($row['id'] ?? '') === '') {
            return ['action' => 'skip', 'id' => '', 'name' => (string) $plan['name'], 'shown' => (string) $plan['shown'], 'hint' => []];
        }
        $id = (string) $row['id'];
        $plan['action'] = $created ? 'create' : 'merge';
    }
    if ($customerId !== '') {
        $cp['customers'][$customerId] = $id;
    }
    if ($plan['action'] === 'merge') {
        jeab_hs_import_contact_refresh($id, is_array($plan['hint'] ?? null) ? $plan['hint'] : []);
    }
    return ['action' => (string) $plan['action'], 'id' => $id, 'name' => (string) $plan['name'], 'shown' => (string) $plan['shown'], 'hint' => []];
}

/**
 * Byter «Efternamn, Förnamn» och en local-del mot det normaliserade namnet.
 * Låsta kontakter (personnummer, order, bokning, påminnelse) rörs inte.
 *
 * @param array<string, mixed> $hint
 */
function jeab_hs_import_contact_refresh(string $id, array $hint): void
{
    if ($id === '' || jeab_hs_import_contact_locked($id)) {
        return;
    }
    $next = trim((string) ($hint['name'] ?? ''));
    $email = jeab_kontakt_email((string) ($hint['email'] ?? ''));
    $city = trim((string) ($hint['city'] ?? ''));
    $missing = !empty($hint['name_missing']);
    jeab_kontakter_mutate(function (array &$contacts) use ($id, $next, $email, $city, $missing): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $row = $contacts[$id];
        $owners = is_array($row['owners'] ?? null) ? array_values($row['owners']) : [];
        if ($owners === [] || !is_array($owners[0])) {
            $owners = [['name' => '', 'personnummer' => '', 'contact' => true, 'email' => $email, 'phone' => '']];
        }
        $current = trim((string) ($owners[0]['name'] ?? ''));
        $rowCity = trim((string) ($row['city'] ?? ''));
        $normalized = jeab_kontakt_normalize_person_name($current, $rowCity !== '' ? $rowCity : $city)['name'];
        $weak = $email !== '' && jeab_hs_import_name_unusable($current, $email);
        if ($next !== '' && $current !== $next && ($normalized === $next || $weak || $current === '')) {
            $owners[0]['name'] = $next;
        }
        if ($email !== '' && jeab_kontakt_email((string) ($owners[0]['email'] ?? '')) === '') {
            $owners[0]['email'] = $email;
        }
        $row['owners'] = $owners;
        if ($city !== '' && trim((string) ($row['city'] ?? '')) === '') {
            $row['city'] = $city;
        }
        if ($missing) {
            $row['name_missing'] = true;
        }
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $contacts[$id] = $row;
    });
}

/** @param list<mixed> $raw */
function jeab_hs_import_attachment_meta(array $raw, string $conversationId, string $threadId, string $company, string $internetId): array
{
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || preg_match('/^\d+$/', $id) !== 1) {
            continue;
        }
        $name = trim((string) ($row['filename'] ?? $row['fileName'] ?? 'bilaga'));
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(str_replace(["\r", "\n", '"'], '', $name));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'bilaga';
        }
        $mime = strtolower(trim((string) ($row['mimeType'] ?? $row['mime_type'] ?? 'application/octet-stream')));
        if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) !== 1) {
            $mime = 'application/octet-stream';
        }
        $item = [
            'name' => mb_substr($name, 0, 180),
            'mime' => $mime,
            'size' => max(0, (int) ($row['size'] ?? 0)),
            'hs_conversation_id' => $conversationId,
            'hs_thread_id' => $threadId,
            'hs_attachment_id' => $id,
            'company' => $company,
        ];
        if ($internetId !== '') {
            $item['internet_message_id'] = mb_substr($internetId, 0, 300);
        }
        $out[] = $item;
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function jeab_hs_import_threads(string $conversationId): array
{
    $out = [];
    for ($page = 1; $page <= 20; $page++) {
        $json = jeab_hs_import_get_json('conversations/' . rawurlencode($conversationId) . '/threads', ['page' => $page]);
        foreach ((array) ($json['_embedded']['threads'] ?? []) as $thread) {
            if (is_array($thread)) {
                $out[] = $thread;
            }
        }
        $total = (int) ($json['page']['totalPages'] ?? 1);
        if ($page >= $total || $total < 1) {
            break;
        }
    }
    usort($out, fn($a, $b) => strcmp((string) ($a['createdAt'] ?? ''), (string) ($b['createdAt'] ?? '')));
    return $out;
}

function jeab_hs_import_plain(string $html): string
{
    $text = function_exists('jeab_inkorg_html_to_text')
        ? jeab_inkorg_html_to_text($html)
        : trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return function_exists('jeab_inkorg_strip_hs_junk') ? jeab_inkorg_strip_hs_junk($text) : $text;
}

/**
 * @param array<string, mixed> $thread
 * @return array<string, mixed>|null
 */
function jeab_hs_import_message_row(array $thread, array $conversation, string $company, int $mailboxId): ?array
{
    $type = (string) ($thread['type'] ?? '');
    $kind = match ($type) {
        'customer', 'phone', 'chat', 'beaconchat' => 'in',
        'message', 'reply' => 'out',
        'note' => 'note',
        default => '',
    };
    if ($kind === '') {
        return null;
    }
    $cid = (string) ($conversation['id'] ?? '');
    $threadId = (string) ($thread['id'] ?? '');
    if ($cid === '' || $threadId === '') {
        return null;
    }
    $by = (array) ($thread['createdBy'] ?? []);
    $cust = (array) ($thread['customer'] ?? $conversation['primaryCustomer'] ?? []);
    $email = $kind === 'in' ? (string) ($cust['email'] ?? $by['email'] ?? '') : (string) ($by['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $alt = $kind === 'in' ? (string) ($by['email'] ?? '') : '';
        if ($alt !== '' && filter_var($alt, FILTER_VALIDATE_EMAIL)) {
            $email = $alt;
        }
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = jeab_inkorg_sender($company);
    }
    $byNames = jeab_hs_import_actor_names($by);
    $custNames = jeab_hs_import_actor_names($cust);
    $picked = $byNames;
    if ($kind === 'in' && !jeab_hs_import_name_is_person($byNames['display']) && jeab_hs_import_name_is_person($custNames['display'])) {
        $picked = $custNames;
    } elseif ($kind === 'in' && $byNames['display'] === '') {
        $picked = $custNames;
    }
    $name = $picked['display'];
    $html = (string) ($thread['body'] ?? '');
    $plain = jeab_hs_import_plain($html);
    if ($kind === 'out' && function_exists('jeab_inkorg_clean_message')) {
        $plain = jeab_inkorg_clean_message(['from' => $email, 'body' => $plain, 'source' => JEAB_HS_IMPORT_SOURCE]);
    }
    $internet = trim((string) ($thread['source']['messageId'] ?? $thread['messageId'] ?? ''));
    $attachments = [];
    if ($type === 'customer') {
        $rawAtt = (array) ($thread['_embedded']['attachments'] ?? $thread['attachments'] ?? []);
        $attachments = jeab_hs_import_attachment_meta($rawAtt, $cid, $threadId, $company, $internet);
    }
    return [
        'message_id' => jeab_hs_import_message_id($cid, $threadId),
        'from' => $email,
        'from_name' => $name,
        'hs_name_first' => $picked['first'],
        'hs_name_last' => $picked['last'],
        'hs_name_display' => $picked['display'],
        'to' => jeab_inkorg_sender($company),
        'subject' => (string) ($conversation['subject'] ?? ''),
        'date' => (string) ($thread['createdAt'] ?? ''),
        'received_at' => (string) ($thread['createdAt'] ?? ''),
        'body_text' => $plain,
        'source' => JEAB_HS_IMPORT_SOURCE,
        'thread_key' => jeab_hs_import_thread_key($cid),
        'company' => $company,
        'internal_note' => $kind === 'note',
        'attachments' => $attachments,
        'internet_message_id' => $internet,
        'hs_conversation_id' => $cid,
        'hs_thread_id' => $threadId,
        'hs_thread_type' => $type,
        'hs_customer_id' => (string) ($cust['id'] ?? $conversation['primaryCustomer']['id'] ?? ''),
        'hs_mailbox_id' => (string) $mailboxId,
        'preset_labels' => [
            'type' => 'Information',
            'action' => 'arkivera',
            'priority' => 4,
            'confidence' => 100,
            'source' => 'import',
            'reason' => '',
            'needs_review' => false,
            'add_tags' => [],
        ],
    ];
}

function jeab_hs_import_root(): string
{
    return jeab_data_dir() . '/inkorg/hs-import';
}

function jeab_hs_import_conv_path(string $tid): ?string
{
    if (preg_match('/^hsi-([a-f0-9]{16})$/', $tid, $m) !== 1) {
        return null;
    }
    return jeab_hs_import_root() . '/c/' . substr($m[1], 0, 2) . '/' . $tid . '.json';
}

/** @return array{bytes: int, files: int} */
function jeab_dir_usage(string $dir): array
{
    $bytes = 0;
    $files = 0;
    if ($dir === '' || !is_dir($dir)) {
        return ['bytes' => 0, 'files' => 0];
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $files++;
            $size = $file->getSize();
            if ($size !== false) {
                $bytes += (int) $size;
            }
        }
    } catch (Throwable $e) {
        unset($e);
    }
    return ['bytes' => $bytes, 'files' => $files];
}

/** @return array{ok: bool, disk_free_bytes: int|null, disk_total_bytes: int|null, disk_free_ratio: float|null, data: array{bytes: int, files: int}, inkorg: array{bytes: int, files: int}} */
function jeab_system_disk(): array
{
    $data = function_exists('jeab_public_data_dir') ? jeab_public_data_dir() : (defined('JEAB_ROOT') ? JEAB_ROOT . '/data' : '');
    $probe = function_exists('jeab_data_dir') ? jeab_data_dir() : $data;
    if ($probe === '' || !is_dir($probe)) {
        $probe = $probe !== '' ? dirname($probe) : $data;
    }
    $free = ($probe !== '' && is_dir($probe)) ? @disk_free_space($probe) : false;
    $total = ($probe !== '' && is_dir($probe)) ? @disk_total_space($probe) : false;
    $freeN = $free === false ? null : (int) $free;
    $totalN = $total === false ? null : (int) $total;
    $ratio = ($freeN !== null && $totalN !== null && $totalN > 0) ? round($freeN / $totalN, 4) : null;
    return [
        'ok' => true,
        'disk_free_bytes' => $freeN,
        'disk_total_bytes' => $totalN,
        'disk_free_ratio' => $ratio,
        'data' => jeab_dir_usage($data),
        'inkorg' => jeab_dir_usage(jeab_data_dir() . '/inkorg'),
    ];
}

/** @return array{min_bytes: int, min_ratio: float} */
function jeab_hs_import_disk_limit(array $in): array
{
    $cfg = function_exists('jeab_helpscout_config') ? jeab_helpscout_config() : [];
    $bytes = array_key_exists('disk_min_bytes', $in)
        ? (int) $in['disk_min_bytes']
        : (int) ($cfg['import_disk_min_bytes'] ?? 524288000);
    $ratio = array_key_exists('disk_min_ratio', $in)
        ? (float) $in['disk_min_ratio']
        : (float) ($cfg['import_disk_min_ratio'] ?? 0.10);
    if ($bytes < 0) {
        $bytes = 0;
    }
    if ($ratio < 0) {
        $ratio = 0.0;
    }
    if ($ratio > 1) {
        $ratio = 1.0;
    }
    return ['min_bytes' => $bytes, 'min_ratio' => $ratio];
}

/** @return array{reason: string, disk_free_bytes: int|null, disk_total_bytes: int|null, disk_free_ratio: float|null}|null */
function jeab_hs_import_disk_block(array $in): ?array
{
    $lim = jeab_hs_import_disk_limit($in);
    $probe = jeab_data_dir();
    if (!is_dir($probe)) {
        $probe = dirname($probe);
    }
    $free = is_dir($probe) ? @disk_free_space($probe) : false;
    $total = is_dir($probe) ? @disk_total_space($probe) : false;
    if ($free === false || $total === false) {
        return ['reason' => 'disk_okand', 'disk_free_bytes' => null, 'disk_total_bytes' => null, 'disk_free_ratio' => null];
    }
    $freeN = (int) $free;
    $totalN = (int) $total;
    $ratio = $totalN > 0 ? $freeN / $totalN : 0.0;
    $low = ($lim['min_bytes'] > 0 && $freeN < $lim['min_bytes'])
        || ($lim['min_ratio'] > 0 && $totalN > 0 && $ratio < $lim['min_ratio']);
    if (!$low) {
        return null;
    }
    return [
        'reason' => 'disk',
        'disk_free_bytes' => $freeN,
        'disk_total_bytes' => $totalN,
        'disk_free_ratio' => round($ratio, 4),
    ];
}

/** @param array{reason: string, disk_free_bytes: int|null, disk_total_bytes: int|null, disk_free_ratio: float|null} $block */
function jeab_hs_import_disk_report(bool $dry, string $which, array $block): array
{
    $mailbox = $which === 'malmo' || $which === 'jarfalla' ? $which : 'all';
    return [
        'ok' => true,
        'dry' => $dry,
        'mailbox' => $mailbox,
        'mode' => 'backfill',
        'conversations' => 0,
        'messages' => 0,
        'notes' => 0,
        'attachments' => 0,
        'duplicates' => 0,
        'contacts_created' => 0,
        'contacts_merged' => 0,
        'contacts_skipped' => 0,
        'by_company' => [],
        'stopped' => (string) $block['reason'],
        'errors' => [],
        'disk_free_bytes' => $block['disk_free_bytes'],
        'disk_total_bytes' => $block['disk_total_bytes'],
        'disk_free_ratio' => $block['disk_free_ratio'],
    ];
}

/** @param array{by_id: array<string, array<string, mixed>>, by_msg: array<string, string>} $idx
 *  @param array<string, mixed> $row
 */
function jeab_hs_import_index_apply(array &$idx, array $row): void
{
    $id = (string) ($row['id'] ?? '');
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
        return;
    }
    if (isset($idx['by_id'][$id])) {
        foreach ((array) ($idx['by_id'][$id]['message_ids'] ?? []) as $old) {
            if (($idx['by_msg'][(string) $old] ?? '') === $id) {
                unset($idx['by_msg'][(string) $old]);
            }
        }
    }
    $idx['by_id'][$id] = $row;
    foreach ((array) ($row['message_ids'] ?? []) as $mid) {
        $mid = (string) $mid;
        if ($mid !== '') {
            $idx['by_msg'][$mid] = $id;
        }
    }
}

/** @return array{by_id: array<string, array<string, mixed>>, by_msg: array<string, string>} */
function jeab_hs_import_index(): array
{
    if (!empty($GLOBALS['_JEAB_INKORG_SETTINGS_COUNTS'])) {
        return ['by_id' => [], 'by_msg' => []];
    }
    if (isset($GLOBALS['_JEAB_HS_IMPORT_INDEX']) && is_array($GLOBALS['_JEAB_HS_IMPORT_INDEX'])) {
        return $GLOBALS['_JEAB_HS_IMPORT_INDEX'];
    }
    $idx = ['by_id' => [], 'by_msg' => []];
    $jsonReserve = function_exists('jeab_inkorg_json_reserve_closed') && jeab_inkorg_json_reserve_closed();
    if (!jeab_hs_import_store_is_imported() && !$jsonReserve) {
        foreach (jeab_hs_archive_index_json_map() as $row) {
            jeab_hs_import_index_apply($idx, $row);
        }
    }
    foreach (jeab_hs_import_store_index_rows() as $row) {
        jeab_hs_import_index_apply($idx, $row);
    }
    $GLOBALS['_JEAB_HS_IMPORT_INDEX'] = $idx;
    return $GLOBALS['_JEAB_HS_IMPORT_INDEX'];
}

/** @param array<string, mixed> $row */
function jeab_hs_import_index_remember(array $row): void
{
    if (!isset($GLOBALS['_JEAB_HS_IMPORT_INDEX']) || !is_array($GLOBALS['_JEAB_HS_IMPORT_INDEX'])) {
        return;
    }
    $id = (string) ($row['id'] ?? '');
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $id) !== 1) {
        return;
    }
    $idx = &$GLOBALS['_JEAB_HS_IMPORT_INDEX'];
    jeab_hs_import_index_apply($idx, $row);
}

/** @param array<string, mixed> $row */
function jeab_hs_import_index_append(array $row): bool
{
    $id = (string) ($row['id'] ?? '');
    if (!jeab_hs_import_store_index_put($id, $row)) {
        return false;
    }
    $GLOBALS['_JEAB_HS_IMPORT_INDEX_GEN'] = (int) ($GLOBALS['_JEAB_HS_IMPORT_INDEX_GEN'] ?? 0) + 1;
    jeab_hs_import_index_remember($row);
    return true;
}

/** @return array<string, mixed>|null */
function jeab_hs_import_read_conv(string $tid): ?array
{
    if (jeab_hs_import_conv_path($tid) === null) {
        return null;
    }
    $sql = jeab_hs_import_store_conv_get($tid);
    if (is_array($sql)) {
        return $sql;
    }
    if (jeab_hs_import_store_is_imported() || (function_exists('jeab_inkorg_json_reserve_closed') && jeab_inkorg_json_reserve_closed())) {
        return null;
    }
    $path = jeab_hs_import_conv_path($tid);
    if ($path === null || !is_file($path)) {
        return null;
    }
    $d = json_decode((string) file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

/** @param array<string, mixed> $conv */
function jeab_hs_import_save_conv(array $conv): bool
{
    $id = (string) ($conv['id'] ?? '');
    if (jeab_hs_import_conv_path($id) === null) {
        return false;
    }
    $saved = jeab_hs_import_store_conv_put($id, $conv);
    if ($saved) {
        jeab_hs_import_note_list_contact($id, $conv);
    }

    return $saved;
}

/**
 * Varje ny länk ska synas i inkorg_list.contact_id. Övriga fält lämnas.
 *
 * @param array<string, mixed> $conv
 */
function jeab_hs_import_note_list_contact(string $id, array $conv): void
{
    if (!function_exists('jeab_inkorg_sql_store_live')) {
        return;
    }
    $contactId = trim((string) ($conv['contact_id'] ?? ''));
    if ($contactId === '' && is_array($conv['meta'] ?? null)) {
        $contactId = trim((string) ($conv['meta']['contact_id'] ?? ''));
    }
    $live = jeab_inkorg_sql_store_live();
    if (!$live instanceof JeabPdoInkorgStore) {
        return;
    }
    try {
        $live->assignListContact($id, $contactId);
    } catch (Throwable) {
    }
}

/** @param array<string, mixed> $row */
function jeab_hs_import_stored_message(array $row): array
{
    $mid = trim((string) ($row['message_id'] ?? ''));
    $received = (string) ($row['received_at'] ?? '');
    $msg = [
        'id' => substr(hash('sha256', $mid), 0, 16),
        'message_id' => mb_substr($mid, 0, 300),
        'thread_id' => (string) ($row['thread_key'] ?? ''),
        'from' => (string) ($row['from'] ?? ''),
        'from_name' => mb_substr((string) ($row['from_name'] ?? ''), 0, 120),
        'to' => mb_substr((string) ($row['to'] ?? ''), 0, 300),
        'subject' => mb_substr((string) ($row['subject'] ?? ''), 0, 300),
        'date' => mb_substr((string) ($row['date'] ?? ''), 0, 64),
        'received_at' => strtotime($received) !== false ? date('c', (int) strtotime($received)) : date('c'),
        'headers' => [],
        'body' => mb_substr((string) ($row['body_text'] ?? ''), 0, 100000),
        'source' => JEAB_HS_IMPORT_SOURCE,
        'test' => false,
        'handled' => false,
        'in_reply_to' => '',
        'references' => [],
        'labels' => is_array($row['preset_labels'] ?? null) ? $row['preset_labels'] : [],
    ];
    if (in_array((string) ($row['company'] ?? ''), ['jarfalla', 'malmo'], true)) {
        $msg['company'] = (string) $row['company'];
    }
    if (!empty($row['internal_note'])) {
        $msg['internal_note'] = true;
    }
    $attachments = [];
    foreach ((array) ($row['attachments'] ?? []) as $att) {
        if (!is_array($att)) {
            continue;
        }
        unset($att['data'], $att['contentBytes'], $att['content']);
        $attachments[] = $att;
    }
    $msg['attachments'] = $attachments;
    foreach (['hs_conversation_id', 'hs_thread_type', 'hs_thread_id', 'hs_customer_id', 'hs_mailbox_id', 'internet_message_id', 'hs_name_first', 'hs_name_last', 'hs_name_display'] as $k) {
        if (isset($row[$k]) && !is_array($row[$k]) && (string) $row[$k] !== '') {
            $msg[$k] = (string) $row[$k];
        }
    }
    return $msg;
}

function jeab_hs_import_time_or_empty(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);

    return $ts === false ? '' : date('c', $ts);
}

/**
 * Listdatum för Historik: raderingsdatum, annars stängningsdatum, annars senaste mejlet.
 * Importens updated_at används aldrig.
 *
 * @param array<string, mixed> $conv
 */
function jeab_hs_import_list_at(array $conv): string
{
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $deleted = jeab_hs_import_time_or_empty((string) ($meta['deleted_at'] ?? $conv['deleted_at'] ?? ''));
    if ($deleted !== '') {
        return $deleted;
    }
    foreach (['closed_at', 'closedAt', 'hs_closed_at'] as $key) {
        $closed = jeab_hs_import_time_or_empty((string) ($meta[$key] ?? $conv[$key] ?? ''));
        if ($closed !== '') {
            return $closed;
        }
    }
    $latest = '';
    foreach ((array) ($conv['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $at = jeab_hs_import_time_or_empty((string) ($message['received_at'] ?? ''));
        if ($at === '') {
            $at = jeab_hs_import_time_or_empty((string) ($message['date'] ?? ''));
        }
        if ($at > $latest) {
            $latest = $at;
        }
    }

    return $latest;
}

/** @param array<string, mixed> $conv @return array<string, mixed> */
function jeab_hs_import_index_row(array $conv): array
{
    $msgs = array_values(array_filter((array) ($conv['messages'] ?? []), 'is_array'));
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $first = $msgs[0] ?? [];
    $last = $msgs !== [] ? $msgs[count($msgs) - 1] : [];
    $from = '';
    $fromName = '';
    foreach ($msgs as $m) {
        if (!empty($m['internal_note']) || (string) ($m['hs_thread_type'] ?? '') === 'note') {
            continue;
        }
        $email = (string) ($m['from'] ?? '');
        if ($email === '' || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email))) {
            continue;
        }
        $from = $email;
        $fromName = (string) ($m['from_name'] ?? '');
        break;
    }
    if ($from === '' && $first !== []) {
        $from = (string) ($first['from'] ?? '');
        $fromName = (string) ($first['from_name'] ?? '');
    }
    $snippet = '';
    if ($last !== [] && function_exists('jeab_inkorg_message_snippet')) {
        $snippet = jeab_inkorg_message_snippet($last, 160);
    } elseif ($last !== []) {
        $snippet = mb_substr(trim((string) ($last['body'] ?? '')), 0, 160);
    }
    $labels = function_exists('jeab_inkorg_labels_with_type')
        ? jeab_inkorg_labels_with_type((array) ($last['labels'] ?? []), $msgs)
        : (array) ($last['labels'] ?? []);
    $ids = [];
    foreach ($msgs as $m) {
        $mid = (string) ($m['id'] ?? '');
        if ($mid !== '') {
            $ids[] = $mid;
        }
    }
    $updated = jeab_hs_import_list_at($conv);
    $encoded = json_encode($conv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $hsIds = is_array($conv['hs_ids'] ?? null) ? $conv['hs_ids'] : [];
    $metaIds = is_array($meta['hs_ids'] ?? null) ? $meta['hs_ids'] : [];
    $row = [
        'id' => (string) ($conv['id'] ?? ''),
        'company' => (string) ($conv['company'] ?? ''),
        'status' => (string) ($meta['status'] ?? $conv['status'] ?? 'open'),
        'subject' => (string) ($first['subject'] ?? ''),
        'from' => $from,
        'from_name' => $fromName,
        'snippet' => $snippet,
        'updated_at' => $updated,
        'labels' => [
            'type' => (string) ($labels['type'] ?? ''),
            'action' => (string) ($labels['action'] ?? ''),
            'priority' => (int) ($labels['priority'] ?? 0),
        ],
        'assignee' => (string) ($meta['assignee'] ?? ''),
        'contact_id' => (string) ($meta['contact_id'] ?? $conv['contact_id'] ?? ''),
        'import_source' => JEAB_HS_IMPORT_SOURCE,
        'message_count' => count($msgs),
        'message_ids' => $ids,
        'bytes' => is_string($encoded) ? strlen($encoded) : 0,
        'deleted' => (string) ($meta['deleted_at'] ?? '') !== '',
        'tags' => array_values(array_map('strval', (array) ($meta['tags'] ?? []))),
        'read' => is_array($meta['read'] ?? null) ? $meta['read'] : [],
        'hs_conversation_id' => (string) ($hsIds['conversation'] ?? $metaIds['conversation'] ?? ''),
        'hs_number' => (string) ($hsIds['number'] ?? $metaIds['number'] ?? ''),
    ];
    if (is_array($meta['quote_sent'] ?? null) && $meta['quote_sent'] !== []) {
        $row['quote_sent'] = $meta['quote_sent'];
    }
    $reopened = trim((string) ($meta['reopened_live'] ?? ''));
    if ($reopened !== '') {
        $row['reopened_live'] = $reopened;
    }
    $stamp = function_exists('jeab_inkorg_closed_stamp') ? jeab_inkorg_closed_stamp($meta) : '';
    if ($stamp === '' && function_exists('jeab_inkorg_closed_stamp')) {
        $stamp = jeab_inkorg_closed_stamp($conv);
    }
    if ($stamp !== '') {
        $row['closed_at'] = $stamp;
    }
    $park = trim((string) ($meta['park_until'] ?? $meta['waiting_until'] ?? ''));
    if ($park !== '') {
        $row['park_until'] = $park;
    }
    $dup = trim((string) ($meta['duplicate_of'] ?? $conv['duplicate_of'] ?? ''));
    if ($dup !== '') {
        $row['duplicate_of'] = $dup;
    }
    return $row;
}

/**
 * Synlig text i Inkorgen. Ämnet på originalet, annars ärende-id.
 */
function jeab_hs_import_duplicate_caption(string $originalId, string $subject): string
{
    $label = trim($subject);
    if ($label === '') {
        $label = trim($originalId);
    }

    return $label === '' ? '' : 'Dubblett av ' . $label;
}

/**
 * Levande lead med samma kundmejl, eller samma leadämne och samma namn.
 * Help Scout-importer räknas inte som original. Partneradresser matchar inte.
 */
function jeab_hs_import_lead_original(array $probe): string
{
    if (!function_exists('jeab_inkorg_threads') || !function_exists('jeab_lead_customer')) {
        return '';
    }
    $email = mb_strtolower(trim((string) ($probe['email'] ?? '')));
    if ($email !== '' && function_exists('jeab_lead_bought_partner') && jeab_lead_bought_partner($email)) {
        $email = '';
    }
    $name = trim((string) ($probe['name'] ?? ''));
    $subject = function_exists('mb_strtolower') ? mb_strtolower(trim((string) ($probe['subject'] ?? ''))) : strtolower(trim((string) ($probe['subject'] ?? '')));
    $subject = preg_replace('/\s+/u', ' ', $subject) ?? $subject;
    if ($email === '' && ($name === '' || $subject === '')) {
        return '';
    }
    $best = '';
    $bestAt = PHP_INT_MAX;
    foreach (jeab_inkorg_threads() as $thread) {
        if (!is_array($thread) || !empty($thread['deleted'])) {
            continue;
        }
        if (jeab_hs_import_frozen($thread)) {
            continue;
        }
        $tid = (string) ($thread['id'] ?? '');
        if ($tid === '') {
            continue;
        }
        $msgs = [];
        foreach ((array) ($thread['messages'] ?? []) as $message) {
            if (is_array($message)) {
                $msgs[] = $message;
            }
        }
        if ($msgs === [] && is_array($thread['first'] ?? null)) {
            $msgs[] = $thread['first'];
        }
        $msg = [];
        $leadAt = PHP_INT_MAX;
        foreach ($msgs as $message) {
            $labels = is_array($message['labels'] ?? null) ? $message['labels'] : [];
            $typeRaw = $labels['type'] ?? '';
            $type = is_scalar($typeRaw) ? (string) $typeRaw : '';
            $subjRaw = trim((string) ($message['subject'] ?? ''));
            $lead = (function_exists('jeab_lead_is_lead') && jeab_lead_is_lead($thread, $message))
                || $type === 'Ny lead'
                || preg_match('/^\s*ny\s+lead\b/iu', $subjRaw) === 1;
            if (!$lead) {
                continue;
            }
            $at = strtotime((string) ($message['received_at'] ?? '')) ?: 0;
            if ($msg === [] || $at < $leadAt) {
                $msg = $message;
                $leadAt = $at;
            }
        }
        if ($msg === []) {
            continue;
        }
        $cust = jeab_lead_customer($msg);
        $otherEmail = mb_strtolower(trim((string) ($thread['customer_email'] ?? '')));
        if ($otherEmail === '' || (function_exists('jeab_lead_bought_partner') && jeab_lead_bought_partner($otherEmail))) {
            $otherEmail = mb_strtolower(trim((string) ($cust['email'] ?? '')));
        }
        if ($otherEmail !== '' && function_exists('jeab_lead_bought_partner') && jeab_lead_bought_partner($otherEmail)) {
            $otherEmail = '';
        }
        $emailHit = $email !== '' && $otherEmail === $email;
        $otherName = trim((string) ($thread['customer_name'] ?? ''));
        if ($otherName === '') {
            $otherName = trim((string) ($cust['name'] ?? ''));
        }
        $subjRaw = trim((string) ($msg['subject'] ?? ''));
        $otherSubject = function_exists('mb_strtolower') ? mb_strtolower($subjRaw) : strtolower($subjRaw);
        $otherSubject = preg_replace('/\s+/u', ' ', $otherSubject) ?? $otherSubject;
        $nameHit = false;
        if ($subject !== '' && $otherSubject === $subject && $name !== '' && $otherName !== '') {
            $nameHit = function_exists('jeab_name_same_person')
                ? jeab_name_same_person($name, $otherName)
                : (function_exists('jeab_fold_person') ? jeab_fold_person($name) === jeab_fold_person($otherName) : strcasecmp($name, $otherName) === 0);
        }
        if (!$emailHit && !$nameHit) {
            continue;
        }
        $at = $leadAt === PHP_INT_MAX ? (strtotime((string) ($thread['updated_at'] ?? '')) ?: 0) : $leadAt;
        if ($best === '' || $at < $bestAt) {
            $best = $tid;
            $bestAt = $at;
        }
    }

    return $best;
}

/**
 * Kopplar en importerad tråd till ett befintligt lead. Lägger bara till.
 * Skriver inte över en befintlig länk och raderar inget.
 *
 * @param array<string, mixed> $conv
 * @return array{linked:bool,original:string,note:bool}
 */
function jeab_hs_import_link_lead_duplicate(array $conv): array
{
    $blank = ['linked' => false, 'original' => '', 'note' => false];
    $tid = (string) ($conv['id'] ?? '');
    if ($tid === '' || !jeab_hs_import_frozen($conv)) {
        return $blank;
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $already = trim((string) ($meta['duplicate_of'] ?? $conv['duplicate_of'] ?? ''));
    $msgs = array_values(array_filter((array) ($conv['messages'] ?? []), 'is_array'));
    $probeMsg = [];
    foreach ($msgs as $message) {
        if (!empty($message['internal_note']) || (string) ($message['hs_thread_type'] ?? '') === 'note') {
            continue;
        }
        $probeMsg = $message;
        break;
    }
    if ($probeMsg === [] && $msgs !== []) {
        $probeMsg = $msgs[0];
    }
    $cust = function_exists('jeab_lead_customer') ? jeab_lead_customer($probeMsg) : ['email' => '', 'name' => ''];
    $original = $already !== '' ? $already : jeab_hs_import_lead_original([
        'email' => (string) ($cust['email'] ?? ''),
        'name' => (string) ($cust['name'] ?? ''),
        'subject' => (string) ($probeMsg['subject'] ?? ''),
    ]);
    if ($original === '' || $original === $tid) {
        return $blank;
    }
    $origSubject = '';
    if (function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread) || (string) ($thread['id'] ?? '') !== $original) {
                continue;
            }
            $cands = [];
            $subjectOf = static function (mixed $raw): string {
                return trim(is_scalar($raw) ? (string) $raw : '');
            };
            foreach ((array) ($thread['messages'] ?? []) as $message) {
                if (is_array($message)) {
                    $cands[] = $subjectOf($message['subject'] ?? '');
                }
            }
            $firstSub = $subjectOf(((array) ($thread['first'] ?? []))['subject'] ?? '');
            if ($firstSub !== '') {
                $cands[] = $firstSub;
            }
            foreach ($cands as $cand) {
                if (preg_match('/^\s*ny\s+lead\b/iu', $cand) === 1) {
                    $origSubject = $cand;
                    break;
                }
            }
            if ($origSubject === '' && $cands !== []) {
                $origSubject = $cands[0];
            }
            break;
        }
    }
    $caption = jeab_hs_import_duplicate_caption($original, $origSubject);
    if ($caption === '') {
        return $blank;
    }
    $noteId = substr(hash('sha256', $tid . '|duplicate-note'), 0, 16);
    $hasNote = false;
    foreach ($msgs as $message) {
        $body = trim((string) ($message['body'] ?? ''));
        if ((string) ($message['id'] ?? '') === $noteId || $body === $caption || str_starts_with($body, 'Dubblett av ')) {
            $hasNote = true;
            break;
        }
    }
    if ($already !== '' && $hasNote) {
        return ['linked' => true, 'original' => $already, 'note' => false];
    }
    if ($already === '') {
        $meta['duplicate_of'] = $original;
        $conv['duplicate_of'] = $original;
        $meta['status'] = 'closed';
        $conv['status'] = 'closed';
        if (trim((string) ($meta['closed_at'] ?? $conv['closed_at'] ?? '')) === '') {
            $stamp = date('c');
            $meta['closed_at'] = $stamp;
            $conv['closed_at'] = $stamp;
        }
    }
    $inserted = false;
    if (!$hasNote) {
        $labels = is_array($probeMsg['labels'] ?? null) ? $probeMsg['labels'] : [];
        $msgs[] = [
            'id' => $noteId,
            'thread_id' => $tid,
            'from' => '',
            'from_name' => 'Inkorg',
            'subject' => (string) ($probeMsg['subject'] ?? ''),
            'body' => $caption,
            'received_at' => date('c'),
            'source' => JEAB_HS_IMPORT_SOURCE,
            'internal_note' => true,
            'hs_thread_type' => 'note',
            'labels' => $labels,
            'attachments' => [],
        ];
        $conv['messages'] = $msgs;
        $inserted = true;
    }
    $conv['meta'] = $meta;
    $conv['import_source'] = JEAB_HS_IMPORT_SOURCE;
    if (!jeab_hs_import_save_conv($conv)) {
        return $blank;
    }
    jeab_hs_import_index_append(jeab_hs_import_index_row($conv));

    return ['linked' => true, 'original' => (string) ($meta['duplicate_of'] ?? $original), 'note' => $inserted];
}

/** @param array<string, mixed> $conv */
function jeab_hs_import_thread_from_conv_stored(array $conv): ?array
{
    $msgs = array_values(array_filter((array) ($conv['messages'] ?? []), 'is_array'));
    if ($msgs === []) {
        return null;
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $last = $msgs[count($msgs) - 1];
    $labels = function_exists('jeab_inkorg_labels_with_type')
        ? jeab_inkorg_labels_with_type((array) ($last['labels'] ?? []), $msgs)
        : (array) ($last['labels'] ?? []);
    $company = (string) ($conv['company'] ?? '');
    if ($company !== 'jarfalla' && $company !== 'malmo' && function_exists('jeab_inkorg_brand')) {
        $company = jeab_inkorg_brand($msgs);
    }
    $updated = jeab_hs_import_list_at($conv);
    $thread = [
        'id' => (string) ($conv['id'] ?? ''),
        'messages' => $msgs,
        'message_count' => count($msgs),
        'last' => $last,
        'first' => $msgs[0],
        'labels' => $labels,
        'needs_review' => (bool) array_filter($msgs, fn($m) => !empty($m['labels']['needs_review'])),
        'status' => (string) ($meta['status'] ?? $conv['status'] ?? 'open'),
        'tags' => array_values(array_map('strval', (array) ($meta['tags'] ?? []))),
        'assignee' => (string) ($meta['assignee'] ?? ''),
        'drafts' => array_values((array) ($meta['drafts'] ?? [])),
        'updated_at' => $updated,
        'source' => JEAB_HS_IMPORT_SOURCE,
        'brand' => $company,
        'company' => $company,
        'read' => is_array($meta['read'] ?? null) ? $meta['read'] : [],
        'deleted' => (string) ($meta['deleted_at'] ?? '') !== '',
        'same_customer' => [],
        'duplicate_of' => (string) ($meta['duplicate_of'] ?? $conv['duplicate_of'] ?? ''),
        'lead_customer' => [],
        'contact_id' => (string) ($meta['contact_id'] ?? $conv['contact_id'] ?? ''),
        'import_source' => JEAB_HS_IMPORT_SOURCE,
    ] + (function_exists('jeab_inkorg_closed_fields') ? jeab_inkorg_closed_fields($meta + $conv) : []);
    $park = trim((string) ($meta['park_until'] ?? $meta['waiting_until'] ?? ''));
    if ($park !== '') {
        $thread['park_until'] = $park;
    }

    return $thread;
}

/** @param array<string, mixed> $conv */
function jeab_hs_import_thread_from_conv(array $conv): ?array
{
    $thread = jeab_hs_import_thread_from_conv_stored($conv);
    if ($thread === null) {
        return null;
    }
    return function_exists('jeab_inkorg_thread_apply_override') ? jeab_inkorg_thread_apply_override($thread) : $thread;
}

/** @param array<string, mixed> $row */
function jeab_hs_import_stub_thread_stored(array $row): array
{
    $company = (string) ($row['company'] ?? '');
    $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
    $ids = array_values(array_filter(array_map('strval', (array) ($row['message_ids'] ?? []))));
    $msg = [
        'id' => $ids[0] ?? (string) ($row['id'] ?? ''),
        'from' => (string) ($row['from'] ?? ''),
        'from_name' => (string) ($row['from_name'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'body' => (string) ($row['snippet'] ?? ''),
        'received_at' => (string) ($row['updated_at'] ?? ''),
        'source' => JEAB_HS_IMPORT_SOURCE,
        'company' => $company,
        'labels' => $labels,
        'internal_note' => false,
        'attachments' => [],
    ];
    $thread = [
        'id' => (string) ($row['id'] ?? ''),
        'messages' => [$msg],
        'message_count' => (int) ($row['message_count'] ?? 1),
        'last' => $msg,
        'first' => $msg,
        'labels' => $labels,
        'needs_review' => false,
        'status' => (string) ($row['status'] ?? 'open'),
        'tags' => array_values(array_map('strval', (array) ($row['tags'] ?? []))),
        'assignee' => (string) ($row['assignee'] ?? ''),
        'drafts' => [],
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'source' => JEAB_HS_IMPORT_SOURCE,
        'brand' => $company,
        'company' => $company,
        'read' => is_array($row['read'] ?? null) ? $row['read'] : [],
        'deleted' => !empty($row['deleted']),
        'same_customer' => [],
        'duplicate_of' => (string) ($row['duplicate_of'] ?? ''),
        'lead_customer' => [],
        'contact_id' => (string) ($row['contact_id'] ?? ''),
        'import_source' => JEAB_HS_IMPORT_SOURCE,
    ] + (function_exists('jeab_inkorg_closed_fields') ? jeab_inkorg_closed_fields($row) : []);
    $park = trim((string) ($row['park_until'] ?? $row['waiting_until'] ?? ''));
    if ($park !== '') {
        $thread['park_until'] = $park;
    }

    return $thread;
}

/** @param array<string, mixed> $row */
function jeab_hs_import_stub_thread(array $row): array
{
    $thread = jeab_hs_import_stub_thread_stored($row);
    return function_exists('jeab_inkorg_thread_apply_override') ? jeab_inkorg_thread_apply_override($thread) : $thread;
}

/** Indexrad som ett ärende, så menyn och listan använder samma filter. */
function jeab_hs_import_row_thread(array $row): array
{
    $labels = is_array($row['labels'] ?? null) ? $row['labels'] : [];
    $company = (string) ($row['company'] ?? '');

    $thread = [
        'id' => (string) ($row['id'] ?? ''),
        'deleted' => !empty($row['deleted']),
        'company' => $company,
        'brand' => (string) ($row['brand'] ?? $company),
        'labels' => $labels,
        'tags' => array_values(array_map('strval', (array) ($row['tags'] ?? []))),
        'status' => (string) ($row['status'] ?? 'open'),
        'assignee' => (string) ($row['assignee'] ?? ''),
        'needs_review' => !empty($row['needs_review']),
        'import_source' => (string) ($row['import_source'] ?? JEAB_HS_IMPORT_SOURCE),
        'source' => JEAB_HS_IMPORT_SOURCE,
        'duplicate_of' => (string) ($row['duplicate_of'] ?? ''),
        'park_until' => (string) ($row['park_until'] ?? $row['waiting_until'] ?? ''),
        'messages' => [[
            'subject' => (string) ($row['subject'] ?? ''),
            'from' => (string) ($row['from'] ?? ''),
            'from_name' => (string) ($row['from_name'] ?? ''),
            'body' => (string) ($row['snippet'] ?? ''),
        ]],
    ] + (function_exists('jeab_inkorg_closed_fields') ? jeab_inkorg_closed_fields($row) : []);
    return function_exists('jeab_inkorg_thread_apply_override') ? jeab_inkorg_thread_apply_override($thread) : $thread;
}

/** Indexrad mot ett vyfilter. Läser inte konversationsfilen. Samma funktion som listan. */
function jeab_hs_import_row_matches(array $row, array $f): bool
{
    return function_exists('jeab_inkorg_thread_matches') && jeab_inkorg_thread_matches(jeab_hs_import_row_thread($row), $f);
}

function jeab_hs_import_count(array $filter): int
{
    if (function_exists('jeab_inkorg_thread_overrides')) {
        jeab_inkorg_thread_overrides();
    }
    $n = 0;
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (is_array($row) && jeab_hs_import_row_matches($row, $filter)) {
            $n++;
        }
    }
    return $n;
}

/** @return array{page: int, pages: int, total: int, rows: list<array<string, mixed>>} */
function jeab_hs_import_query(array $filter, int $page, int $size = 50): array
{
    $matched = [];
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (is_array($row) && jeab_hs_import_row_matches($row, $filter)) {
            $matched[] = $row;
        }
    }
    usort($matched, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
    $paged = jeab_hs_import_page($matched, $page, $size);
    $rows = [];
    foreach ($paged['rows'] as $row) {
        if (is_array($row)) {
            $rows[] = jeab_hs_import_stub_thread($row);
        }
    }
    $paged['rows'] = $rows;
    return $paged;
}

/** Andra importerade ärenden med samma e-post. Övriga filer öppnas inte. */
function jeab_hs_import_related_threads(string $email, string $exceptId = ''): array
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return [];
    }
    $out = [];
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (!is_array($row) || (string) ($row['id'] ?? '') === $exceptId) {
            continue;
        }
        if (mb_strtolower((string) ($row['from'] ?? '')) !== $email) {
            continue;
        }
        $out[] = jeab_hs_import_stub_thread($row);
    }
    return $out;
}

/** @return list<array<string, mixed>> */
function jeab_hs_import_list_threads(): array
{
    $out = [];
    foreach (jeab_hs_import_index()['by_id'] as $row) {
        if (is_array($row)) {
            $out[] = jeab_hs_import_stub_thread($row);
        }
    }
    usort($out, fn($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
    return $out;
}

/** @param list<array<string, mixed>> $rows @return array{page: int, pages: int, total: int, rows: list<array<string, mixed>>} */
function jeab_hs_import_page(array $rows, int $page, int $size = 50): array
{
    $size = max(1, $size);
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $size));
    $page = max(1, min($pages, $page));
    return [
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'rows' => array_values(array_slice($rows, ($page - 1) * $size, $size)),
    ];
}

function jeab_hs_import_open_thread(string $id): ?array
{
    if ($id === '') {
        return null;
    }
    $index = jeab_hs_import_index();
    $tid = isset($index['by_id'][$id]) ? $id : (string) ($index['by_msg'][$id] ?? '');
    if ($tid === '') {
        return null;
    }
    $conv = jeab_hs_import_read_conv($tid);
    return $conv === null ? null : jeab_hs_import_thread_from_conv($conv);
}

function jeab_hs_import_is_stored(string $tid): bool
{
    if (preg_match('/^hsi-[a-f0-9]{16}$/', $tid) !== 1) {
        return false;
    }
    if (isset($GLOBALS['_JEAB_HS_IMPORT_INDEX']) && is_array($GLOBALS['_JEAB_HS_IMPORT_INDEX'])) {
        return isset($GLOBALS['_JEAB_HS_IMPORT_INDEX']['by_id'][$tid]);
    }
    $pdo = function_exists('jeab_hs_import_store_pdo') ? jeab_hs_import_store_pdo(false) : null;
    if ($pdo instanceof PDO) {
        try {
            jeab_hs_import_store_ensure($pdo);
            $stmt = $pdo->prepare('SELECT 1 FROM jeab_hs_import_index WHERE id = ? LIMIT 1');
            $stmt->execute([$tid]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
            if (function_exists('jeab_hs_import_store_is_imported') && jeab_hs_import_store_is_imported($pdo)) {
                return false;
            }
        } catch (Throwable) {
        }
    }

    return isset(jeab_hs_import_index()['by_id'][$tid]);
}

function jeab_hs_import_message_owns(string $id): bool
{
    return preg_match('/^[a-f0-9]{16}$/', $id) === 1 && isset(jeab_hs_import_index()['by_msg'][$id]);
}

function jeab_hs_import_message_get(string $id): ?array
{
    $tid = (string) (jeab_hs_import_index()['by_msg'][$id] ?? '');
    if ($tid === '') {
        return null;
    }
    $conv = jeab_hs_import_read_conv($tid);
    if ($conv === null) {
        return null;
    }
    foreach ((array) ($conv['messages'] ?? []) as $m) {
        if (is_array($m) && (string) ($m['id'] ?? '') === $id) {
            return $m;
        }
    }
    return null;
}

function jeab_hs_import_message_update(string $id, callable $mutate): ?array
{
    $tid = (string) (jeab_hs_import_index()['by_msg'][$id] ?? '');
    if ($tid === '') {
        return null;
    }
    $conv = jeab_hs_import_read_conv($tid);
    if ($conv === null) {
        return null;
    }
    foreach ($conv['messages'] as $i => $m) {
        if (!is_array($m) || (string) ($m['id'] ?? '') !== $id) {
            continue;
        }
        $next = $mutate($m);
        if (!is_array($next)) {
            return $m;
        }
        $conv['messages'][$i] = $next;
        if (!jeab_hs_import_save_conv($conv) || !jeab_hs_import_index_append(jeab_hs_import_index_row($conv))) {
            return null;
        }
        return $next;
    }
    return null;
}

function jeab_hs_import_thread_write(string $tid, callable $fn): array
{
    $conv = jeab_hs_import_read_conv($tid);
    $row = jeab_hs_import_index()['by_id'][$tid] ?? [];
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [
        'status' => (string) ($row['status'] ?? 'open'),
        'tags' => (array) ($row['tags'] ?? []),
        'assignee' => (string) ($row['assignee'] ?? ''),
        'read' => is_array($row['read'] ?? null) ? $row['read'] : [],
        'contact_id' => (string) ($row['contact_id'] ?? ''),
        'import_source' => JEAB_HS_IMPORT_SOURCE,
    ];
    $meta['status'] = (string) ($meta['status'] ?? 'open');
    $meta['tags'] = array_values((array) ($meta['tags'] ?? []));
    $next = $fn($meta);
    if (!is_array($next)) {
        $next = $meta;
    }
    if (!is_array($conv)) {
        return $next;
    }
    $conv['meta'] = $next;
    $conv['status'] = (string) ($next['status'] ?? $conv['status'] ?? 'open');
    if (isset($next['contact_id'])) {
        $conv['contact_id'] = (string) $next['contact_id'];
    }
    $conv['import_source'] = JEAB_HS_IMPORT_SOURCE;
    if (jeab_hs_import_save_conv($conv)) {
        jeab_hs_import_index_append(jeab_hs_import_index_row($conv));
    }
    return $next;
}

/**
 * @param array<string, mixed> $conversation
 * @param array<string, mixed> $cp
 * @return array<string, int>
 */
function jeab_hs_import_one(array $conversation, string $company, int $mailboxId, bool $dry, array &$cp): array
{
    $n = ['conversations' => 0, 'messages' => 0, 'notes' => 0, 'attachments' => 0, 'duplicates' => 0, 'contacts_created' => 0, 'contacts_merged' => 0, 'contacts_skipped' => 0];
    $cid = (string) ($conversation['id'] ?? '');
    if ($cid === '' || preg_match('/^\d+$/', $cid) !== 1) {
        return $n;
    }
    $threads = jeab_hs_import_threads($cid);
    $rows = [];
    foreach ($threads as $thread) {
        $row = jeab_hs_import_message_row($thread, $conversation, $company, $mailboxId);
        if ($row !== null) {
            $rows[] = $row;
        }
    }
    if ($rows === []) {
        return $n;
    }
    $primary = (array) ($conversation['primaryCustomer'] ?? []);
    $customerId = (string) ($primary['id'] ?? '');
    $customer = $primary;
    if ($customerId !== '' && preg_match('/^\d+$/', $customerId) === 1 && !isset($cp['customers'][$customerId])) {
        try {
            $customer = jeab_hs_import_get_json('customers/' . rawurlencode($customerId));
        } catch (Throwable $e) {
            $customer = $primary;
        }
    }
    $hint = jeab_hs_import_customer_hint($customer, $primary);
    $author = ['first' => '', 'last' => '', 'display' => ''];
    foreach ($threads as $thread) {
        if (!is_array($thread) || (string) ($thread['type'] ?? '') !== 'customer') {
            continue;
        }
        $names = jeab_hs_import_actor_names((array) ($thread['createdBy'] ?? []));
        if ($names['display'] === '') {
            $names = jeab_hs_import_actor_names((array) ($thread['customer'] ?? []));
        }
        if ($names['display'] !== '') {
            $author = $names;
            break;
        }
    }
    $profileNames = jeab_hs_import_actor_names($customer);
    if ($profileNames['display'] === '') {
        $profileNames = jeab_hs_import_actor_names($primary);
    }
    $hint['name_first'] = $author['first'] !== '' ? $author['first'] : $profileNames['first'];
    $hint['name_last'] = $author['last'] !== '' ? $author['last'] : $profileNames['last'];
    $hint['name_display'] = $author['display'] !== '' ? $author['display'] : $profileNames['display'];
    $hint['name'] = jeab_hs_import_choose_display((string) ($hint['name'] ?? ''), (string) $author['display']);
    $body = '';
    foreach ($threads as $thread) {
        if ((string) ($thread['type'] ?? '') === 'customer') {
            $body = jeab_hs_import_plain((string) ($thread['body'] ?? ''));
            break;
        }
    }
    $hint['body'] = $body;
    $hint['subject'] = (string) ($conversation['subject'] ?? '');
    $hint = jeab_hs_import_prefer_body_customer($hint);
    $real = false;
    foreach ($threads as $thread) {
        if (is_array($thread) && jeab_hs_import_message_is_real_customer($thread)) {
            $real = true;
            break;
        }
    }
    $hint['real_customer'] = $real;
    $tid = jeab_hs_import_thread_key($cid);
    $hint['indexed'] = isset(jeab_hs_import_index()['by_id'][$tid]);
    $linked = jeab_hs_import_contact_apply($hint, $customerId, $dry, $cp);
    $n['contacts_' . ($linked['action'] === 'create' ? 'created' : ($linked['action'] === 'merge' ? 'merged' : 'skipped'))]++;
    $contactId = (string) $linked['id'];
    $shown = (string) ($linked['shown'] ?? '');
    $hintEmail = mb_strtolower(jeab_kontakt_email((string) ($hint['email'] ?? '')));
    foreach ($rows as $i => $row) {
        if ((string) ($row['hs_thread_type'] ?? '') === 'note') {
            continue;
        }
        $from = mb_strtolower(jeab_kontakt_email((string) ($row['from'] ?? '')));
        if ($hintEmail !== '' && $from === $hintEmail && $shown !== '') {
            $rows[$i]['from_name'] = $shown;
        }
        if (trim((string) ($rows[$i]['hs_name_display'] ?? '')) === '') {
            $rows[$i]['hs_name_display'] = trim((string) ($row['from_name'] ?? ''));
        }
    }
    $bits = [(string) ($conversation['subject'] ?? '')];
    foreach ($threads as $thread) {
        if (!is_array($thread) || (string) ($thread['type'] ?? '') !== 'customer') {
            continue;
        }
        $bits[] = jeab_hs_import_plain((string) ($thread['body'] ?? ''));
    }
    $conversation['jeab_text'] = implode("\n", $bits);
    $class = jeab_hs_import_classify($conversation);
    foreach ($rows as $i => $row) {
        if (!is_array($rows[$i]['preset_labels'] ?? null)) {
            continue;
        }
        $rows[$i]['preset_labels']['type'] = $class['type'];
        if ($class['tags'] !== []) {
            $rows[$i]['preset_labels']['add_tags'] = $class['tags'];
        }
    }
    $existing = jeab_hs_import_index()['by_id'][$tid] ?? null;
    $known = [];
    foreach ((array) ($existing['message_ids'] ?? []) as $mid) {
        $known[(string) $mid] = true;
    }
    $status = jeab_hs_import_status_name((string) ($conversation['status'] ?? 'active'));
    $fresh = [];
    foreach ($rows as $row) {
        $hash = substr(hash('sha256', (string) $row['message_id']), 0, 16);
        $exists = isset($known[$hash]);
        if (!empty($row['internal_note'])) {
            $n['notes']++;
        } else {
            $n['messages']++;
        }
        $n['attachments'] += count((array) $row['attachments']);
        if ($exists) {
            $n['duplicates']++;
            continue;
        }
        if (!$dry) {
            $fresh[] = jeab_hs_import_stored_message($row);
        }
    }
    $n['conversations'] = 1;
    $statusSame = is_array($existing) && (string) ($existing['status'] ?? '') === $status;
    $contactSame = is_array($existing) && (string) ($existing['contact_id'] ?? '') === $contactId;
    if ($dry || ($fresh === [] && $statusSame && $contactSame)) {
        if (!$dry && is_array($existing)) {
            $stored = jeab_hs_import_read_conv($tid);
            if (is_array($stored)) {
                jeab_hs_import_link_lead_duplicate($stored);
            }
        }
        return $n;
    }
    $conv = is_array($existing) ? jeab_hs_import_read_conv($tid) : null;
    $hsIds = ['conversation' => $cid, 'mailbox' => (string) $mailboxId, 'customer' => $customerId];
    $hsNumber = trim((string) ($conversation['number'] ?? ''));
    if ($hsNumber !== '' && preg_match('/^\d+$/', $hsNumber) === 1) {
        $hsIds['number'] = $hsNumber;
    }
    if (!is_array($conv)) {
        $conv = [
            'id' => $tid,
            'company' => $company,
            'status' => $status,
            'import_source' => JEAB_HS_IMPORT_SOURCE,
            'contact_id' => $contactId,
            'hs_ids' => $hsIds,
            'messages' => [],
            'meta' => [
                'status' => $status,
                'tags' => [],
                'assignee' => '',
                'read' => [],
                'drafts' => [],
                'contact_id' => $contactId,
                'import_source' => JEAB_HS_IMPORT_SOURCE,
                'hs_ids' => $hsIds,
                'created_at' => date('c'),
            ],
        ];
    }
    foreach ($fresh as $msg) {
        $conv['messages'][] = $msg;
    }
    $conv['company'] = $company;
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $keptDup = trim((string) ($meta['duplicate_of'] ?? $conv['duplicate_of'] ?? ''));
    if ($keptDup !== '') {
        $status = 'closed';
        $meta['duplicate_of'] = $keptDup;
        $conv['duplicate_of'] = $keptDup;
    }
    $conv['status'] = $status;
    $conv['import_source'] = JEAB_HS_IMPORT_SOURCE;
    $conv['hs_ids'] = $hsIds;
    $conv['contact_id'] = $contactId;
    $meta['status'] = $status;
    $meta['import_source'] = JEAB_HS_IMPORT_SOURCE;
    $meta['hs_ids'] = $hsIds;
    $meta['updated_at'] = date('c');
    $closedAt = jeab_hs_import_time_or_empty((string) ($conversation['closedAt'] ?? $conversation['closed_at'] ?? ''));
    if ($closedAt !== '') {
        $meta['closed_at'] = $closedAt;
        $conv['closed_at'] = $closedAt;
    }
    $meta['contact_id'] = $contactId;
    if ($class['tags'] !== []) {
        $meta['tags'] = $class['tags'];
    }
    if ($class['assignee'] !== '' && trim((string) ($meta['assignee'] ?? '')) === '') {
        $meta['assignee'] = $class['assignee'];
    }
    $conv['meta'] = $meta;
    if (!jeab_hs_import_save_conv($conv) || !jeab_hs_import_index_append(jeab_hs_import_index_row($conv))) {
        throw new RuntimeException('hs_fel');
    }
    $saved = jeab_hs_import_read_conv($tid);
    if (is_array($saved)) {
        jeab_hs_import_link_lead_duplicate($saved);
    }
    return $n;
}

/** @param array<string, int> $into @param array<string, int> $add */
function jeab_hs_import_add_counts(array &$into, array $add): void
{
    foreach ($add as $k => $v) {
        $into[$k] = (int) ($into[$k] ?? 0) + (int) $v;
    }
}

/** @return array<string, mixed> */
function jeab_hs_import_blank_box(): array
{
    return [
        'page' => 1,
        'after_id' => '',
        'backfill_done' => false,
        'watermark' => '',
        'inc_page' => 1,
        'fail_id' => '',
        'fail_n' => 0,
        'total_pages' => 0,
        'error_count' => 0,
        'errors' => [],
        'counts' => [],
    ];
}

function jeab_hs_import_lock_path(): string
{
    return jeab_inkorg_dir() . '/helpscout-import.lock';
}

/** Samma lås för helpscout.import och Malmö-synken. Upptaget lås ger busy, ingen kö. */
function jeab_hs_import_with_lock(callable $fn): array
{
    $lock = fopen(jeab_hs_import_lock_path(), 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['ok' => false, 'error' => 'busy'];
    }
    try {
        $out = $fn();
        return is_array($out) ? $out : ['ok' => false, 'error' => 'fel'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_run(array $in): array
{
    $GLOBALS['_JEAB_HS_IMPORTING'] = true;
    try {
        $run = static fn (): array => jeab_hs_import_with_lock(static fn (): array => jeab_hs_import_run_locked($in));
        if (function_exists('jeab_kontakt_quiet_call')) {
            $out = jeab_kontakt_quiet_call($run);
            return is_array($out) ? $out : ['ok' => false, 'error' => 'fel'];
        }
        return $run();
    } finally {
        unset($GLOBALS['_JEAB_HS_IMPORTING']);
    }
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_run_locked(array $in): array
{
    $dry = jeab_hs_import_dry($in);
    $limit = max(1, min(500, (int) ($in['limit'] ?? 25)));
    $budget = jeab_hs_import_budget($in);
    $started = microtime(true);
    if (function_exists('set_time_limit')) {
        @set_time_limit($budget + 20);
    }
    $which = (string) ($in['mailbox'] ?? 'all');
    $targets = jeab_hs_import_targets($which);
    if ($targets === []) {
        return ['ok' => false, 'error' => 'Ingen Help Scout-brevlåda.', 'dry' => $dry];
    }
    if (!isset($GLOBALS['_JEAB_HS_IMPORT_HTTP']) && jeab_hs_import_token() === null) {
        return ['ok' => false, 'error' => 'Help Scout-nyckel saknas.', 'dry' => $dry];
    }
    if (!$dry) {
        $block = jeab_hs_import_disk_block($in);
        if ($block !== null) {
            return jeab_hs_import_disk_report($dry, $which, $block);
        }
    }
    $cp = jeab_hs_import_checkpoint();
    $seen = 0;
    $report = [
        'ok' => true,
        'dry' => $dry,
        'mailbox' => $which === 'malmo' || $which === 'jarfalla' ? $which : 'all',
        'mode' => 'backfill',
        'conversations' => 0,
        'messages' => 0,
        'notes' => 0,
        'attachments' => 0,
        'duplicates' => 0,
        'contacts_created' => 0,
        'contacts_merged' => 0,
        'contacts_skipped' => 0,
        'by_company' => [],
        'stopped' => '',
        'errors' => [],
    ];
    $explicitSince = is_string($in['since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $in['since']) === 1;
    $wantSince = $explicitSince || (isset($in['since']) && ($in['since'] === 1 || $in['since'] === '1' || $in['since'] === true));
    foreach ($targets as $company => $mailboxId) {
        if ($seen >= $limit || (microtime(true) - $started) >= $budget) {
            $report['stopped'] = $seen >= $limit ? 'limit' : 'time';
            break;
        }
        if (!isset($cp['mailboxes'][$company]) || !is_array($cp['mailboxes'][$company])) {
            $cp['mailboxes'][$company] = jeab_hs_import_blank_box();
        }
        $box = &$cp['mailboxes'][$company];
        $backfillDone = !empty($box['backfill_done']);
        $incremental = $explicitSince || ($wantSince && $backfillDone);
        if ($incremental) {
            $report['mode'] = 'incremental';
        }
        $since = $explicitSince ? (string) $in['since'] : (string) ($box['watermark'] ?? '');
        if ($incremental && $since === '') {
            $report['errors'][] = $company . ': since_saknas';
            continue;
        }
        $page = $incremental ? max(1, (int) ($box['inc_page'] ?? 1)) : max(1, (int) ($box['page'] ?? 1));
        $after = $incremental ? '' : (string) ($box['after_id'] ?? '');
        while ($seen < $limit && (microtime(true) - $started) < $budget) {
            $query = [
                'mailbox' => $mailboxId,
                'status' => 'all',
                'sortField' => $incremental ? 'modifiedAt' : 'createdAt',
                'sortOrder' => 'asc',
                'page' => $page,
            ];
            if ($incremental) {
                $query['query'] = '(modifiedAt:[' . $since . ' TO *])';
            }
            try {
                $list = jeab_hs_import_get_json('conversations', $query);
            } catch (Throwable $e) {
                $code = jeab_hs_import_safe_error($e);
                if (!$dry) {
                    jeab_hs_import_note_error($cp, $company, $code);
                    jeab_hs_import_checkpoint_update(function (array &$d) use ($cp) {
                        $d = $cp;
                        return true;
                    });
                }
                $report['errors'][] = $company . ': ' . $code;
                break 2;
            }
            $convs = array_values(array_filter((array) ($list['_embedded']['conversations'] ?? []), 'is_array'));
            $totalPages = max(1, (int) ($list['page']['totalPages'] ?? 1));
            if (!$dry) {
                $box['total_pages'] = $totalPages;
            }
            $start = 0;
            if ($after !== '') {
                foreach ($convs as $i => $c) {
                    if ((string) ($c['id'] ?? '') === $after) {
                        $start = $i + 1;
                        break;
                    }
                }
            }
            if ($start >= count($convs)) {
                $page++;
                $after = '';
                if ($page > $totalPages) {
                    if (!$dry) {
                        if ($incremental) {
                            $box['watermark'] = gmdate('Y-m-d\TH:i:s\Z');
                            $box['inc_page'] = 1;
                        } else {
                            $box['backfill_done'] = true;
                            $box['watermark'] = gmdate('Y-m-d\TH:i:s\Z');
                            $box['page'] = $page;
                            $box['after_id'] = '';
                        }
                        jeab_hs_import_checkpoint_update(function (array &$d) use ($cp) {
                            $d = $cp;
                            return true;
                        });
                    }
                    break;
                }
                if (!$dry) {
                    if ($incremental) {
                        $box['inc_page'] = $page;
                    } else {
                        $box['page'] = $page;
                        $box['after_id'] = '';
                    }
                    jeab_hs_import_checkpoint_update(function (array &$d) use ($cp) {
                        $d = $cp;
                        return true;
                    });
                }
                continue;
            }
            for ($i = $start; $i < count($convs); $i++) {
                if ($seen >= $limit || (microtime(true) - $started) >= $budget) {
                    $report['stopped'] = $seen >= $limit ? 'limit' : 'time';
                    break 3;
                }
                $conv = $convs[$i];
                $convId = (string) ($conv['id'] ?? '');
                if (!$dry) {
                    $block = jeab_hs_import_disk_block($in);
                    if ($block !== null) {
                        $report['stopped'] = (string) $block['reason'];
                        $report['disk_free_bytes'] = $block['disk_free_bytes'];
                        $report['disk_total_bytes'] = $block['disk_total_bytes'];
                        $report['disk_free_ratio'] = $block['disk_free_ratio'];
                        break 3;
                    }
                }
                try {
                    $one = jeab_hs_import_one($conv, $company, $mailboxId, $dry, $cp);
                } catch (Throwable $e) {
                    $code = jeab_hs_import_safe_error($e);
                    $failId = (string) ($box['fail_id'] ?? '');
                    $failN = $failId === $convId ? (int) ($box['fail_n'] ?? 0) + 1 : 1;
                    if (!$dry) {
                        $box['fail_id'] = $convId;
                        $box['fail_n'] = $failN;
                        jeab_hs_import_note_error($cp, $company, $code);
                        if ($failN >= 3) {
                            $box['fail_id'] = '';
                            $box['fail_n'] = 0;
                            $box['after_id'] = $convId;
                            $box['page'] = $page;
                        }
                        jeab_hs_import_checkpoint_update(function (array &$d) use ($cp) {
                            $d = $cp;
                            return true;
                        });
                    }
                    $report['errors'][] = $company . ': ' . $code;
                    if ($failN < 3) {
                        $report['stopped'] = 'error';
                        break 3;
                    }
                    continue;
                }
                $seen++;
                jeab_hs_import_add_counts($report, $one);
                $report['by_company'][$company] = (int) ($report['by_company'][$company] ?? 0) + (int) $one['conversations'];
                if (!$dry) {
                    $box['counts'] = is_array($box['counts'] ?? null) ? $box['counts'] : [];
                    jeab_hs_import_add_counts($box['counts'], $one);
                    $box['fail_id'] = '';
                    $box['fail_n'] = 0;
                    if ($incremental) {
                        $box['inc_page'] = $page;
                    } else {
                        $box['page'] = $page;
                        $box['after_id'] = $convId;
                    }
                    jeab_hs_import_checkpoint_update(function (array &$d) use ($cp) {
                        $d = $cp;
                        return true;
                    });
                }
            }
            $page++;
            $after = '';
            if ($page > $totalPages) {
                if (!$dry) {
                    if ($incremental) {
                        $box['watermark'] = gmdate('Y-m-d\TH:i:s\Z');
                        $box['inc_page'] = 1;
                    } else {
                        $box['backfill_done'] = true;
                        $box['watermark'] = gmdate('Y-m-d\TH:i:s\Z');
                        $box['page'] = $page;
                        $box['after_id'] = '';
                    }
                    jeab_hs_import_checkpoint_update(function (array &$d) use ($cp) {
                        $d = $cp;
                        return true;
                    });
                }
                break;
            }
        }
        unset($box);
    }
    if ($report['stopped'] === '' && $seen >= $limit) {
        $report['stopped'] = 'limit';
    }
    $report['errors'] = array_slice($report['errors'], 0, 20);
    return $report;
}

/** @return array<string, mixed> */
function jeab_hs_import_status(): array
{
    $cp = jeab_hs_import_checkpoint();
    $usage = jeab_hs_import_store_usage();
    $n = count(jeab_hs_import_index()['by_id']);
    $per = $n > 0 ? (int) round($usage['bytes'] / $n) : 0;
    $progress = [];
    $counts = ['conversations' => 0, 'messages' => 0, 'notes' => 0, 'attachments' => 0, 'duplicates' => 0, 'contacts_created' => 0, 'contacts_merged' => 0, 'contacts_skipped' => 0];
    $errors = [];
    foreach ((array) $cp['mailboxes'] as $company => $box) {
        if (!is_array($box)) {
            continue;
        }
        $progress[(string) $company] = [
            'page' => (int) ($box['page'] ?? 1),
            'total_pages' => (int) ($box['total_pages'] ?? 0),
            'backfill_done' => !empty($box['backfill_done']),
            'watermark' => (string) ($box['watermark'] ?? ''),
            'error_count' => (int) ($box['error_count'] ?? 0),
        ];
        if (is_array($box['counts'] ?? null)) {
            jeab_hs_import_add_counts($counts, $box['counts']);
        }
        foreach ((array) ($box['errors'] ?? []) as $err) {
            $errors[] = (string) $err;
        }
    }
    $checkpointJson = json_encode($cp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [
        'ok' => true,
        'progress' => $progress,
        'counts' => $counts,
        'errors' => array_slice($errors, -20),
        'space' => [
            'import_bytes' => $usage['bytes'],
            'import_files' => $usage['files'],
            'checkpoint_bytes' => is_string($checkpointJson) ? strlen($checkpointJson) : 0,
            'conversations' => $n,
            'bytes_per_conversation' => $per,
            'projected_bytes_20000' => $per * 20000,
        ],
    ];
}

function jeab_hs_import_contact_locked(string $id): bool
{
    if ($id === '' || !function_exists('jeab_kontakt_get')) {
        return true;
    }
    $row = jeab_kontakt_get($id);
    if (!is_array($row)) {
        return true;
    }
    if (jeab_kontakt_row_has_personnummer($row)) {
        return true;
    }
    if (!function_exists('jeab_store') || !function_exists('jeab_kontakt_collect_sources')) {
        return false;
    }
    $store = jeab_store();
    if (!$store instanceof JeabStore) {
        return false;
    }
    foreach (jeab_kontakt_collect_sources($store) as $item) {
        if (!is_array($item) || (string) ($item['contact_id'] ?? '') !== $id) {
            continue;
        }
        if (in_array((string) ($item['kind'] ?? ''), ['reminder', 'order', 'booking'], true)) {
            return true;
        }
    }
    return false;
}

/** @param array<string, mixed> $conv @return array<string, mixed> */
function jeab_hs_import_hint_from_conv(array $conv): array
{
    $email = '';
    $name = '';
    $body = '';
    $subject = '';
    $rawFirst = '';
    $rawLast = '';
    $rawDisplay = '';
    foreach ((array) ($conv['messages'] ?? []) as $m) {
        if (!is_array($m) || !empty($m['internal_note']) || (string) ($m['hs_thread_type'] ?? '') === 'note') {
            continue;
        }
        $from = (string) ($m['from'] ?? '');
        if ($from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
            continue;
        }
        $email = $from;
        $stored = (string) ($m['from_name'] ?? '');
        $display = trim((string) ($m['hs_name_display'] ?? ''));
        if ($display === '') {
            $display = trim(trim((string) ($m['hs_name_first'] ?? '')) . ' ' . trim((string) ($m['hs_name_last'] ?? '')));
        }
        $name = jeab_hs_import_choose_display($stored, $display);
        $rawFirst = trim((string) ($m['hs_name_first'] ?? ''));
        $rawLast = trim((string) ($m['hs_name_last'] ?? ''));
        $rawDisplay = trim((string) ($m['hs_name_display'] ?? ''));
        if ($rawDisplay === '') {
            $rawDisplay = $stored;
        }
        $body = (string) ($m['body'] ?? '');
        $subject = (string) ($m['subject'] ?? '');
        break;
    }
    if ($subject === '') {
        $first = (array) (($conv['messages'][0] ?? []));
        $subject = (string) ($first['subject'] ?? '');
    }
    $real = false;
    foreach ((array) ($conv['messages'] ?? []) as $m) {
        if (is_array($m) && jeab_hs_import_message_is_real_customer($m)) {
            $real = true;
            break;
        }
    }
    return [
        'name' => $name,
        'name_first' => $rawFirst,
        'name_last' => $rawLast,
        'name_display' => $rawDisplay,
        'email' => $email,
        'phone' => '',
        'address' => '',
        'street' => '',
        'city' => '',
        'postal_code' => '',
        'body' => $body,
        'subject' => $subject,
        'indexed' => true,
        'real_customer' => $real,
    ];
}

function jeab_hs_import_case_quote_cleaned(array $conv): bool
{
    foreach ((array) ($conv['messages'] ?? []) as $m) {
        if (!is_array($m) || !empty($m['internal_note'])) {
            continue;
        }
        $raw = (string) ($m['body'] ?? '');
        if ($raw === '' || !function_exists('jeab_inkorg_clean_message')) {
            continue;
        }
        $clean = jeab_inkorg_clean_message($m);
        foreach (preg_split("/\n/", $raw) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $quoted = str_starts_with($line, '>') || (function_exists('jeab_inkorg_clean_quote_header') && jeab_inkorg_clean_quote_header($line));
            if ($quoted && !str_contains($clean, $line)) {
                return true;
            }
        }
    }
    return false;
}

/** @return array{id:string,name:string,email:string,phone:string,street:string,domain:string,company:bool,locked:bool}|null */
function jeab_hs_import_contact_snapshot(string $id): ?array
{
    $row = jeab_kontakt_get($id);
    if (!is_array($row)) {
        return null;
    }
    $name = '';
    $phone = '';
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $candidate = trim((string) ($owner['name'] ?? ''));
        if ($candidate === '') {
            continue;
        }
        if ($name === '' || !empty($owner['contact'])) {
            $name = $candidate;
            $phone = jeab_kontakt_phone((string) ($owner['phone'] ?? ''));
        }
    }
    $norm = jeab_kontakt_normalize_person_name($name, trim((string) ($row['city'] ?? '')));
    $folded = jeab_kontakt_fold_text($norm['name'] !== '' ? $norm['name'] : $name);
    if ($folded === '') {
        return null;
    }
    $email = jeab_kontakt_row_email($row);
    $domain = jeab_hs_import_email_domain($email);
    if ($domain === '' || jeab_hs_import_consumer_domain($email)) {
        $domain = '';
    }
    $display = $norm['name'] !== '' ? $norm['name'] : $name;
    return [
        'id' => $id,
        'name' => $folded,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : jeab_kontakt_row_phone($row),
        'street' => (string) ($row['street_key'] ?? ''),
        'domain' => $domain,
        'company' => jeab_kontakt_company_name($display),
        'locked' => jeab_hs_import_contact_locked($id),
    ];
}

/**
 * Samma namn och samma telefon, eller samma namn och samma gata, slås ihop
 * när e-post och adress inte krockar. Annars en konflikt att titta på för hand.
 * Par med samma e-post ägs av e-postihopslagningen.
 *
 * @param list<string> $ids
 * @return array{merged:int,conflict:int,replace:array<string,string>,conflict_ids:list<string>}
 */
function jeab_hs_import_identity_plan(array $ids): array
{
    $empty = ['merged' => 0, 'conflict' => 0, 'replace' => [], 'conflict_ids' => []];
    $rows = [];
    foreach ($ids as $id) {
        $snap = jeab_hs_import_contact_snapshot((string) $id);
        if ($snap !== null) {
            $rows[] = $snap;
        }
    }
    if (count($rows) < 2) {
        return $empty;
    }
    $byName = [];
    $parent = [];
    foreach ($rows as $row) {
        $byName[$row['name']][] = $row;
        $parent[$row['id']] = $row['id'];
    }
    $find = static function (string $id) use (&$parent, &$find): string {
        if (($parent[$id] ?? $id) !== $id) {
            $parent[$id] = $find($parent[$id]);
        }
        return $parent[$id] ?? $id;
    };
    foreach ($byName as $group) {
        $n = count($group);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $group[$i];
                $b = $group[$j];
                $sameEmail = $a['email'] !== '' && $a['email'] === $b['email'];
                $samePhone = $a['phone'] !== '' && $a['phone'] === $b['phone'];
                $sameStreet = $a['street'] !== '' && $a['street'] === $b['street'];
                $sameDomain = ($a['domain'] ?? '') !== '' && ($a['domain'] ?? '') === ($b['domain'] ?? '');
                $sameCompany = !empty($a['company']) && !empty($b['company']);
                if (!$sameEmail && !$samePhone && !$sameStreet && !$sameDomain && !$sameCompany) {
                    continue;
                }
                $left = $find((string) $a['id']);
                $right = $find((string) $b['id']);
                if ($left !== $right) {
                    $parent[$right] = $left;
                }
            }
        }
    }
    $clusters = [];
    foreach ($rows as $row) {
        $clusters[$find((string) $row['id'])][] = $row;
    }
    $replace = [];
    $merged = 0;
    $conflict = 0;
    $conflictIds = [];
    foreach ($clusters as $members) {
        if (count($members) < 2) {
            continue;
        }
        $emails = [];
        $streets = [];
        $domains = [];
        $company = false;
        foreach ($members as $member) {
            if ($member['email'] !== '') {
                $emails[$member['email']] = true;
            }
            if ($member['street'] !== '') {
                $streets[$member['street']] = true;
            }
            if (($member['domain'] ?? '') !== '') {
                $domains[(string) $member['domain']] = true;
            }
            if (!empty($member['company'])) {
                $company = true;
            }
        }
        $sameDomain = count($domains) === 1;
        if (count($streets) > 1 || (count($emails) > 1 && !$sameDomain && !$company)) {
            $conflict++;
            foreach ($members as $member) {
                $conflictIds[] = (string) $member['id'];
            }
            continue;
        }
        $survivor = (string) $members[0]['id'];
        $pickedLocked = false;
        foreach ($members as $member) {
            if (!empty($member['locked'])) {
                $survivor = (string) $member['id'];
                $pickedLocked = true;
                break;
            }
        }
        if (!$pickedLocked) {
            foreach ($members as $member) {
                if ($member['email'] !== '') {
                    $survivor = (string) $member['id'];
                    break;
                }
            }
        }
        foreach ($members as $member) {
            if ((string) $member['id'] === $survivor) {
                continue;
            }
            $replace[(string) $member['id']] = $survivor;
            if (empty($member['locked'])) {
                $merged++;
            }
        }
    }
    return ['merged' => $merged, 'conflict' => $conflict, 'replace' => $replace, 'conflict_ids' => array_values(array_unique($conflictIds))];
}

/** @param list<array<string, mixed>> $plans @return list<string> */
function jeab_hs_import_identity_ids(array $plans): array
{
    $ids = [];
    foreach ($plans as $row) {
        foreach (['old', 'new'] as $key) {
            $id = (string) ($row[$key] ?? '');
            if ($id !== '' && is_array(jeab_kontakt_get($id))) {
                $ids[$id] = true;
            }
        }
    }
    return array_keys($ids);
}

/**
 * @param list<array<string, mixed>> $plans
 * @param array{merged:int,conflict:int,replace:array<string,string>,conflict_ids:list<string>} $identity
 * @return list<array<string, mixed>>
 */
function jeab_hs_import_identity_apply(array $plans, array $identity): array
{
    $replace = $identity['replace'];
    if ($replace !== []) {
        foreach ($plans as $i => $row) {
            $id = (string) ($row['new'] ?? '');
            $old = (string) ($row['old'] ?? '');
            if (isset($replace[$id])) {
                $plans[$i]['new'] = $replace[$id];
            } elseif ($id === '' && isset($replace[$old])) {
                $plans[$i]['new'] = $replace[$old];
            } elseif ($id === $old && isset($replace[$old])) {
                $plans[$i]['new'] = $replace[$old];
            }
        }
        foreach ($plans as $row) {
            $conv = jeab_hs_import_read_conv((string) ($row['tid'] ?? ''));
            if (!is_array($conv)) {
                continue;
            }
            $next = (string) ($row['new'] ?? '');
            if ($next === '' || (string) ($conv['contact_id'] ?? '') === $next) {
                continue;
            }
            $conv['contact_id'] = $next;
            $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
            $meta['contact_id'] = $next;
            $conv['meta'] = $meta;
            if (jeab_hs_import_save_conv($conv)) {
                jeab_hs_import_index_append(jeab_hs_import_index_row($conv));
            }
        }
        $dropIds = [];
        $companyIds = [];
        foreach ($replace as $loser => $survivor) {
            foreach ([$loser, $survivor] as $cid) {
                $row = jeab_kontakt_get((string) $cid);
                if (!is_array($row)) {
                    continue;
                }
                foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
                    if (is_array($owner) && jeab_kontakt_company_name((string) ($owner['name'] ?? ''))) {
                        $companyIds[(string) $survivor] = true;
                    }
                }
            }
            if (!jeab_hs_import_contact_locked((string) $loser)) {
                $dropIds[] = (string) $loser;
            }
        }
        if ($dropIds !== [] || $companyIds !== []) {
            jeab_kontakter_mutate(function (array &$contacts) use ($dropIds, $companyIds, $replace): void {
                foreach ($replace as $loser => $survivor) {
                    $loser = (string) $loser;
                    $survivor = (string) $survivor;
                    if (!isset($contacts[$survivor], $contacts[$loser]) || !is_array($contacts[$survivor]) || !is_array($contacts[$loser])) {
                        continue;
                    }
                    $owners = is_array($contacts[$survivor]['owners'] ?? null) ? array_values($contacts[$survivor]['owners']) : [];
                    $have = [];
                    foreach ($owners as $owner) {
                        if (!is_array($owner)) {
                            continue;
                        }
                        $email = jeab_kontakt_email((string) ($owner['email'] ?? ''));
                        if ($email !== '') {
                            $have[$email] = true;
                        }
                    }
                    foreach (is_array($contacts[$loser]['owners'] ?? null) ? $contacts[$loser]['owners'] : [] as $owner) {
                        if (!is_array($owner)) {
                            continue;
                        }
                        $email = jeab_kontakt_email((string) ($owner['email'] ?? ''));
                        if ($email === '' || isset($have[$email])) {
                            continue;
                        }
                        $owners[] = [
                            'name' => trim((string) ($owner['name'] ?? '')),
                            'personnummer' => '',
                            'contact' => false,
                            'email' => $email,
                            'phone' => jeab_kontakt_phone((string) ($owner['phone'] ?? '')),
                        ];
                        $have[$email] = true;
                    }
                    $contacts[$survivor]['owners'] = $owners;
                    if (trim((string) ($contacts[$survivor]['street_key'] ?? '')) === '' && trim((string) ($contacts[$loser]['street_key'] ?? '')) !== '') {
                        foreach (['street', 'street_key', 'postal_code', 'city'] as $field) {
                            if (trim((string) ($contacts[$survivor][$field] ?? '')) === '') {
                                $contacts[$survivor][$field] = (string) ($contacts[$loser][$field] ?? '');
                            }
                        }
                    }
                }
                foreach (array_keys($companyIds) as $id) {
                    if (isset($contacts[$id]) && is_array($contacts[$id])) {
                        $contacts[$id]['kind'] = 'company';
                    }
                }
                foreach ($replace as $loser => $survivor) {
                    $loser = (string) $loser;
                    $survivor = (string) $survivor;
                    if (!in_array($loser, $dropIds, true) || !isset($contacts[$loser], $contacts[$survivor]) || !is_array($contacts[$loser]) || !is_array($contacts[$survivor])) {
                        continue;
                    }
                    if (function_exists('jeab_kontakt_merge_rows')) {
                        $sameAddress = function_exists('jeab_kontakt_row_identity')
                            && jeab_kontakt_row_identity($contacts[$loser])['key'] !== ''
                            && jeab_kontakt_row_identity($contacts[$loser])['key'] === jeab_kontakt_row_identity($contacts[$survivor])['key'];
                        $contacts[$survivor] = jeab_kontakt_merge_rows($contacts[$survivor], $contacts[$loser], $sameAddress ? 'address' : 'email');
                        $contacts[$survivor]['id'] = $survivor;
                    }
                }
                foreach ($dropIds as $id) {
                    unset($contacts[$id]);
                }
            });
            $traced = [];
            foreach ($replace as $loser => $survivor) {
                $loser = (string) $loser;
                if (!in_array($loser, $dropIds, true)) {
                    continue;
                }
                $traced[] = ['from' => $loser, 'to' => (string) $survivor, 'rule' => 'email', 'reason' => 'samma e-post'];
            }
            if ($traced !== [] && function_exists('jeab_kontakt_dedupe_finish')) {
                jeab_kontakt_dedupe_finish($traced);
            }
        }
    }
    if ($identity['conflict_ids'] !== []) {
        $ids = $identity['conflict_ids'];
        jeab_kontakter_mutate(function (array &$contacts) use ($ids): void {
            foreach ($ids as $id) {
                if (isset($contacts[$id]) && is_array($contacts[$id])) {
                    $contacts[$id]['conflict'] = true;
                }
            }
        });
    }
    return $plans;
}

/** Bara en kontakt som importen själv skapade, och som inget annat pekar på. */
function jeab_hs_import_contact_removable(string $id): bool
{
    $row = jeab_kontakt_get($id);
    if (!is_array($row) || (string) ($row['source'] ?? '') !== 'hs-import') {
        return false;
    }
    if (jeab_hs_import_contact_locked($id)) {
        return false;
    }
    if (function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (is_array($thread) && (string) ($thread['contact_id'] ?? '') === $id) {
                return false;
            }
        }
    }
    if (function_exists('jeab_inkorg_contacts')) {
        foreach (jeab_inkorg_contacts() as $contact) {
            if (is_array($contact) && (string) ($contact['contact_id'] ?? '') === $id) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Kontakter som ett skräpfiltrerat ärende pekar på och som får tas bort.
 * Orsaken är filtrets kod. Ingen e-post.
 *
 * @param list<array<string, mixed>> $plans
 * @param array<string, true> $still
 * @param array<string, mixed> $blocked
 * @return list<array{id:string,reason:string}>
 */
function jeab_hs_import_skip_drops(array $plans, array $still, array $blocked): array
{
    $drop = [];
    foreach ($plans as $row) {
        if (!is_array($row)) {
            continue;
        }
        $old = (string) ($row['old'] ?? '');
        if ((string) ($row['action'] ?? '') !== 'skip' || $old === '' || isset($still[$old]) || isset($drop[$old]) || isset($blocked[$old])) {
            continue;
        }
        if (!jeab_hs_import_contact_removable($old)) {
            continue;
        }
        $contact = jeab_kontakt_get($old);
        if (!is_array($contact)) {
            continue;
        }
        $contactEmail = jeab_kontakt_row_email($contact);
        $rowEmail = (string) ($row['email'] ?? '');
        if ($contactEmail !== '' && $rowEmail !== '' && $contactEmail !== $rowEmail) {
            continue;
        }
        $reason = (string) (($row['plan']['filter'] ?? '') ?: 'junk');
        if ($reason === '' || str_contains($reason, '@') || preg_match('/\s/u', $reason) === 1) {
            $reason = 'junk';
        }
        $drop[$old] = $reason;
    }
    $summary = [];
    foreach ($drop as $id => $reason) {
        $summary[] = ['id' => (string) $id, 'reason' => (string) $reason];
    }
    return $summary;
}

/**
 * Går igenom redan importerade ärenden. dry=1 är standard och skriver inget.
 * Tar bara bort kontakter som importen själv skapade (source hs-import) när
 * inget annat pekar på dem. En kontakt som fanns innan ligger kvar.
 * Länken till ett skräpärende tas bort. summary är id och orsak, utan e-post.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_reprocess(array $in): array
{
    $GLOBALS['_JEAB_HS_IMPORTING'] = true;
    try {
        $run = static fn (): array => jeab_hs_import_reprocess_work($in);
        if (function_exists('jeab_kontakt_quiet_call')) {
            $out = jeab_kontakt_quiet_call($run);
            return is_array($out) ? $out : ['ok' => false, 'error' => 'fel'];
        }
        return $run();
    } finally {
        unset($GLOBALS['_JEAB_HS_IMPORTING']);
    }
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
/**
 * Stabil sida av index-id. limit saknas = 200. Tak 500.
 *
 * @param array<string, mixed> $in
 * @return array{ids: list<string>, offset: int, limit: int, total: int, next_offset: int|null}
 */
function jeab_hs_import_id_page(array $in): array
{
    $ids = array_keys(jeab_hs_import_index()['by_id']);
    sort($ids, SORT_STRING);
    $offset = max(0, (int) ($in['offset'] ?? 0));
    $limit = array_key_exists('limit', $in) ? (int) $in['limit'] : 200;
    if ($limit < 1) {
        $limit = 200;
    }
    $limit = min(500, $limit);
    $total = count($ids);
    $page = array_values(array_slice($ids, $offset, $limit));
    $next = $offset + count($page);

    return [
        'ids' => $page,
        'offset' => $offset,
        'limit' => $limit,
        'total' => $total,
        'next_offset' => $next < $total ? $next : null,
    ];
}

/** Kontakt-id på ärenden utanför sidan. De får inte raderas för att sidan inte såg dem. @param list<string> $pageIds @return array<string, true> */
function jeab_hs_import_contacts_outside(array $pageIds): array
{
    $page = array_fill_keys($pageIds, true);
    $still = [];
    foreach (jeab_hs_import_index()['by_id'] as $id => $row) {
        if (isset($page[(string) $id]) || !is_array($row)) {
            continue;
        }
        $cid = (string) ($row['contact_id'] ?? '');
        if ($cid !== '') {
            $still[$cid] = true;
        }
    }

    return $still;
}

/**
 * inbox.reindex_dates. dry=1 som standard. limit (standard 200) och offset.
 * Rapporten är bara antal. Inga namn, adresser eller ämnen.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_reindex_dates(array $in): array
{
    $dry = jeab_hs_import_dry($in);
    $page = jeab_hs_import_id_page($in);
    $changed = 0;
    $unchanged = 0;
    $missing = 0;
    foreach ($page['ids'] as $tid) {
        $conv = jeab_hs_import_read_conv((string) $tid);
        if (!is_array($conv)) {
            $missing++;
            continue;
        }
        $row = jeab_hs_import_index()['by_id'][(string) $tid] ?? null;
        $next = jeab_hs_import_list_at($conv);
        $cur = is_array($row) ? jeab_hs_import_time_or_empty((string) ($row['updated_at'] ?? '')) : '';
        if ($cur === $next) {
            $unchanged++;
            continue;
        }
        $changed++;
        if (!$dry) {
            jeab_hs_import_index_append(jeab_hs_import_index_row($conv));
        }
    }

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'offset' => $page['offset'],
        'limit' => $page['limit'],
        'total' => $page['total'],
        'scanned' => count($page['ids']),
        'changed' => $changed,
        'unchanged' => $unchanged,
        'missing' => $missing,
        'next_offset' => $page['next_offset'],
    ];
}

function jeab_hs_import_reprocess_work(array $in): array
{
    $dry = jeab_hs_import_dry($in);
    $page = jeab_hs_import_id_page($in);
    $report = [
        'ok' => true,
        'dry' => $dry,
        'offset' => $page['offset'],
        'limit' => $page['limit'],
        'total' => $page['total'],
        'next_offset' => $page['next_offset'],
        'cases' => 0,
        'contacts_cleared' => 0,
        'contacts_removed' => 0,
        'contacts_merged' => 0,
        'contacts_conflict' => 0,
        'contacts_recreated' => 0,
        'names_normalized' => 0,
        'contacts_kept' => 0,
        'snippets_cleaned' => 0,
        'contacts_created' => 0,
    ];
    unset($GLOBALS['_JEAB_HS_DOMAIN_STATS'], $GLOBALS['_JEAB_HS_CUSTOMER_TIES']);
    $plans = [];
    foreach ($page['ids'] as $tid) {
        $conv = jeab_hs_import_read_conv((string) $tid);
        if (!is_array($conv)) {
            continue;
        }
        $report['cases']++;
        $hint = jeab_hs_import_hint_from_conv($conv);
        $plan = jeab_hs_import_contact_plan($hint);
        $oldId = (string) ($conv['contact_id'] ?? '');
        if ($oldId === '' && is_array($conv['meta'] ?? null)) {
            $oldId = (string) ($conv['meta']['contact_id'] ?? '');
        }
        $shown = (string) ($plan['shown'] ?? '');
        $email = mb_strtolower(jeab_kontakt_email((string) ($hint['email'] ?? '')));
        $renamed = false;
        foreach ((array) ($conv['messages'] ?? []) as $m) {
            if (!is_array($m) || !empty($m['internal_note'])) {
                continue;
            }
            $from = mb_strtolower(jeab_kontakt_email((string) ($m['from'] ?? '')));
            if ($email !== '' && $from === $email && $shown !== '' && (string) ($m['from_name'] ?? '') !== $shown) {
                $renamed = true;
                break;
            }
        }
        if ($renamed) {
            $report['names_normalized']++;
        }
        if (jeab_hs_import_case_quote_cleaned($conv)) {
            $report['snippets_cleaned']++;
        }
        $newId = $plan['action'] === 'skip' ? '' : (string) $plan['id'];
        if ($plan['action'] === 'skip') {
            if ($oldId !== '') {
                $report['contacts_cleared']++;
            }
        } else {
            $report['contacts_kept']++;
            if ($newId === '' && $plan['action'] === 'create') {
                $report['contacts_created']++;
            }
        }
        $plans[] = [
            'tid' => (string) $tid,
            'old' => $oldId,
            'new' => $newId,
            'action' => (string) $plan['action'],
            'shown' => $shown,
            'name' => (string) ($plan['name'] ?? ''),
            'email' => $email,
            'hint' => $hint,
            'plan' => $plan,
        ];
    }
    if ($dry) {
        $byEmail = [];
        $still = [];
        foreach ($plans as $row) {
            $id = (string) $row['new'];
            if ($id !== '') {
                $still[$id] = true;
            }
            if ($row['action'] === 'skip' || $row['email'] === '') {
                continue;
            }
            if ($id !== '') {
                $byEmail[$row['email']][$id] = true;
            }
            $old = (string) $row['old'];
            if ($old !== '') {
                $oldRow = jeab_kontakt_get($old);
                $oldEmail = is_array($oldRow) ? jeab_kontakt_row_email($oldRow) : '';
                if ($oldEmail !== '' && $oldEmail === (string) $row['email']) {
                    $byEmail[$row['email']][$old] = true;
                }
            }
        }
        $emailTouch = [];
        foreach ($byEmail as $ids) {
            if (count($ids) > 1) {
                $report['contacts_merged'] += count($ids) - 1;
                foreach (array_keys($ids) as $id) {
                    $emailTouch[(string) $id] = true;
                }
            }
        }
        $identity = jeab_hs_import_identity_plan(jeab_hs_import_identity_ids($plans));
        $still += jeab_hs_import_contacts_outside($page['ids']);
        $summary = jeab_hs_import_skip_drops($plans, $still, $identity['replace']);
        $report['summary'] = $summary;
        $report['contacts_removed'] = count($summary);
        $report['contacts_merged'] += jeab_hs_import_identity_extra($identity, $emailTouch);
        $report['contacts_conflict'] = $identity['conflict'];
        $report['contacts_recreated'] = $report['contacts_created'];
        return $report;
    }
    $cp = jeab_hs_import_checkpoint();
    foreach ($plans as $i => $row) {
        $conv = jeab_hs_import_read_conv((string) $row['tid']);
        if (!is_array($conv)) {
            continue;
        }
        $newId = (string) $row['new'];
        if ($row['action'] === 'create' && $newId === '') {
            $applied = jeab_hs_import_contact_apply((array) $row['hint'], '', false, $cp);
            $newId = (string) $applied['id'];
            $plans[$i]['new'] = $newId;
            $plans[$i]['action'] = (string) $applied['action'];
        } elseif ($row['action'] === 'merge' && $newId !== '') {
            $planHint = is_array($row['plan']['hint'] ?? null) ? $row['plan']['hint'] : [];
            jeab_hs_import_contact_refresh($newId, $planHint);
        }
        $email = (string) $row['email'];
        $shown = (string) $row['shown'];
        foreach ($conv['messages'] as $mi => $m) {
            if (!is_array($m) || !empty($m['internal_note'])) {
                continue;
            }
            $from = mb_strtolower(jeab_kontakt_email((string) ($m['from'] ?? '')));
            if ($email !== '' && $from === $email && $shown !== '') {
                $conv['messages'][$mi]['from_name'] = $shown;
            }
        }
        $conv['contact_id'] = $newId;
        $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
        $meta['contact_id'] = $newId;
        $meta['import_source'] = JEAB_HS_IMPORT_SOURCE;
        $conv['meta'] = $meta;
        $conv['import_source'] = JEAB_HS_IMPORT_SOURCE;
        if (jeab_hs_import_save_conv($conv)) {
            jeab_hs_import_index_append(jeab_hs_import_index_row($conv));
        }
    }
    unset($cp);
    $byEmail = [];
    foreach ($plans as $row) {
        if ($row['action'] === 'skip' || $row['email'] === '') {
            continue;
        }
        $id = (string) $row['new'];
        if ($id !== '') {
            $byEmail[$row['email']][$id] = true;
        }
        $old = (string) $row['old'];
        if ($old !== '') {
            $oldRow = jeab_kontakt_get($old);
            $oldEmail = is_array($oldRow) ? jeab_kontakt_row_email($oldRow) : '';
            if ($oldEmail !== '' && $oldEmail === (string) $row['email']) {
                $byEmail[$row['email']][$old] = true;
            }
        }
    }
    $replace = [];
    foreach ($byEmail as $ids) {
        if (count($ids) < 2) {
            continue;
        }
        $list = array_keys($ids);
        $survivor = (string) $list[0];
        foreach ($list as $id) {
            if (jeab_hs_import_contact_locked((string) $id)) {
                $survivor = (string) $id;
                break;
            }
        }
        foreach ($list as $id) {
            if ((string) $id === $survivor) {
                continue;
            }
            $replace[(string) $id] = $survivor;
            if (!jeab_hs_import_contact_locked((string) $id)) {
                $report['contacts_merged']++;
            }
        }
    }
    if ($replace !== []) {
        foreach ($plans as $i => $row) {
            $id = (string) $row['new'];
            if (!isset($replace[$id])) {
                continue;
            }
            $plans[$i]['new'] = $replace[$id];
            $conv = jeab_hs_import_read_conv((string) $row['tid']);
            if (!is_array($conv)) {
                continue;
            }
            $conv['contact_id'] = $replace[$id];
            $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
            $meta['contact_id'] = $replace[$id];
            $conv['meta'] = $meta;
            if (jeab_hs_import_save_conv($conv)) {
                jeab_hs_import_index_append(jeab_hs_import_index_row($conv));
            }
        }
        $dropIds = [];
        foreach ($replace as $loser => $survivor) {
            unset($survivor);
            if (!jeab_hs_import_contact_locked((string) $loser)) {
                $dropIds[] = (string) $loser;
            }
        }
        if ($dropIds !== []) {
            $traced = [];
            jeab_kontakter_mutate(function (array &$contacts) use ($dropIds, $replace, &$traced): void {
                foreach ($replace as $loser => $survivor) {
                    $loser = (string) $loser;
                    $survivor = (string) $survivor;
                    if (!in_array($loser, $dropIds, true) || !isset($contacts[$loser], $contacts[$survivor]) || !is_array($contacts[$loser]) || !is_array($contacts[$survivor])) {
                        continue;
                    }
                    if (function_exists('jeab_kontakt_merge_rows')) {
                        $contacts[$survivor] = jeab_kontakt_merge_rows($contacts[$survivor], $contacts[$loser], 'email');
                        $contacts[$survivor]['id'] = $survivor;
                        $traced[] = ['from' => $loser, 'to' => $survivor, 'rule' => 'email', 'reason' => 'samma e-post'];
                    }
                }
                foreach ($dropIds as $id) {
                    unset($contacts[$id]);
                }
            });
            if ($traced !== [] && function_exists('jeab_kontakt_dedupe_finish')) {
                jeab_kontakt_dedupe_finish($traced);
            }
        }
    }
    $identity = jeab_hs_import_identity_plan(jeab_hs_import_identity_ids($plans));
    $report['contacts_merged'] += jeab_hs_import_identity_extra($identity, $replace);
    $report['contacts_conflict'] = $identity['conflict'];
    $plans = jeab_hs_import_identity_apply($plans, $identity);
    $still = [];
    foreach ($plans as $row) {
        if ((string) $row['new'] !== '') {
            $still[(string) $row['new']] = true;
        }
    }
    $blocked = $replace;
    foreach ($identity['replace'] as $loser => $survivor) {
        unset($survivor);
        $blocked[(string) $loser] = true;
    }
    $still += jeab_hs_import_contacts_outside($page['ids']);
    $summary = jeab_hs_import_skip_drops($plans, $still, $blocked);
    if ($summary !== []) {
        $ids = array_map(static fn(array $row): string => (string) $row['id'], $summary);
        // Skräpkontakterna är redan utvalda. Taket i jeab_kontakter_decided_remove
        // gäller; över taket tas ingenting bort och rapporten säger det.
        $gone = jeab_kontakter_decided_remove(static function (array &$contacts) use ($ids): bool {
            foreach ($ids as $id) {
                unset($contacts[$id]);
            }

            return true;
        }, 'hs_import.skip_drops');
        if ($gone !== true) {
            $report['contacts_remove_blocked'] = $GLOBALS['_JEAB_KONTAKTER_DECIDED_BLOCKED'] ?? true;
            $summary = [];
        }
    }
    $report['summary'] = $summary;
    $report['contacts_removed'] = count($summary);
    $report['contacts_recreated'] = $report['contacts_created'];
    if (!$dry && function_exists('jeab_kontakt_dedupe_fold')) {
        $folded = jeab_kontakter_mutate(static function (array &$contacts): array {
            $store = function_exists('jeab_store') ? jeab_store() : null;
            return jeab_kontakt_dedupe_fold($contacts, $store instanceof JeabStore ? $store : null);
        });
        if (is_array($folded) && $folded !== []) {
            jeab_kontakt_dedupe_finish($folded);
        }
    }
    return $report;
}

/**
 * Identitetsihopslagningar som e-postpasset inte redan räknat.
 *
 * @param array{merged?:int,replace?:array<string,string>} $identity
 * @param array<string, mixed> $emailTouch
 */
function jeab_hs_import_identity_extra(array $identity, array $emailTouch): int
{
    $n = (int) ($identity['merged'] ?? 0);
    foreach ((array) ($identity['replace'] ?? []) as $loser => $survivor) {
        unset($survivor);
        if (isset($emailTouch[(string) $loser])) {
            $n--;
        }
    }
    return max(0, $n);
}

/**
 * Läser importerade ärenden. Skriver inget och tar inte med mejltext.
 * q är ett hsi-id, ett Help Scout-id eller ett utdrag ur namn eller e-post.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_contact_diag(array $in): array
{
    $query = trim((string) ($in['q'] ?? $in['id'] ?? $in['query'] ?? ''));
    if ($query === '') {
        return ['ok' => false, 'error' => 'q saknas'];
    }
    $needle = jeab_kontakt_fold_text($query);
    $cases = [];
    foreach (jeab_hs_import_index()['by_id'] as $tid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = (string) $tid;
        $conv = jeab_hs_import_read_conv($tid);
        if (!is_array($conv)) {
            continue;
        }
        $hsId = (string) (($conv['hs_ids']['conversation'] ?? '') ?: ($row['hs_conversation_id'] ?? ''));
        $hint = jeab_hs_import_hint_from_conv($conv);
        $email = (string) ($hint['email'] ?? '');
        $display = (string) ($hint['name_display'] ?? '');
        $first = (string) ($hint['name_first'] ?? '');
        $last = (string) ($hint['name_last'] ?? '');
        $hay = jeab_kontakt_fold_text($tid . ' ' . $hsId . ' ' . $email . ' ' . $display . ' ' . $first . ' ' . $last . ' ' . (string) ($hint['name'] ?? ''));
        $hit = $query === $tid || $query === $hsId || ($needle !== '' && str_contains($hay, $needle));
        if (!$hit) {
            continue;
        }
        $plan = jeab_hs_import_contact_plan($hint);
        $filter = (string) ($plan['filter'] ?? ($plan['action'] === 'skip' ? 'junk' : 'kept'));
        $cases[] = [
            'id' => $tid,
            'hs_id' => $hsId,
            'first' => $first,
            'last' => $last,
            'display' => $display,
            'normalised' => (string) ($plan['shown'] ?? ''),
            'filter' => $filter,
            'reason' => (string) ($plan['reason'] ?? ''),
        ];
        if (count($cases) >= 50) {
            break;
        }
    }
    return ['ok' => true, 'cases' => $cases];
}

/**
 * @param array<string, mixed> $in
 * @return list<string>
 */
function jeab_hs_import_id_list(array $in): array
{
    $raw = $in['ids'] ?? $in['id'] ?? [];
    if (is_int($raw) || is_float($raw)) {
        $raw = [(string) $raw];
    }
    if (is_string($raw)) {
        $raw = preg_split('/[\s,;]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        if (is_array($item)) {
            $item = $item['id'] ?? $item['number'] ?? '';
        }
        $token = trim((string) $item);
        if ($token === '' || isset($seen[$token])) {
            continue;
        }
        $seen[$token] = true;
        $out[] = $token;
        if (count($out) >= 50) {
            break;
        }
    }
    return $out;
}

function jeab_hs_import_id_digits(string $token): string
{
    if (preg_match('/^(?:hs-|#)?(\d+)$/i', trim($token), $m) === 1) {
        return $m[1];
    }
    return '';
}

/**
 * Tråd som redan finns: live hs-<id>, indexet, eller sparad hs-metadata.
 *
 * @return list<string>
 */
function jeab_hs_import_present_threads(string $digits): array
{
    if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
        return [];
    }
    $out = [];
    $add = static function (string $id) use (&$out): void {
        if ($id !== '' && !in_array($id, $out, true)) {
            $out[] = $id;
        }
    };
    $liveId = 'hs-' . $digits;
    $meta = function_exists('jeab_inkorg_threads_meta') ? jeab_inkorg_threads_meta() : [];
    if (isset($meta[$liveId])) {
        $add($liveId);
    }
    foreach ($meta as $tid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $hs = is_array($row['hs'] ?? null) ? $row['hs'] : [];
        $id = trim((string) ($hs['id'] ?? ''));
        $number = trim((string) ($hs['number'] ?? ''));
        if ($id === $digits || $number === $digits) {
            $add((string) $tid);
        }
    }
    if (function_exists('jeab_inkorg_messages')) {
        foreach (jeab_inkorg_messages() as $message) {
            if (!is_array($message)) {
                continue;
            }
            $tid = (string) ($message['thread_id'] ?? '');
            if ($tid === $liveId || (string) ($message['hs_conversation_id'] ?? '') === $digits) {
                $add($tid !== '' ? $tid : $liveId);
            }
        }
    }
    $key = jeab_hs_import_thread_key($digits);
    $index = jeab_hs_import_index();
    if (isset($index['by_id'][$key])) {
        $add($key);
    }
    foreach ($index['by_id'] as $tid => $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = (string) $tid;
        $storedId = trim((string) ($row['hs_conversation_id'] ?? ''));
        $storedNumber = trim((string) ($row['hs_number'] ?? ''));
        if ($storedId === $digits || ($storedNumber !== '' && $storedNumber === $digits)) {
            $add($tid);
            continue;
        }
        // Tomma fält räknas inte som träff. Då läses konversationsfilen, där id och nummer ligger.
        if ($storedId !== '' || $storedNumber !== '') {
            continue;
        }
        $conv = jeab_hs_import_read_conv($tid);
        if (!is_array($conv)) {
            continue;
        }
        $bags = [];
        if (is_array($conv['hs_ids'] ?? null)) {
            $bags[] = $conv['hs_ids'];
        }
        if (is_array($conv['meta']['hs_ids'] ?? null)) {
            $bags[] = $conv['meta']['hs_ids'];
        }
        if (is_array($conv['meta']['hs'] ?? null)) {
            $bags[] = $conv['meta']['hs'];
        }
        foreach ($bags as $bag) {
            $id = trim((string) ($bag['conversation'] ?? $bag['id'] ?? ''));
            $number = trim((string) ($bag['number'] ?? ''));
            if ($id === $digits || $number === $digits) {
                $add($tid);
                break;
            }
        }
    }
    return $out;
}

/**
 * @return array<string, mixed>
 */
function jeab_hs_import_fetch_conversation(string $digits): array
{
    try {
        $conv = jeab_hs_import_get_json('conversations/' . rawurlencode($digits));
        if ((string) ($conv['id'] ?? '') !== '') {
            return $conv;
        }
    } catch (Throwable $e) {
        if (jeab_hs_import_safe_error($e) !== 'hs_http_404') {
            throw $e;
        }
    }
    foreach (jeab_hs_import_targets('all') as $mailboxId) {
        $list = jeab_hs_import_get_json('conversations', [
            'mailbox' => $mailboxId,
            'status' => 'all',
            'query' => '(number:' . $digits . ')',
            'page' => 1,
        ]);
        foreach ((array) ($list['_embedded']['conversations'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $number = trim((string) ($row['number'] ?? ''));
            if ($id !== $digits && $number !== $digits) {
                continue;
            }
            if ($id !== '' && !isset($row['mailboxId']) && !isset($row['primaryCustomer'])) {
                return jeab_hs_import_get_json('conversations/' . rawurlencode($id));
            }
            return $row;
        }
    }
    return [];
}

/**
 * @param array<string, mixed> $conversation
 * @return array{0: string, 1: int}
 */
function jeab_hs_import_company_of(array $conversation): array
{
    $mailbox = (int) ($conversation['mailboxId'] ?? 0);
    if ($mailbox < 1 && is_array($conversation['mailbox'] ?? null)) {
        $mailbox = (int) ($conversation['mailbox']['id'] ?? 0);
    }
    foreach (jeab_hs_import_targets('all') as $company => $id) {
        if ($id === $mailbox) {
            return [$company, $id];
        }
    }
    return ['', 0];
}

/**
 * Vanlig Grok-klassning (produkt, säljare, kategori). Källan förblir import
 * så automatiken hoppar över mejlet; klassningen själv gör det inte.
 *
 * @param array<string, mixed> $conversation
 * @return array<string, mixed>
 */
function jeab_hs_import_grok_labels(array $conversation, string $company): array
{
    if (!function_exists('jeab_inkorg_classify')) {
        return [];
    }
    $cid = (string) ($conversation['id'] ?? '');
    $threads = $cid !== '' ? jeab_hs_import_threads($cid) : [];
    $from = '';
    $body = '';
    foreach ($threads as $thread) {
        if (!is_array($thread) || (string) ($thread['type'] ?? '') !== 'customer') {
            continue;
        }
        $cust = (array) ($thread['customer'] ?? $conversation['primaryCustomer'] ?? []);
        $by = (array) ($thread['createdBy'] ?? []);
        $email = (string) ($cust['email'] ?? $by['email'] ?? '');
        if ($from === '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $from = $email;
        }
        $plain = jeab_hs_import_plain((string) ($thread['body'] ?? ''));
        if ($plain !== '') {
            $body .= ($body === '' ? '' : "\n") . $plain;
        }
    }
    if ($from === '') {
        $from = trim((string) ($conversation['primaryCustomer']['email'] ?? ''));
    }
    $msg = [
        'from' => $from,
        'from_name' => '',
        'subject' => (string) ($conversation['subject'] ?? ''),
        'body' => mb_substr($body, 0, 4000),
        'source' => JEAB_HS_IMPORT_SOURCE,
        'company' => $company,
    ];
    try {
        $labels = jeab_inkorg_classify($msg);
    } catch (Throwable $e) {
        unset($e);
        return [];
    }
    return is_array($labels) ? $labels : [];
}

/**
 * @param array<string, mixed> $labels
 */
function jeab_hs_import_apply_grok(string $tid, array $labels): void
{
    if ($labels === []) {
        return;
    }
    $conv = jeab_hs_import_read_conv($tid);
    if (!is_array($conv)) {
        return;
    }
    foreach ($conv['messages'] as $i => $message) {
        if (!is_array($message)) {
            continue;
        }
        $conv['messages'][$i]['source'] = JEAB_HS_IMPORT_SOURCE;
        $conv['messages'][$i]['no_auto'] = true;
        if ((string) ($message['hs_thread_type'] ?? '') === 'note' || !empty($message['internal_note'])) {
            continue;
        }
        $conv['messages'][$i]['labels'] = $labels;
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $meta['import_source'] = JEAB_HS_IMPORT_SOURCE;
    $meta['no_auto'] = true;
    if (is_array($labels['case_class'] ?? null)) {
        $meta['case_class'] = $labels['case_class'];
    }
    if ((array) ($labels['add_tags'] ?? []) !== []) {
        $meta['tags'] = array_values(array_unique(array_merge(
            array_map('strval', (array) ($meta['tags'] ?? [])),
            array_map('strval', (array) $labels['add_tags'])
        )));
    }
    $conv['meta'] = $meta;
    $conv['import_source'] = JEAB_HS_IMPORT_SOURCE;
    $conv['no_auto'] = true;
    if (!jeab_hs_import_save_conv($conv) || !jeab_hs_import_index_append(jeab_hs_import_index_row($conv))) {
        throw new RuntimeException('hs_fel');
    }
}

function jeab_hs_import_promote_enabled(array $in): bool
{
    $v = $in['promote'] ?? false;
    if (is_string($v)) {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes'], true);
    }
    return $v === true || $v === 1;
}

/** Standard 5, tak 20. Ett anrop ska inte hinna ta timeout. */
function jeab_hs_import_ids_limit(array $in): int
{
    if (!array_key_exists('limit', $in) || $in['limit'] === '' || $in['limit'] === null) {
        return 5;
    }
    $n = (int) $in['limit'];
    if ($n < 1) {
        $n = 1;
    }
    return min(20, $n);
}

function jeab_hs_import_thread_kind(string $tid): string
{
    if (str_starts_with($tid, 'hsi-')) {
        return 'hsi';
    }
    if (str_starts_with($tid, 'hs-')) {
        return 'hs';
    }
    return '';
}

/**
 * @param list<string> $hits
 * @return array{0: list<string>, 1: list<string>}
 */
function jeab_hs_import_split_hits(array $hits): array
{
    $live = [];
    $hsi = [];
    foreach ($hits as $tid) {
        if (str_starts_with($tid, 'hsi-')) {
            $hsi[] = $tid;
        } else {
            $live[] = $tid;
        }
    }
    return [$live, $hsi];
}

/** @param array<string, mixed> $conv @return array{0: string, 1: string} */
function jeab_hs_import_conv_identity(array $conv): array
{
    $bags = [];
    if (is_array($conv['hs_ids'] ?? null)) {
        $bags[] = $conv['hs_ids'];
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    if (is_array($meta['hs_ids'] ?? null)) {
        $bags[] = $meta['hs_ids'];
    }
    if (is_array($meta['hs'] ?? null)) {
        $bags[] = $meta['hs'];
    }
    $id = '';
    $number = '';
    foreach ($bags as $bag) {
        if ($id === '') {
            $id = trim((string) ($bag['conversation'] ?? $bag['id'] ?? ''));
        }
        if ($number === '') {
            $number = trim((string) ($bag['number'] ?? ''));
        }
    }
    return [$id, $number];
}

/**
 * @param list<string> $present
 * @return array<string, mixed>
 */
function jeab_hs_import_ids_present_item(array $in, string $digits, array $present, bool $dry): array
{
    [$live, $hsi] = jeab_hs_import_split_hits($present);
    if ($live !== []) {
        return [
            'thread_id' => $live[0],
            'status' => 'already_present',
            'hs_id' => $digits,
            'kind' => jeab_hs_import_thread_kind($live[0]),
            'would_send' => false,
        ];
    }
    if ($hsi === []) {
        return [
            'thread_id' => $present[0],
            'status' => 'already_present',
            'hs_id' => $digits,
            'would_send' => false,
        ];
    }
    return jeab_hs_import_promote_hsi($hsi[0], $digits, $dry);
}

/**
 * Lyft en arkivkopia till hs-<konversations-id>. Skriver inget när dry är sant.
 *
 * @return array<string, mixed>
 */
function jeab_hs_import_promote_hsi(string $hsiId, string $digits, bool $dry): array
{
    $conv = jeab_hs_import_read_conv($hsiId);
    if (!is_array($conv)) {
        return ['thread_id' => $hsiId, 'status' => 'already_present', 'hs_id' => $digits, 'kind' => 'hsi', 'would_send' => false];
    }
    [$cid, $number] = jeab_hs_import_conv_identity($conv);
    if (($cid === '' || preg_match('/^\d+$/', $cid) !== 1) && jeab_hs_import_thread_key($digits) === $hsiId) {
        $cid = $digits;
    }
    if ($cid === '' || preg_match('/^\d+$/', $cid) !== 1) {
        return ['thread_id' => '', 'status' => 'not_found', 'hs_id' => $digits, 'would_send' => false];
    }
    $liveId = 'hs-' . $cid;
    $meta = function_exists('jeab_inkorg_threads_meta') ? jeab_inkorg_threads_meta() : [];
    if (isset($meta[$liveId])) {
        return ['thread_id' => $liveId, 'status' => 'already_present', 'hs_id' => $cid, 'hsi_id' => $hsiId, 'would_send' => false];
    }
    [$live] = jeab_hs_import_split_hits(jeab_hs_import_present_threads($cid));
    if ($live !== []) {
        return ['thread_id' => $live[0], 'status' => 'already_present', 'hs_id' => $cid, 'hsi_id' => $hsiId, 'would_send' => false];
    }
    if ($dry) {
        return [
            'thread_id' => $liveId,
            'status' => 'would_promote',
            'hs_id' => $cid,
            'hsi_id' => $hsiId,
            'would_send' => false,
        ];
    }
    $written = jeab_hs_import_promote_write($conv, $hsiId, $liveId, $cid, $number);
    if ($written < 1) {
        return ['thread_id' => '', 'status' => 'not_found', 'hs_id' => $cid, 'hsi_id' => $hsiId, 'would_send' => false];
    }
    return [
        'thread_id' => $liveId,
        'status' => 'promoted',
        'hs_id' => $cid,
        'hsi_id' => $hsiId,
        'would_send' => false,
    ];
}

/**
 * Skapar den levande tråden från arkivkopians mejl. Bara sista kundmejlet klassas.
 * Källan och no_auto sätts så offert, kvittens och utskick inte kan starta.
 */
function jeab_hs_import_promote_write(array $conv, string $hsiId, string $liveId, string $cid, string $number): int
{
    if (!function_exists('jeab_inkorg_ingest')) {
        return 0;
    }
    $messages = array_values(array_filter((array) ($conv['messages'] ?? []), 'is_array'));
    if (count($messages) > 20) {
        $messages = array_slice($messages, -20);
    }
    $classifyAt = -1;
    foreach ($messages as $i => $message) {
        if (!empty($message['internal_note']) || (string) ($message['hs_thread_type'] ?? '') === 'note') {
            continue;
        }
        $classifyAt = $i;
    }
    if ($classifyAt < 0 && $messages !== []) {
        $classifyAt = count($messages) - 1;
    }
    $company = (string) ($conv['company'] ?? '');
    if ($company === 'meab') {
        $company = 'malmo';
    }
    $n = 0;
    foreach ($messages as $i => $message) {
        $from = trim((string) ($message['from'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $mid = trim((string) ($message['message_id'] ?? ''));
        if ($mid === '') {
            $mid = (string) ($message['id'] ?? $i);
        }
        $payload = [
            'message_id' => 'hs-promote-' . $cid . '-' . $mid,
            'from' => $from,
            'from_name' => (string) ($message['from_name'] ?? ''),
            'to' => (string) ($message['to'] ?? ''),
            'subject' => (string) ($message['subject'] ?? ''),
            'body' => (string) ($message['body'] ?? ''),
            'date' => (string) ($message['date'] ?? ''),
            'received_at' => (string) ($message['received_at'] ?? ''),
            'source' => JEAB_HS_IMPORT_SOURCE,
            'thread_key' => $liveId,
            'hs_conversation_id' => $cid,
        ];
        if ($company === 'jarfalla' || $company === 'malmo') {
            $payload['company'] = $company;
        }
        if (!empty($message['internal_note']) || (string) ($message['hs_thread_type'] ?? '') === 'note') {
            $payload['internal_note'] = true;
        }
        foreach (['hs_thread_type', 'hs_thread_id', 'hs_customer_id', 'hs_mailbox_id'] as $k) {
            if (isset($message[$k]) && !is_array($message[$k]) && (string) $message[$k] !== '') {
                $payload[$k] = (string) $message[$k];
            }
        }
        $existingLabels = is_array($message['labels'] ?? null) ? $message['labels'] : [];
        $typed = trim((string) ($existingLabels['type'] ?? '')) !== '';
        if ($i !== $classifyAt || $typed) {
            $payload['preset_labels'] = $existingLabels;
        }
        $saved = jeab_inkorg_ingest($payload);
        if (empty($saved['ok'])) {
            continue;
        }
        $id = (string) ($saved['id'] ?? '');
        if ($id !== '' && function_exists('jeab_inkorg_update')) {
            jeab_inkorg_update($id, static function (array $m): array {
                $m['source'] = JEAB_HS_IMPORT_SOURCE;
                $m['no_auto'] = true;
                return $m;
            });
        }
        $n++;
    }
    if ($n < 1) {
        return 0;
    }
    if (function_exists('jeab_inkorg_thread_write')) {
        jeab_inkorg_thread_write($liveId, static function (array $t) use ($hsiId, $cid, $number, $conv): array {
            $t['status'] = 'open';
            $t['import_source'] = JEAB_HS_IMPORT_SOURCE;
            $t['no_auto'] = true;
            $t['helpscout_import'] = true;
            $t['hsi_id'] = $hsiId;
            $contactId = trim((string) ($conv['contact_id'] ?? ''));
            if ($contactId !== '') {
                $t['contact_id'] = $contactId;
            }
            $hs = is_array($t['hs'] ?? null) ? $t['hs'] : [];
            $hs['id'] = $cid;
            if ($number !== '') {
                $hs['number'] = $number;
            }
            $t['hs'] = $hs;
            return $t;
        });
    }
    if (function_exists('jeab_inkorg_close_open_for_thread')) {
        try {
            jeab_inkorg_close_open_for_thread($liveId);
        } catch (Throwable $e) {
            unset($e);
        }
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $meta['promoted_thread'] = $liveId;
    $conv['meta'] = $meta;
    $conv['promoted_thread'] = $liveId;
    if (!jeab_hs_import_save_conv($conv) || !jeab_hs_import_index_append(jeab_hs_import_index_row($conv))) {
        throw new RuntimeException('hs_fel');
    }
    return $n;
}

/**
 * inbox.import_helpscout med ids. dry=1 är standard. Checkpointen skrivs inte.
 *
 * @param list<string> $tokens
 * @return array<string, mixed>
 */
function jeab_hs_import_ids_locked(array $in, array $tokens, bool $dry): array
{
    if (!isset($GLOBALS['_JEAB_HS_IMPORT_HTTP']) && jeab_hs_import_token() === null) {
        return ['ok' => false, 'error' => 'Help Scout-nyckel saknas.', 'dry' => $dry, 'items' => []];
    }
    if (!$dry) {
        $block = jeab_hs_import_disk_block($in);
        if ($block !== null) {
            return jeab_hs_import_disk_report($dry, 'all', $block);
        }
    }
    $items = [];
    $cp = ['customers' => [], 'mailboxes' => []];
    foreach ($tokens as $token) {
        $digits = jeab_hs_import_id_digits($token);
        if ($digits === '') {
            $items[$token] = ['thread_id' => '', 'status' => 'not_found', 'hs_id' => ''];
            continue;
        }
        $present = jeab_hs_import_present_threads($digits);
        if ($present !== []) {
            try {
                $items[$token] = jeab_hs_import_ids_present_item($in, $digits, $present, $dry);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => jeab_hs_import_safe_error($e), 'dry' => $dry, 'items' => $items];
            }
            continue;
        }
        try {
            $conversation = jeab_hs_import_fetch_conversation($digits);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => jeab_hs_import_safe_error($e), 'dry' => $dry, 'items' => $items];
        }
        $cid = trim((string) ($conversation['id'] ?? ''));
        if ($conversation === [] || $cid === '' || preg_match('/^\d+$/', $cid) !== 1) {
            $items[$token] = ['thread_id' => '', 'status' => 'not_found', 'hs_id' => ''];
            continue;
        }
        $number = trim((string) ($conversation['number'] ?? ''));
        $present = jeab_hs_import_present_threads($cid);
        if ($present === [] && $number !== '' && $number !== $cid) {
            $present = jeab_hs_import_present_threads($number);
        }
        if ($present !== []) {
            try {
                $items[$token] = jeab_hs_import_ids_present_item($in, $cid, $present, $dry);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => jeab_hs_import_safe_error($e), 'dry' => $dry, 'items' => $items];
            }
            continue;
        }
        [$company, $mailboxId] = jeab_hs_import_company_of($conversation);
        if ($company === '' || $mailboxId < 1) {
            $items[$token] = ['thread_id' => '', 'status' => 'not_found', 'hs_id' => $cid];
            continue;
        }
        $labels = jeab_hs_import_grok_labels($conversation, $company);
        try {
            $one = jeab_hs_import_one($conversation, $company, $mailboxId, $dry, $cp);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => jeab_hs_import_safe_error($e), 'dry' => $dry, 'items' => $items];
        }
        $tid = jeab_hs_import_thread_key($cid);
        if ((int) ($one['conversations'] ?? 0) < 1) {
            $items[$token] = ['thread_id' => '', 'status' => 'not_found', 'hs_id' => $cid];
            continue;
        }
        if ($dry) {
            $items[$token] = [
                'thread_id' => 'hs-' . $cid,
                'hsi_id' => $tid,
                'status' => 'would_import',
                'hs_id' => $cid,
                'would_send' => false,
            ];
            continue;
        }
        jeab_hs_import_apply_grok($tid, $labels);
        try {
            $promoted = jeab_hs_import_promote_hsi($tid, $cid, false);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => jeab_hs_import_safe_error($e), 'dry' => $dry, 'items' => $items];
        }
        $liveId = (string) ($promoted['thread_id'] ?? '');
        $promotedStatus = (string) ($promoted['status'] ?? '');
        if ($liveId !== '' && ($promotedStatus === 'promoted' || $promotedStatus === 'already_present')) {
            $items[$token] = [
                'thread_id' => $liveId,
                'hsi_id' => $tid,
                'status' => 'imported',
                'hs_id' => $cid,
                'would_send' => false,
            ];
            continue;
        }
        $items[$token] = $promoted;
    }
    foreach ($items as $token => $item) {
        $items[$token]['would_send'] = false;
    }
    return ['ok' => true, 'dry' => $dry, 'items' => $items];
}

/**
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_ids(array $in): array
{
    $all = jeab_hs_import_id_list($in);
    $limit = jeab_hs_import_ids_limit($in);
    $tokens = array_slice($all, 0, $limit);
    $pending = array_values(array_slice($all, $limit));
    $dry = jeab_hs_import_dry($in);
    $stamp = static function (array $out) use ($limit, $pending): array {
        $out['limit'] = $limit;
        $out['pending'] = $pending;
        return $out;
    };
    if ($tokens === []) {
        return $stamp(['ok' => true, 'dry' => $dry, 'items' => []]);
    }
    $GLOBALS['_JEAB_HS_IMPORTING'] = true;
    try {
        $run = static function () use ($in, $tokens, $dry): array {
            return jeab_hs_import_with_lock(static function () use ($in, $tokens, $dry): array {
                return jeab_hs_import_ids_locked($in, $tokens, $dry);
            });
        };
        if (function_exists('jeab_kontakt_quiet_call')) {
            $out = jeab_kontakt_quiet_call($run);
            return $stamp(is_array($out) ? $out : ['ok' => false, 'error' => 'fel']);
        }
        return $stamp($run());
    } finally {
        unset($GLOBALS['_JEAB_HS_IMPORTING']);
    }
}

/**
 * inbox.find_by_hs. Bara läsning.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_hs_import_find_hook(array $in): array
{
    if (!array_key_exists('ids', $in) && !array_key_exists('id', $in)) {
        return ['ok' => false, 'error' => 'ids saknas'];
    }
    $threads = [];
    foreach (jeab_hs_import_id_list($in) as $token) {
        $digits = jeab_hs_import_id_digits($token);
        $hits = $digits === '' ? [] : jeab_hs_import_present_threads($digits);
        $tid = (string) ($hits[0] ?? '');
        $threads[$token] = [
            'thread_id' => $tid,
            'kind' => jeab_hs_import_thread_kind($tid),
            'thread_ids' => $hits,
        ];
    }
    return ['ok' => true, 'threads' => $threads];
}

/** @param array<string, mixed> $in */
function jeab_hs_import_hook(array $in): array
{
    $action = (string) ($in['action'] ?? '');
    if ($action === 'helpscout.import_status') {
        return jeab_hs_import_status();
    }
    if ($action === 'helpscout.import') {
        return jeab_hs_import_run($in);
    }
    if ($action === 'helpscout.reprocess') {
        return jeab_hs_import_reprocess($in);
    }
    if ($action === 'helpscout.contact_diag') {
        return jeab_hs_import_contact_diag($in);
    }
    return ['ok' => false, 'error' => 'not_found'];
}

/**
 * JSON-läge utan inkorgs-PDO. Äldsta mejlets rubrik, samma ordning som listan hade.
 *
 * @return list<array{id: string, subject: string, status: string, company: string}>
 */
function jeab_hs_import_json_threads_for_contact(string $contactId): array
{
    if ($contactId === '' || !function_exists('jeab_inkorg_json_threads')) {
        return [];
    }
    $want = [];
    foreach (jeab_inkorg_json_threads() as $tid => $t) {
        if (!is_array($t) || (string) ($t['contact_id'] ?? '') !== $contactId || (string) ($t['deleted_at'] ?? '') !== '') {
            continue;
        }
        $want[(string) $tid] = $t;
    }
    if ($want === []) {
        return [];
    }
    $subject = [];
    $company = [];
    if (function_exists('jeab_inkorg_json_messages')) {
        foreach (array_reverse(jeab_inkorg_json_messages()) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $tid = (string) ($m['thread_id'] ?? '');
            if ($tid === '' || !isset($want[$tid]) || isset($subject[$tid])) {
                continue;
            }
            $subject[$tid] = (string) ($m['subject'] ?? '');
            $company[$tid] = (string) ($m['company'] ?? '');
        }
    }
    $out = [];
    foreach ($want as $tid => $t) {
        $out[] = [
            'id' => (string) $tid,
            'subject' => $subject[$tid] ?? '',
            'status' => (string) ($t['status'] ?? 'open'),
            'company' => $company[$tid] ?? '',
        ];
    }

    return $out;
}

/** @param array<string, mixed> $row */
function jeab_hs_import_list_case(array $row): ?array
{
    if (!empty($row['deleted']) || (string) ($row['deleted_at'] ?? '') !== '') {
        return null;
    }
    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '') {
        return null;
    }
    $first = is_array($row['first'] ?? null) ? $row['first'] : [];
    $subject = trim((string) ($first['subject'] ?? ''));
    if ($subject === '') {
        $subject = trim((string) ($row['subject'] ?? ''));
    }

    return [
        'id' => $id,
        'subject' => $subject,
        'status' => (string) ($row['status'] ?? 'open'),
        'company' => (string) ($row['company'] ?? ''),
    ];
}

/**
 * Senaste ärendena för kontakten. contact_id-indexet, och mejlindexet om
 * kolumnen är tom. Högst $limit rader. Ingen genomgång av hela arkivet.
 *
 * @return list<array{id: string, subject: string, status: string, company: string}>
 */
function jeab_hs_import_threads_for_contact(string $contactId, int $limit = 40): array
{
    $contactId = trim($contactId);
    if ($contactId === '') {
        return [];
    }
    if (function_exists('jeab_kontakt_card_budget_hit') && jeab_kontakt_card_budget_hit()) {
        return [];
    }
    $limit = max(1, min(80, $limit));
    $out = [];
    $seen = [];
    $push = static function (array $case) use (&$out, &$seen, $limit): bool {
        if (isset($seen[$case['id']])) {
            return count($out) >= $limit;
        }
        $seen[$case['id']] = true;
        $out[] = $case;

        return count($out) >= $limit;
    };
    $live = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
    if ($live instanceof JeabPdoInkorgStore) {
        foreach ($live->listForContact($contactId, $limit) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $case = jeab_hs_import_list_case($row);
            if ($case === null) {
                continue;
            }
            if ($push($case)) {
                return $out;
            }
        }
        if ($out === [] && function_exists('jeab_kontakt_fetch') && function_exists('jeab_kontakt_row_email')) {
            $contact = jeab_kontakt_fetch($contactId);
            $email = is_array($contact) ? jeab_kontakt_row_email($contact) : '';
            if ($email !== '') {
                foreach ($live->listForCustomer($email, $limit) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $case = jeab_hs_import_list_case($row);
                    if ($case === null) {
                        continue;
                    }
                    if ($push($case)) {
                        return $out;
                    }
                }
            }
        }
    } else {
        foreach (jeab_hs_import_json_threads_for_contact($contactId) as $case) {
            if ($push($case)) {
                return $out;
            }
        }
    }
    if (function_exists('jeab_kontakt_card_budget_hit') && jeab_kontakt_card_budget_hit()) {
        return $out;
    }
    foreach (jeab_hs_import_rows_for_contact($contactId, $limit) as $row) {
        if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $contactId || !empty($row['deleted'])) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        if ($push([
            'id' => $id,
            'subject' => (string) ($row['subject'] ?? ''),
            'status' => (string) ($row['status'] ?? 'open'),
            'company' => (string) ($row['company'] ?? ''),
        ])) {
            break;
        }
    }

    return $out;
}

function jeab_hs_import_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'bilaga';
    }
    return mb_substr($name, 0, 180);
}

/** @return array{ok: bool, bytes?: string, mime?: string, name?: string, error?: string} */
function jeab_hs_import_read_attachment(array $msg, array $att): array
{
    $size = (int) ($att['size'] ?? 0);
    if ($size > JEAB_HS_IMPORT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'too_big'];
    }
    $name = jeab_hs_import_filename((string) ($att['name'] ?? 'bilaga'));
    $mime = strtolower((string) ($att['mime'] ?? 'application/octet-stream'));
    if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime) !== 1) {
        $mime = 'application/octet-stream';
    }
    $bytes = jeab_hs_import_graph_bytes($msg, $att);
    if ($bytes === null) {
        $bytes = jeab_hs_import_hs_bytes($att);
    }
    if ($bytes === null) {
        return ['ok' => false, 'error' => 'miss'];
    }
    if (strlen($bytes) > JEAB_HS_IMPORT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'too_big'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime, 'name' => $name];
}

function jeab_hs_import_graph_find_message(string $box, string $internetId): string
{
    $internetId = trim($internetId, "<> \t");
    if ($internetId === '' || !function_exists('jeab_graph_api')) {
        return '';
    }
    $forms = [$internetId];
    if (!str_starts_with($internetId, '<')) {
        $forms[] = '<' . $internetId . '>';
    }
    foreach ($forms as $id) {
        $safe = str_replace("'", '', $id);
        $filter = rawurlencode("internetMessageId eq '" . $safe . "'");
        $res = jeab_graph_api('GET', 'users/' . rawurlencode($box) . '/messages?$filter=' . $filter . '&$select=id&$top=1');
        if ((int) ($res['code'] ?? 0) !== 200) {
            continue;
        }
        $gid = (string) ($res['body']['value'][0]['id'] ?? '');
        if ($gid !== '') {
            return $gid;
        }
    }
    return '';
}

function jeab_hs_import_graph_bytes(array $msg, array $att): ?string
{
    if (!function_exists('jeab_graph_api') || !function_exists('jeab_graph_mailboxes')) {
        return null;
    }
    $company = (string) ($att['company'] ?? $msg['company'] ?? '');
    $box = (string) (jeab_graph_mailboxes()[$company] ?? '');
    if ($box === '') {
        return null;
    }
    $gid = (string) ($att['graph_message_id'] ?? $msg['graph_id'] ?? '');
    if ($gid === '') {
        $internet = (string) ($att['internet_message_id'] ?? $msg['internet_message_id'] ?? '');
        if ($internet === '') {
            return null;
        }
        $gid = jeab_hs_import_graph_find_message($box, $internet);
    }
    if ($gid === '') {
        return null;
    }
    $list = jeab_graph_api('GET', 'users/' . rawurlencode($box) . '/messages/' . rawurlencode($gid) . '/attachments?$select=id,name,size,contentType');
    if ((int) ($list['code'] ?? 0) !== 200) {
        return null;
    }
    $wantId = trim((string) ($att['graph_attachment_id'] ?? ''));
    $want = mb_strtolower(trim((string) ($att['name'] ?? '')));
    $hits = [];
    foreach ((array) ($list['body']['value'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((int) ($row['size'] ?? 0) > JEAB_HS_IMPORT_MAX_BYTES) {
            continue;
        }
        $rowId = trim((string) ($row['id'] ?? ''));
        if ($rowId === '') {
            continue;
        }
        if ($wantId !== '') {
            if ($rowId === $wantId) {
                $hits = [$rowId];
                break;
            }
            continue;
        }
        if ($want !== '' && mb_strtolower(trim((string) ($row['name'] ?? ''))) !== $want) {
            continue;
        }
        $hits[] = $rowId;
    }
    // Sparat Graph-id vinner. Bara namn: flera träffar (två offert.pdf) hämtas inte.
    if ($wantId !== '') {
        $aid = $hits[0] ?? '';
    } elseif (count($hits) === 1) {
        $aid = $hits[0];
    } else {
        return null;
    }
    if ($aid === '') {
        return null;
    }
    $one = jeab_graph_api('GET', 'users/' . rawurlencode($box) . '/messages/' . rawurlencode($gid) . '/attachments/' . rawurlencode($aid));
    if ((int) ($one['code'] ?? 0) !== 200) {
        return null;
    }
    $b64 = (string) ($one['body']['contentBytes'] ?? '');
    if ($b64 === '') {
        return null;
    }
    $bin = base64_decode($b64, true);
    if (!is_string($bin) || strlen($bin) > JEAB_HS_IMPORT_MAX_BYTES) {
        return null;
    }
    return $bin;
}

function jeab_hs_import_hs_bytes(array $att): ?string
{
    $cid = (string) ($att['hs_conversation_id'] ?? '');
    $aid = (string) ($att['hs_attachment_id'] ?? '');
    if (preg_match('/^\d+$/', $cid) !== 1 || preg_match('/^\d+$/', $aid) !== 1) {
        return null;
    }
    if ((int) ($att['size'] ?? 0) > JEAB_HS_IMPORT_MAX_BYTES) {
        return null;
    }
    try {
        $res = jeab_hs_import_http('GET', 'conversations/' . $cid . '/attachments/' . $aid . '/data');
    } catch (Throwable $e) {
        unset($e);
        return null;
    }
    if ((int) $res['code'] !== 200) {
        return null;
    }
    $body = $res['body'];
    if ($body === '' || strlen($body) > JEAB_HS_IMPORT_MAX_BYTES) {
        return null;
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !array_key_exists('data', $json)) {
        return null;
    }
    $state = strtolower(trim((string) ($json['state'] ?? 'ok')));
    if ($state !== '' && $state !== 'ok') {
        return null;
    }
    $bin = base64_decode((string) $json['data'], true);
    if (!is_string($bin) || $bin === '' || strlen($bin) > JEAB_HS_IMPORT_MAX_BYTES) {
        return null;
    }
    return $bin;
}

function jeab_hs_import_send_attachment(string $messageId, int $index): void
{
    if (!function_exists('jeab_auth_ok') || !jeab_auth_ok()) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo "Logga in.\n";
        return;
    }
    $msg = jeab_inkorg_get($messageId);
    if (is_array($msg) && function_exists('jeab_inkorg_may_fetch_attachment') && !jeab_inkorg_may_fetch_attachment($msg)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo "Inte behörig.\n";
        return;
    }
    $att = is_array($msg) ? ($msg['attachments'][$index] ?? null) : null;
    if (!is_array($msg) || !is_array($att)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo "Bilagan finns inte.\n";
        return;
    }
    $read = jeab_hs_import_read_attachment($msg, $att);
    if (empty($read['ok'])) {
        $tooBig = (string) ($read['error'] ?? '') === 'too_big';
        http_response_code($tooBig ? 413 : 502);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo $tooBig ? "Bilagan är för stor.\n" : "Bilagan kunde inte hämtas.\n";
        return;
    }
    $name = jeab_hs_import_filename((string) ($read['name'] ?? 'bilaga'));
    header('Content-Type: ' . (string) $read['mime']);
    header('Content-Length: ' . strlen((string) $read['bytes']));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo (string) $read['bytes'];
}
