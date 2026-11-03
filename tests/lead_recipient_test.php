<?php

declare(strict_types=1);

/** Leadkälla: offert till kunden i texten, aldrig till avsändaren. Påhittade adresser. */

$tmp = sys_get_temp_dir() . '/jeab-lead-rcpt-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lr(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a) {
    $n = count($GLOBALS['_SENT']) + 1;
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-lr-' . $n, 'message_id' => 'sent-lr-' . $n . '@local'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () {
    $GLOBALS['_HTTP'] = ($GLOBALS['_HTTP'] ?? 0) + 1;
    return ['code' => 500, 'body' => []];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);

$domains = jeab_lead_source_domains();
lr($domains === jeab_lead_platforms(), 'plattformslistan är källistan');
lr(in_array('nordsolen.se', $domains, true) && in_array('weply.se', $domains, true) && in_array('brabyggfirmor.se', $domains, true) && in_array('leadkit.com', $domains, true) && in_array('hitta-solceller.se', $domains, true) && in_array('hemsol.se', $domains, true) && in_array('kalkyl.jarfalla.example.test', $domains, true), 'kända källor ligger i listan');
lr(array_search('drip.com', $domains, true) === array_search('getdrip.com', $domains, true) + 1, 'drip.com ligger intill getdrip.com');
lr(!in_array('wix.com', $domains, true) && jeab_lead_source_id('orders@wix.com') === '' && !jeab_lead_source_blocked('orders@wix.com'), 'butiksorder på wix är inte en leadkälla');
lr(jeab_lead_source_id('hej@nordsolen.se') === 'nordsolen' && jeab_lead_source_id('noreply@wix.com') === 'wix' && jeab_lead_source_id('no-reply@form.test') === 'noreply', 'nordsolen, noreply@wix och no-reply identifieras');
lr(jeab_lead_source_blocked('noreply@wix.com') && jeab_lead_source_blocked('no-reply@annat.test') && jeab_lead_source_blocked('info@wix-forms.com'), 'wix/noreply är blockerad mottagare');
lr(jeab_lead_seller_seed() === ['nordsolen.se', 'brabyggfirmor.se', 'leadkit.com', 'hitta-solceller.se', 'hittasolceller.se'], 'frölistan kommer från samma katalog');
lr(!jeab_lead_bought_partner('support@weply.se') && in_array('weply.se', jeab_lead_own_platforms(), true), 'Weply är eget lead, inte en köpt säljare');

$kund = jeab_lead_kund_line("Kund: Nora Exempel · nora.exempel@exempel.se · 070-000 00 21\n");
lr($kund['name'] === 'Nora Exempel' && $kund['email'] === 'nora.exempel@exempel.se' && $kund['phone'] === '070-000 00 21', 'Kund-raden läses');
$labeled = jeab_lead_customer(['from' => 'hej@nordsolen.se', 'body' => "Email: nora.exempel@exempel.se\nNamn: Nora Exempel\n"]);
lr($labeled['email'] === 'nora.exempel@exempel.se' && $labeled['name'] === 'Nora Exempel', 'Email-fältet läses');

$box = jeab_graph_mailboxes()['jarfalla'];
$labels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'product' => 'batteri', 'confidence' => 100, 'question' => ''];
$customer = 'nora.exempel@exempel.se';
$body = "Kund: Nora Exempel · {$customer} · 070-000 00 21\nAdress: Exempelvägen 1, 177 80 Järfälla\nVill ha batteri.\n";
$in = jeab_inkorg_ingest([
    'message_id' => 'nord@lead.test',
    'from' => 'hej@nordsolen.se',
    'from_name' => 'Nordsolen',
    'to' => $box,
    'subject' => 'Ny lead',
    'body_text' => $body,
    'source' => 'graph-jarfalla',
    'thread_key' => 'lead-nord',
    'graph_mailbox' => $box,
    'company' => 'jarfalla',
    'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=nordsolen.se'],
    'preset_labels' => $labels,
]);
lr(!empty($in['ok']), 'nordsolen-lead intaget');
$tid = (string) ($in['thread_id'] ?? '');
$meta = jeab_inkorg_thread_meta($tid);
lr((string) ($meta['customer_email'] ?? '') === $customer && (string) ($meta['lead_source'] ?? '') === 'nordsolen', 'tråden sparar kunden och källan');
$party = jeab_lead_sql();
lr($party instanceof PDO, 'SQL är tillgänglig');
$stored = $party->query('SELECT email, source_id FROM inbox_lead_party')->fetchAll();
lr(count($stored) === 1 && (string) ($stored[0]['email'] ?? '') === $customer && (string) ($stored[0]['source_id'] ?? '') === 'nordsolen', 'kunden ligger i SQL');
$before = count($GLOBALS['_SENT']);
$auto = jeab_auto_on_message((string) $in['id']);
$sent = $GLOBALS['_SENT'][$before] ?? [];
lr(($auto['action'] ?? '') === 'quoted' && ($sent['to'] ?? '') === $customer, 'autooffert går till kunden');
lr(!str_contains((string) ($sent['to'] ?? ''), 'nordsolen'), 'autooffert går inte till nordsolen');

