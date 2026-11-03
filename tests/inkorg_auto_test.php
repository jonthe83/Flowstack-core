<?php

declare(strict_types=1);

/** Automatik efter klassning: offert vid köp, skräp till papperskorgen, inlärning från rättelser. */

$tmp = sys_get_temp_dir() . '/jeab-auto-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function au(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g1', 'conversation_id' => 'CV' . count($GLOBALS['_SENT']), 'message_id' => 'sent' . count($GLOBALS['_SENT']) . '@x'];
};
function au_notes(string $tid): string
{
    foreach (jeab_inkorg_threads() as $t) {
        if ($t['id'] !== $tid) {
            continue;
        }
        $bits = [];
        foreach ($t['drafts'] as $d) {
            if (($d['kind'] ?? '') === 'note') {
                $bits[] = (string) $d['body'];
            }
        }
        return implode("\n", $bits);
    }
    return '';
}
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
$GLOBALS['_CLS'] = [];
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => json_encode($GLOBALS['_CLS']);
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Ja, lastbalansering ingår i superdealen. Ring gärna för mer detaljer.']);

jeab_auto_intent_migrate();
au(str_contains(jeab_inkorg_settings()['intent'], 'Köpintresse'), 'Sams regler står i den redigerbara instruktionen');
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode("%PDF-1.4 test")]);
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'auto_trash_spam' => 1, 'auto_quote_only_from' => 'tillaten.kund@gmail.com', 'tpl' => jeab_auto_quote_seed()]);

$in = function (string $id, string $from, string $text) {
    return jeab_inkorg_ingest(['message_id' => $id . '@t', 'from' => $from, 'from_name' => 'Anna Svensson', 'to' => 'kontakt@jarfalla.example.test', 'subject' => 'Laddbox', 'body_text' => $text, 'source' => 'graph-jarfalla', 'graph_mailbox' => 'kontakt@jarfalla.example.test']);
};
$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'elbilsladdare', 'intent' => 'kop', 'offer' => 'laddbox', 'question' => 'Ingår lastbalansering?'];
$r = $in('k1', 'tillaten.kund@gmail.com', 'Hej, vill ha en laddbox. Ingår lastbalansering?');
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted', 'köpintresse → offert');
$s = $GLOBALS['_SENT'][0];
au($s['company'] === 'jarfalla' && $s['html'] && preg_match('~^<div[^>]*>Hej Anna,</div><div[^>]*><br></div><div[^>]*>Ja, lastbalansering ingår~u', $s['body']), 'HTML, kort svar på frågan först');
au($s['subject'] === 'Re: Laddbox' && !str_contains($s['body'], '#808080') && !str_contains($s['body'], 'width:280px'), 'Elbilsladdare behåller Re:-ämne och utan batteri-stil');
au(str_contains($s['body'], 'Easee Charge Up') && !preg_match('/laddbox/i', $s['body']) && count($s['attachments']) === 1, 'mall (Elbilsladdare, Charge Up) och PDF med');
au(($s['opts']['from_name'] ?? '') === 'Elin, Demo Energi Järfälla', 'avsändarnamn Elin, Demo Energi Järfälla');
au(($s['opts']['person_email'] ?? '') === 'saljare4@jarfalla.example.test' && ($s['to'] ?? '') === 'tillaten.kund@gmail.com', 'offerten går från Elins brevlåda till kunden');
$notes = au_notes((string) $r['thread_id']);
au(str_contains($notes, 'från saljare4@jarfalla.example.test till tillaten.kund@gmail.com') && str_contains($notes, 'Påminnelse skapad') && !str_contains($notes, 'kontakt@') && !str_contains($notes, 'Leadhantering misslyckades'), 'anteckningen anger elin@, inte kontakt@');
au((jeab_inkorg_thread_meta((string) $r['thread_id'])['status'] ?? '') === 'closed', 'ärendet stängt');
$rem = jeab_get_reminder(jeab_store(), (int) $a['reminder']);
au(is_array($rem) && str_contains((string) $rem['note'], 'mail: https://flowstack.example.test/index.php?p=inkorg&id='), 'påminnelse med länken mail');

