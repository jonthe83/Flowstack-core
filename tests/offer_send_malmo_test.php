<?php

declare(strict_types=1);

/** offer.send_malmo: Malmö-offert utan inkorgstråd. Påhittade adresser. */

$tmp = sys_get_temp_dir() . '/jeab-offer-malmo-' . bin2hex(random_bytes(4));
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

function om(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => 'saljare.syd@malmo.example.test', 'graph_id' => 'g-om', 'message_id' => 'sent-om@local'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_malmo', 'data' => base64_encode('%PDF-1.4 batteri-malmo')]);
jeab_auto_pdf_save(['key' => 'laddbox_malmo', 'data' => base64_encode('%PDF-1.4 laddbox-malmo')]);
$team = jeab_auto_team_image_path('malmo');
@mkdir(dirname($team), 0775, true);
file_put_contents($team, "\xFF\xD8" . str_repeat('J', 40));
file_put_contents($team . '.empty', '');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
om(str_contains($hook, "offer.send_malmo") && str_contains($hook, 'jeab_inkorg_hook_authorized()'), 'hooken kräver samma token som övriga inkorgshookar');
om(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php'), "case 'offer.send_malmo':"), 'åtgärden ligger bland hookarna');

function om_neo_prices(string $text, string $label, bool $full = true): void
{
    om(!preg_match('/utan avdrag|utan rätt till grön teknik|priset det dubbla/iu', $text), $label . ': ingen text om pris utan grön teknik');
    foreach (['46 500', '70 000', '106 000', '67 500', '81 000', '93 000', '9 kWh', '18 kWh', 'Fox', 'SigenNeo'] as $old) {
        om(!str_contains($text, $old), $label . ': ingen gammal rad ' . $old);
    }
    if (!$full) {
        om(str_contains($text, '39 500'), $label . ': 39 500 syns');
        return;
    }
    foreach (['7,5 kWh', '39 500', '15 kWh', '55 500', '19,5 kWh', '65 500', '22,5 kWh', '68 500', '30 kWh', '81 500', '37,5 kWh', '94 500'] as $bit) {
        om(str_contains($text, $bit), $label . ': ' . $bit);
    }
}

$tpl = jeab_auto_malmo_template('batteri_malmo');
om_neo_prices($tpl, 'mallen');
$edited = "Hej\n\n9 kWh för 46 500 kr efter grön teknik. Sigenergy 18 kWh för 70 000 kr. Fox 49 kWh för 106 000 kr.\n\nSigenNeo med 8 kW växelriktare:\n7,5 kWh 39 500 kr\n\nVilken storlek på batteri är rätt för dig?\n\nKim\nElektriker\n070-000 00 03\nDemo Energi Malmö\n\nUtan rätt till grön teknik är priset det dubbla.";
$editedFixed = jeab_offer_malmo_batteri_with_gt($edited);
om_neo_prices($editedFixed, 'redigerad mall');
om(mb_strpos($editedFixed, '39 500') < mb_strpos($editedFixed, 'Vilken storlek på batteri'), 'redigerad mall: NEO-priserna står före frågan om storlek');
$neoOnly = jeab_offer_malmo_batteri_with_gt("Hej\n\nSigenNeo med 8 kW växelriktare:\n7,5 kWh 39 500 kr\n15 kWh 55 500 kr\n22,5 kWh 67 500 kr\n30 kWh 81 000 kr\n37,5 kWh 93 000 kr\n\nKim\nElektriker");
om_neo_prices($neoOnly, 'bara NEO');
om(str_contains($neoOnly, '19,5 kWh') && str_contains($neoOnly, '68 500') && !str_contains($neoOnly, 'Sigenergy 18 kWh'), 'bara NEO: raderna är PDF:ens priser');
$short = jeab_offer_malmo_batteri_with_gt("Hej\n\nMalmö batteri.");
om($short === "Hej\n\nMalmö batteri.", 'kort mall utan priser lämnas');
jeab_notiser_store_write(['items' => ['offert-batteri-malmo' => ['body' => $edited]], 'pdf' => []]);
$rendered = jeab_offer_malmo_render('batteri', 'Anna Berg');
om_neo_prices((string) ($rendered['text'] ?? ''), 'offer.send_malmo');
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
$autoIn = jeab_inkorg_ingest([
    'message_id' => 'gt-place@offer.test',
    'from' => 'anna.berg.gt@gmail.com',
    'from_name' => 'Anna Berg',
    'to' => jeab_graph_mailboxes()['malmo'],
    'subject' => 'Batteri',
    'body_text' => "Namn: Anna Berg\nE-post: anna.berg.gt@gmail.com\nTelefon: 070-100 20 01\nAdress: Storgatan 9, 211 00 Malmö\n",
    'source' => 'graph-malmo',
    'graph_mailbox' => jeab_graph_mailboxes()['malmo'],
    'thread_key' => 'gt-place',
    'preset_labels' => ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => ''],
]);
$placeSent = count($GLOBALS['_SENT']);
$autoDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $autoIn['thread_id'], 'dry' => 1]);
om(($autoDry['ok'] ?? false) === true && ($autoDry['sender'] ?? '') === 'saljare.syd@malmo.example.test', 'automatisk Malmö-batterioffert förhandsvisas från Kim');
om_neo_prices((string) ($autoDry['body'] ?? ''), 'automatisk offert');
om(count($GLOBALS['_SENT']) === $placeSent, 'placeringstestet skickar inget');
jeab_notiser_store_write(['items' => [], 'pdf' => []]);

