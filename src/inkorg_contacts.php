<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_inbox_strip.php';
require_once __DIR__ . '/normalize.php';
require_once __DIR__ . '/inkorg_contact_link.php';

/*
 * Kontakter (som Help Scout Customers). data/.private/inkorg/contacts.json.
 * Ärenden kopplas på e-post eller mobilnummer (normaliserat +46). Skapas automatiskt från inkommande mejl.
 */

function jeab_inkorg_phone_norm(string $raw): string
{
    return jeab_norm_phone($raw);
}

/** Mobilnummer i texten, bara när det är tydligt (07x / +46 7x). */
function jeab_inkorg_find_mobile(string $text): string
{
    if (preg_match('/(?<![\d+])(?:\+46|0046|0)\s?7[02369](?:[\s\-]?\d){7}(?!\d)/', $text, $m)) {
        return jeab_inkorg_phone_norm($m[0]);
    }
    return '';
}

/** Adress och ort ur signaturen när den är tydlig: «Gatan 12» följt av «123 45 Ort». */
function jeab_inkorg_find_address(string $text): array
{
    if (preg_match('/([A-ZÅÄÖ][\p{L}\.\- ]{2,40}?\s\d{1,4}[A-Za-z]?)\s*[,\n]\s*(\d{3}\s?\d{2})\s+([A-ZÅÄÖ][\p{L}\- ]{1,30})/u', $text, $m)) {
        $street = trim($m[1]);
        if (function_exists('jeab_address_strip_leading_filler')) {
            $street = jeab_address_strip_leading_filler($street);
        }
        if ($street === '') {
            return ['address' => '', 'town' => ''];
        }
        return ['address' => $street . ', ' . preg_replace('/\s/', '', $m[2]), 'town' => trim($m[3])];
    }
    return ['address' => '', 'town' => ''];
}

function jeab_inkorg_contacts(bool $includeDeleted = false): array
{
    $dataDir = function_exists('jeab_data_dir') ? rtrim(jeab_data_dir(), '/') : '';
    $marker = $dataDir !== '' && is_file($dataDir . '/inkorg/contacts.json.migrated');
    if ($marker) {
        if (!function_exists('jeab_inkorg_contacts_projected')) {
            $migrate = __DIR__ . '/kontakter_migrate.php';
            if (is_file($migrate)) {
                require_once $migrate;
            }
        }
        if (function_exists('jeab_inkorg_contacts_projected')) {
            return jeab_inkorg_contacts_projected();
        }

        return [];
    }
    if (function_exists('jeab_kontakt_inbox_read')) {
        $all = jeab_kontakt_inbox_read();
    } else {
        $path = jeab_inkorg_dir() . '/contacts.json';
        $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $all = is_array($d) ? (array) ($d['contacts'] ?? []) : [];
    }
    if ($includeDeleted) {
        return $all;
    }
    return array_filter($all, static fn($c): bool => !is_array($c) || empty($c['deleted_at']));
}

function jeab_inkorg_contact(string $id): ?array
{
    return jeab_inkorg_contacts()[$id] ?? null;
}

function jeab_inkorg_contact_find(array $contacts, string $email, string $phone): ?string
{
    $email = jeab_norm_email($email);
    foreach ($contacts as $id => $c) {
        if (!is_array($c) || !empty($c['deleted_at'])) {
            continue;
        }
        if ($email !== '' && in_array($email, array_map('jeab_norm_email', (array) ($c['emails'] ?? [])), true)) {
            return (string) $id;
        }
    }
    if ($phone !== '') {
        foreach ($contacts as $id => $c) {
            if (!is_array($c) || !empty($c['deleted_at'])) {
                continue;
            }
            if (($c['mobile'] ?? '') === $phone) {
                return (string) $id;
            }
        }
    }
    return null;
}

/** Plattform, formulär, no-reply och våra egna kontaktadresser är inte en kund. */
function jeab_inkorg_address_blocked(string $email): bool
{
    $email = jeab_norm_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return true;
    }
    if (function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($email)) {
        return true;
    }
    if (function_exists('jeab_lead_platform') && jeab_lead_platform($email) !== '') {
        return true;
    }
    if (function_exists('jeab_lead_seller_match') && jeab_lead_seller_match($email) !== '') {
        return true;
    }
    if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email)) {
        return true;
    }
    $local = (string) strstr($email, '@', true);
    return preg_match('/^(no-?reply|donotreply|do-not-reply|mailer-daemon|notifications?)([.+_-]|$)/', $local) === 1;
}

/**
 * Första e-post i brödtexten som inte är plattform, formulär eller oss själva.
 */
function jeab_inkorg_body_customer_email(array $msg): string
{
    $body = function_exists('jeab_lead_customer_plain')
        ? jeab_lead_customer_plain((string) ($msg['body'] ?? ''))
        : (string) ($msg['body'] ?? '');
    if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $body, $all)) {
        return '';
    }
    foreach ($all[0] as $cand) {
        $cand = jeab_norm_email((string) $cand);
        if (!jeab_inkorg_address_blocked($cand) && !(function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($cand)) && preg_match('/@(?:jarfalla-demo|malmo-demo)\.se$/', $cand) !== 1) {
            return $cand;
        }
    }
    return '';
}

/** Reply-To, första adress som är en kund. */
function jeab_inkorg_reply_to_email(array $msg): string
{
    $raw = trim((string) ($msg['reply_to'] ?? ''));
    if ($raw === '' && function_exists('jeab_inkorg_header')) {
        $raw = trim((string) (jeab_inkorg_header((array) ($msg['headers'] ?? []), 'Reply-To') ?? ''));
    }
    if ($raw === '') {
        return '';
    }
    foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
        $addr = function_exists('jeab_inkorg_addr') ? (string) (jeab_inkorg_addr($part)[0] ?? '') : jeab_norm_email($part);
        if ($addr !== '' && !jeab_inkorg_address_blocked($addr)) {
            return $addr;
        }
    }
    return '';
}

/**
 * Kundens e-post för ett mejl.
 * Plattform, leadsäljare och no-reply: brödtext, sedan Reply-To. Aldrig partnerns From.
 * Alla andra avsändare: kunden är From. En adress i brödtexten eller Reply-To byter inte kunden.
 * Personal och egna domäner är aldrig kunden.
 *
 * @return array{email: string, name: string, phone: string, address: string, platform: string, source: string}
 */
