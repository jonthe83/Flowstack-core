<?php

declare(strict_types=1);

/** Bekräftad omsändning: kö, fortsättning, tick, orsak och retry. Skickar inget utanför mocken. */

$tmp = sys_get_temp_dir() . '/jeab-resend-outcome-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/m365.json', json_encode([
    'tenant_id' => 'tenant',
    'client_id' => 'client',
    'client_secret' => 'secret',
]));
require dirname(__DIR__) . '/src/bootstrap.php';

function oc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    if (!empty($GLOBALS['_JEAB_RESEND_GRAPH_SLOW']) && function_exists('jeab_resend_profile_add')) {
        jeab_resend_profile_add('graph', 1900);
    }
    if ($method === 'POST') {
        $GLOBALS['_SENT'][] = ['method' => $method, 'url' => $url];

        return ['code' => 202, 'body' => []];
    }

    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    $n = count($GLOBALS['_SENT']);

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-oc-' . $n, 'message_id' => 'sent-oc-' . $n . '@local'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);

$box = jeab_graph_mailboxes()['jarfalla'];
$auth = ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=nordsolen.se'];
$ingest = static function (string $id, string $email, string $body, array $labels) use ($box, $auth): array {
    $when = gmdate('c', time() - 3600);

    return jeab_inkorg_ingest([
        'message_id' => $id . '@outcome.test',
        'from' => 'hej@nordsolen.se',
        'from_name' => 'Nordsolen',
        'to' => $box,
        'subject' => 'Lead ' . $id,
        'date' => $when,
        'received_at' => $when,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => 'oc-' . $id,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
        'headers' => $auth,
        'preset_labels' => $labels,
        'backfilled' => 1,
    ]);
};

$body = static function (string $name, string $email, string $tail): string {
    $n = hexdec(substr(md5(mb_strtolower($email)), 0, 6)) % 10000000;
    $phone = '070' . str_pad((string) $n, 7, '0', STR_PAD_LEFT);

    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Solvägen 9, 177 31 Järfälla\n{$tail}\n";
};
$leadLabels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'confidence' => 90, 'source' => 'rule', 'needs_review' => false, 'question' => ''];
$driftLabels = ['type' => 'Kund', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'confidence' => 90, 'source' => 'llm', 'needs_review' => false, 'question' => ''];
$blankLabels = ['type' => 'Kund', 'intent' => 'annat', 'offer' => '', 'lead' => '', 'confidence' => 40, 'source' => 'llm', 'needs_review' => false, 'question' => ''];

$live = $ingest('live', 'live.kund@exempel.se', $body('Live Kund', 'live.kund@exempel.se', 'Vill ha batteri.'), $leadLabels);
$tick = $ingest('tick', 'tick.kund@exempel.se', $body('Tick Kund', 'tick.kund@exempel.se', 'Vill ha batteri.'), $leadLabels);
$drift = $ingest('drift', 'drift.kund@exempel.se', $body('Drift Kund', 'drift.kund@exempel.se', 'Vill ha batteri.'), $driftLabels);
$probe = $ingest('probe', 'probe.kund@exempel.se', $body('Probe Kund', 'probe.kund@exempel.se', 'Vill ha batteri.'), $driftLabels);
$blank = $ingest('blank', 'blank.kund@exempel.se', $body('Blank Kund', 'blank.kund@exempel.se', 'Hej, jag hör av mig.'), $blankLabels);
$liveId = (string) ($live['thread_id'] ?? '');
$tickId = (string) ($tick['thread_id'] ?? '');
$driftId = (string) ($drift['thread_id'] ?? '');
$probeId = (string) ($probe['thread_id'] ?? '');
$blankId = (string) ($blank['thread_id'] ?? '');
oc($liveId !== '' && $tickId !== '' && $driftId !== '' && $probeId !== '' && $blankId !== '', 'trådarna är intagna');

$stamp = static function (string $threadId, string $type, string $intent, string $offer): void {
    $thread = jeab_auto_thread_by_id($threadId);
    $mid = (string) ($thread['messages'][0]['id'] ?? '');
    jeab_inkorg_klassning_stamp($mid, $type, $intent, $offer, true);
};
$stamp($driftId, 'Kund', 'kop', 'batteri');
$stamp($probeId, 'Kund', 'kop', 'batteri');
$stamp($blankId, 'Kund', 'annat', '');

