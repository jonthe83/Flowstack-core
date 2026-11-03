<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/*
 * Startdata för Inkorgens regler och listor. Skrivs EN gång till data/.private/inkorg/rules.json
 * och redigeras sedan på Regler-sidan. Utvärderaren (inkorg.php) läser bara data, aldrig den här filen.
 */

function jeab_inkorg_seed_lists(): array
{
    $l = fn(string $name, array $entries) => ['name' => $name, 'entries' => $entries];
    return [
        // Vilken mejlbox (Malmö/Järfälla) ett ärende hör till: ord i mottagaradressen. Help Scout-brevlådor känns igen på sitt id.
        'mejlbox-malmo' => $l('Mejlbox: Malmö', ['malmo', 'malmö', 'malmo-demo', 'Demo Energi Malmö']),
        'mejlbox-jarfalla' => $l('Mejlbox: Järfälla', ['jarfalla', 'järfälla', 'jarfalla-demo', 'Demo Energi Järfälla']),
        'hoj-prio' => $l('Höj prioritet ett steg (kundmejl)', ['strömavbrott', 'strömlös', 'ingen ström', 'vattenläcka', 'läcker vatten', 'häva', 'hävning', 'häver', 'avbryta köpet', 'installation imorgon', 'imorgon', 'i morgon', 'inom 48 timmar']),
        // Domäner som aldrig är köpintresse. Kända märken gäller även utan rad här. Listan lägger till fler.
        'lead-block' => $l('Lead: blockera domäner', ['huawei.com', 'scanmont.se', 'epax.se', 'solaredge.com', 'fronius.com', 'growatt.com', 'goodwe.com', 'easee.com', 'zaptec.com', 'chargeamps.com', 'laniakeaenergy.com', 'ecosolarcarport.com', 'ecopvcarport.com', 'felicitysolar.com', 'renonpower.com', 'conluxs.com', 'wwwebb.com', 'contractors1000.com', 'ifsek.se', 'qpsmedia.com']),
    ];
}

function jeab_inkorg_seed_rules(): array
{
    // En inbyggd, redigerbar regel. Klassningen av betalt/obetalt ligger i webshop.php.
    return [[
        'id' => 'builtin-webshop-order',
        'name' => 'Betald webbshoporder (Wix) är Order',
        'enabled' => true,
        'builtin' => true,
        'seed_top' => true,
        'match' => 'all',
        'conditions' => [
            ['field' => 'domain', 'op' => 'regex', 'value' => '/(?:^|\.)(?:stores-emails\.com|wix\.com)$/i'],
            ['field' => 'body', 'op' => 'regex', 'value' => '/\b(betalt|betald|paid)\b/iu'],
        ],
        'type' => 'Order',
        'action' => 'arkivera',
        'priority' => 4,
        'confidence' => 100,
    ]];
}

function jeab_inkorg_seed_tags(): array
{
    return ['test' => ['name' => 'Test'], 'service' => ['name' => 'Service'], 'servicejobb' => ['name' => 'Servicejobb'], 'sol' => ['name' => 'Sol'], 'batteri' => ['name' => 'Batteri'], 'elbilsladdare' => ['name' => 'Elbilsladdare'], 'skrap' => ['name' => 'Skräp'], 'support' => ['name' => 'Support'], 'restnoterad' => ['name' => 'Restnoterad/ej i lager']];
}

/** Service = support och befintliga kunder (Grok sätter typen utifrån instruktionen). */
function jeab_inkorg_seed_service_types(): array
{
    return ['Support', 'Bokning/ombokning'];
}

/** Osorterat visar inte service, information eller skräp (de har egna vyer). */
function jeab_inkorg_seed_unsorted_skip(): array
{
    return array_merge(jeab_inkorg_seed_service_types(), ['Information', 'Skräp']);
}

