<?php

declare(strict_types=1);

/** Rensning av riktiga leadformat, med påhittade kunduppgifter. Originalet rörs inte. */

$tmp = sys_get_temp_dir() . '/jeab-clean-real-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cr(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$clean = static function (string $from, string $body): string {
    return jeab_inkorg_clean_message(['from' => $from, 'from_name' => 'Avsändare', 'body' => $body]);
};

$generic = "Hej Anna\u{200B} Svensson,\u{00A0}\u{00A0}vi vill ha batteri.\n"
    . "Adress: Testvägen 1 <https://trk.example/click?utm_source=x> och fb.1.AbCdEfGh1234.\n"
    . "Ring <tel:+46701234567> eller <mailto:kund.b@example.invalid>.\n"
    . "Spårning EAIaIQobChMI12345678 och gclid=CjwKCAjw12345.\n"
    . "E-post: kund.b@example.invalid\n";
$g = $clean('kund.b@example.invalid', $generic);
cr(str_contains($g, 'Anna Svensson') && str_contains($g, 'Testvägen 1') && str_contains($g, 'kund.b@example.invalid') && str_contains($g, 'batteri'), 'generiskt: namn, adress, e-post och fri text kvar');
cr(!str_contains($g, 'https://') && !str_contains($g, '<tel:') && !str_contains($g, '<mailto:') && !str_contains($g, 'fb.1.') && !str_contains($g, 'EAIaIQ') && !str_contains($g, 'gclid') && !str_contains($g, "\u{200B}") && !str_contains($g, "\u{00A0}"), 'generiskt: länkar, spårnings-id och osynliga tecken borta');

$wix = "Kan du inte se meddelandet? Visa i en webbläsare\n"
    . "View Submissions\n"
    . "Namn: Sara Malmö\n"
    . "E-post: sara.malmo@gmail.comKontakt Formulär\n"
    . "Telefon: 070-555 66 77\n"
    . "Adress: Malmövägen 4, 211 20 Malmö\n"
    . "Meddelande: Vi vill ha batteri till radhuset.\n"
    . "Det här mejlat har skickats som en avisering från din sajt.\n";
$w = $clean('info@wix-forms.com', $wix);
cr(str_contains($w, 'Sara Malmö') && str_contains($w, 'sara.malmo@gmail.com') && str_contains($w, '070-555 66 77') && str_contains($w, 'Malmövägen 4') && str_contains($w, 'batteri till radhuset'), 'Wix: kunduppgifter och fri text kvar');
cr(!str_contains($w, 'Visa i en webbläsare') && !str_contains($w, 'View Submissions') && !str_contains($w, 'avisering') && !str_contains($w, 'gmail.comKontakt'), 'Wix: svensk krom och ihopklistrad formulärrubrik borta');

$weply = "Namn: Nora Alm\nE-post: nora.alm@gmail.com\nTelefon: 070-222 11 00\nAdress: Almgatan 3, 176 74 Järfälla\n"
    . "Nora Alm: Hej, jag vill ha offert på solceller.\n"
    . "Alex Agent, Weply\n"
    . "Du fick det här e-mailet eftersom du chattade med oss.\n"
    . "ID# 4412\n"
    . "Kontakta oss 040-12 34 56\n";
cr(jeab_lead_platform('noreply@weply.dk') === 'weply.dk', 'Weply.dk är en plattform');
$wp = $clean('noreply@weply.dk', $weply);
cr(str_contains($wp, 'Nora Alm') && str_contains($wp, 'nora.alm@gmail.com') && str_contains($wp, 'Almgatan 3') && str_contains($wp, 'jag vill ha offert på solceller'), 'Weply: kunddata och kundens chattrad kvar');
cr(!str_contains($wp, 'Alex Agent') && !str_contains($wp, 'e-mailet eftersom') && !str_contains($wp, 'ID#') && !str_contains($wp, 'Kontakta oss'), 'Weply: agentrad, sidfot, id och växel borta');

$lead = "Namn: Bo Ek\nE-post: bo.ek@gmail.com\nTelefon: 073-555 12 12\nAdress: Ekvägen 4, 177 31 Järfälla\nProdukt: Solceller\n"
    . "Meddelande: Hur många paneler får plats på garaget?\n"
    . "leadkit-lead-id: abc-1 leadkit-field-namn: Bo\n"
    . "[Leadkit Inbox]\n"
    . "Hantera i Leadkit Inbox→\n"
    . "Färska leads förfaller fort. Ring inom en timme.\n"
    . "Ring<tel:+464012345> E-post<mailto:bo.ek@gmail.com>\n";
