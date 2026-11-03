<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * One system-mail stack for company mail (invites, booking mail, automatic mail).
 * Help Scout API first when From is the company mailbox, then Graph. Never PHP mail().
 * Kims Malmö-offert (malmo_hs_kim / saljare.syd@malmo.example.test) använder samma helper:
 * Help Scout kan bara skicka som kontakt@, så den vägen hoppas över utan kundsvar,
 * och Graph skickar från kim@ i inkorgen.
 *
 * $opts['company'] selects From. Omitted company stays JEAB
 * (kontakt@jarfalla.example.test / Demo Energi Järfälla). malmo uses the catalog
 * domain and mail_name (kontakt@malmo.example.test / Demo Energi Malmö).
 */

function jeab_system_mail_company(mixed $company = null): string
{
    if (function_exists('jeab_normalize_company')) {
        return jeab_normalize_company($company) ?? 'jarfalla';
    }
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) $company), 'UTF-8')
        : strtolower(trim((string) $company));
    $raw = strtr($raw, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
    if (in_array($raw, ['malmo', 'meab'], true)) {
        return 'malmo';
    }
    return 'jarfalla';
}

/**
 * @return array{email: string, name: string}
 */
function jeab_system_mail_identity(?string $company = null): array
{
    $company = jeab_system_mail_company($company);
    $fallback = [
        'jarfalla' => ['email' => 'kontakt@jarfalla.example.test', 'name' => 'Demo Energi Järfälla'],
        'malmo' => ['email' => 'kontakt@malmo.example.test', 'name' => 'Demo Energi Malmö'],
    ];
    $email = $fallback[$company]['email'];
    $name = $fallback[$company]['name'];
    if (function_exists('jeab_company_catalog')) {
        $row = jeab_company_catalog()[$company] ?? null;
        if (is_array($row)) {
            $domain = trim((string) ($row['domain'] ?? ''));
            if ($domain !== '' && !str_contains($domain, '@') && !str_contains($domain, '/')) {
                $email = 'kontakt@' . $domain;
            }
            $mailName = trim((string) ($row['mail_name'] ?? ''));
            if ($mailName !== '') {
                $name = $mailName;
            }
        }
    }
    return ['email' => $email, 'name' => $name];
}

function jeab_system_mail_address(?string $company = null): string
{
    return jeab_system_mail_identity($company)['email'];
}

function jeab_system_mail_from_name(?string $company = null): string
{
    return jeab_system_mail_identity($company)['name'];
}

/**
 * Bolagens avsändaradresser. contact@ och kontakt@ på ett bolagsdomän är samma
 * avsändare (samma Help Scout-brevlåda). Malmö-bekräftelser sätter
 * contact@malmo.example.test och ska ändå gå via Help Scout Malmö.
 *
 * @return array<string, list<string>> bolag => domäner
 */
function jeab_system_mail_sender_domains(): array
{
    $out = [
        'jarfalla' => ['jarfalla.example.test'],
        'malmo' => ['malmo.example.test'],
    ];
    foreach (array_keys($out) as $company) {
        $domain = strtolower((string) substr(strrchr(jeab_system_mail_address($company), '@') ?: '', 1));
        if ($domain !== '' && !in_array($domain, $out[$company], true)) {
            $out[$company][] = $domain;
        }
    }
    return $out;
}

/** Lokala delar som räknas som bolagets egen avsändare. */
function jeab_system_mail_sender_locals(): array
{
    return ['kontakt', 'contact'];
}

/**
 * Normaliserad avsändare. «Namn <x@y>» och versaler tas bort. contact@/kontakt@
 * på ett bolagsdomän blir bolagets kanoniska adress (jeab_system_mail_address).
 * Andra adresser lämnas som de är (gemener).
 */
