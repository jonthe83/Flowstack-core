<?php

declare(strict_types=1);

/** Klassning som tar timeout köas med backoff, kan köras om, och lyfter en befintlig påminnelse när Grok sedan svarar. Inget går till en riktig kund. */

$tmp = sys_get_temp_dir() . '/jeab-classify-retry-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-llm.json', json_encode(['key' => 'test-key', 'model' => 'grok-4-fast-non-reasoning']));
require dirname(__DIR__) . '/src/bootstrap.php';

function cr(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g-cr', 'conversation_id' => 'CV', 'message_id' => 'cr@x'];
};
unset($GLOBALS['_JEAB_INKORG_LLM']);

cr(jeab_auto_classify_attempt_max() === 8, 'taket är 8 försök');
cr(jeab_auto_classify_backoff_seconds(1) === 120 && jeab_auto_classify_backoff_seconds(2) === 600 && jeab_auto_classify_backoff_seconds(3) === 1800 && jeab_auto_classify_backoff_seconds(4) === 7200 && jeab_auto_classify_backoff_seconds(5) === 21600 && jeab_auto_classify_backoff_seconds(9) === 21600, 'backoff är 2 min, 10 min, 30 min, 2 h och sedan 6 h');

$httpCalls = 0;
$GLOBALS['_JEAB_AUTO_LLM_HTTP'] = static function () use (&$httpCalls): array {
    $httpCalls++;

    return ['status' => 0, 'body' => false, 'errno' => 28, 'error' => 'Operation timed out'];
};
$timed = jeab_inkorg_classify([
    'id' => 'timeout-only',
    'from' => 'notifications@drip.com',
    'from_name' => 'Hitta-Solceller',
    'subject' => 'Hitta-Solceller via Drip timeout',
    'body' => "Källa: Hitta-Solceller via Drip\nNamn: Nora Retry\nE-post: nora.retry.kund@gmail.com\nTelefon: 070-333 22 11\nAdress: Dripvägen 3\nPostnummer: 176 70\nOrt: Järfälla\n",
    'labels' => [],
]);
cr(($timed['classify_error'] ?? '') === 'xAI svarade inte (timeout)' && $httpCalls === 1 && (int) ($GLOBALS['_JEAB_AUTO_LLM_HTTP_TIMEOUT'] ?? 0) === 24, 'timeout på 24 s syns i klassningen och görs en gång');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_min_confidence' => 50,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = is_array($d) ? $d : [];
    $d['JEAB_CLASSIFY_RETRY_SINCE'] = 1;

    return [true, $d];
});
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '';
$body = "Källa: Hitta-Solceller via Drip\nNamn: Nora Retry\nE-post: nora.retry.kund@gmail.com\nTelefon: 070-333 22 11\nAdress: Dripvägen 3\nPostnummer: 176 70\nOrt: Järfälla\n";
$ing = jeab_inkorg_ingest([
    'message_id' => 'retry@classify.test',
    'from' => 'notifications@drip.com',
    'from_name' => 'Hitta-Solceller',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hitta-Solceller via Drip retry',
    'body_text' => $body,
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
]);
$id = (string) $ing['id'];
$tid = (string) $ing['thread_id'];
jeab_auto_on_message($id);
$before = $httpCalls;
$dry = jeab_inkorg_hook(['action' => 'inbox.classify_retry', 'id' => $tid]);
$dryJson = json_encode($dry);
$item = is_array($dry['items'][0] ?? null) ? $dry['items'][0] : [];
cr(($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false && ($item['next'] ?? '') === 'retry' && (int) ($item['queued'] ?? 0) === 1 && (int) ($item['classify_attempts'] ?? 0) === 1 && !str_contains((string) $dryJson, '@') && $httpCalls === $before, 'dry-run visar omförsök utan adress och utan anrop');

jeab_auto_classify_pending_forget($id);
$live = jeab_inkorg_hook(['action' => 'inkorg.classify_retry', 'ids' => [$tid], 'dry' => 0]);
$liveItem = is_array($live['items'][0] ?? null) ? $live['items'][0] : [];
$queued = jeab_auto_classify_pending_read()[$id] ?? null;
cr(($live['sent'] ?? 1) === 0 && ($live['would_send'] ?? true) === false && (int) ($liveItem['queued'] ?? 0) === 1 && is_array($queued) && (int) ($queued['next_at'] ?? 0) <= jeab_auto_classify_clock() && $httpCalls === $before, 'dry=0 köar om direkt utan xAI');

jeab_auto_classify_pending_forget($id);
cr(jeab_auto_classify_requeue_failed(20) === 1 && isset(jeab_auto_classify_pending_read()[$id]), 'tick lägger tillbaka ett misslyckat lead som ramlat ur kön');
cr(jeab_auto_classify_requeue_failed(20) === 0 && isset(jeab_auto_classify_pending_read()[$id]), 'samma lead köas inte en gång till');

jeab_inkorg_update($id, static function (array $stored): array {
    $labels = (array) ($stored['labels'] ?? []);
    $labels['classify_attempts'] = 7;
    $stored['labels'] = $labels;

    return $stored;
});
$GLOBALS['_JEAB_NOW'] = jeab_auto_classify_clock() + 5;
cr(jeab_auto_classify_pending_drain(20) === 1 && !isset(jeab_auto_classify_pending_read()[$id]), 'åttonde försöket lämnar kön');
$rem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string) ($row['thread_id'] ?? '') === $tid || str_contains((string) ($row['note'] ?? ''), $tid)) {
        $rem = $row;
        break;
    }
}
cr(is_array($rem) && str_ends_with((string) ($rem['title'] ?? ''), 'okänd produkt') && $GLOBALS['_SENT'] === [], 'påminnelsen efter taket är okänd produkt och inget skickas');

