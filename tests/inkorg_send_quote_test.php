<?php

declare(strict_types=1);

/** inbox.send_quote delar utskick med automatiken. dry=1 (standard) skickar inget. */

$tmp = sys_get_temp_dir() . '/jeab-send-quote-' . bin2hex(random_bytes(4));
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

function sq(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function sq_fn_src(string $fn): string
{
    $rf = new ReflectionFunction($fn);
    $file = $rf->getFileName();
    $lines = is_string($file) ? file($file) : false;
    if (!is_array($lines)) {
        return '';
    }
    return implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1));
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-sq', 'conversation_id' => 'CV', 'message_id' => 'sent-sq@x'];
};
$GLOBALS['_SENT_BOX'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) {
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => $GLOBALS['_SENT_BOX']]];
    }
    return ['code' => 404, 'body' => []];
};
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Ja, det ingår i offerten.']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
sq(is_string($jpeg) && str_starts_with($jpeg, "\xFF\xD8"), 'testbilden är JPEG');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode($jpeg)]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
sq(str_contains($hookSrc, "case 'inbox.send_quote':"), 'åtgärden finns bredvid auto_selftest');
sq(str_contains($hookSrc, "case 'inbox.gp_probe':") && str_contains($hookSrc, "case 'inbox.auto_selftest':"), 'samma hook som gp_probe');
$sendSrc = sq_fn_src('jeab_auto_send_quote');
$quoteSrc = sq_fn_src('jeab_auto_quote');
sq(str_contains($sendSrc, 'jeab_auto_quote(') && !str_contains($sendSrc, 'jeab_outbound_graph_send'), 'hooken duplicerar inte utskicket');
sq(str_contains($quoteSrc, 'jeab_outbound_graph_send'), 'automatiken skickar från den delade funktionen');
sq(in_array('drip.com', jeab_lead_platforms(), true) && array_search('drip.com', jeab_lead_platforms(), true) === array_search('getdrip.com', jeab_lead_platforms(), true) + 1, 'drip.com ligger intill getdrip.com');

$names = ['batteri' => 'Batteri', 'sol' => 'Solceller med batteri', 'laddbox' => 'Elbilsladdare'];
$ingest = function (string $id, string $from, string $fromName, string $body, string $offer, array $extra = []) use ($names): array {
    $labels = [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
    ];
    $company = ($extra['company'] ?? '') === 'malmo' ? 'malmo' : 'jarfalla';
    unset($extra['company']);
    $internet = (string) ($extra['internet_message_id'] ?? '');
    unset($extra['internet_message_id']);
    $box = jeab_graph_mailboxes()[$company];
    $row = [
        'message_id' => $id . '@sq.test',
        'from' => $from,
        'from_name' => $fromName,
        'to' => $box,
        'subject' => $names[$offer] ?? 'Offert',
        'body_text' => $body,
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'sq-' . $id,
        'preset_labels' => array_merge($labels, $extra['labels'] ?? []),
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
    ];
    if ($internet !== '') {
        $row['internet_message_id'] = $internet;
    }
    return jeab_inkorg_ingest($row);
};

$notes = function (string $tid): string {
    $bits = [];
    foreach ((array) (jeab_auto_thread_by_id($tid)['drafts'] ?? []) as $d) {
        if (($d['kind'] ?? '') === 'note') {
            $bits[] = (string) ($d['body'] ?? '');
        }
    }
    return implode("\n", $bits);
};
$remCount = function (string $email): int {
    $n = 0;
    foreach (jeab_list_reminders(jeab_store(), true) as $r) {
        if (mb_strtolower((string) ($r['email'] ?? '')) === mb_strtolower($email)) {
            $n++;
        }
    }
    return $n;
};
$namesOf = function (array $atts): array {
    $out = [];
    foreach ($atts as $att) {
        $out[] = (string) ($att['filename'] ?? '');
    }
    return $out;
};

$leakAddress = 'Decembervägen 10, 17760 Järfälla';
$phoneFor = static function (string $email): string {
    $n = hexdec(substr(md5(mb_strtolower($email)), 0, 6)) % 10000000;

    return '070' . str_pad((string) $n, 7, '0', STR_PAD_LEFT);
};
$battBody = function (string $name, string $email) use ($leakAddress, $phoneFor): string {
    $phone = $phoneFor($email);

    return "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nNamn {$name}\n\nAdress:\n\nAdress {$leakAddress}\n\nTelefon:\n\nTelefon {$phone}\n\nE-post:\n\nE-post {$email}\n";
};
$solBody = function (string $name, string $email) use ($leakAddress, $phoneFor): string {
    $phone = $phoneFor($email);

    return "Källa: Hitta-Solceller via Drip\nNamn: Namn {$name}\nE-post: E-post {$email}\nTelefon: Telefon {$phone}\nAdress: Adress {$leakAddress}\n";
};

$same = function (string $label, array $dry, array $sent) use ($namesOf): void {
    $files = $namesOf((array) ($sent['attachments'] ?? []));
    sq(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1, $label . ' dry=1 är förhandsvisning');
    sq(($dry['body'] ?? '') !== '' && ($dry['body'] ?? null) === ($sent['body'] ?? null), $label . ' brödtexten är identisk med automatiken');
    sq(($dry['subject'] ?? '') === ($sent['subject'] ?? '') && ($dry['recipient'] ?? '') === ($sent['to'] ?? ''), $label . ' ämne och mottagare är identiska');
    sq(($dry['sender'] ?? '') === (string) ($sent['opts']['person_email'] ?? ''), $label . ' avsändaren är identisk');
    sq(($dry['attachments'] ?? null) === $files, $label . ' bilagenamnen är identiska');
    sq(!str_contains(json_encode($dry), '%PDF'), $label . ' svaret innehåller inte bilagebyte');
};

$closeThread = static function (string $tid): void {
    jeab_inkorg_thread_write($tid, static function (array $t): array {
        $t['status'] = 'closed';
        return $t;
    });
};
$before = count($GLOBALS['_SENT']);
$bDryIn = $ingest('batt-dry', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Mia Holm', 'mia.holm@gmail.com'), 'batteri');
$bDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $bDryIn['thread_id']]);
sq(count($GLOBALS['_SENT']) === $before, 'dry utan parameter skickar inget');
sq((jeab_inkorg_thread_meta((string) $bDryIn['thread_id'])['status'] ?? '') === 'open' && $notes((string) $bDryIn['thread_id']) === '', 'dry rör inte ärendet');
$closeThread((string) $bDryIn['thread_id']);
$bAutoIn = $ingest('batt-auto', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Mia Holm', 'mia.holm@gmail.com'), 'batteri');
$bAuto = jeab_auto_on_message((string) $bAutoIn['id']);
sq(($bAuto['action'] ?? '') === 'quoted', 'batteri-automatiken skickar');
$bSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
$same('Batteri', $bDry, $bSent);
sq(($bDry['template'] ?? '') === 'Batteri' && ($bDry['sender'] ?? '') === 'saljare3@jarfalla.example.test' && ($bDry['subject'] ?? '') === 'Offert batteri', 'Batteri från saljare3@ med ämnet Offert batteri');
sq(str_contains((string) $bDry['body'], 'Hej Mia,') && !str_contains((string) $bDry['body'], 'Mia Holm') && !str_contains((string) $bDry['body'], 'mia.holm@gmail.com') && !str_contains((string) $bDry['body'], $leakAddress), 'Batteri-offerten har ingen kundrad efter signaturen');
sq(!str_contains((string) $bDry['body'], 'Adress ' . $leakAddress) && !str_contains((string) $bDry['body'], 'Namn Mia'), 'Adress och Namn läcker inte in i batteriofferten');
$muted = 'color:#808080; font-style:italic';
sq(substr_count((string) $bDry['body'], $muted) === 4 && str_contains((string) $bDry['body'], 'src="cid:team-jarfalla-energi.jpg"') && str_contains((string) $bDry['body'], 'width="280"'), 'Batteri har grå kursiv och teambild');
sq(($bDry['attachments'] ?? []) === ['Batteri-offert.pdf', 'team-jarfalla-energi.jpg'], 'Batteri-bilagorna heter rätt');
sq((jeab_inkorg_thread_meta((string) $bDryIn['thread_id'])['status'] ?? '') === 'closed' && $notes((string) $bDryIn['thread_id']) === '' && $remCount('mia.holm@gmail.com') === 1, 'dry skriver ingen anteckning; tvillingens påminnelse stänger syskontråden');
sq($remCount('mia.holm@gmail.com') === 1, 'påminnelsen kommer från tvillingens automatik');

$before = count($GLOBALS['_SENT']);
$sDryIn = $ingest('sol-dry', 'notifications@drip.com', 'Drip', $solBody('Nora Drip', 'nora.drip@gmail.com'), 'sol');
$sDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $sDryIn['thread_id'], 'dry' => 1]);
sq(count($GLOBALS['_SENT']) === $before, 'dry=1 skickar inget');
$closeThread((string) $sDryIn['thread_id']);
$sAutoIn = $ingest('sol-auto', 'notifications@drip.com', 'Drip', $solBody('Nora Drip', 'nora.drip@gmail.com'), 'sol');
$sAuto = jeab_auto_on_message((string) $sAutoIn['id']);
sq(($sAuto['action'] ?? '') === 'quoted' && ($sAuto['mall'] ?? '') === 'A', 'drip.com får mall A');
$sSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
$same('Sol A', $sDry, $sSent);
sq(($sDry['template'] ?? '') === 'A' && ($sDry['sender'] ?? '') === 'demo@example.test' && ($sDry['subject'] ?? '') === 'Solceller med batteri', 'Sol A från sam@');
sq(str_contains((string) $sDry['body'], 'Du har visat intresse för att installera solceller med oss!') && !str_contains((string) $sDry['body'], $leakAddress) && !str_contains((string) $sDry['body'], 'nora.drip@gmail.com') && !str_contains((string) $sDry['body'], 'Nora Drip'), 'Sol A är köpt-mallen utan kundblock efter signaturen');
sq(($sDry['attachments'] ?? []) === ['Solceller offert 2608 Demo Energi Järfälla.pdf'], 'Sol A har sol-PDF:en och ingen teambild');
sq(($sDry['recipient'] ?? '') === 'nora.drip@gmail.com' && !str_contains((string) $sDry['recipient'], 'drip.com'), 'mottagaren är kunden, inte drip.com');

