<?php

declare(strict_types=1);

/** Systemmejl via Inkorgen (inte Help Scout) och automatisk ombokning med Grok (mockad). */

$tmp = sys_get_temp_dir() . '/jeab-rebook-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
$GLOBALS['_JEAB_SEND_GUARD_REPLAY'] = true;

function rb(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_GRAPH'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) {
    $GLOBALS['_GRAPH'][] = [$method, $url, $body];
    if ($method === 'POST' && (str_ends_with($url, '/sendMail') || str_ends_with($url, '/reply'))) {
        if (str_ends_with($url, '/sendMail')) {
            $GLOBALS['_LAST_REF'] = (string) ($body['message']['internetMessageHeaders'][0]['value'] ?? '');
        }
        return ['code' => 202, 'body' => []];
    }
    if ($method === 'GET' && str_contains($url, 'sentitems')) {
        return ['code' => 200, 'body' => ['value' => [['id' => 'S1', 'conversationId' => 'CONV-OUT', 'internetMessageId' => '<out1@x>', 'internetMessageHeaders' => [['name' => 'X-JEAB-Ref', 'value' => $GLOBALS['_LAST_REF'] ?? '']]]]]];
    }
    if ($method === 'GET' && str_contains($url, '/messages/R1')) {
        return ['code' => 200, 'body' => $GLOBALS['_INBOUND']];
    }
    return ['code' => 500, 'body' => []];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
@mkdir(jeab_inkorg_dir(), 0775, true);
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['helpscout_companies' => []]));
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));

// 1. Help Scout skrivs aldrig.
$hs = jeab_email_send('kund@ex.se', 'x', 'y');
rb(empty($hs['ok']) && str_contains((string) $hs['error'], 'Help Scout används inte'), 'Help Scout-skrivning är avstängd');

// 2. Systemmejl → Graph från kontakt@ + stängt ärende, kopplat till konversationen.
$sent = jeab_system_mail_send(['to' => 'kund@ex.se', 'subject' => 'Installation av laddbox', 'text' => 'Hej', 'company' => 'malmo', 'from_email' => 'contact@malmo.example.test', 'job_type' => 'laddbox', 'tags' => ['kalender']]);
rb(!empty($sent['ok']) && $sent['path'] === 'inkorg', 'systemmejl går via Inkorgen');
$send = array_values(array_filter($GLOBALS['_GRAPH'], fn($c) => str_ends_with($c[1], '/sendMail')));
rb(str_contains($send[0][1], 'kontakt%40malmo.example.test'), 'Malmö skickas från kontakt@malmo.example.test');
$meta = jeab_inkorg_thread_meta((string) $sent['id']);
rb(($meta['status'] ?? '') === 'closed', 'ärendet stängs som i Help Scout');
rb((jeab_graph_state()['conv']['CONV-OUT'] ?? '') === $sent['id'], 'kundens svar hamnar i samma ärende');

// 3. Ombokning.
$store = jeab_store();
$tz = new DateTimeZone('Europe/Stockholm');
$d = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->modify('+20 days');
while (!jeab_ordrar_is_workday($d)) {
    $d = $d->modify('+1 day');
}
$b = jeab_create_booking($store, 'Test Testsson', ['Kim'], $d->format('Y-m-d') . ' 07:00:00', $d->format('Y-m-d') . ' 11:00:00', 'laddbox', ['email' => 'demo@example.test', 'address' => 'Testvägen 12, Järfälla', 'company' => 'malmo']);
$other = jeab_create_booking($store, 'Annan', ['Kim'], $d->modify('+1 day')->format('Y-m-d') . ' 07:00:00', $d->modify('+1 day')->format('Y-m-d') . ' 11:00:00', 'laddbox', ['email' => 'x@ex.se', 'company' => 'malmo']);
$want = $d->modify('+1 day');
while (!jeab_ordrar_is_workday($want)) {
    $want = $want->modify('+1 day');
}

$inbound = function (string $id, string $text) {
    $GLOBALS['_INBOUND'] = ['id' => 'R1', 'internetMessageId' => '<' . $id . '@kund>', 'conversationId' => 'CONV-IN', 'from' => ['emailAddress' => ['address' => 'demo@example.test']],
        'toRecipients' => [['emailAddress' => ['address' => 'kontakt@malmo.example.test']]], 'subject' => 'SV: Installation', 'receivedDateTime' => date('c'), 'body' => ['content' => $text]];
    return jeab_graph_ingest_id('malmo', 'R1');
};
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => json_encode(['type' => 'Bokning/ombokning', 'action' => 'svara', 'priority' => 2, 'confidence' => 90]);

