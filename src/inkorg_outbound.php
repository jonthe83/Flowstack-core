<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Utgående systemmejl via Inkorgen (ersätter Help Scout som skrivväg, 2026-10-15).
 *
 * Det Help Scout gjorde «bakom kulisserna» (bokningsbekräftelser, fullmakter, hämtning,
 * offerter) skickas nu via Microsoft Graph från bolagets kontakt@-adress och sparas
 * som ett ärende i Inkorgen. Som Help Scouts «skapa konversation, status closed»:
 * ärendet stängs direkt och öppnas igen om kunden svarar (samma Graph-konversation).
 *
 * Help Scout skrivs aldrig till härifrån.
 */

/** Graph-bilagor (fileAttachment, base64) från systemmejlets bilageformat. */
function jeab_outbound_graph_attachments(array $attachments): array
{
    $out = [];
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
        $row = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => (string) ($att['filename'] ?? $att['fileName'] ?? 'bilaga'),
            'contentType' => (string) ($att['content_type'] ?? $att['mimeType'] ?? 'application/octet-stream'),
            'contentBytes' => $b64,
        ];
        if (!empty($att['content_id'])) {
            $row['isInline'] = true;
            $row['contentId'] = (string) $att['content_id'];
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Skickar ett nytt mejl från bolagets kontakt@ via Graph sendMail och slår upp
 * det skickade mejlet i Skickat (conversationId, Message-ID). Oberoende av Inkorgens Skicka-brytare (systemmejl gick
 * alltid förut också).
 *
 * @return array{ok: bool, error?: string, from?: string, graph_id?: string, conversation_id?: string, message_id?: string}
 */
/** Tom sträng = skicka. Annars orsaken. Gäller offert, kort svar och frågan om okänd produkt. */
function jeab_outbound_seller_blocked(string $to, array $cc, array $opts): string
{
    if (empty($opts['guard_seller']) || !function_exists('jeab_auto_quote_seller_guard')) {
        return '';
    }
    $msg = is_array($opts['seller_msg'] ?? null) ? $opts['seller_msg'] : [];
    $why = jeab_auto_quote_seller_guard($msg, $to);
    if ($why !== '') {
        return $why;
    }
    foreach ($cc as $addr) {
        $why = jeab_auto_quote_seller_guard($msg, (string) $addr);
        if ($why !== '') {
            return $why;
        }
    }
    return '';
}

function jeab_outbound_graph_send(string $company, string $to, string $subject, string $body, bool $html = false, array $attachments = [], array $cc = [], array $opts = []): array
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
        $why = jeab_lead_refuse_send($to, $cc, 'outbound');
        if ($why !== '') {
            return ['ok' => false, 'error' => $why];
        }
    }
    if (function_exists('jeab_inkorg_send_held') && jeab_inkorg_send_held()) {
        return ['ok' => false, 'error' => 'omklassning skickar inte'];
    }
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('quote');
        return ['ok' => false, 'error' => 'Offert är avstängd under sammanslagning och import.'];
    }
    $blocked = jeab_outbound_seller_blocked($to, $cc, $opts);
    if ($blocked !== '') {
        return ['ok' => false, 'error' => $blocked];
    }
    if (isset($GLOBALS['JEAB_GRAPH_SEND']) && is_callable($GLOBALS['JEAB_GRAPH_SEND'])) {
        return ($GLOBALS['JEAB_GRAPH_SEND'])(compact('company', 'to', 'subject', 'body', 'html', 'attachments', 'cc', 'opts'));
    }
    if (!function_exists('jeab_graph_configured') || !jeab_graph_configured()) {
        return ['ok' => false, 'error' => 'Microsoft 365 är inte kopplat.'];
    }
    $kimSend = $company === 'malmo' && !empty($opts['malmo_hs_kim']);
    if (function_exists('jeab_inkorg_company_live') && !jeab_inkorg_company_live($company) && !$kimSend) {
        return ['ok' => false, 'error' => 'Bolaget hanteras i Help Scout.'];
    }
    $box = jeab_graph_mailboxes()[$company] ?? '';
    if (($box === '' && !$kimSend) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Fel mottagare eller bolag.'];
    }
    $gate = ['action' => 'send', 'inner' => true, 'manual_pending' => false, 'ticket' => ''];
    if (function_exists('send_guard_check')) {
        $gate = jeab_send_guard_gate('email', $to, $body, [
            'template' => (string) ($opts['guard_template'] ?? 'email'),
            'mode' => (string) ($opts['guard_mode'] ?? 'auto'),
            'via' => (string) ($opts['guard_via'] ?? ''),
            'actor' => (string) ($opts['guard_actor'] ?? ''),
            'body_hash' => (string) ($opts['guard_body_hash'] ?? ''),
            'handler' => 'outbound',
            'job' => [
                'company' => $company,
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
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
    // Personavsändare (graph_person_mail): nytt mejl från produktägarens egen brevlåda.
    // answer_only svarar i samma konversation när Graph-id eller Message-ID finns.
    // Kims Malmö-ärende går bara från Kims brevlåda, aldrig från kontakt@.
    $person = trim((string) ($opts['person_email'] ?? ''));
    if ($kimSend) {
        if ($person === '' || !function_exists('jeab_gp_mail_on') || !jeab_gp_mail_on()) {
            return ['ok' => false, 'error' => 'Kims brevlåda är inte påslagen.'];
        }
        $gpOpts = [
            'strip_cc' => !empty($opts['strip_cc']),
            'guard_seller' => !empty($opts['guard_seller']),
            'seller_msg' => is_array($opts['seller_msg'] ?? null) ? $opts['seller_msg'] : [],
        ];
        if (!empty($opts['answer_only'])) {
            $gpOpts += [
                'reply_to_graph_id' => (string) ($opts['reply_to_graph_id'] ?? ''),
                'in_reply_to' => (string) ($opts['in_reply_to'] ?? ''),
                'keep_subject' => !empty($opts['keep_subject']),
            ];
        }
        $gpSent = jeab_gp_send($person, $to, $subject, $html ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')), $attachments, $cc, $gpOpts);
        return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $gpSent) : $gpSent;
    }
    if ($person !== '' && function_exists('jeab_gp_mail_on') && jeab_gp_mail_on()) {
        $gpOpts = [
            'strip_cc' => !empty($opts['strip_cc']),
            'guard_seller' => !empty($opts['guard_seller']),
            'seller_msg' => is_array($opts['seller_msg'] ?? null) ? $opts['seller_msg'] : [],
        ];
        if (!empty($opts['answer_only'])) {
            $gpOpts += [
                'reply_to_graph_id' => (string) ($opts['reply_to_graph_id'] ?? ''),
                'in_reply_to' => (string) ($opts['in_reply_to'] ?? ''),
                'keep_subject' => !empty($opts['keep_subject']),
            ];
        }
        $gpSent = jeab_gp_send($person, $to, $subject, $html ? $body : nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')), $attachments, $cc, $gpOpts);
        return function_exists('jeab_send_guard_mark') ? jeab_send_guard_mark($gate, $gpSent) : $gpSent;
    }
    $msg = [
        'subject' => $subject,
        'body' => ['contentType' => $html ? 'HTML' : 'Text', 'content' => $body],
        'toRecipients' => jeab_graph_recips([$to]),
    ];
    if ($cc || !empty($opts['strip_cc'])) {
        // Tom lista när strip_cc: Graph-reply behåller annars trådens Cc.
        $msg['ccRecipients'] = jeab_graph_recips($cc);
    }
    $files = jeab_outbound_graph_attachments($attachments);
    if ($files) {
        $msg['attachments'] = $files;
    }
    $fromName = trim((string) ($opts['from_name'] ?? ''));
    if ($fromName !== '') {
        // Personens namn på bolagets kontakt@ («Elin, Demo Energi Järfälla»).
        $msg['from'] = ['emailAddress' => ['name' => $fromName, 'address' => $box]];
    }
    $ref = bin2hex(random_bytes(8));
    $msg['internetMessageHeaders'] = [['name' => 'X-JEAB-Ref', 'value' => $ref]];
    $replyId = trim((string) ($opts['reply_to_graph_id'] ?? ''));
    $irt = trim((string) ($opts['in_reply_to'] ?? ''), '<> ');
    $r = ['code' => 0];
    $replied = false;
    if ($replyId !== '') {
        // Svar i kundens tråd (Graph reply, Mail.Send räcker). Ämnet sätts av Graph (SV: …) om det inte ska behållas.
        $rm = $msg;
        if (empty($opts['keep_subject'])) {
            unset($rm['subject']);
        }
        $r = jeab_graph_api('POST', 'users/' . rawurlencode($box) . '/messages/' . rawurlencode($replyId) . '/reply', ['message' => $rm]);
        if ((int) ($r['code'] ?? 0) === 202) {
            $replied = true;
        } elseif (jeab_graph_reply_uncertain($r)) {
            $fail = jeab_graph_reply_uncertain_fail($r);

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
        $r = jeab_graph_api('POST', 'users/' . rawurlencode($box) . '/sendMail', ['message' => $msg, 'saveToSentItems' => true]);
        if ((int) ($r['code'] ?? 0) !== 202) {
            $fail = ['ok' => false, 'error' => 'graph ' . $r['code']];
            if (!empty($r['blocked'])) {
                $fail['blocked'] = true;
            }

            return $fail;
        }
    }
    $out = ['ok' => true, 'from' => $box, 'graph_id' => '', 'conversation_id' => '', 'message_id' => '', 'ref' => $ref, 'replied' => !empty($replied)];
    // Hitta det skickade mejlet i Skickat (Mail.Read räcker) för conversationId, så kundens svar
    // hamnar i samma ärende. Saknas det blir svaret ett nytt ärende, inget går förlorat.
    $q = 'users/' . rawurlencode($box) . '/mailFolders/sentitems/messages?$top=15&$orderby=sentDateTime%20desc&$select=id,subject,conversationId,internetMessageId,internetMessageHeaders';
    for ($i = 0; $i < 4; $i++) {
        if ($i > 0) {
            usleep(1500000);
        }
        $list = jeab_graph_api('GET', $q);
        foreach ((array) ($list['body']['value'] ?? []) as $m) {
            foreach ((array) ($m['internetMessageHeaders'] ?? []) as $h) {
                if (strcasecmp((string) ($h['name'] ?? ''), 'X-JEAB-Ref') === 0 && trim((string) ($h['value'] ?? '')) === $ref) {
                    $out['graph_id'] = (string) ($m['id'] ?? '');
                    $out['conversation_id'] = (string) ($m['conversationId'] ?? '');
                    $out['message_id'] = trim((string) ($m['internetMessageId'] ?? ''), '<> ');
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

/** Help Scout-taggar och jobbtyp → Inkorgens etiketter (bara etiketter som finns). */
function jeab_outbound_tag_ids(array $tags, string $job): array
{
    $names = array_map('strval', $tags);
    $map = (array) (jeab_inkorg_settings()['product_tags'] ?? []);
    $prod = ['laddbox' => 'elbilsladdare', 'batteri' => 'batteri', 'sol' => 'solceller'][$job] ?? '';
    $ids = jeab_inkorg_tag_ids_from_names($names);
    if ($prod !== '' && !empty($map[$prod])) {
        $ids[] = (string) $map[$prod];
    }
    return array_values(array_unique($ids));
}

/**
 * Sparar ett skickat systemmejl som ärende i Inkorgen. Status efter: Stängd (som Help Scout).
 * Graph-konversationen kopplas till ärendet, så kundens svar hamnar i samma ärende och öppnar det.
 */
function jeab_outbound_record(string $company, array $sent, string $to, string $subject, string $text, array $opts = []): string
{
    $box = (string) ($sent['from'] ?? jeab_inkorg_sender($company));
    $mid = (string) ($sent['message_id'] ?? '');
    if ($mid === '') {
        $mid = 'out-' . bin2hex(random_bytes(8)) . '@flowstack.example.test';
    }
    $tid = (string) ($opts['thread_id'] ?? '');
    if ($tid === '') {
        $conv = (string) ($sent['conversation_id'] ?? '');
        $tid = 'o-' . substr(hash('sha256', $conv !== '' ? $conv : $mid), 0, 16);
    }
    $kind = (string) ($opts['kind'] ?? 'systemmejl');
    $cc = $opts['cc'] ?? [];
    $ccText = is_array($cc) ? implode(', ', array_map('strval', $cc)) : (string) $cc;
    $res = jeab_inkorg_ingest([
        'message_id' => $mid,
        'from' => $box,
        'from_name' => (string) ($opts['from_name'] ?? jeab_system_mail_from_name($company)),
        'to' => $to,
        'cc' => $ccText,
        'subject' => $subject,
        'received_at' => date('c'),
        'body_text' => $text,
        'source' => 'outbound-' . $company,
        'thread_key' => $tid,
        'graph_id' => (string) ($sent['graph_id'] ?? ''),
        'graph_conversation_id' => (string) ($sent['conversation_id'] ?? ''),
        'graph_mailbox' => $box,
        'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 100,
            'source' => 'system', 'reason' => 'Skickat av flowstack.example.test (' . $kind . ')', 'add_tags' => [], 'needs_review' => false],
    ]);
    if (!empty($res['ok']) && empty($res['duplicate'])) {
        $tid = (string) ($res['thread_id'] ?? $tid);
        $tags = jeab_outbound_tag_ids((array) ($opts['tags'] ?? []), (string) ($opts['job_type'] ?? ''));
        $close = !array_key_exists('status_after', $opts) || $opts['status_after'] === 'closed';
        jeab_inkorg_thread_write($tid, function (array $t) use ($tags, $kind, $close, $opts) {
            $t['tags'] = array_values(array_unique(array_merge((array) ($t['tags'] ?? []), $tags)));
            $t['status'] = $close ? 'closed' : (string) ($opts['status_after'] ?? 'open');
            $t['outbound'] = $kind;
            $t['system_tags'] = array_values(array_map('strval', (array) ($opts['tags'] ?? [])));
            $t['updated_at'] = date('c');
            return $t;
        });
        $conv = (string) ($sent['conversation_id'] ?? '');
        if ($conv !== '' && function_exists('jeab_graph_state_update')) {
            jeab_graph_state_update(function (array &$d) use ($conv, $tid) {
                $d['conv'][$conv] = $tid;
                return true;
            });
        }
    }
    return $tid;
}

/**
 * Systemmejl via Inkorgen: Graph från kontakt@ + ärende (stängt).
 *
 * @return array{ok: bool, path: string, error: ?string, id: string, thread_id?: string, graph_id?: string}
 */
function jeab_outbound_system_mail(array $opts, string $text, array $attachments): array
{
    $company = jeab_system_mail_company($opts['company'] ?? null);
    $to = (string) $opts['to'];
    $subject = (string) $opts['subject'];
    $graphOpts = [];
    $replyGraph = trim((string) ($opts['reply_graph_id'] ?? ''));
    if ($replyGraph !== '') {
        $graphOpts['reply_to_graph_id'] = $replyGraph;
    }
    $irt = trim((string) ($opts['in_reply_to'] ?? ''));
    if ($irt !== '') {
        $graphOpts['in_reply_to'] = $irt;
    }
    $sent = jeab_outbound_graph_send($company, $to, $subject, $text, false, $attachments, [], $graphOpts);
    if (empty($sent['ok'])) {
        $out = ['ok' => false, 'path' => '', 'error' => 'Inkorgen: ' . (string) ($sent['error'] ?? 'kunde inte skicka'), 'id' => ''];
        if (!empty($sent['blocked'])) {
            $out['blocked'] = true;
        }

        return $out;
    }
    $tid = trim((string) ($opts['thread_id'] ?? ''));
    try {
        $saved = jeab_outbound_record($company, $sent, $to, $subject, $text . ($attachments ? "\n\n[Bilagor: " . implode(', ', array_map(fn($a) => (string) ($a['filename'] ?? 'bilaga'), $attachments)) . ']' : ''), [
            'tags' => (array) ($opts['tags'] ?? []), 'job_type' => (string) ($opts['job_type'] ?? ''),
            'kind' => (string) (($opts['tags'][0] ?? '') ?: 'systemmejl'),
            'thread_id' => trim((string) ($opts['thread_id'] ?? '')),
        ]);
        if ($saved !== '') {
            $tid = $saved;
        }
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('inkorg', 'Systemmejl skickat men inte sparat i Inkorgen: ' . $e->getMessage());
        }
    }
    if (function_exists('jeab_inbox_sent_record')) {
        $ref = trim((string) ($sent['ref'] ?? $sent['message_id'] ?? $sent['graph_id'] ?? ''));
        $systemExtra = [
            'from' => trim((string) ($sent['from'] ?? '')),
            'idem' => 'system|' . $tid . '|' . $ref,
        ];
        if (function_exists('jeab_inbox_sent_mail_snapshot')) {
            $systemExtra['snapshot'] = jeab_inbox_sent_mail_snapshot($to, $subject, $text);
        }
        jeab_inbox_sent_record($tid, 'auto', 'system', 'system', $systemExtra);
    }
    return [
        'ok' => true,
        'path' => 'inkorg',
        'error' => null,
        'id' => $tid,
        'thread_id' => $tid,
        'graph_id' => (string) ($sent['graph_id'] ?? ''),
    ];
}