$before = count($GLOBALS['_SENT']);
$qDryIn = $ingest('sol-q-dry', 'notifications@drip.com', 'Drip', $solBody('Mia Fraga', 'mia.fraga.sq@gmail.com') . "\nHur många paneler får plats?\n", 'sol', ['labels' => ['question' => 'Hur många paneler får plats?']]);
$qDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $qDryIn['thread_id'], 'dry' => '1']);
$closeThread((string) $qDryIn['thread_id']);
$qAutoIn = $ingest('sol-q-auto', 'notifications@drip.com', 'Drip', $solBody('Mia Fraga', 'mia.fraga.sq@gmail.com') . "\nHur många paneler får plats?\n", 'sol', ['labels' => ['question' => 'Hur många paneler får plats?']]);
$qAuto = jeab_auto_on_message((string) $qAutoIn['id']);
sq(($qAuto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'frågan skickas när svaret finns');
$qSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
$same('Sol A med fråga', $qDry, $qSent);
sq(preg_match('~^<div[^>]*>Ja, det ingår i offerten\.</div><div[^>]*><br></div><div[^>]*>Hej Mia,~u', (string) $qDry['body']) === 1, 'det korta svaret ligger först, likadant som automatiken');

$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): array => ['error' => 'AI-fel: HTTP 401'];
$before = count($GLOBALS['_SENT']);
$failIn = $ingest('sol-q-fail', 'notifications@drip.com', 'Drip', $solBody('Fail Fraga', 'fail.fraga.sq@gmail.com') . "\nHur många paneler får plats på just det här taket?\n", 'sol', ['labels' => ['question' => 'Hur många paneler får plats på just det här taket?']]);
$fail = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $failIn['thread_id'], 'dry' => 0]);
$failBody = (string) ($GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1]['body'] ?? '');
sq(($fail['ok'] ?? false) === true && ($fail['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1 && str_contains($failBody, 'Du har visat intresse') && !str_contains($failBody, 'HTTP 401'), 'AI-fel skickar Notis-texten utan det korta svaret');
sq((jeab_inkorg_thread_meta((string) $failIn['thread_id'])['status'] ?? '') === 'closed', 'hållet ärende med påminnelse lämnar öppna vyer');
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Ja, det ingår i offerten.']);

$lIn = $ingest('ladd-dry', 'bosse.sq@gmail.com', 'Bosse Berg', "Hej, jag vill ha en elbilsladdare.\nNamn: Bosse Berg\nE-post: bosse.sq@gmail.com\nTelefon: 070-111 22 33\nAdress: Bossevägen 1, 176 70 Järfälla\n", 'laddbox');
$lDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $lIn['thread_id'], 'dry' => 1]);
sq(($lDry['ok'] ?? false) === true && ($lDry['template'] ?? '') === 'Elbilsladdare' && ($lDry['sender'] ?? '') === 'saljare4@jarfalla.example.test' && str_starts_with((string) ($lDry['subject'] ?? ''), 'Re: '), 'Elbilsladdare från elin@');
sq(!str_contains((string) ($lDry['body'] ?? ''), '#808080') && in_array('Elbilsladdare-offert.pdf', (array) ($lDry['attachments'] ?? []), true), 'Elbilsladdare behåller sin mall och PDF');

$malmo = $ingest('malmo', 'malmo.kund@gmail.com', 'Malmö Kund', "Namn: Malmö Kund\nE-post: malmo.kund@gmail.com\nAdress: Testgatan 1, 176 70 Järfälla\n", 'batteri', ['company' => 'malmo']);
$before = count($GLOBALS['_SENT']);
$malmoRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $malmo['thread_id'], 'dry' => 0]);
sq(($malmoRes['ok'] ?? true) === false && ($malmoRes['why'] ?? '') === 'bolaget hanteras i Help Scout' && count($GLOBALS['_SENT']) === $before, 'Malmö i Help Scout hålls och skickar inte');
$skane = $ingest('skane', 'lund.kund@gmail.com', 'Lund Kund', "Namn: Lund Kund\nE-post: lund.kund@gmail.com\nTelefon: 070-444 55 66\nAdress: Lundavägen 2, 222 23 Lund\n", 'sol');
$skaneRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $skane['thread_id'], 'dry' => 1]);
sq(($skaneRes['ok'] ?? true) === false && ($skaneRes['why'] ?? '') === 'Malmö/Skåne', 'Skåne-postnummer nekas');
$word = $ingest('skane-ord', 'skane.ord@gmail.com', 'Skåne Ord', "Namn: Skåne Ord\nE-post: skane.ord@gmail.com\nAdress: Byvägen 1, 176 70 Järfälla\nVi bor i Skåne.\n", 'sol');
sq((jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $word['thread_id'], 'dry' => 1])['why'] ?? '') === 'bolaget hanteras i Help Scout', 'ordet Skåne gör ärendet till Malmö');

$testIn = $ingest('test', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Testar Lite', 'test@test.se'), 'batteri');
$testRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $testIn['thread_id'], 'dry' => 0]);
sq(($testRes['action'] ?? '') === 'hold' && str_contains((string) ($testRes['why'] ?? ''), 'test') && count($GLOBALS['_SENT']) === $before, 'testinlämning nekas');

$plat = $ingest('plattform', 'notifications@drip.com', 'Drip', "Namn: Vera Plattform\nE-post: notifications@drip.com\nAdress: Veravägen 1, 176 70 Järfälla\n", 'sol');
$platRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $plat['thread_id'], 'dry' => 0]);
sq(($platRes['action'] ?? '') === 'hold' && str_contains((string) ($platRes['why'] ?? ''), 'notifications@drip.com') && count($GLOBALS['_SENT']) === $before, 'plattformsadress nekas som mottagare');

$again = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $bAutoIn['thread_id'], 'dry' => 1]);
sq(($again['ok'] ?? true) === false && str_contains((string) ($again['why'] ?? ''), 'anteckning') && !array_key_exists('body', $again), 'anteckning om skickad offert stoppar en ny');
$before = count($GLOBALS['_SENT']);
$againSend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $bAutoIn['thread_id'], 'dry' => 0]);
sq(($againSend['action'] ?? '') === 'hold' && count($GLOBALS['_SENT']) === $before && $remCount('mia.holm@gmail.com') === 1, 'andra försöket skickar inte och skapar ingen ny påminnelse');

$boxIn = $ingest('sent-box', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Sent Box', 'sent.box@gmail.com'), 'batteri');
$GLOBALS['_SENT_BOX'] = [[
    'id' => 'S1',
    'subject' => 'Offert batteri',
    'toRecipients' => [['emailAddress' => ['address' => 'Sent.Box@gmail.com']]],
    'sentDateTime' => '2026-10-16T08:00:00Z',
    'hasAttachments' => false,
]];
$before = count($GLOBALS['_SENT']);
$boxRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $boxIn['thread_id'], 'dry' => 0]);
sq(($boxRes['action'] ?? '') === 'hold' && str_contains((string) ($boxRes['why'] ?? ''), 'Skickat') && count($GLOBALS['_SENT']) === $before, 'Skickat till kunden stoppar offerten');
$GLOBALS['_SENT_BOX'] = [];
$boxOk = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $boxIn['thread_id'], 'dry' => 1]);
sq(($boxOk['ok'] ?? false) === true && ($boxOk['recipient'] ?? '') === 'sent.box@gmail.com', 'tomt Skickat släpper igenom förhandsvisningen');

$graph = $GLOBALS['_JEAB_GRAPH_HTTP'];
$GLOBALS['_JEAB_GRAPH_HTTP'] = fn() => ['code' => 403, 'body' => ['error' => ['message' => 'SECRET']]];
$gIn = $ingest('graph-fail', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Graph Fail', 'graph.fail@gmail.com'), 'batteri');
$gRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $gIn['thread_id'], 'dry' => 1]);
sq(($gRes['action'] ?? '') === 'hold' && str_contains((string) ($gRes['why'] ?? ''), 'Skickat') && !str_contains(json_encode($gRes), 'SECRET'), 'oläsbart Skickat håller utan att läcka Graph-svaret');
$GLOBALS['_JEAB_GRAPH_HTTP'] = $graph;

$freshEmail = 'ny.paminnelse.sq@gmail.com';
$fresh = $ingest('fresh', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Nils Ny', $freshEmail), 'batteri');
sq($remCount($freshEmail) === 0, 'ingen påminnelse före utskick');
$before = count($GLOBALS['_SENT']);
$sentOnce = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $fresh['thread_id'], 'dry' => '0']);
sq(($sentOnce['ok'] ?? false) === true && ($sentOnce['action'] ?? '') === 'quoted' && ($sentOnce['from'] ?? '') === 'saljare3@jarfalla.example.test' && count($GLOBALS['_SENT']) === $before + 1, 'dry=0 skickar samma offert');
sq((jeab_inkorg_thread_meta((string) $fresh['thread_id'])['status'] ?? '') === 'closed', 'dry=0 stänger ärendet');
$freshNotes = $notes((string) $fresh['thread_id']);
sq(str_contains($freshNotes, 'Offert (Batteri) skickad manuellt') && str_contains($freshNotes, 'från saljare3@jarfalla.example.test till ' . $freshEmail) && str_contains($freshNotes, 'Påminnelse skapad') && str_contains($freshNotes, 'Ärendet stängt.'), 'manuellt send_quote antecknas som manuellt');
sq($remCount($freshEmail) === 1, 'en påminnelse skapas');
$before = count($GLOBALS['_SENT']);
$sentTwice = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $fresh['thread_id'], 'dry' => 0]);
sq(($sentTwice['action'] ?? '') === 'hold' && count($GLOBALS['_SENT']) === $before && $remCount($freshEmail) === 1, 'fanns påminnelsen redan skapas ingen till');

$haveEmail = 'redan.fanns.sq@gmail.com';
$have = $ingest('have', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Eva Finns', $haveEmail), 'batteri');
$haveTid = (string) $have['thread_id'];
jeab_create_reminder(jeab_store(), 'Eva Finns – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Eva Finns', 'email' => $haveEmail, 'company' => 'jarfalla', 'who' => 'Roberto',
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode($haveTid),
]);
sq($remCount($haveEmail) === 1, 'påminnelsen finns före hooken');
$haveRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $haveTid, 'dry' => 0]);
sq(($haveRes['ok'] ?? false) === true && $remCount($haveEmail) === 1 && str_contains($notes($haveTid), 'Påminnelse fanns redan.'), 'befintlig trådpåminnelse dupliceras inte');

sq((jeab_inkorg_hook(['action' => 'inbox.send_quote'])['error'] ?? '') === 'thread_id saknas', 'thread_id krävs');
sq((jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => 'finns-inte'])['error'] ?? '') === 'not_found', 'okänt ärende är not_found');

$plainAsk = $ingest('plain-ask', 'bosse.plain.sq@gmail.com', 'Bosse Plain', "Hej, jag vill ha en elbilsladdare.\nNamn: Bosse Plain\nE-post: bosse.plain.sq@gmail.com\nTelefon: 070-101 00 01\nAdress: Plainvägen 1, 176 70 Järfälla\n", 'laddbox');
$plain = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $plainAsk['thread_id'], 'dry' => 1]);
sq(($plain['ok'] ?? false) === true && ($plain['template'] ?? '') === 'Elbilsladdare' && !str_contains((string) ($plain['body'] ?? ''), 'Ja, det ingår i offerten.') && !str_contains((string) ($plain['body'] ?? ''), 'Storleken tar vi med'), 'vanlig önskan om elbilsladdare får ingen sakfråga');

