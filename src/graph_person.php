<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Personbrevlådor via Microsoft Graph (Sam 2026-10-15).
 * Samma Entra-app som Inkorgen (data/.private/secrets/m365.json, applikationsbehörighet).
 * Kräver Mail.Send/Mail.ReadWrite + Calendars.ReadWrite och att personens brevlåda ligger i
 * jeab-inkorg-scope (ApplicationAccessPolicy). Allt är AV tills flaggorna slås på i
 * inkorg/settings.json: graph_person_mail, graph_person_calendar (bool, standard false).
 * Malmö kundmejl ligger kvar i Help Scout; detta rör bara personalens brevlådor/kalendrar.
 */

function jeab_gp_flag(string $key): bool
{
    if (isset($GLOBALS['_JEAB_GP_FLAGS'][$key])) {
        return (bool) $GLOBALS['_JEAB_GP_FLAGS'][$key];
    }
    if (!function_exists('jeab_inkorg_json_file')) {
        return false;
    }
    $d = jeab_inkorg_json_file('settings.json');
    return is_array($d) && !empty($d[$key]);
}

function jeab_gp_mail_on(): bool
{
    return jeab_gp_flag('graph_person_mail') && function_exists('jeab_graph_configured') && jeab_graph_configured();
}

function jeab_gp_calendar_on(): bool
{
    return jeab_gp_flag('graph_person_calendar') && function_exists('jeab_graph_configured') && jeab_graph_configured();
}

function jeab_gp_flags_save(array $in): array
{
    return jeab_inkorg_locked('settings.json', function (?array $d) use ($in) {
        $next = is_array($d) ? $d : [];
        foreach (['graph_person_mail', 'graph_person_calendar'] as $k) {
            if (array_key_exists($k, $in)) {
                $next[$k] = !empty($in[$k]) && $in[$k] !== '0';
            }
        }
        $out = [
            'graph_person_mail' => !empty($next['graph_person_mail']),
            'graph_person_calendar' => !empty($next['graph_person_calendar']),
        ];

        return [$out, $next];
    });
}

/** Personalens brevlådor (alla bolag), från people-listan. */
function jeab_gp_staff_emails(): array
{
    $out = [];
    foreach (jeab_people() as $p) {
        $e = jeab_normalize_person_email($p['email'] ?? '');
        if ($e !== null) {
            $out[$e] = ['name' => (string) ($p['name'] ?? ''), 'company' => (string) ($p['company'] ?? '')];
        }
    }
    return $out;
}

/** Offertägare per produkt. Malmö som ligger i Help Scout har ingen personavsändare. Är Malmö på i inkorgen går batteri och elbilsladdare från Kim. Sol i Malmö hålls. */
function jeab_gp_owner_email(string $offer, string $company): string
{
    if ($company === 'malmo') {
        $kimQuote = !empty($GLOBALS['_JEAB_MALMO_HS_KIM_QUOTE']);
        if ((!function_exists('jeab_inkorg_company_live') || !jeab_inkorg_company_live('malmo')) && !$kimQuote) {
            return '';
        }
        if (!in_array($offer, ['batteri', 'laddbox'], true)) {
            return '';
        }
        return jeab_gp_person_email('Kim', 'malmo');
    }
    if ($company !== 'jarfalla') {
        return '';
    }
    $who = ['batteri' => 'Roberto', 'laddbox' => 'Elin', 'sol' => 'Sam L'][$offer] ?? '';
    return $who === '' ? '' : jeab_gp_person_email($who, 'jarfalla');
}

/**
 * Automatik-texten i Inkorgen. Adresserna kommer från jeab_gp_owner_email,
 * samma funktion som utskicket, så texten inte kan glida isär från avsändaren.
 * Solceller markeras pausad bara när Järfälla-mallen är tom.
 * De skickade mejlen (mall A/B) är den riktiga mallen och pausar inte.
 */
function jeab_gp_auto_sender_phrase(): string
{
    $labels = [
        'batteri' => 'Batteri',
        'laddbox' => 'Elbilsladdare',
        'sol' => 'Solceller med batteri',
    ];
    $solHeld = jeab_auto_sol_jarfalla_placeholder((string) (jeab_auto_settings()['templates']['sol_jarfalla'] ?? ''));
    $bits = [];
    foreach ($labels as $offer => $label) {
        $email = jeab_gp_owner_email($offer, 'jarfalla');
        if ($email === '') {
            continue;
        }
        $bit = $label . ' från ' . $email;
        if ($offer === 'sol' && $solHeld) {
            $bit .= ' (pausad tills solmallen är ifylld)';
        }
        $bits[] = $bit;
    }
    $lead = 'offert från personens egen brevlåda (Graph)';
    if ($bits === []) {
        return $lead;
    }
    $last = array_pop($bits);
    $list = $bits === [] ? $last : implode(', ', $bits) . ' och ' . $last;
    return $lead . ' — ' . $list . ' —';
}

function jeab_gp_person_email(string $who, ?string $company = null): string
{
    $who = trim($who);
    if (str_contains($who, '@')) {
        $e = jeab_normalize_person_email($who);
        return $e !== null && isset(jeab_gp_staff_emails()[$e]) ? $e : '';
    }
    $p = jeab_person_by_name($who, $company);
    if (!$p && $company !== null) {
        $p = jeab_person_by_name($who, null);
    }
    return $p ? (string) (jeab_normalize_person_email($p['email'] ?? '') ?? '') : '';
}

/** Läser åtkomst per brevlåda (inget skrivs). code 200 = OK, 403 = policy/behörighet saknas. */
function jeab_gp_probe(): array
{
    $rows = [];
    foreach (jeab_gp_staff_emails() as $e => $info) {
        $u = 'users/' . rawurlencode($e);
        $mail = jeab_graph_api('GET', $u . '/mailFolders/sentitems?$select=id');
        $cal = jeab_graph_api('GET', $u . '/calendar?$select=id');
        $rows[] = ['email' => $e, 'name' => $info['name'], 'company' => $info['company'], 'mail' => (int) $mail['code'], 'calendar' => (int) $cal['code']];
    }
    return [
        'ok' => true,
        'ai_key_present' => function_exists('jeab_auto_ai_key_present') && jeab_auto_ai_key_present(),
        'flags' => ['graph_person_mail' => jeab_gp_flag('graph_person_mail'), 'graph_person_calendar' => jeab_gp_flag('graph_person_calendar')],
        'mailboxes' => $rows,
    ];
}

/**
 * Hook inbox.gp_sent. Senaste N mejlen i Skickat för en vitlistad personbrevlåda.
 * N är 1–10, standard 10. Bara ämne, till, sentDateTime och bilagenamn.
 * body_check=1 hämtar brödtexten med GET och svarar med flaggor och antal, aldrig texten.
 * Samma token som inbox.gp_probe. Bara GET. Brödtext och bilageinnehåll kopieras inte.
 */