$r = $in('k2', 'kund@ex.se', 'Vill köpa laddbox');
$held = jeab_auto_on_message((string) $r['id']);
au(($held['action'] ?? '') === 'hold' && str_contains((string) ($held['why'] ?? ''), 'listan'), 'testlistan håller riktiga kunder');
$k2notes = au_notes((string) $r['thread_id']);
au(str_contains($k2notes, 'Offert skickades inte automatiskt') && !str_contains($k2notes, 'Offertutkast') && !str_contains($k2notes, 'lead-offert'), 'inget utkast, synlig orsak');
$k2rem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $rr) {
    if (mb_strtolower((string) ($rr['email'] ?? '')) === 'kund@ex.se') {
        $k2rem = $rr;
    }
}
au(is_array($k2rem) && str_contains((string) $k2rem['note'], 'mail: https://flowstack.example.test/index.php?p=inkorg&id=') && str_contains((string) $k2rem['note'], 'listan för automatisk offert'), 'påminnelse med mail och orsak');

$GLOBALS['_CLS'] = ['type' => 'Nyhetsbrev/reklam', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 95, 'intent' => 'skrap'];
$r = $in('n1', 'news@shop.se', 'Rea!');
$newsAuto = jeab_auto_on_message((string) $r['id']);
au(($newsAuto['action'] ?? '') === 'historik' && ($newsAuto['sent'] ?? 1) === 0 && (jeab_inkorg_thread_meta((string) $r['thread_id'])['status'] ?? '') === 'closed' && empty(jeab_inkorg_thread_meta((string) $r['thread_id'])['deleted_at']), 'nyhetsbrev → Historik');

$GLOBALS['_CLS'] = ['type' => 'Fråga från befintlig kund', 'action' => 'svara', 'priority' => 2, 'confidence' => 95, 'product' => 'batteri', 'intent' => 'support'];
$r = $in('s1', 'kund2@ex.se', 'Batteriet laddar inte');
au((jeab_auto_on_message((string) $r['id'])['action'] ?? '') === 'none' && count($GLOBALS['_SENT']) === 1, 'support → inget svar');

jeab_inkorg_relabel((string) $r['id'], ['type' => 'Information'], 'Sam');
$ex = jeab_inkorg_settings()['examples'];
au((string) end($ex)['label'] === 'typ:Information', 'rättad typ blir exempel');
$p = jeab_inkorg_llm_prompt(jeab_inkorg_get((string) $r['id']));
au(str_contains($p[0]['content'], '→ typ Information'), 'exemplet styr Grok');