$GLOBALS['_JEAB_REBOOK_LLM'] = static fn() => json_encode(['answer' => '']);
$sizeHold = $ingest('size-hold', 'sara.size.sq@gmail.com', 'Sara Size', "Hej, vi vill ha 20 kWh utbyggbart till 40.\nNamn: Sara Size\nE-post: sara.size.sq@gmail.com\nTelefon: 070-202 00 02\nAdress: Sizevägen 2, 176 70 Järfälla\n", 'batteri');
$before = count($GLOBALS['_SENT']);
$sizeHoldRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $sizeHold['thread_id'], 'dry' => 1]);
sq(($sizeHoldRes['ok'] ?? false) === true && ($sizeHoldRes['action'] ?? '') !== 'hold' && str_contains((string) ($sizeHoldRes['body'] ?? ''), jeab_auto_answer_safe_line()) && !str_contains((string) ($sizeHoldRes['body'] ?? ''), '20 kWh utbyggbart') && count($GLOBALS['_SENT']) === $before, 'sakfråga utan fakta får den säkra raden och hålls inte');

$GLOBALS['_JEAB_REBOOK_LLM'] = static function (array $messages): string {
    $user = (string) ($messages[1]['content'] ?? '');
    if (str_contains($user, '20 kWh') || str_contains($user, 'Sigenergy') || str_contains($user, '38 kw')) {
        return json_encode(['answer' => 'Storleken tar vi med i offerten.'], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['answer' => 'Ja, det ingår i offerten.']);
};
$answerFirst = static function (string $id, string $name, string $email, string $text) use ($ingest): array {
    $in = $ingest($id, $email, $name, $text . "\nNamn: {$name}\nE-post: {$email}\nTelefon: 070-303 00 03\nAdress: Svarvägen 3, 176 70 Järfälla\n", 'batteri');
    $dry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $in['thread_id'], 'dry' => 1]);
    return $dry;
};
foreach ([
    'kwh' => 'Vi tittar på 20 kWh som går att bygga ut till 40.',
    'sig' => 'Vi vill ha Sigenergy 18 kWh plus elbilsladdare.',
    'pris' => 'vad kostar batteri med 38 kw?',
] as $id => $text) {
    $dry = $answerFirst('ans-' . $id, 'Maja Svar', 'maja.' . $id . '.sq@gmail.com', $text);
    $body = (string) ($dry['body'] ?? '');
    $answerAt = strpos($body, 'Storleken tar vi med i offerten.');
    $tplAt = strpos($body, 'Den minsta investeringen');
    sq(($dry['ok'] ?? false) === true && preg_match('~Hej Maja,</div><div[^>]*><br></div><div[^>]*>Storleken tar vi med i offerten\.~u', $body) === 1 && $answerAt !== false && $tplAt !== false && $answerAt < $tplAt, 'sakfråga ' . $id . ' svaras före mallen');
}

$tb = $ingest('torbjorn', 'torbjorn.ek@gmail.com', 'Torbjörn Ek', "Hej, jag vill ha ett batteri.\nNamn: Torbjörn Ek\nE-post: torbjorn.ek@gmail.com\nTelefon: 070-404 00 04\nAdress: Torbjörns väg 5, 176 70 Järfälla\n", 'batteri');
$tbDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $tb['thread_id'], 'dry' => 1]);
sq(($tbDry['ok'] ?? false) === true && str_contains((string) ($tbDry['body'] ?? ''), 'Hej Torbjörn,') && !str_contains((string) ($tbDry['body'] ?? ''), 'Torbjorn'), 'hälsningen behåller åäö');

$gissBody = "Hej,\nVi vill ha ett batteri till villan på Ekströms väg 5, 176 70 Järfälla.\n\nMvh\nMårten Ekström\n";
$gissMsg = ['from' => 'marten.ekstrom@gmail.com', 'from_name' => 'Marten', 'subject' => 'Batteri', 'body' => $gissBody];
$gissCust = jeab_lead_customer($gissMsg);
sq($gissCust['name'] === 'Mårten Ekström' && jeab_auto_greeting_name($gissMsg, $gissCust) === 'Mårten', 'signaturen Mårten Ekström slår local-delen marten');
$gissAscii = jeab_lead_customer(['from' => 'marten.ekstrom@gmail.com', 'from_name' => 'Marten Ekstrom', 'body' => $gissBody]);
sq($gissAscii['name'] === 'Mårten Ekström', 'From utan accenter byts mot signaturen');
$gissEmpty = jeab_lead_customer(['from' => 'marten.ekstrom@gmail.com', 'from_name' => '', 'body' => $gissBody]);
sq($gissEmpty['name'] === 'Mårten Ekström' && $gissEmpty['email'] === 'marten.ekstrom@gmail.com', 'tomt visningsnamn läser signaturen');
$giss = $ingest('ekstrom', 'marten.ekstrom@gmail.com', 'Marten', $gissBody, 'batteri');
$gissDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $giss['thread_id'], 'dry' => 1]);
$gissHtml = (string) ($gissDry['body'] ?? '');
sq(($gissDry['ok'] ?? false) === true && str_contains($gissHtml, 'Hej Mårten,') && !str_contains($gissHtml, 'Mårten Ekström') && !str_contains($gissHtml, 'Marten'), 'marten.ekstrom@ hälsas Hej Mårten utan kundblock');

$prompts = [];
$sysPrompts = [];
$GLOBALS['_JEAB_REBOOK_LLM'] = static function (array $messages) use (&$prompts, &$sysPrompts): string {
    $user = (string) ($messages[1]['content'] ?? '');
    $prompts[] = $user;
    $sysPrompts[] = (string) ($messages[0]['content'] ?? '');
    if (str_contains($user, '20 kWh') && str_contains($user, '40 kWh')) {
        return json_encode(['answer' => 'För de flesta är Sigenergy 18 kWh en bra nivå.'], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['answer' => 'SolarX 38 kW kostar 120 000 kr och elbilsladdaren är extra.'], JSON_UNESCAPED_UNICODE);
};
$safe = jeab_auto_answer_safe_line();
sq($safe === 'Storlek och utformning anpassar vi efter era behov, och det går vi igenom tillsammans utifrån offerten.' && !str_starts_with($safe, 'Tack') && !str_contains($safe, 'nedan'), 'säkra raden nämner varken tack eller nedan');
sq(!jeab_auto_answer_matches_request('För de flesta är Sigenergy 18 kWh en bra nivå.', 'Vi vill ha 20 kWh som går att bygga ut till 40 kWh.') && jeab_auto_answer_matches_request('Sigenergy 18 kWh finns med.', 'Vi vill ha Sigenergy 18 kWh.'), 'en annan kapacitet än kundens räknas inte');
$detail = "Hej,\nVi vill ha ett batteri på 20 kWh som går att bygga ut till 40 kWh.\nHur stort rekommenderar ni, och vad kostar det?\n";
$tbDetail = $ingest('torbjorn-detalj', 'torbjorn.detalj.sq@gmail.com', 'Torbjörn Ek', $detail . "Namn: Torbjörn Ek\nE-post: torbjorn.detalj.sq@gmail.com\nTelefon: 070-404 00 05\nAdress: Torbjörns väg 5, 176 70 Järfälla\ngclid: CjwKCAjw1234567890abc\n", 'batteri');
$tbDetailDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $tbDetail['thread_id'], 'dry' => 1]);
$tbPrompt = (string) ($prompts[count($prompts) - 1] ?? '');
$tbSys = (string) ($sysPrompts[count($sysPrompts) - 1] ?? '');
$tbBody = (string) ($tbDetailDry['body'] ?? '');
sq(($tbDetailDry['ok'] ?? true) === false && ($tbDetailDry['action'] ?? '') === 'hold' && str_contains((string) ($tbDetailDry['why'] ?? ''), 'priset står inte i svarsreglerna') && !str_contains((string) ($tbDetailDry['body'] ?? ''), '18 kWh'), 'Torbjörn: pris som inte står i svarsreglerna hålls');
sq(str_contains($tbSys, 'Svarsregler') && str_contains($tbSys, '101 500') && str_contains($tbPrompt, '20 kWh') && str_contains($tbPrompt, '40 kWh') && str_contains($tbPrompt, 'vad kostar det') && !str_contains($tbPrompt, 'Torbjörns väg') && !str_contains($tbPrompt, 'gclid') && !str_contains($tbPrompt, 'CjwKCAj'), 'Torbjörn: frågan är bara den rensade fritexten och reglerna följer med');

$carvajalBody = "A site visitor just submitted your form: Kontakt Formulär\u{200E}<https://malmo.example.test/so/tr/abc>\n\nNamn:\n\nLuis Carvajal\n\nE-post:\n\nE-post luis.carvajal.sq@gmail.com\n\nTelefon:\n\nTelefon 070-606 00 06\n\nAdress:\n\nAdress {$leakAddress}\n\nMeddelande:\n\nSigenergy stor 18kwh och elbilsladdare\n\ngclid: Cj0KCQjw1234567890xyz\n/laddbox\n";
$carvajal = $ingest('carvajal', 'info@wix-forms.com', 'Formulär JEAB', $carvajalBody, 'batteri');
$carvajalDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $carvajal['thread_id'], 'dry' => 1]);
$carPrompt = (string) ($prompts[count($prompts) - 1] ?? '');
sq(($carvajalDry['ok'] ?? false) === true && ($carvajalDry['recipient'] ?? '') === 'luis.carvajal.sq@gmail.com' && str_contains((string) ($carvajalDry['body'] ?? ''), $safe) && !str_contains((string) ($carvajalDry['body'] ?? ''), '120 000') && !str_contains((string) ($carvajalDry['body'] ?? ''), 'SolarX'), 'Carvajal: ogrunda svar blir den säkra raden');
sq(str_contains($carPrompt, 'Sigenergy stor 18kwh och elbilsladdare') && !str_contains($carPrompt, 'malmo.example.test') && !str_contains($carPrompt, 'Cj0KCQ') && !str_contains($carPrompt, '/laddbox') && !str_contains($carPrompt, 'Luis Carvajal'), 'Carvajal: bara meddelandefältet går till AI');