$kund = 'unikmottagare@example.com';
$before = count($GLOBALS['_SENT']);
$dry = jeab_offer_send_malmo([
    'to' => $kund,
    'product' => 'batteri',
    'customer_name' => 'Anna Berg',
    'address' => 'Exempelgatan 1, 211 00 Malmö',
]);
om(count($GLOBALS['_SENT']) === $before && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['ok'] ?? false) === true, 'dry=1 är standard och skickar inget');
om(($dry['subject'] ?? '') === 'Offert batteri' && ($dry['sender'] ?? '') === 'saljare.syd@malmo.example.test', 'dry visar ämne och avsändare kim@');
om(($dry['attachment'] ?? '') === jeab_auto_pdf_name('batteri_malmo') && mb_strlen((string) ($dry['body_preview'] ?? '')) <= 300 && mb_strlen((string) ($dry['body_preview'] ?? '')) > 0, 'dry visar bilagenamn och högst 300 tecken');
om(($dry['guard'] ?? '') === 'ok' && ($dry['reminder_plan']['action'] ?? '') === 'create' && ($dry['reminder_plan']['who'] ?? '') === 'Kim' && ($dry['reminder_plan']['company'] ?? '') === 'malmo', 'dry visar spärr och påminnelseplan');
om(($dry['to'] ?? '') === $kund && ($dry['from'] ?? '') === 'saljare.syd@malmo.example.test' && ($dry['template'] ?? '') === 'offert-batteri-malmo' && ($dry['prices'] ?? null) === jeab_offer_malmo_price_rows() && ($dry['answer_included'] ?? true) === false && mb_strlen((string) ($dry['preview'] ?? '')) <= 200 && mb_strlen((string) ($dry['preview'] ?? '')) > 0, 'dry visar mottagare, avsändare, mall, NEO-prisraderna och förhandsvisning');
om_neo_prices((string) ($dry['body_preview'] ?? '') . "\n" . (string) ($dry['preview'] ?? ''), 'dry-förhandsvisning', false);
om(!str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE), 'helpscout.net') && !str_contains((string) ($dry['body_preview'] ?? ''), 'Kundens fråga'), 'dry-svaret har ingen Help Scout-länk');
om(!array_key_exists('gron_teknik', $dry), 'dry har inget gron_teknik-fält');

$gtTo = 'utan.avdrag@example.com';
$gtBefore = count($GLOBALS['_SENT']);
$gtDry = jeab_offer_send_malmo([
    'to' => $gtTo,
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 1,
    'gron_teknik' => false,
]);
$gtJson = json_encode($gtDry, JSON_UNESCAPED_UNICODE);
$gtPreview = (string) ($gtDry['body_preview'] ?? '') . "\n" . (string) ($gtDry['preview'] ?? '');
om(($gtDry['ok'] ?? false) === true && !array_key_exists('gron_teknik', $gtDry) && ($gtDry['prices'] ?? null) === jeab_offer_malmo_price_rows() && ($gtDry['to'] ?? '') === $gtTo && ($gtDry['from'] ?? '') === 'saljare.syd@malmo.example.test' && ($gtDry['subject'] ?? '') === 'Offert batteri' && ($gtDry['sender'] ?? '') === 'saljare.syd@malmo.example.test' && count($GLOBALS['_SENT']) === $gtBefore, 'gron_teknik=false ignoreras: NEO-prisrader, to, from och ämne');
om_neo_prices($gtPreview . "\n" . $gtJson, 'dry med ignorerad gron_teknik', false);
$gtLive = jeab_offer_send_malmo([
    'to' => $gtTo,
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 0,
    'gron_teknik' => 0,
]);
$gtSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$gtNames = [];
foreach ((array) ($gtSent['attachments'] ?? []) as $att) {
    if (is_array($att)) {
        $gtNames[] = (string) ($att['filename'] ?? '');
    }
}
$gtHtml = (string) ($gtSent['body'] ?? '');
om(($gtLive['ok'] ?? false) === true && ($gtLive['sent'] ?? 0) === 1 && !array_key_exists('gron_teknik', $gtLive) && ($gtLive['prices'] ?? null) === jeab_offer_malmo_price_rows() && ($gtLive['sender'] ?? '') === 'saljare.syd@malmo.example.test' && $gtNames === [jeab_auto_pdf_name('batteri_malmo')], 'gron_teknik=0 skickar NEO-raderna från Kim utan teambild');
om_neo_prices($gtHtml, 'skickat mejl trots gron_teknik');