$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => json_encode([
    'type' => 'Ny lead',
    'confidence' => 95,
    'intent' => 'kop',
    'offer' => 'laddbox',
    'product' => 'elbilsladdare',
    'reason' => 'kunden vill ha laddbox',
    'case_intent' => 'kopintresse',
    'primary_product' => 'Elbilsladdare',
    'kind' => 'atgard',
], JSON_UNESCAPED_UNICODE);
jeab_auto_classify_pending_remember($id, $tid, 'xAI svarade inte', 8, true);
$GLOBALS['_JEAB_NOW'] = jeab_auto_classify_clock() + 5;
cr(jeab_auto_classify_pending_drain(20) === 1, 'nästa tick klassar om');
$fresh = (array) (jeab_inkorg_get($id)['labels'] ?? []);
$promoted = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((int) ($row['id'] ?? 0) === (int) ($rem['id'] ?? 0)) {
        $promoted = $row;
        break;
    }
}
$who = (string) ($promoted['assignee'] ?? $promoted['who'] ?? '');
cr(($fresh['classify_error'] ?? '') === '' && ($fresh['source'] ?? '') === 'llm' && ($fresh['offer'] ?? '') === 'laddbox' && is_array($promoted) && str_contains((string) ($promoted['title'] ?? ''), 'Elbilsladdare') && str_contains($who, 'Elin') && $GLOBALS['_SENT'] === [], 'lyckad klassning lyfter påminnelsen till Elin utan utskick');
$rules = implode("\n", jeab_auto_rule_lines());
cr(str_contains($rules, 'inbox.classify_retry') && str_contains($rules, 'klassas för hand') && str_contains($rules, 'skickas inte en gång till'), 'regeln nämner omförsökshooken, manuell klassning och att offerten inte går två gånger');