function jeab_system_mail_sender_key(string $email): string
{
    $email = trim($email);
    if (preg_match('/<([^>]+)>/', $email, $m)) {
        $email = trim($m[1]);
    }
    $email = strtolower($email);
    $at = strrpos($email, '@');
    if ($at === false) {
        return $email;
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if (!in_array($local, jeab_system_mail_sender_locals(), true)) {
        return $email;
    }
    foreach (jeab_system_mail_sender_domains() as $company => $domains) {
        if (in_array($domain, $domains, true)) {
            return strtolower(jeab_system_mail_address($company));
        }
    }
    return $email;
}

/** Är adressen bolagets egen avsändare (inklusive alias)? Tom adress räknas som ja. */
function jeab_system_mail_is_company_sender(string $company, string $email): bool
{
    if (trim($email) === '') {
        return true;
    }
    $company = jeab_system_mail_company($company);
    return jeab_system_mail_sender_key($email) === strtolower(jeab_system_mail_address($company));
}

/**
 * Help Scout eller inte, innan något skickas. Help Scout skickar alltid från
 * bolagets brevlåda, så en From som är bolagets egen adress eller alias
 * (contact@ = kontakt@) går via Help Scout. Bara en främmande From hoppar över.
 *
 * @param array<string, mixed> $opts
 * @return array{try: bool, error: string}
 */
function jeab_system_mail_hs_route(array $opts): array
{
    $company = jeab_system_mail_company($opts['company'] ?? null);
    $forcedFrom = trim((string) ($opts['from_email'] ?? ''));
    if (!jeab_system_mail_is_company_sender($company, $forcedFrom)) {
        return ['try' => false, 'error' => 'Bekräftelsen skickas från ' . strtolower($forcedFrom) . ', som inte är bolagets kontakt@-adress.'];
    }
    return ['try' => true, 'error' => 'Inkorgen svarade inte.'];
}

/**
 * From som faktiskt ska stå på mejlet. opts.from_email vinner över bolagets
 * standard (Malmö-bekräftelser använder contact@malmo.example.test).
 *
 * @param array<string, mixed> $opts
 * @return array{email: string, name: string}
 */
function jeab_system_mail_from_identity(array $opts): array
{
    $company = jeab_system_mail_company($opts['company'] ?? null);
    $email = trim((string) ($opts['from_email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $email = jeab_system_mail_address($company);
    }
    $name = trim((string) ($opts['from_name'] ?? ''));
    if ($name === '') {
        $name = jeab_system_mail_from_name($company);
    }
    return ['email' => $email, 'name' => $name];
}

/**
 * Graph från en känd personbrevlåda när Help Scout inte kan vara avsändare.
 * null = ingen person_from, anroparen tar den vanliga felvägen.
 *
 * @param array<string, mixed> $opts
 * @param list<array<string, mixed>> $attachments
 * @param array<string, mixed> $gate
 * @return array{ok: bool, path: string, error: ?string, id: string}|null
 */
function jeab_system_mail_person_fallback(array $opts, string $to, string $subject, string $text, array $attachments, array $gate): ?array
{
    $person = strtolower(trim((string) ($opts['person_from'] ?? '')));
    if ($person === '') {
        return null;
    }
    if (!function_exists('jeab_gp_send') || !function_exists('jeab_normalize_person_email') || !function_exists('jeab_gp_staff_emails')) {
        return jeab_system_mail_fail('Personens brevlåda saknas.', false);
    }
    $email = jeab_normalize_person_email($person) ?? '';
    $staff = jeab_gp_staff_emails();
    if ($email === '' || !isset($staff[$email])) {
        return jeab_system_mail_fail('Okänd personbrevlåda.', false);
    }
    $html = trim((string) ($opts['html'] ?? ''));
    if ($html === '') {
        $html = jeab_system_mail_html($text);
    }
    $name = trim((string) ($opts['from_name'] ?? ''));
    if ($name === '') {
        $name = (string) ($staff[$email]['name'] ?? '');
    }
    $sent = jeab_gp_send($email, $to, $subject, $html, $attachments, [], [
        'reply_to' => $email,
        'reply_to_name' => $name,
        'guard_template' => (string) ($opts['guard_template'] ?? 'email'),
        'guard_mode' => (string) ($opts['guard_mode'] ?? 'manual'),
        'guard_via' => (string) ($opts['guard_via'] ?? 'manual'),
        'guard_actor' => (string) ($opts['guard_actor'] ?? ''),
        'guard_body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
    ]);
    if (empty($sent['ok'])) {
        $held = jeab_system_mail_hold_blocked($opts, $to, $text, $sent);
        if ($held !== null) {
            return $held;
        }
        $why = trim((string) ($sent['error'] ?? ''));
        return jeab_system_mail_fail($why !== '' ? $why : 'Personens brevlåda avvisade mejlet.', false);
    }
    $out = [
        'ok' => true,
        'path' => 'graph',
        'error' => null,
        'id' => trim((string) ($sent['graph_id'] ?? $sent['message_id'] ?? 'graph')),
        'from' => $email,
        'to' => (string) ($sent['to'] ?? $to),
    ];
    return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
}

function jeab_system_mail_path_label(string $path): string
{
    return match ($path) {
        'helpscout' => 'Help Scout',
        'inkorg' => 'Inkorgen',
        'graph' => 'Graph',
        default => '',
    };
}

function jeab_system_mail_html(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div>' . nl2br($safe, false) . '</div>';
}

/**
 * @param list<array<string, mixed>> $attachments
 * @return list<array{filename: string, fileName: string, content_type: string, mimeType: string, content: string, data: string}>
 */
function jeab_system_mail_attachments(array $attachments): array
{
    $out = [];
    foreach ($attachments as $i => $att) {
        if (!is_array($att)) {
            continue;
        }
        $name = trim((string) ($att['filename'] ?? $att['fileName'] ?? $att['file_name'] ?? ''));
        if ($name === '') {
            $name = 'bilaga-' . ($i + 1);
        }
        $name = basename($name);
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'bilaga-' . ($i + 1);
        }
        $mime = trim((string) ($att['content_type'] ?? $att['mimeType'] ?? $att['mime_type'] ?? 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $bytes = '';
        $b64 = '';
        if (isset($att['content']) && is_string($att['content']) && $att['content'] !== '') {
            $bytes = $att['content'];
            $b64 = base64_encode($bytes);
        } else {
            $b64 = trim((string) ($att['data'] ?? $att['content_base64'] ?? ''));
            if (str_contains($b64, ',')) {
                $b64 = substr($b64, (int) strrpos($b64, ',') + 1);
            }
            $b64 = preg_replace('/\s+/', '', $b64) ?? '';
            $decoded = $b64 !== '' ? base64_decode($b64, true) : false;
            if ($b64 === '' || $decoded === false) {
                continue;
            }
            $bytes = $decoded;
        }
        if ($bytes === '') {
            continue;
        }
        $row = [
            'filename' => $name,
            'fileName' => $name,
            'content_type' => $mime,
            'mimeType' => $mime,
            'content' => $bytes,
            'data' => $b64,
        ];
        $cid = trim((string) ($att['content_id'] ?? $att['contentId'] ?? ''));
        if ($cid !== '' && !str_contains($cid, ' ') && !str_contains($cid, "\n")) {
            $row['content_id'] = $cid;
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * @param array<string, mixed> $opts
 * @return array{ok: bool, path: string, error: ?string, id: string}
 */
function jeab_system_mail_fail(string $error, bool $log = true): array
{
    $error = trim($error);
    if ($error === '') {
        $error = 'Mejlet kunde inte skickas.';
    }
    if ($log && function_exists('jeab_log_app_error')) {
        jeab_log_app_error('email', $error);
    }
    return ['ok' => false, 'path' => '', 'error' => $error, 'id' => ''];
}

/**
 * Ett Graph-sendMail från bolagets kontakt@. Ett försök. Ingen mail().
 *
 * @param array<string, mixed> $opts
 * @param list<array<string, mixed>> $attachments
 * @return array{ok: bool, path: string, error: ?string, id: string}
 */
function jeab_system_mail_graph(array $opts, string $html, array $attachments): array
{
    if (!function_exists('jeab_graph_api') || !function_exists('jeab_graph_mailboxes')) {
        return jeab_system_mail_fail('Graph-utskick saknas.', false);
    }
    $mocked = isset($GLOBALS['_JEAB_GRAPH_HTTP']) && is_callable($GLOBALS['_JEAB_GRAPH_HTTP']);
    if (!$mocked && (!function_exists('jeab_graph_configured') || !jeab_graph_configured())) {
        return jeab_system_mail_fail('Microsoft 365 är inte kopplat.', false);
    }
    $company = jeab_system_mail_company($opts['company'] ?? null);
    $box = (string) (jeab_graph_mailboxes()[$company] ?? '');
    if ($box === '') {
        return jeab_system_mail_fail('Graph-utskick saknas.', false);
    }
    $reply = jeab_system_mail_from_identity($opts);
    $to = (string) ($opts['to'] ?? '');
    $msg = [
        'subject' => (string) ($opts['subject'] ?? ''),
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => function_exists('jeab_graph_recips')
            ? jeab_graph_recips([$to])
            : [['emailAddress' => ['address' => $to]]],
        'from' => ['emailAddress' => ['name' => $reply['name'], 'address' => $box]],
        'replyTo' => [['emailAddress' => ['name' => $reply['name'], 'address' => $reply['email']]]],
    ];
    $files = [];
    if (function_exists('jeab_outbound_graph_attachments')) {
        $files = jeab_outbound_graph_attachments($attachments);
    } else {
        foreach ($attachments as $att) {
            if (!is_array($att)) {
                continue;
            }
            $b64 = trim((string) ($att['data'] ?? ''));
            if ($b64 === '' && isset($att['content']) && is_string($att['content']) && $att['content'] !== '') {
                $b64 = base64_encode($att['content']);
            }
            if ($b64 === '') {
                continue;
            }
            $files[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => (string) ($att['filename'] ?? $att['fileName'] ?? 'bilaga'),
                'contentType' => (string) ($att['content_type'] ?? $att['mimeType'] ?? 'application/octet-stream'),
                'contentBytes' => $b64,
            ];
        }
    }
    if ($files !== []) {
        $msg['attachments'] = $files;
    }
    $replyGraph = trim((string) ($opts['reply_graph_id'] ?? ''));
    if ($replyGraph !== '') {
        $replyMsg = $msg;
        unset($replyMsg['subject']);
        $comment = trim((string) ($opts['text'] ?? ''));
        if ($comment === '') {
            $comment = trim(strip_tags($html));
        }
        $replied = jeab_graph_api('POST', 'users/' . rawurlencode($box) . '/messages/' . rawurlencode($replyGraph) . '/reply', [
            'message' => $replyMsg,
            'comment' => $comment,
        ]);
        if ((int) ($replied['code'] ?? 0) === 202) {
            return jeab_system_mail_graph_result($box, $to, 202, $replyGraph, $replyGraph);
        }
    }
    $r = jeab_graph_api('POST', 'users/' . rawurlencode($box) . '/sendMail', [
        'message' => $msg,
        'saveToSentItems' => true,
    ]);
    $code = (int) ($r['code'] ?? 0);
    if ($code !== 202) {
        $detail = $code === 0 ? 'Microsoft 365 är inte kopplat.' : ('Graph svarade ' . $code . '.');
        $fail = jeab_system_mail_fail('Graph: ' . $detail, false);
        $fail['from'] = $box;
        $fail['to'] = $to;
        $fail['provider'] = jeab_system_mail_provider('graph', $code, $box, $to, '', false);
        if (!empty($r['blocked']) || ($code === 0 && function_exists('jeab_page_blocks_external') && jeab_page_blocks_external())) {
            $fail['blocked'] = true;
        }
        return $fail;
    }
    $id = trim((string) ($r['body']['id'] ?? ''));
    return jeab_system_mail_graph_result($box, $to, $code, $id !== '' ? $id : 'graph', '');
}

/**
 * @return array{name:string,code:int,from:string,to:string,id:string,ok:bool}
 */
function jeab_system_mail_provider(string $name, int $code, string $from, string $to, string $id, bool $ok): array
{
    return [
        'name' => $name,
        'code' => $code,
        'from' => $from,
        'to' => $to,
        'id' => $id,
        'ok' => $ok,
    ];
}

/**
 * @return array{ok:bool,path:string,error:null,id:string,from:string,to:string,provider:array{name:string,code:int,from:string,to:string,id:string,ok:bool},graph_id?:string}
 */
function jeab_system_mail_graph_result(string $from, string $to, int $code, string $id, string $graphId): array
{
    $out = [
        'ok' => true,
        'path' => 'graph',
        'error' => null,
        'id' => $id,
        'from' => $from,
        'to' => $to,
        'provider' => jeab_system_mail_provider('graph', $code, $from, $to, $id, true),
    ];
    if ($graphId !== '') {
        $out['graph_id'] = $graphId;
    }
    return $out;
}

/**
 * Send one system mail. Help Scout first. Graph only if Help Scout did not accept it.
 * A live company uses Inkorgen (Graph plus a closed case) and does not send a second time.
 *
 * $opts: to, subject, text, job_type?, tags?, attachments?, company?
 * company: jarfalla (default) or malmo. Selects From.
 * attachments: {filename|fileName, content_type|mimeType, content bytes | data base64}
 *
 * @param array<string, mixed> $opts
 * @return array{ok: bool, path: string, error: ?string, id: string}
 */
function jeab_system_mail_send(array $opts): array
{
    if (function_exists('jeab_demo_sent')) {
        $demoSent = jeab_demo_sent('email', (string) ($opts['to'] ?? ''));
        if ($demoSent !== null) {
            return $demoSent;
        }
    }
    if (function_exists('jeab_test_lead_take_mail')) {
        $mail = jeab_test_lead_take_mail((string) ($opts['to'] ?? ''), (array) ($opts['cc'] ?? []), $opts);
        if (!empty($mail['block_to'])) {
            return jeab_system_mail_fail('test lead', false);
        }
        $opts = $mail['opts'];
    }
    if (function_exists('jeab_lead_refuse_send')) {
        $why = jeab_lead_refuse_send((string) ($opts['to'] ?? ''), (array) ($opts['cc'] ?? []), 'system-mail');
        if ($why !== '') {
            return jeab_system_mail_fail($why, false);
        }
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return jeab_system_mail_fail('Omklassning skickar inget mejl.', false);
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('mail');
        return jeab_system_mail_fail('Mejl är avstängt under sammanslagning och import.', false);
    }
    $company = jeab_system_mail_company($opts['company'] ?? null);
    $opts['company'] = $company;
    if (isset($GLOBALS['JEAB_SYSTEM_MAIL']) && is_callable($GLOBALS['JEAB_SYSTEM_MAIL'])) {
        $mocked = ($GLOBALS['JEAB_SYSTEM_MAIL'])($opts);
        if (!is_array($mocked)) {
            return jeab_system_mail_fail('Systemmejlet svarade fel.');
        }
        $mockedOut = [
            'ok' => !empty($mocked['ok']),
            'path' => trim((string) ($mocked['path'] ?? '')),
            'error' => isset($mocked['error']) && is_string($mocked['error']) ? $mocked['error'] : null,
            'id' => trim((string) ($mocked['id'] ?? '')),
        ];
        if (!empty($mocked['queued'])) {
            $mockedOut['queued'] = true;
        }
        foreach (['thread_id', 'graph_id', 'from', 'to'] as $extra) {
            $value = trim((string) ($mocked[$extra] ?? ''));
            if ($value !== '') {
                $mockedOut[$extra] = $value;
            }
        }
        if (is_array($mocked['provider'] ?? null)) {
            $mockedOut['provider'] = $mocked['provider'];
        }
        return $mockedOut;
    }
    $to = trim((string) ($opts['to'] ?? ''));
    $subject = trim((string) ($opts['subject'] ?? ''));
    if ($subject === '') {
        $subject = 'Bokad tid';
    }
    $text = (string) ($opts['text'] ?? '');
    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return jeab_system_mail_fail('Mottagarens e-post är ogiltig.');
    }
    if (trim($text) === '' && trim((string) ($opts['html'] ?? '')) === '') {
        return jeab_system_mail_fail('Meddelandetext saknas.');
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $deliverCustomer = trim((string) ($opts['deliver_customer_email'] ?? ''));
        unset($opts['deliver_customer_email']);
        $guardContext = [
            'template' => (string) ($opts['guard_template'] ?? 'email'),
            'mode' => (string) ($opts['guard_mode'] ?? 'auto'),
            'via' => (string) ($opts['guard_via'] ?? ''),
            'actor' => (string) ($opts['guard_actor'] ?? ''),
            'body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
            'handler' => 'system_mail',
            'job' => ['opts' => $opts],
        ];
        if ($deliverCustomer !== '') {
            $guardContext['deliver_customer_email'] = $deliverCustomer;
        }
        $gate = jeab_send_guard_gate('email', $to, $text !== '' ? $text : (string) ($opts['html'] ?? ''), $guardContext);
        if (($gate['action'] ?? '') === 'stop') {
            $stopped = $gate['result'] ?? null;
            return is_array($stopped) ? $stopped : jeab_system_mail_fail('Automatiska utskick är stoppade.', false);
        }
    }
    try {
    $attachments = jeab_system_mail_attachments((array) ($opts['attachments'] ?? []));
    $fromIdentity = jeab_system_mail_from_identity($opts);
    // skip_helpscout: e-signering (fullmakt) går aldrig via Help Scout, bara inkorg/Graph till kontaktens mejl.
    $noHs = !empty($opts['skip_helpscout']);
    unset($opts['skip_helpscout']);
    if (!empty($opts['malmo_hs_kim']) || strcasecmp($fromIdentity['email'], 'saljare.syd@malmo.example.test') === 0) {
        // Help Scout API först. Den skickar alltid som kontakt@ och får inte bli ett kundsvar
        // i Malmös tråd. kim@ är inte den adressen, så vi går direkt till Graph. Aldrig PHP:s mail-funktion.
        $hsRoute = jeab_system_mail_hs_route($opts);
        if (!$noHs && !empty($hsRoute['try']) && function_exists('jeab_email_send')) {
            $hs = jeab_email_send($to, $text, $subject, trim((string) ($opts['job_type'] ?? '')) ?: null, $attachments, ['offert'], false, 'malmo');
            if (!empty($hs['ok'])) {
                $hsOut = ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => '', 'from' => $fromIdentity['email']];
                return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $hsOut) : $hsOut;
            }
        }
        if (!function_exists('jeab_outbound_graph_send')) {
            return jeab_system_mail_fail('Kims utskick saknar Graph.');
        }
        $htmlBody = trim((string) ($opts['html'] ?? ''));
        $sent = jeab_outbound_graph_send('malmo', $to, $subject, $htmlBody !== '' ? $htmlBody : $text, $htmlBody !== '', $attachments, [], [
            'person_email' => $fromIdentity['email'],
            'from_name' => $fromIdentity['name'] !== '' ? $fromIdentity['name'] : 'Kim, Demo Energi Malmö',
            'malmo_hs_kim' => true,
        ]);
        if (empty($sent['ok'])) {
            $held = jeab_system_mail_hold_blocked($opts, $to, $text, $sent);
            if ($held !== null) {
                return $held;
            }
            return jeab_system_mail_fail(trim((string) ($sent['error'] ?? '')) ?: 'Kims utskick misslyckades.');
        }
        if (function_exists('jeab_inbox_sent_record')) {
            $loggedFrom = trim((string) ($sent['from'] ?? ''));
            $systemExtra = [
                'from' => $loggedFrom !== '' ? $loggedFrom : (string) $fromIdentity['email'],
            ];
            if (function_exists('jeab_inbox_sent_mail_snapshot')) {
                $systemExtra['snapshot'] = jeab_inbox_sent_mail_snapshot($to, $subject, $htmlBody !== '' ? $htmlBody : $text);
            }
            jeab_inbox_sent_record(trim((string) ($opts['thread_id'] ?? '')), 'auto', 'system', 'system', $systemExtra);
        }
        $inkorgOut = [
            'ok' => true,
            'path' => 'inkorg',
            'error' => null,
            'id' => trim((string) ($sent['message_id'] ?? $sent['graph_id'] ?? '')),
            'from' => $fromIdentity['email'],
        ];
        return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $inkorgOut) : $inkorgOut;
    }
    $tags = [];
    foreach ((array) ($opts['tags'] ?? ['kalender']) as $tag) {
        $tag = trim((string) $tag);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }
    if ($tags === []) {
        $tags = ['kalender'];
    }
    $job = trim((string) ($opts['job_type'] ?? ''));
    // Help Scout skapar ett stängt samtal och svarar 201 utan att skicka.
    // Malmö-svar saknar user, och brevlådan är inte kontakt@. deliver_to ska
    // lämna Graph sendMail från kontakt@malmo.example.test och ta med svaret.
    if (!empty($opts['deliver_direct']) && $company === 'malmo') {
        unset($opts['deliver_direct']);
        $opts['to'] = $to;
        $opts['subject'] = $subject;
        $graph = jeab_system_mail_graph($opts, jeab_system_mail_html($text), $attachments);
        $held = jeab_system_mail_hold_blocked($opts, $to, $text, $graph);
        if ($held !== null) {
            return $held;
        }
        return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $graph) : $graph;
    }
    $route = jeab_system_mail_hs_route($opts);
    if (!$route['try']) {
        // Help Scout kan bara skicka som kontakt@. En personlig From (person_from)
        // prövas därför inte där — den skulle gå ut som bolagets brevlåda — och
        // Graph skickar i stället från personens egen brevlåda.
        $personSent = jeab_system_mail_person_fallback($opts, $to, $subject, $text, $attachments, $gate);
        if ($personSent !== null) {
            return $personSent;
        }
        return jeab_system_mail_fail($route['error']);
    }
    $hsError = $route['error'];
    $notLive = function_exists('jeab_inkorg_company_live') && !jeab_inkorg_company_live($company);
    $skipHs = $notLive
        && $company === 'malmo'
        && (!function_exists('jeab_helpscout_mailbox_id') || jeab_helpscout_mailbox_id('malmo') === null);
    if ($noHs) {
        $hsError = 'Mejlet kunde inte skickas via inkorgen/Graph.';
    } elseif ($skipHs) {
        $hsError = 'Ingen Help Scout-brevlåda för kontakt@malmo.example.test.';
    } elseif (function_exists('jeab_email_send')) {
        $replyId = trim((string) ($opts['hs_conversation_id'] ?? ''));
        if ($replyId !== '' && function_exists('jeab_email_reply_conversation')) {
            $replied = jeab_email_reply_conversation($replyId, $to, $text, $company);
            if (!empty($replied['ok'])) {
                $hsOut = ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => $replyId];
                return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $hsOut) : $hsOut;
            }
        }
        $hs = jeab_email_send($to, $text, $subject, $job !== '' ? $job : null, $attachments, $tags, false, $company);
        if (!empty($hs['ok'])) {
            $hsOut = ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => trim((string) ($hs['id'] ?? ''))];
            return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $hsOut) : $hsOut;
        }
        $hsError = 'Help Scout: ' . (trim((string) ($hs['error'] ?? '')) ?: 'avvisade mejlet.');
    }
    $opts['to'] = $to;
    $opts['subject'] = $subject;
    $live = function_exists('jeab_inkorg_company_live') && jeab_inkorg_company_live($company);
    if ($live && function_exists('jeab_outbound_system_mail')) {
        $out = jeab_outbound_system_mail(array_merge($opts, ['to' => $to, 'subject' => $subject, 'tags' => $tags, 'job_type' => $job]), $text, $attachments);
        if (!empty($out['ok'])) {
            return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $out) : $out;
        }
        $held = jeab_system_mail_hold_blocked($opts, $to, $text, $out);
        if ($held !== null) {
            return $held;
        }
        $outError = trim((string) ($out['error'] ?? '')) ?: 'Inkorgen avvisade mejlet.';
        $both = $hsError;
        if ($outError !== '' && $outError !== $hsError) {
            $both .= '. ' . $outError;
        }
        return jeab_system_mail_fail($both);
    }
    $graph = jeab_system_mail_graph($opts, jeab_system_mail_html($text), $attachments);
    if (!empty($graph['ok'])) {
        return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $graph) : $graph;
    }
    $held = jeab_system_mail_hold_blocked($opts, $to, $text, $graph);
    if ($held !== null) {
        return $held;
    }
    $graphError = trim((string) ($graph['error'] ?? ''));
    $both = $hsError;
    if ($graphError !== '' && $graphError !== $hsError) {
        $both .= '. ' . $graphError;
    }
    return jeab_system_mail_fail($both);
    } finally {
        if (function_exists('jeab_send_guard_release')) {
            jeab_send_guard_release($gate);
        }
    }
}

