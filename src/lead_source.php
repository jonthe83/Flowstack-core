<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
require_once __DIR__ . '/snapshots.php';
require_once __DIR__ . '/contacts_strict.php';
require_once __DIR__ . '/contacts_inbox_strip.php';
jeab_deny_if_direct(__FILE__);

/**
 * En lista för köpta leads och formulär. Domäner, kända adresser och no-reply.
 * Butiksorder (orders@wix.com, stores-emails.com) ingår inte.
 *
 * @return list<array{id:string,domains:list<string>,addresses:list<string>,seed:list<string>}>
 */
function jeab_lead_sources(): array
{
    return [
        ['id' => 'weply', 'domains' => ['weply.se', 'weply.dk'], 'addresses' => ['support@weply.se'], 'seed' => []],
        ['id' => 'offerta', 'domains' => ['offerta.se'], 'addresses' => [], 'seed' => []],
        ['id' => 'soloversikt', 'domains' => ['soloversikt.se', 'solöversikt.se'], 'addresses' => [], 'seed' => []],
        ['id' => 'hitta-solceller', 'domains' => ['hitta-solceller.se', 'hittasolceller.se'], 'addresses' => [], 'seed' => ['hitta-solceller.se', 'hittasolceller.se']],
        ['id' => 'solceller-nu', 'domains' => ['solceller.nu'], 'addresses' => [], 'seed' => []],
        ['id' => 'hemsol', 'domains' => ['hemsol.se'], 'addresses' => ['hej@hemsol.se'], 'seed' => []],
        ['id' => 'nordsolen', 'domains' => ['nordsolen.se'], 'addresses' => ['hej@nordsolen.se'], 'seed' => ['nordsolen.se']],
        ['id' => 'mandrill', 'domains' => ['mandrillapp.com'], 'addresses' => [], 'seed' => []],
        ['id' => 'leadkit', 'domains' => ['leadkit.com', 'nettbureau.no', 'nettbureau.se'], 'addresses' => ['leads@leadkit.com', 'support@leadkit.com'], 'seed' => ['leadkit.com']],
        ['id' => 'brabyggfirmor', 'domains' => ['brabyggfirmor.se'], 'addresses' => [], 'seed' => ['brabyggfirmor.se']],
        ['id' => 'kalkyl', 'domains' => ['kalkyl.jarfalla.example.test'], 'addresses' => ['form@kalkyl.jarfalla.example.test'], 'seed' => []],
        ['id' => 'drip', 'domains' => ['drip.se', 'getdrip.com', 'drip.com'], 'addresses' => [], 'seed' => []],
        ['id' => 'servicefinder', 'domains' => ['servicefinder.se'], 'addresses' => [], 'seed' => []],
        ['id' => 'byggahus', 'domains' => ['byggahus.se'], 'addresses' => [], 'seed' => []],
        ['id' => 'wix', 'domains' => ['wix-forms.com', 'crm.wix.com'], 'addresses' => ['info@wix-forms.com'], 'seed' => []],
        ['id' => 'omtago', 'domains' => ['omtago.se'], 'addresses' => [], 'seed' => []],
    ];
}

/** @return list<string> */
function jeab_lead_source_domains(): array
{
    $out = [];
    foreach (jeab_lead_sources() as $src) {
        foreach ($src['domains'] as $dom) {
            if (!in_array($dom, $out, true)) {
                $out[] = $dom;
            }
        }
    }
    return $out;
}

/** @return list<string> */
function jeab_lead_source_seed_domains(): array
{
    $have = [];
    foreach (jeab_lead_sources() as $src) {
        foreach ($src['seed'] as $dom) {
            $have[$dom] = true;
        }
    }
    $order = ['nordsolen.se', 'brabyggfirmor.se', 'leadkit.com', 'hitta-solceller.se', 'hittasolceller.se'];
    $out = [];
    foreach ($order as $dom) {
        if (isset($have[$dom])) {
            $out[] = $dom;
        }
    }
    foreach (array_keys($have) as $dom) {
        if (!in_array($dom, $out, true)) {
            $out[] = $dom;
        }
    }
    return $out;
}

function jeab_lead_source_local_blocked(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return false;
    }
    if (function_exists('jeab_lead_noreply_local') && jeab_lead_noreply_local($email)) {
        return true;
    }
    $local = (string) strstr($email, '@', true);
    return preg_match('/^(no-?reply|donotreply|do-not-reply)([.+_-]|$)/', $local) === 1;
}

/** Tom sträng = inte en leadkälla. */
function jeab_lead_source_id(string $emailOrDomain): string
{
    $raw = mb_strtolower(trim($emailOrDomain));
    if ($raw === '') {
        return '';
    }
    $email = str_contains($raw, '@') ? $raw : '';
    $dom = $email !== '' && function_exists('jeab_lead_email_domain')
        ? jeab_lead_email_domain($email)
        : trim($raw, " \t.<>");
    if ($email !== '') {
        foreach (jeab_lead_sources() as $src) {
            if (in_array($email, $src['addresses'], true)) {
                return $src['id'];
            }
        }
    }
    if ($dom !== '') {
        foreach (jeab_lead_sources() as $src) {
            foreach ($src['domains'] as $one) {
                $aligned = function_exists('jeab_auto_domain_aligned')
                    ? jeab_auto_domain_aligned($dom, $one)
                    : ($dom === $one || str_ends_with($dom, '.' . $one));
                if ($aligned) {
                    return $src['id'];
                }
            }
        }
        if ($email !== '' && jeab_lead_source_local_blocked($email) && ($dom === 'wix.com' || str_ends_with($dom, '.wix.com'))) {
            return 'wix';
        }
    }
    if ($email !== '' && jeab_lead_source_local_blocked($email)) {
        return 'noreply';
    }
    return '';
}

function jeab_lead_source_blocked(string $email): bool
{
    return jeab_lead_source_id($email) !== '';
}

/**
 * Formulärkuvert: Wix, kalkyl, no-reply och andra leadkällor i jeab_lead_sources()
 * när avsändaren är no-reply eller ett aviseringsnamn. Köpta leadsäljare med egen adress ingår inte.
 */
function jeab_form_envelope_is(string $email, string $fromName = ''): bool
{
    $id = jeab_lead_source_id($email);
    if ($id === '') {
        return false;
    }
    if (in_array($id, ['wix', 'kalkyl', 'noreply'], true) || jeab_lead_source_local_blocked($email)) {
        return true;
    }
    return function_exists('jeab_auto_unresolved_name') && jeab_auto_unresolved_name($fromName);
}

/**
 * Adressen som avgör leadkälla. Efter att kuvertet bytts mot kunden ligger originalet i envelope_from.
 *
 * @param array<string, mixed> $msg
 */
function jeab_msg_platform_address(array $msg): string
{
    $envelope = mb_strtolower(trim((string) ($msg['envelope_from'] ?? '')));
    if ($envelope !== '' && jeab_lead_source_id($envelope) !== '') {
        return $envelope;
    }
    return mb_strtolower(trim((string) ($msg['from'] ?? '')));
}

/**
 * Formulär: trådens avsändare blir kunden i texten. Kuvertet sparas i envelope_from.
 * Saknas en användbar kundadress lämnas mejlet orört.
 *
 * @param array<string, mixed> $msg
 * @return array<string, mixed>
 */
