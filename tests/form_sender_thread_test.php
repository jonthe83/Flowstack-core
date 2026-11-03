<?php

declare(strict_types=1);

/** Formulär: kundens namn och mejl, öppen tråd, offert bara vid Ny lead. Påhittade adresser. */

$tmp = sys_get_temp_dir() . '/jeab-form-sender-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fs_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function fs_body(string $name, string $email): string
{
    return "A site visitor just submitted your form: JEAB Kontakt Formulär\n\nNamn:\n\n{$name}\n\nE-post:\n\n{$email}\n\nTelefon:\n\n070-100 00 10\n\nMeddelande:\n\nVi vill ha offert på batteri.\n";
}

$parsed = jeab_lead_customer([
    'from' => 'noreply@wix.com',
    'from_name' => 'Theowhiz',
    'subject' => 'JEAB Kontakt Formulär',
    'body' => fs_body('Ada Form', 'ada.form@exempel.se'),
]);
fs_ok(($parsed['name'] ?? '') === 'Ada Form' && ($parsed['email'] ?? '') === 'ada.form@exempel.se', 'namn och mejl läses ur formuläret');
fs_ok(jeab_form_envelope_is('noreply@wix.com', 'Theowhiz') && jeab_form_envelope_is('info@wix-forms.com', 'Formulär JEAB'), 'wix och no-reply är formulärkuvert');
fs_ok(!jeab_form_envelope_is('hej@nordsolen.se', 'Nordsolen'), 'leadsäljare med egen adress är inte formulärkuvert');

$applied = jeab_form_sender_apply([
    'from' => 'no-reply@crm.wix.com',
    'from_name' => 'Theowhiz',
    'subject' => 'JEAB Kontakt Formulär',
    'body' => fs_body('Bo Form', 'bo.form@exempel.se'),
]);
fs_ok(($applied['from'] ?? '') === 'bo.form@exempel.se' && ($applied['from_name'] ?? '') === 'Bo Form' && ($applied['envelope_from'] ?? '') === 'no-reply@crm.wix.com', 'avsändaren blir kunden och kuvertet sparas');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $n = count($GLOBALS['_SENT']);
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-fs-' . $n, 'message_id' => 'sent-fs-' . $n . '@local'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) {
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 404, 'body' => []];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);

$labels = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100, 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => ''];
$ingest = static function (string $id, string $from, string $fromName, string $name, string $email, array $preset) use ($labels): array {
    unset($labels);
    return jeab_inkorg_ingest([
        'message_id' => $id . '@form.test',
        'from' => $from,
        'from_name' => $fromName,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'JEAB Kontakt Formulär ' . $id,
        'body_text' => fs_body($name, $email),
        'source' => 'graph-jarfalla',
        'thread_key' => 'graph-' . $id,
        'preset_labels' => $preset,
        'test' => true,
    ]);
};
$leadLabels = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100, 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => ''];
$first = $ingest('open-1', 'noreply@wix.com', 'Theowhiz', 'Cia Form', 'cia.form@exempel.se', $leadLabels);
$saved = jeab_inkorg_get((string) $first['id']);
fs_ok(is_array($saved) && ($saved['from'] ?? '') === 'cia.form@exempel.se' && ($saved['from_name'] ?? '') === 'Cia Form', 'intaget mejl visar kunden');
fs_ok(($saved['envelope_from'] ?? '') === 'noreply@wix.com', 'kuvertet ligger kvar på mejlet');

$second = $ingest('open-2', 'info@wix-forms.com', 'Formulär JEAB', 'Cia Form', 'cia.form@exempel.se', $leadLabels);
fs_ok(($second['thread_id'] ?? '') === ($first['thread_id'] ?? '') && ($second['thread_id'] ?? '') !== '', 'nytt inskick läggs i den öppna tråden');
$sentBefore = count($GLOBALS['_SENT']);
$again = jeab_auto_on_message((string) $second['id']);
fs_ok(($again['why'] ?? '') === 'inte ett nytt ärende' && count($GLOBALS['_SENT']) === $sentBefore, 'påfyllnad i öppen tråd skickar ingen ny offert');