$weplyBody = "Namn: Nora Alm\nE-post: nora.alm.sq@gmail.com\nTelefon: 070-222 11 00\nAdress: Almgatan 3, 176 74 Järfälla\n\nHej, jag vill ha offert på solceller.\n\nNora Alm\n\nVi tar fram en offert redan i veckan.\nElla, Weply\n\nVad kostar batteri med 38 kw?\n\nNora Alm\n\nDet blir ungefär nittio tusen, jag återkommer.\nElla, Weply\n\nDen här chatten bemannas av människor, inte robotar.\nVad händer nu?\nRing leadet!\nOm du inte är den avsedda mottagaren av det här meddelandet ska du radera det.\n";
$weplyQ = $ingest('weply-38', 'noreply@weply.se', 'Weply', $weplyBody, 'batteri');
$weplyDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $weplyQ['thread_id'], 'dry' => 1]);
$weplyPrompt = (string) ($prompts[count($prompts) - 1] ?? '');
sq(($weplyDry['ok'] ?? true) === false && ($weplyDry['action'] ?? '') === 'hold' && str_contains((string) ($weplyDry['why'] ?? ''), 'priset står inte i svarsreglerna') && !str_contains((string) ($weplyDry['body'] ?? ''), '38') && !str_contains((string) ($weplyDry['body'] ?? ''), '120 000'), 'Weply: 38 kw utan pris i svarsreglerna hålls');
sq(str_contains($weplyPrompt, 'Vad kostar batteri med 38 kw') && str_contains($weplyPrompt, 'offert på solceller') && !str_contains($weplyPrompt, 'nittio tusen') && !str_contains($weplyPrompt, 'Ella') && !str_contains($weplyPrompt, 'Ring leadet'), 'Weply: agentraderna är inte frågan');

$storeBody = "Ny beställning mottagen\nEasee Charge Up laddbox med installation\nNamn: Kim Order\nE-post: kim.order.sq@gmail.com\nTelefon: 070-404 11 22\nAdress: Ordervägen 8, 176 70 Järfälla\n";
sq(!in_array('stores-emails.com', jeab_lead_platforms(), true) && jeab_lead_platform('info@stores-emails.com') === 'stores-emails.com' && jeab_lead_store_sender('orders@wix.com') === 'wix.com' && jeab_lead_platform_lead_only('orders@wix.com') === '', 'butiksavsändare löser kunden men ingår inte i leadlistan');
$storeCust = jeab_lead_customer(['from' => 'info@stores-emails.com', 'from_name' => 'Wix Stores', 'body' => $storeBody]);
sq($storeCust['email'] === 'kim.order.sq@gmail.com' && $storeCust['name'] === 'Kim Order' && $storeCust['platform'] === 'stores-emails.com', 'Wix Stores: kunden läses ur ordertexten');
$store = $ingest('stores', 'info@stores-emails.com', 'Wix Stores', $storeBody, 'laddbox');
$storeTid = (string) $store['thread_id'];
$storeThread = jeab_auto_thread_by_id($storeTid);
sq(is_array($storeThread) && jeab_lead_is_store_mail((array) $storeThread['first']) && !jeab_lead_is_lead($storeThread, (array) $storeThread['first']), 'Ny lead med laddbox från stores-emails.com är inte ett lead');
$before = count($GLOBALS['_SENT']);
$storeAuto = jeab_auto_on_message((string) $store['id']);
sq(($storeAuto['action'] ?? '') === 'skip' && str_contains((string) ($storeAuto['why'] ?? ''), 'butiksorder') && count($GLOBALS['_SENT']) === $before && (jeab_inkorg_thread_meta($storeTid)['status'] ?? '') !== 'closed' && $remCount('kim.order.sq@gmail.com') === 0, 'butiksorder skickar ingen offert, stänger inte och skapar ingen påminnelse');
$storeSend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $storeTid, 'dry' => 0]);
sq(($storeSend['ok'] ?? true) === false && ($storeSend['action'] ?? '') === 'hold' && str_contains((string) ($storeSend['why'] ?? ''), 'butiksorder') && count($GLOBALS['_SENT']) === $before && (jeab_inkorg_thread_meta($storeTid)['status'] ?? '') !== 'closed' && $remCount('kim.order.sq@gmail.com') === 0, 'send_quote nekar samma butiksorder');
$wixStoreBody = str_replace('kim.order.sq@gmail.com', 'kim.wixstore.sq@gmail.com', $storeBody);
$wixStore = $ingest('wix-store', 'orders@wix.com', 'Wix Stores', $wixStoreBody, 'laddbox');
$wixTid = (string) $wixStore['thread_id'];
$before = count($GLOBALS['_SENT']);
$wixAuto = jeab_auto_on_message((string) $wixStore['id']);
sq(($wixAuto['action'] ?? '') === 'skip' && count($GLOBALS['_SENT']) === $before && (jeab_inkorg_thread_meta($wixTid)['status'] ?? '') !== 'closed' && $remCount('kim.wixstore.sq@gmail.com') === 0, 'orders@wix.com är samma butiksstopp');

$keyPath = jeab_data_dir() . '/secrets/inkorg-llm.json';
$savedKey = (string) file_get_contents($keyPath);
$savedLlm = $GLOBALS['_JEAB_REBOOK_LLM'];
unset($GLOBALS['_JEAB_REBOOK_LLM']);
$sentinel = 'sk-' . bin2hex(random_bytes(8));
file_put_contents($keyPath, json_encode(['key' => $sentinel]));
$probeOn = jeab_gp_probe();
sq(($probeOn['ai_key_present'] ?? null) === true && !str_contains((string) json_encode($probeOn), $sentinel), 'gp_probe visar ai_key_present utan nyckeln');
unlink($keyPath);
putenv('XAI_API_KEY');
$missing = jeab_auto_answer('vad kostar batteri med 10 kw?', 'Ingen siffra här.', 'vad kostar batteri med 10 kw?');
sq(($missing['error'] ?? '') === 'AI-fel: saknad nyckel' && ($missing['answer'] ?? '') === '', 'saknad nyckel är ett AI-fel');
$probeOff = jeab_gp_probe();
sq(($probeOff['ai_key_present'] ?? null) === false, 'gp_probe visar ai_key_present false utan nyckel');
file_put_contents($keyPath, $savedKey);
$GLOBALS['_JEAB_REBOOK_LLM'] = $savedLlm;

$emptyOffer = $ingest('batt-no-offer', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Kim Batteri', 'kim.batteri.sq@gmail.com'), 'batteri', ['labels' => ['offer' => '']]);
$emptyTid = (string) $emptyOffer['thread_id'];
$remBefore = $remCount('kim.batteri.sq@gmail.com');
$assigneeBefore = (string) (jeab_inkorg_thread_meta($emptyTid)['assignee'] ?? '');
$emptyDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $emptyTid, 'dry' => 1]);
sq(($emptyDry['ok'] ?? false) === true && ($emptyDry['template'] ?? '') === 'Batteri' && !str_contains((string) ($emptyDry['why'] ?? ''), 'okänd produkt'), 'dry=1 tar produkten från leadsteget');
sq((jeab_inkorg_thread_meta($emptyTid)['status'] ?? '') === 'open' && $notes($emptyTid) === '' && $remCount('kim.batteri.sq@gmail.com') === $remBefore && (string) (jeab_inkorg_thread_meta($emptyTid)['assignee'] ?? '') === $assigneeBefore, 'dry=1 skriver inget när produkten härleds');
$emptyLiveIn = $ingest('batt-no-offer-live', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Kim Live', 'kim.live.sq@gmail.com'), 'batteri', ['labels' => ['offer' => '']]);
$before = count($GLOBALS['_SENT']);
$emptyLive = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $emptyLiveIn['thread_id'], 'dry' => 0]);
sq(($emptyLive['ok'] ?? false) === true && ($emptyLive['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'dry=0 med tom offer hittar också batteri');
$GLOBALS['_JEAB_REBOOK_LLM'] = static fn() => json_encode(['answer' => 'Ja, det ingår i offerten.']);

$httpBattIn = $ingest('http-batt', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Http Mia', 'http.mia.sq@gmail.com'), 'batteri');
$httpSolIn = $ingest('http-sol', 'notifications@drip.com', 'Drip', $solBody('Http Nora', 'http.nora.sq@gmail.com'), 'sol');
$expectBatt = (string) (jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $httpBattIn['thread_id'], 'dry' => 1])['body'] ?? '');
$expectSol = (string) (jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $httpSolIn['thread_id'], 'dry' => 1])['body'] ?? '');
sq($expectBatt !== '' && str_contains($expectBatt, 'Hej Http,'), 'HTTP-batteriet har en egen förhandsvisning');
sq($expectSol !== '' && str_contains($expectSol, 'Hej Http,'), 'HTTP-solen har en egen förhandsvisning');
$httpRoot = sys_get_temp_dir() . '/jeab-send-quote-http-' . bin2hex(random_bytes(4));
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$sendLog = $httpRoot . '/sent.jsonl';
$router = $httpRoot . '/router.php';
$log = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_SQ_DATA');
$root = getenv('JEAB_SQ_ROOT');
$sendLog = getenv('JEAB_SQ_SENT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '' || !is_string($sendLog) || $sendLog === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $data . '/public';
$GLOBALS['_SENT_BOX'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 404, 'body' => []];
};
$GLOBALS['_JEAB_REBOOK_LLM'] = static fn() => json_encode(['answer' => 'Ja, det ingår i offerten.']);
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a) use ($sendLog): array {
    file_put_contents($sendLog, json_encode(['to' => $a['to'] ?? '', 'subject' => $a['subject'] ?? '']) . "\n", FILE_APPEND);
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    return ['ok' => true, 'from' => $person, 'graph_id' => 'g-http', 'conversation_id' => 'CV', 'message_id' => 'http@x'];
};
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
@mkdir($tmp . '/public', 0775, true);
sq(is_file(jeab_inkorg_token_path()), 'ingest-token finns kvar i privat data');
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
sq($probe !== false, 'kunde inte välja port');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
sq($port > 0, 'port vald');
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_SQ_DATA=' . escapeshellarg($tmp)
    . ' JEAB_SQ_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' JEAB_SQ_SENT=' . escapeshellarg($sendLog)
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
sq(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
sq($up, 'testservern lyssnar');
$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (string $url, ?string $body, array $headers) use ($inboxToken): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 8,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    if (str_contains($text, $inboxToken)) {
        fwrite(STDERR, "FAIL svaret innehåller token\n");
        exit(1);
    }
    return ['status' => $status, 'body' => $text, 'json' => json_decode($text, true)];
};
$deny = $http($base, json_encode(['action' => 'inbox.send_quote', 'thread_id' => (string) $httpBattIn['thread_id']]), ['Content-Type: application/json']);
sq($deny['status'] === 401 && ($deny['json']['error'] ?? '') === 'Fel token.', 'utan token blir det 401');
$httpBatt = $http($base, json_encode(['action' => 'inbox.send_quote', 'thread_id' => (string) $httpBattIn['thread_id']]), ['Content-Type: application/json', 'Authorization: Bearer ' . $inboxToken]);
sq($httpBatt['status'] === 200 && ($httpBatt['json']['body'] ?? '') === $expectBatt && ($httpBatt['json']['template'] ?? '') === 'Batteri' && ($httpBatt['json']['sender'] ?? '') === 'saljare3@jarfalla.example.test', 'HTTP dry Batteri är identisk med automatiken');
$httpSol = $http($base, json_encode(['action' => 'inbox.send_quote', 'thread_id' => (string) $httpSolIn['thread_id'], 'dry' => 1]), ['Content-Type: application/json', 'X-Webhook-Token: ' . $inboxToken]);
sq($httpSol['status'] === 200 && ($httpSol['json']['body'] ?? '') === $expectSol && ($httpSol['json']['template'] ?? '') === 'A' && ($httpSol['json']['sender'] ?? '') === 'demo@example.test', 'HTTP dry Sol A är identisk med automatiken');
sq(!is_file($sendLog), 'HTTP dry skickade inget mejl');