$closed = 0;
$GLOBALS['JEAB_CLOSE_QUOTED_WATCH'] = static function () use (&$closed): void {
    $closed++;
};
$before = count($GLOBALS['_SENT']);
$GLOBALS['_JEAB_RESEND_GRAPH_SLOW'] = true;
$GLOBALS['_JEAB_RESEND_PROFILE_ON'] = true;
$GLOBALS['_JEAB_RESEND_PROFILE'] = [];
$probeRes = jeab_auto_on_message((string) (jeab_auto_thread_by_id($probeId)['messages'][0]['id'] ?? ''), true);
$probeGraph = (int) ($GLOBALS['_JEAB_RESEND_PROFILE']['graph'] ?? 0);
unset($GLOBALS['_JEAB_RESEND_PROFILE_ON'], $GLOBALS['_JEAB_RESEND_PROFILE'], $GLOBALS['_JEAB_RESEND_GRAPH_SLOW']);
oc(($probeRes['action'] ?? '') === 'none' && ($probeRes['why'] ?? '') === 'etiketten är inte Ny lead' && count($GLOBALS['_SENT']) === $before && $closed > 0 && $probeGraph >= 1900, 'utan Ny lead stannar kedjan efter Graph-stängningen och skickar inget');

$GLOBALS['_JEAB_SEND_GUARD_REPLAY'] = true;
$queuedLive = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$liveId]]);
$liveJob = (string) ($queuedLive['job'] ?? '');
$liveStatus = jeab_inkorg_hook(['action' => 'inbox.resend_status', 'job' => $liveJob]);
oc((int) ($queuedLive['dry'] ?? 1) === 0 && (int) ($queuedLive['sent'] ?? 1) === 0 && ($queuedLive['status'] ?? '') === 'queued' && count($GLOBALS['_SENT']) === $before && (int) ($liveStatus['dry'] ?? 1) === 0 && ($liveStatus['status'] ?? '') === 'queued' && (int) ($liveStatus['consumed'] ?? 1) === 0 && (string) ($liveStatus['error'] ?? 'x') === '', 'confirm köar med dry 0 och skickar inget');
jeab_inkorg_resend_after_response($liveJob);
oc(count($GLOBALS['_SENT']) === $before && (jeab_inkorg_resend_status(['job' => $liveJob])['status'] ?? '') === 'queued', 'cli-fortsättning rör inte jobbet');
$GLOBALS['_JEAB_RESEND_SAPI'] = 'fpm-fcgi';
$continued = jeab_inkorg_resend_status(['job' => $liveJob]);
jeab_inkorg_resend_after_response($liveJob);
unset($GLOBALS['_JEAB_RESEND_SAPI']);
$liveDone = jeab_inkorg_hook(['action' => 'inbox.resend_status', 'job' => $liveJob]);
$liveJson = (string) json_encode($liveDone);
$liveTo = '';
foreach (array_slice($GLOBALS['_SENT'], $before) as $call) {
    if (is_array($call) && (string) ($call['to'] ?? '') !== '') {
        $liveTo = (string) $call['to'];
    }
}
oc((string) ($continued['status'] ?? '') === 'queued' && (int) ($liveDone['sent'] ?? 0) === 1 && ($liveDone['status'] ?? '') === 'sent' && ($liveDone['action'] ?? '') === 'quoted' && (int) ($liveDone['dry'] ?? 1) === 0 && (string) ($liveDone['error'] ?? 'x') === '' && (int) ($liveDone['consumed'] ?? 0) === 1 && $liveTo === 'live.kund@exempel.se' && !str_contains($liveJson, '@'), 'fortsättningen skickar en gång och status visar sent utan torr flagga');
$afterLive = count($GLOBALS['_SENT']);
jeab_inkorg_resend_work($liveJob);
$retryLive = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'dry' => 0, 'confirm' => 'SAM', 'job' => $liveJob]);
oc(count($GLOBALS['_SENT']) === $afterLive && ($retryLive['action'] ?? '') === 'already' && (int) ($retryLive['sent'] ?? 1) === 0 && (string) ($retryLive['error'] ?? '') === 'redan skickad' && (int) ($retryLive['consumed'] ?? 0) === 1, 'förbrukat jobb med utskick skickas inte igen');