jeab_inkorg_thread_write((string) $first['thread_id'], static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$third = $ingest('closed-1', 'noreply@wix.com', 'Theowhiz', 'Cia Form', 'cia.form@exempel.se', $leadLabels);
fs_ok(($third['thread_id'] ?? '') !== '' && ($third['thread_id'] ?? '') !== ($first['thread_id'] ?? ''), 'stängd tråd ger ett nytt ärende');

$infoLabels = ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 95, 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'source' => 'llm', 'question' => ''];
$info = $ingest('info-1', 'kund.info@exempel.se', 'Disa Info', 'Disa Info', 'disa.info@exempel.se', $infoLabels);
$remindersBefore = count(jeab_list_reminders(jeab_store(), true));
$infoAuto = jeab_auto_on_message((string) $info['id']);
fs_ok(($infoAuto['action'] ?? '') === 'none' && ($infoAuto['why'] ?? '') === 'etiketten är inte Ny lead' && count($GLOBALS['_SENT']) === $sentBefore, 'Information skickar ingen offert trots intent och produkt');
fs_ok(count(jeab_list_reminders(jeab_store(), true)) === $remindersBefore, 'Information skapar ingen påminnelse');
fs_ok((string) (jeab_inkorg_get((string) $info['id'])['labels']['type'] ?? '') === 'Information', 'etiketten Information ligger kvar');

jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = is_array($d) ? $d : [];
    $d['grok_decides'] = true;
    return [true, $d];
});
$GLOBALS['_JEAB_INKORG_LLM'] = static fn (): string => json_encode([
    'type' => 'Information',
    'confidence' => 92,
    'intent' => 'information',
    'case_intent' => '',
    'kind' => 'information',
    'primary_product' => '',
    'reason' => 'info',
], JSON_UNESCAPED_UNICODE);
$buy = jeab_inkorg_ingest([
    'message_id' => 'buy-info@form.test',
    'from' => 'noa.kop@exempel.se',
    'from_name' => 'Noa Kop',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert batteri',
    'body_text' => "Hej, vi vill köpa och installera ett batteri. Kan vi få pris?\n",
    'source' => 'graph-jarfalla',
    'test' => true,
]);
$buyAuto = jeab_auto_on_message((string) $buy['id']);
$buyLabels = (array) (jeab_inkorg_get((string) $buy['id'])['labels'] ?? []);
fs_ok(($buyLabels['type'] ?? '') === 'Information' && ($buyLabels['case_class']['type'] ?? '') === 'Information' && ($buyLabels['offer'] ?? '') === 'batteri', 'köpord fyller offert och lämnar Information');
fs_ok(($buyAuto['action'] ?? '') === 'none' && ($buyAuto['why'] ?? '') === 'etiketten är inte Ny lead' && count($GLOBALS['_SENT']) === $sentBefore, 'Information med köpord får ingen auto-offert');
unset($GLOBALS['_JEAB_INKORG_LLM']);