$rules = implode("\n", jeab_auto_rule_lines());
au(str_contains($rules, 'saljare3@jarfalla.example.test') && str_contains($rules, 'saljare4@jarfalla.example.test') && str_contains($rules, 'wix-forms.com') && str_contains($rules, 'crm.wix.com') && str_contains($rules, 'Offert skickades inte automatiskt') && str_contains($rules, 'test.se') && str_contains($rules, 'Ogiltigt eller saknat mobilnummer'), 'reglerna är synliga som text');
au(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php'), 'jeab_auto_rule_lines()'), 'Regler-sidan visar samma rader');

jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 batteri")]);
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'auto_trash_spam' => 1, 'auto_quote_only_from' => '', 'auto_send_per_hour' => 500, 'auto_send_per_day' => 2000, 'tpl' => jeab_auto_quote_seed()]);
$wixBody = function (string $name, string $email, string $phone, string $address): string {
    return "A site visitor just submitted your form Batteri lead\n\nSubmission summary:\n\nNamn:\n\n{$name}\n\nAdress:\n\n{$address}\n\nTelefon:\n\n{$phone}\n\nE-post:\n\n{$email}\n";
};
$wix = function (string $id, string $body) {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@wix.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär JEAB',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'JEAB Batteri lead',
        'body_text' => $body,
        'received_at' => '2026-10-16T13:47:00+02:00',
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'wix-' . $id,
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=wix-forms.com'],
    ]);
};
$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'batteri', 'intent' => 'kop', 'offer' => 'batteri', 'question' => ''];
$parsed = jeab_lead_customer(['from' => 'info@wix-forms.com', 'from_name' => 'Formulär JEAB', 'subject' => 'JEAB Batteri lead', 'body' => $wixBody('testar lite', 'test@test.se', '+46 123 45 67 89', 'testvägen')]);
au($parsed['platform'] === 'wix-forms.com' && $parsed['name'] === 'testar lite' && $parsed['email'] === 'test@test.se' && $parsed['phone'] === '+46 123 45 67 89' && $parsed['address'] === 'testvägen', 'Wix-formulär: kundfält, inte avsändaren');

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-anna', $wixBody('Anna Svensson', 'kund.b@example.invalid', '070-123 45 67', 'Batterivägen 1'));
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted' && ($a['from'] ?? '') === 'saljare3@jarfalla.example.test' && ($a['to'] ?? '') === 'kund.b@example.invalid', 'Wix-lead: offert från saljare3@ till formulärets e-post');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au($s['to'] === 'kund.b@example.invalid' && ($s['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test' && ($s['opts']['reply_to_graph_id'] ?? '') === '' && $s['subject'] === 'Offert batteri', 'nytt mejl med ämnet Offert batteri, inte svar till wix-forms');
au(str_contains($s['body'], 'Hej Anna,') && !str_contains($s['body'], 'Formulär') && !str_contains($s['body'], 'info@wix-forms.com') && !str_contains($s['body'], 'Anna Svensson') && !str_contains($s['body'], 'kund.b@example.invalid') && !str_contains($s['body'], 'Batterivägen 1'), 'hälsning är Anna och kundblocket efter signaturen är borta');
$tid = (string) $r['thread_id'];
$notes = au_notes($tid);
au(str_contains($notes, 'Offert (Batteri) skickad automatiskt från saljare3@jarfalla.example.test till kund.b@example.invalid') && str_contains($notes, 'Påminnelse skapad') && !str_contains($notes, 'kontakt@') && !str_contains($notes, 'Leadhantering misslyckades'), 'anteckningen stämmer med avsändare, mottagare och påminnelse');
$th = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === $tid) {
        $th = $t;
    }
}
au(is_array($th) && ($th['status'] ?? '') === 'closed', 'Wix-offerten stänger ärendet');
$out = null;
foreach ($th['messages'] as $m) {
    if (str_starts_with((string) ($m['source'] ?? ''), 'outbound-')) {
        $out = $m;
    }
}
au(is_array($out) && $out['from'] === 'saljare3@jarfalla.example.test' && $out['to'] === 'kund.b@example.invalid' && str_starts_with((string) $out['body'], 'Hej Anna,'), 'tråden visar saljare3@, Anna och Hej Anna');
$contact = jeab_inkorg_contact_for_thread($th);
au(is_array($contact) && $contact['name'] === 'Anna Svensson' && in_array('kund.b@example.invalid', $contact['emails'], true) && !in_array('info@wix-forms.com', $contact['emails'], true) && $contact['mobile'] === '+46701234567' && str_contains((string) $contact['address'], 'Batterivägen 1'), 'kundpanelen är Anna, inte wix-forms');
$rem = jeab_get_reminder(jeab_store(), (int) $a['reminder']);
au(is_array($rem) && ($rem['phone'] ?? null) === '+46701234567' && ($rem['email'] ?? '') === 'kund.b@example.invalid', 'påminnelsen har kundens mobil');

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-test', $wixBody('testar lite', 'test@test.se', '+46 123 45 67 89', 'testvägen'));
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'hold' && str_contains((string) ($a['why'] ?? ''), 'test@test.se') && str_contains((string) ($a['why'] ?? ''), 'testar lite') && count($GLOBALS['_SENT']) === $before, 'testinlämning skickas inte');
$tid = (string) $r['thread_id'];
$notes = au_notes($tid);
au(str_contains($notes, 'Offert skickades inte automatiskt:') && str_contains($notes, 'Påminnelse skapad. Telefonen «+46 123 45 67 89» sparades inte') && !str_contains($notes, 'Leadhantering misslyckades') && !str_contains($notes, 'skickad automatiskt från'), 'testfallet får sann anteckning, inte «misslyckades» och «skickad»');
$th = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === $tid) {
        $th = $t;
    }
}
au(is_array($th) && ($th['status'] ?? '') === 'closed', 'testinlämning med påminnelse lämnar öppna vyer');
$found = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'test@test.se') {
        $found = $row;
    }
}
au(is_array($found) && ($found['phone'] ?? null) === null && str_contains((string) $found['note'], 'Tel (ogiltigt, ej sparat): +46 123 45 67 89'), 'ogiltigt nummer stoppar inte påminnelsen');
$contact = jeab_inkorg_contact_for_thread($th);
au(is_array($contact) && $contact['name'] === 'testar lite' && in_array('test@test.se', $contact['emails'], true) && !in_array('info@wix-forms.com', $contact['emails'], true), 'kundpanelen på testlead är kunden i formuläret');

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-karin', $wixBody('Karin Berg', 'karin.berg@gmail.com', '+46 123 45 67 89', 'Karinvägen 2'));
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted' && ($a['to'] ?? '') === 'karin.berg@gmail.com', 'ogiltig telefon stoppar inte offerten');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au(str_contains($s['body'], 'Hej Karin,') && !str_contains($s['body'], 'Formulär'), 'hälsning är Karin');
$notes = au_notes((string) $r['thread_id']);
au(str_contains($notes, 'Telefonen «+46 123 45 67 89» sparades inte') && str_contains($notes, 'från saljare3@jarfalla.example.test till karin.berg@gmail.com') && str_contains($notes, 'Påminnelse skapad') && str_contains($notes, 'Telefonen sparades inte.') && !str_contains($notes, 'Leadhantering misslyckades'), 'både offert- och telefonanteckningen är sanna');
$found = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'karin.berg@gmail.com') {
        $found = $row;
    }
}
au(is_array($found) && ($found['phone'] ?? null) === null, 'Karins påminnelse finns utan telefon');

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-noname', "A site visitor just submitted your form Batteri lead\n\nE-post:\n\nverklig.kund@gmail.com\n");
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted' && ($a['to'] ?? '') === 'verklig.kund@gmail.com' && count($GLOBALS['_SENT']) === $before + 1, 'formulär utan namn skickas ändå');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au(preg_match('~Hej</div>~u', (string) ($s['body'] ?? '')) === 1 && !str_contains((string) ($s['body'] ?? ''), 'Hej Formulär') && !str_contains((string) ($s['body'] ?? ''), 'Hej Verklig'), 'hälsningen är bara Hej');

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-nomail', "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nSara Ek\n");
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'hold' && str_contains((string) ($a['why'] ?? ''), 'kundadress') && count($GLOBALS['_SENT']) === $before, 'formulär utan e-post skickas inte till wix-forms');
$saraRem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (str_contains((string) ($row['note'] ?? ''), 'ring kunden och ta mejladressen') && str_contains((string) ($row['customer_name'] ?? ''), 'Sara Ek')) {
        $saraRem = $row;
    }
}
au(is_array($saraRem) && in_array('Roberto', (array) ($saraRem['assignees'] ?? []), true) && (string) ($saraRem['email'] ?? '') !== 'info@wix-forms.com' && !str_contains(au_notes((string) $r['thread_id']), 'Påminnelse skapades inte'), 'utan kundadress blir en påminnelse till Roberto, inte till wix-adressen');
$th = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === $r['thread_id']) {
        $th = $t;
    }
}
au(jeab_inkorg_contact_for_thread($th) === null, 'wix-forms blir inte kund när e-post saknas');