$prevLlm = $GLOBALS['_JEAB_REBOOK_LLM'];
$llmCalls = 0;
$GLOBALS['_JEAB_REBOOK_LLM'] = static function (array $messages) use (&$llmCalls): string {
    $llmCalls++;
    return json_encode(['answer' => 'SKA INTE SYNAS'], JSON_UNESCAPED_UNICODE);
};
$answerText = "Taket tittar vi på måndag.\nPriset & <storlek> står i offerten.";
$answerBody = "Hej, vad kostar batteri med 38 kw?\nNamn: magnus\nE-post: magnus.svar.sq@gmail.com\nTelefon: 070-123 45 67\nAdress: Magnusvägen 1, 176 70 Järfälla\n";
$answerIn = $ingest('answer-hook', 'magnus.svar.sq@gmail.com', 'magnus', $answerBody, 'batteri');
$answerTid = (string) $answerIn['thread_id'];
$plainDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $answerTid, 'dry' => 1]);
sq(($plainDry['ok'] ?? false) === true && $llmCalls === 1 && str_contains((string) ($plainDry['body'] ?? ''), 'SKA INTE SYNAS'), 'utan answer frågar automatiken som idag');
$emptyDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $answerTid, 'dry' => 1, 'answer' => '   ']);
sq(($emptyDry['body'] ?? null) === ($plainDry['body'] ?? ''), 'tom answer efter trim är samma offert som utan answer');
$llmCalls = 0;
$withDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $answerTid, 'dry' => 1, 'answer' => '  ' . $answerText . '  ']);
$withBody = (string) ($withDry['body'] ?? '');
$greetAt = strpos($withBody, 'Hej Magnus,');
$answerAt = strpos($withBody, 'Taket tittar vi på måndag.<br>Priset &amp; &lt;storlek&gt; står i offerten.');
$tplAt = strpos($withBody, 'Den minsta investeringen');
sq(($withDry['ok'] ?? false) === true && $llmCalls === 0 && $greetAt !== false && $answerAt !== false && $tplAt !== false && $greetAt < $answerAt && $answerAt < $tplAt && !str_contains($withBody, 'SKA INTE SYNAS') && !str_contains($withBody, jeab_auto_answer_safe_line()) && !str_contains($withBody, 'Priset & <storlek>'), 'answer syns efter hälsningen, escapad, med radbrytning, och ersätter automatiken');
$fit = str_repeat('å', 500);
$fitDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $answerTid, 'dry' => 1, 'answer' => $fit]);
sq(($fitDry['ok'] ?? false) === true && str_contains((string) ($fitDry['body'] ?? ''), $fit), 'answer på 500 tecken går in i förhandsvisningen');
$before = count($GLOBALS['_SENT']);
$tooLong = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $answerTid, 'dry' => 0, 'answer' => str_repeat('å', 501)]);
sq(($tooLong['ok'] ?? true) === false && ($tooLong['error'] ?? '') === 'answer är längre än 500 tecken' && count($GLOBALS['_SENT']) === $before && (jeab_inkorg_thread_meta($answerTid)['status'] ?? '') === 'open', 'answer över 500 tecken är ett fel och skickar inget');
$notText = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $answerTid, 'answer' => ['nej']]);
sq(($notText['ok'] ?? true) === false && ($notText['error'] ?? '') === 'answer ska vara text', 'answer som inte är text är ett fel');
$solAnswerIn = $ingest('sol-answer', 'notifications@drip.com', 'Drip', $solBody('Sara Sol', 'sara.sol.answer.sq@gmail.com'), 'sol');
$solAnswer = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $solAnswerIn['thread_id'], 'dry' => 1, 'answer' => "Eget solsvar.\nRad två."]);
$solHtml = (string) ($solAnswer['body'] ?? '');
$solHej = strpos($solHtml, 'Hej Sara,');
$solAns = strpos($solHtml, 'Eget solsvar.<br>Rad två.');
$solTpl = strpos($solHtml, 'Du har visat intresse');
sq(($solAnswer['ok'] ?? false) === true && $solHej !== false && $solAns !== false && $solTpl !== false && $solHej < $solAns && $solAns < $solTpl, 'sol: answer ligger efter hälsningen och före mallen');

$sendAnswerEmail = 'skickad.svar.sq@gmail.com';
$sendAnswerIn = $ingest('answer-send', $sendAnswerEmail, 'Mia Svar', "Hej, jag vill ha ett batteri.\nNamn: Mia Svar\nE-post: {$sendAnswerEmail}\nTelefon: 070-404 00 09\nAdress: Svarvägen 9, 176 70 Järfälla\n", 'batteri');
$before = count($GLOBALS['_SENT']);
$sendAnswer = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $sendAnswerIn['thread_id'], 'dry' => 0, 'answer' => 'Kort svar till Mia.']);
$sendNotes = $notes((string) $sendAnswerIn['thread_id']);
sq(($sendAnswer['ok'] ?? false) === true && ($sendAnswer['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1 && str_contains((string) ($GLOBALS['_SENT'][$before]['body'] ?? ''), 'Kort svar till Mia.') && str_contains($sendNotes, 'skickad manuellt') && str_contains($sendNotes, 'med kort svar') && !str_contains($sendNotes, 'Med kort svar på frågan'), 'dry=0 antecknar manuellt skick med kort svar');

$mailEmail = 'utan.mail.sq@gmail.com';
$mailIn = $ingest('mail-line', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Olle Utan', $mailEmail), 'batteri');
$mailTid = (string) $mailIn['thread_id'];
$mailLine = 'mail: ' . jeab_lead_mail_url($mailTid);
$createdRem = jeab_create_reminder(jeab_store(), 'Olle Utan – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Olle Utan', 'email' => $mailEmail, 'company' => 'jarfalla', 'who' => 'Roberto',
    'note' => 'Ring kunden.',
]);
sq(!str_contains((string) ($createdRem['note'] ?? ''), $mailLine), 'påminnelsen saknar mail-raden från början');
$mailRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $mailTid, 'dry' => 0]);
$mailNote = '';
$mailRow = null;
foreach (jeab_list_reminders(jeab_store(), true) as $r) {
    if (mb_strtolower((string) ($r['email'] ?? '')) === $mailEmail) {
        $mailRow = $r;
        $mailNote = (string) ($r['note'] ?? '');
    }
}
sq(($mailRes['ok'] ?? false) === true && str_contains($notes($mailTid), 'Påminnelse fanns redan.') && $remCount($mailEmail) === 1 && str_contains($mailNote, 'Ring kunden.') && substr_count($mailNote, $mailLine) === 1, 'återanvänd påminnelse får mail-raden');
$mailAgain = jeab_auto_reminder_ensure_mail(is_array($mailRow) ? $mailRow : [], $mailTid);
sq(substr_count((string) ($mailAgain['note'] ?? ''), $mailLine) === 1 && (string) ($mailAgain['note'] ?? '') === $mailNote, 'mail-raden läggs inte dit en gång till');
$GLOBALS['_JEAB_REBOOK_LLM'] = $prevLlm;

$shortEmail = 'kort.svar.sq@gmail.com';
$shortIn = $ingest('short', 'kort.svar.sq@gmail.com', 'Bosse Kort', "Hej, jag vill ha en elbilsladdare.\nNamn: Bosse Kort\nE-post: {$shortEmail}\nTelefon: 070-909 00 01\nAdress: Kortvägen 1, 176 70 Järfälla\n", 'laddbox', ['internet_message_id' => '<kund-kort@sq.test>']);
$shortTid = (string) $shortIn['thread_id'];
sq((jeab_inkorg_hook(['action' => 'inbox.send_quote', 'answer_only' => 1, 'answer' => 'Hej'])['error'] ?? '') === 'thread_id saknas', 'answer_only kräver fortfarande thread_id');
sq((jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $shortTid, 'answer_only' => 1])['error'] ?? '') === 'answer saknas', 'answer_only utan answer är ett fel');
sq((jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $shortTid, 'answer_only' => '1', 'answer' => '   '])['error'] ?? '') === 'answer saknas', 'answer_only med tom answer är ett fel');
$before = count($GLOBALS['_SENT']);
$shortLong = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $shortTid, 'answer_only' => 1, 'dry' => 0, 'answer' => str_repeat('å', 501)]);
sq(($shortLong['error'] ?? '') === 'answer är längre än 500 tecken' && count($GLOBALS['_SENT']) === $before, 'answer_only har samma teckengräns');
$GLOBALS['_SENT_BOX'] = [[
    'id' => 'BLOCK',
    'subject' => 'Offert',
    'toRecipients' => [['emailAddress' => ['address' => $shortEmail]]],
    'sentDateTime' => '2026-10-16T08:00:00Z',
    'hasAttachments' => false,
]];
$shortBlocked = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $shortTid, 'dry' => 1]);
$shortAnswer = "Vi tittar på måndag.\nPriset & <säkring> tar vi då.";
$shortDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $shortTid, 'answer_only' => 1, 'answer' => $shortAnswer]);
$shortBody = (string) ($shortDry['body'] ?? '');
$shortHej = strpos($shortBody, 'Hej Bosse,');
$shortAns = strpos($shortBody, 'Vi tittar på måndag.<br>Priset &amp; &lt;säkring&gt; tar vi då.');
$shortSig = strpos($shortBody, 'Elin<br>070-000 00 05<br>Elektriker<br>Demo Energi Järfälla');
sq(($shortBlocked['action'] ?? '') === 'hold' && str_contains((string) ($shortBlocked['why'] ?? ''), 'Skickat'), 'offerten stoppas av Skickat');
sq(($shortDry['ok'] ?? false) === true && ($shortDry['dry'] ?? 0) === 1 && ($shortDry['attachments'] ?? null) === [] && ($shortDry['sender'] ?? '') === 'saljare4@jarfalla.example.test' && ($shortDry['subject'] ?? '') === 'Sv: Elbilsladdare' && ($shortDry['recipient'] ?? '') === $shortEmail, 'answer_only är förhandsvisning från elin@ med Sv: och utan bilaga');
sq($shortHej !== false && $shortAns !== false && $shortSig !== false && $shortHej < $shortAns && $shortAns < $shortSig && !str_contains($shortBody, 'Easee') && !str_contains($shortBody, 'Priset & <säkring>') && !str_contains($shortBody, 'Kortvägen'), 'kort svar är hälsning, escapad text och Elins signatur');
$GLOBALS['_SENT_BOX'] = [];
sq($remCount($shortEmail) === 0, 'ingen påminnelse före det korta svaret');
$before = count($GLOBALS['_SENT']);
$shortSend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $shortTid, 'answer_only' => 1, 'dry' => 0, 'answer' => $shortAnswer]);
$shortSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$shortNotes = $notes($shortTid);
sq(($shortSend['ok'] ?? false) === true && count($GLOBALS['_SENT']) === $before + 1 && ($shortSent['attachments'] ?? null) === [] && ($shortSent['subject'] ?? '') === 'Sv: Elbilsladdare' && ($shortSent['opts']['person_email'] ?? '') === 'saljare4@jarfalla.example.test' && ($shortSent['opts']['reply_to_graph_id'] ?? '') === 'graph-short' && ($shortSent['opts']['in_reply_to'] ?? '') === 'kund-kort@sq.test' && !empty($shortSent['opts']['answer_only']) && !empty($shortSent['opts']['keep_subject']), 'kort svar går som svar i tråden, utan bilaga');
sq(str_contains($shortNotes, 'kort svar skickat') && (jeab_inkorg_thread_meta($shortTid)['status'] ?? '') === 'closed' && $remCount($shortEmail) === 0 && !str_contains($shortNotes, 'Påminnelse skapad'), 'anteckningen säger kort svar skickat, ärendet stängs och ingen påminnelse skapas');