/**
 * Sidan blockerade Graph (http 0, blocked=1). Kön tar emot mejlet.
 * Själva anropet skickar inte och betar inte av.
 *
 * @param array<string, mixed> $opts
 * @param array<string, mixed> $result
 * @return array<string, mixed>|null
 */
function jeab_system_mail_blocked_send(array $result): bool
{
    if (!empty($result['blocked'])) {
        return true;
    }
    if (!function_exists('jeab_page_blocks_external') || !jeab_page_blocks_external()) {
        return false;
    }
    $error = trim((string) ($result['error'] ?? ''));

    return $error === 'graph 0' || str_ends_with($error, 'graph 0');
}

/**
 * @param array<string, mixed> $opts
 * @param array<string, mixed> $result
 * @return array<string, mixed>|null
 */
function jeab_system_mail_hold_blocked(array $opts, string $to, string $text, array $result): ?array
{
    if (!jeab_system_mail_blocked_send($result) || !function_exists('jeab_send_guard_queue_blocked')) {
        return null;
    }
    if (trim((string) ($opts['to'] ?? '')) === '') {
        $opts['to'] = $to;
    }
    if (trim((string) ($opts['text'] ?? '')) === '' && $text !== '') {
        $opts['text'] = $text;
    }
    $queued = jeab_send_guard_queue_blocked($opts, $to, $text);

    return is_array($queued) ? $queued : null;
}