$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
au(is_string($jpeg) && str_starts_with($jpeg, "\xFF\xD8"), 'testbilden är JPEG');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode($jpeg)]);

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-bild', $wixBody('Mia Holm', 'mia.holm@gmail.com', '070-555 66 77', 'Miavägen 4'));
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted' && ($a['from'] ?? '') === 'saljare3@jarfalla.example.test', 'batterioffert med teambild skickas från saljare3@');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au($s['subject'] === 'Offert batteri' && ($s['opts']['reply_to_graph_id'] ?? '') === '', 'formulärbatteri är ett nytt mejl med ämnet Offert batteri');
au(str_contains($s['body'], 'src="cid:team-jarfalla-energi.jpg"') && str_contains($s['body'], 'width="280"') && str_contains($s['body'], 'width:280px') && !str_contains($s['body'], 'width="400"') && !str_contains($s['body'], 'width:400px'), 'teambilden är 280 px och cid team-jarfalla-energi.jpg');
$img = null;
foreach ($s['attachments'] as $att) {
    if (($att['content_id'] ?? '') !== '') {
        $img = $att;
    }
}
au(is_array($img) && $img['filename'] === 'team-jarfalla-energi.jpg' && $img['content_id'] === 'team-jarfalla-energi.jpg' && $img['content'] === $jpeg, 'bilagan heter team-jarfalla-energi.jpg och är team_jarfalla');
$muted = 'font-family:inherit; font-size:inherit; color:#808080; font-style:italic';
au(substr_count($s['body'], $muted) === 4, 'exakt de fyra sista styckena är grå kursiv');
au(str_contains($s['body'], $muted . '">Vi är bästa installatören') && str_contains($s['body'], $muted . '">Med ett batteri finns möjligheten') && str_contains($s['body'], $muted . '">Är det möjligt installerar vi gärna') && str_contains($s['body'], $muted . '">Batterier &gt;20 kWh'), 'grå kursiv från bästa installatören till Batterier &gt;20 kWh');
au(str_contains($s['body'], 'color:inherit">Roberto<br>Elektriker') && !str_contains($s['body'], $muted . '">Roberto') && !str_contains($s['body'], $muted . '">Den minsta'), 'signatur och inledning är inte grå kursiv');
au(str_contains($s['body'], 'Hej Mia,') && !str_contains($s['body'], 'Mia Holm') && !str_contains($s['body'], 'mia.holm@gmail.com') && !str_contains($s['body'], 'Miavägen 4'), 'kundblocket efter signaturen är borta');