$linkEmail = 'kort.lank.sq@gmail.com';
$linkIn = $ingest('short-link', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Lina Lank', $linkEmail), 'laddbox');
$linkTid = (string) $linkIn['thread_id'];
$linkLine = 'mail: ' . jeab_lead_mail_url($linkTid);
jeab_create_reminder(jeab_store(), 'Lina Lank – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Lina Lank', 'email' => $linkEmail, 'company' => 'jarfalla', 'who' => 'Elin',
    'note' => 'Ring kunden.',
]);
$linkSend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $linkTid, 'answer_only' => 1, 'dry' => '0', 'answer' => 'Kort svar till Lina.']);
$linkNote = '';
foreach (jeab_list_reminders(jeab_store(), true) as $r) {
    if (mb_strtolower((string) ($r['email'] ?? '')) === $linkEmail) {
        $linkNote = (string) ($r['note'] ?? '');
    }
}
sq(($linkSend['ok'] ?? false) === true && $remCount($linkEmail) === 1 && str_contains($linkNote, 'Ring kunden.') && substr_count($linkNote, $linkLine) === 1 && str_contains($notes($linkTid), 'kort svar skickat'), 'befintlig påminnelse får mail-raden en gång');
$linkAgain = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $linkTid, 'answer_only' => 1, 'dry' => 0, 'answer' => 'Kort svar till Lina.']);
$linkNote2 = '';
foreach (jeab_list_reminders(jeab_store(), true) as $r) {
    if (mb_strtolower((string) ($r['email'] ?? '')) === $linkEmail) {
        $linkNote2 = (string) ($r['note'] ?? '');
    }
}
sq(($linkAgain['ok'] ?? false) === true && $remCount($linkEmail) === 1 && $linkNote2 === $linkNote, 'andra korta svaret skapar ingen påminnelse och dubblerar inte mail-raden');

$solShortIn = $ingest('sol-short', 'notifications@drip.com', 'Drip', $solBody('Sara Kort', 'sara.kort.sq@gmail.com'), 'sol');
$solShort = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $solShortIn['thread_id'], 'answer_only' => 1, 'answer' => 'Eget kortsvar.']);
$solShortBody = (string) ($solShort['body'] ?? '');
sq(($solShort['ok'] ?? false) === true && ($solShort['sender'] ?? '') === 'demo@example.test' && ($solShort['attachments'] ?? null) === [] && str_contains($solShortBody, 'Hej Sara,') && str_contains($solShortBody, 'Eget kortsvar.') && str_contains($solShortBody, 'Sam Exempel') && str_contains($solShortBody, '08-000 00 02') && !str_contains($solShortBody, 'Du har visat intresse') && !str_contains($solShortBody, 'medal'), 'solens korta svar har Sams signatur och ingen mall');

$outEmail = 'redan.ut.sq@gmail.com';
jeab_inkorg_ingest([
    'message_id' => 'out-redan@sq.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => 'Elin <' . $outEmail . '>',
    'subject' => 'Sv: Elbilsladdare',
    'body_text' => 'Offerten gick iväg 30 september.',
    'received_at' => '2026-09-30T08:00:00+02:00',
    'source' => 'outbound-jarfalla',
    'thread_key' => 'out-redan',
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 100],
]);
$outIn = $ingest('out-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Redan Ut', $outEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$outRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $outIn['thread_id'], 'dry' => 0]);
sq(($outRes['action'] ?? '') === 'hold' && str_contains((string) ($outRes['why'] ?? ''), 'inkorgen') && str_contains((string) ($outRes['why'] ?? ''), '2026-09-30 08:00') && count($GLOBALS['_SENT']) === $before, 'utgående mejl i inkorgen stoppar offerten med datum');
$oldEmail = 'gammal.ut.sq@gmail.com';
jeab_inkorg_ingest([
    'message_id' => 'out-gammal@sq.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => $oldEmail,
    'subject' => 'Sv: Elbilsladdare',
    'body_text' => 'Gammal offert.',
    'received_at' => '2025-01-01T08:00:00+00:00',
    'source' => 'outbound-jarfalla',
    'thread_key' => 'out-gammal',
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 100],
]);
$oldIn = $ingest('old-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Gammal Ut', $oldEmail), 'laddbox');
$oldOk = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $oldIn['thread_id'], 'dry' => 1]);
sq(($oldOk['ok'] ?? false) === true && ($oldOk['recipient'] ?? '') === $oldEmail, 'utgående mejl äldre än 180 dagar stoppar inte');

$hsEmail = 'hs.anteckning.sq@gmail.com';
$hsTid = 'hsi-' . substr(hash('sha256', 'hs-note-sq'), 0, 16);
$hsConv = [
    'id' => $hsTid,
    'company' => 'jarfalla',
    'messages' => [
        [
            'id' => substr(hash('sha256', 'hs-cust'), 0, 16),
            'from' => $hsEmail,
            'from_name' => 'Hs Kund',
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Elbilsladdare',
            'body' => 'Hej',
            'received_at' => '2026-09-01T10:00:00+02:00',
            'source' => 'helpscout_import',
            'hs_thread_type' => 'customer',
        ],
        [
            'id' => substr(hash('sha256', 'hs-note'), 0, 16),
            'from' => 'saljare4@jarfalla.example.test',
            'from_name' => 'Elin',
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Elbilsladdare',
            'body' => 'Offert (Elbilsladdare) skickad till kunden 30 september.',
            'received_at' => '2026-09-30T10:00:00+02:00',
            'source' => 'helpscout_import',
            'internal_note' => true,
            'hs_thread_type' => 'note',
        ],
    ],
];
sq(jeab_hs_import_save_conv($hsConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($hsConv)), 'Help Scout-anteckningen finns lokalt');
$hsIn = $ingest('hs-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Hs Kund', $hsEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$hsRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $hsIn['thread_id'], 'dry' => 0]);
sq(($hsRes['action'] ?? '') === 'hold' && str_contains((string) ($hsRes['why'] ?? ''), 'Help Scout') && str_contains((string) ($hsRes['why'] ?? ''), '2026-09-30 10:00') && count($GLOBALS['_SENT']) === $before, 'lokal Help Scout-anteckning stoppar offerten med datum');

$searchEmail = 'sept.kund.sq@gmail.com';
$searchUrls = [];
$prevHttp = $GLOBALS['_JEAB_GRAPH_HTTP'];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$searchUrls, $searchEmail): array {
    $searchUrls[] = [$url, $headers];
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($url, 'sept-page-2')) {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'SEPT',
            'subject' => 'Offert elbilsladdare',
            'toRecipients' => [['emailAddress' => ['address' => 'Sept.Kund.sq@gmail.com']]],
            'sentDateTime' => '2026-09-30T08:00:00Z',
        ]]]];
    }
    if ($method === 'GET' && str_contains($decoded, '$search=') && str_contains($decoded, 'to:' . $searchEmail)) {
        return ['code' => 200, 'body' => [
            'value' => [[
                'id' => 'OTHER',
                'toRecipients' => [['emailAddress' => ['address' => 'nagon.annan@gmail.com']]],
                'sentDateTime' => '2026-10-01T08:00:00Z',
            ]],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/saljare4@jarfalla.example.test/mailFolders/sentitems/messages?sept-page-2',
        ]];
    }
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 404, 'body' => []];
};
$searchIn = $ingest('sept-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Sept Kund', $searchEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$searchRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $searchIn['thread_id'], 'dry' => 0]);
$searchUrl = '';
$paged = false;
foreach ($searchUrls as [$url, $headers]) {
    $decoded = rawurldecode($url);
    if (str_contains($url, 'sept-page-2')) {
        $paged = true;
    }
    if (str_contains($decoded, '$search=') && str_contains($decoded, 'to:' . $searchEmail)) {
        $searchUrl = $decoded;
        sq(in_array('ConsistencyLevel: eventual', $headers, true), 'Skickat-sökningen ber om ConsistencyLevel');
    }
}
sq(($searchRes['action'] ?? '') === 'hold' && str_contains((string) ($searchRes['why'] ?? ''), 'Skickat') && str_contains((string) ($searchRes['why'] ?? ''), '2026-09-30 10:00') && count($GLOBALS['_SENT']) === $before && $paged && str_contains($searchUrl, '$search="to:' . $searchEmail . '"') && !str_contains($searchUrl, '\\"') && !str_contains($searchUrl, '$filter=') && !str_contains($searchUrl, '$orderby='), 'träff i Skickat håller med datum');
$ancientEmail = 'ancient.kund.sq@gmail.com';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($ancientEmail): array {
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($decoded, 'to:' . $ancientEmail)) {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'OLD',
            'toRecipients' => [['emailAddress' => ['address' => $ancientEmail]]],
            'sentDateTime' => '2025-01-01T08:00:00Z',
        ]]]];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$ancientIn = $ingest('ancient-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Ancient Kund', $ancientEmail), 'laddbox');
$ancientOk = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $ancientIn['thread_id'], 'dry' => 1]);
sq(($ancientOk['ok'] ?? false) === true, 'träff i Skickat äldre än 180 dagar stoppar inte');
$ccEmail = 'cc.kund.sq@gmail.com';
$ccUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$ccUrls, $ccEmail): array {
    $ccUrls[] = rawurldecode($url);
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($decoded, '$search=') && str_contains($decoded, 'cc:' . $ccEmail)) {
        return ['code' => 200, 'body' => ['value' => [[
            'id' => 'CC1',
            'subject' => 'Offert elbilsladdare',
            'toRecipients' => [['emailAddress' => ['address' => 'nagon.annan@gmail.com']]],
            'ccRecipients' => [['emailAddress' => ['address' => 'CC.Kund.sq@gmail.com']]],
            'sentDateTime' => '2026-10-14T11:53:00Z',
        ]]]];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$ccIn = $ingest('cc-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Cc Kund', $ccEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$ccRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $ccIn['thread_id'], 'dry' => 1]);