function jeab_inkorg_seed_views(): array
{
    $v = fn(string $id, string $name, array $f, string $owner = '', string $co = '') => ['id' => $id, 'name' => $name, 'owner' => $owner, 'company' => $co, 'filter' => $f + ['tag' => '', 'status' => [], 'type' => '', 'priority' => 0, 'review' => false, 'assignee' => '']];
    return [
        $v('v-mig', 'Tilldelade mig', ['assignee' => 'me', 'status' => ['open', 'waiting_customer', 'waiting_supplier']]),
        $v('v-otilldelade', 'Otilldelade', ['assignee' => 'none', 'status' => ['open']]),
        // Produktvyer. Ägare = öppnas som standard för den personen (ändras på Vyer). «ingen» = ingen produktetikett.
        $v('v-solceller', 'Solceller', ['tag' => 'sol', 'status' => ['open']], 'SamL', 'jarfalla'),
        $v('v-batteri-oppna', 'Batteri', ['tag' => 'batteri', 'status' => ['open']], 'Roberto', 'jarfalla'),
        // Malmö (Sam 14 okt): Sam W = solceller + okänt, Kim = Elbilsladdare + batteri.
        $v('v-malmo-jw', 'Sam W', ['tags_any' => ['sol', 'ingen'], 'types_not' => jeab_inkorg_seed_unsorted_skip(), 'status' => ['open']], 'SamW', 'malmo'),
        $v('v-malmo-kim', 'Kim', ['tags_any' => ['elbilsladdare', 'batteri'], 'status' => ['open']], 'Kim', 'malmo'),
        $v('v-elbilsladdare', 'Elbilsladdare', ['tag' => 'elbilsladdare', 'status' => ['open']]),
        $v('v-elbil-osorterat', 'Elbilsladdare + Osorterat', ['tags_any' => ['elbilsladdare', 'ingen'], 'types_not' => jeab_inkorg_seed_unsorted_skip(), 'status' => ['open']], 'Elin', 'jarfalla'),
        $v('v-osorterat', 'Osorterat', ['tags_any' => ['ingen'], 'types_not' => jeab_inkorg_seed_unsorted_skip(), 'status' => ['open']]),
        $v('v-servicekund', 'Service', ['types' => jeab_inkorg_seed_service_types(), 'status' => ['open']]),
        $v('v-allt', 'Allt', ['status' => ['open']]),
        $v('v-oppna', 'Öppna', ['status' => ['open']]),
        $v('v-leads', 'Leads – öppna', ['type' => 'Ny lead', 'status' => ['open']]),
        $v('v-service', 'Servicejobb – öppna', ['tag' => 'servicejobb', 'status' => ['open']]),
        $v('v-vantar', 'Väntar', ['status' => ['waiting_customer', 'waiting_supplier']]),
        $v('v-vantar-offert', 'Väntar på offert', ['status' => ['open'], 'awaiting_quote' => true]),
        $v('v-granska', 'Behöver granskas', ['review' => true, 'status' => ['open']]),
        $v('v-stangda', 'Stängda', ['status' => ['closed']]),
        $v('v-historik', 'Historik', ['historik' => true]),
        $v('v-skrap', 'Skräp', ['types' => ['Skräp']]),
        $v('v-information', 'Information', ['types' => ['Information'], 'status' => ['open']]),
        $v('v-papperskorg', 'Papperskorg', ['deleted' => true]),
    ];
}

