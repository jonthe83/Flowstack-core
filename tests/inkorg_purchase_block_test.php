<?php

declare(strict_types=1);

/** Undantag, formulärprodukt och hård spärr mot utskick vid omklassning. */

$tmp = sys_get_temp_dir() . '/jeab-block-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function pb(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function pb_msg(string $from, string $body, array $extra = []): array
{
    return $extra + [
        'from' => $from,
        'from_name' => 'Kund',
        'subject' => 'Förfrågan',
        'body' => $body,
    ];
}

$ask = 'Kan jag få pris och offert?';
$store = jeab_store();
pb($store instanceof JeabPdoStore, 'sqlite är uppe');

$rules = implode("\n", jeab_auto_rule_lines());
pb(str_contains($rules, 'would_quote') && str_contains($rules, 'lead-block') && str_contains($rules, 'Omklassningen kan inte skicka'), 'reglerna beskriver undantag och spärr');
pb(in_array('huawei.com', (array) (jeab_inkorg_seed_lists()['lead-block']['entries'] ?? []), true), 'blocklistan har kända leverantörer');

pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', $ask . ' Ett batteri.', ['headers' => ['List-Unsubscribe' => '<mailto:a@brevlada.se>']])) === 'bulk', 'List-Unsubscribe');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', $ask . ' Ett batteri.', ['headers' => ['Precedence' => 'bulk']])) === 'bulk', 'Precedence bulk');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', $ask . ' Ett batteri.', ['headers' => ['Auto-Submitted' => 'auto-generated']])) === 'bulk', 'Auto-Submitted');
pb(jeab_inkorg_purchase_blocked(pb_msg('noreply@brevlada.se', $ask . ' Ett batteri.')) === 'noreply', 'noreply');
pb(jeab_inkorg_purchase_blocked(pb_msg('news@huawei.com', $ask . ' Ett batteri.')) === 'supplier', 'Huawei');
pb(jeab_inkorg_purchase_blocked(pb_msg('order@scanmont.se', $ask . ' En laddbox.')) === 'supplier', 'Scanmont');
pb(jeab_inkorg_purchase_blocked(pb_msg('info@epax.se', $ask . ' Solceller och offert.')) === 'supplier', 'Epax');
pb(jeab_inkorg_purchase_blocked(pb_msg('saljare.syd@malmo.example.test', $ask . ' Ett batteri.')) === 'staff', 'egen personal');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Ja tack, vad kostar batteriet?', ['subject' => 'SV: Installation av laddbox'])) === 'ours', 'kundsvar på vår bekräftelse');
$thread = ['messages' => [
    ['from' => 'saljare4@jarfalla.example.test', 'source' => 'outbound', 'subject' => 'Hej', 'body' => 'Vi återkommer.'],
    pb_msg('kund@brevlada.se', $ask . ' En laddbox.', ['subject' => 'Re: Hej']),
]];
pb(jeab_inkorg_purchase_blocked($thread['messages'][1], $thread) === 'ours', 'tråd som vi startat');
pb(jeab_inkorg_purchase_offer(pb_msg('kund@brevlada.se', $ask . ' Installation av laddbox hemma.', ['subject' => 'Installation av laddbox'])) === 'laddbox', 'nytt ämne om installation är fortfarande lead');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', $ask . ' Ett batteri.', ['thread_id' => 'autotest-1'])) === 'autotest', 'autotest');
pb(jeab_inkorg_purchase_blocked(pb_msg('testkund@example.com', $ask . ' Ett batteri.')) === 'formtest', 'testadress');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', "Namn: Test\nE-post: anna@brevlada.se\n" . $ask . ' Ett batteri.')) === 'formtest', 'formulärnamn test');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', "Vad gäller det: Support till befintlig kund\n" . $ask . ' Solceller.')) === 'support_form', 'support-kryss');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Det är fel på laddboxen. ' . $ask)) === 'support_words', 'fel- och serviceord');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Behöver service på anläggningen och en offert på batteri.')) === 'support_words', 'serviceord');

$entries = array_map('strval', (array) (jeab_inkorg_lists()['lead-block']['entries'] ?? []));
$entries[] = 'extra-lev.se';
jeab_inkorg_list_save('lead-block', 'Lead: blockera domäner', implode("\n", $entries));
pb(jeab_inkorg_purchase_blocked(pb_msg('a@extra-lev.se', $ask . ' Ett batteri.')) === 'blocklist', 'konfigurerbar blocklista');
pb(jeab_inkorg_purchase_offer(pb_msg('a@extra-lev.se', $ask . ' Ett batteri.')) === '', 'blocklistan ger ingen produkt');