$sawCc = false;
foreach ($ccUrls as $url) {
    if (str_contains($url, '$search=') && str_contains($url, 'cc:' . $ccEmail)) {
        $sawCc = true;
    }
}
sq($sawCc && ($ccRes['action'] ?? '') === 'hold' && str_contains((string) ($ccRes['why'] ?? ''), 'Skickat') && str_contains((string) ($ccRes['why'] ?? ''), '2026-10-14 13:53') && count($GLOBALS['_SENT']) === $before, 'kopia i Skickat stoppar offerten med datum');
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    return ['code' => 200, 'body' => [
        'value' => [],
        '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/saljare4@jarfalla.example.test/mailFolders/sentitems/messages?$skiptoken=forever',
        'error' => ['message' => 'SECRET-PAGE'],
    ]];
};
$pageIn = $ingest('page-fail', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Page Fail', 'page.fail.sq@gmail.com'), 'laddbox');
$before = count($GLOBALS['_SENT']);
$pageRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $pageIn['thread_id'], 'dry' => 1]);
sq(($pageRes['action'] ?? '') === 'hold' && str_contains((string) ($pageRes['why'] ?? ''), 'hela Skickat') && !str_contains(json_encode($pageRes), 'SECRET-PAGE') && count($GLOBALS['_SENT']) === $before, 'ofullständig sidbläddring i Skickat håller utan att läcka svaret');
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(string $method, string $url, ?array $body = null, array $headers = []): array => ['code' => 500, 'body' => ['error' => ['message' => 'SECRET-SEARCH']]];
$failSearchIn = $ingest('search-fail', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Search Fail', 'search.fail.sq@gmail.com'), 'laddbox');
$before = count($GLOBALS['_SENT']);
$failSearch = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $failSearchIn['thread_id'], 'dry' => 0]);
sq(($failSearch['action'] ?? '') === 'hold' && str_contains((string) ($failSearch['why'] ?? ''), 'Skickat') && str_contains((string) ($failSearch['why'] ?? ''), 'graph 500') && !str_contains((string) ($failSearch['why'] ?? ''), 'search.fail.sq@gmail.com') && !str_contains(json_encode($failSearch), 'SECRET-SEARCH') && count($GLOBALS['_SENT']) === $before, 'misslyckad Skickat-sökning håller utan att läcka svaret');
$fbEmail = 'fallback.kund.sq@gmail.com';
$fbUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$fbUrls, $fbEmail): array {
    $fbUrls[] = [rawurldecode($url), $headers];
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($decoded, '$search=')) {
        return ['code' => 400, 'body' => ['error' => ['message' => 'SECRET-SEARCH-BODY']]];
    }
    if ($method === 'GET' && str_contains($url, 'fb-page-2')) {
        return ['code' => 200, 'body' => ['value' => [[
            'toRecipients' => [['emailAddress' => ['address' => 'Fallback.Kund.sq@gmail.com']]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-15T22:12:00Z',
        ]]]];
    }
    if ($method === 'GET' && str_contains($decoded, 'sentDateTime ge')) {
        return ['code' => 200, 'body' => [
            'value' => [[
                'toRecipients' => [['emailAddress' => ['address' => 'nagon.annan@gmail.com']]],
                'sentDateTime' => '2026-10-01T08:00:00Z',
            ]],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/saljare4@jarfalla.example.test/mailFolders/sentitems/messages?fb-page-2',
        ]];
    }
    return ['code' => 404, 'body' => ['error' => ['message' => 'SECRET-SEARCH-BODY']]];
};
$fbIn = $ingest('fb-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Fallback Kund', $fbEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$fbRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $fbIn['thread_id'], 'dry' => 1]);
$fbSearch = '';
$fbScan = '';
$fbPaged = false;
foreach ($fbUrls as [$url, $headers]) {
    if (str_contains($url, 'fb-page-2')) {
        $fbPaged = true;
    }
    if (str_contains($url, '$search=') && str_contains($url, 'to:' . $fbEmail)) {
        $fbSearch = $url;
        sq(in_array('ConsistencyLevel: eventual', $headers, true), 'avvisad $search skickade ConsistencyLevel');
    }
    if (str_contains($url, 'sentDateTime ge') && str_contains($url, '$top=250')) {
        $fbScan = $url;
    }
}
$cut = gmdate('Y-m-d', time() - 180 * 86400);
preg_match('/sentDateTime ge (\d{4}-\d{2}-\d{2})/', $fbScan, $fbCut);
sq($fbSearch !== '' && !str_contains($fbSearch, '$filter=') && !str_contains($fbSearch, '$orderby=') && $fbScan !== '' && str_contains($fbScan, '$select=toRecipients,ccRecipients,sentDateTime') && !str_contains($fbScan, '$orderby=') && !str_contains($fbScan, '$search=') && $fbPaged && isset($fbCut[1]) && $fbCut[1] <= $cut, 'HTTP 400 på $search går över till datumfiltret');
sq(($fbRes['action'] ?? '') === 'hold' && str_contains((string) ($fbRes['why'] ?? ''), 'Skickat') && str_contains((string) ($fbRes['why'] ?? ''), '2026-10-16 00:12') && !str_contains(json_encode($fbRes), 'SECRET-SEARCH-BODY') && !str_contains((string) ($fbRes['why'] ?? ''), 'graph 400') && count($GLOBALS['_SENT']) === $before, 'datumfiltret hittar träffen och håller med datum');
$noneEmail = 'ingen.traff.sq@gmail.com';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($decoded, '$search=')) {
        return ['code' => 400, 'body' => ['error' => ['message' => 'SECRET-NONE']]];
    }
    if ($method === 'GET' && str_contains($decoded, 'sentDateTime ge')) {
        return ['code' => 200, 'body' => ['value' => [[
            'toRecipients' => [['emailAddress' => ['address' => 'Nagon.Annan@gmail.com']]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-02T08:00:00Z',
        ]]]];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$noneIn = $ingest('none-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Ingen Traff', $noneEmail), 'laddbox');
$noneRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $noneIn['thread_id'], 'dry' => 1]);
sq(($noneRes['ok'] ?? false) === true && ($noneRes['recipient'] ?? '') === $noneEmail && !str_contains(json_encode($noneRes), 'SECRET-NONE') && !str_contains((string) ($noneRes['why'] ?? ''), 'graph 400'), 'tomt Skickat efter datumfiltret håller inte');
$emptyEmail = 'scan.tom.sq@gmail.com';
$emptyUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$emptyUrls, $emptyEmail): array {
    $emptyUrls[] = rawurldecode($url);
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($decoded, '/mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder-elin']];
    }
    if ($method === 'GET' && str_contains($decoded, '$search=') && str_contains($decoded, '/messages') && !str_contains($decoded, '/mailFolders/')) {
        return ['code' => 200, 'body' => ['value' => [[
            'parentFolderId' => 'inbox-folder-elin',
            'toRecipients' => [['emailAddress' => ['address' => $emptyEmail]]],
            'sentDateTime' => '2026-10-13T11:53:00Z',
        ]]]];
    }
    if ($method === 'GET' && str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    if ($method === 'GET' && str_contains($url, 'empty-page-2')) {
        return ['code' => 200, 'body' => ['value' => [[
            'toRecipients' => [['emailAddress' => ['address' => 'Scan.Tom.sq@gmail.com']]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-13T11:53:00Z',
        ]]]];
    }
    if ($method === 'GET' && str_contains($decoded, 'sentDateTime ge')) {
        return ['code' => 200, 'body' => [
            'value' => [[
                'toRecipients' => [['emailAddress' => ['address' => 'nagon.annan@gmail.com']]],
                'sentDateTime' => '2026-10-02T08:00:00Z',
            ]],
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/saljare4@jarfalla.example.test/mailFolders/sentitems/messages?empty-page-2',
        ]];
    }
    return ['code' => 404, 'body' => []];
};
$emptyIn = $ingest('empty-search', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Scan Tom', $emptyEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$emptyRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $emptyIn['thread_id'], 'dry' => 1]);
$emptySearch = '';
$emptyMailbox = false;
$emptyScan = false;
foreach ($emptyUrls as $url) {
    if (str_contains($url, '$search="to:' . $emptyEmail . '"') && str_contains($url, '/mailFolders/sentitems/messages')) {
        $emptySearch = $url;
    }
    if (str_contains($url, '/messages?') && str_contains($url, '$search="to:' . $emptyEmail . '"') && !str_contains($url, '/mailFolders/')) {
        $emptyMailbox = true;
    }
    if (str_contains($url, 'empty-page-2')) {
        $emptyScan = true;
    }
}
sq($emptySearch !== '' && !str_contains($emptySearch, '\\"') && $emptyMailbox && $emptyScan && str_contains(implode("\n", $emptyUrls), '$top=250'), 'tom $search 200 söker brevlådan och går vidare till datumskanningen');
sq(($emptyRes['action'] ?? '') === 'hold' && str_contains((string) ($emptyRes['why'] ?? ''), 'Skickat') && str_contains((string) ($emptyRes['why'] ?? ''), '2026-10-13 13:53') && !str_contains((string) ($emptyRes['why'] ?? ''), 'graph ') && count($GLOBALS['_SENT']) === $before, 'tom $search och träff i datumskanningen håller med datum');
$GLOBALS['_JEAB_GRAPH_HTTP'] = $prevHttp;
$GLOBALS['_SENT_BOX'] = [];

$hsReplyEmail = 'hs.reply.sq@gmail.com';
$hsReplyTid = 'hsi-' . substr(hash('sha256', 'hs-reply-sq'), 0, 16);
$hsReplyConv = [
    'id' => $hsReplyTid,
    'company' => 'jarfalla',
    'messages' => [
        [
            'id' => substr(hash('sha256', 'hs-reply-cust'), 0, 16),
            'from' => $hsReplyEmail,
            'from_name' => 'Hs Reply',
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Batteri',
            'body' => 'Hej',
            'received_at' => '2026-09-01T10:00:00+02:00',
            'source' => 'helpscout_import',
            'hs_thread_type' => 'customer',
        ],
        [
            'id' => substr(hash('sha256', 'hs-reply-out'), 0, 16),
            'from' => 'saljare3@jarfalla.example.test',
            'from_name' => 'Roberto',
            'to' => $hsReplyEmail,
            'subject' => 'Offert batteri',
            'body' => 'Hej, här kommer offerten.',
            'received_at' => '2026-09-30T08:00:00Z',
            'source' => 'helpscout_import',
            'hs_thread_type' => 'reply',
        ],
    ],
];
sq(jeab_hs_import_save_conv($hsReplyConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($hsReplyConv)), 'lokal Help Scout-tråd finns');
$hsReplyIn = $ingest('hs-reply-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Hs Reply', $hsReplyEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$hsReplyRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $hsReplyIn['thread_id'], 'dry' => 1]);
sq(($hsReplyRes['action'] ?? '') === 'hold' && str_contains((string) ($hsReplyRes['why'] ?? ''), 'Help Scout') && str_contains((string) ($hsReplyRes['why'] ?? ''), '2026-09-30 10:00') && count($GLOBALS['_SENT']) === $before, 'lokal Help Scout-reply stoppar offerten med datum');

$hsLiveEmail = 'hs.live.sq@gmail.com';
$hsLiveUrls = [];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use (&$hsLiveUrls, $hsLiveEmail): array {
    $hsLiveUrls[] = rawurldecode($url);
    $decoded = rawurldecode($url);
    if (str_contains($decoded, '/threads')) {
        return ['code' => 200, 'body' => json_encode(['_embedded' => ['threads' => [
            ['id' => 1, 'type' => 'customer', 'createdAt' => '2026-10-01T08:00:00Z', 'body' => 'SECRET-HS-BODY'],
            ['id' => 2, 'type' => 'reply', 'createdAt' => '2026-10-14T11:53:00Z', 'body' => 'SECRET-HS-REPLY'],
        ]]], JSON_UNESCAPED_UNICODE)];
    }
    if (str_contains($decoded, '/conversations')) {
        return ['code' => 200, 'body' => json_encode([
            '_embedded' => ['conversations' => [['id' => 55, 'subject' => 'SECRET-HS-SUBJECT']]],
            'page' => ['totalPages' => 1],
        ])];
    }
    return ['code' => 404, 'body' => '{"message":"SECRET-HS"}'];
};
$GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (): void {
};
$hsLiveIn = $ingest('hs-live', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Hs Live', $hsLiveEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$hsLiveRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $hsLiveIn['thread_id'], 'dry' => 1]);
$hsQuery = implode("\n", $hsLiveUrls);
sq(str_contains($hsQuery, 'mailbox=372708') && str_contains($hsQuery, 'email:' . $hsLiveEmail) && str_contains($hsQuery, 'modifiedSince='), 'Help Scout söker kunden i Järfällas mailbox');
sq(($hsLiveRes['action'] ?? '') === 'hold' && str_contains((string) ($hsLiveRes['why'] ?? ''), '(Help Scout 2026-10-14 13:53)') && !str_contains(json_encode($hsLiveRes), 'SECRET-HS') && count($GLOBALS['_SENT']) === $before, 'Help Scout-reply stoppar offerten med datum');
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (): array {
    return ['code' => 403, 'body' => '{"message":"SECRET-HS-403"}'];
};
$hsErrEmail = 'hs.fel.sq@gmail.com';
$hsErrIn = $ingest('hs-err', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Hs Fel', $hsErrEmail), 'laddbox');
$before = count($GLOBALS['_SENT']);
$hsErrRes = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $hsErrIn['thread_id'], 'dry' => 1]);
sq(($hsErrRes['action'] ?? '') === 'hold' && str_contains((string) ($hsErrRes['why'] ?? ''), 'kunde inte läsa Help Scout (403)') && !str_contains(json_encode($hsErrRes), 'SECRET-HS-403') && !str_contains((string) ($hsErrRes['why'] ?? ''), $hsErrEmail) && count($GLOBALS['_SENT']) === $before, 'Help Scout-fel håller med statuskoden');

$diagEmail = 'diag.kund.sq@gmail.com';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($diagEmail): array {
    $decoded = rawurldecode($url);
    if ($method === 'GET' && str_contains($decoded, '/mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder-diag']];
    }
    if ($method === 'GET' && str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    if ($method === 'GET' && str_contains($decoded, 'sentDateTime ge')) {
        return ['code' => 200, 'body' => ['value' => [[
            'toRecipients' => [['emailAddress' => ['address' => $diagEmail]]],
            'ccRecipients' => [],
            'sentDateTime' => '2026-10-16T08:00:00Z',
        ]]]];
    }
    return ['code' => 404, 'body' => []];
};
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use ($diagEmail): array {
    $decoded = rawurldecode($url);
    if (!str_contains($decoded, $diagEmail) && !str_contains($decoded, '/threads')) {
        return ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => []], 'page' => ['totalPages' => 1]])];
    }
    if (str_contains($decoded, '/threads')) {
        return ['code' => 200, 'body' => json_encode(['_embedded' => ['threads' => [
            ['id' => 8, 'type' => 'reply', 'createdAt' => '2026-09-30T08:00:00Z', 'body' => 'SECRET-DIAG-BODY'],
        ]]])];
    }
    return ['code' => 200, 'body' => json_encode([
        '_embedded' => ['conversations' => [['id' => 77]]],
        'page' => ['totalPages' => 1],
    ])];
};
$diagIn = $ingest('diag-lead', 'info@wix-forms.com', 'Formulär JEAB', $battBody('Diag Kund', $diagEmail), 'laddbox');
$diag = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => (string) $diagIn['thread_id']]);
$diagJson = json_encode($diag, JSON_UNESCAPED_UNICODE);
$src = is_array($diag['sources'] ?? null) ? $diag['sources'] : [];
sq(($diag['ok'] ?? false) === true && ($src['graph_search']['status'] ?? '') === 'ok' && ($src['graph_search']['hit'] ?? true) === false && ($src['graph_scan']['hit'] ?? false) === true && ($src['graph_scan']['date'] ?? '') === '2026-10-16 10:00' && ($src['graph_scan']['scanned'] ?? 0) >= 1 && ($src['helpscout']['hit'] ?? false) === true && ($src['helpscout']['status'] ?? '') === 'ok' && ($src['helpscout']['date'] ?? '') === '2026-09-30 10:00' && !isset($src['nylas']) && ($src['note']['hit'] ?? true) === false, 'diagnosen visar status, antal, träff och datum per källa');
sq(!str_contains((string) $diagJson, $diagEmail) && !str_contains((string) $diagJson, '@') && !str_contains((string) $diagJson, 'SECRET-DIAG'), 'diagnosen innehåller varken adress eller brödtext');
unset($GLOBALS['_JEAB_HS_IMPORT_HTTP'], $GLOBALS['_JEAB_HS_IMPORT_SLEEP']);
$GLOBALS['_JEAB_GRAPH_HTTP'] = $prevHttp;