function jeab_form_sender_apply(array $msg): array
{
    if ((string) ($msg['envelope_from'] ?? '') !== '') {
        return $msg;
    }
    $from = mb_strtolower(trim((string) ($msg['from'] ?? '')));
    $fromName = trim((string) ($msg['from_name'] ?? ''));
    if (!jeab_form_envelope_is($from, $fromName)) {
        return $msg;
    }
    $party = function_exists('jeab_inkorg_resolve_customer')
        ? jeab_inkorg_resolve_customer($msg)
        : ['email' => '', 'name' => ''];
    $email = mb_strtolower(trim((string) ($party['email'] ?? '')));
    $name = trim((string) ($party['name'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || jeab_lead_source_blocked($email)) {
        return $msg;
    }
    if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($email)) {
        return $msg;
    }
    $msg['envelope_from'] = $from;
    $msg['envelope_from_name'] = mb_substr($fromName, 0, 120);
    $msg['from'] = $email;
    $msg['lead_source'] = jeab_lead_source_id($from);
    if ($name !== '') {
        $msg['from_name'] = mb_substr($name, 0, 120);
    }
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole()) {
        $phone = trim((string) ($party['phone'] ?? ''));
        if ($phone === '') {
            $body = (string) ($msg['body'] ?? '');
            if ($body === '') {
                $body = (string) ($msg['body_text'] ?? '');
            }
            $phone = (string) (jeab_lead_kund_line($body)['phone'] ?? '');
        }
        $address = trim((string) ($party['address'] ?? ''));
        $place = function_exists('jeab_inkorg_reminder_place_fields')
            ? jeab_inkorg_reminder_place_fields($address)
            : ['address' => $address, 'ort' => '', 'postal' => ''];
        $hint = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'company' => trim((string) ($msg['company'] ?? '')),
        ];
        if (function_exists('jeab_name_address_contact_hint_from_lead')) {
            $parsed = jeab_name_address_contact_hint_from_lead($msg, [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
            ], is_array($place) ? $place : []);
            if (trim((string) ($parsed['name'] ?? '')) !== '') {
                $hint['name'] = (string) $parsed['name'];
            }
            if (trim((string) ($parsed['address'] ?? '')) !== '') {
                $hint['address'] = (string) $parsed['address'];
                $hint['street'] = (string) ($parsed['street'] ?? $parsed['address']);
            }
            if (trim((string) ($parsed['postal_code'] ?? '')) !== '') {
                $hint['postal_code'] = (string) $parsed['postal_code'];
            }
            if (trim((string) ($parsed['city'] ?? '')) !== '') {
                $hint['city'] = (string) $parsed['city'];
            }
        }
        if (function_exists('jeab_name_address_company_for_place')) {
            $hint['company'] = jeab_name_address_company_for_place(
                (string) ($hint['company'] ?? ''),
                (string) ($hint['postal_code'] ?? ''),
                (string) ($hint['city'] ?? '')
            );
        }
        $hint['require_postal'] = true;
        $formThread = trim((string) ($msg['thread_id'] ?? ''));
        if ($formThread !== '') {
            $hint['thread_id'] = $formThread;
        }
        jeab_lead_sole_upsert($hint);
        if (trim((string) ($msg['phone'] ?? '')) === '' && $phone !== '') {
            $msg['phone'] = $phone;
        }
    }
    return $msg;
}

/**
 * «Kund: Namn · mejl · telefon», plus en ensam mejladress på raden.
 *
 * @return array{name:string,email:string,phone:string}
 */
function jeab_lead_kund_line(string $body): array
{
    $empty = ['name' => '', 'email' => '', 'phone' => ''];
    if (preg_match('/(?:^|\R)\s*kund\s*[:\-–]\s*(.+)$/imu', $body, $m) !== 1
        && preg_match('/(?:^|\s)kund\s*[:\-–]\s*(.+?)(?=\s+(?:namn|e-?post|telefon|adress|postnummer|ort|sammanfattning|profil|källa|lead-id)\s*[:\-–]|$)/iu', $body, $m) !== 1) {
        return $empty;
    }
    $parts = preg_split('/\s*[·•|]\s*/u', trim((string) $m[1])) ?: [];
    $name = '';
    $email = '';
    $phone = '';
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        if ($email === '' && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $part, $em) === 1) {
            $email = mb_strtolower($em[0]);
            continue;
        }
        if ($phone === '' && function_exists('jeab_phone_swedish_span')) {
            $span = jeab_phone_swedish_span($part);
            if ($span !== '') {
                $phone = $span;
                continue;
            }
        }
        if ($phone === '' && preg_match('/\+?[0-9][0-9 \-]{6,}[0-9]/', $part, $ph) === 1) {
            $phone = trim($ph[0]);
            continue;
        }
        if ($name === '' && !str_contains($part, '@')) {
            $name = $part;
        }
    }
    return ['name' => $name, 'email' => $email, 'phone' => $phone];
}

/**
 * Kontakt vid lead. Flaggan av skriver inget.
 *
 * @param array<string, mixed> $hint
 * @return array<string, mixed>|null
 */
function jeab_lead_sole_upsert(array $hint): ?array
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_inflow_upsert')) {
        return null;
    }
    $GLOBALS['_JEAB_LEAD_CONTACT_WATCH'] = true;
    $GLOBALS['_JEAB_LEAD_CONTACT_LAST_ERROR'] = null;
    try {
        $row = null;
        $thrown = null;
        try {
            $row = jeab_contacts_sole_inflow_upsert($hint);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        if ($thrown instanceof Throwable || !is_array($row) || trim((string) ($row['id'] ?? '')) === '') {
            $stashed = $GLOBALS['_JEAB_LEAD_CONTACT_LAST_ERROR'] ?? null;
            $err = $thrown instanceof Throwable ? $thrown : ($stashed instanceof Throwable ? $stashed : null);
            jeab_lead_contact_create_failed(jeab_lead_contact_thread_id($hint), $err);

            return null;
        }

        return $row;
    } finally {
        unset($GLOBALS['_JEAB_LEAD_CONTACT_WATCH'], $GLOBALS['_JEAB_LEAD_CONTACT_LAST_ERROR']);
    }
}

function jeab_lead_contact_thread_id(array $hint): string
{
    $tid = trim((string) ($hint['thread_id'] ?? ''));
    if ($tid === '' || preg_match('/\A[A-Za-z0-9_-]{1,80}\z/', $tid) !== 1) {
        return '';
    }

    return $tid;
}

/**
 * Synliggör att leadets kontakt inte blev sparad. Fellogg och befintlig bugg-webhook.
 * En påminnelse per ärende till ansvarig säljare. Inget till kunden.
 */
function jeab_lead_contact_create_failed(string $threadId, ?Throwable $e): void
{
    $threadId = jeab_lead_contact_thread_id(['thread_id' => $threadId]);
    $message = 'kontakt kunde inte skapas';
    if ($threadId !== '') {
        $message .= ' thread=' . $threadId;
    }
    if ($e instanceof Throwable) {
        $class = $e::class;
        $pos = strrpos($class, '\\');
        $class = $pos === false ? $class : substr($class, $pos + 1);
        $class = preg_replace('/[^A-Za-z0-9_]/', '', $class) ?? '';
        if ($class === '') {
            $class = 'Throwable';
        }
        $reason = trim(preg_replace('/\s+/u', ' ', $e->getMessage()) ?? '');
        if (function_exists('jeab_log_mask_text')) {
            $reason = jeab_log_mask_text($reason);
        }
        $reason = function_exists('mb_substr') ? mb_substr($reason, 0, 160, 'UTF-8') : substr($reason, 0, 160);
        $message .= ' ' . $class;
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
    }
    try {
        if (function_exists('jeab_log_app_error')) {
            $context = ['skip_request' => true];
            if ($threadId !== '' && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $threadId) === 1) {
                $context['ref'] = $threadId;
            }
            jeab_log_app_error('lead', $message, $context);
        }
    } catch (Throwable) {
    }
    if ($threadId === '') {
        return;
    }
    try {
        jeab_lead_contact_fail_reminder($threadId);
    } catch (Throwable) {
    }
}