/** «an***@example.com». Hela adressen visas aldrig i diagnosen. */
function jeab_system_mail_mask(string $email): string
{
    $email = trim($email);
    $at = strrpos($email, '@');
    if ($at === false) {
        return $email === '' ? '' : '***';
    }
    $local = substr($email, 0, $at);
    return substr($local, 0, min(2, strlen($local))) . '***' . substr($email, $at);
}

/**
 * Läsdiagnos för bekräftelsemejl från Malmö (contact@). Bara GET, bara inloggad,
 * skickar ingenting och ändrar inget. Visar Help Scout-brevlådor (id, inga
 * nycklar), Malmö-bokningar som får contact@-bekräftelse och deras läge, och
 * Fellogg-rader där Help Scout hoppades över för en contact@-From.
 *
 * @return list<array<string, string>>
 */
function jeab_system_mail_diag_rows(?string $nowSql = null): array
{
    $nowSql = $nowSql ?? (function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'));
    $rows = [];
    $store = jeab_store();
    $all = method_exists($store, 'listAllBookings') ? $store->listAllBookings() : [];
    $sent = function_exists('jeab_sms_sent_store') ? jeab_sms_sent_store() : [];
    foreach ($all as $row) {
        if (!is_array($row) || jeab_booking_confirm_mail_from($row) === null) {
            continue;
        }
        if ((string) ($row['created_at'] ?? '') < '2026-09-25' && (string) ($row['updated_at'] ?? '') < '2026-09-25') {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $done = $sent[(string) $id] ?? [];
        $shown = function_exists('jeab_contacts_sole_recipient_apply') ? jeab_contacts_sole_recipient_apply($row) : $row;
        $email = trim((string) ($shown['email'] ?? ''));
        if (($row['status'] ?? 'booked') === 'cancelled') {
            $state = 'avbokad';
        } elseif (function_exists('jeab_sol_calendar_customer_mail_blocked') && jeab_sol_calendar_customer_mail_blocked($row)) {
            $state = 'avstängt (solceller)';
        } elseif (is_array($done) && !empty($done['email'])) {
            $state = 'skickat ' . (string) ($done['email_at'] ?? '');
        } elseif ($email === '' || !str_contains($email, '@')) {
            $state = 'ingen e-post';
        } elseif (!jeab_booking_mail_age_ready($row, $nowSql)) {
            $state = 'väntar (yngre än 2 h)';
        } elseif (jeab_booking_mail_start_clearly_past($row, $nowSql)) {
            $state = 'TAPPAD (start passerad, aldrig skickad)';
        } else {
            $state = 'I KÖ (går vid nästa tick)';
        }
        $job = jeab_booking_job_type($row);
        $subject = match ($job) {
            'laddbox' => jeab_email_laddbox_subject($row),
            'batteri' => jeab_email_batteri_subject($row),
            'sol' => jeab_email_sol_subject($row),
            default => 'Bokad tid',
        };
        $rows[] = [
            'id' => (string) $id,
            'state' => $state,
            'job' => $job,
            'address' => trim((string) ($shown['address'] ?? '')),
            'name' => trim((string) ($shown['customer_name'] ?? '')),
            'to' => jeab_system_mail_mask($email),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'subject' => (string) $subject,
            'route' => jeab_system_mail_hs_route(['company' => 'malmo', 'from_email' => jeab_meab_confirm_mail_from()])['try'] ? 'helpscout' : 'graph',
        ];
    }
    usort($rows, static fn (array $a, array $b): int => strcmp($b['starts_at'], $a['starts_at']));
    return $rows;
}

function jeab_system_mail_diag_page(): void
{
    if (strtolower((string) ($_GET['p'] ?? '')) !== 'maildiag' || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><meta charset="utf-8"><title>maildiag</title><style>body{font:12px system-ui;margin:12px}td,th{border:1px solid #ccc;padding:2px 4px;text-align:left;vertical-align:top}</style>';
    $cfg = function_exists('jeab_helpscout_config') ? jeab_helpscout_config() : [];
    $keys = [];
    foreach (['mailbox_id', 'mailbox_id_malmo', 'meab_mailbox_id', 'mailbox_malmo', 'mailboxes'] as $k) {
        if (array_key_exists($k, $cfg)) {
            $keys[] = $k;
        }
    }
    $malmo = jeab_helpscout_mailbox_id('malmo');
    echo '<h2>Help Scout</h2><ul id="hs">';
    echo '<li>nyckel finns: ' . (jeab_helpscout_token() !== null ? 'ja' : 'nej') . '</li>';
    echo '<li>config-nycklar: ' . e(implode(', ', $keys)) . '</li>';
    echo '<li>brevlåda jarfalla: ' . e((string) jeab_helpscout_mailbox_id('jarfalla')) . '</li>';
    echo '<li>brevlåda malmo: <b id="mbmalmo">' . e($malmo === null ? 'SAKNAS' : (string) $malmo) . '</b></li>';
    if (($_GET['hs'] ?? '') === '1' && $malmo !== null && jeab_helpscout_token() !== null) {
        if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('helpscout', 'mailboxes')) {
            echo '<li>Help Scout API malmo: demo</li>';
        } else {
        $ch = curl_init('https://api.helpscout.net/v2/mailboxes/' . $malmo);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . jeab_helpscout_token(), 'Accept: application/json'],
            CURLOPT_TIMEOUT => 15,
        ]);
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $box = is_string($raw) ? json_decode($raw, true) : null;
        echo '<li>Help Scout API malmo: HTTP ' . $code . ' namn=' . e((string) ($box['name'] ?? '')) . ' e-post=<b id="mbemail">' . e((string) ($box['email'] ?? '')) . '</b></li>';
        }
    }
    $route = jeab_system_mail_hs_route(['company' => 'malmo', 'from_email' => jeab_meab_confirm_mail_from()]);
    echo '<li>Malmö contact@ går via: <b id="route">' . ($route['try'] ? 'Help Scout' : 'Graph (' . e($route['error']) . ')') . '</b></li></ul>';

    echo '<h2>Malmö-bekräftelser från contact@ (sedan 2026-09-25)</h2><table id="book"><tr>';
    $cols = ['id', 'state', 'job', 'address', 'name', 'to', 'starts_at', 'created_at', 'updated_at', 'subject', 'route'];
    foreach ($cols as $c) {
        echo '<th>' . e($c) . '</th>';
    }
    echo '</tr>';
    foreach (jeab_system_mail_diag_rows() as $r) {
        echo '<tr>';
        foreach ($cols as $c) {
            echo '<td>' . e($r[$c] ?? '') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table><h2>Fellogg: Help Scout hoppades över för contact@ / Graph-fel på e-post</h2><table id="errs"><tr><th>time</th><th>path</th><th>message</th></tr>';
    $path = function_exists('jeab_app_errors_path') ? jeab_app_errors_path() : '';
    if ($path !== '' && is_file($path)) {
        foreach ((array) @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode((string) $line, true);
            if (!is_array($r)) {
                continue;
            }
            $msg = (string) ($r['message'] ?? '');
            if (!str_contains($msg, 'skickas från') && !str_contains($msg, 'missing scope')) {
                continue;
            }
            echo '<tr><td>' . e((string) ($r['time'] ?? '')) . '</td><td>' . e((string) ($r['path'] ?? '')) . '</td><td>' . e($msg) . '</td></tr>';
        }
    }
    echo '</table>';
    exit;
}
