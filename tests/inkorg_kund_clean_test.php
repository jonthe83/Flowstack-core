<?php

declare(strict_types=1);

/** Kundens e-post som kontakt, rensad leadtext, rekontakt och clean_preview. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-kund-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kc(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$fixtures = require __DIR__ . '/fixtures/inkorg/leads.php';
$threads = [];
$ingest = function (string $key, array $over = []) use ($fixtures, &$threads): array {
    $fx = array_replace($fixtures[$key], $over);
    $id = (string) ($over['id'] ?? $key);
    $msg = [
        'message_id' => $id . '@kund.test',
        'from' => $fx['from'],
        'from_name' => $fx['from_name'],
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $fx['subject'],
        'body_text' => $fx['body'],
        'source' => 'graph-jarfalla',
        'thread_key' => 'kund-' . $id,
        'test' => true,
        'headers' => (array) ($over['headers'] ?? []),
    ];
    if (!empty($over['reply_to'])) {
        $msg['headers']['Reply-To'] = (string) $over['reply_to'];
    }
    $r = jeab_inkorg_ingest($msg);
    $threads[$id] = (string) ($r['thread_id'] ?? '');
    return $r;
};

$byId = function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ($t['id'] === $tid) {
            return $t;
        }
    }
    return [];
};

$storedBody = function (string $tid): string {
    $t = null;
    foreach (jeab_inkorg_threads() as $row) {
        if ($row['id'] === $tid) {
            $t = $row;
        }
    }
    return (string) ($t['first']['body'] ?? '');
};

// --- kundens e-post, inte plattformen ---

$expect = [
    'wix' => ['kund.b@example.invalid', 'Anna Svensson', 'body'],
    'wix-html' => ['erik.lind@gmail.com', 'Erik Lind', 'body'],
    'weply' => ['nora.weply@gmail.com', 'Nora Weply', 'body'],
    'brabygg' => ['pia.holm@gmail.com', 'Pia Holm', 'body'],
    'leadkit' => ['bo.ek@gmail.com', 'Bo Ek', 'body'],
    'leadkit-support' => ['sara.ek@gmail.com', 'Sara Ek', 'body'],
    'hitta' => ['vera.hitta@gmail.com', 'Vera Hitta', 'body'],
    'solceller-nu' => ['maja.nu@gmail.com', 'Maja Nu', 'body'],
    'offerta' => ['olle.offert@gmail.com', 'Olle Offert', 'body'],
    'kund' => ['maja.kund@gmail.com', 'Maja Kund', 'from'],
];
foreach ($expect as $key => [$email, $name, $source]) {
    $r = $ingest($key);
    kc(!empty($r['ok']), $key . ' intagen');
    $t = $byId($threads[$key]);
    $party = jeab_inkorg_party($t);
    kc($party['email'] === $email && $party['source'] === $source, $key . ' kund är ' . $email . ' via ' . $source . ', fick ' . $party['email'] . '/' . $party['source']);
    kc($party['name'] === $name, $key . ' namn ' . $name . ', fick ' . $party['name']);
    kc(!jeab_inkorg_address_blocked($party['email']), $key . ' kunden är inte en plattformsadress');
    kc($storedBody($threads[$key]) === $fixtures[$key]['body'], $key . ' originalet ligger kvar');
    $clean = jeab_inkorg_clean_message($t['first']);
    kc($clean !== '' && !str_contains(mb_strtolower($clean), 'unsubscribe') && !str_contains(mb_strtolower($clean), 'avregistrera') && !str_contains(mb_strtolower($clean), 'integritet'), $key . ' rensad text saknar sidfot');
    kc(str_contains($clean, $name) && str_contains($clean, $email), $key . ' rensad text behåller namn och e-post');
}

$keep = [
    'wix' => 'Taket är mot sydväst och vi vill ha batteri också.',
    'wix-html' => 'Vi har ett platt tak och vill ha en offert.',
    'weply' => 'Vi vill ha solceller på villan, tak mot söder.',
    'brabygg' => 'Vill ha offert på solceller och batteri innan vintern.',
    'leadkit' => 'Hur många paneler får plats på garaget?',
    'leadkit-support' => 'Kan ni titta på ett batteri till villan?',
    'hitta' => 'Taket är 40 kvadratmeter.',
    'solceller-nu' => 'Vi vill ha batteri också till huset.',
    'offerta' => 'Behöver offert innan vintern, huset är från 1978.',
    'kund' => 'Jag vill ha en offert på solceller till villan.',
];
foreach ($keep as $key => $sentence) {
    $clean = jeab_inkorg_clean_message($byId($threads[$key])['first']);
    kc(str_contains($clean, $sentence), $key . ' behåller kundens text');
}
kc(!str_contains(jeab_inkorg_clean_message($byId($threads['wix'])['first']), 'Submission summary'), 'Wix: submission summary borta');
kc(!str_contains(jeab_inkorg_clean_message($byId($threads['wix'])['first']), 'Wix.com'), 'Wix: marknadsrad borta');
kc(str_contains(jeab_inkorg_clean_message($byId($threads['wix'])['first']), 'Produkt: Batteri lead'), 'Wix: formulärets produkt behålls');
kc(!str_contains(jeab_inkorg_clean_message($byId($threads['wix-html'])['first']), '<table') && !str_contains(jeab_inkorg_clean_message($byId($threads['wix-html'])['first']), 'utm_source'), 'Wix HTML blir text utan spårningslänk');
kc(!str_contains(jeab_inkorg_clean_message($byId($threads['leadkit'])['first']), '<td') && str_contains(jeab_inkorg_clean_message($byId($threads['leadkit'])['first']), 'Bo Ek'), 'Leadkit-tabell blir text');
kc(!str_contains(jeab_inkorg_clean_message($byId($threads['kund'])['first']), 'iPhone'), 'vanligt mejl tappar iPhone-sidfoten');
kc(!str_contains(jeab_inkorg_message_snippet($byId($threads['wix'])['first'], 200), 'Submission summary'), 'förhandsvisningen är rensad');
kc(str_contains(jeab_inkorg_message_snippet($byId($threads['wix'])['first'], 200), 'Anna'), 'förhandsvisningen visar kunden');

// Samma kund på två plattformar hör ihop. En annan Wix-kund gör det inte.
// Öppen tråd slås ihop med nästa formulär från samma kundmejl, så det första stängs först.
jeab_inkorg_thread_write($threads['wix'], static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$ingest('wix', ['id' => 'wix-anna-2', 'subject' => 'Andra förfrågan från Anna']);
$cust = jeab_inkorg_customer($byId($threads['wix']), jeab_inkorg_threads());
$caseIds = array_column($cust['cases'], 'id');
kc($cust['email'] === 'kund.b@example.invalid' && in_array($threads['wix-anna-2'], $caseIds, true) && !in_array($threads['wix-html'], $caseIds, true), 'andra ärenden grupperas på kunden, inte på info@wix-forms.com');
$contact = jeab_inkorg_contact_for_thread($byId($threads['wix']));
kc(is_array($contact) && in_array('kund.b@example.invalid', $contact['emails'], true) && !in_array('info@wix-forms.com', $contact['emails'], true), 'kontakten är kunden');
$history = array_column(jeab_inkorg_contact_threads($contact, jeab_inkorg_threads()), 'id');
kc(in_array($threads['wix'], $history, true) && in_array($threads['wix-anna-2'], $history, true) && !in_array($threads['wix-html'], $history, true), 'kundhistoriken är Annas ärenden');

// Reply-To när brödtexten saknar e-post. Plattform aldrig.
$r = $ingest('wix', [
    'id' => 'wix-reply',
    'body' => "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nKim Alm\n\nSubmission summary:\n\nUnsubscribe: https://www.wix.com/unsubscribe?x=1\n",
    'reply_to' => 'Kim Alm <kim.alm@gmail.com>',
]);
kc(jeab_inkorg_party($byId($threads['wix-reply']))['email'] === 'kim.alm@gmail.com' && jeab_inkorg_party($byId($threads['wix-reply']))['source'] === 'reply-to', 'Reply-To används när brödtexten saknar e-post');
$r = $ingest('wix', [
    'id' => 'wix-none',
    'body' => "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nUtan Post\n\nUnsubscribe: https://www.wix.com/unsubscribe?x=2\n",
    'reply_to' => 'Formulär <info@wix-forms.com>',
]);
$none = jeab_inkorg_party($byId($threads['wix-none']));
kc($none['email'] === '' && $none['source'] === 'none', 'plattform i From och Reply-To blir inte kund');
kc(jeab_inkorg_contact_for_thread($byId($threads['wix-none'])) === null, 'ingen kontakt skapas på plattformen');
kc(jeab_inkorg_customer($byId($threads['wix-none']), jeab_inkorg_threads())['cases'] === [], 'tom kundadress grupperas inte med andra');

// Vanligt mejl: From gäller. Reply-To och en annan adress i texten byter inte kunden.
$r = jeab_inkorg_ingest([
    'message_id' => 'reply-plain@kund.test',
    'from' => 'shared@exempel-firma.se',
    'from_name' => 'Växel',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Vidarebefordran',
    'body_text' => "Hej, kunden vill bli uppringd om laddbox.\nSvar till annan@exempel-firma.se\n",
    'headers' => ['Reply-To' => 'Kund <kund.vaxel@gmail.com>'],
    'source' => 'graph-jarfalla',
    'thread_key' => 'kund-reply-plain',
    'test' => true,
]);
$plainParty = jeab_inkorg_party($byId((string) $r['thread_id']));
kc($plainParty['email'] === 'shared@exempel-firma.se' && $plainParty['source'] === 'from', 'vanligt mejl behåller From, fick ' . $plainParty['email'] . '/' . $plainParty['source']);

// Upprepad standardrad lärs bort från tredje mejlet. Telefonraden stannar.
$campaign = 'Vinterkampanj: boka besiktning gratis i hela länet.';
$phoneLine = 'Telefon: 070-100 00 01';
$learnBody = function (string $who, string $mail) use ($campaign, $phoneLine): string {
    return "Du har fått en ny förfrågan via Weply.\n\nNamn: {$who}\nE-post: {$mail}\n{$phoneLine}\nAdress: Testvägen 1, 176 70 Järfälla\nMeddelande:\nEget meddelande från {$who}.\n\n{$campaign}\nDetta är en notis från Weply.\nhttps://weply.se/unsubscribe?u=9\n";
};
$ingest('weply', ['id' => 'learn-1', 'body' => $learnBody('Ada Ett', 'ada.ett@gmail.com')]);
$ingest('weply', ['id' => 'learn-2', 'body' => $learnBody('Ada Tva', 'ada.tva@gmail.com')]);
$mid = jeab_inkorg_clean_message($byId($threads['learn-1'])['first']);
kc(str_contains($mid, $campaign), 'två mejl räcker inte för att lära bort raden');
$ingest('weply', ['id' => 'learn-3', 'body' => $learnBody('Ada Tre', 'ada.tre@gmail.com')]);
$learned = jeab_inkorg_clean_message($byId($threads['learn-3'])['first']);
kc(!str_contains($learned, $campaign), 'rad som finns i tre mejl från källan tas bort');
kc(str_contains($learned, $phoneLine) && str_contains($learned, 'Ada Tre'), 'kunduppgift som upprepas behålls');
kc(str_contains($storedBody($threads['learn-3']), $campaign), 'lärd standardtext ligger kvar i lagringen');

// Hook: dry=1 är standard och skriver inte. dry=0 är idempotent.
$path = jeab_inkorg_dir() . '/threads.json';
$beforeThreads = (string) file_get_contents($path);
$beforeMessages = (string) file_get_contents(jeab_inkorg_dir() . '/messages.json');
$meta = json_decode($beforeThreads, true);
foreach ($meta['threads'] as &$row) {
    unset($row['customer_email'], $row['customer_source'], $row['customer_name']);
}
file_put_contents($path, json_encode($meta));
$dry = jeab_inkorg_hook(['action' => 'inkorg.rekontakt']);
kc(($dry['ok'] ?? false) === true && ($dry['dry'] ?? false) === true, 'inkorg.rekontakt är torr som standard');
$hit = null;
foreach ((array) ($dry['changes'] ?? []) as $ch) {
    if (($ch['thread'] ?? '') === $threads['weply']) {
        $hit = $ch;
    }
}
kc(is_array($hit) && $hit['old'] === 'support@weply.se' && $hit['new'] === 'nora.weply@gmail.com' && $hit['source'] === 'body', 'rapporten visar tråd, old → new och källa');
kc((string) file_get_contents(jeab_inkorg_dir() . '/messages.json') === $beforeMessages, 'torrkörning rör inte mejlen');
kc(!array_key_exists('customer_email', (array) (json_decode((string) file_get_contents($path), true)['threads'][$threads['wix']] ?? [])), 'torrkörning skriver inte kundadressen');
$apply = jeab_inkorg_hook(['action' => 'inbox.rekontakt', 'dry' => 0]);
kc(($apply['dry'] ?? true) === false && count((array) ($apply['changes'] ?? [])) > 0, 'dry=0 skriver ändringarna');
kc((string) (jeab_inkorg_thread_meta($threads['wix'])['customer_email'] ?? '') === 'kund.b@example.invalid', 'ärendet får kundens e-post');
$again = jeab_inkorg_hook(['action' => 'inkorg.rekontakt', 'dry' => '0']);
kc(($again['changes'] ?? null) === [], 'andra körningen ändrar inget');
kc((string) file_get_contents(jeab_inkorg_dir() . '/messages.json') === $beforeMessages, 'rekontakt lämnar mejltexten orörd');

$msgHash = md5((string) file_get_contents(jeab_inkorg_dir() . '/messages.json'));
$thHash = md5((string) file_get_contents($path));
$preview = jeab_inkorg_hook(['action' => 'inkorg.clean_preview', 'threads' => [$threads['wix'], $threads['weply'], 'saknas']]);
kc(($preview['ok'] ?? false) === true && ($preview['read_only'] ?? false) === true, 'clean_preview är bara läsning');
$byThread = [];
foreach ((array) ($preview['threads'] ?? []) as $row) {
    $byThread[$row['thread']] = $row;
}
kc(($byThread[$threads['wix']]['customer_email'] ?? '') === 'kund.b@example.invalid' && str_contains((string) ($byThread[$threads['wix']]['clean'] ?? ''), 'Taket är mot sydväst'), 'clean_preview ger kund och rensad text');
kc(($byThread[$threads['weply']]['customer_email'] ?? '') === 'nora.weply@gmail.com', 'clean_preview på Weply');
kc(in_array('saknas', (array) ($preview['missing'] ?? []), true), 'okänt id rapporteras');
kc(md5((string) file_get_contents(jeab_inkorg_dir() . '/messages.json')) === $msgHash && md5((string) file_get_contents($path)) === $thHash, 'clean_preview skriver inget');

// Trådvyn: rensad text, Visa original, andra ärenden på kunden.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'view' => 'v-allt', 'id' => $threads['wix']];
require_once dirname(__DIR__) . '/src/layout.php';
$html = require dirname(__DIR__) . '/src/pages/inkorg.php';
kc(is_string($html) && str_contains($html, 'Visa original') && str_contains($html, 'data-orig-toggle'), 'tråden har Visa original');
kc(str_contains($html, 'Taket är mot sydväst och vi vill ha batteri också.') && str_contains($html, 'Andra ärenden från kunden'), 'tråden visar rensad text och kundens ärenden');
$pos = strpos($html, 'Andra ärenden från kunden');
$chunk = substr($html, (int) $pos, 800);
kc(str_contains($chunk, 'Andra förfrågan från Anna') && !str_contains($chunk, 'erik.lind@gmail.com') && !str_contains($chunk, 'New form submission'), 'panelen listar Annas andra ärende, inte Eriks');
kc(preg_match('/<div class="ix-body ix-clean">(?!.*Submission summary).*Taket är mot sydväst/s', $html) === 1 || (str_contains($html, 'ix-clean') && !preg_match('/ix-clean">[^<]*Submission summary/', $html)), 'den synliga texten är rensad');
kc(str_contains($html, 'Submission summary') && str_contains($html, 'ix-orig'), 'originalet finns kvar bakom visningen');
kc(str_contains($html, 'kund.b@example.invalid') && !preg_match('/ix-nm">[^<]*info@wix-forms\\.com/', $html), 'listan visar kunden, inte formuläradressen');

echo "inkorg kund/clean: ok\n";
