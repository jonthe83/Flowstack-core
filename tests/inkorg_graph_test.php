<?php

declare(strict_types=1);

/** Inkorg via Graph: intag, trådning per conversationId, Help Scout-dubblett, webhook clientState, skicka AV. */

$tmp = sys_get_temp_dir() . '/jeab-graph-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function gk(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$sent = [];
$msgs = [
    'A1' => ['id' => 'A1', 'internetMessageId' => '<a1@kund>', 'conversationId' => 'C1', 'from' => ['emailAddress' => ['address' => 'kund@ex.se', 'name' => 'Kund']], 'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]], 'subject' => 'Fråga om Elbilsladdare', 'receivedDateTime' => '2026-10-14T10:00:00Z', 'body' => ['content' => 'Hej']],
    'A2' => ['id' => 'A2', 'internetMessageId' => '<a2@kund>', 'conversationId' => 'C1', 'from' => ['emailAddress' => ['address' => 'kund@ex.se']], 'subject' => 'SV: Fråga om Elbilsladdare', 'receivedDateTime' => '2026-10-14T11:00:00Z', 'body' => ['content' => 'Igen']],
    'H1' => ['id' => 'H1', 'internetMessageId' => '<h1@kund>', 'conversationId' => 'C9', 'from' => ['emailAddress' => ['address' => 'hs@ex.se']], 'subject' => 'Batteri', 'receivedDateTime' => '2026-10-14T09:05:00Z', 'body' => ['content' => 'x']],
];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) use (&$sent, $msgs) {
    $sent[] = [$method, $url];
    if (preg_match('#/messages/([A-Z0-9]+)\?#', $url, $m)) {
        return isset($msgs[$m[1]]) ? ['code' => 200, 'body' => $msgs[$m[1]]] : ['code' => 404, 'body' => []];
    }
    if ($method === 'POST' && (str_ends_with($url, '/sendMail') || str_ends_with($url, '/reply'))) {
        $GLOBALS['_SENT_BODIES'][] = [$url, $body];
        return ['code' => 202, 'body' => []];
    }
    if ($method === 'POST' && str_ends_with($url, '/subscriptions')) {
        return ['code' => 201, 'body' => ['id' => 'sub-' . (str_contains($body['resource'], 'malmo') ? 'm' : 'j'), 'expirationDateTime' => $body['expirationDateTime']]];
    }
    return ['code' => 500, 'body' => []];
};

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
gk(jeab_graph_configured(), 'nycklar sparade');
$cs = (string) jeab_graph_config()['client_state'];
gk(strlen($cs) >= 32, 'clientState skapad');

$r1 = jeab_graph_ingest_id('jarfalla', 'A1');
$r2 = jeab_graph_ingest_id('jarfalla', 'A2');
gk(!empty($r1['ok']) && empty($r1['duplicate']), 'nytt mejl blir ärende');
gk(($r2['thread_id'] ?? '') === ($r1['thread_id'] ?? 'x'), 'samma conversationId → samma ärende');
gk(!empty(jeab_graph_ingest_id('jarfalla', 'A1')['duplicate']), 'idempotent');
$row = jeab_inkorg_get((string) $r1['id']);
gk(($row['source'] ?? '') === 'graph-jarfalla' && ($row['graph_conversation_id'] ?? '') === 'C1', 'källa + conversationId sparas');

jeab_inkorg_ingest(['message_id' => 'hs-1-2@helpscout.net', 'from' => 'hs@ex.se', 'subject' => 'Batteri', 'received_at' => '2026-10-14T09:00:00Z', 'body_text' => 'x', 'source' => 'helpscout-live', 'thread_key' => 'hs-1']);
$h = jeab_graph_ingest_id('jarfalla', 'H1');
gk(!empty($h['duplicate']) && ($h['thread_id'] ?? '') === 'hs-1', 'dubblett mot Help Scout-import tas inte in igen');
gk((jeab_graph_state()['conv']['C9'] ?? '') === 'hs-1', 'conversationId kopplas till HS-ärendet');

