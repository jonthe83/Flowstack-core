<?php

declare(strict_types=1);

/** Vallentuna-service blir ingen påminnelse. Ett vanligt lead får fortfarande en. */

$tmp = sys_get_temp_dir() . '/jeab-service-rem-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sr(bool $ok, string $m): void
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
$GLOBALS['_JEAB_INKORG_LLM'] = static fn (): string => '{"type":"Ny lead","intent":"kop","offer":"laddbox","confidence":92,"product":"elbilsladdare","question":"","reason":"laddning"}';

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'follow_receipt_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);

$vallBody = "Hej,\nAnläggningen från i somras i Vallentuna har ingen ödrift. Laddningen är långsam och Sungrow felsöker på distans.\n";
$vallSubject = 'Vallentuna: ödrift och laddhastighet';
$leadBody = "Hej, vi vill ha offert på en elbilsladdare till villan på Testvägen 8 i Järfälla.\nNamn: Bo Lead\nE-post: bo.lead@exempel.se\n";

$prompt = jeab_inkorg_llm_prompt(['from' => 'kund@exempel.se', 'subject' => 'Hej', 'body' => 'En fråga.']);
sr(str_contains((string) ($prompt[0]['content'] ?? ''), 'utan köpintresse är type Service'), 'Grok-prompten säger Service, inte lead');
sr(str_contains(jeab_auto_intent_text(), 'type Service'), 'instruktionen i automatiken säger Service');
sr(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.service_reminders_scan'), 'regeln beskriver läs-hooken');

$classified = jeab_inkorg_classify([
    'from' => 'eva.vallentuna@exempel.se',
    'from_name' => 'Eva Vall',
    'subject' => $vallSubject,
    'body' => $vallBody,
]);
sr(($classified['type'] ?? '') === 'Service' && ($classified['intent'] ?? '') === 'support' && ($classified['offer'] ?? '') === '', 'Vallentuna-texten blir Service även när Grok säger Ny lead');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $from, string $subject, string $body) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@service.test',
        'from' => $from,
        'from_name' => 'Kund',
        'to' => $box,
        'subject' => $subject,
        'date' => '2026-10-18T09:12:00+02:00',
        'received_at' => '2026-10-18T09:12:00+02:00',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
    ]);
};

$sentBefore = count($GLOBALS['_SENT']);
$live = $ingest('vall', 'eva.vallentuna@exempel.se', $vallSubject, $vallBody);
sr(($live['labels']['type'] ?? '') === 'Service', 'intaget sparar Service');
$auto = jeab_auto_on_message((string) $live['id']);
sr(count($GLOBALS['_SENT']) === $sentBefore, 'Vallentuna skickar ingen offert och inget kvitto');
sr(count(jeab_list_reminders(jeab_store(), true)) === 0, 'Vallentuna-texten skapar ingen påminnelse');
sr(($auto['action'] ?? '') !== 'quoted', 'automatiken offererar inte serviceärendet');

$preset = jeab_inkorg_ingest([
    'message_id' => 'preset-vall@service.test',
    'from' => 'preset.vallentuna@exempel.se',
    'from_name' => 'Preset',
    'to' => $box,
    'subject' => $vallSubject,
    'body_text' => $vallBody,
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'confidence' => 99],
]);
sr(($preset['labels']['type'] ?? '') === 'Service', 'förinställd Ny lead viker för service-texten');
jeab_auto_on_message((string) $preset['id']);
sr(count(jeab_list_reminders(jeab_store(), true)) === 0, 'förinställd Ny lead på Vallentuna ger ingen påminnelse');

$fake = [
    'id' => 'fake-vall',
    'status' => 'open',
    'assignee' => '',
    'tags' => [],
    'first' => [
        'from' => 'eva.vallentuna@exempel.se',
        'subject' => $vallSubject,
        'body' => $vallBody,
        'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'laddbox', 'confidence' => 95],
    ],
];
$forced = jeab_lead_process($fake, true);
sr(($forced['lead'] ?? true) === false && count(jeab_list_reminders(jeab_store(), true)) === 0, 'spärren håller även när klassen fortfarande är Ny lead');
$quote = jeab_auto_quote($fake, $fake['first'], jeab_auto_settings(), [], ['dry' => true]);
sr(($quote['action'] ?? '') === 'skip' && count($GLOBALS['_SENT']) === $sentBefore, 'offert skapas inte för service');
sr(jeab_follow_wants_receipt($fake['first']) === false, 'service får inget kvitto');

foreach (['Support', 'Reklamation', 'Fel', 'Oklassad'] as $blocked) {
    $row = $fake;
    $row['id'] = 'fake-' . $blocked;
    $row['first']['labels'] = ['type' => $blocked, 'intent' => 'kop', 'offer' => 'laddbox'];
    $row['first']['body'] = 'Vi vill ha offert på en laddbox.';
    $row['first']['subject'] = 'Offert';
    $got = jeab_lead_process($row, true);
    sr(($got['lead'] ?? true) === false, $blocked . ' får ingen påminnelse');
}
sr(count(jeab_list_reminders(jeab_store(), true)) === 0, 'spärrade klasser skapade inga påminnelser');

