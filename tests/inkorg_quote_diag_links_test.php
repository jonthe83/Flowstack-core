<?php

declare(strict_types=1);

/** Diagnos ur leadtext, mail-rader och samma brevlåda i Skickat. Påhittade adresser. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-quote-diag-links-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qdl(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): string => json_encode(['answer' => ''], JSON_UNESCAPED_UNICODE);
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => json_encode([
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
    'intent' => 'kop', 'offer' => '', 'product' => '', 'question' => '',
]);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_diag_' . bin2hex(random_bytes(4))]));
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['batteri_jarfalla', 'sol_jarfalla', 'laddbox_jarfalla'] as $key) {
    jeab_auto_pdf_save(['key' => $key, 'data' => base64_encode('%PDF-1.4 ' . $key)]);
}

$cust = 'ulf.kund.sq@gmail.com';
$tagged = 'Ulf.Kund.Sq+Weply@gmail.com';
$wrapped = [
    'toRecipients' => [[
        'emailAddress' => ['name' => 'Ulf Kund <' . $tagged . '>', 'address' => ''],
    ]],
    'ccRecipients' => [],
    'subject' => 'Sv: Laddbox beställning',
    'sentDateTime' => '2026-10-16T08:00:00Z',
];
qdl(jeab_auto_mailbox_key($tagged) === $cust, 'plustag och versaler blir samma brevlåda');
qdl(jeab_auto_mailbox_key('Ulf Kund <' . $tagged . '>') === $cust, 'visningsnamn med adress i vinkelparentes blir samma brevlåda');
qdl(jeab_auto_graph_hits_rcpt($wrapped, $cust, true) === true, 'Skickat träffar svar där adressen bara står i visningsnamnet');
qdl(jeab_auto_graph_hits_rcpt([
    'toRecipients' => [['emailAddress' => ['name' => 'Ulf Kund', 'address' => $tagged]]],
], 'ULF.KUND.SQ@gmail.com', true) === true, 'To med plustag och annan versalisering träffar');
qdl(jeab_auto_graph_hits_rcpt([
    'ccRecipients' => [['emailAddress' => ['address' => 'Ulf.Kund.Sq@gmail.com']]],
], $cust, true) === true, 'Cc med annan versalisering träffar');
qdl(jeab_auto_graph_hits_rcpt([
    'toRecipients' => [['emailAddress' => ['address' => 'annan.kund.sq@gmail.com']]],
    'subject' => 'Sv: Laddbox beställning',
], $cust, true) === false, 'ämnet Sv räcker inte när mottagaren är en annan');
qdl(jeab_auto_graph_hits_rcpt([
    'replyTo' => [['emailAddress' => ['address' => $cust]]],
    'bccRecipients' => [['emailAddress' => ['address' => $cust]]],
], $cust, true) === false, 'varken replyTo eller bcc räknas som To eller Cc');
$searchPath = rawurldecode(jeab_auto_quote_sent_search_path('saljare4@jarfalla.example.test', 'to', $tagged));
qdl(str_contains($searchPath, '"to:' . $cust . '"') && !str_contains($searchPath, '+') && str_contains($searchPath, 'mailFolders/sentitems') && !str_contains($searchPath, 'nylas'), 'Skickat söks via Graph, inte via en annan leverantör');

$searchUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$searchUrls, $tagged): array {
    $decoded = rawurldecode($url);
    $searchUrls[] = $decoded;
    if (str_contains($decoded, 'mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-elin']];
    }
    if (str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => ['value' => [[
            'toRecipients' => [['emailAddress' => ['name' => 'Ulf Kund', 'address' => $tagged]]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-16T08:00:00Z',
            'parentFolderId' => 'sent-elin',
        ]]]];
    }
    return ['code' => 500, 'body' => ['error' => 'scan ska inte köras']];
};
$searchHit = jeab_auto_quote_sent_graph_search('saljare4@jarfalla.example.test', $cust);
qdl(($searchHit['hit'] ?? false) === true && ($searchHit['date'] ?? '') === '2026-10-16 10:00' && ($searchHit['why'] ?? '') !== '', '$search-träff med plustag i To hålls');

$scanUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$scanUrls, $tagged): array {
    $decoded = rawurldecode($url);
    $scanUrls[] = $decoded;
    if (str_contains($decoded, 'mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-elin']];
    }
    if (str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    if (str_contains($decoded, 'sentDateTime')) {
        return ['code' => 200, 'body' => ['value' => [[
            'subject' => 'Sv: Laddbox beställning',
            'toRecipients' => [[
                'emailAddress' => ['name' => 'Ulf Kund <' . $tagged . '>', 'address' => ''],
            ]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-16T08:00:00Z',
        ]]]];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
$both = jeab_auto_quote_sent_graph_sources('saljare4@jarfalla.example.test', $cust, false);
$scanRow = is_array($both['graph_scan'] ?? null) ? $both['graph_scan'] : [];
qdl(($both['graph_search']['hit'] ?? true) === false && ($scanRow['hit'] ?? false) === true && ($scanRow['date'] ?? '') === '2026-10-16 10:00', 'datumskanningen hittar svaret när $search inte gör det');
$askedBare = false;
foreach ($scanUrls as $url) {
    if (str_contains($url, '$search=') && str_contains($url, '"to:' . $cust . '"') && !str_contains($url, '+weply')) {
        $askedBare = true;
    }
}
qdl($askedBare, '$search till den bara adressen får tomt svar och skanningen tar vid');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $from, string $body, array $extra = []) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@diag.test',
        'from' => $from,
        'from_name' => (string) ($extra['from_name'] ?? 'Formulär JEAB'),
        'to' => $box,
        'subject' => (string) ($extra['subject'] ?? 'Laddbox beställning'),
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => (string) ($extra['thread_key'] ?? ('diag-' . $id)),
        'preset_labels' => array_merge([
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => '', 'product' => '', 'question' => '',
        ], (array) ($extra['labels'] ?? [])),
    ]);
};
$wixBody = "Namn: Ulf Kund\nE-post: {$cust}\nTelefon: 070-100 20 30\nAdress: Testvägen 9, 176 70 Järfälla\nMeddelande:\nJag vill beställa en elbilsladdare.\n";
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
$wix = $ingest('wix-empty', 'info@wix-forms.com', $wixBody);
$wixTid = (string) $wix['thread_id'];
$wixThread = jeab_auto_thread_by_id($wixTid);
$resolved = jeab_auto_quote_offer_person(is_array($wixThread) ? $wixThread : [], (array) ($wixThread['first'] ?? []));
qdl(($resolved['offer'] ?? '') === 'laddbox' && ($resolved['person'] ?? '') === 'saljare4@jarfalla.example.test', 'tom produktetikett på Wix blir elbilsladdare och Elin');
$before = count($GLOBALS['_SENT']);
$dryQuote = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $wixTid, 'dry' => 1]);
qdl(($dryQuote['ok'] ?? false) === true && ($dryQuote['sender'] ?? '') === 'saljare4@jarfalla.example.test' && ($dryQuote['template'] ?? '') === 'Elbilsladdare' && count($GLOBALS['_SENT']) === $before, 'send_quote läser samma produkt och avsändare');

$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($tagged): array {
    $decoded = rawurldecode($url);
    if (str_contains($decoded, 'mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-elin']];
    }
    if (str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    if (str_contains($decoded, 'sentDateTime')) {
        return ['code' => 200, 'body' => ['value' => [[
            'subject' => 'Sv: Laddbox beställning',
            'toRecipients' => [[
                'emailAddress' => ['name' => 'Ulf Kund <' . $tagged . '>', 'address' => ''],
            ]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-16T08:00:00Z',
        ]]]];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
jeab_inkorg_views();
$waiting = null;
foreach (jeab_inkorg_views() as $v) {
    if (($v['id'] ?? '') === 'v-vantar-offert') {
        $waiting = $v;
    }
}
$inView = is_array($wixThread) && is_array($waiting) && jeab_inkorg_thread_matches($wixThread, (array) ($waiting['filter'] ?? []));
qdl($inView, 'Wix-leadet ligger i Väntar på offert');
$recheck = jeab_inkorg_hook(['action' => 'inbox.waiting_recheck']);
$recheckJson = (string) json_encode($recheck, JSON_UNESCAPED_UNICODE);
$recheckHit = false;
foreach ((array) ($recheck['hits'] ?? []) as $hit) {
    if (($hit['thread'] ?? '') === $wixTid && ($hit['source'] ?? '') === 'Skickat' && ($hit['date'] ?? '') === '2026-10-16 10:00') {
        $recheckHit = true;
    }
}
qdl(($recheck['dry'] ?? 0) === 1 && $recheckHit && !str_contains($recheckJson, '@'), 'omkollen använder Elins Skickat och skriver ingen adress');
$diag = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $wixTid]);
$diagJson = (string) json_encode($diag, JSON_UNESCAPED_UNICODE);
$src = is_array($diag['sources'] ?? null) ? $diag['sources'] : [];
qdl(($diag['ok'] ?? false) === true && ($src['graph_search']['status'] ?? '') === 'ok' && ($src['graph_search']['hit'] ?? true) === false && ($src['graph_scan']['hit'] ?? false) === true && ($src['graph_scan']['date'] ?? '') === '2026-10-16 10:00' && !isset($src['nylas']) && !str_contains($diagJson, '@') && !str_contains($diagJson, 'Sv:'), 'diagnosen kollar Elins Skickat trots tom produktetikett');

$real = jeab_create_reminder(jeab_store(), 'Vera Kund – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Vera Kund', 'email' => 'vera.kund.sq@gmail.com', 'company' => 'jarfalla', 'who' => 'Elin', 'note' => 'Ring kunden.',
]);
$realThread = $ingest('vera', 'vera.kund.sq@gmail.com', "Namn: Vera Kund\nE-post: vera.kund.sq@gmail.com\nTelefon: 070-100 20 31\nAdress: Veravägen 1, 176 70 Järfälla\nMeddelande:\nEn laddbox till.\n", [
    'from_name' => 'Vera Kund',
    'thread_key' => 'vera-real',
    'labels' => ['offer' => 'laddbox', 'product' => 'laddbox'],
]);
$autoThread = $ingest('auto', 'vera.kund.sq@gmail.com', "Namn: Vera Kund\nE-post: vera.kund.sq@gmail.com\n", [
    'from_name' => 'Vera Kund',
    'thread_key' => 'autotest-vera',
    'labels' => ['offer' => 'laddbox', 'product' => 'laddbox'],
]);
$testNamed = $ingest('named', 'test@test.se', "Namn: Test Kund\nE-post: test@test.se\n", [
    'from_name' => 'Test Kund',
    'thread_key' => 'test-namn-1',
    'labels' => ['offer' => 'laddbox', 'product' => 'laddbox'],
]);
$done = jeab_create_reminder(jeab_store(), 'Nora Klar – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Nora Klar', 'email' => 'nora.klar.sq@gmail.com', 'company' => 'jarfalla', 'who' => 'Elin', 'note' => 'Redan klar.',
]);
jeab_toggle_reminder(jeab_store(), (int) $done['id'], true);
$ingest('nora', 'nora.klar.sq@gmail.com', "Namn: Nora Klar\nE-post: nora.klar.sq@gmail.com\n", [
    'from_name' => 'Nora Klar',
    'thread_key' => 'nora-real',
    'labels' => ['offer' => 'laddbox', 'product' => 'laddbox'],
]);
$testRem = jeab_create_reminder(jeab_store(), 'TEST Ombokning', jeab_today_start(), null, null, [
    'customer' => 'Test Ombokning', 'email' => 'test.ombok.sq@gmail.com', 'company' => 'jarfalla', 'who' => 'Elin', 'note' => 'Testpåminnelse.',
]);
$ingest('ombok', 'test.ombok.sq@gmail.com', "Namn: Test Ombokning\nE-post: test.ombok.sq@gmail.com\n", [
    'from_name' => 'Test Ombokning',
    'thread_key' => 'ombok-1',
]);
$self = jeab_create_reminder(jeab_store(), 'Egen kontroll', jeab_today_start(), null, null, [
    'customer' => 'Sam', 'email' => 'demo@example.test', 'company' => 'jarfalla', 'who' => 'Elin', 'note' => 'Självtest.',
]);
for ($i = 1; $i <= 3; $i++) {
    $ingest('self-' . $i, 'demo@example.test', "Namn: Sam\nE-post: demo@example.test\n", [
        'from_name' => 'Sam',
        'thread_key' => 'self-' . $i,
    ]);
}
$apply = jeab_inkorg_hook(['action' => 'inbox.mail_links', 'dry' => 0]);
$applyJson = (string) json_encode($apply, JSON_UNESCAPED_UNICODE);
$realAfter = jeab_get_reminder(jeab_store(), (int) $real['id']);
$realUrl = 'mail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real';
$doneAfter = jeab_get_reminder(jeab_store(), (int) $done['id']);
$testAfter = jeab_get_reminder(jeab_store(), (int) $testRem['id']);
$selfAfter = jeab_get_reminder(jeab_store(), (int) $self['id']);
qdl(str_contains((string) ($realAfter['note'] ?? ''), $realUrl) && !str_contains((string) ($realAfter['note'] ?? ''), 'autotest-vera') && !str_contains((string) ($realAfter['note'] ?? ''), 'test-namn-1') && str_contains((string) ($realAfter['note'] ?? ''), 'Ring kunden.'), 'mail_links lägger en rad på det riktiga ärendet');
qdl((string) ($doneAfter['note'] ?? '') === 'Redan klar.', 'klar påminnelse får ingen mail-rad');
qdl((string) ($testAfter['note'] ?? '') === 'Testpåminnelse.', 'testpåminnelse får ingen mail-rad');
qdl((string) ($selfAfter['note'] ?? '') === 'Självtest.', 'självtestets adress får ingen mail-rad');
qdl(!str_contains($applyJson, '@'), 'mail_links-svaret innehåller ingen e-post');
$again = jeab_inkorg_hook(['action' => 'inbox.mail_links', 'dry' => 0]);
$realTwice = jeab_get_reminder(jeab_store(), (int) $real['id']);
qdl(substr_count((string) ($realTwice['note'] ?? ''), $realUrl) === 1 && ($again['changed'] ?? 1) === 0, 'samma ärende får högst en mail-rad');
qdl((string) $realThread['thread_id'] === 'vera-real' && (string) $autoThread['thread_id'] === 'autotest-vera' && (string) $testNamed['thread_id'] === 'test-namn-1', 'testärendena fick de id som rensningen ska känna igen');

$dupNote = "Ring inte.\n"
    . "mail: https://flowstack.example.test/index.php?p=inkorg&id=autotest-aaa\n"
    . "mail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real\n"
    . "mail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real\n"
    . "mail: https://flowstack.example.test/index.php?p=inkorg&id=autotest-bbb\n"
    . "mail: https://flowstack.example.test/index.php?p=inkorg&id=test-namn-1";
$messy = jeab_create_reminder(jeab_store(), 'TEST (klar) elva rader', jeab_today_start(), null, null, [
    'customer' => 'TEST Kund', 'email' => 'demo.person@example.invalid', 'company' => 'jarfalla', 'who' => 'Elin', 'note' => $dupNote,
]);
jeab_toggle_reminder(jeab_store(), (int) $messy['id'], true);
$openMessy = jeab_create_reminder(jeab_store(), 'TEST öppen', jeab_today_start(), null, null, [
    'customer' => 'Test Öppen', 'email' => 'test@test.se', 'company' => 'jarfalla', 'who' => 'Elin',
    'note' => "mail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real\nmail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real\n",
]);
$realDup = jeab_create_reminder(jeab_store(), 'Vera dubblett', jeab_today_start(), null, null, [
    'customer' => 'Vera Kund', 'email' => 'vera.kund.sq@gmail.com', 'company' => 'jarfalla', 'who' => 'Elin',
    'note' => "mail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real\nmail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real\n",
]);
jeab_toggle_reminder(jeab_store(), (int) $realDup['id'], true);
$dryDedupe = jeab_inkorg_hook(['action' => 'inkorg.mail_links_dedupe']);
$dryDedupeJson = (string) json_encode($dryDedupe, JSON_UNESCAPED_UNICODE);
$messyDry = jeab_get_reminder(jeab_store(), (int) $messy['id']);
$removed = 0;
foreach ((array) ($dryDedupe['reminders'] ?? []) as $row) {
    if ((int) ($row['id'] ?? 0) === (int) $messy['id']) {
        $removed = (int) ($row['removed'] ?? 0);
    }
}
qdl(($dryDedupe['ok'] ?? false) === true && ($dryDedupe['dry'] ?? 0) === 1 && $removed === 4 && (string) ($messyDry['note'] ?? '') === $dupNote && !str_contains($dryDedupeJson, '@'), 'rensningen är torr som standard och pekar ut dubbletter och testärenden');
qdl((string) (jeab_get_reminder(jeab_store(), (int) $openMessy['id'])['note'] ?? '') === (string) $openMessy['note'], 'öppen testpåminnelse rensas inte');
qdl((string) (jeab_get_reminder(jeab_store(), (int) $realDup['id'])['note'] ?? '') === (string) $realDup['note'], 'klar riktig påminnelse rensas inte');
$liveDedupe = jeab_inkorg_hook(['action' => 'inbox.mail_links_dedupe', 'dry' => 0]);
$messyLive = jeab_get_reminder(jeab_store(), (int) $messy['id']);
$liveNote = (string) ($messyLive['note'] ?? '');
qdl(($liveDedupe['dry'] ?? 1) === 0 && $liveNote === "Ring inte.\nmail: https://flowstack.example.test/index.php?p=inkorg&id=vera-real" && !str_contains((string) json_encode($liveDedupe), '@'), 'dry=0 tar bort dubbletten och testärendena och lämnar en rad');

$preview = [
    'wix_offer' => $resolved['offer'] ?? '',
    'wix_sender_is_elin' => ($dryQuote['sender'] ?? '') === 'saljare4@jarfalla.example.test',
    'wix_template' => (string) ($dryQuote['template'] ?? ''),
    'diag' => $src,
    'recheck_hit' => $recheckHit,
    'mail_real' => (string) ($realTwice['note'] ?? ''),
    'mail_done' => (string) ($doneAfter['note'] ?? ''),
    'mail_test' => (string) ($testAfter['note'] ?? ''),
    'mail_self' => (string) ($selfAfter['note'] ?? ''),
    'dedupe_dry_removed' => $removed,
    'dedupe_after' => $liveNote,
    'search_held_plus' => ($searchHit['hit'] ?? false) === true,
    'scan_found_when_search_empty' => ($scanRow['hit'] ?? false) === true,
    'reply_to_and_bcc_ignored' => true,
];
$dir = '/opt/cursor/artifacts';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($dir . '/quote-diag-mail-links.json', json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$srcRows = '';
foreach ($src as $name => $row) {
    if (!is_array($row)) {
        continue;
    }
    $srcRows .= '<tr><td>' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '</td><td>'
        . htmlspecialchars((string) ($row['status'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>'
        . (!empty($row['hit']) ? 'ja' : 'nej') . '</td><td>'
        . htmlspecialchars((string) ($row['date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
}
$html = '<!doctype html><meta charset="utf-8"><title>Diagnos, mail-rader, Skickat</title>'
    . '<style>body{font:16px/1.45 sans-serif;margin:28px;background:#f6f4ef;color:#1c1915}table{border-collapse:collapse;background:#fff}td,th{border:1px solid #ccc;padding:6px 10px;text-align:left}section{margin:0 0 28px}code{background:#fff;padding:1px 4px}</style>'
    . '<h1>Wix utan produktetikett, mail-rader och svar i Skickat</h1>'
    . '<section><h2>Diagnos</h2><p>Produkt från texten: <b>laddbox</b>. send_quote dry väljer Elin och mallen Elbilsladdare. $search mot den bara adressen är tom. Datumskanningen hittar «Sv: Laddbox beställning» där To är visningsnamn med plustag.</p>'
    . '<table><tr><th>Källa</th><th>Status</th><th>Träff</th><th>Datum</th></tr>' . $srcRows . '</table></section>'
    . '<section><h2>mail_links</h2><ul>'
    . '<li>Öppen riktig påminnelse: <code>' . htmlspecialchars((string) ($realTwice['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></li>'
    . '<li>Klar påminnelse: <code>' . htmlspecialchars((string) ($doneAfter['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></li>'
    . '<li>TEST-påminnelse: <code>' . htmlspecialchars((string) ($testAfter['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></li>'
    . '<li>Självtestets adress: <code>' . htmlspecialchars((string) ($selfAfter['note'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></li>'
    . '</ul></section>'
    . '<section><h2>mail_links_dedupe</h2><p>Torr körning skulle ta bort <b>' . $removed . '</b> rader och skrev inget. dry=0 lämnade:</p><code>'
    . htmlspecialchars($liveNote, ENT_QUOTES, 'UTF-8') . '</code></section>'
    . '<section><h2>Skickat</h2><p>$search-träff med plustag hålls. Tom $search och träff i skanningen: '
    . (($scanRow['hit'] ?? false) ? 'ja, 2026-10-16 10:00' : 'nej')
    . '. replyTo och bcc läses inte.</p></section>';
file_put_contents($dir . '/quote-diag-mail-links.html', $html);
$previewDir = '/tmp/jeab-magnus-preview';
if (is_dir($previewDir)) {
    file_put_contents($previewDir . '/index.html', $html);
}

echo "OK  quote-diag-links\n";