$lk = $clean('leads@leadkit.com', $lead);
cr(str_contains($lk, 'Bo Ek') && str_contains($lk, 'bo.ek@gmail.com') && str_contains($lk, 'Ekvägen 4') && str_contains($lk, 'Solceller') && str_contains($lk, 'paneler får plats'), 'Leadkit: kundfält och fri text kvar');
cr(!str_contains($lk, 'leadkit-lead-id') && !str_contains($lk, 'Leadkit Inbox') && !str_contains($lk, 'Färska leads') && !str_contains($lk, '<tel:') && !str_contains($lk, '<mailto:') && !preg_match('/^Ring\s+E-post/mu', $lk), 'Leadkit: krom och tel/mailto-rad borta');
$nu = $clean('lead@solceller.nu', $lead);
cr(str_contains($nu, 'Bo Ek') && str_contains($nu, 'paneler får plats') && !str_contains($nu, 'leadkit-field') && !str_contains($nu, 'Färska leads'), 'Solceller.nu: samma leadkit-krom tas bort');

$drip = "[Drip Logo]\nNamn: Vera Hitta\nE-post: vera.hitta@gmail.com\nTelefon: 076-333 22 11\nAdress: Hittavägen 2, 176 70 Järfälla\nProdukt: Solceller med batteri\n"
    . "Meddelande: Taket är 40 kvadratmeter.\n"
    . "https://u123.ct.sendgrid.net/ls/click?upn=fake\n"
    . "Lycka till! Mvh Gustav & Alex / Hitta-Solceller.se\n"
    . "To make sure you keep getting these emails, add us.\n"
    . "401 N Washington Ave Suite 500\n"
    . "Minneapolis, MN 55401\n";
$d = $clean('notifications@drip.com', $drip);
cr(str_contains($d, 'Vera Hitta') && str_contains($d, 'vera.hitta@gmail.com') && str_contains($d, 'Hittavägen 2') && str_contains($d, '40 kvadratmeter') && str_contains($d, 'Solceller med batteri'), 'Drip: kunddata och fri text kvar');
cr(!str_contains($d, 'Drip Logo') && !str_contains($d, 'sendgrid') && !str_contains($d, 'Gustav') && !str_contains($d, 'keep getting') && !str_contains($d, 'Washington') && !str_contains($d, 'Minneapolis'), 'Drip: logo, sendgrid, signatur och Minneapolis borta');

$hem = "Namn: Pia Holm\nAdress: Hemvägen 2https://mandrillapp.com/track/click/abc?id=1&gclid=CjwKCAjw999\nE-post: pia.holm@gmail.com\nTelefon: 070-999 88 77\nProdukt: Batteri\nMeddelande: Vi vill ha ett batteri på 10 kWh.\n"
    . "https://www.wix.com/store/order/1001\n";
$h = $clean('lead@hemsol.se', $hem);
cr(str_contains($h, 'Pia Holm') && str_contains($h, 'Hemvägen 2') && str_contains($h, 'pia.holm@gmail.com') && str_contains($h, '10 kWh'), 'Hemsol: adress och fri text kvar när spårningslänken satt ihopklistrad');
cr(!str_contains($h, 'mandrillapp.com') && !str_contains($h, 'wix.com/store') && !str_contains($h, 'gclid'), 'Hemsol och Wix Stores: spårningslänkar borta');

$wixMalmo = "A site visitor just submitted your form: Kontakt Formulär on Demo Energi Malmö\u{200E}<https://malmo.example.test/so/tr/abc123>\n"
    . "Namn: Sara Malmö\n"
    . "E-post: sara.malmo@gmail.com\n"
    . "Telefon: 070-555 66 77\n"
    . "Meddelande: Sigenergy stor 18kwh och elbilsladdare\n";
$wm = $clean('info@wix-forms.com', $wixMalmo);
cr(str_contains($wm, 'Produkt: Kontakt Formulär on Demo Energi Malmö') && str_contains($wm, 'Sigenergy stor 18kwh'), 'Wix Malmö: produktfältet behåller formulärnamnet');
cr(!str_contains($wm, 'https://') && !str_contains($wm, '<') && !str_contains($wm, "\u{200E}"), 'Wix Malmö: vinkelparenteslänk och U+200E är borta ur produktfältet');

