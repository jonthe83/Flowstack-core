<?php

declare(strict_types=1);

/** Testleads får ärende märkt Test, men ingen automatisk offert, kvitto eller SMS. */

$tmp = sys_get_temp_dir() . '/jeab-test-lead-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_OMTAGO_KEY_FILE'] = $tmp . '/omtago_api_key';
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($GLOBALS['_JEAB_OMTAGO_KEY_FILE'], "test-omtago-key-7535\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function tl(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

/** @return list<array<string,mixed>> */
function tl_logs(): array
{
    $pdo = jeab_lead_sql();
    if (!$pdo instanceof PDO) {
        return [];
    }
    $rows = $pdo->query("SELECT payload FROM log_lines WHERE kind = 'test_lead' ORDER BY id")->fetchAll();
    $out = [];
    foreach ($rows ?: [] as $row) {
        $decoded = json_decode((string) ($row['payload'] ?? ''), true);
        if (is_array($decoded)) {
            $out[] = $decoded;
        }
    }

    return $out;
}

function tl_has(string $flow, string $reason, string $emailMask, string $phoneMask = ''): bool
{
    foreach (tl_logs() as $row) {
        if ((string) ($row['flow'] ?? '') === $flow
            && (string) ($row['reason'] ?? '') === $reason
            && (string) ($row['email'] ?? '') === $emailMask
            && ($phoneMask === '' || (string) ($row['phone'] ?? '') === $phoneMask)) {
            return true;
        }
    }

    return false;
}

$store = jeab_boot();
jeab_set_setting($store, 'inkorg_storage', 'sql');
tl(jeab_inkorg_storage_mode() === 'sql', 'inkorgen ligger i SQL');

$llmCalls = 0;
$llmMode = 'real';
$GLOBALS['_JEAB_INKORG_LLM'] = static function () use (&$llmCalls, &$llmMode): string {
    $llmCalls++;
    if ($llmMode === 'grok-test') {
        return '{"type":"Ny lead","confidence":90,"product":"batteri","intent":"kop","offer":"batteri","lead":"eget","question":"","reason":"test","is_test":true}';
    }

    return '{"type":"Ny lead","confidence":90,"product":"batteri","intent":"kop","offer":"batteri","lead":"eget","question":"","reason":"kund","is_test":false}';
};
$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['_SENT'][] = $args;
    $n = count($GLOBALS['_SENT']);

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-tl-' . $n, 'message_id' => 'sent-tl-' . $n . '@local'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to, string $text): array {
    $GLOBALS['_SMS'][] = ['to' => $to, 'text' => $text];

    return ['ok' => true];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 500, 'body' => []];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);

$prompt = jeab_inkorg_llm_prompt(['from' => 'anna@gmail.com', 'subject' => 'Batteri', 'body' => 'Hej']);
$promptText = (string) ($prompt[0]['content'] ?? '');
tl(str_contains($promptText, '"is_test":false') && str_contains($promptText, 'Testa') && str_contains($promptText, 'is_test true'), 'prompten ber Grok sätta is_test och undanta Testa');

tl(is_test_lead(['email' => 'ada@example.org', 'name' => 'Ada Andersson']), 'example.org är test');
tl(jeab_test_lead_match(['email' => 'jeab+test-1@mail.grokbot.com'])['reason'] === 'mejldomän', 'mail.grokbot.com är mejldomän');
tl(jeab_test_lead_match(['email' => 'ada@mail.grokbot.com'])['reason'] === 'mejldomän', 'adress utan test i lokaldelen på mail.grokbot.com är test');
tl(!is_test_lead(['email' => 'ada@grokbot.com', 'name' => 'Ada Andersson']), 'grokbot.com utan mail. är inte test');
tl(str_contains($promptText, 'mail.grokbot.com'), 'prompten nämner mail.grokbot.com');
tl(jeab_test_lead_match(['email' => 'Ada <ada@mail.example.org>'])['reason'] === 'mejldomän', 'underdomän till example.org är test');
tl(is_test_lead(['email' => 'ada@example.com']) && is_test_lead(['email' => 'ada@example.net']), 'example.com och example.net är test');
tl(is_test_lead(['email' => 'ada@test.se']) && is_test_lead(['email' => 'ada@foo.test']) && is_test_lead(['email' => 'ada@bar.invalid']), 'test.* *.test och *.invalid är test');
tl(!is_test_lead(['email' => 'ada@exempel.se', 'name' => 'Ada Andersson']), 'exempel.se är inte en testdomän');

