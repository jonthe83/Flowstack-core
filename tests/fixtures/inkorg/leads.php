<?php

declare(strict_types=1);

/**
 * Påhittade leadmejl. Samma standardtext i varje källa, olika kunduppgifter.
 * @return array<string, array{from: string, from_name: string, subject: string, body: string, reply_to?: string}>
 */
return [
    'wix' => [
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär JEAB',
        'subject' => 'JEAB Batteri lead',
        'body' => <<<'TXT'
A site visitor just submitted your form Batteri lead

Submission summary:

Namn:

Anna Svensson

Adress:

Batterivägen 12

Telefon:

070-123 45 67

E-post:

kund.b@example.invalid

Meddelande:

Taket är mot sydväst och vi vill ha batteri också.

You can view this submission in your site dashboard.
This email was sent via Wix. Create your own free website on Wix.com.
Don't want to receive these emails? Unsubscribe: https://www.wix.com/unsubscribe?token=fake
TXT,
    ],
    'wix-html' => [
        'from' => 'crm@crm.wix.com',
        'from_name' => 'Wix CRM',
        'subject' => 'New form submission',
        'body' => <<<'HTML'
<html><body>
<style>.x{color:#999}</style>
<p>A site visitor just submitted your form Solceller lead</p>
<p>Submission summary:</p>
<table>
<tr><td>Namn</td><td>Erik Lind</td></tr>
<tr><td>Adress</td><td>Lindvägen 4, 177 32 Järfälla</td></tr>
<tr><td>Telefon</td><td>073-555 01 02</td></tr>
<tr><td>E-post</td><td><a href="mailto:erik.lind@gmail.com">erik.lind@gmail.com</a></td></tr>
</table>
<p>Meddelande:</p>
<p>Vi har ett platt tak och vill ha en offert.</p>
<p>You can view this submission in your dashboard.</p>
<p><a href="https://www.wix.com/track?utm_source=crm">https://www.wix.com/track?utm_source=crm</a></p>
<p>Powered by Wix. Privacy policy. Unsubscribe</p>
</body></html>
HTML,
    ],
    'weply' => [
        'from' => 'support@weply.se',
        'from_name' => 'Weply',
        'subject' => 'Ny förfrågan via Weply',
        'body' => <<<'TXT'
Hej!

Du har fått en ny förfrågan via Weply.

Namn: Nora Weply
E-post: nora.weply@gmail.com
Telefon: 070-222 11 00
Adress: Weplyvägen 3, 176 74 Järfälla
Produkt: Solceller
Meddelande:
Vi vill ha solceller på villan, tak mot söder.

Detta är en notis från Weply. Svara inte på detta mejl.
https://weply.se/leads/1001
Avregistrera notiser: https://weply.se/unsubscribe?u=1
Weply AB
Org.nr 559000-0001
Integritetspolicy
TXT,
    ],
    'brabygg' => [
        'from' => 'leads@brabyggfirmor.se',
        'from_name' => 'Bra Byggfirmor',
        'subject' => 'Ny förfrågan från Bra Byggfirmor',
        'body' => <<<'TXT'
Ny förfrågan från Bra Byggfirmor

Namn: Pia Holm
Telefon: 073-444 55 66
E-post: pia.holm@gmail.com
Adress: Holmgatan 8, 176 76 Järfälla
Tjänst: Solceller
Beskrivning:
Vill ha offert på solceller och batteri innan vintern.

Meddelandet skickades via Bra Byggfirmor.
Logga in för att se förfrågan: https://brabyggfirmor.se/foretag/forfragan/1
Avsluta prenumeration
Bra Byggfirmor är en tjänst från exempelbolaget. Integritetspolicy. Alla rättigheter förbehållna.
TXT,
    ],
    'leadkit' => [
        'from' => 'leads@leadkit.com',
        'from_name' => 'Leadkit',
        'subject' => 'Ny lead',
        'body' => <<<'HTML'
<html><body>
<p>Ny lead från Leadkit</p>
<table>
<tr><td>Kundens namn</td><td>Bo Ek</td></tr>
<tr><td>Kundens e-post</td><td><a href="mailto:bo.ek@gmail.com">bo.ek@gmail.com</a></td></tr>
<tr><td>Kundens telefon</td><td>073-555 12 12</td></tr>
<tr><td>Kundens adress</td><td>Ekvägen 4, 177 31 Järfälla</td></tr>
<tr><td>Produkt</td><td>Solceller</td></tr>
</table>
<p>Meddelande:</p>
<p>Hur många paneler får plats på garaget?</p>
<p>Leadkit – fler leads till ditt företag</p>
<p>https://leadkit.com/app/leads/99</p>
<p>Unsubscribe: https://leadkit.com/unsubscribe?id=99</p>
</body></html>
HTML,
    ],
    'leadkit-support' => [
        'from' => 'support@leadkit.com',
        'from_name' => 'Leadkit',
        'subject' => 'Ny lead',
        'body' => <<<'TXT'
Ny lead från Leadkit

Namn: Sara Ek
E-post: sara.ek@gmail.com
Telefon: 070-222 33 44
Adress: Ekvägen 2, 176 70 Järfälla
Produkt: Batteri
Meddelande:
Kan ni titta på ett batteri till villan?

Leadkit – fler leads till ditt företag
https://leadkit.com/unsubscribe?id=100
TXT,
    ],
    'hitta' => [
        'from' => 'notify@drip.se',
        'from_name' => 'Hitta-Solceller',
        'subject' => 'Ny lead från Hitta-Solceller',
        'body' => <<<'TXT'
Hej Demo Energi Järfälla,

En ny lead från Hitta-Solceller.

Namn: Vera Hitta
E-post: vera.hitta@gmail.com
Telefon: 076-333 22 11
Adress: Hittavägen 2, 176 70 Järfälla
Produkt: Solceller med batteri
Meddelande:
Taket är 40 kvadratmeter.

Detta mejl skickades med Drip.
Avsluta prenumeration: https://www.getdrip.com/unsubscribe?fake=1
Hitta-Solceller.se · villkor · integritet
TXT,
    ],
    'solceller-nu' => [
        'from' => 'lead@solceller.nu',
        'from_name' => 'Solceller.nu',
        'subject' => 'Ny offertförfrågan',
        'body' => <<<'TXT'
Ny offertförfrågan – Solceller.nu

Namn: Maja Nu
E-post: maja.nu@gmail.com
Telefon: 070-888 11 22
Ort / adress: Nuvägen 5, 177 40 Järfälla
Vad gäller förfrågan: Solceller
Övrigt:
Vi vill ha batteri också till huset.

Du får detta mejl för att du är ansluten till Solceller.nu.
Klicka inte på länkarna i sidfoten.
https://solceller.nu/partner/lead/1
Avregistrera
TXT,
    ],
    'offerta' => [
        'from' => 'noreply@offerta.se',
        'from_name' => 'Offerta',
        'subject' => 'Nytt uppdrag via Offerta',
        'body' => <<<'TXT'
Hej,

Du har fått ett nytt uppdrag via Offerta.

Kundens namn: Olle Offert
E-post: olle.offert@gmail.com
Telefon: 070-888 77 66
Adress: Offertgatan 3, 176 74 Järfälla
Kategori: Solceller
Beskrivning från kunden:
Behöver offert innan vintern, huset är från 1978.

Offerta AB
Detta är ett automatiskt meddelande. Svara kunden direkt.
https://offerta.se/foretag/uppdrag/1
Avprenumerera från notiser
Integritetspolicy
TXT,
    ],
    'kund' => [
        'from' => 'maja.kund@gmail.com',
        'from_name' => 'Maja Kund',
        'subject' => 'Offert på solceller',
        'body' => <<<'TXT'
Hej!

Jag heter Maja Kund och bor på Kundvägen 9, 176 68 Järfälla.
Jag vill ha en offert på solceller till villan. Mitt nummer är 070-123 99 88.

Maja
maja.kund@gmail.com

Skickat från min iPhone
TXT,
    ],
];