function jeab_inkorg_resolve_customer(array $msg): array
{
    $cust = function_exists('jeab_lead_customer')
        ? jeab_lead_customer($msg)
        : ['name' => '', 'email' => '', 'phone' => '', 'address' => '', 'platform' => '', 'email_from' => ''];
    $from = jeab_norm_email((string) ($msg['from'] ?? ''));
    $platformAddr = function_exists('jeab_msg_platform_address') ? jeab_msg_platform_address($msg) : $from;
    $platform = (string) ($cust['platform'] ?? '');
    if ($platform === '' && function_exists('jeab_lead_platform')) {
        $platform = jeab_lead_platform($platformAddr);
    }
    if ($platform === '' && (string) ($msg['envelope_from'] ?? '') !== '' && function_exists('jeab_form_envelope_is') && jeab_form_envelope_is($platformAddr, (string) ($msg['envelope_from_name'] ?? ''))) {
        $platform = 'form';
    }
    $email = '';
    $source = 'none';
    $name = '';
    $partnerFrom = function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($platformAddr);
    if ($platform !== '' || $partnerFrom) {
        $parsed = jeab_norm_email((string) ($cust['email'] ?? ''));
        $emailFrom = (string) ($cust['email_from'] ?? '');
        if ($parsed !== '' && !jeab_inkorg_address_blocked($parsed) && in_array($emailFrom, ['field', 'body'], true)) {
            $email = $parsed;
            $source = 'body';
        }
        if ($source === 'none') {
            $fromBody = jeab_inkorg_body_customer_email($msg);
            if ($fromBody !== '') {
                $email = $fromBody;
                $source = 'body';
            }
        }
        if ($source === 'none') {
            $reply = jeab_inkorg_reply_to_email($msg);
            if ($reply !== '') {
                $email = $reply;
                $source = 'reply-to';
            }
        }
        $name = trim((string) ($cust['name'] ?? ''));
    } elseif ($from !== '' && !jeab_inkorg_address_blocked($from)) {
        $email = $from;
        $source = 'from';
        $name = trim((string) ($cust['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($msg['from_name'] ?? ''));
        }
    }
    if ($name !== '' && function_exists('jeab_auto_unresolved_name') && jeab_auto_unresolved_name($name)) {
        $name = '';
    }
    return [
        'email' => $email,
        'name' => $name,
        'phone' => trim((string) ($cust['phone'] ?? '')),
        'address' => trim((string) ($cust['address'] ?? '')),
        'platform' => $platform,
        'source' => $source,
    ];
}

/**
 * Kund för hela ärendet. Inkommande mejl i ordning; brödtext slår Reply-To slår From.
 *
 * @return array{email: string, name: string, phone: string, address: string, platform: string, source: string}
 */
function jeab_inkorg_resolve_thread_customer(array $thread): array
{
    $opening = function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() === 'thread';
    if ($opening && function_exists('jeab_inkorg_thread_test_mark') && jeab_inkorg_thread_test_mark($thread)) {
        $msg = is_array($thread['first'] ?? null) ? $thread['first'] : [];
        $from = jeab_norm_email((string) ($msg['from'] ?? $thread['customer_email'] ?? ''));
        if ($from !== '' && !jeab_inkorg_address_blocked($from)) {
            return [
                'email' => $from,
                'name' => trim((string) ($msg['from_name'] ?? $thread['customer_name'] ?? '')),
                'phone' => '',
                'address' => '',
                'platform' => '',
                'source' => 'from',
            ];
        }
    }
    if ($opening) {
        $stored = jeab_norm_email((string) ($thread['customer_email'] ?? ''));
        $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
        if ($stored === '') {
            $stored = jeab_norm_email((string) ($lead['email'] ?? ''));
        }
        if ($stored !== '' && !jeab_inkorg_address_blocked($stored)) {
            $name = trim((string) ($thread['customer_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($lead['name'] ?? ''));
            }

            return [
                'email' => $stored,
                'name' => $name,
                'phone' => '',
                'address' => '',
                'platform' => '',
                'source' => 'stored',
            ];
        }
    }
    $best = ['email' => '', 'name' => '', 'phone' => '', 'address' => '', 'platform' => '', 'source' => 'none'];
    $rank = ['none' => 0, 'from' => 1, 'reply-to' => 2, 'body' => 3];
    $seen = 0;
    $msgs = (array) ($thread['messages'] ?? []);
    if ($msgs === [] && is_array($thread['first'] ?? null)) {
        $msgs = [$thread['first']];
    }
    if ($msgs === [] && isset($thread['from'])) {
        $msgs = [$thread];
    }
    foreach ($msgs as $m) {
        if (!is_array($m)) {
            continue;
        }
        $src = (string) ($m['source'] ?? '');
        $from = (string) ($m['from'] ?? '');
        if (str_starts_with($src, 'outbound-') || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from))) {
            continue;
        }
        if ($opening) {
            $from = jeab_norm_email((string) ($m['from'] ?? ''));
            $plain = $from !== '' && !jeab_inkorg_address_blocked($from)
                && !(function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($from))
                && !(function_exists('jeab_lead_platform') && jeab_lead_platform($from) !== '');
            if ($plain) {
                $r = [
                    'email' => $from,
                    'name' => trim((string) ($m['from_name'] ?? '')),
                    'phone' => '',
                    'address' => '',
                    'platform' => '',
                    'source' => 'from',
                ];
            } else {
                $r = jeab_inkorg_resolve_customer($m);
            }
        } else {
            $r = jeab_inkorg_resolve_customer($m);
        }
        $next = $rank[$r['source']] ?? 0;
        $cur = $rank[$best['source']] ?? 0;
        if ($next > $cur) {
            $best = $r;
        }
        if ($next >= 3) {
            break;
        }
        if ($opening && ++$seen >= 8 && ($rank[$best['source']] ?? 0) >= 1) {
            break;
        }
    }
    return $best;
}

/**
 * Personen ärendet handlar om. Alltid kundens e-post, aldrig plattformens From.
 *
 * @return array{email: string, name: string, phone: string, address: string, form: bool, source: string}
 */
function jeab_inkorg_party(array $t): array
{
    $r = jeab_inkorg_resolve_thread_customer($t);
    $stored = jeab_norm_email((string) ($t['customer_email'] ?? ''));
    if ($stored === '') {
        $lead = (array) ($t['lead_customer'] ?? []);
        $stored = jeab_norm_email((string) ($lead['email'] ?? ''));
    }
    $storedOk = $stored !== '' && !jeab_inkorg_address_blocked($stored);
    $resolvedBlocked = $r['email'] === '' || jeab_inkorg_address_blocked($r['email']);
    $hook = (string) ($t['customer_source'] ?? '') === 'hook';
    if ($storedOk && ($resolvedBlocked || $hook)) {
        $r['email'] = $stored;
        $r['source'] = 'stored';
        $name = trim((string) ($t['customer_name'] ?? ''));
        if ($name !== '') {
            $r['name'] = $name;
        }
    }
    $contactId = trim((string) ($t['contact_id'] ?? ''));
    if (!$storedOk && $contactId !== '' && function_exists('jeab_kontakt_get') && function_exists('jeab_kontakt_row_email')) {
        $contact = jeab_kontakt_get($contactId);
        if (is_array($contact)) {
            $fromContact = jeab_norm_email(jeab_kontakt_row_email($contact));
            if ($fromContact !== '' && !jeab_inkorg_address_blocked($fromContact)) {
                $r['email'] = $fromContact;
                $r['source'] = 'contact';
                $contactName = trim((string) ($contact['name'] ?? ''));
                if ($contactName !== '') {
                    $r['name'] = $contactName;
                }
            }
        }
    }
    return [
        'email' => $r['email'],
        'name' => $r['name'],
        'phone' => $r['phone'],
        'address' => $r['address'],
        'form' => $r['platform'] !== '',
        'source' => $r['source'],
    ];
}

function jeab_inkorg_email_is_test(string $email): bool
{
    $email = jeab_norm_email($email);
    if ($email === '') {
        return false;
    }

    return function_exists('jeab_auto_test_email') && jeab_auto_test_email($email);
}

/** @param array<string, mixed> $c */
function jeab_inkorg_contact_emails_of(array $c): array
{
    $out = [];
    foreach ((array) ($c['emails'] ?? []) as $em) {
        $norm = jeab_norm_email((string) $em);
        if ($norm !== '' && !in_array($norm, $out, true)) {
            $out[] = $norm;
        }
    }
    $one = jeab_norm_email((string) ($c['email'] ?? ''));
    if ($one !== '' && !in_array($one, $out, true)) {
        $out[] = $one;
    }
    if (function_exists('jeab_kontakt_row_emails')) {
        foreach (jeab_kontakt_row_emails($c) as $em) {
            $norm = jeab_norm_email((string) $em);
            if ($norm !== '' && !in_array($norm, $out, true)) {
                $out[] = $norm;
            }
        }
    }

    return $out;
}

/** Test Testsson, markerad test eller en känd testadress. */
function jeab_inkorg_contact_is_test(array $c): bool
{
    if (!empty($c['test']) || !empty($c['is_test'])) {
        return true;
    }
    $names = [trim((string) ($c['name'] ?? ''))];
    foreach ((array) ($c['owners'] ?? []) as $owner) {
        if (is_array($owner)) {
            $names[] = trim((string) ($owner['name'] ?? ''));
        }
    }
    foreach ($names as $name) {
        if ($name === '') {
            continue;
        }
        if (preg_match('/^test\s+testsson$/iu', $name) === 1) {
            return true;
        }
        if (function_exists('jeab_auto_test_name') && jeab_auto_test_name($name)) {
            return true;
        }
    }
    foreach (jeab_inkorg_contact_emails_of($c) as $em) {
        if (jeab_inkorg_email_is_test($em)) {
            return true;
        }
    }
    $source = mb_strtolower(trim((string) ($c['source'] ?? '')));

    return $source === 'test' || str_contains($source, 'testkontakt');
}

/** Avsändarens e-post. Plattform och egna adresser lämnas, då gäller kunden i mejlet. */
function jeab_inkorg_thread_sender_email(array $t): string
{
    $msgs = [];
    foreach ((array) ($t['messages'] ?? []) as $m) {
        if (is_array($m)) {
            $msgs[] = $m;
        }
    }
    usort($msgs, static fn(array $a, array $b): int => strcmp((string) ($b['received_at'] ?? ''), (string) ($a['received_at'] ?? '')));
    foreach ($msgs as $m) {
        $src = (string) ($m['source'] ?? '');
        $from = jeab_norm_email((string) ($m['from'] ?? ''));
        if ($from === '' || str_starts_with($src, 'outbound')) {
            continue;
        }
        if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
            continue;
        }
        if (jeab_inkorg_address_blocked($from)) {
            continue;
        }

        return $from;
    }
    if (function_exists('jeab_inkorg_party')) {
        return jeab_norm_email((string) (jeab_inkorg_party($t)['email'] ?? ''));
    }

    return jeab_norm_email((string) ($t['customer_email'] ?? ''));
}

function jeab_inkorg_thread_sender_is_test(array $t): bool
{
    if (function_exists('jeab_inkorg_thread_is_test') && jeab_inkorg_thread_is_test($t)) {
        return true;
    }
    $email = jeab_inkorg_thread_sender_email($t);
    if ($email !== '' && jeab_inkorg_email_is_test($email)) {
        return true;
    }
    foreach ((array) ($t['messages'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $name = trim((string) ($m['from_name'] ?? ''));
        if ($name !== '' && function_exists('jeab_auto_test_name') && jeab_auto_test_name($name)) {
            return true;
        }
    }

    return false;
}

function jeab_inkorg_contact_record(string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    $row = jeab_inkorg_contact($id);
    if (is_array($row)) {
        $row['id'] = (string) ($row['id'] ?? $id);

        return jeab_inkorg_contact_linked_view($row);
    }
    if (!function_exists('jeab_kontakt_get')) {
        return null;
    }
    try {
        $crm = jeab_kontakt_get($id);
    } catch (Throwable) {
        $crm = null;
    }
    if (!is_array($crm)) {
        return null;
    }
    if (function_exists('jeab_inkorg_contact_project')) {
        return jeab_inkorg_contact_project($id, $crm);
    }
    $crm['id'] = $id;

    return $crm;
}

/** Kopplad kontakt har annan e-post än avsändaren, eller är en testkontakt mot en riktig kund. */
function jeab_inkorg_contact_mismatch(array $thread, array $contact): bool
{
    $sender = jeab_inkorg_thread_sender_email($thread);
    $emails = jeab_inkorg_contact_emails_of($contact);
    $emailHit = $sender !== '' && in_array($sender, $emails, true);
    $senderTest = jeab_inkorg_thread_sender_is_test($thread);
    if (!$senderTest && !$emailHit && jeab_inkorg_contact_is_test($contact)) {
        return true;
    }
    if ($sender === '') {
        return false;
    }
    if ($emails === []) {
        return true;
    }

    return !$emailHit;
}

/**
 * @param list<array<string, mixed>> $hits
 */
function jeab_inkorg_contact_pick(array $hits, bool $senderTest, string $email = ''): ?array
{
    $email = jeab_norm_email($email);
    $ok = [];
    foreach ($hits as $hit) {
        if (!is_array($hit) || !empty($hit['deleted_at'])) {
            continue;
        }
        $exact = $email !== '' && in_array($email, jeab_inkorg_contact_emails_of($hit), true);
        if (!$senderTest && !$exact && jeab_inkorg_contact_is_test($hit)) {
            continue;
        }
        $ok[] = $hit;
    }
    if ($ok === []) {
        return null;
    }

    return $ok[0];
}

/** Kontakt för ett ärende. Exakt avsändar-e-post först. Telefon är bara en svag signal när e-post saknas. */
function jeab_inkorg_contact_for_thread(array $t): ?array
{
    $party = jeab_inkorg_party($t);
    $sender = jeab_inkorg_thread_sender_email($t);
    $email = $sender !== '' ? $sender : jeab_norm_email((string) ($party['email'] ?? ''));
    $senderTest = jeab_inkorg_thread_sender_is_test($t);
    $hits = [];
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (function_exists('jeab_kontakter_use_sql') && jeab_kontakter_use_sql() && function_exists('jeab_contact_store_pdo')) {
            $pdo = jeab_contact_store_pdo();
            if ($pdo instanceof PDO) {
                $box = new JeabPdoInboxContactStore($pdo);
                foreach ($box->findByEmail($email) as $hit) {
                    if (is_array($hit)) {
                        $hits[] = $hit;
                    }
                }
            }
        }
        $scanBook = !($senderTest && function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() === 'thread');
        if ($hits === [] && $scanBook) {
            foreach (jeab_inkorg_contacts() as $id => $c) {
                if (!is_array($c) || !empty($c['deleted_at'])) {
                    continue;
                }
                if (in_array($email, jeab_inkorg_contact_emails_of($c), true)) {
                    $hits[] = ['id' => (string) $id] + $c;
                }
            }
        }
        $picked = jeab_inkorg_contact_pick($hits, $senderTest, $email);
        if (is_array($picked) && in_array($email, jeab_inkorg_contact_emails_of($picked), true)) {
            return jeab_inkorg_contact_linked_view($picked);
        }

        return null;
    }
    if ($party['form'] && !filter_var((string) ($party['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $phone = '';
    if (!empty($party['form'])) {
        $norm = jeab_inkorg_phone_norm((string) ($party['phone'] ?? ''));
        $phone = preg_match('/^\+467\d{8}$/', $norm) === 1 ? $norm : '';
    } else {
        foreach ((array) ($t['messages'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $phone = $phone !== '' ? $phone : jeab_inkorg_find_mobile((string) ($m['body'] ?? ''));
        }
    }
    if ($phone === '') {
        return null;
    }
    $weak = [];
    if (function_exists('jeab_kontakter_use_sql') && jeab_kontakter_use_sql() && function_exists('jeab_contact_store_pdo')) {
        $pdo = jeab_contact_store_pdo();
        if ($pdo instanceof PDO) {
            $box = new JeabPdoInboxContactStore($pdo);
            foreach ($box->findByMobile($phone) as $hit) {
                if (is_array($hit)) {
                    $weak[] = $hit;
                }
            }
        }
    }
    if ($weak === []) {
        $id = jeab_inkorg_contact_find(jeab_inkorg_contacts(), '', $phone);
        $all = jeab_inkorg_contacts();
        if ($id !== null && isset($all[$id]) && is_array($all[$id])) {
            $weak[] = ['id' => $id] + $all[$id];
        }
    }

    $picked = jeab_inkorg_contact_pick($weak, $senderTest, '');

    return is_array($picked) ? jeab_inkorg_contact_linked_view($picked) : null;
}

/** Kontakt som kundrutan får visa. En felkopplad eller testkontakt mot en riktig kund visas inte. */
function jeab_inkorg_panel_contact(array $thread): ?array
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_inbox_panel')) {
        return jeab_contacts_sole_inbox_panel($thread);
    }
    $id = trim((string) ($thread['contact_id'] ?? ''));
    if ($id !== '') {
        $stored = jeab_inkorg_contact_record($id);
        if (is_array($stored) && !jeab_inkorg_contact_mismatch($thread, $stored)) {
            return $stored;
        }
    }
    $found = jeab_inkorg_contact_for_thread($thread);
    if (!is_array($found) || jeab_inkorg_contact_mismatch($thread, $found)) {
        return null;
    }

    return $found;
}

function jeab_inkorg_contact_mismatch_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }

    return !($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false);
}

/**
 * inkorg.contact_mismatch_scan. dry=1 är standard och listar bara tråd-id.
 * dry=0 kräver confirm=SAM och kopplar bort fel kontakt. Inget skickas.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_inkorg_contact_mismatch_scan(array $input): array
{
    $dry = jeab_inkorg_contact_mismatch_dry($input);
    $base = ['would_send' => false, 'dry' => $dry ? 1 : 0, 'threads' => []];
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm'] + $base;
    }
    $ids = [];
    foreach (jeab_inkorg_threads() as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $tid = trim((string) ($thread['id'] ?? ''));
        $linked = trim((string) ($thread['contact_id'] ?? ''));
        if ($tid === '' || $linked === '') {
            continue;
        }
        $row = jeab_inkorg_contact_record($linked);
        if (!is_array($row) || !jeab_inkorg_contact_mismatch($thread, $row)) {
            continue;
        }
        $ids[] = $tid;
    }
    $ids = array_values(array_unique($ids));
    if (!$dry) {
        foreach ($ids as $tid) {
            jeab_inkorg_thread_write($tid, static function (array $row): array {
                $row['contact_id'] = '';

                return $row;
            });
        }
    }

    return ['ok' => true, 'count' => count($ids), 'threads' => $ids] + $base;
}

/**
 * Påminnelsen som ärendet pekar på, via thread_id eller mail-länken i anteckningen.
 */
function jeab_inkorg_thread_reminder(array $thread): ?array
{
    $tid = trim((string) ($thread['id'] ?? ''));
    if ($tid === '') {
        return null;
    }
    if (!isset($GLOBALS['_JEAB_INKORG_THREAD_REM']) || !is_array($GLOBALS['_JEAB_INKORG_THREAD_REM'])) {
        $GLOBALS['_JEAB_INKORG_THREAD_REM'] = [];
    }
    if (array_key_exists($tid, $GLOBALS['_JEAB_INKORG_THREAD_REM'])) {
        $cached = $GLOBALS['_JEAB_INKORG_THREAD_REM'][$tid];

        return is_array($cached) ? $cached : null;
    }
    $hit = function_exists('jeab_reminder_for_thread') ? jeab_reminder_for_thread($tid) : null;
    $skipNote = function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() === 'thread'
        && function_exists('jeab_inkorg_thread_test_mark') && jeab_inkorg_thread_test_mark($thread);
    if (!is_array($hit) && !$skipNote) {
        $hit = jeab_inkorg_thread_reminder_by_note($tid);
    }
    $GLOBALS['_JEAB_INKORG_THREAD_REM'][$tid] = is_array($hit) ? $hit : null;

    return is_array($hit) ? $hit : null;
}

/**
 * LIKE mot note. Utropstecken är escape i både MySQL och SQLite.
 * Ett bakstreck i ESCAPE '\' stänger citatet i MySQL (native prepare) och kastar PDOException.
 *
 * @return array{0:string,1:string}
 */
function jeab_inkorg_reminder_note_like(string $needle): array
{
    $safe = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);

    return ["note LIKE ? ESCAPE '!'", '%' . $safe . '%'];
}

/**
 * Påminnelse som bara nämner ärendet i anteckningen. SQL söker note, inte hela tabellen.
 */
function jeab_inkorg_thread_reminder_by_note(string $tid): ?array
{
    $tid = trim($tid);
    if ($tid === '' || !function_exists('jeab_store') || !function_exists('jeab_inkorg_reminder_links_thread')) {
        return null;
    }
    $store = jeab_store();
    if (!$store instanceof JeabStore) {
        return null;
    }
    $best = null;
    $take = static function (array $row) use ($tid, &$best): void {
        if (!jeab_inkorg_reminder_links_thread($row, $tid)) {
            return;
        }
        if ($best === null || (int) ($row['id'] ?? 0) > (int) ($best['id'] ?? 0)) {
            $best = $row;
        }
    };
    if ($store instanceof JeabPdoStore) {
        $needles = ['id=' . $tid];
        $enc = rawurlencode($tid);
        if ($enc !== $tid) {
            $needles[] = 'id=' . $enc;
        }
        $likes = [];
        $args = [];
        foreach ($needles as $needle) {
            [$like, $arg] = jeab_inkorg_reminder_note_like($needle);
            $likes[] = $like;
            $args[] = $arg;
        }
        $stmt = $store->pdo()->prepare(
            'SELECT * FROM reminders WHERE id > 0 AND (' . implode(' OR ', $likes) . ') ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $take(function_exists('jeab_hydrate_reminder') ? jeab_hydrate_reminder($row) : $row);
        }

        return is_array($best) ? $best : null;
    }
    foreach ($store->listReminders(true) as $row) {
        if (is_array($row)) {
            $take($row);
        }
    }

    return is_array($best) ? $best : null;
}

/**
 * Kontaktens uppgifter först. Tomma fält fylls från påminnelsens egen kopia.
 *
 * @return array{email:string,phone:string,street:string,town:string}
 */
function jeab_inkorg_reminder_card_fields(array $rem): array
{
    $shown = function_exists('jeab_reminder_shown_customer') ? jeab_reminder_shown_customer($rem) : [];
    $email = trim((string) ($shown['email'] ?? ''));
    if ($email === '' && function_exists('jeab_reminder_email')) {
        $email = jeab_reminder_email($rem);
    }
    $phone = trim((string) ($shown['phone'] ?? ''));
    if ($phone === '' && function_exists('jeab_reminder_phone')) {
        $phone = jeab_reminder_phone($rem);
    }
    $street = trim((string) ($shown['street'] ?? ''));
    $town = trim((string) ($shown['city'] ?? ''));
    if ($street === '') {
        $raw = trim((string) ($rem['address'] ?? ''));
        if ($raw !== '' && function_exists('jeab_address_parts')) {
            $parts = jeab_address_parts($raw);
            $street = trim((string) ($parts['street'] ?? ''));
            if ($street === '') {
                $street = $raw;
            }
            if ($town === '' && trim((string) ($parts['city'] ?? '')) !== '') {
                $town = trim((string) $parts['city']);
            }
        } elseif ($raw !== '') {
            $street = $raw;
        }
    }
    if ($town === '') {
        $town = trim((string) ($rem['ort'] ?? ''));
    }

    return ['email' => $email, 'phone' => $phone, 'street' => $street, 'town' => $town];
}

/**
 * Mejl till kundkortet när trådens kontakt saknar det: länkad kontakt, påminnelsen, sedan Till.
 */
function jeab_inkorg_contact_box_email(array $thread): string
{
    $take = static function (string $email): string {
        $norm = function_exists('jeab_norm_email') ? jeab_norm_email($email) : strtolower(trim($email));
        if ($norm === '' || (function_exists('jeab_inkorg_address_blocked') && jeab_inkorg_address_blocked($norm))) {
            return '';
        }

        return $norm;
    };
    $rem = jeab_inkorg_thread_reminder($thread);
    if (is_array($rem)) {
        $norm = $take(jeab_inkorg_reminder_card_fields($rem)['email']);
        if ($norm !== '') {
            return $norm;
        }
    }
    if (function_exists('jeab_inkorg_reply_recipient')) {
        return $take(jeab_inkorg_reply_recipient($thread));
    }

    return '';
}

/**
 * Kontaktboxen på ärendet. Länkad kontakt först, sedan påminnelsen.
 *
 * @param array<string, mixed>|null $contact
 * @param array<string, mixed> $cust
 * @return array{emails: list<string>, phone: string}
 */
function jeab_inkorg_contact_box(array $thread, ?array $contact, array $cust): array
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_inbox_view')) {
        $view = jeab_contacts_sole_inbox_view($thread);
        $internal = function_exists('jeab_contacts_inbox_thread_signals')
            && !empty(jeab_contacts_inbox_thread_signals($thread)['internal_sender']);
        if ($internal && $view['id'] === '') {
            return ['emails' => [], 'phone' => ''];
        }
        $emails = $view['email'] !== '' ? [$view['email']] : [];
        $phone = $view['phone'];
        if ($emails === [] || $phone === '') {
            $rem = jeab_inkorg_thread_reminder($thread);
            $fields = is_array($rem) ? jeab_inkorg_reminder_card_fields($rem) : ['email' => '', 'phone' => '', 'street' => '', 'town' => ''];
            if ($emails === []) {
                $fallback = jeab_inkorg_contact_box_email($thread);
                if ($fallback !== '') {
                    $emails[] = $fallback;
                }
            }
            if ($phone === '' && $fields['phone'] !== '') {
                $phone = $fields['phone'];
            }
        }

        return [
            'emails' => $emails,
            'phone' => $phone,
        ];
    }
    $takeEmail = static function (string $raw): string {
        $norm = jeab_norm_email($raw);
        if ($norm === '' || jeab_inkorg_address_blocked($norm)) {
            return '';
        }

        return $norm;
    };
    $stored = function_exists('jeab_contacts_inbox_contact_email')
        ? $takeEmail(jeab_contacts_inbox_contact_email($thread))
        : '';
    if ($stored === '') {
        $stored = $takeEmail((string) ($thread['customer_email'] ?? ''));
    }
    if ($stored === '') {
        $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
        $stored = $takeEmail((string) ($lead['email'] ?? ''));
    }
    $storedOk = $stored !== '' && !jeab_inkorg_address_blocked($stored);
    $emails = [];
    if (is_array($contact)) {
        foreach ((array) ($contact['emails'] ?? []) as $em) {
            $norm = jeab_norm_email((string) $em);
            if ($norm === '') {
                continue;
            }
            if ($storedOk && jeab_inkorg_address_blocked($norm)) {
                continue;
            }
            if (!in_array($norm, $emails, true)) {
                $emails[] = $norm;
            }
        }
    }
    if ($storedOk && !in_array($stored, $emails, true)) {
        array_unshift($emails, $stored);
    }
    if ($emails === [] && !$storedOk) {
        $fallback = jeab_norm_email((string) ($cust['email'] ?? ''));
        if ($fallback !== '') {
            $emails[] = $fallback;
        }
    }
    if ($emails === []) {
        $fallback = jeab_inkorg_contact_box_email($thread);
        if ($fallback !== '' && !in_array($fallback, $emails, true)) {
            $emails[] = $fallback;
        }
    }
    $phone = is_array($contact) ? trim((string) ($contact['mobile'] ?? '')) : '';
    if ($phone === '') {
        $phone = trim((string) ($cust['phone'] ?? ''));
    }
    if ($phone === '') {
        $rem = jeab_inkorg_thread_reminder($thread);
        if (is_array($rem)) {
            $phone = jeab_inkorg_reminder_card_fields($rem)['phone'];
        }
    }
    if ($phone === '') {
        foreach ((array) ($thread['messages'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $hit = jeab_inkorg_find_mobile((string) ($m['body'] ?? ''));
            if ($hit !== '') {
                $phone = $hit;
                break;
            }
        }
    }
    return ['emails' => $emails, 'phone' => $phone];
}

/**
 * Adressraden i kundpanelen. Länkad kontakt först, sedan påminnelsen, sedan formuläret.
 *
 * @param array<string, mixed>|null $contact
 * @return array{address: string, town: string}
 */
function jeab_inkorg_panel_place(array $thread, ?array $contact): array
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_inbox_view')) {
        $view = jeab_contacts_sole_inbox_view($thread);
        $internal = function_exists('jeab_contacts_inbox_thread_signals')
            && !empty(jeab_contacts_inbox_thread_signals($thread)['internal_sender']);
        if ($internal && $view['id'] === '') {
            return ['address' => '', 'town' => ''];
        }
        $street = $view['street'];
        $town = $view['city'];
        if ($street === '' || $town === '') {
            $rem = jeab_inkorg_thread_reminder($thread);
            if (is_array($rem)) {
                $fields = jeab_inkorg_reminder_card_fields($rem);
                if ($street === '') {
                    $street = $fields['street'];
                }
                if ($town === '') {
                    $town = $fields['town'];
                }
            }
        }
        $addr = trim($street . ($street !== '' && $town !== '' ? ', ' : '') . $town);

        return ['address' => $addr, 'town' => $town];
    }
    $town = is_array($contact) ? trim((string) ($contact['town'] ?? '')) : '';
    $street = is_array($contact) ? trim((string) ($contact['address'] ?? '')) : '';
    $addr = trim($street . ($street !== '' && $town !== '' ? ', ' : '') . $town);
    if ($addr === '') {
        $rem = jeab_inkorg_thread_reminder($thread);
        if (is_array($rem)) {
            $fields = jeab_inkorg_reminder_card_fields($rem);
            $street = $fields['street'];
            if ($town === '') {
                $town = $fields['town'];
            }
            $addr = trim($street . ($street !== '' && $town !== '' ? ', ' : '') . $town);
        }
    }
    if ($addr === '' && function_exists('jeab_inkorg_party')) {
        $addr = trim((string) (jeab_inkorg_party($thread)['address'] ?? ''));
        if ($town === '' && $addr !== '' && function_exists('jeab_inkorg_reminder_place_fields')) {
            $town = trim((string) (jeab_inkorg_reminder_place_fields($addr)['ort'] ?? ''));
        }
    }
    return ['address' => $addr, 'town' => $town];
}

/** Skapa eller komplettera kontakt från ett inkommande mejl. Fyller bara tomma fält; redigerat skrivs aldrig över. */
function jeab_inkorg_contact_from_message(array $msg): ?string
{
    return jeab_inkorg_contact_ensure(jeab_inkorg_resolve_customer($msg), $msg);
}

/**
 * Kontakt på kundens e-post. Plattformsadress skapar ingen kontakt.
 *
 * @param array{email?: string, name?: string, phone?: string, address?: string, platform?: string, source?: string} $resolved
 */
function jeab_inkorg_contact_ensure(array $resolved, array $msg): ?string
{
    $email = jeab_norm_email((string) ($resolved['email'] ?? ''));
    if (jeab_inkorg_address_blocked($email)) {
        return null;
    }
    $name = trim((string) ($resolved['name'] ?? ''));
    if ($name === '' && (string) ($resolved['source'] ?? '') === 'from') {
        $name = trim((string) ($msg['from_name'] ?? ''));
    }
    if ($name !== '' && function_exists('jeab_auto_unresolved_name') && jeab_auto_unresolved_name($name)) {
        $name = '';
    }
    $body = (string) ($msg['body'] ?? '');
    $form = (string) ($resolved['platform'] ?? '') !== '';
    if ($form || (string) ($resolved['source'] ?? '') === 'body') {
        $norm = jeab_inkorg_phone_norm((string) ($resolved['phone'] ?? ''));
        $phone = preg_match('/^\+467\d{8}$/', $norm) === 1 ? $norm : '';
        $addr = trim((string) ($resolved['address'] ?? '')) !== ''
            ? ['address' => (string) $resolved['address'], 'town' => '']
            : jeab_inkorg_find_address($body);
        if (!$form && $phone === '') {
            $phone = jeab_inkorg_find_mobile($body);
        }
    } else {
        $phone = jeab_inkorg_find_mobile($body);
        $addr = jeab_inkorg_find_address($body);
    }
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived() && function_exists('jeab_kontakt_inbox_upsert')) {
        return jeab_kontakt_inbox_upsert($email, $name, $phone, (string) ($addr['address'] ?? ''), (string) ($addr['town'] ?? ''), $msg);
    }
    $id = jeab_kontakt_inbox_locked(function (?array $d) use ($msg, $email, $phone, $addr, $name) {
        $d = $d ?? ['contacts' => []];
        $id = jeab_inkorg_contact_find((array) $d['contacts'], $email, $phone);
        if ($id !== null && isset($d['contacts'][$id]) && is_array($d['contacts'][$id])) {
            $row = $d['contacts'][$id];
            $have = in_array($email, array_map('jeab_norm_email', (array) ($row['emails'] ?? [])), true);
            if (!$have && jeab_inkorg_contact_is_test($row) && !jeab_inkorg_email_is_test($email)) {
                $id = null;
            }
        }
        $new = $id === null;
        $id = $id ?? ('c-' . bin2hex(random_bytes(5)));
        $c = (array) ($d['contacts'][$id] ?? ['name' => '', 'address' => '', 'town' => '', 'emails' => [], 'mobile' => '', 'notes' => '', 'created_at' => date('c')]);
        if ($new) {
            $c['test'] = !empty($msg['test']);
            $c['source'] = (string) ($msg['source'] ?? '');
        }
        $c['name'] = $c['name'] !== '' ? $c['name'] : $name;
        if (!in_array($email, array_map('jeab_norm_email', $c['emails']), true)) {
            $c['emails'][] = $email;
        }
        $c['mobile'] = $c['mobile'] !== '' ? $c['mobile'] : $phone;
        if ($c['address'] === '' && $c['town'] === '') {
            $c['address'] = $addr['address'];
            $c['town'] = $addr['town'];
        }
        $d['contacts'][$id] = $c;
        return [$id, $d];
    });
    if (is_string($id) && $id !== '' && function_exists('jeab_contacts_sole_mirror_inbox') && function_exists('jeab_inkorg_contact_record')) {
        $row = jeab_inkorg_contact_record($id);
        if (is_array($row)) {
            try {
                jeab_contacts_sole_mirror_inbox($id, $row, false);
            } catch (Throwable) {
            }
        }
    }

    return is_string($id) ? $id : null;
}