$before = count($GLOBALS['_SENT']);
$r = $wix('wix-notel', "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nOla Ny\n\nAdress:\n\nOlavägen 5\n\nE-post:\n\nola.ny@gmail.com\n");
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted', 'formulär utan telefon skickas');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au(str_contains($s['body'], 'Hej Ola,') && !str_contains($s['body'], 'Ola Ny') && !str_contains($s['body'], 'ola.ny@gmail.com') && !str_contains($s['body'], 'Olavägen 5'), 'kundblocket efter signaturen är borta även utan telefon');

$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'batteri', 'intent' => 'kop', 'offer' => 'batteri', 'question' => ''];
$r = jeab_inkorg_ingest([
    'message_id' => 'direct-batt@t',
    'from' => 'sara.ek@gmail.com',
    'from_name' => 'Sara Ek',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Re: Batteri till villan',
    'body_text' => "Hej\n\nVi vill ha offert på batteri.\n\nAdress: Ekvägen 9\nTelefon: 073-111 22 33\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-direct-batt',
]);
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted' && ($a['to'] ?? '') === 'sara.ek@gmail.com', 'vanligt batterimejl går till kunden');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au($s['subject'] === 'Offert batteri' && ($s['opts']['reply_to_graph_id'] ?? '') === '' && ($s['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'vanligt batterimejl är nytt, ämne Offert batteri, från saljare3@');
au(str_contains($s['body'], 'Hej Sara,') && !str_contains($s['body'], 'Sara Ek') && !str_contains($s['body'], 'sara.ek@gmail.com') && !str_contains($s['body'], 'Ekvägen 9'), 'direktmejl har ingen kundrad efter signaturen');