function jeab_gp_sent(array $in): array
{
    $email = jeab_gp_sent_mailbox($in);
    if ($email === '') {
        return ['ok' => false, 'error' => 'Okänd personbrevlåda.'];
    }
    $n = jeab_gp_sent_limit($in);
    $check = jeab_gp_sent_wants_body_check($in);
    $list = jeab_graph_api('GET', 'users/' . rawurlencode($email) . '/mailFolders/sentitems/messages?$top=' . $n . '&$orderby=sentDateTime%20desc&$select=id,subject,toRecipients,sentDateTime,hasAttachments');
    if ((int) $list['code'] !== 200) {
        return ['ok' => false, 'error' => 'graph ' . (int) $list['code'], 'mailbox' => $email];
    }
    $messages = [];
    $counts = ['has_helpscout_link' => 0, 'has_kundens_fraga' => 0, 'has_internal_marker' => 0];
    foreach (array_slice(array_values(array_filter((array) ($list['body']['value'] ?? []), 'is_array')), 0, $n) as $m) {
        $id = trim((string) ($m['id'] ?? ''));
        $names = [];
        $has = array_key_exists('hasAttachments', $m) ? !empty($m['hasAttachments']) : true;
        if ($has) {
            if ($id === '') {
                return ['ok' => false, 'error' => 'graph 0', 'mailbox' => $email];
            }
            $att = jeab_graph_api('GET', 'users/' . rawurlencode($email) . '/messages/' . rawurlencode($id) . '/attachments?$select=name');
            if ((int) $att['code'] !== 200) {
                return ['ok' => false, 'error' => 'graph ' . (int) $att['code'], 'mailbox' => $email];
            }
            foreach ((array) ($att['body']['value'] ?? []) as $a) {
                if (!is_array($a)) {
                    continue;
                }
                $name = trim((string) ($a['name'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        $to = [];
        foreach ((array) ($m['toRecipients'] ?? []) as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $addr = trim((string) ($rec['emailAddress']['address'] ?? ''));
            if ($addr !== '') {
                $to[] = $addr;
            }
        }
        $row = [
            'subject' => (string) ($m['subject'] ?? ''),
            'to' => $to,
            'sentDateTime' => (string) ($m['sentDateTime'] ?? ''),
            'attachments' => $names,
        ];
        if ($check) {
            if ($id === '') {
                return ['ok' => false, 'error' => 'graph 0', 'mailbox' => $email];
            }
            $got = jeab_gp_sent_fetch_body($email, $id);
            if ((int) ($got['code'] ?? 0) !== 200) {
                return ['ok' => false, 'error' => 'graph ' . (int) ($got['code'] ?? 0), 'mailbox' => $email];
            }
            $flags = jeab_gp_sent_body_flags(jeab_gp_sent_body_content($got['body'] ?? null));
            foreach ($flags as $key => $on) {
                $row[$key] = $on;
                if ($on) {
                    $counts[$key]++;
                }
            }
        }
        $messages[] = $row;
    }
    $out = ['ok' => true, 'mailbox' => $email, 'n' => $n, 'messages' => $messages];
    if ($check) {
        $out['body_check'] = 1;
        $out['counts'] = $counts;
    }
    return $out;
}

function jeab_gp_sent_wants_body_check(array $in): bool
{
    if (!array_key_exists('body_check', $in)) {
        return false;
    }
    $value = $in['body_check'];
    return $value === 1 || $value === '1' || $value === true;
}

/** Bara GET av brödtexten. Inget skrivs till brevlådan. */
function jeab_gp_sent_fetch_body(string $email, string $id): array
{
    return jeab_graph_api(
        'GET',
        'users/' . rawurlencode($email) . '/messages/' . rawurlencode($id) . '?$select=body',
        null,
        ['Prefer: outlook.body-content-type="text"']
    );
}

function jeab_gp_sent_body_content(mixed $payload): string
{
    if (!is_array($payload)) {
        return '';
    }
    $body = $payload['body'] ?? '';
    if (is_string($body)) {
        return $body;
    }
    if (!is_array($body)) {
        return '';
    }
    return (string) ($body['content'] ?? '');
}

/** @return array{has_helpscout_link: bool, has_kundens_fraga: bool, has_internal_marker: bool} */
function jeab_gp_sent_body_flags(string $content): array
{
    $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($decoded)) ?? $decoded);
    return [
        'has_helpscout_link' => stripos($content, 'helpscout.net') !== false || stripos($decoded, 'helpscout.net') !== false,
        'has_kundens_fraga' => preg_match('/kundens\s+fråga/iu', $text) === 1,
        'has_internal_marker' => preg_match('/HS\s*:/iu', $decoded) === 1 || preg_match('/\bUtkast\b/iu', $text) === 1,
    ];
}

function jeab_gp_sent_mailbox(array $in): string
{
    $raw = $in['mailbox'] ?? '';
    if (!is_string($raw)) {
        return '';
    }
    $email = jeab_normalize_person_email($raw) ?? '';
    return $email !== '' && isset(jeab_gp_staff_emails()[$email]) ? $email : '';
}

function jeab_gp_sent_limit(array $in): int
{
    if (!array_key_exists('n', $in) || $in['n'] === '' || $in['n'] === null) {
        return 10;
    }
    $n = $in['n'];
    $ok = is_int($n) || (is_string($n) && preg_match('/^[1-9]\d*$/', $n) === 1);
    $i = $ok ? (int) $n : 0;
    if (!$ok || $i < 1 || $i > 10) {
        throw new InvalidArgumentException('n ska vara ett heltal mellan 1 och 10.');
    }
    return $i;
}

/** Bygger ett Graph-meddelande (HTML) med bilagor. */
function jeab_gp_message(string $to, string $subject, string $html, array $attachments, array $cc = []): array
{
    $msg = [
        'subject' => $subject,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => jeab_graph_recips([$to]),
    ];
    if ($cc) {
        $msg['ccRecipients'] = jeab_graph_recips($cc);
    }
    $files = jeab_outbound_graph_attachments($attachments);
    if ($files) {
        $msg['attachments'] = $files;
    }
    return $msg;
}

/**
 * Skickar från personens egen brevlåda (sparas i personens Skickat) och slår upp det skickade mejlet.
 * @return array{ok: bool, error?: string, from?: string, graph_id?: string, conversation_id?: string, message_id?: string, ref?: string, via?: string}
 */
function jeab_gp_send(string $email, string $to, string $subject, string $html, array $attachments = [], array $cc = [], array $opts = []): array
{
    if (function_exists('jeab_demo_sent')) {
        $demoSent = jeab_demo_sent('email', $to);
        if ($demoSent !== null) {
            return $demoSent;
        }
    }
    if (function_exists('jeab_test_lead_take_mail')) {
        $mail = jeab_test_lead_take_mail($to, $cc, $opts);
        if (!empty($mail['block_to'])) {
            return ['ok' => false, 'error' => 'test lead', 'skipped' => true, 'reason' => 'testlead'];
        }
        $cc = $mail['cc'];
        $opts = $mail['opts'];
    }
    if (function_exists('jeab_lead_refuse_send')) {
        $why = jeab_lead_refuse_send($to, $cc, 'graph-person');
        if ($why !== '') {
            return ['ok' => false, 'error' => $why];
        }
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'omklassning skickar inte'];
    }
    if (function_exists('jeab_outbound_seller_blocked')) {
        $blocked = jeab_outbound_seller_blocked($to, $cc, $opts);
        if ($blocked !== '') {
            return ['ok' => false, 'error' => $blocked];
        }
    }
    $email = jeab_normalize_person_email($email) ?? '';
    if ($email === '' || !isset(jeab_gp_staff_emails()[$email])) {
        return ['ok' => false, 'error' => 'Okänd personbrevlåda.'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Fel mottagare.'];
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $gate = jeab_send_guard_gate('email', $to, $html, [
            'template' => (string) ($opts['guard_template'] ?? 'email'),
            'mode' => (string) ($opts['guard_mode'] ?? 'auto'),
            'via' => (string) ($opts['guard_via'] ?? ''),
            'actor' => (string) ($opts['guard_actor'] ?? ''),
            'body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
            'handler' => 'gp_send',
            'job' => [
                'email' => $email,
                'to' => $to,
                'subject' => $subject,
                'html' => $html,
                'attachments' => $attachments,
                'cc' => $cc,
                'opts' => $opts,
            ],
        ]);
        if (($gate['action'] ?? '') === 'stop') {
            $stopped = $gate['result'] ?? null;
            return is_array($stopped) ? $stopped : ['ok' => false, 'error' => 'Automatiska utskick är stoppade.'];
        }
    }
    try {
    $msg = jeab_gp_message($to, $subject, $html, $attachments, $cc);
    if (!empty($opts['strip_cc'])) {
        $msg['ccRecipients'] = jeab_graph_recips($cc);
    }
    $replyTo = trim((string) ($opts['reply_to'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyName = trim((string) ($opts['reply_to_name'] ?? ''));
        $addr = ['address' => $replyTo];
        if ($replyName !== '') {
            $addr['name'] = $replyName;
        }
        $msg['replyTo'] = [['emailAddress' => $addr]];
    }
    $ref = bin2hex(random_bytes(8));
    $msg['internetMessageHeaders'] = [['name' => 'X-JEAB-Ref', 'value' => $ref]];
    $u = 'users/' . rawurlencode($email);
    $replyId = trim((string) ($opts['reply_to_graph_id'] ?? ''));
    $irt = trim((string) ($opts['in_reply_to'] ?? ''), '<> ');
    $r = ['code' => 0];
    $replied = false;
    if ($replyId !== '') {
        $rm = $msg;
        if (empty($opts['keep_subject'])) {
            unset($rm['subject']);
        }
        $r = jeab_graph_api('POST', $u . '/messages/' . rawurlencode($replyId) . '/reply', ['message' => $rm]);
        $replied = (int) ($r['code'] ?? 0) === 202;
        if (!$replied && function_exists('jeab_graph_reply_uncertain') && jeab_graph_reply_uncertain($r)) {
            $fail = function_exists('jeab_graph_reply_uncertain_fail')
                ? jeab_graph_reply_uncertain_fail($r)
                : ['ok' => false, 'error' => 'graph ' . (int) ($r['code'] ?? 0), 'transport' => true];

            return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $fail) : $fail;
        }
    }
    if (!$replied) {
        if ($irt !== '') {
            $msg['singleValueExtendedProperties'] = [[
                'id' => 'String 0x1042',
                'value' => '<' . $irt . '>',
            ]];
        }
        $r = jeab_graph_api('POST', $u . '/sendMail', ['message' => $msg, 'saveToSentItems' => true]);
        if ((int) $r['code'] !== 202) {
            $fail = ['ok' => false, 'error' => 'graph ' . (int) $r['code']];
            if (!empty($r['blocked'])) {
                $fail['blocked'] = true;
            }

            return $fail;
        }
    }
    $out = ['ok' => true, 'via' => 'graph-person', 'from' => $email, 'graph_id' => '', 'conversation_id' => '', 'message_id' => '', 'ref' => $ref, 'replied' => $replied];
    $q = $u . '/mailFolders/sentitems/messages?$top=15&$orderby=sentDateTime%20desc&$select=id,subject,conversationId,internetMessageId,internetMessageHeaders';
    for ($i = 0; $i < 4; $i++) {
        if (function_exists('jeab_auto_quote_web_budget_active') && jeab_auto_quote_web_budget_active() && jeab_auto_quote_web_remaining_seconds() < 2) {
            break;
        }
        if ($i > 0 && empty($GLOBALS['_JEAB_GRAPH_HTTP'])) {
            $pauseStarted = hrtime(true);
            usleep(1500000);
            if (function_exists('jeab_resend_profile_add')) {
                jeab_resend_profile_add('graph', (int) round((hrtime(true) - $pauseStarted) / 1e6));
            }
        }
        foreach ((array) (jeab_graph_api('GET', $q)['body']['value'] ?? []) as $m) {
            foreach ((array) ($m['internetMessageHeaders'] ?? []) as $h) {
                if (strcasecmp((string) ($h['name'] ?? ''), 'X-JEAB-Ref') === 0 && trim((string) ($h['value'] ?? '')) === $ref) {
                    $out['graph_id'] = (string) ($m['id'] ?? '');
                    $out['conversation_id'] = (string) ($m['conversationId'] ?? '');
                    $out['message_id'] = trim((string) ($m['internetMessageId'] ?? ''), '<> ');
                    $out['in_sent_items'] = true;
                    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
                }
            }
        }
    }
    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
    } finally {
        if (function_exists('jeab_send_guard_release')) {
            jeab_send_guard_release($gate);
        }
    }
}

/** Utkast i personens egen brevlåda (Utkast-mappen). Skickar inget. */
function jeab_gp_create_draft(string $email, string $to, string $subject, string $html, array $attachments = []): array
{
    $email = jeab_normalize_person_email($email) ?? '';
    if ($email === '' || !isset(jeab_gp_staff_emails()[$email])) {
        return ['ok' => false, 'error' => 'Okänd personbrevlåda.'];
    }
    $r = jeab_graph_api('POST', 'users/' . rawurlencode($email) . '/messages', jeab_gp_message($to, $subject, $html, $attachments));
    $id = (string) ($r['body']['id'] ?? '');
    return (int) $r['code'] === 201 && $id !== '' ? ['ok' => true, 'id' => $id, 'from' => $email] : ['ok' => false, 'error' => 'graph ' . (int) $r['code']];
}

/* ---------- Kalender ---------- */

function jeab_gp_tz(): string
{
    return defined('JEAB_TZ') ? JEAB_TZ : 'Europe/Stockholm';
}

/** Upptagna tider i personens kalender (calendarView). */
function jeab_gp_busy_events(string $email, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $tz = new DateTimeZone(jeab_gp_tz());
    $q = 'users/' . rawurlencode($email) . '/calendarView?startDateTime=' . rawurlencode($from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
        . '&endDateTime=' . rawurlencode($to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
        . '&$top=200&$select=start,end,isCancelled,showAs,isAllDay';
    $r = jeab_graph_api('GET', $q, null, ['Prefer: outlook.timezone="UTC"']);
    if ((int) $r['code'] !== 200) {
        return [];
    }
    $fromSql = $from->setTimezone($tz)->format('Y-m-d H:i:s');
    $toSql = $to->setTimezone($tz)->format('Y-m-d H:i:s');
    $out = [];
    foreach ((array) ($r['body']['value'] ?? []) as $ev) {
        if (!empty($ev['isCancelled']) || in_array(strtolower((string) ($ev['showAs'] ?? '')), ['free', 'workingelsewhere'], true)) {
            continue;
        }
        try {
            $s = (new DateTimeImmutable((string) ($ev['start']['dateTime'] ?? ''), new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s');
            $e = (new DateTimeImmutable((string) ($ev['end']['dateTime'] ?? ''), new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            continue;
        }
        if ($e <= $s || $e <= $fromSql || $s >= $toSql) {
            continue;
        }
        $out[] = ['starts_at' => $s, 'ends_at' => $e];
    }
    return $out;
}

function jeab_gp_events_path(): string
{
    return jeab_data_dir() . '/graph-person-events.json';
}

/**
 * Kundfälten på en kopia. Resursens e-post rörs inte. Flaggan av lämnar raden.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_gp_shown_booking(array $row): array
{
    if (!function_exists('jeab_contacts_sole_paint_booking')) {
        return $row;
    }

    return jeab_contacts_sole_paint_booking($row);
}

function jeab_gp_redact_pnr(string $text): string
{
    if (function_exists('jeab_inkorg_text_hide_pnr')) {
        return jeab_inkorg_text_hide_pnr($text);
    }
    $text = preg_replace('/(?<!\d)(?:(?:19|20)\d{6}[-+]?\d{4}|\d{6}[-+]\d{4})(?!\d)/', '', $text) ?? $text;

    return $text;
}

function jeab_gp_keep_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    return trim(jeab_gp_redact_pnr($text));
}

function jeab_gp_booking_purchase(array $row): ?array
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_by_booking')) {
        return null;
    }
    $purchase = jeab_purchase_by_booking($id);

    return is_array($purchase) ? $purchase : null;
}

function jeab_gp_reminder_by_booking(JeabStore $store, int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }
    if ($store instanceof JeabPdoStore) {
        $stmt = $store->pdo()->prepare('SELECT * FROM reminders WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$bookingId]);
        $found = $stmt->fetch();
        if (is_array($found) && function_exists('jeab_hydrate_reminder')) {
            return jeab_hydrate_reminder($found);
        }

        return null;
    }
    $open = $store->findOpenReminderByBooking($bookingId);

    return is_array($open) ? $open : null;
}

function jeab_gp_booking_reminder(array $row, ?array $purchase): ?array
{
    $store = null;
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
    }
    if (!$store instanceof JeabStore) {
        return null;
    }
    if (is_array($purchase) && function_exists('jeab_get_reminder')) {
        $reminder = jeab_get_reminder($store, (int) ($purchase['reminder_id'] ?? 0));
        if (is_array($reminder)) {
            return $reminder;
        }
    }
    $linked = jeab_gp_reminder_by_booking($store, (int) ($row['id'] ?? 0));

    return is_array($linked) ? $linked : null;
}

/** @param array{street?:string,postnummer?:string,place?:string} $lines */
function jeab_gp_format_address_lines(array $lines): string
{
    $street = trim((string) ($lines['street'] ?? ''));
    $post = trim((string) ($lines['postnummer'] ?? ''));
    $place = trim((string) ($lines['place'] ?? ''));
    $tail = trim($post . ($place !== '' ? ' ' . $place : ''));
    if ($street === '') {
        return $tail;
    }
    if ($tail === '') {
        return $street;
    }
    $folded = function_exists('mb_strtolower') ? mb_strtolower($street, 'UTF-8') : strtolower($street);
    $tailFold = function_exists('mb_strtolower') ? mb_strtolower($tail, 'UTF-8') : strtolower($tail);
    if ($tailFold !== '' && str_contains($folded, $tailFold)) {
        return $street;
    }

    return $street . ', ' . $tail;
}

function jeab_gp_order_visit_address(array $purchase): string
{
    if (!function_exists('jeab_ordrar_status_address_stored')) {
        return '';
    }

    return jeab_gp_keep_text(jeab_gp_format_address_lines(jeab_ordrar_status_address_stored($purchase)));
}

/** Kontaktens visning. Flaggan av ger null. */
function jeab_gp_contact_view(array $row): ?array
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return null;
    }
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id === '' || !function_exists('jeab_contacts_sole_linked_contact') || !function_exists('jeab_contacts_sole_view_from_contact')) {
        return null;
    }
    $contact = jeab_contacts_sole_linked_contact($id);
    if (!is_array($contact)) {
        return null;
    }

    return jeab_contacts_sole_view_from_contact($contact);
}

/** Besöksadress: kontakten först, sedan ordern, bokningen och påminnelsen. */
function jeab_gp_visit_address(array $row): string
{
    $view = jeab_gp_contact_view($row);
    if (is_array($view)) {
        $fromContact = jeab_gp_keep_text((string) ($view['display'] ?? ''));
        if ($fromContact !== '') {
            return $fromContact;
        }
    }
    $purchase = jeab_gp_booking_purchase($row);
    if (is_array($purchase)) {
        $fromOrder = jeab_gp_order_visit_address($purchase);
        if ($fromOrder !== '') {
            return $fromOrder;
        }
    }
    $shown = jeab_gp_shown_booking($row);
    $fromBooking = jeab_gp_keep_text((string) ($shown['address'] ?? ''));
    if ($fromBooking === '') {
        $fromBooking = jeab_gp_keep_text((string) ($row['address'] ?? ''));
    }
    if ($fromBooking !== '') {
        return $fromBooking;
    }
    $reminder = jeab_gp_booking_reminder($row, $purchase);
    if (!is_array($reminder)) {
        return '';
    }
    if (function_exists('jeab_reminder_shown_customer')) {
        $view = jeab_reminder_shown_customer($reminder);
        $display = jeab_gp_keep_text((string) ($view['display'] ?? ''));
        if ($display !== '') {
            return $display;
        }
    }

    return jeab_gp_keep_text((string) ($reminder['address'] ?? ''));
}

function jeab_gp_event_phone(array $row, array $shown, ?array $purchase, ?array $reminder): string
{
    $view = jeab_gp_contact_view($row);
    $candidates = [
        is_array($view) ? ($view['phone'] ?? '') : '',
        $shown['phone'] ?? '',
        $row['phone'] ?? '',
        is_array($purchase) ? ($purchase['phone'] ?? '') : '',
        is_array($reminder) ? ($reminder['phone'] ?? '') : '',
    ];
    foreach ($candidates as $candidate) {
        $phone = function_exists('jeab_normalize_booking_phone')
            ? (jeab_normalize_booking_phone($candidate) ?? '')
            : '';
        if ($phone === '') {
            $phone = trim((string) $candidate);
        }
        if ($phone !== '') {
            return jeab_gp_keep_text($phone);
        }
    }

    return '';
}

function jeab_gp_event_email(array $row, array $shown, ?array $purchase, ?array $reminder): string
{
    $view = jeab_gp_contact_view($row);
    $candidates = [
        is_array($view) ? ($view['email'] ?? '') : '',
        $shown['email'] ?? '',
        $row['email'] ?? '',
        is_array($purchase) ? ($purchase['email'] ?? '') : '',
        is_array($reminder) ? ($reminder['email'] ?? '') : '',
    ];
    foreach ($candidates as $candidate) {
        $email = trim((string) $candidate);
        if ($email !== '' && str_contains($email, '@')) {
            return $email;
        }
    }

    return '';
}

function jeab_gp_install_text(?array $purchase): string
{
    if (!is_array($purchase)) {
        return '';
    }
    $what = function_exists('jeab_ordrar_install_what')
        ? jeab_ordrar_install_what($purchase)
        : trim((string) ($purchase['install_what'] ?? ''));
    $note = function_exists('jeab_ordrar_install_note')
        ? jeab_ordrar_install_note($purchase)
        : trim((string) ($purchase['install_note'] ?? ''));
    $what = jeab_gp_keep_text($what);
    $note = jeab_gp_keep_text($note);
    if ($what !== '' && $note !== '' && $what !== $note) {
        return $what . "\n\n" . $note;
    }

    return $what !== '' ? $what : $note;
}

function jeab_gp_reminder_text(?array $reminder, array $row): string
{
    $text = '';
    if (is_array($reminder) && function_exists('jeab_reminder_notes_text')) {
        $text = jeab_reminder_notes_text($reminder);
    }
    if ($text === '') {
        $text = trim((string) ($row['reminder_note'] ?? ''));
    }

    return jeab_gp_keep_text($text);
}

/** Hela kundtexten för sol, batteri, elbilsladdare och service. Skickar inget. */
function jeab_gp_customer_letter(array $row): string
{
    $row = jeab_gp_shown_booking($row);
    $job = function_exists('jeab_booking_job_type') ? jeab_booking_job_type($row) : trim((string) ($row['job_type'] ?? ''));
    try {
        $text = match ($job) {
            'laddbox' => function_exists('jeab_email_laddbox_text') ? jeab_email_laddbox_text($row) : '',
            'batteri' => function_exists('jeab_email_batteri_text') ? jeab_email_batteri_text($row) : '',
            'sol' => function_exists('jeab_email_sol_text') ? jeab_email_sol_text($row) : '',
            default => function_exists('jeab_email_text') ? jeab_email_text($row) : '',
        };
    } catch (Throwable) {
        $text = '';
    }

    return jeab_gp_keep_text($text);
}

function jeab_gp_join_blocks(array $parts): string
{
    $out = [];
    $seen = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $part;
    }

    return implode("\n\n", $out);
}

function jeab_gp_event_title(array $row): string
{
    $row = jeab_gp_shown_booking($row);
    $visit = jeab_gp_visit_address($row);
    if ($visit !== '') {
        $row['address'] = $visit;
    }
    $job = function_exists('jeab_job_types_label')
        ? jeab_job_types_label($row)
        : '';
    if ($job === '') {
        $key = (string) ($row['job_type'] ?? '');
        if ($key !== '' && isset(JEAB_JOB_TYPES[$key])) {
            $job = JEAB_JOB_TYPES[$key];
        }
    }
    $address = trim((string) ($row['address'] ?? ''));
    $customer = trim((string) ($row['customer_name'] ?? ''));
    $parts = array_values(array_filter([$job, $address, $customer], static fn(string $part): bool => $part !== ''));
    return $parts !== [] ? implode(', ', $parts) : 'JEAB';
}

function jeab_gp_event_location(array $row): string
{
    return jeab_gp_visit_address($row);
}

function jeab_gp_section_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = trim($text);
    $text = preg_replace("/\n{2,}/", "\n", $text) ?? $text;
    return trim($text);
}

function jeab_gp_note_sections(array $row): array
{
    $what = '';
    $install = '';
    $reminderNote = '';
    $purchase = null;
    if (function_exists('jeab_purchase_by_booking')) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $purchase = jeab_purchase_by_booking($id);
        }
    }
    if (!is_array($purchase) && (isset($row['install_what']) || isset($row['install_note']) || isset($row['kwh']) || isset($row['vaxelriktare']))) {
        $purchase = $row;
    }
    if (is_array($purchase)) {
        $what = function_exists('jeab_ordrar_install_what')
            ? jeab_ordrar_install_what($purchase)
            : trim((string) ($purchase['install_what'] ?? ''));
        $install = function_exists('jeab_ordrar_install_note')
            ? jeab_ordrar_install_note($purchase)
            : trim((string) ($purchase['install_note'] ?? ''));
    }
    $reminder = null;
    $store = null;
    if (function_exists('jeab_store')) {
        try {
            $store = jeab_store();
        } catch (Throwable) {
            $store = null;
        }
    }
    if ($store instanceof JeabStore) {
        if (is_array($purchase) && function_exists('jeab_get_reminder')) {
            $reminder = jeab_get_reminder($store, (int) ($purchase['reminder_id'] ?? 0));
        }
        if (!is_array($reminder)) {
            $reminder = $store->findOpenReminderByBooking((int) ($row['id'] ?? 0));
        }
    }
    if (is_array($reminder) && function_exists('jeab_reminder_notes_text')) {
        $reminderNote = jeab_reminder_notes_text($reminder);
    }
    if ($reminderNote === '') {
        $reminderNote = trim((string) ($row['reminder_note'] ?? $row['note'] ?? ''));
    }
    $what = jeab_gp_section_text($what);
    $install = jeab_gp_section_text($install);
    $reminderNote = jeab_gp_section_text($reminderNote);
    $sections = [];
    foreach ([$what, $install, $reminderNote] as $part) {
        if ($part !== '') {
            $sections[] = $part;
        }
    }
    if ($sections !== []) {
        return $sections;
    }
    $raw = function_exists('jeab_booking_site_notes')
        ? trim(jeab_booking_site_notes($row))
        : trim((string) ($row['notes'] ?? ''));
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    if ($raw === '') {
        return [];
    }
    foreach (preg_split("/\n{2,}/", $raw) ?: [] as $chunk) {
        $chunk = jeab_gp_section_text((string) $chunk);
        if ($chunk !== '') {
            $sections[] = $chunk;
        }
    }
    return $sections;
}

function jeab_gp_contact_section(array $row): string
{
    $row = jeab_gp_shown_booking($row);
    $phone = function_exists('jeab_normalize_booking_phone')
        ? (jeab_normalize_booking_phone($row['phone'] ?? null) ?? '')
        : trim((string) ($row['phone'] ?? ''));
    $lines = [];
    foreach ([
        trim((string) ($row['customer_name'] ?? '')),
        trim($phone),
        trim((string) ($row['email'] ?? '')),
        trim((string) ($row['address'] ?? '')),
    ] as $line) {
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return implode("\n", $lines);
}

function jeab_gp_event_description(array $row): string
{
    $shown = jeab_gp_shown_booking($row);
    $purchase = jeab_gp_booking_purchase($row);
    $reminder = jeab_gp_booking_reminder($row, $purchase);
    $sections = [];
    $address = jeab_gp_visit_address($row);
    if ($address !== '') {
        $sections[] = "Besöksadress\n" . $address;
    }
    $phone = jeab_gp_event_phone($row, $shown, $purchase, $reminder);
    if ($phone !== '') {
        $sections[] = "Telefon\n" . $phone;
    }
    $email = jeab_gp_event_email($row, $shown, $purchase, $reminder);
    if ($email !== '') {
        $sections[] = "E-post\n" . $email;
    }
    $install = jeab_gp_install_text($purchase);
    if ($install !== '') {
        $sections[] = "Installation\n" . $install;
    }
    $reminderText = jeab_gp_join_blocks([
        jeab_gp_reminder_text($reminder, $row),
        jeab_gp_customer_letter($shown),
    ]);
    if ($reminderText !== '') {
        $sections[] = "Påminnelse\n" . $reminderText;
    }

    return implode("\n\n", $sections);
}

/**
 * Bara plats och brödtext. Ingen rubrik, ingen tid, inga deltagare.
 *
 * @return array<string, mixed>
 */
function jeab_gp_event_detail_patch(array $row): array
{
    $patch = [];
    $loc = jeab_gp_event_location($row);
    if ($loc !== '') {
        $patch['location'] = ['displayName' => $loc];
    }
    $desc = jeab_gp_event_description($row);
    if ($desc !== '') {
        $patch['body'] = ['contentType' => 'Text', 'content' => $desc];
    }

    return $patch;
}

/** PATCH av plats och brödtext. sendUpdates=none. Rör inte tid eller deltagare. */
function jeab_gp_patch_event_details(string $email, string $eventId, array $row): bool
{
    $email = function_exists('jeab_normalize_person_email') ? (jeab_normalize_person_email($email) ?? '') : trim($email);
    $eventId = trim($eventId);
    if ($email === '' || $eventId === '') {
        return false;
    }
    $patch = jeab_gp_event_detail_patch($row);
    foreach (array_keys($patch) as $key) {
        if ($key !== 'location' && $key !== 'body') {
            return false;
        }
    }
    if ($patch === [] || isset($patch['subject']) || isset($patch['start']) || isset($patch['end']) || isset($patch['attendees'])) {
        return false;
    }
    $path = 'users/' . rawurlencode($email) . '/events/' . rawurlencode($eventId) . '?sendUpdates=none';
    $r = jeab_graph_api('PATCH', $path, $patch);
    if ((int) ($r['code'] ?? 0) === 200) {
        return true;
    }
    jeab_gp_log_graph('detaljer', $r, (int) ($row['id'] ?? 0));

    return false;
}

function jeab_gp_event_body(array $row): array
{
    $tz = jeab_gp_tz();
    $s = new DateTimeImmutable((string) $row['starts_at'], new DateTimeZone($tz));
    $e = new DateTimeImmutable((string) $row['ends_at'], new DateTimeZone($tz));
    $allDay = function_exists('jeab_booking_is_all_day') && jeab_booking_is_all_day((string) $row['starts_at'], (string) $row['ends_at']);
    $b = [
        'subject' => jeab_gp_event_title($row),
        'showAs' => 'busy',
        'isAllDay' => $allDay,
        'start' => ['dateTime' => $allDay ? $s->format('Y-m-d\T00:00:00') : $s->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
        'end' => ['dateTime' => $allDay ? $e->format('Y-m-d\T00:00:00') : $e->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
        'isReminderOn' => false,
        'attendees' => [],
    ];
    $loc = jeab_gp_event_location($row);
    if ($loc !== '') {
        $b['location'] = ['displayName' => $loc];
    }
    $desc = jeab_gp_event_description($row);
    if ($desc !== '') {
        $b['body'] = ['contentType' => 'Text', 'content' => $desc];
    }
    return $b;
}

/** Riktigt Graph-fel. Okonfigurerat och blockerad sidvisning är tysta. */
function jeab_gp_graph_failed(array $r): bool
{
    if (!empty($r['blocked'])) {
        return false;
    }
    $code = (int) ($r['code'] ?? 0);
    if (in_array($code, [200, 201, 204], true)) {
        return false;
    }
    $msg = (string) ($r['body']['error']['message'] ?? '');
    if ($code === 0 && $msg === 'Graph ej konfigurerat.') {
        return false;
    }
    return true;
}

function jeab_gp_log_graph(string $what, array $r, int $bookingId = 0): void
{
    if (!jeab_gp_graph_failed($r) || !function_exists('jeab_log_app_error')) {
        return;
    }
    $msg = trim(str_replace(["\r", "\n"], ' ', (string) ($r['body']['error']['message'] ?? '')));
    if (function_exists('jeab_log_mask_text')) {
        $msg = trim(jeab_log_mask_text($msg));
    }
    if (function_exists('mb_substr')) {
        $msg = mb_substr($msg, 0, 180, 'UTF-8');
    } else {
        $msg = substr($msg, 0, 180);
    }
    $line = 'Outlook ' . $what . ' graph ' . (int) ($r['code'] ?? 0);
    if ($msg !== '') {
        $line .= ': ' . $msg;
    }
    if ($bookingId > 0) {
        $line .= ' bokning ' . $bookingId;
    }
    if (function_exists('jeab_graph_http_note')) {
        $line .= jeab_graph_http_note($r);
    }
    jeab_log_app_error('calendar', $line);
}

function jeab_gp_upsert_event(string $email, array $row): bool
{
    if (function_exists('jeab_calendar_test_lead') && jeab_calendar_test_lead($row)) {
        if (function_exists('jeab_booking_stamp_test')) {
            jeab_booking_stamp_test($row);
        }

        return true;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_outlook_event_id')) {
        return false;
    }
    $email = jeab_normalize_person_email($email) ?? '';
    if ($email === '') {
        return false;
    }
    $resource = jeab_outlook_resource_for_email($row, $email);
    $eid = jeab_outlook_event_id($id, $email);
    $u = 'users/' . rawurlencode($email) . '/events';
    $body = jeab_gp_event_body($row);
    if ($body['attendees'] !== []) {
        return false;
    }
    if ($eid !== '') {
        $r = jeab_graph_api('PATCH', $u . '/' . rawurlencode($eid) . '?sendUpdates=none', $body);
        if ((int) $r['code'] === 200) {
            jeab_outlook_event_save($id, $resource, $email, $eid, 'busy');
            return true;
        }
        if ((int) $r['code'] !== 404) {
            jeab_gp_log_graph('händelse', $r, $id);
            if (jeab_gp_graph_failed($r)) {
                $GLOBALS['_JEAB_GP_SYNC_USER_ERROR'] = true;
            }
            return false;
        }
    }
    $r = jeab_graph_api('POST', $u . '?sendUpdates=none', $body);
    $new = (string) ($r['body']['id'] ?? '');
    if ((int) $r['code'] !== 201 || $new === '') {
        jeab_gp_log_graph('händelse', $r, $id);
        if (jeab_gp_graph_failed($r)) {
            $GLOBALS['_JEAB_GP_SYNC_USER_ERROR'] = true;
        }
        return false;
    }
    jeab_outlook_event_save($id, $resource, $email, $new, 'busy');
    return true;
}

function jeab_gp_delete_event(string $email, int $bookingId): void
{
    if ($bookingId <= 0 || !function_exists('jeab_outlook_event_id')) {
        return;
    }
    $email = jeab_normalize_person_email($email) ?? '';
    if ($email === '') {
        return;
    }
    $eid = jeab_outlook_event_id($bookingId, $email);
    if ($eid === '') {
        return;
    }
    $r = jeab_graph_api('DELETE', 'users/' . rawurlencode($email) . '/events/' . rawurlencode($eid));
    if (in_array((int) $r['code'], [204, 404], true)) {
        jeab_outlook_event_delete($bookingId, $email);
        return;
    }
    jeab_gp_log_graph('radering', $r, $bookingId);
}

/** Bokning → en händelse per resurs. $keepEmail lämnas orörd (Outlook-ändring som redan lästs). */
function jeab_gp_sync_booking(array $row, ?string $keepEmail = null): void
{
    if (function_exists('jeab_calendar_test_lead') && jeab_calendar_test_lead($row)) {
        if (function_exists('jeab_booking_stamp_test')) {
            jeab_booking_stamp_test($row);
        }

        return;
    }
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_outlook_events_for_booking')) {
        return;
    }
    $company = jeab_booking_company($row);
    $want = [];
    if (($row['status'] ?? '') !== 'cancelled') {
        foreach (jeab_assignees_from_row($row) as $name) {
            $e = jeab_gp_person_email((string) $name, $company);
            if ($e !== '') {
                $want[$e] = (string) $name;
            } elseif (trim((string) $name) !== '' && function_exists('jeab_log_app_error')) {
                jeab_log_app_error('calendar', 'Bokning ' . $id . ' har en resurs utan brevlåda.');
            }
        }
    }
    $existing = jeab_outlook_events_for_booking($id);
    foreach ($existing as $link) {
        $email = (string) ($link['email'] ?? '');
        if ($email !== '' && !isset($want[$email])) {
            jeab_gp_delete_event($email, $id);
        }
    }
    foreach ($want as $email => $resource) {
        unset($resource);
        if ($keepEmail !== null && strcasecmp((string) $email, $keepEmail) === 0) {
            continue;
        }
        jeab_gp_upsert_event((string) $email, $row);
    }
}

/**
 * Skickar ett väntande leadoffert-utkast i Inkorgen EN gång från produktägarens egen brevlåda,
 * med offert-PDF. Kräver graph_person_mail på. Mottagare = utkastets kundadress (aldrig plattformen).
 */
function jeab_gp_send_lead_draft(string $tid, string $draftId, string $author): array
{
    if (!jeab_gp_mail_on()) {
        return ['ok' => false, 'error' => 'Personavsändare är inte påslaget.'];
    }
    $t = null;
    if (function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql() && function_exists('jeab_inkorg_pdo_store')) {
        $store = jeab_inkorg_pdo_store();
        if ($store instanceof JeabPdoInkorgStore) {
            $meta = $store->threadsByIds([$tid])[$tid] ?? null;
            if (is_array($meta)) {
                $t = jeab_inkorg_thread_row($tid, $store->findMessagesByThread($tid), $meta);
            }
        }
    }
    if ($t === null) {
        foreach (jeab_inkorg_threads() as $x) {
            if ($x['id'] === $tid) {
                $t = $x;
            }
        }
    }
    if (!$t) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $company = jeab_inkorg_thread_company($t);
    $d = null;
    foreach ((array) $t['drafts'] as $x) {
        if (($x['id'] ?? '') === $draftId) {
            $d = $x;
        }
    }
    if (!$d || ($d['source'] ?? '') !== 'lead-offert') {
        return ['ok' => false, 'error' => 'Utkastet finns inte.'];
    }
    if (($d['state'] ?? '') !== 'pending') {
        return ['ok' => false, 'error' => 'Utkastet är redan ' . (string) ($d['state'] ?? '') . '.'];
    }
    $key = (string) ($d['quote_key'] ?? '');
    [$offer] = explode('_', $key . '_');
    $from = jeab_gp_owner_email($offer, $company);
    $to = (string) ($d['to'] ?? '');
    if ($from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || (function_exists('jeab_lead_platform') && jeab_lead_platform($to) !== '')) {
        return ['ok' => false, 'error' => 'Saknar avsändare eller kundadress.'];
    }
    $pdfBytes = function_exists('jeab_notiser_quote_pdf_bytes') ? jeab_notiser_quote_pdf_bytes($key) : null;
    $pdf = jeab_auto_quote_pdf_file($key);
    if (!is_string($pdfBytes) && !is_file($pdf)) {
        return ['ok' => false, 'error' => 'offert-PDF saknas'];
    }
    if (!is_string($pdfBytes)) {
        $pdfBytes = (string) file_get_contents($pdf);
    }
    $label = ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solceller med batteri'][$offer] ?? 'Offert';
    $text = (string) ($d['body'] ?? '');
    $isHtml = str_starts_with(ltrim($text), '<');
    $subject = $key === 'sol_jarfalla' ? 'Solceller med batteri' : 'Offert ' . $label . ' – Demo Energi Järfälla';
    $html = $isHtml ? $text : jeab_auto_quote_html($text, $company, (array) $t['first'], false);
    $atts = [['filename' => jeab_auto_pdf_name($key), 'content_type' => 'application/pdf', 'content' => $pdfBytes]];
    // Lås: markera som approved innan utskick så ett dubbelanrop inte skickar två gånger.
    $nonce = bin2hex(random_bytes(6));
    $locked = jeab_inkorg_thread_write($tid, function (array $x) use ($draftId, $nonce) {
        foreach ((array) ($x['drafts'] ?? []) as $i => $y) {
            if (($y['id'] ?? '') === $draftId && ($y['state'] ?? '') === 'pending') {
                $x['drafts'][$i]['state'] = 'approved';
                $x['_gp_lock'] = $nonce;
            }
        }
        return $x;
    });
    if ((string) ($locked['_gp_lock'] ?? '') !== $nonce) {
        return ['ok' => false, 'error' => 'Utkastet skickas redan.'];
    }
    $sent = jeab_gp_send($from, $to, $subject, $html, $atts);
    jeab_inkorg_thread_write($tid, function (array $x) use ($draftId, $sent, $from, $to, $author) {
        unset($x['_gp_lock']);
        foreach ((array) ($x['drafts'] ?? []) as $i => $y) {
            if (($y['id'] ?? '') === $draftId) {
                $x['drafts'][$i]['state'] = !empty($sent['ok']) ? 'sent' : 'pending';
                if (!empty($sent['ok'])) {
                    $x['drafts'][$i] += ['sent' => true, 'sent_at' => date('c'), 'from' => $from, 'via' => 'graph-person', 'approved_by' => $author];
                    $x['status'] = 'closed';
                }
            }
        }
        $x['updated_at'] = date('c');
        return $x;
    });
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => (string) ($sent['error'] ?? 'okänt')];
    }
    $fromUsed = trim((string) ($sent['from'] ?? ''));
    if ($fromUsed === '') {
        $fromUsed = $from;
    }
    if (function_exists('jeab_quote_send_commit')) {
        $actor = function_exists('jeab_inbox_sent_actor') ? jeab_inbox_sent_actor('manual', $author) : $author;
        $seed = [];
        $frozen = function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($t, 'offert');
        if (!$frozen && function_exists('jeab_offert_kundblock')) {
            $block = jeab_offert_kundblock($t);
            $seed = [
                'name' => (string) ($block['name'] ?? ''),
                'phone' => (string) ($block['phone'] ?? ''),
                'address' => (string) ($block['display'] ?? ''),
            ];
        }
        $party = function_exists('jeab_snapshots_quote_party') ? jeab_snapshots_quote_party($t, $to, $seed) : [];
        jeab_quote_send_commit($tid, $fromUsed, 'manual', $actor, $offer, hash('sha256', "draft\n" . $tid . "\n" . $draftId), 'offert', $party);
    }
    jeab_auto_note($tid, 'Offert (' . $label . ') skickad från ' . $fromUsed . ' till ' . $to . '.');
    if (function_exists('jeab_notiser_record_send') && function_exists('jeab_notiser_quote_notice_id')) {
        $variant = $key === 'sol_jarfalla' && function_exists('jeab_auto_sol_variant') ? jeab_auto_sol_variant((array) ($t['first'] ?? [])) : '';
        $split = $variant === 'kopt' || $variant === 'eget';
        jeab_notiser_record_send(jeab_notiser_quote_notice_id($offer, $company, $variant, $split), true, false, '');
    }
    return ['ok' => true, 'from' => $fromUsed, 'to' => $to, 'in_sent_items' => !empty($sent['in_sent_items'])];
}

/** Outlook-händelser i personalens kalendrar för Kalender-vyn. Egna bokningshändelser hoppas över. */
function jeab_gp_week_events(DateTimeImmutable $monday, ?string $whoFilter, ?DateTimeImmutable $until = null, ?string $company = null): array
{
    if ($company === null || $company === '') {
        $company = jeab_selected_company();
    } elseif (function_exists('jeab_normalize_company')) {
        $company = jeab_normalize_company($company) ?? jeab_selected_company();
    }
    $GLOBALS['_JEAB_GP_WEEK_OK'] = 0;
    $tz = new DateTimeZone(jeab_gp_tz());
    $from = $monday->modify('-1 day')->setTime(0, 0);
    $to = ($until ?? $monday->modify('+5 days'))->modify('+1 day')->setTime(0, 0);
    $weekStart = $monday->setTime(0, 0)->format('Y-m-d H:i:s');
    $weekEnd = ($until ?? $monday->modify('+5 days'))->setTime(0, 0)->format('Y-m-d H:i:s');
    $skip = function_exists('jeab_outlook_known_event_ids') ? jeab_outlook_known_event_ids() : [];
    $people = jeab_company_people($company);
    if (empty($GLOBALS['_JEAB_PDO_HOLD']) && function_exists('jeab_pdo_release_if_idle')) {
        jeab_pdo_release_if_idle();
    }
    $out = [];
    foreach ($people as $p) {
        $name = (string) ($p['name'] ?? '');
        if ($whoFilter !== null && $whoFilter !== '' && strcasecmp($name, $whoFilter) !== 0 && strcasecmp((string) ($p['id'] ?? ''), $whoFilter) !== 0) {
            continue;
        }
        $email = jeab_normalize_person_email($p['email'] ?? '');
        if ($email === null) {
            continue;
        }
        $q = 'users/' . rawurlencode($email) . '/calendarView?startDateTime=' . rawurlencode($from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
            . '&endDateTime=' . rawurlencode($to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'))
            . '&$top=200&$select=id,subject,start,end,isCancelled,isAllDay,showAs,categories';
        $r = jeab_graph_api('GET', $q, null, ['Prefer: outlook.timezone="UTC"']);
        if ((int) $r['code'] !== 200) {
            jeab_gp_log_graph('vecka', $r);
            continue;
        }
        $GLOBALS['_JEAB_GP_WEEK_OK'] = (int) ($GLOBALS['_JEAB_GP_WEEK_OK'] ?? 0) + 1;
        foreach ((array) ($r['body']['value'] ?? []) as $ev) {
            $id = (string) ($ev['id'] ?? '');
            if ($id === '' || !empty($ev['isCancelled']) || isset($skip[$id]) ) {
                continue;
            }
            try {
                $allDay = !empty($ev['isAllDay']);
                $s = new DateTimeImmutable((string) ($ev['start']['dateTime'] ?? ''), new DateTimeZone('UTC'));
                $e = new DateTimeImmutable((string) ($ev['end']['dateTime'] ?? ''), new DateTimeZone('UTC'));
                if (!$allDay) {
                    $s = $s->setTimezone($tz);
                    $e = $e->setTimezone($tz);
                }
            } catch (Throwable) {
                continue;
            }
            $ss = $s->format('Y-m-d H:i:s');
            $es = $e->format('Y-m-d H:i:s');
            if ($es <= $weekStart || $ss >= $weekEnd) {
                continue;
            }
            $title = trim((string) ($ev['subject'] ?? '')) ?: 'Outlook';
            $out[] = ['who' => $name, 'title' => $title, 'event_id' => $id, 'all_day' => $allDay, 'starts_at' => $ss, 'ends_at' => $es];
        }
    }
    return $out;
}

/** 90 sekunder cache runt veckans Outlook-händelser. */
function jeab_gp_week_events_cache_path(): string
{
    return jeab_data_dir() . '/graph-week-cache.json';
}

function jeab_gp_week_events_cache_forget(): void
{
    if (function_exists('jeab_log_graph_week_forget')) {
        jeab_log_graph_week_forget();
    }
    $path = jeab_gp_week_events_cache_path();
    if (is_file($path)) {
        @unlink($path);
    }
}

function jeab_gp_week_events_cache_key(DateTimeImmutable $monday, ?string $whoFilter, ?DateTimeImmutable $until = null): string
{
    return $monday->format('Y-m-d H:i:s') . '|' . (string) $whoFilter . '|' . ($until instanceof DateTimeImmutable ? $until->format('Y-m-d H:i:s') : '');
}

/** @return array{monday: DateTimeImmutable, who: ?string, until: ?DateTimeImmutable}|null */
function jeab_gp_week_events_key_parts(string $key): ?array
{
    $parts = explode('|', $key, 3);
    if (count($parts) < 2) {
        return null;
    }
    $monday = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $parts[0]);
    if (!$monday instanceof DateTimeImmutable) {
        return null;
    }
    $until = null;
    $untilRaw = (string) ($parts[2] ?? '');
    if ($untilRaw !== '') {
        $until = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $untilRaw);
        if (!$until instanceof DateTimeImmutable) {
            return null;
        }
    }
    $who = (string) ($parts[1] ?? '');

    return ['monday' => $monday, 'who' => $who === '' ? null : $who, 'until' => $until];
}

/** @return array{at: int, events: list<array<string, mixed>>}|null */
function jeab_gp_week_events_json_hit(string $key): ?array
{
    $path = jeab_gp_week_events_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $all = json_decode((string) file_get_contents($path), true);
    $hit = is_array($all) ? ($all[$key] ?? null) : null;
    if (!is_array($hit) || !is_array($hit['events'] ?? null)) {
        return null;
    }

    return ['at' => (int) ($hit['at'] ?? 0), 'events' => $hit['events']];
}

/**
 * Cachen som den är, även om den är äldre än 90 sekunder. Anropar inte Graph.
 * En SQL-rad vinner alltid över JSON-cachen. JSON används bara när SQL saknas.
 *
 * @return array{events: list<array<string, mixed>>, stale: bool, at: int}
 */
function jeab_gp_week_events_cache_read(DateTimeImmutable $monday, ?string $whoFilter, ?DateTimeImmutable $until = null): array
{
    $key = jeab_gp_week_events_cache_key($monday, $whoFilter, $until);
    $company = function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla';
    if (function_exists('jeab_log_graph_week_note')) {
        jeab_log_graph_week_note($key, $company);
    }
    $now = time();
    $sql = function_exists('jeab_log_graph_week_peek') ? jeab_log_graph_week_peek($key) : null;
    // Explicit sql-flagga: JSON-cachen läses inte alls (ingen reserv).
    $sqlOnly = function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', 'graph_week');
    $json = $sqlOnly ? null : jeab_gp_week_events_json_hit($key);
    if (is_array($sql)) {
        $sqlFresh = (int) ($sql['at'] ?? 0) > 0 && ($now - (int) $sql['at']) < 90;

        return ['events' => $sql['events'], 'stale' => !$sqlFresh, 'at' => (int) ($sql['at'] ?? 0)];
    }
    $jsonFresh = is_array($json) && (int) ($json['at'] ?? 0) > 0 && ($now - (int) $json['at']) < 90;
    if ($jsonFresh) {
        return ['events' => $json['events'], 'stale' => false, 'at' => (int) $json['at']];
    }
    if (is_array($json)) {
        return ['events' => $json['events'], 'stale' => true, 'at' => (int) ($json['at'] ?? 0)];
    }

    return ['events' => [], 'stale' => true, 'at' => 0];
}

function jeab_gp_week_events_cached(DateTimeImmutable $monday, ?string $whoFilter, ?DateTimeImmutable $until = null): array
{
    $read = jeab_gp_week_events_cache_read($monday, $whoFilter, $until);
    if (!$read['stale']) {
        return $read['events'];
    }
    if (function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
        return $read['events'];
    }
    if (function_exists('jeab_pdo_hold_push')) {
        jeab_pdo_hold_push();
    } else {
        $GLOBALS['_JEAB_PDO_HOLD'] = true;
    }
    try {
        $events = jeab_gp_week_events($monday, $whoFilter, $until);
        $key = jeab_gp_week_events_cache_key($monday, $whoFilter, $until);
        if (!(function_exists('jeab_log_graph_week_put') && jeab_log_graph_week_put($key, $events))) {
            if (function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', 'graph_week')) {
                return $events;
            }
            $path = jeab_gp_week_events_cache_path();
            $all = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $all = is_array($all) ? $all : [];
            $all[$key] = ['at' => time(), 'events' => $events];
            @file_put_contents($path, json_encode($all));
        }

        return $events;
    } finally {
        if (function_exists('jeab_pdo_hold_pop')) {
            jeab_pdo_hold_pop();
        } else {
            unset($GLOBALS['_JEAB_PDO_HOLD']);
        }
    }
}

/**
 * Tick fyller veckor som kalendern just bett om. Klicket själv anropar inte Graph.
 *
 * @return array{ok: bool, count: int, skipped?: string}
 */
function jeab_gp_week_refresh_due(int $limit, float $deadline): array
{
    $mocked = isset($GLOBALS['_JEAB_GRAPH_HTTP']) && is_callable($GLOBALS['_JEAB_GRAPH_HTTP']);
    if (!$mocked && (!function_exists('jeab_graph_configured') || !jeab_graph_configured())) {
        return ['ok' => true, 'count' => 0, 'skipped' => 'av'];
    }
    $due = function_exists('jeab_log_graph_week_due') ? jeab_log_graph_week_due($limit) : [];
    $done = 0;
    foreach ($due as $item) {
        if (microtime(true) >= $deadline) {
            break;
        }
        if (!is_array($item)) {
            continue;
        }
        $key = (string) ($item['key'] ?? '');
        $parts = jeab_gp_week_events_key_parts($key);
        if ($parts === null) {
            continue;
        }
        try {
            $events = jeab_gp_week_events($parts['monday'], $parts['who'], $parts['until'], (string) ($item['company'] ?? ''));
        } catch (Throwable $e) {
            error_log('calendar graph refresh ' . $e::class);
            continue;
        }
        if ((int) ($GLOBALS['_JEAB_GP_WEEK_OK'] ?? 0) < 1) {
            continue;
        }
        if (function_exists('jeab_log_graph_week_put') && jeab_log_graph_week_put($key, $events)) {
            $done++;
        }
    }

    return ['ok' => true, 'count' => $done];
}

/**
 * Upptaget i personens kalender. $who är namn eller e-post.
 * Saknas Graph-koppling blir listan tom.
 *
 * @return list<array{starts_at: string, ends_at: string}>
 */
function jeab_gp_busy_for_person(string $who, DateTimeImmutable $from, DateTimeImmutable $to, ?string $company = null): array
{
    $email = jeab_gp_person_email($who, $company);
    if ($email === '') {
        return [];
    }
    $mocked = isset($GLOBALS['_JEAB_GRAPH_HTTP']) && is_callable($GLOBALS['_JEAB_GRAPH_HTTP']);
    if (!$mocked && (!function_exists('jeab_graph_configured') || !jeab_graph_configured())) {
        return [];
    }
    return jeab_gp_busy_events($email, $from, $to);
}