// Malmö ligger i Help Scout som standard (Sam 2026-10-15): ingen prenumeration, ingen inläsning.
$s0 = jeab_graph_subscriptions_ensure();
gk(!isset($s0['subs']['malmo']) && isset($s0['subs']['jarfalla']), 'Malmö i Help Scout: ingen prenumeration');
gk((jeab_graph_ingest_id('malmo', 'A1')['skipped'] ?? '') === 'helpscout', 'Malmö i Help Scout: inget läses in');
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
$s = jeab_graph_subscriptions_ensure();
gk(!empty($s['ok']) && !isset($s['subs']['malmo']) && isset($s['subs']['jarfalla']) && array_keys(jeab_graph_poll_mailboxes()) === ['jarfalla'], 'Malmö pollas aldrig, även när Help Scout-bocken är av');
$jf = (string) (jeab_graph_state()['subs']['jarfalla']['id'] ?? '');
$n = jeab_graph_notifications(['value' => [['clientState' => 'fel', 'subscriptionId' => $jf, 'resourceData' => ['id' => 'A1']], ['clientState' => $cs, 'subscriptionId' => $jf, 'resourceData' => ['id' => 'A1']], ['clientState' => $cs, 'subscriptionId' => 'sub-m', 'resourceData' => ['id' => 'A1']]]]);
gk($n['accepted'] === 1 && $n['rejected'] === 2 && $n['jobs'] === [['jarfalla', 'A1']], 'webhook kräver clientState och tar inte Malmö');

gk(jeab_inkorg_send_enabled() === true, 'skicka PÅ som standard');
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['send_enabled' => false]));
$before = count($sent);
$x = jeab_graph_send('malmo', 'kund@ex.se', 'Hej', 'Text');
gk(empty($x['ok']) && count($sent) === $before, 'inställning AV: inget anrop till Graph');
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['helpscout_companies' => []]));

$tid = (string) $r1['thread_id'];
$e = jeab_inkorg_send_in_thread($tid, ['body' => 'Svar till kund', 'send_key' => 'ab12'], 'Test');
$last = end($GLOBALS['_SENT_BODIES']);
gk(str_contains($last[0], '/users/kontakt%40jarfalla.example.test/messages/A2/reply') && $last[1]['comment'] === 'Svar till kund', 'svar = Graph reply på senaste mejlet från kontakt@jarfalla');
gk($e['to'] === 'kund@ex.se' && $e['state'] === 'sent' && jeab_inkorg_thread_meta($tid)['status'] === 'closed', 'skicka och stäng är standard');
$n = count($GLOBALS['_SENT_BODIES']);
jeab_inkorg_send_in_thread($tid, ['body' => 'Svar till kund', 'send_key' => 'ab12'], 'Test');
gk(count($GLOBALS['_SENT_BODIES']) === $n, 'dubbelklick skickar inte två gånger');
jeab_inkorg_thread_set($tid, 'open', null);
jeab_inkorg_send_in_thread($tid, ['body' => 'Till', 'status_after' => ''], 'Test');
gk(jeab_inkorg_thread_meta($tid)['status'] === 'open', 'behåll status');
jeab_inkorg_add_draft($tid, ['kind' => 'note', 'body' => 'Intern', 'status_after' => 'closed'], 'Test');
gk(jeab_inkorg_thread_meta($tid)['status'] === 'open', 'anteckning ändrar inte status');

$pw = $tmp . '/passwords-admin.json';
file_put_contents($pw, json_encode(['secret' => 'test-secret-value', 'hashes' => [['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test']]]));
putenv('JEAB_PASSWORDS_FILE=' . $pw);
jeab_auth_data(true);
require_once dirname(__DIR__) . '/tests/sam_l_login.php';
jeab_test_login_sam_l();
$nw = jeab_inkorg_send_new(['company' => 'malmo', 'to' => 'x@ex.se', 'subject' => 'Ämne', 'body' => 'Text'], 'Test');
$last = end($GLOBALS['_SENT_BODIES']);
gk(str_contains($last[0], '/users/kontakt%40malmo.example.test/sendMail') && $last[1]['message']['subject'] === 'Ämne' && $nw['from'] === 'kontakt@malmo.example.test', 'Nytt mejl från kontakt@malmo.example.test');
gk(!str_contains(json_encode(jeab_graph_status()), $cs), 'status visar inte clientState');