$chat = "Namn: Nora Alm\nE-post: nora.alm@gmail.com\nTelefon: 070-222 11 00\nAdress: Almgatan 3, 176 74 Järfälla\n\n"
    . "Hej, jag vill ha offert på solceller.\n\n"
    . "Nora Alm\n\n"
    . "Vi tar fram en offert redan i veckan.\n"
    . "Den skickas på fredag.\n"
    . "Ella, Weply\n\n"
    . "Nora Alm: Vad kostar batteri med 38 kw?\n"
    . "Det beror på huset.\n"
    . "Jag återkommer med ett pris.\n"
    . "Ella, Weply\n"
    . "Den här chatten bemannas av människor och inte av robotar.\n"
    . "Vad händer nu?\n"
    . "Ring leadet!\n"
    . "Om du inte är den avsedda mottagaren av det här meddelandet ber vi dig radera det och meddela oss.\n"
    . "Weply AB\nOrg.nr 559000-0001\n";
foreach (['noreply@weply.dk', 'support@weply.se'] as $from) {
    $c = $clean($from, $chat);
    cr(str_contains($c, 'Nora Alm') && str_contains($c, 'nora.alm@gmail.com') && str_contains($c, 'offert på solceller') && str_contains($c, 'Vad kostar batteri med 38 kw'), $from . ': kundens rader kvar');
    cr(!str_contains($c, 'Vi tar fram') && !str_contains($c, 'fredag') && !str_contains($c, 'Det beror') && !str_contains($c, 'återkommer') && !str_contains($c, 'Ella') && !str_contains($c, 'bemannas') && !str_contains($c, 'Ring leadet') && !str_contains($c, 'avsedda mottagaren'), $from . ': agentrader, signatur och disclaimer borta');
}

$chatRun = "Namn: Nora Alm\nE-post: nora.alm@gmail.com\nTelefon: 070-222 11 00\nAdress: Almgatan 3, 176 74 Järfälla\n\n"
    . "Nora Alm\n\n"
    . "Hej jag vill ha ett batteri\n\n"
    . "Nora Alm\n\n"
    . "Hej!\n\n"
    . "Det ska vi hjälpa dig med!\n\n"
    . "Jens, Weply\n\n"
    . "Vi har villa i Järfälla\n\n"
    . "Nora Alm\n\n"
    . "Jag förstår!\n\n"
    . "Kanon!\n\n"
    . "Jens, Weply\n";
$crun = $clean('support@weply.se', $chatRun);
cr(str_contains($crun, 'Hej jag vill ha ett batteri') && str_contains($crun, 'Vi har villa i Järfälla') && str_contains($crun, 'nora.alm@gmail.com') && preg_match_all('/^Nora Alm$/mu', $crun) === 1, 'Weply .se: kundrader kvar och namnraden en gång när varje rad har blankrad');
cr(!str_contains($crun, 'Hej!') && !str_contains($crun, 'hjälpa dig') && !str_contains($crun, 'Jag förstår') && !str_contains($crun, 'Kanon') && !str_contains($crun, 'Jens'), 'Weply .se: varje agentrad före «Jens, Weply» är borta');

$leadLive = "Nytt lead från Solceller\u{200E}.nu\n"
    . "Nytt lead från Solceller.se\n"
    . "Namn: Bo Ek\nE-post: bo.ek@gmail.com\nTelefon: 073-555 12 12\nAdress: Ekvägen 4, 177 31 Järfälla\n"
    . "Meddelande: Hur många paneler\u{200F}\u{2060}\u{180E} får plats på garaget?\n"
    . "Vad är Leadkit Inbox?\n"
    . "Här ser du alla leads i dashboarden.\n"
    . "Få pushnotiser direkt\n"
    . "Ladda ner\n"
    . "[App Store] [Google Play]\n";
$ll = $clean('lead@solceller.nu', $leadLive);
cr(substr_count($ll, 'Nytt lead från Solceller') === 1 && str_contains($ll, 'Bo Ek') && str_contains($ll, 'paneler får plats') && !str_contains($ll, "\u{200E}") && !str_contains($ll, "\u{200F}"), 'Solceller.nu: en rubrik, osynliga tecken borta');
cr(!str_contains($ll, 'Leadkit Inbox') && !str_contains($ll, 'pushnotiser') && !str_contains($ll, 'Ladda ner') && !str_contains($ll, 'App Store') && !str_contains($ll, 'Google Play') && !str_contains($ll, 'dashboard'), 'Leadkit: allt från Inbox-rubriken och nedåt är borta');