$local = jeab_test_lead_match(['email' => 'anna.test@example.invalid', 'name' => 'Anna Andersson']);
tl($local['test'] && $local['reason'] === 'test i mejl', 'ordet test i lokaldelen är test');
tl(jeab_test_lead_mask_email('anna.test@example.invalid') === 'a***@gmail.com', 'mejlet maskeras');
tl(!is_test_lead(['email' => 'testing@gmail.com']) && !is_test_lead(['email' => 'testar@gmail.com']) && !is_test_lead(['email' => 'testkund@gmail.com']), 'testing, testar och testkund är inte ordet test');

tl(is_test_lead(['name' => 'Test Testsson', 'email' => 'testsson@gmail.com']) && jeab_test_lead_match(['name' => 'Test Testsson'])['reason'] === 'test i namn', 'Test Testsson är test');
tl(is_test_lead(['name' => 'Asdf']) && jeab_test_lead_match(['name' => 'Asdf'])['reason'] === 'namn asdf', 'Asdf är test');
tl(!is_test_lead(['name' => 'Testa Svensson', 'email' => 'testa@gmail.com', 'phone' => '0701234567']), 'Testa är ett riktigt namn');
tl(!is_test_lead(['name' => 'asdfgh', 'email' => 'kund@gmail.com']), 'asdfgh är inte namnet Asdf');
tl(!is_test_lead(['name' => 'Teststad', 'email' => 'bo@gmail.com']), 'Teststad är inte ordet test');

tl(is_test_lead(['phone' => '0700000000']) && is_test_lead(['phone' => '+46700000000']) && is_test_lead(['phone' => '1234567']), 'kända testnummer är test');
tl(!is_test_lead(['phone' => '0701234567']) && !is_test_lead(['phone' => '070-000 00 21']), 'riktiga nummer är inte test');
tl(jeab_test_lead_mask_phone('0700000000') === '070***00', 'telefonen maskeras');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $from, string $name, string $body, array $extra = []) use ($box): array {
    return jeab_inkorg_ingest(array_merge([
        'message_id' => $id . '@lead.jeab',
        'from' => $from,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Batteri',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => 'tl-' . $id,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
    ], $extra));
};