/** Grok-instruktionen (redigeras på Regler och inställningar). */
function jeab_inkorg_seed_settings(): array
{
    return [
        'intent' => "Avgör vad avsändaren vill. All styrning står här; det finns inga fasta regler i koden.\n"
            . "- Testmejl: allt från demo.person@example.invalid är test. Typ Information, etikett Test.\n"
            . "- Köpta leads: mejl från HemSol (hemsol.se), Leadkit (leads@leadkit.com och support@leadkit.com), kalkylformuläret (form@kalkyl.jarfalla.example.test) och Bra Byggfirmor (brabyggfirmor.se) är köpta leadkällor och aldrig skräp. Weply (support@weply.se, weply.dk) är ett eget lead (lead=eget), inte en köpt leadsäljare, och aldrig skräp. Offerten går till kunden i chatten, aldrig till weply. De är alltid Ny lead (om det inte tydligt är en befintlig kund), även när mejlet låter som ett erbjudande till oss (t.ex. «er första förfrågan är gratis») eller gäller annat än våra produkter. Läs själva förfrågan och avgör produkten; lämna product tomt om det inte gäller solceller, batteri, elbilsladdare eller support.\n"
            . "- Produkt (fältet product, flera kommaseparerade går bra): solceller utan batteri → solceller; solceller med batteri → solceller,batteri; batteri till befintliga solpaneler → batteri; elbilsladdare/laddbox → elbilsladdare; hjälp med något vi redan levererat (fel, frågor om anläggning, app, garanti) → support. Lämna tomt om inget av detta. Gäller det support (även när kunden väljer support i ett formulär eller via en leadkälla) är typen Support, inte Ny lead.\n"
            . "- Erbjuder avsändaren oss något (försäljning, tjänster, SEO, leads, finansiering, samarbeten, produkter till oss) → Skräp. Nyhetsbrev, reklam, nätfiske och bedrägeri → Skräp.\n"
            . "- Köpintresse är alltid Ny lead: offert, pris, eller att någon vill köpa eller installera sol, batteri eller laddbox. Aldrig Information.\n"
            . "- Den som vill sälja eller samarbeta med oss, eller skriver till många mottagare, är inte Ny lead. Solcellscarport, återförsäljare, skrotköpare, varma leads och samarbete kring laddstolpar är skräp eller information.\n"
            . "- Test från qpsmedia, även kevin@qpsmedia, är inte Ny lead.\n"
            . "- En tråd som personal startat är inte Ny lead, även ett Re:-svar om installation av solceller.\n"
            . "- Överlåtelse, överlåta, sålt huset, ny ägare, flytta eller överföra Easee, laddbox eller laddaren, koppla bort från mitt konto och Easee-konto är support, inte Ny lead. Tillägg på en laddbox är också support.\n"
            . "- Järfälla tar bara Stockholmsområdet och Malmö bara Skåne. Örebro och liknande orter är inte Ny lead. Orsaken är utanför området.\n"
            . "- Nämns solceller, solpaneler, paneler, solanläggning, sol+batteri eller solceller samt, med eller och batteri i kundens text eller i produktfältet är product solceller och batteri sekundärt. HemSol- och Hitta-Solceller-mallens ämne, rubrik, tagg och mailbox räknas inte. Bara batteri, kWh eller lagring är batteri även från de källorna. Sammansättningar som slutar på batteri eller lagring, som solcellsbatteri och solcellslagring, och batteri till solceller är batteri. Källan ger solceller bara när kundtexten varken nämner batteri eller sol. Har redan solceller, befintliga solceller och vi har sol utan nya paneler är batteri.\n"
            . "- Epax, Renon Power, Sol Axel, Gozan och IFSEK är inte Ny lead när de erbjuder oss något.\n"
            . "- Ort och postnummer gäller. 1xx xx är Stockholm och 2xx xx är Skåne. Örebro och andra postnummer är inte Ny lead och markeras utanför området.\n"
            . "- Ber avsändaren om vår hjälp eller våra produkter (solceller, batteri, elbilsladdare, support) → kund eller lead: Ny lead om det är en ny förfrågan, annars Support (frågor, ärenden och klagomål från befintliga kunder) eller Bokning/ombokning. Signering och dokument → Information.\n"
            . "- Information till oss som inte kräver något (orderbekräftelser där produkter och leveranstider stämmer, kvitton, aviseringar) → Information.\n"
            . "- En betald webbshoporder (Wix, betalt) är typen Order, inte Information och inte lead. Obetald eller avbruten är Information.\n"
            . "- Leverantör, nätägare eller myndighet som ber oss göra något eller återkomma → Kräver åtgärd från oss.\n"
            . "- Är du osäker, sätt säkerhet under " . JEAB_INKORG_REVIEW_BELOW . ".",
        'product_tags' => ['solceller' => 'sol', 'batteri' => 'batteri', 'elbilsladdare' => 'elbilsladdare', 'support' => ''],
        'examples' => [],
    ];
}