$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '';
$guardBody = static function (string $name, string $email, string $phone, string $street): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$street}, 177 31 Järfälla\nVill ha batteri.\n";
};
$guardIngest = static function (string $key, string $name, string $email, string $phone, string $street) use ($guardBody): array {
    $when = gmdate('c');

    return jeab_inkorg_ingest([
        'message_id' => $key . '@classify.test',
        'from' => 'hej@nordsolen.se',
        'from_name' => 'Lead',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Ny lead batteri ' . $key,
        'date' => $when,
        'received_at' => $when,
        'body_text' => $guardBody($name, $email, $phone, $street),
        'source' => 'graph-jarfalla',
        'thread_key' => 'classify-' . $key,
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'company' => 'jarfalla',
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=nordsolen.se'],
    ]);
};
$quoted = $guardIngest('quoted', 'Nora Guard', 'nora.guard@exempel.se', '070-111 22 31', 'Guardvägen 1');
$open = $guardIngest('open', 'Mia Oppna', 'mia.oppna@exempel.se', '070-111 22 32', 'Oppnavägen 2');
$hand = $guardIngest('hand', 'Elsa Hand', 'elsa.hand@exempel.se', '070-111 22 34', 'Handvägen 4');
cr(!empty($quoted['ok']) && !empty($open['ok']) && !empty($hand['ok']), 'tre nya ärenden tas in');
$quotedId = (string) $quoted['id'];
$quotedTid = (string) $quoted['thread_id'];
$openId = (string) $open['id'];
$handId = (string) $hand['id'];
$handTid = (string) $hand['thread_id'];
jeab_auto_on_message($quotedId);
jeab_auto_on_message($openId);
jeab_auto_on_message($handId);
jeab_inkorg_relabel($handId, ['type' => 'Information'], 'Elin');
jeab_auto_note($quotedTid, 'Offert (Batteri) skickad manuellt från saljare3@jarfalla.example.test till nora.guard@exempel.se.');
jeab_auto_quote_sent_remember($quotedTid, 'note', ['hit' => true, 'date' => '2026-10-31'], 'batteri');
$handLabels = (array) (jeab_inkorg_get($handId)['labels'] ?? []);
cr(($handLabels['source'] ?? '') === 'manual' && ($handLabels['type'] ?? '') === 'Information' && jeab_auto_classify_hand_sorted(jeab_inkorg_get($handId) ?: []), 'handpåläggningen syns som manuell Information');
cr(jeab_auto_classify_quote_landed($quotedTid), 'redan skickad offert syns innan omförsöket');
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_min_confidence' => 50,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 batteri\n")]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri.", 'test');
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => json_encode([
    'type' => 'Ny lead',
    'confidence' => 95,
    'intent' => 'kop',
    'offer' => 'batteri',
    'product' => 'batteri',
    'reason' => 'kunden vill ha batteri',
    'case_intent' => 'kopintresse',
    'primary_product' => 'Batteri',
    'kind' => 'atgard',
], JSON_UNESCAPED_UNICODE);
$due = 0;
foreach ([$quotedId, $openId, $handId] as $dueId) {
    $dueRow = jeab_auto_classify_pending_read()[$dueId] ?? null;
    $due = max($due, (int) (is_array($dueRow) ? ($dueRow['next_at'] ?? 0) : 0));
}
$GLOBALS['_JEAB_NOW'] = $due + 1;
$sentBefore = count($GLOBALS['_SENT']);
jeab_auto_classify_pending_drain(20);
$sentTo = array_map(static fn(array $row): string => (string) ($row['to'] ?? ''), $GLOBALS['_SENT']);
$handAfter = (array) (jeab_inkorg_get($handId)['labels'] ?? []);
$quotedAfter = (array) (jeab_inkorg_get($quotedId)['labels'] ?? []);
cr(count($GLOBALS['_SENT']) === $sentBefore + 1 && in_array('mia.oppna@exempel.se', $sentTo, true) && !in_array('nora.guard@exempel.se', $sentTo, true) && !in_array('elsa.hand@exempel.se', $sentTo, true), 'omförsöket skickar den öppna offerten en gång och hoppar över den som redan gått');
cr(($handAfter['type'] ?? '') === 'Information' && ($handAfter['source'] ?? '') === 'manual' && ($handAfter['reason'] ?? '') === 'Ändrad av Elin' && !isset(jeab_auto_classify_pending_read()[$handId]), 'manuell klassning skrivs inte över och lämnar kön');
cr(($quotedAfter['classify_error'] ?? '') === '' && ($quotedAfter['type'] ?? '') === 'Ny lead' && ($quotedAfter['offer'] ?? '') === 'batteri', 'redan skickad offert klassas om utan ett nytt utskick');
$GLOBALS['_JEAB_NOW'] = jeab_auto_classify_clock() + 700;
jeab_auto_classify_pending_remember($quotedId, $quotedTid, 'xAI svarade inte', 2, true);
$sentAfter = count($GLOBALS['_SENT']);
cr(jeab_auto_classify_pending_drain(20) === 1 && count($GLOBALS['_SENT']) === $sentAfter && !in_array('nora.guard@exempel.se', array_map(static fn(array $row): string => (string) ($row['to'] ?? ''), $GLOBALS['_SENT']), true), 'nästa omförsök skickar inte offerten en gång till');
jeab_inkorg_update($handId, static function (array $stored): array {
    $labels = (array) ($stored['labels'] ?? []);
    $labels['classify_error'] = 'xAI svarade inte';
    $labels['classify_attempts'] = 1;
    $stored['labels'] = $labels;

    return $stored;
});
cr(jeab_auto_classify_requeue_failed(20) === 0 && !isset(jeab_auto_classify_pending_read()[$handId]), 'ett handklassat ärende läggs inte tillbaka i kön');