// Av: inget händer.
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['reschedule' => true, 'confidence' => 95, 'proposals' => [['date' => $want->format('Y-m-d'), 'time' => '', 'part' => 'fm']]]);
$r = $inbound('m0', 'Tiden passar inte');
rb(($r['rebook']['action'] ?? '') === 'off', 'brytaren av: ingen ombokning');

jeab_rebook_settings_save(['rebook_enabled' => 1, 'rebook_only_from' => 'demo@example.test', 'rebook_prompt' => '']);
// Föreslagen tid upptagen (Kim har annan bokning) → erbjud tider.
$busyDay = $d->modify('+1 day');
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['reschedule' => true, 'confidence' => 95, 'proposals' => [['date' => $busyDay->format('Y-m-d'), 'time' => '07:00', 'part' => '']], 'reason' => 'kunden vill flytta']);
$r = $inbound('m1', 'Tiden passar inte, kan ni dagen efter kl 7?');
rb(($r['rebook']['action'] ?? '') === 'offered' && count($r['rebook']['offered']) >= 2, 'upptagen tid → 2–3 nya tider erbjuds');
$tid = (string) $r['thread_id'];
$m = jeab_inkorg_thread_meta($tid);
rb(($m['status'] ?? '') === 'waiting_customer', 'ärendet väntar på kund');
rb((bool) array_filter((array) $m['drafts'], fn($x) => ($x['kind'] ?? '') === 'note'), 'anteckning i ärendet');
rb(jeab_get_booking($store, (int) $b['id'])['starts_at'] === $b['starts_at'], 'bokningen orörd tills kunden valt');
foreach ($m['rebook']['offered'] as $o) {
    rb(substr($o['starts_at'], 11, 5) >= '07:00' && substr($o['ends_at'], 11, 5) <= '17:00' && jeab_ordrar_is_workday(new DateTimeImmutable($o['starts_at'])), 'erbjuden tid följer reglerna: ' . $o['starts_at']);
    rb(!jeab_ordrar_slot_busy([$other], 'Kim', $o['starts_at'], $o['ends_at']), 'erbjuden tid krockar inte');
}

// Kunden väljer nr 2 → flyttas, bekräftelse, stängt.
$pick = $m['rebook']['offered'][1];
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['reschedule' => true, 'confidence' => 95, 'proposals' => [], 'accepts_offer' => 2, 'reason' => 'väljer 2']);
$r = $inbound('m2', 'Nummer 2 passar bra');
rb(($r['rebook']['action'] ?? '') === 'moved', 'vald tid → bokningen flyttas');
$nb = jeab_get_booking($store, (int) $b['id']);
rb($nb['starts_at'] === $pick['starts_at'] && $nb['ends_at'] === $pick['ends_at'], 'ny tid sparad med samma längd');
$m = jeab_inkorg_thread_meta($tid);
rb(($m['status'] ?? '') === 'closed', 'ärendet stängt efter bekräftelse');
$confirm = array_values(array_filter($GLOBALS['_GRAPH'], fn($c) => str_ends_with($c[1], '/reply')));
rb(str_contains((string) (end($confirm)[2]['comment'] ?? ''), 'Vi har flyttat din tid') && str_contains((string) (end($confirm)[2]['comment'] ?? ''), 'laddbox'), 'bekräftelse i samma stil som bokningsbekräftelsen');
rb(jeab_sms_email_already_sent((int) $b['id']), 'tickern skickar inte bekräftelsen igen');

// Osäker → lämnas öppet med anteckning.
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['reschedule' => true, 'confidence' => 40, 'proposals' => [], 'reason' => 'oklart']);
$r = $inbound('m3', 'Hmm kanske');
rb(($r['rebook']['action'] ?? '') === 'hold', 'osäker → lämnas till människa');
rb((jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'open', 'ärendet öppet');

// Inte på testlistan → inget.
jeab_rebook_settings_save(['rebook_enabled' => 1, 'rebook_only_from' => 'annan@ex.se']);
$r = $inbound('m4', 'Tiden passar inte');
rb(($r['rebook']['action'] ?? '') === 'skip', 'testlistan skyddar riktiga kunder');

echo "inkorg rebook: ok\n";