function jeab_lead_contact_fail_text(): string
{
    return 'Kontakten kunde inte skapas och måste läggas till manuellt i Kontakter.';
}

/**
 * @return array{who:string,company:string}
 */
function jeab_lead_contact_fail_seller(string $threadId): array
{
    $company = 'jarfalla';
    $thread = ['id' => $threadId, 'assignee' => ''];
    if (function_exists('jeab_inkorg_thread_meta')) {
        $meta = jeab_inkorg_thread_meta($threadId);
        if (is_array($meta)) {
            $thread = $meta;
            $thread['id'] = $threadId;
        }
    }
    if (function_exists('jeab_inkorg_thread_company')) {
        $picked = jeab_inkorg_thread_company($thread);
        if ($picked !== '') {
            $company = $picked;
        }
    }
    $who = '';
    if (function_exists('jeab_reminder_case_assignee')) {
        $who = jeab_reminder_case_assignee($thread, $company);
    }
    if ($who === '') {
        $id = trim((string) ($thread['assignee'] ?? ''));
        $staff = function_exists('jeab_inkorg_staff') ? jeab_inkorg_staff() : [];
        $name = trim((string) ($staff[$id] ?? ''));
        if ($name !== '' && function_exists('jeab_resolve_person_name')) {
            $resolved = jeab_resolve_person_name($name, $company);
            $who = is_string($resolved) ? $resolved : '';
        }
    }

    return ['who' => $who, 'company' => $company];
}

function jeab_lead_contact_fail_reminder_id(JeabStore $store, string $threadId): int
{
    $isFail = static function (array $row) use ($threadId): bool {
        if (trim((string) ($row['thread_id'] ?? '')) !== $threadId) {
            return false;
        }
        if (function_exists('jeab_reminder_is_contact_fail')) {
            return jeab_reminder_is_contact_fail($row);
        }
        $blob = (string) ($row['title'] ?? '') . ' ' . (string) ($row['note'] ?? '');

        return str_contains($blob, 'Kontakt kunde inte skapas')
            || str_contains($blob, 'måste läggas till manuellt i Kontakter');
    };
    if ($store instanceof JeabPdoStore) {
        $stmt = $store->pdo()->prepare('SELECT id, title, note, thread_id FROM reminders WHERE thread_id = ? AND id > 0');
        $stmt->execute([$threadId]);
        $rows = $stmt->fetchAll();
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && $isFail($row)) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row) && $isFail($row)) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

function jeab_lead_contact_fail_reminder(string $threadId): void
{
    if (!function_exists('jeab_store')) {
        return;
    }
    $store = jeab_store();
    if (!$store instanceof JeabStore) {
        return;
    }
    if (jeab_lead_contact_fail_reminder_id($store, $threadId) > 0) {
        return;
    }
    $seller = jeab_lead_contact_fail_seller($threadId);
    $who = $seller['who'];
    $company = $seller['company'] !== '' ? $seller['company'] : 'jarfalla';
    $now = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $due = function_exists('jeab_today_start') ? jeab_today_start() : $now;
    $note = jeab_lead_contact_fail_text();
    if (function_exists('jeab_lead_mail_url')) {
        $note .= "\n" . jeab_lead_mail_url($threadId);
    }
    $store->insertReminder([
        'title' => 'Kontakt kunde inte skapas',
        'customer_name' => null,
        'email' => null,
        'phone' => null,
        'address' => null,
        'assignees' => $who !== '' ? [$who] : [],
        'company' => $company,
        'note' => $note,
        'due_at' => $due,
        'thread_id' => $threadId,
        'created_at' => $now,
        'updated_at' => $now,
        'tags' => [],
    ]);
}

/**
 * Mottagare för utskick. Leadkälla ger kunden i texten. Saknas mejlen blir why satt och email tom.
 *
 * @param array<string, mixed> $msg
 * @param array<string, mixed> $thread
 * @return array{email:string,name:string,phone:string,why:string,lead:bool,source:string}
 */