$before = count($GLOBALS['_SENT']);
$none = jeab_inkorg_ingest([
    'message_id' => 'none@lead.test',
    'from' => 'hej@nordsolen.se',
    'from_name' => 'Nordsolen',
    'to' => $box,
    'subject' => 'Ny lead utan mejl',
    'body_text' => "Kund: Saknar Mejl\nVill ha batteri.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'lead-none',
    'graph_mailbox' => $box,
    'preset_labels' => $labels,
]);
$noneRes = jeab_auto_on_message((string) $none['id']);
lr(count($GLOBALS['_SENT']) === $before && ($noneRes['action'] ?? '') === 'hold' && str_contains((string) ($noneRes['why'] ?? ''), 'hittar ingen kundadress'), 'saknad kundmejl hålls');

$before = count($GLOBALS['_SENT']);
$plainCustomer = 'vanlig.kund@exempel.se';
$plain = jeab_inkorg_ingest([
    'message_id' => 'plain@lead.test',
    'from' => $plainCustomer,
    'from_name' => 'Vanlig Kund',
    'to' => $box,
    'subject' => 'Batteri',
    'body_text' => "Hej, jag vill ha ett batteri.\nE-post: annan.person@exempel.se\nAdress: Exempelvägen 2, 177 80 Järfälla\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'lead-plain',
    'graph_mailbox' => $box,
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'eget', 'product' => 'batteri', 'confidence' => 100, 'question' => ''],
]);
$plainRes = jeab_auto_on_message((string) $plain['id']);
$plainSent = $GLOBALS['_SENT'][$before] ?? [];
lr(($plainRes['action'] ?? '') === 'quoted' && ($plainSent['to'] ?? '') === $plainCustomer, 'vanlig kund får offerten till sin avsändaradress');

$httpBefore = (int) ($GLOBALS['_HTTP'] ?? 0);
$blocked = jeab_graph_send('jarfalla', 'noreply@wix.com', 'Offert', 'text');
$blockedSeller = jeab_graph_send('jarfalla', 'hej@nordsolen.se', 'Offert', 'text');
lr(empty($blocked['ok']) && ($blocked['error'] ?? '') === 'mottagaren är en leadkälla', 'noreply@wix stoppas');
lr(empty($blockedSeller['ok']) && str_contains((string) ($blockedSeller['error'] ?? ''), 'leadsäljare'), 'nordsolen stoppas');
lr((int) ($GLOBALS['_HTTP'] ?? 0) === $httpBefore, 'blocket anropar inte Graph');
$logs = $party->query('SELECT source_id, path, reason FROM lead_send_block')->fetchAll();
$encoded = json_encode($logs);
lr(is_string($encoded) && !str_contains($encoded, '@') && count($logs) >= 2, 'blockloggen saknar personuppgifter');