$beforeTick = count($GLOBALS['_SENT']);
$queuedTick = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$tickId]]);
$tickJob = (string) ($queuedTick['job'] ?? '');
jeab_inkorg_resend_after_response($tickJob);
$retryQueued = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'dry' => 0, 'confirm' => 'SAM', 'job' => $tickJob]);
oc(count($GLOBALS['_SENT']) === $beforeTick && ($retryQueued['job'] ?? '') === $tickJob && (int) ($retryQueued['background'] ?? 1) === 0 && (int) ($retryQueued['consumed'] ?? 1) === 0, 'retry av köat jobb startar inget andra utskick');
$pdo = jeab_inkorg_resend_pdo();
$pdo->prepare("UPDATE inkorg_resend_jobs SET status = 'running' WHERE id = ?")->execute([$tickJob]);
$drainedRunning = jeab_inkorg_resend_drain_queued();
$retryRunning = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'dry' => 0, 'confirm' => 'SAM', 'job' => $tickJob]);
oc($drainedRunning === 0 && count($GLOBALS['_SENT']) === $beforeTick && ($retryRunning['job'] ?? '') === $tickJob && (int) ($retryRunning['sent'] ?? 1) === 0, 'tick och retry rör inte ett jobb som redan är running');
$pdo->prepare("UPDATE inkorg_resend_jobs SET status = 'queued' WHERE id = ?")->execute([$tickJob]);
$drained = jeab_inkorg_resend_drain_queued();
$tickDone = jeab_inkorg_hook(['action' => 'inbox.resend_status', 'job' => $tickJob]);
$tickTo = '';
foreach (array_slice($GLOBALS['_SENT'], $beforeTick) as $call) {
    if (is_array($call) && (string) ($call['to'] ?? '') !== '') {
        $tickTo = (string) $call['to'];
    }
}
oc($drained === 1 && (int) ($tickDone['sent'] ?? 0) === 1 && ($tickDone['status'] ?? '') === 'sent' && (int) ($tickDone['dry'] ?? 1) === 0 && $tickTo === 'tick.kund@exempel.se', 'tick plockar det köade bekräftade jobbet och skickar en gång');
$afterTick = count($GLOBALS['_SENT']);
jeab_inkorg_resend_work($tickJob);
oc(count($GLOBALS['_SENT']) === $afterTick, 'samma tick-jobb tas inte en gång till');

$driftMsg = jeab_inkorg_get((string) (jeab_auto_thread_by_id($driftId)['messages'][0]['id'] ?? ''));
oc((string) ($driftMsg['labels']['type'] ?? '') === 'Kund', 'drifttråden är inte Ny lead före jobbet');
$beforeDrift = count($GLOBALS['_SENT']);
$queuedDrift = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$driftId]]);
$driftJob = (string) ($queuedDrift['job'] ?? '');
$GLOBALS['_JEAB_RESEND_SAPI'] = 'fpm-fcgi';
jeab_inkorg_resend_after_response($driftJob);
unset($GLOBALS['_JEAB_RESEND_SAPI']);
$driftDone = jeab_inkorg_hook(['action' => 'inbox.resend_status', 'job' => $driftJob]);
$driftTo = '';
foreach (array_slice($GLOBALS['_SENT'], $beforeDrift) as $call) {
    if (is_array($call) && (string) ($call['to'] ?? '') !== '') {
        $driftTo = (string) $call['to'];
    }
}
$driftAfter = jeab_inkorg_get((string) (jeab_auto_thread_by_id($driftId)['messages'][0]['id'] ?? ''));
oc((int) ($driftDone['sent'] ?? 0) === 1 && ($driftDone['action'] ?? '') === 'quoted' && (string) ($driftDone['error'] ?? 'x') === '' && $driftTo === 'drift.kund@exempel.se' && (string) ($driftAfter['labels']['type'] ?? '') === 'Ny lead', 'fortsättningen skickar trots att etiketten inte var Ny lead');