$r = jeab_inkorg_ingest([
    'message_id' => 'hemsol-batt@t',
    'from' => 'lead@hemsol.se',
    'from_name' => 'Hemsol',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Ny lead batteri',
    'body_text' => "Namn: Pia Holm\nAdress: Piavägen 2\nTelefon: 070-999 88 77\nE-post: pia.holm@gmail.com\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-hemsol-batt',
    'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=hemsol.se'],
]);
$a = jeab_auto_on_message((string) $r['id']);
au(($a['action'] ?? '') === 'quoted' && ($a['to'] ?? '') === 'pia.holm@gmail.com', 'plattformslead batteri går till kunden');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au($s['subject'] === 'Offert batteri' && ($s['opts']['reply_to_graph_id'] ?? '') === '' && str_contains($s['body'], 'Hej Pia,') && !str_contains($s['body'], 'Pia Holm') && !str_contains($s['body'], 'pia.holm@gmail.com'), 'plattformsbatteri har samma ämne och inget kundblock');

$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'elbilsladdare', 'intent' => 'kop', 'offer' => 'laddbox', 'question' => ''];
$r = jeab_inkorg_ingest([
    'message_id' => 'direct-ladd@t',
    'from' => 'bosse@gmail.com',
    'from_name' => 'Bosse Berg',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Laddbox hemma',
    'body_text' => 'Hej, jag vill ha en elbilsladdare.',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-direct-ladd',
]);
$a = jeab_auto_on_message((string) $r['id']);
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au(($a['action'] ?? '') === 'quoted' && $s['subject'] === 'Re: Laddbox hemma' && ($s['opts']['reply_to_graph_id'] ?? '') === 'graph-direct-ladd' && ($s['opts']['person_email'] ?? '') === 'saljare4@jarfalla.example.test', 'Elbilsladdare svarar fortfarande i tråden');
$laddImg = null;
foreach ($s['attachments'] as $att) {
    if (($att['content_id'] ?? '') !== '') {
        $laddImg = $att;
    }
}
au(is_array($laddImg) && $laddImg['filename'] === 'team.jpg' && $laddImg['content_id'] === 'team' && str_contains($s['body'], 'width="400"') && str_contains($s['body'], 'src="cid:team"') && !str_contains($s['body'], '#808080') && !str_contains($s['body'], 'team-jarfalla-energi.jpg'), 'Elbilsladdare behåller team.jpg i 400 px');

$tpl = jeab_auto_quote_seed();
$tpl['sol_jarfalla'] = "Hej\n\nSOL-UNIK-MENING som inte är batteri.";
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'auto_trash_spam' => 1, 'auto_quote_only_from' => '', 'tpl' => $tpl]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode("%PDF-1.4 solunik")]);
$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'solceller', 'intent' => 'kop', 'offer' => 'sol', 'question' => ''];
$r = jeab_inkorg_ingest([
    'message_id' => 'direct-sol@t',
    'from' => 'solkund@gmail.com',
    'from_name' => 'Sol Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Solceller tack',
    'body_text' => 'Hej, vi vill ha solceller.',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-direct-sol',
]);
$a = jeab_auto_on_message((string) $r['id']);
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au(($a['action'] ?? '') === 'quoted' && $s['subject'] === 'Re: Solceller tack' && str_contains($s['body'], 'SOL-UNIK-MENING') && !str_contains($s['body'], '#808080') && !str_contains($s['subject'], 'Offert batteri') && ($s['opts']['person_email'] ?? '') === 'demo@example.test', 'solmallen är oförändrad');
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'auto_trash_spam' => 1, 'auto_quote_only_from' => '', 'tpl' => jeab_auto_quote_seed()]);

