<?php

declare(strict_types=1);

/** Fritext, hållning, påminnelse med mail, Väntar på offert, papperskorg. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-quote-hold-' . bin2hex(random_bytes(4));
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

function qh(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = static function (array $messages): string {
    $user = (string) ($messages[1]['content'] ?? '');
    if (str_contains($user, '18000') || str_contains($user, 'Årsförbrukning') || str_contains($user, 'kvadratmeter')) {
        return json_encode(['answer' => 'DET HÄR SKA INTE SVARAS'], JSON_UNESCAPED_UNICODE);
    }
    return json_encode(['answer' => 'Ja, det tar vi med i offerten.'], JSON_UNESCAPED_UNICODE);
};
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
$GLOBALS['_CLS'] = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => ''];
$GLOBALS['_JEAB_INKORG_LLM'] = fn() => json_encode($GLOBALS['_CLS']);
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_hold_' . bin2hex(random_bytes(4))]));

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed() + ['batteri_malmo' => "Hej\n\nMalmö batteri.\n", 'laddbox_malmo' => "Hej\n\nMalmö laddbox.\n"],
]);
foreach (['batteri_jarfalla', 'sol_jarfalla', 'laddbox_jarfalla', 'batteri_malmo', 'laddbox_malmo'] as $key) {
    jeab_auto_pdf_save(['key' => $key, 'data' => base64_encode('%PDF-1.4 ' . $key)]);
}
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\n" . jeab_offer_batteri_neo_price_paragraph(), 'test');
jeab_notiser_save_body('offert-batteri-malmo', "Hej\n\nMalmö batteri.\n", 'test');
jeab_notiser_save_body('offert-laddbox-jarfalla', "Hej\n\nOffert elbilsladdare.", 'test');
jeab_notiser_save_body('offert-laddbox-malmo', "Hej\n\nMalmö laddbox.\n", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-eget', "Hej\n\nSol eget.", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-kopt', "Hej\n\nSol köpt.", 'test');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = function (string $id, string $from, string $body, string $offer = 'batteri', array $extra = []) use ($box): array {
    $company = ($extra['company'] ?? '') === 'malmo' ? 'malmo' : 'jarfalla';
    $labels = [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
    ];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@hold.test',
        'from' => $from,
        'from_name' => (string) ($extra['from_name'] ?? 'Formulär'),
        'to' => jeab_graph_mailboxes()[$company],
        'subject' => (string) ($extra['subject'] ?? 'Offert'),
        'body_text' => $body,
        'source' => (string) ($extra['source'] ?? ('graph-' . $company)),
        'graph_mailbox' => jeab_graph_mailboxes()[$company],
        'thread_key' => 'hold-' . $id,
        'preset_labels' => array_merge($labels, (array) ($extra['labels'] ?? [])),
    ]);
};

$usage = "Typ av fastighet: Villa\nÅrlig förbrukning: 14000 kWh\nÅrsförbrukning: 18000 kWh\nTakyta: 80\nTaktyp: Sadeltak\nTakmaterial: Tegel\nLutning: 27\nVäderstreck: Syd\nSäkring: 20A\nHuvudsäkring: 25A\nNär vill de installera: I vår\nPrimärt skäl: Spara\nÖnskad kontakttid: Kväll\nPaneler: 20\nPå tak: Ja\nAntal: 20\nElbolag: Vattenfall\nNätägare: Ellevio\nBudget: 200000\n";
$person = "Namn: Ada Holm\nE-post: ada.holm@gmail.com\nTelefon: 070-100 20 01\nAdress: Björkvägen 1, 176 70 Järfälla\n";

qh(jeab_auto_substantive_question($usage . $person) === '', 'formulärfält med kWh är ingen fråga');
qh(jeab_auto_substantive_question("Meddelande:\n" . $usage) === '', 'kWh inuti meddelandefältet som bara är formulärfält ignoreras');
qh(jeab_auto_customer_freetext("Meddelande:\nTaket är 40 kvadratmeter.\n" . $usage) === 'Taket är 40 kvadratmeter.', 'fritexten behåller meningen och tappar fälten');
qh(jeab_auto_substantive_question("Meddelande:\nTaket är 40 kvadratmeter.") === '', 'kvadratmeter utan fråga besvaras inte');
qh(jeab_auto_substantive_question("Vi vill ha 10 kWh i garaget.") !== '', 'riktig fritext med kWh besvaras');
qh(jeab_auto_substantive_question("Hur många paneler får plats?") === '', 'paneler i en mening äts inte som fält');
qh(str_contains(jeab_auto_customer_freetext($person . "Hur många paneler får plats?\n"), 'Hur många paneler får plats?'), 'frågan ligger kvar i fritexten');

$structures = [
    'drip' => ["notifications@drip.com", "Källa: Hitta-Solceller via Drip\n" . $person . $usage],
    'hemsol' => ['lead@hemsol.se', $person . "Produkt: Batteri\n" . $usage],
    'weply' => ['noreply@weply.se', $person . "\nHej, jag vill ha offert på solceller.\n\nAda Holm\n"],
    'wix' => ['info@wix-forms.com', "A site visitor just submitted your form\n\n" . $person . "Meddelande:\n\n"],
    'kalkyl' => ['form@kalkyl.jarfalla.example.test', $person . $usage],
];
foreach ($structures as $name => [$from, $body]) {
    $clean = jeab_inkorg_clean_message(['from' => $from, 'body' => $body]);
    qh(jeab_auto_substantive_question($clean) === '', $name . ' utan fråga ger inget svar');
    $with = $body . "\nMeddelande:\nFår 10 kWh plats i garaget?\n";
    if ($name === 'weply') {
        $with = $person . "\nVad kostar batteri med 10 kWh?\n\nAda Holm\n";
    }
    if ($name === 'wix') {
        $with = "A site visitor just submitted your form\n\n" . $person . "Meddelande:\n\nFår 10 kWh plats i garaget?\n";
    }
    $cleanQ = jeab_inkorg_clean_message(['from' => $from, 'body' => $with]);
    $q = jeab_auto_substantive_question($cleanQ);
    qh($q !== '' && !str_contains($q, '18000') && !str_contains($q, 'Vattenfall'), $name . ' med fråga tar bara fritexten');
}

$before = count($GLOBALS['_SENT']);
$plain = $ingest('plain', 'info@wix-forms.com', $person . $usage, 'batteri');
$plainDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $plain['thread_id'], 'dry' => 1]);
qh(($plainDry['ok'] ?? false) === true && !str_contains((string) ($plainDry['body'] ?? ''), 'DET HÄR SKA INTE SVARAS') && !str_contains((string) ($plainDry['body'] ?? ''), 'Ja, det tar vi med'), 'förhandsvisning utan fråga har inget påhittat svar');
$ask = $ingest('ask', 'info@wix-forms.com', $person . "Meddelande:\nFår 10 kWh plats i garaget?\n" . $usage, 'batteri', ['labels' => ['question' => '']]);
$askDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $ask['thread_id'], 'dry' => 1]);
$askBody = (string) ($askDry['body'] ?? '');
$ansAt = strpos($askBody, 'Ja, det tar vi med i offerten.');
$tplAt = strpos($askBody, 'Den minsta investeringen');
qh(($askDry['ok'] ?? false) === true && $ansAt !== false && $tplAt !== false && $ansAt < $tplAt && str_contains($askBody, 'Hej Ada') && !str_contains($askBody, '18000'), 'förhandsvisning med fråga har svaret före mallen');
qh(count($GLOBALS['_SENT']) === $before, 'förhandsvisningarna skickade inget');

$heldIn = $ingest('held', 'ada.held@gmail.com', $person . "E-post: ada.held@gmail.com\n" . $usage, 'batteri');
$held = jeab_auto_on_message((string) $heldIn['id']);
qh(($held['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'avstängt utskick stoppar inte offerten, fick ' . (string) ($held['action'] ?? '') . ' ' . (string) ($held['why'] ?? ''));
$heldThread = jeab_auto_thread_by_id((string) $heldIn['thread_id']);
$draftSources = array_map(fn($d) => (string) ($d['source'] ?? ''), (array) ($heldThread['drafts'] ?? []));
qh(!in_array('lead-offert', $draftSources, true), 'inget offertutkast sparas');
$heldNote = '';
$heldRem = null;
foreach ((array) ($heldThread['drafts'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'note') {
        $heldNote .= (string) ($d['body'] ?? '') . "\n";
    }
}
foreach (jeab_list_reminders(jeab_store(), true) as $r) {
    if (mb_strtolower((string) ($r['email'] ?? '')) === 'ada.held@gmail.com') {
        $heldRem = $r;
    }
}
$mailLine = 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode((string) $heldIn['thread_id']);
qh(str_contains($heldNote, 'Offert') && is_array($heldRem) && str_contains((string) $heldRem['note'], $mailLine) && (string) ($heldRem['who'] ?? $heldRem['assignee'] ?? '') !== '', 'påminnelsen har mail och ägare');
qh(substr_count((string) $heldRem['note'], $mailLine) === 1, 'mail-raden finns en gång');

$sentAfterHold = count($GLOBALS['_SENT']);
$on = jeab_inkorg_hook(['action' => 'inbox.quote_enabled', 'auto_quote_enabled' => true]);
qh(($on['ok'] ?? false) === true && jeab_auto_settings()['quote_enabled'] === true && count($GLOBALS['_SENT']) === $sentAfterHold, 'påslaget utskick skickar inget i efterhand');
jeab_auto_settings_save(['auto_quote_enabled' => 0, 'auto_trash_spam' => 1, 'auto_quote_only_from' => '', 'tpl' => jeab_auto_settings()['templates']]);

jeab_inkorg_views();
$view = null;
foreach (jeab_inkorg_views() as $v) {
    if (($v['id'] ?? '') === 'v-vantar-offert') {
        $view = $v;
    }
}
qh(is_array($view) && ($view['name'] ?? '') === 'Väntar på offert' && ($view['menu'] ?? false) === true && !empty($view['filter']['awaiting_quote']), 'Väntar på offert finns i menyn');
qh(!jeab_inkorg_thread_matches($heldThread, (array) $view['filter']), 'hållet lead med påminnelse ligger inte i öppna vyn');
$closed = $heldThread;
$closed['status'] = 'closed';
qh(!jeab_inkorg_thread_matches($closed, (array) $view['filter']), 'stängt ärende ligger inte i vyn');

$old = jeab_create_reminder(jeab_store(), 'Ada Holm – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Ada Holm', 'email' => 'ada.reuse@gmail.com', 'company' => 'jarfalla', 'who' => 'Roberto',
    'note' => 'mail: https://flowstack.example.test/index.php?p=inkorg&id=old-case',
]);
$reuse = $ingest('reuse', 'ada.reuse@gmail.com', "Namn: Ada Holm\nE-post: ada.reuse@gmail.com\nTelefon: 070-100 20 02\nAdress: Björkvägen 2, 176 70 Järfälla\n", 'laddbox');
jeab_lead_process(jeab_auto_thread_by_id((string) $reuse['thread_id']), true);
$again = jeab_lead_process(jeab_auto_thread_by_id((string) $reuse['thread_id']), true);
$reloaded = jeab_get_reminder(jeab_store(), (int) $old['id']);
$reuseUrl = 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode((string) $reuse['thread_id']);
qh(is_array($reloaded) && str_contains((string) $reloaded['note'], 'id=old-case') && !str_contains((string) $reloaded['note'], $reuseUrl) && (string) ($reloaded['thread_id'] ?? '') === (string) $reuse['thread_id'] && (int) ($again['reminder'] ?? 0) === (int) $old['id'], 'återanvänd påminnelse kopplas via ärendet utan en ny mail-rad');

$bare = jeab_create_reminder(jeab_store(), 'Bea Länk – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Bea Länk', 'email' => 'bea.link@gmail.com', 'company' => 'jarfalla', 'who' => 'Roberto', 'note' => 'Ring kunden.',
]);
$linkThread = $ingest('link', 'bea.link@gmail.com', "Namn: Bea Länk\nE-post: bea.link@gmail.com\nTelefon: 070-100 20 09\nAdress: Länkvägen 1, 176 70 Järfälla\n", 'batteri');
$dryLinks = jeab_inkorg_hook(['action' => 'inkorg.mail_links']);
$bareAfterDry = jeab_get_reminder(jeab_store(), (int) $bare['id']);
qh(($dryLinks['dry'] ?? 0) === 1 && ($dryLinks['changed'] ?? 0) >= 1 && (string) ($bareAfterDry['note'] ?? '') === 'Ring kunden.' && (string) ($bareAfterDry['title'] ?? '') === (string) $bare['title'], 'mail_links är torr som standard och rör ingen text');
$applyLinks = jeab_inkorg_hook(['action' => 'inkorg.mail_links', 'dry' => 0]);
$bareAfter = jeab_get_reminder(jeab_store(), (int) $bare['id']);
$linkUrl = 'mail: https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode((string) $linkThread['thread_id']);
qh(($applyLinks['dry'] ?? 1) === 0 && str_contains((string) ($bareAfter['note'] ?? ''), $linkUrl) && str_contains((string) ($bareAfter['note'] ?? ''), 'Ring kunden.') && (string) ($bareAfter['email'] ?? '') === 'bea.link@gmail.com' && (string) ($bareAfter['title'] ?? '') === (string) $bare['title'], 'mail_links lägger bara till mail-raden');
$onceMore = jeab_inkorg_hook(['action' => 'inkorg.mail_links', 'dry' => '0']);
$bareFinal = jeab_get_reminder(jeab_store(), (int) $bare['id']);
qh(substr_count((string) ($bareFinal['note'] ?? ''), $linkUrl) === 1 && ($onceMore['changed'] ?? 1) === 0, 'samma mail-rad läggs inte till igen');
qh(!str_contains(json_encode($applyLinks), 'bea.link@gmail.com'), 'hooksvaret innehåller ingen e-post');

$GLOBALS['_CLS'] = ['type' => 'Skräp', 'action' => 'radera', 'priority' => 4, 'confidence' => 96, 'intent' => 'skrap', 'offer' => '', 'product' => '', 'question' => ''];
$junkLead = $ingest('junk-lead', 'lead@hemsol.se', "Namn: Cilla Batteri\nE-post: cilla.batt@gmail.com\nTelefon: 070-100 20 04\nAdress: Battvägen 4, 176 70 Järfälla\nProdukt: Batteri\nMeddelande:\n", 'batteri', ['labels' => ['type' => 'Skräp', 'intent' => 'skrap', 'offer' => '', 'product' => 'batteri'], 'subject' => 'Batteri till villan']);
$junkAuto = jeab_auto_on_message((string) $junkLead['id']);
$junkThread = jeab_auto_thread_by_id((string) $junkLead['thread_id']);
qh(($junkAuto['action'] ?? '') !== 'trashed' && ($junkAuto['action'] ?? '') !== 'quoted' && empty($junkThread['deleted']), 'HemSol med produkt åker inte i papperskorgen och får ingen offert');
qh((string) ($junkThread['first']['labels']['type'] ?? '') === 'Skräp', 'köpord byter inte Skräp till Ny lead');
$news = $ingest('news', 'news@shop.se', 'Rea på allt!', 'batteri', ['labels' => ['type' => 'Skräp', 'intent' => 'skrap', 'offer' => '', 'product' => ''], 'subject' => 'Rea!']);
$newsAuto = jeab_auto_on_message((string) $news['id']);
qh(($newsAuto['action'] ?? '') === 'historik' && ($newsAuto['sent'] ?? 1) === 0 && (jeab_inkorg_thread_meta((string) $news['thread_id'])['status'] ?? '') === 'closed' && empty(jeab_inkorg_thread_meta((string) $news['thread_id'])['deleted_at']), 'nyhetsbrev utan produkt går till Historik');

$trashList = jeab_inkorg_hook(['action' => 'inkorg.trash_leads']);
$trashIds = array_column((array) ($trashList['leads'] ?? []), 'thread');
qh(!in_array((string) $junkLead['thread_id'], $trashIds, true), 'skyddat lead listas inte förrän någon flyttat det');
$_POST = ['thread' => (string) $junkLead['thread_id']];
$threw = false;
try {
    jeab_inkorg_action('inkorg.trash');
} catch (InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'bekräftelse');
}
qh($threw && empty(jeab_auto_thread_by_id((string) $junkLead['thread_id'])['deleted']), 'manuell radering utan bekräftelse stoppas');
$_POST = ['thread' => (string) $junkLead['thread_id'], 'confirm_lead' => '1'];
jeab_inkorg_action('inkorg.trash');
$trashed = jeab_auto_thread_by_id((string) $junkLead['thread_id']);
$trashNote = '';
foreach ((array) ($trashed['drafts'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'note') {
        $trashNote .= (string) ($d['body'] ?? '') . "\n";
    }
}
qh(!empty($trashed['deleted']) && str_contains($trashNote, 'flyttade leadet till papperskorgen') && str_contains($trashNote, date('Y-m-d')), 'bekräftad radering loggar vem och när');
$trashList = jeab_inkorg_hook(['action' => 'inkorg.trash_leads']);
$listed = null;
foreach ((array) ($trashList['leads'] ?? []) as $row) {
    if (($row['thread'] ?? '') === (string) $junkLead['thread_id']) {
        $listed = $row;
    }
}
qh(is_array($listed) && ($listed['offer'] ?? '') === 'batteri' && ($listed['deleted_by'] ?? '') !== '' && !str_contains(json_encode($trashList), 'cilla.batt@gmail.com'), 'trash_leads listar produktleadet utan kundtext');

$import = $ingest('import', 'import.kund@gmail.com', $person . 'E-post: import.kund@gmail.com', 'sol', ['source' => 'helpscout_import', 'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol']]);
$importAuto = jeab_auto_on_message((string) $import['id']);
qh(($importAuto['action'] ?? '') === 'skip' && str_contains((string) ($importAuto['why'] ?? ''), 'importerat') && count($GLOBALS['_SENT']) === $before, 'importerat ärende triggar inget');
$importThread = jeab_auto_thread_by_id((string) $import['thread_id']);
qh(is_array($importThread) && !jeab_inkorg_thread_matches($importThread, (array) $view['filter']), 'importerat ärende ligger inte i Väntar på offert');

$southBatt = $ingest('south-batt', 'south.batt@gmail.com', "Namn: Siv Batteri\nE-post: south.batt@gmail.com\nTelefon: 070-100 20 05\nAdress: Storgatan 1, 211 22 Malmö\n", 'batteri');
$southBattDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $southBatt['thread_id'], 'dry' => 1]);
qh(($southBattDry['action'] ?? '') === 'hold' && ($southBattDry['why'] ?? '') === 'bolaget hanteras i Help Scout' && count($GLOBALS['_SENT']) === $before, 'batteri med Malmö i formuläret är Malmö och skrivs inte');
$southSol = $ingest('south-sol', 'south.sol@gmail.com', "Namn: Siv Sol\nE-post: south.sol@gmail.com\nTelefon: 070-100 20 06\nAdress: Storgatan 2, 211 22 Malmö\n", 'sol');
$southSolDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $southSol['thread_id'], 'dry' => 1]);
qh(($southSolDry['action'] ?? '') === 'hold' && ($southSolDry['why'] ?? '') === 'bolaget hanteras i Help Scout' && count($GLOBALS['_SENT']) === $before, 'sol med Malmö i formuläret är Malmö och skrivs inte');

jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
$live = $ingest('malmo-live', 'kim.kund@gmail.com', "Namn: Kim Kund\nE-post: kim.kund@gmail.com\nTelefon: 070-100 20 07\nAdress: Testgatan 3, 211 20 Malmö\n", 'batteri', ['company' => 'malmo']);
$liveDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $live['thread_id'], 'dry' => 1]);
qh(jeab_gp_owner_email('batteri', 'malmo') === 'saljare.syd@malmo.example.test' && jeab_gp_owner_email('sol', 'malmo') === '' && ($liveDry['sender'] ?? '') === 'saljare.syd@malmo.example.test' && ($liveDry['ok'] ?? false) === true && count($GLOBALS['_SENT']) === $before, 'påslagen Malmö skickar batteri från Kim och håller sol');
$liveSol = $ingest('malmo-sol', 'sol.kund@gmail.com', "Namn: Sol Kund\nE-post: sol.kund@gmail.com\nAdress: Testgatan 4, 211 20 Malmö\n", 'sol', ['company' => 'malmo']);
$liveSolDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $liveSol['thread_id'], 'dry' => 1]);
qh(($liveSolDry['why'] ?? '') === 'Malmö/Skåne' && count($GLOBALS['_SENT']) === $before, 'sol i påslagen Malmö hålls');

$graphCalls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;
    return ['code' => 200, 'body' => ['value' => []]];
};
$nora = $ingest('nora', 'nora.vantar@gmail.com', "Namn: Nora Väntar\nE-post: nora.vantar@gmail.com\nTelefon: 070-100 20 11\nAdress: Väntevägen 1, 176 70 Järfälla\n", 'batteri');
$noraThread = jeab_auto_thread_by_id((string) $nora['thread_id']);
qh(is_array($noraThread) && jeab_inkorg_thread_matches($noraThread, (array) $view['filter']) && $graphCalls === 0, 'olett lead ligger i vyn utan Graph');
$pim = $ingest('pim', 'pim.vantar@gmail.com', "Namn: Pim Väntar\nE-post: pim.vantar@gmail.com\nTelefon: 070-100 20 12\nAdress: Väntevägen 2, 176 70 Järfälla\n", 'batteri');
$pimThread = jeab_auto_thread_by_id((string) $pim['thread_id']);
qh(is_array($pimThread) && jeab_inkorg_thread_matches($pimThread, (array) $view['filter']), 'andra olett lead ligger också i vyn');
jeab_inkorg_ingest([
    'message_id' => 'out-nora@hold.test',
    'from' => 'saljare3@jarfalla.example.test',
    'from_name' => 'Roberto',
    'to' => 'nora.vantar@gmail.com',
    'subject' => 'Offert batteri',
    'body_text' => 'Hej Nora, offerten kommer här.',
    'source' => 'outbound-graph',
    'received_at' => date('c'),
    'thread_key' => 'hold-out-nora',
    'preset_labels' => ['type' => 'Övrigt', 'action' => 'arkiv', 'priority' => 4, 'confidence' => 90, 'intent' => '', 'offer' => '', 'product' => ''],
]);
$noraAfter = jeab_auto_thread_by_id((string) $nora['thread_id']);
$pimAfter = jeab_auto_thread_by_id((string) $pim['thread_id']);
qh(is_array($noraAfter) && !jeab_inkorg_thread_matches($noraAfter, (array) $view['filter']) && is_array($pimAfter) && jeab_inkorg_thread_matches($pimAfter, (array) $view['filter']) && $graphCalls === 0, 'utgående mejl i inkorgen tar bara det leadet ur vyn');

$olle = $ingest('olle', 'olle.markerad@gmail.com', "Namn: Olle Markerad\nE-post: olle.markerad@gmail.com\nTelefon: 070-100 20 13\nAdress: Väntevägen 3, 176 70 Järfälla\n", 'batteri');
$olleBefore = jeab_auto_thread_by_id((string) $olle['thread_id']);
qh(is_array($olleBefore) && jeab_inkorg_thread_matches($olleBefore, (array) $view['filter']), 'lead utan träff ligger kvar inför omkollen');
$callsBefore = $graphCalls;
$dryRecheck = jeab_inkorg_hook(['action' => 'inbox.waiting_recheck']);
$dryJson = (string) json_encode($dryRecheck, JSON_UNESCAPED_UNICODE);
$olleMetaDry = jeab_inkorg_thread_meta((string) $olle['thread_id']);
qh(($dryRecheck['ok'] ?? false) === true && ($dryRecheck['dry'] ?? 0) === 1 && ($dryRecheck['checked'] ?? 0) >= 1 && ($olleMetaDry['quote_sent'] ?? []) === [] && jeab_inkorg_thread_matches(jeab_auto_thread_by_id((string) $olle['thread_id']), (array) $view['filter']) && !str_contains($dryJson, '@'), 'torr omkoll skriver inget och svaret saknar adresser');
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$graphCalls): array {
    $graphCalls++;
    if (str_contains($url, 'sentDateTime')) {
        return ['code' => 200, 'body' => ['value' => [[
            'sentDateTime' => '2026-10-16T08:00:00Z',
            'toRecipients' => [['emailAddress' => ['address' => 'olle.markerad@gmail.com']]],
            'ccRecipients' => [],
        ]]]];
    }
    if (str_contains($url, 'search')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 404, 'body' => []];
};
unset($GLOBALS['_JEAB_GRAPH_SCAN_MEM']);
$liveRecheck = jeab_inkorg_hook(['action' => 'inbox.waiting_recheck', 'dry' => 0]);
$liveJson = (string) json_encode($liveRecheck, JSON_UNESCAPED_UNICODE);
$olleMeta = jeab_inkorg_thread_meta((string) $olle['thread_id']);
$olleMarked = jeab_auto_thread_by_id((string) $olle['thread_id']);
$hitThreads = array_column((array) ($liveRecheck['hits'] ?? []), 'thread');
qh(($liveRecheck['dry'] ?? 1) === 0 && in_array((string) $olle['thread_id'], $hitThreads, true) && ($olleMeta['quote_sent']['source'] ?? '') === 'Skickat' && ($olleMeta['quote_sent']['date'] ?? '') === '2026-10-16 10:00' && !isset($olleMeta['quote_sent']['why']) && is_array($olleMarked) && !jeab_inkorg_thread_matches($olleMarked, (array) $view['filter']) && $graphCalls > $callsBefore && !str_contains($liveJson, '@') && !str_contains($liveJson, 'olle.markerad'), 'omkoll med dry=0 markerar Skickat och tar leadet ur vyn');
$pimStill = jeab_auto_thread_by_id((string) $pim['thread_id']);
qh(is_array($pimStill) && jeab_inkorg_thread_matches($pimStill, (array) $view['filter']), 'lead utan träff ligger kvar efter omkollen');

echo "OK  quote-hold\n";