$beforeBlank = count($GLOBALS['_SENT']);
$queuedBlank = jeab_inkorg_hook(['action' => 'inbox.resend_missed', 'dry' => 0, 'confirm' => 'SAM', 'ids' => [$blankId]]);
$blankJob = (string) ($queuedBlank['job'] ?? '');
$GLOBALS['_JEAB_RESEND_GRAPH_SLOW'] = true;
$blankDone = jeab_inkorg_resend_work($blankJob);
unset($GLOBALS['_JEAB_RESEND_GRAPH_SLOW']);
$blankJson = (string) json_encode($blankDone);
oc(count($GLOBALS['_SENT']) === $beforeBlank && ($blankDone['status'] ?? '') === 'done' && ($blankDone['action'] ?? '') === 'none' && (int) ($blankDone['sent'] ?? 1) === 0 && (string) ($blankDone['error'] ?? '') === 'etiketten är inte Ny lead' && (int) ($blankDone['dry'] ?? 1) === 0 && (int) ($blankDone['consumed'] ?? 0) === 1 && (int) ($blankDone['profile']['graph'] ?? 0) >= 1900 && !str_contains($blankJson, '@'), 'jobb utan offert loggar orsaken efter Graph och är förbrukat');
jeab_inkorg_resend_work($blankJob);
$dryRetry = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'job' => $blankJob]);
oc(count($GLOBALS['_SENT']) === $beforeBlank && ($dryRetry['action'] ?? '') === 'would' && (int) ($dryRetry['dry'] ?? 0) === 1 && (string) ($dryRetry['job'] ?? 'x') === '' && (int) ($dryRetry['consumed'] ?? 0) === 1, 'torr retry skickar inget och återanvänder inte id');
$denied = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'dry' => 0, 'job' => $blankJob]);
oc(($denied['ok'] ?? true) === false && ($denied['error'] ?? '') === 'confirm' && count($GLOBALS['_SENT']) === $beforeBlank, 'retry utan confirm skickar inget');
$stamp($blankId, 'Ny lead', 'kop', 'batteri');
$retryBlank = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'dry' => 0, 'confirm' => 'SAM', 'job' => $blankJob]);
$retryJob = (string) ($retryBlank['job'] ?? '');
oc($retryJob !== '' && $retryJob !== $blankJob && ($retryBlank['action'] ?? '') === 'queued' && (int) ($retryBlank['background'] ?? 0) === 1 && (int) ($retryBlank['consumed'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $beforeBlank, 'retry köar ett nytt jobb när inget utskick finns');
jeab_inkorg_resend_work($blankJob);
oc(count($GLOBALS['_SENT']) === $beforeBlank, 'det förbrukade id:t skickar inte vid retry');
$GLOBALS['_JEAB_RESEND_SAPI'] = 'fpm-fcgi';
jeab_inkorg_resend_after_response($retryJob);
unset($GLOBALS['_JEAB_RESEND_SAPI']);
$retryDone = jeab_inkorg_hook(['action' => 'inbox.resend_status', 'job' => $retryJob]);
$blankTo = '';
foreach (array_slice($GLOBALS['_SENT'], $beforeBlank) as $call) {
    if (is_array($call) && (string) ($call['to'] ?? '') !== '') {
        $blankTo = (string) $call['to'];
    }
}
oc((int) ($retryDone['sent'] ?? 0) === 1 && $blankTo === 'blank.kund@exempel.se' && (int) ($retryDone['dry'] ?? 1) === 0 && (string) ($retryDone['error'] ?? 'x') === '', 'det nya retry-jobbet skickar en gång');
$afterRetry = count($GLOBALS['_SENT']);
$retryAgain = jeab_inkorg_hook(['action' => 'inbox.resend_retry', 'dry' => 0, 'confirm' => 'SAM', 'job' => $retryJob]);
jeab_inkorg_resend_after_response($retryJob);
oc(count($GLOBALS['_SENT']) === $afterRetry && ($retryAgain['action'] ?? '') === 'already', 'en andra retry efter utskick skickar inte');

echo "resend job outcome: ok\n";