$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'elbilsladdare', 'intent' => 'kop', 'offer' => 'laddbox', 'question' => 'Ingår lastbalansering?'];
$before = count($GLOBALS['_SENT']);
$st = jeab_auto_selftest([
    'deliver_to' => 'demo.person@example.invalid',
    'from' => 'info@wix-forms.com',
    'subject' => 'Re: fel ämne',
    'text' => "Namn: Sam Exempel\nAdress: Testgatan 1\nE-post: demo.person@example.invalid\n",
]);
au(($st['ok'] ?? false) === true && ($st['auto']['action'] ?? '') === 'quoted' && ($st['auto']['from'] ?? '') === 'saljare3@jarfalla.example.test' && ($st['auto']['to'] ?? '') === 'demo.person@example.invalid' && ($st['labels']['offer'] ?? '') === 'batteri', 'självtest med deliver_to skickar batterioffert från saljare3@');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
au(count($GLOBALS['_SENT']) === $before + 1 && $s['subject'] === 'Offert batteri' && ($s['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test' && ($s['opts']['reply_to_graph_id'] ?? '') === '' && $s['to'] === 'demo.person@example.invalid', 'självtestet är nytt mejl via Graph-avsändaren saljare3@');
au(str_contains($s['body'], 'src="cid:team-jarfalla-energi.jpg"') && str_contains($s['body'], 'width="280"') && substr_count($s['body'], $muted) === 4 && !str_contains($s['body'], 'Sam Exempel<br>') && !str_contains($s['body'], 'testkund@example.com') && !str_contains($s['body'], 'demo.person@example.invalid') && !str_contains($s['body'], 'Testgatan 1'), 'självtestet har samma batteri-HTML utan kundblock');
au(!isset($GLOBALS['_JEAB_AUTO_DELIVER_TO']), 'självtestet läcker inte deliver_to');
au(str_contains(implode("\n", jeab_auto_rule_lines()), 'ämne «Offert batteri», från saljare3@jarfalla.example.test'), 'regeln beskriver batterisjälvtestet');

$before = count($GLOBALS['_SENT']);
$bad = jeab_auto_selftest(['deliver_to' => 'kund@gmail.com', 'from' => 'info@wix-forms.com', 'text' => 'batteri']);
au(($bad['ok'] ?? true) === false && count($GLOBALS['_SENT']) === $before, 'deliver_to till annan adress skickar inget');
$none = jeab_auto_selftest(['from' => 'testkund@example.com', 'text' => 'batteri']);
au(($none['ok'] ?? true) === false && str_contains((string) ($none['error'] ?? ''), 'avsändaren'), 'testkund är inte en tillåten avsändare');
$leadkitFrom = jeab_auto_selftest(['from' => 'leads@leadkit.com', 'deliver_to' => 'demo.person@example.invalid', 'text' => 'batteri']);
au(($leadkitFrom['ok'] ?? true) === false && count($GLOBALS['_SENT']) === $before, 'leadkit är inte en tillåten avsändare');
$supportFrom = jeab_auto_selftest(['from' => 'support@leadkit.com', 'deliver_to' => 'demo@example.test', 'text' => 'batteri']);
au(($supportFrom['ok'] ?? true) === false && count($GLOBALS['_SENT']) === $before, 'support@leadkit är inte en tillåten avsändare');

$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'product' => 'batteri', 'intent' => 'kop', 'offer' => 'batteri', 'question' => ''];
$mock = $GLOBALS['JEAB_GRAPH_SEND'];
unset($GLOBALS['JEAB_GRAPH_SEND']);
$before = count($GLOBALS['_SENT']);
$r = $wix('wix-off', $wixBody('Nora Lind', 'nora.lind@gmail.com', '070-111 22 33', 'Noravägen 3'));
$a = jeab_auto_on_message((string) $r['id']);
$GLOBALS['JEAB_GRAPH_SEND'] = $mock;
au(($a['action'] ?? '') === 'hold' && str_contains((string) ($a['why'] ?? ''), 'saljare3@jarfalla.example.test') && str_contains((string) ($a['why'] ?? ''), 'kontakt@') && count($GLOBALS['_SENT']) === $before, 'av personbrevlåda faller inte tillbaka till kontakt@');
echo "inkorg auto: ok\n";