function jeab_inkorg_contact_save(array $in): array
{
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived() && function_exists('jeab_kontakt_inbox_save')) {
        return jeab_inkorg_contact_save_finish(jeab_kontakt_inbox_save($in), $in);
    }
    $id = (string) ($in['id'] ?? '');
    $emails = array_values(array_unique(array_filter(array_map(fn($e) => jeab_norm_email((string) $e), preg_split('/[\s,;]+/', (string) ($in['emails'] ?? '')) ?: []), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    $mobileRaw = trim((string) ($in['mobile'] ?? ''));
    $mobile = $mobileRaw === '' ? '' : jeab_inkorg_phone_norm($mobileRaw);
    if ($mobileRaw !== '' && $mobile === '') {
        throw new InvalidArgumentException('Mobilnumret ser inte svenskt ut (07x eller +46).');
    }
    if (trim((string) ($in['name'] ?? '')) === '' && !$emails) {
        throw new InvalidArgumentException('Ange namn eller e-post.');
    }
    $saved = jeab_kontakt_inbox_locked(function (?array $d) use ($id, $in, $emails, $mobile) {
        $d = $d ?? ['contacts' => []];
        if ($id !== '' && !isset($d['contacts'][$id])) {
            throw new InvalidArgumentException('Kontakten finns inte.');
        }
        $id = $id !== '' ? $id : 'c-' . bin2hex(random_bytes(5));
        $c = (array) ($d['contacts'][$id] ?? ['created_at' => date('c')]);
        foreach (['name' => 120, 'address' => 200, 'town' => 80, 'notes' => 4000] as $k => $len) {
            $c[$k] = mb_substr(trim((string) ($in[$k] ?? '')), 0, $len);
        }
        $c['emails'] = $emails;
        $c['mobile'] = $mobile;
        $c['updated_at'] = date('c');
        $d['contacts'][$id] = $c;
        return [['id' => $id] + $c, $d];
    });

    return jeab_inkorg_contact_save_finish(is_array($saved) ? $saved : [], $in);
}

function jeab_inkorg_contact_save_finish(array $saved, array $in): array
{
    if (function_exists('jeab_contacts_sole_mirror_inbox')) {
        $inboxId = trim((string) ($saved['id'] ?? ''));
        try {
            $mirrored = jeab_contacts_sole_mirror_inbox($inboxId, $saved, false);
        } catch (Throwable) {
            $mirrored = [];
        }
        $contactId = trim((string) ($mirrored['contact_id'] ?? ''));
        if ($contactId !== '' && trim((string) ($saved['contact_id'] ?? '')) === '') {
            $saved['contact_id'] = $contactId;
        }
    }
    if (function_exists('jeab_contacts_sole_sync_inbox_save')) {
        jeab_contacts_sole_sync_inbox_save($saved, $in);
    }

    return $saved;
}

/** Mjukradering: kontakten finns kvar, med vem och när, och kan återställas. */
function jeab_inkorg_contact_delete(string $id, string $by = ''): void
{
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        return;
    }
    $by = trim($by) !== '' ? trim($by) : (function_exists('jeab_inkorg_me') ? jeab_inkorg_me() : 'personal');
    jeab_kontakt_inbox_locked(function (?array $d) use ($id, $by) {
        $d = $d ?? ['contacts' => []];
        if ($id === '' || !isset($d['contacts'][$id]) || !is_array($d['contacts'][$id])) {
            throw new InvalidArgumentException('Kontakten finns inte.');
        }
        $c = $d['contacts'][$id];
        if (!empty($c['deleted_at'])) {
            return [null, null];
        }
        $at = date('c');
        $c['deleted_at'] = $at;
        $c['deleted_by'] = mb_substr($by, 0, 80);
        $log = array_values((array) ($c['log'] ?? []));
        $log[] = ['action' => 'delete', 'by' => $c['deleted_by'], 'at' => $at];
        $c['log'] = array_slice($log, -20);
        $d['contacts'][$id] = $c;
        return [null, $d];
    });
}

function jeab_inkorg_contact_restore(string $id, string $by = ''): void
{
    $by = trim($by) !== '' ? trim($by) : (function_exists('jeab_inkorg_me') ? jeab_inkorg_me() : 'personal');
    jeab_kontakt_inbox_locked(function (?array $d) use ($id, $by) {
        $d = $d ?? ['contacts' => []];
        if ($id === '' || !isset($d['contacts'][$id]) || !is_array($d['contacts'][$id])) {
            throw new InvalidArgumentException('Kontakten finns inte.');
        }
        $c = $d['contacts'][$id];
        if (empty($c['deleted_at'])) {
            return [null, null];
        }
        $at = date('c');
        unset($c['deleted_at'], $c['deleted_by']);
        $log = array_values((array) ($c['log'] ?? []));
        $log[] = ['action' => 'restore', 'by' => mb_substr($by, 0, 80), 'at' => $at];
        $c['log'] = array_slice($log, -20);
        $d['contacts'][$id] = $c;
        return [null, $d];
    });
}

/** Testkontakter rensas tillsammans med sin testkälla. */
function jeab_inkorg_contacts_delete_source(string $source): int
{
    if (function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        return 0;
    }
    return jeab_kontakt_inbox_locked(function (?array $d) use ($source) {
        $d = $d ?? ['contacts' => []];
        $n = count($d['contacts']);
        $d['contacts'] = array_filter($d['contacts'], fn($c) => !(!empty($c['test']) && (string) ($c['source'] ?? '') === $source));
        return [$n - count($d['contacts']), $d];
    });
}

/**
 * Räknar om kundens e-post på befintliga ärenden. dry=1 (standard) skriver inget.
 * Rapport: thread, old, new, source. Plattformsadress blir aldrig den nya kunden.
 * contact_id ligger på trådens metadata (threads.json eller SQL-raden) och följer med
 * i den sammansatta tråden. En tråd som redan har contact_id hoppas över: customer_email
 * och customer_name skrivs inte. De räknas i skipped_linked. Svaret listar inte deras värden.
 */
function jeab_inkorg_rekontakt(array $input): array
{
    $dry = true;
    if (array_key_exists('dry', $input)) {
        $v = $input['dry'];
        $dry = !($v === false || $v === 0 || $v === '0' || $v === 'false' || $v === 'no');
    }
    $changes = [];
    $unchanged = 0;
    $skippedLinked = 0;
    foreach (jeab_inkorg_threads() as $t) {
        $tid = (string) $t['id'];
        $meta = jeab_inkorg_thread_meta($tid);
        $linkedId = trim((string) ($t['contact_id'] ?? ''));
        if ($linkedId === '') {
            $linkedId = trim((string) ($meta['contact_id'] ?? ''));
        }
        if ($linkedId !== '') {
            $skippedLinked++;
            continue;
        }
        $new = jeab_inkorg_resolve_thread_customer($t);
        $had = array_key_exists('customer_email', $meta);
        $old = $had
            ? jeab_norm_email((string) $meta['customer_email'])
            : jeab_norm_email((string) ($t['first']['from'] ?? ''));
        $same = $old === $new['email'] && (!$had || (string) ($meta['customer_source'] ?? '') === $new['source']);
        if ($same && $had) {
            $unchanged++;
            continue;
        }
        if ($old !== $new['email']) {
            $changes[] = ['thread' => $tid, 'old' => $old, 'new' => $new['email'], 'source' => $new['source']];
        } else {
            $unchanged++;
        }
        if ($dry) {
            continue;
        }
        jeab_inkorg_thread_write($tid, function (array $row) use ($new) {
            $row['customer_email'] = $new['email'];
            $row['customer_source'] = $new['source'];
            $row['customer_name'] = $new['name'];
            return $row;
        });
        if ($new['email'] === '') {
            continue;
        }
        $msg = (array) ($t['first'] ?? []);
        foreach ((array) $t['messages'] as $m) {
            if (str_starts_with((string) ($m['source'] ?? ''), 'outbound-')) {
                continue;
            }
            $one = jeab_inkorg_resolve_customer($m);
            if ($one['email'] === $new['email']) {
                $msg = $m;
                break;
            }
        }
        jeab_inkorg_contact_ensure($new, $msg);
    }
    return ['ok' => true, 'dry' => $dry, 'changes' => $changes, 'unchanged' => $unchanged, 'skipped_linked' => $skippedLinked];
}

function jeab_inkorg_contacts_backfill(): int
{
    $n = 0;
    foreach (jeab_inkorg_messages() as $m) {
        if (jeab_inkorg_contact_from_message($m) !== null) {
            $n++;
        }
    }
    return $n;
}

function jeab_inkorg_contact_matches(array $c, string $q): bool
{
    $q = mb_strtolower(trim($q));
    if ($q === '') {
        return true;
    }
    $hay = mb_strtolower(implode(' ', [(string) ($c['name'] ?? ''), implode(' ', (array) ($c['emails'] ?? [])), (string) ($c['mobile'] ?? ''), (string) ($c['address'] ?? ''), (string) ($c['town'] ?? ''), (string) ($c['notes'] ?? '')]));
    $qp = jeab_inkorg_phone_norm($q);
    return str_contains($hay, $q) || ($qp !== '' && ($c['mobile'] ?? '') === $qp);
}

/** Kontaktens ärenden: kundens e-post eller mobilnummer. Plattformens From räknas inte. */
function jeab_inkorg_contact_threads(array $c, array $threads): array
{
    $emails = array_map('jeab_norm_email', (array) ($c['emails'] ?? []));
    $out = [];
    foreach ($threads as $t) {
        $party = jeab_inkorg_party($t);
        $hit = $party['email'] !== '' && in_array($party['email'], $emails, true);
        if (!$hit) {
            foreach ((array) ($t['messages'] ?? []) as $m) {
                $from = jeab_norm_email((string) ($m['from'] ?? ''));
                if ($from !== '' && !jeab_inkorg_address_blocked($from) && in_array($from, $emails, true)) {
                    $hit = true;
                    break;
                }
            }
        }
        if (!$hit && ($c['mobile'] ?? '') !== '' && empty($party['form'])) {
            foreach ($t['messages'] as $m) {
                if (jeab_inkorg_find_mobile((string) ($m['body'] ?? '')) === $c['mobile']) {
                    $hit = true;
                    break;
                }
            }
        }
        if ($hit) {
            $out[] = $t;
        }
    }
    return $out;
}

function jeab_inbox_contact_set_email_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }
    return !($in['dry'] === 0 || $in['dry'] === '0' || $in['dry'] === false);
}

