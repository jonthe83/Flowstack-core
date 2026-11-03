<?php

if (PHP_SAPI !== 'cli') { /* jeab-deny-direct */
    $jeabScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $jeabSelf = realpath(__FILE__);
    if ($jeabScript !== false && $jeabSelf !== false && $jeabScript === $jeabSelf) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

return <<<'QA'
## Lärda svarsregler (Sams träning 2026-10-17)
1. (#25172) Andra märken (Growatt m.fl.): säg inte "vi installerar inte X" – säg "vi rekommenderar sällan X", Sigenergy blir oftast bättre till likvärdig (ev. något högre) kostnad.
2. Sigenergy kan: köras helt separat, integreras via AC eller smartmätare, eller ta över solcellerna och ersätta befintlig växelriktare. Nämn alternativen.
3. Grönt avdrag-tak 50 000 kr: lös det med ett mindre batteri i år och prisvärd/enkel utökning nästa år – lova inte exakt belopp.
4. Ledtid order→installation ca 4 veckor; vill kunden tidigare: "vi kan ta en dialog om det".
5. Om kunden vill ha annat märke: vi kan installera det också om det är superviktigt, men säg vårt primära spår (Sigenergy).
6. Komplexa ärenden: be kunden slå en signal / ta dialog på telefon.
7. Avsluta frågesvaret med övergång till mallen: "Nedan är standardinformation gällande Sigenergy om vi ska gå i den riktningen."
8. SigenNeo Retrofit: kopplas bredvid befintlig växelriktare ELLER tar emot solcellerna direkt och ersätter den – utom SolarEdge, som måste sitta kvar.
9. Utökning SigenStor 9 kWh-modul: 1 modul 24 000 kr, 2 moduler 46 000 kr efter grön teknik. Skriv priset direkt i svaret.
10. Batterier över 20 kWh måste stå utomhus, i separat byggnad eller i egen brandcell – nämn när relevant.
11. Avsluta med 'Hör gärna av dig så kan vi diskutera mer i detalj.' hellre än att be om svar via mejl.
12. Easee Charge Up 8 490 kr efter grönt avdrag = installerad med laddkabel, krok och dynamisk lastbalansering (skriv ut det).
13. Tibber: vi rekommenderar Tibber som elbolag, men oftast fördel med samma fabrikat på lastbalansering som laddaren.
14. Ledtid elbilsladdare: oftast någon vecka efter order (batteri/sol ca 4 veckor).
15. Laddbox-avslut: 'Hör gärna av dig så kan vi diskutera mer i detalj om vilken laddare som passar dina behov bäst.'
16. Sigenergy-stack max 6 platser (54 kWh med 9 kWh-moduler). DC-laddare upptar en plats → max 5 moduler då.
17. Webbshop-leverans 19 900 kr = orten ligger utanför arbetsområdet (avsiktlig spärr mot för långa avstånd). Installation ingår annars alltid.
18. NYA SigenNeo-priser (kundpris efter grön teknik, 8 kW växelriktare), ersätter gamla erbjudanden inom kort: 7,5 kWh 39 500 | 15 kWh 55 500 | 19,5 kWh 65 500 | 22,5 kWh 67 500 | 30 kWh 81 000 | 37,5 kWh 93 000. Utökning senare: första 7,5 kWh 19 000, andra 7,5 kWh 17 000.
19. Flera växelriktare på olika platser = utmaning → föreslå telefondialog om lösning, lova inte teknisk lösning i mejl.
20. Vill kunden ha större växelriktare än standard (8 kW): notera önskemålet och säg att vi tar dialogen på telefon om vad som passar bäst.
21. Grönt avdrag gäller per person och år – kan användas för flera fastigheter. Vi är inget elbolag; man behöver inte vara elkund. Längre bort: be om adresser, mätarskåp på fasad eller kabeldragning in, bilder.
22. Malmö har SAMMA priser som Järfälla (t.ex. SigenStor-modul 24 000 / två 46 000). Använd inte Kims äldre 20 000-pris.
23. Platsbesök: normalt åker vi inte ut före installation. Efter telefonsamtal kan vi åka ut om det är klokt – vi är elektriker som åker ut vid osäkerhet om elen, inte säljare som bygger relation/tjatar. Säg det i den tonen.
24. Säg aldrig nej till det kunden ber om. Easee Charge Max: kan installeras om en specifik funktion gör den prisvärd för kunden, men i regel inte värt merkostnaden 2026. Priset går ner 2027 när Max ersätter Charge Up vid årsskiftet.
25. BRF, samfälligheter och företag som har eller ska ha fler än en laddare: ingen standardinformation. Kort svar: tack, vi hjälper gärna, men jag behöver prata med dig i telefon först – slå mig en signal så ringer jag upp om jag inte kan svara direkt. (Komplext/utanför standarderbjudandet.)
26. Föreslå ALDRIG SigenNeo 19,5 kWh – svindyr per kWh. Vid ~18 kWh: föreslå 15 kWh (55 500) eller 22,5 kWh (67 500, bara 2 000 kr mer än 19,5).
27. Laddkabel Easee: standard 7 m ingår. 10 m kostar 500 kr extra och är en trevlig kabel (föreslå 10 m när 7 m är för kort, även om kunden frågar om 9 m). 15 m är tjock och tung – bara i nödfall, avråd i vanliga fall.
28. Sigenergy EV AC 11 kW: 8 500 kr efter grönt avdrag inkl installation och 5 m fast kabel (kapsling +1 000 kr vid behov). Sigenergy DC 12 kW: 17 000 kr (tar en plats i stacken; rättat av Sam – inte 17 kW). Kund med befintligt Sigenergy → Sigenergy-laddare är naturligt val. (Bekräftat av Sam 2026-10-17.)
29. Nämn INTE grönt-avdrag-taket 50 000 kr/person om inte kunden själv säger att hen är ensam ägare. Oftast två ägare på ett hus. (Skärper regel 3: dela upp i år/nästa år bara när kunden är ensam ägare eller själv tar upp taket.)
30. Fråga ALDRIG vilket märke kunden har på växelriktaren. Ev. kort nämna: har de SolarEdge måste den vara kvar och solcellerna kan inte anslutas direkt till vår växelriktare – men sällan relevant att reda ut i tidigt skede.
31. Ensam ägare: grönt avdrag max 50 000 kr/år motsvarar ca 53 000 kr kundpris totalt (efter avdrag). Laddbox räknas också mot taket. Vill kunden ha batteri + laddare i år → börja med 7,5 kWh (39 500) + laddare (8 500) = 48 000, bygg ut batteriet nästa år.
32. Sol: nämn inte 'från 94 000 kr' när rekommendationen är större (t.ex. ~30 paneler) – priset motsäger rådet. Lova aldrig att vi ska göra något och återkomma (satellitbild, alternativ osv). Locka i stället kunden att ringa oss för att ta dialogen vidare (t.ex. 'flera bra vägar att gå – ring mig så går vi igenom vad som passar er bäst').
33. Stort batteri, mer prisvärt än Sigenergy: Fox ESS 63 kWh med 15 kW hybridväxelriktare, 101 500 kr (installerat efter grön teknik, webbshop-pris). Föreslå när kunden vill ha ~40 kWh+ (t.ex. 40–50 kWh).
34. Märken: vi REKOMMENDERAR Sigenergy. Fox ESS är ett alternativ vi kan installera. Solis (med Dyness och Pylontech) är SÄLJSTOPPAT – de levde inte upp till våra förväntningar. Säg inte att vi kan installera Solis/Dyness/Pylontech (undantag från regel 5).
35. Frågesvaret skrivs alltid FÖRST, därefter följer alltid den vanliga offertmallen (sol kopt/eget, Roberto batteri, Elin laddbox, Kim Malmö) med bifogad offert. Undantag: BRF/företag med flera laddare (regel 25) och fall som danska kunden (ingen offert möjlig).
36. Ö-drift (fungera vid strömavbrott) tillkommer med 30 000 kr. Hus med pool köper oftast drygt 2 000 kWh i maj/juni.
37. Finansiering: vi erbjuder räntefri avbetalning på 3 år, men då till ett högre pris. Gäller både solceller och enbart batteri. Hitta inte på påslaget – säg att priset blir något högre och ta detaljer på telefon.
38. Kund som köpt eget material (paneler/montage): vi monterar gärna, normalt 1 490 kr/h, men försöker göra paketpris om sol+batteri görs samtidigt. Grönt avdrag blir krångligare/dyrare när material köpts separat.
39. Ange aldrig antal MPPT på Sigenergy (modeller har 2, 3 eller 4). Säg 'flera MPPT, så varje taksida får en egen'. Öst-väst: båda sidor smart mest för att få plats; med batteri spelar väderstreck mindre roll; seriekoppla per taksida. Nybygge: samla ventilationshuvar/skorstenar/stegar i takkant, helst ena sidan.
QA;