$sellerBefore = count($GLOBALS['_SENT']);
$seller = jeab_offer_send_malmo(['to' => 'info@weply.se', 'product' => 'batteri', 'customer_name' => 'Anna Berg', 'dry' => 0]);
om(count($GLOBALS['_SENT']) === $sellerBefore && ($seller['ok'] ?? true) === false && ($seller['sent'] ?? 1) === 0 && str_contains((string) ($seller['guard'] ?? ''), 'leadsäljare'), 'leadsäljardomän nekas');
$empty = jeab_offer_send_malmo(['to' => '', 'product' => 'batteri', 'customer_name' => 'Anna Berg', 'dry' => 0]);
$bad = jeab_offer_send_malmo(['to' => 'inte-en-adress', 'product' => 'elbilsladdare', 'customer_name' => 'Anna Berg', 'dry' => 1]);
om(($empty['ok'] ?? true) === false && ($bad['ok'] ?? true) === false && count($GLOBALS['_SENT']) === $sellerBefore, 'tom eller ogiltig e-post nekas');

$live = jeab_offer_send_malmo([
    'to' => $kund,
    'product' => 'batteri',
    'customer_name' => 'Anna Berg',
    'address' => 'Exempelgatan 1, 211 00 Malmö',
    'hs_conversation_id' => 'https://secure.helpscout.net/conversation/24563/hej',
    'dry' => 0,
]);
$sent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$names = [];
foreach ((array) ($sent['attachments'] ?? []) as $att) {
    if (is_array($att)) {
        $names[] = (string) ($att['filename'] ?? '');
    }
}
$body = (string) ($sent['body'] ?? '') . (string) ($sent['html'] ?? '');
om(($live['ok'] ?? false) === true && ($live['sent'] ?? 0) === 1 && ($live['sender'] ?? '') === 'saljare.syd@malmo.example.test', 'dry=0 skickar från kim@');
om(($sent['opts']['person_email'] ?? '') === 'saljare.syd@malmo.example.test' && !empty($sent['opts']['malmo_hs_kim']) && ($sent['to'] ?? '') === $kund, 'samma Graph-väg som Kims offert');
om($names === [jeab_auto_pdf_name('batteri_malmo')], 'bara batteri-PDF, ingen teambild, fick ' . implode(',', $names));
om_neo_prices($body, 'skickat mejl');
om(!str_contains(strtolower($body), 'helpscout.net') && !str_contains($body, 'Kundens fråga'), 'mejlet har inga interna rader');
om(str_starts_with((string) ($sent['attachments'][0]['content'] ?? ''), '%PDF'), 'bilagan är PDF:en');

$againBefore = count($GLOBALS['_SENT']);
$again = jeab_offer_send_malmo(['to' => $kund, 'product' => 'batteri', 'customer_name' => 'Anna Berg', 'dry' => 0]);
om(count($GLOBALS['_SENT']) === $againBefore && ($again['already_sent'] ?? false) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($again['date'] ?? '')) === 1 && ($again['sent'] ?? 1) === 0, 'samma mottagare och produkt inom 30 dagar är already_sent');

$log = (string) file_get_contents(jeab_inkorg_dir() . '/malmo-offer-sends.json');
om(!str_contains($log, 'unikmottagare') && str_contains($log, 'u***@example.com') && str_contains($log, '"hs_conversation_id": "24563"') && !str_contains($log, 'helpscout.net'), 'loggen har maskad adress och bara id');

