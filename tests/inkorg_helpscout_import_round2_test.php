<?php

declare(strict_types=1);

/**
 * Help Scout-import, omgång 2. Fejkade kontakter och ärenden. Ingen riktig nyckel och inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-hs2-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [
        ['id' => 'PersonalExempel', 'email' => 'personal.exempel@personal.post', 'name' => 'Personal Exempel', 'company' => 'jarfalla', 'phone' => '0701000000'],
    ],
], true) . ";\n");
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client',
    'client_secret' => 'hs-test-secret',
    'mailbox_id' => 111,
    'mailbox_id_malmo' => 222,
    'import_supplier_domains' => ['kgk.se'],
    'import_keep_domains' => ['post.se'],
    'import_supplier_domain_min' => 6,
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function r2(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function r2_plan(array $hint): array
{
    return jeab_hs_import_contact_plan($hint);
}

$calls = 0;
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function () use (&$calls): array {
    $calls++;
    return ['code' => 500, 'headers' => [], 'body' => ''];
};

$kgk = r2_plan(['name' => 'KGK Support', 'email' => 'lev@post.se', 'phone' => '0701000001', 'street' => 'Lagervägen 1', 'city' => 'Exempelstad']);
r2($kgk['action'] === 'skip' && $kgk['id'] === '', 'KGK Support blir ingen kontakt');
$auto = r2_plan(['name' => 'AutoConcept', 'email' => 'faktura@post.se', 'street' => 'Industrigatan 2']);
r2($auto['action'] === 'skip', 'AutoConcept blir ingen kontakt');
$awm = r2_plan(['name' => 'AWM Network Invoice', 'email' => 'ekonomi@post.se']);
r2($awm['action'] === 'skip', 'AWM Network Invoice blir ingen kontakt');
$domain = r2_plan(['name' => 'Alias Lev', 'email' => 'lev@kgk.se', 'street' => 'Kundgatan 3', 'phone' => '0701000002']);
r2($domain['action'] === 'skip', 'konfigurerad leverantörsdomän blir ingen kontakt');
$noreply = r2_plan(['name' => 'Order', 'email' => 'no-reply@butik.post', 'phone' => '0701000003']);
r2($noreply['action'] === 'skip', 'no-reply i adressen blir ingen kontakt');
$noreplyName = r2_plan(['name' => 'DoNotReply Kund', 'email' => 'kund@post.se', 'phone' => '0701000004']);
r2($noreplyName['action'] === 'skip', 'no-reply i namnet blir ingen kontakt');
foreach (['TEST TEST', 'namn', 'Sdfg', 'hjjkh', 'test22 sdg'] as $junk) {
    $plan = r2_plan(['name' => $junk, 'email' => 'verklig@post.se', 'phone' => '0701000005', 'street' => 'Riktigagatan 4']);
    r2($plan['action'] === 'skip', 'skräpnamn ' . $junk . ' blir ingen kontakt');
}

$quoted = r2_plan(['name' => "'Alias Exempel'", 'email' => 'alias.exempel@post.se', 'street' => 'Exempelgatan 5', 'city' => 'Exempelstad', 'postal_code' => '12345']);
r2($quoted['action'] === 'create' && $quoted['name'] === 'Alias Exempel', 'citat runt namnet skalas av');
$flipped = r2_plan(['name' => 'Exempelnamn, Alias', 'email' => 'alias.flip@post.se', 'street' => 'Exempelgatan 1']);
r2($flipped['action'] === 'create' && $flipped['name'] === 'Alias Exempelnamn', 'Efternamn, Förnamn vänds');

$localBody = "Hej\n\nJag vill ha en laddbox.\n\nVänliga hälsningar\nAnna Exempel\n";
$local = r2_plan(['name' => 'kund42', 'email' => 'anna.exempel@post.se', 'body' => $localBody, 'street' => 'Exempelgatan 2']);
r2($local['action'] === 'create' && $local['name'] === 'Anna Exempel' && $local['shown'] === 'Anna Exempel', 'local-del byts mot signaturen');
$digits = r2_plan(['name' => 'kund42', 'email' => 'kund42@post.se']);
r2($digits['action'] === 'skip' && $digits['shown'] === '', 'siffror i namnet och ingen kanal skapar ingen kontakt');
$digitsPhone = r2_plan(['name' => 'kund42', 'email' => 'annan@post.se', 'phone' => '0701000099']);
r2($digitsPhone['action'] === 'create' && $digitsPhone['name'] === '', 'siffror i namnet lämnar namnet tomt när telefon finns');

$cp = ['customers' => [], 'mailboxes' => []];
$first = jeab_hs_import_contact_apply(['name' => 'Namn Sparat', 'email' => 'alias.delad@post.se', 'street' => 'Exempelgatan 1', 'city' => 'Exempelstad'], '1', false, $cp);
$second = jeab_hs_import_contact_apply(['name' => 'Namn Sparat', 'email' => 'alias.delad@post.se', 'street' => 'Exempelgatan 9', 'city' => 'Exempelstad'], '2', false, $cp);
r2($first['action'] === 'create' && $second['action'] === 'merge' && $first['id'] === $second['id'], 'samma e-post är en kontakt');
r2($calls === 0, 'kontaktfiltret anropar inte Help Scout');

function r2_store(string $n, string $company, string $status, string $email, string $name, string $subject, string $body, string $contactId = ''): string
{
    $tid = 'hsi-' . substr(hash('sha256', $n), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => $company,
        'status' => $status,
        'import_source' => 'helpscout_import',
        'contact_id' => $contactId,
        'messages' => [[
            'id' => substr(hash('sha256', 'm' . $n), 0, 16),
            'from' => $email,
            'from_name' => $name,
            'to' => 'kontakt@' . ($company === 'malmo' ? 'malmo.example.test' : 'jarfalla.example.test'),
            'subject' => $subject,
            'body' => $body,
            'received_at' => '2021-09-28T08:41:00+00:00',
            'source' => 'helpscout_import',
            'company' => $company,
            'labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4],
            'internal_note' => false,
            'attachments' => [],
        ]],
        'meta' => [
            'status' => $status,
            'tags' => [],
            'assignee' => '',
            'read' => [],
            'contact_id' => $contactId,
            'import_source' => 'helpscout_import',
        ],
    ];
    r2(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'ärende ' . $n . ' skrivs');
    return $tid;
}

$quote = "Hej, taket är klart.\n\n28 sep. 2021 kl. 10:41 skrev Kontakt <gammal@post.se>:\n> Den gamla texten ska inte synas\n";
$openId = r2_store('open', 'jarfalla', 'open', 'alias.exempel@post.se', 'Alias Exempel', 'Batteri till villan', $quote, (string) $first['id']);
$closedId = r2_store('closed', 'jarfalla', 'closed', 'stangd@post.se', 'Stängd Kund', 'Stängt ärende alfa', 'Klart sedan länge.');
$waitId = r2_store('wait', 'jarfalla', 'waiting_customer', 'vantar@post.se', 'Väntar Kund', 'Väntar på svar', 'Hör av er.');
$malmoId = r2_store('malmo', 'malmo', 'closed', 'malmo.kund@post.se', 'Malmö Kund', 'Malmö historikpump', 'Pumpen är bytt.');
$junkRow = jeab_kontakt_blank(['name' => 'KGK Support', 'email' => 'lev@kgk.se', 'street' => 'Lagervägen 1', 'street_key' => 'lagervagen 1', 'source' => 'hs-import']);
$mergeA = jeab_kontakt_blank(['name' => 'Namn Sparat', 'email' => 'alias.samma@post.se', 'street' => 'Exempelgatan 1', 'street_key' => 'exempelgatan 1']);
$mergeB = jeab_kontakt_blank(['name' => 'Namn Sparat', 'email' => 'alias.samma@post.se', 'street' => 'Exempelgatan 9', 'street_key' => 'exempelgatan 9']);
jeab_kontakter_mutate(function (array &$contacts) use ($junkRow, $mergeA, $mergeB): void {
    $contacts[(string) $junkRow['id']] = $junkRow;
    $contacts[(string) $mergeA['id']] = $mergeA;
    $contacts[(string) $mergeB['id']] = $mergeB;
});
$junkId = r2_store('junk', 'jarfalla', 'closed', 'lev@kgk.se', 'KGK Support', 'Faktura 100', 'Se bifogad faktura.', (string) $junkRow['id']);
$mergeLeft = r2_store('merge-a', 'jarfalla', 'closed', 'alias.samma@post.se', 'Namn Sparat', 'Första post', 'Hej från posten.', (string) $mergeA['id']);
$mergeRight = r2_store('merge-b', 'jarfalla', 'closed', 'alias.samma@post.se', 'Namn Sparat', 'Andra post', 'Hej igen.', (string) $mergeB['id']);
for ($i = 1; $i <= 60; $i++) {
    r2_store('page' . $i, 'jarfalla', 'closed', 'sida' . $i . '@post.se', 'Sida ' . $i, 'Historiksida ' . $i, 'Rad ' . $i);
}
for ($i = 1; $i <= 6; $i++) {
    r2_store('vendor' . $i, 'jarfalla', 'closed', 'order' . $i . '@lev.post', 'Lev Post', 'Orderämne ' . $i, 'Leverans ' . $i);
}

$clean = jeab_inkorg_clean_message(['from' => 'alias.exempel@post.se', 'body' => $quote]);
r2(str_contains($clean, 'taket är klart') && !str_contains($clean, 'gamla texten') && !str_contains($clean, 'skrev Kontakt'), 'svensk citatrubrik klipps bort');
r2(str_contains($quote, 'gamla texten'), 'originalet finns kvar i källtexten');
$den = jeab_inkorg_clean_message(['from' => 'a@post.se', 'body' => "Hej\n\nDen 28 september 2021 skrev Alias <a@post.se>:\nGammal rad"]);
r2(str_contains($den, 'Hej') && !str_contains($den, 'Gammal rad'), 'Den … skrev klipps');
$wrote = jeab_inkorg_clean_message(['from' => 'a@post.se', 'body' => "Thanks\n\nOn 28 Sep 2021, Alias wrote:\nOld line"]);
r2(str_contains($wrote, 'Thanks') && !str_contains($wrote, 'Old line'), 'On … wrote klipps');
$outlook = jeab_inkorg_clean_message(['from' => 'a@post.se', 'body' => "Hej\n\n-----Ursprungligt meddelande-----\nFrån: Alias\nSkickat: den 1 jan 2020\nGammal"]);
r2(str_contains($outlook, 'Hej') && !str_contains($outlook, 'Gammal'), 'ursprungligt meddelande klipps');
$lead = jeab_inkorg_clean_message(['from' => 'a@post.se', 'body' => "Från: Alias\nTelefon: 0701\nHej jag vill ha solceller"]);
r2(str_contains($lead, 'Från: Alias') && str_contains($lead, 'solceller'), 'formulärets Från lämnas kvar');
$tech = jeab_inkorg_clean_message(['from' => 'a@post.se', 'body' => "Den tekniker som skrev rapporten kommer imorgon"]);
r2(str_contains($tech, 'tekniker som skrev'), 'en mening med skrev lämnas kvar');

$vendor = r2_plan(['name' => 'Lev Post', 'email' => 'ny@lev.post', 'subject' => 'Ett nytt ämne', 'indexed' => true, 'street' => 'Levgatan 1', 'phone' => '0701999888']);
r2($vendor['action'] === 'skip', 'domän med fler än fem ärenden och olika ämnen blir ingen kontakt');
$gmail = r2_plan(['name' => 'Kund Gmail', 'email' => 'kund.gmail@gmail.com', 'indexed' => true, 'street' => 'Gmailgatan 1']);
r2($gmail['action'] === 'create', 'gmail auto-blockeras inte');

$hist = jeab_hs_import_query(['company' => 'jarfalla', 'historik' => true, 'q' => ''], 1, 50);
r2($hist['total'] > 50 && count($hist['rows']) === 50 && $hist['pages'] > 1, 'historiken delas i sidor från indexet');
$hist2 = jeab_hs_import_query(['company' => 'jarfalla', 'historik' => true, 'q' => ''], 2, 50);
r2(count($hist2['rows']) > 0 && $hist2['rows'][0]['id'] !== $hist['rows'][0]['id'], 'sida 2 är en annan sida');
$found = jeab_hs_import_query(['company' => 'jarfalla', 'historik' => true, 'q' => 'alias.exempel@post.se'], 1, 50);
r2(count($found['rows']) === 1 && (string) $found['rows'][0]['first']['subject'] === 'Batteri till villan', 'sök träffar e-post');
$byName = jeab_hs_import_query(['company' => 'jarfalla', 'historik' => true, 'q' => 'Alias Exempel'], 1, 50);
r2(count($byName['rows']) === 1, 'sök träffar namn');
$bySub = jeab_hs_import_query(['company' => 'jarfalla', 'historik' => true, 'q' => 'Batteri till villan'], 1, 50);
r2(count($bySub['rows']) === 1, 'sök träffar rubrik');
$openView = jeab_hs_import_count(['company' => 'jarfalla', 'status' => ['open'], 'types' => ['Information']]);
r2($openView >= 1, 'öppet importerat ärende matchar informationsvyn');
$closedView = jeab_hs_import_count(['company' => 'jarfalla', 'status' => ['closed']]);
r2($closedView === 0, 'stängt importerat ärende ligger inte kvar i en stängd arbetsvy');
r2(jeab_hs_import_count(['company' => 'jarfalla', 'historik' => true, 'q' => 'Stängt ärende alfa']) === 1, 'stängt importerat ärende ligger i Historik');
$waitView = jeab_hs_import_count(['company' => 'jarfalla', 'status' => ['waiting_customer', 'waiting_supplier']]);
r2($waitView >= 1, 'väntande importerat ärende matchar väntar');
$malmoCount = jeab_hs_import_count(['company' => 'malmo', 'historik' => true]);
r2($malmoCount === 1, 'Malmö-ärendet ligger i Malmös historik');
r2(jeab_hs_import_count(['company' => 'jarfalla', 'historik' => true, 'q' => 'Malmö historikpump']) === 0, 'Malmö syns inte i Järfällas lista');

$caseOpen = $openId;
$_GET = ['p' => 'inkorg', 'view' => 'v-historik', 'box' => 'jarfalla', 'q' => 'Batteri till villan', 'id' => $caseOpen];
$jf = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
r2(str_contains($jf, 'Batteri till villan') && str_contains($jf, 'taket är klart') && !str_contains($jf, 'Historiksida 60'), 'Järfällas lista visar träffen och inte hela historiken');
r2(str_contains($jf, 'Visa original') && str_contains($jf, 'data-orig-toggle'), 'Visa original finns');
r2(str_contains($jf, 'ix-clean') && str_contains($jf, 'ix-orig') && str_contains($jf, 'Den gamla texten ska inte synas'), 'originalet ligger bakom Visa original');
r2(!str_contains(strtolower($jf), 'help scout') && !str_contains($jf, 'helpscout.net'), 'listan nämner inte Help Scout');

$_GET = ['p' => 'inkorg', 'view' => 'v-historik', 'box' => 'jarfalla', 'sida' => '2'];
$page2 = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
r2(str_contains($page2, 'Nästa') || str_contains($page2, 'Föregående'), 'listan har sidlänkar');
r2(str_contains($page2, 'Historik'), 'Historik finns i menyn');

$_GET = ['p' => 'inkorg', 'view' => 'v-historik', 'box' => 'malmo', 'id' => $malmoId];
$mo = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
r2(jeab_inkorg_box() === 'malmo' && jeab_inkorg_box_readonly() && !jeab_inkorg_company_live('malmo'), 'Malmö är skrivskyddad historik');
r2(str_contains($mo, 'Malmö historikpump') && str_contains($mo, 'Sol hanteras i Help Scout.') && str_contains($mo, 'ink-muted') && !str_contains($mo, 'visas som historik') && !str_contains($mo, 'Inget skickas härifrån'), 'Malmös historik öppnas med en kort notis');
r2(!str_contains($mo, 'id="ix-composer"') && !str_contains($mo, 'class="ix-composer"') && !str_contains($mo, '>Skicka<') && !str_contains($mo, 'Nytt mejl') && !str_contains($mo, 'inkorg.trash'), 'Malmö har ingen skicka-knapp');
r2(str_contains($mo, 'name="status_ui"') && str_contains($mo, 'data-status-btn') && str_contains($mo, '>Parkerad</span>') && str_contains($mo, '>Stängd</span>') && str_contains($mo, '>Öppen</span>') && !str_contains($mo, 'name="assignee"'), 'Malmö har samma statusmeny som Järfälla');
r2(substr_count(strtolower($mo), 'help scout') === 1 && !str_contains($mo, 'helpscout.net'), 'Malmö-sidan nämner Help Scout bara i notisen');
r2(array_keys(jeab_graph_ingest_mailboxes()) === ['jarfalla'], 'Malmös historik slår inte på inläsning');

$threw = false;
try {
    jeab_inkorg_send_in_thread($caseOpen, ['body' => 'Hej'], 'test');
} catch (InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'skickas inte');
}
r2($threw, 'importerat ärende kan inte skickas');
$threw = false;
try {
    jeab_inkorg_send_new(['company' => 'malmo', 'to' => 'a@post.se', 'subject' => 'Hej', 'body' => 'Hej'], 'test');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
r2($threw && $calls === 0, 'Malmö skickar inget');
r2(jeab_hs_import_frozen(['import_source' => 'helpscout_import']), 'importen är fortfarande fryst för automatik');

$before = $calls;
$dry = jeab_hs_import_reprocess([]);
r2(!empty($dry['dry']) && ($dry['cases'] ?? 0) >= 60 && ($dry['contacts_cleared'] ?? 0) >= 1 && ($dry['snippets_cleaned'] ?? 0) >= 1, 'omkörning torr räknar ärenden, rensning och citat');
r2($calls === $before, 'omkörning anropar inte Help Scout');
$junkBefore = (string) (jeab_hs_import_open_thread($junkId)['contact_id'] ?? '');
r2($junkBefore === (string) $junkRow['id'], 'torrkörning skriver inte om contact_id');

$real = jeab_hs_import_reprocess(['dry' => 0]);
r2(empty($real['dry']) && ($real['contacts_cleared'] ?? 0) >= 1 && ($real['contacts_removed'] ?? 0) >= 1 && ($real['contacts_merged'] ?? 0) >= 1 && ($real['snippets_cleaned'] ?? 0) >= 1, 'omkörning på riktigt räknar borttag och ihopslagning');
r2((string) (jeab_hs_import_open_thread($junkId)['contact_id'] ?? 'x') === '', 'leverantörens ärende tappar contact_id');
r2(!isset(jeab_kontakter_load()[(string) $junkRow['id']]), 'importens leverantörskontakt tas bort');
$leftId = (string) (jeab_hs_import_open_thread($mergeLeft)['contact_id'] ?? '');
$rightId = (string) (jeab_hs_import_open_thread($mergeRight)['contact_id'] ?? '');
r2($leftId !== '' && $leftId === $rightId, 'två kontakter med samma e-post blir en');
$openContactId = (string) (jeab_hs_import_open_thread($caseOpen)['contact_id'] ?? '');
$openContact = $openContactId !== '' ? jeab_kontakt_get($openContactId) : null;
r2(is_array($openContact) && jeab_kontakt_row_email($openContact) === 'alias.exempel@post.se', 'kundens ärende behåller kontakten');
$stored = (string) json_encode(jeab_hs_import_read_conv($caseOpen), JSON_UNESCAPED_UNICODE);
r2(str_contains($stored, 'Den gamla texten ska inte synas'), 'omkörning lämnar originalet');
r2($calls === $before, 'riktig omkörning anropar inte Help Scout');
$report = json_encode($real, JSON_UNESCAPED_UNICODE);
r2(is_string($report) && !str_contains($report, '@') && !str_contains($report, 'Alias'), 'omkörningens svar är bara antal');

$again = jeab_hs_import_reprocess(['dry' => 0]);
r2(($again['contacts_removed'] ?? 1) === 0 && ($again['contacts_merged'] ?? 1) === 0, 'andra omkörningen tar inte bort mer');

$store = jeab_store();
$staffOnly = jeab_kontakt_blank(['name' => 'Personal Exempel', 'email' => 'personal.exempel@personal.post', 'street' => '', 'street_key' => '']);
$withMark = jeab_kontakt_blank(['name' => 'Personal Exempel', 'email' => 'personal.exempel@personal.post', 'street' => '', 'street_key' => '']);
$withMark['owners'][0]['personnummer'] = 'sekretess';
$realContact = jeab_kontakt_blank(['name' => 'Alias Kund', 'email' => 'alias.kund@post.se', 'street' => 'Kundgatan 8', 'street_key' => 'kundgatan 8']);
jeab_kontakter_mutate(function (array &$contacts) use ($staffOnly, $withMark, $realContact): void {
    $contacts[(string) $staffOnly['id']] = $staffOnly;
    $contacts[(string) $withMark['id']] = $withMark;
    $contacts[(string) $realContact['id']] = $realContact;
});
$event = jeab_create_reminder($store, 'Möte', jeab_today_start(), null, null, [
    'customer' => 'Möte',
    'email' => '',
    'phone' => '',
    'address' => '',
    'company' => 'jarfalla',
]);
$eventContact = jeab_kontakt_blank(['name' => 'Möte', 'email' => '', 'street' => '', 'street_key' => '']);
jeab_kontakter_mutate(function (array &$contacts) use ($eventContact): void {
    $contacts[(string) $eventContact['id']] = $eventContact;
});
$store->saveReminder((int) $event['id'], ['contact_id' => (string) $eventContact['id']]);
$keepRem = jeab_create_reminder($store, 'Kund', jeab_today_start(), null, null, [
    'customer' => 'Alias Kund',
    'email' => 'alias.kund@post.se',
    'address' => 'Kundgatan 8, Exempelstad',
    'company' => 'jarfalla',
]);
$store->saveReminder((int) $keepRem['id'], ['contact_id' => (string) $realContact['id']]);
$prune = jeab_kontakter_prune(['dry' => 1], $store);
$pruneIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), (array) ($prune['contacts'] ?? []));
r2(in_array((string) $staffOnly['id'], $pruneIds, true), 'personal utan källor föreslås till prune');
r2(!in_array((string) $eventContact['id'], $pruneIds, true), 'en påminnelse som pekar på kontakten hindrar prune');
r2(!in_array((string) $withMark['id'], $pruneIds, true), 'kontakt med personnummer lämnas kvar');
r2(!in_array((string) $realContact['id'], $pruneIds, true), 'riktig kund lämnas kvar');
$pruneJson = json_encode($prune, JSON_UNESCAPED_UNICODE);
r2(is_string($pruneJson) && !str_contains($pruneJson, 'sekretess'), 'prune-svaret innehåller inte personnummer');

$prepared = jeab_kontakt_prepare_sources([[
    'kind' => 'reminder',
    'id' => 'intern-1',
    'name' => 'Personal Exempel',
    'title' => 'Internt',
    'email' => 'personal.exempel@personal.post',
    'phone' => '0701000000',
    'street' => '',
]]);
$labels = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), (array) ($prepared['skipped_internal_sources'] ?? []));
r2(in_array('Personal Exempel', $labels, true), 'skipped_internal har namn');
$linked = jeab_kontakter_link(['dry' => 1], $store);
$linkedNames = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), (array) ($linked['skipped_internal_sources'] ?? []));
r2(in_array('Personal Exempel', $linkedNames, true) || in_array('Möte', $linkedNames, true), 'link_report tar med namn på skipped_internal');
$linkedJson = json_encode($linked, JSON_UNESCAPED_UNICODE);
r2(is_string($linkedJson) && !str_contains($linkedJson, 'sekretess'), 'link_report innehåller inte personnummer');

echo "helpscout import round 2: ok\n";