$llmBefore = $llmCalls;
$sentBefore = count($GLOBALS['_SENT']);
$org = $ingest('org', 'ada@example.org', 'Ada Andersson', "Hej, jag vill ha ett batteri.\nAdress: Exempelvägen 1, 177 80 Järfälla\n");
tl(!empty($org['ok']), 'example.org tas emot');
$orgId = (string) ($org['thread_id'] ?? '');
$orgMeta = jeab_inkorg_thread_meta($orgId);
$orgMsg = jeab_inkorg_get((string) ($org['id'] ?? ''));
tl($llmCalls === $llmBefore, 'example.org klassas inte av Grok');
tl(is_array($orgMsg) && !empty($orgMsg['labels']['is_test']) && (string) ($orgMsg['labels']['source'] ?? '') === 'test', 'example.org märks som test före Grok');
tl(!empty($orgMeta['test_lead']) || in_array('test', array_map('strval', (array) ($orgMeta['tags'] ?? [])), true), 'ärendet märks Test');
$orgAuto = jeab_auto_on_message((string) ($org['id'] ?? ''));
tl(($orgAuto['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $sentBefore, 'example.org får ingen offert');
tl(tl_has('offert', 'mejldomän', 'a***@example.org'), 'skippet för example.org loggas maskerat');
$orgBlob = json_encode(tl_logs(), JSON_UNESCAPED_UNICODE);
tl(is_string($orgBlob) && !str_contains($orgBlob, 'ada@example.org'), 'loggen innehåller inte hela mejladressen');

$word = $ingest('word', 'anna.test@example.invalid', 'Anna Andersson', "Hej, jag vill ha ett batteri.\nTelefon: 0701234567\nAdress: Exempelvägen 2, 177 80 Järfälla\n");
tl(!empty($word['ok']) && $llmCalls === $llmBefore, 'test i mejlet hoppar över Grok');
$wordAuto = jeab_auto_on_message((string) ($word['id'] ?? ''));
tl(($wordAuto['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $sentBefore, 'test i mejlet får ingen offert');
tl(tl_has('offert', 'test i mejl', 'a***@gmail.com', '070***67'), 'skippet för testmejlet loggar maskerat mejl och telefon');
$wordBlob = json_encode(tl_logs(), JSON_UNESCAPED_UNICODE);
tl(is_string($wordBlob) && !str_contains($wordBlob, 'anna.test@example.invalid') && !str_contains($wordBlob, '0701234567'), 'loggen innehåller varken hela mejlet eller hela numret');

$sentBefore = count($GLOBALS['_SENT']);
$llmBefore = $llmCalls;
$real = $ingest('real', 'anna@gmail.com', 'Anna Andersson', "Hej, jag vill ha ett batteri.\nTelefon: 0701234567\nAdress: Exempelvägen 3, 177 80 Järfälla\n");
tl(!empty($real['ok']) && $llmCalls === $llmBefore + 1, 'ett riktigt lead klassas');
$realMsg = jeab_inkorg_get((string) ($real['id'] ?? ''));
tl(is_array($realMsg) && empty($realMsg['labels']['is_test']) && (string) ($realMsg['labels']['type'] ?? '') === 'Ny lead', 'det riktiga leadet är inte test');
$realAuto = jeab_auto_on_message((string) ($real['id'] ?? ''));
$realSent = $GLOBALS['_SENT'][$sentBefore] ?? [];
tl(($realAuto['action'] ?? '') === 'quoted' && ($realSent['to'] ?? '') === 'anna@gmail.com', 'det riktiga leadet får offert');

$testaBefore = $llmCalls;
$testaSent = count($GLOBALS['_SENT']);
$testa = $ingest('testa', 'testa@gmail.com', 'Testa Svensson', "Hej, jag vill ha ett batteri.\nTelefon: 0701234568\nAdress: Exempelvägen 4, 177 80 Järfälla\n");
$testaAuto = jeab_auto_on_message((string) ($testa['id'] ?? ''));
tl($llmCalls === $testaBefore + 1 && ($testaAuto['action'] ?? '') === 'quoted' && ($GLOBALS['_SENT'][$testaSent]['to'] ?? '') === 'testa@gmail.com', 'Testa Svensson får offert');

$llmMode = 'grok-test';
$grokBefore = $llmCalls;
$grokSent = count($GLOBALS['_SENT']);
$grok = $ingest('grok', 'vera@gmail.com', 'Vera Svensson', "Hej, jag vill ha ett batteri.\nTelefon: 0701112233\nAdress: Exempelvägen 5, 177 80 Järfälla\n");
$grokAuto = jeab_auto_on_message((string) ($grok['id'] ?? ''));
$grokMsg = jeab_inkorg_get((string) ($grok['id'] ?? ''));
tl($llmCalls === $grokBefore + 1 && is_array($grokMsg) && !empty($grokMsg['labels']['is_test']), 'Grok is_test märker leadet');
tl(($grokAuto['why'] ?? '') === 'testlead' && count($GLOBALS['_SENT']) === $grokSent, 'Grok is_test stoppar offerten');
tl(tl_has('offert', 'grok', 'v***@gmail.com'), 'Grok-skippet loggas');
$llmMode = 'real';

$buySent = count($GLOBALS['_SENT']);
$buyCalls = $llmCalls;
$buy = $ingest('buy', 'hej@nordsolen.se', 'Nordsolen', "Kund: Per Exempel · per.exempel@example.org · 0701234567\nAdress: Exempelvägen 6, 177 80 Järfälla\nVill ha batteri.\n", [
    'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=nordsolen.se'],
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'product' => 'batteri', 'confidence' => 100, 'question' => ''],
]);
$buyAuto = jeab_auto_on_message((string) ($buy['id'] ?? ''));
tl($llmCalls === $buyCalls && ($buyAuto['why'] ?? '') === 'testlead' && count($GLOBALS['_SENT']) === $buySent, 'köpt lead med example.org får ingen offert');
$buyMeta = jeab_inkorg_thread_meta((string) ($buy['thread_id'] ?? ''));
tl(!empty($buyMeta['created_at']) && (!empty($buyMeta['test_lead']) || in_array('test', array_map('strval', (array) ($buyMeta['tags'] ?? [])), true)), 'det köpta testleadet blir ändå ett ärende');

$smsBefore = count($GLOBALS['_SMS']);
$noMail = $ingest('nomail', 'support@weply.se', 'Weply', "Namn: Asdf\nTelefon: 0701234999\nProdukt: Batteri\nVill ha batteri.\n", [
    'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=weply.se'],
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'product' => 'batteri', 'confidence' => 100, 'question' => ''],
]);
$noThread = ['id' => (string) ($noMail['thread_id'] ?? ''), 'tags' => [], 'messages' => [], 'source' => 'graph-jarfalla', 'company' => 'jarfalla'];
$noMsg = jeab_inkorg_get((string) ($noMail['id'] ?? ''));
tl(is_array($noMsg), 'lead utan mejl finns');
$noRow = jeab_no_email_apply($noThread, $noMsg, false);
tl(count($GLOBALS['_SMS']) === $smsBefore + 1 && ($noRow['sms'] ?? '') === 'skickat', 'namnet Asdf stoppar inte SMS');
tl(is_test_lead(['name' => 'Asdf']) && jeab_test_lead_strict(['name' => 'Asdf'])['test'] === false, 'Asdf märks som testlead men spärrar inte utskick');

$graphSent = count($GLOBALS['_SENT']);
$graph = jeab_graph_ingest_message('jarfalla', [
    'id' => 'graph-tl-1',
    'internetMessageId' => '<graph-tl-1@lead.jeab>',
    'conversationId' => 'conv-tl-graph',
    'isDraft' => false,
    'subject' => 'Batteri',
    'receivedDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
    'from' => ['emailAddress' => ['address' => 'greg@example.net', 'name' => 'Greg Andersson']],
    'toRecipients' => [['emailAddress' => ['address' => $box]]],
    'body' => ['content' => "Hej, jag vill ha ett batteri.\nAdress: Exempelvägen 7, 177 80 Järfälla\n"],
    'internetMessageHeaders' => [],
]);
tl(!empty($graph['ok']) && ($graph['test_lead'] ?? '') === 'mejldomän' && count($GLOBALS['_SENT']) === $graphSent, 'inkorg_graph skickar ingen offert till example.net');

$guard = send_guard_check('email', 'kund@example.org', 'offert', 'auto', 'auto', 'system', hash('sha256', 'offert'), ['body' => 'Hej']);
tl(($guard['send'] ?? true) === false && ($guard['reason'] ?? '') === 'testlead', 'send_guard stoppar automatisk offert till example.org');
$guardSms = send_guard_check('sms', '0700000000', 'sms', 'auto', 'auto', 'system', hash('sha256', 'sms'), ['body' => 'Hej']);
tl(($guardSms['send'] ?? false) === true && ($guardSms['reason'] ?? '') !== 'testlead', 'testnummer stoppar inte SMS');
$manual = send_guard_check('email', 'kund@example.org', 'svar', 'manual', 'manual', 'hook', hash('sha256', 'svar'), ['body' => 'Manuellt']);
tl(($manual['send'] ?? false) === true && ($manual['reason'] ?? '') !== 'testlead', 'manuellt svar till samma adress går igenom');
$manualSms = send_guard_check('sms', '0700000000', 'sms', 'manual', 'manual', 'hook', hash('sha256', 'sms-man'), ['body' => 'Hej', 'name' => 'Test Kund']);
tl(($manualSms['send'] ?? false) === true && ($manualSms['reason'] ?? '') !== 'testlead', 'manuellt SMS till testnummer och testnamn går igenom');
$manualSmsLead = send_guard_check('sms', '0707654321', 'sms', 'manual', 'manual', 'hook', hash('sha256', 'sms-lead'), [
    'body' => 'Hej',
    'email' => 'jeab+test-1@mail.grokbot.com',
]);
tl(($manualSmsLead['send'] ?? true) === false && ($manualSmsLead['reason'] ?? '') === 'testlead', 'manuellt SMS med testmejlet stoppas');
$manualSmsReal = send_guard_check('sms', '0707654321', 'sms', 'manual', 'manual', 'hook', hash('sha256', 'sms-real'), ['body' => 'Hej']);
tl(($manualSmsReal['send'] ?? false) === true && ($manualSmsReal['reason'] ?? '') !== 'testlead', 'manuellt SMS till ett vanligt nummer går igenom');
tl(tl_has('send_guard', 'mejldomän', 'k***@example.org'), 'send_guard-skippet loggas');
$guardBlob = json_encode(tl_logs(), JSON_UNESCAPED_UNICODE);
tl(is_string($guardBlob) && !str_contains($guardBlob, '0700000000'), 'SMS-loggen i send_guard är maskerad');

$callsBefore = $llmCalls;
$sentBefore = count($GLOBALS['_SENT']);
$payload = [
    'lead_id' => 'omt-test-lead',
    'created_at' => '2026-10-21T10:00:00+02:00',
    'lead_type' => 'Batteri',
    'property_type' => 'Villa',
    'contact' => [
        'first_name' => 'Ola',
        'last_name' => 'Exempel',
        'email' => 'ola.exempel@example.org',
        'phone' => '070-123 45 67',
    ],
    'address' => [
        'street' => 'Exempelvägen 8',
        'city' => 'Järfälla',
        'postal_code' => '177 80',
    ],
    'summary' => 'Vill ha batteri.',
    'profile' => [],
    'test' => false,
];
$om = jeab_omtago_handle('POST', (string) json_encode($payload, JSON_UNESCAPED_UNICODE), [
    'REMOTE_ADDR' => '203.0.113.40',
    'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535',
]);
tl($om['status'] === 200 && ($om['body']['ok'] ?? false) === true && ($om['body']['id'] ?? '') !== '', 'Omtago-payload med example.org tas emot');
tl($llmCalls === $callsBefore && count($GLOBALS['_SENT']) === $sentBefore, 'Omtago-testlead klassas inte och får ingen offert');
$omId = (string) $om['body']['id'];
$omMeta = jeab_inkorg_thread_meta($omId);
tl((string) ($omMeta['created_at'] ?? '') !== '' && (!empty($omMeta['test_lead']) || in_array('test', array_map('strval', (array) ($omMeta['tags'] ?? [])), true)), 'Omtago-ärendet märks Test');
$omSql = jeab_omtago_pdo()->prepare('SELECT test FROM omtago_leads WHERE lead_id = ?');
$omSql->execute(['omt-test-lead']);
tl((int) $omSql->fetchColumn() === 1, 'Omtago-raden markeras som test');
tl(tl_has('offert', 'mejldomän', 'o***@example.org'), 'Omtago-skippet loggas maskerat');

$flagCalls = $llmCalls;
$flagSent = count($GLOBALS['_SENT']);
$flag = jeab_omtago_handle('POST', (string) json_encode([
    'lead_id' => 'omt-flag-real',
    'created_at' => '2026-10-21T11:00:00+02:00',
    'lead_type' => 'Batteri',
    'property_type' => 'Villa',
    'contact' => [
        'first_name' => 'Vera',
        'last_name' => 'Svensson',
        'email' => 'vera.flagga@gmail.com',
        'phone' => '070-222 33 44',
    ],
    'address' => ['street' => 'Svenssonvägen 1', 'city' => 'Järfälla', 'postal_code' => '177 80'],
    'summary' => 'Vill ha batteri.',
    'profile' => [],
    'test' => true,
], JSON_UNESCAPED_UNICODE), [
    'REMOTE_ADDR' => '203.0.113.41',
    'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535',
]);
tl($flag['status'] === 200 && $llmCalls === $flagCalls && count($GLOBALS['_SENT']) === $flagSent, 'test=true med vanliga uppgifter skapar ärendet utan utskick');
$flagMeta = jeab_inkorg_thread_meta((string) ($flag['body']['id'] ?? ''));
tl(!empty($flagMeta['test_lead']) || in_array('test', array_map('strval', (array) ($flagMeta['tags'] ?? [])), true), 'testflaggan märker ärendet Test');

$liveCalls = $llmCalls;
$liveSent = count($GLOBALS['_SENT']);
$live = jeab_omtago_handle('POST', (string) json_encode([
    'lead_id' => 'omt-live-real',
    'created_at' => '2026-10-21T12:00:00+02:00',
    'lead_type' => 'Batteri',
    'property_type' => 'Villa',
    'contact' => [
        'first_name' => 'Nora',
        'last_name' => 'Svensson',
        'email' => 'nora.live@gmail.com',
        'phone' => '070-333 44 55',
    ],
    'address' => ['street' => 'Svenssonvägen 2', 'city' => 'Järfälla', 'postal_code' => '177 80'],
    'summary' => 'Vill ha batteri.',
    'profile' => [],
    'test' => false,
], JSON_UNESCAPED_UNICODE), [
    'REMOTE_ADDR' => '203.0.113.42',
    'HTTP_AUTHORIZATION' => 'Bearer test-omtago-key-7535',
]);
tl($live['status'] === 200 && $llmCalls === $liveCalls + 1 && count($GLOBALS['_SENT']) === $liveSent + 1 && ($GLOBALS['_SENT'][$liveSent]['to'] ?? '') === 'nora.live@gmail.com', 'vanligt Omtago-lead får fortfarande offert');

echo "test lead ok\n";