$hemFoot = "Namn: Pia Holm\nE-post: pia.holm@gmail.com\nTelefon: 070-999 88 77\nAdress: Hemvägen 2, 176 70 Järfälla\nMeddelande: Vi vill ha ett batteri på 10 kWh.\n\n"
    . "Hemsol AB\nkontakt@hemsol.se\n08-123 45 67\nDrottninggatan 32\n111 51 Stockholm\n";
$hf = $clean('lead@hemsol.se', $hemFoot);
cr(str_contains($hf, 'Pia Holm') && str_contains($hf, 'pia.holm@gmail.com') && str_contains($hf, '070-999 88 77') && str_contains($hf, 'Hemvägen 2') && str_contains($hf, '10 kWh'), 'Hemsol: kundens kontakt och text kvar');
cr(!str_contains($hf, 'kontakt@hemsol.se') && !str_contains($hf, '08-123') && !str_contains($hf, 'Drottninggatan') && !str_contains($hf, 'Hemsol AB'), 'Hemsol: plattformens eget kontaktblock borta');

$hemBare = "Namn: Pia Holm\nE-post: pia.holm@gmail.com\nTelefon: 070-999 88 77\nAdress: Hemvägen 2, 176 70 Järfälla\nMeddelande: Vi vill ha ett batteri på 10 kWh.\n\n"
    . "E-post\nkontakt@hemsol.se\nTelefon\n0812132599\nAdress\nDrottninggatan 32\n111 51 Stockholm\n";
$hb = $clean('lead@hemsol.se', $hemBare);
cr(str_contains($hb, 'Pia Holm') && str_contains($hb, 'pia.holm@gmail.com') && str_contains($hb, '070-999 88 77') && str_contains($hb, 'Hemvägen 2') && str_contains($hb, '10 kWh') && preg_match('/^E-post:/mu', $hb) === 1, 'Hemsol: kundens egna fält kvar när sidfoten har nakna etiketter');
cr(!str_contains($hb, 'kontakt@hemsol.se') && !str_contains($hb, '0812132599') && !str_contains($hb, 'Drottninggatan') && !str_contains($hb, '111 51') && !preg_match('/^E-post$/mu', $hb) && !preg_match('/^Telefon$/mu', $hb) && !preg_match('/^Adress$/mu', $hb), 'Hemsol: naken sidfot med E-post, Telefon och Adress borta');

$hemTail = "Namn: Pia Holm\nE-post: pia.holm@gmail.com\nTelefon: 070-999 88 77\nAdress: Hemvägen 2, 176 70 Järfälla\nMeddelande: Vi vill ha ett batteri på 10 kWh.\n\n"
    . "[Hemsol]\n\n"
    . "HemSol Group AB\n\n"
    . "Kontakta oss\n\n"
    . "E-post\n\n"
    . "kontakt@hemsol.se\n\n"
    . "Telefon\n\n"
    . "0812132599\n\n"
    . "Adress\n\n"
    . "Drottninggatan 32\n\n"
    . "111 51 Stockholm\n\n"
    . "Kundomdömen\n\n"
    . "Se vad våra kunder säger\n\n"
    . "★ Trustpilot\n\n"
    . "[Hemsols betyg på Trustpilot]\n\n"
    . "[TrustScore]\n";
$ht = $clean('lead@hemsol.se', $hemTail);
cr(str_contains($ht, 'Pia Holm') && str_contains($ht, 'pia.holm@gmail.com') && str_contains($ht, '070-999 88 77') && str_contains($ht, 'Hemvägen 2') && str_contains($ht, '10 kWh') && preg_match('/^E-post:/mu', $ht) === 1, 'Hemsol: kundfält kvar ovanför den riktiga sidfoten');
cr(!str_contains($ht, '[Hemsol]') && !str_contains($ht, 'HemSol Group') && !str_contains($ht, 'Kontakta oss') && !str_contains($ht, 'kontakt@hemsol.se') && !str_contains($ht, '0812132599') && !str_contains($ht, 'Drottninggatan') && !str_contains($ht, '111 51') && !str_contains($ht, 'Kundomdömen') && !str_contains($ht, 'kunder säger') && !str_contains($ht, 'Trustpilot') && !str_contains($ht, 'TrustScore') && !preg_match('/^E-post$/mu', $ht), 'Hemsol: kontaktblock och Trustpilot-svans borta till slutet');