$resendIn = jeab_inkorg_ingest([
    'message_id' => 'resend@lead.test',
    'from' => 'hej@nordsolen.se',
    'from_name' => 'Nordsolen',
    'to' => $box,
    'subject' => 'Ny lead att skicka om',
    'body_text' => $body,
    'source' => 'graph-jarfalla',
    'thread_key' => 'lead-resend',
    'graph_mailbox' => $box,
    'preset_labels' => $labels,
]);
$resendTid = (string) ($resendIn['thread_id'] ?? '');
$bad = jeab_inkorg_ingest([
    'message_id' => 'bad-out@lead.test',
    'from' => 'saljare3@jarfalla.example.test',
    'from_name' => 'Roberto',
    'to' => 'hej@nordsolen.se',
    'subject' => 'Offert batteri',
    'body_text' => "Pris: 10 000 kr\n\n[Bilaga: Batteri-offert.pdf]",
    'source' => 'outbound-jarfalla',
    'thread_key' => 'lead-resend',
    'received_at' => date('c'),
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 100, 'source' => 'system', 'reason' => 'offert'],
]);
lr(!empty($bad['ok']), 'felutskick finns i tråden');
$log = jeab_inkorg_hook(['action' => 'inbox.sent_log', 'domain' => 'nordsolen.se', 'date' => date('Y-m-d')]);
lr(!empty($log['ok']) && count((array) ($log['rows'] ?? [])) >= 1, 'sent_log filtrerar domän och datum');
$mismatch = false;
foreach ((array) ($log['rows'] ?? []) as $row) {
    if (!empty($row['mismatch']) && ($row['pdf'][0] ?? '') === 'Batteri-offert.pdf' && str_contains((string) ($row['subject'] ?? ''), 'Offert')) {
        $mismatch = true;
    }
}
lr($mismatch, 'sent_log flaggar leadkälla som mismatch');
$goodLog = jeab_inkorg_hook(['action' => 'inbox.sent_log', 'domain' => 'example.test', 'mismatch' => 1]);
lr(!empty($goodLog['ok']), 'sent_log läser utan att skriva');

$preview = jeab_inkorg_hook(['action' => 'offer.resend', 'thread_id' => $resendTid]);
lr(!empty($preview['ok']) && (int) ($preview['dry'] ?? 0) === 1 && ($preview['recipient'] ?? '') === $customer && ($preview['pdf'] ?? '') === 'Batteri-offert.pdf' && in_array('Pris: 10 000 kr', (array) ($preview['prices'] ?? []), true) && (int) ($preview['sent'] ?? 1) === 0, 'resend dry visar mottagare, pdf och pris');

