<?php

declare(strict_types=1);

/** Söndagens lead utan offert: 48-timmarskatchup, Weply=eget, torr lista och bekräftad omsändning. */

$tmp = sys_get_temp_dir() . '/jeab-sunday-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sun(bool $ok, string $m): void
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
    $n = count($GLOBALS['_SENT']);

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-sun-' . $n, 'message_id' => 'sent-sun-' . $n . '@local'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 laddbox')]);

sun(jeab_test_submission_marked(['body' => "Latest: 1\nVill ha batteri.\n"]) === false, 'Latest: 1 är inte en testflagga');
sun(!jeab_lead_bought_partner('support@weply.se') && jeab_lead_message_is_own(['from' => 'support@weply.se']), 'Weply är eget');
sun(jeab_inkorg_klassning_route(['from' => 'support@weply.se', 'subject' => 'chatt', 'body' => 'Vill ha laddbox.']) !== 'egen', 'Weply arkiveras inte som personalpost');

$box = jeab_graph_mailboxes()['jarfalla'];
$auth = static function (string $domain): array {
    return ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=' . $domain];
};
$ingest = static function (string $id, string $from, string $subject, string $body, array $labels, int $age, int $backfilled = 0) use ($box, $auth): array {
    $domain = (string) substr(strrchr($from, '@') ?: '', 1);
    $when = gmdate('c', time() - $age);

    return jeab_inkorg_ingest([
        'message_id' => $id . '@sunday.test',
        'from' => $from,
        'from_name' => 'Lead',
        'to' => $box,
        'subject' => $subject,
        'date' => $when,
        'received_at' => $when,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => 'sun-' . $id,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
        'headers' => $auth($domain),
        'preset_labels' => $labels,
        'backfilled' => $backfilled,
    ]);
};

$lateBody = "Namn: Nora Batteri\nE-post: nora.batteri@exempel.se\nTelefon: 070-111 22 33\nAdress: Solvägen 1, 177 31 Järfälla\nVill ha batteri.\n";
$labels = ['type' => 'Ny lead', 'intent' => 'annat', 'offer' => '', 'lead' => 'kopt', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''];
$before = count($GLOBALS['_SENT']);
$late = $ingest('late', 'hej@nordsolen.se', 'Ny lead batteri', $lateBody, $labels, 20 * 3600);
sun(!empty($late['ok']), '20-timmarslead intaget');
$lateAuto = jeab_auto_on_message((string) $late['id']);
$lateTo = (string) (($GLOBALS['_SENT'][$before]['to'] ?? ''));
sun(($lateAuto['why'] ?? '') !== 'lead utan köpintresse' && ($lateAuto['why'] ?? '') !== 'backfilled', 'sent lead stoppas inte som ointresse eller backfill');
sun(($lateAuto['action'] ?? '') === 'quoted' && $lateTo === 'nora.batteri@exempel.se', 'offert går till kunden trots att tick är 20 timmar sen');
$lateThread = null;
foreach (jeab_inkorg_threads() as $thread) {
    if ((string) ($thread['id'] ?? '') === (string) ($late['thread_id'] ?? '')) {
        $lateThread = $thread;
    }
}
sun(is_array($lateThread) && (string) ($lateThread['assignee'] ?? '') === 'Roberto', 'batteri tilldelas Roberto');
$lateRem = jeab_inkorg_thread_list_reminder_for($lateThread);
sun($lateRem > 0, 'påminnelse skapas');

$oldWhen = gmdate('Y-m-d\TH:i:s\Z', time() - 72 * 3600);
$old = jeab_graph_ingest_message('jarfalla', [
    'id' => 'OLD72',
    'internetMessageId' => '<old72@kund>',
    'conversationId' => 'C-OLD72',
    'from' => ['emailAddress' => ['address' => 'hej@nordsolen.se', 'name' => 'Nordsolen']],
    'toRecipients' => [['emailAddress' => ['address' => $box]]],
    'subject' => 'Gammal historik',
    'receivedDateTime' => $oldWhen,
    'body' => ['content' => $lateBody],
    'isDraft' => false,
    'internetMessageHeaders' => [['name' => 'Authentication-Results', 'value' => 'abc.protection.outlook.com; dmarc=pass header.from=nordsolen.se']],
], true, false);
sun(empty($old['auto']) && (int) (jeab_inkorg_get((string) ($old['id'] ?? ''))['backfilled'] ?? 0) === 1, 'mejl äldre än 48 timmar förblir backfill utan auto');

$midWhen = gmdate('Y-m-d\TH:i:s\Z', time() - 2 * 3600);
$mid = jeab_graph_ingest_message('jarfalla', [
    'id' => 'MID2',
    'internetMessageId' => '<mid2@kund>',
    'conversationId' => 'C-MID2',
    'from' => ['emailAddress' => ['address' => 'hej@nordsolen.se', 'name' => 'Nordsolen']],
    'toRecipients' => [['emailAddress' => ['address' => $box]]],
    'subject' => 'Ny lead batteri två timmar',
    'receivedDateTime' => $midWhen,
    'body' => ['content' => "Namn: Mia Mid\nE-post: mia.mid@exempel.se\nTelefon: 070-222 33 44\nAdress: Midvägen 2, 177 31 Järfälla\nVill ha batteri.\n"],
    'isDraft' => false,
    'internetMessageHeaders' => [['name' => 'Authentication-Results', 'value' => 'abc.protection.outlook.com; dmarc=pass header.from=nordsolen.se']],
], true, false);
$midRow = jeab_inkorg_get((string) ($mid['id'] ?? ''));
sun(is_array($midRow) && (int) ($midRow['backfilled'] ?? 0) !== 1 && isset($mid['auto']), 'två timmar gammalt lead går in i auto');