$dripLines = "Namn: Vera Hitta\nE-post: vera.hitta@gmail.com\nTelefon: 076-333 22 11\nAdress: Hittavägen 2, 176 70 Järfälla\nMeddelande: Taket är 40 kvadratmeter.\n"
    . "Lycka till!\nMvh\nGustav & Alex\nHitta-Solceller.se\n";
$dl = $clean('notifications@drip.com', $dripLines);
cr(str_contains($dl, 'Vera Hitta') && str_contains($dl, '40 kvadratmeter') && !str_contains($dl, 'Lycka till') && !preg_match('/^Mvh$/mu', $dl) && !str_contains($dl, 'Gustav') && !str_contains($dl, 'Hitta-Solceller'), 'Drip: signaturen borta även på egna rader');

$tokens = "Namn: Ida Spår\nE-post: ida.spar@gmail.com\nMeddelande: Vi vill ha laddbox.\n"
    . "gclid: CjwKCAjw1234567890abcdef\n"
    . "fbclid: Cj0KCQjwABCDEFGHIJKLMN\n"
    . "wbraid: EAIaIQobChMI99887766\n"
    . "fb.1.IwAR3abcdefghijk\n"
    . "/laddbox\n"
    . "utm_source: nyhetsbrev\n";
$tk = $clean('ida.spar@gmail.com', $tokens);
cr(str_contains($tk, 'Ida Spår') && str_contains($tk, 'laddbox') && str_contains($tk, 'ida.spar@gmail.com'), 'spårning: kundtexten kvar');
cr(!str_contains($tk, 'CjwKCAj') && !str_contains($tk, 'Cj0KCQ') && !str_contains($tk, 'EAIaIQ') && !str_contains($tk, 'fb.1.') && !str_contains($tk, 'gclid') && !str_contains($tk, 'fbclid') && !str_contains($tk, 'wbraid') && !str_contains($tk, 'utm_') && !preg_match('#^/laddbox$#mu', $tk), 'spårning: klick-id, utm och lös sökväg borta');

$order = "Ny order från butiken\nNamn: Kim Order\nE-post: kim.order@gmail.com\nTelefon: 070-404 11 22\nAdress: Ordervägen 8, 176 70 Järfälla\nMeddelande: Vi vill ha ett batteri.\nAvsändare: info@stores-emails.com\n";
cr(jeab_lead_platform('info@stores-emails.com') === 'stores-emails.com', 'stores-emails.com är en plattform');
$oc = jeab_lead_customer(['from' => 'info@stores-emails.com', 'from_name' => 'Wix Stores', 'body' => $order]);
cr($oc['email'] === 'kim.order@gmail.com' && $oc['name'] === 'Kim Order' && $oc['platform'] === 'stores-emails.com', 'Wix Stores: kunden läses ur ordertexten');
$os = $clean('info@stores-emails.com', $order);
cr(str_contains($os, 'Kim Order') && str_contains($os, 'kim.order@gmail.com') && str_contains($os, 'batteri') && !str_contains($os, 'info@stores-emails.com'), 'Wix Stores: avsändaradressen följer inte med i texten');

$previewDir = '/opt/cursor/artifacts';
if (!is_dir($previewDir)) {
    mkdir($previewDir, 0775, true);
}
$cards = [
    'Wix Malmö' => $wm,
    'Weply' => $clean('noreply@weply.dk', $chat),
    'Weply .se med blankrad' => $crun,
    'Solceller.nu' => $ll,
    'Hemsol' => $hf,
    'Hemsol naken sidfot' => $hb,
    'Hemsol Trustpilot' => $ht,
    'Drip' => $dl,
    'Spårning' => $tk,
    'Wix Stores' => $os,
];
$html = '<!doctype html><meta charset="utf-8"><title>Rensade leads</title><style>body{font:16px/1.45 sans-serif;margin:24px;background:#f6f4ef;color:#1c1915}pre{white-space:pre-wrap;background:#fff;border:1px solid #ddd;padding:12px 14px}section{margin:0 0 28px}</style><h1>Rensade förhandsvisningar</h1>';
foreach ($cards as $title => $text) {
    $html .= '<section><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><pre>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</pre></section>';
}
file_put_contents($previewDir . '/inkorg-cleaned-previews.html', $html);

echo "inkorg clean real: ok\n";