$inboxToken = jeab_inkorg_token_ensure();
$statusFile = $tmp . '/hook-status.txt';
$runner = $tmp . '/hook-runner.php';
$root = dirname(__DIR__);
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_LR_DATA') ?: '';
putenv('XAI_API_KEY');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$auth = getenv('JEAB_LR_AUTH') ?: '';
if ($auth === 'inbox') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (getenv('JEAB_LR_TOKEN') ?: '');
} elseif ($auth === 'incoming') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-the-inbox-token';
}
$GLOBALS['_JEAB_RAW_BODY'] = getenv('JEAB_LR_BODY') ?: '';
$statusFile = getenv('JEAB_LR_STATUS') ?: '';
register_shutdown_function(static function () use ($statusFile): void {
    if ($statusFile !== '') {
        file_put_contents($statusFile, (string) http_response_code());
    }
});
require (getenv('JEAB_LR_ROOT') ?: '') . '/hook.php';
PHP);
$dispatch = static function (array $payload, string $auth) use ($tmp, $inboxToken, $statusFile, $runner, $root): array {
    @unlink($statusFile);
    $cmd = 'JEAB_LR_DATA=' . escapeshellarg($tmp)
        . ' JEAB_LR_ROOT=' . escapeshellarg($root)
        . ' JEAB_LR_TOKEN=' . escapeshellarg($inboxToken)
        . ' JEAB_LR_BODY=' . escapeshellarg((string) json_encode($payload, JSON_UNESCAPED_UNICODE))
        . ' JEAB_LR_AUTH=' . escapeshellarg($auth)
        . ' JEAB_LR_STATUS=' . escapeshellarg($statusFile)
        . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($runner);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    lr(is_resource($proc), 'hook-processen startade');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $text = is_string($stdout) ? $stdout : '';
    lr(!str_contains($text, $inboxToken) && !str_contains((string) $stderr, $inboxToken), 'hook-svaret innehåller inte token');
    $json = json_decode($text, true);
    return [
        'status' => is_file($statusFile) ? (int) file_get_contents($statusFile) : 0,
        'json' => is_array($json) ? $json : [],
    ];
};
$dryHttp = $dispatch(['action' => 'offer.resend', 'thread_id' => $resendTid, 'dry' => 1], 'inbox');
lr($dryHttp['status'] === 200 && ($dryHttp['json']['ok'] ?? false) === true && (int) ($dryHttp['json']['dry'] ?? 0) === 1 && ($dryHttp['json']['recipient'] ?? '') === $customer && (int) ($dryHttp['json']['sent'] ?? 1) === 0 && ($dryHttp['json']['error'] ?? '') !== 'Fel token.', 'offer.resend dry=1 via hook.php med inkorgstoken');
$sentHttp = $dispatch(['action' => 'inbox.sent_log', 'domain' => 'nordsolen.se', 'date' => date('Y-m-d')], 'inbox');
lr($sentHttp['status'] === 200 && ($sentHttp['json']['ok'] ?? false) === true && ($sentHttp['json']['error'] ?? '') !== 'Fel token.', 'inbox.sent_log via hook.php med samma token');
$confirmHttp = $dispatch(['action' => 'offer.resend', 'thread_id' => $resendTid, 'dry' => 0], 'inbox');
lr($confirmHttp['status'] !== 401 && ($confirmHttp['json']['why'] ?? '') === 'confirm saknas' && ($confirmHttp['json']['error'] ?? '') !== 'Fel token.', 'dry=0 utan confirm kräver SAM');
$otherHttp = $dispatch(['action' => 'offer.resend', 'thread_id' => $resendTid, 'dry' => 1], 'incoming');
lr($otherHttp['status'] === 401 && ($otherHttp['json']['error'] ?? '') === 'Fel token.', 'inkommande webhook-token nekas för offer.resend');
$sentBefore = count($GLOBALS['_SENT']);
$noConfirm = jeab_inkorg_hook(['action' => 'offer.resend', 'thread_id' => $resendTid, 'dry' => 0]);
lr(empty($noConfirm['ok']) && ($noConfirm['why'] ?? '') === 'confirm saknas' && count($GLOBALS['_SENT']) === $sentBefore, 'resend utan confirm skickar inte');
$again = jeab_inkorg_hook(['action' => 'offer.resend', 'thread_id' => $resendTid, 'dry' => 0, 'confirm' => 'SAM']);
lr(!empty($again['ok']) && (int) ($again['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $sentBefore + 1 && ($GLOBALS['_SENT'][$sentBefore]['to'] ?? '') === $customer, 'resend går till kunden');
$resendRows = $party->query('SELECT thread_id, pdf_name, from_addr FROM lead_resend_log')->fetchAll();
$resendJson = json_encode($resendRows);
lr(is_string($resendJson) && !str_contains($resendJson, $customer) && ($resendRows[0]['pdf_name'] ?? '') === 'Batteri-offert.pdf', 'resend-loggen saknar kundens mejl');
$second = jeab_inkorg_hook(['action' => 'offer.resend', 'thread_id' => $resendTid, 'dry' => 0, 'confirm' => 'SAM']);
lr(empty($second['ok']) && ($second['why'] ?? '') === 'kunden har redan fått offerten' && count($GLOBALS['_SENT']) === $sentBefore + 1, 'andra resend stoppas');

$list = jeab_inkorg_hook(['action' => 'inbox.lead_recipients']);
$masked = '';
foreach ((array) ($list['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === $tid) {
        $masked = (string) ($row['suggested'] ?? '');
    }
}
lr((int) ($list['dry'] ?? 0) === 1 && $masked === 'n***@exempel.se' && !str_contains(json_encode($list['rows']), $customer), 'dry-listan maskerar kunden');
jeab_inkorg_thread_write($tid, static function (array $t): array {
    $t['customer_email'] = 'hej@nordsolen.se';
    return $t;
});
$fix = jeab_inkorg_hook(['action' => 'inbox.lead_recipients', 'dry' => 0]);
lr((int) ($fix['updated'] ?? 0) >= 1 && count($GLOBALS['_SENT']) === $sentBefore + 1, 'dry=0 uppdaterar utan utskick');
$fixedStmt = $party->prepare('SELECT email FROM inbox_lead_party WHERE thread_id = ?');
$fixedStmt->execute([$tid]);
$fixedEmail = (string) $fixedStmt->fetchColumn();
lr($fixedEmail === $customer, 'dry=0 skriver kunden i SQL');
lr((string) (jeab_inkorg_thread_meta($tid)['customer_email'] ?? '') === $customer, 'trådens mottagare är kunden igen');

echo "lead recipient ok\n";
