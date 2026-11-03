<?php

declare(strict_types=1);

/**
 * Wix-aviseringar för sol, batteri, laddbox och kontakta-oss.
 * Påhittade kunder. Samma gata i exemplen. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-wix-form-parse-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function wp(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static function (string $street, string $postal): array {
    $fold = function_exists('mb_strtolower') ? mb_strtolower($street, 'UTF-8') : strtolower($street);
    if (str_contains($fold, 'dimvägen 38') && str_contains($fold, 'järfälla')) {
        return ['postal_code' => '17770', 'city' => 'Järfälla'];
    }

    return [];
};

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 1,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$solar = <<<'TXT'
A site visitor just submitted your form: Solceller lead on Demo Energi Järfälla

Offert på din mail, ring oss om du vill, vi ringer inte och tjatar.:

Hej, vi vill ha en offert.

Namn:

Ada Solform

Adress och ort:

Dimvägen 38, 17770 Järfälla

E-post:

ada.solform@gmail.com

Telefon:

070-100 00 01
TXT;

$battery = <<<'TXT'
A site visitor just submitted your form: Batteri lead on Demo Energi Järfälla

Namn:

Bea Battform

Telefon:

070-100 00 02

Adress:

Dimvägen 38, 17770 Järfälla

E-post:

bea.battform@gmail.com

Välj vad du vill ha av oss i nästa steg:

Offert på batteri
TXT;

$chargerLines = <<<'TXT'
A site visitor just submitted your form: Ladbox lead on Demo Energi Järfälla‎<https://www.jarfalla.example.test/laddbox>

Namn:

Cia Laddform

Telefon:

070-100 00 03

Adress:

Dimvägen 38
17770
Järfälla

E-post:

cia.laddform@gmail.com

Välj vad du vill ha av oss i nästa steg:

Hjälp att välja rätt laddare för mig
TXT;

$chargerCity = <<<'TXT'
A site visitor just submitted your form: Ladbox lead

Namn:

Cia Laddform

Adress:

Dimvägen 38, Järfälla

E-post:

cia.laddform@gmail.com

Telefon:

070-100 00 03
TXT;

$support = <<<'TXT'
A site visitor just submitted your form: kontakta-oss on Demo Energi Järfälla‎<https://www.jarfalla.example.test/kontakta-oss>

Namn:

Disa Fraga

E-post:

disa.fraga@gmail.com

Telefon:

070-100 00 04

Adress:

Exempelvägen 4, Järfälla

Välj vad du vill ha information och mailoffert på?:

Support till befintliga kund

Meddelande:

Hej, jag har en fråga om installationen.
TXT;

$place = static function (string $body): array {
    $cust = jeab_lead_customer(['from' => 'info@wix-forms.com', 'from_name' => 'Formulär', 'body' => $body]);

    return jeab_inkorg_reminder_place_fields((string) ($cust['address'] ?? '')) + ['raw' => (string) ($cust['address'] ?? '')];
};

$solarOch = "Offert på din mail, ring oss om du vill, vi ringer inte och tjatar.:\n\nJa\n\nAdress och ort:\n\nDimvägen 38\n\nE-post:\n\nada.solform@gmail.com\n";
$ochPlace = $place($solarOch);
wp(($ochPlace['raw'] ?? '') === 'Dimvägen 38', 'sol: «Adress och ort» tar inte med ordet från etiketten, fick ' . ($ochPlace['raw'] ?? ''));

$solPlace = $place($solar);
wp(!str_contains(mb_strtolower($solPlace['address']), 'och,'), 'sol: adressen är bara gatans värde, fick ' . $solPlace['address']);
wp(str_contains($solPlace['address'], 'Dimvägen 38') && ($solPlace['postal'] ?? '') === '17770' && ($solPlace['ort'] ?? '') === 'Järfälla', 'sol: gata, postnummer 17770 och ort');

$batPlace = $place($battery);
wp(str_contains($batPlace['address'], 'Dimvägen 38') && ($batPlace['postal'] ?? '') === '17770', 'batteri: samma gata ger postnummer 17770');

$linePlace = $place($chargerLines);
wp(str_contains($linePlace['address'], 'Dimvägen 38') && ($linePlace['postal'] ?? '') === '17770' && ($linePlace['ort'] ?? '') === 'Järfälla', 'laddbox: postnummer på egen rad läses som på de andra formulären, fick ' . $linePlace['raw']);

$cityPlace = $place($chargerCity);
wp(($cityPlace['postal'] ?? '') === '17770' && str_contains($cityPlace['address'], 'Dimvägen 38'), 'laddbox: tomt postnummer fylls från gata och ort, fick ' . $cityPlace['raw']);

$unclassified = ['type' => 'Oklassad', 'action' => 'svara', 'priority' => 3, 'confidence' => 0, 'source' => 'none', 'reason' => 'Ingen modell kopplad.'];
$chargerLabels = jeab_inkorg_finish_labels($unclassified, ['from' => 'info@wix-forms.com', 'subject' => 'Ladbox lead', 'body' => $chargerLines]);
wp(($chargerLabels['type'] ?? '') === 'Ny lead' && ($chargerLabels['offer'] ?? '') === 'laddbox' && empty($chargerLabels['needs_review']), 'svaret laddare blir laddbox även utan modell');
$bare = jeab_inkorg_finish_labels($unclassified, [
    'from' => 'info@wix-forms.com',
    'body' => "A site visitor just submitted your form: Elbilsladdare\n\nNamn:\n\nCia Laddform\n",
]);
wp(($bare['type'] ?? '') === 'Oklassad' && ($bare['offer'] ?? 'laddbox') === '' && ($bare['needs_review'] ?? false) === true, 'elbilsladdare som formulärnamn sätter ingen produkt');

$supportMsg = ['from' => 'info@wix-forms.com', 'subject' => 'kontakta-oss', 'body' => $support];
wp(jeab_inkorg_form_is_existing_support($supportMsg), 'support till befintliga kund räknas som support');
$supportLabels = jeab_inkorg_finish_labels($unclassified, $supportMsg);
wp(($supportLabels['type'] ?? '') === 'Support' && ($supportLabels['intent'] ?? '') === 'support' && ($supportLabels['offer'] ?? '') === '', 'kontakta-oss får supportetiketten');
wp(jeab_inkorg_productless_form_skips_reminder($supportMsg), 'kontakta-oss skapar ingen påminnelse');
$kontakt = jeab_inkorg_finish_labels($unclassified, [
    'from' => 'info@wix-forms.com',
    'body' => "A site visitor just submitted your form: Kontakt Formulär on Demo Energi Järfälla\u{200E}<https://www.jarfalla.example.test/kontakta-oss>\n\nNamn:\n\nDisa Fraga\n\nMeddelande:\n\nHej.\n",
]);
wp(($kontakt['type'] ?? '') === 'Support', 'Kontakt Formulär på kontakta-oss blir Support');
$keptLead = jeab_inkorg_finish_labels(
    ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 100, 'intent' => 'kop', 'offer' => 'batteri'],
    ['from' => 'info@wix-forms.com', 'body' => "A site visitor just submitted your form: Kontakt Formulär\n\nNamn:\n\nEja Auto\n"]
);
wp(($keptLead['type'] ?? '') === 'Ny lead' && ($keptLead['offer'] ?? '') === 'batteri', 'satt Ny lead på Kontakt Formulär lämnas');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $body, string $subject) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@wix-form.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => $box,
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass header.from=wix-forms.com'],
    ]);
};

$beforeSent = count($GLOBALS['_SENT']);
$beforeRem = count(jeab_list_reminders(jeab_store(), true));
$sup = $ingest('support', $support, 'kontakta-oss');
$supAuto = jeab_auto_on_message((string) ($sup['id'] ?? ''));
$supRow = jeab_inkorg_get((string) ($sup['id'] ?? ''));
wp((string) (($supRow['labels']['type'] ?? '')) === 'Support', 'intaget kontakta-oss sparas som Support');
wp(count($GLOBALS['_SENT']) === $beforeSent && ($supAuto['sent'] ?? 1) === 0, 'kontakta-oss skickar ingen offert');
wp(count(jeab_list_reminders(jeab_store(), true)) === $beforeRem, 'kontakta-oss skapar ingen påminnelse');

$contactsFor = static function (string $email): array {
    $want = jeab_kontakt_email($email);
    $hits = [];
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row) || jeab_kontakt_row_email($row) !== $want) {
            continue;
        }
        $hits[] = $row;
    }

    return $hits;
};
$disaHits = $contactsFor('disa.fraga@gmail.com');
wp(count($disaHits) === 1, 'kontakta-oss skapar en kontakt, fick ' . count($disaHits));
$disaId = (string) ($disaHits[0]['id'] ?? '');
$supThread = jeab_inkorg_thread_meta((string) ($sup['thread_id'] ?? ''));
wp($disaId !== '' && (string) ($supThread['contact_id'] ?? '') === $disaId, 'ärendet pekar på kundens kontakt');

$supAgain = $ingest('support-igen', str_replace('Hej, jag har en fråga om installationen.', 'Hej igen.', $support), 'kontakta-oss');
$supAgainAuto = jeab_auto_on_message((string) ($supAgain['id'] ?? ''));
$disaAgain = $contactsFor('disa.fraga@gmail.com');
wp(count($disaAgain) === 1 && (string) ($disaAgain[0]['id'] ?? '') === $disaId, 'samma e-post återanvänder kontakten');
wp(count($GLOBALS['_SENT']) === $beforeSent && empty($supAgainAuto['sent']) && empty($supAgainAuto['quoted']), 'en andra kontakta-oss skickar ingen offert');
wp(count(jeab_list_reminders(jeab_store(), true)) === $beforeRem, 'en andra kontakta-oss skapar ingen påminnelse');

$testSupport = str_replace('disa.fraga@gmail.com', 'inkorg+fraga@example.test', $support);
$testIn = $ingest('support-test', $testSupport, 'kontakta-oss');
$testAuto = jeab_auto_on_message((string) ($testIn['id'] ?? ''));
$testHits = $contactsFor('inkorg+fraga@example.test');
wp(count($testHits) === 1 && (string) ($testHits[0]['id'] ?? '') !== $disaId, 'testadress får en egen kontakt');
wp(jeab_kontakt_row_email($testHits[0] ?? []) === 'inkorg+fraga@example.test', 'testkontaktens e-post är example-adressen');
wp(count($contactsFor('disa.fraga@gmail.com')) === 1, 'testadressen slås inte ihop med gmail-kunden');
wp(count($GLOBALS['_SENT']) === $beforeSent && ($testAuto['sent'] ?? 1) === 0 && count(jeab_list_reminders(jeab_store(), true)) === $beforeRem, 'testad kontakta-oss ger varken offert eller påminnelse');

$viaTwice = "Formulär via wix-forms.com\nNamn: Bea\nFormulär via wix-forms.com";
$viaOnce = jeab_text_drop_duplicate_via_lines($viaTwice);
wp(substr_count($viaOnce, 'Formulär via wix-forms.com') === 1, 'källraden på egna rader blir en');
$viaSame = jeab_text_drop_duplicate_via_lines('Formulär via wix-forms.com Formulär via wix-forms.com');
wp(substr_count($viaSame, 'Formulär via wix-forms.com') === 1, 'källraden två gånger på samma rad blir en');
wp(substr_count(jeab_reminder_view_note($viaTwice), 'Formulär via wix-forms.com') === 1, 'påminnelsekortet visar källraden en gång');
$batClean = jeab_inkorg_clean_message([
    'from' => 'info@wix-forms.com',
    'body' => $battery . "\nFormulär via wix-forms.com\nFormulär via wix-forms.com\n",
]);
wp(substr_count($batClean, 'Formulär via wix-forms.com') === 1, 'batteritråden visar källraden en gång');
$batCard = jeab_inkorg_list_snippet(['body' => "Formulär via wix-forms.com\n\nFormulär via wix-forms.com\nBea Battform"], 200);
wp(substr_count($batCard, 'Formulär via wix-forms.com') === 1, 'batterikortets förhandsrad visar källraden en gång');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'tpl' => jeab_auto_quote_seed(),
]);
$chg = $ingest('ladd', $chargerCity, 'Ladbox lead');
$chgAuto = jeab_auto_on_message((string) ($chg['id'] ?? ''));
$chgRow = jeab_inkorg_get((string) ($chg['id'] ?? ''));
wp((string) (($chgRow['labels']['type'] ?? '')) === 'Oklassad' && (string) (($chgRow['labels']['offer'] ?? 'laddbox')) === '' && ($chgRow['labels']['needs_review'] ?? false) === true, 'intagen laddbox får ingen produkt från formulärrubriken');
$rem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'cia.laddform@gmail.com') {
        $rem = $row;
    }
}
wp($rem === null && ($chgAuto['sent'] ?? 0) === 0, 'laddbox utan modell skapar ingen påminnelse och skickar inget');

echo "inkorg-wix-form-parse: ok\n";