$clock = jeab_auto_classify_clock();
$since = $clock - 5 * 86400;
jeab_inkorg_locked('settings.json', static function (?array $d) use ($since): array {
    $d = is_array($d) ? $d : [];
    $d['JEAB_CLASSIFY_RETRY_SINCE'] = $since;

    return [true, $d];
});
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '';
$stampFail = static function (string $msgId, int $receivedAt, int $errorAt, int $attempts, bool $dropDate) : void {
    jeab_inkorg_update($msgId, static function (array $stored) use ($receivedAt, $errorAt, $attempts, $dropDate): array {
        if ($dropDate) {
            $stored['received_at'] = '';
            $stored['date'] = '';
            unset($stored['created_at']);
        } else {
            $stored['received_at'] = date('c', $receivedAt);
            $stored['date'] = date('c', $receivedAt);
        }
        $labels = (array) ($stored['labels'] ?? []);
        $labels['classify_error'] = 'xAI svarade inte';
        $labels['classify_attempts'] = $attempts;
        $labels['classify_error_at'] = $errorAt > 0 ? date('c', $errorAt) : '';
        $labels['type'] = 'Oklassad';
        $labels['source'] = 'none';
        unset($labels['panel_choice'], $labels['offer_manual']);
        $stored['labels'] = $labels;

        return $stored;
    });
    jeab_auto_classify_pending_forget($msgId);
};
$oldFailed = $guardIngest('old-failed', 'Ove Gammal', 'ove.gammal@exempel.se', '070-444 10 01', 'Gammalvägen 1');
$oldExhausted = $guardIngest('old-exhausted', 'Eva Tak', 'eva.tak@exempel.se', '070-444 10 02', 'Takvägen 2');
$freshRetry = $guardIngest('fresh-retry', 'Nils Ny', 'nils.ny@exempel.se', '070-444 10 03', 'Nyvägen 3');
$noDate = $guardIngest('no-date', 'Ulla Tom', 'ulla.tom@exempel.se', '070-444 10 04', 'Tomvägen 4');
cr(!empty($oldFailed['ok']) && !empty($oldExhausted['ok']) && !empty($freshRetry['ok']) && !empty($noDate['ok']), 'gamla och nya felärenden tas in');
$oldFailedId = (string) $oldFailed['id'];
$oldExhaustedId = (string) $oldExhausted['id'];
$freshRetryId = (string) $freshRetry['id'];
$noDateId = (string) $noDate['id'];
$stampFail($oldFailedId, $clock - 50 * 3600, $clock - 50 * 3600, 1, false);
$stampFail($oldExhaustedId, $clock - 2 * 3600, $since - 86400, 3, false);
$stampFail($freshRetryId, $clock - 3600, $clock - 3600, 1, false);
$stampFail($noDateId, $clock - 3600, $clock - 3600, 1, true);
cr(jeab_auto_classify_requeue_failed(20) === 1 && isset(jeab_auto_classify_pending_read()[$freshRetryId]) && !isset(jeab_auto_classify_pending_read()[$oldFailedId]) && !isset(jeab_auto_classify_pending_read()[$oldExhaustedId]) && !isset(jeab_auto_classify_pending_read()[$noDateId]), 'bara ett nytt fel köas: gammalt fel, uttömt före gränsen och tråd utan datum ligger kvar');
jeab_auto_classify_pending_forget($freshRetryId);
$sentBeforeOld = count($GLOBALS['_SENT']);
jeab_auto_classify_pending_remember($oldExhaustedId, (string) $oldExhausted['thread_id'], 'xAI svarade inte', 3, true);
cr(jeab_auto_classify_pending_drain(20) >= 1 && !isset(jeab_auto_classify_pending_read()[$oldExhaustedId]) && count($GLOBALS['_SENT']) === $sentBeforeOld && (int) ((jeab_inkorg_get($oldExhaustedId)['labels']['classify_attempts'] ?? 0)) === 3, 'ett uttömt ärende från före gränsen väcks inte och inget skickas');
foreach (array_keys(jeab_auto_classify_pending_read()) as $pendingId) {
    jeab_auto_classify_pending_forget((string) $pendingId);
}

