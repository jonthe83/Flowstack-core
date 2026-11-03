<?php

declare(strict_types=1);

/** Svarsregler i offertsvar. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-svarsregler-' . bin2hex(random_bytes(4));
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

function sr(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$rulesPath = jeab_auto_svarsregler_path();
$rulesFile = (string) file_get_contents($rulesPath);
sr(str_ends_with($rulesPath, '/config/svarsregler.php') && !is_file(dirname(__DIR__) . '/config/svarsregler.md') && str_contains($rulesFile, 'jeab-deny-direct'), 'reglerna ligger i config/svarsregler.php');
$shield = (string) file_get_contents(dirname(__DIR__) . '/src/shield.php');
$htaccess = (string) file_get_contents(dirname(__DIR__) . '/.htaccess');
sr(jeab_http_path_is_sensitive('/config/svarsregler.php') === false && !str_contains($shield, 'svarsregler') && !str_contains($htaccess, 'svarsregler'), 'regelfilen spärras av sig själv, inte via shield eller htaccess');
$rules = jeab_auto_svarsregler_text();
sr($rules !== '' && str_contains($rules, '101 500') && str_contains($rules, '8 500') && str_contains($rules, '17 000') && str_contains($rules, '1 490') && str_contains($rules, '30 000') && str_contains($rules, 'Slå mig en signal så ringer jag upp om jag inte kan svara direkt'), 'regelfilen har priserna och ring-meningen');
sr(!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $rules), 'regelfilen har ingen e-post');

sr(jeab_auto_substantive_question("Hjälp att välja rätt laddare för mig") !== '', 'hjälp att välja är en sakfråga');
sr(jeab_auto_substantive_question("Hjälp att välja rätt elbilsladdare för mig") !== '', 'hjälp att välja elbilsladdare är en sakfråga');
sr(jeab_auto_substantive_question("Årsförbrukning: 18000 kWh\nTakyta: 40") === '', 'formulärfält är ingen sakfråga');
sr(jeab_auto_phone_only_kind('Vi är BRF Eken och vill ha en elbilsladdare') === 'brf', 'BRF är telefon');
sr(jeab_auto_phone_only_kind('Samfälligheten behöver laddare') === 'brf', 'samfällighet är telefon');
sr(jeab_auto_phone_only_kind("Företaget AB\nVi vill ha två elbilsladdare") === 'company', 'företag med flera laddare är telefon');
sr(jeab_auto_phone_only_kind('Vi bor i Danmark och vill ha batteri') === 'abroad', 'utlandet är telefon');
sr(jeab_auto_phone_only_kind('Jag vill ha två elbilsladdare hemma') === '', 'privatperson med två laddare är vanlig offert');
sr(str_contains(jeab_auto_phone_only_reply('Kim'), 'Slå mig en signal så ringer jag upp om jag inte kan svara direkt'), 'förslaget ber kunden ringa');

sr(jeab_auto_answer_block('Vi föreslår 19,5 kWh.') === 'svaret nämner 19,5 kWh', '19,5 kWh stoppas');
sr(jeab_auto_answer_block('Sigenergy har 3 MPPT.') === 'svaret anger antal MPPT', 'antal MPPT stoppas');
sr(jeab_auto_answer_block('Den har flera MPPT, så varje taksida får en egen.') === '', 'flera MPPT utan antal går');
sr(jeab_auto_answer_block('Vi rekommenderar Solis, det är ett bra val.') !== '', 'Solis som rekommendation stoppas');
sr(jeab_auto_answer_block('Solis är säljstoppat. Vi rekommenderar Sigenergy.') === '', 'Solis som säljstopp går');
sr(jeab_auto_answer_block('Jag återkommer med ett förslag.') !== '', 'återkommer stoppas');
sr(jeab_auto_answer_block('Tyvärr kan vi inte hjälpa till med det.') !== '', 'ett nej stoppas');
sr(jeab_auto_answer_block('Taket för grönt avdrag är 50 000 kr.', 'Vi vill ha batteri') !== '', '50 000 kr utan ensam ägare stoppas');
sr(jeab_auto_answer_block('Taket för grönt avdrag är 50 000 kr.', 'Jag är ensam ägare') === '', '50 000 kr när kunden är ensam ägare går');
sr(jeab_auto_answer_block('Från 94 000 kr för ungefär 30 paneler.') !== '', '94 000 kr med stor anläggning stoppas');
sr(jeab_auto_answer_block('Från 94 000 kr för ungefär 12 paneler.') === '', '94 000 kr för en normal anläggning går');
sr(jeab_auto_answer_block('Vilket märke har ni på växelriktaren?') !== '', 'fråga om växelriktarens märke stoppas');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = static function (array $messages): string {
    $user = (string) ($messages[1]['content'] ?? '');
    if (str_contains($user, '19,5') || str_contains($user, '19.5')) {
        return json_encode(['answer' => 'Ett lagom batteri är 19,5 kWh.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, 'välja')) {
        return json_encode(['answer' => 'Sigenergy EV AC 11 kW är 8 500 kr efter grön teknik. Easee Charge Up är 8 490 kr med 7 m kabel. Hör gärna av dig så kan vi diskutera mer i detalj om vilken elbilsladdare som passar dina behov bäst.'], JSON_UNESCAPED_UNICODE);
    }
    if (str_contains($user, '40 kWh') || str_contains($user, '50 kWh')) {
        return json_encode(['answer' => 'Fox ESS 63 kWh med 15 kW hybridväxelriktare kostar 101 500 kr efter grön teknik. Hör gärna av dig så kan vi diskutera mer i detalj.'], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['answer' => ''], JSON_UNESCAPED_UNICODE);
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['batteri_jarfalla', 'laddbox_jarfalla', 'sol_jarfalla'] as $key) {
    jeab_auto_pdf_save(['key' => $key, 'data' => base64_encode('%PDF-1.4 ' . $key)]);
}
$seed = jeab_auto_quote_seed();
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\n" . jeab_offer_batteri_neo_price_paragraph(), 'test');
jeab_notiser_save_body('offert-laddbox-jarfalla', (string) ($seed['laddbox_jarfalla'] ?? ''), 'test');
jeab_notiser_save_body('offert-sol-jarfalla-eget', "Hej\n\nSol eget.", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-kopt', "Hej\n\nSol köpt.", 'test');
$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = function (string $id, string $from, string $name, string $body, string $offer) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@svarsregler.test',
        'from' => $from,
        'from_name' => 'Formulär',
        'to' => $box,
        'subject' => 'Offert',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'sr-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
        ],
    ]);
};
$person = static fn(string $name, string $email): string => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-100 20 11\nAdress: Regeltorget 1, 176 70 Järfälla\n";

$before = count($GLOBALS['_SENT']);
$advice = $ingest('advice', 'info@wix-forms.com', 'Kim Råd', $person('Kim Råd', 'kim.rad.sr@gmail.com') . "Meddelande:\nHjälp att välja rätt laddare för mig\n", 'laddbox');
$adviceDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $advice['thread_id'], 'dry' => 1]);
$adviceBody = (string) ($adviceDry['body'] ?? '');
$ansAt = strpos($adviceBody, '8 500 kr');
$tplAt = strpos($adviceBody, 'Min favorit är Easee Charge Up');
sr(($adviceDry['ok'] ?? false) === true && $ansAt !== false && $tplAt !== false && $ansAt < $tplAt && in_array('Elbilsladdare-offert.pdf', (array) ($adviceDry['attachments'] ?? []), true) && count($GLOBALS['_SENT']) === $before, 'råd om elbilsladdare ligger före mallen och bilagan');
sr(str_contains($adviceBody, 'Elbilsladdare') && !str_contains(substr($adviceBody, 0, $tplAt), 'laddbox'), 'svaret skriver Elbilsladdare');

$fox = $ingest('fox', 'fox.kund.sr@gmail.com', 'Fox Kund', $person('Fox Kund', 'fox.kund.sr@gmail.com') . "Vi tittar på 40 kWh eller 50 kWh.\n", 'batteri');
$foxDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $fox['thread_id'], 'dry' => 1]);
$foxBody = (string) ($foxDry['body'] ?? '');
sr(($foxDry['ok'] ?? false) === true && str_contains($foxBody, '101 500') && strpos($foxBody, '101 500') < strpos($foxBody, 'Den minsta investeringen') && count($GLOBALS['_SENT']) === $before, 'stort batteri föreslår Fox och behåller mallen');

$bad = $ingest('bad', 'bad.storlek.sr@gmail.com', 'Bo Stor', $person('Bo Stor', 'bad.storlek.sr@gmail.com') . "Kan ni ta 19,5 kWh?\n", 'batteri');
$badDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $bad['thread_id'], 'dry' => 1]);
$badBody = (string) ($badDry['body'] ?? '');
sr(($badDry['ok'] ?? false) === true && ($badDry['action'] ?? '') !== 'hold' && str_contains($badBody, jeab_auto_answer_safe_line()) && !str_contains($badBody, 'Ett lagom batteri är 19,5 kWh') && count($GLOBALS['_SENT']) === $before, '19,5 kWh i svaret blir den säkra raden och dry skickar inte');

$brf = $ingest('brf', 'info@wix-forms.com', 'BRF Eken', "Namn: BRF Eken\nE-post: brf.eken.sr@gmail.com\nTelefon: 070-100 20 12\nAdress: Ekgatan 2, 176 70 Järfälla\nMeddelande:\nVi vill ha en elbilsladdare.\n", 'laddbox');
$brfDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $brf['thread_id'], 'dry' => 1]);
sr(($brfDry['ok'] ?? true) === false && ($brfDry['action'] ?? '') === 'hold' && ($brfDry['why'] ?? '') === 'BRF/företag – ring' && str_contains((string) ($brfDry['suggested'] ?? ''), 'Slå mig en signal så ringer jag upp om jag inte kan svara direkt') && !str_contains((string) ($brfDry['body'] ?? ''), 'Min favorit') && count($GLOBALS['_SENT']) === $before, 'BRF förhandsvisas inte som mall och skickas inte');
$brfLive = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $brf['thread_id'], 'dry' => 0]);
$brfRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $r) {
    if (mb_strtolower((string) ($r['email'] ?? '')) === 'brf.eken.sr@gmail.com') {
        $brfRem = $r;
    }
}
sr(($brfLive['action'] ?? '') === 'hold' && is_array($brfRem) && str_contains((string) $brfRem['note'], 'BRF/företag – ring') && str_contains((string) $brfRem['note'], 'Slå mig en signal så ringer jag upp om jag inte kan svara direkt') && str_contains((string) $brfRem['note'], 'mail: https://flowstack.example.test/index.php?p=inkorg&id=') && count($GLOBALS['_SENT']) === $before, 'BRF hålls med påminnelse, förslag och mail');

$firm = $ingest('firm', 'info@wix-forms.com', 'Verkstan AB', "Namn: Verkstan AB\nE-post: verkstan.sr@gmail.com\nTelefon: 070-100 20 13\nAdress: Verkstadsgatan 3, 176 70 Järfälla\nMeddelande:\nVi behöver två elbilsladdare.\n", 'laddbox');
$firmDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $firm['thread_id'], 'dry' => 1]);
sr(($firmDry['why'] ?? '') === 'BRF/företag – ring' && count($GLOBALS['_SENT']) === $before, 'företag med två elbilsladdare hålls');

$dk = $ingest('dk', 'dans.kund.sr@gmail.com', 'Dans Kund', "Namn: Dans Kund\nE-post: dans.kund.sr@gmail.com\nTelefon: 070-100 20 14\nAdress: Köpenhamn\nMeddelande:\nVi bor i Danmark och vill ha batteri.\n", 'batteri');
$dkDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $dk['thread_id'], 'dry' => 1]);
sr(($dkDry['why'] ?? '') === 'BRF/företag – ring' && count($GLOBALS['_SENT']) === $before, 'utlandet hålls utan mall');

$deny = trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && python3 -c ' . escapeshellarg(
    'import importlib.util,sys; p=".github/scripts/loopia_deploy.py"; s=importlib.util.spec_from_file_location("loopia_deploy", p); m=importlib.util.module_from_spec(s); sys.modules["loopia_deploy"]=m; s.loader.exec_module(m); print(m.deny_reason("config/svarsregler.php") or "ok"); print(m.deny_reason("config/svarsregler.md") or "ok")'
)));
$denyLines = preg_split('/\R/', $deny) ?: [];
sr(($denyLines[0] ?? '') === 'ok' && ($denyLines[1] ?? '') === 'dokumentation', 'deploy tar med php och nekar fortfarande md');

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
sr($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$log = sys_get_temp_dir() . '/jeab-svarsregler-http-' . bin2hex(random_bytes(3)) . '.log';
$proc = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg(dirname(__DIR__)),
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes
);
sr(is_resource($proc), 'regelfilens server startade');
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
sr($up, 'regelfilens server lyssnar');
$ch = curl_init('http://127.0.0.1:' . $port . '/config/svarsregler.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$httpBody = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$httpText = is_string($httpBody) ? $httpBody : '';
sr($httpStatus === 403 && !str_contains($httpText, '101 500') && !str_contains($httpText, 'Slå mig en signal'), 'direktträff på regelfilen är 403');

echo "OK  svarsregler\n";