$cachePerson = 'saljare4@jarfalla.example.test';
$cacheHttp = $GLOBALS['_JEAB_GRAPH_HTTP'];
unset($GLOBALS['_JEAB_GRAPH_HTTP'], $GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
jeab_auto_graph_scan_cache_put($cachePerson, [[
    'to' => ['cache.kund.sq@gmail.com'],
    'cc' => [],
    'sent' => '2026-10-16T08:00:00Z',
]]);
$cachedRows = jeab_auto_graph_scan_cache_get($cachePerson);
sq(is_array($cachedRows) && count($cachedRows) === 1 && ($cachedRows[0]['to'][0] ?? '') === 'cache.kund.sq@gmail.com' && !isset($cachedRows[0]['subject']), 'Skickat-skanningen cachas per avsändare utan brödtext');
$cachePath = jeab_data_dir() . '/graph-sent-scan.json';
$cacheFile = json_decode((string) file_get_contents($cachePath), true);
$cacheFile[$cachePerson]['at'] = time() - 601;
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
file_put_contents($cachePath, json_encode($cacheFile));
sq(jeab_auto_graph_scan_cache_get($cachePerson) === null, 'Skickat-cachen släpps efter tio minuter');
$GLOBALS['_JEAB_GRAPH_HTTP'] = $cacheHttp;

$dir = '/opt/cursor/artifacts';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$card = static function (string $title, array $json, string $expect) : string {
    $match = (($json['body'] ?? '') === $expect);
    $meta = htmlspecialchars($title . ' · mall ' . (string) ($json['template'] ?? '') . ' · ' . (string) ($json['sender'] ?? '') . ' → ' . (string) ($json['recipient'] ?? '') . ' · ' . (string) ($json['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
    $files = htmlspecialchars(implode(', ', (array) ($json['attachments'] ?? [])), ENT_QUOTES, 'UTF-8');
    $src = htmlspecialchars((string) ($json['body'] ?? ''), ENT_QUOTES, 'UTF-8');
    $banner = $match ? 'IDENTISK med automatikens utskick' : 'SKILJER SIG';
    return '<section><h2>' . $meta . '</h2><p><b>' . $banner . '</b> · bilagor: ' . $files . '</p><iframe sandbox srcdoc="' . $src . '"></iframe></section>';
};
$case = static function (string $title, array $json) : string {
    $meta = htmlspecialchars($title . ' · ' . (string) ($json['sender'] ?? '') . ' → ' . (string) ($json['recipient'] ?? '') . ' · ' . (string) ($json['subject'] ?? ''), ENT_QUOTES, 'UTF-8');
    $src = htmlspecialchars((string) ($json['body'] ?? ''), ENT_QUOTES, 'UTF-8');
    return '<section><h2>' . $meta . '</h2><iframe sandbox srcdoc="' . $src . '"></iframe></section>';
};
$html = '<!doctype html><meta charset="utf-8"><title>send_quote dry</title><style>body{font:16px/1.4 sans-serif;margin:24px;background:#f6f4ef;color:#1c1915}iframe{width:100%;height:420px;border:1px solid #ccc;background:#fff}section{margin:0 0 28px}</style>'
    . '<h1>inbox.send_quote dry=1</h1>'
    . $card('Batteri', $httpBatt['json'], $expectBatt)
    . $card('Sol A', $httpSol['json'], $expectSol)
    . $case('Mårten Ekström', $gissDry)
    . $case('Torbjörn 20 till 40 kWh', $tbDetailDry)
    . $case('Carvajal Wix', $carvajalDry)
    . $case('Weply 38 kw', $weplyDry)
    . '<section><h2>Wix Stores order · ingen offert</h2><p>'
    . htmlspecialchars('auto ' . (string) ($storeAuto['action'] ?? '') . ' · send_quote ' . (string) ($storeSend['action'] ?? '') . ' · ' . (string) ($storeSend['why'] ?? '') . ' · status ' . (string) (jeab_inkorg_thread_meta($storeTid)['status'] ?? ''), ENT_QUOTES, 'UTF-8')
    . '</p></section>';
file_put_contents($dir . '/send-quote-dry.html', $html);
file_put_contents($dir . '/send-quote-http.json', json_encode([
    'batteri_match' => ($httpBatt['json']['body'] ?? '') === $expectBatt,
    'sol_match' => ($httpSol['json']['body'] ?? '') === $expectSol,
    'batteri' => ['template' => $httpBatt['json']['template'] ?? '', 'sender' => $httpBatt['json']['sender'] ?? '', 'subject' => $httpBatt['json']['subject'] ?? '', 'attachments' => $httpBatt['json']['attachments'] ?? []],
    'sol' => ['template' => $httpSol['json']['template'] ?? '', 'sender' => $httpSol['json']['sender'] ?? '', 'subject' => $httpSol['json']['subject'] ?? '', 'attachments' => $httpSol['json']['attachments'] ?? []],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "inkorg send quote: ok\n";