$weplyBody = "Namn: Bo Ladd\nE-post: bo.ladd@gmail.com\nTelefon: 070-333 22 11\nAdress: Ladugatan 2, 177 31 Järfälla\nVill ha laddbox.\n";
$weplyLabels = ['type' => 'Ny lead', 'intent' => 'annat', 'offer' => '', 'lead' => 'kopt', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''];
$before = count($GLOBALS['_SENT']);
$weply = $ingest('weply', 'support@weply.se', 'Weply chatt', $weplyBody, $weplyLabels, 600);
$weplyAuto = jeab_auto_on_message((string) $weply['id']);
$weplyMsg = jeab_inkorg_get((string) $weply['id']);
$weplyTo = (string) (($GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1]['to'] ?? ''));
sun((string) (($weplyMsg['labels']['lead'] ?? '')) === 'eget', 'Weply-klassningen är eget');
sun(($weplyAuto['action'] ?? '') === 'quoted' && $weplyTo === 'bo.ladd@gmail.com' && !str_contains($weplyTo, 'weply'), 'Weply-offerten går till kunden');
sun(jeab_inkorg_klassning_route(is_array($weplyMsg) ? $weplyMsg : []) !== 'egen', 'Weply blir inte historik via egen');

$stuckLabels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'confidence' => 100, 'source' => 'rule', 'question' => ''];
$stuck = $ingest('stuck', 'hej@nordsolen.se', 'Ny lead utan kedja', "Namn: Stuck Lead\nE-post: stuck.lead@exempel.se\nVill ha batteri.\n", $stuckLabels, 10 * 3600, 1);
sun(!empty($stuck['ok']), 'backfillat lead ligger kvar');
$list = jeab_inkorg_hook(['action' => 'inbox.missing_offer', 'since' => '2026-10-25', 'dry' => 0]);
$listJson = (string) json_encode($list);
$ids = array_column((array) ($list['leads'] ?? []), 'id');
sun(($list['ok'] ?? false) === true && (int) ($list['sent'] ?? 1) === 0 && ($list['would_send'] ?? true) === false, 'listan skickar inget');
sun(in_array((string) ($stuck['thread_id'] ?? ''), $ids, true), 'backfillat lead utan påminnelse listas');
sun(!str_contains($listJson, '@') && !str_contains($listJson, 'stuck.lead'), 'listan har ingen kundtext');

$dry = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'ids' => [(string) ($stuck['thread_id'] ?? '')]]);
$dryJson = (string) json_encode($dry);
$dryRow = (array) ($dry['leads'][0] ?? []);
sun(($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? 1) === 0 && (($dryRow['action'] ?? '') === 'would'), 'omsändning är torr som standard');
sun(($dryRow['sender'] ?? '') === 'Roberto, Demo Energi Järfälla' && ($dryRow['template'] ?? '') === 'offert-batteri-jarfalla' && ($dryRow['recipient'] ?? '') === 'yes' && !str_contains($dryJson, '@'), 'torr omsändning visar avsändare, mall och mottagare utan adress');
$before = count($GLOBALS['_SENT']);
$denied = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'ids' => [(string) ($stuck['thread_id'] ?? '')]]);
sun(($denied['ok'] ?? true) === false && ($denied['error'] ?? '') === 'confirm' && count($GLOBALS['_SENT']) === $before, 'utan confirm skickas inget');
$once = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [(string) ($stuck['thread_id'] ?? '')]]);
$marked = jeab_auto_thread_by_id((string) ($stuck['thread_id'] ?? ''));
sun(($once['ok'] ?? false) === true && (int) ($once['sent'] ?? 1) === 0 && ($once['status'] ?? '') === 'queued' && count($GLOBALS['_SENT']) === $before && trim((string) ($marked['missed_resend_at'] ?? '')) !== '', 'hooken svarar queued och markerar innan något skickas');
$ran = jeab_inkorg_resend_work((string) ($once['job'] ?? ''));
sun((int) ($ran['sent'] ?? 0) === 1 && (string) (($GLOBALS['_SENT'][$before]['to'] ?? '')) === 'stuck.lead@exempel.se', 'bakgrundsjobbet skickar en gång till kunden');
$after = count($GLOBALS['_SENT']);
$twice = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [(string) ($stuck['thread_id'] ?? '')]]);
sun(count($GLOBALS['_SENT']) === $after && (($twice['leads'][0]['action'] ?? '') === 'already') && (int) ($twice['sent'] ?? 1) === 0, 'samma ärende skickas inte en gång till');
jeab_inkorg_resend_work((string) ($once['job'] ?? ''));
sun(count($GLOBALS['_SENT']) === $after, 'ett färdigt jobb skickar inte igen');

$kimBefore = 0;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (is_array($row) && (string) ($row['who'] ?? $row['assignee'] ?? '') !== '') {
        $kimBefore++;
    }
}
$fill = jeab_malmo_hs_fill('mhs-7ed4af5c57a71cf1', '424242', ['jobs' => ['laddbox']], '', 'Malmo Kund', '');
sun((int) (($fill['created'] ?? false) ? 1 : 0) === 1 || $fill['created'] === true, 'Malmö-laddbox får påminnelse även utan mejl');
sun(count($GLOBALS['_SENT']) === $after, 'tom kundadress skickar ingen Malmö-offert');

echo "sunday missed leads: ok\n";
