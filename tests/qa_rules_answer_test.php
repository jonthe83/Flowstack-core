<?php

declare(strict_types=1);

/** Sams lärda svarsregler i det korta offertsvaren. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-qa-rules-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qa(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$path = jeab_auto_qa_rules_path();
$file = (string) file_get_contents($path);
qa(str_ends_with($path, '/src/prompts/qa_rules.php') && str_contains($file, 'jeab-deny-direct') && !is_file(dirname(__DIR__) . '/src/prompts/qa_rules.md'), 'reglerna ligger i src/prompts/qa_rules.php');
$rules = jeab_auto_qa_rules_text();
preg_match_all('/^\d+\. /m', $rules, $nums);
qa(count($nums[0]) === 39 && str_contains($rules, 'Föreslå ALDRIG SigenNeo 19,5 kWh') && str_contains($rules, '30 000 kr') && str_contains($rules, '1 490 kr/h') && str_contains($rules, 'Kims äldre 20 000-pris'), 'filen har de lärda reglerna ordagrant');
qa(!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $rules), 'regelfilen har ingen e-post');
qa(jeab_auto_qa_rules_apply('jarfalla', 'laddbox') && jeab_auto_qa_rules_apply('jarfalla', 'batteri') && jeab_auto_qa_rules_apply('jarfalla', 'sol') && jeab_auto_qa_rules_apply('malmo', 'batteri') && jeab_auto_qa_rules_apply('malmo', 'laddbox'), 'Järfälla laddbox/batteri/sol och Malmö batteri/laddbox tar med reglerna');
qa(!jeab_auto_qa_rules_apply('malmo', 'sol') && !jeab_auto_qa_rules_apply('jarfalla', 'sol+batteri'), 'Malmö sol och sol+batteri tar inte med regelfilen');

qa(jeab_auto_answer_hard_block('Easee Charge Up kostar 8 490 kr.') === '', 'pris utan orden grön teknik intill går');
qa(jeab_auto_answer_hard_block('Easee Charge Up är 8 490 kr efter grön teknik.') === '', 'pris efter grön teknik går');
qa(jeab_auto_answer_hard_block('Avbetalningen är 16 980 kr, alltså dubbelt pris.') === 'svaret nämner dubbelt pris', 'dubbelt pris stoppas');
qa(jeab_auto_answer_hard_block('Utan grön teknik är det dubbla priset.') === 'svaret nämner dubbelt pris', 'dubbla priset stoppas');
qa(jeab_auto_answer_hard_block('Utan grön teknik kostar den 16 980 kr.') === 'pris utan grön teknik', 'pris utan grön teknik stoppas');
qa(jeab_auto_answer_hard_block('Ö-drift kostar 20 000 kr efter grön teknik.') === 'ö-drift kostar 30 000 kr', 'fel ö-driftpris stoppas');
qa(jeab_auto_answer_hard_block('Ö-drift ingår efter grön teknik.') === 'ö-drift kostar 30 000 kr', 'ö-drift utan 30 000 kr stoppas');
qa(jeab_auto_answer_hard_block('Ö-drift kostar 30 000 kr efter grön teknik.') === '', 'ö-drift 30 000 kr går');
qa(jeab_auto_answer_hard_block('Paneler ni köpt monterar vi för 2 000 kr per timme efter grön teknik.') === 'kundköpt material monteras för 1 490 kr per timme', 'fel timpris för kundköpt material stoppas');
qa(jeab_auto_answer_hard_block('Paneler ni köpt monterar vi för 1 490 kr per timme efter grön teknik.') === '', '1 490 kr per timme går');

$seen = [];
$GLOBALS['_JEAB_REBOOK_LLM'] = static function (array $messages) use (&$seen): string {
    $user = (string) ($messages[1]['content'] ?? '');
    $seen[] = $messages;
    if (str_contains($user, 'DUBBELT')) {
        return json_encode(['answer' => 'Easee Charge Up är 8 490 kr efter grön teknik. Utan grön teknik är det dubbla priset.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'BARA pris')) {
        return json_encode(['answer' => 'Easee Charge Up kostar 8 490 kr med installation.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'MED kval')) {
        return json_encode(['answer' => 'Easee Charge Up är 8 490 kr efter grön teknik, med laddkabel, krok och dynamisk lastbalansering.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'fel ö-drift')) {
        return json_encode(['answer' => 'Ö-drift kostar 20 000 kr efter grön teknik.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'rätt ö-drift')) {
        return json_encode(['answer' => 'Ö-drift kostar 30 000 kr efter grön teknik.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'egna paneler fel')) {
        return json_encode(['answer' => 'Paneler ni köpt monterar vi för 2 000 kr per timme efter grön teknik.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'egna paneler rätt')) {
        return json_encode(['answer' => 'Paneler ni köpt monterar vi för 1 490 kr per timme efter grön teknik.'], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['answer' => ''], JSON_UNESCAPED_UNICODE);
};

$facts = 'Mall utan extra pris.';
$samples = [
    ['jarfalla', 'laddbox', 'Vad kostar en elbilsladdare? BARA pris'],
    ['jarfalla', 'batteri', 'Vad kostar batteri med rätt ö-drift?'],
    ['jarfalla', 'sol', 'Vad kostar solceller om vi har egna paneler rätt?'],
    ['malmo', 'batteri', 'Vad kostar batteri i Malmö?'],
    ['malmo', 'laddbox', 'Vad kostar en elbilsladdare i Malmö?'],
];
foreach ($samples as [$company, $offer, $question]) {
    $before = count($seen);
    jeab_auto_answer($question, $facts, $question, $company, $offer);
    $last = $GLOBALS['_JEAB_AUTO_LLM_LAST'] ?? [];
    $sys = (string) ($last['messages'][0]['content'] ?? '');
    qa(count($seen) === $before + 1 && str_contains($sys, 'Föreslå ALDRIG SigenNeo 19,5 kWh') && str_contains($sys, $rules) && ($last['model'] ?? '') === 'grok-4.7' && ($last['reasoning_effort'] ?? '') === 'medium', $company . ' ' . $offer . ' får reglerna och grok-4.7 medium');
}
$skip = jeab_auto_answer('Vad kostar solceller?', $facts, 'Vad kostar solceller?', 'malmo', 'sol');
$skipSys = (string) (($GLOBALS['_JEAB_AUTO_LLM_LAST']['messages'][0]['content'] ?? ''));
qa(($skip['error'] ?? '') === '' && !str_contains($skipSys, 'Föreslå ALDRIG') && str_contains($skipSys, 'Svarsregler'), 'Malmö sol anropar modellen utan regelfilen');
$skipCombo = jeab_auto_answer('Vad kostar solceller?', $facts, 'Vad kostar solceller?', 'jarfalla', 'sol+batteri');
qa(!str_contains((string) (($GLOBALS['_JEAB_AUTO_LLM_LAST']['messages'][0]['content'] ?? '')), 'Föreslå ALDRIG'), 'sol+batteri anropar modellen utan regelfilen');

$bare = jeab_auto_answer('Vad kostar en elbilsladdare? DUBBELT', $facts, 'Vad kostar en elbilsladdare? DUBBELT', 'jarfalla', 'laddbox');
qa(($bare['answer'] ?? '') === '' && str_contains((string) ($bare['error'] ?? ''), 'dubbelt pris'), 'exempelfråga om dubbelt pris ger inget kort svar');
$plain = jeab_auto_answer('Vad kostar en elbilsladdare? BARA pris', $facts, 'Vad kostar en elbilsladdare? BARA pris', 'jarfalla', 'laddbox');
qa(str_contains((string) ($plain['answer'] ?? ''), '8 490 kr') && ($plain['error'] ?? '') === '', 'pris utan orden grön teknik intill behålls');
$okPrice = jeab_auto_answer('Vad kostar en elbilsladdare? MED kval', $facts, 'Vad kostar en elbilsladdare? MED kval', 'jarfalla', 'laddbox');
qa(($okPrice['answer'] ?? '') !== '' && str_contains((string) $okPrice['answer'], 'efter grön teknik') && ($okPrice['error'] ?? '') === '', 'exempelfråga med grön teknik behålls');
$badIsland = jeab_auto_answer('Vad kostar batteri med fel ö-drift?', $facts, 'Vad kostar batteri med fel ö-drift?', 'jarfalla', 'batteri');
qa(($badIsland['answer'] ?? '') === '' && str_contains((string) ($badIsland['error'] ?? ''), '30 000'), 'fel ö-driftpris ger inget kort svar');
$okIsland = jeab_auto_answer('Vad kostar batteri med rätt ö-drift?', $facts, 'Vad kostar batteri med rätt ö-drift?', 'malmo', 'batteri');
qa(str_contains((string) ($okIsland['answer'] ?? ''), '30 000 kr') && ($okIsland['error'] ?? '') === '', 'Malmö batteri får ö-drift 30 000 kr');
$badHour = jeab_auto_answer('Vad kostar solceller om vi har egna paneler fel?', $facts, 'Vad kostar solceller om vi har egna paneler fel?', 'jarfalla', 'sol');
qa(($badHour['answer'] ?? '') === '' && str_contains((string) ($badHour['error'] ?? ''), '1 490'), 'fel timpris ger inget kort svar');
$okHour = jeab_auto_answer('Vad kostar solceller om vi har egna paneler rätt?', $facts, 'Vad kostar solceller om vi har egna paneler rätt?', 'jarfalla', 'sol');
qa(str_contains((string) ($okHour['answer'] ?? ''), '1 490 kr per timme') && ($okHour['error'] ?? '') === '', 'eget material 1 490 kr per timme behålls');

$opts = jeab_auto_answer_llm_opts();
$payload = jeab_auto_llm_payload([['role' => 'user', 'content' => 'hej']], $opts);
qa(($payload['model'] ?? '') === 'grok-4.7' && ($payload['reasoning_effort'] ?? '') === 'medium' && ($payload['temperature'] ?? null) === 0, 'anropskroppen är grok-4.7 medium');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['batteri_jarfalla', 'laddbox_jarfalla', 'sol_jarfalla'] as $key) {
    jeab_auto_pdf_save(['key' => $key, 'data' => base64_encode('%PDF-1.4 ' . $key)]);
}
$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = function (string $id, string $from, string $name, string $body, string $offer) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@qa-rules.test',
        'from' => $from,
        'from_name' => 'Formulär',
        'to' => $box,
        'subject' => 'Offert',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'qa-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
        ],
    ]);
};
$person = static fn (string $name, string $email): string => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-100 20 11\nAdress: Regeltorget 1, 176 70 Järfälla\n";
$before = count($GLOBALS['_SENT']);
$bareIn = $ingest('bare', 'info@wix-forms.com', 'Kim Pris', $person('Kim Pris', 'kim.pris.qa@gmail.com') . "Meddelande:\nVad kostar en elbilsladdare? DUBBELT\n", 'laddbox');
$okIn = $ingest('ok', 'info@wix-forms.com', 'Kim Ok', $person('Kim Ok', 'kim.ok.qa@gmail.com') . "Meddelande:\nVad kostar en elbilsladdare? MED kval\n", 'laddbox');
$islandIn = $ingest('island', 'island.qa@gmail.com', 'Bo Ö', $person('Bo Ö', 'island.qa@gmail.com') . "Meddelande:\nVad kostar batteri med fel ö-drift?\n", 'batteri');
qa(($bareIn['thread_id'] ?? '') !== '' && ($okIn['thread_id'] ?? '') !== '' && ($islandIn['thread_id'] ?? '') !== '', 'exempelfrågorna blir ärenden');
$bareDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) ($bareIn['thread_id'] ?? ''), 'dry' => 1]);
$bareBody = (string) ($bareDry['body'] ?? '');
$tplAt = strpos($bareBody, 'Min favorit är Easee Charge Up');
qa(($bareDry['ok'] ?? false) === true && ($bareDry['action'] ?? '') !== 'hold' && $tplAt !== false && !str_contains($bareBody, 'dubbla priset') && !str_contains(substr($bareBody, 0, $tplAt), jeab_auto_answer_safe_line()) && count($GLOBALS['_SENT']) === $before, 'dry: dubbelt pris ger mallen utan kort svar och skickar inte');

$okDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $okIn['thread_id'], 'dry' => 1]);
$okBody = (string) ($okDry['body'] ?? '');
$ansAt = strpos($okBody, 'efter grön teknik');
$okTpl = strpos($okBody, 'Min favorit är Easee Charge Up');
qa(($okDry['ok'] ?? false) === true && $ansAt !== false && $okTpl !== false && $ansAt < $okTpl && count($GLOBALS['_SENT']) === $before, 'dry: godkänt kort svar ligger före mallen och skickar inte');

$islandDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $islandIn['thread_id'], 'dry' => 1]);
$islandBody = (string) ($islandDry['body'] ?? '');
qa(($islandDry['ok'] ?? false) === true && ($islandDry['action'] ?? '') !== 'hold' && !str_contains($islandBody, '20 000 kr') && !str_contains($islandBody, jeab_auto_answer_safe_line()) && count($GLOBALS['_SENT']) === $before, 'dry: fel ö-driftpris ger inget kort svar och skickar inte');

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
qa($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$log = sys_get_temp_dir() . '/jeab-qa-rules-http-' . bin2hex(random_bytes(3)) . '.log';
$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__)),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes
);
qa(is_resource($proc), 'regelfilens server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(50000);
}
qa($up, 'regelfilens server lyssnar');
$ch = curl_init('http://127.0.0.1:' . $port . '/src/prompts/qa_rules.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$httpBody = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$httpText = is_string($httpBody) ? $httpBody : '';
qa($httpStatus === 403 && !str_contains($httpText, '30 000') && !str_contains($httpText, 'Föreslå ALDRIG'), 'direktträff på regelfilen är 403');

echo "OK  qa-regler\n";