$capIds = [];
foreach ([1, 2, 3] as $capN) {
    $cap = $guardIngest('cap-' . $capN, 'Cap ' . $capN, 'cap' . $capN . '@exempel.se', '070-555 10 0' . $capN, 'Capvägen ' . $capN);
    cr(!empty($cap['ok']), 'tak-ärende ' . $capN . ' tas in');
    $capId = (string) $cap['id'];
    $stampFail($capId, $clock - 120, $clock - 120, 1, false);
    jeab_auto_classify_pending_remember($capId, (string) $cap['thread_id'], 'xAI svarade inte', 1, true);
    $capIds[] = $capId;
}
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => json_encode([
    'type' => 'Ny lead',
    'confidence' => 95,
    'intent' => 'kop',
    'offer' => 'batteri',
    'product' => 'batteri',
    'reason' => 'kunden vill ha batteri',
    'case_intent' => 'kopintresse',
    'primary_product' => 'Batteri',
    'kind' => 'atgard',
], JSON_UNESCAPED_UNICODE);
$GLOBALS['_JEAB_CLASSIFY_RETRY_TICK_QUOTES'] = 0;
$capDay = date('Y-m-d', jeab_auto_classify_clock());
jeab_inkorg_locked('settings.json', static function (?array $d) use ($capDay): array {
    $d = is_array($d) ? $d : [];
    $d['classify_retry_quotes'] = ['day' => $capDay, 'count' => 0];

    return [true, $d];
});
$sentBeforeCap = count($GLOBALS['_SENT']);
cr(jeab_auto_classify_pending_drain(20) === 2, 'första tick skickar högst två omförsök');
$capQueued = jeab_auto_classify_pending_read();
$stillQueued = 0;
$quotedCaps = 0;
foreach ($capIds as $capId) {
    if (isset($capQueued[$capId])) {
        $stillQueued++;
        $left = (array) (jeab_inkorg_get($capId)['labels'] ?? []);
        cr(($left['classify_error'] ?? '') !== '', 'ärendet över taket ligger kvar med felet');
    } else {
        $quotedCaps++;
    }
}
$leftId = '';
foreach ($capIds as $capId) {
    if (isset($capQueued[$capId])) {
        $leftId = $capId;
    }
}
cr(count($GLOBALS['_SENT']) === $sentBeforeCap + 2 && $quotedCaps === 2 && $stillQueued === 1 && $leftId !== '' && (int) (jeab_auto_classify_retry_quotes_state()['count'] ?? 0) === 2, 'två offerter går ut, den tredje stannar i kön och dygnet räknar två');
$sentMidCap = count($GLOBALS['_SENT']);
cr(jeab_auto_classify_pending_drain(20) === 0 && count($GLOBALS['_SENT']) === $sentMidCap && isset(jeab_auto_classify_pending_read()[$leftId]), 'samma tick skickar inte en tredje offert');
$GLOBALS['_JEAB_CLASSIFY_RETRY_TICK_QUOTES'] = 0;
jeab_inkorg_locked('settings.json', static function (?array $d) use ($capDay): array {
    $d = is_array($d) ? $d : [];
    $d['classify_retry_quotes'] = ['day' => $capDay, 'count' => 10];

    return [true, $d];
});
cr(jeab_auto_classify_pending_drain(20) === 0 && count($GLOBALS['_SENT']) === $sentMidCap && isset(jeab_auto_classify_pending_read()[$leftId]), 'dygnstaket 10 släpper ingen mer offert');
cr(str_contains($rules, 'JEAB_CLASSIFY_RETRY_SINCE') && str_contains($rules, '48 timmar') && str_contains($rules, '10 per dygn'), 'regeln nämner gränsen, 48 timmar och dygnstaket');