$manual = jeab_auto_send_quote(['thread_id' => (string) $info['thread_id'], 'dry' => 0]);
if (($manual['action'] ?? '') !== 'quoted') {
    fwrite(STDERR, json_encode($manual, JSON_UNESCAPED_UNICODE) . "\n");
}
fs_ok(($manual['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $sentBefore + 1, 'manuell offert på Information stoppas inte');
$manualLog = jeab_inkorg_hook(['action' => 'inbox.sent_log', 'domain' => 'exempel.se']);
$manualRow = null;
foreach ((array) ($manualLog['rows'] ?? []) as $row) {
    if (($row['thread_id'] ?? '') === (string) $info['thread_id']) {
        $manualRow = $row;
    }
}
fs_ok(is_array($manualRow) && ($manualRow['via'] ?? '') === 'manual' && ($manualRow['actor'] ?? '') === 'hook', 'sent_log visar manuell offert utan inloggning som hook');
$sentSql = jeab_store()->pdo()->query('SELECT via, actor, kind FROM inbox_sent')->fetchAll(PDO::FETCH_ASSOC);
fs_ok($sentSql === [['via' => 'manual', 'actor' => 'hook', 'kind' => 'offert']], 'utskicket ligger i SQL utan mottagaradress');
fs_ok((string) (jeab_inkorg_get((string) $info['id'])['labels']['type'] ?? '') === 'Information', 'manuell offert byter inte etikett');
$mis = jeab_store()->pdo()->query('SELECT label, path FROM inbox_misclass')->fetchAll(PDO::FETCH_ASSOC);
fs_ok(is_array($mis) && count($mis) === 1 && ($mis[0]['label'] ?? '') === 'Information' && ($mis[0]['path'] ?? '') === 'manual_quote', 'felklassningen ligger i SQL');
$pub = jeab_inkorg_misclass_public();
fs_ok(($pub[0]['surface'] ?? '') === 'felklassning' && !str_contains(json_encode($pub), '@'), 'felloggen visar felklassning utan e-post');
$noted = false;
foreach ((array) (jeab_auto_thread_by_id((string) $info['thread_id'])['drafts'] ?? []) as $d) {
    if (is_array($d) && str_contains((string) ($d['body'] ?? ''), 'Felklassning')) {
        $noted = true;
    }
}
fs_ok($noted, 'felklassningen syns som anteckning');

$reclass = jeab_inkorg_ingest([
    'message_id' => 'reclass@form.test',
    'from' => 'eva.reclass@exempel.se',
    'from_name' => 'Eva Reclass',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert batteri',
    'body_text' => "Hej, vi vill köpa och installera ett batteri. Kan vi få pris?\n",
    'source' => 'graph-jarfalla',
    'preset_labels' => $infoLabels,
    'test' => true,
    'received_at' => date('c'),
]);
jeab_inkorg_thread_write((string) $reclass['thread_id'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'inkorg', 'date' => date('Y-m-d'), 'product' => 'batteri'];
    return $t;
});
$sentAtReclass = count($GLOBALS['_SENT']);
$re = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads', 'dry' => 0, 'days' => 14]);
fs_ok(($re['ok'] ?? false) === true && count($GLOBALS['_SENT']) === $sentAtReclass, 'omklassning skickar ingen offert');
$already = false;
foreach ((array) ($re['rows'] ?? []) as $row) {
    if (($row['thread_id'] ?? '') === ($reclass['thread_id'] ?? '') && !empty($row['quote_already']) && ($row['would_send'] ?? true) === false) {
        $already = true;
    }
}
fs_ok($already, 'tråd som redan fått offert markeras och skickas inte om');

$autoLead = $ingest('auto-1', 'noreply@wix.com', 'Theowhiz', 'Eja Auto', 'eja.auto@exempel.se', $leadLabels);
$autoRes = jeab_auto_on_message((string) $autoLead['id']);
fs_ok(($autoRes['action'] ?? '') === 'quoted', 'Ny lead skickar offert');
$autoLog = jeab_inkorg_hook(['action' => 'inbox.sent_log', 'domain' => 'exempel.se']);
$autoRow = null;
foreach ((array) ($autoLog['rows'] ?? []) as $row) {
    if (($row['thread_id'] ?? '') === (string) $autoLead['thread_id']) {
        $autoRow = $row;
    }
}
fs_ok(is_array($autoRow) && ($autoRow['via'] ?? '') === 'auto' && ($autoRow['actor'] ?? '') === 'system', 'sent_log visar auto och system');
$old = jeab_inbox_sent_infer([
    'drafts' => [[
        'kind' => 'note',
        'author' => 'Grok (automatik)',
        'source' => 'grok-auto',
        'body' => 'Offert (Batteri) skickad automatiskt från saljare3@jarfalla.example.test till kunden.',
        'created_at' => date('c'),
    ]],
], date('c'));
fs_ok(($old['via'] ?? '') === 'unknown' && ($old['actor'] ?? '') === 'unknown', 'anteckningen skickad automatiskt skiljer inte auto från manuell Skicka');
$human = jeab_inbox_sent_infer([
    'drafts' => [[
        'kind' => 'reply',
        'sent' => true,
        'author' => 'Elin',
        'source' => 'manual',
        'body' => 'Hej, här är ett svar.',
        'sent_at' => date('c'),
    ]],
], date('c'));
fs_ok(($human['via'] ?? '') === 'manual' && ($human['actor'] ?? '') === 'Elin', 'ett skickat svar med person som avsändare är manuellt');

echo "OK  form_sender_thread\n";