$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null) {
    unset($body);
    if ($method === 'PATCH' && str_contains($url, '/subscriptions/')) {
        return ['code' => 404, 'body' => ['error' => ['message' => 'not found']]];
    }
    if ($method === 'POST' && str_contains($url, '/subscriptions')) {
        return ['code' => 400, 'body' => ['error' => ['message' => "Subscription validation request failed. HTTP status code is 'Forbidden'."]]];
    }
    if (str_contains($url, '/messages/delta')) {
        return ['code' => 200, 'body' => [
            'value' => [['id' => 'GONE'], ['id' => 'BAD']],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/kontakt/messages/delta?$deltatoken=next',
        ]];
    }
    if (str_contains($url, '/messages/GONE')) {
        return ['code' => 404, 'body' => ['error' => ['message' => 'The specified object was not found.']]];
    }
    if (str_contains($url, '/messages/BAD')) {
        return ['code' => 500, 'body' => ['error' => ['message' => 'mailbox busy']]];
    }
    return ['code' => 500, 'body' => ['error' => ['message' => 'unexpected']]];
};
$bad = jeab_graph_subscriptions_ensure();
gk(empty($bad['ok']), 'prenumeration som Graph nekar är inte ok');
$st = jeab_graph_status();
$blob = (string) json_encode($st);
gk(str_contains((string) ($st['subs']['jarfalla']['error'] ?? ''), 'Forbidden'), 'status visar Graph-feltexten på prenumerationen');
gk(!isset($st['subs']['malmo']), 'Malmö är inte en misslyckad prenumeration');
gk(($st['companies']['malmo']['mode'] ?? '') === 'helpscout' && ($st['companies']['malmo']['polled'] ?? true) === false, 'Malmö är avsiktligt Help Scout');
gk(($st['companies']['jarfalla']['mode'] ?? '') === 'graph' && ($st['companies']['jarfalla']['polled'] ?? false) === true, 'Järfälla pollas via Graph');
gk(str_contains((string) ($st['malmo_note'] ?? ''), 'Help Scout'), 'status skiljer historisk graph-malmo från ett fel');
gk(!str_contains($blob, $cs), 'feltexten innehåller inte clientState');
gk(str_contains((string) (jeab_graph_state()['subs']['jarfalla']['error'] ?? ''), 'Forbidden'), 'feltexten sparas på prenumerationen');
$delta = jeab_graph_delta(0, 3);
$box = (array) ($delta['boxes']['jarfalla'] ?? []);
gk((int) ($box['errors'] ?? 0) === 1 && str_contains((string) ($box['error'] ?? ''), 'mailbox busy'), 'borttaget mejl räknas inte, Graph-feltexten följer med');
gk((int) ($box['duplicates'] ?? 0) === 1 && (int) ($box['seen'] ?? 0) === 2, '404 hoppas över och de andra räknas');
$tick = jeab_graph_mail_tick();
$poll = jeab_graph_json_read(jeab_inkorg_dir() . '/graph-poll.json');
$daily = jeab_graph_json_read(jeab_inkorg_dir() . '/graph-daily.json');
gk(str_contains((string) json_encode($poll), 'mailbox busy'), 'pollen skriver feltexten');
gk(($daily['result']['malmo'] ?? '') === 'helpscout' && ($daily['result']['subs_ok'] ?? true) === false, 'dagstatusen visar avsiktlig Malmö och nekad prenumeration');
gk(str_contains((string) ($daily['result']['subs_error']['jarfalla'] ?? ''), 'Forbidden'), 'dagstatusen har prenumerationens feltext');
gk(empty($tick['subscriptions']['ok']), 'ticken försöker igen när prenumerationen har fel');
gk(!str_contains((string) json_encode($poll), $cs) && !str_contains((string) json_encode($daily), $cs), 'sparad status saknar clientState');
echo "klart\n";