/** MySQL när det är lagringen, annars SQLite. Ingen JSON-fil. */
function jeab_inbox_contact_set_email_audit_pdo(): ?PDO
{
    try {
        $driver = function_exists('jeab_driver') ? jeab_driver() : 'json';
        if ($driver === 'mysql' && function_exists('jeab_pdo_connect_mysql')) {
            $pdo = jeab_pdo_connect_mysql();
            jeab_ensure_contact_email_audit($pdo, 'mysql');
            return $pdo;
        }
        if ($driver === 'sqlite' && function_exists('jeab_pdo_connect_sqlite')) {
            $pdo = jeab_pdo_connect_sqlite();
            jeab_ensure_contact_email_audit($pdo, 'sqlite');
            return $pdo;
        }
    } catch (Throwable) {
        return null;
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return null;
    }
    $path = (string) (function_exists('jeab_config') ? (jeab_config()['db']['sqlite_path'] ?? '') : '');
    if ($path === '' || (function_exists('jeab_store_path_is_public_data') && jeab_store_path_is_public_data($path))) {
        return null;
    }
    try {
        if (function_exists('jeab_data_dir_ready')) {
            jeab_data_dir_ready($path);
        }
        $pdo = function_exists('jeab_pdo_connect_sqlite') ? jeab_pdo_connect_sqlite() : null;
        if (!$pdo instanceof PDO) {
            return null;
        }
        jeab_ensure_contact_email_audit($pdo, 'sqlite');
        return $pdo;
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, string> $pairs */
function jeab_inbox_contact_set_email_audit_pairs(array $pairs): ?string
{
    $lines = [];
    foreach ($pairs as $id => $email) {
        $id = trim((string) $id);
        $email = trim((string) $email);
        if ($id === '' && $email === '') {
            continue;
        }
        $lines[] = trim($id . ' ' . $email);
    }
    return $lines === [] ? null : implode("\n", $lines);
}

/**
 * @param array<string, mixed> $old
 */
function jeab_inbox_contact_set_email_audit(string $threadId, int $reminderId, array $old, string $email): int
{
    $pdo = jeab_inbox_contact_set_email_audit_pdo();
    if (!$pdo instanceof PDO) {
        return 0;
    }
    $inbox = is_array($old['inbox'] ?? null) ? jeab_inbox_contact_set_email_audit_pairs($old['inbox']) : null;
    $kontakt = is_array($old['kontakt'] ?? null) ? jeab_inbox_contact_set_email_audit_pairs($old['kontakt']) : null;
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO contact_email_audit (created_at, thread_id, reminder_id, old_thread, old_reminder, old_inbox, old_kontakt, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $at,
        $threadId,
        $reminderId,
        trim((string) ($old['thread'] ?? '')) !== '' ? (string) $old['thread'] : null,
        trim((string) ($old['reminder'] ?? '')) !== '' ? (string) $old['reminder'] : null,
        $inbox,
        $kontakt,
        $email,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $old
 * @return array<string, mixed>
 */
function jeab_inbox_contact_set_email_audit_view(array $old, string $email, int $auditId): array
{
    $out = ['audit_id' => $auditId];
    foreach (['thread', 'reminder'] as $key) {
        if (!array_key_exists($key, $old)) {
            continue;
        }
        $out[$key] = ['old' => (string) $old[$key], 'new' => $email];
    }
    foreach (['inbox', 'kontakt'] as $key) {
        $pairs = $old[$key] ?? null;
        if (!is_array($pairs) || $pairs === []) {
            continue;
        }
        $olds = [];
        foreach ($pairs as $one) {
            $one = trim((string) $one);
            if ($one !== '' && !in_array($one, $olds, true)) {
                $olds[] = $one;
            }
        }
        $out[$key] = ['old' => implode(', ', $olds), 'new' => $email];
    }
    return $out;
}

/** @return array{meta: array<string, mixed>, messages: list<array<string, mixed>>} */
function jeab_inbox_contact_set_email_slots(string $threadId): array
{
    $meta = $threadId !== '' && function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($threadId) : [];
    $messages = [];
    if ($threadId !== '' && function_exists('jeab_inkorg_messages')) {
        foreach (jeab_inkorg_messages() as $m) {
            if (is_array($m) && (string) ($m['thread_id'] ?? '') === $threadId) {
                $messages[] = $m;
            }
        }
    }
    return ['meta' => $meta, 'messages' => $messages];
}

function jeab_inbox_contact_set_email_partner_from(string $email): bool
{
    if ($email === '') {
        return false;
    }
    if (function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($email)) {
        return true;
    }
    return jeab_inkorg_address_blocked($email);
}

/** @return list<array<string, mixed>> */
function jeab_inbox_contact_set_email_audit_list(): array
{
    $pdo = jeab_inbox_contact_set_email_audit_pdo();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $rows = $pdo->query('SELECT created_at, thread_id, reminder_id, old_thread, old_reminder, old_inbox, old_kontakt, email FROM contact_email_audit ORDER BY id')->fetchAll();
    return is_array($rows) ? $rows : [];
}

function jeab_inbox_contact_set_email_thread_exists(string $tid): bool
{
    if ($tid === '') {
        return false;
    }
    if (isset(jeab_inkorg_threads_meta()[$tid])) {
        return true;
    }
    foreach (jeab_inkorg_messages() as $m) {
        if (is_array($m) && (string) ($m['thread_id'] ?? '') === $tid) {
            return true;
        }
    }
    return function_exists('jeab_hs_import_is_stored') && jeab_hs_import_is_stored($tid);
}

/**
 * inbox.contact_set_email. dry=1 är standard.
 * Sätter kundens e-post på trådens kontakt, påminnelsens kontakt och adresser som redan
 * står på den tråden eller påminnelsen. Andra inkorgskontakter med samma mobil rörs inte.
 * En påminnelse utanför personens bolag nekas. Gamla adressen ligger i contact_email_audit.
 * dry=1 skriver inget: changed är 0 och would_change är 1 när något skulle bytas.
 * Skickar inget. Partneradress nekas.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inbox_contact_set_email(array $in): array
{
    $dry = jeab_inbox_contact_set_email_dry($in);
    $threadId = trim((string) ($in['thread_id'] ?? ''));
    $reminderId = (int) ($in['reminder_id'] ?? 0);
    $email = function_exists('jeab_norm_email') ? jeab_norm_email((string) ($in['email'] ?? '')) : mb_strtolower(trim((string) ($in['email'] ?? '')));
    $base = ['would_send' => false, 'dry' => $dry ? 1 : 0];
    if ($threadId === '' && $reminderId < 1) {
        return ['ok' => false, 'error' => 'thread_id eller reminder_id saknas'] + $base;
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['ok' => false, 'error' => 'ogiltig e-post'] + $base;
    }
    $partner = (function_exists('jeab_lead_partner_address') && jeab_lead_partner_address($email))
        || jeab_inkorg_address_blocked($email);
    if ($partner) {
        return ['ok' => false, 'error' => 'partner'] + $base;
    }
    if ($threadId !== '' && !jeab_inbox_contact_set_email_thread_exists($threadId)) {
        return ['ok' => false, 'error' => 'not_found', 'thread_id' => $threadId] + $base;
    }
    $reminder = null;
    if ($reminderId > 0) {
        // jeab_get_reminder gömmer fel bolag som saknad rad. Råraden behövs så svaret blir bolag, inte not_found.
        $store = function_exists('jeab_store') ? jeab_store() : null;
        $reminder = $store ? $store->getReminder($reminderId) : null;
        if (!is_array($reminder)) {
            return ['ok' => false, 'error' => 'not_found', 'reminder_id' => $reminderId] + $base;
        }
        $remCompany = function_exists('jeab_reminder_company') ? jeab_reminder_company($reminder) : 'jarfalla';
        if (function_exists('jeab_company_allowed') && !jeab_company_allowed($remCompany)) {
            return ['ok' => false, 'error' => 'bolag', 'reminder_id' => $reminderId] + $base;
        }
    }
    $slots = jeab_inbox_contact_set_email_slots($threadId);
    $meta = $slots['meta'];
    $messages = $slots['messages'];
    $old = [];
    $threadChanged = 0;
    $reminderChanged = 0;
    $inboxChanged = 0;
    $kontaktChanged = 0;
    $replace = [];
    $messageIds = [];
    if ($threadId !== '') {
        $contactEmail = function_exists('jeab_contacts_inbox_contact_email')
            ? jeab_norm_email(jeab_contacts_inbox_contact_email($meta))
            : '';
        $copyEmail = jeab_norm_email((string) ($meta['customer_email'] ?? ''));
        $lead = is_array($meta['lead_customer'] ?? null) ? $meta['lead_customer'] : [];
        $leadCopy = jeab_norm_email((string) ($lead['email'] ?? ''));
        $metaEmail = $contactEmail !== '' ? $contactEmail : $copyEmail;
        $leadEmail = $contactEmail !== '' ? $contactEmail : $leadCopy;
        $visible = '';
        foreach ($messages as $m) {
            $src = (string) ($m['source'] ?? '');
            $from = jeab_norm_email((string) ($m['from'] ?? ''));
            if (str_starts_with($src, 'outbound-') || $from === '') {
                continue;
            }
            $partnerFrom = jeab_inbox_contact_set_email_partner_from($from);
            $onMessage = jeab_norm_email((string) ($m['customer_email'] ?? ''));
            if ($partnerFrom && $from !== $email) {
                $replace[$from] = true;
                if ($visible === '') {
                    $visible = $from;
                }
            }
            if ($partnerFrom && $onMessage !== $email) {
                $messageIds[] = (string) ($m['id'] ?? '');
            }
        }
        if ($metaEmail !== '' && $metaEmail !== $email) {
            $replace[$metaEmail] = true;
            if ($visible === '') {
                $visible = $metaEmail;
            }
        }
        if ($leadEmail !== '' && $leadEmail !== $email && $visible === '') {
            $visible = $leadEmail;
        }
        $threadNeeds = $metaEmail !== $email
            || $leadEmail !== $email
            || $messageIds !== [];
        if ($threadNeeds) {
            $threadChanged = 1;
            $old['thread'] = $visible !== '' ? $visible : $metaEmail;
        }
    }
    $reminderColumn = '';
    $reminderShown = '';
    if (is_array($reminder)) {
        $reminderColumn = jeab_norm_email((string) ($reminder['email'] ?? ''));
        $shown = function_exists('jeab_reminder_shown_customer') ? jeab_reminder_shown_customer($reminder) : [];
        $reminderShown = jeab_norm_email((string) ($shown['email'] ?? ''));
        if ($reminderShown === '') {
            $reminderShown = $reminderColumn;
        }
        if ($reminderShown !== $email || $reminderColumn !== $email) {
            $reminderChanged = 1;
            $old['reminder'] = $reminderShown !== '' ? $reminderShown : $reminderColumn;
            if ($reminderShown !== '' && $reminderShown !== $email) {
                $replace[$reminderShown] = true;
            }
        }
    }
    $inboxOld = [];
    $inboxPartners = [];
    foreach (jeab_inkorg_contacts(true) as $id => $row) {
        if (!is_array($row)) {
            continue;
        }
        $local = [];
        foreach ((array) ($row['emails'] ?? []) as $one) {
            $norm = jeab_norm_email((string) $one);
            if ($norm === '' || $norm === $email) {
                continue;
            }
            // Bara adresser som redan sitter på tråden eller påminnelsen. Inte mobil ur brödtexten.
            if (isset($replace[$norm])) {
                $local[] = $norm;
            }
        }
        if ($local !== []) {
            $inboxOld[(string) $id] = $local[0];
            $inboxPartners[(string) $id] = $local;
        }
    }
    if ($inboxOld !== []) {
        $inboxChanged = 1;
        $old['inbox'] = $inboxOld;
    }
    $kontaktIds = [];
    if ($threadId !== '') {
        $linked = trim((string) ($meta['contact_id'] ?? ''));
        if ($linked !== '') {
            $kontaktIds[$linked] = true;
        }
    }
    if (is_array($reminder)) {
        $linked = trim((string) ($reminder['contact_id'] ?? ''));
        if ($linked !== '') {
            $kontaktIds[$linked] = true;
        }
    }
    $kontaktOld = [];
    foreach (array_keys($kontaktIds) as $cid) {
        if (!function_exists('jeab_kontakt_get')) {
            break;
        }
        $row = jeab_kontakt_get((string) $cid);
        if (!is_array($row)) {
            continue;
        }
        $current = function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '';
        if ($current !== $email) {
            $kontaktOld[(string) $cid] = $current;
        }
    }
    if ($kontaktOld !== []) {
        $kontaktChanged = 1;
        $old['kontakt'] = $kontaktOld;
    }
    $would = ($threadChanged || $reminderChanged || $inboxChanged || $kontaktChanged) ? 1 : 0;
    $out = [
        'ok' => true,
        'thread_id' => $threadId,
        'reminder_id' => $reminderId,
        'changed' => $dry ? 0 : $would,
        'would_change' => $dry ? $would : 0,
        'thread' => $threadChanged,
        'reminder' => $reminderChanged,
        'inbox' => $inboxChanged,
        'kontakt' => $kontaktChanged,
        'audit' => jeab_inbox_contact_set_email_audit_view($old, $email, 0),
    ] + $base;
    if ($dry || $would === 0) {
        return $out;
    }
    if ($threadChanged === 1 && function_exists('jeab_inkorg_thread_write')) {
        $linkedThread = false;
        jeab_inkorg_thread_write($threadId, static function (array $t) use ($email, &$linkedThread): array {
            if (jeab_contacts_inbox_strip_flags_on() && trim((string) ($t['contact_id'] ?? '')) !== '') {
                $linkedThread = true;
                return function_exists('jeab_inkorg_drop_linked_customer_copy')
                    ? jeab_inkorg_drop_linked_customer_copy($t)
                    : $t;
            }
            $t['customer_email'] = $email;
            $t['customer_source'] = 'hook';
            $lead = is_array($t['lead_customer'] ?? null) ? $t['lead_customer'] : [];
            $lead['email'] = $email;
            $t['lead_customer'] = $lead;
            return $t;
        });
        // Kopplad tråd med spärren på: meddelandena får ingen kundkopia heller.
        if (!$linkedThread && function_exists('jeab_inkorg_update')) {
            foreach ($messageIds as $mid) {
                if ($mid === '') {
                    continue;
                }
                jeab_inkorg_update($mid, static function (array $m) use ($email): array {
                    $m['customer_email'] = $email;
                    return $m;
                });
            }
        }
    }
    if ($reminderChanged === 1 && function_exists('jeab_store')) {
        $fields = ['updated_at' => function_exists('jeab_now') ? jeab_now() : date('c')];
        if ($reminderColumn !== $email) {
            $fields['email'] = $email;
        }
        $note = (string) ($reminder['note'] ?? '');
        if ($reminderShown !== '' && $reminderShown !== $email && $note !== '' && stripos($note, $reminderShown) !== false) {
            $fields['note'] = str_ireplace($reminderShown, $email, $note);
        }
        jeab_store()->saveReminder($reminderId, $fields);
    }
    if ($inboxChanged === 1) {
        $only = $inboxPartners;
        jeab_kontakt_inbox_locked(static function (?array $d) use ($only, $email): array {
            $d = $d ?? ['contacts' => []];
            foreach ($d['contacts'] as $id => $row) {
                if (!is_array($row) || !isset($only[(string) $id])) {
                    continue;
                }
                $needles = $only[(string) $id];
                $emails = [];
                $hit = false;
                foreach ((array) ($row['emails'] ?? []) as $one) {
                    $norm = jeab_norm_email((string) $one);
                    if (in_array($norm, $needles, true)) {
                        $hit = true;
                        if (!in_array($email, $emails, true)) {
                            $emails[] = $email;
                        }
                        continue;
                    }
                    if ($norm !== '' && !in_array($norm, $emails, true)) {
                        $emails[] = $norm;
                    }
                }
                if ($hit) {
                    $row['emails'] = $emails;
                    $d['contacts'][$id] = $row;
                }
            }
            return [true, $d];
        });
    }
    if ($kontaktChanged === 1 && function_exists('jeab_reminder_save_contact_customer')) {
        foreach (array_keys($kontaktOld) as $cid) {
            jeab_reminder_save_contact_customer((string) $cid, ['email' => $email]);
        }
    }
    $auditId = jeab_inbox_contact_set_email_audit($threadId, $reminderId, $old, $email);
    $out['audit'] = jeab_inbox_contact_set_email_audit_view($old, $email, $auditId);
    return $out;
}
