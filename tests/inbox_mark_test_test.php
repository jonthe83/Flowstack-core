<?php

declare(strict_types=1);

/** inbox.mark_test, namn «Test » + känd testadress, och uttrycklig testinlämning. */

$tmp = sys_get_temp_dir() . '/jeab-mark-test-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_WEBHOOK_CAPTURE'] = '1';
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'outgoing_webhook_url' => 'https://example.test/hook',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function mt_ok(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

function mt_reminder_count(): int
{
    return count(jeab_reminders_every(jeab_store()));
}

/** @return array<string, mixed> */
function mt_thread(string $id): array
{
    foreach (jeab_inkorg_threads() as $thread) {
        if ((string) ($thread['id'] ?? '') === $id) {
            return $thread;
        }
    }

    return [];
}

$store = jeab_boot();
jeab_set_setting($store, 'inkorg_storage', 'sql');
mt_ok(jeab_inkorg_storage_mode() === 'sql', 'inkorgen ligger i SQL');

$llmCalls = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = static function () use (&$llmCalls): string {
    $llmCalls++;

    return '{"type":"Ny lead","confidence":90,"product":"batteri","intent":"kop","offer":"batteri","lead":"eget","question":"","reason":"kund","is_test":false}';
};
$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['_JEAB_WEBHOOKS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['_SENT'][] = $args;
    $n = count($GLOBALS['_SENT']);

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-mt-' . $n, 'message_id' => 'sent-mt-' . $n . '@local'];
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
mt_ok(str_contains($promptText, 'gmail plus-test') && str_contains($promptText, 'qpsmedia') && str_contains($promptText, 'Testa'), 'prompten nämner kända testadresser och undantar Testa');
$rules = implode("\n", jeab_auto_rule_lines());
mt_ok(str_contains($rules, 'inbox.mark_test') && str_contains($rules, 'confirm=SAM'), 'reglerna beskriver inbox.mark_test');

mt_ok(jeab_test_lead_match(['name' => 'Test Kund', 'email' => 'einstein@gmail.com'])['reason'] === 'einstein', 'Test Kund + einstein är einstein');
mt_ok(!is_test_lead(['name' => 'Ada Andersson', 'email' => 'einstein@gmail.com']), 'einstein utan Test-prefix är inte test');
mt_ok(jeab_test_lead_match(['name' => 'Test Kevin', 'email' => 'kevin@qpsmedia.se'])['reason'] === 'qpsmedia', 'Test Kevin + qpsmedia är qpsmedia');
mt_ok(!is_test_lead(['name' => 'Kevin Berg', 'email' => 'kevin@qpsmedia.se']), 'qpsmedia utan Test-prefix är inte is_test');
mt_ok(jeab_test_lead_match(['name' => 'Test Word', 'email' => 'word_test@example.invalid'])['reason'] === 'word_test', 'Test Word + word_test är word_test');
mt_ok(jeab_test_lead_match(['name' => 'Test Ada', 'email' => 'ada+test@example.invalid'])['reason'] === 'gmail plus-test', 'Test Ada + gmail plus-test');
mt_ok(jeab_test_lead_match(['name' => 'Test Ada', 'email' => 'ada+test@hotmail.com'])['reason'] === '+test', 'Test Ada + hotmail plus-test är +test');
mt_ok(jeab_test_lead_match(['email' => 'anna.test@example.invalid', 'name' => 'Anna Andersson'])['reason'] === 'test i mejl', 'test i lokaldelen utan Test-prefix är test i mejl');
mt_ok(jeab_test_lead_match(['name' => 'Test Testsson', 'email' => 'testsson@gmail.com'])['reason'] === 'test i namn', 'Test Testsson utan känd adress är test i namn');
mt_ok(!is_test_lead(['name' => 'Testa Svensson', 'email' => 'einstein@gmail.com', 'phone' => '0701234567']), 'Testa tillsammans med einstein är inte test');
mt_ok(jeab_test_lead_blocks_reminder('einstein') && !jeab_test_lead_blocks_reminder('test i namn') && !jeab_test_lead_blocks_reminder('test i mejl'), 'bara kända testorsaker stoppar påminnelse');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $from, string $name, string $body, array $extra = []) use ($box): array {
    return jeab_inkorg_ingest(array_merge([
        'message_id' => $id . '@mark.jeab',
        'from' => $from,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Batteri',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => 'mt-' . $id,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
    ], $extra));
};

$llmBefore = $llmCalls;
$sentBefore = count($GLOBALS['_SENT']);
$remBefore = mt_reminder_count();
$wix = $ingest('wix', 'info@wix-forms.com', 'Wix', "Namn: Test Kund\nE-post: einstein@gmail.com\nTelefon: 0701234501\nAdress: Solvägen 1, 177 31 Järfälla\nProdukt: Batteri\n");
mt_ok(!empty($wix['ok']), 'Wix-formulär tas emot');
$wixMsg = jeab_inkorg_get((string) ($wix['id'] ?? ''));
mt_ok(is_array($wixMsg) && !empty($wixMsg['labels']['is_test']) && (string) ($wixMsg['labels']['test_reason'] ?? '') === 'einstein' && (string) ($wixMsg['labels']['source'] ?? '') === 'test', 'Wix Test Kund + einstein är is_test före Grok');
mt_ok($llmCalls === $llmBefore && count($GLOBALS['_SENT']) === $sentBefore && mt_reminder_count() === $remBefore, 'Wix-testet skapar ingen påminnelse och skickar ingen offert');
$wixThread = mt_thread((string) ($wix['thread_id'] ?? ''));
$wixLead = $wixThread === [] ? [] : jeab_lead_process($wixThread, true);
mt_ok(($wixLead['skipped'] ?? '') === 'testlead' && (int) ($wixLead['reminder'] ?? 0) === 0 && mt_reminder_count() === $remBefore, 'leadprocessen skapar ingen påminnelse för einstein');
$wixAuto = jeab_auto_on_message((string) ($wix['id'] ?? ''));
mt_ok(($wixAuto['action'] ?? '') !== 'quoted' && count($GLOBALS['_SENT']) === $sentBefore, 'Wix-testet får ingen offert');
mt_ok(!jeab_thread_counts_as_production_lead($wixThread, is_array($wixMsg) ? $wixMsg : []), 'Wix-tråden räknas inte som produktionslead');

$flagBody = "Namn: Anna Andersson\nE-post: anna.flagga@gmail.com\nTelefon: 0701234502\nAdress: Solvägen 2, 177 31 Järfälla\nTest: ja\n";
$flag = $ingest('flag', 'info@wix-forms.com', 'Wix', $flagBody);
$flagMsg = jeab_inkorg_get((string) ($flag['id'] ?? ''));
mt_ok(is_array($flagMsg) && !empty($flagMsg['is_test']) && (string) ($flagMsg['flag_reason'] ?? '') === 'testflagga' && (string) ($flagMsg['labels']['test_reason'] ?? '') === 'testflagga', 'raden Test: ja märks testflagga');
mt_ok($llmCalls === $llmBefore && mt_reminder_count() === $remBefore, 'Test: ja hoppar över Grok och påminnelse');

$header = $ingest('hdr', 'anna.header@gmail.com', 'Anna Header', "Hej, jag vill ha ett batteri.\nTelefon: 0701234503\nAdress: Solvägen 3, 177 31 Järfälla\n", [
    'headers' => ['X-Jeab-Test' => '1'],
]);
$headerMsg = jeab_inkorg_get((string) ($header['id'] ?? ''));
mt_ok(is_array($headerMsg) && !empty($headerMsg['labels']['is_test']) && (string) ($headerMsg['labels']['test_reason'] ?? '') === 'testflagga', 'X-Jeab-Test märks innan Grok');
mt_ok($llmCalls === $llmBefore, 'X-Jeab-Test anropar inte Grok');

$field = $ingest('field', 'anna.field@gmail.com', 'Anna Field', "Hej, jag vill ha ett batteri.\nTelefon: 0701234504\nAdress: Solvägen 4, 177 31 Järfälla\n", [
    'test' => 'ja',
]);
$fieldMsg = jeab_inkorg_get((string) ($field['id'] ?? ''));
mt_ok(is_array($fieldMsg) && !empty($fieldMsg['labels']['is_test']), 'ingest-fältet test=ja är is_test');

$realBefore = $llmCalls;
$realSent = count($GLOBALS['_SENT']);
$realRem = mt_reminder_count();
$hooksBefore = count($GLOBALS['_JEAB_WEBHOOKS']);
$real = $ingest('real', 'mira.mark@gmail.com', 'Mira Marksson', "Hej, jag vill ha ett batteri.\nTelefon: 0701234505\nAdress: Solvägen 5, 177 31 Järfälla\n");
mt_ok(!empty($real['ok']) && $llmCalls === $realBefore + 1, 'ett riktigt lead klassas');
$realAuto = jeab_auto_on_message((string) ($real['id'] ?? ''));
mt_ok(($realAuto['action'] ?? '') === 'quoted' && ($GLOBALS['_SENT'][$realSent]['to'] ?? '') === 'mira.mark@gmail.com', 'det riktiga leadet får offert');
mt_ok(mt_reminder_count() === $realRem + 1, 'det riktiga leadet får en påminnelse');
$realTid = (string) ($real['thread_id'] ?? '');
$linked = null;
foreach (jeab_reminders_every(jeab_store()) as $row) {
    if ((string) ($row['thread_id'] ?? '') === $realTid) {
        $linked = $row;
        break;
    }
}
mt_ok(is_array($linked) && jeab_reminder_counts_as_production_lead($linked), 'den kopplade påminnelsen är ett produktionslead');
$createdHooks = 0;
foreach (array_slice($GLOBALS['_JEAB_WEBHOOKS'], $hooksBefore) as $hook) {
    if ((string) ($hook['event'] ?? '') === 'reminder.created') {
        $createdHooks++;
    }
}
mt_ok($createdHooks === 1, 'ett riktigt lead skickar reminder.created');

$loose = jeab_create_reminder($store, 'Lös påminnelse', null, null, null, [
    'customer_name' => 'Bo Berg',
    'email' => 'bo.berg@gmail.com',
    'phone' => '0701234506',
]);
$looseId = (int) ($loose['id'] ?? 0);
mt_ok($looseId > 0 && jeab_reminder_counts_as_production_lead($loose), 'en lös påminnelse räknas');

$dry = jeab_inkorg_hook(['action' => 'inbox.mark_test', 'thread_ids' => [$realTid], 'reminder_ids' => [$looseId]]);
mt_ok(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0 && (int) ($dry['sent'] ?? 1) === 0 && (int) ($dry['deleted'] ?? 1) === 0 && empty($dry['would_send']), 'dry är standard och skriver inget');
mt_ok(jeab_reminder_counts_as_production_lead(jeab_store()->getReminder($looseId) ?: []), 'dry lämnar den lösa påminnelsen');
$refused = jeab_inbox_mark_test(['thread_ids' => [$realTid], 'dry' => 0]);
mt_ok(empty($refused['ok']) && (int) ($refused['written'] ?? 1) === 0 && (int) ($refused['sent'] ?? 1) === 0, 'dry=0 utan confirm skriver inget');
mt_ok(empty(jeab_inkorg_thread_meta($realTid)['is_test']), 'vägrad markering sätter inte is_test');

$sentAtMark = count($GLOBALS['_SENT']);
$smsAtMark = count($GLOBALS['_SMS']);
$live = jeab_inbox_mark_test([
    'thread_ids' => [$realTid],
    'reminder_ids' => [(string) $looseId],
    'dry' => 0,
    'confirm' => 'SAM',
]);
$liveJson = json_encode($live, JSON_UNESCAPED_UNICODE);
mt_ok(!empty($live['ok']) && (int) ($live['written'] ?? 0) > 0 && (int) ($live['sent'] ?? 1) === 0 && (int) ($live['deleted'] ?? 1) === 0 && empty($live['would_send']), 'confirm=SAM markerar');
mt_ok(is_string($liveJson) && !str_contains($liveJson, '@'), 'svaret innehåller ingen mejladress');
mt_ok(count($GLOBALS['_SENT']) === $sentAtMark && count($GLOBALS['_SMS']) === $smsAtMark, 'mark_test skickar inget');
$markedMeta = jeab_inkorg_thread_meta($realTid);
$markedTags = array_map('strval', (array) ($markedMeta['tags'] ?? []));
mt_ok(!empty($markedMeta['is_test']) && !empty($markedMeta['test_lead']) && in_array('test', $markedTags, true), 'ärendet får is_test och taggen test');
$markedLinked = jeab_store()->getReminder((int) $linked['id']);
$markedLoose = jeab_store()->getReminder($looseId);
mt_ok(is_array($markedLinked) && !jeab_reminder_counts_as_production_lead($markedLinked), 'kopplad påminnelse lämnar leadstatistiken');
mt_ok(is_array($markedLoose) && in_array('test', jeab_parse_tags($markedLoose['tags'] ?? []), true), 'utpekad påminnelse får taggen test');
$markedThread = mt_thread($realTid);
mt_ok($markedThread !== [] && !jeab_thread_counts_as_production_lead($markedThread) && jeab_notiser_thread_is_test($markedThread), 'markerad tråd är inte ett produktionslead');
$block = jeab_auto_quote_block(['name' => 'Test Kund', 'email' => 'einstein@gmail.com'], 'einstein@gmail.com', false);
mt_ok(str_contains($block, 'känd testadress'), 'auto-offert stoppar känd testadress');

$batch = (string) ($live['batch'] ?? '');
$undoDry = jeab_inbox_mark_test(['undo' => 1, 'batch' => $batch]);
mt_ok(!empty($undoDry['ok']) && (int) ($undoDry['written'] ?? 1) === 0 && !empty(jeab_inkorg_thread_meta($realTid)['is_test']), 'undo dry återställer inget');
$undoRefused = jeab_inbox_mark_test(['undo' => 1, 'batch' => $batch, 'dry' => 0]);
mt_ok(empty($undoRefused['ok']) && !empty(jeab_inkorg_thread_meta($realTid)['is_test']), 'undo utan confirm skriver inget');
$undo = jeab_inbox_mark_test(['undo' => 1, 'batch' => $batch, 'dry' => 0, 'confirm' => 'SAM']);
mt_ok(!empty($undo['ok']) && (int) ($undo['sent'] ?? 1) === 0 && (int) ($undo['deleted'] ?? 1) === 0, 'undo med confirm återställer');
mt_ok(empty(jeab_inkorg_thread_meta($realTid)['is_test']), 'ångrat ärende är inte is_test');
$restored = jeab_store()->getReminder((int) $linked['id']);
mt_ok(is_array($restored) && jeab_reminder_counts_as_production_lead($restored), 'ångrad påminnelse räknas igen');
$restoredLoose = jeab_store()->getReminder($looseId);
mt_ok(is_array($restoredLoose) && !in_array('test', jeab_parse_tags($restoredLoose['tags'] ?? []), true), 'ångrad lös påminnelse tappar taggen test');

$hookBefore = count($GLOBALS['_JEAB_WEBHOOKS']);
$parsed = jeab_parse_incoming([
    'type' => 'reminder',
    'title' => 'Test Kund',
    'test' => true,
    'email' => 'einstein@gmail.com',
    'customer' => 'Test Kund',
]);
mt_ok(($parsed['type'] ?? '') === 'reminder' && !empty($parsed['is_test']) && in_array('test', (array) ($parsed['tags'] ?? []), true), 'webhook test:true sätter taggen test');
$made = jeab_create_reminder($store, (string) $parsed['title'], $parsed['due_at'] ?? null, $parsed['note'] ?? null, null, $parsed);
mt_ok(!jeab_reminder_counts_as_production_lead($made), 'webhook-påminnelsen räknas inte');
$madeHooks = 0;
foreach (array_slice($GLOBALS['_JEAB_WEBHOOKS'], $hookBefore) as $hook) {
    if ((string) ($hook['event'] ?? '') === 'reminder.created') {
        $madeHooks++;
    }
}
mt_ok($madeHooks === 0, 'testpåminnelse skickar inte reminder.created');

$bookBefore = count($GLOBALS['_JEAB_WEBHOOKS']);
$bookingIn = jeab_parse_incoming([
    'type' => 'booking',
    'customer' => 'Test Kund',
    'assignee' => 'Elin',
    'starts_at' => '2026-11-19 09:00:00',
    'ends_at' => '2026-11-19 10:00:00',
    'job_type' => 'laddbox',
    'company' => 'jarfalla',
    'email' => 'einstein@gmail.com',
    'is_test' => '1',
]);
$booking = jeab_create_booking($store, (string) $bookingIn['customer'], (array) $bookingIn['assignees'], (string) $bookingIn['starts_at'], (string) $bookingIn['ends_at'], $bookingIn['job_type'] ?? 'laddbox', $bookingIn, false);
mt_ok((int) ($booking['id'] ?? 0) > 0, 'testbokning sparas');
$bookHooks = 0;
foreach (array_slice($GLOBALS['_JEAB_WEBHOOKS'], $bookBefore) as $hook) {
    if ((string) ($hook['event'] ?? '') === 'booking.created') {
        $bookHooks++;
    }
}
mt_ok($bookHooks === 0, 'testbokning skickar inte booking.created');

echo "ALL OK\n";
