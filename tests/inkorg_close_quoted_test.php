<?php

declare(strict_types=1);

/** Stäng Järfälla-ärenden som redan har offert och påminnelse. Påhittade namn. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-close-quoted-' . bin2hex(random_bytes(4));
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

function cq(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$graphMode = 'plain';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$graphMode): array {
    unset($method);
    $decoded = rawurldecode($url);
    if (str_contains($url, 'mailFolders/sentitems') && !str_contains($url, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-box']];
    }
    if (str_contains($decoded, 'greta.falk@gmail.com')) {
        return ['code' => 500, 'body' => ['error' => ['message' => 'SECRET-GRAPH']]];
    }
    $hit = $graphMode === 'hit' && (str_contains($decoded, 'ada.ny@gmail.com') || str_contains($decoded, 'pim.vakt@gmail.com'));
    if ($hit && str_contains($decoded, '$search=')) {
        $who = str_contains($decoded, 'ada.ny@gmail.com') ? 'ada.ny@gmail.com' : 'pim.vakt@gmail.com';
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'Q-' . $who,
            'toRecipients' => [['emailAddress' => ['address' => $who]]],
            'ccRecipients' => [],
            'sentDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
        ]]]];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);

$lead = static function (string $id, string $email, string $name, string $company = 'jarfalla'): array {
    $box = jeab_graph_mailboxes()[$company];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@close.test',
        'from' => $email,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Batteri till ' . $name,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-100 20 40\nAdress: Solvägen 4, 176 70 Järfälla\nHej, jag vill ha ett batteri.\n",
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'thread_key' => 'cq-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
};
$quoteNote = static function (string $tid): void {
    jeab_inkorg_thread_write($tid, static function (array $t): array {
        $drafts = array_values((array) ($t['drafts'] ?? []));
        $drafts[] = [
            'id' => 'd-quote-' . bin2hex(random_bytes(4)),
            'kind' => 'note',
            'body' => 'Offert (Batteri) skickad till kunden.',
            'state' => 'note',
            'author' => 'Test',
            'source' => 'manual',
            'created_at' => date('c'),
        ];
        $t['drafts'] = $drafts;
        return $t;
    });
};
$reopen = static function (string $tid): void {
    try {
        jeab_inkorg_thread_write($tid, static function (array $t): array {
            $t['status'] = 'open';
            unset($t['hook_closed']);
            return $t;
        });
    } catch (Throwable $e) {
        unset($e);
    }
};
$remind = static function (string $tid, string $name, string $email, string $note = '') use ($reopen): array {
    $line = 'mail: ' . jeab_lead_mail_url($tid);
    $text = trim($note === '' ? $line : $note . "\n" . $line);
    $row = jeab_create_reminder(jeab_store(), $name . ' – Batteri', jeab_today_start(), null, null, [
        'customer' => $name,
        'email' => $email,
        'company' => 'jarfalla',
        'who' => 'Roberto',
        'note' => $text,
    ]);
    // Skapad påminnelse stänger öppna träffar. close_quoted testar sin egen regel på ett öppet ärende.
    $reopen($tid);
    return $row;
};
$rowOf = static function (array $res, string $tid): array {
    foreach ((array) ($res['threads'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $tid) {
            return $row;
        }
    }
    return [];
};
$statusOf = static function (string $tid): string {
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
};
$inView = static function (string $tid, string $view): bool {
    $thread = jeab_auto_thread_by_id($tid);
    $views = array_column(jeab_inkorg_views(), null, 'id');
    return is_array($thread) && isset($views[$view]) && jeab_inkorg_thread_matches($thread, (array) $views[$view]['filter']);
};

$mira = $lead('mira', 'mira.holm@gmail.com', 'Mira Holm');
$miraTid = (string) $mira['thread_id'];
$quoteNote($miraTid);
$remind($miraTid, 'Mira Holm', 'mira.holm@gmail.com');

$vera = $lead('vera', 'vera.lund@gmail.com', 'Vera Lund');
$veraTid = (string) $vera['thread_id'];
$quoteNote($veraTid);
$veraRem = $remind($veraTid, 'Vera Lund', 'vera.lund@gmail.com');
jeab_toggle_reminder(jeab_store(), (int) $veraRem['id'], true);

$cleo = $lead('cleo', 'cleo.voss@gmail.com', 'Cleo Voss');
$cleoTid = (string) $cleo['thread_id'];
$quoteNote($cleoTid);
$cleoRem = jeab_create_reminder(jeab_store(), 'Cleo Voss – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Cleo Voss',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Ring och följ upp offerten.',
]);
jeab_store()->saveReminder((int) $cleoRem['id'], ['contact_id' => 'kont-cleo']);
jeab_inkorg_thread_write($cleoTid, static function (array $t): array {
    $t['contact_id'] = 'kont-cleo';
    return $t;
});

$nils = $lead('nils', 'nils.berg@gmail.com', 'Nils Berg');
$nilsTid = (string) $nils['thread_id'];
$quoteNote($nilsTid);

$otto = $lead('otto', 'otto.ek@gmail.com', 'Otto Ek');
$ottoTid = (string) $otto['thread_id'];
$remind($ottoTid, 'Otto Ek', 'otto.ek@gmail.com');

$greta = $lead('greta', 'greta.falk@gmail.com', 'Greta Falk');
$gretaTid = (string) $greta['thread_id'];
$remind($gretaTid, 'Greta Falk', 'greta.falk@gmail.com');

$siri = $lead('siri', 'siri.malm@gmail.com', 'Siri Malm', 'malmo');
$siriTid = (string) $siri['thread_id'];
$quoteNote($siriTid);
$remind($siriTid, 'Siri Malm', 'siri.malm@gmail.com');

$junk = $lead('junk', 'testkund@example.com', 'Testkund Exempel');
$junkTid = (string) $junk['thread_id'];
$quoteNote($junkTid);
$remind($junkTid, 'Testkund Exempel', 'testkund@example.com');

$noa = $lead('noa', 'noa.berg@gmail.com', 'Noa Berg');
$noaTid = (string) $noa['thread_id'];
$quoteNote($noaTid);
$remind($noaTid, 'Noa Berg', 'noa.berg@gmail.com');
jeab_create_reminder(jeab_store(), 'Lo Berg – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Lo Berg',
    'email' => 'lo.berg@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($noaTid),
]);
$reopen($noaTid);

$before = count($GLOBALS['_SENT']);
$alias = jeab_inkorg_hook(['action' => 'inkorg.close_quoted']);
$dry = jeab_inkorg_hook(['action' => 'inbox.close_quoted']);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
cq(($alias['dry'] ?? 0) === 1 && ($dry['dry'] ?? 0) === 1 && ($alias['counts'] ?? null) === ($dry['counts'] ?? null), 'inkorg.close_quoted är samma anrop och dry=1 är standard');
cq(($dry['ok'] ?? false) === true && !str_contains($dryJson, '@') && !str_contains($dryJson, 'SECRET'), 'hooksvaret har ingen adress');
cq(($rowOf($dry, $miraTid)['reason'] ?? '') === 'offert och påminnelse' && $statusOf($miraTid) === 'open', 'torr körning stänger inte offert och påminnelse');
cq(($rowOf($dry, $veraTid)['reason'] ?? '') === 'offert och påminnelse', 'klar påminnelse räknas');
cq(($rowOf($dry, $cleoTid)['reason'] ?? '') === 'offert och påminnelse', 'kontakt utan mail-rad räknas');
cq(($rowOf($dry, $nilsTid)['reason'] ?? '') === 'offert utan påminnelse' && ($dry['counts']['quote_only'] ?? 0) >= 1, 'bara offert lämnas öppen');
cq(($rowOf($dry, $ottoTid)['reason'] ?? '') === 'påminnelse utan offert' && ($dry['counts']['reminder_only'] ?? 0) >= 1, 'bara påminnelse lämnas öppen');
cq(($rowOf($dry, $gretaTid)['reason'] ?? '') === 'källa oläsbar' && ($dry['counts']['source_error'] ?? 0) >= 1, 'Graph-fel lämnas öppet');
cq(($rowOf($dry, $siriTid)['reason'] ?? '') === 'malmö är skrivskyddad' && ($rowOf($dry, $siriTid)['status'] ?? 0) === 403, 'Malmö listas som skrivskyddad');
cq(($rowOf($dry, $junkTid)['reason'] ?? '') === 'ser ut som skräp' && ($dry['counts']['junk'] ?? 0) >= 1, 'skräp lämnas öppet');
cq(($rowOf($dry, $noaTid)['reason'] ?? '') === 'flera kunder' && ($dry['counts']['multiple_customers'] ?? 0) >= 1, 'flera kunder lämnas öppet');
cq(count($GLOBALS['_SENT']) === $before, 'genomgången skickar inget');

$live = jeab_inkorg_hook(['action' => 'inbox.close_quoted', 'dry' => 0]);
$liveJson = (string) json_encode($live, JSON_UNESCAPED_UNICODE);
cq(($live['dry'] ?? 1) === 0 && !str_contains($liveJson, '@') && !str_contains($liveJson, 'SECRET'), 'dry=0 skriver ingen adress');
cq($statusOf($miraTid) === 'closed' && $statusOf($veraTid) === 'closed' && $statusOf($cleoTid) === 'closed', 'offert och påminnelse stängs');
cq($statusOf($nilsTid) === 'open' && $statusOf($ottoTid) === 'open' && $statusOf($gretaTid) === 'open' && $statusOf($junkTid) === 'open' && $statusOf($noaTid) === 'open' && $statusOf($siriTid) === 'open', 'osäkra ärenden är öppna');
$miraThread = jeab_auto_thread_by_id($miraTid);
cq(is_array($miraThread) && empty($miraThread['deleted']) && count((array) $miraThread['messages']) >= 1, 'stängt ärende finns kvar');
cq(!$inView($miraTid, 'v-oppna') && !$inView($miraTid, 'v-vantar-offert') && !$inView($miraTid, 'v-batteri-oppna') && !$inView($miraTid, 'v-stangda') && $inView($miraTid, 'v-historik'), 'stängt ärende syns i Historik');
cq($inView($nilsTid, 'v-oppna') && !$inView($nilsTid, 'v-vantar-offert') && $inView($ottoTid, 'v-vantar-offert'), 'bara offert ligger kvar öppet, bara påminnelse väntar på offert');
cq(jeab_inkorg_thread_matches($miraThread, ['q' => 'Batteri till Mira Holm']), 'stängt ärende går att söka fram');
$mailHref = 'https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode($miraTid);
$miraRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (str_contains((string) ($rem['note'] ?? ''), $mailHref)) {
        $miraRem = $rem;
    }
}
cq(is_array($miraRem) && str_contains((string) $miraRem['note'], $mailHref), 'påminnelsens mail-länk pekar på ärendet');

$wrote = false;
try {
    jeab_inkorg_thread_set($siriTid, 'closed', null);
    $wrote = true;
} catch (JeabInkorgWriteException $e) {
    cq(jeab_inkorg_error_status($e) === 403 && $statusOf($siriTid) === 'open', 'Malmö-tråd tar inte emot stängning');
}
cq($wrote === false, 'Malmö skrev inget');

jeab_inkorg_ingest([
    'message_id' => 'mira-reply@close.test',
    'from' => 'mira.holm@gmail.com',
    'from_name' => 'Mira Holm',
    'to' => jeab_graph_mailboxes()['jarfalla'],
    'subject' => 'Re: Batteri till Mira Holm',
    'body_text' => 'Tack, jag har en följdfråga om batteriet.',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => jeab_graph_mailboxes()['jarfalla'],
    'thread_key' => 'cq-mira',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
cq($statusOf($miraTid) === 'open', 'kundsvar öppnar det stängda ärendet');
$again = jeab_inkorg_hook(['action' => 'inbox.close_quoted', 'dry' => '0']);
cq($statusOf($miraTid) === 'open' && ($rowOf($again, $miraTid)['reason'] ?? '') === 'kunden har svarat', 'omkörning stänger inte ett återöppnat ärende');

$graphMode = 'hit';
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
$pim = $lead('pim', 'pim.vakt@gmail.com', 'Pim Vakt');
$pimTid = (string) $pim['thread_id'];
$remind($pimTid, 'Pim Vakt', 'pim.vakt@gmail.com');
cq($inView($pimTid, 'v-vantar-offert') && $statusOf($pimTid) === 'open', 'omkollens lead ligger öppet i Väntar på offert');
$dryRecheck = jeab_inkorg_hook(['action' => 'inbox.waiting_recheck']);
cq(($dryRecheck['dry'] ?? 0) === 1 && $statusOf($pimTid) === 'open', 'torr omkoll stänger inget');
$liveRecheck = jeab_inkorg_hook(['action' => 'inbox.waiting_recheck', 'dry' => 0]);
$liveRecheckJson = (string) json_encode($liveRecheck, JSON_UNESCAPED_UNICODE);
$hitThreads = array_column((array) ($liveRecheck['hits'] ?? []), 'thread');
cq(in_array($pimTid, $hitThreads, true) && $statusOf($pimTid) === 'closed' && !$inView($pimTid, 'v-vantar-offert') && !$inView($pimTid, 'v-oppna') && !str_contains($liveRecheckJson, '@'), 'omkoll stänger när både offert och påminnelse finns');

$ada = $lead('ada', 'ada.ny@gmail.com', 'Ada Ny');
$adaTid = (string) $ada['thread_id'];
$sentBefore = count($GLOBALS['_SENT']);
$adaAuto = jeab_auto_on_message((string) $ada['id']);
cq(($adaAuto['action'] ?? '') === 'hold' && $statusOf($adaTid) === 'closed' && count($GLOBALS['_SENT']) === $sentBefore, 'nytt mejl med offert och påminnelse stängs utan utskick');
$adaRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (mb_strtolower((string) ($rem['email'] ?? '')) === 'ada.ny@gmail.com') {
        $adaRem = $rem;
    }
}
cq(is_array($adaRem) && str_contains((string) $adaRem['note'], 'mail: ' . jeab_lead_mail_url($adaTid)), 'det nya mejlet har en mail-länk');

echo "OK  close-quoted\n";