$reminders = [];
foreach (jeab_reminders_every(jeab_store()) as $row) {
    if (is_array($row) && mb_strtolower((string) ($row['email'] ?? '')) === $kund) {
        $reminders[] = $row;
    }
}
om(count($reminders) === 1 && ($live['reminder_action'] ?? '') === 'create' && (int) ($live['reminder'] ?? 0) === (int) ($reminders[0]['id'] ?? 0), 'en påminnelse skapas');
$note = (string) ($reminders[0]['note'] ?? '');
om(str_contains($note, 'Kim') === false && str_contains($note, 'hs_conversation_id: 24563') && !str_contains(strtolower($note), 'helpscout.net') && !str_contains($note, 'unikmottagare') && !str_contains($note, 'Kundens fråga'), 'anteckningen saknar Help Scout-länk och e-post');
om((string) ($reminders[0]['company'] ?? '') === 'malmo' && str_contains((string) ($reminders[0]['assignee'] ?? ''), 'Kim'), 'påminnelsen är till Kim i Malmö');

$box = jeab_offer_send_malmo([
    'to' => 'annan.kund@example.com',
    'product' => 'elbilsladdare',
    'customer_name' => 'Bo Ek',
    'dry' => 0,
]);
$boxSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$boxName = (string) (($boxSent['attachments'][0]['filename'] ?? ''));
om(($box['ok'] ?? false) === true && $boxName === jeab_auto_pdf_name('laddbox_malmo'), 'elbilsladdare får Easee-PDF:en');

$open = jeab_create_reminder(jeab_store(), 'Bo Ek – Batteri', jeab_today_start(), 'fanns redan', null, [
    'customer' => 'Bo Ek',
    'email' => 'fanns.redan@example.com',
    'address' => 'Andra gatan 2, 211 20 Malmö',
    'company' => 'malmo',
    'who' => 'Kim',
]);
$openId = (int) ($open['id'] ?? 0);
$nBefore = 0;
foreach (jeab_reminders_every(jeab_store()) as $row) {
    if (is_array($row)) {
        $nBefore++;
    }
}
$linked = jeab_offer_send_malmo([
    'to' => 'fanns.redan@example.com',
    'product' => 'batteri',
    'customer_name' => 'Bo Ek',
    'address' => 'Andra gatan 2, 211 20 Malmö',
    'dry' => 0,
]);
$nAfter = 0;
foreach (jeab_reminders_every(jeab_store()) as $row) {
    if (is_array($row)) {
        $nAfter++;
    }
}
om(($linked['reminder_action'] ?? '') === 'link' && (int) ($linked['reminder'] ?? 0) === $openId && $nAfter === $nBefore, 'öppen påminnelse med samma e-post länkas');
$linkedRow = jeab_store()->getReminder($openId);
om(is_array($linkedRow) && !str_contains(strtolower((string) ($linkedRow['note'] ?? '')), 'helpscout.net'), 'länkad anteckning saknar Help Scout-länk');

$byAddr = jeab_create_reminder(jeab_store(), 'Cajsa – Elbilsladdare', jeab_today_start(), 'adress', null, [
    'customer' => 'Cajsa',
    'email' => 'annan.cajsa@example.com',
    'address' => 'Tredje vägen 3, 214 00 Malmö',
    'company' => 'malmo',
    'who' => 'Kim',
]);
$addrId = (int) ($byAddr['id'] ?? 0);
$addrSend = jeab_offer_send_malmo([
    'to' => 'ny.cajsa@example.com',
    'product' => 'elbilsladdare',
    'customer_name' => 'Cajsa',
    'address' => 'Tredje vägen 3, 214 00 Malmö',
    'dry' => 0,
]);
om(($addrSend['reminder_action'] ?? '') === 'link' && (int) ($addrSend['reminder'] ?? 0) === $addrId, 'öppen påminnelse med samma adress länkas');

$items = [];
foreach ((array) (jeab_notiser_list()['items'] ?? []) as $item) {
    if (is_array($item)) {
        $items[(string) ($item['id'] ?? '')] = $item;
    }
}
om((int) ($items['offert-batteri-malmo']['sends_30d'] ?? 0) >= 1 && (int) ($items['offert-laddbox-malmo']['sends_30d'] ?? 0) >= 1, 'Notiser räknar utskicken');

