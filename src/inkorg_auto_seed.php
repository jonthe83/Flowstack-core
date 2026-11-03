<?php

declare(strict_types=1);

require_once __DIR__ . "/shield.php";
jeab_deny_if_direct(__FILE__);

/** Startmallar för automatiska offerter (från Kundtjänst-mallarna 2026-10-14). Redigeras på Regler-sidan. */
function jeab_auto_quote_seed(): array
{
    return array (
  'laddbox_jarfalla' => 'Hej

Kul att du frågat oss om installation av elbilsladdare, det hjälper jag dig gärna med.

Min favorit är Easee Charge Up: https://www.jarfalla.example.test/product-page/easee-elbilsladdare-stockholm

Vi har installerat över 5 000 st av den laddaren senaste 5 åren och den är extremt driftsäker och enkel att använda. Eftersom att vi installerat så många av just den så kan vi också ge bäst support på just den laddaren.

Dynamisk lastbalansering gör laddaren smart på det sätt att den kan ladda när elpriset är lågt eller när solen lyser eller sänka laddhastigheten för att skydda huvudsäkringarna.

Största anledningen att välja Easee Elbilsladdare är att den är nästintill 100% fungerande över tid och att både laddare och lastbalansering fungerar utan wifi (via 4G och lokal radiolänk).

Priser:
easee utan lastbalansering inkl. installation 5 990 kr
Rekommenderar dock vår superdeal där du får allt du behöver;
Elbilsladdare, lastbalansering, 7m laddkabel samt kabelhållare för 8 490 kr inkl. installation.
Priserna avser efter grön teknik.

Vad ingår?

Vi vänder på det...Det enda som kan tillkomma är om det behövs mer än 10m installationskabel (200:- per extra meter) eller om du ej har plats för säkringen för elbilsladdaren i din elcentral/mätarskåp (då behövs en kapsling som kostar 1000:-) annars ingår allt! inga dolda överraskningar!

Så länge det finns el i den huskropp vi ska installera på så behöver vi inte veta något i förväg. Och 19 av 20 installationer sker utan tillkommande kostnader. Våra kunder ger oss 4,9 av 5 i omdöme på google.

Är det något du är osäker så skicka lite bilder. Vill du bolla med den elektrikern som kommer installera hemma hos dig så slå mig en signal. Vill du ha en säljare som ringer och tjatar på dig så kan jag tyvärr inte hjälpa till  🙂

Elin
070-000 00 05
Elektriker
Demo Energi Järfälla',
  'batteri_jarfalla' => 'Hej

' . jeab_offer_batteri_neo_price_paragraph() . '

Vilken storlek på batteri är rätt för dig?

Även om batteriet även sparar dig pengar på vintern så är den största delen på sommarhalvåret. Ett batteri bör som minimum göra att ni inte behöver köpa el en typisk sommardag. Kolla hur mycket el ni köpt i maj eller juni på en månad (och dela med 30 dagar) eller per dag för att se hur stort batteri som behövs som absolut minimum. Sen rekommenderar jag att den siffran avrundas uppåt till närmsta 7,5 kWh modul.

Det är många som säljer Solis, Pylontech eller Dyness 15-20 kWh. Generellt är det något jag avråder ifrån. Men kanske är det rätt för just dig, läs i så fall min köpguide där jag går igenom det mesta jag brukar prata om på telefonen och framförallt det här med stödtjänster och budgetbatterier: Komplett köpguide med jämförelser, platskrav, prislistor och hjälp att räkna ut optimal storlek på batteri

Jag kommer försöka nå dig på telefon en gång. Men jag jobbar inte som säljare. Så att du ställer frågor på mail eller ringer mig är ett bra nästa steg.

Vi är bästa installatören i norra Stockholm när det gäller att montera solceller rätt så att garantierna gäller, batterier har färre moment att misslyckas med. Men jag vill ändå vara tydliga med att vi är ”lite dyrare, lite bättre”.  Varför installera med Demo Energi Järfälla: https://youtu.be/xj3DQUNQwlg?si=PwOzG3J9X6Xeyypp

Med ett batteri finns möjligheten att slippa köpa el mars till oktober. Ni slipper köpa el på vintern de timmarna med högst elpris. Enligt mina (slarviga) beräkningar bör batteriet betala sig på ca 8 år utan effektavgifter.

Är det möjligt installerar vi gärna batteriet i förråd, källare eller garage för att minimera kostnad för uppvärmning.

Batterier >20 kWh behöver installeras utomhus, i egen huskropp eller i egen brandcell.

Roberto
Elektriker
070-000 00 04
Demo Energi Järfälla',
  'batteri_malmo' => '',
  'laddbox_malmo' => '',
  'sol_jarfalla' => '(solmallen från offertflödet används)',
  'sol_malmo' => '',
);
}