function jeab_lead_send_recipient(array $msg, array $thread = []): array
{
    $blank = ['email' => '', 'name' => '', 'phone' => '', 'why' => '', 'lead' => false, 'source' => ''];
    $platformOf = static function (array $one): string {
        if (function_exists('jeab_msg_platform_address')) {
            return jeab_msg_platform_address($one);
        }
        return mb_strtolower(trim((string) ($one['from'] ?? '')));
    };
    $probe = $msg;
    if ($thread !== [] && function_exists('jeab_auto_quote_lead_message')) {
        $leadMsg = jeab_auto_quote_lead_message($thread);
        if ($leadMsg !== [] && jeab_lead_source_id($platformOf($leadMsg)) !== '') {
            $probe = $leadMsg;
        }
    }
    $from = $platformOf($probe);
    $source = jeab_lead_source_id($from);
    if ($source === '' && (string) ($thread['lead_source'] ?? '') !== '') {
        $source = (string) $thread['lead_source'];
    }
    $purchased = function_exists('jeab_lead_purchased') && jeab_lead_purchased($probe);
    $lead = $source !== '' || $purchased;
    $body = (string) ($probe['body'] ?? '');
    if ($body === '') {
        $body = (string) ($probe['body_text'] ?? '');
    }
    $plain = function_exists('jeab_lead_customer_plain') ? jeab_lead_customer_plain($body) : $body;
    $kund = jeab_lead_kund_line($plain);
    $cust = function_exists('jeab_lead_customer')
        ? jeab_lead_customer($probe + ['body' => $body !== '' ? $body : (string) ($probe['body'] ?? '')])
        : ['name' => '', 'email' => '', 'phone' => '', 'email_from' => '', 'platform' => ''];
    $name = $kund['name'] !== '' ? $kund['name'] : trim((string) ($cust['name'] ?? ''));
    $phone = $kund['phone'] !== '' ? $kund['phone'] : trim((string) ($cust['phone'] ?? ''));
    $soleLead = static function (string $email) use ($lead, $name, $phone, $cust, $probe, $thread): void {
        if (!$lead) {
            return;
        }
        $sendThread = trim((string) ($thread['id'] ?? ''));
        jeab_lead_sole_upsert([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => trim((string) ($cust['address'] ?? '')),
            'company' => trim((string) ($probe['company'] ?? $thread['company'] ?? '')),
            'thread_id' => $sendThread,
        ]);
    };
    if (!$lead) {
        $email = mb_strtolower(trim((string) ($cust['email'] ?? '')));
        if ($email === '' || (string) ($cust['email_from'] ?? '') === 'from' || (string) ($cust['platform'] ?? '') === '') {
            $fromNow = mb_strtolower(trim((string) ($msg['from'] ?? $from)));
            if ($fromNow !== '') {
                $email = $fromNow;
            }
        }
        if (jeab_lead_source_blocked($email)) {
            return array_merge($blank, ['why' => 'mottagaren är en leadkälla', 'lead' => true, 'source' => jeab_lead_source_id($email), 'name' => $name, 'phone' => $phone]);
        }
        return array_merge($blank, ['email' => $email, 'name' => trim((string) ($cust['name'] ?? '')), 'phone' => trim((string) ($cust['phone'] ?? ''))]);
    }
    $usable = static function (string $addr) use ($probe): bool {
        $addr = mb_strtolower(trim($addr));
        if ($addr === '' || filter_var($addr, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if (jeab_lead_source_blocked($addr)) {
            return false;
        }
        if (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($addr)) {
            return false;
        }
        if (function_exists('jeab_auto_quote_seller_guard') && jeab_auto_quote_seller_guard($probe, $addr) !== '') {
            return false;
        }
        return true;
    };
    $email = '';
    $pickedWhy = '';
    if ($kund['email'] !== '' && $usable($kund['email'])) {
        $email = $kund['email'];
    }
    if ($email === '' && function_exists('jeab_lead_purchased_customer_email')) {
        $picked = jeab_lead_purchased_customer_email($probe);
        $pickedEmail = mb_strtolower(trim((string) ($picked['email'] ?? '')));
        if ($pickedEmail !== '' && $usable($pickedEmail)) {
            $email = $pickedEmail;
        } elseif ((string) ($picked['why'] ?? '') !== '') {
            $pickedWhy = (string) $picked['why'];
        }
    }
    if ($email === '') {
        $parsed = mb_strtolower(trim((string) ($cust['email'] ?? '')));
        if ($parsed !== '' && $usable($parsed) && (string) ($cust['email_from'] ?? '') !== 'from') {
            $email = $parsed;
        }
    }
    // Länkad tråd under hold: leadets frysta kopia går före trådens customer_email,
    // sedan trådens egen customer_email. display_email (kontaktens) läses aldrig här.
    $linkedHold = trim((string) ($thread['contact_id'] ?? '')) !== ''
        && jeab_contacts_inbox_hold();
    $fromParty = static function () use ($thread, $usable): string {
        // Sparad kopia: leadets mottagare i inbox_lead_party (fryst vid inläsning). Inte kontaktens mejl.
        $party = jeab_lead_party_get(trim((string) ($thread['id'] ?? '')));
        $partyEmail = mb_strtolower(trim((string) ($party['email'] ?? '')));
        return $usable($partyEmail) ? $partyEmail : '';
    };
    if ($email === '' && $linkedHold) {
        $email = $fromParty();
    }
    if ($email === '') {
        $stored = mb_strtolower(trim((string) ($thread['customer_email'] ?? '')));
        if ($usable($stored)) {
            $email = $stored;
        }
    }
    if ($email === '' && !$linkedHold) {
        $email = $fromParty();
    }
    // Kontaktens mejl fyller inte en lead utan kundadress. Den kan höra till ett annat ärende.
    if ($email === '' && function_exists('jeab_inkorg_reply_to_email')) {
        $reply = mb_strtolower(trim(jeab_inkorg_reply_to_email($probe)));
        $withoutReply = $probe;
        unset($withoutReply['reply_to']);
        if (isset($withoutReply['headers']) && is_array($withoutReply['headers'])) {
            foreach (array_keys($withoutReply['headers']) as $headerName) {
                if (strcasecmp((string) $headerName, 'Reply-To') === 0) {
                    unset($withoutReply['headers'][$headerName]);
                }
            }
        }
        $replyOk = $reply !== '' && !jeab_lead_source_blocked($reply)
            && !(function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($reply))
            && !(function_exists('jeab_auto_quote_seller_guard') && jeab_auto_quote_seller_guard($withoutReply, $reply) !== '');
        if ($replyOk) {
            $email = $reply;
        }
    }
    if ($email !== '' && function_exists('jeab_lead_bought_partner') && jeab_lead_bought_partner($email)) {
        $pickedWhy = 'mottagaren ' . $email . ' är en leadplattform, inte kunden';
        $email = '';
    }
    if ($email === '' || jeab_lead_source_blocked($email)) {
        $why = $pickedWhy !== '' ? $pickedWhy : 'hittar ingen kundadress i leadtexten';
        if (function_exists('jeab_lead_bought_partner') && jeab_lead_bought_partner($from) && !str_contains($why, 'kundadress') && !str_contains($why, '@')) {
            $why = 'hittar ingen kundadress i leadtexten';
        }
        $soleLead('');
        return array_merge($blank, ['why' => $why, 'lead' => true, 'source' => $source, 'name' => $name, 'phone' => $phone]);
    }
    $soleLead($email);
    return array_merge($blank, ['email' => $email, 'name' => $name, 'phone' => $phone, 'lead' => true, 'source' => $source]);
}

function jeab_ensure_lead_mail_tables(PDO $pdo, string $driver): void
{
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inbox_lead_party (
                thread_id VARCHAR(191) NOT NULL,
                email VARCHAR(191) NOT NULL DEFAULT \'\',
                name VARCHAR(191) NOT NULL DEFAULT \'\',
                phone VARCHAR(64) NOT NULL DEFAULT \'\',
                source_id VARCHAR(64) NOT NULL DEFAULT \'\',
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lead_send_block (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                source_id VARCHAR(64) NOT NULL DEFAULT \'\',
                path VARCHAR(32) NOT NULL DEFAULT \'\',
                reason VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lead_resend_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                thread_id VARCHAR(191) NOT NULL DEFAULT \'\',
                from_addr VARCHAR(191) NOT NULL DEFAULT \'\',
                pdf_name VARCHAR(191) NOT NULL DEFAULT \'\',
                source_id VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inbox_lead_party (
            thread_id TEXT PRIMARY KEY,
            email TEXT NOT NULL DEFAULT \'\',
            name TEXT NOT NULL DEFAULT \'\',
            phone TEXT NOT NULL DEFAULT \'\',
            source_id TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lead_send_block (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            source_id TEXT NOT NULL DEFAULT \'\',
            path TEXT NOT NULL DEFAULT \'\',
            reason TEXT NOT NULL DEFAULT \'\'
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS lead_resend_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            thread_id TEXT NOT NULL DEFAULT \'\',
            from_addr TEXT NOT NULL DEFAULT \'\',
            pdf_name TEXT NOT NULL DEFAULT \'\',
            source_id TEXT NOT NULL DEFAULT \'\'
        )'
    );
}

function jeab_lead_sql(): ?PDO
{
    try {
        if (!function_exists('jeab_driver') || jeab_driver() === 'json') {
            return null;
        }
        if (!function_exists('jeab_store')) {
            return null;
        }
        $store = jeab_store();
        if (!$store instanceof JeabPdoStore) {
            return null;
        }
        $pdo = $store->pdo();
        jeab_ensure_lead_mail_tables($pdo, jeab_driver() === 'mysql' ? 'mysql' : 'sqlite');
        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

/** Leadets sparade mottagare för tråden. Tom array när raden eller SQL saknas. */
function jeab_lead_party_get(string $tid): array
{
    $tid = trim($tid);
    if ($tid === '') {
        return [];
    }
    $pdo = jeab_lead_sql();
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT email, name, phone, source_id FROM inbox_lead_party WHERE thread_id = ?');
        $stmt->execute([mb_substr($tid, 0, 191)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }

    return is_array($row) ? $row : [];
}

function jeab_lead_party_remember(string $tid, string $email, string $name, string $phone, string $source): void
{
    $tid = trim($tid);
    if ($tid === '') {
        return;
    }
    $pdo = jeab_lead_sql();
    if (!$pdo instanceof PDO) {
        return;
    }
    $at = date('c');
    if (jeab_driver() === 'mysql') {
        $sql = 'INSERT INTO inbox_lead_party (thread_id, email, name, phone, source_id, updated_at) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name), phone = VALUES(phone), source_id = VALUES(source_id), updated_at = VALUES(updated_at)';
    } else {
        $sql = 'INSERT INTO inbox_lead_party (thread_id, email, name, phone, source_id, updated_at) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(thread_id) DO UPDATE SET email = excluded.email, name = excluded.name, phone = excluded.phone, source_id = excluded.source_id, updated_at = excluded.updated_at';
    }
    $pdo->prepare($sql)->execute([
        mb_substr($tid, 0, 191),
        mb_substr(mb_strtolower(trim($email)), 0, 191),
        mb_substr(trim($name), 0, 191),
        mb_substr(trim($phone), 0, 64),
        mb_substr($source, 0, 64),
        $at,
    ]);
}

/** Blockerad händelse utan personuppgifter. */
function jeab_lead_block_log(string $path, string $source = ''): void
{
    $pdo = jeab_lead_sql();
    if (!$pdo instanceof PDO) {
        return;
    }
    $pdo->prepare('INSERT INTO lead_send_block (created_at, source_id, path, reason) VALUES (?, ?, ?, ?)')->execute([
        date('c'),
        mb_substr($source, 0, 64),
        mb_substr($path, 0, 32),
        'lead_source',
    ]);
}

/** Tom sträng = mottagaren får användas. Annars ett fel utan adress. */
function jeab_lead_refuse_send(string $to, array $cc, string $path): string
{
    $hit = '';
    $seller = false;
    foreach (array_merge([$to], $cc) as $addr) {
        $addr = trim((string) $addr);
        if ($addr === '' || !jeab_lead_source_blocked($addr)) {
            continue;
        }
        $hit = jeab_lead_source_id($addr);
        if (function_exists('jeab_lead_seller_match') && jeab_lead_seller_match($addr) !== '') {
            $seller = true;
        }
        break;
    }
    if ($hit === '' && !jeab_lead_source_blocked($to)) {
        return '';
    }
    if ($hit === '') {
        $hit = jeab_lead_source_id($to);
    }
    jeab_lead_block_log($path, $hit);
    return $seller ? 'mottagaren är en leadsäljare' : 'mottagaren är en leadkälla';
}

/**
 * @param array<string, mixed> $msg
 * @return list<string>
 */
function jeab_lead_sent_pdf_names(array $msg): array
{
    $names = [];
    $blob = (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
    if (preg_match_all('/\[Bilaga(?:or)?:\s*([^\]]+)\]/u', $blob, $m) > 0) {
        foreach ($m[1] as $raw) {
            foreach (preg_split('/\s*,\s*/', (string) $raw) ?: [] as $one) {
                $one = trim((string) $one);
                if ($one !== '' && !in_array($one, $names, true)) {
                    $names[] = $one;
                }
            }
        }
    }
    foreach ((array) ($msg['attachments'] ?? []) as $att) {
        if (!is_array($att)) {
            continue;
        }
        $one = trim((string) ($att['filename'] ?? $att['name'] ?? ''));
        if ($one !== '' && !in_array($one, $names, true)) {
            $names[] = $one;
        }
    }
    return $names;
}

/** @return list<string> */
function jeab_lead_price_lines(string $text): array
{
    $out = [];
    $plain = function_exists('jeab_lead_customer_plain') ? jeab_lead_customer_plain($text) : $text;
    foreach (preg_split("/\n/", $plain) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/\d[\d\s\x{00A0}]{0,12}(?:kr|:-)/iu', $line) !== 1 && preg_match('/\bpris\b/iu', $line) !== 1) {
            continue;
        }
        $out[] = mb_substr($line, 0, 180);
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $thread
 * @return list<array<string, mixed>>
 */
function jeab_lead_quote_messages(array $thread): array
{
    $rows = [];
    foreach ((array) ($thread['messages'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $src = (string) ($m['source'] ?? '');
        $blob = (string) ($m['subject'] ?? '') . "\n" . (string) ($m['body'] ?? '') . "\n" . (string) ($m['body_text'] ?? '');
        $pdf = jeab_lead_sent_pdf_names($m);
        $outbound = str_starts_with($src, 'outbound');
        $quote = $outbound && ($pdf !== [] || preg_match('/offert|solceller med batteri/iu', $blob) === 1);
        if ($quote) {
            $rows[] = $m;
        }
    }
    foreach ((array) ($thread['drafts'] ?? []) as $d) {
        if (!is_array($d) || empty($d['sent'])) {
            continue;
        }
        $rows[] = $d + ['received_at' => (string) ($d['sent_at'] ?? $d['created_at'] ?? ''), 'source' => 'draft'];
    }
    return $rows;
}

/**
 * inbox.sent_log. Bara läsning.
 *
 * @param array<string, mixed> $in
 * @return array{ok:bool,rows:list<array<string,mixed>>}
 */
function jeab_inbox_sent_log(array $in): array
{
    $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;
    $domain = mb_strtolower(trim((string) ($in['domain'] ?? '')));
    $day = trim((string) ($in['date'] ?? ''));
    $onlyMismatch = !empty($in['mismatch']) && (string) $in['mismatch'] !== '0';
    $rows = [];
    $threads = function_exists('jeab_inkorg_threads') ? jeab_inkorg_threads() : [];
    foreach ($threads as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $tid = (string) ($thread['id'] ?? '');
        $customer = function_exists('jeab_norm_email')
            ? jeab_norm_email((string) ($thread['customer_email'] ?? ''))
            : mb_strtolower(trim((string) ($thread['customer_email'] ?? '')));
        foreach (jeab_lead_quote_messages($thread) as $m) {
            $to = '';
            foreach (function_exists('jeab_auto_emails_in') ? jeab_auto_emails_in((string) ($m['to'] ?? '')) : [] as $addr) {
                $to = $addr;
                break;
            }
            if ($to === '') {
                $to = mb_strtolower(trim((string) ($m['to'] ?? '')));
            }
            if ($to === '' || !str_contains($to, '@')) {
                continue;
            }
            $when = (string) ($m['received_at'] ?? $m['sent_at'] ?? $m['date'] ?? '');
            if ($day !== '' && !str_starts_with($when, $day)) {
                continue;
            }
            $toDom = function_exists('jeab_lead_email_domain') ? jeab_lead_email_domain($to) : '';
            if ($domain !== '') {
                $okDom = function_exists('jeab_auto_domain_aligned')
                    ? jeab_auto_domain_aligned($toDom, $domain)
                    : $toDom === $domain;
                if (!$okDom) {
                    continue;
                }
            }
            $pdfs = jeab_lead_sent_pdf_names($m);
            $mismatch = jeab_lead_source_blocked($to) || $customer === '' || $to !== $customer;
            if ($onlyMismatch && !$mismatch) {
                continue;
            }
            $who = jeab_inbox_sent_for_message($tid, $when, $thread);
            $rows[] = [
                'thread_id' => $tid,
                'to' => $to,
                'from' => (string) ($m['from'] ?? ''),
                'subject' => (string) ($m['subject'] ?? ''),
                'pdf' => $pdfs,
                'date' => $when,
                'mismatch' => $mismatch,
                'via' => $who['via'],
                'actor' => $who['actor'],
            ];
        }
    }
    foreach (jeab_inbox_sent_unmatched() as $extra) {
        $when = (string) ($extra['created_at'] ?? '');
        if ($day !== '' && !str_starts_with($when, $day)) {
            continue;
        }
        if ($domain !== '') {
            continue;
        }
        $rows[] = [
            'thread_id' => (string) ($extra['thread_id'] ?? ''),
            'to' => '',
            'from' => (string) ($extra['from_addr'] ?? ''),
            'subject' => '',
            'pdf' => [],
            'date' => $when,
            'mismatch' => false,
            'via' => (string) ($extra['via'] ?? 'unknown'),
            'actor' => (string) ($extra['actor'] ?? 'unknown'),
            'kind' => (string) ($extra['kind'] ?? ''),
            'outcome' => (string) ($extra['outcome'] ?? ''),
            'channel' => (string) ($extra['channel'] ?? ''),
        ];
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Fryst kopia av ett manuellt svar eller systemmejl: mottagare, ämne, text och tid.
 * Samma kodning som offertens snapshot (jeab_snapshots_encode). Tomma fält fylls inte i efteråt
 * om raden redan har en kopia.
 *
 * @return array<string, mixed>
 */
function jeab_inbox_sent_mail_snapshot(string $recipient, string $subject, string $body, string $at = ''): array
{
    $at = trim($at);
    if ($at === '') {
        $at = function_exists('jeab_snapshots_now') ? jeab_snapshots_now() : date('c');
    }
    $snap = [
        'name' => '',
        'address' => '',
        'email' => trim($recipient),
        'phone' => '',
        'owners' => [],
        'at' => $at,
        'source' => 'send',
        'recipient' => trim($recipient),
        'subject' => trim($subject),
        'body' => $body,
    ];
    if (function_exists('jeab_snapshots_normalize')) {
        return jeab_snapshots_normalize($snap);
    }

    return $snap;
}

/**
 * Sparar via, actor och den From-adress som faktiskt användes. Ingen mottagaradress i kolumnerna.
 * idem gör samma utskick till en rad: ett senare anrop med samma nyckel skriver inte en till.
 * En kopia som redan ligger på raden skrivs inte om. Saknas den fylls snapshot_json en gång.
 *
 * @param array{from?:string,idem?:string,outcome?:string,channel?:string,snapshot?:array<string,mixed>} $extra
 */
function jeab_inbox_sent_record(string $threadId, string $via, string $actor, string $kind = '', array $extra = []): void
{
    if ($via !== 'auto' && $via !== 'manual') {
        $via = 'manual';
    }
    $actor = trim($actor);
    if ($via === 'auto') {
        $actor = 'system';
    } elseif ($actor === '') {
        $actor = jeab_inbox_sent_actor('manual');
    }
    $from = mb_substr(trim((string) ($extra['from'] ?? '')), 0, 191);
    $idem = trim((string) ($extra['idem'] ?? ''));
    $bodyHash = '';
    if ($idem !== '') {
        $bodyHash = preg_match('/^[a-f0-9]{64}$/', $idem) === 1 ? $idem : hash('sha256', $idem);
    }
    $outcome = mb_substr(trim((string) ($extra['outcome'] ?? '')), 0, 32);
    $channel = mb_substr(trim((string) ($extra['channel'] ?? '')), 0, 16);
    $snapJson = '';
    if (isset($extra['snapshot']) && is_array($extra['snapshot']) && function_exists('jeab_snapshots_encode')) {
        $snapJson = jeab_snapshots_encode($extra['snapshot']);
    }
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        return;
    }
    if (!$store instanceof JeabPdoStore || !function_exists('jeab_ensure_inbox_sent')) {
        return;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    $pdo = $store->pdo();
    jeab_ensure_inbox_sent($pdo, $driver);
    $threadId = mb_substr($threadId, 0, 191);
    $kind = mb_substr($kind, 0, 32);
    if ($bodyHash !== '' && $threadId !== '') {
        $have = $pdo->prepare('SELECT id, from_addr, snapshot_json FROM inbox_sent WHERE thread_id = ? AND kind = ? AND body_hash = ? LIMIT 1');
        $have->execute([$threadId, $kind, $bodyHash]);
        $row = $have->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $id = (int) ($row['id'] ?? 0);
            if ($from !== '' && trim((string) ($row['from_addr'] ?? '')) === '') {
                $pdo->prepare('UPDATE inbox_sent SET from_addr = ? WHERE id = ?')->execute([$from, $id]);
                $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;
            }
            if ($snapJson !== '' && trim((string) ($row['snapshot_json'] ?? '')) === '') {
                $pdo->prepare('UPDATE inbox_sent SET snapshot_json = ? WHERE id = ?')->execute([$snapJson, $id]);
                $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;
            }
            return;
        }
    }
    $pdo->prepare(
        'INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, from_addr, snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        date('c'),
        $threadId,
        $via,
        mb_substr($actor, 0, 191),
        $kind,
        $outcome,
        $channel,
        $bodyHash,
        $from,
        $snapJson,
    ]);
    $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;
}

/**
 * Sent-logg och quote_sent direkt efter ett lyckat offertutskick, innan resten av ärendet skrivs.
 * Samma idem-nyckel en gång till lägger ingen ny rad och rör inte en befintlig markör mer än remember redan gör.
 */
function jeab_quote_send_commit(string $tid, string $from, string $via, string $actor, string $offer, string $idem, string $kind = 'offert', array $party = []): void
{
    $from = trim($from);
    if ($idem === '') {
        $idem = $tid . '|' . $kind . '|' . $offer . '|' . mb_strtolower($from);
    }
    $replace = !empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION']);
    $snap = [];
    if (function_exists('jeab_snapshots_normalize')) {
        $snap = jeab_snapshots_normalize($party);
    }
    try {
        jeab_inbox_sent_record($tid, $via, $actor, $kind, [
            'from' => $from,
            'idem' => $idem,
            'outcome' => 'sent',
            'channel' => 'email',
            'snapshot' => $snap,
        ]);
    } catch (Throwable $e) {
        unset($e);
    }
    if (function_exists('jeab_snapshots_commit_offert')) {
        try {
            jeab_snapshots_commit_offert($tid, $party, $replace);
        } catch (Throwable $e) {
            unset($e);
        }
    }
    if ($tid === '' || !function_exists('jeab_auto_quote_sent_remember')) {
        return;
    }
    $stamp = function_exists('jeab_auto_sent_stamp') ? jeab_auto_sent_stamp(date('c')) : date('Y-m-d H:i');
    try {
        jeab_auto_quote_sent_remember($tid, 'note', ['hit' => true, 'date' => $stamp], $offer);
    } catch (Throwable $e) {
        unset($e);
    }
}

/** Inloggad användare, eller hook när utskicket kommer utan session. */
function jeab_inbox_sent_actor(string $via, string $named = ''): string
{
    if ($via === 'auto') {
        return 'system';
    }
    $email = function_exists('jeab_auth_user_email') ? trim((string) jeab_auth_user_email()) : '';
    if ($email !== '') {
        return mb_substr($email, 0, 191);
    }
    $named = trim($named);
    if ($named !== '' && !in_array(mb_strtolower($named), ['personal', 'hook', 'system'], true) && !str_starts_with($named, 'Grok')) {
        return mb_substr($named, 0, 191);
    }
    return 'hook';
}

/**
 * @return list<array{id:int,created_at:string,thread_id:string,via:string,actor:string,kind:string,used:bool}>
 */
function jeab_inbox_sent_load(): array
{
    if (isset($GLOBALS['_JEAB_INBOX_SENT_CACHE']) && is_array($GLOBALS['_JEAB_INBOX_SENT_CACHE'])) {
        return $GLOBALS['_JEAB_INBOX_SENT_CACHE'];
    }
    $rows = [];
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        $store = null;
    }
    if ($store instanceof JeabPdoStore && function_exists('jeab_ensure_inbox_sent')) {
        $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_inbox_sent($store->pdo(), $driver);
        $stmt = $store->pdo()->query('SELECT id, created_at, thread_id, via, actor, kind, outcome, channel, from_addr FROM inbox_sent ORDER BY id ASC');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'thread_id' => (string) ($row['thread_id'] ?? ''),
                'via' => (string) ($row['via'] ?? ''),
                'actor' => (string) ($row['actor'] ?? ''),
                'kind' => (string) ($row['kind'] ?? ''),
                'outcome' => (string) ($row['outcome'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'from_addr' => (string) ($row['from_addr'] ?? ''),
                'used' => false,
            ];
        }
    }
    $GLOBALS['_JEAB_INBOX_SENT_CACHE'] = $rows;
    return $rows;
}

/**
 * @param array<string, mixed> $thread
 * @return array{via:string,actor:string}
 */
function jeab_inbox_sent_for_message(string $threadId, string $when, array $thread): array
{
    $rows = jeab_inbox_sent_load();
    $best = -1;
    $bestGap = PHP_INT_MAX;
    $stamp = strtotime($when) ?: 0;
    foreach ($rows as $i => $row) {
        if (!empty($row['used']) || (string) $row['thread_id'] !== $threadId) {
            continue;
        }
        $at = strtotime((string) $row['created_at']) ?: 0;
        $gap = $stamp === 0 || $at === 0 ? 0 : abs($stamp - $at);
        if ($gap < $bestGap) {
            $best = $i;
            $bestGap = $gap;
        }
    }
    if ($best >= 0) {
        $GLOBALS['_JEAB_INBOX_SENT_CACHE'][$best]['used'] = true;
        return [
            'via' => (string) $rows[$best]['via'],
            'actor' => (string) $rows[$best]['actor'],
        ];
    }
    return jeab_inbox_sent_infer($thread, $when);
}

/**
 * Gamla utskick utan SQL-rad. Äldre «skickad automatiskt» skrevs av både auto och manuell Skicka offert och räknas inte.
 *
 * @param array<string, mixed> $thread
 * @return array{via:string,actor:string}
 */
function jeab_inbox_sent_infer(array $thread, string $when): array
{
    $unknown = ['via' => 'unknown', 'actor' => 'unknown'];
    $stamp = strtotime($when) ?: 0;
    foreach ((array) ($thread['drafts'] ?? []) as $d) {
        if (!is_array($d)) {
            continue;
        }
        $at = strtotime((string) ($d['sent_at'] ?? $d['created_at'] ?? '')) ?: 0;
        if ($stamp !== 0 && $at !== 0 && abs($stamp - $at) > 600) {
            continue;
        }
        $body = (string) ($d['body'] ?? '');
        $author = trim((string) ($d['author'] ?? ''));
        $src = (string) ($d['source'] ?? '');
        if (($d['kind'] ?? '') === 'reply' && !empty($d['sent']) && $author !== '' && !str_starts_with($author, 'Grok')) {
            return ['via' => 'manual', 'actor' => $author];
        }
        if ($src === 'grok-rebook') {
            return ['via' => 'auto', 'actor' => 'system'];
        }
        if (str_contains($body, 'Kvittens skickad') || str_contains($body, 'Fråga om produkt skickad')) {
            return ['via' => 'auto', 'actor' => 'system'];
        }
        if (str_contains($body, 'kort svar skickat')) {
            return ['via' => 'manual', 'actor' => 'unknown'];
        }
    }
    return $unknown;
}

/**
 * SQL-rader som inte hör till en offertmejl i sent_log. Ingen adress.
 *
 * @return list<array<string, mixed>>
 */
function jeab_inbox_sent_unmatched(): array
{
    $out = [];
    foreach (jeab_inbox_sent_load() as $row) {
        if (!empty($row['used'])) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function jeab_lead_pdf_file(string $name): string
{
    if ($name === '' || !function_exists('jeab_auto_quote_seed') || !function_exists('jeab_auto_pdf_name') || !function_exists('jeab_auto_pdf_path')) {
        return '';
    }
    foreach (array_keys(jeab_auto_quote_seed()) as $key) {
        if (jeab_auto_pdf_name((string) $key) !== $name) {
            continue;
        }
        $path = jeab_auto_pdf_path((string) $key);
        return is_file($path) ? $path : '';
    }
    return '';
}

/**
 * offer.resend. dry=1 är standard och skickar inget. dry=0 kräver confirm=SAM.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_offer_resend(array $in): array
{
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($in) : true;
    $tid = trim((string) ($in['thread_id'] ?? ''));
    if ($tid === '') {
        return ['ok' => false, 'dry' => $dry ? 1 : 0, 'action' => 'stop', 'why' => 'thread_id saknas'];
    }
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    if (!is_array($thread)) {
        return ['ok' => false, 'dry' => $dry ? 1 : 0, 'action' => 'stop', 'why' => 'not_found'];
    }
    $msg = function_exists('jeab_auto_quote_lead_message') ? jeab_auto_quote_lead_message($thread) : (array) ($thread['first'] ?? []);
    $resolved = jeab_lead_send_recipient($msg, $thread);
    $email = (string) ($resolved['email'] ?? '');
    if ($email === '' || (string) ($resolved['why'] ?? '') !== '') {
        return ['ok' => false, 'dry' => $dry ? 1 : 0, 'action' => 'stop', 'why' => (string) ($resolved['why'] ?: 'kundens e-post saknas i leaden')];
    }
    $wantsNew = function_exists('jeab_snapshots_wants_new') && jeab_snapshots_wants_new($in);
    if ($wantsNew) {
        $GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION'] = true;
    }
    try {
    if ($wantsNew) {
        return [
            'ok' => false,
            'dry' => $dry ? 1 : 0,
            'action' => 'stop',
            'why' => 'ny version kan inte skapas. Offert-PDF:en byggs inte om från den nya kunden, så varken bilaga eller mejltext skickas.',
        ];
    }
    if (function_exists('jeab_snapshots_resend_email')) {
        $email = jeab_snapshots_resend_email($thread, $email, $in);
    }
    if (jeab_lead_source_blocked($email)) {
        jeab_lead_block_log('resend', jeab_lead_source_id($email));
        return ['ok' => false, 'dry' => $dry ? 1 : 0, 'action' => 'stop', 'why' => 'mottagaren är en leadkälla'];
    }
    $quotes = jeab_lead_quote_messages($thread);
    $quote = $quotes === [] ? [] : (array) $quotes[array_key_last($quotes)];
    $tos = [];
    foreach ($quotes as $m) {
        $blob = (string) ($m['to'] ?? '');
        $found = function_exists('jeab_auto_emails_in') ? jeab_auto_emails_in($blob) : [];
        if ($found === [] && str_contains($blob, '@')) {
            $found = [mb_strtolower(trim($blob))];
        }
        foreach ($found as $addr) {
            $tos[] = $addr;
        }
    }
    if (in_array($email, $tos, true)) {
        return ['ok' => false, 'dry' => $dry ? 1 : 0, 'action' => 'stop', 'why' => 'kunden har redan fått offerten'];
    }
    $pdfs = $quote !== [] ? jeab_lead_sent_pdf_names($quote) : [];
    $pdf = $pdfs[0] ?? '';
    $sender = trim((string) ($quote['from'] ?? ''));
    $body = (string) ($quote['body'] ?? $quote['body_text'] ?? '');
    $prices = jeab_lead_price_lines($body);
    $preview = [
        'ok' => true,
        'dry' => 1,
        'action' => 'preview',
        'recipient' => $email,
        'sender' => $sender,
        'pdf' => $pdf,
        'prices' => $prices,
        'sent' => 0,
    ];
    if ($dry) {
        return $preview;
    }
    if ((string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'dry' => 0, 'action' => 'stop', 'why' => 'confirm saknas'];
    }
    if ($pdf === '' || $sender === '') {
        return ['ok' => false, 'dry' => 0, 'action' => 'stop', 'why' => 'offerten saknas'];
    }
    $path = jeab_lead_pdf_file($pdf);
    if ($path === '') {
        return ['ok' => false, 'dry' => 0, 'action' => 'stop', 'why' => 'pdf saknas'];
    }
    $company = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : 'jarfalla';
    $subject = trim((string) ($quote['subject'] ?? ''));
    if ($subject === '') {
        $subject = 'Offert';
    }
    $party = [];
    $sent = jeab_outbound_graph_send($company, $email, $subject, $body !== '' ? $body : $subject, true, [[
        'filename' => $pdf,
        'content_type' => 'application/pdf',
        'content' => (string) file_get_contents($path),
    ]], [], [
        'person_email' => $sender,
        'guard_seller' => true,
        'seller_msg' => $msg,
    ]);
    if (empty($sent['ok'])) {
        return ['ok' => false, 'dry' => 0, 'action' => 'stop', 'why' => (string) ($sent['error'] ?? 'utskicket misslyckades')];
    }
    $from = trim((string) ($sent['from'] ?? ''));
    if ($from === '') {
        $from = $sender;
    }
    $resendOffer = function_exists('jeab_auto_quote_offer_key') ? jeab_auto_quote_offer_key((string) ($quote['subject'] ?? '') . ' ' . $pdf) : '';
    if ($party === [] && function_exists('jeab_snapshots_quote_party')) {
        $party = jeab_snapshots_quote_party($thread, $email, [
            'name' => (string) ($resolved['name'] ?? ''),
            'phone' => (string) ($resolved['phone'] ?? ''),
            'address' => (string) ($resolved['address'] ?? ''),
        ]);
    }
    jeab_quote_send_commit($tid, $from, 'manual', jeab_inbox_sent_actor('manual'), $resendOffer, hash('sha256', "resend\n" . $tid . "\n" . $pdf), 'resend', $party);
    if ($resendOffer !== '' && function_exists('jeab_notiser_record_send') && function_exists('jeab_notiser_quote_notice_id')) {
        jeab_notiser_record_send(jeab_notiser_quote_notice_id($resendOffer, $company, '', false), true, false, '');
    }
    if (function_exists('jeab_outbound_record')) {
        jeab_outbound_record($company, $sent, $email, $subject, $body . "\n\n[Bilaga: " . $pdf . ']', [
            'thread_id' => $tid,
            'kind' => 'offert',
            'status_after' => (string) ($thread['status'] ?? 'closed'),
            'from_name' => (string) ($quote['from_name'] ?? ''),
        ]);
    }
    $pdo = jeab_lead_sql();
    if ($pdo instanceof PDO) {
        $pdo->prepare('INSERT INTO lead_resend_log (created_at, thread_id, from_addr, pdf_name, source_id) VALUES (?, ?, ?, ?, ?)')->execute([
            date('c'),
            mb_substr($tid, 0, 191),
            mb_substr($from, 0, 191),
            mb_substr($pdf, 0, 191),
            mb_substr((string) ($resolved['source'] ?? ''), 0, 64),
        ]);
    }
    return ['ok' => true, 'dry' => 0, 'action' => 'resent', 'sent' => 1, 'sender' => $from, 'pdf' => $pdf];
    } finally {
        unset($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION']);
    }
}

/**
 * inbox.lead_recipients. dry=1 listar maskerat. dry=0 uppdaterar mottagare i SQL. Inget utskick.
 *
 * @param array<string, mixed> $in
 * @return array{ok:bool,dry:int,rows:list<array<string,mixed>>,updated:int}
 */
function jeab_lead_recipients_hook(array $in): array
{
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($in) : true;
    $rows = [];
    $updated = 0;
    $threads = function_exists('jeab_inkorg_threads') ? jeab_inkorg_threads() : [];
    foreach ($threads as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $tid = (string) ($thread['id'] ?? '');
        $msg = function_exists('jeab_inkorg_thread_form_message') ? jeab_inkorg_thread_form_message($thread) : (array) ($thread['first'] ?? []);
        $source = jeab_lead_source_id((string) ($msg['from'] ?? ''));
        if ($source === '') {
            $source = (string) ($thread['lead_source'] ?? '');
        }
        if ($source === '') {
            continue;
        }
        $resolved = jeab_lead_send_recipient($msg, $thread);
        $email = (string) ($resolved['email'] ?? '');
        $rows[] = [
            'thread_id' => $tid,
            'source' => $source,
            'suggested' => function_exists('jeab_lead_mask_email') ? jeab_lead_mask_email($email) : '',
            'has_customer' => $email !== '' && !jeab_lead_source_blocked($email),
        ];
        if ($dry || $email === '' || jeab_lead_source_blocked($email)) {
            continue;
        }
        $name = (string) ($resolved['name'] ?? '');
        $phone = (string) ($resolved['phone'] ?? '');
        if (function_exists('jeab_inkorg_thread_write')) {
            jeab_inkorg_thread_write($tid, static function (array $t) use ($email, $name, $phone, $source): array {
                $linked = jeab_contacts_inbox_strip_flags_on()
                    && trim((string) ($t['contact_id'] ?? '')) !== '';
                if (!$linked) {
                    $t['customer_email'] = $email;
                    $t['customer_name'] = $name;
                    $t['customer_source'] = 'body';
                    if ($phone !== '') {
                        $t['customer_phone'] = $phone;
                    }
                }
                $t['lead_source'] = $source;
                $t['updated_at'] = date('c');
                return $t;
            });
        }
        jeab_lead_party_remember($tid, $email, $name, $phone, $source);
        if (function_exists('jeab_inkorg_contact_ensure')) {
            jeab_inkorg_contact_ensure([
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
                'address' => '',
                'platform' => $source,
                'source' => 'body',
            ], $msg);
        }
        $updated++;
    }
    return ['ok' => true, 'dry' => $dry ? 1 : 0, 'rows' => $rows, 'updated' => $updated];
}