foreach (array_keys(jeab_auto_classify_pending_read()) as $pendingId) {
    jeab_auto_classify_pending_forget((string) $pendingId);
}
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '';
$askWhen = date('c', $clock - 180);
$askIngest = static function (string $key, string $name, string $email, string $phone) use ($askWhen): array {
    return jeab_inkorg_ingest([
        'message_id' => $key . '@classify.test',
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Hej',
        'date' => $askWhen,
        'received_at' => $askWhen,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Fragavägen 8, 177 80 Järfälla\n",
        'source' => 'graph-jarfalla',
        'thread_key' => 'classify-' . $key,
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'company' => 'jarfalla',
    ]);
};
$askOne = $askIngest('ask-1', 'Vera Fraga', 'vera.fraga1@gmail.com', '070-555 80 21');
cr(!empty($askOne['ok']), 'okänd produkt tas in');
$askOneId = (string) $askOne['id'];
$stampFail($askOneId, $clock - 180, $clock - 180, 1, false);
jeab_auto_classify_pending_remember($askOneId, (string) $askOne['thread_id'], 'xAI svarade inte', 1, true);
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => json_encode([
    'type' => 'Ny lead',
    'confidence' => 90,
    'intent' => 'kop',
    'offer' => '',
    'product' => '',
    'reason' => 'okänd produkt',
    'question' => '',
], JSON_UNESCAPED_UNICODE);
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'unknown_product_ask_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_min_confidence' => 50,
    'tpl' => jeab_auto_quote_seed(),
]);
$GLOBALS['_JEAB_CLASSIFY_RETRY_TICK_QUOTES'] = 0;
$askDay = date('Y-m-d', jeab_auto_classify_clock());
jeab_inkorg_locked('settings.json', static function (?array $d) use ($askDay): array {
    $d = is_array($d) ? $d : [];
    $d['classify_retry_quotes'] = ['day' => $askDay, 'count' => 0];

    return [true, $d];
});
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    unset($method);
    if (str_contains($url, 'mailFolders/sentitems?')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder']];
    }

    return ['code' => 200, 'body' => ['value' => []]];
};
$sentBeforeAsk = count($GLOBALS['_SENT']);
cr(jeab_auto_classify_pending_drain(20) === 1 && !isset(jeab_auto_classify_pending_read()[$askOneId]), 'omförsöket frågar om okänd produkt');
$askSent = $GLOBALS['_SENT'][$sentBeforeAsk] ?? [];
cr(count($GLOBALS['_SENT']) === $sentBeforeAsk + 1 && str_contains((string) ($askSent['body'] ?? ''), 'Gäller det solceller, batteri eller Elbilsladdare') && (int) (jeab_auto_classify_retry_quotes_state()['count'] ?? 0) === 1, 'frågan räknas som utskick och dygnet går från 0 till 1');
$askMore = [];
foreach ([2, 3] as $askN) {
    $row = $askIngest('ask-' . $askN, 'Vera Fraga ' . $askN, 'vera.fraga' . $askN . '@gmail.com', '070-555 80 2' . $askN);
    cr(!empty($row['ok']), 'okänd produkt ' . $askN . ' tas in');
    $askId = (string) $row['id'];
    $stampFail($askId, $clock - 180, $clock - 180, 1, false);
    jeab_auto_classify_pending_remember($askId, (string) $row['thread_id'], 'xAI svarade inte', 1, true);
    $askMore[] = $askId;
}
$sentMidAsk = count($GLOBALS['_SENT']);
cr(jeab_auto_classify_pending_drain(20) === 1 && count($GLOBALS['_SENT']) === $sentMidAsk + 1 && (int) (jeab_auto_classify_retry_quotes_state()['count'] ?? 0) === 2, 'samma tick släpper en fråga till och stannar på 2');
$askLeft = '';
foreach ($askMore as $askId) {
    if (isset(jeab_auto_classify_pending_read()[$askId])) {
        $askLeft = $askId;
        $leftLabels = (array) (jeab_inkorg_get($askId)['labels'] ?? []);
        cr(($leftLabels['classify_error'] ?? '') !== '', 'frågan över taket ligger kvar okörd');
    }
}
cr($askLeft !== '' && count(array_filter($askMore, static fn(string $id): bool => !isset(jeab_auto_classify_pending_read()[$id]))) === 1, 'en av de två frågorna stannar i kön');
$sentAtAskCap = count($GLOBALS['_SENT']);
cr(jeab_auto_classify_pending_drain(20) === 0 && count($GLOBALS['_SENT']) === $sentAtAskCap && isset(jeab_auto_classify_pending_read()[$askLeft]), 'frågan om okänd produkt stoppas av taket');

echo "OK  classify retry\n";