jeab_notiser_store_write(['items' => ['offert-laddbox-malmo' => ['body' => "Hej\n\nKundens fråga: intern.\n\nSe https://secure.helpscout.net/conversation/9\n\nPris 8 490 kr."]], 'pdf' => []]);
$stripBefore = count($GLOBALS['_SENT']);
$strip = jeab_offer_send_malmo([
    'to' => 'rensad.kund@example.com',
    'product' => 'elbilsladdare',
    'customer_name' => 'Disa',
    'dry' => 0,
]);
$stripSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$stripBody = (string) ($stripSent['body'] ?? '');
om(($strip['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $stripBefore + 1 && !str_contains($stripBody, 'Kundens fråga') && !str_contains(strtolower($stripBody), 'helpscout.net') && str_contains($stripBody, '8 490'), 'interna rader och Help Scout-länkar följer inte med');

$ansTo = 'nora.svar@example.com';
$answer = "Vi tittar på taket.\nPriset & <storlek> står i offerten.";
$beforeAns = count($GLOBALS['_SENT']);
$plainAgain = jeab_offer_send_malmo([
    'to' => 'nora.utan@example.com',
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 1,
]);
$blank = jeab_offer_send_malmo([
    'to' => 'nora.utan@example.com',
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 1,
    'answer' => '   ',
]);
om(($plainAgain['body_preview'] ?? '') === ($blank['body_preview'] ?? '') && ($plainAgain['answer_included'] ?? true) === false && ($blank['answer_included'] ?? true) === false && ($plainAgain['prices'] ?? null) === jeab_offer_malmo_price_rows(), 'utan answer är offerten och prisraderna oförändrade');
$ansDry = jeab_offer_send_malmo([
    'to' => $ansTo,
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 1,
    'answer' => '  ' . $answer . '  ',
]);
$ansPreview = (string) ($ansDry['preview'] ?? '');
$hej = mb_strpos($ansPreview, 'Hej Nora,');
$ansAt = mb_strpos($ansPreview, 'Vi tittar på taket.');
$priceAtPreview = mb_strpos($ansPreview, '39 500');
om(($ansDry['ok'] ?? false) === true && ($ansDry['answer_included'] ?? false) === true && ($ansDry['to'] ?? '') === $ansTo && ($ansDry['from'] ?? '') === 'saljare.syd@malmo.example.test' && ($ansDry['subject'] ?? '') === 'Offert batteri' && ($ansDry['template'] ?? '') === 'offert-batteri-malmo' && ($ansDry['attachment'] ?? '') === jeab_auto_pdf_name('batteri_malmo') && ($ansDry['prices'] ?? null) === jeab_offer_malmo_price_rows() && $hej !== false && $ansAt !== false && $priceAtPreview !== false && $hej < $ansAt && $ansAt < $priceAtPreview && count($GLOBALS['_SENT']) === $beforeAns, 'answer ligger före mallen och dry visar mottagaren');
$too = jeab_offer_send_malmo([
    'to' => $ansTo,
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 0,
    'answer' => str_repeat('å', 501),
]);
om(($too['ok'] ?? true) === false && ($too['error'] ?? '') === 'answer är längre än 500 tecken' && count($GLOBALS['_SENT']) === $beforeAns, 'answer över 500 tecken nekas och skickar inget');
$liveAns = jeab_offer_send_malmo([
    'to' => $ansTo,
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 0,
    'answer' => $answer,
]);
$liveSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$liveBody = (string) ($liveSent['body'] ?? '');
$esc = 'Vi tittar på taket.<br>Priset &amp; &lt;storlek&gt; står i offerten.';
$escAt = strpos($liveBody, $esc);
$priceAt = strpos($liveBody, '39 500');
om(($liveAns['ok'] ?? false) === true && ($liveAns['sent'] ?? 0) === 1 && ($liveAns['to'] ?? '') === $ansTo && ($liveAns['from'] ?? '') === 'saljare.syd@malmo.example.test' && ($liveAns['answer_included'] ?? false) === true && ($liveAns['prices'] ?? null) === jeab_offer_malmo_price_rows() && $escAt !== false && $priceAt !== false && $escAt < $priceAt && !str_contains($liveBody, 'Priset & <storlek>'), 'utsänd answer är escapad och står före prisraderna');
om_neo_prices($liveBody, 'utsänd med answer');
$fit = str_repeat('å', 500);
$fitSend = jeab_offer_send_malmo([
    'to' => 'nora.fit@example.com',
    'product' => 'batteri',
    'customer_name' => 'Nora Test',
    'dry' => 0,
    'answer' => $fit,
]);
$fitBody = (string) (($GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1]['body'] ?? ''));
om(($fitSend['ok'] ?? false) === true && str_contains($fitBody, $fit) && !str_contains($fitBody, $fit . 'å'), 'answer på 500 tecken följer med och kapas inte');

echo "offer.send_malmo: ok\n";