$leadIn = $ingest('lead', 'bo.lead@exempel.se', 'Offert elbilsladdare', $leadBody);
sr(($leadIn['labels']['type'] ?? '') === 'Ny lead', 'vanligt lead förblir Ny lead');
$leadAuto = jeab_auto_on_message((string) $leadIn['id']);
$leadRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'bo.lead@exempel.se') {
        $leadRem = $row;
    }
}
sr(is_array($leadRem), 'ett vanligt lead skapar fortfarande en påminnelse');
sr(count(jeab_list_reminders(jeab_store(), true)) === 1, 'bara leadet blev en påminnelse');
sr(!in_array((string) ($leadAuto['action'] ?? ''), ['quoted'], true) || count($GLOBALS['_SENT']) === $sentBefore, 'avstängd offert skickar inget till leadet');

$store = jeab_store();
sr($store instanceof JeabPdoStore, 'lagret är SQL');
$skips = $store->pdo()->query('SELECT thread_id, label, reason, path FROM inbox_service_skip')->fetchAll(PDO::FETCH_ASSOC);
sr(is_array($skips) && $skips !== [], 'hoppen ligger i SQL');
$sawVall = false;
foreach ($skips as $skip) {
    $blob = json_encode($skip, JSON_UNESCAPED_UNICODE);
    sr(!str_contains((string) $blob, '@'), 'loggen har ingen adress');
    if ((string) ($skip['thread_id'] ?? '') === (string) ($live['thread_id'] ?? '') || (string) ($skip['thread_id'] ?? '') === 'fake-vall') {
        $sawVall = true;
        sr(str_contains((string) ($skip['reason'] ?? ''), 'köpintresse') || str_contains((string) ($skip['reason'] ?? ''), 'klassen är'), 'orsaken säger varför');
    }
}
sr($sawVall, 'Vallentuna-hoppet är loggat');
sr(!is_file($tmp . '/inkorg/service-skip.json') && !is_file($tmp . '/.private/inkorg/service-skip.json'), 'ingen JSON-fil för hoppen');

$bad = jeab_create_reminder($store, 'Eva – service', '2026-10-18 09:00:00', 'fel på ödrift', null, [
    'customer' => 'Eva Vall',
    'email' => 'eva.vallentuna@exempel.se',
    'address' => 'Solvägen 4, Vallentuna',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'thread_id' => (string) $live['thread_id'],
]);
$badId = (int) ($bad['id'] ?? 0);
sr($badId > 0 && empty($bad['completed_at']), 'öppen servicepåminnelse finns att läsa');
$openBefore = $bad;

$scan = jeab_inkorg_hook(['action' => 'inbox.service_reminders_scan']);
sr(($scan['ok'] ?? false) === true && (int) ($scan['dry'] ?? 0) === 1 && (int) ($scan['closed'] ?? 1) === 0 && ($scan['would_send'] ?? true) === false, 'hooken är torr och stänger inget');
$ids = [];
foreach ((array) ($scan['rows'] ?? []) as $row) {
    sr(isset($row['id'], $row['address'], $row['seller'], $row['date']), 'raden har id, adress, säljare och datum');
    sr(!isset($row['email']) && !isset($row['phone']), 'raden har ingen e-post');
    $ids[] = (int) ($row['id'] ?? 0);
    if ((int) ($row['id'] ?? 0) === $badId) {
        sr(str_contains((string) $row['address'], 'Solvägen 4'), 'adressen följer med');
        sr(str_contains((string) $row['seller'], 'Elin'), 'säljaren följer med');
        sr((string) $row['date'] === '2026-10-18', 'datumet följer med');
    }
}
sr(in_array($badId, $ids, true), 'servicepåminnelsen listas');
sr(!in_array((int) ($leadRem['id'] ?? 0), $ids, true), 'leadpåminnelsen listas inte');

$still = jeab_get_reminder($store, $badId);
sr(is_array($still) && empty($still['completed_at']) && (string) ($still['completed_at'] ?? '') === (string) ($openBefore['completed_at'] ?? ''), 'läsningen lämnade påminnelsen öppen');
$denied = jeab_inkorg_hook(['action' => 'inbox.service_reminders_scan', 'dry' => 0]);
sr(($denied['ok'] ?? true) === false && ($denied['rows'] ?? [1]) === [] && (int) ($denied['closed'] ?? 1) === 0, 'dry=0 nekas');
$after = jeab_get_reminder($store, $badId);
sr(is_array($after) && empty($after['completed_at']), 'dry=0 stänger inget');

echo "inkorg-service-reminder: ok\n";