$rem = jeab_create_reminder($store, 'Batteri', '2026-10-21 09:00:00', 'anteckning', null, [
    'email' => 'paminn.kund@brevlada.se',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
pb(jeab_inkorg_purchase_blocked(pb_msg('paminn.kund@brevlada.se', $ask . ' Ett batteri.')) === 'support_reminder', 'befintlig kund med påminnelse');

$orderRem = jeab_create_reminder($store, 'Order', '2026-10-21 10:00:00', 'anteckning', null, [
    'email' => 'order.kund@brevlada.se',
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
$orderRow = jeab_purchase_blank((int) $orderRem['id'], 'batteri', jeab_get_reminder($store, (int) $orderRem['id']), $store);
$orderRow['email'] = 'order.kund@brevlada.se';
jeab_purchase_put($orderRow);
pb(jeab_inkorg_purchase_blocked(pb_msg('order.kund@brevlada.se', $ask . ' Ett batteri.')) === 'support_order', 'befintlig kund med order');

$instRem = jeab_create_reminder($store, 'Installation', '2026-10-21 11:00:00', 'anteckning', null, [
    'email' => 'install.kund@brevlada.se',
    'company' => 'jarfalla',
    'who' => 'Elin',
]);
$instRow = jeab_purchase_blank((int) $instRem['id'], 'laddbox', jeab_get_reminder($store, (int) $instRem['id']), $store);
$instRow['email'] = 'install.kund@brevlada.se';
$instRow['install_date'] = '2026-11-02';
jeab_purchase_put($instRow);
pb(jeab_inkorg_purchase_blocked(pb_msg('install.kund@brevlada.se', $ask . ' En laddbox.')) === 'support_installation', 'befintlig kund med installation');

pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', "Produkt: Laddbox\nVi har solceller och batteri hemma.\n" . $ask)) === 'laddbox', 'formulärets laddbox vinner');
pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', "Produkt: Batteri\nVi har solceller på taket.\n" . $ask)) === 'batteri', 'formulärets batteri vinner över sol');
pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', 'Vi vill ha offert på solceller och ett batteri.')) === 'sol', 'utan formulär är texten reserv och sol väger tyngst');
pb(jeab_inkorg_form_product(pb_msg('ny.kund@brevlada.se', "Kategori\nElbilsladdare")) === 'laddbox', 'produkt på nästa rad');

$domains = [
    'news@laniakeaenergy.com' => 'laniakea',
    'info@ecosolarcarport.com' => 'ecosolarcarport',
    'a@ecopvcarport.com' => 'ecopvcarport',
    'sales@felicitysolar.com' => 'felicitysolar',
    'a@renonpower.com' => 'renon',
    'a@conluxs.com' => 'conluxs',
    'a@wwwebb.com' => 'wwwebb',
    'a@contractors1000.com' => 'contractors1000',
    'a@ifsek.se' => 'ifsek',
];
foreach ($domains as $from => $name) {
    $msg = pb_msg($from, $ask . ' Ett batteri och installation.');
    pb(jeab_inkorg_purchase_blocked($msg) === 'supplier' && jeab_inkorg_purchase_offer($msg) === '', $name . ' är leverantör');
}
$pitches = [
    'Varma leads till er, kan vi få en offertdiskussion?' => 'varma leads',
    'Samarbete kring laddstolpar. Vi vill installera hos era kunder.' => 'samarbete laddstolpar',
    'Vi köper ert skrot och kan ge ett pris.' => 'skrotköpare',
    'Vi är Huawei-återförsäljare och vill sälja till er.' => 'huawei-återförsäljare',
];
foreach ($pitches as $body => $name) {
    $msg = pb_msg('salj@brevlada.se', $body);
    pb(jeab_inkorg_purchase_blocked($msg) === 'supplier' && jeab_inkorg_purchase_offer($msg) === '', $name . ' är inte lead');
}
$many = pb_msg('kund@brevlada.se', $ask . ' Ett batteri.', [
    'to' => 'a@brevlada.se, b@brevlada.se',
    'cc' => 'c@brevlada.se, d@brevlada.se',
]);
pb(jeab_inkorg_purchase_blocked($many) === 'supplier', 'mejl till många mottagare');
pb(jeab_inkorg_purchase_offer(pb_msg('kund@brevlada.se', 'Kan jag få pris på ett batteri?')) === 'batteri', 'vanlig batteriförfrågan är fortfarande lead');

pb(jeab_inkorg_purchase_blocked(pb_msg('kevin@qpsmedia.se', $ask . ' Ett batteri.')) === 'formtest', 'kevin@qpsmedia');
pb(jeab_inkorg_purchase_blocked(pb_msg('news@qpsmedia.com', $ask . ' Ett batteri.')) === 'formtest', 'qpsmedia-domän');
pb(jeab_inkorg_purchase_offer(pb_msg('kevin@qpsmedia.se', $ask . ' Ett batteri.')) === '', 'qpsmedia ger ingen produkt');

pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', $ask . ' Ett batteri.', ['subject' => 'Re: Installation av solceller onsdag'])) === 'ours', 'Re: installation av solceller');
$newestFirst = ['messages' => [
    ['from' => 'kund@brevlada.se', 'subject' => 'Re: Hej', 'body' => $ask . ' En laddbox.', 'received_at' => '2026-10-20T12:00:00+02:00'],
    ['from' => 'saljare4@jarfalla.example.test', 'source' => 'outbound', 'subject' => 'Hej', 'body' => 'Vi bokar installationen.', 'received_at' => '2026-10-20T09:00:00+02:00'],
]];
pb(jeab_inkorg_purchase_blocked($newestFirst['messages'][0], $newestFirst) === 'ours', 'första meddelandet i tiden är personal');

pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Överlåtelse av Easee, kan jag få pris?')) === 'support_words', 'överlåtelse av Easee');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Tillägg på laddbox. ' . $ask)) === 'support_words', 'tillägg på laddbox');
pb(jeab_inkorg_purchase_offer(pb_msg('kund@brevlada.se', 'Överlåtelse av Easee, kan jag få pris på laddboxen?')) === '', 'support ger ingen produkt');

$footer = "\nMed vänlig hälsning\nDemo Energi Järfälla\nStockholm";
$orebro = pb_msg('ort@brevlada.se', "Ort: Örebro\n" . $ask . ' Ett batteri.' . $footer);
pb(jeab_inkorg_purchase_blocked($orebro) === 'outside' && jeab_inkorg_purchase_offer($orebro) === '', 'Örebro är utanför området trots signatur');
pb(jeab_inkorg_purchase_offer(pb_msg('ort@brevlada.se', "Ort: Järfälla\n" . $ask . ' Ett batteri.')) === 'batteri', 'Järfälla är i området');
pb(jeab_inkorg_purchase_offer(pb_msg('ort@brevlada.se', "Ort: Lund\n" . $ask . ' Ett batteri.')) === 'batteri', 'Lund är i området');
pb(jeab_inkorg_purchase_blocked(pb_msg('ort@brevlada.se', $ask . ' Ett batteri. Adressen är Storgatan 1, 702 15.')) === 'outside', 'postnummer utanför Stockholm och Skåne');
pb(jeab_inkorg_purchase_offer(pb_msg('ort@brevlada.se', $ask . ' Ett batteri. Adressen är Storgatan 1, 177 57.')) === 'batteri', 'postnummer i Stockholmsområdet');
$outsideCase = jeab_inkorg_case_enforce([
    'intent' => 'kopintresse',
    'kind' => 'atgard',
    'type' => 'Ny lead',
    'label' => '',
    'is_lead_seller' => false,
    'primary_product' => 'Sol',
    'confidence' => [],
    'priority' => 1,
], $orebro);
pb(($outsideCase['label'] ?? '') === 'Utanför området' && ($outsideCase['type'] ?? '') === 'Information' && ($outsideCase['intent'] ?? '') === 'information', 'etiketten blir Utanför området');

pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', 'Vi har solceller och vill ha ett batteri på 10 kWh. Kan vi få pris?')) === 'batteri', 'kWh utan ny sol är batteri');
pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', 'Vi vill installera en ny solanläggning och ett batteri på 10 kWh. Kan vi få pris?')) === 'sol', 'ny solanläggning med kWh är sol');
pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', "Produkt: Solceller med batteri\nStorlek 10 kWh.\n" . $ask)) === 'sol', 'formuläret Solceller med batteri vinner över kWh');
pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', 'Vi vill ha solceller med batteri, 10 kWh. Kan vi få pris?')) === 'sol', 'solceller med batteri och kWh är sol');
pb(jeab_inkorg_purchase_offer(pb_msg('ny.kund@brevlada.se', 'Vi vill ha nya solceller och ett batteri på 15 kWh. Kan vi få pris?')) === 'sol', 'nya solceller och batteri är sol');
$live = jeab_inkorg_case_enforce([
    'intent' => 'kopintresse',
    'kind' => 'atgard',
    'type' => 'Ny lead',
    'label' => '',
    'is_lead_seller' => false,
    'primary_product' => 'Batteri',
    'secondary_products' => [],
    'confidence' => [],
    'priority' => 1,
], pb_msg('ny.kund@brevlada.se', 'Vi vill ha solceller med batteri, 10 kWh. Kan vi få pris?'));
pb(($live['primary_product'] ?? '') === 'Batteri' && ($live['secondary_products'] ?? []) === [] && ($live['type'] ?? '') === 'Ny lead', 'Grok får behålla Batteri när texten också nämner sol');
$infoKept = jeab_inkorg_case_enforce([
    'intent' => 'information',
    'kind' => 'information',
    'type' => 'Information',
    'label' => '',
    'is_lead_seller' => false,
    'primary_product' => '',
    'secondary_products' => [],
    'confidence' => [],
    'priority' => 4,
], pb_msg('ny.kund@brevlada.se', 'Vi vill ha solceller med batteri, 10 kWh. Kan vi få pris?'));
pb(($infoKept['type'] ?? '') === 'Information' && ($infoKept['kind'] ?? '') === 'information' && ($infoKept['intent'] ?? '') === 'information' && ($infoKept['primary_product'] ?? '') === '', 'köporden byter inte Information och fyller ingen produkt');
$patterns = [
    '22–24 paneler och 18 kWh. Kan vi få pris på batteri?' => 'sol',
    'Vi vill ha solceller samt batteri. Kan vi få pris?' => 'sol',
    'Vi vill ha solceller med batteri. Kan vi få pris?' => 'sol',
    'Vi vill ha solceller och batteri. Kan vi få pris?' => 'sol',
    'Vi vill ha sol+batteri. Kan vi få pris?' => 'sol',
    'Vi vill ha solpaneler och 10 kWh. Kan vi få pris?' => 'sol',
    'Vi vill ha en solanläggning. Kan vi få pris?' => 'sol',
    "Produkt: Solceller\nBatteri 10 kWh.\n" . $ask => 'sol',
    'Kan jag få pris på ett batteri ca 10 kWh?' => 'batteri',
    'Har redan solceller och vill ha batteri ca 10 kWh. Kan vi få pris?' => 'batteri',
    'Befintliga solceller, vill ha ett batteri på 10 kWh. Kan vi få pris?' => 'batteri',
    'Vi har sol och vill ha batteri ca 10 kWh. Kan vi få pris?' => 'batteri',
    'Vi har solceller och vill ha nya paneler och ett batteri. Kan vi få pris?' => 'sol',
    'Vill ha ett solcellsbatteri ca 10 kWh. Kan vi få pris?' => 'batteri',
    'Kan jag få pris på ett solbatteri?' => 'batteri',
    'Kan jag få pris på solcellsbatterier?' => 'batteri',
    'Kan jag få pris på solcellslagring?' => 'batteri',
    'Vi vill ha batteri till solceller. Kan vi få pris?' => 'batteri',
    'Vi vill ha batteri till mina solceller. Kan vi få pris?' => 'batteri',
    'Vi vill ha batteri till befintliga solceller. Kan vi få pris?' => 'batteri',
    'Vi vill ha nya solceller och solcellsbatteri. Kan vi få pris?' => 'sol',
    'Vi vill ha 22–24 paneler + 18 kWh. Kan vi få pris?' => 'sol',
];
foreach ($patterns as $body => $want) {
    pb(jeab_inkorg_purchase_offer_product(pb_msg('ny.kund@brevlada.se', $body)) === $want, 'produkt ' . $want . ' för «' . mb_substr($body, 0, 42) . '»');
}
$compound = jeab_inkorg_purchase_offer_detail(pb_msg('ny.kund@brevlada.se', 'Vill ha ett solcellsbatteri ca 10 kWh. Kan vi få pris?'));
pb(($compound['offer'] ?? '') === 'batteri' && str_starts_with((string) ($compound['basis'] ?? ''), 'batteriord') && !str_starts_with((string) ($compound['basis'] ?? ''), 'solord'), 'solcellsbatteri är batteriord');
$solbatt = jeab_inkorg_purchase_offer_detail(pb_msg('ny.kund@brevlada.se', 'Kan jag få pris på ett solbatteri?'));
pb(($solbatt['offer'] ?? '') === 'batteri' && str_starts_with((string) ($solbatt['basis'] ?? ''), 'batteriord'), 'solbatteri är batteriord');
$med = jeab_inkorg_purchase_offer_detail(pb_msg('ny.kund@brevlada.se', 'Vi vill ha solceller med batteri. Kan vi få pris?'));
pb(($med['offer'] ?? '') === 'sol' && str_starts_with((string) ($med['basis'] ?? ''), 'solord'), 'solceller med batteri är solord');
$nya = jeab_inkorg_purchase_offer_detail(pb_msg('ny.kund@brevlada.se', 'Vi vill ha nya solceller och solcellsbatteri. Kan vi få pris?'));
pb(($nya['offer'] ?? '') === 'sol' && str_starts_with((string) ($nya['basis'] ?? ''), 'solord'), 'nya solceller och solcellsbatteri är solord');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hemsol.se', 'Kan jag få pris på batteri ca 10 kWh?')) === 'batteri', 'HemSol med bara kWh är batteri');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hittasolceller.se', 'Kan jag få pris på batteri ca 10 kWh?')) === 'batteri', 'Hitta-Solceller med bara kWh är batteri');
pb(jeab_inkorg_purchase_offer_product(pb_msg('formular@brevlada.se', "Källa: solcellsformulär\nKan jag få pris på batteri ca 10 kWh?")) === 'batteri', 'solcellsformulär med bara kWh är batteri');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hemsol.se', 'Kan jag få pris på lagring?')) === 'batteri', 'HemSol med bara lagring är batteri');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hemsol.se', 'Kan jag få en offert?')) === '', 'HemSol utan produkt i texten får ingen produkt');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hittasolceller.se', 'Kan jag få en offert?')) === '', 'Hitta-Solceller utan produkt i texten får ingen produkt');
pb(jeab_inkorg_purchase_offer_product(pb_msg('formular@brevlada.se', "Källa: solcellsformulär\nKan jag få en offert?")) === '', 'källraden utan produkt i texten sätter ingen produkt');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hemsol.se', "Produkt: Solceller med batteri\nKan jag få pris?")) === 'sol', 'HemSol med solceller i produktfältet är sol');
pb(jeab_inkorg_purchase_offer_product(pb_msg('lead@hemsol.se', 'Har redan solceller och vill ha batteri ca 10 kWh. Kan vi få pris?')) === 'batteri', 'HemSol med redan sol är batteri');
$mallBody = "Solceller\nNytt lead (HemSol)\nEtiketter: Solceller\nMailbox: lead@hemsol.se\nNamn: Ada Holm\nE-post: ada.holm@brevlada.se\nProdukt: Batteri\nMeddelande: Vi vill ha ett batteri på ca 10 kWh.\n[HemSol]\n";
$mall = pb_msg('lead@hemsol.se', $mallBody, ['subject' => 'Offertförfrågan solceller', 'to' => 'kontakt@jarfalla.example.test']);
$mallDetail = jeab_inkorg_purchase_offer_detail($mall);
pb(($mallDetail['offer'] ?? '') === 'batteri' && str_starts_with((string) ($mallDetail['basis'] ?? ''), 'formulär') && mb_strlen((string) ($mallDetail['basis'] ?? '')) <= 40 && !str_contains((string) ($mallDetail['basis'] ?? ''), '@'), 'HemSol-mall med bara batteri är batteri');
$hittaMall = pb_msg('lead@hitta-solceller.se', "Solceller\nEtiketter: Solceller\nMeddelande: Batteri ca 10 kWh. Kan vi få pris?\n", ['subject' => 'Solceller']);
pb(jeab_inkorg_purchase_offer_product($hittaMall) === 'batteri', 'Hitta-Solceller-mall med bara batteri är batteri');
$mallSol = pb_msg('lead@hemsol.se', "Solceller\nOffertförfrågan solceller\nMeddelande: Vi vill ha solceller samt batteri. Kan vi få pris?\n", ['subject' => 'Offertförfrågan solceller']);
$mallSolDetail = jeab_inkorg_purchase_offer_detail($mallSol);
pb(($mallSolDetail['offer'] ?? '') === 'sol' && str_starts_with((string) ($mallSolDetail['basis'] ?? ''), 'solord'), 'kundens solceller i HemSol-mallen är sol');
$supportBodies = [
    'Överlåtelse av Easee. Kan ni hjälpa?',
    'Jag vill överlåta laddboxen.',
    'Vi har sålt huset och laddboxen ska med.',
    'Ny ägare ska ta över laddaren.',
    'Kan ni flytta Easee till den nya ägaren?',
    'Kan ni överföra laddboxen till det nya kontot?',
    'Kan ni flytta laddaren till den nya adressen?',
    'Koppla bort från mitt konto.',
    'Det gäller mitt Easee-konto.',
];
foreach ($supportBodies as $body) {
    pb(jeab_inkorg_purchase_support_words($body) && jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', $body)) === 'support_words' && jeab_inkorg_purchase_offer(pb_msg('kund@brevlada.se', $body)) === '', 'support: ' . mb_substr($body, 0, 36));
}
$panelMalmo = jeab_malmo_hs_decision([
    'id' => 806,
    'subject' => 'Paneler',
    'status' => 'active',
    'tags' => [],
    'primaryCustomer' => ['email' => 'paneler@brevlada.se'],
    '_threads' => [['type' => 'customer', 'body' => 'Vi vill ha 22–24 paneler och 18 kWh. Kan vi få pris?', 'createdBy' => ['email' => 'paneler@brevlada.se']]],
]);
pb(($panelMalmo['target'] ?? '') === 'historik' && ($panelMalmo['reason'] ?? '') === 'sol', 'Malmö skickar paneler och kWh till Historik');
$solMalmo = jeab_malmo_hs_decision([
    'id' => 805,
    'subject' => 'Sol och batteri',
    'status' => 'active',
    'tags' => [],
    'primaryCustomer' => ['email' => 'solbatt@brevlada.se'],
    '_threads' => [['type' => 'customer', 'body' => "Produkt: Solceller med batteri\n10 kWh. Kan vi få pris?", 'createdBy' => ['email' => 'solbatt@brevlada.se']]],
]);
pb(($solMalmo['target'] ?? '') === 'historik' && ($solMalmo['jobs'] ?? []) === [], 'Malmö skickar sol och batteri till Sam W, inte Kim');

$named = [
    ['Epax Solar i avsändarnamnet', 'anna@gmail.com', 'Epax Solar', 'Hej, se vår produktkatalog.'],
    ['epaxsolar.com', 'sales@epaxsolar.com', 'Sales', $ask . ' Ett batteri.'],
    ['Renon Power i signaturen', 'kim.renon@gmail.com', 'Kim', "Vi erbjuder batterilager.\n\nRenon Power\nSales"],
    ['Sol Axel Gozan', 'a@gozan.se', 'Sol Axel', 'Sol Axel vill sälja paneler till er.'],
    ['IFSEK i texten', 'boss@gmail.com', 'Boss', 'IFSEK erbjuder tjänster till er och en prislista.'],
];
foreach ($named as $row) {
    $msg = pb_msg($row[1], $row[3], ['from_name' => $row[2]]);
    pb(jeab_inkorg_purchase_blocked($msg) === 'supplier' && jeab_inkorg_purchase_offer($msg) === '', $row[0] . ' är inte lead');
}
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Jag vill överlåta laddboxen till ny ägare. Kan ni hjälpa?')) === 'support_words', 'överlåta och ny ägare');
pb(jeab_inkorg_purchase_blocked(pb_msg('kund@brevlada.se', 'Kan ni flytta över laddboxen till den nya adressen?')) === 'support_words', 'flytta över laddboxen');
pb(jeab_inkorg_purchase_offer(pb_msg('kund@brevlada.se', 'Jag vill överlåta anläggningen. Kan jag få pris?')) === '', 'överlåtelse ger ingen produkt');

$signatur = "\nMed vänlig hälsning\nDemo Energi Järfälla\n177 57 Stockholm";
$orebroBody = pb_msg('ort@brevlada.se', "Hej, jag bor i Örebro och vill ha offert på ett batteri." . $signatur);
pb(jeab_inkorg_purchase_blocked($orebroBody) === 'outside' && jeab_inkorg_purchase_offer($orebroBody) === '', 'Örebro i brödtexten slår signaturen');
$postalBody = pb_msg('ort@brevlada.se', "Postnummer: 70215\nOrt: Örebro\n" . $ask . ' Ett batteri.' . $signatur);
pb(jeab_inkorg_purchase_blocked($postalBody) === 'outside', 'formulärets postnummer utanför 1xx och 2xx');
pb(jeab_inkorg_purchase_offer(pb_msg('ort@brevlada.se', "Postnummer: 177 57\nOrt: Järfälla\n" . $ask . ' Ett batteri.')) === 'batteri', '1xx xx är Stockholmsområdet');
pb(jeab_inkorg_purchase_offer(pb_msg('ort@brevlada.se', "Postnummer: 211 20\nOrt: Malmö\n" . $ask . ' Ett batteri.')) === 'batteri', '2xx xx är Skåne');
pb(jeab_inkorg_purchase_blocked(pb_msg('ort@brevlada.se', "Postnummer: 411 03\nOrt: Göteborg\n" . $ask . ' Ett batteri.' . $signatur)) === 'outside', 'Göteborgs postnummer är utanför');

$sellerImport = jeab_hs_import_classify([
    'subject' => 'Samarbete',
    'jeab_text' => 'Samarbete kring laddstolpar. Vi vill installera hos era kunder.',
    'tags' => [['tag' => 'elbilsladdare']],
    'primaryCustomer' => ['email' => 'sales@felicitysolar.com'],
]);
pb(($sellerImport['type'] ?? '') === 'Information' && ($sellerImport['tags'] ?? []) === [], 'import av säljare blir inte Ny lead');
$kwhImport = jeab_hs_import_classify([
    'subject' => 'Batteri',
    'jeab_text' => 'Vi har solceller och vill ha ett batteri på 10 kWh. Kan vi få pris?',
    'tags' => [],
    'primaryCustomer' => ['email' => 'kwh.klass@brevlada.se'],
]);
pb(($kwhImport['type'] ?? '') === 'Information' && ($kwhImport['tags'] ?? []) === ['batteri'], 'import av kWh-batteri blir batteri utan Ny lead');
$areaImport = jeab_hs_import_classify([
    'subject' => 'Offert',
    'jeab_text' => "Ort: Örebro\nKan jag få pris på ett batteri?",
    'tags' => [['tag' => 'batteri']],
    'primaryCustomer' => ['email' => 'orebro.klass@brevlada.se'],
]);
pb(($areaImport['type'] ?? '') === 'Information' && ($areaImport['tags'] ?? []) === [], 'import utanför området blir inte Ny lead');

$sellerDecision = jeab_malmo_hs_decision([
    'id' => 801,
    'subject' => 'Carport',
    'status' => 'active',
    'tags' => [],
    'primaryCustomer' => ['email' => 'news@laniakeaenergy.com'],
    '_threads' => [['type' => 'customer', 'body' => 'Vi vill sälja solcellscarport och ge er ett pris.', 'createdBy' => ['email' => 'news@laniakeaenergy.com']]],
]);
pb(($sellerDecision['target'] ?? '') === 'historik' && ($sellerDecision['reason'] ?? '') === 'undantagen', 'Malmö tar inte säljare till Kim');
$areaDecision = jeab_malmo_hs_decision([
    'id' => 802,
    'subject' => 'Offert',
    'status' => 'active',
    'tags' => [],
    'primaryCustomer' => ['email' => 'ort.malmo@brevlada.se'],
    '_threads' => [['type' => 'customer', 'body' => "Ort: Örebro\nKan jag få pris på ett batteri?", 'createdBy' => ['email' => 'ort.malmo@brevlada.se']]],
]);
pb(($areaDecision['target'] ?? '') === 'historik' && ($areaDecision['reason'] ?? '') === 'utanför området', 'Malmö markerar Örebro som utanför området');
$kwhDecision = jeab_malmo_hs_decision([
    'id' => 803,
    'subject' => 'Batteri',
    'status' => 'active',
    'tags' => [],
    'primaryCustomer' => ['email' => 'kwh.malmo@brevlada.se'],
    '_threads' => [['type' => 'customer', 'body' => 'Vi har solceller och vill ha ett batteri på 10 kWh. Kan vi få pris?', 'createdBy' => ['email' => 'kwh.malmo@brevlada.se']]],
]);
pb(($kwhDecision['target'] ?? '') === 'kim' && ($kwhDecision['jobs'][0] ?? '') === 'batteri', 'Malmö klassar kWh-batteri som batteri');
$areaPlan = jeab_malmo_hs_plan_row([
    'id' => 804,
    'subject' => 'Offert',
    'status' => 'active',
    'tags' => [],
    'primaryCustomer' => ['email' => 'plan.ort@brevlada.se'],
    '_threads' => [['type' => 'customer', 'body' => "Ort: Göteborg\nKan jag få pris på en laddbox?", 'createdBy' => ['email' => 'plan.ort@brevlada.se']]],
], false);
pb(empty($areaPlan['would_send_offer']) && ($areaPlan['reason'] ?? '') === 'utanför området', 'Malmö-planen skickar inte utanför området');

$prompt = jeab_inkorg_llm_prompt(['from' => 'a@brevlada.se', 'subject' => 'Hej', 'body' => 'Hej']);
$promptText = (string) ($prompt[0]['content'] ?? '');
pb(str_contains($promptText, 'alltid type Ny lead') && str_contains($promptText, 'qpsmedia') && str_contains($promptText, 'kWh') && str_contains($promptText, 'Örebro'), 'prompten har undantagen');
pb(str_contains(jeab_inkorg_seed_settings()['intent'], 'qpsmedia') && str_contains(implode("\n", jeab_auto_rule_lines()), 'Utanför området'), 'instruktion och regler nämner test och område');

$supplier = jeab_hs_import_classify([
    'subject' => 'Offert',
    'jeab_text' => 'Vi vill installera ett batteri och få en offert.',
    'tags' => [['tag' => 'batteri']],
    'primaryCustomer' => ['email' => 'news@scanmont.se'],
]);
pb(($supplier['type'] ?? '') === 'Information' && ($supplier['tags'] ?? []) === [], 'import av leverantör blir inte Ny lead');
$form = jeab_hs_import_classify([
    'subject' => 'Form',
    'jeab_text' => "Produkt: Laddbox\nVi har solceller och vill ha offert.",
    'tags' => [['tag' => 'sol']],
    'primaryCustomer' => ['email' => 'form.klass@brevlada.se'],
]);
pb(($form['type'] ?? '') === 'Information' && ($form['tags'] ?? []) === ['elbilsladdare'], 'importen tar produkten från formuläret utan Ny lead');
$plain = jeab_hs_import_classify([
    'subject' => 'Offert',
    'jeab_text' => 'Vi vill installera ett batteri och få en offert.',
    'tags' => [],
    'primaryCustomer' => ['email' => 'ny.klass@brevlada.se'],
]);
pb(($plain['type'] ?? '') === 'Information' && in_array('batteri', (array) ($plain['tags'] ?? []), true), 'import utan formulär använder texten utan Ny lead');

$lead = jeab_inkorg_ingest([
    'message_id' => 'lead@block.test',
    'from' => 'ny.lead@brevlada.se',
    'from_name' => 'Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Förfrågan',
    'body_text' => 'Kan jag få pris på ett batteri?',
    'received_at' => date('c'),
    'source' => 'test-reclass',
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 40, 'intent' => 'information'],
]);
$noise = jeab_inkorg_ingest([
    'message_id' => 'noise@block.test',
    'from' => 'news@huawei.com',
    'from_name' => 'Huawei',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Nyhetsbrev',
    'body_text' => 'Offert på batteri och laddbox.',
    'received_at' => date('c'),
    'source' => 'test-reclass',
    'headers' => ['List-Unsubscribe' => '<mailto:leave@huawei.com>'],
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 40, 'intent' => 'information'],
]);
pb(!empty($lead['ok']) && !empty($noise['ok']), 'lead och leverantör intagna');
$preset = ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 40, 'intent' => 'information'];
$kwhLead = jeab_inkorg_ingest([
    'message_id' => 'kwh@block.test',
    'from' => 'kwh.lead@brevlada.se',
    'from_name' => 'Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Batteri',
    'body_text' => 'Vi har solceller och vill ha ett batteri på 10 kWh. Kan vi få pris?',
    'received_at' => date('c'),
    'source' => 'test-reclass',
    'preset_labels' => $preset,
]);
$falseLeads = [
    ['id' => 'seller@block.test', 'from' => 'news@laniakeaenergy.com', 'body' => 'Offert på batteri och installation av carport.'],
    ['id' => 'qps@block.test', 'from' => 'kevin@qpsmedia.se', 'body' => 'Kan jag få pris på ett batteri?'],
    ['id' => 'easee@block.test', 'from' => 'easee.kund@brevlada.se', 'body' => 'Överlåtelse av Easee, kan jag få pris?'],
    ['id' => 'orebro@block.test', 'from' => 'orebro.kund@brevlada.se', 'body' => "Ort: Örebro\nKan jag få pris på ett batteri?"],
    ['id' => 're@block.test', 'from' => 're.kund@brevlada.se', 'subject' => 'Re: Installation av solceller onsdag', 'body' => 'Vad kostar ett batteri?'],
];
foreach ($falseLeads as $falseLead) {
    $taken = jeab_inkorg_ingest([
        'message_id' => $falseLead['id'],
        'from' => $falseLead['from'],
        'from_name' => 'Avsandare',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $falseLead['subject'] ?? 'Förfrågan',
        'body_text' => $falseLead['body'],
        'received_at' => date('c'),
        'source' => 'test-reclass',
        'preset_labels' => $preset,
    ]);
    pb(!empty($taken['ok']), 'intag ' . $falseLead['id']);
}
pb(!empty($kwhLead['ok']), 'kWh-lead intaget');
$reclass = [];
$reclassBodies = [
    'paneler' => ['from' => 'paneler@brevlada.se', 'body' => 'Vi vill ha 22–24 paneler och 18 kWh. Kan vi få pris på batteri?', 'preset' => $preset + ['product' => 'batteri', 'offer' => 'batteri'], 'offer' => 'sol'],
    'samt' => ['from' => 'samt@brevlada.se', 'body' => 'Vi vill ha solceller samt batteri. Kan vi få pris?', 'offer' => 'sol'],
    'med' => ['from' => 'med@brevlada.se', 'body' => 'Vi vill ha solceller med batteri. Kan vi få pris?', 'offer' => 'sol'],
    'och' => ['from' => 'och@brevlada.se', 'body' => 'Vi vill ha solceller och batteri. Kan vi få pris?', 'offer' => 'sol'],
    'plus' => ['from' => 'plus@brevlada.se', 'body' => 'Vi vill ha sol+batteri. Kan vi få pris?', 'offer' => 'sol'],
    'solpanel' => ['from' => 'solpanel@brevlada.se', 'body' => 'Vi vill ha solpaneler och 10 kWh. Kan vi få pris?', 'offer' => 'sol'],
    'anlaggning' => ['from' => 'anlaggning@brevlada.se', 'body' => 'Vi vill ha en solanläggning. Kan vi få pris?', 'offer' => 'sol'],
    'hemsol' => ['from' => 'lead@hemsol.se', 'body' => 'Kan jag få pris på batteri ca 10 kWh?', 'offer' => 'batteri'],
    'hitta' => ['from' => 'lead@hittasolceller.se', 'body' => 'Kan jag få pris på batteri ca 10 kWh?', 'offer' => 'batteri'],
    'formsol' => ['from' => 'formsol@brevlada.se', 'body' => "Produkt: Solceller\nBatteri 10 kWh.\nKan jag få pris?", 'offer' => 'sol'],
    'formular' => ['from' => 'formular@brevlada.se', 'body' => "Källa: solcellsformulär\nKan jag få pris på batteri ca 10 kWh?", 'offer' => 'batteri'],
    'hemsol-tom' => ['from' => 'tom@hemsol.se', 'body' => 'Kan jag få en offert?', 'offer' => ''],
    'hemsol-mall' => [
        'from' => 'mall@hemsol.se',
        'subject' => 'Offertförfrågan solceller',
        'body' => "Solceller\nNytt lead (HemSol)\nEtiketter: Solceller\nNamn: Ada Holm\nE-post: ada.holm@brevlada.se\nProdukt: Batteri\nMeddelande: Vi vill ha ett batteri på ca 10 kWh.\n[HemSol]\n",
        'preset' => $preset + ['product' => 'solceller', 'offer' => 'sol', 'add_tags' => ['sol']],
        'offer' => 'batteri',
        'basis' => 'formulär',
    ],
    'hitta-mall' => [
        'from' => 'mall@hitta-solceller.se',
        'subject' => 'Solceller',
        'body' => "Solceller\nEtiketter: Solceller\nMeddelande: Batteri ca 10 kWh, ingen ny sol. Kan vi få pris?\n",
        'preset' => $preset + ['product' => 'solceller', 'offer' => 'sol'],
        'offer' => 'batteri',
        'basis' => 'batteriord',
    ],
    'hitta-tom' => ['from' => 'tom@hittasolceller.se', 'body' => 'Kan jag få en offert?', 'offer' => ''],
    'formular-tom' => ['from' => 'formular.tom@brevlada.se', 'body' => "Källa: solcellsformulär\nKan jag få en offert?", 'offer' => ''],
    'lagring' => ['from' => 'lagring@hemsol.se', 'body' => 'Kan jag få pris på lagring?', 'offer' => 'batteri'],
    'nya-paneler' => ['from' => 'nyapaneler@brevlada.se', 'body' => 'Vi har solceller och vill ha nya paneler och ett batteri. Kan vi få pris?', 'offer' => 'sol'],
    'enbart' => ['from' => 'enbart@brevlada.se', 'body' => 'Kan jag få pris på ett batteri ca 10 kWh?', 'offer' => 'batteri'],
    'redan' => ['from' => 'redan@brevlada.se', 'body' => 'Har redan solceller och vill ha batteri ca 10 kWh. Kan vi få pris?', 'offer' => 'batteri'],
    'befintlig' => ['from' => 'befintlig@brevlada.se', 'body' => 'Befintliga solceller, vill ha ett batteri på 10 kWh. Kan vi få pris?', 'offer' => 'batteri'],
    'vihar' => ['from' => 'vihar@brevlada.se', 'body' => 'Vi har sol och vill ha batteri ca 10 kWh. Kan vi få pris?', 'offer' => 'batteri'],
    'solcellsbatteri' => ['from' => 'solcellsbatteri@brevlada.se', 'body' => 'Vill ha ett solcellsbatteri ca 10 kWh. Kan vi få pris?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'solbatteri' => ['from' => 'solbatteri@brevlada.se', 'body' => 'Kan jag få pris på ett solbatteri?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'solcellsbatterier' => ['from' => 'solcellsbatterier@brevlada.se', 'body' => 'Kan jag få pris på solcellsbatterier?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'solcellslagring' => ['from' => 'solcellslagring@brevlada.se', 'body' => 'Kan jag få pris på solcellslagring?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'till' => ['from' => 'till@brevlada.se', 'body' => 'Vi vill ha batteri till solceller. Kan vi få pris?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'till-mina' => ['from' => 'tillmina@brevlada.se', 'body' => 'Vi vill ha batteri till mina solceller. Kan vi få pris?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'till-befintliga' => ['from' => 'tillbef@brevlada.se', 'body' => 'Vi vill ha batteri till befintliga solceller. Kan vi få pris?', 'offer' => 'batteri', 'basis' => 'batteriord'],
    'nya-och-solbatt' => ['from' => 'nyaoch@brevlada.se', 'body' => 'Vi vill ha nya solceller och solcellsbatteri. Kan vi få pris?', 'offer' => 'sol', 'basis' => 'solord'],
    'paneler-plus' => ['from' => 'panelerplus@brevlada.se', 'body' => 'Vi vill ha 22–24 paneler + 18 kWh. Kan vi få pris?', 'offer' => 'sol', 'basis' => 'solord'],
];
foreach ($reclassBodies as $key => $spec) {
    $taken = jeab_inkorg_ingest([
        'message_id' => $key . '@block.test',
        'from' => $spec['from'],
        'from_name' => 'Kund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $spec['subject'] ?? 'Förfrågan',
        'body_text' => $spec['body'],
        'received_at' => date('c'),
        'source' => 'test-reclass',
        'preset_labels' => $spec['preset'] ?? $preset,
    ]);
    pb(!empty($taken['ok']), 'intag ' . $key);
    $reclass[$key] = $taken + ['offer' => $spec['offer']];
}
$supportReclass = [
    'overlatelse' => 'Överlåtelse av Easee, kan jag få pris?',
    'overlata' => 'Jag vill överlåta laddboxen. Kan jag få pris?',
    'salt' => 'Vi har sålt huset. Kan jag få pris på solceller?',
    'agare' => 'Ny ägare vill ta över. Kan jag få pris?',
    'flytta-easee' => 'Kan ni flytta Easee till den nya ägaren?',
    'overfor' => 'Kan ni överföra laddboxen till det nya huset?',
    'flytta-laddaren' => 'Kan ni flytta laddaren till den nya adressen?',
    'konto' => 'Koppla bort från mitt konto. Kan jag få pris?',
    'easee-konto' => 'Det gäller mitt Easee-konto. Kan jag få pris på laddbox?',
];
foreach ($supportReclass as $key => $body) {
    $taken = jeab_inkorg_ingest([
        'message_id' => $key . '@block.test',
        'from' => $key . '@brevlada.se',
        'from_name' => 'Kund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Förfrågan',
        'body_text' => $body,
        'received_at' => date('c'),
        'source' => 'test-reclass',
        'preset_labels' => $preset,
    ]);
    pb(!empty($taken['ok']), 'intag support ' . $key);
    $reclass[$key] = $taken + ['offer' => ''];
}
$remindersBefore = count($store->listReminders(true));
$calls = 0;
$graphs = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$calls): array {
    $calls++;
    return ['ok' => true, 'path' => 'test', 'id' => 'x'];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function () use (&$graphs): array {
    $graphs++;
    return ['ok' => true];
};
jeab_inkorg_reclassify_lock(true);
try {
    $mail = jeab_system_mail_send(['to' => 'kund@brevlada.se', 'subject' => 'Offert', 'text' => 'Hej']);
    pb(($mail['ok'] ?? true) === false && $calls === 0, 'systemmejl skickas inte under låset');
    $graph = jeab_outbound_graph_send('jarfalla', 'kund@brevlada.se', 'Offert', 'Hej');
    pb(($graph['ok'] ?? true) === false && $graphs === 0, 'Graph skickas inte under låset');
    $person = jeab_gp_send('saljare4@jarfalla.example.test', 'kund@brevlada.se', 'Offert', 'Hej');
    pb(($person['ok'] ?? true) === false, 'personbrevlåda skickas inte under låset');
    $quote = jeab_auto_send_quote(['dry' => 0, 'thread_id' => (string) $lead['thread_id']]);
    pb(($quote['ok'] ?? true) === false && (int) ($quote['sent'] ?? 1) === 0, 'offertkroken skickar inte under låset');
    $malmo = jeab_offer_send_malmo(['dry' => 0, 'product' => 'batteri', 'to' => 'kund@brevlada.se', 'customer_name' => 'Kund']);
    pb(($malmo['ok'] ?? true) === false && (int) ($malmo['sent'] ?? 1) === 0, 'Malmö-offert skickas inte under låset');
    $auto = jeab_auto_on_message((string) $lead['id'], true);
    pb(($auto['action'] ?? '') === 'skip', 'auto på mejl hoppas över under låset');
    $email = jeab_email_send('kund@brevlada.se', 'Hej', 'Kvitto');
    pb(($email['ok'] ?? true) === false, 'kvitto via mejl skickas inte under låset');
    $receipt = jeab_follow_send_receipt(['id' => 't'], ['id' => 'm', 'from' => 'kund@brevlada.se'], [], ['name' => 'Kim', 'email' => 'saljare.syd@malmo.example.test', 'company' => 'malmo', 'id' => 'Kim']);
    pb($receipt === false, 'kvittens skickas inte under låset');
    $threw = false;
    try {
        jeab_create_reminder($store, 'Spärr', '2026-10-22 09:00:00', 'nej', null, ['email' => 'sparr@brevlada.se', 'company' => 'jarfalla']);
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'påminnelse');
    }
    pb($threw, 'påminnelse skapas inte under låset');
    $sendThrew = false;
    try {
        jeab_inkorg_send_in_thread('t', ['body' => 'Hej'], 'test');
    } catch (RuntimeException $e) {
        $sendThrew = str_contains($e->getMessage(), 'mejl');
    }
    pb($sendThrew, 'svar i tråden skickas inte under låset');
} finally {
    jeab_inkorg_reclassify_lock(false);
}
pb(jeab_inkorg_reclassify_locked() === false, 'låset släpps');

$dry = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads', 'dry' => 1, 'days' => 14]);
$byThread = [];
foreach ((array) ($dry['rows'] ?? []) as $dryRow) {
    $byThread[(string) ($dryRow['thread_id'] ?? '')] = $dryRow;
    pb(($dryRow['would_quote'] ?? true) === false && ($dryRow['would_send'] ?? true) === false && ($dryRow['would_remind'] ?? true) === false, 'dry-raden har would_quote, would_send och would_remind av');
}
pb(($byThread[(string) $kwhLead['thread_id']]['offer'] ?? '') === 'batteri' && ($byThread[(string) $lead['thread_id']]['offer'] ?? '') === 'batteri', 'dry-raderna har batteri');
foreach ($reclass as $key => $taken) {
    $row = $byThread[(string) ($taken['thread_id'] ?? '')] ?? null;
    if ($taken['offer'] === '') {
        pb($row === null, 'support ' . $key . ' är inte lead');
        continue;
    }
    $basisOk = !isset($taken['basis']) || (is_array($row) && str_starts_with((string) ($row['offer_basis'] ?? ''), (string) $taken['basis']));
    pb(is_array($row) && (string) ($row['offer'] ?? '') === $taken['offer'] && (string) ($row['new_type'] ?? '') === 'Ny lead' && $basisOk && mb_strlen((string) ($row['offer_basis'] ?? '')) <= 40 && !str_contains((string) ($row['offer_basis'] ?? ''), '@'), 'omklassning ' . $key . ' blir ' . $taken['offer']);
}
$orebroSavedId = '';
$orebroThread = '';
foreach (jeab_inkorg_messages() as $saved) {
    if ((string) ($saved['message_id'] ?? '') === 'orebro@block.test') {
        $orebroSavedId = (string) ($saved['id'] ?? '');
        $orebroThread = (string) ($saved['thread_id'] ?? '');
        break;
    }
}
$orebroRow = $byThread[$orebroThread] ?? null;
pb(is_array($orebroRow) && (string) ($orebroRow['message_id'] ?? '') === $orebroSavedId && (string) ($orebroRow['reason'] ?? '') === 'utanför området' && (string) ($orebroRow['new_type'] ?? '') === 'Information' && (string) ($orebroRow['offer'] ?? '') === '', 'Örebro markeras utanför området');
pb(str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'dry-listan har ingen e-post');

$apply = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads', 'dry' => 0, 'days' => 14]);
$leadRows = 0;
foreach ((array) ($apply['rows'] ?? []) as $appliedRow) {
    if ((string) ($appliedRow['new_type'] ?? '') === 'Ny lead') {
        $leadRows++;
    }
}
pb(($apply['dry'] ?? 1) === 0 && $leadRows === 2 + count(array_filter($reclass, static fn (array $row): bool => $row['offer'] !== '')) && $calls === 0 && $graphs === 0, 'dry=0 byter typ utan mejl');
pb(count($store->listReminders(true)) === $remindersBefore, 'dry=0 skapar ingen påminnelse');
pb(jeab_inkorg_get((string) $lead['id'])['labels']['type'] === 'Ny lead', 'typen blev Ny lead');
pb(jeab_inkorg_get((string) $kwhLead['id'])['labels']['type'] === 'Ny lead', 'kWh-batteriet blev Ny lead');
pb(jeab_inkorg_get((string) $noise['id'])['labels']['type'] === 'Information', 'leverantören förblir Information');
pb((jeab_inkorg_get((string) $reclass['paneler']['id'])['labels']['offer'] ?? '') === 'sol' && (jeab_inkorg_get((string) $reclass['paneler']['id'])['labels']['type'] ?? '') === 'Ny lead', 'sparad batteriprodukt skrivs över till sol');
$orebroSaved = $orebroSavedId !== '' ? jeab_inkorg_get($orebroSavedId) : null;
pb(is_array($orebroSaved) && (string) ($orebroSaved['labels']['type'] ?? '') === 'Information' && (string) ($orebroSaved['labels']['reason'] ?? '') === 'utanför området', 'Örebro förblir Information med orsaken');
foreach ((array) ($apply['rows'] ?? []) as $applied) {
    pb(($applied['would_quote'] ?? true) === false && ($applied['would_send'] ?? true) === false && ($applied['would_remind'] ?? true) === false, 'apply-raden skickar inte');
}

echo "OK  purchase block\n";
